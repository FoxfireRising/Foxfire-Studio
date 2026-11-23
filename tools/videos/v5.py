"""Video 5: a clip for social media."""
from playwright.sync_api import sync_playwright
from common import *
from take2 import plan, chunks
from rec import Recorder, render
import ranges
import pointer as P
OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', 'docs', 'media')
with sync_playwright() as p:
	b, ctx, page, box = launch(p, size=(1066, 517), scale=1.2); ranges.install(ctx)
	page.goto(TP, wait_until='load'); page.wait_for_timeout(1000)
	words = page.evaluate("Array.prototype.map.call(document.querySelectorAll('.tp-w'), e => e.textContent)")
	heard, secs = plan(words); box['js'] = stub_js(chunks(heard))
	page.goto(BASE + '/wp-admin/admin.php?page=rrt-readings&post=%d' % POST, wait_until='load'); page.wait_for_timeout(2500)
	page.evaluate("document.querySelector('.rrt-suggest-table').scrollIntoView({block: 'center'})"); page.wait_for_timeout(400)
	P.park(page, 1000, 480)
	r = Recorder(page, '/tmp/v5'); r.start()
	r.say("Each reading's page suggests moments for short clips, each a whole stretch of sentences.")
	P.move(page, '.rrt-suggest-table tbody tr'); r.hold(3)
	r.say('Make this clip opens the clip maker at that moment.')
	P.click(page, 'button:has-text("Make this clip")', after=1500)
	r.say('Choose the shapes: square for feeds, vertical for Stories and Reels, wide for YouTube.')
	page.evaluate("() => { document.querySelectorAll('.rrt-clip-check input').forEach((c, i) => { c.checked = i === 0; }); }")
	P.click(page, '.rrt-clip-check input >> nth=1', after=1500)
	r.say('Each clip gets its own tagged link, so you can see which one brought listeners.')
	P.move(page, 'button:has-text("Copy link")'); r.hold(3)
	r.say('Make the clip draws it in your browser, captions and all. (Sped up here.)')
	P.click(page, '.rrt-clip-make', after=300)
	fa = r.now()
	page.wait_for_function("/^Done/.test(document.querySelector('.rrt-clip-status').textContent)", timeout=300000)
	fb = r.now()
	page.evaluate("document.querySelector('.rrt-clip-result').scrollIntoView({behavior: 'smooth', block: 'start'})")
	r.hold(1.2)
	r.say('Watch it, then download it or save it to the Media Library.')
	page.evaluate("() => { var v = document.querySelector('.rrt-clip-result video'); v.muted = true; v.play(); }")
	P.park(page, 1000, 480)
	r.hold(8)
	r.stop()
	render(r, OUT + '/clips.mp4', poster=OUT + '/clips.jpg', poster_at=-3, fast=[(fa + 2, fb, 4)])
	b.close()
print('done')
