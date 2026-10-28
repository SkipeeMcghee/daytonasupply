# Catalog sync

`catalog_sync.php` is a PHP 7.1+ / PDO endpoint. It uses `includes/db.php` and
`getDb(false)` to connect with the site's existing `DB_*` environment, without
schema initialization, migrations, or the manager portal CSV importer.
It supports MySQL (InnoDB required for rollback) and existing SQLite databases.
Sync never falls back to SQLite when MySQL fails.

## Deployment

### Inventory rollout (schema 2)

Run [the additive migration](../tools/migrate_add_product_qty.sql) on the actual
production database **before** deploying the inventory code. It adds nullable
`products.qty` and `products.qty_updated_at`, without a backfill or index.
The [base MySQL schema](../mysql_schema.sql) and new SQLite databases include
these fields; existing databases require the explicit migration. For an existing
development SQLite database, add `qty INTEGER DEFAULT NULL` and
`qty_updated_at DATETIME DEFAULT NULL` with separate `ALTER TABLE` statements.
Sync never migrates databases.

Deploy in this order:

1. Apply the migration. Keep the previous application code running.
2. Deploy [the shared display helper](../includes/inventory_display.php),
   [inventory styles](../assets/inventory.css), and the updated
   [shared product readers](../includes/functions.php). Deploy the updated
   diagnostic tools too: their explicit public projections prevent new columns
   leaking through diagnostic output. Do not copy test fixtures into production.
3. Deploy the endpoint; verify authenticated ping (schema 2) and a schema-1
   replay, confirming existing quantities and quantity timestamps are untouched.
4. Deploy [the catalogue](../catalogue.php) and
   [product details](../productinfo.php). Initially NULL quantities render
   nothing for customers; guests always receive no stock markup or styles.
5. Have Talos send a full schema-2 dry run, inspect diffs, then send the live push
   and immediately replay it to verify idempotence.
6. Verify guest/customer views with real quantities and a 360px viewport.
   Query `SELECT COUNT(*) FROM products WHERE qty < 0;` and report the count;
   never correct negative quantities on the website.

Code-only rollback: restore all previous application files. The added columns
are inert. To remove them after restoring code, run
`ALTER TABLE products DROP COLUMN qty_updated_at, DROP COLUMN qty;`.
Do not remove columns while the inventory code is serving requests.

QuickBooks owns inventory. Only this authenticated POST endpoint writes its
values. Cart, checkout, order placement and fulfilment neither reserve nor
decrement stock, and ordering is not capped by displayed availability.

This repository's root is the webroot: deploy `api/catalog_sync.php` as
`httpdocs/api/catalog_sync.php`. Deploy the updated `includes/db.php` and
`includes/functions.php` too; keep the existing `includes/product_images.php`
dependency available.
The canonical production endpoint is
`https://www.daytona-supply.com/api/catalog_sync.php`; use it directly rather than
relying on redirects to preserve authentication headers and POST bodies.

1. Create `config/` **beside**, not inside, `httpdocs/`.
   Give the web user ownership and directory permissions `775`. These must be
   persistent and writable by PHP. Restrict parent directory access to trusted
   users. This directory holds state, locks and the sync log, not DB credentials.
   The endpoint creates files, not directories. It does not use Plesk's `logs/`.
   Existing lock/log files must also permit PHP to open/write them.
2. Keep the site's existing DB configuration. Apache `SetEnv DB_DRIVER`,
   `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASS` must reach PHP. Sync also honors
   the site's existing local overrides when connecting. Do not copy DB secrets
   into a separate sync config.
3. Provision a securely generated token of at least 32 characters (recommended:
   32 random bytes encoded as 64 hex characters). Supply it to the web PHP process
   as `SYNC_TOKEN`, and privately to the gateway process as `DSD_SYNC_TOKEN`.
   Restart/reload the relevant processes after changing their environment.
   Prefer a hosting-managed PHP-FPM environment setting. Apache `SetEnv` is an
   Apache directive, not a PHP ini directive; do not paste it into Plesk's PHP
   Additional configuration directives field. Do not put the token in the tracked
   root `.htaccess`, source control, command history, or logs.
4. Deploy the endpoint with permission `644`. If the host does not forward
   `Authorization` to PHP, use `X-Sync-Token` instead. Use HTTPS only.
