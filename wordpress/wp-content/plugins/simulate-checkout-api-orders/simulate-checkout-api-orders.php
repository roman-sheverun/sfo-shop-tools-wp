<?php
/**
 * Plugin Name: Simulate Checkout for API Orders
 * Description: Makes REST API orders look like checkout orders for plugin compatibility (e.g. Quivo).
 * Author: Roman Sheverun <roman.sheverun@jointoit.com>
 * Version: 1.1
 */

// --- helper: logger ---
if ( ! function_exists( 'sco_log' ) ) {
	function sco_log( $msg, $ctx = [] ) {
		if ( is_array( $msg ) || is_object( $msg ) ) $msg = print_r( $msg, true );
		if ( ! empty( $ctx ) ) $msg .= ' | ' . wp_json_encode( $ctx );
		error_log( '[SCO] ' . $msg );
	}
}

// --- helper: get or create a WP user from order billing email ---
function sco_resolve_customer_id_from_order( WC_Order $order ) {
	$email = $order->get_billing_email();
	if ( ! $email ) { sco_log('No billing email on order', ['order_id'=>$order->get_id()]); return 0; }

	// Existing user by email?
	$user = get_user_by( 'email', $email );
	if ( $user ) {
		sco_log('Mapped to existing user', ['user_id'=>$user->ID, 'email'=>$email]);
		sco_send_welcome_email($order, $user->ID);

		return (int) $user->ID;
	}

	if ( function_exists( 'wc_create_new_customer_username' ) ) {
		$username = wc_create_new_customer_username( $email );
	} else {
		// Fallback: sanitize local-part and ensure it's not empty
		$local = sanitize_user( preg_replace( '/[^a-z0-9._-]/i', '', strstr( $email, '@', true ) ), true );
		if ( '' === $local ) { $local = 'user'; }
		$username = $local;
	}

	$base = $username;
	$attempt = 0;
	while ( username_exists( $username ) || ! validate_username( $username ) ) {
		$attempt++;
		$suffix = wp_generate_password( 4, false, false );
		$username = substr( $base, 0, 40 ) . '_' . strtolower( $suffix ); // keep under 60 chars
		if ( $attempt > 5 ) { // last resort, very safe fallback
			$username = 'customer_' . strtolower( wp_generate_password( 8, false, false ) );
			break;
		}
	}
	sco_log('Username prepared', ['email'=>$email, 'username'=>$username]);

	// Create a new customer
	$password = '123456';
	$user_id  = wc_create_new_customer( $email, $username, $password, [
		'first_name' => $order->get_billing_first_name(),
		'last_name'  => $order->get_billing_last_name(),
	] );

	if ( is_wp_error( $user_id ) ) {
		sco_log('Failed to create customer', ['error'=>$user_id->get_error_message(), 'email'=>$email]);
		return 0;
	}

	sco_log('Created customer', ['user_id'=>$user_id, 'email'=>$email, 'username'=>$username, 'password'=>$password]);
	sco_send_welcome_email($order, $user_id, $password);
	// Optional: copy billing/shipping to user meta for completeness
	update_user_meta( $user_id, 'billing_first_name', $order->get_billing_first_name() );
	update_user_meta( $user_id, 'billing_last_name',  $order->get_billing_last_name() );
	update_user_meta( $user_id, 'billing_phone',      $order->get_billing_phone() );
	update_user_meta( $user_id, 'billing_address_1',  $order->get_billing_address_1() );
	update_user_meta( $user_id, 'billing_address_2',  $order->get_billing_address_2() );
	update_user_meta( $user_id, 'billing_city',       $order->get_billing_city() );
	update_user_meta( $user_id, 'billing_postcode',   $order->get_billing_postcode() );
	update_user_meta( $user_id, 'billing_country',    $order->get_billing_country() );
	update_user_meta( $user_id, 'billing_state',      $order->get_billing_state() );

	return (int) $user_id;
}

