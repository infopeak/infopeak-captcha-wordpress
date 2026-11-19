<?php
/**
 * Fluent Forms. Loaded when Fluent Forms is active.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Conversational forms draw their own one-question-at-a-time interface without the
// submit button hooks below, so they get no widget and are left alone.
function ipkcap_fluent_active( $form ) {
	if ( ! ipkcap_integration_active( 'enable_fluent' ) || ! is_object( $form ) || empty( $form->id ) ) {
		return false;
	}
	if ( is_callable( array( 'FluentForm\App\Helpers\Helper', 'isConversionForm' ) ) && \FluentForm\App\Helpers\Helper::isConversionForm( $form->id ) ) {
		return false;
	}
	return true;
}

// The widget goes right in front of the submit button. A multi-step form (Fluent Forms
// Pro) has its submit button in the navigation of the last step, drawn by step_end.
function ipkcap_fluent_print_widget( $item, $form ) {
	if ( ! ipkcap_fluent_active( $form ) ) {
		return;
	}
	ipkcap_enqueue_for_forms();
	ipkcap_print_widget();
}
add_action( 'fluentform/render_item_submit_button', 'ipkcap_fluent_print_widget', 5, 2 );
add_action( 'fluentform/render_item_step_end', 'ipkcap_fluent_print_widget', 5, 2 );

// Runs in Fluent Forms' own validation, for every submission, before the entry is
// stored and before any notification or integration runs. Fluent Forms sends the form
// fields as one serialized value, so the token is read from the parsed form data.
add_filter( 'fluentform/validation_errors', function ( $errors, $form_data, $form ) {
	if ( ! ipkcap_fluent_active( $form ) ) {
		return $errors;
	}
	// Another field already failed, so nothing is stored: keep the token unspent and the
	// quota untouched.
	if ( ! empty( $errors ) ) {
		return $errors;
	}
	$token = isset( $form_data[ IPKCAP_FIELD ] ) && is_string( $form_data[ IPKCAP_FIELD ] ) ? sanitize_text_field( $form_data[ IPKCAP_FIELD ] ) : '';
	if ( ! ipkcap_verify_token( $token ) ) {
		// No field carries this name, so Fluent Forms shows the message with the form's errors.
		$errors['infopeak-captcha'] = array( ipkcap_error_message() );
	}
	return $errors;
}, 10, 3 );

// Fluent Forms drops every posted value that is not a field of the form or on its list of
// allowed extras (its own captchas are on it). The token is needed for the check above,
// and is taken out again before the entry is stored.
add_filter( 'fluentform/white_listed_fields', function ( $fields ) {
	$fields[] = IPKCAP_FIELD;
	return $fields;
} );

add_filter( 'fluentform/insert_response_data', function ( $form_data ) {
	if ( is_array( $form_data ) ) {
		unset( $form_data[ IPKCAP_FIELD ] );
	}
	return $form_data;
} );

// A refused submission fires fluentform_submission_failed on the form. A form that is
// cleared after a successful submit (instead of hidden) fires fluentform_reset on the
// body with the form; its token is used up as well.
ipkcap_add_form_script( "ipkcapForms.resetOn('fluentform_submission_failed', function (e) { return e.target !== document ? e.target : null; }, { jquery: true });" );
ipkcap_add_form_script( "ipkcapForms.resetOn('fluentform_reset', function (e, form) { return form && form[0]; }, { jquery: true, on: document.body });" );
