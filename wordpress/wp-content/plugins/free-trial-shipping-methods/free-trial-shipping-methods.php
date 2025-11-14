<?php
/**
 * Plugin Name: Two Custom Shipping Methods for Free Trial
 * Description: Adds two custom shipping methods to WooCommerce.
 * Version: 1.0
 * Author: Vladyslav Sabo
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

// Initialize shipping methods
add_action( 'woocommerce_shipping_init', 'two_custom_shipping_methods_init' );

function two_custom_shipping_methods_init() {

    // Shipping Method 1 ($1)
    class WC_Shipping_One_Dollar extends WC_Shipping_Method {

        public function __construct( $instance_id = 0 ) {
            $this->id                 = 'free_trial_one_dollar_shipping';
            $this->instance_id        = absint( $instance_id );
            $this->method_title       = __( 'Free Trial $1 validation fee Shipping' );
            $this->method_description = __( 'Custom shipping method with $1 cost.' );
            $this->supports           = array( 'shipping-zones', 'instance-settings' );

            $this->init();
        }

        public function init() {
            $this->init_form_fields();
            $this->init_settings();

            $this->enabled = $this->get_option( 'enabled' );
            $this->title   = $this->get_option( 'title', 'Free Trial $1 validation fee Shipping' );

            add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
        }

        public function calculate_shipping( $package = array() ) {
            $rate = array(
                'id'    => $this->id,
                'label' => $this->title,
                'cost'  => 1.00,
            );
            $this->add_rate( $rate );
        }
    }

    // Shipping Method 2 ($500)
    class WC_Shipping_Five_Hundred extends WC_Shipping_Method {

        public function __construct( $instance_id = 0 ) {
            $this->id                 = 'free_trial_five_hundred_shipping';
            $this->instance_id        = absint( $instance_id );
            $this->method_title       = __( 'Free Trial $500 deposit fee Shipping' );
            $this->method_description = __( 'Custom shipping method with $500 cost.' );
            $this->supports           = array( 'shipping-zones', 'instance-settings' );

            $this->init();
        }

        public function init() {
            $this->init_form_fields();
            $this->init_settings();

            $this->enabled = $this->get_option( 'enabled' );
            $this->title   = $this->get_option( 'title', 'Free Trial $500 deposit fee Shipping' );

            add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
        }

        public function calculate_shipping( $package = array() ) {
            $rate = array(
                'id'    => $this->id,
                'label' => $this->title,
                'cost'  => 500.00,
            );
            $this->add_rate( $rate );
        }
    }
}

// Register the methods
add_filter( 'woocommerce_shipping_methods', 'register_two_custom_shipping_methods' );

function register_two_custom_shipping_methods( $methods ) {
    $methods['free_trial_one_dollar_shipping']   = 'WC_Shipping_One_Dollar';
    $methods['free_trial_five_hundred_shipping'] = 'WC_Shipping_Five_Hundred';
    return $methods;
}
