<?php
/**
 * tests/unit/bootstrap.php
 * PHPUnit bootstrap for LGW unit tests.
 *
 * Stubs all WordPress functions manually — no WP_Mock required.
 * Only dependency: phpunit/phpunit
 *
 *   composer require --dev phpunit/phpunit:^10
 *   ./vendor/bin/phpunit tests/phpunit.xml --testdox
 */

require_once __DIR__ . '/../../vendor/autoload.php';

// ── WP options store (in-memory for tests) ────────────────────────────────────
// Tests call WpStubs::resetOptions() in setUp() to get a clean slate.

class WpStubs {
    public static array $options  = [];
    public static array $postMeta = [];

    public static function resetOptions(): void {
        self::$options  = [];
        self::$postMeta = [];
    }
}

// ── WordPress function stubs ──────────────────────────────────────────────────

if ( ! function_exists( 'get_option' ) ) {
    function get_option( $key, $default = false ) {
        return WpStubs::$options[$key] ?? $default;
    }
}
if ( ! function_exists( 'update_option' ) ) {
    function update_option( $key, $value, $autoload = null ) {
        WpStubs::$options[$key] = $value;
        return true;
    }
}
if ( ! function_exists( 'delete_option' ) ) {
    function delete_option( $key ) {
        unset( WpStubs::$options[$key] );
        return true;
    }
}
if ( ! function_exists( 'get_post_meta' ) ) {
    function get_post_meta( $post_id, $key, $single = false ) {
        $val = WpStubs::$postMeta[$post_id][$key] ?? null;
        return $single ? ( $val ?? '' ) : ( $val !== null ? [ $val ] : [] );
    }
}
if ( ! function_exists( 'update_post_meta' ) ) {
    function update_post_meta( $post_id, $key, $value ) {
        WpStubs::$postMeta[$post_id][$key] = $value;
        return true;
    }
}
if ( ! function_exists( 'add_action' ) ) {
    function add_action( $hook, $callback, $priority = 10, $args = 1 ) { /* no-op */ }
}
if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( $str ) {
        return strtolower( preg_replace( '/[^a-z0-9_\-]/', '-', strtolower( $str ) ) );
    }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data, $flags = 0 ) { return json_encode( $data, $flags ); }
}
if ( ! function_exists( 'human_time_diff' ) ) {
    function human_time_diff( $from, $to = 0 ) {
        $diff = abs( ( $to ?: time() ) - $from );
        if ( $diff < 60 )   return $diff . ' seconds';
        if ( $diff < 3600 ) return round( $diff / 60 ) . ' minutes';
        return round( $diff / 3600 ) . ' hours';
    }
}
if ( ! function_exists( 'current_time' ) ) {
    function current_time( $type ) { return time(); }
}
if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'wp_unslash' ) ) {
    function wp_unslash( $s ) { return stripslashes( $s ); }
}
if ( ! function_exists( 'wp_schedule_event' ) ) {
    function wp_schedule_event( $timestamp, $recurrence, $hook, $args = [] ) { /* no-op */ }
}
if ( ! function_exists( 'wp_next_scheduled' ) ) {
    function wp_next_scheduled( $hook, $args = [] ) { return false; }
}
if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
    function wp_clear_scheduled_hook( $hook ) { /* no-op */ }
}
if ( ! function_exists( 'wp_remote_get' ) ) {
    function wp_remote_get( $url, $args = [] ) { return new WP_Error( 'stub', 'Not implemented in unit tests' ); }
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
    function wp_remote_retrieve_response_code( $response ) { return 0; }
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
    function wp_remote_retrieve_body( $response ) { return ''; }
}
if ( ! function_exists( 'set_transient' ) ) {
    function set_transient( $key, $value, $expiration = 0 ) { return true; }
}
if ( ! function_exists( 'get_transient' ) ) {
    function get_transient( $key ) { return false; }
}
if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
}
if ( ! function_exists( 'check_ajax_referer' ) ) {
    function check_ajax_referer( $action, $query_arg = false, $die = true ) { return 1; }
}
if ( ! function_exists( 'current_user_can' ) ) {
    function current_user_can( $cap ) { return true; }
}
if ( ! function_exists( 'wp_send_json_success' ) ) {
    function wp_send_json_success( $data = null ) { /* no-op in tests */ }
}
if ( ! function_exists( 'wp_send_json_error' ) ) {
    function wp_send_json_error( $data = null ) { /* no-op in tests */ }
}
if ( ! function_exists( 'wp_die' ) ) {
    function wp_die( $message = '' ) { /* no-op in tests */ }
}

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private string $code;
        private string $message;
        public function __construct( string $code = '', string $message = '' ) {
            $this->code    = $code;
            $this->message = $message;
        }
        public function get_error_message(): string { return $this->message; }
        public function get_error_code(): string    { return $this->code; }
    }
}

// ── Constants ─────────────────────────────────────────────────────────────────
if ( ! defined( 'ABSPATH' ) )           define( 'ABSPATH',           '/tmp/wp/' );
if ( ! defined( 'DAY_IN_SECONDS' ) )    define( 'DAY_IN_SECONDS',    86400 );
if ( ! defined( 'HOUR_IN_SECONDS' ) )   define( 'HOUR_IN_SECONDS',   3600 );
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) define( 'MINUTE_IN_SECONDS', 60 );
if ( ! defined( 'LGW_CACHE_HARD_TTL' ) ) define( 'LGW_CACHE_HARD_TTL', DAY_IN_SECONDS );
if ( ! defined( 'WP_DEBUG' ) )          define( 'WP_DEBUG',          false );

// ── LGW stubs (functions defined in other plugin files) ───────────────────────
if ( ! function_exists( 'lgw_get_active_season_id' ) ) {
    function lgw_get_active_season_id() { return '2026'; }
}
if ( ! function_exists( 'lgw_get_seasons' ) ) {
    function lgw_get_seasons() { return []; }
}
if ( ! function_exists( 'lgw_sheets_parse_division_csv' ) ) {
    function lgw_sheets_parse_division_csv( $body ) {
        return [ 'teams' => [], 'fixtures' => [] ];
    }
}
if ( ! function_exists( 'lgw_parse_any_date' ) ) {
    function lgw_parse_any_date( $str ) {
        $str = trim( $str );
        if ( ! $str ) return null;
        if ( preg_match( '#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $str, $m ) ) {
            $ts = mktime( 0, 0, 0, (int) $m[2], (int) $m[1], (int) $m[3] );
            return $ts ?: null;
        }
        if ( preg_match( '#(?:^|\s)(\d{1,2})-([A-Za-z]{3})-(\d{4})$#', $str, $m ) ) {
            $ts = strtotime( $m[1] . ' ' . $m[2] . ' ' . $m[3] );
            return ( $ts !== false ) ? $ts : null;
        }
        $ts = strtotime( $str );
        return ( $ts !== false ) ? $ts : null;
    }
}
// ── Load module under test ────────────────────────────────────────────────────
require_once __DIR__ . '/../../lgw-div-cache.php';