// --- helper: move any payment tokens from order to user (for auto-renewals) ---
function sco_rehome_order_tokens_to_user( WC_Order $order, $user_id ) {
	if ( ! class_exists( 'WC_Payment_Tokens' ) || ! $user_id ) return;

	// 1) Tokens attached to this order
	$tokens = WC_Payment_Tokens::get_order_tokens( $order );

	// 2) If none, also check tokens already stored on the customer
	if ( empty( $tokens ) ) {
		$customer_tokens = WC_Payment_Tokens::get_customer_tokens( $user_id );
		if ( ! empty( $customer_tokens ) ) {
			sco_log('Found existing customer tokens', ['count'=>count($customer_tokens)]);
			return; // already saved to user, nothing to move
		}
		sco_log('No tokens on order to move and none on user');
		return;
	}

	foreach ( $tokens as $token ) {
		if ( (int) $token->get_user_id() === (int) $user_id ) continue;
		$token->set_user_id( $user_id );
		$token->save();
		sco_log('Rehomed token to user', ['token_id'=>$token->get_id(), 'user_id'=>$user_id]);
	}
}

function sco_get_schedule_from_order( WC_Order $order ) {
	$valid = ['day','week','month','year'];
	$period       = $order->get_meta('_subscription_period', true);
	$interval     = (int) $order->get_meta('_subscription_interval', true);
	$length       = (int) $order->get_meta('_subscription_length', true);
	$trial_length = (int) $order->get_meta('_subscription_trial_length', true);
	$trial_period =        $order->get_meta('_subscription_trial_period', true);

	if ( $period && in_array($period, $valid, true) && $interval >= 1 ) {
		return compact('period','interval','length','trial_length','trial_period');
	}
	return null;
}

