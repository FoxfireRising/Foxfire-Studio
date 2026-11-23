/*
 * Foxfire Studio: MP3 tags and chapters (0.7.0).
 *
 * Writes the ID3 tag that podcast apps, phones and music players read from
 * an MP3: title, author, show name, year, a link back to the post, artwork,
 * and chapters (the ID3 chapter frames, CHAP and CTOC). Also reads chapters
 * back out of an MP3, which the listening player uses so a post needs no
 * separate chapters file.
 *
 * ID3 version 2.3 is written, since it is the version every podcast app and
 * phone reads. Chapter frames follow the ID3 chapter addendum. Text that fits
 * in Latin-1 is stored that way; anything else as UTF-16 with a byte order mark.
 *
 * The tag is a block at the very start of the file, so changing it (for
 * example to add chapters once the Whisper review has finished) only means
 * swapping that block: the audio after it is never touched.
 *
 * Works on the page as window.RRTId3 and in Node for tests. The listening
 * player loads it on public pages, so like player.js it has no double
 * ampersands and only block comments, for strict caching-plugin optimisers.
 */
(function (root) {
	'use strict';

	var PAD = 1024; /* Spare room after the frames, as most taggers leave. */

	function isLatin1(s) {
		for (var i = 0; i < s.length; i++) { if (s.charCodeAt(i) > 255) { return false; } }
		return true;
	}
	/* Encoding byte plus the string, ending with the right terminator when asked. */
	function encText(s, terminate) {
		s = String(s == null ? '' : s);
		var out = [], i;
		if (isLatin1(s)) {
			out.push(0);
			for (i = 0; i < s.length; i++) { out.push(s.charCodeAt(i)); }
			if (terminate) { out.push(0); }
		} else {
			out.push(1, 0xff, 0xfe);
			for (i = 0; i < s.length; i++) { var c = s.charCodeAt(i); out.push(c & 255, c >> 8); }
			if (terminate) { out.push(0, 0); }
		}
		return out;
	}
	function latin1(s, terminate) {
		var out = [];
		s = String(s == null ? '' : s);
		for (var i = 0; i < s.length; i++) { var c = s.charCodeAt(i); out.push(c < 256 ? c : 63); }
		if (terminate) { out.push(0); }
		return out;
	}
	function u32(n) { return [(n >>> 24) & 255, (n >>> 16) & 255, (n >>> 8) & 255, n & 255]; }
	function syncsafe(n) { return [(n >> 21) & 127, (n >> 14) & 127, (n >> 7) & 127, n & 127]; }
	function concat(list) {
		var len = 0, i;
		for (i = 0; i < list.length; i++) { len += list[i].length; }
		var out = new Uint8Array(len), at = 0;
		for (i = 0; i < list.length; i++) { out.set(list[i], at); at += list[i].length; }
		return out;
	}
	/* A version 2.3 frame: four-letter id, plain 32-bit size, two flag bytes, body. */
	function frame(id, body) {
		body = body instanceof Uint8Array ? body : new Uint8Array(body);
		return concat([new Uint8Array(latin1(id).concat(u32(body.length), [0, 0])), body]);
	}
	function textFrame(id, s) { return frame(id, encText(s, false)); }

	/*
	 * Builds a tag. meta: { title, artist, album, track (1.20.0), year, genre, url,
	 * lengthMs, encoder, comment, artwork: { mime, data (Uint8Array) },
	 * chapters: [{ start (seconds), end (seconds), title, url }] }.
	 * Every field is optional. Returns the tag as a Uint8Array.
	 */
	function build(meta) {
		meta = meta || {};
		var frames = [];
		if (meta.title) { frames.push(textFrame('TIT2', meta.title)); }
		if (meta.artist) { frames.push(textFrame('TPE1', meta.artist)); }
		if (meta.album) { frames.push(textFrame('TALB', meta.album)); }
		if (meta.track) { frames.push(textFrame('TRCK', String(meta.track))); }
		if (meta.year) { frames.push(textFrame('TYER', String(meta.year).slice(0, 4))); }
		if (meta.genre) { frames.push(textFrame('TCON', meta.genre)); }
		if (meta.lengthMs) { frames.push(textFrame('TLEN', String(Math.round(meta.lengthMs)))); }
		if (meta.encoder) { frames.push(textFrame('TSSE', meta.encoder)); }
		/* 1.33.0: the reading's language (ISO 639-2), when it is given. */
		if (/^[a-z]{3}$/.test(meta.lang || '')) { frames.push(textFrame('TLAN', meta.lang)); }
		if (meta.comment) {
			/* A comment (0.24.0), such as the music bed's credit: language eng, no description. */
			var ct = encText(meta.comment, false);
			frames.push(frame('COMM', [ct[0]].concat(latin1(/^[a-z]{3}$/.test(meta.lang || '') ? meta.lang : 'eng'), ct[0] === 0 ? [0] : [0xff, 0xfe, 0, 0], ct.slice(1))));
		}
		if (meta.url) {
			/* Official audio source webpage: the post this reading belongs to. */
			frames.push(frame('WOAS', latin1(meta.url, false)));
		}
		var ch = (meta.chapters || []).filter(function (c) { return isFinite(c.start) ? (isFinite(c.end) ? c.end > c.start : false) : false; });
		if (ch.length) {
			var ids = ch.map(function (c, i) { return 'chp' + i; });
			/* Table of contents: top level, ordered, listing every chapter. */
			var toc = [].concat(latin1('toc', true), [3, ids.length]);
			ids.forEach(function (id) { toc = toc.concat(latin1(id, true)); });
			frames.push(frame('CTOC', toc));
			ch.forEach(function (c, i) {
				var sub = [textFrame('TIT2', c.title || ('Chapter ' + (i + 1)))];
				if (c.url) { sub.push(frame('WXXX', [0].concat(latin1('', true), latin1(c.url, false)))); }
				var head = new Uint8Array(latin1(ids[i], true).concat(
					u32(Math.round(c.start * 1000)), u32(Math.round(c.end * 1000)),
					[255, 255, 255, 255, 255, 255, 255, 255] /* No byte offsets: players seek by time. */
				));
				frames.push(frame('CHAP', concat([head].concat(sub))));
			});
		}
		/* Artwork last, so a reader after only the chapters can stop before it. */
		var art = meta.artwork || null;
		if (art ? (art.data ? art.data.length > 0 : false) : false) {
			frames.push(frame('APIC', concat([
				new Uint8Array([0].concat(latin1(art.mime || 'image/jpeg', true), [3], [0])), /* Front cover, no description. */
				art.data
			])));
		}
		var body = concat(frames.concat([new Uint8Array(PAD)]));
		return concat([new Uint8Array(latin1('ID3').concat([3, 0, 0], syncsafe(body.length))), body]);
	}

	/* The size of an ID3v2 tag at the start of bytes (header included), or 0 when there is none. */
	function tagLength(bytes) {
		if (bytes.length < 10 || bytes[0] !== 73 || bytes[1] !== 68 || bytes[2] !== 51) { return 0; }
		var size = (bytes[6] << 21) | (bytes[7] << 14) | (bytes[8] << 7) | bytes[9];
		var footer = (bytes[3] === 4 ? (bytes[5] & 16) : 0) ? 10 : 0;
		return 10 + size + footer;
	}

	function decText(b, enc) {
		var s = '', i;
		if (enc === 0 || enc === 3) {
			if (enc === 3) { try { return new TextDecoder('utf-8').decode(b).replace(/\u0000+$/, ''); } catch (e) {} }
			for (i = 0; i < b.length; i++) {
				if (b[i] === 0) { break; }
				s += String.fromCharCode(b[i]);
			}
			return s;
		}
		var le = true, start = 0;
		if (enc === 1 ? b.length >= 2 : false) {
			if (b[0] === 0xfe ? b[1] === 0xff : false) { le = false; start = 2; } else if (b[0] === 0xff ? b[1] === 0xfe : false) { start = 2; }
		}
		if (enc === 2) { le = false; }
		for (i = start; i + 1 < b.length; i += 2) {
			var c = le ? (b[i] | (b[i + 1] << 8)) : ((b[i] << 8) | b[i + 1]);
			if (c === 0) { break; }
			s += String.fromCharCode(c);
		}
		return s;
	}
	/* Walks frames from start to end in a v2.3 or v2.4 body, calling fn(id, body). Stops early if fn returns true. */
	function frames(b, start, end, v, fn) {
		var at = start;
		while (at + 10 <= end) {
			var id = String.fromCharCode(b[at], b[at + 1], b[at + 2], b[at + 3]);
			if (!/^[A-Z0-9]{4}$/.test(id)) { break; }
			var size = v === 4
				? (b[at + 4] << 21) | (b[at + 5] << 14) | (b[at + 6] << 7) | b[at + 7]
				: ((b[at + 4] << 24) | (b[at + 5] << 16) | (b[at + 6] << 8) | b[at + 7]) >>> 0;
			if (size <= 0 || at + 10 + size > end) { break; }
			if (fn(id, b.subarray(at + 10, at + 10 + size))) { return; }
			at += 10 + size;
		}
	}
	function cstr(b, at) {
		var s = '';
		while (at < b.length) {
			if (b[at] === 0) { break; }
			s += String.fromCharCode(b[at++]);
		}
		return { s: s, next: at + 1 };
	}

	/*
	 * Reads the title and chapters from the start of an MP3 (the whole tag
	 * need not be there: reading stops at the artwork or the end of the
	 * bytes given). Returns { title, chapters: [{ start, end, title }] }.
	 */
	function read(bytes) {
		var out = { title: '', chapters: [] };
		if (tagLength(bytes) === 0) { return out; }
		var v = bytes[3];
		if (v !== 3 ? v !== 4 : false) { return out; }
		if (bytes[5] & 0x80) { return out; } /* Unsynchronised tags are rare and not worth the code. */
		var start = 10;
		if (bytes[5] & 0x40) {
			/* Skip an extended header. */
			start += v === 4 ? ((bytes[10] << 21) | (bytes[11] << 14) | (bytes[12] << 7) | bytes[13]) : (((bytes[10] << 24) | (bytes[11] << 16) | (bytes[12] << 8) | bytes[13]) + 4);
		}
		var end = Math.min(bytes.length, tagLength(bytes));
		var order = null;
		frames(bytes, start, end, v, function (id, body) {
			if (id === 'TIT2') { out.title = decText(body.subarray(1), body[0]); }
			if (id === 'CTOC' ? !order : false) {
				var e = cstr(body, 0), flags = body[e.next], n = body[e.next + 1], at = e.next + 2;
				if (flags & 2 || flags & 1) {
					order = [];
					for (var k = 0; k < n; k++) { var c = cstr(body, at); order.push(c.s); at = c.next; }
				}
			}
			if (id === 'CHAP') {
				var h = cstr(body, 0), p = h.next;
				var s = ((body[p] << 24) | (body[p + 1] << 16) | (body[p + 2] << 8) | body[p + 3]) >>> 0;
				var t = ((body[p + 4] << 24) | (body[p + 5] << 16) | (body[p + 6] << 8) | body[p + 7]) >>> 0;
				var chap = { id: h.s, start: s / 1000, end: t / 1000, title: '' };
				frames(body, p + 16, body.length, v, function (sid, sb) {
					if (sid === 'TIT2') { chap.title = decText(sb.subarray(1), sb[0]); }
				});
				out.chapters.push(chap);
			}
			return id === 'APIC';
		});
		if (order) {
			var pos = {};
			order.forEach(function (id, i) { pos[id] = i; });
			out.chapters.sort(function (a, b) { return (pos[a.id] === undefined ? 1e9 : pos[a.id]) - (pos[b.id] === undefined ? 1e9 : pos[b.id]); });
		} else {
			out.chapters.sort(function (a, b) { return a.start - b.start; });
		}
		return out;
	}

	/* A Podcasting 2.0 chapters file (JSON) for the same chapters. */
	function chaptersJson(chapters, title) {
		return JSON.stringify({
			version: '1.2.0',
			title: title || undefined,
			chapters: chapters.map(function (c) {
				var o = { startTime: Math.round(c.start * 1000) / 1000, endTime: Math.round(c.end * 1000) / 1000, title: c.title };
				if (c.url) { o.url = c.url; }
				return o;
			})
		}, null, 2);
	}

	var api = { build: build, read: read, tagLength: tagLength, chaptersJson: chaptersJson };
	root.RRTId3 = api;
	if (typeof module !== 'undefined') {
		if (module.exports) { module.exports = api; }
	}
})(typeof window !== 'undefined' ? window : this);
