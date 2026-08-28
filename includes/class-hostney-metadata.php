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
        $total_db_size = $this->get_database_size();

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
     * Total size of this site's database, in bytes.
     *
     * Split out of get_site_info() because the admin screen wants this number
     * on its own: get_site_info() also walks the whole filesystem, which is far
     * too expensive to run on every page render.
     *
     * Data_length + Index_length is the same basis Hostney measures a database
     * on at the other end - the per-table `size` this plugin reports during
     * export, and the figure the platform uses to decide whether a database has
     * outgrown its plan. Reporting anything else here (a dump size, a gzip
     * size) would compare two different numbers.
     *
     * @return int Size in bytes, 0 if it could not be read
     */
    public function get_database_size() {
        global $wpdb;

        $total = 0;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time metadata read, caching not applicable
        $table_status = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );

        if ( empty( $table_status ) ) {
            return 0;
        }

        foreach ( $table_status as $table ) {
            $total += intval( $table['Data_length'] ) + intval( $table['Index_length'] );
        }

        return $total;
    }

    /**
     * Format a byte count for display.
     *
     * @param int $bytes
     * @return string
     */
    public static function format_bytes( $bytes ) {
        $n = intval( $bytes );
        if ( $n <= 0 ) {
            return '0 B';
        }

        $units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
        $i     = min( count( $units ) - 1, (int) floor( log( $n, 1024 ) ) );

        return sprintf( '%s %s', number_format_i18n( $n / pow( 1024, $i ), $i > 0 ? 1 : 0 ), $units[ $i ] );
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
