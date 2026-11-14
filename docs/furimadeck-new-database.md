# FurimaDeck New Database

FurimaDeck uses a clean database and does not import legacy FURUPRO data.

## Product acquisition data ownership

The existing `products` row is the sole current source for purchase date, unit
cost, purchase shipping cost, other purchase expense, and supplier. Register or
edit those values in product management. The accounting screens read and
aggregate the same values; do not create a second purchase master or keep
independent accounting-screen copies.

`product_inventory_events` records stock changes and encrypted before/after
snapshots for purchase-information history. Those snapshots are audit history,
not a competing source for current purchase values. Inventory balance comes
from the event history; `products.quantity_available` and `inventory_status`
are synchronized projection fields. Do not edit stock in the ordinary product
form; use the reason-required inventory adjustment workflow.

Each product ID represents one physical item: purchase and sale quantities are
one, and available stock is zero or one. Product update, purchase-history
recording, and dependent profit/listing recalculation must be atomic. Sale or
accounting reconciliation locks must reject the whole update rather than save
unrelated fields partially. Existing sale snapshots and accounting history
must remain unchanged.

## Product and inventory identity

One FurimaDeck `product_id` identifies one individually tracked physical item.
Identical models or titles owned as separate physical units use separate
product IDs. Purchase, listing, and sale quantity for each product ID is one;
available inventory is either zero or one. Do not aggregate identical items
under one product ID or implement partial/multi-quantity sales in 1.0. Returns
and inventory adjustments update the same item's balance within that range.

## Product image processing requirements

Product images are validated on upload, converted to WebP, and saved at a
maximum dimension of 2000px. A separate thumbnail at a maximum dimension of
400px is generated for list views. Uploads are limited to 2 MB and 12 million
source pixels; the thumbnail is derived from the resized main image, and WebP
output is streamed through a temporary file. The uploaded source file is not
retained.

The PHP runtime that serves FurimaDeck must have the following extensions
enabled before product management is turned on:

```text
gd
exif
```

On Debian or Ubuntu, install the matching PHP packages before enabling product
management:

```bash
sudo apt install -y php-gd php-exif
```

`gd` performs resizing and WebP conversion. `exif` normalizes the orientation
of JPEG photos taken on phones. Confirm both extensions on the target server:

```bash
php -m | grep -E '^(gd|exif)$'
```

Do not enable `FURIMADECK_PRODUCT_MANAGEMENT_ENABLED=true` until this check
has returned both extension names.

## Existing public product-image migration

New product images are saved to private storage and are served only through an
authenticated, owner-authorized application route. For pre-existing rows whose
`storage_disk` is empty, first run a non-mutating preview:

```bash
php artisan furimadeck:secure-product-images
```

After confirming the reported target and backing up both the database and
`storage/app/public`, run the explicit copy-and-marker update:

```bash
php artisan furimadeck:secure-product-images --apply
```

The command verifies each source file, copies it to private storage, and updates
only successfully copied rows. It never deletes files from the public disk.
Removal of verified public source files is a separate, human-approved operation.

## Historical audit-log text scrubbing

New audit records retain operation metadata and numeric or boolean values, but
do not retain arbitrary text. To inspect the number of historical records that
would be redacted, run:

```bash
php artisan furimadeck:scrub-audit-log-text
```

The explicit apply command is irreversible for the redacted text values, so run
it only after a database backup has been confirmed:

```bash
php artisan furimadeck:scrub-audit-log-text --apply
```

The command reports counts and record identifiers for failures only; it never
prints stored audit content.

## Safety rule

Set `FURIMADECK_DB_*` only to a newly created, empty database. Do not reuse the
legacy database name or credentials. This migration path deliberately excludes
the legacy `auction_items` schema.

## Create the schema

After a database administrator has created the empty database and the relevant
environment variables have been configured, run:

```powershell
php artisan migrate --database=furimadeck --path=database/migrations/furimadeck
php artisan db:seed --database=furimadeck --class=Database\\Seeders\\FurimaDeckCategorySeeder
php artisan db:seed --database=furimadeck --class=Database\\Seeders\\FurimaDeckMarketplaceSeeder
```

The category seeder installs the versioned 1,686-category Rakuma public catalog
snapshot bundled with the application, so a new development or production
database does not depend on a live marketplace request during deployment. To
refresh the catalog later, first run and verify this command in development:

```bash
php artisan furimadeck:sync-rakuma-categories
```

The command reads Rakuma's public category page. It preserves existing category
rows referenced by products and does not delete product data. Review the
reported counts before using it in another environment.

This command must be run first in the development environment. Production use
requires a separately confirmed backup and cutover checklist.

## Cutover readiness check

After configuring the target environment and before enabling traffic, run the
read-only cutover check:

```bash
php artisan furimadeck:check-cutover
```

It checks the FurimaDeck database connection and schema, production security
settings, feature flags, and billing configuration. After the local checks pass,
it reads the configured Stripe Price and verifies that it is active, JPY,
monthly, and matches the configured monthly amount. It does not display
secrets, modify data, or assume a public domain.

## Cutover

FurimaDeck is a full cutover. The new VPS must use the clean FurimaDeck database
as its application default. It must not use the legacy FURUPRO database or
connect the new product models to it.

```env
DB_CONNECTION=furimadeck
FURIMADECK_CUTOVER_ENABLED=true
FURIMADECK_PRODUCT_MANAGEMENT_ENABLED=true
```

The application refuses to expose FurimaDeck product routes unless all three
conditions are met. During the cutover, legacy FURUPRO authenticated routes and
the legacy Stripe webhook return 404 to prevent queries against tables that do
not exist in the clean database.

## Marketplace listing preparation

Each marketplace listing draft stores marketplace-specific values separately
from the canonical product. In addition to title, description, category,
condition, price, and delivery method, a draft can retain the shipping payer,
sender region, dispatch days, package size, package weight, sale format,
listing period, return policy, and purchase-application setting.

The listing editor shows a marketplace-specific pre-listing checklist. It only
reports whether the FurimaDeck draft has values for the observed fields. It
does not navigate to a marketplace, populate a marketplace form, upload an
image, save a marketplace draft, or submit a listing.

Before setting these values to `true`, complete authentication, billing, and
FurimaDeck feature tests against the new database. The old deployment workflow
remains out of scope for this step.
