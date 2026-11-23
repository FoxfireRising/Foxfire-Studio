/*
 * Foxfire Studio: the listening player on public posts.
 *
 * Written for caching plugins' script optimisers, which can mangle a double
 * ampersand: this file uses nested ifs instead, and only block comments.
 *
 * Every listener action is announced as a browser event, rrt:player, with
 * { type, post, t, rate, ... }. Nothing is stored or sent from here; the
 * listener analytics (listen.js, 0.10.0) listens for these events.
 */
(function () {
	'use strict';

	function fmt(sec) {
		if (!isFinite(sec)) { sec = 0; }
		sec = Math.max(0, Math.floor(sec));
		var m = Math.floor(sec / 60);
		var s = sec % 60;
		return m + ':' + (s < 10 ? '0' : '') + s;
	}
	/* 1.33.0: set from the player's data; outside English, letters of any script are kept. */
	var LANG = 'en';
	var NOT_WORD = null;
	function clean(s) {
		if (LANG !== 'en') {
			if (NOT_WORD === null) {
				try { NOT_WORD = new RegExp('[^\\p{L}\\p{M}\\p{N}]', 'gu'); } catch (e) { NOT_WORD = /[^a-z0-9à-ɏ]/g; }
			}
			var t = String(s);
			if (t.normalize) { t = t.normalize('NFC'); }
			return t.toLowerCase().replace(/[’']/g, '').replace(NOT_WORD, '');
		}
		return String(s).toLowerCase().replace(/[’']/g, '').replace(/[^a-z0-9à-ɏ]/g, '');
	}
	/*
	 * Finds each caption word in the post (0.19.1). The very next post word
	 * is taken at once; a word further ahead counts only when the two
	 * caption words after it follow it in the post too. A caption word with
	 * no place in the post (the spoken title, a stumble, an aside) is skipped
	 * without moving the place in the post, so one stray word can no longer
	 * throw the whole reading out of step. Further on than 40 words, a word
	 * is trusted when at least five of the six after it follow it too (a
	 * skipped paragraph, say). Returns the post index for each
	 * caption word, or -1.
	 */
	function align(cap, post) {
		var out = [];
		var at = 0;
		function agrees(j, k, len) {
			var n = 0;
			var d;
			for (d = 1; d <= len; d++) {
				if (j + d >= cap.length) { n++; } else if (k + d >= post.length) { n++; } else if (cap[j + d] === post[k + d]) { n++; }
			}
			return n;
		}
		cap.forEach(function (w, j) {
			var reach = Math.min(post.length, at + 40);
			var k;
			for (k = at; k < reach; k++) {
				if (post[k] !== w) { continue; }
				if (k > at) { if (agrees(j, k, 2) < 2) { continue; } }
				out.push(k);
				at = k + 1;
				return;
			}
			/* Not near: a longer run that follows on anywhere later in the post is trusted too. */
			if (j + 6 < cap.length) {
				for (k = reach; k < post.length - 6; k++) {
					if (post[k] !== w) { continue; }
					if (agrees(j, k, 6) < 5) { continue; }
					out.push(k);
					at = k + 1;
					return;
				}
			}
			out.push(-1);
		});
		return out;
	}
	window.RRTAlign = align;

	function store(key, value) {
		try {
			if (value === undefined) { return JSON.parse(window.localStorage.getItem(key)); }
			if (value === null) { window.localStorage.removeItem(key); } else { window.localStorage.setItem(key, JSON.stringify(value)); }
		} catch (e) {}
		return null;
	}

	/* "1:45", "105", "1m45s" or "1h2m3s" into seconds. */
	function parseTime(v) {
		if (!v) { return 0; }
		v = String(v).trim();
		if (/^\d+(\.\d+)?$/.test(v)) { return parseFloat(v); }
		if (/^\d+(:\d{1,2}){1,2}$/.test(v)) {
			return v.split(':').reduce(function (acc, n) { return acc * 60 + parseInt(n, 10); }, 0);
		}
		var m = /^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/.exec(v);
		if (m) { return (parseInt(m[1] || 0, 10) * 3600) + (parseInt(m[2] || 0, 10) * 60) + parseInt(m[3] || 0, 10); }
		return 0;
	}
	function startTimeFromUrl() {
		var t = 0;
		try { t = parseTime(new URLSearchParams(window.location.search).get('t')); } catch (e) {}
		if (!t) {
			var h = /[#&]t=([^&]+)/.exec(window.location.hash);
			if (h) { t = parseTime(decodeURIComponent(h[1])); }
		}
		return t;
	}

	function parseVtt(text) {
		var cues = [];
		var blocks = String(text).replace(/\r/g, '').split(/\n\n+/);
		blocks.forEach(function (b) {
			var lines = b.split('\n');
			var i;
			for (i = 0; i < lines.length; i++) {
				var m = /(\d+:)?(\d+):(\d+)\.(\d+)\s+-->\s+(\d+:)?(\d+):(\d+)\.(\d+)/.exec(lines[i]);
				if (m) {
					var s = (m[1] ? parseInt(m[1], 10) * 3600 : 0) + parseInt(m[2], 10) * 60 + parseInt(m[3], 10) + parseInt(m[4], 10) / 1000;
					var e = (m[5] ? parseInt(m[5], 10) * 3600 : 0) + parseInt(m[6], 10) * 60 + parseInt(m[7], 10) + parseInt(m[8], 10) / 1000;
					var txt = lines.slice(i + 1).join(' ').replace(/<[^>]+>/g, '').trim();
					if (txt) { cues.push({ s: s, e: e, text: txt }); }
					break;
				}
			}
		});
		return cues;
	}

	/*
	 * One of the player's own words, as translated on the server (0.25.0), with
	 * %s, %1$s and %2$s filled in from the extra arguments.
	 */
	function say(text, key, fallback) {
		var f = typeof text[key] === 'string' ? text[key] : fallback;
		var args = Array.prototype.slice.call(arguments, 3);
		var i = 0;
		return f.replace(/%(?:(\d+)\$)?s/g, function (m, pos) {
			var v = pos ? args[Number(pos) - 1] : args[i++];
			return v === undefined ? '' : String(v);
		});
	}
	window.RRTSay = say;

	function Player(el) {
		var self = this;
		var data = {};
		try { data = JSON.parse(el.getAttribute('data-rrt')) || {}; } catch (e) {}
		this.text = data.text || {};
		this.el = el;
		this.data = data;
		/* Read along runs for one player only, on its own post's page, so that player's language is the one words are cleaned in. */
		if (data.follow && data.lang) { LANG = String(data.lang); }
		this.audio = el.querySelector('.rrt-audio');
		this.playBtn = el.querySelector('.rrt-play');
		this.track = el.querySelector('.rrt-track');
		this.fill = el.querySelector('.rrt-fill');
		this.nowEl = el.querySelector('.rrt-now');
		this.lenEl = el.querySelector('.rrt-len');
		this.speedBtn = el.querySelector('.rrt-speed');
		this.followBtn = el.querySelector('.rrt-follow');
		this.resumeBtn = el.querySelector('.rrt-resume');
		this.transcript = el.querySelector('.rrt-transcript');
		/* 1.26.0: the Transcript button, and a quiet line read out by screen readers. */
		this.transcriptBtn = el.querySelector('.rrt-transcript-btn');
		this.sayEl = el.querySelector('.rrt-say');
		this.listOpen = false;
		this.speeds = (data.speeds || [0.75, 1, 1.25, 1.5, 1.75, 2]).filter(function (x) { return x > 0; });
		if (this.speeds.indexOf(1) === -1) { this.speeds.push(1); this.speeds.sort(function (a, b) { return a - b; }); }
		this.resumeKey = 'rrtResume_' + (data.post || 0);
		this.cues = null;
		this.words = null;
		this.following = false;
		this.lastSave = 0;
		this.lastPos = 0;
		this.heardIn = 0;
		this.readySent = false;
		this.userScrolledAt = 0;
		this.startAt = startTimeFromUrl();
		this.chapters = null;
		this.chapterNow = -1;
		this.labelEl = el.querySelector('.rrt-label');

		this.audio.removeAttribute('controls');
		el.classList.add('is-ready');
		/* Chapters are looked up once the page has settled, so they never compete with the page loading. */
		window.setTimeout(function () { self.loadChapters(); }, 1200);

		var speed = store('rrtSpeed');
		if (typeof speed === 'number') {
			if (this.speeds.indexOf(speed) !== -1) { this.audio.playbackRate = speed; }
		}
		this.showSpeed(false);

		this.playBtn.addEventListener('click', function () { self.toggle(); });
		el.querySelector('.rrt-back').addEventListener('click', function () { self.skip(-(data.back || 15), 'skip'); });
		el.querySelector('.rrt-fwd').addEventListener('click', function () { self.skip(data.fwd || 30, 'skip'); });
		this.speedBtn.addEventListener('click', function () { self.nextSpeed(); });
		if (this.followBtn) { this.followBtn.addEventListener('click', function () { self.setFollow(!self.following); }); }
		if (this.transcriptBtn) { this.transcriptBtn.addEventListener('click', function () { self.setList(!self.listOpen); }); }
		this.resumeBtn.addEventListener('click', function () {
			var t = parseFloat(self.resumeBtn.getAttribute('data-t')) || 0;
			self.seek(t, 'resume');
			self.resumeBtn.hidden = true;
			self.play();
		});

		this.bindTrack();

		var a = this.audio;
		a.addEventListener('loadedmetadata', function () { self.onMeta(); });
		a.addEventListener('timeupdate', function () { self.onTime(); });
		a.addEventListener('play', function () { self.pauseOthers(); el.classList.add('is-playing'); self.playBtn.setAttribute('aria-label', self.say('pause', 'Pause the audio reading')); self.emit('play'); self.mediaSession(); });
		a.addEventListener('pause', function () {
			el.classList.remove('is-playing');
			self.playBtn.setAttribute('aria-label', self.say('play', 'Play the audio reading'));
			if (!a.ended) { self.emit('pause'); }
			self.savePosition(true);
		});
		a.addEventListener('ended', function () { self.closeBucket(true); self.emit('ended'); store(self.resumeKey, null); });
		a.addEventListener('error', function () { self.emit('error', { code: a.error ? a.error.code : 0 }); });
		var dl = el.querySelector('.rrt-download');
		if (dl) { dl.addEventListener('click', function () { self.emit('download'); }); }
		a.addEventListener('ratechange', function () { self.showSpeed(true); self.positionState(); });
		a.addEventListener('volumechange', function () { self.emit('volume', { volume: a.volume, muted: a.muted }); });
		document.addEventListener('visibilitychange', function () {
			if (!a.paused) { self.emit(document.visibilityState === 'hidden' ? 'hidden' : 'visible'); }
		});
		window.addEventListener('pagehide', function () { self.savePosition(true); self.closeBucket(false); self.emit('leave', { playing: !a.paused }); });
		window.addEventListener('scroll', function () { self.userScrolledAt = Date.now(); }, { passive: true });

		/* The page view is announced before anything else, so listeners hear it first. */
		this.emit('view');
		if (a.readyState >= 1) { this.onMeta(); }
		if (this.startAt) {
			window.setTimeout(function () { el.scrollIntoView({ block: 'center' }); }, 300);
		}
	}

	Player.prototype.say = function (key, fallback) {
		return say.apply(null, [this.text].concat(Array.prototype.slice.call(arguments)));
	};

	Player.prototype.emit = function (type, extra) {
		var detail = { type: type, post: this.data.post || 0, t: Math.round((this.audio.currentTime || 0) * 10) / 10, rate: this.audio.playbackRate };
		if (extra) { Object.keys(extra).forEach(function (k) { detail[k] = extra[k]; }); }
		try { this.el.dispatchEvent(new CustomEvent('rrt:player', { detail: detail, bubbles: true })); } catch (e) {}
	};

	Player.prototype.play = function () {
		var p = this.audio.play();
		if (p) { if (p.catch) { p.catch(function () {}); } }
	};
	Player.prototype.toggle = function () {
		if (this.audio.paused) { this.play(); } else { this.audio.pause(); }
	};
	Player.prototype.seek = function (t, how) {
		var d = this.audio.duration;
		if (!isFinite(d)) { d = t; }
		var from = this.audio.currentTime;
		t = Math.max(0, Math.min(d, t));
		this.audio.currentTime = t;
		this.paint();
		/* The line or words at the new place are marked at once, even while paused (1.26.0). */
		if (this.following || !this.transcript.hidden) { this.follow(t); }
		this.emit('seek', { from: Math.round(from * 10) / 10, to: Math.round(t * 10) / 10, how: how || 'scrubber' });
	};
	Player.prototype.skip = function (by, how) { this.seek(this.audio.currentTime + by, how); };

	Player.prototype.nextSpeed = function () {
		var cur = this.audio.playbackRate;
		var i = this.speeds.indexOf(cur);
		var next = this.speeds[(i + 1) % this.speeds.length];
		var from = cur;
		this.audio.playbackRate = next;
		store('rrtSpeed', next);
		this.emit('rate', { from: from, to: next });
	};
	/* The speed button says its speed in words (1.26.0), so its name is what it shows; a change is read out. */
	Player.prototype.showSpeed = function (announce) {
		var r = this.audio.playbackRate;
		var label = this.say('speed', 'Speed %s', (Math.round(r * 100) / 100) + '×');
		this.speedBtn.textContent = label;
		this.speedBtn.removeAttribute('aria-label');
		if (announce) { this.announce(label); }
	};
	Player.prototype.announce = function (text) {
		if (!this.sayEl) { return; }
		var el = this.sayEl;
		el.textContent = '';
		window.setTimeout(function () { el.textContent = text; }, 60);
	};

	Player.prototype.bindTrack = function () {
		var self = this;
		var tr = this.track;
		var dragging = false;
		function at(clientX) {
			var r = tr.getBoundingClientRect();
			var d = self.audio.duration;
			if (!isFinite(d)) { return 0; }
			return Math.max(0, Math.min(1, (clientX - r.left) / r.width)) * d;
		}
		tr.addEventListener('pointerdown', function (e) {
			dragging = true;
			try { tr.setPointerCapture(e.pointerId); } catch (err) {}
			self.seek(at(e.clientX), 'scrubber');
		});
		tr.addEventListener('pointermove', function (e) {
			if (dragging) { self.audio.currentTime = at(e.clientX); self.paint(); }
		});
		tr.addEventListener('pointerup', function () { dragging = false; });
		tr.addEventListener('pointercancel', function () { dragging = false; });
		tr.addEventListener('keydown', function (e) {
			var k = e.key;
			var d = self.audio.duration;
			if (k === 'ArrowRight' || k === 'ArrowUp') { e.preventDefault(); self.skip(5, 'keys'); }
			else if (k === 'ArrowLeft' || k === 'ArrowDown') { e.preventDefault(); self.skip(-5, 'keys'); }
			else if (k === 'PageUp') { e.preventDefault(); self.skip(30, 'keys'); }
			else if (k === 'PageDown') { e.preventDefault(); self.skip(-30, 'keys'); }
			else if (k === 'Home') { e.preventDefault(); self.seek(0, 'keys'); }
			else if (k === 'End') { if (isFinite(d)) { e.preventDefault(); self.seek(d - 1, 'keys'); } }
			else if (k === ' ' || k === 'Enter') { e.preventDefault(); self.toggle(); }
		});
	};

	Player.prototype.onMeta = function () {
		var d = this.audio.duration;
		if (isFinite(d)) {
			if (!this.readySent) { this.readySent = true; this.emit('ready', { duration: Math.round(d * 10) / 10 }); }
			this.lenEl.textContent = fmt(d);
			this.track.setAttribute('aria-valuemax', String(Math.round(d)));
		}
		if (this.startAt) {
			this.audio.currentTime = Math.min(this.startAt, isFinite(d) ? d - 1 : this.startAt);
			this.emit('start-link', { at: this.startAt });
			this.startAt = 0;
		} else if (this.data.resume) {
			var r = store(this.resumeKey);
			if (r) {
				var fresh = (Date.now() - (r.at || 0)) < 60 * 864e5;
				if (fresh) {
					if (r.t > 15) {
						if (!isFinite(d) || r.t < d - Math.max(5, Math.min(20, d * 0.1))) {
							this.resumeBtn.textContent = this.say('resume', 'Resume at %s', fmt(r.t));
							this.resumeBtn.setAttribute('data-t', String(r.t));
							this.resumeBtn.hidden = false;
						}
					}
				}
			}
		}
		this.paint();
		this.positionState();
		this.placeTicks();
	};

	Player.prototype.onTime = function () {
		this.paint();
		var t = this.audio.currentTime;
		if (!this.audio.paused) {
			if (t > 3) { this.resumeBtn.hidden = true; }
			this.savePosition(false);
			var bucket = Math.floor(t / 5);
			if (bucket !== this.bucket) {
				this.closeBucket(false);
				this.bucket = bucket;
			}
			/* Count only time actually played: a jump shows up as a large step and is ignored. */
			var step = t - this.lastPos;
			if (step > 0) { if (step < 1.5 * Math.max(1, this.audio.playbackRate)) { this.heardIn += step; } }
		}
		if (this.following || !this.transcript.hidden) { this.follow(t); }
		if (this.chapters) { this.markChapter(t); }
		if (Math.abs(t - this.lastPos) > 4) { this.positionState(); }
		this.lastPos = t;
	};

	/*
	 * Progress is reported in 5 second buckets. A bucket counts as heard once
	 * at least 2 seconds of it were played (or half of a short last bucket), so
	 * skipping through does not count as listening. Each bucket is reported
	 * when playback leaves it, ends, or the page closes.
	 */
	Player.prototype.closeBucket = function (atEnd) {
		if (this.bucket === undefined) { return; }
		var need = 2;
		var d = this.audio.duration;
		if (isFinite(d)) { need = Math.min(2, Math.max(0.5, (d - this.bucket * 5) / 2)); }
		if (this.heardIn >= need) { this.emit('progress', { bucket: this.bucket, end: atEnd ? 1 : 0 }); }
		this.heardIn = 0;
		if (atEnd) { this.bucket = undefined; }
	};

	Player.prototype.paint = function () {
		var t = this.audio.currentTime || 0;
		var d = this.audio.duration;
		var f = isFinite(d) ? (d > 0 ? t / d : 0) : 0;
		this.fill.style.width = (f * 100).toFixed(2) + '%';
		this.nowEl.textContent = fmt(t);
		this.track.setAttribute('aria-valuenow', String(Math.round(t)));
		this.track.setAttribute('aria-valuetext', isFinite(d) ? this.say('of', '%1$s of %2$s', fmt(t), fmt(d)) : fmt(t));
	};

	Player.prototype.savePosition = function (force) {
		if (!this.data.resume) { return; }
		var now = Date.now();
		if (!force) { if (now - this.lastSave < 5000) { return; } }
		this.lastSave = now;
		var t = this.audio.currentTime;
		if (t > 15) { store(this.resumeKey, { t: Math.round(t), at: now }); }
	};

	/* Lock screen, notification and headphone controls. */
	/* 1.28.0: one player plays at a time; the lock screen follows the one playing. */
	Player.prototype.pauseOthers = function () {
		var mine = this.audio;
		Array.prototype.forEach.call(document.querySelectorAll('.rrt-player.is-ready'), function (o) {
			var p = o.rrtPlayer;
			if (p && p.audio !== mine && !p.audio.paused) { p.audio.pause(); }
		});
	};

	Player.prototype.mediaSession = function () {
		var ms = navigator.mediaSession;
		if (!ms) { return; }
		var self = this;
		var d = this.data;
		try {
			ms.metadata = new window.MediaMetadata({ title: d.title || document.title, artist: d.artist || '', album: d.album || '', artwork: d.artwork || [] });
		} catch (e) {}
		var handlers = {
			play: function () { self.play(); },
			pause: function () { self.audio.pause(); },
			seekbackward: function (ev) { self.skip(-((ev ? ev.seekOffset : 0) || d.back || 15), 'lockscreen'); },
			seekforward: function (ev) { self.skip((ev ? ev.seekOffset : 0) || d.fwd || 30, 'lockscreen'); },
			seekto: function (ev) {
				if (ev) {
					if (ev.fastSeek) { if ('fastSeek' in self.audio) { self.audio.fastSeek(ev.seekTime); return; } }
					self.seek(ev.seekTime, 'lockscreen');
				}
			}
		};
		Object.keys(handlers).forEach(function (k) {
			try { ms.setActionHandler(k, handlers[k]); } catch (e) {}
		});
		this.positionState();
	};
	Player.prototype.positionState = function () {
		var ms = navigator.mediaSession;
		if (!ms) { return; }
		if (!ms.setPositionState) { return; }
		var d = this.audio.duration;
		if (!isFinite(d)) { return; }
		if (d <= 0) { return; }
		try { ms.setPositionState({ duration: d, playbackRate: this.audio.playbackRate, position: Math.min(d, Math.max(0, this.audio.currentTime)) }); } catch (e) {}
	};

	/* Read along: the post text lights up as it is read. */
	Player.prototype.setFollow = function (on) {
		var self = this;
		this.following = on;
		this.followBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
		this.emit('follow', { on: on });
		if (!on) {
			if (this.root) { this.root.classList.remove('is-following'); }
			this.clearMarks();
			this.showList();
			return;
		}
		this.prepareFollow().then(function () {
			if (!self.following) { return; }
			if (self.words) { self.root.classList.add('is-following'); }
			self.showList();
		});
	};

	/* The Transcript button (1.26.0): the reading's lines, each a button that plays from its moment. */
	Player.prototype.setList = function (open) {
		var self = this;
		this.listOpen = open;
		this.emit(open ? 'transcript-open' : 'transcript-close');
		if (!open) { this.showList(); return; }
		this.loadCues().then(function () { self.showList(); });
	};
	/* The list shows when it was asked for, or when read along could not follow the post itself. */
	Player.prototype.showList = function () {
		var shown = this.listOpen || (this.following ? !this.words : false);
		if (this.cues) { if (!this.cues.length) { if (!this.cuesFailed) { shown = false; } } }
		this.transcript.hidden = !shown;
		if (this.transcriptBtn) { this.transcriptBtn.setAttribute('aria-expanded', shown ? 'true' : 'false'); }
		this.clearMarks();
		if (this.cues) { this.follow(this.audio.currentTime || 0); }
	};

	/* The captions, fetched once for read along and the Transcript button alike. */
	Player.prototype.loadCues = function () {
		var self = this;
		if (this.cuesReady) { return this.cuesReady; }
		this.cuesReady = window.fetch(this.data.captions, { credentials: 'same-origin' }).then(function (r) {
			if (!r.ok) { throw new Error('captions'); }
			return r.text();
		}).then(function (txt) {
			self.cues = parseVtt(txt);
			self.buildTranscript();
		}).catch(function () {
			self.cues = [];
			self.cuesFailed = true;
			if (self.followBtn) { self.followBtn.hidden = true; }
			self.transcript.innerHTML = '';
			var li = document.createElement('li');
			li.className = 'rrt-transcript-none';
			li.textContent = self.say('noText', 'The transcript could not be loaded.');
			self.transcript.appendChild(li);
		});
		return this.cuesReady;
	};
	Player.prototype.prepareFollow = function () {
		var self = this;
		if (this.ready) { return this.ready; }
		this.ready = this.loadCues().then(function () {
			if (self.cues.length) { self.alignToPost(); }
		});
		return this.ready;
	};

	Player.prototype.buildTranscript = function () {
		var self = this;
		this.transcript.innerHTML = '';
		this.cues.forEach(function (c, i) {
			var li = document.createElement('li');
			var b = document.createElement('button');
			b.type = 'button';
			b.className = 'rrt-line';
			var tm = document.createElement('span');
			tm.className = 'rrt-line-time';
			tm.textContent = fmt(c.s);
			tm.setAttribute('aria-hidden', 'true');
			b.appendChild(tm);
			b.appendChild(document.createTextNode(' ' + c.text));
			b.setAttribute('aria-label', self.say('playFrom', 'Play from %s', fmt(c.s)) + ': ' + c.text);
			b.addEventListener('click', function () { self.seek(c.s, 'transcript'); self.play(); });
			li.appendChild(b);
			c.li = li;
			c.btn = b;
			self.transcript.appendChild(li);
			c.n = i;
		});
	};

	/* Wrap each word of the post in a span and match the captions to them. */
	Player.prototype.alignToPost = function () {
		var self = this;
		var scope = this.el.closest('article') || document;
		var root = null;
		try { root = scope.querySelector(this.data.content || '.entry-content'); } catch (e) {}
		if (!root) { try { root = document.querySelector(this.data.content || '.entry-content'); } catch (e2) {} }
		if (!root) { return; }
		var skip = /^(SCRIPT|STYLE|NOSCRIPT|FIGURE|FIGCAPTION|BUTTON|AUDIO|VIDEO|IFRAME|SVG|SELECT|TEXTAREA|CODE|PRE)$/;
		var spans = [];
		var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
			acceptNode: function (n) {
				var p = n.parentNode;
				while (p) {
					if (p === root) { break; }
					if (skip.test(p.nodeName)) { return NodeFilter.FILTER_REJECT; }
					if (p.classList) { if (p.classList.contains('rrt-player')) { return NodeFilter.FILTER_REJECT; } }
					p = p.parentNode;
				}
				return /\S/.test(n.nodeValue) ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT;
			}
		});
		var nodes = [];
		while (walker.nextNode()) { nodes.push(walker.currentNode); }
		nodes.forEach(function (n) {
			var parts = n.nodeValue.split(/(\s+)/);
			var frag = document.createDocumentFragment();
			parts.forEach(function (part) {
				if (!part) { return; }
				if (/^\s+$/.test(part)) { frag.appendChild(document.createTextNode(part)); return; }
				var s = document.createElement('span');
				s.className = 'rrt-fw';
				s.textContent = part;
				s.rrtKey = clean(part);
				spans.push(s);
				frag.appendChild(s);
			});
			n.parentNode.replaceChild(frag, n);
		});
		var keyed = spans.filter(function (s) { return s.rrtKey !== ''; });

		/* Match the captions word by word to the post (0.19.1: see align()). */
		var flat = [];
		this.cues.forEach(function (c) {
			var ws = c.text.split(/\s+/).map(clean).filter(function (w) { return w !== ''; });
			c.spans = [];
			c.times = [];
			ws.forEach(function (w, i) { flat.push({ w: w, c: c, i: i, n: ws.length }); });
		});
		/* The teleprompter reads the title first; the title is not in the post text. */
		var titled = 0;
		if (this.cues.length) {
			if (clean(this.cues[0].text) !== '') {
				if (clean(this.cues[0].text) === clean(this.data.title || '')) { titled = this.cues[0].text.split(/\s+/).map(clean).filter(function (w) { return w !== ''; }).length; }
			}
		}
		flat = flat.slice(titled);
		var found = align(flat.map(function (f) { return f.w; }), keyed.map(function (s) { return s.rrtKey; }));
		var total = flat.length;
		var hit = 0;
		flat.forEach(function (f, j) {
			var k = found[j];
			if (k < 0) { return; }
			var c = f.c;
			c.spans.push(keyed[k]);
			c.times.push(c.s + (c.e - c.s) * (f.i / Math.max(1, f.n)));
			keyed[k].rrtCue = c;
			hit++;
		});
		var share = total ? hit / total : 0;
		this.matchShare = share;
		if (share < 0.6) {
			/* The post and the captions differ too much; use the transcript list instead. */
			this.emit('follow-fallback', { matched: Math.round(share * 100) });
			return;
		}
		root.classList.add('rrt-follow-root');
		root.addEventListener('click', function (e) {
			if (!self.following) { return; }
			var t = e.target;
			if (t.classList) {
				if (t.classList.contains('rrt-fw')) {
					if (t.rrtCue) {
						var idx = t.rrtCue.spans.indexOf(t);
						self.seek(idx >= 0 ? t.rrtCue.times[idx] : t.rrtCue.s, 'read-along');
						self.play();
					}
				}
			}
		});
		this.root = root;
		this.words = keyed;
	};

	Player.prototype.clearMarks = function () {
		if (this.marked) {
			this.marked.forEach(function (s) { s.classList.remove('rrt-now', 'rrt-cue', 'rrt-said'); });
		}
		this.marked = [];
		if (this.cues) {
			this.cues.forEach(function (c) {
				if (c.li) { c.li.classList.remove('rrt-on'); }
				if (c.btn) { c.btn.removeAttribute('aria-current'); }
			});
		}
		this.lastCue = -1;
		this.lastWord = null;
	};

	Player.prototype.cueAt = function (t) {
		var lo = 0;
		var hi = this.cues.length - 1;
		var found = -1;
		while (lo <= hi) {
			var mid = (lo + hi) >> 1;
			if (this.cues[mid].s <= t) { found = mid; lo = mid + 1; } else { hi = mid - 1; }
		}
		return found;
	};

	Player.prototype.follow = function (t) {
		if (!this.cues) { return; }
		if (!this.cues.length) { return; }
		var ci = this.cueAt(t);
		var self = this;
		var wordsOn = this.following ? !!this.words : false;
		if (ci !== this.lastCue) {
			this.clearMarks();
			this.lastCue = ci;
			if (ci >= 0) {
				var c = this.cues[ci];
				if (wordsOn) {
					var k;
					for (k = 0; k < ci; k++) {
						this.cues[k].spans.forEach(function (s) { s.classList.add('rrt-said'); self.marked.push(s); });
					}
					c.spans.forEach(function (s) { s.classList.add('rrt-cue'); self.marked.push(s); });
					if (c.spans.length) { this.keepInView(c.spans[0]); }
				}
				if (c.li ? !this.transcript.hidden : false) {
					c.li.classList.add('rrt-on');
					if (c.btn) { c.btn.setAttribute('aria-current', 'true'); }
					var box = this.transcript;
					/* Measured from the box itself, so the line is shown whole whatever the theme does to positioning. */
					var top = c.li.getBoundingClientRect().top - box.getBoundingClientRect().top + box.scrollTop;
					box.scrollTop = Math.max(0, top - Math.max(8, (box.clientHeight - c.li.offsetHeight) * 0.35));
				}
			}
		}
		if (wordsOn) {
			if (ci >= 0) {
				var cue = this.cues[ci];
				var w = null;
				var j;
				for (j = 0; j < cue.times.length; j++) {
					if (cue.times[j] <= t) { w = cue.spans[j]; }
				}
				if (w !== this.lastWord) {
					if (this.lastWord) { this.lastWord.classList.remove('rrt-now'); this.lastWord.classList.add('rrt-said'); }
					if (w) { w.classList.add('rrt-now'); }
					this.lastWord = w;
				}
			}
		}
	};

	/* Scroll gently to the line being read, unless the listener has just scrolled themselves. */
	Player.prototype.keepInView = function (span) {
		if (this.audio.paused) { return; }
		if (Date.now() - this.userScrolledAt < 4000) { return; }
		var r = span.getBoundingClientRect();
		var vh = window.innerHeight;
		if (r.top < vh * 0.15 || r.bottom > vh * 0.8) {
			var y = window.pageYOffset + r.top - vh * 0.35;
			var self = this;
			/* 1.26.0: a jump, never a glide, when the reader has asked for less motion. */
			var calm = false;
			try { calm = window.matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (e) {}
			window.scrollTo({ top: y, behavior: calm ? 'auto' : 'smooth' });
			window.setTimeout(function () { self.userScrolledAt = 0; }, 50);
		}
	};

	/*
	 * Chapters (0.7.0), read from the MP3's own tag so a post needs no extra
	 * file. Only the start of the file is fetched, and reading stops as soon
	 * as enough has arrived, even if the server ignores the byte range.
	 */
	function readStart(url, limit) {
		return window.fetch(url, { credentials: 'same-origin', headers: { Range: 'bytes=0-' + (limit - 1) } }).then(function (r) {
			if (!r.ok) { throw new Error('chapters'); }
			if (!r.body) { return r.arrayBuffer().then(function (ab) { return new Uint8Array(ab, 0, Math.min(limit, ab.byteLength)); }); }
			var reader = r.body.getReader();
			var parts = [];
			var got = 0;
			function pump() {
				return reader.read().then(function (step) {
					if (!step.done) {
						parts.push(step.value);
						got += step.value.length;
						if (got < limit) { return pump(); }
						reader.cancel();
					}
					var out = new Uint8Array(Math.min(got, limit));
					var at = 0;
					for (var i = 0; i < parts.length; i++) {
						if (at >= out.length) { break; }
						var piece = parts[i].subarray(0, out.length - at);
						out.set(piece, at);
						at += piece.length;
					}
					return out;
				});
			}
			return pump();
		});
	}
	Player.prototype.loadChapters = function () {
		var self = this;
		var src = this.audio.currentSrc || this.audio.getAttribute('src');
		if (!window.RRTId3 || !window.fetch || !src) { return; }
		readStart(src, 65536).then(function (b) {
			var info = window.RRTId3.read(b);
			var len = window.RRTId3.tagLength(b);
			/* Another tagger may have put the artwork first: then fetch the whole tag, if it is a sensible size. */
			if (!info.chapters.length) {
				if (len > b.length) {
					if (len < 4194304) { return readStart(src, len).then(window.RRTId3.read); }
				}
			}
			return info;
		}).then(function (info) {
			var ch = info.chapters.filter(function (c) { return c.end > c.start; });
			if (ch.length >= 2) { self.showChapters(ch); }
		}).catch(function () {});
	};
	Player.prototype.showChapters = function (ch) {
		var self = this;
		this.chapters = ch;
		var tools = this.el.querySelector('.rrt-tools');
		var btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'rrt-btn rrt-chapters-btn';
		btn.setAttribute('aria-expanded', 'false');
		btn.textContent = this.say('chapters', 'Chapters');
		var list = document.createElement('ol');
		list.className = 'rrt-chapters';
		list.hidden = true;
		list.id = 'rrt-chapters-' + (this.data.post || 0);
		btn.setAttribute('aria-controls', list.id);
		ch.forEach(function (c, i) {
			var li = document.createElement('li');
			var b = document.createElement('button');
			b.type = 'button';
			var tm = document.createElement('span');
			tm.className = 'rrt-chap-time';
			tm.textContent = fmt(c.start);
			b.appendChild(tm);
			b.appendChild(document.createTextNode(' ' + c.title));
			b.addEventListener('click', function () { self.seek(c.start, 'chapter'); self.play(); });
			li.appendChild(b);
			list.appendChild(li);
			c.li = li;
			c.btn = b;
			c.index = i;
		});
		btn.addEventListener('click', function () {
			list.hidden = !list.hidden;
			btn.setAttribute('aria-expanded', list.hidden ? 'false' : 'true');
			self.emit(list.hidden ? 'chapters-close' : 'chapters-open');
		});
		var before = this.el.querySelector('.rrt-resume');
		tools.insertBefore(btn, before);
		tools.parentNode.insertBefore(list, tools.nextSibling);
		this.chapterEl = document.createElement('span');
		this.chapterEl.className = 'rrt-chap-now';
		if (this.labelEl) { this.labelEl.appendChild(this.chapterEl); }
		this.placeTicks();
		this.markChapter(this.audio.currentTime || 0);
	};
	/* A small notch on the progress bar where each chapter after the first begins. */
	Player.prototype.placeTicks = function () {
		if (!this.chapters) { return; }
		var d = this.audio.duration;
		if (!isFinite(d)) { d = this.chapters[this.chapters.length - 1].end; }
		if (!(d > 0)) { return; }
		var old = this.track.querySelectorAll('.rrt-tick');
		Array.prototype.forEach.call(old, function (n) { n.parentNode.removeChild(n); });
		for (var i = 1; i < this.chapters.length; i++) {
			var tick = document.createElement('span');
			tick.className = 'rrt-tick';
			tick.style.left = (Math.min(1, this.chapters[i].start / d) * 100).toFixed(2) + '%';
			this.track.appendChild(tick);
		}
	};
	Player.prototype.markChapter = function (t) {
		var idx = 0;
		for (var i = 0; i < this.chapters.length; i++) { if (t >= this.chapters[i].start - 0.05) { idx = i; } }
		if (idx === this.chapterNow) { return; }
		if (this.chapterNow >= 0) { this.chapters[this.chapterNow].btn.removeAttribute('aria-current'); }
		this.chapterNow = idx;
		var c = this.chapters[idx];
		/* 1.26.0: on the button, where a screen reader reads it. */
		c.btn.setAttribute('aria-current', 'true');
		if (this.chapterEl) { this.chapterEl.textContent = idx > 0 ? c.title : ''; }
	};

	function init() {
		var els = document.querySelectorAll('.rrt-player:not(.is-ready)');
		Array.prototype.forEach.call(els, function (el) {
			try { el.rrtPlayer = new Player(el); } catch (e) { el.classList.remove('is-ready'); }
		});
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

	/*
	 * A button anywhere on the page with data-rrt-seek (a collection's list of
	 * readings, 1.19.0) plays the page's reading from that time.
	 */
	document.addEventListener('click', function (e) {
		var b = e.target && e.target.closest ? e.target.closest('[data-rrt-seek]') : null;
		if (!b) { return; }
		var el = document.querySelector('.rrt-player.is-ready');
		var p = el ? el.rrtPlayer : null;
		var t = parseFloat(b.getAttribute('data-rrt-seek'));
		if (!p || !isFinite(t)) { return; }
		e.preventDefault();
		p.seek(t, 'chapter');
		p.play();
	});
})();
