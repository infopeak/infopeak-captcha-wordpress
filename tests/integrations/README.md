# Integration tests

Every form integration runs the same checks, with the real widget and the public test
credentials (`ipk_test_sitekey` / `ipk_test_secret`, never counted against a quota).
Synthetic data only.

| Check | What it proves |
|---|---|
| valid token accepted, 1280 px | The widget starts once, fits the page, and a normal submission goes through (one valid siteverify, mail sent). Run for each submission kind the form plugin has (AJAX, postback, ...). |
| missing token refused | A submission without the widget's token is refused with a message, nothing is sent and no siteverify call is made. |
| false token refused, then sent again | A made-up token is refused. For AJAX forms a new token arrives without reloading the page and the next attempt goes through; for postback forms the redrawn page works. |
| captcha service unreachable | Fail closed: the submission is refused while siteverify cannot be reached, and goes through afterwards. |
| token used before is refused | A token that was accepted once is refused the second time. |
| two forms on one page | Each form has its own widget and token, one loader script, both go through. |
| page served from the page cache | The page comes from a page cache (Cache Enabler), holds no token, and a submission goes through. |
| 375 px | The widget fits a phone screen, no horizontal scroll, a submission goes through. |
| mixed page | CF7, WPForms, Jetpack, Fluent Forms and Formidable on one post with the comment form: every widget starts and each form goes through without a reload. |

## Layout

```
lib/harness.mjs          the checks, runner and token helpers
lib/env-wp.mjs           the throwaway WordPress (stats, simulated outage, page cache)
lib/env-gf.mjs           the Gravity Forms stand-in (php -S + siteverify proxy)
adapters/*.mjs           one per integration: where the form is, how to fill and send it, how to read the outcome
run.mjs                  runs the checks for all or some integrations
wp/site.sh               sets up, updates and removes the throwaway WordPress in Docker on a test host
wp/setup-content.php     the pages and forms the adapters use
wp/mu-plugin/            test helper: counts siteverify answers and mail, simulates an outage
gravity-forms/           Gravity Forms server-side tests (unit.php), the stand-in site and its own browser scenarios
```

## Running

```bash
bash tests/integrations/wp/site.sh up
```

```bash
ssh -N -L 8099:127.0.0.1:8099 root@<test host>
```

```bash
bash tests/integrations/wp/site.sh deploy
```

```bash
PLAYWRIGHT_CORE=<path to playwright-core> CHROMIUM=<path to chrome> node tests/integrations/run.mjs
```

`deploy <commit>` puts that commit's plugin on the site instead of the working tree, which is
how a change is compared with the code before it. `run.mjs cf7 wpforms` runs only those
integrations (keys: `login register lostpw comments cf7 wpforms jetpack woo fluent formidable mixed gf`), and
`ONLY_CHECK="two forms"` only the checks whose name matches. Afterwards:

```bash
bash tests/integrations/wp/site.sh wp plugin check infopeak-captcha
```

```bash
php tests/integrations/gravity-forms/unit.php
```

```bash
bash tests/integrations/wp/site.sh down
```

`down` removes the containers, volumes, network and images from the test host.

## Adding an integration

1. Install and activate the form plugin in `wp/site.sh` (`plugin install`), and add its pages to
   `wp/setup-content.php`: one page per submission kind, a page with two forms and a
   `/cached-...` page (only paths starting with `/cached-` are cached).
2. Write `adapters/<plugin>.mjs`. An adapter is a plain object:
   - `container`: CSS selector of an element around one form that survives the plugin's own
     redraws (the n-th match is form n).
   - `variants`: `[{ label, path, ajax }]`, one per submission kind. `ajax: false` means the page
     loads again after submit.
   - `fill(page, container, n)`: fill the required fields; `n` is unique per call.
   - `submit(page, container)`: click the plugin's own submit button.
   - `outcome(page, container)`: `'ok'`, `'refused'`, or `null` while there is no answer yet.
   - Optional: `beforeSubmit` (clear the previous attempt's message), `refusedByCaptcha` (when the
     plugin shows its own text instead of InfoPeak's), `twoForms: { path }`, `cached: { path }`,
     `mails: true` when an accepted submission sends mail, `refillAfterRefusal`,
     `retryByOpening` (the refusal is an error page), `submitWithoutToken` (when the plugin's
     JavaScript would wait for a token), `noTwoForms` / `noCache` (reason shown as N/A).
3. Add it to `run.mjs`, run it against the current release first (`site.sh deploy <tag>`) and then
   against the change.

In the plugin, an integration file under `infopeak-captcha/includes/integrations/` adds its hooks
and, for AJAX forms, one line of JavaScript with `ipkcap_add_form_script()`, usually
`ipkcapForms.resetOn('<event>', '<form selector>' or null, { jquery: true })`. Register the file in
`ipkcap_integrations()` with the form plugin's main file and a check that the plugin has loaded.
