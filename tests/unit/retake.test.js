'use strict';
/* Fitting a retake to the selected words, and trimming its own ends (0.25.1). */
const test = require('node:test');
const assert = require('node:assert/strict');
const { load } = require('./load');

const E = load('edit.js');
const RATE = 44100;
const keys = function (s) { return s.split(' '); };

test('a retake heard as said is fitted from the first to the last word', () => {
	assert.deepEqual(E.fitRun(keys('trip ice up in vines and leaves'), keys('trip ice up in vines and leaves')), { i: 0, j: 6 });
});

test('a false start on the first word is left out', () => {
	assert.deepEqual(E.fitRun(keys('trip trip ice up in vines and leaves'), keys('trip ice up in vines and leaves')), { i: 1, j: 7 });
});

test('starting again after a few words is left out too', () => {
	assert.deepEqual(E.fitRun(keys('trip ice up trip ice up in vines and leaves'), keys('trip ice up in vines and leaves')), { i: 3, j: 9 });
});

test('a run-up of other words and a carry-on are outside the fit', () => {
	assert.deepEqual(E.fitRun(keys('so trip ice up in vines and leaves drop'), keys('trip ice up in vines and leaves')), { i: 1, j: 7 });
});

test('a lone copy of the first word later on does not move the start', () => {
	// "trip" heard again near the end, with nothing after it that fits.
	assert.deepEqual(E.fitRun(keys('trip ice up in vines trip and leaves'), keys('trip ice up in vines and leaves')), { i: 0, j: 7 });
});

test('a repeated last word ends at the last copy', () => {
	assert.deepEqual(E.fitRun(keys('trip ice up in vines and leaves leaves'), keys('trip ice up in vines and leaves')), { i: 0, j: 7 });
});

test('nothing fits when the first or last word was never heard', () => {
	assert.equal(E.fitRun(keys('owls call softly'), keys('trip ice')), null);
	assert.equal(E.fitRun(keys('trip owls'), keys('trip ice')), null);
	assert.equal(E.fitRun([], keys('trip')), null);
	assert.equal(E.fitRun(keys('trip'), []), null);
});

test('a single selected word fits its last-started copy', () => {
	assert.deepEqual(E.fitRun(keys('tr trip trip'), keys('trip')), { i: 2, j: 2 });
});

test('trimming the start later cuts the front of the recording, and back again', () => {
	const t = { a: 4410, b: 44100, fa: 4410, fb: 44100 };
	const later = E.trimEdge(t, 's', 0.05, 88200, RATE);
	assert.equal(later.a, 4410 + 2205);
	assert.equal(later.b, 44100);
	assert.deepEqual(E.trimmed(later, RATE), { s: 50, e: 0 });
	const back = E.trimEdge(later, 's', -0.08, 88200, RATE);
	assert.deepEqual(E.trimmed(back, RATE), { s: -30, e: 0 });
	assert.equal(back.fa, 4410);
});

test('trimming the end earlier cuts the tail; later keeps more', () => {
	const t = { a: 0, b: 44100, fa: 0, fb: 44100 };
	assert.deepEqual(E.trimmed(E.trimEdge(t, 'e', -0.02, 88200, RATE), RATE), { s: 0, e: 20 });
	assert.deepEqual(E.trimmed(E.trimEdge(t, 'e', 0.1, 88200, RATE), RATE), { s: 0, e: -100 });
});

test('the trim stops at the recording\'s own ends and keeps a minimum', () => {
	const t = { a: 0, b: 88200, fa: 0, fb: 88200 };
	assert.equal(E.trimEdge(t, 's', -0.01, 88200, RATE), null);
	assert.equal(E.trimEdge(t, 'e', 0.01, 88200, RATE), null);
	const short = { a: 0, b: Math.round(E.TRIM_MIN * RATE), fa: 0, fb: Math.round(E.TRIM_MIN * RATE) };
	assert.equal(E.trimEdge(short, 's', 0.01, 88200, RATE), null);
	assert.equal(E.trimEdge(short, 'e', -0.01, 88200, RATE), null);
	const near = E.trimEdge({ a: 0, b: 10000, fa: 0, fb: 10000 }, 's', 5, 88200, RATE);
	assert.equal(near.a, 10000 - Math.round(E.TRIM_MIN * RATE));
});

test('a retake keeps its recording and trim through the edit list and its nudges', () => {
	const raw = new Float32Array(88200);
	const ins = raw.subarray(2205, 44100);
	const cut = { s: 2, e: 3, ins: ins, o: { s: 2, e: 3 }, raw: raw, trim: { a: 2205, b: 44100, fa: 0, fb: 44100 } };
	const list = E.normalize([cut]);
	assert.equal(list[0].raw, raw);
	assert.deepEqual(list[0].trim, { a: 2205, b: 44100, fa: 0, fb: 44100 });
	assert.notEqual(list[0].trim, cut.trim);
	const nudged = E.nudgeEdge(list[0], 's', -0.01, [], 10);
	assert.equal(nudged.raw, raw);
	assert.deepEqual(nudged.trim, cut.trim);
	const spaced = E.spaceEdge(list[0], 'e', 0.05);
	assert.equal(spaced.raw, raw);
	assert.equal(E.trimmed(null, RATE).s, 0);
});

/*
 * Back-to-back retakes (0.25.1): "...cloak of night" retaken, then "Lost in
 * a sea of stars and trees" right after it. The joins touch, and both must
 * be heard; before the fix the later one was dropped without a word.
 */
function backToBack() {
	const x = new Float32Array(RATE * 6).fill(0.01);
	const one = new Float32Array(RATE).fill(0.5);
	const two = new Float32Array(RATE).fill(-0.5);
	const first = { s: 1, e: 2, ins: one, o: { s: 1, e: 2 } };
	const second = { s: 2.01, e: 3, ins: two, o: { s: 2.01, e: 3 } };
	return { x, one, two, first, second };
}

test('two retakes that touch both stay in the edit list', () => {
	const b = backToBack();
	const list = E.normalize([b.second, b.first]);
	assert.equal(list.length, 2);
	assert.equal(list[0].ins, b.one);
	assert.equal(list[1].ins, b.two);
});

test('two retakes that touch both play, each in its place', () => {
	const b = backToBack();
	const y = E.render(b.x, RATE, [b.first, b.second]);
	let pos = 0;
	let neg = 0;
	for (let i = 0; i < y.length; i++) { if (y[i] > 0.4) { pos++; } if (y[i] < -0.4) { neg++; } }
	assert.ok(pos > RATE * 0.9, 'first retake heard');
	assert.ok(neg > RATE * 0.9, 'second retake heard');
	// The second follows the first.
	let lastPos = 0;
	let firstNeg = -1;
	for (let i = 0; i < y.length; i++) { if (y[i] > 0.4) { lastPos = i; } if (firstNeg < 0 && y[i] < -0.4) { firstNeg = i; } }
	assert.ok(firstNeg > lastPos);
	// Nothing of the old 2 to 3 s reading is left between them.
	assert.ok(firstNeg - lastPos < Math.round(0.05 * RATE));
});

test('two retakes over the same words still keep only one', () => {
	const b = backToBack();
	const over = { s: 1.5, e: 2.5, ins: b.two, o: { s: 1.5, e: 2.5 } };
	assert.equal(E.normalize([b.first, over]).length, 1);
});
