#!/usr/bin/env python3
"""
Review for verse and pace (1.11.0), in the studio: a post of short lines
gets the Verse suggestion; choosing a kind starts the prompter in the middle
of its range and is saved with the post; moving the slider on that post is
kept for that post only, and the pace for other posts stays as it was;
going back to Not chosen brings the usual pace back. The Fade meant cue is
drawn, saved and cleared. The Reading audio box shows and saves the kind.

Usage: VS_POST=<post id> tests/browser/verse.py http://localhost:8080
The post must be verse-shaped: six or more short lines.
"""
import json, os, sys
from playwright.sync_api import sync_playwright
from signin import sign_in

BASE = sys.argv[1].rstrip('/') if len(sys.argv) > 1 else 'http://localhost:8080'
CHROME = os.environ.get('CHROME_PATH', '/usr/bin/google-chrome')
POST_ID = int(os.environ['VS_POST'])
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


with sync_playwright() as p:
	browser = p.chromium.launch(executable_path=CHROME if os.path.exists(CHROME) else None)
	ctx = browser.new_context(viewport={'width': 1280, 'height': 800})
	page = ctx.new_page()
	errors = []
	page.on('pageerror', lambda e: errors.append(str(e)))

	sign_in(page, BASE)
	page.goto(TP, wait_until='load')
	page.evaluate("""try {
		sessionStorage.setItem('rrtIntroSeen', '1'); localStorage.setItem('rrtIntro', 'off');
		localStorage.removeItem('rrtPace_%d'); localStorage.removeItem('rrtCuesPending_%d');
		localStorage.setItem('rrtTeleprompter', JSON.stringify({ size: 40, cols: 18, line: 30, rec: false, wpm: 140 }));
	} catch (e) {}""" % (POST_ID, POST_ID))

	def load():
		page.goto(TP, wait_until='load')
		page.wait_for_timeout(400)

	def wpm():
		return int(page.input_value('#tpWpm'))

	def saved_wpm():
		return page.evaluate("JSON.parse(localStorage.getItem('rrtTeleprompter') || '{}').wpm")

	# Start from no kind, whatever an earlier run left.
	page.evaluate("""p => fetch(RRT.kind.rest, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': RRT.kind.nonce }, body: JSON.stringify({ post: p, kind: '' }) })""", POST_ID)
	load()
	check(page.evaluate("RRT.kind && RRT.kind.guess === 'verse' && RRT.kind.kind === ''"), 'a post of short lines with no kind gets the Verse suggestion')
	check(page.is_visible('#tpKindHint') and 'may be verse' in page.inner_text('#tpKindHint'), 'the Practice section suggests Verse', page.inner_text('#tpKindHint') if page.is_visible('#tpKindHint') else '')
	check(page.input_value('#tpKind') == '' and wpm() == 140, 'with no kind, the usual pace', str(wpm()))
	labels = page.evaluate("Array.from(document.querySelectorAll('#tpKind option')).map(o => o.textContent)")
	check(labels == ['Not chosen', 'Prose (140 to 160 wpm)', 'Story (130 to 150 wpm)', 'Verse (110 to 135 wpm)', 'Ritual and chant (95 to 120 wpm)'], 'the menu lists the four kinds with their ranges', json.dumps(labels))

	# Set to Verse: the prompter starts in the middle of the range, and the choice is saved with the post.
	with page.expect_response(lambda r: 'rrt/v1/kind' in r.url) as resp:
		page.click('#tpKindHint .tp-kind-set')
	check(resp.value.status == 200, 'Set to Verse saves the kind on the site', str(resp.value.status))
	check(page.input_value('#tpKind') == 'verse' and wpm() == 125, 'Verse starts the prompter at 125 wpm', str(wpm()))
	check('judges the pace against 110 to 135' in page.inner_text('#tpMsg'), 'the studio says what Verse does', page.inner_text('#tpMsg'))
	check(not page.is_visible('#tpKindHint'), 'the suggestion goes once a kind is chosen')
	check(saved_wpm() == 140, 'the pace saved for other posts stays at 140', str(saved_wpm()))
	load()
	check(page.input_value('#tpKind') == 'verse' and wpm() == 125, 'the kind comes back from the site, with its starting pace', '%s %s' % (page.input_value('#tpKind'), wpm()))

	# Moving the slider on this post is kept for this post only.
	page.evaluate("v => { var s = document.getElementById('tpWpm'); s.value = v; s.dispatchEvent(new Event('input')); }", 115)
	check(saved_wpm() == 140, 'moving the slider on a post with a kind leaves the usual pace alone', str(saved_wpm()))
	load()
	check(wpm() == 115, 'the slider position on this post is remembered', str(wpm()))

	# Another kind starts at its own middle; Not chosen brings the usual pace back.
	with page.expect_response(lambda r: 'rrt/v1/kind' in r.url):
		page.select_option('#tpKind', 'prose')
	check(wpm() == 150, 'Prose starts at 150 wpm', str(wpm()))
	with page.expect_response(lambda r: 'rrt/v1/kind' in r.url):
		page.select_option('#tpKind', '')
	check(wpm() == 140 and page.is_visible('#tpKindHint'), 'Not chosen brings back the usual pace and the suggestion', str(wpm()))
	with page.expect_response(lambda r: 'rrt/v1/kind' in r.url):
		page.select_option('#tpKind', 'verse')

	# The Fade meant cue.
	page.evaluate("document.getElementById('tpCues').click()")
	page.wait_for_timeout(200)
	last = page.evaluate("Array.from(document.querySelectorAll('#tpTrack .tp-w')).findIndex(w => /long$/.test(w.textContent))")
	page.evaluate("i => document.querySelectorAll('#tpTrack .tp-w')[i].click()", last)
	page.wait_for_selector('.tp-cue-menu')
	check(page.is_visible('.tp-cue-menu [data-cue="fade"]'), 'the cue menu offers Fade meant')
	with page.expect_response(lambda r: 'rrt/v1/cues' in r.url, timeout=5000) as cr:
		page.click('.tp-cue-menu [data-cue="fade"]')
	check(cr.value.status == 200, 'a fade meant is saved with the post', str(cr.value.status))
	sent = json.loads(cr.value.request.post_data or '{}')
	# Since 1.15.0 only the changed mark is sent.
	check(any((c.get('cue') or {}).get('f') is True and c.get('i') == last for c in sent.get('changes', [])), 'the saved cue carries the fade', json.dumps(sent))
	check(page.evaluate("i => { var n = document.querySelectorAll('#tpTrack .tp-w')[i].nextSibling; return !!n && n.classList && n.classList.contains('tp-cue-fade'); }", last), 'a tilde shows after the word')
	load()
	check(page.evaluate("document.querySelectorAll('#tpTrack .tp-cue-fade').length") == 1, 'the fade meant comes back from the site')
	page.evaluate("document.getElementById('tpCues').click()")
	page.evaluate("i => document.querySelectorAll('#tpTrack .tp-w')[i].click()", last)
	page.wait_for_selector('.tp-cue-menu')
	check(page.get_attribute('.tp-cue-menu [data-cue="fade"]', 'aria-pressed') == 'true', 'Fade meant shows as on for a word that has it')
	with page.expect_response(lambda r: 'rrt/v1/cues' in r.url, timeout=5000):
		page.click('.tp-cue-menu [data-cue="clear"]')
	check(page.evaluate("document.querySelectorAll('#tpTrack .tp-cue-fade').length") == 0, 'Clear takes the fade off')
	check(not errors, 'no script errors in the studio', '; '.join(errors))

	# The Reading audio box.
	page.goto(BASE + '/wp-admin/post.php?post=%d&action=edit' % POST_ID, wait_until='load')
	page.wait_for_timeout(800)
	try:
		page.evaluate("() => { if (window.wp && wp.data && wp.data.dispatch('core/edit-post')) { var d = wp.data.dispatch('core/edit-post'); if (d.toggleFeature && wp.data.select('core/edit-post').isFeatureActive('welcomeGuide')) { d.toggleFeature('welcomeGuide'); } } }")
	except Exception:
		pass
	box = page.locator('#rrt_kind')
	check(box.count() == 1 and box.input_value() == 'verse', 'the Reading audio box shows the kind', box.input_value() if box.count() else 'missing')

print('\n%d passed, %d failed' % (passed, len(failed)))
sys.exit(1 if failed else 0)
