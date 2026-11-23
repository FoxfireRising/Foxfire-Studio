#!/usr/bin/env python3
"""
Finishing presets (1.6.0), end to end.

Settings: the Finish for menu fills in each preset's loudness, true peak and
channels and keeps them read-only; Custom shows its own numbers and can be
changed; switching between them never overwrites Custom's numbers.

Then a take is opened and reviewed with Podcast, stereo chosen: the file is a
stereo MP3 at -16 LUFS (both channels counted), the read-out names the preset
and the studio check reports the loudness range. In the review, the one-off
Finish for menu downloads a Broadcast version: a 48 kHz 24-bit stereo WAV at
-23 LUFS, while the download button behind the review keeps the stereo MP3.
Last, with Broadcast chosen in Settings, a take is a WAV from the start and
Use this recording puts the WAV on the post (run.sh checks its type).

Whisper is a stand-in; the MP3 encoder and noise model are the real ones
from npm, as in take.py.

Usage: FN_POST=<post id> tests/browser/finish.py http://localhost:8080
The post must read: "<p>Leaves fall slowly. Rain taps the glass.</p><p>Owls call softly. The lamp stays lit.</p>"
"""
import io, json, math, os, re, struct, sys, tempfile, wave
from playwright.sync_api import sync_playwright
from signin import sign_in
from whisper_stub import PIECE
from take_assets import MP3, RNN

BASE = sys.argv[1].rstrip('/') if len(sys.argv) > 1 else 'http://localhost:8080'
CHROME = os.environ.get('CHROME_PATH', '/usr/bin/google-chrome')
POST_ID = int(os.environ['FN_POST'])
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


def wav_facts(data):
	"""Channels, rate and bits of a PCM WAV, or None."""
	if data[:4] != b'RIFF' or data[8:12] != b'WAVE':
		return None
	fmt, nc, rate, _, _, bits = struct.unpack('<HHIIHH', data[20:36])
	return {'fmt': fmt, 'channels': nc, 'rate': rate, 'bits': bits, 'bytes': len(data)}


def choose(page, value):
	"""Chooses a preset on the Settings screen, as a person would."""
	page.select_option('#rrt-sound-finish', value)


def fields(page):
	return page.evaluate("""() => {
		var l = document.getElementById('rrt-sound-lufs'), t = document.getElementById('rrt-sound-tp'), c = document.getElementById('rrt-sound-ch');
		return { lufs: l.value, tp: t.value, ch: c.value, ro: l.readOnly && t.readOnly && c.disabled, help: document.getElementById('rrt-finish-help').textContent, lufsShown: !l.closest('tr').hidden, rowsShown: !t.closest('tr').hidden };
	}""")


