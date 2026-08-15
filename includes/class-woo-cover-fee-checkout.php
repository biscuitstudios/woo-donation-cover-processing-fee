<?php
/**
 * Front-end: the checkout box, the two cart fees, and the AJAX endpoints.
 *
 * Classic (shortcode) checkout only.
 */

if ( ! defined( 'ABSPATH' ) ) exit; // no direct access

class WOO_Cover_Fee_Checkout {

	/** Nonce action shared by both AJAX endpoints. */
	const NONCE = 'woo_cover_fee';

	const SESSION_DONATION = 'woo_cover_fee_donation';
	const SESSION_FEE      = 'woo_cover_fee';

	/** Per-request memo for cart_has_donation_product(). */
	private $suppressed_cache     = null;
	private $suppressed_cache_key = '';

	public function init() {
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'add_fees' ), 20, 1 );
		add_action( 'woocommerce_review_order_before_payment', array( $this, 'render_box' ), 50 );

		add_action( 'wp_ajax_woo_cover_fee_set', array( $this, 'ajax_set' ) );
		add_action( 'wp_ajax_nopriv_woo_cover_fee_set', array( $this, 'ajax_set' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_action( 'woocommerce_checkout_create_order', array( $this, 'save_order_meta' ), 20, 2 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'clear_session' ), 20 );
	}

	/* ---------------------------------------------------------------------
	 * Auth
	 * ------------------------------------------------------------------ */

	/**
	 * Shared guard for every AJAX handler in this class.
	 *
	 * Nonce only, no capability check: these endpoints are registered for
	 * nopriv because guests check out. The nonce is what stops a
	 * cross-origin page from moving a shopper's total.
	 */
	private function guard() {
		check_ajax_referer( self::NONCE, 'nonce' );
	}

	/* ---------------------------------------------------------------------
	 * Cart fees
	 * ------------------------------------------------------------------ */

	/**
	 * Add the donation and the processing surcharge.
	 *
	 * Both fees are added from this one callback so their order is
	 * deterministic — the surcharge is calculated on a base that already
	 * includes the donation, so a covered gift arrives whole.
	 */
	public function add_fees( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
		if ( ! WC()->session ) return;

		$settings = WOO_Cover_Fee_Settings::get();
		$donation = $this->get_donation();

		if ( $donation > 0 ) {
			// false = not taxable; a voluntary contribution is not a sale.
			$cart->add_fee( $settings['donation_label'], $donation, false );
		}

		if ( ! $settings['fee_enabled'] || ! WC()->session->get( self::SESSION_FEE ) ) {
			return;
		}

		$base = (float) $cart->get_subtotal()
			+ (float) $cart->get_shipping_total()
			+ $donation;

		$fee = WOO_Cover_Fee_Settings::processing_fee( $base, $settings['rate'], $settings['flat'] );

		if ( $fee > 0 ) {
			$cart->add_fee( $settings['fee_label'], $fee, false );
		}
	}

	/**
	 * Session donation amount, re-validated on read.
	 *
	 * Settings can change after an amount was stored (a preset removed, the
	 * max lowered, donations switched off entirely), so the stored value is
	 * checked again rather than trusted. This is what stops a shopper who
	 * already picked an amount from still being charged it after the feature
	 * is disabled.
	 */
	private function get_donation() {
		if ( ! WC()->session ) return 0.0;

		// Already giving via a donation product — drop any checkout donation
		// rather than merely hiding it, so nobody is charged for a control
		// they can no longer see or clear.
		if ( $this->cart_has_donation_product() ) {
			return 0.0;
		}

		$amount = (float) WC()->session->get( self::SESSION_DONATION );

		if ( ! WOO_Cover_Fee_Settings::is_valid_amount( $amount ) ) {
			return 0.0;
		}

		return round( $amount, 2 );
	}

	/**
	 * Does the cart contain a product in one of the suppression categories?
	 *
	 * `product_id` is the parent for variations, which is what carries the
	 * category terms — variation posts have none of their own.
	 *
	 * Memoized per request: this runs on every fee recalculation, and
	 * `woocommerce_cart_calculate_fees` can fire several times per page.
	 */
	private function cart_has_donation_product() {
		$cats = WOO_Cover_Fee_Settings::suppress_cats();

		if ( empty( $cats ) || ! WC()->cart ) {
			return false;
		}

		$contents = WC()->cart->get_cart();
		$key      = md5( implode( ',', $cats ) . '|' . implode( ',', wp_list_pluck( $contents, 'product_id' ) ) );

		if ( null !== $this->suppressed_cache && $key === $this->suppressed_cache_key ) {
			return $this->suppressed_cache;
		}

		$found = false;
		foreach ( $contents as $item ) {
			$product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			if ( $product_id && has_term( $cats, 'product_cat', $product_id ) ) {
				$found = true;
				break;
			}
		}

		$this->suppressed_cache_key = $key;
		$this->suppressed_cache     = $found;

		return $found;
	}

	/* ---------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------ */

	/**
	 * Render the combined donation + processing-fee box.
	 *
	 * This markup sits inside the order-review fragment, so WooCommerce
	 * re-renders it on every `update_checkout`. All state therefore comes
	 * from the session on the PHP side — the JS keeps none of its own.
	 */
	public function render_box() {
		if ( ! WC()->session ) return;

		$settings = WOO_Cover_Fee_Settings::get();
		$amounts  = WOO_Cover_Fee_Settings::amounts();
		$donation = $this->get_donation();

		$has_donation = ( $donation > 0 );
		$is_preset    = $has_donation && in_array( $donation, $amounts, true );

		// A live donation that isn't a preset came from "Other" — reopen the
		// panel with the value so the state survives the re-render.
		$other_active = $has_donation && ! $is_preset;

		$fee_checked = (bool) WC()->session->get( self::SESSION_FEE );
		?>
		<div id="woo-cover-fee-wrapper" class="wcf-box" style="<?php echo esc_attr( $this->css_vars( $settings ) ); ?>">

			<?php
			// Suppressed when the cart already contains a donation product —
			// asking someone to donate on top of a donation reads badly.
			$show_donation = $settings['donation_enabled']
				&& ! $this->cart_has_donation_product()
				&& ( ! empty( $amounts ) || $settings['other_enabled'] );
			?>
			<?php if ( $show_donation ) : ?>
			<div class="wcf-donation">
				<h3 class="wcf-heading"><?php echo esc_html( $settings['heading'] ); ?></h3>

				<div class="wcf-amounts">
					<?php foreach ( $amounts as $amount ) : ?>
						<button type="button"
							class="wcf-amount<?php echo ( $is_preset && $amount === $donation ) ? ' is-selected' : ''; ?>"
							data-amount="<?php echo esc_attr( $amount ); ?>"
							aria-pressed="<?php echo ( $is_preset && $amount === $donation ) ? 'true' : 'false'; ?>">
							<?php echo wp_kses_post( wc_price( $amount ) ); ?>
						</button>
					<?php endforeach; ?>

					<?php if ( $settings['other_enabled'] ) : ?>
						<button type="button"
							class="wcf-amount wcf-other-toggle<?php echo $other_active ? ' is-selected' : ''; ?>"
							aria-expanded="<?php echo $other_active ? 'true' : 'false'; ?>"
							aria-controls="wcf-other-panel">
							<?php echo esc_html( $settings['other_label'] ); ?>
						</button>
					<?php endif; ?>
				</div>

				<?php if ( $settings['other_enabled'] ) : ?>
					<div class="wcf-other-panel" id="wcf-other-panel" <?php echo $other_active ? '' : 'hidden'; ?>>
						<label class="screen-reader-text" for="wcf-other-input">
							<?php echo esc_html( $settings['other_label'] ); ?>
						</label>
						<input type="number"
							id="wcf-other-input"
							class="wcf-other-input"
							inputmode="decimal"
							step="0.01"
							min="<?php echo esc_attr( $settings['other_min'] ); ?>"
							max="<?php echo esc_attr( $settings['other_max'] ); ?>"
							value="<?php echo $other_active ? esc_attr( $donation ) : ''; ?>"
							placeholder="<?php echo esc_attr( $this->range_hint( $settings ) ); ?>" />
						<button type="button" class="wcf-apply">
							<?php echo esc_html( $settings['apply_label'] ); ?>
						</button>
					</div>
				<?php endif; ?>

				<p class="wcf-error" role="alert" hidden></p>
			</div>
			<?php endif; ?>

			<?php if ( $settings['fee_enabled'] ) : ?>
			<div class="wcf-fee">
				<p class="form-row wcf-fee-row">
					<label for="woo_cover_fee">
						<input type="checkbox"
							id="woo_cover_fee"
							name="woo_cover_fee"
							<?php checked( $fee_checked ); ?> />
						<span class="wcf-fee-message"><?php echo wp_kses( $settings['fee_message'], WOO_Cover_Fee_Settings::allowed_html() ); ?></span>
					</label>
				</p>
			</div>
			<?php endif; ?>

		</div>
		<?php
	}

	/**
	 * Build the inline custom-property declaration for the four color
	 * controls. Values are already hex-validated by the sanitizer; the
	 * whole string is esc_attr()'d at the call site.
	 */
	private function css_vars( $settings ) {
		$map = array(
			'--wcf-accent'      => $settings['color_accent'],
			'--wcf-selected-bg' => $settings['color_selected_bg'],
			'--wcf-button-bg'   => $settings['color_button_bg'],
			'--wcf-button-text' => $settings['color_button_text'],
		);

		$out = '';
		foreach ( $map as $property => $value ) {
			$value = sanitize_hex_color( $value );
			if ( $value ) {
				$out .= $property . ':' . $value . ';';
			}
		}
		return $out;
	}

	private function range_hint( $settings ) {
		return sprintf(
			/* translators: 1: minimum amount, 2: maximum amount */
			__( '%1$s – %2$s', 'woo-donation-cover-processing-fee' ),
			wp_strip_all_tags( wc_price( $settings['other_min'] ) ),
			wp_strip_all_tags( wc_price( $settings['other_max'] ) )
		);
	}

	/* ---------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------ */

	/**
	 * Set the donation amount and/or the processing-fee checkbox.
	 *
	 * Handles both controls through one endpoint so a single nonce and a
	 * single guard cover the whole box.
	 */
	public function ajax_set() {
		$this->guard();

		if ( ! WC()->session ) {
			wp_send_json_error( array( 'message' => __( 'Session unavailable.', 'woo-donation-cover-processing-fee' ) ), 400 );
		}

		// --- Donation ---
		if ( isset( $_POST['amount'] ) ) {
			$raw    = sanitize_text_field( wp_unslash( $_POST['amount'] ) );
			$amount = ( '' === trim( $raw ) ) ? 0.0 : (float) wc_format_decimal( $raw );

			if ( ! WOO_Cover_Fee_Settings::is_valid_amount( $amount ) ) {
				wp_send_json_error( array(
					'message' => $this->invalid_amount_message(),
				), 400 );
			}

			WC()->session->set( self::SESSION_DONATION, round( $amount, 2 ) );
		}

		// --- Processing fee checkbox ---
		if ( isset( $_POST['fee'] ) ) {
			$fee = sanitize_text_field( wp_unslash( $_POST['fee'] ) );
			WC()->session->set( self::SESSION_FEE, ( 'true' === $fee || '1' === $fee ) );
		}

		wp_send_json_success( array(
			'donation' => $this->get_donation(),
			'fee'      => (bool) WC()->session->get( self::SESSION_FEE ),
		) );
	}

	private function invalid_amount_message() {
		$settings = WOO_Cover_Fee_Settings::get();

		if ( ! $settings['other_enabled'] ) {
			return __( 'Please choose one of the listed amounts.', 'woo-donation-cover-processing-fee' );
		}

		return sprintf(
			/* translators: 1: minimum amount, 2: maximum amount */
			__( 'Please enter an amount between %1$s and %2$s.', 'woo-donation-cover-processing-fee' ),
			wp_strip_all_tags( wc_price( $settings['other_min'] ) ),
			wp_strip_all_tags( wc_price( $settings['other_max'] ) )
		);
	}

	/* ---------------------------------------------------------------------
	 * Order meta
	 * ------------------------------------------------------------------ */

	/**
	 * Record both amounts on the order.
	 *
	 * Fee line names are user-configurable, so reporting should key off
	 * this meta rather than parsing fee labels.
	 */
	public function save_order_meta( $order, $data ) {
		if ( ! WC()->session || ! WC()->cart ) return;

		$settings = WOO_Cover_Fee_Settings::get();
		$donation = $this->get_donation();

		if ( $donation > 0 ) {
			$order->update_meta_data( '_woo_cover_fee_donation', wc_format_decimal( $donation, 2 ) );
		}

		if ( $settings['fee_enabled'] && WC()->session->get( self::SESSION_FEE ) ) {
			$base = (float) WC()->cart->get_subtotal()
				+ (float) WC()->cart->get_shipping_total()
				+ $donation;

			$fee = WOO_Cover_Fee_Settings::processing_fee( $base, $settings['rate'], $settings['flat'] );

			if ( $fee > 0 ) {
				$order->update_meta_data( '_woo_cover_fee_processing', wc_format_decimal( $fee, 2 ) );
			}
		}
	}

	/**
	 * Reset the box for the next order.
	 */
	public function clear_session( $order_id ) {
		if ( ! WC()->session ) return;
		WC()->session->set( self::SESSION_DONATION, 0 );
		WC()->session->set( self::SESSION_FEE, false );
	}

	/* ---------------------------------------------------------------------
	 * Assets
	 * ------------------------------------------------------------------ */

	public function enqueue_assets() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;

		wp_enqueue_style(
			'woo-donation-cover-processing-fee',
			WOO_COVER_FEE_URL . 'assets/css/checkout.css',
			array(),
			WOO_DONATION_COVER_FEE_VERSION
		);

		wp_enqueue_script(
			'woo-donation-cover-processing-fee',
			WOO_COVER_FEE_URL . 'assets/js/checkout.js',
			array( 'jquery' ),
			WOO_DONATION_COVER_FEE_VERSION,
			true
		);

		wp_localize_script( 'woo-donation-cover-processing-fee', 'wooCoverFee', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE ),
			'genericError' => __( 'Sorry, that could not be applied. Please try again.', 'woo-donation-cover-processing-fee' ),
		) );
	}
}
