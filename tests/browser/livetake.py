#!/usr/bin/env python3
"""
Phone take mode (1.12.0), in the studio, with a stand-in microphone at a
phone's level (about -54 LUFS).

On an iPhone: the switch is shown and on; while recording the large meter
says Good and the site line counts up; at Stop the take is finished on the
site, byte for byte the same audio as Original, the phone's own copy is
forgotten, and the MP3 is made only when asked, with the review pointing to
the computer. Then a take whose pieces stop going up partway (the line says
it is waiting for the connection) and whose page is reloaded mid-take: the
next visit sends the rest, the take on the site is cut off with all of the
audio the phone kept, and Carry on starts a new take one sentence before
where it stopped. On a computer, the cut take is offered as cut off and
opens for the review, reaching -19 LUFS.

Usage: LT_POST=<post id> tests/browser/livetake.py http://localhost:8080
"""
import base64, math, os, random, re, struct, sys, tempfile, time
from playwright.sync_api import sync_playwright
from signin import sign_in
from take_assets import MP3, RNN

BASE = sys.argv[1].rstrip('/') if len(sys.argv) > 1 else 'http://localhost:8080'
CHROME = os.environ.get('CHROME_PATH', '/usr/bin/google-chrome')
POST_ID = int(os.environ['LT_POST'])
TP = BASE + '/?teleprompter=1&tp_post=%d' % POST_ID
IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1'

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


def mic_wav():
	"""Word-like bursts at a phone's level over a room hiss 39 dB down, 48 kHz, 32-bit float, 8 s, looped."""
	rate = 48000
	n = rate * 8
	rnd = random.Random(7)
	amp = 0.0042
	room = 10 ** (-93 / 20.0) * math.sqrt(3)
	out = bytearray()
	for i in range(n):
		t = i / rate
		k = int(t / 0.4)
		v = 0.0
		if (t % 0.4) < 0.27 and (k % 6) != 5:
			u = (t % 0.4) / 0.27
			f = 150 + 12 * (k % 7)
			v = amp * math.sin(math.pi * u) * (math.sin(2 * math.pi * f * t) + 0.5 * math.sin(4 * math.pi * f * t))
		v += room * (2 * rnd.random() - 1)
		out += struct.pack('<f', v)
	head = b'RIFF' + struct.pack('<I', 36 + len(out)) + b'WAVEfmt ' + struct.pack('<IHHIIHH', 16, 3, 1, rate, rate * 4, 4, 32) + b'data' + struct.pack('<I', len(out))
	f = tempfile.NamedTemporaryFile(suffix='.wav', delete=False)
	f.write(head + bytes(out))
	f.close()
	return f.name


