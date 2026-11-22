<?php
/**
 * MailPoet subscription forms. Loaded when MailPoet is active.
 *
 * MailPoet draws the same form markup for every way a form can be shown (shortcode, block,
 * widget, below posts, pop-up, slide-in, fixed bar), but only the shortcode, block and
 * widget pass through a PHP filter. So the widget is added in the browser to every
 * MailPoet subscription form on a page that loads MailPoet's form script, which covers all
 * of them the same way. MailPoet's own CAPTCHA setting is left as it is.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// MailPoet's form script is enqueued while a form is drawn, the pop-up kinds in wp_footer at
// priority 10. Footer scripts are printed at 20, so ours are added in between.
add_action( 'wp_footer', function () {
	if ( ipkcap_integration_active( 'enable_mailpoet' ) && wp_script_is( 'mailpoet_public', 'enqueued' ) ) {
		ipkcap_enqueue_for_forms();
	}
}, 15 );

// The posted token, from MailPoet's form data. MailPoet sends only the form's data[...]
// fields, so the widget names its hidden field data[infopeak-captcha-response].
function ipkcap_mailpoet_token() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- MailPoet checks its own token; the captcha token is its own proof.
	if ( ! isset( $_POST['data'] ) || ! is_array( $_POST['data'] ) || ! isset( $_POST['data'][ IPKCAP_FIELD ] ) || ! is_string( $_POST['data'][ IPKCAP_FIELD ] ) ) {
		return '';
	}
	return sanitize_text_field( wp_unslash( $_POST['data'][ IPKCAP_FIELD ] ) );
	// phpcs:enable
}

// A token can be confirmed once, so the first answer in a request stands.
function ipkcap_mailpoet_passed() {
	if ( ! isset( $GLOBALS['ipkcap_mailpoet_passed'] ) ) {
		$GLOBALS['ipkcap_mailpoet_passed'] = ipkcap_verify_token( ipkcap_mailpoet_token() );
	}
	return $GLOBALS['ipkcap_mailpoet_passed'];
}

// Forms send with AJAX through MailPoet's JSON API. The token is checked there, before
// MailPoet looks at the subscription: a refused attempt then does not count towards
// MailPoet's limit on subscriptions per IP address, so the visitor can try again at once.
// The refusal has the shape of MailPoet's own error responses, so the form shows it in its
// error line.
add_action( 'mailpoet_api_setup', function () {
	if ( ! ipkcap_integration_active( 'enable_mailpoet' ) ) {
		return;
	}
	// phpcs:disable WordPress.Security.NonceVerification -- MailPoet checks its own token after this.
	$request = isset( $_POST['api_version'] ) ? $_POST : $_GET;
	$method  = isset( $request['mailpoet_method'] ) ? $request['mailpoet_method'] : ( isset( $request['method'] ) ? $request['method'] : '' );
	$endpoint = isset( $request['endpoint'] ) ? $request['endpoint'] : '';
	// phpcs:enable
	if ( ! is_string( $endpoint ) || ! is_string( $method ) || 'subscribers' !== trim( $endpoint ) || 'subscribe' !== trim( $method ) ) {
		return;
	}
	if ( ! ipkcap_mailpoet_passed() ) {
		wp_send_json( array( 'errors' => array( array( 'error' => 'bad_request', 'message' => ipkcap_error_message() ) ) ), 400 );
	}
} );

// Every other way into a subscription (the form's fallback without AJAX, which MailPoet
// sends to admin-post.php) passes here, MailPoet's documented point for refusing one:
// before the subscriber is saved and before any confirmation email.
add_action( 'mailpoet_subscription_before_subscribe', function () {
	if ( ipkcap_integration_active( 'enable_mailpoet' ) && ! ipkcap_mailpoet_passed() ) {
		throw new \MailPoet\UnexpectedValueException( esc_html( ipkcap_error_message() ) );
	}
} );

// The widget goes in front of the submit button of each subscription form. MailPoet adds
// mailpoet_form_sending to the form while it sends and takes it off when the answer is in,
// refused or accepted; the token is used up either way.
ipkcap_add_form_script(
	str_replace(
		'%SITEKEY%',
		(string) wp_json_encode( ipkcap_get_settings()['sitekey'] ),
		<<<'JS'
(function () {
	var forms = document.querySelectorAll('form.mailpoet_form_form');
	var sitekey = %SITEKEY%;
	var watch = window.MutationObserver ? new MutationObserver(function (changes) {
		changes.forEach(function (c) {
			var was = (c.oldValue || '').split(/\s+/).indexOf('mailpoet_form_sending') !== -1;
			if (was && !c.target.classList.contains('mailpoet_form_sending')) ipkcapForms.reset(c.target);
		});
	}) : null;
	Array.prototype.forEach.call(forms, function (form) {
		if (form.querySelector('.infopeak-captcha')) return;
		var w = document.createElement('div');
		w.className = 'infopeak-captcha';
		w.setAttribute('data-sitekey', sitekey);
		w.setAttribute('data-response-field-name', 'data[infopeak-captcha-response]');
		w.style.margin = '0 0 12px';
		var submit = form.querySelector('.mailpoet_submit');
		var at = submit ? (submit.closest('.mailpoet_paragraph') || submit) : form.querySelector('.mailpoet_message');
		if (at && at.parentNode) at.parentNode.insertBefore(w, at); else form.appendChild(w);
		if (watch) watch.observe(form, { attributes: true, attributeFilter: ['class'], attributeOldValue: true });
	});
})();
JS
	)
);
