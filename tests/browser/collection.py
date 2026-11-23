#!/usr/bin/env python3
"""
Collections (1.19.0), end to end.

On Foxfire Studio, Collections, Add New: three readings are found by search
and added in order. The third is stereo at 192 kbps, so the box says it must
be matched; saving does not join the collection and says why. After the
save, the box measures each reading's loudness in the browser: the second is
about 4 dB quieter and is pointed out. Match it in this browser makes a copy
of the third in the collection's format, and the collection joins; matching
the second levels it with the others.

On the collection's page the player plays the joined MP3, its chapter menu
lists the three readings, and the list's play buttons move the player to
each reading. Decoding the joined file shows silence in the gaps and every
reading near -19 LUFS. Opening the box again does not measure anything
twice, and removing a reading and saving joins the other two.

The readings are made by tests/wp/collections-mp3.js and tests/wp/collections.php.

Usage: CL_SEED=<json from tests/wp/collections.php> CL_MP3=<mp3.wasm> tests/browser/collection.py http://localhost:8080
"""
import json, os, re, sys
from playwright.sync_api import sync_playwright
from signin import sign_in

BASE = sys.argv[1].rstrip('/') if len(sys.argv) > 1 else 'http://localhost:8080'
CHROME = os.environ.get('CHROME_PATH', '/usr/bin/google-chrome')
SEED = json.load(open(os.environ['CL_SEED']))
MP3 = open(os.environ['CL_MP3'], 'rb').read()

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


def add(page, title):
	n = page.locator('.rrt-col-row').count()
	page.fill('#rrt-col-search', title)
	page.locator('.rrt-col-results button', has_text=title).first.click(timeout=15000)
	page.wait_for_function('document.querySelectorAll(".rrt-col-row").length === %d' % (n + 1), timeout=15000)


def row(page, i):
	return page.locator('.rrt-col-row').nth(i)


def wait_msg(page, text, timeout=120000):
	page.wait_for_function('(document.querySelector(".rrt-col-msg") || {}).textContent && (document.querySelector(".rrt-col-msg").textContent.indexOf(%s) >= 0 || document.querySelector(".rrt-col-msg").className.indexOf("is-bad") >= 0)' % json.dumps(text), timeout=timeout)
	return page.inner_text('.rrt-col-msg')


