/*
 * Foxfire Studio: clips for social media (0.13.0).
 *
 * Turns a moment of a reading into a short captioned video: the post's image
 * softened behind a moving waveform, the title, the show, captions two or three
 * words at a time with the spoken word lit, the site's address, and an end card.
 * Square for feeds, vertical for Stories, Reels and TikTok, wide for YouTube.
 *
 * Everything runs in the browser. The video is made with WebCodecs (H.264, and
 * AAC sound, from the browser's own encoder or since 1.30.0 the AAC add-on,
 * otherwise Opus) and packed into an MP4 by Mediabunny, served from this site.
 * Word timings come from the review when the clip is made in the studio, and
 * otherwise from Whisper run on just the chosen stretch, with every word
 * spelled from the post's text.
 *
 * window.RRTClip.open(boot) opens the clip maker. The small helpers at the top
 * are pure functions, also used by the tests in Node.
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

	var C = {};

	/*
	 * ---------------------------------------------------------------------
	 * Pure helpers
	 * ---------------------------------------------------------------------
	 */

	/* A word reduced to letters, digits and inner apostrophes, for comparing. */
	var NOT_WORD = null;
	function norm(w) {
		/* 1.33.0: outside English, letters and digits of any script (C.lang is set from the clip's boot). */
		if (C.lang ? C.lang !== 'en' : false) {
			if (NOT_WORD === null) {
				try { NOT_WORD = new RegExp("[^\\p{L}\\p{M}\\p{N}']+", 'gu'); } catch (e) { NOT_WORD = /[^a-z0-9\u00E0-\u024F']+/g; }
			}
			var t = String(w);
			if (t.normalize) { t = t.normalize('NFC'); }
			return t.toLowerCase().replace(/[’‘]/g, "'").replace(NOT_WORD, '').replace(/^'+|'+$/g, '');
		}
		return String(w).toLowerCase().replace(/[’‘]/g, "'").replace(/[^a-z0-9']+/g, '').replace(/^'+|'+$/g, '');
	}
	C.norm = norm;

	/* The words of a text, keeping their punctuation for display. */
	C.tokens = function (text) {
		return String(text || '').split(/\s+/).filter(function (t) { return norm(t) !== ''; });
	};

	/* How alike two normalised words are, 0 to 1, from their edit distance. */
	function likeness(a, b) {
		if (a === b) { return 1; }
		var n = a.length;
		var m = b.length;
		if (!n || !m) { return 0; }
		var prev = [];
		var cur = [];
		var i;
		var j;
		for (j = 0; j <= m; j++) { prev[j] = j; }
		for (i = 1; i <= n; i++) {
			cur = [i];
			for (j = 1; j <= m; j++) {
				cur[j] = Math.min(prev[j] + 1, cur[j - 1] + 1, prev[j - 1] + (a.charAt(i - 1) === b.charAt(j - 1) ? 0 : 1));
			}
			prev = cur;
		}
		return 1 - prev[m] / Math.max(n, m);
	}
	C.likeness = likeness;

	/*
	 * Lines up words Whisper heard ({w, s, e}) with the post's words, and returns
	 * the post's spelling with Whisper's timings: [{t, s, e}]. A local alignment,
	 * since the clip is a short stretch somewhere inside the post. Post words in
	 * the stretch that Whisper missed get times shared out between neighbours;
	 * words Whisper heard that are not in the post are left out. When too little
	 * matches (a different text, or speech that is not in the post), Whisper's
	 * own words are used.
	 */
	C.align = function (heard, script) {
		var n = script.length;
		var m = heard.length;
		if (!m) { return []; }
		var asIs = heard.map(function (h) { return { t: h.w, s: h.s, e: h.e }; });
		if (!n) { return asIs; }
		var sn = script.map(norm);
		var hn = heard.map(function (h) { return norm(h.w); });
		var cols = m + 1;
		var H = new Int32Array((n + 1) * cols);
		var T = new Uint8Array((n + 1) * cols);
		var best = 0;
		var bi = 0;
		var bj = 0;
		var i;
		var j;
		for (i = 1; i <= n; i++) {
			for (j = 1; j <= m; j++) {
				var a = sn[i - 1];
				var b = hn[j - 1];
				/* Exact 3, close (a mishearing like "there" for "their") 2, different -1; a post word Whisper missed -1, an extra heard word -2. */
				var sc = a === b ? 3 : (likeness(a, b) >= 0.6 ? 2 : -1);
				var d = H[(i - 1) * cols + j - 1] + sc;
				var u = H[(i - 1) * cols + j] - 1;
				var l = H[i * cols + j - 1] - 2;
				var v = 0;
				var t = 0;
				if (d > v) { v = d; t = 1; }
				if (u > v) { v = u; t = 2; }
				if (l > v) { v = l; t = 3; }
				H[i * cols + j] = v;
				T[i * cols + j] = t;
				if (v > best) { best = v; bi = i; bj = j; }
			}
		}
		/* Most heard words should match for the post's spelling to be trusted. */
		if (best < m * 1.5) { return asIs; }
		var pairs = [];
		i = bi;
		j = bj;
		while (i > 0 && j > 0 && H[i * cols + j] > 0) {
			var tt = T[i * cols + j];
			if (tt === 1) { pairs.push([i - 1, j - 1]); i--; j--; }
			else if (tt === 2) { pairs.push([i - 1, -1]); i--; }
			else { j--; }
		}
		pairs.reverse();
		var out = pairs.map(function (p) {
			return p[1] >= 0 ? { t: script[p[0]], s: heard[p[1]].s, e: heard[p[1]].e } : { t: script[p[0]], s: null, e: null };
		});
		/* Share out time for script words Whisper did not mark. */
		for (var k = 0; k < out.length; k++) {
			if (out[k].s !== null) { continue; }
			var a0 = k;
			while (k < out.length && out[k].s === null) { k++; }
			var from = a0 > 0 ? out[a0 - 1].e : (k < out.length ? Math.max(0, out[k].s - 0.3 * (k - a0)) : 0);
			var to = k < out.length ? out[k].s : from + 0.3 * (k - a0);
			var step = Math.max(0.05, (to - from) / (k - a0));
			for (var q = a0; q < k; q++) {
				out[q].s = from + step * (q - a0);
				out[q].e = out[q].s + step;
			}
			k--;
		}
		/* Whisper's own words before and after the matched stretch keep their spelling. */
		var lead = heard.slice(0, pairs.length && pairs[0][1] >= 0 ? pairs[0][1] : 0).map(function (h) { return { t: h.w, s: h.s, e: h.e }; });
		var lastJ = -1;
		pairs.forEach(function (p) { if (p[1] >= 0) { lastJ = p[1]; } });
		var tail = lastJ >= 0 ? heard.slice(lastJ + 1).map(function (h) { return { t: h.w, s: h.s, e: h.e }; }) : [];
		/* Short stray bits at the edges are usually half-words cut by the clip's edge. */
		if (lead.length > 3) { out = lead.concat(out); }
		if (tail.length > 3) { out = out.concat(tail); }
		return out;
	};

	/*
	 * Caption groups for a stretch: two or three words at a time, a new group
	 * after a pause or at the end of a sentence or clause, at most about 18
	 * characters. Times are relative to the clip's start.
	 */
	C.groups = function (words, from, to, opts) {
		opts = opts || {};
		var max = opts.max || 3;
		var chars = opts.chars || 18;
		/* Square and curly brackets are an editor's marks in the post; on screen they read oddly, so they go. */
		var list = words.filter(function (w) { return w.e > from + 0.05 && w.s < to - 0.05; }).map(function (w) {
			return { t: String(w.t).replace(/[\[\]{}]/g, ''), s: Math.max(0, w.s - from), e: Math.min(to - from, w.e - from) };
		}).filter(function (w) { return '' !== w.t.trim(); });
		var out = [];
		var g = null;
		list.forEach(function (w, i) {
			var prev = i ? list[i - 1] : null;
			var brk = !g || g.words.length >= max || (g.text + ' ' + w.t).length > chars || (prev && /[.,;:!?…)"”]$/.test(prev.t)) || (prev && w.s - prev.e > 0.6);
			if (brk) {
				g = { words: [], text: '', s: w.s, e: w.e };
				out.push(g);
			}
			g.words.push(w);
			g.text = g.text ? g.text + ' ' + w.t : w.t;
			g.e = w.e;
		});
		/* Hold each group until the next begins when the gap is short, so captions do not flicker. */
		for (var k = 0; k < out.length - 1; k++) {
			if (out[k + 1].s - out[k].e < 0.5) { out[k].e = out[k + 1].s; }
		}
		return out;
	};

	/* Loudness per video frame for a stretch, 0 to 1, for the waveform. */
	C.envelope = function (samples, rate, from, to, fps) {
		var n = Math.max(1, Math.round((to - from) * fps));
		var per = rate / fps;
		var out = new Float32Array(n);
		var i;
		for (i = 0; i < n; i++) {
			var a = Math.floor((from + i / fps) * rate);
			var b = Math.min(samples.length, Math.floor(a + per));
			var sum = 0;
			var c = 0;
			for (var k = Math.max(0, a); k < b; k++) { sum += samples[k] * samples[k]; c++; }
			out[i] = c ? Math.sqrt(sum / c) : 0;
		}
		var sorted = Array.prototype.slice.call(out).sort(function (x, y) { return x - y; });
		var top = sorted[Math.floor(sorted.length * 0.95)] || 1;
		for (i = 0; i < n; i++) { out[i] = Math.min(1, out[i] / (top || 1)); }
		return out;
	};

	/*
	 * The liveliest stretch: where the voice's loudness moves most, measured
	 * as the spread of 100 ms loudness in decibels, over windows a second apart.
	 */
	C.lively = function (samples, rate, len) {
		var step = Math.floor(rate / 10);
		var db = [];
		for (var a = 0; a + step <= samples.length; a += step) {
			var sum = 0;
			for (var k = a; k < a + step; k++) { sum += samples[k] * samples[k]; }
			db.push(10 * Math.log10(sum / step + 1e-10));
		}
		var win = Math.round(len * 10);
		var best = 0;
		var bestScore = -1;
		for (var s = 0; s + win <= db.length; s += 10) {
			var part = db.slice(s, s + win).filter(function (v) { return v > -60; });
			if (part.length < win * 0.5) { continue; }
			var mean = part.reduce(function (x, y) { return x + y; }, 0) / part.length;
			var sd = Math.sqrt(part.reduce(function (x, y) { return x + (y - mean) * (y - mean); }, 0) / part.length);
			if (sd > bestScore) { bestScore = sd; best = s / 10; }
		}
		return best;
	};

	/*
	 * Where listeners replay most: the window with the most replayed 5 second
	 * stretches. Null when there are too few plays to say.
	 */
	C.replayed = function (curve, len, minPlays) {
		if (!curve || !curve.replayed || (curve.plays || 0) < (minPlays || 10)) { return null; }
		var r = curve.replayed;
		var w = Math.max(1, Math.round(len / 5));
		var best = -1;
		var at = 0;
		for (var i = 0; i + w <= r.length; i++) {
			var s = 0;
			for (var k = i; k < i + w; k++) { s += r[k] || 0; }
			if (s > best) { best = s; at = i; }
		}
		return best > 0 ? at * 5 : null;
	};

	/*
	 * Clip suggestions (1.7.0). The review's words as sentences: each word
	 * carries its sentence (sn), its block (b) and the block's kind (k: title,
	 * h or p). ps and pe say the sentence starts or ends a block.
	 */
	C.sentences = function (words) {
		var out = [];
		var cur = null;
		(words || []).forEach(function (w, i) {
			if (typeof w.sn !== 'number' || w.s === null || w.s === undefined) { return; }
			if (!cur || cur.sn !== w.sn) {
				if (cur) { out.push(cur); }
				var prev = i > 0 ? words[i - 1] : null;
				cur = { sn: w.sn, s: w.s, e: w.e, b: w.b, k: w.k || 'p', ps: !prev || prev.b !== w.b, pe: false };
			}
			cur.e = w.e;
			cur.last = String(w.t || '');
			var next = words[i + 1];
			cur.pe = !next || next.b !== w.b;
		});
		if (cur) { out.push(cur); }
		return out;
	};

	/*
	 * Where a clip starts and how long it runs: a breath before the first word
	 * and the last word's tail, kept inside the pauses either side (at most
	 * 0.15 s before and 0.25 s after, never more than 40% of the pause), so it
	 * never catches the sentence before or after. As rrt_suggest_bounds() on
	 * the site. prev and next (the end of the sentence before, the start of
	 * the one after) are optional.
	 */
	C.span = function (s, e, prev, next) {
		var hasPrev = typeof prev === 'number';
		var hasNext = typeof next === 'number';
		var lead = hasPrev ? Math.max(0, Math.min(0.15, (s - prev) * 0.4)) : 0.15;
		var tail = hasNext ? Math.max(0, Math.min(0.25, (next - e) * 0.4)) : 0.3;
		var start = Math.max(0, Math.round((s - lead) * 100) / 100);
		return { start: start, len: Math.floor((e + tail - start) * 100 + 1e-6) / 100, s: s, e: e, prev: hasPrev ? prev : null, next: hasNext ? next : null };
	};

	/* The quietest 20 ms in a stretch of the audio, in 10 ms steps: where to cut in a pause. */
	C.quiet = function (samples, rate, a, b) {
		var win = Math.max(1, Math.round(rate * 0.02));
		var step = Math.max(1, Math.round(rate * 0.01));
		var from = Math.max(0, Math.floor(a * rate));
		var to = Math.min(samples.length - win, Math.floor(b * rate));
		var best = -1;
		var bestE = Infinity;
		for (var x = from; x <= to; x += step) {
			var sum = 0;
			for (var k = x; k < x + win; k++) { sum += samples[k] * samples[k]; }
			if (sum < bestE) { bestE = sum; best = x; }
		}
		return best < 0 ? null : (best + win / 2) / rate;
	};

	/*
	 * A suggestion's edges moved onto the quietest moment of the pauses either
	 * side, heard from the audio itself, since word timings can be a little
	 * early or late. Never earlier than the sentence before ends, never later
	 * than the one after starts.
	 */
	C.settle = function (samples, rate, sp) {
		if (!sp || typeof sp.s !== 'number' || typeof sp.e !== 'number') { return sp; }
		var a = C.quiet(samples, rate, typeof sp.prev === 'number' ? Math.max(sp.prev, sp.s - 0.4) : Math.max(0, sp.s - 0.15), sp.s);
		var b = C.quiet(samples, rate, sp.e, typeof sp.next === 'number' ? Math.min(sp.next, sp.e + 0.5) : sp.e + 0.3);
		var start = null === a ? sp.start : Math.max(0, Math.round(a * 100) / 100);
		var end = null === b ? sp.start + sp.len : b;
		return { start: start, len: Math.floor((end - start) * 100 + 1e-6) / 100, s: sp.s, e: sp.e, prev: sp.prev, next: sp.next };
	};

	/*
	 * Moves a suggested moment to sentence edges: it starts at the sentence
	 * start nearest t and ends at a sentence end 30 to 60 s later, as near the
	 * wanted length as it can, a paragraph's end counting for it and a
	 * length over 45 s against it. Never ends on a title or heading, or on a
	 * lead-in such as "Declare:". Null
	 * when the reading has no such stretch.
	 */
	C.edge = function (sents, t, want) {
		if (!sents || !sents.length) { return null; }
		want = Math.max(30, want || 30);
		var i = 0;
		for (var k = 1; k < sents.length; k++) {
			if (Math.abs(sents[k].s - t) < Math.abs(sents[i].s - t)) { i = k; }
		}
		var best = null;
		var bestScore = Infinity;
		for (var j = i; j < sents.length; j++) {
			var d = sents[j].e - sents[i].s;
			if (d > 60) { break; }
			/* Never on words that lead into what follows ("Declare:", a closing dash or comma). */
			if (d < 30 || 'p' !== sents[j].k || /[:;,\u2013\u2014-]["'\u201D\u2019)\]]*$/.test(sents[j].last || '')) { continue; }
			var score = Math.abs(d - want) + (d > 45 ? d - 45 : 0) - (sents[j].pe ? 3 : 0);
			if (score < bestScore) { bestScore = score; best = j; }
		}
		return null === best ? null : C.span(sents[i].s, sents[best].e, i > 0 ? sents[i - 1].e : undefined, best < sents.length - 1 ? sents[best + 1].s : undefined);
	};

	/* Moves a start time to the quietest point within a second and a half, so a clip begins between words. */
	C.snap = function (samples, rate, t) {
		var step = Math.floor(rate / 50);
		var bestT = t;
		var bestE = Infinity;
		for (var x = Math.max(0, t - 1.5); x <= t + 1.5; x += 0.02) {
			var a = Math.floor(x * rate);
			if (a + step > samples.length) { break; }
			var sum = 0;
			for (var k = a; k < a + step; k++) { sum += Math.abs(samples[k]); }
			if (sum < bestE) { bestE = sum; bestT = x; }
		}
		return Math.max(0, Math.round(bestT * 100) / 100);
	};

	/* m:ss */
	function clock(s) {
		s = Math.max(0, Math.round(s));
		return Math.floor(s / 60) + ':' + (s % 60 < 10 ? '0' : '') + (s % 60);
	}
	C.clock = clock;

	/* "1m05s", for file and campaign names. */
	C.stamp = function (s) {
		s = Math.max(0, Math.round(s));
		return Math.floor(s / 60) + 'm' + (s % 60 < 10 ? '0' : '') + (s % 60) + 's';
	};

	/* The tagged link for a clip: the channel in the short tag, the clip in utm_campaign. */
	C.link = function (postUrl, param, channel, campaign) {
		var u;
		try { u = new URL(postUrl); } catch (e) { return postUrl; }
		if (channel) { u.searchParams.set(param || 'src', channel); }
		if (campaign) { u.searchParams.set('utm_campaign', campaign); }
		return u.toString();
	};

	/* Shapes, in pixels. */
	C.shapes = {
		square: { w: 1080, h: 1080, label: __( 'Square', 'foxfire-studio' ), note: __( 'for feeds', 'foxfire-studio' ) },
		vertical: { w: 1080, h: 1920, label: __( 'Vertical', 'foxfire-studio' ), note: __( 'for Stories, Reels and TikTok', 'foxfire-studio' ) },
		wide: { w: 1920, h: 1080, label: __( 'Wide', 'foxfire-studio' ), note: __( 'for YouTube', 'foxfire-studio' ) }
	};

	/* Lines of text that fit a width, at most a number of lines, the last ending in an ellipsis if cut. */
	function wrap(ctx, text, width, maxLines) {
		var words = String(text).split(/\s+/);
		var lines = [];
		var line = '';
		for (var i = 0; i < words.length; i++) {
			var test = line ? line + ' ' + words[i] : words[i];
			if (ctx.measureText(test).width > width && line) {
				lines.push(line);
				line = words[i];
				if (lines.length === maxLines) { break; }
			} else {
				line = test;
			}
		}
		if (lines.length < maxLines && line) { lines.push(line); }
		if (lines.length === maxLines && i < words.length) {
			var last = lines[maxLines - 1];
			while (last && ctx.measureText(last + '…').width > width) { last = last.replace(/\s*\S+$/, ''); }
			lines[maxLines - 1] = last + '…';
		}
		return lines;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Drawing
	 * ---------------------------------------------------------------------
	 */

	/* Where things go in each shape. */
	function layout(shape) {
		if ('vertical' === shape) {
			return { w: 1080, h: 1920, card: [210, 300, 660], show: [540, 220], title: [540, 1090, 900, 2, 58], wave: [140, 1260, 800, 150], cap: [540, 1540, 940, 92], dom: [540, 1800, 38] };
		}
		if ('wide' === shape) {
			return { w: 1920, h: 1080, card: [140, 230, 620], show: [1340, 230], title: [1340, 320, 960, 2, 60], wave: [860, 480, 960, 130], cap: [1340, 760, 1000, 80], dom: [1340, 990, 34] };
		}
		return { w: 1080, h: 1080, card: [370, 120, 340], show: [540, 80], title: [540, 520, 940, 1, 50], wave: [190, 600, 700, 110], cap: [540, 840, 960, 76], dom: [540, 1020, 32] };
	}

	function rounded(ctx, x, y, w, h, r) {
		ctx.beginPath();
		ctx.moveTo(x + r, y);
		ctx.arcTo(x + w, y, x + w, y + h, r);
		ctx.arcTo(x + w, y + h, x, y + h, r);
		ctx.arcTo(x, y + h, x, y, r);
		ctx.arcTo(x, y, x + w, y, r);
		ctx.closePath();
	}

	function hexA(hex, a) {
		var h = String(hex || '#000000').replace('#', '');
		if (h.length === 3) { h = h.charAt(0) + h.charAt(0) + h.charAt(1) + h.charAt(1) + h.charAt(2) + h.charAt(2); }
		var n = parseInt(h, 16) || 0;
		return 'rgba(' + ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + a + ')';
	}

	/* The parts that never move: background, picture, show and title. */
	function staticLayer(o, shape, img) {
		var L = layout(shape);
		var cv = document.createElement('canvas');
		cv.width = L.w;
		cv.height = L.h;
		var x = cv.getContext('2d');
		var P = o.palette;
		x.fillStyle = P.bg;
		x.fillRect(0, 0, L.w, L.h);
		if (img) {
			var sc = Math.max(L.w / img.width, L.h / img.height) * 1.15;
			x.save();
			x.filter = 'blur(48px) brightness(0.42) saturate(1.15)';
			x.drawImage(img, (L.w - img.width * sc) / 2, (L.h - img.height * sc) / 2, img.width * sc, img.height * sc);
			x.restore();
		} else {
			var gr = x.createRadialGradient(L.w * 0.5, L.h * 0.3, 10, L.w * 0.5, L.h * 0.4, Math.max(L.w, L.h) * 0.8);
			gr.addColorStop(0, hexA(P.accent2, 0.55));
			gr.addColorStop(1, hexA(P.bg, 1));
			x.fillStyle = gr;
			x.fillRect(0, 0, L.w, L.h);
		}
		/* A gentle darkening toward the bottom keeps captions readable on any picture. */
		var sh = x.createLinearGradient(0, 0, 0, L.h);
		sh.addColorStop(0, hexA(P.bg, 0.15));
		sh.addColorStop(1, hexA(P.bg, 0.7));
		x.fillStyle = sh;
		x.fillRect(0, 0, L.w, L.h);

		var cx = L.card[0];
		var cy = L.card[1];
		var cs = L.card[2];
		x.save();
		x.shadowColor = 'rgba(0,0,0,0.5)';
		x.shadowBlur = 40;
		rounded(x, cx, cy, cs, cs, 28);
		x.fillStyle = P.panel;
		x.fill();
		x.restore();
		x.save();
		rounded(x, cx, cy, cs, cs, 28);
		x.clip();
		if (img) {
			var s2 = Math.max(cs / img.width, cs / img.height);
			x.drawImage(img, cx + (cs - img.width * s2) / 2, cy + (cs - img.height * s2) / 2, img.width * s2, img.height * s2);
		} else {
			var g2 = x.createLinearGradient(cx, cy, cx + cs, cy + cs);
			g2.addColorStop(0, P.accent2);
			g2.addColorStop(1, P.panel);
			x.fillStyle = g2;
			x.fillRect(cx, cy, cs, cs);
			x.fillStyle = P.accent;
			x.textAlign = 'center';
			x.textBaseline = 'middle';
			x.font = Math.round(cs * 0.42) + 'px ' + o.fonts.headings;
			x.fillText(String(o.show || o.title || '').trim().charAt(0).toUpperCase(), cx + cs / 2, cy + cs / 2);
		}
		x.restore();
		rounded(x, cx, cy, cs, cs, 28);
		x.lineWidth = 3;
		x.strokeStyle = hexA(P.accent, 0.6);
		x.stroke();

		var wide = 'wide' === shape;
		x.textAlign = wide ? 'left' : 'center';
		x.textBaseline = 'alphabetic';
		var tx = wide ? 860 : L.show[0];
		x.fillStyle = P.accent;
		x.font = '600 ' + (wide ? 34 : 32) + 'px ' + o.fonts.interface;
		if ('letterSpacing' in x) { x.letterSpacing = '4px'; }
		x.fillText(String(o.show || '').toUpperCase(), tx, L.show[1]);
		if ('letterSpacing' in x) { x.letterSpacing = '0px'; }
		x.fillStyle = P.text;
		x.font = L.title[4] + 'px ' + o.fonts.headings;
		wrap(x, o.title, L.title[2], L.title[3]).forEach(function (line, i) {
			x.fillText(line, wide ? 860 : L.title[0], L.title[1] + i * L.title[4] * 1.2);
		});
		x.fillStyle = hexA(P.text, 0.75);
		x.font = '500 ' + L.dom[2] + 'px ' + o.fonts.interface;
		x.fillText(o.domain, wide ? 860 : L.dom[0], L.dom[1]);
		return cv;
	}

	/* One frame at time t (seconds from the clip's start). */
	function drawFrame(x, o, shape, st, t, fi) {
		var L = layout(shape);
		var P = o.palette;
		var wide = 'wide' === shape;
		x.drawImage(st.bg, 0, 0);

		/* Waveform: the last few frames of loudness, newest on the right. */
		var bars = 40;
		var wx = L.wave[0];
		var wy = L.wave[1];
		var ww = L.wave[2];
		var wh = L.wave[3];
		var bw = ww / bars;
		for (var b = 0; b < bars; b++) {
			var k = fi - (bars - 1 - b);
			var v = k >= 0 && k < st.env.length ? st.env[k] : 0;
			var hgt = Math.max(6, v * wh);
			x.fillStyle = b === bars - 1 ? P.accent : hexA(P.accent, 0.35 + 0.6 * b / bars);
			rounded(x, wx + b * bw + bw * 0.2, wy + (wh - hgt) / 2, bw * 0.6, hgt, Math.min(bw * 0.3, 6));
			x.fill();
		}

		/* Caption: the group being spoken, with the current word lit. */
		var g = null;
		for (var i = 0; i < st.groups.length; i++) {
			if (t >= st.groups[i].s - 0.05 && t < st.groups[i].e + 0.15) { g = st.groups[i]; }
		}
		if (g && t < o.len) {
			var fs = L.cap[3];
			x.font = '600 ' + fs + 'px ' + o.fonts.reading;
			x.textBaseline = 'alphabetic';
			var maxW = L.cap[2];
			var words = g.words;
			var lines = [[]];
			var widths = [0];
			var space = x.measureText(' ').width;
			words.forEach(function (w) {
				var ww2 = x.measureText(w.t).width;
				var li = lines.length - 1;
				if (widths[li] && widths[li] + space + ww2 > maxW) { lines.push([]); widths.push(0); li++; }
				lines[li].push({ w: w, width: ww2 });
				widths[li] += (widths[li] ? space : 0) + ww2;
			});
			var alpha = Math.min(1, (t - g.s + 0.05) / 0.12);
			x.save();
			x.globalAlpha = Math.max(0, alpha);
			x.shadowColor = 'rgba(0,0,0,0.65)';
			x.shadowBlur = 18;
			lines.forEach(function (line, li) {
				var lx = wide ? L.cap[0] - L.cap[2] / 2 + (L.cap[2] - widths[li]) / 2 : L.cap[0] - widths[li] / 2;
				var ly = L.cap[1] + (li - (lines.length - 1) / 2) * fs * 1.15;
				x.textAlign = 'left';
				line.forEach(function (it) {
					var on = t >= it.w.s - 0.03 && t < it.w.e + 0.05;
					var said = t >= it.w.e;
					x.fillStyle = on ? P.accent : (said ? P.text : hexA(P.text, 0.82));
					x.fillText(it.w.t, lx, ly);
					lx += it.width + space;
				});
			});
			x.restore();
		}

		/* Progress along the bottom edge. */
		x.fillStyle = hexA(P.text, 0.15);
		x.fillRect(0, L.h - 8, L.w, 8);
		x.fillStyle = P.accent;
		x.fillRect(0, L.h - 8, L.w * Math.min(1, t / o.len), 8);

		/* End card. */
		if (t >= o.len) {
			var a = Math.min(1, (t - o.len) / 0.4);
			x.save();
			x.globalAlpha = a;
			x.drawImage(st.bg, 0, 0);
			x.fillStyle = hexA(P.bg, 1);
			x.fillRect(0, 0, L.w, L.h);
			x.textAlign = 'center';
			x.fillStyle = P.text;
			var big = wide ? 64 : 66;
			x.font = big + 'px ' + o.fonts.reading;
			x.fillText(o.endLine, L.w / 2, L.h / 2 - big * 0.9);
			x.fillStyle = P.accent;
			x.font = '700 ' + (wide ? 84 : 78) + 'px ' + o.fonts.headings;
			var dw = x.measureText(o.domain).width;
			if (dw > L.w - 120) { x.font = '700 ' + Math.floor((wide ? 84 : 78) * (L.w - 120) / dw) + 'px ' + o.fonts.headings; }
			x.fillText(o.domain, L.w / 2, L.h / 2 + 40);
			x.fillStyle = hexA(P.text, 0.75);
			x.font = '500 34px ' + o.fonts.interface;
			wrap(x, o.title, L.w - 200, 2).forEach(function (line, i) {
				x.fillText(line, L.w / 2, L.h / 2 + 130 + i * 44);
			});
			x.restore();
		}
	}
	C._drawFrame = drawFrame;
	C._staticLayer = staticLayer;

	/*
	 * ---------------------------------------------------------------------
	 * Audio, Whisper and encoding
	 * ---------------------------------------------------------------------
	 */

	function OAC() { return window.OfflineAudioContext || window.webkitOfflineAudioContext; }

	/* A stretch of mono audio at a new rate. */
	function resample(samples, rate, from, to, outRate) {
		var a = Math.max(0, Math.floor(from * rate));
		var b = Math.min(samples.length, Math.ceil(to * rate));
		var part = samples.subarray(a, Math.max(a + 1, b));
		var Ctx = OAC();
		var len = Math.max(1, Math.ceil(part.length * outRate / rate));
		var off = new Ctx(1, len, outRate);
		var buf = off.createBuffer(1, part.length, rate);
		buf.copyToChannel(part, 0);
		var src = off.createBufferSource();
		src.buffer = buf;
		src.connect(off.destination);
		src.start(0);
		return off.startRendering().then(function (r) { return new Float32Array(r.getChannelData(0)); });
	}

	/*
	 * Clips are leveled to -14 LUFS (1.6.0), the level Instagram, TikTok,
	 * Facebook and YouTube play everything at, with peaks under -1.5 dBTP
	 * (AAC and Opus lift peaks a little). Raised by 12 dB at most. Without
	 * the sound engine on the page, the clip keeps the reading's own level.
	 */
	var CLIP_LUFS = -14;
	function clipLevel(pcm) {
		var S = root.RRTSound;
		if (!S || !S.toLoudness) { return pcm; }
		try { return S.toLoudness(pcm, 48000, CLIP_LUFS, -1.5, 12).data; } catch (e) { return pcm; }
	}
	C.CLIP_LUFS = CLIP_LUFS;
	C.clipLevel = clipLevel;

	/* Decodes an audio file to mono samples. */
	function decode(url) {
		return fetch(url, { credentials: 'same-origin' }).then(function (r) {
			if (!r.ok) { throw new Error(sprintf( /* translators: %s: r.status. */ __( 'the reading could not be loaded (%s)', 'foxfire-studio' ), r.status )); }
			return r.arrayBuffer();
		}).then(function (buf) {
			var Ctx = OAC();
			var off = new Ctx(1, 1, 48000);
			return off.decodeAudioData(buf);
		}).then(function (ab) {
			var n = ab.length;
			var out = new Float32Array(n);
			for (var c = 0; c < ab.numberOfChannels; c++) {
				var d = ab.getChannelData(c);
				for (var i = 0; i < n; i++) { out[i] += d[i] / ab.numberOfChannels; }
			}
			return { samples: out, rate: ab.sampleRate, duration: ab.duration };
		});
	}

	/*
	 * Whisper, the same model and files as the review studio, in a worker, so
	 * the page stays responsive. Loaded only when a clip needs word timings.
	 */
	/*
	 * The site's Whisper settings with the studio's phone rule added (can.js
	 * RRTCan.lowMem, review.js oldSafari): on an iPhone the lighter build on one thread,
	 * as the studio's review uses there. Without it Safari closed the page.
	 */
	function whisperCfg(cfg, nav, ans) {
		var ua = (nav && nav.userAgent) || '';
		var out = {};
		Object.keys(cfg || {}).forEach(function (k) { out[k] = cfg[k]; });
		/* 1.31.0: the studio's own phone rule, from can.js, so the two always agree. */
		var Can = root.RRTCan;
		if (!ans) { ans = Can ? Can.now() : { touch: false, memory: nav && nav.deviceMemory ? nav.deviceMemory : 0 }; }
		out.lowMem = Can ? Can.lowMem(ans) : (ans.memory ? ans.memory < 4 : !!ans.touch);
		out.oldSafari = /Safari/.test(ua) && !/Chrome|Chromium|Edg|Android/.test(ua) && !(nav && nav.gpu);
		/* 1.33.0: a reading in another language is heard by the multilingual standard model, pinned like the review's. */
		if (out.language && out.multi) {
			out.model = out.multi.model;
			out.host = out.multi.host || '';
			out.fallback = '';
			out.rev = out.multi.rev || '';
		}
		return out;
	}
	C.whisperCfg = whisperCfg;
	var engine = null;
	function stopWhisper() {
		if (engine) {
			try { engine.terminate(); } catch (e) { /* already gone */ }
			engine = null;
		}
	}
	function whisper(audio16k, cfg, onStatus) {
		return new Promise(function (resolve, reject) {
			if (!engine) {
				/* The same worker as the review studio's, from whisper.js (0.25.1). */
				try {
					if (!root.RRTWhisper) { throw new Error('whisper.js is not loaded'); }
					engine = root.RRTWhisper.start();
				} catch (e) {
					reject(new Error(__( 'this browser cannot run Whisper', 'foxfire-studio' )));
					return;
				}
			}
			var files = {};
			engine.onmessage = function (e) {
				var d = e.data;
				if ('load' === d.type) {
					if ('progress' === d.p.status && d.p.total) { files[d.p.file] = [d.p.loaded, d.p.total]; }
					var got = 0;
					var all = 0;
					Object.keys(files).forEach(function (f) { got += files[f][0]; all += files[f][1]; });
					if (all) { onStatus(sprintf( /* translators: 1: Math.round(got / 1048576), 2: Math.round(all / 1048576). */ __( 'Loading Whisper: %1$s of %2$s MB. The browser keeps it after this.', 'foxfire-studio' ), Math.round(got / 1048576), Math.round(all / 1048576) )); }
				} else if ('working' === d.type) {
					onStatus(__( 'Whisper is listening to the clip for word timings...', 'foxfire-studio' ));
				} else if ('done' === d.type) {
					/* Whisper is let go at once: on a phone its memory and the clip's together can close the page. */
					stopWhisper();
					resolve(d.chunks);
				} else if ('error' === d.type) {
					stopWhisper();
					reject(new Error(d.message));
				}
			};
			engine.onerror = function () { stopWhisper(); reject(new Error(__( 'Whisper could not start in this browser', 'foxfire-studio' ))); };
			onStatus(__( 'Preparing Whisper...', 'foxfire-studio' ));
			engine.postMessage({ cfg: whisperCfg(cfg, root.navigator), audio: audio16k }, [audio16k.buffer]);
		});
	}

	/* Whisper's chunks as words with times. */
	function heardWords(chunks, offset) {
		var out = [];
		chunks.forEach(function (c) {
			var ts = c.timestamp || [0, 0];
			var s = typeof ts[0] === 'number' ? ts[0] : 0;
			var e = typeof ts[1] === 'number' ? ts[1] : s + 0.3;
			String(c.text || '').trim().split(/\s+/).forEach(function (w) {
				if (norm(w) !== '') { out.push({ w: w, s: s + offset, e: e + offset }); }
			});
		});
		return out;
	}
	C.heardWords = heardWords;

	/*
	 * The MP4 writer (1.30.0): Mediabunny, in place of mp4-muxer. The page
	 * keeps its own video encoder (its settings, its pacing by the encoder's
	 * queue) and hands each finished piece to Mediabunny, which works out
	 * decoding times by itself, so pieces that arrive out of display order
	 * (Firefox's H.264 High and Main use B-frames) are stored as they are and
	 * every browser can use High profile. The sound goes to Mediabunny as raw
	 * samples and Mediabunny encodes it: with the browser's own AAC encoder
	 * where there is one, else with the AAC add-on (FFmpeg's encoder as
	 * WebAssembly, loaded only then), else as Opus.
	 */
	C.FPS_CLIP = 30;
	var aacWait = null;
	C.aacAddOn = false;

	/* The add-on's address: beside Mediabunny's own script, with the same version. */
	C.aacUrl = function () {
		if (root.RRTClipAac) { return root.RRTClipAac; }
		var s = document.querySelector('script[src*="vendor/mediabunny/mediabunny.min.js"]');
		return s ? s.src.replace('mediabunny.min.js', 'mediabunny-aac-encoder.min.js') : '';
	};

	function loadScript(url) {
		return new Promise(function (resolve, reject) {
			var s = document.createElement('script');
			s.src = url;
			s.onload = function () { resolve(); };
			s.onerror = function () { reject(new Error('could not load ' + url)); };
			document.head.appendChild(s);
		});
	}

	/* Whether AAC can be made here, loading the add-on when the browser has no AAC of its own. */
	function aacReady(ch) {
		var MB = root.Mediabunny;
		var opts = { numberOfChannels: ch, sampleRate: 48000 };
		return MB.canEncodeAudio('aac', opts).then(function (ok) {
			if (ok) { return true; }
			if (!aacWait) {
				var url = C.aacUrl();
				aacWait = (url ? loadScript(url) : Promise.reject(new Error('no add-on'))).then(function () {
					if (root.MediabunnyAacEncoder && !C.aacAddOn) {
						root.MediabunnyAacEncoder.registerAacEncoder();
						C.aacAddOn = true;
					}
				})['catch'](function () { return null; });
			}
			return aacWait.then(function () { return MB.canEncodeAudio('aac', opts); });
		})['catch'](function () { return false; });
	}

	/*
	 * How far the sound's first encoded piece starts before its first sample
	 * (an AAC encoder's start-up delay, 1,024 samples, about 21 ms). Mediabunny
	 * writes an edit list for a track whose first piece is not at 0, and
	 * Spotify's and Instagram's specs refuse edit lists, so the samples are
	 * handed over this much later: the first piece then sits at 0, no edit
	 * list is written, and the sound plays at most about 21 ms late (Spotify
	 * allows 50 ms). Found once per page by encoding a little silence.
	 */
	var leadSeen = {};
	function soundLead(codec, ch, bitrate) {
		var key = codec + '|' + ch;
		if (key in leadSeen) { return Promise.resolve(leadSeen[key]); }
		var MB = root.Mediabunny;
		var first = null;
		var out = new MB.Output({ format: new MB.Mp4OutputFormat(), target: new MB.BufferTarget() });
		var src = new MB.AudioSampleSource({ codec: codec, bitrate: bitrate, onEncodedPacket: function (p) { if (null === first) { first = p.timestamp; } } });
		out.addAudioTrack(src);
		var chain = out.start();
		[0, 1, 2, 3].forEach(function (n) {
			chain = chain.then(function () {
				var s = new MB.AudioSample({ format: 'f32-planar', sampleRate: 48000, numberOfFrames: 1024, numberOfChannels: ch, timestamp: n * 1024 / 48000, data: new Float32Array(1024 * ch) });
				return src.add(s).then(function () { s.close(); });
			});
		});
		return chain.then(function () { return out.finalize(); }).then(function () {
			leadSeen[key] = null !== first && first < 0 ? -first : 0;
			return leadSeen[key];
		}, function () {
			out.cancel()['catch'](function () { return null; });
			leadSeen[key] = 0;
			return 0;
		});
	}
	C.soundLead = soundLead;

	/* The sound for a clip or video: AAC where it can be made here, else Opus; with its lead. */
	function pickSound(ch, aacBps, opusBps) {
		return aacReady(ch).then(function (aac) {
			if (aac) { return { codec: 'aac', bitrate: aacBps, sound: 'aac', addOn: C.aacAddOn }; }
			return root.Mediabunny.canEncodeAudio('opus', { numberOfChannels: ch, sampleRate: 48000 }).then(function (ok) {
				if (!ok) { throw new Error(__( 'this browser cannot make the sound for a video', 'foxfire-studio' )); }
				return { codec: 'opus', bitrate: opusBps, sound: 'opus', addOn: false };
			});
		}).then(function (plan) {
			return soundLead(plan.codec, ch, plan.bitrate).then(function (lead) { plan.lead = lead; return plan; });
		});
	}
	C.pickSound = pickSound;

	/*
	 * One MP4 being written. o: fps, sound (from pickSound), stream (an
	 * opened file to write to, else the file is kept in memory), chunkSize,
	 * MB (Mediabunny, for the tests). Pieces are queued in order and never
	 * waited on by the encoder's callback; any refusal is kept and reported
	 * (onFail), and check( frames ) refuses a file missing any frame.
	 */
	function mp4Writer(o, onFail) {
		var MB = o.MB || root.Mediabunny;
		var w = { frames: 0, got: 0, lost: 0, pending: 0, err: null, firstKey: false };
		var target = o.stream ? new MB.StreamTarget(o.stream, { chunked: true, chunkSize: o.chunkSize || 8388608 }) : new MB.BufferTarget();
		var out = new MB.Output({ format: new MB.Mp4OutputFormat({ fastStart: o.stream ? false : 'in-memory' }), target: target });
		var vs = new MB.EncodedVideoPacketSource('avc');
		var as = new MB.AudioSampleSource({ codec: o.sound.codec, bitrate: o.sound.bitrate });
		out.addVideoTrack(vs, { frameRate: o.fps });
		out.addAudioTrack(as);
		var lead = o.sound.lead || 0;
		var vq = out.start();
		var aq = vq;
		function fail(e) {
			if (!w.err) {
				w.err = e;
				if (onFail) { onFail(lostError(w)); }
			}
		}
		/*
		 * The picture's first frame is drawn at 0, but some encoders hand every
		 * piece back later than its frame (Firefox on Windows: one frame late).
		 * Mediabunny would then start the picture after the sound with an edit
		 * list, so the first piece's time is taken off every piece.
		 */
		var shift = null;
		w.addVideoChunk = function (c, m) {
			var p;
			try {
				p = MB.EncodedPacket.fromEncodedChunk(c);
				if (null === shift) {
					w.firstKey = 'key' === p.type;
					shift = w.firstKey && p.timestamp > 0 ? p.timestamp : 0;
				}
				if (shift) { p = p.clone({ timestamp: Math.max(0, p.timestamp - shift) }); }
			} catch (e) { fail(e); return; }
			w.got++;
			w.pending++;
			vq = vq.then(function () { return vs.add(p, m); }).then(function () { w.frames++; w.pending--; }, function (e) { w.pending--; fail(e); });
		};
		/* Raw sound, planar: ch channels of nf samples each, starting at t seconds. */
		w.addAudio = function (data, nf, ch, rate, t) {
			var s;
			try { s = new MB.AudioSample({ format: 'f32-planar', sampleRate: rate, numberOfFrames: nf, numberOfChannels: ch, timestamp: t + lead, data: data }); } catch (e) { fail(e); return; }
			w.pending++;
			aq = aq.then(function () { return as.add(s); }).then(function () { s.close(); w.pending--; }, function (e) { s.close(); w.pending--; fail(e); });
		};
		/* Until every queued piece is written. */
		w.idle = function () { return Promise.all([vq, aq]); };
		/*
		 * Until the encoder has handed back every frame, or wait ms have passed
		 * (Safari on iPhone can say it is done a moment before its last piece
		 * arrives). Call it after the encoder's flush and before closing it.
		 */
		w.settle = function (frames, wait) {
			var until = Date.now() + (null == wait ? 2000 : wait);
			return new Promise(function (res) {
				(function look() {
					if (w.got >= frames || w.err || Date.now() >= until) { res(); return; }
					setTimeout(look, 50);
				})();
			});
		};
		/*
		 * A file missing frames is refused, except a tiny loss in a long file
		 * (at most 1 in 1,000, at least 1 from 300 frames) that still starts
		 * with its first frame: every piece keeps its own time, so the frame
		 * before a missing one simply stays a frame longer. w.lost says how many.
		 */
		w.allowed = function (frames) {
			return frames >= 300 && w.firstKey ? Math.max(1, Math.floor(frames / 1000)) : 0;
		};
		w.check = function (frames) {
			if (w.err || w.frames + w.allowed(frames) < frames) { throw lostError(w, frames); }
			w.lost = Math.max(0, frames - w.frames);
		};
		/* Finishes the file: the bytes when kept in memory, else null (the opened file is closed by Mediabunny). */
		w.finish = function (frames) {
			return w.idle().then(function () {
				w.check(frames);
				return out.finalize();
			}).then(function () { return o.stream ? null : target.buffer; });
		};
		w.cancel = function () { return Promise.resolve().then(function () { return out.cancel(); })['catch'](function () { return null; }); };
		return w;
	}
	function lostError(g, frames) {
		if (frames && g.frames < frames && !g.err) {
			return new Error(sprintf( /* translators: 1: frames lost, 2: frames in all. */ __( 'the encoder lost %1$d of %2$d frames, so the file was not finished', 'foxfire-studio' ), frames - g.frames, frames ));
		}
		return new Error(sprintf( /* translators: %s: the MP4 writer's reason. */ __( 'a piece of the video could not be written (%s), so the file was not finished', 'foxfire-studio' ), g.err && g.err.message ? g.err.message : String(g.err) ));
	}
	C.mp4Writer = mp4Writer;

	/*
	 * How many tracks of an MP4 carry an edit list (an "edts" box inside a
	 * "trak" inside "moov"). read( pos, len ) gives a promise of bytes, so the
	 * same walk serves a file in memory and one in private storage.
	 */
	function editLists(read, size) {
		function u32(b, i) { return ((b[i] << 24) >>> 0) + (b[i + 1] << 16) + (b[i + 2] << 8) + b[i + 3]; }
		function type(b, i) { return String.fromCharCode(b[i], b[i + 1], b[i + 2], b[i + 3]); }
		function find(pos, end, name) {
			if (pos + 8 > end) { return Promise.resolve(null); }
			return read(pos, 16).then(function (h) {
				var len = u32(h, 0);
				var hdr = 8;
				if (1 === len) { len = u32(h, 8) * 4294967296 + u32(h, 12); hdr = 16; }
				if (0 === len) { len = end - pos; }
				if (len < 8) { return null; }
				if (type(h, 4) === name) { return { pos: pos, len: len, hdr: hdr }; }
				return find(pos + len, end, name);
			});
		}
		return find(0, size, 'moov').then(function (moov) {
			if (!moov) { return 0; }
			return read(moov.pos, moov.len).then(function (b) {
				var n = 0;
				var i = moov.hdr;
				while (i + 8 <= b.length) {
					var len = u32(b, i);
					if (len < 8) { break; }
					if ('trak' === type(b, i + 4)) {
						var j = i + 8;
						while (j + 8 <= i + len) {
							var l2 = u32(b, j);
							if (l2 < 8) { break; }
							if ('edts' === type(b, j + 4)) { n++; }
							j += l2;
						}
					}
					i += len;
				}
				return n;
			});
		});
	}
	C.editLists = function (bytes) {
		var u = bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes);
		return editLists(function (pos, len) { return Promise.resolve(u.subarray(pos, Math.min(u.length, pos + len))); }, u.length);
	};
	C.fileEditLists = function (file) {
		return editLists(function (pos, len) {
			return file.slice(pos, Math.min(file.size, pos + len)).arrayBuffer().then(function (ab) { return new Uint8Array(ab); });
		}, file.size);
	};

	/* The first of these encoder settings the browser accepts. */
	function firstSupported(tries) {
		var i = 0;
		function next() {
			if (i >= tries.length) { return Promise.reject(new Error(__( 'this browser cannot make H.264 video', 'foxfire-studio' ))); }
			var cfg = tries[i++];
			return window.VideoEncoder.isConfigSupported(cfg).then(function (r) { return r.supported ? cfg : next(); }, next);
		}
		return next();
	}
	C.firstSupported = firstSupported;

	/* The first video setting this browser can encode (High profile first), and the sound. */
	function pickCodecs(w, h) {
		var videos = ['avc1.640028', 'avc1.4d0028', 'avc1.42e028'].map(function (codec) {
			return { codec: codec, width: w, height: h, bitrate: w * h > 1200000 ? 8000000 : 6000000, framerate: 30, avc: { format: 'avc' } };
		});
		return Promise.all([firstSupported(videos), pickSound(1, 128000, 128000)]).then(function (r) {
			return { video: r[0], audio: r[1], sound: r[1].sound };
		});
	}

	/* Whether this browser can make clips at all (1.30.0: RRTCan's answer when it is loaded). */
	C.supported = function () {
		if (root.RRTCan) { return root.RRTCan.now().clips && !!root.Mediabunny; }
		return !!(window.VideoEncoder && window.VideoFrame && window.WebAssembly && root.Mediabunny);
	};

	/*
	 * Renders one clip to an MP4.
	 *
	 * @param o      Clip settings: palette, fonts, title, show, domain, endLine, len.
	 * @param shape  square, vertical or wide.
	 * @param st     image (or null), groups, env, audio (Float32 at 48 kHz, the clip plus its tail).
	 */
	function render(o, shape, st, onProgress) {
		var L = layout(shape);
		var fps = C.FPS_CLIP;
		var total = o.len + 3;
		var frames = Math.round(total * fps);
		var cv = document.createElement('canvas');
		cv.width = L.w;
		cv.height = L.h;
		var x = cv.getContext('2d', { alpha: false });
		var bg = staticLayer(o, shape, st.image);
		var state = { bg: bg, env: st.env, groups: st.groups };
		return pickCodecs(L.w, L.h).then(function (codecs) {
			var failed = null;
			var writer = mp4Writer({ fps: fps, sound: codecs.audio }, function (e) { failed = failed || e; });
			var venc = new window.VideoEncoder({
				output: writer.addVideoChunk,
				error: function (e) { failed = failed || e; }
			});
			venc.configure(codecs.video);

			/* Sound: the clip, a fade at each end, then silence under the rest of the end card. */
			var rate = 48000;
			var need = Math.round(total * rate);
			var pcm = new Float32Array(need);
			pcm.set(st.audio.subarray(0, Math.min(need, st.audio.length)));
			var fadeIn = Math.round(0.15 * rate);
			var fadeOut = Math.round((o.exact ? 0.04 : 1.2) * rate);
			var endAt = Math.min(need, Math.round((o.len + (o.exact ? 0 : 1.2)) * rate));
			var i;
			for (i = 0; i < fadeIn && i < need; i++) { pcm[i] *= i / fadeIn; }
			for (i = Math.max(0, endAt - fadeOut); i < need; i++) { pcm[i] *= i >= endAt ? 0 : (endAt - i) / fadeOut; }
			for (var a = 0; a < need; a += 1024) {
				var nf = Math.min(1024, need - a);
				writer.addAudio(pcm.subarray(a, a + nf), nf, 1, rate, a / rate);
			}

			var f = 0;
			function step() {
				if (failed) { return Promise.reject(failed); }
				var until = Math.min(frames, f + 10);
				for (; f < until; f++) {
					drawFrame(x, o, shape, state, f / fps, f);
					var vf = new window.VideoFrame(cv, { timestamp: Math.round(f * 1e6 / fps), duration: Math.round(1e6 / fps) });
					venc.encode(vf, { keyFrame: f % 60 === 0 });
					vf.close();
				}
				if (onProgress) { onProgress(f / frames); }
				if (f >= frames) { return Promise.resolve(); }
				/* Waits on the encoder and yields by message, never by timer: Chrome slows timers to once a second when the window is not in front (1.18.0). */
				return new Promise(function (res) {
					function check() {
						if (venc.encodeQueueSize > 6 && 'configured' === venc.state) { return; }
						venc.removeEventListener('dequeue', check);
						var ch = new MessageChannel();
						ch.port1.onmessage = function () { ch.port1.close(); res(); };
						ch.port2.postMessage(0);
					}
					venc.addEventListener('dequeue', check);
					check();
				}).then(step);
			}
			return step().then(function () {
				return venc.flush();
			}).then(function () {
				return writer.settle(frames);
			}).then(function () {
				if (failed) { throw failed; }
				venc.close();
				return writer.finish(frames);
			}).then(function (buf) {
				return C.editLists(buf).then(function (edits) {
					return { blob: new Blob([buf], { type: 'video/mp4' }), sound: codecs.sound, addOn: codecs.audio.addOn, codec: codecs.video.codec, frames: writer.frames, lost: writer.lost, edits: edits, w: L.w, h: L.h };
				});
			}, function (err) {
				try { if ('closed' !== venc.state) { venc.close(); } } catch (e) { /* already closed */ }
				return writer.cancel().then(function () { throw err; });
			});
		});
	}
	C._render = render;

	function loadImage(url) {
		return new Promise(function (resolve) {
			if (!url) { resolve(null); return; }
			var im = new Image();
			im.crossOrigin = 'anonymous';
			im.onload = function () { resolve(im); };
			im.onerror = function () { resolve(null); };
			im.src = url;
		});
	}

	/* The family names in a CSS font stack, loaded before drawing so the canvas uses them. */
	function loadFonts(fonts) {
		if (!document.fonts || !document.fonts.load) { return Promise.resolve(); }
		var jobs = [];
		Object.keys(fonts).forEach(function (k) {
			['400', '600', '700'].forEach(function (wgt) { jobs.push(document.fonts.load(wgt + ' 40px ' + fonts[k]).catch(function () {})); });
		});
		return Promise.all(jobs);
	}

	/*
	 * ---------------------------------------------------------------------
	 * The clip maker
	 * ---------------------------------------------------------------------
	 */

	function el(tag, cls, text) {
		var e = document.createElement(tag);
		if (cls) { e.className = cls; }
		if (text !== undefined) { e.textContent = text; }
		return e;
	}

	/*
	 * Opens the clip maker.
	 *
	 * boot: audio (URL), title, show, domain, link (the post's address), image
	 * (featured image URL), palette, fonts, script (the post's text), whisper
	 * (the studio's Whisper settings), src (the short tag's name), upload
	 * ({ rest, nonce, post } when the user may add to the Media Library),
	 * curve (listening curve for suggestions), words (optional [{t, s, e}]
	 * from a review, with sn, b and k for sentences since 1.7.0), start
	 * (optional suggested start), len (optional exact length, 1.7.0), suggest
	 * (optional [{start, len, label, why}], the site's suggestions, 1.7.0),
	 * slug.
	 */
	C.open = function (boot) {
		C.lang = boot && boot.whisper && boot.whisper.language ? boot.whisper.language : 'en';
		var o = {
			palette: boot.palette,
			fonts: boot.fonts,
			title: boot.title || '',
			show: boot.show || '',
			domain: boot.domain || window.location.hostname,
			endLine: __( 'Hear the whole reading at', 'foxfire-studio' ),
			len: 30
		};
		var old = document.querySelector('.rrt-clip');
		if (old) { old.parentNode.removeChild(old); }
		var wrapEl = el('div', 'rrt-clip');
		wrapEl.setAttribute('role', 'dialog');
		wrapEl.setAttribute('aria-modal', 'true');
		wrapEl.setAttribute('aria-label', __( 'Make a clip', 'foxfire-studio' ));
		var box = el('div', 'rrt-clip-box');
		wrapEl.appendChild(box);
		var head = el('div', 'rrt-clip-head');
		head.appendChild(el('h2', '', sprintf( /* translators: %s: o.title. */ __( 'Make a clip: %s', 'foxfire-studio' ), o.title )));
		var closeBtn = el('button', 'rrt-clip-close', '×');
		closeBtn.type = 'button';
		closeBtn.setAttribute('aria-label', __( 'Close', 'foxfire-studio' ));
		head.appendChild(closeBtn);
		box.appendChild(head);
		var status = el('p', 'rrt-clip-status', __( 'Loading the reading...', 'foxfire-studio' ));
		status.setAttribute('aria-live', 'polite');
		box.appendChild(status);
		document.body.appendChild(wrapEl);
		var urls = [];
		function close() {
			if (audio) { audio.pause(); }
			urls.forEach(function (u) { URL.revokeObjectURL(u); });
			if (wrapEl.parentNode) { wrapEl.parentNode.removeChild(wrapEl); }
			document.removeEventListener('keydown', onKey);
		}
		function onKey(e) { if ('Escape' === e.key && !busy) { close(); } }
		document.addEventListener('keydown', onKey);
		closeBtn.addEventListener('click', function () { if (!busy) { close(); } });

		/* 1.30.0: the message names the missing tool and the browsers that have it (RRTCan). */
		function cannot() {
			return root.RRTCan ? root.RRTCan.why('clips') : __( 'This browser cannot make clips here. Chrome, Edge, Safari and Firefox on a computer can.', 'foxfire-studio' );
		}
		if (!C.supported()) {
			status.textContent = cannot() || __( 'This browser cannot make clips here. Chrome, Edge, Safari and Firefox on a computer can.', 'foxfire-studio' );
			return;
		}
		if (root.RRTCan) {
			root.RRTCan.check().then(function (a) {
				var why = root.RRTCan.why('clips', a);
				if (why) { status.textContent = why; }
			});
		}

		var audio = null;
		var busy = false;
		var src = null;

		decode(boot.audio).then(function (d) {
			src = d;
			build();
		}, function (err) {
			status.textContent = sprintf( /* translators: %s: err.message. */ __( 'The clip maker could not start: %s.', 'foxfire-studio' ), err.message );
		});

		function build() {
			status.textContent = '';
			var dur = src.duration;
			var start = typeof boot.start === 'number' ? Math.min(boot.start, Math.max(0, dur - 15)) : 0;

			/* 1. The moment. */
			var s1 = el('section', 'rrt-clip-step');
			s1.appendChild(el('h3', '', __( '1. Choose the moment', 'foxfire-studio' )));
			var ov = el('canvas', 'rrt-clip-overview');
			ov.width = 1200;
			ov.height = 110;
			ov.setAttribute('aria-label', __( 'The whole reading. Click to start the clip there.', 'foxfire-studio' ));
			s1.appendChild(ov);
			var row = el('div', 'rrt-clip-row');
			var startLabel = el('label', '', __( 'Starts at ', 'foxfire-studio' ));
			var startIn = el('input');
			startIn.type = 'text';
			startIn.size = 6;
			startIn.setAttribute('inputmode', 'numeric');
			startLabel.appendChild(startIn);
			row.appendChild(startLabel);
			var lenLabel = el('label', '', __( ' Length ', 'foxfire-studio' ));
			var lenSel = el('select');
			[15, 20, 30, 45, 60].forEach(function (n) {
				var op = el('option', '', sprintf( /* translators: %s: n. */ __( '%s seconds', 'foxfire-studio' ), n ));
				op.value = String(n);
				if (30 === n) { op.selected = true; }
				lenSel.appendChild(op);
			});
			lenLabel.appendChild(lenSel);
			row.appendChild(lenLabel);
			var back = el('button', 'button', '− 5 s');
			back.type = 'button';
			var fwd = el('button', 'button', '+ 5 s');
			fwd.type = 'button';
			var play = el('button', 'button', __( 'Play this moment', 'foxfire-studio' ));
			play.type = 'button';
			row.appendChild(back);
			row.appendChild(fwd);
			row.appendChild(play);
			s1.appendChild(row);
			var sugg = el('div', 'rrt-clip-sugg');
			s1.appendChild(sugg);
			var said = el('p', 'rrt-clip-said');
			s1.appendChild(said);
			box.appendChild(s1);

			/* 2. Shapes. */
			var s2 = el('section', 'rrt-clip-step');
			s2.appendChild(el('h3', '', __( '2. Shapes', 'foxfire-studio' )));
			var shapeBoxes = {};
			Object.keys(C.shapes).forEach(function (k) {
				var lab = el('label', 'rrt-clip-check');
				var cb = el('input');
				cb.type = 'checkbox';
				cb.checked = 'wide' !== k;
				shapeBoxes[k] = cb;
				lab.appendChild(cb);
				lab.appendChild(document.createTextNode(' ' + C.shapes[k].label + ' (' + C.shapes[k].w + ' × ' + C.shapes[k].h + ') ' + C.shapes[k].note));
				s2.appendChild(lab);
			});
			box.appendChild(s2);

			/* 3. Link. */
			var s3 = el('section', 'rrt-clip-step');
			s3.appendChild(el('h3', '', __( '3. The link to share with it', 'foxfire-studio' )));
			var chRow = el('div', 'rrt-clip-row');
			var chLabel = el('label', '', __( 'Where you will post it ', 'foxfire-studio' ));
			var chSel = el('select');
			[['instagram', __( 'Instagram', 'foxfire-studio' )], ['facebook', __( 'Facebook', 'foxfire-studio' )], ['tiktok', 'TikTok'], ['youtube', 'YouTube'], ['threads', __( 'Threads', 'foxfire-studio' )], ['bluesky', __( 'Bluesky', 'foxfire-studio' )], ['other', __( 'Somewhere else', 'foxfire-studio' )]].forEach(function (c) {
				var op = el('option', '', c[1]);
				op.value = c[0];
				chSel.appendChild(op);
			});
			chLabel.appendChild(chSel);
			chRow.appendChild(chLabel);
			s3.appendChild(chRow);
			var linkRow = el('div', 'rrt-clip-row');
			var linkIn = el('input', 'rrt-clip-link');
			linkIn.type = 'text';
			linkIn.readOnly = true;
			var copy = el('button', 'button', __( 'Copy link', 'foxfire-studio' ));
			copy.type = 'button';
			linkRow.appendChild(linkIn);
			linkRow.appendChild(copy);
			s3.appendChild(linkRow);
			s3.appendChild(el('p', 'rrt-clip-note', __( 'Instagram does not make links in captions clickable: put this one in your bio or on a Story link sticker. Visits through it appear in Foxfire Studio, Sources, under Campaigns, with this clip\'s name.', 'foxfire-studio' )));
			box.appendChild(s3);

			/* Make. */
			var make = el('button', 'button button-primary rrt-clip-make', __( 'Make the clip', 'foxfire-studio' ));
			make.type = 'button';
			box.appendChild(make);
			var prog = el('progress', 'rrt-clip-prog');
			prog.max = 1;
			prog.value = 0;
			prog.hidden = true;
			box.appendChild(prog);
			var results = el('div', 'rrt-clip-results');
			box.appendChild(results);

			function len() { return Math.min(parseFloat(lenSel.value) || 30, Math.max(5, Math.floor(dur - start))); }
			/* An exact length (1.7.0): a suggestion's own length joins the menu, once. */
			function setLen(v) {
				v = Math.round(v * 100) / 100;
				var ex = lenSel.querySelector('.is-exact');
				if ([15, 20, 30, 45, 60].indexOf(v) !== -1) {
					if (ex) { lenSel.removeChild(ex); }
					lenSel.value = String(v);
					return;
				}
				if (!ex) {
					ex = el('option', 'is-exact');
					lenSel.appendChild(ex);
				}
				ex.value = String(v);
				ex.textContent = sprintf( /* translators: %s: a length in seconds, such as 38.4. */ __( '%s seconds, as suggested', 'foxfire-studio' ), v.toFixed(1) );
				lenSel.value = ex.value;
			}
			function campaign() { return 'clip-' + C.stamp(start); }
			function updateLink() { linkIn.value = C.link(boot.link, boot.src || 'src', 'other' === chSel.value ? '' : chSel.value, campaign()); }
			function wordsIn(a, b) {
				if (!boot.words) { return ''; }
				return boot.words.filter(function (w) { return w.s >= a && w.s < b; }).map(function (w) { return w.t; }).join(' ');
			}

			var peaks = (function () {
				var n = ov.width;
				var per = Math.floor(src.samples.length / n) || 1;
				var out = new Float32Array(n);
				var mx = 0;
				for (var i = 0; i < n; i++) {
					var m = 0;
					for (var k = i * per; k < (i + 1) * per && k < src.samples.length; k += 8) { m = Math.max(m, Math.abs(src.samples[k])); }
					out[i] = m;
					mx = Math.max(mx, m);
				}
				for (var j = 0; j < n; j++) { out[j] = mx ? out[j] / mx : 0; }
				return out;
			})();

			function drawOverview() {
				var x = ov.getContext('2d');
				var w = ov.width;
				var h = ov.height;
				x.clearRect(0, 0, w, h);
				var a = start / dur * w;
				var b = (start + len()) / dur * w;
				x.fillStyle = 'rgba(212,168,67,0.18)';
				x.fillRect(a, 0, b - a, h);
				for (var i = 0; i < w; i++) {
					var v = peaks[i] * (h - 10);
					x.fillStyle = i >= a && i <= b ? '#b07d10' : '#9a96a3';
					x.fillRect(i, (h - v) / 2, 1, Math.max(1, v));
				}
				if (boot.curve && boot.curve.heard && boot.curve.plays) {
					x.strokeStyle = 'rgba(91,58,140,0.9)';
					x.lineWidth = 2;
					x.beginPath();
					boot.curve.heard.forEach(function (v2, i2) {
						var px = (i2 * 5 + 2.5) / dur * w;
						var py = h - 4 - (v2 / boot.curve.plays) * (h - 8);
						if (0 === i2) { x.moveTo(px, py); } else { x.lineTo(px, py); }
					});
					x.stroke();
				}
				x.strokeStyle = '#b07d10';
				x.lineWidth = 2;
				x.strokeRect(a, 1, b - a, h - 2);
			}
			function update() {
				startIn.value = clock(start);
				drawOverview();
				updateLink();
				var txt = wordsIn(start, start + len());
				said.textContent = txt ? '“' + txt + '”' : sprintf( /* translators: 1: clock(start), 2: clock(start + len()), 3: clock(dur). */ __( '%1$s to %2$s of %3$s.', 'foxfire-studio' ), clock(start), clock(start + len()), clock(dur) );
			}
			function setStart(t, snapIt) {
				start = Math.max(0, Math.min(t, Math.max(0, dur - 5)));
				if (snapIt) { start = C.snap(src.samples, src.rate, start); }
				update();
			}
			ov.addEventListener('click', function (e) {
				var r = ov.getBoundingClientRect();
				setStart((e.clientX - r.left) / r.width * dur - len() / 2, true);
			});
			startIn.addEventListener('change', function () {
				var m = String(startIn.value).match(/^(\d+)(?::(\d{1,2}))?$/);
				if (m) { setStart(m[2] !== undefined ? parseInt(m[1], 10) * 60 + parseInt(m[2], 10) : parseInt(m[1], 10), false); } else { update(); }
			});
			lenSel.addEventListener('change', update);
			back.addEventListener('click', function () { setStart(start - 5, true); });
			fwd.addEventListener('click', function () { setStart(start + 5, true); });
			chSel.addEventListener('change', updateLink);
			copy.addEventListener('click', function () {
				linkIn.select();
				var done = function () { copy.textContent = __( 'Copied', 'foxfire-studio' ); window.setTimeout(function () { copy.textContent = __( 'Copy link', 'foxfire-studio' ); }, 1500); };
				if (navigator.clipboard) { navigator.clipboard.writeText(linkIn.value).then(done, function () { document.execCommand('copy'); done(); }); } else { document.execCommand('copy'); done(); }
			});
			audio = new Audio(boot.audio);
			var stopAt = 0;
			/* Checked every frame: timeupdate comes only about four times a second, enough to run into the next sentence. */
			function watch() {
				if (audio.paused) { return; }
				if (audio.currentTime >= stopAt) { audio.pause(); play.textContent = __( 'Play this moment', 'foxfire-studio' ); return; }
				window.requestAnimationFrame(watch);
			}
			audio.addEventListener('playing', function () { window.requestAnimationFrame(watch); });
			play.addEventListener('click', function () {
				if (!audio.paused) { audio.pause(); play.textContent = __( 'Play this moment', 'foxfire-studio' ); return; }
				stopAt = start + len();
				audio.currentTime = start;
				audio.play();
				play.textContent = __( 'Stop', 'foxfire-studio' );
			});

			/*
			 * Suggestions. The site's own (1.7.0), worked out from the
			 * reading's captions, when it has them; otherwise the opening,
			 * the most replayed and the most lively moments. After a take,
			 * those move to sentence edges from the review's words.
			 */
			var list = [];
			if (boot.suggest && boot.suggest.length) {
				boot.suggest.forEach(function (sg) {
					/* Settled on the quietest moment of the pauses, from the audio itself. */
					var sp = C.settle(src.samples, src.rate, { start: sg.start, len: sg.len, s: sg.s, e: sg.e, prev: sg.prev, next: sg.next });
					var it = { s: sp.start, len: sp.len, label: sg.label, why: sg.why, from: sg.start };
					/* Opened with Make this clip: the clip starts where the settled suggestion does. */
					if (typeof boot.start === 'number' && Math.abs(boot.start - sg.start) < 0.02) { start = sp.start; boot.start = sp.start; boot.len = sp.len; }
					list.push(it);
				});
			} else {
				list.push({ s: 0, label: __( 'The opening', 'foxfire-studio' ), why: __( 'Where every listener starts.', 'foxfire-studio' ) });
				var rp = C.replayed(boot.curve, 30, 10);
				if (null !== rp) { list.push({ s: rp, label: __( 'Most replayed', 'foxfire-studio' ), why: __( 'Listeners went back to hear this part again.', 'foxfire-studio' ) }); }
				var lv = C.lively(src.samples, src.rate, 30);
				list.push({ s: lv, label: __( 'Most lively', 'foxfire-studio' ), why: __( 'Where your voice moves most.', 'foxfire-studio' ) });
				if (typeof boot.start === 'number' && boot.start > 0) { list.unshift({ s: boot.start, len: boot.len, label: __( 'Suggested', 'foxfire-studio' ), why: '' }); }
				var sents = C.sentences(boot.words);
				if (sents.length) {
					list.forEach(function (it) {
						if (it.len) { return; }
						var ed = C.edge(sents, it.s, 30);
						if (ed) {
							ed = C.settle(src.samples, src.rate, ed);
							it.s = ed.start;
							it.len = ed.len;
							it.why = (it.why ? it.why + ' ' : '') + __( 'Starts and ends with a sentence.', 'foxfire-studio' );
						}
					});
				}
			}
			sugg.appendChild(el('span', 'rrt-clip-sugg-label', __( 'Suggested moments: ', 'foxfire-studio' )));
			var suggBtns = [];
			list.forEach(function (it) {
				var bt = el('button', 'button button-small', it.label + ' (' + clock(it.s) + (it.len ? ', ' + Math.round(it.len) + ' s' : '') + ')');
				bt.type = 'button';
				if (it.why) { bt.title = it.why; }
				bt.addEventListener('click', function () {
					suggBtns.forEach(function (o2) { o2.classList.remove('is-on'); });
					bt.classList.add('is-on');
					if (it.len) {
						setLen(it.len);
						setStart(it.s, false);
					} else {
						setStart(it.s, it.s > 0);
					}
				});
				if (typeof boot.start === 'number' && it.len && Math.abs(it.s - boot.start) < 0.02) { bt.classList.add('is-on'); }
				suggBtns.push(bt);
				sugg.appendChild(bt);
			});
			if (typeof boot.len === 'number' && boot.len > 0) {
				setLen(boot.len);
				setStart(start, false);
			} else {
				setStart(start, start > 0);
			}

			make.addEventListener('click', function () {
				var shapes = Object.keys(shapeBoxes).filter(function (k) { return shapeBoxes[k].checked; });
				if (!shapes.length) { status.textContent = __( 'Tick at least one shape.', 'foxfire-studio' ); return; }
				if (audio) { audio.pause(); }
				busy = true;
				make.disabled = true;
				prog.hidden = false;
				prog.value = 0;
				var L = len();
				o.len = L;
				/* A suggestion's exact length ends with its last word: the sound stops there too, with a short fade, not 1.2 s later under the end card. */
				var exOpt = lenSel.options[lenSel.selectedIndex];
				o.exact = !!(exOpt && exOpt.classList.contains('is-exact'));
				var from = start;
				var to = start + L;
				var camp = campaign();
				var getWords;
				if (boot.words && boot.words.length) {
					getWords = Promise.resolve(boot.words);
				} else {
					/* Whisper hears a little either side, so words at the edges are caught whole. */
					var a0 = Math.max(0, from - 1);
					getWords = resample(src.samples, src.rate, a0, Math.min(dur, to + 1), 16000).then(function (a16) {
						return whisper(a16, boot.whisper, function (m) { status.textContent = m; });
					}).then(function (chunks) {
						return C.align(heardWords(chunks, a0), C.tokens(boot.script));
					});
				}
				Promise.all([
					getWords,
					resample(src.samples, src.rate, from, Math.min(dur, to + 1.3), 48000),
					loadImage(boot.image),
					loadFonts(o.fonts)
				]).then(function (got) {
					var st = {
						groups: C.groups(got[0], from, to),
						env: C.envelope(src.samples, src.rate, from, from + L + 3, 30),
						audio: clipLevel(got[1]),
						image: got[2]
					};
					var done = Promise.resolve();
					shapes.forEach(function (shape, si) {
						done = done.then(function () {
							status.textContent = sprintf( /* translators: 1: C.shapes[shape].label.toLowerCase(), 2: si + 1, 3: shapes.length. */ __( 'Making the %1$s clip (%2$s of %3$s)...', 'foxfire-studio' ), C.shapes[shape].label.toLowerCase(), si + 1, shapes.length );
							var t0 = Date.now();
							return render(o, shape, st, function (p) { prog.value = (si + p) / shapes.length; }).then(function (r) {
								showResult(r, shape, camp, (Date.now() - t0) / 1000);
							});
						});
					});
					return done;
				}).then(function () {
					status.textContent = __( 'Done. Watch each clip before you post it.', 'foxfire-studio' );
				}, function (err) {
					status.textContent = sprintf( /* translators: %s: (err && err.message) || err. */ __( 'The clip could not be made: %s.', 'foxfire-studio' ), (err && err.message) || err );
				}).then(function () {
					busy = false;
					make.disabled = false;
					prog.hidden = true;
				});
			});

			function showResult(r, shape, camp, secs) {
				var url = URL.createObjectURL(r.blob);
				urls.push(url);
				var card = el('div', 'rrt-clip-result is-' + shape);
				var v = el('video');
				v.src = url;
				v.controls = true;
				v.playsInline = true;
				v.preload = 'metadata';
				card.appendChild(v);
				var name = (boot.slug || 'reading') + '-' + camp + '-' + shape + '.mp4';
				var meta = el('div', 'rrt-clip-meta');
				meta.appendChild(el('strong', '', C.shapes[shape].label));
				meta.appendChild(el('span', '', sprintf( /* translators: 1: (r.blob.size / 1048576).toFixed(1), 2: Math.round(secs). */ __( ' %1$s MB, made in %2$s s', 'foxfire-studio' ), (r.blob.size / 1048576).toFixed(1), Math.round(secs) )));
				var dl = el('a', 'button button-primary', __( 'Download', 'foxfire-studio' ));
				dl.href = url;
				dl.download = name;
				meta.appendChild(dl);
				if (boot.upload) {
					var save = el('button', 'button', __( 'Save to Media Library', 'foxfire-studio' ));
					save.type = 'button';
					var saved = el('span', 'rrt-clip-saved');
					save.addEventListener('click', function () {
						save.disabled = true;
						saved.textContent = __( 'Saving...', 'foxfire-studio' );
						var to = new URL(boot.upload.rest, window.location.href);
						if (boot.upload.post) { to.searchParams.set('post', String(boot.upload.post)); }
						fetch(to.toString(), {
							method: 'POST',
							credentials: 'same-origin',
							headers: { 'X-WP-Nonce': boot.upload.nonce, 'Content-Type': 'video/mp4', 'Content-Disposition': 'attachment; filename="' + name + '"' },
							body: r.blob
						}).then(function (res) {
							return res.json().then(function (j) { if (!res.ok) { throw new Error(j && j.message ? j.message : sprintf( /* translators: %s: res.status. */ __( 'error %s', 'foxfire-studio' ), res.status )); } return j; });
						}).then(function (j) {
							saved.textContent = '';
							var a = el('a', '', __( 'Saved: open it on your phone', 'foxfire-studio' ));
							a.href = j.source_url;
							a.target = '_blank';
							a.rel = 'noopener';
							saved.appendChild(a);
						}, function (err) {
							save.disabled = false;
							saved.textContent = sprintf( /* translators: %s: err.message. */ __( 'Not saved: %s', 'foxfire-studio' ), err.message );
						});
					});
					meta.appendChild(save);
					meta.appendChild(saved);
				}
				card.appendChild(meta);
				if (r.lost) {
					card.appendChild(el('p', 'rrt-small', sprintf( /* translators: %d: frames the encoder did not return. */ _n( 'The encoder skipped %d frame; the frame before it stays on screen a moment longer, and the sound is unaffected.', 'The encoder skipped %d frames; the frames before them stay on screen a moment longer, and the sound is unaffected.', r.lost, 'foxfire-studio' ), r.lost )));
				}
				if ('opus' === r.sound) {
					card.appendChild(el('p', 'rrt-clip-warn', __( 'The sound was made as Opus, because the AAC encoder did not load. Instagram and some phones want AAC: reload the page and make the clip again.', 'foxfire-studio' )));
				}
				results.insertBefore(card, results.firstChild);
			}
		}
	};

	root.RRTClip = C;
	if (typeof module === 'object' && module.exports) { module.exports = C; }
})(typeof window !== 'undefined' ? window : globalThis);
