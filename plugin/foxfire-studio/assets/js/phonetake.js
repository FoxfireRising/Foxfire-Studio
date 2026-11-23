/*
 * Foxfire Studio: phone take mode (1.12.0), loaded right after record.js.
 *
 * On a phone, with the switch on (Controls, Recording, Phone take mode), a
 * take goes to the site in pieces of about ten seconds while it is recorded
 * (includes/live.php). A piece that cannot go up waits and is tried again;
 * the take is also kept in the browser every second as before, so after a
 * reload or a closed page the next visit sends what the site is missing and
 * finishes the take there. At Stop the take is finished on the site and the
 * phone does nothing more unless asked (Make the MP3 here). The computer
 * offers it as a take from the phone, for the Whisper review.
 *
 * While recording, a large level meter sits across the bottom of the screen,
 * with a word under it and a line saying how much of the take is on the site.
 * When a post's last phone take was cut off, opening the post on the phone
 * offers Carry on: a new take from the sentence before where it stopped,
 * which Combine takes joins with the first on the computer.
 *
 * Makes studio.live (used by record.js) and studio.liveStartup (used by
 * teleprompter.js). Pure parts are in live.js (window.RRTLive).
 */
(function () {
	var rrtI18n = (typeof wp === 'object' ? (wp ? wp.i18n : null) : null) || null;
	var __ = rrtI18n ? rrtI18n.__ : function (s) { return s; };
	var sprintf = rrtI18n ? rrtI18n.sprintf : function (f) {
		var a = arguments;
		var i = 0;
		return String(f).replace(/%(?:(\d+)\$)?([sd%])/g, function (m, pos, type) {
			if (type === '%') { return '%'; }
			var v = a[pos ? Number(pos) : ++i];
			return String(v);
		});
	};
	var studio = window.RRTStudio;
	var LV = window.RRTLive;
	var SENT = studio.SENT;
	var POST_ID = studio.POST_ID;
	var settings = studio.settings;
	var W = studio.W;
	var fmtClock = studio.fmtClock;
	function $(id) { return document.getElementById(id); }

	var wrap = $('tpPhoneWrap');
	var opt = $('tpPhoneOpt');
	if (!LV || !studio.PHONE || !SENT || !studio.track || !POST_ID || !wrap || !opt) { return; }

	wrap.hidden = false;
	opt.checked = settings.phoneTake !== false;
	opt.addEventListener('change', function () {
		settings.phoneTake = opt.checked;
		if (studio.saveSettings) { studio.saveSettings(); }
	});

	var meterEl = $('tpPMeter');
	var fillEl = $('tpPMeterFill');
	var peakEl = $('tpPMeterPeak');
	var zoneEl = $('tpPMeterZone');
	var wordEl = $('tpPMeterWord');
	var siteEl = $('tpPMeterSite');
	var resultEl = studio.resultEl;
	var resultInfo = studio.resultInfo;
	var resultAudio = studio.resultAudio;
	var downloadA = studio.downloadA;
	var makeBtn = studio.makeMp3Btn;
	var msgEl = studio.msgEl;

	function api(path, body, type) {
		var h = { 'X-WP-Nonce': SENT.nonce };
		if (type) { h['Content-Type'] = type; }
		return fetch(studio.sentUrl(path), { method: 'POST', credentials: 'same-origin', headers: h, body: body }).then(function (r) {
			return r.json()['catch'](function () { return {}; }).then(function (j) { return { status: r.status, ok: r.ok, j: j || {} }; });
		});
	}
	function form(obj) {
		var f = new FormData();
		Object.keys(obj).forEach(function (k) { f.append(k, String(obj[k])); });
		return f;
	}
	/* The lift a take will need (as Check microphone works it out): the voice target and how far noise reduction lowers the room. */
	function liftOpts() {
		var SND = window.RRTSound, SO = studio.SOUND || null;
		var out = { target: -19, nrDb: 0, liftLimit: SND ? SND.liftLimit : null };
		if (SND && SND.plan && SO && SO.spec) {
			var pl = SND.plan(SO.spec);
			if (pl && pl.mode === 'podcast') { out.target = pl.target; }
		}
		var NR = { off: 0, gentle: 12, medium: 20, strong: 30 };
		var dn = studio.RRT.denoise ? studio.RRT.denoise.strength : 'off';
		out.nrDb = NR.hasOwnProperty(dn) ? NR[dn] : 0;
		return out;
	}

	var cur = null;

	/* ---- The large meter ---- */
	var WORDS = {
		wait: __( 'Listening for your voice', 'foxfire-studio' ),
		good: __( 'Good', 'foxfire-studio' ),
		quiet: __( 'A little quiet: hold the phone closer', 'foxfire-studio' ),
		low: __( 'Too quiet: move closer', 'foxfire-studio' ),
		loud: __( 'Too loud: move back a little', 'foxfire-studio' )
	};
	var lastDraw = 0;
	function showMeter(on) {
		if (!meterEl) { return; }
		meterEl.hidden = !on;
		studio.body.classList.toggle('tp-phone-rec', !!on);
	}
	function draw(force) {
		if (!cur || !meterEl) { return; }
		var now = Date.now();
		if (!force && now - lastDraw < 90) { return; }
		lastDraw = now;
		var st = cur.meter.state();
		fillEl.style.width = LV.meterPct(st.level) + '%';
		peakEl.style.left = LV.meterPct(st.peak) + '%';
		/* The gold band: where the voice should sit for the lift this room allows. */
		if (st.limit !== null) {
			var lo = cur.lift.target - st.limit + 4;
			zoneEl.style.left = LV.meterPct(lo) + '%';
			zoneEl.style.width = Math.max(0, LV.meterPct(-6) - LV.meterPct(lo)) + '%';
		}
		wordEl.textContent = WORDS[st.word] || '';
		wordEl.setAttribute('data-word', st.word);
		siteEl.textContent = siteLine();
	}
	function siteLine() {
		if (!cur) { return ''; }
		if (cur.dead) { return cur.dead; }
		if (!cur.id) { return __( 'Connecting to the site...', 'foxfire-studio' ); }
		var onSite = LV.seconds(cur.sent, cur.rate, cur.bits);
		var made = LV.seconds(cur.queued + cur.pendingBytes, cur.rate, cur.bits);
		if (made - onSite > LV.PIECE_SECONDS * 2.5) {
			return sprintf( /* translators: %s: minutes and seconds. */ __( 'On the site up to %s. Waiting for the connection; the rest is kept on this phone meanwhile.', 'foxfire-studio' ), fmtClock(onSite) );
		}
		return sprintf( /* translators: 1: minutes and seconds, 2: megabytes. */ __( 'On the site up to %1$s, %2$s MB sent', 'foxfire-studio' ), fmtClock(onSite), LV.mb(cur.sent) );
	}

	/* ---- Sending ---- */
	function words() { return studio.finished ? W.length : Math.max(1, studio.wi); }
	function retryLater(fn) {
		var wait = LV.RETRY[Math.min(cur.tries, LV.RETRY.length - 1)];
		cur.tries++;
		window.clearTimeout(cur.timer);
		cur.timer = window.setTimeout(fn, wait * 1000);
	}
	function stopWith(msg) {
		cur.dead = msg;
		cur.queue = [];
		cur.pending = [];
		cur.pendingBytes = 0;
		if (studio.lastRaw) { studio.lastRaw.onSite = false; }
		draw(true);
		if (cur.ended) { endedHere(); }
	}
	function start() {
		var c = cur;
		api('sent/live', form({ post: POST_ID, rate: c.rate, bits: c.bits, drill: '', drill_s: 0, focus: studio.takeFocus || '', setup: studio.micSetup ? studio.micSetup.key : '', setup_label: studio.micSetup ? studio.micSetup.label : '' })).then(function (r) {
			if (c !== cur) { return; }
			if (r.ok && r.j.take) {
				c.id = r.j.take.id;
				c.ends = r.j.take.ends;
				mine(c.id, true);
				c.tries = 0;
				/* The browser's copy knows where it is on the site, for after an interruption. */
				if (c.rec.take) {
					c.rec.take.site = c.id;
					c.rec.take.siteRate = c.rate;
					studio.takeStore.begin(c.rec.take)['catch'](function () {});
				}
				draw(true);
				pump();
				return;
			}
			if (r.status === 409 || r.status === 400 || r.status === 401 || r.status === 403) {
				stopWith(r.j.message || __( 'The site did not take this recording, so it is kept on this phone only.', 'foxfire-studio' ));
				return;
			}
			retryLater(start);
		})['catch'](function () { if (c === cur) { retryLater(start); } });
	}
	function cutPiece() {
		if (!cur.pendingBytes) { return; }
		var data = new Uint8Array(cur.pendingBytes);
		var o = 0;
		cur.pending.forEach(function (p) { data.set(p, o); o += p.length; });
		cur.queue.push({ offset: cur.queued, data: data });
		cur.queued += data.length;
		cur.pending = [];
		cur.pendingBytes = 0;
		pump();
	}
	function pump() {
		var c = cur;
		if (!c || c.dead || c.busy || !c.id) { return; }
		if (!c.queue.length) {
			if (c.ended) { finishOnSite(); }
			return;
		}
		c.busy = true;
		var p = c.queue[0];
		api('sent/' + c.id + '/piece?offset=' + p.offset + '&words=' + words(), p.data, 'application/octet-stream').then(function (r) {
			c.busy = false;
			if (c !== cur) { return; }
			var have = r.j ? Number(r.j.have) : NaN;
			if (r.ok || (r.status === 409 && isFinite(have))) {
				c.tries = 0;
				c.sent = Math.max(c.sent, isFinite(have) ? have : p.offset + p.data.length);
				while (c.queue.length && c.queue[0].offset + c.queue[0].data.length <= c.sent) { c.queue.shift(); }
				/* The site is missing audio this phone no longer holds as a piece: the browser's copy will have to do. */
				if (c.queue.length && c.queue[0].offset > c.sent) {
					stopWith(__( 'Part of the take did not reach the site, so it is kept on this phone. Download the original from the result.', 'foxfire-studio' ));
					return;
				}
				draw(true);
				pump();
				return;
			}
			if (r.status === 401 || r.status === 403 || r.status === 404 || r.status === 410 || r.status === 413) {
				stopWith(r.j.message || __( 'The take is no longer on the site, so the rest is kept on this phone only.', 'foxfire-studio' ));
				return;
			}
			retryLater(pump);
		})['catch'](function () {
			c.busy = false;
			if (c === cur) { draw(true); retryLater(pump); }
		});
	}
	window.addEventListener('online', function () {
		if (!cur || cur.dead) { return; }
		window.clearTimeout(cur.timer);
		cur.tries = 0;
		if (cur.id) { pump(); } else { start(); }
	});
	/* A phone hides the page for a call or another app: send what is ready, and write the rest to the browser. */
	document.addEventListener('visibilitychange', function () {
		if (document.visibilityState !== 'hidden' || !cur || cur.dead || cur.ended) { return; }
		if (cur.rec.saveNow) { cur.rec.saveNow(); }
		cutPiece();
	});

	/* ---- After Stop ---- */
	function finishOnSite() {
		var c = cur;
		if (c.closing || c.closed) { return; }
		c.closing = true;
		api('sent/' + c.id + '/done', form({ words: c.words, cut: 0 })).then(function (r) {
			c.closing = false;
			if (c !== cur) { return; }
			if (r.ok) {
				c.closed = true;
				c.take = r.j.take || null;
				mine(c.id, false);
				endedHere();
				return;
			}
			if (r.status === 401 || r.status === 403 || r.status === 404) { stopWith(r.j.message || __( 'The take is no longer on the site.', 'foxfire-studio' )); return; }
			retryLater(finishOnSite);
		})['catch'](function () { c.closing = false; if (c === cur) { retryLater(finishOnSite); } });
	}
	/* What the result says once the take is finished on the site, or could not be. */
	function endedHere() {
		var c = cur;
		showMeter(false);
		if (!resultEl.classList.contains('is-on')) { return; }
		if (c.dead) {
			resultInfo.textContent = c.dead + ' ' + __( 'Make the MP3 here, or download the original and open it on your computer with Review a recording.', 'foxfire-studio' );
			return;
		}
		studio.unsaved = false;
		if (studio.forgetTake) { studio.forgetTake(); }
		/* 1.12.1: the ended flag is written as Stop is pressed; remove the copy only after that
		   write, so it cannot come back as an unfinished recording the site already has. */
		var tid = c.rec.take ? c.rec.take.id : '';
		if (tid) { (c.saving || Promise.resolve()).then(function () { return studio.takeStore.remove(tid); })['catch'](function () {}); }
		var secs = LV.seconds(c.sent, c.rate, c.bits);
		resultInfo.textContent = sprintf( /* translators: 1: minutes and seconds, 2: megabytes, 3: a date. */ __( 'Saved on the site: %1$s long, %2$s MB. Open the teleprompter for this post on your computer and it will be waiting there for the Whisper review, until %3$s at most. You can listen back here (the original is quiet until it is leveled), or make the MP3 on this phone.', 'foxfire-studio' ), fmtClock(secs), LV.mb(c.sent + 44), c.take ? c.take.ends : (c.ends || '') );
		if (c.take && SENT) {
			SENT.waiting = (SENT.waiting || []).filter(function (t) { return t.id !== c.take.id; });
			SENT.waiting.unshift(c.take);
		}
	}
	function waitingLine() {
		if (!cur || cur.closed || cur.dead) { return; }
		var left = cur.queued + cur.pendingBytes - cur.sent;
		resultInfo.textContent = left > 0 && !navigator.onLine ? sprintf( /* translators: %s: megabytes. */ __( 'Waiting for a connection to send the last %s MB. Keep this page open; the take is also kept on this phone, and the next visit sends it if the page closes.', 'foxfire-studio' ), LV.mb(left) ) : sprintf( /* translators: %s: megabytes. */ __( 'Saving the end of the take on the site (%s MB to go)...', 'foxfire-studio' ), LV.mb(Math.max(0, left)) );
		window.setTimeout(waitingLine, 1000);
	}

	studio.live = {
		/* A take begins: the worklet's recorder, which knows its rate and depth. Opening drills stay on the phone. */
		begin: function (rec) {
			if (cur && cur.timer) { window.clearTimeout(cur.timer); }
			cur = null;
			if (!settings.phoneTake || !rec || !rec.bits || studio.takeDrill) { showMeter(false); return; }
			var rate = rec.take ? rec.take.rate : 48000;
			cur = { rec: rec, rate: rate, bits: rec.bits, id: '', queue: [], queued: 0, sent: 0, pending: [], pendingBytes: 0, pieceBytes: LV.pieceBytes(rate, rec.bits), tries: 0, timer: 0, busy: false, ended: false, dead: '', closed: false, closing: false, words: 0, lift: liftOpts() };
			cur.meter = LV.meter(cur.lift);
			showMeter(true);
			draw(true);
			start();
		},
		/* New audio, as packed samples (16-bit, or 24-bit bytes). */
		push: function (s) {
			if (!cur || cur.dead || cur.ended) { return; }
			var b = new Uint8Array(s.buffer, s.byteOffset, s.byteLength);
			cur.pending.push(b);
			cur.pendingBytes += b.length;
			if (cur.pendingBytes >= cur.pieceBytes) { cutPiece(); }
		},
		level: function (rms, peak, dt) {
			if (!cur || cur.ended) { return; }
			cur.meter.push(rms, peak, dt);
			draw(false);
		},
		/* Whether this take is the site's to finish. */
		owns: function () { return !!cur && !cur.dead; },
		/* Stop: send the rest, finish the take on the site, and offer the MP3 on the phone. */
		finish: function (raw, makeMp3) {
			var c = cur;
			c.ended = true;
			c.words = studio.lastRaw ? studio.lastRaw.words : words();
			if (c.rec.take) {
				c.rec.take.ended = true;
				c.saving = studio.takeStore.begin(c.rec.take)['catch'](function () {});
			}
			if (studio.lastRaw) { studio.lastRaw.onSite = true; }
			cutPiece();
			downloadA.hidden = true;
			makeBtn.hidden = false;
			makeBtn.onclick = function () { makeBtn.blur(); makeMp3(); };
			resultAudio.src = URL.createObjectURL(raw);
			waitingLine();
			pump();
		}
	};

	/* ---- The next visit: the rest of a take that was cut off, then Carry on ---- */
	function sendRest(t, site) {
		var bits = t.bits === 24 ? 24 : 16;
		return studio.takeStore.load(t.id, bits).then(function (parts) {
			var total = 0;
			parts.forEach(function (p) { total += p.byteLength; });
			var plan = LV.resumePlan(total, Math.max(0, site.bytes - 44), bits);
			var all = null;
			if (plan.bytes > 0) {
				all = new Uint8Array(total);
				var o = 0;
				parts.forEach(function (p) { all.set(new Uint8Array(p.buffer, p.byteOffset, p.byteLength), o); o += p.byteLength; });
			}
			msgEl.textContent = plan.bytes ? sprintf( /* translators: %s: megabytes. */ __( 'Sending the rest of a take that was cut off (%s MB)...', 'foxfire-studio' ), LV.mb(plan.bytes) ) : '';
			var step = LV.pieceBytes(t.rate, bits);
			function next(from) {
				if (from >= total) {
					return api('sent/' + t.site + '/done', form({ words: t.words || 0, cut: t.ended ? 0 : 1 }));
				}
				var end = Math.min(total, from + step);
				return api('sent/' + t.site + '/piece?offset=' + from + '&words=' + (t.words || 0), all.subarray(from, end), 'application/octet-stream').then(function (r) {
					var have = r.j ? Number(r.j.have) : NaN;
					if (!r.ok && !(r.status === 409 && isFinite(have))) { throw new Error(String(r.status)); }
					return next(isFinite(have) ? Math.max(have, from) : end);
				});
			}
			return next(plan.from).then(function (r) {
				if (!r.ok) { throw new Error(String(r.status)); }
				msgEl.textContent = '';
				studio.takeStore.remove(t.id)['catch'](function () {});
				if (r.j.take) {
					SENT.waiting = (SENT.waiting || []).filter(function (x) { return x.id !== r.j.take.id; });
					SENT.waiting.unshift(r.j.take);
				}
			});
		})['catch'](function () {
			msgEl.textContent = __( 'The rest of a take that was cut off could not be sent yet. It is kept on this phone, and the next visit tries again.', 'foxfire-studio' );
		});
	}
	/* 1.12.1: the takes this browser started on the site. One still marked as being
	   recorded when the post opens again was cut off (this page is not recording it),
	   so it is finished as cut at once, and Carry on need not wait for the site's 90 s. */
	var MINE = 'rrtLiveMine';
	function mineList() {
		try { return JSON.parse(window.localStorage.getItem(MINE) || '[]') || []; } catch (e) { return []; }
	}
	function mine(id, on) {
		if (!id) { return; }
		var list = mineList().filter(function (x) { return x !== id; });
		if (on) { list = list.slice(-19); list.push(id); }
		try { window.localStorage.setItem(MINE, JSON.stringify(list)); } catch (e) {}
	}
	function closeOrphans(held) {
		var list = mineList();
		if (!list.length) { return Promise.resolve(); }
		var waiting = SENT.waiting || [];
		var ids = waiting.map(function (x) { return x.id; });
		/* Forget takes the site no longer holds. */
		list.forEach(function (id) { if (ids.indexOf(id) === -1) { mine(id, false); } });
		/* A take this phone still holds audio for is sendRest's to finish, with that audio. */
		var orphans = waiting.filter(function (x) { return x.state === 'live' && list.indexOf(x.id) !== -1 && !held[x.id]; });
		return orphans.reduce(function (p, x) {
			return p.then(function () {
				return api('sent/' + x.id + '/done', form({ words: x.words || 0, cut: 1 })).then(function (r) {
					if (!r.ok) { return; }
					mine(x.id, false);
					SENT.waiting = (SENT.waiting || []).filter(function (y) { return y.id !== x.id; });
					if (r.j.take) { SENT.waiting.unshift(r.j.take); }
				});
			})['catch'](function () {});
		}, Promise.resolve());
	}
	var CARRIED = 'rrtCarried';
	function carried() {
		try { return JSON.parse(window.localStorage.getItem(CARRIED) || '[]') || []; } catch (e) { return []; }
	}
	function markCarried(id) {
		var list = carried().filter(function (x) { return x !== id; }).slice(-19);
		list.push(id);
		try { window.localStorage.setItem(CARRIED, JSON.stringify(list)); } catch (e) {}
	}
	function offerCarry() {
		var box = $('tpCarry');
		if (!box || !W.length) { return false; }
		var seen = carried();
		var t = null;
		(SENT.waiting || []).forEach(function (x) {
			if (t || x.post !== POST_ID || x.drill || seen.indexOf(x.id) !== -1) { return; }
			if (x.state === 'cut' && x.words > 0 && x.words < W.length) { t = x; }
		});
		if (!t) { return false; }
		var sents = W.map(function (w) { return w.sent; });
		var from = LV.carryStart(sents, t.words);
		var at = W.slice(Math.max(0, t.words - 4), Math.min(W.length, t.words + 1)).map(function (w) { return w.el.textContent; }).join(' ');
		$('tpCarryInfo').textContent = sprintf( /* translators: 1: minutes and seconds, 2: a date and time, 3: the words where it stopped. */ __( 'Your take of this post on the phone was cut off at %1$s (%2$s), around “%3$s”. What was recorded is safe on the site for your computer. Carry on from the sentence before, as a new take? On the computer, Combine takes joins the two.', 'foxfire-studio' ), fmtClock(t.seconds), t.when, at );
		box.classList.add('is-on');
		$('tpCarryGo').onclick = function () {
			box.classList.remove('is-on');
			if (settings.drill) {
				/* Offered again on the next visit. */
				msgEl.textContent = __( 'Opening drill is on, so the take would start at the top. Switch it off (Controls, Practice) and open the post again to carry on.', 'foxfire-studio' );
				return;
			}
			markCarried(t.id);
			if (!studio.beginFrom(from)) {
				msgEl.textContent = __( 'Finish the take under way first.', 'foxfire-studio' );
			}
		};
		$('tpCarryLater').onclick = function () {
			box.classList.remove('is-on');
			markCarried(t.id);
		};
		return true;
	}
	studio.liveStartup = function (otherwise) {
		var by = {}, held = {};
		(SENT.waiting || []).forEach(function (x) { by[x.id] = x; });
		studio.takeStore.list().then(function (all) {
			/* A copy of a take that reached Stop and that the site no longer holds (used or
			   downloaded on the computer) is spent: forget it rather than offer it as unfinished. */
			all.forEach(function (t) {
				if (t.site && t.ended && !by[t.site]) { studio.takeStore.remove(t.id)['catch'](function () {}); }
			});
			all = all.filter(function (t) { return !(t.site && t.ended && !by[t.site]); });
			var todo = all.filter(function (t) { return t.site ? !!by[t.site] : false; });
			todo.forEach(function (t) { held[t.site] = true; });
			return todo.reduce(function (p, t) {
				return p.then(function () {
					var site = by[t.site];
					if (site.state === 'done') { studio.takeStore.remove(t.id)['catch'](function () {}); return null; }
					return sendRest(t, site);
				});
			}, Promise.resolve());
		})['catch'](function () {}).then(function () { return closeOrphans(held); }).then(function () {
			if (!offerCarry()) { otherwise(); }
		});
	};
})();
