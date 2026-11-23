'use strict';
/*
 * Runs the Whisper worker's own code in Node: the worker source is written
 * to a module file with a stand-in for the worker's self, and transformers.js
 * is a stand-in module that records what it was asked for.
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { pathToFileURL } = require('url');
const { load } = require('./load');

const W = load('whisper.js');
const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'foxfire-whisper-'));
let made = 0;

/* A stand-in transformers.js. failModels: models whose pipeline throws. */
function fakeLib(name, failModels) {
	const file = path.join(dir, name + '.mjs');
	fs.writeFileSync(file, [
		'export const env = { backends: { onnx: { wasm: {} } } };',
		'export const calls = [];',
		'const FAIL = ' + JSON.stringify(failModels || []) + ';',
		'export async function pipeline(task, model, opts) {',
		'  calls.push({ task, model, host: env.remoteHost, tpl: env.remotePathTemplate, dtype: opts.dtype, device: opts.device, revision: opts.revision });',
		'  opts.progress_callback({ status: "progress", file: model + "/x.onnx", loaded: 5, total: 10 });',
		'  if (FAIL.indexOf(model) !== -1) { throw new Error("no " + model); }',
		'  return async function (audio, o) { calls.push({ heard: audio.length, o }); return { chunks: [{ text: " owls", timestamp: [0, 0.4] }], text: " owls" }; };',
		'}'
	].join('\n'));
	return pathToFileURL(file).href;
}

/* The worker, as a fresh module with its own self; returns { send, env, calls }. */
async function worker(libUrl) {
	const file = path.join(dir, 'worker' + (made++) + '.mjs');
	fs.writeFileSync(file, 'const self = globalThis.__self;\n' + W.source + '\nexport {};\n');
	const posted = [];
	globalThis.__self = { postMessage: function (m) { posted.push(m); } };
	await import(pathToFileURL(file).href);
	const self = globalThis.__self;
	const lib = libUrl ? await import(libUrl) : null;
	return {
		posted: posted,
		lib: lib,
		send: async function (cfg, audio) {
			const from = posted.length;
			await self.onmessage({ data: { cfg: cfg, audio: audio || new Float32Array(16000) } });
			return posted.slice(from);
		}
	};
}

test('the worker source is valid module code', () => {
	assert.ok(W.source.length > 500);
	assert.match(W.source, /self\.onmessage = async function/);
	assert.equal(typeof W.start, 'function');
});

test('from the site: its own library, model files and wasm', async () => {
	const lib = fakeLib('site');
	const w = await worker(lib);
	const out = await w.send({ lib: lib, cdn: 'nowhere:', site: 'this site', wasm: '/w/', host: '/models/', model: 'base.en', fallback: 'base-fallback' });
	/* 1.30.0: each piece is reported as it is heard, so the studio can time the model. */
	assert.deepEqual(out.map(function (m) { return m.type; }), ['load', 'working', 'piece', 'done']);
	assert.deepEqual(out[0].p, { status: 'progress', file: 'base.en/x.onnx', loaded: 5, total: 10 });
	assert.deepEqual(out[1].from, { code: 'this site', model: 'this site', gpu: false });
	assert.deepEqual([out[2].i, out[2].n, out[2].a, out[2].b], [0, 1, 0, 1]);
	assert.equal(out[3].chunks[0].text, ' owls');
	assert.equal(w.lib.env.backends.onnx.wasm.wasmPaths.wasm, '/w/ort-wasm-simd-threaded.asyncify.wasm');
	assert.equal(w.lib.env.allowLocalModels, false);
	assert.equal(w.lib.calls[0].host, '/models/');
	assert.equal(w.lib.calls[0].tpl, '{model}/');
	assert.equal(w.lib.calls[0].dtype, 'q8');
	/* Since 1.9.1 the worker cuts long audio itself, so the library is asked for one window at a time. */
	assert.deepEqual(w.lib.calls[1].o, { return_timestamps: 'word' });
});

