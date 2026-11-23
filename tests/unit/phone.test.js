/*
 * Phone level (1.9.0): the lift limit that follows the room, and the
 * microphone check's lift line. Fixtures at a phone's level in the browser
 * (about -54 LUFS, room 39 dB under the voice before noise reduction) and at
 * a laptop's (about -44 LUFS, needing 23 to 27 dB), as measured on real takes.
 */
'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { load } = require('./load');

const S = load('sound.js', 'RRTSound');
const P = load('practice.js', 'RRTPractice');
const RATE = 44100;

/* A voice-like reading: syllables with short pauses and longer ones, over a steady room hiss. */
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
	/* The room, as white hiss, set relative to the voice's loud stretches. */
	const L = S.lufs(x, RATE);
	const hiss = Math.pow(10, (L - roomBelow) / 20) * Math.sqrt(3);
	for (let i = 0; i < x.length; i++) { x[i] += hiss * (2 * rnd() - 1); }
	return x;
}
function at(x, lufs) {
	const g = Math.pow(10, (lufs - S.lufs(x, RATE)) / 20);
	return x.map((v) => v * g);
}
function opts() { return { mode: 'podcast', target: -19, ceilingTp: -1.8, compress: false }; }

test('the room is measured from the quieter stretches, and digital silence does not count', () => {
	const x = at(reading(20, 50), -40);
	const room = S.roomLevel(x, RATE);
	assert.ok(room < -85 && room > -100, 'room ' + room);
	/* Padding with exact zeros leaves the room where it was. */
	const padded = new Float32Array(x.length + RATE * 5);
	padded.set(x, RATE * 5);
	assert.ok(Math.abs(S.roomLevel(padded, RATE) - room) < 1.5, 'padded ' + S.roomLevel(padded, RATE));
	assert.equal(S.roomLevel(new Float32Array(RATE * 3), RATE), null, 'silence alone says nothing');
});

test('the lift limit: 30 dB in a noisy or unknown room, up to 45 in a quiet one, louder presets on top', () => {
	assert.equal(S.MAX_GAIN, 30);
	assert.equal(S.MAX_LIFT, 45);
	assert.equal(S.ROOM_CEIL, -60);
	assert.equal(S.liftLimit(null, -19, 'podcast').limit, 30);
	assert.equal(S.liftLimit(-70, -19, 'podcast').limit, 30, 'a room at -70 may only rise 10 dB, so the usual 30 stands');
	assert.equal(S.liftLimit(-97, -19, 'podcast').limit, 37, 'a room at -97 may rise 37 dB');
	assert.equal(S.liftLimit(-130, -19, 'podcast').limit, 45, 'never more than 45');
	assert.equal(S.liftLimit(-130, -14, 'podcast').limit, 50, 'a -14 target gets its 5 dB on top');
	assert.equal(S.liftLimit(-130, -14, 'podcast').usual, 35);
	assert.equal(S.liftLimit(-130, null, 'audiobook').limit, 45, 'the audiobook mode gets nothing on top');
});

test('a phone-level take (about 35 dB of lift, quiet room) reaches -19 with the background under -60', () => {
	/* -53.6 LUFS as the iPhone gave; the room 39 dB under the voice, then 20 dB lower after noise reduction. */
	const x = at(reading(40, 59), -53.6);
	const r = S.process(x, RATE, opts());
	assert.equal(r.report.gainCapped, false, 'not held back');
	assert.equal(r.report.liftByRoom, true);
	assert.ok(r.report.gainDb > 33 && r.report.gainDb < 38, 'raised by ' + r.report.gainDb);
	assert.ok(Math.abs(S.lufs(r.data, RATE) - -19) < 0.5, 'measured ' + S.lufs(r.data, RATE));
	assert.ok(S.noiseFloorDb(r.data, RATE) <= -60, 'background ' + S.noiseFloorDb(r.data, RATE));
	assert.ok(S.roomLevel(r.data, RATE) <= -60, 'room ' + S.roomLevel(r.data, RATE));
});

test('the same phone take without noise reduction is held back where its room would rise over -60', () => {
	const x = at(reading(40, 39), -53.6);
	const r = S.process(x, RATE, opts());
	assert.equal(r.report.gainCapped, true);
	assert.ok(r.report.maxGainDb >= 30 && r.report.maxGainDb < 34.6, 'limit ' + r.report.maxGainDb);
	assert.ok(S.roomLevel(r.data, RATE) <= -59.5, 'room ' + S.roomLevel(r.data, RATE));
});

test('a laptop-level take (about 25 dB) is raised exactly as before 1.9.0', () => {
	const x = at(reading(40, 57), -44);
	const r = S.process(x, RATE, opts());
	assert.equal(r.report.gainCapped, false);
	assert.ok(r.report.gainDb > 23 && r.report.gainDb < 28, 'raised by ' + r.report.gainDb);
	assert.ok(Math.abs(S.lufs(r.data, RATE) - -19) < 0.5);
	assert.ok(r.report.gainDb < r.report.usualGainDb, 'inside the usual limit, so the read-out has nothing new to say');
});

