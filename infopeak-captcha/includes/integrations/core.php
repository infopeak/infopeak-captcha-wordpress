<?php
/**
 * WordPress' own forms: login, registration, lost password (wp-login.php) and comments.
 * Always loaded.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
