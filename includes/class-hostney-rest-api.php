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
            'methods'             => 'GET',
            'callback'            => array( $this, 'scan_filesystem' ),
            'permission_callback' => array( 'Hostney_Auth', 'validate_request' ),
        ) );

        register_rest_route( $namespace, '/fs/read', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'read_file_chunk' ),
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
     * GET /status - Health check
     */
    public function get_status( $request ) {
        $this->set_nocache_headers();
        global $wpdb;
        return rest_ensure_response( array(
            'success'      => true,
            'version'      => HOSTNEY_MIGRATION_VERSION,
            'php'          => PHP_VERSION,
            'wp'           => get_bloginfo( 'version' ),
            'table_prefix' => $wpdb->prefix,
        ) );
    }

    /**
     * GET /db/tables - List database tables with metadata
     */
    public function get_db_tables( $request ) {
        $this->set_nocache_headers();
        $db_export = new Hostney_DB_Export();
        $tables = $db_export->get_tables();

        return rest_ensure_response( array(
            'success' => true,
            'tables'  => $tables,
        ) );
    }

    /**
     * POST /db/rows - Get rows from a table
     * Body: { table, last_id, limit }
     */
    public function get_db_rows( $request ) {
        $this->set_nocache_headers();
        $table   = $request->get_param( 'table' );
        $last_id = intval( $request->get_param( 'last_id' ) );
        $limit   = intval( $request->get_param( 'limit' ) );

        if ( empty( $table ) ) {
            return new WP_Error( 'missing_table', __( 'Table name is required.', 'hostney-migration' ), array( 'status' => 400 ) );
        }

        if ( $limit <= 0 || $limit > 5000 ) {
            $limit = 200;
        }

        $db_export = new Hostney_DB_Export();

        // Try the requested batch size, halve on memory errors
        $attempts = 0;
        while ( $attempts < 3 ) {
            $attempts++;
            try {
                $result = $db_export->get_rows( $table, $last_id, $limit );

                if ( is_wp_error( $result ) ) {
                    return $result;
                }

                return rest_ensure_response( $result );
            } catch ( \Throwable $e ) {
                // On memory or fatal errors, halve the batch size and retry
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
        $fs_export = new Hostney_FS_Export();
        $files = $fs_export->scan();

        return rest_ensure_response( array(
            'success' => true,
            'files'   => $files,
        ) );
    }

    /**
     * POST /fs/read - Read a file chunk
     * Body: { path, offset, length }
     */
    public function read_file_chunk( $request ) {
        $this->set_nocache_headers();
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
}
