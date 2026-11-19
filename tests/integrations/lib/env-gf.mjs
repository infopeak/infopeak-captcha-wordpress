// Gravity Forms has no licence for automated runs, so its browser checks use a stand-in:
// `php -S` with gravity-forms/site/router.php (Gravity Forms-like markup and submission
// JS, backed by the real plugin hooks) and a siteverify proxy that forwards to
// captcha.infopeak.io, counts answers and can simulate an outage. Tokens are never logged.
import http from 'node:http';
import { spawn } from 'node:child_process';
import { mkdtempSync, readFileSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));

export async function gfEnv({ port = 8790, proxyPort = 8791 } = {}) {
	const work = mkdtempSync(join(tmpdir(), 'ipkcap-gf-'));
	const entryLog = join(work, 'entries.jsonl');
	const stats = { calls: 0, valid: 0, invalid: 0, failed: 0, failNext: 0 };
	const proxy = http.createServer((req, res) => {
		let body = '';
		req.on('data', (c) => (body += c));
		req.on('end', async () => {
			stats.calls++;
			const keys = Object.keys(JSON.parse(body));
			if (keys.join() !== 'sitekey,secret,token') {
				res.writeHead(500).end('{"error":"unexpected body"}');
				throw new Error('unexpected siteverify body keys: ' + keys);
			}
			if (stats.failNext > 0) {
				stats.failNext--;
				stats.failed++;
				res.writeHead(503).end('{"error":"simulated outage"}');
				return;
			}
			const r = await fetch('https://captcha.infopeak.io/api/v1/pow/siteverify', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body });
			const text = await r.text();
			JSON.parse(text).valid === true ? stats.valid++ : stats.invalid++;
			res.writeHead(r.status, { 'Content-Type': 'application/json' }).end(text);
		});
	});
	await new Promise((r) => proxy.listen(proxyPort, '127.0.0.1', r));
	const php = spawn('php', ['-S', `127.0.0.1:${port}`, join(here, '..', 'gravity-forms', 'site', 'router.php')], {
		env: { ...process.env, SITEVERIFY_PROXY: `http://127.0.0.1:${proxyPort}/`, ENTRY_LOG: entryLog },
		stdio: 'ignore',
	});
	const base = `http://127.0.0.1:${port}`;
	for (let i = 0; i < 50; i++) {
		try {
			await fetch(base + '/?form=7');
			break;
		} catch {
			await new Promise((r) => setTimeout(r, 100));
		}
	}
	const entries = () => (existsSync(entryLog) ? readFileSync(entryLog, 'utf8').trim().split('\n').filter(Boolean).map(JSON.parse) : []);
	return {
		name: 'gravity-forms stand-in',
		base,
		url: (path) => base + path,
		raw: stats,
		entries,
		// An entry saved stands for the notification Gravity Forms would send.
		stats: async () => ({ calls: stats.calls, valid: stats.valid, invalid: stats.invalid, failed: stats.failed, mails: entries() }),
		outage: async (n) => {
			stats.failNext = n;
		},
		reset: async () => {},
		cache: null,
		async close() {
			php.kill();
			proxy.close();
		},
	};
}
