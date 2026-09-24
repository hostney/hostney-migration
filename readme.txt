=== Hostney Migration ===
Contributors: hostney
Tags: migration, hosting, transfer, move, import
Requires at least: 5.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Migrate your WordPress site to Hostney hosting. Paste your migration token and Hostney handles the rest automatically.

== Description ==

Hostney Migration connects your WordPress site to the Hostney hosting platform so the Hostney worker can pull your database and files automatically. No SSH access, FTP credentials, or manual exports required. Your source site stays fully operational throughout the process.

**How it works:**

1. Generate a migration token in your [Hostney control panel](https://www.hostney.com)
2. Install this plugin on your source WordPress site
3. Go to Tools > Hostney Migration and paste the token
4. Click "Start migration" in the Hostney control panel
5. The worker pulls your database and files automatically

**What gets migrated:**

* All database tables (exported row-by-row with primary key pagination)
* All WordPress files (transferred in chunks with checksum verification)
* File permissions and directory structure

**What gets excluded automatically:**

* Cache directories
* Log files
* Backup plugin directories (UpdraftPlus, All-in-One WP Migration, etc.)
* Node modules and .git directories

**Security:**

All requests from the Hostney worker are authenticated using HMAC-SHA256 signatures with timestamp validation and replay protection. The migration token is removed from your site automatically when the migration is over, when it is revoked in the Hostney control panel, 7 days after you connected at the latest, and when the plugin is deactivated. While no token is stored, the plugin's REST API endpoints do not exist.

== Installation ==

1. In your WordPress admin go to Plugins > Add New > Upload Plugin
2. Upload the ZIP file and activate the plugin
3. Go to Tools > Hostney Migration
4. Paste your migration token from the Hostney control panel and click Connect

== Frequently Asked Questions ==

= Do I need SSH or FTP access on my current host? =

No. The plugin exposes authenticated REST API endpoints that the Hostney worker calls over HTTPS. No server-level access is needed on the source site.

= Will my site go down during migration? =

No. The plugin only reads data — it does not modify anything on your source site. Your site continues to operate normally throughout the migration.

= What data is sent to Hostney when I connect? =

When you click Connect, the plugin sends your site URL, WordPress version, PHP version, and database/file size estimates to Hostney servers to register the migration. No file contents or database data are sent during this step. The actual data transfer only happens when you start the migration from the Hostney control panel, and it flows through authenticated REST API endpoints.

= Does this plugin work with multisite? =

No. WordPress multisite installations are not supported. The plugin will show a warning if multisite is detected.

= What happens to the token when I deactivate the plugin? =

The migration token and connection status are automatically deleted from the database when the plugin is deactivated.

= Does the token stay on my site after the migration? =

No. When the migration completes, fails for good or is cancelled, and when its token is revoked or expires in the Hostney control panel, Hostney tells the plugin to disconnect and the token is deleted. If that message cannot reach your site, the connection still ends on its own 7 days after you connected. The Tools > Hostney Migration screen then says why the site was disconnected.

= Can I use this plugin with a WAF (ModSecurity, Wordfence, Imunify, Cloudflare)? =

Yes. The plugin supports optional base64 encoding of request and response bodies to prevent WAF false positives on SQL or PHP content in migration data. This is handled automatically by the Hostney worker.

== Changelog ==

= 1.0.5 =
* Security: the migration token is deleted from your site when the migration is over.
  Hostney now tells the plugin to disconnect when a migration completes, fails for
  good or is cancelled, and when its token is revoked or expires. Until now the token
  stayed in your site's settings until you clicked Disconnect or deactivated the plugin.
* Security: a connection now ends on its own 7 days after you connected, even if
  Hostney cannot reach your site to disconnect it.
* Security: the plugin's REST API endpoints now exist only while a token is stored.
* Security: the file reader's check that a file sits inside your WordPress folder no
  longer accepts a neighbouring folder whose name starts the same way.
* Fixed: failed sign-in attempts by other visitors behind the same proxy (Cloudflare,
  for example) could pause a running migration for up to a minute. A correctly signed
  request is never held back by someone else's failures now.
* Fixed: every request was counted twice against the rate limit, and every failed
  sign-in twice against the lockout, because WordPress checks a route's permissions a
  second time after answering it. The limit of 900 requests a minute was really 450,
  which a migration could reach and then have to wait out.
* Changed: when Hostney asks for it, database tables are exported in key order, one
  page strictly after the last. Rows added or removed on a busy site during the
  migration can no longer shift the pages, and tables whose primary key has more than
  one column are no longer split part-way through a key.

= 1.0.4 =
* Added: the system requirements panel now reports the size of your database.
* Changed: a site whose database is larger than the destination hosting plan allows
  is now told so when it connects, instead of partway through the migration. Nothing
  is copied in that case, and reconnecting works once the plan is upgraded or the
  database is reduced.

= 1.0.3 =
* Fixed: the per-IP rate limiter refreshed its own expiry on every request, so the
  60-second window never elapsed during a migration and the limit behaved as a
  one-time cap of 900 requests. Large sites were rejected with HTTP 429 partway
  through the database export.
* Added: a Retry-After header on rate-limited responses, so the caller knows exactly
  how long to wait instead of guessing.
* Changed: the request rate limit now applies only to authenticated requests.
  Unauthenticated traffic is handled by a separate failed-authentication throttle and
  can no longer consume a running migration's request budget.

= 1.0.2 =
* Confirmed compatibility with WordPress 7.1

= 1.0.1 =
* Confirmed compatibility with WordPress 7.0

= 1.0.0 =
* Initial release
* Database export with primary key pagination and adaptive batch sizing
* Filesystem export with chunked transfers and MD5 checksum verification
* HMAC-SHA256 authentication with timestamp validation
* WAF-compatible base64 encoding for request and response bodies
* Path traversal protection with realpath validation
* Admin UI with system requirement checks

== Upgrade Notice ==

= 1.0.5 =
Security update: the migration token is removed from your site when the migration is over, and a connection ends on its own after 7 days. Recommended for all users.

= 1.0.4 =
Shows your database size in the system requirements, and reports a database that is too large for the destination hosting plan when you connect rather than after the migration has started.

= 1.0.3 =
Fixes migrations of larger sites failing with a "Too many requests" error partway through the database export. Recommended for all users.

= 1.0.2 =
Confirmed compatible with WordPress 7.1.

= 1.0.1 =
Confirmed compatible with WordPress 7.0.

= 1.0.0 =
Initial release.

== Privacy Policy ==

This plugin sends the following data to Hostney servers (api.hostney.com) when you click Connect:

* Your site URL and REST API URL
* WordPress version
* PHP version
* Number of database tables and total database size
* Number of files and total file size
* Active theme name and active plugin count

This data is used solely to register and plan the migration. No file contents, database rows, passwords, or personal data are transmitted during the connection step.

During an active migration (initiated from the Hostney control panel), the Hostney worker connects to your site's REST API endpoints to read database tables and files. All requests are authenticated with HMAC-SHA256 signatures. Data is transferred over HTTPS.

For more information, see the [Hostney Privacy Policy](https://www.hostney.com/documents/privacy-policy).
