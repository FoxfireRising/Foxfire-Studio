"""
Accessibility of the public player and the admin screens (1.26.0).

1. axe-core, the checker behind most browsers' accessibility tools, run on
   the player and on every Foxfire Studio admin screen against WCAG 2.0,
   2.1 and 2.2 A and AA. Every rule must report nothing. axe-core is a test
   tool only, fetched from npm and checked against its fingerprint; it is
   never part of the plugin.
2. The player from the keyboard: the buttons are named by their own words,
   the speed is read out, the Transcript button opens lines that play from
   the keyboard, read along's fallback list is made of buttons, focus shows
   a clear ring, the progress bar is 24 px to touch, and the panel is solid.
3. Settings' Readability check: colours that fall short are listed, and
   Make it readable makes them pass without touching the backgrounds.

Usage: A11Y_SEED=<json from tests/wp/a11y.php> python3 tests/browser/a11y.py [http://localhost:8080]
"""
import json
import os
import sys

from playwright.sync_api import sync_playwright
from signin import sign_in
from take_assets import npm_file

BASE = (sys.argv[1] if len(sys.argv) > 1 else os.environ.get('WP_URL', 'http://localhost:8080')).rstrip('/')
CHROME = os.environ.get('CHROME_PATH', '/usr/bin/google-chrome')
SEED = json.load(open(os.environ['A11Y_SEED']))
AXE_VERSION = '4.14.1'
AXE_SHA = '29348999450cdf2849a8e70b7912fc2da85a849da39c0d0d5394f51d4a9ed95c'
AXE = npm_file('axe-core', AXE_VERSION, 'axe.min.js', 'axe-core', sha=AXE_SHA).decode('utf8')
TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']

# The admin screens, each checked inside WordPress's own content area.
SCREENS = [
	'admin.php?page=rrt-dashboard',
	'admin.php?page=rrt-readings',
	'admin.php?page=rrt-readings&post=%d' % SEED['follow'],
	'admin.php?page=rrt-backlog',
	'admin.php?page=rrt-pronounce',
	'admin.php?page=rrt-progress',
	'admin.php?page=rrt-insights',
	'admin.php?page=rrt-recordings',
	'admin.php?page=rrt-reports',
	'admin.php?page=rrt-podcast',
	'admin.php?page=rrt-shares',
	'options-general.php?page=rrt-settings',
	'post.php?post=%d&action=edit' % SEED['follow'],
]
# Samples that show colours as they are, on purpose: the look preview and the Readability table's samples.
EXCLUDE = ['.rrt-look-preview', '.rrt-readable-sample']

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


def axe(page, include):
	page.add_script_tag(content=AXE)
	return page.evaluate(
		'''(a) => axe.run({ include: a.include, exclude: a.exclude }, { runOnly: { type: 'tag', values: a.tags }, resultTypes: ['violations'] })
			.then(r => r.violations.map(v => v.id + ' x' + v.nodes.length + ' (' + v.nodes.slice(0, 3).map(n => n.target.join(' ')).join(', ') + ')'))''',
		{'include': include, 'exclude': [[e] for e in EXCLUDE], 'tags': TAGS},
	)


def ratio(page, fg, bg):
	return page.evaluate('''(c) => {
		function lum(s) {
			const m = s.match(/[0-9.]+/g).slice(0, 3).map(Number).map(v => v / 255).map(v => v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4));
			return 0.2126 * m[0] + 0.7152 * m[1] + 0.0722 * m[2];
		}
		const a = lum(c[0]); const b = lum(c[1]);
		return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
	}''', [fg, bg])


