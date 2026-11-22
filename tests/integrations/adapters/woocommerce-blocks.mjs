// WooCommerce Checkout block (the default checkout in new shops), on /checkout-block/ with the
// content WooCommerce gives a new shop. The block places the order through the Store API and
// moves to "order received" on success; a refusal is a notice in the block. Run as a guest and
// as a logged-in customer.
import { CAPTCHA_ERROR, waitToken } from '../lib/harness.mjs';

const notice = '.wc-block-components-notice-banner.is-error';

function blocks(loggedIn) {
	return {
		key: loggedIn ? 'wooblocks-user' : 'wooblocks',
		name: 'WooCommerce Checkout block' + (loggedIn ? ' (logged in)' : ' (guest)'),
		container: '.wp-block-woocommerce-checkout',
		variants: [{ label: 'Store API', path: '/checkout-block/', ajax: true }],
		noTwoForms: 'one checkout per page',
		noCache: 'page caches never cache the checkout (WooCommerce marks it DONOTCACHEPAGE)',
		mails: true,
		async prepare(page, env) {
			if (loggedIn) {
				// The login form has the widget too.
				await page.goto(env.url('/wp-login.php'), { waitUntil: 'domcontentloaded' });
				await page.fill('#user_login', env.cfg.IPK_USER);
				await page.fill('#user_pass', env.cfg.IPK_USER_PASS);
				await waitToken(page.locator('#loginform'));
				await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.click('#wp-submit')]);
			}
			const { product } = await env.stats();
			await page.goto(env.url('/?add-to-cart=' + product), { waitUntil: 'domcontentloaded' });
		},
		async fill(page, c, n) {
			await c.locator('#email').waitFor({ timeout: 30000 });
			// A returning customer's saved address is shown as a card until it is edited.
			const edit = c.locator('.wc-block-components-address-card__edit');
			if ((await edit.count()) && (await edit.first().isVisible())) await edit.first().click();
			const set = async (sel, v) => { const l = c.locator(sel); if (await l.isEditable()) await l.fill(v); };
			await set('#email', `customer${n}@example.test`);
			await set('#billing-first_name', 'Synthetic');
			await set('#billing-last_name', 'Customer');
			await set('#billing-address_1', 'Testvej 1');
			await set('#billing-postcode', '8000');
			await set('#billing-city', 'Aarhus');
		},
		// The previous attempt's notice is closed the way a shopper would (the block draws it,
		// so it is not taken out of the page directly).
		async beforeSubmit(page) {
			for (const b of await page.locator(notice + ' .wc-block-components-notice-banner__dismiss').all()) await b.click().catch(() => {});
			await page.waitForFunction((sel) => !document.querySelector(sel), notice, { timeout: 10000 });
		},
		submit: (page, c) => c.locator('.wc-block-components-checkout-place-order-button').click(),
		async outcome(page, c) {
			if (/order-received/.test(page.url())) return 'ok';
			const n = page.locator(notice);
			if ((await n.count()) && (await n.first().innerText()).trim()) return 'refused';
			return null;
		},
		// The captcha message, once, in the block's notice. WordPress also reads every notice out
		// through its screen reader region (wp.a11y.speak), which WooCommerce uses for all of them.
		async refusedByCaptcha(page) {
			const text = await page.evaluate(() => {
				const body = document.body.cloneNode(true);
				body.querySelectorAll('.a11y-speak-region, #a11y-speak-intro-text').forEach((e) => e.remove());
				return body.textContent;
			});
			const all = text.match(new RegExp(CAPTCHA_ERROR.source, 'gi')) || [];
			return CAPTCHA_ERROR.test(await page.locator(notice).first().innerText()) && all.length === 1;
		},
	};
}

export const wooBlocks = blocks(false);
export const wooBlocksUser = blocks(true);
