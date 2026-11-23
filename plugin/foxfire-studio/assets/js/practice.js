/*
 * Foxfire Studio: practice tools for the teleprompter (0.14.0 and 0.15.0).
 *
 * - Microphone check: a ten second test before recording. Four seconds of
 *   quiet measure the room, then the reader reads the opening line aloud. It
 *   reports how far the room sits below the voice (on the same scale as the
 *   review's Room line) and whether the voice is too quiet, about right or
 *   close to clipping, with practical suggestions.
 * - Practice focus: what each habit means for the review (which tile leads,
 *   which flags are listed first, which line of the read-out comes first).
 * - Opening drill: where a drill take stops.
 * - Cue marks (0.15.0): placing saved cues back on the script, and how
 *   long each one holds the timed scroll.
 * - Warm-up (0.15.0): breathing, tongue twisters and a pacing passage in a
 *   panel over the page, with a live level bar from the microphone. Nothing
 *   is recorded or saved.
 *
 * window.RRTPractice. The helpers at the top are pure and tested in Node.
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

	var P = {};

	/*
	 * ---------------------------------------------------------------------
	 * Pure helpers
	 * ---------------------------------------------------------------------
	 */

	/* Loudness in dBFS of a block of samples (root mean square). */
	function rmsDb(x, a, b) {
		var s = 0;
		var n = 0;
		for (var i = a; i < b; i++) { s += x[i] * x[i]; n++; }
		return n ? 10 * Math.log10(s / n + 1e-12) : -120;
	}
	P.rmsDb = rmsDb;

	/* A value at a fraction of the way through a sorted copy of a list. */
	function pct(list, f) {
		if (!list.length) { return null; }
		var s = list.slice().sort(function (a, b) { return a - b; });
		return s[Math.min(s.length - 1, Math.max(0, Math.floor(f * (s.length - 1))))];
	}
	P.pct = pct;

	/*
	 * Levels from the two parts of a check.
	 *
	 * @param quiet  Samples recorded while the room was quiet.
	 * @param voice  Samples recorded while the reader read aloud.
	 * @param rate   Sample rate.
	 * @return roomDb (the room's steady level), voiceDb (the voice's typical
	 *         loud level), peakDb, gap (voice minus room) and spoke (whether
	 *         any speech was heard at all).
	 */
	P.levels = function (quiet, voice, rate) {
		var win = Math.max(1, Math.floor(rate * 0.05));
		var q = [];
		var v = [];
		var i;
		for (i = 0; i + win <= quiet.length; i += win) { q.push(rmsDb(quiet, i, i + win)); }
		for (i = 0; i + win <= voice.length; i += win) { v.push(rmsDb(voice, i, i + win)); }
		/* The room: the quieter half of the quiet part, so a cough or a click does not count. */
		var room = pct(q, 0.3);
		/* The voice: its loud stretches, the way a listener hears it. */
		var loud = pct(v, 0.9);
		var peak = 0;
		for (i = 0; i < voice.length; i++) { peak = Math.max(peak, Math.abs(voice[i])); }
		var peakDb = 20 * Math.log10(peak + 1e-12);
		var gap = room === null || loud === null ? null : loud - room;
		return { roomDb: room, voiceDb: loud, peakDb: peakDb, gap: gap, spoke: gap !== null && gap >= 8 };
	};

	/*
	 * The lift a take will need (1.9.0). lv: levels with voiceLufs (the voice
	 * part's loudness, BS.1770). o: target (the voice's LUFS target, -19 for
	 * a mono podcast), mode, nrDb (how far noise reduction lowers the room:
	 * 20 for Medium), liftLimit (RRTSound.liftLimit), shown (the file's own
	 * loudness, named in the words; a stereo preset's voice target is 3 LU lower). Returns null when there
	 * is nothing to say, else { need, limit, over, short, target }: the
	 * decibels needed to reach the target, the most this room allows, and
	 * whether the take would fall short, by how much.
	 */
	P.lift = function (lv, o) {
		o = o || {};
		if (!lv || !lv.spoke || typeof o.liftLimit !== 'function') { return null; }
		if (lv.voiceLufs === null || lv.voiceLufs === undefined || !isFinite(lv.voiceLufs)) { return null; }
		var target = isFinite(Number(o.target)) && o.target !== null ? Number(o.target) : -19;
		var need = target - lv.voiceLufs;
		var room = lv.roomDb === null || lv.roomDb === undefined ? null : lv.roomDb - Math.max(0, Number(o.nrDb) || 0);
		var lim = o.liftLimit(room, target, o.mode || 'podcast');
		var limit = lim.limit;
		return { need: need, limit: limit, over: need > limit + 0.5, short: Math.max(0, need - limit), nrDb: Math.max(0, Number(o.nrDb) || 0), target: isFinite(Number(o.shown)) && o.shown !== null && o.shown !== undefined ? Number(o.shown) : Math.round(target * 10) / 10 };
	};

	/*
	 * What a check means, in plain words. Room thresholds match the review's
	 * Room line: 45 dB or more is quiet, 35 or more fairly quiet, less noisy.
	 */
	/*
	 * lift (1.9.0): from P.lift, or null. phone (1.9.0): true on a phone or
	 * tablet, for tips that fit it instead of a laptop's.
	 */
	P.verdict = function (lv, lift, phone) {
		var out = { room: '', level: '', lines: [], tips: [], lift: lift || null };
		if (!lv.spoke) {
			out.room = 'unknown';
			out.level = 'silent';
			out.lines.push(__( 'No voice came through in the second part, so the room could not be compared with it. Check the right microphone is chosen in the browser, then try again.', 'foxfire-studio' ));
			return out;
		}
		var g = Math.round(lv.gap);
		if (g >= 45) {
			out.room = 'quiet';
			out.lines.push(sprintf( /* translators: %s: g. */ __( 'A quiet room: the background sits %s dB below your voice.', 'foxfire-studio' ), g ));
		} else if (g >= 35) {
			out.room = 'fair';
			out.lines.push(sprintf( /* translators: %s: g. */ __( 'A fairly quiet room: the background sits %s dB below your voice. Fine for recording; a little quieter would be better still.', 'foxfire-studio' ), g ));
		} else if (lift && lift.nrDb > 0 && g + lift.nrDb >= 45) {
			/* Noise reduction (1.9.0) will take it well down, so the room is fair for recording. */
			out.room = 'noisy';
			out.lines.push(sprintf( /* translators: 1: g, 2: decibels noise reduction removes. */ __( 'The background sits %1$s dB below your voice. Noise reduction lowers it by up to %2$s dB more, so it should be well down in the finished reading, though a quieter room is still better.', 'foxfire-studio' ), g, lift.nrDb ));
		} else {
			out.room = 'noisy';
			out.lines.push(sprintf( /* translators: %s: g. */ __( 'A noisy room: the background sits only %s dB below your voice, so it will be heard in pauses.', 'foxfire-studio' ), g ));
		}
		if (lv.peakDb > -1) {
			out.level = 'clipped';
			out.lines.push(__( 'Your voice reached the very top of the scale, so the loudest words may crackle. Sit a little further from the microphone or turn its level down.', 'foxfire-studio' ));
		} else if (lv.voiceDb > -10) {
			out.level = 'hot';
			out.lines.push(__( 'Your voice is loud and close to the top. It will work, but a little less would leave room for emphasis.', 'foxfire-studio' ));
		} else if (lv.voiceDb < -38) {
			out.level = 'quiet';
			if (!lift) { out.lines.push(__( 'Your voice came through quietly. Sit a little closer or speak up slightly; leveling can lift it, but it lifts the room too.', 'foxfire-studio' )); }
		} else {
			out.level = 'good';
			out.lines.push(__( 'Your voice level is about right.', 'foxfire-studio' ));
		}
		/* The lift a take will need (1.9.0), and whether this room allows it. */
		if (lift) {
			var need = Math.round(lift.need), lim = Math.round(lift.limit);
			if (need < 1) {
				out.lines.push(sprintf( /* translators: %s: target loudness in LUFS. */ __( 'A take at this level is already loud enough for %s LUFS, so it needs no lift.', 'foxfire-studio' ), lift.target ));
			} else if (!lift.over) {
				out.lines.push(sprintf( /* translators: 1: decibels needed, 2: target loudness in LUFS, 3: the most the studio can raise a take here, in decibels. */ __( 'A take at this level needs about %1$s dB of lift to reach %2$s LUFS. The studio can raise it by up to %3$s dB in this room, so it will reach the target.', 'foxfire-studio' ), need, lift.target, lim ));
			} else {
				out.lines.push(sprintf( /* translators: 1: decibels needed, 2: target loudness in LUFS, 3: the most the studio can raise a take here, in decibels, 4: how many decibels short the take would be. */ __( 'A take at this level needs about %1$s dB of lift to reach %2$s LUFS, more than the %3$s dB the studio can raise it in this room, so it would come out about %4$s dB quiet. Move closer to the microphone before recording: halving the distance makes your voice about 6 dB louder.', 'foxfire-studio' ), need, lift.target, lim, Math.max(1, Math.round(lift.short)) ));
				if (phone) { out.tips.push(__( 'Hold the phone about a hand’s width from your mouth, a little to one side so your breath does not hit the microphone.', 'foxfire-studio' )); }
			}
		}
		if ('quiet' !== out.room ? phone : false) {
			/* A phone or tablet (1.9.0): tips for where it is, not for a laptop. */
			out.tips.push(__( 'Rest the phone on something soft, such as a folded towel, or hold it still: knocks and taps on a table carry straight to its microphone.', 'foxfire-studio' ));
			out.tips.push(__( 'Move away from fans, the fridge and open windows, and switch off anything humming nearby.', 'foxfire-studio' ));
			out.tips.push(__( 'A small space full of soft things (a closet of clothes, curtains, a bed) soaks up echo.', 'foxfire-studio' ));
			return out;
		}
		if ('quiet' !== out.room) {
			out.tips.push(__( 'Put something soft under the laptop, such as a folded towel: it stops desk and fan rumble reaching the built-in microphone.', 'foxfire-studio' ));
			out.tips.push(__( 'Move the laptop or microphone a little further from fans, the fridge and open windows, and switch off anything humming nearby.', 'foxfire-studio' ));
			out.tips.push(__( 'Soft things around you (curtains, a bed, cushions) soak up echo.', 'foxfire-studio' ));
		}
		return out;
	};

	/*
	 * Practice focus: one habit per session. For each, the take summary's
	 * number it leads with, which way is better (1 up, -1 down, 0 neither),
	 * the review's flag kinds it lists first, and the read-out line it opens with.
	 */
	P.focuses = {
		pace: { label: __( 'Pace', 'foxfire-studio' ), key: 'wpm', better: 0, unit: ' wpm', kinds: ['rushed', 'slow'], line: __( 'Pace', 'foxfire-studio' ) },
		endings: { label: __( 'Endings', 'foxfire-studio' ), key: 'endDrop', better: -1, unit: __( ' dB fade', 'foxfire-studio' ), kinds: ['faded'], line: __( 'Endings', 'foxfire-studio' ) },
		fillers: { label: __( 'Fillers', 'foxfire-studio' ), key: 'filler', better: -1, unit: '', kinds: ['filler'], line: '' },
		pauses: { label: __( 'Pauses', 'foxfire-studio' ), key: 'pauses', better: 0, unit: __( ' long pauses', 'foxfire-studio' ), kinds: ['pause', 'hesitant'], line: __( 'Pauses', 'foxfire-studio' ) },
		pitch: { label: __( 'Pitch', 'foxfire-studio' ), key: 'pitch', better: 1, unit: ' semitones', kinds: ['flat'], line: __( 'Pitch', 'foxfire-studio' ) },
		energy: { label: __( 'Energy', 'foxfire-studio' ), key: 'energy', better: 1, unit: ' dB', kinds: [], line: __( 'Energy', 'foxfire-studio' ) },
		accuracy: { label: __( 'Accuracy', 'foxfire-studio' ), key: 'acc', better: 1, unit: '%', kinds: ['omit', 'sub', 'added', 'repeat'], line: '' }
	};

	/* Read-out lines with the focus's line first, and the rest set aside. */
	P.orderLines = function (lines, focus) {
		var f = P.focuses[focus];
		if (!f || !f.line) { return { first: [], rest: lines }; }
		return {
			first: lines.filter(function (l) { return l.label === f.line; }),
			rest: lines.filter(function (l) { return l.label !== f.line; })
		};
	};

	/*
	 * The take to compare with: the latest earlier take of the same kind (an
	 * opening drill with drills, a full reading with full readings), and for
	 * a focus, the latest earlier take with the same focus.
	 */
	P.previous = function (takes, now, sameFocus) {
		for (var i = takes.length - 2; i >= 0; i--) {
			var t = takes[i];
			if ((t.drill || '') !== (now.drill || '')) { continue; }
			if (sameFocus && (t.focus || '') !== (now.focus || '')) { continue; }
			return t;
		}
		return null;
	};

	/*
	 * Where an opening drill stops: once the limit has passed, at the end of the
	 * sentence being read. Returns the index of that sentence's last word.
	 *
	 * @param sents  Each word's sentence number.
	 * @param at     The word being read when the limit passed.
	 */
	P.drillStopWord = function (sents, at) {
		var s = sents[at];
		var i = at;
		while (i + 1 < sents.length && sents[i + 1] === s) { i++; }
		return i;
	};

	/* The text of the first sentence, for the microphone check. */
	P.firstLine = function (words, sents, blocks) {
		if (!words.length) { return ''; }
		var from = 0;
		/* A heading in its own block, such as the title, is skipped when a paragraph follows it. */
		if (blocks && blocks.length === words.length) {
			var b = 0;
			while (b < words.length && blocks[b] === blocks[0]) { b++; }
			if (b < 8 && b < words.length) { from = b; }
		}
		var out = [];
		for (var i = from; i < words.length && sents[i] === sents[from]; i++) { out.push(words[i]); }
		/* A short title alone is not much to read: carry on into the next sentence. */
		if (out.length < 6) {
			var s2 = sents[i];
			for (; i < words.length && sents[i] === s2; i++) { out.push(words[i]); }
		}
		return out.join(' ');
	};

	/*
	 * ---------------------------------------------------------------------
	 * The microphone check
	 * ---------------------------------------------------------------------
	 */

	var CHECK_KEY = 'rrtMicCheck';

	/* The last check in this browser, or null. */
	P.lastCheck = function () {
		try {
			var c = JSON.parse(window.localStorage.getItem(CHECK_KEY) || 'null');
			return c && typeof c.at === 'number' ? c : null;
		} catch (e) { return null; }
	};

	/* A one-line summary for the control bar. */
	P.summary = function (c) {
		if (!c) { return ''; }
		if ('silent' === c.level) { return __( 'Mic: no voice heard', 'foxfire-studio' ); }
		var lv = { clipped: __( ', clipping', 'foxfire-studio' ), hot: __( ', loud', 'foxfire-studio' ), quiet: __( ', quiet voice', 'foxfire-studio' ), good: '' };
		/* A check whose take would need more lift than the room allows says so before recording (1.9.0). */
		var more = c.liftOver ? __( ', too quiet: move closer', 'foxfire-studio' ) : (lv[c.level] || '');
		return sprintf( /* translators: 1: c.gap, 2: lv[c.level] || ''. */ __( 'Mic: room %1$s dB below voice%2$s', 'foxfire-studio' ), c.gap, more );
	};

	function el(tag, cls, text) {
		var e = document.createElement(tag);
		if (cls) { e.className = cls; }
		if (text !== undefined) { e.textContent = text; }
		return e;
	}

	/*
	 * Runs a check in a panel over the page.
	 *
	 * @param opts firstLine (text to read aloud), onDone (called with the saved result).
	 */
	P.micCheck = function (opts) {
		var old = document.querySelector('.tp-mic');
		if (old) { old.parentNode.removeChild(old); }
		var box = el('div', 'tp-result tp-mic is-on');
		box.setAttribute('role', 'dialog');
		box.setAttribute('aria-label', __( 'Microphone check', 'foxfire-studio' ));
		box.appendChild(el('h2', '', __( 'Microphone check', 'foxfire-studio' )));
		var say = el('p', 'tp-mic-say', __( 'This takes about ten seconds. First, stay quiet for four seconds so the room can be measured.', 'foxfire-studio' ));
		say.setAttribute('aria-live', 'polite');
		box.appendChild(say);
		var line = el('p', 'tp-mic-line');
		line.hidden = true;
		box.appendChild(line);
		var meter = el('div', 'tp-mic-meter');
		var bar = el('i');
		meter.appendChild(bar);
		box.appendChild(meter);
		var out = el('div', 'tp-mic-out');
		box.appendChild(out);
		var row = el('div', 'tp-row');
		var go = el('button', 'tp-btn primary', __( 'Start', 'foxfire-studio' ));
		go.type = 'button';
		var close = el('button', 'tp-btn', __( 'Close', 'foxfire-studio' ));
		close.type = 'button';
		close.setAttribute('data-dlg-close', '');
		row.appendChild(go);
		row.appendChild(close);
		box.appendChild(row);
		document.body.appendChild(box);
		go.focus();

		var stream = null;
		var ctx = null;
		var timer = 0;
		var raf = 0;
		function stop() {
			window.clearTimeout(timer);
			window.cancelAnimationFrame(raf);
			if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); }
			if (ctx) { try { ctx.close(); } catch (e) {} }
			stream = null;
			ctx = null;
		}
		close.addEventListener('click', function () { stop(); box.parentNode.removeChild(box); });

		go.addEventListener('click', function () {
			go.disabled = true;
			out.innerHTML = '';
			if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
				say.textContent = __( 'This browser cannot use a microphone here.', 'foxfire-studio' );
				go.disabled = false;
				return;
			}
			navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: false, noiseSuppression: false, autoGainControl: false, channelCount: 1 } }).then(function (s) {
				stream = s;
				var AC = window.AudioContext || window.webkitAudioContext;
				ctx = new AC();
				var src = ctx.createMediaStreamSource(stream);
				var an = ctx.createAnalyser();
				an.fftSize = 2048;
				src.connect(an);
				var buf = new Float32Array(an.fftSize);
				var quiet = [];
				var voice = [];
				var phase = 'quiet';
				var t0 = window.performance.now();
				var lastT = t0;
				/* Gather the newest samples about 20 times a second, as levels and peaks. */
				function tick(now) {
					an.getFloatTimeDomainData(buf);
					var take = Math.min(buf.length, Math.round((now - lastT) / 1000 * ctx.sampleRate));
					lastT = now;
					var part = buf.subarray(buf.length - take);
					var dest = 'quiet' === phase ? quiet : voice;
					for (var i = 0; i < part.length; i++) { dest.push(part[i]); }
					var db = rmsDb(buf, 0, buf.length);
					bar.style.width = Math.max(0, Math.min(100, (db + 70) / 70 * 100)) + '%';
					raf = window.requestAnimationFrame(tick);
				}
				raf = window.requestAnimationFrame(tick);
				say.textContent = __( 'Stay quiet...', 'foxfire-studio' );
				timer = window.setTimeout(function () {
					phase = 'voice';
					say.textContent = __( 'Now read this aloud, as you would for a recording:', 'foxfire-studio' );
					line.textContent = opts.firstLine || __( 'The quick brown fox jumps over the lazy dog, and then rests a while in the shade.', 'foxfire-studio' );
					line.hidden = false;
					timer = window.setTimeout(function () {
						var rate = ctx.sampleRate;
						stop();
						line.hidden = true;
						bar.style.width = '0';
						/* Leave out the first half second of each part, while the room settles and the reader starts. */
						var cut = Math.floor(rate * 0.5);
						var vs = new Float32Array(voice.slice(cut));
						var lv = P.levels(new Float32Array(quiet.slice(cut)), vs, rate);
						/* The voice part's loudness, measured as a finished reading is (1.9.0). */
						var SND = root.RRTSound || null;
						lv.voiceLufs = SND && SND.lufs ? SND.lufs(vs, rate) : null;
						var lift = opts.lift && SND && SND.liftLimit ? P.lift(lv, { target: opts.lift.target, shown: opts.lift.shown, mode: opts.lift.mode, nrDb: opts.lift.nrDb, liftLimit: SND.liftLimit }) : null;
						var v = P.verdict(lv, lift, !!opts.phone);
						var r0 = function (x) { return x === null || x === undefined || !isFinite(x) ? null : Math.round(x); };
						var res = { at: Date.now(), gap: r0(lv.gap), roomDb: r0(lv.roomDb), voiceDb: r0(lv.voiceDb), peakDb: Math.round(lv.peakDb * 10) / 10, room: v.room, level: v.level, voiceLufs: lv.voiceLufs === null || !isFinite(lv.voiceLufs) ? null : Math.round(lv.voiceLufs * 10) / 10, liftNeed: lift ? Math.round(lift.need) : null, liftMax: lift ? Math.round(lift.limit) : null, liftOver: lift ? lift.over : false };
						try { window.localStorage.setItem(CHECK_KEY, JSON.stringify(res)); } catch (e) {}
						say.textContent = __( 'Done.', 'foxfire-studio' );
						v.lines.forEach(function (l) { out.appendChild(el('p', '', l)); });
						if (v.tips.length) {
							var ul = el('ul', 'tp-mic-tips');
							v.tips.forEach(function (t) { ul.appendChild(el('li', '', t)); });
							out.appendChild(ul);
						}
						go.textContent = __( 'Check again', 'foxfire-studio' );
						go.disabled = false;
						if (opts.onDone) { opts.onDone(res); }
					}, 6000);
				}, 4000);
			}, function () {
				say.textContent = __( 'The microphone was not available. Allow it for this site in the browser, then try again.', 'foxfire-studio' );
				go.disabled = false;
			});
		});
	};

	/*
	 * ---------------------------------------------------------------------
	 * Cue marks (0.15.0)
	 * ---------------------------------------------------------------------
	 * A cue belongs to one word of the script: { i (word number), k (the word,
	 * cleaned, to find it again if the post changes), a ('pause', 'breath' or
	 * ''), e (true for emphasis) }. The post itself is never changed.
	 */

	/* How long each cue holds the timed scroll, in seconds. */
	P.CUE_SECS = { pause: 0.7, breath: 1.1 };
	/* An emphasised word is given a little more time. */
	P.CUE_EM = 1.2;

	/* A tidy list: valid entries only, one per word, in script order. */
	P.cueClean = function (list) {
		var seen = {};
		var out = [];
		(Array.isArray(list) ? list : []).forEach(function (c) {
			if (!c || typeof c !== 'object') { return; }
			var i = parseInt(c.i, 10);
			if (!(i >= 0) || i > 100000) { return; }
			var a = ('pause' === c.a || 'breath' === c.a) ? c.a : '';
			var e = !!c.e;
			var f = !!c.f;
			var n = P.noteText(c.n);
			if (!a && !e && !f && !n) { return; }
			if (seen[i]) { return; }
			seen[i] = true;
			out.push({ i: i, k: String(c.k || '').slice(0, 40), a: a, e: e, f: f, n: n });
		});
		out.sort(function (x, y) { return x.i - y.i; });
		return out.slice(0, 3000);
	};

	/*
	 * Puts saved cues back on the script. A cue stays on its word when the word
	 * still matches; otherwise it moves to the nearest matching word within 40
	 * words (the post was edited), or is left out.
	 *
	 * @param list Saved cues.
	 * @param keys The cleaned word of every script word, in order.
	 * @return { cues (placed, with i updated), moved, lost }.
	 */
	P.cuePlace = function (list, keys) {
		var used = {};
		var out = [];
		var moved = 0;
		var lost = 0;
		P.cueClean(list).forEach(function (c) {
			var at = -1;
			if (c.i < keys.length && keys[c.i] === c.k && !used[c.i]) {
				at = c.i;
			} else if (c.k) {
				for (var d = 1; d <= 40 && at < 0; d++) {
					var lo = c.i - d;
					var hi = c.i + d;
					if (lo >= 0 && lo < keys.length && keys[lo] === c.k && !used[lo]) { at = lo; }
					else if (hi < keys.length && keys[hi] === c.k && !used[hi]) { at = hi; }
				}
				if (at >= 0) { moved++; }
			}
			if (at < 0) { lost++; return; }
			used[at] = true;
			out.push({ i: at, k: c.k, a: c.a, e: c.e, f: c.f, n: c.n });
		});
		out.sort(function (x, y) { return x.i - y.i; });
		return { cues: out, moved: moved, lost: lost };
	};

	/*
	 * ---------------------------------------------------------------------
	 * Pronunciation notes (1.1.0)
	 * ---------------------------------------------------------------------
	 * A note such as "SOW-in" shown above a word. It sits on one word only
	 * (the cue's n), on every use of the word in this post, or on every use
	 * in any post (the reader's own). The nearest one wins.
	 */

	/* The longest note, in characters (the server keeps the same limit). */
	P.NOTE_LEN = 40;

	/* A note's text: one line of plain text, at most NOTE_LEN characters. */
	P.noteText = function (n) {
		if (typeof n !== 'string' ? typeof n !== 'number' : false) { return ''; }
		return String(n).replace(/<[^>]*>/g, '').replace(/[\u0000-\u001F\u007F]+/g, ' ').replace(/\s+/g, ' ').trim().slice(0, P.NOTE_LEN).trim();
	};

	/* A list of { k, n } as a lookup from word to note; bad entries are left out, the first of a word wins. */
	P.noteMap = function (list) {
		var out = {};
		var count = 0;
		(Array.isArray(list) ? list : []).forEach(function (x) {
			if (!x || typeof x !== 'object' || count >= 1000) { return; }
			var k = String(x.k || '').slice(0, 40);
			var n = P.noteText(x.n);
			if (!k || !n || Object.prototype.hasOwnProperty.call(out, k)) { return; }
			out[k] = n;
			count++;
		});
		return out;
	};

	/* A lookup back as a list sorted by word, as the site saves it. */
	P.noteList = function (map) {
		return Object.keys(map || {}).sort().map(function (k) { return { k: k, n: map[k] }; });
	};

	/*
	 * The note to show on a word, and where it is kept: 'here' (this word only),
	 * 'post' (every use in this post) or 'all' (every post). Null for none.
	 */
	P.noteFor = function (cue, key, postMap, mineMap) {
		if (cue ? !!cue.n : false) { return { n: cue.n, scope: 'here' }; }
		if (!key) { return null; }
		var has = Object.prototype.hasOwnProperty;
		if (postMap ? has.call(postMap, key) : false) { return { n: postMap[key], scope: 'post' }; }
		if (mineMap ? has.call(mineMap, key) : false) { return { n: mineMap[key], scope: 'all' }; }
		return null;
	};

	/*
	 * ---------------------------------------------------------------------
	 * Saving changes only (1.15.0)
	 * ---------------------------------------------------------------------
	 * The studio used to send a whole list of notes or cue marks with each
	 * save, so a tab opened earlier could undo a change made since on the
	 * Pronunciation screen. Now it sends only what it changed.
	 */

	/* What changed from base to now, as word key: note ('' for removed). Both are lookups from noteMap. */
	P.noteDiff = function (now, base) {
		var out = {};
		var has = Object.prototype.hasOwnProperty;
		Object.keys(now || {}).forEach(function (k) { if (!has.call(base || {}, k) || base[k] !== now[k]) { out[k] = now[k]; } });
		Object.keys(base || {}).forEach(function (k) { if (!has.call(now || {}, k)) { out[k] = ''; } });
		return out;
	};

	/* A notes lookup with changes (word key: note, '' to remove) applied. */
	P.noteApply = function (map, changes) {
		var out = {};
		Object.keys(map || {}).forEach(function (k) { out[k] = map[k]; });
		Object.keys(changes || {}).forEach(function (k) {
			var n = P.noteText(changes[k]);
			if (n) { out[k] = n; } else { delete out[k]; }
		});
		return out;
	};

	/* One cue as text, to compare two cues. */
	function cueSig(c) { return c ? [c.k || '', c.a || '', c.e ? 1 : 0, c.f ? 1 : 0, c.n || ''].join('\u0001') : ''; }

	/* What changed from the base list to the current one: a list of { i, cue }, cue null where a mark went. */
	P.cueDiff = function (now, base) {
		var b = {};
		var n = {};
		var out = [];
		P.cueClean(base || []).forEach(function (c) { b[c.i] = c; });
		P.cueClean(now || []).forEach(function (c) { n[c.i] = c; });
		Object.keys(n).forEach(function (i) { if (cueSig(n[i]) !== cueSig(b[i])) { out.push({ i: Number(i), cue: n[i] }); } });
		Object.keys(b).forEach(function (i) { if (!n[i]) { out.push({ i: Number(i), cue: null }); } });
		out.sort(function (x, y) { return x.i - y.i; });
		return out;
	};

	/* A cue list with changes from cueDiff applied, in word order. */
	P.cueApply = function (list, changes) {
		var m = {};
		P.cueClean(list || []).forEach(function (c) { m[c.i] = c; });
		(Array.isArray(changes) ? changes : []).forEach(function (x) {
			if (!x || typeof x.i !== 'number') { return; }
			if (x.cue) {
				var c = P.cueClean([Object.assign({}, x.cue, { i: x.i })])[0];
				if (c) { m[x.i] = c; } else { delete m[x.i]; }
			} else { delete m[x.i]; }
		});
		return Object.keys(m).map(function (i) { return m[i]; }).sort(function (x, y) { return x.i - y.i; });
	};

	/*
	 * ---------------------------------------------------------------------
	 * Taught words that stay forgotten (1.15.0)
	 * ---------------------------------------------------------------------
	 * A set of taught words: pairs { script: { heard: 1 } }, ignore { word: 1 },
	 * labels { entry: text }, and since 1.15.0 at { entry: time taught } and
	 * forgot { entry: time forgotten }, in milliseconds. An entry is
	 * "script|heard" or "+word". A word stays only if it was taught after it
	 * was last forgotten (a word with no time counts as taught long ago), so
	 * a copy kept in another browser can no longer bring a forgotten word back.
	 * The site's rrt_merge_learned() follows the same rule.
	 */
	P.learnedEmpty = function (L) {
		L = (L ? typeof L === 'object' : false) ? L : {};
		['pairs', 'ignore', 'labels', 'at', 'forgot'].forEach(function (k) { if (!L[k] || typeof L[k] !== 'object' || Array.isArray(L[k])) { L[k] = {}; } });
		return L;
	};

	/* Every entry in a set, as entry: true. */
	function learnedEntries(L) {
		var out = {};
		Object.keys(L.pairs).forEach(function (s) {
			if (!L.pairs[s] || typeof L.pairs[s] !== 'object') { return; }
			Object.keys(L.pairs[s]).forEach(function (h) { if (L.pairs[s][h]) { out[s + '|' + h] = true; } });
		});
		Object.keys(L.ignore).forEach(function (w) { if (L.ignore[w]) { out['+' + w] = true; } });
		Object.keys(L.labels).forEach(function (e) { out[e] = true; });
		return out;
	}

	/* Takes one entry out of a set (its pair or ignored word, and its label). */
	P.learnedDrop = function (L, entry) {
		if (entry.charAt(0) === '+') {
			delete L.ignore[entry.slice(1)];
		} else {
			var cut = entry.indexOf('|');
			var s = entry.slice(0, cut);
			if (cut > 0 ? !!L.pairs[s] : false) {
				delete L.pairs[s][entry.slice(cut + 1)];
				if (!Object.keys(L.pairs[s]).length) { delete L.pairs[s]; }
			}
		}
		delete L.labels[entry];
		delete L.at[entry];
		return L;
	};

	/* Marks an entry forgotten now and takes it out. */
	P.learnedForget = function (L, entry, now) {
		L = P.learnedEmpty(L);
		L.forgot[entry] = now || Date.now();
		return P.learnedDrop(L, entry);
	};

	/* Both sets together, each entry kept only if taught after it was last forgotten. Neither set is changed. */
	P.mergeLearned = function (a, b) {
		a = P.learnedEmpty(JSON.parse(JSON.stringify(a || {})));
		b = P.learnedEmpty(JSON.parse(JSON.stringify(b || {})));
		var out = P.learnedEmpty({});
		[a, b].forEach(function (src) {
			Object.keys(src.pairs).forEach(function (s) {
				if (!src.pairs[s] || typeof src.pairs[s] !== 'object') { return; }
				Object.keys(src.pairs[s]).forEach(function (h) {
					if (!src.pairs[s][h]) { return; }
					out.pairs[s] = out.pairs[s] || {};
					out.pairs[s][h] = 1;
				});
			});
			Object.keys(src.ignore).forEach(function (w) { if (src.ignore[w]) { out.ignore[w] = 1; } });
			Object.keys(src.labels).forEach(function (e) { if (!out.labels[e]) { out.labels[e] = src.labels[e]; } });
			Object.keys(src.at).forEach(function (e) { out.at[e] = Math.max(out.at[e] || 0, Number(src.at[e]) || 0); });
			Object.keys(src.forgot).forEach(function (e) { out.forgot[e] = Math.max(out.forgot[e] || 0, Number(src.forgot[e]) || 0); });
		});
		Object.keys(learnedEntries(out)).forEach(function (e) {
			if (out.forgot[e] ? out.forgot[e] >= (out.at[e] || 0) : false) { P.learnedDrop(out, e); }
		});
		return out;
	};

	/* True when two sets hold the same words, times and forgotten words. */
	P.learnedSame = function (a, b) {
		function sig(L) {
			L = P.learnedEmpty(L);
			var e = Object.keys(learnedEntries(L)).sort();
			var t = Object.keys(L.at).sort().map(function (k) { return k + '=' + L.at[k]; });
			var f = Object.keys(L.forgot).sort().map(function (k) { return k + '=' + L.forgot[k]; });
			return JSON.stringify([e, t, f]);
		}
		return sig(JSON.parse(JSON.stringify(a || {}))) === sig(JSON.parse(JSON.stringify(b || {})));
	};

	/*
	 * ---------------------------------------------------------------------
	 * Kind of reading (1.11.0)
	 * ---------------------------------------------------------------------
	 * Each kind has a pace range in words a minute (Settings). A post with a
	 * kind starts the prompter in the middle of its range, and the review
	 * judges the pace of a take against it.
	 */

	/* The ranges as first set up, in case the site sends none. */
	P.KIND_RANGES = { prose: [140, 160], story: [130, 150], verse: [110, 135], ritual: [95, 120] };
	/* The kinds where a trailing line is expected. */
	P.KIND_TRAILS = { verse: true, ritual: true };

	/* A kind's range as { lo, hi }, or null for no kind (or an unknown one). */
	P.kindRange = function (kind, ranges) {
		var r = (ranges ? ranges[kind] : null) || P.KIND_RANGES[kind];
		if (!kind || !r || r.length < 2) { return null; }
		var lo = Number(r[0]);
		var hi = Number(r[1]);
		if (!(lo > 0) || !(hi > lo)) { return null; }
		return { lo: lo, hi: hi };
	};

	/* Where the prompter starts for a range: its middle, on the slider's 5 wpm steps, inside 80 to 220. */
	P.kindPace = function (range) {
		if (!range) { return 0; }
		return Math.max(80, Math.min(220, Math.round((range.lo + range.hi) / 2 / 5) * 5));
	};

	/* A pace against a range: 'under', 'inside' or 'over', and by how many words a minute. */
	P.paceAgainst = function (wpm, range) {
		if (!range) { return null; }
		if (wpm < range.lo) { return { where: 'under', by: range.lo - wpm }; }
		if (wpm > range.hi) { return { where: 'over', by: wpm - range.hi }; }
		return { where: 'inside', by: 0 };
	};

	/*
	 * The review's options for a kind: the range, the edges of its Rushed and
	 * Slow flags (a quarter beyond the range, which for prose are the 200 and
	 * 105 used before 1.11.0), and how deep a fade must be to be flagged.
	 */
	P.kindReview = function (kind, ranges) {
		var r = P.kindRange(kind, ranges);
		return {
			kind: r ? kind : '',
			range: r,
			rushed: r ? Math.round(r.hi * 1.25) : 200,
			slow: r ? Math.round(r.lo * 0.75) : 105,
			fadeDb: (r && P.KIND_TRAILS[kind]) ? 14 : 8
		};
	};

	/* Seconds a word's cue adds after it. */
	P.cueSecs = function (c) {
		return c ? (P.CUE_SECS[c.a] || 0) : 0;
	};

	/*
	 * ---------------------------------------------------------------------
	 * Warm-up (0.15.0)
	 * ---------------------------------------------------------------------
	 */

	/* Tongue twisters: traditional ones, and a few for a witch's mouth. */
	P.TWISTERS = [
		__( 'Red leather, yellow leather.', 'foxfire-studio' ),
		__( 'Unique New York, you know you need unique New York.', 'foxfire-studio' ),
		__( 'Rubber baby buggy bumpers.', 'foxfire-studio' ),
		__( 'Which witch is which?', 'foxfire-studio' ),
		__( 'The lips, the teeth, the tip of the tongue.', 'foxfire-studio' ),
		__( 'A proper copper coffee pot.', 'foxfire-studio' ),
		__( 'Six slick slim sycamore saplings.', 'foxfire-studio' ),
		__( 'Freshly fried flying fish.', 'foxfire-studio' ),
		__( 'Eleven benevolent elephants.', 'foxfire-studio' ),
		__( 'Thin sticks, thick bricks.', 'foxfire-studio' ),
		__( 'Truly rural, really rural.', 'foxfire-studio' ),
		__( 'She sells seashells by the seashore.', 'foxfire-studio' ),
		__( 'Toy boat, toy boat, toy boat.', 'foxfire-studio' ),
		__( 'Irish wristwatch, Swiss wristwatch.', 'foxfire-studio' ),
		__( 'Seven silver sigils sealed in salt.', 'foxfire-studio' ),
		__( 'Black cats cross crooked creeks.', 'foxfire-studio' ),
		__( 'Moonlit mist moves mildly over the moss.', 'foxfire-studio' ),
		__( 'Brave bright brooms brush bare brick.', 'foxfire-studio' ),
		__( 'Twelve twisted twigs tied with twine.', 'foxfire-studio' ),
		__( 'Sage, salt and sweet smoke circle the circle.', 'foxfire-studio' )
	];

	/* The passage for the pacing step. */
	P.PACE_TEXT = __( 'Light the candle and let the room go still. Breathe once, slowly, and feel your feet on the floor. Every word has its own place in the line, and there is time for each of them. Read this as if someone you love is listening, and let the last word land.', 'foxfire-studio' );

	/* The paces for the pacing step: slow, quick, then the reader's own. */
	P.PACES = [110, 170];

	/*
	 * n different twisters, picked at random. rnd is a function returning
	 * 0 to 1 (Math.random by default), so tests can fix the order.
	 */
	P.pickTwisters = function (n, rnd) {
		rnd = rnd || Math.random;
		var pool = P.TWISTERS.slice();
		var out = [];
		while (out.length < n && pool.length) {
			out.push(pool.splice(Math.floor(rnd() * pool.length), 1)[0]);
		}
		return out;
	};

	/* Rough syllable count, the same idea the teleprompter uses for timing. */
	function syl(word) {
		var w = String(word).toLowerCase().replace(/[^a-z]/g, '');
		if (!w) { return 1; }
		if (w.length <= 3) { return 1; }
		w = w.replace(/(?:[^laeiouy]es|[^laeiouy]ed|[^laeiouy]e)$/, '').replace(/^y/, '');
		var g = w.match(/[aeiouy]+/g);
		return Math.max(1, g ? g.length : 1);
	}
	P.syl = syl;

	/*
	 * When each word of a passage starts and ends at a pace, in seconds. Longer
	 * words get more time, and punctuation a short rest, as in the teleprompter.
	 * The words themselves fill exactly words / wpm minutes.
	 */
	P.paceTimes = function (words, wpm) {
		var weights = words.map(function (w) { return Math.max(0.6, Math.min(2.8, 0.4 + syl(w) * 0.4)); });
		var sum = weights.reduce(function (a, b) { return a + b; }, 0) || 1;
		var per = (words.length * 60 / wpm) / sum;
		var t = 0;
		return words.map(function (w, i) {
			var s = t;
			t += weights[i] * per;
			var e = t;
			if (/[.!?]["'”’)]*$/.test(w)) { t += 0.8 * 60 / wpm; }
			else if (/[,;:]["'”’)]*$/.test(w)) { t += 0.35 * 60 / wpm; }
			return { s: s, e: e };
		});
	};

	/*
	 * The warm-up, in a panel over the page: one step at a time, with Next and
	 * Back, and a live level bar so the reader can see the microphone hears
	 * them. Nothing is recorded or saved.
	 *
	 * @param opts wpm (the reader's own pace), onClose.
	 */
	P.warmup = function (opts) {
		opts = opts || {};
		var old = document.querySelector('.tp-warm');
		if (old) { old.parentNode.removeChild(old); }
		var own = Math.max(80, Math.min(220, opts.wpm || 140));
		var twisters = P.pickTwisters(4);
		var steps = [
			{ kind: 'breath', head: __( 'Breathe', 'foxfire-studio' ), say: __( 'Follow the circle: breathe in through your nose as it grows, then let the air out slowly on a long, steady “sss” as it shrinks. Three rounds.', 'foxfire-studio' ) },
			{ kind: 'text', head: __( 'Wake up the voice', 'foxfire-studio' ), say: __( 'Hum on “mmm”, sliding from low to high and back down. Then blow a lip trill, a loose “brrr”, up and down your range. Stop if anything feels strained.', 'foxfire-studio' ), big: __( 'mmm . . . brrr', 'foxfire-studio' ) }
		];
		twisters.forEach(function (t, n) {
			steps.push({ kind: 'text', head: sprintf( /* translators: 1: n + 1, 2: twisters.length. */ __( 'Tongue twister %1$s of %2$s', 'foxfire-studio' ), n + 1, twisters.length ), say: __( 'Say it three times: slowly, with every sound clear, then a little quicker, then as quick as you can while it stays clean.', 'foxfire-studio' ), big: t });
		});
		steps.push({ kind: 'pace', head: __( 'Pacing', 'foxfire-studio' ), say: sprintf( /* translators: %s: own. */ __( 'Read along as the words light up. It runs slow, then quick, then at your own pace from the control bar (%s wpm), so you can feel the difference.', 'foxfire-studio' ), own ) });
		steps.push({ kind: 'text', head: __( 'Ready', 'foxfire-studio' ), say: __( 'That is the warm-up done. Close this and press Begin when you are ready. Take a sip of water first.', 'foxfire-studio' ) });

		var box = el('div', 'tp-result tp-warm is-on');
		box.setAttribute('role', 'dialog');
		box.setAttribute('aria-label', __( 'Warm-up', 'foxfire-studio' ));
		var top = el('div', 'tp-warm-top');
		var head = el('h2', '');
		var count = el('span', 'tp-warm-count');
		top.appendChild(head);
		top.appendChild(count);
		box.appendChild(top);
		var say = el('p', 'tp-warm-say');
		say.setAttribute('aria-live', 'polite');
		box.appendChild(say);
		var stageEl = el('div', 'tp-warm-stage');
		box.appendChild(stageEl);
		/* A live level bar, so the reader can see the microphone hears them. Nothing is recorded or kept. */
		var lvRow = el('div', 'tp-warm-level');
		var lvLabel = el('span', 'tp-warm-level-label', __( 'Your voice', 'foxfire-studio' ));
		var lvMeter = el('div', 'tp-mic-meter');
		var lvBar = el('i');
		lvMeter.appendChild(lvBar);
		lvRow.appendChild(lvLabel);
		lvRow.appendChild(lvMeter);
		box.appendChild(lvRow);
		var row = el('div', 'tp-row');
		var back = el('button', 'tp-btn', __( 'Back', 'foxfire-studio' ));
		back.type = 'button';
		var next = el('button', 'tp-btn primary', __( 'Next', 'foxfire-studio' ));
		next.type = 'button';
		var again = el('button', 'tp-btn', __( 'Again', 'foxfire-studio' ));
		again.type = 'button';
		var close = el('button', 'tp-btn', __( 'Close', 'foxfire-studio' ));
		close.type = 'button';
		close.setAttribute('data-dlg-close', '');
		row.appendChild(back);
		row.appendChild(next);
		row.appendChild(again);
		row.appendChild(close);
		box.appendChild(row);
		document.body.appendChild(box);

		var at = 0;
		var raf = 0;
		var timer = 0;
		function stopAnim() {
			window.cancelAnimationFrame(raf);
			window.clearTimeout(timer);
			raf = 0;
			timer = 0;
		}

		/* The breathing circle: in for 4 s, out for 6 s, three times. */
		function runBreath() {
			var ring = el('div', 'tp-warm-ring');
			var word = el('span', 'tp-warm-ring-word', 'in');
			ring.appendChild(word);
			stageEl.appendChild(ring);
			var IN = 4;
			var OUT = 6;
			var ROUNDS = 3;
			var t0 = window.performance.now();
			function tick(now) {
				var t = (now - t0) / 1000;
				var round = Math.floor(t / (IN + OUT));
				if (round >= ROUNDS) {
					ring.style.transform = 'scale(0.55)';
					word.textContent = 'done';
					raf = 0;
					return;
				}
				var r = t - round * (IN + OUT);
				var f;
				if (r < IN) { f = r / IN; word.textContent = 'in'; }
				else { f = 1 - (r - IN) / OUT; word.textContent = 'out'; }
				/* Ease so the circle slows at each end, like a breath. */
				f = 0.5 - Math.cos(f * Math.PI) / 2;
				ring.style.transform = 'scale(' + (0.55 + 0.45 * f).toFixed(3) + ')';
				raf = window.requestAnimationFrame(tick);
			}
			raf = window.requestAnimationFrame(tick);
		}

		/* The pacing passage: lit word by word at each pace in turn. */
		function runPace() {
			var words = P.PACE_TEXT.split(/\s+/);
			var label = el('p', 'tp-warm-pace');
			stageEl.appendChild(label);
			var para = el('p', 'tp-warm-big tp-warm-passage');
			var spans = words.map(function (w, i) {
				if (i) { para.appendChild(document.createTextNode(' ')); }
				var s = el('span', 'tp-warm-w', w);
				para.appendChild(s);
				return s;
			});
			stageEl.appendChild(para);
			var paces = P.PACES.concat([own]);
			var names = [__( 'Slow', 'foxfire-studio' ), __( 'Quick', 'foxfire-studio' ), __( 'Your pace', 'foxfire-studio' )];
			var n = 0;
			function run() {
				if (n >= paces.length) {
					label.textContent = __( 'All three done. Again runs them once more.', 'foxfire-studio' );
					return;
				}
				var wpm = paces[n];
				label.textContent = sprintf( /* translators: 1: names[n], 2: wpm. */ __( '%1$s: %2$s words a minute', 'foxfire-studio' ), names[n], wpm );
				spans.forEach(function (s) { s.className = 'tp-warm-w'; });
				var times = P.paceTimes(words, wpm);
				var t0 = window.performance.now();
				function tick(now) {
					var t = (now - t0) / 1000;
					var done = true;
					for (var i = 0; i < spans.length; i++) {
						var on = t >= times[i].s;
						spans[i].classList.toggle('is-lit', on ? t >= times[i].e : false);
						spans[i].classList.toggle('is-now', on ? t < times[i].e : false);
						if (t < times[i].e) { done = false; }
					}
					if (done) {
						raf = 0;
						n++;
						timer = window.setTimeout(run, 1800);
						return;
					}
					raf = window.requestAnimationFrame(tick);
				}
				raf = window.requestAnimationFrame(tick);
			}
			timer = window.setTimeout(run, 1200);
		}

		function show(i) {
			stopAnim();
			at = Math.max(0, Math.min(steps.length - 1, i));
			var st = steps[at];
			head.textContent = st.head;
			count.textContent = sprintf( /* translators: 1: at + 1, 2: steps.length. */ __( '%1$s of %2$s', 'foxfire-studio' ), at + 1, steps.length );
			say.textContent = st.say;
			stageEl.innerHTML = '';
			if ('breath' === st.kind) { runBreath(); }
			else if ('pace' === st.kind) { runPace(); }
			else if (st.big) { stageEl.appendChild(el('p', 'tp-warm-big', st.big)); }
			back.disabled = at === 0;
			next.hidden = at === steps.length - 1;
			again.hidden = !('breath' === st.kind || 'pace' === st.kind);
			(next.hidden ? close : next).focus();
		}
		var lv = { stream: null, ctx: null, raf: 0, closed: false };
		function levelStop() {
			window.cancelAnimationFrame(lv.raf);
			if (lv.stream) { lv.stream.getTracks().forEach(function (t) { t.stop(); }); }
			if (lv.ctx) { try { lv.ctx.close(); } catch (e) {} }
			lv.stream = null;
			lv.ctx = null;
		}
		function levelStart() {
			if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
				lvLabel.textContent = __( 'No microphone in this browser; the warm-up works without one.', 'foxfire-studio' );
				lvMeter.hidden = true;
				return;
			}
			navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: false, noiseSuppression: false, autoGainControl: false, channelCount: 1 } }).then(function (s) {
				if (lv.closed) { s.getTracks().forEach(function (t) { t.stop(); }); return; }
				lv.stream = s;
				var AC = window.AudioContext || window.webkitAudioContext;
				lv.ctx = new AC();
				var an = lv.ctx.createAnalyser();
				an.fftSize = 2048;
				lv.ctx.createMediaStreamSource(s).connect(an);
				var buf = new Float32Array(an.fftSize);
				var shown = 0;
				function tick() {
					an.getFloatTimeDomainData(buf);
					var db = rmsDb(buf, 0, buf.length);
					var f = Math.max(0, Math.min(1, (db + 70) / 70));
					/* Rise at once, fall gently, so the bar is easy to follow. */
					shown = f > shown ? f : shown * 0.9 + f * 0.1;
					lvBar.style.width = (shown * 100).toFixed(1) + '%';
					/* -60 since 1.9.0, so a phone's quieter voice lights it too. */
					lvRow.classList.toggle('is-heard', db > -60);
					lv.raf = window.requestAnimationFrame(tick);
				}
				lv.raf = window.requestAnimationFrame(tick);
			}, function () {
				lvLabel.textContent = __( 'The microphone is not available, so there is no level to show. The warm-up works without it.', 'foxfire-studio' );
				lvMeter.hidden = true;
			});
		}
		function finish() {
			lv.closed = true;
			levelStop();
			stopAnim();
			if (box.parentNode) { box.parentNode.removeChild(box); }
			document.removeEventListener('keydown', onKey, true);
			if (opts.onClose) { opts.onClose(); }
		}
		function onKey(e) {
			if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); finish(); }
			else if (e.key === 'ArrowRight' ? !next.hidden : false) { e.preventDefault(); e.stopPropagation(); show(at + 1); }
			else if (e.key === 'ArrowLeft' ? at > 0 : false) { e.preventDefault(); e.stopPropagation(); show(at - 1); }
		}
		document.addEventListener('keydown', onKey, true);
		back.addEventListener('click', function () { show(at - 1); });
		next.addEventListener('click', function () { show(at + 1); });
		again.addEventListener('click', function () { show(at); });
		close.addEventListener('click', finish);
		show(0);
		levelStart();
		return { close: finish };
	};

	root.RRTPractice = P;
	if (typeof module === 'object' && module.exports) { module.exports = P; }
})(typeof window !== 'undefined' ? window : globalThis);
