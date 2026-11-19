// Shared checks for every form integration. Each integration is an adapter
// (tests/integrations/adapters/*.mjs) that says where its form is, how to fill and
// send it and how to read the outcome. The checks themselves live here, so every
// integration, now and later, is tested the same way:
//
//   accepted with a valid token (per submission kind: AJAX, postback, ...)
//   missing token refused, false token refused
//   sent again after a refusal without reloading the page (AJAX) or on the redrawn page (postback)
//   unreachable captcha service refused, then accepted
//   a token that was already used is refused
//   two forms on the same page
//   page served from a page cache
//   375 px and 1280 px wide
//
// Uses the real widget and the public test credentials (ipk_test_sitekey / ipk_test_secret).

import { pathToFileURL } from 'node:url';
import { join } from 'node:path';

export const FIELD = 'infopeak-captcha-response';
export const CAPTCHA_ERROR = /Captcha verification failed|cannot check your message right now/i;
const TOKEN_TIMEOUT = 45000;
const OUTCOME_TIMEOUT = 45000;

export async function launchBrowser() {
	if (!process.env.PLAYWRIGHT_CORE || !process.env.CHROMIUM) {
		throw new Error('Set PLAYWRIGHT_CORE (path to the playwright-core package) and CHROMIUM (path to chrome).');
	}
	const pw = await import(pathToFileURL(join(process.env.PLAYWRIGHT_CORE, 'index.js')).href);
	const chromium = pw.chromium || pw.default.chromium;
	return chromium.launch({ executablePath: process.env.CHROMIUM });
}

export class Runner {
	constructor() {
		this.results = [];
		this.failed = 0;
		this.only = process.env.ONLY_CHECK ? new RegExp(process.env.ONLY_CHECK, 'i') : null;
	}
	async test(name, fn) {
		if (this.only && !this.only.test(name)) return;
		const t0 = Date.now();
		try {
			await fn();
			this.log('PASS ' + name + ` (${((Date.now() - t0) / 1000).toFixed(1)}s)`);
		} catch (e) {
			this.failed++;
			this.log('FAIL ' + name + '\n    ' + String(e && e.stack ? e.stack.split('\n').slice(0, 3).join('\n    ') : e));
		}
	}
	skip(name, why) {
		if (this.only && !this.only.test(name)) return;
		this.log('N/A  ' + name + ' - ' + why);
	}
	log(line) {
		this.results.push(line);
		console.log(line);
	}
	summary() {
		const pass = this.results.filter((r) => r.startsWith('PASS')).length;
		const total = this.results.filter((r) => /^(PASS|FAIL)/.test(r)).length;
		const na = this.results.filter((r) => r.startsWith('N/A')).length;
		return `${pass}/${total} passed, ${na} not applicable`;
	}
}

export function assert(cond, what) {
	if (!cond) throw new Error(what);
}

export function delta(a, b) {
	return {
		calls: b.calls - a.calls,
		valid: b.valid - a.valid,
		invalid: b.invalid - a.invalid,
		failed: b.failed - a.failed,
		mails: b.mails.length - a.mails.length,
	};
}

let counter = 0;
export const unique = () => `${Date.now().toString(36)}${(counter++).toString(36)}`;

// --- The widget's token in a given container (a form or a wrapper around it) ---

export async function waitToken(container, not = '') {
	const handle = await container.elementHandle({ timeout: TOKEN_TIMEOUT });
	await handle.waitForSelector('.infopeak-captcha', { state: 'attached', timeout: TOKEN_TIMEOUT });
	const page = container.page();
	await page.waitForFunction(
		([el, field, old]) => {
			const i = el.querySelector(`input[name="${field}"]`);
			return i && i.value && i.value !== old;
		},
		[handle, FIELD, not],
		{ timeout: TOKEN_TIMEOUT, polling: 100 }
	);
	return container.locator(`input[name="${FIELD}"]`).first().inputValue();
}

export const setToken = (container, value) =>
	container.evaluate((el, [field, v]) => el.querySelectorAll(`input[name="${field}"]`).forEach((i) => (i.value = v)), [FIELD, value]);

export const removeToken = (container) =>
	container.evaluate((el, field) => el.querySelectorAll(`input[name="${field}"]`).forEach((i) => i.remove()), FIELD);

export const widgetInfo = (container) =>
	container.evaluate((el) => {
		const w = el.querySelector('.infopeak-captcha');
		if (!w) return { present: false };
		const r = w.getBoundingClientRect();
		return {
			present: true,
			count: el.querySelectorAll('.infopeak-captcha').length,
			started: w.classList.contains('ipk-cap'),
			left: r.left,
			right: r.right,
			width: r.width,
			height: r.height,
			viewport: window.innerWidth,
			visible: r.width > 0 && r.height > 0 && getComputedStyle(w).visibility !== 'hidden',
		};
	});

export async function pageInfo(page) {
	return page.evaluate(() => ({
		widgets: document.querySelectorAll('.infopeak-captcha').length,
		started: document.querySelectorAll('.infopeak-captcha.ipk-cap').length,
		loaders: document.querySelectorAll('script[src*="infopeak-captcha.js"]').length,
		scrollWidth: document.documentElement.scrollWidth,
		viewport: window.innerWidth,
	}));
}