with sync_playwright() as p:
	browser = p.chromium.launch(executable_path=CHROME)
	visitor = browser.new_context(viewport={'width': 1280, 'height': 900})
	page = visitor.new_page()
	errors = []
	page.on('pageerror', lambda e: errors.append(str(e)))
	page.add_init_script('''window.__rrt = []; document.addEventListener('rrt:player', e => window.__rrt.push(e.detail));''')

	# ---- The player, as a visitor --------------------------------------------
	page.goto(BASE + '/?p=%d' % SEED['follow'], wait_until='load')
	page.wait_for_selector('.rrt-player.is-ready')
	page.wait_for_timeout(1500)
	found = axe(page, [['.rrt-player']])
	check(found == [], 'axe finds nothing in the player', found)

	names = page.evaluate('''() => ['.rrt-back', '.rrt-fwd', '.rrt-speed', '.rrt-transcript-btn'].map(s => {
		const b = document.querySelector('.rrt-player ' + s);
		return b ? [b.textContent.trim(), b.getAttribute('aria-label')] : null;
	})''')
	check(names[0] == ['Back 15s', None], 'the back button says Back 15s and is named by those words', names[0])
	check(names[1] == ['Ahead 30s', None], 'the ahead button says Ahead 30s and is named by those words', names[1])
	check(names[2] == ['Speed 1×', None], 'the speed button says Speed 1× and is named by those words', names[2])
	check(names[3] is not None, 'a reading with captions has a Transcript button')

	bg = page.eval_on_selector('.rrt-player', 'e => getComputedStyle(e).backgroundColor')
	check(bg.startswith('rgb(') and 'rgba' not in bg, 'the player panel is solid, so the page behind never changes its colours', bg)
	label = page.eval_on_selector('.rrt-label', 'e => getComputedStyle(e).color')
	check(ratio(page, label, bg) >= 4.5, 'the label reaches 4.5 to 1 on the panel on a light theme', round(ratio(page, label, bg), 2))
	now = page.eval_on_selector('.rrt-now', 'e => getComputedStyle(e).color')
	check(ratio(page, now, bg) >= 4.5, 'the time reaches 4.5 to 1 on the panel', round(ratio(page, now, bg), 2))
	hit = page.eval_on_selector('.rrt-track', 'e => { const b = getComputedStyle(e, "::before"); return e.getBoundingClientRect().height - parseFloat(b.top) - parseFloat(b.bottom); }')
	check(hit >= 24, 'the progress bar is at least 24 px to touch', hit)

	# Tab from the play button: every control, in order, with a clear ring.
	page.focus('.rrt-play')
	page.keyboard.press('Shift+Tab')
	page.keyboard.press('Tab')
	order = []
	rings = []
	for i in range(8):
		info = page.evaluate('''() => { const e = document.activeElement; const c = getComputedStyle(e); return [e.className, c.outlineStyle, parseFloat(c.outlineWidth)]; }''')
		if 'rrt-' not in str(info[0]):
			break
		order.append(info[0].split(' ')[-1])
		rings.append(info[1] == 'solid' and info[2] >= 2)
		page.keyboard.press('Tab')
	check(order[:7] == ['rrt-play', 'rrt-track', 'rrt-back', 'rrt-fwd', 'rrt-speed', 'rrt-follow', 'rrt-transcript-btn'], 'Tab goes through the player in order', order)
	check(len(rings) >= 7 and all(rings), 'every focused control shows a 2 px ring', list(zip(order, rings)))

	# The speed, read out.
	page.focus('.rrt-speed')
	page.keyboard.press('Enter')
	page.wait_for_timeout(300)
	check(page.eval_on_selector('.rrt-speed', 'e => e.textContent') == 'Speed 1.25×', 'Enter on the speed button changes it to Speed 1.25×')
	check(page.eval_on_selector('.rrt-say', 'e => e.textContent') == 'Speed 1.25×', 'the new speed is read out by the quiet line')
	for i in range(5):
		page.keyboard.press('Enter')
	page.wait_for_timeout(200)
	check(page.eval_on_selector('.rrt-speed', 'e => e.textContent') == 'Speed 1×', 'the speed goes round back to Speed 1×')

	# The Transcript button, from the keyboard.
	page.focus('.rrt-transcript-btn')
	page.keyboard.press('Enter')
	page.wait_for_selector('.rrt-transcript:not([hidden]) button')
	check(page.eval_on_selector('.rrt-transcript-btn', 'e => e.getAttribute("aria-expanded")') == 'true', 'the Transcript button says it is open')
	lines = page.eval_on_selector_all('.rrt-transcript button', 'bs => bs.map(b => [b.textContent.trim(), b.getAttribute("aria-label")])')
	check(len(lines) == 3, 'the transcript lists the three lines', lines)
	check(lines[1][0].startswith('0:05 and the river'), 'each line shows its time and words', lines[1])
	check(lines[1][1] == 'Play from 0:05: and the river carried its light away', 'each line is named by its time and words', lines[1])
	for i in range(4):
		page.keyboard.press('Tab')
		if page.evaluate('document.activeElement.className') == 'rrt-line':
			break
	check(page.evaluate('document.activeElement.textContent').startswith('0:00 The moon'), 'Tab reaches the first transcript line')
	page.keyboard.press('Tab')
	page.keyboard.press('Enter')
	page.wait_for_timeout(800)
	seek = [e for e in page.evaluate('window.__rrt') if e['type'] == 'seek']
	check(seek and seek[-1]['how'] == 'transcript' and abs(seek[-1]['to'] - 5) < 0.2, 'Enter on a line plays from its moment', seek[-1:] if seek else seek)
	page.evaluate('document.querySelector(".rrt-audio").pause()')
	# The test server cannot seek an audio file, so the marking is checked at a set time.
	page.evaluate('document.querySelector(".rrt-player").rrtPlayer.follow(5.5)')
	current = page.eval_on_selector_all('.rrt-transcript button[aria-current]', 'bs => bs.map(b => b.textContent.trim())')
	check(len(current) == 1 and 'and the river' in current[0], 'the line being read is marked as current', current)
	axe_open = axe(page, [['.rrt-player']])
	check(axe_open == [], 'axe finds nothing with the transcript open', axe_open)
	page.focus('.rrt-transcript-btn')
	page.keyboard.press('Enter')
	page.wait_for_timeout(200)
	check(page.eval_on_selector('.rrt-transcript', 'e => e.hidden') is True, 'pressing Transcript again closes the list')

	# Read along keeps the post's own colours.
	page.click('.rrt-follow')
	page.wait_for_selector('.rrt-follow-root.is-following')
	page.evaluate('''() => { const a = document.querySelector('.rrt-audio'); a.currentTime = 6; a.dispatchEvent(new Event('timeupdate')); }''')
	page.wait_for_timeout(300)
	dims = page.evaluate('''() => [...document.querySelectorAll('.rrt-fw')].filter(s => parseFloat(getComputedStyle(s).opacity) < 1).length''')
	check(dims == 0, 'read along dims no words of the post', dims)
	cue = page.evaluate('''() => { const s = document.querySelector('.rrt-fw.rrt-cue'); if (!s) { return null; } const c = getComputedStyle(s); const p = getComputedStyle(s.parentElement); return [c.color === p.color, c.backgroundColor]; }''')
	check(cue is not None and cue[0], 'the sentence being read keeps the post\'s text colour', cue)
	underline = page.evaluate('''() => { const s = document.querySelector('.rrt-fw.rrt-now'); return s ? getComputedStyle(s).textDecorationLine : null; }''')
	check(underline == 'underline', 'the word being read is underlined', underline)
	page.click('.rrt-follow')

	# Read along's fallback list is made of buttons too.
	page.goto(BASE + '/?p=%d' % SEED['listed'], wait_until='load')
	page.wait_for_selector('.rrt-player.is-ready')
	page.click('.rrt-follow')
	page.wait_for_selector('.rrt-transcript:not([hidden]) button')
	check(page.eval_on_selector_all('.rrt-transcript li', 'ls => ls.every(l => l.querySelector("button"))'), 'when read along cannot follow the post, its list lines are buttons')
	check(page.eval_on_selector('.rrt-transcript-btn', 'e => e.getAttribute("aria-expanded")') == 'true', 'the Transcript button shows the list is open while read along uses it')
	page.click('.rrt-follow')
	page.wait_for_timeout(200)
	check(page.eval_on_selector('.rrt-transcript', 'e => e.hidden') is True, 'turning read along off closes its list')
	check(errors == [], 'no script errors on the player', errors)
	visitor.close()

	# ---- The admin screens, signed in ----------------------------------------
	ctx = browser.new_context(viewport={'width': 1280, 'height': 900})
	admin = ctx.new_page()
	sign_in(admin, BASE)
	for s in SCREENS:
		admin.goto(BASE + '/wp-admin/' + s, wait_until='load')
		admin.wait_for_timeout(800)
		found = axe(admin, [['#wpbody-content']])
		check(found == [], 'axe finds nothing on ' + s, found)

	box = admin.evaluate('''() => [...document.querySelectorAll('#rrt-reading .rrt-box-pick, #rrt-reading .rrt-box-clear')].map(b => b.getAttribute('aria-label'))''')
	check(box == ['Choose the reading (MP3)', 'Remove the reading', 'Choose the captions (.vtt)', 'Remove the captions'], 'the Reading audio box\'s buttons say which file they are for', box)

	# Readability in Settings: a failing set of colours, then Make it readable.
	admin.goto(BASE + '/wp-admin/options-general.php?page=rrt-settings', wait_until='load')
	check(admin.eval_on_selector('[data-rrt-readable]', 'e => e.getAttribute("data-rrt-readable")') == 'pass', 'the default colours all read easily')
	admin.evaluate('''() => {
		const set = (k, v) => { const i = document.getElementById('rrt-color-' + k); i.value = v; };
		set('bg', '#fbf8f2'); set('surface', '#f6f2ea'); set('panel', '#f4f1ea'); set('muted', '#b9b2a6'); set('faint', '#cfc9bd'); set('accent', '#e0b04f');
	}''')
	with admin.expect_navigation():
		admin.click('#submit')
	check(admin.eval_on_selector('[data-rrt-readable]', 'e => e.getAttribute("data-rrt-readable")') == 'fail', 'a light panel with pale words is flagged')
	bad = admin.eval_on_selector_all('#rrt-readable tr[data-ok="0"]', 'rs => rs.map(r => r.getAttribute("data-pair"))')
	check('muted-panel' in bad and 'faint-panel' in bad and 'accent-panel' in bad, 'the failing pairs are named', bad)
	with admin.expect_navigation():
		admin.click('#rrt-readable button[type=submit]')
	check(admin.eval_on_selector('[data-rrt-readable]', 'e => e.getAttribute("data-rrt-readable")') == 'pass', 'Make it readable makes every pair pass')
	check(admin.eval_on_selector('#rrt-color-panel', 'e => e.value') == '#f4f1ea', 'Make it readable leaves the panel as it was')
	ctx.close()
	browser.close()

print('%d passed, %d failed' % (passed, len(failed)))
if failed:
	for f in failed:
		print('  - ' + f)
	sys.exit(1)