function sco_after_payment_create_subscription( $order_id, string $action) {
	if ( ! function_exists( 'wcs_create_subscription' ) || ! class_exists( 'WC_Subscriptions_Product' ) ) {
		sco_log('Subscriptions plugin not available');
		return;
	}

	$order = wc_get_order( $order_id );
	if ( ! $order ) return;
    if ($order->get_created_via() == 'subscription') return;

	$original_via = $order->get_meta('_sco_original_created_via') ?: $order->get_created_via();
	sco_log("ACTION: $action - FUNCTION: sco_after_payment_create_subscription", [
		'order_id'=>$order_id,
        'created_via'=>$order->get_created_via(),
        'original_via'=>$original_via,
        'is_subscription'=>$order->get_meta('_sco_is_subscription', true)
	]);

	if ( $original_via !== 'rest-api' || $order->get_meta('_sco_is_subscription', true) !== 'true' ) return;

	if ( function_exists('wcs_get_subscriptions_for_order') ) {
		$existing = wcs_get_subscriptions_for_order( $order_id );
		if ( ! empty( $existing ) ) {
			sco_log('Order already linked to subscription(s)', ['order_id'=>$order_id, 'subs'=>array_keys($existing)]);
			return;
		}
	}

	$schedule_from_order = sco_get_schedule_from_order( $order );
	if ( ! $schedule_from_order ) {
		sco_log('Order missing subscription schedule meta', ['order_id'=>$order_id]);
		return;
	}

	// Ensure we have a customer_id (create/map if needed)
	$customer_id = $order->get_user_id();
	if ( ! $customer_id ) {
		$customer_id = sco_resolve_customer_id_from_order( $order );
		if ( ! $customer_id ) { sco_log('Could not resolve customer_id; abort', ['order_id'=>$order_id]); return; }

		$order->set_customer_id( $customer_id );
		$order->save();
		sco_log('Assigned order to customer', ['order_id'=>$order_id, 'user_id'=>$customer_id]);
	}

	// Move tokens from order to that user (helps auto-renewals)
	sco_rehome_order_tokens_to_user( $order, $customer_id );

	$valid_periods = ['day','week','month','year'];
	$period   = strtolower( trim( (string) $schedule_from_order['period'] ) );
	$interval = max( 1, (int) $schedule_from_order['interval'] );
	$length   = max( 0, (int) $schedule_from_order['length'] );
	$trial_len= max( 0, (int) $schedule_from_order['trial_length'] );
	$trial_pr = $schedule_from_order['trial_period'] ? strtolower( trim( (string) $schedule_from_order['trial_period'] ) ) : '';

	if ( ! in_array( $period, $valid_periods, true ) || $interval < 1 ) {
		sco_log('Invalid order-level schedule (sanitized)', compact('period','interval','length','trial_len','trial_pr'));
		return;
	}
	sco_log('Using schedule for creation', compact('period','interval','length','trial_len','trial_pr'));

	// Create the subscription (NO schedule keys here)
	$subscription = wcs_create_subscription( [
		'order_id'        => $order->get_id(),
		'customer_id'     => $customer_id,
		'status'          => 'pending',
		'start_date'      => gmdate('Y-m-d H:i:s'),
		'billing_address' => $order->get_address('billing'),
		'shipping_address'=> $order->get_address('shipping'),
		'billing_period'   => $period,
		'billing_interval' => $interval,
		'payment_details' => [
			'post_meta' => [
				'_stripe_customer_id' => $order->get_meta('_stripe_customer_id'),
				'_stripe_source_id' => $order->get_meta('_stripe_source_id'),
			],
		]
	] );

	$subscription = wcs_copy_order_address( $order, $subscription );
	sco_log('order payment method', ['payment_method'=>$order->get_payment_method()]);

	$subscription->set_payment_method( 'stripe' );

	wcs_copy_order_meta( $order, $subscription, 'subscription' );

	if ( is_wp_error( $subscription ) || ! is_a( $subscription, 'WC_Subscription' ) ) {
		sco_log('Failed to create subscription', [
			'order_id' => $order_id,
			'error'    => is_wp_error($subscription) ? $subscription->get_error_message() : 'not a WC_Subscription',
			'user_id'  => $customer_id,
		]);
		return;
	}

	sco_log('Applied billing schedule', [
		'period'   => $schedule_from_order['period'],
		'interval' => (int) $schedule_from_order['interval'],
	]);

	// Optional: trial
	$now = time();
	if ( ! empty( $schedule_from_order['trial_length'] )
	     && (int) $schedule_from_order['trial_length'] > 0
	     && in_array( $schedule_from_order['trial_period'], $valid_periods, true ) ) {

		$trial_end_ts = strtotime( '+' . (int) $schedule_from_order['trial_length'] . ' ' . $schedule_from_order['trial_period'], $now );
		$subscription->update_dates( [ 'trial_end' => gmdate('Y-m-d H:i:s', $trial_end_ts) ] );
		sco_log('Applied trial', ['trial_end' => gmdate('Y-m-d H:i:s', $trial_end_ts)]);
	}

	// Add subscription products & preserve per-order price
	foreach ( $order->get_items('line_item') as $item ) {
		$p = $item->get_product();
		$rec_total  = $item->get_meta('_sco_recurring_total', true);
		$rec_total  = $rec_total !== '' ? (float) $rec_total : (float) $item->get_total();
		$rec_subtot = $rec_total; // keep simple; adjust if you need subtotals/taxes split

		$subscription->add_product( $p, max(1, (int) $item->get_quantity()), [
			'subtotal'       => $rec_subtot,
			'total'          => $rec_total,
			'subtotal_taxes' => (array) ( $item->get_taxes()['subtotal'] ?? [] ),
			'total_taxes'    => (array) ( $item->get_taxes()['total'] ?? [] ),
		] );
	}

	$subscription->calculate_totals();
	$subscription->update_status('active');
	$subscription->save();

	$order->add_order_note( sprintf( 'Created subscription #%d after payment.', $subscription->get_id() ) );
	$order->update_meta_data('_sco_subscription_created', $subscription->get_id());
    $order->update_meta_data('_sco_is_subscription', 'false');
	$order->save();

	sco_log('Subscription created & activated', [
		'order_id' => $order_id,
		'sub_id'   => $subscription->get_id(),
		'user_id'  => $customer_id
	]);
}

