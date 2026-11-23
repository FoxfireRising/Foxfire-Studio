#!/usr/bin/env python3
"""
Reading languages (1.33.0), end to end in the studio.

Whisper is a stand-in that answers only when it is asked the right way: an
English post with the English model and no language, as before; a post in
another language with the pinned multilingual model, its language and the
task "transcribe". Any other request fails the review, so a wrong model or a
missing language shows as an error.

Checked: the studio is told each post's language; a Spanish and a Russian
reading read as written review at 100%, and a skipped Russian word is
flagged; an English reading reviews exactly as before; a Spanish filler is a
filler; the pace outside English is a number until three takes, then the
reader's own range; taught words are asked for in the post's language;
Follow my voice shows as English only on a Spanish post and keeps the
reader's choice for English posts; the Controls panel fits at phone width.

Usage: LG_EN=<id> LG_ES=<id> LG_ES2=<id> LG_RU=<id> tests/browser/languages.py http://localhost:8080
LG_SHOTS=<folder> also saves a phone-width screenshot of the Controls panel.
"""
import io, json, math, os, re, struct, sys, tempfile, wave
from playwright.sync_api import sync_playwright
from signin import sign_in
from whisper_stub import PIECE
from take_assets import MP3, RNN

BASE = sys.argv[1].rstrip('/') if len(sys.argv) > 1 else 'http://localhost:8080'
CHROME = os.environ.get('CHROME_PATH', '/usr/bin/google-chrome')
POSTS = {'en': int(os.environ['LG_EN']), 'es': int(os.environ['LG_ES']), 'ru': int(os.environ['LG_RU'])}
if os.environ.get('LG_ES2'):
	POSTS['es2'] = int(os.environ['LG_ES2'])
SHOTS = os.environ.get('LG_SHOTS', '')
MULTI_REV = '608c49e61301901684bc36cac8f74b95ff6b5a8e'

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


def tp(lang):
	return BASE + '/?teleprompter=1&tp_post=%d' % POSTS[lang]


def plan(words, pause=0.6):
	out = []
	t = 0.2
	for w in words:
		bare = re.sub(r'[^\w]', '', w)
		if bare == '':
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


def fake_transformers(heard, lang):
	"""Answers only the right model, revision and language for the post."""
	chunks = [{'text': ' ' + w, 'timestamp': [round(s, 3), round(e, 3)]} for (w, s, e) in heard]
	return (
		'export const env = { backends: { onnx: { wasm: {} } } };\n'
		+ PIECE +
		'const CHUNKS = %s;\n'
		'const LANG = %s;\n'
		'const REV = %s;\n'
		'export async function pipeline(task, model, opts) {\n'
		'  if (LANG === "en") {\n'
		'    if (!/whisper-base\\.en_timestamped$/.test(model) || opts.revision !== undefined) { throw new Error("wrong English model: " + model); }\n'
		'  } else if (!/whisper-base_timestamped$/.test(model) || opts.revision !== REV) { throw new Error("wrong multilingual model: " + model + " " + opts.revision); }\n'
		'  return async function (audio, o) {\n'
		'    const want = LANG === "en" ? JSON.stringify({ return_timestamps: "word" }) : JSON.stringify({ return_timestamps: "word", language: LANG, task: "transcribe" });\n'
		'    if (JSON.stringify(o) !== want) { throw new Error("asked " + JSON.stringify(o)); }\n'
		'    return piece(audio, { chunks: CHUNKS, text: CHUNKS.map(x => x.text).join("") });\n'
		'  };\n'
		'}\n' % (json.dumps(chunks), json.dumps(lang), json.dumps(MULTI_REV))
	)


