<?php
/**
 * Just enough of WordPress and Gravity Forms to load the real plugin file and run
 * its Gravity Forms hooks outside WordPress. The Gravity Forms part mirrors the
 * documented order in GFFormDisplay::process_form: validate (gform_validation),
 * and only when valid save the entry and send notifications.
 *
 * Synthetic data only. Nothing here talks to a real site.
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['ipk_test'] = array(
	'filters'        => array(),
	'options'        => array(),
	'http_calls'     => array(),
	'http_response'  => null,   // callable( $url, $args ) or null.
	'scripts'        => array(),
	'inline_scripts' => array(),
	'entries'        => array(),
	'notifications'  => array(),
	'gf_present'     => true,
	'last_page'      => null,   // null = computed like Gravity Forms.
	'forms'          => array(),
);

// --- Hooks ---
function add_filter( $tag, $cb, $prio = 10, $args = 1 ) {
	$GLOBALS['ipk_test']['filters'][ $tag ][ $prio ][] = array( $cb, $args );
	return true;
}
function add_action( $tag, $cb, $prio = 10, $args = 1 ) {
	return add_filter( $tag, $cb, $prio, $args );
}
function apply_filters( $tag, $value, ...$rest ) {
	$all = isset( $GLOBALS['ipk_test']['filters'][ $tag ] ) ? $GLOBALS['ipk_test']['filters'][ $tag ] : array();
	ksort( $all );
	foreach ( $all as $cbs ) {
		foreach ( $cbs as $cb ) {
			$value = call_user_func_array( $cb[0], array_slice( array_merge( array( $value ), $rest ), 0, $cb[1] ) );
		}
	}
	return $value;
}
function has_filter( $tag ) {
	return ! empty( $GLOBALS['ipk_test']['filters'][ $tag ] );
}

// --- Options, i18n, escaping ---
function get_option( $name, $default = false ) {
	return isset( $GLOBALS['ipk_test']['options'][ $name ] ) ? $GLOBALS['ipk_test']['options'][ $name ] : $default;
}
function wp_parse_args( $args, $defaults ) {
	return array_merge( $defaults, $args );
}
function __( $s ) { return $s; }
function esc_html__( $s ) { return $s; }
function esc_html_e( $s ) { echo $s; }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s ) { return $s; }
function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $s ) ) ); }
function wp_unslash( $s ) { return $s; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function plugin_basename( $f ) { return basename( dirname( $f ) ) . '/' . basename( $f ); }
function get_bloginfo( $k ) { return '6.8'; }
function is_user_logged_in() { return false; }
define( 'WP_LANG_DIR', __DIR__ . '/no-lang-dir' );
function determine_locale() { return $GLOBALS['ipk_test']['locale'] ?? 'da_DK'; }
function load_textdomain( $domain, $mofile ) { $GLOBALS['ipk_test']['textdomains'][ $domain ] = $mofile; return true; }
function get_locale() { return $GLOBALS['ipk_test']['locale'] ?? 'da_DK'; }

// --- Scripts ---
function wp_enqueue_script( $handle, $src = '' ) { $GLOBALS['ipk_test']['scripts'][ $handle ] = $src; }
function wp_register_script( $handle, $src = '' ) { $GLOBALS['ipk_test']['scripts'][ $handle ] = $src; }
function wp_add_inline_script( $handle, $js ) { $GLOBALS['ipk_test']['inline_scripts'][ $handle ][] = $js; }

// --- HTTP ---
class WP_Error {
	public $code;
	public $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_remote_post( $url, $args ) {
	$GLOBALS['ipk_test']['http_calls'][] = array( 'url' => $url, 'args' => $args );
	$cb = $GLOBALS['ipk_test']['http_response'];
	return $cb ? $cb( $url, $args ) : new WP_Error( 'http_request_failed', 'no network in tests' );
}
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? $r['response']['code'] : ''; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? $r['body'] : ''; }

// --- Gravity Forms ---
function rgar( $array, $key ) { return isset( $array[ $key ] ) ? $array[ $key ] : ''; }
function ipk_test_define_gf() {
	if ( class_exists( 'GFFormDisplay' ) ) {
		return;
	}
	class GFForms {}
	class GFFormDisplay {
		// As in Gravity Forms: the last page is when the target page works out to 0.
		// A test can set ipk_test.last_page to a bool to simulate a version where
		// this helper disagrees with process_form.
		public static function is_last_page( $form ) {
			$o = $GLOBALS['ipk_test']['last_page'];
			return is_bool( $o ) ? $o : 0 === ipk_test_target_page( $form );
		}
	}
	class GFAPI {
		// GFAPI::submit_form() posts the values and runs process_form with context api-submit.
		public static function submit_form( $form_id, $input_values ) {
			$_POST = array_merge( $input_values, array( 'gform_submit' => $form_id ) );
			return ipk_test_gf_submit( $GLOBALS['ipk_test']['forms'][ $form_id ], 'api-submit' );
		}
	}
}

/** A synthetic form like the school's: a name field, a file upload and the settings InfoPeak adds. */
function ipk_test_form( $id, $settings = array() ) {
	$name = (object) array( 'id' => 1, 'type' => 'text', 'label' => 'Name', 'isRequired' => true, 'failed_validation' => false );
	$file = (object) array( 'id' => 3, 'type' => 'fileupload', 'label' => 'Upload', 'isRequired' => false );
	return array_merge( array( 'id' => $id, 'title' => 'Test form ' . $id, 'fields' => array( $name, $file ), 'pages' => 1, 'hidden_pages' => array() ), $settings );
}

