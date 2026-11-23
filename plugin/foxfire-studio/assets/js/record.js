/*
 * Foxfire Studio: recording. Unfinished takes kept in this browser and their recovery,
 * Send to my computer, the microphone and its capture, the level meter, and
 * finishing a take: the read-out, cleanup and the file, with its downloads.
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
	var cleanReading = studio.cleanReading;
	var analyseReading = studio.analyseReading;
	var describeReading = studio.describeReading;
	var pageUrl = studio.pageUrl;
	var track = studio.track;
	var pick = studio.pick;
	var playBtn = studio.playBtn;
	var finishBtn = studio.finishBtn;
	var recEl = studio.recEl;
	var recTimeEl = studio.recTimeEl;
	var meterEl = studio.meterEl;
	var msgEl = studio.msgEl;
	var resultEl = studio.resultEl;
	var resultAudio = studio.resultAudio;
	var resultInfo = studio.resultInfo;
	var downloadA = studio.downloadA;
	var downloadRawA = studio.downloadRawA;
	var dismissBtn = studio.dismissBtn;
	/* Phone take mode (1.12.0): the MP3 is made on the phone only when asked. */
	var makeMp3Btn = document.getElementById('tpMakeHere') || document.createElement('button');
	studio.makeMp3Btn = makeMp3Btn;
	var readoutEl = studio.readoutEl;
	var whisperStatus = studio.whisperStatus;
	var firstLookEl = document.getElementById('tpFirstLook') || { hidden: true, textContent: '' };
	var openReviewBtn = studio.openReviewBtn;
	var PR = studio.PR;
	var POST_ID = studio.POST_ID;
	var settings = studio.settings;
	var LOW_MEM = studio.LOW_MEM;
	var fmtClock = studio.fmtClock;
	var slug = studio.slug;
	var W = studio.W;
	var voiceMode = studio.voiceMode;
	var fmt = studio.fmt;
	studio.forgetTake = forgetTake;
	studio.wavBlob = wavBlob;
	studio.offerRecovery = offerRecovery;
	studio.sentUrl = sentUrl;
	studio.sentDone = sentDone;
	studio.offerSent = offerSent;
	studio.prepareMic = prepareMic;
	studio.startRecorder = startRecorder;
	studio.pauseRecorder = pauseRecorder;
	studio.resumeRecorder = resumeRecorder;
	studio.stopRecording = stopRecording;
	studio.recTick = recTick;
	studio.takeSummary = takeSummary;
	studio.showReadout = showReadout;
	studio.describeSound = describeSound;
	studio.resampleTo = resampleTo;
	studio.cleanWithModel = cleanWithModel;
	studio.mp3Progress = mp3Progress;

	/*
	 * Unfinished takes. While recording, the raw audio is saved in this
	 * browser (IndexedDB) about once a second, so a frozen tab or a closed
	 * laptop never loses a take. A take is cleared once its MP3 or original
	 * is downloaded, and at most three unfinished takes are kept.
	 */
	var takeStore = (function () {
		var dbp = null;
		function db() {
			if (!dbp) {
				dbp = new Promise(function (resolve, reject) {
					if (!window.indexedDB) { reject(new Error('no storage')); return; }
					var r;
					try { r = window.indexedDB.open('rrt-takes', 1); } catch (e) { reject(e); return; }
					r.onupgradeneeded = function () {
						var d = r.result;
						d.createObjectStore('takes', { keyPath: 'id' });
						d.createObjectStore('chunks', { autoIncrement: true }).createIndex('take', 'take');
					};
					r.onsuccess = function () { resolve(r.result); };
					r.onerror = function () { reject(r.error); };
				});
			}
			return dbp;
		}
		function run(names, mode, work) {
			return db().then(function (d) {
				return new Promise(function (resolve, reject) {
					var tx = d.transaction(names, mode);
					var out = { value: null };
					work(tx, out);
					tx.oncomplete = function () { resolve(out.value); };
					tx.onerror = function () { reject(tx.error); };
					tx.onabort = function () { reject(tx.error); };
				});
			});
		}
		return {
			begin: function (take) {
				return run(['takes'], 'readwrite', function (tx) { tx.objectStore('takes').put(take); });
			},
			add: function (take, pcm) {
				return run(['takes', 'chunks'], 'readwrite', function (tx) {
					tx.objectStore('chunks').add({ take: take.id, data: pcm.buffer });
					tx.objectStore('takes').put(take);
				});
			},
			list: function () {
				return run(['takes'], 'readonly', function (tx, out) {
					var q = tx.objectStore('takes').getAll();
					q.onsuccess = function () { out.value = q.result || []; };
				});
			},
			/* bits (1.9.0): 24 for a take kept as packed 24-bit samples, else 16. */
			load: function (id, bits) {
				return run(['chunks'], 'readonly', function (tx, out) {
					out.value = [];
					var c = tx.objectStore('chunks').index('take').openCursor(window.IDBKeyRange.only(id));
					c.onsuccess = function () {
						var cur = c.result;
						if (cur) { out.value.push(bits === 24 ? new Uint8Array(cur.value.data) : new Int16Array(cur.value.data)); cur['continue'](); }
					};
				});
			},
			remove: function (id) {
				return run(['takes', 'chunks'], 'readwrite', function (tx) {
					tx.objectStore('takes')['delete'](id);
					var c = tx.objectStore('chunks').index('take').openCursor(window.IDBKeyRange.only(id));
					c.onsuccess = function () {
						var cur = c.result;
						if (cur) { cur['delete'](); cur['continue'](); }
					};
				});
			}
		};
	})();
	studio.takeStore = takeStore;
	studio.currentTake = null;
	function forgetTake() {
		if (studio.currentTake) {
			takeStore.remove(studio.currentTake)['catch'](function () {});
			studio.currentTake = null;
		}
	}

	/*
	 * A WAV file from pieces, without copying them into one big buffer.
	 * total is the number of samples; bits 16 (Int16Array pieces, the
	 * default) or 24 (1.9.0: Uint8Array pieces of packed little-endian samples).
	 */
	function wavBlob(parts, rate, total, bits) {
		var bps = bits === 24 ? 3 : 2;
		var h = new DataView(new ArrayBuffer(44));
		function str(o, s) { for (var i = 0; i < s.length; i++) { h.setUint8(o + i, s.charCodeAt(i)); } }
		str(0, 'RIFF');
		h.setUint32(4, 36 + total * bps, true);
		str(8, 'WAVE');
		str(12, 'fmt ');
		h.setUint32(16, 16, true);
		h.setUint16(20, 1, true);
		h.setUint16(22, 1, true);
		h.setUint32(24, rate, true);
		h.setUint32(28, rate * bps, true);
		h.setUint16(32, bps, true);
		h.setUint16(34, bps * 8, true);
		str(36, 'data');
		h.setUint32(40, total * bps, true);
		return new Blob([h.buffer].concat(parts), { type: 'audio/wav' });
	}

	/* Offer to recover the newest unfinished take, if there is one. */
	var recoverEl = document.getElementById('tpRecover');
	function offerRecovery(recover) {
		if (!recoverEl) { return; }
		takeStore.list().then(function (all) {
			all = all.filter(function (t) { return t.samples > 0; });
			all.sort(function (a, b) { return b.started - a.started; });
			all.slice(3).forEach(function (t) { takeStore.remove(t.id)['catch'](function () {}); });
			var t = all[0];
			if (!t || t.samples < t.rate) { return; }
			var when = '';
			try { when = new Date(t.started).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }); } catch (e) { when = new Date(t.started).toString(); }
			var here = recover && t.postId === POST_ID;
			document.getElementById('tpRecoverInfo').textContent = sprintf( /* translators: 1: fmt(t.samples / t.rate), 2: t.title || t.slug, 3: when, 4: here ? '' : __( ' Open that post to recover it.', 'foxfire-s. */ __( 'A %1$s recording of “%2$s” from %3$s was never downloaded. It is still saved in this browser.%4$s', 'foxfire-studio' ), fmt(t.samples / t.rate), t.title || t.slug, when, here ? '' : __( ' Open that post to recover it.', 'foxfire-studio' ) );
			var go = document.getElementById('tpRecoverGo');
			var drop = document.getElementById('tpRecoverDrop');
			var later = document.getElementById('tpRecoverLater');
			go.textContent = here ? __( 'Recover it', 'foxfire-studio' ) : __( 'Open that post', 'foxfire-studio' );
			drop.textContent = __( 'Discard', 'foxfire-studio' );
			recoverEl.classList.add('is-on');
			go.onclick = function () {
				if (here) {
					recoverEl.classList.remove('is-on');
					recover(t);
				} else {
					/* A practice take (1.29.0) has no post: it goes back to the practice reading. */
					window.location.href = pageUrl + (pageUrl.indexOf('?') === -1 ? '?' : '&') + (t.postId ? (RRT.postParam || 'tp_post') + '=' + encodeURIComponent(t.postId) : 'tp_practice=1');
				}
			};
			drop.onclick = function () {
				if (drop.textContent === __( 'Discard', 'foxfire-studio' )) { drop.textContent = __( 'Discard for good', 'foxfire-studio' ); return; }
				takeStore.remove(t.id)['catch'](function () {});
				recoverEl.classList.remove('is-on');
			};
			later.onclick = function () { recoverEl.classList.remove('is-on'); };
		})['catch'](function () {});
	}

	var SENT = RRT.sent || null;
	studio.SENT = SENT;
	var sentEl = document.getElementById('tpSent');
	studio.sentOpenId = '';
	function sentUrl(path) {
		var q = path.indexOf('?');
		return SENT.rest + (q < 0 ? path : path.slice(0, q) + (SENT.rest.indexOf('?') < 0 ? '?' : '&') + path.slice(q + 1));
	}
	function sentDelete(id) {
		if (!SENT || !id) { return Promise.resolve(); }
		SENT.waiting = (SENT.waiting || []).filter(function (t) { return t.id !== id; });
		return fetch(sentUrl('sent/' + id), { method: 'DELETE', credentials: 'same-origin', headers: { 'X-WP-Nonce': SENT.nonce } })['catch'](function () {});
	}
	/* Once a sent take is safe on this computer (downloaded or on the post), the site's copy goes. */
	function sentDone() {
		if (studio.sentOpenId) { sentDelete(studio.sentOpenId); studio.sentOpenId = ''; }
	}
	function sentParam() {
		var m = /[?&]rrt_sent=([a-zA-Z0-9]+)/.exec(window.location.search);
		return m ? m[1] : '';
	}
	/* Offers the newest waiting take (or the one named in the address). Returns true when it did. */
	function offerSent(open) {
		/* A waiting take is for a computer's review, so it is never offered on a phone (1.31.0: by touch and memory). */
		if (!sentEl || !SENT || studio.PHONE || LOW_MEM) { return false; }
		/* A take still arriving from the phone (1.12.0) is offered once it is finished or cut off. */
		var all = (SENT.waiting || []).filter(function (x) { return x.state !== 'live'; });
		if (!all.length) { return false; }
		var want = sentParam();
		var t = null;
		all.forEach(function (x) { if (x.id === want) { t = x; } });
		var named = !!t;
		t = t || all[0];
		var here = open ? t.post === POST_ID : false;
		if (named && here) { open(t); return true; }
		var more = all.length - 1;
		document.getElementById('tpSentInfo').textContent = sprintf( /* translators: 1: fmtClock(t.seconds), 2: t.drill ? __( ' opening drill', 'foxfire-studio' ) : __( ' take', 'foxfire-studio' ), 3: t.title, 4: t.when, 5: t.ends, 6: more ? sprintf( _n( ' %s more take is waiting too, listed un. */ __( 'A %1$s%2$s of “%3$s”, sent from your phone %4$s, is waiting. It stays on the site until you use, download or delete it here, and until %5$s at most.%6$s', 'foxfire-studio' ), fmtClock(t.seconds), t.drill ? __( ' opening drill', 'foxfire-studio' ) : __( ' take', 'foxfire-studio' ), t.title, t.when, t.ends, more ? sprintf( /* translators: %s: more. */ _n( ' %s more take is waiting too, listed under Foxfire Studio, Recordings.', ' %s more takes are waiting too, listed under Foxfire Studio, Recordings.', more, 'foxfire-studio' ), more ) : '' );
		if (t.state === 'cut') { document.getElementById('tpSentInfo').textContent += ' ' + __( 'It was cut off partway, so it holds the reading up to where the phone stopped. Combine takes can join it with a take of the rest.', 'foxfire-studio' ); }
		var go = document.getElementById('tpSentGo');
		var drop = document.getElementById('tpSentDrop');
		var later = document.getElementById('tpSentLater');
		go.textContent = here ? __( 'Open it', 'foxfire-studio' ) : __( 'Open that post', 'foxfire-studio' );
		drop.textContent = __( 'Delete', 'foxfire-studio' );
		sentEl.classList.add('is-on');
		go.onclick = function () {
			sentEl.classList.remove('is-on');
			if (here) {
				open(t);
			} else {
				window.location.href = pageUrl + (pageUrl.indexOf('?') === -1 ? '?' : '&') + (RRT.postParam || 'tp_post') + '=' + encodeURIComponent(t.post) + '&rrt_sent=' + encodeURIComponent(t.id);
			}
		};
		drop.onclick = function () {
			if (drop.textContent === 'Delete') { drop.textContent = __( 'Delete for good', 'foxfire-studio' ); return; }
			sentEl.classList.remove('is-on');
			sentDelete(t.id);
		};
		later.onclick = function () { sentEl.classList.remove('is-on'); };
		return true;
	}

	if (!track) {
		playBtn.addEventListener('click', function () { pick.focus(); });
		if (!offerSent(null)) { offerRecovery(null); }
		return;
	}

	/* Recording */
	var stream = null;
	studio.recorder = null;
	var chunks = [];
	var recType = '';
	var recSeconds = 0;
	var audioCtx = null;
	var analyser = null;
	var meterBuf = null;
	var meterFloat = null;
	studio.unsaved = false;

	function pickType() {
		var list = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg;codecs=opus'];
		var k;
		if (!window.MediaRecorder) { return ''; }
		for (k = 0; k < list.length; k++) {
			if (MediaRecorder.isTypeSupported(list[k])) { return list[k]; }
		}
		return '';
	}
	/*
	 * Lossless recording. A small AudioWorklet copies the microphone's raw
	 * samples while the reading runs, so cleanup and the MP3 work from the
	 * untouched signal (the browser's own recorder compresses to Opus first).
	 * It behaves like MediaRecorder (start, pause, resume, stop, state,
	 * onstop), so the reading code drives either one. MediaRecorder stays as
	 * the fallback where AudioWorklet is missing or the audio cannot start.
	 */
	var CAPTURE_SRC = [
		'class RrtCapture extends AudioWorkletProcessor {',
		'  constructor() {',
		'    super();',
		'    this.on = false;',
		'    this.buf = new Float32Array(4096);',
		'    this.n = 0;',
		'    this.port.onmessage = (e) => {',
		'      if (e.data === "rec") { this.on = true; }',
		'      else if (e.data === "hold") { this.on = false; }',
		'      else if (e.data === "flush") { this.send(); this.port.postMessage({ done: true }); }',
		'    };',
		'  }',
		'  send() {',
		'    if (this.n) { this.port.postMessage({ pcm: this.buf.slice(0, this.n) }); this.n = 0; }',
		'  }',
		'  process(inputs) {',
		'    const ch = inputs[0] ? inputs[0][0] : null;',
		'    if (this.on) {',
		'      if (ch) {',
		'        for (let i = 0; i < ch.length; i++) {',
		'          this.buf[this.n++] = ch[i];',
		'          if (this.n === this.buf.length) { this.send(); }',
		'        }',
		'      }',
		'    }',
		'    return true;',
		'  }',
		'}',
		'registerProcessor("rrt-capture", RrtCapture);'
	].join('\n');
	studio.CAPTURE_SRC = CAPTURE_SRC;

	function toInt16(f) {
		var out = new Int16Array(f.length);
		for (var i = 0; i < f.length; i++) {
			var s = f[i] < -1 ? -1 : (f[i] > 1 ? 1 : f[i]);
			out[i] = s < 0 ? s * 32768 : s * 32767;
		}
		return out;
	}
	/*
	 * Phone takes in 24 bits (1.9.0). A phone's microphone comes through the
	 * browser about 35 dB under a finished reading, so its quietest detail
	 * sits near the bottom of 16 bits; after a 35 dB lift that bottom would be
	 * heard. 24 bits keep it clean, for 1.5 times the size (about 8.6 MB a
	 * minute at 48 kHz). The laptop path stays 16-bit.
	 */
	var CAPTURE_BITS = studio.PHONE ? 24 : 16;
	studio.CAPTURE_BITS = CAPTURE_BITS;
	function toInt24(f) {
		var out = new Uint8Array(f.length * 3);
		for (var i = 0, o = 0; i < f.length; i++, o += 3) {
			var s = f[i] < -1 ? -1 : (f[i] > 1 ? 1 : f[i]);
			var q = Math.round(s < 0 ? s * 8388608 : s * 8388607);
			if (q < -8388608) { q = -8388608; }
			out[o] = q & 255;
			out[o + 1] = (q >> 8) & 255;
			out[o + 2] = (q >> 16) & 255;
		}
		return out;
	}
	studio.toInt24 = toInt24;
	function makeCapture(ctx, src) {
		if (!ctx.audioWorklet || !window.AudioWorkletNode) { return Promise.reject(new Error('no worklet')); }
		var url = URL.createObjectURL(new Blob([CAPTURE_SRC], { type: 'application/javascript' }));
		return ctx.audioWorklet.addModule(url).then(function () {
			URL.revokeObjectURL(url);
			/* The audio must actually be running, or nothing would be captured. */
			return ctx.state === 'running' ? null : Promise.race([
				ctx.resume(),
				new Promise(function (res) { window.setTimeout(res, 800); })
			]);
		}).then(function () {
			if (ctx.state !== 'running') { throw new Error('audio not running'); }
			var node = new AudioWorkletNode(ctx, 'rrt-capture', { numberOfInputs: 1, numberOfOutputs: 1, outputChannelCount: [1], channelCount: 1, channelCountMode: 'explicit' });
			var mute = ctx.createGain();
			mute.gain.value = 0;
			src.connect(node);
			node.connect(mute);
			mute.connect(ctx.destination);
			var rate = ctx.sampleRate;
			var bits = CAPTURE_BITS;
			var rec = { state: 'inactive', mimeType: 'audio/wav', onstop: null, parts: [], total: 0, take: null, bits: bits };
			var pending = [];
			var pendingN = 0;
			function save(force) {
				if (!rec.take || !pendingN) { return; }
				if (!force && pendingN < rate) { return; }
				var all = bits === 24 ? new Uint8Array(pendingN * 3) : new Int16Array(pendingN);
				var o = 0;
				pending.forEach(function (p) { all.set(p, o); o += p.length; });
				pending = [];
				pendingN = 0;
				rec.take.samples = rec.total;
				rec.take.updated = Date.now();
				rec.take.words = studio.takeDrill && studio.drillWords ? studio.drillWords : studio.finished ? W.length : Math.max(1, studio.wi);
				takeStore.add(rec.take, all)['catch'](function () { rec.take = null; });
			}
			node.port.onmessage = function (e) {
				var d = e.data;
				if (d.pcm) {
					var s = bits === 24 ? toInt24(d.pcm) : toInt16(d.pcm);
					rec.parts.push(s);
					rec.total += d.pcm.length;
					pending.push(s);
					pendingN += d.pcm.length;
					save(false);
					/* Phone take mode (1.12.0): the same audio, on its way to the site. */
					if (studio.live) { studio.live.push(s); }
					/* Voice following (1.14.0) hears the same audio, on this device. */
					if (studio.followFeed) { studio.followFeed(d.pcm, rate); }
				}
				if (d.done) {
					save(true);
					try { src.disconnect(node); node.disconnect(); mute.disconnect(); } catch (err) {}
					chunks = [wavBlob(rec.parts, rate, rec.total, bits)];
					if (rec.onstop) { rec.onstop(); }
				}
			};
			rec.start = function () {
				if (rec.state !== 'inactive') { return; }
				rec.state = 'recording';
				var title = track.querySelector('.tp-title');
				rec.take = { id: Date.now().toString(36) + Math.random().toString(36).slice(2, 6), postId: POST_ID, slug: slug, title: title ? title.textContent : '', rate: rate, bits: bits, started: Date.now(), updated: Date.now(), samples: 0, setup: studio.micSetup ? studio.micSetup.key : '', setupLabel: studio.micSetup ? studio.micSetup.label : '' };
				studio.currentTake = rec.take.id;
				takeStore.begin(rec.take)['catch'](function () { rec.take = null; });
				if (studio.live) { studio.live.begin(rec); }
				node.port.postMessage('rec');
			};
			rec.pause = function () {
				if (rec.state === 'recording') { rec.state = 'paused'; node.port.postMessage('hold'); }
			};
			rec.resume = function () {
				if (rec.state === 'paused') { rec.state = 'recording'; node.port.postMessage('rec'); }
			};
			/* Writes what is waiting to the browser at once (1.12.0: when the page is hidden). */
			rec.saveNow = function () { save(true); };
			rec.stop = function () {
				if (rec.state === 'inactive') { return; }
				rec.state = 'inactive';
				node.port.postMessage('hold');
				node.port.postMessage('flush');
			};
			return rec;
		});
	}

	function prepareMic() {
		if (studio.recorder) {
			if (studio.recorder.state !== 'inactive') { return Promise.resolve(true); }
		}
		if (!navigator.mediaDevices) { return Promise.resolve(false); }
		var AC = window.AudioContext || window.webkitAudioContext;
		if (!window.MediaRecorder && !(AC && window.AudioWorkletNode)) { return Promise.resolve(false); }
		return navigator.mediaDevices.getUserMedia({
			audio: { echoCancellation: false, noiseSuppression: false, autoGainControl: false, channelCount: 1 }
		}).then(function (s) {
			stream = s;
			chunks = [];
			recSeconds = 0;
			/* The setup this take is recorded on (1.13.0): the device and the microphone's name, for its EQ. */
			var mt = s.getAudioTracks ? s.getAudioTracks()[0] : null;
			studio.micSetup = window.RRTPolish ? window.RRTPolish.setup(navigator.userAgent, navigator.maxTouchPoints, mt ? mt.label : '') : null;
			var src = null;
			try {
				audioCtx = new AC();
				src = audioCtx.createMediaStreamSource(stream);
				analyser = audioCtx.createAnalyser();
				analyser.fftSize = 512;
				meterBuf = new Uint8Array(analyser.fftSize);
				meterFloat = null;
				src.connect(analyser);
			} catch (e) { analyser = null; }
			var lossless = src ? makeCapture(audioCtx, src) : Promise.reject(new Error('no audio'));
			return lossless.then(function (rec) {
				studio.recorder = rec;
				recType = 'audio/wav';
				studio.recorder.onstop = buildFile;
				return true;
			}, function () {
				if (!window.MediaRecorder) { releaseMic(); return false; }
				recType = pickType();
				var opts = { audioBitsPerSecond: 96000 };
				if (recType) { opts.mimeType = recType; }
				studio.recorder = new MediaRecorder(stream, opts);
				studio.recorder.ondataavailable = function (e) { if (e.data.size > 0) { chunks.push(e.data); } };
				studio.recorder.onstop = buildFile;
				return true;
			});
		})['catch'](function () { return false; });
	}
	function startRecorder() {
		if (!studio.recorder) { return; }
		if (studio.recorder.state !== 'inactive') { return; }
		/* The fallback recorder keeps nothing in the browser, so no earlier take is tied to this one. */
		if (window.MediaRecorder && studio.recorder instanceof window.MediaRecorder) { studio.currentTake = null; }
		studio.recorder.start(1000);
		/* This take's setup (1.13.0), and the live energy note, fresh for each take. */
		studio.takeSetup = studio.micSetup || null;
		energyStart();
		recEl.classList.add('is-on');
		recEl.classList.remove('is-paused');
		finishBtn.hidden = false;
	}
	function pauseRecorder() {
		if (!studio.recorder) { return; }
		if (studio.recorder.state === 'recording') {
			studio.recorder.pause();
			recEl.classList.add('is-paused');
		}
	}
	function resumeRecorder() {
		if (!studio.recorder) { return; }
		if (studio.recorder.state === 'paused') {
			studio.recorder.resume();
			recEl.classList.remove('is-paused');
		}
	}
	function stopRecording() {
		if (!studio.recorder) { return; }
		if (studio.recorder.state === 'inactive') { return; }
		studio.recorder.stop();
	}
	function releaseMic() {
		if (stream) {
			stream.getTracks().forEach(function (t) { t.stop(); });
		}
		if (audioCtx) {
			try { audioCtx.close(); } catch (e) {}
		}
		stream = null;
		audioCtx = null;
		analyser = null;
	}
	function recTick(dt) {
		if (!studio.recorder) { return; }
		if (studio.recorder.state === 'recording') {
			recSeconds += dt;
			recTimeEl.textContent = fmt(recSeconds);
		}
		if (analyser) {
			if (studio.recorder.state === 'recording') { energyTick(dt); }
			analyser.getByteTimeDomainData(meterBuf);
			var sum = 0;
			var k;
			for (k = 0; k < meterBuf.length; k++) {
				var v = (meterBuf[k] - 128) / 128;
				sum += v * v;
			}
			var rms = Math.sqrt(sum / meterBuf.length);
			meterEl.style.width = Math.min(100, rms * 320) + '%';
			/*
			 * The large meter of phone take mode (1.12.0) reads the samples as
			 * numbers: a phone's voice sits near -50 dBFS, under the smallest
			 * step of the byte reading above.
			 */
			if ((studio.live ? studio.recorder.state === 'recording' : false) && analyser.getFloatTimeDomainData) {
				if (!meterFloat) { meterFloat = new Float32Array(analyser.fftSize); }
				analyser.getFloatTimeDomainData(meterFloat);
				var fs = 0, fp = 0;
				for (k = 0; k < meterFloat.length; k++) {
					fs += meterFloat[k] * meterFloat[k];
					if (Math.abs(meterFloat[k]) > fp) { fp = Math.abs(meterFloat[k]); }
				}
				studio.live.level(Math.sqrt(fs / meterFloat.length), fp, dt);
			}
		}
	}

	/*
	 * The live energy note (1.13.0): "Your voice has dropped a little since
	 * the start", when the voice sits 4 dB or more below its first minute,
	 * at most once a minute, for a few seconds. Under the large meter in
	 * phone take mode, otherwise in the control bar. Never in an opening
	 * drill or on a Ritual and chant post, where a hushed voice is meant.
	 * It reads the samples as numbers, since a phone's voice sits under the
	 * byte reading's smallest step.
	 */
	var energy = null;
	var energyFloat = null;
	var energyTimer = 0;
	var energyDock = document.getElementById('tpEnergy');
	var energyPhone = document.getElementById('tpPMeterEnergy');
	function energyStart() {
		energyStop();
		var PL = window.RRTPolish;
		energy = PL && PL.energyWanted(studio.kind, studio.takeDrill) ? PL.energy() : null;
	}
	function energyStop() {
		energy = null;
		if (energyTimer) { window.clearTimeout(energyTimer); energyTimer = 0; }
		if (energyDock) { energyDock.hidden = true; }
		if (energyPhone) { energyPhone.hidden = true; }
	}
	function energyTick(dt) {
		if (!energy || !analyser || !analyser.getFloatTimeDomainData) { return; }
		if (!energyFloat) { energyFloat = new Float32Array(analyser.fftSize); }
		analyser.getFloatTimeDomainData(energyFloat);
		var s2 = 0;
		for (var k = 0; k < energyFloat.length; k++) { s2 += energyFloat[k] * energyFloat[k]; }
		energy.push(Math.sqrt(s2 / energyFloat.length), dt);
		if (!energy.note()) { return; }
		var phone = energyPhone ? !(document.getElementById('tpPMeter') || { hidden: true }).hidden : false;
		var el = phone ? energyPhone : energyDock;
		if (!el) { return; }
		el.textContent = __( 'Your voice has dropped a little since the start', 'foxfire-studio' );
		el.hidden = false;
		if (energyTimer) { window.clearTimeout(energyTimer); }
		energyTimer = window.setTimeout(function () { energyTimer = 0; el.hidden = true; }, window.RRTPolish.ENERGY.showS * 1000);
	}
	studio.energyNow = function () { return energy ? energy.state() : null; };

	/* Cleanup for a finished reading: clean.js (0.25.1). */
	/* The read-out after a take: readout.js (0.25.1). */
	/* The comparison of a take with the script: compare.js (0.25.1). */

	/* A small record of a take, kept so takes can be compared later. */
	function takeSummary(R, seconds, room, m) {
		m = m || (studio.rv.last ? studio.rv.last.metrics : null) || null;
		var r1 = function (v) { return (v === null || v === undefined || !isFinite(v)) ? null : Math.round(v * 10) / 10; };
		return {
			/* A sent take keeps the time it was sent, so reviewing it twice updates one take (0.21.0). */
			at: studio.sentAt || Date.now(),
			mode: studio.takeMode,
			setWpm: studio.takeMode === 'timed' ? settings.wpm : null,
			words: R.words ? R.words.filter(function (w) { return w.status !== 'omit' && w.status !== 'out'; }).length : null,
			rest: m ? r1(m.restShare) : null,
			pauseTrend: (m ? m.pauseEarly > 0 : false) ? r1((m.pauseLate - m.pauseEarly) / m.pauseEarly) : null,
			endDrop: m ? r1(m.endDropDb) : null,
			pitch: m ? r1(m.pitchSpread) : null,
			energy: m ? r1(m.energySpread) : null,
			clean: room ? true : (settings.clean ? true : false),
			whisper: true,
			model: studio.usedModel,
			whisperS: Math.round(studio.whisperListenS),
			whisperLoadS: Math.round(studio.whisperLoadS),
			acc: Math.round(R.accuracy * 1000) / 10,
			omit: R.counts.omit,
			sub: R.counts.sub,
			check: R.counts.check,
			added: R.counts.added,
			repeat: R.counts.repeat,
			filler: R.counts.filler,
			wpm: R.wpm,
			pauses: R.pauses.filter(function (p) { return !p.inSentence ? !p.cued : false; }).length,
			retake: R.retake,
			secs: Math.round(seconds),
			clicks: room ? room.clicks.length : null,
			whistle: room ? Math.round(room.whistleShare * 100) : null,
			snr: room ? Math.round(room.snr) : null,
			/* Practice (0.14.0): an opening drill and its length, and the habit being worked on. */
			drill: studio.takeDrill || '',
			drillSecs: studio.takeDrill ? (studio.sentDrillS || settings.drillSecs) : null,
			focus: studio.takeFocus || ''
		};
	}

	/* Plain-language lines for what broadcast sound did, and the studio-ready check. */
	function describeSound(sound) {
		if (!sound) { return []; }
		var r = sound.report;
		var c = sound.check;
		var lines = [];
		function n1(v) { return (Math.round(v * 10) / 10).toFixed(1); }
		/* "Level (RMS)" becomes "level (RMS)", but "MP3" stays "MP3". */
		function lc(label) { return /^[A-Z][A-Z0-9]/.test(label) ? label : label.charAt(0).toLowerCase() + label.slice(1); }
		var parts = [];
		if (c) {
			var mm = c.measures;
			var fin = r.finish || null;
			if (r.mode === 'audiobook') {
				parts.push(sprintf( /* translators: 1: n1(mm.rms), 2: n1(mm.samplePeak). */ __( 'Leveled for audiobooks: %1$s dB RMS, peaks at %2$s dB.', 'foxfire-studio' ), n1(mm.rms), n1(mm.samplePeak) ));
			} else if (fin && fin.key !== 'podcast_mono') {
				/* Finishing presets (1.6.0): which one, and what the file is. */
				parts.push(sprintf( /* translators: 1: preset name, 2: loudness, 3: true peak, 4: what the file is, such as "stereo MP3". */ __( 'Finished for %1$s: %2$s LUFS, peaks at %3$s dBTP, %4$s.', 'foxfire-studio' ), fin.label || __( 'your own settings', 'foxfire-studio' ), n1(mm.lufs), n1(mm.truePeak), fin.format === 'wav' ? sprintf( /* translators: 1: mono or stereo, 2: kHz, 3: bits. */ __( '%1$s WAV at %2$s kHz, %3$s-bit', 'foxfire-studio' ), fin.channels === 2 ? __( 'stereo', 'foxfire-studio' ) : __( 'mono', 'foxfire-studio' ), fin.rate / 1000, fin.bits ) : (fin.channels === 2 ? __( 'stereo MP3', 'foxfire-studio' ) : __( 'mono MP3', 'foxfire-studio' )) ));
			} else {
				parts.push(sprintf( /* translators: 1: n1(mm.lufs), 2: SOUND.target === -19 ? __( ' (it plays at about -16 on phone, 3: n1(mm.truePeak). */ __( 'Leveled to %1$s LUFS for podcasts%2$s, with peaks at %3$s dBTP.', 'foxfire-studio' ), n1(mm.lufs), (fin ? fin.fileTarget === -19 : studio.SOUND.target === -19) ? __( ' (it plays at about -16 on phones and headphones)', 'foxfire-studio' ) : '', n1(mm.truePeak) ));
			}
		}
		/*
		 * The lift (1.9.0): a quiet room allows more than the usual limit, a
		 * noisy one does not, so the read-out says which held a take back.
		 */
		var usual = window.RRTSound && window.RRTSound.MAX_GAIN ? window.RRTSound.MAX_GAIN : 30;
		if (r.gainCapped) {
			parts.push(r.liftByRoom ? sprintf( /* translators: %s: decibels. */ __( 'The recording was very quiet, so it could only be raised by %s dB, the most the studio allows even in a quiet room. Moving closer to the microphone, or a USB microphone, would help.', 'foxfire-studio' ), Math.round(r.maxGainDb || usual) ) : sprintf( /* translators: %s: decibels. */ __( 'The recording was very quiet, so it could only be raised by %s dB: raising it further would bring the background up too. Moving closer to the microphone, or a USB microphone, would help.', 'foxfire-studio' ), Math.round(r.maxGainDb || usual) ));
		} else if (r.liftByRoom && r.gainDb > (r.usualGainDb || usual) + 0.5) {
			parts.push(sprintf( /* translators: 1: decibels raised, 2: the usual limit in decibels. */ __( 'The recording was quiet, and the room was quiet enough to raise it by %1$s dB (more than the usual %2$s) with the background still well down.', 'foxfire-studio' ), Math.round(r.gainDb), Math.round(r.usualGainDb || usual) ));
		}
		if (r.compressMaxDb !== undefined) {
			parts.push(r.compressMaxDb < 1 ? __( 'Your level was already even, so compression had almost nothing to do.', 'foxfire-studio' ) : sprintf( /* translators: 1: Math.max(1, Math.round(r.compressAvgDb)), 2: Math.round(r.compressMaxDb). */ __( 'Gentle compression evened out the louder words: about %1$s dB on a typical word, %2$s dB at most.', 'foxfire-studio' ), Math.max(1, Math.round(r.compressAvgDb)), Math.round(r.compressMaxDb) ));
		}
		var fixed = [];
		if (r.plosives) { fixed.push(sprintf( /* translators: %s: r.plosives. */ _n( 'softened %s pop on P and B', 'softened %s pops on P and B', r.plosives, 'foxfire-studio' ), r.plosives )); }
		if (r.sibilants) { fixed.push(sprintf( /* translators: %s: r.sibilants. */ _n( 'softened %s harsh S sound', 'softened %s harsh S sounds', r.sibilants, 'foxfire-studio' ), r.sibilants )); }
		if (r.breaths) { fixed.push(sprintf( /* translators: %s: r.breaths. */ _n( 'lowered %s breath', 'lowered %s breaths', r.breaths, 'foxfire-studio' ), r.breaths )); }
		if (fixed.length) { parts.push(fixed.join(', ').replace(/^./, function (ch) { return ch.toUpperCase(); }).replace(/, ([^,]*)$/, ' ' + __( 'and', 'foxfire-studio' ) + ' $1') + '.'); }
		lines.push({ label: __( 'Sound', 'foxfire-studio' ), text: parts.join(' ') });
		var pol = describePolish(r);
		if (pol) { lines.push({ label: __( 'Polish', 'foxfire-studio' ), text: pol }); }
		if (c) {
			var met = c.items.filter(function (it) { return it.ok === true; });
			var missed = c.items.filter(function (it) { return it.ok === false; });
			var info = c.items.filter(function (it) { return it.ok === null; });
			var who = r.mode === 'audiobook' ? __( 'Audible (ACX)', 'foxfire-studio' ) : (r.finish && r.finish.key !== 'podcast_mono' && r.finish.label ? r.finish.label : __( 'podcasts', 'foxfire-studio' ));
			var text = missed.length ? sprintf( /* translators: 1: who, 2: missed.map(function (it) { return lc(it.label) + ' ' + it.va. */ __( 'Not ready for %1$s yet. Missed: %2$s.', 'foxfire-studio' ), who, missed.map(function (it) { return lc(it.label) + ' ' + it.value + ' (needs ' + it.rule + ')'; }).join('; ') ) : sprintf( /* translators: %s: who. */ __( 'Ready for %s.', 'foxfire-studio' ), who );
			if (met.length) { text += sprintf( /* translators: %s: met.map(function (it) { return lc(it.label) + ' ' + it.value. */ __( ' Met: %s.', 'foxfire-studio' ), met.map(function (it) { return lc(it.label) + ' ' + it.value; }).join(', ') ); }
			info.forEach(function (it) { text += ' ' + it.label + ' ' + it.value + ' (' + it.rule + ').'; });
			lines.push({ label: __( 'Studio check', 'foxfire-studio' ), text: text });
		}
		return lines;
	}
	/*
	 * Voice polish (1.13.0) in a line: strengths other than Standard, slow
	 * leveling, the setup's EQ and lowered clicks. Empty when the take was
	 * polished just as before 1.13.0.
	 */
	function describePolish(r) {
		if (!r) { return ''; }
		var out = [];
		var names = { plosives: __( 'pops', 'foxfire-studio' ), deess: __( 'harsh S sounds', 'foxfire-studio' ), breaths: __( 'breaths', 'foxfire-studio' ), compress: __( 'compression', 'foxfire-studio' ) };
		var lv = { off: __( 'off', 'foxfire-studio' ), gentle: __( 'gentle', 'foxfire-studio' ), strong: __( 'strong', 'foxfire-studio' ) };
		var st = r.strengths || {};
		var moved = Object.keys(names).filter(function (k) { return st[k] && st[k] !== 'standard'; }).map(function (k) { return sprintf( /* translators: 1: a step, such as "breaths", 2: its strength, such as "gentle". */ __( '%1$s %2$s', 'foxfire-studio' ), names[k], lv[st[k]] || st[k] ); });
		if (moved.length) { out.push(sprintf( /* translators: %s: the steps and their strengths. */ __( 'Strengths other than Standard: %s.', 'foxfire-studio' ), moved.join(', ') )); }
		if (r.slowShort) {
			out.push(__( 'Slow leveling works on readings of a minute and a half or more, so it left this one as it was.', 'foxfire-studio' ));
		} else if (r.slowDb !== undefined) {
			out.push(r.slowDb >= 0.5 ? sprintf( /* translators: 1: decibels, 2: the most allowed. */ __( 'Slow leveling evened out a level that drifted over the reading, by up to %1$s dB (at most %2$s).', 'foxfire-studio' ), (Math.round(r.slowDb * 10) / 10).toFixed(1), r.slowMaxDb ) : __( 'Slow leveling had nothing to do: your level held steady over the reading.', 'foxfire-studio' ));
		}
		if (r.eq ? r.eq.length : false) {
			var bands = r.eq.filter(function (b) { return b.g; }).map(function (b) { return (b.f >= 1000 ? (b.f / 1000) + ' kHz' : b.f + ' Hz') + ' ' + (b.g > 0 ? '+' : '') + b.g + ' dB'; });
			out.push(bands.length ? sprintf( /* translators: %s: bands and gains, such as "250 Hz +1 dB". */ __( 'EQ for this setup: %s.', 'foxfire-studio' ), bands.join(', ') ) : __( 'EQ for this setup: flat, nothing to change.', 'foxfire-studio' ));
		}
		if (r.clicks !== undefined) {
			out.push(r.clicks ? sprintf( /* translators: %s: how many. */ _n( 'Lowered %s click between words.', 'Lowered %s clicks between words.', r.clicks, 'foxfire-studio' ), r.clicks ) : __( 'No clicks between words were found.', 'foxfire-studio' ));
		}
		return out.join(' ');
	}
	studio.describePolish = describePolish;
	function showReadout(res, words, voiced) {
		readoutEl.innerHTML = '';
		readoutEl.classList.add('is-on');
		var h = document.createElement('h3');
		h.textContent = __( 'How it went', 'foxfire-studio' );
		readoutEl.appendChild(h);
		var m = null;
		try { m = analyseReading(res.samples, res.rate, words, settings.wpm); } catch (e) { m = null; }
		if (m && res.room) { m.room = res.room; }
		if (!m) {
			var short = document.createElement('p');
			short.className = 'tp-note';
			short.textContent = __( 'This reading was too short to say much about.', 'foxfire-studio' );
			readoutEl.appendChild(short);
			return null;
		}
		var all = describeReading(m, voiced, fmt, studio.paceJudge ? studio.paceJudge() : null).concat(describeSound(res.sound));
		/* Practice focus (0.14.0): its line first, the rest folded away. */
		var order = PR ? PR.orderLines(all, studio.takeFocus) : { first: [], rest: all };
		var fill = function (dl, list) {
			list.forEach(function (line) {
				var dt = document.createElement('dt');
				dt.textContent = line.label;
				var dd = document.createElement('dd');
				dd.textContent = line.text;
				dl.appendChild(dt);
				dl.appendChild(dd);
			});
		};
		var dl = document.createElement('dl');
		fill(dl, order.first.length ? order.first : order.rest);
		readoutEl.appendChild(dl);
		if (order.first.length) {
			var more = document.createElement('details');
			more.className = 'tp-rv-more';
			var sm = document.createElement('summary');
			sm.textContent = __( 'Everything else', 'foxfire-studio' );
			more.appendChild(sm);
			var dl2 = document.createElement('dl');
			fill(dl2, order.rest);
			more.appendChild(dl2);
			readoutEl.appendChild(more);
		}
		return m;
	}

	function runCleaner(buf, rate, onProgress, skipNoise) {
		return new Promise(function (resolve) {
			var c;
			try { c = cleanReading(buf, rate, skipNoise); } catch (e) { resolve(null); return; }
			function step() {
				try {
					var p = c.step(250);
					onProgress(p, 'clean');
					if (p < 1) { window.setTimeout(step, 0); return; }
					resolve({ data: c.finish(), report: c.report });
				} catch (e) { resolve(null); }
			}
			step();
		});
	}
	/*
	 * Speech-model noise reduction (0.6.0). RNNoise works at 48 kHz, so the
	 * reading is converted there and back by the browser's own resampler.
	 * The model runs in a background worker (assets/js/denoise.js), which
	 * refuses it unless it matches the fingerprint. Anything that goes wrong
	 * resolves to { failed: reason } so cleanup carries on without it.
	 */
	var DENOISE = RRT.denoise || null;
	function resampleTo(x, from, to) {
		var OACx = window.OfflineAudioContext || window.webkitOfflineAudioContext;
		var off = new OACx(1, Math.max(1, Math.round(x.length * to / from)), to);
		var b = off.createBuffer(1, x.length, from);
		b.copyToChannel(x, 0);
		var src = off.createBufferSource();
		src.buffer = b;
		src.connect(off.destination);
		src.start(0);
		return off.startRendering().then(function (r) { return r.getChannelData(0); });
	}
	function runDenoise(buf, rate, onProgress) {
		if (!DENOISE || DENOISE.strength === 'off') { return Promise.resolve(null); }
		if (!window.Worker || !(window.OfflineAudioContext || window.webkitOfflineAudioContext) || !window.WebAssembly) {
			return Promise.resolve({ failed: __( 'this browser cannot run it', 'foxfire-studio' ) });
		}
		var seconds = buf.length / rate;
		onProgress(0, 'denoise');
		return resampleTo(buf, rate, 48000).then(function (x48) {
			var urls = [DENOISE.model];
			if (DENOISE.fallback) { urls.push(DENOISE.fallback); }
			var worker;
			try { worker = new Worker(DENOISE.worker); } catch (e) { return { failed: __( 'its worker could not start', 'foxfire-studio' ) }; }
			function attempt(i) {
				return new Promise(function (resolve, reject) {
					// Generous: the model needs about a second per 15 seconds of reading.
					var timer = window.setTimeout(function () { reject(new Error(__( 'it took too long', 'foxfire-studio' ))); }, 60000 + seconds * 1000);
					worker.onmessage = function (e) {
						var d = e.data;
						if (d.progress !== undefined) { onProgress(d.progress, 'denoise'); return; }
						window.clearTimeout(timer);
						if (d.error) { reject(new Error(d.error)); } else { resolve(d); }
					};
					worker.onerror = function (e) { window.clearTimeout(timer); e.preventDefault(); reject(new Error(__( 'its worker stopped', 'foxfire-studio' ))); };
					// The 48 kHz copy is sent as a copy, so a second attempt still has it.
					worker.postMessage({ id: i + 1, url: urls[i], sha256: DENOISE.sha256, data: x48, strength: DENOISE.strength });
				}).catch(function (err) {
					// Report the first reason (a fingerprint mismatch says more than a blocked fallback).
					if (i + 1 < urls.length) { return attempt(i + 1).catch(function () { throw err; }); }
					throw err;
				});
			}
			return attempt(0).then(function (d) {
				worker.terminate();
				return resampleTo(d.data, 48000, rate).then(function (y) {
					var out = new Float32Array(buf.length);
					out.set(y.length > buf.length ? y.subarray(0, buf.length) : y);
					return { data: out, report: d.report };
				});
			}, function (err) {
				worker.terminate();
				return { failed: String(err && err.message || err).replace(/\.$/, '') };
			});
		}).catch(function (err) {
			return { failed: String(err && err.message || err).replace(/\.$/, '') };
		});
	}
	/*
	 * The stronger cleanup (1.16.0): DeepFilterNet 3 in its own worker
	 * (assets/js/deepfilter.js), which checks the runtime and the model
	 * against their fingerprints. Resolves like runDenoise: null when noise
	 * reduction is off, { failed: reason } when it could not run.
	 */
	function runStronger(buf, rate, onProgress) {
		if (!DENOISE || DENOISE.strength === 'off') { return Promise.resolve(null); }
		var S = DENOISE.stronger;
		if (!S) { return Promise.resolve({ failed: __( 'its files are missing from the plugin', 'foxfire-studio' ) }); }
		if (!window.Worker || !(window.OfflineAudioContext || window.webkitOfflineAudioContext) || !window.WebAssembly) {
			return Promise.resolve({ failed: __( 'this browser cannot run it', 'foxfire-studio' ) });
		}
		var seconds = buf.length / rate;
		onProgress(0, 'denoise');
		return resampleTo(buf, rate, 48000).then(function (x48) {
			var worker;
			try { worker = new Worker(S.worker); } catch (e) { return { failed: __( 'its worker could not start', 'foxfire-studio' ) }; }
			return new Promise(function (resolve, reject) {
				// Generous: the model needs about 5 to 10 seconds a minute of reading on a laptop, and it gives up by itself on a slow device.
				var timer = window.setTimeout(function () { reject(new Error(__( 'it took too long', 'foxfire-studio' ))); }, 120000 + seconds * 1000);
				worker.onmessage = function (e) {
					var d = e.data;
					if (d.progress !== undefined) { onProgress(d.progress, 'denoise'); return; }
					window.clearTimeout(timer);
					if (d.error) { reject(new Error(d.error)); } else { resolve(d); }
				};
				worker.onerror = function (e) { window.clearTimeout(timer); e.preventDefault(); reject(new Error(__( 'its worker stopped', 'foxfire-studio' ))); };
				worker.postMessage({ id: 1, cfg: S, data: x48, strength: DENOISE.strength, maxRtf: S.maxRtf }, [x48.buffer]);
			}).then(function (d) {
				worker.terminate();
				return resampleTo(d.data, 48000, rate).then(function (y) {
					var out = new Float32Array(buf.length);
					out.set(y.length > buf.length ? y.subarray(0, buf.length) : y);
					return { data: out, report: d.report };
				});
			}, function (err) {
				worker.terminate();
				return { failed: String(err && err.message || err).replace(/\.$/, '') };
			});
		}).catch(function (err) {
			return { failed: String(err && err.message || err).replace(/\.$/, '') };
		});
	}
	/* Which engine a take gets: the one asked for, or the Settings choice (1.16.0). */
	function engineFor(want) {
		var e = want || (DENOISE ? DENOISE.engine : 'standard');
		return e === 'stronger' ? 'stronger' : 'standard';
	}
	/* The speech model for a take: the stronger cleanup when chosen, with RNNoise when it cannot run. */
	function runModel(buf, rate, onProgress, engine) {
		if (engineFor(engine) !== 'stronger') { return runDenoise(buf, rate, onProgress); }
		return runStronger(buf, rate, onProgress).then(function (st) {
			if (!st || !st.failed) { return st; }
			return runDenoise(buf, rate, onProgress).then(function (dn) {
				var out = dn || {};
				out.strongerFailed = st.failed;
				return out;
			});
		});
	}
	studio.cleanEngine = engineFor;
	/*
	 * The whole cleanup: the speech model first, then clicks, stray sounds and
	 * whistles. engine (1.16.0): 'standard' or 'stronger' for this take, or
	 * nothing for the Settings choice.
	 */
	function cleanWithModel(buf, rate, onProgress, engine) {
		return runModel(buf, rate, onProgress, engine).then(function (dn) {
			var ran = dn && dn.data ? dn : null;
			return runCleaner(ran ? ran.data : buf, rate, onProgress, !!ran).then(function (c) {
				if (!c && ran) {
					c = { data: ran.data, report: { clicks: [], strays: [], whistles: 0, sibilants: 0, whistleShare: 0, snr: null, noiseDropDb: 0 } };
				}
				if (!c) { return null; }
				if (ran) {
					c.report.denoise = ran.report;
					// The Room line describes the room itself, so keep the measure from before the model ran.
					if (isFinite(ran.report.snrBefore)) { c.report.snr = ran.report.snrBefore; }
				} else if (dn && dn.failed) {
					c.report.denoiseFailed = dn.failed;
				}
				/* The stronger cleanup (1.16.0): why it fell back, and whether it is there to suggest. */
				if (dn && dn.strongerFailed) { c.report.strongerFailed = dn.strongerFailed; }
				c.report.strongerReady = !!(DENOISE && DENOISE.stronger && DENOISE.strength !== 'off');
				return c;
			});
		});
	}
	function mp3Progress(p, phase) {
		var label = { denoise: __( 'Lowering background noise... ', 'foxfire-studio' ), clean: __( 'Cleaning up the audio... ', 'foxfire-studio' ), sound: __( 'Shaping the sound...', 'foxfire-studio' ), check: __( 'Checking the finished file...', 'foxfire-studio' ) }[phase] || __( 'Making a phone-friendly MP3... ', 'foxfire-studio' );
		resultInfo.textContent = phase === 'sound' || phase === 'check' ? label : label + Math.round(p * 100) + '%';
	}

	function buildFile() {
		var wordsRead = studio.takeDrill && studio.drillWords ? studio.drillWords : studio.finished ? W.length : Math.max(1, studio.wi);
		var wasVoiced = voiceMode();
		var type = (studio.recorder.mimeType || recType || 'audio/webm').split(';')[0];
		var ext = 'webm';
		if (type.indexOf('mp4') !== -1) { ext = 'm4a'; }
		if (type.indexOf('ogg') !== -1) { ext = 'ogg'; }
		if (type.indexOf('wav') !== -1) { ext = 'wav'; }
		var raw = new Blob(chunks, { type: type });
		var rawUrl = URL.createObjectURL(raw);
		/* What Send to my computer sends (0.21.0): the original, and how far the reading got. */
		studio.lastRaw = { blob: raw, words: wordsRead, seconds: recSeconds, drill: studio.takeDrill || '', drillS: studio.takeDrill ? settings.drillSecs : 0, focus: studio.takeFocus || '', setup: studio.takeSetup || null };
		energyStop();
		var base = slug + '-reading';

		recEl.classList.remove('is-on');
		finishBtn.hidden = true;
		if (studio.heldAtEnd) {
			studio.heldAtEnd = false;
			studio.setPlayLabel();
		}
		studio.unsaved = true;
		releaseMic();
		studio.recorder = null;
		makeMp3Btn.hidden = true;

		downloadRawA.href = rawUrl;
		downloadRawA.setAttribute('download', base + '.' + ext);
		downloadRawA.textContent = sprintf( /* translators: %s: ext.toUpperCase(). */ __( 'Original (%s)', 'foxfire-studio' ), ext.toUpperCase() );
		downloadA.hidden = true;
		resultAudio.src = rawUrl;
		resultInfo.textContent = __( 'Making a phone-friendly MP3...', 'foxfire-studio' );
		readoutEl.classList.remove('is-on');
		readoutEl.innerHTML = '';
		whisperStatus.textContent = '';
		openReviewBtn.hidden = true;
		/* The first look (1.14.0): what voice following heard, shown at once, until the full review is ready. */
		var look = wasVoiced && studio.followLook ? studio.followLook(recSeconds) : '';
		firstLookEl.textContent = look;
		firstLookEl.hidden = !look;
		downloadRawA.hidden = false;
		resultEl.classList.add('is-on');
		msgEl.textContent = '';

		/* Phone take mode (1.12.0): the take is finished on the site, and the MP3 waits to be asked for. */
		if (studio.live ? studio.live.owns() : false) {
			studio.live.finish(raw, function () { makeMp3(raw, base, wordsRead, wasVoiced); });
			return;
		}
		makeMp3(raw, base, wordsRead, wasVoiced);
	}
	/* What to do with the MP3: a post's reading, or (1.29.1) a practice take, which belongs to no post. */
	function afterLine() {
		if (studio.PRACTICE) { return __( 'This practice take belongs to no post, so it is kept with your other takes.', 'foxfire-studio' ); }
		return (studio.STATS ? __( 'Press Use this recording to make it this post’s reading, or upload it', 'foxfire-studio' ) : __( 'Upload it', 'foxfire-studio' )) + __( ' in the Reading audio box on this post’s edit screen.', 'foxfire-studio' );
	}
	/* Cleans, levels and encodes a finished take, then shows the read-out and starts the review. */
	function makeMp3(raw, base, wordsRead, wasVoiced) {
		makeMp3Btn.hidden = true;
		resultInfo.textContent = __( 'Making a phone-friendly MP3...', 'foxfire-studio' );
		var outExt = studio.finishExt ? studio.finishExt() : 'mp3';
		downloadA.textContent = outExt === 'wav' ? __( 'Download WAV', 'foxfire-studio' ) : __( 'Download MP3', 'foxfire-studio' );
		studio.toMp3(raw, mp3Progress).then(function (res) {
			var url = URL.createObjectURL(res.blob);
			resultAudio.src = url;
			downloadA.href = url;
			downloadA.setAttribute('download', base + '.' + outExt);
			downloadA.hidden = false;
			resultInfo.textContent = base + '.' + outExt + ', ' + fmt(res.seconds) + ' long, ' + (res.blob.size / 1048576).toFixed(1) + ' MB, ' + (res.room ? __( 'cleaned up, ', 'foxfire-studio' ) : '') + __( 'trimmed and leveled. ', 'foxfire-studio' ) + afterLine();
			window.setTimeout(function () {
				var metrics = showReadout(res, wordsRead, wasVoiced);
				if (settings.whisper) {
					studio.startReview(res.heard || res.samples, res.rate, url, res.seconds, metrics, function () {
						openReviewBtn.hidden = false;
						firstLookEl.hidden = true;
					});
				}
			}, 60);
		}).catch(function () {
			resultInfo.textContent = sprintf( /* translators: 1: fmt(recSeconds), 2: (raw.size / 1048576).toFixed(1). */ __( 'The MP3 could not be made here, so download the original and send it to be converted. %1$s long, %2$s MB.', 'foxfire-studio' ), fmt(recSeconds), (raw.size / 1048576).toFixed(1) );
			if (studio.lastRaw ? studio.lastRaw.onSite : false) { resultInfo.textContent += ' ' + __( 'The take is also on the site, waiting for your computer.', 'foxfire-studio' ); }
		});
	}
	downloadA.addEventListener('click', function () { studio.unsaved = false; forgetTake(); sentDone(); });
	downloadRawA.addEventListener('click', function () { if (downloadA.hidden) { studio.unsaved = false; } forgetTake(); sentDone(); });
	dismissBtn.addEventListener('click', function () { resultEl.classList.remove('is-on'); });
	window.addEventListener('beforeunload', function (e) {
		var busy = studio.unsaved;
		if (studio.recorder) { if (studio.recorder.state !== 'inactive') { busy = true; } }
		if (busy) { e.preventDefault(); e.returnValue = ''; }
	});
})();
