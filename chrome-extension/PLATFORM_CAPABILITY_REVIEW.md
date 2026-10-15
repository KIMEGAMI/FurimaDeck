# Marketplace form-fill capability review

Reviewed: 2026-10-15

Implementation update: Chrome extension 0.1.9 added disclosure that marketplace page event handlers may upload images or save drafts on input. Version 0.1.10 corrects outdated product-page and popup text so it matches the four-site form-fill prototype. This behavior is unverified on logged-in live forms. The extension does not submit the final listing form. The automated form-fill continues to have fixture-only verification; live logged-in marketplace mapping remains unverified. The decision table below records the initial 0.1.6 policy review.

This is an implementation gate, not legal advice or a guarantee against account restrictions. Technical ability to modify a page from a browser extension does not establish platform permission. Re-check the linked official sources before each release because platform rules can change.

## Decision summary

| Platform | Verified official material | Current implementation decision |
| --- | --- | --- |
| Yahoo! Flea Market | The detailed rules prohibit tools or similar programs that automatically list items, and conduct equivalent to tool use, unless specifically permitted. Applicability is judged by Yahoo! from overall listing circumstances. Yahoo! Customer Service replied on 2026-10-15 that it does not provide individual interpretations of the guidelines or assess whether a particular feature is permitted. | Version 0.1.6 includes experimental user-triggered field fill after an individual optional host permission. This is not platform approval; applicability remains unresolved and restriction risk exists. No automatic submission. |
| Yahoo! Auctions | Its detailed rules likewise prohibit automatic-listing tools or similar conduct unless specifically permitted, with applicability judged from overall listing circumstances. Yahoo! Flea Market's response does not establish permission for Auctions. | Version 0.1.6 includes experimental user-triggered field fill after an individual optional host permission. Yahoo! Auctions has not given feature-specific approval; restriction risk is unresolved. No automatic submission. |
| Rakuma | Rakuma Support replied on 2026-10-15 that users should avoid tool-based input or operations that may fall under access through an unapproved tool, even if the user presses the final listing button, and instructs users to enter information themselves on Rakuma's official listing screen. | At the user's explicit direction, version 0.1.6 includes a prototype despite this warning. This is not approval and is the clearest documented policy risk among the test sites. It remains user-triggered and does not submit. |
| Mercari | The terms require sellers to follow Mercari's prescribed listing procedure and allow Mercari to restrict particular listing methods. Its account-restriction guidance says access from external environments may be restricted and asks users to access without routing through external apps. That passage is about access restrictions; it does not explicitly classify a local extension that fills visible fields after a user action. Mercari's own AI listing support is an official, in-product feature and does not authorize third-party extensions. | Official permission remains unconfirmed. Version 0.1.6 extends the opt-in prototype to title, description, price, exact-match category/condition/shipping choices, shipping cost and payer, sender region, dispatch days, package size/weight, sale format, listing period, return policy, purchase applications, quantity, category attributes (for example brand/size), JAN/EAN, ISBN, model/serial numbers, and registered images. No form is submitted. This is not evidence of permission or store readiness. |
| Mercari Shops | No platform-specific rule or official permission for this extension's proposed field prefill has been confirmed in this review. | Keep extension assistance disabled; only enable a platform-specific adapter after separate review. |

## Proposed behavior sent to Yahoo! Flea Market

The question describes an extension that, only after the user selects the platform from their FurimaDeck product page, fills visible fields on the normal listing page with title, description, price, category, condition, shipping information, and images. The user reviews/edits the result and performs the final listing action. The extension would not collect credentials or cookies, use private APIs, bypass CAPTCHA/access limits, submit automatically, bulk-list, or remove/pause existing listings.

Yahoo! Customer Service responded on 2026-10-15 but declined to explain how the guidelines apply to a particular feature. This is neither approval nor a feature-specific prohibition. At the user's explicit risk acceptance, version 0.1.6 has an opt-in experimental form-fill for Yahoo! Flea Market and Auctions. The published rules still create unresolved risk; retain this distinction in the UI and do not claim the feature is permitted.

## Competitor public-feature check

Checked on 2026-10-15 using the developer's official site/blog and the Chrome Web Store listing:

- The Chrome Web Store listing describes Mercari Web features such as listing/update timestamps, comment timestamps, search-result external links, and canned comments/messages; Rakuma features include listing/update timestamps, seller ratings/marks, search-result external links, and canned comments/messages; PayPay Flea Market features include listing/update timestamps and search-result external links.
- The developer's official blog separately documents “copy listing” and CSV export. It says access to those functions requires a secret code distributed through the developer's official LINE account, and that the code is periodically changed.
- The public material inspected does not document the exact copy-listing field mapping, whether copy-listing writes into each marketplace form, or any platform authorization for that behavior. Linked videos were not independently inspected.
- A Chrome Web Store listing, its user count/ratings, and the developer's existence of copy-listing features are evidence of publication and claimed functionality only. They do not prove that each marketplace has authorized the specific behavior or that users face zero restriction risk.

