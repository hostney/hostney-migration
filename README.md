# Hostney Migration

A WordPress plugin that allows the [Hostney](https://www.hostney.com) worker to pull your site's database and files during a migration. Install it on your source site, paste a migration token from your Hostney control panel, and Hostney handles the rest automatically.

No SSH access required. No FTP credentials. No manual exports. Your source site stays fully operational throughout the process.

## How it works

The plugin exposes a set of authenticated REST API endpoints that the Hostney worker calls to read your database and files. The worker pulls data incrementally in batches, handles retries, and reassembles everything on the destination server.

1. Generate a migration token in your [Hostney control panel](https://www.hostney.com)
2. Install this plugin on your source WordPress site
3. Go to Tools > Hostney Migration and paste the token
4. Click Start Migration in the Hostney control panel
5. The worker pulls your database and files automatically

## Security

All requests from the Hostney worker are authenticated using HMAC-SHA256 signatures. Each request includes a timestamp, token, and signature computed over the request body. The plugin validates all three on every request.

- Tokens are 96-character hex strings stored in the WordPress options table with autoload disabled
- HMAC keys are derived from the token using a domain-separated hash rather than using the raw token as the key
- Timestamps are validated within a 300-second window to prevent replay attacks
- `hash_equals()` is used for all token and signature comparisons to prevent timing attacks
- REST endpoints are registered only while a token is stored; deactivating the plugin clears all credentials
- The token is deleted when Hostney calls `/disconnect` (the migration completed, failed for good or was cancelled, or its token was revoked or expired), and in any case 7 days after Connect
- The failed-authentication lockout (per connecting IP) is only consulted for requests that fail; a correctly signed request is never held back by other clients behind the same proxy

### WAF bypass encoding

WordPress sites protected by WAFs (ModSecurity, Wordfence, Imunify, Cloudflare) may block REST API responses containing SQL statements, PHP code, or other patterns that trigger WAF rules. The plugin handles this transparently.

When the Hostney worker sends `X-Migration-Encoding: base64`, the plugin wraps the entire JSON response in base64 before sending it. The worker decodes the response on its end. WAFs inspect the response body and see harmless base64 text rather than SQL or PHP content.

The same mechanism works in reverse for POST request bodies. If the worker wraps a request body in `{"_b64": "..."}`, the plugin decodes it before processing and verifies the HMAC against the original decoded content.

### Path security

File export is restricted to the WordPress root directory (ABSPATH). The plugin validates every file path request by:

- Rejecting a `..` path segment (since 1.0.7 a name that merely contains two dots, such as `Wait...what.jpg`, is an ordinary file)
- Rejecting null bytes
- Resolving the real path with `realpath()` and confirming it sits within the resolved ABSPATH, compared up to a directory separator so a neighbouring folder with the same name prefix does not pass

### What gets excluded

The following are excluded from file migration automatically:

- `cache`, `upgrade`, `upgrade-temp-backup`, `wflogs`, `ai1wm-backups`, `updraft` and `backup*` folders - **only directly inside wp-content** (since 1.0.6; before, any folder with these names was skipped at any depth, including a plugin's own code such as Smush's `core/backups/` and Elementor's `core/upgrade/`)
- Log files
- Node modules
- `.git` directories
- `.DS_Store`, `Thumbs.db`, and similar system files

## REST API endpoints

All endpoints are under the `hostney-migrate/v1` namespace and require valid authentication headers on every request.

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/status` | Health check, returns PHP/WP versions and table prefix; since 1.0.7 also the folder layout (`layout`), multisite facts (`multisite`), the prefixes of other WordPress sites sharing the database (`other_installs`) and `features` |
| GET | `/db/tables` | Lists all database tables with row counts, sizes, engines, and CREATE statements |
| POST | `/db/rows` | Returns a batch of rows from a table using primary key pagination |
| GET | `/fs/scan` | Scans the WordPress filesystem and returns a full file list with metadata |
| POST | `/fs/scan` | Since 1.0.7: one page of the file list; body `{ "cursor": "", "limit": 5000 }`, answer `done` and `next_cursor` |
| POST | `/fs/read` | Reads a file chunk at a given byte offset, returns base64-encoded data with MD5 checksum |
| POST | `/disconnect` | Deletes the stored token; body `{ "reason": "completed" }` (or `failed`, `cancelled`, `revoked`, `expired`) is shown on the admin screen |

### Authentication headers

Every request from the Hostney worker includes:

```
X-Migration-Token: <96-char hex token>
X-Migration-Timestamp: <unix timestamp>
X-Migration-Signature: <hmac-sha256 hex>
```

The signature is computed as:

```
key = SHA256("hostney-hmac-signing:" + token)
signature = HMAC-SHA256(key, timestamp + request_body)
```

### Database export

Tables are exported in batches using primary key pagination. For tables with a numeric primary key, the worker passes `last_id` and receives the next batch of rows ordered by that key. This avoids the performance problems of LIMIT/OFFSET pagination on large tables.

For tables without a numeric primary key, the plugin falls back to LIMIT/OFFSET pagination, ordered by the primary key when the table has one. Binary and BLOB columns are automatically base64-encoded with a `base64:` prefix so they survive JSON transport cleanly.

Since 1.0.5 the worker can ask for key paging instead by sending `cursor` (`""` for the first page). The plugin then orders the table by its primary key, or its first unique key whose columns are all NOT NULL, compares on every column of that key, and returns `paging: "key"` with a `next_cursor` to send back for the next page. Rows added or removed during the export cannot shift the pages, and a multi-column key is never split between two pages. A table without a usable key is paged the original way, and the response says so (`paging: "id"` or `"offset"`). A request without `cursor` is paged as in 1.0.4, except that the OFFSET path now orders by the primary key. That original paging cannot finish a table whose numeric key is DECIMAL or FLOAT, or holds values of 0 or below (`last_id` goes through `intval()`, and anything below 1 starts again from the top); key paging has neither problem for integer and DECIMAL keys.

Batch size is adaptive. If building a batch throws, the plugin halves the batch size and retries up to three times before returning an error. PHP running out of memory is a fatal error rather than an exception and ends the request; the worker then asks again for fewer rows. Since 1.0.7 the routes ask WordPress for the admin area's memory limit (`wp_raise_memory_limit()`) where the host allows it.

### File export

The filesystem scan returns relative paths, file sizes, modification times, and permissions for every file under ABSPATH up to a limit of 500,000 files.

Since 1.0.7 the list can also be read in pages (`POST /fs/scan`). Each folder's entries are sorted byte by byte and a folder is listed just before its contents; a page holds at most `limit` entries, ends early after 20 seconds, and starts strictly after `cursor`, the last path the previous page listed. Files added or removed between two pages therefore cannot shift a page. `skipped` then describes that page only.

`/status` reports `layout.problem` when the site's content, plugins, must-use plugins or uploads folder is not in its standard place inside the WordPress folder (`content_outside`, `content_renamed`, `plugins_elsewhere`, `mu_plugins_elsewhere`, `uploads_elsewhere`). Such a site cannot be copied folder for folder, and Hostney refuses it at Connect.

File content is read in chunks of up to 5MB per request. Each chunk response includes the byte offset, actual bytes read, total file size, and an MD5 checksum of the chunk data for integrity verification.

## Requirements

- WordPress 5.0 or later
- PHP 7.4 or later
- The `hash` PHP extension (available by default on PHP 7.4+)

## Installation

The plugin is in the WordPress.org directory: in your WordPress admin go to Plugins > Add New, search for "Hostney Migration", then click Install Now and Activate. Or download the ZIP from the [releases page](https://github.com/hostney/hostney-migration/releases) and upload it under Plugins > Add New > Upload Plugin.

Then go to Tools > Hostney Migration, paste your migration token and click Connect.

No Hostney account yet? The token comes from the Hostney control panel, and the 14-day free trial (no credit card) is enough to run a migration - [see the plans](https://www.hostney.com/pricing).

## Configuration

The API endpoint is configurable for development and testing. Add this to `wp-config.php` to override the default:

```php
define( 'HOSTNEY_MIGRATION_API_BASE', 'https://dev.example.com/api/v2/public/plugin-migration' );
```

## License

GPL v2 or later. See [LICENSE](LICENSE) for details.

---

Built by [Hostney](https://www.hostney.com) - Web hosting with container isolation, ML-based bot protection, and a custom control panel built from the ground up.