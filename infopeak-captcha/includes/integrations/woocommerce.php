<?php
/**
 * WooCommerce classic checkout. Loaded when WooCommerce is active.
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
