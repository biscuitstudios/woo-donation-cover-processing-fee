<?php
/**
 * Settings storage, sanitization, and the fee math.
 *
 * Everything here is static and side-effect free apart from the option
 * read/write, so the calculation helpers can be unit-tested without a
 * WordPress bootstrap.
 */

if ( ! defined( 'ABSPATH' ) ) exit; // no direct access

class WOO_Cover_Fee_Settings {

	const OPTION = 'woo_cover_fee_settings';

	/** Hard ceiling on the configurable rate — guards the gross-up divisor. */
	const MAX_RATE = 90.0;

	/**
	 * Shipped defaults. Also the fallback for any key missing from a saved
	 * option array, so adding a key here is enough to roll it out.
	 */
	public static function defaults() {
		return array(
			'donation_enabled' => 1,
			'suppress_cats'    => self::default_suppress_cats(),
			'heading'        => 'Give Additional Support',
			'amounts'        => array( 25, 50, 100, 250, 500 ),
			'other_enabled'  => 1,
			'other_min'      => 0.0,
			'other_max'      => 5000.0,
			'other_label'    => 'Other',
			'apply_label'    => 'Apply Donation',
			'donation_label' => 'Additional donation',

			'fee_enabled'    => 1,
			'fee_label'      => 'Cover payment processing (thank you!)',
			'fee_message'    => "I'd like to add a small amount to cover payment processing so 100% of my payment supports us.",
			'rate'           => 2.9,
			'flat'           => 0.30,

			'color_accent'      => '#3b6ea5', // amount button border + text
			'color_selected_bg' => '#3b6ea5', // fill of the chosen amount
			'color_button_bg'   => '#3b6ea5', // Apply Donation fill
			'color_button_text' => '#ffffff', // Apply Donation + selected-amount text
		);
	}

	/**
	 * Ship with the "Donations" category pre-selected when one exists, so the
	 * feature is useful before anyone visits the settings screen.
	 *
	 * Guarded for the test bootstrap, which has no taxonomy functions.
	 */
	private static function default_suppress_cats() {
		if ( ! function_exists( 'get_term_by' ) ) {
			return array();
		}

		$term = get_term_by( 'slug', 'donations', 'product_cat' );

		return ( $term && ! is_wp_error( $term ) ) ? array( (int) $term->term_id ) : array();
	}

	/**
	 * Product category IDs that suppress the donation section when present
	 * in the cart.
	 */
	public static function suppress_cats() {
		$value = self::value( 'suppress_cats' );

		return is_array( $value ) ? array_values( array_filter( array_map( 'absint', $value ) ) ) : array();
	}

