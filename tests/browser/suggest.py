#!/usr/bin/env python3
"""
Clip suggestions (1.7.0), end to end.

On a reading's page: Suggested clips lists the opening and three more, each
30 to 60 s from a sentence start to a sentence end; the paragraph opening on
"And" is passed over; ▶ plays a suggestion and stops at its end; Make this
clip opens the clip maker at the suggestion with its exact length in the
length menu, and the clip it makes runs that long (plus the end card) at
-14 LUFS; Use for the feed keeps a suggestion as the post's pick, Stop using
clears it, and a stretch that does not start and end with a sentence is
refused.

Then the clip maker as the studio opens it after a take (the review's words,
no site suggestions): its own suggestions move to sentence edges.

Levels: the readings are made at -19 LUFS (a finished reading) and at -26
(a quieter reading put on by hand); the clip reaches -14 from both.

Usage: SG_SEED=<json from tests/wp/suggest.php -19> SG_QUIET=<json at -26> tests/browser/suggest.py http://localhost:8080
"""
import json, os, re, sys
from playwright.sync_api import sync_playwright
from signin import sign_in
from whisper_stub import PIECE

BASE = sys.argv[1].rstrip('/') if len(sys.argv) > 1 else 'http://localhost:8080'
CHROME = os.environ.get('CHROME_PATH', '/usr/bin/google-chrome')
SEED = json.load(open(os.environ['SG_SEED']))
QUIET = json.load(open(os.environ['SG_QUIET']))
POST_ID = SEED['post']
PAGE = BASE + '/wp-admin/admin.php?page=rrt-readings&post=%d'

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


# Sentence edges, from the words the seed read: each sentence's first word start and last word end.
sents = {}
for w in SEED['words']:
	s = sents.setdefault(w['sn'], {'s': w['s'], 'e': w['e'], 'k': w['k'], 'first': w['t']})
	s['e'] = w['e']
SENTS = [sents[k] for k in sorted(sents)]


def at_start(t):
	"""In the pause before a sentence: after the one before ends, at most 0.15 s before this one starts."""
	for k, x in enumerate(SENTS):
		before = SENTS[k - 1]['e'] if k else -1
		if before < t <= x['s'] and x['s'] - t <= 0.151:
			return True
	return False


def at_end(t):
	"""In the pause after a sentence: after its last word, before the next sentence starts."""
	for k, x in enumerate(SENTS):
		after = SENTS[k + 1]['s'] if k + 1 < len(SENTS) else x['e'] + 1
		if x['k'] == 'p' and x['e'] <= t < after:
			return True
	return False


