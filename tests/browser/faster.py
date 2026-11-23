#!/usr/bin/env python3
"""
The faster review (1.17.0), end to end.

The studio asks the browser for a graphics chip (WebGPU) with half
precision. Headless Chrome has none, so a stand-in navigator.gpu gives one
(or one without half precision, or a software one), chosen by the test
before each page load. Whisper is a stand-in that only answers the graphics
chip when it is asked for the larger model with the half-precision encoder,
the 4-bit half-precision decoder and the pinned revision, and can be told
to fail there, so the processor fallback is seen too.

Checked: the Model menu offers Automatic, Standard and Larger, and starts on
Automatic, also for a browser that saved the old default; Automatic reviews
with the larger model on the graphics chip where there is one, says so while
listening and in the result, and uses the standard model without one, with
a software one, without half precision, and for opening drills; a failure
on the graphics chip carries on with the processor and says so; Larger on a
computer without the graphics chip runs on the processor as before.

Usage: FR_POST=<post id> tests/browser/faster.py http://localhost:8080
"""
import io, json, math, os, re, struct, sys, tempfile, wave
from playwright.sync_api import sync_playwright
from signin import sign_in
from whisper_stub import PIECE
from take_assets import MP3, RNN

BASE = sys.argv[1].rstrip('/') if len(sys.argv) > 1 else 'http://localhost:8080'
CHROME = os.environ.get('CHROME_PATH', '/usr/bin/google-chrome')
POST_ID = int(os.environ['FR_POST'])
TP = BASE + '/?teleprompter=1&tp_post=%d' % POST_ID
REV = '8085393831131554ad978a4e438295a734d3d2c0'

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
	out = []
	t = 0.2
	for w in words:
		if key(w) == '':
			continue
		out.append((w, t, t + 0.32))
		t += 0.32 + (pause if re.search(r'[.!?]$', w) else 0.12)
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
	f = tempfile.NamedTemporaryFile(suffix='.wav', delete=False)
	f.write(buf.getvalue())
	f.close()
	return f.name


def fake_transformers(heard, fail_gpu):
	"""Answers the take; on the graphics chip only with the right files, after a pause so the status line can be read."""
	chunks = [{'text': ' ' + w, 'timestamp': [round(s, 3), round(e, 3)]} for (w, s, e) in heard]
	return (
		'export const env = { backends: { onnx: { wasm: {} } } };\n'
		+ PIECE +
		'const CHUNKS = %s;\n'
		'const FAIL_GPU = %s;\n'
		'export async function pipeline(task, model, opts) {\n'
		'  if (opts.device === "webgpu") {\n'
		'    const d = opts.dtype || {};\n'
		'    if (!/whisper-small\\.en_timestamped$/.test(model) || d.encoder_model !== "fp16" || d.decoder_model_merged !== "q4f16" || opts.revision !== %s) { throw new Error("wrong graphics chip files: " + model + " " + JSON.stringify(opts)); }\n'
		'    if (FAIL_GPU) { throw new Error("device lost"); }\n'
		'  } else if (opts.dtype !== "q8" || opts.device !== "wasm") { throw new Error("wrong processor files"); }\n'
		'  const slow = opts.device === "webgpu" ? 2500 : 0;\n'
		'  return async function (audio) { await new Promise(r => setTimeout(r, slow)); return piece(audio, { chunks: CHUNKS, text: CHUNKS.map(x => x.text).join("") }); };\n'
		'}\n' % (json.dumps(chunks), 'true' if fail_gpu else 'false', json.dumps(REV))
	)


# Read before each page loads: which graphics chip the page is given ('' for the browser's own, which is none here).
GPU_STUB = """
(() => {
	var mode = '';
	try { mode = localStorage.getItem('frGpu') || ''; } catch (e) {}
	if (!mode) { return; }
	var adapter = mode === 'none' ? null : {
		features: new Set(mode === 'nof16' ? [] : ['shader-f16']),
		info: { vendor: 'test', architecture: 'test', isFallbackAdapter: mode === 'soft' },
		isFallbackAdapter: mode === 'soft'
	};
	Object.defineProperty(Navigator.prototype, 'gpu', { configurable: true, get: function () { return { requestAdapter: function () { return Promise.resolve(adapter); } }; } });
})();
"""