// --- main: after payment, ensure user exists, then create subscription ---
add_action( 'woocommerce_order_status_processing', function ($order_id) {
	$order = wc_get_order($order_id);

	sco_log('ACTION: woocommerce_order_status_processing', [
		'order_id'=>$order_id,
		'created_via'=>$order->get_created_via(),
		'is_subscription'=>$order->get_meta('_sco_is_subscription', true)
	]);

	sco_after_payment_create_subscription($order_id, 'woocommerce_order_status_processing');
});
add_action( 'woocommerce_order_status_completed',  function ($order_id) {
	$order = wc_get_order($order_id);

    sco_log('ACTION: woocommerce_order_status_completed', [
		'order_id'=>$order_id,
		'created_via'=>$order->get_created_via(),
		'is_subscription'=>$order->get_meta('_sco_is_subscription', true)
	]);
	sco_after_payment_create_subscription($order_id, 'woocommerce_order_status_completed');
} );

add_action('woocommerce_new_order', function($order_id) {
	$order = wc_get_order($order_id);
	sco_log('ACTION: woocommerce_new_order', [
            'order_id'=>$order_id,
            'created_via'=>$order->get_created_via(),
            'is_subscription'=>$order->get_meta('_sco_is_subscription', true)
    ]);

	if (!$order || $order->get_created_via() !== 'rest-api') {
		return;
	}

	sco_log('ACTION: Processing new order', ['is_subscription'=>$order->get_meta('_sco_is_subscription', true), 'order_id'=>$order_id]);
	if ($order->get_meta('_sco_is_subscription', true) == 'true') {
		// Map/create user now so Stripe can attach the PM to this user
		$user_id = $order->get_user_id();
		if ( ! $user_id ) {
			$user_id = sco_resolve_customer_id_from_order( $order );
			if ( $user_id ) {
				$order->set_customer_id( $user_id );
				$order->save();
				sco_log('Pre-assigned user before payment', ['order_id'=>$order_id, 'user_id'=>$user_id]);
			}
		}

		// Ask our Stripe filters to force-save PM on this order
		$order->update_meta_data('_sco_force_save_pm', 'yes');
		$order->update_meta_data('_sco_original_created_via', 'rest-api');
	}

    if ($order->get_created_via() == 'rest-api') {
	    $order->set_created_via('checkout');
	    $order->save();

	    // Manually trigger checkout-like behavior
	    do_action('woocommerce_checkout_order_processed', $order_id, [], $order);
	    do_action('woocommerce_thankyou', $order_id);
    }
});

// ========================================
// NEW: Auto-save payment methods for subscriptions
// ========================================

/**
 * Force save payment method for subscription orders on Stripe payment form
 */
add_filter('woocommerce_stripe_force_save_source', function($force_save, $source_object = null) {
	// Check if we're on order-pay endpoint
	if (is_wc_endpoint_url('order-pay')) {
		$order_id = absint(get_query_var('order-pay'));
		if ($order_id) {
			$order = wc_get_order($order_id);
			if ($order && $order->get_meta('_sco_is_subscription') === 'true') {
				sco_log('Force saving payment method for subscription order', ['order_id' => $order_id]);
				return true;
			}
		}
	}
	return $force_save;
}, 10, 2);

/**
 * Hide the save payment checkbox and add custom text for subscription orders
 */
