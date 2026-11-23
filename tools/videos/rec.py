"""Screen recording helpers for the docs videos: Chrome's screencast, frame by frame,
then ffmpeg makes a constant 30 fps H.264 with a caption band under the page."""
import base64, os, shutil, subprocess, time

W, H, BAND = 1280, 620, 100


class Recorder:
	def __init__(self, page, out_dir):
		self.page = page
		self.dir = out_dir
		shutil.rmtree(out_dir, ignore_errors=True)
		os.makedirs(out_dir)
		self.frames = []  # (wall time, path)
		self.cdp = page.context.new_cdp_session(page)
		self.cdp.on('Page.screencastFrame', self._frame)
		self.t0 = None
		self.cues = []  # (start, end, text)
		self._cue = None

	def _frame(self, ev):
		now = time.time()
		p = os.path.join(self.dir, '%06d.jpg' % len(self.frames))
		with open(p, 'wb') as f:
			f.write(base64.b64decode(ev['data']))
		self.frames.append((now, p))
		try:
			self.cdp.send('Page.screencastFrameAck', {'sessionId': ev['sessionId']})
		except Exception:
			pass

	def start(self):
		self.cdp.send('Page.startScreencast', {'format': 'jpeg', 'quality': 92, 'maxWidth': W, 'maxHeight': H, 'everyNthFrame': 1})
		self.t0 = time.time()

	def now(self):
		return time.time() - self.t0

	def say(self, text):
		"""Starts a caption now; it lasts until the next one."""
		t = self.now()
		if self._cue:
			self.cues.append((self._cue[0], t, self._cue[1]))
		self._cue = (t, text)

	def hold(self, secs):
		"""Waits while the screen keeps being captured (the screencast only sends changed frames)."""
		self.page.wait_for_timeout(int(secs * 1000))

	def stop(self):
		t = self.now()
		if self._cue:
			self.cues.append((self._cue[0], t, self._cue[1]))
			self._cue = None
		self.page.wait_for_timeout(300)
		self.cdp.send('Page.stopScreencast')
		self.t1 = time.time()


def _ass_time(s):
	h = int(s // 3600); m = int(s % 3600 // 60); sec = s % 60
	return '%d:%02d:%05.2f' % (h, m, sec)


def render(rec, out_mp4, poster=None, poster_at=None, cut_start=0.0, fast=()):
	"""Frames at their real times into a 30 fps video with the captions burned in below."""
	if not rec.frames:
		raise RuntimeError('no frames')
	lst = os.path.join(rec.dir, 'list.txt')
	def M(t):
		# Stretches listed in fast (start, end, factor) play faster.
		out = t
		for (a, b, f) in fast:
			o = max(0.0, min(t, b) - a)
			out -= o * (1 - 1.0 / f)
		return out
	fr = [(M(t - rec.t0), p) for (t, p) in rec.frames if t - rec.t0 >= cut_start - 0.05]
	end = M(rec.t1 - rec.t0)
	with open(lst, 'w') as f:
		for i, (t, p) in enumerate(fr):
			nxt = fr[i + 1][0] if i + 1 < len(fr) else end
			f.write("file '%s'\nduration %.4f\n" % (p, max(0.001, nxt - t)))
		f.write("file '%s'\n" % fr[-1][1])
	ass = os.path.join(rec.dir, 'cap.ass')
	with open(ass, 'w') as f:
		f.write('[Script Info]\nScriptType: v4.00+\nPlayResX: %d\nPlayResY: %d\nWrapStyle: 0\n\n' % (W, H + BAND))
		f.write('[V4+ Styles]\nFormat: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\n')
		# White on the band, centred in it (bottom alignment, margin from the bottom).
		f.write('Style: Cap,Inter,31,&H00FFFFFF,&H00FFFFFF,&H00000000,&H00000000,0,0,0,0,100,100,0,0,1,0,0,2,40,40,30,1\n\n')
		f.write('[Events]\nFormat: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n')
		for (a, b, text) in rec.cues:
			a, b = M(a), M(b)
			a = max(0.0, a - cut_start); b = max(0.0, b - cut_start)
			if b - a < 0.2:
				continue
			f.write('Dialogue: 0,%s,%s,Cap,,0,0,0,,%s\n' % (_ass_time(a), _ass_time(b), text.replace('\n', '\\N')))
	vf = ("scale=%d:%d:force_original_aspect_ratio=decrease,pad=%d:%d:0:0:color=0x14111c,"
		"pad=%d:%d:0:0:color=0x14111c,fps=30,subtitles=%s,format=yuv420p") % (W, H, W, H, W, H + BAND, ass)
	subprocess.run(['ffmpeg', '-y', '-loglevel', 'error', '-f', 'concat', '-safe', '0', '-i', lst,
		'-vf', vf, '-an', '-c:v', 'libx264', '-preset', 'slow', '-crf', '26', '-tune', 'stillimage',
		'-movflags', '+faststart', '-profile:v', 'high', out_mp4], check=True)
	if poster:
		at = poster_at if poster_at is not None else 1.0
		if at < 0:
			at = end + at
		subprocess.run(['ffmpeg', '-y', '-loglevel', 'error', '-ss', '%.2f' % at, '-i', out_mp4, '-frames:v', '1',
			'-q:v', '3', poster], check=True)
