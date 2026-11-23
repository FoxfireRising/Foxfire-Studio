#!/usr/bin/env python3
"""
Member feeds (1.21.0), as an owner and two subscribers use them: member
feeds switched on and one person made a member on their profile, a reading
for members refused to visitors and to someone who is not a member, played
by the member on the post, the member's own feed fetched signed out with its
block tags and the members reading's audio from an address with their key
(with byte ranges), Make a new address ending the old one with 410, and Turn
off on the owner's list ending the new one.

Usage: MB_SEED=<json from tests/wp/members.php> tests/browser/members.py http://localhost:8080

PHP's test server does not read .htaccess, so the folder's refusal of direct
requests is checked in tests/php/members.php from the file itself. It also
answers a missing path ending in a file extension (.wav, .vtt) with its own
404 instead of passing it to WordPress, as real servers do, so the checked
addresses are fetched here without their file name, which they answer the same.
"""
import json, os, re, sys
import xml.etree.ElementTree as ET
from playwright.sync_api import sync_playwright
from signin import sign_in

BASE = sys.argv[1].rstrip('/') if len(sys.argv) > 1 else 'http://localhost:8080'
CHROME = os.environ.get('CHROME_PATH', '/usr/bin/google-chrome')
SEED = json.loads(open(os.environ['MB_SEED']).read())
SCREEN = BASE + '/wp-admin/admin.php?page=rrt-podcast'
NS = {'itunes': 'http://www.itunes.com/dtds/podcast-1.0.dtd', 'podcast': 'https://podcastindex.org/namespace/1.0', 'atom': 'http://www.w3.org/2005/Atom'}

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


def bare(url):
	# The address without its file name (see above).
	return re.sub(r'/(file/[0-9]+)/[^/]*$', r'/\1', url or '')


def post_url(pid):
	return BASE + '/?p=%d' % pid


