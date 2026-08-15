<?php
// Direct web access exits cleanly. Under PHPUnit, tests/bootstrap.php defines
// ABSPATH before this file loads, so the suite runs normally; fetched over
// HTTP, ABSPATH is undefined and this returns nothing instead of a fatal that
// would leak the absolute server path.
if ( ! defined( 'ABSPATH' ) ) exit;

use PHPUnit\Framework\TestCase;

class SettingsTest extends TestCase {

	protected function setUp(): void {
		_test_reset_options();
	}

	/* ---------------------------------------------------------------- Math */

	/**
	 * The whole point of the gross-up: after the processor takes its cut of
	 * the larger total, the store nets exactly the original base.
	 *
	 * @dataProvider baseAmounts
	 */
	public function test_gross_up_leaves_store_whole( $base ) {
		$rate = 2.9;
		$flat = 0.30;

		$fee     = WOO_Cover_Fee_Settings::processing_fee( $base, $rate, $flat );
		$charged = $base + $fee;
		$cut     = round( $charged * ( $rate / 100 ) + $flat, 2 );
		$net     = round( $charged - $cut, 2 );

		$this->assertEqualsWithDelta( $base, $net, 0.01, "Store did not net the base on {$base}" );
	}

	public function baseAmounts() {
		return array( array( 10 ), array( 25 ), array( 100 ), array( 250.50 ), array( 1000 ), array( 5000 ) );
	}

	public function test_gross_up_beats_naive_formula() {
		// Naive: 100 * 0.029 + 0.30 = 3.20, which under-recovers by ~0.10.
		$this->assertSame( 3.30, WOO_Cover_Fee_Settings::processing_fee( 100, 2.9, 0.30 ) );
	}

	public function test_zero_base_yields_no_fee() {
		$this->assertSame( 0.0, WOO_Cover_Fee_Settings::processing_fee( 0, 2.9, 0.30 ) );
	}

	public function test_negative_base_yields_no_fee() {
		$this->assertSame( 0.0, WOO_Cover_Fee_Settings::processing_fee( -50, 2.9, 0.30 ) );
	}

	public function test_negative_inputs_are_clamped_not_propagated() {
		$this->assertSame( 0.0, WOO_Cover_Fee_Settings::processing_fee( 100, -5, -1 ) );
	}

	/** A 100% rate must not divide by zero. */
	public function test_extreme_rate_does_not_blow_up() {
		$fee = WOO_Cover_Fee_Settings::processing_fee( 100, 100, 0.30 );
		$this->assertIsFloat( $fee );
		$this->assertTrue( is_finite( $fee ) );
	}

	/* ---------------------------------------------------- Amount validation */

	public function test_zero_is_valid_because_it_clears_the_donation() {
		$this->assertTrue( WOO_Cover_Fee_Settings::is_valid_amount( 0 ) );
	}

	public function test_presets_are_valid() {
		$this->assertTrue( WOO_Cover_Fee_Settings::is_valid_amount( 100 ) );
	}

	/** A negative fee would reduce the order total — the key rejection. */
	public function test_negative_amount_is_rejected() {
		$this->assertFalse( WOO_Cover_Fee_Settings::is_valid_amount( -50 ) );
	}

	public function test_amount_above_max_is_rejected() {
		$this->assertFalse( WOO_Cover_Fee_Settings::is_valid_amount( 5001 ) );
	}

	public function test_non_numeric_amount_is_rejected() {
		$this->assertFalse( WOO_Cover_Fee_Settings::is_valid_amount( 'abc' ) );
	}

	public function test_custom_amount_within_range_is_valid() {
		$this->assertTrue( WOO_Cover_Fee_Settings::is_valid_amount( 33.33 ) );
	}

	public function test_custom_amount_rejected_when_other_disabled() {
		_test_set_options( array( 'other_enabled' => 0 ) );
		$this->assertFalse( WOO_Cover_Fee_Settings::is_valid_amount( 33.33 ) );
		$this->assertTrue( WOO_Cover_Fee_Settings::is_valid_amount( 100 ), 'Presets stay valid' );
	}

	/* -------------------------------------------------------- Sanitization */

	public function test_sanitize_drops_blank_and_negative_amounts() {
		$clean = WOO_Cover_Fee_Settings::sanitize( array(
			'amounts' => array( '25', '', '-10', '50', '0' ),
		) );
		$this->assertSame( array( 25.0, 50.0 ), $clean['amounts'] );
	}

	public function test_sanitize_deduplicates_and_sorts_amounts() {
		$clean = WOO_Cover_Fee_Settings::sanitize( array(
			'amounts' => array( '100', '25', '100', '50' ),
		) );
		$this->assertSame( array( 25.0, 50.0, 100.0 ), $clean['amounts'] );
	}

	public function test_sanitize_falls_back_when_all_amounts_removed() {
		$clean = WOO_Cover_Fee_Settings::sanitize( array( 'amounts' => array() ) );
		$this->assertSame( WOO_Cover_Fee_Settings::defaults()['amounts'], $clean['amounts'] );
	}

	public function test_sanitize_collapses_transposed_min_max() {
		$clean = WOO_Cover_Fee_Settings::sanitize( array(
			'other_min' => '500',
			'other_max' => '100',
		) );
		$this->assertSame( 500.0, $clean['other_min'] );
		$this->assertSame( 500.0, $clean['other_max'] );
	}

	public function test_sanitize_clamps_rate_to_maximum() {
		$clean = WOO_Cover_Fee_Settings::sanitize( array( 'rate' => '9999' ) );
		$this->assertSame( WOO_Cover_Fee_Settings::MAX_RATE, $clean['rate'] );
	}

	public function test_sanitize_rejects_negative_flat_fee() {
		$clean = WOO_Cover_Fee_Settings::sanitize( array( 'flat' => '-5' ) );
		$this->assertSame( 0.0, $clean['flat'] );
	}

	public function test_sanitize_falls_back_on_invalid_color() {
		$clean = WOO_Cover_Fee_Settings::sanitize( array(
			'color_accent' => 'red; background: url(javascript:alert(1))',
		) );
		$this->assertSame( WOO_Cover_Fee_Settings::defaults()['color_accent'], $clean['color_accent'] );
	}

	public function test_sanitize_accepts_valid_hex_color() {
		$clean = WOO_Cover_Fee_Settings::sanitize( array( 'color_accent' => '#AABBCC' ) );
		$this->assertSame( '#AABBCC', $clean['color_accent'] );
	}

	public function test_sanitize_unchecked_boxes_become_zero() {
		$clean = WOO_Cover_Fee_Settings::sanitize( array() );
		$this->assertSame( 0, $clean['other_enabled'] );
		$this->assertSame( 0, $clean['fee_enabled'] );
	}
}
