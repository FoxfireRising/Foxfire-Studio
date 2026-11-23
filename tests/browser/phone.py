#!/usr/bin/env python3
"""
Phone level (1.9.0), in the studio: a stand-in microphone at a phone's level
in the browser (about -54 LUFS, its room about 39 dB under the voice, written
as floating point so it has detail below 16 bits), on a phone and on a computer.

On a phone the take is kept in 24 bits (Original, and a take recovered from
the browser), the check of the microphone says how much lift a take will need
and that the room allows it, and the finished MP3 reaches -19 LUFS with the
read-out saying the quiet room allowed more than the usual lift. A take
sent from an iPhone arrives on the computer in 24 bits and finishes at -19.
On a computer the same microphone gives a 16-bit take, as before. A take
whose first words are louder than the rest keeps the quieter ones.

Usage: PH_POST=<post id> tests/browser/phone.py http://localhost:8080
"""
import base64, math, os, random, re, struct, sys, tempfile
from playwright.sync_api import sync_playwright
from signin import sign_in
from take_assets import MP3, RNN

BASE = sys.argv[1].rstrip('/') if len(sys.argv) > 1 else 'http://localhost:8080'
CHROME = os.environ.get('CHROME_PATH', '/usr/bin/google-chrome')
POST_ID = int(os.environ['PH_POST'])
TP = BASE + '/?teleprompter=1&tp_post=%d' % POST_ID
IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1'
ANDROID = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36'

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


def mic_wav(loud_start=0.0):
	"""Word-like bursts at a phone's level over a room hiss 39 dB down, 48 kHz, 32-bit float (Chrome's stand-in reads no 24-bit files), 8 s, looped.
	loud_start: seconds at the start read 18 dB louder, with peaks over -38 dBFS, as a real phone take had."""
	rate = 48000
	n = rate * 8
	rnd = random.Random(5)
	amp = 0.0042  # the bursts measure about -54 LUFS
	room = 10 ** (-93 / 20.0) * math.sqrt(3)
	out = bytearray()
	for i in range(n):
		t = i / rate
		k = int(t / 0.4)
		inword = (t % 0.4) < 0.27 and (k % 6) != 5
		v = 0.0
		if inword:
			u = (t % 0.4) / 0.27
			env = math.sin(math.pi * u)
			f = 150 + 12 * (k % 7)
			v = amp * (8 if t < loud_start else 1) * env * (math.sin(2 * math.pi * f * t) + 0.5 * math.sin(4 * math.pi * f * t))
		v += room * (2 * rnd.random() - 1)
		out += struct.pack('<f', v)
	head = b'RIFF' + struct.pack('<I', 36 + len(out)) + b'WAVEfmt ' + struct.pack('<IHHIIHH', 16, 3, 1, rate, rate * 4, 4, 32) + b'data' + struct.pack('<I', len(out))
	f = tempfile.NamedTemporaryFile(suffix='.wav', delete=False)
	f.write(head + bytes(out))
	f.close()
	return f.name