test('a hand-over (1.30.0): fromS skips the pieces already heard, with times in the whole take', async () => {
	const lib = fakeLib('handover');
	const w = await worker(lib);
	const audio = new Float32Array(16000 * 70);
	const all = await w.send({ lib: lib, cdn: 'nowhere:', site: 'this site', wasm: '/w/', host: '/models/', model: 'base.en', fallback: 'x' }, audio);
	const pieces = all.filter(function (m) { return m.type === 'piece'; });
	assert.ok(pieces.length >= 3, 'a 70 s take is heard in three pieces or more');
	const out = await w.send({ lib: lib, cdn: 'nowhere:', site: 'this site', wasm: '/w/', host: '/models/', model: 'base.en', fallback: 'x', fromS: pieces[1].b }, new Float32Array(16000 * 70));
	const later = out.filter(function (m) { return m.type === 'piece'; });
	assert.equal(later.length, pieces.length - 2);
	assert.equal(later[0].i, 2);
	assert.equal(later[0].a, pieces[2].a);
});

test('a phone gets one thread and the plain wasm build', async () => {
	const lib = fakeLib('phone');
	const w = await worker(lib);
	await w.send({ lib: lib, cdn: 'nowhere:', site: 'this site', wasm: '/w/', host: '/models/', model: 'base.en', fallback: 'x', lowMem: true });
	assert.equal(w.lib.env.backends.onnx.wasm.numThreads, 1);
	assert.equal(w.lib.env.backends.onnx.wasm.wasmPaths.wasm, '/w/ort-wasm-simd-threaded.wasm');
});

test('without the site\'s library, jsDelivr is used and its own wasm kept', async () => {
	const cdn = fakeLib('cdn');
	const w = await worker(cdn);
	const out = await w.send({ lib: '', cdn: cdn, site: 'this site', wasm: '/w/', host: '', model: 'base.en', fallback: 'x' });
	const working = out.find(function (m) { return m.type === 'working'; });
	assert.deepEqual(working.from, { code: 'jsDelivr', model: 'Hugging Face', gpu: false });
	assert.equal(w.lib.env.backends.onnx.wasm.wasmPaths, undefined);
	assert.equal(w.lib.calls[0].host, 'https://huggingface.co/');
});

test('a model that will not load falls back to the next place, then the fallback model', async () => {
	const lib = fakeLib('fallback', ['small.en']);
	const w = await worker(lib);
	const out = await w.send({ lib: lib, cdn: 'nowhere:', site: 'this site', wasm: '', host: '/models/', model: 'small.en', fallback: 'small-fallback' });
	assert.deepEqual(w.lib.calls.filter(function (c) { return c.task; }).map(function (c) { return c.model + '@' + c.host; }), ['small.en@/models/', 'small.en@https://huggingface.co/', 'small-fallback@https://huggingface.co/']);
	assert.equal(out[out.length - 1].type, 'done');
});

test('the graphics chip (1.17.0): the half-precision encoder and 4-bit half-precision decoder on WebGPU, from the site', async () => {
	const lib = fakeLib('gpu-site');
	const w = await worker(lib);
	const out = await w.send({ lib: lib, cdn: 'nowhere:', site: 'this site', wasm: '/w/', host: '/models/', model: 'small.en', fallback: '', gpu: true, rev: 'abc123' });
	const load = w.lib.calls.filter(function (c) { return c.task; });
	assert.equal(load.length, 1);
	assert.deepEqual(load[0].dtype, { encoder_model: 'fp16', decoder_model_merged: 'q4f16' });
	assert.equal(load[0].device, 'webgpu');
	assert.equal(load[0].host, '/models/');
	assert.equal(w.lib.env.backends.onnx.wasm.wasmPaths.wasm, '/w/ort-wasm-simd-threaded.asyncify.wasm');
	assert.deepEqual(out.find(function (m) { return m.type === 'working'; }).from, { code: 'this site', model: 'this site', gpu: true });
	assert.equal(out[out.length - 1].type, 'done');
});

