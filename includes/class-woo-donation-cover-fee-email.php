<?php
/**
 * Internal donation receipt email.
 *
 * A WooCommerce email in the ordinary sense: it registers through
 * `woocommerce_email_classes`, so it gets its own row under
 * WooCommerce > Settings > Emails with the enable switch, recipient list,
 * subject, heading and HTML/plain choice that every core email has. Nothing
 * about it is configured on this plugin's own settings screen.
 *
 * It exists because WooCommerce Advanced Notifications cannot route on a
 * donation. That plugin builds its match set from `$order->get_items()` and
 * keys on product, category and shipping class. A donation here is a *fee*
 * line added with `WC_Cart::add_fee()`, so it has no product and therefore no
 * category, and there is no filter on its notification lookup to inject one.
 * Advanced Notifications keeps doing its department routing untouched; this
 * runs beside it.
 *
 * Deliberately shipped disabled, with no default recipient. On a site that
 * does not want it, nothing sends and nothing needs switching off.
 *
 * The class name carries the Donation prefix for the same reason the updater
 * does: this plugin shares the `WOO_Cover_Fee_*` names with its sibling
 * woo-cover-processing-fee, and adding another shared name would deepen that
 * collision rather than reveal it.
 */

if ( ! defined( 'ABSPATH' ) ) exit; // no direct access

class WOO_Donation_Cover_Fee_Email extends WC_Email {

	/** Order meta written by WOO_Cover_Fee_Checkout::save_order_meta(). */
	const DONATION_META   = '_woo_cover_fee_donation';
	const PROCESSING_META = '_woo_cover_fee_processing';

	/**
	 * Marks an order as already receipted.
	 *
	 * Several status transitions qualify, and an order can pass through more
	 * than one of them (pending > on-hold, then on-hold > processing when the
	 * bank transfer clears). Without this the finance inbox gets the same
	 * donation twice. Core's New Order email guards itself the same way with
	 * `_new_order_email_sent`.
	 */
	const SENT_META = '_woo_cover_fee_receipt_sent';

	public function __construct() {
		$this->id             = 'woo_donation_cover_fee_receipt';
		$this->title          = __( 'Donation receipt (internal)', 'woo-donation-cover-processing-fee' );
		$this->description    = __( 'Sent to the recipients below when an order includes a donation. Shows the donation and nothing that was bought. Disabled until you enable it and enter at least one address.', 'woo-donation-cover-processing-fee' );
		$this->email_group    = 'orders';
		$this->customer_email = false;

		// Templates live in this plugin, not in WooCommerce. template_base is
		// also what the "copy to theme" control on the email settings screen
		// reads, so setting it keeps that control working.
		$this->template_base  = WOO_COVER_FEE_PATH . 'templates/';
		$this->template_html  = 'emails/donation-receipt.php';
		$this->template_plain = 'emails/plain/donation-receipt.php';

		$this->placeholders = array(
			'{order_date}'   => '',
			'{order_number}' => '',
		);

		foreach ( self::trigger_hooks() as $hook ) {
			add_action( $hook, array( $this, 'trigger' ), 10, 2 );
		}

		parent::__construct();

		// No fallback to admin_email on purpose. An unconfigured email must
		// send nowhere rather than somewhere nobody chose.
		$this->recipient = $this->get_option( 'recipient', '' );
	}

	/**
	 * Status transitions that count as "the order is real, money is in".
	 *
	 * The same set core's New Order email uses, so a donation receipt arrives
	 * on exactly the orders a New Order email would arrive on. on-hold is in
	 * the list because that is where bank transfer and cheque orders land.
	 *
	 * @return string[]
	 */
	private static function trigger_hooks() {
		return array(
			'woocommerce_order_status_pending_to_processing_notification',
			'woocommerce_order_status_pending_to_completed_notification',
			'woocommerce_order_status_pending_to_on-hold_notification',
			'woocommerce_order_status_failed_to_processing_notification',
			'woocommerce_order_status_failed_to_completed_notification',
			'woocommerce_order_status_failed_to_on-hold_notification',
			'woocommerce_order_status_cancelled_to_processing_notification',
			'woocommerce_order_status_cancelled_to_completed_notification',
			'woocommerce_order_status_cancelled_to_on-hold_notification',
		);
	}

