'use strict';
/* Voice polish (1.13.0): strengths, slow leveling, clicks between words, the EQ per setup, the preview and the energy note. */
const test = require('node:test');
const assert = require('node:assert/strict');
const { load } = require('./load');
const { hush, reading, rms } = require('./synth');

const S = load('sound.js', 'RRTSound');
const PL = load('polish.js', 'RRTPolish');
const I = S._internal;
const RATE = 44100;

/* A voice with harmonics, an S in each phrase, a pop at each start and a breath before each phrase. */
function voice(phrases, opts) {
	opts = opts || {};
	const gap = opts.gap || 1.9;
	const n = Math.round(RATE * (0.6 + phrases * gap));
	const x = hush(n, opts.hush || 0.002, 5);
	const noise = hush(n, 1, 11);
	const at = [];
	for (let p = 0; p < phrases; p++) {
		const a = Math.round(RATE * (0.6 + p * gap));
		const m = Math.round(RATE * 1.3);
		const lvl = (opts.level || 0.12) * (opts.levelOf ? opts.levelOf(p) : 1);
		let ph = 0;
		for (let i = 0; i < m && a + i < n; i++) {
			const env = Math.min(1, i / 1500, (m - i) / 1500);
			ph += 2 * Math.PI * (150 + 30 * Math.sin(i / m * 6)) / RATE;
			let v = 0;
			for (let h = 1; h < 8; h++) { v += Math.sin(ph * h) / h; }
			x[a + i] += lvl * env * v;
			if (i > m * 0.4 && i < m * 0.5) { x[a + i] += lvl * 1.2 * noise[a + i]; }
			if (i < RATE * 0.03) { x[a + i] += lvl * 3 * Math.sin(2 * Math.PI * 60 * i / RATE); }
		}
		const b = a - Math.round(RATE * 0.33);
		if (b > 0) { for (let i = 0; i < RATE * 0.3; i++) { x[b + i] += lvl * 0.15 * noise[b + i]; } }
		at.push({ a: a, b: a + m, breath: b });
	}
	return { data: x, at: at };
}
const OPTS = { mode: 'podcast', target: -19, ceilingTp: -1.8, slow: false };
function run(x, extra) { return S.process(x, RATE, Object.assign({}, OPTS, extra || {})); }
function same(a, b) {
	assert.equal(a.length, b.length);
	for (let i = 0; i < a.length; i++) { if (a[i] !== b[i]) { return false; } }
	return true;
}

test('a strength reads from a setting: switches from before 1.13.0 are Standard or Off', () => {
	assert.equal(S.strengthOf(true), 'standard');
	assert.equal(S.strengthOf(undefined), 'standard');
	assert.equal(S.strengthOf(false), 'off');
	assert.equal(S.strengthOf('off'), 'off');
	assert.equal(S.strengthOf('gentle'), 'gentle');
	assert.equal(S.strengthOf('strong'), 'strong');
	assert.equal(S.strengthOf('loud'), 'standard');
	assert.deepEqual(Array.from(S.LEVELS), ['off', 'gentle', 'standard', 'strong']);
});

test('Standard is exactly the sound before 1.13.0: true, Standard and nothing set give the same samples', () => {
	const v = voice(8).data;
	const a = run(v, { plosives: true, deess: true, breaths: true, compress: true });
	const b = run(v, { plosives: 'standard', deess: 'standard', breaths: 'standard', compress: 'standard' });
	const c = run(v, {});
	assert.ok(same(a.data, b.data), 'true and standard');
	assert.ok(same(a.data, c.data), 'true and nothing set');
	assert.equal(b.report.strengths.deess, 'standard');
});

test('Off leaves a step out, as the switch did', () => {
	const v = voice(6).data;
	const r = run(v, { plosives: 'off', deess: 'off', breaths: 'off', compress: 'off' });
	assert.equal(r.report.plosives, undefined);
	assert.equal(r.report.sibilants, undefined);
	assert.equal(r.report.breaths, undefined);
	assert.equal(r.report.compressMaxDb, undefined);
	const old = run(v, { plosives: false, deess: false, breaths: false, compress: false });
	assert.ok(same(r.data, old.data));
});

