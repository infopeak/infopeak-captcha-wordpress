// Mailchimp for WordPress (free): the default sign-up form as a shortcode and as a block. The
// free version posts back; the answer is a notice in the redrawn form. The Mailchimp API is
// answered by the test helper (wp/mu-plugin); an accepted sign-up counts as a record and the
// success message carries IPK-MC4WP-OK.
import { CAPTCHA_ERROR } from '../lib/harness.mjs';

const ok = '.mc4wp-alert.mc4wp-success';
const err = '.mc4wp-alert.mc4wp-error';

export default {
	key: 'mc4wp',
	name: 'Mailchimp for WordPress',
	container: 'form.mc4wp-form',
	variants: [
		{ label: 'postback, shortcode', path: '/mc4wp/', ajax: false },
		{ label: 'postback, block', path: '/mc4wp-block/', ajax: false },
	],
	twoForms: { path: '/mc4wp-two/' },
	cached: { path: '/cached-mc4wp/' },
	records: true,
	refillAfterRefusal: true,
	async fill(page, c, n) {
		await c.locator('input[name="EMAIL"]').fill(`mc4wp${n}@example.test`);
	},
	// MC4WP refuses a form sent within 2 seconds of the page being drawn.
	async beforeSubmit(page) {
		await page.waitForFunction(() => performance.now() > 2500, null, { timeout: 10000 });
	},
	submit: (page, c) => c.locator('input[type="submit"]').click(),
	async outcome(page, c) {
		const s = c.locator(ok);
		if ((await s.count()) && /IPK-MC4WP-OK/.test(await s.innerText())) return 'ok';
		const e = c.locator(err);
		if ((await e.count()) && (await e.innerText()).trim()) return 'refused';
		return null;
	},
	// The captcha message, once, in the form's own notice, and nothing else refused it.
	async refusedByCaptcha(page, c) {
		const all = (await page.locator('body').innerText()).match(new RegExp(CAPTCHA_ERROR.source, 'gi')) || [];
		return (await c.locator(err).count()) === 1 && CAPTCHA_ERROR.test(await c.locator(err).innerText()) && all.length === 1;
	},
};