function ipk_test_source_page( $form ) {
	$k = 'gform_source_page_number_' . $form['id'];
	return isset( $_POST[ $k ] ) ? max( 1, (int) $_POST[ $k ] ) : 1;
}

/** GFFormDisplay::get_target_page: skip pages hidden by conditional logic; past the last page means submit (0). */
function ipk_test_target_page( $form ) {
	$k      = 'gform_target_page_number_' . $form['id'];
	// Forms and GFAPI::submit_form() post 0 for a final submit; a missing field is treated the same.
	$target = isset( $_POST[ $k ] ) && is_numeric( $_POST[ $k ] ) ? (int) $_POST[ $k ] : 0;
	if ( 0 === $target ) {
		return 0;
	}
	$dir = $target >= ipk_test_source_page( $form ) ? 1 : -1;
	while ( $target > 0 && in_array( $target, $form['hidden_pages'], true ) ) {
		$target += $dir;
	}
	return $target > $form['pages'] ? 0 : $target;
}

/**
 * One request through GFFormDisplay::process_form as Gravity Forms 3.2.2 runs it
 * (form_display.php, read in the demo): no validation when going back or saving
 * for later; gform_validation; then, for a valid final submit or a save:
 * gform_abort_submission_with_confirmation, and only if not aborted and not saving:
 * gform_pre_submission, entry, notifications. ipk_test.no_abort_filter simulates a
 * Gravity Forms version before 2.7, which has no abort filter.
 */
function ipk_test_gf_submit( $form, $context = 'form-submit' ) {
	$GLOBALS['ipk_test']['forms'][ $form['id'] ] = $form;
	$source = ipk_test_source_page( $form );
	$target = ipk_test_target_page( $form );
	$saving = ! empty( $_POST['gform_save'] );
	$valid  = true;
	if ( ! $saving && ( 0 === $target || $target > $source ) ) {
		$fields_ok = isset( $_POST['input_1'] ) && trim( $_POST['input_1'] ) !== '';
		$result    = apply_filters( 'gform_validation', array( 'is_valid' => $fields_ok, 'form' => $form, 'failed_validation_page' => $source ), $context );
		$valid     = $result['is_valid'];
		if ( ! $valid ) {
			$message = apply_filters( 'gform_validation_message', "<h2 class='gform_submission_error'>There was a problem with your submission.</h2>", $result['form'] );
			return array( 'saved' => false, 'message' => $message );
		}
	}
	if ( 'api-validate' === $context || ( ! $saving && 0 !== $target ) ) {
		return array( 'saved' => false, 'message' => '', 'page' => $target );
	}
	if ( empty( $GLOBALS['ipk_test']['no_abort_filter'] ) && apply_filters( 'gform_abort_submission_with_confirmation', false, $form ) ) {
		$confirmation = apply_filters( 'gform_confirmation', "<div class='gform_confirmation_message'>Thanks for contacting us!</div>", $form, array(), false );
		return array( 'saved' => false, 'message' => '', 'aborted' => $confirmation );
	}
	if ( $saving ) {
		return array( 'saved' => false, 'message' => '', 'draft' => true );
	}
	try {
		apply_filters( 'gform_pre_submission', $form );
	} catch ( IpkTestDie $e ) {
		return array( 'saved' => false, 'message' => '', 'died' => $e->getMessage() );
	}
	$GLOBALS['ipk_test']['entries'][]       = array( 'form_id' => $form['id'], 'input_1' => isset( $_POST['input_1'] ) ? $_POST['input_1'] : '' );
	$GLOBALS['ipk_test']['notifications'][] = $form['id'];
	$confirmation = apply_filters( 'gform_confirmation', "<div class='gform_confirmation_message'>Thanks for contacting us!</div>", $form, array(), false );
	return array( 'saved' => true, 'message' => '', 'confirmation' => $confirmation );
}

class IpkTestDie extends Exception {}
function wp_die( $message ) {
	throw new IpkTestDie( (string) $message );
}

/** Siteverify answers like the real service. */
function ipk_test_siteverify( $body, $code = 200 ) {
	return function () use ( $body, $code ) {
		return array( 'response' => array( 'code' => $code ), 'body' => json_encode( $body ) );
	};
}
