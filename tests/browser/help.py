#!/usr/bin/env python3
"""
Help (1.32.0), as people meet it:

1. The Help screen: every guide listed, the search box shows only the guides
   that match and says how many, says so when none does, and Escape clears
   it; arriving at one guide moves the focus there; axe-core finds nothing.
2. The Help link beside the heading of the plugin's screens (after any Add
   New button), opening the screen's own guide, and the Help tab's text; no
   link on WordPress's own screens.
3. The studio's Controls panel links to the Help screen in a new tab, named
   as such, and the panel still fits an iPhone's width (393 px).
4. (1.36.0) The guides in groups, a group hidden while none of its guides
   matches a search, Start here's links, a part's anchor taking the focus,
   the Help with this section links under Settings' and Podcast feed's
   headings, and the Help screen at 393 px with nothing wider than the screen.

Usage: tests/browser/help.py http://localhost:8080
"""
import os, sys
from playwright.sync_api import sync_playwright
from signin import sign_in
from take_assets import npm_file

BASE = (sys.argv[1] if len(sys.argv) > 1 else 'http://localhost:8080').rstrip('/')
CHROME = os.environ.get('CHROME_PATH', '/usr/bin/google-chrome')
SHOTS = os.environ.get('HELP_SHOTS', '')
AXE = npm_file('axe-core', '4.14.1', 'axe.min.js', 'axe-core', sha='29348999450cdf2849a8e70b7912fc2da85a849da39c0d0d5394f51d4a9ed95c').decode('utf8')
TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']
HELP = BASE + '/wp-admin/admin.php?page=rrt-help'

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


def axe(page, include):
	page.add_script_tag(content=AXE)
	return page.evaluate(
		'''(a) => axe.run({ include: a.include }, { runOnly: { type: 'tag', values: a.tags }, resultTypes: ['violations'] })
			.then(r => r.violations.map(v => v.id + ' x' + v.nodes.length + ' (' + v.nodes.slice(0, 3).map(n => n.target.join(' ')).join(', ') + ')'))''',
		{'include': include, 'tags': TAGS},
	)


def shown(page, sel):
	return page.evaluate('(s) => Array.from(document.querySelectorAll(s)).filter(n => !n.hidden).map(n => n.id || n.getAttribute("data-rrt-help-for"))', sel)


