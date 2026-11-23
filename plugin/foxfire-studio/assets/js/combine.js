/*
 * Foxfire Studio: one reading from several takes (1.3.0).
 *
 * After two or more takes of the same post, the reader chooses which take
 * reads each paragraph (or each sentence of a paragraph), and these functions
 * build one recording from the choice. Everything here is pure: the studio
 * (takes.js) keeps the takes, finds where the voice starts and stops around a
 * word (RRTEdit.speechEdges) and hands the answers in.
 *
 * The reading is cut into pieces: a paragraph, or a sentence of a paragraph
 * the reader split. A take can read a piece when none of the piece's words
 * fall outside the stretch it read, so a partial take can supply the pieces
 * it covers. Each take's flags from its own review are counted per piece.
 *
 * Consecutive pieces from the same take are one run, played as recorded. At
 * a join between runs the earlier run stops just after the voice does, and
 * the later run starts in its own pause before the next word, so the pause
 * at the join is the one the later take made there. Where that take has too
 * little audio before the word (it started there), the room's own background
 * fills the rest. The runs are leveled to the take that reads the most, and
 * joined with 30 ms equal-power crossfades at quiet points.
 *
 * Works on the page as window.RRTCombine and in Node for tests.
 */
(function (root) {
	'use strict';

	var FADE = 0.03; /* Crossfade at a join, in seconds. Both sides are quiet, so it can be longer than a cut's. */
	var LEAD = 0.5; /* Before the first word of the reading. The MP3 step trims the rest of any silence. */
	var TAIL = 0.8; /* After the last word. */
	var JOIN_COST = 0.75; /* What one more join is worth, in flag weight, when choosing the best of each. */
	var GAIN_MAX = 12; /* The most a take is raised or lowered to match, in dB. */
	var PAUSE_MIN = 0.2;
	var PAUSE_MAX = 2.5;
	var PAUSE_SENT = 0.6; /* Used when neither take shows the pause: between sentences... */
	var PAUSE_PARA = 1.0; /* ...and between paragraphs. */
	var NUDGE_MAX = 500; /* How far a join's edge moves, in ms. */
	var SPACE_MAX = 2000; /* How much space a join can add, in ms. */

	/*
	 * How much each kind of flag counts against a take when choosing. Words
	 * said wrong count most; "worth a listen" and flat sentences are often
	 * Whisper's doing or a matter of taste, so they count least.
	 */
	var WEIGHT = {
		omit: 3, sub: 3, unfinished: 3, rough: 2,
		repeat: 1.5, filler: 1, added: 1, pause: 1,
		rushed: 1, slow: 1, hesitant: 1, faded: 1,
		check: 0.5, flat: 0.5
	};

	/*
	 * The reading cut into pieces. script: [{ key, block, sent }] as the
	 * studio keeps its words (key '' for a word with nothing to say). split:
	 * { blockIndex: true } for paragraphs chosen sentence by sentence.
	 * Returns [{ id, block, first, last }] in reading order, leaving out
	 * pieces with nothing to say.
	 */
	function pieces(script, split) {
		var out = [];
		var cur = null;
		script.forEach(function (w, k) {
			var id = (split ? split[w.block] : false) ? 's' + w.sent : 'b' + w.block;
			if (!cur || cur.id !== id) {
				cur = { id: id, block: w.block, first: k, last: k, keyed: 0 };
				out.push(cur);
			}
			cur.last = k;
			if (w.key !== '') { cur.keyed++; }
		});
		return out.filter(function (p) { return p.keyed > 0; }).map(function (p) {
			return { id: p.id, block: p.block, first: p.first, last: p.last };
		});
	}

	/*
	 * A take's word, as kept on the shelf: [s, e, status] or null. Status is
	 * the review's: ok, sub, check, omit, or out (outside the stretch read).
	 */
	function word(take, k) {
		var w = take.words ? take.words[k] : null;
		if (!w) { return { s: null, e: null, st: 'out' }; }
		return { s: w[0], e: w[1], st: w[2] || 'ok' };
	}
	function timed(take, k) {
		var w = word(take, k);
		if (w.st === 'out' || w.st === 'omit') { return false; }
		return typeof w.s === 'number' ? (typeof w.e === 'number' ? w.e > w.s : false) : false;
	}

	/* True when the take read the whole piece: none of its words outside the stretch read, at least one heard. */
	function covers(take, piece, script) {
		var any = false;
		for (var k = piece.first; k <= piece.last; k++) {
			if (script[k].key === '') { continue; }
			if (word(take, k).st === 'out') { return false; }
			if (timed(take, k)) { any = true; }
		}
		return any;
	}

	/* The take's flags inside the piece, and what they weigh together. */
	function flagsIn(take, piece) {
		var list = (take.problems || []).filter(function (p) { return p.idx >= piece.first ? p.idx <= piece.last : false; });
		var weight = 0;
		list.forEach(function (p) { weight += WEIGHT[p.kind] !== undefined ? WEIGHT[p.kind] : 1; });
		return { n: list.length, weight: weight, list: list };
	}

	/*
	 * The best of each: for every piece, the take with the fewest flags,
	 * counting each join as JOIN_COST, so a take is only switched for a clear
	 * gain (at least one real flag fewer). Pieces no take covers get null.
	 * On a tie the reading stays with the take it is in, and the first piece
	 * goes to the take that reads the most of the post. Returns
	 * { pieceId: takeId }.
	 */
	function best(list, takes, script) {
		var reach = takes.map(function (t) {
			var n = 0;
			list.forEach(function (p) { if (covers(t, p, script)) { n++; } });
			return n;
		});
		var cost = [];
		var from = [];
		list.forEach(function (p, i) {
			cost[i] = [];
			from[i] = [];
			takes.forEach(function (t, j) {
				if (!covers(t, p, script)) { cost[i][j] = Infinity; from[i][j] = -1; return; }
				var own = flagsIn(t, p).weight;
				var prev = i > 0 ? cost[i - 1] : null;
				var bestC = Infinity;
				var bestK = -1;
				var anyPrev = false;
				if (prev) {
					for (var k = 0; k < takes.length; k++) {
						if (prev[k] === Infinity) { continue; }
						anyPrev = true;
						var c = prev[k] + (k === j ? 0 : JOIN_COST);
						/* Ties: staying wins, then the take that reads the most. */
						var better = c < bestC - 1e-9;
						var tie = Math.abs(c - bestC) <= 1e-9;
						if (better || (tie ? (k === j || (bestK !== j ? reach[k] > reach[bestK] : false)) : false)) { bestC = c; bestK = k; }
					}
				}
				if (!anyPrev) { bestC = 0; bestK = -1; }
				cost[i][j] = bestC + own;
				from[i][j] = bestK;
			});
		});
		var out = {};
		var j = -1;
		for (var i = list.length - 1; i >= 0; i--) {
			if (j < 0 || cost[i][j] === Infinity) {
				/* Start again from the cheapest take here (the last piece, or after a piece no take covers). */
				j = -1;
				var bc = Infinity;
				for (var k = 0; k < takes.length; k++) {
					var c = cost[i][k];
					if (c < bc - 1e-9 || (Math.abs(c - bc) <= 1e-9 && c !== Infinity && reach[k] > reach[j])) { bc = c; j = k; }
				}
			}
			out[list[i].id] = j >= 0 ? takes[j].id : null;
			j = j >= 0 ? from[i][j] : -1;
		}
		return out;
	}

	/*
	 * Consecutive pieces from the same take, as runs: [{ take, first, last,
	 * pieces: [index] }]. A piece with no take ends a run and is left out,
	 * so missing(list, choice) should be empty before building.
	 */
	function runs(list, choice) {
		var out = [];
		var cur = null;
		list.forEach(function (p, i) {
			var t = choice[p.id];
			if (t === null || t === undefined) { cur = null; return; }
			if (cur ? cur.take === t : false) {
				cur.last = p.last;
				cur.pieces.push(i);
				return;
			}
			cur = { take: t, first: p.first, last: p.last, pieces: [i] };
			out.push(cur);
		});
		return out;
	}
	function missing(list, choice) {
		return list.filter(function (p) { return choice[p.id] === null || choice[p.id] === undefined; });
	}

	/* The first or last word in k1..k2 the take really said, or -1. */
	function firstTimed(take, k1, k2) {
		for (var k = k1; k <= k2; k++) { if (timed(take, k)) { return k; } }
		return -1;
	}
	function lastTimed(take, k1, k2) {
		for (var k = k2; k >= k1; k--) { if (timed(take, k)) { return k; } }
		return -1;
	}

	/* A join's name, so its nudges stay with it while the same two takes meet at the same place. */
	function joinKey(a, b, kA, kB) { return kA + '-' + kB + ':' + a + '>' + b; }

	/* The key of each join between runs, in order, without needing the audio. */
	function joinKeys(rs, takes) {
		var out = [];
		var prev = null;
		rs.forEach(function (r) {
			var t = takes[r.take];
			var kA = firstTimed(t, r.first, r.last);
			if (kA < 0) { return; }
			var kZ = lastTimed(t, r.first, r.last);
			if (prev) { out.push({ key: joinKey(prev.take, r.take, prev.kZ, kA), a: prev.take, b: r.take, after: prev.last, before: r.first }); }
			prev = { take: r.take, kZ: kZ, last: r.last };
		});
		return out;
	}

	function clampMs(v, max) { v = Math.round(Number(v) || 0); return Math.max(-max, Math.min(max, v)); }

	/*
	 * Where each run is cut from its take. takes: { id: { words, seconds,
	 * level } }. edge(takeId, k) gives { s, e }, where the voice really starts
	 * and stops around word k on that take, in seconds. script as for
	 * pieces(). joins: { key: { a, b, sp } } in ms: a moves the end of the
	 * earlier run, b the start of the later one (positive is later), and sp
	 * adds room tone at the join. Returns [{ take, s, e, tone, gain, join }],
	 * where tone is the room tone before the slice in seconds and gain is in
	 * dB; join is the key of the join before the segment, or ''.
	 */
	function layout(rs, takes, script, edge, joins) {
		joins = joins || {};
		var spans = rs.map(function (r) {
			var t = takes[r.take];
			return { r: r, t: t, kA: firstTimed(t, r.first, r.last), kZ: lastTimed(t, r.first, r.last) };
		}).filter(function (x) { return x.kA >= 0; });
		/* Leveled to the take that reads the most. */
		var share = {};
		spans.forEach(function (x) { share[x.r.take] = (share[x.r.take] || 0) + (x.r.last - x.r.first + 1); });
		var ref = null;
		var refN = -1;
		Object.keys(share).forEach(function (id) {
			var lv = takes[id].level;
			if (typeof lv === 'number' ? share[id] > refN : false) { refN = share[id]; ref = lv; }
		});
		var out = spans.map(function (x, i) {
			var lv = x.t.level;
			var gain = (ref !== null ? typeof lv === 'number' : false) ? Math.max(-GAIN_MAX, Math.min(GAIN_MAX, ref - lv)) : 0;
			var dur = x.t.seconds;
			var a = edge(x.r.take, x.kA);
			var z = edge(x.r.take, x.kZ);
			return { take: x.r.take, s: i === 0 ? Math.max(0, a.s - LEAD) : a.s, e: i === spans.length - 1 ? Math.min(dur, z.e + TAIL) : z.e, tone: 0, gain: Math.round(gain * 10) / 10, join: '' };
		});
		for (var i = 1; i < spans.length; i++) {
			var A = spans[i - 1];
			var B = spans[i];
			var jA = A.kZ;
			var jB = B.kA;
			var key = joinKey(A.r.take, B.r.take, jA, jB);
			var nudge = joins[key] || {};
			/* The later take's own pause before its first word, else the earlier take's after its last, else a usual one. */
			var prevB = lastTimed(B.t, 0, jB - 1);
			var nextA = firstTimed(A.t, jA + 1, script.length - 1);
			var P;
			if (prevB >= 0 ? prevB >= jA - 1 : false) {
				P = edge(B.r.take, jB).s - edge(B.r.take, prevB).e;
			} else if (nextA >= 0 ? nextA <= jB + 1 : false) {
				P = edge(A.r.take, nextA).s - edge(A.r.take, jA).e;
			} else {
				P = script[jA].block !== script[jB].block ? PAUSE_PARA : PAUSE_SENT;
			}
			P = Math.max(PAUSE_MIN, Math.min(PAUSE_MAX, P));
			/* The earlier run stops just after its voice does, never into its own next word. */
			var endA = edge(A.r.take, jA).e + 0.02 + clampMs(nudge.a, NUDGE_MAX) / 1000;
			var hiA = nextA >= 0 ? edge(A.r.take, nextA).s - 0.01 : A.t.seconds;
			endA = Math.max(out[i - 1].s + 0.05, Math.min(hiA, endA));
			out[i - 1].e = endA;
			/*
			 * The later run starts far enough back for the pause: from the earlier
			 * voice's end to the later one's start is then P, counting the 20 ms
			 * left after the earlier voice and the crossfade's overlap.
			 */
			var voiceB = edge(B.r.take, jB).s;
			var startB = voiceB - (P - 0.02 + FADE) + clampMs(nudge.b, NUDGE_MAX) / 1000;
			var lowB = prevB >= 0 ? edge(B.r.take, prevB).e + 0.01 : 0;
			var tone = 0;
			if (startB < lowB) { tone = lowB - startB; startB = lowB; }
			startB = Math.min(startB, voiceB - 0.005);
			var sp = Math.max(0, Math.min(SPACE_MAX, Math.round(Number(nudge.sp) || 0)));
			out[i].s = startB;
			out[i].tone = Math.round((tone + sp / 1000) * 1000) / 1000;
			out[i].join = key;
		}
		return out;
	}

	/*
	 * Renders the segments into one recording. slice(takeId, s, e) gives the
	 * take's audio between s and e seconds; tone(takeId) its room tone (a
	 * Float32Array to repeat) or null. Returns { samples, map, joins }: map
	 * lists [{ take, s, e, at }] (the slice of each take and where it starts
	 * in the result, in seconds) and joins [{ key, at }] (where each join is).
	 */
	function render(segs, slice, tone, rate) {
		var F = Math.max(2, Math.round(FADE * rate));
		var parts = [];
		segs.forEach(function (g) {
			var g10 = Math.pow(10, (g.gain || 0) / 20);
			if (g.tone > 0) {
				var n = Math.round(g.tone * rate) + F;
				var room = tone(g.take);
				var t = new Float32Array(n);
				if (room ? room.length > 0 : false) { for (var i = 0; i < n; i++) { t[i] = room[i % room.length] * g10; } }
				parts.push({ x: t, take: null, join: g.join });
			}
			var x = slice(g.take, g.s, g.e);
			if (g10 !== 1) {
				var y = new Float32Array(x.length);
				for (var k = 0; k < x.length; k++) { y[k] = x[k] * g10; }
				x = y;
			}
			parts.push({ x: x, take: g.take, s: g.s, e: g.e, join: g.tone > 0 ? '' : g.join });
		});
		var total = 0;
		parts.forEach(function (p, i) { total += p.x.length - (i > 0 ? Math.min(F, p.x.length, parts[i - 1].x.length) : 0); });
		var out = new Float32Array(Math.max(0, total));
		var map = [];
		var joins = [];
		var w = 0;
		parts.forEach(function (p, i) {
			var f = i > 0 ? Math.min(F, p.x.length, parts[i - 1].x.length) : 0;
			var start = w - f;
			for (var n = 0; n < f; n++) {
				var g = (n + 0.5) / f;
				out[start + n] = out[start + n] * Math.cos(g * Math.PI / 2) + p.x[n] * Math.sin(g * Math.PI / 2);
			}
			out.set(p.x.subarray(f), w);
			w = start + p.x.length;
			if (p.join) { joins.push({ key: p.join, at: start / rate }); }
			if (p.take !== null) { map.push({ take: p.take, s: p.s, e: p.e, at: start / rate }); }
		});
		return { samples: out, map: map, joins: joins };
	}

	/*
	 * Whisper's words for the result, from each take's own: a chunk goes
	 * where its slice went, when its middle falls inside the slice. chunks:
	 * { takeId: [{ text, timestamp: [s, e] }] }.
	 */
	function mapChunks(chunks, map) {
		var out = [];
		map.forEach(function (m) {
			(chunks[m.take] || []).forEach(function (c) {
				var ts = c.timestamp || [];
				var s = typeof ts[0] === 'number' ? ts[0] : null;
				if (s === null) { return; }
				var e = typeof ts[1] === 'number' ? ts[1] : s + 0.3;
				var mid = (s + e) / 2;
				if (mid < m.s || mid >= m.e) { return; }
				var d = m.at - m.s;
				out.push({ text: c.text, timestamp: [Math.round(Math.max(0, s + d) * 1000) / 1000, Math.round(Math.max(0, e + d) * 1000) / 1000] });
			});
		});
		return out.sort(function (a, b) { return a.timestamp[0] - b.timestamp[0]; });
	}

	/*
	 * The speech level of a recording, in dB: the average power of its 20 ms
	 * blocks that stand 15 dB or more above its background. null when there
	 * is too little speech to tell.
	 */
	function speechLevel(x, rate) {
		var n = Math.max(1, Math.round(0.02 * rate));
		var dbs = [];
		var pw = [];
		for (var a = 0; a + n <= x.length; a += n) {
			var s = 0;
			for (var i = a; i < a + n; i++) { s += x[i] * x[i]; }
			pw.push(s / n);
			dbs.push(10 * Math.log10(s / n + 1e-12));
		}
		if (!dbs.length) { return null; }
		var sorted = dbs.slice().sort(function (p, q) { return p - q; });
		var floor = sorted[Math.floor(sorted.length * 0.1)];
		var sum = 0;
		var c = 0;
		dbs.forEach(function (d, k) { if (d > floor + 15 ? d > -60 : false) { sum += pw[k]; c++; } });
		if (c < 25) { return null; }
		return Math.round(10 * Math.log10(sum / c) * 10) / 10;
	}

	/*
	 * A recording's fingerprint, so the same take is not put on the shelf
	 * twice (a take reviewed, then its Original opened again): its length and
	 * the level of 64 equal stretches.
	 */
	function fingerprint(x, rate) {
		var parts = 64;
		var step = Math.max(1, Math.floor(x.length / parts));
		var p = [];
		for (var j = 0; j < parts; j++) {
			var s = 0;
			var a = j * step;
			var b = Math.min(x.length, a + step);
			for (var i = a; i < b; i++) { s += x[i] * x[i]; }
			p.push(Math.round(10 * Math.log10((b > a ? s / (b - a) : 0) + 1e-12) * 10) / 10);
		}
		return { n: x.length, rate: rate, p: p };
	}
	function sameTake(a, b) {
		if (!a || !b || !a.p || !b.p || a.p.length !== b.p.length) { return false; }
		if (Math.abs(a.n / a.rate - b.n / b.rate) > 0.05) { return false; }
		var d = 0;
		for (var i = 0; i < a.p.length; i++) { d += Math.abs(Math.max(a.p[i], -90) - Math.max(b.p[i], -90)); }
		return d / a.p.length < 1;
	}

	/* A join's nudge moved by one step: side 'a', 'b' (10 ms) or 'sp' (50 ms). Returns the new nudge, or null at a limit. */
	function nudge(cur, side, dir) {
		var out = { a: (cur && cur.a) || 0, b: (cur && cur.b) || 0, sp: (cur && cur.sp) || 0 };
		var was = out[side];
		if (side === 'sp') { out.sp = Math.max(0, Math.min(SPACE_MAX, was + dir * 50)); } else { out[side] = Math.max(-NUDGE_MAX, Math.min(NUDGE_MAX, was + dir * 10)); }
		return out[side] === was ? null : out;
	}

	/*
	 * A saved plan, cleaned: only takes still on the shelf, only choices for
	 * the pieces in list (made with the plan's own split), numbers for every
	 * nudge.
	 */
	function cleanPlan(plan, list, takeIds) {
		var ids = {};
		takeIds.forEach(function (id) { ids[id] = true; });
		var known = {};
		list.forEach(function (p) { known[p.id] = true; });
		var out = { choice: {}, split: {}, joins: {} };
		if (!plan || typeof plan !== 'object') { return out; }
		Object.keys(plan.split || {}).forEach(function (b) { if (/^\d+$/.test(b) ? plan.split[b] : false) { out.split[b] = true; } });
		Object.keys(plan.choice || {}).forEach(function (p) { if (known[p] ? ids[plan.choice[p]] : false) { out.choice[p] = plan.choice[p]; } });
		Object.keys(plan.joins || {}).forEach(function (k) {
			var j = plan.joins[k] || {};
			var c = { a: clampMs(j.a, NUDGE_MAX), b: clampMs(j.b, NUDGE_MAX), sp: Math.max(0, Math.min(SPACE_MAX, Math.round(Number(j.sp) || 0))) };
			if (c.a || c.b || c.sp) { out.joins[k] = c; }
		});
		return out;
	}

	/*
	 * How much of the post the shelf covers (1.4.0), paragraph by paragraph.
	 * takes: the shelf's usable takes. Returns { paras: [{ id, block, first,
	 * last, by: [takeId, ...] }], left: paragraphs no take reads in full,
	 * start: the word Continue starts from, or -1 when every paragraph is
	 * read }. Continue starts at the first sentence before the first unread
	 * paragraph, a run-up the best of each can use either way; the title has
	 * no run-up.
	 */
	function coverage(script, takes) {
		var paras = pieces(script, {}).map(function (p) {
			return { id: p.id, block: p.block, first: p.first, last: p.last, by: takes.filter(function (t) { return covers(t, p, script); }).map(function (t) { return t.id; }) };
		});
		var gap = null;
		var left = 0;
		paras.forEach(function (p) {
			if (p.by.length) { return; }
			left++;
			if (!gap) { gap = p; }
		});
		var start = -1;
		if (gap) {
			start = gap.first;
			if (start > 0) {
				var sent = script[start - 1].sent;
				var k = start - 1;
				while (k > 0 ? script[k - 1].sent === sent : false) { k--; }
				start = k;
			}
		}
		return { paras: paras, left: left, start: start };
	}

	var api = {
		FADE: FADE,
		LEAD: LEAD,
		TAIL: TAIL,
		JOIN_COST: JOIN_COST,
		GAIN_MAX: GAIN_MAX,
		WEIGHT: WEIGHT,
		NUDGE_MAX: NUDGE_MAX,
		SPACE_MAX: SPACE_MAX,
		pieces: pieces,
		word: word,
		timed: timed,
		covers: covers,
		flagsIn: flagsIn,
		best: best,
		runs: runs,
		missing: missing,
		coverage: coverage,
		firstTimed: firstTimed,
		lastTimed: lastTimed,
		joinKey: joinKey,
		joinKeys: joinKeys,
		layout: layout,
		render: render,
		mapChunks: mapChunks,
		speechLevel: speechLevel,
		fingerprint: fingerprint,
		sameTake: sameTake,
		nudge: nudge,
		cleanPlan: cleanPlan
	};
	root.RRTCombine = api;
	if (typeof module !== 'undefined') {
		if (module.exports) { module.exports = api; }
	}
})(typeof window !== 'undefined' ? window : this);
