<?php
/**
 * Hostney Migration - REST API Routes
 *
 * Registers WP REST API endpoints that the Hostney worker calls
 * to pull database rows and file contents during migration.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Hostney_REST_API {

    /**
     * Register all REST routes under hostney-migrate/v1
     */
    public function register_routes() {
        $namespace = 'hostney-migrate/v1';

        register_rest_route( $namespace, '/status', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_status' ),
            'permission_callback' => array( 'Hostney_Auth', 'validate_request' ),
        ) );

        register_rest_route( $namespace, '/db/tables', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_db_tables' ),
            'permission_callback' => array( 'Hostney_Auth', 'validate_request' ),
        ) );

        register_rest_route( $namespace, '/db/rows', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'get_db_rows' ),
            'permission_callback' => array( 'Hostney_Auth', 'validate_request' ),
        ) );

        register_rest_route( $namespace, '/fs/scan', array(
            // The whole file list in one answer (every worker before 1.0.7).
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'scan_filesystem' ),
                'permission_callback' => array( 'Hostney_Auth', 'validate_request' ),
            ),
            // 1.0.7: one page at a time (Hostney_FS_Export::scan_page()). The
            // worker asks for it only when /status lists the scan_cursor feature.
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'scan_filesystem_page' ),
                'permission_callback' => array( 'Hostney_Auth', 'validate_request' ),
            ),
        ) );

        register_rest_route( $namespace, '/fs/read', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'read_file_chunk' ),
            'permission_callback' => array( 'Hostney_Auth', 'validate_request' ),
        ) );

        register_rest_route( $namespace, '/disconnect', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'disconnect' ),
            'permission_callback' => array( 'Hostney_Auth', 'validate_request' ),
        ) );
    }

    /**
     * Prevent caching of all migration REST API responses.
     * These responses contain sensitive data (DB rows, file contents).
     */
    private function set_nocache_headers() {
        nocache_headers();
    }

    /**
     * Ask WordPress for the memory limit wp-admin gets (WP_MAX_MEMORY_LIMIT, 256M
     * unless the site sets it). A REST request runs with the front end's limit,
     * and a page of rows, a file list or a file chunk is the heaviest work this
     * plugin does. Where the host does not let PHP change its limit, nothing
     * changes.
     */
    private function raise_memory_limit() {
        if ( function_exists( 'wp_raise_memory_limit' ) ) {
            wp_raise_memory_limit( 'hostney_migration' );
        }
    }

    /**
     * GET /status - Health check
     *
     * 1.0.7 adds what the worker checks before it copies anything: the site's
     * folder layout, whether it is a multisite network, the other WordPress
     * sites that share its database, and the features this version serves.
     */
    public function get_status( $request ) {
        $this->set_nocache_headers();
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the live table list, caching not applicable
        $table_names = $wpdb->get_col( 'SHOW TABLES' );
        return rest_ensure_response( array(
            'success'        => true,
            'version'        => HOSTNEY_MIGRATION_VERSION,
            'php'            => PHP_VERSION,
            'wp'             => get_bloginfo( 'version' ),
            'table_prefix'   => $wpdb->prefix,
            'layout'         => Hostney_FS_Export::layout(),
            'multisite'      => Hostney_Metadata::multisite_info(),
            'other_installs' => Hostney_DB_Export::other_install_prefixes( is_array( $table_names ) ? $table_names : array(), $wpdb->base_prefix ),
            'features'       => array( 'scan_cursor' ),
        ) );
    }

    /**
     * GET /db/tables - List database tables with metadata
     */
    public function get_db_tables( $request ) {
        $this->set_nocache_headers();
        $this->raise_memory_limit();
        $db_export = new Hostney_DB_Export();
        $tables = $db_export->get_tables();

        return rest_ensure_response( array(
            'success' => true,
            'tables'  => $tables,
        ) );
    }

    /**
     * POST /db/rows - Get rows from a table
     * Body: { table, last_id, limit, cursor }
     *
     * `cursor` is optional: present, it asks for key-order paging (see
     * Hostney_DB_Export::get_rows()); absent, the table is paged on last_id as
     * it always was.
     */
    public function get_db_rows( $request ) {
        $this->set_nocache_headers();
        $this->raise_memory_limit();
        $table   = $request->get_param( 'table' );
        $last_id = intval( $request->get_param( 'last_id' ) );
        $limit   = intval( $request->get_param( 'limit' ) );
        $cursor  = $request->get_param( 'cursor' );

        if ( empty( $table ) ) {
            return new WP_Error( 'missing_table', __( 'Table name is required.', 'hostney-migration' ), array( 'status' => 400 ) );
        }

        if ( null !== $cursor && ! is_string( $cursor ) ) {
            return new WP_Error( 'invalid_cursor', __( 'Invalid paging cursor.', 'hostney-migration' ), array( 'status' => 400 ) );
        }

        if ( $limit <= 0 || $limit > 5000 ) {
            $limit = 200;
        }

        $db_export = new Hostney_DB_Export();

        // Try the requested batch size, halving it when the export throws. PHP
        // running out of memory is NOT one of those: it is a fatal error that
        // ends the request on the spot and no catch ever sees it. The Hostney
        // worker answers that one itself, by asking again for fewer rows.
        $attempts = 0;
        while ( $attempts < 3 ) {
            $attempts++;
            try {
                $result = $db_export->get_rows( $table, $last_id, $limit, $cursor );

                if ( is_wp_error( $result ) ) {
                    return $result;
                }

                return rest_ensure_response( $result );
            } catch ( \Throwable $e ) {
                // An exception thrown by the export: halve the batch size and retry
                if ( $limit <= 50 ) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional server-side logging for migration export failures
                    error_log( '[Hostney Migration] Row export error: ' . $e->getMessage() );
                    return new WP_Error( 'export_error', __( 'Failed to export rows.', 'hostney-migration' ), array( 'status' => 500 ) );
                }
                $limit = intval( $limit / 2 );
            }
        }

        return new WP_Error( 'export_error', __( 'Failed to export rows after reducing batch size.', 'hostney-migration' ), array( 'status' => 500 ) );
    }

    /**
     * GET /fs/scan - Scan filesystem and return file list
     */
    public function scan_filesystem( $request ) {
        $this->set_nocache_headers();
        $this->raise_memory_limit();
        $fs_export = new Hostney_FS_Export();
        $files = $fs_export->scan();

        return rest_ensure_response( array(
            'success'   => true,
            'files'     => $files,
            // 1.0.6: what the list does NOT hold - a stop at the file limit,
            // links, unreadable folders and files - so Hostney can say so.
            'truncated' => $fs_export->was_truncated(),
            'skipped'   => $fs_export->skipped(),
        ) );
    }

    /**
     * POST /fs/scan - One page of the file list (1.0.7)
     * Body: { cursor, limit }
     *
     * `cursor` is '' for the first page, then the previous answer's
     * next_cursor; the answer says `done` once the walk is over. `skipped`
     * describes this page only.
     */
    public function scan_filesystem_page( $request ) {
        $this->set_nocache_headers();
        $this->raise_memory_limit();
        $cursor = $request->get_param( 'cursor' );
        $limit  = intval( $request->get_param( 'limit' ) );

        if ( null !== $cursor && ! is_string( $cursor ) ) {
            return new WP_Error( 'invalid_cursor', __( 'Invalid paging cursor.', 'hostney-migration' ), array( 'status' => 400 ) );
        }

        if ( $limit <= 0 ) {
            $limit = Hostney_FS_Export::SCAN_PAGE_DEFAULT;
        }

        $fs_export = new Hostney_FS_Export();
        $page = $fs_export->scan_page( null === $cursor ? '' : $cursor, $limit );

        if ( is_wp_error( $page ) ) {
            return $page;
        }

        return rest_ensure_response( array(
            'success'     => true,
            'files'       => $page['files'],
            'done'        => $page['done'],
            'next_cursor' => $page['next_cursor'],
            'truncated'   => false,
            'skipped'     => $fs_export->skipped(),
        ) );
    }

    /**
     * POST /fs/read - Read a file chunk
     * Body: { path, offset, length }
     */
    public function read_file_chunk( $request ) {
        $this->set_nocache_headers();
        $this->raise_memory_limit();
        $file_path = $request->get_param( 'path' );
        $offset    = intval( $request->get_param( 'offset' ) );
        $length    = intval( $request->get_param( 'length' ) );

        if ( empty( $file_path ) ) {
            return new WP_Error( 'missing_path', __( 'File path is required.', 'hostney-migration' ), array( 'status' => 400 ) );
        }

        if ( $length <= 0 || $length > 5242880 ) { // Max 5MB
            $length = 2097152; // Default 2MB
        }

        $fs_export = new Hostney_FS_Export();
        $result = $fs_export->read_chunk( $file_path, $offset, $length );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( $result );
    }

    /**
     * POST /disconnect - Hostney is done with this connection
     * Body: { reason }
     *
     * Hostney sends this when the migration completes, fails for good, is
     * cancelled, or when its token is revoked or expires in the control panel.
     * The request passed Hostney_Auth::validate_request() like every other, so
     * only the holder of this site's current token can end its connection; a
     * request signed with an older token is refused and changes nothing.
     */
    public function disconnect( $request ) {
        $this->set_nocache_headers();
        $reason = $request->get_param( 'reason' );

        if ( ! is_string( $reason ) || ! in_array( $reason, Hostney_Auth::END_REASONS, true ) ) {
            $reason = 'ended';
        }

        Hostney_Auth::end_connection( $reason );

        return rest_ensure_response( array(
            'success' => true,
            'message' => __( 'This site has been disconnected from Hostney.', 'hostney-migration' ),
        ) );
    }
}
