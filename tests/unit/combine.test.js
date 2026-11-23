'use strict';
/* One reading from several takes (1.3.0): pieces, choosing, joins, leveling and the words' new times. */
const test = require('node:test');
const assert = require('node:assert/strict');
const { load } = require('./load');
const { RATE, hush, rms } = require('./synth');

const C = load('combine.js');
const E = load('edit.js');

/* Two paragraphs of two sentences, two words each: "a b. c d." / "e f. g h." */
const SCRIPT = 'a b. c d. e f. g h.'.split(' ').map(function (w, k) {
	return { key: w.replace('.', ''), block: k < 4 ? 0 : 1, sent: Math.floor(k / 2) };
});

/*
 * A made-up take: each word a 0.4 s voiced burst at its time, over a hush.
 * times: [start] per script word, or null for a word outside the stretch read.
 */
function take(id, times, opts) {
	opts = opts || {};
	const level = opts.level || 0.3;
	const last = times.reduce(function (m, t) { return t === null ? m : Math.max(m, t); }, 0);
	const seconds = last + 0.4 + (opts.tail || 1.5);
	const x = hush(Math.round(seconds * RATE), 0.002, opts.seed || 3);
	const words = times.map(function (t, k) {
		if (t === null) { return null; }
		const a = Math.round(t * RATE);
		const m = Math.round(0.4 * RATE);
		for (let i = 0; i < m; i++) {
			const env = Math.min(1, i / 400, (m - i) / 400);
			x[a + i] += level * env * Math.sin(2 * Math.PI * (150 + 20 * k) * i / RATE);
		}
		return [t, t + 0.4, 'ok'];
	});
	const chunks = times.map(function (t, k) { return t === null ? null : { text: ' ' + SCRIPT[k].key, timestamp: [t, t + 0.4] }; }).filter(Boolean);
	return { id: id, x: x, seconds: seconds, words: words, problems: opts.problems || [], chunks: chunks, level: C.speechLevel(x, RATE) };
}
/* Words 0.6 s apart in a sentence, pauses of `pause` between sentences. */
function evenly(start, pause, from) {
	const out = [];
	let t = start;
	for (let k = 0; k < SCRIPT.length; k++) {
		if (k < (from || 0)) { out.push(null); continue; }
		out.push(t);
		t += 0.4 + (k % 2 === 1 ? pause : 0.2);
	}
	return out;
}
/* Where the voice starts and stops around a word, found from the sound as the studio does. */
function edger(takes) {
	return function (id, k) {
		const t = takes[id];
		const tokens = [];
		t.words.forEach(function (w, i) { if (w) { tokens.push({ s: w[0], e: w[1], k: i }); } });
		const i = tokens.findIndex(function (q) { return q.k === k; });
		return E.speechEdges(tokens, i, i, t.x, RATE);
	};
}
function build(takes, choice, joins, split) {
	const byId = {};
	takes.forEach(function (t) { byId[t.id] = t; });
	const list = C.pieces(SCRIPT, split || {});
	const segs = C.layout(C.runs(list, choice), byId, SCRIPT, edger(byId), joins || {});
	const out = C.render(segs, function (id, s, e) {
		return byId[id].x.slice(Math.round(s * RATE), Math.round(e * RATE));
	}, function (id) { return E.roomTone(byId[id].x, RATE, 1); }, RATE);
	out.segs = segs;
	out.chunks = C.mapChunks(Object.fromEntries(takes.map(function (t) { return [t.id, t.chunks]; })), out.map);
	return out;
}
/* Where the voice of the result is, from the sound: [start, end] of each burst. */
function bursts(x) {
	const n = Math.round(0.01 * RATE);
	const out = [];
	let on = -1;
	for (let a = 0; a + n <= x.length; a += n) {
		const loud = rms(x, a, a + n) > 0.05;
		if (loud && on < 0) { on = a; }
		if (!loud && on >= 0) { out.push([on / RATE, a / RATE]); on = -1; }
	}
	return out;
}

test('paragraphs are pieces, and a split paragraph gives one piece a sentence', () => {
	const whole = C.pieces(SCRIPT, {});
	assert.deepEqual(whole.map(function (p) { return [p.id, p.first, p.last]; }), [['b0', 0, 3], ['b1', 4, 7]]);
	const split = C.pieces(SCRIPT, { 1: true });
	assert.deepEqual(split.map(function (p) { return [p.id, p.first, p.last]; }), [['b0', 0, 3], ['s2', 4, 5], ['s3', 6, 7]]);
});