// --- Driving an adapter ---

const container = (a, page, i = 0) => page.locator(a.container).nth(i);

async function open(a, browser, env, path, viewport = { width: 1280, height: 900 }) {
	const context = await browser.newContext({ viewport, locale: 'en-US' });
	const page = await context.newPage();
	page.on('pageerror', (e) => (page.__errors = (page.__errors || []).concat(e.message)));
	if (a.prepare) await a.prepare(page, env);
	const response = await page.goto(env.url(path), { waitUntil: 'domcontentloaded' });
	return { context, page, response };
}

// Send form i and wait for the adapter to report 'ok' or 'refused'.
export async function send(a, page, i, variant) {
	const c = container(a, page, i);
	if (a.beforeSubmit) await a.beforeSubmit(page, c, variant);
	const nav = variant.ajax ? null : page.waitForNavigation({ timeout: OUTCOME_TIMEOUT, waitUntil: 'domcontentloaded' });
	await a.submit(page, c, variant);
	if (nav) {
		await nav.catch((e) => {
			throw new Error(`no page load after submit (still on ${page.url()}): ${e.message.split('\n')[0]}`);
		});
	}
	const t0 = Date.now();
	for (;;) {
		const o = await a.outcome(page, container(a, page, i), variant).catch(() => null);
		if (o) return o;
		if (Date.now() - t0 > OUTCOME_TIMEOUT) throw new Error('no outcome after submit');
		await page.waitForTimeout(150);
	}
}

async function refusedByCaptcha(a, page, i, variant) {
	if (a.refusedByCaptcha) return a.refusedByCaptcha(page, container(a, page, i), variant);
	return CAPTCHA_ERROR.test(await page.locator('body').innerText());
}

async function fillAndToken(a, page, i, variant) {
	const c = container(a, page, i);
	await a.fill(page, c, unique(), variant);
	return waitToken(c);
}

async function expectAccepted(a, env, page, i, variant, before) {
	const o = await send(a, page, i, variant);
	assert(o === 'ok', 'expected the submission to be accepted, got: ' + o);
	const d = delta(before, await env.stats());
	assert(d.valid === 1, 'one valid siteverify: ' + JSON.stringify(d));
	if (a.mails) assert(d.mails >= 1, 'mail sent: ' + JSON.stringify(d));
}

async function expectRefused(a, env, page, i, variant, before, stat) {
	const o = await send(a, page, i, variant);
	assert(o === 'refused', 'expected a refusal, got: ' + o);
	assert(await refusedByCaptcha(a, page, i, variant), 'the captcha message is shown');
	const d = delta(before, await env.stats());
	assert(d.valid === 0 && d.mails === 0, 'nothing accepted, no mail: ' + JSON.stringify(d));
	if (stat) assert(d[stat] === 1, `one ${stat} siteverify: ` + JSON.stringify(d));
}

// After a refusal: same page for AJAX forms (a new token must arrive without a reload),
// the redrawn page for postback forms, or the form page again where the refusal is an error page.
async function retry(a, env, page, i, variant, oldToken) {
	if (a.retryByOpening) await page.goto(env.url(variant.path), { waitUntil: 'domcontentloaded' });
	const c = container(a, page, i);
	if (!variant.ajax || a.refillAfterRefusal) await a.fill(page, c, unique(), variant);
	const tok = await waitToken(c, oldToken);
	assert(tok !== oldToken, 'a new token after the refusal');
	await expectAccepted(a, env, page, i, variant, await env.stats());
}

