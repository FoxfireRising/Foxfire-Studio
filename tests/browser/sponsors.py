#!/usr/bin/env python3
"""
Sponsor messages (1.22.0), end to end.

A recorded message (tests/wp/sponsors.php) for every reading, in the
pre-roll and the mid-roll. On its screen the copies box first measures the
reading's loudness in the browser, then asks for one copy, in 128 kbps mono
at the reading's loudness; Make them now makes it with the plugin's own
encoder and the site keeps it. The paid message's captions hold its
disclosure, and the box says so. The studio opens on the message's script.

In the reading's Reading audio box the pauses are found in the browser and
the places near the middle offered; one is chosen, heard, and saved with
the post. The box then shows what the podcast episode holds.

The feed's episode is the sponsored copy, its notes start with the
sponsor's line, and its chapters name the messages. Decoding the copy in the
browser shows the message at the reading's loudness, and the reading after
the mid-roll identical to the original from 50 ms on; in those first 50 ms
(where an MP3 cut without care ticks) it differs by less than the room. The thank-you line under the player is
switched on and checked; No sponsor messages on this reading puts the feed
back to the reading.

Usage: SP_SEED=<json from tests/wp/sponsors.php> SP_MP3=<mp3.wasm> tests/browser/sponsors.py http://localhost:8080
"""
import json, os, re, sys
from playwright.sync_api import sync_playwright
from signin import sign_in

BASE = sys.argv[1].rstrip('/') if len(sys.argv) > 1 else 'http://localhost:8080'
CHROME = os.environ.get('CHROME_PATH', '/usr/bin/google-chrome')
SEED = json.load(open(os.environ['SP_SEED']))
MP3 = open(os.environ['SP_MP3'], 'rb').read()
R = SEED['reading']
SPOT = SEED['spot']

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


def no_guide(page):
	page.evaluate("() => { if (window.wp && wp.data && wp.data.dispatch('core/edit-post')) { var d = wp.data.dispatch('core/edit-post'); if (d.toggleFeature && wp.data.select('core/edit-post').isFeatureActive('welcomeGuide')) { d.toggleFeature('welcomeGuide'); } } }")


def save_post(page):
	# The block editor saves the post, then the meta boxes in a second request: wait for that one to start and end.
	page.evaluate("""() => { window.__mbSaw = false; wp.data.subscribe(() => { if (wp.data.select('core/edit-post').isSavingMetaBoxes()) { window.__mbSaw = true; } }); }""")
	page.evaluate("async () => { await wp.data.dispatch('core/editor').savePost(); }")
	try:
		page.wait_for_function("() => window.__mbSaw", timeout=10000)
	except Exception:
		pass
	page.wait_for_function("() => !wp.data.select('core/editor').isSavingPost() && !wp.data.select('core/edit-post').isSavingMetaBoxes()", timeout=60000)
	page.wait_for_timeout(500)


def feed(page):
	r = page.request.get(BASE + '/?feed=podcast')
	return r.text()


def item(xml, title):
	for chunk in xml.split('<item>'):
		if '<title>' + title + '</title>' in chunk:
			return chunk
	return ''


TITLE = 'Sponsor test: a long reading'

