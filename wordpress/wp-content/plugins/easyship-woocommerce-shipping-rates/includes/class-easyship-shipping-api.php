<?php
/**
 * Easyship API.
 *
 * @package WooCommerce_Easyship/Classes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Easyship API class.
 */
class Easyship_Shipping_API {
	/**
	 * API Key.
	 *
	 * @var string
	 */
	private static $apikey = '';

	/**
	 * API Secret.
	 *
	 * @var string
	 */
	private static $api_secret = '';

	/**
	 * Access Token.
	 *
	 * @var string
	 */
	private static $access_token = '';

	/**
	 * Is insured.
	 *
	 * @var bool
	 */
	private static $is_insured = false;

	/**
	 * Taxes and duties paid by.
	 *
	 * @var string
	 */
	private static $taxes_duties_paid_by = 'Sender';

	/**
	 * OAuth URL.
	 *
	 * @var string
	 */
	private static $oauth_url = 'https://auth.easyship.com/oauth2/token';

	/**
	 * API URL.
	 *
	 * @var string
	 */
	private static $api_url = 'https://api.easyship.com/rate/v1/woocommerce';

	/**
	 * Currency.
	 *
	 * @var string
	 * @since 0.2.7
	 */
	private static $currency;

	/**
	 * Init.
	 *
	 * @param string $token Access token.
	 * @throws Exception If API Key or API Secret is empty.
	 */
	public static function init( $token = null ) {
		$easyship_shipping_method = new Easyship_Shipping_Method();

		self::$apikey     = isset( $easyship_shipping_method->settings['es_api_key'] ) ? trim( esc_attr( $easyship_shipping_method->settings['es_api_key'] ), ' ' ) : '' ;
		self::$api_secret = isset( $easyship_shipping_method->settings['es_api_secret'] ) ? str_replace( '\n', "\n", $easyship_shipping_method->settings['es_api_secret'] ) : '';

		// If incoterms/insurance already exist on WC, send it with API.
		self::$is_insured           = 0;
		self::$taxes_duties_paid_by = isset( $easyship_shipping_method->settings['es_taxes_duties'] ) ? $easyship_shipping_method->settings['es_taxes_duties'] : 'Sender';
		self::$currency             = get_woocommerce_currency();

		// Feature/access_token.
		if ( ! is_null( $token ) ) {
			self::$access_token = trim( esc_attr( $token ), ' ' );
		} else {
			$token_option_name  = 'es_access_token_' . get_current_network_id();
			self::$access_token = trim( esc_attr( get_option( $token_option_name ) ), ' ' );
		}

		if ( ( empty( self::$apikey ) || empty( self::$api_secret ) ) && empty( self::$access_token ) ) {
			throw new Exception( esc_html__( 'Missing API Key and API Secret OR Access Token!', 'easyship-shipping-rates' ) );
		}
	}

