=== InfoPeak Captcha ===
Contributors: infopeak
Tags: captcha, spam, privacy, gdpr, login protection
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

EU-hosted, cookie-free CAPTCHA with nothing to solve. Rejected attempts are free. Protects login, comments, Contact Form 7, WPForms and WooCommerce.

== Description ==

InfoPeak Captcha stops bots without bothering your visitors and without sending their data to the US.

Instead of tracking visitors or making them click traffic lights, it uses **proof-of-work**: the visitor's browser solves a small computational challenge in the background, the moment they start filling in a form. Bots pay a CPU cost that makes mass abuse expensive; humans wait a fraction of a second and never see a puzzle.

**Why site owners choose it**

* **Hosted in the EU.** The service is hosted entirely in the EU. No personal data leaves the EU, no US transfer, no CLOUD Act exposure.
* **No cookies, no tracking.** Nothing to declare in your cookie banner for the captcha.
* **Nothing to solve.** No image grids, no distorted text, nothing to click. Fully accessible by design.
* **Rejected attempts are free.** Only verifications that get through count toward your monthly quota. Bots that are stopped and fake or reused tokens never count.
* **Set up in two minutes.** Paste a sitekey and a secret, and the forms of the plugins you use are protected automatically.
* **Free plan.** 10,000 verifications per month at no cost.

**What this plugin protects**

* Login form
* Registration form
* Lost-password form
* Comment form (with an option to skip logged-in users)
* Contact Form 7 forms
* WPForms forms
* Jetpack Forms (the contact form built into Jetpack)
* WooCommerce checkout (classic checkout)

Each can be toggled individually on the settings page. The widget is added to your forms automatically, so there is nothing to edit in Contact Form 7, WPForms, Jetpack Forms or WooCommerce.

**Setup takes two minutes**

1. Install and activate the plugin, then open Settings → InfoPeak Captcha.
2. Click "Create a free account" and create a key for your website. Your sitekey and secret are shown right away.
3. Paste them into the settings page and save. The widget appears on your forms and verification happens automatically.

You can try the plugin first with the public test credentials `ipk_test_sitekey` / `ipk_test_secret` - they work on any domain and never count against a quota.

**For agencies**

