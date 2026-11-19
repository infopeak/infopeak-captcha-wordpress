// Fluent Forms (free): sent with AJAX, the page stays. The confirmation carries
// IPK-FLUENT-OK and hides the form (see wp/setup-content.php). Multi-step forms are a
// Pro feature and are not covered here.
const errorSel = '.ff-errors-in-stack .error, .ff-el-is-error .error';

export default {
	key: 'fluent',
	name: 'Fluent Forms',
	container: '.fluentform',
	variants: [{ label: 'AJAX', path: '/fluent/', ajax: true }],
	twoForms: { path: '/fluent-two/' },
	cached: { path: '/cached-fluent/' },
	mails: true,
	async fill(page, c, n) {
		await c.locator('[name="full_name"]').fill('Synthetic Visitor');
		await c.locator('[name="email"]').fill(`visitor${n}@example.test`);
		await c.locator('[name="message"]').fill('Synthetic message ' + n);
	},
	async beforeSubmit(page, c) {
		// Fluent Forms ignores any submit within 1.5 s of the previous one, on any form of the
		// page (window.ff_sumitting_form), with or without InfoPeak. Wait that out like a person would.
		const wait = (page.__ffLastSubmit || 0) + 1600 - Date.now();
		if (wait > 0) await page.waitForTimeout(wait);
		await c.evaluate((el, sel) => el.querySelectorAll(sel).forEach((e) => e.remove()), errorSel);
	},
	async submit(page, c) {
		page.__ffLastSubmit = Date.now();
		await c.locator('.ff-btn-submit').click();
	},
	async outcome(page, c) {
		if (/IPK-FLUENT-OK/.test(await c.innerText())) return 'ok';
		const err = c.locator(errorSel);
		if ((await err.count()) && (await err.first().innerText()).trim()) return 'refused';
		return null;
	},
	refusedByCaptcha: async (page, c) => /Captcha verification failed|cannot check your message/i.test(await c.locator(errorSel).first().innerText()),
};