	/* ---------------------------------------------------------------------
	 * The send / don't send decision
	 *
	 * Both helpers below are pure: no WordPress, no WooCommerce, no $this.
	 * That is what makes the decision unit-testable without a bootstrap, and
	 * it is the half of this class most worth testing.
	 * ------------------------------------------------------------------ */

	/**
	 * Read a stored money meta value as a float.
	 *
	 * The meta is written through wc_format_decimal(), so it arrives as a
	 * string like "25.00". Anything else, including an empty string from an
	 * order that never had a donation, is not an amount.
	 *
	 * @param mixed $raw Whatever get_meta() returned.
	 * @return float Positive amount to 2dp, or 0.0.
	 */
	public static function amount_from_meta( $raw ) {
		if ( is_string( $raw ) ) {
			$raw = trim( $raw );
		}

		if ( ! is_scalar( $raw ) || ! is_numeric( $raw ) ) {
			return 0.0;
		}

		$amount = round( (float) $raw, 2 );

		// A negative or infinite stored value is corrupt, not a donation.
		if ( ! is_finite( $amount ) || $amount <= 0 ) {
			return 0.0;
		}

		return $amount;
	}

	/**
	 * Does this order's donation meta describe an actual donation?
	 *
	 * @param mixed $raw Whatever get_meta() returned.
	 * @return bool
	 */
	public static function has_donation( $raw ) {
		return self::amount_from_meta( $raw ) > 0;
	}

	/* ---------------------------------------------------------------------
	 * Trigger
	 * ------------------------------------------------------------------ */

	/**
	 * @param int            $order_id Order ID.
	 * @param WC_Order|false $order    Order object, when the hook passed one.
	 */
	public function trigger( $order_id, $order = false ) {
		$this->setup_locale();

		if ( $order_id && ! is_a( $order, 'WC_Order' ) ) {
			$order = wc_get_order( $order_id );
		}

		if ( ! is_a( $order, 'WC_Order' ) ) {
			$this->restore_locale();
			return;
		}

		$this->object                         = $order;
		$this->placeholders['{order_date}']   = wc_format_datetime( $order->get_date_created() );
		$this->placeholders['{order_number}'] = $order->get_order_number();

		// Read through the CRUD layer, never postmeta. Under HPOS this meta
		// lives in wp_wc_orders_meta and a postmeta query returns nothing.
		if ( ! self::has_donation( $order->get_meta( self::DONATION_META ) ) ) {
			$this->restore_locale();
			return;
		}

		if ( 'true' === $order->get_meta( self::SENT_META ) ) {
			$this->restore_locale();
			return;
		}

		$recipient = $this->get_recipient();

		// get_recipient() drops anything that fails is_email(), so a setting
		// full of typos reduces to an empty string here rather than throwing.
		if ( ! $this->is_enabled() || '' === $recipient ) {
			$this->restore_locale();
			return;
		}

		$sent = $this->send(
			$recipient,
			$this->get_subject(),
			$this->get_content(),
			$this->get_headers(),
			$this->get_attachments()
		);

		if ( $sent ) {
			$order->update_meta_data( self::SENT_META, 'true' );
			$order->save();
		}

		$this->restore_locale();
	}

	/* ---------------------------------------------------------------------
	 * Content
	 * ------------------------------------------------------------------ */

