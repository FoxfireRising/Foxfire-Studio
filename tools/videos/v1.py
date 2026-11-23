"""Video 1: make your first reading."""
from playwright.sync_api import sync_playwright
from common import *
from mic import mic_wav
from rec import Recorder, render
import pointer as P
OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', 'docs', 'media')
with sync_playwright() as p:
	b, ctx, page, box = launch(p, mic=mic_wav())
	page.goto(TP, wait_until='load'); page.wait_for_timeout(1500)
	page.evaluate("a => a.forEach(x => { var e = document.getElementById(x[0]); if (e.checked !== x[1]) e.click(); })", [['tpRecOpt', True], ['tpWhisperOpt', False]])
	P.park(page, 700, 560)
	r = Recorder(page, '/tmp/v1'); r.start()
	r.say('Open the studio on any post. It becomes a script, and nothing on the post changes.')
	r.hold(4.5)
	r.say('The Controls panel sets the pace, size and font, and switches recording on.')
	P.move(page, '#tpRecOpt'); r.hold(2.2)
	P.click(page, '#tpPanelClose', after=900)
	r.say('Press Begin. After a short countdown, each word lights up as you read.')
	P.click(page, '#tpPlay', after=200)
	P.park(page, 1240, 560)
	r.hold(7)
	r.say('Your voice is recorded as you go. Press Pause at any time, and the recording pauses too.')
	r.hold(7)
	r.say('Press Finish recording when you are done.')
	P.click(page, '#tpFinish', after=200)
	r.say('The take is cleaned up, leveled for podcasts and made into an MP3, all in your browser.')
	page.wait_for_selector('#tpResult.is-on', timeout=90000)
	P.park(page, 1240, 560)
	r.hold(3.5)
	r.say('How it went: your pace, pauses and phrase endings, in plain words.')
	r.hold(3)
	page.evaluate("""() => { var b = document.querySelector('#tpResult .tp-result-body') || document.querySelector('#tpResult'); var s = b; while (s && s.scrollHeight <= s.clientHeight + 2) { s = s.parentElement; } if (!s) s = b; window._sc = s; }""")
	page.evaluate("() => { var s = window._sc; var top = s.scrollHeight; var t0 = performance.now(); (function f(){ var k = Math.min(1,(performance.now()-t0)/5000); s.scrollTop = k*top; if (k<1) requestAnimationFrame(f); })(); }")
	r.hold(5.5)
	r.say('Use this recording puts the reading on the post, with a player for your visitors.')
	try:
		P.move(page, '#tpResult button:has-text("Use this recording")')
	except Exception as e:
		print('no use button', e)
	r.hold(3.5)
	r.stop()
	render(r, OUT + '/first-reading.mp4', poster=OUT + '/first-reading.jpg', poster_at=19)
	b.close()
print('done')
