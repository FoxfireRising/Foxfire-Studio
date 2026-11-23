/*
 * Foxfire Studio: what this browser can do (1.30.0).
 *
 * One small check shared by the clip maker, the full-length video, the
 * studio and the Settings screen. It asks the browser for the tools
 * themselves, never for its name or version (Safari on iOS 26 reports a
 * frozen older version, and names go stale), so every message can name the
 * tool that is missing and the browsers that have it.
 *
 * window.RRTCan.now()      the answers that need no waiting.
 * window.RRTCan.check()    a promise of every answer (encoders, AAC, the
 *                          graphics chip, whether storage is kept for good).
 * window.RRTCan.why(task)  why 'clips' or 'video' cannot be made here, or ''.
 * window.RRTCan.line(a)    the "This browser" sentences for check()'s answers.
 * window.RRTCan.mount(el)  fills el with that line (it shows el when done).
 * Any [data-rrt-can] element on the page is filled by itself.
 */
(function (root) {
	'use strict';

	var rrtI18n = (typeof wp === 'object' ? (wp ? wp.i18n : null) : null) || null;
	var __ = rrtI18n ? rrtI18n.__ : function (s) { return s; };

	var C = {};

	function has(path) {
		try {
			var o = root;
			var parts = path.split('.');
			for (var i = 0; i < parts.length; i++) {
				if (o === null || o === undefined) { return false; }
				o = o[parts[i]];
			}
			return o !== null && o !== undefined;
		} catch (e) {
			return false;
		}
	}
	function media(q) {
		try { return root.matchMedia ? root.matchMedia(q).matches : false; } catch (e) { return false; }
	}

	/* The answers that need no waiting. */
	C.now = function () {
		var nav = root.navigator || {};
		var a = {
			encoder: has('VideoEncoder') && has('VideoFrame'),
			wasm: has('WebAssembly'),
			writer: has('Mediabunny'),
			opfs: has('navigator.storage.getDirectory') && has('FileSystemFileHandle.prototype.createWritable'),
			worklet: has('AudioWorkletNode'),
			fullscreen: has('document.documentElement.requestFullscreen') ? !!(root.document && root.document.fullscreenEnabled) : false,
			gpuApi: has('navigator.gpu.requestAdapter'),
			/* Touch-first: the main pointer is a finger, with no hover (phones and tablets, in any browser). */
			touch: media('(pointer: coarse)') && !media('(hover: hover)'),
			/* The browser's memory hint in GB (Chrome and Edge only; Safari and Firefox give none). */
			memory: typeof nav.deviceMemory === 'number' ? nav.deviceMemory : 0
		};
		/* Clips need a video encoder and WebAssembly (the AAC add-on and the sound engine); the page loads the MP4 writer (writer). */
		a.clips = a.encoder && a.wasm;
		/* The full video also writes into the browser's private storage as it goes. */
		a.video = a.clips && a.opfs;
		return a;
	};

	/*
	 * Phones (1.31.0), decided by how the device is used and how much memory
	 * it says it has, never by the browser's name. a: now()'s answers (or
	 * any object with touch and memory); left out, they are asked.
	 *
	 * phone(a)   touch-first: iPhones, iPads, Android phones and tablets, in
	 *            any browser. They get the phone rules: the standard model
	 *            under Automatic, the lighter review, 24-bit takes.
	 * lowMem(a)  a memory hint under 4 GB, or a touch-first device that gives
	 *            no hint (Safari and Firefox never do). Whisper then uses its
	 *            lighter engine build on one thread.
	 */
	C.phone = function (a) {
		a = a || C.now();
		return !!a.touch;
	};
	C.lowMem = function (a) {
		a = a || C.now();
		return a.memory ? a.memory < 4 : !!a.touch;
	};

	var wait = null;
	/* Every answer, asked once a page. */
	C.check = function () {
		if (wait) { return wait; }
		var a = C.now();
		function safe(p, dflt) {
			return Promise.resolve().then(function () { return p(); }).then(null, function () { return dflt; });
		}
		var h264 = a.encoder ? safe(function () {
			return root.VideoEncoder.isConfigSupported({ codec: 'avc1.42e028', width: 640, height: 360, bitrate: 1000000, framerate: 30 }).then(function (r) { return !!r.supported; });
		}, false) : Promise.resolve(false);
		var aac = has('AudioEncoder') ? safe(function () {
			return root.AudioEncoder.isConfigSupported({ codec: 'mp4a.40.2', sampleRate: 48000, numberOfChannels: 2, bitrate: 128000 }).then(function (r) { return !!r.supported; });
		}, false) : Promise.resolve(false);
		var gpu = a.gpuApi ? safe(function () {
			var t = new Promise(function (res) { root.setTimeout(function () { res(null); }, 5000); });
			return Promise.race([root.navigator.gpu.requestAdapter({ powerPreference: 'high-performance' }), t]).then(function (ad) {
				if (!ad) { return false; }
				var soft = ad.isFallbackAdapter || (ad.info ? ad.info.isFallbackAdapter : false);
				return soft ? false : !!(ad.features ? ad.features.has('shader-f16') : false);
			});
		}, false) : Promise.resolve(false);
		var kept = has('navigator.storage.persisted') ? safe(function () { return root.navigator.storage.persisted(); }, false) : Promise.resolve(false);
		wait = Promise.all([h264, aac, gpu, kept]).then(function (r) {
			a.h264 = r[0];
			a.aacOwn = r[1];
			/* AAC can be made: the browser's own, or the add-on (FFmpeg's encoder as WebAssembly). */
			a.aac = r[1] || a.wasm;
			a.gpu = r[2];
			a.persisted = !!r[3];
			if (!a.h264) { a.clips = false; a.video = false; }
			return a;
		});
		return wait;
	};

	var BROWSERS = __( 'Chrome, Edge, Safari and Firefox on a computer can.', 'foxfire-studio' );

	/* Why 'clips' or 'video' cannot be made in this browser, or '' when they can. */
	C.why = function (task, a) {
		a = a || C.now();
		var video = 'video' === task;
		if (!a.encoder || (a.h264 === false)) {
			return (video ? __( 'This browser has no H.264 video encoder, so it cannot make the video here.', 'foxfire-studio' ) : __( 'This browser has no H.264 video encoder, so it cannot make clips here.', 'foxfire-studio' )) + ' ' + BROWSERS;
		}
		if (!a.wasm) {
			return (video ? __( 'This browser cannot run WebAssembly, which the video needs for its sound.', 'foxfire-studio' ) : __( 'This browser cannot run WebAssembly, which clips need for their sound.', 'foxfire-studio' )) + ' ' + BROWSERS;
		}
		if (!a.writer && !a.forLine) {
			return __( 'The video writer did not load. Reload the page and try again.', 'foxfire-studio' );
		}
		if (video && !a.opfs) {
			return __( 'This browser cannot write a long file into its private storage, which the full video needs (Safari has none in a private window). Open this page in an ordinary window of Chrome, Edge, Safari or Firefox.', 'foxfire-studio' );
		}
		return '';
	};

	/* The "This browser" sentences, from check()'s answers. */
	C.line = function (a) {
		var out = [];
		a = Object.assign({}, a, { forLine: true });
		if (a.video) {
			out.push(a.aacOwn ? __( 'This browser makes clips and the full video, with AAC sound from its own encoder.', 'foxfire-studio' ) : __( 'This browser makes clips and the full video, with AAC sound from the AAC add-on (it has no AAC encoder of its own).', 'foxfire-studio' ));
		} else if (a.clips) {
			out.push(a.aacOwn ? __( 'This browser makes clips, with AAC sound from its own encoder.', 'foxfire-studio' ) : __( 'This browser makes clips, with AAC sound from the AAC add-on (it has no AAC encoder of its own).', 'foxfire-studio' ));
			out.push(C.why('video', a));
		} else {
			out.push(C.why('clips', a));
		}
		out.push(a.gpu ? __( 'Its graphics chip can run the larger Whisper model.', 'foxfire-studio' ) : __( 'Whisper runs on its processor (no graphics chip it can use).', 'foxfire-studio' ));
		if (!a.worklet) {
			out.push(__( 'It cannot record losslessly, so takes are recorded in its compressed format.', 'foxfire-studio' ));
		}
		if (!a.fullscreen) {
			out.push(__( 'It has no full screen for pages, so the prompter view stays inside the page.', 'foxfire-studio' ));
		}
		if (!a.persisted) {
			out.push(__( 'Takes kept only in this browser can be cleared by the browser after a long break without a visit (Safari does so after about a week), so keep takes on the site (Settings, Reader stats) or finish a reading in one sitting.', 'foxfire-studio' ));
		}
		return out.filter(function (s) { return !!s; }).join(' ');
	};

	C.mount = function (el) {
		if (!el) { return Promise.resolve(''); }
		return C.check().then(function (a) {
			var t = C.line(a);
			el.textContent = t;
			el.hidden = !t;
			return t;
		});
	};

	root.RRTCan = C;

	if (root.document) {
		var go = function () {
			Array.prototype.forEach.call(root.document.querySelectorAll('[data-rrt-can]'), function (el) { C.mount(el); });
		};
		if ('loading' === root.document.readyState) { root.document.addEventListener('DOMContentLoaded', go); } else { go(); }
	}
}(typeof window !== 'undefined' ? window : this));
