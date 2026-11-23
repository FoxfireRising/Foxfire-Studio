/*
 * Foxfire Studio: broadcast sound (0.5.0).
 *
 * Shapes a cleaned-up reading the way a podcast editor would, then levels it
 * to a published loudness standard:
 *
 *   plosives > high-pass (80 Hz) > EQ > clicks > breaths > de-esser
 *   > slow leveling > compression > loudness (BS.1770, gated) > true-peak limiter
 *
 * (1.13.0: each step has a strength, and the EQ, clicks and slow leveling
 * are voice polish; see process() below.)
 *
 * and checks the finished file against the podcast or audiobook (ACX) rules.
 *
 * Every function here works on plain Float32Arrays, with no page code, so it
 * can be tested on its own. Exposed as window.RRTSound.
 *
 * Whisper never hears this processing: it listens to the untouched recording
 * (see DESIGN.md, section 18).
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

	var MAX_GAIN = 30;
	/*
	 * Phone level (1.9.0). A recording whose room sits far enough under the
	 * voice may be raised further than MAX_GAIN, up to MAX_LIFT, as long as
	 * the room after the lift stays at ROOM_CEIL or below (for a -19 LUFS
	 * voice, so ACX's -60 dB background rule holds too). A phone in the
	 * browser comes through about 35 dB under -19 with its room about 39 dB
	 * below the voice; a laptop needs 23 to 27 dB. Frames at SILENT or below
	 * are digital silence (padding, a gated pause; 24 bits reach down to
	 * about -144) and say nothing about the room.
	 */
	var MAX_LIFT = 45;
	var ROOM_CEIL = -60;
	var SILENT = -150;
	var PRESETS = {
		podcast: { kbps: 128, ceilingTp: -1.8, head: 0.25, tail: 0.6, fadeIn: 0.1, fadeOut: 0.4 },
		audiobook: { kbps: 192, ceilingTp: -3.5, ceilingSample: -3.5, rmsTarget: -20, head: 1.5, tail: 2.5, fadeIn: 0.01, fadeOut: 0.05 }
	};

	function db(x) { return 10 * Math.log10(x + 1e-20); }
	function fromDb(d) { return Math.pow(10, d / 20); }

	/* A biquad filter run over a whole buffer (Direct Form I). */
	function biquad(x, c) {
		var out = new Float32Array(x.length);
		var x1 = 0, x2 = 0, y1 = 0, y2 = 0;
		for (var i = 0; i < x.length; i++) {
			var x0 = x[i];
			var y0 = c.b0 * x0 + c.b1 * x1 + c.b2 * x2 - c.a1 * y1 - c.a2 * y2;
			out[i] = y0;
			x2 = x1; x1 = x0; y2 = y1; y1 = y0;
		}
		return out;
	}
	function norm(b0, b1, b2, a0, a1, a2) {
		return { b0: b0 / a0, b1: b1 / a0, b2: b2 / a0, a1: a1 / a0, a2: a2 / a0 };
	}
	function highpass(f0, q, rate) {
		var w = 2 * Math.PI * f0 / rate, cs = Math.cos(w), al = Math.sin(w) / (2 * q);
		return norm((1 + cs) / 2, -(1 + cs), (1 + cs) / 2, 1 + al, -2 * cs, 1 - al);
	}
	function lowpass(f0, q, rate) {
		var w = 2 * Math.PI * f0 / rate, cs = Math.cos(w), al = Math.sin(w) / (2 * q);
		return norm((1 - cs) / 2, 1 - cs, (1 - cs) / 2, 1 + al, -2 * cs, 1 - al);
	}
	/*
	 * The two K-weighting filters of ITU-R BS.1770, built for any sample rate
	 * the way libebur128 does it. At 48 kHz they reproduce the coefficients
	 * printed in the standard.
	 */
	function kShelf(rate) {
		var f0 = 1681.974450955533, G = 3.999843853973347, Q = 0.7071752369554196;
		var K = Math.tan(Math.PI * f0 / rate), Vh = Math.pow(10, G / 20), Vb = Math.pow(Vh, 0.4996667741545416);
		var a0 = 1 + K / Q + K * K;
		return { b0: (Vh + Vb * K / Q + K * K) / a0, b1: 2 * (K * K - Vh) / a0, b2: (Vh - Vb * K / Q + K * K) / a0, a1: 2 * (K * K - 1) / a0, a2: (1 - K / Q + K * K) / a0 };
	}
	function kHighpass(rate) {
		var f0 = 38.13547087602444, Q = 0.5003270373238773, K = Math.tan(Math.PI * f0 / rate);
		var a0 = 1 + K / Q + K * K;
		return { b0: 1, b1: -2, b2: 1, a1: 2 * (K * K - 1) / a0, a2: (1 - K / Q + K * K) / a0 };
	}

	/*
	 * Integrated loudness, ITU-R BS.1770-4, for one channel: K-weighting,
	 * 400 ms blocks every 100 ms, an absolute gate at -70 LUFS and a relative
	 * gate 10 LU below.
	 */
	function lufs(x, rate) {
		var k = biquad(biquad(x, kShelf(rate)), kHighpass(rate));
		var block = Math.round(0.4 * rate), step = Math.round(0.1 * rate);
		if (k.length < block) { return -Infinity; }
		var energies = [];
		var cum = new Float64Array(k.length + 1);
		for (var i = 0; i < k.length; i++) { cum[i + 1] = cum[i] + k[i] * k[i]; }
		for (var s = 0; s + block <= k.length; s += step) { energies.push((cum[s + block] - cum[s]) / block); }
		function loud(e) { return -0.691 + 10 * Math.log10(e); }
		var abs = energies.filter(function (e) { return loud(e) > -70; });
		if (!abs.length) { return -Infinity; }
		var mean = abs.reduce(function (a, b) { return a + b; }, 0) / abs.length;
		var rel = loud(mean) - 10;
		var kept = abs.filter(function (e) { return loud(e) > rel; });
		if (!kept.length) { return -Infinity; }
		return loud(kept.reduce(function (a, b) { return a + b; }, 0) / kept.length);
	}

	/*
	 * Integrated loudness of several channels (1.6.0), as BS.1770 counts
	 * them: each block's energy is the sum of the channels' energies, so a
	 * voice copied into both channels of a stereo file measures 3 LU louder
	 * than the same voice in a mono file. Given one channel, the same as lufs().
	 */
	function lufsMulti(chs, rate) {
		if (!chs || !chs.length) { return -Infinity; }
		if (chs.length === 1) { return lufs(chs[0], rate); }
		var block = Math.round(0.4 * rate), step = Math.round(0.1 * rate);
		var n = chs[0].length;
		if (n < block) { return -Infinity; }
		var sums = [];
		chs.forEach(function (x) {
			var k = biquad(biquad(x, kShelf(rate)), kHighpass(rate));
			var cum = new Float64Array(n + 1);
			for (var i = 0; i < n; i++) { cum[i + 1] = cum[i] + k[i] * k[i]; }
			sums.push(cum);
		});
		var energies = [];
		for (var s = 0; s + block <= n; s += step) {
			var e = 0;
			for (var c = 0; c < sums.length; c++) { e += (sums[c][s + block] - sums[c][s]) / block; }
			energies.push(e);
		}
		function loud(e) { return -0.691 + 10 * Math.log10(e); }
		var abs = energies.filter(function (e) { return loud(e) > -70; });
		if (!abs.length) { return -Infinity; }
		var rel = loud(abs.reduce(function (a, b) { return a + b; }, 0) / abs.length) - 10;
		var kept = abs.filter(function (e) { return loud(e) > rel; });
		if (!kept.length) { return -Infinity; }
		return loud(kept.reduce(function (a, b) { return a + b; }, 0) / kept.length);
	}

	/*
	 * Loudness range (1.6.0), EBU Tech 3342: the spread between the quieter
	 * and louder parts, in LU. Short-term loudness (3 s windows, every 100 ms),
	 * an absolute gate at -70 LUFS and a relative gate 20 LU below, then the
	 * 95th percentile less the 10th. A speech reading usually measures 3 to
	 * 8 LU; over 10 the quiet parts may be hard to hear in a car or on a bus.
	 * Channels copied from one voice give the same range as the voice alone.
	 * Returns null for a reading too short to measure (under 3 s).
	 */
	function loudnessRange(x, rate) {
		var k = biquad(biquad(x, kShelf(rate)), kHighpass(rate));
		var win = Math.round(3 * rate), step = Math.round(0.1 * rate);
		if (k.length < win) { return null; }
		var cum = new Float64Array(k.length + 1);
		for (var i = 0; i < k.length; i++) { cum[i + 1] = cum[i] + k[i] * k[i]; }
		var es = [];
		for (var s = 0; s + win <= k.length; s += step) { es.push((cum[s + win] - cum[s]) / win); }
		function loud(e) { return -0.691 + 10 * Math.log10(e); }
		var abs = es.filter(function (e) { return loud(e) > -70; });
		if (abs.length < 2) { return null; }
		var rel = loud(abs.reduce(function (a, b) { return a + b; }, 0) / abs.length) - 20;
		var kept = abs.map(loud).filter(function (l) { return l > rel; }).sort(function (a, b) { return a - b; });
		if (kept.length < 2) { return 0; }
		function pct(p) { return kept[Math.min(kept.length - 1, Math.max(0, Math.round(p * (kept.length - 1))))]; }
		return Math.max(0, pct(0.95) - pct(0.10));
	}
	var LRA_WIDE = 10;

	/*
	 * A finishing preset (1.6.0, from Settings or the review's one-off menu)
	 * turned into what the chain needs. The preset's loudness and true peak
	 * are what the finished file measures; the voice is processed in mono, so
	 * for stereo its target is 3 LU lower (both channels then add up to the
	 * preset's number). MP3 encoding lifts peaks a little, so an MP3 is
	 * limited 0.8 dB under its limit (-1.8 for -1, as since 0.5.0); a WAV is
	 * kept exactly, so 0.2 dB covers the change of sample rate.
	 * Returns null for the simple leveling.
	 */
	var STEREO_LU = 10 * Math.log10(2);
	function plan(spec) {
		spec = spec || { kind: 'loudness', lufs: -19, tp: -1, ch: 1, format: 'mp3', kbps: 128 };
		if (spec.kind === 'off') { return null; }
		var ch = spec.ch === 2 ? 2 : 1;
		var format = spec.format === 'wav' ? 'wav' : 'mp3';
		var out = {
			key: spec.key || '',
			label: spec.label || '',
			channels: ch,
			format: format,
			ext: format,
			rate: format === 'wav' ? (spec.rate || 48000) : 44100,
			bits: format === 'wav' ? (spec.bits || 24) : 0,
			kbps: format === 'wav' ? 0 : (spec.kbps || 128)
		};
		if (spec.kind === 'acx') {
			out.mode = 'audiobook';
			out.fileTarget = null;
			out.target = null;
			out.tp = -3;
			out.ceilingTp = PRESETS.audiobook.ceilingTp;
			return out;
		}
		var lu = isFinite(Number(spec.lufs)) ? Number(spec.lufs) : -19;
		var tp = isFinite(Number(spec.tp)) ? Number(spec.tp) : -1;
		out.mode = 'podcast';
		out.fileTarget = lu;
		out.target = lu - (ch === 2 ? STEREO_LU : 0);
		out.tp = tp;
		out.ceilingTp = tp - (format === 'mp3' ? 0.8 : 0.2);
		return out;
	}

	/* Channels as a WAV file (1.6.0): 16 or 24-bit PCM, little-endian. Returns a Uint8Array. */
	function wav(chs, rate, bits) {
		bits = bits === 16 ? 16 : 24;
		var nc = chs.length, n = chs[0].length, bps = bits / 8;
		var data = n * nc * bps;
		var buf = new ArrayBuffer(44 + data), v = new DataView(buf), u = new Uint8Array(buf);
		function str(o, t) { for (var i = 0; i < t.length; i++) { v.setUint8(o + i, t.charCodeAt(i)); } }
		str(0, 'RIFF'); v.setUint32(4, 36 + data, true); str(8, 'WAVE');
		str(12, 'fmt '); v.setUint32(16, 16, true); v.setUint16(20, 1, true); v.setUint16(22, nc, true);
		v.setUint32(24, rate, true); v.setUint32(28, rate * nc * bps, true); v.setUint16(32, nc * bps, true); v.setUint16(34, bits, true);
		str(36, 'data'); v.setUint32(40, data, true);
		var max = bits === 16 ? 32767 : 8388607, o = 44;
		for (var i = 0; i < n; i++) {
			for (var c = 0; c < nc; c++) {
				var x = chs[c][i];
				x = x > 1 ? 1 : (x < -1 ? -1 : x);
				var q = Math.round(x * max);
				if (bits === 16) { v.setInt16(o, q, true); } else { u[o] = q & 255; u[o + 1] = (q >> 8) & 255; u[o + 2] = (q >> 16) & 255; }
				o += bps;
			}
		}
		return u;
	}

	/*
	 * A short piece leveled to a loudness and held under a true peak, with a
	 * cap on how far it is raised. For clips (1.6.0: -14 LUFS, as social apps
	 * play them). Returns { data, gainDb, lufs }.
	 */
	function toLoudness(x, rate, target, ceilingTp, maxGain) {
		var before = lufs(x, rate);
		if (!isFinite(before)) { return { data: new Float32Array(x), gainDb: 0, lufs: before }; }
		var gainDb = Math.min(maxGain === undefined ? 12 : maxGain, target - before);
		var y = levelAndLimit(x, rate, { ceilingTp: ceilingTp }, gainDb);
		var after = lufs(y.data, rate);
		if (isFinite(after) && Math.abs(target - after) > 0.3 && gainDb + (target - after) <= (maxGain === undefined ? 12 : maxGain)) {
			gainDb += target - after;
			y = levelAndLimit(x, rate, { ceilingTp: ceilingTp }, gainDb);
			after = lufs(y.data, rate);
		}
		return { data: y.data, gainDb: gainDb, lufs: after };
	}

	/*
	 * True peak: the signal rebuilt at four times the sample rate with a
	 * windowed-sinc filter (48 taps), as BS.1770-4 Annex 2 describes, so peaks
	 * that fall between samples are seen. Returns a per-sample envelope.
	 */
	var TP_TAPS = 12;
	var tpFilter = null;
	function tpPhases() {
		if (tpFilter) { return tpFilter; }
		tpFilter = [];
		for (var ph = 0; ph < 4; ph++) {
			var taps = new Float64Array(TP_TAPS);
			var sum = 0;
			for (var t = 0; t < TP_TAPS; t++) {
				var n = t - TP_TAPS / 2 + 1 - ph / 4;
				var sinc = n === 0 ? 1 : Math.sin(Math.PI * n) / (Math.PI * n);
				var w = 0.5 + 0.5 * Math.cos(Math.PI * n / (TP_TAPS / 2));
				taps[t] = sinc * w;
				sum += taps[t];
			}
			for (t = 0; t < TP_TAPS; t++) { taps[t] /= sum; }
			tpFilter.push(taps);
		}
		return tpFilter;
	}
	/*
	 * Only samples whose neighbourhood comes within 6 dB of `near` are rebuilt;
	 * a peak between samples cannot rise that far above its neighbours in real
	 * audio, so the rest keep their sample value. This makes it fast enough for
	 * long readings.
	 */
	function truePeakEnvelope(x, near) {
		var phases = tpPhases();
		var env = new Float32Array(x.length);
		var half = TP_TAPS / 2;
		var B = 64, nb = Math.ceil(x.length / B), bmax = new Float32Array(nb), i, b;
		for (i = 0; i < x.length; i++) { var ax = Math.abs(x[i]); b = (i / B) | 0; if (ax > bmax[b]) { bmax[b] = ax; } }
		var limitNear = near === undefined ? 0 : near * 0.5;
		for (i = 0; i < x.length; i++) {
			var m = Math.abs(x[i]);
			b = (i / B) | 0;
			var hood = bmax[b];
			if (b > 0 && bmax[b - 1] > hood) { hood = bmax[b - 1]; }
			if (b < nb - 1 && bmax[b + 1] > hood) { hood = bmax[b + 1]; }
			if (hood < limitNear) { env[i] = m; continue; }
			// Within a busy block, skip samples whose immediate neighbours are also well under.
			var nx = i + 1 < x.length ? Math.abs(x[i + 1]) : 0, pv = i > 0 ? Math.abs(x[i - 1]) : 0;
			if (m < limitNear && nx < limitNear && pv < limitNear) { env[i] = m; continue; }
			var base = i - half + 1;
			var inside = base >= 0 && base + TP_TAPS <= x.length;
			for (var ph = 1; ph < 4; ph++) {
				var taps = phases[ph];
				var acc = 0, t;
				if (inside) {
					for (t = 0; t < TP_TAPS; t++) { acc += taps[t] * x[base + t]; }
				} else {
					for (t = 0; t < TP_TAPS; t++) { var j = base + t; if (j >= 0 && j < x.length) { acc += taps[t] * x[j]; } }
				}
				if (acc < 0) { acc = -acc; }
				if (acc > m) { m = acc; }
			}
			env[i] = m;
		}
		return env;
	}
	function truePeakDb(x) {
		var sp = 0;
		for (var k = 0; k < x.length; k++) { var a = Math.abs(x[k]); if (a > sp) { sp = a; } }
		var env = truePeakEnvelope(x, sp), m = 0;
		for (var i = 0; i < env.length; i++) { if (env[i] > m) { m = env[i]; } }
		return 20 * Math.log10(m + 1e-20);
	}
	function samplePeakDb(x) {
		var m = 0;
		for (var i = 0; i < x.length; i++) { var a = Math.abs(x[i]); if (a > m) { m = a; } }
		return 20 * Math.log10(m + 1e-20);
	}

	/*
	 * A look-ahead limiter. Gain is worked out from the true-peak envelope
	 * (or plain samples), held across a short window so it starts falling
	 * before each peak, then released smoothly. Offline, so there is no delay.
	 */
	function limit(x, rate, ceilingDb, useTruePeak) {
		var ceiling = fromDb(ceilingDb);
		var env = useTruePeak ? truePeakEnvelope(x, ceiling) : (function () { var e = new Float32Array(x.length); for (var i = 0; i < x.length; i++) { e[i] = Math.abs(x[i]); } return e; })();
		var need = new Float32Array(x.length);
		var hits = 0;
		for (var i = 0; i < x.length; i++) {
			need[i] = env[i] > ceiling ? ceiling / env[i] : 1;
			if (need[i] < 1) { hits++; }
		}
		if (!hits) { return { data: x, maxReductionDb: 0 }; }
		var look = Math.max(1, Math.round(rate * 0.002));
		// The smallest gain needed anywhere within `look` samples on either side,
		// in one pass with a monotonic queue.
		var held = new Float32Array(x.length);
		var q = new Int32Array(x.length), qh = 0, qt = 0, n = x.length;
		for (var r = 0; r < n + look; r++) {
			if (r < n) {
				while (qt > qh && need[q[qt - 1]] >= need[r]) { qt--; }
				q[qt++] = r;
			}
			var c = r - look;
			if (c >= 0) {
				while (q[qh] < c - look) { qh++; }
				held[c] = need[q[qh]];
			}
		}
		var rel = Math.exp(-1 / (rate * 0.06));
		var g = 1, out = new Float32Array(x.length), maxRed = 1;
		for (i = 0; i < x.length; i++) {
			g = held[i] < g ? held[i] : held[i] + (g - held[i]) * rel;
			out[i] = x[i] * g;
			if (g < maxRed) { maxRed = g; }
		}
		return { data: out, maxReductionDb: -20 * Math.log10(maxRed) };
	}

	/* Where the speech is, in 20 ms frames: the same rule as the cleanup uses. */
	function speechMap(x, rate) {
		var HOP = Math.round(rate * 0.02), nF = Math.floor(x.length / HOP);
		var fdb = new Float32Array(nF);
		for (var f = 0; f < nF; f++) {
			var s = 0;
			for (var i = f * HOP; i < (f + 1) * HOP; i++) { s += x[i] * x[i]; }
			fdb[f] = db(s / HOP);
		}
		var sorted = Array.prototype.slice.call(fdb).sort(function (a, b) { return a - b; });
		var floor = nF ? sorted[Math.floor(nF * 0.1)] : -100;
		var loud = nF ? sorted[Math.floor(nF * 0.9)] : -100;
		var gate = Math.max(floor + 10, loud - 28);
		var sp = new Uint8Array(nF);
		for (f = 0; f < nF; f++) { sp[f] = fdb[f] > gate ? 1 : 0; }
		return { HOP: HOP, nF: nF, db: fdb, sp: sp, floor: floor, loud: loud, gate: gate };
	}

	/* Fades a dip in and out over a few milliseconds so no edit can click. */
	function shape(len, ramp, depth, at) {
		if (at < ramp) { return 1 - (1 - depth) * (0.5 - 0.5 * Math.cos(Math.PI * at / ramp)); }
		if (len - at < ramp) { return 1 - (1 - depth) * (0.5 - 0.5 * Math.cos(Math.PI * (len - at) / ramp)); }
		return depth;
	}

	/*
	 * Plosives: a "p" or "b" puffs air at the microphone, a short burst of low
	 * rumble far above the voice's own low end. Found where the band under
	 * 150 Hz jumps at least 12 dB above its usual level in speech and carries
	 * most of the energy, for no longer than 80 ms. That band is then pulled
	 * down there only.
	 */
	function plosives(x, rate, map, st) {
		st = st || STRENGTH.plosives.standard;
		var lp = biquad(biquad(x, lowpass(150, 0.7071, rate)), lowpass(150, 0.7071, rate));
		var H = Math.round(rate * 0.005), n = Math.floor(x.length / H);
		var lf = new Float32Array(n), all = new Float32Array(n), f, i;
		for (f = 0; f < n; f++) {
			var a = 0, b = 0;
			for (i = f * H; i < (f + 1) * H; i++) { a += lp[i] * lp[i]; b += x[i] * x[i]; }
			lf[f] = db(a / H); all[f] = db(b / H);
		}
		var speechLf = [];
		for (f = 0; f < n; f++) { var mf = Math.floor(f * H / map.HOP); if (map.sp[mf]) { speechLf.push(lf[f]); } }
		if (speechLf.length < 50) { return { data: x, count: 0, at: [] }; }
		speechLf.sort(function (p, q) { return p - q; });
		var med = speechLf[Math.floor(speechLf.length / 2)];
		var out = new Float32Array(x), count = 0, at = [];
		f = 0;
		while (f < n) {
			if (lf[f] > med + st.over && lf[f] - all[f] > -1.5) {
				var e = f;
				while (e < n && lf[e] > med + 6) { e++; }
				if ((e - f) * H <= rate * 0.08) {
					var s0 = Math.max(0, f * H - Math.round(rate * 0.01)), s1 = Math.min(x.length, e * H + Math.round(rate * 0.02));
					var ramp = Math.round(rate * 0.008);
					for (i = s0; i < s1; i++) { out[i] -= lp[i] * (1 - shape(s1 - s0, ramp, st.depth, i - s0)); }
					count++;
					at.push(f * H / rate);
				}
				f = e + 1;
			} else {
				f++;
			}
		}
		return { data: out, count: count, at: at };
	}

	/*
	 * De-esser: when the band above 4.5 kHz grows loud next to the rest of the
	 * voice (an S, Sh, or Ch), that band alone is turned down, by up to 8 dB.
	 */
	function deess(x, rate, map, skip, st) {
		st = st || STRENGTH.deess.standard;
		var hp = highpass(4500, 0.7071, rate);
		var hf = biquad(biquad(x, hp), hp);
		var att = Math.exp(-1 / (rate * 0.001)), rel = Math.exp(-1 / (rate * 0.06));
		var CR = 16, gAtt = Math.exp(-CR / (rate * 0.004)), gRel = Math.exp(-CR / (rate * 0.06));
		var eh = 0, ea = 0, out = new Float32Array(x.length), g = 1, gPrev = 1;
		var events = 0, inEvent = false, eventLen = 0, quietLen = 0, maxCut = 0, minLen = Math.round(rate * 0.02 / CR), gap = Math.round(rate * 0.08 / CR);
		var gateLin = Math.pow(10, map.gate / 10);
		for (var s0 = 0; s0 < x.length; s0 += CR) {
			var s1 = Math.min(x.length, s0 + CR), i;
			for (i = s0; i < s1; i++) {
				var h2 = hf[i] * hf[i], a2 = x[i] * x[i];
				eh = h2 > eh ? h2 + (eh - h2) * att : h2 + (eh - h2) * rel;
				ea = a2 > ea ? a2 + (ea - a2) * att : a2 + (ea - a2) * rel;
			}
			var mf = Math.floor(s0 / map.HOP), target = 1;
			if (mf < map.nF && map.sp[mf] && !(skip && skip[mf]) && ea > gateLin && eh > ea * st.share) {
				target = Math.max(fromDb(-st.maxDb), Math.sqrt(ea * st.share / eh));
			}
			g = target < g ? target + (g - target) * gAtt : target + (g - target) * gRel;
			for (i = s0; i < s1; i++) {
				var gi = gPrev + (g - gPrev) * (i - s0 + 1) / (s1 - s0);
				out[i] = x[i] - (1 - gi) * hf[i];
			}
			gPrev = g;
			var cut = -20 * Math.log10(g);
			if (cut > 1.5) {
				eventLen++;
				quietLen = 0;
				if (!inEvent && eventLen >= minLen) { events++; inEvent = true; }
				if (cut > maxCut) { maxCut = cut; }
			} else if (cut < 0.5) {
				quietLen++;
				eventLen = 0;
				if (quietLen >= gap) { inEvent = false; }
			}
		}
		return { data: out, count: events, maxCutDb: maxCut };
	}

	/*
	 * Breaths: the short, noisy, quieter sound that leads straight into a
	 * phrase. A breath has no voice in it, so its waveform crosses zero often,
	 * like an S; it is also well below the words and longer than a consonant.
	 * Lowered by 6 dB and kept in, so the reading still sounds human. The
	 * last 30 ms before each phrase is left alone so no word loses its start.
	 */
	function breaths(x, rate, map, st) {
		st = st || STRENGTH.breaths.standard;
		var out = new Float32Array(x), count = 0, HOP = map.HOP, nF = map.nF, mask = new Uint8Array(nF);
		var zc = new Float32Array(nF), f, i;
		for (f = 0; f < nF; f++) {
			var c = 0;
			for (i = f * HOP + 1; i < (f + 1) * HOP; i++) { if ((x[i] >= 0) !== (x[i - 1] >= 0)) { c++; } }
			zc[f] = c / HOP;
		}
		function breathy(k) { return map.db[k] > map.floor + 6 && map.db[k] < map.loud - 12 && zc[k] > 0.12; }
		function voiced(k) { return map.db[k] >= map.loud - 15 && zc[k] < 0.12; }
		f = 0;
		while (f < nF) {
			if (!breathy(f)) { f++; continue; }
			var e = f;
			while (e < nF && breathy(e)) { e++; }
			var dur = (e - f) * 0.02;
			var leadsIn = false;
			for (var k = e; k < Math.min(nF, e + 4); k++) { if (voiced(k)) { leadsIn = true; break; } }
			var fromQuiet = f === 0 || map.db[f - 1] < map.floor + 6 || !map.sp[f - 1];
			if (leadsIn && fromQuiet && dur >= 0.12 && dur <= 0.9) {
				var s0 = f * HOP, s1 = e * HOP - Math.round(rate * 0.03);
				if (s1 - s0 > rate * 0.06) {
					var ramp = Math.round(rate * 0.015);
					for (i = s0; i < s1; i++) { out[i] *= shape(s1 - s0, ramp, st.depth, i - s0); }
					for (k = f; k < e; k++) { mask[k] = 1; }
					count++;
				}
			}
			f = e + 1;
		}
		return { data: out, count: count, mask: mask };
	}

	/*
	 * Gentle compression: 2 to 1 above a threshold 6 dB under the loudest
	 * speech, with a soft knee. The level it listens to is averaged over about
	 * 30 ms, so it follows whole words and leaves brief peaks to the limiter
	 * (on a real voice, a faster detector cut up to 12 dB). No make-up gain:
	 * the loudness step that follows sets the level.
	 */
	function compress(x, rate, map, st) {
		st = st || STRENGTH.compress.standard;
		var speechDb = [];
		for (var f = 0; f < map.nF; f++) { if (map.sp[f]) { speechDb.push(map.db[f]); } }
		if (speechDb.length < 25) { return { data: x, avgDb: 0, maxDb: 0 }; }
		speechDb.sort(function (a, b) { return a - b; });
		var thr = speechDb[Math.floor(speechDb.length * 0.9)] - st.below;
		var ratio = st.ratio, knee = 6, CR = 16;
		var detA = Math.exp(-1 / (rate * 0.03)), detR = Math.exp(-1 / (rate * 0.12));
		var att = Math.exp(-CR / (rate * 0.02)), rel = Math.exp(-CR / (rate * 0.2));
		var e = 0, gr = 0, grPrev = 0, out = new Float32Array(x.length), sum = 0, cnt = 0, mx = 0;
		for (var s0 = 0; s0 < x.length; s0 += CR) {
			var s1 = Math.min(x.length, s0 + CR), i;
			for (i = s0; i < s1; i++) {
				var p = x[i] * x[i];
				e = p > e ? p + (e - p) * detA : p + (e - p) * detR;
			}
			var over = db(e) - thr, want;
			if (over <= -knee / 2) { want = 0; }
			else if (over < knee / 2) { want = (1 - 1 / ratio) * Math.pow(over + knee / 2, 2) / (2 * knee); }
			else { want = (1 - 1 / ratio) * over; }
			gr = want > gr ? want + (gr - want) * att : want + (gr - want) * rel;
			var g0 = fromDb(-grPrev), g1 = fromDb(-gr);
			for (i = s0; i < s1; i++) { out[i] = x[i] * (g0 + (g1 - g0) * (i - s0 + 1) / (s1 - s0)); }
			grPrev = gr;
			var mf = Math.floor(s0 / map.HOP);
			if (mf < map.nF && map.sp[mf]) { sum += gr; cnt++; if (gr > mx) { mx = gr; } }
		}
		return { data: out, avgDb: cnt ? sum / cnt : 0, maxDb: mx };
	}

	function rmsDb(x) {
		var s = 0;
		for (var i = 0; i < x.length; i++) { s += x[i] * x[i]; }
		return db(s / Math.max(1, x.length));
	}
	/* The background: the RMS of the quietest half second, as ACX measures it. */
	function noiseFloorDb(x, rate) {
		var w = Math.round(rate * 0.5), hop = Math.round(rate * 0.1), best = Infinity;
		if (x.length < w) { return rmsDb(x); }
		var cum = new Float64Array(x.length + 1);
		for (var i = 0; i < x.length; i++) { cum[i + 1] = cum[i] + x[i] * x[i]; }
		for (var s = 0; s + w <= x.length; s += hop) { var v = (cum[s + w] - cum[s]) / w; if (v < best) { best = v; } }
		return db(best);
	}
	/* Seconds of quiet before the first word and after the last. */
	function edges(x, rate) {
		var map = speechMap(x, rate), first = -1, last = -1;
		for (var f = 0; f < map.nF; f++) { if (map.sp[f]) { if (first < 0) { first = f; } last = f; } }
		if (first < 0) { return { head: 0, tail: 0 }; }
		return { head: first * 0.02, tail: Math.max(0, x.length / rate - (last + 1) * 0.02) };
	}

	/*
	 * Audiobook export (1.20.0): the pauses between the first word and the
	 * last, in seconds, each at least minS long ({ s, e }), as the speech map
	 * finds them.
	 */
	function pauses(x, rate, minS) {
		var map = speechMap(x, rate), H = map.HOP / rate, out = [], first = -1, last = -1, f;
		for (f = 0; f < map.nF; f++) { if (map.sp[f]) { if (first < 0) { first = f; } last = f; } }
		if (first < 0) { return out; }
		var need = Math.max(1, Math.round((minS || 0.3) / H)), st = -1;
		for (f = first; f <= last + 1; f++) {
			var quiet = f <= last ? !map.sp[f] : false;
			if (quiet) { if (st < 0) { st = f; } continue; }
			if (st >= 0) {
				if (f - st >= need) { out.push({ s: st * H, e: f * H }); }
				st = -1;
			}
		}
		return out;
	}
	/* Two runs of audio joined with an equal-power crossfade of xf samples. */
	function joinXf(a, b, xf) {
		xf = Math.max(0, Math.min(xf, a.length, b.length));
		var out = new Float32Array(a.length + b.length - xf), i;
		out.set(a.subarray(0, a.length - xf), 0);
		for (i = 0; i < xf; i++) {
			var k = (i + 0.5) / xf;
			out[a.length - xf + i] = a[a.length - xf + i] * Math.cos(0.5 * Math.PI * k) + b[i] * Math.sin(0.5 * Math.PI * k);
		}
		out.set(b.subarray(xf), a.length);
		return out;
	}
	/*
	 * Room tone for an audiobook file (1.20.0). ACX and the other audiobook
	 * stores want a stretch of the room's own sound before the first word and
	 * after the last (1 to 5 seconds). A podcast reading is trimmed to a
	 * quarter second before and 0.6 s after, so the missing part is made from
	 * the recording's own quietest pauses: the middle of each pause of 0.42 s
	 * or more (60 ms left out at each side, so no breath or word tail comes
	 * with it), the quietest first, only those within 6 dB of the quietest,
	 * up to 3 s, joined and repeated with short crossfades, then crossfaded
	 * onto the reading. Nothing is ever filled with digital silence.
	 *
	 * head, tail: the seconds wanted. keep (optional): the most of the file's
	 * own quiet to keep at each end, for a file whose ends were faded (a
	 * podcast MP3); its quiet ends are then never used as room tone.
	 * Returns { data, report: { headWas, tailWas, head, tail, addedHead,
	 * addedTail, toneDb, toneSecs, shift (how much later the first word is) } }.
	 */
	var ROOM = { minRun: 0.42, edge: 0.06, within: 6, most: 3, xf: 0.03, loopXf: 0.05 };
	function roomPad(x, rate, head, tail, keep) {
		var map = speechMap(x, rate), HOP = map.HOP, first = -1, last = -1, f;
		for (f = 0; f < map.nF; f++) { if (map.sp[f]) { if (first < 0) { first = f; } last = f; } }
		if (first < 0) { return { data: x, report: { none: true } }; }
		var s0 = first * HOP, s1 = Math.min(x.length, (last + 1) * HOP);
		var useEnds = keep === undefined || keep === null;
		var hMax = Math.round(rate * (useEnds ? head : Math.min(head, keep)));
		var tMax = Math.round(rate * (useEnds ? tail : Math.min(tail, keep)));
		var hk = Math.min(s0, hMax), tk = Math.min(x.length - s1, tMax);
		var core = x.subarray(s0 - hk, s1 + tk);
		/* The room's own sound: the quiet middles of the pauses. */
		var edge = Math.round(ROOM.edge / 0.02), minF = Math.round(ROOM.minRun / 0.02), runs = [], st = -1;
		var lo = useEnds ? 0 : first, hi = useEnds ? map.nF : last + 1;
		for (f = lo; f <= hi; f++) {
			var quiet = f < hi ? !map.sp[f] : false;
			if (quiet) { if (st < 0) { st = f; } continue; }
			if (st >= 0 && f - st >= minF) {
				var a = (st + (st === 0 ? 0 : edge)) * HOP, b = (f - (f === map.nF ? 0 : edge)) * HOP;
				if (b - a >= HOP * 5) {
					var e = 0;
					for (var i = a; i < b; i++) { e += x[i] * x[i]; }
					var d = db(e / (b - a));
					if (d > SILENT) { runs.push({ a: a, b: b, db: d }); }
				}
			}
			st = -1;
		}
		var mat;
		if (runs.length) {
			runs.sort(function (p, q) { return p.db - q.db; });
			var q0 = runs[0].db, got = 0, parts = [];
			for (var r = 0; r < runs.length; r++) {
				if (runs[r].db > q0 + ROOM.within || got >= rate * ROOM.most) { break; }
				parts.push(x.subarray(runs[r].a, runs[r].b));
				got += runs[r].b - runs[r].a;
			}
			var xfm = Math.round(rate * 0.015);
			mat = new Float32Array(parts[0]);
			for (var p = 1; p < parts.length; p++) { mat = joinXf(mat, parts[p], xfm); }
		} else {
			/* No pause long enough: the quietest 0.3 s that is not digital silence. */
			var w = Math.min(x.length, Math.round(rate * 0.3)), hop = Math.round(rate * 0.02), best = Infinity, at = 0;
			var cum = new Float64Array(x.length + 1);
			for (var c = 0; c < x.length; c++) { cum[c + 1] = cum[c] + x[c] * x[c]; }
			for (var s = 0; s + w <= x.length; s += hop) {
				var v = (cum[s + w] - cum[s]) / w;
				if (v < best ? db(v) > SILENT : false) { best = v; at = s; }
			}
			mat = new Float32Array(x.subarray(at, at + w));
		}
		var tone = function (n) { return loopTo(mat, n, rate, ROOM.loopXf); };
		var needH = Math.max(0, Math.round(rate * head) - hk), needT = Math.max(0, Math.round(rate * tail) - tk);
		var xf = Math.round(rate * ROOM.xf), out = core, xh = 0, xt = 0;
		if (needH) {
			xh = Math.min(xf, hk);
			out = joinXf(tone(needH + xh), out, xh);
		}
		if (needT) {
			xt = Math.min(xf, tk);
			out = joinXf(out, tone(needT + xt), xt);
		}
		var me = 0;
		for (var m = 0; m < mat.length; m++) { me += mat[m] * mat[m]; }
		return {
			data: out,
			report: {
				headWas: s0 / rate,
				tailWas: (x.length - s1) / rate,
				head: (needH + hk) / rate,
				tail: (needT + tk) / rate,
				addedHead: needH / rate,
				addedTail: needT / rate,
				toneDb: db(me / Math.max(1, mat.length)),
				toneSecs: mat.length / rate,
				shift: (needH + hk - s0) / rate
			}
		};
	}
	/*
	 * A finished file brought to an RMS level with its peaks held under a
	 * ceiling (1.20.0, ACX: -20 dB RMS, peaks under -3), measured again and
	 * corrected once, since the limiter takes a little with it.
	 */
	function levelRms(x, rate, target, ceiling) {
		var y = adjust(x, rate, 'audiobook', target - rmsDb(x), ceiling);
		var off = target - rmsDb(y);
		if (Math.abs(off) > 0.1) { y = adjust(x, rate, 'audiobook', target - rmsDb(x) + off, ceiling); }
		return y;
	}

	/*
	 * A small level change after the MP3 was measured: MP3 encoding can shift
	 * loudness a little (lamejs lost about half a decibel on a real voice), so
	 * the finished file is checked and, if needed, nudged and encoded once more.
	 */
	function adjust(x, rate, mode, gainDb, ceilingTp) {
		return levelAndLimit(x, rate, withCeiling(PRESETS[mode] || PRESETS.podcast, ceilingTp), gainDb).data;
	}
	/* A preset with its true peak ceiling replaced (1.6.0: each finishing preset has its own). */
	function withCeiling(P, ceilingTp) {
		if (ceilingTp === undefined || ceilingTp === null || !isFinite(Number(ceilingTp))) { return P; }
		var Q = {};
		Object.keys(P).forEach(function (k) { Q[k] = P[k]; });
		Q.ceilingTp = Number(ceilingTp);
		return Q;
	}
	function levelAndLimit(x, rate, P, gainDb) {
		var g = fromDb(gainDb), y = new Float32Array(x.length);
		for (var i = 0; i < x.length; i++) { y[i] = x[i] * g; }
		var lim = limit(y, rate, P.ceilingTp, true);
		y = lim.data;
		if (P.ceilingSample !== undefined) { y = limit(y, rate, P.ceilingSample, false).data; }
		return { data: y, maxReductionDb: lim.maxReductionDb };
	}

	/*
	 * The room under a recording (1.9.0): the level of its quieter stretches,
	 * the 10th percentile of 50 ms frames that are not digital silence. Null
	 * when too little of the recording is anything but silence to tell.
	 */
	function roomLevel(x, rate) {
		var w = Math.max(1, Math.round(rate * 0.05)), list = [];
		for (var s = 0; s + w <= x.length; s += w) {
			var e = 0;
			for (var i = s; i < s + w; i++) { e += x[i] * x[i]; }
			var d = db(e / w);
			if (d > SILENT) { list.push(d); }
		}
		if (list.length < 10) { return null; }
		list.sort(function (a, b) { return a - b; });
		return list[Math.floor(list.length * 0.1)];
	}
	/*
	 * Where the speech starts and ends (1.9.0), in samples: the first and
	 * last 20 ms window with a sample over the threshold. The threshold was
	 * a fixed 0.012 (-38 dBFS) until 1.9.0, which cut a phone reading after
	 * its last loud word, since a phone's voice peaks around -35 dBFS. It is
	 * now 20 dB under the recording's own peaks (the 99th percentile of the
	 * windows' peaks, so a single click does not count), at least 12 dB over
	 * its room (the 10th percentile), and never above 0.012, so a laptop or
	 * louder recording is trimmed exactly as before. Returns { first, last,
	 * thresh }, last being one past the end; the whole recording when
	 * nothing reaches the threshold.
	 */
	var TRIM_MAX = 0.012;
	function speechBounds(data, rate) {
		var n = data.length, win = Math.max(1, Math.round(rate * 0.02)), peaks = [], i, j;
		for (i = 0; i < n; i += win) {
			var p = 0;
			for (j = i; j < Math.min(n, i + win); j++) { var a = data[j] < 0 ? -data[j] : data[j]; if (a > p) { p = a; } }
			peaks.push(p);
		}
		if (!peaks.length) { return { first: 0, last: n, thresh: TRIM_MAX }; }
		var sorted = peaks.slice().sort(function (a, b) { return a - b; });
		var top = sorted[Math.min(sorted.length - 1, Math.floor(sorted.length * 0.99))];
		var room = sorted[Math.floor(sorted.length * 0.1)];
		var thresh = Math.min(TRIM_MAX, Math.max(top * 0.1, room * 4));
		var first = -1, last = -1;
		for (i = 0; i < peaks.length; i++) { if (peaks[i] > thresh) { if (first < 0) { first = i; } last = i; } }
		if (first < 0) { return { first: 0, last: n, thresh: thresh }; }
		return { first: first * win, last: Math.min(n, (last + 1) * win), thresh: thresh };
	}

	/*
	 * How far a recording may be raised (1.9.0). roomDb: its room before the
	 * lift (null when unknown); target: the voice's LUFS target. A quiet room
	 * allows up to MAX_LIFT; a noisy or unknown one MAX_GAIN, as before. A
	 * louder target than -19 gets the difference on top (1.6.0), the
	 * audiobook mode none. Returns { limit, base, byRoom, usual (the limit
	 * before 1.9.0) }.
	 */
	function liftLimit(roomDb, target, mode) {
		var byRoom = roomDb === null || roomDb === undefined || !isFinite(roomDb) ? null : ROOM_CEIL - roomDb;
		var base = byRoom === null ? MAX_GAIN : Math.max(MAX_GAIN, Math.min(MAX_LIFT, byRoom));
		var extra = mode === 'audiobook' ? 0 : Math.max(0, (isFinite(Number(target)) ? Number(target) : -19) + 19);
		return { limit: base + extra, base: base, byRoom: byRoom, usual: MAX_GAIN + extra };
	}

	/*
	 * Voice polish (1.13.0): each step's strength. Standard is exactly the
	 * sound of every version before 1.13.0; Off leaves the step out. An old
	 * page or setting (true or false, or nothing) reads as Standard or Off.
	 *   plosives: how far a pop must rise over the voice's low end, and how
	 *     much of the low band is left in it (0.15 is about -16 dB);
	 *   deess: how loud the S band must grow next to the rest of the voice
	 *     (its share), and the most it is turned down;
	 *   breaths: how much of a breath is kept (0.5 is -6 dB): lowered, never cut;
	 *   compress: the threshold under the loudest speech, and the ratio.
	 */
	var STRENGTH = {
		plosives: { gentle: { over: 15, depth: 0.4 }, standard: { over: 12, depth: 0.15 }, strong: { over: 9, depth: 0.06 } },
		deess: { gentle: { share: 0.7, maxDb: 4 }, standard: { share: 0.5, maxDb: 8 }, strong: { share: 0.35, maxDb: 12 } },
		breaths: { gentle: { depth: 0.708 }, standard: { depth: 0.5 }, strong: { depth: 0.316 } },
		compress: { gentle: { below: 4, ratio: 1.5 }, standard: { below: 6, ratio: 2 }, strong: { below: 9, ratio: 3 } }
	};
	var LEVELS = ['off', 'gentle', 'standard', 'strong'];
	/* A step's strength from a setting: 'off', 'gentle', 'standard' or 'strong'; false is off, anything else standard. */
	function strengthOf(v) {
		if (v === false || v === 'off' || v === 0 || v === '0') { return 'off'; }
		if (v === 'gentle' || v === 'strong') { return v; }
		return 'standard';
	}

	/*
	 * Slow leveling (1.13.0): evens out a level that drifts over minutes, as
	 * a voice tires or the reader leans back. The voice's level over a minute
	 * around each moment (speech only, every 5 s) is brought towards the
	 * level of the whole reading, by at most maxDb either way, so the shape
	 * of a phrase and a stanza is left alone. Readings under 90 s are not
	 * touched: there is nothing to drift.
	 */
	var SLOW = { win: 60, step: 5, minSpeech: 6, minLen: 90, prose: 4, verse: 2 };
	function slowLevel(x, rate, map, maxDb) {
		var dur = x.length / rate;
		var none = { data: x, maxDb: 0, used: false };
		if (!(maxDb > 0)) { return none; }
		if (dur < SLOW.minLen) { return { data: x, maxDb: 0, used: false, short: true }; }
		var H = map.HOP / rate, nF = map.nF, f;
		var cumP = new Float64Array(nF + 1), cumN = new Float64Array(nF + 1);
		for (f = 0; f < nF; f++) {
			var on = map.sp[f] ? 1 : 0;
			cumP[f + 1] = cumP[f] + (on ? Math.pow(10, map.db[f] / 10) : 0);
			cumN[f + 1] = cumN[f] + on;
		}
		var need = SLOW.minSpeech / H;
		if (cumN[nF] < need) { return none; }
		var ref = 10 * Math.log10(cumP[nF] / cumN[nF]);
		var nB = Math.floor(dur / SLOW.step) + 1, g = [], b;
		for (b = 0; b < nB; b++) {
			var t = b * SLOW.step;
			var a = Math.max(0, Math.floor((t - SLOW.win / 2) / H)), e = Math.min(nF, Math.ceil((t + SLOW.win / 2) / H));
			var n = cumN[e] - cumN[a];
			if (n < need) { g.push(null); continue; }
			var loc = 10 * Math.log10((cumP[e] - cumP[a]) / n);
			g.push(Math.max(-maxDb, Math.min(maxDb, ref - loc)));
		}
		/* Stretches with too little speech take the nearest known level. */
		var last = null;
		for (b = 0; b < nB; b++) { if (g[b] === null) { g[b] = last; } else { last = g[b]; } }
		last = null;
		for (b = nB - 1; b >= 0; b--) { if (g[b] === null) { g[b] = last; } else { last = g[b]; } }
		var most = 0;
		for (b = 0; b < nB; b++) { if (g[b] === null) { g[b] = 0; } if (Math.abs(g[b]) > most) { most = Math.abs(g[b]); } }
		if (most < 0.05) { return none; }
		function at(tt) {
			var k = tt / SLOW.step, i0 = Math.min(nB - 1, Math.floor(k)), i1 = Math.min(nB - 1, i0 + 1), u = k - i0;
			return g[i0] + (g[i1] - g[i0]) * (0.5 - 0.5 * Math.cos(Math.PI * Math.min(1, Math.max(0, u))));
		}
		var out = new Float32Array(x.length), B = Math.max(1, Math.round(rate * 0.01));
		var prev = fromDb(at(0));
		for (var s0 = 0; s0 < x.length; s0 += B) {
			var s1 = Math.min(x.length, s0 + B), next = fromDb(at(s1 / rate));
			for (var i = s0; i < s1; i++) { out[i] = x[i] * (prev + (next - prev) * (i - s0) / (s1 - s0)); }
			prev = next;
		}
		return { data: out, maxDb: most, used: true };
	}
	/* How far slow leveling may move a reading of this kind: 2 dB in verse and ritual, 4 otherwise. */
	function slowMax(kind) { return kind === 'verse' || kind === 'ritual' ? SLOW.verse : SLOW.prose; }

	/*
	 * Clicks between words (1.13.0): a click of the tongue or lips in the
	 * quiet between two words, found from Whisper's word times. Only the
	 * quiet well inside a gap is searched (50 ms in from each word, since
	 * Whisper's word edges can be a little late), a click must jump 15 dB
	 * over the gap's own level, last under 15 ms and stay 20 dB under the
	 * reading's loud speech (a consonant's burst is louder than that), and
	 * it is lowered by 12 dB, never cut. A gap with more than six such jumps
	 * holds sound Whisper did not hear as a word, and is left alone, as is
	 * anything longer, so a real k or t at a word's edge is never touched.
	 * gaps: [[start, end], ...] in seconds.
	 */
	var CLICK = { margin: 0.05, jumpDb: 15, maxMs: 15, depth: 0.25, most: 6, underDb: 20 };
	function clicks(x, rate, gaps) {
		if (!gaps || !gaps.length) { return { data: x, count: 0, at: [] }; }
		var out = new Float32Array(x), F = Math.max(1, Math.round(rate * 0.001)), count = 0, at = [];
		var jump = Math.pow(10, CLICK.jumpDb / 10);
		/* The reading's loud speech: the 95th percentile of its 10 ms stretches. */
		var T = Math.max(1, Math.round(rate * 0.01)), lv = [];
		for (var t0 = 0; t0 + T <= x.length; t0 += T) {
			var e10 = 0;
			for (var j = t0; j < t0 + T; j++) { e10 += x[j] * x[j]; }
			lv.push(e10 / T);
		}
		lv.sort(function (p, q) { return p - q; });
		var ceiling = (lv.length ? lv[Math.floor(lv.length * 0.95)] : 1) * Math.pow(10, -CLICK.underDb / 10);
		gaps.forEach(function (gp) {
			var a = Math.max(0, Math.round((gp[0] + CLICK.margin) * rate)), b = Math.min(x.length, Math.round((gp[1] - CLICK.margin) * rate));
			var nF = Math.floor((b - a) / F);
			if (nF < 20) { return; }
			var e = new Float32Array(nF), f, i;
			for (f = 0; f < nF; f++) {
				var sum = 0;
				for (i = a + f * F; i < a + (f + 1) * F; i++) { sum += x[i] * x[i]; }
				e[f] = sum / F;
			}
			var sorted = Array.prototype.slice.call(e).sort(function (p, q) { return p - q; });
			var thr = Math.max(sorted[Math.floor(nF / 2)], 1e-12) * jump;
			var runs = [];
			f = 0;
			while (f < nF) {
				if (e[f] <= thr || e[f] < 1e-9) { f++; continue; }
				var s = f;
				while (f < nF && e[f] > thr * 0.25) { f++; }
				runs.push([s, f]);
			}
			if (runs.length > CLICK.most) { return; }
			runs.forEach(function (r) {
				if (r[1] - r[0] > CLICK.maxMs) { return; }
				var top = 0;
				for (var q = r[0]; q < r[1]; q++) { if (e[q] > top) { top = e[q]; } }
				if (top > ceiling) { return; }
				var s0 = Math.max(0, a + r[0] * F - F), s1 = Math.min(x.length, a + r[1] * F + F);
				for (var k = s0; k < s1; k++) { out[k] *= shape(s1 - s0, F, CLICK.depth, k - s0); }
				count++;
				at.push(s0 / rate);
			});
		});
		return { data: count ? out : x, count: count, at: at };
	}

	/*
	 * EQ per setup (1.13.0). Each take's voice is measured in six octave
	 * bands; a setup (the device and its microphone) gets a suggested EQ
	 * from its first few takes: half the way from its average shape towards
	 * the long-term shape of speech (ANSI S3.5, normal effort), at most 3 dB
	 * either way, with the bands' average kept at zero so the voice's
	 * loudness hardly moves. A band above the recording's top is left out.
	 */
	var EQ = { bands: [250, 500, 1000, 2000, 4000, 8000], ref: [57.2, 59.8, 53.5, 48.8, 43.8, 38.6], maxDb: 3, pull: 0.5, minTakes: 3, maxTakes: 5, q: 1.41 };
	function bandpass(f0, q, rate) {
		var w = 2 * Math.PI * f0 / rate, cs = Math.cos(w), al = Math.sin(w) / (2 * q);
		return norm(al, 0, -al, 1 + al, -2 * cs, 1 - al);
	}
	function peaking(f0, q, gainDb, rate) {
		var A = Math.pow(10, gainDb / 40), w = 2 * Math.PI * f0 / rate, cs = Math.cos(w), al = Math.sin(w) / (2 * q);
		return norm(1 + al * A, -2 * cs, 1 - al * A, 1 + al / A, -2 * cs, 1 - al / A);
	}
	/* The voice's level in each band, in dB, over speech only; null for a band out of reach, or for under 10 s of speech. */
	function measureBands(x, rate, map) {
		map = map || speechMap(x, rate);
		var n = 0, f;
		for (f = 0; f < map.nF; f++) { if (map.sp[f]) { n++; } }
		if (n * map.HOP / rate < 10) { return null; }
		return EQ.bands.map(function (fc) {
			if (fc * 1.41 > rate * 0.45) { return null; }
			/* Two passes, so a band is not swamped by the voice's much louder low end. */
			var bp = bandpass(fc, EQ.q, rate), y = biquad(biquad(x, bp), bp), sum = 0, cnt = 0;
			for (var k = 0; k < map.nF; k++) {
				if (!map.sp[k]) { continue; }
				for (var i = k * map.HOP; i < (k + 1) * map.HOP; i++) { sum += y[i] * y[i]; }
				cnt += map.HOP;
			}
			return Math.round(db(sum / Math.max(1, cnt)) * 10) / 10;
		});
	}
	/*
	 * The suggested EQ from a setup's takes (each a list of band levels from
	 * measureBands). Null until minTakes takes; only the first maxTakes
	 * count. Returns [{ f, g }] with each gain in half decibels.
	 */
	function eqSuggest(list) {
		list = (list || []).filter(function (b) { return b && b.length === EQ.bands.length; }).slice(0, EQ.maxTakes);
		if (list.length < EQ.minTakes) { return null; }
		var use = EQ.bands.map(function (fc, i) { return list.every(function (b) { return b[i] !== null && isFinite(b[i]); }); });
		var meas = EQ.bands.map(function (fc, i) {
			if (!use[i]) { return null; }
			var s = 0;
			list.forEach(function (b) { s += b[i]; });
			return s / list.length;
		});
		var idx = [];
		use.forEach(function (u, i) { if (u) { idx.push(i); } });
		if (idx.length < 3) { return null; }
		function mean(arr) { var s = 0; idx.forEach(function (i) { s += arr[i]; }); return s / idx.length; }
		var mm = mean(meas), mr = mean(EQ.ref);
		var g = EQ.bands.map(function (fc, i) {
			if (!use[i]) { return 0; }
			var d = ((EQ.ref[i] - mr) - (meas[i] - mm)) * EQ.pull;
			return Math.max(-EQ.maxDb, Math.min(EQ.maxDb, d));
		});
		var gm = mean(g);
		return EQ.bands.map(function (fc, i) {
			var v = use[i] ? Math.max(-EQ.maxDb, Math.min(EQ.maxDb, g[i] - gm)) : 0;
			return { f: fc, g: (Math.round(v * 2) / 2) || 0 };
		});
	}
	/* The EQ ([{ f, g }]) run over a recording. */
	function eqApply(x, rate, gains) {
		var y = x, used = false;
		(gains || []).forEach(function (b) {
			var gd = Number(b.g), fc = Number(b.f);
			if (!gd || !isFinite(gd) || !(fc > 20) || fc * 1.41 > rate * 0.45) { return; }
			y = biquad(y, peaking(fc, EQ.q, Math.max(-EQ.maxDb, Math.min(EQ.maxDb, gd)), rate));
			used = true;
		});
		return { data: y, used: used };
	}

	/*
	 * The whole chain. opts: { mode: 'podcast' | 'audiobook', target (LUFS,
	 * podcast only, of this mono voice), ceilingTp (1.6.0, the preset's
	 * internal limit; else the mode's own), hp, plosives, deess, breaths,
	 * compress (1.13.0: each off, gentle, standard or strong), slow and
	 * slowMax, eq ([{ f, g }]), gaps (for clicks), measure (the bands),
	 * bed }. Returns the
	 * processed audio and a report of what each step did.
	 */
	function process(input, rate, opts) {
		var P = withCeiling(PRESETS[opts.mode] || PRESETS.podcast, opts.ceilingTp);
		var x = new Float32Array(input), rep = { mode: opts.mode };
		var map = speechMap(x, rate);
		/* Voice polish (1.13.0): each step's strength, Standard unless set. */
		var lv = { plosives: strengthOf(opts.plosives), deess: strengthOf(opts.deess), breaths: strengthOf(opts.breaths), compress: strengthOf(opts.compress) };
		rep.strengths = lv;
		/* The voice's bands, measured before any step, for the setup's EQ (1.13.0). */
		if (opts.measure) { rep.bands = measureBands(x, rate, map); }
		// Pops first: the high-pass filter removes part of each one, which would hide them from the count.
		if (lv.plosives !== 'off') { var pl = plosives(x, rate, map, STRENGTH.plosives[lv.plosives]); x = pl.data; rep.plosives = pl.count; }
		if (opts.hp !== false) {
			var hp = highpass(80, 0.7071, rate);
			x = biquad(biquad(x, hp), hp);
			rep.highpass = true;
		}
		if (opts.eq ? opts.eq.length : false) { var eqd = eqApply(x, rate, opts.eq); x = eqd.data; if (eqd.used) { rep.eq = opts.eq; } }
		if (opts.gaps ? opts.gaps.length : false) { var ck = clicks(x, rate, opts.gaps); x = ck.data; rep.clicks = ck.count; }
		// Breaths before the de-esser: a breath is a hiss too, and should not be counted as an S.
		var breathMask = null;
		if (lv.breaths !== 'off') { var br = breaths(x, rate, map, STRENGTH.breaths[lv.breaths]); x = br.data; rep.breaths = br.count; breathMask = br.mask; }
		if (lv.deess !== 'off') { var de = deess(x, rate, map, breathMask, STRENGTH.deess[lv.deess]); x = de.data; rep.sibilants = de.count; rep.deessMaxDb = de.maxCutDb; }
		/* Slow leveling before compression, so the compressor sees an even voice (1.13.0). */
		if (opts.slow) { var sl = slowLevel(x, rate, map, Number(opts.slowMax) || SLOW.prose); x = sl.data; rep.slowDb = sl.maxDb; if (sl.short) { rep.slowShort = true; } rep.slowMaxDb = Number(opts.slowMax) || SLOW.prose; }
		if (lv.compress !== 'off') { var co = compress(x, rate, map, STRENGTH.compress[lv.compress]); x = co.data; rep.compressAvgDb = co.avgDb; rep.compressMaxDb = co.maxDb; }
		/* The room is measured before the music bed joins, which would otherwise count as the room (1.9.0). */
		var room = roomLevel(x, rate);
		/* The music bed (0.24.0) joins after the voice chain, so it is leveled and limited with the voice. */
		if (opts.bed ? opts.bed.data : false) { var mb = mixBed(x, rate, opts.bed); x = mb.data; rep.bed = mb.report; }

		var gainDb;
		if (opts.mode === 'audiobook') {
			gainDb = P.rmsTarget - rmsDb(x);
		} else {
			var before = lufs(x, rate);
			gainDb = isFinite(before) ? opts.target - before : 0;
		}
		/*
		 * A very quiet recording is raised by MAX_GAIN dB at most, so near
		 * silence is never blown up. 24 dB until 1.6.0, when a laptop
		 * microphone's readings were found needing 25 to 27 dB with the
		 * background still 66 dB down after noise reduction; 30 now. The limit
		 * is set for -19 LUFS; a louder target (Spotify and YouTube at -14, say)
		 * gets the difference on top, so any recording loud enough for a
		 * podcast reaches the louder presets too. The corrections below may go
		 * 6 dB further to win back what the limiter takes.
		 *
		 * Since 1.9.0 the limit follows the room: one quiet enough that the
		 * lift keeps it at -60 dB or below may go up to MAX_LIFT (a phone in
		 * the browser needs about 35 dB), measured here, after cleanup and
		 * noise reduction, on the voice that is actually raised.
		 */
		var lim = liftLimit(room, opts.target, opts.mode);
		var maxGain = lim.limit;
		rep.roomDb = room;
		rep.maxGainDb = maxGain;
		rep.liftByRoom = lim.base > MAX_GAIN;
		rep.usualGainDb = lim.usual;
		rep.gainDb = Math.min(maxGain, gainDb);
		rep.gainCapped = gainDb > maxGain;
		var y = levelAndLimit(x, rate, P, rep.gainDb);
		// The limiter takes a little loudness with it, so measure again and
		// correct once, so the reading lands on its target.
		/*
		 * Loud presets with a low peak limit (1.6.0) can need more than one
		 * correction, since the limiter takes more as the level rises: each
		 * try steps by the change the last one actually made (at most 6 dB).
		 */
		var want = opts.mode === 'audiobook' ? P.rmsTarget : opts.target;
		var meas = function (d) { return opts.mode === 'audiobook' ? rmsDb(d) : lufs(d, rate); };
		var gPrev = null, lPrev = null;
		for (var pass = 0; pass < 6 && !rep.gainCapped; pass++) {
			var after = meas(y.data);
			if (!isFinite(after) || Math.abs(want - after) <= 0.2) { break; }
			var slope = 1;
			if (gPrev !== null && Math.abs(rep.gainDb - gPrev) > 0.01) { slope = Math.max(0.1, Math.min(1, (after - lPrev) / (rep.gainDb - gPrev))); }
			var next = Math.min(maxGain + 6, rep.gainDb + Math.max(-6, Math.min(6, (want - after) / slope)));
			if (Math.abs(next - rep.gainDb) < 0.05) { break; }
			gPrev = rep.gainDb;
			lPrev = after;
			rep.gainDb = next;
			y = levelAndLimit(x, rate, P, rep.gainDb);
		}
		rep.limiterMaxDb = y.maxReductionDb;
		return { data: y.data, report: rep };
	}

	/*
	 * Hear the polish (1.13.0): one stretch of a reading (a to b, in
	 * seconds) as it sounds with these settings, and as recorded at the same
	 * loudness. The chain runs over the stretch with 8 s either side, so its
	 * steps hear what is around it; slow leveling works over minutes, so it
	 * is left out here. Returns { before, after, report }.
	 */
	function preview(input, rate, opts, a, b) {
		var n = input.length, pad = 8;
		var s0 = Math.max(0, Math.floor((a - pad) * rate)), s1 = Math.min(n, Math.ceil((b + pad) * rate));
		var win = input.subarray(s0, s1), o = {};
		Object.keys(opts || {}).forEach(function (k) { o[k] = opts[k]; });
		o.slow = false;
		o.bed = null;
		o.measure = false;
		var off = s0 / rate;
		o.gaps = (opts.gaps || []).filter(function (g) { return g[1] > off && g[0] < s1 / rate; }).map(function (g) { return [g[0] - off, g[1] - off]; });
		var r = process(win, rate, o);
		var i0 = Math.max(0, Math.floor(a * rate) - s0), i1 = Math.min(win.length, Math.ceil(b * rate) - s0);
		var after = r.data.slice(i0, i1);
		var raw = new Float32Array(win.subarray(i0, i1));
		var la = lufs(after, rate), lb = lufs(raw, rate);
		var g = isFinite(la) && isFinite(lb) ? la - lb : r.report.gainDb;
		var P = withCeiling(PRESETS[o.mode] || PRESETS.podcast, o.ceilingTp);
		var before = levelAndLimit(raw, rate, P, g).data;
		return { before: before, after: after, report: r.report };
	}

	/*
	 * The studio-ready check, run on the decoded MP3 so it measures what a
	 * listener or Audible actually receives.
	 */
	/*
	 * more (1.6.0, optional): { chans (every channel of the file, x being the
	 * first), tp (the true peak limit, -1 by default), format ('mp3' or 'wav'),
	 * bits, label (who the file is for) }. Loudness counts every channel;
	 * peaks are the highest of any channel. The loudness range is measured
	 * every time, for information.
	 */
	function check(x, rate, mode, target, kbps, more) {
		more = more || {};
		var chans = more.chans && more.chans.length ? more.chans : [x];
		var tpLimit = isFinite(Number(more.tp)) && more.tp !== null ? Number(more.tp) : -1;
		var m = {
			lufs: lufsMulti(chans, rate),
			truePeak: Math.max.apply(null, chans.map(truePeakDb)),
			samplePeak: Math.max.apply(null, chans.map(samplePeakDb)),
			rms: rmsDb(x),
			noise: noiseFloorDb(x, rate),
			lra: loudnessRange(x, rate),
			channels: chans.length
		};
		var e = edges(x, rate);
		m.head = e.head;
		m.tail = e.tail;
		var clipped = 0;
		chans.forEach(function (ch) { for (var i = 0; i < ch.length; i++) { if (Math.abs(ch[i]) >= 0.999) { clipped++; } } });
		m.clipped = clipped;
		var items = [];
		function r1(v) { return Math.round(v * 10) / 10; }
		if (mode === 'audiobook') {
			items.push({ label: __( 'Level (RMS)', 'foxfire-studio' ), value: sprintf( /* translators: %s: r1(m.rms). */ __( '%s dB', 'foxfire-studio' ), r1(m.rms) ), ok: m.rms >= -23 && m.rms <= -18, rule: __( 'between -23 and -18 dB', 'foxfire-studio' ) });
			items.push({ label: __( 'Peaks', 'foxfire-studio' ), value: sprintf( /* translators: %s: r1(m.samplePeak). */ __( '%s dB', 'foxfire-studio' ), r1(m.samplePeak) ), ok: m.samplePeak <= -3, rule: __( '-3 dB or lower', 'foxfire-studio' ) });
			items.push({ label: __( 'Background', 'foxfire-studio' ), value: sprintf( /* translators: %s: r1(m.noise). */ __( '%s dB', 'foxfire-studio' ), r1(m.noise) ), ok: m.noise <= -60, rule: __( '-60 dB or quieter', 'foxfire-studio' ) });
			items.push({ label: __( 'Room tone at the start', 'foxfire-studio' ), value: r1(m.head) + ' s', ok: m.head >= 1 && m.head <= 5, rule: __( '1 to 5 seconds', 'foxfire-studio' ) });
			items.push({ label: __( 'Room tone at the end', 'foxfire-studio' ), value: r1(m.tail) + ' s', ok: m.tail >= 1 && m.tail <= 5, rule: __( '1 to 5 seconds', 'foxfire-studio' ) });
			items.push({ label: 'MP3', value: sprintf( /* translators: %s: kbps. */ __( '%s kbps constant, 44.1 kHz', 'foxfire-studio' ), kbps ), ok: kbps >= 192, rule: __( '192 kbps or more, constant', 'foxfire-studio' ) });
		} else {
			items.push({ label: __( 'Loudness', 'foxfire-studio' ), value: sprintf( /* translators: %s: r1(m.lufs). */ __( '%s LUFS', 'foxfire-studio' ), r1(m.lufs) ), ok: Math.abs(m.lufs - target) <= 1, rule: sprintf( /* translators: %s: target. */ __( 'within 1 of %s', 'foxfire-studio' ), target ) });
			items.push({ label: __( 'True peak', 'foxfire-studio' ), value: sprintf( /* translators: %s: r1(m.truePeak). */ __( '%s dBTP', 'foxfire-studio' ), r1(m.truePeak) ), ok: m.truePeak <= tpLimit + 0.05, rule: sprintf( /* translators: %s: the true peak limit. */ __( '%s dBTP or lower', 'foxfire-studio' ), tpLimit ) });
			// Podcast specs set no limit for the background, so this one is information only.
			items.push({ label: __( 'Background', 'foxfire-studio' ), value: sprintf( /* translators: %s: r1(m.noise). */ __( '%s dB', 'foxfire-studio' ), r1(m.noise) ), ok: null, rule: __( 'no podcast rule; audiobooks need -60 dB or quieter', 'foxfire-studio' ) });
		}
		/* Loudness range (1.6.0): information, with a note when it is wide. */
		if (m.lra !== null) {
			items.push({ label: __( 'Loudness range', 'foxfire-studio' ), value: sprintf( /* translators: %s: loudness range in LU. */ __( '%s LU', 'foxfire-studio' ), r1(m.lra) ), ok: null, wide: m.lra > LRA_WIDE, rule: m.lra > LRA_WIDE ? __( 'wide for speech: the quiet parts may be hard to hear in a car or on a bus; reading at a steadier level or more compression would narrow it', 'foxfire-studio' ) : __( '10 LU or less suits speech', 'foxfire-studio' ) });
		}
		if (more.format === 'wav') {
			items.push({ label: 'WAV', value: sprintf( /* translators: 1: channels, 2: kHz, 3: bits. */ __( '%1$s, %2$s kHz, %3$s-bit', 'foxfire-studio' ), chans.length === 2 ? __( 'stereo', 'foxfire-studio' ) : __( 'mono', 'foxfire-studio' ), Math.round(rate / 100) / 10, more.bits || 24 ), ok: null, rule: __( 'as broadcasters ask for', 'foxfire-studio' ) });
		}
		items.push({ label: __( 'Clipping', 'foxfire-studio' ), value: clipped ? sprintf( /* translators: %s: clipped. */ __( '%s samples', 'foxfire-studio' ), clipped ) : 'none', ok: clipped === 0, rule: 'none' });
		return { measures: m, items: items, pass: items.every(function (it) { return it.ok !== false; }) };
	}

	/*
	 * The music bed (0.24.0): quiet music under a reading, mixed after the
	 * voice chain and before loudness, so the finished file is leveled and
	 * limited as one.
	 *
	 *   music alone (lead) > dips just before the first word > stays low under
	 *   the voice > rises after the last word > fades out (tail)
	 *
	 * The music is looped with a crossfade when it is shorter than the reading.
	 * Levels are set from loudness: the music alone sits FULL_GAP below the
	 * voice, and under the voice it sits depth (16, 20 or 24 dB) below it.
	 * With swell on, it rises a little (SWELL_DB) in pauses of over 2 s.
	 */
	var BED = {
		lead: 4,       /* Seconds of music before the voice starts. */
		tail: 5,       /* Seconds of music after the voice ends. */
		fullGap: 6,    /* The music alone, in dB below the voice. */
		fadeIn: 1,     /* The music's own start, from silence. */
		fadeOut: 3,    /* The last seconds of the tail. */
		xfade: 3,      /* Loop crossfade, seconds. */
		before: 0.15,  /* The dip is complete this long before a word. */
		down: 0.6,     /* How long a dip takes. */
		up: 1.2,       /* How long the rise after the last word takes. */
		after: 0.4,    /* The rise starts this long after the last word. */
		pause: 2,      /* With swell on: pauses longer than this rise. */
		swellDb: 6,
		depths: [16, 20, 24]
	};

	/* The music repeated (or cut) to exactly n samples, with an equal-power crossfade at each join. */
	function loopTo(m, n, rate, xfadeS) {
		var out = new Float32Array(n);
		if (!m.length || !n) { return out; }
		if (m.length >= n) { out.set(m.subarray(0, n)); return out; }
		var xf = Math.max(1, Math.min(Math.round((xfadeS || BED.xfade) * rate), Math.floor(m.length / 3)));
		var step = m.length - xf;
		var at = 0, first = true;
		while (at < n) {
			for (var i = 0; i < m.length; i++) {
				var j = at + i;
				if (j >= n) { break; }
				var v = m[i];
				if (!first) {
					if (i < xf) { v *= Math.sin(0.5 * Math.PI * (i / xf)); }
				}
				if (i >= step) {
					var k = (i - step) / xf;
					/* The next pass fades in here; this one fades out, unless it is the last. */
					if (at + step < n) { v *= Math.cos(0.5 * Math.PI * k); }
				}
				out[j] += v;
			}
			at += step;
			first = false;
		}
		return out;
	}

	/*
	 * Where the voice speaks, in seconds: the first and last word and the
	 * longer pauses between. A run of under 60 ms (a click) is not speech.
	 */
	function voiceRuns(v, rate, pauseS) {
		var map = speechMap(v, rate);
		var H = map.HOP / rate, runs = [], f = 0;
		while (f < map.nF) {
			if (!map.sp[f]) { f++; continue; }
			var s = f;
			while (f < map.nF ? map.sp[f] : false) { f++; }
			if (f - s >= 3) { runs.push([s * H, f * H]); }
		}
		/* Gaps of under 250 ms are part of the same phrase. */
		var merged = [];
		runs.forEach(function (r) {
			if (merged.length ? r[0] - merged[merged.length - 1][1] < 0.25 : false) { merged[merged.length - 1][1] = r[1]; } else { merged.push(r); }
		});
		var pauses = [];
		for (var i = 1; i < merged.length; i++) {
			if (merged[i][0] - merged[i - 1][1] > pauseS) { pauses.push([merged[i - 1][1], merged[i][0]]); }
		}
		return {
			first: merged.length ? merged[0][0] : null,
			last: merged.length ? merged[merged.length - 1][1] : null,
			pauses: pauses
		};
	}

	/*
	 * The music's level over time, in dB relative to its level alone, as
	 * points [seconds, dB] joined by smooth (cosine) curves.
	 */
	function bedPoints(runs, opts) {
		var lead = BED.lead, low = -(opts.depth - BED.fullGap), pts = [[0, 0]];
		if (runs.first === null) { return pts; }
		var a = lead + runs.first - BED.before;
		pts.push([Math.max(0, a - BED.down), 0], [Math.max(0.01, a), low]);
		if (opts.swell) {
			var high = Math.min(0, low + BED.swellDb);
			runs.pauses.forEach(function (p) {
				var r0 = lead + p[0] + 0.4, r1 = r0 + 0.8;
				var d1 = lead + p[1] - BED.before, d0 = d1 - BED.down;
				if (d0 > r1) { pts.push([r0, low], [r1, high], [d0, high], [d1, low]); }
			});
		}
		var b = lead + runs.last + BED.after;
		pts.push([b, low], [b + BED.up, 0]);
		return pts;
	}
	function pointsAt(pts, t) {
		if (t <= pts[0][0]) { return pts[0][1]; }
		for (var i = 1; i < pts.length; i++) {
			if (t < pts[i][0]) {
				var p = pts[i - 1], q = pts[i], u = (t - p[0]) / Math.max(1e-6, q[0] - p[0]);
				return p[1] + (q[1] - p[1]) * (0.5 - 0.5 * Math.cos(Math.PI * u));
			}
		}
		return pts[pts.length - 1][1];
	}

	/*
	 * Mixes the music under the voice. bed: { data (mono Float32Array at
	 * rate), depth (dB below the voice, default 20), swell }. Returns
	 * { data, report } where data is lead + voice + tail long.
	 */
	function mixBed(voice, rate, bed) {
		var depth = BED.depths.indexOf(Number(bed.depth)) >= 0 ? Number(bed.depth) : 20;
		var leadN = Math.round(BED.lead * rate), tailN = Math.round(BED.tail * rate);
		var n = leadN + voice.length + tailN;
		var vL = lufs(voice, rate), mL = lufs(bed.data, rate);
		var out = new Float32Array(n);
		out.set(voice, leadN);
		if (!isFinite(vL) || !isFinite(mL)) {
			return { data: out, report: { used: false, lead: BED.lead, tail: BED.tail, depth: depth } };
		}
		var m = loopTo(bed.data, n, rate, BED.xfade);
		var g0 = fromDb(vL - BED.fullGap - mL);
		var runs = voiceRuns(voice, rate, BED.pause);
		var pts = bedPoints(runs, { depth: depth, swell: !!bed.swell });
		var fiN = Math.round(BED.fadeIn * rate), foN = Math.round(BED.fadeOut * rate);
		/* The level curve is worked out every 5 ms and eased between. */
		var H = Math.max(1, Math.round(rate * 0.005)), prev = fromDb(pointsAt(pts, 0)), next;
		for (var s = 0; s < n; s += H) {
			next = fromDb(pointsAt(pts, (s + H) / rate));
			var end = Math.min(n, s + H);
			for (var i = s; i < end; i++) {
				var g = prev + (next - prev) * ((i - s) / H);
				if (i < fiN) { g *= i / fiN; }
				if (n - i < foN) { g *= 0.5 - 0.5 * Math.cos(Math.PI * (n - i) / foN); }
				out[i] += m[i] * g0 * g;
			}
			prev = next;
		}
		return {
			data: out,
			report: { used: true, lead: leadN / rate, tail: tailN / rate, depth: depth, swell: !!bed.swell, voiceLufs: vL, musicLufs: mL, gainDb: 20 * Math.log10(g0), first: runs.first, last: runs.last, swells: bed.swell ? runs.pauses.length : 0, looped: bed.data.length < n }
		};
	}

	root.RRTSound = {
		STRENGTH: STRENGTH,
		LEVELS: LEVELS,
		strengthOf: strengthOf,
		SLOW: SLOW,
		slowMax: slowMax,
		CLICK: CLICK,
		EQ: EQ,
		measureBands: measureBands,
		eqSuggest: eqSuggest,
		preview: preview,
		BED: BED,
		mixBed: mixBed,
		PRESETS: PRESETS,
		MAX_GAIN: MAX_GAIN,
		MAX_LIFT: MAX_LIFT,
		ROOM_CEIL: ROOM_CEIL,
		roomLevel: roomLevel,
		liftLimit: liftLimit,
		speechBounds: speechBounds,
		pauses: pauses,
		roomPad: roomPad,
		levelRms: levelRms,
		ROOM: ROOM,
		TRIM_MAX: TRIM_MAX,
		process: process,
		check: check,
		adjust: adjust,
		lufs: lufs,
		lufsMulti: lufsMulti,
		loudnessRange: loudnessRange,
		LRA_WIDE: LRA_WIDE,
		STEREO_LU: STEREO_LU,
		plan: plan,
		wav: wav,
		toLoudness: toLoudness,
		truePeakDb: truePeakDb,
		samplePeakDb: samplePeakDb,
		rmsDb: rmsDb,
		noiseFloorDb: noiseFloorDb,
		limit: limit,
		_internal: { loopTo: loopTo, voiceRuns: voiceRuns, bedPoints: bedPoints, pointsAt: pointsAt, biquad: biquad, highpass: highpass, lowpass: lowpass, kShelf: kShelf, kHighpass: kHighpass, speechMap: speechMap, plosives: plosives, deess: deess, breaths: breaths, compress: compress, edges: edges, joinXf: joinXf, slowLevel: slowLevel, clicks: clicks, eqApply: eqApply, peaking: peaking }
	};

	/*
	 * Run as a background worker too (new Worker('sound.js')), so a long
	 * reading never freezes the page. Messages: { cmd: 'process' | 'adjust' | 'check', ... }.
	 */
	if (typeof window === 'undefined' && typeof self !== 'undefined' && typeof self.postMessage === 'function') {
		self.onmessage = function (e) {
			var d = e.data;
			try {
				if (d.cmd === 'process') {
					var r = process(d.data, d.rate, d.opts);
					self.postMessage({ id: d.id, data: r.data, report: r.report }, [r.data.buffer]);
				} else if (d.cmd === 'adjust') {
					var a = adjust(d.data, d.rate, d.mode, d.gainDb, d.ceilingTp);
					self.postMessage({ id: d.id, data: a }, [a.buffer]);
				} else if (d.cmd === 'preview') {
					var pv = preview(d.data, d.rate, d.opts, d.a, d.b);
					self.postMessage({ id: d.id, before: pv.before, after: pv.after, report: pv.report }, [pv.before.buffer, pv.after.buffer]);
				} else if (d.cmd === 'check') {
					self.postMessage({ id: d.id, check: check(d.data, d.rate, d.mode, d.target, d.kbps, d.more) });
				}
			} catch (err) {
				self.postMessage({ id: d.id, error: String(err && err.message || err) });
			}
		};
	}
})(typeof window !== 'undefined' ? window : (typeof self !== 'undefined' ? self : this));
