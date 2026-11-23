#!/usr/bin/env python3
"""
The take shelf across computers (1.34.0), end to end. Three browser
profiles signed in as the same person stand in for two computers and a
phone; each has its own browser storage, as separate computers do.

Computer one reviews the first part of a post: the take goes up to the site
as FLAC in pieces. Computer two, with an empty shelf of its own, lists it at
once (Continue starts after it), reviews the rest (take 2, numbered by the
site), and Combine takes fetches take 1's recording from the site, checks
every sample against what computer one kept, and builds the reading. A plan
made on computer two opens on computer one. A take removed on one computer
leaves the other. The same take reviewed on a third browser is kept once
and its recording is not fetched. The phone sends a short take of its own,
sees Continue from the other takes, and never fetches a recording. Clear the
shelf empties it everywhere.

Whisper is a stand-in, as in combine.py.

Usage: SH_POST=<post id> tests/browser/shelf.py http://localhost:8080
The post must read: "<p>Leaves fall slowly. Rain taps the glass.</p><p>Owls call softly. The lamp stays lit.</p>",
and the take shelf on the site must be switched on (run.sh does both).
"""
import io, json, math, os, re, struct, sys, tempfile, wave
from playwright.sync_api import sync_playwright
from signin import sign_in
from take_assets import MP3, RNN
from whisper_stub import PIECE

BASE = sys.argv[1].rstrip('/') if len(sys.argv) > 1 else 'http://localhost:8080'
CHROME = os.environ.get('CHROME_PATH', '/usr/bin/google-chrome')
POST_ID = int(os.environ['SH_POST'])
TP = BASE + '/?teleprompter=1&tp_post=%d' % POST_ID

passed = 0
failed = []


# The made-up takes and stand-in Whisper, as in combine.py.
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


def check(ok, name, detail=''):
	global passed
	if ok:
		passed += 1
		print('ok   ' + name)
	else:
		failed.append(name)
		print('FAIL ' + name + (': ' + detail if detail else ''))


SITE_LIST = """async () => {
	var S = window.RRTStudio.RRT.shelfSite;
	var u = S.rest + (S.rest.indexOf('?') < 0 ? 'shelf?post=' : 'shelf&post=') + %d;
	var r = await fetch(u, { credentials: 'same-origin', headers: { 'X-WP-Nonce': S.nonce } });
	var j = await r.json();
	return { takes: (j.takes || []).map(t => ({ id: t.id, n: t.n, state: t.state, words: (t.words || []).length })), plan: j.plan };
}""" % POST_ID

# Each take on this browser's shelf: its number, whether it came from the site without its recording, how it stands on the site, and a check of its samples.
LOCAL = """() => new Promise(r => {
	var q = indexedDB.open('rrt-shelf');
	q.onsuccess = () => {
		var d = q.result; var g = d.transaction(['takes', 'audio']); var out = { takes: [], audio: {} };
		g.objectStore('takes').getAll().onsuccess = e => { out.takes = e.target.result.map(t => ({ id: t.id, n: t.n, remote: !!t.remote, site: t.site || '' })); };
		g.objectStore('audio').getAll().onsuccess = e => { e.target.result.forEach(a => { var x = new Int16Array(a.data); var h = 7; for (var i = 0; i < x.length; i++) { h = (Math.imul(h, 31) + x[i]) | 0; } out.audio[a.id] = h + ':' + x.length; }); };
		g.oncomplete = () => r(out);
	};
	q.onerror = () => r({ takes: [], audio: {} });
})"""

