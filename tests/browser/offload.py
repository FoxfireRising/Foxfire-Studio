#!/usr/bin/env python3
"""
Storage offload (1.23.0), as an owner sets it up: S3-compatible storage (the
stand-in in tests/wp/storage.py, which checks every signature) filled in and
saved; the switch stays off until Check this setup passes; the check fails
while the storage gives this browser no CORS headers, and Set the CORS rules
for me fixes it; switched on, Copy everything now copies the reading and its
captions; the post's player then plays the outside copy; Delete the outside
copies (pressed twice) empties the storage; switched off, the player is back
on this site's file.

Usage: OF_SEED=<json from tests/wp/offload.php> OFF_PORT=8090 tests/browser/offload.py http://localhost:8080
"""
import json, os, sys, urllib.request
from playwright.sync_api import sync_playwright
from signin import sign_in

BASE = sys.argv[1].rstrip('/') if len(sys.argv) > 1 else 'http://localhost:8080'
CHROME = os.environ.get('CHROME_PATH', '/usr/bin/google-chrome')
SEED = json.loads(open(os.environ['OF_SEED']).read())
ST = 'http://127.0.0.1:%s' % os.environ.get('OFF_PORT', '8090')
SCREEN = BASE + '/wp-admin/admin.php?page=rrt-podcast#rrt-offload'

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


def state():
	return json.loads(urllib.request.urlopen(ST + '/admin/state').read())


def admin(q):
	urllib.request.urlopen(ST + '/admin/' + q).read()


def ours(st):
	return [k for k, v in st['objects'].items() if v['sha'] == SEED['sha']]


admin('reset')
with sync_playwright() as p:
	browser = p.chromium.launch(executable_path=CHROME if os.path.exists(CHROME) else None)
	ctx = browser.new_context(viewport={'width': 1280, 'height': 1000})
	errors = []
	page = ctx.new_page()
	page.on('pageerror', lambda e: errors.append(str(e)))
	sign_in(page, BASE)

	page.goto(SCREEN)
	page.check('input[name="mode"][value="s3"]')
	check(page.is_visible('#rrt-off-s3_endpoint') and not page.is_visible('#rrt-off-b_zone'), 'choosing S3 shows its fields only')
	page.select_option('[data-rrt-off-preset]', 'r2')
	check(page.input_value('#rrt-off-s3_region') == 'auto', 'a service\'s preset fills in its region')
	page.fill('#rrt-off-s3_endpoint', ST + '/s3')
	page.fill('#rrt-off-s3_bucket', 'readings')
	page.fill('#rrt-off-s3_key', 'FFTESTKEY')
	page.fill('#rrt-off-s3_secret', 'fftestsecret')
	page.fill('#rrt-off-s3_base', ST + '/pub')
	with page.expect_navigation():
		page.click('#rrt-off-form [type="submit"]')
	check('offsaved=1' in page.url, 'the section saves on its own')
	check(page.is_disabled('#rrt-off-form input[name="on"]'), 'the switch waits for the check')
	check(page.input_value('#rrt-off-s3_secret') == '' and 'fftestsecret' not in page.content(), 'the saved secret is never shown again')

	admin('set?cors=0')
	page.click('[data-rrt-off-check]')
	page.wait_for_selector('text=Set the CORS rules for me', timeout=30000)
	txt = page.inner_text('[data-rrt-off-steps]')
	check('This browser could not read it' in txt and 'byte range' not in txt, 'without CORS the check says so after the site\'s part passed', txt)
	page.click('text=Set the CORS rules for me')
	page.wait_for_selector('text=Passed.', timeout=30000)
	st = state()
	check('<AllowedMethod>GET</AllowedMethod>' in st['bucket_cors'] and 'Range' in st['bucket_cors'], 'the plugin set the bucket\'s CORS rules, and the check passed')
	check(not page.is_disabled('#rrt-off-form input[name="on"]'), 'the switch can be ticked now')
	check(not [k for k in st['objects'] if 'check-' in k], 'the test file was deleted')

	page.check('#rrt-off-form input[name="on"]')
	with page.expect_navigation():
		page.click('#rrt-off-form [type="submit"]')
	check(page.is_checked('#rrt-off-form input[name="on"]'), 'switched on')
	page.click('[data-rrt-off-copy]')
	page.wait_for_function("document.querySelector('[data-rrt-off-progress]').textContent.indexOf('Done.') >= 0", timeout=120000)
	st = state()
	check(len(ours(st)) == 1 and any(k.endswith('.vtt') for k in st['objects']), 'Copy everything now copied the reading and its captions', page.inner_text('[data-rrt-off-progress]'))

	page.goto(BASE + '/?p=%d' % SEED['post'])
	src = page.get_attribute('audio.rrt-audio', 'src') or ''
	check(src.startswith(ST + '/pub/foxfire/') and src.endswith('/storage-offload-test.mp3'), 'the post\'s player plays the outside copy', src)
	played = page.evaluate("""() => new Promise(function (res) {
		var a = document.querySelector('audio.rrt-audio');
		a.muted = true;
		var t = setTimeout(function () { res(a.currentTime); }, 8000);
		a.addEventListener('timeupdate', function () { if (a.currentTime > 0.3) { clearTimeout(t); res(a.currentTime); } });
		a.play().catch(function () {});
	})""")
	check(played > 0.3, 'and it plays', played)
	track = page.get_attribute('audio.rrt-audio track', 'src') or ''
	check(track.startswith(BASE), 'its captions come from this site', track)

	page.goto(SCREEN)
	page.click('[data-rrt-off-clear]')
	check('Press again' in page.inner_text('[data-rrt-off-clear]'), 'Delete the outside copies asks for a second press')
	page.click('[data-rrt-off-clear]')
	page.wait_for_selector('text=Every outside copy is deleted', timeout=60000)
	st = state()
	check(not [k for k in st['objects'] if k.startswith('foxfire/')], 'the storage is empty of copies')

	page.uncheck('#rrt-off-form input[name="on"]')
	with page.expect_navigation():
		page.click('#rrt-off-form [type="submit"]')
	page.goto(BASE + '/?p=%d' % SEED['post'])
	check((page.get_attribute('audio.rrt-audio', 'src') or '') == SEED['mp3'], 'switched off, the player is back on this site\'s file')
	check(not errors, 'no script errors', errors)
	browser.close()

print('\n%d passed, %d failed' % (passed, len(failed)))
sys.exit(1 if failed else 0)
