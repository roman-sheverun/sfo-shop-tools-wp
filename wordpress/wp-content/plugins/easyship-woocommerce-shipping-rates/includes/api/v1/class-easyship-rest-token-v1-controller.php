<?php
/**
 * Easyship REST API Token Controller.
 *
 * @package WooCommerce_Easyship/Classes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Easyship REST API Token controller class.
 *
 * @extends WC_REST_Controller
 */
class Easyship_REST_Token_V1_Controller extends WP_REST_Controller {

	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'easyship/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'token';

	/**
	 * Register the routes for tokens.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'createToken' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( true ),
				),
			)
		);
	}

	/**
	 * Check if a given request has access to create items
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_Error|bool
	 */
	public function create_item_permissions_check( $request ) {
		$check = $this->perform_oauth_authentication( $request->get_params() );

		return is_null( $check ) ? true : $check;
	}

	/**
	 * Perform OAuth authentication.
	 *
	 * @param array $params Request parameters.
	 *
	 * @return WP_Error|bool
	 */
	protected function perform_oauth_authentication( $params ) {
		$param_names = array(
			'oauth_consumer_key',
			'oauth_timestamp',
			'oauth_nonce',
			'oauth_signature',
			'oauth_signature_method',
		);

		// Check for required OAuth parameters.
		foreach ( $param_names as $param_name ) {
			if ( empty( $params[ $param_name ] ) ) {
				return new WP_Error(
					'woocommerce_rest_authentication_error',
					esc_html__( 'Invalid signature - failed to sort parameters.', 'easyship-shipping-rates' ),
					array( 'status' => 401 )
				);
			}
		}

		// Fetch WP user by consumer key.
		try {
			$keys = $this->get_keys_by_consumer_key( $params['oauth_consumer_key'] );
		} catch ( \Exception $exception ) {
			return new WP_Error(
				'woocommerce_rest_authentication_error',
				$exception->getMessage(),
				array( 'status' => 401 )
			);
		}

		// Perform OAuth validation.
		$this->check_oauth_signature( $keys, $params );
	}

