=== Hostney Migration ===
Contributors: hostney
Tags: migration, hosting, transfer, move, import
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
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

All requests from the Hostney worker are authenticated using HMAC-SHA256 signatures with timestamp validation and replay protection. Tokens are cleared automatically when the plugin is deactivated.

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

= Can I use this plugin with a WAF (ModSecurity, Wordfence, Imunify, Cloudflare)? =

Yes. The plugin supports optional base64 encoding of request and response bodies to prevent WAF false positives on SQL or PHP content in migration data. This is handled automatically by the Hostney worker.

== Changelog ==

= 1.0.0 =
* Initial release
* Database export with primary key pagination and adaptive batch sizing
* Filesystem export with chunked transfers and MD5 checksum verification
* HMAC-SHA256 authentication with timestamp validation
* WAF-compatible base64 encoding for request and response bodies
* Path traversal protection with realpath validation
* Admin UI with system requirement checks

== Upgrade Notice ==

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
