#!/usr/bin/env python3
"""
Clips and the full-length video in Firefox (1.30.0), local only.

Firefox has no AAC encoder on any system, and its H.264 encoder in High and
Main profile sends frames out of display order (B-frames). Since 1.30.0
Mediabunny writes the MP4 and the AAC add-on makes the sound, so Firefox
should give the same file as Chrome. On a reading's page, in Firefox:

- the browser check (RRTCan) says it can make clips and the video, with AAC
  from the add-on;
- a square clip made by the clip maker's own renderer, and the full-length
  video made with Make the video, each have every frame, H.264 High profile,
  AAC sound at 48 kHz, and no edit list on either track;
- no script errors.

Firefox comes from conda-forge (see CONTRIBUTING.md, Testing in other
browsers) and is driven by Selenium; this test is not run on GitHub.

Usage: FIREFOX=/path/to/firefox GECKODRIVER=/path/to/geckodriver \
  VD_SEED=<json from tests/wp/video.php> tests/browser/firefox.py http://localhost:8080
"""
import base64, json, os, sys, time
from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.firefox.options import Options
from selenium.webdriver.firefox.service import Service
from mp4box import find, tracks, edit_lists

BASE = sys.argv[1].rstrip('/') if len(sys.argv) > 1 else 'http://localhost:8080'
SEED = json.load(open(os.environ['VD_SEED']))
POST_ID = SEED['post']
DUR = SEED['duration']
PAGE = BASE + '/wp-admin/admin.php?page=rrt-readings&post=%d' % POST_ID

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


def wait(driver, js, timeout):
	end = time.time() + timeout
	while time.time() < end:
		if driver.execute_script('return ' + js):
			return True
		time.sleep(0.5)
	return False


def fetch(driver, expr):
	"""The bytes of a Blob the page holds (expr gives a promise of it)."""
	b64 = driver.execute_async_script("""
		var done = arguments[arguments.length - 1];
		Promise.resolve(%s).then(function (blob) {
			var r = new FileReader();
			r.onload = function () { done(String(r.result).split(',')[1] || ''); };
			r.onerror = function () { done(''); };
			r.readAsDataURL(blob);
		}, function (e) { done('ERR ' + e); });
	""" % expr)
	if not b64 or b64.startswith('ERR'):
		return b''
	return base64.b64decode(b64)


def judge(b, frames, what):
	T = tracks(b) if b else []
	v = [t for t in T if t['handler'] == 'vide']
	s = [t for t in T if t['handler'] == 'soun']
	check(len(v) == 1 and len(s) == 1, what + ': one picture track and one sound track', T)
	if v and s:
		check(abs(v[0]['frames'] - frames) <= 1, what + ': every frame is in the file', (v[0]['frames'], frames))
		check(v[0].get('profile') == 100, what + ': H.264 High profile', v[0].get('profile'))
		check(s[0]['entry'] == 'mp4a' and s[0]['rate'] == 48000, what + ': AAC sound at 48 kHz', s[0])
		check(edit_lists(b) == 0, what + ': no track has an edit list')
		# Firefox's High profile reorders frames: a ctts box shows Mediabunny stored them as they came.
		print('     %s: %s' % (what, 'frames reordered by the encoder (ctts)' if find(b, ['moov', 'trak', 'mdia', 'minf', 'stbl', 'ctts']) else 'frames in display order'))


opts = Options()
opts.add_argument('-headless')
if os.environ.get('FIREFOX'):
	opts.binary_location = os.environ['FIREFOX']
