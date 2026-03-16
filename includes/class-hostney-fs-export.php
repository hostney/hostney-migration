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
     * Directories to exclude from migration
     */
    private $excluded_dirs = array(
        'cache',
        'upgrade',
        'wflogs',
        'ai1wm-backups',
        'updraft',
        'node_modules',
        '.git',
        'backups',
        'backup',
    );

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
            return;
        }

        $handle = @opendir( $dir );
        if ( ! $handle ) {
            return;
        }

        while ( false !== ( $entry = readdir( $handle ) ) ) {
            if ( $entry === '.' || $entry === '..' ) {
                continue;
            }

            if ( count( $files ) >= $max_files ) {
                break;
            }

            $full_path = $dir . '/' . $entry;
            $relative_path = substr( $full_path, strlen( $base_path ) + 1 );

            // Skip symlinks
            if ( is_link( $full_path ) ) {
                continue;
            }

            if ( is_dir( $full_path ) ) {
                // Check excluded directories
                if ( in_array( $entry, $this->excluded_dirs, true ) ) {
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

        // Ensure resolved path is within ABSPATH
        $real_base = realpath( ABSPATH );
        if ( strpos( $real_path, $real_base ) !== 0 ) {
            return new WP_Error( 'outside_root', __( 'File is outside WordPress root.', 'hostney-migration' ), array( 'status' => 403 ) );
        }

        return $real_path;
    }
}
