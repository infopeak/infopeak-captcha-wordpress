// Runs the shared checks (lib/harness.mjs) for every integration.
//
//   node tests/integrations/run.mjs                    all integrations
//   node tests/integrations/run.mjs cf7 wpforms        only these (keys below)
//   ONLY_CHECK="two forms" node tests/integrations/run.mjs     only checks whose name matches
//
// Needs PLAYWRIGHT_CORE and CHROMIUM (see lib/harness.mjs). The WordPress integrations
// run against the throwaway site from wp/site.sh (tunnel to 127.0.0.1:8099); Gravity
// Forms runs against its stand-in (gravity-forms/site), started here.

import { launchBrowser, Runner, runChecks, runMixed } from './lib/harness.mjs';
import { wpEnv } from './lib/env-wp.mjs';
import { gfEnv } from './lib/env-gf.mjs';
import { login, register, lostpw, comments } from './adapters/core.mjs';
import cf7 from './adapters/cf7.mjs';
import wpforms from './adapters/wpforms.mjs';
import jetpack from './adapters/jetpack.mjs';
import woo from './adapters/woocommerce.mjs';
import gf from './adapters/gravity-forms.mjs';

const wpAdapters = [login, register, lostpw, comments, cf7, wpforms, jetpack, woo];
const keys = process.argv.slice(2);
const want = (key) => !keys.length || keys.includes(key);

const runner = new Runner();
const browser = await launchBrowser();
try {
	if (wpAdapters.some((a) => want(a.key)) || want('mixed')) {
		const env = wpEnv();
		globalThis.IPK_CFG = env.cfg;
		await env.reset();
		for (const a of wpAdapters) if (want(a.key)) await runChecks(a, env, browser, runner);
		// CF7, WPForms and Jetpack on one post that also has the comment form.
		if (want('mixed')) await runMixed([cf7, wpforms, jetpack], '/mixed/', env, browser, runner, 1);
	}
	if (want(gf.key)) {
		const env = await gfEnv();
		try {
			await runChecks(gf, env, browser, runner);
		} finally {
			await env.close();
		}
	}
} finally {
	await browser.close();
}
console.log('\n' + runner.summary());
process.exit(runner.failed ? 1 : 0);
