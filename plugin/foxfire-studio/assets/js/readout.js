/*
 * Foxfire Studio: the read-out after a take (moved here from
 * teleprompter.js in 0.25.1). analyseReading( samples, rate, words, setWpm )
 * measures pace, pauses, endings, energy and pitch; describeReading( m,
 * voiced, fmtTime ) turns them into short, plain sentences. Loaded before
 * teleprompter.js as window.RRTReadout, and by Node for the unit tests.
 */
(function (root) {
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
	/*
	 * Listen back to a finished reading and describe it in plain language.
	 * Works on the trimmed mono samples before they become an MP3.
	 *   pace:    words read per minute, against the pace that was set
	 *   pauses:  share of time spent resting, and whether rests shrank later on
	 *   endings: how much quieter the last part of each phrase is
	 *   pitch:   how much the voice moves within each phrase (in semitones)
	 */
	function analyseReading(data, rate, words, setWpm) {
		var HOP = Math.round(rate * 0.02);
		var nF = Math.floor(data.length / HOP);
		var dur = data.length / rate;
		var out = { seconds: dur, words: words };
		if (nF < 50 || words < 10) { return null; }

		// Loudness of each 20 ms frame, in dB.
		var db = new Float32Array(nF);
		var f;
		var i;
		for (f = 0; f < nF; f++) {
			var s = 0;
			var base = f * HOP;
			for (i = 0; i < HOP; i++) { var x = data[base + i]; s += x * x; }
			db[f] = 10 * Math.log10(s / HOP + 1e-10);
		}
		var sorted = Array.prototype.slice.call(db).sort(function (a, b) { return a - b; });
		var floor = sorted[Math.floor(nF * 0.1)];
		var loud = sorted[Math.floor(nF * 0.9)];
		var gate = Math.max(floor + 10, loud - 28);

		// Speech or silence per frame, smoothed so tiny gaps inside words do not count.
		var on = new Uint8Array(nF);
		for (f = 0; f < nF; f++) { on[f] = db[f] > gate ? 1 : 0; }
		var runs = [];
		var start = 0;
		for (f = 1; f <= nF; f++) {
			if (f === nF || on[f] !== on[start]) {
				runs.push({ on: on[start], a: start, b: f });
				start = f;
			}
		}
		var MIN_PAUSE = Math.round(0.16 / 0.02);
		var k;
		for (k = 0; k < runs.length; k++) {
			if (!runs[k].on && (runs[k].b - runs[k].a) < MIN_PAUSE && k > 0 && k < runs.length - 1) { runs[k].on = 1; }
		}
		var merged = [];
		for (k = 0; k < runs.length; k++) {
			var last = merged[merged.length - 1];
			if (last && last.on === runs[k].on) { last.b = runs[k].b; } else { merged.push({ on: runs[k].on, a: runs[k].a, b: runs[k].b }); }
		}
		var phrases = [];
		var pauses = [];
		for (k = 0; k < merged.length; k++) {
			var r = merged[k];
			if (r.on) { phrases.push(r); }
			else if (k > 0 && k < merged.length - 1) { pauses.push({ a: r.a, len: (r.b - r.a) * 0.02 }); }
		}

		// Pace.
		out.wpm = Math.round(words / (dur / 60));
		out.setWpm = setWpm;

		// Pauses.
		var restTotal = 0;
		pauses.forEach(function (p) { restTotal += p.len; });
		out.restShare = restTotal / dur;
		var mid = nF / 2;
		var early = pauses.filter(function (p) { return p.a < mid; });
		var late = pauses.filter(function (p) { return p.a >= mid; });
		function avg(list) {
			if (!list.length) { return 0; }
			return list.reduce(function (a, p) { return a + p.len; }, 0) / list.length;
		}
		out.pauseEarly = avg(early);
		out.pauseLate = avg(late);
		out.pauseCount = pauses.length;
		out.longPauses = pauses.filter(function (p) { return p.len >= 0.6; }).length;

		// Phrase endings: last stretch of each phrase against the body of it.
		var drops = [];
		phrases.forEach(function (p) {
			var n = p.b - p.a;
			if (n < 50) { return; }
			var tail = Math.max(10, Math.round(n * 0.2));
			function energy(a, b) {
				var e = 0;
				var c = 0;
				for (var q = a; q < b; q++) { if (db[q] > gate) { e += Math.pow(10, db[q] / 10); c++; } }
				return c ? 10 * Math.log10(e / c) : null;
			}
			var bodyE = energy(p.a, p.b - tail);
			var tailE = energy(p.b - tail, p.b);
			if (bodyE !== null && tailE !== null) { drops.push(bodyE - tailE); }
		});
		drops.sort(function (a, b) { return a - b; });
		out.endDropDb = drops.length >= 3 ? drops[Math.floor(drops.length / 2)] : null;

		/*
		 * Energy (0.9.0): how much the voice's loudness moves within a phrase,
		 * the spread (standard deviation, in dB) of its voiced 20 ms frames,
		 * leaving out the last fifth, which the endings measure already covers.
		 * The typical phrase is reported.
		 */
		var moves = [];
		phrases.forEach(function (p) {
			var n = p.b - p.a;
			if (n < 50) { return; }
			var end = p.b - Math.max(10, Math.round(n * 0.2));
			var vals = [];
			for (var q = p.a; q < end; q++) { if (db[q] > gate + 3) { vals.push(db[q]); } }
			if (vals.length < 20) { return; }
			var mean = vals.reduce(function (x, y) { return x + y; }, 0) / vals.length;
			var v2 = vals.reduce(function (x, y) { return x + (y - mean) * (y - mean); }, 0) / vals.length;
			moves.push(Math.sqrt(v2));
		});
		moves.sort(function (a, b) { return a - b; });
		out.energySpread = moves.length >= 3 ? moves[Math.floor(moves.length / 2)] : null;

		// Pitch, using the YIN method on a lightly downsampled copy.
		var DS = Math.max(1, Math.round(rate / 11025));
		var sr = rate / DS;
		var dn = Math.floor(data.length / DS);
		var low = new Float32Array(dn);
		for (i = 0; i < dn; i++) {
			var acc = 0;
			for (var d2 = 0; d2 < DS; d2++) { acc += data[i * DS + d2]; }
			low[i] = acc / DS;
		}
		var WIN = Math.round(sr * 0.03);
		var minLag = Math.floor(sr / 400);
		var maxLag = Math.ceil(sr / 65);
		var diff = new Float32Array(maxLag + 1);
		var pitch = new Float32Array(nF);
		var STEP = 2;
		for (f = 0; f < nF; f += STEP) {
			if (db[f] < gate + 6) { continue; }
			var st = Math.floor(f * HOP / DS);
			if (st + WIN + maxLag >= dn) { break; }
			var lag;
			for (lag = 1; lag <= maxLag; lag++) {
				var sum = 0;
				for (i = 0; i < WIN; i++) { var dd = low[st + i] - low[st + i + lag]; sum += dd * dd; }
				diff[lag] = sum;
			}
			var run = 0;
			var best = -1;
			for (lag = 1; lag <= maxLag; lag++) {
				run += diff[lag];
				var cm = run > 0 ? diff[lag] * lag / run : 1;
				if (lag >= minLag && cm < 0.15) {
					while (lag + 1 <= maxLag) {
						run += diff[lag + 1];
						var nx = run > 0 ? diff[lag + 1] * (lag + 1) / run : 1;
						if (nx < cm) { cm = nx; lag++; } else { run -= diff[lag + 1]; break; }
					}
					best = lag;
					break;
				}
			}
			if (best > 0) { pitch[f] = 12 * Math.log2(sr / best / 55); }
		}
		function spread(a, b) {
			var vals = [];
			for (var q = a; q < b; q++) { if (pitch[q] > 0) { vals.push(pitch[q]); } }
			if (vals.length < 8) { return null; }
			vals.sort(function (x, y) { return x - y; });
			var md = vals[Math.floor(vals.length / 2)];
			vals = vals.filter(function (v) { return Math.abs(v - md) < 7; });
			if (vals.length < 8) { return null; }
			var m = vals.reduce(function (x, y) { return x + y; }, 0) / vals.length;
			var v2 = vals.reduce(function (x, y) { return x + (y - m) * (y - m); }, 0) / vals.length;
			return Math.sqrt(v2);
		}
		var spreads = [];
		phrases.forEach(function (p) {
			if (p.b - p.a < 50) { return; }
			var sp = spread(p.a, p.b);
			if (sp !== null) { spreads.push(sp); }
		});
		spreads.sort(function (a, b) { return a - b; });
		out.pitchSpread = spreads.length >= 3 ? spreads[Math.floor(spreads.length / 2)] : null;

		// The flattest stretch, in 20 second windows, so it can be found again.
		out.flatAt = null;
		var W20 = Math.round(20 / 0.02);
		if (out.pitchSpread !== null && nF > W20 * 2) {
			var worst = 99;
			var worstAt = 0;
			for (f = 0; f + W20 <= nF; f += Math.round(W20 / 2)) {
				var sp2 = spread(f, f + W20);
				if (sp2 !== null && sp2 < worst) { worst = sp2; worstAt = f; }
			}
			if (worst < out.pitchSpread * 0.7 && worst < 1.6) { out.flatAt = worstAt * 0.02; out.flatSpread = worst; }
		}
		// Kept for the full review, which looks at each sentence on its own.
		out.frames = { db: db, pitch: pitch, gate: gate, spread: spread, step: 0.02 };
		return out;
	}

	/* Turn the numbers into short, plain sentences. */
	/*
	 * range (1.11.0): the post's kind of reading as { lo, hi, name, trails }, or null
	 * (trails, 1.15.1: true for verse and ritual, whose endings are judged gently).
	 * faded (1.15.1): how many Faded ending flags the full review left standing,
	 * or nothing before the review.
	 * With one, the pace is judged against that range; without one, against
	 * the general listening bands.
	 */
	function describeReading(m, voiced, fmtTime, range, faded) {
		var lines = [];
		var pace;
		/* 1.33.0: a reading in another language before three takes in it: the number, without a judgement. */
		if (range ? !!range.plain : false) {
			pace = null;
		} else if (range ? (range.lo > 0 && range.hi > range.lo) : false) {
			if (m.wpm < range.lo - 10) { pace = sprintf( /* translators: 1: kind of reading, such as verse, 2: lowest pace, 3: highest pace. */ __( 'well under your %1$s range of %2$s to %3$s. Some lines may be dragging; let them move a little more.', 'foxfire-studio' ), range.name, range.lo, range.hi ); }
			else if (m.wpm < range.lo) { pace = sprintf( /* translators: 1: kind of reading, such as verse, 2: lowest pace, 3: highest pace. */ __( 'just under your %1$s range of %2$s to %3$s.', 'foxfire-studio' ), range.name, range.lo, range.hi ); }
			else if (m.wpm <= range.hi) { pace = sprintf( /* translators: 1: kind of reading, such as verse, 2: lowest pace, 3: highest pace. */ __( 'inside your %1$s range of %2$s to %3$s.', 'foxfire-studio' ), range.name, range.lo, range.hi ); }
			else if (m.wpm <= range.hi + 10) { pace = sprintf( /* translators: 1: kind of reading, such as verse, 2: lowest pace, 3: highest pace. */ __( 'just over your %1$s range of %2$s to %3$s.', 'foxfire-studio' ), range.name, range.lo, range.hi ); }
			else { pace = sprintf( /* translators: 1: kind of reading, such as verse, 2: lowest pace, 3: highest pace. */ __( 'well over your %1$s range of %2$s to %3$s. Easing off would give the words more room.', 'foxfire-studio' ), range.name, range.lo, range.hi ); }
		} else if (m.wpm < 115) { pace = __( 'unhurried, which suits a slow, atmospheric piece, though listeners can drift if it stays this slow.', 'foxfire-studio' ); }
		else if (m.wpm < 165) { pace = __( 'a comfortable listening pace.', 'foxfire-studio' ); }
		else if (m.wpm < 185) { pace = __( 'a little brisk. Easing off slightly would give the words more room.', 'foxfire-studio' ); }
		else { pace = __( 'fast for listening. Try settling back toward 140 to 155.', 'foxfire-studio' ); }
		var paceText = pace === null ? sprintf( /* translators: 1: words a minute, 2: language name, 3: how many more takes. */ _n( 'About %1$s words a minute. After %3$s more take in %2$s, the pace is compared with your own.', 'About %1$s words a minute. After %3$s more takes in %2$s, the pace is compared with your own.', range.need, 'foxfire-studio' ), m.wpm, range.name, range.need ) : sprintf( /* translators: 1: m.wpm, 2: pace. */ __( 'About %1$s words a minute, %2$s', 'foxfire-studio' ), m.wpm, pace );
		if (!voiced) {
			var gap = m.wpm - m.setWpm;
			if (Math.abs(gap) >= 12) {
				paceText += sprintf( /* translators: 1: m.setWpm, 2: gap > 0 ? __( 'ahead of', 'foxfire-studio' ) : __( 'behind', 'foxfire-studio' ). */ __( ' The prompter was set to %1$s, so you ran %2$s it.', 'foxfire-studio' ), m.setWpm, gap > 0 ? __( 'ahead of', 'foxfire-studio' ) : __( 'behind', 'foxfire-studio' ) );
			}
		}
		lines.push({ label: __( 'Pace', 'foxfire-studio' ), text: paceText });

		var share = Math.round(m.restShare * 100);
		var pauseText;
		if (share < 12) { pauseText = sprintf( /* translators: %s: share. */ __( 'Only %s%% of the reading was rest. Listeners need small gaps to take things in, so let the full stops breathe a little more.', 'foxfire-studio' ), share ); }
		else if (share <= 30) { pauseText = sprintf( /* translators: %s: share. */ __( '%s%% of the reading was rest, a natural amount of breathing room.', 'foxfire-studio' ), share ); }
		else { pauseText = sprintf( /* translators: %s: share. */ __( '%s%% of the reading was rest. That is a lot of space, which works if it is deliberate.', 'foxfire-studio' ), share ); }
		if (m.pauseEarly > 0 && m.pauseLate > 0) {
			var change = (m.pauseLate - m.pauseEarly) / m.pauseEarly;
			if (change <= -0.25) { pauseText += sprintf( /* translators: %s: Math.round(-change * 100). */ __( ' Your pauses got about %s%% shorter in the second half, which usually means the reading was speeding up.', 'foxfire-studio' ), Math.round(-change * 100) ); }
			else if (change >= 0.3) { pauseText += sprintf( /* translators: %s: Math.round(change * 100). */ __( ' Your pauses grew about %s%% longer in the second half.', 'foxfire-studio' ), Math.round(change * 100) ); }
			else { pauseText += __( ' That stayed consistent from start to finish.', 'foxfire-studio' ); }
		}
		lines.push({ label: __( 'Pauses', 'foxfire-studio' ), text: pauseText });

		if (m.endDropDb !== null) {
			var pct = Math.round((1 - Math.pow(2, -m.endDropDb / 10)) * 100);
			var endText;
			/*
			 * 1.15.1: the line judges the typical ending, the flags each
			 * sentence, so once the review knows how many Faded ending flags
			 * stand (fade meant cues left out), a line that would say all is
			 * well says how many did not.
			 */
			var nFaded = faded > 0 ? faded : 0;
			var fadedNote = nFaded ? ' ' + sprintf( /* translators: %s: how many sentences were flagged Faded ending. */ _n( '%s sentence faded further: see the Faded ending flag.', '%s sentences faded further: see the Faded ending flags.', nFaded, 'foxfire-studio' ), nFaded ) : '';
			/*
			 * 1.15.1: verse and ritual readings trail their line endings on
			 * purpose, so their bands match the Faded ending flags (14 dB).
			 */
			if (range ? range.trails : false) {
				if (m.endDropDb < 8) { endText = (nFaded ? sprintf( /* translators: %s: kind of reading, such as verse. */ __( 'For %s, most of your line endings stayed steady and clear.', 'foxfire-studio' ), range.name ) : sprintf( /* translators: %s: kind of reading, such as verse. */ __( 'For %s, your line endings stayed steady and clear to the last word.', 'foxfire-studio' ), range.name )) + fadedNote; }
				else if (m.endDropDb < 14) { endText = sprintf( /* translators: 1: kind of reading, such as verse, 2: pct. */ __( 'Line endings eased off about %2$s%% in volume, a natural trail for %1$s.', 'foxfire-studio' ), range.name, pct ) + fadedNote; }
				else { endText = sprintf( /* translators: 1: kind of reading, such as verse, 2: pct. */ __( 'Line endings sank about %2$s%% in volume, more than %1$s usually wants. Check the Faded ending flags, and mark any fade you meant with a Fade meant cue.', 'foxfire-studio' ), range.name, pct ); }
			}
			else if (m.endDropDb < 4) { endText = (nFaded ? __( 'You carried most of your phrases through to the last word.', 'foxfire-studio' ) : __( 'You carried your phrases through to the last word. Nicely done.', 'foxfire-studio' )) + fadedNote; }
			else if (m.endDropDb < 8) { endText = sprintf( /* translators: %s: pct. */ __( 'Phrase endings faded about %s%% in volume. Some of that is natural, but it is worth noticing.', 'foxfire-studio' ), pct ) + fadedNote; }
			else { endText = sprintf( /* translators: %s: pct. */ __( 'Phrase endings dropped about %s%% in volume, so last words may get lost. Keep a little breath in reserve for the end of each line.', 'foxfire-studio' ), pct ) + fadedNote; }
			lines.push({ label: __( 'Endings', 'foxfire-studio' ), text: endText });
		}

		if (m.pitchSpread !== null) {
			var pitchText;
			if (m.pitchSpread < 1.3) { pitchText = __( 'Your pitch stayed fairly flat within phrases. Letting your voice rise and fall on the important words would bring more life to it.', 'foxfire-studio' ); }
			else if (m.pitchSpread < 2.2) { pitchText = __( 'A moderate amount of movement in your voice, steady without going flat.', 'foxfire-studio' ); }
			else { pitchText = __( 'Plenty of movement in your voice. It sounds alive and engaged.', 'foxfire-studio' ); }
			if (m.flatAt !== null) { pitchText += sprintf( /* translators: %s: fmtTime(m.flatAt). */ __( ' The flattest stretch was around %s in the recording.', 'foxfire-studio' ), fmtTime(m.flatAt) ); }
			lines.push({ label: __( 'Pitch', 'foxfire-studio' ), text: pitchText });
		}
		if (m.energySpread !== null ? m.energySpread !== undefined : false) {
			var en = m.energySpread;
			var enText;
			if (en < 3.5) { enText = sprintf( /* translators: %s: en.toFixed(1). */ __( 'Your loudness stayed fairly even within phrases (about %s dB of movement). Leaning into a key word now and then would add some lift.', 'foxfire-studio' ), en.toFixed(1) ); }
			else if (en < 6.5) { enText = sprintf( /* translators: %s: en.toFixed(1). */ __( 'Your loudness rose and fell naturally within phrases (about %s dB of movement).', 'foxfire-studio' ), en.toFixed(1) ); }
			else { enText = sprintf( /* translators: %s: en.toFixed(1). */ __( 'Plenty of energy: your loudness moved about %s dB within phrases. If listeners need to turn it up and down, smooth the biggest swings a little.', 'foxfire-studio' ), en.toFixed(1) ); }
			lines.push({ label: __( 'Energy', 'foxfire-studio' ), text: enText });
		}
		if (m.room) {
			var rm = m.room;
			var roomText;
			var dn = rm.denoise;
			var cleaned = !!(dn && isFinite(dn.dropDb) && dn.dropDb >= 1 && isFinite(dn.snrAfter));
			var snr = Math.round(rm.snr);
			/* 1.12.1: when noise reduction ran, the room is rated by what is left after it,
			   as Check microphone does, so a quiet phone take is no longer called noisy. */
			var judged = cleaned ? Math.max(rm.snr, dn.snrAfter) : rm.snr;
			if (cleaned) {
				var after = Math.round(dn.snrAfter), drop = Math.round(dn.dropDb);
				if (judged >= 45) { roomText = sprintf( /* translators: 1: decibels below the voice as recorded, 2: decibels noise reduction removed, 3: decibels below the voice after it. */ __( 'A quiet room once cleaned up: the background sat %1$s dB below your voice as recorded, and noise reduction lowered it by %2$s dB, to %3$s dB below. It should be well down in the finished reading.', 'foxfire-studio' ), snr, drop, after ); }
				else if (judged >= 35) { roomText = sprintf( /* translators: 1: decibels below the voice as recorded, 2: decibels noise reduction removed, 3: decibels below the voice after it. */ __( 'A fairly quiet room once cleaned up: the background sat %1$s dB below your voice as recorded, and noise reduction lowered it by %2$s dB, to %3$s dB below. A quieter spot would help a little.', 'foxfire-studio' ), snr, drop, after ); }
				else { roomText = sprintf( /* translators: 1: decibels below the voice as recorded, 2: decibels noise reduction removed, 3: decibels below the voice after it. */ __( 'A noisy room: the background sat only %1$s dB below your voice as recorded, and noise reduction lowered it by %2$s dB, to %3$s dB below, so it may still be heard in pauses.', 'foxfire-studio' ), snr, drop, after ); }
			} else {
				if (rm.snr >= 45) { roomText = sprintf( /* translators: %s: Math.round(rm.snr). */ __( 'A quiet room: the background sits %s dB below your voice.', 'foxfire-studio' ), snr ); }
				else if (rm.snr >= 35) { roomText = sprintf( /* translators: %s: decibels below the voice. */ __( 'A fairly quiet room: the background sits %s dB below your voice. A quieter spot would help a little.', 'foxfire-studio' ), snr ); }
				else { roomText = sprintf( /* translators: %s: Math.round(rm.snr). */ __( 'A noisy room: the background sits only %s dB below your voice.', 'foxfire-studio' ), snr ); }
			}
			var fixed = [];
			if (rm.clicks.length) { fixed.push(sprintf( /* translators: %s: rm.clicks.length + (_n( ' click', ' clicks', rm.clicks.lengt. */ __( '%s before phrases', 'foxfire-studio' ), rm.clicks.length + (_n( ' click', ' clicks', rm.clicks.length, 'foxfire-studio' )) )); }
			if (rm.strays.length) {
				/* 1.31.0: times are shown to the second, so sounds a moment apart are listed once (the count stays true). */
				var strayAt = api.strayTimes(rm.strays, fmtTime);
				fixed.push(sprintf( /* translators: 1: how many stray sounds, 2: their times, 3: ', ...' when there are more. */ _n( '%1$s stray sound in pauses (%2$s%3$s)', '%1$s stray sounds in pauses (%2$s%3$s)', rm.strays.length, 'foxfire-studio' ), rm.strays.length, strayAt.slice(0, 4).join(', '), strayAt.length > 4 ? ', ...' : '' ));
			}
			if (rm.whistleShare >= 0.05) { fixed.push(__( 'whistling in your S sounds', 'foxfire-studio' )); }
			if (fixed.length) { roomText += sprintf( /* translators: %s: fixed.join(', '). */ __( ' Cleaned up: %s.', 'foxfire-studio' ), fixed.join(', ') ); }
			if (dn && isFinite(dn.dropDb)) {
				if (!cleaned) { roomText += __( ' Noise reduction found little to lower.', 'foxfire-studio' ); }
			} else if (rm.denoiseFailed) {
				roomText += sprintf( /* translators: 1: rm.denoiseFailed.charAt(0).toLowerCase(), 2: rm.denoiseFailed.slice(1). */ __( ' Noise reduction could not run this time (%1$s%2$s), so only the steady hush was lowered.', 'foxfire-studio' ), rm.denoiseFailed.charAt(0).toLowerCase(), rm.denoiseFailed.slice(1) );
			}
			/* The stronger cleanup (1.16.0): which engine did it, why it fell back, and when to try it. */
			var stronger = !!(dn && dn.engine === 'stronger');
			if (stronger) {
				roomText += __( ' The stronger cleanup did this.', 'foxfire-studio' );
			} else if (rm.strongerFailed) {
				roomText += sprintf( /* translators: %s: why the stronger cleanup did not run. */ __( ' The stronger cleanup could not run this time (%s), so the standard one was used.', 'foxfire-studio' ), rm.strongerFailed.charAt(0).toLowerCase() + rm.strongerFailed.slice(1) );
			} else if (rm.strongerReady && dn && isFinite(dn.dropDb) && (rm.snr < 35 || dn.tones >= 2)) {
				roomText += dn.tones >= 2
					? __( ' There are steady tones in the pauses, such as a bell or a hum. The stronger cleanup takes those out better: compare it on a sentence in Hear the polish, or choose it in Settings.', 'foxfire-studio' )
					: __( ' The stronger cleanup may take out more: compare it on a sentence in Hear the polish, or choose it in Settings.', 'foxfire-studio' );
			}
			lines.push({ label: __( 'Room', 'foxfire-studio' ), text: roomText });
		}
		return lines;
	}

	var api = {
		analyseReading: analyseReading,
		describeReading: describeReading,
		/* Stray sounds' times as shown, each shown time once, in order. */
		strayTimes: function (secs, fmtTime) {
			var out = [];
			(secs || []).forEach(function (t) {
				var s = fmtTime(t);
				if (out.indexOf(s) === -1) { out.push(s); }
			});
			return out;
		}
	};
	root.RRTReadout = api;
	if (typeof module !== 'undefined') {
		if (module.exports) { module.exports = api; }
	}
})(typeof window !== 'undefined' ? window : this);
