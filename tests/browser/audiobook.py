#!/usr/bin/env python3
"""
Audiobook export (1.20.0), end to end.

Settings: Keep an audiobook copy can be ticked and is saved.

On a collection's screen the Audiobook box lists where each chapter comes
from: Samhain's audiobook copy, Yule's post MP3 (made in the browser), and
Imbolc, which has the music bed and needs a copy. The opening credits are
recorded; the closing credits are not. A changed script is saved, and
Record in the studio opens the studio on the closing credits in a new tab,
where a take is reviewed and Use for the audiobook keeps it.

In the studio on Imbolc, the review's Finish for menu keeps the take as the
post's audiobook chapter; Use this recording, with Keep an audiobook copy
ticked, keeps one too. Back on the collection, every reading has a source,
and Make the audiobook downloads a ZIP: the opening credits, three chapters,
the closing credits, a retail sample of about a minute, the cover and the
check report. Every MP3 in it is 192 kbps constant bitrate, 44.1 kHz mono;
Samhain's chapter is its copy byte for byte under a new tag; the cover is the
featured image's own bytes; Yule's chapter, made from its post's MP3, passes
every rule (level, peaks, background, room tone at both ends, the heading
read aloud).

Whisper is a stand-in; the MP3 encoder and noise model are the real ones
from npm, as in take.py. The collection and readings are made by
tests/wp/audiobook-mp3.js and tests/wp/audiobook.php.

Usage: AB_SEED=<json from tests/wp/audiobook.php> AB_DIR=<folder with the files> tests/browser/audiobook.py http://localhost:8080
"""
import hashlib, io, json, math, os, re, struct, sys, tempfile, wave, zipfile
from playwright.sync_api import sync_playwright
from signin import sign_in
from whisper_stub import PIECE
from take_assets import MP3, RNN

BASE = sys.argv[1].rstrip('/') if len(sys.argv) > 1 else 'http://localhost:8080'
CHROME = os.environ.get('CHROME_PATH', '/usr/bin/google-chrome')
SEED = json.load(open(os.environ['AB_SEED']))
DIR = os.environ['AB_DIR']
SETTINGS = BASE + '/wp-admin/admin.php?page=rrt-settings'
EDIT = BASE + '/wp-admin/post.php?post=%d&action=edit' % SEED['col']

passed = 0
failed = []


def check(ok, name, detail=''):
	global passed
	if ok:
		passed += 1
		print('ok   ' + name)
	else:
		failed.append(name)
		print('FAIL ' + name + (': ' + str(detail) if detail else ''))


def key(w):
	return re.sub(r'[^a-z0-9]', '', w.lower())


def plan(words, pause=0.6):
	"""What the take says: (text, start, end) for every word, with a second and a half of room first."""
	out = []
	t = 1.5
	for w in words:
		if key(w) == '':
			continue
		out.append((w, t, t + 0.32))
		t += 0.32 + (pause if re.search(r'[.!?]$', w) else 0.12)
	return out, t + 2.5


def wav_for(heard, seconds, seed):
	"""Tone bursts where the words are, over a quiet hush, 44.1 kHz mono."""
	rate = 44100
	n = int(seconds * rate)
	x = [0.0] * n
	for i in range(n):
		seed = (seed * 1103515245 + 12345) % 2147483648
		x[i] = (seed / 2147483648 - 0.5) * 0.002
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


BITRATES = [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 0]
RATES = [44100, 48000, 32000, 0]


def audio_start(b):
	"""Where an MP3's frames start, past its ID3 tag."""
	if b[:3] == b'ID3':
		return 10 + ((b[6] & 127) << 21 | (b[7] & 127) << 14 | (b[8] & 127) << 7 | (b[9] & 127))
	return 0


def frames(b):
	"""Every frame header of an MPEG-1 Layer III file: (kbps, rate, channels); None if it breaks."""
	i, out = audio_start(b), []
	while i + 4 <= len(b):
		h = struct.unpack('>I', b[i:i + 4])[0]
		if (h & 0xFFE00000) != 0xFFE00000 or ((h >> 19) & 3) != 3 or ((h >> 17) & 3) != 1:
			if b[i:i + 3] == b'TAG':
				break
			return None
		kbps, rate = BITRATES[(h >> 12) & 15], RATES[(h >> 10) & 3]
		if not kbps or not rate:
			return None
		out.append((kbps, rate, 1 if ((h >> 6) & 3) == 3 else 2))
		i += 144000 * kbps // rate + ((h >> 9) & 1)
	return out


