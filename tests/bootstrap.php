<?php
/**
 * Test bootstrap.
 *
 * No WordPress load — the settings class is deliberately structured so its
 * calculation and validation helpers depend only on the handful of WP
 * functions stubbed below.
 */

define( 'ABSPATH', true );

if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults ) {
		return array_merge( $defaults, (array) $args );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return $GLOBALS['_test_options'][ $key ] ?? $default;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( strip_tags( (string) $str ) );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( (string) $value );
	}
}

/**
 * Passthrough stub — NOT a reimplementation of wp_kses_post().
 *
 * Message-field tag filtering is therefore NOT covered by these tests; it
 * relies entirely on the real WordPress function at runtime. Do not read a
 * passing suite as evidence that fee_message is sanitized.
 */
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $str ) {
		return (string) $str;
	}
}

// Passthrough — the second argument (allowlist) is ignored, so this does NOT
// exercise the real tag filtering either. Same caveat as above.
if ( ! function_exists( 'wp_kses' ) ) {
	function wp_kses( $str, $allowed = array(), $protocols = array() ) {
		return (string) $str;
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $str ) {
		return strip_tags( (string) $str );
	}
}

if ( ! function_exists( 'sanitize_hex_color' ) ) {
	function sanitize_hex_color( $color ) {
		return preg_match( '/^#([A-Fa-f0-9]{3}){1,2}$/', (string) $color ) ? $color : '';
	}
}

if ( ! function_exists( 'wc_format_decimal' ) ) {
	function wc_format_decimal( $number, $dp = false ) {
		$number = (float) str_replace( array( ',', '$' ), '', (string) $number );
		return ( false !== $dp ) ? number_format( $number, $dp, '.', '' ) : (string) $number;
	}
}

/** Replace the stored settings for a single test. */
function _test_set_options( array $settings ) {
	$GLOBALS['_test_options'][ WOO_Cover_Fee_Settings::OPTION ] = $settings;
}

function _test_reset_options() {
	$GLOBALS['_test_options'] = array();
}

require_once __DIR__ . '/../includes/class-woo-cover-fee-settings.php';

/**
 * Stand-in for WooCommerce's WC_Email so the donation receipt class can be
 * declared without loading WooCommerce.
 *
 * It is an empty shell and it is NOT exercised. Only the pure static helpers
 * on WOO_Donation_Cover_Fee_Email are tested, and those never touch $this,
 * the parent, or any WordPress function. Instantiating the email class here
 * would fatal (the constructor calls parent::__construct(), which this stub
 * does not have) and that is deliberate: it keeps the tests honest about what
 * they cover. The trigger, the templates and the settings screen are only
 * exercised on a real site.
 */
if ( ! class_exists( 'WC_Email' ) ) {
	class WC_Email {}
}

require_once __DIR__ . '/../includes/class-woo-donation-cover-fee-email.php';

_test_reset_options();

/* ------------------------------------------- Changelog renderer (updater) */

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $str ) {
		return htmlspecialchars( (string) $str, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = '' ) {
		return esc_html( $text );
	}
}

/**
 * Close enough to the real esc_url() for the changelog renderer: it rejects a
 * scheme it does not recognise and encodes ampersands. NOT a reimplementation,
 * so a passing suite says nothing about the real function's stricter handling.
 */
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		$url = trim( (string) $url );
		return preg_match( '#^https?://#', $url ) ? str_replace( '&', '&#038;', $url ) : '';
	}
}

require_once __DIR__ . '/../includes/class-woo-donation-cover-fee-updater.php';

/* ------------------------------------ Stubs for the updater's HTTP and dates */

// The response body is whatever a test put in $GLOBALS['_test_http']. Nothing
// here reaches the network.
if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( $url, $args = array() ) {
		$GLOBALS['_test_http_url'] = $url;
		return array(
			'code' => $GLOBALS['_test_http_code'] ?? 200,
			'body' => $GLOBALS['_test_http'] ?? '',
		);
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $r ) { return $r['body']; }
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) { return false; }
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) { return 'https://example.test' . $path; }
}
if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $what = '' ) { return '7.1'; }
}
if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( $file ) { return basename( dirname( $file ) ) . '/' . basename( $file ); }
}

// Fixed to UTC so a heading's date does not depend on the machine running the
// suite. The real wp_date() uses the site's timezone.
if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( $format, $timestamp = null, $timezone = null ) {
		return gmdate( $format, $timestamp ?? time() );
	}
}
