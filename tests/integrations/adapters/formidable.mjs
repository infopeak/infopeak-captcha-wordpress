// Formidable Forms (free): an AJAX form (page stays) and a form without AJAX (page
// reloads). The success message carries IPK-FRM-OK (see wp/setup-content.php).
// The captcha refusal is a form-level error, shown in Formidable's error summary.
const errorSel = '.frm_error_style, [data-frm-error-summary]';

export default {
	key: 'formidable',
	name: 'Formidable Forms',
	container: '.frm_forms',
	variants: [
		{ label: 'AJAX', path: '/formidable-ajax/', ajax: true },
		{ label: 'postback', path: '/formidable-post/', ajax: false },
	],
	twoForms: { path: '/formidable-two/' },
	cached: { path: '/cached-formidable/' },
	mails: true,
	async fill(page, c, n) {
		await c.locator('input[type="text"]').first().fill('Synthetic Visitor');
		await c.locator('input[type="email"]').first().fill(`visitor${n}@example.test`);
		await c.locator('textarea').first().fill('Synthetic message ' + n);
	},
	async beforeSubmit(page, c) {
		await c.evaluate((el, sel) => el.querySelectorAll(sel).forEach((e) => e.remove()), errorSel);
	},
	submit: (page, c) => c.locator('.frm_button_submit, button[type="submit"], input[type="submit"]').first().click(),
	async outcome(page, c) {
		if (/IPK-FRM-OK/.test(await c.innerText())) return 'ok';
		const err = c.locator(errorSel);
		if ((await err.count()) && (await err.first().innerText()).trim()) return 'refused';
		return null;
	},
	refusedByCaptcha: async (page, c) => /Captcha verification failed|cannot check your message/i.test(await c.innerText()),
};
