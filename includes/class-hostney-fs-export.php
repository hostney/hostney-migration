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
     * What the last scan() left out, so Hostney can say so instead of the
     * site arriving with files silently missing.
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

            // Skip symlinks (counted, so a linked plugin folder is not lost silently)
            if ( is_link( $full_path ) ) {
                $this->skipped_links++;
                continue;
            }

            if ( is_dir( $full_path ) ) {
                if ( self::skip_directory( $dir, $entry ) ) {
                    continue;
                }

                // Add directory entry
                $files[] = array(
                    'path'  => $relative_path,
                    'type'  => 'directory',
                    'size'  => 0,
                    'mtime' => filemtime( $full_path ),
                    'perms' => substr( decoct( fileperms( $full_path ) ), -4 ),
                );

                // Recurse
                $this->scan_directory( $full_path, $base_path, $files, $max_files );
            } else {
                // Check excluded files
                if ( in_array( $entry, $this->excluded_files, true ) ) {
                    continue;
                }

                // Check excluded extensions
                $extension = strtolower( pathinfo( $entry, PATHINFO_EXTENSION ) );
                if ( in_array( $extension, $this->excluded_extensions, true ) ) {
                    continue;
                }

                $stat = @stat( $full_path );
                if ( ! $stat ) {
                    $this->note_unreadable( $relative_path );
                    continue;
                }

                $files[] = array(
                    'path'  => $relative_path,
                    'type'  => 'file',
                    'size'  => $stat['size'],
                    'mtime' => $stat['mtime'],
                    'perms' => substr( decoct( $stat['mode'] ), -4 ),
                );
            }
        }

        closedir( $handle );
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

        // Reject path traversal attempts
        if ( strpos( $relative_path, '..' ) !== false ) {
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
