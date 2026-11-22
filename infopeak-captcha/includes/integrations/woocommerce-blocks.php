<?php
/**
 * The script for WooCommerce's Checkout block, registered through WooCommerce's block
 * integration interface. Loaded from woocommerce.php when the Checkout block registers
 * its integrations.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IPKCAP_WC_Blocks_Integration implements \Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface {

	public function get_name() {
		return 'infopeak-captcha';
	}

	// WooCommerce loads these handles with the Checkout block, only on a page that has it.
	public function initialize() {
		wp_register_script( 'infopeak-captcha-wc-blocks', false, array( 'wp-data', 'wc-blocks-data-store' ), IPKCAP_VERSION, true );
		wp_add_inline_script( 'infopeak-captcha-wc-blocks', ipkcap_wc_blocks_script() );
	}

	public function get_script_handles() {
		return ipkcap_integration_active( 'enable_woo' ) ? array( 'infopeak-captcha-wc-blocks' ) : array();
	}

	public function get_editor_script_handles() {
		return array();
	}

	public function get_script_data() {
		return array();
	}
}

// The Checkout block sends the order with the Store API and only passes on what is in its
// extension data, so the widget's token is put there under 'infopeak-captcha': when it
// changes, and right before the order is placed. When a placed order comes back refused,
// the widget gets a fresh token. The block draws its markup after the page has loaded and
// can draw it again, so widgets it has drawn are started by running the widget script again
// (it leaves started ones alone).
function ipkcap_wc_blocks_script() {
	return <<<'JS'
(function () {
	var data = window.wp && window.wp.data;
	if (!data) return;
	var STORE = 'wc/store/checkout';
	var sent = null, busy = false, loader = null;
	function root() { return document.querySelector('.wp-block-woocommerce-checkout'); }
	function startNew() {
		var tag = document.getElementById('infopeak-captcha-js');
		if (loader || !tag || !tag.src || !document.querySelector('.wp-block-woocommerce-checkout .infopeak-captcha:not(.ipk-cap)')) return;
		loader = document.createElement('script');
		loader.src = tag.src;
		loader.onload = loader.onerror = function () { loader.remove(); loader = null; };
		document.body.appendChild(loader);
	}
	function token() {
		var r = root(), i = r && r.querySelector('input[name="infopeak-captcha-response"]');
		return i ? i.value : '';
	}
	function send() {
		var t = token(), d = data.dispatch(STORE);
		if (t === sent || !d) return;
		var set = d.setExtensionData || d.__internalSetExtensionData;
		if (typeof set !== 'function') return;
		sent = t;
		set('infopeak-captcha', { token: t });
	}
	function placing(e) {
		var el = e.target;
		if (el && el.closest && (el.closest('.wc-block-components-checkout-place-order-button') || (e.type === 'submit' && el.closest('.wp-block-woocommerce-checkout')))) send();
	}
	document.addEventListener('click', placing, true);
	document.addEventListener('submit', placing, true);
	setInterval(function () { startNew(); send(); }, 500);
	data.subscribe(function () {
		var c = data.select(STORE);
		if (!c || typeof c.isProcessing !== 'function') return;
		if (c.isProcessing()) { busy = true; return; }
		if (busy && (c.hasError() || c.isIdle())) {
			busy = false;
			if (c.hasError() && window.ipkcapForms) window.ipkcapForms.reset(root());
		}
	});
})();
JS;
}
