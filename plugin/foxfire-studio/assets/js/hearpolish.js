/*
 * Foxfire Studio: voice polish in the studio (1.13.0). The polish each take
 * gets (the Settings choice, or this take's one-off), the setup's EQ
 * learned from its takes and kept on the site, clicks between words lowered
 * once the Whisper review knows where the words are, and the review
 * studio's Hear the polish panel: one sentence before and after, looping.
 * Shares window.RRTStudio with the studio's other files (made in
 * studio-core.js; docs/ARCHITECTURE.md maps them). The sound itself is
 * shaped in sound.js; the parts with no page are in polish.js.
 */
(function () {
	/* Translations: WordPress's wp.i18n when the page has it, otherwise the English text as written. */
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
	var studio = window.RRTStudio;
	var RRT = studio.RRT;
	var PL = window.RRTPolish;
	var SND = window.RRTSound;
	if (!PL || !SND || !studio.track) { return; }
	var SOUND = studio.SOUND || {};
	var EQB = RRT.eq || null;
	var downloadA = studio.downloadA;
	var resultAudio = studio.resultAudio;
	var resultInfo = studio.resultInfo;
	var RATE = 44100;

	/* This take's polish: the one-off from Hear the polish ({} for the Settings choice), and the gaps between its words once reviewed. */
	var oneOff = {};
	var gaps = null;
	var gapsFor = null;

	/* A setup's suggested EQ ([{ f, g }]), or null until it has three takes. */
	function eqOf(key) {
		var s = EQB && EQB.setups && key ? EQB.setups[key] : null;
		if (!s || !s.takes) { return null; }
		return SND.eqSuggest(s.takes.map(function (t) { return t.bands; }));
	}
	/* A route on the site, with any query joined right for sites without pretty links. */
	function eqUrl(path) {
		var q = path.indexOf('?');
		return EQB.rest + (q < 0 ? path : path.slice(0, q) + (EQB.rest.indexOf('?') < 0 ? '?' : '&') + path.slice(q + 1));
	}
	function takeKey() { return studio.takeSetup ? studio.takeSetup.key : ''; }
	/*
	 * The stronger cleanup (1.16.0): the engine that cleaned this take
	 * ('standard' or 'stronger', or null if noise reduction did not run), and
	 * the one a polish asks for (its one-off, else Settings).
	 */
	var DN = RRT.denoise || null;
	function takeEngine(m) {
		var dn = m && m.room ? m.room.denoise : null;
		return dn ? (dn.engine === 'stronger' ? 'stronger' : 'standard') : null;
	}
	function wantEngine(o) {
		return o && o.cleanup ? o.cleanup : (studio.cleanEngine ? studio.cleanEngine() : 'standard');
	}
	function canSwitchCleanup(m) {
		return !!(DN && DN.stronger && studio.cleanWithModel && m && m.heard && takeEngine(m));
	}
	/* The polish for this voice: draft (Hear the polish's choices, not yet used) or this take's one-off. */
	function polishFor(seg, draft) {
		var o = draft || oneOff;
		var key = o.eqSetup !== undefined ? o.eqSetup : takeKey();
		var g = gaps;
		if (draft ? draft.gaps : false) { g = draft.gaps; } else if (seg !== gapsFor) { g = null; }
		return PL.options(SOUND, o, eqOf(key), g, studio.kind);
	}
	studio.polishFor = function (seg) { return polishFor(seg, null); };
	studio.polishNewTake = function () {
		oneOff = {};
		gaps = null;
		gapsFor = null;
		panelClose();
	};

	/*
	 * The setup's EQ is learned from its first takes (five at most): the
	 * voice's six band levels, sent to the site so a phone take reviewed on
	 * the computer counts for the phone. Opening drills, takes under 30 s
	 * and files whose setup is not known are left out.
	 */
	studio.polishLearn = function (res) {
		var set = studio.takeSetup;
		var bands = res && res.sound && res.sound.report ? res.sound.report.bands : null;
		if (!EQB || !set || !set.key || !bands || studio.takeDrill || (res.seconds || 0) < 30) { return; }
		var have = EQB.setups && EQB.setups[set.key] ? EQB.setups[set.key].takes.length : 0;
		if (have >= (EQB.takes || 5)) { return; }
		var id = String(studio.sentOpenId || studio.currentTake || ('t' + Date.now().toString(36))).toLowerCase().replace(/[^a-z0-9_\-]/g, '').slice(0, 40);
		var f = new FormData();
		f.append('setup', set.key);
		f.append('label', set.label || set.key);
		f.append('bands', JSON.stringify(bands));
		f.append('take', id);
		fetch(eqUrl('eq'), { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': EQB.nonce }, body: f }).then(function (r) {
			return r.ok ? r.json() : null;
		}).then(function (j) {
			if (j && j.setups) { EQB.setups = j.setups; eqLine(); }
		})['catch'](function () {});
	};

	/* The take's own MP3 is still the one behind the download button (not an edited version). */
	function takeMp3() {
		var m = studio.lastMp3;
		if (!m || !m.samples || downloadA.hidden) { return null; }
		if (/edited/i.test(downloadA.textContent)) { return null; }
		return m;
	}

	/*
	 * Makes the take's MP3 again with its polish now (clicks found, or a
	 * one-off chosen), with its chapters, and puts it behind the download
	 * button, the review's player, clips and Share for feedback. Resolves to
	 * the polish line for the read-out.
	 */
	var remaking = null;
	function remake(onStatus) {
		var m = takeMp3();
		if (!m) { return Promise.reject(new Error(__( 'this take has an edited version: make the edited MP3 again to polish it', 'foxfire-studio' ))); }
		var say = onStatus || function () {};
		var names = { sound: __( 'Shaping the sound...', 'foxfire-studio' ), check: __( 'Checking the finished file...', 'foxfire-studio' ) };
		/* A different cleanup engine (1.16.0) cleans the untouched take again first. */
		var want = wantEngine(oneOff);
		/* A take where the stronger cleanup already fell back is not tried again unless this take's polish asks for it. */
		var retried = !oneOff.cleanup && (m && m.room ? !!m.room.strongerFailed : false);
		var cleaning = canSwitchCleanup(m) && want !== takeEngine(m) && !retried
			? studio.cleanWithModel(m.heard, RATE, function (p) {
				say(sprintf( /* translators: %s: percent. */ __( 'Lowering background noise... %s%%', 'foxfire-studio' ), Math.round(p * 100) ));
			}, want).then(function (c) {
				if (!c || !c.data) { throw new Error(__( 'the cleanup could not run', 'foxfire-studio' )); }
				if (studio.lastMp3 !== m) { throw new Error(__( 'the take changed', 'foxfire-studio' )); }
				m.samples = c.data;
				m.room = c.report;
			})
			: Promise.resolve();
		var job = cleaning.then(function () {
			return studio.masterMp3(m.samples, function (p, phase) {
				say(names[phase] || sprintf( /* translators: %s: percent. */ __( 'Making the MP3 again... %s%%', 'foxfire-studio' ), Math.round(p * 100) ));
			});
		}).then(function (mm) {
			var R = studio.rv ? studio.rv.R : null;
			var ch = R && studio.chaptersFrom ? studio.chaptersFrom(R, m.seconds) : [];
			return studio.tagMp3(mm.blob, m.seconds, ch.length ? ch : null).then(function (tagged) {
				if (studio.lastMp3 !== m) { throw new Error(__( 'the take changed', 'foxfire-studio' )); }
				m.body = mm.blob;
				m.blob = tagged;
				m.sound = mm.sound;
				var url = URL.createObjectURL(tagged);
				/* The review's player is the take's (an edited version plays in its own), so clips and Share for feedback get this one. */
				var rv = studio.rv;
				downloadA.href = url;
				resultAudio.src = url;
				if (rv && rv.audio) {
					var at = rv.audio.currentTime;
					rv.audio.pause();
					rv.audio.src = url;
					var back = function () { try { rv.audio.currentTime = at; } catch (e) {} rv.audio.removeEventListener('loadedmetadata', back); };
					rv.audio.addEventListener('loadedmetadata', back);
				}
				if (studio.musicSetVoice) { studio.musicSetVoice({ samples: m.samples, seconds: m.seconds, chapters: ch, cuts: null }); }
				studio.unsaved = true;
				var line = studio.describePolish ? studio.describePolish(mm.sound ? mm.sound.report : null) : '';
				var rd = m.room ? m.room.denoise : null;
				if (rd ? isFinite(rd.dropDb) : false) {
					line += ' ' + sprintf( /* translators: 1: Standard or Stronger, 2: decibels. */ __( 'Cleanup: %1$s, background lowered by %2$s dB.', 'foxfire-studio' ), takeEngine(m) === 'stronger' ? __( 'Stronger', 'foxfire-studio' ) : __( 'Standard', 'foxfire-studio' ), Math.round(rd.dropDb) );
				}
				if (m.room ? m.room.strongerFailed : false) {
					line += ' ' + sprintf( /* translators: %s: why. */ __( 'The stronger cleanup could not run (%s), so the standard one was used.', 'foxfire-studio' ), m.room.strongerFailed );
				}
				return line.trim();
			});
		});
		remaking = job;
		job.then(function () { remaking = null; }, function () { remaking = null; });
		return job;
	}

	/* After the Whisper review: with click lowering on, the gaps between words are known now, so the MP3 is made again. */
	studio.polishAfterReview = function (chunks) {
		var m = takeMp3();
		if (!m || !studio.soundPreset || !studio.soundPreset()) { return Promise.resolve(''); }
		var g = PL.gaps(chunks);
		gaps = g;
		gapsFor = m.samples;
		if (!PL.needsWords(SOUND, oneOff) || !g.length) { return Promise.resolve(''); }
		var info = resultInfo.textContent;
		return remake(function (t) { resultInfo.textContent = t; }).then(function (line) {
			resultInfo.textContent = info;
			var r = m.sound ? m.sound.report : null;
			return r && r.clicks !== undefined ? (r.clicks ? sprintf( /* translators: %s: how many. */ _n( 'Lowered %s click between words; the MP3 behind the download button has it.', 'Lowered %s clicks between words; the MP3 behind the download button has them.', r.clicks, 'foxfire-studio' ), r.clicks ) : __( 'No clicks between words were found.', 'foxfire-studio' )) : line;
		}, function (err) {
			resultInfo.textContent = info;
			return sprintf( /* translators: %s: error. */ __( 'Clicks between words could not be lowered (%s); the MP3 behind the download button is as before.', 'foxfire-studio' ), err && err.message ? err.message : __( 'unknown error', 'foxfire-studio' ) );
		});
	};

	/* ---- Hear the polish ---- */
	var btn = document.getElementById('tpRvPolish');
	var box = document.getElementById('tpPolishBox');
	if (!btn || !box) { return; }
	var sentIn = document.getElementById('tpPolishSent');
	var playBtn = document.getElementById('tpPolishPlay');
	var beforeBtn = document.getElementById('tpPolishBefore');
	var afterBtn = document.getElementById('tpPolishAfter');
	var stepsEl = document.getElementById('tpPolishSteps');
	var eqEl = document.getElementById('tpPolishEq');
	var useBtn = document.getElementById('tpPolishUse');
	var resetBtn = document.getElementById('tpPolishReset');
	var closeBtn = document.getElementById('tpPolishClose');
	var statusEl = document.getElementById('tpPolishStatus');
	var LV = { off: __( 'Off', 'foxfire-studio' ), gentle: __( 'Gentle', 'foxfire-studio' ), standard: __( 'Standard', 'foxfire-studio' ), strong: __( 'Strong', 'foxfire-studio' ) };
	var STEP_NAMES = { plosives: __( 'Pops', 'foxfire-studio' ), deess: __( 'S sounds', 'foxfire-studio' ), breaths: __( 'Breaths', 'foxfire-studio' ), compress: __( 'Compression', 'foxfire-studio' ) };
	var ctrl = {};
	var draft = {};
	var side = 'after';
	var heard = null; /* { key, before, after } for the sentence and choices last worked out. */
	var cleanedWin = null; /* The sentence's window cleaned by the other engine (1.16.0), so polish changes do not clean it again. */
	var player = null;

	function el(tag, cls, text) {
		var n = document.createElement(tag);
		if (cls) { n.className = cls; }
		if (text !== undefined) { n.textContent = text; }
		return n;
	}
	function say(t) { statusEl.textContent = t || ''; }
	function settingsLevel(k) { return SND.strengthOf(SOUND[k]); }

	/* The controls: a strength for each step, the EQ, clicks and slow leveling. */
	function build() {
		stepsEl.innerHTML = '';
		/* Cleanup (1.16.0): Standard or Stronger for this take. */
		var clLab = el('label', 'tp-polish-step tp-polish-cleanup');
		clLab.appendChild(el('span', '', __( 'Cleanup', 'foxfire-studio' )));
		ctrl.cleanup = el('select');
		[['', ''], ['standard', __( 'Standard', 'foxfire-studio' )], ['stronger', __( 'Stronger', 'foxfire-studio' )]].forEach(function (c) {
			var o = el('option', '', c[1]);
			o.value = c[0];
			ctrl.cleanup.appendChild(o);
		});
		ctrl.cleanup.addEventListener('change', changed);
		clLab.appendChild(ctrl.cleanup);
		clLab.title = __( 'Which model lowers the background. Stronger also takes out sounds with a shape, such as a bell, a chair or mouth sounds. With a different choice here, Before plays this take\'s cleanup and After the other, both with the polish chosen below.', 'foxfire-studio' );
		stepsEl.appendChild(clLab);
		ctrl.cleanupLab = clLab;
		PL.STEPS.forEach(function (k) {
			var lab = el('label', 'tp-polish-step');
			lab.appendChild(el('span', '', STEP_NAMES[k]));
			var sel = el('select');
			sel.setAttribute('data-step', k);
			var o0 = el('option', '', sprintf( /* translators: %s: a strength, such as Standard. */ __( 'Settings (%s)', 'foxfire-studio' ), LV[settingsLevel(k)] ));
			o0.value = '';
			sel.appendChild(o0);
			PL.LEVELS.forEach(function (lv) {
				var o = el('option', '', LV[lv]);
				o.value = lv;
				sel.appendChild(o);
			});
			sel.addEventListener('change', changed);
			lab.appendChild(sel);
			stepsEl.appendChild(lab);
			ctrl[k] = sel;
		});
		var eqLab = el('label', 'tp-polish-step');
		eqLab.appendChild(el('span', '', __( 'EQ', 'foxfire-studio' )));
		ctrl.eq = el('select');
		ctrl.eq.addEventListener('change', changed);
		eqLab.appendChild(ctrl.eq);
		stepsEl.appendChild(eqLab);
		var ck = el('label', 'tp-polish-step');
		ctrl.clicks = el('input');
		ctrl.clicks.type = 'checkbox';
		ctrl.clicks.addEventListener('change', changed);
		ck.appendChild(ctrl.clicks);
		ck.appendChild(el('span', '', __( 'Lower clicks between words', 'foxfire-studio' )));
		stepsEl.appendChild(ck);
		var sl = el('label', 'tp-polish-step');
		ctrl.slow = el('input');
		ctrl.slow.type = 'checkbox';
		ctrl.slow.addEventListener('change', changed);
		sl.appendChild(ctrl.slow);
		sl.appendChild(el('span', '', __( 'Slow leveling', 'foxfire-studio' )));
		sl.title = __( 'Slow leveling works over minutes, so one sentence cannot show it. It applies to the whole take when you press Use for this take.', 'foxfire-studio' );
		stepsEl.appendChild(sl);
	}
	/* The EQ menu: none, this take's setup, and any other setup with an EQ to try. */
	function eqMenu(pick) {
		var sel = ctrl.eq;
		sel.innerHTML = '';
		var none = el('option', '', __( 'None', 'foxfire-studio' ));
		none.value = '';
		sel.appendChild(none);
		var mine = takeKey();
		var setups = EQB && EQB.setups ? EQB.setups : {};
		Object.keys(setups).forEach(function (k) {
			if (!eqOf(k)) { return; }
			var o = el('option', '', (k === mine ? __( 'This setup: ', 'foxfire-studio' ) : '') + (setups[k].label || k));
			o.value = k;
			sel.appendChild(o);
		});
		sel.value = pick && sel.querySelector('option[value="' + pick.replace(/"/g, '') + '"]') ? pick : '';
	}
	/* The line under the controls: what this take's setup's EQ is, or how many takes it still needs. */
	function eqLine() {
		if (!eqEl) { return; }
		eqEl.innerHTML = '';
		var set = studio.takeSetup;
		var s = set && EQB && EQB.setups ? EQB.setups[set.key] : null;
		var need = SND.EQ.minTakes;
		if (!EQB) { eqEl.textContent = ''; return; }
		if (!set) {
			eqEl.textContent = __( 'This take\'s setup is not known (it came from a file, or was recorded before 1.13.0). Any setup with an EQ can still be tried here.', 'foxfire-studio' );
			return;
		}
		var n = s ? s.takes.length : 0;
		var sug = eqOf(set.key);
		var text;
		if (sug) {
			var bands = sug.filter(function (b) { return b.g; }).map(function (b) { return (b.f >= 1000 ? (b.f / 1000) + ' kHz' : b.f + ' Hz') + ' ' + (b.g > 0 ? '+' : '') + b.g; });
			text = sprintf( /* translators: 1: device and microphone, 2: takes measured, 3: bands and gains. */ __( 'This setup (%1$s): EQ from %2$s takes, %3$s.', 'foxfire-studio' ), set.label, n, bands.length ? bands.join(', ') + ' dB' : __( 'flat', 'foxfire-studio' ) );
		} else {
			text = sprintf( /* translators: 1: device and microphone, 2: takes measured, 3: takes needed. */ __( 'This setup (%1$s): %2$s of %3$s takes measured. Its EQ is suggested once %3$s takes of 30 seconds or more are reviewed.', 'foxfire-studio' ), set.label, n, need );
		}
		eqEl.appendChild(document.createTextNode(text));
		if (n) {
			var forget = el('button', 'tp-btn', __( 'Forget this setup', 'foxfire-studio' ));
			forget.type = 'button';
			forget.title = __( 'Clear this setup\'s measurements, so its EQ is learned again from the next takes', 'foxfire-studio' );
			forget.addEventListener('click', function () {
				forget.disabled = true;
				fetch(eqUrl('eq?setup=' + encodeURIComponent(set.key)), { method: 'DELETE', credentials: 'same-origin', headers: { 'X-WP-Nonce': EQB.nonce } }).then(function (r) {
					return r.ok ? r.json() : null;
				}).then(function (j) {
					if (j && j.setups) { EQB.setups = j.setups; }
					eqMenu(ctrl.eq.value);
					eqLine();
					changed();
				})['catch'](function () { forget.disabled = false; });
			});
			eqEl.appendChild(forget);
		}
	}
	/* The controls set to this take's polish. */
	/* The EQ the Settings choice gives this take: its setup's, when EQ is on and the setup has one. */
	function settingsEq() { return SOUND.eq && eqOf(takeKey()) ? takeKey() : ''; }
	function fill() {
		var mm = studio.lastMp3;
		ctrl.cleanupLab.hidden = !canSwitchCleanup(mm);
		var setEng = studio.cleanEngine ? studio.cleanEngine() : 'standard';
		ctrl.cleanup.options[0].textContent = sprintf( /* translators: %s: Standard or Stronger. */ __( 'Settings (%s)', 'foxfire-studio' ), setEng === 'stronger' ? __( 'Stronger', 'foxfire-studio' ) : __( 'Standard', 'foxfire-studio' ) );
		ctrl.cleanup.value = oneOff.cleanup || '';
		PL.STEPS.forEach(function (k) { ctrl[k].value = oneOff[k] || ''; });
		var eqKey = oneOff.eq !== undefined ? (oneOff.eq ? (oneOff.eqSetup || takeKey()) : '') : settingsEq();
		eqMenu(eqKey);
		ctrl.clicks.checked = oneOff.clicks !== undefined ? !!oneOff.clicks : !!SOUND.clicks;
		ctrl.slow.checked = oneOff.slow !== undefined ? !!oneOff.slow : SOUND.slow !== false;
		readDraft();
		eqLine();
	}
	/* The choices in the panel as a one-off: only what differs from Settings. */
	function readDraft() {
		var d = {};
		PL.STEPS.forEach(function (k) { if (ctrl[k].value) { d[k] = ctrl[k].value; } });
		var eqKey = ctrl.eq.value;
		if (eqKey !== settingsEq()) { d.eq = !!eqKey; d.eqSetup = eqKey; }
		if (ctrl.clicks.checked !== !!SOUND.clicks) { d.clicks = ctrl.clicks.checked; }
		if (ctrl.slow.checked !== (SOUND.slow !== false)) { d.slow = ctrl.slow.checked; }
		var setEng = studio.cleanEngine ? studio.cleanEngine() : 'standard';
		if (!ctrl.cleanupLab.hidden && ctrl.cleanup.value && ctrl.cleanup.value !== setEng) { d.cleanup = ctrl.cleanup.value; }
		draft = d;
		return d;
	}
	function same(a, b) { return JSON.stringify(a) === JSON.stringify(b); }
	function changed() {
		readDraft();
		useBtn.disabled = same(draft, oneOff);
		resetBtn.disabled = same(oneOff, {});
		heard = null;
		if (player) { play(); }
	}

	/* The review's sentences, with their words and times. */
	function sentences() {
		var R = studio.rv ? studio.rv.R : null;
		if (!R || !R.sentences) { return []; }
		var W = studio.W || [];
		return R.sentences.filter(function (S) { return isFinite(S.s) && isFinite(S.e) && S.e - S.s >= 0.6; }).map(function (S) {
			var words = [];
			for (var k = S.first; k <= S.last; k++) { if (W[k] && W[k].el) { words.push(W[k].el.textContent); } }
			var text = words.join(' ').replace(/\s+/g, ' ').trim();
			return { s: S.s, e: Math.min(S.e, S.s + 20), text: text, id: S.id };
		});
	}
	function fillSentences() {
		var list = sentences();
		sentIn.innerHTML = '';
		var at = studio.rv && studio.rv.audio ? studio.rv.audio.currentTime : 0;
		var pick = 0;
		list.forEach(function (S, i) {
			var o = el('option', '', (studio.fmtClock ? studio.fmtClock(S.s) : Math.round(S.s) + ' s') + '  ' + (S.text.length > 70 ? S.text.slice(0, 68) + '…' : S.text));
			o.value = String(i);
			sentIn.appendChild(o);
			if (S.s <= at + 0.1) { pick = i; }
		});
		/* With the player at the start, the first sentence of three seconds or more. */
		if (at < 0.5) {
			for (var j = 0; j < list.length; j++) { if (list[j].e - list[j].s >= 3) { pick = j; break; } }
		}
		sentIn.value = String(pick);
		return list;
	}
	var sentList = [];
	sentIn.addEventListener('change', function () { heard = null; if (player) { play(); } });

	/* Works out the sentence before and after with the panel's choices. */
	function work() {
		var m = studio.lastMp3;
		var S = sentList[Number(sentIn.value) || 0];
		var PRE = studio.soundPreset ? studio.soundPreset() : null;
		if (!m || !m.samples || !S || !PRE) { return Promise.reject(new Error(__( 'nothing to play', 'foxfire-studio' ))); }
		var key = JSON.stringify([sentIn.value, draft]);
		if (heard && heard.key === key) { return Promise.resolve(heard); }
		/* The sentence with 8 s around it, so the steps hear what is around it. */
		var a = Math.max(0, S.s - 0.15), b = S.e + 0.25;
		var w0 = Math.max(0, Math.floor((a - 8) * RATE)), w1 = Math.min(m.samples.length, Math.ceil((b + 8) * RATE));
		var win = new Float32Array(m.samples.subarray(w0, w1));
		var d = {};
		Object.keys(draft).forEach(function (k) { d[k] = draft[k]; });
		var wantClicks = d.clicks !== undefined ? d.clicks : !!SOUND.clicks;
		var all = gaps || (studio.rv && studio.rv.last ? PL.gaps(studio.rv.last.chunks) : []);
		d.gaps = wantClicks ? all : null;
		var opts = studio.soundOpts(PRE, null);
		var pol = polishFor(null, d);
		Object.keys(pol).forEach(function (k) { opts[k] = pol[k]; });
		var off = w0 / RATE;
		opts.gaps = (opts.gaps || []).map(function (g) { return [g[0] - off, g[1] - off]; }).filter(function (g) { return g[1] > 0 && g[0] < win.length / RATE; });
		say(__( 'Working out the sentence...', 'foxfire-studio' ));
		/* Cleanup (1.16.0): with the other engine chosen, Before is this take's cleanup and After the other's, both polished. */
		var eng = wantEngine(d), cur = takeEngine(m), other = canSwitchCleanup(m) && eng !== cur;
		var preview = function (data) { return studio.soundTask({ cmd: 'preview', data: data, rate: RATE, opts: opts, a: a - off, b: b - off }); };
		if (other) {
			var ck = sentIn.value + '|' + eng;
			var cleaned = cleanedWin && cleanedWin.key === ck && cleanedWin.m === m ? Promise.resolve(cleanedWin.data) : studio.cleanWithModel(new Float32Array(m.heard.subarray(w0, w1)), RATE, function (p) {
				say(sprintf( /* translators: %s: percent. */ __( 'Cleaning the sentence with the other engine... %s%%', 'foxfire-studio' ), Math.round(p * 100) ));
			}, eng).then(function (c) {
				if (!c || !c.data) { throw new Error(__( 'the cleanup could not run', 'foxfire-studio' )); }
				if (c.report && c.report.strongerFailed) { throw new Error(c.report.strongerFailed); }
				cleanedWin = { key: ck, m: m, data: c.data };
				return c.data;
			});
			return cleaned.then(function (alt) {
				return Promise.all([preview(win), preview(new Float32Array(alt))]);
			}).then(function (rr) {
				var out2 = { key: key, before: rr[0].after, after: rr[1].after, report: rr[1].report };
				heard = out2;
				say(sprintf( /* translators: 1: this take's engine, 2: the other engine. */ __( 'Before: %1$s cleanup. After: %2$s cleanup. Both with the polish chosen here.', 'foxfire-studio' ), cur === 'stronger' ? __( 'Stronger', 'foxfire-studio' ) : __( 'Standard', 'foxfire-studio' ), eng === 'stronger' ? __( 'Stronger', 'foxfire-studio' ) : __( 'Standard', 'foxfire-studio' ) ));
				return out2;
			});
		}
		return preview(win).then(function (r) {
			var out = { key: key, before: r.before, after: r.after, report: r.report };
			heard = out;
			var rep = r.report || {};
			var bits = [];
			if (rep.plosives) { bits.push(sprintf( /* translators: %s: how many. */ _n( '%s pop softened', '%s pops softened', rep.plosives, 'foxfire-studio' ), rep.plosives )); }
			if (rep.sibilants) { bits.push(sprintf( /* translators: %s: how many. */ _n( '%s S sound softened', '%s S sounds softened', rep.sibilants, 'foxfire-studio' ), rep.sibilants )); }
			if (rep.breaths) { bits.push(sprintf( /* translators: %s: how many. */ _n( '%s breath lowered', '%s breaths lowered', rep.breaths, 'foxfire-studio' ), rep.breaths )); }
			if (rep.clicks) { bits.push(sprintf( /* translators: %s: how many. */ _n( '%s click lowered', '%s clicks lowered', rep.clicks, 'foxfire-studio' ), rep.clicks )); }
			if (rep.eq) { bits.push(__( 'EQ on', 'foxfire-studio' )); }
			say(bits.length ? sprintf( /* translators: %s: what the polish did in this stretch. */ __( 'Around this sentence: %s.', 'foxfire-studio' ), bits.join(', ') ) : __( 'Around this sentence the polish had little to do.', 'foxfire-studio' ));
			return out;
		});
	}

	/* Two loops started together, one heard at a time, so Before and After switch at the same moment. */
	function stop() {
		if (player) {
			try { player.a.stop(); player.b.stop(); } catch (e) {}
			try { player.ctx.close(); } catch (e) {}
		}
		player = null;
		playBtn.textContent = __( 'Play', 'foxfire-studio' );
	}
	function setSide(s) {
		side = s;
		beforeBtn.setAttribute('aria-pressed', s === 'before' ? 'true' : 'false');
		afterBtn.setAttribute('aria-pressed', s === 'after' ? 'true' : 'false');
		if (player) {
			var t = player.ctx.currentTime;
			player.ga.gain.setTargetAtTime(s === 'before' ? 1 : 0, t, 0.01);
			player.gb.gain.setTargetAtTime(s === 'after' ? 1 : 0, t, 0.01);
		}
	}
	function play() {
		var AC = window.AudioContext || window.webkitAudioContext;
		if (!AC) { say(__( 'This browser cannot play the preview.', 'foxfire-studio' )); return; }
		stop();
		if (studio.rv && studio.rv.audio) { studio.rv.audio.pause(); }
		playBtn.disabled = true;
		work().then(function (h) {
			var ctx = new AC();
			function buf(x) {
				var b = ctx.createBuffer(1, x.length, RATE);
				b.copyToChannel(x, 0);
				return b;
			}
			var a = ctx.createBufferSource(), b = ctx.createBufferSource();
			a.buffer = buf(h.before);
			b.buffer = buf(h.after);
			a.loop = true;
			b.loop = true;
			var ga = ctx.createGain(), gb = ctx.createGain();
			ga.gain.value = side === 'before' ? 1 : 0;
			gb.gain.value = side === 'after' ? 1 : 0;
			a.connect(ga);
			b.connect(gb);
			ga.connect(ctx.destination);
			gb.connect(ctx.destination);
			var at = ctx.currentTime + 0.05;
			a.start(at);
			b.start(at);
			player = { ctx: ctx, a: a, b: b, ga: ga, gb: gb };
			playBtn.textContent = __( 'Stop', 'foxfire-studio' );
		}, function (err) {
			say(sprintf( /* translators: %s: error. */ __( 'The sentence could not be worked out (%s).', 'foxfire-studio' ), err && err.message ? err.message : __( 'unknown error', 'foxfire-studio' ) ));
		}).then(function () { playBtn.disabled = false; });
	}
	playBtn.addEventListener('click', function () { if (player) { stop(); } else { play(); } });
	beforeBtn.addEventListener('click', function () { setSide('before'); });
	afterBtn.addEventListener('click', function () { setSide('after'); });

	function applyOneOff(next, done) {
		stop();
		useBtn.disabled = true;
		resetBtn.disabled = true;
		if ((next.clicks !== undefined ? next.clicks : !!SOUND.clicks) && !gaps && studio.rv && studio.rv.last) {
			gaps = PL.gaps(studio.rv.last.chunks);
			gapsFor = studio.lastMp3 ? studio.lastMp3.samples : null;
		}
		var prev = oneOff;
		oneOff = next;
		remake(say).then(function (line) {
			say(done + (line ? ' ' + line : ''));
			resultInfo.textContent = done;
		}, function (err) {
			oneOff = prev;
			say(sprintf( /* translators: %s: error. */ __( 'The MP3 could not be made again (%s).', 'foxfire-studio' ), err && err.message ? err.message : __( 'unknown error', 'foxfire-studio' ) ));
		}).then(function () {
			fill();
			useBtn.disabled = same(draft, oneOff);
			resetBtn.disabled = same(oneOff, {});
		});
	}
	useBtn.addEventListener('click', function () {
		readDraft();
		applyOneOff(draft, __( 'This take\'s MP3 was made again with your choices. Download, Use this recording, clips and Share for feedback now give this version; Settings is unchanged.', 'foxfire-studio' ));
	});
	resetBtn.addEventListener('click', function () {
		applyOneOff({}, __( 'This take\'s MP3 was made again with the Settings choice.', 'foxfire-studio' ));
	});

	function panelClose() {
		if (!box) { return; }
		stop();
		box.hidden = true;
		if (btn) { btn.setAttribute('aria-expanded', 'false'); }
	}
	function panelOpen() {
		if (!ctrl.plosives) { build(); }
		sentList = fillSentences();
		fill();
		useBtn.disabled = true;
		resetBtn.disabled = same(oneOff, {});
		heard = null;
		say(sentList.length ? '' : __( 'The review found no sentence to play.', 'foxfire-studio' ));
		box.hidden = false;
		btn.setAttribute('aria-expanded', 'true');
	}
	btn.addEventListener('click', function () { if (box.hidden) { panelOpen(); } else { panelClose(); } });
	closeBtn.addEventListener('click', panelClose);

	/* Shown with a reviewed take whose MP3 has the broadcast sound. */
	studio.polishShow = function () {
		var on = !!(studio.soundPreset ? studio.soundPreset() : null) && !!(studio.lastMp3 ? studio.lastMp3.samples : null);
		btn.hidden = !on;
		if (!on) { panelClose(); } else if (!box.hidden) { panelOpen(); }
	};
	if (studio.rv && studio.rv.closeBtn) { studio.rv.closeBtn.addEventListener('click', stop); }
})();
