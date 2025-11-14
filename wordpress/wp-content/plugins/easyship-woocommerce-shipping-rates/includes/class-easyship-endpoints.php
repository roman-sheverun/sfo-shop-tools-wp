<?php
/**
 * Easyship Endpoints.
 *
 * @package WooCommerce_Easyship/Classes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Easyship Endpoints class.
 */
class Easyship_Endpoints {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->rest_api_init();
	}

	/**
	 * Init REST API.
	 */
	private function rest_api_init() {
		// REST API was included starting WordPress 4.4.
		if ( ! class_exists( 'WP_REST_Server' ) ) {
			return;
		}

		$this->rest_api_includes();

		// Init REST API routes.
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ), 10 );
	}

	/**
	 * Include required files.
	 */
	private function rest_api_includes() {
		include_once EASYSHIP_PATH . 'includes/api/v1/class-easyship-rest-token-v1-controller.php';
	}

	/**
	 * Register REST API routes.
	 */
	public function register_rest_routes() {
		$controller = new Easyship_REST_Token_V1_Controller();
		$controller->register_routes();
	}
}
