#!/usr/bin/env python3
"""
Readings of deleted posts on Foxfire Studio, Recordings (1.36.2):

1. Each row says what became of its reading: yes, no, and yes, post in the
   Trash; the reading on a live post has no Delete; the others do.
2. Delete opens a question on the page (no browser dialog), Cancel closes it
   and gives the focus back; Yes, delete for good deletes the reading and
   says so; the row is gone and the file no longer answers.
3. Clear readings of deleted posts shows the count, asks the same way, and
   clears the post in the Trash's reading, leaving the live one.
4. Download CSV leaves the Delete column out; axe-core finds nothing on the
   list with a question open; the list fits 393 px.

Usage: TD_SEED=/tmp/tidy.json tests/browser/tidy.py http://localhost:8080
"""
import json, os, sys, urllib.request
from playwright.sync_api import sync_playwright
from signin import sign_in
from take_assets import npm_file

BASE = (sys.argv[1] if len(sys.argv) > 1 else 'http://localhost:8080').rstrip('/')
CHROME = os.environ.get('CHROME_PATH', '/usr/bin/google-chrome')
SEED = json.load(open(os.environ['TD_SEED']))
REC = BASE + '/wp-admin/admin.php?page=rrt-recordings'
AXE = npm_file('axe-core', '4.14.1', 'axe.min.js', 'axe-core', sha='29348999450cdf2849a8e70b7912fc2da85a849da39c0d0d5394f51d4a9ed95c').decode('utf8')
TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']

passed = 0
failed = []


def check(ok, name, detail=''):
	global passed
	if ok:
		passed += 1
		print('ok   ' + name)
	else:
		failed.append(name)
		print('FAIL ' + name + ((': ' + str(detail)) if detail != '' else ''))


def row(page, name):
	"""The cells of the row whose file name starts with name, or None."""
	return page.evaluate('''(n) => {
		const r = Array.from(document.querySelectorAll('#rrt-t-recordings tbody tr')).find(tr => tr.cells[1] && tr.cells[1].textContent.trim().startsWith(n));
		return r ? { now: r.cells[7].textContent.trim(), del: !!r.querySelector('details.rrt-tidy-ask'), label: (r.querySelector('details.rrt-tidy-ask summary') || {}).textContent || '' } : null;
	}''', name)


def answers(url):
	try:
		return urllib.request.urlopen(urllib.request.Request(url, method='HEAD'), timeout=10).status
	except Exception as e:
		return getattr(e, 'code', 0)


with sync_playwright() as p:
	browser = p.chromium.launch(executable_path=CHROME if os.path.exists(CHROME) else None)
	page = browser.new_page(viewport={'width': 1280, 'height': 900})
	dialogs = []
	page.on('dialog', lambda d: (dialogs.append(d.message), d.dismiss()))
	sign_in(page, BASE)
	page.goto(REC)

	old = row(page, 'tidy-browser-old')
	cur = row(page, 'tidy-browser-current')
	tr = row(page, 'tidy-browser-trashed')
	check(old and old['now'] == 'no' and old['del'] and old['label'] == 'Delete', 'a replaced reading reads no and has Delete', old)
	check(cur and cur['now'] == 'yes' and not cur['del'], 'the reading on the live post reads yes and has no Delete', cur)
	check(tr and tr['now'] == 'yes, post in the Trash' and tr['del'], 'the reading of a post in the Trash says so and has Delete', tr)
	clear = page.locator('#rrt-tidy-clear summary')
	check(clear.count() == 1 and 'Clear readings of deleted posts (' in clear.inner_text(), 'Clear readings of deleted posts shows with its count', clear.inner_text() if clear.count() else '')

	# Delete asks on the page; Cancel closes it.
	r = page.locator('#rrt-t-recordings tbody tr', has_text='tidy-browser-old')
	r.locator('summary').click()
	q = r.locator('details.rrt-tidy-ask form')
	check(q.is_visible() and 'Delete this reading and its captions for good?' in q.inner_text(), 'Delete asks on the page')
	check(page.evaluate('() => document.activeElement && document.activeElement.type === "submit"'), 'the question takes the focus to its Yes button')
	page.add_script_tag(content=AXE)
	v = page.evaluate('''(t) => axe.run({ include: ['#rrt-t-recordings', '#rrt-tidy-clear'] }, { runOnly: { type: 'tag', values: t }, resultTypes: ['violations'] }).then(r => r.violations.map(v => v.id + ' x' + v.nodes.length))''', TAGS)
	check(v == [], 'axe-core finds nothing on the list with a question open', v)
	r.locator('.rrt-tidy-cancel').click()
	check(not q.is_visible() and page.evaluate('() => document.activeElement && document.activeElement.tagName === "SUMMARY"'), 'Cancel closes the question and gives the focus back to Delete')

	# The CSV leaves the Delete column out.
	csv = page.evaluate('''() => { const t = document.getElementById('rrt-t-recordings'); return Array.from(t.rows[1].cells).filter(c => !c.classList.contains('rrt-no-csv')).length + '/' + t.rows[1].cells.length; }''')
	check(csv == '10/11', 'Download CSV leaves the Delete column out', csv)

	# Yes, delete for good.
	mp3 = page.evaluate('() => (Array.from(document.querySelectorAll("#rrt-t-recordings a")).find(a => a.textContent.startsWith("tidy-browser-old")) || {}).href')
	r.locator('summary').click()
	with page.expect_navigation():
		r.locator('button[type=submit]').click()
	note = page.locator('.rrt-tidy-msg').inner_text() if page.locator('.rrt-tidy-msg').count() else ''
	check('Deleted the reading and its captions.' in note, 'Delete says what it did', note)
	check(row(page, 'tidy-browser-old') is None and row(page, 'tidy-browser-current'), 'the row is gone and the live reading stays')
	check(mp3 and answers(mp3) == 404, 'the deleted file no longer answers', mp3)

	# Clear.
	page.locator('#rrt-tidy-clear summary').click()
	with page.expect_navigation():
		page.locator('#rrt-tidy-clear button[type=submit]').click()
	note = page.locator('.rrt-tidy-msg').inner_text() if page.locator('.rrt-tidy-msg').count() else ''
	check(note.startswith('Cleared ') and row(page, 'tidy-browser-trashed') is None and row(page, 'tidy-browser-current'), 'Clear removes the reading of the post in the Trash and keeps the live one', note)
	check(dialogs == [], 'no browser dialog was ever shown', dialogs)

	# 393 px.
	page.set_viewport_size({'width': 393, 'height': 852})
	page.goto(REC)
	wide = page.evaluate('() => document.documentElement.scrollWidth - document.documentElement.clientWidth')
	check(wide <= 0, 'the Recordings screen fits 393 px', wide)
	browser.close()

print('\n%d passed, %d failed' % (passed, len(failed)))
sys.exit(1 if failed else 0)
