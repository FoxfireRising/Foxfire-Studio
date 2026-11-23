'use strict';
/* The take shelf on the site (1.34.0): FLAC written and read back sample for sample. */
const test = require('node:test');
const assert = require('node:assert/strict');
const { load } = require('./load');
const { RATE, hush, reading } = require('./synth');

const F = load('flac.js');

function toPcm(x, gain) {
	const out = new Int16Array(x.length);
	for (let i = 0; i < x.length; i++) {
		const v = x[i] * (gain || 1);
		out[i] = v >= 1 ? 32767 : (v <= -1 ? -32768 : Math.round(v * 32767));
	}
	return out;
}
function same(a, b) {
	if (a.length !== b.length) { return 'lengths ' + a.length + ' and ' + b.length; }
	for (let i = 0; i < a.length; i++) { if (a[i] !== b[i]) { return 'sample ' + i + ': ' + a[i] + ' and ' + b[i]; } }
	return '';
}
function roundTrip(pcm, rate) {
	const f = F.encode(pcm, rate || RATE);
	const r = F.decode(f);
	assert.equal(r.rate, rate || RATE);
	assert.equal(same(pcm, r.pcm), '');
	assert.equal(F.sum(r.pcm), F.sum(pcm));
	return f;
}
/* A made-up voice: phrases of tones over a room hush. */
function voice(level, seconds) {
	return reading(Math.max(1, Math.floor(seconds / 2.8)), { level: level, hush: level / 30 }).data;
}

test('a voice at a normal level comes back exactly and smaller than a WAV', function () {
	const pcm = toPcm(voice(0.3, 12));
	const f = roundTrip(pcm);
	assert.ok(f.length < pcm.length * 2 * 0.85, 'FLAC ' + f.length + ' bytes for ' + pcm.length * 2 + ' bytes of samples');
});

test('a quiet phone-level take comes back exactly and much smaller', function () {
	const pcm = toPcm(voice(0.004, 10));
	const f = roundTrip(pcm);
	assert.ok(f.length < pcm.length * 2 * 0.5, 'FLAC ' + f.length + ' bytes for ' + pcm.length * 2);
});

test('a level that changes within the take, and clipped peaks', function () {
	const a = voice(0.01, 6);
	const b = voice(0.6, 6);
	const x = new Float32Array(a.length + b.length);
	x.set(a);
	x.set(b, a.length);
	roundTrip(toPcm(x, 2.5));
});

test('silence, digital zeros, the loudest values and noise', function () {
	roundTrip(new Int16Array(RATE));
	const edge = new Int16Array(9000);
	for (let i = 0; i < edge.length; i++) { edge[i] = i % 2 ? 32767 : -32768; }
	roundTrip(edge);
	roundTrip(toPcm(hush(RATE * 2, 0.9, 7)));
	const step = new Int16Array(5000);
	step.fill(-1234, 2500);
	roundTrip(step);
});

test('odd lengths: none, one sample, a few, and one past a frame', function () {
	[0, 1, 2, 3, 5, F.BLOCK - 1, F.BLOCK, F.BLOCK + 1, F.BLOCK * 3 + 17].forEach(function (n) {
		const pcm = toPcm(hush(n, 0.2, n + 1));
		roundTrip(pcm);
	});
});

test('other sample rates are kept in the file', function () {
	roundTrip(toPcm(hush(4800, 0.1, 2)), 48000);
	roundTrip(toPcm(hush(1600, 0.1, 3)), 16000);
});

test('frame numbers past one byte (a long take) are written and read', function () {
	/* 300 frames: numbers above 127 take two bytes. */
	const pcm = toPcm(hush(F.BLOCK * 300 + 5, 0.05, 9));
	roundTrip(pcm);
});

test('the same samples always make the same bytes, so a send can carry on', function () {
	const pcm = toPcm(voice(0.2, 5));
	const a = F.encode(pcm, RATE);
	const b = F.encode(pcm, RATE);
	assert.deepEqual(Buffer.from(a), Buffer.from(b));
});

test('a damaged file is refused', function () {
	const pcm = toPcm(voice(0.2, 3));
	const f = F.encode(pcm, RATE);
	const bad = f.slice();
	bad[Math.floor(bad.length / 2)] ^= 0x10;
	assert.throws(function () { F.decode(bad); });
	assert.throws(function () { F.decode(f.slice(0, f.length - 100)); });
	assert.throws(function () { F.decode(new Uint8Array([1, 2, 3, 4])); });
});

test('the check tells changed samples apart', function () {
	const pcm = toPcm(voice(0.2, 2));
	const s = F.sum(pcm);
	const c = pcm.slice();
	c[100] += 1;
	assert.notEqual(F.sum(c), s);
	assert.notEqual(F.sum(pcm.slice(0, pcm.length - 1)), s);
	assert.match(s, /^[0-9a-f]{16}-\d+$/);
});

test('the CRCs match FLAC’s published check values', function () {
	const b = Buffer.from('123456789');
	assert.equal(F._internal.crc8(b, 0, b.length), 0xF4);
	assert.equal(F._internal.crc16(b, 0, b.length), 0xFEE8);
});
