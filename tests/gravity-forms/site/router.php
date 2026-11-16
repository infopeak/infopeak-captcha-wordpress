<?php
/**
 * Router for `php -S`: a page with Gravity Forms-like markup and a tiny stand-in for
 * Gravity Forms' submission JS (gform.utils filters, gform/post_render), backed by
 * the real plugin hooks. Siteverify goes to the local proxy (SITEVERIFY_PROXY), which
 * forwards to captcha.infopeak.io with the public test credentials.
 *
 * Forms: 2 = protected, page language; 5 = protected, English; 7 = not protected.
 * ?mode=ajax-replace (markup redrawn after a failed submit, like the iframe method),
 * ajax-keep (markup kept, like the newer AJAX method) or postback.
 */

require dirname( __DIR__ ) . '/wp-stubs.php';
ipk_test_define_gf();
$t = &$GLOBALS['ipk_test'];
$t['options']['ipkcap_settings'] = array( 'sitekey' => 'ipk_test_sitekey', 'secret' => 'ipk_test_secret' );
$t['http_response'] = function ( $url, $args ) {
	if ( 'https://captcha.infopeak.io/api/v1/pow/siteverify' !== $url ) {
		return new WP_Error( 'unexpected_url', $url );
	}
	$ctx  = stream_context_create( array( 'http' => array( 'method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $args['body'], 'ignore_errors' => true, 'timeout' => $args['timeout'] ) ) );
	$body = @file_get_contents( getenv( 'SITEVERIFY_PROXY' ), false, $ctx ); // phpcs:ignore
	if ( false === $body ) {
		return new WP_Error( 'http_request_failed', 'proxy unreachable' );
	}
	preg_match( '/\s(\d{3})\s/', $http_response_header[0], $m );
	return array( 'response' => array( 'code' => (int) $m[1] ), 'body' => $body );
};
require dirname( __DIR__, 3 ) . '/infopeak-captcha/infopeak-captcha.php';

$forms = array(
	2 => ipk_test_form( 2, array( 'ipkcap_enabled' => '1' ) ),
	5 => ipk_test_form( 5, array( 'ipkcap_enabled' => '1', 'ipkcap_lang' => 'en' ) ),
	7 => ipk_test_form( 7 ),
);
$id   = isset( $_GET['form'] ) && isset( $forms[ (int) $_GET['form'] ] ) ? (int) $_GET['form'] : 2;
$form = $forms[ $id ];
$mode = isset( $_GET['mode'] ) ? preg_replace( '/[^a-z-]/', '', $_GET['mode'] ) : 'ajax-replace';
$log  = getenv( 'ENTRY_LOG' );

function ipk_site_submit( $form, $log ) {
	$r = ipk_test_gf_submit( $form );
	if ( $r['saved'] ) {
		$size = isset( $_FILES['input_3']['size'] ) ? (int) $_FILES['input_3']['size'] : 0;
		file_put_contents( $log, json_encode( array( 'form' => $form['id'], 'file_bytes' => $size, 'notified' => true ) ) . "\n", FILE_APPEND );
	}
	return $r;
}

function ipk_site_form( $form, $mode, $message = '' ) {
	$id     = (int) $form['id'];
	$button = apply_filters( 'gform_submit_button', '<button type="submit" class="gform_button button" id="gform_submit_button_' . $id . '">Send</button>', $form );
	return '<div class="gform_validation_errors">' . $message . '</div>'
		. '<form id="gform_' . $id . '" method="post" enctype="multipart/form-data" action="/?form=' . $id . '&mode=' . $mode . '">'
		. '<label>Name <input name="input_1" id="input_' . $id . '_1"></label>'
		. '<label>File <input type="file" name="input_3" id="input_' . $id . '_3"></label>'
		. '<input type="hidden" name="gform_target_page_number_' . $id . '" value="0">'
		. '<div class="gform_footer">' . $button . '</div></form>';
}

$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );

if ( '/submit' === $path ) {
	$r = ipk_site_submit( $form, $log );
	header( 'Content-Type: application/json' );
	echo json_encode( array( 'is_valid' => $r['saved'], 'message' => $r['message'], 'markup' => $r['saved'] ? '' : ipk_site_form( $form, $mode, $r['message'] ) ) );
	return true;
}

$inner = '';
if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
	$r     = ipk_site_submit( $form, $log );
	$inner = $r['saved'] ? '<div class="gform_confirmation_message">Thanks</div>' : ipk_site_form( $form, $mode, $r['message'] );
} else {
	$inner = ipk_site_form( $form, $mode );
}
?><!doctype html>
<html lang="da-DK">
<head><meta charset="utf-8"><title>GF test</title></head>
<body>
<div class="gform_wrapper" id="gform_wrapper_<?php echo (int) $id; ?>"><?php echo $inner; // phpcs:ignore ?></div>
<?php if ( isset( $t['scripts']['infopeak-captcha'] ) ) : ?>
<script src="https://captcha.infopeak.io/infopeak-captcha.js?ver=<?php echo IPKCAP_VERSION; ?>" id="infopeak-captcha-js"></script>
<script id="infopeak-captcha-js-after"><?php echo implode( "\n", $t['inline_scripts']['infopeak-captcha'] ); // phpcs:ignore ?></script>
<?php endif; ?>
<script>
// Stand-in for Gravity Forms' submission JS, loaded after the plugin's listener as on a real page.
(function () {
	var FORM = <?php echo (int) $id; ?>, MODE = <?php echo json_encode( $mode ); ?>;
	var filters = {};
	window.gform = { utils: {
		addAsyncFilter: function (n, fn) { (filters[n] = filters[n] || []).push(fn); },
		apply: async function (n, d) { for (var fn of filters[n] || []) d = await fn(d); return d; }
	} };
	document.dispatchEvent(new Event('gform/theme/scripts_loaded'));
	document.addEventListener('click', async function (e) {
		var btn = e.target.closest && e.target.closest('.gform_button');
		if (!btn) return;
		e.preventDefault();
		var form = btn.form;
		window.__submitting = true;
		var data = await gform.utils.apply('gform/submission/pre_submission', { form: form, submissionType: 'submit', submissionMethod: MODE === 'postback' ? 'postback' : 'ajax', abort: false });
		if (data.abort) return;
		if (MODE === 'postback') { HTMLFormElement.prototype.submit.call(form); return; }
		var res = await fetch('/submit?form=' + FORM + '&mode=' + MODE, { method: 'POST', body: new FormData(form) });
		var result = await res.json();
		await gform.utils.apply('gform/ajax/post_ajax_submission', { form: form, submissionResult: result });
		var wrap = document.getElementById('gform_wrapper_' + FORM);
		if (result.is_valid) wrap.innerHTML = '<div class="gform_confirmation_message">Thanks</div>';
		else if (MODE === 'ajax-replace') wrap.innerHTML = result.markup;
		else wrap.querySelector('.gform_validation_errors').innerHTML = result.message;
		document.dispatchEvent(new CustomEvent('gform/post_render', { detail: { formId: FORM, currentPage: 1 } }));
		window.__submitting = false;
	});
})();
</script>
</body>
</html>
