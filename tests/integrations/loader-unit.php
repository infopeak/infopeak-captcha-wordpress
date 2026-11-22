<?php
/**
 * Which integration files the plugin loads, and the shared reset script. Each scenario
 * runs in its own PHP process, like one WordPress request.
 *
 *   php tests/integrations/loader-unit.php            runs every scenario
 *   php tests/integrations/loader-unit.php <name>     runs one
 */

$scenarios = array(
	'no_form_plugin_only_core',
	'active_plugins_list_loads_now',
	'renamed_folder_still_matches',
	'network_active_plugin_loads',
	'already_loaded_plugin_loads_now',
	'plugin_found_only_after_plugins_loaded',
	'loaded_after_plugins_loaded_fired',
	'each_file_once',
	'hook_priorities_unchanged',
	'reset_script_shared_helper_and_active_integrations_only',
	'reset_script_once_per_page',
	'reset_script_without_widget_script',
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
		$ok      = 0 === $code && end( $out ) === 'OK';
		$failed += $ok ? 0 : 1;
		echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok ? '' : "\n    " . implode( "\n    ", $out ) ) . "\n";
	}
	echo "\n" . ( count( $scenarios ) - $failed ) . '/' . count( $scenarios ) . " passed\n";
	exit( $failed ? 1 : 0 );
}

$name = $argv[1];
require __DIR__ . '/gravity-forms/wp-stubs.php';

function check( $cond, $what ) {
	if ( ! $cond ) {
		echo "assertion failed: $what\n";
		exit( 1 );
	}
}

$t = &$GLOBALS['ipk_test'];
$t['options']['ipkcap_settings'] = array( 'sitekey' => 'ipk_test_sitekey', 'secret' => 'synthetic-secret-123' );

// One hook per integration that only that integration adds.
$marker = array(
	'core'           => 'authenticate',
	'contact-form-7' => 'wpcf7_spam',
	'wpforms'        => 'wpforms_process_before',
	'jetpack'        => 'jetpack_contact_form_is_spam',
	'woocommerce'    => 'woocommerce_after_checkout_validation',
	'gravity-forms'  => 'gform_validation',
	'fluent-forms'   => 'fluentform/validation_errors',
	'formidable'     => 'frm_validate_entry',
	'forminator'     => 'forminator_custom_form_submit_errors',
	'ninja-forms'    => 'ninja_forms_register_fields',
	'mailpoet'       => 'mailpoet_api_setup',
);
function loaded( $marker ) {
	$on = array();
	foreach ( $marker as $file => $hook ) {
		if ( has_filter( $hook ) ) {
			$on[] = $file;
		}
	}
	return $on;
}
function load_plugin() {
	require dirname( __DIR__, 2 ) . '/infopeak-captcha/infopeak-captcha.php';
}
function fire_plugins_loaded() {
	apply_filters( 'plugins_loaded', null );
}
function reset_script() {
	return implode( "\n", (array) ( $GLOBALS['ipk_test']['inline_scripts']['infopeak-captcha'] ?? array() ) );
}

