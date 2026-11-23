"""
The setup wizard (1.29.0), on a site made to look like a new install.

1. The notice on Plugins, with Set up; axe-core finds nothing in it.
2. The whole wizard from the keyboard only: Start, a show name, the Dark
   preset, Pages and Everyone, the download button (its requests answered
   here), the practice link, Finish. axe-core on every step, and the steps
   at iPhone width (393 px) with a screenshot.
3. Afterwards: Settings shows what was chosen and Run setup again, the
   notice and the menu entry are gone, and an editor cannot open the wizard.
4. The practice reading: Check microphone opens by itself, the script is the
   practice script, Use this recording is not offered, and a stand-in take's
   review is kept as a practice take (post 0).
5. New here: offered once on the studio's first screen and beside Check
   microphone in a new browser, gone after Not now.

Usage: SU_SEED=<json from tests/wp/setup.php> python3 tests/browser/setup.py [http://localhost:8080] [folder for the screenshot]
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
from urllib.parse import parse_qs

from playwright.sync_api import sync_playwright
from signin import sign_in
from take_assets import MP3, RNN, npm_file
from whisper_stub import PIECE

BASE = (sys.argv[1] if len(sys.argv) > 1 else os.environ.get('WP_URL', 'http://localhost:8080')).rstrip('/')
SHOTS = sys.argv[2] if len(sys.argv) > 2 else tempfile.gettempdir()
CHROME = os.environ.get('CHROME_PATH', '/usr/bin/google-chrome')
SEED = json.load(open(os.environ['SU_SEED']))
AXE = npm_file('axe-core', '4.14.1', 'axe.min.js', 'axe-core', sha='29348999450cdf2849a8e70b7912fc2da85a849da39c0d0d5394f51d4a9ed95c').decode('utf8')
TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']
SETUP = BASE + '/wp-admin/admin.php?page=rrt-setup'

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


def axe(page, include=None):
	if not page.evaluate("typeof window.axe === 'object'"):
		page.add_script_tag(content=AXE)
	return page.evaluate('''([t, inc]) => axe.run(inc ? { include: [[inc]] } : document, { runOnly: { type: 'tag', values: t }, resultTypes: ['violations'] }).then(r => r.violations.map(v => v.id + ' x' + v.nodes.length + ' ' + v.nodes.slice(0, 2).map(n => n.target.join(' ')).join(', ')))''', [TAGS, include])


def step_name(page):
	return page.evaluate("(document.getElementById('rrt-setup-h') || {}).textContent || ''")


def press_until(page, selector, key='Tab', limit=100):
	"""Presses Tab until the element is focused, the way a keyboard user gets there."""
	for _ in range(limit):
		if page.evaluate('(s) => document.activeElement === document.querySelector(s)', selector):
			return True
		page.keyboard.press(key)
	return False


def from_heading(page):
	"""Starts the keyboard walk at the step's heading (as a screen reader user would land there)."""
	page.evaluate("() => { const h = document.getElementById('rrt-setup-h'); h.tabIndex = -1; h.focus(); }")


def submit_next(page):
	"""Next from the keyboard: from the step's heading, Tab to it, then Enter."""
	from_heading(page)
	ok = press_until(page, '.rrt-setup-buttons button[value="next"]')
	with page.expect_navigation(wait_until='load'):
		page.keyboard.press('Enter')
	return ok


def wav_for(heard, seconds):
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


asked = {'whisper': [], 'local': []}


