'use strict';
/*
 * The stronger cleanup (1.16.0): assets/js/deepfilter.js without its model.
 * A stand-in for ONNX Runtime answers with a gain of 1 in every band and a
 * deep filter that passes the current frame through, so the transform, the
 * chunks, the lift and the cap can be checked exactly. The real model is
 * run in tests/browser/stronger.py. Also denoise.js's steady-tone count and
 * the Room line's wording for either engine.
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const { load } = require('./load');

const DF = load('deepfilter.js');
const DN = load('denoise.js');
const R = load('readout.js', 'RRTReadout');
const RATE = 48000;

/* A stand-in model. gain: the ERB gain to answer with; tap: the deep filter's pass-through tap. */
function fakeModel(gain, opts) {
	opts = opts || {};
	const ort = { Tensor: function (type, data, dims) { this.type = type; this.data = data; this.dims = dims; } };
	const runs = [];
	const enc = {
		run(feeds) {
			const T = feeds.feat_erb.dims[2];
			runs.push(T);
			if (opts.check) { opts.check(feeds); }
			return Promise.resolve({ e0: {}, e1: {}, e2: {}, e3: {}, emb: { T }, c0: { T }, lsnr: { data: new Float32Array(T).fill(opts.lsnr === undefined ? 30 : opts.lsnr) } });
		}
	};
	const erb = { run(f) { return Promise.resolve({ m: { data: new Float32Array(f.emb.T * 32).fill(gain) } }); } };
	const dfd = {
		run(f) {
			const T = f.emb.T, c = new Float32Array(T * 96 * 5 * 2);
			// Tap 2 of 5 is the current frame (order 5, 2 frames of lookahead): a filter of 1 there passes it through.
			for (let t = 0; t < T; t++) { for (let k = 0; k < 96; k++) { c[((t * 96 + k) * 5 + 2) * 2] = gain; } }
			return Promise.resolve({ coefs: { data: c } });
		}
	};
	return { ort, enc, erb, dfd, runs };
}

function voiceLike(seconds, level, seed) {
	const n = Math.round(seconds * RATE), x = new Float32Array(n);
	let s = seed || 7;
	for (let i = 0; i < n; i++) {
		s = (s * 1103515245 + 12345) % 2147483648;
		const t = i / RATE, on = Math.sin(2 * Math.PI * 0.7 * t) > -0.2 ? 1 : 0.05;
		x[i] = level * (on * (0.6 * Math.sin(2 * Math.PI * 140 * t) + 0.3 * Math.sin(2 * Math.PI * 720 * t + 1) + 0.1 * Math.sin(2 * Math.PI * 2400 * t)) + 0.02 * (s / 2147483648 - 0.5));
	}
	return x;
}
function maxErr(a, b) {
	let m = 0;
	for (let i = 0; i < a.length; i++) { m = Math.max(m, Math.abs(a[i] - b[i])); }
	return m;
}

test('the ERB bands cover all 481 bins, as libDF makes them', () => {
	const w = DF._internal.erbWidths();
	assert.deepEqual(w, [2, 2, 2, 2, 2, 2, 2, 2, 2, 2, 2, 2, 2, 5, 5, 7, 7, 8, 10, 12, 13, 15, 18, 20, 24, 28, 31, 37, 42, 50, 56, 67]);
	assert.equal(w.reduce((a, b) => a + b, 0), 481);
});

test('the 960-point FFT matches a plain DFT', () => {
	const n = 960, fft = DF._internal.plan(n);
	const re = new Float64Array(n), im = new Float64Array(n), or = new Float64Array(n), oi = new Float64Array(n);
	for (let i = 0; i < n; i++) { re[i] = Math.sin(i * 0.37) + (i % 7) / 7; im[i] = Math.cos(i * 0.11); }
	fft(re, im, or, oi);
	let worst = 0;
	for (const k of [0, 1, 2, 3, 59, 240, 479, 480, 481, 959]) {
		let sr = 0, si = 0;
		for (let t = 0; t < n; t++) {
			const a = -2 * Math.PI * k * t / n;
			sr += re[t] * Math.cos(a) - im[t] * Math.sin(a);
			si += re[t] * Math.sin(a) + im[t] * Math.cos(a);
		}
		worst = Math.max(worst, Math.abs(sr - or[k]), Math.abs(si - oi[k]));
	}
	assert.ok(worst < 1e-9, String(worst));
});

test('with a gain of 1 the reading comes back as it went in, across chunks', async () => {
	const x = voiceLike(23.4, 0.2);
	const M = fakeModel(1);
	const r = await DF.denoise(M, x, { strength: 'strong' });
	assert.equal(r.data.length, x.length);
	assert.ok(maxErr(r.data, x) < 1e-4, String(maxErr(r.data, x)));
	// 23.4 s is three chunks; each after the first starts 2 s early.
	assert.equal(M.runs.length, 3);
	assert.equal(M.runs[1], 200 + 1000 + 6);
});

test('a gain of 0 with no cap leaves silence; a 20 dB cap leaves a tenth', async () => {
	const x = voiceLike(5, 0.2);
	const off = await DF.denoise(fakeModel(0), x, { strength: 'strong' });
	assert.ok(maxErr(off.data, new Float32Array(x.length)) < 1e-6);
	const capped = await DF.denoise(fakeModel(0), x, { strength: 'medium' });
	const want = x.map(v => v * 0.1);
	assert.ok(maxErr(capped.data, want) < 1e-4, String(maxErr(capped.data, want)));
	assert.equal(capped.report.strength, 'medium');
	assert.equal(off.report.engine, 'stronger');
});

