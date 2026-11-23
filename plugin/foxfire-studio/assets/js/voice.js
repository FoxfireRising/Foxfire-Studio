/*
 * Foxfire Studio: voice following (1.14.0). A small speech model on this
 * device (follow-worker.js) hears the reader, and RRTFollow (follow.js)
 * works out where in the script they are: the word being read stays on the
 * reading line, a pause stops the scroll, an ad-lib holds the place, and a
 * skip or going back is followed once a few words line up. If the model
 * cannot load or the device falls behind, the timed pace takes over and a
 * line says why. Also the pace over the last 30 s, and the first look shown
 * the moment a take stops.
 *
 * While a take is recorded the follower hears the recorder's own copy of the
 * microphone (record.js calls studio.followFeed); otherwise it opens the
 * microphone itself. Nothing it hears is kept or sent anywhere.
 *
 * Until 1.13.0 this file used the browser's speech recognition, which sends
 * the audio to an outside service in most browsers.
 * Shares window.RRTStudio with the studio's other files (made in studio-core.js;
 * docs/ARCHITECTURE.md maps them).
 */
(function () {
	/* Translations (0.25.0): WordPress's wp.i18n when the page has it, otherwise the English text as written. */
	var rrtI18n = (typeof wp === 'object' ? (wp ? wp.i18n : null) : null) || null;
	var __ = rrtI18n ? rrtI18n.__ : function (s) { return s; };
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
	var F = window.RRTFollow;
	var CFG = studio.RRT ? studio.RRT.follow : null;
	var body = studio.body;
	var track = studio.track;
	var voiceIn = studio.voiceIn;
	var heardEl = studio.heardEl;
	var msgEl = studio.msgEl;
	var settings = studio.settings;
	var applyLook = studio.applyLook;
	var W = studio.W;
	var setCursor = studio.setCursor;
	var onModeChange = studio.onModeChange;
	var status = studio.status;
	studio.prepareVoice = prepareVoice;
	studio.startListening = startListening;
	studio.stopListening = stopListening;
	studio.followFeed = feed;
	studio.followLine = paceLine;
	studio.followLook = firstLook;
	studio.listening = false;
	if (!track || !F || !CFG) { return; }

	var LEAD = 3;        /* words the highlight may run ahead of the last word heard */
	var LAG_MAX = 1.5;   /* seconds of unheard audio that count as falling behind */
	var LAG_FOR = 3;     /* for this long, before the timed pace takes over */
	var worker = null;
	var ready = null;    /* a promise: true once the model is loaded, false if it cannot be */
	var from = '';
	var follower = null;
	var doneWords = [];
	var partial = [];
	var heardN = 0;
	var sent = 0;        /* seconds of audio sent to the worker this take */
	var behindSince = -1;
	var maxLag = 0;
	var points = [];     /* { t, w } for the pace */
	var clock0 = 0;
	var lastWord = -1;
	var heardWord = 0;   /* the word after the last one heard */
	var heardAt = 0;     /* when the heard word last moved on */
	var lead = { shown: 0, heard: 0, acc: 0 };
	var vad = {};
	var speaking = false;
	var endWordAt = 0;     /* when the latest word came where only the last word was left */
	var END_QUIET = 1.2;   /* seconds with no new word after that before the script ends */
	var want = false;
	var own = null;      /* the follower's own microphone when no take is being recorded */
	var tapping = false;
	var paceEl = document.getElementById('tpPMeterPace');
	var meterEl = document.getElementById('tpPMeter');

	function fail(why) {
		want = false;
		stopOwn();
		tapping = false;
		studio.listening = false;
		body.classList.remove('is-listening');
		settings.voice = false;
		voiceIn.checked = false;
		applyLook();
		studio.takeMode = 'timed';
		msgEl.textContent = why;
		if (paceEl) { paceEl.hidden = true; }
		onModeChange();
	}

	/* Starts the worker and loads the model once; later calls reuse it. */
	function prepareVoice() {
		if (ready) { return ready; }
		ready = new Promise(function (res) {
			try { worker = new Worker(CFG.worker); } catch (e) { res(false); return; }
			var shown = -1;
			worker.onmessage = function (e) {
				var d = e.data;
				if (d.type === 'load') {
					var mb = Math.round(d.got / 1048576);
					if (mb !== shown) {
						shown = mb;
						msgEl.textContent = sprintf( /* translators: 1: megabytes loaded, 2: megabytes in all. */ __( 'Getting voice following ready: %1$s of %2$s MB (usually only the first time in this browser).', 'foxfire-studio' ), mb, Math.round((d.total || CFG.bytes) / 1048576) );
					}
				} else if (d.type === 'ready') {
					from = d.from;
					msgEl.textContent = '';
					res(true);
				} else if (d.type === 'heard') {
					heard(d);
				} else if (d.type === 'error') {
					res(false);
					worker = null;
					ready = null;
					fail(sprintf( /* translators: %s: the reason. */ __( 'Voice following could not start (%s), so the timed pace has taken over.', 'foxfire-studio' ), d.message ));
				}
			};
			worker.onerror = function () {
				res(false);
				worker = null;
				ready = null;
				fail(__( 'Voice following could not start in this browser, so the timed pace has taken over.', 'foxfire-studio' ));
			};
			worker.postMessage({ type: 'init', cfg: { engine: CFG.engine, v: CFG.v || '', files: CFG.files, names: CFG.names, hosts: CFG.hosts, bytes: CFG.bytes } });
		});
		return ready;
	}

	function texts() {
		return W.map(function (w) { return w.el.textContent; });
	}

	/* A new take, or the first listen: a fresh follower at the current word. */
	function freshTake() {
		follower = new F.Follower(F.script(texts()));
		follower.setWord(studio.wi);
		doneWords = [];
		partial = [];
		heardN = 0;
		sent = 0;
		behindSince = -1;
		maxLag = 0;
		points = [];
		clock0 = performance.now();
		lastWord = studio.wi;
		heardWord = studio.wi;
		heardAt = 0;
		lead = { shown: studio.wi, heard: studio.wi, acc: 0 };
		vad = {};
		speaking = false;
		endWordAt = 0;
		if (worker) { worker.postMessage({ type: 'reset' }); }
	}
	studio.followFresh = freshTake;

	function startListening() {
		want = true;
		/* Switched on mid-reading: load the model first, then listen if still wanted. */
		if (!worker) {
			prepareVoice().then(function (ok) { if (ok && want && studio.playing) { startListening(); } });
			return;
		}
		if (!follower || studio.wi !== lastWord) {
			if (!follower) { freshTake(); } else { follower.setWord(studio.wi); lastWord = studio.wi; }
		}
		var rec = studio.recorder;
		/* The recorder's lossless copy of the microphone, when a take is being recorded; else a microphone of its own. */
		tapping = rec ? rec.mimeType === 'audio/wav' : false;
		if (!tapping) { openOwn(); }
		studio.listening = true;
		body.classList.add('is-listening');
		status();
	}
	function stopListening() {
		want = false;
		tapping = false;
		stopOwn();
		studio.listening = false;
		body.classList.remove('is-listening');
		if (paceEl) { paceEl.hidden = true; }
	}

	function openOwn() {
		if (own) { return; }
		var AC = window.AudioContext || window.webkitAudioContext;
		own = { stream: null, ctx: null };
		var me = own;
		navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: false, noiseSuppression: false, autoGainControl: false, channelCount: 1 } }).then(function (s) {
			if (own !== me) { s.getTracks().forEach(function (t) { t.stop(); }); return; }
			me.stream = s;
			me.ctx = new AC();
			var src = me.ctx.createMediaStreamSource(s);
			var url = URL.createObjectURL(new Blob([studio.CAPTURE_SRC], { type: 'application/javascript' }));
			return me.ctx.audioWorklet.addModule(url).then(function () {
				URL.revokeObjectURL(url);
				if (own !== me) { return; }
				var node = new AudioWorkletNode(me.ctx, 'rrt-capture', { numberOfInputs: 1, numberOfOutputs: 1, outputChannelCount: [1], channelCount: 1, channelCountMode: 'explicit' });
				var mute = me.ctx.createGain();
				mute.gain.value = 0;
				src.connect(node);
				node.connect(mute);
				mute.connect(me.ctx.destination);
				node.port.onmessage = function (e) { if (e.data.pcm) { send(e.data.pcm, me.ctx.sampleRate); } };
				node.port.postMessage('rec');
				if (me.ctx.state !== 'running') { me.ctx.resume(); }
			});
		})['catch'](function () {
			if (own === me) { fail(__( 'The microphone was not available, so the timed pace has taken over.', 'foxfire-studio' )); }
		});
	}
	function stopOwn() {
		if (!own) { return; }
		if (own.stream) { own.stream.getTracks().forEach(function (t) { t.stop(); }); }
		if (own.ctx) { try { own.ctx.close(); } catch (e) {} }
		own = null;
	}

	/* From the recorder (record.js): the take's audio as it is recorded. */
	function feed(pcm, rate) {
		if (!tapping || !want) { return; }
		send(pcm, rate);
	}
	function send(pcm, rate) {
		if (!worker || !want) { return; }
		/* How loud this piece is, for leading the highlight while the voice sounds. */
		var e = 0, k;
		for (k = 0; k < pcm.length; k += 2) { e += pcm[k] * pcm[k]; }
		speaking = F.voiced(vad, 10 * Math.log10(e / Math.max(1, pcm.length / 2) + 1e-12), (performance.now() - clock0) / 1000);
		sent += pcm.length / rate;
		worker.postMessage({ type: 'pcm', pcm: pcm, rate: rate });
	}

	function heard(d) {
		if (!follower) { return; }
		var lag = sent - d.at;
		if (lag > maxLag) { maxLag = lag; }
		var now = (performance.now() - clock0) / 1000;
		if (want && lag > LAG_MAX) {
			if (behindSince < 0) { behindSince = now; }
			if (now - behindSince > LAG_FOR) {
				fail(__( 'Voice following fell behind on this device, so the timed pace has taken over.', 'foxfire-studio' ));
				return;
			}
		} else {
			behindSince = -1;
		}
		if (d.done) {
			d.done.forEach(function (s) { doneWords = doneWords.concat(F.words(s)); });
			partial = [];
		}
		if (d.p !== null) { partial = F.words(d.p); }
		if (!want || !studio.playing) { return; }
		/* The reader moved by hand (a click, Page Up): follow from there. */
		if (studio.wi !== lastWord) { follower.setWord(studio.wi); heardWord = studio.wi; }
		var all = doneWords.slice(-12).concat(partial);
		var n = doneWords.length + partial.length;
		if (n > heardN) {
			heardN = n;
			var w = follower.update(all, n);
			if (w !== heardWord) { heardAt = now; }
			if (follower.endHeard) { endWordAt = now; }
			if (w > studio.wi) {
				setCursor(w);
			} else if (w < heardWord - 2 || w < studio.wi - LEAD - 1) {
				/* Going back to read again (not just the lead running ahead of what was heard). */
				studio.jumpWord(w);
			}
			heardWord = w;
			lead.heard = w;
			lead.shown = studio.wi;
			lastWord = studio.wi;
			points.push({ t: now, w: w });
			if (points.length > 400) { points.splice(0, 100); }
			heardEl.textContent = '“' + all.slice(-8).join(' ') + '”';
			showPace(now);
		}
	}

	/*
	 * Each frame while following (studio-core.js calls this): while the voice
	 * sounds, the highlight moves on at the reader's pace, at most LEAD words
	 * past the last word heard (one when nothing has been matched for a
	 * moment, as in an ad-lib), never into the next paragraph and never to
	 * the end, which only a heard word reaches.
	 */
	function tick(dt) {
		if (!want || !follower || !studio.playing) { return; }
		if (studio.wi !== lastWord) { follower.setWord(studio.wi); heardWord = studio.wi; lead = { shown: studio.wi, heard: studio.wi, acc: 0 }; lastWord = studio.wi; }
		var now = (performance.now() - clock0) / 1000;
		var wps = (paceNow() || settings.wpm) / 60;
		var cap = (heardAt && now - heardAt > 1.5) ? 1 : LEAD;
		var hw = Math.min(heardWord, W.length - 1);
		F.leadStep(lead, dt, speaking, wps, cap);
		var to = Math.min(lead.shown, W.length - 1);
		while (to > hw && W[to].block !== W[hw].block) { to--; }
		lead.shown = to;
		if (to > studio.wi) {
			setCursor(to);
			lastWord = studio.wi;
		}
		/*
		 * The last word heard, misheard (1.36.1): once a word has come where only
		 * the last word was left, the script ends when no new word has been heard
		 * for END_QUIET seconds (a reader still adding words keeps it open), and
		 * the take finishes as after any last word.
		 */
		if ((follower.endHeard ? heardWord === W.length - 1 : false) ? now - endWordAt >= END_QUIET : false) {
			heardWord = W.length;
			setCursor(W.length);
		}
	}
	studio.followTick = tick;
	/*
	 * Closes the follower's worker and its model (1.31.0), so the review's
	 * Whisper never shares a phone's memory with it. The next take loads it
	 * again, from the browser's cache.
	 */
	studio.closeVoice = function () {
		if (!worker || studio.listening) { return false; }
		try { worker.terminate(); } catch (e) { /* already gone */ }
		worker = null;
		ready = null;
		return true;
	};

	/* The pace (1.14.0): words per minute over the last 30 s, against the post's kind of reading when it has one. */
	function paceNow() {
		if (studio.takeDrill) { return null; }
		return F.pace(points, (performance.now() - clock0) / 1000);
	}
	function paceLine() {
		var p = paceNow();
		if (!p) { return __( 'Following your voice', 'foxfire-studio' ); }
		var r = studio.paceRange ? studio.paceRange() : null;
		if (r) {
			return sprintf( /* translators: 1: words per minute, 2: kind of reading such as Verse, 3: lowest pace, 4: highest pace. */ __( 'Following your voice, %1$s wpm (%2$s: %3$s to %4$s)', 'foxfire-studio' ), p, r.name, r.lo, r.hi );
		}
		return sprintf( /* translators: %s: words per minute. */ __( 'Following your voice, %s wpm', 'foxfire-studio' ), p );
	}
	function showPace() {
		if (!paceEl) { return; }
		var phone = meterEl ? !meterEl.hidden : false;
		var p = paceNow();
		paceEl.hidden = !(phone && p);
		if (phone && p) {
			var r = studio.paceRange ? studio.paceRange() : null;
			paceEl.textContent = r ? sprintf( /* translators: 1: words per minute, 2: kind of reading such as Verse, 3: lowest pace, 4: highest pace. */ __( 'Pace %1$s wpm (%2$s: %3$s to %4$s)', 'foxfire-studio' ), p, r.name, r.lo, r.hi ) : sprintf( /* translators: %s: words per minute. */ __( 'Pace %s wpm', 'foxfire-studio' ), p );
		}
	}

	/*
	 * The first look (1.14.0), shown the moment a voiced take stops, before
	 * the Whisper review: how long, the pace, and any paragraph the follower
	 * never heard. Empty when the follower did not run for this take.
	 */
	function firstLook(seconds) {
		if (!follower || !studio.takeMode || studio.takeMode !== 'voice') { return ''; }
		var look = F.firstLook(follower.heard, W.map(function (w) { return w.block; }));
		if (!look.heardAny) { return ''; }
		var parts = [];
		var p = seconds > 20 ? Math.round(points.length ? (points[points.length - 1].w - points[0].w) / (Math.max(1, (points[points.length - 1].t - points[0].t)) / 60) : 0) : 0;
		parts.push(p > 0 ? sprintf( /* translators: 1: how long the take ran, 2: words per minute. */ __( 'First look, from following your voice: %1$s at about %2$s wpm.', 'foxfire-studio' ), studio.fmt(seconds), p ) : sprintf( /* translators: %s: how long the take ran. */ __( 'First look, from following your voice: %s.', 'foxfire-studio' ), studio.fmt(seconds) ));
		if (look.skipped.length === 1) {
			parts.push(sprintf( /* translators: %s: a paragraph number. */ __( 'Paragraph %s was not heard.', 'foxfire-studio' ), look.skipped[0] ));
		} else if (look.skipped.length > 1) {
			parts.push(sprintf( /* translators: %s: a list of paragraph numbers. */ __( 'Paragraphs %s were not heard.', 'foxfire-studio' ), look.skipped.join(', ') ));
		} else {
			parts.push(__( 'Every paragraph it passed was heard.', 'foxfire-studio' ));
		}
		parts.push(sprintf( /* translators: %s: seconds, such as 0.4. */ __( 'It kept up within %s s on this device.', 'foxfire-studio' ), (Math.round(maxLag * 10) / 10).toFixed(1) ));
		if (settings.whisper) { parts.push(__( 'The full review follows.', 'foxfire-studio' )); }
		return parts.join(' ');
	}
	/* Load the model as soon as the switch is on, so Begin does not wait for it. */
	voiceIn.addEventListener('change', function () { if (voiceIn.checked) { prepareVoice(); } });
	if (studio.voiceMode()) { prepareVoice(); }
})();