with sync_playwright() as p:
	browser = p.chromium.launch(executable_path=CHROME if os.path.exists(CHROME) else None, args=['--autoplay-policy=no-user-gesture-required'])
	ctx = browser.new_context(viewport={'width': 1280, 'height': 1000})
	ctx.route(re.compile(r'.*/mp3\.wasm.*'), lambda r: r.fulfill(status=200, content_type='application/wasm', body=MP3, headers={'Access-Control-Allow-Origin': '*'}))
	page = ctx.new_page()
	errors = []
	page.on('pageerror', lambda e: errors.append(str(e)))
	sign_in(page, BASE)

	page.goto(BASE + '/wp-admin/post-new.php?post_type=rrt_collection', wait_until='load')
	page.wait_for_selector('#rrt-collection .rrt-col-add', timeout=20000)
	check(page.locator('#adminmenu a[href="edit.php?post_type=rrt_collection"]').count() == 1, 'Collections is in the Foxfire Studio menu')
	col_id = int(page.input_value('#post_ID'))
	page.fill('#title', 'Collection test: the turning year')
	add(page, 'Collection test Samhain')
	add(page, 'Collection test Yule')
	add(page, 'Collection test Imbolc')
	page.wait_for_function('document.querySelectorAll(".rrt-col-row.is-format").length === 1', timeout=15000)
	note = row(page, 2).inner_text()
	check('192 kbps stereo' in note and '128 kbps mono' in note and 'needs matching' in note, 'the stereo reading must be matched, and the box says why', note)
	check(page.input_value('#rrt-col-items') == '%d,%d,%d' % (SEED['a'], SEED['b'], SEED['c']), 'the readings are kept in the order added')
	check('Save the collection to join' in page.inner_text('#rrt-collection'), 'the box says to save before joining')

	# Moving and removing change the order the site sees.
	row(page, 2).locator('button[aria-label="Move up"]').click()
	page.wait_for_function('document.getElementById("rrt-col-items").value === "%d,%d,%d"' % (SEED['a'], SEED['c'], SEED['b']), timeout=5000)
	row(page, 1).locator('button[aria-label="Move down"]').click()
	page.wait_for_function('document.getElementById("rrt-col-items").value === "%d,%d,%d"' % (SEED['a'], SEED['b'], SEED['c']), timeout=5000)
	check(True, 'moving a reading up and down changes the order')

	with page.expect_navigation(wait_until='load'):
		page.click('#save-post')
	notice = page.inner_text('#wpbody-content .notice-warning') if page.locator('#wpbody-content .notice-warning').count() else ''
	check('needs matching first' in notice, 'saving with a reading still to match does not join, and says why', notice)

	# The loudness of each reading is measured in the browser, once.
	page.wait_for_function('document.querySelectorAll(".rrt-col-row.is-level").length === 1', timeout=60000)
	lev = row(page, 1).inner_text()
	check('quieter than the rest' in lev and 'LUFS' in lev, 'the quieter reading is pointed out once measured', lev)
	m = re.search(r'(-?\d+\.\d) LUFS', row(page, 0).inner_text())
	check(m and abs(float(m.group(1)) + 19) < 1.0, 'the first reading measures about -19 LUFS', row(page, 0).inner_text())

	# Match the stereo reading, then the quiet one.
	row(page, 2).locator('.rrt-col-match').click()
	msg = wait_msg(page, 'is matched')
	check('is matched' in msg, 'the stereo reading is matched in the browser and the collection joins', msg)
	built = page.inner_text('.rrt-col-built')
	check('Joined 3 readings' in built, 'the box says the three readings are joined', built)
	check('Matched in the browser' in row(page, 2).inner_text(), 'the stereo reading now says it was matched')
	row(page, 1).locator('.rrt-col-match').click()
	msg = wait_msg(page, 'is matched')
	check('is matched' in msg and page.locator('.rrt-col-row.is-level').count() == 0, 'the quiet reading is matched too', msg)

	# Publish and look at the page.
	with page.expect_navigation(wait_until='load'):
		page.click('#publish')
	view = page.locator('#message a, #wp-admin-bar-view a')
	link = view.first.get_attribute('href') if view.count() else ''
	check('/collections/' in link or 'rrt_collection=' in link, 'the collection has an address of its own', link)
	page.goto(link, wait_until='load')
	page.wait_for_selector('.rrt-player.is-ready', timeout=20000)
	starts = [float(x) for x in page.eval_on_selector_all('.rrt-col-list [data-rrt-seek]', 'els => els.map(e => e.getAttribute("data-rrt-seek"))')]
	check(len(starts) == 3 and starts[0] == 0 and starts[1] > 8 and starts[2] > starts[1] + 6, 'the page lists the three readings with their times', starts)
	page.wait_for_selector('.rrt-chapters-btn', timeout=20000)
	page.click('.rrt-chapters-btn')
	chapters = page.eval_on_selector_all('.rrt-chapters li', 'els => els.map(e => e.textContent.trim())')
	check(len(chapters) == 3 and 'Collection test Yule' in chapters[1], 'the player\'s chapter menu lists the readings', chapters)
	page.keyboard.press('Escape')
	# The test server cannot answer range requests, so the player's own seek event is what is checked.
	page.evaluate('window.rrtSeeks = []; document.addEventListener("rrt:player", e => { if (e.detail.type === "seek") window.rrtSeeks.push(e.detail); })')
	page.locator('.rrt-col-list [data-rrt-seek]').nth(1).click()
	page.wait_for_timeout(800)
	seeks = page.evaluate('window.rrtSeeks')
	check(seeks and abs(seeks[-1]['to'] - starts[1]) < 0.2 and seeks[-1]['how'] == 'chapter', 'a play button in the list moves the player to its reading', seeks)
	page.evaluate('document.querySelector(".rrt-player audio").pause()')

	# The joined file itself.
	res = page.evaluate('''async (starts) => {
		const src = document.querySelector('.rrt-player audio').currentSrc;
		const ab = await (await fetch(src)).arrayBuffer();
		const ctx = new OfflineAudioContext(1, 1, 44100);
		const buf = await ctx.decodeAudioData(ab);
		const x = buf.getChannelData(0);
		const rate = buf.sampleRate;
		const rms = (a, b) => { let s = 0; const i0 = Math.round(a * rate), i1 = Math.round(b * rate); for (let i = i0; i < i1; i++) s += x[i] * x[i]; return Math.sqrt(s / Math.max(1, i1 - i0)); };
		return { secs: buf.duration, ch: buf.numberOfChannels, gap1: rms(starts[1] - 1.6, starts[1] - 0.4), gap2: rms(starts[2] - 1.6, starts[2] - 0.4) };
	}''', starts)
	check(res['ch'] == 1 and res['secs'] > starts[2] + 4, 'the joined file is mono and holds all three readings', res)
	check(res['gap1'] < 1e-4 and res['gap2'] < 1e-4, 'the gaps between readings are silent', res)
	page.goto(BASE + '/wp-admin/post.php?post=%d&action=edit' % col_id, wait_until='load')
	page.wait_for_selector('#rrt-collection .rrt-col-row', timeout=20000)
	lufs = page.evaluate('''async () => {
		const sound = window.RRTSound;
		const src = document.querySelector('.rrt-col-audio').currentSrc || document.querySelector('.rrt-col-audio').src;
		const ab = await (await fetch(src)).arrayBuffer();
		const buf = await new OfflineAudioContext(1, 1, 44100).decodeAudioData(ab);
		return sound.lufs(buf.getChannelData(0), buf.sampleRate);
	}''')
	check(abs(lufs + 19) < 1.0, 'the whole collection measures about -19 LUFS once every reading is level', lufs)
	page.wait_for_timeout(1500)
	check('measuring' not in page.inner_text('#rrt-collection'), 'opening the box again measures nothing twice')

	# Remove the stereo reading and save: the other two are joined.
	row(page, 2).locator('.rrt-col-remove').click()
	page.wait_for_function('document.querySelectorAll(".rrt-col-row").length === 2', timeout=10000)
	with page.expect_navigation(wait_until='load'):
		page.click('#publish')
	page.wait_for_selector('.rrt-col-built', timeout=20000)
	built = page.inner_text('.rrt-col-built')
	check('Joined 2 readings' in built, 'removing a reading and saving joins the other two', built)

	check(not errors, 'no script errors on the pages', errors)
	browser.close()

print('%d passed, %d failed' % (passed, len(failed)))
sys.exit(1 if failed else 0)
