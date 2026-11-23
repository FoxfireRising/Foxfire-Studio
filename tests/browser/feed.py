#!/usr/bin/env python3
"""
The full Podcasting 2.0 feed (1.10.0), as a person sets it up: the Podcast
feed screen's episode list (chapters, transcript, soundbite), the Pings
switch greyed out until the feed is published, the new fields saved, the feed
read signed out with every new tag, the chapters file with its type and in
time order, and both gone again once the feed is switched off (which also
switches the pings off).

Usage: FF_SEED=<json from tests/wp/feed.php> tests/browser/feed.py http://localhost:8080

The chapters file is fetched without its .json ending: PHP's test server
answers a missing .json path itself, where a real server's rewrite rules pass
it to WordPress. tests/php/feed.php checks that both forms are recognised.
"""
import json, os, re, sys
import xml.etree.ElementTree as ET
from playwright.sync_api import sync_playwright
from signin import sign_in

BASE = sys.argv[1].rstrip('/') if len(sys.argv) > 1 else 'http://localhost:8080'
CHROME = os.environ.get('CHROME_PATH', '/usr/bin/google-chrome')
SEED = json.loads(open(os.environ['FF_SEED']).read())
POST, PLAIN = int(SEED['post']), int(SEED['plain'])
SCREEN = BASE + '/wp-admin/admin.php?page=rrt-podcast'
NS = {'podcast': 'https://podcastindex.org/namespace/1.0', 'atom': 'http://www.w3.org/2005/Atom', 'itunes': 'http://www.itunes.com/dtds/podcast-1.0.dtd'}
FUND = 'https://www.paypal.com/donate/?hosted_button_id=TESTFEED'

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


def save(page):
	with page.expect_navigation():
		page.click('#submit')


