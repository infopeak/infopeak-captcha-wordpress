// WooCommerce My Account (/my-account/): login and registration side by side, lost password on
// its own page, and the login form at the classic checkout. All of them post back; a refusal is
// a WooCommerce error notice on the redrawn page.
import { CAPTCHA_ERROR } from '../lib/harness.mjs';

// Block themes show WooCommerce's notices as banners, classic themes as a list.
const error = '.woocommerce-notices-wrapper .wc-block-components-notice-banner.is-error, .woocommerce-error';
const signedIn = async (page) => (await page.context().cookies()).some((c) => c.name.startsWith('wordpress_logged_in_'));
const isRegister = (c) => c.evaluate((el) => el.classList.contains('woocommerce-form-register'));

async function refused(page) {
	const e = page.locator(error);
	return (await e.count()) && (await e.first().innerText()).trim() ? 'refused' : null;
}

// The captcha message, once, in WooCommerce's error notice (screen reader copies left out).
async function refusedByCaptcha(page) {
	const text = await page.evaluate(() => {
		const body = document.body.cloneNode(true);
		body.querySelectorAll('.a11y-speak-region, #a11y-speak-intro-text').forEach((e) => e.remove());
		return body.textContent;
	});
	const all = text.match(new RegExp(CAPTCHA_ERROR.source, 'gi')) || [];
	return CAPTCHA_ERROR.test(await page.locator(error).first().innerText()) && all.length === 1;
}

async function fillLogin(page, c) {
	await c.locator('#username').fill(globalThis.IPK_CFG.IPK_USER);
	await c.locator('#password').fill(globalThis.IPK_CFG.IPK_USER_PASS);
}

async function fillRegister(page, c, n) {
	await c.locator('#reg_email').fill(`wcreg${n}@example.test`);
}

// Login and registration share the page; form 0 is the login form, form 1 the registration form.
export const wooLogin = {
	key: 'woo-login',
	name: 'WooCommerce My Account login',
	container: 'form.woocommerce-form-login, form.woocommerce-form-register',
	variants: [{ label: 'postback', path: '/my-account/', ajax: false }],
	// A successful registration signs the customer in, so the login form is sent from the page opened again.
	twoForms: { path: '/my-account/', reopen: true },
	noCache: 'page caches never cache My Account (WooCommerce marks it DONOTCACHEPAGE)',
	refillAfterRefusal: true,
	async fill(page, c, n) {
		if (await isRegister(c)) await fillRegister(page, c, n);
		else await fillLogin(page, c);
	},
	submit: async (page, c) => c.locator((await isRegister(c)) ? 'button[name="register"]' : 'button[name="login"]').click(),
	async outcome(page) {
		if ((await signedIn(page)) && (await page.locator('.woocommerce-MyAccount-navigation').count())) return 'ok';
		return refused(page);
	},
	refusedByCaptcha,
};

export const wooRegister = {
	key: 'woo-register',
	name: 'WooCommerce My Account registration',
	container: 'form.woocommerce-form-register',
	variants: [{ label: 'postback', path: '/my-account/', ajax: false }],
	noTwoForms: 'covered by the login check: login and registration on one page',
	noCache: 'page caches never cache My Account (WooCommerce marks it DONOTCACHEPAGE)',
	mails: true,
	refillAfterRefusal: true,
	fill: fillRegister,
	submit: (page, c) => c.locator('button[name="register"]').click(),
	async outcome(page) {
		if ((await signedIn(page)) && (await page.locator('.woocommerce-MyAccount-navigation').count())) return 'ok';
		return refused(page);
	},
	refusedByCaptcha,
};

export const wooLostpw = {
	key: 'woo-lostpw',
	name: 'WooCommerce My Account lost password',
	container: 'form.woocommerce-ResetPassword',
	variants: [{ label: 'postback', path: '/my-account/lost-password/', ajax: false }],
	noTwoForms: 'one form on the lost password page',
	noCache: 'page caches never cache My Account (WooCommerce marks it DONOTCACHEPAGE)',
	mails: true,
	refillAfterRefusal: true,
	async fill(page, c) {
		await c.locator('#user_login').fill(globalThis.IPK_CFG.IPK_USER);
	},
	submit: (page, c) => c.locator('button[type="submit"]').click(),
	async outcome(page) {
		if (/reset-link-sent=true/.test(page.url())) return 'ok';
		return refused(page);
	},
	refusedByCaptcha,
};

// "Returning customer? Click here to login" above the classic checkout.
export const wooCheckoutLogin = {
	key: 'woo-checkout-login',
	name: 'WooCommerce checkout login (classic)',
	container: 'form.woocommerce-form-login',
	variants: [{ label: 'postback', path: '/checkout/', ajax: false }],
	noTwoForms: 'one login form at checkout',
	noCache: 'page caches never cache the checkout (WooCommerce marks it DONOTCACHEPAGE)',
	refillAfterRefusal: true,
	async prepare(page, env) {
		const { product } = await env.stats();
		await page.goto(env.url('/?add-to-cart=' + product), { waitUntil: 'domcontentloaded' });
	},
	async fill(page, c) {
		if (!(await c.isVisible())) {
			await page.locator('a.showlogin').click();
			await c.waitFor({ state: 'visible' });
		}
		await fillLogin(page, c);
	},
	submit: (page, c) => c.locator('button[name="login"]').click(),
	async outcome(page) {
		if ((await signedIn(page)) && /\/checkout\//.test(page.url()) && (await page.locator('form.checkout').count()) && !(await page.locator(error).count())) return 'ok';
		return refused(page);
	},
	refusedByCaptcha,
};
