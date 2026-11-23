"""Answers byte-range requests for uploaded audio, as a real web server does (php -S cannot)."""
import re, urllib.parse
ROOT = '/tmp/foxfire-wp/wordpress'
def serve(route):
	try:
		_serve(route)
	except Exception as e:
		print('RANGE ERR', route.request.method, route.request.headers.get('range'), repr(e))
		route.continue_()
def _serve(route):
	req = route.request
	path = urllib.parse.urlparse(req.url).path
	data = open(ROOT + urllib.parse.unquote(path), 'rb').read()
	n = len(data)
	rng = req.headers.get('range')
	ctype = 'audio/mpeg' if path.endswith('.mp3') else 'text/vtt'
	if rng:
		m = re.match(r'bytes=(\d*)-(\d*)', rng)
		a = int(m.group(1) or 0); b = int(m.group(2)) if m.group(2) else n - 1
		b = min(b, n - 1)
		route.fulfill(status=206, body=data[a:b + 1], headers={'Content-Type': ctype, 'Accept-Ranges': 'bytes', 'Content-Range': 'bytes %d-%d/%d' % (a, b, n), 'Content-Length': str(b - a + 1)})
	else:
		route.fulfill(status=200, body=data, headers={'Content-Type': ctype, 'Accept-Ranges': 'bytes', 'Content-Length': str(n)})
def install(ctx):
	ctx.route(re.compile(r'.*/wp-content/uploads/.*\.mp3(\?.*)?$'), serve)
