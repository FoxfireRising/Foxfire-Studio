#!/usr/bin/env python3
"""
Check private folders (1.25.0), as an owner meets it on Podcast feed. The
test site's PHP server reads no .htaccess, like nginx, so every folder
answers: the check must say so, name the folders, delete its test files, and
show the nginx lines on the next visit.

Usage: tests/browser/privatecheck.py http://localhost:8080 /path/to/uploads
"""
import glob, os, sys
from playwright.sync_api import sync_playwright
from signin import sign_in

BASE = sys.argv[1].rstrip('/') if len(sys.argv) > 1 else 'http://localhost:8080'
UPLOADS = sys.argv[2] if len(sys.argv) > 2 else '/tmp/foxfire-wp/wordpress/wp-content/uploads'
CHROME = os.environ.get('CHROME_PATH', '/usr/bin/google-chrome')
SCREEN = BASE + '/wp-admin/admin.php?page=rrt-podcast'

passed = 0
failed = []


def check(ok, name, detail=''):
	global passed
	if ok:
		passed += 1
		print('ok   ' + name)
	else:
		failed.append(name)
		print('FAIL ' + name + (': ' + str(detail) if detail else ''))


with sync_playwright() as p:
	browser = p.chromium.launch(executable_path=CHROME if os.path.exists(CHROME) else None)
	ctx = browser.new_context(viewport={'width': 1280, 'height': 1000})
	errors = []
	page = ctx.new_page()
	page.on('pageerror', lambda e: errors.append(str(e)))
	sign_in(page, BASE)

	page.goto(SCREEN + '#rrt-private')
	check(page.is_visible('#rrt-private') and page.is_visible('[data-rrt-pc-run]'), 'Podcast feed has the Private folders section with its button')
	page.click('[data-rrt-pc-run]')
	page.wait_for_selector('#rrt-private-check[data-rrt-pc-done]', timeout=30000)
	done = page.get_attribute('#rrt-private-check', 'data-rrt-pc-done')
	txt = page.inner_text('[data-rrt-pc-status]')
	check('open' == done, 'on a server that reads no .htaccess the check finds the folders open', done + ' ' + txt)
	check('rrt-members' in txt and 'rrt-kept' in txt and 'nginx' in txt, 'it names them and says what to do', txt)
	check(not glob.glob(os.path.join(UPLOADS, 'rrt-*', 'rrt-probe-*')), 'its test files are deleted')
	page.reload()
	check(page.is_visible('#rrt-private-check pre code') and 'deny all;' in page.inner_text('#rrt-private-check pre code'), 'on the next visit the nginx lines are shown')
	check('visitors could open' in page.inner_text('[data-rrt-pc-status]'), 'with the last result')
	check(not errors, 'no script errors', errors)
	browser.close()

print('\n%d passed, %d failed' % (passed, len(failed)))
sys.exit(1 if failed else 0)
