<?php
/**
 * Formidable Forms. Loaded when Formidable Forms is active.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Right in front of the submit button. Formidable fires this for the form's own submit
// button and for a Submit field, whatever the button's custom HTML looks like.
add_action( 'frm_before_submit_btn', function () {
	if ( ! ipkcap_integration_active( 'enable_formidable' ) ) {
		return;
	}
	ipkcap_enqueue_for_forms();
	ipkcap_print_widget();
} );

// Runs in Formidable's own validation (FrmEntryValidate::validate), for normal and AJAX
// submissions alike, before the entry is created and before any email or other action.
add_filter( 'frm_validate_entry', function ( $errors, $values ) {
	$form_id = is_array( $values ) && isset( $values['form_id'] ) ? (int) $values['form_id'] : 0;
	if ( ! $form_id || ! ipkcap_integration_active( 'enable_formidable' ) ) {
		return $errors;
	}
	// Entries added or edited in wp-admin have no widget.
	if ( is_admin() && ! wp_doing_ajax() ) {
		return $errors;
	}
	// Another field already failed, so nothing is saved: keep the token unspent and the
	// quota untouched.
	if ( ! empty( $errors ) ) {
		return $errors;
	}
	// A token can be confirmed once. Should Formidable validate twice in the same request,
	// the first answer stands; it is never kept beyond this request.
	if ( ! empty( $GLOBALS['ipkcap_frm_passed'][ $form_id ] ) ) {
		return $errors;
	}
	if ( ipkcap_posted_token_valid() ) {
		$GLOBALS['ipkcap_frm_passed'][ $form_id ] = true;
	} else {
		// Not a field ID, so Formidable shows it with the errors for the whole form.
		$errors['infopeak-captcha'] = ipkcap_error_message();
	}
	return $errors;
}, 10, 2 );

// After a refused AJAX submit Formidable fires frmFormErrors on the document with the
// form. When it draws the form again after a submit (for example "show the form" after
// the message), the widget in the new markup has not been started; running the loader
// again starts it, as the loader only sets up widgets it has not seen before.
ipkcap_add_form_script(
	<<<'JS'
(function () {
	ipkcapForms.resetOn('frmFormErrors', function (e, form) { return form; }, { jquery: true });
	var loader = null;
	function startNew() {
		var tag = document.getElementById('infopeak-captcha-js');
		if (loader || !tag || !tag.src || !document.querySelector('.frm_forms .infopeak-captcha:not(.ipk-cap)')) return;
		loader = document.createElement('script');
		loader.src = tag.src;
		loader.onload = loader.onerror = function () { loader.remove(); loader = null; };
		document.body.appendChild(loader);
	}
	if (window.jQuery) jQuery(document).on('frmFormComplete', startNew);
})();
JS
);
