# FurimaDeck Discovery and Delivery Gates

## Purpose

FurimaDeck is a new, category-neutral flea-market listing and profit-management SaaS.
This document defines the discovery work that must complete before implementation.
It is not a deployment plan for the legacy FURUPRO service.

## Fixed Product Decisions

- Product name: FurimaDeck
- Initial marketplaces: Mercari, Yahoo! Auctions, Yahoo! Flea Market, and Rakuma
- Mercari Shops API is a later phase and is out of scope for the initial extension
- Browser-form automation and a Chrome listing extension are out of scope
- FurimaDecK accepts up to 10 listing images per item, with a maximum size of 2 MB per image
- Premium price: 980 JPY per month with a 7-day trial
- The legacy service, database, VPS, and Stripe configuration are not migrated or changed

## Delivery Priority

### P0: Discovery, Safety, and Data Contract

1. Build a marketplace field inventory from official documentation and logged-in desktop forms.
2. Record every field as one of: canonical, marketplace-only, category-dependent, account-dependent, or user-required.
3. Define category mappings and validation rules before creating database migrations.
4. Define an explicit Yahoo! Auctions and Yahoo! Flea Market duplicate-listing rule.
5. Confirm marketplace terms and extension constraints before any automation implementation.

No implementation starts until all P0 acceptance criteria are complete.

### P1: FurimaDecK Core Data Model and Product UI

- New clean database and category-neutral item model.
- Three-level categories: major, middle, and minor.
- Item attributes are schema-driven rather than hard-coded to apparel.
- Image asset management, listing drafts, marketplace selection, profit calculation, and pre-listing validation.
- Marketplace-specific mapping preview that explains what will be filled and what remains for the user.

### P2: Marketplace-Agnostic Management Features

- Marketplace-specific sales-fee presets and an `Other` destination for user-defined selling channels.
- Listing status, sale records, profitability, inventory ageing, CSV, and analytics for every supported product category.
- Optional copy-ready listing notes may be considered later, but marketplace navigation, form interaction, image upload, and submission remain out of scope.

### P3: Billing and New Environment

- New Stripe Product and Price: 980 JPY monthly, 7-day trial.
- New VPS, new database, new domain, and renamed GitHub repository.
- New CI/CD only after the target host, repository name, app path, and environment secrets are documented and verified.

## Canonical Item Data Contract

The core model must support the following fields without assuming apparel:

| Group | Canonical fields |
| --- | --- |
| Identity | title, internal SKU, barcode/JAN, model number, brand, manufacturer, series |
| Category | major category, middle category, minor category, marketplace category mappings |
| Content | description, condition, images, image order, defect notes |
| Sale | selling price, quantity, sale format, listing period, return policy |
| Delivery | sender region, shipping payer, shipping method, shipping size, shipping weight, dispatch days |
| Attributes | category-defined key/value fields such as color, size, material, capacity, compatibility, and age rating |
| Profit | purchase cost, selling fee, shipping cost, other cost, expected profit, expected margin |
| Listing | selected marketplaces, per-marketplace draft values, field completion state, user review state |

Marketplace-specific values must be stored separately from canonical values. A marketplace field must not overwrite the canonical item value.

## Image Intake and Ordering Requirements

FurimaDecK has a product-level limit that is stricter than some marketplace limits: an item can hold at most 10 images, and each image must be at most 2 MB. These values must be named configuration values in the implementation, not scattered numeric literals.

- The item editor must support drag and drop from the user's local computer.
- The item editor must support selecting multiple files at once from the local file chooser.
- The item editor must support adding one image at a time from the same file chooser.
- The user must be able to reorder images with a visible drag-and-drop interaction; the first image is the primary image.
- The saved order is canonical and must be used consistently by the item screen, export, listing preview, and marketplace adapters.
- The UI must show per-file validation failures without discarding valid files in the same selection.
- Client-side checks are for immediate feedback only. The server must independently enforce the count, byte size, permitted image formats, decoded-image validity, ownership, and authorization before persisting a file.
- Marketplace adapters may use fewer images when a destination has a smaller limit, but must preserve the FurimaDecK order and clearly show omitted images before opening the destination form.

