// Ninja Forms (free), sent with AJAX (Ninja Forms always sends this way). The forms have the
// InfoPeak Captcha field, an admin email and the success message IPK-NF-OK; after a successful
// submit the form is hidden, as by default (see wp/setup-content.php).
import { CAPTCHA_ERROR } from '../lib/harness.mjs';

const field = '.nf-field-container.infopeak_captcha-container';

export default {
	key: 'ninja',
	name: 'Ninja Forms',
	container: '.nf-form-cont',
	variants: [{ label: 'AJAX', path: '/ninja/', ajax: true }],
	twoForms: { path: '/ninja-two/' },
	cached: { path: '/cached-ninja/' },
	mails: true,
	async fill(page, c, n) {
		const name = c.locator('input[type="text"].nf-element').first();
		await name.waitFor({ state: 'visible' });
		await name.fill('Synthetic Visitor');
		await c.locator('input[type="email"].nf-element').fill(`visitor${n}@example.test`);
		await c.locator('textarea.nf-element').fill('Synthetic message ' + n);
	},
	submit: (page, c) => c.locator('.submit-container input[type="submit"], .submit-container input[type="button"]').click(),
	async outcome(page, c) {
		const ok = c.locator('.nf-response-msg');
		if ((await ok.count()) && /IPK-NF-OK/.test(await ok.innerText())) return 'ok';
		const err = c.locator(field + ' .nf-error-msg');
		if ((await err.count()) && (await err.first().innerText()).trim()) return 'refused';
		return null;
	},
	// The captcha message, once, under the field.
	async refusedByCaptcha(page, c) {
		const all = (await c.innerText()).match(new RegExp(CAPTCHA_ERROR.source, 'gi')) || [];
		return CAPTCHA_ERROR.test(await c.locator(field + ' .nf-error-msg').first().innerText()) && all.length === 1;
	},
};
