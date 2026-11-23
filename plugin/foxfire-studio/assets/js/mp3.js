/*
 * Foxfire Studio: the MP3 encoder (0.7.0).
 *
 * LAME, the standard MP3 encoder, compiled to WebAssembly by the
 * wasm-media-encoders project. It replaces lamejs, a hand translation of an
 * old LAME to JavaScript, and is about ten times faster.
 *
 * Only the encoder file is used: mp3.wasm from the wasm-media-encoders 0.7.0
 * npm package (about 130 KB). Its SHA-256 fingerprint is checked every time
 * it loads, before it is compiled, whether it comes from this site or from
 * jsDelivr. The package's own JavaScript is replaced by the small loader
 * below, so nothing else from the package runs.
 *
 * Files are constant bitrate. LAME can make smaller variable-bitrate files,
 * but this build streams its output and cannot go back to write the header
 * that tells players a variable file's length, so they would show the wrong
 * duration (a 4:00 file showed as 6:40 in testing). Constant bitrate needs
 * no such header and seeks exactly, which matters for chapters.
 *
 * Works as a background worker (new Worker('mp3.js')), on the page as
 * window.RRTMp3, and in Node for tests.
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

	var VERSION = 'wasm-media-encoders-0.7.0';
	var RATES = [8000, 11025, 12000, 16000, 22050, 24000, 32000, 44100, 48000];
	var KBPS = [32, 40, 48, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320];
	var BLOCK = 1152 * 64; // Samples handed to the encoder at a time.

	function hex(buf) {
		var b = new Uint8Array(buf), s = '';
		for (var i = 0; i < b.length; i++) { s += (b[i] < 16 ? '0' : '') + b[i].toString(16); }
		return s;
	}
	function sha256(bytes) {
		var c = (typeof crypto !== 'undefined' && crypto.subtle) ? crypto : null;
		if (!c) { return Promise.reject(new Error(__( 'This browser cannot check the MP3 encoder\'s fingerprint here (a secure https page is needed).', 'foxfire-studio' ))); }
		return c.subtle.digest('SHA-256', bytes).then(hex);
	}
	function cString(ex, p) {
		var u = new Uint8Array(ex.memory.buffer, p), s = '';
		for (var i = 0; i < u.length && u[i] !== 0; i++) { s += String.fromCharCode(u[i]); }
		return s;
	}

	/* Compiles the encoder after checking its fingerprint. Resolves to its exports. */
	function load(bytes, expect) {
		return sha256(bytes).then(function (got) {
			if (expect && got !== String(expect).toLowerCase()) {
				throw new Error(__( 'The MP3 encoder did not match its expected fingerprint, so it was not used.', 'foxfire-studio' ));
			}
			var imports = {
				env: { emscripten_notify_memory_growth: function () {} },
				wasi_snapshot_preview1: { proc_exit: function (code) { throw new Error(sprintf( /* translators: %s: code. */ __( 'The MP3 encoder stopped (%s).', 'foxfire-studio' ), code )); } }
			};
			return WebAssembly.instantiate(bytes, imports).then(function (r) {
				var ex = r.instance.exports;
				ex._initialize();
				if (cString(ex, ex.version()) !== VERSION || cString(ex, ex.mime_type()) !== 'audio/mpeg') {
					throw new Error(__( 'The MP3 encoder is not the expected version.', 'foxfire-studio' ));
				}
				return ex;
			});
		});
	}

	/*
	 * Float32 samples (-1 to 1) to a constant-bitrate MP3. Returns a
	 * Uint8Array. onProgress(fraction) is called along the way.
	 *
	 * pcm is one channel, or (1.6.0) an array of one or two channels of the
	 * same length. channels (1.6.0, optional) set to 2 with one channel makes
	 * a stereo file with that channel in both, as a voice reading needs.
	 * LAME writes stereo as joint stereo, so a voice in both channels costs
	 * almost nothing over mono at the same bitrate.
	 */
	function encode(ex, pcm, rate, kbps, onProgress, channels) {
		if (RATES.indexOf(rate) === -1) { throw new Error(sprintf( /* translators: %s: rate. */ __( 'Unsupported sample rate %s', 'foxfire-studio' ), rate )); }
		if (KBPS.indexOf(kbps) === -1) { throw new Error(sprintf( /* translators: %s: kbps. */ __( 'Unsupported bitrate %s', 'foxfire-studio' ), kbps )); }
		var src = Array.isArray(pcm) ? pcm : [pcm];
		if (!src.length || src.length > 2) { throw new Error(__( 'The MP3 encoder takes one or two channels.', 'foxfire-studio' )); }
		var nc = src.length === 2 ? 2 : (channels === 2 ? 2 : 1);
		if (nc === 2 && src.length === 1) { src = [src[0], src[0]]; }
		var total0 = src[0].length;
		if (nc === 2 && src[1].length !== total0) { throw new Error(__( 'The two channels are not the same length.', 'foxfire-studio' )); }
		// enc_init reads: channels, sample rate (uint32), then bitrate (int32), VBR quality (float, -1 for constant), output rate (0 = same).
		var p = ex.malloc(20);
		if (!p) { throw new Error(__( 'The MP3 encoder ran out of memory.', 'foxfire-studio' )); }
		var i32 = new Int32Array(ex.memory.buffer, p, 5);
		i32[0] = nc;
		i32[1] = rate;
		i32[2] = kbps;
		new Float32Array(ex.memory.buffer, p, 5)[3] = -1;
		i32[4] = 0;
		var ref = ex.enc_init(p);
		ex.free(p);
		if (!ref) { throw new Error(__( 'The MP3 encoder could not start.', 'foxfire-studio' )); }
		var parts = [], total = 0;
		function take(n) {
			if (n < 0) { throw new Error(sprintf( /* translators: %s: n. */ __( 'The MP3 encoder failed (%s).', 'foxfire-studio' ), n )); }
			if (n > 0) {
				var out = new Uint8Array(ex.memory.buffer, ex.enc_get_out_buf(ref), n).slice();
				parts.push(out);
				total += n;
			}
		}
		try {
			for (var a = 0; a < total0; a += BLOCK) {
				var len = Math.min(BLOCK, total0 - a);
				var pp = ex.enc_get_pcm(ref, len);
				if (!pp) { throw new Error(__( 'The MP3 encoder ran out of memory.', 'foxfire-studio' )); }
				/* One buffer per channel, their addresses side by side. Read after the call, since memory may have grown. */
				var chp = new Int32Array(ex.memory.buffer, pp, nc);
				for (var c = 0; c < nc; c++) {
					var dst = new Float32Array(ex.memory.buffer, chp[c], len);
					var from = src[c];
					for (var i = 0; i < len; i++) {
						var v = from[a + i];
						dst[i] = v > 1 ? 1 : (v < -1 ? -1 : v);
					}
				}
				take(ex.enc_encode(ref, len));
				if (onProgress) { onProgress(Math.min(1, (a + len) / total0)); }
			}
			take(ex.enc_flush(ref));
		} finally {
			ex.enc_free(ref);
		}
		var all = new Uint8Array(total), at = 0;
		for (var k = 0; k < parts.length; k++) { all.set(parts[k], at); at += parts[k].length; }
		return all;
	}

	var api = { VERSION: VERSION, load: load, encode: encode, sha256: sha256 };
	root.RRTMp3 = api;
	if (typeof module !== 'undefined' && module.exports) { module.exports = api; }

	/*
	 * As a background worker. Message: { id, url, sha256, pcm (Float32Array),
	 * rate, kbps, channels (1.6.0: 2 for stereo, 1 by default) }. Replies with { id, progress } along the way, then
	 * { id, data (Uint8Array) } or { id, error }. The encoder is fetched and
	 * checked once per worker, and kept for the next job.
	 */
	if (typeof window === 'undefined' && typeof self !== 'undefined' && typeof self.postMessage === 'function' && typeof importScripts === 'function') {
		var ready = null, readyUrl = '';
		self.onmessage = function (e) {
			var d = e.data;
			if (!ready || readyUrl !== d.url) {
				readyUrl = d.url;
				ready = fetch(d.url, { credentials: 'omit' }).catch(function () {
					throw new Error(__( 'The MP3 encoder could not be downloaded.', 'foxfire-studio' ));
				}).then(function (r) {
					if (!r.ok) { throw new Error(sprintf( /* translators: %s: r.status. */ __( 'The MP3 encoder could not be loaded (HTTP %s).', 'foxfire-studio' ), r.status )); }
					return r.arrayBuffer();
				}).then(function (buf) { return load(buf, d.sha256); });
				ready.catch(function () { ready = null; });
			}
			ready.then(function (ex) {
				var last = 0;
				var out = encode(ex, d.pcm, d.rate, d.kbps, function (p) {
					if (p - last >= 0.05 || p === 1) { last = p; self.postMessage({ id: d.id, progress: p }); }
				}, d.channels);
				self.postMessage({ id: d.id, data: out }, [out.buffer]);
			}).catch(function (err) {
				self.postMessage({ id: d.id, error: String(err && err.message || err) });
			});
		};
	}
})(typeof window !== 'undefined' ? window : (typeof self !== 'undefined' ? self : this));
