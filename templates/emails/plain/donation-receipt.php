<?php
/**
 * Donation receipt email (plain text).
 *
 * Override by copying to yourtheme/woocommerce/emails/plain/donation-receipt.php.
 *
 * Same rule as the HTML version: no `woocommerce_email_order_details`, because
 * that renders the line items and this receipt shows the donation only.
 *
 * @package Woo_Donation_Cover_Processing_Fee\Templates\Emails\Plain
 * @version 2.6.0
 *
 * @var WC_Order $order
 * @var float    $donation
 * @var float    $processing
 * @var string   $donation_label
 * @var string   $fee_label
 * @var string   $email_heading
 * @var string   $additional_content
 * @var bool     $sent_to_admin
 * @var bool     $plain_text
 * @var WC_Email $email
 */

defined( 'ABSPATH' ) || exit;

$currency_args = array( 'currency' => $order->get_currency() );

/**
 * Money as bare text.
 *
 * wc_price() returns HTML, and the currency symbol inside it is an entity:
 * a dollar sign comes back as `&#036;`. wp_strip_all_tags() removes the tags
 * and leaves the entity, so stripping alone prints "&#036;25.00" in a plain
 * text email. Decode after stripping.
 */
$as_text = static function ( $amount ) use ( $currency_args ) {
	return html_entity_decode(
		wp_strip_all_tags( wc_price( $amount, $currency_args ) ),
		ENT_QUOTES,
		'UTF-8'
	);
};

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

printf(
	/* translators: %s: customer billing full name */
	esc_html__( 'A donation was made by %s.', 'woo-donation-cover-processing-fee' ),
	esc_html( $order->get_formatted_billing_full_name() )
);
echo "\n\n";

printf(
	/* translators: 1: order number, 2: order date */
	esc_html__( 'Order #%1$s (%2$s)', 'woo-donation-cover-processing-fee' ),
	esc_html( $order->get_order_number() ),
	esc_html( wc_format_datetime( $order->get_date_created() ) )
);
echo "\n";
// esc_url_raw(), not esc_url(): this is plain text, and esc_url() would turn
// the ampersand between query arguments into &amp; in the printed URL.
echo esc_url_raw( $order->get_edit_order_url() ) . "\n\n";

echo "----------------------------------------\n\n";

echo esc_html( $donation_label ) . ': ' . esc_html( $as_text( $donation ) ) . "\n";

if ( $processing > 0 ) {
	echo esc_html( $fee_label ) . ': ' . esc_html( $as_text( $processing ) ) . "\n";
}

echo esc_html__( 'Payment method', 'woo-donation-cover-processing-fee' ) . ': ' . esc_html( $order->get_payment_method_title() ) . "\n";
echo esc_html__( 'Order status', 'woo-donation-cover-processing-fee' ) . ': ' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . "\n";

if ( $processing > 0 ) {
	echo "\n" . esc_html__( 'The processing fee is what the customer added to cover card costs across the whole order, not a charge against the donation.', 'woo-donation-cover-processing-fee' ) . "\n";
}

echo "\n----------------------------------------\n\n";

/*
 * @hooked WC_Emails::customer_details() Shows customer details
 * @hooked WC_Emails::email_address() Shows email address
 */
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

echo "\n\n----------------------------------------\n\n";

/**
 * Show user-defined additional content - this is set in the email's settings.
 */
if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
	echo "\n\n----------------------------------------\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
