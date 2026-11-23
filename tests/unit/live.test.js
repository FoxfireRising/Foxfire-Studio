/*
 * Phone take mode (1.12.0): pieces, what is left to send after an
 * interruption, where Carry on starts, the take's state on the site, and the
 * large meter at a phone's level (about -54 LUFS, needing 35 dB), a laptop's
 * (about -44), a voice that drops partway through, and a clipped one.
 */
'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { load } = require('./load');

const S = load('sound.js', 'RRTSound');
const L = load('live.js', 'RRTLive');
const RATE = 48000;

/* A voice-like reading over a steady room hiss (as in phone.test.js). */
function reading(secs, roomBelow) {
	const x = new Float32Array(Math.round(RATE * secs));
	let seed = 11;
	function rnd() { seed = (seed * 16807) % 2147483647; return seed / 2147483647; }
	for (let i = 0; i < x.length; i++) {
		const t = i / RATE;
		const inPhrase = (t % 4) < 3.1 ? 1 : 0;
		const syl = Math.max(0, Math.sin(Math.PI * (t % 0.25) / 0.25));
		const env = inPhrase * syl * (0.7 + 0.3 * Math.sin(2 * Math.PI * t / 9));
		x[i] = 0.3 * env * (Math.sin(2 * Math.PI * 170 * t) + 0.5 * Math.sin(2 * Math.PI * 340 * t) + 0.25 * Math.sin(2 * Math.PI * 510 * t));
	}
	const lv = S.lufs(x, RATE);
	const hiss = Math.pow(10, (lv - roomBelow) / 20) * Math.sqrt(3);
	for (let i = 0; i < x.length; i++) { x[i] += hiss * (2 * rnd() - 1); }
	return x;
}
function at(x, lufs) {
	const g = Math.pow(10, (lufs - S.lufs(x, RATE)) / 20);
	return x.map((v) => v * g);
}
/* Feeds the meter the way the page does: a 512-sample window about 60 times a second. Returns each state. */
function feed(m, x, from, to) {
	const N = 512, hop = Math.round(RATE / 60), out = [];
	for (let i = from || 0; i + N < (to || x.length); i += hop) {
		let s = 0, p = 0;
		for (let k = i; k < i + N; k++) { s += x[k] * x[k]; p = Math.max(p, Math.abs(x[k])); }
		m.push(Math.sqrt(s / N), p, 1 / 60);
		out.push(m.state());
	}
	return out;
}
/* The middle reading once the meter has heard 8 seconds: the fixture's own level swings slowly, as a reader's does. */
function typical(states) {
	const v = states.slice(8 * 60).filter((x) => x.voiceDb !== null).sort((a, b) => a.voiceDb - b.voiceDb);
	return v[Math.floor(v.length / 2)];
}
function meter() { return L.meter({ target: -19, nrDb: 20, liftLimit: S.liftLimit }); }

test('pieces are about ten seconds of whole samples', () => {
	assert.equal(L.pieceBytes(48000, 24), 1440000);
	assert.equal(L.pieceBytes(44100, 16), 882000);
	assert.equal(L.pieceBytes(44100, 24) % 3, 0);
	assert.equal(L.seconds(1440000, 48000, 24), 10);
	assert.equal(L.mb(1048576 * 2.26), '2.3');
});

test('after an interruption only what the site is missing is sent', () => {
	/* The sandbox's objects are not Node's, so fields are compared. */
	const plan = (a, b, bits) => { const p = L.resumePlan(a, b, bits); return [p.from, p.bytes]; };
	assert.deepEqual(plan(3000000, 2880000, 24), [2880000, 120000]);
	assert.deepEqual(plan(3000000, 3000000, 24), [3000000, 0], 'nothing left');
	assert.deepEqual(plan(1000, 2000, 16), [1000, 0], 'the site has more than the phone kept');
	assert.deepEqual(plan(3000, 1001, 24), [999, 2001], 'back to a whole sample');
	assert.deepEqual(plan(3000, 0, 16), [0, 3000]);
});

