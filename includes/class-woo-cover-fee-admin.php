<?php
/**
 * Admin settings screen.
 *
 * Menu sits under WooCommerce and is gated on manage_woocommerce, so shop
 * managers can reach it without being full administrators.
 */

if ( ! defined( 'ABSPATH' ) ) exit; // no direct access

class WOO_Cover_Fee_Admin {

	const CAP         = 'manage_woocommerce';
	const SLUG        = 'woo-donation-cover-processing-fee';
	const SAVE_ACTION = 'woo_cover_fee_save';
	const NONCE       = 'woo_cover_fee_settings';

	public function init() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . WOO_COVER_FEE_BASENAME, array( $this, 'action_links' ) );
	}

	/**
	 * Shared guard for the settings write.
	 *
	 * Unlike the checkout endpoints this one is authenticated, so it gets
	 * both halves: nonce and capability.
	 */
	private function guard() {
		check_admin_referer( self::NONCE );

		if ( ! current_user_can( self::CAP ) ) {
			wp_die(
				esc_html__( 'You do not have permission to change these settings.', 'woo-donation-cover-processing-fee' ),
				esc_html__( 'Unauthorized', 'woo-donation-cover-processing-fee' ),
				array( 'response' => 403 )
			);
		}
	}

	public function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Donation & Processing Fee', 'woo-donation-cover-processing-fee' ),
			__( 'Donation & Fee', 'woo-donation-cover-processing-fee' ),
			self::CAP,
			self::SLUG,
			array( $this, 'render_page' )
		);
	}

	public function action_links( $links ) {
		$url = admin_url( 'admin.php?page=' . self::SLUG );
		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'woo-donation-cover-processing-fee' ) . '</a>'
		);
		return $links;
	}

	public function enqueue_assets( $hook ) {
		if ( 'woocommerce_page_' . self::SLUG !== $hook ) return;

		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_style(
			'woo-cover-fee-admin',
			WOO_COVER_FEE_URL . 'assets/css/admin.css',
			array( 'wp-color-picker' ),
			WOO_DONATION_COVER_FEE_VERSION
		);

		wp_enqueue_script(
			'woo-cover-fee-admin',
			WOO_COVER_FEE_URL . 'assets/js/admin.js',
			array( 'jquery', 'wp-color-picker' ),
			WOO_DONATION_COVER_FEE_VERSION,
			true
		);

		wp_localize_script( 'woo-cover-fee-admin', 'wooCoverFeeAdmin', array(
			'removeLabel' => __( 'Remove amount', 'woo-donation-cover-processing-fee' ),
			'currency'    => html_entity_decode( get_woocommerce_currency_symbol() ),
		) );
	}

	/* ---------------------------------------------------------------------
	 * Save
	 * ------------------------------------------------------------------ */

	public function handle_save() {
		$this->guard();

		// Passed through raw — sanitize() unslashes and validates per field.
		$raw = ( isset( $_POST['wcf'] ) && is_array( $_POST['wcf'] ) ) ? $_POST['wcf'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		$clean = WOO_Cover_Fee_Settings::sanitize( $raw );

		update_option( WOO_Cover_Fee_Settings::OPTION, $clean );

		wp_safe_redirect( add_query_arg(
			array( 'page' => self::SLUG, 'updated' => 'true' ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * View
	 * ------------------------------------------------------------------ */

	public function render_page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'woo-donation-cover-processing-fee' ) );
		}

		$s        = WOO_Cover_Fee_Settings::get();
		$amounts  = WOO_Cover_Fee_Settings::amounts();
		$currency = html_entity_decode( get_woocommerce_currency_symbol() );

		// Worked example so the gross-up is legible rather than theoretical.
		$example_base = 100.0;
		$example_fee  = WOO_Cover_Fee_Settings::processing_fee( $example_base, $s['rate'], $s['flat'] );
		?>
		<div class="wrap wcf-admin">

			<div class="wcf-page-header">
				<h1><?php esc_html_e( 'Donation & Processing Fee', 'woo-donation-cover-processing-fee' ); ?></h1>
			</div>

			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Settings saved.', 'woo-donation-cover-processing-fee' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>" />
				<?php wp_nonce_field( self::NONCE ); ?>

				<!-- ------------------------------------------------ Donation -->
				<div class="wcf-card">
					<div class="wcf-card-header">
						<h2><?php esc_html_e( 'Donation amounts', 'woo-donation-cover-processing-fee' ); ?></h2>
					</div>
					<div class="wcf-card-body">

						<div class="wcf-field">
							<label class="wcf-checkbox">
								<input type="checkbox" name="wcf[donation_enabled]" value="1"
									<?php checked( $s['donation_enabled'] ); ?> />
								<?php esc_html_e( 'Show donation amounts at checkout', 'woo-donation-cover-processing-fee' ); ?>
							</label>
							<p class="wcf-help">
								<?php esc_html_e( 'Turning this off hides the amounts and removes any donation already chosen. The processing-fee checkbox is unaffected.', 'woo-donation-cover-processing-fee' ); ?>
							</p>
						</div>

						<div class="wcf-field">
							<label for="wcf-heading"><?php esc_html_e( 'Donation heading', 'woo-donation-cover-processing-fee' ); ?></label>
							<input type="text" id="wcf-heading" name="wcf[heading]"
								value="<?php echo esc_attr( $s['heading'] ); ?>" />
							<p class="wcf-help"><?php esc_html_e( 'Shown as a heading directly above the amount buttons.', 'woo-donation-cover-processing-fee' ); ?></p>
						</div>

						<div class="wcf-field">
							<label><?php esc_html_e( 'Preset amounts', 'woo-donation-cover-processing-fee' ); ?></label>
							<p class="wcf-help">
								<?php esc_html_e( 'Shown as buttons at checkout. Clicking one applies it immediately; clicking it again removes it.', 'woo-donation-cover-processing-fee' ); ?>
							</p>

							<div class="wcf-repeater" id="wcf-amounts">
								<?php foreach ( $amounts as $amount ) : ?>
									<div class="wcf-repeater-row">
										<span class="wcf-currency"><?php echo esc_html( $currency ); ?></span>
										<input type="number" step="0.01" min="0"
											name="wcf[amounts][]"
											value="<?php echo esc_attr( $amount ); ?>" />
										<button type="button" class="wcf-remove-row"
											aria-label="<?php esc_attr_e( 'Remove amount', 'woo-donation-cover-processing-fee' ); ?>">&times;</button>
									</div>
								<?php endforeach; ?>
							</div>

							<button type="button" class="button wcf-add-row" id="wcf-add-amount">
								<span aria-hidden="true">+</span> <?php esc_html_e( 'Add amount', 'woo-donation-cover-processing-fee' ); ?>
							</button>
						</div>

						<div class="wcf-field">
							<label class="wcf-checkbox">
								<input type="checkbox" name="wcf[other_enabled]" value="1"
									<?php checked( $s['other_enabled'] ); ?> />
								<?php esc_html_e( 'Show an "Other" button for custom amounts', 'woo-donation-cover-processing-fee' ); ?>
							</label>
						</div>

						<div class="wcf-field-row">
							<div class="wcf-field">
								<label for="wcf-other-min"><?php esc_html_e( 'Minimum custom amount', 'woo-donation-cover-processing-fee' ); ?></label>
								<input type="number" step="0.01" min="0" id="wcf-other-min"
									name="wcf[other_min]" value="<?php echo esc_attr( $s['other_min'] ); ?>" />
							</div>
							<div class="wcf-field">
								<label for="wcf-other-max"><?php esc_html_e( 'Maximum custom amount', 'woo-donation-cover-processing-fee' ); ?></label>
								<input type="number" step="0.01" min="0" id="wcf-other-max"
									name="wcf[other_max]" value="<?php echo esc_attr( $s['other_max'] ); ?>" />
							</div>
						</div>

						<div class="wcf-field-row">
							<div class="wcf-field">
								<label for="wcf-other-label"><?php esc_html_e( '"Other" button text', 'woo-donation-cover-processing-fee' ); ?></label>
								<input type="text" id="wcf-other-label" name="wcf[other_label]"
									value="<?php echo esc_attr( $s['other_label'] ); ?>" />
							</div>
							<div class="wcf-field">
								<label for="wcf-apply-label"><?php esc_html_e( 'Apply button text', 'woo-donation-cover-processing-fee' ); ?></label>
								<input type="text" id="wcf-apply-label" name="wcf[apply_label]"
									value="<?php echo esc_attr( $s['apply_label'] ); ?>" />
							</div>
						</div>

						<div class="wcf-field">
							<label for="wcf-suppress-cats"><?php esc_html_e( 'Hide donations for these product categories', 'woo-donation-cover-processing-fee' ); ?></label>
							<?php
							$selected   = WOO_Cover_Fee_Settings::suppress_cats();
							$categories = get_terms( array(
								'taxonomy'   => 'product_cat',
								'hide_empty' => false,
							) );
							?>
							<?php if ( is_wp_error( $categories ) || empty( $categories ) ) : ?>
								<p class="wcf-help"><?php esc_html_e( 'No product categories found.', 'woo-donation-cover-processing-fee' ); ?></p>
							<?php else : ?>
								<select id="wcf-suppress-cats" name="wcf[suppress_cats][]" multiple size="8" class="wcf-multiselect">
									<?php foreach ( $categories as $cat ) : ?>
										<option value="<?php echo esc_attr( $cat->term_id ); ?>"
											<?php selected( in_array( (int) $cat->term_id, $selected, true ) ); ?>>
											<?php echo esc_html( $cat->name ); ?>
											(<?php echo esc_html( $cat->count ); ?>)
										</option>
									<?php endforeach; ?>
								</select>
								<p class="wcf-help">
									<?php esc_html_e( 'If the cart contains a product in any selected category, the donation amounts are hidden and any donation already chosen is removed from the order. The processing-fee checkbox still shows. Hold Cmd/Ctrl to select more than one, or to deselect all.', 'woo-donation-cover-processing-fee' ); ?>
								</p>
							<?php endif; ?>
						</div>

						<div class="wcf-field">
							<label for="wcf-donation-label"><?php esc_html_e( 'Order line label', 'woo-donation-cover-processing-fee' ); ?></label>
							<input type="text" id="wcf-donation-label" name="wcf[donation_label]"
								value="<?php echo esc_attr( $s['donation_label'] ); ?>" />
							<p class="wcf-help"><?php esc_html_e( 'How the donation appears on the order totals and receipt.', 'woo-donation-cover-processing-fee' ); ?></p>
						</div>

					</div>
				</div>

				<!-- ------------------------------------------- Processing fee -->
				<div class="wcf-card">
					<div class="wcf-card-header">
						<h2><?php esc_html_e( 'Processing fee', 'woo-donation-cover-processing-fee' ); ?></h2>
					</div>
					<div class="wcf-card-body">

						<div class="wcf-field">
							<label class="wcf-checkbox">
								<input type="checkbox" name="wcf[fee_enabled]" value="1"
									<?php checked( $s['fee_enabled'] ); ?> />
								<?php esc_html_e( 'Offer customers the option to cover processing', 'woo-donation-cover-processing-fee' ); ?>
							</label>
						</div>

						<div class="wcf-field-row">
							<div class="wcf-field">
								<label for="wcf-rate"><?php esc_html_e( 'Percentage', 'woo-donation-cover-processing-fee' ); ?></label>
								<div class="wcf-input-affix">
									<input type="number" step="0.001" min="0" max="<?php echo esc_attr( WOO_Cover_Fee_Settings::MAX_RATE ); ?>"
										id="wcf-rate" name="wcf[rate]" value="<?php echo esc_attr( $s['rate'] ); ?>" />
									<span class="wcf-affix">%</span>
								</div>
							</div>
							<div class="wcf-field">
								<label for="wcf-flat"><?php esc_html_e( 'Flat amount', 'woo-donation-cover-processing-fee' ); ?></label>
								<div class="wcf-input-affix">
									<span class="wcf-affix wcf-affix-prefix"><?php echo esc_html( $currency ); ?></span>
									<input type="number" step="0.01" min="0"
										id="wcf-flat" name="wcf[flat]" value="<?php echo esc_attr( $s['flat'] ); ?>" />
								</div>
							</div>
						</div>

						<div class="wcf-callout">
							<strong><?php esc_html_e( 'How this is calculated', 'woo-donation-cover-processing-fee' ); ?></strong>
							<p>
								<?php
								printf(
									/* translators: 1: base amount, 2: calculated fee, 3: resulting total */
									esc_html__( 'The fee is grossed up so the full amount reaches you after the processor takes its cut of the larger total. On a %1$s order the customer is charged %2$s extra, for a total of %3$s.', 'woo-donation-cover-processing-fee' ),
									'<strong>' . wp_kses_post( wc_price( $example_base ) ) . '</strong>',
									'<strong>' . wp_kses_post( wc_price( $example_fee ) ) . '</strong>',
									'<strong>' . wp_kses_post( wc_price( $example_base + $example_fee ) ) . '</strong>'
								);
								?>
							</p>
							<p><?php esc_html_e( 'The donation is included in the amount being covered, so a covered gift arrives whole.', 'woo-donation-cover-processing-fee' ); ?></p>
						</div>

						<div class="wcf-field">
							<label for="wcf-fee-message"><?php esc_html_e( 'Checkbox message', 'woo-donation-cover-processing-fee' ); ?></label>
							<textarea id="wcf-fee-message" name="wcf[fee_message]" rows="3"><?php
								echo esc_textarea( $s['fee_message'] );
							?></textarea>
							<p class="wcf-help">
								<?php esc_html_e( 'Shown next to the checkbox at checkout. Basic HTML and links are allowed.', 'woo-donation-cover-processing-fee' ); ?>
							</p>
						</div>

						<div class="wcf-field">
							<label for="wcf-fee-label"><?php esc_html_e( 'Order line label', 'woo-donation-cover-processing-fee' ); ?></label>
							<input type="text" id="wcf-fee-label" name="wcf[fee_label]"
								value="<?php echo esc_attr( $s['fee_label'] ); ?>" />
						</div>

					</div>
				</div>

				<!-- ------------------------------------------------- Colors -->
				<div class="wcf-card">
					<div class="wcf-card-header">
						<h2><?php esc_html_e( 'Colors', 'woo-donation-cover-processing-fee' ); ?></h2>
					</div>
					<div class="wcf-card-body">
						<div class="wcf-field-row wcf-field-row-4">
							<?php
							$colors = array(
								'color_accent'      => __( 'Amount border & text', 'woo-donation-cover-processing-fee' ),
								'color_selected_bg' => __( 'Selected amount fill', 'woo-donation-cover-processing-fee' ),
								'color_button_bg'   => __( 'Apply button fill', 'woo-donation-cover-processing-fee' ),
								'color_button_text' => __( 'Button & selected text', 'woo-donation-cover-processing-fee' ),
							);
							foreach ( $colors as $key => $label ) :
								?>
								<div class="wcf-field">
									<label for="wcf-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
									<input type="text" class="wcf-color"
										id="wcf-<?php echo esc_attr( $key ); ?>"
										name="wcf[<?php echo esc_attr( $key ); ?>]"
										value="<?php echo esc_attr( $s[ $key ] ); ?>"
										data-default-color="<?php echo esc_attr( WOO_Cover_Fee_Settings::defaults()[ $key ] ); ?>" />
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				</div>

				<p class="wcf-actions">
					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'Save changes', 'woo-donation-cover-processing-fee' ); ?>
					</button>
				</p>
			</form>
		</div>
		<?php
	}
}