add_action('wp_footer', function() {
	if (!is_wc_endpoint_url('order-pay')) {
		return;
	}

	$order_id = absint(get_query_var('order-pay'));
	if (!$order_id) {
		return;
	}

	$order = wc_get_order($order_id);
	if (!$order || $order->get_meta('_sco_is_subscription') !== 'true') {
		return;
	}
	?>
    <style>
        /* Hide the save payment method checkbox container */
        .woocommerce-SavedPaymentMethods-saveNew,
        #wc-stripe-new-payment-method-wrap,
        .wc-stripe-save-payment-method-checkbox,
        p.woocommerce-SavedPaymentMethods-saveNew {
            display: none !important;
        }
    </style>
    <script>
        jQuery(document).ready(function($) {
            // Force check the save payment checkbox if it exists
            $('#wc-stripe-new-payment-method').prop('checked', true);
            $('input[name="wc-stripe-new-payment-method"]').prop('checked', true);

            // // Add custom text about payment method being saved
            // var customMessage = '<div class="sco-payment-notice" style="background:#f0f0f0; padding:10px; margin:10px 0; border-left:4px solid #2271b1;">' +
            //     '<strong>Note:</strong> Your payment method will be securely saved for automatic recurring payments.' +
            //     '</div>';
            //
            // // Insert after payment methods or before place order button
            // if ($('#payment').length) {
            //     if (!$('.sco-payment-notice').length) {
            //         $('#payment .payment_methods').after(customMessage);
            //     }
            // }
        });
    </script>
	<?php
});

/**
 * Ensure payment tokens are saved for subscription orders (Stripe specific)
 */
add_action('woocommerce_stripe_process_payment', function($order, $prepared_source) {
	if ($order->get_meta('_sco_is_subscription') === 'true') {
		// Force save the source/payment method
		if (!empty($prepared_source->source)) {
			update_post_meta($order->get_id(), '_stripe_source_id', $prepared_source->source);
		}
		if (!empty($prepared_source->customer)) {
			update_post_meta($order->get_id(), '_stripe_customer_id', $prepared_source->customer);
		}

		// Ensure save_payment_method is set
		$_POST['wc-stripe-new-payment-method'] = true;

		sco_log('Forced payment method save for subscription', [
			'order_id' => $order->get_id(),
			'source' => $prepared_source->source ?? 'none',
			'customer' => $prepared_source->customer ?? 'none'
		]);
	}
}, 10, 2);

/**
 * Alternative: Force save via Stripe's save payment method filter
 */
add_filter('wc_stripe_save_payment_method_checkbox', function($save) {
	if (is_wc_endpoint_url('order-pay')) {
		$order_id = absint(get_query_var('order-pay'));
		if ($order_id) {
			$order = wc_get_order($order_id);
			if ($order && $order->get_meta('_sco_is_subscription') === 'true') {
				return true;
			}
		}
	}
	return $save;
});

/**
 * Ensure Stripe payment intent includes setup for future usage
 */
//add_filter('wc_stripe_generate_payment_request', function($post_data, $order, $prepared_source) {
//    sco_log("ACTION: wc_stripe_generate_payment_request", [
//            'order_id' => $order->get_id(),
//            'created_via' => $order->get_created_via(),
//            'is_subscription' => $order->get_meta('_sco_is_subscription', true)
//    ]);
//	if ($order && $order->get_created_via() == 'rest-api' && $order->get_meta('_sco_is_subscription') === 'true') {
//		$post_data['setup_future_usage'] = 'off_session';
//		sco_log('Added setup_future_usage to Stripe request', ['order_id' => $order->get_id()]);
//	}
//	return $post_data;
//}, 10, 3);

/**
 * Force save payment token after successful payment
 */
