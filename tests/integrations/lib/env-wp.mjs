// The throwaway WordPress from tests/integrations/wp/site.sh, reached through an SSH tunnel.
import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));

export function readConfig() {
	const cfg = {};
	for (const line of readFileSync(join(here, '..', 'wp', 'config.sh'), 'utf8').split('\n')) {
		const m = line.match(/^(\w+)="(?:\$\{\w+:-)?([^}"]*)\}?"/);
		if (m) cfg[m[1]] = process.env[m[1]] || m[2];
	}
	return cfg;
}

export function wpEnv() {
	const cfg = readConfig();
	const base = `http://127.0.0.1:${cfg.IPK_PORT}`;
	const helper = async (q) => {
		const r = await fetch(`${base}/?ipk_test=${q}&ipk_key=${cfg.IPK_TEST_KEY}`);
		if (!r.ok) throw new Error('test helper: HTTP ' + r.status);
		return r.json();
	};
	return {
		name: 'wordpress',
		cfg,
		base,
		url: (path) => base + path,
		stats: () => helper('stats'),
		outage: (n) => helper('outage&n=' + n),
		reset: () => helper('reset'),
		cache: {
			// Two requests: the first stores the page, the browser's must come from the cache.
			// Cache Enabler only stores requests that accept text/html, as a browser's do.
			async prime(path) {
				for (let i = 0; i < 2; i++) await fetch(base + path, { headers: { Accept: 'text/html' } });
			},
			isCached: (html, headers) => headers['x-cache-handler'] === 'cache-enabler-engine' && /<!-- Cache Enabler by KeyCDN/.test(html),
		},
		async close() {},
	};
}
