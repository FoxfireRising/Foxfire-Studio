/*
 * Foxfire Studio: the stronger cleanup (1.16.0).
 *
 * Runs DeepFilterNet 3 (MIT or Apache-2.0) over a finished reading. It is a
 * speech enhancement model a step up from RNNoise: in the research behind
 * this release (docs/RESEARCH.md) it took a ringing bell and a rocking chair
 * out of real phone takes that RNNoise left audible, and the words read back
 * more truly afterwards.
 *
 * How it works, as in the model's own code (libDF and df/enhance.py):
 * a short-time Fourier transform (960-point vorbis window, 480 hop, 48 kHz),
 * 32 ERB band energies and the first 96 bins as features, each normalised by
 * a slow running mean; the encoder, the ERB decoder (a gain per band) and the
 * deep-filter decoder (a 5-tap complex filter per bin, two frames ahead) on
 * ONNX Runtime Web; gains applied above bin 96 and the filter below; then the
 * inverse transform. A cap mixes a little of the original back in, as
 * RNNoise's strengths do.
 *
 * Three things the research found and this file follows:
 * - The model judges noise partly by absolute level, so a very quiet phone
 *   take reads to it as background. The reading is lifted to a normal level
 *   first, and the level is put back afterwards.
 * - It runs in chunks of 10 s, each with 2 s of the frames before it so the
 *   model's memory settles, so a long reading never needs much memory. The
 *   result matches one pass over the whole take.
 * - The model and runtime are checked against SHA-256 fingerprints before
 *   they are used.
 *
 * The model files ship with the plugin (assets/vendor/deepfilternet3). The
 * runtime is the one the Whisper review already uses: its loader ships in
 * assets/vendor/onnxruntime-web, and its WebAssembly comes from the site's
 * Whisper files when they are there, otherwise jsDelivr, checked either way.
 *
 * Whisper never hears this processing: it listens to the untouched recording.
 *
 * Works as a background worker (new Worker('deepfilter.js')), as
 * window.RRTDeepFilter, and in Node for tests.
 */