Sources: Chrome Web Store listing at `https://chromewebstore.google.com/detail/jcbljdgnpcckiamdgmnfhijgkkaogmgg`; developer article at `https://kiyomaro.net/furima-assist-copy/`.

## Technical boundary

For a normal web page, same-origin restrictions prevent FurimaDeck's page JavaScript from directly manipulating a marketplace page. Chrome's `activeTab` permission and `scripting` API can technically support a user-triggered content-script action on the current tab without persistent marketplace host access. That is only a technical route; it is not platform authorization. Never request broad all-sites access for this feature.

Yahoo Developer Network's documented listing-management APIs reviewed here are for Yahoo! Shopping store operations; they are not evidence of an API for individual Yahoo! Flea Market or Yahoo! Auctions listings.

## Official listing URL smoke check

Checked on 2026-10-15 without signing in or submitting a listing:

- Mercari: `https://jp.mercari.com/sell` is an official selling landing page, not the item form; it links to `/sell/create`. Opening `/sell/create` without a signed-in session redirects to the official Mercari login page. The logged-in form fields were not inspected.
- Rakuma: `https://fril.jp/item/new` redirected to the official Rakuma sign-in page, as expected for an unauthenticated session.
- Yahoo! Flea Market: `https://paypayfleamarket-sec.yahoo.co.jp/item/add?from=sellTop` redirected to Yahoo! JAPAN sign-in.
- Yahoo! Auctions: `https://auctions.yahoo.co.jp/sell/jp/show/submit?category=0` redirected to Yahoo! JAPAN sign-in.

These checks establish route reachability only. They do not verify logged-in form behavior or authorize extension input.

## Release gate

1. Record the official answer or applicable official API for each platform separately. Existing prototypes are not release approval.
2. Implement a platform-specific adapter only when the allowed actions are clear; no shared assumption across services.
3. Require a deliberate user action to fill fields, show a review/confirmation summary, and never submit the listing automatically.
4. Do not collect platform credentials, session cookies, or use private APIs, CAPTCHA bypasses, or access-limit evasion.
5. Test field mapping, missing/ambiguous fields, non-listing routes, image order, and no-submit behavior against a test fixture and a permitted live page. Version 0.1.6 has fixture-only coverage; logged-in form mapping remains unverified.
6. Do not add automatic submission or bulk listing. Current Rakuma and Yahoo form-fill prototypes are a user-directed experiment with unresolved or explicitly warned policy risk, not platform approval.
7. Disclose that support has not confirmed the behavior as permitted. Do not treat user risk acceptance as platform permission or release approval.

## Official sources

- Yahoo! Flea Market detailed rules: https://paypayfleamarket.yahoo.co.jp/guide/guideline/detail/
- Yahoo! Auctions detailed rules: https://guide-ec.yahoo.co.jp/notice/rules/auc/detailed_regulations.html
- Rakuma notice about listing tools: https://fril.jp/magazine/2021-01-21-150008/
- Rakuma official contact guidance (in-app form or support email): https://faq.fril.jp/hc/ja/articles/39048490800653-%E3%81%8A%E9%9B%BB%E8%A9%B1%E3%81%A7%E3%81%AE%E3%81%8A%E5%95%8F%E3%81%84%E5%90%88%E3%82%8F%E3%81%9B%E3%81%AB%E9%96%A2%E3%81%97%E3%81%A6
- Mercari terms: https://static.jp.mercari.com/tos
- Mercari account-restriction and external-app guidance (context: access restriction): https://help.jp.mercari.com/guide/articles/1331/
- Mercari registration troubleshooting page (context-specific external-app guidance): https://help.jp.mercari.com/guide/articles/353/
- Mercari official AI listing support: https://help.jp.mercari.com/guide/articles/1867/
- Chrome content scripts and host permissions: https://developer.chrome.com/docs/extensions/develop/concepts/content-scripts
- Chrome `activeTab` permission: https://developer.chrome.com/docs/extensions/develop/concepts/activeTab
- MDN same-origin policy: https://developer.mozilla.org/en-US/docs/Web/Security/Defenses/Same-origin_policy
- Yahoo Developer Network listing APIs: https://developer.yahoo.co.jp/webapi/shopping/selling/
