<?php
/**
 * Plugin Name: IPK test helper (throwaway test site only)
 * Description: Counts siteverify answers and outgoing mail, can simulate an unreachable captcha service. Never install on a real site.
 *
 * Endpoints (need ?ipk_key=IPK_TEST_KEY from wp-config.php):
 *   ?ipk_test=stats           {"calls","valid","invalid","failed","mails":[subjects],"records","product"}
 *   ?ipk_test=outage&n=1      the next n siteverify requests fail as if the service were down
 *   ?ipk_test=reset           counters back to zero
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const IPK_TEST_SITEVERIFY = 'https://captcha.infopeak.io/api/v1/pow/siteverify';

function ipk_test_stats() {
	$s = get_option( 'ipk_test_stats' );
	return ( is_array( $s ) ? $s : array() ) + array( 'calls' => 0, 'valid' => 0, 'invalid' => 0, 'failed' => 0, 'mails' => array(), 'records' => 0 );
}

function ipk_test_bump( $key, $value = null ) {
	$s = ipk_test_stats();
	if ( 'mails' === $key ) {
		$s['mails'][] = $value;
	} else {
		$s[ $key ]++;
	}
	update_option( 'ipk_test_stats', $s, false );
}

add_action( 'init', function () {
	if ( empty( $_GET['ipk_test'] ) || ! defined( 'IPK_TEST_KEY' ) || ! isset( $_GET['ipk_key'] ) || ! hash_equals( IPK_TEST_KEY, (string) $_GET['ipk_key'] ) ) {
		return;
	}
	nocache_headers();
	switch ( $_GET['ipk_test'] ) {
		case 'reset':
			delete_option( 'ipk_test_stats' );
			delete_option( 'ipk_test_outage' );
			break;
		case 'outage':
			update_option( 'ipk_test_outage', max( 0, (int) ( $_GET['n'] ?? 1 ) ), false );
			break;
	}
	wp_send_json( ipk_test_stats() + array( 'product' => (int) get_option( 'ipk_test_product' ) ) );
}, 0 );

// Simulated outage: the request never reaches the service.
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	if ( IPK_TEST_SITEVERIFY !== $url ) {
		return $pre;
	}
	ipk_test_bump( 'calls' );
	$n = (int) get_option( 'ipk_test_outage', 0 );
	if ( $n > 0 ) {
		update_option( 'ipk_test_outage', $n - 1, false );
		ipk_test_bump( 'failed' );
		return new WP_Error( 'http_request_failed', 'simulated outage' );
	}
	return $pre;
}, 10, 3 );

// The service's answer (tokens are never stored).
add_action( 'http_api_debug', function ( $response, $context, $class, $args, $url ) {
	if ( IPK_TEST_SITEVERIFY !== $url || is_wp_error( $response ) ) {
		return;
	}
	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	ipk_test_bump( ! empty( $body['valid'] ) ? 'valid' : 'invalid' );
}, 10, 5 );

// Mail is counted, never sent.
add_filter( 'pre_wp_mail', function ( $return, $atts ) {
	ipk_test_bump( 'mails', is_array( $atts ) && isset( $atts['subject'] ) ? (string) $atts['subject'] : '' );
	return true;
}, 10, 2 );

// The tests send several comments in a row from the same address.
add_filter( 'comment_flood_filter', '__return_false' );

// Fluent Forms allows 5 stored submissions per IP in 30 seconds; the tests send more.
add_filter( 'fluentform/prevent_malicious_attacks', '__return_false' );

// Form plugins that store a submission without sending mail on the test site: each stored one
// counts as a record.
add_action( 'mailpoet_subscription_after_subscribe', function () {
	ipk_test_bump( 'records' );
} );

// MailPoet makes an IP address wait longer after each subscription; the tests send many.
add_filter( 'mailpoet_subscription_limit_enabled', '__return_false' );
