# Catalog sync

`catalog_sync.php` is a standalone PHP 7.1+ / PDO endpoint. It does not load the
site bootstrap, change the schema, or call the manager portal CSV importer.
It supports MySQL (InnoDB required for rollback) and SQLite.

## Deployment

This repository's root is the webroot: deploy `api/catalog_sync.php` as
`httpdocs/api/catalog_sync.php`.

1. Create `config/` and `logs/` **beside**, not inside, `httpdocs/`.
   Give the web user ownership and directory permissions `775`. These must be
   persistent and writable by PHP. Restrict parent directory access to trusted
   users; the token must not be web-accessible.
2. Copy `config/catalog_sync.example.php` from this repository to
   `../config/catalog_sync.php` relative to the webroot. Save as UTF-8 without BOM,
   permission `640`. Set the real DB DSN/user/password. This connection must target
   the same database as the site, including hidden rows. There is no SQLite
   fallback on a failed MySQL connection.
3. Generate a token using `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`,
   securely put it in `$SYNC_TOKEN` in the private config, and deliver it privately
   to the gateway's user environment as `DSD_SYNC_TOKEN`. Never commit it, put it
   in a command history, or print it in logs.
4. Deploy the endpoint with permission `644`. If the host does not forward
   `Authorization` to PHP, use `X-Sync-Token` instead. Use HTTPS only.
5. Authenticated `GET ?action=ping` must return `200` (no DB access).
   Authenticated `GET ?action=stats` must return the site's real product count.
   Prefer a header over `?token=...`, which may be captured by web access logs.
6. Preview the gateway's full payload with `dry_run=true`, compare counts, then
   send the real push. Do not force the first production push to bypass guards.

If the outside-webroot config location is unavailable, the endpoint also accepts
`includes/catalog_sync_config.php`. **Before creating that file**, deny HTTP access
to `includes/` with Apache 2.4 `Require all denied` in `includes/.htaccess`, and
verify denial on the actual host. For nginx, configure an equivalent server rule;
`.htaccess` alone does not protect nginx. The endpoint then keeps its state/lock
beside that fallback file; logs still go to `../logs/`.

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
  New rows insert only name/price/description. Hidden flags, images, categories,
  IDs, sort order and all other fields are untouched. Nothing is deleted.
- `counts.valid` counts unique valid names; `counts.received` counts raw input.
  `added` contains normalized new items. `updated` contains `name`, `changed`,
  `from` and `to` (price/description). `total_rows` is the actual DB row count,
  so a dry run reports the unchanged database count, not projected additions.
- Dry runs return the same add/update lists as a live push against the same DB
  snapshot but do not write catalog rows, last-sync state, or the success log.
- Successful live pushes atomically replace `config/.catalog_sync_state.json`
  and append timestamp, IP and counts to `logs/catalog_sync.log`.
  Stats returns `{ok, products, last_sync}`; `last_sync` is null before a live push.
- Concurrent pushes return `409`. Writes use one transaction and roll back on DB
  failure. A failure publishing state/log **after** commit returns an explicit
  JSON `500` saying the DB changes committed; inspect stats/logs before retrying.
  Replaying a successfully applied payload is harmless.

Errors use `{ "ok": false, "error": "..." }`: `400` invalid contract/JSON, `403`
invalid auth, `405` invalid method/action, `409` partial push/lock/ambiguous names,
`413` oversized payload and `500` configuration/database/filesystem failures.
Failures are logged without tokens, request bodies, DSNs or DB exception details.
Fatal runtime errors also return JSON when PHP can execute the shutdown handler;
server-level failures and syntax errors in the endpoint itself require host-level
JSON error handling.

## Verification

Run `php -l api/catalog_sync.php` and `php tests/catalog_sync_test.php`.
The integration suite starts an isolated local PHP HTTP server with a temporary
SQLite catalog and private config; it never writes the site's database. The
endpoint uses PHP 7.1 syntax/APIs; run these checks under PHP 7.1 on hosts that
require that runtime as well.