test('the de-esser turns S sounds down further at Strong than at Gentle, within each limit', () => {
	const v = voice(6, { level: 0.1 }).data;
	const map = I.speechMap(v, RATE);
	const g = I.deess(v, RATE, map, null, S.STRENGTH.deess.gentle);
	const st = I.deess(v, RATE, map, null, S.STRENGTH.deess.standard);
	const sg = I.deess(v, RATE, map, null, S.STRENGTH.deess.strong);
	assert.ok(g.maxCutDb <= 4.01, 'gentle at most 4 dB: ' + g.maxCutDb);
	assert.ok(st.maxCutDb <= 8.01, 'standard at most 8 dB');
	assert.ok(sg.maxCutDb <= 12.01, 'strong at most 12 dB');
	assert.ok(sg.maxCutDb > g.maxCutDb, 'strong cuts more than gentle');
});

test('breaths are lowered more at Strong than at Gentle, and kept in at every strength', () => {
	const vv = voice(6);
	const map = I.speechMap(vv.data, RATE);
	const levels = {};
	['gentle', 'standard', 'strong'].forEach((k) => {
		const r = I.breaths(vv.data, RATE, map, S.STRENGTH.breaths[k]);
		const b = vv.at[2].breath;
		levels[k] = rms(r.data, b + 3000, b + 9000) / rms(vv.data, b + 3000, b + 9000);
		assert.ok(r.count >= 1, k + ': breaths found');
	});
	assert.ok(Math.abs(20 * Math.log10(levels.gentle) - -3) < 0.3, 'gentle about -3 dB');
	assert.ok(Math.abs(20 * Math.log10(levels.standard) - -6) < 0.3, 'standard about -6 dB');
	assert.ok(Math.abs(20 * Math.log10(levels.strong) - -10) < 0.3, 'strong about -10 dB');
	assert.ok(levels.strong > 0.25, 'never cut');
});

test('compression works harder at Strong than at Gentle', () => {
	const v = voice(8, { levelOf: (p) => (p % 2 ? 2.5 : 1) }).data;
	const map = I.speechMap(v, RATE);
	const g = I.compress(v, RATE, map, S.STRENGTH.compress.gentle);
	const sg = I.compress(v, RATE, map, S.STRENGTH.compress.strong);
	assert.ok(sg.maxDb > g.maxDb + 1, 'strong ' + sg.maxDb + ' over gentle ' + g.maxDb);
});

/* A long reading at 16 kHz (fast enough for slow leveling tests): phrases every 2 s, the level set by levelAt(seconds). */
function longReading(minutes, levelAt) {
	const R2 = 16000, n = Math.round(R2 * minutes * 60), x = hush(n, 0.0005, 3);
	for (let t = 0.5; t < minutes * 60 - 2; t += 2) {
		const a = Math.round(t * R2), m = Math.round(1.4 * R2), lvl = 0.1 * Math.pow(10, levelAt(t) / 20);
		for (let i = 0; i < m; i++) { const env = Math.min(1, i / 400, (m - i) / 400); x[a + i] += lvl * env * Math.sin(2 * Math.PI * (160 + 20 * Math.sin(i / 900)) * i / R2); }
	}
	return { data: x, rate: R2 };
}
function phraseDb(x, rate, t) { return 20 * Math.log10(rms(x, Math.round((t + 0.3) * rate), Math.round((t + 1.1) * rate))); }

test('slow leveling evens out a voice that drops 6 dB over a reading, by at most 4 dB', () => {
	const L = longReading(5, (t) => (t < 150 ? 0 : -6));
	const map = I.speechMap(L.data, L.rate);
	const r = I.slowLevel(L.data, L.rate, map, 4);
	assert.ok(r.used);
	assert.ok(r.maxDb <= 4.0001, 'at most 4 dB: ' + r.maxDb);
	const before = phraseDb(L.data, L.rate, 30.5) - phraseDb(L.data, L.rate, 270.5);
	const after = phraseDb(r.data, L.rate, 30.5) - phraseDb(r.data, L.rate, 270.5);
	assert.ok(Math.abs(before - 6) < 0.3);
	assert.ok(after < 2.5, 'the drop is smaller: ' + after.toFixed(2) + ' dB');
	/* Within a phrase nothing moves: the gain changes over seconds. */
	const p0 = r.data[Math.round(30.6 * L.rate)] / L.data[Math.round(30.6 * L.rate)];
	const p1 = r.data[Math.round(31.6 * L.rate)] / L.data[Math.round(31.6 * L.rate)];
	assert.ok(Math.abs(20 * Math.log10(p0 / p1)) < 0.2);
});