5. Authenticated `GET ?action=ping` must return `200` (no DB access).
   Authenticated `GET ?action=stats` must return the site's real product count.
   Prefer a header over `?token=...`, which may be captured by web access logs.
6. Preview the gateway's full payload with `dry_run=true`, compare counts, then
   send the real push. Do not force the first production push to bypass guards.

If the host cannot provision the PHP environment directly, copy
`config/catalog_sync.example.php` to the ignored `includes/sync_config.php`
(UTF-8 without BOM, permission `640`) and set the real token there using
`putenv('SYNC_TOKEN=...');`. **Before provisioning that secret**, deny HTTP access
to that file in the hosting/server configuration and verify `403` or `404` on the
actual host. Account for nginx as well as Apache. A PHP file returning an empty
`200` is not an access-control check. Do not commit the provisioned file.

The old `../config/catalog_sync.php` and `includes/catalog_sync_config.php`
configuration files are no longer loaded. Migrate their token to `SYNC_TOKEN`;
remove obsolete duplicate credentials securely. Optional schema overrides
(`$CFG_TABLE`, `$CFG_COL_ID`, `$CFG_COL_NAME`, `$CFG_COL_PRICE`, `$CFG_COL_DESC`)
may still be set in `includes/sync_config.php`.

## Contract

All responses are JSON with `Cache-Control: no-store`. Authenticate with
`Authorization: Bearer TOKEN` or `X-Sync-Token: TOKEN`. Only the read-only GET
actions `ping` and `stats` accept a `token` query parameter.

POST body:

```json
{
  "schema": 2,
  "mode": "upsert",
  "dry_run": false,
  "items": [
    {"name": "POL-1095", "price": 6.45, "description": "Bags, 14\" x 18\"", "qty": 120}
  ]
}
```

- Minimum 300 raw **and valid unique** items unless `force: true`; maximum
  2000 raw items even with force; maximum request body 8 MiB.
- Integer `schema: 1` or `2`, `mode: "upsert"` and `items` are required. `dry_run` and `force`
  are optional booleans, defaulting to false. Pruning is not supported.
- Names are printable ASCII, nonblank, at most 100 characters; surrounding spaces
  are trimmed. Matching and payload deduplication are case-insensitive. The last
  valid duplicate wins; existing name spelling is preserved. Ambiguous
  case-insensitive duplicate names in the database cause `409` before writes.
- Prices must be numeric, finite, between 0 and 100000 inclusive, and are rounded
  to two decimals. Zero is valid. Missing descriptions default to an empty string.
  Descriptions must be strings of at most 500 Unicode characters after stripping
  control characters. Quotes and commas are preserved.
- Invalid rows appear in `skipped` with a zero-based `index`, optional `name`,
  and `reason`; the valid remainder is applied subject to the minimum guard.
- In schema 2, optional `qty` accepts JSON integers from -99999 to 999999 or
  explicit `null`. Absent keys leave stock untouched; `null` clears tracking.
  Floats (including `4.0`), strings, booleans, arrays and objects skip the row
  with `qty not an integer`; out-of-range integers use `qty out of range`.
  Raw negatives are stored unchanged. Schema 1 ignores `qty`, even if sent.
  Qty diffs normalize SQL values to `int|null`, so NULL and zero remain distinct.
  `qty_updated_at` is UTC and changes only when qty actually changes (or an
  explicit qty is inserted); unchanged replays never refresh it.
- Existing rows change only price/description and supplied quantity, matched by their primary key.
  New rows insert name/price/description, optional quantity/timestamp, and preserve DB defaults, except that
  when the connected table's `is_hidden` default is 1, new rows explicitly get
  `is_hidden=0` so they are visible. Existing hidden flags, images, categories,
  IDs, sort order and all other fields are untouched. Nothing is deleted or unhidden.
- `counts.valid` counts unique valid names; `counts.received` counts raw input.
  `added` contains normalized new items. `updated` contains `name`, `changed`,
  `from` and `to` (price/description/qty); `changed` can include `qty`.
  `added` includes `qty` only when supplied in schema 2. Response `schema`
  echoes the request version; ping advertises the latest version, 2.
  `total_rows` is the actual DB row count,
  so a dry run reports the unchanged database count, not projected additions.
- Dry runs return the same add/update lists as a live push against the same DB
  snapshot but do not write catalog rows, last-sync state, or the success log.
