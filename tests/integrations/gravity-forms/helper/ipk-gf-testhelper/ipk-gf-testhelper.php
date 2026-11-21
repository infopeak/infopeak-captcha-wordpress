<?php
/**
 * Plugin Name: IPK GF test helper (demo only)
 * Description: Test-only helper for the InfoPeak Captcha Gravity Forms integration. Admin-only. Never install on a real site.
 * Version: 0.4.0
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

// Sets up a fresh demo: imports the synthetic forms (posted as the JSON from
// fixtures/synthetic-forms.json), turns InfoPeak on for the first three, the third in
// English, and makes a postback page and an AJAX page for each form. InfoPeak gets the
// public test keys and only protects form plugins, so the demo's own login is untouched.
add_action( 'wp_ajax_ipk_gf_setup', function () {
	ipk_gf_helper_guard();
	$export = json_decode( wp_unslash( $_POST['forms'] ), true );
	if ( ! is_array( $export ) ) {
		wp_send_json_error( 'bad forms json' );
	}
	unset( $export['version'] );
	$out = array();
	foreach ( array_values( $export ) as $i => $form ) {
		$existing = array_filter( GFAPI::get_forms(), function ( $f ) use ( $form ) {
			return $f['title'] === $form['title'];
		} );
		$id = $existing ? (int) reset( $existing )['id'] : GFAPI::add_form( $form );
		if ( is_wp_error( $id ) ) {
			wp_send_json_error( $id->get_error_message() );
		}
		$saved = GFAPI::get_form( $id );
		if ( $i < 3 ) {
			$saved['ipkcap_enabled'] = '1';
		}
		if ( 2 === $i ) {
			$saved['ipkcap_lang'] = 'en';
		}
		GFAPI::update_form( $saved );
		$pages = array();
		foreach ( array( 'postback' => 'false', 'ajax' => 'true' ) as $kind => $ajax ) {
			$slug = 'ipk-gf-' . $id . '-' . $kind;
			$page = get_page_by_path( $slug );
			$pid  = $page ? $page->ID : wp_insert_post( array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_name'    => $slug,
				'post_title'   => $form['title'] . ' (' . $kind . ')',
				'post_content' => '[gravityform id="' . $id . '" title="true" ajax="' . $ajax . '"]',
			) );
			$pages[ $kind ] = get_permalink( $pid );
		}
		$out[] = array( 'id' => $id, 'title' => $form['title'], 'pages' => $pages );
	}
	update_option( 'ipkcap_settings', array(
		'sitekey' => 'ipk_test_sitekey', 'secret' => 'ipk_test_secret',
		'enable_login' => 0, 'enable_register' => 0, 'enable_lostpw' => 0, 'enable_comments' => 0, 'skip_logged_in' => 1,
		'enable_cf7' => 1, 'enable_wpforms' => 1, 'enable_jetpack' => 1, 'enable_woo' => 1, 'enable_gf' => 1, 'enable_fluent' => 1,
	) );
	wp_send_json_success( $out );
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
