<?php
/**
 * Contact Form 7. Loaded when Contact Form 7 is active.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Add the widget in front of the submit button, unless the form already
// places it itself with the [infopeak_captcha] tag.
add_filter( 'wpcf7_form_elements', function ( $html ) {
	if ( ! ipkcap_integration_active( 'enable_cf7' ) ) {
		return $html;
	}
	ipkcap_enqueue_for_forms();
	if ( strpos( $html, 'infopeak-captcha' ) !== false ) {
		return $html;
	}
	$widget = ipkcap_widget_html();
	$count  = 0;
	$html   = preg_replace( '/(<(?:input[^>]+type="submit"|button[^>]+type="submit")[^>]*>)/i', $widget . '$1', $html, 1, $count );
	return $count ? $html : $html . $widget;
} );

add_action( 'wpcf7_init', function () {
	if ( function_exists( 'wpcf7_add_form_tag' ) ) {
		wpcf7_add_form_tag( 'infopeak_captcha', function () {
			return ipkcap_integration_active( 'enable_cf7' ) ? ipkcap_widget_html() : '';
		} );
	}
} );

// Contact Form 7's own spam check. Returning true marks the submission as spam.
add_filter( 'wpcf7_spam', function ( $spam ) {
	if ( $spam || ! ipkcap_integration_active( 'enable_cf7' ) ) {
		return $spam;
	}
	return ! ipkcap_posted_token_valid();
} );

// Contact Form 7 shows its generic spam message; use the clearer one when the quota is the reason.
add_filter( 'wpcf7_display_message', function ( $message, $status ) {
	if ( 'spam' === $status && ! empty( $GLOBALS['ipkcap_quota_exceeded'] ) ) {
		return ipkcap_error_message();
	}
	return $message;
}, 10, 2 );

// After every submit, sent or not, Contact Form 7 fires wpcf7submit on the form.
ipkcap_add_form_script( "ipkcapForms.resetOn('wpcf7submit', null);" );
