'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { load } = require('./load');

const C = load('compare.js');

/*
 * The script as the studio hands it over: one entry per word with its key
 * (lowercase letters and digits), paragraph (block) and sentence. Paragraphs
 * are split by a blank line, sentences after . ! or ?
 */
function script(text) {
	const out = [];
	let sent = 0;
	text.split(/\n\s*\n/).forEach(function (para, block) {
		para.trim().split(/\s+/).forEach(function (w) {
			out.push({ text: w, key: C.clean(w), block: block, sent: sent });
			if (/[.!?]["”’)]*$/.test(w)) { sent++; }
		});
		if (out.length && !/[.!?]["”’)]*$/.test(out[out.length - 1].text)) { sent++; }
	});
	return out;
}

/*
 * What Whisper heard, as its word chunks: words 0.3 s long with 0.1 s
 * between them. A number in the list is extra silence (in seconds) before the
 * next word; { pace } changes the length of the words that follow.
 */
function heard(list) {
	const out = [];
	let t = 0.5;
	let len = 0.3;
	list.forEach(function (w) {
		if (typeof w === 'number') { t += w; return; }
		if (typeof w === 'object') { len = w.pace; return; }
		out.push({ text: ' ' + w, timestamp: [t, t + len] });
		t += len + 0.1;
	});
	return out;
}
function words(s) { return s.split(/\s+/).filter(Boolean); }

function compare(text, said, learned, cues, frames) {
	const sc = script(text);
	return { sc: sc, R: C.compareReading(sc, heard(said), frames || null, learned || null, cues || []) };
}
function statuses(R, sc) { return sc.map(function (w, i) { return w.text + ':' + R.words[i].status; }); }
function kinds(R) { return R.problems.map(function (p) { return p.kind; }); }

const OWLS = 'The owls call softly over the river. The moon is bright tonight.';

test('a reading as written is all correct, with nothing to retake', () => {
	const { R } = compare(OWLS, words('the owls call softly over the river the moon is bright tonight'));
	assert.ok(R.words.every(function (w) { return w.status === 'ok'; }));
	assert.equal(R.accuracy, 1);
	assert.deepEqual(R.problems, []);
	assert.equal(R.retake, 0);
	assert.equal(R.firstRead, 0);
	assert.equal(R.lastRead, 11);
	assert.equal(R.sentences.length, 2);
});

test('nothing heard, or an empty script, gives no comparison', () => {
	assert.equal(C.compareReading(script(OWLS), [], null, null, []), null);
	assert.equal(C.compareReading([], heard(['owls']), null, null, []), null);
});

test('a skipped word is flagged, timed between its neighbours and marks a retake', () => {
	const { sc, R } = compare(OWLS, words('the owls call over the river the moon is bright tonight'));
	assert.equal(R.words[3].status, 'omit');
	assert.ok(R.words[3].s >= R.words[2].e - 1e-9 && R.words[3].e <= R.words[4].s + 1e-9);
	assert.equal(R.counts.omit, 1);
	assert.ok(Math.abs(R.accuracy - 11 / 12) < 1e-9);
	const p = R.problems.find(function (x) { return x.kind === 'omit'; });
	assert.equal(p.label, 'Skipped');
	assert.equal(p.detail, '“softly”');
	assert.equal(R.retake, 1);
	assert.equal(sc[p.idx].text, 'softly');
});

test('skipped words in a row are one problem', () => {
	const { R } = compare(OWLS, words('the owls over the river the moon is bright tonight'));
	const omits = R.problems.filter(function (x) { return x.kind === 'omit'; });
	assert.equal(omits.length, 1);
	assert.equal(omits[0].detail, '“call softly”');
});

test('starting late and stopping early are not counted as skips', () => {
	const { sc, R } = compare(OWLS, words('softly over the river the moon is'));
	assert.deepEqual(statuses(R, sc).slice(0, 3), ['The:out', 'owls:out', 'call:out']);
	assert.equal(R.words[11].status, 'out');
	assert.equal(R.firstRead, 3);
	assert.equal(R.lastRead, 9);
	assert.equal(R.accuracy, 1);
	assert.equal(R.counts.omit, 0);
});

test('a misread word is a substitution, offered for teaching', () => {
	const { R } = compare(OWLS, words('the owls call softly over the road the moon is bright tonight'));
	assert.equal(R.words[6].status, 'sub');
	assert.equal(R.words[6].heard, 'road');
	const p = R.problems.find(function (x) { return x.kind === 'sub'; });
	assert.equal(p.label, 'Misread');
	assert.equal(p.detail, '“river.” heard as “road”'.replace('river.', 'river'));
	assert.equal(p.teach, true);
	assert.equal(p.sk, 'river');
	assert.equal(p.hk, 'road');
	assert.equal(R.retake, 1);
});

test('another form of the same word is worth a listen, and still counts as read', () => {
	const { R } = compare('The owls are calling softly over the river tonight.', words('the owls are called softly over the river tonight'));
	assert.equal(R.words[3].status, 'check');
	assert.equal(R.accuracy, 1);
	assert.deepEqual(kinds(R), ['check']);
	assert.equal(R.problems[0].label, 'Worth a listen');
	assert.equal(R.retake, 0);
});

test('fillers, repeats and added words are told apart', () => {
	const { R } = compare(OWLS, words('the owls um call softly softly over the river the moon is truly bright tonight'));
	const all = [];
	Object.keys(R.inserts).forEach(function (at) { R.inserts[at].forEach(function (x) { all.push(x.text + ':' + x.kind); }); });
	assert.deepEqual(all.sort(), ['softly:repeat', 'truly:added', 'um:filler']);
	assert.equal(R.counts.filler, 1);
	assert.equal(R.counts.repeat, 1);
	assert.equal(R.counts.added, 1);
	assert.equal(R.accuracy, 1);
	const added = R.problems.find(function (x) { return x.kind === 'added'; });
	assert.equal(added.teach, true);
	// A filler alone never asks for a retake; the repeat and the added word do (one sentence each).
	assert.equal(R.retake, 2);
});

test('a short common word that was added is not offered for teaching', () => {
	const { R } = compare(OWLS, words('the owls call softly over the river the moon is just bright tonight'));
	const added = R.problems.find(function (x) { return x.kind === 'added'; });
	assert.equal(added.detail, '“just”');
	assert.equal(added.teach, false);
});

test('words ignored by the reader are left out of the extras', () => {
	const { R } = compare(OWLS, words('the owls call softly over the river so the moon is bright tonight'), { ignore: { so: 1 } });
	assert.deepEqual(R.inserts, {});
	assert.deepEqual(R.problems, []);
});

test('numbers match their words either way round', () => {
	assert.equal(compare('I counted 7 owls on the hill tonight.', words('i counted seven owls on the hill tonight')).R.accuracy, 1);
	assert.equal(compare('I counted seven owls on the hill tonight.', words('i counted 7 owls on the hill tonight')).R.accuracy, 1);
	assert.equal(compare('It was 1999 when we first met here.', words('it was nineteen ninety nine when we first met here')).R.counts.omit, 0);
	assert.equal(C.numberWords(342), 'threehundredfortytwo');
});

test('words that sound alike are not called misreads', () => {
	const { R } = compare('We walked down to see the sea at night.', words('we walked down to sea the see at knight'));
	assert.equal(R.accuracy, 1);
	assert.deepEqual(R.problems, []);
});

test('contractions match their long forms in both directions', () => {
	assert.equal(compare('It’s a quiet night in the woods.', words('it is a quiet night in the woods')).R.accuracy, 1);
	const longForm = compare('It has been a quiet night in the woods.', words('it\'s been a quiet night in the woods'));
	assert.equal(longForm.R.accuracy, 1);
	assert.equal(longForm.R.words[0].status, 'ok');
	assert.equal(longForm.R.words[1].status, 'ok');
});

test('a hyphenated word heard in pieces is one word', () => {
	const { R } = compare('We carved the jack-o-lanterns by the fire.', words('we carved the jack o lanterns by the fire'));
	assert.equal(R.accuracy, 1);
	assert.deepEqual(R.inserts, {});
	assert.deepEqual(R.problems, []);
});

test('a word the reader taught is accepted, in pieces too', () => {
	const text = 'We waited for Siobhan under the old oak.';
	assert.equal(compare(text, words('we waited for shivawn under the old oak')).R.words[3].status, 'sub');
	const taught = { pairs: { siobhan: { shivawn: 1 } } };
	assert.equal(compare(text, words('we waited for shivawn under the old oak'), taught).R.accuracy, 1);
	// Heard in two pieces, the review offers the pieces joined (in their simplified form, so "sew" is "so"); teaching that fixes it.
	const first = compare(text, words('we waited for sew bawn under the old oak')).R;
	const offer = first.problems.find(function (x) { return x.teach; });
	assert.equal(offer.sk, 'siobhan');
	assert.equal(offer.hk, 'sobawn');
	const pieces = compare(text, words('we waited for sew bawn under the old oak'), { pairs: { siobhan: { sobawn: 1 } } });
	assert.equal(pieces.R.words[3].status, 'ok');
	assert.deepEqual(pieces.R.inserts, {});
});

test('words added just before a mishearing are folded into it', () => {
	const { R } = compare('We carved the lanterns by the fire.', words('we carved the jacquelyn terms by the fire'));
	const sub = R.problems.find(function (x) { return x.kind === 'sub'; });
	assert.equal(sub.detail, '“lanterns” heard as “jacquelyn terms”');
	assert.equal(sub.hk, 'jacquelynterms');
	assert.equal(R.counts.added, 0);
	assert.ok(!R.problems.some(function (x) { return x.kind === 'added'; }));
});

test('a long pause between sentences is flagged unless it was marked', () => {
	const said = words('the owls call softly over the river').concat([2.5], words('the moon is bright tonight'));
	const plain = compare(OWLS, said).R;
	const pause = plain.problems.find(function (x) { return x.kind === 'pause'; });
	assert.equal(pause.label, 'Long pause');
	assert.match(pause.detail, /^2\.6 seconds before “The”$/);
	assert.equal(plain.pauses.length, 1);
	assert.equal(plain.pauses[0].inSentence, false);
	assert.equal(plain.retake, 0);
	const cues = [];
	cues[6] = { i: 6, k: 'river', a: 'pause', e: false };
	const marked = compare(OWLS, said, null, cues).R;
	assert.equal(marked.pauses[0].cued, true);
	assert.ok(!marked.problems.some(function (x) { return x.kind === 'pause'; }));
});

test('a pause in the middle of a sentence makes it hesitant unless it was marked', () => {
	const said = words('the owls call').concat([1.3], words('softly over the river the moon is bright tonight'));
	const plain = compare(OWLS, said).R;
	assert.deepEqual(plain.sentences[0].flags, ['hesitant']);
	assert.ok(plain.problems.some(function (x) { return x.kind === 'hesitant'; }));
	assert.ok(!plain.problems.some(function (x) { return x.kind === 'pause'; }));
	const cues = [];
	cues[2] = { i: 2, k: 'call', a: 'breath', e: false };
	const marked = compare(OWLS, said, null, cues).R;
	assert.deepEqual(marked.sentences[0].flags, []);
	assert.deepEqual(marked.problems, []);
	// An emphasis mark is not a pause, so the pause is still flagged.
	const em = [];
	em[2] = { i: 2, k: 'call', a: '', e: true };
	assert.deepEqual(compare(OWLS, said, null, em).R.sentences[0].flags, ['hesitant']);
});

test('a sentence whose end was not read is unfinished', () => {
	const text = 'The owls call softly over the dark river tonight. The moon is bright above us all.';
	const { R } = compare(text, words('the owls call softly over the dark the moon is bright above us all'));
	assert.deepEqual(R.sentences[0].flags, ['unfinished']);
	const p = R.problems.find(function (x) { return x.kind === 'unfinished'; });
	assert.match(p.detail, /^the end of this sentence was not read: “The owls call softly over the dark…”$/);
});

test('a sentence read much faster than the others is rushed', () => {
	const calm = 'The owls call softly over the river every night. ';
	const text = calm + calm + calm + 'The moon climbs slowly over the hills and the dark old woods tonight.';
	const said = words(calm + calm + calm).concat([{ pace: 0.12 }], words('the moon climbs slowly over the hills and the dark old woods tonight'));
	const { R } = compare(text.replace(/\. /g, '.\n\n').replace(/\n\n$/, ''), said);
	assert.deepEqual(R.sentences[3].flags, ['rushed']);
	assert.deepEqual(R.sentences[0].flags, []);
	const p = R.problems.find(function (x) { return x.kind === 'rushed'; });
	assert.match(p.detail, new RegExp('^' + R.sentences[3].wpm + ' words a minute, against ' + R.sentWpm + ' for this take: '));
});

test('a flat or faded sentence is noticed from the sound', () => {
	const text = 'The owls call softly over the river every single night.';
	const said = words('the owls call softly over the river every single night');
	const step = 0.02;
	const n = 300;
	const db = new Float32Array(n);
	for (let i = 0; i < n; i++) { db[i] = -20; }
	// The sentence runs 0.5 to 4.4 s (frames 25 to 220); its last fifth, from frame 181, drops well below the rest.
	for (let i = 181; i < n; i++) { db[i] = -40; }
	const frames = { step: step, db: db, gate: -50, spread: function () { return 0.5; } };
	const { R } = compare(text, said, null, null, frames);
	assert.deepEqual(R.sentences[0].flags, ['flat', 'faded']);
	const moving = { step: step, db: new Float32Array(n).fill(-20), gate: -50, spread: function () { return 3; } };
	assert.deepEqual(compare(text, said, null, null, moving).R.sentences[0].flags, []);
});

test('a fade meant, or a gentle trail in verse, is not flagged; a deep fade in verse still is (1.11.0)', () => {
	const text = 'The owls call softly over the river every single night.';
	const said = words('the owls call softly over the river every single night');
	const n = 300;
	function frames(tail) {
		const db = new Float32Array(n).fill(-20);
		for (let i = 181; i < n; i++) { db[i] = tail; }
		return { step: 0.02, db: db, gate: -50, spread: function () { return 3; } };
	}
	const verse = { rushed: 169, slow: 83, fadeDb: 14 };
	const sc = script(text);
	// An 11 dB fade: flagged in prose, a trail in verse.
	assert.deepEqual(C.compareReading(sc, heard(said), frames(-31), null, []).sentences[0].flags, ['faded']);
	const trail = C.compareReading(sc, heard(said), frames(-31), null, [], verse).sentences[0];
	assert.deepEqual(trail.flags, []);
	assert.equal(trail.fadeMeant, 'kind');
	// A 20 dB fade is flagged even in verse, unless a word of the sentence has a Fade meant cue.
	assert.deepEqual(C.compareReading(sc, heard(said), frames(-40), null, [], verse).sentences[0].flags, ['faded']);
	const cues = [];
	cues[9] = { i: 9, k: 'night', a: '', e: false, f: true };
	const meant = C.compareReading(sc, heard(said), frames(-40), null, cues).sentences[0];
	assert.deepEqual(meant.flags, []);
	assert.equal(meant.fadeMeant, 'cue');
	assert.ok(!C.compareReading(sc, heard(said), frames(-40), null, cues).problems.some(function (x) { return x.kind === 'faded'; }));
});

test('a kind of reading moves the Rushed edge to its range (1.11.0)', () => {
	const slowLine = 'The owls call softly over the river every night. ';
	const text = (slowLine + slowLine + slowLine + 'The moon climbs slowly over the hills and the dark woods.').replace(/\. /g, '.\n\n');
	const said = [{ pace: 0.6 }].concat(words(slowLine + slowLine + slowLine), [{ pace: 0.22 }], words('the moon climbs slowly over the hills and the dark woods'));
	const sc = script(text);
	const plain = C.compareReading(sc, heard(said), null, null, []);
	assert.ok(plain.sentences[3].wpm > 170 && plain.sentences[3].wpm < 200, String(plain.sentences[3].wpm));
	assert.deepEqual(plain.sentences[3].flags, []);
	const verse = C.compareReading(sc, heard(said), null, null, [], { rushed: 169, slow: 83, fadeDb: 14 });
	assert.deepEqual(verse.sentences[3].flags, ['rushed']);
	assert.deepEqual(verse.sentences[0].flags, []);
});

test('the pause map finds every pause of a second or more, and where it falls (1.11.0)', () => {
	const text = 'The owls call softly over the river. The moon is bright tonight.\n\nThe stars come out one by one.';
	const said = words('the owls call').concat([0.95], words('softly over the river'), [1.4], words('the moon is bright tonight'), [2.2], words('the stars come out one by one'));
	const { R } = compare(text, said);
	assert.deepEqual(R.gaps.map(function (g) { return g.type; }), ['inside', 'sentence', 'break']);
	assert.ok(Math.abs(R.gaps[0].len - 1.05) < 1e-9);
	assert.equal(R.pauseMap.count, 3);
	assert.equal(R.pauseMap.inside, 1);
	assert.equal(R.pauseMap.sentence, 1);
	assert.equal(R.pauseMap['break'], 1);
	assert.ok(Math.abs(R.pauseMap.longest - 2.3) < 1e-9);
	// Only the 2.3 s pause is long enough for the Long pause list; the 1.05 s one inside a sentence is not hesitant.
	assert.equal(R.pauses.length, 1);
	assert.deepEqual(R.sentences[0].flags, []);
	// Short gaps are not in the map.
	assert.deepEqual(compare(OWLS, words('the owls call softly over the river the moon is bright tonight')).R.gaps, []);
});

test('a cued pause the reading ran past is noted, and not counted as a flag (1.11.0)', () => {
	const cues = [];
	cues[6] = { i: 6, k: 'river', a: 'pause', e: false };
	const ran = compare(OWLS, words('the owls call softly over the river the moon is bright tonight'), null, cues).R;
	assert.equal(ran.cueMissed.length, 1);
	assert.equal(ran.cueMissed[0].idx, 6);
	assert.equal(ran.cueMissed[0].a, 'pause');
	assert.deepEqual(ran.problems, []);
	const took = compare(OWLS, words('the owls call softly over the river').concat([1.2], words('the moon is bright tonight')), null, cues).R;
	assert.deepEqual(took.cueMissed, []);
	assert.equal(took.gaps[0].cued, true);
	assert.equal(took.pauseMap.cued, 1);
});

test('first words Whisper ran together are paired with the script, not left unread (1.11.1)', () => {
	const text = 'Hex the Oligarchy.\n\nThe Earth is crying out and her people are suffering.';
	const { sc, R } = compare(text, words('hexa oligarchy the earth is crying out and her people are suffering'));
	assert.equal(R.firstRead, 0);
	assert.deepEqual([R.words[0].status, R.words[1].status], ['sub', 'sub']);
	assert.ok(R.words[0].s !== null && R.words[1].e <= R.words[2].s + 1e-9);
	const mis = R.problems.filter(function (x) { return x.kind === 'sub'; });
	assert.equal(mis.length, 1);
	assert.equal(mis[0].detail, '“Hex the” heard as “hexa”');
	assert.equal(mis[0].teach, false);
	assert.equal(mis[0].idx, 0);
	assert.ok(!R.problems.some(function (x) { return x.kind === 'added' || x.kind === 'omit'; }));
	assert.equal(sc[0].text, 'Hex');
});

test('a first word heard on its own but differently is a misread, and one heard alike is read (1.11.1)', () => {
	const text = 'Brigid walks the hills. The owls call softly over the river.';
	const R = compare(text, words('bridget walks the hills the owls call softly over the river')).R;
	assert.equal(R.firstRead, 0);
	assert.equal(R.words[0].status, 'sub');
	assert.equal(R.words[0].heard, 'bridget');
	const R2 = compare('Samhains fire burns. The owls call softly over the river.', words('samhain fire burns the owls call softly over the river')).R;
	assert.equal(R2.words[0].status, 'check');
});

test('last words Whisper ran together are paired too (1.11.1)', () => {
	const text = 'The owls call softly over the river. So mote it be.';
	const R = compare(text, words('the owls call softly over the river so moat it be')).R;
	assert.equal(R.lastRead, 10);
	assert.ok(R.words.every(function (w) { return w.status !== 'out' && w.status !== 'omit'; }));
	const R2 = compare('The owls call softly over the river tonight. Hex it.', words('the owls call softly over the river tonight hexit')).R;
	assert.deepEqual([R2.words[8].status, R2.words[9].status], ['ok', 'ok']);
	assert.equal(R2.lastRead, 9);
	assert.ok(!R2.problems.some(function (x) { return x.kind === 'added'; }));
});

test('a stray phrase before the reading is not taken for its first words (1.11.1)', () => {
	const text = 'Hex the Oligarchy.\n\nThe Earth is crying out and her people are suffering.';
	const R = compare(text, words('thank you oligarchy the earth is crying out and her people are suffering')).R;
	assert.equal(R.words[0].status, 'out');
	assert.equal(R.words[1].status, 'out');
	assert.equal(R.firstRead, 2);
	// And words heard long before the reading starts are left alone, however alike.
	const far = compare(text, words('hexa').concat([4], words('oligarchy the earth is crying out and her people are suffering'))).R;
	assert.equal(far.words[0].status, 'out');
});

test('a short last or first word misheard on its own is a misread, not added and unread (1.36.1)', () => {
	const text = 'The owls call softly over the river. It is the end.';
	const { sc, R } = compare(text, words('the owls call softly over the river it is the and'));
	assert.equal(R.lastRead, sc.length - 1);
	assert.equal(R.words[sc.length - 1].status, 'sub');
	assert.deepEqual(kinds(R), ['sub']);
	assert.equal(R.problems[0].detail, '“end” heard as “and”');
	assert.equal(R.counts.added, 0);
	assert.equal(R.paragraphs[0].total, 11);
	// The first word, from the other side.
	const first = compare('Fox runs over the hill. The owls call softly over the river.', words('box runs over the hill the owls call softly over the river')).R;
	assert.equal(first.firstRead, 0);
	assert.equal(first.words[0].status, 'sub');
	assert.equal(first.words[0].heard, 'box');
	// Outside English too.
	C.setLang('es');
	try {
		const es = compare('El agua corre hacia el mar.', words('el agua corre hacia el más')).R;
		assert.equal(es.words[5].status, 'sub');
		assert.equal(es.counts.added, 0);
	} finally { C.setLang('en'); }
});

test('two stray words, a filler, or a word long after the end are still left as they were (1.36.1)', () => {
	const text = 'The owls call softly over the river. It is the end.';
	const ok = compare(text, words('the owls call softly over the river it is the end thank you')).R;
	assert.equal(ok.words[10].status, 'ok');
	assert.equal(ok.problems.length, 1);
	assert.equal(ok.problems[0].kind, 'added');
	const two = compare(text, words('the owls call softly over the river it is the and thanks')).R;
	assert.equal(two.words[10].status, 'out');
	const um = compare(text, words('the owls call softly over the river it is the um')).R;
	assert.equal(um.words[10].status, 'out');
	const late = compare(text, words('the owls call softly over the river it is the').concat([3], words('and'))).R;
	assert.equal(late.words[10].status, 'out');
});

test('paragraph scores and pace come out of the comparison', () => {
	const { R } = compare('The owls call softly.\n\nThe moon is bright tonight over us.', words('the owls call softly the moon bright tonight over us'));
	assert.equal(R.paragraphs[0].score, 1);
	assert.ok(Math.abs(R.paragraphs[1].score - 6 / 7) < 1e-9);
	// Ten words read in (0.5 .. 4.4 s): 10 / 3.9 s.
	assert.equal(R.wpm, Math.round(10 / ((R.end - R.start) / 60)));
	assert.equal(R.heardCount, 10);
});

test('problems come in the order they happen', () => {
	const { R } = compare(OWLS, words('the owls um call over the road the moon is bright tonight'));
	const times = R.problems.map(function (p) { return p.s; });
	assert.deepEqual(times, times.slice().sort(function (a, b) { return a - b; }));
	assert.deepEqual(kinds(R), ['filler', 'omit', 'sub']);
});

test('heardWords splits chunks into words and shares out their time', () => {
	const hw = C.heardWords([{ text: ' Hello, world!', timestamp: [1, 2] }, { text: ' 7', timestamp: [2, null] }]);
	assert.deepEqual(hw.map(function (h) { return h.text; }), ['Hello', 'world', '7']);
	assert.deepEqual(hw.map(function (h) { return h.nk; }), ['hello', 'world', 'seven']);
	assert.equal(hw[0].s, 1);
	assert.equal(hw[0].e, 1.5);
	assert.equal(hw[1].s, 1.5);
	assert.ok(Math.abs(hw[2].e - 2.3) < 1e-9);
});

test('the word helpers behave as the studio expects', () => {
	assert.equal(C.clean('Don’t!'), 'dont');
	assert.equal(C.clean('Café,'), 'café');
	assert.equal(C.similar('river', 'river'), 1);
	assert.equal(C.similar('owl', 'owls'), 0);
	assert.ok(C.similar('lantern', 'lanterns') > 0.8);
	assert.equal(C.STOP.because, true);
	assert.equal(C.norm('sea'), 'see');
	assert.equal(C.norm('12'), 'twelve');
	assert.equal(C.relation('calling', 'called'), 'check');
	assert.equal(C.relation('owl', 'cat'), 'diff');
});

/*
 * Numbers (1.29.1): Whisper writes numbers as digits even when the script
 * spells them out, and the other way round.
 */
test('a number heard as digits matches the words the script spells it with', () => {
	const { R } = compare('She walked twelve hundred steps on the fourteenth of March.', words('she walked 1200 steps on the 14th of march'));
	assert.deepEqual(R.problems, []);
	assert.equal(R.accuracy, 1);
});

test('a year, a hundred and a percentage heard as digits all match', () => {
	const { R } = compare('It was nineteen eighty four. A hundred owls came. Five percent stayed. The year nineteen oh five.', words('it was 1984 100 owls came 5% stayed the year 1905'));
	assert.deepEqual(R.problems, []);
	assert.equal(R.accuracy, 1);
});

test('digits in the script match the number read as words', () => {
	const { R } = compare('It was 1984 and 1200 owls came on the 14th.', words('it was nineteen eighty four and twelve hundred owls came on the fourteenth'));
	assert.deepEqual(R.problems, []);
	assert.equal(R.accuracy, 1);
});

test('a different number is still a misread', () => {
	const { R } = compare('She walked twelve hundred steps today.', words('she walked 1300 steps today'));
	assert.ok(kinds(R).includes('sub') || kinds(R).includes('omit'));
	assert.ok(R.accuracy < 1);
});

test('the spellings of a number', () => {
	assert.ok(C.numberSpellings('1200').includes('twelvehundred'));
	assert.ok(C.numberSpellings('1200').includes('onethousandtwohundred'));
	assert.deepEqual(C.numberSpellings('14th'), ['fourteenth']);
	assert.ok(C.numberSpellings('21st').includes('twentyfirst'));
	assert.ok(C.numberSpellings('1984').includes('nineteeneightyfour'));
	assert.ok(C.numberSpellings('1905').includes('nineteenohfive'));
	assert.ok(C.numberSpellings('130').includes('onehundredandthirty'));
	assert.deepEqual(C.numberSpellings('owl'), []);
	assert.deepEqual(C.numberSpellings('007'), []);
});

/*
 * A restarted opening (1.29.1): heard words that fill the gap between two
 * script words are not silence, so the title is not flagged Hesitant.
 */
test('a restart after the title is no pause inside the title', () => {
	const { R } = compare('A practice reading\n\nWelcome to your first practice reading. There is nothing to get right today.',
		words('a practice reading').concat([0.5], words('welcome to your practice reading'), [2.5], words('welcome to your first practice reading there is nothing to get right today')));
	assert.ok(!kinds(R).includes('hesitant'), JSON.stringify(R.problems));
	assert.ok(kinds(R).includes('pause'));
});

test('a real pause inside a sentence is still Hesitant', () => {
	const { R } = compare('The owls call softly over the river tonight.', words('the owls call softly').concat([1.6], words('over the river tonight')));
	assert.ok(kinds(R).includes('hesitant'));
});