	/**
	 * Saved settings merged over defaults.
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args( $saved, self::defaults() );
	}

	/**
	 * Single setting with default fallback.
	 */
	public static function value( $key ) {
		$all = self::get();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * HTML permitted in the checkout fee message.
	 *
	 * Deliberately tighter than wp_kses_post() — this string renders inside a
	 * <label> on the public checkout, so block-level, media, and iframe tags
	 * have no business here (wp_kses_post would allow <img>, letting an admin
	 * embed a remote image on checkout). The same list is used on save and on
	 * output, so what is stored is exactly what renders.
	 */
	public static function allowed_html() {
		return array(
			'a'      => array(
				'href'   => array(),
				'title'  => array(),
				'target' => array(),
				'rel'    => array(),
				'class'  => array(),
			),
			'strong' => array(),
			'b'      => array(),
			'em'     => array(),
			'i'      => array(),
			'br'     => array(),
			'span'   => array( 'class' => array() ),
		);
	}

	/**
	 * Preset amounts as a clean, ordered, de-duplicated float list.
	 */
	public static function amounts() {
		$raw = self::value( 'amounts' );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();
		foreach ( $raw as $amount ) {
			$amount = round( (float) $amount, 2 );
			if ( $amount > 0 ) {
				$out[] = $amount;
			}
		}

		$out = array_values( array_unique( $out, SORT_NUMERIC ) );
		sort( $out, SORT_NUMERIC );
		return $out;
	}

	/**
	 * Server-side gate for any donation amount arriving from the browser.
	 *
	 * 0 is always allowed — it is how the front end clears a donation.
	 * Presets are allowed regardless of the min/max, which only bound the
	 * free-entry "Other" field.
	 *
	 * @return bool
	 */
	public static function is_valid_amount( $amount ) {
		if ( ! is_numeric( $amount ) ) {
			return false;
		}

		$amount = round( (float) $amount, 2 );

		// Negative fees would reduce the order total — never acceptable.
		if ( $amount < 0 || ! is_finite( $amount ) ) {
			return false;
		}

		if ( 0.0 === $amount ) {
			return true; // clear the donation
		}

		// Feature switched off — nothing but a clear is acceptable, so a
		// stale session value can't keep charging after it's disabled.
		if ( ! self::value( 'donation_enabled' ) ) {
			return false;
		}

		if ( in_array( $amount, self::amounts(), true ) ) {
			return true;
		}

		if ( ! self::value( 'other_enabled' ) ) {
			return false;
		}

		$min = (float) self::value( 'other_min' );
		$max = (float) self::value( 'other_max' );

		return ( $amount >= $min && $amount <= $max );
	}

	/**
	 * Gross-up processing fee.
	 *
	 * Solves for the surcharge that leaves the store whole after the
	 * processor takes its cut of the *grossed-up* total:
	 *
	 *   net = total - (total * rate) - flat
	 *   total = (base + flat) / (1 - rate)
	 *   fee   = total - base
	 *
	 * At 2.9% + $0.30 on a $100 base this returns 3.30, not 3.20 — the
	 * naive `base * rate + flat` under-recovers because the processor also
	 * charges its percentage on the surcharge itself.
	 *
	 * Pure function: no WP dependencies, unit-testable.
	 *
	 * @param float $base         Amount to be made whole (subtotal + shipping + donation).
	 * @param float $rate_percent Processor percentage, e.g. 2.9.
	 * @param float $flat         Processor flat fee, e.g. 0.30.
	 * @return float Fee rounded to 2dp, never negative.
	 */
	public static function processing_fee( $base, $rate_percent, $flat ) {
		$base = (float) $base;
		$flat = max( 0.0, (float) $flat );
		$rate = (float) $rate_percent;

		if ( $base <= 0 ) {
			return 0.0;
		}

		// Clamp before dividing — a rate of 100 would blow up the divisor.
		$rate = min( max( $rate, 0.0 ), self::MAX_RATE ) / 100;

		$total = ( $base + $flat ) / ( 1 - $rate );
		$fee   = round( $total - $base, 2 );

		return $fee > 0 ? $fee : 0.0;
	}

	/**
	 * Sanitize a raw $_POST settings array.
	 *
	 * Every value is coerced to its expected type and range here; callers
	 * may hand this untrusted input directly.
	 */
	public static function sanitize( $raw ) {
		$defaults = self::defaults();
		$clean    = array();

		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		// --- Text ---
		foreach ( array( 'heading', 'other_label', 'apply_label', 'donation_label', 'fee_label' ) as $key ) {
			$value         = isset( $raw[ $key ] ) ? sanitize_text_field( wp_unslash( $raw[ $key ] ) ) : '';
			$clean[ $key ] = ( '' === $value ) ? $defaults[ $key ] : $value;
		}

		// --- Message: links and light inline markup allowed, nothing else ---
		$message             = isset( $raw['fee_message'] ) ? wp_kses( wp_unslash( $raw['fee_message'] ), self::allowed_html() ) : '';
		$clean['fee_message'] = ( '' === trim( wp_strip_all_tags( $message ) ) ) ? $defaults['fee_message'] : $message;

		// --- Amounts ---
		$amounts = array();
		if ( isset( $raw['amounts'] ) && is_array( $raw['amounts'] ) ) {
			foreach ( wp_unslash( $raw['amounts'] ) as $amount ) {
				if ( '' === trim( (string) $amount ) ) {
					continue; // blank row — user added and left empty
				}
				$amount = round( (float) wc_format_decimal( $amount ), 2 );
				if ( $amount > 0 ) {
					$amounts[] = $amount;
				}
			}
		}
		$amounts = array_values( array_unique( $amounts, SORT_NUMERIC ) );
		sort( $amounts, SORT_NUMERIC );
		$clean['amounts'] = empty( $amounts ) ? $defaults['amounts'] : $amounts;

		// --- Toggles ---
		// --- Suppression categories ---
		// An absent field means "none selected", which is a legitimate saved
		// state — so the key is always written rather than left to fall back
		// to the default.
		$cats = array();
		if ( isset( $raw['suppress_cats'] ) && is_array( $raw['suppress_cats'] ) ) {
			foreach ( wp_unslash( $raw['suppress_cats'] ) as $id ) {
				$id = absint( $id );
				if ( ! $id ) {
					continue;
				}
				// Only accept IDs that really are product categories.
				if ( function_exists( 'get_term' ) ) {
					$term = get_term( $id, 'product_cat' );
					if ( ! $term || is_wp_error( $term ) ) {
						continue;
					}
				}
				$cats[] = $id;
			}
		}
		$clean['suppress_cats'] = array_values( array_unique( $cats ) );

		$clean['donation_enabled'] = empty( $raw['donation_enabled'] ) ? 0 : 1;
		$clean['other_enabled']    = empty( $raw['other_enabled'] ) ? 0 : 1;
		$clean['fee_enabled']      = empty( $raw['fee_enabled'] ) ? 0 : 1;

		// --- Other bounds ---
		$min = isset( $raw['other_min'] ) ? (float) wc_format_decimal( wp_unslash( $raw['other_min'] ) ) : 0.0;
		$max = isset( $raw['other_max'] ) ? (float) wc_format_decimal( wp_unslash( $raw['other_max'] ) ) : 0.0;
		$min = max( 0.0, round( $min, 2 ) );
		$max = max( 0.0, round( $max, 2 ) );
		if ( $max < $min ) {
			$max = $min; // transposed entry — collapse rather than invert
		}
		$clean['other_min'] = $min;
		$clean['other_max'] = $max;

		// --- Processor terms ---
		$rate          = isset( $raw['rate'] ) ? (float) wc_format_decimal( wp_unslash( $raw['rate'] ) ) : 0.0;
		$clean['rate'] = min( max( round( $rate, 3 ), 0.0 ), self::MAX_RATE );

		$flat          = isset( $raw['flat'] ) ? (float) wc_format_decimal( wp_unslash( $raw['flat'] ) ) : 0.0;
		$clean['flat'] = max( 0.0, round( $flat, 2 ) );

		// --- Colors ---
		foreach ( array( 'color_accent', 'color_selected_bg', 'color_button_bg', 'color_button_text' ) as $key ) {
			$color         = isset( $raw[ $key ] ) ? sanitize_hex_color( wp_unslash( $raw[ $key ] ) ) : '';
			$clean[ $key ] = $color ? $color : $defaults[ $key ];
		}

		return $clean;
	}
}
