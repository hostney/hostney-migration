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
        // This site's tables and their size - another site sharing the database
        // is not part of the migration (see get_database_breakdown()).
        $database = $this->get_database_breakdown();

        // Estimate file count and size
        $file_stats = $this->estimate_files();

        $multisite = self::multisite_info();
        $layout    = Hostney_FS_Export::layout();

        return array(
            'wordpress_version' => get_bloginfo( 'version' ),
            'php_version'       => PHP_VERSION,
            'site_url'          => get_option( 'siteurl' ),
            'home_url'          => get_option( 'home' ),
            'is_multisite'      => $multisite['enabled'],
            'site_count'        => $multisite['sites'],
            'layout_problem'    => $layout['problem'],
            'total_tables'      => $database['tables'],
            'total_db_size'     => $database['size'],
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
        $database = $this->get_database_breakdown();
        return $database['size'];
    }

    /**
     * This site's share of the database (1.0.7): its tables and their size, and
     * the tables of other WordPress sites that share the database, which the
     * migration leaves out (Hostney_DB_Export::other_install_prefixes()). Until
     * 1.0.7 those were counted, so a site sharing its database with a bigger one
     * could be refused as too large for a plan it fits.
     *
     * @return array { size: int, tables: int, other_tables: int, other_prefixes: string[] }
     */
    public function get_database_breakdown() {
        global $wpdb;

        $breakdown = array(
            'size'           => 0,
            'tables'         => 0,
            'other_tables'   => 0,
            'other_prefixes' => array(),
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time metadata read, caching not applicable
        $table_status = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );

        if ( empty( $table_status ) ) {
            return $breakdown;
        }

        $names  = wp_list_pluck( $table_status, 'Name' );
        $others = Hostney_DB_Export::other_install_prefixes( $names, $wpdb->base_prefix );

        $breakdown['other_prefixes'] = $others;

        foreach ( $table_status as $table ) {
            if ( Hostney_DB_Export::belongs_to_other_install( $table['Name'], $wpdb->base_prefix, $others ) ) {
                $breakdown['other_tables']++;
                continue;
            }
            $breakdown['tables']++;
            $breakdown['size'] += intval( $table['Data_length'] ) + intval( $table['Index_length'] );
        }

        return $breakdown;
    }

    /**
     * Is this a multisite network, and how many sites does it have (1.0.7)?
     * Hostney migrates single sites; a network with more than one site is
     * refused when it connects rather than migrated with all but one site
     * missing.
     *
     * @return array { enabled: bool, sites: int }
     */
    public static function multisite_info() {
        if ( ! is_multisite() ) {
            return array(
                'enabled' => false,
                'sites'   => 1,
            );
        }

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the live count; get_blog_count() is a cached figure
        $sites = intval( $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->blogs} WHERE deleted = 0" ) );

        return array(
            'enabled' => true,
            'sites'   => max( 1, $sites ),
        );
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
                // The same rule as the transfer, so the estimate counts what is copied.
                if ( ! Hostney_FS_Export::skip_directory( $dir, $entry ) ) {
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
