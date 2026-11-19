// Contact Form 7: sent with JavaScript (REST), the page stays. CF7 fires wpcf7submit on the
// form with the outcome; a captcha refusal is CF7's own "spam" status.
export default {
	key: 'cf7',
	name: 'Contact Form 7',
	container: 'div.wpcf7',
	variants: [{ label: 'AJAX', path: '/cf7/', ajax: true }],
	twoForms: { path: '/cf7-two/' },
	cached: { path: '/cached-cf7/' },
	mails: true,
	async fill(page, c, n) {
		await c.locator('[name="your-name"]').fill('Synthetic Visitor');
		await c.locator('[name="your-email"]').fill(`visitor${n}@example.test`);
		await c.locator('[name="your-subject"]').fill('Synthetic subject ' + n);
		await c.locator('[name="your-message"]').fill('Synthetic message ' + n);
	},
	async beforeSubmit(page, c) {
		await c.locator('form').evaluate((f) => {
			delete f.dataset.ipkResult;
			f.addEventListener('wpcf7submit', (e) => (f.dataset.ipkResult = e.detail.status), { once: true });
		});
	},
	submit: (page, c) => c.locator('.wpcf7-submit').click(),
	async outcome(page, c) {
		const s = await c.locator('form').getAttribute('data-ipk-result');
		if (!s) return null;
		return s === 'mail_sent' ? 'ok' : s === 'spam' ? 'refused' : 'unexpected status ' + s;
	},
	// CF7 shows its own spam message; the status says the captcha refused it.
	refusedByCaptcha: async (page, c) => (await c.locator('form').getAttribute('data-status')) === 'spam',
};