	/**
	 * Get Auth info.
	 *
	 * @throws Exception If API Key or API Secret is empty.
	 */
	protected static function auth() {
		$now = time();

		$access_token = isset( $_SESSION['access_token'] ) ? sanitize_text_field( $_SESSION['access_token'] ) : '' ;
		$expires_in   = isset( $_SESSION['expires_in'] ) ? sanitize_text_field( $_SESSION['expires_in'] ) : 0 ;

		// If access token is found in session and not expired, will reuse the access token.
		if ( ! empty( $access_token ) && ! empty( $expires_in ) && ( intval( $expires_in ) - $now ) > 0 ) {
			self::$access_token = sanitize_text_field( $access_token );

			return;
		}

		$jwt_head           = rtrim( strtr( base64_encode( '{"typ":"JWT","alg":"RS256"}' ), '+/', '-_' ), '=' ); // phpcs:ignore
		$url                = self::$oauth_url;
		$jwt_claim_set_json = '{"iss":"' . self::$apikey . '","aud":"' . $url . '","scope":"rate","exp":' . ( $now + 3600 ) . ',"iat":' . $now . '}';
		$jwt_claim_set      = rtrim( strtr( base64_encode( $jwt_claim_set_json ), '+/', '-_' ), '=' ); // phpcs:ignore
		$private_key        = openssl_get_privatekey( self::$api_secret );

		if ( ! $private_key ) {
			throw new Exception( esc_html__( 'API Secret is incorrect', 'easyship-shipping-rates' ) );
		}

		$signature = '';

		openssl_sign( $jwt_head . '.' . $jwt_claim_set, $signature, $private_key, 'sha256' );

		$signature = rtrim( strtr( base64_encode( $signature ), '+/', '-_' ), '=' ); // phpcs:ignore
		$jwt_token = $jwt_head . '.' . $jwt_claim_set . '.' . $signature;

		/**
		 * API Document - request access token
		 * POST https://auth.easyship.com/oauth2/token
		 * {
		 *   "grant_type": "assertion",
		 *   "assertion": "YOUR_JWT",
		 *   "assertion_type": "urn:ietf:params:oauth:grant-type:jwt-bearer"
		 * }
		 */
		$request_array = array(
			'grant_type'     => 'assertion',
			'assertion'      => $jwt_token,
			'assertion_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
		);

		$response = wp_remote_post(
			$url,
			array(
				'headers' => array( 'content-type' => 'application/json' ),
				'body'    => wp_json_encode( $request_array ),
				'method'  => 'POST',
			)
		);

		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();
			throw new Exception( esc_html( $error_message ) );
		} elseif ( 200 === $response['response']['code'] ) {
			$body                     = json_decode( $response['body'], true );
			self::$access_token       = $body['access_token'];
			$_SESSION['access_token'] = self::$access_token;
			$_SESSION['expires_in']   = $body['created_at'] + $body['expires_in'];
		}
	}

	/**
	 * Get shipping rate.
	 *
	 * @param array $destination Destination.
	 * @param array $items       Items.
	 * @return array
	 * @throws Exception If API Key or API Secret is empty.
	 */
	public static function get_shipping_rate( $destination, $items ) {
		$url = self::$api_url;
		if ( empty( self::$access_token ) ) { // Access token is not set yet.
			try {
				self::auth();
			} catch ( Exception $e ) {
				// translators: %s: error message.
				$message      = sprintf( __( 'Error: %s', 'easyship-shipping-rates' ), $e->getMessage() );
				$message_type = 'error';

				wc_add_notice( $message, $message_type );

				// Error, but return empty array.
				return array();
			}
		}

		// @since 0.2.7
		// We want to get back shipping rate base on store setting.
		if ( defined( 'WCML_VERSION' ) ) {
			self::$currency = get_option( 'woocommerce_currency' );
		}

		// @since 0.4.2
		// Support WooCommerce Currency Switcher.
		if ( defined( 'WOOCS_VERSION' ) ) {
			global $WOOCS; // phpcs:ignore
			self::$currency = $WOOCS->current_currency; // phpcs:ignore
		}

		$request_array = array(
			'destination_country_alpha2' => $destination['country'],
			'destination_postal_code'    => ( empty( $destination['postcode'] ) ) ? 0 : $destination['postcode'],
			'destination_address_line_1' => $destination['address'],
			'destination_address_line_2' => $destination['address_2'],
			'destination_city'           => isset( $destination['city'] ) ? $destination['city'] : '',
			'destination_state'          => isset( $destination['state'] ) ? $destination['state'] : '',
			'taxes_duties_paid_by'       => self::$taxes_duties_paid_by,
			'is_insured'                 => self::$is_insured,
			'output_currency'            => self::$currency,
			'items'                      => $items,
		);

		$response = wp_remote_post(
			$url,
			array(
				'headers' => array(
					'content-type'  => 'application/json',
					'Authorization' => 'Bearer ' . self::$access_token,
					'X-Woocommerce' => 'woocommerce-easyship',
				),
				'body'    => wp_json_encode( $request_array ),
				'method'  => 'POST',
				'timeout' => 25,
			)
		);

		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();

			if ( 'fsocket timed out' === $error_message ) {
				throw new Exception( esc_html__( 'Sorry, the shipping rates are currently unavailable, please refresh the page or try again later', 'easyship-shipping-rates' ) );
			} else {
				throw new Exception( esc_html__( 'Sorry, something went wrong with the shipping rates. If the problem persists, please contact us!', 'easyship-shipping-rates' ) );
			}
		} elseif ( 200 === $response['response']['code'] ) {
			$body = json_decode( $response['body'], true );

			return $body['rates'];
		}

		// Should never reach here, but just in case.
		return array(); // Return empty array.
	}
}
