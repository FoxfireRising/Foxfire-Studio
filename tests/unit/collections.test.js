'use strict';
/*
 * Collections (1.19.0): the helpers behind the Readings in this collection
 * box, and the leveling a matched copy gets.
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const { load } = require('./load');

const C = load('collections.js');
const S = load('sound.js', 'RRTSound');

test('clock reads minutes, and hours from an hour', () => {
	assert.equal(C.clock(0), '0:00');
	assert.equal(C.clock(65.4), '1:05');
	assert.equal(C.clock(1780), '29:40');
	assert.equal(C.clock(3725), '1:02:05');
	assert.equal(C.clock(-3), '0:00');
});

test('sizes in megabytes', () => {
	assert.equal(C.size(1048576 * 2.34), '2.3 MB');
	assert.equal(C.size(1048576 * 28.4), '28 MB');
});

test('REST addresses join their query with ? or & as the root needs', () => {
	assert.equal(C.restUrl('https://example.com/wp-json/rrt/v1/', 'collections/readings', { search: 'yule & more', exclude: '1,2' }), 'https://example.com/wp-json/rrt/v1/collections/readings?search=yule%20%26%20more&exclude=1%2C2');
	assert.equal(C.restUrl('https://example.com/?rest_route=/rrt/v1/', 'collections/readings', { search: 'a' }), 'https://example.com/?rest_route=/rrt/v1/collections/readings&search=a');
	assert.equal(C.restUrl('https://example.com/?rest_route=/rrt/v1/', 'collections/plan'), 'https://example.com/?rest_route=/rrt/v1/collections/plan');
});

test('the hidden field\'s ids, without repeats or junk', () => {
	assert.deepEqual(C.parseIds('3, 1,x,3,,0,7'), [3, 1, 7]);
	assert.deepEqual(C.parseIds(''), []);
});

test('moving a reading up and down, and nowhere past the ends', () => {
	assert.deepEqual(C.move([1, 2, 3], 2, 0), [3, 1, 2]);
	assert.deepEqual(C.move([1, 2, 3], 0, 1), [2, 1, 3]);
	assert.deepEqual(C.move([1, 2, 3], 0, -1), [1, 2, 3]);
	assert.deepEqual(C.move([1, 2, 3], 2, 3), [1, 2, 3]);
	assert.ok(C.same([1, 2], [1, 2]) && !C.same([1, 2], [2, 1]) && !C.same([1], [1, 2]));
});

test('the summary line counts readings and says the format', () => {
	assert.match(C.summary({ count: 3, secs: 1780, label: '128 kbps mono' }), /^3 readings, 29:40 with the gaps, made as 128 kbps mono\.$/);
	assert.match(C.summary({ count: 1, secs: 61, label: '128 kbps mono' }), /^1 reading, 1:01, made as 128 kbps mono\.$/);
	assert.match(C.summary({ count: 0 }), /No readings yet/);
});

test('two channels average to one', () => {
	const a = new Float32Array([1, 0.5, -1]);
	const b = new Float32Array([0, 0.5, 1]);
	assert.deepEqual(Array.from(C.monoMix([a, b])), [0.5, 0.5, 0]);
	assert.equal(C.monoMix([a]), a);
});

test('a matched copy in stereo is leveled 3 dB lower in its one channel, so both channels measure the target', () => {
	assert.equal(C.monoTarget(-19, 1), -19);
	assert.ok(Math.abs(C.monoTarget(-16, 2) - (-19.01)) < 1e-9);
	assert.equal(C.monoTarget(null, 1), null);
	const rate = 44100;
	const x = new Float32Array(rate * 6);
	for (let i = 0; i < x.length; i++) { x[i] = 0.05 * Math.sin(2 * Math.PI * 220 * i / rate) * (0.6 + 0.4 * Math.sin(2 * Math.PI * 1.3 * i / rate)); }
	const y = S.toLoudness(x, rate, C.monoTarget(-16, 2), -1, 30);
	const both = S.lufsMulti([y.data, y.data], rate);
	assert.ok(Math.abs(both - (-16)) < 0.3, 'stereo measures ' + both);
	const z = S.toLoudness(x, rate, C.monoTarget(-19, 1), -1, 30);
	assert.ok(Math.abs(S.lufs(z.data, rate) - (-19)) < 0.3);
});