test('on the graphics chip, Hugging Face is asked for the pinned revision, and there is no fallback model', async () => {
	const lib = fakeLib('gpu-hf', ['small.en']);
	const w = await worker(lib);
	const out = await w.send({ lib: lib, cdn: 'nowhere:', site: 's', wasm: '', host: '', model: 'small.en', fallback: 'small-fallback', gpu: true, rev: 'abc123' });
	const load = w.lib.calls.filter(function (c) { return c.task; });
	assert.deepEqual(load.map(function (c) { return c.model + '@' + c.host + '@' + c.revision; }), ['small.en@https://huggingface.co/@abc123']);
	assert.deepEqual(out[out.length - 1], { type: 'error', message: 'no small.en' });
});

test('on the processor, the files are q8 on wasm and no revision is asked for', async () => {
	const lib = fakeLib('cpu-q8');
	const w = await worker(lib);
	const out = await w.send({ lib: lib, cdn: 'nowhere:', site: 's', wasm: '', host: '', model: 'base.en', fallback: 'x', rev: 'abc123' });
	const load = w.lib.calls.filter(function (c) { return c.task; })[0];
	assert.equal(load.dtype, 'q8');
	assert.equal(load.device, 'wasm');
	assert.equal(load.revision, undefined);
	assert.equal(out.find(function (m) { return m.type === 'working'; }).from.gpu, false);
});

test('when nothing loads, the worker reports the error', async () => {
	const lib = fakeLib('broken', ['a', 'b']);
	const w = await worker(lib);
	const out = await w.send({ lib: lib, cdn: 'nowhere:', site: 's', host: '', model: 'a', fallback: 'b' });
	assert.deepEqual(out[out.length - 1], { type: 'error', message: 'no b' });
});

test('the model loads once and is reused for the next message', async () => {
	const lib = fakeLib('reuse');
	const w = await worker(lib);
	const cfg = { lib: lib, cdn: 'nowhere:', site: 's', host: '', model: 'base.en', fallback: 'x' };
	await w.send(cfg);
	await w.send(cfg);
	assert.equal(w.lib.calls.filter(function (c) { return c.task; }).length, 1);
	assert.equal(w.lib.calls.filter(function (c) { return c.heard; }).length, 2);
});

/* A made-up reading at 16 kHz: speech-like noise with a pause every 6 s (0.6 s of near silence). */
function speech(secs, pauseEvery, quietAt) {
	const x = new Float32Array(Math.round(16000 * secs));
	let seed = 3;
	for (let i = 0; i < x.length; i++) {
		seed = (seed * 16807) % 2147483647;
		const t = i / 16000;
		const inPause = (t % pauseEvery) > pauseEvery - 0.6 || (quietAt ? Math.abs(t - quietAt) < 0.4 : false);
		x[i] = (inPause ? 0.0005 : 0.05) * (seed / 2147483647 - 0.5);
	}
	return x;
}

test('pieces (1.9.1): up to 28 s is one piece; a longer recording is cut in its pauses, with no overlap and nothing left out', () => {
	assert.deepEqual(W.pieces(new Float32Array(16000 * 28), 16000), [[0, 16000 * 28]]);
	const x = speech(72.4, 6);
	const p = W.pieces(x, 16000);
	assert.ok(p.length >= 3, p.length + ' pieces');
	assert.equal(p[0][0], 0);
	assert.equal(p[p.length - 1][1], x.length);
	for (let i = 0; i < p.length; i++) {
		const len = (p[i][1] - p[i][0]) / 16000;
		assert.ok(len <= 28.001, 'piece ' + i + ' is ' + len + ' s');
		if (i < p.length - 1) {
			assert.ok(len >= 15, 'piece ' + i + ' is ' + len + ' s');
			assert.equal(p[i][1], p[i + 1][0], 'pieces meet with no gap or overlap');
			/* Each cut falls in a pause: the 6 s pauses run from 5.4 to 6.0 s past each multiple of 6. */
			const t = p[i][1] / 16000;
			assert.ok(t % 6 > 5.35 || t % 6 < 0.05, 'cut at ' + t.toFixed(2) + ' s, inside a pause');
		}
	}
	assert.ok((p[p.length - 1][1] - p[p.length - 1][0]) / 16000 >= 5, 'the last piece is not a sliver');
	/* A single quieter moment is chosen over louder ones. */
	const y = speech(40, 100, 21);
	const q = W.pieces(y, 16000);
	assert.equal(q.length, 2);
	assert.ok(Math.abs(q[0][1] / 16000 - 21) < 0.3, 'cut at the quiet moment: ' + (q[0][1] / 16000).toFixed(2));
});

