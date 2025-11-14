<?php
/**
 * Uninstall Easyship.
 *
 * NOTE: This file is intentionally not namespaced.
 * NOTE: Multisite not supported.
 *
 * @package WooCommerce_Easyship/Uninstaller
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	die();
}

// uninstall delete option.
delete_option( 'woocommerce_easyship_settings' );
