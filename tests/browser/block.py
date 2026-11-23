"""
The Reading player block (1.28.0), with whichever theme is active.

1. As a visitor: the block plays where it is placed, in place of the top of
   the post; a post without it keeps the top; a page with two chosen
   readings shows both, plays one at a time, and offers Transcript in place
   of read along there. axe-core finds nothing in the players.
2. In the block editor: a post without a reading shows the card with Open
   the studio; a pasted shortcode turns into the block; Another reading is
   chosen from the sidebar's search; the preview shows the player, silent,
   with its note; the saved post then plays the chosen reading.

Usage: BK_SEED=<json from tests/wp/block.php> python3 tests/browser/block.py [http://localhost:8080] [theme name, for the output]
"""
import json
import os
import sys

from playwright.sync_api import sync_playwright
from signin import sign_in
from take_assets import npm_file

BASE = (sys.argv[1] if len(sys.argv) > 1 else os.environ.get('WP_URL', 'http://localhost:8080')).rstrip('/')
THEME = sys.argv[2] if len(sys.argv) > 2 else ''
CHROME = os.environ.get('CHROME_PATH', '/usr/bin/google-chrome')
SEED = json.load(open(os.environ['BK_SEED']))
AXE = npm_file('axe-core', '4.14.1', 'axe.min.js', 'axe-core', sha='29348999450cdf2849a8e70b7912fc2da85a849da39c0d0d5394f51d4a9ed95c').decode('utf8')
TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']
TAG = (' (' + THEME + ')') if THEME else ''

passed = 0
failed = []


def check(ok, name, detail=''):
	global passed
	name = name + TAG
	if ok:
		passed += 1
		print('ok   ' + name)
	else:
		failed.append(name)
		print('FAIL ' + name + ((': ' + str(detail)) if detail != '' else ''))


def no_guide(page):
	page.evaluate("""() => {
		try { const d = wp.data.dispatch('core/edit-post'); if (d.toggleFeature && wp.data.select('core/edit-post').isFeatureActive('welcomeGuide')) { d.toggleFeature('welcomeGuide'); } } catch (e) {}
		try { wp.data.dispatch('core/preferences').set('core/edit-post', 'welcomeGuide', false); } catch (e) {}
	}""")


def canvas(page):
	"""The editor's content: inside its frame where WordPress frames it, the page itself where it does not."""
	if page.locator('iframe[name="editor-canvas"]').count():
		return page.frame_locator('iframe[name="editor-canvas"]')
	return page


def players(page):
	return page.evaluate('''() => Array.from(document.querySelectorAll('.rrt-player')).map(e => {
		const d = JSON.parse(e.getAttribute('data-rrt'));
		return { post: d.post, ready: e.classList.contains('is-ready'), follow: !!e.querySelector('.rrt-follow'), transcript: !!e.querySelector('.rrt-transcript-btn'), inBlock: !!e.closest('.wp-block-foxfire-studio-player') };
	})''')


def save_post(page):
	page.evaluate("async () => { await wp.data.dispatch('core/editor').savePost(); }")
	page.wait_for_function("() => !wp.data.select('core/editor').isSavingPost() && !(wp.data.select('core/edit-post').isSavingMetaBoxes && wp.data.select('core/edit-post').isSavingMetaBoxes())", timeout=60000)
	page.wait_for_timeout(500)