The exact accepted file formats, image transformation policy, storage backend, and retention policy remain P0 decisions. The implementation must not trust a filename extension or browser-provided MIME type as the only file validation.

## Marketplace Inventory: Public Evidence

This is an initial inventory, not a claim of complete form coverage.

### Mercari

Initial logged-in desktop form observation on 2026-09-09 confirmed:

- Listing images: maximum 20.
- Title: maximum 40 characters.
- Description: optional, maximum 1,000 characters.
- Category, condition, shipping payer, shipping method, sender region, dispatch days, sale type, and sale price.
- The form calculates a 10% selling fee and sale profit from the sale price.
- The form exposes both final submission and draft-save actions.
- An AI listing-support switch is present and must remain out of scope for the extension.

This account displayed a marketplace usage restriction. No draft was created and no field values, images, category selection, shipping setting, or final submission action was performed.

Still required: required markers, image file restrictions, category-dependent attributes, marketplace options, delivery option values, and sale-type-specific fields on an account that can create drafts.

### Yahoo! Auctions

Initial logged-in desktop form observation on 2026-09-09 confirmed the following for an LYP Premium account. No field value was changed, no image was attached, and no draft or listing was submitted.

- Images: file selection or drag and drop, maximum 10 images.
- Product information: required title (65-character limit), required category, optional product lookup, required condition, required description, and required quantity.
- Shipping: required sender region, required shipping payer, required shipping method, and required dispatch days.
- Shipping methods shown: Yamato anonymous shipping (Nekopos, Compact, 60-160, and 180-200 sizes) and Japan Post anonymous shipping (Yu-Packet Post mini, Yu-Packet or Yu-Packet Post, Yu-Packet Plus, and Yu-Pack), plus a separate flow for other delivery methods.
- Selling: required sale format (auction or fixed-price flea-market format), required price, required end date/time, automatic relisting count, and automatic markdown percentage.
- Paid promotion: featured auction and recommendation-collection options; these must default to off and must never be enabled by FurimaDecK.
- Account-only settings: seller profile and automatic proceeds-to-PayPay setting. These are not item data and must remain outside the extension.
- The form exposes draft-save and confirmation actions. Neither was used.

Still required: category-dependent attributes, exact title/description handling under all sale formats, other-delivery controls, final confirmation fields, account-plan differences, and the behaviour of all supported shipping combinations. These require a separately approved draft-only matrix; no final submission is permitted.

### Yahoo! Flea Market

Initial logged-in desktop form observation on 2026-09-09 confirmed the following. No field value was changed, no image was attached, and no draft or listing was submitted.

- Images: file selection or drag and drop, maximum 20 images. The form also shows a separate mobile-assisted image-upload path.
- Product information: required title (65-character limit), required category, required condition, optional product selection, and description (1,000-character limit; no required marker in the initial form).
- Delivery: required method, required dispatch days, and required sender region. The initial form shows seller-paid `Otegaru Delivery (Yamato Transport)`; the exact selectable method matrix still needs verification.
- Price: required, from 300 JPY through 9,999,999 JPY. The form displays sales fee and sales profit derived from the entered price.
- The initial category chooser lists top-level categories, including fashion, food, outdoors/travel, health, beauty, devices, AV/camera, appliances, furniture/interior, home goods, DIY, pet supplies, collectibles, games/toys, baby/kids, sports, vehicles, music, video, and books/comics.
- The form exposes draft-save and final-listing actions. The final-listing action was disabled with the form empty; neither action was used.

Still required: category-dependent attributes at middle/minor levels, image file restrictions and video capability, delivery-method choices and conditions, final confirmation fields, and account-dependent controls. These require a separately approved draft-only matrix; no final submission is permitted.

### Rakuma

Initial logged-in desktop form observation on 2026-09-09 confirmed the following. No field value was changed, no image was attached, and no draft or listing was submitted.

- Images: required; JPEG or PNG, maximum 10 MB per file, and maximum 20 images.
- Product information: required title (65-character limit), required category, optional brand, required description (1,000-character limit), and required condition.
- Delivery: required shipping-payer choice, delivery-method selection, required dispatch-days selection, and required sender region. The form states that the delivery method may also be changed at dispatch.
- Purchase application: required choice of enabled or disabled; it is marketplace-only and must not be inferred from a FurimaDecK item.
- Price: required. The form presents a 10% sales-fee display and receipt calculation, while warning that certain shipping costs are determined after carrier measurement.
- The form exposes draft-save and confirmation actions. Neither was used.

