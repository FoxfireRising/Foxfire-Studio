#!/usr/bin/env python3
"""
The stronger cleanup (1.16.0), end to end, with the real model.

The take is a stand-in voice (a buzz through moving vowel formants, with an
S now and then) read over a struck bell and a hiss, so no real recording is
needed. Whisper is a stand-in; the MP3 encoder, RNNoise, ONNX Runtime Web
and DeepFilterNet 3 are the real ones.

1. Settings: the engine menu offers Standard and Stronger, Standard on a new site.
2. A take on Standard: RNNoise counts the bell's tones, and the Room line
   suggests the stronger cleanup.
3. Hear the polish: Cleanup offers Settings (Standard), Standard and
   Stronger; Stronger plays Before (Standard) and After (Stronger), and Use
   for this take cleans the take again with it, lowering the background
   further while the voice keeps its level.
4. With Stronger and Strong saved, a new take is cleaned by it, and the Room
   line says so.
5. A model file that fails its fingerprint: the take falls back to Standard,
   and the Room line says why.

Usage: SC_POST=<post id> tests/browser/stronger.py http://localhost:8080
The post must be at least 25 words of plain sentences.
"""
import io, json, math, os, random, re, struct, sys, tempfile, wave
from playwright.sync_api import sync_playwright
from signin import sign_in
from whisper_stub import PIECE
from take_assets import MP3, RNN, ort_runtime

BASE = sys.argv[1].rstrip('/') if len(sys.argv) > 1 else 'http://localhost:8080'
CHROME = os.environ.get('CHROME_PATH', '/usr/bin/google-chrome')
POST_ID = int(os.environ['SC_POST'])
TP = BASE + '/?teleprompter=1&tp_post=%d' % POST_ID
SETTINGS = BASE + '/wp-admin/admin.php?page=rrt-settings'
GLUE, WASM = ort_runtime()

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


def plan(words):
	out = []
	t = 0.4
	for w in words:
		if key(w) == '':
			continue
		out.append((w, t, t + 0.38))
		t += 0.38 + (0.6 if re.search(r'[.!?]$', w) else 0.12)
	return out, t + 1.2


def voice_wav(heard, seconds, seed):
	"""The stand-in voice over a struck bell (1.6, 4.7, 5.2 and 8.9 kHz) and a hiss, 44.1 kHz mono."""
	rate = 44100
	rng = random.Random(seed)
	n = int(seconds * rate)
	x = [(rng.random() - 0.5) * 0.0015 for _ in range(n)]
	parts = [(1605, 1.0), (4693, 0.6), (5220, 0.45), (8853, 0.3)]
	for i in range(n):
		t = i / rate
		x[i] += 0.004 * math.exp(-(t % 3.1) * 0.6) * sum(a * math.sin(2 * math.pi * f * t) for f, a in parts)
	F = [(700, 1220, 2600), (300, 2300, 3000), (500, 900, 2500), (400, 1900, 2550), (650, 1700, 2450)]
	for k, (_, s, e) in enumerate(heard):
		a, b = int(s * rate), min(int(e * rate), n)
		coef = []
		for f, bw in zip(F[k % len(F)], (90, 110, 150)):
			r = math.exp(-math.pi * bw / rate)
			th = 2 * math.pi * f / rate
			coef.append((2 * r * math.cos(th), -r * r, 1 - r))
		st = [[0.0, 0.0] for _ in range(3)]
		f0 = 115 + 10 * math.sin(k)
		ph = 0.0
		for i in range(a, b):
			t = (i - a) / rate
			length = (b - a) / rate
			env = min(1.0, t / 0.03, (length - t) / 0.05)
			ph += f0 * (1 + 0.04 * math.sin(2 * math.pi * 5 * t)) / rate
			src = 1.0 if ph >= 1 else 0.0
			if ph >= 1:
				ph -= 1
			y = 0.0
			for j, (c1, c2, g) in enumerate(coef):
				v = g * src + c1 * st[j][0] + c2 * st[j][1]
				st[j][1] = st[j][0]
				st[j][0] = v
				y += v * (1.0, 0.5, 0.25)[j]
			x[i] += 0.5 * env * y
		if k % 3 == 2:
			m = int(0.08 * rate)
			for i in range(b, min(b + m, n)):
				x[i] += 0.03 * (rng.random() - 0.5) * math.sin(math.pi * (i - b) / m)
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


def save(page, fill):
	page.goto(SETTINGS, wait_until='load')
	fill(page)
	with page.expect_navigation(wait_until='load'):
		page.click('#submit')
	page.goto(SETTINGS, wait_until='load')


