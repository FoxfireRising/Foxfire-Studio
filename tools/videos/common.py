"""Shared setup for the docs videos: the test site, signed in, with the tests' stand-ins."""
import json, os, re, sys
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', 'tests', 'browser'))
from signin import sign_in
from take_assets import MP3, RNN
from whisper_stub import PIECE

BASE = 'http://localhost:8080'
CHROME = '/usr/bin/google-chrome'
POST = int(os.environ.get('VID_POST', '5'))
TP = BASE + '/?teleprompter=1&tp_post=%d' % POST


def stub_js(chunks):
	return ('export const env = { backends: { onnx: { wasm: {} } } };\n' + PIECE +
		'const CHUNKS = %s;\n'
		'export async function pipeline() {\n'
		'  return async function (audio) { return piece(audio, { chunks: CHUNKS, text: CHUNKS.map(x => x.text).join("") }); };\n'
		'}\n' % json.dumps(chunks))


def launch(p, mic=None, size=(1280, 620), whisper_js=None, scale=1):
	args = ['--autoplay-policy=no-user-gesture-required', '--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream', '--hide-scrollbars', '--font-render-hinting=none']
	if mic:
		args.append('--use-file-for-fake-audio-capture=' + mic)
	browser = p.chromium.launch(executable_path=CHROME, args=args)
	ctx = browser.new_context(permissions=['microphone'], viewport={'width': size[0], 'height': size[1]}, device_scale_factor=scale)
	box = {'js': whisper_js or ''}
	ctx.route(re.compile(r'.*transformers(\.min)?\.js.*'), lambda r: r.fulfill(status=200, content_type='text/javascript', body=box['js'], headers={'Access-Control-Allow-Origin': '*'}))
	ctx.route(re.compile(r'.*/mp3\.wasm.*'), lambda r: r.fulfill(status=200, content_type='application/wasm', body=MP3, headers={'Access-Control-Allow-Origin': '*'}))
	ctx.route(re.compile(r'.*/rnnoise\.wasm.*'), lambda r: r.fulfill(status=200, content_type='application/wasm', body=RNN, headers={'Access-Control-Allow-Origin': '*'}))
	page = ctx.new_page()
	sign_in(page, BASE)
	page.goto(BASE + '/wp-admin/', wait_until='load')
	page.evaluate("try { sessionStorage.setItem('rrtIntroSeen', '1'); localStorage.setItem('rrtIntro', 'off'); localStorage.setItem('rrtWelcomed', '1'); localStorage.setItem('rrtTookOne', '1'); } catch (e) {}")
	return browser, ctx, page, box
