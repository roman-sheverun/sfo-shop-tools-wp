<?php
/**
 * Easyship primary class.
 *
 * @package Easyship
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 *  Easyship Integration Class.
 */
class WC_Integration_Easyship {
	/**
	 * Easyship endpoints.
	 *
	 * @var $endpoints Easyship_Endpoints
	 */
	protected $endpoints;

	/**
	 * Construct the plugin.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_session' ) );
		add_action( 'woocommerce_shipping_init', array( $this, 'init' ) );
		add_filter( 'plugin_action_links_' . EASYSHIP_BASENAME, array( $this, 'plugin_action_links' ) );
		add_action( 'wp_ajax_oauth_es', array( $this, 'oauth_es_callback' ) );
		add_action( 'wp_ajax_es_disabled', array( $this, 'es_disabled_callback' ) );

		$this->endpoints = new Easyship_Endpoints();
	}

	/**
	 * Initialize the plugin.
	 */
	public function init() {
		// Start a session.

		add_filter( 'woocommerce_shipping_methods', array( $this, 'add_shipping_method' ) );

		// Checks if WooCommerce is installed.
		if ( class_exists( 'WC_Integration' ) ) {
			// Include our integration class.
			if ( ! class_exists( 'Easyship_Shipping_Method' ) ) {
				include_once EASYSHIP_PATH . 'includes/class-easyship-shipping-method.php';
			}

			// Register the integration.
			add_filter( 'woocommerce_shipping_methods', array( $this, 'add_integration' ) );
		}
	}

	/**
	 * Add a new integration to WooCommerce.
	 *
	 * @param  array $integrations WooCommerce integrations.
	 *
	 * @return array
	 */
	public function add_integration( $integrations ) {
		$integrations[] = 'Easyship_Shipping_Method';

		return $integrations;
	}

	/**
	 *  Register a session
	 */
	public function register_session() {
		if ( session_status() === PHP_SESSION_NONE && ! headers_sent() ) {
			session_start();
		}

		session_write_close();
	}

	/**
	 * Add Settings link to plugin page
	 *
	 * @param array $links Plugin links.
	 *
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		return array_merge(
			$links,
			array( '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=shipping&section=easyship' ) . '"> ' . esc_html__( 'Settings', 'easyship-shipping-rates' ) . '</a>' )
		);
	}

	/**
	 * Add a new shipping method to WooCommerce.
	 *
	 * @param  array $methods WooCommerce shipping methods.
	 *
	 * @return array
	 */
	public function add_shipping_method( $methods ) {
		if ( is_array( $methods ) ) {
			$methods['easyship'] = 'Easyship_Shipping_Method';
		}

		return $methods;
	}

	/**
	 * Oauth callback.
	 *
	 * @return void
	 */
	public function oauth_es_callback() {
		// Verify nonce.
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'oauth_action_button_es_nonce' ) ) {
			wp_send_json_error( 'Nonce verification failed.' );
		}

		$obj = new Easyship_Registration();

		$res = $obj->send_request();

		wp_send_json( $res );
	}

	/**
	 * Disable Easyship.
	 *
	 * @return void
	 */
	public function es_disabled_callback() {
		// Verify nonce.
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'oauth_action_button_es_nonce' ) ) {
			wp_send_json_error( 'Nonce verification failed.' );
		}

		// Check if the user has admin capabilities.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'User without permissions.' );
		}

		$option_name = 'es_access_token_' . get_current_network_id();

		update_option( $option_name, '' );

		wp_send_json( 'Success' );
	}
}
