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

_test_reset_options();