add_action('woocommerce_payment_complete', function($order_id) {
	$order = wc_get_order($order_id);
	if (!$order || $order->get_meta('_sco_is_subscription') !== 'true') {
		return;
	}

	// Ensure we have a user
	$user_id = $order->get_user_id();
	if (!$user_id) {
		$user_id = sco_resolve_customer_id_from_order($order);
		if ($user_id) {
			$order->set_customer_id($user_id);
			$order->save();
		}
	}

	// Move any payment tokens to the user
	if ($user_id) {
		sco_rehome_order_tokens_to_user($order, $user_id);
	}

	sco_log('Payment complete - ensured tokens are saved', ['order_id' => $order_id, 'user_id' => $user_id]);
});

/**
 * Add custom text to order-pay page for subscription orders
 */
add_action('woocommerce_before_pay_action', function($order) {
	if ($order->get_meta('_sco_is_subscription') === 'true') {
		add_action('woocommerce_pay_order_before_submit', function() {
			echo '<div class="woocommerce-info">';
			echo '<strong>Subscription Payment:</strong> Your payment method will be securely saved for automatic recurring payments.';
			echo '</div>';
		});
	}
});

function sco_build_welcome_email_html(array $args): string {
	$defaults = [
		'name'        => '',
		'email'       => '',
		'password'    => '', // if empty -> no password block
		'payment_url' => home_url('/checkout/'),
		'login_url'   => wc_get_page_permalink('myaccount'),
	];
	$a = wp_parse_args($args, $defaults);

	$brand_green = '#17a34a';
	$text_color  = '#1f2937';
	$muted       = '#6b7280';
	$bg          = '#f6f7fb';
	$card_bg     = '#ffffff';

	ob_start(); ?>
    <div style="background:<?=esc_attr($bg)?>;padding:32px 16px;font-family:Arial,Helvetica,sans-serif;color:<?=esc_attr($text_color)?>;">
        <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" width="100%" style="max-width:640px;margin:0 auto;background:<?=esc_attr($card_bg)?>;border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,0.07);">
            <tr><td style="padding:28px 28px 8px;">
                    <h1 style="margin:0 0 8px;font-size:22px;line-height:1.3;">Welcome to <span style="color:<?=esc_attr($brand_green)?>;">SaveFryOil</span>!</h1>
                    <p style="margin:0;font-size:14px;color:<?=esc_attr($muted)?>;">Your account was created successfully.</p>
                </td></tr>

            <tr><td style="padding:12px 28px 4px;">
                    <p style="margin:0 0 12px;">Hi <?=esc_html($a['name'] ?: 'there')?>,</p>
                    <p style="margin:0 0 16px;">Please complete your subscription payment to get started.</p>
                </td></tr>

            <tr><td style="padding:0 28px 20px;text-align:center;">
                    <a href="<?=esc_url($a['payment_url'])?>"
                       style="display:inline-block;padding:14px 28px;background:<?=esc_attr($brand_green)?>;color:#ffffff;text-decoration:none;border-radius:10px;font-weight:bold;font-size:16px;">
                        Complete Payment
                    </a>
                </td></tr>

            <tr><td style="padding:0 28px 8px;">
                    <div style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;padding:16px;">
                        <p style="margin:0 0 10px;font-weight:bold;">Your login details</p>
                        <p style="margin:0 0 6px;"><strong>Email:</strong> <?=esc_html($a['email'])?></p>
						<?php if (!empty($a['password'])): ?>
                            <p style="margin:0;"><strong>Password:</strong> <?=esc_html($a['password'])?></p>
						<?php else: ?>
                            <p style="margin:0;">You can log in to your account here:</p>
						<?php endif; ?>
                        <p style="margin:10px 0 0;">My Account: <a href="<?=esc_url($a['login_url'])?>" style="color:<?=esc_attr($brand_green)?>;text-decoration:underline;">Log in</a></p>
                    </div>
                </td></tr>

            <tr><td style="padding:16px 28px 28px;color:<?=esc_attr($muted)?>;font-size:12px;">
                    <p style="margin:0">If you didn’t request this, you can safely ignore this email.</p>
                    <p style="margin:6px 0 0;">&copy; <?=date('Y')?> SaveFryOil</p>
                </td></tr>
        </table>
    </div>
	<?php
	return (string) ob_get_clean();
}


