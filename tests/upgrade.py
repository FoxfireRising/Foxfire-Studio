"""
Upgrades from older releases (0.26.0).

For each release that changed the database or the plugin's folder, a test
site of its own gets that release, settings saved as a site owner would,
a made-up row in every table and the post and person fields the plugin
keeps. Then it is upgraded to the build in this repo, the way a site owner
would do it:

- releases in the old folder (reading-room-teleprompter, up to 0.11.0):
  Foxfire Studio is added and switched on, which switches the old copy
  off, and the old copy is then deleted from the Plugins screen (its
  uninstall must not touch the shared data);
- later releases: the folder is replaced while the plugin is active, as
  Upload Plugin, Replace current with uploaded does.

Checked after each: the version and database version, every table and
column against a fresh install, the rows, every saved setting, the post
and person fields, every Foxfire Studio screen in Chrome with no PHP
errors, and the studio loading a post.

The older zips come from the releases of the repository this runs in, fetched
with the GitHub CLI (gh) during the run into a cache folder and never
kept in the repo. A release that cannot be fetched is skipped, and with
none at all the test says so and passes (the public copy has no releases).

Usage: python3 tests/upgrade.py [release ...]     e.g. tests/upgrade.py 0.9.0
Needs: php, gh (signed in, or GH_TOKEN), unzip, Playwright with Chrome.
"""
import json
import os
import re
import shutil
import subprocess
import sys
import time

REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PLUGIN = os.path.join(REPO, 'plugin', 'foxfire-studio')
DIR = os.environ.get('WP_UP_DIR', '/tmp/foxfire-up')
PORT = int(os.environ.get('WP_UP_PORT', '8092'))
BASE = 'http://localhost:%d' % PORT
CACHE = os.environ.get('FOXFIRE_ZIPS', '/tmp/foxfire-zips')


def source_repo():
	"""Where the releases are: FOXFIRE_RELEASES, else the repository this runs in."""
	if os.environ.get('FOXFIRE_RELEASES'):
		return os.environ['FOXFIRE_RELEASES']
	if os.environ.get('GITHUB_REPOSITORY'):
		return os.environ['GITHUB_REPOSITORY']
	r = subprocess.run(['git', '-C', REPO, 'remote', 'get-url', 'origin'], capture_output=True, text=True)
	m = re.search(r'github\.com[:/]([^/]+/[^/.]+?)(?:\.git)?/?$', r.stdout.strip())
	return m.group(1) if m else ''


SOURCE = source_repo()
CHROME = os.environ.get('CHROME_PATH', '/usr/bin/google-chrome')
WPROOT = os.path.join(DIR, 'wordpress')
PLUGINS = os.path.join(WPROOT, 'wp-content', 'plugins')
OLD = 'reading-room-teleprompter'

# Every release that changed the database or the plugin's folder, 1.5.0, the last before the finishing presets changed how the sound settings are saved (1.6.0), 1.6.0, the release clip suggestions (1.7.0) upgrade from, 1.7.0, the release Keep a reading aside (1.8.0) upgrades from, 1.8.0, the release Phone level (1.9.0) upgrades from, and 1.24.0 (database version 8), the release 1.25.0 upgrades from (it moves members' files into folders of their own).
RELEASES = ['0.3.0', '0.3.2', '0.9.0', '0.10.0', '0.11.0', '0.11.1', '0.12.0', '0.14.0', '0.17.0', '1.5.0', '1.6.0', '1.7.0', '1.8.0', '1.24.0']

SCREENS = ['rrt-dashboard', 'rrt-readings', 'rrt-sources', 'rrt-progress', 'rrt-insights', 'rrt-recordings', 'rrt-reports', 'rrt-podcast', 'rrt-shares']
PHP_ERR = re.compile(r'<b>(Fatal error|Parse error|Warning|Notice|Deprecated)</b>')

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


def wp(*args, ok=True):
	r = subprocess.run(['php', os.path.join(DIR, 'wp-cli.phar'), '--path=' + WPROOT, '--allow-root'] + list(args), capture_output=True, text=True)
	if ok and r.returncode != 0:
		print(r.stdout + r.stderr)
		raise SystemExit('wp ' + ' '.join(args) + ' failed')
	return r


