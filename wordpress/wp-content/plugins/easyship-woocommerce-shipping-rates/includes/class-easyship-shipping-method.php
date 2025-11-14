<?php
/**
 * Easyship Shipping Method.
 *
 * @package WooCommerce_Easyship/Classes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Easyship Shipping Method class.
 */
class Easyship_Shipping_Method extends WC_Shipping_Method {
	/**
	 * Discount for item.
	 *
	 * @var int
	 */
	protected $discount_for_item = 0;

	/**
	 * Control discount.
	 *
	 * @var int
	 */
	protected $control_discount = 0;

	/**
	 * Token.
	 *
	 * @var array
	 */
	protected $token;

	/**
	 * Shipping class.
	 *
	 * @var string
	 */
	protected $shipping_class;


	/**
	 * Constructor.
	 *
	 * @param int $instance_id Instance ID.
	 */
	public function __construct( $instance_id = 0 ) {
		$this->id           = 'easyship';
		$this->instance_id  = empty( $instance_id ) ? 99 : absint( $instance_id );
		$this->method_title = esc_html__( 'Easyship', 'easyship-shipping-rates' );

		$this->supports = array(
			'shipping-zones',
			'settings',
			'instance-settings',
			'instance-settings-modal',
		);

		$this->init();
		$this->enabled = isset( $this->settings['enabled'] ) ? $this->settings['enabled'] : 'yes';
		$this->title   = isset( $this->settings['title'] ) ? $this->settings['title'] : esc_html__( 'Easyship', 'easyship-shipping-rates' );

		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
	}


	/**
	 * Init settings.
	 *
	 * @return void
	 */
	public function init() {
		// Load the settings API.
		$this->init_form_fields();
		$this->init_settings();

		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'update_option_woocommerce_easyship_settings', array( $this, 'clear_session' ), 10, 2 );