(function (root) {
	'use strict';

	/* Translations (as in denoise.js): WordPress's wp.i18n when there is one, otherwise the English text as written. */
	var rrtI18n = (typeof wp === 'object' ? (wp ? wp.i18n : null) : null) || null;
	var __ = rrtI18n ? rrtI18n.__ : function (s) { return s; };
	var sprintf = rrtI18n ? rrtI18n.sprintf : function (f) {
		var a = arguments;
		var i = 0;
		return String(f).replace(/%(?:(\d+)\$)?([sd%])/g, function (m, pos, type) {
			if (type === '%') { return '%'; }
			var v = a[pos ? Number(pos) : ++i];
			return type === 'd' ? String(parseInt(v, 10)) : String(v);
		});
	};

	var RATE = 48000, N = 960, HOP = 480, NF = 481, NE = 32, ND = 96, ORD = 5, LA = 2, CLA = 2;
	var ALPHA = 0.99; // exp(-hop / sr / 1 s), rounded as libDF rounds it.
	var CHUNK = 1000, WARM = 200; // Frames: 10 s, and 2 s before each chunk.
	/* As denoise.js: the most the background may drop, in dB, or null for no cap. */
	var STRENGTH = { gentle: 12, medium: 20, strong: null };
	/* The level the model hears: the loud end of the speech near -20 dBFS (about -23 LUFS). */
	var LOUD_TARGET = -20;

	/* The ERB bands' widths in bins, as libDF's erb_fb(48000, 960, 32, 2). */
	function erbWidths() {
		var f2e = function (f) { return 9.265 * Math.log(1 + f / (24.7 * 9.265)); };
		var e2f = function (e) { return 24.7 * 9.265 * (Math.exp(e / 9.265) - 1); };
		var fw = RATE / N, lo = f2e(0), hi = f2e(RATE / 2), step = (hi - lo) / NE;
		var w = [], prev = 0, over = 0, sum = 0, i;
		for (i = 1; i <= NE; i++) {
			var fb = Math.round(e2f(lo + i * step) / fw), n = fb - prev - over;
			if (n < 2) { over = 2 - n; n = 2; } else { over = 0; }
			w.push(n);
			prev = fb;
		}
		w[NE - 1] += 1;
		for (i = 0; i < NE; i++) { sum += w[i]; }
		w[NE - 1] -= sum - NF;
		return w;
	}
	var WID = erbWidths();
	var BAND = new Int32Array(NF);
	(function () {
		var k = 0;
		for (var b = 0; b < NE; b++) { for (var j = 0; j < WID[b]; j++) { BAND[k++] = b; } }
	})();
	var WIN = new Float64Array(N);
	(function () {
		for (var i = 0; i < N; i++) {
			var s = Math.sin(0.5 * Math.PI * (i + 0.5) / (N / 2));
			WIN[i] = Math.sin(0.5 * Math.PI * s * s);
		}
	})();
	var WNORM = 1 / (N * N / (2 * HOP));

	/*
	 * A mixed-radix FFT for n = 960 (4 x 4 x 4 x 3 x 5), planned once:
	 * a digit-reversal order, then one pass per radix with its twiddles.
	 */
	function plan(n) {
		var perm = new Int32Array(n), levels = [];
		function radix(len) { return len % 4 === 0 ? 4 : (len % 2 === 0 ? 2 : (len % 3 === 0 ? 3 : 5)); }
		(function order(off, stride, len, oo) {
			if (len === 1) { perm[oo] = off; return; }
			var p = radix(len), m = len / p;
			for (var q = 0; q < p; q++) { order(off + q * stride, stride * p, m, oo + q * m); }
		})(0, 1, n, 0);
		for (var len = n, list = []; len > 1; len /= radix(len)) { list.push(len); }
		list.reverse().forEach(function (len) {
			var p = radix(len), m = len / p, q, k, r;
			var tc = new Float64Array(p * m), ts = new Float64Array(p * m), dc = new Float64Array(p * p), ds = new Float64Array(p * p);
			for (q = 0; q < p; q++) {
				for (k = 0; k < m; k++) { var a = -2 * Math.PI * q * k / len; tc[q * m + k] = Math.cos(a); ts[q * m + k] = Math.sin(a); }
				for (r = 0; r < p; r++) { var b = -2 * Math.PI * q * r / p; dc[q * p + r] = Math.cos(b); ds[q * p + r] = Math.sin(b); }
			}
			levels.push({ len: len, p: p, m: m, tc: tc, ts: ts, dc: dc, ds: ds });
		});
		var tr = new Float64Array(5), ti = new Float64Array(5);
		/* Forward transform of (re, im) into (ore, oim). */
		return function (re, im, ore, oim) {
			var i, L, b0, k, q, r;
			for (i = 0; i < n; i++) { ore[i] = re[perm[i]]; oim[i] = im[perm[i]]; }
			for (L = 0; L < levels.length; L++) {
				var lv = levels[L], len = lv.len, p = lv.p, m = lv.m, tc = lv.tc, ts = lv.ts, dc = lv.dc, ds = lv.ds;
				for (b0 = 0; b0 < n; b0 += len) {
					for (k = 0; k < m; k++) {
						for (q = 0; q < p; q++) {
							var idx = b0 + q * m + k, w = q * m + k, xr = ore[idx], xi = oim[idx];
							tr[q] = xr * tc[w] - xi * ts[w];
							ti[q] = xr * ts[w] + xi * tc[w];
						}
						for (r = 0; r < p; r++) {
							var sr = 0, si = 0;
							for (q = 0; q < p; q++) {
								var c = dc[q * p + r], s = ds[q * p + r];
								sr += tr[q] * c - ti[q] * s;
								si += tr[q] * s + ti[q] * c;
							}
							ore[b0 + r * m + k] = sr;
							oim[b0 + r * m + k] = si;
						}
					}
				}
			}
		};
	}
	var fft = plan(N);

	/*
	 * Two frames at a time (one as the real part, one as the imaginary part),
	 * which halves the work of a real-input transform. Frame t covers samples
	 * (t - 1) * HOP to (t + 1) * HOP, as libDF's analysis does.
	 */
	var zr = new Float64Array(N), zi = new Float64Array(N), Zr = new Float64Array(N), Zi = new Float64Array(N);
	function analyse(x, gain, t0, count, outRe, outIm) {
		for (var j = 0; j < count; j += 2) {
			var tA = t0 + j, two = j + 1 < count, i;
			for (i = 0; i < N; i++) {
				var pa = (tA - 1) * HOP + i, pb = pa + HOP;
				zr[i] = (pa >= 0 && pa < x.length ? x[pa] * gain : 0) * WIN[i];
				zi[i] = two ? (pb >= 0 && pb < x.length ? x[pb] * gain : 0) * WIN[i] : 0;
			}
			fft(zr, zi, Zr, Zi);
			var oa = j * NF, ob = (j + 1) * NF;
			for (var k = 0; k < NF; k++) {
				var nk = k === 0 ? 0 : N - k;
				var ar = Zr[k], ai = Zi[k], br = Zr[nk], bi = -Zi[nk];
				outRe[oa + k] = (ar + br) * 0.5 * WNORM;
				outIm[oa + k] = (ai + bi) * 0.5 * WNORM;
				if (two) {
					outRe[ob + k] = (ai - bi) * 0.5 * WNORM;
					outIm[ob + k] = (br - ar) * 0.5 * WNORM;
				}
			}
		}
	}
	/* The inverse of two frames at once, overlap-added into y at frames tA and tA + 1. */
	function synthesise(Yr, Yi, row, two, y, tA) {
		var i, k;
		// z = X_a + i * X_b over the whole circle (each half-spectrum mirrored), then an inverse by conjugation.
		for (k = 0; k < N; k++) {
			var kk = k < NF ? k : N - k, sgn = k < NF ? 1 : -1;
			var ar = Yr[row + kk], ai = sgn * Yi[row + kk];
			var br = two ? Yr[row + NF + kk] : 0, bi = two ? sgn * Yi[row + NF + kk] : 0;
			// The first and last bins of a real signal's spectrum are real (an inverse real FFT ignores their imaginary parts).
			if (kk === 0 || kk === N / 2) { ai = 0; bi = 0; }
			zr[k] = ar - bi;
			zi[k] = -(ai + br); // conjugated for the inverse
		}
		fft(zr, zi, Zr, Zi);
		var o = tA * HOP;
		for (i = 0; i < N; i++) {
			if (o + i < y.length) { y[o + i] += Zr[i] * WIN[i]; }
			if (two ? o + HOP + i < y.length : false) { y[o + HOP + i] += -Zi[i] * WIN[i]; }
		}
	}

	function dbOf(x) { return 10 * Math.log10(x + 1e-20); }

	/* The same room measures as denoise.js, so the Room line reads the same for either engine. */
	function roomOf(x, rate) {
		var hop = Math.round(rate * 0.02), nF = Math.floor(x.length / hop);
		if (nF < 50) { return null; }
		var d = new Float32Array(nF);
		for (var f = 0; f < nF; f++) {
			var s = 0;
			for (var i = f * hop; i < (f + 1) * hop; i++) { s += x[i] * x[i]; }
			d[f] = dbOf(s / hop + 1e-12);
		}
		var sorted = Array.prototype.slice.call(d).sort(function (a, b) { return a - b; });
		return { floor: sorted[Math.floor(nF * 0.1)], loud: sorted[Math.floor(nF * 0.9)], snr: sorted[Math.floor(nF * 0.9)] - sorted[Math.floor(nF * 0.1)], frames: d };
	}
	function quietestDb(x, rate) {
		var w = Math.round(rate * 0.5), hop = Math.round(rate * 0.1), best = Infinity;
		if (x.length < w) { var t = 0; for (var k = 0; k < x.length; k++) { t += x[k] * x[k]; } return dbOf(t / Math.max(1, x.length)); }
		var cum = new Float64Array(x.length + 1);
		for (var i = 0; i < x.length; i++) { cum[i + 1] = cum[i] + x[i] * x[i]; }
		for (var s = 0; s + w <= x.length; s += hop) { var v = (cum[s + w] - cum[s]) / w; if (v < best) { best = v; } }
		return dbOf(best);
	}

	/* How far to lift the reading for the model, in dB: the loud end of its 20 ms frames to LOUD_TARGET. */
	function liftFor(x) {
		var rm = roomOf(x, RATE);
		if (!rm || !isFinite(rm.loud) || rm.loud < -110) { return 0; }
		return Math.max(-20, Math.min(60, LOUD_TARGET - rm.loud));
	}

	/* Loads the three parts of the model from their bytes. Resolves to the model. */
	function create(ort, bytes) {
		var so = { executionProviders: ['wasm'], graphOptimizationLevel: 'all' };
		return Promise.all([
			ort.InferenceSession.create(bytes.enc, so),
			ort.InferenceSession.create(bytes.erb_dec, so),
			ort.InferenceSession.create(bytes.df_dec, so)
		]).then(function (s) { return { ort: ort, enc: s[0], erb: s[1], dfd: s[2] }; });
	}

	/* One chunk through the network: gains (T x 32) and filter taps (T x 96 x 5, complex). */
	function net(M, fe, fr, fi, T) {
		var ort = M.ort;
		// pad_feat: frame t hears the features of frame t + 2.
		var e = new Float32Array(T * NE), s = new Float32Array(2 * T * ND);
		for (var t = 0; t + CLA < T; t++) {
			e.set(fe.subarray((t + CLA) * NE, (t + CLA + 1) * NE), t * NE);
			s.set(fr.subarray((t + CLA) * ND, (t + CLA + 1) * ND), t * ND);
			s.set(fi.subarray((t + CLA) * ND, (t + CLA + 1) * ND), T * ND + t * ND);
		}
		return M.enc.run({
			feat_erb: new ort.Tensor('float32', e, [1, 1, T, NE]),
			feat_spec: new ort.Tensor('float32', s, [1, 2, T, ND])
		}).then(function (o) {
			return Promise.all([
				M.erb.run({ emb: o.emb, e3: o.e3, e2: o.e2, e1: o.e1, e0: o.e0 }),
				M.dfd.run({ emb: o.emb, c0: o.c0 })
			]).then(function (r) {
				return { m: r[0].m.data, c: r[1].coefs.data, lsnr: o.lsnr.data };
			});
		});
	}

	/*
	 * The whole job on 48 kHz audio. opts: strength ('gentle' | 'medium' |
	 * 'strong'), onProgress(fraction), maxRtf (give up after the first chunk
	 * if this device takes longer than that share of the audio's length),
	 * now() (a clock in ms). Resolves to { data, report } like denoise.js.
	 */
	function denoise(M, x, opts) {
		opts = opts || {};
		var now = opts.now || function () { return Date.now(); };
		var t0 = now();
		var cap = STRENGTH.hasOwnProperty(opts.strength) ? STRENGTH[opts.strength] : STRENGTH.medium;
		var lim = cap === null ? 0 : Math.pow(10, -cap / 20);
		var liftDb = liftFor(x), g = Math.pow(10, liftDb / 20);
		var L = x.length, T = Math.ceil((L + N) / HOP);
		var y = new Float32Array((T + 2) * HOP + N);
		var speech = new Uint8Array(T);

		// Running norm states, and their snapshot at the next chunk's warm-up start.
		var ms = new Float64Array(NE), us = new Float64Array(ND), snapMs = null, snapUs = null, b, k;
		for (b = 0; b < NE; b++) { ms[b] = -60 + b * (-30 / (NE - 1)); }
		for (k = 0; k < ND; k++) { us[k] = 0.001 + k * (-0.0009 / (ND - 1)); }
		var e = new Float64Array(NE);

		function chunk(a) {
			if (a >= T) { return Promise.resolve(); }
			var s0 = Math.max(0, a - WARM), kk = Math.min(CHUNK, T - a), e0 = Math.min(T, a + kk + CLA + 4), n = e0 - s0;
			var Xr = new Float32Array(n * NF), Xi = new Float32Array(n * NF);
			analyse(x, g, s0, n, Xr, Xi);
			if (snapMs) { ms.set(snapMs); us.set(snapUs); }
			var next = a + kk - WARM, fe = new Float32Array(n * NE), fr = new Float32Array(n * ND), fi = new Float32Array(n * ND);
			for (var t = 0; t < n; t++) {
				if (s0 + t === Math.max(0, next)) { snapMs = new Float64Array(ms); snapUs = new Float64Array(us); }
				var row = t * NF, kx;
				e.fill(0);
				for (kx = 0; kx < NF; kx++) { var r = Xr[row + kx], im = Xi[row + kx]; e[BAND[kx]] += r * r + im * im; }
				for (b = 0; b < NE; b++) {
					var v = 10 * Math.log10(e[b] / WID[b] + 1e-10);
					ms[b] = v * (1 - ALPHA) + ms[b] * ALPHA;
					fe[t * NE + b] = (v - ms[b]) / 40;
				}
				for (kx = 0; kx < ND; kx++) {
					var rr = Xr[row + kx], ii = Xi[row + kx];
					us[kx] = Math.sqrt(rr * rr + ii * ii) * (1 - ALPHA) + us[kx] * ALPHA;
					var q = Math.sqrt(us[kx]);
					fr[t * ND + kx] = rr / q;
					fi[t * ND + kx] = ii / q;
				}
			}
			return net(M, fe, fr, fi, n).then(function (o) {
				var off = a - s0, Yr = new Float32Array(2 * NF), Yi = new Float32Array(2 * NF);
				for (var j = 0; j < kk; j += 2) {
					var two = j + 1 < kk;
					for (var h = 0; h < (two ? 2 : 1); h++) {
						var tl = off + j + h, rowX = tl * NF, rowY = h * NF, kx2, q2;
						speech[a + j + h] = o.lsnr[tl] > 15 ? 1 : 0;
						for (kx2 = ND; kx2 < NF; kx2++) {
							var gg = o.m[tl * NE + BAND[kx2]];
							Yr[rowY + kx2] = Xr[rowX + kx2] * gg;
							Yi[rowY + kx2] = Xi[rowX + kx2] * gg;
						}
						for (kx2 = 0; kx2 < ND; kx2++) {
							var sr = 0, si = 0;
							for (q2 = 0; q2 < ORD; q2++) {
								var src = tl + q2 - (ORD - 1 - LA);
								if (src < 0 || src >= n) { continue; }
								var ci = ((tl * ND + kx2) * ORD + q2) * 2, cr = o.c[ci], cim = o.c[ci + 1];
								var xr = Xr[src * NF + kx2], xi = Xi[src * NF + kx2];
								sr += xr * cr - xi * cim;
								si += xr * cim + xi * cr;
							}
							Yr[rowY + kx2] = sr;
							Yi[rowY + kx2] = si;
						}
						if (lim) {
							for (kx2 = 0; kx2 < NF; kx2++) {
								Yr[rowY + kx2] = Xr[rowX + kx2] * lim + Yr[rowY + kx2] * (1 - lim);
								Yi[rowY + kx2] = Xi[rowX + kx2] * lim + Yi[rowY + kx2] * (1 - lim);
							}
						}
					}
					synthesise(Yr, Yi, 0, two, y, a + j);
				}
				if (opts.onProgress) { opts.onProgress((a + kk) / T); }
				if (a === 0 && opts.maxRtf && T > CHUNK) {
					var rtf = (now() - t0) / 1000 / (kk * HOP / RATE);
					if (rtf > opts.maxRtf) {
						var err = new Error(__( 'this device was too slow for it', 'foxfire-studio' ));
						err.slow = rtf;
						throw err;
					}
				}
				return chunk(a + kk);
			});
		}

		return chunk(0).then(function () {
			var out = new Float32Array(L), d = N - HOP, back = 1 / g;
			for (var i = 0; i < L; i++) { out[i] = y[i + d] * back; }
			var before = roomOf(x, RATE), after = roomOf(out, RATE);
			var report = { engine: 'stronger', strength: cap === null ? 'strong' : opts.strength || 'medium', liftDb: liftDb };
			if (before && after) {
				report.snrBefore = before.snr;
				report.snrAfter = after.snr;
				report.floorBefore = before.floor;
				report.floorAfter = after.floor;
				report.dropDb = before.floor - after.floor;
				var hopF = Math.round(RATE * 0.02), sIn = 0, sOut = 0, cnt = 0;
				for (var f = 0; f < before.frames.length; f++) {
					var tt = Math.floor((f * hopF + d) / HOP);
					if (speech[tt] && before.frames[f] > before.loud - 15) {
						sIn += Math.pow(10, before.frames[f] / 10);
						sOut += Math.pow(10, after.frames[f] / 10);
						cnt++;
					}
				}
				report.speechChangeDb = cnt ? dbOf(sOut) - dbOf(sIn) : null;
			}
			report.quietBefore = quietestDb(x, RATE);
			report.quietAfter = quietestDb(out, RATE);
			var sp = 0;
			for (var z = 0; z < T; z++) { sp += speech[z]; }
			report.speechShare = sp / Math.max(1, T);
			report.seconds = (now() - t0) / 1000;
			return { data: out, report: report };
		});
	}

	function hex(buf) {
		var u = new Uint8Array(buf), s = '';
		for (var i = 0; i < u.length; i++) { s += (u[i] < 16 ? '0' : '') + u[i].toString(16); }
		return s;
	}
	/* Fetches the first address that answers with bytes matching the fingerprint. */
	function fetchChecked(urls, sha, what) {
		var list = (urls || []).filter(Boolean), i = 0, firstErr = null;
		function next() {
			if (i >= list.length) { throw firstErr || new Error(sprintf( /* translators: %s: which file. */ __( '%s could not be loaded', 'foxfire-studio' ), what )); }
			var u = list[i++];
			return fetch(u, { credentials: 'omit' }).then(function (r) {
				if (!r.ok) { throw new Error(sprintf( /* translators: 1: which file, 2: HTTP status. */ __( '%1$s could not be loaded (HTTP %2$s)', 'foxfire-studio' ), what, r.status )); }
				return r.arrayBuffer();
			}).then(function (buf) {
				if (!crypto || !crypto.subtle) { throw new Error(__( 'this browser cannot check fingerprints here (a secure https page is needed)', 'foxfire-studio' )); }
				return crypto.subtle.digest('SHA-256', buf).then(function (h) {
					if (hex(h) !== String(sha).toLowerCase()) { throw new Error(sprintf( /* translators: %s: which file. */ __( '%s did not match its expected fingerprint', 'foxfire-studio' ), what )); }
					return buf;
				});
			}).catch(function (err) {
				if (!firstErr) { firstErr = err; }
				return next();
			});
		}
		return next();
	}

	var api = {
		RATE: RATE,
		STRENGTH: STRENGTH,
		create: create,
		denoise: denoise,
		_internal: { plan: plan, analyse: analyse, synthesise: synthesise, liftFor: liftFor, erbWidths: erbWidths, roomOf: roomOf }
	};
	root.RRTDeepFilter = api;
	if (typeof module !== 'undefined' && module.exports) { module.exports = api; }

	/*
	 * As a background worker. Message: { id, cfg, data (Float32Array, 48 kHz),
	 * strength, maxRtf }. cfg: ort (the loader's address), glue and wasm
	 * (addresses to try for the runtime's two files), glueSha, wasmSha, model
	 * (the folder of the model's files) and models ({ enc, erb_dec, df_dec }:
	 * each a file name and its SHA-256). Replies { id, progress } along the
	 * way, then { id, data, report } or { id, error }.
	 */
	if (typeof window === 'undefined' && typeof self !== 'undefined' && typeof self.postMessage === 'function' && typeof importScripts === 'function') {
		var ready = null;
		var load = function (cfg) {
			importScripts(cfg.ort);
			var ort = self.ort;
			ort.env.wasm.numThreads = 1; // No shared memory is needed, so no special server headers either.
			ort.env.wasm.proxy = false;
			return Promise.all([
				fetchChecked(cfg.glue, cfg.glueSha, __( 'The cleanup runtime', 'foxfire-studio' )),
				fetchChecked(cfg.wasm, cfg.wasmSha, __( 'The cleanup runtime', 'foxfire-studio' ))
			]).then(function (rt) {
				ort.env.wasm.wasmPaths = { mjs: URL.createObjectURL(new Blob([rt[0]], { type: 'text/javascript' })) };
				ort.env.wasm.wasmBinary = rt[1];
				var names = ['enc', 'erb_dec', 'df_dec'];
				return Promise.all(names.map(function (nm) {
					return fetchChecked([cfg.model + cfg.models[nm].file], cfg.models[nm].sha256, __( 'The stronger cleanup model', 'foxfire-studio' ));
				})).then(function (bufs) {
					return create(ort, { enc: new Uint8Array(bufs[0]), erb_dec: new Uint8Array(bufs[1]), df_dec: new Uint8Array(bufs[2]) });
				});
			});
		};
		self.onmessage = function (ev) {
			var d = ev.data;
			if (!ready) {
				ready = Promise.resolve().then(function () { return load(d.cfg); });
				ready.catch(function () { ready = null; });
			}
			ready.then(function (M) {
				return denoise(M, d.data, {
					strength: d.strength,
					maxRtf: d.maxRtf,
					now: function () { return self.performance ? self.performance.now() : Date.now(); },
					onProgress: function (p) { self.postMessage({ id: d.id, progress: p }); }
				});
			}).then(function (r) {
				self.postMessage({ id: d.id, data: r.data, report: r.report }, [r.data.buffer]);
			}).catch(function (err) {
				self.postMessage({ id: d.id, error: String(err && err.message || err), slow: err && err.slow ? err.slow : 0 });
			});
		};
	}
})(typeof window !== 'undefined' ? window : (typeof self !== 'undefined' ? self : this));