def fetch(ver):
	"""The release's zip, from the cache or GitHub; None if it cannot be had."""
	os.makedirs(CACHE, exist_ok=True)
	dest = os.path.join(CACHE, ver + '.zip')
	if os.path.exists(dest) and os.path.getsize(dest) > 0:
		return dest
	r = subprocess.run(['gh', 'api', 'repos/%s/releases/tags/v%s' % (SOURCE, ver), '--jq', '.assets[0].id'], capture_output=True, text=True)
	if r.returncode != 0 or not r.stdout.strip():
		return None
	with open(dest + '.part', 'wb') as f:
		r = subprocess.run(['gh', 'api', '-H', 'Accept: application/octet-stream', 'repos/%s/releases/assets/%s' % (SOURCE, r.stdout.strip())], stdout=f)
	if r.returncode != 0:
		return None
	os.replace(dest + '.part', dest)
	return dest


def reset():
	"""An empty site: no plugin of ours, no tables, no uploads of ours, a clean log."""
	for d in ('foxfire-studio', OLD):
		p = os.path.join(PLUGINS, d)
		if os.path.islink(p):
			os.unlink(p)
		elif os.path.isdir(p):
			shutil.rmtree(p)
	dbdir = os.path.join(WPROOT, 'wp-content', 'database')
	for name in os.listdir(dbdir):
		p = os.path.join(dbdir, name)
		if name.startswith('.ht.') and os.path.isdir(p):
			for f in os.listdir(p):
				os.remove(os.path.join(p, f))
	up = os.path.join(WPROOT, 'wp-content', 'uploads')
	if os.path.isdir(up):
		shutil.rmtree(up)
	log = os.path.join(WPROOT, 'wp-content', 'debug.log')
	if os.path.exists(log):
		os.remove(log)
	wp('core', 'install', '--url=' + BASE, '--title=Upgrade test', '--admin_user=admin', '--admin_password=admin', '--admin_email=admin@example.test', '--skip-email', '--quiet')
	wp('option', 'update', 'siteurl', BASE, '--quiet')
	wp('option', 'update', 'home', BASE, '--quiet')
	wp('user', 'create', 'editor', 'editor@example.test', '--role=editor', '--user_pass=editor', '--quiet')
	wp('post', 'create', '--post_title=The Test Reading', '--post_status=publish', '--post_content=<p>The fox ran over the hill, under the moon.</p><h2>Second part</h2><p>Owls called across the river, and the night grew still.</p>', '--quiet')


def install_new():
	shutil.copytree(PLUGIN, os.path.join(PLUGINS, 'foxfire-studio'))


def log_errors():
	log = os.path.join(WPROOT, 'wp-content', 'debug.log')
	if not os.path.exists(log):
		return []
	return [l.strip() for l in open(log, errors='replace') if re.search(r'PHP (Fatal|Warning|Notice|Deprecated)', l) and 'foxfire-studio' in l]


def browser(ver, post_id):
	from playwright.sync_api import sync_playwright
	with sync_playwright() as p:
		b = p.chromium.launch(executable_path=CHROME, args=['--no-sandbox'])
		page = b.new_page()
		errors = []
		page.on('pageerror', lambda e: errors.append(str(e)))
		# Sign in, waiting only for the answer to the form (not for the dashboard to finish
		# loading), and try once more if it never comes: on a fresh site WordPress's first
		# scheduled jobs can hold up that first request.
		for attempt in range(2):
			page.goto(BASE + '/wp-login.php', wait_until='domcontentloaded')
			page.fill('#user_login', 'admin')
			page.fill('#user_pass', 'admin')
			try:
				with page.expect_navigation(wait_until='commit', timeout=60000):
					page.click('#wp-submit')
				break
			except Exception:
				if attempt:
					raise
		if '/wp-login.php' in page.url:
			check(False, '[%s] signs in' % ver)
			b.close()
			return
		bad = []
		for s in SCREENS + ['rrt-settings']:
			r = page.goto(BASE + '/wp-admin/admin.php?page=' + s)
			html = page.content()
			html = re.sub(r'wpseoAdminGlobalL10n[\s\S]*?</script>', '', html)
			if not r or r.status != 200 or PHP_ERR.search(html):
				bad.append('%s %s' % (s, r.status if r else '?'))
		check(not bad, '[%s] every Foxfire Studio screen loads with no PHP errors' % ver, ', '.join(bad))
		page.goto(BASE + '/wp-admin/plugins.php')
		check(OLD not in page.content() or 'reading-room-teleprompter/reading-room-teleprompter.php' not in page.content(), '[%s] the old copy is gone from the Plugins screen' % ver)
		# The setup wizard (1.29.0) is never offered to a site that had the plugin before.
		check(page.locator('.rrt-setup-notice').count() == 0, '[%s] an upgraded site is not offered the setup wizard' % ver)
		page.goto(BASE + '/?teleprompter=1&tp_post=%d' % post_id)
		try:
			page.wait_for_function("document.querySelectorAll('span[data-i]').length > 0", timeout=20000)
			words = page.evaluate("document.querySelectorAll('span[data-i]').length")
		except Exception:
			words = 0
		check(words > 10, '[%s] the studio opens the post with its words' % ver, str(words))
		check(page.evaluate("typeof window.RRTStudio === 'object'"), '[%s] the studio starts' % ver)
		check(not errors, '[%s] no script errors' % ver, '; '.join(errors))
		b.close()


