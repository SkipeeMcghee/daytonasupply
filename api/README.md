# Catalog sync

`catalog_sync.php` is a PHP 7.1+ / PDO endpoint. It uses `includes/db.php` and
`getDb(false)` to connect with the site's existing `DB_*` environment, without
schema initialization, migrations, or the manager portal CSV importer.
It supports MySQL (InnoDB required for rollback) and existing SQLite databases.
Sync never falls back to SQLite when MySQL fails.

## Deployment

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
  "schema": 1,
  "mode": "upsert",
  "dry_run": false,
  "items": [
    {"name": "POL-1095", "price": 6.45, "description": "Bags, 14\" x 18\""}
  ]
}
```

- Minimum 300 raw **and valid unique** items unless `force: true`; maximum
  2000 raw items even with force; maximum request body 8 MiB.
- `schema: 1`, `mode: "upsert"` and `items` are required. `dry_run` and `force`
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
- Existing rows change only price/description, matched by their primary key.
  New rows insert name/price/description and preserve DB defaults, except that
  when the connected table's `is_hidden` default is 1, new rows explicitly get
  `is_hidden=0` so they are visible. Existing hidden flags, images, categories,
  IDs, sort order and all other fields are untouched. Nothing is deleted.
- `counts.valid` counts unique valid names; `counts.received` counts raw input.
  `added` contains normalized new items. `updated` contains `name`, `changed`,
  `from` and `to` (price/description). `total_rows` is the actual DB row count,
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
  Stats returns `{ok, products, last_sync}`; `last_sync` is null before a live push.
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

Run `php -l api/catalog_sync.php`, `php -l includes/db.php`, and
`php tests/catalog_sync_test.php` using PHP 7.1.33 for the current production host.
The integration suite starts an isolated local PHP HTTP server with a temporary
SQLite catalog and the real DB bootstrap; it never writes the site's database.
It verifies no schema initialization, no database creation/fallback, ping without
the DB bootstrap or storage, both token sources, safe diagnostics, and existing
upsert/rollback/dry-run protections.

Production acceptance (do not send a live push until these pass):

1. With the server token provisioned, unauthenticated `GET ?action=ping` returns
   `403`; an authenticated request using `X-Sync-Token` returns `200` with
   `ok`, `php`, `service: "catalog_sync"` and ISO-8601 `time`. Existing `pong` and
   `schema` fields are retained for gateway compatibility. Ping needs neither a
   DB connection nor writable state/log directories.
2. Authenticated `GET ?action=stats` returns `200` and the real site count
   (expected approximately 660; compare with the current catalogue).
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
