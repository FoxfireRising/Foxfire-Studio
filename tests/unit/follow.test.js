/*
 * Voice following (1.14.0): the follower on a made-up reading heard with the
 * kind of slips a small live speech model makes (misheard words, dropped
 * words, names spelled its own way), straight through, with ad-libs, a skip
 * ahead, going back, and a hand jump; and the pace and the first look.
 */
'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { load } = require('./load');

const F = load('follow.js');

const TEXT = [
	'The lantern swings above the harbour gate while gulls argue over the morning catch.',
	'Fishermen mend their nets beside the seven-day market, and the smell of salt and tar drifts up the cobbled lane toward the chapel.',
	'Old Marisol keeps the tide tables pinned inside her window, crossing out each high water with a stub of red pencil. She says the sea remembers every promise made beside it, and she has made a few.',
	'By noon the fog lifts from Penhallow Point. Children race along the breakwater, counting waves in threes, and the ferry sounds twice before it rounds the headland.',
	'When evening comes the lantern is lit again, and the harbour settles into its long slow breathing, waiting for the boats to come home.'
];
const shown = [];
const block = [];
TEXT.forEach((p, b) => p.split(/\s+/).forEach(w => { shown.push(w); block.push(b); }));
const sc = F.script(shown);

/* A reader heard by a small model: about one word in eight misheard, one in twenty dropped. */
function hear(fromWord, toWord, seed) {
	let s = seed || 7;
	function rnd() { s = (s * 16807) % 2147483647; return s / 2147483647; }
	const out = [];
	for (let i = fromWord; i < toWord; i++) {
		F.words(shown[i]).forEach(k => {
			const r = rnd();
			if (r < 0.05) { return; }
			if (r < 0.17 && k.length > 4) { out.push(k.slice(0, -2) + 'er'); return; }
			out.push(k);
		});
	}
	return out;
}
/* Feeds heard words one at a time, as a live model would, and returns the follower's word after each. */
function run(f, heard, start) {
	const seen = start || [];
	const at = [];
	heard.forEach(w => {
		seen.push(w);
		at.push(f.update(seen.slice(-12), seen.length));
	});
	return { at, seen };
}
const firstOf = b => block.indexOf(b);

test('words: hyphens split, small numbers written out, punctuation dropped', () => {
	assert.deepEqual(F.words('seven-day'), ['seven', 'day']);
	assert.deepEqual(F.words('In 3 rounds, I’m sure!'), ['in', 'three', 'rounds', 'im', 'sure']);
	assert.equal(sc.keys.length > shown.length, true);
	assert.equal(sc.at[sc.keys.indexOf('day')], shown.indexOf('seven-day'));
});

test('likeness: short words exact, long words close or sounding alike', () => {
	assert.equal(F.sim('the', 'the'), 1);
	assert.equal(F.sim('the', 'tho'), 0);
	assert.ok(F.sim('harbour', 'harbor') >= 0.75);
	assert.ok(F.sim('penhallow', 'penhalo') >= 0.75);
	assert.equal(F.sim('lantern', 'breakwater'), 0);
});

test('straight through with slips: ends on the last word, never far behind', () => {
	const f = new F.Follower(sc);
	const heard = hear(0, shown.length, 3);
	const r = run(f, heard);
	assert.equal(f.word(), shown.length, 'the follower reaches the end');
	/* A straight reading moves the place a few words at a time, never in big jumps. */
	let worst = 0;
	r.at.forEach((w, i) => { if (i > 10) { worst = Math.max(worst, Math.abs(r.at[i] - r.at[i - 1])); } });
	assert.ok(worst <= 8, 'no big jumps on a straight reading, worst step ' + worst);
	assert.equal(f.jumps, 0);
});

