'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { load } = require('./load');
const { RATE, reading } = require('./synth');

const R = load('readout.js', 'RRTReadout');

function lineOf(lines, label) {
	const l = lines.find(function (x) { return x.label === label; });
	return l ? l.text : '';
}

test('too little to judge gives no read-out', () => {
	assert.equal(R.analyseReading(new Float32Array(RATE / 2), RATE, 40, 140), null);
	assert.equal(R.analyseReading(reading(10).data, RATE, 5, 140), null);
});

test('pace is words over minutes, and the set pace is kept', () => {
	const r = reading(10);
	const m = R.analyseReading(r.data, RATE, 40, 150);
	assert.equal(m.wpm, Math.round(40 / (r.seconds / 60)));
	assert.equal(m.setWpm, 150);
	assert.ok(Math.abs(m.seconds - r.seconds) < 0.01);
});

test('rests are measured as a share of the reading', () => {
	// 11 pauses of 1.2 s and 10 phrases of 1.6 s: 13.2 of 29.2 seconds.
	const m = R.analyseReading(reading(10).data, RATE, 40, 140);
	assert.ok(Math.abs(m.restShare - 13.2 / 29.2) < 0.1, String(m.restShare));
	assert.equal(m.pauseCount, 9);
	assert.equal(m.longPauses, 9);
});

test('pauses that shrink in the second half are noticed and described', () => {
	const m = R.analyseReading(reading(10, { pause: function (p) { return p < 6 ? 1.6 : 0.6; } }).data, RATE, 40, 140);
	assert.ok(m.pauseEarly > m.pauseLate * 1.5);
	assert.match(lineOf(R.describeReading(m, false, String), 'Pauses'), /shorter in the second half/);
	const even = R.analyseReading(reading(10).data, RATE, 40, 140);
	assert.match(lineOf(R.describeReading(even, false, String), 'Pauses'), /consistent from start to finish/);
});

test('a faded ending shows as a bigger drop than a carried one', () => {
	const carried = R.analyseReading(reading(10).data, RATE, 40, 140);
	const faded = R.analyseReading(reading(10, { fade: 0.85 }).data, RATE, 40, 140);
	assert.ok(carried.endDropDb < 2, String(carried.endDropDb));
	assert.ok(faded.endDropDb > 6, String(faded.endDropDb));
});

test('a moving voice has pitch spread and a monotone has none', () => {
	const moving = R.analyseReading(reading(10).data, RATE, 40, 140);
	const flat = R.analyseReading(reading(10, { swing: 0 }).data, RATE, 40, 140);
	assert.ok(moving.pitchSpread > 2, String(moving.pitchSpread));
	assert.ok(flat.pitchSpread < 0.5, String(flat.pitchSpread));
});

test('the frames are kept for the review, in 20 ms steps', () => {
	const m = R.analyseReading(reading(10).data, RATE, 40, 140);
	assert.equal(m.frames.step, 0.02);
	assert.equal(m.frames.db.length, Math.floor(RATE * 29.2 / Math.round(RATE * 0.02)));
	assert.equal(typeof m.frames.spread, 'function');
});

test('the pace line fits the pace, and says when the prompter was outrun', () => {
	const fast = R.analyseReading(reading(10, { len: 1.2, pause: 0.4 }).data, RATE, 60, 140);
	const lines = R.describeReading(fast, false, String);
	assert.match(lineOf(lines, 'Pace'), /^About 220 words a minute, fast for listening/);
	assert.match(lineOf(lines, 'Pace'), /set to 140, so you ran ahead of it/);
	// Following the voice, the prompter's set pace is not a target.
	assert.doesNotMatch(lineOf(R.describeReading(fast, true, String), 'Pace'), /prompter was set/);
	const slow = R.analyseReading(reading(10).data, RATE, 40, 140);
	assert.match(lineOf(R.describeReading(slow, false, String), 'Pace'), /unhurried/);
});

test('with a kind of reading, the pace line judges against its range (1.11.0)', () => {
	const verse = { lo: 110, hi: 135, name: 'verse' };
	function line(wpm) { return lineOf(R.describeReading({ wpm: wpm, setWpm: wpm, restShare: 0.2, endDropDb: 2, pitchSpread: 2, energy: 3 }, false, String, verse), 'Pace'); }
	assert.match(line(120), /^About 120 words a minute, inside your verse range of 110 to 135\.$/);
	assert.match(line(105), /just under your verse range/);
	assert.match(line(90), /well under your verse range/);
	assert.match(line(140), /just over your verse range/);
	assert.match(line(160), /well over your verse range of 110 to 135\. Easing off/);
	// Without a range, the general bands as before.
	assert.match(lineOf(R.describeReading({ wpm: 120, setWpm: 120, restShare: 0.2, endDropDb: 2, pitchSpread: 2, energy: 3 }, false, String, null), 'Pace'), /comfortable listening pace/);
});

test('in verse and ritual, the Endings line matches the Faded ending flags (1.15.1)', () => {
	function line(db, range) { return lineOf(R.describeReading({ wpm: 120, setWpm: 120, restShare: 0.2, endDropDb: db, pitchSpread: 2, energy: 3 }, false, String, range), 'Endings'); }
	const ritual = { lo: 95, hi: 120, name: 'ritual and chant', trails: true };
	const story = { lo: 130, hi: 150, name: 'story', trails: false };
	assert.match(line(6, ritual), /^For ritual and chant, your line endings stayed steady/);
	assert.match(line(11, ritual), /eased off about \d+% in volume, a natural trail for ritual and chant\.$/);
	assert.match(line(16, ritual), /sank about \d+% in volume, more than ritual and chant usually wants\. Check the Faded ending flags/);
	// Prose, story and no kind keep the general bands.
	[story, null].forEach(function (r) {
		assert.match(line(2, r), /carried your phrases through/);
		assert.match(line(6, r), /faded about/);
		assert.match(line(11, r), /dropped about .* last words may get lost/);
	});
	// A range from before 1.15.1, without trails, is judged as before.
	assert.match(line(11, { lo: 110, hi: 135, name: 'verse' }), /dropped about/);
});