Still required: exact delivery-method choices by payer and category, category-dependent middle/minor attributes, price bounds, final confirmation fields, and account-dependent restrictions. These require a separately approved draft-only matrix; no final submission is permitted.

## Required Logged-In Form Verification

For every marketplace, record the following using a manually operated disposable draft only. Do not submit a listing. No extension automation may be used for this verification until the Marketplace Automation Compliance Gate is satisfied.

1. Listing URL and desktop accessibility.
2. All visible fields in initial form order, labels, types, required state, help text, option values, and character limits.
3. Fields revealed after selecting each of these test categories: apparel, electronics, books/media, hobby/collectibles, home goods, and other.
4. Fields revealed by delivery method, sale format, account plan, and identity verification state.
5. Image upload constraints, accepted file types, ordering behavior, and maximum count.
6. Final confirmation page fields. The final submission button is out of scope for automation.

## Non-Negotiable Duplicate Rule

Yahoo! Auctions can publish qualifying listings to Yahoo! Flea Market automatically. FurimaDecK must detect this condition and prevent the user from opening a separate Yahoo! Flea Market listing flow for the same item unless they explicitly acknowledge the duplication risk.

## Marketplace Automation Research (Out of Scope)

### Confirmed Restrictions

- Yahoo! Auctions prohibits using tools or similar programs to list automatically, unless Yahoo! specifically permits it. Yahoo! also states that it determines tool-equivalent conduct based on the overall listing circumstances.
- Rakuma states that it has prohibited automated tools that perform listing or transaction activities since service launch and that detected use can result in immediate account restrictions.
- Yahoo! Auctions may automatically publish qualifying Yahoo! Auctions listings to Yahoo! Flea Market. Creating an independent Yahoo! Flea Market listing flow for the same item is therefore both a duplication and compliance risk.

Browser-form automation is no longer a product goal. The restrictions above are retained only as design history: FurimaDecK must not programmatically navigate, populate, upload to, submit, scrape, or bypass marketplace forms.

## No-Regression Gates

- No deployment, Stripe change, production database write, or legacy service modification during discovery.
- No schema migration before the canonical data contract and field inventory are approved.
- Every marketplace adapter requires isolated automated tests and a manual draft-only verification.
- Every category mapping must have a fallback of `manual selection required`; values must never be guessed.
- Final publish actions must remain outside extension automation and test automation.

## Official Sources

- Yahoo! Auctions listing flow: https://support.yahoo-net.jp/SccAuctions/s/article/H000008872
- Yahoo! Auctions form fields for non-LYP accounts: https://support.yahoo-net.jp/SccAuctions/s/article/H000008886
- Yahoo! Auctions form fields for LYP accounts: https://support.yahoo-net.jp/SccAuctions/s/article/H000008877
- Yahoo! Flea Market listing flow: https://support.yahoo-net.jp/SccPaypayfleamarket/s/article/H000008388
- Yahoo! Flea Market delivery constraints: https://support.yahoo-net.jp/SccPaypayfleamarket/s/article/H000008409
- Yahoo! Auctions to Yahoo! Flea Market cross-listing: https://support.yahoo-net.jp/PccAuctions/s/article/H000010920
- Rakuma listing flow: https://faq.fril.jp/hc/ja/articles/38949362354061-%E5%95%86%E5%93%81%E3%82%92%E5%87%BA%E5%93%81%E3%81%99%E3%82%8B
- Yahoo! Auctions guideline details: https://guide-ec.yahoo.co.jp/notice/rules/auc/detailed_regulations.html
- Rakuma automated-tool restriction: https://fril.jp/magazine/2021-01-21-150008/
- Mercari listing flow and user review requirement: https://help.jp.mercari.com/guide/articles/305/

## Next Gate

Open and inspect the logged-in desktop listing forms using draft-only test data. Add the observed field inventory and mapping decisions to this document before implementation begins.
