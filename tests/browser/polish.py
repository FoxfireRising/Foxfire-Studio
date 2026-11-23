#!/usr/bin/env python3
"""
Voice polish (1.13.0), end to end.

Settings: each Broadcast sound step has a strength (Standard on a new site),
slow leveling is on and the EQ and click lowering off; a strength and the
click switch are saved.

Then a take with a click between two sentences is opened and reviewed:
with click lowering on, the MP3 is made again once Whisper's word times are
known and the review says how many clicks were lowered. Hear the polish
plays a sentence (Before and After), and Use for this take makes the MP3
again with Strong compression for this take only. Last, three takes on one
setup give that setup a suggested EQ, which Settings lists and Hear the
polish offers.

Whisper is a stand-in; the MP3 encoder and noise model are the real ones
from npm, as in take.py.

Usage: PL_POST=<post id> tests/browser/polish.py http://localhost:8080
The post must be at least 60 words of plain sentences.
"""
import io, json, math, os, re, struct, sys, tempfile, wave
from playwright.sync_api import sync_playwright
from signin import sign_in
from whisper_stub import PIECE
from take_assets import MP3, RNN

BASE = sys.argv[1].rstrip('/') if len(sys.argv) > 1 else 'http://localhost:8080'
CHROME = os.environ.get('CHROME_PATH', '/usr/bin/google-chrome')
POST_ID = int(os.environ['PL_POST'])
TP = BASE + '/?teleprompter=1&tp_post=%d' % POST_ID
SETTINGS = BASE + '/wp-admin/admin.php?page=rrt-settings'

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


def plan(words, pause=0.6):
	"""What the take says: (text, start, end) for every word."""
	out = []
	t = 0.2
	for w in words:
		if key(w) == '':
			continue
		out.append((w, t, t + 0.32))
		t += 0.32 + (pause if re.search(r'[.!?]$', w) else 0.12)
	return out, t + 1.0


def wav_for(heard, seconds, seed):
	"""Tone bursts where the words are, over a quiet hush, 44.1 kHz mono."""
	rate = 44100
	n = int(seconds * rate)
	x = [0.0] * n
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
	f = tempfile.NamedTemporaryFile(suffix='.wav', delete=False)
	f.write(buf.getvalue())
	f.close()
	return f.name


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


def with_click(path, at):
	"""The same WAV with a 3 ms click at `at` seconds."""
	w = wave.open(path, 'rb')
	rate, n = w.getframerate(), w.getnframes()
	data = bytearray(w.readframes(n))
	w.close()
	a = int(at * rate)
	# A mouth click: a 3 kHz ring that dies away in 3 ms, well under the words' level.
	for i in range(int(0.003 * rate)):
		v = int(32767 * 0.03 * math.exp(-i / (0.0008 * rate)) * math.sin(2 * math.pi * 3000 * i / rate))
		data[(a + i) * 2:(a + i) * 2 + 2] = struct.pack('<h', v)
	w = wave.open(path, 'wb')
	w.setnchannels(1)
	w.setsampwidth(2)
	w.setframerate(rate)
	w.writeframes(bytes(data))
	w.close()
	return path


def save(page, fill):
	page.goto(SETTINGS, wait_until='load')
	fill(page)
	with page.expect_navigation(wait_until='load'):
		page.click('#submit')
	page.goto(SETTINGS, wait_until='load')


def polish_settings(page):
	return page.evaluate("""() => {
		var v = function (id) { var e = document.getElementById(id); return e ? e.value : null; };
		var c = function (k) { var e = document.querySelector('input[name$="[sound][' + k + ']"]'); return e ? e.checked : null; };
		return { plosives: v('rrt-sound-plosives'), deess: v('rrt-sound-deess'), breaths: v('rrt-sound-breaths'), compress: v('rrt-sound-compress'),
			levels: Array.prototype.map.call(document.querySelectorAll('#rrt-sound-deess option'), function (o) { return o.value; }),
			hp: c('hp'), slow: c('slow'), eq: c('eq'), clicks: c('clicks') };
	}""")