with sync_playwright() as p:
	browser = p.chromium.launch(executable_path=CHROME if os.path.exists(CHROME) else None, args=['--autoplay-policy=no-user-gesture-required'])
	ctx = browser.new_context(accept_downloads=True)
	page = ctx.new_page()
	errors = []
	page.on('pageerror', lambda e: errors.append(str(e)))
	asked = []
	page.on('request', lambda r: asked.append(r.url) if 'learned' in r.url else None)
	fake = {'js': ''}
	ctx.route(re.compile(r'.*transformers(\.min)?\.js.*'), lambda r: r.fulfill(status=200, content_type='text/javascript', body=fake['js'], headers={'Access-Control-Allow-Origin': '*'}))
	ctx.route(re.compile(r'.*/mp3\.wasm.*'), lambda r: r.fulfill(status=200, content_type='application/wasm', body=MP3, headers={'Access-Control-Allow-Origin': '*'}))
	ctx.route(re.compile(r'.*/rnnoise\.wasm.*'), lambda r: r.fulfill(status=200, content_type='application/wasm', body=RNN, headers={'Access-Control-Allow-Origin': '*'}))

	sign_in(page, BASE)
	page.goto(tp('en'), wait_until='load')
	page.evaluate("""try {
		sessionStorage.setItem('rrtIntroSeen', '1'); localStorage.setItem('rrtIntro', 'off');
		localStorage.setItem('rrtWelcomed', String(Date.now()));
		localStorage.removeItem('rrtLangPace_es'); localStorage.removeItem('rrtLangPace_ru');
		localStorage.setItem('rrtTeleprompter', JSON.stringify({ wmodel: 'base', wm2: true, whisper: true, voice: true, rec: false }));
	} catch (e) {}""")

	def open_studio(lang):
		page.goto(tp(lang), wait_until='load')
		page.wait_for_timeout(400)
		page.wait_for_function("window.RRTStudio && window.RRTStudio.W && window.RRTStudio.W.length > 0", timeout=10000)

	def script_words():
		return page.evaluate("Array.prototype.map.call(document.querySelectorAll('#tpTrack .tp-w'), function (e) { return e.textContent; })")

	def review(lang, words):
		heard, secs = plan(words)
		fake['js'] = fake_transformers(heard, lang)
		path = wav_for(heard, secs)
		page.evaluate("a => { var e = document.querySelector(a); if (!e.checked) { e.click(); } }", '#tpWhisperOpt')
		page.set_input_files('#tpFile', path)
		page.wait_for_function("/full review is ready|could not|Error|error/.test(document.getElementById('tpWhisperStatus').textContent)", timeout=120000)
		return page.evaluate("document.getElementById('tpWhisperStatus').textContent")

	def head():
		return page.evaluate("(document.getElementById('tpRvHeadInfo') || {}).textContent || ''")

	def notes():
		return page.evaluate("(document.getElementById('tpRvNotes') || {}).textContent || ''")

	# English, exactly as before.
	open_studio('en')
	check(page.evaluate("RRT.lang && RRT.lang.code === 'en' && RRT.lang.en === true"), 'an English post tells the studio it is English')
	check(page.evaluate("RRTCompare.lang()") == 'en', 'the review compares English words the English way')
	check(not page.evaluate("RRTStudio.VOICE_LATER"), 'Follow my voice is not held back on an English post')
	words = script_words()
	st = review('en', [w.lower() for w in words])
	check('full review is ready' in st, 'an English reading reviews with the English model, asked as before', st[-200:])
	check(head().startswith('100% of words read as written'), 'and every word is read as written', head())

	# Spanish.
	open_studio('es')
	check(page.evaluate("RRT.lang.code === 'es' && RRT.lang.en === false && RRT.lang.name === 'Spanish'"), 'a Spanish post tells the studio it is Spanish')
	check(page.evaluate("RRTCompare.lang()") == 'es', 'the review compares Spanish words')
	src = page.evaluate("(document.querySelector('script[src*=\"studio-core.js\"]') || {}).src || ''")
	check(re.search(r'studio-core\.js\?ver=\d+\.\d+\.\d+\.\d{9,}', src) is not None, 'the studio\'s scripts carry their file\'s time, so a phone fetches a changed file at once', src)
	if page.evaluate("RRTStudio.VOICE_LATER"):
		check(page.is_disabled('#tpVoice') and not page.is_checked('#tpVoice'), 'Follow my voice is switched off on a Spanish post')
		check('English readings only for now' in page.inner_text('#tpVoiceWrap'), 'and says it is for English readings only for now', page.inner_text('#tpVoiceWrap'))
		check(page.evaluate("JSON.parse(localStorage.getItem('rrtTeleprompter')).voice") is True, 'the reader\'s choice for English posts is kept')
	else:
		print('skip Follow my voice (not available in this browser)')
	words = script_words()
	check('río.' in words and 'búho' in words, 'Spanish words keep their accents in the script', json.dumps(words[:8], ensure_ascii=False))
	st = review('es', [w.lower() for w in words])
	check('full review is ready' in st, 'a Spanish reading reviews with the multilingual model, in Spanish, as a transcription', st[-200:])
	check(head().startswith('100% of words read as written'), 'a Spanish reading read as written is all correct', head())
	check('After 3 more takes in Spanish' in notes() or 'After 2 more takes in Spanish' in notes(), 'the pace is a number until there are three Spanish takes', notes()[:300])
	check(page.evaluate("JSON.parse(localStorage.getItem('rrtLangPace_es') || '[]').length") == 1, 'the take\'s pace is kept for Spanish')
	check(any('lang=es' in u for u in asked), 'taught words are asked for in Spanish', ' '.join(asked[-3:]))
	page.evaluate("localStorage.setItem('rrtLangPace_es', JSON.stringify([150, 150, 150]))")
	open_studio('es')
	with_filler = [w.lower() for w in words]
	with_filler.insert(3, 'eh')
	st = review('es', with_filler)
	check('full review is ready' in st, 'a second Spanish review', st[-200:])
	check('your Spanish range of' in notes(), 'with three Spanish takes, the pace is judged against the reader\'s own', notes()[:300])
	fillers = page.evaluate("RRTStudio.rv && RRTStudio.rv.R ? RRTStudio.rv.R.counts.filler : -1")
	check(fillers == 1, 'a Spanish "eh" is a filler', str(fillers))

	# Russian.
	open_studio('ru')
	check(page.evaluate("RRT.lang.code === 'ru'"), 'a Russian post tells the studio it is Russian')
	keys = page.evaluate("RRTStudio.W.filter(function (w) { return w.kind !== 'title'; }).slice(0, 4).map(function (w) { return w.key; })")
	check(keys[:2] == ['совы', 'тихо'], 'the script\'s Russian words keep their letters', json.dumps(keys, ensure_ascii=False))
	words = script_words()
	st = review('ru', [w.lower() for w in words])
	check(head().startswith('100% of words read as written'), 'a Russian reading read as written is all correct', head() + ' ' + st[-120:])
	open_studio('ru')
	skipped = [w.lower() for w in words]
	skipped.remove('тихо')
	st = review('ru', skipped)
	probs = page.evaluate("RRTStudio.rv && RRTStudio.rv.R ? RRTStudio.rv.R.problems.map(function (p) { return p.kind + ':' + (p.detail || ''); }) : []")
	check(any(p.startswith('omit:') and 'тихо' in p for p in probs), 'a skipped Russian word is flagged', json.dumps(probs, ensure_ascii=False))

	# Phone width: the Controls panel with the English-only note fits.
	page.set_viewport_size({'width': 393, 'height': 852})
	open_studio('es')
	page.evaluate("document.querySelectorAll('dialog[open]').forEach(function (d) { try { d.close(); } catch (e) {} })")
	if page.locator('#tpPanelToggle').count() and page.is_visible('#tpPanelToggle'):
		page.click('#tpPanelToggle')
		page.wait_for_timeout(300)
	wide = page.evaluate("() => { var p = document.querySelector('.tp-panel'); return p ? p.scrollWidth - p.clientWidth : 0; }")
	check(wide <= 1, 'the Controls panel does not slide sideways at phone width', str(wide))
	if SHOTS:
		page.evaluate("var v = document.getElementById('tpVoiceWrap'); if (v) { v.scrollIntoView({ block: 'center' }); }")
		page.screenshot(path=os.path.join(SHOTS, 'languages-phone.png'))

	# The end of the script with Follow my voice off (1.33.0): the recording waits for Finish.
	heard, secs = plan(script_words())
	mic = wav_for(heard, secs + 20)
	b2 = p.chromium.launch(executable_path=CHROME if os.path.exists(CHROME) else None, args=['--autoplay-policy=no-user-gesture-required', '--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream', '--use-file-for-fake-audio-capture=' + mic])
	c2 = b2.new_context()
	page = c2.new_page()
	page.on('pageerror', lambda e: errors.append(str(e)))
	c2.route(re.compile(r'.*/mp3\.wasm.*'), lambda r: r.fulfill(status=200, content_type='application/wasm', body=MP3, headers={'Access-Control-Allow-Origin': '*'}))
	c2.route(re.compile(r'.*/rnnoise\.wasm.*'), lambda r: r.fulfill(status=200, content_type='application/wasm', body=RNN, headers={'Access-Control-Allow-Origin': '*'}))
	sign_in(page, BASE)
	page.goto(tp('es'), wait_until='load')
	page.evaluate("""try {
		sessionStorage.setItem('rrtIntroSeen', '1'); localStorage.setItem('rrtIntro', 'off');
		localStorage.setItem('rrtWelcomed', String(Date.now()));
		localStorage.setItem('rrtTeleprompter', JSON.stringify({ wmodel: 'base', wm2: true, whisper: false, clean: false, voice: true, rec: true, wpm: 400, phoneTake: false }));
	} catch (e) {}""")
	held = 'es2' if 'es2' in POSTS else 'es'
	if held == 'es2':
		page.set_viewport_size({'width': 393, 'height': 852})
	open_studio(held)
	page.evaluate("document.querySelectorAll('dialog[open]').forEach(function (d) { try { d.close(); } catch (e) {} })")
	page.evaluate("document.getElementById('tpPlay').click()")
	page.wait_for_function("!document.getElementById('tpFinish').hidden", timeout=15000)
	pace_msg = page.evaluate("document.getElementById('tpMsg') ? document.getElementById('tpMsg').textContent : ''")
	page.wait_for_function("window.RRTStudio.heldAtEnd === true", timeout=30000)
	page.wait_for_timeout(3000)
	st = page.evaluate("[RRTStudio.recorder ? RRTStudio.recorder.state : 'none', document.getElementById('tpPlay').textContent, document.getElementById('tpFinish').hidden, document.body.classList.contains('is-held')]")
	check(st == ['recording', 'Finish', False, True], 'with Follow my voice off, the end of the script keeps the recording going and the main button reads Finish', json.dumps(st))
	msg = page.evaluate("document.getElementById('tpMsg') ? document.getElementById('tpMsg').textContent : document.body.innerText")
	check('press Finish recording after your last word' in msg, 'and says to press Finish recording after the last word', msg[-200:])
	check(page.evaluate("RRTStudio.beginFrom ? RRTStudio.beginFrom(0) === false : true"), 'a new take cannot start over the open recording')
	check('Follow my voice' not in pace_msg and 'lower Pace' in pace_msg, 'outside English the pace line does not offer Follow my voice, which cannot be switched on', pace_msg)
	if 'es2' in POSTS:
		# At phone width the script scrolls back so the long last paragraph fills the room above the message.
		view = page.evaluate("""() => {
			var W = RRTStudio.W, last = W.length - 1, first = last;
			while (first > 0 && W[first - 1].block === W[last].block) { first--; }
			var st = document.getElementById('tpStage').getBoundingClientRect();
			var cover = st.bottom;
			[document.getElementById('tpMsg'), document.querySelector('.tp-dock')].forEach(function (e) {
				if (!e) { return; }
				var r = e.getBoundingClientRect();
				if (r.height && r.top < cover && r.top > st.top) { cover = r.top; }
			});
			var seen = 0;
			for (var k = first; k <= last; k++) {
				var r = W[k].el.getBoundingClientRect();
				if (r.top >= st.top - 1 && r.bottom <= cover + 1) { seen++; }
			}
			var lr = W[last].el.getBoundingClientRect();
			return { seen: seen, words: last - first + 1, lastBottom: lr.bottom, cover: cover, top: st.top };
		}""")
		check(view['lastBottom'] <= view['cover'] + 1, 'at phone width the last word stays above the message', json.dumps(view))
		check(view['lastBottom'] > view['top'] + (view['cover'] - view['top']) * 0.6, 'and the paragraph is brought back to fill the room above it, so a reader still behind can see what is left', json.dumps(view))
	page.evaluate("document.getElementById('tpPlay').click()")
	page.wait_for_function("document.getElementById('tpResult').classList.contains('is-on')", timeout=60000)
	st = page.evaluate("[RRTStudio.heldAtEnd, document.getElementById('tpPlay').textContent, document.getElementById('tpFinish').hidden, document.body.classList.contains('is-held')]")
	check(st == [False, 'Again', True, False], 'Finish ends the take and the studio is finished as before', json.dumps(st))
	b2.close()
	check(not errors, 'no script errors in the studio', '; '.join(errors))

print('\n%d passed, %d failed' % (passed, len(failed)))
sys.exit(1 if failed else 0)