with sync_playwright() as p:
	browser = p.chromium.launch(executable_path=CHROME if os.path.exists(CHROME) else None, args=['--autoplay-policy=no-user-gesture-required'])
	ctx = browser.new_context(accept_downloads=True)
	ctx.add_init_script(GPU_STUB)
	page = ctx.new_page()
	errors = []
	page.on('pageerror', lambda e: errors.append(str(e)))
	fake = {'js': ''}
	ctx.route(re.compile(r'.*transformers(\.min)?\.js.*'), lambda r: r.fulfill(status=200, content_type='text/javascript', body=fake['js'], headers={'Access-Control-Allow-Origin': '*'}))
	ctx.route(re.compile(r'.*/mp3\.wasm.*'), lambda r: r.fulfill(status=200, content_type='application/wasm', body=MP3, headers={'Access-Control-Allow-Origin': '*'}))
	ctx.route(re.compile(r'.*/rnnoise\.wasm.*'), lambda r: r.fulfill(status=200, content_type='application/wasm', body=RNN, headers={'Access-Control-Allow-Origin': '*'}))

	sign_in(page, BASE)
	page.goto(TP, wait_until='load')
	page.evaluate("try { sessionStorage.setItem('rrtIntroSeen', '1'); localStorage.setItem('rrtIntro', 'off'); localStorage.removeItem('frGpu'); } catch (e) {}")

	def open_studio(gpu='', saved=None):
		"""The studio with the given graphics chip and, if given, a saved teleprompter setting to start from."""
		page.evaluate("""a => {
			if (a.gpu) { localStorage.setItem('frGpu', a.gpu); } else { localStorage.removeItem('frGpu'); }
			if (a.saved) { localStorage.setItem('rrtTeleprompter', JSON.stringify(a.saved)); }
		}""", {'gpu': gpu, 'saved': saved})
		page.goto(TP, wait_until='load')
		page.wait_for_timeout(400)
		page.wait_for_function("window.RRTStudio && window.RRTStudio.gpuOk !== null", timeout=10000)

	def menu():
		return page.evaluate("() => { var s = document.getElementById('tpWModel'); return { value: s.value, options: Array.prototype.map.call(s.options, function (o) { return o.value; }), shown: !s.parentNode.hidden }; }")

	def pick(value):
		page.evaluate("v => { var s = document.getElementById('tpWModel'); s.value = v; s.dispatchEvent(new Event('change')); }", value)

	# The menu and the old saved default.
	open_studio(saved={'wmodel': 'base', 'whisper': True})
	m = menu()
	check(m['options'] == ['auto', 'base', 'small'] and m['shown'], 'the Model menu offers Automatic, Standard and Larger', json.dumps(m))
	check(m['value'] == 'auto', 'a browser that saved the old default starts on Automatic', json.dumps(m))
	pick('base')
	open_studio()
	check(menu()['value'] == 'base', 'Standard chosen since 1.17.0 stays Standard')
	open_studio(saved={'wmodel': 'small', 'whisper': True})
	check(menu()['value'] == 'small', 'a browser that chose Larger before keeps Larger')
	pick('auto')

	words = page.evaluate("Array.prototype.map.call(document.querySelectorAll('.tp-w'), function (e) { return e.textContent; })")
	heard, secs = plan(words)
	path = wav_for(heard, secs)

	def review(gpu, fail_gpu=False, watch=False):
		fake['js'] = fake_transformers(heard, fail_gpu)
		open_studio(gpu)
		page.evaluate("a => { var e = document.querySelector(a); if (!e.checked) { e.click(); } }", '#tpWhisperOpt')
		page.set_input_files('#tpFile', path)
		seen = ''
		if watch:
			page.wait_for_function("/graphics chip/.test(document.getElementById('tpWhisperStatus').textContent)", timeout=60000)
			seen = page.evaluate("document.getElementById('tpWhisperStatus').textContent")
		page.wait_for_function("/full review is ready/.test(document.getElementById('tpWhisperStatus').textContent)", timeout=120000)
		return seen, page.evaluate("document.getElementById('tpWhisperStatus').textContent")

	# Automatic, without a graphics chip (headless Chrome has none).
	open_studio()
	check(page.evaluate("RRTStudio.gpuOk") is False and page.evaluate("RRTStudio.wantModel()") == 'base', 'without a graphics chip, Automatic picks the standard model')
	_, st = review('')
	check('larger model' not in st and 'graphics chip' not in st, 'and the review says nothing about a graphics chip', st[-200:])

	# Automatic, with one.
	seen, st = review('f16', watch=True)
	check(seen.startswith('Listening on the graphics chip'), 'while it listens, the status line says it is on the graphics chip', seen[:160])
	check('Whisper larger model on the graphics chip' in st, 'with a graphics chip, Automatic reviews with the larger model there, and the result says so', st[-220:])
	check(page.evaluate("RRTStudio.usedModel") == 'onnx-community/whisper-small.en_timestamped', 'the review is recorded as the larger model\'s')
	check(page.evaluate("() => { RRTStudio.takeDrill = 'opening'; var w = RRTStudio.wantModel(); RRTStudio.takeDrill = ''; return w; }") == 'base', 'an opening drill keeps the standard model under Automatic')

	# Not a usable graphics chip.
	for mode, why in (('nof16', 'without half precision'), ('soft', 'with a software one'), ('none', 'when the browser finds none')):
		open_studio(mode)
		check(page.evaluate("RRTStudio.gpuOk") is False and page.evaluate("RRTStudio.wantModel()") == 'base', 'Automatic picks the standard model ' + why)

	# The graphics chip fails: Automatic carries on with the standard model on the processor.
	_, st = review('f16', fail_gpu=True)
	check('the graphics chip could not run it, so the processor was used' in st and 'larger model' not in st, 'when the graphics chip fails, Automatic carries on with the standard model on the processor and says so', st[-260:])
	check(page.evaluate("RRTStudio.usedModel") == 'onnx-community/whisper-base.en_timestamped', 'and the review is the standard model\'s')
	check(page.evaluate("RRTStudio.wantModel()") == 'base', 'a failed graphics chip is not tried again on this page')

	# Larger chosen: on the graphics chip, and on the processor when it fails.
	open_studio('f16')
	pick('small')
	check(page.evaluate("RRTStudio.wantModel()") == 'gpu', 'Larger runs on the graphics chip where it can')
	check('graphics chip' in page.evaluate("document.getElementById('tpMsg').textContent"), 'choosing Larger there says it runs on the graphics chip')
	_, st = review('f16', fail_gpu=True)
	check('larger model, ' in st and 'the graphics chip could not run it' in st, 'when it fails, Larger carries on with the larger model on the processor', st[-260:])
	open_studio()
	check(page.evaluate("RRTStudio.wantModel()") == 'small', 'without a graphics chip, Larger runs on the processor as before')
	pick('auto')
	check('cannot run Whisper on a graphics chip' in page.evaluate("document.getElementById('tpMsg').textContent"), 'choosing Automatic without a graphics chip says the standard model will be used')

	# 1.30.0: Automatic times the larger model on a long take and hands over to the standard one when it is slower than the take.
	long_words = []
	while True:
		long_words += words
		lh, ls = plan(long_words)
		if ls >= 70:
			break
	long_path = wav_for(lh, ls)
	fake['js'] = fake_transformers(lh, False)
	open_studio('f16')
	pick('auto')
	check(page.evaluate("RRTStudio.wantModel()") == 'gpu', 'before any timing, Automatic uses the larger model on the graphics chip')
	# The stand-in takes 2.5 s a piece; at a twentieth of real time that counts as slower than the take.
	page.evaluate("RRTStudio.gpuPaceLimit = 0.05")
	page.evaluate("a => { var e = document.querySelector(a); if (!e.checked) { e.click(); } }", '#tpWhisperOpt')
	page.set_input_files('#tpFile', long_path)
	page.wait_for_function("/full review is ready/.test(document.getElementById('tpWhisperStatus').textContent)", timeout=240000)
	st = page.evaluate("document.getElementById('tpWhisperStatus').textContent")
	check('the larger model ran slower than the take' in st and 'standard model finished the review' in st, 'a larger model slower than the take hands over to the standard model, and the result says so', st[-300:])
	check(page.evaluate("RRTStudio.usedModel") == 'onnx-community/whisper-base.en_timestamped', 'the rest of the take is heard by the standard model')
	got = page.evaluate("(RRTStudio.rv && RRTStudio.rv.last ? RRTStudio.rv.last.chunks : []).map(c => c.timestamp[0])")
	check(len(got) >= len(lh) * 0.9 and all(got[i] <= got[i + 1] for i in range(len(got) - 1)), 'the words of both models join in time order with none lost', '%d of %d' % (len(got), len(lh)))
	check(page.evaluate("JSON.parse(localStorage.getItem('rrtTeleprompter')).gpuSlow") is True, 'the browser remembers it')
	open_studio('f16')
	check(page.evaluate("RRTStudio.wantModel()") == 'base', 'so the next review starts with the standard model')
	pick('base')
	pick('auto')
	check(page.evaluate("RRTStudio.wantModel()") == 'gpu' and page.evaluate("JSON.parse(localStorage.getItem('rrtTeleprompter')).gpuSlow") is False, 'choosing Automatic again times the larger model afresh')

	page.evaluate("try { localStorage.removeItem('frGpu'); } catch (e) {}")
	check(not errors, 'no page errors', '; '.join(errors[:3]))

	# 1.31.0: the review's Pace line takes the At a Glance tile's number (Whisper's words, first to last).
	pm = page.evaluate("() => { var m = { wpm: 129, setWpm: 140, restShare: 0.2 }; var o = RRTStudio.paceFromReview(m, { wpm: 131 }); return [o.wpm, o.setWpm, m.wpm, RRTStudio.paceFromReview(m, null).wpm]; }")
	check(pm == [131, 140, 129, 129], 'the review\'s Pace line uses the tile\'s number, and the read-out at Stop keeps its own', pm)

	# --- 1.31.0: a phone, decided by touch (any browser), at iPhone width ---
	ph = browser.new_context(accept_downloads=True, viewport={'width': 393, 'height': 852}, is_mobile=True, has_touch=True, storage_state=ctx.storage_state())
	ph.add_init_script(GPU_STUB)
	ph.route(re.compile(r'.*transformers(\.min)?\.js.*'), lambda r: r.fulfill(status=200, content_type='text/javascript', body=fake['js'], headers={'Access-Control-Allow-Origin': '*'}))
	ph.route(re.compile(r'.*/mp3\.wasm.*'), lambda r: r.fulfill(status=200, content_type='application/wasm', body=MP3, headers={'Access-Control-Allow-Origin': '*'}))
	ph.route(re.compile(r'.*/rnnoise\.wasm.*'), lambda r: r.fulfill(status=200, content_type='application/wasm', body=RNN, headers={'Access-Control-Allow-Origin': '*'}))
	page = ph.new_page()
	perrors = []
	page.on('pageerror', lambda e: perrors.append(str(e)))
	page.goto(TP, wait_until='load')
	page.evaluate("try { sessionStorage.setItem('rrtIntroSeen', '1'); localStorage.setItem('rrtIntro', 'off'); } catch (e) {}")
	open_studio('f16', saved={'wmodel': 'auto', 'whisper': True})
	check(page.evaluate("RRTStudio.PHONE") is True, 'a touch-first device counts as a phone, whatever its browser says it is')
	m = menu()
	label = page.evaluate("document.querySelector('#tpWModel option[value=auto]').textContent")
	check(m['shown'] and 'Automatic (standard model)' == label.strip(), 'on a phone the Model menu is shown, and Automatic says it means the standard model', label)
	check(page.evaluate("RRTStudio.wantModel()") == 'base', 'on a phone Automatic uses the standard model, even with a graphics chip')
	pick('small')
	check(page.evaluate("RRTStudio.wantModel()") == 'gpu', 'Larger chosen by hand on a phone may use its graphics chip')
	pick('auto')
	check('standard model' in page.evaluate("document.getElementById('tpMsg').textContent"), 'choosing Automatic on a phone says the standard model will be used')

	# A short take reviews by itself on the lighter path, keeps its take for the shelf, and goes on it.
	fake['js'] = fake_transformers(heard, False)
	page.evaluate("a => { var e = document.querySelector(a); if (!e.checked) { e.click(); } }", '#tpWhisperOpt')
	page.set_input_files('#tpFile', path)
	page.wait_for_function("/full review is ready/.test(document.getElementById('tpWhisperStatus').textContent)", timeout=120000)
	st = page.evaluate("document.getElementById('tpWhisperStatus').textContent")
	check(page.evaluate("RRTStudio.usedModel") == 'onnx-community/whisper-base.en_timestamped', 'a short phone take is reviewed by itself with the standard model')
	check(page.evaluate("RRTStudio.reviewHeldFull") is True, 'a short phone take keeps its full-rate copy for the shelf')
	page.wait_for_function("/Kept on/.test(document.getElementById('tpWhisperStatus').textContent)", timeout=30000)
	st = page.evaluate("document.getElementById('tpWhisperStatus').textContent")
	check('Combine takes needs a computer' in st or 'Continue starts' in st, 'it goes on the shelf, and the line speaks of Continue or says Combine takes is for a computer', st[-200:])
	check(page.evaluate("document.getElementById('tpCombineOpen').hidden") is True, 'Combine takes is not offered on a phone')

	# A take over the limit waits to be asked, then lets its full-rate copy go while Whisper listens.
	open_studio('f16')
	page.evaluate("RRTStudio.PHONE_AUTO_S = 1")
	page.evaluate("a => { var e = document.querySelector(a); if (!e.checked) { e.click(); } }", '#tpWhisperOpt')
	page.set_input_files('#tpFile', path)
	page.wait_for_selector('.tp-wtry', timeout=120000)
	check('needs more memory than phones' in page.evaluate("document.getElementById('tpWhisperStatus').textContent"), 'a longer phone take waits to be asked, as before')
	page.evaluate("document.querySelector('.tp-wtry').click()")
	page.wait_for_function("/full review is ready/.test(document.getElementById('tpWhisperStatus').textContent)", timeout=120000)
	check(page.evaluate("RRTStudio.reviewHeldFull") is False, 'Try it here anyway lets the full-rate copy go before Whisper listens')
	page.wait_for_timeout(1500)
	check('Kept on' not in page.evaluate("document.getElementById('tpWhisperStatus').textContent"), 'and a long phone take stays off the shelf')

	# The page closed during a review with Larger: the next visit goes back to Automatic.
	page.evaluate("() => { var s = JSON.parse(localStorage.getItem('rrtTeleprompter') || '{}'); s.wmodel = 'small'; localStorage.setItem('rrtTeleprompter', JSON.stringify(s)); localStorage.setItem('rrtWhisperRunning', JSON.stringify({ at: Date.now() })); }")
	open_studio('f16')
	check(menu()['value'] == 'auto', 'a phone whose page closed during a review with Larger goes back to Automatic')
	check(not perrors, 'no page errors on the phone', '; '.join(perrors[:3]))
	ph.close()
	browser.close()

print('\nfaster.py: %d passed, %d failed' % (passed, len(failed)))
sys.exit(1 if failed else 0)
