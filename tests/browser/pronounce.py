#!/usr/bin/env python3
"""
The pronunciation list (1.15.0), as a person uses it: the Pronunciation
screen with its rows, suggestions, search and filters, a note changed in
place, Add a note, Move to with a clash, Forget, Export CSV, Export backup
and Import with its preview; then a studio tab opened before those changes,
which must not undo them when it saves a note of its own, must drop a taught
word forgotten on the screen from its own copy, and links to the screen from
its note menu.

Usage: PN_POST=<post id> tests/browser/pronounce.py http://localhost:8080
The post comes from run.sh: two paragraphs with names in them.
"""
import json, os, sys, time
from playwright.sync_api import sync_playwright
from signin import sign_in

BASE = sys.argv[1].rstrip('/') if len(sys.argv) > 1 else 'http://localhost:8080'
CHROME = os.environ.get('CHROME_PATH', '/usr/bin/google-chrome')
POST_ID = int(os.environ['PN_POST'])
SCREEN = BASE + '/wp-admin/admin.php?page=rrt-pronounce'
TP = BASE + '/?teleprompter=1&tp_post=%d' % POST_ID
OUT = os.environ.get('PN_OUT', '/tmp/foxfire-pronounce')
os.makedirs(OUT, exist_ok=True)

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
	ctx = browser.new_context(viewport={'width': 1280, 'height': 900}, accept_downloads=True)
	page = ctx.new_page()
	errors = []
	page.on('pageerror', lambda e: errors.append(str(e)))
	sign_in(page, BASE)

	def rest(method, route, body=None):
		return page.evaluate("""([m, r, b]) => fetch(RRTPronBoot.rest.replace(/pronounce$/, r), { method: m, credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': RRTPronBoot.nonce }, body: b ? JSON.stringify(b) : undefined }).then(x => x.json())""", [method, route, body])

	def settle():
		# The screen draws the new list before it stops being busy (1.15.0), so wait for that.
		page.wait_for_selector('.rrt-pron:not(.is-busy)')

	def row(word):
		return page.locator('.rrt-pron-table tbody tr', has=page.locator('.rrt-pron-word strong', has_text=word))

	# Start clean: your own words, and the post's, set through the site.
	page.goto(SCREEN, wait_until='load')
	rest('POST', 'learned', {'learned': {}, 'replace': True})
	rest('POST', 'notes', {'post': POST_ID, 'scope': 'all', 'notes': [{'k': 'brigid', 'n': 'BRIDJ-id'}]})
	rest('POST', 'notes', {'post': POST_ID, 'scope': 'post', 'notes': [{'k': 'lachesis', 'n': 'LACK-eh-sis'}]})
	rest('POST', 'learned', {'learned': {'pairs': {'brigid': {'bridget': 1}}, 'labels': {'brigid|bridget': '“Brigid” heard as “bridget”'}, 'at': {'brigid|bridget': int(time.time() * 1000) + 1000}, 'forgot': {}}})
	rest('POST', 'learned-post', {'post': POST_ID, 'learned': {'pairs': {'lachesis': {'lakeses': 1}}, 'labels': {'lachesis|lakeses': '“Lachesis” heard as “lakeses”'}, 'at': {'lachesis|lakeses': int(time.time() * 1000) + 1000}, 'forgot': {}}})

	# A studio tab opened now, before the screen changes anything. It keeps an old copy of the taught words.
	tp = ctx.new_page()
	tp.on('pageerror', lambda e: errors.append('studio: ' + str(e)))
	tp.goto(TP, wait_until='load')
	tp.evaluate("""() => { try {
		sessionStorage.setItem('rrtIntroSeen', '1'); localStorage.setItem('rrtIntro', 'off');
		Object.keys(localStorage).filter(k => /^rrtNotes|^rrtCuesPending|^rrtLearnedPostPending/.test(k)).forEach(k => localStorage.removeItem(k));
	} catch (e) {} }""")
	tp.goto(TP, wait_until='load')
	tp.wait_for_timeout(1200)
	check(tp.evaluate("localStorage.getItem('rrtLearned') || ''").find('bridget') >= 0, 'the studio keeps its own copy of your taught words')
	check(tp.evaluate("RRT.notes && /page=rrt-pronounce/.test(RRT.notes.list)"), 'the studio knows where the Pronunciation screen is')

	page.goto(SCREEN, wait_until='load')
	page.wait_for_selector('.rrt-pron-table tbody tr')
	page.screenshot(path=os.path.join(OUT, 'start.png'), full_page=True)
	check(page.locator('.nav-tab-active').inner_text() == 'Pronunciation', 'the screen has its own tab')
	check(not page.is_visible('.rrt-pron-add') and not page.is_visible('.rrt-pron-import'), 'the Add and Import panels start closed')
	check(row('Brigid').count() == 1 and 'BRIDJ-id' in row('Brigid').inner_text() and 'bridget' in row('Brigid').inner_text(), 'your note and taught word for Brigid share a row', row('Brigid').inner_text() if row('Brigid').count() else '')
	check('In every post' in row('Brigid').inner_text(), 'it says it is kept for every post')
	lrow = row('Lachesis')
	check(lrow.count() == 1 and 'Pronounce browser test' in lrow.inner_text() and 'lakeses' in lrow.inner_text(), 'the post’s note and taught word name the post', lrow.inner_text() if lrow.count() else '')
	check(lrow.locator('a.rrt-pron-open').get_attribute('href').endswith('tp_post=%d' % POST_ID), 'a post’s row links to the studio')
	sug = page.inner_text('.rrt-pron-suggest')
	check('Atropos' in sug and 'Name' in sug and 'still to read' in sug, 'Atropos, a name in a post still to read, is suggested', sug[:300])
	check('Brigid' not in sug and 'Lachesis' not in sug, 'words with notes are not suggested')

	# Search and filters.
	page.fill('.rrt-pron-search', 'lakes')
	check(page.locator('.rrt-pron-table tbody tr').count() == 1 and row('Lachesis').count() == 1, 'search finds what Whisper heard')
	page.fill('.rrt-pron-search', '')
	page.select_option('.rrt-pron-filters select >> nth=1', 'all')
	check(row('Lachesis').count() == 0 and row('Brigid').count() == 1, 'the Where filter shows only every-post words')
	page.select_option('.rrt-pron-filters select >> nth=1', '')

	# A note changed in place, while the studio tab is still open with the old one.
	row('Lachesis').locator('.rrt-pron-note-btn').click()
	page.fill('.rrt-pron-note input', 'LAKE-eh-sis')
	with page.expect_response(lambda r: 'rrt/v1/pronounce' in r.url and r.request.method == 'POST'):
		page.keyboard.press('Enter')
	settle()
	check('LAKE-eh-sis' in row('Lachesis').inner_text(), 'Enter saves a changed note')

	# Add a note from a suggestion.
	page.locator('.rrt-pron-sug li', has_text='Atropos').locator('button', has_text='Add a note').click()
	check(page.input_value('.rrt-pron-add input >> nth=0') == 'Atropos', 'Add a note from a suggestion fills in the word')
	page.fill('.rrt-pron-add input >> nth=1', 'AT-ro-pos')
	page.select_option('.rrt-pron-add select >> nth=0', 'post')
	page.select_option('.rrt-pron-add select >> nth=1', str(POST_ID))
	with page.expect_response(lambda r: 'rrt/v1/pronounce' in r.url and r.request.method == 'POST'):
		page.click('.rrt-pron-add button[type=submit]')
	settle()
	check(row('Atropos').count() == 1 and 'AT-ro-pos' in row('Atropos').inner_text(), 'the new note is on the list')
	check('Atropos' not in page.inner_text('.rrt-pron-suggest'), 'and off the suggestions')

	# Not needed.
	first = page.locator('.rrt-pron-sug li strong').first.inner_text()
	with page.expect_response(lambda r: 'rrt/v1/pronounce' in r.url and r.request.method == 'POST'):
		page.locator('.rrt-pron-sug li').first.locator('button', has_text='Not needed').click()
	settle()
	check(first not in [x.inner_text() for x in page.locator('.rrt-pron-sug li strong').all()], 'Not needed takes a word off the suggestions', first)

	# Move Brigid to the post, then back onto a different note: the screen asks which to keep.
	row('Brigid').locator('.rrt-pron-do select').select_option('other')
	check(row('Brigid').locator('.rrt-pron-pick-post select').input_value() == str(POST_ID), 'the post menu starts on the post that has the word')
	row('Brigid').locator('.rrt-pron-pick-post select').select_option(str(POST_ID))
	with page.expect_response(lambda r: 'rrt/v1/pronounce' in r.url and r.request.method == 'POST'):
		row('Brigid').locator('.rrt-pron-pick-post button').click()
	settle()
	check('Pronounce browser test' in row('Brigid').inner_text() and 'bridget' in row('Brigid').inner_text(), 'Move to one post takes the note and the taught word there', row('Brigid').inner_text())
	rest('POST', 'notes', {'post': POST_ID, 'scope': 'all', 'changes': {'brigid': 'BRIJ'}})
	page.reload(wait_until='load')
	page.wait_for_selector('.rrt-pron-table tbody tr')
	prow = page.locator('.rrt-pron-table tbody tr', has_text='Pronounce browser test').filter(has=page.locator('strong', has_text='Brigid'))
	with page.expect_response(lambda r: 'rrt/v1/pronounce' in r.url and r.request.method == 'POST') as mv:
		prow.locator('.rrt-pron-do select').select_option('all')
	settle()
	check(mv.value.status == 409 and page.is_visible('.rrt-pron-clash-row'), 'moving onto a different note asks which to keep', str(mv.value.status))
	with page.expect_response(lambda r: 'rrt/v1/pronounce' in r.url and r.request.method == 'POST'):
		page.locator('.rrt-pron-clash-row button', has_text='BRIJ').click()
	settle()
	check(row('Brigid').count() == 1 and 'BRIJ' in row('Brigid').inner_text() and 'In every post' in row('Brigid').inner_text(), 'the kept note and the taught word are back in every post', row('Brigid').inner_text())

	# Forget Brigid's taught word on the screen.
	with page.expect_response(lambda r: 'rrt/v1/pronounce' in r.url and r.request.method == 'POST'):
		row('Brigid').locator('.rrt-pron-forget').click()
	settle()
	check('bridget' not in row('Brigid').inner_text() and 'Forgotten' in page.inner_text('.rrt-pron-line'), 'Forget takes the taught word off and says so')

	# Export CSV and backup.
	with page.expect_download() as dl:
		page.click('.rrt-pron-actions button:has-text("Export CSV")')
	csv = open(dl.value.path(), encoding='utf-8-sig').read()
	check(csv.startswith('Word,Note,Whisper hears it as,Where,In posts') and 'Lachesis,LAKE-eh-sis,lakeses,Pronounce browser test,1' in csv, 'Export CSV gives the list', csv[:300])
	with page.expect_download() as dl:
		page.click('.rrt-pron-actions button:has-text("Export backup")')
	backup_path = os.path.join(OUT, 'backup.json')
	dl.value.save_as(backup_path)
	bk = json.load(open(backup_path))
	check(bk.get('format') == 'foxfire-studio-pronunciation' and any(pp['id'] == POST_ID for pp in bk['posts']), 'Export backup saves a backup file', dl.value.suggested_filename)

	# Import: empty the post's notes, change one, then bring the backup back with its preview.
	rest('POST', 'notes', {'post': POST_ID, 'scope': 'post', 'notes': [{'k': 'lachesis', 'n': 'OTHER'}]})
	page.reload(wait_until='load')
	page.wait_for_selector('.rrt-pron-table tbody tr')
	with page.expect_response(lambda r: 'rrt/v1/pronounce' in r.url and r.request.method == 'POST'):
		page.set_input_files('.rrt-pron-actions input[type=file]', backup_path)
	settle()
	page.wait_for_selector('.rrt-pron-import:not([hidden])')
	text = page.inner_text('.rrt-pron-import')
	check('1 to add' in text and '1 different note' in text, 'the preview says what it would add and the clash', text[:300])
	page.locator('.rrt-pron-import input[value=file]').check()
	with page.expect_response(lambda r: 'rrt/v1/pronounce' in r.url and r.request.method == 'POST'):
		page.click('.rrt-pron-import button:has-text("Import")')
	settle()
	check('LAKE-eh-sis' in row('Lachesis').inner_text() and row('Atropos').count() == 1, 'import with the file’s note chosen brings both back', row('Lachesis').inner_text())
	check(not page.is_visible('.rrt-pron-import') and not page.is_visible('.rrt-pron-add'), 'the Import and Add panels close when done')
	page.screenshot(path=os.path.join(OUT, 'screen.png'), full_page=True)

	# The studio tab opened before all this saves a note of its own: the screen's changes stay.
	idx = tp.evaluate("RRTStudio.W.findIndex(w => w.key === 'thread')")
	with tp.expect_response(lambda r: 'rrt/v1/notes' in r.url, timeout=5000) as nr:
		tp.evaluate("i => RRTStudio.noteSet(i, 'THRED', 'post')", idx)
	sent = json.loads(nr.value.request.post_data or '{}')
	check(sent.get('changes') == {'thread': 'THRED'}, 'the studio sends only the note it changed', json.dumps(sent))
	rows = rest('GET', 'pronounce')['rows']
	post_notes = {r['key']: r['note'] for r in rows if r['where'] == 'post' and r['note']}
	check(post_notes.get('lachesis') == 'LAKE-eh-sis' and post_notes.get('atropos') == 'AT-ro-pos' and post_notes.get('thread') == 'THRED', 'the old tab did not undo the screen’s notes', json.dumps(post_notes))

	# Reloaded, the studio drops the word forgotten on the screen from its own copy and does not send it back.
	tp.reload(wait_until='load')
	tp.wait_for_timeout(1500)
	learned = rest('GET', 'learned')['learned']
	check('brigid' not in (learned.get('pairs') or {}), 'the studio’s old copy did not bring the forgotten word back', json.dumps(learned)[:300])
	check(tp.evaluate("(() => { var L = JSON.parse(localStorage.getItem('rrtLearned') || '{}'); return !(L.pairs || {}).brigid && !!(L.forgot || {})['brigid|bridget']; })()"), 'and its own copy dropped it, keeping the record that it was forgotten')

	# The note menu links to the screen.
	tp.evaluate("document.getElementById('tpCues').click()")
	tp.wait_for_timeout(200)
	tp.evaluate("i => document.querySelectorAll('#tpTrack .tp-w')[i].click()", idx)
	tp.wait_for_selector('.tp-cue-menu')
	tp.click('.tp-cue-menu [data-cue="note"]')
	href = tp.get_attribute('.tp-cue-menu .tp-note-all', 'href') or ''
	check('page=rrt-pronounce' in href and 'word=thread' in href and tp.get_attribute('.tp-cue-menu .tp-note-all', 'target') == '_blank', 'the studio’s note menu has All notes, opening the screen in a new tab', href)
	page.goto(href, wait_until='load')
	page.wait_for_selector('.rrt-pron-table tbody tr')
	check(page.input_value('.rrt-pron-search') == 'thread' and page.locator('.rrt-pron-table tbody tr').count() == 1, 'it opens searching for that word')

	# Tidy up the reader's words.
	rest('POST', 'learned', {'learned': {}, 'replace': True})
	rest('POST', 'notes', {'post': POST_ID, 'scope': 'all', 'notes': []})
	check(not errors, 'no script errors', '; '.join(errors))
	browser.close()

print('\n%d passed, %d failed' % (passed, len(failed)))
sys.exit(1 if failed else 0)