test('a piece with nothing to say is left out', () => {
	const s = SCRIPT.concat([{ key: '', block: 2, sent: 4 }]);
	assert.equal(C.pieces(s, {}).length, 2);
});

test('a take covers only the pieces it read', () => {
	const full = take('t1', evenly(1, 1));
	const part = take('t2', evenly(1, 1, 4));
	const list = C.pieces(SCRIPT, {});
	assert.equal(C.covers(full, list[0], SCRIPT), true);
	assert.equal(C.covers(part, list[0], SCRIPT), false);
	assert.equal(C.covers(part, list[1], SCRIPT), true);
	/* A skipped word inside the stretch read is a flag, and the piece is still covered. */
	const skipped = take('t3', evenly(1, 1));
	skipped.words[1][2] = 'omit';
	assert.equal(C.covers(skipped, list[0], SCRIPT), true);
});

test('flags are counted for the piece they are in, weighed by kind', () => {
	const t = take('t1', evenly(1, 1), { problems: [{ kind: 'omit', idx: 1 }, { kind: 'check', idx: 2 }, { kind: 'filler', idx: 5 }] });
	const list = C.pieces(SCRIPT, {});
	assert.deepEqual([C.flagsIn(t, list[0]).n, C.flagsIn(t, list[0]).weight], [2, 3.5]);
	assert.deepEqual([C.flagsIn(t, list[1]).n, C.flagsIn(t, list[1]).weight], [1, 1]);
});

test('the best of each takes the cleaner take for each paragraph', () => {
	const t1 = take('t1', evenly(1, 1), { problems: [{ kind: 'omit', idx: 1 }] });
	const t2 = take('t2', evenly(1, 1), { problems: [{ kind: 'sub', idx: 6 }] });
	assert.deepEqual(C.best(C.pieces(SCRIPT, {}), [t1, t2], SCRIPT), { b0: 't2', b1: 't1' });
});

test('the best of each does not switch takes for a small gain', () => {
	/* "Worth a listen" weighs less than a join, so the reading stays in one take. */
	const t1 = take('t1', evenly(1, 1), { problems: [{ kind: 'check', idx: 5 }] });
	const t2 = take('t2', evenly(1, 1), { problems: [{ kind: 'check', idx: 1 }, { kind: 'check', idx: 2 }] });
	const c = C.best(C.pieces(SCRIPT, {}), [t1, t2], SCRIPT);
	assert.equal(c.b0, c.b1);
	assert.equal(c.b0, 't1');
});

test('on a tie the take that reads the most wins, and a partial take is used only where it is better', () => {
	const full = take('t1', evenly(1, 1), { problems: [{ kind: 'sub', idx: 6 }] });
	const part = take('t2', evenly(1, 1, 4));
	assert.deepEqual(C.best(C.pieces(SCRIPT, {}), [part, full], SCRIPT), { b0: 't1', b1: 't2' });
	const clean = take('t1', evenly(1, 1));
	assert.deepEqual(C.best(C.pieces(SCRIPT, {}), [part, clean], SCRIPT), { b0: 't1', b1: 't1' });
});

test('a piece no take reads is left without a take, and is missing', () => {
	const part = take('t2', evenly(1, 1, 4));
	const list = C.pieces(SCRIPT, {});
	const c = C.best(list, [part], SCRIPT);
	assert.deepEqual(c, { b0: null, b1: 't2' });
	assert.deepEqual(C.missing(list, c).map(function (p) { return p.id; }), ['b0']);
});

test('pieces from the same take in a row are one run', () => {
	const list = C.pieces(SCRIPT, { 0: true, 1: true });
	const r = C.runs(list, { s0: 'a', s1: 'a', s2: 'b', s3: 'a' });
	assert.deepEqual(r.map(function (x) { return [x.take, x.first, x.last]; }), [['a', 0, 3], ['b', 4, 5], ['a', 6, 7]]);
});

test('one take alone comes back as recorded, its words where they were', () => {
	const t1 = take('t1', evenly(1, 1));
	const out = build([t1], { b0: 't1', b1: 't1' });
	assert.equal(out.segs.length, 1);
	const lead = out.segs[0].s;
	assert.ok(Math.abs(out.chunks[0].timestamp[0] - (1 - lead)) < 0.002, JSON.stringify(out.chunks[0]));
	assert.equal(out.chunks.length, 8);
	assert.ok(Math.abs(out.samples.length / RATE - (out.segs[0].e - out.segs[0].s)) < 0.001);
});

