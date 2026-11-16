// Gravity Forms integration in a real browser, with the live widget and the public
// test credentials (ipk_test_sitekey / ipk_test_secret, never counted). Synthetic data only.
//
//   PLAYWRIGHT_CORE=<path to playwright-core> CHROMIUM=<chrome.exe> node tests/gravity-forms/browser.mjs
//
// Starts `php -S` with site/router.php and a siteverify proxy that forwards to
// captcha.infopeak.io, counts calls and can simulate a failure. Tokens are never logged.

import http from 'node:http';
import { spawn } from 'node:child_process';
import { mkdtempSync, readFileSync, writeFileSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const pw = await import(pathToFileURL(join(process.env.PLAYWRIGHT_CORE, 'index.js')).href);
const chromium = pw.chromium || pw.default.chromium;
const work = mkdtempSync(join(tmpdir(), 'ipkcap-gf-'));
const entryLog = join(work, 'entries.jsonl');
const upload = join(work, 'synthetic-application.pdf');
writeFileSync(upload, '%PDF-1.4\n% synthetic test file\n' + 'x'.repeat(2048));

// --- Siteverify proxy ---
const stats = { calls: 0, valid: 0, invalid: 0, failNext: 0 };
const proxy = http.createServer((req, res) => {
	let body = '';
	req.on('data', (c) => (body += c));
	req.on('end', async () => {
		stats.calls++;
		const keys = Object.keys(JSON.parse(body));
		if (keys.join() !== 'sitekey,secret,token') throw new Error('unexpected siteverify body keys: ' + keys);
		if (stats.failNext > 0) {
			stats.failNext--;
			res.writeHead(503).end('{"error":"simulated outage"}');
			return;
		}
		const r = await fetch('https://captcha.infopeak.io/api/v1/pow/siteverify', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body });
		const text = await r.text();
		JSON.parse(text).valid === true ? stats.valid++ : stats.invalid++;
		res.writeHead(r.status, { 'Content-Type': 'application/json' }).end(text);
	});
});
await new Promise((r) => proxy.listen(8791, '127.0.0.1', r));

const php = spawn('php', ['-S', '127.0.0.1:8790', join(here, 'site', 'router.php')], {
	env: { ...process.env, SITEVERIFY_PROXY: 'http://127.0.0.1:8791/', ENTRY_LOG: entryLog },
	stdio: 'ignore',
});
await new Promise((r) => setTimeout(r, 800));

const entries = () => (existsSync(entryLog) ? readFileSync(entryLog, 'utf8').trim().split('\n').filter(Boolean).map(JSON.parse) : []);
const results = [];
let failed = 0;
async function test(name, fn) {
	const before = { ...stats };
	try {
		await fn(before);
		results.push('PASS ' + name);
	} catch (e) {
		failed++;
		results.push('FAIL ' + name + '\n    ' + e.message);
	}
}
function assert(cond, what) {
	if (!cond) throw new Error(what);
}

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM });
const page = await browser.newPage();
page.on('pageerror', (e) => results.push('PAGE ERROR ' + e.message));

const base = 'http://127.0.0.1:8790/';
const counts = () => page.evaluate(() => ({
	widgets: document.querySelectorAll('.infopeak-captcha').length,
	started: document.querySelectorAll('.infopeak-captcha.ipk-cap').length,
	loaders: document.querySelectorAll('script[src*="infopeak-captcha.js"]').length,
	css: document.querySelectorAll('#ipk-captcha-css').length,
	tokens: document.querySelectorAll('input[name="infopeak-captcha-response"]').length,
	label: (document.querySelector('.ipk-cap__label') || {}).textContent || '',
}));
const fill = async (id, name, withFile) => {
	await page.fill(`#input_${id}_1`, name);
	if (withFile) await page.setInputFiles(`#input_${id}_3`, upload);
};
const send = async (id) => {
	await page.click(`#gform_submit_button_${id}`);
	await page.waitForFunction(() => window.__submitting === false || !!document.querySelector('.gform_confirmation_message'), null, { timeout: 30000 });
};
const confirmed = () => page.locator('.gform_confirmation_message').count();
const errorText = () => page.locator('.ipkcap-gf-error').allTextContents();

await test('AJAX (markup redrawn): file upload, quick click waits for the token, entry saved', async (b) => {
	await page.goto(base + '?form=2&mode=ajax-replace');
	let c = await counts();
	assert(c.widgets === 1 && c.started === 1 && c.loaders === 1, 'one widget, one loader: ' + JSON.stringify(c));
	assert(c.label === 'Jeg er et menneske', 'page language (da-DK): ' + c.label);
	const n = entries().length;
	await fill(2, 'Synthetic Student', true);
	await send(2); // Clicked right away: the pre_submission hook waits for the solve.
	assert((await confirmed()) === 1, 'confirmation shown');
	assert(stats.calls - b.calls === 1 && stats.valid - b.valid === 1, 'one siteverify, valid');
	const e = entries().slice(n);
	assert(e.length === 1 && e[0].file_bytes > 2000, 'entry saved with the uploaded file: ' + JSON.stringify(e));
});

await test('AJAX (markup redrawn): field error spends no token, new widget starts once, retry saves', async (b) => {
	await page.goto(base + '?form=5&mode=ajax-replace');
	let c = await counts();
	assert(c.label === 'I am human', 'English on a da-DK page: ' + c.label);
	await page.locator('#input_5_1').focus();
	await send(5); // Name empty.
	assert(stats.calls - b.calls === 0, 'no siteverify on a field error');
	await page.waitForFunction(() => document.querySelectorAll('.infopeak-captcha.ipk-cap').length === 1);
	c = await counts();
	assert(c.widgets === 1 && c.started === 1 && c.loaders === 1 && c.css === 1 && c.tokens <= 1, 'redrawn form: one widget, no leftover loader: ' + JSON.stringify(c));
	assert(c.label === 'I am human', 'language kept after redraw');
	await fill(5, 'Synthetic International', true);
	await send(5);
	assert((await confirmed()) === 1, 'saved on retry');
	assert(stats.valid - b.valid === 1, 'one valid siteverify');
});

