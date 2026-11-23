/*
 * Foxfire Studio: YouTube and SoundCloud players that load on play (1.35.0).
 *
 * Each panel (.rrt-embed) arrives as a plain link to the video or track.
 * This turns it into a play button; one press loads the real player in the
 * panel's box and starts it. Nothing is fetched from either service before
 * then. One thing plays at a time: a panel's player pauses the reading
 * player and other panels, and the reading player pauses them, through the
 * short messages both services' players accept from their page.
 *
 * Written without the double ampersand, for page optimizers that inline scripts.
 */
(function () {
	'use strict';

	var YT = 'https://www.youtube-nocookie.com';
	var SC = 'https://w.soundcloud.com';
	var loaded = [];

	function startsWith(s, p) { return String(s).indexOf(p) === 0; }

	/* Only these two players are ever loaded, whatever the page says. */
	function safeSrc(service, src) {
		if (service === 'yt') { return startsWith(src, YT + '/embed/') ? src : ''; }
		if (service === 'sc') { return startsWith(src, SC + '/player/?') ? src : ''; }
		return '';
	}

	function send(item, msg) {
		var w = item.frame ? item.frame.contentWindow : null;
		if (!w) { return; }
		try { w.postMessage(JSON.stringify(msg), item.service === 'yt' ? YT : SC); } catch (e) {}
	}

	function pauseItem(item) {
		if (item.service === 'yt') {
			send(item, { event: 'command', func: 'pauseVideo', args: [] });
		} else {
			send(item, { method: 'pause' });
		}
	}

	/* Pauses every loaded panel player except one. */
	function pausePanels(except) {
		loaded.forEach(function (item) {
			if (item !== except) { pauseItem(item); }
		});
	}

	/* Pauses every Foxfire reading player on the page. */
	function pauseReadings() {
		Array.prototype.forEach.call(document.querySelectorAll('.rrt-player.is-ready'), function (o) {
			var p = o.rrtPlayer;
			if (!p) { return; }
			if (p.audio) { if (!p.audio.paused) { p.audio.pause(); } }
		});
	}

	function playing(item) {
		pauseReadings();
		pausePanels(item);
	}

	function listen(item) {
		if (item.service === 'yt') {
			send(item, { event: 'listening', id: item.id, channel: 'widget' });
		} else {
			send(item, { method: 'addEventListener', value: 'play' });
		}
	}

	function load(box, focus) {
		if (box.rrtEmbed) { return box.rrtEmbed; }
		var service = box.getAttribute('data-rrt-embed');
		var src = safeSrc(service, box.getAttribute('data-rrt-play') || '');
		if (!src) { return null; }
		var item = { box: box, service: service, id: loaded.length + 1, frame: null };
		pauseReadings();
		pausePanels(null);
		var f = document.createElement('iframe');
		f.className = 'rrt-embed-frame';
		f.setAttribute('title', box.getAttribute('data-rrt-title') || '');
		f.setAttribute('allow', box.getAttribute('data-rrt-allow') || 'autoplay');
		f.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
		if (box.getAttribute('data-rrt-full') === '1') { f.setAttribute('allowfullscreen', ''); }
		f.setAttribute('src', src);
		f.addEventListener('load', function () {
			listen(item);
			window.setTimeout(function () { listen(item); }, 800);
		});
		item.frame = f;
		var go = box.querySelector('.rrt-embed-go');
		if (go) { box.replaceChild(f, go); } else { box.appendChild(f); }
		box.classList.add('is-loaded');
		box.rrtEmbed = item;
		loaded.push(item);
		if (focus) {
			try { f.focus(); } catch (e) {}
		}
		try { box.dispatchEvent(new CustomEvent('rrt:embed', { detail: { type: 'load', service: service }, bubbles: true })); } catch (e) {}
		return item;
	}

	/* The link becomes a real button, keeping the picture and words inside it. */
	function ready(box) {
		if (box.getAttribute('data-rrt-ready') === '1') { return; }
		var a = box.querySelector('a.rrt-embed-go');
		if (!a) { return; }
		var b = document.createElement('button');
		b.type = 'button';
		b.className = 'rrt-embed-go';
		while (a.firstChild) { b.appendChild(a.firstChild); }
		b.addEventListener('click', function (e) {
			e.preventDefault();
			load(box, true);
		});
		box.replaceChild(b, a);
		box.setAttribute('data-rrt-ready', '1');
	}

	function itemFor(source) {
		for (var i = 0; i < loaded.length; i++) {
			if (loaded[i].frame) { if (loaded[i].frame.contentWindow === source) { return loaded[i]; } }
		}
		return null;
	}

	/* A panel's player started by itself or from its own controls: the others stop. */
	window.addEventListener('message', function (e) {
		if (e.origin !== YT) { if (e.origin !== SC) { return; } }
		var item = itemFor(e.source);
		if (!item) { return; }
		var d = e.data;
		if (typeof d === 'string') {
			try { d = JSON.parse(d); } catch (err) { return; }
		}
		if (!d) { return; }
		if (typeof d !== 'object') { return; }
		if (item.service === 'yt') {
			var state = null;
			if (d.event === 'onStateChange') { state = d.info; }
			if (d.event === 'infoDelivery') { if (d.info) { if (typeof d.info.playerState === 'number') { state = d.info.playerState; } } }
			if (state === 1) { playing(item); }
		} else if (d.method === 'play') {
			playing(item);
		}
	});

	/* The reading player started: loaded panel players pause. */
	document.addEventListener('rrt:player', function (e) {
		if (e.detail) { if (e.detail.type === 'play') { pausePanels(null); } }
	});

	function init() {
		Array.prototype.forEach.call(document.querySelectorAll('.rrt-embed[data-rrt-embed]'), ready);
	}

	window.RRTEmbeds = { init: init, load: load, pauseAll: function () { pausePanels(null); }, loaded: loaded, safeSrc: safeSrc };

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
}());