//function sco_send_welcome_email(WC_Order $order, int $user_id, string $maybe_password = ''): void {
//	$user = get_userdata($user_id);
//	if (!$user) return;
//
//	$subject = 'Welcome to SaveFryOil — Complete Your Payment';
//	$headers = ['Content-Type: text/html; charset=UTF-8'];
//
//	$html = sco_build_welcome_email_html([
//		'name'        => $order->get_billing_first_name(),
//		'email'       => $user->user_email,
//		'password'    => $maybe_password, // empty => not shown
//		'payment_url' => $order->get_checkout_payment_url(),
//		'login_url'   => wc_get_page_permalink('myaccount'),
//	]);
//
//	wp_mail($user->user_email, $subject, $html, $headers);
//}

function sco_send_welcome_email( WC_Order $order, int $user_id, string $maybe_password = '' ): void {
	$user = get_userdata( $user_id );
	if ( ! $user ) return;

	// Use Woo mailer (pulls WC email styles, from-name, from-address, etc.)
	$mailer        = WC()->mailer();
	$subject       = sprintf(
	/* translators: %s: site name */
		__( 'Welcome to %s — Complete Your Payment', 'sco' ),
		wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES )
	);
	$email_heading = __( 'Welcome to SaveFryOil', 'sco' );

	// Pass any existing WC_Email object for proper context (not strictly required but nice to have)
	$email_obj = $mailer->emails['WC_Email_Customer_Note'] ?? null;

	ob_start();
	// Woo header (theme/child-theme overrides will be respected)
	do_action( 'woocommerce_email_header', $email_heading, $email_obj );
	?>
    <p>
		<?php
		printf(
		/* translators: %s: first name */
			esc_html__( 'Hi %s,', 'sco' ),
			esc_html( $order->get_billing_first_name() ?: ( $user->display_name ?: $user->user_login ) )
		);
		?>
    </p>

    <p><?php esc_html_e( 'Your account was created successfully. Please complete your subscription payment to get started.', 'sco' ); ?></p>

    <p style="text-align:center; margin: 20px 0;">
        <a class="button"
           href="<?php echo esc_url( $order->get_checkout_payment_url() ); ?>"
           target="_blank"
           style="display:inline-block;padding:14px 28px;background:#17a34a;color:#ffffff;text-decoration:none;border-radius:10px;font-weight:bold;font-size:16px;">
		    <?php esc_html_e( 'Complete Payment', 'sco' ); ?>
        </a>
    </p>

    <h3><?php esc_html_e( 'Your login details', 'sco' ); ?></h3>
    <ul>
        <li><strong><?php esc_html_e( 'Email:', 'sco' ); ?></strong> <?php echo esc_html( $user->user_email ); ?></li>
		<?php if ( ! empty( $maybe_password ) ) : ?>
            <li><strong><?php esc_html_e( 'Password:', 'sco' ); ?></strong> <?php echo esc_html( $maybe_password ); ?></li>
		<?php else : ?>
            <li><?php esc_html_e( 'Use your existing password to sign in.', 'sco' ); ?></li>
		<?php endif; ?>
    </ul>

    <p>
        <a href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>" target="_blank">
			<?php esc_html_e( 'Log in to My Account', 'sco' ); ?>
        </a>
    </p>

    <p style="font-size: 12px; color: #6b7280;">
		<?php esc_html_e( 'If you didn’t request this, you can safely ignore this email.', 'sco' ); ?>
    </p>

	<?php
	// Woo footer
	do_action( 'woocommerce_email_footer', $email_obj );
	$message = ob_get_clean();

	$headers = [ 'Content-Type: text/html; charset=UTF-8' ];
	$mailer->send( $user->user_email, $subject, $message, $headers );
}
