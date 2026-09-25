<?php
/**
 * Plugin Name:       InfoPeak Captcha
 * Plugin URI:        https://infopeak.io/captcha
 * Description:       Privacy-first, EU-hosted captcha for WordPress. No tracking, no cookies, no image puzzles - protects login, registration, password reset, comments, Contact Form 7, WPForms and WooCommerce checkout.
 * Version:           1.1.0
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

define( 'IPKCAP_VERSION', '1.1.0' );
define( 'IPKCAP_API_ORIGIN', 'https://captcha.infopeak.io' );
define( 'IPKCAP_FIELD', 'infopeak-captcha-response' );

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
		'enable_woo'       => 1,
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

function ipkcap_widget_html() {
	$s = ipkcap_get_settings();
	return '<div class="infopeak-captcha" data-sitekey="' . esc_attr( $s['sitekey'] ) . '"></div>';
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
		array(),
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
	return isset( $body['valid'] ) && $body['valid'] === true;
}

function ipkcap_posted_token_valid() {
	$token = isset( $_POST[ IPKCAP_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ IPKCAP_FIELD ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- captcha token is its own proof.
	return ipkcap_verify_token( $token );
}

function ipkcap_error_message() {
	return __( 'Captcha verification failed. Please try again.', 'infopeak-captcha' );
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
 * Form plugins: Contact Form 7, WPForms, WooCommerce checkout
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
function ipkcap_enqueue_for_forms() {
	ipkcap_enqueue_script();
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
})();
JS;
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
		'enable_woo'      => empty( $input['enable_woo'] ) ? 0 : 1,
	);
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
				/* translators: %s: link to the InfoPeak dashboard */
				esc_html__( 'Privacy-first, EU-hosted captcha. Get your free sitekey and secret from your %s.', 'infopeak-captcha' ),
				'<a href="https://auth.infopeak.io/free.php" target="_blank" rel="noopener">' . esc_html__( 'InfoPeak dashboard', 'infopeak-captcha' ) . '</a>'
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
								'enable_woo'     => array( __( 'WooCommerce checkout', 'infopeak-captcha' ), class_exists( 'WooCommerce' ) ),
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