		add_action( 'woocommerce_update_options_shipping_easyship', array( $this, 'save_options' ) );
	}

	/**
	 * Add settings tab.
	 *
	 * @param mixed $settings_tabs Settings tab.
	 *
	 * @return array
	 */
	public static function add_settings_tab( $settings_tabs ) {
		$settings_tabs['shipping&section=easyship'] = esc_html__( 'Easyship', 'easyship-shipping-rates' );

		return $settings_tabs;
	}

	/**
	 * Clear session.
	 *
	 * @param mixed $old_value Old value.
	 * @param mixed $new_value New value.
	 *
	 * @return void
	 */
	public function clear_session( $old_value, $new_value ) {
		$_SESSION['access_token'] = null;
	}

	/**
	 * Save options.
	 *
	 * @return void
	 */
	public function save_options() {
		// Check if the user has admin capabilities.
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		$option_key            = 'woocommerce_easyship_settings';
		$token_option          = $this->get_token()['name'];
		$shopping_class_option = 'woocommerce_easyship_skip_shipping_class';
		$value                 = get_option( $option_key );

		if ( ! empty( $value ) ) {
			$value = unserialize( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		} else {
			$value = array();
		}

		if ( isset( $_POST['easyship_nonce_field'] ) && wp_verify_nonce( sanitize_text_field( $_POST['easyship_nonce_field'] ), 'easyship_nonce' ) ) {
			if ( isset( $_POST[ $shopping_class_option ] ) ) {
				$value[ $shopping_class_option ] = sanitize_text_field( $_POST[ $shopping_class_option ] );
			}

			if ( isset( $_POST[ 'woocommerce_easyship_' . $token_option ] ) ) {
				update_option( $token_option, sanitize_text_field( $_POST[ 'woocommerce_easyship_' . $token_option ] ) );
				$value[ 'woocommerce_easyship_' . $token_option ] = sanitize_text_field( $_POST[ 'woocommerce_easyship_' . $token_option ] );
			}

			update_option( $option_key, serialize( $value ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		}
	}

	/**
	 * Notification when api key and secret is not set
	 *
	 * @return void
	 */
	public function easyship_admin_notice() {
		$token = 'es_access_token_' . get_current_network_id();

		if ( ( empty( $this->get_option( 'es_api_key' ) ) || empty( $this->get_option( 'es_api_secret' ) ) ) && ( empty( get_option( $token ) ) ) ) {
			echo '<div class="error">';
			esc_html_e( 'Please go to <strong>WooCommerce > Settings > Shipping > Easyship</strong> to add your API key and API Secret Or Access Token', 'easyship-shipping-rates' );
			echo '</div>';
		}
	}

	/**
	 * Define settings field for this shipping.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		/**
		 * Display access token field to new customer, if api_key or secret already set for current customer,
		 * then display api key and secret key information
		 * new customer
		 */
		if ( empty( $this->get_option( 'es_api_key' ) ) || empty( $this->get_option( 'es_api_secret' ) ) ) {
			$this->form_fields = array_merge(
				array(
					'skip_shipping_class' => array(
						'title'       => esc_html__( 'Skip Shipping Class Slug', 'easyship-shipping-rates' ),
						'type'        => 'text',
						'description' => esc_html__( 'Enter the shipping class slug for items which do not need to be shipped with Easyship. The slug can be found in "WooCommerce > Settings > Shipping > Shipping classes"', 'easyship-shipping-rates' ),
						'desc_tip'    => true,
						'default'     => $this->get_shipping_class(),
					),
				),
				$this->form_fields
			);

			$token        = $this->get_token();
			$token_fields = array();

			if ( empty( $token['value'] ) ) {
				$token_fields['es_oauth_ajax'] = array(
					'title'       => esc_html__( 'Enable Easyship', 'easyship-shipping-rates' ),
					'type'        => 'button',
					// translators: %s: Easyship URL link.
					'description' => sprintf( esc_html__( 'Click \'Enable\' will redirect you to Easyship to create an account for free, or connect to an existing Easyship account. If it doesn\'t work, don\'t worry, you can always create an account at %s to obtain your Access Token, and paste it below.', 'easyship-shipping-rates' ), '<a href="https://www.easyship.com" target="_blank">Easyship</a>' ),
					'default'     => 'Enable',
				);
			} else {
				$token_fields['es_ajax_disabled'] = array(
					'title'       => esc_html__( 'Disable Easyship', 'easyship-shipping-rates' ),
					'type'        => 'button',
					'description' => esc_html__( "Click 'Disable' will deactivate the dynamic shipping rates at checkout.", 'easyship-shipping-rates' ),
					'default'     => 'Disable',
				);
			}

			$token_fields[ $token['name'] ] = array(
				'title'       => esc_html__( 'Easyship Access Token', 'easyship-shipping-rates' ),
				'type'        => 'text',
				'description' => esc_html__( 'Enter your Easyship Access Token. To retrieve it, connect to the Easyship dashboard and go to "Connect > Add New" to connect your WooCommerce store. You can then retrieve your Access Token from your store\'s page by clicking on "Activate Rates". This is also the place where you will be able to set all your shipping options and rules.', 'easyship-shipping-rates' ),
				'desc_tip'    => true,
				'default'     => $token['value'],
			);

			$this->form_fields = array_merge( $token_fields, $this->form_fields );
		} else {
			$this->form_fields = array_merge(
				array(
					'enabled'       => array(
						'title'       => esc_html__( 'Enable', 'easyship-shipping-rates' ),
						'type'        => 'checkbox',
						'description' => esc_html__( 'Enable Easyship Rates. If unchecked, no rates will be shown at checkout.', 'easyship-shipping-rates' ),
						'default'     => 'yes',
					),
					'es_api_key'    => array(
						'title'       => esc_html__( 'API Key', 'easyship-shipping-rates' ),
						'type'        => 'text',
						'description' => esc_html__( 'Enter your Easyship API Key. ', 'easyship-shipping-rates' ),
						'desc_tip'    => true,
						'default'     => '',
					),
					'es_api_secret' => array(
						'title'       => esc_html__( 'API Secret', 'easyship-shipping-rates' ),
						'type'        => 'textarea',
						'description' => esc_html__( 'Enter your Easyship API Secret. ', 'easyship-shipping-rates' ),
						'desc_tip'    => true,
						'default'     => '',
					),
				),
				$this->form_fields
			);
		}
		$this->oauth_action_button_es();

		add_action( 'admin_enqueue_scripts', array( $this, 'oauth_action_button_es' ) );
	}

	/**
	 * Generate button html.
	 *
	 * @param mixed $key Key.
	 * @param mixed $data Data.
	 * @return string
	 */
	public function generate_text_html( $key, $data ) {
		$field_key = $this->get_field_key( $key );
		$defaults  = array(
			'title'             => '',
			'disabled'          => false,
			'class'             => '',
			'css'               => '',
			'placeholder'       => '',
			'type'              => 'text',
			'desc_tip'          => false,
			'description'       => '',
			'custom_attributes' => array(),
		);
		$data      = wp_parse_args( $data, $defaults );

		if ( isset( $data['type'] ) && ( 'button' === $data['type'] ) ) {
			$value = isset( $data['default'] ) ? $data['default'] : '';
		} else {
			$value = esc_attr( $this->get_option( $key ) );
		}

		ob_start();
		$easyship_nonce = wp_create_nonce( 'easyship_nonce' );
		?>
		<tr>
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?> <?php echo wp_kses_post( $this->get_tooltip_html( $data ) ); ?></label>
			</th>
			<td class="forminp">
				<fieldset>
					<legend class="screen-reader-text"><span><?php echo wp_kses_post( $data['title'] ); ?></span></legend>
					<input class="input-text regular-input <?php echo esc_attr( $data['class'] ); ?>"
						type="<?php echo esc_attr( $data['type'] ); ?>"
						name="<?php echo esc_attr( $field_key ); ?>"
						id="<?php echo esc_attr( $field_key ); ?>"
						style="<?php echo esc_attr( $data['css'] ); ?>"
						value="<?php echo esc_attr( $value ); ?>"
						placeholder="<?php echo esc_attr( $data['placeholder'] ); ?>"
						<?php disabled( $data['disabled'], true ); ?> <?php echo wp_kses_post( $this->get_custom_attribute_html( $data ) ); ?> />
					<?php echo wp_kses_post( $this->get_description_html( $data ) ); ?>
					<input type="hidden" name="easyship_nonce_field" value="<?php echo esc_attr( $easyship_nonce ); ?>">
				</fieldset>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * This function is used to calculate the shipping cost. Within this function we can check for weights, dimensions and other parameters.
	 *
	 * @param array $package Package.
	 * @return void
	 */
	public function calculate_shipping( $package = array() ) {
		if ( ! WC_Shipping_Zones::get_shipping_method( $this->instance_id ) ) {
			return;
		}

		$destination     = $package['destination'];
		$items           = array();
		$product_factory = new WC_Product_Factory();
		$currency        = get_woocommerce_currency();

		// @since 0.4.2
		// Support WooCommerce Currency Switcher.
		if ( defined( 'WOOCS_VERSION' ) ) {
			global $WOOCS; // phpcs:ignore

			$currency = $WOOCS->current_currency; // phpcs:ignore

			// Rates API already return rates with currency converted, so no need for WOOCS to convert.
			$WOOCS->is_multiple_allowed = false; // phpcs:ignore
		}

		if ( method_exists( WC()->cart, 'get_discount_total' ) ) {
			$total_discount = WC()->cart->get_discount_total();
		} elseif ( method_exists( WC()->cart, 'get_cart_discount_total' ) ) {
			$total_discount = WC()->cart->get_cart_discount_total();
		} else {
			$total_discount = 0;
		}

		if ( method_exists( WC()->cart, 'get_subtotal' ) ) {
			$total_cart_without_discount = WC()->cart->get_subtotal();
		} else {
			$total_cart_without_discount = WC()->cart->subtotal;
		}

		if ( ! empty( $total_discount ) && ( $total_discount > 0 ) ) {
			$discount_for_item = ( $total_discount / $total_cart_without_discount ) * 100;

			$this->set_discount_for_item( $discount_for_item );

			unset( $discount_for_item );
		}

		foreach ( $package['contents'] as $item ) {
			// Default product - assume it is simple product.
			$product             = $product_factory->get_product( $item['product_id'] );
			$skip_shipping_class = $this->get_option( 'skip_shipping_class' );

			if ( ! empty( $skip_shipping_class ) && ( $product->get_shipping_class() === $skip_shipping_class ) ) {
				continue;
			}

			// Check version.
			if ( WC()->version < '2.7.0' ) {
				// If this item is variation, get variation product instead.
				if ( 'variation' === $item['data']->product_type ) {
					$product = $product_factory->get_product( $item['variation_id'] );
				}

				// Exclude virtual and downloadable product.
				if ( 'yes' === $item['data']->virtual ) {
					continue;
				}
			} else {
				if ( 'variation' === $item['data']->get_type() ) {
					$product = $product_factory->get_product( $item['variation_id'] );
				}

				if ( 'yes' === $item['data']->get_virtual() ) {
					continue;
				}
			}

			if ( array_key_exists( 'variation_id', $item ) ) {
				if ( 0 === $item['variation_id'] ) {
					$identifier_id = $item['product_id'];
				} else {
					$identifier_id = $item['variation_id'];
				}
			} else {
				$identifier_id = $item['product_id'];
			}

			$items[] = array(
				'actual_weight'          => $this->weight_to_kg( $product->get_weight() ),
				'height'                 => $this->default_dimension( $this->dimension_to_cm( $product->get_height() ) ),
				'width'                  => $this->default_dimension( $this->dimension_to_cm( $product->get_width() ) ),
				'length'                 => $this->default_dimension( $this->dimension_to_cm( $product->get_length() ) ),
				'declared_currency'      => $currency,
				'declared_customs_value' => $this->declared_customs_value( $item['line_subtotal'], $item['quantity'] ),
				'identifier_id'          => $identifier_id,
				'sku'                    => $product->get_sku(),
				'quantity'               => $item['quantity'],
			);
		}

		if ( method_exists( WC()->cart, 'get_cart_contents_total' ) ) {
			$total_cart_with_discount = (float) WC()->cart->get_cart_contents_total();
		} else {
			$total_cart_with_discount = WC()->cart->cart_contents_total;
		}

		if ( ( $this->control_discount !== $total_cart_with_discount ) && ( is_array( $items ) && isset( $items[0] ) && isset( $items[0]['declared_customs_value'] ) ) ) {
			$diff                                = round( ( $total_cart_with_discount - $this->control_discount ), 2 );
			$items[0]['declared_customs_value'] += $diff;
			$this->add_control_discount( $diff );
			unset( $diff );
		}

		if ( ! class_exists( 'Easyship_Shipping_API' ) ) {
			// Include Easyship API.
			include_once EASYSHIP_PATH . 'includes/class-easyship-shipping-api.php';
		}

		try {
			$token = $this->get_token();

			Easyship_Shipping_API::init( $token['value'] );

			$perferred_rates = Easyship_Shipping_API::get_shipping_rate( $destination, $items );
		} catch ( Exception $e ) {
			// Exception.
			$perferred_rates = array();
		}

		foreach ( $perferred_rates as $rate ) {
			$shipping_rate = array(
				'id'        => $rate['courier_id'],
				'label'     => $rate['full_description'],
				'cost'      => $rate['total_charge'],
				'meta_data' => array( 'courier_id' => $rate['courier_id'] ),
			);

			wp_cache_add( 'easyship' . $rate['courier_id'], $rate );

			$this->add_rate( $shipping_rate );
		}
	}


	/**
	 * Get token.
	 *
	 * @return array
	 */
	protected function get_token() {
		if ( ! empty( $this->token ) ) {
			return $this->token;
		}

		$token = 'es_access_token_' . get_current_network_id();

		if ( ! get_option( $token ) && ! $this->get_option( $token ) ) {
			$token = 'es_access_token';
		}

		$this->token = array(
			'name'  => $token,
			'value' => $this->get_option( $token ) ? $this->get_option( $token ) : get_option( $token ),
		);

		return $this->token;
	}

	/**
	 * Get shipping class.
	 *
	 * @return string
	 */
	protected function get_shipping_class() {
		if ( ! empty( $this->shipping_class ) ) {
			return $this->shipping_class;
		}

		$option_key            = 'woocommerce_easyship_settings';
		$shopping_class_option = 'woocommerce_easyship_skip_shipping_class';

		$value = get_option( $option_key );

		if ( ! empty( $value ) && is_string( $value ) ) {
			$value = unserialize( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize

			$this->shipping_class = isset( $value[ $shopping_class_option ] ) ? $value[ $shopping_class_option ] : '';
		} else {
			$this->shipping_class = '';
		}

		return $this->shipping_class;
	}

	/**
	 * Set discount for item.
	 *
	 * @param int $count Count.
	 * @return void
	 */
	protected function set_discount_for_item( $count ) {
		$this->discount_for_item = $count;
	}

	/**
	 * Get discount for item.
	 *
	 * @return int
	 */
	protected function get_discount_for_item() {
		return $this->discount_for_item;
	}

	/**
	 * Add control discount.
	 *
	 * @param int $val Value.
	 * @return void
	 */
	protected function add_control_discount( $val ) {
		$this->control_discount += $val;
	}


	/**
	 * This function is used to calculate the declared customs value
	 *
	 * @param float $subtotal Subtotal.
	 * @param float $count Count.
	 * @return number
	 */
	protected function declared_customs_value( $subtotal, $count ) {
		$price = (float) ( ( $subtotal / $count ) * ( ( 100 - $this->get_discount_for_item() ) / 100 ) );
		$price = round( $price, 2 );

		$this->add_control_discount( ( $price * $count ) );

		return $price;
	}

	/**
	 * This function is convert weight to kg.
	 *
	 * @param float $weight Weight.
	 * @return number
	 */
	protected function weight_to_kg( $weight ) {
		$weight      = floatval( $weight );
		$weight_unit = get_option( 'woocommerce_weight_unit' );

		// If weight_unit is kg we do not need to convert it.
		if ( 'g' !== $weight_unit ) {
			$weight = $weight * 0.001;
		} elseif ( 'lbs' === $weight_unit ) {
			$weight = $weight * 0.453592;
		} elseif ( 'oz' === $weight_unit ) {
			$weight = $weight * 0.0283495;
		}

		return $weight;
	}


	/**
	 * This function is convert dimension to cm.
	 *
	 * @param float $length Length.
	 * @return float
	 */
	protected function dimension_to_cm( $length ) {
		$length         = floatval( $length );
		$dimension_unit = get_option( 'woocommerce_dimension_unit' );

		// If dimension_unit is cm we do not need to convert it.
		if ( 'm' === $dimension_unit ) {
			$length = $length * 100;
		} elseif ( 'mm' === $dimension_unit ) {
			$length = $length * 0.1;
		} elseif ( 'in' === $dimension_unit ) {
			$length = $length * 2.54;
		} elseif ( 'yd' === $dimension_unit ) {
			$length = $length * 91.44;
		}

		return $length;
	}

	/**
	 * Default dimension.
	 *
	 * @param number $length Length.
	 * @return mixed
	 */
	protected function default_dimension( $length ) {
		// Default dimension to 1 if it is 0.
		return $length > 0 ? $length : 1;
	}

	/**
	* Enqueue script for oauth button.
	*/
	public function oauth_action_button_es() {
		$nonce = wp_create_nonce( 'oauth_action_button_es_nonce' );

		// Add nonce to AJAX call.
		wp_localize_script(
			'oauth_action_button_es',
			'oauth_action_button_es_params',
			array(
				'nonce' => $nonce,
				'url'   => admin_url( 'admin-ajax.php' ),
			)
		);

		wp_enqueue_script(
			'oauth_action_button_es',
			EASYSHIP_URL . 'assets/js/admin/ajax_oauth_es.js',
			array( 'jquery' ),
			'5.0.8',
			true
		);
	}
}