opts.set_preference('media.autoplay.default', 0)
service = Service(executable_path=os.environ['GECKODRIVER']) if os.environ.get('GECKODRIVER') else Service()
driver = webdriver.Firefox(options=opts, service=service)
driver.set_script_timeout(300)
try:
	driver.get(BASE + '/wp-login.php')
	time.sleep(0.5)
	driver.find_element(By.ID, 'user_login').send_keys('admin')
	driver.find_element(By.ID, 'user_pass').send_keys('admin')
	driver.find_element(By.ID, 'wp-submit').click()
	wait(driver, "location.pathname.indexOf('wp-login') < 0", 30)
	driver.get(PAGE)
	# Catch the made files, and any script error.
	driver.execute_script("""
		window.__errs = [];
		window.addEventListener('error', function (e) { window.__errs.push(String(e.message)); });
		window.__blobs = [];
		var make = URL.createObjectURL;
		URL.createObjectURL = function (b) { if (b && b.type === 'video/mp4' || (b && b.name && /\\.mp4$/.test(b.name))) { window.__blobs.push(b); } return make.call(URL, b); };
	""")
	check(wait(driver, "!!(window.RRTCan && window.RRTClip && window.RRTVideo && window.Mediabunny)", 30), 'the reading page loads the clip maker, the video, Mediabunny and the browser check')
	can = driver.execute_async_script("var d = arguments[arguments.length - 1]; RRTCan.check().then(function (a) { d({ clips: a.clips, video: a.video, aacOwn: a.aacOwn, line: RRTCan.line(a) }); });")
	check(can['clips'] and can['video'], 'Firefox can make clips and the video here', can)
	check(can['aacOwn'] is False and 'AAC add-on' in can['line'], 'with AAC from the add-on, as the This browser line says', can['line'])

	# A square clip, 4 s plus the end card, from the clip maker's own renderer.
	clip = driver.execute_async_script("""
		var done = arguments[arguments.length - 1];
		var boot = JSON.parse(document.getElementById('rrt-clip-boot').textContent);
		var o = { palette: boot.palette, fonts: boot.fonts, title: boot.title || 'Test', show: boot.show || 'Show', domain: 'example.test', endLine: 'Hear the whole reading at', len: 4 };
		var words = [{ t: 'Leaves', s: 0.3, e: 0.8 }, { t: 'fall', s: 0.9, e: 1.3 }, { t: 'slowly.', s: 1.4, e: 2.0 }, { t: 'Owls', s: 2.4, e: 2.9 }, { t: 'call.', s: 3.0, e: 3.6 }];
		var rate = 48000, pcm = new Float32Array(rate * 8);
		for (var i = 0; i < pcm.length; i++) { pcm[i] = 0.2 * Math.sin(i * 2 * Math.PI * 220 / rate) * (i < rate * 4 ? 1 : 0); }
		var st = { groups: RRTClip.groups(words, 0, 4), env: RRTClip.envelope(pcm, rate, 0, 7, 30), audio: pcm, image: null };
		var t0 = Date.now();
		RRTClip._render(o, 'square', st).then(function (r) {
			window.__clip = r.blob;
			done({ frames: r.frames, sound: r.sound, addOn: r.addOn, codec: r.codec, edits: r.edits, secs: (Date.now() - t0) / 1000 });
		}, function (e) { done({ error: String(e && e.message || e) }); });
	""")
	check('error' not in clip, 'the clip is made in Firefox', clip)
	if 'error' not in clip:
		check(clip['sound'] == 'aac' and clip['addOn'] is True and clip['codec'] == 'avc1.640028' and clip['edits'] == 0, 'the clip maker reports AAC from the add-on, High profile and no edit list', clip)
		judge(fetch(driver, 'window.__clip'), 7 * 30, 'the clip')

	# The full-length video, with Make the video.
	driver.execute_script("document.querySelector('.rrt-video-make').click();")
	made = wait(driver, "/ready/.test((document.querySelector('.rrt-video-status') || {}).textContent || '')", 300)
	check(made, 'the full video is made in Firefox', driver.execute_script("return (document.querySelector('.rrt-video-status') || {}).textContent"))
	if made:
		check(driver.execute_script("return document.querySelectorAll('.rrt-video-warn').length") == 0, 'no Opus warning')
		b = fetch(driver, 'window.__blobs[window.__blobs.length - 1]')
		judge(b, round((DUR + 3) * 30), 'the video')
	errs = driver.execute_script('return window.__errs')
	check(errs == [], 'no script errors', errs)
finally:
	driver.quit()

print('\nfirefox.py: %d passed, %d failed' % (passed, len(failed)))
sys.exit(1 if failed else 0)