- After every successful live commit, even an all-unchanged replay, the endpoint
  calls `invalidateProductsCache(true)` in the serving web request. This deletes
  APCu key `daytona_all_products_v1`, advances `daytona_products_generation` for
  suggestions, and removes `<webroot>/data/cache_products.json`. Dry runs and
  rollbacks do not invalidate caches. APCu invalidation must run in the same
  serving pool; CLI execution is not a substitute.
- If cache invalidation fails after commit, the response remains HTTP `200`
  with `ok: true`, `db_committed: true`, and a `warnings` array containing
  `code: "sync_cache_invalidation_failed"` and a message that catalogue or
  suggestions may remain stale. Clients must inspect warnings, not just HTTP
  status. Database work was committed, not rolled back; inspect the server log
  for `stage=cache_invalidation` and repair the cache before declaring success.
  Other post-commit state/log failures still return the explicit `500` below.
- Successful live pushes atomically replace `config/.catalog_sync_state.json`
  and append timestamp, IP and counts to `config/catalog_sync.log`.
  Both paths are relative to the parent of the webroot. The lock is
  `config/.catalog_sync.lock`. For a webroot `/var/www/vhosts/DOMAIN/httpdocs`,
  all three files are in `/var/www/vhosts/DOMAIN/config/`.
  Earlier releases wrote to `../logs/catalog_sync.log`; leave any old audit log
  intact or archive it privately. New entries go only to the config directory.
  Each audit line also appends ` qty=N`, counting changed quantities (including
  tracked inserts). Stats returns `{ok, products, qty, last_sync}`; `last_sync`
  is null before a live push. The authenticated-only `qty` block contains
  `tracked` (non-NULL), `out` (<= 0), `low` (1..24), and `last_update` (maximum
  quantity timestamp as ISO-8601 UTC, or null).
- Concurrent pushes return `409`. Writes use one transaction and roll back on DB
  failure. A failure publishing state/log **after** commit returns an explicit
  JSON `500` saying the DB changes committed; inspect stats/logs before retrying.
  Replaying a successfully applied payload is harmless.

Errors use `{ "ok": false, "error": "..." }`: `400` invalid contract/JSON, `403`
invalid auth, `405` invalid method/action, `409` partial push/lock/ambiguous names,
`413` oversized payload, `503` missing/short token (`sync_token_not_configured`)
or DB bootstrap/connection failure (`sync_db_unreachable`), and `500` other
configuration/filesystem/query failures (`sync_internal_error`). Post-commit
failures retain the explicit warning that database changes committed.
Failures are logged with stage, exception class, source basename and line number,
without tokens, request bodies, DSNs, raw exception messages or traces. These can
contain secrets or catalog values; `debug=1` does not expose them to clients.
Fatal runtime errors also return JSON when PHP can execute the shutdown handler;
server-level failures and syntax errors in the endpoint itself require host-level
JSON error handling.

## Verification

The repository schema defines `products.is_hidden` as `NOT NULL DEFAULT 0`.
This is not verification of the deployed schema. Check the actual production
default read-only before changing insert behavior:

```sql
SELECT COLUMN_NAME, COLUMN_DEFAULT, IS_NULLABLE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'products'
  AND COLUMN_NAME = 'is_hidden';
```

Run this on the site's actual database connection. No returned row means the
column is absent, not default zero. Sync inspects the connected table's column
metadata during pushes and explicitly inserts `is_hidden=0` only when that
column defaults to 1. Tables without that column remain supported. Existing
products' visibility is never updated.

Run `php -l` on all changed PHP files using **PHP 7.1.33**, then run:

```text
php tests/catalog_sync_test.php
php tests/inventory_display_test.php
php tests/inventory_storefront_test.php
```

The storefront suite uses a temporary SQLite database and real HTTP pages,
customer sessions, and checkout. It verifies that an order larger than the
displayed quantity is accepted without changing stock or its timestamp, and
checks authenticated stats afterwards. Email delivery is isolated to the local
development fixture. Use `--browser` on that suite to retain its isolated server
for 180 seconds; visit the printed `/_customer.php` URL before the catalogue.

The integration suite starts an isolated local PHP HTTP server with a temporary
SQLite catalog and the real DB bootstrap; it never writes the site's database.
It verifies no schema initialization, no database creation/fallback, ping without
the DB bootstrap or storage, both token sources, safe diagnostics, and existing
upsert/rollback/dry-run protections.

### Storefront inventory and read-path audit