with sync_playwright() as p:
	browser = p.chromium.launch(executable_path=CHROME if os.path.exists(CHROME) else None)
	ctx = browser.new_context(viewport={'width': 1280, 'height': 1000})
	errors = []
	page = ctx.new_page()
	page.on('pageerror', lambda e: errors.append(str(e)))
	sign_in(page, BASE)

	# ---- 1. The Help screen ----
	page.goto(HELP)
	page.wait_for_selector('.rrt-help-search:not([hidden])', timeout=10000)
	all_topics = shown(page, '.rrt-help-topic')
	check(len(all_topics) >= 25, 'the Help screen shows every guide', all_topics)
	groups = shown(page, '.rrt-help-group')
	check(len(page.query_selector_all('.rrt-help-group')) == 8 and len(groups) == 8, 'the list of guides is in eight groups', groups)
	check(page.evaluate("document.querySelector('.rrt-help-group li').getAttribute('data-rrt-help-for')") == 'start', 'Start here is the first guide')
	check(len(shown(page, '.rrt-help-toc li')) == len(all_topics), 'with an entry for each in the list of guides')
	check(page.is_visible('#rrt-help-q'), 'the search box shows once the script runs')
	check(page.is_visible('#adminmenu a[href="admin.php?page=rrt-help"]'), 'Help is in the Foxfire Studio menu')

	page.fill('#rrt-help-q', 'phone')
	now = shown(page, '.rrt-help-topic')
	check('rrt-help-phone' in now and len(now) < len(all_topics), 'searching for phone keeps the phone guide and hides others', now)
	check('phone' in shown(page, '.rrt-help-toc li'), 'and the list of guides follows')
	count = page.inner_text('#rrt-help-count')
	check(('%d guide' % len(now)) in count, 'the count says how many match', count)
	page.fill('#rrt-help-q', 'Overcast')
	hidden_groups = page.evaluate("Array.from(document.querySelectorAll('.rrt-help-group')).filter(g => g.hidden).length")
	check(hidden_groups >= 5, 'a group with no matching guide is hidden while searching', hidden_groups)
	page.fill('#rrt-help-q', 'podcast feed')
	check('rrt-help-feed' in shown(page, '.rrt-help-topic'), 'every word must match, in any order')
	page.fill('#rrt-help-q', 'zzqx')
	check(shown(page, '.rrt-help-topic') == [] and page.is_visible('.rrt-help-none'), 'no match says so')
	page.focus('#rrt-help-q')
	page.keyboard.press('Escape')
	check(page.input_value('#rrt-help-q') == '' and len(shown(page, '.rrt-help-topic')) == len(all_topics) and not page.is_visible('.rrt-help-none'), 'Escape clears the search and shows every guide')
	check(len(shown(page, '.rrt-help-group')) == 8, 'and every group')
	found = axe(page, [['#wpbody-content']])
	check(found == [], 'axe finds nothing on the Help screen', found)

	page.goto(HELP + '#rrt-help-feed')
	page.wait_for_timeout(300)
	check(page.evaluate('document.activeElement.id') == 'rrt-help-feed', 'arriving at one guide moves the focus to it', page.evaluate('document.activeElement.id'))
	page.click('.rrt-help-toc a[href="#rrt-help-keys"]')
	page.wait_for_timeout(200)
	check(page.evaluate('document.activeElement.id') == 'rrt-help-keys', 'and so does a link in the list of guides')
	page.click('#rrt-help-start .rrt-help-links a[href="#rrt-help-review"]')
	page.wait_for_timeout(200)
	check(page.evaluate('document.activeElement.id') == 'rrt-help-review', 'Start here links to its guides')
	page.goto(HELP + '#rrt-help-stats-reports')
	page.wait_for_timeout(300)
	check(page.evaluate('document.activeElement.id') == 'rrt-help-stats-reports', 'arriving at one part of a guide moves the focus to it', page.evaluate('document.activeElement.id'))
	page.goto(HELP + '#rrt-help-group-record')
	page.wait_for_timeout(300)
	check(page.evaluate('document.activeElement.id') == 'rrt-help-group-record', 'and so does arriving at a group')
	if SHOTS:
		page.screenshot(path=os.path.join(SHOTS, 'help.png'), full_page=False)

	# ---- 2. The Help link and Help tab on the plugin's screens ----
	for addr, topic in [
		('admin.php?page=rrt-podcast', 'feed'),
		('admin.php?page=rrt-dashboard', 'stats'),
		('options-general.php?page=rrt-settings', 'settings'),
		('admin.php?page=rrt-backlog', 'studio-backlog'),
		('admin.php?page=rrt-recordings', 'recordings'),
		('edit.php?post_type=rrt_collection', 'collections'),
		('edit.php?post_type=rrt_spot', 'sponsors'),
	]:
		page.goto(BASE + '/wp-admin/' + addr)
		page.wait_for_selector('.rrt-help-link', timeout=10000)
		href = page.get_attribute('.rrt-help-link', 'href') or ''
		check(href.endswith('page=rrt-help#rrt-help-' + topic), addr + ': the Help link opens its guide', href)
	right = page.evaluate('''() => { const l = document.querySelector('.rrt-help-link'); const p = l.previousElementSibling; return p ? p.className : ''; }''')
	check('page-title-action' in right, 'on a list screen the link comes after Add New', right)

	page.goto(BASE + '/wp-admin/admin.php?page=rrt-podcast')
	page.wait_for_selector('.rrt-help-link', timeout=10000)
	page.wait_for_timeout(300)
	beside = page.evaluate('''() => { const h = document.querySelector('.wrap h1').getBoundingClientRect(); const l = document.querySelector('.rrt-help-link').getBoundingClientRect(); return [Math.round(h.top), Math.round(l.top), Math.round(h.bottom)]; }''')
	check(beside[1] < beside[2], 'the link sits beside the heading, above any notice', beside)
	page.click('#contextual-help-link')
	page.wait_for_selector('#tab-panel-rrt-help', state='visible', timeout=5000)
	tab = page.inner_text('#tab-panel-rrt-help')
	check('The podcast feed' in tab and page.is_visible('#tab-panel-rrt-help a[href*="rrt-help-feed"]'), 'the Help tab names the guide and links to it', tab)
	with page.expect_navigation():
		page.click('.rrt-help-link')
	page.wait_for_timeout(300)
	check(page.evaluate('document.activeElement.id') == 'rrt-help-feed', 'following the Help link lands in the guide')

	page.goto(BASE + '/wp-admin/edit.php')
	page.wait_for_timeout(300)
	check(page.query_selector('.rrt-help-link') is None, 'WordPress\'s own Posts screen gets no Help link')

	# ---- 4. Help with this section ----
	page.goto(BASE + '/wp-admin/options-general.php?page=rrt-settings')
	page.wait_for_selector('.rrt-help-part-link', timeout=10000)
	parts = page.evaluate('''() => Array.from(document.querySelectorAll('#wpbody-content h2')).map(h => { const n = h.nextElementSibling; return [h.textContent.trim(), n && n.classList.contains('rrt-help-part-link') ? n.querySelector('a').getAttribute('href').split('#')[1] : '']; })''')
	missing = [p_[0] for p_ in parts if not p_[1]]
	check(len(parts) >= 15 and not missing, 'Settings: a Help with this section link under every section heading', missing)
	check(dict(parts).get('Take shelf') == 'rrt-help-settings-shelf' and dict(parts).get('Music bed') == 'rrt-help-sound-music', 'each opens its own part', parts)
	name = page.evaluate('''() => { const h = Array.from(document.querySelectorAll('#wpbody-content h2')).find(x => x.textContent.trim() === 'Take shelf'); return h.nextElementSibling.textContent.replace(/\\s+/g, ' ').trim(); }''')
	check(name == 'Help with this section (Take shelf)', 'its name says which section', name)
	found = axe(page, [['.rrt-help-part-link']])
	check(found == [], 'axe finds nothing in the section links', found)
	with page.expect_navigation():
		page.click('#rrt-embeds-settings + .rrt-help-part-link a')
	page.wait_for_timeout(300)
	check(page.evaluate('document.activeElement.id') == 'rrt-help-embeds', 'following one lands in its guide', page.evaluate('document.activeElement.id'))
	page.goto(BASE + '/wp-admin/admin.php?page=rrt-podcast')
	page.wait_for_selector('.rrt-help-part-link', timeout=10000)
	pod = page.evaluate('''() => Array.from(document.querySelectorAll('#wpbody-content h2')).map(h => { const n = h.nextElementSibling; return n && n.classList.contains('rrt-help-part-link') ? 1 : 0; })''')
	check(len(pod) >= 6 and all(pod), 'Podcast feed: a link under every section heading', pod)

	narrow = browser.new_context(viewport={'width': 393, 'height': 852}, is_mobile=True, has_touch=True, device_scale_factor=3)
	narrow.add_cookies(ctx.cookies())
	hn = narrow.new_page()
	hn.goto(HELP)
	hn.wait_for_selector('.rrt-help-search:not([hidden])', timeout=10000)
	wide = hn.evaluate('[document.documentElement.scrollWidth, document.documentElement.clientWidth]')
	check(wide[0] <= wide[1], 'the Help screen fits 393 px', wide)
	if SHOTS:
		hn.screenshot(path=os.path.join(SHOTS, 'help-393.png'))
	narrow.close()

	# ---- 3. The studio ----
	phone = browser.new_context(viewport={'width': 393, 'height': 852}, is_mobile=True, has_touch=True, device_scale_factor=3)
	phone.add_cookies(ctx.cookies())
	tp = phone.new_page()
	tp.on('pageerror', lambda e: errors.append('studio: ' + str(e)))
	tp.goto(BASE + '/?teleprompter=1&tp_practice=1')
	tp.wait_for_selector('#tpHelpLink', state='attached', timeout=20000)
	link = tp.evaluate('''() => { const a = document.getElementById('tpHelpLink'); return { href: a.getAttribute('href'), target: a.target, rel: a.rel, name: a.textContent.replace(/\\s+/g, ' ').trim() }; }''')
	check(link['href'].endswith('page=rrt-help#rrt-help-group-record') and link['target'] == '_blank' and 'noopener' in link['rel'], 'the Controls panel links to the Help screen in a new tab', link)
	check('Help and guides' in link['name'] and 'opens in a new tab' in link['name'], 'and its name says it opens a new tab', link['name'])
	tp.evaluate('''() => { const p = document.getElementById('tpPanel'); if (p) { p.scrollTop = p.scrollHeight; } }''')
	fit = tp.evaluate('''() => { const p = document.getElementById('tpPanel'); return p ? [p.scrollWidth, p.clientWidth] : [0, 0]; }''')
	check(fit[0] <= fit[1], 'the panel still fits 393 px with the link in it', fit)
	if SHOTS:
		tp.screenshot(path=os.path.join(SHOTS, 'help-studio-393.png'))
	phone.close()

	check(not errors, 'no script errors', errors)
	browser.close()

print('\n%d passed, %d failed' % (passed, len(failed)))
sys.exit(1 if failed else 0)
