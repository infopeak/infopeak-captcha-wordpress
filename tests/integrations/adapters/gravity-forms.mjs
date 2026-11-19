// Gravity Forms, on the stand-in site (lib/env-gf.mjs): form 2 protected, form 5 protected
// in English, form 7 not protected. Three ways Gravity Forms sends a form: AJAX with the
// markup kept (newer AJAX), AJAX with the markup redrawn (older iframe method) and postback.
const errorSel = '.ipkcap-gf-error';

export default {
	key: 'gf',
	name: 'Gravity Forms (stand-in)',
	container: '.gform_wrapper',
	variants: [
		{ label: 'AJAX, markup kept', path: '/?form=2&mode=ajax-keep', ajax: true },
		{ label: 'AJAX, markup redrawn', path: '/?form=2&mode=ajax-replace', ajax: true },
		{ label: 'postback', path: '/?form=2&mode=postback', ajax: false },
	],
	twoForms: { path: '/?form=2,5&mode=ajax-keep' },
	noCache: 'the stand-in has no page cache; a licensed Gravity Forms site is needed',
	mails: true,
	refillAfterRefusal: true,
	async fill(page, c, n) {
		await c.locator('input[name="input_1"]').fill('Synthetic Student ' + n);
	},
	async beforeSubmit(page, c) {
		await c.evaluate((el, sel) => el.querySelectorAll(sel).forEach((e) => e.remove()), errorSel);
	},
	submit: (page, c) => c.locator('.gform_button').click(),
	async outcome(page, c) {
		if (await c.locator('.gform_confirmation_message').count()) return 'ok';
		if (await c.locator(errorSel).count()) return 'refused';
		return null;
	},
	refusedByCaptcha: async (page, c) => /Captcha verification failed|cannot check your message/i.test(await c.locator(errorSel).first().innerText()),
	// With JavaScript the form waits for a token before it is sent (so a quick click is not
	// refused). A bot sends the form without the widget, which is what this does.
	async submitWithoutToken(page, c, v) {
		if (v.ajax) {
			const r = await c.locator('form').evaluate(async (f) => {
				const fd = new FormData(f);
				fd.delete('infopeak-captcha-response');
				return (await fetch('/submit?form=' + f.id.replace('gform_', ''), { method: 'POST', body: fd })).json();
			});
			return r.is_valid ? 'ok' : /Captcha verification failed/.test(r.message) ? 'refused' : 'unexpected: ' + r.message;
		}
		await Promise.all([
			page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
			c.locator('form').evaluate((f) => {
				f.querySelectorAll('input[name="infopeak-captcha-response"]').forEach((i) => i.remove());
				HTMLFormElement.prototype.submit.call(f);
			}),
		]);
		return this.outcome(page, page.locator(this.container).first());
	},
};
