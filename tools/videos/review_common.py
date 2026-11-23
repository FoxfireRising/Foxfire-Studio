import os, shutil
from take2 import plan, wav_for, chunks
from common import stub_js
def open_take(page, box):
	words = page.evaluate("Array.prototype.map.call(document.querySelectorAll('.tp-w'), e => e.textContent)")
	heard, secs = plan(words)
	box['js'] = stub_js(chunks(heard))
	src = wav_for(heard, secs)
	d = '/tmp/vidtake'; os.makedirs(d, exist_ok=True)
	dst = os.path.join(d, 'keeper-take-1.wav'); shutil.copy(src, dst)
	return dst