test('Carry on starts at the sentence before the one the prompter was in', () => {
	/* Words 0-3 sentence 0, 4-6 sentence 1, 7-9 sentence 2. */
	const sents = [0, 0, 0, 0, 1, 1, 1, 2, 2, 2];
	assert.equal(L.carryStart(sents, 8), 4, 'in sentence 2: back to the start of sentence 1');
	assert.equal(L.carryStart(sents, 7), 4, 'at the first word of sentence 2');
	assert.equal(L.carryStart(sents, 5), 0);
	assert.equal(L.carryStart(sents, 2), 0, 'in the first sentence: the top');
	assert.equal(L.carryStart(sents, 99), 4, 'past the end counts as the last word');
	assert.equal(L.carryStart([], 3), 0);
});

test('a take on the site is done, live or cut', () => {
	assert.equal(L.state({ live: false }, 1000), 'done');
	assert.equal(L.state({ live: true, updated: 950 }, 1000), 'live');
	assert.equal(L.state({ live: true, updated: 900 }, 1000), 'cut', 'not heard from for over 90 s');
	assert.equal(L.state({ live: false, cut: true }, 1000), 'cut', 'finished after an interruption');
});

test('the meter at a phone level reads about -54 LUFS, needing 35 dB, which a quiet room allows', () => {
	const x = at(reading(20, 31), -54);
	const all = feed(meter(), x);
	const st = typical(all);
	assert.ok(all.slice(8 * 60).every((a) => a.word === 'good'), 'good throughout');
	assert.ok(Math.abs(st.voiceDb - (-54)) < 2, 'voice ' + st.voiceDb);
	assert.ok(Math.abs(st.need - 35) < 2, 'need ' + st.need);
	assert.ok(st.limit > 44, 'limit ' + st.limit);
});

test('the meter at a laptop level reads about -44 LUFS, needing 25 dB', () => {
	const st = typical(feed(meter(), at(reading(20, 31), -44)));
	assert.ok(Math.abs(st.need - 25) < 2, 'need ' + st.need);
	assert.equal(st.word, 'good');
});

test('a voice that drops 9 dB partway says to move closer within eight seconds', () => {
	const x = reading(40, 31);
	const a = at(x.subarray(0, RATE * 20), -54);
	const b = at(x.subarray(RATE * 20), -63);
	const y = new Float32Array(x.length);
	y.set(a);
	y.set(b, a.length);
	const m = meter();
	const first = feed(m, y, 0, RATE * 20).pop();
	assert.equal(first.word, 'good');
	const later = feed(m, y, RATE * 20, RATE * 28);
	assert.ok(later.slice(-30).every((s) => s.word === 'quiet' || s.word === 'low'), later.slice(-1)[0].word + ' need ' + later.slice(-1)[0].need);
	const deeper = feed(meter(), at(reading(20, 31), -68)).pop();
	assert.equal(deeper.word, 'low', 'need ' + deeper.need + ' over the limit ' + deeper.limit);
});

test('a clipped peak says too loud, and nothing is said before a voice is heard', () => {
	const m = meter();
	const silent = feed(m, new Float32Array(RATE)).pop();
	assert.equal(silent.word, 'wait');
	assert.equal(silent.voiceDb, null);
	const x = at(reading(10, 31), -20);
	for (let i = RATE * 5; i < RATE * 5 + 200; i++) { x[i] = 0.99; }
	const st = feed(m, x, 0, RATE * 5 + 2000).pop();
	assert.equal(st.word, 'loud');
	assert.ok(st.peak > -1, 'peak ' + st.peak);
});

test('the meter scale', () => {
	assert.equal(L.meterPct(0), 100);
	assert.equal(L.meterPct(-72), 0);
	assert.equal(L.meterPct(-200), 0);
	assert.equal(L.meterPct(-36), 50);
});
