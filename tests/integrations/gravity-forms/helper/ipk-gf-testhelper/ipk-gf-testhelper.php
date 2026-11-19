<?php
/**
 * Plugin Name: IPK GF test helper (demo only)
 * Description: Test-only helper for the InfoPeak Captcha Gravity Forms integration. Admin-only. Never install on a real site.
 * Version: 0.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// For one request flagged by an admin cookie, take away InfoPeak's gform_validation check
// so the gform_pre_submission backstop has to decide on its own.
add_action( 'init', function () {
	if ( ! empty( $_COOKIE['ipk_skip_validation'] ) && current_user_can( 'manage_options' ) ) {
		remove_all_filters( 'gform_validation', 9 );
	}
}, 1 );

// Record notifications Gravity Forms tries to send, and stop the actual mail.
add_filter( 'gform_pre_send_email', function ( $email, $format, $notification, $entry ) {
	$log   = get_option( 'ipk_gf_notification_log', array() );
	$log[] = array( 'form' => (int) rgar( $entry, 'form_id' ), 'entry' => (int) rgar( $entry, 'id' ), 'name' => rgar( $notification, 'name' ) );
	update_option( 'ipk_gf_notification_log', $log, false );
	$email['abort_email'] = true;
	return $email;
}, 10, 4 );

function ipk_gf_helper_guard() {
	if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'ipk_gf_helper', 'nonce', false ) ) {
		wp_send_json_error( 'forbidden', 403 );
	}
}

add_action( 'wp_ajax_ipk_gf_nonce', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'forbidden', 403 );
	}
	wp_send_json_success( wp_create_nonce( 'ipk_gf_helper' ) );
} );

add_action( 'wp_ajax_ipk_gf_counts', function () {
	ipk_gf_helper_guard();
	$out = array();
	foreach ( array_map( 'absint', (array) $_POST['forms'] ) as $id ) {
		$out[ $id ] = array(
			'active' => GFAPI::count_entries( $id, array( 'status' => 'active' ) ),
			'spam'   => GFAPI::count_entries( $id, array( 'status' => 'spam' ) ),
			'trash'  => GFAPI::count_entries( $id, array( 'status' => 'trash' ) ),
		);
		// reCAPTCHA meta on the newest entry, active or spam.
		$latest = GFAPI::get_entries( $id, array( 'status' => array( 'active', 'spam' ) ), array( 'key' => 'id', 'direction' => 'DESC' ), array( 'page_size' => 1 ) );
		if ( ! empty( $latest[0] ) ) {
			$meta = array( 'id' => $latest[0]['id'], 'status' => $latest[0]['status'] );
			foreach ( $latest[0] as $k => $v ) {
				if ( stripos( (string) $k, 'recaptcha' ) !== false ) {
					$meta[ $k ] = $v;
				}
			}
			$out[ $id ]['latest'] = $meta;
		}
	}
	wp_send_json_success( array( 'entries' => $out, 'notifications' => get_option( 'ipk_gf_notification_log', array() ) ) );
} );

add_action( 'wp_ajax_ipk_gf_add_notifications', function () {
	ipk_gf_helper_guard();
	foreach ( array_map( 'absint', (array) $_POST['forms'] ) as $id ) {
		$form = GFAPI::get_form( $id );
		if ( ! $form || ! empty( $form['notifications']['ipktest'] ) ) {
			continue;
		}
		$form['notifications']['ipktest'] = array(
			'id' => 'ipktest', 'name' => 'IPK test notification', 'isActive' => true, 'event' => 'form_submission',
			'toType' => 'email', 'to' => 'notify@example.test', 'subject' => 'IPK test {form_title}', 'message' => '{all_fields}',
		);
		GFAPI::update_form( $form );
	}
	wp_send_json_success();
} );

// Read-only: lines in Gravity Forms' form_display.php around the given hook names, to check
// call order in the installed version.
add_action( 'wp_ajax_ipk_gf_grep', function () {
	ipk_gf_helper_guard();
	$lines = file( GFCommon::get_base_path() . '/form_display.php' );
	$out   = array();
	foreach ( (array) $_POST['needles'] as $needle ) {
		$needle = sanitize_text_field( wp_unslash( $needle ) );
		foreach ( $lines as $i => $line ) {
			if ( strpos( $line, $needle ) !== false ) {
				$out[] = '--- ' . $needle . ' @' . ( $i + 1 );
				for ( $j = max( 0, $i - (int) $_POST['before'] ); $j <= min( count( $lines ) - 1, $i + (int) $_POST['after'] ); $j++ ) {
					$out[] = ( $j + 1 ) . ': ' . rtrim( $lines[ $j ] );
				}
			}
		}
	}
	wp_send_json_success( implode( "\n", $out ) );
} );

// GFAPI::submit_form() with the values given (token optional).
add_action( 'wp_ajax_ipk_gf_api_submit', function () {
	ipk_gf_helper_guard();
	$id     = absint( $_POST['form'] );
	$values = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['values'] ) );
	$result = GFAPI::submit_form( $id, $values );
	if ( is_wp_error( $result ) ) {
		wp_send_json_success( array( 'wp_error' => $result->get_error_message() ) );
	}
	wp_send_json_success( array( 'is_valid' => $result['is_valid'], 'entry_id' => rgar( $result, 'entry_id' ), 'messages' => rgar( $result, 'validation_messages' ) ) );
} );
