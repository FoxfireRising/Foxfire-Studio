'use strict';
/*
 * Reading languages (1.33.0): the review's word rules in other languages,
 * English left exactly as it was, and the Whisper worker hearing a reading
 * in its own language without translating it.
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { pathToFileURL } = require('url');
const { load } = require('./load');

const C = load('compare.js');
const W = load('whisper.js');
const K = load('clip.js', 'RRTClip');

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
function heard(list) {
	const out = [];
	let t = 0.5;
	list.forEach(function (w) {
		out.push({ text: ' ' + w, timestamp: [t, t + 0.3] });
		t += 0.4;
	});
	return out;
}
function words(s) { return s.split(/\s+/).filter(Boolean); }
function compare(text, said) {
	const sc = script(text);
	return { sc: sc, R: C.compareReading(sc, heard(said), null, null, []) };
}
function inLang(code, tag, fn) {
	C.setLang({ code: code, tag: tag || code });
	try { return fn(); } finally { C.setLang({ code: 'en', tag: 'en' }); }
}

test('English is the language until the studio says otherwise, and its rules are unchanged', () => {
	assert.equal(C.lang(), 'en');
	assert.equal(C.clean('Don’t-stop, 1984!'), 'dontstop1984');
	assert.equal(C.clean('Привет'), '', 'English keeps the Latin-only rule');
	assert.equal(C.norm('12'), 'twelve');
	assert.equal(C.norm('two'), 'to');
	assert.deepEqual(C.numberSpellings('14th'), ['fourteenth']);
	assert.equal(C.relation('walked', 'walking'), 'check');
	assert.ok(C.fillers().um && C.fillers().erm);
	assert.equal(C.fillers(), C.FILLERS);
});

test('Greek and Russian words keep their letters, lowercased, without punctuation', () => {
	inLang('ru', 'ru', () => {
		assert.equal(C.clean('«Привет,»'), 'привет');
		assert.equal(C.clean('ЁЛКА'), 'ёлка');
		const h = C.heardWords([{ text: ' Привет, мир.', timestamp: [0, 1] }]);
		assert.deepEqual(h.map(function (x) { return x.text; }), ['Привет', 'мир']);
		assert.deepEqual(h.map(function (x) { return x.key; }), ['привет', 'мир']);
	});
	inLang('el', 'el', () => {
		assert.equal(C.clean('Καλημέρα;'), 'καλημέρα');
	});
});

test('Turkish lowercases its own way: dotted and dotless i', () => {
	inLang('tr', 'tr', () => {
		assert.equal(C.clean('İstanbul'), 'istanbul');
		assert.equal(C.clean('IRMAK'), 'ırmak');
	});
});

test('a word written with combining accents matches the same word heard precomposed', () => {
	inLang('es', 'es', () => {
		assert.equal(C.clean('canción'), C.clean('canción'));
		assert.equal(C.clean('Devanagari'), 'devanagari');
	});
	inLang('hi', 'hi', () => {
		assert.equal(C.clean('नमस्ते।'), 'नमस्ते', 'the vowel signs stay, the danda goes');
	});
});

test('a Spanish reading read as written is all correct', () => {
	inLang('es', 'es', () => {
		const { R } = compare('El búho canta sobre el río. La luna brilla esta noche.', words('el búho canta sobre el río la luna brilla esta noche'));
		assert.ok(R.words.every(function (w) { return w.status === 'ok'; }));
		assert.equal(R.accuracy, 1);
	});
});

test('a short word heard without its accent is still read, even as the last word', () => {
	inLang('es', 'es', () => {
		const { sc, R } = compare('María cerró los ojos y escuchó el río.', words('maría cerró los ojos y escuchó el rio'));
		const last = R.words[sc.length - 1];
		assert.equal(last.status, 'ok', 'río is read, not left as not reached');
		assert.equal(Object.keys(R.inserts).length, 0, 'nothing added');
		assert.equal(R.accuracy, 1);
	});
	inLang('el', 'el', () => {
		const { sc, R } = compare('Η νύχτα είναι ήσυχη και ο ποταμός τρέχει.', words('η νυχτα ειναι ησυχη και ο ποταμος τρεχει'));
		assert.ok(R.words.every(function (w) { return w.status === 'ok'; }), 'Greek accents too');
		assert.equal(sc.length, R.words.length);
	});
	inLang('hi', 'hi', () => {
		assert.equal(C.relation('कम', 'कमी'), 'diff', 'a vowel sign is a letter, never an accent');
	});
	assert.equal(C.relation('rio', 'río'), 'diff', 'English keeps its rules: short words need the same letters');
});

test('a Russian reading with a skipped word flags it', () => {
	inLang('ru', 'ru', () => {
		const { sc, R } = compare('Совы тихо кричат над рекой. Луна сегодня яркая.', words('совы кричат над рекой луна сегодня яркая'));
		const p = R.problems.find(function (x) { return x.kind === 'omit'; });
		assert.ok(p, 'the skip is found');
		assert.equal(sc[p.idx].text, 'тихо');
	});
});

test('fillers are known per language, and an English filler is a word elsewhere', () => {
	inLang('fr', 'fr', () => {
		assert.ok(C.fillers().euh);
		assert.ok(C.fillers().hmm, 'the shared hums');
		assert.ok(!C.fillers().um);
		const { R } = compare('Le hibou chante ce soir.', words('le hibou euh chante ce soir'));
		assert.deepEqual(R.problems.map(function (x) { return x.kind; }).filter(function (k) { return k === 'filler'; }), ['filler']);
	});
	inLang('de', 'de', () => {
		assert.ok(C.fillers()['äh']);
		assert.ok(C.fillers()['ähm']);
	});
	inLang('ru', 'ru', () => {
		assert.ok(C.fillers()['ээ']);
	});
	inLang('fi', 'fi', () => {
		assert.ok(C.fillers()['öö']);
	});
	inLang('sw', 'sw', () => {
		assert.deepEqual(Object.keys(C.fillers()).sort(), ['hm', 'hmm', 'mhm', 'mm', 'mmm'], 'a language without a list has the shared hums only');
	});
});

test('numbers outside English: digits for words is Check, never a miss, and no English number words are used', () => {
	inLang('es', 'es', () => {
		assert.deepEqual(C.numberSpellings('1200'), []);
		assert.equal(C.norm('12'), '12');
		assert.equal(C.relation('12', 'doce'), 'check');
		assert.equal(C.relation('doce', '12'), 'check');
		assert.equal(C.relation('12', '12'), 'same');
		const { R } = compare('Caminó doce pasos hasta el mercado.', words('caminó 12 pasos hasta el mercado'));
		assert.equal(R.words[1].status, 'check');
		assert.equal(R.counts.omit, 0);
	});
});

test('outside English, English sound-alikes, contractions and endings are left out', () => {
	inLang('es', 'es', () => {
		assert.equal(C.norm('two'), 'two');
		assert.equal(C.stem('cantando'), 'cantando');
		assert.equal(C.relation('caminaba', 'caminaron'), 'check', 'the same start for most of the word is worth a look');
		assert.equal(C.relation('luna', 'sol'), 'diff');
	});
});

test('the Whisper worker hears a reading in its language, as a transcription', async () => {
	const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'foxfire-lang-'));
	const libFile = path.join(dir, 'lib.mjs');
	fs.writeFileSync(libFile, [
		'export const env = { backends: { onnx: { wasm: {} } } };',
		'export const calls = [];',
		'export async function pipeline(task, model, opts) {',
		'  calls.push({ task, model, revision: opts.revision });',
		'  return async function (audio, o) { calls.push({ o }); return { chunks: [{ text: " hola", timestamp: [0, 0.4] }], text: " hola" }; };',
		'}'
	].join('\n'));
	async function run(cfg) {
		const file = path.join(dir, 'w' + Math.random().toString(36).slice(2) + '.mjs');
		fs.writeFileSync(file, 'const self = globalThis.__self;\n' + W.source + '\nexport {};\n');
		const posted = [];
		globalThis.__self = { postMessage: function (m) { posted.push(m); } };
		await import(pathToFileURL(file).href);
		const self = globalThis.__self;
		const lib = await import(pathToFileURL(libFile).href);
		lib.calls.length = 0;
		await self.onmessage({ data: { cfg: Object.assign({ lib: pathToFileURL(libFile).href, cdn: 'nowhere:', site: 'this site', wasm: '', host: '' }, cfg), audio: new Float32Array(16000) } });
		return { posted: posted, calls: lib.calls.slice() };
	}
	const es = await run({ model: 'multi-base', fallback: '', rev: 'abc123', language: 'es' });
	assert.equal(es.posted[es.posted.length - 1].type, 'done');
	const loads = es.calls.filter(function (c) { return c.task; });
	assert.deepEqual(loads.map(function (c) { return c.model; }), ['multi-base'], 'no unpinned fallback is tried');
	assert.equal(loads[0].revision, 'abc123', 'the multilingual model is pinned on Hugging Face');
	assert.deepEqual(es.calls.filter(function (c) { return c.o; })[0].o, { return_timestamps: 'word', language: 'es', task: 'transcribe' });
	const en = await run({ model: 'base.en', fallback: 'base-fallback', rev: '' });
	assert.deepEqual(en.calls.filter(function (c) { return c.o; })[0].o, { return_timestamps: 'word' }, 'English is asked exactly as before');
	assert.equal(en.calls.filter(function (c) { return c.task; })[0].revision, undefined);
});

test('the clip maker swaps in the multilingual model for a reading in another language', () => {
	const boot = { model: 'base.en', host: '/m/', fallback: 'fb', multi: { model: 'multi-base', host: '', rev: 'r1' } };
	const en = K.whisperCfg(boot, { userAgent: 'x' }, { touch: false, memory: 8 });
	assert.equal(en.model, 'base.en');
	assert.equal(en.fallback, 'fb');
	const es = K.whisperCfg(Object.assign({ language: 'es' }, boot), { userAgent: 'x' }, { touch: false, memory: 8 });
	assert.equal(es.model, 'multi-base');
	assert.equal(es.host, '');
	assert.equal(es.fallback, '');
	assert.equal(es.rev, 'r1');
	assert.equal(es.language, 'es');
});

test('the clip maker compares words of any script once its reading is in another language', () => {
	K.lang = 'en';
	assert.equal(K.norm('Привет'), '');
	assert.equal(K.norm("Don't"), "don't");
	K.lang = 'ru';
	assert.equal(K.norm('Привет,'), 'привет');
	K.lang = 'en';
});
