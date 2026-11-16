<?php
/**
 * Plugin Name:       InfoPeak Captcha
 * Plugin URI:        https://infopeak.io/products/captcha
 * Description:       Privacy-first, EU-hosted captcha for WordPress. No tracking, no cookies, no image puzzles - protects login, registration, password reset, comments, Contact Form 7, WPForms, Jetpack Forms, Gravity Forms and WooCommerce checkout.
 * Version:           1.3.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            InfoPeak
 * Author URI:        https://infopeak.io
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       infopeak-captcha
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'IPKCAP_VERSION', '1.3.0' );
define( 'IPKCAP_API_ORIGIN', 'https://captcha.infopeak.io' );
define( 'IPKCAP_FIELD', 'infopeak-captcha-response' );

// Translations shipped with the plugin (languages/), for the messages visitors see.
// A language pack from WordPress.org for the same language takes precedence.
add_action( 'init', function () {
	$locale = determine_locale();
	if ( is_readable( WP_LANG_DIR . '/plugins/infopeak-captcha-' . $locale . '.mo' ) ) {
		return;
	}
	$mo = __DIR__ . '/languages/infopeak-captcha-' . $locale . '.mo';
	if ( is_readable( $mo ) ) {
		load_textdomain( 'infopeak-captcha', $mo );
	}
} );

/* -------------------------------------------------------------------------
 * Settings helpers
 * ---------------------------------------------------------------------- */

function ipkcap_get_settings() {
	$defaults = array(
		'sitekey'          => '',
		'secret'           => '',
		'enable_login'     => 1,
		'enable_register'  => 1,
		'enable_lostpw'    => 1,
		'enable_comments'  => 1,
		'skip_logged_in'   => 1,
		// The integrations only do anything when the form plugin is active.
		'enable_cf7'       => 1,
		'enable_wpforms'   => 1,
		'enable_jetpack'   => 1,
		'enable_woo'       => 1,
		// Gravity Forms is also switched on per form (Form Settings), so nothing changes until an admin does that.
		'enable_gf'        => 1,
	);
	$saved = get_option( 'ipkcap_settings', array() );
	return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
}

function ipkcap_is_configured() {
	$s = ipkcap_get_settings();
	return $s['sitekey'] !== '' && $s['secret'] !== '';
}

/* -------------------------------------------------------------------------
 * Widget rendering
 * ---------------------------------------------------------------------- */

// $lang: one of ipkcap_languages() to show the widget in that language instead of the page's.
function ipkcap_widget_html( $lang = '' ) {
	$s    = ipkcap_get_settings();
	$attr = isset( ipkcap_languages()[ $lang ] ) ? ' data-lang="' . esc_attr( $lang ) . '"' : '';
	return '<div class="infopeak-captcha" data-sitekey="' . esc_attr( $s['sitekey'] ) . '"' . $attr . '></div>';
}

// The languages the widget and infopeak.io offer, by InfoPeak's language code, with their own names.
function ipkcap_languages() {
	return array(
		'cs' => 'Čeština',
		'da' => 'Dansk',
		'de' => 'Deutsch',
		'en' => 'English',
		'es' => 'Español',
		'et' => 'Eesti',
		'fi' => 'Suomi',
		'fr' => 'Français',
		'it' => 'Italiano',
		'nl' => 'Nederlands',
		'no' => 'Norsk',
		'pl' => 'Polski',
		'pt' => 'Português',
		'ro' => 'Română',
		'sv' => 'Svenska',
		'uk' => 'Українська',
	);
}

function ipkcap_print_widget() {
	echo ipkcap_widget_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in ipkcap_widget_html().
}

function ipkcap_enqueue_script() {
	// wp_enqueue_script() accepts the $args array from WP 6.3 onwards; older versions expect a bool.
	$args = version_compare( get_bloginfo( 'version' ), '6.3', '>=' )
		? array( 'in_footer' => true, 'strategy' => 'defer' )
		: true;

	wp_enqueue_script(
		'infopeak-captcha',
		IPKCAP_API_ORIGIN . '/infopeak-captcha.js',
		array( 'jquery' ),
		IPKCAP_VERSION,
		$args
	);
}

/* -------------------------------------------------------------------------
 * Server-side verification
 * ---------------------------------------------------------------------- */

