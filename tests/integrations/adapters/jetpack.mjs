// Jetpack Forms: the block form and the classic [contact-form] shortcode. Current Jetpack
// sends both with fetch() and shows the outcome in the page.
const errorSel = '.contact-form__error.show-errors';

export default {
	key: 'jetpack',
	name: 'Jetpack Forms',
	container: '.jetpack-contact-form-container',
	variants: [
		{ label: 'block, AJAX', path: '/jetpack-block/', ajax: true },
		{ label: 'shortcode, AJAX', path: '/jetpack-shortcode/', ajax: true },
	],
	twoForms: { path: '/jetpack-two/' },
	cached: { path: '/cached-jetpack/' },
	mails: true,
	async fill(page, c, n) {
		await c.locator('input.name').fill('Synthetic Visitor');
		await c.locator('input.email').fill(`visitor${n}@example.test`);
		await c.locator('textarea').fill('Synthetic message ' + n);
	},
	async beforeSubmit(page, c) {
		await c.evaluate((el, sel) => el.querySelectorAll(sel).forEach((e) => e.classList.remove('show-errors')), errorSel);
	},
	submit: (page, c) => c.locator('button[type="submit"]').click(),
	async outcome(page, c) {
		if (await c.locator('.contact-form-submission.submission-success').isVisible()) return 'ok';
		const err = c.locator(errorSel);
		if ((await err.count()) && (await err.first().innerText()).trim()) return 'refused';
		return null;
	},
	refusedByCaptcha: async (page, c) => /Captcha verification failed|cannot check your message/i.test(await c.locator(errorSel).first().innerText()),
};
