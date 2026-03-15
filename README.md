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
- REST endpoints are only active while a token is stored; deactivating the plugin clears all credentials

### WAF bypass encoding

WordPress sites protected by WAFs (ModSecurity, Wordfence, Imunify, Cloudflare) may block REST API responses containing SQL statements, PHP code, or other patterns that trigger WAF rules. The plugin handles this transparently.

When the Hostney worker sends `X-Migration-Encoding: base64`, the plugin wraps the entire JSON response in base64 before sending it. The worker decodes the response on its end. WAFs inspect the response body and see harmless base64 text rather than SQL or PHP content.

The same mechanism works in reverse for POST request bodies. If the worker wraps a request body in `{"_b64": "..."}`, the plugin decodes it before processing and verifies the HMAC against the original decoded content.

### Path security

File export is restricted to the WordPress root directory (ABSPATH). The plugin validates every file path request by:

- Rejecting path traversal sequences (`..`)
- Rejecting null bytes
- Resolving the real path with `realpath()` and confirming it sits within the resolved ABSPATH

### What gets excluded

The following are excluded from file migration automatically:

- Cache directories
- Log files
- Backup plugin directories (UpdraftPlus, All-in-One WP Migration, etc.)
- Node modules
- `.git` directories
- `.DS_Store`, `Thumbs.db`, and similar system files

## REST API endpoints

All endpoints are under the `hostney-migrate/v1` namespace and require valid authentication headers on every request.

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/status` | Health check, returns PHP/WP versions and table prefix |
| GET | `/db/tables` | Lists all database tables with row counts, sizes, engines, and CREATE statements |
| POST | `/db/rows` | Returns a batch of rows from a table using primary key pagination |
| GET | `/fs/scan` | Scans the WordPress filesystem and returns a full file list with metadata |
| POST | `/fs/read` | Reads a file chunk at a given byte offset, returns base64-encoded data with MD5 checksum |

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

For tables without a numeric primary key, the plugin falls back to LIMIT/OFFSET pagination. Binary and BLOB columns are automatically base64-encoded with a `base64:` prefix so they survive JSON transport cleanly.

Batch size is adaptive. If a batch fails due to memory constraints, the plugin halves the batch size and retries up to three times before returning an error.

### File export

The filesystem scan returns relative paths, file sizes, modification times, and permissions for every file under ABSPATH up to a limit of 500,000 files.

File content is read in chunks of up to 5MB per request. Each chunk response includes the byte offset, actual bytes read, total file size, and an MD5 checksum of the chunk data for integrity verification.

## Requirements

- WordPress 5.0 or later
- PHP 7.4 or later
- The `hash` PHP extension (available by default on PHP 7.4+)

## Installation

Download the plugin ZIP from your [Hostney control panel](https://www.hostney.com) or from the [releases page](https://github.com/hostney/hostney-migration/releases).

1. In your WordPress admin go to Plugins > Add New > Upload Plugin
2. Upload the ZIP and activate the plugin
3. Go to Tools > Hostney Migration
4. Paste your migration token and click Connect

## Configuration

The API endpoint is configurable for development and testing. Add this to `wp-config.php` to override the default:

```php
define( 'HOSTNEY_MIGRATION_API_BASE', 'https://dev.example.com/api/v2/public/plugin-migration' );
```

## License

GPL v2 or later. See [LICENSE](LICENSE) for details.

---

Built by [Hostney](https://www.hostney.com) - Web hosting with container isolation, ML-based bot protection, and a custom control panel built from the ground up.