test('once the review is ready, the Endings line counts the Faded ending flags (1.15.1)', () => {
	function line(db, range, n) { return lineOf(R.describeReading({ wpm: 120, setWpm: 120, restShare: 0.2, endDropDb: db, pitchSpread: 2, energy: 3 }, false, String, range, n), 'Endings'); }
	const ritual = { lo: 95, hi: 120, name: 'ritual and chant', trails: true };
	assert.equal(line(6, ritual, 4), 'For ritual and chant, most of your line endings stayed steady and clear. 4 sentences faded further: see the Faded ending flags.');
	assert.match(line(11, ritual, 1), /natural trail for ritual and chant\. 1 sentence faded further: see the Faded ending flag\.$/);
	assert.doesNotMatch(line(16, ritual, 3), /faded further/); // That line already points to the flags.
	assert.equal(line(2, null, 2), 'You carried most of your phrases through to the last word. 2 sentences faded further: see the Faded ending flags.');
	assert.match(line(6, null, 1), /worth noticing\. 1 sentence faded further/);
	// No flags, or before the review: as before.
	assert.match(line(6, ritual, 0), /to the last word\.$/);
	assert.match(line(2, null), /Nicely done\.$/);
});

test('every line has a label and some text', () => {
	const m = R.analyseReading(reading(10, { fade: 0.85, swing: 0 }).data, RATE, 40, 140);
	const lines = R.describeReading(m, false, String);
	assert.ok(lines.length >= 4);
	lines.forEach(function (l) {
		assert.ok(l.label && l.text, JSON.stringify(l));
	});
	assert.match(lineOf(lines, 'Endings'), /./);
});

function roomLine(room) {
	const base = { clicks: [], strays: [], whistleShare: 0 };
	return lineOf(R.describeReading({ wpm: 140, setWpm: 140, restShare: 0.2, endDropDb: 2, pitchSpread: 2, room: Object.assign(base, room) }, false, String), 'Room');
}

test('room: a phone take cleaned to 45 dB reads as quiet once cleaned up (1.12.1)', () => {
	const t = roomLine({ snr: 32, denoise: { dropDb: 13, snrAfter: 45 } });
	assert.match(t, /^A quiet room once cleaned up: the background sat 32 dB below your voice as recorded, and noise reduction lowered it by 13 dB, to 45 dB below\./);
	assert.doesNotMatch(t, /noisy/i);
});

test('room: cleaned to between 35 and 44 dB is fairly quiet, with advice for any device', () => {
	const t = roomLine({ snr: 28, denoise: { dropDb: 13, snrAfter: 41 } });
	assert.match(t, /^A fairly quiet room once cleaned up/);
	assert.match(t, /A quieter spot would help a little\./);
	assert.doesNotMatch(t, /laptop/);
});

test('room: still noisy after noise reduction says so', () => {
	assert.match(roomLine({ snr: 18, denoise: { dropDb: 10, snrAfter: 28 } }), /^A noisy room: the background sat only 18 dB .* to 28 dB below, so it may still be heard in pauses\./);
});

test('room: with no noise reduction the recorded figure decides, and no laptop advice', () => {
	assert.match(roomLine({ snr: 48 }), /^A quiet room: the background sits 48 dB/);
	const fair = roomLine({ snr: 38 });
	assert.match(fair, /^A fairly quiet room: the background sits 38 dB below your voice\. A quieter spot would help a little\./);
	assert.match(roomLine({ snr: 30 }), /^A noisy room: the background sits only 30 dB/);
});

test('room: noise reduction that found little, or could not run, is still reported', () => {
	const little = roomLine({ snr: 30, denoise: { dropDb: 0.4, snrAfter: 30.4 } });
	assert.match(little, /^A noisy room: the background sits only 30 dB/);
	assert.match(little, /Noise reduction found little to lower\./);
	assert.match(roomLine({ snr: 30, denoiseFailed: 'Out of memory' }), /could not run this time \(out of memory\)/);
});

test('room: cleanup notes follow the room sentence', () => {
	assert.match(roomLine({ snr: 32, clicks: [1, 2], denoise: { dropDb: 13, snrAfter: 45 } }), /well down in the finished reading\. Cleaned up: 2 clicks before phrases\.$/);
});

test('room: stray sounds a moment apart are listed once, and the count stays true (1.31.0)', () => {
	const toSec = function (t) { return '0:' + String(Math.round(t)).padStart(2, '0'); };
	const t = lineOf(R.describeReading({ wpm: 140, setWpm: 140, restShare: 0.2, endDropDb: 2, pitchSpread: 2, room: { snr: 40, clicks: [], strays: [18.1, 18.3, 42], whistleShare: 0 } }, false, toSec), 'Room');
	assert.match(t, /3 stray sounds in pauses \(0:18, 0:42\)/);
	assert.equal(JSON.stringify(R.strayTimes([5, 5.2, 9, 5.4], toSec)), JSON.stringify(['0:05', '0:09']));
});