test('an ad-lib holds the place, and the reading picks up after it', () => {
	const f = new F.Follower(sc);
	const mid = firstOf(2) + 10;
	let r = run(f, hear(0, mid, 5));
	const before = f.word();
	const adlib = 'oh i love this part i wrote it on a windy night last spring with tea'.split(' ');
	r = run(f, adlib, r.seen);
	assert.ok(Math.abs(f.word() - before) <= 2, 'held within two words: ' + before + ' to ' + f.word());
	run(f, hear(mid, mid + 15, 9), r.seen);
	assert.ok(f.word() >= mid + 10, 'followed again after the ad-lib: ' + f.word());
});

test('a skip ahead is followed within a few words', () => {
	const f = new F.Follower(sc);
	let r = run(f, hear(0, firstOf(1) + 8, 11));
	const to = firstOf(3) + 2;
	const after = hear(to, to + 14, 13);
	r = run(f, after, r.seen);
	let caught = -1;
	r.at.forEach((w, i) => { if (caught < 0 && Math.abs(w - (to + i)) <= 4 && w > firstOf(3)) { caught = i; } });
	assert.ok(caught >= 0 && caught <= 9, 'caught up after ' + caught + ' words');
	assert.ok(f.jumps >= 1);
});

test('going back to read again is followed', () => {
	const f = new F.Follower(sc);
	let r = run(f, hear(0, firstOf(3) + 12, 17));
	const back = firstOf(2) + 3;
	r = run(f, hear(back, back + 14, 19), r.seen);
	assert.ok(f.word() > back && f.word() < firstOf(3), 'back in paragraph 3: ' + f.word());
});

test('a jump by hand: setWord moves the follower, which carries on from there', () => {
	const f = new F.Follower(sc);
	const r = run(f, hear(0, 12, 23));
	f.setWord(firstOf(4));
	run(f, hear(firstOf(4), firstOf(4) + 8, 29), r.seen);
	assert.ok(f.word() >= firstOf(4) + 5, 'reading on from the new place: ' + f.word());
});

test('a misheard last word is noted for the page to end on, and elsewhere a misheard word waits (1.36.1)', () => {
	const last = shown.length - 1;
	const f = new F.Follower(sc);
	let r = run(f, F.words(shown.slice(0, last).join(' ')));
	assert.equal(f.word(), last, 'waiting on the last word');
	assert.equal(f.endHeard, false);
	r = run(f, ['hum'], r.seen);
	assert.equal(f.word(), last, 'the place stays until the voice goes quiet');
	assert.equal(f.endHeard, true);
	/* More words after it (an ad-lib before the real last word) keep it noted. */
	r = run(f, ['oh', 'yes'], r.seen);
	assert.equal(f.endHeard, true);
	/* The real last word heard after all ends the script as before. */
	run(f, F.words(shown.slice(last - 3).join(' ')), r.seen);
	assert.equal(f.word(), shown.length);
	assert.equal(f.endHeard, false);
	/* A misheard word in the middle holds the place, as before, and notes nothing. */
	const g = new F.Follower(sc);
	const mid = firstOf(2) + 6;
	r = run(g, F.words(shown.slice(0, mid).join(' ')));
	const at = g.word();
	run(g, ['hum'], r.seen);
	assert.ok(g.word() <= at, 'held in the middle: ' + at + ' to ' + g.word());
	assert.equal(g.endHeard, false);
});

test('a follower not on track at the last word notes nothing (1.36.1)', () => {
	const last = shown.length - 1;
	/* Moved to the last word by hand, with nothing matched there yet. */
	const f = new F.Follower(sc);
	f.setWord(last);
	run(f, 'oh i love this part'.split(' '));
	assert.equal(f.word(), last);
	assert.equal(f.endHeard, false);
	/* Noted, then moved by hand: the note is gone. */
	const g = new F.Follower(sc);
	const r = run(g, F.words(shown.slice(0, last).join(' ')));
	run(g, ['hum'], r.seen);
	assert.equal(g.endHeard, true);
	g.setWord(firstOf(4));
	assert.equal(g.endHeard, false);
});