with sync_playwright() as p:
	browser = p.chromium.launch(executable_path=CHROME if os.path.exists(CHROME) else None, args=['--autoplay-policy=no-user-gesture-required'])
	ctx = browser.new_context(viewport={'width': 1280, 'height': 900})
	# A stand-in Whisper for the clip maker, which hears only the chosen stretch (from a second before it):
	# the seed's words there, timed from the stretch's start.
	whisper = {'a0': 0}
	def fake_whisper(route):
		a0 = whisper['a0']
		words = [{'text': ' ' + w['t'], 'timestamp': [round(w['s'] - a0, 3), round(w['e'] - a0, 3)]} for w in SEED['words'] if w['s'] >= a0]
		js = ('export const env = { backends: { onnx: { wasm: {} } } };\n'
			+ PIECE +
			'const W = %s;\n'
			'export async function pipeline() { return async function (audio) { const d = total(audio);'
			' const c = W.filter(x => x.timestamp[1] <= d); return piece(audio, { chunks: c, text: c.map(x => x.text).join("") }); }; }\n' % json.dumps(words))
		route.fulfill(status=200, content_type='text/javascript', body=js, headers={'Access-Control-Allow-Origin': '*'})
	ctx.route(re.compile(r'.*transformers(\.min)?\.js.*'), fake_whisper)
	page = ctx.new_page()
	errors = []
	page.on('pageerror', lambda e: errors.append(str(e)))
	sign_in(page, BASE)

	page.goto(PAGE % POST_ID, wait_until='load')
	rows = page.evaluate("""() => Array.from(document.querySelectorAll('.rrt-suggest-table tbody tr')).map(r => ({
		s: parseFloat(r.getAttribute('data-s')), len: parseFloat(r.getAttribute('data-len')),
		label: r.cells[0].innerText, words: r.cells[1].innerText, why: r.cells[2].innerText, feed: r.classList.contains('is-feed') }))""")
	check(len(rows) == 4, 'the reading page suggests four clips', len(rows))
	check(rows and rows[0]['label'].startswith('The opening') and rows[0]['s'] == 0.25, 'the opening comes first, from the title', rows[0] if rows else '')
	ok_edges = [(r['s'], round(r['s'] + r['len'], 2)) for r in rows if not (at_start(r['s']) and at_end(r['s'] + r['len']))]
	check(not ok_edges, 'each starts in the pause before a sentence and ends in the pause after one, never into the next', ok_edges)
	lens = [r['len'] for r in rows]
	check(all(29.9 <= x <= 60.6 for x in lens), 'each runs 30 to 60 s', lens)
	check(not any(r['words'].startswith('“And ') for r in rows), 'the paragraph opening on "And" is passed over', [r['words'][:20] for r in rows])
	check(any('Starts a section' in r['why'] for r in rows), 'the stretch from the heading is there, as the start of a section')
	check('10 plays' in page.inner_text('#rrt-suggest'), 'with no plays it says the listeners will count once there are 10')

	# ▶ plays from the suggestion and stops at its end.
	r1 = rows[1]
	# The test server cannot answer range requests, so the page's audio plays from a copy in memory, which can seek.
	page.evaluate("""() => fetch(document.querySelector('.rrt-dash-audio').src).then(r => r.blob()).then(b => {
		var a = document.querySelector('.rrt-dash-audio'); a.src = URL.createObjectURL(b); })""")
	page.click('.rrt-suggest-table tbody tr:nth-child(2) .rrt-seek')
	page.wait_for_timeout(700)
	a = page.evaluate("(() => { var a = document.querySelector('.rrt-dash-audio'); return { t: a.currentTime, paused: a.paused }; })()")
	check(not a['paused'] and r1['s'] - 0.1 <= a['t'] <= r1['s'] + 1.5, '▶ plays from the suggestion', a)
	page.evaluate("document.querySelector('.rrt-dash-audio').currentTime = %f" % (r1['s'] + r1['len'] - 0.6))
	page.wait_for_timeout(1500)
	a = page.evaluate("(() => { var a = document.querySelector('.rrt-dash-audio'); return { t: a.currentTime, paused: a.paused }; })()")
	nxt = min(x['s'] for x in SENTS if x['s'] > r1['s'] + r1['len'] - 0.3)
	check(a['paused'] and a['t'] - (r1['s'] + r1['len']) < 0.06 and a['t'] < nxt, 'and stops at its end, before the next sentence starts', '%s, next sentence at %.2f' % (a, nxt))

	# Make this clip: the clip maker opens at the suggestion, with its exact length.
	page.click('.rrt-suggest-table tbody tr:nth-child(2) [data-rrt-clip]')
	page.wait_for_selector('.rrt-clip-sugg button', timeout=30000)
	cm = page.evaluate("""() => { var sel = document.querySelector('.rrt-clip select');
		return { len: sel.value, label: sel.options[sel.selectedIndex].text,
			start: document.querySelector('.rrt-clip-row input').value,
			btns: Array.from(document.querySelectorAll('.rrt-clip-sugg button')).map(b => b.textContent),
			on: Array.from(document.querySelectorAll('.rrt-clip-sugg button')).findIndex(b => b.classList.contains('is-on')) }; }""")
	check(abs(float(cm['len']) - r1['len']) < 0.3 and 'as suggested' in cm['label'], 'the length menu has the suggestion\'s exact length, settled on the audio', '%s against %.2f' % (cm['label'], r1['len']))
	m0, s0 = cm['start'].split(':')
	check(abs(int(m0) * 60 + int(s0) - r1['s']) <= 1, 'the clip starts at the suggestion, not moved to a quiet spot a second away', cm['start'])
	# Settled on the audio: the clip's own edges are in the pauses, never inside a sentence.
	settled = page.evaluate("(() => { var b = document.querySelector('.rrt-clip-sugg button.is-on'); return b ? b.textContent : ''; })()")
	check(settled.startswith('Suggestion 1'), 'Suggestion 1 is marked in the row', settled)
	check(len(cm['btns']) == 4 and cm['btns'][0].startswith('The opening') and cm['on'] == 1, 'the suggestion row is the site\'s four, this one marked', cm['btns'])
	# Another suggestion from the row sets its own start and length.
	page.click('.rrt-clip-sugg button:nth-of-type(3)')
	cm2 = page.evaluate("document.querySelector('.rrt-clip select').value")
	check(abs(float(cm2) - rows[2]['len']) < 0.3, 'a suggestion in the row sets its exact length', cm2)
	page.click('.rrt-clip-sugg button:nth-of-type(2)')

	# The level a clip gets, from a finished reading (-19) and a quiet one (-26).
	def levels(post):
		return page.evaluate("""async (o) => {
			var r = await fetch(o.url, { credentials: 'same-origin' });
			var ac = new OfflineAudioContext(1, 48000, 48000);
			var buf = await ac.decodeAudioData(await r.arrayBuffer());
			var x = buf.getChannelData(0), rate = buf.sampleRate;
			var seg = x.slice(Math.floor(o.s * rate), Math.floor((o.s + o.len) * rate));
			var before = window.RRTSound.lufsMulti([seg], rate);
			var after = window.RRTSound.lufsMulti([window.RRTClip.clipLevel(seg)], rate);
			return { reading: window.RRTSound.lufsMulti([x], rate), before: before, after: after };
		}""", {'url': post['url'], 's': r1['s'], 'len': r1['len']})
	audio_url = page.evaluate("document.querySelector('.rrt-dash-audio').src")
	lv = levels({'url': audio_url})
	check(abs(lv['reading'] + 19) < 1.0, 'the finished reading measures about -19 LUFS', round(lv['reading'], 1))
	check(abs(lv['after'] + 14) < 0.7, 'its suggested clip is leveled to -14 LUFS', round(lv['after'], 1))

	# Make the clip itself: square only, to keep the test short.
	if page.evaluate("window.RRTClip.supported()"):
		page.evaluate("""() => { document.querySelectorAll('.rrt-clip-check input').forEach((c, i) => { c.checked = i === 0; }); }""")
		whisper['a0'] = max(0, r1['s'] - 1)
		page.click('.rrt-clip-make')
		try:
			page.wait_for_function("/^Done/.test(document.querySelector('.rrt-clip-status').textContent)", timeout=300000)
			made = True
		except Exception:
			made = False
		check(made, 'the suggested clip is made', page.inner_text('.rrt-clip-status'))
		if made:
			dur = page.evaluate("""() => new Promise(res => { var v = document.querySelector('.rrt-clip-result video');
				if (v.readyState >= 1) { res(v.duration); } else { v.addEventListener('loadedmetadata', () => res(v.duration)); } })""")
			check(abs(dur - (float(cm['len']) + 3)) < 0.25, 'the clip runs the suggestion\'s exact length, then the 3 s end card', '%.2f s for %s s' % (dur, cm['len']))
			# 1.30.0: Mediabunny writes it, with AAC (the add-on here, as Linux Chrome has none) and no edit list.
			mp4 = page.evaluate("""async () => { var v = document.querySelector('.rrt-clip-result video');
				var b = new Uint8Array(await (await fetch(v.src)).arrayBuffer());
				var head = ''; for (var i = 0; i < Math.min(b.length, 200000); i++) { head += String.fromCharCode(b[i]); }
				return { edits: await RRTClip.editLists(b), aac: head.indexOf('mp4a') >= 0, high: head.indexOf('avcC') >= 0 ? b[head.indexOf('avcC') + 5] : 0 }; }""")
			check(mp4['aac'] and mp4['edits'] == 0, 'the clip\'s sound is AAC and no track has an edit list (1.30.0)', mp4)
			check(mp4['high'] == 100, 'the clip is H.264 High profile (1.30.0)', mp4)
			# Its sound ends with the last word: the end card is silent, with nothing of the next sentence.
			snd = page.evaluate("""async (len) => { try {
				var v = document.querySelector('.rrt-clip-result video');
				var buf = await (await fetch(v.src)).arrayBuffer();
				var ab = await new OfflineAudioContext(1, 48000, 48000).decodeAudioData(buf);
				var x = ab.getChannelData(0), r = ab.sampleRate;
				var rms = (a, b) => { var s = 0, n = 0; for (var k = Math.floor(a * r); k < Math.min(x.length, Math.floor(b * r)); k++) { s += x[k] * x[k]; n++; } return n ? 10 * Math.log10(s / n + 1e-12) : -120; };
				return { voice: rms(len - 4, len - 0.5), after: rms(len + 0.08, len + 2.5) };
			} catch (e) { return { error: String(e) }; } }""", float(cm['len']))
			if 'error' in snd:
				print('skip the end card\'s sound: this browser could not decode the clip (%s)' % snd['error'])
			else:
				check(snd['after'] < snd['voice'] - 45, 'the end card is silent: nothing of the next sentence', snd)
	else:
		print('skip making the clip: this browser cannot make videos')
	page.keyboard.press('Escape')

	# The quiet reading: its suggestions, and the clip's level.
	page.goto(PAGE % QUIET['post'], wait_until='load')
	q_audio = page.evaluate("document.querySelector('.rrt-dash-audio').src")
	q1 = page.evaluate("parseFloat(document.querySelector('.rrt-suggest-table tbody tr:nth-child(2)').getAttribute('data-s'))")
	check(abs(q1 - r1['s']) < 0.01, 'a quieter reading of the same words gets the same suggestions', q1)
	page.wait_for_function("typeof window.RRTClip === 'object' && typeof window.RRTSound === 'object'")
	lq = levels({'url': q_audio})
	check(abs(lq['reading'] + 26) < 1.0, 'the quiet reading measures about -26 LUFS', round(lq['reading'], 1))
	check(abs(lq['after'] + 14) < 0.7, 'its suggested clip is still leveled to -14 LUFS', round(lq['after'], 1))

	# Use for the feed, then Stop using.
	page.goto(PAGE % POST_ID, wait_until='load')
	with page.expect_navigation():
		page.click('.rrt-suggest-table tbody tr:nth-child(3) button[type=submit]')
	feed = page.evaluate("Array.from(document.querySelectorAll('.rrt-suggest-table tbody tr')).map(r => r.classList.contains('is-feed'))")
	check(feed == [False, False, True, False], 'Use for the feed marks that suggestion', feed)
	check('Used for the feed' in page.inner_text('.rrt-suggest-table tbody tr:nth-child(3)'), 'it says it is used for the feed')
	check(page.url.endswith('#rrt-suggest'), 'the page comes back at the suggestions', page.url)
	with page.expect_navigation():
		page.click('.rrt-suggest-table tbody tr:nth-child(3) button[type=submit]')
	feed = page.evaluate("Array.from(document.querySelectorAll('.rrt-suggest-table tbody tr')).map(r => r.classList.contains('is-feed'))")
	check(feed == [False, False, False, False], 'Stop using clears it', feed)

	# A stretch that does not start and end with a sentence is refused.
	page.evaluate("""() => { var f = document.querySelector('.rrt-suggest-table tbody tr:nth-child(2) form'); var d = new FormData(f);
		d.set('s', String(parseFloat(d.get('s')) + 1.3));
		return fetch(f.action, { method: 'POST', body: d, credentials: 'same-origin', redirect: 'manual' }).then(r => r.status); }""")
	page.goto(PAGE % POST_ID, wait_until='load')
	feed = page.evaluate("document.querySelectorAll('.rrt-suggest-table tr.is-feed').length")
	check(feed == 0 and 'chosen earlier' not in page.inner_text('#rrt-suggest'), 'a stretch that is not a sentence\'s is not kept')
	# Keep one for run.sh to check in the database: the opening.
	with page.expect_navigation():
		page.click('.rrt-suggest-table tbody tr:nth-child(1) button[type=submit]')
	check(page.evaluate("document.querySelector('.rrt-suggest-table tbody tr:nth-child(1)').classList.contains('is-feed')"), 'the opening can be the pick too')

	# The clip maker as the studio opens it after a take: the review's words, no site suggestions.
	boot = page.evaluate("JSON.parse(document.getElementById('rrt-clip-boot').textContent)")
	boot['suggest'] = None
	boot['curve'] = None
	boot['words'] = SEED['words']
	page.evaluate("b => window.RRTClip.open(b)", boot)
	page.wait_for_selector('.rrt-clip-sugg button', timeout=30000)
	sb = page.evaluate("Array.from(document.querySelectorAll('.rrt-clip-sugg button')).map(b => ({ text: b.textContent, why: b.title }))")
	check([b['text'].split(' (')[0] for b in sb] == ['The opening', 'Most lively'], 'after a take the row has the opening and the most lively moment', [b['text'] for b in sb])
	check(all('Starts and ends with a sentence' in b['why'] for b in sb), 'both move to sentence edges', [b['why'] for b in sb])
	page.click('.rrt-clip-sugg button:nth-of-type(2)')
	st = page.evaluate("""() => { var sel = document.querySelector('.rrt-clip select'); var m = document.querySelector('.rrt-clip-row input').value.split(':');
		return { len: parseFloat(sel.value), start: parseInt(m[0], 10) * 60 + parseInt(m[1], 10) }; }""")
	ends = [x['e'] for x in SENTS if x['k'] == 'p']
	starts = [x['s'] for x in SENTS]
	near_start = min(abs(st['start'] - x) for x in starts)
	check(30.3 <= st['len'] <= 60.5 and near_start < 1.0, 'the lively moment starts at a sentence and runs 30 to 60 s', st)
	page.keyboard.press('Escape')

	check(not errors, 'no script errors', '; '.join(errors))
	browser.close()

print('\n%d passed, %d failed' % (passed, len(failed)))
sys.exit(1 if failed else 0)
