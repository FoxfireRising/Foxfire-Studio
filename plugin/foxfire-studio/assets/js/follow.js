/*
 * Foxfire Studio: voice following's pure parts (1.14.0). The speech model in
 * follow-worker.js says what it has heard so far; this file works out where
 * in the script the reader is. It lines up the last few words heard with the
 * script near the current place, allowing for misheard, extra and missing
 * words, so the place holds through an ad-lib or a pause. When nothing lines
 * up for a few words it looks through the whole script for a strong match,
 * which follows a skip ahead or going back to read something again.
 *
 * Also the pace over the last 30 s, and the first look shown the moment a
 * take stops (which paragraphs were never heard). Exposed as
 * window.RRTFollow, and to Node for the unit tests.
 */
(function (root) {
	var TAIL = 6;      /* words heard that are lined up with the script at a time */
	var AHEAD = 40;    /* how far ahead of the current place a match is looked for */
	var BACK = 4;      /* and how far behind it */
	var LOST = 4;      /* words heard with no match before the whole script is searched */
	var NUM = { '0': 'zero', '1': 'one', '2': 'two', '3': 'three', '4': 'four', '5': 'five', '6': 'six', '7': 'seven', '8': 'eight', '9': 'nine', '10': 'ten', '11': 'eleven', '12': 'twelve', '13': 'thirteen', '20': 'twenty', '30': 'thirty', '100': 'hundred' };

	/* One word as the follower compares it: lower case, letters and digits only, a small number written out. */
	function key(s) {
		var k = String(s).toLowerCase().replace(/[’']/g, '').replace(/[^a-z0-9à-ɏ]/g, '');
		return NUM[k] || k;
	}
	/* Words of a piece of text, split at spaces and at hyphens, dashes and slashes ("seven-day" is heard as two words). */
	function words(text) {
		var out = [];
		String(text || '').split(/[\s\-‐-―\/]+/).forEach(function (p) {
			var k = key(p);
			if (k) { out.push(k); }
		});
		return out;
	}
	/*
	 * The script as the follower sees it: each shown word's text, split the
	 * same way, with at[] the shown word each piece belongs to.
	 */
	function script(texts) {
		var keys = [], at = [];
		texts.forEach(function (t, i) {
			words(t).forEach(function (k) { keys.push(k); at.push(i); });
		});
		return { keys: keys, at: at, count: texts.length };
	}

	function lev(a, b) {
		var m = a.length, n = b.length, prev = [], cur, i, j;
		for (j = 0; j <= n; j++) { prev[j] = j; }
		for (i = 1; i <= m; i++) {
			cur = [i];
			for (j = 1; j <= n; j++) {
				cur[j] = Math.min(prev[j] + 1, cur[j - 1] + 1, prev[j - 1] + (a.charAt(i - 1) === b.charAt(j - 1) ? 0 : 1));
			}
			prev = cur;
		}
		return prev[n];
	}
	/* A rough sound-alike key: the first letter and the consonants after it, doubled letters once. */
	function skel(w) {
		w = w.replace(/ph/g, 'f').replace(/ck/g, 'k').replace(/c/g, 'k').replace(/z/g, 's');
		return (w.charAt(0) + w.slice(1).replace(/[aeiouyhw]/g, '')).replace(/(.)\1+/g, '$1');
	}
	/*
	 * How alike a heard word and a script word are, 0 to 1. Short words must
	 * match exactly; longer ones may differ by a quarter of their letters, or
	 * share their sound-alike key (names the model spells its own way).
	 */
	function sim(a, b) {
		if (a === b) { return 1; }
		if (a.length <= 3 || b.length <= 3) { return 0; }
		var r = 1 - lev(a, b) / Math.max(a.length, b.length);
		if (r >= 0.75) { return r; }
		if (a.length >= 5 ? skel(a) === skel(b) : false) { return 0.75; }
		return 0;
	}

	/*
	 * Lines up the heard tail with the script words ending at j: a match
	 * scores twice its likeness, a heard word with no script word (an
	 * ad-lib, a filler) or a script word not heard costs 1. Returns the best
	 * score and how many words matched.
	 */
	function tailScore(keys, j, tail) {
		var n = tail.length, lo = Math.max(0, j - n - 3), m = j + 1 - lo, i, k;
		var S = [], C = [];
		for (i = 0; i <= n; i++) { S.push(new Float64Array(m + 1)); C.push(new Int16Array(m + 1)); }
		for (i = 1; i <= n; i++) {
			for (k = 1; k <= m; k++) {
				var s = sim(tail[i - 1], keys[lo + k - 1]);
				var best = 0, cnt = 0, v;
				if (s > 0) {
					v = S[i - 1][k - 1] + 2 * s;
					if (v > best) { best = v; cnt = C[i - 1][k - 1] + 1; }
				} else {
					v = S[i - 1][k - 1] - 1;
					if (v > best) { best = v; cnt = C[i - 1][k - 1]; }
				}
				v = S[i - 1][k] - 1;
				if (v > best) { best = v; cnt = C[i - 1][k]; }
				v = S[i][k - 1] - 1;
				if (v > best) { best = v; cnt = C[i][k - 1]; }
				S[i][k] = best;
				C[i][k] = cnt;
			}
		}
		return { score: S[n][m], count: C[n][m] };
	}

	/*
	 * A follower for one script. update(heard) takes every word heard so far
	 * (or just the newest ones with the total count) and returns the shown
	 * word to read next. setWord(i) moves it when the reader jumps by hand.
	 */
	function Follower(sc) {
		this.sc = sc;
		this.pos = 0;          /* the next script piece to be read */
		this.used = 0;         /* heard words already taken in */
		this.lost = 0;
		this.heard = new Uint8Array(sc.count); /* shown words the reader was heard reading */
		this.jumps = 0;
		this.onTrack = false; /* the last update matched heard words with the script */
		this.endHeard = false; /* a word was heard where only the last word is left */
	}
	Follower.prototype.word = function () {
		return this.pos >= this.sc.keys.length ? this.sc.count : this.sc.at[this.pos];
	};
	Follower.prototype.setWord = function (i) {
		var p = 0;
		while (p < this.sc.keys.length && this.sc.at[p] < i) { p++; }
		this.pos = p;
		this.lost = 0;
		this.onTrack = false;
		this.endHeard = false;
	};
	/*
	 * tail: the last words heard (any number; the newest TAIL are used);
	 * total: how many words have been heard in all, so words already taken
	 * in are not counted twice. Returns the shown word to read next.
	 */
	Follower.prototype.update = function (tail, total) {
		var keys = this.sc.keys;
		if (total <= this.used || !tail.length) { return this.word(); }
		var t = tail.slice(-TAIL), last = t[t.length - 1], best = null, j;
		for (j = Math.max(0, this.pos - BACK); j < Math.min(keys.length, this.pos + AHEAD); j++) {
			if (sim(last, keys[j]) === 0) { continue; }
			var r = tailScore(keys, j, t);
			if (r.count < 2) { continue; }
			if (!best || r.score > best.score || (r.score === best.score && Math.abs(j - this.pos) < Math.abs(best.j - this.pos))) {
				best = { score: r.score, j: j };
			}
		}
		if (best && best.score >= 4) {
			this.move(best.j + 1);
		} else if (this.onTrack && this.pos === keys.length - 1 && this.pos > 0) {
			/*
			 * Only the script's last word is left and the words before it were just
			 * heard (1.36.1): a word heard now may be the last word, misheard. The
			 * place stays; endHeard tells the page, which ends the script once the
			 * voice has gone quiet (voice.js), so a reader who adds a few words
			 * before the last one is never cut off.
			 */
			this.endHeard = true;
		} else {
			this.onTrack = false;
			this.lost += total - this.used;
			if (this.lost >= LOST && t.length >= 5) {
				/* Lost for a few words: look through the whole script for a strong match. */
				var g = null;
				for (j = 0; j < keys.length; j++) {
					if (sim(last, keys[j]) === 0) { continue; }
					var q = tailScore(keys, j, t);
					if (q.count >= 5 && q.score >= 9 && (!g || q.score > g.score)) { g = { score: q.score, j: j }; }
				}
				if (g) { this.move(g.j + 1); this.jumps++; }
			}
		}
		this.used = total;
		return this.word();
	};
	Follower.prototype.move = function (p) {
		this.pos = p;
		this.lost = 0;
		this.onTrack = true;
		this.endHeard = false;
		this.heard[this.sc.at[p - 1]] = 1;
	};

	/*
	 * Pace from the follower's progress: words per minute over the last
	 * 30 s, pauses included, from at least 15 s of reading, and not across a
	 * jump (a skip or going back starts the window again). The window was
	 * 20 s from 8 s in the first build; on a real one-minute reading that
	 * swung from 188 to 87 wpm, too jumpy to read while reading.
	 * points: [{ t: seconds, w: word }].
	 */
	function pace(points, now) {
		if (!points.length) { return null; }
		var from = -1, i;
		for (i = points.length - 1; i > 0; i--) {
			if (Math.abs(points[i].w - points[i - 1].w) > 15) { break; }
			if (now - points[i - 1].t > 30) { break; }
		}
		from = i;
		var a = points[from], b = points[points.length - 1];
		var span = b.t - a.t;
		if (span < 15 || b.w <= a.w) { return null; }
		return Math.round((b.w - a.w) / (span / 60));
	}

	/*
	 * The first look at a take (1.14.0): from what the follower heard, the
	 * paragraphs of five words or more that were never heard, between the
	 * first and the last paragraph that was. A paragraph counts as heard when
	 * at least two of its words, and a sixth of them, were: one stray match
	 * (a "the" just after a skip) is not reading it. heard: one flag per
	 * shown word; block: each shown word's paragraph. Numbers count from 1.
	 */
	function firstLook(heard, block) {
		var seen = {}, size = {}, got = {}, first = Infinity, last = -1, i;
		for (i = 0; i < block.length; i++) {
			size[block[i]] = (size[block[i]] || 0) + 1;
			if (heard[i]) { got[block[i]] = (got[block[i]] || 0) + 1; }
		}
		Object.keys(got).forEach(function (k) {
			var bk = Number(k);
			if (got[k] >= Math.min(size[k], Math.max(2, Math.ceil(size[k] / 6)))) {
				seen[bk] = true;
				first = Math.min(first, bk);
				last = Math.max(last, bk);
			}
		});
		var skipped = [], b;
		for (b = first + 1; b < last; b++) {
			if (!seen[b] && (size[b] || 0) >= 5) { skipped.push(b + 1); }
		}
		return { heardAny: last >= 0, first: last >= 0 ? first + 1 : 0, last: last + 1, skipped: skipped };
	}

	/*
	 * Leading the highlight (1.14.0 rebuild). The model reports a word about
	 * half a second to a second after it is said, so a highlight that waits
	 * for it trails the reader. While the voice is sounding, the shown word
	 * moves on at the reader's pace, at most `cap` words past the last word
	 * heard; when the voice stops, so does it. s: { shown, heard, acc }, the
	 * shown and heard words and the part of a word built up so far;
	 * speaking; wps: words a second; cap: how far past heard. Returns s.
	 */
	function leadStep(s, dt, speaking, wps, cap) {
		if (s.shown < s.heard) { s.shown = s.heard; s.acc = 0; }
		if (!speaking) { s.acc = 0; return s; }
		s.acc += dt * wps;
		while (s.acc >= 1 && s.shown < s.heard + cap) { s.shown++; s.acc -= 1; }
		if (s.shown >= s.heard + cap) { s.acc = Math.min(s.acc, 0.99); }
		return s;
	}
	/*
	 * Is the voice sounding? v: { floor, peak, at } kept between calls; db:
	 * this piece's level; t: seconds. Speech is 12 dB over the room's floor
	 * (which rises slowly, falls at once) and within 24 dB of the recent
	 * loudest; a word stays "sounding" for 0.25 s after its last loud piece,
	 * so the gaps inside words and between close words do not stop it.
	 */
	function voiced(v, db, t) {
		if (v.floor === undefined) { v.floor = db; v.peak = db; v.at = -1; }
		var dt = v.t === undefined ? 0 : Math.max(0, t - v.t);
		v.t = t;
		v.floor = db < v.floor ? db : v.floor + dt;
		v.peak = db > v.peak ? db : v.peak - dt * 3;
		if (db > v.floor + 12 && db > v.peak - 24) { v.at = t; }
		return v.at >= 0 && t - v.at < 0.25;
	}

	var api = { leadStep: leadStep, voiced: voiced, key: key, words: words, script: script, sim: sim, skel: skel, tailScore: tailScore, Follower: Follower, pace: pace, firstLook: firstLook, TAIL: TAIL };
	root.RRTFollow = api;
	if (typeof module !== 'undefined') {
		if (module.exports) { module.exports = api; }
	}
})(typeof window !== 'undefined' ? window : this);