test('a join keeps the later take\'s own pause, and every word lands where its chunk says', () => {
	/* Take 1 pauses 1.0 s between sentences, take 2 pauses 1.6 s. */
	const t1 = take('t1', evenly(1, 1.0), { seed: 3 });
	const t2 = take('t2', evenly(1.3, 1.6), { seed: 5 });
	const out = build([t1, t2], { b0: 't1', b1: 't2' });
	assert.equal(out.segs.length, 2);
	assert.equal(out.joins.length, 1);
	const b = bursts(out.samples);
	assert.equal(b.length, 8, JSON.stringify(b));
	/* Between "d." (take 1) and "e" (take 2): take 2's pause before "e" is 1.6 s. */
	const gap = b[4][0] - b[3][1];
	assert.ok(Math.abs(gap - 1.6) < 0.06, 'gap ' + gap);
	/* Each chunk starts where its word is heard. */
	out.chunks.forEach(function (c, i) { assert.ok(Math.abs(c.timestamp[0] - b[i][0]) < 0.03, i + ': ' + c.timestamp[0] + ' vs ' + b[i][0]); });
	assert.deepEqual(out.chunks.map(function (c) { return c.text.trim(); }), ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h']);
});

test('the join itself is quiet: no click between the two takes', () => {
	const t1 = take('t1', evenly(1, 1.0), { seed: 3 });
	const t2 = take('t2', evenly(1.3, 1.6), { seed: 5 });
	const out = build([t1, t2], { b0: 't1', b1: 't2' });
	const at = Math.round(out.joins[0].at * RATE);
	let peak = 0;
	for (let i = at - 400; i < at + Math.round(C.FADE * RATE) + 400; i++) { peak = Math.max(peak, Math.abs(out.samples[i])); }
	assert.ok(peak < 0.01, 'peak ' + peak);
});

test('a take that starts at the piece gets the room\'s background for the rest of the pause', () => {
	const t1 = take('t1', evenly(1, 1.0), { seed: 3 });
	/* Take 2 starts 0.15 s before "e": too little lead-in for a pause. */
	const t2 = take('t2', evenly(0.15, 1.0, 4), { seed: 5 });
	const out = build([t1, t2], { b0: 't1', b1: 't2' });
	assert.ok(out.segs[1].tone > 0.4, 'tone ' + out.segs[1].tone);
	const b = bursts(out.samples);
	assert.equal(b.length, 8);
	const gap = b[4][0] - b[3][1];
	/* Neither take shows a pause there that both read, so take 1's own pause after "d." is used: 1.0 s. */
	assert.ok(Math.abs(gap - 1.0) < 0.06, 'gap ' + gap);
	out.chunks.forEach(function (c, i) { assert.ok(Math.abs(c.timestamp[0] - b[i][0]) < 0.03, i + ': ' + c.timestamp[0] + ' vs ' + b[i][0]); });
});

test('a quieter take is leveled to the one that reads the most', () => {
	const t1 = take('t1', evenly(1, 1.0), { seed: 3 });
	const t2 = take('t2', evenly(1.3, 1.0), { seed: 5, level: 0.15 });
	assert.ok(Math.abs((t1.level - t2.level) - 6.02) < 0.3, t1.level + ' ' + t2.level);
	const out = build([t1, t2], { s0: 't1', s1: 't1', s2: 't1', s3: 't2' }, {}, { 0: true, 1: true });
	assert.ok(Math.abs(out.segs[1].gain - 6) < 0.4, 'gain ' + out.segs[1].gain);
	const b = bursts(out.samples);
	const lv = function (k) { return rms(out.samples, Math.round((b[k][0] + 0.05) * RATE), Math.round((b[k][1] - 0.05) * RATE)); };
	assert.ok(Math.abs(20 * Math.log10(lv(7) / lv(0))) < 0.6, lv(0) + ' ' + lv(7));
});

test('a nudge moves the earlier end, the later start, and adds space', () => {
	const t1 = take('t1', evenly(1, 1.0), { seed: 3 });
	const t2 = take('t2', evenly(1.3, 1.6), { seed: 5 });
	const plain = build([t1, t2], { b0: 't1', b1: 't2' });
	const key = plain.segs[1].join;
	assert.equal(key, C.joinKey('t1', 't2', 3, 4));
	const later = build([t1, t2], { b0: 't1', b1: 't2' }, { [key]: { a: 0, b: 300, sp: 0 } });
	const spaced = build([t1, t2], { b0: 't1', b1: 't2' }, { [key]: { a: 0, b: 0, sp: 500 } });
	const gap = function (o) { const b = bursts(o.samples); return b[4][0] - b[3][1]; };
	assert.ok(Math.abs(gap(later) - (gap(plain) - 0.3)) < 0.02, gap(later) + ' ' + gap(plain));
	assert.ok(Math.abs(gap(spaced) - (gap(plain) + 0.5)) < 0.02, gap(spaced) + ' ' + gap(plain));
});

test('nudges step and stop at their limits; space never goes below none', () => {
	assert.deepEqual(C.nudge(null, 'a', 1), { a: 10, b: 0, sp: 0 });
	assert.deepEqual(C.nudge({ a: 0, b: 0, sp: 0 }, 'sp', 1), { a: 0, b: 0, sp: 50 });
	assert.equal(C.nudge({ a: 0, b: 0, sp: 0 }, 'sp', -1), null);
	assert.equal(C.nudge({ a: C.NUDGE_MAX, b: 0, sp: 0 }, 'a', 1), null);
});

test('words outside a run do not come along with it', () => {
	const t1 = take('t1', evenly(1, 1.0), { seed: 3 });
	const t2 = take('t2', evenly(1.3, 1.6), { seed: 5 });
	const out = build([t1, t2], { b0: 't2', b1: 't1' });
	assert.equal(out.chunks.length, 8);
	assert.deepEqual(out.chunks.map(function (c) { return c.text.trim(); }), ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h']);
});

test('the same take is recognised, another is not', () => {
	const t1 = take('t1', evenly(1, 1.0), { seed: 3 });
	const t2 = take('t2', evenly(1.3, 1.6), { seed: 5 });
	const again = new Float32Array(t1.x.length);
	for (let i = 0; i < again.length; i++) { again[i] = Math.round(t1.x[i] * 32767) / 32767; }
	assert.equal(C.sameTake(C.fingerprint(t1.x, RATE), C.fingerprint(again, RATE)), true);
	assert.equal(C.sameTake(C.fingerprint(t1.x, RATE), C.fingerprint(t2.x, RATE)), false);
});

test('silence has no speech level', () => {
	assert.equal(C.speechLevel(hush(RATE * 3, 0.002, 1), RATE), null);
});

test('a saved plan keeps only what still applies', () => {
	const list = C.pieces(SCRIPT, {});
	const p = C.cleanPlan({ choice: { b0: 't1', b1: 'gone', s9: 't1' }, split: { 1: true, x: true }, joins: { k: { a: '20', b: 9999, sp: -5 }, z: { a: 0 } } }, list, ['t1']);
	assert.deepEqual(p, { choice: { b0: 't1' }, split: { 1: true }, joins: { k: { a: 20, b: C.NUDGE_MAX, sp: 0 } } });
	assert.deepEqual(C.cleanPlan(null, list, []), { choice: {}, split: {}, joins: {} });
});

/* Reading a post in parts (1.4.0): coverage and where Continue starts. */
test('coverage: no takes leaves every paragraph to read, from the top', function () {
	const c = C.coverage(SCRIPT, []);
	assert.equal(c.paras.length, 2);
	assert.equal(c.left, 2);
	assert.equal(c.start, 0);
});
test('coverage: a take of the first paragraph sends Continue one sentence back, as a run-up', function () {
	const t = take('a', evenly(0.5, 0.6).map(function (v, k) { return k < 4 ? v : null; }));
	const c = C.coverage(SCRIPT, [t]);
	assert.deepEqual(c.paras.map(function (p) { return p.by; }), [['a'], []]);
	assert.equal(c.left, 1);
	/* Paragraph two starts at word 4; the sentence before it ("c d.") starts at word 2. */
	assert.equal(c.start, 2);
});
test('coverage: a take that stops inside a paragraph leaves that paragraph to read', function () {
	const t = take('a', evenly(0.5, 0.6).map(function (v, k) { return k < 6 ? v : null; }));
	const c = C.coverage(SCRIPT, [t]);
	assert.equal(c.left, 1);
	assert.equal(c.start, 2);
});
test('coverage: two partial takes together cover the post', function () {
	const a = take('a', evenly(0.5, 0.6).map(function (v, k) { return k < 4 ? v : null; }));
	const b = take('b', evenly(0.5, 0.6, 2));
	const c = C.coverage(SCRIPT, [a, b]);
	assert.equal(c.left, 0);
	assert.equal(c.start, -1);
	assert.deepEqual(c.paras[1].by, ['b']);
});
test('coverage: a gap in the middle is found even when a later paragraph is read', function () {
	const b = take('b', evenly(0.5, 0.6, 4));
	const c = C.coverage(SCRIPT, [b]);
	assert.equal(c.left, 1);
	assert.equal(c.start, 0);
});
