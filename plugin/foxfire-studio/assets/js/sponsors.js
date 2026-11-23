/*
 * Foxfire Studio: sponsor messages in the browser (1.22.0).
 *
 * On a message's screen: measures any reading the site does not know the
 * loudness of yet, then makes the message's copies in each reading's
 * format and loudness (decoded, leveled with sound.js and encoded once with
 * the plugin's MP3 encoder, mp3.js in a worker) and sends them to the site,
 * which joins them to the readings by copying frames.
 *
 * In a reading's box: finds the pauses in the reading once per file (a
 * mid-roll only goes in a pause), fills in the suggested places, and plays
 * five seconds either side of the chosen one.
 *
 * The pure helpers at the top are also used by the tests in Node.
 */
(function (root) {
	'use strict';

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

	var S = {};

	/* How much louder the same voice measures in two channels than in one (BS.1770). */
	S.STEREO_LU = 3.01;

	/*
	 * ---------------------------------------------------------------------
	 * Pure helpers
	 * ---------------------------------------------------------------------
	 */

	/* Seconds as m:ss, or h:mm:ss from an hour. */
	S.clock = function (secs) {
		var s = Math.max(0, Math.round(Number(secs) || 0));
		var h = Math.floor(s / 3600);
		var m = Math.floor((s % 3600) / 60);
		var r = s % 60;
		return (h ? h + ':' + (m < 10 ? '0' : '') + m : String(m)) + ':' + (r < 10 ? '0' : '') + r;
	};

	/* m:ss, h:mm:ss or plain seconds as seconds, or null. */
	S.parseTime = function (s) {
		var t = String(s || '').trim();
		var m = /^(\d+):(\d{1,2}):(\d{1,2})$/.exec(t);
		if (m) { return Number(m[1]) * 3600 + Number(m[2]) * 60 + Number(m[3]); }
		m = /^(\d+):(\d{1,2})(?:[.,](\d+))?$/.exec(t);
		if (m) { return Number(m[1]) * 60 + Number(m[2]) + (m[3] ? Number('0.' + m[3]) : 0); }
		if (/^\d+(?:\.\d+)?$/.test(t)) { return Number(t); }
		return null;
	};

	/* A REST address from the root WordPress gives, which may already hold a query. */
	S.restUrl = function (rootUrl, path, query) {
		var url = String(rootUrl) + String(path);
		var q = [];
		Object.keys(query || {}).forEach(function (k) {
			q.push(encodeURIComponent(k) + '=' + encodeURIComponent(query[k]));
		});
		if (!q.length) { return url; }
		return url + (url.indexOf('?') === -1 ? '?' : '&') + q.join('&');
	};

	/* One channel from one or two: the two averaged. */
	S.monoMix = function (chs) {
		if (chs.length < 2) { return chs[0]; }
		var a = chs[0];
		var b = chs[1];
		var out = new Float32Array(a.length);
		for (var i = 0; i < a.length; i++) { out[i] = (a[i] + b[i]) / 2; }
		return out;
	};

	/*
	 * The pauses in a reading: stretches of at least minLen seconds that stay
	 * well below the voice around them. Each stretch is judged against the
	 * recording's own level within three seconds either side (its loudest
	 * and its quietest there), never a fixed number, so a quiet take, a
	 * noisy room and a take whose level changes partway all work.
	 *
	 * @param {Float32Array} x      One channel.
	 * @param {number}       rate   Its sample rate.
	 * @param {number}       minLen The shortest pause, in seconds.
	 * @return {Array} Each: s, e (seconds), rounded to milliseconds.
	 */
	S.findPauses = function (x, rate, minLen) {
		var win = Math.max(1, Math.round(rate * 0.02));
		var n = Math.floor(x.length / win);
		if (n < 10) { return []; }
		var db = new Float32Array(n);
		for (var i = 0; i < n; i++) {
			var sum = 0;
			for (var j = i * win, end = j + win; j < end; j++) { sum += x[j] * x[j]; }
			var r = Math.sqrt(sum / win);
			db[i] = r > 1e-6 ? 20 * Math.log10(r) : -120;
		}
		/* Half-second chunks: the loud and quiet ends of each. */
		var per = 25;
		var chunks = Math.ceil(n / per);
		var cLoud = new Float32Array(chunks);
		var cFloor = new Float32Array(chunks);
		for (var c = 0; c < chunks; c++) {
			var part = Array.prototype.slice.call(db, c * per, Math.min(n, (c + 1) * per)).sort(function (a, b) { return a - b; });
			cLoud[c] = part[Math.min(part.length - 1, Math.floor(part.length * 0.9))];
			cFloor[c] = part[Math.floor(part.length * 0.1)];
		}
		/* Each chunk's threshold, from the chunks within three seconds either side. */
		var reach = 6;
		var thr = new Float32Array(chunks);
		for (var k = 0; k < chunks; k++) {
			var loud = -120;
			var floor = 0;
			for (var q = Math.max(0, k - reach); q <= Math.min(chunks - 1, k + reach); q++) {
				if (cLoud[q] > loud) { loud = cLoud[q]; }
				if (cFloor[q] < floor) { floor = cFloor[q]; }
			}
			thr[k] = loud - Math.min(24, 0.6 * Math.max(0, loud - floor));
		}
		var out = [];
		var start = -1;
		var flush = function (endIdx) {
			var s = start * win / rate;
			var e = endIdx * win / rate;
			if (e - s >= minLen) {
				/* A little in from each edge, where the voice fades, when the pause is long enough to spare it. */
				if (e - s - 0.1 >= minLen) { s += 0.05; e -= 0.05; }
				out.push({ s: Math.round(s * 1000) / 1000, e: Math.round(e * 1000) / 1000 });
			}
		};
		for (var w = 0; w < n; w++) {
			if (db[w] < thr[Math.floor(w / per)]) {
				if (start < 0) { start = w; }
			} else if (start >= 0) {
				flush(w);
				start = -1;
			}
		}
		if (start >= 0) { flush(n); }
		return out;
	};

	/* Where to listen for a choice in the box: the option's time, the typed time, or the plan's. */
	S.pickTime = function (selValue, typed, boot) {
		if (selValue && selValue !== 'other') { return Number(selValue); }
		if (selValue === 'other') { var t = S.parseTime(typed); if (t !== null) { return t; } }
		if (boot && boot.mid && isFinite(boot.mid.t)) { return Number(boot.mid.t); }
		if (boot && boot.suggest && boot.suggest.length) { return Number(boot.suggest[0].t); }
		return null;
	};

	/*
	 * ---------------------------------------------------------------------
	 * Shared
	 * ---------------------------------------------------------------------
	 */

	function api(boot, method, path, body, query) {
		var opts = { method: method, credentials: 'same-origin', headers: { 'X-WP-Nonce': boot.nonce } };
		if (body instanceof FormData) {
			opts.body = body;
		} else if (body) {
			opts.headers['Content-Type'] = 'application/json';
			opts.body = JSON.stringify(body);
		}
		return fetch(S.restUrl(boot.rest, path, query), opts).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (j) {
				if (!r.ok) { throw new Error(j && j.message ? j.message : sprintf( /* translators: %s: HTTP status. */ __( 'The site answered %s.', 'foxfire-studio' ), r.status)); }
				return j;
			});
		});
	}

	/* The audio at an address, decoded at a rate (resampled by the browser if it differs). */
	function decode(url, rate) {
		return fetch(url, { credentials: 'same-origin' }).then(function (r) {
			if (!r.ok) { throw new Error(sprintf( /* translators: %s: HTTP status. */ __( 'The audio could not be fetched (HTTP %s).', 'foxfire-studio' ), r.status)); }
			return r.arrayBuffer();
		}).then(function (ab) {
			var Ctx = root.OfflineAudioContext || root.webkitOfflineAudioContext;
			if (!Ctx) { throw new Error(__( 'This browser cannot decode audio here.', 'foxfire-studio' )); }
			var ctx = new Ctx(1, 1, rate || 44100);
			return new Promise(function (ok, bad) { ctx.decodeAudioData(ab, ok, bad); });
		}).then(function (buf) {
			var chs = [];
			for (var c = 0; c < Math.min(2, buf.numberOfChannels); c++) { chs.push(buf.getChannelData(c)); }
			return { chs: chs, rate: buf.sampleRate };
		});
	}

	function el(tag, cls, text) {
		var e = document.createElement(tag);
		if (cls) { e.className = cls; }
		if (text !== undefined) { e.textContent = text; }
		return e;
	}

	/*
	 * ---------------------------------------------------------------------
	 * A message's copies
	 * ---------------------------------------------------------------------
	 */

	function Copies(host, boot) {
		this.host = host;
		this.boot = boot;
		this.needs = boot.needs || [];
		this.measure = boot.measure || [];
		this.busy = '';
		this.note = '';
		this.bad = false;
		this.worker = null;
		this.render();
		if (this.measure.length) { this.measureAll(); }
	}

	Copies.prototype.say = function (msg, bad) {
		this.note = msg || '';
		this.bad = !!bad;
		this.render();
	};

	Copies.prototype.missing = function () {
		return this.needs.filter(function (n) { return !n.have; });
	};

	Copies.prototype.render = function () {
		var self = this;
		var h = this.host;
		h.innerHTML = '';
		if (!this.boot.audio) {
			h.appendChild(el('p', 'description', __( 'Record the message first (Recording, on the right). Its copies are made from the recording.', 'foxfire-studio' )));
		}
		if (!this.needs.length && !this.measure.length) {
			h.appendChild(el('p', 'description', __( 'No reading in the feed fits this message\'s choices yet.', 'foxfire-studio' )));
		}
		if (this.needs.length) {
			h.appendChild(el('p', '', __( 'A message is joined to a reading by copying its sound as it is, so it needs a copy in each reading\'s format and loudness:', 'foxfire-studio' )));
			var ul = el('ul', 'rrt-spot-needs');
			this.needs.forEach(function (n) {
				var li = el('li', n.have ? 'is-done' : 'is-todo');
				li.setAttribute('data-need', n.need);
				li.appendChild(el('strong', '', n.label));
				li.appendChild(document.createTextNode(' ' + (n.have ? __( '(made)', 'foxfire-studio' ) : __( '(still to make)', 'foxfire-studio' ))));
				li.appendChild(el('span', 'description', ' ' + sprintf( /* translators: %s: titles of readings. */ __( 'for %s', 'foxfire-studio' ), n.titles.join(', '))));
				ul.appendChild(li);
			});
			h.appendChild(ul);
		}
		if (this.measure.length) {
			h.appendChild(el('p', 'description', sprintf( /* translators: %d: number of readings. */ __( 'Measuring the loudness of %d reading(s) first...', 'foxfire-studio' ), this.measure.length)));
		}
		var miss = this.missing();
		if (miss.length && this.boot.audio && !this.measure.length) {
			var b = el('button', 'button button-primary', __( 'Make them now', 'foxfire-studio' ));
			b.type = 'button';
			b.setAttribute('data-rrt-spot-make', '1');
			b.disabled = !!this.busy;
			b.addEventListener('click', function () { self.makeAll(); });
			h.appendChild(el('p')).appendChild(b);
		}
		if (this.busy || this.note) {
			var p = el('p', this.bad ? 'rrt-spot-note is-bad' : 'rrt-spot-note', this.busy || this.note);
			p.setAttribute('role', 'status');
			h.appendChild(p);
		}
	};

	Copies.prototype.refresh = function () {
		var self = this;
		return api(this.boot, 'GET', 'spots/' + this.boot.spot + '/needs').then(function (j) {
			self.needs = j.needs || [];
			self.measure = j.measure || [];
			if (j.src !== undefined) { self.boot.src = j.src; }
			self.render();
		});
	};

	/* Measures each reading the site does not know the loudness of yet, one at a time. */
	Copies.prototype.measureAll = function () {
		var self = this;
		var next = this.measure[0];
		if (!next || !root.RRTSound) { return Promise.resolve(); }
		/* translators: %s: a reading's title. */
		this.busy = sprintf(__( 'Measuring %s...', 'foxfire-studio' ), next.title);
		this.render();
		return decode(next.url, 44100).then(function (a) {
			var l = root.RRTSound.lufsMulti(a.chs, a.rate);
			if (!isFinite(l)) { throw new Error(__( 'This reading is silent.', 'foxfire-studio' )); }
			return api(self.boot, 'POST', 'collections/level', { post: next.post, key: next.key, lufs: Math.round(l * 100) / 100 });
		}).then(function () {
			self.busy = '';
			return self.refresh();
		}).then(function () {
			if (self.measure.length && self.measure[0].post !== next.post) { return self.measureAll(); }
			if (self.measure.length) { throw new Error(__( 'The reading was measured but the site still asks for it.', 'foxfire-studio' )); }
		}).catch(function (e) {
			self.busy = '';
			/* translators: 1: a reading's title, 2: what went wrong. */
			self.say(sprintf(__( '%1$s could not be measured: %2$s', 'foxfire-studio' ), next.title, e.message), true);
		});
	};

	/* The MP3 encoder in a worker: this site's copy first, then the fallback. */
	Copies.prototype.encode = function (pcm, fmt, onProgress) {
		var enc = this.boot.encoder;
		var self = this;
		var run = function (url) {
			return new Promise(function (ok, bad) {
				if (!self.worker) { self.worker = new Worker(enc.worker); }
				var w = self.worker;
				w.onmessage = function (e) {
					if (e.data.progress !== undefined) { if (onProgress) { onProgress(e.data.progress); } return; }
					if (e.data.error) { bad(new Error(e.data.error)); } else { ok(e.data.data); }
				};
				w.onerror = function (e) { if (e && e.preventDefault) { e.preventDefault(); } self.worker = null; bad(new Error(__( 'The MP3 encoder\'s worker stopped.', 'foxfire-studio' ))); };
				w.postMessage({ id: 1, url: url, sha256: enc.sha256, pcm: pcm, rate: fmt.rate, kbps: fmt.kbps, channels: fmt.ch });
			});
		};
		return run(enc.url).catch(function (e) {
			if (!enc.fallback) { throw e; }
			if (self.worker) { self.worker.terminate(); self.worker = null; }
			return run(enc.fallback);
		});
	};

	/* Makes one copy: decoded at the reading's rate, leveled to its loudness, encoded in its format. */
	Copies.prototype.makeOne = function (n) {
		var self = this;
		var fmt = n.fmt;
		var lufs = null;
		/* translators: %s: a format and loudness. */
		this.busy = sprintf(__( 'Making the copy for %s: decoding...', 'foxfire-studio' ), n.label);
		this.render();
		return decode(this.boot.audio, fmt.rate).then(function (a) {
			var x = S.monoMix(a.chs);
			if (root.RRTSound && isFinite(n.lu)) {
				/* n.lu is as one channel measures it; a stereo file of the same voice measures 3 LU louder. */
				var y = root.RRTSound.toLoudness(x, a.rate, Number(n.lu), -1, 30);
				x = y.data;
				lufs = isFinite(y.lufs) ? y.lufs : null;
			}
			/* translators: %s: a format and loudness. */
			self.busy = sprintf(__( 'Making the copy for %s: encoding...', 'foxfire-studio' ), n.label);
			self.render();
			return self.encode(x, fmt);
		}).then(function (mp3) {
			var fd = new FormData();
			fd.append('mp3', new Blob([mp3], { type: 'audio/mpeg' }), 'message.mp3');
			fd.append('need', n.need);
			fd.append('src', self.boot.src);
			if (lufs !== null) { fd.append('lufs', String(Math.round(lufs * 100) / 100)); }
			return api(self.boot, 'POST', 'spots/' + self.boot.spot + '/copy', fd);
		}).then(function (j) {
			self.needs = j.needs || self.needs;
		});
	};

	Copies.prototype.makeAll = function () {
		var self = this;
		var list = this.missing();
		var i = 0;
		var step = function () {
			if (i >= list.length) {
				self.busy = '';
				self.say(__( 'Every copy is made. The readings\' sponsored copies are being made on the site now.', 'foxfire-studio' ));
				return null;
			}
			var n = list[i++];
			return self.makeOne(n).then(step);
		};
		return Promise.resolve().then(step).catch(function (e) {
			self.busy = '';
			/* translators: %s: what went wrong. */
			self.say(sprintf(__( 'A copy could not be made: %s', 'foxfire-studio' ), e.message), true);
		});
	};

	/*
	 * ---------------------------------------------------------------------
	 * A reading's box
	 * ---------------------------------------------------------------------
	 */

	function ReadingBox(host, boot) {
		var self = this;
		this.host = host;
		this.boot = boot;
		this.sel = host.querySelector('[data-rrt-spot-mid]');
		this.other = host.querySelector('[data-rrt-spot-other]');
		this.hearBtn = host.querySelector('[data-rrt-spot-hear]');
		this.audio = null;
		this.stopAt = null;
		if (this.sel) { this.sel.addEventListener('change', function () { self.showOther(); }); }
		if (this.hearBtn) { this.hearBtn.addEventListener('click', function () { self.hear(); }); }
		this.showOther();
		if (boot.find && boot.url) { this.find(); }
	}

	ReadingBox.prototype.showOther = function () {
		if (this.other) { this.other.hidden = !this.sel || this.sel.value !== 'other'; }
	};

	ReadingBox.prototype.note = function (msg) {
		var p = this.host.querySelector('[data-rrt-spot-finding]');
		if (p) { p.textContent = msg; }
	};

	/* Finds the pauses once for this file, then offers the places near the middle. */
	ReadingBox.prototype.find = function () {
		var self = this;
		var boot = this.boot;
		return decode(boot.url, 16000).then(function (a) {
			var list = S.findPauses(S.monoMix(a.chs), a.rate, Number(boot.min) || 0.6);
			return api(boot, 'POST', 'spots/pauses', { post: boot.post, key: boot.key, pauses: list });
		}).then(function (j) {
			boot.suggest = j.suggest || [];
			self.fill();
			self.note(boot.suggest.length ? __( 'Pauses found. Choose one, or leave the best one near the middle.', 'foxfire-studio' ) : __( 'No pause long enough was found, so this reading takes no mid-roll.', 'foxfire-studio' ));
		}).catch(function (e) {
			/* translators: %s: what went wrong. */
			self.note(sprintf(__( 'The pauses could not be found: %s', 'foxfire-studio' ), e.message));
		});
	};

	ReadingBox.prototype.fill = function () {
		if (!this.sel) { return; }
		var keep = this.sel.value;
		var other = this.sel.querySelector('option[value="other"]');
		Array.prototype.slice.call(this.sel.options).forEach(function (o) {
			if (o.value !== '' && o.value !== 'other') { o.parentNode.removeChild(o); }
		});
		this.boot.suggest.forEach(function (s) {
			var o = document.createElement('option');
			o.value = String(s.t);
			/* translators: 1: a time such as 7:12, 2: the pause's length in seconds. */
			o.textContent = sprintf(__( '%1$s (a pause of %2$s s)', 'foxfire-studio' ), S.clock(s.t), (Math.round((s.e - s.s) * 10) / 10).toFixed(1));
			this.sel.insertBefore(o, other);
		}, this);
		this.sel.value = keep;
		if (this.sel.value !== keep) { this.sel.value = ''; }
	};

	/* Plays five seconds either side of the chosen place. */
	ReadingBox.prototype.hear = function () {
		var self = this;
		var t = S.pickTime(this.sel ? this.sel.value : '', (this.host.querySelector('#rrt_spot_at') || {}).value, this.boot);
		if (t === null || !this.boot.url) { return; }
		if (!this.audio) {
			this.audio = new Audio(this.boot.url);
			this.audio.addEventListener('timeupdate', function () {
				if (self.stopAt !== null && self.audio.currentTime >= self.stopAt) { self.audio.pause(); self.stopAt = null; }
			});
		}
		this.stopAt = t + 5;
		var go = function () { self.audio.currentTime = Math.max(0, t - 5); self.audio.play(); };
		if (this.audio.readyState >= 1) { go(); } else { this.audio.addEventListener('loadedmetadata', go, { once: true }); this.audio.load(); }
	};

	S.Copies = Copies;
	S.ReadingBox = ReadingBox;
	root.RRTSponsors = S;
	if (typeof module !== 'undefined' && module.exports) { module.exports = S; }

	function start() {
		var copies = document.querySelector('[data-rrt-spot-copies]');
		var cb = document.getElementById('rrt-spot-screen-boot');
		if (copies && cb) {
			try { copies.rrtCopies = new Copies(copies, JSON.parse(cb.textContent)); } catch (e) { /* the box stays as it is */ }
		}
		Array.prototype.forEach.call(document.querySelectorAll('[data-rrt-spot-box]'), function (box) {
			var b = box.querySelector('.rrt-spot-boot');
			if (!b) { return; }
			try { box.rrtSpot = new ReadingBox(box, JSON.parse(b.textContent)); } catch (e) { /* the box stays as it is */ }
		});
	}
	if (typeof document !== 'undefined' && document.addEventListener) {
		if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
	}
})(typeof window !== 'undefined' ? window : this);