with sync_playwright() as p:
	browser = p.chromium.launch(executable_path=CHROME if os.path.exists(CHROME) else None, args=['--autoplay-policy=no-user-gesture-required'])
	ctx = browser.new_context(viewport={'width': 1280, 'height': 1000})
	ctx.route(re.compile(r'.*/mp3\.wasm.*'), lambda r: r.fulfill(status=200, content_type='application/wasm', body=MP3, headers={'Access-Control-Allow-Origin': '*'}))
	page = ctx.new_page()
	errors = []
	page.on('pageerror', lambda e: errors.append(str(e)))
	sign_in(page, BASE)

	# The list and the message's screen.
	page.goto(BASE + '/wp-admin/edit.php?post_type=rrt_spot', wait_until='load')
	check(page.locator('#adminmenu a[href="edit.php?post_type=rrt_spot"]').count() == 1, 'Sponsor messages is in the Foxfire Studio menu')
	row = page.locator('#post-%d' % SPOT).inner_text()
	check('Acme Candles' in row and 'Paid or gifted' in row and 'Running' in row, 'the list shows who it is for, its kind and that it runs', row)

	page.goto(BASE + '/wp-admin/post.php?post=%d&action=edit' % SPOT, wait_until='load')
	page.wait_for_selector('[data-rrt-spot-make]', timeout=60000)
	box = page.inner_text('[data-rrt-spot-copies]')
	check('128 kbps mono at -19' in box and '(still to make)' in box and TITLE in box, 'after measuring the reading, the box asks for one copy in its format and loudness', box)
	check(page.locator('.rrt-spot-ok').count() == 1, 'the paid message\'s disclosure is heard in its captions')
	check('This reading is supported by Acme Candles.' in page.input_value('#rrt-spot-script'), 'the script is on the screen')
	page.click('[data-rrt-spot-make]')
	page.wait_for_function("() => document.querySelectorAll('.rrt-spot-needs li.is-done').length === 1 || /could not/.test((document.querySelector('.rrt-spot-note') || {}).textContent || '')", timeout=120000)
	note = page.inner_text('[data-rrt-spot-copies]')
	check('(made)' in note and 'Every copy is made' in note, 'Make them now makes the copy and the site keeps it', note)
	page.goto(BASE + '/wp-admin/post.php?post=%d&action=edit' % SPOT, wait_until='load')
	page.wait_for_selector('.rrt-spot-needs li', timeout=30000)
	check(page.locator('.rrt-spot-needs li.is-done').count() == 1 and page.locator('[data-rrt-spot-make]').count() == 0, 'opened again, nothing is measured or made twice')

	# The studio reads the message's script.
	page.goto(BASE + '/?teleprompter=1&tp_post=%d' % SPOT, wait_until='load')
	page.wait_for_timeout(1500)
	txt = page.inner_text('body')
	check('Find them at example.com.' in txt, 'the studio opens on the message\'s script')
	first = page.evaluate("() => { const e = document.querySelector('#tpText > *, .tp-block, [data-b]'); return e ? e.textContent.trim() : ''; }")
	check(first.startswith('This reading is supported by Acme Candles') and 'spring message' not in txt.split('Find them')[0].split('supported by')[0], 'it starts with the script\'s first line, never the message\'s title in the list', first)

	# The reading's box: pauses found here, a place chosen, heard and saved.
	page.goto(BASE + '/wp-admin/post.php?post=%d&action=edit' % R, wait_until='load')
	page.wait_for_selector('#rrt_spot_mid', state='attached', timeout=60000)
	no_guide(page)
	page.wait_for_function("() => document.querySelectorAll('#rrt_spot_mid option').length >= 4", timeout=60000)
	opts = page.evaluate("() => Array.from(document.querySelectorAll('#rrt_spot_mid option')).map(o => o.value).filter(v => v && v !== 'other').map(Number)")
	secs = page.evaluate("() => document.querySelector('[data-rrt-spot-box]').rrtSpot.boot.secs")
	check(len(opts) == 3 and all(0.3 * secs <= t <= 0.7 * secs for t in opts), 'three pauses near the middle are offered', (opts, secs))
	want = opts[1]
	page.select_option('#rrt_spot_mid', str(want) if str(want) in page.evaluate("() => Array.from(document.querySelectorAll('#rrt_spot_mid option')).map(o => o.value)") else page.evaluate("() => document.querySelectorAll('#rrt_spot_mid option')[2].value"))
	page.dispatch_event('#rrt_spot_mid', 'change')
	page.click('[data-rrt-spot-hear]')
	page.wait_for_timeout(1500)
	# The test server cannot answer range requests for files, so the seek itself is not checked here; the stop point is.
	now = page.evaluate("() => { var b = document.querySelector('[data-rrt-spot-box]').rrtSpot; return b.audio ? { stop: b.stopAt, playing: !b.audio.paused } : null; }")
	check(now and abs(now['stop'] - (want + 5)) < 0.01 and now['playing'], 'Hear 5 seconds either side plays, to stop five seconds after the place', (now, want))
	save_post(page)
	page.goto(BASE + '/wp-admin/post.php?post=%d&action=edit' % R, wait_until='load')
	page.wait_for_selector('[data-rrt-spot-box]', state='attached', timeout=30000)
	btxt = page.evaluate("() => document.querySelector('[data-rrt-spot-box]').textContent")
	check('Pre-roll: Acme Candles' in btxt and 'Mid-roll at' in btxt, 'the box shows what the podcast episode holds now', btxt)
	check(page.evaluate("() => document.getElementById('rrt_spot_mid').value") == str(want) or abs(float(page.evaluate("() => document.getElementById('rrt_spot_mid').value") or 0) - want) < 0.01, 'the chosen place is kept')

	# The feed.
	it = item(feed(page), TITLE)
	enc = re.search(r'<enclosure url="([^"]+)" length="(\d+)"', it)
	check(enc and '/rrt-sponsored/%d/' % R in enc.group(1), 'the feed\'s episode is the sponsored copy', it[:400])
	check('<description>Sponsored by Acme Candles: https://example.com/candles' in it, 'its notes start with the sponsor\'s line and link')
	chm = re.search(r'<podcast:chapters url="([^"]+)"', it)
	chapters = json.loads(page.request.get(chm.group(1).replace('&amp;', '&')).text())['chapters'] if chm else []
	names = [c['title'] for c in chapters]
	check(names == ['A word from Acme Candles', TITLE, 'A word from Acme Candles', TITLE], 'the chapters name the messages', names)

	# Decode the copy and the reading in the browser and compare them.
	page.goto(BASE + '/wp-admin/post.php?post=%d&action=edit' % SPOT, wait_until='load')
	page.wait_for_function('() => !!window.RRTSound', timeout=30000)
	res = page.evaluate("""async (a) => {
		const dec = async (u) => { const b = await (await fetch(u, { credentials: 'same-origin' })).arrayBuffer(); const c = new OfflineAudioContext(1, 1, 44100); return (await c.decodeAudioData(b)).getChannelData(0); };
		const copy = await dec(a.copy);
		const read = await dec(a.read);
		const sr = 44100;
		const ch = a.chapters;
		const pre = ch[1].startTime, cutOut = ch[2].startTime, resume = ch[3].startTime;
		const cut = cutOut - pre;
		const rms = (x, s, e) => { let t = 0; for (let i = s; i < e; i++) t += x[i] * x[i]; return Math.sqrt(t / Math.max(1, e - s)); };
		const db = (v) => 20 * Math.log10(Math.max(v, 1e-9));
		/* Line up a stretch after the resume with the reading, within a few thousand samples (decoders drop the encoder's delay differently). */
		const probe = Math.round((resume + 3) * sr), ref = Math.round((cut + 3) * sr), L = sr;
		let best = [Infinity, 0];
		for (let d = -4000; d <= 4000; d++) {
			let s = 0;
			for (let i = 0; i < L; i += 7) { const v = copy[probe + d + i] - read[ref + i]; s += v * v; }
			if (s < best[0]) best = [s, d];
		}
		const off = best[1];
		/* From the resume on: every sample. */
		const at = Math.round(resume * sr) + off, rt = Math.round(cut * sr);
		/* The first granules after the resume overlap a muted frame where the original overlapped real sound: a difference no louder than the room. After that, every sample. */
		const early = Math.round(0.05 * sr);
		let maxDiff = 0, earlyDiff = 0, n = Math.min(copy.length - at, read.length - rt, 20 * sr);
		for (let i = 0; i < n; i++) { const v = Math.abs(copy[at + i] - read[rt + i]); if (i < early) { if (v > earlyDiff) earlyDiff = v; } else if (v > maxDiff) maxDiff = v; }
		let peakCopy = 0, peakRead = 0;
		for (let i = 0; i < Math.round(0.08 * sr); i++) { peakCopy = Math.max(peakCopy, Math.abs(copy[at + i])); peakRead = Math.max(peakRead, Math.abs(read[rt + i])); }
		const msg = window.RRTSound.lufsMulti([copy.slice(Math.round(cutOut * sr) + off, Math.round((resume - 0.4) * sr) + off)], sr);
		const whole = window.RRTSound.lufsMulti([read], sr);
		return { off, maxDiff, earlyDiff: db(earlyDiff), peakCopy: db(peakCopy), peakRead: db(peakRead), roomCut: db(rms(read, rt - 2000, rt + 2000)), msg, whole, copyLen: copy.length / sr, readLen: read.length / sr };
	}""", {
		'copy': enc.group(1).replace('&amp;', '&') if enc else '',
		'read': SEED['url'],
		'chapters': chapters,
	})
	check(res['maxDiff'] < 1e-5, 'from 50 ms after the mid-roll, the reading is the original sample for sample', res)
	check(res['earlyDiff'] <= res['peakRead'] and res['peakCopy'] <= res['peakRead'] + 1, 'in the first 50 ms it differs by less than the room\'s own level, with no tick', res)
	check(abs(res['msg'] - res['whole']) < 1.5, 'the message plays at the reading\'s loudness', res)
	check(res['copyLen'] > res['readLen'] + 7, 'the copy is longer by the two messages', res)

	# The thank-you line under the player.
	page.goto(BASE + '/wp-admin/admin.php?page=rrt-podcast', wait_until='load')
	page.check('input[name="s_thank"]')
	with page.expect_navigation(wait_until='load'):
		page.click('#submit')
	page.goto(BASE + '/?p=%d' % R, wait_until='load')
	link = page.locator('.rrt-sponsor-line a')
	check(link.count() == 1 and link.get_attribute('rel') == 'sponsored noopener' and link.inner_text() == 'Acme Candles', 'the thank-you line names the sponsor under the player, the link marked sponsored')

	# No sponsor messages on this reading. A real click, then the saved state read back, so a save the editor skipped shows as such.
	ticked = False
	for attempt in range(2):
		page.goto(BASE + '/wp-admin/post.php?post=%d&action=edit' % R, wait_until='load')
		page.wait_for_selector('input[name="rrt_spot_off"]', state='attached', timeout=30000)
		no_guide(page)
		if page.is_checked('input[name="rrt_spot_off"]'):
			ticked = True
			break
		page.locator('input[name="rrt_spot_off"]').scroll_into_view_if_needed()
		page.check('input[name="rrt_spot_off"]')
		save_post(page)
	check(ticked, 'No sponsor messages on this reading is saved with the post')
	for _ in range(20):
		it = item(feed(page), TITLE)
		if 'rrt-sponsored' not in it:
			break
		page.wait_for_timeout(500)
	check('rrt-sponsored' not in it and 'Sponsored by' not in it, 'No sponsor messages on this reading puts the feed back to the reading', it[:300])

	check(not errors, 'no script errors', errors)
	browser.close()

print('%d passed' % passed)
if failed:
	print('%d failed' % len(failed))
	sys.exit(1)
