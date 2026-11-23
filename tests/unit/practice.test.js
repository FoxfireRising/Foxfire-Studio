'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { load } = require('./load');

const P = load('practice.js');

test('paced word times run end to end at the asked pace', () => {
	const t = P.paceTimes(['one', 'two', 'three'], 120);
	assert.equal(t[0].s, 0);
	assert.ok(Math.abs(t[2].e - 1.5) < 1e-9);
	assert.ok(Math.abs(t[1].s - t[0].e) < 1e-9);
});

test('a cue mark follows its word after an edit, or is reported lost', () => {
	const moved = P.cuePlace([{ i: 1, k: 'fox', a: 'pause' }], ['the', 'quick', 'fox']);
	assert.equal(moved.cues[0].i, 2);
	assert.equal(moved.moved, 1);
	const lost = P.cuePlace([{ i: 1, k: 'fox', a: 'pause' }], ['no', 'match', 'here']);
	assert.equal(lost.cues.length, 0);
	assert.equal(lost.lost, 1);
});

test('cueClean drops bad entries and unknown kinds', () => {
	const out = P.cueClean([{ i: 3, k: 'a', a: 'breath' }, { i: -1, k: 'b', a: 'pause' }, null, { i: 4, k: 'c', a: 'shout' }]);
	assert.equal(out[0].a, 'breath');
	assert.ok(out.every((c) => c.i >= 0));
});

test('a drill stops at the end of the sentence it is on', () => {
	assert.equal(P.drillStopWord([0, 0, 1, 1, 1, 2], 2), 4);
});

test('a silent microphone check says so', () => {
	assert.equal(P.verdict({ spoke: false }).level, 'silent');
});

test('the warm-up has enough tongue twisters to pick four', () => {
	assert.ok(P.TWISTERS.length >= 4);
});

test('a note is one line of plain text, cut to the longest a note can be', () => {
	assert.equal(P.noteText('  <b>SOW-in</b>\n '), 'SOW-in');
	assert.equal(P.noteText('k'.repeat(80)).length, P.NOTE_LEN);
	assert.equal(P.noteText(null), '');
	assert.equal(P.noteText(42), '42');
});

test('a note list becomes a lookup and back, first note of a word winning', () => {
	const map = P.noteMap([{ k: 'samhain', n: 'SOW-in' }, { k: 'samhain', n: 'other' }, { k: '', n: 'x' }, { k: 'imbolc', n: ' ' }, null, { k: 'beltane', n: 'BELL-tain' }]);
	assert.deepEqual(map, { samhain: 'SOW-in', beltane: 'BELL-tain' });
	assert.deepEqual(P.noteList(map), [{ k: 'beltane', n: 'BELL-tain' }, { k: 'samhain', n: 'SOW-in' }]);
});

test('the nearest note wins: this word, then this post, then every post', () => {
	const post = { samhain: 'POST' };
	const mine = { samhain: 'MINE', imbolc: 'IM-bulk' };
	assert.deepEqual(P.noteFor({ a: '', e: false, n: 'HERE' }, 'samhain', post, mine), { n: 'HERE', scope: 'here' });
	assert.deepEqual(P.noteFor(null, 'samhain', post, mine), { n: 'POST', scope: 'post' });
	assert.deepEqual(P.noteFor({ a: 'pause', e: false, n: '' }, 'imbolc', post, mine), { n: 'IM-bulk', scope: 'all' });
	assert.equal(P.noteFor(null, 'moon', post, mine), null);
	assert.equal(P.noteFor(null, 'constructor', {}, {}), null);
});

test('a cue mark with only a note is kept, and its note follows the word after an edit', () => {
	const out = P.cueClean([{ i: 2, k: 'tear', a: '', e: false, n: 'TAIR' }, { i: 3, k: 'x', a: '', e: false, n: '' }]);
	assert.equal(out.length, 1);
	assert.equal(out[0].n, 'TAIR');
	const placed = P.cuePlace(out, ['a', 'new', 'word', 'tear']);
	assert.equal(placed.cues[0].i, 3);
	assert.equal(placed.cues[0].n, 'TAIR');
});

test('a fade meant is kept by cueClean and cuePlace (1.11.0)', () => {
	const out = P.cueClean([{ i: 2, k: 'night', a: '', e: false, f: true }, { i: 3, k: 'x', a: '', e: false, f: false }]);
	assert.equal(out.length, 1);
	assert.equal(out[0].f, true);
	const placed = P.cuePlace([{ i: 1, k: 'night', f: true }], ['the', 'quiet', 'night']);
	assert.equal(placed.cues[0].i, 2);
	assert.equal(placed.cues[0].f, true);
});

test('kinds of reading give a range, a starting pace and the review edges (1.11.0)', () => {
	assert.equal(P.kindRange('', null), null);
	assert.equal(P.kindRange('shouting', null), null);
	assert.deepEqual(P.kindRange('verse', null), { lo: 110, hi: 135 });
	assert.deepEqual(P.kindRange('verse', { verse: [100, 120] }), { lo: 100, hi: 120 });
	assert.equal(P.kindRange('verse', { verse: [120, 100] }), null);
	assert.equal(P.kindPace({ lo: 110, hi: 135 }), 125);
	assert.equal(P.kindPace({ lo: 140, hi: 160 }), 150);
	assert.equal(P.kindPace({ lo: 60, hi: 70 }), 80);
	assert.equal(P.kindPace(null), 0);
	assert.deepEqual(P.paceAgainst(100, { lo: 110, hi: 135 }), { where: 'under', by: 10 });
	assert.deepEqual(P.paceAgainst(120, { lo: 110, hi: 135 }), { where: 'inside', by: 0 });
	assert.deepEqual(P.paceAgainst(140, { lo: 110, hi: 135 }), { where: 'over', by: 5 });
	// No kind: the edges used before 1.11.0. Prose keeps them; verse and ritual move them and allow a trail.
	assert.deepEqual(P.kindReview('', null), { kind: '', range: null, rushed: 200, slow: 105, fadeDb: 8 });
	const prose = P.kindReview('prose', null);
	assert.equal(prose.rushed, 200);
	assert.equal(prose.slow, 105);
	assert.equal(prose.fadeDb, 8);
	assert.equal(P.kindReview('story', null).fadeDb, 8);
	const verse = P.kindReview('verse', null);
	assert.equal(verse.rushed, 169);
	assert.equal(verse.slow, 83);
	assert.equal(verse.fadeDb, 14);
	assert.equal(P.kindReview('ritual', null).fadeDb, 14);
	assert.equal(P.kindReview('ritual', null).slow, 71);
});