function ipkcap_verify_token( $token ) {
	if ( ! is_string( $token ) || $token === '' ) {
		return false;
	}
	$s = ipkcap_get_settings();

	$response = wp_remote_post(
		IPKCAP_API_ORIGIN . '/api/v1/pow/siteverify',
		array(
			'timeout' => 8,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode(
				array(
					'sitekey' => $s['sitekey'],
					'secret'  => $s['secret'],
					'token'   => $token,
				)
			),
		)
	);

	if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
		return false;
	}
	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	// A genuine token that was refused because the monthly quota and its reserve are used up:
	// trying again will not help, so the visitor gets a different message (see ipkcap_error_message).
	$GLOBALS['ipkcap_quota_exceeded'] = isset( $body['quota_exceeded'] ) && $body['quota_exceeded'] === true;
	return isset( $body['valid'] ) && $body['valid'] === true;
}

function ipkcap_posted_token_valid() {
	$token = isset( $_POST[ IPKCAP_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ IPKCAP_FIELD ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- captcha token is its own proof.
	return ipkcap_verify_token( $token );
}

// $lang: a form's own language (one of ipkcap_languages()), when it differs from the
// site's, so a form set to English answers in English on a Danish site.
function ipkcap_error_message( $lang = '' ) {
	if ( ipkcap_is_other_language( $lang ) ) {
		return ipkcap_translate_for(
			empty( $GLOBALS['ipkcap_quota_exceeded'] )
				? 'Captcha verification failed. Please try again.'
				: 'This form cannot check your message right now. Please try again later or contact the site owner.',
			$lang
		);
	}
	if ( ! empty( $GLOBALS['ipkcap_quota_exceeded'] ) ) {
		return __( 'This form cannot check your message right now. Please try again later or contact the site owner.', 'infopeak-captcha' );
	}
	return __( 'Captcha verification failed. Please try again.', 'infopeak-captcha' );
}

// True when $lang is a language InfoPeak offers and not the one the site runs in.
function ipkcap_is_other_language( $lang ) {
	if ( ! is_string( $lang ) || ! isset( ipkcap_languages()[ $lang ] ) ) {
		return false;
	}
	$site = strtolower( substr( determine_locale(), 0, 2 ) );
	if ( 'nb' === $site || 'nn' === $site ) {
		$site = 'no';
	}
	return $lang !== $site;
}

// One of the plugin's messages in another language than the site's. English is the
// language the plugin is written in. Other languages come from a WordPress.org language
// pack or from languages/, and fall back to English when neither has the language.
function ipkcap_translate_for( $text, $lang ) {
	static $catalogs = array();
	$locales = array(
		'cs' => 'cs_CZ', 'da' => 'da_DK', 'de' => 'de_DE', 'es' => 'es_ES', 'et' => 'et', 'fi' => 'fi',
		'fr' => 'fr_FR', 'it' => 'it_IT', 'nl' => 'nl_NL', 'no' => 'nb_NO', 'pl' => 'pl_PL', 'pt' => 'pt_PT',
		'ro' => 'ro_RO', 'sv' => 'sv_SE', 'uk' => 'uk',
	);
	if ( ! isset( $locales[ $lang ] ) || ! class_exists( 'MO' ) ) {
		return $text;
	}
	if ( ! isset( $catalogs[ $lang ] ) ) {
		$catalogs[ $lang ] = false;
		$name              = 'infopeak-captcha-' . $locales[ $lang ] . '.mo';
		foreach ( array( WP_LANG_DIR . '/plugins/' . $name, __DIR__ . '/languages/' . $name ) as $file ) {
			$mo = new MO();
			if ( is_readable( $file ) && $mo->import_from_file( $file ) ) {
				$catalogs[ $lang ] = $mo;
				break;
			}
		}
	}
	return $catalogs[ $lang ] ? $catalogs[ $lang ]->translate( $text ) : $text;
}

/* -------------------------------------------------------------------------
 * Login, registration and lost-password forms (wp-login.php)
 * ---------------------------------------------------------------------- */

add_action( 'login_enqueue_scripts', function () {
	if ( ! ipkcap_is_configured() ) {
		return;
	}
	$s = ipkcap_get_settings();
	if ( $s['enable_login'] || $s['enable_register'] || $s['enable_lostpw'] ) {
		ipkcap_enqueue_script();
	}
} );

add_action( 'login_form', function () {
	$s = ipkcap_get_settings();
	if ( ipkcap_is_configured() && $s['enable_login'] ) {
		ipkcap_print_widget();
	}
} );

add_action( 'register_form', function () {
	$s = ipkcap_get_settings();
	if ( ipkcap_is_configured() && $s['enable_register'] ) {
		ipkcap_print_widget();
	}
} );

add_action( 'lostpassword_form', function () {
	$s = ipkcap_get_settings();
	if ( ipkcap_is_configured() && $s['enable_lostpw'] ) {
		ipkcap_print_widget();
	}
} );

// Login check. Priority 30 runs after the username/password handlers.
add_filter( 'authenticate', function ( $user ) {
	$s = ipkcap_get_settings();
	if ( ! ipkcap_is_configured() || ! $s['enable_login'] ) {
		return $user;
	}
	// Only enforce on an actual interactive login POST, never for cookies, XML-RPC or REST.
	if ( ! isset( $_POST['log'], $_POST['pwd'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		return $user;
	}
	if ( ! ipkcap_posted_token_valid() ) {
		return new WP_Error( 'ipkcap_failed', ipkcap_error_message() );
	}
	return $user;
}, 30 );

// Registration check.
add_filter( 'registration_errors', function ( $errors ) {
	$s = ipkcap_get_settings();
	if ( ipkcap_is_configured() && $s['enable_register'] && ! ipkcap_posted_token_valid() ) {
		$errors->add( 'ipkcap_failed', ipkcap_error_message() );
	}
	return $errors;
} );

// Lost-password check.
add_action( 'lostpassword_post', function ( $errors ) {
	$s = ipkcap_get_settings();
	if ( ipkcap_is_configured() && $s['enable_lostpw'] && ! ipkcap_posted_token_valid() ) {
		$errors->add( 'ipkcap_failed', ipkcap_error_message() );
	}
} );

/* -------------------------------------------------------------------------
 * Comments
 * ---------------------------------------------------------------------- */

function ipkcap_comments_active() {
	$s = ipkcap_get_settings();
	if ( ! ipkcap_is_configured() || ! $s['enable_comments'] ) {
		return false;
	}
	if ( $s['skip_logged_in'] && is_user_logged_in() ) {
		return false;
	}
	return true;
}

add_action( 'wp_enqueue_scripts', function () {
	if ( ipkcap_comments_active() && ( is_singular() && comments_open() ) ) {
		ipkcap_enqueue_script();
	}
} );

add_filter( 'comment_form_submit_field', function ( $submit_field ) {
	if ( ! ipkcap_comments_active() ) {
		return $submit_field;
	}
	return ipkcap_widget_html() . $submit_field;
} );

add_filter( 'preprocess_comment', function ( $commentdata ) {
	// Pingbacks and trackbacks carry no captcha token.
	$type = isset( $commentdata['comment_type'] ) ? $commentdata['comment_type'] : '';
	if ( in_array( $type, array( 'pingback', 'trackback' ), true ) ) {
		return $commentdata;
	}
	$s = ipkcap_get_settings();
	if ( ! ipkcap_is_configured() || ! $s['enable_comments'] ) {
		return $commentdata;
	}
	if ( $s['skip_logged_in'] && is_user_logged_in() ) {
		return $commentdata;
	}
	if ( ! ipkcap_posted_token_valid() ) {
		wp_die(
			esc_html( ipkcap_error_message() ),
			esc_html__( 'Comment blocked', 'infopeak-captcha' ),
			array( 'response' => 400, 'back_link' => true )
		);
	}
	return $commentdata;
} );

/* -------------------------------------------------------------------------
 * Form plugins: Contact Form 7, WPForms, Jetpack Forms, WooCommerce checkout
 *
 * All three submit with JavaScript and keep the visitor on the page. A token
 * can only be verified once, so after every submit the widget is asked for a
 * fresh one (infopeakCaptcha.reset), otherwise the next attempt would fail.
 * ---------------------------------------------------------------------- */

function ipkcap_integration_active( $key ) {
	$s = ipkcap_get_settings();
	return ipkcap_is_configured() && ! empty( $s[ $key ] );
}

// Load the widget script plus a small listener that resets the widget after
// the form plugin's own AJAX submit. Called while the form is rendered.
// With $with_script = false only the listener is added, for forms where the
// widget script is already on the page (for example pasted in by hand).
function ipkcap_enqueue_for_forms( $with_script = true ) {
	if ( $with_script ) {
		ipkcap_enqueue_script();
	}
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	$js = <<<'JS'
(function () {
	function reset(el) {
		if (!window.infopeakCaptcha || !el) return;
		var w = el.querySelector ? el.querySelector('.infopeak-captcha') : null;
		window.infopeakCaptcha.reset(w || undefined);
	}
	// Contact Form 7 fires this on the form after every submit, sent or not.
	document.addEventListener('wpcf7submit', function (e) { reset(e.target); });
	if (window.jQuery) {
		// WPForms (AJAX forms) and the WooCommerce classic checkout.
		jQuery(document).on('wpformsAjaxSubmitFailed wpformsAjaxSubmitSuccess', function (e) { reset(e.target); });
		jQuery(document.body).on('checkout_error', function () { reset(document.querySelector('form.checkout')); });
	}
	// Jetpack Forms submit with fetch() and fire no event of their own. Remember
	// which form was sent and ask for a fresh token once that request settles.
	var jpForm = null, jpTimer = null, nativeFetch = window.fetch;
	if (nativeFetch) {
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
				var done = function () { reset(f); };
				p.then(done, done);
			}
			return p;
		};
	}
	// Gravity Forms. With AJAX the form markup is drawn again after a failed submit
	// or a page change, and a widget in new markup has not been started. Running the
	// loader again starts it: the loader only sets up widgets it has not seen before.
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
JS;
	if ( ! $with_script ) {
		// A handle without a file carries the listener on its own.
		wp_register_script( 'infopeak-captcha-reset', false, array(), IPKCAP_VERSION, true );
		wp_enqueue_script( 'infopeak-captcha-reset' );
		wp_add_inline_script( 'infopeak-captcha-reset', $js );
		return;
	}
	wp_add_inline_script( 'infopeak-captcha', $js, 'after' );
}

// --- Contact Form 7 ---

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

// --- WPForms ---

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

// --- Jetpack Forms ---

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

// --- WooCommerce checkout (classic checkout) ---

// Placed after the billing fields: WooCommerce reloads the order review and
// payment area with AJAX, which would leave a fresh, unstarted widget there.
add_action( 'woocommerce_after_checkout_billing_form', function () {
	if ( ! ipkcap_integration_active( 'enable_woo' ) ) {
		return;
	}
	ipkcap_enqueue_for_forms();
	ipkcap_print_widget();
} );

add_action( 'woocommerce_after_checkout_validation', function ( $data, $errors ) {
	if ( ipkcap_integration_active( 'enable_woo' ) && ! ipkcap_posted_token_valid() ) {
		$errors->add( 'ipkcap_failed', ipkcap_error_message() );
	}
}, 10, 2 );

// --- Gravity Forms ---
//
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

/* -------------------------------------------------------------------------
 * Admin: settings page
 * ---------------------------------------------------------------------- */

add_action( 'admin_menu', function () {
	add_options_page(
		__( 'InfoPeak Captcha', 'infopeak-captcha' ),
		__( 'InfoPeak Captcha', 'infopeak-captcha' ),
		'manage_options',
		'infopeak-captcha',
		'ipkcap_render_settings_page'
	);
} );

add_action( 'admin_init', function () {
	register_setting( 'ipkcap', 'ipkcap_settings', array( 'sanitize_callback' => 'ipkcap_sanitize_settings' ) );
} );

function ipkcap_sanitize_settings( $input ) {
	$input = is_array( $input ) ? $input : array();
	return array(
		'sitekey'         => isset( $input['sitekey'] ) ? sanitize_text_field( $input['sitekey'] ) : '',
		'secret'          => isset( $input['secret'] ) ? sanitize_text_field( $input['secret'] ) : '',
		'enable_login'    => empty( $input['enable_login'] ) ? 0 : 1,
		'enable_register' => empty( $input['enable_register'] ) ? 0 : 1,
		'enable_lostpw'   => empty( $input['enable_lostpw'] ) ? 0 : 1,
		'enable_comments' => empty( $input['enable_comments'] ) ? 0 : 1,
		'skip_logged_in'  => empty( $input['skip_logged_in'] ) ? 0 : 1,
		'enable_cf7'      => empty( $input['enable_cf7'] ) ? 0 : 1,
		'enable_wpforms'  => empty( $input['enable_wpforms'] ) ? 0 : 1,
		'enable_jetpack'  => empty( $input['enable_jetpack'] ) ? 0 : 1,
		'enable_woo'      => empty( $input['enable_woo'] ) ? 0 : 1,
		'enable_gf'       => empty( $input['enable_gf'] ) ? 0 : 1,
	);
}

/**
 * The admin's language as one of the languages infopeak.io offers, or '' when it offers none of them.
 * The links then open the sign-up and partner pages in that language instead of guessing from the browser.
 */
function ipkcap_infopeak_lang() {
	$locale = function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale();
	$code   = strtolower( substr( (string) $locale, 0, 2 ) );
	if ( $code === 'nb' || $code === 'nn' ) {
		$code = 'no';
	}
	return isset( ipkcap_languages()[ $code ] ) ? $code : '';
}

/**
 * Links from the settings page. They carry the admin's language and a plain utm_source tag that says the visit
 * came from this plugin. Nothing about the site or its visitors is added, and nothing is sent until the admin clicks.
 */
function ipkcap_link( $what ) {
	$lang = ipkcap_infopeak_lang();
	if ( $what === 'partner' ) {
		$url = 'https://partner.infopeak.io/' . ( $lang !== '' ? $lang : 'en' ) . '/';
		return add_query_arg( array( 'utm_source' => 'wordpress_plugin', 'utm_medium' => 'plugin', 'utm_content' => 'partner' ), $url );
	}
	if ( $what === 'login' ) {
		// The Captcha console. Someone who is not signed in is asked to sign in and comes back to it.
		return 'https://infopeak.io/' . ( $lang !== '' ? $lang . '/' : '' ) . 'captcha';
	}
	$args = array( 'plan' => 'captcha' );
	if ( $lang !== '' ) {
		$args['lang'] = $lang;
	}
	$args += array( 'utm_source' => 'wordpress_plugin', 'utm_medium' => 'plugin', 'utm_content' => 'settings' );
	return add_query_arg( $args, 'https://accounts.infopeak.io/signup' );
}

function ipkcap_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$s = ipkcap_get_settings();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'InfoPeak Captcha', 'infopeak-captcha' ); ?></h1>
		<p>
			<?php
			printf(
				/* translators: 1: link to create a free InfoPeak Captcha account, 2: link to sign in to the Captcha console */
				esc_html__( 'Privacy-first, EU-hosted captcha. %1$s to get your sitekey and secret, or %2$s if you already have one.', 'infopeak-captcha' ),
				'<a href="' . esc_url( ipkcap_link( 'signup' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Create a free account', 'infopeak-captcha' ) . '</a>',
				'<a href="' . esc_url( ipkcap_link( 'login' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'sign in', 'infopeak-captcha' ) . '</a>'
			);
			?>
		</p>
		<form method="post" action="options.php">
			<?php settings_fields( 'ipkcap' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="ipkcap-sitekey"><?php esc_html_e( 'Sitekey', 'infopeak-captcha' ); ?></label></th>
					<td><input type="text" id="ipkcap-sitekey" name="ipkcap_settings[sitekey]" value="<?php echo esc_attr( $s['sitekey'] ); ?>" class="regular-text" autocomplete="off"></td>
				</tr>
				<tr>
					<th scope="row"><label for="ipkcap-secret"><?php esc_html_e( 'Secret', 'infopeak-captcha' ); ?></label></th>
					<td>
						<input type="password" id="ipkcap-secret" name="ipkcap_settings[secret]" value="<?php echo esc_attr( $s['secret'] ); ?>" class="regular-text" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Server-side only. Never shared with visitors.', 'infopeak-captcha' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Protect', 'infopeak-captcha' ); ?></th>
					<td>
						<fieldset>
							<label><input type="checkbox" name="ipkcap_settings[enable_login]" value="1" <?php checked( $s['enable_login'] ); ?>> <?php esc_html_e( 'Login form', 'infopeak-captcha' ); ?></label><br>
							<label><input type="checkbox" name="ipkcap_settings[enable_register]" value="1" <?php checked( $s['enable_register'] ); ?>> <?php esc_html_e( 'Registration form', 'infopeak-captcha' ); ?></label><br>
							<label><input type="checkbox" name="ipkcap_settings[enable_lostpw]" value="1" <?php checked( $s['enable_lostpw'] ); ?>> <?php esc_html_e( 'Lost password form', 'infopeak-captcha' ); ?></label><br>
							<label><input type="checkbox" name="ipkcap_settings[enable_comments]" value="1" <?php checked( $s['enable_comments'] ); ?>> <?php esc_html_e( 'Comment form', 'infopeak-captcha' ); ?></label>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Form plugins', 'infopeak-captcha' ); ?></th>
					<td>
						<fieldset>
							<?php
							$ipkcap_plugins = array(
								'enable_cf7'     => array( __( 'Contact Form 7', 'infopeak-captcha' ), defined( 'WPCF7_VERSION' ) ),
								'enable_wpforms' => array( __( 'WPForms', 'infopeak-captcha' ), function_exists( 'wpforms' ) ),
								'enable_jetpack' => array( __( 'Jetpack Forms', 'infopeak-captcha' ), defined( 'JETPACK__VERSION' ) ),
								'enable_woo'     => array( __( 'WooCommerce checkout', 'infopeak-captcha' ), class_exists( 'WooCommerce' ) ),
								'enable_gf'      => array( __( 'Gravity Forms', 'infopeak-captcha' ), class_exists( 'GFForms' ) ),
							);
							foreach ( $ipkcap_plugins as $ipkcap_key => $ipkcap_plugin ) :
								?>
								<label><input type="checkbox" name="ipkcap_settings[<?php echo esc_attr( $ipkcap_key ); ?>]" value="1" <?php checked( $s[ $ipkcap_key ] ); ?>> <?php echo esc_html( $ipkcap_plugin[0] ); ?></label>
								<?php if ( ! $ipkcap_plugin[1] ) : ?>
									<span class="description">(<?php esc_html_e( 'not installed', 'infopeak-captcha' ); ?>)</span>
								<?php endif; ?>
								<br>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'The widget is added to every form automatically. In Contact Form 7 you can place it yourself with the [infopeak_captcha] tag.', 'infopeak-captcha' ); ?></p>
							<p class="description"><?php esc_html_e( 'Gravity Forms: turn it on for each form under Form Settings, InfoPeak Captcha. Forms you have not turned it on for are left as they are.', 'infopeak-captcha' ); ?></p>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Logged-in users', 'infopeak-captcha' ); ?></th>
					<td>
						<label><input type="checkbox" name="ipkcap_settings[skip_logged_in]" value="1" <?php checked( $s['skip_logged_in'] ); ?>> <?php esc_html_e( 'Skip the captcha on comments from logged-in users', 'infopeak-captcha' ); ?></label>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<hr>
		<h2><?php esc_html_e( 'Testing', 'infopeak-captcha' ); ?></h2>
		<p>
			<?php esc_html_e( 'To try the plugin before creating a key, use the public test credentials. They work on any domain and never count against a quota. Never use them in production.', 'infopeak-captcha' ); ?>
		</p>
		<p>
			<code>ipk_test_sitekey</code> / <code>ipk_test_secret</code>
		</p>
		<hr>
		<h2><?php esc_html_e( 'Building websites for clients?', 'infopeak-captcha' ); ?></h2>
		<p>
			<?php esc_html_e( 'With the InfoPeak partner programme, agencies create a Captcha account for each client, see every client\'s usage in one console and receive one invoice for all of them, at partner prices.', 'infopeak-captcha' ); ?>
			<a href="<?php echo esc_url( ipkcap_link( 'partner' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'About the partner programme', 'infopeak-captcha' ); ?></a>
		</p>
	</div>
	<?php
}

// Settings shortcut on the Plugins screen.
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	$url = admin_url( 'options-general.php?page=infopeak-captcha' );
	array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'infopeak-captcha' ) . '</a>' );
	return $links;
} );

// Gentle nudge until keys are entered.
add_action( 'admin_notices', function () {
	if ( ipkcap_is_configured() || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( $screen && $screen->id === 'settings_page_infopeak-captcha' ) {
		return;
	}
	printf(
		'<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
		esc_html__( 'InfoPeak Captcha is installed but not active yet.', 'infopeak-captcha' ),
		esc_url( admin_url( 'options-general.php?page=infopeak-captcha' ) ),
		esc_html__( 'Enter your sitekey and secret to enable it.', 'infopeak-captcha' )
	);
} );
