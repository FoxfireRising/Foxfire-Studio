"""
YouTube and SoundCloud players that load on play (1.35.0), in the browser.

The seed (tests/wp/embeds.php) makes one post with a reading, a YouTube
video inside an Embed block's figure, a Short, a SoundCloud track and a
SoundCloud playlist, and a Vimeo player, with both switches on. Every
request to YouTube or SoundCloud is counted, and their players are answered
by small stand-in pages that record the messages they get and can send the
ones the real players send.

1. A visitor: four panels and no request to either service, even after
   scrolling the whole page; each panel is a named button reached with Tab;
   Enter loads the privacy-enhanced player in the same box, started, with
   its start time and the referrer kept, and focus moves into it; Space and
   a click do the same for the others; the Short is 9 by 16 and the
   playlist 450 px tall.
2. One at a time: a panel's player pauses the reading player as it loads
   and when it says it is playing; the reading player pauses the panels'
   players; one panel's player pauses another's.
3. axe-core finds nothing in the panels; without JavaScript each is a link
   to the video or track; at 393 px nothing is wider than the screen.
4. Settings, Embedded players switched off: the ordinary players are back.

Usage: EM_SEED=<json from tests/wp/embeds.php> python3 tests/browser/embeds.py [http://localhost:8080]
"""
import json
import os
import sys
from urllib.parse import urlparse, parse_qs

from playwright.sync_api import sync_playwright
from signin import sign_in
from take_assets import npm_file

BASE = (sys.argv[1] if len(sys.argv) > 1 else os.environ.get('WP_URL', 'http://localhost:8080')).rstrip('/')
CHROME = os.environ.get('CHROME_PATH', '/usr/bin/google-chrome')
SEED = json.load(open(os.environ['EM_SEED']))
URL = SEED['url']
AXE = npm_file('axe-core', '4.14.1', 'axe.min.js', 'axe-core', sha='29348999450cdf2849a8e70b7912fc2da85a849da39c0d0d5394f51d4a9ed95c').decode('utf8')
TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']

SERVICES = ('youtube.com', 'youtube-nocookie.com', 'ytimg.com', 'googlevideo.com', 'ggpht.com', 'soundcloud.com', 'sndcdn.com')

STUB = """<!doctype html><html><head><meta charset="utf-8"><title>Stand-in player</title></head>
<body style="margin:0;background:#222;color:#eee">Stand-in player
<script>
window.got = [];
window.addEventListener('message', function (e) { window.got.push(typeof e.data === 'string' ? e.data : JSON.stringify(e.data)); });
window.say = function (o) { parent.postMessage(JSON.stringify(o), '*'); };
</script></body></html>"""

passed = 0
failed = []


def check(ok, name, detail=''):
	global passed
	if ok:
		passed += 1
		print('ok   ' + name)
	else:
		failed.append(name)
		print('FAIL ' + name + ((': ' + str(detail)) if detail != '' else ''))


def service_host(url):
	h = (urlparse(url).hostname or '').lower()
	for s in SERVICES:
		if h == s or h.endswith('.' + s):
			return True
	return False


def watch(ctx, seen):
	"""Counts every request to either service; answers their players with the stand-in, refuses the rest."""
	def handle(route):
		url = route.request.url
		if service_host(url):
			seen.append(url)
			h = urlparse(url).hostname
			if h in ('www.youtube-nocookie.com', 'w.soundcloud.com'):
				route.fulfill(status=200, content_type='text/html', body=STUB)
				return
			route.abort()
			return
		if 'vimeo.com' in url:
			route.abort()
			return
		route.continue_()
	ctx.route('**/*', handle)


def open_post(page):
	page.goto(URL, wait_until='load')
	page.wait_for_selector('.rrt-embed[data-rrt-ready="1"]', timeout=20000)
	page.wait_for_timeout(300)


def box(page, sel):
	return page.evaluate("(s) => { const r = document.querySelector(s).getBoundingClientRect(); return [Math.round(r.width), Math.round(r.height)]; }", sel)


def frame_for(page, host):
	for f in page.frames:
		if (urlparse(f.url).hostname or '') == host:
			return f
	return None


def got(frame):
	try:
		return frame.evaluate('window.got || []')
	except Exception:
		return []


