"""Reads the boxes of an MP4 file, for the video and clip tests (video.py, firefox.py)."""
import struct


def boxes(b, start, end):
	"""The MP4 boxes between start and end: (type, body start, body end)."""
	out = []
	i = start
	while i + 8 <= end:
		size, kind = struct.unpack('>I4s', b[i:i + 8])
		head = 8
		if size == 1:
			size = struct.unpack('>Q', b[i + 8:i + 16])[0]
			head = 16
		elif size == 0:
			size = end - i
		if size < head:
			break
		out.append((kind.decode('latin-1'), i + head, i + size))
		i += size
	return out


def find(b, path, start=0, end=None):
	"""The first box down a path such as ['moov', 'trak']; every match at the last step."""
	end = len(b) if end is None else end
	level = [(None, start, end)]
	for k, name in enumerate(path):
		nxt = []
		for _, s, e in level:
			nxt += [x for x in boxes(b, s, e) if x[0] == name]
		level = nxt if k == len(path) - 1 else nxt[:]
	return level


def tracks(b):
	"""Each track's handler, sample entry, timescale, duration and keyframe count, and the picture size."""
	out = []
	for _, s, e in find(b, ['moov', 'trak']):
		mdia = find(b, ['mdia'], s, e)[0]
		mdhd = find(b, ['mdhd'], mdia[1], mdia[2])[0]
		v = b[mdhd[1]]
		if v == 1:
			scale, dur = struct.unpack('>IQ', b[mdhd[1] + 20:mdhd[1] + 32])
		else:
			scale, dur = struct.unpack('>II', b[mdhd[1] + 12:mdhd[1] + 20])
		hdlr = find(b, ['hdlr'], mdia[1], mdia[2])[0]
		handler = b[hdlr[1] + 8:hdlr[1] + 12].decode('latin-1')
		stbl = find(b, ['minf', 'stbl'], mdia[1], mdia[2])[0]
		stsd = find(b, ['stsd'], stbl[1], stbl[2])[0]
		entry = boxes(b, stsd[1] + 8, stsd[2])[0]
		t = {'handler': handler, 'entry': entry[0], 'secs': dur / scale}
		if handler == 'vide':
			t['w'], t['h'] = struct.unpack('>HH', b[entry[1] + 24:entry[1] + 28])
			stts = find(b, ['stts'], stbl[1], stbl[2])[0]
			n = struct.unpack('>I', b[stts[1] + 4:stts[1] + 8])[0]
			count = 0
			delta = 0
			for k in range(n):
				c, d = struct.unpack('>II', b[stts[1] + 8 + 8 * k:stts[1] + 16 + 8 * k])
				count += c
				delta = d
			t['frames'] = count
			avcc = [x for x in boxes(b, entry[1] + 78, entry[2]) if x[0] == 'avcC']
			t['profile'] = b[avcc[0][1] + 1] if avcc else None
			t['fps'] = scale / delta if delta else 0
			stss = find(b, ['stss'], stbl[1], stbl[2])
			t['keys'] = struct.unpack('>I', b[stss[0][1] + 4:stss[0][1] + 8])[0] if stss else count
		else:
			t['channels'], _, _, _, rate = struct.unpack('>HHHHI', b[entry[1] + 16:entry[1] + 28])
			t['rate'] = rate >> 16
		out.append(t)
	return out


def edit_lists(b):
	"""How many tracks carry an edit list (an edts box)."""
	return len(find(b, ['moov', 'trak', 'edts']))
