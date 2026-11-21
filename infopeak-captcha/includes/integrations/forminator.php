<?php
/**
 * Forminator custom forms. Loaded when Forminator is active. Polls and quizzes are
 * left alone.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The button filters below are shared with polls and quizzes and carry no form, so the
// form being drawn is remembered here. Forminator calls these around every form it renders.
add_action( 'forminator_before_form_render', function ( $id, $form_type ) {
	$GLOBALS['ipkcap_forminator_form'] = is_string( $form_type ) && 0 === strpos( $form_type, 'custom-' ) ? (int) $id : 0;
}, 10, 2 );

add_action( 'forminator_after_form_render', function () {
	$GLOBALS['ipkcap_forminator_form'] = 0;
} );

function ipkcap_forminator_drawing_form() {
	return ! empty( $GLOBALS['ipkcap_forminator_form'] ) && ipkcap_integration_active( 'enable_forminator' );
}

// One-page form: the widget goes in front of the row with the submit button.
add_filter( 'forminator_render_button_markup', function ( $html ) {
	// The PayPal button and a quiz's start button are not the form's submit button.
	if ( ! ipkcap_forminator_drawing_form() || ! is_string( $html ) || false === strpos( $html, 'forminator-button-submit' ) || false !== strpos( $html, 'forminator-quiz-start' ) ) {
		return $html;
	}
	ipkcap_enqueue_for_forms();
	return '<div class="forminator-row ipkcap-forminator-row"><div class="forminator-col"><div class="forminator-field">' . ipkcap_widget_html() . '</div></div></div>' . $html;
} );

// Form with pages: the submit button sits in the footer next to Previous and Next, and
// Forminator only shows it on the last page. The widget is shown together with it.
add_filter( 'forminator_pagination_submit_markup', function ( $html ) {
	if ( ! ipkcap_forminator_drawing_form() || ! is_string( $html ) ) {
		return $html;
	}
	ipkcap_enqueue_for_forms();
	return '<div class="ipkcap-forminator-paged">' . ipkcap_widget_html() . '</div>' . $html;
} );

// Runs in Forminator's own check of the submission, before anything is stored and before
// any email or integration. Forminator runs this check twice per submission (fields, then
// uploads); the first answer stands for the request.
add_filter( 'forminator_custom_form_submit_errors', function ( $errors, $form_id ) {
	$form_id = (int) $form_id;
	if ( ! $form_id || ! ipkcap_integration_active( 'enable_forminator' ) ) {
		return $errors;
	}
	// Another field already failed, so nothing is stored: keep the token unspent.
	if ( ! empty( $errors ) ) {
		return $errors;
	}
	if ( ipkcap_forminator_is_draft( $form_id ) ) {
		return $errors;
	}
	if ( ! isset( $GLOBALS['ipkcap_forminator_passed'][ $form_id ] ) ) {
		$GLOBALS['ipkcap_forminator_passed'][ $form_id ] = ipkcap_posted_token_valid();
	}
	if ( ! $GLOBALS['ipkcap_forminator_passed'][ $form_id ] ) {
		$errors   = is_array( $errors ) ? $errors : array();
		$errors[] = array( 'infopeak-captcha' => ipkcap_error_message() );
		$GLOBALS['ipkcap_forminator_refused'] = true;
	}
	return $errors;
}, 10, 2 );

// "Save and continue later" and abandoned-form saves only store a draft that is not sent
// on, the same way Forminator itself decides it: the flag is posted and the form allows it.
function ipkcap_forminator_is_draft( $form_id ) {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- Forminator checks its own nonce.
	$draft     = isset( $_POST['save_draft'] ) && filter_var( wp_unslash( $_POST['save_draft'] ), FILTER_VALIDATE_BOOLEAN );
	$abandoned = ! empty( $_POST['abandoned_form'] );
	// phpcs:enable
	if ( ! $draft && ! $abandoned ) {
		return false;
	}
	$model    = class_exists( 'Forminator_Base_Form_Model' ) ? Forminator_Base_Form_Model::get_model( $form_id ) : null;
	$settings = is_object( $model ) && method_exists( $model, 'get_form_settings' ) ? $model->get_form_settings() : array();
	if ( $draft && ! empty( $settings['use_save_and_continue'] ) && filter_var( $settings['use_save_and_continue'], FILTER_VALIDATE_BOOLEAN ) ) {
		return true;
	}
	return $abandoned && ! empty( $settings['abandonment'] ) && function_exists( 'forminator_form_abandonment_disabled' ) && ! forminator_form_abandonment_disabled();
}

// Forminator shows one message above the form for a refused submission; make it ours.
add_filter( 'forminator_custom_form_invalid_form_message', function ( $message ) {
	return empty( $GLOBALS['ipkcap_forminator_refused'] ) ? $message : ipkcap_error_message();
} );

// Forminator sends with AJAX and fires these on the form. After a refusal, and after a
// success when the form stays on the page, the widget needs a fresh token. On a form with
// pages the widget is hidden until Forminator shows the submit button on the last page.
ipkcap_add_form_script( "ipkcapForms.resetOn('forminator:form:submit:failed forminator:form:submit:success', null, { jquery: true });" );
ipkcap_add_form_script(
	<<<'JS'
(function () {
	var css = document.createElement('style');
	css.textContent = '.forminator-pagination-footer .ipkcap-forminator-paged:has(+ .forminator-pagination-submit[style*="none"]){display:none}.ipkcap-forminator-paged{margin-bottom:16px}';
	document.head.appendChild(css);
})();
JS
);
