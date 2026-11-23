#!/usr/bin/env python3
"""
A whole take through the studio, end to end (0.25.1): a recording is opened
in the teleprompter and goes through cleanup, the MP3, the read-out, Whisper
and the comparison, and the review must show what was wrong with it.

Whisper is a stand-in that "hears" the test post with one word left out, a
filler added and two long pauses, one of them marked as a cue. The MP3
encoder and noise model are the real ones from npm (checked against the
plugin's fingerprints), served where the page asks for them.

Also checks Load from URL with an address written differently from the
site's own (0.25.1 fix), and (0.25.2) the downloads with and without the
music bed (set up by tests/wp/music.php), Edit audio's Tidy, cut and undo,
and the edited MP3.

Usage: tests/browser/take.py http://localhost:8080
"""
import io, json, math, os, re, struct, sys, tempfile, urllib.request, wave
from playwright.sync_api import sync_playwright
from signin import sign_in
from take_assets import MP3, RNN
from whisper_stub import PIECE

BASE = sys.argv[1].rstrip('/') if len(sys.argv) > 1 else 'http://localhost:8080'
CHROME = os.environ.get('CHROME_PATH', '/usr/bin/google-chrome')
HERE = os.path.dirname(os.path.abspath(__file__))
PLUGIN = os.path.join(HERE, '..', '..', 'plugin', 'foxfire-studio')

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



with urllib.request.urlopen(BASE + '/?rest_route=/wp/v2/posts&search=The%20Test%20Reading&per_page=1') as r:
	post = json.loads(r.read())[0]
POST_ID = post['id']
POST_URL = post['link']
TP = BASE + '/?teleprompter=1&tp_post=%d' % POST_ID


MISHEARD = {'river': 'water', 'called': 'cold'}


def plan(words):
	"""What the stand-in hears: (text, start, end), with the slips built in."""
	out = []
	t = 1.0
	keys = [re.sub(r'[^a-z0-9]', '', w.lower()) for w in words]
	for i, w in enumerate(words):
		if keys[i] == '':
			continue
		if keys[i] == 'hill':
			continue  # skipped
		if keys[i] in MISHEARD:
			w = MISHEARD[keys[i]]  # misheard (1.1.0): one gets a pronunciation note, one is taught without
		out.append((w, t, t + 0.32))
		t += 0.32 + 0.12
		if keys[i] == 'fox':
			out.append(('um', t, t + 0.25))  # filler
			t += 0.25 + 0.12
		if keys[i] == 'moon':
			t += 2.6  # a long pause, marked as a cue
		if keys[i] == 'part':
			t += 2.6  # a long pause, not marked
	return out, t + 1.0


def wav_for(heard, seconds):
	"""Tone bursts where the words are, over a quiet hush, 44.1 kHz mono."""
	rate = 44100
	n = int(seconds * rate)
	x = [0.0] * n
	seed = 11
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


RETAKE = ['under', 'under', 'the', 'moon.']


def fake_transformers(heard):
	"""The take's words for the take; for a short retake (under 12 s), a false start on its first word."""
	chunks = [{'text': ' ' + w, 'timestamp': [round(s, 3), round(e, 3)]} for (w, s, e) in heard]
	# The edited version (1.1.0): shorter than the take, heard without the filler Tidy cut.
	edited = [c for c in chunks if c['text'].strip() != 'um']
	return (
		'export const env = { backends: { onnx: { wasm: {} } } };\n'
		+ PIECE +
		'const CHUNKS = %s;\n'
		'const EDITED = %s;\n'
		'const RETAKE = %s;\n'
		'let first = 0;\n'
		'export async function pipeline() {\n'
		'  return async function (audio) {\n'
		'    const d = total(audio);\n'
		'    if (d >= 12) {\n'
		'      if (!first) { first = d; }\n'
		'      const c = d < first - 0.3 ? EDITED : CHUNKS;\n'
		'      return piece(audio, { chunks: c, text: c.map(x => x.text).join("") });\n'
		'    }\n'
		'    const c = RETAKE.map((w, i) => ({ text: " " + w, timestamp: [d * (0.1 + i * 0.2), d * (0.1 + i * 0.2) + d * 0.12] }));\n'
		'    return piece(audio, { chunks: c, text: c.map(x => x.text).join("") });\n'
		'  };\n'
		'}\n' % (json.dumps(chunks), json.dumps(edited), json.dumps(RETAKE))
	)


def mic_wav():
	"""The stand-in microphone: word-like bursts with short gaps, 6 s, looped by Chrome."""
	rate = 48000
	n = rate * 6
	x = [0.0] * n
	for k in range(15):
		a = int((0.2 + k * 0.38) * rate)
		b = a + int(0.24 * rate)
		for i in range(a, min(b, n)):
			env = min(1.0, (i - a) / 500.0, (b - i) / 500.0)
			x[i] = 0.35 * env * math.sin(2 * math.pi * (160 + 15 * k) * (i - a) / rate)
	buf = io.BytesIO()
	w = wave.open(buf, 'wb')
	w.setnchannels(1)
	w.setsampwidth(2)
	w.setframerate(rate)
	w.writeframes(b''.join(struct.pack('<h', int(v * 32767)) for v in x))
	w.close()
	f = tempfile.NamedTemporaryFile(suffix='.wav', delete=False)
	f.write(buf.getvalue())
	f.close()
	return f.name


