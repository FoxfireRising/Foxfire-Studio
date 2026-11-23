'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { load } = require('./load');

const E = load('edit.js');
const RATE = 44100;

test('cutting one second from three leaves two', () => {
	const x = new Float32Array(RATE * 3).fill(0.01);
	assert.ok(Math.abs(E.render(x, RATE, [{ s: 1, e: 2 }]).length / RATE - 2) < 0.02);
});

test('times after a cut move back by its length', () => {
	assert.ok(Math.abs(E.mapTime([{ s: 1, e: 2 }], 2.5, RATE) - 1.5) < 0.001);
	assert.ok(Math.abs(E.mapTime([{ s: 1, e: 2 }], 0.5, RATE) - 0.5) < 0.001);
});

test('isCut and removed agree with the cut', () => {
	assert.equal(E.isCut([{ s: 1, e: 2 }], 1.2, 1.8), true);
	assert.equal(E.isCut([{ s: 1, e: 2 }], 2.2, 2.8), false);
	assert.ok(Math.abs(E.removed([{ s: 1, e: 2 }]) - 1) < 0.001);
});

test('normalize drops cuts that are too short or not numbers', () => {
	assert.equal(E.normalize([{ s: 1, e: 1.01 }, { s: NaN, e: 2 }, { s: 0, e: 1 }]).length, 1);
});

test('a retake edge moves in steps and stops at the nudge limit', () => {
	const cut = { s: 1, e: 2, ins: {}, o: { s: 1, e: 2 } };
	assert.equal(E.nudgeEdge(cut, 's', 0.01, [], 3).s, 1.01);
	assert.equal(E.nudgeEdge(cut, 's', -5, [], 3).s, 1 - E.NUDGE_MAX);
});

test('space at a join is capped and can be cleared', () => {
	const cut = { s: 1, e: 2, ins: {}, o: { s: 1, e: 2 } };
	const more = E.spaceEdge(cut, 'e', 99);
	assert.equal(more.pe, E.SPACE_MAX);
	assert.equal('pe' in E.spaceEdge(more, 'e', -99), false);
});

/* Flags after edits (1.1.0): a small review of "the fox ran over the hill under the moon". */
function review() {
	const w = (status, s, e) => ({ status, s, e, heard: '' });
	return {
		words: [w('ok', 1.0, 1.3), w('ok', 1.4, 1.7), w('sub', 1.8, 2.1), w('ok', 2.2, 2.5), w('ok', 2.6, 2.9), w('omit', null, null), w('ok', 3.2, 3.5), w('ok', 3.6, 3.9), w('ok', 4.0, 4.3)],
		inserts: { 4: [{ kind: 'filler', text: 'um', s: 2.52, e: 2.58 }] },
		pauses: [{ after: 6, before: 7, len: 0.1, s: 3.5, inSentence: true, cued: false }, { after: 7, before: 8, len: 2.0, s: 3.9, inSentence: false, cued: false }],
		sentences: [{ id: 0, first: 0, last: 4, s: 1.0, e: 2.9, flags: [] }, { id: 1, first: 5, last: 8, s: 3.2, e: 4.3, flags: [] }],
		problems: [
			{ kind: 'sub', idx: 2, s: 1.8 },
			{ kind: 'filler', idx: 4, s: 2.52 },
			{ kind: 'omit', idx: 5, s: null },
			{ kind: 'pause', idx: 8, s: 3.9 }
		],
		retake: 2
	};
}

test('with no edits, no flag is covered', () => {
	const c = E.flagsCovered(review(), []);
	assert.deepEqual(c.byProblem, ['', '', '', '']);
	assert.equal(c.covered, 0);
});

test('a retake covers the misread word and the skipped one, a cut the filler, a shortened pause counts as cut', () => {
	const R = review();
	const cuts = [
		{ s: 2.50, e: 2.60 },
		{ s: 1.79, e: 2.15, ins: new Float32Array(100) },
		{ s: 2.85, e: 3.25, ins: new Float32Array(100) },
		{ s: 4.2, e: 5.6 }
	];
	const c = E.flagsCovered(R, cuts);
	assert.deepEqual(c.byProblem, ['retaken', 'cut', 'retaken', 'cut']);
	assert.equal(c.covered, 4);
	assert.deepEqual(c.counts, { omit: 1, sub: 1, check: 0, added: 0, repeat: 0, filler: 1, pauses: 1, retake: 2 });
});

test('an edit elsewhere leaves the flags', () => {
	const c = E.flagsCovered(review(), [{ s: 1.0, e: 1.3 }]);
	assert.equal(c.covered, 0);
	assert.equal(c.counts.retake, 0);
});

test('a flag only partly inside an edit is not covered', () => {
	assert.equal(E.coverOf([{ s: 1.0, e: 1.1 }], 1.0, 1.5), '');
	assert.equal(E.coverOf([{ s: 1.0, e: 1.4 }], 1.0, 1.5), 'cut');
	assert.equal(E.coverOf([{ s: 0.9, e: 1.2, ins: new Float32Array(10) }], 1.0, 1.0), 'retaken');
});
