"""
Checks the test translation from tests/wp/pseudo.py reaches every part of
Foxfire Studio: screens printed by PHP, words set by scripts, the scripts the
studio loads later, and the public player. Every translated string is wrapped
in ⟦ ⟧, so any English left in a screen shows up in the report at the end.

Run with the site's language set to "eo":
  python3 tests/browser/translated.py [http://localhost:8080]
"""
import os, re, sys
from playwright.sync_api import sync_playwright
from signin import sign_in

BASE = (sys.argv[1] if len(sys.argv) > 1 else 'http://localhost:8080').rstrip('/')
CHROME = os.environ.get('CHROME_PATH', '/usr/bin/google-chrome')
PHP_ERR = re.compile(r'<b>(Fatal error|Warning|Notice|Deprecated)</b>|PHP (Fatal|Warning|Notice|Deprecated)')
passed, failed, english = 0, [], []

def check(ok, name, detail=''):
	global passed
	if ok:
		passed += 1
		print('ok   ' + name)
	else:
		failed.append(name)
		print('FAIL ' + name + (': ' + detail if detail else ''))

UNTRANSLATED = """() => {
	const root = document.querySelector('#wpbody-content .wrap') || document.querySelector('#wpbody-content') || document.body;
	const out = [];
	const walk = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
	let n;
	while ((n = walk.nextNode())) {
		const p = n.parentElement;
		if (!p || p.closest('script,style,code,textarea,select option[value=""],.rrt-tc,.rrt-look-preview,.tp-block,.tp-rv-script,#tpRvScript')) { continue; }
		if (p.closest('[hidden]')) { continue; }
		const t = n.textContent.trim();
		if (/[A-Za-z]{2,}\\s+[a-z]{2,}/.test(t) && t.indexOf('⟦') === -1 && t.indexOf('⟧') === -1 && !/^(IP Geolocation by DB-IP|Hello world!)$/.test(t)) { out.push(t.slice(0, 90)); }
	}
	return out;
}"""

with sync_playwright() as p:
	browser = p.chromium.launch(executable_path=CHROME if os.path.exists(CHROME) else None)
	page = browser.new_page()
	errors = []
	page.on('pageerror', lambda e: errors.append(str(e)))
	page.add_init_script("try { sessionStorage.setItem('rrtIntroSeen', '1'); } catch (e) {}")

	page.goto(BASE + '/?rest_route=/wp/v2/posts&search=The%20Test%20Reading')
	post = page.evaluate('JSON.parse(document.body.innerText)[0]')

	sign_in(page, BASE)

	screens = ['admin.php?page=rrt-dashboard', 'admin.php?page=rrt-readings', 'admin.php?page=rrt-backlog', 'admin.php?page=rrt-sources', 'admin.php?page=rrt-progress',
		'admin.php?page=rrt-insights', 'admin.php?page=rrt-recordings', 'admin.php?page=rrt-reports', 'admin.php?page=rrt-podcast',
		'admin.php?page=rrt-shares', 'options-general.php?page=rrt-settings']
	for s in screens:
		del errors[:]
		page.goto(BASE + '/wp-admin/' + s)
		page.wait_for_timeout(300)
		html = page.content()
		m = PHP_ERR.search(html)
		check(m is None, s + ' has no PHP notices in the translated site', html[max(0, m.start() - 100):m.end() + 300] if m else '')
		check('⟦' in page.inner_text('#wpbody-content'), s + ' is translated')
		check(not errors, s + ' has no script errors', '; '.join(errors))
		english += [s + ': ' + t for t in page.evaluate(UNTRANSLATED)]

	del errors[:]
	page.goto(BASE + '/?teleprompter=1&tp_post=%d' % post['id'])
	page.wait_for_timeout(800)
	html = page.content()
	check(PHP_ERR.search(html) is None, 'the teleprompter has no PHP notices')
	check(not errors, 'the teleprompter has no script errors', '; '.join(errors))
	check(page.evaluate("wp.i18n.__('Begin', 'foxfire-studio')") == '⟦Begin⟧', 'the studio script has its translations')
	# Each engine file brings its own translations (0.25.1): a word only that file uses.
	# And so does each part of the studio (0.25.2).
	for js, word in (('readout.js', 'a comfortable listening pace.'), ('compare.js', 'Paraphrased'),
			('studio-core.js', 'That URL does not match any post here.'), ('record.js', 'Discard for good'),
			('cues.js', 'Pause here'), ('voice.js', 'Voice following fell behind on this device, so the timed pace has taken over.'),
			('master.js', 'The MP3 encoder could not be loaded.'), ('review.js', 'This browser cannot run background workers.'),
			('editor.js', 'Edited MP3 is up to date')):
		check(page.evaluate("w => wp.i18n.__(w, 'foxfire-studio')", word) == '⟦' + word + '⟧', js + ' has its translations')
	check(page.evaluate("wp.i18n.__('2. Shapes', 'foxfire-studio')") == '⟦2. Shapes⟧', 'the clip maker has its translations before it loads')
	check(page.evaluate("wp.i18n.__('The MP3 encoder could not start.', 'foxfire-studio')") == '⟦The MP3 encoder could not start.⟧', 'the MP3 encoder has its translations before it loads')
	check('⟦' in page.inner_text('#tpState'), 'a word the studio sets by script is translated', page.inner_text('#tpState'))
	english += ['teleprompter: ' + t for t in page.evaluate(UNTRANSLATED)]

	page.goto(BASE + '/?rrt_share=list')
	check('⟦' in page.inner_text('body'), 'the share list is translated')

	ctx = browser.new_context()
	pub = ctx.new_page()
	pub.goto(post['link'])
	label = pub.get_attribute('.rrt-play', 'aria-label') if pub.query_selector('.rrt-play') else None
	check(label is None or label.startswith('⟦'), 'the public player is translated', str(label))
	browser.close()

print('\n%d passed, %d failed' % (passed, len(failed)))
if english:
	print('\nEnglish still showing (%d):' % len(english))
	for t in english:
		print('  ' + t)
sys.exit(1 if failed else 0)
