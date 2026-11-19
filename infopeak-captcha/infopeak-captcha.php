<?php
/**
 * Plugin Name:       InfoPeak Captcha
 * Plugin URI:        https://infopeak.io/products/captcha
 * Description:       Privacy-first, EU-hosted captcha for WordPress. No tracking, no cookies, no image puzzles - protects login, registration, password reset, comments, Contact Form 7, WPForms, Jetpack Forms, Gravity Forms, Fluent Forms and WooCommerce checkout.
 * Version:           1.4.0
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

define( 'IPKCAP_VERSION', '1.4.0' );
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
		'enable_fluent'    => 1,
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
 * Form plugins (includes/integrations/)
 *
 * Most form plugins submit with JavaScript and keep the visitor on the page. A
 * token can only be verified once, so after every submit the widget is asked for
 * a fresh one (infopeakCaptcha.reset), otherwise the next attempt would fail.
 * ---------------------------------------------------------------------- */

function ipkcap_integration_active( $key ) {
	$s = ipkcap_get_settings();
	return ipkcap_is_configured() && ! empty( $s[ $key ] );
}

// JavaScript an integration adds to the listener below, for its form plugin's own
// submit events. Integrations call it when their file is loaded.
function ipkcap_add_form_script( $js ) {
	$GLOBALS['ipkcap_form_scripts'][] = $js;
}

// Load the widget script plus a small listener that resets the widget after
// the form plugin's own AJAX submit. Called while the form is rendered.
// With $with_script = false only the listener is added, for forms where the
// widget script is already on the page (for example pasted in by hand).
//
// The listener starts with ipkcapForms, which the integrations' scripts use:
//   ipkcapForms.reset(el)                     a fresh token for the widget in el (a form or a
//                                             wrapper), or for every widget when el has none
//   ipkcapForms.resetOn(events, form, opts)   the same after the form plugin's own events:
//                                             events  space-separated event names
//                                             form    CSS selector of the form, null for the
//                                                     element the event fires on, or a function
//                                                     (event, ...jQuery arguments) returning it
//                                             opts    { jquery: true } for jQuery events,
//                                                     { on: element } to listen there instead
//                                                     of on document
function ipkcap_enqueue_for_forms( $with_script = true ) {
	if ( $with_script ) {
		ipkcap_enqueue_script();
	}
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	$js   = <<<'JS'
(function () {
	var ipk = window.ipkcapForms = window.ipkcapForms || {};
	ipk.reset = function (el) {
		if (!window.infopeakCaptcha || !el) return;
		var w = el.querySelector ? el.querySelector('.infopeak-captcha') : null;
		window.infopeakCaptcha.reset(w || undefined);
	};
	ipk.resetOn = function (events, form, opts) {
		opts = opts || {};
		var on = opts.on || document;
		var handler = function (e) {
			var el = e ? e.target : null;
			if (typeof form === 'function') el = form.apply(this, arguments);
			else if (form) el = (el && el.closest && el.closest(form)) || document.querySelector(form);
			ipk.reset(el);
		};
		if (opts.jquery) {
			if (window.jQuery) jQuery(on).on(events, handler);
			return;
		}
		events.split(' ').forEach(function (name) { on.addEventListener(name, handler); });
	};
})();
JS;
	$js  .= "\n" . implode( "\n", isset( $GLOBALS['ipkcap_form_scripts'] ) ? (array) $GLOBALS['ipkcap_form_scripts'] : array() );
	if ( ! $with_script ) {
		// A handle without a file carries the listener on its own.
		wp_register_script( 'infopeak-captcha-reset', false, array(), IPKCAP_VERSION, true );
		wp_enqueue_script( 'infopeak-captcha-reset' );
		wp_add_inline_script( 'infopeak-captcha-reset', $js );
		return;
	}
	wp_add_inline_script( 'infopeak-captcha', $js, 'after' );
}

/* -------------------------------------------------------------------------
 * Loading the integrations
 * ---------------------------------------------------------------------- */

// The form plugin integrations in includes/integrations/, by file name. 'plugin' is the
// form plugin's main file as it appears in the list of active plugins; 'present' says
// whether the form plugin has loaded, for installs where it is not in that list (a
// must-use plugin, a renamed main file, a host that bundles it).
function ipkcap_integrations() {
	return array(
		'contact-form-7' => array(
			'plugin'  => 'wp-contact-form-7.php',
			'present' => function () {
				return defined( 'WPCF7_VERSION' );
			},
		),
		'wpforms'        => array(
			'plugin'  => 'wpforms.php',
			'present' => function () {
				return defined( 'WPFORMS_VERSION' ) || function_exists( 'wpforms' );
			},
		),
		'jetpack'        => array(
			'plugin'  => 'jetpack.php',
			'present' => function () {
				return defined( 'JETPACK__VERSION' ) || class_exists( 'Automattic\Jetpack\Forms\Jetpack_Forms', false ) || class_exists( 'Grunion_Contact_Form_Plugin', false );
			},
		),
		'woocommerce'    => array(
			'plugin'  => 'woocommerce.php',
			'present' => function () {
				return class_exists( 'WooCommerce', false );
			},
		),
		'gravity-forms'  => array(
			'plugin'  => 'gravityforms.php',
			'present' => function () {
				return class_exists( 'GFForms', false );
			},
		),
		'fluent-forms'   => array(
			'plugin'  => 'fluentform.php',
			'present' => function () {
				return defined( 'FLUENTFORM_VERSION' );
			},
		),
	);
}

function ipkcap_load_integration( $name ) {
	static $loaded = array();
	if ( empty( $loaded[ $name ] ) ) {
		$loaded[ $name ] = true;
		require_once __DIR__ . '/includes/integrations/' . $name . '.php';
	}
}

// $late: false while this file loads, true once every plugin has loaded.
function ipkcap_load_integrations( $late = false ) {
	$active = array();
	if ( ! $late ) {
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$active = array_map( 'basename', array_map( 'strval', $active ) );
	}
	foreach ( ipkcap_integrations() as $name => $integration ) {
		if ( in_array( $integration['plugin'], $active, true ) || call_user_func( $integration['present'] ) ) {
			ipkcap_load_integration( $name );
		}
	}
}

// WordPress' own forms are always protected. A form plugin's integration is loaded right
// away when the plugin is active, so its hooks are added at the same point as before, or
// once all plugins have loaded when the form plugin only turns up then.
ipkcap_load_integration( 'core' );
ipkcap_load_integrations();
if ( did_action( 'plugins_loaded' ) ) {
	ipkcap_load_integrations( true );
} else {
	add_action( 'plugins_loaded', function () {
		ipkcap_load_integrations( true );
	}, 0 );
}

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
		'enable_fluent'   => empty( $input['enable_fluent'] ) ? 0 : 1,
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
								'enable_fluent'  => array( __( 'Fluent Forms', 'infopeak-captcha' ), defined( 'FLUENTFORM_VERSION' ) ),
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
