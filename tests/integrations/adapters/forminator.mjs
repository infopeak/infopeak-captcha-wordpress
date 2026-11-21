// Forminator (free) custom forms, sent with AJAX (Forminator's default): a one-page form
// and a form with two pages. The thank-you message carries IPK-FMN-OK and the form stays
// on the page after it was sent (see wp/setup-content.php).
const msgSel = '.forminator-response-message';

async function fillIfVisible(loc, value) {
	if (await loc.isVisible()) await loc.fill(value);
}

export default {
	key: 'forminator',
	name: 'Forminator',
	container: 'form.forminator-custom-form',
	variants: [
		{ label: 'AJAX, one page', path: '/forminator/', ajax: true },
		{ label: 'AJAX, two pages', path: '/forminator-pages/', ajax: true },
	],
	twoForms: { path: '/forminator-two/' },
	cached: { path: '/cached-forminator/' },
	mails: true,
	refillAfterRefusal: true,
	async fill(page, c, n) {
		await fillIfVisible(c.locator('input[name="text-1"]'), 'Synthetic Visitor');
		await fillIfVisible(c.locator('input[name="email-1"]'), `visitor${n}@example.test`);
		const next = c.locator('.forminator-button-next');
		if ((await next.count()) && (await next.isVisible())) {
			await next.click();
			await c.locator('textarea').waitFor({ state: 'visible' });
		}
		await fillIfVisible(c.locator('textarea'), 'Synthetic message ' + n);
	},
	async beforeSubmit(page, c) {
		await c.evaluate((el, sel) => el.querySelectorAll(sel).forEach((e) => { e.textContent = ''; e.className = 'forminator-response-message'; }), msgSel);
	},
	submit: (page, c) => c.locator('.forminator-button-submit:visible, .forminator-pagination-submit:visible').first().click(),
	async outcome(page, c) {
		const m = c.locator(msgSel).first();
		const text = (await m.count()) ? (await m.innerText()).trim() : '';
		if (/IPK-FMN-OK/.test(text)) return 'ok';
		if (text && /forminator-error/.test((await m.getAttribute('class')) || '')) return 'refused';
		return null;
	},
	refusedByCaptcha: async (page, c) => /Captcha verification failed|cannot check your message/i.test(await c.locator(msgSel).first().innerText()),
};
