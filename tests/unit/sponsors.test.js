'use strict';
/*
 * Sponsor messages (1.22.0): the helpers behind a message's copies and the
 * pause finder for mid-rolls.
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const { load } = require('./load');
const synth = require('./synth');

const S = load('sponsors.js');
const PAUSES = [1.0, 0.3, 0.8, 0.5, 1.5];

/* A reading whose pauses are known: phrase p is followed by PAUSES[(p + 1) % 5]. */
function known(opts) {
	const r = synth.reading(10, Object.assign({ len: 1.4, pause: (p) => PAUSES[p % 5] }, opts || {}));
	const want = [];
	for (let p = 0; p < 10; p++) {
		const after = PAUSES[(p + 1) % 5];
		const s = r.starts[p] / synth.RATE + 1.4;
		if (after >= 0.6 && p < 9) { want.push({ s, e: s + after }); }
	}
	return { r, want };
}

function check(found, want) {
	const long = found.filter((f) => f.e < want[want.length - 1].e + 0.5 && f.s > 1);
	assert.equal(long.length, want.length, JSON.stringify(found));
	long.forEach((f, i) => {
		assert.ok(f.e - f.s >= 0.6 - 1e-9, 'each pause is at least 0.6 s');
		assert.ok(f.s >= want[i].s - 0.03 && f.e <= want[i].e + 0.03, 'pause ' + i + ' sits inside the real one: ' + JSON.stringify([f, want[i]]));
		assert.ok(f.s - want[i].s < 0.2 && want[i].e - f.e < 0.2, 'and covers most of it');
	});
}

test('clock and time reading', () => {
	assert.equal(S.clock(432.4), '7:12');
	assert.equal(S.clock(3725), '1:02:05');
	assert.equal(S.parseTime('7:12'), 432);
	assert.equal(S.parseTime('1:02:05'), 3725);
	assert.equal(S.parseTime('7:12.5'), 432.5);
	assert.equal(S.parseTime('95'), 95);
	assert.equal(S.parseTime('soon'), null);
});

test('the place to listen to: the option, the typed time, then the plan', () => {
	const boot = { mid: { t: 300 }, suggest: [{ t: 280 }] };
	assert.equal(S.pickTime('412.5', '', boot), 412.5);
	assert.equal(S.pickTime('other', '6:40', boot), 400);
	assert.equal(S.pickTime('', '', boot), 300);
	assert.equal(S.pickTime('', '', { suggest: [{ t: 280 }] }), 280);
	assert.equal(S.pickTime('', '', {}), null);
});

test('REST addresses join their query with ? or &', () => {
	assert.equal(S.restUrl('https://example.com/wp-json/rrt/v1/', 'spots/3/needs'), 'https://example.com/wp-json/rrt/v1/spots/3/needs');
	assert.equal(S.restUrl('https://example.com/?rest_route=/rrt/v1/', 'spots/3/needs', { a: 1 }), 'https://example.com/?rest_route=/rrt/v1/spots/3/needs&a=1');
});

test('pauses of 0.6 s or more are found at a normal level, and shorter ones are not', () => {
	const { r, want } = known({ level: 0.3, hush: 0.002 });
	check(S.findPauses(r.data, synth.RATE, 0.6), want);
});

test('a quiet recording (a phone left far off) finds the same pauses', () => {
	const { r, want } = known({ level: 0.01, hush: 0.00007 });
	check(S.findPauses(r.data, synth.RATE, 0.6), want);
});

test('a reading whose level changes partway through finds the same pauses', () => {
	const { r, want } = known({ level: 0.3, hush: 0.002 });
	const x = r.data;
	/* The second half, voice and room, 18 dB quieter, as a reader leaning back. */
	for (let i = Math.floor(x.length / 2); i < x.length; i++) { x[i] *= 0.126; }
	check(S.findPauses(x, synth.RATE, 0.6), want);
});

test('tight pauses alone give nothing', () => {
	const r = synth.reading(8, { len: 1.4, pause: (p) => (p === 0 ? 0.2 : 0.35), level: 0.3 });
	assert.deepEqual(S.findPauses(r.data, synth.RATE, 0.6), []);
});

test('one channel from two is their average', () => {
	const m = S.monoMix([new Float32Array([1, 0.5]), new Float32Array([0, 0.5])]);
	assert.deepEqual(Array.from(m), [0.5, 0.5]);
});
