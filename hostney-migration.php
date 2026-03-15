<?php
/**
 * Plugin Name: Hostney Migration
 * Plugin URI: https://www.hostney.com
 * Description: Migrate your WordPress site to Hostney hosting. Paste your migration token and the Hostney worker will pull your data automatically.
 * Version: 1.0.0
 * Author: Hostney
 * Author URI: https://www.hostney.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: hostney-migration
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'HOSTNEY_MIGRATION_VERSION', '1.0.0' );
define( 'HOSTNEY_MIGRATION_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'HOSTNEY_MIGRATION_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
// Override in wp-config.php: define( 'HOSTNEY_MIGRATION_API_BASE', 'https://dev.example.com/api/v2/public/plugin-migration' );
if ( ! defined( 'HOSTNEY_MIGRATION_API_BASE' ) ) {
    define( 'HOSTNEY_MIGRATION_API_BASE', 'https://api.hostney.com/api/v2/public/plugin-migration' );
}

// Load includes
require_once HOSTNEY_MIGRATION_PLUGIN_DIR . 'includes/class-hostney-auth.php';
require_once HOSTNEY_MIGRATION_PLUGIN_DIR . 'includes/class-hostney-rest-api.php';
require_once HOSTNEY_MIGRATION_PLUGIN_DIR . 'includes/class-hostney-db-export.php';
require_once HOSTNEY_MIGRATION_PLUGIN_DIR . 'includes/class-hostney-fs-export.php';
require_once HOSTNEY_MIGRATION_PLUGIN_DIR . 'includes/class-hostney-metadata.php';

/**
 * Main plugin class
 */
class Hostney_Migration {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
        add_action( 'wp_ajax_hostney_connect', array( $this, 'ajax_connect' ) );
        add_action( 'wp_ajax_hostney_disconnect', array( $this, 'ajax_disconnect' ) );

        // Base64-wrap migration REST API responses to bypass WAF/ModSec inspection
        add_filter( 'rest_pre_serve_request', array( $this, 'maybe_base64_wrap_response' ), 10, 4 );

