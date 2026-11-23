"""
Accessibility of the studio (1.27.0).

1. axe-core on the studio with the Controls panel open, during a reading,
   with each kind of dialog open, and on the review of a stand-in take.
   Every rule at WCAG 2.2 A and AA must report nothing.
2. The keys from the keyboard: Space ticks every switch and the arrows move
   every slider without starting the reading or changing the pace; Escape
   from a control goes back to the script, where the teleprompter's keys
   work; Page Down works from anywhere; single-key shortcuts act only on the
   script and can be switched off.
3. While reading, Tab lands on controls shown at full strength.
4. Dialogs take focus on their heading, keep Tab inside, close with Escape
   and give focus back; the cue menu is a dialog of buttons reached with the
   word cursor.
5. The "?" help beside controls, the hidden main heading, section headings,
   the polite line, links that say they open a new tab, and words ahead at
   3 to 1 or more.
6. The review from the keyboard: focus moves to its heading, the script is
   one Tab stop with a word cursor, Enter plays from a word, Shift and the
   arrows select with Edit audio on, Delete cuts, shaded sentences carry a
   text tag, and the timeline is hidden from screen readers.
7. The studio at iPhone width (393 px), with a screenshot.

Usage: A11Y_SEED=<json from tests/wp/a11y.php> python3 tests/browser/a11y_studio.py [http://localhost:8080]
"""
import io
import json
import math
import os
import re
import struct
import sys
import tempfile
import wave

from playwright.sync_api import sync_playwright
from signin import sign_in
from take_assets import MP3, RNN, npm_file
from whisper_stub import PIECE

BASE = (sys.argv[1] if len(sys.argv) > 1 else os.environ.get('WP_URL', 'http://localhost:8080')).rstrip('/')
CHROME = os.environ.get('CHROME_PATH', '/usr/bin/google-chrome')
SEED = json.load(open(os.environ['A11Y_SEED']))
AXE_VERSION = '4.14.1'
AXE_SHA = '29348999450cdf2849a8e70b7912fc2da85a849da39c0d0d5394f51d4a9ed95c'
AXE = npm_file('axe-core', AXE_VERSION, 'axe.min.js', 'axe-core', sha=AXE_SHA).decode('utf8')
TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']
TP = BASE + '/?teleprompter=1&tp_post=%d' % SEED['follow']
SHOTS = os.environ.get('A11Y_SHOTS', tempfile.gettempdir())

passed = 0
failed = []


ALIGN = """() => [...document.querySelectorAll('#tpPanel .tp-sec')].map(sec => {
	// Each row's control (1.36.1): a switch, a slider, a menu or a lone button, but not the pairs that
	// share a row by design (Warm up beside Check microphone, the drill's switch beside its length).
	const ends = [...sec.querySelectorAll('.tp-switch input, .tp-slider input[type=range], .tp-field select, .tp-sec > .tp-btn, .tp-helped > .tp-btn')]
		.filter(i => i.offsetParent && !i.closest('.tp-btn-pair, .tp-drill'))
		.map(i => Math.round(i.getBoundingClientRect().right));
	return [sec.getAttribute('aria-labelledby'), Math.max(...ends) - Math.min(...ends), ends.length];
})"""


def check(ok, name, detail=''):
	global passed
	if ok:
		passed += 1
		print('ok   ' + name)
	else:
		failed.append(name)
		print('FAIL ' + name + ((': ' + str(detail)) if detail != '' else ''))