def no_intro(page):
	page.evaluate("try { sessionStorage.setItem('rrtIntroSeen', '1'); localStorage.setItem('rrtIntro', 'off'); } catch (e) {}")


def review(page, url, fake, seed):
	"""Opens the studio, then a made-up take of its script, and waits for the review."""
	page.goto(url, wait_until='load')
	no_intro(page)
	page.goto(url, wait_until='load')
	page.wait_for_timeout(500)
	page.evaluate("a => { var e = document.querySelector(a); if (e ? !e.checked : false) { e.click(); } }", '#tpWhisperOpt')
	words = page.evaluate("Array.prototype.map.call(document.querySelectorAll('.tp-w'), function (e) { return e.textContent; })")
	heard, secs = plan(words)
	fake['js'] = fake_transformers(heard)
	page.set_input_files('#tpFile', wav_for(heard, secs, seed))
	page.wait_for_function("document.body.classList.contains('is-reviewing')", timeout=120000)
	page.wait_for_timeout(300)
	return words


def state(page):
	return page.evaluate("document.querySelector('[data-rrt-book]').rrtBook.state")


with sync_playwright() as p:
	browser = p.chromium.launch(executable_path=CHROME if os.path.exists(CHROME) else None, args=['--autoplay-policy=no-user-gesture-required'])
	ctx = browser.new_context(accept_downloads=True, viewport={'width': 1280, 'height': 1000})
	fake = {'js': ''}
	ctx.route(re.compile(r'.*transformers(\.min)?\.js.*'), lambda r: r.fulfill(status=200, content_type='text/javascript', body=fake['js'], headers={'Access-Control-Allow-Origin': '*'}))
	ctx.route(re.compile(r'.*/mp3\.wasm.*'), lambda r: r.fulfill(status=200, content_type='application/wasm', body=MP3, headers={'Access-Control-Allow-Origin': '*'}))
	ctx.route(re.compile(r'.*/rnnoise\.wasm.*'), lambda r: r.fulfill(status=200, content_type='application/wasm', body=RNN, headers={'Access-Control-Allow-Origin': '*'}))
	page = ctx.new_page()
	errors = []
	page.on('pageerror', lambda e: errors.append(str(e)))
	sign_in(page, BASE)

	# Settings: Keep an audiobook copy.
	page.goto(SETTINGS, wait_until='load')
	box = 'input[name="rrt_settings[sound][book]"]'
	check(page.locator(box).count() == 1 and not page.is_checked(box), 'Settings has Keep an audiobook copy, unticked')
	page.check(box)
	with page.expect_navigation(wait_until='load'):
		page.click('#submit')
	page.goto(SETTINGS, wait_until='load')
	check(page.is_checked(box), 'it is saved ticked')

	# The Audiobook box.
	page.goto(EDIT, wait_until='load')
	page.wait_for_selector('#rrt-audiobook .rrt-book-row', timeout=20000)
	cls = page.evaluate("Array.prototype.map.call(document.querySelectorAll('.rrt-book-row'), function (e) { return e.className; })")
	check(len(cls) == 3 and 'is-book' in cls[0] and 'is-post' in cls[1] and 'is-need' in cls[2], 'each reading shows where its chapter comes from', json.dumps(cls))
	need = page.inner_text('.rrt-book-row.is-need')
	check('music bed' in need and 'Keep as this post' in need and page.locator('.rrt-book-row.is-need a', has_text='Open the studio').count() == 1, 'the reading with the music bed says why it needs a copy, and how', need)
	credits = page.evaluate("Array.prototype.map.call(document.querySelectorAll('.rrt-book-credit'), function (e) { return e.textContent; })")
	check(len(credits) == 2 and 'Recorded' in credits[0] and 'Not recorded yet' in credits[1], 'the opening credits are recorded, the closing ones not yet', json.dumps(credits))
	cover = page.inner_text('#rrt-audiobook').replace('\xa0', ' ')
	check('3000 by 3000' in cover, 'the cover\'s size is shown')
	check('needs an audiobook copy first' in page.inner_text('.rrt-book-act'), 'the button says a reading would be left out')

	# A new closing script is saved.
	close_ta = '.rrt-book-script[data-kind="close"]'
	page.fill(close_ta, 'The end.\nThe turning year was read by Robin.')
	with page.expect_response(lambda r: '/audiobook/col/' in r.url and r.request.method == 'POST', timeout=20000):
		page.dispatch_event(close_ta, 'change')
	page.goto(EDIT, wait_until='load')
	page.wait_for_selector(close_ta, timeout=20000)
	check(page.input_value(close_ta) == 'The end.\nThe turning year was read by Robin.', 'the closing credits\' script is kept', page.input_value(close_ta))

	# Record in the studio opens the credits' studio in a new tab.
	with ctx.expect_page(timeout=20000) as pop:
		page.click('[data-record="close"]')
	tp = pop.value
	tp.on('pageerror', lambda e: errors.append(str(e)))
	tp.wait_for_load_state('load')
	tp.wait_for_function("location.href.indexOf('tp_post=') > 0", timeout=20000)
	close_url = tp.url
	close_id = int(re.search(r'tp_post=(\d+)', close_url).group(1))
	words = review(tp, close_url, fake, 31)
	check(' '.join(words[:2]) == 'The end.' and 'Robin.' in words, 'the studio reads the credits\' script, its first line first', ' '.join(words))
	check(tp.inner_text('#tpRvUseRec') == 'Use for the audiobook', 'on credits the button keeps them for the audiobook', tp.inner_text('#tpRvUseRec'))
	check(not tp.is_visible('#tpRvMusic'), 'credits never get the music bed')
	tp.click('#tpRvUseRec')
	tp.wait_for_function("(document.getElementById('tpRvUseRec') || {}).textContent === 'Kept for the audiobook' || /could not be kept/.test((document.getElementById('tpResultInfo') || {}).textContent || '')", timeout=120000)
	info = tp.evaluate("document.getElementById('tpResultInfo').textContent")
	check(tp.inner_text('#tpRvUseRec') == 'Kept for the audiobook' and 'kept for the collection' in info, 'Use for the audiobook keeps the credits', info[:300])
	tp.close()

	# Imbolc: the review's Finish for menu, then Use this recording with a copy kept too.
	studio = BASE + '/?teleprompter=1&tp_post=%d' % SEED['c']
	review(page, studio, fake, 47)
	opts = page.evaluate("Array.prototype.map.call(document.querySelectorAll('#tpRvFinish option'), function (o) { return o.value; })")
	check('__book' in opts, 'the review\'s Finish for menu can keep the take as the post\'s audiobook chapter', json.dumps(opts))
	page.select_option('#tpRvFinish', '__book')
	check(page.inner_text('#tpRvDownload') == 'Keep for the audiobook', 'the button then says Keep for the audiobook', page.inner_text('#tpRvDownload'))
	page.click('#tpRvDownload')
	page.wait_for_function("/Kept as this post|could not be made/.test(document.getElementById('tpResultInfo').textContent)", timeout=120000)
	info = page.evaluate("document.getElementById('tpResultInfo').textContent")
	check('Kept as this post\'s audiobook chapter' in info and 'unchanged' in info, 'the take is kept as Imbolc\'s audiobook chapter', info[:300])
	got = re.search(r'(\d+):(\d\d), ([\d.]+) MB', info)
	check(got is not None, 'the message gives its length and size', info[:200])
	page.select_option('#tpRvFinish', '')
	page.click('#tpRvUseRec')
	page.wait_for_function("/An audiobook copy is kept too|audiobook copy could not be made/.test(document.getElementById('tpResultInfo').textContent)", timeout=180000)
	info = page.evaluate("document.getElementById('tpResultInfo').textContent")
	check('An audiobook copy is kept too' in info, 'with Keep an audiobook copy ticked, Use this recording keeps one too', info[:400])

	# Back on the collection: the ZIP.
	page.goto(EDIT, wait_until='load')
	page.wait_for_selector('#rrt-audiobook .rrt-book-row', timeout=20000)
	cls = page.evaluate("Array.prototype.map.call(document.querySelectorAll('.rrt-book-row'), function (e) { return e.className; })")
	check('is-book' in cls[0] and 'is-post' in cls[1] and 'is-book' in cls[2], 'Imbolc now has its audiobook copy', json.dumps(cls))
	check('Recorded' in page.evaluate("document.querySelectorAll('.rrt-book-credit')[1].textContent"), 'the closing credits show as recorded')
	with page.expect_download(timeout=300000) as dl:
		page.click('[data-make]')
	d = dl.value
	check(d.suggested_filename.endswith('-audiobook.zip'), 'the ZIP is named for the collection', d.suggested_filename)
	z = zipfile.ZipFile(d.path())
	check(z.testzip() is None, 'the ZIP opens and every file in it is whole')
	names = sorted(z.namelist())
	want = sorted(['00 Opening credits.mp3', '01 Audiobook test Samhain.mp3', '02 Audiobook test Yule.mp3', '03 Audiobook test Imbolc.mp3', '04 Closing credits.mp3', 'Retail sample.mp3', 'Cover.jpg', 'Check report.html'])
	check(names == want, 'it holds the credits, a file for each reading, the sample, the cover and the report', json.dumps(names))
	for n in [x for x in names if x.endswith('.mp3')]:
		fr = frames(z.read(n))
		ok = fr is not None and len(fr) > 20 and all(f == (192, 44100, 1) for f in fr)
		check(ok, n + ' is a 192 kbps constant-bitrate MP3, 44.1 kHz mono', str(fr[:2] if fr else fr))
	a_book = open(os.path.join(DIR, 'a-book.mp3'), 'rb').read()
	one = z.read('01 Audiobook test Samhain.mp3')
	check(one[audio_start(one):] == a_book[audio_start(a_book):], 'Samhain\'s chapter is its audiobook copy, byte for byte')
	check(b'TRCK' in one[:audio_start(one)] and 'Audiobook test Samhain'.encode() in one[:audio_start(one)], 'under a tag with its title and track number')
	check(hashlib.md5(z.read('Cover.jpg')).hexdigest() == SEED['cover'], 'the cover is the featured image as it is')
	page.wait_for_selector('.rrt-book-report', timeout=30000)
	tables = page.evaluate("""() => {
		var out = {}, cur = 'book';
		document.querySelectorAll('.rrt-book-report > *').forEach(function (e) {
			if (e.tagName === 'H4' && e.classList.contains('rrt-book-file')) { cur = e.textContent; }
			if (e.tagName === 'TABLE') { out[cur] = Array.prototype.map.call(e.querySelectorAll('tr'), function (tr) { return [tr.className, tr.children[1].textContent, tr.children[2].textContent]; }); }
		});
		return out;
	}""")
	yule = tables.get('02 Audiobook test Yule.mp3', [])
	bad = [r for r in yule if 'is-bad' in r[0]]
	labels = [r[1] for r in yule]
	check(yule and not bad, 'Yule\'s chapter, made from its post\'s MP3, passes every rule', json.dumps(bad or yule))
	check(all(l in labels for l in ['Level (RMS)', 'Peaks', 'True peak', 'Background', 'Room tone at the start', 'Room tone at the end', 'Length', 'Channels', 'Heading read aloud']), 'its report covers level, peaks, background, room tone, length, channels and the heading', json.dumps(labels))
	heading = [r for r in yule if r[1] == 'Heading read aloud']
	check(heading and 'is-ok' in heading[0][0] and 'audiobook test yule' in heading[0][2], 'the heading is heard first, from the captions', json.dumps(heading))
	sample = tables.get('Retail sample.mp3', [])
	length = [r for r in sample if r[1] == 'Length']
	m = re.match(r'(\d+):(\d\d)', length[0][2]) if length else None
	secs = int(m.group(1)) * 60 + int(m.group(2)) if m else 0
	check(55 <= secs <= 70, 'the retail sample is about a minute', str(secs))
	book = dict((r[1], r) for r in tables.get('book', []))
	check('is-ok' in book.get('Chapters', [''])[0] and 'is-ok' in book.get('Opening credits', [''])[0] and 'is-ok' in book.get('Closing credits', [''])[0] and 'is-ok' in book.get('Cover', [''])[0], 'the whole book: every chapter, both credits and the cover', json.dumps(book))
	html = z.read('Check report.html').decode('utf-8')
	check('02 Audiobook test Yule.mp3' in html and 'Not checked here' in html and 'Kindle' in html, 'the ZIP\'s report has every file and what was not checked')
	msg = page.inner_text('.rrt-book-msg')
	check(msg.startswith('Downloaded ') and '8 files' in msg, 'the box says what was downloaded', msg)
	page.wait_for_timeout(200)

	# Settings back as they were.
	page.goto(SETTINGS, wait_until='load')
	page.uncheck(box)
	with page.expect_navigation(wait_until='load'):
		page.click('#submit')
	check(not errors, 'no script errors', '; '.join(errors[:3]))
	browser.close()

print('\n%d passed, %d failed' % (passed, len(failed)))
sys.exit(1 if failed else 0)
