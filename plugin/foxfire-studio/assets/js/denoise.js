/*
 * Foxfire Studio: speech-model noise reduction (0.6.0).
 *
 * Runs RNNoise, a small neural network trained to tell speech from noise,
 * over a finished reading. It works on 48 kHz audio in 10 ms frames and
 * handles uneven noise that a learned noise profile cannot: a fan that
 * changes speed, traffic going past, a chair.
 *
 * The model is one WebAssembly file (rnnoise.wasm from @jitsi/rnnoise-wasm,
 * about 110 KB). Its SHA-256 fingerprint is checked every time it loads,
 * before it is compiled, whether it comes from this site or from jsDelivr.
 * The small loader below replaces the package's own JavaScript, so nothing
 * else from the package runs.
 *
 * Strength crossfades a little of the original back in, which caps how far
 * the background can be lowered. That keeps word endings and soft consonants
 * from being swallowed, which is where speech models go wrong.
 *
 * Whisper never hears this processing: it listens to the untouched
 * recording (see DESIGN.md, section 18).
 *
 * Works as a background worker (new Worker('denoise.js')), on the page as
 * window.RRTDenoise, and in Node for tests.
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

	var FRAME = 480; // 10 ms at 48 kHz, fixed by the model.
	var RATE = 48000;
	var SCALE = 32768; // The model expects 16-bit sample values.

	/*
	 * How much of the original is mixed back in, as the most the background
	 * can drop, in dB. Strong lets the model do everything it wants.
	 */
	var STRENGTH = { gentle: 12, medium: 20, strong: null };

	/*
	 * The minified export names of rnnoise.wasm 0.2.1. They belong to that
	 * exact file, which the fingerprint check guarantees.
	 */
	var EX = { memory: 'c', ctors: 'd', create: 'f', malloc: 'g', destroy: 'h', free: 'i', processFrame: 'j' };

	function db(x) { return 10 * Math.log10(x + 1e-20); }
	function fromDb(d) { return Math.pow(10, d / 20); }

	function hex(buf) {
		var b = new Uint8Array(buf), s = '';
		for (var i = 0; i < b.length; i++) { s += (b[i] < 16 ? '0' : '') + b[i].toString(16); }
		return s;
	}
	function sha256(bytes) {
		var c = (typeof crypto !== 'undefined' && crypto.subtle) ? crypto : null;
		if (!c) { return Promise.reject(new Error(__( 'This browser cannot check the noise model\'s fingerprint here (a secure https page is needed).', 'foxfire-studio' ))); }
		return c.subtle.digest('SHA-256', bytes).then(hex);
	}

	/* Compiles the model after checking its fingerprint. Resolves to { exports }. */
	function load(bytes, expect) {
		return sha256(bytes).then(function (got) {
			if (expect && got !== String(expect).toLowerCase()) {
				throw new Error(__( 'The noise model did not match its expected fingerprint, so it was not used.', 'foxfire-studio' ));
			}
			var mem = null;
			var imports = {
				a: {
					// emscripten_resize_heap: grow the memory when the model asks.
					a: function (size) {
						size = size >>> 0;
						var have = mem.buffer.byteLength;
						if (size > 2147483648) { return 0; }
						var want = Math.min(2147483648, Math.max(size, Math.ceil(have * 1.2)));
						try { mem.grow(Math.ceil((want - have) / 65536)); return 1; } catch (e) { return 0; }
					},
					// emscripten_memcpy_big
					b: function (dest, src, num) {
						var u8 = new Uint8Array(mem.buffer);
						u8.copyWithin(dest, src, src + num);
					}
				}
			};
			return WebAssembly.instantiate(bytes, imports).then(function (r) {
				var ex = r.instance.exports;
				mem = ex[EX.memory];
				ex[EX.ctors]();
				return { exports: ex, memory: mem };
			});
		});
	}

	/* The 20 ms frame levels the Room line has always used: loud (90th) and quiet (10th) percentiles. */
	function roomOf(x, rate) {
		var hop = Math.round(rate * 0.02), nF = Math.floor(x.length / hop);
		if (nF < 50) { return null; }
		var d = new Float32Array(nF);
		for (var f = 0; f < nF; f++) {
			var s = 0;
			for (var i = f * hop; i < (f + 1) * hop; i++) { s += x[i] * x[i]; }
			d[f] = db(s / hop + 1e-12);
		}
		var sorted = Array.prototype.slice.call(d).sort(function (a, b) { return a - b; });
		var floor = sorted[Math.floor(nF * 0.1)], loud = sorted[Math.floor(nF * 0.9)];
		return { floor: floor, loud: loud, snr: loud - floor, frames: d };
	}
	/* The background as ACX measures it: the RMS of the quietest half second. */
	function quietestDb(x, rate) {
		var w = Math.round(rate * 0.5), hop = Math.round(rate * 0.1), best = Infinity;
		if (x.length < w) { var t = 0; for (var k = 0; k < x.length; k++) { t += x[k] * x[k]; } return db(t / Math.max(1, x.length)); }
		var cum = new Float64Array(x.length + 1);
		for (var i = 0; i < x.length; i++) { cum[i + 1] = cum[i] + x[i] * x[i]; }
		for (var s = 0; s + w <= x.length; s += hop) { var v = (cum[s + w] - cum[s]) / w; if (v < best) { best = v; } }
		return db(best);
	}

	/*
	 * Runs the model over the whole reading (48 kHz mono). Returns the
	 * cleaned audio, the same length and lined up sample for sample with the
	 * input, and the model's speech probability for every 10 ms frame.
	 *
	 * The model's output trails its input by one frame; that is measured
	 * once from the output itself (see lag()) and taken back out.
	 */
	function runModel(model, x, onProgress) {
		var ex = model.exports;
		var st = ex[EX.create](0);
		var bytes = FRAME * 4;
		var pIn = ex[EX.malloc](bytes), pOut = ex[EX.malloc](bytes);
		var n = x.length, nF = Math.ceil(n / FRAME) + 2;
		var wet = new Float32Array(nF * FRAME);
		var vad = new Float32Array(nF);
		try {
			for (var f = 0; f < nF; f++) {
				var heap = new Float32Array(model.memory.buffer);
				var a = f * FRAME, io = pIn >> 2, oo = pOut >> 2;
				for (var i = 0; i < FRAME; i++) {
					var p = a + i;
					heap[io + i] = p < n ? x[p] * SCALE : 0;
				}
				vad[f] = ex[EX.processFrame](st, pOut, pIn);
				heap = new Float32Array(model.memory.buffer); // The memory may have grown.
				for (i = 0; i < FRAME; i++) { wet[a + i] = heap[oo + i] / SCALE; }
				if (onProgress && f % 500 === 0) { onProgress(f / nF); }
			}
		} finally {
			ex[EX.free](pIn);
			ex[EX.free](pOut);
			ex[EX.destroy](st);
		}
		return { wet: wet, vad: vad };
	}

	/* The model's delay in samples, found by matching its output to its input. */
	function lag(x, wet) {
		var best = 0, bestV = -Infinity, n = Math.min(x.length, wet.length - 2 * FRAME);
		for (var d = 0; d <= 2 * FRAME; d += 1) {
			var s = 0, step = Math.max(1, Math.floor(n / 200000));
			for (var i = 0; i < n; i += step) { s += x[i] * wet[i + d]; }
			if (s > bestV) { bestV = s; best = d; }
		}
		return best;
	}

	/*
	 * Steady tones in the pauses (1.16.0): a bell, a hum with overtones, a
	 * whine. Averages the spectrum of up to 150 quiet 10 ms frames the model
	 * calls non-speech (2048-point windows around them), then counts narrow
	 * peaks standing 10 dB or more above the spectrum around them, from
	 * 300 Hz to 16 kHz. Broad noise (hiss, a fan, traffic) has none. The
	 * review suggests the stronger cleanup when there are two or more.
	 */
	var TONE_N = 2048;
	function fft2(re, im) {
		var n = re.length, i, j, k, len;
		for (i = 1, j = 0; i < n; i++) {
			var bit = n >> 1;
			for (; j & bit; bit >>= 1) { j ^= bit; }
			j ^= bit;
			if (i < j) { var t = re[i]; re[i] = re[j]; re[j] = t; t = im[i]; im[i] = im[j]; im[j] = t; }
		}
		for (len = 2; len <= n; len <<= 1) {
			var ang = -2 * Math.PI / len, wr = Math.cos(ang), wi = Math.sin(ang);
			for (i = 0; i < n; i += len) {
				var cr = 1, ci = 0;
				for (k = 0; k < len / 2; k++) {
					var a = i + k, b = a + len / 2;
					var xr = re[b] * cr - im[b] * ci, xi = re[b] * ci + im[b] * cr;
					re[b] = re[a] - xr; im[b] = im[a] - xi;
					re[a] += xr; im[a] += xi;
					var nr = cr * wr - ci * wi;
					ci = cr * wi + ci * wr;
					cr = nr;
				}
			}
		}
	}
	function tones(x, vad, rate) {
		var levels = [], f, i;
		for (f = 0; f < vad.length; f++) {
			var a = f * FRAME, s = 0;
			if (a + FRAME > x.length) { break; }
			for (i = a; i < a + FRAME; i++) { s += x[i] * x[i]; }
			levels.push(db(s / FRAME + 1e-12));
		}
		if (levels.length < 100) { return 0; }
		var sorted = levels.slice().sort(function (p, q) { return p - q; });
		var quiet = sorted[Math.floor(levels.length * 0.3)];
		var picks = [];
		for (f = 0; f < levels.length; f++) {
			if (vad[f] < 0.2 && levels[f] <= quiet && f * FRAME >= TONE_N && f * FRAME + TONE_N < x.length) { picks.push(f); }
		}
		if (picks.length < 20) { return 0; }
		var step = Math.max(1, Math.floor(picks.length / 150));
		var spec = new Float64Array(TONE_N / 2), re = new Float64Array(TONE_N), im = new Float64Array(TONE_N), used = 0;
		for (var p = 0; p < picks.length; p += step) {
			var c = picks[p] * FRAME + FRAME / 2 - TONE_N / 2;
			for (i = 0; i < TONE_N; i++) { re[i] = x[c + i] * (0.5 - 0.5 * Math.cos(2 * Math.PI * i / TONE_N)); im[i] = 0; }
			fft2(re, im);
			for (i = 0; i < TONE_N / 2; i++) { spec[i] += re[i] * re[i] + im[i] * im[i]; }
			used++;
		}
		var L = new Float64Array(TONE_N / 2);
		for (i = 0; i < L.length; i++) { L[i] = db(spec[i] / used); }
		var bin = rate / TONE_N, lo = Math.ceil(300 / bin), hi = Math.min(L.length - 16, Math.floor(16000 / bin)), count = 0, last = -10;
		for (i = lo; i <= hi; i++) {
			if (!(L[i] >= L[i - 1] && L[i] >= L[i + 1])) { continue; }
			var around = [];
			for (var k = i - 15; k <= i + 15; k++) { if (Math.abs(k - i) > 2) { around.push(L[k]); } }
			around.sort(function (p2, q2) { return p2 - q2; });
			if (L[i] - around[Math.floor(around.length / 2)] >= 10 && i - last > 3) { count++; last = i; }
		}
		return count;
	}

	/*
	 * The whole job on 48 kHz audio. strength: 'gentle' | 'medium' | 'strong'.
	 * Returns { data, report } where report holds the background and speech
	 * levels before and after, for the Room line.
	 */
	function denoise(model, input, strength, onProgress) {
		var x = input;
		var m = runModel(model, x, onProgress);
		var d = lag(x, m.wet);
		var cap = STRENGTH.hasOwnProperty(strength) ? STRENGTH[strength] : STRENGTH.medium;
		var dry = cap === null ? 0 : fromDb(-cap);
		var out = new Float32Array(x.length);
		// A crossfade toward the original: the voice keeps its level, and the
		// background can drop by at most the strength's cap.
		for (var i = 0; i < x.length; i++) {
			var w = m.wet[i + d];
			out[i] = w + dry * (x[i] - w);
		}

		var before = roomOf(x, RATE), after = roomOf(out, RATE);
		var report = { engine: 'standard', strength: cap === null ? 'strong' : strength, lagSamples: d };
		if (before && after) {
			report.snrBefore = before.snr;
			report.snrAfter = after.snr;
			report.floorBefore = before.floor;
			report.floorAfter = after.floor;
			report.dropDb = before.floor - after.floor;
			// The voice itself should hardly move: compare the frames where the model hears speech.
			var hopF = Math.round(RATE * 0.02), sIn = 0, sOut = 0, cnt = 0;
			for (var f = 0; f < before.frames.length; f++) {
				var v = Math.max(m.vad[Math.floor((f * hopF + d) / FRAME)] || 0, m.vad[Math.floor((f * hopF + d) / FRAME) + 1] || 0);
				if (v > 0.9 && before.frames[f] > before.loud - 15) {
					sIn += Math.pow(10, before.frames[f] / 10);
					sOut += Math.pow(10, after.frames[f] / 10);
					cnt++;
				}
			}
			report.speechChangeDb = cnt ? db(sOut) - db(sIn) : null;
		}
		report.quietBefore = quietestDb(x, RATE);
		report.quietAfter = quietestDb(out, RATE);
		var sp = 0;
		for (var k = 0; k < m.vad.length; k++) { if (m.vad[k] > 0.5) { sp++; } }
		report.speechShare = sp / Math.max(1, m.vad.length);
		report.tones = tones(x, m.vad, RATE);
		return { data: out, report: report };
	}

	var api = {
		RATE: RATE,
		FRAME: FRAME,
		STRENGTH: STRENGTH,
		load: load,
		denoise: denoise,
		sha256: sha256,
		_internal: { runModel: runModel, lag: lag, roomOf: roomOf, quietestDb: quietestDb, tones: tones }
	};
	root.RRTDenoise = api;
	if (typeof module !== 'undefined' && module.exports) { module.exports = api; }

	/*
	 * As a background worker. Message: { id, url, sha256, data (Float32Array,
	 * 48 kHz), strength }. Replies with { id, progress } along the way, then
	 * { id, data, report } or { id, error }. The model is fetched and checked
	 * once per worker.
	 */
	if (typeof window === 'undefined' && typeof self !== 'undefined' && typeof self.postMessage === 'function' && typeof importScripts === 'function') {
		var ready = null;
		self.onmessage = function (e) {
			var d = e.data;
			if (!ready) {
				ready = fetch(d.url, { credentials: 'omit' }).catch(function () {
					throw new Error(__( 'The noise model could not be downloaded.', 'foxfire-studio' ));
				}).then(function (r) {
					if (!r.ok) { throw new Error(sprintf( /* translators: %s: r.status. */ __( 'The noise model could not be loaded (HTTP %s).', 'foxfire-studio' ), r.status )); }
					return r.arrayBuffer();
				}).then(function (buf) { return load(buf, d.sha256); });
				ready.catch(function () { ready = null; });
			}
			ready.then(function (model) {
				var r = denoise(model, d.data, d.strength, function (p) { self.postMessage({ id: d.id, progress: p }); });
				self.postMessage({ id: d.id, data: r.data, report: r.report }, [r.data.buffer]);
			}).catch(function (err) {
				self.postMessage({ id: d.id, error: String(err && err.message || err) });
			});
		};
	}
})(typeof window !== 'undefined' ? window : (typeof self !== 'undefined' ? self : this));
