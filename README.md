# InfoPeak Captcha for WordPress

Privacy-first, EU-hosted captcha for WordPress. No tracking, no cookies, no image puzzles.

[InfoPeak Captcha](https://captcha.infopeak.io) uses proof-of-work instead of surveillance: the visitor's browser solves a small computational challenge in the background - invisibly, with nothing to click. Bots pay a CPU cost that makes mass abuse expensive; humans wait a fraction of a second.

## What it protects

- Login form
- Registration form
- Lost-password form
- Comment form (optionally skipping logged-in users)

Each form can be toggled individually under **Settings → InfoPeak Captcha**.

## Why

- **No personal data leaves the EU.** Hosted entirely in the EU - no US transfer, no CLOUD Act exposure.
- **No cookies, no tracking.** Nothing to add to your cookie banner.
- **No puzzles.** Accessible by design - no image grids, distorted text or checkboxes.
- **Free plan.** 10,000 verifications per month at no cost.

## Installation

1. Download the latest release and install it via **Plugins → Add New → Upload Plugin**, or copy the `infopeak-captcha/` folder into `wp-content/plugins/`.
2. Activate the plugin.
3. Get a free sitekey and secret at [captcha.infopeak.io](https://captcha.infopeak.io).
4. Paste them into **Settings → InfoPeak Captcha** and choose which forms to protect.

To try it before creating a key, use the public test credentials `ipk_test_sitekey` / `ipk_test_secret` - they work on any domain and never count against a quota. Never use them in production.

## Documentation

Full developer documentation, API reference and framework guides: [captcha.infopeak.io/developers](https://captcha.infopeak.io)

## License

GPL-2.0-or-later. See [readme.txt](infopeak-captcha/readme.txt).