test('a quiet voice in a noisy room keeps the 30 dB limit', () => {
	const x = at(reading(30, 22), -54);
	const r = S.process(x, RATE, opts());
	assert.equal(r.report.gainCapped, true);
	assert.equal(r.report.liftByRoom, false);
	assert.equal(r.report.maxGainDb, 30);
});

test('the music bed is not mistaken for the room', () => {
	const x = at(reading(30, 59), -53.6);
	const bed = new Float32Array(RATE * 10);
	for (let i = 0; i < bed.length; i++) { bed[i] = 0.2 * Math.sin(2 * Math.PI * 220 * i / RATE) * (0.6 + 0.4 * Math.sin(2 * Math.PI * i / RATE / 3)); }
	const r = S.process(x, RATE, Object.assign(opts(), { bed: { data: bed, depth: 20 } }));
	assert.equal(r.report.liftByRoom, true, 'the room is the voice track\'s own');
	assert.equal(r.report.gainCapped, false);
});

test('the check says how much lift a phone take needs, and that this room allows it', () => {
	/* Levels as the check measures them on the iPhone: room about -93 dBFS, voice -53.6 LUFS. */
	const lv = { roomDb: -93, voiceDb: -48, peakDb: -34.7, gap: 45, spoke: true, voiceLufs: -53.6 };
	const lift = P.lift(lv, { target: -19, mode: 'podcast', nrDb: 20, liftLimit: S.liftLimit });
	assert.equal(Math.round(lift.need), 35);
	assert.equal(lift.limit, 45);
	assert.equal(lift.over, false);
	const v = P.verdict(lv, lift);
	assert.equal(v.level, 'quiet');
	assert.ok(v.lines.some((l) => /needs about 35 dB of lift to reach -19 LUFS/.test(l) && /up to 45 dB/.test(l)), v.lines.join(' | '));
	assert.ok(!v.lines.some((l) => /leveling can lift it/.test(l)), 'the older quiet line gives way to the lift line');
});

test('the check warns when a take would need more lift than the room allows', () => {
	const lv = { roomDb: -76, voiceDb: -50, peakDb: -36, gap: 26, spoke: true, voiceLufs: -56 };
	const lift = P.lift(lv, { target: -19, mode: 'podcast', nrDb: 0, liftLimit: S.liftLimit });
	assert.equal(lift.limit, 30);
	assert.equal(lift.over, true);
	assert.equal(Math.round(lift.short), 7);
	const v = P.verdict(lv, lift, true);
	assert.ok(v.lines.some((l) => /more than the 30 dB/.test(l) && /about 7 dB quiet/.test(l) && /halving the distance/.test(l)), v.lines.join(' | '));
	assert.ok(v.tips.some((t) => /hand’s width/.test(t)), 'a phone gets the tip on holding it');
	assert.ok(!P.verdict(lv, lift, false).tips.some((t) => /hand’s width/.test(t)), 'a computer does not');
	const c = { gap: 26, level: 'quiet', liftOver: true };
	assert.equal(P.summary(c), 'Mic: room 26 dB below voice, too quiet: move closer');
	assert.equal(P.summary({ gap: 37, level: 'good', liftOver: false }), 'Mic: room 37 dB below voice');
});

test('a stereo preset names the file\'s own loudness, and a loud voice needs no lift', () => {
	const lv = { roomDb: -80, voiceDb: -20, peakDb: -6, gap: 60, spoke: true, voiceLufs: -18 };
	const lift = P.lift(lv, { target: -19 - 10 * Math.log10(2), shown: -16, mode: 'podcast', nrDb: 20, liftLimit: S.liftLimit });
	assert.equal(lift.target, -16);
	const v = P.verdict(lv, lift);
	assert.ok(v.lines.some((l) => /already loud enough for -16 LUFS/.test(l)), v.lines.join(' | '));
	assert.equal(P.lift({ spoke: false }, { liftLimit: S.liftLimit }), null);
	assert.equal(P.lift(lv, {}), null, 'nothing to say without the limit rule');
});

/* The ends trimmed off a recording before 1.9.0: a fixed 0.012 (-38 dBFS). */
function oldBounds(data, rate) {
	const n = data.length, win = Math.round(rate * 0.02), thresh = 0.012;
	let first = 0, lastS = n;
	for (let i = 0; i < n; i += win) {
		let loud = false;
		for (let j = i; j < Math.min(n, i + win); j++) { if (Math.abs(data[j]) > thresh) { loud = true; break; } }
		if (loud) { first = i; break; }
	}
	for (let i = n - win; i > first; i -= win) {
		let loud = false;
		for (let j = Math.max(0, i); j < Math.min(n, i + win); j++) { if (Math.abs(data[j]) > thresh) { loud = true; break; } }
		if (loud) { lastS = Math.min(n, i + win); break; }
	}
	return { first, last: lastS };
}
function withSilence(x, before, after) {
	const y = new Float32Array(x.length + Math.round(RATE * (before + after)));
	y.set(x, Math.round(RATE * before));
	return y;
}