with sync_playwright() as p:
	browser = p.chromium.launch(executable_path=CHROME, args=['--autoplay-policy=no-user-gesture-required'])

	# ---- As a visitor ---------------------------------------------------------
	visitor = browser.new_context(viewport={'width': 1280, 'height': 900})
	page = visitor.new_page()
	errors = []
	page.on('pageerror', lambda e: errors.append(str(e)))

	page.goto(BASE + '/?p=%d' % SEED['own'], wait_until='load')
	page.wait_for_selector('.rrt-player.is-ready')
	found = players(page)
	check(len(found) == 1 and found[0]['post'] == SEED['own'], 'a post with the block shows one player, its own', found)
	check(bool(found) and found[0]['inBlock'], 'inside the block, where it was placed')
	order = page.evaluate('''() => {
		const all = Array.from(document.querySelectorAll('p, .rrt-player'));
		return all.map(e => e.classList.contains('rrt-player') ? 'player' : (e.textContent.indexOf('lantern') >= 0 ? 'first' : (e.textContent.indexOf('window answered') >= 0 ? 'second' : ''))).filter(Boolean);
	}''')
	check(order == ['first', 'player', 'second'], 'between the two paragraphs, in place of the top of the post', order)
	check(bool(found) and found[0]['follow'], 'read along is offered on the reading\'s own page')
	page.click('.rrt-follow')
	page.wait_for_timeout(500)
	check(page.get_attribute('.rrt-follow', 'aria-pressed') == 'true', 'and switches on')

	page.goto(BASE + '/?p=%d' % SEED['plain'], wait_until='load')
	page.wait_for_selector('.rrt-player.is-ready')
	found = players(page)
	first = page.evaluate('''() => { const p = document.querySelector('.rrt-player'); const t = Array.from(document.querySelectorAll('p')).find(e => e.textContent.indexOf('Dew sat') >= 0); return !!(p && t && (p.compareDocumentPosition(t) & Node.DOCUMENT_POSITION_FOLLOWING)); }''')
	check(len(found) == 1 and first, 'a post without the block keeps one player at the top', found)

	page.goto(BASE + '/?p=%d' % SEED['page'], wait_until='load')
	page.wait_for_function("() => document.querySelectorAll('.rrt-player.is-ready').length === 2")
	found = players(page)
	check([f['post'] for f in found] == [SEED['own'], SEED['plain']], 'a post with two chosen readings shows both, in order', found)
	check(all(not f['follow'] and f['transcript'] for f in found), 'away from their own posts, Transcript is offered in place of read along', found)
	page.click('.rrt-player >> nth=0 >> .rrt-play')
	page.wait_for_function("() => !document.querySelectorAll('.rrt-player')[0].querySelector('audio').paused", timeout=10000)
	page.click('.rrt-player >> nth=1 >> .rrt-play')
	page.wait_for_function("() => !document.querySelectorAll('.rrt-player')[1].querySelector('audio').paused", timeout=10000)
	page.wait_for_timeout(300)
	paused = page.evaluate("() => Array.from(document.querySelectorAll('.rrt-player audio')).map(a => a.paused)")
	check(paused == [True, False], 'starting the second player pauses the first', paused)
	page.evaluate("() => document.querySelectorAll('.rrt-player audio').forEach(a => a.pause())")
	page.add_script_tag(content=AXE)
	found = page.evaluate('''(t) => axe.run({ include: [['.wp-block-foxfire-studio-player']] }, { runOnly: { type: 'tag', values: t }, resultTypes: ['violations'] }).then(r => r.violations.map(v => v.id + ' x' + v.nodes.length))''', TAGS)
	check(found == [], 'axe finds nothing in the two blocks', found)
	check(errors == [], 'no script errors on the public pages', errors)
	visitor.close()

	# ---- In the block editor --------------------------------------------------
	ctx = browser.new_context(viewport={'width': 1400, 'height': 1000})
	page = ctx.new_page()
	errors = []
	page.on('pageerror', lambda e: errors.append(str(e)))
	sign_in(page, BASE)

	page.goto(BASE + '/wp-admin/post.php?post=%d&action=edit' % SEED['empty'], wait_until='load')
	page.wait_for_function("() => window.wp && wp.data && wp.data.select('core/editor') && wp.data.select('core/editor').getCurrentPostId()", timeout=60000)
	no_guide(page)
	page.wait_for_timeout(1000)
	check(page.evaluate("() => !!wp.blocks.getBlockType('foxfire-studio/player')"), 'the block is in the editor')
	check(page.evaluate("() => wp.blocks.getBlockType('foxfire-studio/player').title") == 'Reading player', 'called Reading player')
	got = page.evaluate('''(id) => { const b = wp.blocks.rawHandler({ HTML: '<p>[foxfire_player post="' + id + '"]</p>' }); return b.map(x => [x.name, x.attributes.reading]); }''', SEED['plain'])
	check([SEED['plain']] in [[g[1]] for g in got if g[0] == 'foxfire-studio/player'], 'a pasted [foxfire_player post="…"] turns into the block with that reading', got)
	got = page.evaluate('''() => { const b = wp.blocks.rawHandler({ HTML: '<p>[reading_room_player]</p>' }); return b.map(x => [x.name, x.attributes.reading]); }''')
	check(['foxfire-studio/player', 0] in got, 'and so does the older [reading_room_player]', got)

	page.evaluate("() => { const b = wp.blocks.createBlock('foxfire-studio/player'); wp.data.dispatch('core/block-editor').insertBlocks(b); wp.data.dispatch('core/block-editor').selectBlock(b.clientId); }")
	cv = canvas(page)
	cv.locator('text=This post has no reading yet.').wait_for(timeout=20000)
	check(True, 'a post without a reading shows the card: This post has no reading yet')
	href = cv.locator('.wp-block-foxfire-studio-player a:has-text("Open the studio")').get_attribute('href') or ''
	check('teleprompter=1' in href and ('tp_post=%d' % SEED['empty']) in href, 'with Open the studio for this post', href)

	# Another reading, from the sidebar's search.
	try:
		page.evaluate("() => { try { wp.data.dispatch('core/edit-post').openGeneralSidebar('edit-post/block'); } catch (e) {} }")
	except Exception:
		pass
	page.wait_for_timeout(500)
	page.locator('.block-editor-block-inspector label:has-text("Another reading")').click()
	box = page.locator('.block-editor-block-inspector .components-combobox-control input')
	box.wait_for(timeout=10000)
	box.fill('Orchard')
	page.wait_for_timeout(1200)
	opt = page.locator('.components-form-token-field__suggestion:has-text("Orchard morning"), .components-combobox-control__suggestions-container [role=option]:has-text("Orchard morning")').first
	opt.wait_for(timeout=10000)
	opt.click()
	page.wait_for_timeout(500)
	chosen = page.evaluate("() => { const b = wp.data.select('core/block-editor').getBlocks().find(x => x.name === 'foxfire-studio/player'); return b ? b.attributes.reading : null; }")
	check(chosen == SEED['plain'], 'Another reading is chosen from the sidebar\'s search', chosen)
	cv.locator('.rrt-block-preview .rrt-player').wait_for(timeout=20000)
	check(True, 'the preview shows the player')
	check(cv.locator('text=Plays on the published post.').count() == 1, 'with its note, Plays on the published post')
	check(cv.locator('.rrt-block-preview[inert]').count() == 1, 'silent in the editor')
	check(cv.locator('.rrt-block-preview .rrt-row').first.is_visible(), 'drawn as visitors see it')

	page.add_script_tag(content=AXE)
	found = page.evaluate('''(t) => axe.run({ include: [['.block-editor-block-inspector']] }, { runOnly: { type: 'tag', values: t }, resultTypes: ['violations'] }).then(r => r.violations.map(v => v.id + ' x' + v.nodes.length + ' ' + v.nodes.slice(0, 2).map(n => n.target.join(' ')).join(', ')))''', TAGS)
	# WordPress 6.0 points its own search box's (ComboboxControl) aria-activedescendant at an option while the list is closed; that is the editor's own markup, and 7.2 is clean. Allowed on 6.x only.
	old_wp = page.evaluate("() => { const m = document.body.className.match(/version-(\\d+)-(\\d+)/); return m ? +m[1] < 7 : false; }")
	if old_wp:
		core = [f for f in found if f.startswith('aria-valid-attr-value') and 'components-form-token-input' in f]
		if core:
			print('note WordPress\'s own search box on this version: ' + ', '.join(core))
		found = [f for f in found if f not in core]
	check(found == [], 'axe finds nothing in the block\'s sidebar', found)

	save_post(page)
	view = ctx.new_page()
	view.goto(BASE + '/?p=%d' % SEED['empty'], wait_until='load')
	view.wait_for_selector('.rrt-player.is-ready')
	found = players(view)
	check(len(found) == 1 and found[0]['post'] == SEED['plain'], 'the saved post plays the chosen reading', found)
	view.close()

	# This post's reading again: the radio puts it back.
	page.locator('.block-editor-block-inspector label:has-text("This post")').click()
	page.wait_for_timeout(500)
	chosen = page.evaluate("() => { const b = wp.data.select('core/block-editor').getBlocks().find(x => x.name === 'foxfire-studio/player'); return b ? b.attributes.reading : null; }")
	check(chosen == 0, 'This post\'s reading puts the choice back', chosen)

	# A post with its own reading: the preview straight away.
	page.goto(BASE + '/wp-admin/post.php?post=%d&action=edit' % SEED['own'], wait_until='load')
	page.wait_for_function("() => window.wp && wp.data && wp.data.select('core/editor') && wp.data.select('core/editor').getCurrentPostId()", timeout=60000)
	no_guide(page)
	canvas(page).locator('.rrt-block-preview .rrt-player').wait_for(timeout=30000)
	check(True, 'a post with its own reading shows the preview in the editor')
	check(page.evaluate("() => wp.data.select('core/block-editor').getBlocks().filter(b => b.name === 'foxfire-studio/player' && b.isValid !== false).length") == 1, 'the saved block opens with no "unexpected content" warning')
	real = [e for e in errors if 'ResizeObserver' not in e]
	check(real == [], 'no script errors in the editor', real)
	browser.close()

print('%d passed, %d failed' % (passed, len(failed)))
sys.exit(1 if failed else 0)
