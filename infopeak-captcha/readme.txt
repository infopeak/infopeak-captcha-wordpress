=== InfoPeak Captcha ===
Contributors: infopeak
Tags: captcha, spam, privacy, gdpr, login protection
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Privacy-first, EU-hosted captcha. No tracking, no cookies, no image puzzles. Protects login, registration, password reset and comments.

== Description ==

InfoPeak Captcha stops bots without bothering your visitors and without sending their data to the US.

Instead of tracking visitors or making them click traffic lights, it uses **proof-of-work**: the visitor's browser solves a small computational challenge in the background, invisibly, the moment they start filling in a form. Bots pay a CPU cost that makes mass abuse expensive; humans wait a fraction of a second and never see a puzzle.

**Why site owners choose it**

* **No personal data leaves the EU.** The service is hosted entirely in the EU. No US transfer, no CLOUD Act exposure.
* **No cookies, no tracking.** Nothing to declare in your cookie banner for the captcha.
* **No puzzles.** No image grids, no distorted text, no checkbox. Fully accessible by design.
* **Free plan.** 10,000 verifications per month at no cost.

**What this plugin protects**

* Login form
* Registration form
* Lost-password form
* Comment form (with an option to skip logged-in users)

Each can be toggled individually on the settings page.

**Setup takes two minutes**

1. Create a free sitekey and secret at [infopeak.io/captcha](https://infopeak.io/captcha)
2. Paste them into Settings → InfoPeak Captcha
3. Done - the widget appears on your forms and verification happens automatically

You can try the plugin first with the public test credentials `ipk_test_sitekey` / `ipk_test_secret` - they work on any domain and never count against a quota.

**External service**

This plugin connects to the InfoPeak Captcha service at `captcha.infopeak.io` (EU-hosted) for two things: loading the captcha widget script in the visitor's browser, and verifying captcha tokens server-side when a protected form is submitted. No personal data about your visitors is stored by the service. See the [privacy policy](https://infopeak.io/privacy) and [terms](https://infopeak.io/terms).

== Installation ==

1. Install and activate the plugin.
2. Go to Settings → InfoPeak Captcha.
3. Enter your sitekey and secret from your [InfoPeak dashboard](https://infopeak.io/captcha) (free plan available).
4. Choose which forms to protect. Done.

== Frequently Asked Questions ==

= Do my visitors have to solve puzzles? =

No. The captcha solves itself in the background while the visitor fills in the form. There is nothing to click and nothing to solve.

= Is it GDPR-compliant? =

The service is hosted entirely in the EU, sets no cookies and does not track visitors. There is no data transfer to the US.

= Does it work with caching plugins? =

Yes. The widget is plain JavaScript loaded from captcha.infopeak.io and works on fully cached pages.

= What happens if the captcha service is unreachable? =

Verification fails closed: the protected form submission is rejected with an error message asking the visitor to try again.

= Does it support my language? =

The widget ships in 16 languages (English, German, French, Spanish, Italian, Dutch, Portuguese, Polish, Danish, Swedish, Norwegian, Finnish, Estonian, Czech, Romanian, Ukrainian) and picks the right one automatically from your site's language.

= Is there a free plan? =

Yes - 10,000 verifications per month, free forever. Paid plans start at 500,000 per month.

== Screenshots ==

1. The settings page - sitekey, secret and per-form toggles.
2. The widget on the login form - verified automatically, no interaction needed.

== Changelog ==

= 1.0.0 =
* Initial release: login, registration, lost-password and comment protection.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