test('slow leveling is 2 dB at most in verse and ritual, and leaves a steady or short reading alone', () => {
	assert.equal(S.slowMax('verse'), 2);
	assert.equal(S.slowMax('ritual'), 2);
	assert.equal(S.slowMax('prose'), 4);
	assert.equal(S.slowMax(''), 4);
	const L = longReading(5, (t) => (t < 150 ? 0 : -6));
	const r = I.slowLevel(L.data, L.rate, I.speechMap(L.data, L.rate), 2);
	assert.ok(r.maxDb <= 2.0001);
	const steady = longReading(4, () => 0);
	const rs = I.slowLevel(steady.data, steady.rate, I.speechMap(steady.data, steady.rate), 4);
	assert.ok(rs.maxDb < 0.5, 'steady: ' + rs.maxDb);
	const short = longReading(1.2, (t) => (t < 30 ? 0 : -6));
	const rh = I.slowLevel(short.data, short.rate, I.speechMap(short.data, short.rate), 4);
	assert.equal(rh.used, false);
	assert.equal(rh.short, true, 'a short reading is marked, so the read-out can say why');
	assert.equal(rh.data, short.data);
});

test('slow leveling is on in the chain only when asked, and reported', () => {
	const L = longReading(3, (t) => (t < 90 ? 0 : -5));
	const on = S.process(L.data, L.rate, { mode: 'podcast', target: -19, ceilingTp: -1.8, slow: true, slowMax: 4 });
	const off = S.process(L.data, L.rate, { mode: 'podcast', target: -19, ceilingTp: -1.8 });
	assert.ok(on.report.slowDb > 1, 'used: ' + on.report.slowDb);
	assert.equal(on.report.slowMaxDb, 4);
	assert.equal(off.report.slowDb, undefined);
});

/* Two words with a quiet gap between, a 3 ms click inside the gap and a 40 ms burst too. */
function gapWithClick() {
	const n = RATE * 2, x = hush(n, 0.001, 9);
	const word = (a, b) => { for (let i = a; i < b; i++) { x[i] += 0.2 * Math.sin(2 * Math.PI * 200 * i / RATE); } };
	word(0, Math.round(0.6 * RATE));
	word(Math.round(1.4 * RATE), n);
	const click = Math.round(0.9 * RATE);
	for (let i = 0; i < 0.003 * RATE; i++) { x[click + i] += (i % 2 ? -0.012 : 0.012); }
	/* A consonant's burst: short too, but nearly as loud as the words. */
	const loud = Math.round(1.3 * RATE);
	for (let i = 0; i < 0.004 * RATE; i++) { x[loud + i] += (i % 2 ? -0.12 : 0.12); }
	const burst = Math.round(1.15 * RATE);
	for (let i = 0; i < 0.04 * RATE; i++) { x[burst + i] += 0.05 * Math.sin(i * 1.3); }
	return { data: x, click: click, burst: burst, loud: loud, gap: [0.6, 1.5] };
}

test('a click between words is lowered by 12 dB, never cut; a longer sound in the gap is left alone', () => {
	const g = gapWithClick();
	const r = I.clicks(g.data, RATE, [g.gap]);
	assert.equal(r.count, 1);
	const before = rms(g.data, g.click, g.click + 130), after = rms(r.data, g.click, g.click + 130);
	const drop = 20 * Math.log10(before / after);
	assert.ok(drop > 9 && drop < 13, 'lowered about 12 dB: ' + drop.toFixed(1));
	assert.ok(after > 0, 'kept in');
	assert.ok(Math.abs(rms(r.data, g.burst, g.burst + 1700) - rms(g.data, g.burst, g.burst + 1700)) < 1e-9, 'the 40 ms sound is untouched');
	assert.ok(Math.abs(rms(r.data, g.loud, g.loud + 180) - rms(g.data, g.loud, g.loud + 180)) < 1e-9, 'a loud short burst (a consonant) is untouched');
	assert.ok(Math.abs(rms(r.data, 0, Math.round(0.6 * RATE)) - rms(g.data, 0, Math.round(0.6 * RATE))) < 1e-9, 'the words are untouched');
});

test('clicks are only looked for inside the gaps, away from the words\' edges', () => {
	const g = gapWithClick();
	assert.equal(I.clicks(g.data, RATE, []).count, 0);
	assert.equal(I.clicks(g.data, RATE, null).data, g.data);
	/* A gap ending 40 ms after the click: inside the 50 ms margin. */
	assert.equal(I.clicks(g.data, RATE, [[0.6, 0.94]]).count, 0);
	/* The chain counts them when it has gaps. */
	const p = S.process(g.data, RATE, Object.assign({}, OPTS, { gaps: [g.gap] }));
	assert.equal(p.report.clicks, 1);
	assert.equal(S.process(g.data, RATE, OPTS).report.clicks, undefined);
});

