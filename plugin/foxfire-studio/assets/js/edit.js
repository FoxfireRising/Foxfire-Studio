/*
 * Foxfire Studio: text-based editing (0.8.0).
 *
 * The Whisper review knows roughly when every word was said. These functions
 * turn "cut these words" into exact cut points in the audio, render the
 * edited audio with short crossfades, and shift any time (a caption, a
 * chapter) from the original take to the edited one.
 *
 * Whisper's word times can be off by a few hundredths of a second, so a cut
 * is never placed on them directly. Each edge is moved to the quietest
 * moment nearby, measured from the audio itself, which is almost always the
 * gap between two words. A 12 millisecond crossfade then hides the join.
 *
 * A cut removes the words and half of the gap on each side of them, so the
 * pause that is left is about as long as the two half-gaps together, and the
 * reading keeps its natural rhythm.
 *
 * Edits never touch the recording: they are a list of { s, e } ranges in
 * seconds on the take's own timeline, applied when the audio is rendered.
 * A range can also carry replacement audio ({ s, e, ins: Float32Array }),
 * which is how a punch-in retake is spliced in: the old words go, the new
 * recording takes their place, with a crossfade at each side.
 *
 * Works on the page as window.RRTEdit and in Node for tests.
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

	var FADE = 0.012; /* Crossfade length in seconds. */
	var FRAME = 0.010; /* Loudness is measured in 10 ms frames... */
	var HOP = 0.0025; /* ...every 2.5 ms when looking for the quietest point. */

	function rmsAt(x, rate, t) {
		var n = Math.round(FRAME * rate);
		var a = Math.max(0, Math.round(t * rate - n / 2));
		var b = Math.min(x.length, a + n);
		var s = 0;
		for (var i = a; i < b; i++) { s += x[i] * x[i]; }
		return b > a ? s / (b - a) : 0;
	}

	/*
	 * The quietest moment between lo and hi (seconds), leaning slightly towards
	 * t so that, among equally quiet spots, the one nearest the plan wins.
	 */
	function snapQuiet(x, rate, t, lo, hi) {
		var dur = x.length / rate;
		lo = Math.max(0, lo);
		hi = Math.min(dur, hi);
		if (!(hi > lo)) { return Math.max(0, Math.min(dur, t)); }
		var best = t;
		var bestScore = Infinity;
		var span = Math.max(hi - lo, 0.001);
		for (var u = lo; u <= hi; u += HOP) {
			var e = rmsAt(x, rate, u);
			/* Energy in decibels, plus a gentle pull towards the planned point. */
			var score = 10 * Math.log10(e + 1e-12) + 3 * Math.abs(u - t) / span;
			if (score < bestScore) { bestScore = score; best = u; }
		}
		return best;
	}

	/*
	 * Sorted, overlapping or touching cuts merged, slivers dropped. A
	 * replacement wins over any plain cut that overlaps it, and is never merged.
	 */
	function normalize(cuts) {
		var list = cuts.filter(function (c) { return isFinite(c.s) ? (isFinite(c.e) ? c.e - c.s >= 0.02 : false) : false; })
			.map(function (c) {
				if (!c.ins) { return { s: c.s, e: c.e }; }
				/* A retake keeps the edges first found for it (0.20.0), so a nudge can be reset. */
				var r = { s: c.s, e: c.e, ins: c.ins };
				if (c.o) { r.o = { s: c.o.s, e: c.o.e }; }
				/* Space added at either join (0.20.0), filled with the room's own background. */
				if (c.ps > 0) { r.ps = c.ps; }
				if (c.pe > 0) { r.pe = c.pe; }
				if (c.tone) { r.tone = c.tone; }
				/* The retake as recorded, and how much of each end is trimmed off (0.25.1). */
				if (c.raw) { r.raw = c.raw; }
				if (c.trim) { r.trim = { a: c.trim.a, b: c.trim.b, fa: c.trim.fa, fb: c.trim.fb }; }
				return r;
			})
			.sort(function (a, b) { return a.s - b.s; });
		var reps = list.filter(function (c) { return !!c.ins; });
		list = list.filter(function (c) {
			if (c.ins) { return true; }
			for (var r = 0; r < reps.length; r++) {
				if (c.s < reps[r].e + FADE * 2 ? c.e > reps[r].s - FADE * 2 : false) { return false; }
			}
			return true;
		});
		var out = [];
		for (var i = 0; i < list.length; i++) {
			var c = list[i];
			var last = out.length ? out[out.length - 1] : null;
			var touching = last ? c.s <= last.e + FADE * 2 : false;
			if (touching ? (!c.ins ? !last.ins : false) : false) {
				last.e = Math.max(last.e, c.e);
			} else if (touching ? (c.ins ? !last.ins : false) : false) {
				/* A plain cut just before a replacement gives way to it. */
				out[out.length - 1] = c;
			} else if (touching ? c.s >= last.e : false) {
				/*
				 * Two retakes back to back (0.25.1): both stay. render() keeps a
				 * crossfade's room between the joins, so neither is lost when
				 * one line is retaken right after the line before it.
				 */
				out.push(c);
			} else if (touching) {
				/* Two replacements over the same words: keep the earlier one. */
				continue;
			} else {
				out.push(c);
			}
		}
		return out;
	}
	/* The length a replacement adds back, in seconds, net of its crossfade. */
	function added(c, rate) {
		if (!c.ins) { return 0; }
		var F = Math.max(2, Math.round(FADE * rate));
		return Math.max(0, spacedLength(c, rate) - F) / rate;
	}
	/* Samples of room tone for a space of t seconds. */
	function spaceSamples(t, rate) { return t > 0 ? Math.round(t * rate) : 0; }
	function spacedLength(c, rate) { return c.ins.length + spaceSamples(c.ps, rate) + spaceSamples(c.pe, rate); }
	/*
	 * A retake's audio with its added space (0.20.0): room tone before and
	 * after the new recording, so two words that run into each other at a
	 * join can be pulled apart. The tone repeats as needed, and a 5 ms fade
	 * where it meets the recording keeps the joins free of clicks.
	 */
	function spaced(c, rate) {
		var a = spaceSamples(c.ps, rate);
		var b = spaceSamples(c.pe, rate);
		if (!a && !b) { return c.ins; }
		var n = c.ins.length;
		var out = new Float32Array(a + n + b);
		var tone = c.tone ? (c.tone.length ? c.tone : null) : null;
		function bg(i) { return tone ? tone[i % tone.length] : 0; }
		for (var i = 0; i < a; i++) { out[i] = bg(i); }
		out.set(c.ins, a);
		for (i = a + n; i < out.length; i++) { out[i] = bg(i); }
		var f = Math.min(Math.round(0.005 * rate), Math.floor(n / 4));
		for (var k = 0; k < f; k++) {
			var g = (k + 0.5) / f;
			if (a) { out[a + k] = c.ins[k] * g + bg(a + k) * (1 - g); }
			if (b) { var j = a + n - 1 - k; out[j] = c.ins[n - 1 - k] * g + bg(j) * (1 - g); }
		}
		return out;
	}
	/*
	 * The room's background near time t (seconds) of the take: the longest
	 * quiet stretch within 4 s (else anywhere in the take), at most half a
	 * second of it, or null when the take never rests.
	 */
	function roomTone(x, rate, t) {
		var r = quietRun(x, rate, t - 4, t + 4, 0.06);
		if (!r) { r = quietRun(x, rate, 0, x.length / rate, 0.06); }
		if (!r) { return null; }
		var a = Math.round((r.s + 0.01) * rate);
		var b = Math.min(Math.round((r.e - 0.01) * rate), a + Math.round(0.5 * rate));
		return b - a > Math.round(0.03 * rate) ? x.slice(a, b) : null;
	}

	/*
	 * Cut points for tokens i to j (inclusive) of a time-sorted list of
	 * { s, e } tokens: the words and half of each surrounding gap, with each
	 * edge moved to the quietest point near it.
	 */
	function planCut(tokens, i, j, x, rate) {
		var a = tokens[i];
		var b = tokens[j];
		var prev = i > 0 ? tokens[i - 1] : null;
		var next = j + 1 < tokens.length ? tokens[j + 1] : null;
		var dur = x.length / rate;
		var s0 = prev ? (prev.e + a.s) / 2 : a.s - 0.08;
		var e0 = next ? (b.e + next.s) / 2 : b.e + 0.08;
		/* Search a little past the neighbours' edges too, since Whisper's times are approximate. */
		var sLo = prev ? Math.max(prev.e - 0.03, a.s - 0.3) : a.s - 0.4;
		var sHi = a.s + 0.03;
		var eLo = b.e - 0.03;
		var eHi = next ? Math.min(next.s + 0.03, b.e + 0.3) : b.e + 0.4;
		var s = snapQuiet(x, rate, Math.max(sLo, Math.min(sHi, s0)), sLo, sHi);
		var e = snapQuiet(x, rate, Math.max(eLo, Math.min(eHi, e0)), eLo, eHi);
		if (e <= s) { e = Math.min(dur, s + 0.02); }
		return { s: Math.max(0, s), e: Math.min(dur, e) };
	}

	/*
	 * Speech edges (0.18.1). Whisper's word times are often a tenth of a second
	 * or more off, and usually end a word early, so cutting at them leaves a
	 * sliver of the old word in place: a stutter. These helpers find where the
	 * voice really stops and starts, from the sound itself.
	 */
	var floors = typeof WeakMap === 'function' ? new WeakMap() : null;
	/* The take's background level in dB: the 10th percentile of its 20 ms blocks. */
	function floorDb(x, rate) {
		if (floors ? floors.has(x) : false) { return floors.get(x); }
		var n = Math.max(1, Math.round(0.02 * rate));
		var dbs = [];
		for (var a = 0; a + n <= x.length; a += n) {
			var s = 0;
			for (var i = a; i < a + n; i++) { s += x[i] * x[i]; }
			dbs.push(10 * Math.log10(s / n + 1e-12));
		}
		dbs.sort(function (p, q) { return p - q; });
		var f = dbs.length ? dbs[Math.floor(dbs.length * 0.1)] : -90;
		if (floors) { floors.set(x, f); }
		return f;
	}
	function dbAt(x, rate, t) { return 10 * Math.log10(rmsAt(x, rate, t) + 1e-12); }
	/*
	 * The longest quiet stretch between lo and hi (seconds), as { s, e }, or
	 * null when the voice never rests there for at least min seconds. Quiet is
	 * within 10 dB of the take's background, so a short dip inside a word
	 * (the closure before a "t" or "k") is passed over for a real pause.
	 */
	function quietRun(x, rate, lo, hi, min) {
		var dur = x.length / rate;
		lo = Math.max(0, lo);
		hi = Math.min(dur, hi);
		var thr = floorDb(x, rate) + 10;
		var best = null;
		var runS = -1;
		for (var u = lo; u <= hi + 1e-9; u += 0.005) {
			var q = dbAt(x, rate, u) < thr;
			if (q ? runS < 0 : false) { runS = u; }
			if ((!q || u + 0.005 > hi) ? runS >= 0 : false) {
				var runE = q ? u : u - 0.005;
				if (runE - runS >= min ? (!best || runE - runS > best.e - best.s) : false) { best = { s: runS, e: runE }; }
				runS = -1;
			}
		}
		return best;
	}
	/*
	 * Where the voice stops after word j and starts before word i, for a cut
	 * that takes the whole of words i to j and none of their neighbours.
	 * Each edge sits just inside the pause, so the pause itself stays.
	 */
	function speechEdges(tokens, i, j, x, rate) {
		var a = tokens[i];
		var b = tokens[j];
		var prev = i > 0 ? tokens[i - 1] : null;
		var next = j + 1 < tokens.length ? tokens[j + 1] : null;
		var dur = x.length / rate;
		/* Never reach into the middle of a neighbouring word. */
		var sFloor = prev ? (prev.s + prev.e) / 2 : Math.max(0, a.s - 0.8);
		var eCeil = next ? (next.s + next.e) / 2 : Math.min(dur, b.e + 0.8);
		var s;
		var e;
		var before = quietRun(x, rate, Math.max(sFloor, prev ? prev.e - 0.08 : a.s - 0.8), Math.min(a.s + 0.15, (a.s + a.e) / 2), 0.04);
		if (before) {
			s = Math.max(before.s, before.e - 0.025);
		} else {
			/* Words run together: the quietest moment where they meet. */
			s = snapQuiet(x, rate, a.s - 0.03, Math.max(sFloor, a.s - 0.15), Math.min(a.s + 0.08, (a.s + a.e) / 2));
		}
		var after = quietRun(x, rate, Math.max(b.e - 0.05, (b.s + b.e) / 2), Math.min(eCeil, next ? next.s + 0.15 : b.e + 0.8), 0.06);
		if (after) {
			e = Math.min(after.e, after.s + 0.025);
		} else {
			e = snapQuiet(x, rate, b.e + 0.03, Math.max(b.e - 0.05, (b.s + b.e) / 2), Math.min(eCeil, b.e + 0.2));
		}
		if (e <= s) { e = Math.min(dur, s + 0.02); }
		return { s: Math.max(0, s), e: Math.min(dur, e) };
	}

	/*
	 * Edges for tokens i to j, for a retake: the whole of the words, found
	 * from the sound (speechEdges), so no sliver of the old reading is left
	 * beside the new one. The pauses around them stay as they were.
	 */
	function planSpan(tokens, i, j, x, rate) {
		return speechEdges(tokens, i, j, x, rate);
	}

	/* Shortens the pause between two tokens to keep seconds, by cutting its middle. */
	function planPause(a, b, keep, x, rate) {
		var gap = b.s - a.e;
		if (gap <= keep + 0.05) { return null; }
		var s0 = a.e + keep / 2;
		var e0 = b.s - keep / 2;
		var s = snapQuiet(x, rate, s0, s0 - 0.08, s0 + 0.08);
		var e = snapQuiet(x, rate, e0, e0 - 0.08, e0 + 0.08);
		if (e - s < 0.05) { return null; }
		return { s: s, e: e };
	}

	/*
	 * Suggestions for a one-click tidy, none applied. tokens: time-sorted
	 * { s, e, kind, block, label, cue } where kind is 'word', 'filler', 'repeat' or
	 * 'added', and cue is 'pause' or 'breath' when the reader marked one after the word. opts: { pauseOver (s), keepSentence (s), keepParagraph (s),
	 * fillers, repeats, pauses }. Returns [{ kind, s, e, at, label, tokens }].
	 */
	function tidyPlan(tokens, x, rate, opts) {
		opts = opts || {};
		var over = opts.pauseOver || 1.5;
		var keepS = opts.keepSentence || 0.8;
		var keepP = opts.keepParagraph || 1.2;
		var out = [];
		var cutTok = {};
		for (var i = 0; i < tokens.length; i++) {
			var t = tokens[i];
			var want = (t.kind === 'filler') ? opts.fillers !== false : ((t.kind === 'repeat') ? opts.repeats !== false : false);
			if (!want) { continue; }
			/* Neighbouring fillers and repeats go as one cut. */
			var j = i;
			while (j + 1 < tokens.length) {
				var k2 = tokens[j + 1].kind;
				if (k2 !== 'filler') { if (k2 !== 'repeat') { break; } }
				j++;
			}
			var c = planCut(tokens, i, j, x, rate);
			var words = tokens.slice(i, j + 1).map(function (q) { return q.label; }).join(' ');
			out.push({ kind: t.kind, s: c.s, e: c.e, at: t.s, label: (t.kind === 'filler' ? __( 'Filler', 'foxfire-studio' ) : __( 'Repeat', 'foxfire-studio' )) + ': “' + words + '”', first: i, last: j });
			for (var q = i; q <= j; q++) { cutTok[q] = true; }
			i = j;
		}
		if (opts.pauses !== false) {
			var prev = -1;
			for (var n = 0; n < tokens.length; n++) {
				if (cutTok[n]) { continue; }
				if (prev >= 0) {
					var a = tokens[prev];
					var b = tokens[n];
					var gap = b.s - a.e;
					/* A pause the reader marked with a cue (0.15.0) is meant, so it is never offered as a cut. */
					if (gap > over ? !a.cue : false) {
						var keep = a.block !== b.block ? keepP : keepS;
						var p = planPause(a, b, keep, x, rate);
						if (p) {
							out.push({ kind: 'pause', s: p.s, e: p.e, at: a.e, label: sprintf( /* translators: 1: gap.toFixed(1), 2: keep.toFixed(1). */ __( 'Pause of %1$s s, shortened to %2$s s', 'foxfire-studio' ), gap.toFixed(1), keep.toFixed(1) ), first: prev, last: n });
						}
					}
				}
				prev = n;
			}
		}
		return out.sort(function (u, v) { return u.at - v.at; });
	}

	/* How much shorter the edited take is, in seconds (negative if longer). */
	function removed(cuts, rate) {
		var total = 0;
		normalize(cuts).forEach(function (c) { total += c.e - c.s - (c.ins ? added(c, rate || 44100) : 0); });
		return total;
	}

	/*
	 * Renders the edited audio: everything outside the cuts, joined with
	 * equal-power crossfades. The result is shorter by exactly the cuts' length.
	 */
	function render(x, rate, cuts) {
		var list = normalize(cuts);
		var F = Math.max(2, Math.round(FADE * rate));
		var half = F >> 1;
		var keep = [];
		var pos = -1;
		list.forEach(function (c) {
			var a = Math.round(c.s * rate);
			var b = Math.round(c.e * rate);
			/* Each crossfade needs its own room: never closer than one fade to the previous join or the start. */
			var minA = pos < 0 ? half : pos + F;
			a = Math.max(minA, Math.min(x.length - F, a));
			b = Math.max(a, Math.min(x.length - F, b));
			if (b > a) { keep.push([pos, a, b, c.ins ? spaced(c, rate) : null]); pos = b; }
		});
		var total = x.length;
		for (var k = 0; k < keep.length; k++) {
			total -= keep[k][2] - keep[k][1];
			if (keep[k][3]) { total += keep[k][3].length; }
		}
		var out = new Float32Array(Math.max(0, total));
		var w = 0;
		var from = 0;
		function fade(n) { var g = (n + 0.5) / F; return [Math.cos(g * Math.PI / 2), Math.sin(g * Math.PI / 2)]; }
		for (k = 0; k < keep.length; k++) {
			var cutA = keep[k][1];
			var cutB = keep[k][2];
			var ins = keep[k][3];
			/* Straight copy up to the start of the crossfade. */
			for (var i = from; i < cutA - half; i++) { out[w++] = x[i]; }
			var n, f, ia, ib, va, vb;
			if (ins ? ins.length >= 2 * F : false) {
				/* Old audio fades into the new recording, which plays, then fades into the old audio again. */
				for (n = 0; n < F; n++) {
					f = fade(n);
					ia = cutA - half + n;
					va = ia < x.length ? x[ia] : 0;
					out[w++] = va * f[0] + ins[n] * f[1];
				}
				for (n = F; n < ins.length - F; n++) { out[w++] = ins[n]; }
				for (n = 0; n < F; n++) {
					f = fade(n);
					ib = cutB - half + n;
					vb = ib < x.length ? x[ib] : 0;
					out[w++] = ins[ins.length - F + n] * f[0] + vb * f[1];
				}
			} else {
				/* Crossfade: the audio before the cut fades out as the audio after it fades in. */
				for (n = 0; n < F; n++) {
					f = fade(n);
					ia = cutA - half + n;
					ib = cutB - half + n;
					va = ia < x.length ? x[ia] : 0;
					vb = ib < x.length ? x[ib] : 0;
					out[w++] = va * f[0] + vb * f[1];
				}
			}
			from = cutB + (F - half);
		}
		for (i = from; i < x.length; i++) {
			if (w >= out.length) { break; }
			out[w++] = x[i];
		}
		return w < out.length ? out.subarray(0, w) : out;
	}

	/*
	 * A time on the original take, on the edited take. A time inside a cut
	 * maps to the join; mapRange can drop such items instead.
	 */
	function mapTime(cuts, t, rate) {
		var list = normalize(cuts);
		var r = rate || 44100;
		var shift = 0;
		for (var i = 0; i < list.length; i++) {
			var c = list[i];
			var gone = c.e - c.s - added(c, r);
			if (t >= c.e) {
				shift += gone;
			} else if (t > c.s) {
				/* Inside a retake, times spread evenly over the new recording, so its captions stay. */
				if (c.ins) { return c.s - shift + (t - c.s) / (c.e - c.s) * (c.e - c.s - gone); }
				return c.s - shift;
			} else {
				break;
			}
		}
		return t - shift;
	}
	/* True when most of s..e falls inside plain cuts (a retake's words are still said). */
	function isCut(cuts, s, e) {
		var list = normalize(cuts);
		var inside = 0;
		for (var i = 0; i < list.length; i++) {
			if (list[i].ins) { continue; }
			inside += Math.max(0, Math.min(e, list[i].e) - Math.max(s, list[i].s));
		}
		return inside > (e - s) / 2;
	}
	/* True when most of s..e falls inside a retake. */
	function isRetaken(cuts, s, e) {
		var list = normalize(cuts);
		var inside = 0;
		for (var i = 0; i < list.length; i++) {
			if (!list[i].ins) { continue; }
			inside += Math.max(0, Math.min(e, list[i].e) - Math.max(s, list[i].s));
		}
		return inside > (e - s) / 2;
	}

	/*
	 * Edge nudge (0.20.0). Moves one side of a retake ('s' or 'e') by step
	 * seconds on the take's timeline, within NUDGE_MAX of where it was first
	 * found, never closer than two crossfades to another edit, never past the
	 * take's ends, and never leaving less than 50 ms of old words to replace.
	 * Returns the new { s, e, ins, o } or null when it cannot move that way.
	 */
	var NUDGE_MAX = 0.5;
	function nudgeEdge(cut, side, step, others, dur) {
		var o = cut.o || { s: cut.s, e: cut.e };
		var lo;
		var hi;
		if (side === 's') {
			lo = Math.max(0, o.s - NUDGE_MAX);
			hi = Math.min(o.s + NUDGE_MAX, cut.e - 0.05);
		} else {
			lo = Math.max(o.e - NUDGE_MAX, cut.s + 0.05);
			hi = Math.min(isFinite(dur) ? dur : Infinity, o.e + NUDGE_MAX);
		}
		(others || []).forEach(function (c) {
			if (c.e <= cut.s + 1e-9) { lo = side === 's' ? Math.max(lo, c.e + FADE * 2 + 0.001) : lo; }
			if (c.s >= cut.e - 1e-9) { hi = side === 'e' ? Math.min(hi, c.s - FADE * 2 - 0.001) : hi; }
		});
		var was = cut[side];
		/* Round to the millisecond so repeated steps do not drift. */
		var want = Math.round((was + step) * 1000) / 1000;
		var t = Math.max(lo, Math.min(hi, want));
		if (Math.abs(t - was) < 0.0005) { return null; }
		var out = carry(cut);
		out.o = { s: o.s, e: o.e };
		out[side] = t;
		return out;
	}
	/* A copy of a retake with everything it carries. */
	function carry(cut) {
		var out = { s: cut.s, e: cut.e, ins: cut.ins };
		if (cut.o) { out.o = { s: cut.o.s, e: cut.o.e }; }
		if (cut.ps > 0) { out.ps = cut.ps; }
		if (cut.pe > 0) { out.pe = cut.pe; }
		if (cut.tone) { out.tone = cut.tone; }
		if (cut.raw) { out.raw = cut.raw; }
		if (cut.trim) { out.trim = { a: cut.trim.a, b: cut.trim.b, fa: cut.trim.fa, fb: cut.trim.fb }; }
		return out;
	}

	/*
	 * Which of the words Whisper heard in a retake are the selected ones
	 * (0.25.1). heard and want are word keys. The start is the heard copy of
	 * the first selected word from which most of the rest follow in order; on
	 * a tie the later one wins, since a reader who stumbles starts again (so
	 * "trip trip ice" starts at the second "trip"). The end is the last copy
	 * of the last selected word after that. Returns { i, j } or null.
	 */
	function fitRun(heard, want) {
		if (!heard.length || !want.length) { return null; }
		var best = -1;
		var i = -1;
		for (var k = 0; k < heard.length; k++) {
			if (heard[k] !== want[0]) { continue; }
			var n = 1;
			var w = 1;
			for (var h = k + 1; h < heard.length ? w < want.length : false; h++) {
				if (heard[h] === want[w]) { n++; w++; }
			}
			if (n >= best) { best = n; i = k; }
		}
		if (i < 0) { return null; }
		var last = want[want.length - 1];
		for (var j = heard.length - 1; j >= i; j--) {
			if (heard[j] === last) { return { i: i, j: j }; }
		}
		return null;
	}

	/*
	 * Trimming the retake itself (0.25.1): trim is { a, b, fa, fb }, the
	 * samples of the recording kept (a to b) and where they were first fitted
	 * (fa, fb). step seconds moves the start (side 's') or end ('e') later
	 * (positive) or earlier, never past the recording's own ends and keeping
	 * at least TRIM_MIN of it. Returns the new trim, or null when it cannot
	 * move that way.
	 */
	var TRIM_MIN = 0.2;
	function trimEdge(trim, side, step, len, rate) {
		var keep = Math.round(TRIM_MIN * rate);
		var d = Math.round(step * rate);
		var out = { a: trim.a, b: trim.b, fa: trim.fa, fb: trim.fb };
		if (side === 's') {
			out.a = Math.max(0, Math.min(trim.b - keep, trim.a + d));
		} else {
			out.b = Math.min(len, Math.max(trim.a + keep, trim.b + d));
		}
		if (out.a === trim.a ? out.b === trim.b : false) { return null; }
		return out;
	}
	/* How far each end of the retake is from where it was fitted, in ms: positive trims more. */
	function trimmed(trim, rate) {
		if (!trim) { return { s: 0, e: 0 }; }
		return { s: Math.round((trim.a - trim.fa) / rate * 1000), e: Math.round((trim.fb - trim.b) / rate * 1000) };
	}
	/*
	 * Space at a join (0.20.0): adds step seconds of room tone before ('s')
	 * or after ('e') the new recording, from none up to SPACE_MAX. Returns
	 * the new retake, or null when it cannot change that way.
	 */
	var SPACE_MAX = 2;
	function spaceEdge(cut, side, step) {
		var key = side === 's' ? 'ps' : 'pe';
		var was = cut[key] > 0 ? cut[key] : 0;
		var t = Math.max(0, Math.min(SPACE_MAX, Math.round((was + step) * 1000) / 1000));
		if (Math.abs(t - was) < 0.0005) { return null; }
		var out = carry(cut);
		if (t > 0) { out[key] = t; } else { delete out[key]; }
		return out;
	}
	/* How far each side has moved from where it was found, in milliseconds (positive is later). */
	function nudged(cut) {
		var o = cut.o || cut;
		return { s: Math.round((cut.s - o.s) * 1000), e: Math.round((cut.e - o.e) * 1000) };
	}

	/* Mono Float32 audio to a 16-bit WAV Blob-ready byte array, for previews. */
	function wav(x, rate) {
		var b = new ArrayBuffer(44 + x.length * 2);
		var v = new DataView(b);
		function str(o, s) { for (var i = 0; i < s.length; i++) { v.setUint8(o + i, s.charCodeAt(i)); } }
		str(0, 'RIFF');
		v.setUint32(4, 36 + x.length * 2, true);
		str(8, 'WAVEfmt ');
		v.setUint32(16, 16, true);
		v.setUint16(20, 1, true);
		v.setUint16(22, 1, true);
		v.setUint32(24, rate, true);
		v.setUint32(28, rate * 2, true);
		v.setUint16(32, 2, true);
		v.setUint16(34, 16, true);
		str(36, 'data');
		v.setUint32(40, x.length * 2, true);
		for (var i = 0; i < x.length; i++) {
			var s = x[i] > 1 ? 1 : (x[i] < -1 ? -1 : x[i]);
			v.setInt16(44 + i * 2, s < 0 ? s * 32768 : s * 32767, true);
		}
		return new Uint8Array(b);
	}

	/*
	 * Flags after edits (1.1.0). Which of a review's flagged moments the
	 * edits cover: 'retaken' (most of it is inside a retake), 'cut' (most of
	 * it is cut; for a long pause, shortened) or '' (still there). A moment
	 * with no length (a skipped word, between the words either side of it)
	 * counts as covered when an edit spans that point.
	 */
	function coverOf(cuts, s, e) {
		if (!cuts || !cuts.length || s === null || s === undefined || !isFinite(s)) { return ''; }
		if (e === null || e === undefined || !isFinite(e) || e <= s) { e = s + 0.002; }
		if (isRetaken(cuts, s, e)) { return 'retaken'; }
		return isCut(cuts, s, e) ? 'cut' : '';
	}
	/* Where a skipped word would have been: from the end of the word heard before it to the start of the one after. */
	function gapSpan(words, k) {
		var a = null;
		var b = null;
		var i;
		for (i = k - 1; i >= 0; i--) { if (words[i].status !== 'omit' && words[i].e !== null && words[i].e !== undefined) { a = words[i].e; break; } }
		for (i = k + 1; i < words.length; i++) { if (words[i].status !== 'omit' && words[i].s !== null && words[i].s !== undefined) { b = words[i].s; break; } }
		if (a === null) { a = b; }
		if (b === null) { b = a; }
		return [a, b];
	}
	/* The stretch of the take one flagged moment covers. */
	function flagSpan(R, p) {
		var w = R.words[p.idx];
		if (p.kind === 'omit') { return gapSpan(R.words, p.idx); }
		if (p.kind === 'sub' || p.kind === 'check') { return w ? [w.s, w.e] : [p.s, p.s]; }
		if (p.kind === 'filler' || p.kind === 'added' || p.kind === 'repeat') {
			var s = p.s;
			var e = p.s;
			Object.keys(R.inserts || {}).forEach(function (at) {
				var xs = R.inserts[at].filter(function (x) { return x.kind === p.kind; });
				if (xs.length ? xs[0].s === p.s : false) { xs.forEach(function (x) { e = Math.max(e, x.e); }); }
			});
			return [s, e];
		}
		if (p.kind === 'pause') {
			var pz = (R.pauses || []).filter(function (q) { return q.s === p.s; })[0];
			return pz ? [pz.s, pz.s + pz.len] : [p.s, p.s];
		}
		var S = (R.sentences || []).filter(function (q) { return q.id === p.sent; })[0];
		return S ? [S.s, S.e] : [p.s, p.s];
	}
	/*
	 * For a review R and the edits: each flag's cover (in R.problems order),
	 * how many flags are covered, and how many of each count on the review's
	 * tiles the edits cover (words for skipped, misread and worth a listen;
	 * added words, repeats and fillers; flagged pauses; sentences to retake).
	 */
	function flagsCovered(R, cuts) {
		var out = { byProblem: [], covered: 0, counts: { omit: 0, sub: 0, check: 0, added: 0, repeat: 0, filler: 0, pauses: 0, retake: 0 } };
		if (!R) { return out; }
		var has = cuts ? cuts.length > 0 : false;
		out.byProblem = R.problems.map(function (p) {
			var sp = has ? flagSpan(R, p) : null;
			var c = sp ? coverOf(cuts, sp[0], sp[1]) : '';
			if (c) { out.covered++; }
			return c;
		});
		if (!has) { return out; }
		R.words.forEach(function (w, k) {
			if (w.status === 'omit') {
				var g = gapSpan(R.words, k);
				if (coverOf(cuts, g[0], g[1])) { out.counts.omit++; }
			} else if (w.status === 'sub' || w.status === 'check') {
				if (coverOf(cuts, w.s, w.e)) { out.counts[w.status]++; }
			}
		});
		Object.keys(R.inserts || {}).forEach(function (at) {
			R.inserts[at].forEach(function (x) {
				if (x.merged ? x.kind === 'added' : false) { return; }
				if (out.counts[x.kind] !== undefined ? !!coverOf(cuts, x.s, x.e) : false) { out.counts[x.kind]++; }
			});
		});
		(R.pauses || []).forEach(function (q) {
			if ((!q.inSentence ? !q.cued : false) ? !!coverOf(cuts, q.s, q.s + q.len) : false) { out.counts.pauses++; }
		});
		/* Sentences still worth another attempt: those with a slip or a serious flag the edits leave. */
		var sentOf = function (k) {
			var S = (R.sentences || []).filter(function (q) { return k >= q.first ? k <= q.last : false; })[0];
			return S ? S.id : 'w' + k;
		};
		var before = {};
		var after = {};
		R.problems.forEach(function (p, i) {
			if (p.kind === 'pause' || p.kind === 'filler' || p.kind === 'check') { return; }
			var sid = p.sent !== undefined ? p.sent : sentOf(p.idx);
			before[sid] = true;
			if (!out.byProblem[i]) { after[sid] = true; }
		});
		var nb = Object.keys(before).length;
		var na = Object.keys(after).length;
		out.counts.retake = Math.max(0, Math.min(R.retake === undefined ? nb : R.retake, nb - na));
		return out;
	}

	var api = {
		FADE: FADE,
		snapQuiet: snapQuiet,
		normalize: normalize,
		planCut: planCut,
		planSpan: planSpan,
		speechEdges: speechEdges,
		quietRun: quietRun,
		floorDb: floorDb,
		planPause: planPause,
		tidyPlan: tidyPlan,
		removed: removed,
		render: render,
		mapTime: mapTime,
		isCut: isCut,
		isRetaken: isRetaken,
		added: added,
		NUDGE_MAX: NUDGE_MAX,
		SPACE_MAX: SPACE_MAX,
		spaceEdge: spaceEdge,
		spaced: spaced,
		roomTone: roomTone,
		nudgeEdge: nudgeEdge,
		nudged: nudged,
		fitRun: fitRun,
		TRIM_MIN: TRIM_MIN,
		trimEdge: trimEdge,
		trimmed: trimmed,
		coverOf: coverOf,
		flagSpan: flagSpan,
		flagsCovered: flagsCovered,
		wav: wav
	};
	root.RRTEdit = api;
	if (typeof module !== 'undefined') {
		if (module.exports) { module.exports = api; }
	}
})(typeof window !== 'undefined' ? window : this);
