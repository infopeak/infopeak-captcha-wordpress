<?php
/**
 * WPForms. Loaded when WPForms (Lite or Pro) is active.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wpforms_display_submit_before', function () {
	if ( ! ipkcap_integration_active( 'enable_wpforms' ) ) {
		return;
	}
	ipkcap_enqueue_for_forms();
	ipkcap_print_widget();
} );

add_action( 'wpforms_process_before', function ( $entry, $form_data ) {
	if ( ! ipkcap_integration_active( 'enable_wpforms' ) || ipkcap_posted_token_valid() ) {
		return;
	}
	$process = function_exists( 'wpforms' ) ? ( method_exists( wpforms(), 'get' ) ? wpforms()->get( 'process' ) : wpforms()->process ) : null;
	if ( $process && isset( $form_data['id'] ) ) {
		$process->errors[ $form_data['id'] ]['header'] = ipkcap_error_message();
	}
}, 10, 2 );

// WPForms fires these on the form after an AJAX submit.
ipkcap_add_form_script( "ipkcapForms.resetOn('wpformsAjaxSubmitFailed wpformsAjaxSubmitSuccess', null, { jquery: true });" );