def save_settings(page, value, custom=None):
	page.goto(SETTINGS, wait_until='load')
	choose(page, value)
	if custom:
		page.fill('#rrt-sound-lufs', custom[0])
		page.dispatch_event('#rrt-sound-lufs', 'change')
		page.fill('#rrt-sound-tp', custom[1])
		page.dispatch_event('#rrt-sound-tp', 'change')
		page.select_option('#rrt-sound-ch', custom[2])
		page.dispatch_event('#rrt-sound-ch', 'change')
	with page.expect_navigation(wait_until='load'):
		page.click('#submit')
	page.goto(SETTINGS, wait_until='load')
	return page.input_value('#rrt-sound-finish')


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

	# Settings: the menu and its numbers.
	page.goto(SETTINGS, wait_until='load')
	opts = page.evaluate("Array.prototype.map.call(document.querySelectorAll('#rrt-sound-finish option'), function (o) { return o.value; })")
	check(opts == ['podcast_mono', 'podcast_stereo', 'streaming', 'broadcast', 'acx', 'custom', 'off'], 'Settings offers every preset in one Finish for menu', json.dumps(opts))
	check(page.input_value('#rrt-sound-finish') == 'podcast_mono', 'a new site finishes as Podcast, mono')
	f = fields(page)
	check(f['lufs'] == '-19' and f['tp'] == '-1' and f['ch'] == 'mono' and f['ro'], 'Podcast, mono fills in -19 LUFS, -1 dBTP and mono, read-only', json.dumps(f))
	choose(page, 'streaming')
	f = fields(page)
	check(f['lufs'] == '-14' and f['ch'] == 'stereo' and f['ro'] and '192 kbps' in f['help'], 'Spotify and YouTube fills in -14 LUFS and stereo, with its own help', json.dumps(f))
	choose(page, 'acx')
	f = fields(page)
	check(not f['lufsShown'] and f['tp'] == '-3', 'Audiobook (ACX) hides the loudness (it is RMS) and shows -3 dB peaks', json.dumps(f))
	choose(page, 'off')
	f = fields(page)
	check(not f['rowsShown'], 'Simple leveling hides the numbers')
	choose(page, 'custom')
	f = fields(page)
	own = page.evaluate("['rrt-sound-lufs', 'rrt-sound-tp', 'rrt-sound-ch'].map(function (i) { return document.getElementById(i).getAttribute('data-custom'); })")
	check(not f['ro'] and [f['lufs'], f['tp'], f['ch']] == own, 'Custom shows its own numbers and can be changed', json.dumps(f) + ' ' + json.dumps(own))

	# Custom's numbers are kept when another preset is saved.
	got = save_settings(page, 'custom', ('-17.5', '-1.5', 'stereo'))
	f = fields(page)
	check(got == 'custom' and f['lufs'] == '-17.5' and f['tp'] == '-1.5' and f['ch'] == 'stereo', 'Custom saves its loudness, peak limit and channels', json.dumps(f))
	got = save_settings(page, 'podcast_stereo')
	check(got == 'podcast_stereo', 'Podcast, stereo is saved')
	choose(page, 'custom')
	f = fields(page)
	check(f['lufs'] == '-17.5' and f['tp'] == '-1.5' and f['ch'] == 'stereo', 'saving another preset keeps Custom\'s numbers', json.dumps(f))

	# A take with Podcast, stereo.
	page.goto(TP, wait_until='load')
	page.evaluate("try { sessionStorage.setItem('rrtIntroSeen', '1'); localStorage.setItem('rrtIntro', 'off'); } catch (e) {}")
	page.goto(TP, wait_until='load')
	page.wait_for_timeout(500)
	page.evaluate("a => { var e = document.querySelector(a); if (!e.checked) { e.click(); } }", '#tpWhisperOpt')
	words = page.evaluate("Array.prototype.map.call(document.querySelectorAll('.tp-w'), function (e) { return e.textContent; })")
	heard, secs = plan(words)
	fake['js'] = fake_transformers(heard)
	take = wav_for(heard, secs, 23)

	def review():
		page.goto(TP, wait_until='load')
		page.wait_for_timeout(500)
		page.set_input_files('#tpFile', take)
		page.wait_for_function("document.body.classList.contains('is-reviewing')", timeout=120000)
		page.wait_for_timeout(300)

	review()
	name = page.get_attribute('#tpDownload', 'download') or ''
	check(name.endswith('.mp3'), 'the take is an MP3', name)
	m = page.evaluate("""() => fetch(document.getElementById('tpDownload').href).then(r => r.arrayBuffer()).then(ab => {
		var C = new OfflineAudioContext(2, 1, 44100);
		return C.decodeAudioData(ab);
	}).then(b => {
		var ch = []; for (var c = 0; c < b.numberOfChannels; c++) { ch.push(b.getChannelData(c)); }
		return { n: b.numberOfChannels, lufs: window.RRTSound.lufsMulti(ch, b.sampleRate), tp: Math.max.apply(null, ch.map(window.RRTSound.truePeakDb)) };
	})""")
	check(m['n'] == 2, 'Podcast, stereo makes a stereo MP3', str(m['n']))
	check(abs(m['lufs'] - -16) <= 1, 'it measures -16 LUFS with both channels counted', '%.2f' % m['lufs'])
	check(m['tp'] <= -0.9, 'with peaks under -1 dBTP', '%.2f' % m['tp'])
	page.wait_for_function("/Studio check/.test(document.getElementById('tpReadout').textContent)", timeout=30000)
	ro = page.evaluate("document.getElementById('tpReadout').textContent")
	check('Podcast, stereo' in ro and 'stereo MP3' in ro, 'the read-out names the preset and the file', ro[:300])
	check('Loudness range' in ro and ' LU' in ro, 'the studio check reports the loudness range', ro[-400:])

	# The one-off menu in the review.
	check(page.is_visible('#tpRvFinish'), 'the review has a Finish for menu beside Download')
	ro_opts = page.evaluate("Array.prototype.map.call(document.querySelectorAll('#tpRvFinish option'), function (o) { return o.value; })")
	check(ro_opts[0] == '' and 'podcast_stereo' not in ro_opts and 'off' not in ro_opts and 'broadcast' in ro_opts, 'it starts at the Settings choice and offers the others', json.dumps(ro_opts))
	check(page.inner_text('#tpRvFinish option[value=""]').endswith('(Settings)'), 'the first choice says it is the Settings one', page.inner_text('#tpRvFinish option[value=""]'))
	page.select_option('#tpRvFinish', 'broadcast')
	check(page.inner_text('#tpRvDownload').startswith('Download WAV'), 'choosing Broadcast makes the button Download WAV', page.inner_text('#tpRvDownload'))
	with page.expect_download(timeout=120000) as dl:
		page.click('#tpRvDownload')
	d = dl.value
	data = open(d.path(), 'rb').read()
	w = wav_facts(data)
	check(d.suggested_filename.endswith('-broadcast.wav'), 'the one-off file is named for its preset', d.suggested_filename)
	check(w is not None and w['fmt'] == 1 and w['channels'] == 2 and w['rate'] == 48000 and w['bits'] == 24, 'Broadcast is a stereo PCM WAV at 48 kHz and 24 bits', json.dumps(w))
	page.wait_for_function("document.getElementById('tpRvDownload').disabled === false", timeout=30000)
	msg = page.evaluate("document.getElementById('tpResultInfo').textContent")
	got = re.search(r'(-?\d+\.\d) LUFS', msg)
	check(got is not None and abs(float(got.group(1)) - -23) <= 1, 'the one-off version measures -23 LUFS', msg[:300])
	check('Use this recording still uses the choice in Settings' in msg, 'the message says Use this recording is unchanged', msg[:200])
	check((page.get_attribute('#tpDownload', 'download') or '').endswith('.mp3'), 'the download behind the review is still the Settings MP3')
	page.select_option('#tpRvFinish', '')
	check(re.match(r'Download (cleaned )?MP3', page.inner_text('#tpRvDownload')) is not None, 'back at the Settings choice, the button is the MP3 download again', page.inner_text('#tpRvDownload'))

	# Broadcast in Settings: the take is a WAV, and Use this recording puts it on the post.
	got = save_settings(page, 'broadcast')
	check(got == 'broadcast', 'Broadcast is saved')
	review()
	name = page.get_attribute('#tpDownload', 'download') or ''
	check(name.endswith('.wav') and re.match(r'Download (cleaned )?WAV', page.inner_text('#tpDownload')) is not None, 'with Broadcast chosen, the take is a WAV', name + ' / ' + page.inner_text('#tpDownload'))
	page.click('#tpRvUseRec')
	page.wait_for_function("(document.getElementById('tpRvUseRec') || {}).textContent === 'On the post now' || /could not be put/.test((document.getElementById('tpResultInfo') || {}).textContent || '')", timeout=60000)
	check(page.inner_text('#tpRvUseRec') == 'On the post now', 'Use this recording puts the WAV on the post', page.evaluate("(document.getElementById('tpResultInfo') || {}).textContent || ''")[:200])

	# Back to the defaults for the tests that follow, Custom's numbers included.
	save_settings(page, 'custom', ('-19', '-1', 'mono'))
	got = save_settings(page, 'podcast_mono')
	check(got == 'podcast_mono', 'Podcast, mono is chosen again')
	check(not errors, 'no script errors', '; '.join(errors[:3]))
	browser.close()

print('\n%d passed, %d failed' % (passed, len(failed)))
sys.exit(1 if failed else 0)
