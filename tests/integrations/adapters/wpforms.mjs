// WPForms Lite: an AJAX form (page stays) and a form without AJAX (page reloads).
// The confirmation text carries IPK-WPFORMS-OK (see wp/setup-content.php).
const errorSel = '.wpforms-error-container, .wpforms-error-container-full, .wpforms-error-alert';

export default {
	key: 'wpforms',
	name: 'WPForms',
	container: '.wpforms-container',
	variants: [
		{ label: 'AJAX', path: '/wpforms-ajax/', ajax: true },
		{ label: 'postback', path: '/wpforms-post/', ajax: false },
	],
	twoForms: { path: '/wpforms-two/' },
	cached: { path: '/cached-wpforms/' },
	mails: true,
	async fill(page, c, n) {
		await c.locator('[name="wpforms[fields][0]"]').fill('Synthetic Visitor');
		await c.locator('[name="wpforms[fields][1]"]').fill(`visitor${n}@example.test`);
		await c.locator('[name="wpforms[fields][2]"]').fill('Synthetic message ' + n);
	},
	refillAfterRefusal: false,
	async beforeSubmit(page, c) {
		// Remove the previous attempt's message, so the next outcome is this attempt's.
		await c.evaluate((el, sel) => el.querySelectorAll(sel).forEach((e) => e.remove()), errorSel);
	},
	submit: (page, c) => c.locator('button[type="submit"]').click(),
	async outcome(page, c) {
		if (/IPK-WPFORMS-OK/.test(await c.innerText())) return 'ok';
		const err = c.locator(errorSel);
		if ((await err.count()) && (await err.first().innerText()).trim()) return 'refused';
		return null;
	},
	refusedByCaptcha: async (page, c) => /Captcha verification failed|cannot check your message/i.test(await c.locator(errorSel).first().innerText()),
};
