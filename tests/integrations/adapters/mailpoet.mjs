// MailPoet (free) subscription forms, sent with AJAX: a shortcode form, a form MailPoet
// places below a page and a pop-up (see wp/setup-content.php). No confirmation email on the
// test site; an accepted subscription counts as a record (wp/mu-plugin). The success message
// carries IPK-MP-OK.
import { CAPTCHA_ERROR } from '../lib/harness.mjs';

const ok = '.mailpoet_validate_success';
const err = '.mailpoet_validate_error';

export default {
	key: 'mailpoet',
	name: 'MailPoet',
	container: 'form.mailpoet_form_form',
	variants: [
		{ label: 'AJAX, shortcode', path: '/mailpoet/', ajax: true },
		{ label: 'AJAX, below the page', path: '/mailpoet-below/', ajax: true },
		{ label: 'AJAX, pop-up', path: '/mailpoet-popup/', ajax: true },
	],
	twoForms: { path: '/mailpoet-two/' },
	cached: { path: '/cached-mailpoet/' },
	records: true,
	async fill(page, c, n) {
		const email = c.locator('.mailpoet_paragraph input[type="email"]');
		await email.waitFor({ state: 'visible' });
		await email.fill('');
		// Typed at a person's pace: MailPoet's own check of how the form was filled in (time on
		// the form, key presses) otherwise asks for its built-in CAPTCHA.
		await email.pressSequentially(`subscriber${n}@example.test`, { delay: 80 });
	},
	async beforeSubmit(page, c) {
		await c.evaluate((el, [a, b]) => el.querySelectorAll(a + ',' + b).forEach((p) => { p.style.display = 'none'; if (p.matches(b)) p.textContent = ''; }), [ok, err]);
	},
	submit: (page, c) => c.locator('input.mailpoet_submit').click(),
	async outcome(page, c) {
		const s = c.locator(ok);
		if ((await s.isVisible()) && /IPK-MP-OK/.test(await s.innerText())) return 'ok';
		const e = c.locator(err);
		if ((await e.isVisible()) && (await e.innerText()).trim()) return 'refused';
		return null;
	},
	// The captcha message, shown once in the form's own error line.
	async refusedByCaptcha(page, c) {
		const text = await c.innerText();
		const all = text.match(new RegExp(CAPTCHA_ERROR.source, 'gi')) || [];
		return CAPTCHA_ERROR.test(await c.locator(err).innerText()) && all.length === 1;
	},
};
