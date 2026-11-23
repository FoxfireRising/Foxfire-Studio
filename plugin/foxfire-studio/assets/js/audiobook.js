/*
 * Foxfire Studio: the Audiobook box on a collection's screen (1.20.0).
 *
 * Makes a collection into the files an audiobook store asks for, to ACX's
 * rules (Audible), which Spotify for Authors, Authors Republic and Findaway
 * also accept: the opening credits, one file for each reading, the closing
 * credits, a retail sample of one to five minutes and the cover, each MP3 at
 * 192 kbps mono with room tone at both ends, checked against the rules and
 * downloaded as one ZIP with a check report. Everything is made here, in the
 * browser; nothing of the ZIP is kept on the site.
 *
 * A reading's chapter is its audiobook copy when it has one (copied as it
 * is, with only a tag added). A reading with no copy and no music bed is
 * made from its post's MP3: decoded, given room tone from its own pauses
 * (sound.js roomPad), leveled to -20 dB RMS with peaks under -3.5 dB and
 * encoded once at 192 kbps with the plugin's MP3 encoder (mp3.js in a
 * worker). A reading with the music bed must have an audiobook copy.
 *
 * The pure helpers at the top are also used by the tests in Node.
 */
(function (root) {
	'use strict';

	/* Translations (0.25.0): WordPress's wp.i18n when the page has it, otherwise the English text as written. */
	var rrtI18n = (typeof wp === 'object' ? (wp ? wp.i18n : null) : null) || null;
	var __ = rrtI18n ? rrtI18n.__ : function (s) { return s; };
	var _n = rrtI18n ? rrtI18n._n : function (one, many, n) { return n === 1 ? one : many; };
	var sprintf = rrtI18n ? rrtI18n.sprintf : function (f) {
		var a = arguments;
		var i = 0;
		return String(f).replace(/%(?:(\d+)\$)?([sd%])/g, function (m, pos, type) {
			if (type === '%') { return '%'; }
			var v = a[pos ? Number(pos) : ++i];
			return type === 'd' ? String(parseInt(v, 10)) : String(v);
		});
	};

	var B = {};

	/* ACX's numbers (checked November 2026). */
	B.RULES = { rmsLo: -23, rmsHi: -18, rmsTarget: -20, peak: -3, ceiling: -3.5, noise: -60, roomLo: 1, roomHi: 5, kbps: 192, rate: 44100, maxMin: 120, sampleLo: 60, sampleHi: 300, coverMin: 2400, consistent: 2 };
	B.RATE = 44100;

	/*
	 * ---------------------------------------------------------------------
	 * Pure helpers
	 * ---------------------------------------------------------------------
	 */

	/* Seconds as m:ss, or h:mm:ss from an hour. */
	B.clock = function (secs) {
		var s = Math.max(0, Math.round(Number(secs) || 0));
		var h = Math.floor(s / 3600);
		var m = Math.floor((s % 3600) / 60);
		var r = s % 60;
		return (h ? h + ':' + (m < 10 ? '0' : '') + m : String(m)) + ':' + (r < 10 ? '0' : '') + r;
	};

	/* m:ss (or plain seconds, or h:mm:ss) read back as seconds; null when empty or not a time. */
	B.parseClock = function (s) {
		s = String(s === null || s === undefined ? '' : s).trim();
		if (!s) { return null; }
		if (!/^\d+(:\d{1,2}){0,2}(\.\d+)?$/.test(s)) { return null; }
		var parts = s.split(':').map(Number), t = 0;
		for (var i = 0; i < parts.length; i++) { t = t * 60 + parts[i]; }
		return isFinite(t) ? t : null;
	};

	/* A file name with only the plain letters, numbers and spaces ACX allows, as the site makes them. */
	B.fileName = function (n, title) {
		var t = String(title || '');
		if (t.normalize) { t = t.normalize('NFD').replace(/[̀-ͯ]/g, ''); }
		t = t.replace(/[^A-Za-z0-9 ]+/g, ' ').replace(/\s+/g, ' ').trim();
		if (!t) { t = 'Chapter'; }
		t = t.slice(0, 60).trim();
		return (n < 10 ? '0' : '') + n + ' ' + t + '.mp3';
	};

	/* The ZIP's own name for the collection. */
	B.zipName = function (slug, title) {
		var s = String(slug || '').replace(/[^a-z0-9-]+/gi, '-').replace(/^-+|-+$/g, '');
		if (!s) { s = B.fileName(0, title).slice(3, -4).replace(/ /g, '-').toLowerCase(); }
		return (s || 'collection') + '-audiobook.zip';
	};

	/* CRC-32 (the ZIP's checksum). */
	var CRC = null;
	B.crc32 = function (u8) {
		if (!CRC) {
			CRC = new Uint32Array(256);
			for (var n = 0; n < 256; n++) {
				var c = n;
				for (var k = 0; k < 8; k++) { c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1; }
				CRC[n] = c >>> 0;
			}
		}
		var crc = 0xFFFFFFFF;
		for (var i = 0; i < u8.length; i++) { crc = CRC[(crc ^ u8[i]) & 255] ^ (crc >>> 8); }
		return (crc ^ 0xFFFFFFFF) >>> 0;
	};

	/*
	 * A ZIP of files stored as they are (MP3 and JPEG gain nothing from
	 * compressing). files: [{ name (plain ASCII), data (Uint8Array) }].
	 * Returns the ZIP as a list of Uint8Arrays, to be joined into a Blob.
	 */
	B.zip = function (files, when) {
		var d = when || new Date();
		var dosTime = (d.getHours() << 11) | (d.getMinutes() << 5) | Math.floor(d.getSeconds() / 2);
		var dosDate = ((Math.max(1980, d.getFullYear()) - 1980) << 9) | ((d.getMonth() + 1) << 5) | d.getDate();
		var out = [], central = [], at = 0;
		function head(sig, size) { var b = new Uint8Array(size); var v = new DataView(b.buffer); v.setUint32(0, sig, true); return { b: b, v: v }; }
		files.forEach(function (f) {
			var name = new Uint8Array(String(f.name).split('').map(function (ch) { var c = ch.charCodeAt(0); return c < 128 ? c : 95; }));
			var data = f.data;
			var crc = B.crc32(data);
			var lh = head(0x04034b50, 30);
			lh.v.setUint16(4, 10, true);
			lh.v.setUint16(6, 0, true);
			lh.v.setUint16(8, 0, true);
			lh.v.setUint16(10, dosTime, true);
			lh.v.setUint16(12, dosDate, true);
			lh.v.setUint32(14, crc, true);
			lh.v.setUint32(18, data.length, true);
			lh.v.setUint32(22, data.length, true);
			lh.v.setUint16(26, name.length, true);
			lh.v.setUint16(28, 0, true);
			out.push(lh.b, name, data);
			var ch = head(0x02014b50, 46);
			ch.v.setUint16(4, 20, true);
			ch.v.setUint16(6, 10, true);
			ch.v.setUint16(8, 0, true);
			ch.v.setUint16(10, 0, true);
			ch.v.setUint16(12, dosTime, true);
			ch.v.setUint16(14, dosDate, true);
			ch.v.setUint32(16, crc, true);
			ch.v.setUint32(20, data.length, true);
			ch.v.setUint32(24, data.length, true);
			ch.v.setUint16(28, name.length, true);
			ch.v.setUint32(42, at, true);
			central.push(ch.b, name);
			at += 30 + name.length + data.length;
		});
		var cdSize = 0;
		central.forEach(function (c) { cdSize += c.length; });
		var end = head(0x06054b50, 22);
		end.v.setUint16(8, files.length, true);
		end.v.setUint16(10, files.length, true);
		end.v.setUint32(12, cdSize, true);
		end.v.setUint32(16, at, true);
		return out.concat(central, [end.b]);
	};

	/*
	 * The retail sample's stretch, in seconds: from start (null: the first
	 * pause of half a second or more after the first 1.5 s of speech, so the
	 * title is left out) for about len minutes, both ends at the middle of a
	 * pause, and always between 1 and 5 minutes when the reading is long
	 * enough. pauses: [{ s, e }]; total: the reading's length.
	 */
	B.sampleSpan = function (pauses, start, lenMin, total, speechStart) {
		var mids = (pauses || []).map(function (p) { return { t: (p.s + p.e) / 2, len: p.e - p.s }; });
		var R = B.RULES, a;
		var from = speechStart || 0;
		if (start === null || start === undefined || !isFinite(start)) {
			a = null;
			for (var i = 0; i < mids.length; i++) { if (mids[i].len >= 0.5 && mids[i].t >= from + 1.5) { a = mids[i].t; break; } }
			if (a === null) { a = from; }
		} else {
			a = start;
			var best = null;
			mids.forEach(function (m) { if (best === null || Math.abs(m.t - start) < Math.abs(best - start)) { best = m.t; } });
			if (best !== null ? Math.abs(best - start) <= 10 : false) { a = best; }
		}
		a = Math.max(0, Math.min(a, total));
		var want = a + Math.max(1, Math.min(5, Number(lenMin) || 3)) * 60;
		var lo = a + R.sampleLo + 1, hi = a + R.sampleHi - 1, b = null;
		mids.forEach(function (m) {
			if (m.t < lo || m.t > hi) { return; }
			if (b === null || Math.abs(m.t - want) < Math.abs(b - want)) { b = m.t; }
		});
		if (b === null) { b = Math.min(total, Math.max(lo, Math.min(hi, want))); }
		return { a: a, b: Math.min(b, total) };
	};

	/* Words for comparing a title with what was heard. */
	function words(s) {
		var t = String(s || '').toLowerCase();
		if (t.normalize) { t = t.normalize('NFD').replace(/[̀-ͯ]/g, ''); }
		return t.replace(/[^a-z0-9' ]+/g, ' ').split(/\s+/).filter(function (w) { return w.length > 0; });
	}

	/*
	 * Whether a reading starts with its heading read aloud, from its captions
	 * (a WebVTT file): the title's first words (up to six) found among the
	 * captions' first words, in order. ok null when there are no captions.
	 */
	B.headingCheck = function (vtt, title) {
		var tw = words(title).slice(0, 6);
		if (!vtt || !tw.length) { return { ok: null, heard: '' }; }
		var lines = String(vtt).split(/\r?\n/), text = [], took = 0;
		for (var i = 0; i < lines.length && took < 3; i++) {
			var l = lines[i].trim();
			if (!l || /^WEBVTT/.test(l) || /-->/.test(l) || /^\d+$/.test(l) || /^NOTE/.test(l)) { continue; }
			text.push(l);
			took++;
		}
		var hw = words(text.join(' ')).slice(0, tw.length + 6);
		var j = 0, found = 0;
		tw.forEach(function (w) {
			for (var k = j; k < hw.length; k++) { if (hw[k] === w) { found++; j = k + 1; return; } }
		});
		return { ok: found / tw.length >= 0.7, heard: hw.slice(0, tw.length + 2).join(' ') };
	};

	/*
	 * One file's rows for the check report, from sound.js's audiobook check
	 * (level, peaks, background, room tone, MP3, loudness range, clipping)
	 * with the true peak, the length, mono and, for a chapter, the heading.
	 * Each row: { label, value, ok (true, false or null for information), rule }.
	 */
	B.fileItems = function (check, secs, ch, heading) {
		var R = B.RULES, out = [];
		function r1(v) { return Math.round(v * 10) / 10; }
		(check ? check.items : []).forEach(function (it) { out.push({ label: it.label, value: it.value, ok: it.ok, rule: it.rule }); });
		var m = check ? check.measures : null;
		if (m ? isFinite(m.truePeak) : false) {
			out.splice(Math.min(2, out.length), 0, { label: __( 'True peak', 'foxfire-studio' ), value: sprintf( /* translators: %s: decibels. */ __( '%s dB', 'foxfire-studio' ), r1(m.truePeak) ), ok: m.truePeak <= R.peak, rule: __( '-3 dB or lower', 'foxfire-studio' ) });
		}
		out.push({ label: __( 'Length', 'foxfire-studio' ), value: B.clock(secs), ok: secs > 0 && secs <= R.maxMin * 60, rule: __( '120 minutes at most', 'foxfire-studio' ) });
		out.push({ label: __( 'Channels', 'foxfire-studio' ), value: ch === 1 ? __( 'mono', 'foxfire-studio' ) : __( 'stereo', 'foxfire-studio' ), ok: ch === 1, rule: __( 'all files mono', 'foxfire-studio' ) });
		if (heading) {
			out.push({ label: __( 'Heading read aloud', 'foxfire-studio' ), value: heading.ok === null ? __( 'no captions to check', 'foxfire-studio' ) : sprintf( /* translators: %s: the first words heard. */ __( 'heard "%s"', 'foxfire-studio' ), heading.heard ), ok: heading.ok, rule: heading.ok === null ? __( 'listen to the first seconds', 'foxfire-studio' ) : __( 'the title read first', 'foxfire-studio' ) });
		}
		return out;
	};

	/*
	 * The whole book's rows: the credits, the sample's length, every chapter
	 * made, the levels within 2 dB of each other, and the cover.
	 * parts: [{ kind ('open', 'chapter', 'close', 'sample', 'missing'), secs, rms }].
	 */
	B.bookItems = function (parts, cover) {
		var R = B.RULES, out = [];
		var has = function (k) { return parts.some(function (p) { return p.kind === k; }); };
		out.push({ label: __( 'Opening credits', 'foxfire-studio' ), value: has('open') ? __( 'included', 'foxfire-studio' ) : __( 'not recorded yet', 'foxfire-studio' ), ok: has('open'), rule: __( 'a file of their own: title, author and narrator', 'foxfire-studio' ) });
		out.push({ label: __( 'Closing credits', 'foxfire-studio' ), value: has('close') ? __( 'included', 'foxfire-studio' ) : __( 'not recorded yet', 'foxfire-studio' ), ok: has('close'), rule: __( 'a file of their own: "The end" at least', 'foxfire-studio' ) });
		var miss = parts.filter(function (p) { return p.kind === 'missing'; }).length;
		out.push({ label: __( 'Chapters', 'foxfire-studio' ), value: miss ? sprintf( /* translators: %d: readings left out. */ _n( '%d reading left out', '%d readings left out', miss, 'foxfire-studio' ), miss ) : sprintf( /* translators: %d: number of chapter files. */ _n( '%d file', '%d files', parts.filter(function (p) { return p.kind === 'chapter'; }).length, 'foxfire-studio' ), parts.filter(function (p) { return p.kind === 'chapter'; }).length ), ok: !miss && has('chapter'), rule: __( 'one file for each reading', 'foxfire-studio' ) });
		var sm = parts.filter(function (p) { return p.kind === 'sample'; })[0];
		out.push({ label: __( 'Retail sample', 'foxfire-studio' ), value: sm ? B.clock(sm.secs) : __( 'not made', 'foxfire-studio' ), ok: sm ? sm.secs >= R.sampleLo && sm.secs <= R.sampleHi : false, rule: __( '1 to 5 minutes, starting with narration, nothing explicit', 'foxfire-studio' ) });
		var levels = parts.filter(function (p) { return p.kind !== 'missing' && p.kind !== 'sample' && isFinite(p.rms); }).map(function (p) { return p.rms; });
		if (levels.length > 1) {
			var spread = Math.max.apply(null, levels) - Math.min.apply(null, levels);
			out.push({ label: __( 'Levels from file to file', 'foxfire-studio' ), value: sprintf( /* translators: %s: decibels. */ __( 'within %s dB', 'foxfire-studio' ), Math.round(spread * 10) / 10 ), ok: spread <= R.consistent, rule: __( 'consistent (within 2 dB)', 'foxfire-studio' ) });
		}
		if (cover) {
			var sq = cover.w === cover.h, big = Math.min(cover.w, cover.h) >= R.coverMin;
			out.push({ label: __( 'Cover', 'foxfire-studio' ), value: cover.w + ' × ' + cover.h, ok: sq && big, rule: __( 'square, 2400 by 2400 or larger; its title must match the opening credits', 'foxfire-studio' ) });
		} else {
			out.push({ label: __( 'Cover', 'foxfire-studio' ), value: __( 'none (set the collection\'s featured image)', 'foxfire-studio' ), ok: false, rule: __( 'square, 2400 by 2400 or larger', 'foxfire-studio' ) });
		}
		return out;
	};

	function esc(s) { return String(s === null || s === undefined ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
	function mark(ok) { return ok === true ? '✓' : (ok === false ? '✗' : '·'); }

	/* The check report as a page of its own, for the ZIP. report: { title, author, made, book, files: [{ name, items }] }. */
	B.reportHtml = function (report) {
		var h = [];
		h.push('<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>' + esc(report.title) + ': ' + esc(__( 'audiobook check', 'foxfire-studio' )) + '</title>');
		h.push('<style>body{font:15px/1.5 system-ui,sans-serif;max-width:860px;margin:2em auto;padding:0 1em;color:#1d2327}table{border-collapse:collapse;width:100%;margin:0 0 1.5em}td,th{border-bottom:1px solid #dcdcde;padding:4px 8px;text-align:left;vertical-align:top}.ok{color:#00733a}.bad{color:#b32d2e;font-weight:600}.info{color:#50575e}h2{font-size:17px;margin:1.6em 0 .4em}</style></head><body>');
		h.push('<h1>' + esc(report.title) + '</h1>');
		/* translators: 1: author, 2: date and time made. */
		h.push('<p>' + esc(sprintf(__( 'By %1$s. Checked %2$s against the rules of Audible (ACX), which Spotify for Authors, Authors Republic and Findaway also accept.', 'foxfire-studio' ), report.author, report.made)) + '</p>');
		var row = function (it) { var cls = it.ok === true ? 'ok' : (it.ok === false ? 'bad' : 'info'); return '<tr><td class="' + cls + '">' + mark(it.ok) + '</td><td>' + esc(it.label) + '</td><td>' + esc(it.value) + '</td><td class="info">' + esc(it.rule) + '</td></tr>'; };
		h.push('<h2>' + esc(__( 'The whole audiobook', 'foxfire-studio' )) + '</h2><table>' + report.book.map(row).join('') + '</table>');
		report.files.forEach(function (f) { h.push('<h2>' + esc(f.name) + '</h2><table>' + f.items.map(row).join('') + '</table>'); });
		h.push('<h2>' + esc(__( 'Not checked here', 'foxfire-studio' )) + '</h2><ul>');
		[
			__( 'ACX takes an audiobook only for a book already on Amazon as a Kindle ebook, from a rights holder in the US, UK, Canada or Ireland.', 'foxfire-studio' ),
			__( 'The cover\'s title and author must match the opening credits.', 'foxfire-studio' ),
			__( 'Listen through once for extraneous sounds (clicks, bumps, a word read twice) and for an even sound from file to file.', 'foxfire-studio' ),
			__( 'The retail sample must not hold explicit material.', 'foxfire-studio' ),
			__( 'Spotify for Authors, Authors Republic and Findaway take the same files.', 'foxfire-studio' )
		].forEach(function (t) { h.push('<li>' + esc(t) + '</li>'); });
		h.push('</ul></body></html>');
		return h.join('\n');
	};

	/*
	 * ---------------------------------------------------------------------
	 * Audio
	 * ---------------------------------------------------------------------
	 */

	/* Bytes from an address on this site (cookies sent, nothing cached). */
	function fetchBytes(url) {
		return fetch(url, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) {
			if (!r.ok) { throw new Error(sprintf( /* translators: %s: HTTP status. */ __( 'A file could not be fetched (HTTP %s).', 'foxfire-studio' ), r.status)); }
			return r.arrayBuffer();
		}).then(function (ab) { return new Uint8Array(ab); });
	}

	/* MP3 bytes decoded to one channel at 44.1 kHz. */
	function decodeMono(u8) {
		var Ctx = root.OfflineAudioContext || root.webkitOfflineAudioContext;
		if (!Ctx) { return Promise.reject(new Error(__( 'This browser cannot decode audio here.', 'foxfire-studio' ))); }
		var ctx = new Ctx(1, 1, B.RATE);
		var copy = u8.slice().buffer;
		return new Promise(function (ok, bad) { ctx.decodeAudioData(copy, ok, bad); }).then(function (buf) {
			if (buf.numberOfChannels < 2) { return { x: buf.getChannelData(0), ch: 1 }; }
			var a = buf.getChannelData(0), b = buf.getChannelData(1), x = new Float32Array(a.length);
			for (var i = 0; i < a.length; i++) { x[i] = (a[i] + b[i]) / 2; }
			return { x: x, ch: buf.numberOfChannels };
		});
	}

	/* The channel count written in an MP3's first frame header (3 = mono in the mode bits), or 0. */
	B.mp3Channels = function (u8) {
		var i = 0;
		if (u8.length > 10 && u8[0] === 0x49 && u8[1] === 0x44 && u8[2] === 0x33) {
			i = 10 + ((u8[6] & 127) << 21 | (u8[7] & 127) << 14 | (u8[8] & 127) << 7 | (u8[9] & 127));
		}
		for (; i + 4 < u8.length && i < 200000; i++) {
			if (u8[i] === 0xFF && (u8[i + 1] & 0xE0) === 0xE0) { return ((u8[i + 3] >> 6) & 3) === 3 ? 1 : 2; }
		}
		return 0;
	};

	/* Bytes with an ID3 tag in front (any old tag removed first). */
	function tagged(u8, meta) {
		var start = 0;
		if (u8.length > 10 && u8[0] === 0x49 && u8[1] === 0x44 && u8[2] === 0x33) {
			start = 10 + ((u8[6] & 127) << 21 | (u8[7] & 127) << 14 | (u8[8] & 127) << 7 | (u8[9] & 127));
		}
		if (!root.RRTId3) { return start ? u8.subarray(start) : u8; }
		var tag = root.RRTId3.build(meta);
		var out = new Uint8Array(tag.length + u8.length - start);
		out.set(tag, 0);
		out.set(u8.subarray(start), tag.length);
		return out;
	}

	/*
	 * ---------------------------------------------------------------------
	 * The box
	 * ---------------------------------------------------------------------
	 */

	function el(tag, cls, text) {
		var e = document.createElement(tag);
		if (cls) { e.className = cls; }
		if (text !== undefined) { e.textContent = text; }
		return e;
	}

	function restUrl(rootUrl, path) {
		return String(rootUrl) + String(path);
	}

	function Box(host, boot) {
		this.host = host;
		this.boot = boot;
		this.state = boot.state;
		this.worker = null;
		this.busy = false;
		this.cancelled = false;
		this.report = null;
		var self = this;
		this.render();
		/* Credits recorded in another tab show here when this one comes back to the front. */
		root.addEventListener('focus', function () { if (!self.busy) { self.reload(); } });
	}

	Box.prototype.api = function (method, path, body) {
		var opts = { method: method, credentials: 'same-origin', headers: { 'X-WP-Nonce': this.boot.nonce } };
		if (body) {
			opts.headers['Content-Type'] = 'application/json';
			opts.body = JSON.stringify(body);
		}
		return fetch(restUrl(this.boot.rest, path), opts).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (j) {
				if (!r.ok) { throw new Error((j && j.message) || sprintf( /* translators: %s: HTTP status. */ __( 'The site answered %s.', 'foxfire-studio' ), r.status)); }
				return j;
			});
		});
	};

	Box.prototype.reload = function () {
		var self = this;
		return this.api('GET', 'audiobook/col/' + this.state.col).then(function (st) {
			self.state = st;
			self.render();
		}).catch(function () {});
	};

	Box.prototype.save = function (body) {
		var self = this;
		return this.api('POST', 'audiobook/col/' + this.state.col, body).then(function (st) {
			self.state = st;
			self.render();
			return st;
		});
	};

	Box.prototype.say = function (msg, bad) {
		this.msg.textContent = msg || '';
		this.msg.className = 'rrt-book-msg' + (bad ? ' is-bad' : '');
	};

	Box.prototype.render = function () {
		var self = this, st = this.state;
		var h = this.host;
		var keepMsg = this.msg ? this.msg.textContent : '', keepBad = this.msg ? /is-bad/.test(this.msg.className) : false;
		h.textContent = '';

		/* The chapters. */
		h.appendChild(el('h4', 'rrt-book-h', __( 'Chapters', 'foxfire-studio' )));
		if (!st.rows.length) { h.appendChild(el('p', 'rrt-small', __( 'Add readings to the collection and save it first.', 'foxfire-studio' ))); }
		var list = el('ol', 'rrt-book-rows');
		st.rows.forEach(function (r) {
			var li = el('li', 'rrt-book-row is-' + r.source);
			li.appendChild(el('span', 'rrt-col-name', r.title));
			var line = el('p', 'rrt-book-note');
			if (r.source === 'book') {
				/* translators: 1: length, 2: date made. */
				line.textContent = sprintf(__( 'Audiobook copy, %1$s, made %2$s. Used as it is.', 'foxfire-studio' ), B.clock(r.book.secs), r.book.at);
			} else if (r.source === 'post') {
				line.textContent = __( 'No audiobook copy yet, so it is made from the post\'s MP3 in this browser: leveled for audiobooks, room tone added from its own pauses, and encoded at 192 kbps, one more round of MP3. An audiobook copy from the take sounds a little better.', 'foxfire-studio' );
			} else {
				var why = {
					music: __( 'It has the music bed, which fills the pauses where the room should be heard.', 'foxfire-studio' ),
					none: __( 'It has no reading on its post.', 'foxfire-studio' ),
					away: __( 'Its reading is not a file on this site.', 'foxfire-studio' ),
					notmp3: __( 'Its reading is not a constant-bitrate MP3.', 'foxfire-studio' )
				}[r.why] || '';
				line.textContent = __( 'Needs an audiobook copy.', 'foxfire-studio' ) + ' ' + why + ' ' + __( 'Open it in the studio, review a take (a new one, or one from its shelf), then choose Finish for, Keep as this post\'s audiobook chapter, and press Keep for the audiobook.', 'foxfire-studio' ) + ' ';
				if (r.studio) {
					var a = el('a', '', __( 'Open the studio', 'foxfire-studio' ));
					a.href = r.studio;
					a.target = '_blank';
					a.rel = 'noopener';
					line.appendChild(a);
				}
			}
			li.appendChild(line);
			list.appendChild(li);
		});
		h.appendChild(list);

		/* The credits. */
		['open', 'close'].forEach(function (kind) {
			var c = st.credits[kind];
			h.appendChild(el('h4', 'rrt-book-h', kind === 'open' ? __( 'Opening credits', 'foxfire-studio' ) : __( 'Closing credits', 'foxfire-studio' )));
			var p = el('p', 'rrt-small', kind === 'open' ? __( 'Read before the first chapter, as a file of its own. ACX asks for the title, the author and the narrator. The first line is read first, then the rest.', 'foxfire-studio' ) : __( 'Read after the last chapter, as a file of its own. ACX asks for "The end" at least.', 'foxfire-studio' ));
			h.appendChild(p);
			var ta = el('textarea', 'rrt-book-script');
			ta.rows = 3;
			ta.value = c.script;
			ta.setAttribute('aria-label', kind === 'open' ? __( 'Opening credits script', 'foxfire-studio' ) : __( 'Closing credits script', 'foxfire-studio' ));
			ta.setAttribute('data-kind', kind);
			ta.addEventListener('change', function () {
				var cr = {};
				cr[kind] = ta.value;
				self.save({ credits: cr }).catch(function (e) { self.say(e.message, true); });
			});
			h.appendChild(ta);
			var row = el('p', 'rrt-book-credit');
			/* translators: 1: length, 2: date made. */
			row.appendChild(el('span', c.book ? 'rrt-book-ok' : 'rrt-book-todo', c.book ? sprintf(__( 'Recorded: %1$s, made %2$s.', 'foxfire-studio' ), B.clock(c.book.secs), c.book.at) : __( 'Not recorded yet.', 'foxfire-studio' )));
			row.appendChild(document.createTextNode(' '));
			var rec = el('button', 'button', c.book ? __( 'Record again in the studio', 'foxfire-studio' ) : __( 'Record in the studio', 'foxfire-studio' ));
			rec.type = 'button';
			rec.setAttribute('data-record', kind);
			rec.addEventListener('click', function () { self.record(kind, ta.value); });
			row.appendChild(rec);
			if (c.book) {
				var au = el('audio', 'rrt-book-audio');
				au.controls = true;
				au.preload = 'none';
				au.src = c.book.url;
				row.appendChild(au);
			}
			h.appendChild(row);
		});

		/* The retail sample. */
		h.appendChild(el('h4', 'rrt-book-h', __( 'Retail sample', 'foxfire-studio' )));
		h.appendChild(el('p', 'rrt-small', __( 'One to five minutes from a reading, for shoppers to hear. It starts and ends in a pause, and starts after the title unless you choose a time. It must not hold explicit material.', 'foxfire-studio' )));
		var sp = el('p', 'rrt-book-sample');
		var sel = el('select');
		sel.setAttribute('aria-label', __( 'Reading for the sample', 'foxfire-studio' ));
		st.rows.forEach(function (r) {
			var o = el('option', '', r.title);
			o.value = String(r.id);
			if (r.id === st.sample.post) { o.selected = true; }
			sel.appendChild(o);
		});
		var startIn = el('input');
		startIn.type = 'text';
		startIn.size = 7;
		startIn.placeholder = __( 'after the title', 'foxfire-studio' );
		startIn.value = st.sample.start === null ? '' : B.clock(st.sample.start);
		startIn.setAttribute('aria-label', __( 'Start at (minutes:seconds into the reading)', 'foxfire-studio' ));
		var lenSel = el('select');
		lenSel.setAttribute('aria-label', __( 'Length of the sample', 'foxfire-studio' ));
		[1, 2, 3, 4, 5].forEach(function (n) {
			/* translators: %d: minutes. */
			var o = el('option', '', sprintf(_n( 'about %d minute', 'about %d minutes', n, 'foxfire-studio' ), n));
			o.value = String(n);
			if (n === st.sample.len) { o.selected = true; }
			lenSel.appendChild(o);
		});
		var saveSample = function () {
			var t = B.parseClock(startIn.value);
			if (startIn.value.trim() && t === null) { self.say(__( 'Write the start as minutes and seconds, such as 2:30, or leave it empty.', 'foxfire-studio' ), true); return; }
			self.save({ sample: { post: parseInt(sel.value, 10) || 0, start: t, len: parseInt(lenSel.value, 10) || 3 } }).catch(function (e) { self.say(e.message, true); });
		};
		sel.addEventListener('change', saveSample);
		startIn.addEventListener('change', saveSample);
		lenSel.addEventListener('change', saveSample);
		sp.appendChild(el('label', '', __( 'From', 'foxfire-studio' ) + ' '));
		sp.appendChild(sel);
		sp.appendChild(document.createTextNode(' ' + __( 'starting at', 'foxfire-studio' ) + ' '));
		sp.appendChild(startIn);
		sp.appendChild(document.createTextNode(' '));
		sp.appendChild(lenSel);
		h.appendChild(sp);

		/* The cover. */
		h.appendChild(el('h4', 'rrt-book-h', __( 'Cover', 'foxfire-studio' )));
		var cv = st.cover;
		var cl = el('p', 'rrt-small');
		if (!cv) {
			cl.textContent = __( 'No cover yet. Set the collection\'s featured image: square, 2400 by 2400 pixels or larger, with the same title and author as the opening credits.', 'foxfire-studio' );
		} else if (cv.w !== cv.h || Math.min(cv.w, cv.h) < B.RULES.coverMin) {
			/* translators: 1: width, 2: height. */
			cl.textContent = sprintf(__( 'The featured image is %1$s by %2$s. ACX needs a square cover of 2400 by 2400 pixels or larger; it goes in the ZIP as it is.', 'foxfire-studio' ), cv.w, cv.h);
		} else {
			/* translators: 1: width, 2: height. */
			cl.textContent = sprintf(__( 'The featured image, %1$s by %2$s, goes in the ZIP as the cover. Its title and author must match the opening credits.', 'foxfire-studio' ), cv.w, cv.h);
		}
		h.appendChild(cl);

		/* Make it. */
		var act = el('p', 'rrt-book-act');
		var go = el('button', 'button button-primary', __( 'Make the audiobook', 'foxfire-studio' ));
		go.type = 'button';
		go.setAttribute('data-make', '1');
		go.disabled = this.busy || !st.rows.some(function (r) { return r.source !== 'need'; });
		go.addEventListener('click', function () { self.make(); });
		act.appendChild(go);
		if (this.busy) {
			var cancel = el('button', 'button', __( 'Cancel', 'foxfire-studio' ));
			cancel.type = 'button';
			cancel.addEventListener('click', function () { self.cancelled = true; self.say(__( 'Stopping after this file...', 'foxfire-studio' )); });
			act.appendChild(document.createTextNode(' '));
			act.appendChild(cancel);
		}
		var need = st.rows.filter(function (r) { return r.source === 'need'; }).length;
		if (need) {
			/* translators: %d: readings. */
			act.appendChild(el('span', 'rrt-book-warn', ' ' + sprintf(_n( '%d reading needs an audiobook copy first; the ZIP would leave it out.', '%d readings need an audiobook copy first; the ZIP would leave them out.', need, 'foxfire-studio' ), need)));
		}
		h.appendChild(act);
		this.bar = el('progress', 'rrt-book-bar');
		this.bar.max = 1;
		this.bar.value = 0;
		this.bar.hidden = !this.busy;
		h.appendChild(this.bar);
		this.msg = el('p', 'rrt-book-msg');
		h.appendChild(this.msg);
		this.say(keepMsg, keepBad);
		if (this.report) { h.appendChild(this.reportNode(this.report)); }
	};

	/* Saves the script, then opens the studio on the credits in a new tab. */
	Box.prototype.record = function (kind, script) {
		var self = this;
		var w = root.open('', '_blank');
		var cr = {};
		cr[kind] = script;
		this.save({ credits: cr }).then(function (st) {
			var url = st.credits[kind].studio;
			if (!url) { throw new Error(__( 'The credits could not be saved.', 'foxfire-studio' )); }
			if (w) { w.location.href = url; } else { root.location.href = url; }
			self.say(__( 'The studio opened in a new tab. Record the credits there and press Use for the audiobook; this box shows them when you come back.', 'foxfire-studio' ));
		}).catch(function (e) {
			if (w) { w.close(); }
			self.say(e.message, true);
		});
	};

	/* The MP3 encoder in a worker: this site's copy first, then the fallback. */
	Box.prototype.encode = function (pcm, onProgress) {
		var enc = this.boot.encoder;
		var self = this;
		var run = function (url) {
			return new Promise(function (ok, bad) {
				if (!self.worker) { self.worker = new Worker(enc.worker); }
				var w = self.worker;
				w.onmessage = function (e) {
					if (e.data.progress !== undefined) { if (onProgress) { onProgress(e.data.progress); } return; }
					if (e.data.error) { bad(new Error(e.data.error)); } else { ok(new Uint8Array(e.data.data)); }
				};
				w.onerror = function (e) { if (e && e.preventDefault) { e.preventDefault(); } self.worker = null; bad(new Error(__( 'The MP3 encoder\'s worker stopped.', 'foxfire-studio' ))); };
				w.postMessage({ id: 1, url: url, sha256: enc.sha256, pcm: pcm, rate: B.RATE, kbps: B.RULES.kbps, channels: 1 });
			});
		};
		return run(enc.url).catch(function (e) {
			if (!enc.fallback) { throw e; }
			if (self.worker) { self.worker.terminate(); self.worker = null; }
			return run(enc.fallback);
		});
	};

	/* A finished file from audio: room tone, the level, the MP3. Resolves to MP3 bytes. */
	Box.prototype.finishPcm = function (x, keep, onProgress) {
		var S = root.RRTSound, R = B.RULES, rules = this.state.rules;
		var padded = S.roomPad(x, B.RATE, rules.head, rules.tail, keep);
		var y = S.levelRms(padded.data, B.RATE, R.rmsTarget, R.ceiling);
		var fi = Math.round(B.RATE * 0.01), fo = Math.round(B.RATE * 0.05);
		for (var i = 0; i < fi && i < y.length; i++) { y[i] *= i / fi; }
		for (var j = 0; j < fo && j < y.length; j++) { y[y.length - 1 - j] *= j / fo; }
		return this.encode(y, onProgress);
	};

	/* Checks MP3 bytes as a listener (or ACX) receives them: decoded, then measured. Resolves to { check, secs, ch, x }. */
	function checkBytes(u8) {
		var S = root.RRTSound;
		return decodeMono(u8).then(function (d) {
			var ch = B.mp3Channels(u8) || d.ch;
			var c = S.check(d.x, B.RATE, 'audiobook', null, mp3Kbps(u8), {});
			return { check: c, secs: d.x.length / B.RATE, ch: ch, x: d.x };
		});
	}

	/* The bitrate in an MP3's first frame header, in kbps. */
	function mp3Kbps(u8) {
		var BR = [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 0];
		var i = 0;
		if (u8.length > 10 && u8[0] === 0x49 && u8[1] === 0x44 && u8[2] === 0x33) {
			i = 10 + ((u8[6] & 127) << 21 | (u8[7] & 127) << 14 | (u8[8] & 127) << 7 | (u8[9] & 127));
		}
		for (; i + 4 < u8.length && i < 200000; i++) {
			if (u8[i] === 0xFF && (u8[i + 1] & 0xE0) === 0xE0) { return BR[(u8[i + 2] >> 4) & 15]; }
		}
		return 0;
	}
	B.mp3Kbps = mp3Kbps;

	Box.prototype.make = function () {
		var self = this, st = this.state, S = root.RRTSound;
		if (this.busy) { return; }
		if (!S || !S.roomPad) { this.say(__( 'The sound engine is missing on this screen. Reload the page.', 'foxfire-studio' ), true); return; }
		this.busy = true;
		this.cancelled = false;
		this.report = null;
		this.render();
		var files = [], report = { title: st.title, author: st.author, made: new Date().toLocaleString(), files: [], book: [] }, parts = [];
		var steps = [];
		var total = st.rows.length + 3;
		var done = 0;
		var tagFor = function (title, n) { return { title: title, artist: st.author, album: st.title, track: n }; };
		var step = function (label) {
			done++;
			self.bar.value = Math.min(1, done / total);
			self.say(label);
			if (self.cancelled) { throw new Error(__( 'Stopped.', 'foxfire-studio' )); }
		};
		var addFile = function (name, u8, info, kind, heading) {
			files.push({ name: name, data: u8 });
			report.files.push({ name: name, items: B.fileItems(info.check, info.secs, info.ch, heading) });
			parts.push({ kind: kind, secs: info.secs, rms: info.check ? info.check.measures.rms : NaN });
		};
		/* The credits, as recorded. */
		var credit = function (kind, name, n) {
			var c = st.credits[kind];
			if (!c.book) { return Promise.resolve(); }
			return fetchBytes(c.book.url).then(function (u8) {
				return checkBytes(u8).then(function (info) {
					addFile(name, tagged(u8, tagFor(kind === 'open' ? __( 'Opening credits', 'foxfire-studio' ) : __( 'Closing credits', 'foxfire-studio' ), n)), info, kind, null);
				});
			});
		};
		var sampleFrom = function (r, info) {
			if (st.sample.post !== r.id) { return Promise.resolve(); }
			var pz = S.pauses(info.x, B.RATE, 0.3);
			var bounds = S.speechBounds(info.x, B.RATE);
			var span = B.sampleSpan(pz, st.sample.start, st.sample.len, info.secs, bounds.first / B.RATE);
			var a = Math.round(span.a * B.RATE), b = Math.round(span.b * B.RATE);
			var part = new Float32Array(info.x.subarray(a, b));
			self.say(__( 'Making the retail sample...', 'foxfire-studio' ));
			return self.finishPcm(part, null).then(function (u8) {
				return checkBytes(u8).then(function (si) {
					var name = __( 'Retail sample', 'foxfire-studio' ) + '.mp3';
					addFile(name.replace(/[^A-Za-z0-9 .]+/g, ' '), tagged(u8, tagFor(__( 'Retail sample', 'foxfire-studio' ), 0)), si, 'sample', null);
				});
			});
		};
		steps.push(function () {
			step(__( 'Opening credits...', 'foxfire-studio' ));
			return credit('open', '00 Opening credits.mp3', 0);
		});
		st.rows.forEach(function (r, i) {
			steps.push(function () {
				/* translators: 1: reading number, 2: how many, 3: title. */
				step(sprintf(__( 'Chapter %1$d of %2$d: %3$s...', 'foxfire-studio' ), i + 1, st.rows.length, r.title));
				if (r.source === 'need') {
					parts.push({ kind: 'missing' });
					report.files.push({ name: r.file, items: [{ label: __( 'Left out', 'foxfire-studio' ), value: __( 'needs an audiobook copy', 'foxfire-studio' ), ok: false, rule: __( 'one file for each reading', 'foxfire-studio' ) }] });
					return Promise.resolve();
				}
				var heading = null;
				var caps = r.captions ? fetch(r.captions, { credentials: 'same-origin' }).then(function (x) { return x.ok ? x.text() : ''; }).catch(function () { return ''; }) : Promise.resolve('');
				var bytes;
				if (r.source === 'book') {
					bytes = fetchBytes(r.book.url);
				} else {
					bytes = fetchBytes(r.url).then(decodeMono).then(function (d) {
						/* translators: %s: title. */
						self.say(sprintf(__( '%s: room tone, level and MP3...', 'foxfire-studio' ), r.title));
						/* A podcast MP3's ends were faded: keep at most 0.2 s of its own quiet and build the rest from its pauses. */
						return self.finishPcm(d.x, 0.2, function (p) {
							/* translators: 1: title, 2: percent. */
							self.say(sprintf(__( '%1$s: encoding, %2$d%%', 'foxfire-studio' ), r.title, Math.round(p * 100)));
						});
					});
				}
				return Promise.all([bytes, caps]).then(function (got) {
					heading = B.headingCheck(got[1], r.title);
					return checkBytes(got[0]).then(function (info) {
						addFile(r.file, tagged(got[0], tagFor(r.title, i + 1)), info, 'chapter', heading);
						return sampleFrom(r, info);
					});
				});
			});
		});
		steps.push(function () {
			step(__( 'Closing credits...', 'foxfire-studio' ));
			return credit('close', B.fileName(st.closeN, 'Closing credits'), st.closeN);
		});
		steps.push(function () {
			step(__( 'The cover and the report...', 'foxfire-studio' ));
			var cv = st.cover;
			var art = cv ? fetchBytes(cv.url).then(function (u8) {
				files.push({ name: 'Cover.' + (/png/.test(cv.mime) ? 'png' : 'jpg'), data: u8 });
			}) : Promise.resolve();
			return art.then(function () {
				report.book = B.bookItems(parts, cv);
				files.push({ name: 'Check report.html', data: new TextEncoder().encode(B.reportHtml(report)) });
			});
		});
		var chain = Promise.resolve();
		steps.forEach(function (fn) { chain = chain.then(fn); });
		chain.then(function () {
			var blob = new Blob(B.zip(files), { type: 'application/zip' });
			var url = URL.createObjectURL(blob);
			var a = document.createElement('a');
			a.href = url;
			a.setAttribute('download', B.zipName(st.slug, st.title));
			document.body.appendChild(a);
			a.click();
			a.parentNode.removeChild(a);
			root.setTimeout(function () { URL.revokeObjectURL(url); }, 120000);
			self.busy = false;
			self.report = report;
			self.zip = { url: url, name: B.zipName(st.slug, st.title), bytes: blob.size };
			self.render();
			var bad = report.book.concat.apply(report.book, report.files.map(function (f) { return f.items; })).filter(function (it) { return it.ok === false; }).length;
			/* translators: 1: file name, 2: size, 3: number of files. */
			var line = sprintf(__( 'Downloaded %1$s (%2$s, %3$d files).', 'foxfire-studio' ), self.zip.name, (blob.size / 1048576).toFixed(1) + ' MB', files.length);
			/* translators: %d: rules missed. */
			self.say(line + ' ' + (bad ? sprintf(_n( '%d check needs a look: see the report below.', '%d checks need a look: see the report below.', bad, 'foxfire-studio' ), bad) : __( 'Every check passed.', 'foxfire-studio' )), bad > 0);
		}).catch(function (e) {
			self.busy = false;
			self.render();
			/* translators: %s: what went wrong. */
			self.say(sprintf(__( 'The audiobook could not be made: %s', 'foxfire-studio' ), e.message), true);
		});
	};

	/* The report under the button. */
	Box.prototype.reportNode = function (report) {
		var wrap = el('div', 'rrt-book-report');
		var table = function (items) {
			var t = el('table', 'widefat striped rrt-book-table');
			items.forEach(function (it) {
				var tr = el('tr', it.ok === false ? 'is-bad' : (it.ok === true ? 'is-ok' : 'is-info'));
				tr.appendChild(el('td', 'rrt-book-mark', mark(it.ok)));
				tr.appendChild(el('td', '', it.label));
				tr.appendChild(el('td', '', it.value));
				tr.appendChild(el('td', 'rrt-small', it.rule));
				t.appendChild(tr);
			});
			return t;
		};
		wrap.appendChild(el('h4', 'rrt-book-h', __( 'Check report', 'foxfire-studio' )));
		wrap.appendChild(table(report.book));
		report.files.forEach(function (f) {
			wrap.appendChild(el('h4', 'rrt-book-file', f.name));
			wrap.appendChild(table(f.items));
		});
		if (this.zip) {
			var a = el('a', 'button', __( 'Download again', 'foxfire-studio' ));
			a.href = this.zip.url;
			a.setAttribute('download', this.zip.name);
			wrap.appendChild(a);
		}
		return wrap;
	};

	B.Box = Box;
	root.RRTAudiobook = B;
	if (typeof module !== 'undefined' && module.exports) { module.exports = B; }

	function start() {
		var host = document.querySelector('[data-rrt-book]');
		var bootEl = document.getElementById('rrt-book-boot');
		if (!host || !bootEl) { return; }
		var boot;
		try { boot = JSON.parse(bootEl.textContent); } catch (e) { return; }
		host.rrtBook = new Box(host, boot);
	}
	if (typeof document !== 'undefined' && document.addEventListener) {
		if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
	}
})(typeof window !== 'undefined' ? window : this);
