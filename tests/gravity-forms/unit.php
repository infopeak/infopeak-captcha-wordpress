<?php
/**
 * Gravity Forms integration, server side. Each scenario runs in its own PHP
 * process, like one WordPress request, so nothing carries over between them.
 *
 *   php tests/gravity-forms/unit.php            runs every scenario
 *   php tests/gravity-forms/unit.php <name>     runs one (used by the runner)
 */

$scenarios = array(
	'valid_token_saves_entry',
	'missing_token_blocks',
	'invalid_token_blocks',
	'siteverify_http_error_blocks',
	'siteverify_network_error_blocks',
	'quota_message',
	'form_without_infopeak_untouched',
	'global_switch_off_untouched',
	'not_configured_untouched',
	'field_error_spends_no_token',
	'page_navigation_not_checked',
	'api_validate_not_checked',
	'api_submit_checked',
	'token_reused_in_next_request_blocks',
	'double_validation_same_request',
	'widget_html_and_language',
	'widget_not_duplicated',
	'form_settings_fields',
	'no_gravity_forms_loads',
	'secret_stays_on_server',
	'multipage_next_and_back_not_checked',
	'multipage_final_checked',
	'tampered_target_zero_from_first_page',
	'tampered_target_into_hidden_last_pages',
	'save_and_continue_creates_no_entry',
	'backstop_blocks_when_page_check_disagrees',
	'backstop_passes_valid_token_once',
	'gfapi_submit_form_without_token_blocked',
	'gfapi_submit_form_with_token_saves',
	'pre_27_backstop_dies_before_entry',
	'success_confirmation_untouched',
	'danish_translation_loaded',
	'language_pack_takes_precedence',
);

if ( PHP_SAPI !== 'cli' ) {
	exit;
}

if ( ! isset( $argv[1] ) ) {
	$failed = 0;
	foreach ( $scenarios as $name ) {
		$out  = array();
		$code = 0;
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $name ) . ' 2>&1', $out, $code );
		$ok = 0 === $code && end( $out ) === 'OK';
		$failed += $ok ? 0 : 1;
		echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok ? '' : "\n    " . implode( "\n    ", $out ) ) . "\n";
	}
	echo "\n" . ( count( $scenarios ) - $failed ) . '/' . count( $scenarios ) . " passed\n";
	exit( $failed ? 1 : 0 );
}

$name = $argv[1];
require __DIR__ . '/wp-stubs.php';

function check( $cond, $what ) {
	if ( ! $cond ) {
		echo "assertion failed: $what\n";
		exit( 1 );
	}
}

$t = &$GLOBALS['ipk_test'];
$t['options']['ipkcap_settings'] = array( 'sitekey' => 'ipk_test_sitekey', 'secret' => 'synthetic-secret-123' );
if ( 'no_gravity_forms_loads' !== $name ) {
	ipk_test_define_gf();
}
require dirname( __DIR__, 2 ) . '/infopeak-captcha/infopeak-captcha.php';

$protected   = ipk_test_form( 2, array( 'ipkcap_enabled' => '1' ) );
$unprotected = ipk_test_form( 7 );
$_POST       = array( 'input_1' => 'Synthetic Student', 'infopeak-captcha-response' => 'tok-synthetic-1' );

