#!/usr/bin/env python3
"""
Download counting to the IAB guidelines (1.24.0), as an owner meets it: the
Download counting section on Podcast feed, Check counting on a server with no
hand-off (the test site's PHP server) and with nginx chosen but not set up
(each step shown, a redirect kept), shared networks confirmed, OP3 ticked and
the public feed's addresses then going through it, the method page made as a
draft and read; then the Overview: podcast consumers a day, the unusual day,
Apple's player named plainly, phones, and Reviewed clearing the review line.

Usage: DC_SEED=<json from tests/wp/dlcount.php> tests/browser/dlcount.py http://localhost:8080
"""
import json, os, sys
from playwright.sync_api import sync_playwright
from signin import sign_in

BASE = sys.argv[1].rstrip('/') if len(sys.argv) > 1 else 'http://localhost:8080'
CHROME = os.environ.get('CHROME_PATH', '/usr/bin/google-chrome')
SEED = json.loads(open(os.environ['DC_SEED']).read())
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


def save(page):
	with page.expect_navigation():
		page.click('#rrt-dl-form [name="save_dl"]')


with sync_playwright() as p:
	browser = p.chromium.launch(executable_path=CHROME if os.path.exists(CHROME) else None)
	ctx = browser.new_context(viewport={'width': 1280, 'height': 1000})
	errors = []
	page = ctx.new_page()
	page.on('pageerror', lambda e: errors.append(str(e)))
	sign_in(page, BASE)

	page.goto(SCREEN + '#rrt-dl')
	check(page.is_visible('#rrt-dl') and 'version 2.3' in page.inner_text('#rrt-dl ~ p:not(.rrt-help-part-link)'), 'the Download counting section names the guidelines\' version')
	check('never claims one' in page.inner_text('#rrt-dl ~ p:not(.rrt-help-part-link)'), 'and says it never claims a certificate')
	check('a redirect, until Check counting succeeds' in page.inner_text('[data-rrt-dl-status]'), 'before any check, files go by redirect')

	page.click('[data-rrt-dl-check]')
	page.wait_for_selector('#rrt-dl-form[data-rrt-dl-done]', timeout=30000)
	txt = page.inner_text('[data-rrt-dl-status]')
	check('In use: a redirect' in txt and 'no hand-off' in txt, 'on a server with no hand-off the check says files go by redirect', txt)

	page.select_option('#rrt-dl-send', 'nginx')
	save(page)
	check('dlsaved=1' in page.url and page.is_visible('pre.rrt-dl-nginx') and 'internal;' in page.inner_text('pre.rrt-dl-nginx'), 'choosing nginx shows the lines it needs')
	page.click('[data-rrt-dl-check]')
	page.wait_for_selector('#rrt-dl-form[data-rrt-dl-done]', timeout=30000)
	steps = page.inner_text('[data-rrt-dl-steps]')
	check(page.get_attribute('#rrt-dl-form', 'data-rrt-dl-done') == 'redirect' and '✗' in steps and 'Counting saw the request' in steps, 'nginx not set up: each step is shown, and a redirect stays', steps)
	page.select_option('#rrt-dl-send', 'auto')

	page.fill('#rrt-dl-shared', '203.0.113.0/24\nnot a range')
	save(page)
	check(page.input_value('#rrt-dl-shared') == '203.0.113.0/24' and 'Last confirmed' in page.inner_text('#rrt-dl-form'), 'shared networks keep only real ranges and are confirmed on saving')

	page.check('#rrt-dl-form input[name="op3"]')
	save(page)
	check(page.is_checked('#rrt-dl-form input[name="op3"]'), 'OP3 is ticked')
	page.goto(BASE + '/wp-admin/options-general.php?page=rrt-settings')
	priv = page.content()
	check('op3.dev' in priv, 'the privacy paragraph names OP3 while it is on')
	page.goto(SCREEN + '#rrt-dl')
	page.uncheck('#rrt-dl-form input[name="op3"]')
	save(page)

	page.click('#rrt-dl-form [name="make_page"]') if page.is_visible('#rrt-dl-form [name="make_page"]') else None
	page.wait_for_load_state('load')
	link = page.locator('#rrt-dl-form a:has-text("How downloads are counted")')
	check(link.count() == 1 and '(draft)' in page.inner_text('#rrt-dl-form'), 'Make the method page makes a draft')
	edit = link.get_attribute('href')
	pid = edit.split('post=')[1].split('&')[0]
	page.goto(BASE + '/?page_id=' + pid + '&preview=true')
	body = page.inner_text('body')
	check('What counts as a download' in body and 'version 2.3' in body and 'no audit or certification' in body, 'the draft reads as the method', body[:200])

	page.goto(BASE + '/wp-admin/admin.php?page=rrt-dashboard&range=7')
	page.wait_for_selector('#rrt-downloads')
	sec = page.inner_text('#wpbody-content')
	check('Podcast consumers a day' in sec, 'the Overview shows podcast consumers a day')
	check('Unusual days' in sec and 'all downloads: 40' in sec, 'today\'s 40 downloads are flagged as unusual', sec[sec.find('Unusual'):sec.find('Unusual') + 200])
	check('Apple\'s player (AppleCoreMedia)' in sec and 'Phone or tablet' in sec, 'Apple\'s player is named plainly, and phones are counted', '')
	check(page.is_visible('[data-rrt-dl-reviewed]'), 'a review is asked for after six months')
	with page.expect_navigation():
		page.click('[data-rrt-dl-reviewed]')
	page.wait_for_selector('#rrt-downloads')
	check(not page.is_visible('[data-rrt-dl-reviewed]'), 'Reviewed clears the line')

	check(not errors, 'no script errors', '; '.join(errors))
	browser.close()

print('\n%d passed, %d failed' % (passed, len(failed)))
sys.exit(1 if failed else 0)
