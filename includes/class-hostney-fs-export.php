<?php
/**
 * Hostney Migration - Filesystem Export
 *
 * Scans WordPress files and serves them in chunks.
 * Path validation ensures files are within ABSPATH only.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Hostney_FS_Export {

    /**
     * Directories skipped wherever they are: version control and build
     * tooling. WordPress never loads anything from them.
     */
    const SKIP_EVERYWHERE = array( '.git', 'node_modules' );

    /**
     * Directories skipped ONLY directly inside the content folder (wp-content):
     * page caches, update scratch space, security-plugin logs and backup
     * archives. Any other folder there whose name starts with "backup" is
     * skipped too (backup-db, backups-dup-lite, ...).
     *
     * Until 1.0.6 these names were skipped at EVERY depth, which also skipped
     * a plugin's own code kept in a folder with one of these names - Smush
     * keeps classes in core/backups/, Elementor in core/upgrade/, and bundled
     * libraries such as psr/cache live in a cache/ folder. The migrated site
     * then stopped with WordPress's critical error page.
     */
    const SKIP_IN_CONTENT_ROOT = array( 'cache', 'upgrade', 'upgrade-temp-backup', 'wflogs', 'ai1wm-backups', 'updraft' );

    /**
     * At most this many unreadable paths are named in the scan report; the
     * count covers the rest.
     */
    const MAX_REPORTED_PATHS = 20;

    /**
     * Entries in one page of the paged file list (scan_page()): the default,
     * and the most a caller may ask for.
     */
    const SCAN_PAGE_DEFAULT = 5000;
    const SCAN_PAGE_MAX     = 20000;

    /**
     * Seconds a page of the file list may take before it ends early. Well under
     * the timeouts of the proxies sites sit behind (Cloudflare gives up after
     * 100 seconds), so a slow disk ends a page instead of the whole request.
     */
    const SCAN_PAGE_SECONDS = 20;

    /**
     * Longest paging cursor accepted back from the worker, in characters.
     */
    const MAX_SCAN_CURSOR_LENGTH = 4096;

    /**
     * Problems layout() reports. A site with one of these cannot be copied
     * folder for folder: the scan and the file reader only see the WordPress
     * folder, and the new site gets the standard folders.
     */
    const LAYOUT_PROBLEMS = array( 'content_outside', 'content_renamed', 'plugins_elsewhere', 'mu_plugins_elsewhere', 'uploads_elsewhere' );

    /**
     * Where the current page of scan_page() ends: an entry count and a time.
     */
    private $page_limit    = 0;
    private $page_deadline = 0.0;

    /**
     * What the last scan() - or the last page of scan_page() - left out, so
     * Hostney can say so instead of the site arriving with files silently missing.
     */
    private $truncated = false;
    private $skipped_links = 0;
    private $unreadable = 0;
    private $unreadable_paths = array();

    /**
     * Whether a directory is left out of the migration.
     *
     * Shared with the size estimate (Hostney_Metadata) so the estimate counts
     * exactly what is transferred.
     *
     * @param string $parent Absolute path of the folder that holds it.
     * @param string $name   The directory's own name.
     * @return bool
     */
    public static function skip_directory( $parent, $name ) {
        if ( in_array( $name, self::SKIP_EVERYWHERE, true ) ) {
            return true;
        }
        if ( ! self::is_content_root( $parent ) ) {
            return false;
        }
        return in_array( $name, self::SKIP_IN_CONTENT_ROOT, true ) || 0 === strpos( $name, 'backup' );
    }

    /**
     * Whether $dir is the content folder: wp-content in the WordPress folder,
     * and WP_CONTENT_DIR when a site moved it.
     *
     * @param string $dir Absolute path.
     * @return bool
     */
    private static function is_content_root( $dir ) {
        static $roots = null;
        if ( null === $roots ) {
            $roots = array( self::normalize_path( ABSPATH . 'wp-content' ) );
            if ( defined( 'WP_CONTENT_DIR' ) ) {
                $roots[] = self::normalize_path( WP_CONTENT_DIR );
            }
        }
        return in_array( self::normalize_path( $dir ), $roots, true );
    }

    /**
     * @param string $path
     * @return string Forward slashes, no trailing slash.
     */
    private static function normalize_path( $path ) {
        return rtrim( wp_normalize_path( $path ), '/' );
    }

    /**
     * True when the last scan() stopped at its file limit - the list is
     * incomplete.
     *
     * @return bool
     */
    public function was_truncated() {
        return $this->truncated;
    }

    /**
     * What the last scan() could not include.
     *
     * @return array { links: int, unreadable: int, unreadable_paths: string[] }
     */
    public function skipped() {
        return array(
            'links'            => $this->skipped_links,
            'unreadable'       => $this->unreadable,
            'unreadable_paths' => $this->unreadable_paths,
        );
    }

    /**
     * @param string $relative_path
     */
    private function note_unreadable( $relative_path ) {
        $this->unreadable++;
        if ( count( $this->unreadable_paths ) < self::MAX_REPORTED_PATHS ) {
            $this->unreadable_paths[] = $relative_path;
        }
    }

    /**
     * Files to exclude from migration
     */
    private $excluded_files = array(
        'error_log',
        'debug.log',
        '.DS_Store',
        'Thumbs.db',
    );

    /**
     * File extensions to exclude
     */
    private $excluded_extensions = array(
        'log',
    );

    /**
     * Scan WordPress directory and return file list with metadata
     *
     * @return array File list with path, size, mtime, perms, type
     */
    public function scan() {
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- required for large site scans on shared hosting with short max_execution_time
        @set_time_limit( 300 );

        $files = array();
        $base_path = rtrim( ABSPATH, '/' );
        $max_files = 500000; // Safety limit

        $this->truncated        = false;
        $this->skipped_links    = 0;
        $this->unreadable       = 0;
        $this->unreadable_paths = array();

        $this->scan_directory( $base_path, $base_path, $files, $max_files );

        return $files;
    }

    /**
     * Recursively scan a directory
     *
     * @param string $dir Current directory
     * @param string $base_path WordPress root
     * @param array  $files File list (by reference)
     * @param int    $max_files Safety limit
     */
    private function scan_directory( $dir, $base_path, &$files, $max_files ) {
        if ( count( $files ) >= $max_files ) {
            $this->truncated = true;
            return;
        }

        $handle = @opendir( $dir );
        if ( ! $handle ) {
            // A folder PHP may not open arrives EMPTY - a plugin folder like
            // that breaks the site. Report it instead of returning quietly.
            $this->note_unreadable( $dir === $base_path ? '.' : substr( $dir, strlen( $base_path ) + 1 ) );
            return;
        }

        while ( false !== ( $entry = readdir( $handle ) ) ) {
            if ( $entry === '.' || $entry === '..' ) {
                continue;
            }

            if ( count( $files ) >= $max_files ) {
                $this->truncated = true;
                break;
            }

            $full_path = $dir . '/' . $entry;
            $relative_path = substr( $full_path, strlen( $base_path ) + 1 );

            $record = $this->entry_record( $dir, $entry, $full_path, $relative_path );
            if ( null === $record ) {
                continue;
            }

            $files[] = $record;

            if ( 'directory' === $record['type'] ) {
                $this->scan_directory( $full_path, $base_path, $files, $max_files );
            }
        }

        closedir( $handle );
    }

    /**
     * One folder entry as the file list reports it, or null when it is left
     * out. Shared by scan() and scan_page(), so both lists leave out the same
     * things and count them the same way.
     *
     * @param string $dir           Absolute path of the folder that holds it.
     * @param string $entry         The entry's own name.
     * @param string $full_path     Absolute path of the entry.
     * @param string $relative_path Path relative to the WordPress folder.
     * @return array|null
     */
    private function entry_record( $dir, $entry, $full_path, $relative_path ) {
        // Skip symlinks (counted, so a linked plugin folder is not lost silently)
        if ( is_link( $full_path ) ) {
            $this->skipped_links++;
            return null;
        }

        if ( is_dir( $full_path ) ) {
            if ( self::skip_directory( $dir, $entry ) ) {
                return null;
            }

            return array(
                'path'  => $relative_path,
                'type'  => 'directory',
                'size'  => 0,
                'mtime' => filemtime( $full_path ),
                'perms' => substr( decoct( fileperms( $full_path ) ), -4 ),
            );
        }

        // Check excluded files
        if ( in_array( $entry, $this->excluded_files, true ) ) {
            return null;
        }

        // Check excluded extensions
        $extension = strtolower( pathinfo( $entry, PATHINFO_EXTENSION ) );
        if ( in_array( $extension, $this->excluded_extensions, true ) ) {
            return null;
        }

        $stat = @stat( $full_path );
        if ( ! $stat ) {
            $this->note_unreadable( $relative_path );
            return null;
        }

        return array(
            'path'  => $relative_path,
            'type'  => 'file',
            'size'  => $stat['size'],
            'mtime' => $stat['mtime'],
            'perms' => substr( decoct( $stat['mode'] ), -4 ),
        );
    }

    /**
     * One page of the file list (1.0.7).
     *
     * scan() lists the whole site in one request, which on a big site runs into
     * the time a proxy allows a request, or into PHP's memory limit while the list
     * is built. A page holds at most $limit entries, ends early once
     * SCAN_PAGE_SECONDS have passed, and says where the next one starts.
     *
     * The order: each folder's entries sorted byte by byte, a folder listed just
     * before its contents. A page starts strictly after the cursor - the last path
     * the previous page listed - in that order, so files added or removed between
     * two pages cannot shift a page: nothing is listed twice, and nothing that was
     * there throughout is missed. The same idea as the database's key paging.
     *
     * What is left out, and how it is counted, is exactly what scan() leaves out;
     * skipped() then describes this page only.
     *
     * @param string $cursor '' for the first page, then the previous page's next_cursor.
     * @param int    $limit  Most entries in the page.
     * @return array|WP_Error { files: array, done: bool, next_cursor: string ('' when done) }
     */
    public function scan_page( $cursor, $limit ) {
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- a page is bounded by SCAN_PAGE_SECONDS; this only lifts a shorter host default
        @set_time_limit( 120 );

        $after = $this->parse_scan_cursor( $cursor );
        if ( is_wp_error( $after ) ) {
            return $after;
        }

        $this->truncated        = false;
        $this->skipped_links    = 0;
        $this->unreadable       = 0;
        $this->unreadable_paths = array();
        $this->page_limit       = max( 1, min( self::SCAN_PAGE_MAX, intval( $limit ) ) );
        $this->page_deadline    = microtime( true ) + self::SCAN_PAGE_SECONDS;

        $files = array();
        $done  = $this->walk_page( rtrim( ABSPATH, '/' ), '', $after, $files );

        return array(
            'files'       => $files,
            'done'        => $done,
            // Never empty while more follow: a page always lists at least one
            // entry (page_full()), so the cursor moves on every page.
            'next_cursor' => $done || empty( $files ) ? '' : $files[ count( $files ) - 1 ]['path'],
        );
    }

    /**
     * Walk one folder for scan_page(), in the page order.
     *
     * @param string $dir   Absolute path of the folder.
     * @param string $rel   Its path relative to the WordPress folder ('' for the root).
     * @param array  $after What is left of the cursor below this folder: the entries
     *                      up to and including it were listed by an earlier page.
     *                      Empty: walk the whole folder.
     * @param array  $files The page (by reference).
     * @return bool True when the folder was walked to its end, false when the page
     *              ended inside it.
     */
    private function walk_page( $dir, $rel, $after, &$files ) {
        // A folder reached only after the page filled up is opened by the next
        // page - opened here as well, an unreadable one would be counted twice.
        if ( empty( $after ) && $this->page_full( $files ) ) {
            return false;
        }

        $entries = $this->sorted_entries( $dir );
        if ( false === $entries ) {
            $this->note_unreadable( '' === $rel ? '.' : $rel );
            return true;
        }

        $first = empty( $after ) ? null : $after[0];

        foreach ( $entries as $entry ) {
            $full_path     = $dir . '/' . $entry;
            $relative_path = '' === $rel ? $entry : $rel . '/' . $entry;

            if ( null !== $first ) {
                $order = strcmp( $entry, $first );
                if ( $order < 0 ) {
                    // Listed by an earlier page.
                    continue;
                }
                if ( 0 === $order ) {
                    // The cursor runs through this entry: it was listed already. A
                    // folder carries on inside, after the rest of the cursor (from
                    // its start when the cursor was the folder itself).
                    $first = null;
                    if ( ! is_link( $full_path ) && is_dir( $full_path ) && ! self::skip_directory( $dir, $entry ) ) {
                        if ( ! $this->walk_page( $full_path, $relative_path, array_slice( $after, 1 ), $files ) ) {
                            return false;
                        }
                    }
                    continue;
                }
                // The cursor's entry is gone (deleted since): everything after its
                // place is new to this walk.
                $first = null;
            }

            if ( $this->page_full( $files ) ) {
                return false;
            }

            $record = $this->entry_record( $dir, $entry, $full_path, $relative_path );
            if ( null === $record ) {
                continue;
            }

            $files[] = $record;

            if ( 'directory' === $record['type'] && ! $this->walk_page( $full_path, $relative_path, array(), $files ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Has the current page reached its entry limit or its time? Never before it
     * holds one entry, so every page moves the cursor on.
     *
     * @param array $files The page so far.
     * @return bool
     */
    private function page_full( $files ) {
        $count = count( $files );
        if ( $count >= $this->page_limit ) {
            return true;
        }
        return $count > 0 && microtime( true ) >= $this->page_deadline;
    }

    /**
     * A folder's entry names in the page order (byte order, which neither the
     * file system's own order nor a locale can change), or false when it cannot
     * be read.
     *
     * @param string $dir Absolute path.
     * @return string[]|false
     */
    private function sorted_entries( $dir ) {
        $names = @scandir( $dir, SCANDIR_SORT_NONE );
        if ( false === $names ) {
            return false;
        }

        $names = array_values(
            array_filter(
                $names,
                function ( $name ) {
                    return '.' !== $name && '..' !== $name;
                }
            )
        );
        usort( $names, 'strcmp' );

        return $names;
    }

    /**
     * Read a scan cursor back as its path's names, and refuse anything that is
     * not a plain relative path. The walk only compares these names with the
     * folders' own entries - it never opens a path built from them - but the
     * cursor still arrives over the network.
     *
     * @param mixed $cursor
     * @return array|WP_Error
     */
    private function parse_scan_cursor( $cursor ) {
        if ( null === $cursor || '' === $cursor ) {
            return array();
        }

        $invalid = new WP_Error(
            'invalid_cursor',
            __( 'Invalid paging cursor.', 'hostney-migration' ),
            array( 'status' => 400 )
        );

        if ( ! is_string( $cursor ) || strlen( $cursor ) > self::MAX_SCAN_CURSOR_LENGTH || false !== strpos( $cursor, "\0" ) ) {
            return $invalid;
        }

        $names = explode( '/', $cursor );
        if ( count( $names ) > 128 ) {
            return $invalid;
        }
        foreach ( $names as $name ) {
            if ( '' === $name || '.' === $name || '..' === $name ) {
                return $invalid;
            }
        }

        return $names;
    }

    /**
     * Where this site keeps its content, plugins, must-use plugins and uploads,
     * relative to the WordPress folder, and the first thing about that a
     * migration cannot copy (1.0.7).
     *
     * The scan and the file reader see the WordPress folder (ABSPATH) only, and
     * the new site gets the standard folders. A content folder outside the
     * WordPress folder (Bedrock-style projects, some managed hosts), one reached
     * through a link, or one renamed or moved by a setting in wp-config.php would
     * arrive missing, or where WordPress no longer looks for it. Hostney refuses
     * such a site when it connects, rather than completing a migration into a
     * site without its theme, plugins or media.
     *
     * @return array { content, plugins, mu_plugins, uploads: string|null (relative
     *               to the WordPress folder, null when outside it), problem: string|null
     *               (one of LAYOUT_PROBLEMS) }
     */
    public static function layout() {
        $content    = self::relative_to_root( WP_CONTENT_DIR );
        $plugins    = self::relative_to_root( defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/plugins' );
        $mu_plugins = self::relative_to_root( defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins' );
        $uploads    = self::relative_to_root( self::uploads_base_dir() );

        $problem = null;
        if ( null === $content ) {
            $problem = 'content_outside';
        } elseif ( 'wp-content' !== $content ) {
            $problem = 'content_renamed';
        } elseif ( 'wp-content/plugins' !== $plugins ) {
            $problem = 'plugins_elsewhere';
        } elseif ( 'wp-content/mu-plugins' !== $mu_plugins ) {
            $problem = 'mu_plugins_elsewhere';
        } elseif ( 'wp-content/uploads' !== $uploads ) {
            $problem = 'uploads_elsewhere';
        }

        return array(
            'content'    => $content,
            'plugins'    => $plugins,
            'mu_plugins' => $mu_plugins,
            'uploads'    => $uploads,
            'problem'    => $problem,
        );
    }

    /**
     * The uploads folder as WordPress works it out, WITHOUT the upload_dir
     * filter: the UPLOADS constant, else the upload_path setting, else
     * wp-content/uploads (the same order as _wp_upload_dir()). A plugin filtering
     * the uploads folder for one request must not decide what a migration copies.
     *
     * @return string Absolute path.
     */
    private static function uploads_base_dir() {
        if ( defined( 'UPLOADS' ) ) {
            return trailingslashit( ABSPATH ) . UPLOADS;
        }

        $upload_path = trim( (string) get_option( 'upload_path' ) );
        if ( '' === $upload_path || 'wp-content/uploads' === $upload_path ) {
            return WP_CONTENT_DIR . '/uploads';
        }
        if ( 0 !== strpos( $upload_path, ABSPATH ) ) {
            return path_join( ABSPATH, $upload_path );
        }
        return $upload_path;
    }

    /**
     * A folder's path relative to the WordPress folder, both with links resolved,
     * or null when it lies outside it. A folder that does not exist yet (often
     * mu-plugins) is placed by its parent.
     *
     * @param string $path Absolute path.
     * @return string|null '' for the WordPress folder itself.
     */
    private static function relative_to_root( $path ) {
        $root = realpath( ABSPATH );
        if ( false === $root ) {
            return null;
        }
        $root = rtrim( wp_normalize_path( $root ), '/' );

        $resolved = realpath( $path );
        if ( false === $resolved ) {
            $parent   = realpath( dirname( $path ) );
            $resolved = false === $parent ? $path : $parent . '/' . basename( $path );
        }
        $resolved = rtrim( wp_normalize_path( $resolved ), '/' );

        if ( $resolved === $root ) {
            return '';
        }
        if ( 0 !== strpos( $resolved, $root . '/' ) ) {
            return null;
        }
        return substr( $resolved, strlen( $root ) + 1 );
    }

    /**
     * Read a chunk of a file
     *
     * @param string $relative_path File path relative to ABSPATH
     * @param int    $offset Byte offset
     * @param int    $length Number of bytes to read
     * @return array|WP_Error
     */
    public function read_chunk( $relative_path, $offset = 0, $length = 2097152 ) {
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- required for large file reads on shared hosting with short max_execution_time
        @set_time_limit( 120 );

        // Security: validate path
        $validated = $this->validate_path( $relative_path );
        if ( is_wp_error( $validated ) ) {
            return $validated;
        }

        $full_path = $validated;

        if ( ! is_file( $full_path ) ) {
            return new WP_Error( 'not_file', __( 'Path is not a file.', 'hostney-migration' ), array( 'status' => 400 ) );
        }

        if ( ! is_readable( $full_path ) ) {
            return new WP_Error( 'not_readable', __( 'File is not readable.', 'hostney-migration' ), array( 'status' => 403 ) );
        }

        $file_size = filesize( $full_path );

        if ( $offset >= $file_size ) {
            return array(
                'success'   => true,
                'path'      => $relative_path,
                'data'      => '',
                'offset'    => $offset,
                'length'    => 0,
                'file_size' => $file_size,
                'checksum'  => '',
            );
        }

        // Read chunk using direct file operations.
        // WP_Filesystem does not support byte-offset reads (fseek + partial fread),
        // which are required for chunked file transfer of large files.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $handle = fopen( $full_path, 'rb' );
        if ( ! $handle ) {
            return new WP_Error( 'open_failed', __( 'Failed to open file.', 'hostney-migration' ), array( 'status' => 500 ) );
        }

        fseek( $handle, $offset );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
        $data = fread( $handle, $length );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose( $handle );

        if ( $data === false ) {
            return new WP_Error( 'read_failed', __( 'Failed to read file.', 'hostney-migration' ), array( 'status' => 500 ) );
        }

        $checksum = md5( $data );

        return array(
            'success'   => true,
            'path'      => $relative_path,
            'data'      => base64_encode( $data ),
            'offset'    => $offset,
            'length'    => strlen( $data ),
            'file_size' => $file_size,
            'checksum'  => $checksum,
        );
    }

    /**
     * Validate that a path is safe and within ABSPATH
     *
     * @param string $relative_path
     * @return string|WP_Error Full validated path or error
     */
    private function validate_path( $relative_path ) {
        // Reject empty paths
        if ( empty( $relative_path ) ) {
            return new WP_Error( 'empty_path', __( 'Path cannot be empty.', 'hostney-migration' ), array( 'status' => 400 ) );
        }

        // Reject path traversal attempts: a ".." path SEGMENT. A name that merely
        // contains two dots ("Wait...what.jpg", "report..old.pdf") is an ordinary
        // file - until 1.0.7 those were refused and never reached Hostney. The
        // realpath() check below is what keeps every read inside WordPress.
        if ( preg_match( '#(^|[/\\\\])\.\.([/\\\\]|$)#', $relative_path ) ) {
            return new WP_Error( 'path_traversal', __( 'Path traversal not allowed.', 'hostney-migration' ), array( 'status' => 403 ) );
        }

        // Reject null bytes
        if ( strpos( $relative_path, "\0" ) !== false ) {
            return new WP_Error( 'null_byte', __( 'Invalid path.', 'hostney-migration' ), array( 'status' => 400 ) );
        }

        $base_path = rtrim( ABSPATH, '/' );
        $full_path = $base_path . '/' . ltrim( $relative_path, '/' );

        // Resolve the real path
        $real_path = realpath( $full_path );

        if ( $real_path === false ) {
            return new WP_Error( 'not_found', __( 'File not found.', 'hostney-migration' ), array( 'status' => 404 ) );
        }

        // Ensure resolved path is within ABSPATH. Compared up to a directory
        // separator: a bare prefix test let a neighbouring folder whose name
        // merely starts the same way through (/var/www/html-old for a root of
        // /var/www/html), reachable from a symlink inside the root.
        $real_base = realpath( ABSPATH );
        if ( $real_base === false ) {
            return new WP_Error( 'outside_root', __( 'File is outside WordPress root.', 'hostney-migration' ), array( 'status' => 403 ) );
        }
        $real_base = rtrim( $real_base, '/\\' );
        if ( $real_path !== $real_base && strpos( $real_path, $real_base . DIRECTORY_SEPARATOR ) !== 0 ) {
            return new WP_Error( 'outside_root', __( 'File is outside WordPress root.', 'hostney-migration' ), array( 'status' => 403 ) );
        }

        return $real_path;
    }
}
