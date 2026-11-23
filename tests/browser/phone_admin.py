#!/usr/bin/env python3
"""
The plugin's screens at an iPhone's width (1.33.0).

Safari lets a whole page pan sideways as far as its widest piece, so a wide
table or a long menu makes the page slide under a finger. At 393 px every
plugin screen must fit the screen: a wide table scrolls inside its own box,
and the page itself is never wider than the window.

Usage: tests/browser/phone_admin.py http://localhost:8080
PHONE_SHOTS=<folder> also saves a screenshot of each screen that does not fit.
"""
import os, sys
from playwright.sync_api import sync_playwright
from signin import sign_in

BASE = (sys.argv[1] if len(sys.argv) > 1 else 'http://localhost:8080').rstrip('/')
CHROME = os.environ.get('CHROME_PATH', '/usr/bin/google-chrome')
SHOTS = os.environ.get('PHONE_SHOTS', '')

SCREENS = [
	('Dashboard', 'admin.php?page=rrt-dashboard'),
	('Readings', 'admin.php?page=rrt-readings'),
	('Readings to make', 'admin.php?page=rrt-backlog'),
	('Pronunciation', 'admin.php?page=rrt-pronounce'),
	('Sources', 'admin.php?page=rrt-sources'),
	('Your progress', 'admin.php?page=rrt-progress'),
	('Insights', 'admin.php?page=rrt-insights'),
	('Recordings', 'admin.php?page=rrt-recordings'),
	('Reports', 'admin.php?page=rrt-reports'),
	('Settings', 'options-general.php?page=rrt-settings'),
	('Shares', 'admin.php?page=rrt-shares'),
	('Podcast feed', 'admin.php?page=rrt-podcast'),
	('Help', 'admin.php?page=rrt-help'),
	('Set up', 'admin.php?page=rrt-setup&step=privacy'),
	('Collections', 'edit.php?post_type=rrt_collection'),
	('Profile', 'profile.php'),
]

# What sticks out past the window, outermost pieces only, for the failure message.
WIDE = '''() => {
	var vw = document.documentElement.clientWidth, out = [];
	document.querySelectorAll('#wpbody-content *').forEach(function (e) {
		var r = e.getBoundingClientRect();
		if (!r.width || !r.height || r.right <= vw + 1) { return; }
		var p = e.parentElement.getBoundingClientRect();
		if (p.right > vw + 1) { return; }
		for (var q = e.parentElement; q && q !== document.body; q = q.parentElement) {
			if (getComputedStyle(q).overflowX !== 'visible') { return; }
		}
		out.push(e.tagName.toLowerCase() + (e.id ? '#' + e.id : '') + (typeof e.className === 'string' && e.className ? '.' + e.className.trim().split(/\\s+/).slice(0, 2).join('.') : '') + ' to ' + Math.round(r.right));
	});
	return { doc: document.documentElement.scrollWidth, vw: vw, out: out.slice(0, 4) };
}'''

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


with sync_playwright() as p:
	browser = p.chromium.launch(executable_path=CHROME if os.path.exists(CHROME) else None)
	ctx = browser.new_context(viewport={'width': 393, 'height': 852}, is_mobile=True, has_touch=True)
	errors = []
	page = ctx.new_page()
	page.on('pageerror', lambda e: errors.append(str(e)))
	sign_in(page, BASE)
	tables = 0
	for name, path in SCREENS:
		page.goto(BASE + '/wp-admin/' + path, wait_until='load')
		page.wait_for_timeout(500)
		r = page.evaluate(WIDE)
		ok = r['doc'] <= r['vw'] + 1
		if not ok and SHOTS:
			page.screenshot(path=os.path.join(SHOTS, 'phone-' + path.split('=')[-1].replace('&', '-') + '.png'))
		check(ok, name + ' fits an iPhone\'s width without sliding sideways', r)
		tables += page.evaluate("document.querySelectorAll('.wrap .rrt-table').length")
	check(tables > 0, 'some of these screens have the plugin\'s tables, so the check means something')
	# A wide table keeps its columns and scrolls inside its own box.
	page.goto(BASE + '/wp-admin/admin.php?page=rrt-readings', wait_until='load')
	box = page.evaluate("() => { var t = document.querySelector('.wrap .rrt-table'); return t ? [getComputedStyle(t).overflowX, t.scrollWidth >= t.clientWidth, t.querySelectorAll('th').length] : null; }")
	check(box is not None and box[0] == 'auto' and box[1] and box[2] > 2, 'a wide table scrolls inside its own box and keeps its columns', box)
	# Wider than a phone, nothing changes.
	page.set_viewport_size({'width': 1280, 'height': 900})
	page.reload(wait_until='load')
	disp = page.evaluate("() => { var t = document.querySelector('.wrap .rrt-table'); return t ? getComputedStyle(t).display : ''; }")
	check(disp == 'table', 'on a computer the tables are ordinary tables', disp)
	browser.close()
	check(not errors, 'no script errors', '; '.join(errors))

print('\n%d passed, %d failed' % (passed, len(failed)))
sys.exit(1 if failed else 0)
