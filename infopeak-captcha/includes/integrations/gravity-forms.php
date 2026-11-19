<?php
/**
 * Gravity Forms. Loaded when Gravity Forms is active.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Off for every form until an admin turns it on under Form Settings, so a site can
// move one form at a time from another captcha. The hooks below only run when
// Gravity Forms is active.

function ipkcap_gf_active( $form ) {
	return ipkcap_integration_active( 'enable_gf' ) && is_array( $form ) && ! empty( $form['ipkcap_enabled'] );
}

// The language chosen for the form under Form Settings, or '' for the page's.
function ipkcap_gf_lang( $form ) {
	return isset( $form['ipkcap_lang'] ) ? (string) $form['ipkcap_lang'] : '';
}

add_filter( 'gform_form_settings_fields', function ( $fields, $form ) {
	if ( ! ipkcap_integration_active( 'enable_gf' ) ) {
		return $fields;
	}
	$choices = array( array( 'label' => __( 'The page\'s language', 'infopeak-captcha' ), 'value' => '' ) );
	foreach ( ipkcap_languages() as $code => $name ) {
		$choices[] = array( 'label' => $name, 'value' => $code );
	}
	$fields['ipkcap'] = array(
		'title'  => __( 'InfoPeak Captcha', 'infopeak-captcha' ),
		'fields' => array(
			array(
				'name'  => 'ipkcap_enabled',
				'type'  => 'toggle',
				'label' => __( 'Protect this form with InfoPeak Captcha', 'infopeak-captcha' ),
			),
			array(
				'name'          => 'ipkcap_lang',
				'type'          => 'select',
				'label'         => __( 'Captcha language', 'infopeak-captcha' ),
				'description'   => __( 'Only needed when the form is in another language than the page it is on.', 'infopeak-captcha' ),
				'choices'       => $choices,
				'default_value' => '',
			),
		),
	);
	return $fields;
}, 10, 2 );

// The widget goes in front of the submit button, which on a multi-page form is on
// the last page. A form that already has the widget in an HTML field keeps that one.
add_filter( 'gform_submit_button', function ( $button, $form ) {
	if ( ! ipkcap_gf_active( $form ) ) {
		return $button;
	}
	ipkcap_enqueue_for_forms();
	foreach ( (array) rgar( $form, 'fields' ) as $field ) {
		if ( isset( $field->content ) && is_string( $field->content ) && strpos( $field->content, 'infopeak-captcha' ) !== false ) {
			return $button;
		}
	}
	return ipkcap_widget_html( ipkcap_gf_lang( $form ) ) . $button;
}, 10, 2 );

// Runs in Gravity Forms' own validation, before the entry is saved and before any
// notification or add-on feed (payment add-ons validate at a later priority).
add_filter( 'gform_validation', function ( $result, $context = 'form-submit' ) {
	$form = isset( $result['form'] ) ? $result['form'] : null;
	// api-validate only checks the values and saves nothing.
	if ( ! ipkcap_gf_active( $form ) || 'api-validate' === $context ) {
		return $result;
	}
	// Moving between pages saves nothing; the token is checked when the form is sent.
	// Gravity Forms decides that the same way, including pages hidden by conditional logic.
	if ( is_callable( array( 'GFFormDisplay', 'is_last_page' ) ) && ! GFFormDisplay::is_last_page( $form ) ) {
		return $result;
	}
	// Another field already failed, so nothing is saved: keep the token unspent and
	// the quota untouched. The visitor gets a fresh token for the next attempt.
	if ( empty( $result['is_valid'] ) ) {
		return $result;
	}
	// A token can be confirmed once. Should Gravity Forms validate twice in the same
	// request, the first answer stands; it is never kept beyond this request.
	if ( ! empty( $GLOBALS['ipkcap_gf_passed'][ (int) $form['id'] ] ) ) {
		return $result;
	}
	if ( ipkcap_posted_token_valid() ) {
		$GLOBALS['ipkcap_gf_passed'][ (int) $form['id'] ] = true;
	} else {
		$result['is_valid'] = false;
		$GLOBALS['ipkcap_gf_failed'][ (int) $form['id'] ] = ipkcap_error_message( ipkcap_gf_lang( $form ) );
	}
	return $result;
}, 9, 2 );

// Second check, after validation and before the entry is stored and notifications go
// out. Gravity Forms (2.7 and later) runs this filter for every final submission,
// including GFAPI::submit_form(), whatever the page fields say, and
// never when moving between pages. Normally the token was already confirmed above;
// if that check was skipped for any reason, it is done now. A refused submission ends
// with Gravity Forms' own confirmation response, which here carries the error, so it
// also shows in AJAX forms. Save and Continue only stores a draft and is left alone.
add_filter( 'gform_abort_submission_with_confirmation', function ( $abort, $form ) {
	if ( $abort || ! ipkcap_gf_active( $form ) || ! empty( $_POST['gform_save'] ) || ! empty( $GLOBALS['ipkcap_gf_passed'][ (int) $form['id'] ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Gravity Forms' own flag.
		return $abort;
	}
	if ( ipkcap_posted_token_valid() ) {
		$GLOBALS['ipkcap_gf_passed'][ (int) $form['id'] ] = true;
		return $abort;
	}
	$GLOBALS['ipkcap_gf_refused'][ (int) $form['id'] ] = ipkcap_error_message( ipkcap_gf_lang( $form ) );
	return true;
}, 10, 2 );

add_filter( 'gform_confirmation', function ( $confirmation, $form ) {
	$id = isset( $form['id'] ) ? (int) $form['id'] : 0;
	if ( empty( $GLOBALS['ipkcap_gf_refused'][ $id ] ) ) {
		return $confirmation;
	}
	return '<div id="gform_confirmation_wrapper_' . $id . '" class="gform_confirmation_wrapper"><div id="gform_confirmation_message_' . $id . '" class="gform_confirmation_message ipkcap-gf-error" role="alert">'
		. esc_html( $GLOBALS['ipkcap_gf_refused'][ $id ] ) . '</div></div>';
}, 99, 2 );

// Last line of defence for Gravity Forms versions without the filter above: right
// before the entry is stored, an unconfirmed submission stops.
add_action( 'gform_pre_submission', function ( $form ) {
	if ( ! ipkcap_gf_active( $form ) || ! empty( $GLOBALS['ipkcap_gf_passed'][ (int) $form['id'] ] ) ) {
		return;
	}
	if ( ipkcap_posted_token_valid() ) {
		$GLOBALS['ipkcap_gf_passed'][ (int) $form['id'] ] = true;
		return;
	}
	wp_die(
		esc_html( ipkcap_error_message( ipkcap_gf_lang( $form ) ) ),
		esc_html( ipkcap_is_other_language( ipkcap_gf_lang( $form ) ) ? ipkcap_translate_for( 'Form not sent', ipkcap_gf_lang( $form ) ) : __( 'Form not sent', 'infopeak-captcha' ) ),
		array( 'response' => 400, 'back_link' => true )
	);
}, 1 );

// No field is at fault, so the reason goes under the form's own error summary.
add_filter( 'gform_validation_message', function ( $message, $form ) {
	$id = isset( $form['id'] ) ? (int) $form['id'] : 0;
	if ( empty( $GLOBALS['ipkcap_gf_failed'][ $id ] ) ) {
		return $message;
	}
	return $message . '<p class="ipkcap-gf-error" role="alert">' . esc_html( $GLOBALS['ipkcap_gf_failed'][ $id ] ) . '</p>';
}, 10, 2 );

// With AJAX, Gravity Forms draws the form markup again after a failed submit or a page
// change, and a widget in new markup has not been started. Running the loader again starts
// it: the loader only sets up widgets it has not seen before.
ipkcap_add_form_script(
	<<<'JS'
(function () {
	var gfLoader = null;
	function gfStart(id) {
		var f = document.getElementById('gform_' + id), w = f && f.querySelector('.infopeak-captcha');
		var tag = document.getElementById('infopeak-captcha-js');
		if (!w || w.classList.contains('ipk-cap') || gfLoader || !tag || !tag.src) return;
		gfLoader = document.createElement('script');
		gfLoader.src = tag.src;
		gfLoader.onload = gfLoader.onerror = function () { gfLoader.remove(); gfLoader = null; };
		document.body.appendChild(gfLoader);
	}
	if (window.jQuery) {
		jQuery(document).on('gform_post_render', function (e, id) { gfStart(id); });
	}
	document.addEventListener('gform/post_render', function (e) { if (e.detail) gfStart(e.detail.formId); });
	var gfHooked = false;
	function gfHooks() {
		var u = window.gform && window.gform.utils;
		if (gfHooked || !u || !u.addAsyncFilter) return;
		gfHooked = true;
		// Hold the submit until the widget has a token, so a quick click is not refused.
		u.addAsyncFilter('gform/submission/pre_submission', function (data) {
			var f = data && data.form, w = f && f.querySelector && f.querySelector('.infopeak-captcha');
			// A widget that never started (script blocked) cannot deliver a token; the server says so.
			if (!w || !window.infopeakCaptcha || !w.classList.contains('ipk-cap') || data.submissionType !== 'submit') return data;
			var token = function () { var i = f.querySelector('input[name="infopeak-captcha-response"]'); return i && i.value; };
			if (token()) return data;
			if (!w.classList.contains('is-solving')) window.infopeakCaptcha.reset(w);
			return new Promise(function (resolve) {
				var waited = 0, timer = setInterval(function () {
					waited += 200;
					if (token() || waited >= 20000) { clearInterval(timer); resolve(data); }
				}, 200);
			});
		});
		// The token is used up by the submit. A widget still on the page needs a new one;
		// one that was replaced with new markup is started by gfStart instead.
		u.addAsyncFilter('gform/ajax/post_ajax_submission', function (data) {
			var f = data && data.form;
			if (f && !f.querySelector) f = document.getElementById('gform_' + f.id);
			var w = f && f.querySelector('.infopeak-captcha');
			setTimeout(function () { if (window.infopeakCaptcha && w && w.isConnected && w.classList.contains('ipk-cap')) window.infopeakCaptcha.reset(w); }, 0);
			return data;
		});
	}
	gfHooks();
	document.addEventListener('gform/theme/scripts_loaded', gfHooks);
	document.addEventListener('DOMContentLoaded', gfHooks);
})();
JS
);