test('the voice is measured in six bands; an EQ is suggested from three takes, within 3 dB and centred', () => {
	const v = voice(10).data;
	const b = S.measureBands(v, RATE);
	assert.equal(b.length, 6);
	b.forEach((d) => assert.ok(d === null || isFinite(d)));
	assert.equal(S.measureBands(v.subarray(0, RATE * 3), RATE), null, 'too little speech');
	assert.equal(S.eqSuggest([b, b]), null, 'two takes are not enough');
	const eq = S.eqSuggest([b, b, b]);
	assert.equal(eq.length, 6);
	let sum = 0;
	eq.forEach((x, i) => {
		assert.equal(x.f, S.EQ.bands[i]);
		assert.ok(Math.abs(x.g) <= 3, 'within 3 dB');
		assert.equal(x.g * 2, Math.round(x.g * 2), 'half decibels');
		sum += x.g;
	});
	assert.ok(Math.abs(sum / 6) <= 0.5, 'centred');
	/* A voice already shaped like speech gets a flat EQ. */
	const flat = S.EQ.ref.map((d) => d - 80);
	S.eqSuggest([flat, flat, flat]).forEach((x) => assert.equal(x.g, 0));
	/* Only the first five takes count. */
	const tilted = S.EQ.ref.map((d, i) => d - 80 + i * 4);
	assert.equal(JSON.stringify(S.eqSuggest([flat, flat, flat, flat, flat, tilted])), JSON.stringify(S.eqSuggest([flat, flat, flat])));
});

test('the EQ moves the bands it names, and a flat EQ changes nothing', () => {
	const v = voice(10).data;
	assert.equal(I.eqApply(v, RATE, S.EQ.bands.map((f) => ({ f: f, g: 0 }))).used, false);
	const up = I.eqApply(v, RATE, [{ f: 4000, g: 3 }]);
	assert.ok(up.used);
	const b0 = S.measureBands(v, RATE), b1 = S.measureBands(up.data, RATE);
	assert.ok(b1[4] - b0[4] > 2 && b1[4] - b0[4] < 3.2, 'the 4 kHz band rises by up to 3 dB: ' + (b1[4] - b0[4]));
	assert.ok(Math.abs(b1[1] - b0[1]) < 0.3, 'the 500 Hz band stays');
	assert.ok(S.process(v, RATE, Object.assign({}, OPTS, { eq: [{ f: 1000, g: -2 }] })).report.eq);
	assert.equal(S.process(v, RATE, Object.assign({}, OPTS, { eq: [{ f: 1000, g: 0 }] })).report.eq, undefined);
});

test('Hear the polish: one sentence before and after, the same length and loudness', () => {
	const vv = voice(8);
	const a = vv.at[3].a / RATE - 0.1, b = vv.at[3].b / RATE + 0.2;
	const pv = S.preview(vv.data, RATE, Object.assign({}, OPTS, { deess: 'strong', slow: true }), a, b);
	assert.equal(pv.before.length, pv.after.length);
	assert.ok(Math.abs(pv.before.length - (b - a) * RATE) <= 2);
	assert.ok(Math.abs(S.lufs(pv.before, RATE) - S.lufs(pv.after, RATE)) < 1, 'same loudness');
	assert.equal(pv.report.slowDb, undefined, 'slow leveling is left out of one sentence');
	assert.equal(pv.report.strengths.deess, 'strong');
});

test('a setup is the device and its microphone', () => {
	const iphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/131.0 Mobile/15E148 Safari/604.1';
	const win = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36';
	const a = PL.setup(iphone, 5, 'iPhone Microphone');
	assert.equal(a.key, 'iphone|iphone microphone');
	assert.equal(a.label, 'iPhone, iPhone Microphone');
	const b = PL.setup(win, 0, 'Default - Microphone Array (Realtek(R) Audio)');
	assert.equal(b.key, 'computer|microphone array (realtek(r) audio)');
	assert.equal(PL.setup(win, 0, 'Microphone Array (Realtek(R) Audio)').key, b.key, 'the same microphone with or without Default');
	assert.equal(PL.setup(win, 0, 'Blue Yeti (046d:0ab1)').key, 'computer|blue yeti');
	assert.equal(PL.setup(win, 0, '').key, 'computer|built-in');
	assert.equal(PL.setup('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)', 5, '').device, 'iPad');
	assert.equal(PL.setup('Mozilla/5.0 (Linux; Android 14; Pixel 8) Mobile', 5, '').device, 'Android phone');
});