switch ( $name ) {
	case 'valid_token_saves_entry':
		$t['http_response'] = ipk_test_siteverify( array( 'valid' => true ) );
		$r = ipk_test_gf_submit( $protected );
		check( $r['saved'], 'entry saved' );
		check( count( $t['notifications'] ) === 1, 'one notification' );
		check( count( $t['http_calls'] ) === 1, 'one siteverify call' );
		check( $t['http_calls'][0]['url'] === 'https://captcha.infopeak.io/api/v1/pow/siteverify', 'siteverify url' );
		break;

	case 'missing_token_blocks':
		unset( $_POST['infopeak-captcha-response'] );
		$t['http_response'] = ipk_test_siteverify( array( 'valid' => true ) );
		$r = ipk_test_gf_submit( $protected );
		check( ! $r['saved'] && empty( $t['notifications'] ), 'nothing saved or sent' );
		check( count( $t['http_calls'] ) === 0, 'no siteverify without a token' );
		check( strpos( $r['message'], 'Captcha verification failed. Please try again.' ) !== false, 'actionable message' );
		break;

	case 'invalid_token_blocks':
		$t['http_response'] = ipk_test_siteverify( array( 'valid' => false ) );
		$r = ipk_test_gf_submit( $protected );
		check( ! $r['saved'] && empty( $t['notifications'] ), 'nothing saved or sent' );
		check( strpos( $r['message'], 'class="ipkcap-gf-error" role="alert"' ) !== false, 'message shown under the summary' );
		break;

	case 'siteverify_http_error_blocks':
		$t['http_response'] = ipk_test_siteverify( array( 'error' => 'internal' ), 500 );
		$r = ipk_test_gf_submit( $protected );
		check( ! $r['saved'] && empty( $t['notifications'] ), 'fails closed on 500' );
		check( strpos( $r['message'], 'Please try again' ) !== false, 'actionable message' );
		break;

	case 'siteverify_network_error_blocks':
		$t['http_response'] = null; // wp_remote_post returns WP_Error.
		$r = ipk_test_gf_submit( $protected );
		check( ! $r['saved'] && empty( $t['notifications'] ), 'fails closed when unreachable' );
		check( strpos( $r['message'], 'Please try again' ) !== false, 'actionable message' );
		break;

	case 'quota_message':
		$t['http_response'] = ipk_test_siteverify( array( 'valid' => false, 'quota_exceeded' => true ) );
		$r = ipk_test_gf_submit( $protected );
		check( ! $r['saved'], 'blocked' );
		check( strpos( $r['message'], 'cannot check your message right now' ) !== false, 'quota message' );
		break;

	case 'form_without_infopeak_untouched':
		unset( $_POST['infopeak-captcha-response'] );
		$r = ipk_test_gf_submit( $unprotected );
		check( $r['saved'], 'saved as before' );
		check( count( $t['http_calls'] ) === 0, 'no siteverify' );
		check( apply_filters( 'gform_submit_button', '<button>Send</button>', $unprotected ) === '<button>Send</button>', 'button unchanged' );
		check( empty( $t['scripts'] ), 'no script enqueued' );
		break;

	case 'global_switch_off_untouched':
		$t['options']['ipkcap_settings']['enable_gf'] = 0;
		unset( $_POST['infopeak-captcha-response'] );
		$r = ipk_test_gf_submit( $protected );
		check( $r['saved'] && count( $t['http_calls'] ) === 0, 'global switch off leaves the form alone' );
		check( apply_filters( 'gform_submit_button', '<button>Send</button>', $protected ) === '<button>Send</button>', 'no widget' );
		break;

	case 'not_configured_untouched':
		$t['options']['ipkcap_settings'] = array( 'sitekey' => '', 'secret' => '' );
		unset( $_POST['infopeak-captcha-response'] );
		$r = ipk_test_gf_submit( $protected );
		check( $r['saved'] && count( $t['http_calls'] ) === 0, 'no keys: same as every other integration' );
		break;

	case 'field_error_spends_no_token':
		$_POST['input_1']   = '';
		$t['http_response'] = ipk_test_siteverify( array( 'valid' => true ) );
		$r = ipk_test_gf_submit( $protected );
		check( ! $r['saved'], 'field error blocks' );
		check( count( $t['http_calls'] ) === 0, 'token not spent' );
		check( strpos( $r['message'], 'ipkcap-gf-error' ) === false, 'no captcha message for a field error' );
		break;

	case 'page_navigation_not_checked':
		$t['last_page']     = false;
		$t['http_response'] = ipk_test_siteverify( array( 'valid' => false ) );
		$r = apply_filters( 'gform_validation', array( 'is_valid' => true, 'form' => $protected ), 'form-submit' );
		check( $r['is_valid'] && count( $t['http_calls'] ) === 0, 'next page not checked' );
		$t['last_page'] = true;
		$r = apply_filters( 'gform_validation', array( 'is_valid' => true, 'form' => $protected ), 'form-submit' );
		check( ! $r['is_valid'] && count( $t['http_calls'] ) === 1, 'last page checked' );
		break;

	case 'api_validate_not_checked':
		$r = apply_filters( 'gform_validation', array( 'is_valid' => true, 'form' => $protected ), 'api-validate' );
		check( $r['is_valid'] && count( $t['http_calls'] ) === 0, 'api-validate saves nothing and is not checked' );
		break;

	case 'api_submit_checked':
		unset( $_POST['infopeak-captcha-response'] );
		$r = ipk_test_gf_submit( $protected, 'api-submit' );
		check( ! $r['saved'], 'REST/GFAPI submit without a token is refused' );
		break;

	case 'token_reused_in_next_request_blocks':
		// The service answers valid:false for a token it has confirmed before. The
		// first request is the valid_token scenario; this is the replay.
		$t['http_response'] = ipk_test_siteverify( array( 'valid' => false ) );
		$r = ipk_test_gf_submit( $protected );
		check( ! $r['saved'] && count( $t['http_calls'] ) === 1, 'replay asks the service again and is refused' );
		break;

	case 'double_validation_same_request':
		$calls              = 0;
		$t['http_response'] = function () use ( &$calls ) {
			$calls++;
			return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'valid' => 1 === $calls ) ) );
		};
		$a = apply_filters( 'gform_validation', array( 'is_valid' => true, 'form' => $protected ), 'form-submit' );
		$b = apply_filters( 'gform_validation', array( 'is_valid' => true, 'form' => $protected ), 'form-submit' );
		check( $a['is_valid'] && $b['is_valid'] && 1 === $calls, 'confirmed once per request' );
		$c = apply_filters( 'gform_validation', array( 'is_valid' => true, 'form' => ipk_test_form( 5, array( 'ipkcap_enabled' => '1' ) ) ), 'form-submit' );
		check( ! $c['is_valid'], 'another form in the same request does not inherit the approval' );
		break;

	case 'widget_html_and_language':
		$html = apply_filters( 'gform_submit_button', '<button type="submit">Send</button>', $protected );
		check( $html === '<div class="infopeak-captcha" data-sitekey="ipk_test_sitekey"></div><button type="submit">Send</button>', 'widget before the button, page language' );
		$html = apply_filters( 'gform_submit_button', '<button>Send</button>', ipk_test_form( 5, array( 'ipkcap_enabled' => '1', 'ipkcap_lang' => 'en' ) ) );
		check( strpos( $html, 'data-lang="en"' ) !== false, 'English on request' );
		$html = apply_filters( 'gform_submit_button', '<button>Send</button>', ipk_test_form( 5, array( 'ipkcap_enabled' => '1', 'ipkcap_lang' => '"><script>' ) ) );
		check( strpos( $html, 'data-lang' ) === false && strpos( $html, '<script>' ) === false, 'unknown language ignored' );
		check( isset( $t['scripts']['infopeak-captcha'] ), 'widget script enqueued' );
		check( count( $t['inline_scripts']['infopeak-captcha'] ) === 1, 'listener added once' );
		check( strpos( $t['inline_scripts']['infopeak-captcha'][0], 'gform/submission/pre_submission' ) !== false, 'Gravity Forms listener included' );
		break;

	case 'widget_not_duplicated':
		$form                       = $protected;
		$form['fields'][]           = (object) array( 'id' => 9, 'type' => 'html', 'content' => '<div class="infopeak-captcha" data-sitekey="x"></div>' );
		$html                       = apply_filters( 'gform_submit_button', '<button>Send</button>', $form );
		check( '<button>Send</button>' === $html, 'hand-placed widget is kept, no second one' );
		break;

	case 'form_settings_fields':
		$fields = apply_filters( 'gform_form_settings_fields', array( 'form_options' => array() ), $protected );
		check( isset( $fields['ipkcap'] ), 'section added' );
		check( $fields['ipkcap']['fields'][0]['name'] === 'ipkcap_enabled' && $fields['ipkcap']['fields'][0]['type'] === 'toggle', 'toggle' );
		check( count( $fields['ipkcap']['fields'][1]['choices'] ) === 17, 'page language + 16 languages' );
		check( $fields['ipkcap']['fields'][1]['choices'][0]['value'] === '', 'default follows the page' );
		break;

	case 'no_gravity_forms_loads':
		check( ! class_exists( 'GFForms' ), 'Gravity Forms absent' );
		check( strpos( ipkcap_widget_html(), 'data-sitekey="ipk_test_sitekey"' ) !== false, 'plugin works' );
		check( ipkcap_infopeak_lang() === 'da', 'language helper still maps da_DK' );
		$t['locale'] = 'nb_NO';
		check( ipkcap_infopeak_lang() === 'no', 'nb maps to no' );
		$t['locale'] = 'ja';
		check( ipkcap_infopeak_lang() === '', 'unsupported stays empty' );
		break;

	case 'secret_stays_on_server':
		$t['http_response'] = ipk_test_siteverify( array( 'valid' => false ) );
		$r    = ipk_test_gf_submit( $protected );
		$html = apply_filters( 'gform_submit_button', '<button>Send</button>', $protected );
		$body = json_decode( $t['http_calls'][0]['args']['body'], true );
		check( array_keys( $body ) === array( 'sitekey', 'secret', 'token' ), 'only sitekey, secret and token are sent' );
		check( $body['token'] === 'tok-synthetic-1', 'token sent as posted' );
		$public = $r['message'] . $html . implode( '', $t['inline_scripts']['infopeak-captcha'] );
		check( strpos( $public, 'synthetic-secret-123' ) === false && strpos( $public, 'tok-synthetic-1' ) === false, 'secret and token never in page output' );
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/infopeak-captcha/infopeak-captcha.php' );
		check( ! preg_match( '/\b(error_log|var_dump|print_r|trigger_error)\s*\(/', $src ), 'nothing is logged' );
		break;

	case 'multipage_next_and_back_not_checked':
		$form  = ipk_test_form( 3, array( 'ipkcap_enabled' => '1', 'pages' => 3 ) );
		$_POST = array( 'input_1' => 'Synthetic Student', 'gform_source_page_number_3' => '1', 'gform_target_page_number_3' => '2' );
		$r     = ipk_test_gf_submit( $form );
		check( 2 === $r['page'] && ! $r['saved'] && count( $t['http_calls'] ) === 0, 'next page: nothing saved, nothing checked' );
		$_POST = array( 'input_1' => 'Synthetic Student', 'gform_source_page_number_3' => '3', 'gform_target_page_number_3' => '2' );
		$r     = ipk_test_gf_submit( $form );
		check( 2 === $r['page'] && ! $r['saved'] && count( $t['http_calls'] ) === 0, 'previous page: nothing saved, nothing checked' );
		break;

	case 'multipage_final_checked':
		$form               = ipk_test_form( 3, array( 'ipkcap_enabled' => '1', 'pages' => 3 ) );
		$_POST              = array( 'input_1' => 'Synthetic Student', 'gform_source_page_number_3' => '3', 'gform_target_page_number_3' => '0', 'infopeak-captcha-response' => 'tok-bad' );
		$t['http_response'] = ipk_test_siteverify( array( 'valid' => false ) );
		$r                  = ipk_test_gf_submit( $form );
		check( ! $r['saved'] && empty( $t['notifications'] ) && strpos( $r['message'], 'ipkcap-gf-error' ) !== false, 'final page refused with message' );
		break;

	case 'tampered_target_zero_from_first_page':
		$form  = ipk_test_form( 3, array( 'ipkcap_enabled' => '1', 'pages' => 3 ) );
		$_POST = array( 'input_1' => 'Bot', 'gform_source_page_number_3' => '1', 'gform_target_page_number_3' => '0' );
		$r     = ipk_test_gf_submit( $form );
		check( ! $r['saved'] && empty( $t['entries'] ) && empty( $t['notifications'] ), 'jump to submit from page 1 without a token: nothing saved' );
		break;

	case 'tampered_target_into_hidden_last_pages':
		// Pages 2 and 3 hidden by conditional logic: Gravity Forms turns target 2 into a submit.
		$form  = ipk_test_form( 3, array( 'ipkcap_enabled' => '1', 'pages' => 3, 'hidden_pages' => array( 2, 3 ) ) );
		$_POST = array( 'input_1' => 'Bot', 'gform_source_page_number_3' => '1', 'gform_target_page_number_3' => '2' );
		$r     = ipk_test_gf_submit( $form );
		check( ! $r['saved'] && empty( $t['entries'] ), 'submit through hidden pages without a token: nothing saved' );
		break;

	case 'save_and_continue_creates_no_entry':
		$_POST = array( 'input_1' => 'Synthetic Student', 'gform_save' => '1' );
		$r     = ipk_test_gf_submit( $protected );
		check( ! $r['saved'] && ! empty( $r['draft'] ) && empty( $t['entries'] ) && count( $t['http_calls'] ) === 0, 'draft saved as before, no entry, token untouched' );
		break;

	case 'backstop_blocks_when_page_check_disagrees':
		// Simulates a Gravity Forms version whose is_last_page() says "not last" while
		// process_form still submits. gform_validation skips; the backstop must stop it.
		$t['last_page']     = false;
		$t['http_response'] = ipk_test_siteverify( array( 'valid' => false ) );
		$r                  = ipk_test_gf_submit( $protected );
		check( ! $r['saved'] && empty( $t['entries'] ) && empty( $t['notifications'] ), 'nothing saved or sent' );
		check( isset( $r['aborted'] ) && strpos( $r['aborted'], 'ipkcap-gf-error' ) !== false && strpos( $r['aborted'], 'Please try again' ) !== false, 'ends with Gravity Forms response, carrying the captcha message' );
		check( strpos( $r['aborted'], 'Thanks for contacting us' ) === false, 'no false success message' );
		unset( $_POST['infopeak-captcha-response'] );
		$GLOBALS['ipkcap_gf_passed'] = array();
		$r = ipk_test_gf_submit( $protected );
		check( ! $r['saved'] && isset( $r['aborted'] ), 'no token: stopped too' );
		break;

	case 'backstop_passes_valid_token_once':
		$t['last_page']     = false;
		$t['http_response'] = ipk_test_siteverify( array( 'valid' => true ) );
		$r                  = ipk_test_gf_submit( $protected );
		check( $r['saved'] && count( $t['http_calls'] ) === 1, 'valid token confirmed once by the backstop' );
		break;

	case 'gfapi_submit_form_without_token_blocked':
		$GLOBALS['ipk_test']['forms'][2] = $protected;
		$r                               = GFAPI::submit_form( 2, array( 'input_1' => 'Bot' ) );
		check( ! $r['saved'] && empty( $t['entries'] ) && empty( $t['notifications'] ), 'GFAPI without a token: nothing saved' );
		$t['last_page'] = false;
		$r              = GFAPI::submit_form( 2, array( 'input_1' => 'Bot', 'gform_target_page_number_2' => '5' ) );
		check( ! $r['saved'] && empty( $t['entries'] ), 'GFAPI with page fields set to dodge the check: nothing saved' );
		break;

	case 'gfapi_submit_form_with_token_saves':
		$GLOBALS['ipk_test']['forms'][2] = $protected;
		$t['http_response']              = ipk_test_siteverify( array( 'valid' => true ) );
		$r                               = GFAPI::submit_form( 2, array( 'input_1' => 'Synthetic Student', 'infopeak-captcha-response' => 'tok-synthetic-1' ) );
		check( $r['saved'] && count( $t['http_calls'] ) === 1 && count( $t['notifications'] ) === 1, 'saved and notified once' );
		break;

	case 'pre_27_backstop_dies_before_entry':
		$t['no_abort_filter'] = true;
		$t['last_page']       = false;
		$t['http_response']   = ipk_test_siteverify( array( 'valid' => false ) );
		$r                    = ipk_test_gf_submit( $protected );
		check( ! $r['saved'] && isset( $r['died'] ) && empty( $t['entries'] ) && empty( $t['notifications'] ), 'older Gravity Forms: stopped before the entry' );
		break;

	case 'success_confirmation_untouched':
		$t['http_response'] = ipk_test_siteverify( array( 'valid' => true ) );
		$r                  = ipk_test_gf_submit( $protected );
		check( $r['saved'] && strpos( $r['confirmation'], 'Thanks for contacting us' ) !== false && strpos( $r['confirmation'], 'ipkcap' ) === false, 'normal confirmation kept' );
		break;

	case 'danish_translation_loaded':
		apply_filters( 'init', null );
		$mo = isset( $t['textdomains']['infopeak-captcha'] ) ? $t['textdomains']['infopeak-captcha'] : '';
		check( strpos( str_replace( '\\', '/', $mo ), '/infopeak-captcha/languages/infopeak-captcha-da_DK.mo' ) !== false, 'bundled Danish file loaded: ' . $mo );
		check( is_readable( $mo ), 'file exists' );
		$t['locale'] = 'en_US';
		$t['textdomains'] = array();
		apply_filters( 'init', null );
		check( empty( $t['textdomains'] ), 'nothing loaded for English' );
		break;

	case 'language_pack_takes_precedence':
		@mkdir( WP_LANG_DIR . '/plugins', 0777, true );
		$pack = WP_LANG_DIR . '/plugins/infopeak-captcha-da_DK.mo';
		file_put_contents( $pack, 'x' );
		apply_filters( 'init', null );
		unlink( $pack );
		rmdir( WP_LANG_DIR . '/plugins' );
		rmdir( WP_LANG_DIR );
		check( empty( $t['textdomains'] ), 'WordPress.org pack wins, bundled file not loaded' );
		break;

	default:
		echo "unknown scenario\n";
		exit( 1 );
}
echo "OK";
