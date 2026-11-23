#!/usr/bin/env python3
"""
Keep a reading aside (1.8.0), as a person does it: Take off the post on the
reading's page (with its confirmation), the post without its player and the
old address gone, Readings to make marking the post, the Kept aside table on
Recordings with a player that streams and seeks (byte ranges) and a Download,
refused to someone signed out, Put back bringing the same address back, and
Delete for good asking first and doing nothing when the answer is no.

Usage: KP_POST=<post id> tests/browser/keep.py http://localhost:8080
The post comes from tests/wp/keep.php: a 3 s WAV reading in the Media Library.
"""
import os, re, sys
from playwright.sync_api import sync_playwright
from signin import sign_in

BASE = sys.argv[1].rstrip('/') if len(sys.argv) > 1 else 'http://localhost:8080'
CHROME = os.environ.get('CHROME_PATH', '/usr/bin/google-chrome')
POST_ID = int(os.environ['KP_POST'])
READING = BASE + '/wp-admin/admin.php?page=rrt-readings&post=%d' % POST_ID
RECORDINGS = BASE + '/wp-admin/admin.php?page=rrt-recordings'
BACKLOG = BASE + '/wp-admin/admin.php?page=rrt-backlog'
POST_URL = BASE + '/?p=%d' % POST_ID

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