test('a long recording is heard piece by piece, and the words come back in order with their times in the whole recording', async () => {
	const file = path.join(dir, 'pieces.mjs');
	fs.writeFileSync(file, [
		'export const env = { backends: { onnx: { wasm: {} } } };',
		'export const calls = [];',
		'export async function pipeline() {',
		'  return async function (audio, o) {',
		'    calls.push({ len: audio.length / 16000, off: audio.rrtOffset, total: audio.rrtTotal, last: audio.rrtLast, o });',
		'    const n = calls.length;',
		'    return { chunks: [{ text: " w" + n + "a", timestamp: [0.5, 1] }, { text: " w" + n + "b", timestamp: [2, null] }], text: " w" + n + "a w" + n + "b" };',
		'  };',
		'}'
	].join('\n'));
	const lib = pathToFileURL(file).href;
	const w = await worker(lib);
	const x = speech(72.4, 6);
	const p = W.pieces(x, 16000);
	const out = await w.send({ lib: lib, cdn: 'nowhere:', site: 's', host: '', model: 'small.en', fallback: 'x' }, x);
	const done = out[out.length - 1];
	assert.equal(done.type, 'done');
	const heard = w.lib.calls.filter(function (c) { return c.len; });
	assert.equal(heard.length, p.length, 'one call a piece');
	heard.forEach(function (c, i) {
		assert.ok(c.len <= 28.001);
		assert.equal(c.off, p[i][0] / 16000);
		assert.equal(c.total, x.length / 16000);
		assert.equal(c.last, i === p.length - 1);
		assert.deepEqual(c.o, { return_timestamps: 'word' });
	});
	assert.equal(done.chunks.length, p.length * 2);
	done.chunks.forEach(function (c, k) {
		const i = Math.floor(k / 2), off = p[i][0] / 16000;
		assert.equal(c.text, ' w' + (i + 1) + (k % 2 ? 'b' : 'a'));
		assert.equal(c.timestamp[0], Math.round(((k % 2 ? 2 : 0.5) + off) * 1000) / 1000);
		assert.equal(c.timestamp[1], k % 2 ? null : Math.round((1 + off) * 1000) / 1000);
	});
	assert.equal(done.text, done.chunks.map(function (c) { return c.text; }).join(''));
});

/* A reading's start as a real phone take had it: a title, a 1.7 s pause, an 18 s paragraph, a pause, then the next line. */
function titleThenParagraph() {
	const x = new Float32Array(16000 * 30);
	let seed = 9;
	const spans = [[0.5, 2.3], [4.0, 9.8], [10.4, 15.6], [16.0, 21.8], [23.5, 29.5]];
	for (let i = 0; i < x.length; i++) {
		seed = (seed * 16807) % 2147483647;
		const t = i / 16000;
		const on = spans.some(function (sp) { return t >= sp[0] && t < sp[1] && (t % 0.3) < 0.24; });
		x[i] = (on ? 0.03 : 0.00003) * (seed / 2147483647 - 0.5);
	}
	return { x: x, spans: spans };
}
/* A stand-in Whisper: words a third of a second apart wherever there is speech, except that a piece starting at the title and longer than 10 s gets the title only, timed past the piece's end, as the real model did. */
function standIn(spans, calls) {
	const truth = [];
	spans.forEach(function (sp) { for (let t = sp[0]; t < sp[1] - 0.2; t += 0.33) { truth.push({ text: ' w', timestamp: [t, t + 0.25] }); } });
	return async function (part) {
		const off = part.rrtOffset, len = part.length / 16000;
		calls.push([off, off + len]);
		if (off < 0.4 && len > 10) { return { chunks: [{ text: ' The', timestamp: [26.2, 26.2] }, { text: ' Nature', timestamp: [26.2, 29.06] }], text: ' The Nature' }; }
		const c = truth.filter(function (w) { return w.timestamp[0] >= off && w.timestamp[0] < off + len; }).map(function (w) { return { text: w.text, timestamp: [w.timestamp[0] - off, w.timestamp[1] - off] }; });
		return { chunks: c, text: c.map(function (w) { return w.text; }).join('') };
	};
}

