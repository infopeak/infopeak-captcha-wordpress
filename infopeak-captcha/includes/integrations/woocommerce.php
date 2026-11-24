<?php
/**
 * WooCommerce: the classic checkout, the Checkout block and the My Account forms. Loaded
 * when WooCommerce is active.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Placed after the billing fields: WooCommerce reloads the order review and
// payment area with AJAX, which would leave a fresh, unstarted widget there.
add_action( 'woocommerce_after_checkout_billing_form', function () {
	if ( ! ipkcap_integration_active( 'enable_woo' ) ) {
		return;
	}
	ipkcap_enqueue_for_forms();
	ipkcap_print_widget();
} );

add_action( 'woocommerce_after_checkout_validation', function ( $data, $errors ) {
	if ( ipkcap_integration_active( 'enable_woo' ) && ! ipkcap_posted_token_valid() ) {
		$errors->add( 'ipkcap_failed', ipkcap_error_message() );
	}
}, 10, 2 );

// A refused checkout fires checkout_error on the body; the widget is in form.checkout.
ipkcap_add_form_script( "ipkcapForms.resetOn('checkout_error', 'form.checkout', { jquery: true, on: document.body });" );

/* -------------------------------------------------------------------------
 * Checkout block (the default checkout in new shops). It sends the order to the Store API
 * instead of the classic checkout above, so it has its own widget, token and check.
 * ---------------------------------------------------------------------- */

// Right above the Place order button. WooCommerce draws the block in the browser and keeps
// markup that is not a block, as it does for other captchas placed here.
add_filter( 'render_block_woocommerce/checkout-actions-block', function ( $content ) {
	if ( ! ipkcap_integration_active( 'enable_woo' ) ) {
		return $content;
	}
	ipkcap_enqueue_for_forms();
	return '<div class="ipkcap-wc-block" style="margin-bottom:16px">' . ipkcap_widget_html() . '</div>' . $content;
} );

// The block's script, through WooCommerce's interface for Checkout block integrations.
add_action( 'woocommerce_blocks_checkout_block_registration', function ( $registry ) {
	if ( interface_exists( 'Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface' ) && is_object( $registry ) && method_exists( $registry, 'register' ) ) {
		require_once __DIR__ . '/woocommerce-blocks.php';
		$registry->register( new IPKCAP_WC_Blocks_Integration() );
	}
} );

// The token travels in the Store API's extension data, which only takes what is declared.
function ipkcap_wc_register_endpoint_data() {
	if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
		return;
	}
	woocommerce_store_api_register_endpoint_data(
		array(
			'endpoint'        => 'checkout',
			'namespace'       => 'infopeak-captcha',
			'schema_callback' => function () {
				return array(
					'token' => array(
						'description' => 'InfoPeak Captcha token.',
						'type'        => array( 'string', 'null' ),
						'context'     => array( 'view', 'edit' ),
					),
				);
			},
		)
	);
}
if ( did_action( 'woocommerce_blocks_loaded' ) ) {
	ipkcap_wc_register_endpoint_data();
} else {
	add_action( 'woocommerce_blocks_loaded', 'ipkcap_wc_register_endpoint_data' );
}

// Placing an order through the Store API (the Checkout block, or anything else that posts
// to it): the token is checked first, before WooCommerce stores the customer's details,
// creates the order or takes payment. A refusal is a Store API error, which the Checkout
// block shows in its own notice. Updates while the shopper fills in the form (PUT) and
// paying for an existing order (checkout/<id>) are left alone.
add_action( 'woocommerce_store_api_checkout_update_customer_from_request', function ( $customer, $request ) {
	if ( ! ipkcap_integration_active( 'enable_woo' ) || ! $request instanceof WP_REST_Request || 'POST' !== $request->get_method() || ! preg_match( '~^/wc/store(?:/v\d+)?/checkout$~', $request->get_route() ) ) {
		return;
	}
	// Express payments (Apple Pay, Google Pay, Link) through WooPayments or Stripe can be
	// started from the cart or a product page, where there is no widget. They are left to
	// the wallet, as other captcha plugins for WooCommerce do.
	if ( in_array( $request->get_param( 'payment_method' ), array( 'woocommerce_payments', 'stripe' ), true ) ) {
		$payment_data = $request->get_param( 'payment_data' );
		foreach ( is_array( $payment_data ) ? $payment_data : array() as $item ) {
			if ( is_array( $item ) && isset( $item['key'] ) && in_array( $item['key'], array( 'express_payment_type', 'payment_request_type' ), true ) && ! empty( $item['value'] ) ) {
				return;
			}
		}
	}
	$extensions = $request->get_param( 'extensions' );
	$token      = is_array( $extensions ) && isset( $extensions['infopeak-captcha']['token'] ) && is_string( $extensions['infopeak-captcha']['token'] ) ? sanitize_text_field( $extensions['infopeak-captcha']['token'] ) : '';
	if ( ! ipkcap_verify_token( $token ) ) {
		throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'infopeak_captcha_failed', esc_html( ipkcap_error_message() ), 400 );
	}
}, 10, 2 );

/* -------------------------------------------------------------------------
 * My Account: login, registration and lost password, and the login form at the classic
 * checkout (the same login form). They follow the Login form, Registration form and Lost
 * password form settings, like the forms on wp-login.php: the accounts are the same, and
 * WooCommerce's lost-password form runs WordPress' own lostpassword_post check (core.php).
 *
 * Each form has its own check here, which WordPress' does not repeat: WooCommerce logs in
 * with username/password (core.php only checks a login that posts log/pwd) and registers
 * through wc_create_new_customer(), not register_new_user(). An account created while
 * placing an order is checked once, with the order (above), not here.
 * ---------------------------------------------------------------------- */

function ipkcap_wc_account_form( $setting ) {
	$s = ipkcap_get_settings();
	if ( ipkcap_is_configured() && ! empty( $s[ $setting ] ) ) {
		ipkcap_enqueue_script();
		// WooCommerce's own row class gives the widget the same spacing as the fields around it.
		echo '<div class="form-row ipkcap-wc-account">' . ipkcap_widget_html() . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in ipkcap_widget_html().
	}
}

// Another check already refused the attempt: nothing happens, so the token is kept unspent
// and the visitor sees one message.
function ipkcap_wc_account_check( $error, $setting ) {
	$s = ipkcap_get_settings();
	if ( ipkcap_is_configured() && ! empty( $s[ $setting ] ) && $error instanceof WP_Error && ! $error->has_errors() && ! ipkcap_posted_token_valid() ) {
		$error->add( 'ipkcap_failed', ipkcap_error_message() );
	}
	return $error;
}

add_action( 'woocommerce_login_form', function () {
	ipkcap_wc_account_form( 'enable_login' );
} );

add_action( 'woocommerce_register_form', function () {
	ipkcap_wc_account_form( 'enable_register' );
} );

add_action( 'woocommerce_lostpassword_form', function () {
	ipkcap_wc_account_form( 'enable_lostpw' );
} );

// Runs before WooCommerce signs the customer in.
add_filter( 'woocommerce_process_login_errors', function ( $error ) {
	return ipkcap_wc_account_check( $error, 'enable_login' );
}, 20 );

// Runs before WooCommerce creates the account or sends any email. Only the My Account form
// fires it; the checkout's account creation does not.
add_filter( 'woocommerce_process_registration_errors', function ( $error ) {
	return ipkcap_wc_account_check( $error, 'enable_register' );
}, 20 );