with sync_playwright() as p:
	browser = p.chromium.launch(executable_path=CHROME if os.path.exists(CHROME) else None)
	ctx = browser.new_context(viewport={'width': 1280, 'height': 900})
	page = ctx.new_page()
	errors = []
	page.on('pageerror', lambda e: errors.append(str(e)))
	answer = {'yes': True, 'asked': []}

	def on_dialog(d):
		answer['asked'].append(d.message)
		if answer['yes']:
			d.accept()
		else:
			d.dismiss()
	page.on('dialog', on_dialog)

	outside = browser.new_context()

	def public_status(url):
		return outside.request.get(url, max_redirects=0).status

	def player_on_post():
		# The test site shows the player to editors only, so ask as the signed-in admin.
		html = ctx.request.get(POST_URL).text()
		return 'class="rrt-player"' in html

	sign_in(page, BASE)
	page.goto(READING, wait_until='load')
	keep = page.locator('#rrt-keep')
	check(keep.count() == 1 and 'Take off the post' in keep.inner_text(), "the reading's page offers Take off the post")
	mp3 = page.evaluate("(document.querySelector('.rrt-dash-audio') || {}).src || ''")
	check(mp3.endswith('.wav'), 'the reading has a public address', mp3)
	check(public_status(mp3) == 200 and player_on_post(), 'before: the address answers and the post shows the player')

	# Saying no to the question changes nothing.
	answer['yes'] = False
	page.click('#rrt-keep button[data-kept="off"]')
	page.wait_for_timeout(500)
	check(len(answer['asked']) == 1 and 'kept aside' in answer['asked'][0], 'Take off asks first', ' | '.join(answer['asked']))
	check(page.url.startswith(READING) and public_status(mp3) == 200, 'answering no leaves the reading on the post')

	answer['yes'] = True
	with page.expect_navigation(wait_until='load'):
		page.click('#rrt-keep button[data-kept="off"]')
	msg = page.locator('.rrt-kept-msg').inner_text() if page.locator('.rrt-kept-msg').count() else ''
	check('is off the post and kept aside' in msg, 'a message says the reading is off the post', msg)
	check('#rrt-keep' in page.url, 'it comes back to the same place on the page', page.url)
	check('Kept aside:' in page.locator('#rrt-keep').inner_text() and page.locator('#rrt-keep button[data-kept="back"]').count() == 1, 'the page now offers Put back')
	check(page.locator('.rrt-dash-audio').count() == 0, 'the page no longer plays the public file')
	check(public_status(mp3) == 404, 'the old public address is gone', str(public_status(mp3)))
	check(not player_on_post(), 'the post no longer shows the player')

	page.reload(wait_until='load')
	check(page.locator('.rrt-kept-msg').count() == 0, 'the message shows once')

	page.goto(BACKLOG, wait_until='load')
	pill = page.locator('#rrt-t-backlog .rrt-kept-pill, .rrt-kept-pill')
	check(pill.count() == 1 and 'Reading kept aside' in pill.first.inner_text(), 'Readings to make lists the post, marked Reading kept aside')

	page.goto(RECORDINGS, wait_until='load')
	rows = page.locator('#rrt-t-kept tbody tr[data-kept]')
	check(rows.count() == 1, 'Recordings lists one reading kept aside', str(rows.count()))
	check(page.locator('#rrt-t-recordings a[href="#rrt-kept"]').count() == 1, 'the Recordings table marks its row kept aside')
	src = rows.first.locator('audio.rrt-kept-audio').get_attribute('src')
	r = ctx.request.get(src)
	body = r.body()
	check(r.status == 200 and r.headers.get('content-type', '').startswith('audio/wav') and len(body) > 1000, 'the private player gets the reading', '%s %s %d' % (r.status, r.headers.get('content-type'), len(body)))
	check('no-store' in r.headers.get('cache-control', ''), 'it is never cached')
	rr = ctx.request.get(src, headers={'Range': 'bytes=100-199'})
	check(rr.status == 206 and rr.body() == body[100:200] and rr.headers.get('content-range', '') == 'bytes 100-199/%d' % len(body), 'it answers byte ranges, so the player can seek', '%s %s' % (rr.status, rr.headers.get('content-range')))
	tail = ctx.request.get(src, headers={'Range': 'bytes=-50'})
	check(tail.status == 206 and tail.body() == body[-50:], 'it answers a range from the end')
	o = outside.request.get(src)
	check(o.status in (400, 403) and not o.headers.get('content-type', '').startswith('audio/'), 'someone signed out is refused', str(o.status))
	check(ctx.request.get(src.replace('_wpnonce=', '_wpnonce=x')).status == 403, 'a wrong code is refused')
	dl = rows.first.locator('a', has_text='Download').get_attribute('href')
	d = ctx.request.get(dl)
	check(d.status == 200 and 'attachment' in d.headers.get('content-disposition', '') and 'keep-test-reading' in d.headers.get('content-disposition', '') and d.body() == body, 'Download gives the same file, named as before', d.headers.get('content-disposition', ''))
	audio_ok = page.evaluate("""async () => {
		var a = document.querySelector('#rrt-t-kept audio.rrt-kept-audio');
		a.preload = 'auto'; a.load();
		await new Promise(function (res) { a.addEventListener('loadedmetadata', res, { once: true }); setTimeout(res, 5000); });
		return a.duration;
	}""")
	check(audio_ok and audio_ok > 2 and audio_ok < 5, 'the player on the page opens it', str(audio_ok))

	with page.expect_navigation(wait_until='load'):
		rows.first.locator('button[data-kept="back"]').click()
	msg = page.locator('.rrt-kept-msg').inner_text() if page.locator('.rrt-kept-msg').count() else ''
	check('is back on' in msg and '#rrt-kept' in page.url, 'Put back says the reading is back', msg)
	check(public_status(mp3) == 200, 'the reading is at the same public address again')
	check(player_on_post(), 'the post shows the player again')
	check(page.locator('#rrt-t-kept tbody tr[data-kept]').count() == 0, 'nothing is kept aside afterwards')

	# Take it off again, then Delete for good: no first, then yes.
	page.goto(READING, wait_until='load')
	with page.expect_navigation(wait_until='load'):
		page.click('#rrt-keep button[data-kept="off"]')
	page.goto(RECORDINGS, wait_until='load')
	answer['yes'] = False
	answer['asked'] = []
	page.locator('#rrt-t-kept button[data-kept="delete"]').click()
	page.wait_for_timeout(500)
	check(len(answer['asked']) == 1 and 'cannot be put back' in answer['asked'][0], 'Delete for good asks first')
	check(page.locator('#rrt-t-kept tbody tr[data-kept]').count() == 1, 'answering no keeps it')
	answer['yes'] = True
	with page.expect_navigation(wait_until='load'):
		page.locator('#rrt-t-kept button[data-kept="delete"]').click()
	check(page.locator('#rrt-t-kept tbody tr[data-kept]').count() == 0 and 'deleted for good' in page.locator('.rrt-kept-msg').inner_text(), 'Delete for good removes it')
	check(ctx.request.get(src).status in (403, 404), 'its private address no longer answers')
	check(page.locator('#rrt-t-recordings tbody tr').count() >= 1, 'its row stays in the Recordings table')

	check(not errors, 'no script errors', '; '.join(errors))
	browser.close()

print('\n%d passed, %d failed' % (passed, len(failed)))
sys.exit(1 if failed else 0)