await test('AJAX (markup kept): outage shows message, widget gets a new token, retry saves', async (b) => {
	await page.goto(base + '?form=2&mode=ajax-keep');
	await fill(2, 'Synthetic Student', false);
	await page.waitForFunction(() => { const i = document.querySelector('input[name="infopeak-captcha-response"]'); return i && i.value; }, null, { timeout: 30000 });
	const first = await page.inputValue('input[name="infopeak-captcha-response"]');
	stats.failNext = 1;
	await send(2);
	assert((await confirmed()) === 0, 'not saved during outage');
	const msg = await errorText();
	assert(msg.length === 1 && /try again/i.test(msg[0]), 'actionable message: ' + msg);
	await page.waitForFunction((old) => { const i = document.querySelector('input[name="infopeak-captcha-response"]'); return i && i.value && i.value !== old; }, first, { timeout: 30000 });
	await send(2);
	assert((await confirmed()) === 1, 'saved on retry');
	assert(stats.calls - b.calls === 2 && stats.valid - b.valid === 1, 'two siteverify calls, one valid');
});

await test('Invalid token is refused, then a fresh one works', async (b) => {
	await page.goto(base + '?form=2&mode=ajax-keep');
	await fill(2, 'Synthetic Student', false);
	await page.waitForFunction(() => { const i = document.querySelector('input[name="infopeak-captcha-response"]'); return i && i.value; }, null, { timeout: 30000 });
	await page.evaluate(() => { document.querySelector('input[name="infopeak-captcha-response"]').value = 'not-a-real-token'; });
	await send(2);
	assert((await confirmed()) === 0 && (await errorText()).length === 1, 'refused with message');
	await page.waitForFunction(() => { const i = document.querySelector('input[name="infopeak-captcha-response"]'); return i && i.value && i.value !== 'not-a-real-token'; }, null, { timeout: 30000 });
	await send(2);
	assert((await confirmed()) === 1, 'fresh token accepted');
	assert(stats.invalid - b.invalid === 1 && stats.valid - b.valid === 1, 'one invalid, one valid');
});

await test('A confirmed token cannot be used again in a new request', async (b) => {
	await page.goto(base + '?form=2&mode=ajax-keep');
	await fill(2, 'Synthetic Student', false);
	await page.waitForFunction(() => { const i = document.querySelector('input[name="infopeak-captcha-response"]'); return i && i.value; }, null, { timeout: 30000 });
	const replay = await page.evaluate(async () => {
		const fd = new FormData(document.getElementById('gform_2'));
		const one = await (await fetch('/submit?form=2', { method: 'POST', body: fd })).json();
		const two = await (await fetch('/submit?form=2', { method: 'POST', body: fd })).json();
		return [one.is_valid, two.is_valid];
	});
	assert(replay[0] === true && replay[1] === false, 'first accepted, replay refused: ' + replay);
	assert(stats.valid - b.valid === 1 && stats.invalid - b.invalid === 1, 'service refused the replay');
});

await test('Postback: field error redraws the page with a working widget, retry with file saves', async (b) => {
	await page.goto(base + '?form=2&mode=postback');
	await page.locator('#input_2_1').focus();
	await Promise.all([page.waitForNavigation(), page.click('#gform_submit_button_2')]);
	assert(stats.calls - b.calls === 0, 'no siteverify on a field error');
	const c = await counts();
	assert(c.widgets === 1 && c.started === 1 && c.loaders === 1, 'fresh page, one widget: ' + JSON.stringify(c));
	const n = entries().length;
	await fill(2, 'Synthetic Student', true);
	await Promise.all([page.waitForNavigation({ timeout: 30000 }), page.click('#gform_submit_button_2')]);
	assert((await confirmed()) === 1, 'saved');
	const e = entries().slice(n);
	assert(e.length === 1 && e[0].file_bytes > 2000, 'file received');
	assert(stats.valid - b.valid === 1, 'one valid siteverify');
});

await test('Postback without a token is refused with a message', async (b) => {
	await page.goto(base + '?form=2&mode=postback');
	await page.fill('#input_2_1', 'Synthetic Student');
	// Send the form without the widget's token, the way a bot would.
	await Promise.all([page.waitForNavigation(), page.evaluate(() => {
		const f = document.getElementById('gform_2');
		f.querySelectorAll('input[name="infopeak-captcha-response"]').forEach((i) => i.remove());
		HTMLFormElement.prototype.submit.call(f);
	})]);
	assert((await confirmed()) === 0 && (await errorText()).length === 1, 'refused with message');
	assert(stats.calls - b.calls === 0, 'nothing to verify without a token');
});

await test('Form without InfoPeak: no widget, no script, saved as before', async (b) => {
	await page.goto(base + '?form=7&mode=ajax-replace');
	const c = await counts();
	assert(c.widgets === 0 && c.loaders === 0, 'nothing added: ' + JSON.stringify(c));
	await fill(7, 'Synthetic Visitor', false);
	await send(7);
	assert((await confirmed()) === 1 && stats.calls - b.calls === 0, 'saved without siteverify');
});

await browser.close();
php.kill();
proxy.close();
console.log(results.join('\n'));
console.log(`\n${results.filter((r) => r.startsWith('PASS')).length}/${results.filter((r) => /^(PASS|FAIL)/.test(r)).length} passed`);
process.exit(failed || results.some((r) => r.startsWith('PAGE ERROR')) ? 1 : 0);