with sync_playwright() as p:
	browser = p.chromium.launch(executable_path=CHROME if os.path.exists(CHROME) else None, args=['--autoplay-policy=no-user-gesture-required'])
	ctx = browser.new_context(accept_downloads=True)
	page = ctx.new_page()
	errors = []
	page.on('pageerror', lambda e: errors.append(str(e)))
	fake = {'js': ''}
	ctx.route(re.compile(r'.*transformers(\.min)?\.js.*'), lambda r: r.fulfill(status=200, content_type='text/javascript', body=fake['js'], headers={'Access-Control-Allow-Origin': '*'}))
	ctx.route(re.compile(r'.*/mp3\.wasm.*'), lambda r: r.fulfill(status=200, content_type='application/wasm', body=MP3, headers={'Access-Control-Allow-Origin': '*'}))
	ctx.route(re.compile(r'.*/rnnoise\.wasm.*'), lambda r: r.fulfill(status=200, content_type='application/wasm', body=RNN, headers={'Access-Control-Allow-Origin': '*'}))

	sign_in(page, BASE)

	# Settings.
	page.goto(SETTINGS, wait_until='load')
	s = polish_settings(page)
	check(s['plosives'] == s['deess'] == s['breaths'] == s['compress'] == 'standard', 'a new site has every step at Standard', json.dumps(s))
	check(s['levels'] == ['off', 'gentle', 'standard', 'strong'], 'each step offers Off, Gentle, Standard and Strong', json.dumps(s['levels']))
	check(s['hp'] is True and s['slow'] is True and s['eq'] is False and s['clicks'] is False, 'slow leveling is on, the EQ and click lowering off', json.dumps(s))

	def turn_on(pg):
		pg.select_option('#rrt-sound-deess', 'gentle')
		pg.check('input[name$="[sound][clicks]"]')
	save(page, turn_on)
	s = polish_settings(page)
	check(s['deess'] == 'gentle' and s['clicks'] is True and s['compress'] == 'standard', 'a strength and click lowering are saved', json.dumps(s))

	# A take with a click in the pause after the first sentence.
	page.goto(TP, wait_until='load')
	page.evaluate("try { sessionStorage.setItem('rrtIntroSeen', '1'); localStorage.setItem('rrtIntro', 'off'); } catch (e) {}")
	page.goto(TP, wait_until='load')
	page.wait_for_timeout(500)
	page.evaluate("a => { var e = document.querySelector(a); if (!e.checked) { e.click(); } }", '#tpWhisperOpt')
	# A rerun starts with the test setup unmeasured.
	page.evaluate("() => { var r = RRT.eq.rest, q = 'setup=' + encodeURIComponent('computer|test microphone'); return fetch(r + 'eq' + (r.indexOf('?') < 0 ? '?' : '&') + q, { method: 'DELETE', credentials: 'same-origin', headers: { 'X-WP-Nonce': RRT.eq.nonce } }).then(function (x) { return x.status; }); }")
	words = page.evaluate("Array.prototype.map.call(document.querySelectorAll('.tp-w'), function (e) { return e.textContent; })")
	heard, secs = plan(words)
	fake['js'] = fake_transformers(heard)
	first_end = next(e for (w, s0, e) in heard if re.search(r'[.!?]$', w))
	check(secs >= 30, 'the take is long enough to measure for the EQ', '%.1f s' % secs)

	def review(seed, setup=None, clicked=False):
		path = wav_for(heard, secs, seed)
		if clicked:
			with_click(path, first_end + 0.3)
		page.goto(TP, wait_until='load')
		page.wait_for_timeout(500)
		page.set_input_files('#tpFile', path)
		if setup:
			page.evaluate("s => { window.RRTStudio.takeSetup = s; }", setup)
		page.wait_for_function("document.body.classList.contains('is-reviewing')", timeout=120000)
		page.wait_for_timeout(300)

	review(23, clicked=True)
	page.wait_for_function("/click/.test(document.getElementById('tpWhisperStatus').textContent)", timeout=90000)
	st = page.evaluate("document.getElementById('tpWhisperStatus').textContent")
	got = re.search(r'Lowered (\d+) clicks? between words', st)
	check(got is not None and int(got.group(1)) == 1, 'with click lowering on, the review lowers the one click between the words, and nothing else', st[-300:])
	check(page.evaluate("document.getElementById('tpRvAudio').src === document.getElementById('tpDownload').href"), 'the review plays the polished MP3, so clips and Share for feedback get it too')
	ro = page.evaluate("document.getElementById('tpReadout').textContent")
	check('harsh S sounds gentle' in ro or 'S sounds gentle' in ro, 'the read-out names a strength other than Standard', ro[-500:])
	check('minute and a half' in ro, 'the read-out says slow leveling leaves a short reading as it was', ro[-400:])

	# Hear the polish.
	check(page.is_visible('#tpRvPolish'), 'the review has Hear the polish')
	page.click('#tpRvPolish')
	check(page.is_visible('#tpPolishBox'), 'it opens its panel')
	n = page.evaluate("document.querySelectorAll('#tpPolishSent option').length")
	check(n >= 3, 'it lists the take\'s sentences', str(n))
	steps = page.evaluate("Array.prototype.map.call(document.querySelectorAll('#tpPolishSteps select[data-step]'), function (s) { return s.getAttribute('data-step') + ':' + s.options[0].textContent; })")
	check(steps == ['plosives:Settings (Standard)', 'deess:Settings (Gentle)', 'breaths:Settings (Standard)', 'compress:Settings (Standard)'], 'each step starts at the Settings choice, named', json.dumps(steps))
	page.click('#tpPolishPlay')
	page.wait_for_function("document.getElementById('tpPolishPlay').textContent === 'Stop'", timeout=30000)
	check(True, 'the sentence plays, looping')
	check('Around this sentence' in page.inner_text('#tpPolishStatus'), 'it says what the polish did around the sentence', page.inner_text('#tpPolishStatus'))
	page.click('#tpPolishBefore')
	check(page.get_attribute('#tpPolishBefore', 'aria-pressed') == 'true' and page.get_attribute('#tpPolishAfter', 'aria-pressed') == 'false', 'Before switches while it plays')
	page.click('#tpPolishPlay')
	check(page.is_disabled('#tpPolishUse'), 'Use for this take waits for a change')
	old_href = page.get_attribute('#tpDownload', 'href')
	page.select_option('#tpPolishSteps select[data-step="compress"]', 'strong')
	check(not page.is_disabled('#tpPolishUse'), 'a change can be used for this take')
	page.click('#tpPolishUse')
	page.wait_for_function("/made again/.test(document.getElementById('tpPolishStatus').textContent)", timeout=90000)
	pst = page.inner_text('#tpPolishStatus')
	check('compression strong' in pst, 'the take\'s MP3 is made again with Strong compression', pst[:300])
	check(page.get_attribute('#tpDownload', 'href') != old_href, 'the download button gives the new MP3')
	check(not page.is_disabled('#tpPolishReset'), 'and it can go back to the Settings choice')
	page.click('#tpPolishReset')
	page.wait_for_function("/Settings choice\\./.test(document.getElementById('tpPolishStatus').textContent)", timeout=90000)
	check('compression strong' not in page.inner_text('#tpPolishStatus'), 'back at the Settings choice', page.inner_text('#tpPolishStatus')[:300])
	eqline = page.inner_text('#tpPolishEq')
	check('not known' in eqline, 'a take from a file has no known setup, and the panel says so', eqline)
	page.click('#tpPolishClose')

	# Three takes on one setup give it an EQ.
	setup = {'key': 'computer|test microphone', 'label': 'Computer, Test microphone'}
	for i in range(3):
		review(31 + i, setup=setup)
		page.wait_for_timeout(1500)
	page.click('#tpRvPolish')
	eqline = page.inner_text('#tpPolishEq')
	check('Computer, Test microphone' in eqline and 'EQ from 3 takes' in eqline, 'after three takes the setup has an EQ', eqline)
	eq_opts = page.evaluate("Array.prototype.map.call(document.querySelectorAll('#tpPolishSteps select:not([data-step]) option'), function (o) { return o.value; })")
	check('computer|test microphone' in eq_opts, 'Hear the polish offers this setup\'s EQ', json.dumps(eq_opts))
	page.goto(SETTINGS, wait_until='load')
	txt = page.inner_text('.rrt-eq-setups') if page.query_selector('.rrt-eq-setups') else ''
	check('Computer, Test microphone: 3 takes measured' in txt, 'Settings lists the setup and its takes', txt)
	page.goto(TP, wait_until='load')
	page.wait_for_timeout(500)
	review(41, setup=setup)
	page.click('#tpRvPolish')
	page.click('#tpPolishEq .tp-btn')
	page.wait_for_function("/0 of 3 takes measured/.test(document.getElementById('tpPolishEq').textContent) || /: 1 of 3/.test(document.getElementById('tpPolishEq').textContent)", timeout=15000)
	check(True, 'Forget this setup clears its measurements, so its EQ is learned again')

	# Back to the defaults for the tests that follow.
	def back(pg):
		pg.select_option('#rrt-sound-deess', 'standard')
		pg.uncheck('input[name$="[sound][clicks]"]')
	save(page, back)
	s = polish_settings(page)
	check(s['deess'] == 'standard' and s['clicks'] is False, 'the Settings choice is back to the defaults')
	check(not errors, 'no script errors', '; '.join(errors[:3]))
	browser.close()

print('\n%d passed, %d failed' % (passed, len(failed)))
sys.exit(1 if failed else 0)
