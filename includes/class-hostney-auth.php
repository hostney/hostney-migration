<?php
/**
 * Hostney Migration - Request Authentication
 *
 * Validates incoming requests from the Hostney worker server
 * using token + HMAC-SHA256 signature verification.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Hostney_Auth {

    /**
     * Validate an incoming REST API request
     *
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public static function validate_request( $request ) {
        $stored_token = get_option( 'hostney_migration_token' );

        if ( empty( $stored_token ) ) {
            return new WP_Error(
                'hostney_no_token',
                'No migration token configured.',
                array( 'status' => 403 )
            );
        }

        // Get auth headers
        $token     = $request->get_header( 'X-Migration-Token' );
        $timestamp = $request->get_header( 'X-Migration-Timestamp' );
        $signature = $request->get_header( 'X-Migration-Signature' );

        if ( empty( $token ) || empty( $timestamp ) || empty( $signature ) ) {
            return new WP_Error(
                'hostney_missing_headers',
                'Missing authentication headers.',
                array( 'status' => 401 )
            );
        }

        // Validate token matches
        if ( ! hash_equals( $stored_token, $token ) ) {
            return new WP_Error(
                'hostney_invalid_token',
                'Invalid migration token.',
                array( 'status' => 401 )
            );
        }

        // Validate timestamp (within 300 seconds)
        $current_time = time();
        $request_time = intval( $timestamp );

        if ( abs( $current_time - $request_time ) > 300 ) {
            return new WP_Error(
                'hostney_expired_timestamp',
                'Request timestamp expired.',
                array( 'status' => 401 )
            );
        }

        // Decode base64-wrapped body if present (WAF bypass — worker wraps POST body)
        // HMAC was computed over the original JSON, so we verify against the decoded body
        $body_string = $request->get_body();
        $decoded_body = json_decode( $body_string, true );

        if ( is_array( $decoded_body ) && isset( $decoded_body['_b64'] ) && count( $decoded_body ) === 1 ) {
            $original_json = base64_decode( $decoded_body['_b64'], true );
            if ( $original_json === false ) {
                return new WP_Error(
                    'hostney_invalid_body',
                    'Invalid base64 body encoding.',
                    array( 'status' => 400 )
                );
            }
            // Use the decoded body for HMAC verification
            $body_string = $original_json;

            // Replace request body and params so callbacks get the original data
            $request->set_body( $original_json );
            $original_params = json_decode( $original_json, true );
            if ( is_array( $original_params ) ) {
                foreach ( $original_params as $key => $value ) {
                    $request->set_param( $key, $value );
                }
            }
        }

        // Validate HMAC signature (key derived from token, not the raw token itself)
        $hmac_key = hash( 'sha256', 'hostney-hmac-signing:' . $token, true );
        $signature_data = $timestamp . $body_string;
        $expected_signature = hash_hmac( 'sha256', $signature_data, $hmac_key );

        if ( ! hash_equals( $expected_signature, $signature ) ) {
            return new WP_Error(
                'hostney_invalid_signature',
                'Invalid request signature.',
                array( 'status' => 401 )
            );
        }

        return true;
    }
}
