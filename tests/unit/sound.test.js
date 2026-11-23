'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { load } = require('./load');

const S = load('sound.js', 'RRTSound');
const RATE = 48000;

function sine(amp, hz, secs) {
	const x = new Float32Array(Math.round(RATE * secs));
	for (let i = 0; i < x.length; i++) { x[i] = amp * Math.sin(2 * Math.PI * hz * i / RATE); }
	return x;
}

test('loudness of a 997 Hz sine at -20 dBFS peak reads about -23 LUFS (BS.1770)', () => {
	assert.ok(Math.abs(S.lufs(sine(0.1, 997, 3), RATE) - -23.01) < 0.1);
});

test('loudness of a clip shorter than one gating block is -Infinity', () => {
	assert.equal(S.lufs(sine(0.1, 997, 0.2), RATE), -Infinity);
});

test('sample peak and RMS of a sine', () => {
	const x = sine(0.1, 997, 1);
	assert.ok(Math.abs(S.samplePeakDb(x) - -20) < 0.01);
	assert.ok(Math.abs(S.rmsDb(x) - -23.01) < 0.05);
});

test('the true-peak limiter keeps a hot signal under its ceiling', () => {
	const out = S.limit(sine(1.4, 440, 1), RATE, -1, true);
	const data = out.data || out;
	assert.ok(S.truePeakDb(data) <= -0.99);
});

test('presets and the music bed constants are exposed', () => {
	assert.equal(typeof S.PRESETS, 'object');
	assert.equal(typeof S.BED, 'object');
	assert.equal(typeof S.mixBed, 'function');
});