with sync_playwright() as p:
	MIC = mic_wav()
	browser = p.chromium.launch(executable_path=CHROME if os.path.exists(CHROME) else None, args=['--autoplay-policy=no-user-gesture-required', '--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream', '--use-file-for-fake-audio-capture=' + MIC])
	ctx = browser.new_context(permissions=['microphone'])
	page = ctx.new_page()
	errors = []
	page.on('pageerror', lambda e: errors.append(str(e)))
	fake = {'js': ''}
	ctx.route(re.compile(r'.*transformers(\.min)?\.js.*'), lambda r: r.fulfill(status=200, content_type='text/javascript', body=fake['js'], headers={'Access-Control-Allow-Origin': '*'}))
	ctx.route(re.compile(r'.*/mp3\.wasm.*'), lambda r: r.fulfill(status=200, content_type='application/wasm', body=MP3, headers={'Access-Control-Allow-Origin': '*'}))
	ctx.route(re.compile(r'.*/rnnoise\.wasm.*'), lambda r: r.fulfill(status=200, content_type='application/wasm', body=RNN, headers={'Access-Control-Allow-Origin': '*'}))

	def download(sel):
		"""Clicks a download button and returns the file's bytes and name."""
		with page.expect_download(timeout=90000) as d:
			page.evaluate("s => document.querySelector(s).click()", sel)
		dl = d.value
		return open(dl.path(), 'rb').read(), dl.suggested_filename

	def seconds_of(data):
		"""How long an audio file plays, decoded by Chrome."""
		import base64
		return page.evaluate("""b => {
			var s = atob(b), u = new Uint8Array(s.length);
			for (var i = 0; i < s.length; i++) { u[i] = s.charCodeAt(i); }
			return new OfflineAudioContext(1, 1, 44100).decodeAudioData(u.buffer).then(function (a) { return a.duration; });
		}""", base64.b64encode(data).decode())

	sign_in(page, BASE)

	# Load from URL, written with https, www and a trailing slash the site does not use.
	page.goto(BASE + '/?teleprompter=1', wait_until='load')
	page.wait_for_selector('#tpUrl', timeout=10000)
	page.evaluate("try { sessionStorage.setItem('rrtIntroSeen', '1'); localStorage.setItem('rrtIntro', 'off'); } catch (e) {}")
	variant = re.sub(r'^http://', 'https://www.', POST_URL)
	variant = variant if variant.endswith('/') else variant + '/'
	page.evaluate("v => { var u = document.getElementById('tpUrl'); u.value = v; document.getElementById('tpLoad').click(); }", variant)
	try:
		page.wait_for_url(re.compile(r'.*tp_post=%d.*' % POST_ID), timeout=5000)
		went = True
	except Exception:
		went = False
	check(went, 'Load from URL finds the post when the address is written differently', variant)

	# A pause cue after "moon." (saved on the site, as the Cues switch does).
	page.goto(TP, wait_until='load')
	page.wait_for_timeout(500)
	words = page.evaluate("Array.prototype.map.call(document.querySelectorAll('.tp-w'), function (e) { return e.textContent; })")
	cue_api = page.evaluate("({ rest: RRT.cues.rest, nonce: RRT.cues.nonce })")
	moon = next(i for i, w in enumerate(words) if re.sub(r'[^a-z]', '', w.lower()) == 'moon')
	saved = page.evaluate("""m => fetch(RRT.cues.rest, { method: 'POST', credentials: 'same-origin',
		headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': RRT.cues.nonce },
		body: JSON.stringify({ post: %d, cues: [{ i: m, k: 'moon', a: 'pause', e: false }] }) }).then(r => r.status)""" % POST_ID, moon)
	check(saved == 200, 'a pause cue is saved on the post', str(saved))

	# Pronunciation notes (1.1.0), set the way a reader does: Cues on, click a word, Pronunciation.
	def reset_words():
		# No notes or taught words left from an earlier run, so the review flags the misheard words.
		return page.evaluate("""p => Promise.all([
			fetch(RRT.notes.rest, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': RRT.notes.nonce }, body: JSON.stringify({ post: p, scope: 'post', notes: [] }) }),
			fetch(RRT.notes.rest, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': RRT.notes.nonce }, body: JSON.stringify({ post: p, scope: 'all', notes: [] }) }),
			fetch(RRT.learnedPost.rest, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': RRT.notes.nonce }, body: JSON.stringify({ post: p, learned: {}, replace: true }) }),
			fetch(RRT.notes.rest.replace('notes', 'learned'), { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': RRT.notes.nonce }, body: JSON.stringify({ learned: {}, replace: true }) })
		]).then(rs => rs.map(r => r.status))""", POST_ID)

	check(page.evaluate("!!RRT.notes"), 'the studio is given the pronunciation notes')
	check(reset_words() == [200, 200, 200, 200], 'notes and taught words start empty')
	page.goto(TP, wait_until='load')
	page.wait_for_timeout(500)
	del errors[:]

	def word_index(key, nth=0):
		found = [i for i, w in enumerate(words) if re.sub(r'[^a-z]', '', w.lower()) == key]
		return found[nth]

	def note_on(i):
		return page.evaluate("i => document.querySelectorAll('.tp-w')[i].getAttribute('data-note')", i)

	def open_note(i):
		page.evaluate("var c = document.getElementById('tpCues'); if (!c.checked) { c.click(); }")
		page.evaluate("i => document.querySelectorAll('.tp-w')[i].click()", i)
		page.wait_for_selector('.tp-cue-menu', timeout=3000)

	hill = word_index('hill')
	open_note(hill)
	check(page.inner_text('.tp-cue-menu [data-cue="note"]') == 'Pronunciation', 'the cue menu offers Pronunciation')
	page.click('.tp-cue-menu [data-cue="note"]')
	page.wait_for_selector('.tp-note-input', timeout=3000)
	check(page.evaluate("document.activeElement.classList.contains('tp-note-input')"), 'the note box has the focus')
	check(page.is_checked('.tp-note-where input[value="all"]'), 'a new note is for every post unless changed')
	page.keyboard.type('HILL here')
	check(page.input_value('.tp-note-input') == 'HILL here', 'a space can be typed in the note', page.input_value('.tp-note-input'))
	page.check('.tp-note-where input[value="post"]')
	page.keyboard.press('Enter')
	check(page.evaluate("!document.querySelector('.tp-cue-menu')"), 'Enter saves the note and closes the menu')
	check(note_on(hill) == 'HILL here', 'the note shows above its word', str(note_on(hill)))

	# Only here: one "the" gets a note; the others do not.
	the0, the1 = word_index('the', 1), word_index('the', 2)
	open_note(the0)
	page.click('.tp-cue-menu [data-cue="note"]')
	page.keyboard.type('THEE')
	page.check('.tp-note-only input')
	check(page.is_disabled('.tp-note-where input[value="all"]'), 'Only here sets aside the every post or this post choice')
	page.click('.tp-note-save')
	check(note_on(the0) == 'THEE' and note_on(the1) is None, 'an Only here note shows on that one word only', '%s / %s' % (note_on(the0), note_on(the1)))

	# Edit and Remove note.
	open_note(the0)
	check(page.inner_text('.tp-cue-menu [data-cue="note"]') == 'Edit note' and page.is_visible('.tp-cue-menu [data-cue="unnote"]'), 'a word with a note offers Edit note and Remove note')
	page.click('.tp-cue-menu [data-cue="unnote"]')
	check(note_on(the0) is None, 'Remove note takes it off')
	open_note(the0)
	page.click('.tp-cue-menu [data-cue="note"]')
	page.keyboard.type('nothing')
	page.keyboard.press('Escape')
	check(page.evaluate("!document.querySelector('.tp-cue-menu')") and note_on(the0) is None, 'Escape closes the note box without saving')

	# A note in every post, on the word Whisper will mishear.
	river = word_index('river')
	open_note(river)
	page.click('.tp-cue-menu [data-cue="note"]')
	page.keyboard.type('RIV-er')
	page.click('.tp-note-save')
	page.wait_for_timeout(1500)
	page.goto(TP, wait_until='load')
	page.wait_for_timeout(600)
	check(note_on(hill) == 'HILL here' and note_on(river) == 'RIV-er' and note_on(the0) is None, 'the notes are saved on the site and come back', '%s / %s' % (note_on(hill), note_on(river)))
	saved_notes = page.evaluate("[RRT.notes.post, RRT.notes.mine]")
	check(saved_notes == [[{'k': 'hill', 'n': 'HILL here'}], [{'k': 'river', 'n': 'RIV-er'}]], "this post's note is kept with the post, and the every-post note with the reader", json.dumps(saved_notes))
	check(not errors, 'no script errors while setting notes', '; '.join(errors))

	# A take recorded in the studio (0.25.2): the stand-in microphone, timed scroll, no Whisper.
	def setopt(sel, on):
		page.evaluate("a => { var e = document.querySelector(a[0]); if (e.checked !== a[1]) { e.click(); } }", [sel, on])

	def record(secs):
		page.goto(TP, wait_until='load')
		page.wait_for_timeout(600)
		setopt('#tpRecOpt', True)
		setopt('#tpWhisperOpt', False)
		page.evaluate("document.getElementById('tpPlay').click()")
		page.wait_for_function("!document.getElementById('tpFinish').hidden", timeout=15000)
		page.wait_for_timeout(int(secs * 1000))
		page.evaluate("document.getElementById('tpFinish').click()")
		page.wait_for_function("document.getElementById('tpDownload').href.indexOf('blob:') === 0 ? !document.getElementById('tpDownload').hidden : false", timeout=90000)

	del errors[:]
	record(4)
	check(page.evaluate("document.getElementById('tpResult').classList.contains('is-on')"), 'a recorded take opens the result')
	raw, raw_name = download('#tpDownloadRaw')
	check(raw[:4] == b'RIFF' and raw_name.endswith('.wav'), 'Original gives the take as it was', raw_name)
	rec_secs = seconds_of(raw)
	check(3 < rec_secs < 8, 'the take holds what was read', '%.2f s' % rec_secs)
	rec_mp3, rec_name = download('#tpDownload')
	check(rec_mp3[:3] == b'ID3' and rec_name.endswith('-reading.mp3'), 'its MP3 is named after the post', rec_name)
	rec_mp3_secs = seconds_of(rec_mp3)
	check(abs(rec_mp3_secs - rec_secs - 9) < 0.4, 'a new take downloads with the music bed under it', '%.2f s against %.2f s' % (rec_mp3_secs, rec_secs))
	record(3)
	page.goto(TP, wait_until='load')
	try:
		page.wait_for_selector('#tpRecover.is-on', timeout=10000)
		offered = True
	except Exception:
		offered = False
	check(offered, 'a take never downloaded is offered back when the studio opens again')
	if offered:
		page.click('#tpRecoverGo')
		page.wait_for_function("document.getElementById('tpDownload').href.indexOf('blob:') === 0 ? !document.getElementById('tpDownload').hidden : false", timeout=90000)
		got, _ = download('#tpDownload')
		check(got[:3] == b'ID3', 'the recovered take becomes an MP3')
		page.goto(TP, wait_until='load')
		page.wait_for_timeout(1500)
		check(not page.evaluate("document.getElementById('tpRecover').classList.contains('is-on')"), 'once downloaded, it is not offered again')
	check(not errors, 'no script errors while recording', '; '.join(errors))
	setopt('#tpWhisperOpt', True)

	heard, seconds = plan(words)
	fake['js'] = fake_transformers(heard)
	page.goto(TP, wait_until='load')
	page.wait_for_timeout(800)
	del errors[:]
	with tempfile.NamedTemporaryFile(suffix='.wav', delete=False) as f:
		f.write(wav_for(heard, seconds))
		wav = f.name
	page.set_input_files('#tpFile', wav)
	try:
		page.wait_for_function("document.body.classList.contains('is-reviewing') ? document.querySelectorAll('#tpRvProblems *').length > 0 : false", timeout=120000)
		opened = True
	except Exception:
		opened = False
	check(opened, 'the review opens after cleanup, the MP3 and Whisper')
	problems = page.inner_text('#tpRvProblems') if opened else ''
	check('Skipped' in problems and '“hill,”' in problems, 'the skipped word is in the review', problems[:400])
	check('Filler' in problems and '“um”' in problems, 'the filler is in the review', problems[:400])
	pauses = re.findall(r'Long pause', problems)
	check(len(pauses) == 1, 'one long pause is flagged (the marked one is not)', problems[:400])
	check('before “Owls”' in problems, 'the flagged pause is the unmarked one, before “Owls”', problems[:400])

	# The pause map (1.11.0): both long pauses, one where a pause was cued.
	pm = page.inner_text('#tpRvPauses') if opened else ''
	check('2 pauses of a second or more' in pm and '1 of them where you cued' in pm, 'the pause map counts both pauses and the cued one', pm)
	check('Cued pause not taken' not in pm, 'a cued pause that was taken is not noted', pm)
	page.evaluate("localStorage.removeItem('rrtShowPauses')")
	if page.is_visible('#tpRvShowPauses'):
		page.click('#tpRvShowPauses')
	check(page.evaluate("document.getElementById('tpRvScript').classList.contains('is-gaps') && localStorage.getItem('rrtShowPauses') === '1'"), 'Show pauses shows them in the script, and is remembered')
	page.click('#tpRvShowPauses')
	check(page.evaluate("!document.getElementById('tpRvScript').classList.contains('is-gaps')"), 'Show pauses switches off again')

	# Pronunciation notes in the review (1.1.0).
	check('said as HILL here' in problems, 'a flagged word shows its pronunciation note', problems[:400])
	hill_title = page.evaluate("i => (document.querySelectorAll('#tpRvScript .tp-rv-w')[i] || {}).title || ''", word_index('hill'))
	check('Say it as HILL here' in hill_title, 'hovering a word in the review shows its note', hill_title)
	teach_line = page.inner_text('.tp-rv-note-teach') if page.is_visible('.tp-rv-note-teach') else ''
	check('misheard 1 word that has a pronunciation note' in teach_line, 'the review offers to teach the noted word Whisper misheard', teach_line or problems[:400])
	check('“river” heard as “water”' in problems and '“called” heard as “cold”' in problems, 'both misheard words are flagged', problems[:400])
	page.click('.tp-rv-note-teach button[data-scope="post"]')
	problems = page.inner_text('#tpRvProblems')
	check('“river” heard as “water”' not in problems and not page.is_visible('.tp-rv-note-teach'), 'teaching it clears its flag and the offer', problems[:400])
	called = page.locator('#tpRvProblems li', has_text='“called” heard as “cold”')
	called.locator('.tp-rv-teach').click()
	called.locator('.tp-rv-teach[data-scope="all"]').click()
	check(page.is_visible('.tp-rv-note-offer'), 'teaching a word with no note offers to add one')
	check('“called”' in page.inner_text('.tp-rv-note-offer'), 'the offer names the word', page.inner_text('.tp-rv-note-offer'))
	page.click('#tpRvNoteIn')
	page.keyboard.type('KAWLD it')
	check(page.input_value('#tpRvNoteIn') == 'KAWLD it', 'a space can be typed in the review note without playing the take')
	page.click('.tp-rv-note-offer button[data-scope="post"]')
	check(not page.is_visible('.tp-rv-note-offer'), 'saving the note closes the offer')
	ctitle = page.evaluate("i => (document.querySelectorAll('#tpRvScript .tp-rv-w')[i] || {}).title || ''", word_index('called'))
	check('Say it as KAWLD it' in ctitle, 'the new note shows on the word at once', ctitle)
	problems = page.inner_text('#tpRvProblems')
	stats = page.inner_text('#tpRvStats') if opened else ''
	check(re.search(r'\d+%', stats) is not None, 'the review shows an accuracy', stats[:200])
	readout = page.evaluate("document.body.innerText")
	check('words a minute' in readout, 'the read-out describes the pace')
	href = page.evaluate("(document.getElementById('tpDownload') || {}).href || ''")
	check(href.startswith('blob:'), 'an MP3 is ready to download', href[:60])
	check(not errors, 'no script errors during the take', '; '.join(errors))

	# Downloads (0.25.2): the voice-only MP3, the mix with the music bed, and the Original.
	has_music = page.evaluate("!!(RRT.music ? RRT.music.url : false)")
	check(has_music, 'the test post has a music bed (tests/wp/music.php)')
	check(page.evaluate("!document.getElementById('tpMusic').hidden ? document.getElementById('tpMusicOn').checked : false"), 'a new take has the music bed switched on')
	page.evaluate("var m = document.getElementById('tpRvMusicOn'); m.checked = false; m.dispatchEvent(new Event('change'));")
	check(not page.evaluate("document.getElementById('tpMusicOn').checked"), 'switching the music off in the review switches it off by the result too')
	voice, voice_name = download('#tpDownload')
	check(voice[:3] == b'ID3', 'Download MP3 gives a tagged MP3', repr(voice[:4]))
	check(voice_name.endswith('-cleaned.mp3'), 'an opened file\'s MP3 is named after the file', voice_name)
	voice_secs = seconds_of(voice)
	check(seconds - 3 < voice_secs < seconds + 0.5, 'the MP3 is as long as the take (less its trimmed ends)', '%.2f s of %.2f s' % (voice_secs, seconds))
	page.evaluate("var m = document.getElementById('tpRvMusicOn'); m.checked = true; m.dispatchEvent(new Event('change'));")
	mix, mix_name = download('#tpDownload')
	mix_secs = seconds_of(mix)
	check(mix_name == voice_name, 'with music, the download keeps the same name', mix_name)
	check(abs(mix_secs - voice_secs - 9) < 0.4, 'the mix has 4 s of music before the voice and a 5 s tail', '%.2f s against %.2f s' % (mix_secs, voice_secs))
	check(mix[:3] == b'ID3' and b'Music: Test bed' in mix[:20000], 'the mix is tagged with the music credit')
	check('With music:' in page.inner_text('#tpMusicStatus'), 'the music status says the mix is made', page.inner_text('#tpMusicStatus'))
	# The review header's Download (0.25.3) gives the same file as the button behind the review.
	check(page.is_visible('#tpRvDownload'), 'the review header offers Download')
	check(page.inner_text('#tpRvDownload').strip() == page.evaluate("document.getElementById('tpDownload').textContent").strip(), 'its label matches the download behind the review', page.inner_text('#tpRvDownload'))
	check(page.inner_text('#tpRvDownload').strip().endswith('with music'), 'its label says the music is in the file', page.inner_text('#tpRvDownload'))
	hmix, hmix_name = download('#tpRvDownload')
	check(hmix_name == mix_name and abs(seconds_of(hmix) - mix_secs) < 0.1, 'the header Download gives the mix with music on', '%s, %.2f s' % (hmix_name, seconds_of(hmix)))
	page.evaluate("var m = document.getElementById('tpRvMusicOn'); m.checked = false; m.dispatchEvent(new Event('change'));")
	check(not page.inner_text('#tpRvDownload').strip().endswith('with music'), 'with music off, its label drops the music', page.inner_text('#tpRvDownload'))
	hvoice, hvoice_name = download('#tpRvDownload')
	check(hvoice_name == voice_name and abs(seconds_of(hvoice) - voice_secs) < 0.1, 'and it gives the voice only', '%s, %.2f s' % (hvoice_name, seconds_of(hvoice)))
	page.evaluate("var m = document.getElementById('tpRvMusicOn'); m.checked = true; m.dispatchEvent(new Event('change'));")
	check(not errors, 'no script errors during the downloads', '; '.join(errors))

	# A retake of "under the moon." whose recording starts with a false start (0.25.1).
	page.click('#tpEdToggle')
	page.wait_for_selector('#tpEdTools:not([hidden])', timeout=10000)
	check(page.is_disabled('#tpEdCheck'), 'Check the edited version waits until there is an edit')
	ti = page.evaluate("""() => {
		var t = Array.prototype.slice.call(document.querySelectorAll('#tpRvScript [data-ti]'));
		var k = function (e) { return e.textContent.toLowerCase().replace(/[^a-z]/g, ''); };
		var a = t.findIndex(function (e) { return k(e) === 'under'; });
		return [a >= 0 ? t[a].getAttribute('data-ti') : null, a >= 0 ? t[a + 2].getAttribute('data-ti') : null];
	}""")
	first = page.locator('#tpRvScript [data-ti="%s"]' % ti[0])
	last = page.locator('#tpRvScript [data-ti="%s"]' % ti[1])
	first.scroll_into_view_if_needed()
	fb = first.bounding_box()
	lb = last.bounding_box()
	page.mouse.move(fb['x'] + 3, fb['y'] + fb['height'] / 2)
	page.mouse.down()
	page.mouse.move(lb['x'] + lb['width'] - 3, lb['y'] + lb['height'] / 2, steps=6)
	page.mouse.up()
	page.click('#tpEdRetake')
	check(page.inner_text('#tpEdRetakeLine').strip() == 'under the moon.', 'the retake box shows the selected words', page.inner_text('#tpEdRetakeLine'))
	page.click('#tpEdRecBtn')
	page.wait_for_timeout(3000)
	page.click('#tpEdRecBtn')
	try:
		page.wait_for_function("/Retake ready/.test(document.getElementById('tpEdRetakeNote').textContent)", timeout=60000)
		ready = True
	except Exception:
		ready = False
	note = page.inner_text('#tpEdRetakeNote')
	check(ready, 'the retake is recorded and fitted', note)
	check('Trimmed “under” before the selected words' in note, 'the false start before the first word is trimmed', note)
	check(page.is_visible('#tpEdTrim') and page.inner_text('#tpEdTrimS') == 'as fitted', 'Retake start and Retake end are offered, as fitted')
	for _ in range(5):
		page.click('#tpEdTrim .tp-ed-trim[data-side="s"][data-dir="1"]')
	check(page.inner_text('#tpEdTrimS') == 'trims 50 ms more', 'Retake start later trims more of the new recording', page.inner_text('#tpEdTrimS'))
	page.click('#tpEdTrim .tp-ed-trim[data-side="e"][data-dir="1"]')
	check(page.inner_text('#tpEdTrimE') == 'keeps 10 ms more', 'Retake end later keeps more of it', page.inner_text('#tpEdTrimE'))
	check(not page.is_disabled('#tpEdEdgeReset'), 'Reset is offered once the retake is trimmed')
	page.click('#tpEdEdgeReset')
	check(page.inner_text('#tpEdTrimS') == 'as fitted' and page.inner_text('#tpEdTrimE') == 'as fitted', 'Reset puts both ends of the retake back')
	for _ in range(3):
		page.click('#tpEdTrim .tp-ed-trim[data-side="s"][data-dir="1"]')
	page.click('#tpEdRecUse')
	page.wait_for_timeout(300)
	page.locator('#tpRvScript [data-ti="%s"]' % ti[1]).click()
	page.wait_for_selector('#tpEdRetakeBox:not([hidden])', timeout=5000)
	check(page.inner_text('#tpEdTrimS') == 'trims 30 ms more', 'a retake opened again keeps its trim, and can be trimmed again', page.inner_text('#tpEdTrimS'))
	page.click('#tpEdTrim .tp-ed-trim[data-side="s"][data-dir="-1"]')
	check(page.inner_text('#tpEdTrimS') == 'trims 20 ms more', 'and trimmed back by ear', page.inner_text('#tpEdTrimS'))
	page.click('#tpEdRecCancel')
	check(not errors, 'no script errors during the retake', '; '.join(errors))

	# Edit audio (0.25.2): Tidy finds the filler and the unmarked long pause, a word is cut by hand,
	# undo steps back, and the edited MP3 is made and downloaded.
	page.click('#tpEdTidy')
	page.wait_for_selector('#tpEdTidyBox:not([hidden])', timeout=5000)
	tidy = page.inner_text('#tpEdTidyList')
	check('long pause' in page.inner_text('#tpEdTidyHead'), 'Tidy offers to shorten a long pause', page.inner_text('#tpEdTidyHead'))
	n_tidy = page.evaluate("document.querySelectorAll('#tpEdTidyList input[type=checkbox]').length")
	check(n_tidy >= 1, 'Tidy lists what it found', tidy[:300])
	page.click('#tpEdTidyApply')
	cuts_after_tidy = page.evaluate("document.querySelectorAll('#tpRvScript .is-cut').length")

	# Flags after edits (1.1.0): what Tidy cut is marked at once, and the tiles count what is left.
	covered = page.evaluate("""() => Array.prototype.map.call(document.querySelectorAll('#tpRvProblems li.is-covered'), function (li) { return li.innerText.replace(/\\s+/g, ' '); })""")
	check(any('Filler' in c and 'Cut' in c for c in covered), 'the filler Tidy cut is marked Cut', json.dumps(covered))
	check(any('Long pause' in c and 'Shortened' in c for c in covered), 'the long pause Tidy shortened is marked Shortened', json.dumps(covered))
	check(not any('Skipped' in c for c in covered), 'a flag no edit touches is left as it was', json.dumps(covered))
	line = page.inner_text('.tp-rv-covered') if page.is_visible('.tp-rv-covered') else ''
	check(re.match(r'^2 of \d+ flags covered by your edits\.$', line) is not None, 'a line says how many flags the edits cover', line)
	fill = page.inner_text('#tpRvStats .tp-rv-tile[data-filter="filler"]').replace('\n', ' ')
	check(fill.lower().startswith('fillers 0') and '1 before your edits' in fill, 'the Fillers tile counts what is left', fill)
	check(page.evaluate("document.querySelectorAll('#tpRvTimeline .tp-tl-dot.is-covered').length") >= 1, 'the timeline dims the covered flags')
	check(not page.is_disabled('#tpEdCheck') and page.inner_text('#tpEdCheck') == 'Check the edited version', 'Check the edited version is offered once there are edits')
	page.click('#tpEdCheck')
	try:
		page.wait_for_selector('.tp-rv-flagswitch', timeout=60000)
		checked = True
	except Exception:
		checked = False
	check(checked, 'Whisper checks the edited version', page.inner_text('#tpEdStatus'))
	if checked:
		check(page.get_attribute('.tp-rv-flagswitch [data-view="edited"]', 'aria-pressed') == 'true', 'the flags switch to the edited version')
		eview = page.inner_text('#tpRvProblems')
		check('Filler' not in eview and 'Skipped' in eview, "the edited version's flags show: the filler is gone, the skipped word is not", eview[:300])
		check('Edited version:' in page.inner_text('.tp-rv-flagview') and 'keep the numbers from the reading itself' in page.inner_text('.tp-rv-flagview'), 'it says these are the edited version\'s numbers, and the take keeps its own')
		check(page.inner_text('#tpEdCheck') == 'Edited version checked' and page.is_disabled('#tpEdCheck'), 'the check is up to date', page.inner_text('#tpEdCheck'))
		check(page.inner_text('#tpRvStats .tp-rv-tile[data-filter="filler"]').lower().startswith('fillers\n0'), 'the tiles count the edited version', page.inner_text('#tpRvStats .tp-rv-tile[data-filter="filler"]'))
		page.click('.tp-rv-flagview .tp-rv-editplay')
		page.wait_for_timeout(500)
		check(page.evaluate("window.RRTStudio.rv.editedAudio ? !window.RRTStudio.rv.editedAudio.paused || window.RRTStudio.rv.editedAudio.currentTime > 0 : false"), 'the edited version plays', page.inner_text('.tp-rv-flagview'))
		page.click('.tp-rv-flagview .tp-rv-editplay')
		page.click('.tp-rv-flagswitch [data-view="recorded"]')
		check('Filler' in page.inner_text('#tpRvProblems') and page.is_visible('#tpRvProblems li.is-covered'), 'As recorded shows the take\'s flags again, with the marks')
		page.click('.tp-rv-flagswitch [data-view="edited"]')
	check(not errors, 'no script errors while checking the edited version', '; '.join(errors))
	owls = page.evaluate("""() => {
		var t = Array.prototype.slice.call(document.querySelectorAll('#tpRvScript [data-ti]'));
		var e = t.find(function (e) { return e.textContent.toLowerCase().replace(/[^a-z]/g, '') === 'still'; });
		return e ? e.getAttribute('data-ti') : null;
	}""")
	page.locator('#tpRvScript [data-ti="%s"]' % owls).click()
	page.click('#tpEdCut')
	cut_now = page.evaluate("document.querySelectorAll('#tpRvScript .is-cut').length")
	check(cut_now > cuts_after_tidy, 'a word is cut by hand', '%d then %d' % (cuts_after_tidy, cut_now))
	if checked:
		check(page.inner_text('#tpEdCheck') == 'Check again' and page.is_visible('.tp-rv-stale'), 'a new edit after the check offers Check again', page.inner_text('#tpEdCheck'))
	page.click('#tpEdUndo')
	check(page.evaluate("document.querySelectorAll('#tpRvScript .is-cut').length") == cuts_after_tidy, 'Undo puts the word back')
	if checked:
		check(page.inner_text('#tpEdCheck') == 'Edited version checked' and not page.is_visible('.tp-rv-stale'), 'undoing it makes the check up to date again', page.inner_text('#tpEdCheck'))
	page.click('#tpEdMake')
	try:
		page.wait_for_function("/^Edited MP3 ready/.test(document.getElementById('tpEdStatus').textContent)", timeout=60000)
		made = True
	except Exception:
		made = False
	check(made, 'the edited MP3 is made', page.inner_text('#tpEdStatus'))
	page.evaluate("var m = document.getElementById('tpRvMusicOn'); m.checked = false; m.dispatchEvent(new Event('change'));")
	edited, edited_name = download('#tpDownload')
	edited_secs = seconds_of(edited)
	check(edited_name.endswith('-reading-edited.mp3'), 'the edited MP3 has its own name', edited_name)
	check(edited_secs < voice_secs - 0.5, 'the edited MP3 is shorter than the take', '%.2f s against %.2f s' % (edited_secs, voice_secs))
	check(edited[:3] == b'ID3', 'the edited MP3 is tagged')
	page.evaluate("var m = document.getElementById('tpRvMusicOn'); m.checked = true; m.dispatchEvent(new Event('change'));")
	emix, _ = download('#tpDownload')
	check(abs(seconds_of(emix) - edited_secs - 9) < 0.4, 'the music goes under the edited reading too')
	check(page.inner_text('#tpRvDownload').strip().startswith('Download edited MP3'), 'once the edited MP3 is made, the header Download gives it', page.inner_text('#tpRvDownload'))
	hemix, hemix_name = download('#tpRvDownload')
	check(hemix_name == edited_name and abs(seconds_of(hemix) - seconds_of(emix)) < 0.1, 'the header Download gives the edited MP3 with music', '%s, %.2f s' % (hemix_name, seconds_of(hemix)))
	page.evaluate("var m = document.getElementById('tpRvMusicOn'); m.checked = false; m.dispatchEvent(new Event('change'));")
	hedit, _ = download('#tpRvDownload')
	check(abs(seconds_of(hedit) - edited_secs) < 0.1, 'and the edited MP3 alone with music off', '%.2f s' % seconds_of(hedit))
	page.evaluate("var m = document.getElementById('tpRvMusicOn'); m.checked = true; m.dispatchEvent(new Event('change'));")
	page.click('#tpEdToggle')
	check(not errors, 'no script errors while editing', '; '.join(errors))

	# Use this recording puts the reading on the post; its page in WP-admin then offers clips,
	# whose Whisper is the same worker as the studio's (whisper.js, 0.25.1).
	page.click('#tpRvUseRec')
	try:
		page.wait_for_function("(document.getElementById('tpRvUseRec') || {}).textContent === 'On the post now'", timeout=60000)
		used = True
	except Exception:
		used = False
	check(used, 'Use this recording puts the reading on the post', page.evaluate("(document.getElementById('tpRvUseRec') || {}).textContent"))
	del errors[:]
	page.goto(BASE + '/wp-admin/admin.php?page=rrt-readings&post=%d' % POST_ID, wait_until='load')
	page.wait_for_timeout(600)
	check(page.evaluate("!!document.getElementById('rrt-clip-boot')"), 'the reading page offers a clip')
	check(page.evaluate("typeof window.RRTWhisper === 'object' ? typeof window.RRTWhisper.start === 'function' : false"), 'the clip maker has the shared Whisper worker')
	check(page.evaluate("typeof window.RRTClip === 'object'"), 'the clip maker loads')
	check(not errors, 'no script errors on the reading page', '; '.join(errors))

	# Leave the post without notes or taught words, and without cue marks, for the next run.
	page.goto(TP, wait_until='load')
	page.wait_for_timeout(500)
	check(reset_words() == [200, 200, 200, 200], 'the notes and taught words are cleared after the run')
	page.evaluate("""c => fetch(c.rest, { method: 'POST', credentials: 'same-origin',
		headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': c.nonce },
		body: JSON.stringify({ post: %d, cues: [] }) }).then(r => r.status)""" % POST_ID, cue_api)
	os.unlink(wav)
	os.unlink(MIC)
	browser.close()

print('\n%d passed, %d failed' % (passed, len(failed)))
sys.exit(1 if failed else 0)
