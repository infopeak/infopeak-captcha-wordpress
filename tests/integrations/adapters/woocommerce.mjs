// WooCommerce classic checkout ([woocommerce_checkout] shortcode): placed with AJAX, the
// page stays on a refusal and moves to "order received" on success.
const noticeSel = '.woocommerce-NoticeGroup-checkout, .woocommerce-error';

export default {
	key: 'woo',
	name: 'WooCommerce checkout (classic)',
	container: 'form.checkout',
	variants: [{ label: 'AJAX', path: '/checkout/', ajax: true }],
	noTwoForms: 'one checkout per page',
	noCache: 'page caches never cache the checkout (WooCommerce marks it DONOTCACHEPAGE)',
	mails: true,
	async prepare(page, env) {
		const { product } = await env.stats();
		await page.goto(env.url('/?add-to-cart=' + product), { waitUntil: 'domcontentloaded' });
	},
	async fill(page, c, n) {
		const set = (id, v) => c.locator('#' + id).fill(v);
		await set('billing_first_name', 'Synthetic');
		await set('billing_last_name', 'Customer');
		await set('billing_address_1', 'Testvej 1');
		await set('billing_postcode', '8000');
		await set('billing_city', 'Aarhus');
		await set('billing_phone', '12345678');
		await set('billing_email', `customer${n}@example.test`);
	},
	async beforeSubmit(page, c) {
		// WooCommerce redraws the order review and payment area with AJAX; wait until it is done.
		await page.waitForFunction(() => !document.querySelector('.blockUI.blockOverlay'), null, { timeout: 30000 });
		await page.evaluate((sel) => document.querySelectorAll(sel).forEach((e) => e.remove()), noticeSel);
	},
	submit: (page) => page.locator('#place_order').click(),
	async outcome(page) {
		if (/order-received/.test(page.url())) return 'ok';
		const n = page.locator(noticeSel);
		if ((await n.count()) && (await n.first().innerText()).trim()) return 'refused';
		return null;
	},
	refusedByCaptcha: async (page) => /Captcha verification failed|cannot check your message/i.test(await page.locator(noticeSel).first().innerText()),
};