test('a phone reading with a few loud words early keeps all of its quieter words (the take cut at 0:24)', () => {
	/* 70 s at a phone's level, its first 8 s read louder (peaks over -38 dBFS), the rest under. */
	const x = at(reading(70, 59), -54);
	for (let i = 0; i < RATE * 8; i++) { x[i] *= 4; }
	let late = 0;
	for (let i = RATE * 8; i < x.length; i++) { late = Math.max(late, Math.abs(x[i])); }
	assert.ok(late < 0.012, 'the later words peak under the old threshold: ' + late);
	const y = withSilence(x, 1, 2);
	const old = oldBounds(y, RATE);
	assert.ok(old.last / RATE < 12, 'before 1.9.0 the reading was cut short at ' + (old.last / RATE).toFixed(1) + ' s');
	const b = S.speechBounds(y, RATE);
	assert.ok(b.thresh < 0.012, 'a lower threshold for a quiet recording: ' + b.thresh);
	assert.ok(b.last / RATE > 70, 'kept to ' + (b.last / RATE).toFixed(1) + ' s of 73');
	assert.ok(Math.abs(b.first / RATE - 1) < 0.1, 'starts at the first word: ' + (b.first / RATE).toFixed(2));
});

test('a laptop reading is never trimmed more than before 1.9.0, and a loud one exactly as before', () => {
	[-44, -38, -30].forEach((l) => {
		const y = withSilence(at(reading(30, 57), l), 0.8, 1.5);
		const b = S.speechBounds(y, RATE), o = oldBounds(y, RATE);
		assert.ok(b.thresh <= 0.012);
		assert.ok(b.first <= o.first && b.last >= o.last, l + ' LUFS keeps at least what it kept');
		assert.ok(Math.abs(b.first / RATE - 0.8) < 0.1 && Math.abs(b.last / RATE - 30.8) < 0.5, l + ' LUFS still stops at the speech: ' + (b.first / RATE).toFixed(2) + ' to ' + (b.last / RATE).toFixed(2));
	});
	const loud = withSilence(at(reading(30, 57), -14), 0.8, 1.5);
	assert.equal(S.speechBounds(loud, RATE).thresh, 0.012, 'peaks far over -18 dBFS keep the old threshold');
	assert.equal(S.speechBounds(loud, RATE).last, oldBounds(loud, RATE).last);
});

test('trimming a quiet recording: one click does not set the level, and the room is never taken for speech', () => {
	const x = withSilence(at(reading(20, 40), -54), 1, 2);
	const plain = S.speechBounds(x, RATE);
	x[Math.round(RATE * 0.5)] = 0.9;
	const clicked = S.speechBounds(x, RATE);
	assert.ok(Math.abs(clicked.thresh / plain.thresh - 1) < 0.01, 'the click is in the top 1% and is left out');
	/* The room is 40 dB under the voice here; the last phrase ends 19.1 s into the reading, 20.1 s into the file. */
	assert.ok(plain.last / RATE > 19.9 && plain.last / RATE < 20.4, 'ends at the last word: ' + (plain.last / RATE).toFixed(2));
	const none = S.speechBounds(new Float32Array(RATE), RATE);
	assert.equal(none.first, 0, 'silence alone is kept whole');
	assert.equal(none.last, RATE);
});

test('the check\'s tips fit the device, and a room noise reduction will clear is not called noisy', () => {
	/* As on the iPhone in the closet: 31 dB, with Medium noise reduction (20 dB). */
	const lv = { roomDb: -82, voiceDb: -51, peakDb: -35, gap: 31, spoke: true, voiceLufs: -55 };
	const lift = P.lift(lv, { target: -19, mode: 'podcast', nrDb: 20, liftLimit: S.liftLimit });
	const ph = P.verdict(lv, lift, true);
	assert.ok(ph.lines[0].startsWith('The background sits 31 dB below your voice. Noise reduction lowers it by up to 20 dB more'), ph.lines[0]);
	assert.ok(!ph.tips.some((t) => /laptop/.test(t)), 'no laptop tips on a phone: ' + ph.tips.join(' | '));
	assert.ok(ph.tips.some((t) => /phone on something soft/.test(t)));
	const pc = P.verdict(lv, lift, false);
	assert.ok(pc.tips.some((t) => /laptop/.test(t)), 'a computer keeps its tips');
	/* Without noise reduction, or too noisy for it, the plain warning stays. */
	assert.ok(/^A noisy room/.test(P.verdict(lv, P.lift(lv, { target: -19, mode: 'podcast', nrDb: 0, liftLimit: S.liftLimit }), true).lines[0]));
	assert.ok(/^A noisy room/.test(P.verdict(Object.assign({}, lv, { gap: 20 }), lift, true).lines[0]));
	assert.ok(/^A noisy room/.test(P.verdict(lv, null, true).lines[0]), 'no lift, no softer line');
	/* A quiet room has no tips on either device. */
	assert.deepEqual(P.verdict(Object.assign({}, lv, { gap: 50 }), lift, true).tips.length, 0);
});
