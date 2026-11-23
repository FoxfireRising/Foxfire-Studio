/*
 * Foxfire Studio: listener analytics (0.10.0).
 *
 * Listens for the player's rrt:player events and sends them, in small
 * batches, to this site's own REST route. Nothing goes to any other site.
 * No cookies are used: the session id is a random number that lives only
 * in this page's memory and is gone when it closes.
 *
 * Returning listeners (1.2.0), only when the site switches it on: after a
 * real listen (30 seconds, or the whole reading) the month is kept in this
 * browser's own storage, and a later visit sends only a yes or no, "listened
 * here within the last few months". The month itself is never sent. With the
 * switch off, any month kept earlier is removed.
 *
 * Visitors whose browser asks not to be tracked (Do Not Track or Global
 * Privacy Control) are not measured at all.
 *
 * Written for caching plugins' script optimisers, which can mangle a double
 * ampersand: this file uses nested ifs instead, and only block comments.
 */
(function () {
	'use strict';

	var KEEP = { view: 1, ready: 1, play: 1, pause: 1, progress: 1, seek: 1, rate: 1, volume: 1, ended: 1, hidden: 1, visible: 1, leave: 1, follow: 1, download: 1, error: 1 };
	var S = null;
	var BACK_KEY = 'rrt_listened_month';
	var REAL_LISTEN_S = 30;

	function monthNow() {
		var d = new Date();
		return d.getFullYear() * 12 + d.getMonth();
	}

	/* Whether this browser had a real listen here within the last n months; null when off or unknown. */
	function cameBack(n) {
		var v = null;
		try {
			if (!n) { window.localStorage.removeItem(BACK_KEY); return null; }
			v = window.localStorage.getItem(BACK_KEY);
		} catch (e) { return null; }
		if (v === null) { return 0; }
		var age = monthNow() - parseInt(v, 10);
		if (isNaN(age)) { return 0; }
		if (age < 0) { return 0; }
		return age < n ? 1 : 0;
	}

	function rememberListen() {
		if (!S) { return; }
		if (!S.backMonths) { return; }
		if (S.remembered) { return; }
		S.remembered = true;
		try { window.localStorage.setItem(BACK_KEY, String(monthNow())); } catch (e) {}
	}

	function optedOut() {
		var n = window.navigator || {};
		if (n.globalPrivacyControl === true) { return true; }
		if (n.doNotTrack === '1') { return true; }
		if (window.doNotTrack === '1') { return true; }
		if (n.msDoNotTrack === '1') { return true; }
		return false;
	}

	function randomId() {
		var b = new Uint8Array(16);
		var i;
		var out = '';
		if (window.crypto) {
			if (window.crypto.getRandomValues) { window.crypto.getRandomValues(b); }
		}
		for (i = 0; i < 16; i++) {
			if (!b[i]) { b[i] = Math.floor(Math.random() * 256); }
			out += (b[i] < 16 ? '0' : '') + b[i].toString(16);
		}
		return out;
	}

	function now() {
		return (window.performance ? window.performance.now() : Date.now());
	}

	/* Only the referring site's name is kept, never the page or its address. */
	function referrerHost() {
		var r = document.referrer;
		if (!r) { return ''; }
		try { return new URL(r).hostname.toLowerCase(); } catch (e) { return ''; }
	}

	function campaign(src) {
		var out = {};
		var q;
		try { q = new URLSearchParams(window.location.search); } catch (e) { return out; }
		['utm_source', 'utm_medium', 'utm_campaign', 'utm_content'].forEach(function (k) {
			var v = q.get(k);
			if (v) { out[k.replace('utm_', '')] = String(v).slice(0, 100); }
		});
		if (src) {
			var a = q.get(src);
			if (a) { if (!out.source) { out.source = String(a).slice(0, 100); } }
		}
		return out;
	}

	function send(final) {
		if (!S) { return; }
		if (!S.q.length) { return; }
		var batch = S.q.splice(0, 50);
		var body = { s: S.id, p: S.post, e: batch };
		if (!S.metaSent) { body.m = S.meta; S.metaSent = true; }
		var json = JSON.stringify(body);
		var sent = false;
		try {
			if (navigator.sendBeacon) { sent = navigator.sendBeacon(S.url, new Blob([json], { type: 'text/plain' })); }
		} catch (e) {}
		if (!sent) {
			try { window.fetch(S.url, { method: 'POST', body: json, keepalive: true, credentials: 'same-origin', headers: { 'Content-Type': 'text/plain' } }); } catch (e2) {}
		}
		if (S.q.length) { send(final); }
	}

	function push(type, d) {
		var ev = { k: type, t: d.t || 0, ms: Math.round(now() - S.t0) };
		var x;
		for (x in d) {
			if (Object.prototype.hasOwnProperty.call(d, x)) {
				if (x !== 'type') { if (x !== 'post') { if (x !== 't') { ev[x] = d[x]; } } }
			}
		}
		S.q.push(ev);
		if (S.q.length >= 40) { send(false); }
	}

	/* Visible time on the page, for time on page. */
	function visibleSeconds() {
		var v = S.visMs;
		if (S.visFrom !== null) { v += now() - S.visFrom; }
		return Math.round(v / 1000);
	}

	function watchScroll(selector) {
		var box = null;
		if (selector) { try { box = document.querySelector(selector); } catch (e) {} }
		var marks = [25, 50, 75, 100];
		function check() {
			var top;
			var height;
			var seen;
			if (box) {
				var r = box.getBoundingClientRect();
				top = r.top;
				height = r.height;
				seen = (window.innerHeight - top) / Math.max(1, height) * 100;
			} else {
				var de = document.documentElement;
				seen = (window.scrollY + window.innerHeight) / Math.max(1, de.scrollHeight) * 100;
			}
			while (marks.length) {
				if (seen + 0.5 < marks[0]) { break; }
				push('scroll', { t: S.audioT, depth: marks.shift() });
			}
			if (!marks.length) { window.removeEventListener('scroll', onScroll); }
		}
		var pending = false;
		function onScroll() {
			if (pending) { return; }
			pending = true;
			window.setTimeout(function () { pending = false; check(); }, 400);
		}
		window.addEventListener('scroll', onScroll, { passive: true });
		window.setTimeout(check, 1000);
	}

	function start(el, d) {
		var cfg = {};
		try { cfg = (JSON.parse(el.getAttribute('data-rrt')) || {}).listen || null; } catch (e) { cfg = null; }
		if (!cfg) { return false; }
		if (!cfg.u) { return false; }
		if (cfg.d) { if (optedOut()) { return false; } }
		S = {
			id: randomId(),
			url: cfg.u,
			post: d.post || 0,
			t0: now(),
			q: [],
			metaSent: false,
			audioT: 0,
			visMs: 0,
			visFrom: document.visibilityState === 'hidden' ? null : now(),
			lastVol: null,
			backMonths: cfg.b || 0,
			remembered: false,
			heard: {},
			heardN: 0,
			meta: {
				r: referrerHost(),
				c: campaign(cfg.s || 'src'),
				w: window.innerWidth || 0,
				tp: (navigator.maxTouchPoints || 0)
			}
		};
		var back = cameBack(S.backMonths);
		if (back !== null) { S.meta.b = back; }
		/* Registered after the player's own handlers, so its last events are queued first. */
		document.addEventListener('visibilitychange', function () {
			if (document.visibilityState === 'hidden') {
				if (S.visFrom !== null) { S.visMs += now() - S.visFrom; S.visFrom = null; }
				/* Phones often close a hidden tab without any further event, so time on page is sent now. */
				push('page', { t: S.audioT, vis: visibleSeconds() });
				window.setTimeout(function () { send(true); }, 0);
			} else if (S.visFrom === null) {
				S.visFrom = now();
			}
		});
		window.addEventListener('pagehide', function () { send(true); });
		window.setInterval(function () { send(false); }, 15000);
		watchScroll(cfg.c || '');
		return true;
	}

	document.addEventListener('rrt:player', function (e) {
		var d = e.detail || {};
		var type = d.type;
		if (!KEEP[type]) { return; }
		if (type === 'view') {
			if (S) { return; }
			if (!start(e.target, d)) { return; }
		}
		if (!S) { return; }
		if (d.post !== S.post) { return; }
		S.audioT = d.t || 0;
		if (type === 'volume') {
			/* Volume sliders fire many events; keep mute changes and steps of a tenth. */
			var v = Math.round((d.volume || 0) * 10) / 10;
			var key = (d.muted ? 'm' : '') + v;
			if (key === S.lastVol) { return; }
			S.lastVol = key;
			d = { t: d.t, rate: d.rate, volume: v, muted: d.muted ? 1 : 0 };
		}
		if (type === 'leave') { d.vis = visibleSeconds(); }
		if (type === 'progress') {
			if (!S.heard[d.bucket]) { S.heard[d.bucket] = 1; S.heardN += 1; }
			if (S.heardN * 5 >= REAL_LISTEN_S) { rememberListen(); }
		}
		if (type === 'ended') { rememberListen(); }
		push(type, d);
		if (type === 'pause' || type === 'ended' || type === 'leave') { send(type === 'leave'); }
	});
}());