def axe(page, exclude=None):
	if not page.evaluate('typeof window.axe === "object"'):
		page.add_script_tag(content=AXE)
	return page.evaluate(
		'''(a) => axe.run({ include: [['body']], exclude: a.exclude }, { runOnly: { type: 'tag', values: a.tags }, resultTypes: ['violations'] })
			.then(r => r.violations.map(v => v.id + ' x' + v.nodes.length + ' (' + v.nodes.slice(0, 3).map(n => n.target.join(' ')).join(', ') + ')'))''',
		{'exclude': [[e] for e in (exclude or [])] or [['.tp-none-such']], 'tags': TAGS},
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


def active(page):
	return page.evaluate('''() => { const e = document.activeElement; return e ? (e.id || e.className || e.tagName) : ''; }''')


def plan(words):
	"""What the stand-in hears: the title and the last sentence at an easy pace, the long first sentence slowly."""
	out = []
	t = 0.8
	sent = 0
	for w in words:
		if not re.sub(r'[^a-z0-9]', '', w.lower()):
			continue
		step = 1.05 if sent == 1 else 0.4
		out.append((w, t, t + step * 0.7))
		t += step
		if w.endswith('.') or w == 'hill' and sent == 0:
			sent += 1
	return out, t + 1.0


def wav_for(heard, seconds):
	"""Tone bursts where the words are, over a quiet hush, 44.1 kHz mono."""
	rate = 44100
	n = int(seconds * rate)
	x = [0.0] * n
	seed = 7
	for i in range(n):
		seed = (seed * 1103515245 + 12345) % 2147483648
		x[i] = (seed / 2147483648 - 0.5) * 0.004
	for k, (_, s, e) in enumerate(heard):
		a, b = int(s * rate), int(e * rate)
		f = 170 + 30 * (k % 5)
		for i in range(a, min(b, n)):
			env = min(1.0, (i - a) / 600.0, (b - i) / 600.0)
			x[i] += 0.3 * env * math.sin(2 * math.pi * f * (i - a) / rate)
	buf = io.BytesIO()
	w = wave.open(buf, 'wb')
	w.setnchannels(1)
	w.setsampwidth(2)
	w.setframerate(rate)
	w.writeframes(b''.join(struct.pack('<h', max(-32767, min(32767, int(v * 32767)))) for v in x))
	w.close()
	return buf.getvalue()


def fake_transformers(heard):
	chunks = [{'text': ' ' + w, 'timestamp': [round(s, 3), round(e, 3)]} for (w, s, e) in heard]
	return (
		'export const env = { backends: { onnx: { wasm: {} } } };\n'
		+ PIECE +
		'const CHUNKS = %s;\n'
		'export async function pipeline() {\n'
		'  return async function (audio) { return piece(audio, { chunks: CHUNKS, text: CHUNKS.map(x => x.text).join("") }); };\n'
		'}\n' % json.dumps(chunks)
	)


with sync_playwright() as p:
	browser = p.chromium.launch(executable_path=CHROME if os.path.exists(CHROME) else None, args=['--autoplay-policy=no-user-gesture-required'])
	ctx = browser.new_context(viewport={'width': 1280, 'height': 900})
	page = ctx.new_page()
	errors = []
	page.on('pageerror', lambda e: errors.append(str(e)))
	fake = {'js': ''}
	ctx.route(re.compile(r'.*transformers(\.min)?\.js.*'), lambda r: r.fulfill(status=200, content_type='text/javascript', body=fake['js'], headers={'Access-Control-Allow-Origin': '*'}))
	ctx.route(re.compile(r'.*/mp3\.wasm.*'), lambda r: r.fulfill(status=200, content_type='application/wasm', body=MP3, headers={'Access-Control-Allow-Origin': '*'}))
	ctx.route(re.compile(r'.*/rnnoise\.wasm.*'), lambda r: r.fulfill(status=200, content_type='application/wasm', body=RNN, headers={'Access-Control-Allow-Origin': '*'}))
	sign_in(page, BASE)

	def studio():
		page.goto(TP, wait_until='load')
		page.evaluate("try { sessionStorage.setItem('rrtIntroSeen', '1'); localStorage.setItem('rrtIntro', 'off'); localStorage.removeItem('rrtTeleprompter'); } catch (e) {}")
		page.goto(TP, wait_until='load')
		page.wait_for_selector('.tp-w')
		page.wait_for_timeout(600)
		if page.evaluate("document.body.classList.contains('panel-closed')"):
			page.click('#tpPanelToggle')
			page.wait_for_timeout(400)

	def state():
		return page.evaluate('''() => ({
			wpm: window.RRTStudio.settings.wpm,
			playing: !!window.RRTStudio.playing,
			counting: document.getElementById('tpCount').classList.contains('is-on'),
			wi: window.RRTStudio.wi,
			pv: document.body.classList.contains('is-prompter')
		})''')

	def stop_reading():
		page.wait_for_timeout(3600)
		page.evaluate("() => { if (window.RRTStudio.playing) { window.RRTStudio.togglePlay(); } }")
		page.evaluate("window.RRTStudio.jumpWord(0)")

	studio()
	del errors[:]

	# ---- The "?" column (1.36.1) ----------------------------------------------
	al = page.evaluate(ALIGN)
	check(all(x[1] <= 1 and x[2] >= 3 for x in al), 'every control in each Controls panel section ends at one edge, with or without its "?"', al)

	# ---- Headings, links, the script as a Tab stop ---------------------------
	h1 = page.evaluate("[...document.querySelectorAll('h1')].map(h => [h.textContent.trim(), h.getBoundingClientRect().width <= 1])")
	check(h1 == [['A quiet hill', True]], 'the studio has one main heading, the post title, hidden on screen', h1)
	h2 = page.evaluate("[...document.querySelectorAll('#tpPanel h2')].map(h => h.textContent.trim())")
	check(h2[:2] == ['Reading', 'Prompter view'] and 'Recording' in h2, 'the panel\'s sections are second-level headings', h2)
	check(page.evaluate("document.querySelectorAll('#tpPanel h3').length") == 0, 'no section heading skips a level')
	links = page.evaluate("[...document.querySelectorAll('.tp-nav a')].map(a => a.textContent.replace(/\\s+/g, ' ').trim())")
	check(links and all(l.endswith('(opens in a new tab)') for l in links), 'the menu\'s links say they open a new tab', links)
	hidden_note = page.eval_on_selector('.tp-nav a .tp-vh', 'e => e.getBoundingClientRect().width')
	check(hidden_note <= 1, 'that note is hidden on screen', hidden_note)
	track = page.evaluate("(() => { const t = document.getElementById('tpTrack'); return [t.tabIndex, t.getAttribute('role'), t.getAttribute('aria-label'), t.getAttribute('aria-describedby')]; })()")
	check(track == [0, 'region', 'Script', 'tpKeysHelp'], 'the script is one Tab stop, named and described by the keys', track)

	found = axe(page)
	check(found == [], 'axe finds nothing in the studio with the panel open', found)

	# ---- S1: every control keeps its own keys --------------------------------
	switches = page.evaluate('''() => [...document.querySelectorAll('#tpPanel input[type=checkbox]')].filter(i => i.getClientRects().length && !i.disabled).map(i => i.id)''')
	check(len(switches) >= 8, 'the panel has its switches', switches)
	bad = []
	for sid in switches:
		was = page.evaluate("id => document.getElementById(id).checked", sid)
		page.focus('#' + sid)
		page.keyboard.press('Space')
		page.wait_for_timeout(120)
		s = state()
		now = page.evaluate("id => document.getElementById(id).checked", sid)
		if now == was or s['counting'] or s['playing'] or active(page) != sid:
			bad.append([sid, was, now, s, active(page)])
		# Put it back as it was (Cues and Record my voice change what follows).
		page.keyboard.press('Space')
		page.wait_for_timeout(120)
		if page.evaluate("id => document.getElementById(id).checked", sid) != was:
			bad.append([sid, 'not back'])
	check(bad == [], 'Space ticks every switch in the panel, keeps focus there and never starts the reading', bad)

	page.focus('#tpSize')
	page.keyboard.press('ArrowRight')
	page.wait_for_timeout(100)
	got = page.evaluate("[document.getElementById('tpSize').value, window.RRTStudio.settings.wpm, document.activeElement.id]")
	check(got == ['42', 140, 'tpSize'], 'the right arrow on Size changes Size, leaves Pace and keeps focus', got)
	page.keyboard.press('ArrowLeft')
	for sid in ['tpCols', 'tpLine']:
		was = page.evaluate("id => document.getElementById(id).value", sid)
		page.focus('#' + sid)
		page.keyboard.press('ArrowRight')
		page.wait_for_timeout(80)
		got = page.evaluate("id => [document.getElementById(id).value, window.RRTStudio.settings.wpm, document.activeElement.id]", sid)
		check(got[0] != was and got[1] == 140 and got[2] == sid, 'the arrows move ' + sid + ' and nothing else', got)
		page.keyboard.press('ArrowLeft')

	page.focus('#tpMicCheck')
	page.keyboard.press('Space')
	page.wait_for_selector('.tp-mic')
	page.wait_for_timeout(200)
	check(page.evaluate("document.activeElement.textContent") == 'Microphone check', 'Space on Check microphone opens it, with focus on its heading')
	check(page.eval_on_selector('.tp-mic', 'e => [e.getAttribute("role"), e.getAttribute("aria-modal"), !!document.getElementById(e.getAttribute("aria-labelledby"))]') == ['dialog', 'true', True], 'the check is a modal dialog named by its heading')
	found = axe(page)
	check(found == [], 'axe finds nothing with the microphone check open', found)
	inside = True
	for i in range(5):
		page.keyboard.press('Tab')
		inside = inside and page.evaluate("!!document.activeElement.closest('.tp-mic')")
	page.keyboard.press('Shift+Tab')
	inside = inside and page.evaluate("!!document.activeElement.closest('.tp-mic')")
	check(inside, 'Tab and Shift+Tab stay inside the dialog')
	page.keyboard.press('Escape')
	page.wait_for_timeout(200)
	check(page.evaluate("!document.querySelector('.tp-mic')"), 'Escape closes the microphone check')
	check(active(page) == 'tpMicCheck', 'focus goes back to Check microphone', active(page))

	# Escape from a control goes back to the script, where the teleprompter's keys work.
	page.focus('#tpWpm')
	page.keyboard.press('Escape')
	check(active(page) == 'tpTrack', 'Escape from a panel control goes back to the script', active(page))
	ring = page.evaluate("(() => { const w = document.querySelector('.tp-w.is-now'); const c = getComputedStyle(w); return [c.outlineStyle, parseFloat(c.outlineWidth)]; })()")
	check(ring[0] == 'solid' and ring[1] >= 2, 'the word where the reading starts shows a ring while the script has focus', ring)
	page.keyboard.press('ArrowRight')
	check(state()['wpm'] == 145, 'on the script, the right arrow raises the pace', state())
	page.keyboard.press('ArrowLeft')
	page.keyboard.press('ArrowDown')
	check(state()['wi'] > 0, 'down steps to the next paragraph', state())
	page.keyboard.press('Home')
	check(state()['wi'] == 0, 'Home goes back to the top')

	# Page Down from a switch: a clicker works from anywhere.
	page.focus('#tpBreath')
	page.keyboard.press('PageDown')
	page.wait_for_timeout(300)
	s = state()
	check(s['counting'] or s['playing'], 'Page Down starts the reading even from a switch', s)
	check(page.evaluate("document.getElementById('tpBreath').checked") is True, 'and leaves the switch alone')

	# ---- S2: while reading, focused controls come back to full strength ------
	page.wait_for_timeout(3600)
	check(state()['playing'], 'the reading is running')
	found = axe(page)
	check(found == [], 'axe finds nothing during a reading', found)
	faded = page.evaluate("parseFloat(getComputedStyle(document.getElementById('tpBar')).opacity)")
	check(faded < 0.2, 'the menus fade while reading', faded)
	page.focus('#tpTrack')
	strengths = []
	for i in range(4):
		page.keyboard.press('Tab')
		strengths.append(page.evaluate('''() => {
			const e = document.activeElement;
			let o = 1;
			for (let n = e; n; n = n.parentElement) { o *= parseFloat(getComputedStyle(n).opacity); }
			return [e.id || e.className, Math.round(o * 100) / 100];
		}'''))
	# Wait for the fade back to finish (a slower machine is still mid-fade at 450 ms), up to 2 s.
	try:
		page.wait_for_function('''() => {
			const e = document.activeElement;
			let o = 1;
			for (let n = e; n; n = n.parentElement) { o *= parseFloat(getComputedStyle(n).opacity); }
			return o >= 0.995;
		}''', timeout=2000)
	except Exception:
		pass
	strengths = [[n, page.evaluate('''(id) => {
		const e = document.activeElement;
		let o = 1;
		for (let n = e; n; n = n.parentElement) { o *= parseFloat(getComputedStyle(n).opacity); }
		return Math.round(o * 100) / 100;
	}''', n)] for n, _ in strengths[-1:]]
	check(strengths[0][1] == 1, 'Tab during a reading lands on a control shown at full strength', strengths)
	page.keyboard.press('Escape')
	page.keyboard.press('Space')
	page.wait_for_timeout(200)
	check(not state()['playing'], 'Escape then Space pauses the reading from the keyboard', state())
	page.evaluate("window.RRTStudio.jumpWord(0)")

	# ---- S3: single-key shortcuts -------------------------------------------
	page.focus('#tpBreath')
	page.keyboard.press('p')
	page.wait_for_timeout(200)
	check(not state()['pv'], 'P on a switch does not open prompter view')
	page.focus('#tpTrack')
	page.keyboard.press('p')
	page.wait_for_timeout(300)
	check(state()['pv'], 'P on the script opens prompter view')
	check(active(page) == 'tpTrack', 'focus stays on the script in prompter view', active(page))
	page.keyboard.press('Escape')
	page.wait_for_timeout(300)
	check(not state()['pv'], 'Escape leaves prompter view')
	page.focus('#tpKeys')
	page.keyboard.press('Space')
	stored = page.evaluate("JSON.parse(localStorage.getItem('rrtTeleprompter') || '{}').keys")
	check(stored is False, 'Single-key shortcuts can be switched off, and it is remembered in this browser', stored)
	page.focus('#tpTrack')
	page.keyboard.press('p')
	page.wait_for_timeout(300)
	check(not state()['pv'], 'with them off, P does nothing')
	page.click('#tpPvOpen')
	page.wait_for_timeout(300)
	check(state()['pv'], 'the button still opens prompter view')
	page.keyboard.press('Escape')
	page.wait_for_timeout(300)
	page.focus('#tpKeys')
	page.keyboard.press('Space')

	# ---- S6: "?" help -----------------------------------------------------------
	helps = page.evaluate("document.querySelectorAll('#tpPanel .tp-help-btn').length")
	check(helps >= 12, 'controls that explained themselves only on hover have a ? button', helps)
	clean = page.evaluate('''() => {
		const c = document.getElementById('tpCleanOpt');
		const ids = (c.getAttribute('aria-describedby') || '').split(' ');
		const h = ids.map(i => document.getElementById(i)).find(n => n && n.classList.contains('tp-help-text'));
		const b = h ? document.querySelector('[aria-controls="' + h.id + '"]') : null;
		return h ? [h.textContent, h.hidden, b ? b.getAttribute('aria-label') : null, b ? b.id || b.className : null] : null;
	}''')
	check(clean is not None and clean[0].startswith('Quiets clicks') and clean[1] is True, 'Clean up audio is described by its explanation', clean)
	check(clean is not None and clean[2] == 'About Clean up audio', 'its ? button is named for the control', clean)
	page.evaluate("document.querySelector('[aria-label=\"About Clean up audio\"]').focus()")
	page.keyboard.press('Enter')
	page.wait_for_timeout(100)
	opened = page.evaluate("(() => { const b = document.activeElement; const h = document.getElementById(b.getAttribute('aria-controls')); return [b.getAttribute('aria-expanded'), !h.hidden, h.getBoundingClientRect().height > 10]; })()")
	check(opened == ['true', True, True], 'Enter on ? opens the explanation under the control', opened)
	page.keyboard.press('Escape')
	check(page.evaluate("document.activeElement.getAttribute('aria-expanded')") == 'false', 'Escape folds it away again')
	page.keyboard.press('Enter')
	found = axe(page)
	check(found == [], 'axe finds nothing with an explanation open', found)
	page.keyboard.press('Enter')

	# ---- S10: words ahead ---------------------------------------------------------
	ahead = page.evaluate('''() => {
		const w = document.querySelector('.tp-block.is-on .tp-w:not(.is-said):not(.is-now)') || document.querySelectorAll('.tp-block.is-on .tp-w')[1];
		const c = getComputedStyle(w).color;
		/* color-mix() comes back as color(srgb r g b / a), with each part from 0 to 1. */
		const m = c.match(/[0-9.]+/g).map(Number).map((v, i) => c.indexOf('color(') === 0 && i < 3 ? v * 255 : v);
		const bg = getComputedStyle(document.body).getPropertyValue('--bg').trim();
		const t = document.createElement('i'); t.style.color = bg; document.body.appendChild(t);
		const b = getComputedStyle(t).color.match(/[0-9.]+/g).map(Number); t.remove();
		const a = m.length > 3 ? m[3] : 1;
		return ['rgb(' + [0, 1, 2].map(i => Math.round(m[i] * a + b[i] * (1 - a))).join(', ') + ')', 'rgb(' + b.slice(0, 3).join(', ') + ')'];
	}''')
	r = ratio(page, ahead[0], ahead[1])
	check(r >= 3, 'words ahead of the reading line reach 3 to 1 on the page', round(r, 2))

	# ---- S4 and S5: the cue menu from the word cursor --------------------------
	page.focus('#tpCues')
	page.keyboard.press('Space')
	page.keyboard.press('Escape')
	check(active(page) == 'tpTrack', 'with Cues on, Escape goes to the script')
	page.keyboard.press('ArrowRight')
	page.keyboard.press('ArrowRight')
	s = state()
	check(s['wi'] == 2 and s['wpm'] == 140, 'with Cues on, the right arrow moves a word and leaves the pace', s)
	page.wait_for_timeout(200)
	check(page.evaluate("document.getElementById('tpSay').textContent") == 'hill', 'the word under the cursor is read out', page.evaluate("document.getElementById('tpSay').textContent"))
	page.keyboard.press('Enter')
	page.wait_for_selector('.tp-cue-menu')
	page.wait_for_timeout(150)
	menu = page.eval_on_selector('.tp-cue-menu', 'e => [e.getAttribute("role"), e.getAttribute("aria-label"), e.querySelectorAll("[role=menuitem]").length, !!document.activeElement.closest(".tp-cue-menu")]')
	check(menu == ['dialog', 'Cue for “hill”', 0, True], 'Enter opens the word\'s cue menu, a dialog of buttons with focus inside', menu)
	found = axe(page)
	check(found == [], 'axe finds nothing with the cue menu open', found)
	inside = True
	for i in range(9):
		page.keyboard.press('Tab')
		inside = inside and page.evaluate("!!document.activeElement.closest('.tp-cue-menu')")
	check(inside, 'Tab stays inside the cue menu')
	page.keyboard.press('Escape')
	page.wait_for_timeout(150)
	check(page.evaluate("!document.querySelector('.tp-cue-menu')") and active(page) == 'tpTrack', 'Escape closes it and focus goes back to the script', active(page))
	page.keyboard.press('Enter')
	page.wait_for_selector('.tp-cue-menu')
	page.keyboard.press('Enter')
	page.wait_for_timeout(300)
	check(page.evaluate("document.querySelectorAll('.tp-w')[2].parentElement.querySelector('.tp-cue') !== null || document.querySelector('.tp-cue-pause, .tp-cue-breath') !== null"), 'Enter on Pause after sets the cue')
	page.keyboard.press('Enter')
	page.wait_for_selector('.tp-cue-menu')
	page.evaluate("document.querySelector('.tp-cue-menu [data-cue=\"clear\"]').focus()")
	page.keyboard.press('Enter')
	page.wait_for_timeout(300)
	page.focus('#tpCues')
	page.keyboard.press('Space')
	page.keyboard.press('Escape')
	page.keyboard.press('Home')

	# ---- S4 and S7: the dialogs on the page ---------------------------------------
	page.focus('#tpTrack')
	page.evaluate("document.getElementById('tpResult').classList.add('is-on')")
	page.wait_for_timeout(200)
	check(page.evaluate("document.activeElement.textContent") == 'Your reading is ready', 'the ready dialog takes focus on its heading')
	page.wait_for_timeout(200)
	check(page.evaluate("document.getElementById('tpSay').textContent") == 'Your reading is ready', 'and the polite line reads it out')
	check(page.eval_on_selector('#tpResult', 'e => [e.getAttribute("aria-modal"), document.getElementById(e.getAttribute("aria-labelledby")).textContent]') == ['true', 'Your reading is ready'], 'the ready dialog is modal and named by its heading')
	found = axe(page)
	check(found == [], 'axe finds nothing with the ready dialog open', found)
	before = state()
	page.keyboard.press('Space')
	page.wait_for_timeout(150)
	after = state()
	check(not after['counting'] and not after['playing'], 'Space in the dialog never starts the reading behind it', [before, after, active(page)])
	page.keyboard.press('Escape')
	page.wait_for_timeout(200)
	check(not page.evaluate("document.getElementById('tpResult').classList.contains('is-on')") and active(page) == 'tpTrack', 'Escape closes it (as Close does) and focus goes back to the script', active(page))
	for box, later in [['tpRecover', 'tpRecoverLater'], ['tpCarry', 'tpCarryLater'], ['tpSent', 'tpSentLater']]:
		page.focus('#tpPlay')
		page.evaluate("b => { const e = document.getElementById(b); e.classList.add('is-on'); document.getElementById(b.replace(/^tp/, 'tp') + 'Later').onclick = () => e.classList.remove('is-on'); }", box)
		page.wait_for_timeout(150)
		took = page.evaluate("b => !!document.activeElement.closest('#' + b) && document.activeElement.tagName === 'H2'", box)
		page.keyboard.press('Escape')
		page.wait_for_timeout(150)
		gone = page.evaluate("b => !document.getElementById(b).classList.contains('is-on')", box)
		check(took and gone and active(page) == 'tpPlay', box + ' takes focus, closes with Escape (Not now) and gives focus back', [took, gone, active(page)])

	page.evaluate("document.getElementById('tpRec').classList.add('is-on')")
	page.wait_for_timeout(200)
	check(page.evaluate("document.getElementById('tpSay').textContent") == 'Recording', 'a recording starting is read out')
	page.evaluate("document.getElementById('tpRec').classList.remove('is-on')")
	page.wait_for_timeout(200)
	check(page.evaluate("document.getElementById('tpSay').textContent") == 'Recording stopped', 'and stopping')

	page.focus('#tpWarm')
	page.keyboard.press('Enter')
	page.wait_for_selector('.tp-warm')
	page.wait_for_timeout(200)
	check(page.evaluate("document.activeElement.tagName === 'H2' && !!document.activeElement.closest('.tp-warm')"), 'Warm up opens with focus on its heading')
	found = axe(page)
	check(found == [], 'axe finds nothing with the warm-up open', found)
	page.keyboard.press('Escape')
	page.wait_for_timeout(200)
	check(page.evaluate("!document.querySelector('.tp-warm')") and active(page) == 'tpWarm', 'Escape closes the warm-up and focus goes back to Warm up', active(page))
	check(errors == [], 'no script errors in the studio', errors)

	# ---- The review of a stand-in take ------------------------------------------
	words = page.evaluate("[...document.querySelectorAll('.tp-w')].map(e => e.textContent)")
	heard, seconds = plan(words)
	fake['js'] = fake_transformers(heard)
	page.evaluate("() => { const w = document.getElementById('tpWhisperOpt'); if (!w.checked) { w.click(); } }")
	with tempfile.NamedTemporaryFile(suffix='.wav', delete=False) as f:
		f.write(wav_for(heard, seconds))
		wav = f.name
	page.focus('#tpReviewFile')
	page.set_input_files('#tpFile', wav)
	try:
		page.wait_for_function("document.body.classList.contains('is-reviewing') ? document.querySelectorAll('#tpRvScript .tp-rv-w').length > 0 : false", timeout=120000)
		opened = True
	except Exception:
		opened = False
	check(opened, 'a stand-in take opens in the review')
	os.unlink(wav)
	if opened:
		page.wait_for_timeout(500)
		check(active(page) == 'tpRvTitle', 'focus moves to the review\'s heading as it opens', active(page))
		check(page.evaluate("document.getElementById('tpRvTimeline').getAttribute('aria-hidden')") == 'true', 'the timeline is hidden from screen readers (Flagged moments has the same as buttons)')
		tags = page.evaluate("[...document.querySelectorAll('#tpRvScript .tp-rv-s')].filter(s => /sx-(rushed|slow|rough|unfinished)/.test(s.className)).map(s => [s.className.match(/sx-(rushed|slow|rough|unfinished)/)[1], (s.querySelector('.tp-rv-tag') || {}).textContent || '', getComputedStyle(s).outlineStyle])")
		check(any(t[0] == 'slow' for t in tags), 'the slow sentence is shaded', tags)
		check(tags and all(t[1] for t in tags), 'every shaded sentence starts with a text tag', tags)
		check(all(t[1] == 'slow' and t[2] == 'dotted' for t in tags if t[0] == 'slow'), 'a slow sentence says "slow" and has a dotted edge', tags)
		found = axe(page)
		check(found == [], 'axe finds nothing in the review', found)
		rs = page.evaluate("(() => { const s = document.getElementById('tpRvScript'); return [s.tabIndex, s.getAttribute('role'), s.getAttribute('aria-label'), (document.getElementById(s.getAttribute('aria-describedby')) || {}).textContent || '']; })()")
		check(rs[0] == 0 and rs[1] == 'region' and rs[2] and rs[3].startswith('Left and right arrows'), 'the review\'s script is one Tab stop, named and described', rs)
		page.focus('#tpRvScript')
		page.wait_for_timeout(100)
		first = page.evaluate("(document.querySelector('#tpRvScript .is-cursor') || {}).textContent")
		check(first == 'A', 'the word cursor starts on the first word', first)
		page.keyboard.press('ArrowRight')
		page.keyboard.press('ArrowRight')
		page.wait_for_timeout(200)
		cur = page.evaluate("[document.querySelector('#tpRvScript .is-cursor').textContent, document.getElementById('tpSay').textContent]")
		check(cur[0] == 'hill' and cur[1].startswith('hill'), 'right moves word by word, each read out', cur)
		page.evaluate("document.getElementById('tpRvAudio').pause()")
		page.keyboard.press('Enter')
		page.wait_for_timeout(500)
		t = page.evaluate("[document.getElementById('tpRvAudio').currentTime, document.getElementById('tpRvAudio').paused]")
		check(t[1] is False and t[0] > 1.2, 'Enter plays from the word', t)
		page.keyboard.press('Space')
		page.wait_for_timeout(100)
		check(page.evaluate("document.getElementById('tpRvAudio').paused"), 'Space pauses from the script')
		ed = page.evaluate("document.getElementById('tpEdToggle').disabled")
		if not ed:
			page.click('#tpEdToggle')
			page.focus('#tpRvScript')
			page.keyboard.press('Shift+ArrowRight')
			page.keyboard.press('Shift+ArrowRight')
			sel = page.evaluate("[...document.querySelectorAll('#tpRvScript .is-sel')].map(e => e.textContent)")
			check(sel == ['hill', 'The'], 'with Edit audio on, Shift and the arrows select from the cursor (the title\'s last word and the next)', sel)
			page.keyboard.press('Delete')
			page.wait_for_timeout(200)
			cut = page.evaluate("[...document.querySelectorAll('#tpRvScript .is-cut')].map(e => e.textContent)")
			check(cut == ['hill', 'The'], 'Delete cuts them', cut)
			page.keyboard.press('Control+z')
			page.wait_for_timeout(200)
			check(page.evaluate("document.querySelectorAll('#tpRvScript .is-cut').length") == 0, 'Ctrl+Z puts them back')
			page.click('#tpEdToggle')
		else:
			check(False, 'Edit audio is ready on a take opened on the page')
		page.focus('#tpRvScript')
		page.keyboard.press('Escape')
		page.wait_for_timeout(300)
		check(not page.evaluate("document.body.classList.contains('is-reviewing')"), 'Escape closes the review')
		where = page.evaluate("(() => { const a = document.activeElement; return a.closest('#tpResult') ? 'result' : (a.id || a.tagName); })()")
		check(where in ('result', 'tpTrack'), 'focus goes to the ready dialog behind it, or the script', where)
		if where == 'result':
			page.keyboard.press('Escape')
			page.wait_for_timeout(200)
			check(active(page) in ('tpReviewFile', 'tpTrack'), 'closing the ready dialog gives focus back to where the take came from', active(page))
	check(errors == [], 'no script errors in the review', errors)

	# ---- A phone ----------------------------------------------------------------------
	phone = browser.new_context(viewport={'width': 393, 'height': 852}, device_scale_factor=3, is_mobile=True, has_touch=True, storage_state=ctx.storage_state())
	pp = phone.new_page()
	pp.goto(TP, wait_until='load')
	pp.wait_for_selector('.tp-w')
	pp.wait_for_timeout(600)
	if pp.evaluate("document.body.classList.contains('panel-closed')"):
		pp.click('#tpPanelToggle')
		pp.wait_for_timeout(400)
	al = pp.evaluate(ALIGN)
	check(all(x[1] <= 1 and x[2] >= 3 for x in al), 'and at 393 px', al)
	pp.evaluate("document.querySelector('[aria-label=\"About Clean up audio\"]').scrollIntoView({ block: 'center' })")
	pp.click('[aria-label="About Clean up audio"]')
	pp.wait_for_timeout(200)
	wide = pp.evaluate("document.documentElement.scrollWidth")
	check(wide <= 393, 'nothing scrolls sideways at 393 px with an explanation open', wide)
	inside = pp.evaluate('''() => {
		const h = [...document.querySelectorAll('.tp-help-text')].find(n => !n.hidden);
		const r = h.getBoundingClientRect();
		return r.left >= 0 && r.right <= 393;
	}''')
	check(inside, 'the explanation fits on the phone\'s screen')
	shot = os.path.join(SHOTS, 'a11y-studio-393.png')
	pp.screenshot(path=shot)
	print('     screenshot: ' + shot)
	phone.close()
	browser.close()

print('%d passed, %d failed' % (passed, len(failed)))
if failed:
	for f in failed:
		print('  - ' + f)
	sys.exit(1)
