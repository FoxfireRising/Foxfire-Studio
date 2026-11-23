"""
The MP3 encoder and noise model for the browser tests (1.3.0, from take.py):
the real files from npm, fetched once with npm pack and checked against the
plugin's own fingerprints, so the studio can be served them.
"""
import hashlib, os, re, subprocess, sys

HERE = os.path.dirname(os.path.abspath(__file__))
PLUGIN = os.path.join(HERE, '..', '..', 'plugin', 'foxfire-studio')
NPM = os.environ.get('NPM_CACHE', '/tmp/foxfire-npm')


def pinned(name):
	src = open(os.path.join(PLUGIN, 'includes', 'local.php'), encoding='utf8').read()
	return re.search(r"define\( '%s', '([0-9a-f]+)' \)" % name, src).group(1)


def whisper_pinned(key):
	"""A fingerprint from rrt_whisper_hashes() in includes/whisper.php, by where the file goes."""
	src = open(os.path.join(PLUGIN, 'includes', 'whisper.php'), encoding='utf8').read()
	return re.search(r"'%s' => '([0-9a-f]{64})'" % re.escape(key), src).group(1)


def npm_file(pkg, ver, inner, sha_name, sha=None):
	"""A file from an npm package, fetched once with npm pack and checked (against sha_name in local.php, or sha)."""
	os.makedirs(NPM, exist_ok=True)
	folder = os.path.join(NPM, (pkg + '-' + ver).replace('/', '-').lstrip('@'))
	path = os.path.join(folder, 'package', inner)
	if not os.path.exists(path):
		tgz = subprocess.check_output(['npm', 'pack', '--silent', pkg + '@' + ver], cwd=NPM).decode().strip().splitlines()[-1]
		os.makedirs(folder, exist_ok=True)
		subprocess.check_call(['tar', 'xzf', os.path.join(NPM, tgz), '-C', folder])
	data = open(path, 'rb').read()
	if hashlib.sha256(data).hexdigest() != (sha or pinned(sha_name)):
		sys.exit('%s does not match %s' % (path, sha_name))
	return data


def version(name, where='local.php'):
	src = open(os.path.join(PLUGIN, 'includes', where), encoding='utf8').read()
	return re.search(r"define\( '%s', '([^']+)' \)" % name, src).group(1)


def ort_runtime():
	"""ONNX Runtime Web's two runtime files (1.16.0, for the stronger cleanup), as the site's Whisper copy has them."""
	ver = version('RRT_ORT_VERSION', 'whisper.php')
	glue = npm_file('onnxruntime-web', ver, 'dist/ort-wasm-simd-threaded.mjs', None, whisper_pinned('lib/ort-wasm-simd-threaded.js'))
	wasm = npm_file('onnxruntime-web', ver, 'dist/ort-wasm-simd-threaded.wasm', None, whisper_pinned('lib/ort-wasm-simd-threaded.wasm'))
	return glue, wasm


MP3 = npm_file('wasm-media-encoders', version('RRT_MP3_VERSION'), 'wasm/mp3.wasm', 'RRT_MP3_SHA256')
RNN = npm_file('@jitsi/rnnoise-wasm', version('RRT_RNNOISE_VERSION'), 'dist/rnnoise.wasm', 'RRT_RNNOISE_SHA256')