def wav_info(data):
	"""(bits, rate, samples, share of samples with detail below 16 bits) of a mono PCM WAV."""
	if data[:4] != b'RIFF' or data[8:12] != b'WAVE':
		return None
	bits = struct.unpack('<H', data[34:36])[0]
	rate = struct.unpack('<I', data[24:28])[0]
	size = struct.unpack('<I', data[40:44])[0]
	body = data[44:44 + size]
	fine = 0
	if bits == 24:
		count = len(body) // 3
		step = max(1, count // 20000)
		seen = 0
		for j in range(0, count, step):
			seen += 1
			if body[j * 3] != 0:
				fine += 1
		share = fine / float(max(1, seen))
	else:
		count = len(body) // 2
		share = 0.0
	return bits, rate, count, share


with sync_playwright() as p:
	MIC = mic_wav()
	browser = p.chromium.launch(executable_path=CHROME if os.path.exists(CHROME) else None, args=['--autoplay-policy=no-user-gesture-required', '--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream', '--use-file-for-fake-audio-capture=' + MIC])

	def open_ctx(ua):
		kw = {'permissions': ['microphone']}
		if ua:
			kw['user_agent'] = ua
			# 1.31.0: a phone is a touch-first device (the user agent alone no longer makes one).
			kw.update({'is_mobile': True, 'has_touch': True, 'viewport': {'width': 393, 'height': 852}})
		ctx = browser.new_context(**kw)
		ctx.route(re.compile(r'.*/mp3\.wasm.*'), lambda r: r.fulfill(status=200, content_type='application/wasm', body=MP3, headers={'Access-Control-Allow-Origin': '*'}))
		ctx.route(re.compile(r'.*/rnnoise\.wasm.*'), lambda r: r.fulfill(status=200, content_type='application/wasm', body=RNN, headers={'Access-Control-Allow-Origin': '*'}))
		page = ctx.new_page()
		errors = []
		page.on('pageerror', lambda e: errors.append(str(e)))
		sign_in(page, BASE)
		return ctx, page, errors

	def download(page, sel):
		with page.expect_download(timeout=90000) as d:
			page.evaluate("s => document.querySelector(s).click()", sel)
		return open(d.value.path(), 'rb').read()

	def setopt(page, sel, on):
		page.evaluate("a => { var e = document.querySelector(a[0]); if (e && e.checked !== a[1]) { e.click(); } }", [sel, on])

	def record(page, secs, whisper=False, wait_s=None):
		"""A take with the stand-in microphone. wait_s: on a phone, the length above which the review waits to be asked (1.31.0)."""
		page.goto(TP, wait_until='load')
		page.wait_for_timeout(600)
		if wait_s is not None:
			page.evaluate("s => { window.RRTStudio.PHONE_AUTO_S = s; }", wait_s)
		setopt(page, '#tpRecOpt', True)
		setopt(page, '#tpWhisperOpt', whisper)
		# Phone take mode (1.12.0) has its own test (livetake.py); here the phone finishes takes itself.
		setopt(page, '#tpPhoneOpt', False)
		page.evaluate("document.getElementById('tpPlay').click()")
		page.wait_for_function("!document.getElementById('tpFinish').hidden", timeout=15000)
		page.wait_for_timeout(int(secs * 1000))
		page.evaluate("document.getElementById('tpFinish').click()")
		page.wait_for_function("document.getElementById('tpDownload').href.indexOf('blob:') === 0 ? !document.getElementById('tpDownload').hidden : false", timeout=120000)

	def loudness_of(page, data):
		"""Integrated loudness of an audio file, decoded by Chrome and measured by the plugin's own meter."""
		return page.evaluate("""b => {
			var s = atob(b), u = new Uint8Array(s.length);
			for (var i = 0; i < s.length; i++) { u[i] = s.charCodeAt(i); }
			return new OfflineAudioContext(1, 1, 44100).decodeAudioData(u.buffer).then(function (a) { return window.RRTSound.lufs(a.getChannelData(0), a.sampleRate); });
		}""", base64.b64encode(data).decode())

	# --- On a phone ---
	ctx, page, errors = open_ctx(ANDROID)
	page.goto(TP, wait_until='load')
	page.wait_for_timeout(600)
	check(page.evaluate("window.RRTStudio.PHONE === true ? window.RRTStudio.CAPTURE_BITS : -1") == 24, 'a phone keeps its takes in 24 bits')

	# The microphone check: the lift a take will need, and that this room allows it.
	page.evaluate("try { localStorage.removeItem('rrtMicCheck'); } catch (e) {}")
	page.evaluate("document.getElementById('tpMicCheck').click()")
	page.wait_for_selector('.tp-mic', timeout=5000)
	page.evaluate("document.querySelector('.tp-mic .tp-btn.primary').click()")
	page.wait_for_function("(document.querySelector('.tp-mic-out') || {}).textContent ? document.querySelector('.tp-mic-out').textContent.length > 0 : false", timeout=20000)
	said = page.evaluate("document.querySelector('.tp-mic-out').textContent")
	m = re.search(r'needs about (\d+) dB of lift to reach -19 LUFS\. The studio can raise it by up to (\d+) dB', said)
	check(bool(m), 'the check says how much lift a take will need, and that the room allows it', said)
	if m:
		check(30 < int(m.group(1)) < 40, 'a phone-level voice needs about 35 dB', m.group(1))
		check(int(m.group(2)) == 45, 'a quiet room allows up to 45 dB', m.group(2))
	check('leveling can lift it' not in said, 'the older quiet-voice line gives way to the lift line')
	saved = page.evaluate("JSON.parse(localStorage.getItem('rrtMicCheck') || 'null')")
	check(bool(saved) and saved.get('liftOver') is False and isinstance(saved.get('liftNeed'), int), 'the check is saved with its lift', str(saved))
	page.evaluate("document.querySelectorAll('.tp-mic .tp-btn')[1].click()")
	last = page.evaluate("document.getElementById('tpMicLast').textContent")
	check('too quiet' not in last and last.startswith('Mic: room'), 'the control bar line has no warning when the lift is within reach', last)

	del errors[:]
	record(page, 6)
	raw = download(page, '#tpDownloadRaw')
	info = wav_info(raw)
	check(bool(info) and info[0] == 24, 'Original is a 24-bit WAV on a phone', str(info[:3] if info else None))
	if info:
		check(5 < info[2] / float(info[1]) < 9, 'it holds what was read', '%.2f s' % (info[2] / float(info[1])))
		check(info[3] > 0.5, 'it keeps the detail below 16 bits', '%.2f of samples' % info[3])
	mp3 = download(page, '#tpDownload')
	lu = loudness_of(page, mp3)
	check(abs(lu - -19) < 1.0, 'the phone take reaches -19 LUFS', '%.1f' % lu)
	# The read-out is written once the finished file has been checked, which can come after the downloads are ready.
	try:
		page.wait_for_function("/LUFS/.test(document.getElementById('tpReadout').textContent)", timeout=60000)
	except Exception:
		pass
	readout = page.evaluate("document.getElementById('tpReadout').textContent")
	m = re.search(r'the room was quiet enough to raise it by (\d+) dB \(more than the usual 30\)', readout)
	check(bool(m), 'the read-out says the quiet room allowed more than the usual lift', readout[:400])
	check('could only be raised' not in readout, 'the take was not held back')
	check(not errors, 'no script errors on the phone', '; '.join(errors))

	# A take never downloaded comes back from the browser in 24 bits.
	record(page, 3)
	page.goto(TP, wait_until='load')
	try:
		page.wait_for_selector('#tpRecover.is-on', timeout=10000)
		offered = True
	except Exception:
		offered = False
	check(offered, 'an unfinished phone take is offered back')
	if offered:
		page.click('#tpRecoverGo')
		page.wait_for_function("document.getElementById('tpDownload').href.indexOf('blob:') === 0 ? !document.getElementById('tpDownload').hidden : false", timeout=120000)
		back = wav_info(download(page, '#tpDownloadRaw'))
		check(bool(back) and back[0] == 24 and 2 < back[2] / float(back[1]) < 6, 'the recovered take is the same 24-bit recording', str(back[:3] if back else None))
		download(page, '#tpDownload')
	check(not errors, 'no script errors while recovering', '; '.join(errors))
	ctx.close()

	# --- An iPhone sends its take to the computer ---
	ctx, page, errors = open_ctx(IPHONE)
	# A take longer than the phone's limit (set to 1 s here) offers Send to my computer.
	record(page, 4, whisper=True, wait_s=1)
	try:
		page.wait_for_selector('.tp-wsend', timeout=30000)
		page.evaluate("document.querySelector('.tp-wsend').click()")
		page.wait_for_function("document.querySelector('.tp-wsent') ? /Sent\\./.test(document.querySelector('.tp-wsent').textContent) : false", timeout=60000)
		sent = True
	except Exception:
		sent = False
	check(sent, 'an iPhone take is sent to the computer')
	check(not errors, 'no script errors on the iPhone', '; '.join(errors))
	ctx.close()

	# --- On a computer: the sent take, then the same microphone, 16 bits as before ---
	ctx, page, errors = open_ctx(None)
	page.goto(TP, wait_until='load')
	page.wait_for_timeout(600)
	setopt(page, '#tpWhisperOpt', False)
	if sent:
		head = page.evaluate("""() => {
			var S = window.RRTStudio.SENT, t = (S.waiting || [])[0];
			if (!t) { return null; }
			return fetch(S.rest + 'sent/' + t.id + '/audio', { credentials: 'same-origin', headers: { 'X-WP-Nonce': S.nonce } }).then(function (r) { return r.arrayBuffer(); }).then(function (b) { return Array.prototype.slice.call(new Uint8Array(b, 0, 44)); });
		}""")
		bits = struct.unpack('<H', bytes(head[34:36]))[0] if head else None
		check(bits == 24, 'the sent take arrives on the computer in 24 bits', str(bits))
		page.goto(TP, wait_until='load')
		page.wait_for_selector('#tpSent.is-on', timeout=10000)
		page.click('#tpSentGo')
		page.wait_for_function("document.getElementById('tpDownload').href.indexOf('blob:') === 0 ? !document.getElementById('tpDownload').hidden : false", timeout=120000)
		lu = loudness_of(page, download(page, '#tpDownload'))
		check(abs(lu - -19) < 1.0, 'the sent phone take finishes at -19 LUFS on the computer', '%.1f' % lu)
	check(page.evaluate("window.RRTStudio.CAPTURE_BITS") == 16, 'a computer keeps 16 bits')
	record(page, 3)
	info = wav_info(download(page, '#tpDownloadRaw'))
	check(bool(info) and info[0] == 16, 'Original is a 16-bit WAV on a computer', str(info[:3] if info else None))
	download(page, '#tpDownload')
	check(not errors, 'no script errors on the computer', '; '.join(errors))
	ctx.close()
	browser.close()

	# A phone take whose first words are louder than the rest: before 1.9.0 the trim's fixed
	# -38 dBFS threshold cut a real one after its last loud word, keeping 24 s of 72.
	MIC = mic_wav(loud_start=1.2)
	browser = p.chromium.launch(executable_path=CHROME if os.path.exists(CHROME) else None, args=['--autoplay-policy=no-user-gesture-required', '--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream', '--use-file-for-fake-audio-capture=' + MIC])
	ctx, page, errors = open_ctx(ANDROID)
	record(page, 7)
	raw = wav_info(download(page, '#tpDownloadRaw'))
	raw_s = raw[2] / float(raw[1]) if raw else 0
	mp3_s = page.evaluate("""() => fetch(document.getElementById('tpDownload').href).then(function (r) { return r.arrayBuffer(); }).then(function (b) { return new OfflineAudioContext(1, 1, 44100).decodeAudioData(b); }).then(function (a) { return a.duration; })""")
	check(raw_s > 6 and mp3_s > raw_s - 1.5, 'a take with loud first words keeps its quieter words to the end', 'take %.1f s, MP3 %.1f s' % (raw_s, mp3_s))
	check(not errors, 'no script errors on the take with loud first words', '; '.join(errors))
	ctx.close()
	browser.close()

print('\n%d passed, %d failed' % (passed, len(failed)))
sys.exit(1 if failed else 0)