def ajax(route):
	"""Answers the downloader's requests here, so nothing is fetched from outside."""
	req = route.request
	body = req.post_data_buffer or b''
	text = body.decode('latin-1')
	m = re.search(r'name="action"\r\n\r\n([a-z_]+)', text)
	action = m.group(1) if m else ''
	if action == 'rrt_whisper_fetch':
		i = re.search(r'name="i"\r\n\r\n(\d+)', text)
		s = re.search(r'name="set"\r\n\r\n([a-z]+)', text)
		asked['whisper'].append((s.group(1) if s else '', int(i.group(1)) if i else -1))
		route.fulfill(status=200, content_type='application/json', body=json.dumps({'success': True, 'data': {'size': 1234567}}))
		return
	if action == 'rrt_local_fetch':
		st = re.search(r'name="step"\r\n\r\n([a-z_]+)', text)
		name = st.group(1) if st else ''
		asked['local'].append(name)
		data = {'message': name + ' saved.'}
		if name == 'fonts_plan':
			data = {'message': 'Fonts: 2 files to fetch.', 'count': 2}
		route.fulfill(status=200, content_type='application/json', body=json.dumps({'success': True, 'data': data}))
		return
	route.continue_()


with sync_playwright() as p:
	browser = p.chromium.launch(executable_path=CHROME if os.path.exists(CHROME) else None, args=['--autoplay-policy=no-user-gesture-required', '--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream'])
	ctx = browser.new_context(viewport={'width': 1280, 'height': 900})
	ctx.route(re.compile(r'.*/wp-admin/admin-ajax\.php.*'), ajax)
	page = ctx.new_page()
	errors = []
	page.on('pageerror', lambda e: errors.append(str(e)))
	sign_in(page, BASE)

	# ---- The notice ---------------------------------------------------------
	page.goto(BASE + '/wp-admin/plugins.php', wait_until='load')
	page.wait_for_timeout(400)
	check(page.locator('.rrt-setup-notice').count() == 1, 'a new site shows the setup notice on Plugins')
	found = axe(page, '.rrt-setup-notice')
	check(found == [], 'axe finds nothing in the notice', found)
	check(page.locator('#adminmenu a[href="admin.php?page=rrt-setup"]').count() == 1, 'Foxfire Studio\'s menu has Set up')
	with page.expect_navigation(wait_until='load'):
		page.click('.rrt-setup-notice a.button-primary')
	check('page=rrt-setup' in page.url and step_name(page) == 'Welcome', 'Set up opens the wizard at its welcome', page.url)
	check(page.title().count('Set up Foxfire Studio') == 1, 'the browser tab is named Set up Foxfire Studio, once', page.title())

	shots = {}

	def look_here(name):
		found = axe(page, '.rrt-setup')
		check(found == [], 'axe finds nothing on ' + name, found)
		check(page.evaluate("document.querySelector('.rrt-setup-steps [aria-current=\"step\"]').textContent") == name, name + ' is marked as the current step')
		page.set_viewport_size({'width': 393, 'height': 852})
		page.wait_for_timeout(150)
		wide = page.evaluate("document.documentElement.scrollWidth <= window.innerWidth + 1")
		check(wide, name + ' fits at iPhone width with no sideways scrolling')
		if name == 'The look':
			path = os.path.join(SHOTS, 'setup-393.png')
			page.screenshot(path=path, full_page=True)
			shots['look'] = path
		page.set_viewport_size({'width': 1280, 'height': 900})
		page.wait_for_timeout(100)

	# ---- Welcome ---------------------------------------------------------------
	look_here('Welcome')
	check(page.locator('text=Step 1 of 6').count() == 1, 'the welcome is Step 1 of 6')
	from_heading(page)
	ok = press_until(page, '.rrt-setup-buttons button[value="next"]')
	check(ok, 'Start is reached with Tab')
	with page.expect_navigation(wait_until='load'):
		page.keyboard.press('Enter')

	# ---- Your show ---------------------------------------------------------------
	check(step_name(page) == 'Your show', 'Start goes to Your show', step_name(page))
	look_here('Your show')
	check(page.evaluate("document.getElementById('rrt-setup-show-name').value") == '', 'a new site has no show name yet (the site title is its placeholder)')
	from_heading(page)
	check(press_until(page, '#rrt-setup-show-name'), 'the show name is reached with Tab')
	page.keyboard.type('The Lantern Hour')
	with page.expect_navigation(wait_until='load'):
		page.keyboard.press('Enter')

	# ---- The look ---------------------------------------------------------------
	check(step_name(page) == 'The look', 'Enter in the name saves it and goes to The look', step_name(page))
	look_here('The look')
	check(page.evaluate("document.querySelector('input[name=preset][value=neutral]').checked"), 'the preset in use, Neutral, is chosen')
	check(page.locator('#rrt-setup-readable[data-rrt-readable="pass"]').count() == 1, 'Readability says the colours are easy to read')
	from_heading(page)
	check(press_until(page, 'input[name=preset][value=neutral]'), 'the presets are reached with Tab')
	page.keyboard.press('ArrowRight')
	check(page.evaluate("document.querySelector('input[name=preset][value=dark]').checked"), 'the arrow keys choose Dark')
	check(submit_next(page), 'Next is reached with Tab')

	# ---- Readings and the player -----------------------------------------------------
	check(step_name(page) == 'Readings and the player', 'Next goes to Readings and the player', step_name(page))
	look_here('Readings and the player')
	check(page.evaluate("document.querySelector('input[name=\"post_types[]\"][value=post]').checked") and page.evaluate("document.querySelector('input[name=show][value=editors]').checked"), 'Posts and the editors\' preview are chosen, as on any new site')
	from_heading(page)
	check(press_until(page, 'input[name="post_types[]"][value=page]'), 'Pages is reached with Tab')
	page.keyboard.press('Space')
	check(press_until(page, 'input[name=show]:checked'), 'Show the player to is reached with Tab')
	page.keyboard.press('ArrowDown')
	check(page.evaluate("document.querySelector('input[name=show][value=everyone]').checked"), 'the arrow keys choose Everyone')
	submit_next(page)

	# ---- Privacy and files -----------------------------------------------------------
	check(step_name(page) == 'Privacy and files', 'Next goes to Privacy and files', step_name(page))
	look_here('Privacy and files')
	check(page.evaluate("document.querySelector('input[name=server]').checked"), 'keeping takes on the site starts ticked, as its default')
	rows = page.evaluate("[...document.querySelectorAll('.rrt-setup-table tbody tr')].map(r => r.cells[2].textContent)")
	check(len(rows) == 7 and all(r == 'Off' for r in rows), 'the seven switches that touch visitors are listed, all off', rows)
	from_heading(page)
	check(press_until(page, '#rrt-setup-files-follow'), 'the extras are reached with Tab')
	page.keyboard.press('Space')
	check(press_until(page, '#rrt-setup-files-get'), 'and the download button')
	page.keyboard.press('Enter')
	try:
		page.wait_for_function("/^Done\\./.test(document.getElementById('rrt-setup-files-msg').textContent)", timeout=30000)
		done = True
	except Exception:
		done = False
	check(done, 'the download finishes and says so', page.evaluate("document.getElementById('rrt-setup-files-msg').textContent"))
	sets = sorted(set(s for s, _ in asked['whisper']))
	check(sets == ['base', 'follow'], 'it fetched the standard Whisper set and the ticked voice following, not the larger model', sets)
	check(asked['local'][:3] == ['encoder', 'model', 'fonts_plan'] and asked['local'][-1] == 'fonts_finish', 'and the encoder, the noise model and the fonts', asked['local'])
	check(page.evaluate("[...document.querySelectorAll('#rrt-setup-base li')].every(li => li.className === 'is-ok')"), 'each Whisper file is marked as arrived')
	check(page.evaluate("document.getElementById('rrt-setup-files-msg').getAttribute('role')") == 'status', 'the outcome is read out (a status line)')
	submit_next(page)

	# ---- Your first reading ---------------------------------------------------------
	check(step_name(page) == 'Your first reading', 'Next goes to Your first reading', step_name(page))
	look_here('Your first reading')
	href = page.get_attribute('#rrt-setup-practice', 'href') or ''
	check('tp_practice=1' in href and 'tp_check=1' in href and page.get_attribute('#rrt-setup-practice', 'target') == '_blank', 'it opens the practice reading in a new tab', href)
	check('Finish' in page.evaluate("document.querySelector('.rrt-setup-buttons button[value=next]').textContent"), 'its button is Finish')
	submit_next(page)
	check(step_name(page) == 'Done', 'Finish ends at Done', step_name(page) + ' ' + page.url)
	found = axe(page, '.rrt-setup')
	check(found == [], 'axe finds nothing on Done', found)

	# ---- Afterwards --------------------------------------------------------------
	page.goto(BASE + '/wp-admin/options-general.php?page=rrt-settings', wait_until='load')
	got = page.evaluate('''() => ({
		name: document.getElementById('rrt-show-name').value,
		page: document.querySelector('input[name="rrt_settings[post_types][]"][value=page]').checked,
		everyone: document.querySelector('input[name="rrt_settings[player][show]"][value=everyone]').checked,
		accent: document.getElementById('rrt-color-accent').value,
		again: !!document.getElementById('rrt-setup-again'),
		menu: !!document.querySelector('#adminmenu a[href="admin.php?page=rrt-setup"]'),
		notice: !!document.querySelector('.rrt-setup-notice'),
		stats: document.querySelector('input[name="rrt_settings[stats][server]"]').checked,
		breath: document.querySelector('input[name="rrt_settings[defaults][breath]"]').checked
	})''')
	check(got['name'] == 'The Lantern Hour' and got['page'] and got['everyone'], 'Settings shows the show name, Pages and Everyone', got)
	check(got['accent'] == '#d4a843', 'and the Dark preset\'s colours', got['accent'])
	check(got['stats'] and got['breath'], 'and every other starting value as before', got)
	check(got['again'] and not got['menu'] and not got['notice'], 'Run setup again is at the top; Set up and the notice are gone', got)
	# Settings' own download buttons use the same downloader since 1.29.0.
	asked['whisper'] = []
	asked['local'] = []
	page.click('#rrt-whisper-get')
	page.wait_for_function("[...document.querySelectorAll('#rrt-whisper-files li')].every(li => li.className === 'is-ok')", timeout=30000)
	check(page.evaluate("document.getElementById('rrt-whisper-get').textContent") == 'Download again' and len(asked['whisper']) == page.evaluate("document.querySelectorAll('#rrt-whisper-files li').length"), 'Settings\' Download Whisper to this site still fetches every file in turn', len(asked['whisper']))
	page.click('#rrt-local-get')
	page.wait_for_function("/^Done\\./.test((document.querySelector('#rrt-local-log li:last-child') || {}).textContent || '')", timeout=30000)
	check(asked['local'][0] == 'encoder' and asked['local'][-1] == 'fonts_finish' and page.evaluate("document.getElementById('rrt-local-get').textContent") == 'Download again', 'and its fonts, encoder and noise model button too', asked['local'])
	page.goto(BASE + '/wp-admin/plugins.php', wait_until='load')
	check(page.locator('.rrt-setup-notice').count() == 0, 'Plugins no longer shows the notice')
	page.goto(SETUP + '&step=show', wait_until='load')
	check(page.evaluate("document.getElementById('rrt-setup-show-name').value") == 'The Lantern Hour', 'Run setup again opens each step with the saved values')
	check(page.title().count('Set up Foxfire Studio') == 1, 'once out of the menu, the tab is still named Set up Foxfire Studio, once', page.title())
	check(errors == [], 'no script errors in the wizard', errors)

	ed = browser.new_context(viewport={'width': 1280, 'height': 900})
	ep = ed.new_page()
	sign_in(ep, BASE, 'editor', 'editor')
	ep.goto(SETUP, wait_until='load')
	check(ep.locator('.rrt-setup').count() == 0, 'an editor cannot open the wizard')
	ed.close()

	# ---- The practice reading -------------------------------------------------------
	fake = {'js': ''}
	ctx.route(re.compile(r'.*transformers(\.min)?\.js.*'), lambda r: r.fulfill(status=200, content_type='text/javascript', body=fake['js'], headers={'Access-Control-Allow-Origin': '*'}))
	ctx.route(re.compile(r'.*/mp3\.wasm.*'), lambda r: r.fulfill(status=200, content_type='application/wasm', body=MP3, headers={'Access-Control-Allow-Origin': '*'}))
	ctx.route(re.compile(r'.*/rnnoise\.wasm.*'), lambda r: r.fulfill(status=200, content_type='application/wasm', body=RNN, headers={'Access-Control-Allow-Origin': '*'}))
	page.goto(BASE + '/?teleprompter=1', wait_until='load')
	page.evaluate("try { sessionStorage.setItem('rrtIntroSeen', '1'); localStorage.setItem('rrtIntro', 'off'); } catch (e) {}")
	# 1.30.0: Record my voice off in this browser, as in a new browser on a site that defaults to off.
	page.evaluate("(() => { var k = 'rrtTeleprompter'; var o = JSON.parse(localStorage.getItem(k) || '{}'); o.rec = false; localStorage.setItem(k, JSON.stringify(o)); })()")
	page.goto(href, wait_until='load')
	page.wait_for_selector('.tp-w')
	check(page.evaluate("document.getElementById('tpRecOpt').checked"), 'the practice reading switches Record my voice on for its page (1.30.0)')
	check('Recording is on for this practice' in page.evaluate("document.getElementById('tpMsg').textContent"), 'and the status line says so')
	check(page.evaluate("JSON.parse(localStorage.getItem('rrtTeleprompter') || '{}').rec") is False, 'the browser\'s saved choice stays off')
	tips = page.evaluate("['tpCombineOpen', 'tpContinue', 'tpRvCombine'].map(id => (document.getElementById(id) || {}).title || '')")
	check(all(t and 'post' not in t for t in tips), 'the hidden buttons\' tooltips speak of this reading, never a post (1.30.0)', tips)
	try:
		page.wait_for_selector('.tp-mic', timeout=10000)
		opened = True
	except Exception:
		opened = False
	check(opened, 'the practice reading opens Check microphone by itself')
	if opened:
		check(page.evaluate("!!document.activeElement.closest('.tp-mic')"), 'with focus inside it')
		page.keyboard.press('Escape')
		page.wait_for_timeout(300)
	title = page.evaluate("(document.querySelector('.tp-block.tp-title') || {}).textContent || ''")
	check(title == 'A practice reading', 'the script is the practice script', title)
	check(page.evaluate("document.querySelectorAll('.tp-block.tp-p').length") == 5, 'five paragraphs to read')
	check(page.evaluate("document.getElementById('tpMsg').textContent").startswith('Practice reading'), 'the status line says it is a practice reading')
	check(page.locator('#tpMicNew').count() == 0, 'the practice reading does not offer itself again')
	# 1.31.0: the pace line says the timed pace waits for nobody.
	check(page.evaluate("RRTStudio.paceLineWanted()") is True, 'the practice reading always shows the pace line on the timed pace (1.31.0)')
	page.evaluate("RRTStudio.paceLine()")
	pl = page.evaluate("document.getElementById('tpMsg').textContent")
	wpm = page.evaluate("RRTStudio.settings.wpm")
	check(('moves at %d words a minute' % wpm) in pl and 'does not wait for you' in pl and 'Controls panel, Reading' in pl, 'it names the reader\'s own pace and where Follow my voice is', pl)
	words = page.evaluate("[...document.querySelectorAll('.tp-w')].map(e => e.textContent)")
	heard = []
	t = 0.8
	for w in words:
		if re.sub(r'[^a-z0-9]', '', w.lower()):
			heard.append((w, t, t + 0.28))
			t += 0.42
	fake['js'] = fake_transformers(heard)
	page.evaluate("() => { const w = document.getElementById('tpWhisperOpt'); if (w && !w.checked) { w.click(); } }")
	with tempfile.NamedTemporaryFile(suffix='.wav', delete=False) as f:
		f.write(wav_for(heard, t + 1.0))
		wav = f.name
	page.set_input_files('#tpFile', wav)
	try:
		page.wait_for_function("document.body.classList.contains('is-reviewing') ? document.querySelectorAll('#tpRvScript .tp-rv-w').length > 0 : false", timeout=120000)
		reviewed = True
	except Exception:
		reviewed = False
	os.unlink(wav)
	check(reviewed, 'a stand-in take of the practice script opens in the review')
	if reviewed:
		page.wait_for_timeout(1500)
		check(page.evaluate("document.getElementById('tpRvUseRec').hidden && document.getElementById('tpUseRec').hidden"), 'Use this recording is not offered for a practice take')
		kept = page.evaluate('''async () => {
			const S = window.RRT.stats;
			const q = S.rest.indexOf('?') < 0 ? '?' : '&';
			for (let i = 0; i < 20; i++) {
				const r = await fetch(S.rest + 'takes' + q + 'post=0', { credentials: 'same-origin', headers: { 'X-WP-Nonce': S.nonce } });
				const j = await r.json();
				if (j.takes && j.takes.length) { return j.takes.length; }
				await new Promise(res => setTimeout(res, 500));
			}
			return 0;
		}''')
		check(kept >= 1, 'the take is kept on the site as a practice take (post 0)', kept)
		said = page.evaluate("document.body.innerText")
		check('this post’s shelf' not in said and 'Record this post again' not in said, 'the practice review never speaks of a post (1.29.1)', [l for l in said.split('\n') if 'post' in l][:5])
	check(errors == [], 'no script errors in the practice reading', errors)

	# ---- New here -------------------------------------------------------------------
	fresh = browser.new_context(viewport={'width': 1280, 'height': 900})
	fp = fresh.new_page()
	ferr = []
	fp.on('pageerror', lambda e: ferr.append(str(e)))
	sign_in(fp, BASE)
	fp.goto(BASE + '/?teleprompter=1', wait_until='load')
	fp.evaluate("try { sessionStorage.setItem('rrtIntroSeen', '1'); localStorage.setItem('rrtIntro', 'off'); } catch (e) {}")
	fp.goto(BASE + '/?teleprompter=1', wait_until='load')
	fp.wait_for_timeout(600)
	check(fp.locator('#tpWelcome:not([hidden])').count() == 1, 'a reader new to this browser is offered the practice reading on the studio\'s first screen')
	check('tp_practice=1' in (fp.get_attribute('#tpWelcome a', 'href') or ''), 'Try it opens the practice reading')
	check(fp.locator('#tpEmptyPractice[hidden]').count() == 1, 'while New here shows, the first screen\'s lasting practice line steps aside (1.33.1)')
	# 1.31.0: Follow my voice as a tick in New here, with its size.
	if fp.evaluate("!!(window.RRT && RRT.follow && window.RRTFollow)"):
		check(fp.locator('.tp-welcome-voice:not([hidden])').count() == 1 and ' MB' in (fp.text_content('#tpWelcomeVoiceSize') or ''), 'New here offers Follow my voice as a tick, with its size', fp.text_content('#tpWelcomeVoiceSize'))
		fp.click('#tpWelcomeVoice')
		fp.wait_for_timeout(200)
		check(fp.evaluate("document.getElementById('tpVoice').checked && JSON.parse(localStorage.getItem('rrtTeleprompter') || '{}').voice === true"), 'the tick switches Follow my voice on for this browser')
		fp.click('#tpWelcomeVoice')
		fp.wait_for_timeout(200)
		check(fp.evaluate("JSON.parse(localStorage.getItem('rrtTeleprompter') || '{}').voice") is False, 'and off again')
	else:
		print('note voice following is not set up on this test site, so the New here tick is not checked')
	found = axe(fp, '#tpWelcome')
	check(found == [], 'axe finds nothing in the offer', found)
	fp.goto(BASE + '/?teleprompter=1&tp_post=%d' % SEED['post'], wait_until='load')
	fp.wait_for_selector('.tp-w')
	fp.wait_for_timeout(400)
	if fp.evaluate("document.body.classList.contains('panel-closed')"):
		fp.click('#tpPanelToggle')
		fp.wait_for_timeout(300)
	check(fp.locator('#tpMicNew:not([hidden])').count() == 1, 'and beside Check microphone on a post')
	check(fp.evaluate("RRTStudio.paceLineWanted()") is True, 'a browser\'s first take on a post shows the pace line')
	fp.evaluate("localStorage.setItem('rrtTookOne', String(Date.now()))")
	check(fp.evaluate("RRTStudio.paceLineWanted()") is False, 'and once a take is finished in this browser, it no longer does')
	fp.evaluate("localStorage.removeItem('rrtTookOne')")
	fp.goto(BASE + '/?teleprompter=1', wait_until='load')
	fp.wait_for_timeout(400)
	fp.click('#tpWelcomeLater')
	fp.wait_for_timeout(200)
	check(fp.locator('#tpWelcome:not([hidden])').count() == 0, 'Not now hides it')
	check(fp.locator('#tpEmptyPractice:not([hidden])').count() == 1, 'and the lasting line takes its place (1.33.1)')
	fp.goto(BASE + '/?teleprompter=1', wait_until='load')
	fp.wait_for_timeout(400)
	check(fp.locator('#tpWelcome:not([hidden])').count() == 0, 'and it stays hidden in this browser')
	ehref = fp.get_attribute('#tpEmptyPractice a', 'href') or ''
	check(fp.locator('#tpEmptyPractice:not([hidden])').count() == 1 and 'tp_practice=1' in ehref and 'tp_check' not in ehref, 'the first screen keeps a way to the practice reading, with no automatic microphone check (1.33.1)', ehref)
	fp.goto(BASE + '/?teleprompter=1&tp_post=%d' % SEED['post'], wait_until='load')
	fp.wait_for_selector('.tp-w')
	fp.wait_for_timeout(400)
	check(fp.locator('#tpMicNew:not([hidden])').count() == 0, 'also beside Check microphone')
	# 1.33.1: Practice reading in the Controls panel, under Practice, opens it in this tab.
	if fp.evaluate("document.body.classList.contains('panel-closed')"):
		fp.click('#tpPanelToggle')
		fp.wait_for_timeout(300)
	pbtn = fp.locator('#tpPracticeOpen')
	phref = pbtn.get_attribute('data-href') if pbtn.count() else ''
	check(pbtn.count() == 1 and pbtn.is_visible() and 'tp_practice=1' in (phref or '') and 'tp_check' not in (phref or ''), 'Practice reading sits in the Controls panel under Practice (1.33.1)', phref)
	check(fp.locator('#tpPracticeOpen + .tp-help-btn, .tp-helped:has(#tpPracticeOpen) .tp-help-btn').count() == 1, 'with a ? that explains it')
	sec = axe(fp, 'section[aria-labelledby="tpSecPrac"]')
	check(sec == [], 'axe finds nothing in the Practice section', sec)
	pages_before = len(fresh.pages)
	with fp.expect_navigation():
		pbtn.click()
	fp.wait_for_selector('.tp-w')
	check('tp_practice=1' in fp.url and len(fresh.pages) == pages_before, 'it opens the practice reading in the same tab', fp.url)
	check(fp.locator('#tpPracticeOpen').count() == 0, 'where it is not offered again')
	fp.wait_for_timeout(1200)
	check(fp.locator('.tp-mic').count() == 0, 'and the microphone check does not open by itself')
	# 1.33.1: Your progress has a button to it.
	fp.goto(BASE + '/wp-admin/admin.php?page=rrt-progress', wait_until='load')
	gb = fp.locator('#rrt-progress-practice')
	ghref = gb.get_attribute('href') if gb.count() else ''
	check(gb.count() == 1 and 'tp_practice=1' in (ghref or '') and gb.get_attribute('target') == '_blank', 'Your progress has Open the practice reading, in a new tab (1.33.1)', ghref)
	check(ferr == [], 'no script errors for a new reader', ferr)
	fresh.close()

	browser.close()

if shots:
	print('Screenshot at iPhone width: ' + shots['look'])
print('%d passed, %d failed' % (passed, len(failed)))
sys.exit(1 if failed else 0)
