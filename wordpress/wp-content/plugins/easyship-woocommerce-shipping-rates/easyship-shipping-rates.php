<?php
/**
 * Plugin Name: Easyship
 * Plugin URI: https://wordpress.org/plugins/easyship-woocommerce-shipping-rates/
 * Description: Easyship plugin for easy shipping method
 * Version: 0.9.9
 * Author: Easyship
 * Author URI: https://www.easyship.com
 * Developer: Sunny Cheung, Holubiatnikova Anna, Aloha Chen, Paul Lugagne Delpon, Bernie Chiu, Carlos Longarela
 * Reviewer: Carlos Longarela <carlos@longarela.eu>
 * Reviewer URI: https://tabernawp.com/
 * Text Domain: easyship-shipping-rates
 * Domain Path: /languages
 * Tested up to: 6.3.2
 * Requires PHP: 5.6
 *
 * Woo: 18734002514901:3283d162c5fe7c7b6fc417d60f203768
 * WC requires at least: 2.4.0
 * WC tested up to: 8.2.1
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package Easyship
 * @version 0.9.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EASYSHIP_VERSION', '0.9.9' );
define( 'EASYSHIP_PATH', plugin_dir_path( __FILE__ ) );
define( 'EASYSHIP_URL', plugin_dir_url( __FILE__ ) );
define( 'EASYSHIP_BASENAME', plugin_basename( __FILE__ ) );
define( 'EASYSHIP_DIR_BASENAME', dirname( plugin_basename( __FILE__ ) ) );

/**
 * Show a notice if WooCommerce is not active.
 */
function easyship_missing_notice() {
	// translators: %s: WooCommerce plugin URL.
	echo '<div class="error"><p><strong>' . sprintf( esc_html__( 'Easyship requires WooCommerce to be installed and active. You can download %s here.', 'easyship-shipping-rates' ), '<a href="https://wordpress.org/plugins/woocommerce/" target="_blank">WooCommerce</a>' ) . '</strong></p></div>';
}

/**
 * Load plugin textdomain.
 */
function easyship_l10n() {
	load_plugin_textdomain( 'easyship-shipping-rates', false, EASYSHIP_DIR_BASENAME . '/languages' );
}
add_action( 'plugins_loaded', 'easyship_l10n' );

/**
 * Declare compatibility with HPOS WooCommerce.
 */
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

/**
 * Initialize the plugin.
 */
function initialize_easyship() {
	// Initialize the plugin only if WooCommerce is active.
	if ( class_exists( 'woocommerce' ) ) {
		if ( ! class_exists( 'Easyship_Registration' ) ) {
			include_once EASYSHIP_PATH . 'includes/class-easyship-registration.php';
		}

		if ( ! class_exists( 'Easyship_Endpoints' ) ) {
			include_once EASYSHIP_PATH . 'includes/class-easyship-endpoints.php';
		}

		if ( ! class_exists( 'WC_Integration_Easyship' ) ) {
			include_once EASYSHIP_PATH . 'includes/class-wc-integration-easyship.php';
		}

		$wc_integration_easyship = new WC_Integration_Easyship();
	} else {
		add_action( 'admin_notices', 'easyship_missing_notice' );
	}
}
add_action( 'init', 'initialize_easyship' );
