#!/usr/bin/env python3
"""
Full-length video (1.18.0), end to end.

On a reading's page: the Full-length video section shows the reading's length
and the video's size for each quality; the upload kit's description has the
excerpt, the post's address tagged for YouTube or Spotify, and the chapter
times; the captions link is the reading's captions; the thumbnail downloads
as a 1280 by 720 JPEG.

Make the video downloads an MP4 whose picture is 1920 by 1080 H.264 at 30
frames a second with a keyframe every second, whose sound is stereo at 48 kHz,
and whose picture and sound both run the reading plus the 3 s end card; a
caption shows on the picture while a sentence is read and not in the end card.
The video waits in the browser's private storage until the page is left, and
a reload clears it. Cancel stops a video being made and keeps nothing.

The reading is made by tests/wp/video.php: about 16 s, five sentences, three
chapters.

Usage: VD_SEED=<json from tests/wp/video.php> tests/browser/video.py http://localhost:8080
"""
import json, os, struct, sys
from playwright.sync_api import sync_playwright
from signin import sign_in
from mp4box import boxes, find, tracks

BASE = sys.argv[1].rstrip('/') if len(sys.argv) > 1 else 'http://localhost:8080'
CHROME = os.environ.get('CHROME_PATH', '/usr/bin/google-chrome')
SEED = json.load(open(os.environ['VD_SEED']))
POST_ID = SEED['post']
DUR = SEED['duration']
PAGE = BASE + '/wp-admin/admin.php?page=rrt-readings&post=%d' % POST_ID

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
	browser = p.chromium.launch(executable_path=CHROME if os.path.exists(CHROME) else None, args=['--autoplay-policy=no-user-gesture-required'])
	ctx = browser.new_context(viewport={'width': 1280, 'height': 900}, accept_downloads=True)
	page = ctx.new_page()
	errors = []
	page.on('pageerror', lambda e: errors.append(str(e)))
	sign_in(page, BASE)
	page.goto(PAGE, wait_until='load')

	page.wait_for_selector('#rrt-video .rrt-video-make', timeout=20000)
	check(page.locator('#rrt-video h3').inner_text().strip() == 'Full-length video', 'the reading\'s page has a Full-length video section')
	page.wait_for_function("document.querySelector('.rrt-video-note').textContent.indexOf('about') > 0", timeout=20000)
	note = page.locator('.rrt-video-note').inner_text()
	check('0:%02d' % round(DUR) in note, 'the note gives the reading\'s length', note)
	std = page.evaluate("RRTVideo.estimate(%f, 5000000)" % DUR)
	check(('%.1f MB' % (std / 1e6)) in note, 'the note gives the size at Standard', note)
	page.select_option('#rrt-video-quality', 'smaller')
	small = page.locator('.rrt-video-note').inner_text()
	check(('%.1f MB' % (page.evaluate("RRTVideo.estimate(%f, 2500000)" % DUR) / 1e6)) in small and small != note, 'Smaller file gives a smaller size', small)
	opts = page.eval_on_selector_all('#rrt-video-picture option', 'os => os.map(o => o.value)')
	check(opts[0] == 'none' and 'choose' in opts, 'with no featured image or cover art the picture is the look\'s colours, or one chosen', opts)
	check(page.is_checked('#rrt-video-captions'), 'captions on the picture are on by default when the reading has them')

	# The upload kit.
	desc = page.input_value('#rrt-video-desc')
	parts = desc.split('\n\n')
	check(parts[0] == 'A short test reading for the video maker.', 'the description starts with the excerpt', parts[0])
	check('src=youtube' in desc and 'utm_campaign=video' in desc and ('p=%d' % POST_ID in desc or 'video-test' in desc), 'the description links to the post, tagged for YouTube', parts[1] if len(parts) > 1 else desc)
	# The seed's chapters are 5 s apart, too short for YouTube's chapters: none listed, and the page says why.
	check(len(parts) == 2, 'chapters under 10 s long are not listed for YouTube', parts[2:])
	check('too few or too short' in page.locator('.rrt-video-kit').inner_text(), 'the kit says why the chapters are left out')
	page.select_option('#rrt-video-where', 'spotify')
	desc2 = page.input_value('#rrt-video-desc')
	check('src=spotify' in desc2 and 'src=youtube' not in desc2, 'for Spotify the link is tagged for Spotify')
	href = page.get_attribute('#rrt-video-vtt', 'href') or ''
	check(href.endswith('video-test-%d.vtt' % POST_ID), 'the captions link is the reading\'s captions', href)
	with page.expect_download() as dl:
		page.click('#rrt-video-thumb')
	tpath = dl.value.path()
	tb = open(tpath, 'rb').read()
	# The JPEG's size from its start-of-frame marker.
	wh = None
	i = 2
	while i < len(tb) - 9:
		if tb[i] == 0xFF and tb[i + 1] in (0xC0, 0xC2):
			h, w = struct.unpack('>HH', tb[i + 5:i + 9])
			wh = (w, h)
			break
		i += 2 + struct.unpack('>H', tb[i + 2:i + 4])[0] if tb[i] == 0xFF else 1
	check(tb[:2] == b'\xff\xd8' and wh == (1280, 720) and dl.value.suggested_filename.endswith('-thumbnail.jpg'), 'the thumbnail is a 1280 by 720 JPEG', (wh, dl.value.suggested_filename))

	# Making the video.
	with page.expect_download(timeout=240000) as dl:
		page.click('.rrt-video-make')
	vpath = dl.value.path()
	name = dl.value.suggested_filename
	check(name.endswith('-video.mp4'), 'the video downloads as <post>-video.mp4', name)
	page.wait_for_function("document.querySelector('.rrt-video-status').textContent.indexOf('ready') >= 0", timeout=30000)
	b = open(vpath, 'rb').read()
	T = tracks(b)
	vt = [t for t in T if t['handler'] == 'vide']
	st = [t for t in T if t['handler'] == 'soun']
	check(len(vt) == 1 and len(st) == 1, 'one picture track and one sound track', T)
	if vt and st:
		v = vt[0]
		s = st[0]
		want = DUR + 3
		check(v['entry'] == 'avc1' and (v['w'], v['h']) == (1920, 1080), 'the picture is 1920 by 1080 H.264', v)
		check(abs(v['fps'] - 30) < 0.01, 'the picture runs at 30 frames a second', v['fps'])
		check(abs(v['secs'] - want) < 0.1, 'the picture runs the reading plus the end card', (v['secs'], want))
		check(abs(s['secs'] - want) < 0.1 and abs(s['secs'] - v['secs']) < 0.1, 'the sound runs as long as the picture', (s['secs'], v['secs']))
		check(abs(v['keys'] - v['frames'] / 30) <= 1, 'a keyframe every second', (v['keys'], v['frames']))
		check(abs(v['frames'] - want * 30) <= 1, 'every frame is in the file (1.29.1)', (v['frames'], round(want * 30)))
		# 1.30.0: Mediabunny, with the AAC add-on where the browser has no AAC (Chrome on Linux, as here).
		check(s['entry'] == 'mp4a' and s['channels'] == 2 and s['rate'] == 48000, 'the sound is AAC, stereo at 48 kHz (1.30.0: the add-on where the browser has none)', s)
		check(v.get('profile') == 100, 'the picture is H.264 High profile (1.30.0)', v.get('profile'))
		check(not find(b, ['moov', 'trak', 'edts']), 'no track has an edit list, so both start at 0 (1.30.0)')
		check(page.evaluate("RRTClip.aacAddOn") is True, 'the AAC add-on was loaded, since this Chrome has no AAC of its own')
		check(page.locator('.rrt-video-warn').count() == 0, 'no Opus warning')

	# What the picture shows, from the made video in the page: a caption while a sentence is read, none in the end card.
	mid = (SEED['cues'][1]['s'] + SEED['cues'][1]['e']) / 2
	bright = page.evaluate("""async (args) => {
		const v = document.querySelector('.rrt-video-card video');
		if (v.readyState < 1) { await new Promise(r => v.addEventListener('loadedmetadata', r, { once: true })); }
		const c = document.createElement('canvas'); c.width = 1920; c.height = 1080;
		const x = c.getContext('2d');
		const out = [];
		for (const t of args) {
			await new Promise(r => { v.addEventListener('seeked', r, { once: true }); v.currentTime = t; });
			await new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r)));
			x.drawImage(v, 0, 0, 1920, 1080);
			const d = x.getImageData(840, 700, 1000, 140).data;
			let n = 0;
			for (let i = 0; i < d.length; i += 4) { if (d[i] + d[i + 1] + d[i + 2] > 450) { n++; } }
			out.push(n);
		}
		return out;
	}""", [mid, DUR + 2.5])
	check(bright[0] > 800, 'a caption shows while a sentence is read', bright)
	check(bright[1] < bright[0] / 4, 'no caption in the end card', bright)

	files = page.evaluate("async () => { const d = await navigator.storage.getDirectory(); const n = []; for await (const k of d.keys()) { if (k.startsWith('rrt-video-')) n.push(k); } return n; }")
	check(len(files) == 1, 'the video waits in the browser\'s private storage while the page is open', files)

	# Cancel keeps nothing.
	page.click('.rrt-video-make')
	page.wait_for_function("document.querySelector('.rrt-video-cancel') && !document.querySelector('.rrt-video-cancel').hidden", timeout=10000)
	page.wait_for_function("(document.querySelector('.rrt-video-prog') || {}).value > 0.05", timeout=60000)
	page.click('.rrt-video-cancel')
	page.wait_for_function("document.querySelector('.rrt-video-status').textContent.indexOf('Stopped') >= 0", timeout=30000)
	files = page.evaluate("async () => { const d = await navigator.storage.getDirectory(); const n = []; for await (const k of d.keys()) { if (k.startsWith('rrt-video-')) n.push(k); } return n; }")
	check(files == [], 'Cancel stops the video and keeps nothing (the earlier one was cleared when it started)', files)
	check(page.is_enabled('.rrt-video-make'), 'Make the video can be pressed again after Cancel')

	# A reload clears anything left behind.
	page.evaluate("async () => { const d = await navigator.storage.getDirectory(); const h = await d.getFileHandle('rrt-video-left.mp4', { create: true }); const w = await h.createWritable(); await w.write('x'); await w.close(); }")
	page.goto(PAGE, wait_until='load')
	page.wait_for_selector('#rrt-video .rrt-video-make', timeout=20000)
	page.wait_for_timeout(1000)
	files = page.evaluate("async () => { const d = await navigator.storage.getDirectory(); const n = []; for await (const k of d.keys()) { if (k.startsWith('rrt-video-')) n.push(k); } return n; }")
	check(files == [], 'opening the page again clears what an earlier visit left in the browser\'s storage', files)

	# An encoder that hands every piece back one frame late, as Firefox does on Windows:
	# the file still starts both tracks at 0, with no edit list (found live on 1.30.0).
	page.evaluate("""() => {
		const Real = window.VideoEncoder;
		window.VideoEncoder = class extends Real {
			constructor(init) {
				super({ error: init.error, output: (c, m) => {
					const d = new Uint8Array(c.byteLength);
					c.copyTo(d);
					init.output(new EncodedVideoChunk({ type: c.type, timestamp: c.timestamp + 33333, duration: c.duration, data: d }), m);
				} });
			}
		};
	}""")
	with page.expect_download(timeout=240000) as dl:
		page.click('.rrt-video-make')
	b = open(dl.value.path(), 'rb').read()
	page.wait_for_function("document.querySelector('.rrt-video-status').textContent.indexOf('ready') >= 0", timeout=30000)
	vt = [t for t in tracks(b) if t['handler'] == 'vide']
	check(not find(b, ['moov', 'trak', 'edts']), 'pieces handed back a frame late still give no edit list (Firefox on Windows)')
	check(bool(vt) and abs(vt[0]['frames'] - (DUR + 3) * 30) <= 1, 'and every frame is in the file', vt)

	# Safari on iPhone (found live on 1.30.0): flush() can finish a moment before the
	# last piece arrives, and an encoder may skip a frame. The first must still give
	# every frame; the second a finished file with a line saying so.
	def wrap(js):
		page.goto(PAGE, wait_until='load')
		page.wait_for_selector('#rrt-video .rrt-video-make', timeout=20000)
		page.evaluate(js)
		with page.expect_download(timeout=240000) as dl:
			page.click('.rrt-video-make')
		b = open(dl.value.path(), 'rb').read()
		page.wait_for_function("document.querySelector('.rrt-video-status').textContent.indexOf('ready') >= 0", timeout=30000)
		return [t for t in tracks(b) if t['handler'] == 'vide']
	vt = wrap("""() => {
		const Real = window.VideoEncoder;
		window.VideoEncoder = class extends Real {
			constructor(init) {
				let held = null;
				super({ error: init.error, output: (c, m) => { if (held) { init.output(held[0], held[1]); } held = [c, m]; } });
				this._out = init.output;
				this._held = () => { const h = held; held = null; return h; };
			}
			flush() {
				return super.flush().then(() => {
					const h = this._held();
					if (h) { setTimeout(() => this._out(h[0], h[1]), 300); }
				});
			}
		};
	}""")
	check(bool(vt) and abs(vt[0]['frames'] - (DUR + 3) * 30) <= 1, 'a last piece that arrives after flush() is still waited for (Safari on iPhone)', vt)
	check(page.locator('.rrt-video-card .rrt-small', has_text='skipped').count() == 0, 'and no skipped-frame line')
	want = round((DUR + 3) * 30)
	vt = wrap("""() => {
		const Real = window.VideoEncoder;
		window.VideoEncoder = class extends Real {
			constructor(init) {
				let n = 0;
				super({ error: init.error, output: (c, m) => { n++; if (n !== 100) { init.output(c, m); } } });
			}
		};
	}""")
	check(bool(vt) and abs(vt[0]['frames'] - (want - 1)) <= 0, 'an encoder that skips one frame in a long video still gives a finished file', (vt, want))
	check(page.locator('.rrt-video-card .rrt-small', has_text='skipped 1 of').count() == 1, 'and the card says one frame was skipped')

	# 1.31.0: on a phone the card has no player for the full video, only where to find the file.
	wrap("() => { window.RRTCan.phone = function () { return true; }; }")
	check(page.locator('.rrt-video-card video').count() == 0, 'on a phone the full video\'s card has no player (Safari would not preview a long video)')
	check(page.locator('.rrt-video-card .rrt-video-phone', has_text='Files, Downloads').count() == 1 and page.locator('.rrt-video-card .rrt-video-again').count() == 1, 'it says where the file is, and Download again stays')

	check(not errors, 'no script errors on the page', errors)
	browser.close()

print('%d passed, %d failed' % (passed, len(failed)))
sys.exit(1 if failed else 0)
