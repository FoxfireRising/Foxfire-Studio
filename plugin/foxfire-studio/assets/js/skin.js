/*
 * Foxfire Studio: the studio look (0.18.0).
 *
 * Only what the new layout adds: the fold-away Controls panel, the values
 * beside the sliders, the state shown beside the post menu, the live level
 * waves either side of the Begin button, and the "Your last take" drawer.
 * The teleprompter itself (teleprompter.js) is unchanged and knows nothing
 * of this file; everything here watches the page and reads what it shows.
 */
(function () {
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

	var RRT = window.RRT || {};
	var body = document.body;
	var $ = function (id) { return document.getElementById(id); };
	var store = {
		get: function (k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } },
		set: function (k, v) { try { window.localStorage.setItem(k, v); } catch (e) {} }
	};

	/* The heights of the menus and the dock, so the script fits exactly between them on any screen. */
	var root = document.documentElement;
	var topEl = document.querySelector('.tp-top');
	var scriptEl = $('tpScriptBar');
	var dockEl = document.querySelector('.tp-dock');
	function measureBars() {
		var t = topEl ? topEl.offsetHeight : 0;
		var s = scriptEl ? scriptEl.offsetHeight : 0;
		var d = dockEl ? dockEl.offsetHeight : 0;
		root.style.setProperty('--tp-top-h', t + 'px');
		root.style.setProperty('--tp-head-h', (t + s) + 'px');
		root.style.setProperty('--tp-dock-h', d + 'px');
	}
	measureBars();
	if (window.ResizeObserver) {
		var ro = new ResizeObserver(function () {
			measureBars();
			window.dispatchEvent(new Event('resize'));
		});
		[topEl, scriptEl, dockEl].forEach(function (e) { if (e) { ro.observe(e); } });
	}

	/* Sliders fill with gold up to the thumb (and catch up after the arrow keys change the pace). */
	var refills = [];
	document.addEventListener('keyup', function () { refills.forEach(function (f) { f(); }); });
	Array.prototype.forEach.call(document.querySelectorAll('.tp-panel input[type="range"]'), function (r) {
		var fill = function () {
			var min = parseFloat(r.min) || 0;
			var max = parseFloat(r.max) || 100;
			r.style.setProperty('--fill', (((parseFloat(r.value) - min) / (max - min)) * 100) + '%');
		};
		r.addEventListener('input', fill);
		r.addEventListener('change', fill);
		refills.push(fill);
		fill();
	});

	/* Controls panel: open or folded away, remembered in this browser. Small screens start folded. */
	var PANEL_KEY = 'rrtPanel';
	var panel = $('tpPanel');
	var toggle = $('tpPanelToggle');
	var narrow = window.matchMedia('(max-width: 900px)');
	function setPanel(open, remember) {
		body.classList.toggle('panel-closed', !open);
		if (toggle) { toggle.setAttribute('aria-expanded', open ? 'true' : 'false'); }
		if (remember) { store.set(PANEL_KEY, open ? 'open' : 'closed'); }
		/* The script's width changes with the panel; let the teleprompter measure again. */
		window.dispatchEvent(new Event('resize'));
	}
	if (panel) {
		var saved = store.get(PANEL_KEY);
		setPanel(narrow.matches ? false : saved !== 'closed', false);
		if (toggle) {
			toggle.addEventListener('click', function () {
				setPanel(body.classList.contains('panel-closed'), !narrow.matches);
				toggle.blur();
			});
		}
		var closeBtn = $('tpPanelClose');
		if (closeBtn) { closeBtn.addEventListener('click', function () { setPanel(false, !narrow.matches); }); }
		narrow.addEventListener('change', function (e) { setPanel(e.matches ? false : store.get(PANEL_KEY) !== 'closed', false); });
	}

	/* Values beside the Size and Line sliders (Pace has its own, kept by the teleprompter). */
	function showVal(inputId, outId, unit) {
		var inp = $(inputId);
		var out = $(outId);
		if (!inp || !out) { return; }
		var paint = function () { out.textContent = inp.value + unit; };
		inp.addEventListener('input', paint);
		inp.addEventListener('change', paint);
		paint();
	}
	showVal('tpSize', 'tpSizeVal', ' px');
	showVal('tpLine', 'tpLineVal', '%');

	/* The state beside the post menu: ready, reading, following your voice, recording, paused or finished. */
	var stateEl = $('tpState');
	var playBtn = $('tpPlay');
	var recEl = $('tpRec');
	var hasScript = !!$('tpTrack');
	function paintState() {
		if (!stateEl) { return; }
		var t = '';
		var k = '';
		if (hasScript) {
			var label = playBtn ? playBtn.textContent : '';
			var rec = recEl ? recEl.classList.contains('is-on') : false;
			var recPaused = rec ? recEl.classList.contains('is-paused') : false;
			if (rec) {
				t = recPaused ? __( 'Recording paused', 'foxfire-studio' ) : __( 'Recording', 'foxfire-studio' );
				k = recPaused ? 'paused' : 'rec';
			} else if (body.classList.contains('is-playing')) {
				t = body.classList.contains('is-voice') ? __( 'Following your voice', 'foxfire-studio' ) : __( 'Reading', 'foxfire-studio' );
				k = 'live';
			} else if (label === 'Resume') {
				t = __( 'Paused', 'foxfire-studio' );
				k = 'paused';
			} else if (label === 'Again') {
				t = __( 'Finished', 'foxfire-studio' );
				k = 'done';
			} else {
				t = __( 'Ready to read', 'foxfire-studio' );
				k = 'ready';
			}
		}
		stateEl.textContent = t;
		stateEl.setAttribute('data-state', k);
	}
	var watch = new MutationObserver(function () { paintState(); });
	watch.observe(body, { attributes: true, attributeFilter: ['class'] });
	if (recEl) { watch.observe(recEl, { attributes: true, attributeFilter: ['class'] }); }
	if (playBtn) { watch.observe(playBtn, { childList: true, characterData: true, subtree: true }); }
	paintState();

	/*
	 * Level waves either side of the Begin button. While recording they show
	 * the microphone level of the last few seconds, read from the teleprompter's
	 * own level meter; the rest of the time they lie flat.
	 */
	var BARS = 26;
	var waves = [$('tpWaveL'), $('tpWaveR')].filter(Boolean);
	var meter = $('tpMeter');
	var hist = [];
	waves.forEach(function (w) {
		for (var i = 0; i < BARS; i++) { w.appendChild(document.createElement('i')); }
	});
	function paintWaves() {
		waves.forEach(function (w, side) {
			var bars = w.children;
			for (var i = 0; i < BARS; i++) {
				/* Newest level beside the button on both sides. */
				var idx = side === 0 ? hist.length - BARS + i : hist.length - 1 - i;
				var v = idx >= 0 ? (hist[idx] || 0) : 0;
				bars[i].style.transform = 'scaleY(' + Math.max(0.06, Math.min(1, v)).toFixed(3) + ')';
			}
		});
	}
	var waveTimer = 0;
	function waveTick() {
		var rec = recEl ? recEl.classList.contains('is-on') ? !recEl.classList.contains('is-paused') : false : false;
		if (rec) {
			var pct = meter ? parseFloat(meter.style.width) || 0 : 0;
			hist.push(Math.pow(pct / 100, 0.7));
			if (hist.length > BARS) { hist.shift(); }
			body.classList.add('is-hearing');
		} else if (hist.length) {
			hist = [];
			body.classList.remove('is-hearing');
		}
		paintWaves();
	}
	if (waves.length) {
		paintWaves();
		waveTimer = window.setInterval(waveTick, 90);
		document.addEventListener('visibilitychange', function () {
			window.clearInterval(waveTimer);
			if (!document.hidden) { waveTimer = window.setInterval(waveTick, 90); }
		});
	}

	/* "Your last take": this post's latest full reading (not a drill), with the take before it for comparison. */
	var last = $('tpLast');
	var lastBtn = $('tpLastBtn');
	var STATS = RRT.stats || null;
	var GOAL_KEYS = { wpm: 'wpm', accuracy: 'acc', fillers: 'filler', long_pauses: 'pauses', retakes: 'retake', ending_drop: 'endDrop', pitch: 'pitch', energy: 'energy', room: 'snr' };
	var TILES = [
		{ key: 'wpm', label: __( 'Pace', 'foxfire-studio' ), unit: ' wpm', better: 0 },
		{ key: 'acc', label: __( 'Accuracy', 'foxfire-studio' ), unit: '%', better: 1 },
		{ key: 'filler', label: __( 'Fillers', 'foxfire-studio' ), unit: '', better: -1 },
		{ key: 'pauses', label: __( 'Long pauses', 'foxfire-studio' ), unit: '', better: -1 },
		{ key: 'endDrop', label: __( 'Ending fade', 'foxfire-studio' ), unit: ' dB', better: -1 }
	];
	function el(tag, cls, text) {
		var e = document.createElement(tag);
		if (cls) { e.className = cls; }
		if (text !== undefined) { e.textContent = text; }
		return e;
	}
	function num(v) { return typeof v === 'number' ? isFinite(v) : false; }
	function fmtSecs(s) {
		s = Math.round(s);
		return Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2);
	}
	function setLast(open) {
		if (!last) { return; }
		last.hidden = !open;
		body.classList.toggle('last-open', open);
		if (lastBtn) { lastBtn.setAttribute('aria-expanded', open ? 'true' : 'false'); }
	}
	function paintLast(takes) {
		var full = takes.filter(function (t) { return !t.drill; });
		var cur = full[full.length - 1];
		var prev = full.length > 1 ? full[full.length - 2] : null;
		if (!cur) {
			if (lastBtn) { lastBtn.hidden = true; }
			setLast(false);
			return;
		}
		if (lastBtn) { lastBtn.hidden = false; }
		var when = [];
		if (num(cur.at)) {
			when.push(new Date(cur.at).toLocaleString(undefined, { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' }));
		}
		if (num(cur.secs)) { when.push(sprintf( /* translators: %s: fmtSecs(cur.secs). */ __( '%s long', 'foxfire-studio' ), fmtSecs(cur.secs) )); }
		if (cur.mode === 'voice') { when.push(__( 'followed your voice', 'foxfire-studio' )); }
		else if (num(cur.setWpm)) { when.push(sprintf( /* translators: %s: cur.setWpm. */ __( 'timed at %s wpm', 'foxfire-studio' ), cur.setWpm )); }
		if (cur.model) { when.push(/small/.test(cur.model) ? __( 'larger Whisper model', 'foxfire-studio' ) : __( 'standard Whisper model', 'foxfire-studio' )); }
		$('tpLastWhen').textContent = when.join(' · ');

		var tiles = $('tpLastTiles');
		tiles.textContent = '';
		TILES.forEach(function (f) {
			var v = cur[f.key];
			if (!num(v)) { return; }
			var tile = el('div', 'tp-last-tile');
			tile.appendChild(el('span', 'tp-last-value', String(v) + f.unit));
			tile.appendChild(el('span', 'tp-last-label', f.label));
			var p = prev ? prev[f.key] : null;
			if (num(p)) {
				var d = Math.round((v - p) * 10) / 10;
				var good = f.better === 0 || d === 0 ? null : d * f.better > 0;
				var txt = d === 0 ? __( 'Same as the take before', 'foxfire-studio' ) : sprintf( /* translators: %s: (d > 0 ? '↑ ' : '↓ ') + Math.abs(d) + f.unit. */ __( '%s on the take before', 'foxfire-studio' ), (d > 0 ? '↑ ' : '↓ ') + Math.abs(d) + f.unit );
				tile.appendChild(el('span', 'tp-last-delta' + (good === null ? '' : (good ? ' is-good' : ' is-bad')), txt));
			}
			tiles.appendChild(tile);
		});
		if (!tiles.children.length) {
			tiles.appendChild(el('p', 'tp-last-none', __( 'This take was saved without the Whisper review, so it has no numbers to show. Keep Whisper review ticked to see them here.', 'foxfire-studio' )));
		}

		var goals = $('tpLastGoals');
		goals.textContent = '';
		(STATS ? (STATS.goals || []) : []).forEach(function (g) {
			var v = cur[GOAL_KEYS[g.metric]];
			if (!num(v)) { return; }
			var ok = true;
			if (g.min !== null ? v < g.min : false) { ok = false; }
			if (g.max !== null ? v > g.max : false) { ok = false; }
			var li = el('li', ok ? 'is-met' : 'is-missed');
			li.appendChild(el('span', 'tp-last-mark', ok ? '✓' : '✗'));
			li.appendChild(el('span', '', sprintf( /* translators: 1: g.text, 2: v. */ __( '%1$s (this take: %2$s)', 'foxfire-studio' ), g.text, v )));
			goals.appendChild(li);
		});
	}
	function loadLast() {
		if (!last || !STATS || !RRT.postId) { return; }
		/* Sites with plain permalinks give an address that already has a query string. */
		var url = sprintf( /* translators: 1: STATS.rest, 2: STATS.rest.indexOf('?') < 0 ? '?' : '&', 3: RRT.postId. */ __( '%1$stakes%2$spost=%3$s', 'foxfire-studio' ), STATS.rest, STATS.rest.indexOf('?') < 0 ? '?' : '&', RRT.postId );
		window.fetch(url, { credentials: 'same-origin', headers: { 'X-WP-Nonce': STATS.nonce } })
			.then(function (r) { return r.ok ? r.json() : null; })
			.then(function (j) { if (j ? Array.isArray(j.takes) : false) { paintLast(j.takes); } })
			.catch(function () {});
	}
	if (last) {
		loadLast();
		if (lastBtn) { lastBtn.addEventListener('click', function () { setLast(last.hidden); }); }
		$('tpLastClose').addEventListener('click', function () { setLast(false); if (lastBtn) { lastBtn.focus(); } });
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' ? !last.hidden : false) { setLast(false); }
		});
		/* Reading again folds it away; a new take is fetched once the review closes. */
		var wasReviewing = false;
		new MutationObserver(function () {
			if (body.classList.contains('is-playing')) { setLast(false); }
			var rv = body.classList.contains('is-reviewing');
			if (wasReviewing ? !rv : false) { window.setTimeout(loadLast, 1500); }
			wasReviewing = rv;
		}).observe(body, { attributes: true, attributeFilter: ['class'] });
		/* A take saved without opening the review (the reading's panel closes instead). */
		var dismiss = $('tpDismiss');
		if (dismiss) { dismiss.addEventListener('click', function () { window.setTimeout(loadLast, 1500); }); }
	}
}());
