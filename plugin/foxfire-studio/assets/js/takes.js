/*
 * Foxfire Studio: one reading from several takes (1.3.0). The take shelf
 * (every reviewed take of a post, kept in this browser with its Whisper
 * words), the Combine takes screen in the review studio, and building one
 * recording from the reader's choices. The building itself is in combine.js.
 * Shares window.RRTStudio with the studio's other files (made in
 * studio-core.js; docs/ARCHITECTURE.md maps them).
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
	var track = studio.track;
	var W = studio.W;
	var POST_ID = studio.POST_ID;
	var LOW_MEM = studio.LOW_MEM;
	var fmt = studio.fmt;
	var rv = studio.rv;
	var el = studio.el;
	var C = window.RRTCombine;
	var E = window.RRTEdit;
	studio.shelfAdd = function () { return Promise.resolve(''); };
	studio.shelfCount = function () { return Promise.resolve(0); };
	if (!track) { return; }
	if (!C || !E || !POST_ID) { return; }

	var KEEP = 6; /* Takes kept a post. */
	var DAYS = 30; /* How long a take is kept. */
	var RATE = 44100;

	/* The script as the review knows it, and a short signature of its words, so takes of an older version of the post are told apart. */
	var SCRIPT = W.map(function (w) { return { key: w.key, block: w.block, sent: w.sent }; });
	var SIG = (function () {
		var h = 5381;
		var s = W.map(function (w) { return w.key; }).join(' ');
		for (var i = 0; i < s.length; i++) { h = ((h * 33) ^ s.charCodeAt(i)) >>> 0; }
		return h.toString(16) + '-' + W.length;
	})();

	/*
	 * The take shelf: IndexedDB 'rrt-shelf', separate from the unfinished
	 * takes. takes holds each take's details, words and flags; audio its
	 * recording as 16-bit samples; plans each post's choices.
	 */
	var shelf = (function () {
		var dbp = null;
		function db() {
			if (!dbp) {
				dbp = new Promise(function (resolve, reject) {
					if (!window.indexedDB) { reject(new Error('no storage')); return; }
					var r;
					try { r = window.indexedDB.open('rrt-shelf', 1); } catch (e) { reject(e); return; }
					r.onupgradeneeded = function () {
						var d = r.result;
						d.createObjectStore('takes', { keyPath: 'id' }).createIndex('post', 'post');
						d.createObjectStore('audio', { keyPath: 'id' });
						d.createObjectStore('plans', { keyPath: 'post' });
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
			list: function () {
				return run(['takes'], 'readonly', function (tx, out) {
					var q = tx.objectStore('takes').index('post').getAll(POST_ID);
					q.onsuccess = function () { out.value = (q.result || []).sort(function (a, b) { return a.at - b.at; }); };
				});
			},
			put: function (meta, pcm) {
				return run(['takes', 'audio'], 'readwrite', function (tx) {
					tx.objectStore('audio').put({ id: meta.id, data: pcm.buffer });
					tx.objectStore('takes').put(meta);
				});
			},
			update: function (meta) {
				return run(['takes'], 'readwrite', function (tx) { tx.objectStore('takes').put(meta); });
			},
			putAudio: function (id, pcm) {
				return run(['audio'], 'readwrite', function (tx) { tx.objectStore('audio').put({ id: id, data: pcm.buffer }); });
			},
			audio: function (id) {
				return run(['audio'], 'readonly', function (tx, out) {
					var q = tx.objectStore('audio').get(id);
					q.onsuccess = function () { out.value = q.result ? new Int16Array(q.result.data) : null; };
				});
			},
			drop: function (ids) {
				return run(['takes', 'audio'], 'readwrite', function (tx) {
					ids.forEach(function (id) { tx.objectStore('takes')['delete'](id); tx.objectStore('audio')['delete'](id); });
				});
			},
			plan: function () {
				return run(['plans'], 'readonly', function (tx, out) {
					var q = tx.objectStore('plans').get(POST_ID);
					q.onsuccess = function () { out.value = q.result ? q.result.plan : null; };
				});
			},
			/* The plan with when it was saved (1.34.0), so the later of this browser's and the site's wins. */
			planRow: function () {
				return run(['plans'], 'readonly', function (tx, out) {
					var q = tx.objectStore('plans').get(POST_ID);
					q.onsuccess = function () { out.value = q.result || null; };
				});
			},
			savePlan: function (plan, at) {
				return run(['plans'], 'readwrite', function (tx) { tx.objectStore('plans').put({ post: POST_ID, plan: plan, at: at || Date.now() }); });
			},
			dropPlan: function () {
				return run(['plans'], 'readwrite', function (tx) { tx.objectStore('plans')['delete'](POST_ID); });
			}
		};
	})();

	/* Takes past DAYS, and any beyond the newest KEEP, leave the shelf. */
	function prune(list) {
		var old = Date.now() - DAYS * 86400000;
		var keep = list.filter(function (t) { return t.at >= old; });
		while (keep.length > KEEP) { keep.shift(); }
		var gone = list.filter(function (t) { return keep.indexOf(t) < 0; }).map(function (t) { return t.id; });
		return (gone.length ? shelf.drop(gone) : Promise.resolve()).then(function () { return keep; });
	}
	function listTakes() {
		return shelf.list().then(function (list) {
			/* 1.34.0: a take known only from the site, with the site's shelf switched off since, cannot be heard here, so it goes. */
			if (SITE) { return list; }
			var gone = list.filter(function (t) { return t.remote; }).map(function (t) { return t.id; });
			return (gone.length ? shelf.drop(gone) : Promise.resolve()).then(function () { return list.filter(function (t) { return !t.remote; }); });
		}).then(prune).catch(function () { return []; });
	}
	function usable(t) { return t.sig === SIG; }

	/*
	 * The take shelf across computers (1.34.0). With Settings, Take shelf on,
	 * the site keeps each person's shelf too (includes/shelf.php). Each take
	 * goes up after its review as FLAC (flac.js, in a worker), in pieces; the
	 * list (words and flags) comes from the site when the studio opens a
	 * post, so Continue and the Combine takes count are right on any
	 * computer; a take known only from the site ('remote') has its recording
	 * fetched when Combine takes needs it. The site numbers the takes. A
	 * phone sends its short takes but never fetches recordings. Local takes
	 * carry site: '' (not sent), 'up' (being sent), 'ready' or 'full'.
	 */
	var SITE = (studio.PRACTICE || !studio.RRT) ? null : (studio.RRT.shelfSite || null);
	var siteEl = document.getElementById('tpShelfSite');
	var site = { worker: null, calls: {}, seq: 0, queue: [], running: false, synced: false, planTimer: 0, room: true };
	studio.shelfSite = site;
	function siteUrl(path) {
		var q = path.indexOf('?');
		return SITE.rest + (q < 0 ? path : path.slice(0, q) + (SITE.rest.indexOf('?') < 0 ? '?' : '&') + path.slice(q + 1));
	}
	function siteApi(method, path, body, type) {
		var h = { 'X-WP-Nonce': SITE.nonce };
		if (type) { h['Content-Type'] = type; }
		return fetch(siteUrl(path), { method: method, credentials: 'same-origin', headers: h, body: body }).then(function (r) {
			return r.json()['catch'](function () { return {}; }).then(function (j) { return { status: r.status, ok: r.ok, j: j || {} }; });
		});
	}
	function siteLine(text) {
		if (!siteEl) { return; }
		siteEl.textContent = text || '';
		siteEl.hidden = !text;
	}
	/* The FLAC worker, made once; each call answered by its id. */
	function flacRun(msg, transfer) {
		return new Promise(function (resolve, reject) {
			if (!site.worker) {
				try { site.worker = new Worker(SITE.worker); } catch (e) { reject(e); return; }
				site.worker.onmessage = function (e) {
					var d = e.data || {};
					var c = site.calls[d.id];
					if (!c) { return; }
					delete site.calls[d.id];
					if (d.type === 'done') { c.resolve(d); } else { c.reject(new Error(d.message || 'FLAC')); }
				};
				site.worker.onerror = function () {
					Object.keys(site.calls).forEach(function (k) { site.calls[k].reject(new Error('FLAC')); });
					site.calls = {};
					site.worker = null;
				};
			}
			msg.id = ++site.seq;
			site.calls[msg.id] = { resolve: resolve, reject: reject };
			site.worker.postMessage(msg, transfer || []);
		});
	}
	/* What the site keeps of a take: everything Combine takes and Continue read, without the recording. */
	function siteFields(t) {
		return { id: t.id, at: t.at, seconds: t.seconds, rate: t.rate, sig: t.sig, fp: t.fp, level: t.level, words: t.words, problems: t.problems, chunks: t.chunks, accuracy: t.accuracy, wpm: t.wpm, mode: t.mode || '' };
	}
	function siteQueue(id) {
		if (!SITE || site.queue.indexOf(id) >= 0) { return; }
		site.queue.push(id);
		siteRun();
	}
	function wait(ms) { return new Promise(function (res) { window.setTimeout(res, ms); }); }
	/* Sends the queued takes one at a time; a take that cannot go now waits for the next time the post is opened. */
	function siteRun() {
		if (site.running || !site.queue.length) { return; }
		site.running = true;
		var id = site.queue.shift();
		siteSend(id).catch(function () { return null; }).then(function () {
			site.running = false;
			siteRun();
		});
	}
	function siteSend(id) {
		var t = null;
		var flac = null;
		var name = '';
		return shelf.list().then(function (all) {
			t = all.filter(function (x) { return x.id === id; })[0] || null;
			if (!t || t.remote || t.site === 'ready') { return null; }
			if (t.site === 'full' ? !site.room : false) { return null; }
			return shelf.audio(id).then(function (pcm) {
				if (!pcm) { return null; }
				name = sprintf( /* translators: %d: the take's number on the shelf. */ __( 'Take %d', 'foxfire-studio' ), t.n );
				siteLine(sprintf( /* translators: %s: the take's name, such as Take 2. */ __( 'Sending %s to the site...', 'foxfire-studio' ), name ));
				var copy = new Int16Array(pcm);
				return flacRun({ cmd: 'encode', pcm: copy.buffer, rate: t.rate }, [copy.buffer]).then(function (d) {
					flac = new Uint8Array(d.flac);
					t.sum = d.sum;
					return siteApi('POST', 'shelf/take?post=' + POST_ID, JSON.stringify({ take: siteFields(t), bytes: flac.length, sum: d.sum }), 'application/json');
				});
			});
		}).then(function (r) {
			if (!r) { return null; }
			if (r.status === 507) {
				t.site = 'full';
				site.room = false;
				siteLine(sprintf( /* translators: %s: the take's name, such as Take 2. */ __( '%s is kept in this browser only: the site’s space for take shelves is full.', 'foxfire-studio' ), name ));
				return shelf.update(t).then(function () { return null; });
			}
			if (!r.ok || !r.j.take) { throw new Error('start'); }
			/* The site's number, so Take 3 is Take 3 everywhere. */
			t.n = r.j.take.n;
			t.site = 'up';
			name = sprintf( /* translators: %d: the take's number on the shelf. */ __( 'Take %d', 'foxfire-studio' ), t.n );
			return shelf.update(t).then(function () { return sendPieces(t, flac, r.j.take.have || 0, name); });
		}).then(function (done) {
			if (!done) { return null; }
			return siteApi('POST', 'shelf/take/' + t.id + '/done', '').then(function (r) {
				if (!r.ok) { throw new Error('done'); }
				t.site = 'ready';
				siteLine(sprintf( /* translators: %s: the take's name, such as Take 2. */ __( '%s is on the site’s shelf too, for your other computers.', 'foxfire-studio' ), name ));
				return shelf.update(t);
			});
		}).then(function () { return listTakes().then(showButton); }, function (err) {
			if (t && name) { siteLine(sprintf( /* translators: %s: the take's name, such as Take 2. */ __( '%s could not reach the site just now. It stays in this browser and goes up the next time you open this post here.', 'foxfire-studio' ), name )); }
			throw err;
		});
	}
	/* The recording in pieces from what the site holds; a refused piece says how much is there, and the send carries on from it. */
	function sendPieces(t, flac, have, name) {
		var size = Math.max(262144, SITE.piece || 4194304);
		var tries = 0;
		function next(at) {
			if (at >= flac.length) { return Promise.resolve(true); }
			var piece = flac.subarray(at, Math.min(flac.length, at + size));
			siteLine(sprintf( /* translators: 1: the take's name, such as Take 2, 2: how much has gone, as a percentage. */ __( 'Sending %1$s to the site: %2$d%%', 'foxfire-studio' ), name, Math.floor(100 * at / flac.length) ));
			return siteApi('POST', 'shelf/take/' + t.id + '/piece?offset=' + at, new Blob([piece]), 'application/octet-stream').then(function (r) {
				if (r.ok || r.status === 409) { tries = 0; return next(typeof r.j.have === 'number' ? r.j.have : at + piece.length); }
				if (r.status === 507) { throw new Error('full'); }
				throw new Error('piece');
			}, function (err) { throw err; }).catch(function (err) {
				if (++tries > 3) { throw err; }
				return wait(1500 * tries).then(function () { return next(at); });
			});
		}
		return next(have);
	}
	/* Fetches a take known only from the site, checks every sample against its check, and keeps it in this browser. */
	function siteFetch(t) {
		return fetch(siteUrl('shelf/take/' + t.id + '/audio'), { credentials: 'same-origin', headers: { 'X-WP-Nonce': SITE.nonce } }).then(function (r) {
			if (!r.ok) { throw new Error('fetch'); }
			return r.arrayBuffer();
		}).then(function (buf) {
			return flacRun({ cmd: 'decode', flac: buf }, [buf]);
		}).then(function (d) {
			if (t.sum ? d.sum !== t.sum : false) { throw new Error('damaged'); }
			var pcm = new Int16Array(d.pcm);
			t.remote = false;
			t.bytes = pcm.length * 2;
			return shelf.putAudio(t.id, pcm).then(function () { return shelf.update(t); }).then(function () { return pcm; });
		});
	}
	/* A take as the site lists it, for this browser's shelf (its recording stays on the site until needed). */
	function fromSite(st) {
		return {
			id: st.id, post: POST_ID, n: st.n, at: st.at, sig: st.sig || '',
			seconds: st.seconds, rate: st.rate || RATE, bytes: Math.round(st.seconds * (st.rate || RATE) * 2),
			fp: st.fp, level: st.level, words: st.words || [], problems: st.problems || [], chunks: st.chunks || [],
			accuracy: st.accuracy, wpm: st.wpm, mode: st.mode || '', sum: st.sum, site: 'ready', remote: true
		};
	}
	/*
	 * Brings this browser's shelf and the site's together: takes from other
	 * computers are listed here, takes removed elsewhere (or past their 30
	 * days there) leave here, the site's numbers are taken, takes not yet on
	 * the site are sent, and the later of the two plans is kept.
	 */
	function siteSync() {
		if (!SITE) { return Promise.resolve(); }
		var got = null;
		return siteApi('GET', 'shelf?post=' + POST_ID).then(function (r) {
			if (!r.ok || !r.j || !r.j.takes) { return null; }
			got = r.j;
			site.room = got.room !== false;
			return shelf.list();
		}).then(function (local) {
			if (!got) { return null; }
			var byId = {};
			local.forEach(function (t) { byId[t.id] = t; });
			var ready = {};
			var work = [];
			var resume = [];
			got.takes.forEach(function (st) {
				if (st.state !== 'ready') {
					if (byId[st.id]) { resume.push(st.id); }
					return;
				}
				ready[st.id] = true;
				var mine = byId[st.id];
				if (mine) {
					if (mine.n !== st.n || mine.site !== 'ready' || mine.sum !== st.sum) {
						mine.n = st.n;
						mine.site = 'ready';
						mine.sum = st.sum;
						work.push(shelf.update(mine));
					}
					return;
				}
				/* The same take reviewed in this browser too: keep the site's, with this browser's recording, so nothing is fetched. */
				var twin = local.filter(function (t) { return !t.remote ? (!ready[t.id] ? C.sameTake(t.fp, st.fp) : false) : false; })[0];
				var meta = fromSite(st);
				if (twin) {
					twin.dup = true;
					work.push(shelf.audio(twin.id).then(function (pcm) {
						if (!pcm) { return shelf.update(meta); }
						meta.remote = false;
						return shelf.put(meta, pcm).then(function () { return shelf.drop([twin.id]); });
					}));
					return;
				}
				work.push(shelf.update(meta));
			});
			local.forEach(function (t) {
				if (t.dup) { return; }
				/* On the site before, and gone from it now: removed on another computer, or past its 30 days. */
				if ((t.site === 'ready' || t.remote) ? !ready[t.id] : false) { work.push(shelf.drop([t.id])); return; }
				if (!t.remote ? t.site !== 'ready' : false) {
					if (resume.indexOf(t.id) < 0) { resume.push(t.id); }
				}
			});
			return Promise.all(work).then(function () { return shelf.planRow(); }).then(function (row) {
				var sp = got.plan;
				if (sp ? (sp.plan ? (!row || (row.at || 0) < (sp.at || 0)) : false) : false) { return shelf.savePlan(sp.plan, sp.at); }
				return null;
			}).then(function () {
				site.synced = true;
				/* Oldest first, so the site numbers them in the order they were read. */
				local.filter(function (t) { return resume.indexOf(t.id) >= 0; }).sort(function (a, b) { return a.at - b.at; }).forEach(function (t) { siteQueue(t.id); });
			});
		}).then(function () { return listTakes().then(showButton); }).catch(function () {});
	}
	function siteDrop(ids) {
		if (!SITE) { return Promise.resolve(); }
		return Promise.all(ids.map(function (id) { return siteApi('DELETE', 'shelf/take/' + id).catch(function () {}); }));
	}
	function siteClear() {
		if (!SITE) { return Promise.resolve(); }
		/* A plan save still waiting would bring the plan back after the shelf is cleared. */
		window.clearTimeout(site.planTimer);
		return siteApi('DELETE', 'shelf?post=' + POST_ID).catch(function () {});
	}
	function sitePlan(plan, at) {
		if (!SITE) { return; }
		window.clearTimeout(site.planTimer);
		site.planTimer = window.setTimeout(function () {
			siteApi('POST', 'shelf/plan?post=' + POST_ID, JSON.stringify({ plan: plan, at: at }), 'application/json').catch(function () {});
		}, 1200);
	}

	/* The shelf's entries for the engine: words, flags, length and speech level. */
	function engineTakes(list) {
		var out = {};
		list.forEach(function (t) { out[t.id] = { id: t.id, words: t.words, problems: t.problems, seconds: t.seconds, level: t.level }; });
		return out;
	}

	/*
	 * Put a reviewed take on the shelf (called by the review once Whisper's
	 * words are compared). samples: the recording Whisper heard (before
	 * cleanup), at 44.1 kHz. Opening drills, combined readings and phones
	 * are left out (a drill with a line saying so). Resolves to a line for
	 * the review's status, or ''.
	 */
	studio.shelfAdd = function (samples, rate, chunks, R) {
		siteLine('');
		/* 1.31.0: phones keep short takes too (for Continue); longer ones would need more memory than a phone gives. */
		if (!studio.shelfWants(samples.length / rate) || studio.takeMode === 'combined' || !R || rate !== RATE) { return Promise.resolve(''); }
		/* A drill reads the opening for practice, so it is left out, and the review says so: on a short post a drill can look just like a take. */
		if (studio.takeDrill) { return Promise.resolve(__( ' Opening drills are not kept for Combine takes; switch Opening drill off (Controls, Practice) for a take that is.', 'foxfire-studio' )); }
		var fp = C.fingerprint(samples, rate);
		var words = R.words.map(function (w) {
			if (w.status === 'out') { return null; }
			var s = typeof w.s === 'number' ? Math.round(w.s * 1000) / 1000 : null;
			var e = typeof w.e === 'number' ? Math.round(w.e * 1000) / 1000 : null;
			return [s, e, w.status];
		});
		var problems = R.problems.slice(0, 400).map(function (p) {
			return { kind: p.kind, idx: p.idx, s: typeof p.s === 'number' ? Math.round(p.s * 100) / 100 : null, label: p.label || '', detail: p.detail || '' };
		});
		var lite = (chunks || []).map(function (c) { return { text: c.text, timestamp: c.timestamp }; });
		return listTakes().then(function (list) {
			var same = list.filter(function (t) { return C.sameTake(t.fp, fp); })[0];
			if (same) {
				/* The same take again: its review is brought up to date, nothing more is stored. */
				same.words = words;
				same.problems = problems;
				same.chunks = lite;
				same.accuracy = R.accuracy;
				same.wpm = R.wpm;
				same.sig = SIG;
				/* 1.34.0: a take known only from the site, opened here again: its recording is kept here, so it need not be fetched. */
				var keepHere = Promise.resolve();
				if (same.remote) {
					var pcm16 = new Int16Array(samples.length);
					for (var j = 0; j < samples.length; j++) { var sv = samples[j]; pcm16[j] = sv >= 1 ? 32767 : (sv <= -1 ? -32768 : Math.round(sv * 32767)); }
					same.remote = false;
					keepHere = shelf.putAudio(same.id, pcm16);
				}
				return keepHere.then(function () { return shelf.update(same); }).then(function () {
					return sprintf( studio.PRACTICE ? /* translators: %d: the take's number on the shelf. */ __( ' Already on the practice shelf as take %d.', 'foxfire-studio' ) : /* translators: %d: the take's number on the shelf. */ __( ' Already on this post’s shelf as take %d.', 'foxfire-studio' ), same.n );
				});
			}
			var bytes = samples.length * 2;
			var room = (navigator.storage ? navigator.storage.estimate : null) ? navigator.storage.estimate().catch(function () { return null; }) : Promise.resolve(null);
			return room.then(function (est) {
				if (est ? (est.quota ? est.quota - (est.usage || 0) < bytes * 1.5 : false) : false) {
					return __( ' Not kept for Combine takes: this browser is short of storage space.', 'foxfire-studio' );
				}
				var pcm = new Int16Array(samples.length);
				for (var i = 0; i < samples.length; i++) {
					var v = samples[i];
					pcm[i] = v >= 1 ? 32767 : (v <= -1 ? -32768 : Math.round(v * 32767));
				}
				var n = 1;
				list.forEach(function (t) { n = Math.max(n, t.n + 1); });
				var meta = {
					id: 't' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6),
					post: POST_ID, n: n, at: Date.now(), sig: SIG,
					seconds: samples.length / rate, rate: rate, bytes: bytes,
					fp: fp, level: C.speechLevel(samples, rate),
					words: words, problems: problems, chunks: lite,
					accuracy: R.accuracy, wpm: R.wpm, mode: studio.takeMode || '', site: ''
				};
				/* Ask once for storage the browser keeps under pressure; Chrome decides without asking. */
				if (navigator.storage ? navigator.storage.persist : null) { navigator.storage.persist().catch(function () {}); }
				return shelf.put(meta, pcm).then(function () { return listTakes(); }).then(function (now) {
					showButton(now);
					/* 1.34.0: and to the site, in the background, for the reader's other computers. */
					siteQueue(meta.id);
					var ok = now.filter(usable);
					var left = C.coverage(SCRIPT, ok).left;
					/* 1.4.0: say how much of the post is still to read, so a long post can be read in parts. */
					if (left) {
						if (studio.PRACTICE) {
							return sprintf( /* translators: 1: the take's number on the shelf, 2: how many paragraphs no take has read. */ _n( ' Kept on the practice shelf as take %1$d. %2$d paragraph is in no take yet: Continue starts just before it.', ' Kept on the practice shelf as take %1$d. %2$d paragraphs are in no take yet: Continue starts just before the first.', left, 'foxfire-studio' ), n, left );
						}
						return sprintf( /* translators: 1: the take's number on the shelf, 2: how many paragraphs no take has read. */ _n( ' Kept on this post’s shelf as take %1$d. %2$d paragraph is in no take yet: Continue starts just before it.', ' Kept on this post’s shelf as take %1$d. %2$d paragraphs are in no take yet: Continue starts just before the first.', left, 'foxfire-studio' ), n, left );
					}
					/* Combine takes needs a computer's memory, so a phone says so in its place (1.31.0). */
					if (studio.PHONE || LOW_MEM) {
						return sprintf( /* translators: %d: the take's number on the shelf. */ __( ' Kept on this device’s shelf as take %d. Combine takes needs a computer, so it is not offered on a phone or tablet.', 'foxfire-studio' ), n );
					}
					if (studio.PRACTICE) {
						return ok.length >= 2 ? sprintf( /* translators: %d: the take's number on the shelf. */ __( ' Kept on the practice shelf as take %d: Combine takes in the review builds one reading from your takes.', 'foxfire-studio' ), n ) : sprintf( /* translators: %d: the take's number on the shelf. */ __( ' Kept on the practice shelf as take %d, for combining with your next take.', 'foxfire-studio' ), n );
					}
					return ok.length >= 2 ? sprintf( /* translators: %d: the take's number on the shelf. */ __( ' Kept on this post’s shelf as take %d: Combine takes in the review builds one reading from your takes.', 'foxfire-studio' ), n ) : sprintf( /* translators: %d: the take's number on the shelf. */ __( ' Kept on this post’s shelf as take %d, for combining with your next take.', 'foxfire-studio' ), n );
				});
			});
		}).catch(function () { return ''; });
	};

	/* The buttons in the review's header and beside Open a recording, once the post has two takes that can be combined. */
	var btn = document.getElementById('tpRvCombine');
	var btn2 = document.getElementById('tpCombineOpen');
	var btnGo = document.getElementById('tpContinue');
	var goFrom = -1;
	function showButton(list) {
		var ok = list.filter(usable);
		var n = ok.length;
		[btn, btn2].forEach(function (b) {
			if (!b) { return; }
			b.hidden = studio.PHONE || LOW_MEM || n < 2;
			b.textContent = sprintf( /* translators: %d: how many takes are on the shelf. */ __( 'Combine takes (%d)', 'foxfire-studio' ), n );
		});
		/* Continue (1.4.0): the next part of a post read in parts, once the shelf has a take and a paragraph no take has read. */
		var cov = n ? C.coverage(SCRIPT, ok) : { left: 0, start: -1 };
		goFrom = cov.start;
		if (btnGo) {
			btnGo.hidden = !n || cov.left < 1;
			btnGo.textContent = sprintf( /* translators: %d: how many paragraphs no take has read. */ __( 'Continue (%d to read)', 'foxfire-studio' ), cov.left );
		}
	}
	if (btnGo) {
		btnGo.addEventListener('click', function () {
			btnGo.blur();
			if (goFrom < 0) { return; }
			if (studio.settings.drill) {
				studio.msgEl.textContent = __( 'Opening drill is on, so the take would start at the top. Switch it off (Controls, Practice) to continue the post.', 'foxfire-studio' );
				return;
			}
			if (!studio.beginFrom(goFrom)) {
				studio.msgEl.textContent = __( 'Finish the take under way first, then Continue starts the next part.', 'foxfire-studio' );
			}
		});
	}
	listTakes().then(showButton).then(siteSync);

	/*
	 * The Combine takes screen. Each paragraph shows a button for every take
	 * that read it, with that take's flags there; a paragraph can be split to
	 * choose sentence by sentence. Where two takes meet, the join can be
	 * heard and its edges moved. Every change is saved with the post's plan.
	 */
	var cb = {
		open: false, all: [], takes: [], byId: {}, plan: { choice: {}, split: {}, joins: {} },
		pcm: {}, edges: {}, tones: {}, busy: false, arm: '',
		box: document.getElementById('tpCombine'),
		list: document.getElementById('tpCbTakes'),
		body: document.getElementById('tpCbBody'),
		status: document.getElementById('tpCbStatus'),
		bestBtn: document.getElementById('tpCbBest'),
		buildBtn: document.getElementById('tpCbBuild'),
		closeBtn: document.getElementById('tpCbClose'),
		clearBtn: document.getElementById('tpCbClear'),
		preview: new Audio()
	};
	studio.cb = cb;
	if (!cb.box || !btn) { return; }

	function say(msg) { cb.status.textContent = msg; }
	function colour(t) { return 'tp-cb-c' + ((t.n - 1) % 6); }
	function takeName(t) { return sprintf( /* translators: %d: the take's number on the shelf. */ __( 'Take %d', 'foxfire-studio' ), t.n ); }
	function when(t) {
		try { return new Date(t.at).toLocaleString(undefined, { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' }); } catch (e) { return ''; }
	}
	function list() { return C.pieces(SCRIPT, cb.plan.split); }
	function savePlan() {
		var at = Date.now();
		shelf.savePlan(cb.plan, at).catch(function () {});
		sitePlan(cb.plan, at);
	}

	/* Fill in a take for any piece without one: the paragraph's take when it read the piece, else the best of each. */
	function fillChoice() {
		var P = list();
		var takes = cb.takes.map(function (t) { return cb.byId[t.id]; });
		var guess = null;
		P.forEach(function (p) {
			var c = cb.plan.choice[p.id];
			if (c ? cb.byId[c] : false) { return; }
			var para = cb.plan.choice['b' + p.block];
			/* A paragraph joined again takes the take of its first sentence. */
			var sent = null;
			for (var k = 0; k < SCRIPT.length ? !sent : false; k++) {
				if (SCRIPT[k].block === p.block) { sent = cb.plan.choice['s' + SCRIPT[k].sent] || null; }
			}
			var from = para || sent;
			if (from ? (cb.byId[from] ? C.covers(cb.byId[from], p, SCRIPT) : false) : false) { cb.plan.choice[p.id] = from; return; }
			if (!guess) { guess = C.best(P, takes, SCRIPT); }
			cb.plan.choice[p.id] = guess[p.id];
		});
		/* Choices for pieces no longer in the reading (a paragraph joined again) go. */
		var ids = {};
		P.forEach(function (p) { ids[p.id] = true; });
		Object.keys(cb.plan.choice).forEach(function (k) { if (!ids[k]) { delete cb.plan.choice[k]; } });
	}

	function openCombine() {
		if (cb.busy) { return; }
		listTakes().then(function (all) {
			cb.all = all;
			cb.takes = all.filter(usable);
			cb.byId = engineTakes(cb.takes);
			return shelf.plan().catch(function () { return null; });
		}).then(function (saved) {
			if (cb.takes.length < 2) { showButton(cb.all); return; }
			cb.plan = C.cleanPlan(saved, C.pieces(SCRIPT, saved ? (saved.split || {}) : {}), cb.takes.map(function (t) { return t.id; }));
			var fresh = !Object.keys(cb.plan.choice).length;
			fillChoice();
			if (fresh) { say(__( 'Each paragraph starts with the best of each: the take with the fewest flags there, switching takes only for a clear gain. Choose any other take to change it.', 'foxfire-studio' )); } else { say(__( 'Your choices from last time. Best of each starts again from the flags.', 'foxfire-studio' )); }
			savePlan();
			cb.open = true;
			/* Opened from the teleprompter, with no review open: the review screen opens around it, and closes with it. */
			cb.alone = !rv.open;
			if (cb.alone) {
				rv.el.hidden = false;
				rv.open = true;
				document.body.classList.add('is-reviewing');
				if (studio.playing) { studio.togglePlay(); }
			}
			cb.box.hidden = false;
			rv.el.classList.add('is-combining');
			rv.audio.pause();
			draw();
		});
	}
	function closeCombine() {
		cb.open = false;
		cb.box.hidden = true;
		rv.el.classList.remove('is-combining');
		cb.preview.pause();
		cb.arm = '';
		if (cb.alone) { cb.alone = false; studio.closeReview(); }
	}
	studio.cbClose = closeCombine;
	studio.cbTogglePlay = function () {
		if (!cb.preview.src) { return; }
		if (cb.preview.paused) { cb.preview.play().catch(function () {}); } else { cb.preview.pause(); }
	};
	btn.addEventListener('click', function () { btn.blur(); openCombine(); });
	if (btn2) { btn2.addEventListener('click', function () { btn2.blur(); openCombine(); }); }
	cb.closeBtn.addEventListener('click', closeCombine);

	/* The audio, a take at a time, kept while the screen is in use. */
	function ensure(ids) {
		var need = ids.filter(function (id) { return !cb.pcm[id]; });
		var chain = Promise.resolve();
		need.forEach(function (id) {
			chain = chain.then(function () {
				return shelf.audio(id).then(function (pcm) {
					if (pcm) { return pcm; }
					/* 1.34.0: a take from another computer: its recording comes from the site, once. */
					var t = cb.all.filter(function (x) { return x.id === id; })[0];
					if (!SITE || !t || !t.remote || studio.PHONE || LOW_MEM) { throw new Error('missing'); }
					say(sprintf( /* translators: %s: the take's name, such as Take 2. */ __( 'Fetching %s from the site, once; this browser keeps it after this...', 'foxfire-studio' ), takeName(t) ));
					return siteFetch(t).then(function (got) {
						say(sprintf( /* translators: %s: the take's name, such as Take 2. */ __( '%s is here now.', 'foxfire-studio' ), takeName(t) ));
						return got;
					}, function (err) {
						say(err && err.message === 'damaged' ? sprintf( /* translators: %s: the take's name, such as Take 2. */ __( '%s came back from the site damaged, so it was not used. Try again, or remove it.', 'foxfire-studio' ), takeName(t) ) : sprintf( /* translators: %s: the take's name, such as Take 2. */ __( '%s could not be fetched from the site just now. Try again in a moment.', 'foxfire-studio' ), takeName(t) ));
						throw err;
					});
				}).then(function (pcm) { cb.pcm[id] = pcm; });
			});
		});
		return chain;
	}
	function floatOf(id, s, e) {
		var pcm = cb.pcm[id];
		var a = Math.max(0, Math.round(s * RATE));
		var b = Math.min(pcm.length, Math.round(e * RATE));
		var out = new Float32Array(Math.max(0, b - a));
		for (var i = 0; i < out.length; i++) { out[i] = pcm[a + i] / 32768; }
		return out;
	}
	/* Where the voice starts and stops around word k of a take, from the sound (RRTEdit.speechEdges), on a window around the word. */
	function edge(id, k) {
		var key = id + ':' + k;
		if (cb.edges[key]) { return cb.edges[key]; }
		var t = cb.byId[id];
		var w = C.word(t, k);
		var w0 = Math.max(0, w.s - 1.5);
		var w1 = Math.min(t.seconds, w.e + 1.5);
		var x = floatOf(id, w0, w1);
		var tokens = [];
		var at = -1;
		t.words.forEach(function (q, i) {
			if (!C.timed(t, i)) { return; }
			if (q[1] < w0 - 0.5 || q[0] > w1 + 0.5) { return; }
			if (i === k) { at = tokens.length; }
			tokens.push({ s: q[0] - w0, e: q[1] - w0 });
		});
		var r = at >= 0 ? E.speechEdges(tokens, at, at, x, RATE) : { s: w.s - w0, e: w.e - w0 };
		cb.edges[key] = { s: r.s + w0, e: r.e + w0 };
		return cb.edges[key];
	}
	/* The room's own background, from near the take's first word. */
	function tone(id) {
		if (cb.tones[id] !== undefined) { return cb.tones[id]; }
		var t = cb.byId[id];
		var k = C.firstTimed(t, 0, SCRIPT.length - 1);
		var t0 = k >= 0 ? C.word(t, k).s : 1;
		var w0 = Math.max(0, t0 - 6);
		var x = floatOf(id, w0, Math.min(t.seconds, t0 + 4));
		cb.tones[id] = E.roomTone(x, RATE, t0 - w0);
		return cb.tones[id];
	}
	function layoutNow() {
		var rs = C.runs(list(), cb.plan.choice);
		return ensure(rs.map(function (r) { return r.take; }).filter(function (id, i, a) { return a.indexOf(id) === i; })).then(function () {
			return C.layout(rs, cb.byId, SCRIPT, edge, cb.plan.joins);
		});
	}
	function playSamples(x, from) {
		cb.preview.pause();
		if (cb.preview.src) { URL.revokeObjectURL(cb.preview.src); }
		cb.preview.src = URL.createObjectURL(new Blob([E.wav(x, RATE)], { type: 'audio/wav' }));
		cb.preview.currentTime = from || 0;
		cb.preview.play().catch(function () {});
	}

	/* Hear one take's reading of one piece. */
	function hearPiece(p, id) {
		ensure([id]).then(function () {
			var t = cb.byId[id];
			var a = C.firstTimed(t, p.first, p.last);
			var z = C.lastTimed(t, p.first, p.last);
			if (a < 0) { return; }
			playSamples(floatOf(id, Math.max(0, edge(id, a).s - 0.3), Math.min(t.seconds, edge(id, z).e + 0.4)));
		}).catch(function () { say(__( 'That take could not be read from this browser’s storage.', 'foxfire-studio' )); });
	}
	/* Hear a join: the last 1.5 s before it and the first 1.5 s after, as the reading will sound. */
	function hearJoin(key) {
		layoutNow().then(function (segs) {
			var i = -1;
			segs.forEach(function (g, k) { if (g.join === key) { i = k; } });
			if (i < 1) { return; }
			var A = {};
			var B = {};
			Object.keys(segs[i - 1]).forEach(function (k) { A[k] = segs[i - 1][k]; });
			Object.keys(segs[i]).forEach(function (k) { B[k] = segs[i][k]; });
			A.s = Math.max(A.s, A.e - 1.5);
			A.tone = 0;
			B.e = Math.min(B.e, B.s + Math.max(0, 1.5 - B.tone));
			var out = C.render([A, B], floatOf, tone, RATE);
			playSamples(out.samples);
		}).catch(function () { say(__( 'That take could not be read from this browser’s storage.', 'foxfire-studio' )); });
	}

	function ms(v) { return v ? (v > 0 ? '+' : '') + v + ' ms' : __( 'as found', 'foxfire-studio' ); }
	function space(v) { return v ? '+' + v + ' ms' : __( 'none', 'foxfire-studio' ); }

	function flagsLine(f) {
		if (!f.n) { return __( 'no flags', 'foxfire-studio' ); }
		return sprintf( /* translators: %d: how many flags. */ _n( '%d flag', '%d flags', f.n, 'foxfire-studio' ), f.n );
	}

	function drawTakes() {
		cb.list.innerHTML = '';
		cb.all.forEach(function (t) {
			var li = el('li', 'tp-cb-take ' + colour(t));
			li.appendChild(el('span', 'tp-cb-dot'));
			li.appendChild(el('strong', '', takeName(t)));
			var bits = [when(t), fmt(t.seconds)];
			/* 1.34.0: read on another computer; its recording comes from the site when it is first needed here. */
			if (t.remote) { bits.push(__( 'from another computer', 'foxfire-studio' )); }
			if (typeof t.accuracy === 'number') { bits.push(sprintf( /* translators: %s: accuracy as a percentage. */ __( '%s%% as written', 'foxfire-studio' ), Math.round(t.accuracy * 100) )); }
			bits.push(flagsLine({ n: (t.problems || []).length }));
			var mb = t.bytes / 1048576;
			bits.push(sprintf( /* translators: %s: megabytes. */ __( '%s MB', 'foxfire-studio' ), mb < 10 ? mb.toFixed(1) : mb.toFixed(0) ));
			li.appendChild(el('span', 'tp-cb-meta', bits.join(' · ')));
			if (!usable(t)) { li.appendChild(el('span', 'tp-cb-meta', __( 'Read from an earlier version of the post, so it cannot be combined.', 'foxfire-studio' ))); }
			var rm = el('button', 'tp-btn tp-cb-rm', cb.arm === t.id ? (SITE ? __( 'Click again to remove it on every computer', 'foxfire-studio' ) : __( 'Click again to remove', 'foxfire-studio' )) : __( 'Remove', 'foxfire-studio' ));
			rm.type = 'button';
			rm.addEventListener('click', function () {
				if (cb.arm !== t.id) { cb.arm = t.id; drawTakes(); return; }
				cb.arm = '';
				siteDrop([t.id]);
				shelf.drop([t.id]).then(function () {
					delete cb.pcm[t.id];
					return listTakes();
				}).then(function (all) {
					showButton(all);
					if (all.filter(usable).length < 2) { closeCombine(); return; }
					cb.all = all;
					cb.takes = all.filter(usable);
					cb.byId = engineTakes(cb.takes);
					cb.plan = C.cleanPlan(cb.plan, list(), cb.takes.map(function (x) { return x.id; }));
					fillChoice();
					savePlan();
					draw();
					say(sprintf( /* translators: %s: the take's name, such as Take 2. */ __( '%s is off the shelf.', 'foxfire-studio' ), takeName(t) ));
				});
			});
			li.appendChild(rm);
			cb.list.appendChild(li);
		});
	}

	function pieceText(p) {
		var out = [];
		for (var k = p.first; k <= p.last; k++) { out.push(W[k].el.textContent); }
		return out.join(' ');
	}

	function joinBar(j) {
		var ta = cb.takes.filter(function (t) { return t.id === j.a; })[0];
		var tb = cb.takes.filter(function (t) { return t.id === j.b; })[0];
		var n = cb.plan.joins[j.key] || { a: 0, b: 0, sp: 0 };
		var bar = el('div', 'tp-cb-join');
		bar.appendChild(el('span', 'tp-cb-join-name', sprintf( /* translators: 1: the earlier take's name, 2: the later take's name. */ __( 'Join: %1$s to %2$s', 'foxfire-studio' ), takeName(ta), takeName(tb) )));
		var hear = el('button', 'tp-btn tp-cb-hear', __( 'Hear the join', 'foxfire-studio' ));
		hear.type = 'button';
		hear.addEventListener('click', function () { hear.blur(); hearJoin(j.key); });
		bar.appendChild(hear);
		[
			['a', __( 'End', 'foxfire-studio' ), __( 'where the earlier take stops', 'foxfire-studio' ), ms(n.a)],
			['b', __( 'Start', 'foxfire-studio' ), __( 'where the later take begins, in its pause', 'foxfire-studio' ), ms(n.b)],
			['sp', __( 'Space', 'foxfire-studio' ), __( 'room tone added at the join', 'foxfire-studio' ), space(n.sp)]
		].forEach(function (o) {
			var g = el('span', 'tp-ed-edge');
			g.setAttribute('role', 'group');
			g.setAttribute('aria-label', o[1] + ': ' + o[2]);
			g.appendChild(el('span', 'tp-ed-edge-name', o[1]));
			[-1, 1].forEach(function (dir, i) {
				var step = o[0] === 'sp' ? 50 : 10;
				var b2 = el('button', 'tp-btn tp-ed-nudge', dir < 0 ? '◀' : '▶');
				b2.type = 'button';
				b2.title = sprintf( /* translators: 1: what moves (End, Start or Space), 2: how many ms, 3: earlier, later, less or more. */ __( '%1$s %2$s ms %3$s', 'foxfire-studio' ), o[1], step, o[0] === 'sp' ? (dir < 0 ? __( 'less', 'foxfire-studio' ) : __( 'more', 'foxfire-studio' )) : (dir < 0 ? __( 'earlier', 'foxfire-studio' ) : __( 'later', 'foxfire-studio' )) );
				b2.setAttribute('aria-label', b2.title);
				b2.addEventListener('click', function () {
					var next = C.nudge(cb.plan.joins[j.key], o[0], dir);
					if (!next) { return; }
					if (next.a || next.b || next.sp) { cb.plan.joins[j.key] = next; } else { delete cb.plan.joins[j.key]; }
					savePlan();
					draw();
					hearJoin(j.key);
				});
				if (i === 0) { g.appendChild(b2); g.appendChild(el('span', 'tp-ed-edge-val tp-cb-val' + ((o[0] === 'sp' ? n.sp : n[o[0]]) ? ' is-moved' : ''), o[3])); } else { g.appendChild(b2); }
			});
			bar.appendChild(g);
		});
		var reset = el('button', 'tp-btn', __( 'Reset', 'foxfire-studio' ));
		reset.type = 'button';
		reset.disabled = !cb.plan.joins[j.key];
		reset.addEventListener('click', function () { delete cb.plan.joins[j.key]; savePlan(); draw(); hearJoin(j.key); });
		bar.appendChild(reset);
		return bar;
	}

	function draw() {
		var keepY = cb.body.scrollTop;
		drawTakes();
		cb.body.innerHTML = '';
		var P = list();
		drawCover(P);
		var rs = C.runs(P, cb.plan.choice);
		var joins = C.joinKeys(rs, cb.byId);
		var joinAfter = {};
		joins.forEach(function (j) { joinAfter[j.after] = j; });
		var miss = C.missing(P, cb.plan.choice);
		cb.buildBtn.disabled = !!miss.length || !rs.length;
		var sents = {};
		SCRIPT.forEach(function (w) { sents[w.block] = sents[w.block] || {}; sents[w.block][w.sent] = true; });
		var block = -1;
		var sec = null;
		P.forEach(function (p) {
			if (p.block !== block) {
				block = p.block;
				var kind = W[p.first].kind;
				sec = el('section', 'tp-cb-para' + (kind === 'title' ? ' is-title' : (kind === 'h' ? ' is-h' : '')));
				sec.setAttribute('data-block', p.block);
				var many = Object.keys(sents[p.block]).length > 1;
				if (many) {
					var split = !!cb.plan.split[p.block];
					var sb = el('button', 'tp-btn tp-cb-split', split ? __( 'Whole paragraph', 'foxfire-studio' ) : __( 'By sentence', 'foxfire-studio' ));
					sb.type = 'button';
					sb.title = split ? __( 'Choose one take for the whole paragraph again', 'foxfire-studio' ) : __( 'Choose a take for each sentence of this paragraph', 'foxfire-studio' );
					sb.setAttribute('aria-pressed', split ? 'true' : 'false');
					(function (b) {
						sb.addEventListener('click', function () {
							if (cb.plan.split[b]) {
								/* Joined again: the paragraph takes its first sentence's take, when that take read all of it. */
								delete cb.plan.split[b];
							} else {
								cb.plan.split[b] = true;
							}
							fillChoice();
							savePlan();
							draw();
						});
					})(p.block);
					sec.appendChild(sb);
				}
				cb.body.appendChild(sec);
			}
			var chosen = cb.plan.choice[p.id];
			var ct = cb.takes.filter(function (t) { return t.id === chosen; })[0];
			var row = el('div', 'tp-cb-piece' + (ct ? ' ' + colour(ct) : ' is-missing'));
			row.appendChild(el('p', 'tp-cb-text', pieceText(p)));
			var picks = el('div', 'tp-cb-picks');
			cb.takes.forEach(function (t) {
				var et = cb.byId[t.id];
				var ok = C.covers(et, p, SCRIPT);
				var f = ok ? C.flagsIn(et, p) : null;
				var g = el('span', 'tp-cb-pick ' + colour(t) + (t.id === chosen ? ' is-chosen' : ''));
				var b2 = el('button', 'tp-btn tp-cb-choose', ok ? takeName(t) + ' · ' + flagsLine(f) : sprintf( /* translators: %s: the take's name, such as Take 2. */ __( '%s · not read', 'foxfire-studio' ), takeName(t) ));
				b2.type = 'button';
				b2.disabled = !ok;
				b2.setAttribute('aria-pressed', t.id === chosen ? 'true' : 'false');
				b2.setAttribute('data-take', t.id);
				b2.title = ok ? (f.n ? f.list.map(function (q) { return q.label + (q.detail ? ': ' + q.detail : ''); }).join('\n') : __( 'Nothing flagged here', 'foxfire-studio' )) : __( 'This take did not read this far', 'foxfire-studio' );
				b2.addEventListener('click', function () {
					cb.plan.choice[p.id] = t.id;
					savePlan();
					draw();
				});
				g.appendChild(b2);
				if (ok) {
					var h = el('button', 'tp-btn tp-cb-play', '▶');
					h.type = 'button';
					h.title = sprintf( /* translators: %s: the take's name, such as Take 2. */ __( 'Hear %s read this', 'foxfire-studio' ), takeName(t) );
					h.setAttribute('aria-label', h.title);
					h.addEventListener('click', function () { h.blur(); hearPiece(p, t.id); });
					g.appendChild(h);
				}
				picks.appendChild(g);
			});
			row.appendChild(picks);
			if (!ct) { row.appendChild(el('p', 'tp-cb-warn', __( 'No take on the shelf reads this yet. Record it (starting from here is enough), and it joins the shelf after its review.', 'foxfire-studio' ))); }
			sec.appendChild(row);
			if (joinAfter[p.last]) { sec.appendChild(joinBar(joinAfter[p.last])); }
		});
		cb.body.scrollTop = keepY;
		if (miss.length) { say(sprintf( /* translators: %d: how many paragraphs or sentences. */ _n( '%d part of the script is in no take yet, so the reading cannot be built.', '%d parts of the script are in no take yet, so the reading cannot be built.', miss.length, 'foxfire-studio' ), miss.length )); }
	}

	/*
	 * The coverage bar (1.4.0): a cell for each piece, as wide as its words,
	 * in the colour of the take that reads it, outlined where no take does.
	 * A cell scrolls to its paragraph.
	 */
	var coverEl = document.getElementById('tpCbCover');
	function drawCover(P) {
		if (!coverEl) { return; }
		coverEl.innerHTML = '';
		var block = -1;
		P.forEach(function (p) {
			var ct = cb.takes.filter(function (t) { return t.id === cb.plan.choice[p.id]; })[0];
			var c = el('button', 'tp-cb-cell' + (ct ? ' ' + colour(ct) : ' is-missing') + (p.block !== block ? ' is-para' : ''));
			block = p.block;
			c.type = 'button';
			c.style.flexGrow = String(p.last - p.first + 1);
			var words = pieceText(p).split(' ').slice(0, 6).join(' ');
			c.title = (ct ? takeName(ct) : __( 'In no take yet', 'foxfire-studio' )) + ': ' + words + '...';
			c.setAttribute('aria-label', c.title);
			c.addEventListener('click', function () {
				var sec = cb.body.querySelector('[data-block="' + p.block + '"]');
				if (sec) { sec.scrollIntoView({ block: 'start' }); }
			});
			coverEl.appendChild(c);
		});
	}

	cb.bestBtn.addEventListener('click', function () {
		cb.bestBtn.blur();
		var P = list();
		cb.plan.choice = C.best(P, cb.takes.map(function (t) { return cb.byId[t.id]; }), SCRIPT);
		savePlan();
		draw();
		var n = C.joinKeys(C.runs(P, cb.plan.choice), cb.byId).length;
		say(sprintf( /* translators: %d: how many joins. */ _n( 'The best of each, with %d join.', 'The best of each, with %d joins.', n, 'foxfire-studio' ), n ));
	});

	cb.clearBtn.addEventListener('click', function () {
		if (cb.arm !== 'all') { cb.arm = 'all'; cb.clearBtn.textContent = SITE ? __( 'Click again to clear the shelf on every computer', 'foxfire-studio' ) : __( 'Click again to clear the shelf', 'foxfire-studio' ); return; }
		cb.arm = '';
		cb.clearBtn.textContent = __( 'Clear the shelf', 'foxfire-studio' );
		clearShelf().then(closeCombine);
	});
	function clearShelf() {
		siteClear();
		return shelf.list().then(function (all) { return shelf.drop(all.map(function (t) { return t.id; })); }).then(function () {
			return shelf.dropPlan();
		}).then(function () {
			cb.pcm = {};
			cb.edges = {};
			cb.tones = {};
			showButton([]);
		}).catch(function () {});
	}

	/* Build the reading and open it in the review like any recording, with the takes' own Whisper words. */
	cb.buildBtn.addEventListener('click', function () {
		cb.buildBtn.blur();
		if (cb.busy || cb.buildBtn.disabled) { return; }
		cb.busy = true;
		cb.buildBtn.disabled = true;
		say(__( 'Building the reading...', 'foxfire-studio' ));
		cb.preview.pause();
		new Promise(function (res) { window.setTimeout(res, 30); }).then(layoutNow).then(function (segs) {
			var out = C.render(segs, floatOf, tone, RATE);
			var chunks = {};
			cb.takes.forEach(function (t) { chunks[t.id] = t.chunks; });
			var mapped = C.mapChunks(chunks, out.map);
			var blob = new Blob([E.wav(out.samples, RATE)], { type: 'audio/wav' });
			var used = {};
			segs.forEach(function (g) { used[g.take] = true; });
			var names = cb.takes.filter(function (t) { return used[t.id]; }).map(takeName);
			cb.busy = false;
			closeCombine();
			if (rv.open) { studio.closeReview(); }
			studio.openCombined(blob, mapped, names, segs.length - 1);
		}).catch(function () {
			cb.busy = false;
			cb.buildBtn.disabled = false;
			say(__( 'The reading could not be built: a take could not be read from this browser’s storage.', 'foxfire-studio' ));
		});
	});

	/*
	 * Once a combined reading is on the post (Use this recording), the
	 * takes have done their job: offer to clear the shelf.
	 */
	studio.shelfOfferClear = function (into) {
		var box = el('span', 'tp-cb-offer');
		box.appendChild(document.createTextNode(__( ' The takes it was built from are still on this post’s shelf. ', 'foxfire-studio' )));
		var yes = el('button', 'tp-btn', __( 'Clear the shelf', 'foxfire-studio' ));
		yes.type = 'button';
		var no = el('button', 'tp-btn', __( 'Keep them', 'foxfire-studio' ));
		no.type = 'button';
		yes.addEventListener('click', function () { clearShelf().then(function () { box.textContent = __( ' The shelf is clear.', 'foxfire-studio' ); }); });
		no.addEventListener('click', function () { box.textContent = ''; });
		box.appendChild(yes);
		box.appendChild(no);
		into.appendChild(box);
	};
})();
