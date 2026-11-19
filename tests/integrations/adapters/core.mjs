// WordPress' own forms: login, registration, lost password (wp-login.php) and comments.
// All of them post back to the server; a refusal comes back as a page.

const loginError = async (page) => ((await page.locator('#login_error').count()) ? 'refused' : null);

export const login = {
	key: 'login',
	name: 'Login',
	container: '#loginform',
	variants: [{ label: 'postback', path: '/wp-login.php', ajax: false }],
	noTwoForms: 'wp-login.php has one form',
	noCache: 'wp-login.php is never cached',
	async fill(page, form) {
		// After a failed login, wp-login.php empties the password field 200 ms after load.
		if (await page.locator('#login_error').count()) await page.waitForTimeout(600);
		await form.locator('#user_login').fill(globalThis.IPK_CFG.IPK_USER);
		await form.locator('#user_pass').fill(globalThis.IPK_CFG.IPK_USER_PASS);
	},
	refillAfterRefusal: true,
	submit: (page, form) => form.locator('#wp-submit').click(),
	async outcome(page) {
		// Signed in: WordPress (or WooCommerce, for customers) sends the user on from wp-login.php.
		const signedIn = (await page.context().cookies()).some((c) => c.name.startsWith('wordpress_logged_in_'));
		if (signedIn && !/wp-login\.php/.test(page.url())) return 'ok';
		return loginError(page);
	},
};

export const register = {
	key: 'register',
	name: 'Registration',
	container: '#registerform',
	variants: [{ label: 'postback', path: '/wp-login.php?action=register', ajax: false }],
	noTwoForms: 'wp-login.php has one form',
	noCache: 'wp-login.php is never cached',
	mails: true,
	async fill(page, form, n) {
		await form.locator('#user_login').fill('ipkreg' + n);
		await form.locator('#user_email').fill(`ipkreg${n}@example.test`);
	},
	refillAfterRefusal: true,
	submit: (page, form) => form.locator('#wp-submit').click(),
	async outcome(page) {
		if (/checkemail=registered/.test(page.url())) return 'ok';
		return loginError(page);
	},
};

export const lostpw = {
	key: 'lostpw',
	name: 'Lost password',
	container: '#lostpasswordform',
	variants: [{ label: 'postback', path: '/wp-login.php?action=lostpassword', ajax: false }],
	noTwoForms: 'wp-login.php has one form',
	noCache: 'wp-login.php is never cached',
	mails: true,
	async fill(page, form) {
		await form.locator('#user_login').fill(globalThis.IPK_CFG.IPK_USER);
	},
	refillAfterRefusal: true,
	submit: (page, form) => form.locator('#wp-submit').click(),
	async outcome(page) {
		if (/checkemail=confirm/.test(page.url())) return 'ok';
		return loginError(page);
	},
};

export const comments = {
	key: 'comments',
	name: 'Comments',
	container: '#commentform',
	variants: [{ label: 'postback', path: '/ipk-comments/', ajax: false }],
	noTwoForms: 'one comment form per post',
	cached: { path: '/cached-comments/' },
	// A refusal is WordPress' error page; the visitor goes back to the post.
	retryByOpening: true,
	async fill(page, form, n) {
		await form.locator('#comment').fill('Synthetic comment ' + n);
		await form.locator('#author').fill('Synthetic Visitor');
		await form.locator('#email').fill(`visitor${n}@example.test`);
	},
	submit: (page, form) => form.locator('#submit').click(),
	async outcome(page) {
		const body = await page.locator('body').innerText();
		if (/Captcha verification failed|cannot check your message/i.test(body) && !(await page.locator('#commentform').count())) return 'refused';
		if (/#comment-\d+|unapproved=/.test(page.url())) return 'ok';
		return null;
	},
};
