#!/usr/bin/env python3
"""
One reading from several takes (1.3.0), end to end: three takes of a post
are opened and reviewed (two whole, one starting at the second paragraph),
each goes on the post's shelf in the browser, and Combine takes builds one
reading from them. Then (1.4.0) the post is read in parts: a take of the
first paragraph, Continue, the rest, and the coverage bar.

Checks the shelf (a take opened twice is kept once), the best of each, a
paragraph chosen sentence by sentence, a join's space, that the choices
survive a reload, and that the built reading opens in the review with the
takes' own words, none of the slips that were left out, and no new entry
in the take history.

Whisper is a stand-in that answers each take with that take's words (told
apart by length). The MP3 encoder and noise model are the real ones from
npm, as in take.py.

Usage: CB_POST=<post id> tests/browser/combine.py http://localhost:8080
The post must read: "<p>Leaves fall slowly. Rain taps the glass.</p><p>Owls call softly. The lamp stays lit.</p>"
"""
import io, json, math, os, re, struct, sys, tempfile, wave
from playwright.sync_api import sync_playwright
from signin import sign_in
from whisper_stub import PIECE
from take_assets import MP3, RNN

BASE = sys.argv[1].rstrip('/') if len(sys.argv) > 1 else 'http://localhost:8080'
CHROME = os.environ.get('CHROME_PATH', '/usr/bin/google-chrome')
POST_ID = int(os.environ['CB_POST'])
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


def plan(words, skip=(), misheard=None, pause=0.6, start=0, stop=None):
	"""What a take says: (text, start, end) from word `start` on (up to `stop`), with slips."""
	misheard = misheard or {}
	out = []
	t = 0.2
	for i, w in enumerate(words):
		if i < start or key(w) == '' or (stop is not None and i >= stop):
			continue
		if key(w) in skip:
			continue
		out.append((misheard.get(key(w), w), t, t + 0.32))
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


def fake_transformers(takes):
	"""Each take's words, picked by the length of what Whisper is given (the nearest take)."""
	# Matched on where the last word ends, since the studio trims the silence after it before Whisper hears the take.
	table = [[heard[-1][2] + 0.5, [{'text': ' ' + w, 'timestamp': [round(s, 3), round(e, 3)]} for (w, s, e) in heard]] for (heard, secs) in takes]
	return (
		'export const env = { backends: { onnx: { wasm: {} } } };\n'
		+ PIECE +
		'const TAKES = %s;\n'
		'export async function pipeline() {\n'
		'  return async function (audio) {\n'
		'    const d = total(audio);\n'
		'    let best = TAKES[0];\n'
		'    TAKES.forEach(t => { if (Math.abs(t[0] - d) < Math.abs(best[0] - d)) { best = t; } });\n'
		'    return piece(audio, { chunks: best[1], text: best[1].map(x => x.text).join("") });\n'
		'  };\n'
		'}\n' % json.dumps(table)
	)


