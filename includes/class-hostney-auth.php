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
     * Length of the rate-limit window, in seconds.
     */
    const RATE_WINDOW = 60;

    /**
     * Requests allowed per window from a single IP, once authenticated.
     * The Hostney worker runs sequentially at roughly 7-10 req/s, so this is
     * headroom rather than a pace the worker is ever expected to reach.
     */
    const RATE_MAX = 900;

    /**
     * Failed authentication attempts allowed per window from a single IP.
     */
    const AUTH_FAIL_MAX = 30;

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
                __( 'No migration token configured.', 'hostney-migration' ),
                array( 'status' => 403 )
            );
        }

        // Get auth headers
        $token     = $request->get_header( 'X-Migration-Token' );
        $timestamp = $request->get_header( 'X-Migration-Timestamp' );
        $signature = $request->get_header( 'X-Migration-Signature' );

        // Cheap brute-force guard, checked before any comparison work is done.
        $lockout = self::check_auth_failures();
        if ( is_wp_error( $lockout ) ) {
            return $lockout;
        }

        if ( empty( $token ) || empty( $timestamp ) || empty( $signature ) ) {
            self::record_auth_failure();
            return new WP_Error(
                'hostney_missing_headers',
                __( 'Missing authentication headers.', 'hostney-migration' ),
                array( 'status' => 401 )
            );
        }

        // Validate token matches
        if ( ! hash_equals( $stored_token, $token ) ) {
            self::record_auth_failure();
            return new WP_Error(
                'hostney_invalid_token',
                __( 'Invalid migration token.', 'hostney-migration' ),
                array( 'status' => 401 )
            );
        }

        // Validate timestamp (within 300 seconds)
        $current_time = time();
        $request_time = intval( $timestamp );

        if ( abs( $current_time - $request_time ) > 300 ) {
            self::record_auth_failure();
            return new WP_Error(
                'hostney_expired_timestamp',
                __( 'Request timestamp expired.', 'hostney-migration' ),
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
                    __( 'Invalid base64 body encoding.', 'hostney-migration' ),
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
            self::record_auth_failure();
            return new WP_Error(
                'hostney_invalid_signature',
                __( 'Invalid request signature.', 'hostney-migration' ),
                array( 'status' => 401 )
            );
        }

        // The request is authentic. Only now does it count against the request-rate
        // budget: an unsigned flood is handled by the auth-failure lockout above and
        // must not be able to consume a real migration's quota.
        $rate_check = self::check_rate_limit();
        if ( is_wp_error( $rate_check ) ) {
            return $rate_check;
        }

        return true;
    }

    /**
     * Client IP for rate-limiting purposes.
     *
     * @return string
     */
    private static function client_ip() {
        return isset( $_SERVER['REMOTE_ADDR'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
            : 'unknown';
    }

    /**
     * Fixed-window counter backed by a transient.
     *
     * The window start is stored ALONGSIDE the count and the transient TTL is set to
     * the time remaining in the window, never to the full window length. Writing the
     * full length on every hit would push the expiry forward on each request, so a
     * steady stream (exactly what a migration is) would keep the window alive forever
     * and turn a per-minute limit into a permanent lifetime cap.
     *
     * @param string $key    Transient key.
     * @param int    $max    Maximum hits allowed per window.
     * @return array{allowed:bool,retry_after:int}
     */
    private static function hit_window( $key, $max ) {
        $now  = time();
        $data = get_transient( $key );

        // Missing, malformed, or from an elapsed window: start a fresh one.
        if ( ! is_array( $data ) || ! isset( $data['start'], $data['count'] )
            || ( $now - intval( $data['start'] ) ) >= self::RATE_WINDOW ) {
            set_transient( $key, array( 'start' => $now, 'count' => 1 ), self::RATE_WINDOW );
            return array( 'allowed' => true, 'retry_after' => 0 );
        }

        $elapsed   = $now - intval( $data['start'] );
        $remaining = max( 1, self::RATE_WINDOW - $elapsed );

        if ( intval( $data['count'] ) >= $max ) {
            // Deliberately does NOT rewrite the transient: a blocked request must not
            // extend the window, or a client that keeps retrying could never get back in.
            return array( 'allowed' => false, 'retry_after' => $remaining );
        }

        $data['count'] = intval( $data['count'] ) + 1;
        set_transient( $key, $data, $remaining );

        return array( 'allowed' => true, 'retry_after' => 0 );
    }

    /**
     * Per-IP request rate limiter, applied to authenticated requests only.
     *
     * @return true|WP_Error
     */
    private static function check_rate_limit() {
        $result = self::hit_window( 'hostney_mig_rl_' . md5( self::client_ip() ), self::RATE_MAX );

        if ( $result['allowed'] ) {
            return true;
        }

        return self::rate_limited_error(
            'hostney_rate_limited',
            __( 'Too many requests. Please try again later.', 'hostney-migration' ),
            $result['retry_after']
        );
    }

    /**
     * Reject the request if this IP has failed authentication too often.
     *
     * @return true|WP_Error
     */
    private static function check_auth_failures() {
        $key  = 'hostney_mig_af_' . md5( self::client_ip() );
        $data = get_transient( $key );

        if ( ! is_array( $data ) || ! isset( $data['start'], $data['count'] ) ) {
            return true;
        }

        $elapsed = time() - intval( $data['start'] );
        if ( $elapsed >= self::RATE_WINDOW || intval( $data['count'] ) < self::AUTH_FAIL_MAX ) {
            return true;
        }

        return self::rate_limited_error(
            'hostney_auth_throttled',
            __( 'Too many failed authentication attempts. Please try again later.', 'hostney-migration' ),
            max( 1, self::RATE_WINDOW - $elapsed )
        );
    }

    /**
     * Count one failed authentication attempt against this IP.
     *
     * @return void
     */
    private static function record_auth_failure() {
        self::hit_window( 'hostney_mig_af_' . md5( self::client_ip() ), PHP_INT_MAX );
    }

    /**
     * Build a 429 and make sure a Retry-After header rides along with it.
     *
     * WP_Error data cannot carry headers, so the header is attached to the response
     * on rest_post_dispatch — which runs for error responses too, and before
     * WP_REST_Server::send_headers(). Without it the caller has to guess how long to
     * wait, and a guess that is too short can never clear the window.
     *
     * @param string $code        Error code.
     * @param string $message     User-facing message.
     * @param int    $retry_after Seconds until the window resets.
     * @return WP_Error
     */
    private static function rate_limited_error( $code, $message, $retry_after ) {
        $retry_after = max( 1, intval( $retry_after ) );

        add_filter(
            'rest_post_dispatch',
            function ( $response ) use ( $retry_after ) {
                if ( $response instanceof WP_REST_Response ) {
                    $response->header( 'Retry-After', (string) $retry_after );
                }
                return $response;
            }
        );

        return new WP_Error(
            $code,
            $message,
            array(
                'status'      => 429,
                'retry_after' => $retry_after,
            )
        );
    }
}
