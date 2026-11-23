#!/usr/bin/env python3
"""
Voice following (1.14.0) in the studio, with a stand-in listener in place of
the speech model (follow-worker.js is answered by a worker that "hears" a
planned reading at 1.5 words a second of microphone audio): the switch loads
the listener before Begin; the prompter follows the reading, holds through an
ad-lib, follows a skip of a paragraph and a half, and reaches the end; the
pace shows; with no take being recorded the follower opens the microphone
itself, and with one it hears the recorder's copy (one microphone either
way); the first look names the paragraph never heard; and when the listener
falls behind or cannot start, the timed pace takes over and says why.

Usage: FV_POST=<post id> tests/browser/follow.py http://localhost:8080
The post must read as POST below.
"""
import json, os, re, sys
from playwright.sync_api import sync_playwright
from signin import sign_in

BASE = sys.argv[1].rstrip('/') if len(sys.argv) > 1 else 'http://localhost:8080'
CHROME = os.environ.get('CHROME_PATH', '/usr/bin/google-chrome')
POST_ID = int(os.environ['FV_POST'])
TP = BASE + '/?teleprompter=1&tp_post=%d' % POST_ID

POST = [
	'The lantern swings above the harbour gate while gulls argue over the catch.',
	'Fishermen mend their nets beside the market and the smell of salt drifts up the lane.',
	'Old Marisol keeps the tide tables pinned inside her window with a stub of red pencil.',
	'By noon the fog lifts and children race along the breakwater counting waves in threes.',
]
CONTENT = ''.join('<p>%s</p>' % p for p in POST)


def words(p):
	return [re.sub(r'[^a-z0-9]', '', w.lower()) for w in p.split()]


# The planned reading: paragraph 1, an ad-lib, the first half of paragraph 2, then on to paragraph 4.
PLAN = words(POST[0]) + 'oh i do love this part of it'.split() + words(POST[1])[:8] + words(POST[3])

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


def stand_in(mode):
	"""A listener that hears PLAN at 1.5 words a second of audio sent to it (slow enough that the
	pace, which needs 15 s of reading since 1.14.0's rebuild, shows before the skip). mode: ok, slow, error."""
	return ('var PLAN = %s, MODE = %s, sent = 0, said = 0;\n' % (json.dumps(PLAN), json.dumps(mode))) + r'''
if (MODE === 'lastmis') { PLAN = PLAN.slice(0, -1).concat(['hum']); }
self.onmessage = function (e) {
  var d = e.data;
  if (d.type === 'init') {
    if (MODE === 'error') { self.postMessage({ type: 'error', message: 'stand-in refused' }); return; }
    self.postMessage({ type: 'load', got: 1048576, total: 2097152 });
    setTimeout(function () { self.postMessage({ type: 'ready', from: 'stand-in' }); }, 200);
  } else if (d.type === 'reset') { sent = 0; said = 0; }
  else if (d.type === 'pcm') {
    sent += d.pcm.length / d.rate;
    var n = Math.min(PLAN.length, Math.floor(Math.max(0, sent - 0.5) * 1.5));
    var at = MODE === 'slow' ? Math.max(0, sent - 5) : sent;
    if (n > said) { said = n; self.postMessage({ type: 'heard', done: null, p: PLAN.slice(0, n).join(' '), at: at }); }
    else { self.postMessage({ type: 'heard', done: null, p: null, at: at }); }
  }
};
'''


