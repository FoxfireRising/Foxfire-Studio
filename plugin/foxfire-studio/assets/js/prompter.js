/*
 * Foxfire Studio: prompter view (1.5.0), for a glass teleprompter rig or a
 * screen read from across the room. Full screen with only the script, which
 * can be mirrored left to right, upside down or both, so it reads the right
 * way round in the rig's glass. Prompter view keeps its own Size, Width and
 * Line, and adds Shift (the script over the lens), Fade above and below, and
 * bright text on black. Keys suit presentation clickers and foot pedals:
 * Page Down starts and pauses, Page Up goes back a sentence (both in
 * studio-core.js), B or the full stop blanks the screen, plus and minus
 * change the size, F5 is caught so a clicker cannot reload the page, and
 * Escape leaves. Everything the reader sees is mirrored; the dock and the
 * Controls panel, which only the operator reads, never are.
 *
 * Loads after takes.js. The pure helpers at the top (window.RRTPrompter)
 * are also used by the unit tests in Node.
 */
(function (root) {
	'use strict';

	var P = {};

	P.MIRRORS = ['none', 'h', 'v', 'hv'];
	P.DEFAULTS = { size: 56, cols: 16, line: 30, mirror: 'none', shift: 0, fade: 0, bright: false };

	function num(v, lo, hi, d) {
		v = typeof v === 'number' ? v : parseFloat(v);
		if (!isFinite(v)) { return d; }
		return Math.max(lo, Math.min(hi, Math.round(v)));
	}

	/* Stored prompter settings, checked and filled in with the defaults. */
	P.clean = function (o) {
		o = o && typeof o === 'object' ? o : {};
		var D = P.DEFAULTS;
		return {
			size: num(o.size, 24, 96, D.size),
			cols: num(o.cols, 10, 30, D.cols),
			line: num(o.line, 18, 50, D.line),
			mirror: P.MIRRORS.indexOf(o.mirror) !== -1 ? o.mirror : D.mirror,
			shift: num(o.shift, -30, 30, D.shift),
			fade: num(o.fade, 0, 40, D.fade),
			bright: o.bright === true
		};
	};

	/* The CSS transform that mirrors what the reader sees. */
	P.transform = function (mirror) {
		if (mirror === 'h') { return 'scale(-1, 1)'; }
		if (mirror === 'v') { return 'scale(1, -1)'; }
		if (mirror === 'hv') { return 'scale(-1, -1)'; }
		return 'none';
	};

	/*
	 * The stage's mask for Fade above and below: full strength in a band
	 * around the reading line (line, in % from the top), dimmed to 15% beyond
	 * it over a soft edge a tenth of the screen tall. '' leaves the usual mask.
	 */
	P.mask = function (line, fade) {
		fade = num(fade, 0, 40, 0);
		if (!fade) { return ''; }
		var half = 50 - fade;
		var r = function (v) { return Math.max(0, Math.min(100, Math.round(v * 10) / 10)) + '%'; };
		var dim = 'rgba(0, 0, 0, 0.15)';
		return 'linear-gradient(180deg, ' + dim + ' ' + r(line - half - 10) + ', #000 ' + r(line - half) + ', #000 ' + r(line + half) + ', ' + dim + ' ' + r(line + half + 10) + ')';
	};

	/* Keys that belong to prompter view: what each does, or '' for one it leaves alone. */
	P.keyAction = function (key, inView) {
		if (key === 'p' || key === 'P') { return 'toggle'; }
		if (!inView) { return ''; }
		if (key === 'Escape') { return 'leave'; }
		if (key === 'b' || key === 'B' || key === '.') { return 'blank'; }
		if (key === '+' || key === '=') { return 'bigger'; }
		if (key === '-' || key === '_') { return 'smaller'; }
		if (key === 'F5') { return 'swallow'; }
		return '';
	};

	root.RRTPrompter = P;

	/*
	 * ---------------------------------------------------------------------
	 * The studio part
	 * ---------------------------------------------------------------------
	 */
	var studio = root.RRTStudio;
	if (!studio || typeof document === 'undefined' || !document.getElementById) { return; }
	var openBtn = document.getElementById('tpPvOpen');
	if (!openBtn) { return; }

	var rrtI18n = (typeof wp === 'object' ? (wp ? wp.i18n : null) : null) || null;
	var __ = rrtI18n ? rrtI18n.__ : function (s) { return s; };

	var body = document.body;
	var stage = document.getElementById('tpStage');
	var sizeIn = document.getElementById('tpSize');
	var colsIn = document.getElementById('tpCols');
	var lineIn = document.getElementById('tpLine');
	var mirrorIn = document.getElementById('tpPvMirror');
	var shiftIn = document.getElementById('tpPvShift');
	var shiftVal = document.getElementById('tpPvShiftVal');
	var fadeIn = document.getElementById('tpPvFade');
	var fadeVal = document.getElementById('tpPvFadeVal');
	var brightIn = document.getElementById('tpPvBright');
	var panelBtn = document.getElementById('tpPvPanel');
	var leaveBtn = document.getElementById('tpPvLeave');
	var strip = document.getElementById('tpPvStrip');
	var stripRec = document.getElementById('tpPvRec');
	var stripRecTime = document.getElementById('tpPvRecTime');
	var stripLeft = document.getElementById('tpPvLeft');
	var stripMsg = document.getElementById('tpPvMsg');
	var recEl = document.getElementById('tpRec');
	var recTimeEl = document.getElementById('tpRecTime');
	var leftEl = document.getElementById('tpLeft');
	var msgEl = document.getElementById('tpMsg');
	var resultEl = document.getElementById('tpResult');
	var introEl = document.getElementById('tpIntro');

	var KEY = 'rrtPrompter';
	var pv = P.DEFAULTS;
	try { pv = P.clean(JSON.parse(window.localStorage.getItem(KEY) || '{}')); } catch (e) { pv = P.clean({}); }
	function store() {
		try { window.localStorage.setItem(KEY, JSON.stringify(pv)); } catch (e) {}
	}

	var on = false;
	var normal = null;      /* The normal view's Size, Width and Line while prompter view is open. */
	var panelWasClosed = false;
	var wentFull = false;
	var wake = null;
	var watch = 0;

	function setSlider(inp, v) {
		inp.value = String(v);
		inp.dispatchEvent(new Event('input', { bubbles: true }));
	}

	function paintValues() {
		shiftVal.textContent = (pv.shift > 0 ? '+' : '') + pv.shift + '%';
		fadeVal.textContent = pv.fade ? pv.fade + '%' : __( 'Off', 'foxfire-studio' );
	}

	/* The look of prompter view: mirror, shift, fade and bright text. */
	function applyView() {
		var t = P.transform(pv.mirror);
		body.classList.toggle('pv-mh', pv.mirror === 'h' || pv.mirror === 'hv');
		body.classList.toggle('pv-mv', pv.mirror === 'v' || pv.mirror === 'hv');
		body.classList.toggle('pv-bright', pv.bright);
		document.documentElement.style.setProperty('--pvShift', pv.shift + '%');
		document.documentElement.style.setProperty('--pvMirror', t);
		var m = on ? P.mask(pv.line, pv.fade) : '';
		stage.style.webkitMaskImage = m;
		stage.style.maskImage = m;
		paintValues();
	}

	/* While prompter view is open, saving keeps its own Size, Width and Line and writes the normal view's. */
	function keep(settings) {
		pv.size = settings.size;
		pv.cols = settings.cols;
		pv.line = settings.line;
		store();
		applyView();
		var out = {};
		var k;
		for (k in settings) { if (Object.prototype.hasOwnProperty.call(settings, k)) { out[k] = settings[k]; } }
		out.size = normal.size;
		out.cols = normal.cols;
		out.line = normal.line;
		return out;
	}

	/* Whether something else has the screen: a review, a result, a menu or a panel of its own. */
	function busy() {
		if (studio.cueMenu) { return true; }
		if (studio.rv ? studio.rv.open : false) { return true; }
		if (resultEl ? resultEl.classList.contains('is-on') : false) { return true; }
		if (introEl ? !introEl.hidden : false) { return true; }
		return !!document.querySelector('.tp-warm, .tp-mic, .tp-share');
	}

	function lock() {
		if (!navigator.wakeLock || document.visibilityState !== 'visible') { return; }
		navigator.wakeLock.request('screen').then(function (l) {
			if (on) { wake = l; } else { l.release(); }
		}).catch(function () {});
	}
	function unlock() {
		if (wake) { wake.release().catch(function () {}); wake = null; }
	}

	/* The mirrored strip repeats the dock's recording time, time left and message. */
	function copyStrip() {
		stripRec.hidden = !recEl.classList.contains('is-on');
		stripRec.classList.toggle('is-paused', recEl.classList.contains('is-paused'));
		stripRecTime.textContent = recTimeEl.textContent;
		stripLeft.textContent = leftEl.textContent;
		stripMsg.textContent = msgEl.textContent;
	}
	if (window.MutationObserver) {
		var mo = new MutationObserver(function () { if (on) { copyStrip(); } });
		[recEl, recTimeEl, leftEl, msgEl].forEach(function (n) {
			if (n) { mo.observe(n, { attributes: true, attributeFilter: ['class'], childList: true, characterData: true, subtree: true }); }
		});
	}

	function enter() {
		if (on || busy()) { return; }
		on = true;
		var s = studio.settings;
		normal = { size: s.size, cols: s.cols, line: s.line };
		studio.pvKeep = keep;
		panelWasClosed = body.classList.contains('panel-closed');
		body.classList.add('panel-closed');
		body.classList.add('is-prompter');
		body.classList.remove('pv-blank');
		/* Into the studio's settings first, so each slider's save keeps all three of prompter view's values. */
		s.size = pv.size;
		s.cols = pv.cols;
		s.line = pv.line;
		setSlider(sizeIn, pv.size);
		setSlider(colsIn, pv.cols);
		setSlider(lineIn, pv.line);
		applyView();
		copyStrip();
		openBtn.textContent = __( 'Leave prompter view', 'foxfire-studio' );
		wentFull = false;
		var de = document.documentElement;
		if (de.requestFullscreen && !document.fullscreenElement) {
			de.requestFullscreen({ navigationUI: 'hide' }).then(function () { wentFull = true; }).catch(function () {});
		}
		lock();
		studio.measure();
		window.dispatchEvent(new Event('resize'));
		/* A review or result is for the operator at the computer: prompter view steps aside for it. */
		watch = window.setInterval(function () { if (busy() && !studio.cueMenu) { leave(); } }, 400);
		/* Focus goes to the script, so its keys work at once and Tab can still reach the dock's buttons. */
		studio.toScript();
	}

	function leave() {
		if (!on) { return; }
		on = false;
		window.clearInterval(watch);
		studio.pvKeep = null;
		body.classList.remove('is-prompter', 'pv-blank');
		if (!panelWasClosed) { body.classList.remove('panel-closed'); }
		studio.settings.size = normal.size;
		studio.settings.cols = normal.cols;
		studio.settings.line = normal.line;
		setSlider(sizeIn, normal.size);
		setSlider(colsIn, normal.cols);
		setSlider(lineIn, normal.line);
		normal = null;
		applyView();
		openBtn.textContent = __( 'Open prompter view', 'foxfire-studio' );
		if (document.fullscreenElement && document.exitFullscreen) { document.exitFullscreen().catch(function () {}); }
		wentFull = false;
		unlock();
		studio.measure();
		window.dispatchEvent(new Event('resize'));
	}

	studio.prompterOn = function () { return on; };
	studio.prompterEnter = enter;
	studio.prompterLeave = leave;

	openBtn.addEventListener('click', function () { if (on) { leave(); } else { enter(); } });
	leaveBtn.addEventListener('click', leave);
	panelBtn.addEventListener('click', function () {
		body.classList.toggle('panel-closed');
		window.dispatchEvent(new Event('resize'));
	});
	document.addEventListener('fullscreenchange', function () {
		if (on && wentFull && !document.fullscreenElement) { leave(); }
	});
	document.addEventListener('visibilitychange', function () { if (on && !wake) { lock(); } });

	mirrorIn.value = pv.mirror;
	shiftIn.value = String(pv.shift);
	fadeIn.value = String(pv.fade);
	brightIn.checked = pv.bright;
	mirrorIn.addEventListener('change', function () { pv.mirror = P.clean({ mirror: mirrorIn.value }).mirror; store(); applyView(); });
	shiftIn.addEventListener('input', function () { pv.shift = num(shiftIn.value, -30, 30, 0); store(); applyView(); });
	fadeIn.addEventListener('input', function () { pv.fade = num(fadeIn.value, 0, 40, 0); store(); applyView(); });
	brightIn.addEventListener('change', function () { pv.bright = brightIn.checked; store(); applyView(); });
	applyView();

	/* Runs before the studio's own keys, so a clicker's F5 and the blank key never reach anything else. */
	document.addEventListener('keydown', function (e) {
		if (e.ctrlKey || e.metaKey || e.altKey) { return; }
		var t = e.target;
		if (t && (t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || (t.tagName === 'INPUT' && t.type !== 'range' && t.type !== 'checkbox'))) { return; }
		var a = P.keyAction(e.key, on);
		if (!a) { return; }
		/* Single-key shortcuts (1.27.0) act only on the script, and only while the Single-key shortcuts switch is on. */
		if (e.key.length === 1 ? (!studio.keysOn() || !studio.onScript(t)) : false) { return; }
		if (a === 'swallow') { e.preventDefault(); e.stopPropagation(); return; }
		if (busy()) { return; }
		e.preventDefault();
		e.stopPropagation();
		if (a === 'toggle') { if (on) { leave(); } else { enter(); } }
		else if (a === 'leave') { leave(); }
		else if (a === 'blank') { body.classList.toggle('pv-blank'); }
		else if (a === 'bigger') { setSlider(sizeIn, Math.min(96, studio.settings.size + 4)); }
		else if (a === 'smaller') { setSlider(sizeIn, Math.max(24, studio.settings.size - 4)); }
	}, true);
})(typeof window !== 'undefined' ? window : this);
