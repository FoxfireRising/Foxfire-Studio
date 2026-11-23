/*
 * Foxfire Studio: Edit audio: selecting words in the review script, cuts, Tidy, undo, the
 * edited MP3, and retakes with their edges, space, trims and fitting.
 * Shares window.RRTStudio with the studio's other files (made in studio-core.js;
 * docs/ARCHITECTURE.md maps them). Moved from teleprompter.js in 0.25.2.
 */
(function () {
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
	var studio = window.RRTStudio;
	/* From the studio's other files. */
	var clean = studio.clean;
	var norm = studio.norm;
	var heardWords = studio.heardWords;
	var track = studio.track;
	var resultAudio = studio.resultAudio;
	var resultInfo = studio.resultInfo;
	var downloadA = studio.downloadA;
	var slug = studio.slug;
	var fmt = studio.fmt;
	var CAPTURE_SRC = studio.CAPTURE_SRC;
	var SOUND = studio.SOUND;
	var TAGS = studio.TAGS;
	var tagMp3 = studio.tagMp3;
	var chaptersFrom = studio.chaptersFrom;
	var musicSetVoice = studio.musicSetVoice;
	var masterMp3 = studio.masterMp3;
	var resampleTo = studio.resampleTo;
	var cleanWithModel = studio.cleanWithModel;
	var wantModel = studio.wantModel;
	var transcribeWith = studio.transcribeWith;
	var to16k = studio.to16k;
	var rv = studio.rv;
	var el = studio.el;
	var buildVtt = studio.buildVtt;
	studio.edSetTake = edSetTake;
	studio.edTokens = edTokens;
	studio.edPaint = edPaint;
	studio.edCut = edCut;
	studio.edUndo = edUndo;
	studio.edNudge = edNudge;
	studio.rtClose = rtClose;
	studio.rtNudge = rtNudge;
	if (!track) { return; }

	/*
	 * Text-based editing (0.8.0). With Edit audio on, clicking a word or a
	 * filler selects it (shift-click extends), and Cut or the Delete key
	 * removes it from the audio. Clicking a cut word brings it back. Tidy
	 * suggests cuts for fillers, repeats and long pauses. Nothing touches the
	 * recording: the cuts are a list of time ranges (see edit.js), applied when
	 * the edited MP3 is made, which runs the whole sound chain again so its
	 * loudness is measured on exactly what listeners hear.
	 */
	var ed = {
		on: false, cuts: [], undo: [], tokens: [], pauses: [], a: -1, b: -1, take: null, busy: false, last: null, made: '', preview: new Audio(),
		toggle: document.getElementById('tpEdToggle'),
		tools: document.getElementById('tpEdTools'),
		cutBtn: document.getElementById('tpEdCut'),
		previewBtn: document.getElementById('tpEdPreview'),
		tidyBtn: document.getElementById('tpEdTidy'),
		retakeBtn: document.getElementById('tpEdRetake'),
		sentBtn: document.getElementById('tpEdSent'),
		paraBtn: document.getElementById('tpEdPara'),
		undoBtn: document.getElementById('tpEdUndo'),
		makeBtn: document.getElementById('tpEdMake'),
		status: document.getElementById('tpEdStatus'),
		tidyBox: document.getElementById('tpEdTidyBox'),
		tidyHead: document.getElementById('tpEdTidyHead'),
		tidyList: document.getElementById('tpEdTidyList'),
		tidyApply: document.getElementById('tpEdTidyApply'),
		tidyCancel: document.getElementById('tpEdTidyCancel'),
		plan: []
	};
	studio.ed = ed;
	var ED_RATE = 44100;
	studio.ED_RATE = ED_RATE;
	function edReady() { return !!(window.RRTEdit && ed.take && ed.take.samples); }
	function edSetTake(take) {
		if (take === ed.take) { return; }
		ed.take = take;
		ed.cuts = [];
		ed.undo = [];
		ed.a = -1;
		ed.b = -1;
		ed.last = null;
		ed.made = '';
		ed.tidyBox.hidden = true;
		rtClose();
		ed.status.textContent = '';
		edPaint();
	}
	function edTokens(list) {
		ed.tokens = list.filter(function (t) { return isFinite(t.s) ? (isFinite(t.e) ? t.e > t.s : false) : false; })
			.sort(function (p, q) { return p.s - q.s; });
		ed.tokens.forEach(function (t, i) { t.el.setAttribute('data-ti', String(i)); });
		ed.a = -1;
		ed.b = -1;
		edPaint();
	}
	function edRange() {
		if (ed.a < 0) { return null; }
		return [Math.min(ed.a, ed.b), Math.max(ed.a, ed.b)];
	}
	function edPaint() {
		var range = edRange();
		ed.tokens.forEach(function (t, i) {
			t.cut = ed.cuts.length ? window.RRTEdit.isCut(ed.cuts, t.s, t.e) : false;
			t.retaken = ed.cuts.length ? window.RRTEdit.isRetaken(ed.cuts, t.s, t.e) : false;
			t.el.classList.toggle('is-cut', t.cut);
			t.el.classList.toggle('is-retaken', t.retaken);
			t.el.classList.toggle('is-sel', range ? (i >= range[0] ? i <= range[1] : false) : false);
		});
		/* A shortened pause shows its new length. */
		ed.pauses.forEach(function (p) {
			var gone = 0;
			ed.cuts.forEach(function (c) { gone += Math.max(0, Math.min(p.s + p.len, c.e) - Math.max(p.s, c.s)); });
			p.el.textContent = '\u2016 ' + (gone > 0.05 ? p.len.toFixed(1) + ' \u2192 ' + Math.max(0, p.len - gone).toFixed(1) : p.len.toFixed(1)) + 's';
			p.el.classList.toggle('is-cut', gone > 0.05);
		});
		var ready = edReady();
		ed.toggle.disabled = !ready;
		ed.toggle.title = ready ? __( 'Cut words, fillers and long pauses by editing the script', 'foxfire-studio' ) : __( 'Editing works on a take made or opened on this page since it was loaded.', 'foxfire-studio' );
		if (studio.helpText) { studio.helpText(ed.toggle, ed.toggle.title); }
		ed.toggle.setAttribute('aria-pressed', ed.on ? 'true' : 'false');
		ed.tools.hidden = !ed.on;
		rv.script.classList.toggle('is-editing', ed.on);
		ed.cutBtn.disabled = !range || ed.busy;
		ed.previewBtn.disabled = (!range ? !ed.last : false) || ed.busy;
		ed.undoBtn.disabled = !ed.undo.length || ed.busy;
		var key = JSON.stringify(ed.cuts);
		/* Flags after edits (1.1.0): the review marks what the edits cover. */
		if (studio.flagsEdited) { studio.flagsEdited(key); }
		ed.makeBtn.disabled = !ed.cuts.length || ed.busy || ed.made === key;
		ed.makeBtn.textContent = ed.made ? (ed.made === key ? __( 'Edited MP3 is up to date', 'foxfire-studio' ) : __( 'Make edited MP3 again', 'foxfire-studio' )) : __( 'Make edited MP3', 'foxfire-studio' );
		ed.tidyBtn.disabled = ed.busy;
		ed.retakeBtn.disabled = !range || ed.busy || !rtSupported();
		if (ed.sentBtn) { ed.sentBtn.disabled = !range || ed.busy; }
		if (ed.paraBtn) { ed.paraBtn.disabled = !range || ed.busy; }
		if (ed.on ? !ed.busy : false) {
			var nr = ed.cuts.filter(function (c) { return !!c.ins; }).length;
			var n = ed.cuts.length - nr;
			var diff = window.RRTEdit.removed(ed.cuts, ED_RATE);
			var parts = [];
			if (n) { parts.push(n + (_n( ' cut', ' cuts', n, 'foxfire-studio' ))); }
			if (nr) { parts.push(nr + (_n( ' retake', ' retakes', nr, 'foxfire-studio' ))); }
			if (parts.length) { parts.push(Math.abs(diff).toFixed(1) + (diff >= 0 ? __( ' s shorter.', 'foxfire-studio' ) : __( ' s longer.', 'foxfire-studio' ))); }
			var nsel = range ? range[1] - range[0] + 1 : 0;
			ed.status.textContent = parts.length ? parts.join(', ') : (range ? sprintf( /* translators: %s: nsel === 1 ? '1 word' : sprintf( __( '%s words', 'foxfire-st. */ __( '%s selected. Cut (or press Delete), or Retake to record it again. Shift and the arrow keys make it longer or shorter.', 'foxfire-studio' ), nsel === 1 ? '1 word' : sprintf( /* translators: %s: nsel. */ __( '%s words', 'foxfire-studio' ), nsel ) ) : __( 'Click a word, drag across words or shift-click for more, double-click for a sentence, triple-click for a paragraph. From the keyboard: Shift and an arrow in the script.', 'foxfire-studio' ));
		}
	}
	studio.edKey = function () { return JSON.stringify(ed.cuts); };
	function edSetCuts(next, focus) {
		ed.undo.push(ed.cuts);
		ed.cuts = window.RRTEdit.normalize(next);
		ed.last = focus;
		ed.a = -1;
		ed.b = -1;
		edPaint();
	}
	function edCut() {
		var range = edRange();
		if (!range || !edReady()) { return; }
		var c = window.RRTEdit.planCut(ed.tokens, range[0], range[1], ed.take.samples, ED_RATE);
		edSetCuts(ed.cuts.concat([c]), c);
	}
	function edRestore(i) {
		var t = ed.tokens[i];
		var keep = ed.cuts.filter(function (c) { return !(c.s < t.e ? c.e > t.s : false); });
		edSetCuts(keep, null);
		ed.status.textContent = sprintf( /* translators: %s: ed.status.textContent. */ __( 'Brought back. %s', 'foxfire-studio' ), ed.status.textContent );
	}
	function edUndo() {
		if (!ed.undo.length) { return; }
		ed.cuts = ed.undo.pop();
		ed.last = ed.cuts.length ? ed.cuts[ed.cuts.length - 1] : null;
		edPaint();
	}
	/* A few seconds around a cut, with the cuts applied, at a comfortable level. */
	function edPreview(cuts, focus) {
		if (!edReady() || !focus) { return; }
		var x = ed.take.samples;
		var dur = x.length / ED_RATE;
		var t0 = Math.max(0, focus.s - 2.5);
		var t1 = Math.min(dur, focus.e + 2.5);
		var sub = x.subarray(Math.round(t0 * ED_RATE), Math.round(t1 * ED_RATE));
		var local = window.RRTEdit.normalize(cuts).filter(function (c) { return c.e > t0 ? c.s < t1 : false; })
			.map(function (c) { var m = { s: Math.max(0, c.s - t0), e: Math.min(t1, c.e) - t0 }; if (c.ins) { m.ins = c.ins; if (c.ps) { m.ps = c.ps; } if (c.pe) { m.pe = c.pe; } if (c.tone) { m.tone = c.tone; } } return m; });
		var y = window.RRTEdit.render(sub, ED_RATE, local);
		var peak = 0;
		for (var i = 0; i < y.length; i++) { var v = Math.abs(y[i]); if (v > peak) { peak = v; } }
		if (peak > 0) { var g = Math.min(8, 0.7 / peak); for (i = 0; i < y.length; i++) { y[i] *= g; } }
		rv.audio.pause();
		if (ed.preview.src) { URL.revokeObjectURL(ed.preview.src); }
		ed.preview.src = URL.createObjectURL(new Blob([window.RRTEdit.wav(y, ED_RATE)], { type: 'audio/wav' }));
		ed.preview.play().catch(function () {});
	}
	/*
	 * Edge nudge (0.20.0): the join at one side of a retake, 1.5 s either
	 * side of it as it will sound, so each step is heard at once.
	 */
	function edPreviewEdge(cuts, focus, side) {
		if (!edReady() || !focus) { return; }
		var x = ed.take.samples;
		var dur = x.length / ED_RATE;
		var R = 1.5;
		/* Wide enough that a short retake still has 1.5 s on its far side. */
		var t0 = Math.max(0, focus.s - R);
		var t1 = Math.min(dur, focus.e + R);
		var sub = x.subarray(Math.round(t0 * ED_RATE), Math.round(t1 * ED_RATE));
		var local = window.RRTEdit.normalize(cuts).filter(function (c) { return c.e > t0 ? c.s < t1 : false; })
			.map(function (c) { var m = { s: Math.max(0, c.s - t0), e: Math.min(t1, c.e) - t0 }; if (c.ins) { m.ins = c.ins; if (c.ps) { m.ps = c.ps; } if (c.pe) { m.pe = c.pe; } if (c.tone) { m.tone = c.tone; } } return m; });
		var y = window.RRTEdit.render(sub, ED_RATE, local);
		/* From 1.5 s before the join to 1.5 s after it, in the edited sound. */
		var at = side === 's' ? Math.round((focus.s - t0) * ED_RATE) : y.length - Math.round((t1 - focus.e) * ED_RATE);
		var a = Math.max(0, at - Math.round(R * ED_RATE));
		var b = Math.min(y.length, at + Math.round(R * ED_RATE));
		y = y.slice(a, b);
		var peak = 0;
		for (var i = 0; i < y.length; i++) { var v = Math.abs(y[i]); if (v > peak) { peak = v; } }
		if (peak > 0) { var g = Math.min(8, 0.7 / peak); for (i = 0; i < y.length; i++) { y[i] *= g; } }
		rv.audio.pause();
		ed.preview.pause();
		if (ed.preview.src) { URL.revokeObjectURL(ed.preview.src); }
		ed.preview.src = URL.createObjectURL(new Blob([window.RRTEdit.wav(y, ED_RATE)], { type: 'audio/wav' }));
		ed.preview.play().catch(function () {});
	}
	function edPreviewNow() {
		var range = edRange();
		if (range) {
			var c = window.RRTEdit.planCut(ed.tokens, range[0], range[1], ed.take.samples, ED_RATE);
			edPreview(ed.cuts.concat([c]), c);
		} else if (ed.last) {
			edPreview(ed.cuts, ed.last);
		}
	}
	function edTidy() {
		if (!edReady()) { return; }
		var plan = window.RRTEdit.tidyPlan(ed.tokens, ed.take.samples, ED_RATE, {});
		// Leave out anything already cut.
		ed.plan = plan.filter(function (p) {
			for (var i = 0; i < ed.cuts.length; i++) {
				if (p.s < ed.cuts[i].e ? p.e > ed.cuts[i].s : false) { return false; }
			}
			return true;
		});
		ed.tidyList.innerHTML = '';
		var counts = { filler: 0, repeat: 0, pause: 0 };
		ed.plan.forEach(function (p, i) {
			counts[p.kind] += 1;
			var li = el('li', 'k-' + p.kind);
			var lab = document.createElement('label');
			var cb = document.createElement('input');
			cb.type = 'checkbox';
			cb.checked = true;
			cb.setAttribute('data-i', String(i));
			lab.appendChild(cb);
			lab.appendChild(el('span', 'tp-ed-time', fmt(p.at)));
			lab.appendChild(document.createTextNode(' ' + p.label));
			li.appendChild(lab);
			var hear = el('button', 'tp-btn tp-ed-hear', '\u25B6');
			hear.type = 'button';
			hear.title = __( 'Hear it with this cut', 'foxfire-studio' );
			hear.addEventListener('click', function () { edPreview(ed.cuts.concat([p]), p); });
			li.appendChild(hear);
			ed.tidyList.appendChild(li);
		});
		if (!ed.plan.length) {
			ed.tidyHead.textContent = __( 'Nothing to tidy: no fillers, repeats or pauses over 1.5 seconds were found. Whisper often leaves out ums and uhs when it listens, so strike any you hear yourself.', 'foxfire-studio' );
		} else {
			ed.tidyHead.textContent = sprintf( /* translators: %s: [counts.filler ? counts.filler + (_n( ' filler', ' fillers',. */ __( 'Suggested: %s. Untick any to keep, and press ▶ to hear one first. Whisper often leaves out ums and uhs when it listens, so some may be missing.', 'foxfire-studio' ), [counts.filler ? counts.filler + (_n( ' filler', ' fillers', counts.filler, 'foxfire-studio' )) : '', counts.repeat ? counts.repeat + (_n( ' repeat', ' repeats', counts.repeat, 'foxfire-studio' )) : '', counts.pause ? counts.pause + (_n( ' long pause to shorten', ' long pauses to shorten', counts.pause, 'foxfire-studio' )) : ''].filter(Boolean).join(', ') );
		}
		ed.tidyApply.hidden = !ed.plan.length;
		ed.tidyBox.hidden = false;
	}
	ed.tidyApply.addEventListener('click', function () {
		var picked = [];
		Array.prototype.forEach.call(ed.tidyList.querySelectorAll('input[type=checkbox]'), function (cb) {
			if (cb.checked) { picked.push(ed.plan[Number(cb.getAttribute('data-i'))]); }
		});
		ed.tidyBox.hidden = true;
		if (picked.length) { edSetCuts(ed.cuts.concat(picked.map(function (p) { return { s: p.s, e: p.e }; })), picked[picked.length - 1]); }
	});
	ed.tidyCancel.addEventListener('click', function () { ed.tidyBox.hidden = true; });
	/* The edited take, mastered, tagged with shifted chapters, with shifted captions. */
	function edMake() {
		if (!edReady() || !ed.cuts.length || ed.busy) { return; }
		ed.busy = true;
		edPaint();
		var take = ed.take;
		var cuts = ed.cuts.slice();
		var x = window.RRTEdit.render(take.samples, ED_RATE, cuts);
		var secs = x.length / ED_RATE;
		var names = { sound: __( 'Shaping the sound', 'foxfire-studio' ), check: __( 'Checking the MP3', 'foxfire-studio' ) };
		ed.status.textContent = __( 'Making the edited MP3...', 'foxfire-studio' );
		masterMp3(x, function (p, phase) {
			ed.status.textContent = (names[phase] || __( 'Making the edited MP3', 'foxfire-studio' )) + '... ' + Math.round(p * 100) + '%';
		}).then(function (m) {
			var map = function (t) { return window.RRTEdit.mapTime(cuts, t, ED_RATE); };
			var ch = chaptersFrom(rv.R, take.seconds).map(function (c) { return { start: map(c.start), title: c.title }; })
				.filter(function (c, i, all) { return i === 0 || c.start > all[i - 1].start + 1; });
			for (var i = 0; i < ch.length; i++) { ch[i].end = i + 1 < ch.length ? ch[i + 1].start : secs; }
			if (ch.length < 2) { ch = []; }
			return tagMp3(m.blob, secs, ch).then(function (tagged) {
				var url = URL.createObjectURL(tagged);
				musicSetVoice({ samples: x, seconds: secs, chapters: ch, cuts: cuts });
				downloadA.href = url;
				var wavOut = studio.finishExt ? studio.finishExt() === 'wav' : false;
				downloadA.setAttribute('download', slug + '-reading-edited.' + (wavOut ? 'wav' : 'mp3'));
				downloadA.textContent = wavOut ? __( 'Download edited WAV', 'foxfire-studio' ) : __( 'Download edited MP3', 'foxfire-studio' );
				downloadA.hidden = false;
				resultAudio.src = url;
				studio.unsaved = true;
				rv.vtt.href = URL.createObjectURL(new Blob([buildVtt(rv.R, cuts)], { type: 'text/vtt' }));
				rv.vtt.setAttribute('download', slug + '-captions-edited.vtt');
				if (ch.length) {
					rv.chapters.href = URL.createObjectURL(new Blob([window.RRTId3.chaptersJson(ch, TAGS ? TAGS.title : '')], { type: 'application/json+chapters' }));
					rv.chapters.setAttribute('download', slug + '-chapters-edited.json');
					rv.chapters.hidden = false;
				}
				var c = m.sound ? m.sound.check : null;
				var loud = c ? (m.sound.plan.mode === 'audiobook' ? sprintf( /* translators: %s: c.measures.rms.toFixed(1). */ __( '%s dB RMS', 'foxfire-studio' ), c.measures.rms.toFixed(1) ) : sprintf( /* translators: %s: c.measures.lufs.toFixed(1). */ __( '%s LUFS', 'foxfire-studio' ), c.measures.lufs.toFixed(1) )) : '';
				ed.made = JSON.stringify(cuts);
				var diff = window.RRTEdit.removed(cuts, ED_RATE);
				var msg = sprintf( /* translators: 1: fmt(secs), 2: Math.abs(diff).toFixed(1), 3: diff >= 0 ? __( ' s shorter', 'foxfire-studio' ) : __( ' s longer', 'foxfire-studio' ), 4: loud ? ', ' + loud : '', 5: ch.length ? sprintf( __( ', %s chapters', 'foxfire-studio' ). */ __( 'Edited MP3 ready: %1$s, %2$s%3$s%4$s%5$s. The download button and the captions now give the edited versions.', 'foxfire-studio' ), fmt(secs), Math.abs(diff).toFixed(1), diff >= 0 ? __( ' s shorter', 'foxfire-studio' ) : __( ' s longer', 'foxfire-studio' ), loud ? ', ' + loud : '', ch.length ? sprintf( /* translators: %s: ch.length. */ __( ', %s chapters', 'foxfire-studio' ), ch.length ) : '' );
				ed.status.textContent = msg;
				resultInfo.textContent = msg;
			});
		}).catch(function (err) {
			ed.status.textContent = sprintf( /* translators: %s: err && err.message ? err.message : __( 'unknown error', 'fox. */ __( 'The edited MP3 could not be made (%s). Your cuts are kept, so you can try again.', 'foxfire-studio' ), err && err.message ? err.message : __( 'unknown error', 'foxfire-studio' ) );
		}).then(function () {
			ed.busy = false;
			edPaint();
			if (/^Edited MP3 ready/.test(resultInfo.textContent)) { ed.status.textContent = resultInfo.textContent; }
		});
	}
	ed.toggle.addEventListener('click', function () {
		if (!edReady()) { return; }
		ed.on = !ed.on;
		if (!ed.on) { ed.a = -1; ed.b = -1; ed.tidyBox.hidden = true; rtClose(); ed.status.textContent = ''; }
		edPaint();
	});
	edPaint();
	ed.cutBtn.addEventListener('click', edCut);
	ed.previewBtn.addEventListener('click', edPreviewNow);
	ed.tidyBtn.addEventListener('click', edTidy);
	ed.undoBtn.addEventListener('click', edUndo);
	ed.makeBtn.addEventListener('click', edMake);
	/* In edit mode a click selects instead of playing. */
	rv.script.addEventListener('click', function (e) {
		if (!ed.on) { return; }
		var t = e.target.closest ? e.target.closest('[data-ti]') : null;
		e.stopPropagation();
		e.preventDefault();
		/* A click that ends a drag keeps what the drag selected. */
		if (ed.dragged) { ed.dragged = false; return; }
		if (!t) { ed.a = -1; ed.b = -1; edPaint(); return; }
		var i = Number(t.getAttribute('data-ti'));
		if (ed.tokens[i].cut) { edRestore(i); return; }
		/* A retaken word opens its retake again, with the edge controls (0.20.0). */
		if (ed.tokens[i].retaken) { rtReopen(i); return; }
		/* Triple-click: the whole paragraph (0.18.1). */
		if (e.detail >= 3) { ed.a = i; ed.b = i; edExpand('block'); return; }
		if (e.shiftKey ? ed.a >= 0 : false) { ed.b = i; } else { ed.a = i; ed.b = i; }
		// A selection never spans a cut.
		var range = edRange();
		for (var q = range[0]; q <= range[1]; q++) {
			if (ed.tokens[q].cut) { ed.a = i; ed.b = i; break; }
		}
		edPaint();
	}, true);

	/* Double-click selects the whole sentence, ready for a retake. */
	rv.script.addEventListener('dblclick', function (e) {
		if (!ed.on) { return; }
		var t = e.target.closest ? e.target.closest('[data-ti]') : null;
		if (!t) { return; }
		e.preventDefault();
		var i = Number(t.getAttribute('data-ti'));
		if (ed.tokens[i].cut || ed.tokens[i].retaken) { return; }
		ed.a = i;
		ed.b = i;
		edExpand('sent');
	});

	/*
	 * Selection (0.18.1): widen the selection to whole sentences or the whole
	 * paragraph ('sent' or 'block'), never across a cut.
	 */
	function edExpand(key) {
		var range = edRange();
		if (!range) { return; }
		var first = ed.tokens[range[0]][key];
		var last = ed.tokens[range[1]][key];
		var a = range[0];
		var b = range[1];
		while (a > 0 ? (ed.tokens[a - 1][key] === first ? !ed.tokens[a - 1].cut : false) : false) { a--; }
		while (b + 1 < ed.tokens.length ? (ed.tokens[b + 1][key] === last ? !ed.tokens[b + 1].cut : false) : false) { b++; }
		ed.a = a;
		ed.b = b;
		if (window.getSelection) { window.getSelection().removeAllRanges(); }
		edPaint();
	}
	/* Shift and an arrow key: one word longer or shorter at the end being moved. */
	function edNudge(dir) {
		var range = edRange();
		if (!range) { return; }
		var nb = ed.b + dir;
		if (nb < 0 || nb >= ed.tokens.length || ed.tokens[nb].cut) { return; }
		ed.b = nb;
		edPaint();
	}
	if (ed.sentBtn) { ed.sentBtn.addEventListener('click', function () { edExpand('sent'); }); }
	if (ed.paraBtn) { ed.paraBtn.addEventListener('click', function () { edExpand('block'); }); }
	/* Drag across words to select them. */
	var edDrag = -1;
	rv.script.addEventListener('mousedown', function (e) {
		if (!ed.on || e.button !== 0 || e.shiftKey || e.detail > 1) { return; }
		var t = e.target.closest ? e.target.closest('[data-ti]') : null;
		if (!t) { return; }
		var i = Number(t.getAttribute('data-ti'));
		if (ed.tokens[i].cut || ed.tokens[i].retaken) { return; }
		edDrag = i;
		ed.dragged = false;
		e.preventDefault();
	});
	rv.script.addEventListener('mouseover', function (e) {
		if (edDrag < 0 || !(e.buttons & 1)) { return; }
		var t = e.target.closest ? e.target.closest('[data-ti]') : null;
		if (!t) { return; }
		var i = Number(t.getAttribute('data-ti'));
		if (i === edDrag ? !ed.dragged : false) { return; }
		var lo = Math.min(edDrag, i);
		var hi = Math.max(edDrag, i);
		for (var q = lo; q <= hi; q++) { if (ed.tokens[q].cut) { return; } }
		ed.a = edDrag;
		ed.b = i;
		ed.dragged = i !== edDrag;
		edPaint();
	});
	document.addEventListener('mouseup', function () { edDrag = -1; });

	/*
	 * Punch-in retakes (0.8.0). Record the selected words again; the new
	 * recording is trimmed to the speech, cleaned up the same way as the take,
	 * matched to its level, and spliced in place of the old words with a
	 * crossfade at each side. Nothing is used until "Use this take".
	 */
	var rt = {
		box: document.getElementById('tpEdRetakeBox'),
		line: document.getElementById('tpEdRetakeLine'),
		note: document.getElementById('tpEdRetakeNote'),
		recBtn: document.getElementById('tpEdRecBtn'),
		hear: document.getElementById('tpEdRecHear'),
		use: document.getElementById('tpEdRecUse'),
		cancel: document.getElementById('tpEdRecCancel'),
		back: document.getElementById('tpEdRecBack'),
		edges: document.getElementById('tpEdEdges'),
		edgeS: document.getElementById('tpEdEdgeS'),
		edgeE: document.getElementById('tpEdEdgeE'),
		edgeReset: document.getElementById('tpEdEdgeReset'),
		space: document.getElementById('tpEdSpace'),
		spaceS: document.getElementById('tpEdSpaceS'),
		spaceE: document.getElementById('tpEdSpaceE'),
		trimRow: document.getElementById('tpEdTrim'),
		trimS: document.getElementById('tpEdTrimS'),
		trimE: document.getElementById('tpEdTrimE'),
		cap: null, clip: null, raw: null, trim: null, plan: null, cut: null
	};
	studio.rt = rt;
	function rtSupported() {
		return !!(navigator.mediaDevices ? navigator.mediaDevices.getUserMedia : false) ? !!window.AudioWorkletNode : false;
	}
	function rtOpen() {
		var range = edRange();
		if (!range || !edReady()) { return; }
		rt.plan = window.RRTEdit.planSpan(ed.tokens, range[0], range[1], ed.take.samples, ED_RATE);
		rt.plan.o = { s: rt.plan.s, e: rt.plan.e };
		rt.cut = null;
		rt.range = range;
		rt.raw = null;
		rt.trim = null;
		rt.line.textContent = ed.tokens.slice(range[0], range[1] + 1).filter(function (t) { return t.kind === 'word'; }).map(function (t) { return t.label; }).join(' ') || ed.tokens[range[0]].label;
		rt.clip = null;
		rt.note.textContent = __( 'Read this as you would like it to sound, at your usual distance from the microphone. Press Record, read it, then press Stop.', 'foxfire-studio' );
		rt.recBtn.textContent = __( 'Record', 'foxfire-studio' );
		rt.recBtn.disabled = false;
		rt.hear.hidden = true;
		rt.use.hidden = true;
		rt.use.textContent = __( 'Use this take', 'foxfire-studio' );
		rt.back.hidden = true;
		rt.edges.hidden = true;
		rt.space.hidden = true;
		ed.tidyBox.hidden = true;
		rt.box.hidden = false;
		rv.audio.pause();
	}
	/*
	 * A retake already in place, opened again by clicking one of its words
	 * (0.20.0): its edges can be nudged, it can be recorded again, or the
	 * original words brought back.
	 */
	function rtReopen(i) {
		if (!edReady()) { return; }
		var t = ed.tokens[i];
		var c = null;
		ed.cuts.forEach(function (x) { if (x.ins ? (x.s < t.e ? x.e > t.s : false) : false) { c = x; } });
		if (!c) { return; }
		rtStopMic();
		var a = i;
		var b = i;
		while (a > 0 ? ed.tokens[a - 1].retaken : false) { a--; }
		while (b + 1 < ed.tokens.length ? ed.tokens[b + 1].retaken : false) { b++; }
		rt.cut = c;
		rt.range = [a, b];
		rt.plan = { s: c.s, e: c.e, o: c.o ? { s: c.o.s, e: c.o.e } : { s: c.s, e: c.e }, ps: c.ps || 0, pe: c.pe || 0, tone: c.tone || null };
		rt.clip = c.ins;
		rt.raw = c.raw || c.ins;
		rt.trim = c.trim ? { a: c.trim.a, b: c.trim.b, fa: c.trim.fa, fb: c.trim.fb } : { a: 0, b: c.ins.length, fa: 0, fb: c.ins.length };
		rt.line.textContent = ed.tokens.slice(a, b + 1).filter(function (x) { return x.kind === 'word'; }).map(function (x) { return x.label; }).join(' ') || t.label;
		rt.note.textContent = __( 'This retake is in place. If a word is heard twice at a join, listen to which copy is extra: a false start in the retake goes with Retake start or Retake end, the old reading with Start or End. If two words run together, add Space. Each step plays the join. You can also record it again, or bring back the words as first read.', 'foxfire-studio' );
		rt.recBtn.textContent = __( 'Record again', 'foxfire-studio' );
		rt.recBtn.disabled = false;
		rt.hear.hidden = false;
		rt.use.hidden = false;
		rt.use.textContent = __( 'Keep these edges', 'foxfire-studio' );
		rt.back.hidden = false;
		ed.a = -1;
		ed.b = -1;
		ed.tidyBox.hidden = true;
		rt.box.hidden = false;
		rtEdgesPaint();
		edPaint();
		rv.audio.pause();
	}
	function rtClose() {
		rtStopMic();
		rt.box.hidden = true;
		rt.edges.hidden = true;
		rt.space.hidden = true;
		if (rt.trimRow) { rt.trimRow.hidden = true; }
		rt.clip = null;
		rt.raw = null;
		rt.trim = null;
		rt.cut = null;
	}
	/* The readout: how much more or less of the old reading each side cuts, and any space added. */
	function rtEdgesPaint() {
		if (!rt.plan || !rt.clip) { rt.edges.hidden = true; rt.space.hidden = true; if (rt.trimRow) { rt.trimRow.hidden = true; } return; }
		var m = window.RRTEdit.nudged(rt.plan);
		/* Start earlier, or End later, cuts more of the old reading. */
		var ms = -m.s;
		var me = m.e;
		function say(n) { return n === 0 ? __( 'as found', 'foxfire-studio' ) : sprintf( /* translators: 1: Math.abs(n), 2: n > 0 ? __( 'more', 'foxfire-studio' ) : __( 'less', 'foxfire-studio' ). */ __( 'cuts %1$s ms %2$s', 'foxfire-studio' ), Math.abs(n), n > 0 ? __( 'more', 'foxfire-studio' ) : __( 'less', 'foxfire-studio' ) ); }
		function gap(t) { return t > 0 ? sprintf( /* translators: %s: Math.round(t * 1000). */ __( '%s ms', 'foxfire-studio' ), Math.round(t * 1000) ) : __( 'none', 'foxfire-studio' ); }
		/* The retake's own ends (0.25.1): positive trims more of the new recording. */
		function trimSay(n) { return n === 0 ? __( 'as fitted', 'foxfire-studio' ) : n > 0 ? sprintf( /* translators: %s: milliseconds. */ __( 'trims %s ms more', 'foxfire-studio' ), n ) : sprintf( /* translators: %s: milliseconds. */ __( 'keeps %s ms more', 'foxfire-studio' ), -n ); }
		var tm = window.RRTEdit.trimmed(rt.trim, ED_RATE);
		if (rt.trimRow) {
			rt.trimS.textContent = trimSay(tm.s);
			rt.trimE.textContent = trimSay(tm.e);
			rt.trimS.classList.toggle('is-moved', tm.s !== 0);
			rt.trimE.classList.toggle('is-moved', tm.e !== 0);
			rt.trimRow.hidden = !rt.trim;
		}
		rt.edgeS.textContent = say(ms);
		rt.edgeE.textContent = say(me);
		rt.edgeS.classList.toggle('is-moved', ms !== 0);
		rt.edgeE.classList.toggle('is-moved', me !== 0);
		rt.spaceS.textContent = gap(rt.plan.ps);
		rt.spaceE.textContent = gap(rt.plan.pe);
		rt.spaceS.classList.toggle('is-moved', rt.plan.ps > 0);
		rt.spaceE.classList.toggle('is-moved', rt.plan.pe > 0);
		rt.edgeReset.disabled = (ms === 0 ? me === 0 : false) ? !(rt.plan.ps > 0 || rt.plan.pe > 0 || tm.s !== 0 || tm.e !== 0) : false;
		rt.edges.hidden = false;
		rt.space.hidden = false;
	}
	/* Every other edit: a reopened retake leaves only itself out; a new one also replaces what it overlaps. */
	function rtOthers() {
		return ed.cuts.filter(function (c) {
			if (rt.cut) { return c !== rt.cut; }
			return !(c.s < rt.plan.e ? c.e > rt.plan.s : false);
		});
	}
	function rtNudge(side, dir) {
		if (!rt.plan || !rt.clip || rt.cap) { return; }
		var dur = ed.take.samples.length / ED_RATE;
		var n = window.RRTEdit.nudgeEdge(rtEdit(), side, dir * 0.01, rtOthers(), dur);
		if (!n) {
			rt.note.textContent = sprintf( /* translators: 1: side === 's' ? __( 'The start', 'foxfire-studio' ) : __( 'Th, 2: Math.round(window.RRTEdit.NUDGE_MAX * 1000). */ __( '%1$s cannot move further that way: it is %2$s ms from where it was found, at the next edit or the end of the take, or too close to the other side.', 'foxfire-studio' ), side === 's' ? __( 'The start', 'foxfire-studio' ) : __( 'The end', 'foxfire-studio' ), Math.round(window.RRTEdit.NUDGE_MAX * 1000) );
			return;
		}
		rt.plan.s = n.s;
		rt.plan.e = n.e;
		rtEdgesPaint();
		edPreviewEdge(rtOthers().concat([n]), n, side);
	}
	/*
	 * Space at a join: a pause of the room's own background before or after
	 * the new recording, 50 ms a step. The background is taken once, from the
	 * quietest stretch of the take near the retake.
	 */
	function rtSpace(side, dir) {
		if (!rt.plan || !rt.clip || rt.cap) { return; }
		if (!rt.plan.tone) {
			rt.plan.tone = window.RRTEdit.roomTone(ed.take.samples, ED_RATE, side === 's' ? rt.plan.s : rt.plan.e) || new Float32Array(0);
		}
		var n = window.RRTEdit.spaceEdge(rtEdit(), side, dir * 0.05);
		if (!n) {
			rt.note.textContent = dir < 0 ? __( 'There is no added space there to take out.', 'foxfire-studio' ) : sprintf( /* translators: %s: window.RRTEdit.SPACE_MAX. */ __( 'That is the most space a join can have (%s s).', 'foxfire-studio' ), window.RRTEdit.SPACE_MAX );
			return;
		}
		rt.plan.ps = n.ps || 0;
		rt.plan.pe = n.pe || 0;
		rtEdgesPaint();
		edPreviewEdge(rtOthers().concat([n]), n, side);
	}
	/* Trim the retake's own start or end, 10 ms a step (0.25.1): for a false start or a breath Whisper did not count as a word. */
	function rtTrim(side, dir) {
		if (!rt.plan || !rt.clip || !rt.raw || !rt.trim || rt.cap) { return; }
		var n = window.RRTEdit.trimEdge(rt.trim, side, dir * 0.01, rt.raw.length, ED_RATE);
		if (!n) {
			rt.note.textContent = dir * (side === 's' ? 1 : -1) > 0 ? sprintf( /* translators: %s: milliseconds. */ __( 'The retake cannot get shorter: it keeps at least %s ms.', 'foxfire-studio' ), Math.round(window.RRTEdit.TRIM_MIN * 1000) ) : __( 'That is all of the recording on that side.', 'foxfire-studio' );
			return;
		}
		rt.trim = n;
		rt.clip = rt.raw.slice(n.a, n.b);
		rtEdgesPaint();
		var r = rtEdit();
		edPreviewEdge(rtOthers().concat([r]), r, side);
	}
	if (rt.trimRow) {
		Array.prototype.forEach.call(rt.trimRow.querySelectorAll('.tp-ed-trim'), function (b) {
			b.addEventListener('click', function () { rtTrim(b.getAttribute('data-side'), Number(b.getAttribute('data-dir'))); });
		});
	}
	Array.prototype.forEach.call(rt.space.querySelectorAll('.tp-ed-space'), function (b) {
		b.addEventListener('click', function () { rtSpace(b.getAttribute('data-side'), Number(b.getAttribute('data-dir'))); });
	});
	Array.prototype.forEach.call(rt.edges.querySelectorAll('.tp-ed-nudge'), function (b) {
		b.addEventListener('click', function () { rtNudge(b.getAttribute('data-side'), Number(b.getAttribute('data-dir'))); });
	});
	rt.edgeReset.addEventListener('click', function () {
		if (!rt.plan) { return; }
		rt.plan.s = rt.plan.o.s;
		rt.plan.e = rt.plan.o.e;
		rt.plan.ps = 0;
		rt.plan.pe = 0;
		if (rt.raw ? rt.trim : false) {
			rt.trim = { a: rt.trim.fa, b: rt.trim.fb, fa: rt.trim.fa, fb: rt.trim.fb };
			rt.clip = rt.raw.slice(rt.trim.a, rt.trim.b);
		}
		rtEdgesPaint();
	});
	rt.back.addEventListener('click', function () {
		if (!rt.cut) { return; }
		var gone = rt.cut;
		rtClose();
		edSetCuts(ed.cuts.filter(function (c) { return c !== gone; }), null);
		ed.status.textContent = sprintf( /* translators: %s: ed.status.textContent. */ __( 'Brought back. %s', 'foxfire-studio' ), ed.status.textContent );
	});
	function rtStopMic() {
		var c = rt.cap;
		rt.cap = null;
		if (!c) { return; }
		try { c.stream.getTracks().forEach(function (t) { t.stop(); }); } catch (e) {}
		try { c.ctx.close(); } catch (e) {}
	}
	function rtStart() {
		var AC = window.AudioContext || window.webkitAudioContext;
		rt.recBtn.disabled = true;
		rt.note.textContent = __( 'Opening the microphone...', 'foxfire-studio' );
		navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: false, noiseSuppression: false, autoGainControl: false, channelCount: 1 } }).then(function (stream) {
			var ctx = new AC();
			var c = { stream: stream, ctx: ctx, parts: [], n: 0, node: null };
			rt.cap = c;
			var url = URL.createObjectURL(new Blob([CAPTURE_SRC], { type: 'application/javascript' }));
			return ctx.audioWorklet.addModule(url).then(function () {
				URL.revokeObjectURL(url);
				return ctx.state === 'running' ? null : ctx.resume();
			}).then(function () {
				var src = ctx.createMediaStreamSource(stream);
				var node = new AudioWorkletNode(ctx, 'rrt-capture', { numberOfInputs: 1, numberOfOutputs: 1, outputChannelCount: [1], channelCount: 1, channelCountMode: 'explicit' });
				var mute = ctx.createGain();
				mute.gain.value = 0;
				src.connect(node);
				node.connect(mute);
				mute.connect(ctx.destination);
				c.node = node;
				node.port.onmessage = function (e) {
					if (e.data.pcm) { c.parts.push(e.data.pcm); c.n += e.data.pcm.length; }
					if (e.data.done) { rtGot(c); }
				};
				node.port.postMessage('rec');
				rt.recBtn.textContent = __( 'Stop', 'foxfire-studio' );
				rt.recBtn.disabled = false;
				rt.hear.hidden = true;
				rt.use.hidden = true;
				rt.note.textContent = __( 'Recording. Read the line, then press Stop.', 'foxfire-studio' );
			});
		}).catch(function () {
			rtStopMic();
			rt.recBtn.disabled = false;
			rt.recBtn.textContent = __( 'Record', 'foxfire-studio' );
			rt.note.textContent = __( 'The microphone could not be opened. Check that this page may use it, then try again.', 'foxfire-studio' );
		});
	}
	function rtStop() {
		var c = rt.cap;
		if (!c || !c.node) { return; }
		rt.recBtn.disabled = true;
		rt.note.textContent = __( 'Cleaning up the retake and matching its level...', 'foxfire-studio' );
		c.node.port.postMessage('hold');
		c.node.port.postMessage('flush');
	}
	/* Mean power of the 50 ms blocks within 10 dB of the loudest: a level for speech that ignores the pauses. */
	function speechPower(x) {
		var n = Math.round(ED_RATE * 0.05);
		var ps = [];
		for (var a = 0; a + n <= x.length; a += n) {
			var s = 0;
			for (var i = a; i < a + n; i++) { s += x[i] * x[i]; }
			ps.push(s / n);
		}
		if (!ps.length) { return 0; }
		var top = Math.max.apply(null, ps);
		var sum = 0;
		var k = 0;
		ps.forEach(function (p) { if (p > top * 0.1) { sum += p; k++; } });
		return k ? sum / k : 0;
	}
	function rtGot(c) {
		var all = new Float32Array(c.n);
		var o = 0;
		c.parts.forEach(function (p) { all.set(p, o); o += p.length; });
		var srcRate = c.ctx.sampleRate;
		rtStopMic();
		resampleTo(all, srcRate, ED_RATE).then(function (x) {
			/* Trim to the speech, keeping a little room either side for the crossfades. */
			var hop = Math.round(ED_RATE * 0.02);
			var pw = [];
			for (var a = 0; a + hop <= x.length; a += hop) {
				var s = 0;
				for (var i = a; i < a + hop; i++) { s += x[i] * x[i]; }
				pw.push(s / hop);
			}
			var top = pw.length ? Math.max.apply(null, pw) : 0;
			var first = -1;
			var last = -1;
			for (var f = 0; f < pw.length; f++) { if (pw[f] > Math.max(top * 0.003, 1e-7)) { if (first < 0) { first = f; } last = f; } }
			if (first < 0 || (last - first) * 0.02 < 0.3) { throw new Error('quiet'); }
			var pad = Math.round(ED_RATE * 0.08);
			var seg = x.slice(Math.max(0, first * hop - pad), Math.min(x.length, (last + 1) * hop + pad));
			var clean = ed.take.room ? cleanWithModel(seg, ED_RATE, function () {}) : Promise.resolve(null);
			return clean.then(function (cl) { return cl ? cl.data : seg; });
		}).then(function (y) {
			return rtFit(y).then(function (fit) { fit.raw = y; return fit; });
		}).then(function (fit) {
			var y = fit.clip;
			var orig = ed.take.samples.subarray(Math.round(rt.plan.s * ED_RATE), Math.round(rt.plan.e * ED_RATE));
			var po = speechPower(orig);
			var pn = speechPower(y);
			var g = (po > 0 ? pn > 0 : false) ? Math.max(0.25, Math.min(4, Math.sqrt(po / pn))) : 1;
			/* The whole recording is kept, at the matched level, so its ends can be trimmed by ear (0.25.1). */
			var raw = new Float32Array(fit.raw.length);
			for (var i = 0; i < raw.length; i++) { raw[i] = fit.raw[i] * g; }
			rt.raw = raw;
			rt.trim = { a: fit.a, b: fit.b, fa: fit.a, fb: fit.b };
			var out = raw.slice(fit.a, fit.b);
			rt.clip = out;
			rt.note.textContent = sprintf( /* translators: 1: (out.length / ED_RATE).toFixed(1), 2: (rt.plan.e - rt.plan.s).toFixed(1), 3: Math.abs(20 * Math.log10(g)) >= 0.5 ? sprintf( __( ', level , 4: fit.note ? fit.note + ' ' : ''. */ __( 'Retake ready: %1$s s (the words it replaces took %2$s s)%3$s. %4$sHear it in place, then use it or record again.', 'foxfire-studio' ), (out.length / ED_RATE).toFixed(1), (rt.plan.e - rt.plan.s).toFixed(1), Math.abs(20 * Math.log10(g)) >= 0.5 ? sprintf( /* translators: 1: 20 * Math.log10(g) > 0 ? '+' : '', 2: (20 * Math.log10(g)).toFixed(1). */ __( ', level matched by %1$s%2$s dB', 'foxfire-studio' ), 20 * Math.log10(g) > 0 ? '+' : '', (20 * Math.log10(g)).toFixed(1) ) : '', fit.note ? fit.note + ' ' : '' );
			rt.recBtn.textContent = __( 'Record again', 'foxfire-studio' );
			rt.recBtn.disabled = false;
			rt.hear.hidden = false;
			rt.use.hidden = false;
			rt.use.textContent = rt.cut ? __( 'Use this take instead', 'foxfire-studio' ) : __( 'Use this take', 'foxfire-studio' );
			rtEdgesPaint();
		}).catch(function (err) {
			rt.recBtn.textContent = __( 'Record again', 'foxfire-studio' );
			rt.recBtn.disabled = false;
			rt.note.textContent = err && err.message === 'quiet' ? __( 'That retake was too quiet or too short to use. Try again a little closer to the microphone.', 'foxfire-studio' ) : sprintf( /* translators: %s: err && err.message ? err.message : __( 'unknown error', 'fox. */ __( 'The retake could not be prepared (%s). Try again.', 'foxfire-studio' ), err && err.message ? err.message : __( 'unknown error', 'foxfire-studio' ) );
		});
	}
	/*
	 * Fit the retake to the selected words (0.18.1): Whisper listens to it,
	 * and anything said before the first selected word or after the last
	 * (a run-up to find the rhythm, or carrying on) is trimmed away at the
	 * real edges of the voice. Without Whisper, the retake is used as
	 * trimmed to its speech. Resolves { clip, note }.
	 */
	function rtFit(y) {
		var want = ed.tokens.slice(rt.range[0], rt.range[1] + 1).filter(function (t) { return t.kind === 'word'; }).map(function (t) { return norm(clean(t.label)); }).filter(Boolean);
		var plain = Promise.resolve({ clip: y, note: '', a: 0, b: y.length });
		if (!want.length || studio.engineBusy || !(rv.last ? rv.last.chunks : null)) { return plain; }
		rt.note.textContent = __( 'Whisper is checking the retake’s words...', 'foxfire-studio' );
		/* Listening to a retake must not change what the review says about the take itself. */
		var keep = { usedModel: studio.usedModel, whisperListenS: studio.whisperListenS, whisperTimed: studio.whisperTimed, whisperLoadS: studio.whisperLoadS, engineFrom: studio.engineFrom };
		function restore() {
			studio.usedModel = keep.usedModel;
			studio.whisperListenS = keep.whisperListenS;
			studio.whisperTimed = keep.whisperTimed;
			studio.whisperLoadS = keep.whisperLoadS;
			studio.engineFrom = keep.engineFrom;
		}
		var timer = 0;
		var limit = new Promise(function (resolve) { timer = window.setTimeout(function () { resolve(null); }, 45000); });
		var heard = to16k(y, ED_RATE).then(function (a16) {
			return transcribeWith(a16, function () {}, studio.engineModel || wantModel());
		});
		return Promise.race([heard, limit]).then(function (chunks) {
			window.clearTimeout(timer);
			restore();
			var whole = { clip: y, note: '', a: 0, b: y.length };
			if (!chunks) { return whole; }
			var hw = heardWords(chunks).filter(function (w) { return w.nk; });
			if (!hw.length) { return whole; }
			/* The selected words as a run (0.25.1), so a false start before them is trimmed too. */
			var run = window.RRTEdit.fitRun(hw.map(function (w) { return w.nk; }), want);
			var i = run ? run.i : -1;
			var j = run ? run.j : -1;
			if (i < 0 || j < 0) {
				return { clip: y, a: 0, b: y.length, note: sprintf( /* translators: %s: hw.map(function (w) { return w.text; }).join(' '). */ __( 'Whisper heard “%s”, which does not quite match the selected words, so nothing was trimmed: listen before using it.', 'foxfire-studio' ), hw.map(function (w) { return w.text; }).join(' ') ) };
			}
			if (i === 0 ? j === hw.length - 1 : false) { return whole; }
			var edges = window.RRTEdit.speechEdges(hw, i, j, y, ED_RATE);
			var pad = 0.04;
			var a = Math.max(0, Math.round((edges.s - pad) * ED_RATE));
			var b = Math.min(y.length, Math.round((edges.e + pad) * ED_RATE));
			if (b - a < Math.round(0.2 * ED_RATE)) { return whole; }
			var cut = [];
			if (i > 0) { cut.push(sprintf( /* translators: %s: hw.slice(0, i).map(function (w) { return w.text; }).join(' '. */ __( '“%s” before', 'foxfire-studio' ), hw.slice(0, i).map(function (w) { return w.text; }).join(' ') )); }
			if (j < hw.length - 1) { cut.push(sprintf( /* translators: %s: hw.slice(j + 1).map(function (w) { return w.text; }).join(' . */ __( '“%s” after', 'foxfire-studio' ), hw.slice(j + 1).map(function (w) { return w.text; }).join(' ') )); }
			return { clip: y.slice(a, b), a: a, b: b, note: sprintf( /* translators: %s: cut.join(' ' + __( 'and', 'foxfire-studio' ) + ' '). */ __( 'Trimmed %s the selected words.', 'foxfire-studio' ), cut.join(' ' + __( 'and', 'foxfire-studio' ) + ' ') ) };
		}).catch(function () {
			window.clearTimeout(timer);
			restore();
			return { clip: y, note: '', a: 0, b: y.length };
		});
	}
	function rtEdit() {
		var r = { s: rt.plan.s, e: rt.plan.e, ins: rt.clip, o: rt.plan.o ? { s: rt.plan.o.s, e: rt.plan.o.e } : { s: rt.plan.s, e: rt.plan.e } };
		if (rt.plan.ps > 0) { r.ps = rt.plan.ps; }
		if (rt.plan.pe > 0) { r.pe = rt.plan.pe; }
		if (rt.plan.tone ? (rt.plan.ps > 0 || rt.plan.pe > 0) : false) { r.tone = rt.plan.tone; }
		if (rt.raw ? rt.trim : false) { r.raw = rt.raw; r.trim = { a: rt.trim.a, b: rt.trim.b, fa: rt.trim.fa, fb: rt.trim.fb }; }
		return r;
	}
	rt.recBtn.addEventListener('click', function () {
		if (rt.cap) { rtStop(); return; }
		/* Recording again keeps the edges and space as they stand; they apply to the new take too. */
		rt.edges.hidden = true;
		rt.space.hidden = true;
		rtStart();
	});
	rt.hear.addEventListener('click', function () {
		if (!rt.clip) { return; }
		edPreview(rtOthers().concat([rtEdit()]), rt.plan);
	});
	rt.use.addEventListener('click', function () {
		if (!rt.clip) { return; }
		var others = rtOthers();
		var r = rtEdit();
		rtClose();
		edSetCuts(others.concat([r]), r);
	});
	rt.cancel.addEventListener('click', rtClose);
	ed.retakeBtn.addEventListener('click', rtOpen);
})();
