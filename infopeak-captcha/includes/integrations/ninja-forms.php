<?php
/**
 * Ninja Forms. Loaded when Ninja Forms is active.
 *
 * Ninja Forms draws its forms in the browser from field types and checks every submission
 * field by field on the server. Its own captchas (reCAPTCHA, Turnstile, hCaptcha) are field
 * types, and so is this one: "InfoPeak Captcha", added to a form in the form builder like
 * any other field. Ninja Forms then runs its check in its own validation and shows the
 * message under the field. Forms without the field are left as they are.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'ninja_forms_register_fields', function ( $fields ) {
	if ( class_exists( 'NF_Abstracts_Field' ) && is_array( $fields ) ) {
		require_once __DIR__ . '/ninja-forms-field.php';
		$fields['infopeak_captcha'] = new IPKCAP_Ninja_Forms_Field();
	}
	return $fields;
} );

// Ninja Forms prints the field templates it needs from these folders.
add_filter( 'ninja_forms_field_template_file_paths', function ( $paths ) {
	$paths   = is_array( $paths ) ? $paths : array();
	$paths[] = __DIR__ . '/ninja-forms/';
	return $paths;
} );

// Ninja Forms draws the form after the page has loaded, so the widget script is run again
// for widgets it has not started (it leaves started ones alone). The widget keeps its token
// in the form, outside the field's own markup that Ninja Forms redraws; it is handed to the
// field when the form is sent, as Ninja Forms sends its field values, not the form's inputs.
// After Ninja Forms' answer, refused or accepted, the widget gets a fresh token.
ipkcap_add_form_script(
	<<<'JS'
(function () {
	if (!window.jQuery) return;
	var loader = null;
	function startNew() {
		var tag = document.getElementById('infopeak-captcha-js');
		if (loader || !tag || !tag.src || !document.querySelector('.nf-form-cont .infopeak-captcha:not(.ipk-cap)')) return;
		loader = document.createElement('script');
		loader.src = tag.src;
		loader.onload = loader.onerror = function () { loader.remove(); loader = null; };
		document.body.appendChild(loader);
	}
	jQuery(document).on('nfFormReady', startNew);
	jQuery(function () {
		if (!window.nfRadio) return;
		// Before Ninja Forms checks the fields: the current token, and the previous refusal taken
		// off the field, or Ninja Forms would not send the form again.
		nfRadio.channel('forms').on('before:submit', function (form) {
			var fields = form && form.get('fields');
			if (!fields) return;
			fields.where({ type: 'infopeak_captcha' }).forEach(function (field) {
				var w = document.querySelector('#nf-field-' + field.get('id') + '-wrap .infopeak-captcha');
				var el = w && w.closest('form');
				var input = el && el.querySelector('input[name="infopeak-captcha-response"]');
				nfRadio.channel('fields').request('remove:error', field.get('id'), 'required-error');
				field.set('value', input ? input.value : '', { silent: true });
			});
		});
		nfRadio.channel('forms').on('submit:response', function (response, status, xhr, formID) {
			startNew();
			ipkcapForms.reset(document.getElementById('nf-form-' + formID + '-cont'));
		});
	});
})();
JS
);