with sync_playwright() as p:
	browser = p.chromium.launch(executable_path=CHROME if os.path.exists(CHROME) else None, args=['--autoplay-policy=no-user-gesture-required'])
	ctx = browser.new_context()
	page = ctx.new_page()
	errors = []
	page.on('pageerror', lambda e: errors.append(str(e)))
	fake = {'js': ''}
	ctx.route(re.compile(r'.*transformers(\.min)?\.js.*'), lambda r: r.fulfill(status=200, content_type='text/javascript', body=fake['js'], headers={'Access-Control-Allow-Origin': '*'}))
	ctx.route(re.compile(r'.*/mp3\.wasm.*'), lambda r: r.fulfill(status=200, content_type='application/wasm', body=MP3, headers={'Access-Control-Allow-Origin': '*'}))
	ctx.route(re.compile(r'.*/rnnoise\.wasm.*'), lambda r: r.fulfill(status=200, content_type='application/wasm', body=RNN, headers={'Access-Control-Allow-Origin': '*'}))

	sign_in(page, BASE)
	page.goto(TP, wait_until='load')
	page.evaluate("try { sessionStorage.setItem('rrtIntroSeen', '1'); localStorage.setItem('rrtIntro', 'off'); } catch (e) {}")
	page.evaluate("new Promise(r => { var q = indexedDB.deleteDatabase('rrt-shelf'); q.onsuccess = q.onerror = q.onblocked = () => r(); })")
	page.goto(TP, wait_until='load')
	page.wait_for_timeout(500)
	page.evaluate("a => { var e = document.querySelector(a); if (!e.checked) { e.click(); } }", '#tpWhisperOpt')
	words = page.evaluate("Array.prototype.map.call(document.querySelectorAll('.tp-w'), function (e) { return e.textContent; })")
	owls = next(i for i, w in enumerate(words) if key(w) == 'owls')

	# Take 1 skips "slowly"; take 2 is heard saying "clock" for "lamp", with longer pauses; take 3 starts at "Owls".
	t1 = plan(words, skip=('slowly',))
	t2 = plan(words, misheard={'lamp': 'clock'}, pause=1.4)
	t3 = plan(words, start=owls, pause=0.8)
	# Take 4 (1.4.0) reads the title and the first paragraph only, with longer pauses, so its length tells it apart.
	t4 = plan(words, stop=owls, pause=1.2)
	lengths = sorted(t[0][-1][2] for t in (t1, t2, t3, t4))
	check(all(lengths[i + 1] - lengths[i] > 0.5 for i in range(3)), 'the made-up takes differ in length, so the stand-in Whisper tells them apart', json.dumps(lengths))
	fake['js'] = fake_transformers([t1, t2, t3, t4])
	files = [wav_for(t1[0], t1[1], 11), wav_for(t2[0], t2[1], 13), wav_for(t3[0], t3[1], 17), wav_for(t4[0], t4[1], 19)]
	history = page.evaluate("JSON.parse(localStorage.getItem('rrtTakes_%d') || '[]').length" % POST_ID)

	def review(path):
		page.goto(TP, wait_until='load')
		page.wait_for_timeout(500)
		page.set_input_files('#tpFile', path)
		page.wait_for_function("document.getElementById('tpWhisperStatus').textContent.indexOf('shelf') >= 0", timeout=120000)
		return page.inner_text('#tpWhisperStatus')

	lines = [review(f) for f in files[:3]]
	check('as take 1' in lines[0], 'the first take goes on the shelf as take 1', lines[0])
	check('as take 2' in lines[1] and 'Combine takes' in lines[1], 'the second is take 2, and the line points to Combine takes', lines[1])
	check('as take 3' in lines[2], 'the third is take 3', lines[2])
	again = review(files[0])
	check('Already on this post’s shelf as take 1' in again, 'the same take opened again is kept once', again)
	check(page.evaluate("JSON.parse(localStorage.getItem('rrtTakes_%d') || '[]').length" % POST_ID) == history + 4, 'each take is in the take history as before')
	history += 4

	page.wait_for_function("document.body.classList.contains('is-reviewing')", timeout=30000)
	btn = page.inner_text('#tpRvCombine') if page.is_visible('#tpRvCombine') else ''
	check(btn == 'Combine takes (3)', 'the review offers Combine takes with three takes', btn)
	page.click('#tpRvCombine')
	page.wait_for_selector('#tpCombine:not([hidden])', timeout=5000)
	check(not page.is_visible('#tpRvScript'), 'the combine screen takes the review\'s place')
	check(page.locator('.tp-cb-take').count() == 3, 'all three takes are listed')

	def chosen():
		"""The take chosen for each piece, in reading order, as 'Take n'."""
		return page.evaluate("""Array.prototype.map.call(document.querySelectorAll('.tp-cb-piece'), function (r) {
			var b = r.querySelector('.tp-cb-pick.is-chosen .tp-cb-choose'); return b ? b.textContent.split(' · ')[0] : '';
		})""")

	def picks(i):
		return page.evaluate("i => Array.prototype.map.call(document.querySelectorAll('.tp-cb-piece')[i].querySelectorAll('.tp-cb-choose'), function (b) { return [b.textContent, b.disabled]; })", i)

	# Pieces: the title, paragraph 1, paragraph 2.
	c = chosen()
	check(len(c) == 3, 'one piece for the title and one for each paragraph', json.dumps(c))
	check(c[1] == 'Take 2', 'the best of each reads the first paragraph from take 2 (take 1 skipped a word there)', json.dumps(c))
	check(c[2] != 'Take 2', 'and the second paragraph from a take that read "lamp" right', json.dumps(c))
	check(page.locator('.tp-cb-join').count() == 1, 'with one join', str(page.locator('.tp-cb-join').count()))
	p1 = picks(1)
	check(p1[0][0] == 'Take 1 · 1 flag' and p1[1][0] == 'Take 2 · no flags', 'each take shows its flags for the paragraph', json.dumps(p1))
	check(p1[2][1] and 'not read' in p1[2][0], 'take 3 did not read the first paragraph, so it cannot be chosen there', json.dumps(p1))
	title = page.evaluate("document.querySelectorAll('.tp-cb-piece')[1].querySelectorAll('.tp-cb-choose')[0].title")
	check('Skipped' in title and 'slowly' in title, 'hovering a take shows its flags there', title)

	# Choose take 3 for paragraph 2, and paragraph 1 sentence by sentence, its second sentence from take 1.
	page.locator('.tp-cb-piece').nth(2).locator('.tp-cb-choose', has_text='Take 3').click()
	page.locator('.tp-cb-para').nth(1).locator('.tp-cb-split').click()
	c = chosen()
	check(len(c) == 4 and c[1] == 'Take 2' and c[2] == 'Take 2', 'split by sentence, both sentences keep the paragraph\'s take', json.dumps(c))
	page.locator('.tp-cb-piece').nth(2).locator('.tp-cb-choose', has_text='Take 1').click()
	c = chosen()
	check(c == ['Take 2', 'Take 2', 'Take 1', 'Take 3'], 'each sentence can have its own take', json.dumps(c))
	check(page.locator('.tp-cb-join').count() == 2, 'two joins now')
	page.locator('.tp-cb-join').nth(0).locator('.tp-ed-nudge').nth(5).click()
	val = page.locator('.tp-cb-join').nth(0).locator('.tp-cb-val').nth(2).inner_text()
	check(val == '+50 ms', 'Space adds 50 ms at the join', val)
	if os.environ.get('CB_SHOTS'):
		page.set_viewport_size({'width': 1400, 'height': 900})
		page.screenshot(path=os.path.join(os.environ['CB_SHOTS'], 'combine.png'))
	page.wait_for_timeout(600)

	# The choices survive a reload.
	page.goto(TP, wait_until='load')
	page.wait_for_timeout(500)
	page.wait_for_function("!document.getElementById('tpCombineOpen').hidden", timeout=5000)
	check(page.inner_text('#tpCombineOpen') == 'Combine takes (3)', 'after a reload, Combine takes is beside Open a recording', page.inner_text('#tpCombineOpen'))
	page.click('#tpCombineOpen')
	page.wait_for_selector('#tpCombine:not([hidden])', timeout=5000)
	c = chosen()
	check(c == ['Take 2', 'Take 2', 'Take 1', 'Take 3'], 'the choices are kept for the post', json.dumps(c))
	val = page.locator('.tp-cb-join').nth(0).locator('.tp-cb-val').nth(2).inner_text()
	check(val == '+50 ms', 'and so is the join\'s space', val)
	check(not errors, 'no script errors while choosing', '; '.join(errors))

	# Build: the reading opens in the review with the takes' own words.
	page.click('#tpCbBuild')
	try:
		page.wait_for_function("document.body.classList.contains('is-reviewing') ? document.getElementById('tpWhisperStatus').textContent.indexOf('combined reading is ready') >= 0 : false", timeout=120000)
		built = True
	except Exception:
		built = False
	check(built, 'the combined reading opens in the review', page.inner_text('#tpWhisperStatus'))
	problems = page.inner_text('#tpRvProblems') if built else ''
	if os.environ.get('CB_SHOTS'):
		page.screenshot(path=os.path.join(os.environ['CB_SHOTS'], 'built.png'))
	check('Skipped' not in problems and 'heard as' not in problems, 'none of the slips left out are flagged', problems[:400])
	check(page.evaluate("JSON.parse(localStorage.getItem('rrtTakes_%d') || '[]').length" % POST_ID) == history, 'the combined reading is not added to the take history')
	raw = page.evaluate("document.getElementById('tpDownloadRaw').getAttribute('download') || ''")
	check(raw.endswith('-combined.wav'), 'its Original is the combined WAV', raw)
	secs = page.evaluate("window.RRTStudio.lastMp3 ? window.RRTStudio.lastMp3.seconds : 0")
	# Sentence 1 and the title from take 2, sentence 2 from take 1, paragraph 2 from take 3: about the words plus their pauses.
	n_words = len([w for w in words if key(w)])
	check(n_words * 0.32 < secs < n_words * 0.32 + 12, 'the combined reading is about as long as its parts', '%.1f s' % secs)
	stamps = page.evaluate("window.RRTStudio.rv.last ? window.RRTStudio.rv.last.chunks.map(function (c) { return c.timestamp[0]; }) : []")
	check(len(stamps) == n_words and all(stamps[i] < stamps[i + 1] for i in range(len(stamps) - 1)), 'every word of the post is there once, in order', json.dumps(stamps))
	check(not errors, 'no script errors while building', '; '.join(errors))

	# Clearing the shelf.
	page.click('#tpRvCombine')
	page.wait_for_selector('#tpCombine:not([hidden])', timeout=5000)
	page.click('#tpCbClear')
	page.click('#tpCbClear')
	page.wait_for_function("document.getElementById('tpCombine').hidden", timeout=5000)
	check(page.evaluate("document.getElementById('tpRvCombine').hidden"), 'once the shelf is cleared, Combine takes is gone')
	left = page.evaluate("new Promise(r => { var q = indexedDB.open('rrt-shelf'); q.onsuccess = () => { var d = q.result; var g = d.transaction(['takes', 'audio', 'plans']); var out = []; ['takes', 'audio', 'plans'].forEach(s => { var c = g.objectStore(s).count(); c.onsuccess = () => out.push(c.result); }); g.oncomplete = () => r(out); }; })")
	check(left == [0, 0, 0], 'and nothing of it is left in the browser', json.dumps(left))
	check(page.evaluate("document.getElementById('tpContinue').hidden"), 'with the shelf empty there is nothing to continue')

	# Reading in parts (1.4.0): the first part, then Continue, then the coverage bar.
	part = review(files[3])
	check('1 paragraph is in no take yet' in part and 'Continue' in part, 'a take of the first part says what is still to read', part)
	page.evaluate("window.RRTStudio.closeReview(); window.RRTStudio.dismissBtn.click()")
	page.wait_for_function("!document.getElementById('tpContinue').hidden", timeout=5000)
	check(page.inner_text('#tpContinue') == 'Continue (1 to read)', 'Continue offers the rest of the post', page.inner_text('#tpContinue'))
	rain = next(i for i, w in enumerate(words) if key(w) == 'rain')
	page.click('#tpContinue')
	at = page.evaluate("window.RRTStudio.wi")
	check(at == rain, 'Continue starts one sentence before the unread paragraph', 'word %d, expected %d' % (at, rain))
	page.wait_for_timeout(300)
	more = page.evaluate("window.RRTStudio.beginFrom(0)")
	check(more is False, 'while that take is under way, another cannot start')
	rest = review(files[2])
	check('Combine takes' in rest and 'no take yet' not in rest, 'once the rest is read, the line points to Combine takes', rest)
	check(page.evaluate("document.getElementById('tpContinue').hidden"), 'and Continue is gone')
	page.wait_for_function("document.body.classList.contains('is-reviewing')", timeout=30000)
	page.click('#tpRvCombine')
	page.wait_for_selector('#tpCombine:not([hidden])', timeout=5000)
	cells = page.evaluate("Array.prototype.map.call(document.querySelectorAll('#tpCbCover .tp-cb-cell'), function (c) { return c.className; })")
	check(len(cells) == 3 and not any('is-missing' in c for c in cells), 'the coverage bar has a cell for each piece, every one read', json.dumps(cells))
	check(len(set(c.split(' ')[1] for c in cells[1:])) == 2, 'the two paragraphs show the colours of the two takes', json.dumps(cells))
	check(not errors, 'no script errors while reading in parts', '; '.join(errors))
	for f in files:
		os.unlink(f)
	browser.close()

print('\n%d passed, %d failed' % (passed, len(failed)))
sys.exit(1 if failed else 0)