with sync_playwright() as p:
	browser = p.chromium.launch(executable_path=CHROME if os.path.exists(CHROME) else None, args=['--autoplay-policy=no-user-gesture-required'])
	fake = {'js': ''}
	errors = []
	fetched = {'phone': 0}

	def computer(phone=False):
		kw = {}
		if phone:
			kw.update({'is_mobile': True, 'has_touch': True, 'viewport': {'width': 393, 'height': 852}})
		ctx = browser.new_context(**kw)
		ctx.route(re.compile(r'.*transformers(\.min)?\.js.*'), lambda r: r.fulfill(status=200, content_type='text/javascript', body=fake['js'], headers={'Access-Control-Allow-Origin': '*'}))
		ctx.route(re.compile(r'.*/mp3\.wasm.*'), lambda r: r.fulfill(status=200, content_type='application/wasm', body=MP3, headers={'Access-Control-Allow-Origin': '*'}))
		ctx.route(re.compile(r'.*/rnnoise\.wasm.*'), lambda r: r.fulfill(status=200, content_type='application/wasm', body=RNN, headers={'Access-Control-Allow-Origin': '*'}))
		page = ctx.new_page()
		page.on('pageerror', lambda e: errors.append(str(e)))
		if phone:
			page.on('request', lambda q: fetched.__setitem__('phone', fetched['phone'] + (1 if re.search(r'shelf/take/[a-z0-9]+/audio', q.url) else 0)))
		sign_in(page, BASE)
		page.goto(TP, wait_until='load')
		page.evaluate("try { sessionStorage.setItem('rrtIntroSeen', '1'); localStorage.setItem('rrtIntro', 'off'); localStorage.setItem('rrtWelcomed', '1'); } catch (e) {}")
		page.evaluate("new Promise(r => { var q = indexedDB.deleteDatabase('rrt-shelf'); q.onsuccess = q.onerror = q.onblocked = () => r(); })")
		return ctx, page

	def open_tp(page):
		page.goto(TP, wait_until='load')
		page.wait_for_timeout(500)
		page.evaluate("a => { var e = document.querySelector(a); if (e && !e.checked) { e.click(); } }", '#tpWhisperOpt')
		# The studio asks the site for the shelf as it opens.
		page.wait_for_function("window.RRTStudio.shelfSite ? window.RRTStudio.shelfSite.synced : false", timeout=20000)
		page.wait_for_timeout(300)

	def review(page, path, want='shelf'):
		open_tp(page)
		page.set_input_files('#tpFile', path)
		page.wait_for_function("document.getElementById('tpWhisperStatus').textContent.indexOf(%s) >= 0" % json.dumps(want), timeout=120000)
		return page.inner_text('#tpWhisperStatus')

	def sent(page):
		page.wait_for_function("(document.getElementById('tpShelfSite').textContent || '').indexOf('on the site') >= 0", timeout=60000)
		return page.inner_text('#tpShelfSite')

	def chosen(page):
		return page.evaluate("""Array.prototype.map.call(document.querySelectorAll('.tp-cb-piece'), function (r) {
			var b = r.querySelector('.tp-cb-pick.is-chosen .tp-cb-choose'); return b ? b.textContent.split(' · ')[0] : '';
		})""")

	ctxA, A = computer()
	open_tp(A)
	check(A.evaluate("!!window.RRTStudio.RRT.shelfSite && /flac\\.js/.test(window.RRTStudio.RRT.shelfSite.worker)"), 'with the setting on, the studio knows where to send takes, and which worker writes FLAC')
	check(A.evaluate(SITE_LIST)['takes'] == [], 'the site’s shelf for the post starts empty')
	words = A.evaluate("Array.prototype.map.call(document.querySelectorAll('.tp-w'), function (e) { return e.textContent; })")
	owls = next(i for i, w in enumerate(words) if key(w) == 'owls')
	t1 = plan(words, stop=owls, pause=1.2)  # The title and the first paragraph.
	t2 = plan(words, start=owls, pause=0.8)  # The second paragraph.
	t3 = plan(words, start=owls, pause=0.3, misheard={'lamp': 'clock'})  # The second paragraph again, quicker, on the phone.
	t4 = plan(words, stop=owls, pause=2.2)  # The first part again, slowly, on a browser whose send is cut off.
	fake['js'] = fake_transformers([t1, t2, t3, t4])
	f1, f2, f3, f4 = wav_for(t1[0], t1[1], 11), wav_for(t2[0], t2[1], 13), wav_for(t3[0], t3[1], 17), wav_for(t4[0], t4[1], 19)

	# Computer one: the first part goes on its shelf, and up to the site.
	line = review(A, f1)
	check('as take 1' in line and 'Continue' in line, 'computer one keeps the first part as take 1', line)
	up = sent(A)
	check('Take 1 is on the site’s shelf too' in up, 'and says when it is on the site too', up)
	sl = A.evaluate(SITE_LIST)
	check(len(sl['takes']) == 1 and sl['takes'][0]['state'] == 'ready' and sl['takes'][0]['n'] == 1 and sl['takes'][0]['words'] == len([w for w in words if key(w)]), 'the site has take 1, whole, with its words', json.dumps(sl))
	la = A.evaluate(LOCAL)
	check(len(la['takes']) == 1 and la['takes'][0]['site'] == 'ready' and not la['takes'][0]['remote'], 'computer one marks its take as on the site', json.dumps(la))
	id1 = la['takes'][0]['id']

	# Computer two: nothing of its own, and the site's take is there at once.
	ctxB, B = computer()
	open_tp(B)
	B.wait_for_function("!document.getElementById('tpContinue').hidden", timeout=10000)
	check(B.inner_text('#tpContinue') == 'Continue (1 to read)', 'computer two offers Continue from the take read on computer one', B.inner_text('#tpContinue'))
	check(B.evaluate("document.getElementById('tpCombineOpen').hidden"), 'with one take, there is nothing to combine yet')
	lb = B.evaluate(LOCAL)
	check(len(lb['takes']) == 1 and lb['takes'][0]['remote'] and id1 not in lb['audio'], 'computer two lists the take without fetching its recording', json.dumps(lb))
	line = review(B, f2)
	check('as take 2' in line, 'computer two keeps the rest as take 2', line)
	up = sent(B)
	check('Take 2 is on the site’s shelf too' in up, 'and sends it', up)
	sl = B.evaluate(SITE_LIST)
	check([t['n'] for t in sl['takes']] == [1, 2] and all(t['state'] == 'ready' for t in sl['takes']), 'the site has takes 1 and 2', json.dumps(sl))

	# Combine takes on computer two fetches take 1 from the site and builds the reading.
	B.wait_for_function("document.body.classList.contains('is-reviewing')", timeout=30000)
	B.wait_for_function("!document.getElementById('tpRvCombine').hidden", timeout=10000)
	check(B.inner_text('#tpRvCombine') == 'Combine takes (2)', 'computer two offers Combine takes with both takes', B.inner_text('#tpRvCombine'))
	B.click('#tpRvCombine')
	B.wait_for_selector('#tpCombine:not([hidden])', timeout=10000)
	meta = B.locator('.tp-cb-take').nth(0).inner_text()
	check('from another computer' in meta, 'take 1 is marked as read on another computer', meta)
	B.wait_for_function("document.querySelectorAll('.tp-cb-piece').length >= 3", timeout=30000)
	B.wait_for_timeout(500)
	B.click('#tpCbBuild')
	try:
		B.wait_for_function("document.body.classList.contains('is-reviewing') ? document.getElementById('tpWhisperStatus').textContent.indexOf('combined reading is ready') >= 0 : false", timeout=120000)
		built = True
	except Exception:
		built = False
	check(built, 'the reading is built from takes made on two computers', B.inner_text('#tpWhisperStatus'))
	lb = B.evaluate(LOCAL)
	la = A.evaluate(LOCAL)
	check(id1 in lb['audio'] and lb['audio'][id1] == la['audio'][id1], 'take 1’s recording came back from the site sample for sample', json.dumps([lb['audio'].get(id1), la['audio'].get(id1)]))
	check(not [t for t in lb['takes'] if t['id'] == id1][0]['remote'], 'and computer two keeps it, so it is fetched only once')
	stamps = B.evaluate("window.RRTStudio.rv.last ? window.RRTStudio.rv.last.chunks.length : 0")
	check(stamps == len([w for w in words if key(w)]), 'the built reading has every word of the post', str(stamps))

	# A plan made on computer two opens on computer one.
	B.click('#tpRvCombine')
	B.wait_for_selector('#tpCombine:not([hidden])', timeout=10000)
	B.wait_for_function("document.querySelectorAll('.tp-cb-piece').length >= 3", timeout=30000)
	B.wait_for_timeout(500)
	B.locator('.tp-cb-para').nth(1).locator('.tp-cb-split').click()
	B.wait_for_function("document.querySelectorAll('.tp-cb-piece').length >= 4", timeout=10000)
	plan_b = chosen(B)
	B.wait_for_timeout(2500)
	sl = B.evaluate(SITE_LIST)
	check(bool(sl['plan']) and '1' in json.dumps(sl['plan']['plan']['split']), 'the plan is saved on the site', json.dumps(sl['plan']))
	open_tp(A)
	A.wait_for_function("!document.getElementById('tpCombineOpen').hidden", timeout=10000)
	check(A.inner_text('#tpCombineOpen') == 'Combine takes (2)', 'computer one now has both takes too', A.inner_text('#tpCombineOpen'))
	A.click('#tpCombineOpen')
	A.wait_for_selector('#tpCombine:not([hidden])', timeout=10000)
	A.wait_for_function("document.querySelectorAll('.tp-cb-piece').length >= 3", timeout=30000)
	A.wait_for_timeout(800)
	check(chosen(A) == plan_b and len(plan_b) == 4, 'computer one opens Combine takes with computer two’s choices', json.dumps([chosen(A), plan_b]))

	# Remove take 2 on computer one: it leaves the site and computer two.
	rm = A.locator('.tp-cb-take').nth(1).locator('.tp-cb-rm')
	rm.click()
	check('every computer' in rm.inner_text(), 'Remove says it removes the take on every computer', rm.inner_text())
	rm.click()
	A.wait_for_timeout(1500)
	sl = A.evaluate(SITE_LIST)
	check([t['n'] for t in sl['takes']] == [1], 'the removed take leaves the site', json.dumps(sl))
	B.evaluate("window.RRTStudio.closeReview && window.RRTStudio.closeReview()")
	open_tp(B)
	lb = B.evaluate(LOCAL)
	check([t['n'] for t in lb['takes']] == [1], 'and leaves computer two the next time it opens the post', json.dumps(lb))

	# A third browser reviews take 1's own file: kept once, and its recording taken from the review, not fetched.
	ctxD, D = computer()
	line = review(D, f1)
	check('Already on this post’s shelf as take 1' in line, 'the same take reviewed on a third browser is known as take 1', line)
	ld = D.evaluate(LOCAL)
	check(len(ld['takes']) == 1 and not ld['takes'][0]['remote'] and id1 in ld['audio'], 'and keeps its recording from the review', json.dumps(ld))
	check(len(D.evaluate(SITE_LIST)['takes']) == 1, 'the site still has it once')
	ctxD.close()

	# The phone: Continue from the other computers' take, a short take sent, and no recording fetched.
	ctxP, P = computer(phone=True)
	open_tp(P)
	P.wait_for_function("!document.getElementById('tpContinue').hidden", timeout=10000)
	check(P.inner_text('#tpContinue') == 'Continue (1 to read)', 'the phone offers Continue after the part read on a computer', P.inner_text('#tpContinue'))
	line = review(P, f3)
	check('take 2' in line, 'the phone keeps its short take as take 2', line)
	up = sent(P)
	check('Take 2 is on the site’s shelf too' in up, 'and sends it to the site', up)
	check(P.evaluate("document.getElementById('tpCombineOpen').hidden ? document.getElementById('tpRvCombine').hidden : false"), 'the phone does not offer Combine takes')
	check(fetched['phone'] == 0, 'the phone never fetches a recording', str(fetched['phone']))
	ctxP.close()

	# A send cut off: the take stays in the browser, and goes up the next time the post is opened there.
	ctxE, E = computer()
	E.route(re.compile(r'.*shelf/take/[a-z0-9]+/piece.*'), lambda r: r.abort())
	line = review(E, f4)
	check('as take 3' in line, 'a fourth browser keeps its take as take 3', line)
	E.wait_for_function("(document.getElementById('tpShelfSite').textContent || '').indexOf('could not reach the site') >= 0", timeout=60000)
	check('goes up the next time' in E.inner_text('#tpShelfSite'), 'when the site cannot be reached, it says the take waits in this browser', E.inner_text('#tpShelfSite'))
	sl = E.evaluate(SITE_LIST)
	check([t['state'] for t in sl['takes'] if t['n'] == 3] == ['up'], 'the site has the take started, not finished', json.dumps(sl))
	E.unroute(re.compile(r'.*shelf/take/[a-z0-9]+/piece.*'))
	open_tp(E)
	E.wait_for_function("window.RRTStudio.shelfSite.running ? false : true", timeout=5000)
	E.wait_for_timeout(3000)
	sl = E.evaluate(SITE_LIST)
	check([t['state'] for t in sl['takes'] if t['n'] == 3] == ['ready'], 'opened again, the send carries on and finishes', json.dumps(sl))
	ctxE.close()

	# Clear the shelf on computer two: empty everywhere.
	open_tp(B)
	B.wait_for_function("!document.getElementById('tpCombineOpen').hidden", timeout=10000)
	B.click('#tpCombineOpen')
	B.wait_for_selector('#tpCombine:not([hidden])', timeout=10000)
	B.wait_for_function("document.querySelectorAll('.tp-cb-piece').length >= 3", timeout=30000)
	B.wait_for_timeout(500)
	B.click('#tpCbClear')
	check('every computer' in B.inner_text('#tpCbClear'), 'Clear the shelf says it clears it on every computer', B.inner_text('#tpCbClear'))
	B.click('#tpCbClear')
	B.wait_for_function("document.getElementById('tpCombine').hidden", timeout=10000)
	B.wait_for_timeout(1500)
	check(B.evaluate(SITE_LIST)['takes'] == [] and not B.evaluate(SITE_LIST)['plan'], 'the site’s shelf for the post is empty, plan and all')
	open_tp(A)
	check(A.evaluate(LOCAL)['takes'] == [] and A.evaluate("document.getElementById('tpContinue').hidden"), 'and computer one’s is empty the next time it opens the post')
	check(not errors, 'no script errors', '; '.join(errors))
	ctxA.close()
	ctxB.close()
	browser.close()

print('\n%d passed, %d failed' % (passed, len(failed)))
sys.exit(1 if failed else 0)
