"""Signs a Playwright page in to the test WordPress. Shared by the browser tests.

Waits for the sign-in navigation itself: the login page has already loaded, so
waiting for 'load' alone can return at once and let the next page cancel the
sign-in. On a slow runner the first try now and then lands back on the login
page, so it tries twice and says what WordPress reported before giving up.
"""
import sys


def sign_in(page, base, user='admin', password='admin', tries=2):
	why = ''
	for attempt in range(tries):
		page.goto(base + '/wp-login.php', wait_until='load')
		# wp-login.php focuses the name field after 200 ms; let that pass before typing.
		page.wait_for_timeout(300)
		page.fill('#user_login', user)
		page.fill('#user_pass', password)
		with page.expect_navigation(wait_until='load', timeout=30000):
			page.click('#wp-submit')
		if '/wp-login.php' not in page.url:
			return
		why = page.evaluate("(document.getElementById('login_error') || {}).innerText || ''").strip()
		print('Sign-in try %d landed on %s %s' % (attempt + 1, page.url, ('(' + why + ')') if why else ''))
	sys.exit('Could not sign in to the test site.' + ((' WordPress said: ' + why) if why else ''))
