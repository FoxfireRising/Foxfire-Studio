/*
 * Foxfire Studio: the optional link to Google Analytics (1.2.0).
 *
 * Off by default. When the site switches it on, the player's own events are
 * passed to the Google Analytics tag the site already loads (gtag), as three
 * events: audio_start (the first play), audio_progress (halfway, with
 * audio_percent 50) and audio_complete (the end), each with the reading's
 * title. Nothing is sent when the page has no gtag, and visitors whose
 * browser sends Do Not Track or Global Privacy Control are left out when the
 * site respects those signals, as Foxfire Studio's own measuring does.
 *
 * Written for caching plugins' script optimisers, which can mangle a double
 * ampersand: this file uses nested ifs instead, and only block comments.
 */
(function () {
	'use strict';

	var done = {};

	function optedOut() {
		var n = window.navigator || {};
		if (n.globalPrivacyControl === true) { return true; }
		if (n.doNotTrack === '1') { return true; }
		if (window.doNotTrack === '1') { return true; }
		if (n.msDoNotTrack === '1') { return true; }
		return false;
	}

	function config(el) {
		try { return (JSON.parse(el.getAttribute('data-rrt')) || {}).ga || null; } catch (e) { return null; }
	}

	function send(name, cfg, extra) {
		if (typeof window.gtag !== 'function') { return; }
		var p = { audio_title: cfg.t || '', audio_provider: 'Foxfire Studio' };
		var k;
		if (extra) {
			for (k in extra) {
				if (Object.prototype.hasOwnProperty.call(extra, k)) { p[k] = extra[k]; }
			}
		}
		try { window.gtag('event', name, p); } catch (e) {}
	}

	document.addEventListener('rrt:player', function (e) {
		var d = e.detail || {};
		var cfg = config(e.target);
		if (!cfg) { return; }
		if (cfg.d) { if (optedOut()) { return; } }
		var key = String(d.post || 0);
		if (!done[key]) { done[key] = { start: false, half: false, end: false, dur: 0 }; }
		var st = done[key];
		if (d.type === 'ready') { st.dur = d.duration || 0; }
		if (d.type === 'play') {
			if (!st.start) { st.start = true; send('audio_start', cfg); }
		}
		if (d.type === 'progress') {
			if (!st.half) {
				if (st.dur > 0) {
					if ((d.bucket + 1) * 5 >= st.dur / 2) { st.half = true; send('audio_progress', cfg, { audio_percent: 50 }); }
				}
			}
		}
		if (d.type === 'ended') {
			if (!st.end) { st.end = true; send('audio_complete', cfg); }
		}
	});
}());
