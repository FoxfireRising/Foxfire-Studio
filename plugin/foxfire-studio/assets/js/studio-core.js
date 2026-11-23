/*
 * Foxfire Studio: the studio's core. Reads window.RRT (see includes/render.php) and the
 * page's controls, the remembered settings, choosing a post, the intro, the
 * script as word spans, timing, highlighting, the animation loop, the screen
 * wake lock, play, pause and the countdown, the opening drill, the keys, and
 * the microphone check and warm-up buttons. Makes window.RRTStudio, the state
 * the studio's files share; docs/ARCHITECTURE.md maps them. Moved from
 * teleprompter.js in 0.25.2.
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
	var studio = window.RRTStudio = {};
	studio.compareReading = compareReading;
	studio.applyLook = applyLook;
	studio.fmtClock = fmtClock;
	studio.voiceMode = voiceMode;
	studio.measure = measure;
	studio.target = target;
	studio.markAll = markAll;
	studio.advance = advance;
	studio.setCursor = setCursor;
	studio.onModeChange = onModeChange;
	studio.fmt = fmt;
	studio.status = status;
	studio.frame = frame;
	studio.setPlayLabel = setPlayLabel;
	studio.togglePlay = togglePlay;
	studio.jumpWord = jumpWord;
	studio.beginFrom = beginFrom;
	studio.saveSettings = saveSettings;
	var RRT = window.RRT || {};
	studio.RRT = RRT;
	/* Engines kept in their own files (0.25.1), loaded before this one. */
	var cleanReading = window.RRTClean.cleanReading;
	studio.cleanReading = cleanReading;
	var analyseReading = window.RRTReadout.analyseReading;
	studio.analyseReading = analyseReading;
	var describeReading = window.RRTReadout.describeReading;
	studio.describeReading = describeReading;
	/* The reading language (1.33.0): set first, so every word below is cleaned the way the review compares it. */
	var READ_LANG = RRT.lang || { code: 'en', name: 'English', tag: 'en', en: true };
	studio.READ_LANG = READ_LANG;
	window.RRTCompare.setLang(READ_LANG);
	var clean = window.RRTCompare.clean;
	studio.clean = clean;
	var similar = window.RRTCompare.similar;
	studio.similar = similar;
	var STOP = window.RRTCompare.STOP;
	studio.STOP = STOP;
	var norm = window.RRTCompare.norm;
	studio.norm = norm;
	var heardWords = window.RRTCompare.heardWords;
	studio.heardWords = heardWords;
	function compareReading(script, chunks, frames, learned) {
		return window.RRTCompare.compareReading(script, chunks, frames, learned, studio.cueOf, studio.kindOpts ? studio.kindOpts() : null);
	}
	var pageUrl = RRT.pageUrl || window.location.pathname;
	studio.pageUrl = pageUrl;
	var body = document.body;
	studio.body = body;
	var root = document.documentElement;
	var stage = document.getElementById('tpStage');
	var track = document.getElementById('tpTrack');
	studio.track = track;
	var pick = document.getElementById('tpPick');
	studio.pick = pick;
	var urlIn = document.getElementById('tpUrl');
	var loadBtn = document.getElementById('tpLoad');
	var voiceWrap = document.getElementById('tpVoiceWrap');
	var voiceIn = document.getElementById('tpVoice');
	studio.voiceIn = voiceIn;
	var wpmIn = document.getElementById('tpWpm');
	var wpmVal = document.getElementById('tpWpmVal');
	var sizeIn = document.getElementById('tpSize');
	var colsIn = document.getElementById('tpCols');
	var lineIn = document.getElementById('tpLine');
	var breathIn = document.getElementById('tpBreath');
	var keysIn = document.getElementById('tpKeys');
	var recOpt = document.getElementById('tpRecOpt');
	var playBtn = document.getElementById('tpPlay');
	studio.playBtn = playBtn;
	var restartBtn = document.getElementById('tpRestart');
	var finishBtn = document.getElementById('tpFinish');
	studio.finishBtn = finishBtn;
	var countEl = document.getElementById('tpCount');
	var progEl = document.getElementById('tpProgress');
	var whereEl = document.getElementById('tpWhere');
	var leftEl = document.getElementById('tpLeft');
	var heardEl = document.getElementById('tpHeard');
	studio.heardEl = heardEl;
	var recEl = document.getElementById('tpRec');
	studio.recEl = recEl;
	var recTimeEl = document.getElementById('tpRecTime');
	studio.recTimeEl = recTimeEl;
	var meterEl = document.getElementById('tpMeter');
	studio.meterEl = meterEl;
	var msgEl = document.getElementById('tpMsg');
	studio.msgEl = msgEl;
	var resultEl = document.getElementById('tpResult');
	studio.resultEl = resultEl;
	var resultAudio = document.getElementById('tpAudio');
	studio.resultAudio = resultAudio;
	var resultInfo = document.getElementById('tpResultInfo');
	studio.resultInfo = resultInfo;
	var downloadA = document.getElementById('tpDownload');
	studio.downloadA = downloadA;
	var downloadRawA = document.getElementById('tpDownloadRaw');
	studio.downloadRawA = downloadRawA;
	var dismissBtn = document.getElementById('tpDismiss');
	studio.dismissBtn = dismissBtn;
	var readoutEl = document.getElementById('tpReadout');
	studio.readoutEl = readoutEl;
	var whisperIn = document.getElementById('tpWhisperOpt');
	var cleanIn = document.getElementById('tpCleanOpt');
	var whisperStatus = document.getElementById('tpWhisperStatus');
	studio.whisperStatus = whisperStatus;
	var openReviewBtn = document.getElementById('tpOpenReview');
	studio.openReviewBtn = openReviewBtn;
	var reviewFileBtn = document.getElementById('tpReviewFile');
	studio.reviewFileBtn = reviewFileBtn;
	var fileIn = document.getElementById('tpFile');
	studio.fileIn = fileIn;
	/* Practice tools (0.14.0): microphone check, opening drill and practice focus. */
	var PR = window.RRTPractice || null;
	studio.PR = PR;
	var micBtn = document.getElementById('tpMicCheck');
	var micLast = document.getElementById('tpMicLast');
	var drillIn = document.getElementById('tpDrill');
	var drillSecsIn = document.getElementById('tpDrillSecs');
	var focusIn = document.getElementById('tpFocus');
	/* Studio upgrades (0.15.0): font switch, warm-up and cue marks. */
	var fontIn = document.getElementById('tpFont');
	var warmBtn = document.getElementById('tpWarm');
	var cuesIn = document.getElementById('tpCues');
	studio.cuesIn = cuesIn;
	var FONTS = { serif: true, sans: true, system: true };
	/* The larger Whisper model (0.16.0), chosen per browser beside Whisper review. */
	var wmodelIn = document.getElementById('tpWModel');
	var POST_ID = RRT.postId || 0;
	studio.POST_ID = POST_ID;
	var WHISPER = RRT.whisper || { model: 'onnx-community/whisper-base.en_timestamped', fallback: 'Xenova/whisper-base.en', host: '', lib: '', wasm: '' };
	studio.WHISPER = WHISPER;
	var SITE = RRT.siteName || window.location.hostname;
	studio.SITE = SITE;

	/*
	 * Voice following (1.14.0) runs a small speech model on this device in a
	 * worker (follow-worker.js), so it needs workers, WebAssembly and the
	 * audio worklet that records takes. Until 1.13.0 it used the browser's
	 * own speech recognition, which in most browsers sends the audio away.
	 */
	var SR = (window.Worker && window.WebAssembly && window.AudioWorkletNode && RRT.follow && window.RRTFollow) ? true : null;
	/* 1.33.0: voice following's model hears English only, so a reading in another language scrolls as with the switch off (the reader's choice is kept for English readings). */
	var VOICE_LATER = SR ? !READ_LANG.en : false;
	if (VOICE_LATER) { SR = null; }
	studio.SR = SR;
	studio.VOICE_LATER = VOICE_LATER;
	var LANG = root.getAttribute('lang') || 'en-US';
	studio.LANG = LANG;

	/* Remembered settings */
	/*
	 * Browser storage. Earlier versions used "gw" names; anything saved under
	 * those is copied to the new names once, so nothing is lost on upgrade.
	 */
	var KEY = 'rrtTeleprompter';
	(function migrateStorage() {
		try {
			var ls = window.localStorage;
			if (ls.getItem('rrtMigrated')) { return; }
			var pairs = [['gwTeleprompter', 'rrtTeleprompter'], ['gwTpLearned', 'rrtLearned']];
			var k;
			for (k = 0; k < ls.length; k++) {
				var name = ls.key(k);
				if (name && name.indexOf('gwTpTakes_') === 0) { pairs.push([name, 'rrtTakes_' + name.slice(10)]); }
			}
			pairs.forEach(function (p) {
				var v = ls.getItem(p[0]);
				if (v !== null && ls.getItem(p[1]) === null) { ls.setItem(p[1], v); }
			});
			ls.setItem('rrtMigrated', '1');
		} catch (e) {}
	})();
	var D = RRT.defaults || {};
	var settings = {
		wpm: D.wpm || 140, size: D.size || 40, cols: D.cols || 18, line: D.line || 30,
		breath: D.breath !== false, rec: !!D.rec, voice: false, whisper: D.whisper !== false, clean: D.clean !== false,
		drill: false, drillSecs: 30, focus: '', font: 'serif',
		/*
		 * Whisper's model (1.17.0): 'auto' (the larger model on the graphics chip
		 * where it can run, else the standard one), 'base' or 'small'. wm2 marks
		 * a choice saved since 1.17.0: before, 'base' was saved by every browser
		 * as the default, so only a saved 'small' was a real choice.
		 */
		wmodel: 'auto', wm2: true,
		/* Phone take mode (1.12.0), on for phones unless switched off in this browser. */
		phoneTake: true,
		/* Single-key shortcuts (1.27.0): P, B, the full stop, plus, minus, [ and ]. */
		keys: true,
		/*
		 * 1.30.0: the larger model ran slower than the take on this browser's
		 * graphics chip, so Automatic uses the standard model here. Cleared by
		 * choosing Automatic again in the Model menu.
		 */
		gpuSlow: false
	};
	studio.settings = settings;
	try {
		var saved = JSON.parse(window.localStorage.getItem(KEY) || '{}');
		['wpm', 'size', 'cols', 'line'].forEach(function (k) { if (typeof saved[k] === 'number') { settings[k] = saved[k]; } });
		['breath', 'rec', 'voice', 'whisper', 'clean', 'drill', 'phoneTake', 'keys', 'gpuSlow'].forEach(function (k) { if (typeof saved[k] === 'boolean') { settings[k] = saved[k]; } });
		if ([20, 30, 45, 60].indexOf(saved.drillSecs) !== -1) { settings.drillSecs = saved.drillSecs; }
		if (typeof saved.focus === 'string') { settings.focus = saved.focus; }
		if (FONTS[saved.font] === true) { settings.font = saved.font; }
		if (saved.wm2 === true ? ['auto', 'base', 'small'].indexOf(saved.wmodel) !== -1 : false) { settings.wmodel = saved.wmodel; } else if (saved.wmodel === 'small') { settings.wmodel = 'small'; }
	} catch (e) {}
	if (VOICE_LATER) {
		voiceIn.disabled = true;
		voiceWrap.classList.add('is-later');
		var later = document.createElement('span');
		later.className = 'tp-voice-later';
		later.id = 'tpVoiceLater';
		later.textContent = __( 'English readings only for now', 'foxfire-studio' );
		voiceWrap.appendChild(later);
	} else if (!SR) { settings.voice = false; voiceWrap.hidden = true; }
	/*
	 * Kind of reading (1.11.0). A post with a kind starts the prompter in the
	 * middle of the kind's pace range; moving the slider on that post is kept
	 * for that post in this browser (rrtPace_<post>), and the pace saved for
	 * every other post is left as it was.
	 */
	var KIND = RRT.kind || null;
	studio.kind = KIND ? (KIND.kind || '') : '';
	var PACE_LOCAL = 'rrtPace_' + POST_ID;
	var globalWpm = settings.wpm;
	studio.kindOpts = function () {
		return PR ? PR.kindReview(studio.kind, KIND ? KIND.ranges : null) : null;
	};
	studio.paceRange = function () {
		var r = (PR && studio.kind) ? PR.kindRange(studio.kind, KIND ? KIND.ranges : null) : null;
		if (!r) { return null; }
		r.name = (KIND && KIND.names) ? (KIND.names[studio.kind] || studio.kind) : studio.kind;
		r.trails = !!(PR.KIND_TRAILS ? PR.KIND_TRAILS[studio.kind] : false); // 1.15.1: gentler Endings line.
		return r;
	};
	/*
	 * Pace outside English (1.33.0): the general listening bands are an
	 * English figure, so a reading in another language with no kind of
	 * reading is judged against the reader's own pace in that language, kept
	 * in this browser (rrtLangPace_<code>, the last ten full reviews). Until
	 * there are three, the pace is given as a number only.
	 */
	var LANG_PACE = 'rrtLangPace_' + READ_LANG.code;
	studio.langPaces = function () {
		var l = null;
		try { l = JSON.parse(window.localStorage.getItem(LANG_PACE) || 'null'); } catch (e) {}
		return Array.isArray(l) ? l.filter(function (v) { return typeof v === 'number' && v > 30 && v < 400; }) : [];
	};
	studio.langPaceKeep = function (wpm) {
		if (READ_LANG.en || !(wpm > 30 && wpm < 400)) { return; }
		var l = studio.langPaces().concat([Math.round(wpm)]).slice(-10);
		try { window.localStorage.setItem(LANG_PACE, JSON.stringify(l)); } catch (e) {}
	};
	studio.paceJudge = function () {
		var r = studio.paceRange();
		if (r || READ_LANG.en) { return r; }
		var l = studio.langPaces();
		if (l.length < 3) { return { plain: true, name: READ_LANG.name, need: 3 - l.length }; }
		var avg = l.reduce(function (a, b) { return a + b; }, 0) / l.length;
		return { lo: Math.round(avg * 0.9), hi: Math.round(avg * 1.1), name: READ_LANG.name, own: true };
	};
	function kindStartWpm() {
		var r = studio.paceRange();
		if (!r) { return globalWpm; }
		var own = null;
		try { own = JSON.parse(window.localStorage.getItem(PACE_LOCAL) || 'null'); } catch (e) {}
		if (own ? (own.kind === studio.kind && typeof own.wpm === 'number') : false) { return Math.max(80, Math.min(220, own.wpm)); }
		return PR.kindPace(r);
	}
	if (POST_ID && studio.kind) { settings.wpm = kindStartWpm(); }
	function saveSettings() {
		/* In prompter view (1.5.0) Size, Width and Line are its own: prompter.js keeps them and hands back the normal view's. */
		var out = studio.pvKeep ? studio.pvKeep(settings) : settings;
		/* A post with a kind (1.11.0) keeps its own pace; the pace saved for other posts stays. */
		if (POST_ID && studio.kind) { out = JSON.parse(JSON.stringify(out)); out.wpm = globalWpm; }
		/* The practice reading records for its own page only (1.30.0): the browser's saved choice stays. */
		if (studio.recPractice) { out = JSON.parse(JSON.stringify(out)); out.rec = studio.recPractice.saved; }
		try { window.localStorage.setItem(KEY, JSON.stringify(out)); } catch (e) {}
	}
	function applyLook() {
		root.style.setProperty('--size', settings.size + 'px');
		root.style.setProperty('--cols', settings.cols);
		root.style.setProperty('--lineY', settings.line + '%');
		root.setAttribute('data-font', settings.font);
		body.classList.toggle('is-voice', settings.voice ? !!SR : false);
	}
	wpmIn.value = settings.wpm;
	sizeIn.value = settings.size;
	colsIn.value = settings.cols;
	lineIn.value = settings.line;
	breathIn.checked = settings.breath;
	if (keysIn) { keysIn.checked = settings.keys; }
	recOpt.checked = settings.rec;
	voiceIn.checked = settings.voice ? !!SR : false;
	whisperIn.checked = settings.whisper;
	cleanIn.checked = settings.clean;
	wpmVal.textContent = sprintf( /* translators: %s: settings.wpm. */ __( '%s wpm', 'foxfire-studio' ), settings.wpm );
	applyLook();
	if (drillIn) {
		drillIn.checked = settings.drill;
		drillSecsIn.value = String(settings.drillSecs);
		drillSecsIn.disabled = !settings.drill;
		drillIn.addEventListener('change', function () { settings.drill = drillIn.checked; drillSecsIn.disabled = !settings.drill; saveSettings(); });
		drillSecsIn.addEventListener('change', function () { settings.drillSecs = parseInt(drillSecsIn.value, 10) || 30; saveSettings(); });
	}
	/*
	 * Phones (0.19.1; decided by can.js since 1.31.0). A phone or tablet
	 * browser closes a page that uses more memory than it allows, and
	 * Whisper's model comes close to that. PHONE is a touch-first device in
	 * any browser: its review uses the standard model under Automatic, the
	 * lighter path (review.js), and its takes are kept in 24 bits (record.js,
	 * 1.9.0). LOW_MEM is a memory hint under 4 GB, or a touch-first device
	 * with no hint: Whisper then runs its plainer engine build on one thread.
	 * Neither looks at the browser's name.
	 */
	var CAN = window.RRTCan || null;
	var canNow = CAN ? CAN.now() : { touch: false, memory: navigator.deviceMemory || 0 };
	studio.PHONE = CAN ? CAN.phone(canNow) : false;
	var LOW_MEM = CAN ? CAN.lowMem(canNow) : (canNow.memory ? canNow.memory < 4 : false);
	studio.LOW_MEM = LOW_MEM;
	/* Takes up to this long are reviewed by themselves on a phone, and kept on its shelf (1.31.0). */
	studio.PHONE_AUTO_S = 300;
	/* Whether a take this long goes on the shelf: always on a computer, up to PHONE_AUTO_S on a phone. */
	studio.shelfWants = function (seconds) { return (studio.PHONE || LOW_MEM) ? seconds <= studio.PHONE_AUTO_S : true; };
	var RUN_KEY = 'rrtWhisperRunning';
	studio.RUN_KEY = RUN_KEY;
	studio.lastRunStopped = false;
	try {
		var was = JSON.parse(window.localStorage.getItem(RUN_KEY) || 'null');
		if (was ? Date.now() - was.at < 86400000 : false) { studio.lastRunStopped = true; }
		window.localStorage.removeItem(RUN_KEY);
	} catch (e) {}
	/* A phone whose page closed during a review with the larger model goes back to Automatic (1.31.0). */
	if (studio.PHONE && studio.lastRunStopped && settings.wmodel === 'small') {
		settings.wmodel = 'auto';
		saveSettings();
	}
	if (wmodelIn) {
		/* Shown on every device since 1.31.0; on a phone Automatic says it means the standard model. */
		if (studio.PHONE) {
			var autoOpt = wmodelIn.querySelector('option[value="auto"]');
			if (autoOpt) { autoOpt.textContent = __( 'Automatic (standard model)', 'foxfire-studio' ); }
		}
		if (!(RRT.whisper ? RRT.whisper.small : null)) { wmodelIn.parentNode.hidden = true; }
		wmodelIn.value = settings.wmodel;
		wmodelIn.addEventListener('change', function () {
			settings.wmodel = ['auto', 'small'].indexOf(wmodelIn.value) !== -1 ? wmodelIn.value : 'base';
			/* Choosing Automatic again times the larger model afresh (1.30.0). */
			if (settings.wmodel === 'auto') { settings.gpuSlow = false; }
			saveSettings();
			var sm = RRT.whisper.small;
			var gpu = studio.gpuOk === true;
			if (settings.wmodel === 'auto' ? studio.PHONE : false) {
				msgEl.textContent = __( 'Automatic: on a phone or tablet reviews use the standard model, which needs the least memory. Choose Larger to try the larger one; if the page closes during a review, the next visit goes back to Automatic.', 'foxfire-studio' );
			} else if (settings.wmodel === 'auto') {
				msgEl.textContent = gpu ? __( 'Automatic: on this computer full reviews use the larger Whisper model on the graphics chip, which takes about half the reading\'s length or less. Opening drills use the standard model.', 'foxfire-studio' ) : __( 'Automatic: this browser cannot run Whisper on a graphics chip, so reviews use the standard model.', 'foxfire-studio' );
			} else if (settings.wmodel === 'small') {
				if (gpu) {
					msgEl.textContent = sm.gpuHost ? __( 'The larger Whisper model, on this computer\'s graphics chip: more accurate, and a review takes about half the reading\'s length or less. It loads from this site the first time (about 320 MB), then your browser keeps it.', 'foxfire-studio' ) : __( 'The larger Whisper model, on this computer\'s graphics chip: more accurate, and a review takes about half the reading\'s length or less. It downloads from Hugging Face the first time (about 320 MB), then your browser keeps it.', 'foxfire-studio' );
				} else {
					msgEl.textContent = sm.host ? __( 'The larger Whisper model: more accurate, about two to three times slower. It loads from this site the first time (about 250 MB), then your browser keeps it.', 'foxfire-studio' ) : __( 'The larger Whisper model: more accurate, about two to three times slower. It downloads from Hugging Face the first time (about 250 MB), then your browser keeps it.', 'foxfire-studio' );
				}
			} else {
				msgEl.textContent = '';
			}
		});
	}
	if (fontIn) {
		fontIn.value = settings.font;
		fontIn.addEventListener('change', function () {
			settings.font = FONTS[fontIn.value] === true ? fontIn.value : 'serif';
			applyLook();
			saveSettings();
			/* The words move when the font changes, so measure again (and once more when the font has loaded). */
			if (track) { measure(); }
			if (document.fonts ? document.fonts.ready : false) { document.fonts.ready.then(function () { if (track) { measure(); } }); }
		});
	}
	if (focusIn) {
		if (!PR || !PR.focuses[settings.focus]) { settings.focus = ''; }
		focusIn.value = settings.focus;
		focusIn.addEventListener('change', function () { settings.focus = focusIn.value; saveSettings(); });
	}

	/* Choosing a post */
	function go(id) {
		if (!id) { return; }
		window.location.href = pageUrl + (pageUrl.indexOf('?') === -1 ? '?' : '&') + (RRT.postParam || 'tp_post') + '=' + encodeURIComponent(id);
	}
	/* Tidies a web address for matching (0.25.1: named normUrl, since the word helper norm used to hide it). */
	function normUrl(u) {
		u = String(u || '').trim().toLowerCase();
		u = u.replace(/^https?:\/\//, '').replace(/^www\./, '');
		u = u.split('#')[0];
		if (u.indexOf('?p=') === -1) { u = u.split('?')[0]; }
		return u.replace(/\/+$/, '');
	}
	pick.addEventListener('change', function () { go(pick.value); });
	function loadFromUrl() {
		var want = normUrl(urlIn.value);
		if (!want) { return; }
		var opts = pick.options;
		var k;
		for (k = 0; k < opts.length; k++) {
			if (opts[k].getAttribute('data-url')) {
				if (normUrl(opts[k].getAttribute('data-url')) === want) { go(opts[k].value); return; }
			}
		}
		msgEl.textContent = __( 'That URL does not match any post here.', 'foxfire-studio' );
	}
	loadBtn.addEventListener('click', loadFromUrl);
	urlIn.addEventListener('keydown', function (e) { if (e.key === 'Enter') { loadFromUrl(); } });

	function setWpm(v, fromKind) {
		settings.wpm = Math.max(80, Math.min(220, v));
		if (POST_ID && studio.kind) {
			if (!fromKind) { try { window.localStorage.setItem(PACE_LOCAL, JSON.stringify({ kind: studio.kind, wpm: settings.wpm })); } catch (e) {} }
		} else {
			globalWpm = settings.wpm;
		}
		wpmIn.value = settings.wpm;
		wpmVal.textContent = sprintf( /* translators: %s: settings.wpm. */ __( '%s wpm', 'foxfire-studio' ), settings.wpm );
		saveSettings();
		insightHint();
	}
	wpmIn.addEventListener('input', function () { setWpm(parseInt(wpmIn.value, 10)); });
	studio.setWpm = setWpm;

	/* The Kind of reading menu (1.11.0), in the Practice section. */
	var kindIn = document.getElementById('tpKind');
	var kindHint = document.getElementById('tpKindHint');
	function kindHintShow() {
		if (!kindHint) { return; }
		kindHint.innerHTML = '';
		var guess = KIND ? KIND.guess : '';
		if (studio.kind || guess !== 'verse' || !KIND.rest) { kindHint.hidden = true; return; }
		kindHint.hidden = false;
		kindHint.appendChild(document.createTextNode(__( 'This post has many short lines, so it may be verse.', 'foxfire-studio' ) + ' '));
		var b = document.createElement('button');
		b.type = 'button';
		b.className = 'tp-btn tp-kind-set';
		b.textContent = __( 'Set to Verse', 'foxfire-studio' );
		b.addEventListener('click', function () { kindIn.value = 'verse'; kindChoose('verse'); });
		kindHint.appendChild(b);
	}
	function kindSave(kind) {
		if (!KIND || !KIND.rest) { return; }
		window.fetch(KIND.rest, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': KIND.nonce },
			body: JSON.stringify({ post: POST_ID, kind: kind })
		}).then(function (r) {
			if (!r.ok) { throw new Error(sprintf( /* translators: %s: r.status. */ __( 'HTTP %s', 'foxfire-studio' ), r.status )); }
			return r.json();
		})['catch'](function () {
			msgEl.textContent = __( 'The kind of reading could not be saved to the site just now. Choose it again in a moment.', 'foxfire-studio' );
		});
	}
	function kindChoose(kind) {
		studio.kind = kind;
		if (KIND) { KIND.kind = kind; }
		try { window.localStorage.removeItem(PACE_LOCAL); } catch (e) {}
		var r = studio.paceRange();
		if (r) {
			setWpm(PR.kindPace(r), true);
			msgEl.textContent = sprintf( /* translators: 1: kind of reading, such as Verse, 2: the starting pace, 3: lowest pace, 4: highest pace. */ __( '%1$s: the prompter starts at %2$s wpm, and the review judges the pace against %3$s to %4$s.', 'foxfire-studio' ), KIND.labels[kind] || kind, PR.kindPace(r), r.lo, r.hi );
		} else {
			setWpm(globalWpm, true);
			msgEl.textContent = __( 'No kind of reading: the prompter uses your usual pace, and the review judges the pace as before.', 'foxfire-studio' );
		}
		kindSave(kind);
		kindHintShow();
	}
	if (kindIn) {
		if (!KIND || !PR || !POST_ID) {
			kindIn.parentNode.hidden = true;
		} else {
			kindIn.value = studio.kind;
			if (!KIND.rest) { kindIn.disabled = true; kindIn.title = __( 'Only someone who can edit this post can choose its kind of reading.', 'foxfire-studio' ); }
			kindIn.addEventListener('change', function () { kindChoose(kindIn.value); });
			kindHintShow();
		}
	}
	sizeIn.addEventListener('input', function () { settings.size = parseInt(sizeIn.value, 10); applyLook(); saveSettings(); measure(); });
	colsIn.addEventListener('input', function () { settings.cols = parseInt(colsIn.value, 10); applyLook(); saveSettings(); measure(); });
	lineIn.addEventListener('input', function () { settings.line = parseInt(lineIn.value, 10); applyLook(); saveSettings(); });
	breathIn.addEventListener('change', function () { settings.breath = breathIn.checked; saveSettings(); });
	if (keysIn) { keysIn.addEventListener('change', function () { settings.keys = keysIn.checked; saveSettings(); }); }
	recOpt.addEventListener('change', function () {
		settings.rec = recOpt.checked;
		/* A choice made on the practice page is the reader's own, so it is saved as usual (1.30.0). */
		studio.recPractice = null;
		saveSettings();
		if (studio.PRACTICE ? !settings.rec : false) {
			msgEl.textContent = __( 'Recording is off, so this practice will end with no review. Switch on Record my voice (Controls panel, Recording) to record it.', 'foxfire-studio' );
		}
	});
	whisperIn.addEventListener('change', function () { settings.whisper = whisperIn.checked; saveSettings(); });
	cleanIn.addEventListener('change', function () { settings.clean = cleanIn.checked; saveSettings(); });
	voiceIn.addEventListener('change', function () {
		settings.voice = voiceIn.checked;
		applyLook();
		saveSettings();
		onModeChange();
	});

	/*
	 * Send to my computer (0.21.0). A take sent from a phone waits on the
	 * site; the computer's teleprompter offers it on any page, opens the post
	 * it was read from, and opens the take there for the full review. It is
	 * deleted from the site once it is used, downloaded or deleted here.
	 */
	/*
	 * The intro (0.23.0): a short video chosen in Settings, played when the
	 * studio opens in a new tab. Skip ends it; "Skip it from now on" (or the
	 * Play the intro switch in the Controls panel) stops it in this browser.
	 * It starts with sound when the browser allows, else muted with Sound on.
	 */
	var introEl = document.getElementById('tpIntro');
	var introOffKey = 'rrtIntro';
	function introOff() { try { return localStorage.getItem(introOffKey) === 'off'; } catch (e) { return false; } }
	function introSetOff(off) { try { if (off) { localStorage.setItem(introOffKey, 'off'); } else { localStorage.removeItem(introOffKey); } } catch (e) {} }
	/*
	 * The cover (0.24.0): the page's head blacks the page out from the first
	 * paint when the intro is going to play. It lifts once the intro's own
	 * black overlay is showing, or at once when the intro will not play.
	 */
	function introUncover() { document.documentElement.classList.remove('rrt-intro-wait'); }
	(function () {
		if (!introEl || !RRT.intro || !RRT.intro.url) { introUncover(); return; }
		var wrap = document.getElementById('tpIntroWrap');
		var opt = document.getElementById('tpIntroOpt');
		if (wrap && opt) {
			wrap.hidden = false;
			opt.checked = !introOff();
			opt.addEventListener('change', function () { introSetOff(!opt.checked); });
		}
		var seen = false;
		try { seen = sessionStorage.getItem('rrtIntroSeen') === '1'; } catch (e) {}
		var calm = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)').matches : false;
		/* Not again in this tab, not when switched off, and not when a sent take is being opened. */
		if (seen || calm || introOff() || /[?&]rrt_sent=/.test(window.location.search)) { introUncover(); return; }
		try { sessionStorage.setItem('rrtIntroSeen', '1'); } catch (e) {}
		var v = document.getElementById('tpIntroVideo');
		var skip = document.getElementById('tpIntroSkip');
		var off = document.getElementById('tpIntroOff');
		var sound = document.getElementById('tpIntroSound');
		var done = false;
		var stall = 0;
		function close() {
			if (done) { return; }
			done = true;
			window.clearTimeout(stall);
			introUncover();
			if (off.checked) {
				introSetOff(true);
				if (opt) { opt.checked = false; }
			}
			v.pause();
			introEl.classList.add('is-leaving');
			document.removeEventListener('keydown', keys, true);
			setTimeout(function () { introEl.hidden = true; v.removeAttribute('src'); v.load(); }, 650);
		}
		function keys(e) {
			/* Escape skips; Enter and Space (1.27.0) act on the focused button or tick box, and only skip from elsewhere. */
			if (e.key === 'Escape' || e.key === 'Enter' || e.key === ' ') {
				if (e.key !== 'Escape' ? introEl.contains(e.target) : false) { return; }
				e.preventDefault();
				close();
			}
			/* While the intro plays, the studio's own keys wait. */
			e.stopImmediatePropagation();
		}
		document.addEventListener('keydown', keys, true);
		skip.addEventListener('click', close);
		sound.addEventListener('click', function () { v.muted = false; sound.hidden = true; });
		v.addEventListener('ended', close);
		v.addEventListener('error', close);
		/* A video that has not started within 4 seconds is let go, so the studio is never kept waiting. */
		v.addEventListener('playing', function () { window.clearTimeout(stall); });
		stall = window.setTimeout(close, 4000);
		v.src = RRT.intro.url;
		introEl.hidden = false;
		introUncover();
		skip.focus();
		var p = v.play();
		if (p && p.then) {
			p['catch'](function () {
				/* No sound allowed before a click: play muted and offer it. */
				v.muted = true;
				sound.hidden = false;
				v.play()['catch'](close);
			});
		}
	})();
	function fmtClock(sec) {
		sec = Math.max(0, Math.round(sec || 0));
		return Math.floor(sec / 60) + ':' + (sec % 60 < 10 ? '0' : '') + (sec % 60);
	}
	/*
	 * New here (1.29.0): a reader new to this browser, with no microphone
	 * check saved, is offered the practice reading once, on the studio's
	 * first screen and beside Check microphone. Not now, a microphone check
	 * or opening the practice reading ends the offer in this browser.
	 */
	var PRACTICE = RRT.practice || null;
	studio.PRACTICE = PRACTICE;
	/*
	 * The practice reading is for recording (1.30.0): Record my voice is on
	 * for this page even when the browser has it off, so the practice always
	 * ends with a review. The browser's saved choice is left alone.
	 */
	studio.recPractice = null;
	if (PRACTICE ? !settings.rec : false) {
		studio.recPractice = { saved: false };
		settings.rec = true;
		recOpt.checked = true;
	}
	var WELCOMED_KEY = 'rrtWelcomed';
	function welcomed() { try { return !!window.localStorage.getItem(WELCOMED_KEY); } catch (e) { return true; } }
	function setWelcomed() { try { window.localStorage.setItem(WELCOMED_KEY, String(Date.now())); } catch (e) {} }
	studio.setWelcomed = setWelcomed;
	var offerNew = PRACTICE ? false : (PR ? (!PR.lastCheck() ? !welcomed() : false) : false);
	if (PRACTICE) { setWelcomed(); }
	(function () {
		var box = document.getElementById('tpWelcome');
		/* 1.33.1: the empty studio's lasting line steps aside while New here offers the same reading. */
		var line = document.getElementById('tpEmptyPractice');
		if (!box || !offerNew) { return; }
		box.hidden = false;
		if (line) { line.hidden = true; }
		/* Follow my voice as a tick (1.31.0): the same setting as the switch in the Controls panel, Reading. */
		var vt = document.getElementById('tpWelcomeVoice');
		if (vt && SR) {
			var mb = Math.max(1, Math.round(((RRT.follow ? RRT.follow.bytes : 0) || 47185920) / 1048576));
			var size = document.getElementById('tpWelcomeVoiceSize');
			if (size) { size.textContent = sprintf( /* translators: %s: megabytes. */ __( 'The script then waits for you. Its model loads once, about %s MB.', 'foxfire-studio' ), mb ); }
			vt.closest('.tp-welcome-voice').hidden = false;
			vt.checked = !!settings.voice;
			vt.addEventListener('change', function () {
				voiceIn.checked = vt.checked;
				voiceIn.dispatchEvent(new Event('change'));
			});
		}
		var later = document.getElementById('tpWelcomeLater');
		if (later) {
			later.addEventListener('click', function () {
				setWelcomed();
				box.hidden = true;
				if (line) { line.hidden = false; }
			});
		}
	})();
	if (!track) { return; }

	/* Text helpers */
	var CLOSERS = '["\'\u201D\u2019)\\]]*$';
	var reEllipsis = new RegExp('(\u2026|\\.\\.\\.)' + CLOSERS);
	/* 1.33.0: also the Arabic question mark, the Devanagari danda and the Urdu full stop, and the Arabic comma. */
	var reStop = new RegExp('[.!?\u061F\u0964\u0965\u06D4]' + CLOSERS);
	var reComma = new RegExp('[,;:\u060C]' + CLOSERS);
	var reDash = new RegExp('(\u2014|\u2013|--)' + CLOSERS);

	function syllables(word) {
		var w = clean(word);
		if (!w) { return 1; }
		var digits = w.replace(/[^0-9]/g, '').length;
		if (digits) { return Math.max(1, Math.round(digits * 1.5)); }
		if (w.length <= 3) { return 1; }
		w = w.replace(/(?:[^laeiouy]es|[^laeiouy]ed|[^laeiouy]e)$/, '');
		w = w.replace(/^y/, '');
		var groups = w.match(/[aeiouy\u00E0-\u00FF]+/g);
		/* 1.33.0: a script without these vowels (Cyrillic, Greek, Arabic and others) is weighed by its length. */
		if (!groups && !READ_LANG.en) { return Math.max(1, Math.round(w.length / 3)); }
		return Math.max(1, groups ? groups.length : 1);
	}

	/* Break every paragraph into word spans */
	var slug = track.getAttribute('data-slug') || 'reading';
	studio.slug = slug;
	var blocks = Array.prototype.slice.call(track.querySelectorAll('.tp-block'));
	studio.blocks = blocks;
	var W = [];
	studio.W = W;
	var blockFirst = [];
	var sentId = 0;
	var sentEls = [];

	blocks.forEach(function (b, bi) {
		var parts = b.textContent.split(/(\s+)/);
		var kind = b.classList.contains('tp-title') ? 'title' : (b.classList.contains('tp-h') ? 'h' : 'p');
		b.textContent = '';
		blockFirst.push(W.length);
		parts.forEach(function (part) {
			if (!part) { return; }
			if (/^\s+$/.test(part)) {
				b.appendChild(document.createTextNode(' '));
				return;
			}
			var s = document.createElement('span');
			s.className = 'tp-w';
			s.textContent = part;
			s.setAttribute('data-i', W.length);
			b.appendChild(s);
			var syl = syllables(part);
			var weight = Math.max(0.6, Math.min(2.8, 0.4 + syl * 0.4));
			var beat = 0;
			if (reEllipsis.test(part)) { beat = 1.0; }
			else if (reStop.test(part)) { beat = 0.8; }
			else if (reDash.test(part)) { beat = 0.45; }
			else if (reComma.test(part)) { beat = 0.35; }
			W.push({ el: s, block: bi, kind: kind, weight: weight, beat: beat, last: false, top: 0, key: clean(part), sent: sentId });
			(sentEls[sentId] = sentEls[sentId] || []).push(s);
			if (reStop.test(part) || reEllipsis.test(part)) { sentId++; }
		});
		if (W.length > blockFirst[bi]) {
			W[W.length - 1].last = true;
			if (W[W.length - 1].sent === sentId) { sentId++; }
		}
	});
	studio.wi = 0;
	var wf = 0;
	var pauseLeft = 0;
	studio.pos = 0;
	studio.playing = false;
	var started = false;
	var counting = false;
	studio.finished = false;
	/* At the end of the script with the recording still going (1.33.0). */
	studio.heldAtEnd = false;
	var last = 0;
	var statusClock = 0;
	var shownBlock = -1;
	var shownSent = -1;

	function markSentence() {
		var s = W[studio.wi] ? W[studio.wi].sent : -1;
		if (s === shownSent) { return; }
		if (shownSent >= 0 && sentEls[shownSent]) { sentEls[shownSent].forEach(function (e) { e.classList.remove('in-sent'); }); }
		shownSent = s;
		if (s >= 0 && sentEls[s]) { sentEls[s].forEach(function (e) { e.classList.add('in-sent'); }); }
	}

	function voiceMode() {
		return settings.voice ? !!SR : false;
	}
	function beatSec() {
		return 60 / settings.wpm;
	}
	function wordDur(i) {
		return W[i].weight * studio.cueWeight(i) * beatSec();
	}
	/* The rest after a word: a paragraph's breath (when that is on) plus any cue mark. */
	function breathAfter(i) {
		var c = studio.cueSecs(i);
		if (!W[i].last) { return c; }
		if (!settings.breath) { return c; }
		if (W[i].kind === 'title') { return 1.4 + c; }
		if (W[i].kind === 'h') { return 1.2 + c; }
		return 0.75 + c;
	}
	function pauseAfter(i) {
		return W[i].beat * beatSec() + breathAfter(i);
	}

	function measure() {
		var k;
		for (k = 0; k < W.length; k++) { W[k].top = W[k].el.offsetTop; }
	}
	function target() {
		if (!W.length) { return 0; }
		var lineH = settings.size * 1.5;
		var goal = W[studio.wi].top + lineH * 0.5 - stage.clientHeight * (settings.line / 100);
		if (studio.heldAtEnd) { goal = Math.min(goal, heldGoal(lineH)); }
		return goal;
	}
	/*
	 * Held at the end (1.33.0): the script reached its last word before the
	 * reader, who may still be a few lines back. Bring the last paragraph
	 * back into view: all of it when it fits above the message, or else as
	 * much of its end as fits.
	 */
	function heldGoal(lineH) {
		var last = W.length - 1;
		var first = last;
		while (first > 0 && W[first - 1].block === W[last].block) { first--; }
		var room = stage.clientHeight - heldCover();
		var fromStart = W[first].top - lineH * 0.5;
		var toEnd = W[last].top + lineH * 1.5 - room;
		return Math.max(fromStart, toEnd);
	}
	/* How much of the stage the message and the dock cover from below, so the held paragraph stays above them. */
	function heldCover() {
		var s = stage.getBoundingClientRect();
		var top = s.bottom;
		[msgEl, document.querySelector('.tp-dock')].forEach(function (e) {
			if (!e || !e.offsetParent) { return; }
			var r = e.getBoundingClientRect();
			if (r.height && r.top < top && r.top > s.top) { top = r.top; }
		});
		return Math.max(0, s.bottom - top);
	}

	/* Word and paragraph highlighting */
	function paintNow() {
		var w = W[studio.wi];
		if (!w) { return; }
		var f = voiceMode() ? 1 : Math.min(1, wf);
		w.el.style.backgroundPosition = (100 - f * 100).toFixed(1) + '% 0';
	}
	function markBlocks() {
		var cur = W[studio.wi] ? W[studio.wi].block : 0;
		if (cur === shownBlock) { return; }
		shownBlock = cur;
		var k;
		for (k = 0; k < blocks.length; k++) {
			blocks[k].classList.toggle('is-on', k === cur);
			blocks[k].classList.toggle('is-past', k < cur);
			blocks[k].classList.toggle('is-next', k === cur + 1);
		}
	}
	function markAll() {
		var k;
		for (k = 0; k < W.length; k++) {
			W[k].el.classList.toggle('is-said', k < studio.wi);
			W[k].el.classList.toggle('is-now', k === studio.wi);
			if (k !== studio.wi) { W[k].el.style.backgroundPosition = ''; }
		}
		shownBlock = -1;
		markBlocks();
		markSentence();
		paintNow();
	}
	function advance() {
		var w = W[studio.wi];
		w.el.classList.remove('is-now');
		w.el.classList.add('is-said');
		w.el.style.backgroundPosition = '';
		studio.wi += 1;
		wf = 0;
		pauseLeft = 0;
		W[studio.wi].el.classList.add('is-now');
		markBlocks();
		markSentence();
		paintNow();
	}
	function setCursor(n) {
		if (n >= W.length) {
			while (studio.wi < W.length - 1) { advance(); }
			reachEnd();
			return;
		}
		while (studio.wi < n) { advance(); }
	}
	function onModeChange() {
		if (!track) { return; }
		wf = 0;
		pauseLeft = 0;
		paintNow();
		if (studio.playing) {
			if (voiceMode()) { studio.startListening(); } else { studio.stopListening(); }
		}
		status();
	}

	function fmt(sec) {
		sec = Math.max(0, Math.round(sec));
		var m = Math.floor(sec / 60);
		var s = sec % 60;
		return m + ':' + (s < 10 ? '0' : '') + s;
	}
	function status() {
		var done = 0;
		var breaths = 0;
		var k;
		for (k = 0; k < studio.wi; k++) { done += W[k].weight + W[k].beat; }
		for (k = 0; k < studio.wi; k++) { done += W[k].weight * (studio.cueWeight(k) - 1); }
		if (!voiceMode()) { done += W[studio.wi].weight * studio.cueWeight(studio.wi) * Math.min(1, wf); }
		if (studio.finished) { done = studio.totalWeight; }
		for (k = studio.wi; k < W.length; k++) { breaths += breathAfter(k); }
		progEl.style.width = ((done / studio.totalWeight) * 100) + '%';
		whereEl.textContent = sprintf( /* translators: 1: W[wi].block + 1, 2: blocks.length. */ __( 'Paragraph %1$s of %2$s', 'foxfire-studio' ), W[studio.wi].block + 1, blocks.length );
		if (voiceMode()) {
			leftEl.textContent = studio.listening ? (studio.followLine ? studio.followLine() : __( 'Following your voice', 'foxfire-studio' )) : __( 'Voice following is ready', 'foxfire-studio' );
		} else {
			var remaining = (studio.totalWeight - done) * beatSec() + breaths;
			leftEl.textContent = sprintf( /* translators: 1: fmt(remaining), 2: settings.wpm. */ __( 'About %1$s left at %2$s wpm', 'foxfire-studio' ), fmt(remaining), settings.wpm );
		}
		insightHint();
	}
	/*
	 * Insights (0.19.0): when listeners have stayed with shorter or slower
	 * readings, and this script at this pace runs past that, a quiet line in
	 * the script bar says so. Worked out again only when the pace or the
	 * script's length changes.
	 */
	var INS = RRT.insight || null;
	var hintEl = document.getElementById('tpHint');
	var hintKey = '';
	function insightHint() {
		if (!INS || !hintEl || !W.length) { return; }
		var key = settings.wpm + ':' + studio.totalWeight;
		if (key === hintKey) { return; }
		hintKey = key;
		var rests = 0;
		for (var k = 0; k < W.length; k++) { rests += breathAfter(k); }
		var est = studio.totalWeight * beatSec() + rests;
		var text = '';
		if (INS.length && est > INS.length.cut + 15) {
			text = sprintf( /* translators: 1: fmt(est), 2: settings.wpm, 3: INS.length.text. */ __( 'This script runs about %1$s at %2$s wpm. %3$s', 'foxfire-studio' ), fmt(est), settings.wpm, INS.length.text );
		} else if (INS.tempo && settings.wpm > INS.tempo.cut + 5) {
			text = sprintf( /* translators: 1: settings.wpm, 2: INS.tempo.text. */ __( 'Pace is set to %1$s wpm. %2$s', 'foxfire-studio' ), settings.wpm, INS.tempo.text );
		}
		hintEl.textContent = text;
		hintEl.title = text;
		hintEl.hidden = !text;
	}
	function jumpWord(i) {
		if (studio.heldAtEnd) {
			/* Moving away from the end makes it an ordinary paused take. */
			studio.heldAtEnd = false;
			studio.pauseRecorder();
		}
		studio.wi = Math.max(0, Math.min(W.length - 1, i));
		wf = 0;
		pauseLeft = 0;
		studio.finished = false;
		markAll();
		status();
		setPlayLabel();
	}
	function jumpSentence() {
		if (!W.length) { return; }
		var i = studio.wi;
		var s = W[i].sent;
		while (i > 0 && W[i - 1].sent === s) { i--; }
		if (i === studio.wi && i > 0) {
			s = W[i - 1].sent;
			i--;
			while (i > 0 && W[i - 1].sent === s) { i--; }
		}
		jumpWord(i);
	}
	studio.jumpSentence = jumpSentence;
	function jumpBlock(b) {
		b = Math.max(0, Math.min(blocks.length - 1, b));
		jumpWord(blockFirst[b]);
	}

	/* Animation loop */
	function frame(ts) {
		var dt = last ? Math.min(0.1, (ts - last) / 1000) : 0;
		last = ts;
		if (studio.playing) {
			if (!voiceMode()) {
				if (pauseLeft > 0) {
					pauseLeft -= dt;
					if (pauseLeft <= 0) { advance(); }
				} else {
					wf += dt / wordDur(studio.wi);
					if (wf >= 1) {
						wf = 1;
						paintNow();
						if (studio.wi >= W.length - 1) {
							reachEnd();
						} else {
							var p = pauseAfter(studio.wi);
							if (p > 0) { pauseLeft = p; } else { advance(); }
						}
					} else {
						paintNow();
					}
				}
			} else if (studio.followTick) {
				/* Voice following (1.14.0): the highlight leads a little while the voice sounds. */
				studio.followTick(dt);
			}
			statusClock += dt;
			if (statusClock > 0.25) { statusClock = 0; status(); }
			drillTick(dt);
		}
		var goal = target();
		studio.pos += (goal - studio.pos) * (dt ? Math.min(1, dt * 7) : 1);
		track.style.transform = 'translate(-50%, ' + (-studio.pos).toFixed(1) + 'px)';
		studio.recTick(dt);
		window.requestAnimationFrame(frame);
	}

	/* Keep the screen awake while reading */
	var wake = null;
	function syncWake() {
		if (!navigator.wakeLock) { return; }
		if (studio.playing || studio.heldAtEnd) {
			if (!wake) {
				navigator.wakeLock.request('screen').then(function (l) {
					wake = l;
					l.addEventListener('release', function () { wake = null; });
				}).catch(function () {});
			}
		} else if (wake) {
			wake.release().catch(function () {});
			wake = null;
		}
	}
	document.addEventListener('visibilitychange', function () {
		if (document.visibilityState === 'visible') { syncWake(); }
	});

	/* Play, pause, countdown */
	function setPlayLabel() {
		if (!started) { playBtn.textContent = __( 'Begin', 'foxfire-studio' ); }
		else if (studio.playing) { playBtn.textContent = __( 'Pause', 'foxfire-studio' ); }
		else if (studio.heldAtEnd) { playBtn.textContent = __( 'Finish', 'foxfire-studio' ); }
		else { playBtn.textContent = studio.finished ? __( 'Again', 'foxfire-studio' ) : __( 'Resume', 'foxfire-studio' ); }
		body.classList.toggle('is-playing', studio.playing);
		body.classList.toggle('is-held', !!studio.heldAtEnd);
		syncWake();
	}
	function countdown(n, done) {
		counting = true;
		countEl.classList.add('is-on');
		countEl.textContent = n;
		var timer = window.setInterval(function () {
			n -= 1;
			if (n <= 0) {
				window.clearInterval(timer);
				countEl.classList.remove('is-on');
				counting = false;
				done();
			} else {
				countEl.textContent = n;
			}
		}, 1000);
	}
	/* Opening drill (0.14.0): the take being read is a drill when it starts with the drill on. */
	studio.takeDrill = '';
	/* A sent take's drill length, which may differ from this computer's setting. */
	studio.sentDrillS = 0;
	/* When the open take was sent from the phone, in milliseconds, or 0. */
	studio.sentAt = 0;
	/* The newest take's original, for Send to my computer. */
	studio.lastRaw = null;
	studio.takeFocus = '';
	var drillT = 0;
	var drillStopAt = -1;
	studio.drillWords = 0;
	function drillEnd() {
		/* The drill covers the script up to the end of the sentence it stopped on. */
		studio.drillWords = Math.min(W.length, (drillStopAt >= 0 ? drillStopAt : studio.wi) + 1);
		studio.playing = false;
		studio.finished = true;
		studio.stopListening();
		setPlayLabel();
		status();
		msgEl.textContent = __( 'Opening drill done. Begin again to repeat it; each take is compared with the last drill.', 'foxfire-studio' );
		if (studio.recorder) {
			if (studio.recorder.state !== 'inactive') { window.setTimeout(studio.stopRecording, 900); }
		}
	}
	function drillTick(dt) {
		if (!studio.playing || !studio.takeDrill) { return; }
		drillT += dt;
		if (drillStopAt < 0 && drillT >= settings.drillSecs) {
			drillStopAt = PR ? PR.drillStopWord(W.map(function (w) { return w.sent; }), studio.wi) : studio.wi;
			msgEl.textContent = __( 'Opening drill: finishing this sentence.', 'foxfire-studio' );
		}
		if (drillStopAt < 0) { return; }
		var done = studio.wi > drillStopAt || (studio.wi === drillStopAt ? (!voiceMode() ? wf >= 1 : false) : false);
		/* Never run on for long past the limit, whatever happens. */
		if (done || drillT >= settings.drillSecs + 15) { drillEnd(); }
	}
	function begin() {
		if (counting) { return; }
		msgEl.textContent = '';
		studio.takeMode = voiceMode() ? 'voice' : 'timed';
		studio.takeDrill = settings.drill ? 'opening' : '';
		studio.sentDrillS = 0;
		studio.sentAt = 0;
		studio.takeFocus = settings.focus || '';
		drillT = 0;
		drillStopAt = -1;
		studio.drillWords = 0;
		/* A drill always reads the opening. */
		if (studio.takeDrill && studio.wi > 0) { jumpWord(0); }
		var steps = Promise.resolve(true);
		if (voiceMode()) {
			/* Voice following loads its model the first time (1.14.0); if it cannot, the timed pace reads this take. */
			steps = studio.prepareVoice().then(function () { return true; });
		}
		if (settings.rec) {
			steps = steps.then(function () {
				return studio.prepareMic().then(function (ok) {
					if (!ok) {
						msgEl.textContent = __( 'The microphone was not available, so this reading will not be recorded.', 'foxfire-studio' );
					}
					return true;
				});
			});
		}
		steps.then(function () {
			countdown(3, function () {
				started = true;
				studio.finished = false;
				studio.playing = true;
				/* Each take starts voice following afresh (1.14.0), from the word it begins on. */
				if (voiceMode()) { if (studio.followFresh) { studio.followFresh(); } studio.startListening(); }
				studio.startRecorder();
				setPlayLabel();
				status();
				paceLine();
			});
		});
	}
	/*
	 * The pace line (1.31.0): on the practice reading and on the first take
	 * in a browser, the timed pace says it waits for nobody, since a new
	 * reader can run ahead of the script without knowing why.
	 */
	var TOOK_KEY = 'rrtTookOne';
	function tookOne() { try { window.localStorage.setItem(TOOK_KEY, String(Date.now())); } catch (e) {} }
	studio.paceLineWanted = function () {
		if (voiceMode() || studio.takeDrill) { return false; }
		if (PRACTICE) { return true; }
		try { return !window.localStorage.getItem(TOOK_KEY); } catch (e) { return false; }
	};
	function paceLine() {
		if (!studio.paceLineWanted()) { return false; }
		/* Where Follow my voice cannot be switched on (another language, or this browser), the line does not offer it (1.33.0). */
		if (!SR) {
			msgEl.textContent = sprintf( /* translators: %s: the reader's pace in words a minute. */ __( 'The script moves at %s words a minute and does not wait for you. Pause at each full stop, or lower Pace (Controls panel, Reading).', 'foxfire-studio' ), settings.wpm );
			return true;
		}
		msgEl.textContent = sprintf( /* translators: %s: the reader's pace in words a minute. */ __( 'The script moves at %s words a minute and does not wait for you. Pause at each full stop, or switch on Follow my voice (Controls panel, Reading).', 'foxfire-studio' ), settings.wpm );
		return true;
	}
	studio.paceLine = paceLine;
	function togglePlay() {
		if (counting) { return; }
		if (!started) { begin(); return; }
		if (studio.heldAtEnd) {
			/* At the end with the recording still going, the main button finishes it. */
			studio.stopRecording();
			return;
		}
		if (studio.finished) {
			jumpWord(0);
			started = false;
			setPlayLabel();
			begin();
			return;
		}
		studio.playing = !studio.playing;
		if (studio.playing) {
			studio.resumeRecorder();
			if (voiceMode()) { studio.startListening(); }
		} else {
			studio.pauseRecorder();
			studio.stopListening();
		}
		setPlayLabel();
		status();
	}
	/*
	 * Start a new take at word i (1.4.0, Continue). Refused while a take is
	 * under way: counting down, reading, or paused with its recording open.
	 */
	function beginFrom(i) {
		if (counting) { return false; }
		if (studio.heldAtEnd) { return false; }
		if (started ? !studio.finished : false) {
			if (studio.playing) { return false; }
			if (studio.recorder ? studio.recorder.state !== 'inactive' : false) { return false; }
		}
		jumpWord(i);
		started = false;
		setPlayLabel();
		begin();
		return true;
	}
	function reachEnd() {
		tookOne();
		studio.playing = false;
		studio.finished = true;
		studio.stopListening();
		W[studio.wi].el.classList.remove('is-now');
		W[studio.wi].el.classList.add('is-said');
		W[studio.wi].el.style.backgroundPosition = '';
		status();
		setPlayLabel();
		if (studio.recorder) {
			if (studio.recorder.state !== 'inactive') {
				if (voiceMode()) {
					/* Following the voice, the end is the reader's last word. */
					msgEl.textContent = __( 'The end. Finishing the recording...', 'foxfire-studio' );
					window.setTimeout(studio.stopRecording, 2000);
				} else {
					/*
					 * Scrolling at the pace (1.33.0): the script can reach its end
					 * before the reader does, so the recording keeps going until
					 * Finish recording.
					 */
					studio.heldAtEnd = true;
					setPlayLabel();
					msgEl.textContent = __( 'The end of the script. The recording is still going: press Finish recording after your last word.', 'foxfire-studio' );
				}
			}
		}
	}

	playBtn.addEventListener('click', function () { playBtn.blur(); togglePlay(); });
	restartBtn.addEventListener('click', function () { restartBtn.blur(); jumpWord(0); });
	finishBtn.addEventListener('click', function () {
		tookOne();
		studio.playing = false;
		studio.stopListening();
		setPlayLabel();
		studio.stopRecording();
	});
	track.addEventListener('click', function (e) {
		var t = e.target;
		/* With Cues on (and the reading paused), a word click sets its cue. */
		if (cuesIn ? (cuesIn.checked ? !studio.playing : false) : false) {
			if (t.classList.contains('tp-w')) { studio.cueMenuOpen(parseInt(t.getAttribute('data-i'), 10)); }
			return;
		}
		if (t.classList.contains('tp-w')) {
			jumpWord(parseInt(t.getAttribute('data-i'), 10));
		} else if (t.classList.contains('tp-block')) {
			jumpBlock(blocks.indexOf(t));
		}
	});

	var wheelLock = 0;
	stage.addEventListener('wheel', function (e) {
		if (studio.playing) { return; }
		var now = Date.now();
		if (now - wheelLock < 180) { return; }
		wheelLock = now;
		jumpBlock(W[studio.wi].block + (e.deltaY > 0 ? 1 : -1));
	}, { passive: true });

	/*
	 * Where the keys act (1.27.0). The teleprompter's keys work while focus is
	 * on the script or on the page itself, so every switch, slider, menu and
	 * button keeps its own keys. Escape from any control around the script
	 * goes back to it. Page Up and Page Down, a presentation clicker's keys,
	 * work from anywhere but a field that takes typing.
	 */
	function onScript(t) {
		if (!t || t === body || t === root) { return true; }
		return stage ? stage.contains(t) : false;
	}
	studio.onScript = onScript;
	function typing(t) {
		if (!t || !t.tagName) { return false; }
		if (t.isContentEditable) { return true; }
		if (t.tagName === 'TEXTAREA' || t.tagName === 'SELECT') { return true; }
		return t.tagName === 'INPUT' ? ['checkbox', 'radio', 'range', 'button', 'submit', 'reset', 'file', 'color'].indexOf(t.type) === -1 : false;
	}
	studio.typing = typing;
	/* A control with keys of its own: anything but the script, the review's script and plain text. */
	function isControl(t) {
		if (!t || !t.closest) { return false; }
		return !!t.closest('button, a[href], input, select, textarea, audio, video, summary, [contenteditable="true"], [role="button"], [role="switch"], [role="slider"], [role="checkbox"], [role="radio"], [role="menuitem"]');
	}
	studio.isControl = isControl;
	studio.keysOn = function () { return settings.keys !== false; };
	function toScript() {
		if (track) { track.focus({ preventScroll: true }); return; }
		if (document.activeElement && document.activeElement.blur) { document.activeElement.blur(); }
	}
	studio.toScript = toScript;
	var around = ['tpBar', 'tpScriptBar', 'tpPanel'].map(function (id) { return document.getElementById(id); }).concat([document.querySelector('.tp-dock')]).filter(Boolean);
	if (track) {
		/* The script is one stop in the Tab order, so its keys can be reached from the keyboard. */
		track.tabIndex = 0;
		track.setAttribute('role', 'region');
		track.setAttribute('aria-label', __( 'Script', 'foxfire-studio' ));
		var keysHelp = document.getElementById('tpKeysHelp');
		if (keysHelp) { track.setAttribute('aria-describedby', 'tpKeysHelp'); }
	}

	document.addEventListener('keydown', function (e) {
		/* The cue menu, and panels such as the warm-up and the microphone check, keep their keys to themselves. */
		if (studio.cueMenu) {
			/* In the pronunciation note form (1.1.0), keys type and tick as usual; Escape still closes. */
			if (studio.cueMenu.contains(e.target) ? e.target.tagName === 'INPUT' : false) {
				if (e.key === 'Escape') { e.preventDefault(); studio.cueMenuClose(); }
				return;
			}
			/* Space only closes the menu, so a habitual Space never sets a cue by accident; Enter picks a button. */
			if (e.key === 'Escape' || e.key === ' ' || e.code === 'Space') { e.preventDefault(); studio.cueMenuClose(); }
			return;
		}
		if (document.querySelector('.tp-warm, .tp-mic, .tp-share')) { return; }
		var t = e.target;
		if (studio.rv.open ? (studio.cb ? studio.cb.open : false) : false) {
			/* Combine takes (1.3.0): Escape goes back to the review, Space plays and pauses what was last heard there. */
			if (e.key === 'Escape' ? !typing(t) : false) { e.preventDefault(); studio.cbClose(); }
			else if ((e.key === ' ' || e.code === 'Space') && !isControl(t)) { e.preventDefault(); studio.cbTogglePlay(); }
			return;
		}
		if (studio.rv.open) {
			var free = !isControl(t);
			if (studio.ed.on ? free : false) {
				/* Edge nudge (0.20.0): [ and ] move the retake's start, with Shift its end. A single-key shortcut (1.27.0). */
				if ((e.code === 'BracketLeft' || e.code === 'BracketRight') ? !studio.rt.edges.hidden : false) {
					if (!(e.ctrlKey || e.metaKey || e.altKey) ? studio.keysOn() : false) { e.preventDefault(); studio.rtNudge(e.shiftKey ? 'e' : 's', e.code === 'BracketRight' ? 1 : -1); }
					return;
				}
				if (e.shiftKey ? (e.key === 'ArrowRight' || e.key === 'ArrowLeft') : false) { if (studio.ed.a >= 0) { e.preventDefault(); studio.edNudge(e.key === 'ArrowRight' ? 1 : -1); } return; }
				if (e.key === 'Delete' || e.key === 'Backspace') { if (studio.ed.a >= 0) { e.preventDefault(); studio.edCut(); } return; }
				if ((e.ctrlKey || e.metaKey) ? (e.key === 'z' || e.key === 'Z') : false) { e.preventDefault(); studio.edUndo(); return; }
				if (e.key === 'Escape') {
					if (!studio.ed.tidyBox.hidden) { e.preventDefault(); studio.ed.tidyBox.hidden = true; return; }
					if (!studio.rt.box.hidden) { e.preventDefault(); studio.rtClose(); return; }
					if (studio.ed.a >= 0) { e.preventDefault(); studio.ed.a = -1; studio.ed.b = -1; studio.edPaint(); return; }
				}
			}
			if (e.key === 'Escape' ? !typing(t) : false) { e.preventDefault(); studio.closeReview(); }
			else if ((e.key === ' ' || e.code === 'Space') && free) {
				e.preventDefault();
				if (studio.rv.audio.paused) { studio.rv.audio.play().catch(function () {}); } else { studio.rv.audio.pause(); }
			}
			return;
		}
		/* A dialog over the studio (1.27.0) keeps every key but the clicker's to itself. */
		if (studio.dialogTop() ? e.key !== 'PageDown' && e.key !== 'PageUp' : false) { return; }
		if (e.ctrlKey || e.metaKey || e.altKey) { return; }
		if (e.key === 'PageDown' || e.key === 'PageUp') {
			if (typing(t) || studio.dialogTop()) { return; }
			e.preventDefault();
			/* Page Up (1.5.0), the back button of a presentation clicker: the start of this sentence, or of the one before. */
			if (e.key === 'PageDown') { togglePlay(); } else { jumpSentence(); }
			return;
		}
		if (!onScript(t)) {
			if (e.key === 'Escape' ? around.some(function (n) { return n.contains(t); }) : false) {
				/* Escape from any control around the script (1.27.0) goes back to the script, where Space starts and pauses. */
				e.preventDefault();
				toScript();
			}
			return;
		}
		var cueing = cuesIn ? (cuesIn.checked ? !studio.playing : false) : false;
		if (e.key === ' ' || e.code === 'Space') { e.preventDefault(); togglePlay(); }
		else if (e.key === 'Enter') {
			/* The word cursor (1.27.0): with Cues on, Enter opens the word's cue menu; otherwise it starts from the word when paused. */
			if (counting || studio.playing) { return; }
			e.preventDefault();
			if (cueing) { studio.cueMenuOpen(studio.wi); } else { togglePlay(); }
		}
		else if (e.key === 'ArrowRight') {
			e.preventDefault();
			if (cueing) { jumpWord(studio.wi + (e.shiftKey ? 5 : 1)); studio.say(W[studio.wi].el.textContent); }
			else if (e.shiftKey) { jumpWord(studio.wi + 5); } else { setWpm(settings.wpm + 5); status(); }
		}
		else if (e.key === 'ArrowLeft') {
			e.preventDefault();
			if (cueing) { jumpWord(studio.wi - (e.shiftKey ? 5 : 1)); studio.say(W[studio.wi].el.textContent); }
			else if (e.shiftKey) { jumpWord(studio.wi - 5); } else { setWpm(settings.wpm - 5); status(); }
		}
		else if (e.key === 'ArrowUp') {
			e.preventDefault();
			var cb = W[studio.wi].block;
			if (studio.wi > blockFirst[cb]) { jumpBlock(cb); } else { jumpBlock(cb - 1); }
		}
		else if (e.key === 'ArrowDown') { e.preventDefault(); jumpBlock(W[studio.wi].block + 1); }
		else if (e.key === 'Home') { e.preventDefault(); jumpWord(0); }
	});

	/*
	 * The polite line (1.27.0): a hidden live region that reads out what
	 * changes off to the side, such as a recording starting or the review
	 * being ready, without moving focus.
	 */
	var sayEl = document.createElement('p');
	sayEl.className = 'tp-vh';
	sayEl.id = 'tpSay';
	sayEl.setAttribute('aria-live', 'polite');
	body.appendChild(sayEl);
	var sayTimer = 0;
	studio.say = function (text) {
		window.clearTimeout(sayTimer);
		sayEl.textContent = '';
		/* Cleared first and set a moment later, so the same words twice are still read out. */
		sayTimer = window.setTimeout(function () { sayEl.textContent = String(text || ''); }, 60);
	};

	/*
	 * Dialogs (1.27.0). Every panel that opens over the studio comes through
	 * here: the ready dialog, An unfinished recording, A take that was cut off,
	 * A take from your phone, Check microphone, Warm up, Share for feedback and
	 * the cue menu. Focus moves to its heading as it opens, Tab stays inside
	 * it, Escape closes it where closing loses nothing (never while a take is
	 * recording), and focus goes back to what opened it.
	 */
	var dlgs = [];
	var dlgN = 0;
	var lastOutside = null;
	var FOCUSABLE = 'button, [href], input, select, textarea, audio[controls], video[controls], [tabindex]:not([tabindex="-1"])';
	function shown(n) {
		if (!n || n.disabled || n.hidden || !n.getClientRects().length) { return false; }
		return window.getComputedStyle(n).visibility !== 'hidden';
	}
	document.addEventListener('focusin', function (e) {
		if (e.target.closest ? !e.target.closest('.tp-result, .tp-cue-menu, [role="dialog"]') : false) { lastOutside = e.target; }
	});
	studio.dialogTop = function () {
		for (var i = dlgs.length - 1; i >= 0; i--) { if (shown(dlgs[i].box)) { return dlgs[i]; } }
		return null;
	};
	function recording() { return studio.recorder ? studio.recorder.state === 'recording' : false; }
	function dialogOpen(box, escape) {
		if (!box || box._rrtDlg) { return; }
		var a = document.activeElement;
		var d = { box: box, from: (a && a !== body && !box.contains(a)) ? a : lastOutside, escape: escape || null };
		/* A cue menu opens from a word, so it goes back to the script. */
		if (box.classList.contains('tp-cue-menu')) { d.from = track; }
		box._rrtDlg = d;
		dlgs.push(d);
		if (!box.getAttribute('role')) { box.setAttribute('role', 'dialog'); }
		box.setAttribute('aria-modal', 'true');
		var h = box.querySelector('h1, h2, h3');
		if (h) {
			if (!h.id) { h.id = 'tpDlgH' + (++dlgN); }
			h.tabIndex = -1;
			box.setAttribute('aria-labelledby', h.id);
			h.focus({ preventScroll: true });
		} else if (!box.contains(document.activeElement)) {
			box.tabIndex = -1;
			box.focus({ preventScroll: true });
		}
	}
	function dialogClosed(box) {
		var d = box._rrtDlg;
		if (!d) { return; }
		box._rrtDlg = null;
		dlgs = dlgs.filter(function (x) { return x !== d; });
		var a = document.activeElement;
		/* Focus goes back only when it was in the dialog (or lost with it), never away from something clicked since. */
		if (a && a !== body && document.contains(a) && !box.contains(a)) { return; }
		var next = studio.dialogTop();
		if (next) {
			var nh = next.box.querySelector('[tabindex="-1"]');
			(nh || next.box).focus({ preventScroll: true });
			return;
		}
		if (d.from && document.contains(d.from) && shown(d.from)) { d.from.focus({ preventScroll: true }); return; }
		if ((studio.rv ? studio.rv.open : false) && studio.rvFocus) { studio.rvFocus(); return; }
		toScript();
	}
	studio.dialogOpen = dialogOpen;
	studio.dialogClosed = dialogClosed;
	document.addEventListener('keydown', function (e) {
		var d = studio.dialogTop();
		if (!d || !d.box.contains(e.target)) { return; }
		if (e.key === 'Tab') {
			var f = Array.prototype.filter.call(d.box.querySelectorAll(FOCUSABLE), shown);
			if (!f.length) { e.preventDefault(); return; }
			var i = f.indexOf(document.activeElement);
			if (e.shiftKey ? i <= 0 : (i === -1 || i === f.length - 1)) {
				e.preventDefault();
				f[e.shiftKey ? f.length - 1 : 0].focus();
			}
		} else if (e.key === 'Escape' ? !recording() : false) {
			var close = d.escape || function () {
				var b = d.box.querySelector('[data-dlg-close]');
				if (b ? shown(b) : false) { b.click(); }
			};
			e.preventDefault();
			close();
		}
	});
	/* The four dialogs on the page, opened and closed by their is-on class. */
	[['tpResult', 'tpDismiss'], ['tpRecover', 'tpRecoverLater'], ['tpCarry', 'tpCarryLater'], ['tpSent', 'tpSentLater']].forEach(function (p) {
		var box = document.getElementById(p[0]);
		var esc = document.getElementById(p[1]);
		if (!box || !window.MutationObserver) { return; }
		if (esc) { esc.setAttribute('data-dlg-close', ''); }
		var was = box.classList.contains('is-on');
		new MutationObserver(function () {
			var on = box.classList.contains('is-on');
			if (on === was) { return; }
			was = on;
			if (on) {
				dialogOpen(box);
				if (p[0] === 'tpResult') { studio.say(__( 'Your reading is ready', 'foxfire-studio' )); }
			} else {
				dialogClosed(box);
			}
		}).observe(box, { attributes: true, attributeFilter: ['class'] });
	});
	/* The ones made when they open: Check microphone, Warm up, Share for feedback and the cue menu. */
	if (window.MutationObserver) {
		new MutationObserver(function (list) {
			list.forEach(function (m) {
				Array.prototype.forEach.call(m.addedNodes, function (n) {
					if (n.nodeType === 1 ? n.matches('.tp-mic, .tp-warm, .tp-share, .tp-cue-menu') : false) { dialogOpen(n); }
				});
				Array.prototype.forEach.call(m.removedNodes, function (n) {
					if (n.nodeType === 1 ? !!n._rrtDlg : false) { dialogClosed(n); }
				});
			});
		}).observe(body, { childList: true });
	}

	/*
	 * Help beside a control (1.27.0). Controls that explained themselves only
	 * in a hover tooltip get a small "?" button that opens the explanation
	 * right under them, and the control is described by it, so phones,
	 * keyboards and screen readers all get it. The tooltip stays for mice.
	 */
	var helpN = 0;
	function helpName(ctrl) {
		var n = ctrl.getAttribute('aria-label') || '';
		if (!n && ctrl.labels && ctrl.labels.length) { n = ctrl.labels[0].textContent; }
		if (!n) { n = ctrl.textContent; }
		return String(n).replace(/\s+/g, ' ').trim();
	}
	function help(ctrl, text, row) {
		if (!ctrl || ctrl._rrtHelp || !text) { return null; }
		row = row || ctrl;
		var id = 'tpHelp' + (++helpN);
		var wrap = document.createElement('span');
		wrap.className = 'tp-helped';
		row.parentNode.insertBefore(wrap, row);
		wrap.appendChild(row);
		var btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'tp-help-btn';
		btn.textContent = '?';
		btn.setAttribute('aria-label', sprintf( /* translators: %s: the name of a control, such as Clean up audio. */ __( 'About %s', 'foxfire-studio' ), helpName(ctrl) ));
		btn.setAttribute('aria-expanded', 'false');
		btn.setAttribute('aria-controls', id);
		var p = document.createElement('span');
		p.className = 'tp-help-text';
		p.id = id;
		p.hidden = true;
		p.textContent = text;
		wrap.appendChild(btn);
		wrap.appendChild(p);
		var by = ctrl.getAttribute('aria-describedby');
		ctrl.setAttribute('aria-describedby', by ? by + ' ' + id : id);
		btn.addEventListener('click', function () {
			p.hidden = !p.hidden;
			btn.setAttribute('aria-expanded', p.hidden ? 'false' : 'true');
		});
		btn.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' ? !p.hidden : false) { e.preventDefault(); e.stopPropagation(); p.hidden = true; btn.setAttribute('aria-expanded', 'false'); }
		});
		ctrl._rrtHelp = p;
		return p;
	}
	studio.help = help;
	/* A control whose tooltip changes keeps its help in step. */
	studio.helpText = function (ctrl, text) {
		if (ctrl ? ctrl._rrtHelp : false) { ctrl._rrtHelp.textContent = text; }
	};
	/* Every control of the page whose title explains it. */
	function helpAll(scope) {
		Array.prototype.forEach.call(scope.querySelectorAll('[title]'), function (n) {
			if (n.closest('.tp-nav, .tp-ed-edges, .tp-logo, .tp-top-end, .tp-panel-head, .tp-last-head, .tp-rv-timeline')) { return; }
			var t = n.getAttribute('title');
			var ctrl = n;
			var row = n;
			if (n.tagName === 'LABEL') {
				ctrl = n.control || n.querySelector('input, select');
				if (!ctrl) { return; }
				row = n.classList.contains('tp-switch') ? n : (n.closest('.tp-field, .tp-slider') || n);
			} else if (n.tagName === 'SELECT' || n.tagName === 'INPUT') {
				row = n.closest('.tp-field, .tp-slider') || n;
			} else if (n.tagName !== 'BUTTON') {
				return;
			}
			/* A title that only repeats the control's own name needs no help. */
			if (helpName(ctrl).toLowerCase() === t.toLowerCase()) { return; }
			help(ctrl, t, row);
		});
	}
	studio.helpAll = helpAll;
	['tpScriptBar', 'tpPanel', 'tpResult', 'tpReview'].forEach(function (id) {
		var n = document.getElementById(id);
		if (n) { helpAll(n); }
	});

	/* Recording starting and stopping, read out (1.27.0). */
	if (recEl && window.MutationObserver) {
		var recWas = recEl.classList.contains('is-on');
		new MutationObserver(function () {
			var on = recEl.classList.contains('is-on');
			if (on === recWas) { return; }
			recWas = on;
			studio.say(on ? __( 'Recording', 'foxfire-studio' ) : __( 'Recording stopped', 'foxfire-studio' ));
		}).observe(recEl, { attributes: true, attributeFilter: ['class'] });
	}

	var barTimer = 0;
	document.addEventListener('mousemove', function () {
		body.classList.add('show-bar');
		window.clearTimeout(barTimer);
		barTimer = window.setTimeout(function () { body.classList.remove('show-bar'); }, 1800);
	});

	/*
	 * What the check needs to say how much lift a take will need (1.9.0): the
	 * Settings preset's voice target and how far noise reduction lowers the
	 * room. Null with the simple leveling or the audiobook preset, which level
	 * another way.
	 */
	function micLift() {
		var SND = window.RRTSound, SO = studio.SOUND || null;
		if (!SND || !SND.plan || !SO || !SO.spec) { return null; }
		var pl = SND.plan(SO.spec);
		if (!pl || pl.mode !== 'podcast') { return null; }
		var NR = { off: 0, gentle: 12, medium: 20, strong: 30 };
		var dn = RRT.denoise ? RRT.denoise.strength : 'off';
		return { target: pl.target, shown: pl.fileTarget, mode: pl.mode, nrDb: NR.hasOwnProperty(dn) ? NR[dn] : 0 };
	}

	/* The practice reading from the Controls panel (1.33.1): in this tab, so one studio holds the microphone. */
	var practiceOpen = document.getElementById('tpPracticeOpen');
	if (practiceOpen) {
		practiceOpen.addEventListener('click', function () {
			if (studio.playing || counting) { return; }
			var to = practiceOpen.getAttribute('data-href');
			if (to) { window.location.href = to; }
		});
	}

	/* Microphone check (0.14.0). */
	var micNew = document.getElementById('tpMicNew');
	if (micNew ? offerNew : false) { micNew.hidden = false; }
	var practiceReady = __( 'Ready. Press Begin and read the practice script aloud. Warm up, in the Controls panel under Practice, is there first if you like.', 'foxfire-studio' );
	if (micBtn ? PR : false) {
		micLast.textContent = PR.summary(PR.lastCheck());
		micBtn.addEventListener('click', function () {
			if (studio.playing || counting) { return; }
			var words = W.map(function (w) { return w.el.textContent; });
			var sents = W.map(function (w) { return w.sent; });
			var blocks = W.map(function (w) { return w.block; });
			PR.micCheck({ firstLine: PR.firstLine(words, sents, blocks), lift: micLift(), phone: !!studio.PHONE, onDone: function (r) {
				micLast.textContent = PR.summary(r);
				/* New here (1.29.0): a check made ends the offer. */
				setWelcomed();
				if (micNew) { micNew.hidden = true; }
				if (PRACTICE) { msgEl.textContent = practiceReady; }
			} });
		});
	}
	/*
	 * The practice reading (1.29.0): the built-in script with no post. From
	 * the setup wizard, Check microphone opens by itself once the page is
	 * free (after the intro, and never over another dialog).
	 */
	if (PRACTICE) {
		msgEl.textContent = __( 'Practice reading: this script belongs to no post, so Use this recording is not offered. The take is kept with your other takes.', 'foxfire-studio' ) + ' ' + (studio.recPractice ? __( 'Recording is on for this practice.', 'foxfire-studio' ) : '');
		if (PRACTICE.check ? (micBtn ? PR : false) : false) {
			var tries = 0;
			var openCheck = function () {
				tries++;
				var busy = studio.playing || counting || !!document.querySelector('.tp-result.is-on, .tp-mic, .tp-warm, .tp-intro:not([hidden])');
				if (busy) {
					if (tries < 120) { window.setTimeout(openCheck, 500); }
					return;
				}
				micBtn.click();
			};
			window.setTimeout(openCheck, 300);
		}
	}

	/* Warm-up (0.15.0). */
	if (warmBtn ? PR : false) {
		warmBtn.addEventListener('click', function () {
			if (studio.playing || counting) { return; }
			studio.cueMenuClose();
			PR.warmup({ wpm: settings.wpm, onClose: function () { warmBtn.focus(); } });
		});
	} else if (warmBtn) {
		warmBtn.hidden = true;
	}
})();