with sync_playwright() as p:
	browser = p.chromium.launch(executable_path=CHROME if os.path.exists(CHROME) else None)
	ctx = browser.new_context(viewport={'width': 1280, 'height': 1000})
	out = browser.new_context()
	page = ctx.new_page()
	errors = []
	page.on('pageerror', lambda e: errors.append(str(e)))
	sign_in(page, BASE)

	page.goto(SCREEN)
	rows = page.locator('table.rrt-pc-eps tbody tr')
	text = {}
	for i in range(rows.count()):
		cells = rows.nth(i).locator('td')
		text[cells.nth(0).inner_text().strip()] = [cells.nth(k).inner_text().strip() for k in range(1, 4)]
	ft = text.get('Feed test')
	check(ft is not None and ft[0] == '3' and ft[1] == 'Yes', 'the episode list shows the chapters and transcript', text)
	check(ft is not None and 'top suggestion' in ft[2] and re.search(r'\d+:\d\d, \d+ s', ft[2]), 'and the soundbite, from the top suggestion', ft)
	pl = text.get('Feed plain test')
	check(pl is not None and pl[0] == 'None' and pl[1] == 'None' and pl[2].startswith('None'), 'a plain episode has none of them', pl)
	check(page.locator('input[name=pings]').is_disabled(), 'Pings is greyed out until the feed is published')
	r = out.request.get(BASE + '/podcast-chapters/%d' % POST)
	check(r.status == 404, 'no chapters file while the feed is off', r.status)

	page.check('input[name=on]')
	page.fill('#rrt-pc-fund', FUND)
	page.fill('#rrt-pc-loc', 'Test Valley')
	page.fill('#rrt-pc-txt', 'verify: abc123\nA plain line.')
	page.fill('#rrt-pc-person', '')
	save(page)
	check(page.locator('.notice-success').count() == 1, 'the settings are saved')
	check(page.input_value('#rrt-pc-fund') == FUND and page.input_value('#rrt-pc-loc') == 'Test Valley' and page.input_value('#rrt-pc-txt') == 'verify: abc123\nA plain line.', 'the new fields keep their values')
	pings = page.locator('input[name=pings]')
	check(pings.is_enabled() and not pings.is_checked(), 'Pings can be switched on once the feed is published, and starts off')
	check(page.get_attribute('#rrt-pc-fundtxt', 'placeholder').startswith('Support '), 'the support words show their default', page.get_attribute('#rrt-pc-fundtxt', 'placeholder'))
	pings.check()
	save(page)
	check(page.locator('input[name=pings]').is_checked(), 'Pings stays on once saved')
	feed_url = page.input_value('input.code[readonly]')

	r = out.request.get(feed_url)
	check(r.status == 200, 'the feed answers signed out', r.status)
	body = r.text()
	try:
		ch = ET.fromstring(body).find('channel')
	except ET.ParseError as e:
		ch = None
		check(False, 'the feed is well-formed XML', e)
	if ch is not None:
		check(ch.findtext('podcast:medium', namespaces=NS) == 'podcast', 'podcast:medium is podcast')
		person = ch.find('podcast:person', NS)
		check(person is not None and person.get('role') == 'host' and person.text == ch.findtext('itunes:author', namespaces=NS), 'podcast:person names the author as host', person.attrib if person is not None else None)
		check(person is not None and person.get('href') is None and person.get('img') is None, 'with no link on an http site, and no picture yet')
		fund = ch.find('podcast:funding', NS)
		check(fund is not None and fund.get('url') == FUND and fund.text == 'Support ' + ch.findtext('title'), 'podcast:funding with the support link and its default words', fund.attrib if fund is not None else None)
		check(ch.findtext('podcast:location', namespaces=NS) == 'Test Valley', 'podcast:location')
		txts = ch.findall('podcast:txt', NS)
		check(len(txts) == 2 and txts[0].get('purpose') == 'verify' and txts[0].text == 'abc123' and txts[1].get('purpose') is None and txts[1].text == 'A plain line.', 'podcast:txt lines, one with its purpose', [(t.get('purpose'), t.text) for t in txts])
		hubs = [a.get('href') for a in ch.findall('atom:link', NS) if a.get('rel') == 'hub']
		check(hubs == ['https://pubsubhubbub.appspot.com/'], 'the hub is named while pings are on', hubs)
		items = {i.findtext('title'): i for i in ch.findall('item')}
		it = items.get('Feed test')
		check(it is not None, 'the episode is in the feed', list(items))
		if it is not None:
			c = it.find('podcast:chapters', NS)
			check(c is not None and c.get('url', '').endswith('/podcast-chapters/%d.json' % POST) and c.get('type') == 'application/json+chapters', 'podcast:chapters points to the chapters file', c.attrib if c is not None else None)
			t = it.find('podcast:transcript', NS)
			lang = ch.findtext('language')
			check(t is not None and t.get('language') == lang and t.get('type') == 'text/vtt', 'podcast:transcript gives its language', t.attrib if t is not None else None)
			sb = it.find('podcast:soundbite', NS)
			ok = sb is not None and re.match(r'^\d+\.\d\d$', sb.get('startTime', '')) and re.match(r'^\d+\.\d\d$', sb.get('duration', ''))
			check(ok and 30 <= float(sb.get('duration')) <= 60.6 and float(sb.get('startTime')) > 0 and sb.text and len(sb.text) <= 60, 'podcast:soundbite is the top suggestion, 30 to 60 s, with a short title', sb.attrib if sb is not None else None)
		pt = items.get('Feed plain test')
		check(pt is not None and pt.find('podcast:chapters', NS) is None and pt.find('podcast:soundbite', NS) is None and pt.find('podcast:transcript', NS) is None, 'a plain episode has no chapters, soundbite or transcript')

	r = out.request.get(BASE + '/podcast-chapters/%d' % POST)
	check(r.status == 200 and r.headers.get('content-type', '').startswith('application/json+chapters'), 'the chapters file answers signed out, as application/json+chapters', '%s %s' % (r.status, r.headers.get('content-type')))
	check(r.headers.get('access-control-allow-origin') == '*', 'web players may fetch it')
	try:
		j = r.json()
	except Exception as e:
		j = {}
	check(j.get('version') == '1.2.0' and [c['title'] for c in j.get('chapters', [])] == ['Opening', 'The second part', 'Close'], 'it lists the chapters in time order', j)
	check(len(j.get('chapters', [])) == 3 and j['chapters'][1].get('url') == 'https://example.com/second' and j['chapters'][1]['startTime'] == 60, 'with their times and links')
	r = out.request.get(BASE + '/podcast-chapters/%d' % PLAIN)
	check(r.status == 404, 'a reading with no chapters has no chapters file', r.status)
	r = out.request.get(BASE + '/podcast-chapters/999999')
	check(r.status == 404, 'nor has a post that does not exist', r.status)

	page.goto(SCREEN)
	page.uncheck('input[name=on]')
	save(page)
	check(page.locator('input[name=pings]').is_disabled() and not page.locator('input[name=pings]').is_checked(), 'switching the feed off switches the pings off')
	r = out.request.get(BASE + '/podcast-chapters/%d' % POST)
	check(r.status == 404, 'and the chapters file is gone', r.status)
	r = out.request.get(feed_url)
	check(r.status == 404, 'as is the feed', r.status)
	check(not errors, 'no script errors', errors)
	browser.close()

print('\n%d passed, %d failed' % (passed, len(failed)))
sys.exit(1 if failed else 0)