switch ( $name ) {
	case 'no_form_plugin_only_core':
		load_plugin();
		fire_plugins_loaded();
		check( loaded( $marker ) === array( 'core' ), 'only core: ' . implode( ',', loaded( $marker ) ) );
		check( ! function_exists( 'ipkcap_gf_active' ), 'Gravity Forms file not loaded' );
		check( has_filter( 'preprocess_comment' ) && has_filter( 'lostpassword_post' ) && has_filter( 'registration_errors' ), 'all core forms' );
		check( has_filter( 'admin_menu' ) && has_filter( 'admin_init' ), 'settings page' );
		break;

	case 'active_plugins_list_loads_now':
		$t['options']['active_plugins'] = array( 'contact-form-7/wp-contact-form-7.php', 'wpforms-lite/wpforms.php', 'jetpack/jetpack.php', 'woocommerce/woocommerce.php', 'gravityforms/gravityforms.php', 'fluentform/fluentform.php', 'formidable/formidable.php', 'forminator/forminator.php', 'ninja-forms/ninja-forms.php', 'mailpoet/mailpoet.php' );
		load_plugin();
		check( loaded( $marker ) === array_keys( $marker ), 'all loaded while the plugin file loads: ' . implode( ',', loaded( $marker ) ) );
		break;

	case 'renamed_folder_still_matches':
		$t['options']['active_plugins'] = array( 'gravityforms-2.9/gravityforms.php', 'wpforms/wpforms.php' );
		load_plugin();
		check( loaded( $marker ) === array( 'core', 'wpforms', 'gravity-forms' ), 'matched by main file: ' . implode( ',', loaded( $marker ) ) );
		break;

	case 'network_active_plugin_loads':
		$t['multisite']    = true;
		$t['site_options'] = array( 'active_sitewide_plugins' => array( 'contact-form-7/wp-contact-form-7.php' => 1700000000 ) );
		load_plugin();
		check( loaded( $marker ) === array( 'core', 'contact-form-7' ), 'network-activated plugin found: ' . implode( ',', loaded( $marker ) ) );
		break;

	case 'already_loaded_plugin_loads_now':
		define( 'WPCF7_VERSION', '6.2.5' );
		class WooCommerce {}
		load_plugin();
		check( loaded( $marker ) === array( 'core', 'contact-form-7', 'woocommerce' ), 'found by constant or class: ' . implode( ',', loaded( $marker ) ) );
		break;

	case 'plugin_found_only_after_plugins_loaded':
		load_plugin();
		check( loaded( $marker ) === array( 'core' ), 'nothing yet' );
		// A form plugin that is not in the list (must-use plugin, bundled by the host) has loaded by now.
		define( 'JETPACK__VERSION', '16.4' );
		define( 'FLUENTFORM_VERSION', '6.2.17' );
		function load_formidable_forms() {}
		define( 'FORMINATOR_VERSION', '1.58.6' );
		class Ninja_Forms {}
		define( 'MAILPOET_VERSION', '5.45.2' );
		ipk_test_define_gf();
		fire_plugins_loaded();
		check( loaded( $marker ) === array( 'core', 'jetpack', 'gravity-forms', 'fluent-forms', 'formidable', 'forminator', 'ninja-forms', 'mailpoet' ), 'loaded on plugins_loaded: ' . implode( ',', loaded( $marker ) ) );
		break;

	case 'loaded_after_plugins_loaded_fired':
		// Included after plugins_loaded (for example while being activated): the late check runs at once.
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/infopeak-captcha/infopeak-captcha.php' );
		check( preg_match( "/if \( did_action\( 'plugins_loaded' \) \) \{\s*ipkcap_load_integrations\( true \);/", $src ) === 1, 'did_action branch' );
		break;

	case 'each_file_once':
		$t['options']['active_plugins'] = array( 'contact-form-7/wp-contact-form-7.php' );
		define( 'WPCF7_VERSION', '6.2.5' );
		load_plugin();
		fire_plugins_loaded();
		check( count( $t['filters']['wpcf7_spam'][10] ) === 1, 'CF7 hooks added once' );
		check( count( $t['filters']['authenticate'][30] ) === 1, 'core hooks added once' );
		break;

	case 'hook_priorities_unchanged':
		$t['options']['active_plugins'] = array( 'contact-form-7/wp-contact-form-7.php', 'wpforms-lite/wpforms.php', 'jetpack/jetpack.php', 'woocommerce/woocommerce.php', 'gravityforms/gravityforms.php', 'fluentform/fluentform.php', 'formidable/formidable.php', 'forminator/forminator.php', 'ninja-forms/ninja-forms.php', 'mailpoet/mailpoet.php' );
		load_plugin();
		$expect = array(
			'authenticate' => 30, 'registration_errors' => 10, 'lostpassword_post' => 10, 'preprocess_comment' => 10, 'comment_form_submit_field' => 10,
			'wpcf7_form_elements' => 10, 'wpcf7_spam' => 10, 'wpcf7_display_message' => 10, 'wpcf7_init' => 10,
			'wpforms_display_submit_before' => 10, 'wpforms_process_before' => 10,
			'jetpack_contact_form_html' => 10, 'jetpack_contact_form_is_spam' => 10,
			'woocommerce_after_checkout_billing_form' => 10, 'woocommerce_after_checkout_validation' => 10,
			'render_block_woocommerce/checkout-actions-block' => 10, 'woocommerce_blocks_checkout_block_registration' => 10, 'woocommerce_blocks_loaded' => 10, 'woocommerce_store_api_checkout_update_customer_from_request' => 10,
			'gform_form_settings_fields' => 10, 'gform_submit_button' => 10, 'gform_validation' => 9, 'gform_abort_submission_with_confirmation' => 10,
			'gform_confirmation' => 99, 'gform_pre_submission' => 1, 'gform_validation_message' => 10,
			'fluentform/render_item_submit_button' => 5, 'fluentform/render_item_step_end' => 5, 'fluentform/validation_errors' => 10,
			'fluentform/white_listed_fields' => 10, 'fluentform/insert_response_data' => 10,
			'frm_before_submit_btn' => 10, 'frm_validate_entry' => 10,
			'forminator_before_form_render' => 10, 'forminator_after_form_render' => 10, 'forminator_render_button_markup' => 10,
			'forminator_pagination_submit_markup' => 10, 'forminator_custom_form_submit_errors' => 10, 'forminator_custom_form_invalid_form_message' => 10, 'forminator_submission_error' => 10,
			'ninja_forms_register_fields' => 10, 'ninja_forms_field_template_file_paths' => 10,
			'mailpoet_api_setup' => 10, 'mailpoet_subscription_before_subscribe' => 10,
		);
		foreach ( $expect as $hook => $prio ) {
			check( isset( $t['filters'][ $hook ] ) && array_keys( $t['filters'][ $hook ] ) === array( $prio ), "$hook at priority $prio" );
		}
		break;

	case 'reset_script_shared_helper_and_active_integrations_only':
		$t['options']['active_plugins'] = array( 'contact-form-7/wp-contact-form-7.php', 'woocommerce/woocommerce.php' );
		load_plugin();
		ipkcap_enqueue_for_forms();
		$js = reset_script();
		check( count( $t['inline_scripts']['infopeak-captcha'] ) === 1, 'one inline script after the widget script' );
		check( strpos( $js, 'window.ipkcapForms' ) !== false && strpos( $js, 'ipk.resetOn' ) !== false, 'shared helper' );
		check( strpos( $js, "resetOn('wpcf7submit'" ) !== false && strpos( $js, "resetOn('checkout_error', 'form.checkout'" ) !== false, 'CF7 and WooCommerce listeners' );
		check( strpos( $js, 'wpformsAjaxSubmit' ) === false && strpos( $js, 'grunion' ) === false && strpos( $js, 'gform' ) === false && strpos( $js, 'fluentform_' ) === false && strpos( $js, 'frmForm' ) === false && strpos( $js, 'forminator:' ) === false && strpos( $js, 'nfRadio' ) === false && strpos( $js, 'mailpoet_form' ) === false, 'no listeners for inactive plugins' );
		check( strpos( $js, 'window.ipkcapForms' ) < strpos( $js, 'wpcf7submit' ), 'helper defined before it is used' );
		break;

	case 'reset_script_once_per_page':
		$t['options']['active_plugins'] = array( 'contact-form-7/wp-contact-form-7.php' );
		load_plugin();
		ipkcap_enqueue_for_forms();
		ipkcap_enqueue_for_forms();
		ipkcap_enqueue_for_forms( false );
		check( count( $t['inline_scripts']['infopeak-captcha'] ) === 1 && empty( $t['inline_scripts']['infopeak-captcha-reset'] ), 'listener added once' );
		break;

	case 'reset_script_without_widget_script':
		$t['options']['active_plugins'] = array( 'jetpack/jetpack.php' );
		load_plugin();
		ipkcap_enqueue_for_forms( false );
		check( ! isset( $t['scripts']['infopeak-captcha'] ), 'widget script not added' );
		check( array_key_exists( 'infopeak-captcha-reset', $t['scripts'] ) && strpos( implode( '', $t['inline_scripts']['infopeak-captcha-reset'] ), 'grunion-contact-form' ) !== false, 'listener on its own handle' );
		break;
}

echo "OK\n";