def room(page):
	ro = page.evaluate("document.getElementById('tpReadout').textContent")
	m = re.search(r'Room(.*?)(?:$|Endings|Pauses|Pace)', ro, re.S)
	return m.group(1) if m else ro


def denoised(page):
	return page.evaluate("(function () { var m = window.RRTStudio.lastMp3; return m && m.room ? m.room.denoise || null : null; })()")


with sync_playwright() as p:
	browser = p.chromium.launch(executable_path=CHROME if os.path.exists(CHROME) else None, args=['--autoplay-policy=no-user-gesture-required'])
	ctx = browser.new_context(accept_downloads=True)
	page = ctx.new_page()
	errors = []
	page.on('pageerror', lambda e: errors.append(str(e)))
	fake = {'js': ''}
	broken = {'on': False}
	cors = {'Access-Control-Allow-Origin': '*'}
	ctx.route(re.compile(r'.*transformers(\.min)?\.js.*'), lambda r: r.fulfill(status=200, content_type='text/javascript', body=fake['js'], headers=cors))
	ctx.route(re.compile(r'.*/mp3\.wasm.*'), lambda r: r.fulfill(status=200, content_type='application/wasm', body=MP3, headers=cors))
	ctx.route(re.compile(r'.*/rnnoise\.wasm.*'), lambda r: r.fulfill(status=200, content_type='application/wasm', body=RNN, headers=cors))
	# The runtime from jsDelivr (the test site has no Whisper files), answered with the same files from npm.
	ctx.route(re.compile(r'.*cdn\.jsdelivr\.net/npm/onnxruntime-web@.*/ort-wasm-simd-threaded\.mjs$'), lambda r: r.fulfill(status=200, content_type='text/javascript', body=GLUE, headers=cors))
	ctx.route(re.compile(r'.*cdn\.jsdelivr\.net/npm/onnxruntime-web@.*/ort-wasm-simd-threaded\.wasm$'), lambda r: r.fulfill(status=200, content_type='application/wasm', body=WASM, headers=cors))
	ctx.route(re.compile(r'.*/deepfilternet3/enc\.onnx.*'), lambda r: r.fulfill(status=200, body=b'not the model') if broken['on'] else r.continue_())

	sign_in(page, BASE)

	# 1. Settings.
	page.goto(SETTINGS, wait_until='load')
	eng = page.evaluate("(function () { var s = document.getElementById('rrt-sound-engine'); return s ? { v: s.value, opts: Array.prototype.map.call(s.options, function (o) { return o.value + (o.disabled ? ':off' : ''); }) } : null; })()")
	check(eng is not None and eng['v'] == 'standard' and eng['opts'] == ['standard', 'stronger'], 'Settings offers Standard and Stronger, Standard on a new site', json.dumps(eng))

	def standard(pg):
		pg.select_option('#rrt-sound-engine', 'standard')
		pg.select_option('#rrt-sound-denoise', 'medium')
	save(page, standard)

	page.goto(TP, wait_until='load')
	page.evaluate("try { sessionStorage.setItem('rrtIntroSeen', '1'); localStorage.setItem('rrtIntro', 'off'); } catch (e) {}")
	page.goto(TP, wait_until='load')
	page.wait_for_timeout(500)
	page.evaluate("a => { var e = document.querySelector(a); if (!e.checked) { e.click(); } }", '#tpWhisperOpt')
	page.evaluate("a => { var e = document.querySelector(a); if (e && !e.checked) { e.click(); } }", '#tpCleanOpt')
	words = page.evaluate("Array.prototype.map.call(document.querySelectorAll('.tp-w'), function (e) { return e.textContent; })")
	heard, secs = plan(words[:40])
	fake['js'] = fake_transformers(heard)

	def review(seed):
		path = voice_wav(heard, secs, seed)
		page.goto(TP, wait_until='load')
		page.wait_for_timeout(500)
		page.set_input_files('#tpFile', path)
		page.wait_for_function("document.body.classList.contains('is-reviewing')", timeout=180000)
		page.wait_for_timeout(300)

	# 2. Standard, with the bell.
	review(3)
	dn = denoised(page)
	check(dn is not None and dn.get('engine') == 'standard', 'on Standard, RNNoise cleans the take', json.dumps(dn))
	check(dn is not None and dn.get('tones', 0) >= 2, 'it counts the bell\'s steady tones in the pauses', json.dumps(dn))
	rl = room(page)
	check('steady tones in the pauses' in rl and 'Hear the polish' in rl, 'the Room line suggests the stronger cleanup for the bell', rl[-400:])
	std_drop = dn.get('dropDb', 0) if dn else 0

	# 3. Hear the polish compares the two and can use Stronger for this take.
	page.click('#tpRvPolish')
	cl = page.evaluate("(function () { var s = document.querySelector('.tp-polish-cleanup select'); var l = document.querySelector('.tp-polish-cleanup'); return s ? { hidden: l.hidden, opts: Array.prototype.map.call(s.options, function (o) { return o.value + '=' + o.textContent; }) } : null; })()")
	check(cl is not None and not cl['hidden'] and cl['opts'] == ['=Settings (Standard)', 'standard=Standard', 'stronger=Stronger'], 'Hear the polish offers Cleanup: Settings (Standard), Standard, Stronger', json.dumps(cl))
	page.select_option('.tp-polish-cleanup select', 'stronger')
	page.click('#tpPolishPlay')
	page.wait_for_function("document.getElementById('tpPolishPlay').textContent === 'Stop' || /could not/.test(document.getElementById('tpPolishStatus').textContent)", timeout=120000)
	st = page.inner_text('#tpPolishStatus')
	check('Before: Standard cleanup. After: Stronger cleanup.' in st, 'it plays Before with Standard and After with Stronger', st)
	page.click('#tpPolishPlay')
	page.click('#tpPolishUse')
	page.wait_for_function("/made again/.test(document.getElementById('tpPolishStatus').textContent) || /could not/.test(document.getElementById('tpPolishStatus').textContent)", timeout=180000)
	st = page.inner_text('#tpPolishStatus')
	dn2 = denoised(page)
	check('Cleanup: Stronger' in st and dn2 is not None and dn2.get('engine') == 'stronger', 'Use for this take cleans the take again with Stronger', st[:400])
	check(dn2 is not None and dn2.get('dropDb', 0) > std_drop + 3, 'Stronger lowers the background further than Standard, even at Medium', '%.1f against %.1f dB' % (dn2.get('dropDb', 0) if dn2 else 0, std_drop))
	check(dn2 is not None and dn2.get('speechChangeDb') is not None and dn2['speechChangeDb'] > -3, 'the voice keeps its level', json.dumps(dn2))
	page.click('#tpPolishReset')
	page.wait_for_function("/Settings choice\\./.test(document.getElementById('tpPolishStatus').textContent)", timeout=120000)
	dn3 = denoised(page)
	check(dn3 is not None and dn3.get('engine') == 'standard', 'back at the Settings choice, the take is Standard again', json.dumps(dn3))
	page.click('#tpPolishClose')

	# 4. Stronger and Strong in Settings.
	def stronger(pg):
		pg.select_option('#rrt-sound-engine', 'stronger')
		pg.select_option('#rrt-sound-denoise', 'strong')
	save(page, stronger)
	check(page.evaluate("document.getElementById('rrt-sound-engine').value") == 'stronger', 'Stronger is saved')
	review(4)
	dn = denoised(page)
	check(dn is not None and dn.get('engine') == 'stronger' and dn.get('strength') == 'strong', 'a new take is cleaned by the stronger engine at full strength', json.dumps(dn))
	rl = room(page)
	check('The stronger cleanup did this.' in rl, 'the Room line says the stronger cleanup did it', rl[-300:])
	page.click('#tpRvPolish')
	opt0 = page.evaluate("document.querySelector('.tp-polish-cleanup select').options[0].textContent")
	check(opt0 == 'Settings (Stronger)', 'Hear the polish names the Settings choice', opt0)
	page.click('#tpPolishClose')

	# 5. A model file that fails its fingerprint.
	broken['on'] = True
	review(5)
	dn = denoised(page)
	rl = room(page)
	check(dn is not None and dn.get('engine') == 'standard', 'a model that fails its fingerprint falls back to Standard', json.dumps(dn))
	check('could not run this time' in rl and 'fingerprint' in rl and 'standard one was used' in rl, 'and the Room line says why', rl[-400:])
	broken['on'] = False

	save(page, standard)
	check(page.evaluate("document.getElementById('rrt-sound-engine').value") == 'standard', 'Settings is back to Standard and Medium')
	check(not errors, 'no script errors', '; '.join(errors[:3]))
	browser.close()

print('\n%d passed, %d failed' % (passed, len(failed)))
sys.exit(1 if failed else 0)
