<?php
// Direct web access exits cleanly. Under PHPUnit, tests/bootstrap.php defines
// ABSPATH before this file loads, so the suite runs normally; fetched over
// HTTP, ABSPATH is undefined and this returns nothing instead of a fatal that
// would leak the absolute server path.
if ( ! defined( 'ABSPATH' ) ) exit;

use PHPUnit\Framework\TestCase;

/**
 * The donation receipt's send / don't send decision.
 *
 * Scope, stated plainly so a green suite is not read as more than it is: these
 * tests cover the two pure helpers and nothing else. The trigger method, the
 * templates, the recipient handling and the settings screen all need
 * WooCommerce and are only exercised on a real site. See tests/bootstrap.php
 * for the WC_Email stub and why it is never instantiated.
 */
class DonationReceiptTest extends TestCase {

	/* ------------------------------------------------- Values that do send */

	/**
	 * The shape the meta actually arrives in. save_order_meta() writes it
	 * through wc_format_decimal( $donation, 2 ), so it is a string.
	 */
	public function test_formatted_decimal_string_is_a_donation() {
		$this->assertTrue( WOO_Donation_Cover_Fee_Email::has_donation( '25.00' ) );
		$this->assertSame( 25.00, WOO_Donation_Cover_Fee_Email::amount_from_meta( '25.00' ) );
	}

	public function test_small_amount_is_a_donation() {
		$this->assertTrue( WOO_Donation_Cover_Fee_Email::has_donation( '0.01' ) );
		$this->assertSame( 0.01, WOO_Donation_Cover_Fee_Email::amount_from_meta( '0.01' ) );
	}

	public function test_whitespace_around_the_value_is_tolerated() {
		$this->assertTrue( WOO_Donation_Cover_Fee_Email::has_donation( " 50.00\n" ) );
		$this->assertSame( 50.00, WOO_Donation_Cover_Fee_Email::amount_from_meta( " 50.00\n" ) );
	}

	public function test_a_float_is_accepted_as_well_as_a_string() {
		$this->assertTrue( WOO_Donation_Cover_Fee_Email::has_donation( 100.0 ) );
		$this->assertSame( 100.0, WOO_Donation_Cover_Fee_Email::amount_from_meta( 100.0 ) );
	}

	/* --------------------------------------------- Values that do NOT send */

	/**
	 * The common case by a wide margin. An order with no donation never had
	 * the meta written at all, so get_meta() returns an empty string. If this
	 * ever returns true, every order on the site emails the finance inbox.
	 *
	 * @dataProvider nonDonations
	 */
	public function test_non_donation_values_do_not_send( $raw, $why ) {
		$this->assertFalse( WOO_Donation_Cover_Fee_Email::has_donation( $raw ), $why );
		$this->assertSame( 0.0, WOO_Donation_Cover_Fee_Email::amount_from_meta( $raw ), $why );
	}

	/**
	 * Static, because a non-static data provider was deprecated in PHPUnit 10
	 * and removed in PHPUnit 11.
	 */
	public static function nonDonations() {
		return array(
			'missing meta'      => array( '', 'no donation was ever recorded on this order' ),
			'whitespace only'   => array( "  \n", 'still nothing recorded' ),
			'null'              => array( null, 'get_meta() can return null' ),
			'false'             => array( false, 'a falsy meta read is not an amount' ),
			'zero string'       => array( '0.00', 'a cleared donation is not a donation' ),
			'zero int'          => array( 0, 'same, as a number' ),
			'negative'          => array( '-25.00', 'corrupt: a negative fee would reduce the order total' ),
			'non-numeric text'  => array( 'twenty five', 'not a number at all' ),
			'currency symbol'   => array( '$25.00', 'not what wc_format_decimal writes, so not trusted' ),
			'array'             => array( array( '25.00' ), 'meta can come back as an array; it is not an amount' ),
		);
	}

	/* --------------------------------------------------------- Rounding */

	/**
	 * The stored value is always 2dp, but the helper is also what reads the
	 * processing fee, and it must not invent a third decimal place.
	 */
	public function test_amount_is_rounded_to_two_places() {
		$this->assertSame( 25.01, WOO_Donation_Cover_Fee_Email::amount_from_meta( '25.014' ) );
		$this->assertSame( 25.02, WOO_Donation_Cover_Fee_Email::amount_from_meta( '25.015' ) );
	}

	/**
	 * A numeric string can overflow to INF, and INF formatted into an email
	 * is worse than not sending one.
	 */
	public function test_overflowing_value_is_not_a_donation() {
		$this->assertFalse( WOO_Donation_Cover_Fee_Email::has_donation( '1e400' ) );
		$this->assertSame( 0.0, WOO_Donation_Cover_Fee_Email::amount_from_meta( '1e400' ) );
	}
}