WAITING = """() => { var S = window.RRTStudio.SENT; return fetch(S.rest + 'sent', { credentials: 'same-origin', headers: { 'X-WP-Nonce': S.nonce } }).then(function (r) { return r.json(); }).then(function (j) { return j.waiting || []; }); }"""
AUDIO = """id => { var S = window.RRTStudio.SENT; return fetch(S.rest + 'sent/' + id + '/audio', { credentials: 'same-origin', headers: { 'X-WP-Nonce': S.nonce } }).then(function (r) { return r.arrayBuffer(); }).then(function (b) { var u = new Uint8Array(b), s = ''; for (var i = 0; i < u.length; i += 32768) { s += String.fromCharCode.apply(null, u.subarray(i, i + 32768)); } return btoa(s); }); }"""
LOCAL = """() => window.RRTStudio.takeStore.list().then(function (a) { return a.map(function (t) { return { id: t.id, site: t.site || '', samples: t.samples, rate: t.rate, ended: !!t.ended }; }); })"""


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

	def setopt(page, sel, on):
		page.evaluate("a => { var e = document.querySelector(a[0]); if (e && e.checked !== a[1]) { e.click(); } }", [sel, on])

	def begin(page):
		setopt(page, '#tpRecOpt', True)
		setopt(page, '#tpWhisperOpt', True)
		page.evaluate("document.getElementById('tpPlay').click()")
		page.wait_for_function("!document.getElementById('tpFinish').hidden", timeout=15000)

	def finish_saved(page):
		page.evaluate("document.getElementById('tpFinish').click()")
		page.wait_for_function("/Saved on the site/.test(document.getElementById('tpResultInfo').textContent)", timeout=60000)

	def download(page, sel):
		with page.expect_download(timeout=90000) as d:
			page.evaluate("s => document.querySelector(s).click()", sel)
		return open(d.value.path(), 'rb').read()

	# --- An iPhone, a whole take ---
	ctx, page, errors = open_ctx(IPHONE)
	page.goto(TP, wait_until='load')
	page.wait_for_timeout(600)
	check(page.evaluate("!document.getElementById('tpPhoneWrap').hidden ? document.getElementById('tpPhoneOpt').checked : false"), 'Phone take mode is shown in Controls, Recording, and on')
	begin(page)
	page.wait_for_timeout(5000)
	check(page.evaluate("!document.getElementById('tpPMeter').hidden ? document.body.classList.contains('tp-phone-rec') : false"), 'the large meter shows while recording')
	word = page.evaluate("[document.getElementById('tpPMeterWord').getAttribute('data-word'), document.getElementById('tpPMeterWord').textContent]")
	check(word[0] == 'good' and word[1] == 'Good', 'a phone-level voice in a quiet room reads Good', str(word))
	fill = page.evaluate("parseFloat(document.getElementById('tpPMeterFill').style.width) || 0")
	check(5 < fill < 60, 'the bar sits low on the scale for a phone voice', str(fill))
	page.wait_for_timeout(18000)
	line = page.evaluate("document.getElementById('tpPMeterSite').textContent")
	m = re.search(r'On the site up to 0:(\d\d), ([\d.]+) MB sent', line)
	check(bool(m) and int(m.group(1)) >= 10, 'the site line counts what is on the site', line)
	finish_saved(page)
	info = page.evaluate("document.getElementById('tpResultInfo').textContent")
	check(page.evaluate("document.getElementById('tpDownload').hidden ? !document.getElementById('tpMakeHere').hidden : false"), 'after Stop the MP3 waits to be asked for', info)
	check(page.evaluate("document.getElementById('tpPMeter').hidden"), 'the meter goes after Stop')
	check(not page.evaluate("window.RRTStudio.unsaved"), 'the page no longer warns before closing')
	wait = page.evaluate(WAITING)
	t = wait[0] if wait else {}
	check(t.get('state') == 'done' and t.get('bits') == 24 and t.get('post') == POST_ID, 'the take is finished on the site, in 24 bits', str(t))
	site = base64.b64decode(page.evaluate(AUDIO, t.get('id', '')))
	raw = download(page, '#tpDownloadRaw')
	check(len(site) > 44 and site[44:] == raw[44:] and site[:4] == b'RIFF', 'the site holds the same audio as Original, byte for byte', '%d and %d bytes' % (len(site), len(raw)))
	rate = struct.unpack('<I', site[24:28])[0] if len(site) > 44 else 48000
	secs = (len(site) - 44) / (rate * 3.0)
	check(abs(secs - t.get('seconds', 0)) < 0.1 and secs > 20, 'its length is known', '%.1f s, the site says %s' % (secs, t.get('seconds')))
	local = page.evaluate(LOCAL)
	check(not [x for x in local if x['site'] == t.get('id')], 'the phone forgets its own copy once the site has it', str(local))
	page.evaluate("document.getElementById('tpMakeHere').click()")
	page.wait_for_function("document.getElementById('tpDownload').href.indexOf('blob:') === 0 ? !document.getElementById('tpDownload').hidden : false", timeout=120000)
	page.wait_for_function("document.getElementById('tpWhisperStatus').textContent.length > 0", timeout=30000)
	ws = page.evaluate("document.getElementById('tpWhisperStatus').textContent")
	check('already on the site' in ws and not page.evaluate("!!document.querySelector('.tp-wsend')"), 'Make the MP3 here works, and the review points to the computer without Send', ws)
	check(not errors, 'no script errors in a whole phone take', '; '.join(errors))
	page.evaluate("document.getElementById('tpDismiss').click()")
	first_id = t.get('id', '')

	# --- Cut off: the pieces stop going up, then the page is reloaded mid-take ---
	del errors[:]
	page.goto(TP, wait_until='load')
	page.wait_for_timeout(600)
	begin(page)
	page.wait_for_timeout(13000)
	blocked = re.compile(r'.*/sent/[A-Za-z0-9]+/piece.*')
	page.route(blocked, lambda r: r.abort())
	page.wait_for_timeout(30000)
	line = page.evaluate("document.getElementById('tpPMeterSite').textContent")
	check('Waiting for the connection' in line, 'the site line says when pieces are not getting through', line)
	before = page.evaluate(LOCAL)
	kept = max([x['samples'] / float(x['rate']) for x in before if x['site']] or [0])
	page.unroute(blocked)
	page.goto(TP, wait_until='load')
	try:
		page.wait_for_selector('#tpCarry.is-on', timeout=60000)
		offered = True
	except Exception:
		offered = False
	check(offered, 'the next visit offers Carry on')
	wait = page.evaluate(WAITING)
	cut = [x for x in wait if x.get('id') != first_id]
	c = cut[0] if cut else {}
	check(c.get('state') == 'cut' and c.get('seconds', 0) >= kept - 0.1 and kept > 35, 'the rest was sent, and the take is cut off with all the phone kept', 'site %.1f s, phone kept %.1f s, %s' % (c.get('seconds', 0), kept, c.get('state')))
	check(not [x for x in page.evaluate(LOCAL) if x['site']], 'the phone forgets its copy once the rest is on the site')
	info = page.evaluate("document.getElementById('tpCarryInfo').textContent")
	check('cut off at' in info and 'Carry on from the sentence before' in info, 'Carry on says where the take stopped', info)
	want = page.evaluate("w => { var S = window.RRTStudio; return window.RRTLive.carryStart(S.W.map(function (x) { return x.sent; }), w); }", c.get('words', 0))
	check(0 < want <= c.get('words', 0), 'it starts before where the take stopped', '%s of %s' % (want, c.get('words')))
	page.click('#tpCarryGo')
	page.wait_for_function("!document.getElementById('tpFinish').hidden", timeout=15000)
	at = page.evaluate("window.RRTStudio.wi")
	check(want <= at <= want + 12, 'Carry on starts a take at the sentence before', 'at word %s, sentence start %s' % (at, want))
	page.wait_for_timeout(4000)
	finish_saved(page)
	check(len(page.evaluate(WAITING)) == 3, 'three takes are waiting for the computer')
	page.goto(TP, wait_until='load')
	page.wait_for_timeout(1500)
	check(not page.evaluate("document.getElementById('tpCarry').classList.contains('is-on')"), 'Carry on is offered once')
	check(not errors, 'no script errors while carrying on', '; '.join(errors))
	ctx.close()

	# --- On a computer ---
	ctx, page, errors = open_ctx(None)
	page.goto(TP, wait_until='load')
	page.wait_for_timeout(600)
	check(page.evaluate("document.getElementById('tpPhoneWrap').hidden"), 'a computer has no Phone take mode switch')
	setopt(page, '#tpWhisperOpt', False)
	# The two finished takes go, so the cut one is offered.
	page.evaluate("""ids => { var S = window.RRTStudio.SENT; return Promise.all(ids.map(function (id) { return fetch(S.rest + 'sent/' + id, { method: 'DELETE', credentials: 'same-origin', headers: { 'X-WP-Nonce': S.nonce } }); })); }""", [x['id'] for x in page.evaluate(WAITING) if x.get('state') != 'cut'])
	page.goto(TP, wait_until='load')
	page.wait_for_selector('#tpSent.is-on', timeout=10000)
	said = page.evaluate("document.getElementById('tpSentInfo').textContent")
	check('cut off partway' in said, 'the computer offers the cut take and says it was cut off', said)
	page.click('#tpSentGo')
	page.wait_for_function("document.getElementById('tpDownload').href.indexOf('blob:') === 0 ? !document.getElementById('tpDownload').hidden : false", timeout=120000)
	mp3 = download(page, '#tpDownload')
	lu = page.evaluate("""b => {
		var s = atob(b), u = new Uint8Array(s.length);
		for (var i = 0; i < s.length; i++) { u[i] = s.charCodeAt(i); }
		return new OfflineAudioContext(1, 1, 44100).decodeAudioData(u.buffer).then(function (a) { return window.RRTSound.lufs(a.getChannelData(0), a.sampleRate); });
	}""", base64.b64encode(mp3).decode())
	check(abs(lu - -19) < 1.0, 'the cut take finishes at -19 LUFS on the computer', '%.1f' % lu)
	check(not page.evaluate(WAITING), 'downloaded on the computer, it is gone from the site')
	check(not errors, 'no script errors on the computer', '; '.join(errors))
	ctx.close()

	# --- Reopened at once, with no copy left on the phone (1.12.1) ---
	# The site counts a take as cut off after 90 s without a piece; the phone
	# that started it knows sooner, so Carry on is offered straight away.
	ctx, page, errors = open_ctx(IPHONE)
	page.goto(TP, wait_until='load')
	page.wait_for_timeout(600)
	page.evaluate("() => { var s = window.RRTStudio.takeStore; s.begin = function () { return Promise.resolve(); }; s.add = function () { return Promise.resolve(); }; }")
	begin(page)
	page.wait_for_timeout(23000)
	t0 = time.time()
	page.goto(TP, wait_until='load')
	try:
		page.wait_for_selector('#tpCarry.is-on', timeout=20000)
		offered = True
	except Exception:
		offered = False
	took = time.time() - t0
	check(offered and took < 30, 'reopened at once, the phone that started the take offers Carry on without waiting for the site', '%.0f s' % took)
	wait = page.evaluate(WAITING)
	c = wait[0] if wait else {}
	check(len(wait) == 1 and c.get('state') == 'cut' and c.get('seconds', 0) >= 15, 'the take is marked cut off on the site with what reached it', str(c))
	check(page.evaluate("JSON.parse(localStorage.getItem('rrtLiveMine') || '[]').length") == 0, 'the phone forgets the take once it is finished on the site')
	page.click('#tpCarryLater')
	page.evaluate("""ids => { var S = window.RRTStudio.SENT; return Promise.all(ids.map(function (id) { return fetch(S.rest + 'sent/' + id, { method: 'DELETE', credentials: 'same-origin', headers: { 'X-WP-Nonce': S.nonce } }); })); }""", [x['id'] for x in wait])
	check(not errors, 'no script errors when reopened at once', '; '.join(errors))

	# A copy of a finished phone take left in the browser after the site's take was used on the
	# computer is spent: it is forgotten, not offered as an unfinished recording (seen live).
	page.evaluate("pid => window.RRTStudio.takeStore.begin({ id: 'spent1', postId: pid, slug: 'x', title: 'Spent', rate: 48000, bits: 24, started: Date.now(), updated: Date.now(), samples: 48000 * 23, site: 'goneFromTheSite', siteRate: 48000, ended: true })", POST_ID)
	page.evaluate("pid => window.RRTStudio.takeStore.begin({ id: 'unsent1', postId: pid, slug: 'x', title: 'Unsent', rate: 48000, bits: 24, started: Date.now() - 1000, updated: Date.now(), samples: 48000 * 5, site: 'alsoGone', siteRate: 48000 })", POST_ID)
	page.goto(TP, wait_until='load')
	page.wait_for_timeout(2500)
	ids = [x['id'] for x in page.evaluate(LOCAL)]
	check('spent1' not in ids and 'unsent1' in ids, 'a spent copy of a finished take is forgotten; one that never reached Stop is kept', str(ids))
	info = page.evaluate("document.getElementById('tpRecover').classList.contains('is-on') ? document.getElementById('tpRecoverInfo').textContent : ''")
	check('Spent' not in info, 'the spent copy is not offered as an unfinished recording', info)
	page.evaluate("() => window.RRTStudio.takeStore.remove('unsent1')")
	ctx.close()
	browser.close()

print('\n%d passed, %d failed' % (passed, len(failed)))
sys.exit(1 if failed else 0)