Building websites for clients? With the [InfoPeak partner programme](https://partner.infopeak.io/en/) you create a Captcha account for each client, see every client's usage in one console, get the usage alerts for your clients' sites and receive one invoice for all of them, at partner prices.

Learn more about InfoPeak Captcha at [infopeak.io/products/captcha](https://infopeak.io/products/captcha).

== External services ==

This plugin relies on the InfoPeak Captcha service to do its job. The service is operated by InfoPeak and hosted entirely in the EU.

It is used for two things:

1. **Loading the captcha widget.** The script `https://captcha.infopeak.io/infopeak-captcha.js` is loaded in the visitor's browser on the pages where a protected form appears. The browser then requests a challenge and submits the solved proof of work. This sends the site's public sitekey and the computed solution. No cookies are set and no visitor is tracked.
2. **Verifying submissions.** When a protected form is submitted, your server sends the captcha token together with your sitekey and secret to `https://captcha.infopeak.io/api/v1/pow/siteverify` and receives back whether the token is valid. Nothing about the visitor is included.

No personal data about your visitors is stored by the service. See the [privacy policy](https://infopeak.io/privacy) and the [terms of service](https://infopeak.io/terms).

The settings page also links to infopeak.io to create an account, sign in and read about the partner programme. These links include the admin's language and a `utm_source=wordpress_plugin` tag, so we can see that the visit came from the plugin. Nothing is sent unless the admin clicks a link.

== Installation ==

1. Install and activate the plugin.
2. Go to Settings → InfoPeak Captcha.
3. Click "Create a free account" (or "sign in" if you already have one) and create a key for your website.
4. Enter your sitekey and secret and choose which forms to protect. Done.

== Frequently Asked Questions ==

= Do my visitors have to solve puzzles? =

No. The captcha solves itself in the background while the visitor fills in the form. There is nothing to click and nothing to solve.

= What do my visitors actually see? =

A small box above the submit button showing the verification status, and a "Protected by InfoPeak" line underneath. It ticks itself off while the visitor is filling in the form. They never have to touch it.

= Is it GDPR-compliant? =

The service is hosted entirely in the EU, sets no cookies and does not track visitors. There is no data transfer to the US.

= How is it different from reCAPTCHA or Cloudflare Turnstile? =

reCAPTCHA is run by Google and Turnstile by Cloudflare, both US companies. InfoPeak Captcha is run by InfoPeak, a European company, and the service is hosted entirely in the EU. It sets no cookies, shows visitors nothing to solve, and only verifications that get through count toward your quota.

If you switch from another captcha plugin, deactivate it first, so your visitors are not checked twice.

= What counts as a verification? =

A verification is counted when your server confirms a valid token, which happens when a protected form is sent. Bots that are stopped, fake or reused tokens and visitors who never send the form do not count.

= Can I use one account for several websites? =

Yes. Create a key for each website in your InfoPeak Captcha account. Each key only works on the domains you allow for it, and the monthly verifications are counted for the whole account, across all your websites.

= I build websites for clients. How do I manage them? =

Join the [InfoPeak partner programme](https://partner.infopeak.io/en/). You create a Captcha account for each client and get its sitekey and secret in the partner console. Your clients do not need an InfoPeak login. You see every client's usage in one place, the usage alerts for their sites are sent to you, and you receive one invoice for all your clients, at partner prices.

= Does it work with Contact Form 7, WPForms, Jetpack Forms and WooCommerce? =

Yes. Turn them on under Settings → InfoPeak Captcha and the widget appears in every form, in front of the submit button. In Contact Form 7 you can also place it yourself with the `[infopeak_captcha]` tag. After each submission the widget fetches a fresh token by itself, so a visitor who corrects a mistake and sends again is not stopped.

For WooCommerce the plugin protects the classic checkout, the one built with the `[woocommerce_checkout]` shortcode. The newer block-based checkout is not covered yet.

= Does it work with caching plugins? =

Yes. The widget is plain JavaScript loaded from captcha.infopeak.io and works on fully cached pages.

= What happens if the captcha service is unreachable? =

Verification fails closed: the protected form submission is rejected with an error message asking the visitor to try again.

= Does it support my language? =

The widget ships in 16 languages (English, German, French, Spanish, Italian, Dutch, Portuguese, Polish, Danish, Swedish, Norwegian, Finnish, Estonian, Czech, Romanian, Ukrainian) and picks the right one automatically from your site's language.

= Is there a free plan? =

Yes - 10,000 verifications per month, free forever. Paid plans start at 100,000 per month.

== Screenshots ==

1. The settings page: sitekey, secret, the forms to protect, and a link to create a free account.
2. A Contact Form 7 form. The widget verifies itself while the visitor writes, with nothing to click.
3. The classic WooCommerce checkout, protected in front of the order button.
4. Your InfoPeak Captcha account: the verifications that count this month, next to all challenges solved.
5. Each website gets its own key. Copy the sitekey and secret into the plugin.
6. The widget on the login form - verified automatically, no interaction needed.

== Changelog ==

= 1.2.3 =
* The settings page now sends you to the InfoPeak Captcha sign-up, in your WordPress language, and lands you in the Captcha console where your sitekey and secret are. Before, it opened the general InfoPeak sign-up. A "sign in" link takes existing users straight to their keys.
* New on the settings page: a short note for agencies about the InfoPeak partner programme.
* The plugin's homepage link now points to the InfoPeak Captcha product page.

= 1.2.2 =
* The script that renews the token after a submit now lists jQuery as a dependency of the widget script, so it also works on pages where jQuery was not already loaded.
* When your InfoPeak Captcha quota and its reserve are used up, visitors now see a clear message ("This form cannot check your message right now...") instead of "Captcha verification failed. Please try again.", which would not help them.

= 1.2.1 =
* Jetpack Forms: when the widget has been pasted into the form by hand, the plugin now still asks it for a fresh token after each submit, so a visitor can try again without reloading the page.

= 1.2.0 =
* New: protects Jetpack Forms. The widget is added to every Jetpack form and the token is verified on the server before the message is sent. A form that already contains the widget is left as it is.

= 1.1.0 =
* Contact Form 7: the widget is added to every form, and submissions without a valid token are marked as spam. Place it yourself with the [infopeak_captcha] tag if you prefer.
* WPForms: the widget is added to every form, including AJAX forms.
* WooCommerce: protects the classic checkout.
* After a form is sent without leaving the page, the widget fetches a fresh token automatically.

= 1.0.0 =
* Initial release: login, registration, lost-password and comment protection.

== Upgrade Notice ==

= 1.2.3 =
The settings page now takes you straight to the InfoPeak Captcha sign-up and your keys.

= 1.2.2 =
Makes sure jQuery is loaded together with the widget, so the token is renewed after a submit on every page.

= 1.2.1 =
Improves Jetpack Forms when the widget was added to the form by hand.

= 1.2.0 =
Adds protection for Jetpack Forms. Turned on automatically when Jetpack is installed.

= 1.1.0 =
Adds protection for Contact Form 7, WPForms and WooCommerce checkout. Turned on automatically for the plugins you have installed.

= 1.0.0 =
Initial release.
