/*
 * Foxfire Studio: cleanup for a finished reading (moved here from
 * teleprompter.js in 0.25.1). Runs before the MP3 is made, in slices so the
 * page stays responsive: cleanReading( samples, rate, skipNoise ) returns
 * { report, step( budget ), finish() }. Loaded before teleprompter.js as
 * window.RRTClean, and by Node for the unit tests.
 */
(function (root) {
	/*
	 * Cleanup for a finished reading, done before the MP3 is made:
	 *   - clicks: short sharp sounds in the half second before a phrase
	 *     (jewelry or the mouth opening to breathe) are quieted
	 *   - stray sounds: bumps and creaks in the middle of pauses are quieted
	 *   - background: a steady hush (a laptop fan) is learned from the quietest
	 *     moments and gently lowered, smoothly so it never flickers
	 *   - whistles: a thin whistle tone inside an S sound is dipped, only there
	 * It also reports what it found, so setup changes can be compared.
	 */
	function makeFFT(N) {
		var bits = Math.round(Math.log2(N));
		var rev = new Uint32Array(N);
		var i;
		for (i = 0; i < N; i++) {
			var r = 0;
			var v = i;
			for (var b = 0; b < bits; b++) { r = (r << 1) | (v & 1); v >>= 1; }
			rev[i] = r;
		}
		var cos = new Float64Array(N / 2);
		var sin = new Float64Array(N / 2);
		for (i = 0; i < N / 2; i++) { cos[i] = Math.cos(2 * Math.PI * i / N); sin[i] = Math.sin(2 * Math.PI * i / N); }
		return function (re, im, inverse) {
			var k;
			for (k = 0; k < N; k++) {
				var j = rev[k];
				if (j > k) {
					var t = re[k]; re[k] = re[j]; re[j] = t;
					t = im[k]; im[k] = im[j]; im[j] = t;
				}
			}
			var sgn = inverse ? 1 : -1;
			for (var size = 2; size <= N; size <<= 1) {
				var half = size >> 1;
				var step = N / size;
				for (var st = 0; st < N; st += size) {
					for (k = 0; k < half; k++) {
						var wr = cos[k * step];
						var wi = sgn * sin[k * step];
						var a = st + k;
						var bb = a + half;
						var xr = re[bb] * wr - im[bb] * wi;
						var xi = re[bb] * wi + im[bb] * wr;
						re[bb] = re[a] - xr; im[bb] = im[a] - xi;
						re[a] += xr; im[a] += xi;
					}
				}
			}
			if (inverse) { for (k = 0; k < N; k++) { re[k] /= N; im[k] /= N; } }
		};
	}

	function cleanReading(data, rate, skipNoise) {
		var n = data.length;
		var out = new Float32Array(data);
		var report = { clicks: [], strays: [], whistles: 0, sibilants: 0, snr: null, noiseDropDb: 0 };

		// Where the speech is, in 20 ms frames.
		var HOP = Math.round(rate * 0.02);
		var nF = Math.floor(n / HOP);
		if (nF < 50) { return { report: report, step: function () { return 1; }, finish: function () { return out; } }; }
		var db = new Float32Array(nF);
		var f;
		var i;
		for (f = 0; f < nF; f++) {
			var s = 0;
			for (i = f * HOP; i < (f + 1) * HOP; i++) { s += data[i] * data[i]; }
			db[f] = 10 * Math.log10(s / HOP + 1e-12);
		}
		var sorted = Array.prototype.slice.call(db).sort(function (a, b) { return a - b; });
		var floor = sorted[Math.floor(nF * 0.1)];
		var loud = sorted[Math.floor(nF * 0.9)];
		var gate = Math.max(floor + 10, loud - 28);
		report.snr = loud - floor;
		var sp = new Uint8Array(nF);
		for (f = 0; f < nF; f++) { sp[f] = db[f] > gate ? 1 : 0; }
		var runStart = 0;
		for (f = 1; f <= nF; f++) {
			if (f === nF || sp[f] !== sp[runStart]) {
				if (!sp[runStart] && f - runStart < 8 && runStart > 0 && f < nF) {
					for (var q = runStart; q < f; q++) { sp[q] = 1; }
				}
				runStart = f;
			}
		}

		// Short dips in level, applied to a copy of the audio.
		function dip(center, halfLen, depth) {
			var ramp = Math.round(rate * 0.003);
			var a = Math.max(0, center - halfLen - ramp);
			var b = Math.min(n, center + halfLen + ramp);
			for (var p = a; p < b; p++) {
				var d = Math.abs(p - center);
				var g;
				if (d <= halfLen) { g = depth; }
				else { var x = (d - halfLen) / ramp; g = depth + (1 - depth) * (0.5 - 0.5 * Math.cos(Math.PI * Math.min(1, x))); }
				if (g < 1) { out[p] *= g; }
			}
		}

		// High-passed copy, where clicks stand out from breaths and hum.
		var hp = new Float32Array(n);
		(function () {
			var w = Math.tan(Math.PI * 2500 / rate);
			var k = Math.SQRT2;
			var norm = 1 / (1 + k * w + w * w);
			var b0 = norm;
			var b1 = -2 * norm;
			var a1 = 2 * (w * w - 1) * norm;
			var a2 = (1 - k * w + w * w) * norm;
			var x1 = 0;
			var x2 = 0;
			var y1 = 0;
			var y2 = 0;
			for (var p = 0; p < n; p++) {
				var x0 = data[p];
				var y0 = b0 * x0 + b1 * x1 + b0 * x2 - a1 * y1 - a2 * y2;
				hp[p] = y0;
				x2 = x1; x1 = x0; y2 = y1; y1 = y0;
			}
		})();

		// Clicks in the gap before each phrase (never touching the phrase itself).
		var C3 = Math.round(rate * 0.003);
		var C30 = Math.round(rate * 0.03);
		for (f = 1; f < nF; f++) {
			if (!sp[f] || sp[f - 1]) { continue; }
			var onset = f * HOP;
			var a0 = Math.max(0, onset - Math.round(rate * 0.6));
			var a1x = onset - Math.round(rate * 0.03);
			if (a1x - a0 < C30 * 2) { continue; }
			var vals = [];
			for (i = a0; i < a1x; i += 7) { vals.push(Math.abs(hp[i])); }
			vals.sort(function (x, y) { return x - y; });
			var med = vals[Math.floor(vals.length / 2)] + 1e-6;
			var lastC = -1e9;
			for (i = a0 + C30; i < a1x - C30; i++) {
				var v = Math.abs(hp[i]);
				if (v < med * 12 || i - lastC < Math.round(rate * 0.02)) { continue; }
				var mx = i;
				var e;
				for (e = i; e < Math.min(a1x, i + C3); e++) { if (Math.abs(hp[e]) > Math.abs(hp[mx])) { mx = e; } }
				var inner = 0;
				var outer = 0;
				for (e = mx - C3; e < mx + C3; e++) { inner += hp[e] * hp[e]; }
				for (e = mx - C30; e < mx + C30; e++) { outer += hp[e] * hp[e]; }
				if (inner / (outer + 1e-12) > 0.5) {
					dip(mx, Math.round(rate * 0.004), 0.12);
					report.clicks.push(mx / rate);
					lastC = mx;
					i = mx + Math.round(rate * 0.01);
				}
			}
		}

		// Stray sounds in the middle of pauses: bumps, creaks, taps.
		// Breaths lead straight into a phrase, so anything that does is left alone.
		f = 0;
		while (f < nF) {
			if (!sp[f] && db[f] > floor + 9) {
				var j = f;
				while (j < nF && !sp[j] && db[j] > floor + 6) { j++; }
				var dur = (j - f) * 0.02;
				var leadsIn = false;
				for (var g2 = j; g2 < Math.min(nF, j + 15); g2++) { if (sp[g2]) { leadsIn = true; break; } }
				var after = false;
				for (var g3 = Math.max(0, f - 5); g3 < f; g3++) { if (sp[g3]) { after = true; break; } }
				if (dur >= 0.06 && dur <= 0.8 && !leadsIn && !after) {
					var mid = Math.round((f + j) / 2 * HOP);
					var halfLen = Math.round((j - f) * HOP / 2 + rate * 0.02);
					dip(mid, halfLen, Math.pow(10, -(db.subarray(f, j).reduce(function (m, x) { return Math.max(m, x); }, -200) - floor) / 20));
					report.strays.push(f * 0.02);
				}
				f = j + 1;
			} else {
				f++;
			}
		}

		// Spectral pass: steady background and whistles.
		var N = 1024;
		var H = N / 4;
		var fft = makeFFT(N);
		var win = new Float32Array(N);
		for (i = 0; i < N; i++) { win[i] = 0.5 - 0.5 * Math.cos(2 * Math.PI * i / N); }
		var bins = N / 2 + 1;
		var hz = rate / N;
		var re = new Float64Array(N);
		var im = new Float64Array(N);

		// Learn the background from the quietest pause frames.
		var quiet = [];
		for (f = 0; f < nF; f++) { if (!sp[f] && db[f] < floor + 4) { quiet.push(f); } }
		var noise = new Float64Array(bins);
		var counted = 0;
		var stride = Math.max(1, Math.floor(quiet.length / 400));
		for (var qi = 0; qi < quiet.length; qi += stride) {
			var st0 = quiet[qi] * HOP;
			if (st0 + N >= n) { continue; }
			for (i = 0; i < N; i++) { re[i] = out[st0 + i] * win[i]; im[i] = 0; }
			fft(re, im, false);
			for (i = 0; i < bins; i++) { noise[i] += re[i] * re[i] + im[i] * im[i]; }
			counted++;
		}
		// After the speech model has run, the background is already handled.
		var doNoise = !skipNoise && counted >= 20;
		if (doNoise) { for (i = 0; i < bins; i++) { noise[i] /= counted; } }

		var res = new Float32Array(n + N);
		var gPrev = new Float64Array(bins);
		for (i = 0; i < bins; i++) { gPrev[i] = 1; }
		var gNow = new Float64Array(bins);
		var pw = new Float64Array(bins);
		var psm = new Float64Array(bins);
		var FLOOR = 0.3;
		var lo35 = Math.round(3500 / hz);
		var hi80 = Math.round(8000 / hz);
		var lo40 = Math.round(4000 / hz);
		var hi110 = Math.min(bins - 1, Math.round(11000 / hz));
		var frames = Math.ceil(n / H) + 1;
		var fr;
		var state = { fr: 0 };

		function frame(fr) {
			var st = fr * H - (N - H);
			var k;
			for (k = 0; k < N; k++) {
				var p = st + k;
				re[k] = (p >= 0 && p < n ? out[p] : 0) * win[k];
				im[k] = 0;
			}
			fft(re, im, false);
			var total = 0;
			var high = 0;
			for (k = 0; k < bins; k++) {
				pw[k] = re[k] * re[k] + im[k] * im[k];
				total += pw[k];
				if (k >= lo40 && k <= hi110) { high += pw[k]; }
			}
			// The level used to decide is itself smoothed, which keeps the
			// background from breaking into chirpy fragments.
			for (k = 0; k < bins; k++) {
				psm[k] = 0.55 * psm[k] + 0.45 * pw[k];
				var g = 1;
				if (doNoise) {
					g = 1 - 1.6 * noise[k] / (Math.max(psm[k], pw[k] * 0.5) + 1e-20);
					if (g < FLOOR) { g = FLOOR; }
				}
				gNow[k] = g;
			}
			// Smooth over time (falls slowly, rises quickly) so nothing flickers.
			for (k = 0; k < bins; k++) {
				var gg = gNow[k] > gPrev[k] ? gNow[k] : 0.7 * gPrev[k] + 0.3 * gNow[k];
				gPrev[k] = gg;
			}
			for (k = 2; k < bins - 2; k++) { gNow[k] = (gPrev[k - 2] + 2 * gPrev[k - 1] + 3 * gPrev[k] + 2 * gPrev[k + 1] + gPrev[k + 2]) / 9; }
			gNow[0] = gPrev[0]; gNow[1] = gPrev[1];
			gNow[bins - 1] = gPrev[bins - 1]; gNow[bins - 2] = gPrev[bins - 2];

			// A whistle: a thin tone standing far above the hiss of an S sound.
			var frameDb = 10 * Math.log10(total / N + 1e-20);
			if (high > total * 0.2 && frameDb > gate - 24) {
				report.sibilants++;
				var pk = lo35;
				for (k = lo35; k <= hi80; k++) { if (pw[k] > pw[pk]) { pk = k; } }
				var around = [];
				for (k = pk - 24; k <= pk + 24; k++) { if (Math.abs(k - pk) > 6 && k > 0 && k < bins) { around.push(pw[k]); } }
				around.sort(function (a, b) { return a - b; });
				var md = around[Math.floor(around.length / 2)];
				if (pw[pk] > md * 50) {
					report.whistles++;
					// The whistle is a few hundred hertz wide: follow it down both sides.
					var l = pk;
					while (l > lo35 - 4 && pk - l < 8 && pw[l - 1] > md * 2) { l--; }
					var r = pk;
					while (r < hi80 + 4 && r - pk < 8 && pw[r + 1] > md * 2) { r++; }
					for (k = l - 1; k <= r + 1; k++) {
						var target = Math.sqrt(md * 1.5 / (pw[k] + 1e-20));
						if (target < 1) { gNow[k] *= Math.max(0.15, target); }
					}
				}
			}

			for (k = 0; k < bins; k++) { re[k] *= gNow[k]; im[k] *= gNow[k]; }
			for (k = 1; k < N / 2; k++) { re[N - k] = re[k]; im[N - k] = -im[k]; }
			fft(re, im, true);
			for (k = 0; k < N; k++) {
				var p2 = st + k;
				if (p2 >= 0 && p2 < n) { res[p2] += re[k] * win[k] / 1.5; }
			}
		}

		// Done in slices so the page stays responsive.
		return {
			report: report,
			step: function (budget) {
				var end = Math.min(frames, state.fr + budget);
				for (fr = state.fr; fr < end; fr++) { frame(fr); }
				state.fr = end;
				return state.fr / frames;
			},
			finish: function () {
				report.whistleShare = report.sibilants ? report.whistles / report.sibilants : 0;
				report.noiseDropDb = doNoise ? Math.round(-20 * Math.log10(FLOOR)) : 0;
				// Settle the longer pauses a little lower. Short gaps inside a
				// sentence, the tail of each word, and the breath before each
				// phrase are left as they are, so nothing sounds chopped.
				var tg = new Float32Array(nF);
				var ff;
				for (ff = 0; ff < nF; ff++) { tg[ff] = 1; }
				var a = 0;
				while (a < nF) {
					if (sp[a]) { a++; continue; }
					var b = a;
					while (b < nF && !sp[b]) { b++; }
					if (b - a >= 15) {
						for (ff = a; ff < b; ff++) {
							if (ff - a < 6 && a > 0) { continue; }
							tg[ff] = b - ff <= 15 && b < nF ? 0.8 : 0.5;
						}
					}
					a = b;
				}
				var sm = new Float32Array(nF);
				for (ff = 0; ff < nF; ff++) {
					var acc = 0;
					var cnt = 0;
					for (var d2 = -2; d2 <= 2; d2++) { if (ff + d2 >= 0 && ff + d2 < nF) { acc += tg[ff + d2]; cnt++; } }
					sm[ff] = acc / cnt;
				}
				for (var p3 = 0; p3 < n; p3++) {
					var pos = p3 / HOP - 0.5;
					var f0 = Math.max(0, Math.min(nF - 1, Math.floor(pos)));
					var f1 = Math.min(nF - 1, f0 + 1);
					var fr2 = Math.max(0, Math.min(1, pos - f0));
					res[p3] *= sm[f0] + (sm[f1] - sm[f0]) * fr2;
				}
				return res.subarray(0, n);
			}
		};
	}

	var api = {
		makeFFT: makeFFT,
		cleanReading: cleanReading
	};
	root.RRTClean = api;
	if (typeof module !== 'undefined') {
		if (module.exports) { module.exports = api; }
	}
})(typeof window !== 'undefined' ? window : this);
