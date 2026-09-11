<?php
/**
 * Donation receipt email (HTML).
 *
 * Override by copying to yourtheme/woocommerce/emails/donation-receipt.php.
 *
 * There is no call to `woocommerce_email_order_details` here, and that is the
 * point of the whole template: that action renders the line item table, and
 * this receipt is not supposed to show what was bought. Only the donation, the
 * optional processing fee, and enough order identity to find the order.
 *
 * The table markup mirrors WooCommerce's own order tables (`class="td"`,
 * `cellpadding="6"`, `border="1"`) so the inline styler in
 * templates/emails/email-styles.php picks it up and it matches every other
 * receipt the store sends.
 *
 * @package Woo_Donation_Cover_Processing_Fee\Templates\Emails
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

/*
 * @hooked WC_Emails::email_header() Output the email header
 */
do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p>
	<?php
	printf(
		/* translators: %s: customer billing full name */
		esc_html__( 'A donation was made by %s.', 'woo-donation-cover-processing-fee' ),
		esc_html( $order->get_formatted_billing_full_name() )
	);
	?>
</p>

<h2>
	<a class="link" href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">
		<?php
		/* translators: %s: order number */
		printf( esc_html__( 'Order #%s', 'woo-donation-cover-processing-fee' ), esc_html( $order->get_order_number() ) );
		?>
	</a>
	<span>
		(<time datetime="<?php echo esc_attr( $order->get_date_created()->format( 'c' ) ); ?>"><?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?></time>)
	</span>
</h2>

<div style="margin-bottom: 40px;">
	<table class="td font-family" cellspacing="0" cellpadding="6" style="width: 100%;" border="1">
		<tbody>
			<tr>
				<th class="td text-align-left" scope="row"><?php echo esc_html( $donation_label ); ?></th>
				<td class="td text-align-left"><?php echo wp_kses_post( wc_price( $donation, $currency_args ) ); ?></td>
			</tr>

			<?php if ( $processing > 0 ) : ?>
				<tr>
					<th class="td text-align-left" scope="row"><?php echo esc_html( $fee_label ); ?></th>
					<td class="td text-align-left"><?php echo wp_kses_post( wc_price( $processing, $currency_args ) ); ?></td>
				</tr>
			<?php endif; ?>

			<tr>
				<th class="td text-align-left" scope="row"><?php esc_html_e( 'Payment method', 'woo-donation-cover-processing-fee' ); ?></th>
				<td class="td text-align-left"><?php echo esc_html( $order->get_payment_method_title() ); ?></td>
			</tr>

			<tr>
				<th class="td text-align-left" scope="row"><?php esc_html_e( 'Order status', 'woo-donation-cover-processing-fee' ); ?></th>
				<td class="td text-align-left"><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></td>
			</tr>
		</tbody>
	</table>

	<?php if ( $processing > 0 ) : ?>
		<p style="margin-top: 12px;">
			<?php esc_html_e( 'The processing fee is what the customer added to cover card costs across the whole order, not a charge against the donation.', 'woo-donation-cover-processing-fee' ); ?>
		</p>
	<?php endif; ?>
</div>

<?php
/*
 * Customer identity only. Billing and shipping addresses plus the email
 * address, from WooCommerce's own handlers, so this section looks and behaves
 * exactly as it does in a normal admin receipt.
 *
 * @hooked WC_Emails::customer_details() Shows customer details
 * @hooked WC_Emails::email_address() Shows email address
 */
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

/**
 * Show user-defined additional content - this is set in the email's settings.
 */
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

/*
 * @hooked WC_Emails::email_footer() Output the email footer
 */
do_action( 'woocommerce_email_footer', $email );
