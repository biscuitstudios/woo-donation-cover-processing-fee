<?php
/**
 * Plugin Name:       Woo Checkout Donation + Fee
 * Plugin URI:        https://github.com/biscuitstudios/woo-donation-cover-processing-fee
 * Description:       Adds a donation selector and a voluntary "cover payment processing" checkbox to the checkout. The processing fee is grossed up so the full amount, including any donation, arrives whole. Classic (shortcode) checkout only.
 * Version:           2.3.0
 * Requires at least: 6.3
 * Requires PHP:      8.2
 * Requires Plugins:  woocommerce
 * Author:            Biscuit Studios
 * Author URI:        https://biscuitstudios.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       woo-donation-cover-processing-fee
 * Update URI:        https://github.com/biscuitstudios/woo-donation-cover-processing-fee
 */

if ( ! defined( 'ABSPATH' ) ) exit; // no direct access

/**
 * Sibling-plugin note.
 *
 * woo-cover-processing-fee and woo-donation-cover-processing-fee share the
 * WOO_COVER_FEE_* constant prefix and the WOO_Cover_Fee_Admin /
 * WOO_Cover_Fee_Settings class names. The class declarations are unguarded, so
 * activating BOTH on one site is a fatal error ("Cannot declare class ...").
 *
 * That is accepted by design: the two are variants of the same plugin and are
 * never intended to run together — pick one per site. Only
 * WOO_DONATION_COVER_FEE_VERSION was renamed apart, so the two build artifacts
 * can be told apart by version. If they ever DO need to coexist, both the
 * remaining constants and the class names must be namespaced first.
 */
define( 'WOO_DONATION_COVER_FEE_VERSION', '2.3.0' );
define( 'WOO_COVER_FEE_FILE', __FILE__ );
define( 'WOO_COVER_FEE_PATH', plugin_dir_path( __FILE__ ) );
define( 'WOO_COVER_FEE_URL', plugin_dir_url( __FILE__ ) );
define( 'WOO_COVER_FEE_BASENAME', plugin_basename( __FILE__ ) );

require_once WOO_COVER_FEE_PATH . 'includes/class-woo-cover-fee-settings.php';
require_once WOO_COVER_FEE_PATH . 'includes/class-woo-cover-fee-checkout.php';
require_once WOO_COVER_FEE_PATH . 'includes/class-woo-cover-fee-admin.php';

/**
 * HPOS compatibility. Order meta is written through the CRUD layer, so this
 * plugin works either way — but WooCommerce flags plugins that stay silent.
 */
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			WOO_COVER_FEE_FILE,
			true
		);
	}
} );

/**
 * Boot once WooCommerce is confirmed active.
 */
add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>' .
				esc_html__( 'Woo Checkout Donation + Fee requires WooCommerce to be active.', 'woo-donation-cover-processing-fee' ) .
				'</p></div>';
		} );
		return;
	}

	( new WOO_Cover_Fee_Checkout() )->init();

	if ( is_admin() ) {
		( new WOO_Cover_Fee_Admin() )->init();
	}
} );
