"""
Browser smoke test for Foxfire Studio, run against the throwaway WordPress
from tests/wp/setup.sh with `php -S` serving it.

Checks that every Foxfire Studio screen, the teleprompter and a public post
load without PHP errors or script errors, that the private REST routes
refuse visitors who are not signed in, and that a podcast short address
(1.2.0) redirects without being cached.

Usage: python3 tests/browser/smoke.py [http://localhost:8080]
Needs: pip install playwright, and Chrome (or `playwright install chromium`).
"""
import json
import os
import re
import sys
import urllib.error
import urllib.request

from playwright.sync_api import sync_playwright
from signin import sign_in

BASE = (sys.argv[1] if len(sys.argv) > 1 else os.environ.get('WP_URL', 'http://localhost:8080')).rstrip('/')
CHROME = os.environ.get('CHROME_PATH', '/usr/bin/google-chrome')
PHP_ERR = re.compile(r'(Fatal error|Parse error|Warning|Notice|Deprecated)</b>:|<b>(Fatal error|Warning|Notice|Deprecated)</b>|PHP (Fatal|Warning|Notice|Deprecated)')

SCREENS = [
	'admin.php?page=rrt-dashboard',
	'admin.php?page=rrt-readings',
	'admin.php?page=rrt-backlog',
	'admin.php?page=rrt-pronounce',
	'admin.php?page=rrt-sources',
	'admin.php?page=rrt-progress',
	'admin.php?page=rrt-insights',
	'admin.php?page=rrt-recordings',
	'admin.php?page=rrt-reports',
	'admin.php?page=rrt-podcast',
	'admin.php?page=rrt-shares',
	'edit.php?post_type=rrt_collection',
	'post-new.php?post_type=rrt_collection',
	'edit.php?post_type=rrt_spot',
	'post-new.php?post_type=rrt_spot',
	'options-general.php?page=rrt-settings',
	'edit.php',
	'post.php?post=%POST%&action=edit',
]

PRIVATE = [
	('GET', '/rrt/v1/takes'),
	('POST', '/rrt/v1/takes'),
	('GET', '/rrt/v1/learned'),
	('POST', '/rrt/v1/learned'),
	('POST', '/rrt/v1/import'),
	('POST', '/rrt/v1/recording'),
	('POST', '/rrt/v1/cues'),
	('POST', '/rrt/v1/learned-post'),
	('POST', '/rrt/v1/notes'),
	('GET', '/rrt/v1/pronounce'),
	('POST', '/rrt/v1/pronounce'),
	('GET', '/rrt/v1/pronounce/backup'),
	('GET', '/rrt/v1/share-people'),
	('POST', '/rrt/v1/shares'),
	('GET', '/rrt/v1/sent'),
	('POST', '/rrt/v1/sent'),
	('GET', '/rrt/v1/collections/readings'),
	('POST', '/rrt/v1/collections/plan'),
	('POST', '/rrt/v1/collections/level'),
	('POST', '/rrt/v1/collections/1/match'),
	('POST', '/rrt/v1/collections/1/build'),
	('GET', '/rrt/v1/audiobook/col/1'),
	('POST', '/rrt/v1/audiobook/col/1'),
	('POST', '/rrt/v1/audiobook/1/copy'),
	('DELETE', '/rrt/v1/audiobook/1/copy'),
]

passed = 0
failed = []


def check(ok, name, detail=''):
	global passed
	if ok:
		passed += 1
		print('ok   ' + name)
	else:
		failed.append(name)
		print('FAIL ' + name + (': ' + detail if detail else ''))


def rest(method, route):
	req = urllib.request.Request(BASE + '/?rest_route=' + route, method=method, data=b'{}' if method == 'POST' else None, headers={'Content-Type': 'application/json'})
	try:
		with urllib.request.urlopen(req) as r:
			return r.status
	except urllib.error.HTTPError as e:
		return e.code


for method, route in PRIVATE:
	code = rest(method, route)
	check(code in (401, 403), 'signed out, %s %s is refused' % (method, route), str(code))

with urllib.request.urlopen(BASE + '/?rest_route=/wp/v2/posts&search=The%20Test%20Reading&per_page=1') as r:
	post = json.loads(r.read())[0]
POST_ID = post['id']
POST_URL = post['link']


class NoRedirect(urllib.request.HTTPRedirectHandler):
	def redirect_request(self, *args, **kwargs):
		return None