        // Activation hook
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Check PHP version
        if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
            deactivate_plugins( plugin_basename( __FILE__ ) );
            wp_die( 'Hostney Migration requires PHP 7.4 or later.' );
        }

        // Check required extensions
        if ( ! function_exists( 'hash_hmac' ) ) {
            deactivate_plugins( plugin_basename( __FILE__ ) );
            wp_die( 'Hostney Migration requires the hash extension.' );
        }
    }

    /**
     * Plugin deactivation - clean up stored token
     */
    public function deactivate() {
        delete_option( 'hostney_migration_token' );
        delete_option( 'hostney_migration_status' );
    }

    /**
     * Base64-wrap REST API responses for hostney-migrate routes.
     *
     * WAFs (ModSecurity, Wordfence, Imunify, Cloudflare) inspect response bodies
     * and may block responses containing SQL, PHP code, or other patterns.
     * When the worker sends X-Migration-Encoding: base64, we wrap the entire
     * JSON response in base64 so WAFs see harmless text instead.
     */
    public function maybe_base64_wrap_response( $served, $result, $request, $server ) {
        // Only apply to our migration endpoints
        $route = $request->get_route();
        if ( strpos( $route, '/hostney-migrate/v1/' ) === false ) {
            return $served;
        }

        // Only wrap if the worker requests it
        $encoding = $request->get_header( 'X-Migration-Encoding' );
        if ( $encoding !== 'base64' ) {
            return $served;
        }

        // Get the response data as JSON
        $data = $server->response_to_data( $result, false );
        $json = wp_json_encode( $data );

        // Send as base64-wrapped plain text — WAFs won't inspect this
        header( 'Content-Type: application/octet-stream' );
        header( 'X-Migration-Encoding: base64' );
        nocache_headers();
        echo base64_encode( $json ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        return true; // Tell WordPress we handled the response
    }

    /**
     * Add admin menu under Tools
     */
    public function add_admin_menu() {
        add_management_page(
            'Hostney Migration',
            'Hostney Migration',
            'manage_options',
            'hostney-migration',
            array( $this, 'render_admin_page' )
        );
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts( $hook ) {
        if ( 'tools_page_hostney-migration' !== $hook ) {
            return;
        }

        wp_enqueue_style(
            'hostney-migration-css',
            HOSTNEY_MIGRATION_PLUGIN_URL . 'admin/css/migration.css',
            array(),
            HOSTNEY_MIGRATION_VERSION
        );

        wp_enqueue_script(
            'hostney-migration-js',
            HOSTNEY_MIGRATION_PLUGIN_URL . 'admin/js/migration.js',
            array( 'jquery' ),
            HOSTNEY_MIGRATION_VERSION,
            true
        );

        wp_localize_script( 'hostney-migration-js', 'hostneyMigration', array(
            'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'hostney_migration_nonce' ),
            'restUrl'  => rest_url( 'hostney-migrate/v1/' ),
            'apiBase'  => HOSTNEY_MIGRATION_API_BASE,
            'siteUrl'  => get_option( 'siteurl' ),
        ) );
    }

    /**
     * Register REST API routes for the Hostney worker to call
     */
    public function register_rest_routes() {
        $rest_api = new Hostney_REST_API();
        $rest_api->register_routes();
    }

    /**
     * AJAX handler: connect with token
     */
    public function ajax_connect() {
        check_ajax_referer( 'hostney_migration_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
        }

        $token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';

        if ( empty( $token ) || strlen( $token ) !== 96 || ! preg_match( '/^[a-f0-9]+$/i', $token ) ) {
            wp_send_json_error( array( 'message' => 'Invalid token format. Token must be 96 characters.' ) );
        }

        // Store the token (autoload disabled - only loaded when REST API validates requests)
        update_option( 'hostney_migration_token', $token, false );

        // Collect site metadata
        $metadata = new Hostney_Metadata();
        $site_info = $metadata->get_site_info();

        // Register with Hostney backend
        $response = wp_remote_post( HOSTNEY_MIGRATION_API_BASE . '/validate', array(
            'timeout' => 30,
            'body'    => wp_json_encode( array(
                'token'            => $token,
                'site_url'         => get_option( 'siteurl' ),
                'rest_url'         => rest_url( 'hostney-migrate/v1/' ),
                'wordpress_version' => $site_info['wordpress_version'],
                'php_version'      => $site_info['php_version'],
                'total_tables'     => $site_info['total_tables'],
                'total_files'      => $site_info['total_files'],
                'total_db_size'    => $site_info['total_db_size'],
                'total_files_size' => $site_info['total_files_size'],
            ) ),
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            delete_option( 'hostney_migration_token' );
            // Log the actual error for debugging, but don't expose it to the user
            error_log( '[Hostney Migration] Connection failed: ' . $response->get_error_message() );
            wp_send_json_error( array( 'message' => 'Could not connect to Hostney. Please check your internet connection and try again.' ) );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $status_code !== 200 || empty( $body['success'] ) ) {
            delete_option( 'hostney_migration_token' );
            $error_msg = ! empty( $body['message'] ) ? $body['message'] : 'Registration failed.';
            wp_send_json_error( array( 'message' => $error_msg ) );
        }

        update_option( 'hostney_migration_status', 'connected' );

        wp_send_json_success( array(
            'message'     => $body['message'] ?? 'Connected successfully.',
            'destination' => $body['data']['destination'] ?? '',
        ) );
    }

    /**
     * AJAX handler: disconnect (clear token and status)
     */
    public function ajax_disconnect() {
        check_ajax_referer( 'hostney_migration_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
        }

        delete_option( 'hostney_migration_token' );
        delete_option( 'hostney_migration_status' );

        wp_send_json_success( array( 'message' => 'Disconnected successfully.' ) );
    }

    /**
     * Render admin page
     */
    public function render_admin_page() {
        include HOSTNEY_MIGRATION_PLUGIN_DIR . 'admin/views/migration-page.php';
    }
}

// Initialize
Hostney_Migration::get_instance();
