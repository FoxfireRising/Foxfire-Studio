/*
 * Foxfire Studio: the Readings in this collection box (1.19.0).
 *
 * Lists the collection's readings in order, adds them by search, moves and
 * removes them, and shows what the site will make of them: the length with
 * the gaps, the format, and any reading that needs matching. Each reading's
 * loudness is measured here, in the browser, the first time it is listed,
 * and kept on the site until its file changes.
 *
 * Match it in this browser decodes one reading, levels it to the others with
 * sound.js, encodes it once in the collection's format with the plugin's MP3
 * encoder (mp3.js in a worker) and sends the copy to the site, which joins
 * the collection again. The post's own reading is never touched.
 *
 * The pure helpers at the top are also used by the tests in Node.
 */
(function (root) {
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

	var C = {};

	/* How much louder the same voice measures in two channels than in one (BS.1770). */
	C.STEREO_LU = 3.01;

	/*
	 * ---------------------------------------------------------------------
	 * Pure helpers
	 * ---------------------------------------------------------------------
	 */

	/* Seconds as m:ss, or h:mm:ss from an hour. */
	C.clock = function (secs) {
		var s = Math.max(0, Math.round(Number(secs) || 0));
		var h = Math.floor(s / 3600);
		var m = Math.floor((s % 3600) / 60);
		var r = s % 60;
		return (h ? h + ':' + (m < 10 ? '0' : '') + m : String(m)) + ':' + (r < 10 ? '0' : '') + r;
	};

	/* A file size in words. */
	C.size = function (bytes) {
		var mb = (Number(bytes) || 0) / 1048576;
		/* translators: %s: megabytes. */
		return sprintf(__( '%s MB', 'foxfire-studio' ), mb >= 10 ? String(Math.round(mb)) : (Math.round(mb * 10) / 10).toFixed(1));
	};

	/* A REST address from the root WordPress gives, which may already hold a query (?rest_route=) when the site has no pretty links. */
	C.restUrl = function (rootUrl, path, query) {
		var url = String(rootUrl) + String(path);
		var q = [];
		Object.keys(query || {}).forEach(function (k) {
			q.push(encodeURIComponent(k) + '=' + encodeURIComponent(query[k]));
		});
		if (!q.length) { return url; }
		return url + (url.indexOf('?') === -1 ? '?' : '&') + q.join('&');
	};

	/* One channel from one or two: the two averaged. */
	C.monoMix = function (chs) {
		if (chs.length < 2) { return chs[0]; }
		var a = chs[0];
		var b = chs[1];
		var out = new Float32Array(a.length);
		for (var i = 0; i < a.length; i++) { out[i] = (a[i] + b[i]) / 2; }
		return out;
	};

	/*
	 * The loudness a matched copy's single channel is leveled to, so that the
	 * finished file, in the collection's channels, measures the plan's target.
	 * Null leaves the level as it is.
	 */
	C.monoTarget = function (target, ch) {
		if (target === null || target === undefined || !isFinite(target)) { return null; }
		return ch === 2 ? target - C.STEREO_LU : target;
	};

	/* The ids in the hidden field, and back. */
	C.parseIds = function (s) {
		var out = [];
		String(s || '').split(',').forEach(function (x) {
			var n = parseInt(x, 10);
			if (n > 0 && out.indexOf(n) === -1) { out.push(n); }
		});
		return out;
	};

	/* A list moved: the item at i to j. */
	C.move = function (list, i, j) {
		var out = list.slice();
		if (i < 0 || i >= out.length || j < 0 || j >= out.length) { return out; }
		var x = out.splice(i, 1)[0];
		out.splice(j, 0, x);
		return out;
	};

	/* The line under the list: how many readings, how long with the gaps, and the format. */
	C.summary = function (plan) {
		if (!plan || !plan.count) { return __( 'No readings yet. Search for a post with a reading to add it.', 'foxfire-studio' ); }
		/* translators: 1: number of readings, 2: length such as 29:40, 3: format such as "128 kbps mono". */
		return sprintf(_n( '%1$d reading, %2$s, made as %3$s.', '%1$d readings, %2$s with the gaps, made as %3$s.', plan.count, 'foxfire-studio' ), plan.count, C.clock(plan.secs), plan.label);
	};

	/* Whether two lists of ids are the same, in the same order. */
	C.same = function (a, b) {
		if (a.length !== b.length) { return false; }
		for (var i = 0; i < a.length; i++) { if (a[i] !== b[i]) { return false; } }
		return true;
	};

	/*
	 * ---------------------------------------------------------------------
	 * The box
	 * ---------------------------------------------------------------------
	 */

	function el(tag, cls, text) {
		var e = document.createElement(tag);
		if (cls) { e.className = cls; }
		if (text !== undefined) { e.textContent = text; }
		return e;
	}

	function Box(host, boot) {
		this.host = host;
		this.boot = boot;
		this.saved = boot.items.slice();
		this.items = boot.items.slice();
		this.plan = boot.plan;
		this.built = boot.built;
		this.field = document.getElementById('rrt-col-items');
		this.gapSel = document.getElementById('rrt-col-gap');
		this.measuring = {};
		this.busy = '';
		this.seq = 0;
		this.worker = null;
		this.listHost = el('div', 'rrt-col-list-host');
		this.addHost = el('div', 'rrt-col-add');
		this.infoHost = el('div', 'rrt-col-info');
		this.msg = el('p', 'rrt-col-msg');
		host.textContent = '';
		host.appendChild(this.listHost);
		host.appendChild(this.addHost);
		host.appendChild(this.infoHost);
		host.appendChild(this.msg);
		this.buildSearch();
		this.render();
		var self = this;
		if (this.gapSel) { this.gapSel.addEventListener('change', function () { self.replan(); }); }
		this.measureNext();
	}

	Box.prototype.api = function (method, path, body, query) {
		var opts = { method: method, credentials: 'same-origin', headers: { 'X-WP-Nonce': this.boot.nonce } };
		if (body instanceof FormData) {
			opts.body = body;
		} else if (body) {
			opts.headers['Content-Type'] = 'application/json';
			opts.body = JSON.stringify(body);
		}
		return fetch(C.restUrl(this.boot.rest, path, query), opts).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (j) {
				if (!r.ok) { throw new Error((j && j.message) || sprintf( /* translators: %s: HTTP status. */ __( 'The site answered %s.', 'foxfire-studio' ), r.status)); }
				return j;
			});
		});
	};

	Box.prototype.gap = function () {
		return this.gapSel ? parseFloat(this.gapSel.value) || 0 : 2;
	};

	Box.prototype.setItems = function (ids) {
		this.items = ids;
		if (this.field) { this.field.value = ids.join(','); }
		this.replan();
	};

	Box.prototype.replan = function () {
		var self = this;
		var n = ++this.seq;
		this.render();
		return this.api('POST', 'collections/plan', { col: this.boot.col, ids: this.items, gap: this.gap() }).then(function (plan) {
			if (n !== self.seq) { return; }
			self.plan = plan;
			self.render();
			self.measureNext();
		}).catch(function (e) { self.say(e.message, true); });
	};

	Box.prototype.say = function (msg, bad) {
		if (!this.msg) { return; }
		this.msg.textContent = msg || '';
		this.msg.className = 'rrt-col-msg' + (bad ? ' is-bad' : '');
	};

	Box.prototype.row = function (id) {
		var rows = (this.plan && this.plan.rows) || [];
		for (var i = 0; i < rows.length; i++) { if (rows[i].id === id) { return rows[i]; } }
		return null;
	};

	Box.prototype.render = function () {
		var self = this;
		var h = this.listHost;
		h.textContent = '';
		var list = el('ol', 'rrt-col-rows');
		this.items.forEach(function (id, i) {
			var r = self.row(id) || { id: id, title: '...', secs: 0, kind: '', note: '' };
			var li = el('li', 'rrt-col-row' + (r.kind ? ' is-' + r.kind : ''));
			li.setAttribute('data-id', String(id));
			var top = el('div', 'rrt-col-top');
			top.appendChild(el('span', 'rrt-col-grip', '≡'));
			var t = el('span', 'rrt-col-name');
			if (r.edit) {
				var a = el('a', '', r.title);
				a.href = r.edit;
				t.appendChild(a);
			} else {
				t.textContent = r.title;
			}
			top.appendChild(t);
			var facts = [];
			if (r.secs) { facts.push(C.clock(r.secs)); }
			if (r.fmt) { facts.push(r.fmt); }
			if (r.lufs !== null && r.lufs !== undefined) {
				/* translators: %s: loudness in LUFS. */
				facts.push(sprintf(__( '%s LUFS', 'foxfire-studio' ), (Math.round(r.lufs * 10) / 10).toFixed(1)));
			} else if (self.measuring[id]) {
				facts.push(__( 'measuring...', 'foxfire-studio' ));
			}
			top.appendChild(el('span', 'rrt-col-facts', facts.join(' · ')));
			var tools = el('span', 'rrt-col-tools');
			var up = el('button', 'button-link', '↑');
			up.type = 'button';
			up.setAttribute('aria-label', __( 'Move up', 'foxfire-studio' ));
			up.disabled = i === 0;
			up.addEventListener('click', function () { self.setItems(C.move(self.items, i, i - 1)); });
			var down = el('button', 'button-link', '↓');
			down.type = 'button';
			down.setAttribute('aria-label', __( 'Move down', 'foxfire-studio' ));
			down.disabled = i === self.items.length - 1;
			down.addEventListener('click', function () { self.setItems(C.move(self.items, i, i + 1)); });
			var rm = el('button', 'button-link rrt-col-remove', __( 'Remove', 'foxfire-studio' ));
			rm.type = 'button';
			rm.addEventListener('click', function () { self.setItems(self.items.filter(function (x) { return x !== id; })); });
			tools.appendChild(up);
			tools.appendChild(down);
			tools.appendChild(rm);
			top.appendChild(tools);
			li.appendChild(top);
			if (r.note) { li.appendChild(el('p', 'rrt-col-note', r.note)); }
			if ((r.kind === 'format' || r.kind === 'level') && !r.matched) {
				var mb = el('button', 'button rrt-col-match', __( 'Match it in this browser', 'foxfire-studio' ));
				mb.type = 'button';
				var unsaved = !C.same(self.items, self.saved) || self.saved.indexOf(id) === -1;
				mb.disabled = !!self.busy || unsaved;
				mb.addEventListener('click', function () { self.match(r); });
				var p = el('p', 'rrt-col-matchline');
				p.appendChild(mb);
				p.appendChild(el('span', 'rrt-small', unsaved
					? ' ' + __( 'Save the collection first.', 'foxfire-studio' )
					: ' ' + __( 'Makes a copy of this one reading for the collection, leveled and in the collection\'s format. It goes through MP3 once more; the others stay exact copies.', 'foxfire-studio' )));
				li.appendChild(p);
			}
			list.appendChild(li);
		});
		h.appendChild(list);
		if (root.jQuery && root.jQuery.fn && root.jQuery.fn.sortable) {
			root.jQuery(list).sortable({
				handle: '.rrt-col-grip',
				axis: 'y',
				update: function () {
					var ids = [];
					Array.prototype.forEach.call(list.children, function (li) { ids.push(parseInt(li.getAttribute('data-id'), 10)); });
					self.setItems(ids);
				}
			});
		}

		h = this.infoHost;
		h.textContent = '';
		h.appendChild(el('p', 'rrt-col-summary', C.summary(this.plan)));
		if (!C.same(this.items, this.saved)) {
			h.appendChild(el('p', 'rrt-col-unsaved', __( 'Save the collection to join these readings.', 'foxfire-studio' )));
		}

		/* The last join. */
		var b = this.built;
		var made = el('div', 'rrt-col-built');
		if (b && b.url) {
			/* translators: 1: number of readings, 2: length, 3: file size, 4: date and time. */
			made.appendChild(el('p', '', sprintf(_n( 'Joined %1$d reading, %2$s, %3$s, made %4$s.', 'Joined %1$d readings, %2$s, %3$s, made %4$s.', b.count, 'foxfire-studio' ), b.count, C.clock(b.secs), C.size(b.bytes), b.at)));
			var au = el('audio', 'rrt-col-audio');
			au.controls = true;
			au.preload = 'metadata';
			au.src = b.url;
			made.appendChild(au);
			if (b.chapters) {
				/* translators: %d: number of chapters. */
				made.appendChild(el('p', 'rrt-small', sprintf(_n( '%d chapter in the MP3.', '%d chapters in the MP3.', b.chapters, 'foxfire-studio' ), b.chapters)));
			}
		}
		if (b && b.error) { made.appendChild(el('p', 'rrt-col-error', b.error)); }
		if (b && b.url) {
			var again = el('button', 'button', __( 'Join again now', 'foxfire-studio' ));
			again.type = 'button';
			again.disabled = !!this.busy || !C.same(this.items, this.saved);
			again.addEventListener('click', function () { self.rebuild(); });
			made.appendChild(again);
		}
		h.appendChild(made);
		if (this.busy) { this.say(this.busy); }
	};

	/* The search field, made once so typing is never lost when the list redraws. */
	Box.prototype.buildSearch = function () {
		var self = this;
		var add = this.addHost;
		var lab = el('label', '', __( 'Add a reading', 'foxfire-studio' ));
		lab.setAttribute('for', 'rrt-col-search');
		var inp = el('input', 'regular-text');
		inp.type = 'search';
		inp.id = 'rrt-col-search';
		inp.placeholder = __( 'Search posts with a reading', 'foxfire-studio' );
		var res = el('ul', 'rrt-col-results');
		add.appendChild(lab);
		add.appendChild(document.createTextNode(' '));
		add.appendChild(inp);
		add.appendChild(res);
		var timer = 0;
		var asked = 0;
		var search = function () {
			var n = ++asked;
			self.api('GET', 'collections/readings', null, { search: inp.value, exclude: self.items.join(',') }).then(function (j) {
				if (n !== asked) { return; }
				res.textContent = '';
				(j.readings || []).forEach(function (x) {
					var li = el('li');
					var b = el('button', 'button-link', x.title);
					b.type = 'button';
					b.addEventListener('click', function () {
						if (self.items.indexOf(x.id) === -1) { self.setItems(self.items.concat([x.id])); }
						res.textContent = '';
						inp.value = '';
						inp.focus();
					});
					li.appendChild(b);
					var more = [];
					if (x.secs) { more.push(C.clock(x.secs)); }
					if (x.status) { more.push(x.status); }
					if (more.length) { li.appendChild(el('span', 'rrt-small', ' ' + more.join(' \u00b7 '))); }
					res.appendChild(li);
				});
				if (!(j.readings || []).length) { res.appendChild(el('li', 'rrt-small', __( 'No posts with a reading match that.', 'foxfire-studio' ))); }
			}).catch(function (e) { self.say(e.message, true); });
		};
		inp.addEventListener('input', function () { window.clearTimeout(timer); timer = window.setTimeout(search, 300); });
		inp.addEventListener('focus', function () { if (!res.children.length) { search(); } });
		inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); window.clearTimeout(timer); search(); } });
	};

	Box.prototype.rebuild = function () {
		var self = this;
		this.busy = __( 'Joining...', 'foxfire-studio' );
		this.render();
		return this.api('POST', 'collections/' + this.boot.col + '/build', {}).then(function (j) {
			self.busy = '';
			self.built = j.built;
			self.plan = j.plan;
			self.render();
			self.say(j.ok ? __( 'Joined.', 'foxfire-studio' ) : j.error, !j.ok);
		}).catch(function (e) { self.busy = ''; self.render(); self.say(e.message, true); });
	};

	/* The audio of a reading, decoded at a rate (resampled by the browser if it differs). */
	function decode(url, rate) {
		return fetch(url, { credentials: 'same-origin' }).then(function (r) {
			if (!r.ok) { throw new Error(sprintf( /* translators: %s: HTTP status. */ __( 'The reading could not be fetched (HTTP %s).', 'foxfire-studio' ), r.status)); }
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

	/* Measures the next reading whose loudness the site does not know yet, one at a time. */
	Box.prototype.measureNext = function () {
		var self = this;
		if (this.measuringNow || !root.RRTSound) { return; }
		var rows = (this.plan && this.plan.rows) || [];
		var next = null;
		for (var i = 0; i < rows.length; i++) {
			if (rows[i].measure && !this.measuring[rows[i].id]) { next = rows[i]; break; }
		}
		if (!next) { return; }
		this.measuringNow = true;
		this.measuring[next.id] = true;
		this.render();
		decode(next.url, 44100).then(function (a) {
			var l = root.RRTSound.lufsMulti(a.chs, a.rate);
			if (!isFinite(l)) { throw new Error(__( 'This reading is silent.', 'foxfire-studio' )); }
			return self.api('POST', 'collections/level', { post: next.id, key: next.key, lufs: Math.round(l * 100) / 100 });
		}).then(function () {
			self.measuringNow = false;
			return self.replan();
		}).catch(function (e) {
			self.measuringNow = false;
			/* translators: 1: the reading's title, 2: what went wrong. */
			self.say(sprintf(__( '%1$s could not be measured: %2$s', 'foxfire-studio' ), next.title, e.message), true);
			self.measureNext();
		});
	};

	/* The MP3 encoder in a worker: this site's copy first, then the fallback. */
	Box.prototype.encode = function (pcm, fmt, onProgress) {
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

	Box.prototype.match = function (r) {
		var self = this;
		var plan = this.plan;
		var fmt = plan.fmt;
		/* translators: %s: the reading's title. */
		this.busy = sprintf(__( 'Matching %s: decoding...', 'foxfire-studio' ), r.title);
		this.render();
		var lufs = null;
		decode(r.url, fmt.rate).then(function (a) {
			var x = C.monoMix(a.chs);
			var want = C.monoTarget(plan.target, fmt.ch);
			if (want !== null && root.RRTSound) {
				var y = root.RRTSound.toLoudness(x, a.rate, want, plan.tp, 30);
				x = y.data;
				lufs = isFinite(y.lufs) ? y.lufs + (fmt.ch === 2 ? C.STEREO_LU : 0) : null;
			}
			/* translators: %s: the reading's title. */
			self.busy = sprintf(__( 'Matching %s: encoding...', 'foxfire-studio' ), r.title);
			self.say(self.busy);
			return self.encode(x, fmt, function (p) {
				/* translators: 1: the reading's title, 2: percent done. */
				self.say(sprintf(__( 'Matching %1$s: encoding, %2$d%%', 'foxfire-studio' ), r.title, Math.round(p * 100)));
			});
		}).then(function (mp3) {
			self.say(__( 'Sending the copy to the site and joining...', 'foxfire-studio' ));
			var fd = new FormData();
			fd.append('mp3', new Blob([mp3], { type: 'audio/mpeg' }), 'matched.mp3');
			fd.append('post', String(r.id));
			fd.append('key', r.key);
			if (lufs !== null) { fd.append('lufs', String(Math.round(lufs * 100) / 100)); }
			return self.api('POST', 'collections/' + self.boot.col + '/match', fd);
		}).then(function (j) {
			self.busy = '';
			self.built = j.built;
			self.plan = j.plan;
			self.render();
			/* translators: %s: the reading's title. */
			self.say(j.ok ? sprintf(__( '%s is matched, and the collection is joined again.', 'foxfire-studio' ), r.title) : j.error, !j.ok);
		}).catch(function (e) {
			self.busy = '';
			self.render();
			/* translators: 1: the reading's title, 2: what went wrong. */
			self.say(sprintf(__( '%1$s could not be matched: %2$s', 'foxfire-studio' ), r.title, e.message), true);
		});
	};

	C.Box = Box;
	root.RRTCollections = C;
	if (typeof module !== 'undefined' && module.exports) { module.exports = C; }

	function start() {
		var host = document.querySelector('[data-rrt-col]');
		var bootEl = document.getElementById('rrt-col-boot');
		if (!host || !bootEl) { return; }
		var boot;
		try { boot = JSON.parse(bootEl.textContent); } catch (e) { return; }
		host.rrtBox = new Box(host, boot);
	}
	if (typeof document !== 'undefined' && document.addEventListener) {
		if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
	}
})(typeof window !== 'undefined' ? window : this);