with sync_playwright() as p:
	browser = p.chromium.launch(executable_path=CHROME if os.path.exists(CHROME) else None, args=[
		'--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream', '--autoplay-policy=no-user-gesture-required'])

	def studio(mode, rec, voice=True):
		ctx = browser.new_context(viewport={'width': 1280, 'height': 800})
		ctx.route(re.compile(r'.*/follow-worker\.js.*'), lambda r: r.fulfill(status=200, content_type='text/javascript', body=stand_in(mode)))
		page = ctx.new_page()
		errors = []
		page.on('pageerror', lambda e: errors.append(str(e)))
		sign_in(page, BASE)
		page.goto(TP, wait_until='load')
		page.evaluate("""a => { try {
			sessionStorage.setItem('rrtIntroSeen', '1'); localStorage.setItem('rrtIntro', 'off');
			localStorage.setItem('rrtTeleprompter', JSON.stringify({ voice: a[0], rec: a[1], whisper: false, clean: false, wpm: 140, phoneTake: false }));
		} catch (e) {} }""", [voice, rec])
		page.add_init_script("""(() => {
			window.__mics = 0;
			var g = navigator.mediaDevices.getUserMedia.bind(navigator.mediaDevices);
			navigator.mediaDevices.getUserMedia = function (c) { window.__mics++; return g(c); };
		})();""")
		page.goto(TP, wait_until='load')
		page.wait_for_timeout(600)
		return ctx, page, errors

	def state(page):
		return page.evaluate("""() => ({ wi: RRTStudio.wi, n: RRTStudio.W.length, finished: RRTStudio.finished, voice: RRTStudio.settings.voice,
			listening: RRTStudio.listening, left: document.getElementById('tpLeft').textContent, msg: document.getElementById('tpMsg').textContent,
			heard: document.getElementById('tpHeard').textContent, mics: window.__mics, switchOn: document.getElementById('tpVoice').checked,
			shown: !document.getElementById('tpVoiceWrap').hidden })""")

	def block_first(page, b):
		# The title is the studio's first block, so the post's paragraph b (from 0) is block b + 1.
		return page.evaluate("b => RRTStudio.W.findIndex(w => w.block === b)", b + 1)

	# 1. No take recorded: the follower's own microphone.
	ctx, page, errors = studio('ok', False)
	s = state(page)
	check(s['shown'] and s['switchOn'], 'Follow my voice is offered and on')
	page.wait_for_function("document.getElementById('tpMsg').textContent === ''", timeout=10000)
	check(True, 'the listener is ready before Begin, and its loading line goes away')
	page.evaluate("document.getElementById('tpPlay').click()")
	page.wait_for_function('RRTStudio.listening', timeout=15000)
	p1_end = block_first(page, 1)
	adlib_at = None
	trace = []
	for i in range(100):
		page.wait_for_timeout(500)
		s = state(page)
		trace.append(s['wi'])
		if s['finished']:
			break
	check(s['finished'], 'the reading reaches the end by voice', 'stopped at %d of %d' % (s['wi'], s['n']))
	# Between the end of paragraph 1 and the start of paragraph 2's words being heard, the place holds.
	held = [w for w in trace if w >= p1_end - 1 and w <= p1_end + 1]
	check(len(held) >= 2, 'the place holds through the ad-lib', str(trace))
	p3, p4 = block_first(page, 2), block_first(page, 3)
	check(sum(1 for w in trace if p3 <= w < p4) <= 1 and any(w >= p4 for w in trace), 'a skip of a paragraph and a half is followed', str(trace))
	check(all(trace[i] <= trace[i + 1] for i in range(len(trace) - 1)), 'the place never runs backwards on a reading that does not go back', str(trace))
	check(s['mics'] == 1, 'with no take recorded, the follower opens the microphone once', str(s['mics']))
	check('“' in s['heard'], 'what was heard shows in the dock', s['heard'])
	check(not errors, 'no script errors', '; '.join(errors))
	ctx.close()

	# 2. A take recorded: the follower hears the recorder's copy; the pace shows; the first look names paragraph 3.
	ctx, page, errors = studio('ok', True)
	page.wait_for_function("document.getElementById('tpMsg').textContent === ''", timeout=10000)
	page.evaluate("document.getElementById('tpPlay').click()")
	page.wait_for_function('RRTStudio.listening', timeout=15000)
	pace_seen = ''
	for i in range(100):
		page.wait_for_timeout(500)
		s = state(page)
		if 'wpm' in s['left']:
			pace_seen = s['left']
		if s['finished']:
			break
	check(s['finished'], 'a recorded take reaches the end by voice')
	check(bool(re.search(r'Following your voice, \d+ wpm', pace_seen)), 'the pace shows while following', pace_seen)
	check(s['mics'] == 1, 'one microphone for the recorder and the follower', str(s['mics']))
	page.wait_for_function("document.getElementById('tpResult').classList.contains('is-on')", timeout=20000)
	look = page.evaluate("({ hidden: document.getElementById('tpFirstLook').hidden, text: document.getElementById('tpFirstLook').textContent })")
	check(not look['hidden'] and look['text'].startswith('First look, from following your voice'), 'the first look shows at once', look['text'])
	# The studio counts the title as paragraph 1, as its Paragraph n of m line does, so the post's third is 4.
	check('Paragraph 4 was not heard.' in look['text'], 'the first look names the paragraph never heard', look['text'])
	check('kept up within' in look['text'], 'it says how well the follower kept up', look['text'])
	check('The full review follows' not in look['text'], 'with Whisper review off, no review is promised')
	check(not errors, 'no script errors', '; '.join(errors))
	ctx.close()

	# 3. Falling behind: the timed pace takes over and says so.
	ctx, page, errors = studio('slow', False)
	page.wait_for_function("document.getElementById('tpMsg').textContent === ''", timeout=10000)
	page.evaluate("document.getElementById('tpPlay').click()")
	page.wait_for_function("RRTStudio.settings.voice === false", timeout=30000)
	s = state(page)
	check('fell behind' in s['msg'], 'falling behind hands over to the timed pace, with a line saying so', s['msg'])
	check(not s['switchOn'], 'the switch shows it is off')
	w0 = s['wi']
	page.wait_for_timeout(2500)
	check(state(page)['wi'] > w0, 'the timed pace carries the reading on')
	ctx.close()

	# 4. A listener that cannot start: the switch turns off with the reason, and Begin reads at the timed pace.
	ctx, page, errors = studio('error', False)
	page.wait_for_function("RRTStudio.settings.voice === false", timeout=15000)
	s = state(page)
	check('could not start' in s['msg'] and 'stand-in refused' in s['msg'], 'a listener that cannot start says why', s['msg'])
	page.evaluate("document.getElementById('tpPlay').click()")
	page.wait_for_timeout(5500)
	check(state(page)['wi'] > 0, 'and the timed pace reads the take')
	ctx.close()

	# 5. The last word misheard (1.36.1): the script still ends once the voice goes quiet, and the take finishes.
	ctx, page, errors = studio('lastmis', True)
	page.wait_for_function("document.getElementById('tpMsg').textContent === ''", timeout=10000)
	page.evaluate("document.getElementById('tpPlay').click()")
	page.wait_for_function('RRTStudio.listening', timeout=15000)
	for i in range(120):
		page.wait_for_timeout(500)
		s = state(page)
		if s['finished']:
			break
	check(s['finished'] and s['wi'] == s['n'] - 1, 'a misheard last word still ends the reading by voice', 'stopped at %d of %d' % (s['wi'], s['n']))
	page.wait_for_function("RRTStudio.recorder ? RRTStudio.recorder.state === 'inactive' : true", timeout=15000)
	check(True, 'and the take finishes by itself')
	check(not errors, 'no script errors', '; '.join(errors))
	ctx.close()

	browser.close()

print('\n%d passed, %d failed' % (passed, len(failed)))
if failed:
	sys.exit(1)
