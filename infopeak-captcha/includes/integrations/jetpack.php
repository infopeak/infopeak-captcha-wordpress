<?php
/**
 * Jetpack Forms. Loaded when Jetpack (or its Forms package) is active.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Add the widget in front of the submit button, unless the form already has it
// (for example pasted in by hand), in which case it is left alone.
add_filter( 'jetpack_contact_form_html', function ( $html ) {
	if ( ! ipkcap_integration_active( 'enable_jetpack' ) ) {
		return $html;
	}
	if ( strpos( $html, 'infopeak-captcha' ) !== false ) {
		ipkcap_enqueue_for_forms( false );
		return $html;
	}
	ipkcap_enqueue_for_forms();
	$widget = ipkcap_widget_html();
	$count  = 0;
	// Jetpack's newer form block has the submit button as an inner block, the classic form builds <p class='contact-submit'>.
	foreach ( array( '/(<div class="wp-block-button)/', "/(<p class='contact-submit'>)/", '/(<\/form>)/i' ) as $pattern ) {
		$html = preg_replace( $pattern, $widget . '$1', $html, 1, $count );
		if ( $count ) {
			break;
		}
	}
	return $html;
} );

// Jetpack aborts the submission and shows the message when a WP_Error is returned.
add_filter( 'jetpack_contact_form_is_spam', function ( $is_spam ) {
	if ( ! ipkcap_integration_active( 'enable_jetpack' ) || is_wp_error( $is_spam ) ) {
		return $is_spam;
	}
	if ( ! ipkcap_posted_token_valid() ) {
		return new WP_Error( 'ipkcap_failed', ipkcap_error_message() );
	}
	return $is_spam;
} );

// Jetpack Forms submit with fetch() and fire no event of their own. Remember which form
// was sent and ask for a fresh token once that request settles.
ipkcap_add_form_script(
	<<<'JS'
(function () {
	var jpForm = null, jpTimer = null, nativeFetch = window.fetch;
	if (!nativeFetch) return;
	document.addEventListener('submit', function (e) {
		var f = e.target;
		if (!f || !f.querySelector || !f.querySelector('input[name="action"][value="grunion-contact-form"]') || !f.querySelector('.infopeak-captcha')) return;
		jpForm = f;
		clearTimeout(jpTimer);
		jpTimer = setTimeout(function () { jpForm = null; }, 5000);
	}, true);
	window.fetch = function () {
		var p = nativeFetch.apply(this, arguments), f = jpForm;
		if (f) {
			jpForm = null;
			var done = function () { ipkcapForms.reset(f); };
			p.then(done, done);
		}
		return p;
	};
})();
JS
);