test('nothing new heard: the place stays', () => {
	const f = new F.Follower(sc);
	const r = run(f, hear(0, 20, 31));
	const w = f.word();
	assert.equal(f.update(r.seen.slice(-12), r.seen.length), w);
	assert.equal(f.update([], 0), w);
});

test('pace: words per minute over the last 30 s, not across a jump, needing 15 s', () => {
	const pts = [];
	for (let t = 0; t <= 30; t++) { pts.push({ t: t, w: Math.round(t * 2.2) }); }
	assert.equal(F.pace(pts, 30), 132);
	assert.equal(F.pace(pts.slice(0, 12), 11), null, 'too soon to say');
	const jumped = pts.slice(0, 20).concat([{ t: 20, w: 200 }, { t: 25, w: 211 }, { t: 30, w: 222 }, { t: 35, w: 233 }, { t: 40, w: 244 }]);
	assert.equal(F.pace(jumped, 40), 132, 'only the part since the jump counts');
	assert.equal(F.pace(jumped.slice(0, 22), 30), null, 'and needs 15 s of its own');
	const long = [];
	for (let t = 0; t <= 60; t++) { long.push({ t: t, w: Math.round(t * (t < 30 ? 3 : 2)) }); }
	assert.equal(F.pace(long, 60), 120, 'only the last 30 s count');
	assert.equal(F.pace([{ t: 0, w: 5 }, { t: 20, w: 5 }], 20), null, 'no progress, no pace');
});

test('first look: paragraphs never heard between the first and last heard', () => {
	const f = new F.Follower(sc);
	let r = run(f, hear(0, firstOf(2), 37));
	r = run(f, hear(firstOf(3), firstOf(4) + 5, 41), r.seen);
	const look = F.firstLook(f.heard, block);
	assert.equal(look.heardAny, true);
	assert.deepEqual(look.skipped, [3], 'paragraph 3 was skipped');
	assert.equal(look.first, 1);
	assert.equal(look.last, 5);
	const none = F.firstLook(new Uint8Array(shown.length), block);
	assert.equal(none.heardAny, false);
	assert.deepEqual(none.skipped, []);
});

test('the lead: moves on at the pace while the voice sounds, never past the cap, and stops in a pause', () => {
	let s = { shown: 10, heard: 10, acc: 0 };
	for (let i = 0; i < 10; i++) { F.leadStep(s, 0.1, true, 2.5, 3); }
	assert.equal(s.shown, 12, 'one second at 2.5 words a second');
	for (let i = 0; i < 20; i++) { F.leadStep(s, 0.1, true, 2.5, 3); }
	assert.equal(s.shown, 13, 'no further than three words past the last word heard');
	s.heard = 14;
	F.leadStep(s, 0.1, true, 2.5, 3);
	assert.equal(s.shown, 14, 'catches up to a word heard beyond it');
	for (let i = 0; i < 20; i++) { F.leadStep(s, 0.1, false, 2.5, 3); }
	assert.equal(s.shown, 14, 'holds while the voice is quiet');
	for (let i = 0; i < 20; i++) { F.leadStep(s, 0.1, true, 2.5, 1); }
	assert.equal(s.shown, 15, 'a cap of one word while nothing is matched');
});

test('the voice sounding: speech over the room, held through short gaps, not through a pause', () => {
	const v = {};
	let t = 0;
	const step = (db) => { t += 0.085; return F.voiced(v, db, t); };
	for (let i = 0; i < 20; i++) { step(-60); }
	assert.equal(step(-60), false, 'the room alone is quiet');
	assert.equal(step(-30), true, 'a word 30 dB over the room sounds');
	assert.equal(step(-60), true, 'a short gap inside a phrase still counts');
	step(-60); step(-60);
	assert.equal(step(-60), false, 'a pause of a quarter second or more stops it');
	for (let i = 0; i < 40; i++) { step(i % 4 === 3 ? -58 : -32); }
	assert.equal(step(-31), true, 'a long phrase with breaths keeps sounding');
});