def main():
	wanted = sys.argv[1:] or RELEASES
	zips = {}
	for v in wanted:
		z = fetch(v)
		if z:
			zips[v] = z
		else:
			print('skip %s: its release zip could not be fetched from %s' % (v, SOURCE))
	if not zips:
		print('No older releases could be fetched, so there is nothing to upgrade from. Skipped.')
		return 0

	subprocess.run([os.path.join(REPO, 'tests', 'wp', 'setup.sh'), DIR], env=dict(os.environ, WP_PORT=str(PORT)), check=True, capture_output=True)
	server = subprocess.Popen(['php', '-S', 'localhost:%d' % PORT, '-t', WPROOT], env=dict(os.environ, PHP_CLI_SERVER_WORKERS='4'), stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
	time.sleep(1)
	fresh = os.path.join(DIR, 'fresh.json')
	before = os.path.join(DIR, 'before.json')
	try:
		reset()
		install_new()
		wp('plugin', 'activate', 'foxfire-studio', '--quiet')
		print(wp('eval-file', os.path.join(REPO, 'tests', 'php', 'upgrade-check.php'), 'schema', fresh).stdout.strip())

		for v, z in zips.items():
			print('\n== From %s' % v)
			reset()
			subprocess.run(['unzip', '-qo', z, '-d', PLUGINS], check=True)
			folder = OLD if os.path.isdir(os.path.join(PLUGINS, OLD)) else 'foxfire-studio'
			wp('plugin', 'activate', folder, '--quiet')
			print(wp('eval-file', os.path.join(REPO, 'tests', 'php', 'upgrade-seed.php'), before).stdout.strip())
			post_id = json.load(open(before))['post']
			log = os.path.join(WPROOT, 'wp-content', 'debug.log')
			if os.path.exists(log):
				os.remove(log)
			if folder == OLD:
				install_new()
				wp('plugin', 'activate', 'foxfire-studio', '--quiet')
				wp('eval', 'echo 1;')
				status = wp('plugin', 'status', OLD, ok=False).stdout
				check('Inactive' in status, '[%s] switching Foxfire Studio on switches the old copy off' % v, status.strip()[:200])
				wp('plugin', 'uninstall', OLD, '--quiet')
				check(not os.path.isdir(os.path.join(PLUGINS, OLD)), '[%s] the old copy is deleted' % v)
			else:
				shutil.rmtree(os.path.join(PLUGINS, 'foxfire-studio'))
				install_new()
				wp('eval', 'echo 1;')
			r = wp('eval-file', os.path.join(REPO, 'tests', 'php', 'upgrade-check.php'), before, fresh, ok=False)
			for line in r.stdout.splitlines():
				if line.startswith('ok   '):
					check(True, line[5:])
				elif line.startswith('FAIL '):
					check(False, line[5:])
			if r.returncode != 0 and 'FAIL' not in r.stdout:
				check(False, '[%s] the upgrade check ran' % v, (r.stdout + r.stderr)[-600:])
			browser(v, post_id)
			errs = log_errors()
			check(not errs, '[%s] no PHP errors from the plugin in the log' % v, ' | '.join(errs[:3]))
	finally:
		server.terminate()

	print('\n%d passed, %d failed' % (passed, len(failed)))
	return 1 if failed else 0


if __name__ == '__main__':
	sys.exit(main())
