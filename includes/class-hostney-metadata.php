<?php
/**
 * Hostney Migration - Site Metadata
 *
 * Collects WordPress site information for migration planning.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Hostney_Metadata {

    /**
     * Get comprehensive site information
     *
     * @return array Site metadata
     */
    public function get_site_info() {
        global $wpdb;

        // Count tables
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time metadata read, caching not applicable
        $tables = $wpdb->get_col( 'SHOW TABLES' );
        $total_tables = count( $tables );

        // Calculate total DB size
        $total_db_size = 0;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time metadata read, caching not applicable
        $table_status = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );
        foreach ( $table_status as $table ) {
            $total_db_size += intval( $table['Data_length'] ) + intval( $table['Index_length'] );
        }

        // Estimate file count and size
        $file_stats = $this->estimate_files();

        return array(
            'wordpress_version' => get_bloginfo( 'version' ),
            'php_version'       => PHP_VERSION,
            'site_url'          => get_option( 'siteurl' ),
            'home_url'          => get_option( 'home' ),
            'is_multisite'      => is_multisite(),
            'total_tables'      => $total_tables,
            'total_db_size'     => $total_db_size,
            'total_files'       => $file_stats['count'],
            'total_files_size'  => $file_stats['size'],
            'active_plugins'    => count( get_option( 'active_plugins', array() ) ),
            'active_theme'      => get_stylesheet(),
        );
    }

    /**
     * Estimate total file count and size
     * Uses a quick scan with limits to avoid timeout
     *
     * @return array With 'count' and 'size' keys
     */
    private function estimate_files() {
        $count = 0;
        $size  = 0;
        $max   = 100000; // Safety limit for estimation

        $this->count_directory( rtrim( ABSPATH, '/' ), $count, $size, $max );

        return array(
            'count' => $count,
            'size'  => $size,
        );
    }

    /**
     * Recursively count files in a directory
     */
    private function count_directory( $dir, &$count, &$size, $max ) {
        if ( $count >= $max ) {
            return;
        }

        $excluded_dirs = array( 'cache', 'upgrade', 'node_modules', '.git', 'wflogs', 'ai1wm-backups', 'updraft' );

        $handle = @opendir( $dir );
        if ( ! $handle ) {
            return;
        }

        while ( false !== ( $entry = readdir( $handle ) ) ) {
            if ( $entry === '.' || $entry === '..' ) {
                continue;
            }

            if ( $count >= $max ) {
                break;
            }

            $full_path = $dir . '/' . $entry;

            if ( is_link( $full_path ) ) {
                continue;
            }

            if ( is_dir( $full_path ) ) {
                if ( ! in_array( $entry, $excluded_dirs, true ) ) {
                    $this->count_directory( $full_path, $count, $size, $max );
                }
            } else {
                $count++;
                $file_size = @filesize( $full_path );
                if ( $file_size !== false ) {
                    $size += $file_size;
                }
            }
        }

        closedir( $handle );
    }
}