	/**
	 * Arguments handed to both templates.
	 *
	 * The line items are deliberately absent. This email answers "a donation
	 * came in", and the department that needs the basket already receives a
	 * receipt of its own.
	 */
	private function template_args( $plain_text ) {
		$order    = $this->object;
		$settings = WOO_Cover_Fee_Settings::get();

		return array(
			'order'              => $order,
			'donation'           => $order ? self::amount_from_meta( $order->get_meta( self::DONATION_META ) ) : 0.0,
			'processing'         => $order ? self::amount_from_meta( $order->get_meta( self::PROCESSING_META ) ) : 0.0,
			'donation_label'     => $settings['donation_label'],
			'fee_label'          => $settings['fee_label'],
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => true,
			'plain_text'         => $plain_text,
			'email'              => $this,
		);
	}

	public function get_content_html() {
		return wc_get_template_html(
			$this->template_html,
			$this->template_args( false ),
			'',                    // theme override path: yourtheme/woocommerce/
			$this->template_base
		);
	}

	public function get_content_plain() {
		return wc_get_template_html(
			$this->template_plain,
			$this->template_args( true ),
			'',
			$this->template_base
		);
	}

	public function get_default_subject() {
		return __( '[{site_title}] Donation received with order #{order_number}', 'woo-donation-cover-processing-fee' );
	}

	public function get_default_heading() {
		return __( 'Donation received', 'woo-donation-cover-processing-fee' );
	}

	public function get_default_additional_content() {
		return '';
	}

	/* ---------------------------------------------------------------------
	 * Settings screen
	 * ------------------------------------------------------------------ */

	/**
	 * Core's fields, with two changes: enabled defaults to no, and a
	 * recipient field is added (the base class only gives one to emails that
	 * declare it themselves).
	 */
	public function init_form_fields() {
		/* translators: %s: list of placeholders */
		$placeholder_text = sprintf(
			__( 'Available placeholders: %s', 'woo-donation-cover-processing-fee' ),
			'<code>' . esc_html( implode( '</code>, <code>', array_keys( $this->placeholders ) ) ) . '</code>'
		);

		$this->form_fields = array(
			'enabled'            => array(
				'title'   => __( 'Enable/Disable', 'woo-donation-cover-processing-fee' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable this email notification', 'woo-donation-cover-processing-fee' ),
				'default' => 'no',
			),
			'recipient'          => array(
				'title'       => __( 'Recipient(s)', 'woo-donation-cover-processing-fee' ),
				'type'        => 'text',
				'description' => __( 'Comma separated. There is no default, so nothing is sent until at least one address is entered here.', 'woo-donation-cover-processing-fee' ),
				'placeholder' => '',
				'default'     => '',
				'desc_tip'    => true,
			),
			'subject'            => array(
				'title'       => __( 'Subject', 'woo-donation-cover-processing-fee' ),
				'type'        => 'text',
				'desc_tip'    => true,
				'description' => $placeholder_text,
				'placeholder' => $this->get_default_subject(),
				'default'     => '',
			),
			'heading'            => array(
				'title'       => __( 'Email heading', 'woo-donation-cover-processing-fee' ),
				'type'        => 'text',
				'desc_tip'    => true,
				'description' => $placeholder_text,
				'placeholder' => $this->get_default_heading(),
				'default'     => '',
			),
			'additional_content' => array(
				'title'       => __( 'Additional content', 'woo-donation-cover-processing-fee' ),
				'description' => __( 'Text to appear below the main email content.', 'woo-donation-cover-processing-fee' ) . ' ' . $placeholder_text,
				'css'         => 'width:400px; height: 75px;',
				'placeholder' => __( 'N/A', 'woo-donation-cover-processing-fee' ),
				'type'        => 'textarea',
				'default'     => $this->get_default_additional_content(),
				'desc_tip'    => true,
			),
			'email_type'         => array(
				'title'       => __( 'Email type', 'woo-donation-cover-processing-fee' ),
				'type'        => 'select',
				'description' => __( 'Choose which format of email to send.', 'woo-donation-cover-processing-fee' ),
				'default'     => 'html',
				'class'       => 'email_type wc-enhanced-select',
				'options'     => $this->get_email_type_options(),
				'desc_tip'    => true,
			),
		);
	}
}