Both pages call `stock_badge_html()` with their existing price-visibility
boolean. NULL renders nothing; <= 0 renders only "Out of stock"; 1..24 is
amber "Low stock"; >= 25 is green "In stock". Positive quantities show an exact
count. The ASCII ` - ` separator is intentional and PHP 7.1-safe. Detail pages
show UTC-relative freshness only for a known quantity updated less than six
hours ago (never for future timestamps). Styles load only for customers, so
even `cat-stock` selectors are absent from guest page source.

Logged-in catalogue reads bypass the public product cache and fetch stock in
the existing single list query. Default product readers select only public
columns, so cart snapshots, public JSON and the on-disk product cache do not
acquire stock fields. No per-product inventory query was introduced.
The table has three columns for both audiences; guests have an empty Actions
cell and no visible heading for the login/actions cells.

Checked product SELECTs and their callers:

- Shared readers/search/cache/order code: [functions](../includes/functions.php),
  [DB bootstrap](../includes/db.php), [categories](../includes/categories.php),
  [category baseline](../includes/category_baseline.php), and
  [CSV inventory helper](../includes/inventory.php).
- Public pages: [catalogue](../catalogue.php), [details](../productinfo.php),
  [cart](../cart.php), [checkout](../checkout.php), [deals](../deals.php),
  [account](../account.php), [category products](../products.php), and
  [autocomplete JSON](../ajax/search_suggestions.php). No feed or sitemap
  implementation exists in this repository.
- Manager/import paths (unchanged): [manager portal](../managerportal.php),
  [CSV updater](../admin/update_inventory.php), and
  [legacy product remap diagnostic](../admin/diagnose_products_old_remap.php).
- Diagnostic reads: [DB diagnostic](../tools/db_diagnostic.php),
  [single product](../tools/check_product.php),
  [lookup JSON](../tools/check_product_lookup.php),
  [product dump](../tools/inspect_products.php),
  [manager save test](../tools/test_manager_portal_save.php),
  [product save test](../tools/test_product_save.php),
  [SBO test](../tools/test_sbo_product.php),
  [web DB test](../tools/web_db_test.php),
  [web save test](../tools/web_save_test.php), and
  [image migration](../tools/migrate_product_images.php). Diagnostic wildcard
  product SELECTs were changed to explicit public columns.
- Image-management callers use the default inventory-free product reader:
  [manage](../ajax/manage_product_images.php),
  [upload](../ajax/upload_product_image.php), and
  [remove](../ajax/remove_product_image.php).
- The [sync endpoint](catalog_sync.php) is the sole inventory writer and the
  only JSON surface that includes stock, protected by sync-token authentication.

Guest checks assert zero stock labels, selectors, inventory styles, quantity
attributes or counts in rendered source. A literal search for `left` or `-3`
is not a valid stock-leak test: existing alignment CSS/comments use `left`, and
product IDs/URLs can contain `-3`. Similarly `120 left` contains the substring
`0 left`; tests check stock elements and whole zero/negative counts instead.

Production acceptance (do not send a live push until these pass):

1. With the server token provisioned, unauthenticated `GET ?action=ping` returns
   `403`; an authenticated request using `X-Sync-Token` returns `200` with
   `ok`, `php`, `service: "catalog_sync"` and ISO-8601 `time`. Existing `pong` and
   `schema` fields are retained for gateway compatibility. Ping needs neither a
   DB connection nor writable state/log directories.
2. Authenticated `GET ?action=stats` returns `200` and the real site count
   (expected approximately 675; compare with the current catalogue).
3. A full authenticated push with `dry_run: true` returns a diff and leaves
   products and sync metadata unchanged.

For `503` token errors, check token provisioning in the web PHP environment, not
just the gateway shell. For DB errors, check the site's DB settings and PDO
driver. For `500`, find `Catalog sync failed: stage=... class=... at=...` in
Plesk's PHP error log; storage-stage failures commonly mean missing/unwritable
`../config/` or inaccessible existing lock files. Stats never opens the sync log:
after acquiring the lock it connects to the DB, counts products and reads any
existing state JSON. A stats `500` alone does not establish a storage failure;
query errors or corrupt/unreadable state can also cause it. A root-owned log
directory is not inherently unwritable (permissions/ACLs matter), but denied
log creation/append would fail a live push, not stats. Never share tokens or
unredacted credential files.
