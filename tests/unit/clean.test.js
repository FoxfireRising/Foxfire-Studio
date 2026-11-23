'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { load } = require('./load');

const C = load('clean.js');
const { RATE, hush, reading, rms } = require('./synth');

function run(data, skipNoise) {
	const c = C.cleanReading(data, RATE, skipNoise);
	let p = 0;
	let guard = 0;
	while (p < 1 && guard++ < 100000) { p = c.step(250); }
	return { out: c.finish(), report: c.report };
}

test('the FFT finds a tone in its own bin', () => {
	const N = 1024;
	const fft = C.makeFFT(N);
	const re = new Float64Array(N);
	const im = new Float64Array(N);
	for (let i = 0; i < N; i++) { re[i] = Math.sin(2 * Math.PI * 37 * i / N); }
	fft(re, im, false);
	let best = 0;
	for (let k = 1; k < N / 2; k++) { if (Math.hypot(re[k], im[k]) > Math.hypot(re[best], im[best])) { best = k; } }
	assert.equal(best, 37);
});

test('the FFT and its inverse give back the original', () => {
	const N = 512;
	const fft = C.makeFFT(N);
	const orig = hush(N, 1, 3);
	const re = Float64Array.from(orig);
	const im = new Float64Array(N);
	fft(re, im, false);
	fft(re, im, true);
	for (let i = 0; i < N; i++) {
		assert.ok(Math.abs(re[i] - orig[i]) < 1e-9);
		assert.ok(Math.abs(im[i]) < 1e-9);
	}
});

test('a clip too short to judge comes back unchanged', () => {
	const x = hush(RATE * 0.5, 0.1);
	const r = run(x);
	assert.equal(r.out.length, x.length);
	assert.deepEqual(Array.from(r.out.slice(0, 50)), Array.from(x.slice(0, 50)));
	assert.equal(r.report.clicks.length, 0);
});

test('cleanup keeps the length and the speech', () => {
	const { data, starts } = reading(4);
	const r = run(data);
	assert.equal(r.out.length, data.length);
	const a = starts[1] + RATE * 0.4;
	const b = a + RATE * 0.8;
	const before = rms(data, a, b);
	const after = rms(r.out, a, b);
	assert.ok(after > before * 0.85 && after < before * 1.15, 'speech level ' + before + ' became ' + after);
	assert.ok(r.report.snr > 30);
});

test('a steady hush is lowered in the pauses, and only when asked', () => {
	const { data, starts } = reading(4, { hush: 0.004 });
	const gapA = starts[2] - Math.round(RATE * 0.9);
	const gapB = starts[2] - Math.round(RATE * 0.3);
	const kept = run(data, true);
	const lowered = run(data, false);
	assert.equal(kept.report.noiseDropDb, 0);
	assert.ok(lowered.report.noiseDropDb > 0);
	assert.ok(rms(lowered.out, gapA, gapB) < rms(kept.out, gapA, gapB) * 0.8);
});

test('a click just before a phrase is quieted and reported', () => {
	const { data, starts } = reading(4);
	const at = starts[2] - Math.round(RATE * 0.25);
	for (let i = 0; i < 40; i++) { data[at + i] += (i % 2 ? -0.6 : 0.6) * (1 - i / 40); }
	const r = run(data, true);
	const hit = r.report.clicks.some(function (t) { return Math.abs(t - at / RATE) < 0.01; });
	assert.ok(hit, 'clicks found: ' + JSON.stringify(r.report.clicks));
	assert.ok(rms(r.out, at, at + 40) < rms(data, at, at + 40) * 0.5);
});

test('a bump in the middle of a long pause, quieter than speech, is quieted', () => {
	const { data, starts } = reading(3, { pause: 2.4 });
	const at = starts[1] - Math.round(RATE * 1.4);
	const m = Math.round(RATE * 0.2);
	for (let i = 0; i < m; i++) { data[at + i] += 0.008 * Math.sin(2 * Math.PI * 90 * i / RATE) * Math.sin(Math.PI * i / m); }
	const r = run(data, true);
	assert.ok(r.report.strays.length >= 1, 'no stray found');
	assert.ok(rms(r.out, at, at + m) < rms(data, at, at + m) * 0.5);
});