with sync_playwright() as p:
	browser = p.chromium.launch(executable_path=CHROME if os.path.exists(CHROME) else None)
	admin = browser.new_context(viewport={'width': 1280, 'height': 1000})
	out = browser.new_context()
	errors = []
	page = admin.new_page()
	page.on('pageerror', lambda e: errors.append(str(e)))
	sign_in(page, BASE)

	# The owner switches member feeds on. No role is ticked.
	page.goto(SCREEN)
	check(page.locator('#rrt-members').count() == 1, 'the Podcast feed screen has a Member feeds section')
	check(not page.is_checked('input[name=m_on]') and not page.is_checked('input[name=m_activity]'), 'member feeds and feed activity are off at first')
	page.check('input[name=m_on]')
	page.fill('#rrt-m-join', 'https://example.com/join/')
	with page.expect_navigation():
		page.click('#submit')
	check(page.is_checked('input[name=m_on]') and page.input_value('#rrt-m-join') == 'https://example.com/join/', 'switched on and saved')
	members = page.inner_text('table.rrt-members') if page.locator('table.rrt-members').count() else ''
	check('admin' in members.lower() and 'Friend Tester' not in members, 'only the administrator has a feed so far', members)

	# One friend is made a member on their profile.
	page.goto(BASE + '/wp-admin/user-edit.php?user_id=%d' % SEED['friend'])
	check(page.locator('#rrt_member').count() == 1, 'a person\'s profile has the Members feed choice')
	page.select_option('#rrt_member', 'always')
	with page.expect_navigation():
		page.click('#submit')
	check('They are a member now.' in page.inner_text('#your-profile'), 'Always makes them a member')
	page.goto(SCREEN)
	members = page.inner_text('table.rrt-members')
	check('Friend Tester' in members and 'Other Tester' not in members, 'the list shows the new member only', members)
	row = page.locator('table.rrt-members tr', has_text='Friend Tester')
	check(re.search(r'/podcast-member/[A-Za-z0-9]{32}/$', row.locator('.rrt-mfeed-copy-row').get_attribute('data-url') or ''), 'the list has Copy their feed address for each member')

	# The post's box says who can hear it.
	page.goto(BASE + '/wp-admin/post.php?post=%d&action=edit' % SEED['closed'], wait_until='load')
	page.wait_for_selector('#rrt_access', state='attached', timeout=30000)
	check(page.eval_on_selector('#rrt_access', 'e => e.value') == 'members', 'the Reading audio box shows Members only')
	box = page.eval_on_selector('#rrt-reading', 'e => e.textContent') if page.locator('#rrt-reading').count() else ''
	check('Now: only members can hear it.' in box, 'and says so', box[:200])

	# A visitor.
	v = out.new_page()
	v.goto(post_url(SEED['closed']))
	lock = v.locator('.rrt-player.rrt-locked')
	check(lock.count() == 1 and 'This reading is for members.' in lock.inner_text(), 'a visitor sees the members line', v.content()[:300] if not lock.count() else '')
	check(v.locator('.rrt-player audio').count() == 0 and SEED['closed_url'] not in v.content(), 'and no audio or file address')
	links = lock.inner_text() if lock.count() else ''
	check('Sign in' in links and 'Become a member' in links, 'with Sign in and Become a member', links)
	r = out.request.get(bare(SEED['closed_url']))
	check(r.status == 401, 'the checked address refuses a visitor', r.status)
	r = out.request.get(SEED['closed_old'])
	check(r.status == 404, 'nothing is left at the old public address', r.status)
	v.goto(post_url(SEED['open']))
	check(v.locator('.rrt-player audio').count() == 1, 'a reading for everyone still plays for a visitor')

	# Someone signed in who is not a member.
	oc = browser.new_context()
	op = oc.new_page()
	sign_in(op, BASE, 'mbother', 'mbother')
	op.goto(post_url(SEED['closed']))
	lock = op.locator('.rrt-player.rrt-locked')
	check(lock.count() == 1 and 'Sign in' not in lock.inner_text(), 'someone who is not a member sees the line without Sign in')
	r = oc.request.get(bare(SEED['closed_url']))
	check(r.status == 403, 'and the audio refuses them', r.status)
	op.goto(BASE + '/wp-admin/profile.php')
	check('not a member' in op.inner_text('#your-profile'), 'their profile says they have no members feed')
	oc.close()

	# The member.
	fc = browser.new_context()
	fp = fc.new_page()
	sign_in(fp, BASE, 'mbfriend', 'mbfriend')
	fp.goto(post_url(SEED['closed']))
	audio = fp.locator('.rrt-player audio')
	check(audio.count() == 1 and fp.locator('.rrt-locked').count() == 0, 'the member gets the player')
	src = audio.get_attribute('src') if audio.count() else ''
	check(src == SEED['closed_url'], 'playing from the checked address', src)
	r = fc.request.get(bare(src))
	body = r.body() if r.status == 200 else b''
	check(r.status == 200 and body[:4] == b'RIFF', 'which sends the member the reading', r.status)
	r = fc.request.get(bare(src), headers={'Range': 'bytes=0-99'})
	check(r.status == 206 and len(r.body()) == 100 and r.headers.get('content-range', '').startswith('bytes 0-99/'), 'with byte ranges, so the player can seek', r.status)
	check('no-store' in (r.headers.get('cache-control') or ''), 'and never kept by a shared cache', r.headers.get('cache-control'))
	ok = fp.evaluate("""(u) => new Promise(res => { const a = document.querySelector('.rrt-player audio'); a.src = u; a.muted = true; a.play().then(() => setTimeout(() => res(a.currentTime > 0), 700)).catch(e => res(String(e))); })""", bare(src))
	check(ok is True, 'and it really plays in the page', ok)
	fp.goto(BASE + '/wp-admin/profile.php')
	feed = fp.input_value('.rrt-mfeed-url') if fp.locator('.rrt-mfeed-url').count() else ''
	check(re.search(r'/podcast-member/[A-Za-z0-9]{32}/$', feed), 'the member finds their feed address on their profile', feed)
	check('Spotify cannot take private feeds' in fp.inner_text('#your-profile') and fp.locator('a[href^="overcast://"]').count() == 1, 'with the app steps and Open in Overcast')

	# The member's feed, as a podcast app fetches it: no cookies.
	r = out.request.get(feed)
	check(r.status == 200 and 'rss' in (r.headers.get('content-type') or ''), 'the feed answers an app with no sign-in', r.status)
	x = r.text() if r.status == 200 else ''
	root = ET.fromstring(x) if x else None
	ch = root.find('channel') if root is not None else None
	titles = [i.findtext('title') for i in ch.findall('item')] if ch is not None else []
	check('Member feed closed test' in titles and 'Member feed open test' in titles, 'it holds the members reading and the open one', titles)
	check(ch is not None and ch.findtext('itunes:block', namespaces=NS) == 'Yes' and ch.findtext('podcast:block', namespaces=NS) == 'yes', 'with both block tags')
	check('Friend Tester' not in x and 'mbfriend' not in x, 'and nothing naming the member')
	enc = ''
	if ch is not None:
		for i in ch.findall('item'):
			if i.findtext('title') == 'Member feed closed test':
				enc = i.find('enclosure').get('url')
	key = feed.split('/podcast-member/')[1].split('/')[0] if feed else 'x'
	check(('/podcast-member/%s/file/' % key) in enc, 'the members reading comes from an address with the member\'s key', enc)
	r = out.request.get(bare(enc))
	check(r.status == 200 and r.body()[:4] == b'RIFF', 'which an app can download with no sign-in', r.status)
	r = out.request.get(bare(enc), headers={'Range': 'bytes=10-19'})
	check(r.status == 206 and len(r.body()) == 10, 'in parts too', r.status)
	cap = ''
	if ch is not None:
		for i in ch.findall('item'):
			if i.findtext('title') == 'Member feed closed test':
				t = i.find('podcast:transcript', NS)
				cap = t.get('url') if t is not None else ''
	r = out.request.get(bare(cap)) if cap else None
	check(r is not None and r.status == 200 and 'WEBVTT' in r.text(), 'its captions too', cap)

	# A new address.
	fp.goto(BASE + '/wp-admin/profile.php')
	fp.check('input[name=rrt_mfeed_reset]')
	with fp.expect_navigation():
		fp.click('#submit')
	feed2 = fp.input_value('.rrt-mfeed-url')
	check(feed2 and feed2 != feed, 'Make a new address gives a new one', feed2)
	r = out.request.get(feed)
	check(r.status == 410, 'the old address answers 410 Gone', r.status)
	r = out.request.get(bare(enc))
	check(r.status == 410, 'and so does its audio', r.status)
	r = out.request.get(feed2)
	check(r.status == 200, 'the new one works', r.status)

	# The owner turns the member off.
	page.goto(SCREEN)
	row = page.locator('table.rrt-members tr', has_text='Friend Tester')
	with page.expect_navigation():
		row.locator('button', has_text='Turn off').click()
	check('no longer has a members feed' in page.inner_text('.wrap'), 'Turn off says so')
	r = out.request.get(feed2)
	check(r.status == 410, 'and their address stops', r.status)
	fp.goto(post_url(SEED['closed']))
	check(fp.locator('.rrt-player.rrt-locked').count() == 1, 'and the reading is closed to them again')
	fc.close()

	# Switched off again for the next tests.
	page.goto(SCREEN)
	page.uncheck('input[name=m_on]')
	with page.expect_navigation():
		page.click('#submit')
	r = out.request.get(feed2)
	check(r.status == 404, 'with member feeds off, member addresses answer 404', r.status)
	check(not errors, 'no script errors', errors)
	browser.close()

print('\n%d passed, %d failed' % (passed, len(failed)))
sys.exit(1 if failed else 0)
