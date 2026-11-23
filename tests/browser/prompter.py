#!/usr/bin/env python3
"""
Prompter view (1.5.0): open and leave it by key and by button, the mirror on
the script, the countdown and the strip, its own Size, Width and Line kept
apart from the normal view's, Shift, Fade and bright text, and the keys for
clickers and foot pedals: Page Down starts and pauses, Page Up goes back a
sentence, B blanks the screen, plus makes the script bigger, and F5 does not
reload the page. A result opening for the operator closes prompter view.

Usage: PV_POST=<post id> tests/browser/prompter.py http://localhost:8080
The post must read: "<p>Leaves fall slowly. Rain taps the glass.</p><p>Owls call softly. The lamp stays lit.</p>"
"""
import json, os, re, sys
from playwright.sync_api import sync_playwright
from signin import sign_in

BASE = sys.argv[1].rstrip('/') if len(sys.argv) > 1 else 'http://localhost:8080'
CHROME = os.environ.get('CHROME_PATH', '/usr/bin/google-chrome')
POST_ID = int(os.environ['PV_POST'])
TP = BASE + '/?teleprompter=1&tp_post=%d' % POST_ID

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


def key(w):
	return re.sub(r'[^a-z0-9]', '', w.lower())


with sync_playwright() as p:
	browser = p.chromium.launch(executable_path=CHROME if os.path.exists(CHROME) else None)
	ctx = browser.new_context(viewport={'width': 1280, 'height': 800})
	page = ctx.new_page()
	errors = []
	page.on('pageerror', lambda e: errors.append(str(e)))

	sign_in(page, BASE)
	page.goto(TP, wait_until='load')
	page.evaluate("""try {
		sessionStorage.setItem('rrtIntroSeen', '1'); localStorage.setItem('rrtIntro', 'off');
		localStorage.removeItem('rrtPrompter');
		localStorage.setItem('rrtTeleprompter', JSON.stringify({ size: 40, cols: 18, line: 30, rec: false, wpm: 140 }));
	} catch (e) {}""")
	page.goto(TP, wait_until='load')
	page.wait_for_timeout(500)
	page.evaluate("window.__stay = 1")

	def state():
		return page.evaluate("""() => {
			var b = document.body, st = document.getElementById('tpStage');
			return {
				on: b.classList.contains('is-prompter'),
				mh: b.classList.contains('pv-mh'), mv: b.classList.contains('pv-mv'),
				blank: b.classList.contains('pv-blank'), bright: b.classList.contains('pv-bright'),
				playing: b.classList.contains('is-playing'),
				size: parseInt(document.getElementById('tpSize').value, 10),
				cols: parseInt(document.getElementById('tpCols').value, 10),
				cssSize: getComputedStyle(document.documentElement).getPropertyValue('--size').trim(),
				stage: getComputedStyle(st).transform,
				count: (function () { var c = document.getElementById('tpCount'); var had = c.classList.contains('is-on'); c.classList.add('is-on'); var t = getComputedStyle(c).transform; if (!had) { c.classList.remove('is-on'); } return t; })(),
				strip: getComputedStyle(document.getElementById('tpPvStrip')).transform,
				stripShown: getComputedStyle(document.getElementById('tpPvStrip')).display !== 'none',
				top: getComputedStyle(document.querySelector('.tp-top')).display,
				mask: st.style.maskImage || st.style.webkitMaskImage || '',
				label: document.getElementById('tpPvOpen').textContent,
				wi: window.RRTStudio.wi,
				normal: JSON.parse(localStorage.getItem('rrtTeleprompter') || '{}'),
				pv: JSON.parse(localStorage.getItem('rrtPrompter') || '{}')
			};
		}""")

	s = state()
	check(not s['on'] and s['stage'] == 'none', 'the studio opens in the normal view', json.dumps(s))

	# Settings chosen before opening.
	page.select_option('#tpPvMirror', 'h')
	page.evaluate("v => { var e = document.getElementById('tpPvShift'); e.value = v; e.dispatchEvent(new Event('input', { bubbles: true })); }", '10')
	s = state()
	check(s['pv'].get('mirror') == 'h' and s['pv'].get('shift') == 10, 'mirror and shift are kept in this browser', json.dumps(s['pv']))
	check(s['stage'] == 'none', 'the mirror waits for prompter view')

	# Open with P.
	page.locator('body').click(position={'x': 5, 'y': 400})
	page.keyboard.press('p')
	page.wait_for_timeout(300)
	s = state()
	check(s['on'] and s['mh'] and not s['mv'], 'P opens prompter view, mirrored left to right', json.dumps(s))
	check(s['stage'].startswith('matrix(-1, 0, 0, 1'), 'the script is mirrored', s['stage'])
	check(s['count'].startswith('matrix(-1, 0, 0, 1'), 'the countdown is mirrored', s['count'])
	check(s['stripShown'] and s['strip'].startswith('matrix(-1, 0, 0, 1'), 'the strip shows, mirrored', s['strip'])
	check(s['top'] == 'none', 'the menus are hidden')
	check(s['size'] == 56 and s['cssSize'] == '56px' and s['cols'] == 16, 'prompter view has its own size and width', json.dumps(s))
	check(s['label'] == 'Leave prompter view', 'the panel button now leaves')
	dock_mirror = page.evaluate("getComputedStyle(document.querySelector('.tp-dock')).transform")
	check(dock_mirror == 'none', 'the dock is never mirrored', dock_mirror)
	shift = page.evaluate("getComputedStyle(document.documentElement).getPropertyValue('--pvShift').trim()")
	check(shift == '10%', 'Shift moves the script', shift)

	# Size with the keys, kept for prompter view only.
	page.keyboard.press('+')
	page.wait_for_timeout(150)
	s = state()
	check(s['size'] == 60 and s['pv'].get('size') == 60, 'plus makes the script bigger in prompter view', json.dumps(s['pv']))
	check(s['normal'].get('size') == 40, 'the normal view keeps its own size while prompter view is open', json.dumps(s['normal']))

	# Fade and bright text.
	page.evaluate("v => { var e = document.getElementById('tpPvFade'); e.value = v; e.dispatchEvent(new Event('input', { bubbles: true })); }", '20')
	page.evaluate("() => document.getElementById('tpPvBright').click()")
	s = state()
	check(re.search(r'(#000|rgb\(0, 0, 0\)) 0%', s['mask']) and re.search(r'(#000|rgb\(0, 0, 0\)) 60%', s['mask']), 'Fade keeps a band around the reading line', s['mask'])
	check(s['bright'], 'bright text on black switches on')
	white = page.evaluate("getComputedStyle(document.body).getPropertyValue('--text').trim()")
	check(white == '#fff', 'bright text is white', white)

	# Clicker keys.
	words = page.evaluate("Array.prototype.map.call(document.querySelectorAll('.tp-w'), function (e) { return e.textContent; })")
	rain = next(i for i, w in enumerate(words) if key(w) == 'rain')
	leaves = next(i for i, w in enumerate(words) if key(w) == 'leaves')
	taps = next(i for i, w in enumerate(words) if key(w) == 'taps')
	page.evaluate("i => window.RRTStudio.jumpWord(i)", taps)
	page.keyboard.press('PageUp')
	s = state()
	check(s['wi'] == rain, 'Page Up goes back to the start of the sentence', str(s['wi']))
	page.keyboard.press('PageUp')
	s = state()
	check(s['wi'] == leaves, 'Page Up at a sentence start goes to the one before', str(s['wi']))
	page.keyboard.press('PageDown')
	page.wait_for_timeout(4500)
	s = state()
	check(s['playing'], 'Page Down starts the reading')
	page.keyboard.press('PageDown')
	page.wait_for_timeout(200)
	s = state()
	check(not s['playing'], 'Page Down pauses it')
	page.keyboard.press('b')
	s = state()
	check(s['blank'], 'B blanks the screen')
	vis = page.evaluate("getComputedStyle(document.getElementById('tpStage')).visibility")
	check(vis == 'hidden', 'the script is hidden while blank', vis)
	page.keyboard.press('.')
	s = state()
	check(not s['blank'], 'the full stop brings it back')
	page.keyboard.press('F5')
	page.wait_for_timeout(800)
	check(page.evaluate("window.__stay === 1"), 'F5 does not reload the page in prompter view')

	# Leave, and the normal view comes back as it was.
	page.click('#tpPvLeave', force=True)
	page.wait_for_timeout(300)
	s = state()
	check(not s['on'] and s['stage'] == 'none' and s['top'] != 'none', 'Leave prompter view goes back to the normal view', json.dumps(s))
	check(s['size'] == 40 and s['cssSize'] == '40px' and s['cols'] == 18, 'the normal view keeps its size and width', json.dumps(s))
	check(s['mask'] == '', 'the fade is only in prompter view', s['mask'])
	check(s['normal'].get('size') == 40 and s['pv'].get('size') == 60, 'both views are remembered apart', json.dumps([s['normal'], s['pv']]))

	# The panel button opens it with what was chosen last time.
	page.click('#tpPvOpen')
	page.wait_for_timeout(300)
	s = state()
	check(s['on'] and s['size'] == 60 and s['bright'], 'prompter view opens as it was left', json.dumps(s))

	# A result for the operator closes it.
	page.evaluate("document.getElementById('tpResult').classList.add('is-on')")
	page.wait_for_timeout(900)
	s = state()
	check(not s['on'], 'prompter view steps aside when a result opens')
	page.evaluate("document.getElementById('tpResult').classList.remove('is-on')")

	# Escape leaves too.
	page.locator('body').click(position={'x': 5, 'y': 400})
	page.keyboard.press('p')
	page.wait_for_timeout(300)
	page.keyboard.press('Escape')
	page.wait_for_timeout(300)
	s = state()
	check(not s['on'] and s['size'] == 40, 'Escape leaves prompter view', json.dumps(s))

	# Upside down puts the strip at the bottom.
	page.select_option('#tpPvMirror', 'v')
	page.locator('body').click(position={'x': 5, 'y': 400})
	page.keyboard.press('p')
	page.wait_for_timeout(300)
	s = state()
	bottom = page.evaluate("getComputedStyle(document.getElementById('tpPvStrip')).bottom")
	check(s['mv'] and not s['mh'] and s['stage'].startswith('matrix(1, 0, 0, -1'), 'upside down flips the script', s['stage'])
	check(bottom == '0px', 'the strip moves to the reader\'s top', bottom)
	page.keyboard.press('Escape')

	page.evaluate("localStorage.removeItem('rrtPrompter')")
	check(not errors, 'no script errors', '; '.join(errors))
	browser.close()

print('%d passed, %d failed' % (passed, len(failed)))
sys.exit(1 if failed else 0)
