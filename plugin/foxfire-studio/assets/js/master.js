/*
 * Foxfire Studio: the MP3: the encoder and broadcast sound workers, tags, artwork and
 * chapters, the music bed and its mix, and mastering a take or an edit.
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
	var track = studio.track;
	var resultAudio = studio.resultAudio;
	var downloadA = studio.downloadA;
	var settings = studio.settings;
	var forgetTake = studio.forgetTake;
	var sentDone = studio.sentDone;
	var slug = studio.slug;
	var blocks = studio.blocks;
	var W = studio.W;
	var fmt = studio.fmt;
	var cleanWithModel = studio.cleanWithModel;
	studio.soundPreset = soundPreset;
	studio.toMp3 = toMp3;
	studio.tagMp3 = tagMp3;
	studio.chaptersFrom = chaptersFrom;
	studio.addChapters = addChapters;
	studio.musicWanted = musicWanted;
	studio.musicLead = musicLead;
	studio.musicSetVoice = musicSetVoice;
	studio.shiftVtt = shiftVtt;
	studio.musicBuild = musicBuild;
	studio.masterMp3 = masterMp3;
	studio.finishExt = finishExt;
	studio.finishWord = finishWord;
	studio.finishPlan = soundPreset;
	studio.finishOneOff = finishOneOff;
	studio.bookCopy = bookCopy;
	if (!track) { return; }
	/*
	 * Turn the browser's recording into a tidy MP3 that plays on every phone:
	 * mono, 44.1 kHz, 128 kbps, cleaned up, silence trimmed from both ends, peaks lifted
	 * to just under full volume, and soft fades so it never starts with a click.
	 */
	/*
	 * The MP3 encoder (0.7.0): LAME as WebAssembly, run in a background worker
	 * (assets/js/mp3.js). The worker refuses the encoder unless it matches its
	 * fingerprint. This site's copy is tried first, then jsDelivr's.
	 */
	var MP3 = RRT.encoder || {};
	var mp3Worker = null;
	var mp3Jobs = {};
	var mp3JobId = 0;
	var mp3OnPage = null;
	function mp3Job(pcm, kbps, url, onProgress, channels) {
		if (mp3Worker === null && window.Worker && MP3.worker) {
			try {
				mp3Worker = new Worker(MP3.worker);
				mp3Worker.onmessage = function (e) {
					var job = mp3Jobs[e.data.id];
					if (!job) { return; }
					if (e.data.progress !== undefined) { job.progress(e.data.progress); return; }
					delete mp3Jobs[e.data.id];
					if (e.data.error) { job.reject(new Error(e.data.error)); } else { job.resolve(e.data.data); }
				};
				mp3Worker.onerror = function (e) {
					if (e && e.preventDefault) { e.preventDefault(); }
					Object.keys(mp3Jobs).forEach(function (k) { mp3Jobs[k].reject(new Error(__( 'The MP3 encoder\'s worker stopped.', 'foxfire-studio' ))); });
					mp3Jobs = {};
					mp3Worker = false;
				};
			} catch (e) { mp3Worker = false; }
		}
		if (mp3Worker) {
			return new Promise(function (resolve, reject) {
				var id = ++mp3JobId;
				mp3Jobs[id] = { resolve: resolve, reject: reject, progress: onProgress };
				// Sent as a copy, so a second attempt still has the audio.
				mp3Worker.postMessage({ id: id, url: url, sha256: MP3.sha256, pcm: pcm, rate: 44100, kbps: kbps, channels: channels || 1 });
			});
		}
		// No workers: load the same script on the page and encode here.
		if (!mp3OnPage) {
			mp3OnPage = new Promise(function (resolve, reject) {
				if (window.RRTMp3) { resolve(); return; }
				var s = document.createElement('script');
				s.src = MP3.worker;
				s.onload = function () { resolve(); };
				s.onerror = function () { reject(new Error(__( 'The MP3 encoder could not be loaded.', 'foxfire-studio' ))); };
				document.head.appendChild(s);
			}).then(function () { return {}; });
		}
		return mp3OnPage.then(function (cache) {
			if (!cache[url]) {
				cache[url] = fetch(url, { credentials: 'omit' }).then(function (r) {
					if (!r.ok) { throw new Error(sprintf( /* translators: %s: r.status. */ __( 'The MP3 encoder could not be loaded (HTTP %s).', 'foxfire-studio' ), r.status )); }
					return r.arrayBuffer();
				}).then(function (buf) { return window.RRTMp3.load(buf, MP3.sha256); });
				cache[url].catch(function () { delete cache[url]; });
			}
			return cache[url];
		}).then(function (ex) { return window.RRTMp3.encode(ex, pcm, 44100, kbps, onProgress, channels || 1); });
	}
	/* Mono 44.1 kHz audio (Float32, or 16-bit as older steps make) to a constant-bitrate MP3 Blob; channels 2 (1.6.0) puts it in both channels of a stereo file. */
	function encodeMp3(pcm, kbps, onProgress, channels) {
		var f = pcm;
		if (!(pcm instanceof Float32Array)) {
			f = new Float32Array(pcm.length);
			for (var i = 0; i < pcm.length; i++) { f[i] = pcm[i] / 32768; }
		}
		var urls = [MP3.url];
		if (MP3.fallback) { urls.push(MP3.fallback); }
		function attempt(k) {
			return mp3Job(f, kbps, urls[k], onProgress, channels).catch(function (err) {
				if (k + 1 < urls.length) { return attempt(k + 1).catch(function () { throw err; }); }
				throw err;
			});
		}
		return attempt(0).then(function (bytes) { return new Blob([bytes], { type: 'audio/mpeg' }); });
	}
	/* Broadcast sound settings from WP-admin (Settings, Foxfire Studio). */
	var SOUND = RRT.sound || { mode: 'off' };
	studio.SOUND = SOUND;
	/*
	 * Finishing presets (1.6.0). SOUND.spec is the preset chosen in Settings;
	 * SOUND.specs every preset, for the review's one-off Finish for menu. A
	 * page from before 1.6.0 (no spec) is read from its mode and target.
	 */
	if (!SOUND.spec) {
		SOUND.spec = SOUND.mode === 'off' ? { key: 'off', kind: 'off' } : (SOUND.mode === 'audiobook' ? { key: 'acx', kind: 'acx', ch: 1, format: 'mp3', kbps: 192 } : { key: 'custom', kind: 'loudness', lufs: SOUND.target || -19, tp: -1, ch: 1, format: 'mp3', kbps: 128 });
	}
	/*
	 * The plan for a preset (the Settings one when none is given): the mode's
	 * own step settings (head, tail, fades) with the preset's loudness, true
	 * peak, channels, file type and bitrate. Null for the simple leveling.
	 */
	function soundPreset(spec) {
		if (!window.RRTSound || !window.RRTSound.plan) { return null; }
		var pl = window.RRTSound.plan(spec || SOUND.spec);
		if (!pl) { return null; }
		var base = window.RRTSound.PRESETS[pl.mode] || window.RRTSound.PRESETS.podcast, out = {};
		Object.keys(base).forEach(function (k) { out[k] = base[k]; });
		Object.keys(pl).forEach(function (k) { out[k] = pl[k]; });
		return out;
	}
	/* The finished file's extension and its name in labels: mp3 and MP3, or wav and WAV. */
	function finishExt(spec) { var P = soundPreset(spec); return P ? P.ext : 'mp3'; }
	function finishWord(spec) { return finishExt(spec) === 'wav' ? 'WAV' : 'MP3'; }
	/*
	 * The chain's options for this voice. Voice polish (1.13.0): each step's
	 * strength, slow leveling, the setup's EQ and the gaps for clicks come
	 * from hearpolish.js (the Settings choice, or this take's one-off).
	 */
	function soundOpts(P, seg) {
		var o = { mode: P.mode, target: P.target, ceilingTp: P.ceilingTp, hp: SOUND.hp, plosives: SOUND.plosives, deess: SOUND.deess, breaths: SOUND.breaths, compress: SOUND.compress };
		var pol = studio.polishFor ? studio.polishFor(seg) : null;
		if (pol) { Object.keys(pol).forEach(function (k) { o[k] = pol[k]; }); }
		return o;
	}
	studio.soundOpts = soundOpts;
	studio.soundTask = soundTask;
	/* Runs a sound.js job in a background worker, or on the page if workers are unavailable. */
	var soundWorker = null;
	var soundJobs = {};
	var soundJobId = 0;
	function soundTask(msg) {
		if (soundWorker === null && RRT.soundUrl && window.Worker) {
			try {
				soundWorker = new Worker(RRT.soundUrl);
				soundWorker.onmessage = function (e) {
					var job = soundJobs[e.data.id];
					if (!job) { return; }
					delete soundJobs[e.data.id];
					if (e.data.error) { job.reject(new Error(e.data.error)); } else { job.resolve(e.data); }
				};
				soundWorker.onerror = function () {
					Object.keys(soundJobs).forEach(function (k) { soundJobs[k].retry(); });
					soundJobs = {};
					soundWorker = false;
				};
			} catch (e) { soundWorker = false; }
		}
		function onPage() {
			if (msg.cmd === 'process') { var r = window.RRTSound.process(msg.data, msg.rate, msg.opts); return { data: r.data, report: r.report }; }
			if (msg.cmd === 'adjust') { return { data: window.RRTSound.adjust(msg.data, msg.rate, msg.mode, msg.gainDb, msg.ceilingTp) }; }
			if (msg.cmd === 'preview') { var pv = window.RRTSound.preview(msg.data, msg.rate, msg.opts, msg.a, msg.b); return { before: pv.before, after: pv.after, report: pv.report }; }
			return { check: window.RRTSound.check(msg.data, msg.rate, msg.mode, msg.target, msg.kbps, msg.more) };
		}
		if (!soundWorker) {
			return new Promise(function (resolve) { window.setTimeout(resolve, 30); }).then(onPage);
		}
		return new Promise(function (resolve, reject) {
			var id = ++soundJobId;
			soundJobs[id] = { resolve: resolve, reject: reject, retry: function () { try { resolve(onPage()); } catch (err) { reject(err); } } };
			msg.id = id;
			soundWorker.postMessage(msg);
		});
	}
	/* The leveling used before 0.5.0, and still used when broadcast sound is off. */
	function levelSimple(seg) {
		var RATE = 44100;
		var len = seg.length;
		var blockLen = Math.round(RATE * 0.05);
		/*
		 * Blocks over -40 dB count as the voice; for a recording quieter than
		 * that (a phone, 1.9.0), blocks within 20 dB of its loudest do.
		 */
		var top = 0;
		for (var t = 0; t < len; t += blockLen) {
			var st = 0, et = Math.min(len, t + blockLen);
			for (var u = t; u < et; u++) { st += seg[u] * seg[u]; }
			top = Math.max(top, st / Math.max(1, et - t));
		}
		var gate = Math.min(0.0001, Math.max(1e-9, top * 0.01));
		var power = 0;
		var counted = 0;
		var i;
		var j;
		for (i = 0; i < len; i += blockLen) {
			var sum = 0;
			var end = Math.min(len, i + blockLen);
			for (j = i; j < end; j++) { sum += seg[j] * seg[j]; }
			var ms = sum / Math.max(1, end - i);
			if (ms > gate) { power += ms; counted++; }
		}
		var rms = counted ? Math.sqrt(power / counted) : 0;
		var gain = rms > 0 ? Math.min(0.13 / rms, 16) : 1;
		var KNEE = 0.7;
		var ROOM = 0.19;
		var fadeIn = Math.round(RATE * 0.1);
		var fadeOut = Math.round(RATE * 0.4);
		var pcm = new Int16Array(len);
		for (i = 0; i < len; i++) {
			var g = gain;
			if (i < fadeIn) { g = g * (i / fadeIn); }
			if (len - i < fadeOut) { g = g * ((len - i) / fadeOut); }
			var v = seg[i] * g;
			var av = Math.abs(v);
			if (av > KNEE) {
				var e2 = Math.exp(2 * (av - KNEE) / ROOM);
				av = KNEE + ROOM * ((e2 - 1) / (e2 + 1));
				v = v < 0 ? -av : av;
			}
			pcm[i] = v < 0 ? v * 32768 : v * 32767;
		}
		return pcm;
	}
	function toMp3(blob, onProgress) {
		/* A new take starts with the Settings choice for its polish (1.13.0). */
		if (studio.polishNewTake) { studio.polishNewTake(); }
		return toMp3Now(blob, onProgress).then(function (res) {
			/* The voice's bands, for this setup's EQ (1.13.0). */
			if (studio.polishLearn) { studio.polishLearn(res); }
			// Tags go on as the last step; the studio check above measured the audio itself.
			res.body = res.blob;
			studio.lastMp3 = res;
			musicNewTake({ samples: res.samples, seconds: res.seconds, chapters: [], cuts: null });
			return tagMp3(res.body, res.seconds, null).then(function (tagged) {
				res.blob = tagged;
				return res;
			});
		});
	}

	/*
	 * MP3 tags and chapters (0.7.0). The tag carries the post's title, author,
	 * site name, year, address and featured image, the same details the
	 * listening player shows on a lock screen. Chapters are added once the
	 * Whisper review knows when each heading was read (addChapters below).
	 */
	var TAGS = RRT.tags || null;
	studio.TAGS = TAGS;
	studio.lastMp3 = null;
	var artworkTry = null;
	/* The featured image as a JPEG of at most 1400 pixels a side, or null. */
	function artwork() {
		if (!TAGS || !TAGS.artwork || !window.fetch) { return Promise.resolve(null); }
		if (!artworkTry) {
			artworkTry = fetch(TAGS.artwork, { credentials: 'same-origin' }).then(function (r) {
				if (!r.ok) { throw new Error('artwork'); }
				return r.blob();
			}).then(function (b) {
				return new Promise(function (resolve, reject) {
					var img = new Image();
					var u = URL.createObjectURL(b);
					img.onload = function () {
						var scale = Math.min(1, 1400 / Math.max(img.naturalWidth, img.naturalHeight));
						var c = document.createElement('canvas');
						c.width = Math.max(1, Math.round(img.naturalWidth * scale));
						c.height = Math.max(1, Math.round(img.naturalHeight * scale));
						var g = c.getContext('2d');
						g.fillStyle = '#ffffff'; // JPEG has no transparency.
						g.fillRect(0, 0, c.width, c.height);
						g.drawImage(img, 0, 0, c.width, c.height);
						URL.revokeObjectURL(u);
						c.toBlob(function (jb) { if (jb) { resolve(jb); } else { reject(new Error('artwork')); } }, 'image/jpeg', 0.88);
					};
					img.onerror = function () { URL.revokeObjectURL(u); reject(new Error('artwork')); };
					img.src = u;
				});
			}).then(function (jb) {
				return jb.arrayBuffer();
			}).then(function (ab) {
				return { mime: 'image/jpeg', data: new Uint8Array(ab) };
			}).catch(function () { return null; });
		}
		return artworkTry;
	}
	/* The MP3 (without a tag) with a fresh tag in front. Falls back to the untagged file if anything fails. */
	function tagMp3(body, seconds, chapters, comment) {
		if (!window.RRTId3 || !TAGS) { return Promise.resolve(body); }
		/* A WAV (1.6.0, the broadcast preset) keeps no ID3 tag in front: it would no longer be a WAV. */
		if (body && body.type === 'audio/wav') { return Promise.resolve(body); }
		return artwork().then(function (art) {
			var tag = window.RRTId3.build({
				title: TAGS.title,
				artist: TAGS.artist,
				album: TAGS.album,
				year: TAGS.year,
				genre: TAGS.genre,
				url: TAGS.url,
				lengthMs: seconds * 1000,
				encoder: sprintf( /* translators: %s: MP3.version || 'wasm-media-encoders'. */ __( 'LAME (%s)', 'foxfire-studio' ), MP3.version || 'wasm-media-encoders' ),
				artwork: art,
				chapters: chapters,
				comment: comment || '',
				lang: TAGS.lang || ''
			});
			return new Blob([tag, body], { type: 'audio/mpeg' });
		}).catch(function () { return body; });
	}
	/*
	 * Chapters from the post's headings, timed by the Whisper review: each
	 * starts when the first word of its heading was heard. The opening chapter,
	 * named after the post, starts at zero. Headings not reached in the take are
	 * left out, and a post without headings gets no chapters.
	 */
	function chaptersFrom(R, seconds) {
		var out = [];
		var seen = {};
		for (var k = 0; k < W.length; k++) {
			var b = W[k].block;
			if (seen[b]) { continue; }
			seen[b] = true;
			if (W[k].kind !== 'title' && W[k].kind !== 'h') { continue; }
			var t = null;
			if (W[k].kind === 'title' && !out.length) {
				t = 0;
			} else {
				for (var j = k; j < R.words.length; j++) {
					if (R.words[j] && R.words[j].s !== null && isFinite(R.words[j].s)) { t = R.words[j].s; break; }
				}
			}
			if (t === null) { continue; }
			// A breath of lead-in, so a chapter never starts mid-syllable.
			if (t > 0) { t = Math.max(0, t - 0.3); }
			var title = (blocks[b] ? blocks[b].textContent : '').replace(/\s+/g, ' ').trim();
			if (!title) { continue; }
			if (out.length && t < out[out.length - 1].start + 1) { continue; }
			out.push({ start: t, title: title });
		}
		if (out.length && out[0].start > 0) { out.unshift({ start: 0, title: TAGS ? TAGS.title : __( 'Opening', 'foxfire-studio' ) }); }
		if (out.length < 2) { return []; }
		out[0].start = 0;
		for (var i = 0; i < out.length; i++) {
			out[i].end = i + 1 < out.length ? out[i + 1].start : seconds;
		}
		return out.filter(function (c) { return c.end > c.start; });
	}
	/* Once the review is ready: put chapters into the MP3 behind the download button, and offer the chapters file. */
	function addChapters(R, audioUrl) {
		studio.rv.chapters.hidden = true;
		if (!studio.lastMp3 || downloadA.href !== audioUrl) { return Promise.resolve(0); }
		var ch = chaptersFrom(R, studio.lastMp3.seconds);
		if (!ch.length) { return Promise.resolve(0); }
		var json = new Blob([window.RRTId3.chaptersJson(ch, TAGS ? TAGS.title : '')], { type: 'application/json+chapters' });
		studio.rv.chapters.href = URL.createObjectURL(json);
		studio.rv.chapters.setAttribute('download', slug + '-chapters.json');
		studio.rv.chapters.hidden = false;
		return tagMp3(studio.lastMp3.body, studio.lastMp3.seconds, ch).then(function (tagged) {
			studio.lastMp3.blob = tagged;
			downloadA.href = URL.createObjectURL(tagged);
			if (voiceNow ? voiceNow.cuts === null : false) { musicSetVoice({ samples: voiceNow.samples, seconds: voiceNow.seconds, chapters: ch, cuts: null }); }
			return ch.length;
		});
	}
	/*
	 * The music bed (0.24.0). The review, the editing and the MP3 behind the
	 * download button stay voice only, so Whisper's timings and every edit
	 * keep working as before. The music is mixed in when the take leaves the
	 * studio: Download MP3, Use this recording, or Listen with music. It is
	 * mixed after the voice chain and before loudness (sound.js mixBed), so the
	 * finished file is leveled and limited as one; its chapters and captions
	 * move later by the music's lead-in.
	 */
	var MUSIC = RRT.music || null;
	studio.MUSIC = MUSIC;
	var musicEl = document.getElementById('tpMusic');
	var musicOn = document.getElementById('tpMusicOn');
	var musicRvWrap = document.getElementById('tpRvMusic');
	var musicRvOn = document.getElementById('tpRvMusicOn');
	var musicListen = document.getElementById('tpMusicListen');
	var musicStatus = document.getElementById('tpMusicStatus');
	var musicAudio = document.getElementById('tpMusicAudio');
	var voiceNow = null;   /* The voice behind the download button: { samples, seconds, chapters, cuts }. */
	var musicMade = null;  /* The mix of voiceNow: { voice, job (a promise), blob, url, seconds, lead }. */
	var musicTrack = null; /* The music, decoded once: a promise of a mono Float32Array at 44.1 kHz. */
	function musicAvailable() { return !!(MUSIC && MUSIC.url && musicOn && window.RRTSound && window.RRTSound.mixBed); }
	function musicWanted() { return musicAvailable() && !!voiceNow && musicOn.checked; }
	function musicLead() { return window.RRTSound.BED.lead; }
	function musicShow() {
		var show = musicAvailable() && !!voiceNow && !downloadA.hidden;
		if (musicEl) {
			musicEl.hidden = !show;
			var nm = musicEl.querySelector('.tp-music-name');
			if (nm) { nm.textContent = MUSIC ? MUSIC.title : ''; }
		}
		if (musicRvWrap) {
			musicRvWrap.hidden = !show;
			musicRvWrap.title = MUSIC ? sprintf( /* translators: %s: MUSIC.title. */ __( 'Mix "%s" under this take when it is downloaded or put on the post', 'foxfire-studio' ), MUSIC.title ) : '';
		}
		musicLabel();
	}
	/* The download button says when the music will be in the file. */
	function musicLabel() {
		var t = downloadA.textContent.replace(/ with music$/, '');
		var want = musicWanted() && !downloadA.hidden;
		var next = want ? sprintf( /* translators: %s: t. */ __( '%s with music', 'foxfire-studio' ), t ) : t;
		if (downloadA.textContent !== next) { downloadA.textContent = next; }
	}
	function musicStop() {
		if (!musicAudio) { return; }
		musicAudio.pause();
		if (musicListen) { musicListen.textContent = __( 'Listen with music', 'foxfire-studio' ); musicListen.classList.remove('is-on'); }
	}
	function musicSetVoice(v) {
		voiceNow = v;
		if (musicMade ? musicMade.url : false) { try { URL.revokeObjectURL(musicMade.url); } catch (e) {} }
		musicMade = null;
		musicStop();
		if (musicAudio) { musicAudio.removeAttribute('src'); }
		if (musicStatus) { musicStatus.textContent = ''; }
		musicShow();
	}
	/* A new take starts with the music switched on. */
	function musicNewTake(v) {
		if (musicOn) { musicOn.checked = true; }
		if (musicRvOn) { musicRvOn.checked = true; }
		musicSetVoice(v);
	}
	function musicTrackLoad() {
		if (!musicTrack) {
			var AC = window.AudioContext || window.webkitAudioContext;
			var OAC = window.OfflineAudioContext || window.webkitOfflineAudioContext;
			musicTrack = fetch(MUSIC.url, { credentials: 'same-origin' }).then(function (r) {
				if (!r.ok) { throw new Error(sprintf( /* translators: %s: r.status. */ __( 'the music could not be loaded (HTTP %s)', 'foxfire-studio' ), r.status )); }
				return r.arrayBuffer();
			}).then(function (ab) {
				var ctx = new AC();
				return ctx.decodeAudioData(ab).then(function (dec) {
					try { ctx.close(); } catch (e) {}
					/* Mono at 44.1 kHz, like the voice: the offline render mixes the channels down. */
					var off = new OAC(1, Math.max(1, Math.ceil(dec.duration * 44100)), 44100);
					var src = off.createBufferSource();
					src.buffer = dec;
					src.connect(off.destination);
					src.start(0);
					return off.startRendering();
				}, function () {
					try { ctx.close(); } catch (e) {}
					throw new Error(__( 'this browser could not open the music file', 'foxfire-studio' ));
				});
			}).then(function (r) { return new Float32Array(r.getChannelData(0)); });
			musicTrack['catch'](function () { musicTrack = null; });
		}
		return musicTrack;
	}
	/* Captions moved later by s seconds. */
	function shiftVtt(text, s) {
		function stamp(t) {
			var h = Math.floor(t / 3600), m = Math.floor((t % 3600) / 60), sec = t % 60;
			return (h < 10 ? '0' : '') + h + ':' + (m < 10 ? '0' : '') + m + ':' + (sec < 10 ? '0' : '') + sec.toFixed(3);
		}
		return text.replace(/(\d+):(\d\d):(\d\d\.\d+)/g, function (all, h, m, sec) {
			return stamp(Number(h) * 3600 + Number(m) * 60 + Number(sec) + s);
		});
	}
	/* The mix of the voice behind the download button, made once and kept until the take changes. */
	function musicBuild() {
		if (!musicWanted()) { return Promise.reject(new Error(__( 'no music bed', 'foxfire-studio' ))); }
		if (musicMade ? musicMade.voice === voiceNow : false) { return musicMade.job; }
		var v = voiceNow;
		var made = { voice: v, blob: null, url: '', seconds: 0, lead: musicLead() };
		var say = function (t) { if (musicStatus) { musicStatus.textContent = t; } };
		say(__( 'Loading the music...', 'foxfire-studio' ));
		made.job = musicTrackLoad().then(function (track) {
			say(__( 'Mixing the music under your voice...', 'foxfire-studio' ));
			return masterMp3(v.samples, function (p, phase) {
				var names = { sound: __( 'Mixing the music and shaping the sound...', 'foxfire-studio' ), check: __( 'Checking the finished file...', 'foxfire-studio' ) };
				say(names[phase] || (sprintf( /* translators: %s: Math.round(p * 100). */ __( 'Making the MP3 with music... %s%%', 'foxfire-studio' ), Math.round(p * 100) )));
			}, { data: track, depth: MUSIC.depth, swell: !!MUSIC.swell });
		}).then(function (m) {
			var BED = window.RRTSound.BED;
			var secs = v.seconds + BED.lead + BED.tail;
			/* Chapters: the first still starts at zero, the rest move by the lead-in. */
			var ch = (v.chapters || []).map(function (c, i) { return { start: i === 0 ? 0 : c.start + BED.lead, title: c.title }; });
			for (var i = 0; i < ch.length; i++) { ch[i].end = i + 1 < ch.length ? ch[i + 1].start : secs; }
			return tagMp3(m.blob, secs, ch, MUSIC.credit ? sprintf( /* translators: %s: MUSIC.credit. */ __( 'Music: %s', 'foxfire-studio' ), MUSIC.credit ) : '').then(function (tagged) {
				if (musicMade !== made) { throw new Error(__( 'the take changed', 'foxfire-studio' )); }
				made.blob = tagged;
				made.url = URL.createObjectURL(tagged);
				made.seconds = secs;
				var c = m.sound ? m.sound.check : null;
				var loud = c ? (m.sound.plan.mode === 'audiobook' ? sprintf( /* translators: %s: c.measures.rms.toFixed(1). */ __( '%s dB RMS', 'foxfire-studio' ), c.measures.rms.toFixed(1) ) : sprintf( /* translators: %s: c.measures.lufs.toFixed(1). */ __( '%s LUFS', 'foxfire-studio' ), c.measures.lufs.toFixed(1) )) : '';
				say(sprintf( /* translators: 1: fmt(secs), 2: loud ? ', ' + loud : '', 3: (tagged.size / 1048576).toFixed(1). */ __( 'With music: %1$s long%2$s, %3$s MB.', 'foxfire-studio' ), fmt(secs), loud ? ', ' + loud : '', (tagged.size / 1048576).toFixed(1) ));
				return made;
			});
		});
		made.job['catch'](function (err) {
			if (musicMade === made) {
				musicMade = null;
				say(sprintf( /* translators: %s: err && err.message ? err.message : __( 'unknown error', 'fox. */ __( 'The music could not be added (%s). The voice-only MP3 is still here: switch the music bed off to use it.', 'foxfire-studio' ), err && err.message ? err.message : __( 'unknown error', 'foxfire-studio' ) ));
			}
		});
		musicMade = made;
		return made.job;
	}
	if (musicEl) {
		var musicSync = function (from) {
			if (musicOn) { musicOn.checked = from.checked; }
			if (musicRvOn) { musicRvOn.checked = from.checked; }
			if (!from.checked) { musicStop(); }
			musicLabel();
		};
		if (musicOn) { musicOn.addEventListener('change', function () { musicSync(musicOn); }); }
		if (musicRvOn) { musicRvOn.addEventListener('change', function () { musicSync(musicRvOn); }); }
		new MutationObserver(musicShow).observe(downloadA, { attributes: true, attributeFilter: ['hidden', 'href'] });
		musicListen.addEventListener('click', function () {
			if (!musicAudio.paused) { musicStop(); return; }
			if (!musicWanted()) {
				musicOn.checked = true;
				musicSync(musicOn);
			}
			musicListen.disabled = true;
			musicBuild().then(function (mm) {
				if (musicAudio.getAttribute('src') !== mm.url) { musicAudio.src = mm.url; }
				resultAudio.pause();
				if (studio.rv.audio) { studio.rv.audio.pause(); }
				musicListen.textContent = __( 'Stop', 'foxfire-studio' );
				musicListen.classList.add('is-on');
				return musicAudio.play();
			})['catch'](function () {}).then(function () { musicListen.disabled = false; });
		});
		musicAudio.addEventListener('ended', musicStop);
		/* Download MP3, with music: the mix is made (or reused) and downloaded under the same name. */
		downloadA.addEventListener('click', function (e) {
			if (!musicWanted()) { return; }
			e.preventDefault();
			e.stopImmediatePropagation();
			var name = downloadA.getAttribute('download') || (slug + '-reading.mp3');
			musicBuild().then(function (mm) {
				var a = document.createElement('a');
				a.href = mm.url;
				a.setAttribute('download', name);
				document.body.appendChild(a);
				a.click();
				a.parentNode.removeChild(a);
				studio.unsaved = false;
				forgetTake();
				sentDone();
			}, function () {});
		}, true);
	}

	/*
	 * Mastering: the voice chain, loudness, the MP3 and the studio check, for
	 * audio already trimmed and cleaned up (mono, 44.1 kHz). Used for every new
	 * reading, and again for an edited one (0.8.0) so its loudness is measured
	 * on exactly what listeners hear. Resolves to { blob, sound }.
	 */
	/*
	 * spec (1.6.0, optional): a finishing preset for this file only (the
	 * review's one-off Finish for menu); the Settings choice otherwise.
	 */
	/* extra (1.13.0, optional): { measure: true } also measures the voice's bands for the setup's EQ. */
	function masterMp3(seg, onProgress, bed, spec, extra) {
		var RATE = 44100;
		/* With the broadcast sound off, the music is mixed in before the simple leveling. */
		var plain = function () { return levelSimple(bed ? window.RRTSound.mixBed(seg, RATE, bed).data : seg); };
		var AC = window.AudioContext || window.webkitAudioContext;
		var OAC = window.OfflineAudioContext || window.webkitOfflineAudioContext;
		var PRE = soundPreset(spec);
		var finish = function (pcm, kbps) {
			return encodeMp3(pcm, kbps, onProgress).then(function (blob) { return { blob: blob, sound: null }; });
		};
		if (!PRE) { return finish(plain(), 128); }
		/*
		 * Broadcast sound (0.5.0): the voice chain and loudness standard, run
		 * in a background worker so a long reading never freezes the page. If
		 * anything goes wrong, the simple leveling takes over.
		 */
		onProgress(0, 'sound');
		var more = { tp: PRE.tp, format: PRE.format, bits: PRE.bits };
		var measure = function (chans, rate) {
			more.chans = chans;
			return soundTask({ cmd: 'check', data: chans[0], rate: rate, mode: PRE.mode, target: PRE.fileTarget, kbps: PRE.kbps, more: more });
		};
		/* Audio rendered at another rate (the broadcast WAV's 48 kHz) by the browser's own resampler. */
		var resample = function (x, to) {
			if (to === RATE) { return Promise.resolve(x); }
			var o = new OAC(1, Math.max(1, Math.ceil(x.length * to / RATE)), to);
			var b = o.createBuffer(1, x.length, RATE);
			b.copyToChannel(x, 0);
			var src = o.createBufferSource();
			src.buffer = b;
			src.connect(o.destination);
			src.start(0);
			return o.startRendering().then(function (r) { return new Float32Array(r.getChannelData(0)); });
		};
		/* Fades, the file, then measure the file itself, as a listener receives it: an MP3 decoded again, a WAV as written. */
		var makeMp3 = function (x) {
			var fi = Math.round(RATE * PRE.fadeIn), fo = Math.round(RATE * PRE.fadeOut);
			var pcm = new Float32Array(x.length);
			for (var q = 0; q < x.length; q++) {
				var v = x[q];
				if (q < fi) { v *= q / fi; }
				if (x.length - q < fo) { v *= (x.length - q) / fo; }
				pcm[q] = v < -1 ? -1 : (v > 1 ? 1 : v);
			}
			if (PRE.format === 'wav') {
				return resample(pcm, PRE.rate).then(function (y) {
					var chans = PRE.channels === 2 ? [y, y] : [y];
					var blob = new Blob([window.RRTSound.wav(chans, PRE.rate, PRE.bits)], { type: 'audio/wav' });
					if (onProgress) { onProgress(1); }
					onProgress(0, 'check');
					return measure(chans, PRE.rate).then(function (c) { return { blob: blob, check: c.check }; }, function () { return { blob: blob, check: null }; });
				});
			}
			return encodeMp3(pcm, PRE.kbps, onProgress, PRE.channels).then(function (blob) {
				onProgress(0, 'check');
				return blob.arrayBuffer().then(function (ab) {
					var dctx = new AC();
					return dctx.decodeAudioData(ab).then(function (dec) {
						try { dctx.close(); } catch (e) {}
						var o2 = new OAC(PRE.channels, Math.max(1, Math.ceil(dec.duration * RATE)), RATE);
						var b2 = o2.createBufferSource();
						b2.buffer = dec;
						b2.connect(o2.destination);
						b2.start(0);
						return o2.startRendering();
					});
				}).then(function (rendered2) {
					var chans = [];
					for (var c = 0; c < rendered2.numberOfChannels; c++) { chans.push(rendered2.getChannelData(c)); }
					return measure(chans, RATE);
				}).then(function (c) { return { blob: blob, check: c.check }; }, function () { return { blob: blob, check: null }; });
			});
		};
		var opts = soundOpts(PRE, seg);
		if (bed) { opts.bed = bed; }
		if (extra ? extra.measure : false) { opts.measure = true; }
		return soundTask({ cmd: 'process', data: new Float32Array(seg), rate: RATE, opts: opts }).then(function (r) {
			var processed = r.data;
			/* An audiobook copy (1.20.0): room tone made up at each end from the take's own pauses, before the file is made and measured. */
			if ((extra ? extra.pad : false) && window.RRTSound.roomPad) {
				var padded = window.RRTSound.roomPad(new Float32Array(processed), RATE, extra.pad.head, extra.pad.tail);
				processed = padded.data;
				r.report.room = padded.report;
			}
			r.report.finish = { key: PRE.key, label: PRE.label, fileTarget: PRE.fileTarget, tp: PRE.tp, channels: PRE.channels, format: PRE.format, kbps: PRE.kbps, rate: PRE.rate, bits: PRE.bits };
			return makeMp3(new Float32Array(processed)).then(function (first) {
				var c = first.check;
				var off = null;
				if (c) {
					off = PRE.mode === 'audiobook' ? -20 - c.measures.rms : PRE.fileTarget - c.measures.lufs;
				}
				if (off === null || !isFinite(off) || Math.abs(off) <= 0.3 || r.report.gainCapped) { return first; }
				// The encoder lost some loudness: nudge the level and encode once more.
				onProgress(0, 'sound');
				return soundTask({ cmd: 'adjust', data: processed, rate: RATE, mode: PRE.mode, gainDb: off, ceilingTp: PRE.ceilingTp }).then(function (a) {
					r.report.encoderNudgeDb = off;
					return makeMp3(a.data);
				}, function () { return first; });
			}).then(function (fin) {
				return { blob: fin.blob, sound: { report: r.report, check: fin.check, plan: PRE } };
			});
		}, function () {
			return finish(plain(), 128);
		});
	}
	/*
	 * A one-off version for another destination (1.6.0): the voice behind
	 * the download button (the take, or the edited version), with the music
	 * bed when it is switched on, finished with another preset and
	 * downloaded. Nothing in the studio changes, and Use this recording keeps
	 * the Settings choice. Resolves to { blob, name, sound, seconds }.
	 */
	function finishOneOff(key, onProgress) {
		var specs = SOUND.specs || [];
		var spec = null;
		for (var i = 0; i < specs.length; i++) { if (specs[i].key === key) { spec = specs[i]; } }
		if (!spec) { return Promise.reject(new Error(__( 'no such preset', 'foxfire-studio' ))); }
		if (!voiceNow) { return Promise.reject(new Error(__( 'no take', 'foxfire-studio' ))); }
		var v = voiceNow;
		var withMusic = musicWanted();
		var BED = window.RRTSound ? window.RRTSound.BED : { lead: 0, tail: 0 };
		var got = withMusic ? musicTrackLoad().then(function (t) { return { data: t, depth: MUSIC.depth, swell: !!MUSIC.swell }; }) : Promise.resolve(null);
		return got.then(function (bed) {
			return masterMp3(v.samples, onProgress || function () {}, bed, spec);
		}).then(function (m) {
			var secs = v.seconds + (withMusic ? BED.lead + BED.tail : 0);
			var ch = (v.chapters || []).map(function (c, j) { return { start: j === 0 || !withMusic ? c.start : c.start + BED.lead, title: c.title }; });
			for (var j = 0; j < ch.length; j++) { ch[j].end = j + 1 < ch.length ? ch[j + 1].start : secs; }
			var base = (downloadA.getAttribute('download') || (slug + '-reading.mp3')).replace(/\.(mp3|wav)$/i, '');
			var name = base + '-' + String(spec.key).replace(/_/g, '-') + '.' + finishExt(spec);
			return tagMp3(m.blob, secs, ch.length > 1 ? ch : null, withMusic && MUSIC.credit ? sprintf( /* translators: %s: MUSIC.credit. */ __( 'Music: %s', 'foxfire-studio' ), MUSIC.credit ) : '').then(function (tagged) {
				return { blob: tagged, name: name, sound: m.sound, seconds: secs };
			});
		});
	}
	/*
	 * An audiobook copy (1.20.0): the voice behind the download button (the
	 * take, or the edited version), never with the music bed, finished with
	 * the Audiobook (ACX) preset: -20 dB RMS, peaks under -3.5 dB, 192 kbps
	 * mono, with room tone made up to the wanted seconds at each end from the
	 * take's own pauses. Resolves to { blob, check, report }.
	 */
	function bookCopy(onProgress) {
		if (!voiceNow) { return Promise.reject(new Error(__( 'no take', 'foxfire-studio' ))); }
		var spec = null;
		(SOUND.specs || []).forEach(function (sp) { if (sp.key === 'acx') { spec = sp; } });
		if (!spec) { spec = { key: 'acx', kind: 'acx', ch: 1, format: 'mp3', kbps: 192 }; }
		var B = RRT.book || {};
		return masterMp3(voiceNow.samples, onProgress || function () {}, null, spec, { pad: { head: B.head || 1.2, tail: B.tail || 3 } }).then(function (m) {
			return { blob: m.blob, check: m.sound ? m.sound.check : null, report: m.sound ? m.sound.report : null };
		});
	}
	function toMp3Now(blob, onProgress) {
		var AC = window.AudioContext || window.webkitAudioContext;
		var OAC = window.OfflineAudioContext || window.webkitOfflineAudioContext;
		if (!AC) { return Promise.reject(new Error('no audio')); }
		if (!OAC) { return Promise.reject(new Error('no audio')); }
		var RATE = 44100;
		var ctx = new AC();
		return blob.arrayBuffer().then(function (ab) {
			return ctx.decodeAudioData(ab);
		}).then(function (decoded) {
			try { ctx.close(); } catch (e) {}
			var off = new OAC(1, Math.max(1, Math.ceil(decoded.duration * RATE)), RATE);
			var src = off.createBufferSource();
			src.buffer = decoded;
			src.connect(off.destination);
			src.start(0);
			return off.startRendering();
		}).then(function (rendered) {
			var data = rendered.getChannelData(0);
			var n = data.length;
			/* The speech's start and end, by the recording's own level (1.9.0: a quiet phone take was cut after its last loud word). */
			var bounds = window.RRTSound.speechBounds(data, RATE);
			var first = bounds.first;
			var lastS = bounds.last;
			var PRE = soundPreset();
			first = Math.max(0, first - Math.round(RATE * (PRE ? PRE.head : 0.25)));
			lastS = Math.min(n, lastS + Math.round(RATE * (PRE ? PRE.tail : 0.6)));
			if (lastS <= first) { first = 0; lastS = n; }
			var len = lastS - first;

			var room = null;
			/* Where the kept stretch starts in the file, so word times from elsewhere (a combined reading, 1.3.0) can be moved to match. */
			var head = first / RATE;
			/*
			 * Whisper listens to the untouched recording. Noise reduction made for
			 * human ears can throw speech recognition off even when it changes the
			 * voice by a fraction of a decibel (seen on a real take in 0.4.0: 96%
			 * accuracy raw, 67% cleaned). Cleanup is for the listener's MP3 only.
			 */
			var heard = data.subarray(first, lastS);
			var cleaning = settings.clean ? cleanWithModel(data.subarray(first, lastS), RATE, onProgress) : Promise.resolve(null);
			return cleaning.then(function (cleaned) {
			if (cleaned) {
				data = cleaned.data;
				first = 0;
				lastS = len;
				room = cleaned.report;
			}
			var seg = data.subarray(first, lastS);
			return masterMp3(seg, onProgress, null, null, { measure: true }).then(function (m) {
				return { blob: m.blob, seconds: len / RATE, samples: seg, heard: heard, rate: RATE, room: room, sound: m.sound, start: head };
			});
			});
		}).catch(function (err) {
			try { ctx.close(); } catch (e) {}
			throw err;
		});
	}
})();