	/**
	 * Get keys by consumer key.
	 *
	 * @param string $consumer_key Consumer key.
	 *
	 * @return array
	 * @throws Exception Invalid consumer key.
	 */
	protected function get_keys_by_consumer_key( $consumer_key ) {
		global $wpdb;

		$consumer_key = wc_api_hash( sanitize_text_field( $consumer_key ) );

		$keys = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT *
				FROM {$wpdb->prefix}woocommerce_api_keys
				WHERE consumer_key = %s
				LIMIT 1",
				$consumer_key
			),
			ARRAY_A
		);

		if ( empty( $keys ) ) {
			throw new Exception( esc_html__( 'Consumer key is invalid.', 'easyship-shipping-rates' ), 401 );
		}

		return $keys;
	}

	/**
	 * Check OAuth signature.
	 *
	 * @param array $keys   Keys.
	 * @param array $params Parameters.
	 *
	 * @return WP_Error|bool
	 */
	protected function check_oauth_signature( $keys, $params ) {
		unset( $params['store_id'] );
		unset( $params['token'] );

		if ( empty( $_SERVER['REQUEST_METHOD'] ) ) {
			$http_method = 'GET';
		} else {
			$http_method  = strtoupper( sanitize_text_field( $_SERVER['REQUEST_METHOD'] ) );
		}

		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			$request_path = '/';
		} else {
			$request_path = wp_parse_url( sanitize_text_field( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );
		}

		$wp_base      = get_home_url( null, '/', 'relative' );

		if ( substr( $request_path, 0, strlen( $wp_base ) ) === $wp_base ) {
			$request_path = substr( $request_path, strlen( $wp_base ) );
		}

		$base_request_uri = rawurlencode( get_home_url( null, $request_path, is_ssl() ? 'https' : 'http' ) );

		// Get the signature provided by the consumer and remove it from the parameters prior to checking the signature.
		$consumer_signature = rawurldecode( str_replace( ' ', '+', $params['oauth_signature'] ) );
		unset( $params['oauth_signature'] );

		// Sort parameters.
		if ( ! uksort( $params, 'strcmp' ) ) {
			return new WP_Error(
				'woocommerce_rest_authentication_error',
				esc_html__( 'Invalid signature - failed to sort parameters.', 'easyship-shipping-rates' ),
				array( 'status' => 401 )
			);
		}

		// Normalize parameter key/values.
		$params         = $this->normalize_parameters( $params );
		$query_string   = implode( '%26', $this->join_with_equals_sign( $params ) ); // Join with ampersand.
		$string_to_sign = $http_method . '&' . $base_request_uri . '&' . $query_string;

		if ( 'HMAC-SHA1' !== $params['oauth_signature_method'] && 'HMAC-SHA256' !== $params['oauth_signature_method'] ) {
			return new WP_Error(
				'woocommerce_rest_authentication_error',
				esc_html__( 'Invalid signature - signature method is invalid.', 'easyship-shipping-rates' ),
				array( 'status' => 401 )
			);
		}

		$hash_algorithm = strtolower( str_replace( 'HMAC-', '', $params['oauth_signature_method'] ) );
		$secret         = $keys['consumer_secret'] . '&';
		$signature      = base64_encode( hash_hmac( $hash_algorithm, $string_to_sign, $secret, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		if ( ! hash_equals( $signature, $consumer_signature ) ) {
			return new WP_Error(
				'woocommerce_rest_authentication_error',
				esc_html__( 'Invalid signature - provided signature does not match.', 'easyship-shipping-rates' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}


	/**
	 * Join parameters with equals sign.
	 *
	 * @param array  $params       Parameters to join.
	 * @param array  $query_params Query parameters.
	 * @param string $key          Key.
	 *
	 * @return array
	 */
	private function join_with_equals_sign( $params, $query_params = array(), $key = '' ) {
		foreach ( $params as $param_key => $param_value ) {
			if ( $key ) {
				$param_key = $key . '%5B' . $param_key . '%5D'; // Handle multi-dimensional array.
			}

			if ( is_array( $param_value ) ) {
				$query_params = $this->join_with_equals_sign( $param_value, $query_params, $param_key );
			} else {
				$string         = $param_key . '=' . $param_value; // Join with equals sign.
				$query_params[] = wc_rest_urlencode_rfc3986( $string );
			}
		}

		return $query_params;
	}

	/**
	 * Normalize parameters for OAuth.
	 *
	 * @param array $parameters Parameters to normalize.
	 *
	 * @return array
	 */
	protected function normalize_parameters( $parameters ) {
		$normalized_parameters = array();

		foreach ( $parameters as $key => $value ) {
			// Percent symbols (%) must be double-encoded.
			$key   = str_replace( '%', '%25', rawurlencode( rawurldecode( $key ) ) );
			$value = str_replace( '%', '%25', rawurlencode( rawurldecode( $value ) ) );

			$normalized_parameters[ $key ] = $value;
		}

		return $normalized_parameters;
	}

	/**
	 * Prepare the item for create or update operation
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_Error|object $prepared_item
	 */
	protected function prepare_item_for_database( $request ) {
		$item           = new StdClass();
		$item->token    = $request->get_param( 'token' );
		$item->store_id = $request->get_param( 'store_id' );

		if ( empty( $item->token ) ) {
			return new WP_Error(
				'easyship_bad_param',
				esc_html__( 'token is required field', 'easyship-shipping-rates' ),
				array( 'status' => 400 )
			);
		} elseif ( empty( $item->store_id ) ) {
			return new WP_Error(
				'easyship_bad_param',
				esc_html__( 'store_id is required field', 'easyship-shipping-rates' ),
				array( 'status' => 400 )
			);
		}

		return $item;
	}

	/**
	 * Create one item from the collection
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function createToken( $request ) {
		// Check if the user has admin capabilities.
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		$item        = $this->prepare_item_for_database( $request );
		$option_name = 'es_access_token_' . $item->store_id;
		try {
			if ( get_option( $option_name ) !== false ) {
				update_option( $option_name, $item->token );
			} else {
				add_option( $option_name, $item->token, '', 'yes' );
			}
		} catch ( Exception $exception ) {
			return new WP_Error(
				'easyship_internal_error',
				esc_html__( 'Something went wrong.', 'easyship-shipping-rates' ),
				array( 'status' => 500 )
			);
		}

		$response = new WP_REST_Response();
		$response->set_data( array( 'success' => true ) );

		return $response;
	}
}
