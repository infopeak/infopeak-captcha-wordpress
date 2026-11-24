<?php
/**
 * Mailchimp for WordPress (MC4WP) sign-up forms. Loaded when MC4WP is active.
 *
 * Every way MC4WP shows a form (shortcode, block, widget) draws it through the
 * mc4wp_form_content filter, and every submission passes mc4wp_form_errors before anything
 * is sent to Mailchimp.
 *
 * MC4WP may come with its own InfoPeak Captcha field (MC4WP > Integrations > InfoPeak
 * Captcha). A form that has that field, while it is switched on there, is left to MC4WP:
 * no second widget and no second check.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// True when MC4WP's own InfoPeak Captcha field protects this form.
function ipkcap_mc4wp_has_own_captcha( $form ) {
	if ( ! is_object( $form ) || ! isset( $form->content ) || ! is_string( $form->content ) || false === strpos( $form->content, 'name="infopeak-captcha"' ) ) {
		return false;
	}
	if ( ! class_exists( 'MC4WP_InfoPeak_Captcha' ) ) {
		return false;
	}
	$own = is_callable( array( 'MC4WP_InfoPeak_Captcha', 'get_instance' ) ) ? MC4WP_InfoPeak_Captcha::get_instance() : null;
	if ( is_object( $own ) && method_exists( $own, 'is_enabled' ) ) {
		return (bool) $own->is_enabled();
	}
	// The setting MC4WP keeps it under, should the class look different in another version.
	$settings = get_option( 'mc4wp_integrations', array() );
	return is_array( $settings ) && isset( $settings['infopeak-captcha']['enabled'] ) && '1' === (string) $settings['infopeak-captcha']['enabled'];
}

function ipkcap_mc4wp_active( $form ) {
	return ipkcap_integration_active( 'enable_mc4wp' ) && ! ipkcap_mc4wp_has_own_captcha( $form );
}

// The widget goes in front of the submit button, inside the paragraph that holds it if there
// is one, or at the end of the form when it has no submit button. After MC4WP's own fields
// (priority 10), so a widget already in the form is seen and not added twice.
add_filter( 'mc4wp_form_content', function ( $html, $form = null ) {
	if ( ! is_string( $html ) || ! ipkcap_mc4wp_active( $form ) ) {
		return $html;
	}
	ipkcap_enqueue_for_forms();
	if ( false !== strpos( $html, 'class="infopeak-captcha' ) ) {
		return $html;
	}
	$widget = '<div class="ipkcap-mc4wp" style="margin:0 0 1em">' . ipkcap_widget_html() . '</div>';
	if ( ! preg_match( '~<input\b[^>]*\btype\s*=\s*["\']?(?:submit|image)\b|<button\b(?![^>]*\btype\s*=\s*["\']?(?:button|reset)\b)~i', $html, $m, PREG_OFFSET_CAPTURE ) ) {
		return $html . $widget;
	}
	$at = $m[0][1];
	if ( preg_match_all( '~<p\b~i', substr( $html, 0, $at ), $p, PREG_OFFSET_CAPTURE ) ) {
		$open = end( $p[0] )[1];
		if ( false === stripos( substr( $html, $open, $at - $open ), '</p>' ) ) {
			$at = $open;
		}
	}
	return substr( $html, 0, $at ) . $widget . substr( $html, $at );
}, 20, 2 );

// Before MC4WP sends anything to Mailchimp. When MC4WP already refused the submission for
// another reason, nothing is sent: the token is kept unspent and the visitor sees one message.
add_filter( 'mc4wp_form_errors', function ( $errors, $form = null ) {
	if ( ! ipkcap_mc4wp_active( $form ) || ! empty( $errors ) ) {
		return $errors;
	}
	if ( ! ipkcap_posted_token_valid() ) {
		// The message is set here, after the check, so a used-up quota gets its own text.
		if ( is_object( $form ) && isset( $form->messages ) && is_array( $form->messages ) ) {
			$form->messages['ipkcap_failed'] = esc_html( ipkcap_error_message() );
		}
		$errors   = is_array( $errors ) ? $errors : array();
		$errors[] = 'ipkcap_failed';
	}
	return $errors;
}, 20, 2 );

// The token is not a sign-up field: keep it out of the data MC4WP sends to Mailchimp.
add_filter( 'mc4wp_form_ignored_field_names', function ( $names ) {
	$names   = is_array( $names ) ? $names : array();
	$names[] = IPKCAP_FIELD;
	return $names;
} );

// Forms sent without reloading the page (MC4WP Premium's AJAX forms) fire "submitted" after
// every answer, and the widget needs a fresh token. A form sent the normal way comes back
// as a new page with a fresh widget, and MC4WP fires the same event while that page loads:
// that one is left alone. MC4WP defines mc4wp.forms.on early in the page.
ipkcap_add_form_script(
	<<<'JS'
(function () {
	var loaded = false;
	window.addEventListener('load', function () { setTimeout(function () { loaded = true; }, 0); });
	if (window.mc4wp && window.mc4wp.forms && typeof window.mc4wp.forms.on === 'function') {
		window.mc4wp.forms.on('submitted', function (form) {
			if (loaded && form && form.element) ipkcapForms.reset(form.element);
		});
	}
})();
JS
);