def short(path):
	"""Status and headers of a podcast short address (1.2.0), without following the redirect."""
	try:
		with urllib.request.build_opener(NoRedirect).open(BASE + path) as r:
			return r.status, r.headers
	except urllib.error.HTTPError as e:
		return e.code, e.headers


# Without the file name: php -S answers any address ending in .mp3 itself, with a 404,
# where a real server's rewrite rules pass it to WordPress.
DL_POST = int(os.environ.get('DL_POST', '0') or 0)
if DL_POST:
	code, hdr = short('/podcast-file/%d' % DL_POST)
	check(code == 302 and (hdr.get('Location') or '').endswith('/short-test.mp3'), 'a podcast short address redirects to the reading', '%s %s' % (code, hdr.get('Location')))
	check('no-store' in (hdr.get('Cache-Control') or '') or 'no-cache' in (hdr.get('Cache-Control') or ''), 'and is never cached', hdr.get('Cache-Control') or '')
code, hdr = short('/podcast-file/999999')
check(code == 404, 'a short address for no post is not found', str(code))
# Member feeds (1.21.0): with member feeds off, a member address answers 404, and a file kept for members is refused to visitors.
code, hdr = short('/podcast-member/' + 'a' * 32 + '/')
check(code == 404, 'a member feed address answers 404 while member feeds are off', str(code))
check('no-store' in (hdr.get('Cache-Control') or ''), 'and is never cached', hdr.get('Cache-Control') or '')
code, hdr = short('/podcast-member/file/999999')
check(code in (401, 404), 'a member file address gives a visitor nothing', str(code))

with sync_playwright() as p:
	browser = p.chromium.launch(executable_path=CHROME if os.path.exists(CHROME) else None, args=['--autoplay-policy=no-user-gesture-required'])
	ctx = browser.new_context()
	page = ctx.new_page()
	errors = []
	page.on('pageerror', lambda e: errors.append(str(e)))

	def visit(url, name):
		del errors[:]
		resp = page.goto(url, wait_until='load')
		page.wait_for_timeout(400)
		html = page.content()
		m = PHP_ERR.search(html)
		check(resp is not None and resp.status == 200, name + ' loads', str(resp.status if resp else 'no response'))
		check(m is None, name + ' has no PHP errors', html[max(0, m.start() - 80):m.end() + 200] if m else '')
		check(not errors, name + ' has no script errors', '; '.join(errors))
		return html

	visit(POST_URL, 'public post, signed out')

	sign_in(page, BASE)

	for s in SCREENS:
		visit(BASE + '/wp-admin/' + s.replace('%POST%', str(POST_ID)), s)

	visit(BASE + '/?teleprompter=1', 'teleprompter, no post')
	html = visit(BASE + '/?teleprompter=1&tp_post=%d' % POST_ID, 'teleprompter with a post')
	try:
		page.wait_for_function("document.querySelectorAll('.tp-w').length > 0", timeout=10000)
	except Exception:
		pass
	words = page.evaluate("document.querySelectorAll('.tp-w').length")
	check(words >= 10, 'teleprompter shows the post words', str(words) + ' at ' + page.url)
	check(page.evaluate("typeof window.RRT === 'object'"), 'teleprompter boots its settings')

	# Readings to make (1.4.0): Hide takes a post off the list, Bring back returns it.
	page.goto(BASE + '/wp-admin/admin.php?page=rrt-backlog', wait_until='load')
	rows = page.locator('#rrt-t-backlog tbody tr')
	first = rows.first.locator('td').first.inner_text() if rows.count() and rows.first.locator('form').count() else ''
	check(first != '' and rows.first.locator('a', has_text='Start').count() == 1, 'Readings to make lists a post with Start', first)
	if first:
		n = page.locator('#rrt-t-backlog tbody form').count()
		with page.expect_navigation():
			rows.first.locator('button', has_text='Hide').click()
		check(page.locator('#rrt-t-backlog tbody form').count() == n - 1 and page.locator('a', has_text='Show hidden (1)').count() == 1, 'Hide takes it off the list')
		with page.expect_navigation():
			page.locator('a', has_text='Show hidden (1)').click()
		with page.expect_navigation():
			page.locator('#rrt-t-backlog tbody tr').first.locator('button', has_text='Bring back').click()
		check(page.locator('#rrt-t-backlog tbody form').count() == n and page.locator('a', has_text='Show hidden').count() == 0, 'Bring back returns it')

	browser.close()

print('\n%d passed, %d failed' % (passed, len(failed)))
sys.exit(1 if failed else 0)
