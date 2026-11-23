/*
 * Foxfire Studio: the review studio. Whisper, takes and reader stats, learned words, the
 * review screen (stats, problems, takes, timeline, the script with its
 * playback), Use this recording, captions, clips, sharing a take, the
 * whole review run, and opening a recording, a sent take or a recovered one.
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
	var RRT = studio.RRT;
	var describeReading = studio.describeReading;
	var compareReading = studio.compareReading;
	var body = studio.body;
	var track = studio.track;
	var msgEl = studio.msgEl;
	var resultEl = studio.resultEl;
	var resultAudio = studio.resultAudio;
	var resultInfo = studio.resultInfo;
	var downloadA = studio.downloadA;
	var downloadRawA = studio.downloadRawA;
	var readoutEl = studio.readoutEl;
	var whisperStatus = studio.whisperStatus;
	var openReviewBtn = studio.openReviewBtn;
	var reviewFileBtn = studio.reviewFileBtn;
	var fileIn = studio.fileIn;
	var PR = studio.PR;
	var POST_ID = studio.POST_ID;
	var WHISPER = studio.WHISPER;
	var SITE = studio.SITE;
	var settings = studio.settings;
	var LOW_MEM = studio.LOW_MEM;
	var RUN_KEY = studio.RUN_KEY;
	var takeStore = studio.takeStore;
	var forgetTake = studio.forgetTake;
	var wavBlob = studio.wavBlob;
	var SENT = studio.SENT;
	var sentUrl = studio.sentUrl;
	var sentDone = studio.sentDone;
	var slug = studio.slug;
	var W = studio.W;
	var cueOf = studio.cueOf;
	var fmt = studio.fmt;
	var togglePlay = studio.togglePlay;
	var soundPreset = studio.soundPreset;
	var toMp3 = studio.toMp3;
	var addChapters = studio.addChapters;
	var MUSIC = studio.MUSIC;
	var musicWanted = studio.musicWanted;
	var musicLead = studio.musicLead;
	var shiftVtt = studio.shiftVtt;
	var musicBuild = studio.musicBuild;
	var takeSummary = studio.takeSummary;
	var showReadout = studio.showReadout;
	var mp3Progress = studio.mp3Progress;
	studio.wantModel = wantModel;
	studio.transcribeWith = transcribeWith;
	studio.to16k = to16k;
	studio.el = el;
	studio.closeReview = closeReview;
	studio.buildVtt = buildVtt;
	studio.startReview = startReview;
	/* The read-out's measures with the review's pace in place of the quick estimate (1.31.0). */
	studio.paceFromReview = function (metrics, R) {
		var m = {};
		Object.keys(metrics || {}).forEach(function (k) { m[k] = metrics[k]; });
		if (R ? isFinite(R.wpm) && R.wpm > 0 : false) { m.wpm = R.wpm; }
		return m;
	};
	studio.openSent = openSent;
	studio.recoverTake = recoverTake;
	if (!track) { return; }

	/*
	 * Whisper, loaded by this page into a background worker so the screen
	 * stays responsive. Both the model and the code that runs it come from
	 * this site when their files are in uploads/rrt-whisper/, and from
	 * Hugging Face and jsDelivr otherwise. After the first load the browser
	 * keeps everything, so later reviews start quickly.
	 */
	var TJS_CDN = 'https://cdn.jsdelivr.net/npm/@huggingface/transformers@4.3.2/dist/transformers.min.js';
	var engine = null;
	studio.engineBusy = false;
	var engineLoaded = false;
	/* Which model the worker holds ('base' or 'small'), and the model behind the last review. */
	studio.engineModel = '';
	studio.usedModel = WHISPER.model;
	/* How long the last review took (0.16.0): loading the model, then listening. */
	studio.whisperLoadS = 0;
	studio.whisperListenS = 0;
	studio.whisperTimed = false;
	/*
	 * The graphics chip (1.17.0). Where the browser offers WebGPU on a real
	 * graphics chip (not a software stand-in) with half precision
	 * (shader-f16), the larger model runs there: about a third to a half of
	 * the reading's length, where the processor takes nearly twice it. Asked
	 * once a page; gpuFailed keeps a failure from being tried again.
	 */
	var gpuWait = null;
	/* How much slower than the take the larger model may hear a piece before Automatic hands over (1.30.0; the tests lower it). */
	studio.gpuPaceLimit = 1;
	var gpuFailed = '';
	studio.gpuOk = null;
	function gpuCheck() {
		if (gpuWait) { return gpuWait; }
		gpuWait = new Promise(function (res) {
			var g = navigator.gpu;
			/* Phones may ask too (1.31.0): Larger chosen by hand can then run on the chip. */
			if (!WHISPER.small || !g || typeof g.requestAdapter !== 'function') { res(false); return; }
			var t = window.setTimeout(function () { res(false); }, 5000);
			Promise.resolve().then(function () { return g.requestAdapter({ powerPreference: 'high-performance' }); }).then(function (a) {
				window.clearTimeout(t);
				var soft = a ? (a.isFallbackAdapter || (a.info ? a.info.isFallbackAdapter : false)) : true;
				res(soft ? false : !!(a.features ? a.features.has('shader-f16') : false));
			}, function () { window.clearTimeout(t); res(false); });
		}).then(function (ok) { studio.gpuOk = ok; return ok; });
		return gpuWait;
	}
	studio.gpuCheck = gpuCheck;
	gpuCheck();
	/*
	 * Which model a review uses: 'base', 'small' (the larger model on the
	 * processor) or 'gpu' (the larger model on the graphics chip, 1.17.0).
	 * Automatic, the default since 1.17.0, takes the larger model wherever
	 * the graphics chip can run it, and the standard one for opening drills
	 * and everywhere else.
	 */
	function wantModel() {
		if (!WHISPER.small) { return 'base'; }
		var gpu = studio.gpuOk === true && !gpuFailed;
		if (settings.wmodel === 'base') { return 'base'; }
		if (settings.wmodel === 'small') { return gpu ? 'gpu' : 'small'; }
		/* 1.31.0: on a phone or a small-memory device, Automatic means the standard model; Larger is a choice made by hand. */
		if (studio.PHONE || LOW_MEM) { return 'base'; }
		/* 1.30.0: where the larger model ran slower than the take on this graphics chip, Automatic uses the standard one. */
		return gpu && !studio.takeDrill && !settings.gpuSlow ? 'gpu' : 'base';
	}
	/*
	 * 1.33.0: a reading in any language but English uses the multilingual
	 * models (WHISPER.multi) with its language set; English keeps the
	 * English models, which hear English better.
	 */
	var LANG = studio.READ_LANG;
	var MODELS = !LANG.en && WHISPER.multi ? WHISPER.multi : WHISPER;
	function modelCfg(which) {
		var c;
		if (which === 'gpu') { c = { model: MODELS.small.model, fallback: '', host: MODELS.small.gpuHost || '', gpu: true, rev: MODELS.small.rev || '' }; }
		else if (which === 'small') { c = { model: MODELS.small.model, fallback: MODELS.small.fallback, host: MODELS.small.host, rev: LANG.en ? '' : MODELS.small.rev || '' }; }
		else { c = { model: MODELS.model, fallback: MODELS.fallback, host: MODELS.host, rev: LANG.en ? '' : MODELS.rev || '' }; }
		c.language = LANG.en ? '' : LANG.code;
		return c;
	}

	function getEngine() {
		if (engine) { return engine; }
		/* The worker itself is in whisper.js (0.25.1), shared with the clip maker. */
		engine = window.RRTWhisper.start();
		return engine;
	}
	studio.engineFrom = '';

	/*
	 * Transcribes with the model wantModel() picks. If the graphics chip
	 * cannot load or run it (1.17.0), the review runs on the processor as it
	 * did before: the larger model when the reader chose Larger, the standard
	 * one for Automatic. If the larger model cannot load or run on the
	 * processor (too little memory, say), the standard one is used instead.
	 * The review says which happened.
	 */
	function transcribe(audio16k, onStatus) {
		return gpuCheck().then(function () {
			var which = wantModel();
			if (which === 'base') { return transcribeWith(audio16k, onStatus, 'base'); }
			if (which === 'small') { return smallOnProcessor(audio16k, onStatus); }
			var copy = audio16k.slice();
			/*
			 * Automatic times the larger model (1.30.0): the second piece (the
			 * first also warms the graphics chip up) is timed, and if it took
			 * longer than its own length, this browser remembers it and the
			 * standard model hears the rest. A graphics chip that passes the
			 * check can still be slow (Firefox on a laptop's integrated chip
			 * took about 4.7 times the take's length).
			 */
			var ctl = settings.wmodel === 'auto' ? { judge: function (d, secs) {
				if (d.i !== 1 || secs <= (d.b - d.a) * studio.gpuPaceLimit) { return false; }
				settings.gpuSlow = true;
				if (studio.saveSettings) { studio.saveSettings(); }
				return d.i < d.n - 1;
			} } : null;
			return transcribeWith(audio16k, onStatus, 'gpu', ctl).then(function (r) {
				if (!r || !r.handover) { return r; }
				onStatus(__( 'The larger model is slower than your reading on this browser\'s graphics chip, so the standard model is hearing the rest...', 'foxfire-studio' ));
				return transcribeWith(copy, onStatus, 'base', { fromS: r.at }).then(function (rest) {
					studio.whisperListenS += r.listenS;
					studio.whisperLoadS += r.loadS;
					studio.engineFrom = sprintf( /* translators: %s: where Whisper was served from, then '; ', or nothing. */ __( '%sthe larger model ran slower than the take on this browser\'s graphics chip, so the standard model finished the review (choose Larger in the Model menu, Controls panel, Recording, to use it anyway)', 'foxfire-studio' ), studio.engineFrom ? studio.engineFrom + '; ' : '' );
					return r.chunks.concat(rest);
				});
			})['catch'](function (err) {
				var why = err ? err.message : __( 'unknown error', 'foxfire-studio' );
				gpuFailed = why;
				studio.engineFrom = '';
				onStatus(sprintf( /* translators: %s: the reason. */ __( 'The graphics chip could not run Whisper here (%s), so it is listening on the processor instead...', 'foxfire-studio' ), why ));
				var next = settings.wmodel === 'small' ? smallOnProcessor(copy, onStatus) : transcribeWith(copy, onStatus, 'base');
				return next.then(function (chunks) {
					studio.engineFrom = sprintf( /* translators: %s: engineFrom ? engineFrom + '; ' : ''. */ __( '%sthe graphics chip could not run it, so the processor was used', 'foxfire-studio' ), studio.engineFrom ? studio.engineFrom + '; ' : '' );
					return chunks;
				});
			});
		});
	}
	function smallOnProcessor(audio16k, onStatus) {
		var copy = audio16k.slice();
		return transcribeWith(audio16k, onStatus, 'small')['catch'](function (err) {
			studio.engineFrom = '';
			onStatus(sprintf( /* translators: %s: err ? err.message : __( 'unknown error', 'foxfire-studio' ). */ __( 'The larger model could not run here (%s), so the standard model is listening instead...', 'foxfire-studio' ), err ? err.message : __( 'unknown error', 'foxfire-studio' ) ));
			return transcribeWith(copy, onStatus, 'base').then(function (chunks) {
				studio.engineFrom = sprintf( /* translators: %s: engineFrom ? engineFrom + '; ' : ''. */ __( '%sthe larger model could not run here, so the standard one was used', 'foxfire-studio' ), studio.engineFrom ? studio.engineFrom + '; ' : '' );
				return chunks;
			});
		});
	}
	/*
	 * ctl (1.30.0, optional): judge( piece, seconds ) is asked after each
	 * piece and returns true to stop there, handing back { handover, chunks,
	 * at (seconds heard), listenS, loadS }; fromS starts from that point.
	 */
	function transcribeWith(audio16k, onStatus, which, ctl) {
		/* A worker holds one model; switching models starts a fresh one. */
		if (engine ? studio.engineModel !== which : false) {
			try { engine.terminate(); } catch (e) {}
			engine = null;
			engineLoaded = false;
		}
		studio.engineModel = which;
		var mc = modelCfg(which);
		var larger = which !== 'base';
		return new Promise(function (resolve, reject) {
			var w;
			try { w = getEngine(); } catch (e) { reject(new Error(__( 'This browser cannot run background workers.', 'foxfire-studio' ))); return; }
			var files = {};
			var started = 0;
			var asked = Date.now();
			var tick = 0;
			var lastPiece = 0;
			var heard = [];
			function finish() {
				try { window.localStorage.removeItem(RUN_KEY); } catch (e) {}
				w.onmessage = null;
				w.onerror = null;
				window.clearInterval(tick);
				studio.engineBusy = false;
			}
			studio.engineBusy = true;
			w.onmessage = function (e) {
				var d = e.data;
				if (d.type === 'load') {
					if (d.p.status === 'progress' && d.p.total) { files[d.p.file] = [d.p.loaded, d.p.total]; }
					var got = 0;
					var all = 0;
					Object.keys(files).forEach(function (f) { got += files[f][0]; all += files[f][1]; });
					if (all > 0 && !engineLoaded) {
						onStatus(sprintf( /* translators: 1: which === 'small' ? __( 'the larger Whisper model', 'foxfire, 2: Math.round(got / 1048576), 3: Math.round(all / 1048576). */ __( 'Loading %1$s for the first time: %2$s of %3$s MB. Your browser keeps it after this.', 'foxfire-studio' ), larger ? __( 'the larger Whisper model', 'foxfire-studio' ) : __( 'Whisper', 'foxfire-studio' ), Math.round(got / 1048576), Math.round(all / 1048576) ));
					}
				} else if (d.type === 'working') {
					engineLoaded = true;
					started = Date.now();
					if (d.from) {
						studio.engineFrom = d.from.code === d.from.model ? sprintf( /* translators: %s: d.from.model. */ __( 'served from %s', 'foxfire-studio' ), d.from.model ) : sprintf( /* translators: 1: d.from.model, 2: d.from.code. */ __( 'model from %1$s, code from %2$s', 'foxfire-studio' ), d.from.model, d.from.code );
						if (which === 'small') { studio.engineFrom = sprintf( /* translators: %s: engineFrom. */ __( 'larger model, %s', 'foxfire-studio' ), studio.engineFrom ); }
						if (which === 'gpu') { studio.engineFrom = sprintf( /* translators: %s: engineFrom. */ __( 'larger model on the graphics chip, %s', 'foxfire-studio' ), studio.engineFrom ); }
					}
					var label = which === 'gpu' ? sprintf( /* translators: %s: engineFrom. */ __( 'Listening on the graphics chip: Whisper is hearing your reading (%s)... ', 'foxfire-studio' ), studio.engineFrom ) : sprintf( /* translators: %s: engineFrom. */ __( 'Whisper is listening to your reading (%s)... ', 'foxfire-studio' ), studio.engineFrom );
					onStatus(label + '0:00');
					tick = window.setInterval(function () {
						onStatus(label + fmt((Date.now() - started) / 1000));
					}, 1000);
				} else if (d.type === 'piece') {
					var nowT = Date.now();
					var secs = (nowT - (lastPiece || started || nowT)) / 1000;
					lastPiece = nowT;
					heard = heard.concat(d.chunks || []);
					if (ctl && ctl.judge ? ctl.judge(d, secs) : false) {
						finish();
						try { w.terminate(); } catch (e) {}
						engine = null;
						engineLoaded = false;
						studio.whisperTimed = started > 0;
						resolve({ handover: true, chunks: heard, at: d.b, listenS: started ? (nowT - started) / 1000 : 0, loadS: started ? (started - asked) / 1000 : 0 });
					}
				} else if (d.type === 'done') {
					finish();
					studio.usedModel = mc.model;
					studio.whisperListenS = started ? (Date.now() - started) / 1000 : 0;
					studio.whisperTimed = started > 0;
					studio.whisperLoadS = started ? (started - asked) / 1000 : 0;
					resolve(d.chunks);
				} else if (d.type === 'error') {
					finish();
					/* A worker whose model failed to load is not reused. */
					if (!engineLoaded) { try { w.terminate(); } catch (e) {} engine = null; }
					reject(new Error(d.message));
				}
			};
			w.onerror = function () {
				finish();
				engine = null;
				reject(new Error(__( 'Whisper could not start in this browser.', 'foxfire-studio' )));
			};
			onStatus(__( 'Preparing Whisper...', 'foxfire-studio' ));
			/* Cleared when Whisper finishes; still there on the next visit means the page was closed under it. */
			try { window.localStorage.setItem(RUN_KEY, JSON.stringify({ at: Date.now() })); } catch (e) {}
			var ua = navigator.userAgent;
			var oldSafari = /Safari/.test(ua) && !/Chrome|Chromium|Edg|Android/.test(ua) && !navigator.gpu;
			w.postMessage({ cfg: { site: SITE, model: mc.model, fallback: mc.fallback, host: mc.host, gpu: !!mc.gpu, rev: mc.rev || '', language: mc.language || '', lib: WHISPER.lib, wasm: WHISPER.wasm, cdn: TJS_CDN, oldSafari: oldSafari, lowMem: LOW_MEM, fromS: ctl ? ctl.fromS || 0 : 0 }, audio: audio16k }, [audio16k.buffer]);
		});
	}

	function to16k(samples, rate) {
		var OAC = window.OfflineAudioContext || window.webkitOfflineAudioContext;
		var len = Math.max(1, Math.ceil(samples.length * 16000 / rate));
		var off = new OAC(1, len, 16000);
		var buf = off.createBuffer(1, samples.length, rate);
		buf.copyToChannel(samples, 0);
		var src = off.createBufferSource();
		src.buffer = buf;
		src.connect(off.destination);
		src.start(0);
		return off.startRendering().then(function (r) { return new Float32Array(r.getChannelData(0)); });
	}

	/*
	 * Takes are remembered per post as small summaries. With reader stats on
	 * the server (0.9.0), the site is the record and this browser keeps a
	 * copy, so the take comparison works at once and on any computer.
	 */
	var TAKES_KEY = 'rrtTakes_' + (POST_ID || slug);
	var STATS = RRT.stats || null;
	studio.STATS = STATS;
	studio.takeMode = 'timed';
	function api(path, body, method) {
		var opts = { method: method || (body ? 'POST' : 'GET'), credentials: 'same-origin', headers: { 'X-WP-Nonce': STATS.nonce } };
		if (body instanceof FormData) {
			opts.body = body;
		} else if (body) {
			opts.headers['Content-Type'] = 'application/json';
			opts.body = JSON.stringify(body);
		}
		/* Sites with plain permalinks give an address that already has a query string. */
		var q = path.indexOf('?');
		var url = STATS.rest + (q < 0 ? path : path.slice(0, q) + (STATS.rest.indexOf('?') < 0 ? '?' : '&') + path.slice(q + 1));
		return fetch(url, opts).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (j) {
				if (!r.ok) { throw new Error(j && j.message ? j.message : sprintf( /* translators: %s: r.status. */ __( 'HTTP %s', 'foxfire-studio' ), r.status )); }
				return j;
			});
		});
	}
	/* Once per reader and browser: copy what this browser kept before 0.9.0 onto the site. */
	function importOldHistory() {
		var flag = 'rrtImported_' + STATS.user;
		try { if (window.localStorage.getItem(flag)) { return Promise.resolve(); } } catch (e) { return Promise.resolve(); }
		var takes = {};
		var n = 0;
		try {
			for (var i = 0; i < window.localStorage.length; i++) {
				var k = window.localStorage.key(i);
				var m = /^rrtTakes_(\d+)$/.exec(k || '');
				if (!m) { continue; }
				var list = JSON.parse(window.localStorage.getItem(k) || '[]');
				if (Array.isArray(list) ? list.length : false) { takes[m[1]] = list; n += list.length; }
			}
		} catch (e) {}
		/* 1.33.0: the words kept before 0.9.0 were all English. */
		var L = LANG.en ? loadLearned() : { pairs: {}, ignore: {}, labels: {}, at: {}, forgot: {} };
		var hasWords = Object.keys(L.pairs).length + Object.keys(L.ignore).length > 0;
		if (!n ? !hasWords : false) {
			try { window.localStorage.setItem(flag, '1'); } catch (e) {}
			return Promise.resolve();
		}
		return api('import', { takes: takes, learned: L }).then(function () {
			try { window.localStorage.setItem(flag, '1'); } catch (e) {}
		});
	}
	var statsReady = Promise.resolve();
	var lastTakeAt = 0;
	if (STATS) {
		/* Started once the rest of the page's set-up has run (taught words are defined further down). */
		statsReady = new Promise(function (resolve) { window.setTimeout(resolve, 0); }).then(importOldHistory).then(function () {
			var jobs = [api('learned' + LEARN_Q).then(function (j) {
				var L = loadLearned();
				var S = j.learned || {};
				/*
				 * Since 1.15.0 the two copies are merged with their times, so a word
				 * forgotten elsewhere (another browser, or the Pronunciation screen)
				 * stays forgotten here, and a word taught only here is sent.
				 */
				var M = PR ? PR.mergeLearned(S, L) : S;
				try { window.localStorage.setItem(LEARN_KEY, JSON.stringify(M)); } catch (e) {}
				if (PR ? !PR.learnedSame(M, S) : false) { return api('learned' + LEARN_Q, { learned: M }); }
			})];
			/* Practice takes (1.29.0) are kept as post 0. */
			if (POST_ID || RRT.practice) {
				jobs.push(api('takes?post=' + POST_ID).then(function (j) {
					var local = loadTakes();
					var server = Array.isArray(j.takes) ? j.takes : [];
					var seen = {};
					server.forEach(function (t) { seen[t.at] = true; });
					/* A take made here while the site could not be reached is sent now. */
					var missing = local.filter(function (t) { return !seen[t.at]; });
					missing.forEach(function (t) { api('takes', { post: POST_ID, summary: t }).catch(function () {}); });
					var all = server.concat(missing).sort(function (a, b) { return a.at - b.at; });
					try { window.localStorage.setItem(TAKES_KEY, JSON.stringify(all.slice(-50))); } catch (e) {}
				}));
			}
			return Promise.all(jobs);
		}).catch(function () {});
	}
	function loadTakes() {
		try {
			var t = JSON.parse(window.localStorage.getItem(TAKES_KEY) || '[]');
			return Array.isArray(t) ? t : [];
		} catch (e) { return []; }
	}
	function saveTake(sum, R) {
		var t = loadTakes();
		t.push(sum);
		var keep = STATS ? 50 : 12;
		if (t.length > keep) { t = t.slice(t.length - keep); }
		try { window.localStorage.setItem(TAKES_KEY, JSON.stringify(t)); } catch (e) {}
		sendTake(sum, R);
		return t;
	}
	/* The flag list, small enough to keep: what, when, and the words. */
	function flagsOf(R) {
		return (R ? R.problems : []).slice(0, 400).map(function (p) {
			return { kind: p.kind, t: isFinite(p.s) ? Math.round(p.s * 100) / 100 : null, text: (String(p.label || '') + ': ' + String(p.detail || '')).slice(0, 160) };
		});
	}
	function sendTake(sum, R) {
		if (!STATS || !(POST_ID || RRT.practice)) { return; }
		statsReady.then(function () {
			return api('takes', { post: POST_ID, summary: sum, flags: R ? flagsOf(R) : null });
		}).catch(function () {});
	}

	/*
	 * Words Whisper has learned: pairs you have marked as said correctly
	 * ("Siobhan" heard as "shivon") and extra words it imagined. Kept in this
	 * browser for every post, so your vocabulary only needs teaching once.
	 */
	/* 1.33.0: kept per language, so a word taught in one language never changes a review in another. */
	var LEARN_KEY = 'rrtLearned' + (LANG.en ? '' : '_' + LANG.code);
	var LEARN_Q = LANG.en ? '' : '?lang=' + encodeURIComponent(LANG.code);
	function loadLearned() {
		var L = null;
		try { L = JSON.parse(window.localStorage.getItem(LEARN_KEY) || 'null'); } catch (e) { L = null; }
		if (!L || typeof L !== 'object') { L = {}; }
		/*
		 * The site sends an empty set as [] (a PHP empty array), and words
		 * added to an array are lost when saved, so a reader with no taught
		 * words could never teach the first one. Fixed in 0.16.0.
		 */
		['pairs', 'ignore', 'labels', 'at', 'forgot'].forEach(function (k) { if (!L[k] || typeof L[k] !== 'object' || Array.isArray(L[k])) { L[k] = {}; } });
		return L;
	}
	/* Since 1.15.0 the site always merges, keeping forgotten words forgotten, so a set is never sent to replace. */
	function saveLearned(L) {
		try { window.localStorage.setItem(LEARN_KEY, JSON.stringify(L)); } catch (e) {}
		if (STATS) { statsReady.then(function () { return api('learned' + LEARN_Q, { learned: L }); }).then(function (j) {
			/* The site's answer holds words taught or forgotten elsewhere too. */
			if (j ? !!j.learned : false) { try { window.localStorage.setItem(LEARN_KEY, JSON.stringify(PR ? PR.mergeLearned(j.learned, loadLearned()) : j.learned)); } catch (e) {} }
		}).catch(function () {}); }
	}

	/*
	 * Learned words per post (0.16.0): words taught for this post only, kept
	 * with the post on the site (RRT.learnedPost). If a save fails, the set
	 * waits in this browser and is sent with the next change or visit.
	 */
	var PL = RRT.learnedPost || null;
	var PL_LOCAL = 'rrtLearnedPostPending_' + POST_ID;
	function emptyLearned(L) {
		L = (L ? typeof L === 'object' : false) ? L : {};
		['pairs', 'ignore', 'labels', 'at', 'forgot'].forEach(function (k) { if (!L[k] || typeof L[k] !== 'object' || Array.isArray(L[k])) { L[k] = {}; } });
		return L;
	}
	var postLearned = emptyLearned(PL ? PL.learned : null);
	var plPending = false;
	(function () {
		var pend = null;
		try { pend = JSON.parse(window.localStorage.getItem(PL_LOCAL) || 'null'); } catch (e) {}
		if (pend ? !!pend.learned : false) { postLearned = emptyLearned(pend.learned); plPending = true; }
	})();
	function savePostLearned() {
		try { window.localStorage.setItem(PL_LOCAL, JSON.stringify({ at: Date.now(), learned: postLearned })); } catch (e) {}
		if (!PL || !PL.rest) { return Promise.resolve(); }
		return window.fetch(PL.rest, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': PL.nonce },
			/* Merged on the site with its own copy (1.15.0), so words forgotten on the Pronunciation screen stay forgotten. */
			body: JSON.stringify({ post: POST_ID, learned: postLearned })
		}).then(function (r) {
			if (!r.ok) { throw new Error(sprintf( /* translators: %s: r.status. */ __( 'HTTP %s', 'foxfire-studio' ), r.status )); }
			return r.json();
		}).then(function (j) {
			try { window.localStorage.removeItem(PL_LOCAL); } catch (e) {}
			plPending = false;
			if (j ? !!j.learned : false) { postLearned = emptyLearned(j.learned); }
		})['catch'](function () {
			plPending = true;
			msgEl.textContent = __( 'The words taught for this post could not be saved to the site just now. They are kept in this browser and will be sent with the next change or visit.', 'foxfire-studio' );
		});
	}
	if (plPending ? !!PL : false) { savePostLearned(); }
	/* Every taught word that applies to this post: the reader's words for every post, plus this post's own. */
	function allLearned() {
		var L = loadLearned();
		var out = { pairs: {}, ignore: {}, labels: {} };
		[L, postLearned].forEach(function (src) {
			Object.keys(src.pairs).forEach(function (sk) {
				out.pairs[sk] = out.pairs[sk] || {};
				Object.keys(src.pairs[sk]).forEach(function (hk) { out.pairs[sk][hk] = 1; });
			});
			Object.keys(src.ignore).forEach(function (w) { out.ignore[w] = 1; });
		});
		return out;
	}

	function scriptForReview() {
		return W.map(function (w) { return { key: w.key, block: w.block, sent: w.sent, text: w.el.textContent }; });
	}

	/* The review studio */
	var rv = {
		el: document.getElementById('tpReview'),
		audio: document.getElementById('tpRvAudio'),
		stats: document.getElementById('tpRvStats'),
		notes: document.getElementById('tpRvNotes'),
		problems: document.getElementById('tpRvProblems'),
		filterNote: document.getElementById('tpRvFilter'),
		takes: document.getElementById('tpRvTakes'),
		script: document.getElementById('tpRvScript'),
		timeline: document.getElementById('tpRvTimeline'),
		head: document.getElementById('tpRvHeadInfo'),
		vtt: document.getElementById('tpRvVtt'),
		chapters: document.getElementById('tpRvChapters'),
		closeBtn: document.getElementById('tpRvClose'),
		learned: document.getElementById('tpRvLearned'),
		side: document.querySelector('.tp-rv-side'),
		last: null,
		open: false,
		R: null,
		timed: [],
		spans: [],
		playing: -1,
		filter: '',
		/* Flags after edits (1.1.0): which flags the edits cover, and the edited version once checked. */
		view: 'recorded',
		edited: null,
		cover: null,
		cutKey: '[]',
		checking: false,
		editedAudio: null,
		checkBtn: document.getElementById('tpEdCheck')
	};
	studio.rv = rv;

	function el(tag, cls, txt) {
		var e = document.createElement(tag);
		if (cls) { e.className = cls; }
		if (txt !== undefined) { e.textContent = txt; }
		return e;
	}
	function playFrom(t) {
		if (rv.editedAudio) { rv.editedAudio.pause(); }
		rv.audio.currentTime = Math.max(0, t);
		var p = rv.audio.play();
		if (p && p.catch) { p.catch(function () {}); }
	}

	/* Focus (1.27.0): the review's heading as it opens, and back where it came from as it closes. */
	var rvTitle = document.getElementById('tpRvTitle');
	studio.rvFocus = function () { if (rvTitle) { rvTitle.focus({ preventScroll: true }); } };
	function openReview() {
		if (!rv.R) { return; }
		rv.el.hidden = false;
		rv.open = true;
		body.classList.add('is-reviewing');
		if (studio.playing) { togglePlay(); }
		studio.rvFocus();
	}
	function closeReview() {
		if (rv.editedAudio) { rv.editedAudio.pause(); }
		rv.el.hidden = true;
		rv.open = false;
		body.classList.remove('is-reviewing');
		rv.audio.pause();
		/* The ready dialog shows again behind a review opened from it; otherwise back to the script. */
		var d = studio.dialogTop ? studio.dialogTop() : null;
		if (d) {
			var h = d.box.querySelector('[tabindex="-1"]');
			(h || d.box).focus({ preventScroll: true });
		} else if (studio.toScript) {
			studio.toScript();
		}
	}
	/* The full review being ready, read out (1.27.0). */
	if (openReviewBtn && window.MutationObserver) {
		var readyWas = !openReviewBtn.hidden;
		new MutationObserver(function () {
			var on = !openReviewBtn.hidden;
			if (on === readyWas) { return; }
			readyWas = on;
			if (on) { studio.say(__( 'The full review is ready', 'foxfire-studio' )); }
		}).observe(openReviewBtn, { attributes: true, attributeFilter: ['hidden'] });
	}
	rv.closeBtn.addEventListener('click', closeReview);

	var KIND_NAMES = {
		omit: __( 'Skipped words', 'foxfire-studio' ), sub: __( 'Misread words', 'foxfire-studio' ), check: __( 'Words worth a listen', 'foxfire-studio' ), added: __( 'Added words', 'foxfire-studio' ), repeat: __( 'Repeats', 'foxfire-studio' ), filler: __( 'Fillers', 'foxfire-studio' ),
		pause: __( 'Long pauses', 'foxfire-studio' ), unfinished: __( 'Unfinished sentences', 'foxfire-studio' ), rough: __( 'Paraphrased sentences', 'foxfire-studio' ), rushed: __( 'Rushed sentences', 'foxfire-studio' ),
		slow: __( 'Slow sentences', 'foxfire-studio' ), hesitant: __( 'Hesitant sentences', 'foxfire-studio' ), flat: __( 'Flat sentences', 'foxfire-studio' ), faded: __( 'Faded endings', 'foxfire-studio' )
	};

	function renderStats(R, seconds, prev, covered) {
		rv.stats.innerHTML = '';
		var sum = takeSummary(R, seconds, rv.last && rv.last.metrics ? rv.last.metrics.room : null);
		var tiles = [
			{ label: __( 'Script accuracy', 'foxfire-studio' ), value: sum.acc.toFixed(1) + '%', key: 'acc', better: 1, filter: '', wide: true },
			{ label: __( 'Skipped', 'foxfire-studio' ), value: sum.omit, key: 'omit', better: -1, filter: 'omit' },
			{ label: __( 'Misread', 'foxfire-studio' ), value: sum.sub, key: 'sub', better: -1, filter: 'sub' },
			{ label: __( 'Worth a listen', 'foxfire-studio' ), value: sum.check || 0, key: 'check', better: 0, filter: 'check' },
			{ label: __( 'Added', 'foxfire-studio' ), value: sum.added, key: 'added', better: -1, filter: 'added' },
			{ label: __( 'Repeats', 'foxfire-studio' ), value: sum.repeat, key: 'repeat', better: -1, filter: 'repeat' },
			{ label: __( 'Fillers', 'foxfire-studio' ), value: sum.filler, key: 'filler', better: -1, filter: 'filler' },
			{ label: __( 'Pace', 'foxfire-studio' ), value: sprintf( /* translators: %s: sum.wpm. */ __( '%s wpm', 'foxfire-studio' ), sum.wpm ), key: 'wpm', better: 0, filter: 'rushed,slow' },
			{ label: __( 'Long pauses', 'foxfire-studio' ), value: sum.pauses, key: 'pauses', better: 0, filter: 'pause,hesitant' },
			{ label: __( 'Reading time', 'foxfire-studio' ), value: fmt(seconds), key: 'secs', better: 0, filter: '' },
			{ label: __( 'To retake', 'foxfire-studio' ), value: sum.retake, key: 'retake', better: -1, filter: 'retake' }
		];
		tiles.forEach(function (t) {
			var b = el('button', 'tp-rv-tile' + (t.wide ? ' is-wide' : ''));
			b.type = 'button';
			b.appendChild(el('span', 'tp-rv-tile-label', t.label));
			/* Flags after edits (1.1.0): a count the edits have covered shows what is left. */
			var gone = covered ? (covered[t.key] || 0) : 0;
			b.appendChild(el('span', 'tp-rv-tile-value', String(gone > 0 ? Math.max(0, t.value - gone) : t.value)));
			if (gone > 0) {
				b.appendChild(el('span', 'tp-rv-tile-delta is-good', sprintf( /* translators: %s: the count before the edits. */ __( '%s before your edits', 'foxfire-studio' ), t.value )));
			} else if (prev && typeof prev[t.key] === 'number') {
				var d = sum[t.key] - prev[t.key];
				if (Math.abs(d) >= (t.key === 'acc' ? 0.5 : 1)) {
					var good = t.better === 0 ? null : (d * t.better > 0);
					var shown = t.key === 'acc' ? sprintf( /* translators: %s: Math.abs(d).toFixed(1). */ __( '%s pts', 'foxfire-studio' ), Math.abs(d).toFixed(1) ) : (t.key === 'secs' ? fmt(Math.abs(d)) : String(Math.abs(d)));
					var word = (d > 0 ? '+' : '−') + shown;
					b.appendChild(el('span', 'tp-rv-tile-delta' + (good === null ? '' : (good ? ' is-good' : ' is-bad')), sprintf( /* translators: %s: word. */ __( '%s since last take', 'foxfire-studio' ), word )));
				}
			}
			if (t.filter) {
				b.addEventListener('click', function () { setFilter(rv.filter === t.filter ? '' : t.filter); });
			} else {
				b.classList.add('is-static');
			}
			b.setAttribute('data-filter', t.filter);
			rv.stats.appendChild(b);
		});
		focusTile(sum);
		goalsTile(sum);
		return sum;
	}

	/* Practice focus (0.14.0): the habit being worked on leads the review. */
	function focusTile(sum) {
		var f = PR ? PR.focuses[sum.focus] : null;
		if (!f) { return; }
		var v = sum[f.key];
		var b = el('div', 'tp-rv-tile is-wide is-static tp-rv-focus');
		b.appendChild(el('span', 'tp-rv-tile-label', sprintf( /* translators: %s: f.label. */ __( 'Your focus: %s', 'foxfire-studio' ), f.label )));
		b.appendChild(el('span', 'tp-rv-tile-value', typeof v === 'number' ? (f.key === 'acc' ? v.toFixed(1) : String(v)) + f.unit : __( 'Not measured in this take', 'foxfire-studio' )));
		var p = rv.prevFocus;
		if (p ? typeof p[f.key] === 'number' : false) {
			if (typeof v === 'number') {
				var d = Math.round((v - p[f.key]) * 10) / 10;
				var good = f.better === 0 ? null : (d * f.better > 0);
				var txt = d === 0 ? __( 'the same as', 'foxfire-studio' ) : sprintf( /* translators: %s: (d > 0 ? '+' : '−') + Math.abs(d) + f.unit. */ __( '%s compared with', 'foxfire-studio' ), (d > 0 ? '+' : '−') + Math.abs(d) + f.unit );
				b.appendChild(el('span', 'tp-rv-tile-delta' + (good === null || d === 0 ? '' : (good ? ' is-good' : ' is-bad')), sprintf( /* translators: %s: txt. */ __( '%s your last take on this focus', 'foxfire-studio' ), txt )));
			}
		} else {
			b.appendChild(el('span', 'tp-rv-tile-delta', __( 'The first take with this focus. The next one will be compared with it.', 'foxfire-studio' )));
		}
		if (f.kinds.length) { b.appendChild(el('span', 'tp-rv-tile-delta', sprintf( /* translators: %s: f.kinds.map(function (k) { return KIND_NAMES[k].toLowerCase(. */ __( 'The flags below start with %s; click any tile to change.', 'foxfire-studio' ), f.kinds.map(function (k) { return KIND_NAMES[k].toLowerCase(); }).join(' ' + __( 'and', 'foxfire-studio' ) + ' ') ))); }
		rv.stats.insertBefore(b, rv.stats.firstChild);
	}

	/* Goals (0.11.0): each of the reader's goals, met or not by this take. */
	var GOAL_KEYS = { wpm: 'wpm', accuracy: 'acc', fillers: 'filler', long_pauses: 'pauses', retakes: 'retake', ending_drop: 'endDrop', pitch: 'pitch', energy: 'energy', room: 'snr' };
	function goalsTile(sum) {
		var goals = STATS ? (STATS.goals || []) : [];
		if (!goals.length) { return; }
		var met = 0;
		var n = 0;
		var parts = [];
		goals.forEach(function (g) {
			var v = sum[GOAL_KEYS[g.metric]];
			if (typeof v !== 'number') { return; }
			var ok = true;
			if (g.min !== null) { if (v < g.min) { ok = false; } }
			if (g.max !== null) { if (v > g.max) { ok = false; } }
			n++;
			if (ok) { met++; }
			parts.push(sprintf( /* translators: 1: (ok ? '✓ ' : '✗ ') + g.text, 2: v. */ __( '%1$s (this take: %2$s)', 'foxfire-studio' ), (ok ? '✓ ' : '✗ ') + g.text, v ));
		});
		if (!n) { return; }
		var b = el('a', 'tp-rv-tile is-wide is-static tp-rv-goals');
		b.href = STATS.progress || '#';
		b.target = '_blank';
		b.rel = 'noopener';
		b.appendChild(el('span', 'tp-rv-tile-label', __( 'Your goals', 'foxfire-studio' )));
		b.appendChild(el('span', 'tp-rv-tile-value', sprintf( /* translators: 1: met, 2: n. */ __( '%1$s of %2$s met', 'foxfire-studio' ), met, n )));
		parts.forEach(function (p) { b.appendChild(el('span', 'tp-rv-tile-delta' + (p.charAt(0) === '✓' ? ' is-good' : ' is-bad'), p)); });
		rv.stats.appendChild(b);
	}

	function setFilter(f) {
		rv.filter = f;
		Array.prototype.forEach.call(rv.stats.children, function (b) {
			b.classList.toggle('is-on', !!f && b.getAttribute('data-filter') === f);
		});
		renderProblems();
	}

	/*
	 * Pronunciation notes in the review (1.1.0). noteOf(k) is script word k's
	 * note, or ''. Words Whisper misheard that have a note can be taught all
	 * at once, and teaching a word with no note offers to add one.
	 */
	function noteOf(k) {
		var f = studio.noteAt ? studio.noteAt(k) : null;
		return f ? f.n : '';
	}
	/* The hover text on each script word: what it had before, with its note. */
	function noteTitles() {
		rv.spans.forEach(function (sp, k) {
			var base = sp.getAttribute('data-base-title') || '';
			var n = noteOf(k);
			var say = n ? sprintf( /* translators: %s: how to say the word, as the reader wrote it. */ __( 'Say it as %s', 'foxfire-studio' ), n ) : '';
			var t = base ? (say ? base + '. ' + say : base) : say;
			if (t) { sp.title = t; } else { sp.removeAttribute('title'); }
		});
	}
	/* Misheard words with a note that have not been taught yet. */
	function notedToTeach(R) {
		return R.problems.filter(function (p) { return p.teach ? (p.kind !== 'added' ? !!noteOf(p.idx) : false) : false; });
	}
	function scopeButtons(box, label, go) {
		box.appendChild(el('span', '', label));
		var opts = PL ? [['all', __( 'In every post', 'foxfire-studio' )], ['post', __( 'Just this post', 'foxfire-studio' )]] : [['all', __( 'Teach them', 'foxfire-studio' )]];
		opts.forEach(function (o) {
			var c = el('button', 'tp-rv-teach', o[1]);
			c.type = 'button';
			c.setAttribute('data-scope', o[0]);
			c.addEventListener('click', function () { go(o[0]); });
			box.appendChild(c);
		});
	}
	function renderNoteOffers(R) {
		var noted = notedToTeach(R);
		if (noted.length) {
			var li = el('li', 'tp-rv-note-teach');
			var box = el('span', 'tp-rv-teach-ask');
			scopeButtons(box, sprintf( /* translators: %s: how many words. */ _n( 'Whisper misheard %s word that has a pronunciation note. Teach it:', 'Whisper misheard %s words that have pronunciation notes. Teach them all:', noted.length, 'foxfire-studio' ), noted.length ), function (scope) {
				noted.forEach(function (p) { teachOne(p, scope); });
				saveTaught(scope);
				refreshReview();
			});
			li.appendChild(box);
			rv.problems.appendChild(li);
		}
		var o = rv.noteOffer;
		if (o ? (studio.noteSet ? !noteOf(o.idx) : false) : false) {
			var oli = el('li', 'tp-rv-note-offer');
			var lab = el('label', 'tp-rv-note-offer-label', sprintf( /* translators: %s: the word in the script. */ __( 'Add a pronunciation note for “%s”?', 'foxfire-studio' ), o.word ));
			lab.setAttribute('for', 'tpRvNoteIn');
			var input = document.createElement('input');
			input.type = 'text';
			input.id = 'tpRvNoteIn';
			input.className = 'tp-rv-note-in';
			input.maxLength = PR ? PR.NOTE_LEN : 40;
			input.placeholder = __( 'for example SOW-in', 'foxfire-studio' );
			oli.appendChild(lab);
			oli.appendChild(input);
			var row = el('span', 'tp-rv-teach-ask');
			function save(scope) {
				if (!input.value.trim()) { input.focus(); return; }
				studio.noteSet(o.idx, input.value, scope);
				rv.noteOffer = null;
				noteTitles();
				renderProblems();
			}
			scopeButtons(row, __( 'Save it:', 'foxfire-studio' ), save);
			var not = el('button', 'tp-rv-teach', __( 'Not now', 'foxfire-studio' ));
			not.type = 'button';
			not.addEventListener('click', function () { rv.noteOffer = null; renderProblems(); });
			row.appendChild(not);
			input.addEventListener('keydown', function (e) {
				if (e.key === 'Enter') { e.preventDefault(); save('all'); }
				else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); rv.noteOffer = null; renderProblems(); }
			});
			oli.appendChild(row);
			rv.problems.appendChild(oli);
		}
	}

	/*
	 * Flags after edits (1.1.0). As soon as a cut or retake covers a flagged
	 * moment, the flag is marked and the tiles and timeline count what is
	 * left. "Check the edited version" has Whisper listen to the edited
	 * recording; its flags can then be shown in place of the take's. The
	 * take's own numbers (history, goals, charts) never change.
	 */
	function cutsNow() { return studio.ed ? (studio.ed.take ? studio.ed.cuts : []) : []; }
	function viewR() { return rv.view === 'edited' ? (rv.edited ? rv.edited.R : rv.R) : rv.R; }
	function editedPlayer() {
		if (!rv.editedAudio) {
			rv.editedAudio = new window.Audio();
			rv.editedAudio.preload = 'auto';
			rv.editedAudio.addEventListener('play', function () { rv.audio.pause(); renderFlagView(); });
			rv.editedAudio.addEventListener('pause', function () { renderFlagView(); });
			rv.audio.addEventListener('play', function () { rv.editedAudio.pause(); });
		}
		return rv.editedAudio;
	}
	function playEdited(t) {
		if (!rv.edited) { return; }
		var a = editedPlayer();
		if (a.src !== rv.edited.url) { a.src = rv.edited.url; }
		var go = function () {
			a.currentTime = Math.max(0, Math.min(t, (a.duration || t + 1) - 0.05));
			var p = a.play();
			if (p && p.catch) { p.catch(function () {}); }
		};
		if (a.readyState >= 1) { go(); } else { a.addEventListener('loadedmetadata', go, { once: true }); }
	}
	/* Redraws the tiles, timeline and flags for the view shown and the edits made. */
	function renderFlags() {
		if (!rv.R) { return; }
		var cuts = cutsNow();
		if (rv.view === 'edited' ? !!rv.edited : false) {
			rv.cover = null;
			renderStats(rv.edited.R, rv.edited.seconds, null, null);
			renderTimeline(rv.edited.R, rv.edited.seconds, null, playEdited);
		} else {
			rv.view = 'recorded';
			rv.cover = cuts.length ? window.RRTEdit.flagsCovered(rv.R, cuts) : null;
			renderStats(rv.R, rv.secs, rv.prev, rv.cover ? rv.cover.counts : null);
			renderTimeline(rv.R, rv.secs, rv.cover, playFrom);
		}
		setFilter(rv.filter);
		updateCheckBtn();
	}
	studio.flagsEdited = function (key) {
		if (key === rv.cutKey) { return; }
		rv.cutKey = key;
		renderFlags();
	};
	function setView(v) {
		if (v === rv.view) { return; }
		rv.view = v;
		if (rv.editedAudio ? v === 'recorded' : false) { rv.editedAudio.pause(); }
		renderFlags();
	}
	/* The line above the flags: what the edits cover, and the switch between the two versions. */
	function renderFlagView() {
		var box = rv.flagView;
		if (!box) {
			box = el('div', 'tp-rv-flagview');
			box.setAttribute('aria-live', 'polite');
			rv.problems.parentNode.insertBefore(box, rv.problems);
			rv.flagView = box;
		}
		box.innerHTML = '';
		var E = rv.edited;
		if (E) {
			var sw = el('div', 'tp-rv-flagswitch');
			sw.setAttribute('role', 'group');
			sw.setAttribute('aria-label', __( 'Flags for', 'foxfire-studio' ));
			[['edited', __( 'Edited version', 'foxfire-studio' )], ['recorded', __( 'As recorded', 'foxfire-studio' )]].forEach(function (o) {
				var b = el('button', 'tp-btn', o[1]);
				b.type = 'button';
				b.setAttribute('data-view', o[0]);
				b.setAttribute('aria-pressed', rv.view === o[0] ? 'true' : 'false');
				b.addEventListener('click', function () { setView(o[0]); });
				sw.appendChild(b);
			});
			if (rv.view === 'edited') {
				var playing = rv.editedAudio ? !rv.editedAudio.paused : false;
				var pb = el('button', 'tp-btn tp-rv-editplay', playing ? __( 'Pause', 'foxfire-studio' ) : __( 'Play edited version', 'foxfire-studio' ));
				pb.type = 'button';
				pb.addEventListener('click', function () {
					var a = editedPlayer();
					if (playing) { a.pause(); } else if (a.src === E.url ? a.currentTime > 0 : false) { var p = a.play(); if (p && p.catch) { p.catch(function () {}); } } else { playEdited(0); }
				});
				sw.appendChild(pb);
			}
			box.appendChild(sw);
			if (rv.view === 'edited') {
				box.appendChild(el('p', 'tp-rv-muted', sprintf( /* translators: 1: accuracy, 2: words a minute, 3: how many flags. */ _n( 'Edited version: %1$s%% of words read as written, %2$s words a minute, %3$s thing flagged. Your takes and goals keep the numbers from the reading itself.', 'Edited version: %1$s%% of words read as written, %2$s words a minute, %3$s things flagged. Your takes and goals keep the numbers from the reading itself.', E.R.problems.length, 'foxfire-studio' ), Math.round(E.R.accuracy * 100), E.R.wpm, E.R.problems.length )));
			}
			if (E.key !== rv.cutKey) {
				box.appendChild(el('p', 'tp-rv-muted tp-rv-stale', __( 'You have changed the edits since this check. Press Check again in Edit audio to update it.', 'foxfire-studio' )));
			}
		}
		if (rv.view === 'recorded' ? (rv.cover ? rv.cover.covered > 0 : false) : false) {
			box.appendChild(el('p', 'tp-rv-muted tp-rv-covered', sprintf( /* translators: 1: flags covered, 2: all flags. */ _n( '%1$s of %2$s flag covered by your edits.', '%1$s of %2$s flags covered by your edits.', rv.R.problems.length, 'foxfire-studio' ), rv.cover.covered, rv.R.problems.length )));
		}
		box.hidden = !box.firstChild;
	}
	function updateCheckBtn() {
		var b = rv.checkBtn;
		if (!b) { return; }
		var cuts = cutsNow();
		var fresh = rv.edited ? rv.edited.key === rv.cutKey : false;
		b.textContent = rv.checking ? __( 'Whisper is listening...', 'foxfire-studio' ) : (rv.edited ? (fresh ? __( 'Edited version checked', 'foxfire-studio' ) : __( 'Check again', 'foxfire-studio' )) : __( 'Check the edited version', 'foxfire-studio' ));
		b.disabled = rv.checking || !cuts.length || fresh || (studio.ed ? !!studio.ed.busy : false);
	}
	/* Whisper listens to the edited recording, and the comparison is made again. */
	function checkEdited() {
		var ed = studio.ed;
		if (rv.checking || !ed || !ed.take || !ed.take.samples || !ed.cuts.length) { return; }
		if (studio.engineBusy) { ed.status.textContent = __( 'Whisper is still busy. Try again in a moment.', 'foxfire-studio' ); return; }
		var key = rv.cutKey;
		var cuts = ed.cuts.slice();
		var rate = studio.ED_RATE;
		rv.checking = true;
		updateCheckBtn();
		ed.status.textContent = __( 'Putting the edited version together...', 'foxfire-studio' );
		/* Listening to the edited version must not change what the review says about the take itself. */
		var keep = { usedModel: studio.usedModel, whisperListenS: studio.whisperListenS, whisperTimed: studio.whisperTimed, whisperLoadS: studio.whisperLoadS, engineFrom: studio.engineFrom };
		function restore() {
			Object.keys(keep).forEach(function (k) { studio[k] = keep[k]; });
		}
		var x = null;
		var metrics = null;
		new Promise(function (res) { window.setTimeout(res, 30); }).then(function () {
			x = window.RRTEdit.render(ed.take.samples, rate, cuts);
			var read = rv.R ? rv.R.words.filter(function (w) { return w.status !== 'omit' ? w.status !== 'out' : false; }).length : W.length;
			try { metrics = studio.analyseReading(x, rate, read, settings.wpm); } catch (e) { metrics = null; }
			return to16k(x, rate);
		}).then(function (a16) {
			return transcribeWith(a16, function (msg) { ed.status.textContent = msg; }, studio.engineModel || wantModel());
		}).then(function (chunks) {
			restore();
			var R2 = compareReading(scriptForReview(), chunks, metrics ? metrics.frames : null, allLearned());
			rv.checking = false;
			if (!R2) {
				ed.status.textContent = __( 'Whisper did not hear enough of the script in the edited version to check it.', 'foxfire-studio' );
				updateCheckBtn();
				return;
			}
			if (rv.edited) { URL.revokeObjectURL(rv.edited.url); }
			rv.edited = { key: key, chunks: chunks, metrics: metrics, seconds: x.length / rate, R: R2, url: URL.createObjectURL(new Blob([window.RRTEdit.wav(x, rate)], { type: 'audio/wav' })) };
			if (rv.editedAudio) { rv.editedAudio.pause(); rv.editedAudio.removeAttribute('src'); }
			rv.view = 'edited';
			ed.status.textContent = sprintf( /* translators: 1: how many flags in the edited version, 2: how many in the take. */ __( 'Edited version checked: %1$s flagged, against %2$s in the take as recorded.', 'foxfire-studio' ), R2.problems.length, rv.R.problems.length );
			renderFlags();
		}).catch(function (err) {
			restore();
			rv.checking = false;
			updateCheckBtn();
			ed.status.textContent = sprintf( /* translators: %s: err.message. */ __( 'The edited version could not be checked (%s).', 'foxfire-studio' ), err ? err.message : '' );
		});
	}
	if (rv.checkBtn) { rv.checkBtn.addEventListener('click', function () { rv.checkBtn.blur(); checkEdited(); }); }

	/* What "I said it right" and "I didn't say this" do (1.27.0), read with each of those buttons. */
	var teachIds = {};
	function teachHelp(added, text) {
		var k = added ? 'added' : 'sub';
		if (!teachIds[k]) {
			var h = el('span', 'tp-vh', text);
			h.id = 'tpRvTeach_' + k;
			rv.side.appendChild(h);
			teachIds[k] = h.id;
		}
		return teachIds[k];
	}

	/*
	 * The word cursor in the review (1.27.0). The script is one stop in the
	 * Tab order: left and right move word by word (each word read out with
	 * what was heard), Enter plays from the word, and with Edit audio on
	 * Shift and the arrows select from the cursor and Delete cuts. Home and
	 * End go to the first and last word.
	 */
	var cur = null;
	rv.script.tabIndex = 0;
	rv.script.setAttribute('role', 'region');
	rv.script.setAttribute('aria-label', __( 'The reading, word by word', 'foxfire-studio' ));
	var curHelpEl = el('span', 'tp-vh', __( 'Left and right arrows move word by word, Enter plays from the word, Space plays and pauses. With Edit audio on, Shift and the arrows select, and Delete cuts.', 'foxfire-studio' ));
	curHelpEl.id = 'tpRvScriptHelp';
	rv.side.appendChild(curHelpEl);
	rv.script.setAttribute('aria-describedby', 'tpRvScriptHelp');
	function curWords() { return Array.prototype.slice.call(rv.script.querySelectorAll('.tp-rv-w, .tp-rv-ins')); }
	function curLabel(n) {
		var out = [n.textContent.replace(/\s+/g, ' ').trim()];
		if (n.classList.contains('st-omit')) { out.push(__( 'skipped', 'foxfire-studio' )); }
		var t = n.getAttribute('data-base-title') || n.getAttribute('title') || '';
		if (t) { out.push(t); }
		if (n.classList.contains('is-cut')) { out.push(__( 'cut', 'foxfire-studio' )); }
		if (n.classList.contains('is-retaken')) { out.push(__( 'retaken', 'foxfire-studio' )); }
		if (n.classList.contains('is-sel')) { out.push(__( 'selected', 'foxfire-studio' )); }
		return out.join(', ');
	}
	function curSet(n, say) {
		if (cur) { cur.classList.remove('is-cursor'); }
		cur = n || null;
		if (!cur) { return; }
		cur.classList.add('is-cursor');
		if (cur.scrollIntoView) { cur.scrollIntoView({ block: 'nearest' }); }
		if (say) { studio.say(curLabel(cur)); }
	}
	rv.script.addEventListener('focus', function () {
		if (!cur || !rv.script.contains(cur)) { curSet(rv.script.querySelector('.tp-rv-w.is-play') || curWords()[0], false); }
	});
	rv.script.addEventListener('mousedown', function (e) {
		var w = e.target.closest ? e.target.closest('.tp-rv-w, .tp-rv-ins') : null;
		if (w) { curSet(w, false); }
	});
	rv.script.addEventListener('keydown', function (e) {
		if (e.target !== rv.script || e.ctrlKey || e.metaKey || e.altKey) { return; }
		var list = curWords();
		if (!list.length) { return; }
		var i = list.indexOf(cur);
		if (i < 0) { curSet(list[0], false); i = 0; }
		var ed = studio.ed;
		if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
			var dir = e.key === 'ArrowRight' ? 1 : -1;
			e.preventDefault();
			e.stopPropagation();
			if (e.shiftKey ? ed.on : false) {
				if (ed.a < 0) {
					var ti = cur.getAttribute('data-ti');
					if (ti === null || ed.tokens[Number(ti)].cut) { return; }
					ed.a = Number(ti);
					ed.b = ed.a;
					studio.edPaint();
				} else {
					studio.edNudge(dir);
				}
				if (ed.tokens[ed.b]) { curSet(ed.tokens[ed.b].el, false); }
				return;
			}
			curSet(list[Math.max(0, Math.min(list.length - 1, i + dir))], true);
		} else if (e.key === 'Home' || e.key === 'End') {
			e.preventDefault();
			curSet(list[e.key === 'Home' ? 0 : list.length - 1], true);
		} else if (e.key === 'Enter') {
			e.preventDefault();
			e.stopPropagation();
			/* With Edit audio on, Enter on a cut or retaken word does what a click does: brings it back, or opens its retake. */
			if (ed.on ? (cur.classList.contains('is-cut') || cur.classList.contains('is-retaken')) : false) { cur.click(); return; }
			if (cur._rrtPlay) { cur._rrtPlay(); }
		}
	});

	function renderProblems() {
		var R = viewR();
		var cov = rv.view === 'edited' ? null : rv.cover;
		rv.problems.innerHTML = '';
		renderFlagView();
		renderNoteOffers(R);
		var wanted = rv.filter ? rv.filter.split(',') : null;
		var list = R.problems.filter(function (p) {
			if (!wanted) { return true; }
			if (wanted[0] === 'retake') { return p.kind !== 'pause' && p.kind !== 'filler' && p.kind !== 'check'; }
			return wanted.indexOf(p.kind) !== -1;
		});
		rv.filterNote.textContent = wanted ? sprintf( /* translators: %s: wanted[0] === 'retake' ? __( 'passages to retake', 'foxfire-. */ __( 'Showing %s. Click the tile again to show everything.', 'foxfire-studio' ), wanted[0] === 'retake' ? __( 'passages to retake', 'foxfire-studio' ) : wanted.map(function (k) { return KIND_NAMES[k].toLowerCase(); }).join(' ' + __( 'and', 'foxfire-studio' ) + ' ') ) : '';
		if (!list.length) {
			rv.problems.appendChild(el('li', 'tp-rv-none', wanted ? __( 'Nothing of this kind in this take.', 'foxfire-studio' ) : __( 'Nothing flagged. A clean take.', 'foxfire-studio' )));
			return;
		}
		var play = rv.view === 'edited' ? playEdited : playFrom;
		list.forEach(function (p) {
			var li = el('li');
			var b = el('button', 'tp-rv-prob k-' + p.kind);
			var covered = cov ? cov.byProblem[R.problems.indexOf(p)] : '';
			if (covered) {
				li.classList.add('is-covered');
				b.appendChild(el('span', 'tp-rv-cover', covered === 'retaken' ? __( 'Retaken', 'foxfire-studio' ) : (p.kind === 'pause' ? __( 'Shortened', 'foxfire-studio' ) : __( 'Cut', 'foxfire-studio' ))));
			}
			b.type = 'button';
			b.appendChild(el('span', 'tp-rv-prob-time', fmt(p.s)));
			var txt = el('span', 'tp-rv-prob-text');
			txt.appendChild(el('strong', '', p.label + ' '));
			txt.appendChild(document.createTextNode(p.detail));
			var pn = (p.kind === 'sub' || p.kind === 'check' || p.kind === 'omit') ? noteOf(p.idx) : '';
			if (pn) { txt.appendChild(el('span', 'tp-rv-prob-note', sprintf( /* translators: %s: how to say the word, as the reader wrote it. */ __( 'said as %s', 'foxfire-studio' ), pn ))); }
			b.appendChild(txt);
			b.addEventListener('click', function () {
				play(p.s - 1);
				var span = rv.spans[Math.min(p.idx, rv.spans.length - 1)];
				if (span) { span.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
			});
			li.appendChild(b);
			if (p.teach) {
				var t = el('button', 'tp-rv-teach', p.kind === 'added' ? __( 'I didn’t say this', 'foxfire-studio' ) : __( 'I said it right', 'foxfire-studio' ));
				t.type = 'button';
				t.title = p.kind === 'added' ? __( 'Whisper imagined this word. Ignore it in future reviews.', 'foxfire-studio' ) : __( 'Whisper misheard you. Treat this pairing as correct in future reviews.', 'foxfire-studio' );
				t.setAttribute('aria-describedby', teachHelp(p.kind === 'added', t.title));
				if (PL) {
					/* Ask where to remember it: every post, or this post only (0.16.0). */
					t.addEventListener('click', function () {
						var ask = el('span', 'tp-rv-teach-ask');
						ask.appendChild(el('span', '', __( 'Remember it:', 'foxfire-studio' )));
						[['all', __( 'In every post', 'foxfire-studio' )], ['post', __( 'Just this post', 'foxfire-studio' )]].forEach(function (o) {
							var c = el('button', 'tp-rv-teach', o[1]);
							c.type = 'button';
							c.setAttribute('data-scope', o[0]);
							c.addEventListener('click', function () { teach(p, o[0]); });
							ask.appendChild(c);
						});
						li.replaceChild(ask, t);
					});
				} else {
					t.addEventListener('click', function () { teach(p, 'all'); });
				}
				li.appendChild(t);
			}
			rv.problems.appendChild(li);
		});
	}

	/* Teaches a word: scope 'all' (every post, the reader's own words) or 'post' (this post only, kept with the post). */
	function teach(p, scope) {
		teachOne(p, scope);
		saveTaught(scope);
		/* A misheard word with no note yet: offer to add one (1.1.0). */
		if (p.kind !== 'added' ? (studio.noteSet ? !noteOf(p.idx) : false) : false) {
			rv.noteOffer = { idx: p.idx, word: W[p.idx] ? W[p.idx].el.textContent.replace(/[^\w\u00C0-\u024F'\u2019-]/g, '') : '' };
		}
		refreshReview();
	}
	/* Adds one taught word to a set, without saving yet. */
	function teachOne(p, scope) {
		var L = scope === 'post' ? postLearned : (rv.taughtAll || (rv.taughtAll = loadLearned()));
		if (p.kind === 'added') {
			L.ignore[p.hk] = 1;
			L.labels['+' + p.hk] = p.detail + ' (ignored)';
			L.at['+' + p.hk] = Date.now();
		} else {
			L.pairs[p.sk] = L.pairs[p.sk] || {};
			L.pairs[p.sk][p.hk] = 1;
			L.labels[p.sk + '|' + p.hk] = p.detail;
			L.at[p.sk + '|' + p.hk] = Date.now();
		}
	}
	function saveTaught(scope) {
		if (scope === 'post') { savePostLearned(); return; }
		var L = rv.taughtAll || loadLearned();
		rv.taughtAll = null;
		saveLearned(L);
	}

	function forget(label, scope) {
		var L = scope === 'post' ? postLearned : loadLearned();
		/* Kept as forgotten (1.15.0), so another browser's copy cannot bring it back. */
		L.forgot[label] = Date.now();
		if (label.charAt(0) === '+') {
			delete L.ignore[label.slice(1)];
		} else {
			var parts = label.split('|');
			if (L.pairs[parts[0]]) {
				delete L.pairs[parts[0]][parts[1]];
				if (!Object.keys(L.pairs[parts[0]]).length) { delete L.pairs[parts[0]]; }
			}
		}
		delete L.labels[label];
		delete L.at[label];
		if (scope === 'post') { savePostLearned(); } else { saveLearned(L); }
		refreshReview();
	}

	function renderLearned() {
		var sets = [[loadLearned(), 'all', __( 'In every post', 'foxfire-studio' )], [postLearned, 'post', __( 'Just this post', 'foxfire-studio' )]];
		rv.learned.innerHTML = '';
		var any = false;
		sets.forEach(function (set) {
			var keys = Object.keys(set[0].labels);
			if (!keys.length) { return; }
			any = true;
			if (PL) { rv.learned.appendChild(el('p', 'tp-rv-learned-head', set[2])); }
			var ul = el('ul', 'tp-rv-learned');
			ul.setAttribute('data-scope', set[1]);
			keys.sort().forEach(function (k) {
				var li = el('li');
				li.appendChild(el('span', '', set[0].labels[k]));
				var x = el('button', 'tp-rv-forget', __( 'Forget', 'foxfire-studio' ));
				x.type = 'button';
				x.addEventListener('click', function () { forget(k, set[1]); });
				li.appendChild(x);
				ul.appendChild(li);
			});
			rv.learned.appendChild(ul);
		});
		if (!any) {
			rv.learned.appendChild(el('p', 'tp-rv-muted', PL ? __( 'When Whisper mishears a word you said correctly, click “I said it right” on it, then choose whether to remember it in every post or just this one.', 'foxfire-studio' ) : __( 'When Whisper mishears a word you said correctly, click “I said it right” on it. It will remember, here and in every other post.', 'foxfire-studio' )));
		}
	}

	/* Re-run the comparison after teaching, without counting it as a new take. */
	function refreshReview() {
		if (!rv.last) { return; }
		var keepScroll = rv.side ? rv.side.scrollTop : 0;
		var R = compareReading(scriptForReview(), rv.last.chunks, rv.last.metrics ? rv.last.metrics.frames : null, allLearned());
		if (!R) { return; }
		if (rv.edited) {
			var R2 = compareReading(scriptForReview(), rv.edited.chunks, rv.edited.metrics ? rv.edited.metrics.frames : null, allLearned());
			if (R2) { rv.edited.R = R2; }
		}
		var keepFilter = rv.filter;
		showReview(R, rv.last.metrics, null, rv.last.seconds, true);
		if (keepFilter) { setFilter(keepFilter); }
		if (rv.side) { rv.side.scrollTop = keepScroll; }
	}

	function renderTakes(takes, drill) {
		rv.takes.innerHTML = '';
		if (drill) { rv.takes.appendChild(el('p', 'tp-rv-muted', __( 'Opening drills only: full readings are compared separately.', 'foxfire-studio' ))); }
		if (takes.length < 2) {
			rv.takes.appendChild(el('p', 'tp-rv-muted', drill ? __( 'Do the drill again and your drills will be compared here.', 'foxfire-studio' ) : (studio.PRACTICE ? __( 'Read the practice reading again and your takes will be compared here.', 'foxfire-studio' ) : __( 'Record this post again and your takes will be compared here.', 'foxfire-studio' ))));
			return;
		}
		var shown = takes.slice(-6);
		var table = el('table', 'tp-rv-table');
		var thead = el('tr');
		[__( 'Take', 'foxfire-studio' ), __( 'Accuracy', 'foxfire-studio' ), __( 'Pace', 'foxfire-studio' ), __( 'Slips', 'foxfire-studio' ), __( 'Clicks', 'foxfire-studio' ), __( 'Retake', 'foxfire-studio' )].forEach(function (h) { thead.appendChild(el('th', '', h)); });
		table.appendChild(thead);
		var base = takes.length - shown.length;
		shown.forEach(function (t, i) {
			var tr = el('tr', i === shown.length - 1 ? 'is-now' : '');
			var d = new Date(t.at);
			tr.appendChild(el('td', '', String(base + i + 1) + ' · ' + d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })));
			tr.appendChild(el('td', '', t.acc.toFixed(1) + '%'));
			tr.appendChild(el('td', '', String(t.wpm)));
			tr.appendChild(el('td', '', String(t.omit + t.sub + t.added + t.repeat)));
			tr.appendChild(el('td', '', typeof t.clicks === 'number' ? String(t.clicks) : '\u2013'));
			tr.appendChild(el('td', '', String(t.retake)));
			table.appendChild(tr);
		});
		rv.takes.appendChild(table);
		var first = takes[0];
		var now = takes[takes.length - 1];
		var lines = [];
		if (Math.abs(now.acc - first.acc) >= 1) {
			lines.push(sprintf( /* translators: 1: now.acc > first.acc ? 'up' : 'down', 2: first.acc.toFixed(1), 3: now.acc.toFixed(1). */ __( 'Accuracy has gone %1$s from %2$s%% to %3$s%% since your first take.', 'foxfire-studio' ), now.acc > first.acc ? 'up' : 'down', first.acc.toFixed(1), now.acc.toFixed(1) ));
		} else {
			lines.push(sprintf( /* translators: %s: now.acc.toFixed(0). */ __( 'Accuracy has held steady at around %s%% across your takes.', 'foxfire-studio' ), now.acc.toFixed(0) ));
		}
		var firstSlips = first.omit + first.sub + first.added + first.repeat;
		var nowSlips = now.omit + now.sub + now.added + now.repeat;
		if (firstSlips !== nowSlips) { lines.push(sprintf( /* translators: 1: firstSlips, 2: nowSlips. */ __( 'Slips went from %1$s to %2$s.', 'foxfire-studio' ), firstSlips, nowSlips )); }
		var roomed = takes.filter(function (t) { return typeof t.snr === 'number'; });
		if (roomed.length >= 2) {
			var r0 = roomed[0];
			var r1 = roomed[roomed.length - 1];
			if (Math.abs(r1.snr - r0.snr) >= 3) { lines.push(sprintf( /* translators: 1: r1.snr > r0.snr ? __( 'quieter', 'foxfire-studio' ) : __( 'noisier', 'foxfire-studio' ), 2: r0.snr, 3: r1.snr. */ __( 'Your room got %1$s: the background went from %2$s to %3$s dB below your voice.', 'foxfire-studio' ), r1.snr > r0.snr ? __( 'quieter', 'foxfire-studio' ) : __( 'noisier', 'foxfire-studio' ), r0.snr, r1.snr )); }
			if (r0.clicks !== r1.clicks) { lines.push(sprintf( /* translators: 1: r0.clicks, 2: r1.clicks. */ __( 'Clicks before phrases went from %1$s to %2$s.', 'foxfire-studio' ), r0.clicks, r1.clicks )); }
		}
		rv.takes.appendChild(el('p', 'tp-rv-muted', lines.join(' ')));
	}

	function renderTimeline(R, seconds, cov, player) {
		player = player || playFrom;
		var tl = rv.timeline;
		tl.innerHTML = '';
		var total = Math.max(1, seconds);
		function pct(t) { return (Math.max(0, Math.min(total, t)) / total * 100) + '%'; }
		R.sentences.forEach(function (S) {
			var seg = el('span', 'tp-tl-seg');
			var rel = R.sentWpm ? S.wpm / R.sentWpm : 1;
			var heat = 'mid';
			if (rel > 1.18) { heat = 'fast'; } else if (rel > 1.07) { heat = 'quick'; } else if (rel < 0.82) { heat = 'slow'; } else if (rel < 0.93) { heat = 'easy'; }
			seg.className += ' h-' + heat;
			seg.style.left = pct(S.s);
			seg.style.width = 'calc(' + ((S.e - S.s) / total * 100) + '% - 1px)';
			seg.title = sprintf( /* translators: %s: S.wpm. */ __( '%s wpm', 'foxfire-studio' ), S.wpm );
			tl.appendChild(seg);
		});
		R.pauses.forEach(function (p) {
			var mk = el('span', 'tp-tl-pause');
			mk.style.left = pct(p.s);
			mk.style.width = (p.len / total * 100) + '%';
			mk.title = sprintf( /* translators: %s: p.len.toFixed(1). */ __( '%s s pause', 'foxfire-studio' ), p.len.toFixed(1) );
			tl.appendChild(mk);
		});
		/* The pause map (1.11.0): a thin tick for every other pause of a second or more. */
		var longAt = {};
		R.pauses.forEach(function (p) { longAt[p.before] = true; });
		(R.gaps || []).forEach(function (g) {
			if (longAt[g.before]) { return; }
			var tk = el('span', 'tp-tl-gap g-' + g.type);
			tk.style.left = pct(g.s);
			tk.style.width = (g.len / total * 100) + '%';
			tk.title = sprintf( /* translators: %s: length in seconds. */ __( '%s s pause', 'foxfire-studio' ), g.len.toFixed(1) );
			tl.appendChild(tk);
		});
		R.problems.forEach(function (p, i) {
			if (p.kind === 'pause') { return; }
			var dot = el('span', 'tp-tl-dot k-' + p.kind + ((cov ? cov.byProblem[i] : '') ? ' is-covered' : ''));
			dot.style.left = pct(p.s);
			dot.title = p.label;
			tl.appendChild(dot);
		});
		var head = el('span', 'tp-tl-head');
		head.id = 'tpTlHead';
		tl.appendChild(head);
		tl.onclick = function (e) {
			var r = tl.getBoundingClientRect();
			player((e.clientX - r.left) / r.width * total);
		};
	}

	/*
	 * Words whose times are out of step with the script (0.18.1). Whisper
	 * listens in 30 second pieces that overlap where they join, and near a
	 * join it sometimes gives a word a time from somewhere else in the take.
	 * The words whose times rise in script order (the longest such run) are
	 * kept; any other timed word is returned here, so it is never lit up out
	 * of place during playback or used to place a cut or a retake.
	 */
	function strayTimes(words) {
		var idx = [];
		words.forEach(function (r, k) { if (r.s !== null ? r.status !== 'omit' : false) { idx.push(k); } });
		var tails = [];
		var tailAt = [];
		var prev = [];
		idx.forEach(function (k, n) {
			var v = words[k].s;
			var lo = 0;
			var hi = tails.length;
			while (lo < hi) { var mid = (lo + hi) >> 1; if (tails[mid] <= v) { lo = mid + 1; } else { hi = mid; } }
			tails[lo] = v;
			tailAt[lo] = n;
			prev[n] = lo > 0 ? tailAt[lo - 1] : -1;
		});
		var keep = {};
		for (var n = tails.length ? tailAt[tails.length - 1] : -1; n >= 0; n = prev[n]) { keep[idx[n]] = true; }
		var out = {};
		idx.forEach(function (k) { if (!keep[k]) { out[k] = true; } });
		return out;
	}
	/* A stray word's time, from its kept neighbours: where it must really be. */
	function strayGuess(words, stray, k) {
		var a = null;
		var b = null;
		for (var i = k - 1; i >= 0; i--) { if (words[i].s !== null ? !stray[i] : false) { a = words[i].e; break; } }
		for (i = k + 1; i < words.length; i++) { if (words[i].s !== null ? !stray[i] : false) { b = words[i].s; break; } }
		if (a !== null ? b !== null : false) { return (a + b) / 2; }
		return a !== null ? a : (b !== null ? b : 0);
	}

	var SX_TAG = { rushed: __( 'fast', 'foxfire-studio' ), slow: __( 'slow', 'foxfire-studio' ), rough: __( 'paraphrased', 'foxfire-studio' ), unfinished: __( 'unfinished', 'foxfire-studio' ) };
	function renderScript(R) {
		var S = rv.script;
		S.innerHTML = '';
		rv.spans = [];
		rv.timed = [];
		var stray = strayTimes(R.words);
		var sentFlags = {};
		R.sentences.forEach(function (s) { sentFlags[s.id] = s; });
		var pauseBefore = {};
		R.pauses.forEach(function (p) { pauseBefore[p.before] = p; });
		/* The pause map (1.11.0): the other pauses of a second or more, shown while Show pauses is on. */
		var gapBefore = {};
		(R.gaps || []).forEach(function (g) { if (!pauseBefore[g.before]) { gapBefore[g.before] = g; } });
		var missedAfter = {};
		(R.cueMissed || []).forEach(function (m) { missedAfter[m.idx] = m; });
		var curBlock = -1;
		var curSent = -1;
		var para = null;
		var box = null;
		var toks = [];
		var pauseMarks = [];
		W.forEach(function (w, k) {
			var newBlock = w.block !== curBlock;
			if (newBlock) {
				curBlock = w.block;
				para = el('div', 'tp-rv-p tp-rv-' + w.kind);
				var ps = R.paragraphs[w.block];
				if (ps && w.kind === 'p') {
					var badge = el('span', 'tp-rv-score' + (ps.score >= 0.97 ? ' is-good' : (ps.score < 0.85 ? ' is-low' : '')), Math.round(ps.score * 100) + '%');
					badge.title = __( 'Words read as written in this paragraph', 'foxfire-studio' );
					para.appendChild(badge);
				}
				S.appendChild(para);
			}
			if (newBlock || w.sent !== curSent) {
				curSent = w.sent;
				if (!newBlock) { para.appendChild(document.createTextNode(' ')); }
				box = el('span', 'tp-rv-s');
				var flagsHere = sentFlags[w.sent];
				if (flagsHere) { flagsHere.flags.forEach(function (f) { if (f !== 'faded') { box.classList.add('sx-' + f); } }); }
				/* A text tag as well as the shading (1.27.0), so the shading never says it by colour alone. */
				var tagged = flagsHere ? flagsHere.flags.filter(function (f) { return SX_TAG[f]; }) : [];
				if (tagged.length) { box.appendChild(el('span', 'tp-rv-tag', tagged.map(function (f) { return SX_TAG[f]; }).join(', '))); }
				para.appendChild(box);
			} else {
				box.appendChild(document.createTextNode(' '));
			}
			if (gapBefore[k]) {
				var gm = el('span', 'tp-rv-gap g-' + gapBefore[k].type + (gapBefore[k].cued ? ' is-cued' : ''), gapBefore[k].len.toFixed(1) + 's');
				gm.title = pauseTitle(gapBefore[k]);
				box.appendChild(gm);
				box.appendChild(document.createTextNode(' '));
			}
			if (pauseBefore[k]) {
				var pm = el('span', 'tp-rv-pause', '‖ ' + pauseBefore[k].len.toFixed(1) + 's');
				pauseMarks.push({ el: pm, s: pauseBefore[k].s, len: pauseBefore[k].len });
				box.appendChild(pm);
				box.appendChild(document.createTextNode(' '));
			}
			(R.inserts[k] || []).forEach(function (x) {
				var chip = el('span', 'tp-rv-ins k-' + x.kind, (x.kind === 'filler' ? '' : '+') + x.text);
				chip.title = x.kind === 'filler' ? __( 'Filler', 'foxfire-studio' ) : (x.kind === 'repeat' ? __( 'Repeated', 'foxfire-studio' ) : __( 'Not in the script', 'foxfire-studio' ));
				chip.addEventListener('click', function () { playFrom(x.s - 0.5); });
				chip._rrtPlay = function () { playFrom(x.s - 0.5); };
				box.appendChild(chip);
				box.appendChild(document.createTextNode(' '));
				toks.push({ el: chip, s: x.s, e: x.e, kind: x.kind, block: w.block, sent: w.sent, label: x.text });
			});
			var r = R.words[k];
			var sp = el('span', 'tp-rv-w st-' + r.status, w.el.textContent);
			var sf = sentFlags[w.sent];
			if (sf && k === sf.last && sf.flags.indexOf('faded') !== -1) { sp.classList.add('sx-faded'); }
			if (r.status === 'sub') { sp.title = sprintf( /* translators: %s: r.heard. */ __( 'Heard as “%s”', 'foxfire-studio' ), r.heard ); }
			if (r.s !== null ? stray[k] : false) {
				/* Out of step: played from where it must be, but never lit up or edited by its own time. */
				var guess = strayGuess(R.words, stray, k);
				sp.classList.add('is-stray');
				sp.title = sprintf( /* translators: %s: sp.title ? sp.title + '. ' : ''. */ __( '%sWhisper gave this word a time out of step with the words around it, so it is not lit up during playback; to edit it, select the words either side as well', 'foxfire-studio' ), sp.title ? sp.title + '. ' : '' );
				sp.addEventListener('click', function () { playFrom(guess - 0.15); });
				sp._rrtPlay = function () { playFrom(guess - 0.15); };
			} else if (r.s !== null) {
				sp.addEventListener('click', function () { playFrom(r.s - 0.15); });
				sp._rrtPlay = function () { playFrom(r.s - 0.15); };
				if (r.status !== 'omit') {
					rv.timed.push({ s: r.s, e: r.e, k: k });
					toks.push({ el: sp, s: r.s, e: r.e, kind: 'word', block: w.block, sent: w.sent, label: w.el.textContent, cue: cueOf[k] ? cueOf[k].a : '' });
				}
			}
			if (sp.title) { sp.setAttribute('data-base-title', sp.title); }
			rv.spans.push(sp);
			box.appendChild(sp);
			/* Cue marks (0.15.0) from the teleprompter, so a marked pause can be checked by ear. */
			var cq = cueOf[k];
			if (cq) {
				if (cq.e) { sp.classList.add('tp-cue-em'); }
				if (cq.f) {
					var fm = el('span', 'tp-cue tp-cue-fade', '~');
					fm.title = __( 'You marked this line to fade', 'foxfire-studio' );
					box.appendChild(fm);
				}
				if (cq.a) {
					var cm = el('span', 'tp-cue tp-cue-' + cq.a + (missedAfter[k] ? ' is-missed' : ''), 'pause' === cq.a ? '/' : '\u2713');
					cm.title = missedAfter[k] ? ('pause' === cq.a ? __( 'You marked a pause here, but the reading ran straight on', 'foxfire-studio' ) : __( 'You marked a breath here, but the reading ran straight on', 'foxfire-studio' )) : ('pause' === cq.a ? __( 'You marked a pause here', 'foxfire-studio' ) : __( 'You marked a breath here', 'foxfire-studio' ));
					box.appendChild(cm);
				}
			}
		});
		if (R.inserts[W.length]) {
			R.inserts[W.length].forEach(function (x) {
				para.appendChild(document.createTextNode(' '));
				var chip = el('span', 'tp-rv-ins k-' + x.kind, '+' + x.text);
				para.appendChild(chip);
				toks.push({ el: chip, s: x.s, e: x.e, kind: x.kind, block: W.length ? W[W.length - 1].block : 0, sent: W.length ? W[W.length - 1].sent : 0, label: x.text });
			});
		}
		rv.timed.sort(function (a, b) { return a.s - b.s; });
		studio.ed.pauses = pauseMarks;
		studio.edTokens(toks);
	}

	/* A pause's hover text in the pause map (1.11.0): how long, and where it falls. */
	function pauseTitle(g) {
		var where = 'break' === g.type ? __( 'at a paragraph break', 'foxfire-studio' ) : ('inside' === g.type ? __( 'inside a sentence', 'foxfire-studio' ) : __( 'between sentences', 'foxfire-studio' ));
		return sprintf( /* translators: 1: length in seconds, 2: where the pause falls, such as between sentences. */ __( '%1$s seconds, %2$s', 'foxfire-studio' ), g.len.toFixed(1), where ) + (g.cued ? ', ' + __( 'where you cued one', 'foxfire-studio' ) : '');
	}

	/*
	 * The pause map (1.11.0): a summary of every pause of a second or more,
	 * the Show pauses switch (remembered in this browser), which shows each
	 * one's length in the script, and any cued pause the reading ran past.
	 */
	var SHOW_PAUSES = 'rrtShowPauses';
	function renderPauseMap(R) {
		var box = document.getElementById('tpRvPauses');
		if (!box) { return; }
		box.innerHTML = '';
		var PM = R.pauseMap || { count: 0 };
		var on = false;
		try { on = window.localStorage.getItem(SHOW_PAUSES) === '1'; } catch (e) {}
		rv.script.classList.toggle('is-gaps', on);
		if (!PM.count) {
			box.appendChild(el('p', 'tp-rv-muted', __( 'No pauses of a second or more.', 'foxfire-studio' )));
		} else {
			var parts = [];
			if (PM.sentence) { parts.push(sprintf( /* translators: %d: number of pauses. */ _n( '%d between sentences', '%d between sentences', PM.sentence, 'foxfire-studio' ), PM.sentence )); }
			if (PM['break']) { parts.push(sprintf( /* translators: %d: number of pauses. */ _n( '%d at a paragraph break', '%d at paragraph breaks', PM['break'], 'foxfire-studio' ), PM['break'] )); }
			if (PM.inside) { parts.push(sprintf( /* translators: %d: number of pauses. */ _n( '%d inside a sentence', '%d inside a sentence', PM.inside, 'foxfire-studio' ), PM.inside )); }
			var line = sprintf( /* translators: 1: number of pauses, 2: where they fall, such as "14 between sentences, 6 at paragraph breaks", 3: the longest, in seconds. */ _n( '%1$d pause of a second or more: %2$s; the longest %3$s s.', '%1$d pauses of a second or more: %2$s; the longest %3$s s.', PM.count, 'foxfire-studio' ), PM.count, parts.join(', '), PM.longest.toFixed(1) );
			if (PM.cued) { line += ' ' + sprintf( /* translators: %d: number of pauses. */ _n( '%d of them where you cued a pause or a breath.', '%d of them where you cued a pause or a breath.', PM.cued, 'foxfire-studio' ), PM.cued ); }
			box.appendChild(el('p', 'tp-rv-pm-sum', line));
			var lab = el('label', 'tp-switch tp-rv-pm-switch');
			var cb = document.createElement('input');
			cb.type = 'checkbox';
			cb.id = 'tpRvShowPauses';
			cb.checked = on;
			cb.addEventListener('change', function () {
				rv.script.classList.toggle('is-gaps', cb.checked);
				try { window.localStorage.setItem(SHOW_PAUSES, cb.checked ? '1' : '0'); } catch (e) {}
				cb.blur();
			});
			lab.appendChild(cb);
			lab.appendChild(el('span', '', __( 'Show pauses in the script', 'foxfire-studio' )));
			box.appendChild(lab);
		}
		(R.cueMissed || []).forEach(function (m) {
			var b = el('button', 'tp-rv-pm-missed', sprintf( /* translators: 1: a pause or a breath, 2: the word before it. */ __( 'Cued %1$s not taken after “%2$s”', 'foxfire-studio' ), 'pause' === m.a ? __( 'pause', 'foxfire-studio' ) : __( 'breath', 'foxfire-studio' ), W[m.idx] ? W[m.idx].el.textContent : '' ));
			b.type = 'button';
			b.title = __( 'Play from a moment before. A gentle note: it is not counted as a flag.', 'foxfire-studio' );
			b.addEventListener('click', function () { playFrom(m.s - 1.5); });
			box.appendChild(b);
		});
	}

	/* Light up the word being played, karaoke style, and keep it in view. */
	function syncPlayback() {
		if (rv.open && rv.timed.length) {
			var t = rv.audio.currentTime;
			var lo = 0;
			var hi = rv.timed.length - 1;
			var at = -1;
			while (lo <= hi) {
				var mid = (lo + hi) >> 1;
				if (rv.timed[mid].s <= t) { at = mid; lo = mid + 1; } else { hi = mid - 1; }
			}
			var k = at >= 0 ? rv.timed[at].k : -1;
			if (k !== rv.playing) {
				if (rv.playing >= 0 && rv.spans[rv.playing]) { rv.spans[rv.playing].classList.remove('is-play'); }
				rv.playing = k;
				if (k >= 0) {
					var sp = rv.spans[k];
					sp.classList.add('is-play');
					if (!rv.audio.paused) {
						var box = rv.script.getBoundingClientRect();
						var r = sp.getBoundingClientRect();
						if (r.top < box.top + 40 || r.bottom > box.bottom - 60) {
							rv.script.scrollTop += r.top - box.top - box.height * 0.35;
						}
					}
				}
			}
			var head = document.getElementById('tpTlHead');
			if (head && rv.audio.duration) { head.style.left = (t / rv.audio.duration * 100) + '%'; }
		}
		window.requestAnimationFrame(syncPlayback);
	}
	window.requestAnimationFrame(syncPlayback);

	/*
	 * "Use this recording" (0.9.0): the MP3 behind the download button, and
	 * the captions once the review has made them, go to the site and become
	 * this post's reading. Only ever on this click; nothing else leaves the
	 * computer. The site notes it in Recordings, linked to the take.
	 */
	/* The button sits with the result and in the review studio's header; both do the same. */
	var useRecBtns = [document.getElementById('tpUseRec'), document.getElementById('tpRvUseRec')];
	/*
	 * Audiobook export (1.20.0). BOOK.keep: Use this recording also keeps an
	 * audiobook copy. BOOK.credit: this post is a collection's credits, so
	 * Use this recording keeps only the audiobook copy.
	 */
	var BOOK = RRT.book || {};
	function useRecLabel() { return BOOK.credit ? __( 'Use for the audiobook', 'foxfire-studio' ) : __( 'Use this recording', 'foxfire-studio' ); }
	/* Makes this take's audiobook copy and keeps it on the site. say(text) shows how it goes. Resolves to the copy as the site keeps it. */
	function bookKeep(say) {
		if (!studio.bookCopy) { return Promise.reject(new Error(__( 'not available', 'foxfire-studio' ))); }
		say(__( 'Making the audiobook copy...', 'foxfire-studio' ));
		var made = null;
		return studio.bookCopy(function (p, phase) {
			if (phase === 'sound') { say(__( 'Audiobook copy: shaping the sound...', 'foxfire-studio' )); } else if (phase === 'check') { say(__( 'Audiobook copy: checking...', 'foxfire-studio' )); } else if (isFinite(p)) { say(sprintf( /* translators: %s: percent. */ __( 'Audiobook copy: %s%%', 'foxfire-studio' ), Math.round(p * 100) )); }
		}).then(function (out) {
			made = out;
			say(__( 'Audiobook copy: sending it to the site...', 'foxfire-studio' ));
			var form = new FormData();
			form.append('mp3', out.blob, slug + '-audiobook.mp3');
			form.append('from', BOOK.credit ? 'credit' : 'take');
			var mm = out.check ? out.check.measures : null;
			if (mm) {
				form.append('rms', String(mm.rms));
				form.append('peak', String(mm.samplePeak));
				form.append('tp', String(mm.truePeak));
				form.append('noise', String(mm.noise));
				form.append('head', String(mm.head));
				form.append('tail', String(mm.tail));
			}
			return api('audiobook/' + POST_ID + '/copy', form);
		}).then(function (j) {
			BOOK.have = j.book;
			return { book: j.book, check: made ? made.check : null };
		});
	}
	/* What a copy's check found, in a sentence: the rules it misses, or that it meets them. */
	function bookCheckLine(c) {
		if (!c) { return ''; }
		var bad = (c.items || []).filter(function (it) { return it.ok === false; }).map(function (it) { return it.label + ' ' + it.value + ' (' + it.rule + ')'; });
		return bad.length ? sprintf( /* translators: %s: the rules missed. */ __( 'Check: %s.', 'foxfire-studio' ), bad.join('; ') ) : __( 'It meets the audiobook rules.', 'foxfire-studio' );
	}
	function bookDone(b, c) {
		/* translators: 1: length such as 14:20, 2: size in MB. */
		return sprintf(__( '%1$s, %2$s MB.', 'foxfire-studio' ), Math.floor(Math.round(b.secs) / 60) + ':' + ('0' + (Math.round(b.secs) % 60)).slice(-2), (b.bytes / 1048576).toFixed(1)) + ' ' + bookCheckLine(c);
	}
	function useRecSet(text, disabled) {
		useRecBtns.forEach(function (b) { b.textContent = text; b.disabled = disabled; });
	}
	function useRecShow() {
		var show = !!(STATS ? (STATS.upload ? POST_ID : false) : false) ? !downloadA.hidden : false;
		useRecBtns.forEach(function (b) { b.hidden = !show; });
		useRecSet(useRecLabel(), false);
	}
	new MutationObserver(useRecShow).observe(downloadA, { attributes: true, attributeFilter: ['hidden', 'href'] });
	useRecShow();
	useRecBtns.forEach(function (b) { b.addEventListener('click', useRecording); });
	/*
	 * Download in the review studio's header (0.25.3): the same file as the
	 * download button behind the review, so it always gives the latest one
	 * (the take, or the edited MP3 once it is made) and the music bed's mix
	 * when that is switched on. It clicks that button, so the name, the mix
	 * and forgetting the unfinished take all work the same way.
	 */
	var rvDownload = document.getElementById('tpRvDownload');
	/*
	 * Finish for (1.6.0): a menu beside it for a one-off version for another
	 * destination (Spotify and YouTube, broadcast, and so on), made from the
	 * same voice and music when Download is pressed. Use this recording always
	 * uses the Settings choice, so the menu never changes what goes on the post.
	 */
	var rvFinish = document.getElementById('tpRvFinish');
	var rvFinishBusy = false;
	var SPECS = studio.SOUND && studio.SOUND.specs ? studio.SOUND.specs : [];
	var NOW = studio.SOUND && studio.SOUND.spec ? studio.SOUND.spec.key : '';
	if (rvFinish) {
		if (SPECS.length && studio.finishOneOff) {
			var o0 = document.createElement('option');
			o0.value = '';
			var nowLabel = '';
			SPECS.forEach(function (sp) { if (sp.key === NOW) { nowLabel = sp.label; } });
			o0.textContent = sprintf( /* translators: %s: the finishing preset chosen in Settings. */ __( 'Finish for: %s (Settings)', 'foxfire-studio' ), nowLabel || __( 'Settings', 'foxfire-studio' ) );
			rvFinish.appendChild(o0);
			SPECS.forEach(function (sp) {
				if (sp.key === NOW || sp.key === 'off') { return; }
				var o = document.createElement('option');
				o.value = sp.key;
				o.textContent = sp.label;
				o.title = sp.help || '';
				rvFinish.appendChild(o);
			});
			/* Audiobook export (1.20.0): this take kept as the post's audiobook chapter; the post's reading stays as it is. */
			if (BOOK.can ? !BOOK.credit : false) {
				var ob = document.createElement('option');
				ob.value = '__book';
				ob.textContent = __( 'Keep as this post\'s audiobook chapter', 'foxfire-studio' );
				ob.title = __( 'Finishes this take with the Audiobook (ACX) preset, voice only, and keeps it on the site for the collections\' Audiobook box. The reading on the post is not changed.', 'foxfire-studio' );
				rvFinish.appendChild(ob);
			}
			rvFinish.addEventListener('change', function () { rvDownloadShow(); });
		} else {
			rvFinish = null;
		}
	}
	function rvDownloadShow() {
		if (!rvDownload) { return; }
		rvDownload.hidden = downloadA.hidden ? true : downloadA.href.indexOf('blob:') !== 0;
		if (rvFinish) { rvFinish.hidden = rvDownload.hidden; }
		if (rvFinishBusy) { return; }
		var label = downloadA.textContent;
		if (rvFinish ? rvFinish.value === '__book' : false) {
			label = __( 'Keep for the audiobook', 'foxfire-studio' );
		} else if (rvFinish ? rvFinish.value : false) {
			var word = studio.finishWord ? studio.finishWord(SPECS.filter(function (sp) { return sp.key === rvFinish.value; })[0]) : 'MP3';
			label = /with music$/.test(downloadA.textContent) ? sprintf( /* translators: %s: MP3 or WAV. */ __( 'Download %s with music', 'foxfire-studio' ), word ) : sprintf( /* translators: %s: MP3 or WAV. */ __( 'Download %s', 'foxfire-studio' ), word );
		}
		if (rvDownload.textContent !== label) { rvDownload.textContent = label; }
	}
	function rvFinishDownload(key) {
		rvFinishBusy = true;
		rvDownload.disabled = true;
		rvFinish.disabled = true;
		var say = function (t) { rvDownload.textContent = t; };
		say(__( 'Finishing...', 'foxfire-studio' ));
		studio.finishOneOff(key, function (p, phase) {
			if (phase === 'sound') { say(__( 'Shaping the sound...', 'foxfire-studio' )); } else if (phase === 'check') { say(__( 'Checking...', 'foxfire-studio' )); } else if (isFinite(p)) { say(sprintf( /* translators: %s: percent. */ __( 'Finishing... %s%%', 'foxfire-studio' ), Math.round(p * 100) )); }
		}).then(function (out) {
			var a = document.createElement('a');
			var url = URL.createObjectURL(out.blob);
			a.href = url;
			a.setAttribute('download', out.name);
			document.body.appendChild(a);
			a.click();
			a.parentNode.removeChild(a);
			window.setTimeout(function () { URL.revokeObjectURL(url); }, 60000);
			var c = out.sound ? out.sound.check : null;
			var lines = studio.describeSound ? studio.describeSound(out.sound) : [];
			var msg = sprintf( /* translators: 1: file name, 2: size in MB. */ __( 'Downloaded %1$s (%2$s MB), a one-off version. Use this recording still uses the choice in Settings.', 'foxfire-studio' ), out.name, (out.blob.size / 1048576).toFixed(1) );
			if (lines.length) { msg += ' ' + lines.map(function (l) { return l.text; }).join(' '); }
			if (c ? !c.pass : false) { msg = __( 'Check the notes: ', 'foxfire-studio' ) + msg; }
			if (studio.ed && studio.ed.status) { studio.ed.status.textContent = msg; }
			resultInfo.textContent = msg;
		}, function (err) {
			var msg = sprintf( /* translators: %s: error. */ __( 'The one-off version could not be made (%s).', 'foxfire-studio' ), err && err.message ? err.message : __( 'unknown error', 'foxfire-studio' ) );
			if (studio.ed && studio.ed.status) { studio.ed.status.textContent = msg; }
			resultInfo.textContent = msg;
		}).then(function () {
			rvFinishBusy = false;
			rvDownload.disabled = false;
			rvFinish.disabled = false;
			rvDownloadShow();
		});
	}
	if (rvDownload) {
		new MutationObserver(rvDownloadShow).observe(downloadA, { attributes: true, attributeFilter: ['hidden', 'href'], childList: true, characterData: true, subtree: true });
		rvDownloadShow();
		rvDownload.addEventListener('click', function () {
			if (downloadA.hidden || rvFinishBusy) { return; }
			if (rvFinish ? rvFinish.value === '__book' : false) { rvBookKeep(); return; }
			if (rvFinish ? rvFinish.value : false) { rvFinishDownload(rvFinish.value); return; }
			downloadA.click();
		});
	}
	/* Keep as this post's audiobook chapter (1.20.0), from the review's Finish for menu. */
	function rvBookKeep() {
		rvFinishBusy = true;
		rvDownload.disabled = true;
		rvFinish.disabled = true;
		bookKeep(function (t) { rvDownload.textContent = t; }).then(function (k) {
			var msg = __( 'Kept as this post\'s audiobook chapter:', 'foxfire-studio' ) + ' ' + bookDone(k.book, k.check) + ' ' + __( 'The reading on the post is unchanged.', 'foxfire-studio' );
			if (studio.ed && studio.ed.status) { studio.ed.status.textContent = msg; }
			resultInfo.textContent = msg;
		}, function (err) {
			var msg = sprintf( /* translators: %s: error. */ __( 'The audiobook copy could not be made (%s).', 'foxfire-studio' ), err && err.message ? err.message : __( 'unknown error', 'foxfire-studio' ) );
			if (studio.ed && studio.ed.status) { studio.ed.status.textContent = msg; }
			resultInfo.textContent = msg;
		}).then(function () {
			rvFinishBusy = false;
			rvDownload.disabled = false;
			rvFinish.disabled = false;
			rvDownloadShow();
		});
	}
	function useRecording() {
		if (useRecBtns[0].disabled || downloadA.hidden) { return; }
		if (BOOK.credit) { useForCredits(); return; }
		var edited = /edited/i.test(downloadA.textContent);
		var name = downloadA.getAttribute('download') || (slug + '-reading.mp3');
		var withCaps = !!(rv.R ? rv.vtt.getAttribute('href') : false) ? rv.vtt.getAttribute('href') !== '#' : false;
		/* With the music bed (0.24.0): the mix goes up, and the captions move by its lead-in. */
		var music = musicWanted();
		useRecSet(music ? __( 'Adding the music...', 'foxfire-studio' ) : __( 'Uploading...', 'foxfire-studio' ), true);
		var form = new FormData();
		var mixed = null;
		Promise.all([
			music ? musicBuild().then(function (mm) { mixed = mm; useRecSet(__( 'Uploading...', 'foxfire-studio' ), true); return mm.blob; }) : fetch(downloadA.href).then(function (r) { return r.blob(); }),
			withCaps ? fetch(rv.vtt.getAttribute('href')).then(function (r) { return r.text(); }).then(function (t) { return new Blob([music ? shiftVtt(t, musicLead()) : t], { type: 'text/vtt' }); }) : Promise.resolve(null)
		]).then(function (b) {
			form.append('post', String(POST_ID));
			form.append('mp3', b[0], name);
			if (b[1]) { form.append('vtt', b[1], (rv.vtt.getAttribute('download') || slug + '-captions.vtt')); }
			if (lastTakeAt) { form.append('take_at', String(lastTakeAt)); }
			var secs = (studio.lastMp3 ? studio.lastMp3.seconds : 0);
			if (edited ? (studio.ed.take ? studio.ed.cuts.length : false) : false) { secs -= window.RRTEdit.removed(studio.ed.cuts, studio.ED_RATE); }
			if (mixed) { secs = mixed.seconds; form.append('music', String(MUSIC.id)); }
			if (secs > 0) { form.append('duration', secs.toFixed(2)); }
			if (rv.R) { form.append('tempo', String(rv.R.wpm)); }
			var pre = soundPreset();
			/* A WAV (1.6.0) has no bitrate to give. */
			if (!pre || pre.kbps) { form.append('kbps', String(pre ? pre.kbps : 128)); }
			if (edited) { form.append('edited', '1'); }
			return api('recording', form);
		}).then(function (j) {
			studio.unsaved = false;
			sentDone();
			useRecSet(__( 'On the post now', 'foxfire-studio' ), true);
			resultInfo.textContent = sprintf( /* translators: %s: j.captions ? __( ', with its captions for read along', 'foxf. */ __( 'This recording is now the reading on the post%s. ', 'foxfire-studio' ), j.captions ? __( ', with its captions for read along', 'foxfire-studio' ) : (withCaps ? '' : __( ' (the captions will be ready once the review finishes; press it again then to add them)', 'foxfire-studio' )) );
			if (rv.open) { studio.ed.status.textContent = resultInfo.textContent; }
			/* A combined reading on the post (1.3.0): its takes can leave the shelf. */
			if (studio.takeMode === 'combined' ? !!studio.shelfOfferClear : false) { studio.shelfOfferClear(rv.open ? studio.ed.status : resultInfo); }
			if (j.post_link) {
				var a = el('a', '', __( 'View the post', 'foxfire-studio' ));
				a.href = j.post_link;
				a.target = '_blank';
				a.rel = 'noopener';
				resultInfo.appendChild(a);
			}
			/* Keep an audiobook copy (1.20.0): the same take finished for audiobooks, once the reading is on the post. */
			if (BOOK.keep ? BOOK.can : false) {
				var line = el('span', 'tp-book-line', '');
				resultInfo.appendChild(document.createTextNode(' '));
				resultInfo.appendChild(line);
				var say = function (t) { line.textContent = t; };
				bookKeep(say).then(function (k) {
					say(__( 'An audiobook copy is kept too:', 'foxfire-studio' ) + ' ' + bookDone(k.book, k.check));
				}, function (err) {
					say(sprintf( /* translators: %s: error. */ __( 'The audiobook copy could not be made (%s). The reading is on the post; the review\'s Finish for menu can keep one later.', 'foxfire-studio' ), err && err.message ? err.message : __( 'unknown error', 'foxfire-studio' ) ));
				});
			}
		}).catch(function (err) {
			useRecSet(useRecLabel(), false);
			resultInfo.textContent = sprintf( /* translators: %s: err && err.message ? err.message : __( 'unknown error', 'fox. */ __( 'The recording could not be put on the post (%s). It is still here to download.', 'foxfire-studio' ), err && err.message ? err.message : __( 'unknown error', 'foxfire-studio' ) );
			if (rv.open) { studio.ed.status.textContent = resultInfo.textContent; }
		});
	}

	/* A collection's credits (1.20.0): Use for the audiobook keeps only the audiobook copy; nothing goes on any post. */
	function useForCredits() {
		var say = function (t) { useRecSet(t, true); };
		bookKeep(say).then(function (k) {
			studio.unsaved = false;
			useRecSet(__( 'Kept for the audiobook', 'foxfire-studio' ), true);
			resultInfo.textContent = __( 'These credits are kept for the collection\'s audiobook:', 'foxfire-studio' ) + ' ' + bookDone(k.book, k.check) + ' ';
			if (BOOK.colEdit) {
				var a = el('a', '', __( 'Back to the collection', 'foxfire-studio' ));
				a.href = BOOK.colEdit;
				resultInfo.appendChild(a);
			}
			if (rv.open) { studio.ed.status.textContent = resultInfo.textContent; }
		}, function (err) {
			useRecSet(useRecLabel(), false);
			resultInfo.textContent = sprintf( /* translators: %s: error. */ __( 'The credits could not be kept (%s). The take is still here.', 'foxfire-studio' ), err && err.message ? err.message : __( 'unknown error', 'foxfire-studio' ) );
			if (rv.open) { studio.ed.status.textContent = resultInfo.textContent; }
		});
	}

	/* Captions for the recording, built from the script with Whisper's timings. */
	function buildVtt(R, cuts) {
		function stamp(t) {
			t = Math.max(0, t);
			var h = Math.floor(t / 3600);
			var m = Math.floor((t % 3600) / 60);
			var s = t % 60;
			return (h < 10 ? '0' : '') + h + ':' + (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s.toFixed(3);
		}
		/* 1.33.0: a reading in another language names it in the file's header; English captions are written as before. */
		var lines = LANG.en ? ['WEBVTT', ''] : ['WEBVTT', 'Language: ' + LANG.tag, ''];
		var n = 1;
		var E = cuts ? window.RRTEdit : null;
		function at(t) { return E ? E.mapTime(cuts, t) : t; }
		R.sentences.forEach(function (S) {
			var ks = [];
			for (var k = S.first; k <= S.last; k++) {
				// After editing, words that were cut leave the captions too.
				if (E) {
					var wq = R.words[k];
					if (wq.s !== null) { if (E.isCut(cuts, wq.s, wq.e)) { continue; } }
				}
				ks.push(k);
			}
			if (!ks.length) { return; }
			for (var a = 0; a < ks.length; a += 12) {
				var part = ks.slice(a, a + 12);
				if (ks.length - (a + 12) > 0 && ks.length - (a + 12) < 4) { part = ks.slice(a); a = ks.length; }
				var s = at(R.words[part[0]].s);
				var e = at(R.words[part[part.length - 1]].e);
				if (e - s < 0.6) { e = s + 0.6; }
				lines.push(String(n++));
				lines.push(stamp(s) + ' --> ' + stamp(e));
				lines.push(part.map(function (q) { return W[q].el.textContent; }).join(' '));
				lines.push('');
			}
		});
		return lines.join('\n');
	}

	function showReview(R, metrics, audioUrl, seconds, replaceLast) {
		rv.R = R;
		if (!replaceLast) {
			rv.noteOffer = null;
			/* A new take starts with no edits and no edited version. */
			if (rv.edited) { URL.revokeObjectURL(rv.edited.url); }
			rv.edited = null;
			rv.view = 'recorded';
			if (rv.editedAudio) { rv.editedAudio.pause(); }
		}
		rv.filter = '';
		var takes;
		if (studio.takeMode === 'combined') {
			/* A combined reading (1.3.0) is not a take: the takes it came from keep their own numbers, and the history is left alone. */
			takes = loadTakes();
			lastTakeAt = 0;
		} else if (replaceLast) {
			takes = loadTakes();
			if (takes.length) {
				var sum = takeSummary(R, seconds, metrics ? metrics.room : null, metrics);
				sum.at = takes[takes.length - 1].at;
				takes[takes.length - 1] = sum;
				try { window.localStorage.setItem(TAKES_KEY, JSON.stringify(takes)); } catch (e) {}
				sendTake(sum, R);
			}
		} else {
			var fresh = takeSummary(R, seconds, metrics ? metrics.room : null, metrics);
			takes = saveTake(fresh, R);
			lastTakeAt = fresh.at;
		}
		/*
		 * Practice (0.14.0): a drill is compared with the last drill and a full
		 * reading with the last full reading; a focus also with the last take
		 * that worked on the same habit.
		 */
		var cur = takes.length ? takes[takes.length - 1] : {};
		var prev = PR ? PR.previous(takes, cur, false) : (takes.length >= 2 ? takes[takes.length - 2] : null);
		rv.prevFocus = PR ? (cur.focus ? PR.previous(takes, cur, true) : null) : null;
		var focus = PR ? PR.focuses[cur.focus] : null;
		if (audioUrl) { rv.audio.src = audioUrl; }
		rv.head.textContent = sprintf( /* translators: 1: (cur.drill ? __( 'Opening drill: ', 'foxfire-studio' ) : ''), 2: R.wpm, 3: R.problems.length. */ _n( '%1$s%% of words read as written, %2$s words a minute, %3$s thing flagged.', '%1$s%% of words read as written, %2$s words a minute, %3$s things flagged.', R.problems.length, 'foxfire-studio' ), (studio.takeMode === 'combined' ? __( 'Combined reading: ', 'foxfire-studio' ) : (cur.drill ? __( 'Opening drill: ', 'foxfire-studio' ) : '')) + Math.round(R.accuracy * 100), R.wpm, R.problems.length );
		rv.prev = prev;
		rv.secs = seconds;
		renderStats(R, seconds, prev);
		rv.notes.innerHTML = '';
		if (metrics) {
			/* 1.15.1: the Endings line says how many Faded ending flags stand. */
			var nFaded = R.sentences.filter(function (S) { return S.flags.indexOf('faded') !== -1; }).length;
			/* 1.31.0: the Pace line uses the review's own number (the words Whisper heard, first to last), the same as the At a Glance tile. */
			var judged = studio.paceJudge ? studio.paceJudge() : null;
			var readLines = describeReading(studio.paceFromReview(metrics, R), false, fmt, judged, nFaded);
			/* 1.33.0: a full review in another language adds its pace to the reader's own, once per take. */
			if (!LANG.en && !cur.drill && !cur.langPaced && studio.takeMode !== 'combined' && R.wpm > 0) { cur.langPaced = true; studio.langPaceKeep(R.wpm); }
			var order = PR ? PR.orderLines(readLines, cur.focus) : { first: [], rest: readLines };
			var addLines = function (into, list) {
				list.forEach(function (line) {
					/* The Pace line shows with a Pace focus, or (1.11.0) when the post has a kind of reading to judge it against. */
					if (line.label === __( 'Pace', 'foxfire-studio' ) && !(focus ? focus.line === __( 'Pace', 'foxfire-studio' ) : false) && !judged) { return; }
					into.appendChild(el('dt', '', line.label));
					into.appendChild(el('dd', '', line.text));
				});
			};
			var dl = el('dl');
			addLines(dl, order.first.length ? order.first : order.rest);
			rv.notes.appendChild(dl);
			if (order.first.length) {
				var more = el('details', 'tp-rv-more');
				more.appendChild(el('summary', '', __( 'Everything else', 'foxfire-studio' )));
				var dl2 = el('dl');
				addLines(dl2, order.rest);
				more.appendChild(dl2);
				rv.notes.appendChild(more);
			}
		}
		/* The focus's flags come first: the list opens filtered to them. */
		if (focus ? focus.kinds.length : false) { rv.filter = focus.kinds.join(','); }
		renderProblems();
		renderLearned();
		renderTakes(takes.filter(function (t) { return (t.drill || '') === (cur.drill || ''); }), !!cur.drill);
		renderTimeline(R, seconds);
		renderScript(R);
		renderPauseMap(R);
		noteTitles();
		/* Flags after edits (1.1.0): keep the marks, or the edited version's flags, through a refresh. */
		rv.cutKey = studio.edKey ? studio.edKey() : rv.cutKey;
		renderFlags();
		var vtt = new Blob([buildVtt(R)], { type: 'text/vtt' });
		rv.vtt.href = URL.createObjectURL(vtt);
		rv.vtt.setAttribute('download', slug + '-captions.vtt');
		clipBtn.hidden = !(RRT.clip ? RRT.clip.boot : null);
		if (shareBtn) { shareBtn.hidden = !(STATS ? POST_ID : false); }
		/* Hear the polish (1.13.0). */
		if (studio.polishShow) { studio.polishShow(); }
	}

	/*
	 * Clips for social media (0.13.0): "Make a clip" opens the clip maker with
	 * this take and the review's word timings, so captions need no second pass.
	 * Its files load only when the button is pressed.
	 */
	var clipBtn = document.getElementById('tpRvClip');
	function loadOnce(tag, url) {
		return new Promise(function (resolve, reject) {
			if (document.querySelector('[data-rrt-src="' + url + '"]')) { resolve(); return; }
			var n = document.createElement(tag);
			n.setAttribute('data-rrt-src', url);
			if ('link' === tag) { n.rel = 'stylesheet'; n.href = url; } else { n.src = url; }
			n.onload = function () { resolve(); };
			n.onerror = function () { reject(new Error(sprintf( /* translators: %s: url. */ __( 'could not load %s', 'foxfire-studio' ), url ))); };
			document.head.appendChild(n);
		});
	}
	if (clipBtn) {
		clipBtn.addEventListener('click', function () {
			var c = RRT.clip;
			if (!c || !c.boot || !rv.R) { return; }
			clipBtn.disabled = true;
			var words = [];
			rv.R.words.forEach(function (w, k) {
				if (w.s !== null && W[k]) { words.push({ t: W[k].el.textContent, s: w.s, e: w.e, sn: W[k].sent, b: W[k].block, k: W[k].kind }); }
			});
			/* 1.30.0: Mediabunny writes the MP4; clip.js loads the AAC add-on (c.aac) only where the browser has no AAC. */
			window.RRTClipAac = c.aac || '';
			Promise.all([loadOnce('link', c.css), loadOnce('script', c.mediabunny)]).then(function () {
				return loadOnce('script', c.js);
			}).then(function () {
				var boot = {};
				Object.keys(c.boot).forEach(function (k) { boot[k] = c.boot[k]; });
				boot.audio = rv.audio.currentSrc || rv.audio.src;
				boot.words = words;
				boot.curve = null;
				/* The site's suggestions are for the reading on the post, not this take (1.7.0). */
				boot.suggest = null;
				window.RRTClip.open(boot);
			}).catch(function (err) {
				window.alert(sprintf( /* translators: %s: err.message. */ __( 'The clip maker could not load (%s).', 'foxfire-studio' ), err.message ));
			}).then(function () { clipBtn.disabled = false; });
		});
	}

	/*
	 * Share a take for feedback (0.17.0): the review's audio, the script with
	 * its word timings, and the people chosen go to the site. A friend signs
	 * in and leaves comments at any moment for 14 days.
	 */
	var shareBtn = document.getElementById('tpRvShare');
	function shareOpen() {
		var old = document.querySelector('.tp-share');
		if (old) { old.parentNode.removeChild(old); }
		var box = el('div', 'tp-result tp-share is-on');
		box.setAttribute('role', 'dialog');
		box.setAttribute('aria-label', __( 'Share for feedback', 'foxfire-studio' ));
		box.appendChild(el('h2', '', __( 'Share for feedback', 'foxfire-studio' )));
		box.appendChild(el('p', '', __( 'Choose who can hear this take and leave comments. They sign in with their own account on this site; add a friend under Users as a Subscriber first. The share ends after 14 days, or sooner from Foxfire Studio, Shared takes, and then the audio is deleted from the site.', 'foxfire-studio' )));
		var list = el('div', 'tp-share-people');
		list.appendChild(el('p', 'tp-share-wait', __( 'Loading the people on this site...', 'foxfire-studio' )));
		box.appendChild(list);
		var inviteLab = el('label', 'tp-share-invite');
		var invite = document.createElement('input');
		invite.type = 'checkbox';
		invite.checked = true;
		inviteLab.appendChild(invite);
		inviteLab.appendChild(document.createTextNode(__( ' Email them the link', 'foxfire-studio' )));
		box.appendChild(inviteLab);
		var out = el('div', 'tp-share-out');
		box.appendChild(out);
		var row = el('div', 'tp-row');
		var go = el('button', 'tp-btn primary', __( 'Share', 'foxfire-studio' ));
		go.type = 'button';
		go.disabled = true;
		var close = el('button', 'tp-btn', __( 'Close', 'foxfire-studio' ));
		close.type = 'button';
		row.appendChild(go);
		row.appendChild(close);
		box.appendChild(row);
		document.body.appendChild(box);
		close.setAttribute('data-dlg-close', '');
		close.addEventListener('click', function () { box.parentNode.removeChild(box); });
		api('share-people?post=' + POST_ID).then(function (j) {
			list.innerHTML = '';
			var people = j.people || [];
			if (!people.length) {
				list.appendChild(el('p', 'tp-share-wait', __( 'There is nobody else with an account here yet. Add a friend under Users (as a Subscriber), then come back.', 'foxfire-studio' )));
				return;
			}
			people.forEach(function (p) {
				var lab = el('label', 'tp-share-person');
				var cb = document.createElement('input');
				cb.type = 'checkbox';
				cb.value = String(p.id);
				cb.addEventListener('change', function () { go.disabled = !list.querySelector('input:checked'); });
				lab.appendChild(cb);
				lab.appendChild(document.createTextNode(' ' + p.name));
				if (p.role) { lab.appendChild(el('span', 'tp-share-role', p.role)); }
				list.appendChild(lab);
			});
		})['catch'](function (err) {
			list.innerHTML = '';
			list.appendChild(el('p', 'tp-share-wait', sprintf( /* translators: %s: err.message. */ __( 'The list of people could not be loaded (%s).', 'foxfire-studio' ), err.message )));
		});
		go.addEventListener('click', function () {
			var ids = Array.prototype.map.call(list.querySelectorAll('input:checked'), function (c) { return parseInt(c.value, 10); });
			if (!ids.length) { return; }
			go.disabled = true;
			go.textContent = __( 'Uploading...', 'foxfire-studio' );
			var words = [];
			W.forEach(function (w, k) {
				var r = rv.R ? rv.R.words[k] : null;
				words.push({ t: w.el.textContent, b: w.block, k: w.kind, s: r ? r.s : null, e: r ? r.e : null });
			});
			var src = rv.audio.currentSrc || rv.audio.src;
			fetch(src).then(function (r) { return r.blob(); }).then(function (blob) {
				var form = new FormData();
				form.append('post', String(POST_ID));
				form.append('viewers', JSON.stringify(ids));
				form.append('invite', invite.checked ? '1' : '0');
				form.append('words', JSON.stringify(words));
				if (lastTakeAt) { form.append('take_at', String(lastTakeAt)); }
				if (isFinite(rv.audio.duration)) { form.append('duration', rv.audio.duration.toFixed(2)); }
				var ext = /mpeg|mp3/.test(blob.type) ? 'mp3' : (/wav/.test(blob.type) ? 'wav' : (/webm/.test(blob.type) ? 'webm' : 'audio'));
				form.append('audio', blob, slug + '-take.' + ext);
				return api('shares', form);
			}).then(function (j) {
				go.textContent = __( 'Shared', 'foxfire-studio' );
				out.innerHTML = '';
				out.appendChild(el('p', '', sprintf( /* translators: 1: j.ends, 2: invite.checked ? '. ' + (j.invited === ids.length ? __( 'Eve. */ __( 'Shared until %1$s%2$s', 'foxfire-studio' ), j.ends, invite.checked ? '. ' + (j.invited === ids.length ? __( 'Everyone you chose has an email with the link.', 'foxfire-studio' ) : sprintf( /* translators: 1: j.invited, 2: ids.length. */ __( '%1$s of %2$s emails went out; send the link below to anyone who did not get one.', 'foxfire-studio' ), j.invited, ids.length )) : '. Send them this link:' )));
				var linkRow = el('div', 'tp-share-link');
				var input = document.createElement('input');
				input.type = 'text';
				input.readOnly = true;
				input.value = j.url;
				linkRow.appendChild(input);
				var copy = el('button', 'tp-btn', __( 'Copy', 'foxfire-studio' ));
				copy.type = 'button';
				copy.addEventListener('click', function () {
					input.select();
					(navigator.clipboard ? navigator.clipboard.writeText(j.url) : Promise.reject())['catch'](function () { document.execCommand('copy'); });
					copy.textContent = __( 'Copied', 'foxfire-studio' );
				});
				linkRow.appendChild(copy);
				out.appendChild(linkRow);
				var a = el('a', '', __( 'See every shared take', 'foxfire-studio' ));
				a.href = STATS.shares || '#';
				a.target = '_blank';
				a.rel = 'noopener';
				out.appendChild(a);
				list.querySelectorAll('input').forEach(function (c) { c.disabled = true; });
				invite.disabled = true;
			})['catch'](function (err) {
				go.disabled = false;
				go.textContent = __( 'Share', 'foxfire-studio' );
				out.innerHTML = '';
				out.appendChild(el('p', 'tp-share-err', sprintf( /* translators: %s: err && err.message ? err.message : __( 'unknown error', 'fox. */ __( 'The take could not be shared (%s).', 'foxfire-studio' ), err && err.message ? err.message : __( 'unknown error', 'foxfire-studio' ) )));
			});
		});
	}
	if (shareBtn) { shareBtn.addEventListener('click', function () { shareOpen(); }); }

	/*
	 * Starts the review, or on a phone (0.19.1) offers it: the take and its
	 * MP3 are ready first, so if the phone closes the page nothing is lost.
	 */
	function startReview(samples, rate, audioUrl, seconds, metrics, onReady) {
		/* The take in a holder, so the lighter path below can let it go while Whisper listens (1.31.0). */
		var hold = { s: samples };
		samples = null;
		var say = function (msg) { whisperStatus.textContent = msg; };
		var careful = studio.PHONE || LOW_MEM;
		if (!careful) { runReview(hold, rate, audioUrl, seconds, metrics, say, onReady); return; }
		/* 1.31.0: on a phone, takes up to five minutes are reviewed by themselves, on the lighter path. */
		/* A phone take mode take (1.12.0) is on the site for the computer already, so it still waits to be asked. */
		var onSite = studio.lastRaw ? !!studio.lastRaw.onSite : false;
		if (seconds <= studio.PHONE_AUTO_S && !studio.lastRunStopped && !onSite) { runReview(hold, rate, audioUrl, seconds, metrics, say, onReady); return; }
		whisperStatus.textContent = sprintf( /* translators: %s: lastRunStopped ? __( 'Last time Whisper ran on this phone, t. */ __( '%sThe full Whisper review needs more memory than phones give a web page, so it has not started. Download the MP3, then open it on your computer with Review a recording. ', 'foxfire-studio' ), studio.lastRunStopped ? __( 'Last time Whisper ran on this phone, the page closed before it finished. ', 'foxfire-studio' ) : '' );
		var go = document.createElement('button');
		go.type = 'button';
		go.className = 'tp-btn tp-wtry';
		go.textContent = __( 'Try it here anyway', 'foxfire-studio' );
		go.addEventListener('click', function () {
			go.blur();
			studio.lastRunStopped = false;
			runReview(hold, rate, audioUrl, seconds, metrics, say, onReady);
		});
		whisperStatus.appendChild(go);
		/* Phone take mode (1.12.0): the take is on the site already. */
		if (studio.lastRaw ? studio.lastRaw.onSite : false) {
			whisperStatus.firstChild.textContent = sprintf( /* translators: %s: lastRunStopped ? __( 'Last time Whisper ran on this phone, t. */ __( '%sThe full Whisper review needs more memory than phones give a web page, so it has not started. The take is already on the site: open the teleprompter for this post on your computer to review it there. ', 'foxfire-studio' ), studio.lastRunStopped ? __( 'Last time Whisper ran on this phone, the page closed before it finished. ', 'foxfire-studio' ) : '' );
		} else if (SENT ? (POST_ID ? !!studio.lastRaw : false) : false) {
			whisperStatus.firstChild.textContent = sprintf( /* translators: %s: lastRunStopped ? __( 'Last time Whisper ran on this phone, t. */ __( '%sThe full Whisper review needs more memory than phones give a web page, so it has not started. Send the take to your computer and review it there, or download the MP3. ', 'foxfire-studio' ), studio.lastRunStopped ? __( 'Last time Whisper ran on this phone, the page closed before it finished. ', 'foxfire-studio' ) : '' );
			var send = document.createElement('button');
			send.type = 'button';
			send.className = 'tp-btn primary tp-wsend';
			send.textContent = __( 'Send to my computer', 'foxfire-studio' );
			send.addEventListener('click', function () { send.blur(); sendToComputer(send, go); });
			whisperStatus.insertBefore(send, go);
		}
	}
	/*
	 * Uploads the take's original to the site, for this account only, so the
	 * computer's teleprompter can offer it (0.21.0). Shows how far the upload
	 * has got, since a long take is tens of megabytes on mobile data.
	 */
	function sendToComputer(btn, other) {
		var r = studio.lastRaw;
		if (!r || !SENT) { return; }
		if (SENT.max ? r.blob.size > SENT.max : false) {
			whisperStatus.appendChild(el('span', 'tp-wsent', sprintf( /* translators: 1: (r.blob.size / 1048576).toFixed(0), 2: (SENT.max / 1048576).toFixed(0). */ __( ' This take is %1$s MB, more than this site accepts (%2$s MB). Download it instead.', 'foxfire-studio' ), (r.blob.size / 1048576).toFixed(0), (SENT.max / 1048576).toFixed(0) )));
			return;
		}
		btn.disabled = true;
		other.disabled = true;
		var note = el('span', 'tp-wsent', ' Sending... 0%');
		whisperStatus.appendChild(note);
		var ext = /wav/.test(r.blob.type) ? 'wav' : (/mp4/.test(r.blob.type) ? 'm4a' : (/ogg/.test(r.blob.type) ? 'ogg' : 'webm'));
		var form = new FormData();
		form.append('post', String(POST_ID));
		form.append('audio', r.blob, slug + '-reading.' + ext);
		form.append('words', String(r.words || 0));
		form.append('seconds', String((r.seconds || 0).toFixed(2)));
		form.append('drill', r.drill || '');
		form.append('drill_s', String(r.drillS || 0));
		form.append('focus', r.focus || '');
		/* The setup it was recorded on (1.13.0), so its EQ follows the take to the computer. */
		form.append('setup', r.setup ? r.setup.key : '');
		form.append('setup_label', r.setup ? r.setup.label : '');
		var x = new XMLHttpRequest();
		x.open('POST', sentUrl('sent'));
		x.setRequestHeader('X-WP-Nonce', SENT.nonce);
		x.upload.onprogress = function (e) {
			if (e.lengthComputable) { note.textContent = __( ' Sending... ', 'foxfire-studio' ) + Math.round(e.loaded / e.total * 100) + '%'; }
		};
		x.onload = function () {
			var j = {};
			try { j = JSON.parse(x.responseText); } catch (err) {}
			if (x.status >= 200 && x.status < 300 && j.take) {
				btn.textContent = __( 'Sent', 'foxfire-studio' );
				note.textContent = sprintf( /* translators: %s: j.take.ends. */ __( ' Sent. Open the teleprompter on your computer and it will be waiting, until %s at most.', 'foxfire-studio' ), j.take.ends );
				/* The site holds it now, so this phone need not keep it for recovery. */
				studio.unsaved = false;
				forgetTake();
				other.disabled = false;
				return;
			}
			btn.disabled = false;
			other.disabled = false;
			note.textContent = sprintf( /* translators: %s: j.message || (sprintf( __( 'error %s', 'foxfire-studio' ), x. */ __( ' The take could not be sent (%s).', 'foxfire-studio' ), j.message || (sprintf( /* translators: %s: x.status. */ __( 'error %s', 'foxfire-studio' ), x.status )) );
		};
		x.onerror = function () {
			btn.disabled = false;
			other.disabled = false;
			note.textContent = __( ' The take could not be sent: the connection dropped. Try again on Wi-Fi.', 'foxfire-studio' );
		};
		x.send(form);
	}

	/* Run the whole review for a finished recording or a chosen file. */
	/*
	 * hold: { s: the take Whisper hears, at full rate }. On a phone (1.31.0)
	 * the lighter path closes voice following's worker first, and for a take
	 * too long for the shelf lets that untrimmed copy go once the 16 kHz one
	 * is made, so Whisper has the memory to itself. The MP3, the cleaned take
	 * behind it (for editing) and the copy kept for recovery stay; Hear the
	 * polish's Cleanup switch, which cleans the untrimmed copy again, is then
	 * not offered for that take.
	 */
	function runReview(hold, rate, audioUrl, seconds, metrics, onStatus, onReady) {
		/* A combined reading (1.3.0) brings its takes' own Whisper words, so Whisper need not listen again. */
		var given = studio.presetChunks;
		studio.presetChunks = null;
		if (!given ? studio.engineBusy : false) { onStatus(__( 'Whisper is still busy with the last recording.', 'foxfire-studio' )); return; }
		var light = studio.PHONE || LOW_MEM;
		if (light ? !given : false) {
			if (studio.closeVoice) { studio.closeVoice(); }
		}
		(given ? Promise.resolve(given) : to16k(hold.s, rate).then(function (a16) {
			if (light ? !studio.shelfWants(seconds) : false) {
				/* The MP3 step's result (studio.lastMp3) holds the same copy for Hear the polish's Cleanup switch, which a long phone take then goes without. */
				if (studio.lastMp3 ? studio.lastMp3.heard === hold.s : false) { studio.lastMp3.heard = null; }
				hold.s = null;
			}
			studio.reviewHeldFull = !!hold.s;
			return transcribe(a16, onStatus);
		})).then(function (chunks) {
			onStatus(__( 'Comparing what you said with the script...', 'foxfire-studio' ));
			return new Promise(function (res) { window.setTimeout(function () { res(chunks); }, 30); });
		}).then(function (chunks) {
			rv.last = { chunks: chunks, metrics: metrics, seconds: seconds };
			var R = compareReading(scriptForReview(), chunks, metrics ? metrics.frames : null, allLearned());
			if (!R) {
				onStatus(__( 'Whisper did not hear enough of the script in this recording to review it.', 'foxfire-studio' ));
				return;
			}
			// Editing (0.8.0) works on the take this review belongs to, while it is in memory.
			studio.edSetTake(studio.lastMp3 ? (downloadA.href === audioUrl ? studio.lastMp3 : null) : null);
			showReview(R, metrics, audioUrl, seconds);
			var ready = given ? sprintf( /* translators: 1: Math.round(R.accuracy * 100), 2: R.problems.length. */ __( 'Your combined reading is ready: %1$s%% accuracy, %2$s flagged, from the words Whisper heard in each take.', 'foxfire-studio' ), Math.round(R.accuracy * 100), R.problems.length ) : sprintf( /* translators: 1: Math.round(R.accuracy * 100), 2: R.problems.length, 3: engineFrom || __( 'ran on this page', 'foxfire-studio' ). */ __( 'Your full review is ready: %1$s%% accuracy, %2$s flagged. Whisper %3$s.', 'foxfire-studio' ), Math.round(R.accuracy * 100), R.problems.length, studio.engineFrom || __( 'ran on this page', 'foxfire-studio' ) );
			/* How long it took, so the two models can be compared on this computer. */
			if (given ? false : studio.whisperTimed) {
				ready += sprintf( /* translators: 1: fmt(whisperListenS), 2: whisperLoadS >= 2 ? sprintf( __( ', after %s loading the mod. */ __( ' It listened for %1$s%2$s.', 'foxfire-studio' ), fmt(studio.whisperListenS), studio.whisperLoadS >= 2 ? sprintf( /* translators: %s: fmt(whisperLoadS). */ __( ', after %s loading the model', 'foxfire-studio' ), fmt(studio.whisperLoadS) ) : '' );
			}
			onStatus(ready);
			onReady();
			/* The take shelf (1.3.0): every reviewed take of the post is kept for Combine takes. */
			var shelved = (given || !hold.s) ? Promise.resolve('') : studio.shelfAdd(hold.s, rate, chunks, R);
			hold.s = null;
			shelved.then(function (line) {
				if (line) { ready += line; onStatus(ready); }
			});
			addChapters(R, audioUrl).then(function (n) {
				if (n) { ready += __( ' The MP3 now has ', 'foxfire-studio' ) + n + ' chapters, one for each heading; if you downloaded it already, download it again to get them.'; onStatus(ready); }
			}, function () {}).then(function () {
				/* Voice polish (1.13.0): clicks between words are found from Whisper's word times, so the MP3 is made again now. */
				return studio.polishAfterReview ? studio.polishAfterReview(chunks) : '';
			}).then(function (line) {
				if (line) { ready += ' ' + line; onStatus(ready); }
			}, function () {});
		}).catch(function (err) {
			onStatus(sprintf( /* translators: %s: err.message. */ __( 'The Whisper review could not finish (%s). Your MP3 is fine.', 'foxfire-studio' ), err.message ));
		});
	}

	/* Open a recording made earlier: clean it up, make an MP3, and review it. */
	reviewFileBtn.addEventListener('click', function () { reviewFileBtn.blur(); fileIn.click(); });
	fileIn.addEventListener('change', function () {
		var file = fileIn.files ? fileIn.files[0] : null;
		fileIn.value = '';
		if (!file) { return; }
		studio.currentTake = null;
		studio.lastRaw = null;
		studio.sentDrillS = 0;
		studio.sentAt = 0;
		studio.takeMode = 'file';
		/* A file's setup is not known (1.13.0): Hear the polish can still try a saved setup's EQ on it. */
		studio.takeSetup = null;
		studio.takeDrill = '';
		studio.takeFocus = settings.focus || '';
		openRecording(file, file.name.replace(/\.[^.]+$/, ''), null, 0);
	});
	/*
	 * Clean up, make the MP3, read out and review any audio: a file the
	 * reader opens, or a recovered take (which also offers its WAV master).
	 */
	function openRecording(file, base, master, wordsRead) {
		if (master) { studio.takeMode = 'recovered'; }
		var name = master ? base + '.wav' : file.name;
		resultEl.classList.add('is-on');
		resultAudio.src = URL.createObjectURL(file);
		downloadA.hidden = true;
		downloadRawA.hidden = true;
		if (master) {
			downloadRawA.href = master;
			downloadRawA.setAttribute('download', base + '.wav');
			downloadRawA.textContent = __( 'Original (WAV)', 'foxfire-studio' );
			downloadRawA.hidden = false;
			studio.unsaved = true;
		}
		openReviewBtn.hidden = true;
		readoutEl.classList.remove('is-on');
		readoutEl.innerHTML = '';
		whisperStatus.textContent = '';
		resultInfo.textContent = sprintf( /* translators: %s: name. */ __( 'Opening %s...', 'foxfire-studio' ), name );
		toMp3(file, mp3Progress).then(function (res) {
			var url = URL.createObjectURL(res.blob);
			resultAudio.src = url;
			downloadA.href = url;
			var wavOut = studio.finishExt ? studio.finishExt() === 'wav' : false;
			downloadA.setAttribute('download', base + (res.room ? '-cleaned' : '') + (wavOut ? '.wav' : '.mp3'));
			downloadA.textContent = wavOut ? (res.room ? __( 'Download cleaned WAV', 'foxfire-studio' ) : __( 'Download WAV', 'foxfire-studio' )) : (res.room ? __( 'Download cleaned MP3', 'foxfire-studio' ) : __( 'Download MP3', 'foxfire-studio' ));
			downloadA.hidden = false;
			resultInfo.textContent = sprintf( /* translators: 1: name, 2: fmt(res.seconds), 3: res.room ? __( ', cleaned up and leveled.', 'foxfire-studio'. */ __( '%1$s, %2$s long%3$s', 'foxfire-studio' ), name, fmt(res.seconds), res.room ? __( ', cleaned up and leveled.', 'foxfire-studio' ) : __( ', leveled.', 'foxfire-studio' ) );
			// How far the reading got: known for a recovered take, otherwise assume the whole post.
			var keyedCount = wordsRead || W.filter(function (w) { return w.key !== ''; }).length;
			/* A combined reading's words (1.3.0), moved by the silence the MP3 step trimmed from its start. */
			if (studio.combinedChunks) {
				var shift = res.start || 0;
				studio.presetChunks = studio.combinedChunks.map(function (c) { return { text: c.text, timestamp: [Math.max(0, c.timestamp[0] - shift), Math.max(0, c.timestamp[1] - shift)] }; });
				studio.combinedChunks = null;
			}
			window.setTimeout(function () {
				var metrics = showReadout(res, keyedCount, false);
				if (settings.whisper || studio.presetChunks) {
					startReview(res.heard || res.samples, res.rate, url, res.seconds, metrics, function () {
						openReviewBtn.hidden = false;
						openReview();
					});
				}
			}, 60);
		})['catch'](function () {
			resultInfo.textContent = master ? __( 'The MP3 could not be made here, but the recording is safe: download the original (WAV).', 'foxfire-studio' ) : __( 'That file could not be opened as audio.', 'foxfire-studio' );
		});
	}
	/*
	 * Open a reading built from several takes (1.3.0) like any recording:
	 * cleanup, the MP3 and the read-out run once over the whole of it, and
	 * the review uses the words Whisper heard in each take. names: the takes
	 * used; joins: how many joins it has.
	 */
	studio.openCombined = function (blob, chunks, names, joins) {
		studio.currentTake = null;
		studio.lastRaw = null;
		studio.sentDrillS = 0;
		studio.sentAt = 0;
		studio.takeDrill = '';
		studio.takeFocus = '';
		studio.combinedChunks = chunks;
		openRecording(blob, slug + '-combined', URL.createObjectURL(blob), 0);
		studio.takeMode = 'combined';
		studio.takeSetup = null;
		resultInfo.textContent = sprintf( /* translators: 1: the takes used, such as Take 1 and Take 3, 2: how many joins. */ _n( 'Opening the reading combined from %1$s, with %2$s join...', 'Opening the reading combined from %1$s, with %2$s joins...', joins, 'foxfire-studio' ), names.join(', '), joins );
	};
	/* Open a take sent from the phone (0.21.0), the way an unfinished take is recovered. */
	function openSent(t) {
		resultEl.classList.add('is-on');
		resultInfo.textContent = sprintf( /* translators: %s: (t.bytes / 1048576).toFixed(1). */ __( 'Fetching the take from your phone (%s MB)...', 'foxfire-studio' ), (t.bytes / 1048576).toFixed(1) );
		fetch(sentUrl('sent/' + t.id + '/audio'), { credentials: 'same-origin', headers: { 'X-WP-Nonce': SENT.nonce } }).then(function (r) {
			if (!r.ok) { throw new Error(String(r.status)); }
			return r.blob();
		}).then(function (blob) {
			studio.currentTake = null;
			studio.lastRaw = null;
			studio.takeDrill = t.drill || '';
			studio.sentDrillS = t.drill_s || 0;
			studio.sentAt = (t.sent || 0) * 1000;
			studio.takeFocus = t.focus || '';
			studio.sentOpenId = t.id;
			studio.takeSetup = t.setup ? { key: t.setup, label: t.setup_label || t.setup } : null;
			openRecording(blob, slug + '-reading', URL.createObjectURL(blob), t.words || 0);
			studio.takeMode = 'phone';
		})['catch'](function () {
			resultInfo.textContent = __( 'The take from your phone could not be fetched. It is still on the site; reload the page to try again.', 'foxfire-studio' );
		});
	}
	/* Bring back a take that was never downloaded. */
	function recoverTake(t) {
		resultEl.classList.add('is-on');
		resultInfo.textContent = __( 'Recovering the recording...', 'foxfire-studio' );
		var bits = t.bits === 24 ? 24 : 16;
		takeStore.load(t.id, bits).then(function (parts) {
			var total = 0;
			parts.forEach(function (p) { total += p.byteLength / (bits / 8); });
			if (!total) { throw new Error('empty'); }
			studio.currentTake = t.id;
			studio.sentAt = 0;
			studio.sentDrillS = 0;
			studio.takeSetup = t.setup ? { key: t.setup, label: t.setupLabel || t.setup } : null;
			var wav = wavBlob(parts, t.rate, total, bits);
			studio.lastRaw = { blob: wav, words: t.words || 0, seconds: total / t.rate, drill: '', drillS: 0, focus: '' };
			openRecording(wav, (t.slug || slug) + '-reading', URL.createObjectURL(wav), t.words || 0);
		})['catch'](function () {
			resultInfo.textContent = __( 'The recording could not be recovered from this browser.', 'foxfire-studio' );
		});
	}
	openReviewBtn.addEventListener('click', openReview);
})();