def wait_msg(frame, part, ms=5000):
	for _ in range(ms // 100):
		if any(part in g for g in got(frame)):
			return True
		frame.page.wait_for_timeout(100)
	return False


def reading_paused(page):
	return page.evaluate("() => { const p = document.querySelector('.rrt-player.is-ready'); return p ? p.rrtPlayer.audio.paused : null; }")


def play_reading(page):
	page.evaluate("() => document.querySelector('.rrt-player.is-ready').rrtPlayer.play()")
	for _ in range(50):
		if reading_paused(page) is False:
			return True
		page.wait_for_timeout(100)
	return False


def press(page, sel):
	"""Clicks a panel once it has stopped moving (the theme scrolls smoothly)."""
	page.evaluate("(s) => document.querySelector(s).scrollIntoView({ block: 'center', behavior: 'instant' })", sel)
	page.wait_for_timeout(400)
	page.click(sel + ' .rrt-embed-go')


def tab_to(page, sel, most=80):
	for _ in range(most):
		page.keyboard.press('Tab')
		if page.evaluate("(s) => document.activeElement === document.querySelector(s)", sel):
			return True
	return False


YT = '.rrt-embed-yt.is-wide'
SHORT = '.rrt-embed-yt.is-tall'
SC = '.rrt-embed-sc.is-wide'
SCP = '.rrt-embed-sc.is-tall'

with sync_playwright() as pw:
	browser = pw.chromium.launch(executable_path=CHROME, args=['--autoplay-policy=no-user-gesture-required'])

	# 1 and 2: a visitor on a computer.
	seen = []
	ctx = browser.new_context(viewport={'width': 1280, 'height': 900})
	watch(ctx, seen)
	page = ctx.new_page()
	ERRS = []
	page.on('pageerror', lambda e: ERRS.append(str(e)))
	page.on('console', lambda m: ERRS.append(m.text) if m.type == 'error' else None)
	open_post(page)
	page.evaluate("() => new Promise((done) => { let y = 0; const t = setInterval(() => { y += 400; window.scrollTo(0, y); if (y > document.body.scrollHeight) { clearInterval(t); window.scrollTo(0, 0); done(); } }, 60); })")
	page.wait_for_timeout(800)
	check(page.locator('.rrt-embed').count() == 4, 'the page shows four panels', page.locator('.rrt-embed').count())
	check(not seen, 'nothing is asked of YouTube or SoundCloud, even after scrolling the whole page', seen[:3])
	check(page.locator('iframe').count() == 1 and page.locator('iframe[src*="vimeo"]').count() == 1, 'the only player left in the page is the other service’s')
	names = page.evaluate("() => Array.from(document.querySelectorAll('.rrt-embed button.rrt-embed-go')).map((b) => b.textContent.replace(/\\s+/g, ' ').trim())")
	check(names == ['Play A made-up video, Plays from YouTube', 'Play A made-up Short, Plays from YouTube', 'Play A made-up track, Plays from SoundCloud', 'Play A made-up playlist, Plays from SoundCloud'], 'each panel is a button named for what it plays and where from', names)
	check(page.evaluate("() => Array.from(document.querySelectorAll('.rrt-embed .rrt-embed-pic')).every((i) => i.src.indexOf('/uploads/rrt-embeds/') > 0)"), 'every picture comes from this site')
	check(page.locator('.rrt-player.is-ready').count() == 1, 'the reading player is on the page too')

	before = {k: box(page, k) for k in (YT, SHORT, SC, SCP)}
	check(abs(before[YT][0] / max(1, before[YT][1]) - 16 / 9) < 0.03, 'the video’s panel is 16 by 9', before[YT])
	check(abs(before[SHORT][0] / max(1, before[SHORT][1]) - 9 / 16) < 0.03 and before[SHORT][0] <= 316, 'the Short’s panel is 9 by 16', before[SHORT])
	check(before[SC][1] == 166 and before[SCP][1] == 450, 'the track’s panel is 166 px tall and the playlist’s 450 px', [before[SC], before[SCP]])

	# A theme's rule for pictures in a post's text (two classes and img, as real themes write them) must not change the pictures' size.
	fits = page.evaluate("""() => {
		const st = document.createElement('style');
		st.id = 'em-theme-img';
		st.textContent = 'body :is(.rrt-embed, .em-a) :is(.rrt-embed-go, .em-b) img { height: auto; width: auto; max-width: 100%; margin: 1em 0; border: 3px solid red; }';
		document.head.appendChild(st);
		const out = Array.from(document.querySelectorAll('.rrt-embed')).map((p) => {
			const i = p.querySelector('img.rrt-embed-pic');
			if (!i) { return true; }
			const b = p.getBoundingClientRect(), r = i.getBoundingClientRect();
			const wide = p.classList.contains('rrt-embed-sc') && p.classList.contains('is-wide');
			return Math.abs(r.top - b.top) <= 1 && Math.abs(r.height - b.height) <= 1 && (wide || Math.abs(r.width - b.width) <= 1);
		});
		st.remove();
		return out;
	}""")
	check(all(fits), 'a theme’s rule for pictures in a post cannot change a panel’s picture', fits)

	# Keyboard: Tab to the video, see the focus ring, press Enter.
	play_reading(page)
	check(tab_to(page, YT + ' button.rrt-embed-go'), 'Tab reaches the video’s play button')
	ring = page.evaluate("(s) => getComputedStyle(document.querySelector(s)).outlineStyle", YT + ' button.rrt-embed-go')
	check(ring not in ('none', ''), 'which shows the focus ring', ring)
	page.keyboard.press('Enter')
	page.wait_for_selector(YT + ' iframe.rrt-embed-frame', timeout=5000)
	f = page.locator(YT + ' iframe.rrt-embed-frame')
	src = f.get_attribute('src') or ''
	q = parse_qs(urlparse(src).query)
	check(src.startswith('https://www.youtube-nocookie.com/embed/FoxTestVid1?'), 'Enter loads the privacy-enhanced player for the video', src)
	check(q.get('autoplay') == ['1'] and q.get('start') == ['12'] and q.get('enablejsapi') == ['1'] and q.get('origin') == [BASE], 'started at once, at the video’s own start time, listening to this page', src)
	check(f.get_attribute('referrerpolicy') == 'strict-origin-when-cross-origin' and f.get_attribute('allowfullscreen') is not None and 'autoplay' in (f.get_attribute('allow') or '') and f.get_attribute('title') == 'A made-up video', 'with the referrer YouTube needs, full screen, autoplay allowed and its title')
	check(page.evaluate("(s) => document.activeElement === document.querySelector(s)", YT + ' iframe.rrt-embed-frame'), 'and focus moves into the player')
	after = box(page, YT)
	check(abs(after[0] - before[YT][0]) <= 1 and abs(after[1] - before[YT][1]) <= 1 and box(page, YT + ' iframe') == after, 'the player takes exactly the panel’s box, so nothing on the page moves', [before[YT], after])
	check(len(seen) == 1 and 'youtube-nocookie.com' in seen[0], 'and only now is YouTube asked, once', seen)
	check(reading_paused(page) is True, 'pressing play on the panel paused the reading player')

	ytf = frame_for(page, 'www.youtube-nocookie.com')
	check(ytf is not None and wait_msg(ytf, '"listening"'), 'the page asks the video’s player to say when it plays')
	play_reading(page)
	check(ytf is not None and wait_msg(ytf, '"pauseVideo"'), 'starting the reading player pauses the video')
	if ytf:
		ytf.evaluate("() => say({ event: 'infoDelivery', info: { playerState: 1 } })")
	page.wait_for_timeout(400)
	check(reading_paused(page) is True, 'the video saying it plays pauses the reading player')

	# Space on the track.
	check(tab_to(page, SC + ' button.rrt-embed-go'), 'Tab reaches the track’s play button')
	n0 = len(got(ytf)) if ytf else 0
	page.keyboard.press('Space')
	page.wait_for_selector(SC + ' iframe.rrt-embed-frame', timeout=5000)
	ssrc = page.locator(SC + ' iframe.rrt-embed-frame').get_attribute('src') or ''
	sq = parse_qs(urlparse(ssrc).query)
	check(ssrc.startswith('https://w.soundcloud.com/player/?url=') and sq.get('url') == ['https://api.soundcloud.com/tracks/1234567'] and sq.get('auto_play') == ['true'] and sq.get('color') == ['#ff5500'], 'Space loads SoundCloud’s player for the track, started, with its colour', ssrc)
	check(box(page, SC) == before[SC], 'in the same box')
	check(ytf is not None and any('pauseVideo' in g for g in got(ytf)[n0:]), 'loading the track pauses the video')
	scf = frame_for(page, 'w.soundcloud.com')
	check(scf is not None and wait_msg(scf, 'addEventListener'), 'the page asks the track’s player to say when it plays')
	play_reading(page)
	check(scf is not None and wait_msg(scf, '"pause"'), 'starting the reading player pauses the track')
	if scf:
		n1 = len(got(ytf)) if ytf else 0
		scf.evaluate("() => say({ method: 'play' })")
		page.wait_for_timeout(400)
		check(reading_paused(page) is True, 'the track saying it plays pauses the reading player')
		check(ytf is not None and any('pauseVideo' in g for g in got(ytf)[n1:]), 'and the video')

	# A click on the Short and the playlist.
	press(page, SHORT)
	page.wait_for_selector(SHORT + ' iframe.rrt-embed-frame', timeout=5000)
	check((page.locator(SHORT + ' iframe').get_attribute('src') or '').startswith('https://www.youtube-nocookie.com/embed/FoxShortVid?') and box(page, SHORT) == before[SHORT], 'a click plays the Short in its 9 by 16 box')
	press(page, SCP)
	page.wait_for_selector(SCP + ' iframe.rrt-embed-frame', timeout=5000)
	check('playlists%2F7654321' in (page.locator(SCP + ' iframe').get_attribute('src') or '') and box(page, SCP) == before[SCP], 'and the playlist in its 450 px box')
	check(not ERRS, 'the page shows no script errors', ERRS[:3])
	check(page.evaluate("() => window.RRTEmbeds.safeSrc('yt', 'https://evil.example/embed/x') === '' && window.RRTEmbeds.safeSrc('sc', 'https://w.soundcloud.com.evil.example/player/?url=x') === ''"), 'embeds.js loads only the two players’ own addresses')
	ctx.close()

	# 3: axe-core, without JavaScript, and at 393 px.
	ctx = browser.new_context(viewport={'width': 1280, 'height': 900})
	watch(ctx, [])
	page = ctx.new_page()
	open_post(page)
	page.add_script_tag(content=AXE)
	res = page.evaluate("(tags) => axe.run({ include: [['.rrt-embed']] }, { runOnly: { type: 'tag', values: tags } }).then((r) => r.violations.map((v) => v.id + ': ' + v.nodes.length))", TAGS)
	check(res == [], 'axe-core finds nothing in the panels', res)
	ctx.close()

	ctx = browser.new_context(viewport={'width': 1280, 'height': 900}, java_script_enabled=False)
	seen = []
	watch(ctx, seen)
	page = ctx.new_page()
	page.goto(URL, wait_until='load')
	links = page.evaluate("() => Array.from(document.querySelectorAll('.rrt-embed a.rrt-embed-go')).map((a) => a.getAttribute('href'))")
	check(len(links) == 4 and links[0] == 'https://www.youtube.com/watch?v=FoxTestVid1&t=12s' and links[1] == 'https://www.youtube.com/watch?v=FoxShortVid' and links[2].startswith('https://w.soundcloud.com/player/?url='), 'without JavaScript each panel is a link to the video or track', links)
	check(not seen, 'and still nothing is asked of either service', seen[:3])
	ctx.close()

	ctx = browser.new_context(viewport={'width': 393, 'height': 852}, is_mobile=True, has_touch=True, device_scale_factor=3)
	watch(ctx, [])
	page = ctx.new_page()
	open_post(page)
	wide = page.evaluate("() => [document.documentElement.scrollWidth, document.documentElement.clientWidth, Math.max(...Array.from(document.querySelectorAll('.rrt-embed')).map((e) => e.getBoundingClientRect().right))]")
	check(wide[0] <= wide[1] and wide[2] <= wide[1], 'at 393 px no panel is wider than the screen', wide)
	ph = box(page, YT)
	check(abs(ph[0] / max(1, ph[1]) - 16 / 9) < 0.03, 'and the video keeps its shape', ph)
	page.tap(SC + ' .rrt-embed-go')
	page.wait_for_selector(SC + ' iframe.rrt-embed-frame', timeout=5000)
	check(True, 'a tap plays the track on a phone')
	ctx.close()

	# 4: switched off in Settings.
	ctx = browser.new_context(viewport={'width': 1280, 'height': 900})
	watch(ctx, [])
	page = ctx.new_page()
	sign_in(page, BASE)
	page.goto(BASE + '/wp-admin/options-general.php?page=rrt-settings', wait_until='load')
	check(page.locator('#rrt-embeds-settings').count() == 1 and page.is_checked('#rrt-embeds-yt') and page.is_checked('#rrt-embeds-sc'), 'Settings, Embedded players shows both switches on')
	page.uncheck('#rrt-embeds-yt')
	page.uncheck('#rrt-embeds-sc')
	with page.expect_navigation(wait_until='load'):
		page.click('#rrt-embeds-form [name="save_embeds"]')
	check('rrt_embeds=saved' in page.url and not page.is_checked('#rrt-embeds-yt') and not page.is_checked('#rrt-embeds-sc'), 'Save embedded players switches them off')
	page.goto(URL, wait_until='load')
	page.wait_for_timeout(300)
	check(page.locator('.rrt-embed').count() == 0 and page.locator('iframe[src*="youtube.com/embed/FoxTestVid1"]').count() == 1 and page.locator('iframe[src*="w.soundcloud.com/player"]').count() == 2, 'then the ordinary players are back')
	ctx.close()
	browser.close()

print('\n%d passed, %d failed' % (passed, len(failed)))
sys.exit(1 if failed else 0)