export async function runChecks(a, env, browser, runner) {
	const used = {};
	for (const v of a.variants) {
		const tag = `${a.name} [${v.label}]`;

		await runner.test(`${tag} valid token accepted, 1280 px`, async () => {
			const { context, page } = await open(a, browser, env, v.path);
			try {
				const c = container(a, page);
				const tok = await fillAndToken(a, page, 0, v);
				const w = await widgetInfo(c);
				assert(w.present && w.count === 1 && w.started && w.visible, 'one started, visible widget: ' + JSON.stringify(w));
				assert(w.left >= 0 && w.right <= w.viewport, 'widget inside the viewport: ' + JSON.stringify(w));
				const p = await pageInfo(page);
				assert(p.loaders === 1, 'loader script once: ' + JSON.stringify(p));
				await expectAccepted(a, env, page, 0, v, await env.stats());
				used[v.label] = tok;
				assert(!page.__errors, 'no page errors: ' + page.__errors);
			} finally {
				await context.close();
			}
		});

		await runner.test(`${tag} missing token refused`, async () => {
			const { context, page } = await open(a, browser, env, v.path);
			try {
				await fillAndToken(a, page, 0, v);
				const before = await env.stats();
				if (a.submitWithoutToken) {
					const o = await a.submitWithoutToken(page, container(a, page), v, env);
					assert(o === 'refused', 'expected a refusal, got: ' + o);
					const d = delta(before, await env.stats());
					assert(d.valid === 0 && d.mails === 0, 'nothing accepted: ' + JSON.stringify(d));
				} else {
					await removeToken(container(a, page));
					await expectRefused(a, env, page, 0, v, before);
				}
				assert(delta(before, await env.stats()).calls === 0, 'no siteverify call without a token');
			} finally {
				await context.close();
			}
		});

		await runner.test(`${tag} false token refused, then sent again ${v.ajax ? 'without reloading' : 'on the redrawn page'}`, async () => {
			const { context, page } = await open(a, browser, env, v.path);
			try {
				await fillAndToken(a, page, 0, v);
				await setToken(container(a, page), 'not-a-real-token');
				await expectRefused(a, env, page, 0, v, await env.stats(), 'invalid');
				await retry(a, env, page, 0, v, 'not-a-real-token');
			} finally {
				await context.close();
			}
		});

		await runner.test(`${tag} captcha service unreachable: refused, then accepted`, async () => {
			const { context, page } = await open(a, browser, env, v.path);
			try {
				const tok = await fillAndToken(a, page, 0, v);
				await env.outage(1);
				await expectRefused(a, env, page, 0, v, await env.stats(), 'failed');
				await retry(a, env, page, 0, v, tok);
			} finally {
				await env.outage(0);
				await context.close();
			}
		});

		await runner.test(`${tag} token used before is refused`, async () => {
			assert(used[v.label], 'needs the token from the accepted submission');
			const { context, page } = await open(a, browser, env, v.path);
			try {
				await fillAndToken(a, page, 0, v);
				await setToken(container(a, page), used[v.label]);
				await expectRefused(a, env, page, 0, v, await env.stats(), 'invalid');
			} finally {
				await context.close();
			}
		});
	}

	const v0 = a.variants[0];

	if (a.twoForms) {
		await runner.test(`${a.name} two forms on one page`, async () => {
			const v = { ...v0, ...a.twoForms };
			const { context, page } = await open(a, browser, env, v.path);
			try {
				assert((await page.locator(a.container).count()) === 2, 'two forms');
				const [t0, t1] = [await fillAndToken(a, page, 0, v), await fillAndToken(a, page, 1, v)];
				assert(t0 !== t1, 'each form has its own token');
				const p = await pageInfo(page);
				assert(p.widgets === 2 && p.started === 2 && p.loaders === 1, 'two widgets, one loader: ' + JSON.stringify(p));
				await expectAccepted(a, env, page, 1, v, await env.stats());
				await expectAccepted(a, env, page, 0, v, await env.stats());
			} finally {
				await context.close();
			}
		});
	} else {
		runner.skip(`${a.name} two forms on one page`, a.noTwoForms || 'one form per page');
	}

	if (a.cached && env.cache) {
		await runner.test(`${a.name} page served from the page cache`, async () => {
			const v = { ...v0, ...a.cached };
			await env.cache.prime(v.path);
			const { context, page, response } = await open(a, browser, env, v.path);
			try {
				const html = await response.text();
				assert(env.cache.isCached(html, response.headers()), 'served from the page cache: ' + JSON.stringify(response.headers()));
				assert(!/name="infopeak-captcha-response"[^>]*value="[^"]+"/.test(html), 'no token stored in the cached page');
				await fillAndToken(a, page, 0, v);
				await expectAccepted(a, env, page, 0, v, await env.stats());
			} finally {
				await context.close();
			}
		});
	} else {
		runner.skip(`${a.name} page served from the page cache`, a.noCache || 'not available in this test environment');
	}

	await runner.test(`${a.name} [${v0.label}] 375 px: widget fits, valid token accepted`, async () => {
		const { context, page } = await open(a, browser, env, v0.path, { width: 375, height: 812 });
		try {
			await fillAndToken(a, page, 0, v0);
			const w = await widgetInfo(container(a, page));
			assert(w.visible && w.left >= 0 && w.right <= 375, 'widget inside 375 px: ' + JSON.stringify(w));
			const p = await pageInfo(page);
			assert(p.scrollWidth <= 375 || a.allowPageOverflow, 'no horizontal scroll: ' + JSON.stringify(p));
			await expectAccepted(a, env, page, 0, v0, await env.stats());
		} finally {
			await context.close();
		}
	});
}

// Several adapters' forms on one page: every widget starts, each form is sent in turn without a reload.
export async function runMixed(adapters, path, env, browser, runner, extraWidgets = 0) {
	await runner.test(`Mixed page (${adapters.map((a) => a.name).join(', ')}): each form sent without reloading`, async () => {
		const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
		const page = await context.newPage();
		try {
			await page.goto(env.url(path), { waitUntil: 'domcontentloaded' });
			const tokens = [];
			for (const a of adapters) tokens.push(await fillAndToken(a, page, 0, a.variants[0]));
			assert(new Set(tokens).size === tokens.length, 'a token per form');
			const p = await pageInfo(page);
			assert(p.widgets === adapters.length + extraWidgets && p.started === p.widgets && p.loaders === 1, 'all widgets started, one loader: ' + JSON.stringify(p));
			for (const a of adapters) await expectAccepted(a, env, page, 0, a.variants[0], await env.stats());
		} finally {
			await context.close();
		}
	});
}
