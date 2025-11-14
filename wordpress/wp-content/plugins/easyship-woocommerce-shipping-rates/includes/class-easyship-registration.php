<?php
/**
 * Easyship Registration.
 *
 * @package WooCommerce_Easyship/Classes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Easyship Registration class.
 */
class Easyship_Registration {
	/**
	 * WPDB instance.
	 *
	 * @var wpdb
	 */
	private $wpdb;

	/**
	 * WooCommerce instance.
	 *
	 * @var WooCommerce
	 */
	private $woocommerce;

	/**
	 * Endpoint.
	 *
	 * @var string
	 */
	protected $endpoint = 'https://api.easyship.com/api/v1/woo_commerce_group/registrations';

	/**
	 * Easyship Description.
	 *
	 * @var string
	 */
	protected $description = 'Easyship Integration';

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		global $woocommerce;

		$this->wpdb        = $wpdb;
		$this->woocommerce = $woocommerce;
	}

	/**
	 * Send request to Easyship.
	 *
	 * @return string
	 */
	public function send_request() {
		$request            = array();
		$request['oauth']   = $this->get_oauth_info();
		$request['user']    = $this->get_user_info();
		$request['company'] = $this->get_company_info();
		$request['store']   = $this->get_store_info();
		$request['address'] = $this->get_address_info();

		$curl = curl_init( $this->endpoint ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init
		curl_setopt( $curl, CURLOPT_POST, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
		curl_setopt( $curl, CURLOPT_POSTFIELDS, wp_json_encode( $request ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
		curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
		curl_setopt( $curl, CURLOPT_HTTPHEADER, array( 'Content-Type:application/json', 'Cache-Control: no-cache' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
		$response   = curl_exec( $curl ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_exec
		$curl_errno = curl_errno( $curl ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_errno
		$curl_error = curl_error( $curl ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_error
		curl_close( $curl ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close
		header( 'Content-Type: application/json' );

		try {
			$raw_response = $response;
			$response     = json_decode( $response, true );
			if ( is_null( $response ) ) {
				return array(
					'error'      => esc_html__( 'Service temporarily unavailable', 'easyship-shipping-rates' ),
					'curl_errno' => $curl_errno,
					'curl_error' => $curl_error,
					'response'   => $raw_response,
				);
			}
			return $response;
		} catch ( \Exception $exception ) {
			return array( 'error' => $exception->getMessage() );
		}
	}

	/**
	 * Get OAuth info.
	 *
	 * @return array
	 */
	protected function get_oauth_info() {
		return $this->create_api_keys();
	}

	/**
	 * Create API keys.
	 *
	 * @return array
	 */
	protected function create_api_keys() {
		// Check if the user has admin capabilities.
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		$wpdb = $this->wpdb;

		$consumer_key    = 'ck_' . wc_rand_hash();
		$consumer_secret = 'cs_' . wc_rand_hash();

		$data = array(
			'user_id'         => get_current_user_id(),
			'description'     => $this->description,
			'permissions'     => 'read_write',
			'consumer_key'    => wc_api_hash( $consumer_key ),
			'consumer_secret' => $consumer_secret,
			'truncated_key'   => substr( $consumer_key, -7 ),
		);

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}woocommerce_api_keys WHERE description = %s", $this->description ) );

		$table = $wpdb->prefix . 'woocommerce_api_keys';
		$wpdb->insert(
			$table,
			$data,
			array(
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
			)
		);

		return array(
			'consumer_key'    => $consumer_key,
			'consumer_secret' => $consumer_secret,
		);
	}

	/**
	 * Get API keys.
	 *
	 * @return array
	 */
	protected function get_api_keys() {
		$wpdb = $this->wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}woocommerce_api_keys WHERE description = %s LIMIT 1", $this->description
			)
		);
	}

	/**
	 * Get user info.
	 *
	 * @return array
	 */
	protected function get_user_info() {
		$user = wp_get_current_user();

		$response['email']        = $user->user_email;
		$response['first_name']   = 'test'; // TODO: Check this in the previous commented code. //NOSONAR.
		$response['last_name']    = 'test'; // TODO: Check this in the previous commented code. //NOSONAR.
		$response['mobile_phone'] = ! empty( $user->billing_phone ) ? $user->billing_phone : '';

		return $response;
	}

	/**
	 * Get company info.
	 *
	 * @return array
	 */
	protected function get_company_info() {
		$response = array();
		$country  = explode( ':', get_option( 'woocommerce_default_country' ) );

		$response['name']         = get_option( 'blogname' );
		$response['country_code'] = ! empty( $country[0] ) ? $country[0] : '';

		return $response;
	}

	/**
	 * Get store info.
	 *
	 * @return array
	 */
	protected function get_store_info() {
		$response                      = array();
		$response['platform_store_id'] = get_current_network_id();
		$response['name']              = get_option( 'blogname' );
		$response['url']               = get_option( 'home' );
		$response['wc_version']        = $this->woocommerce->version;

		return $response;
	}

	/**
	 * Get address info.
	 *
	 * @return array
	 */
	protected function get_address_info() {
		$response    = array();
		$country     = explode( ':', get_option( 'woocommerce_default_country' ) );
		$city        = get_option( 'woocommerce_store_city' );
		$postal_code = get_option( 'woocommerce_store_postcode' );
		$line_1      = get_option( 'woocommerce_store_address' );
		$line_2      = get_option( 'woocommerce_store_address_2' );

		$response['state']       = ! empty( $country[1] ) ? $country[1] : '';
		$response['city']        = ! empty( $city ) ? $city : '';
		$response['postal_code'] = ! empty( $postal_code ) ? $postal_code : '';
		$response['line_1']      = ! empty( $line_1 ) ? $line_1 : '';
		$response['line_2']      = ! empty( $line_2 ) ? $line_2 : '';

		return $response;
	}
}