test('the speech in a stretch, and the speech Whisper gave no word for', () => {
	const r = titleThenParagraph();
	const sp = W.speechSpans(r.x, 16000, 0, r.x.length);
	assert.ok(Math.abs(sp[0][0] - 0.5) < 0.1 && Math.abs(sp[0][1] - 2.3) < 0.15, JSON.stringify(sp[0]));
	assert.ok(sp.length >= 5 && sp.length <= 8, sp.length + ' spans');
	/* Words over all the speech: nothing missed. */
	const all = [];
	r.spans.forEach(function (s) { for (let t = s[0]; t < s[1] - 0.2; t += 0.33) { all.push({ timestamp: [t, t + 0.25] }); } });
	assert.ok(W.missedSpeech(sp, all, 30).longest < 0.5);
	/* The title only: the paragraph is one long stretch missed. */
	const title = all.filter(function (w) { return w.timestamp[0] < 2.3; });
	assert.ok(W.missedSpeech(sp, title, 30).longest > 15);
	/* A word timed past the piece's end: the whole piece's speech counts as missed. */
	const lost = W.missedSpeech(sp, [{ timestamp: [26.2, 29] }], 22);
	assert.equal(lost.total, lost.longest);
	/* Short gaps between words (breaths) stay under the limit for asking again. */
	const breathy = all.filter(function (w, k) { return k % 6 !== 3; });
	assert.ok(W.missedSpeech(sp, breathy, 30).longest <= 2.5, JSON.stringify(W.missedSpeech(sp, breathy, 30)));
	assert.ok(Math.abs(W.quietPoint(r.x, 16000, 0, 16000 * 22.4, 2) / 16000 - 3.15) < 0.6, 'the quietest pause is the one after the title');
});

test('a piece Whisper stops short on is heard again in halves, and a good one only once', async () => {
	const r = titleThenParagraph();
	const calls = [];
	const asr = standIn(r.spans, calls);
	const first = await W.hear(asr, r.x, 16000, 0, 16000 * 22.4, 0);
	assert.ok(calls.length >= 3, 'heard again: ' + JSON.stringify(calls));
	assert.ok(first.missed < 1, 'nothing left without words: ' + first.missed);
	const times = first.chunks.map(function (c) { return c.timestamp[0]; });
	assert.ok(times[0] < 0.6 && times.some(function (t) { return t > 4 && t < 5; }) && times.some(function (t) { return t > 21; }), 'the title, the start and the end of the paragraph');
	assert.ok(times.every(function (t, k) { return k === 0 || t >= times[k - 1]; }), 'in order');
	assert.ok(times.every(function (t) { return t <= 22.4; }), 'no word past the piece');
	const before = calls.length;
	const next = await W.hear(asr, r.x, 16000, 16000 * 22.4, r.x.length, 0);
	assert.equal(calls.length, before + 1, 'a good answer is not asked for again');
	assert.ok(next.chunks[0].timestamp[0] >= 23.4);
});

test('when a piece cannot be heard better, the first answer stands, with its times kept inside the piece', async () => {
	const r = titleThenParagraph();
	const calls = [];
	const always = async function (part) { calls.push(part.length); return { chunks: [{ text: ' The', timestamp: [26.2, 29] }], text: ' The' }; };
	const got = await W.hear(always, r.x, 16000, 0, 16000 * 22.4, 0);
	assert.equal(got.chunks.length, 1);
	assert.ok(got.chunks[0].timestamp[0] <= 22.4 && got.chunks[0].timestamp[1] <= 22.4, JSON.stringify(got.chunks[0]));
	assert.ok(calls.length <= 7, 'two levels at most: ' + calls.length);
});

test.after(() => { fs.rmSync(dir, { recursive: true, force: true }); });