test('a quiet phone take is lifted for the model and put back afterwards', async () => {
	const loud = voiceLike(6, 0.2), quiet = loud.map(v => v * 0.002); // about 54 dB down, as an iPhone take
	assert.ok(DF._internal.liftFor(quiet) > 45, String(DF._internal.liftFor(quiet)));
	assert.ok(Math.abs(DF._internal.liftFor(quiet) - DF._internal.liftFor(loud) - 54) < 0.5);
	let seen = 0;
	const M = fakeModel(1, { check(f) { seen = Math.max(seen, f.feat_erb.data.reduce((a, v) => Math.max(a, v), -9)); } });
	const r = await DF.denoise(M, quiet, { strength: 'strong' });
	assert.ok(maxErr(r.data, quiet) < 1e-6);
	assert.ok(r.report.liftDb > 45);
	// The model is fed the same features whether the take came in loud or quiet.
	let seenLoud = 0;
	await DF.denoise(fakeModel(1, { check(f) { seenLoud = Math.max(seenLoud, f.feat_erb.data.reduce((a, v) => Math.max(a, v), -9)); } }), loud, { strength: 'strong' });
	assert.ok(Math.abs(seen - seenLoud) < 1e-3, seen + ' ' + seenLoud);
});

test('a device too slow for it gives up after the first chunk', async () => {
	const x = voiceLike(25, 0.2);
	let clock = 0;
	await assert.rejects(
		DF.denoise(fakeModel(1), x, { strength: 'strong', maxRtf: 0.6, now() { clock += 8000; return clock; } }),
		(err) => /too slow/.test(err.message) && err.slow > 0.6
	);
	clock = 0;
	const ok = await DF.denoise(fakeModel(1), x, { strength: 'strong', maxRtf: 0.6, now() { clock += 100; return clock; } });
	assert.equal(ok.data.length, x.length);
});

test('the report reads like RNNoise\'s, for the Room line', async () => {
	const r = await DF.denoise(fakeModel(0.3), voiceLike(8, 0.2), { strength: 'strong' });
	for (const k of ['snrBefore', 'snrAfter', 'floorBefore', 'floorAfter', 'dropDb', 'quietBefore', 'quietAfter', 'speechShare', 'seconds']) {
		assert.ok(Number.isFinite(r.report[k]), k);
	}
	assert.ok(Math.abs(r.report.dropDb - 10.5) < 0.6, String(r.report.dropDb));
});

/* denoise.js: steady tones in the pauses (a bell, a whine) against broad noise. */
function pauses(seconds, withTones) {
	const n = Math.round(seconds * RATE), x = new Float32Array(n);
	let s = 11;
	for (let i = 0; i < n; i++) {
		s = (s * 1103515245 + 12345) % 2147483648;
		x[i] = 0.002 * (s / 2147483648 - 0.5);
		if (withTones) {
			const t = i / RATE, strike = Math.exp(-((t % 2.5)) * 0.8);
			x[i] += 0.0015 * strike * (Math.sin(2 * Math.PI * 1605 * t) + Math.sin(2 * Math.PI * 4693 * t) + 0.7 * Math.sin(2 * Math.PI * 5220 * t) + 0.5 * Math.sin(2 * Math.PI * 8853 * t));
		}
	}
	return x;
}
test('steady tones in the pauses are counted; broad noise has none', () => {
	const vad = new Float32Array(Math.floor(12 * RATE / 480)); // no speech heard anywhere
	assert.ok(DN._internal.tones(pauses(12, true), vad, RATE) >= 3);
	assert.equal(DN._internal.tones(pauses(12, false), vad, RATE), 0);
	assert.equal(DN._internal.tones(pauses(0.5, true), vad.subarray(0, 50), RATE), 0);
});

/* The Room line (readout.js) for either engine. */
function roomLine(room) {
	const base = { snr: 30, clicks: [], strays: [], whistleShare: 0 };
	const lines = R.describeReading({ wpm: 140, setWpm: 140, restShare: 0.2, endDropDb: 2, pitchSpread: 2, room: Object.assign(base, room) }, false, String);
	const l = lines.find(x => x.label === 'Room');
	return l ? l.text : '';
}
test('room: the stronger cleanup says it did the work', () => {
	const t = roomLine({ denoise: { engine: 'stronger', dropDb: 21, snrAfter: 50 }, strongerReady: true });
	assert.match(t, /The stronger cleanup did this\.$/);
	assert.doesNotMatch(t, /compare it/);
});
test('room: when the stronger cleanup falls back, the line says why', () => {
	const t = roomLine({ denoise: { engine: 'standard', dropDb: 14, snrAfter: 43 }, strongerFailed: 'This device was too slow for it', strongerReady: true });
	assert.match(t, /The stronger cleanup could not run this time \(this device was too slow for it\), so the standard one was used\./);
	assert.doesNotMatch(t, /compare it/);
});
test('room: a bell in the pauses, or a noisy room, suggests the stronger cleanup', () => {
	assert.match(roomLine({ snr: 36, denoise: { engine: 'standard', dropDb: 14, snrAfter: 47, tones: 7 }, strongerReady: true }), /steady tones in the pauses, such as a bell or a hum\. The stronger cleanup takes those out better: compare it on a sentence in Hear the polish, or choose it in Settings\.$/);
	assert.match(roomLine({ snr: 29, denoise: { engine: 'standard', dropDb: 14, snrAfter: 43, tones: 0 }, strongerReady: true }), /The stronger cleanup may take out more: compare it on a sentence in Hear the polish, or choose it in Settings\.$/);
	assert.doesNotMatch(roomLine({ snr: 38, denoise: { engine: 'standard', dropDb: 10, snrAfter: 48, tones: 0 }, strongerReady: true }), /stronger/);
	assert.doesNotMatch(roomLine({ snr: 29, denoise: { engine: 'standard', dropDb: 14, snrAfter: 43, tones: 5 } }), /stronger/);
});
