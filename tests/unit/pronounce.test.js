'use strict';
/* The pronunciation list (1.15.0): taught words that stay forgotten, change-only saves, and the screen's filter, sort and CSV. */
const test = require('node:test');
const assert = require('node:assert');
const P = require('../../plugin/foxfire-studio/assets/js/practice.js');
const N = require('../../plugin/foxfire-studio/assets/js/pronounce.js');

const base = () => ({
	pairs: { siobhan: { shivon: 1 } },
	ignore: { um: 1 },
	labels: { 'siobhan|shivon': 'Siobhan heard as shivon', '+um': 'um (ignored)' }
});

test('a forgotten word stays forgotten when an old copy is merged in', () => {
	const f = P.learnedForget(base(), 'siobhan|shivon', 1000);
	assert.ok(!f.pairs.siobhan);
	assert.equal(f.forgot['siobhan|shivon'], 1000);
	const m = P.mergeLearned(f, base());
	assert.ok(!m.pairs.siobhan);
	assert.ok(!m.labels['siobhan|shivon']);
	assert.ok(m.ignore.um);
	const m2 = P.mergeLearned(base(), f);
	assert.ok(P.learnedSame(m, m2), 'the same either way round');
});

test('a word taught again after it was forgotten comes back', () => {
	const f = P.learnedForget(base(), 'siobhan|shivon', 1000);
	const again = base();
	again.at = { 'siobhan|shivon': 2000 };
	assert.ok(P.mergeLearned(f, again).pairs.siobhan.shivon);
	assert.equal(P.mergeLearned(f, again).forgot['siobhan|shivon'], 1000, 'the forgotten time is kept for other browsers');
});

test('the site sends empty sets as arrays', () => {
	const m = P.mergeLearned({ pairs: [], ignore: [], labels: [] }, base());
	assert.ok(m.pairs.siobhan.shivon);
	assert.ok(P.learnedSame(m, base()));
	assert.ok(!P.learnedSame(m, P.learnedForget(base(), '+um', 5)));
});

test('notes: only what changed is sent, and applied on top', () => {
	assert.deepEqual(P.noteDiff({ a: 'A', b: 'B2', d: 'D' }, { a: 'A', b: 'B', c: 'C' }), { b: 'B2', d: 'D', c: '' });
	assert.deepEqual(P.noteDiff({ a: 'A' }, { a: 'A' }), {});
	/* The site's list gained "e" from the Pronunciation screen meanwhile; it stays. */
	assert.deepEqual(P.noteApply({ a: 'A', c: 'C', e: 'E' }, { b: 'B2', c: '' }), { a: 'A', b: 'B2', e: 'E' });
});

test('cue marks: only what changed is sent, and applied on top', () => {
	const was = [{ i: 1, k: 'moon', a: 'pause', e: false, f: false, n: '' }, { i: 4, k: 'owl', a: '', e: true, f: false, n: '' }];
	const now = [{ i: 1, k: 'moon', a: 'pause', e: false, f: false, n: '' }, { i: 6, k: 'sea', a: 'breath', e: false, f: false, n: '' }];
	const d = P.cueDiff(now, was);
	assert.deepEqual(d.map((x) => [x.i, x.cue ? x.cue.a : null]), [[4, null], [6, 'breath']]);
	const site = was.concat([{ i: 9, k: 'star', a: '', e: false, f: false, n: 'STAR' }]);
	assert.deepEqual(P.cueApply(site, d).map((c) => c.i), [1, 6, 9]);
	assert.deepEqual(P.cueDiff(was, was), []);
	const noted = [{ i: 4, k: 'owl', a: '', e: true, f: false, n: 'OUL' }];
	assert.equal(P.cueDiff(noted, was.slice(1)).length, 1, 'a changed note counts');
});

const rows = [
	{ id: 'all:0:brigid', where: 'all', post: 0, key: 'brigid', word: 'Brigid', note: 'BRIDJ-id', taught: [{ e: 'brigid|bridget', heard: 'bridget', ign: false }], count: 4 },
	{ id: 'post:7:lachesis', where: 'post', post: 7, title: 'The Fates', key: 'lachesis', word: 'Lachesis', note: '', taught: [{ e: 'lachesis|lakeses', heard: 'lakeses', ign: false }], count: 1 },
	{ id: 'post:7:um', where: 'post', post: 7, title: 'The Fates', key: 'um', word: 'um', note: '', taught: [{ e: '+um', heard: 'um', ign: true }], count: 0 },
	{ id: 'here:7:12', where: 'here', post: 7, title: 'The Fates', key: '12', wkey: 'tear', word: 'tear', note: 'TARE', taught: [], count: 9, para: 3 }
];

test('filter by kind, place, post and search', () => {
	const ids = (f) => N.filter(rows, f).map((r) => r.id);
	assert.deepEqual(ids({ kind: 'note' }), ['all:0:brigid', 'here:7:12']);
	assert.deepEqual(ids({ kind: 'taught' }), ['all:0:brigid', 'post:7:lachesis']);
	assert.deepEqual(ids({ kind: 'ign' }), ['post:7:um']);
	assert.deepEqual(ids({ where: 'post' }), ['post:7:lachesis', 'post:7:um']);
	assert.deepEqual(ids({ post: 7, where: '' }), ['post:7:lachesis', 'post:7:um', 'here:7:12']);
	assert.deepEqual(ids({ q: 'bridg' }), ['all:0:brigid'], 'search covers what Whisper heard');
	assert.deepEqual(ids({ q: 'fates' }), ['post:7:lachesis', 'post:7:um', 'here:7:12'], 'and the post title');
	assert.deepEqual(ids({ q: 'TARE' }), ['here:7:12'], 'and the note');
});

test('sort by a column, either way', () => {
	assert.deepEqual(N.sort(rows, 'word', 1).map((r) => r.word), ['Brigid', 'Lachesis', 'tear', 'um']);
	assert.deepEqual(N.sort(rows, 'count', -1).map((r) => r.count), [9, 4, 1, 0]);
	assert.deepEqual(N.sort(rows, 'where', 1).map((r) => r.where), ['all', 'post', 'post', 'here']);
});

test('the place and the CSV', () => {
	assert.equal(N.whereText(rows[3]), 'Only here: The Fates, paragraph 3');
	assert.equal(N.whereText(rows[0]), 'In every post');
	const csv = N.csv([rows[0], rows[2], { word: '=cmd', note: 'say "this", then', taught: [], where: 'all', count: 1 }]);
	assert.ok(csv.startsWith('﻿Word,Note,Whisper hears it as,Where,In posts\r\n'));
	assert.ok(csv.includes('Brigid,BRIDJ-id,bridget,In every post,4\r\n'));
	assert.ok(csv.includes('um,,“um” ignored,The Fates,0\r\n'));
	assert.ok(csv.includes('\'=cmd,"say ""this"", then",,In every post,1\r\n'), 'a formula is guarded and quotes are doubled');
});