test('the gaps between words come from Whisper\'s words, the edges left out', () => {
	const chunks = [
		{ text: ' Leaves', timestamp: [0.5, 0.9] },
		{ text: ' fall', timestamp: [0.95, 1.2] },
		{ text: ' slowly.', timestamp: [1.6, 2.1] },
		{ text: ' ', timestamp: [2.2, 2.3] },
		{ text: ' Rain', timestamp: [3.0, 3.3] }
	];
	assert.equal(JSON.stringify(PL.gaps(chunks)), JSON.stringify([[1.2, 1.6], [2.1, 3.0]]));
	assert.equal(PL.gaps([]).length, 0);
	assert.equal(PL.gaps(null).length, 0);
});

test('the energy note: a voice that drops 6 dB after the first minute is noted, at most once a minute', () => {
	const E = PL.energy();
	const step = 0.016;
	let shown = [];
	function feed(seconds, level) {
		for (let t = 0; t < seconds; t += step) {
			const inWord = (t % 2) < 1.4;
			E.push(inWord ? level : 0.0005, step);
			if (E.note()) { shown.push(E.state().t); }
		}
	}
	feed(70, 0.05);
	assert.equal(shown.length, 0, 'steady: nothing');
	feed(40, 0.05 * Math.pow(10, -6 / 20));
	assert.equal(shown.length, 1, 'once: ' + shown);
	const st = E.state();
	assert.ok(st.drop > 5 && st.drop < 7, 'drop ' + st.drop);
	feed(30, 0.025);
	assert.equal(shown.length, 1, 'not again within a minute');
	feed(40, 0.025);
	assert.equal(shown.length, 2, 'again after a minute');
});

test('the energy note keeps quiet for a small drop, before a minute, in a drill and on a ritual post', () => {
	const E = PL.energy();
	for (let t = 0; t < 130; t += 0.016) { E.push((t % 2) < 1.4 ? (t < 60 ? 0.05 : 0.05 * Math.pow(10, -3 / 20)) : 0.0005, 0.016); assert.equal(E.note(), false); }
	const F = PL.energy();
	for (let t = 0; t < 55; t += 0.016) { F.push((t % 2) < 1.4 ? (t < 20 ? 0.05 : 0.01) : 0.0005, 0.016); assert.equal(F.note(), false); }
	assert.equal(PL.energyWanted('prose', ''), true);
	assert.equal(PL.energyWanted('', ''), true);
	assert.equal(PL.energyWanted('ritual', ''), false);
	assert.equal(PL.energyWanted('verse', 'opening'), false);
});

test('a take\'s polish: the Settings choice with this take\'s one-off over it', () => {
	const sound = { plosives: 'standard', deess: 'gentle', breaths: true, compress: false, slow: true, eq: false, clicks: false };
	const eq = [{ f: 250, g: 1 }];
	const a = PL.options(sound, {}, eq, [[1, 2]], 'prose');
	assert.equal(a.plosives, 'standard');
	assert.equal(a.deess, 'gentle');
	assert.equal(a.breaths, 'standard');
	assert.equal(a.compress, 'off');
	assert.equal(a.slow, true);
	assert.equal(a.slowMax, 4);
	assert.equal(a.eq, null, 'EQ off in Settings');
	assert.equal(a.gaps, null, 'clicks off in Settings');
	const b = PL.options(sound, { deess: 'strong', eq: true, clicks: true, slow: false }, eq, [[1, 2]], 'verse');
	assert.equal(b.deess, 'strong');
	assert.equal(b.eq, eq);
	assert.equal(JSON.stringify(b.gaps), '[[1,2]]');
	assert.equal(b.slow, false);
	assert.equal(b.slowMax, 2);
	assert.equal(PL.options({ eq: true }, {}, null, null, '').eq, null, 'no EQ yet for this setup');
	assert.equal(PL.needsWords({ clicks: true }, {}), true);
	assert.equal(PL.needsWords({ clicks: true }, { clicks: false }), false);
	assert.equal(PL.needsWords({}, {}), false);
});
