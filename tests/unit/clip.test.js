'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { load } = require('./load');

const C = load('clip.js');

test('a clip link carries its source and campaign', () => {
	const u = new URL(C.link('https://example.test/post/', 'src', 'instagram', 'clip-0m00s'));
	assert.equal(u.searchParams.get('src'), 'instagram');
	assert.equal(u.searchParams.get('utm_campaign'), 'clip-0m00s');
});

test('words compare without case or punctuation', () => {
	assert.equal(C.norm('“Hello,”'), 'hello');
	assert.equal(C.norm('Robin’s'), "robin's");
});

test('heard words take the post spelling when they line up', () => {
	const heard = [{ w: 'the', s: 0, e: 0.2 }, { w: 'foks', s: 0.2, e: 0.5 }, { w: 'ran', s: 0.5, e: 0.8 }];
	const out = C.align(heard, ['The', 'fox', 'ran.']);
	assert.equal(out.length, 3);
	assert.equal(out[0].s, 0);
});

/*
 * Clip suggestions (1.7.0): after a take, the clip maker's own suggestions
 * move to sentence edges from the review's words.
 */
function takeWords() {
	// A title, then two paragraphs: sentences of about 8 s, a word every second.
	const words = [];
	let t = 0.5;
	const add = (n, sn, b, k) => { for (let i = 0; i < n; i++) { words.push({ t: 'w', s: t, e: t + 0.6, sn, b, k }); t += 1; } t += 0.6; };
	add(3, 0, 0, 'title');
	for (let s = 1; s <= 6; s++) { add(8, s, 1, 'p'); }
	add(2, 7, 2, 'h');
	for (let s = 8; s <= 12; s++) { add(8, s, 3, 'p'); }
	return words;
}

test('the review words become sentences with paragraph edges', () => {
	const S = C.sentences(takeWords());
	assert.equal(S.length, 13);
	assert.equal(S[0].k, 'title');
	assert.ok(S[0].ps && S[0].pe, 'the title is a block of its own');
	assert.ok(S[1].ps && !S[1].pe);
	assert.ok(S[6].pe && !S[6].ps);
	assert.equal(S[7].k, 'h');
	assert.ok(S[8].ps);
});

test('a moment moves to sentence edges, 30 to 60 s, ending at a paragraph when one is near', () => {
	const S = C.sentences(takeWords());
	const ed = C.edge(S, 0, 30);
	assert.ok(ed, 'there is a stretch from the opening');
	assert.equal(ed.start, Math.max(0, S[0].s - 0.15));
	const j = S.findIndex(x => Math.abs(x.e - ed.e) < 0.001);
	assert.ok(j >= 0, 'it ends where a sentence ends');
	const d = S[j].e - S[0].s;
	assert.ok(d >= 30 && d <= 60, 'length ' + d);
	// A paragraph's end a little further on wins over a sentence end nearer 30 s.
	const P = [
		{ s: 0, e: 10, k: 'p', ps: true, pe: false },
		{ s: 10.5, e: 31, k: 'p', ps: false, pe: false },
		{ s: 31.5, e: 33, k: 'p', ps: false, pe: true },
		{ s: 34, e: 50, k: 'p', ps: true, pe: true }
	];
	assert.deepEqual(C.edge(P, 0, 30), C.span(0, 33, undefined, 34));
	// From the middle of sentence 9, it starts at the nearest sentence start.
	const mid = C.edge(S, S[9].s + 2, 30);
	assert.equal(mid.s, S[9].s);
	assert.ok(mid.start < S[9].s && mid.start > S[8].e, 'it starts in the pause before');
});

test('a moment never ends on a heading, and a short reading has no stretch', () => {
	const S = C.sentences(takeWords());
	for (let t = 0; t < 120; t += 3) {
		const ed = C.edge(S, t, 30);
		if (!ed) { continue; }
		const j = S.findIndex(x => Math.abs(x.e - ed.e) < 0.001);
		assert.notEqual(S[j].k, 'h');
	}
	assert.equal(C.edge(C.sentences(takeWords().slice(0, 20)), 0, 30), null);
	assert.equal(C.edge([], 0, 30), null);
	assert.deepEqual(C.sentences([{ t: 'x', s: 1, e: 2 }]), [], 'words without sentences (before 1.7.0) give none');
});

test('a span starts a breath early and keeps the last word\'s tail, inside the pauses', () => {
	const a = C.span(10, 40);
	assert.equal(a.start, 9.85);
	assert.equal(a.len, 30.45);
	assert.equal(C.span(0.05, 31).start, 0);
	// A natural reading: 0.26 s before the next sentence (as measured on a real one), 0.2 s after the one before.
	const b = C.span(10, 40, 9.8, 40.26);
	assert.ok(b.start > 9.8 && b.start <= 10, 'starts after the sentence before ends: ' + b.start);
	const end = b.start + b.len;
	assert.ok(end > 40 && end < 40.26, 'ends before the next sentence starts: ' + end);
	// Overlapping timings (Whisper sometimes gives them): no breath, no tail.
	const c = C.span(10, 40, 10.3, 39.9);
	assert.equal(c.start, 10);
	assert.equal(c.start + c.len <= 40.001, true);
});

test('the edges settle on the quietest moment of the pauses, heard from the audio', () => {
	// 16 kHz: a sentence 1.0 to 3.0 s, a pause with true silence from 3.05 to 3.2, the next sentence from 3.2.
	const rate = 16000;
	const x = new Float32Array(rate * 4);
	const loud = (a, b) => { for (let k = Math.floor(a * rate); k < Math.floor(b * rate); k++) { x[k] = 0.5 * Math.sin(k * 0.3); } };
	loud(0, 0.7); loud(1.0, 3.05); loud(3.2, 4);
	for (let k = 0; k < x.length; k++) { x[k] += 0.0005 * Math.sin(k * 1.7); }
	assert.ok(Math.abs(C.quiet(x, rate, 3.0, 3.4) - 3.12) < 0.08, 'the quiet point is inside the true pause');
	// Whisper says the last word ends at 2.95 (early) and the next starts at 3.35 (late).
	const sp = C.settle(x, rate, C.span(1.0, 2.95, 0.72, 3.35));
	const end = sp.start + sp.len;
	assert.ok(end > 3.05 && end < 3.2, 'the end lands in the real pause: ' + end);
	assert.ok(sp.start > 0.7 && sp.start < 1.0, 'the start lands in the pause before: ' + sp.start);
});

test('a moment never ends on a lead-in such as "Declare:"', () => {
	const P = [
		{ s: 0, e: 10, k: 'p', ps: true, pe: false, last: 'fire.' },
		{ s: 10.5, e: 32, k: 'p', ps: false, pe: true, last: 'Declare:' },
		{ s: 33, e: 40, k: 'p', ps: true, pe: true, last: 'gold.' }
	];
	assert.deepEqual(C.edge(P, 0, 30), C.span(0, 40));
	const words = [{ t: 'Speak', s: 0, e: 0.4, sn: 0, b: 0, k: 'p' }, { t: 'Declare:', s: 31, e: 31.6, sn: 0, b: 0, k: 'p' }];
	assert.equal(C.sentences(words)[0].last, 'Declare:');
});

/*
 * The MP4 writer (1.30.0, Mediabunny; its guard since 1.29.1): a piece the
 * writer refuses is caught and reported once, and a file with missing frames
 * is never finished. Mediabunny itself is a stand-in here.
 */
function fakeMB(refuseAt) {
	const log = { v: 0, a: 0, closed: 0, finalized: false, cancelled: false, audioT: [] };
	class Output {
		constructor(o) { this.target = o.target; }
		addVideoTrack() {}
		addAudioTrack() {}
		start() { return Promise.resolve(); }
		finalize() { log.finalized = true; this.target.buffer = new ArrayBuffer(8); return Promise.resolve(); }
		cancel() { log.cancelled = true; return Promise.resolve(); }
	}
	class EncodedVideoPacketSource {
		add() { log.v++; return log.v === refuseAt ? Promise.reject(new Error('refused')) : Promise.resolve(); }
	}
	class AudioSampleSource { add() { log.a++; return Promise.resolve(); } }
	class AudioSample { constructor(o) { log.audioT.push(o.timestamp); } close() { log.closed++; } }
	return {
		log,
		Output, EncodedVideoPacketSource, AudioSampleSource, AudioSample,
		Mp4OutputFormat: class {}, BufferTarget: class {}, StreamTarget: class {},
		EncodedPacket: { fromEncodedChunk: c => c }
	};
}

test('every frame written: the writer finishes the file', async () => {
	const MB = fakeMB(0);
	const w = C.mp4Writer({ MB, fps: 30, sound: { codec: 'aac', bitrate: 128000, lead: 0 } });
	for (let i = 0; i < 90; i++) { w.addVideoChunk({}, {}); }
	w.addAudio(new Float32Array(1024), 1024, 1, 48000, 0);
	const buf = await w.finish(90);
	assert.equal(w.frames, 90);
	assert.ok(buf instanceof ArrayBuffer || buf.byteLength === 8);
	assert.ok(MB.log.finalized);
	assert.equal(MB.log.closed, 1, 'the sound sample is closed once written');
});

test('a refused frame is reported once and the file is not finished', async () => {
	const reasons = [];
	const MB = fakeMB(3);
	const w = C.mp4Writer({ MB, fps: 30, sound: { codec: 'aac', bitrate: 128000, lead: 0 } }, e => reasons.push(e.message));
	for (let i = 0; i < 10; i++) { assert.doesNotThrow(() => w.addVideoChunk({}, {})); }
	await assert.rejects(w.finish(10), /could not be written \(refused\)/);
	assert.equal(w.frames, 9);
	assert.equal(reasons.length, 1);
	assert.equal(MB.log.finalized, false);
});

test('fewer frames than were drawn: the file is not finished', async () => {
	const w = C.mp4Writer({ MB: fakeMB(0), fps: 30, sound: { codec: 'aac', bitrate: 128000, lead: 0 } });
	for (let i = 0; i < 80; i++) { w.addVideoChunk({}, {}); }
	await assert.rejects(w.finish(90), /lost 10 of 90 frames/);
});

test('a long file missing one frame after a key frame is finished, and says so (Safari on iPhone)', async () => {
	const MB = packetMB();
	const w = C.mp4Writer({ MB, fps: 30, sound: { codec: 'aac', bitrate: 128000, lead: 0 } });
	for (let i = 0; i < 7505; i++) { if (i !== 4000) { w.addVideoChunk({ type: i % 30 ? 'delta' : 'key', timestamp: i * 33333 }, {}); } }
	await w.finish(7505);
	assert.equal(w.lost, 1);
	assert.ok(MB.log.finalized);
});

test('a long file missing more than 1 in 1,000 frames is still refused', async () => {
	const MB = packetMB();
	const w = C.mp4Writer({ MB, fps: 30, sound: { codec: 'aac', bitrate: 128000, lead: 0 } });
	for (let i = 0; i < 7505; i++) { if (i % 1000 !== 5) { w.addVideoChunk({ type: i % 30 ? 'delta' : 'key', timestamp: i * 33333 }, {}); } }
	await assert.rejects(w.finish(7505), /lost 8 of 7505 frames/);
	assert.equal(MB.log.finalized, false);
});

test('a file whose first piece is not a key frame gets no allowance', async () => {
	const MB = packetMB();
	const w = C.mp4Writer({ MB, fps: 30, sound: { codec: 'aac', bitrate: 128000, lead: 0 } });
	for (let i = 1; i < 990; i++) { w.addVideoChunk({ type: 'delta', timestamp: i * 33333 }, {}); }
	await assert.rejects(w.finish(990), /lost 1 of 990 frames/);
});

test('settle waits for a last piece that arrives after the flush, and gives up after its wait', async () => {
	const w = C.mp4Writer({ MB: packetMB(), fps: 30, sound: { codec: 'aac', bitrate: 128000, lead: 0 } });
	for (let i = 0; i < 9; i++) { w.addVideoChunk({ type: i ? 'delta' : 'key', timestamp: i * 33333 }, {}); }
	setTimeout(() => w.addVideoChunk({ type: 'delta', timestamp: 9 * 33333 }, {}), 120);
	const t0 = Date.now();
	await w.settle(10, 2000);
	assert.equal(w.got, 10);
	assert.ok(Date.now() - t0 < 1500);
	const t1 = Date.now();
	await w.settle(11, 200);
	assert.ok(Date.now() - t1 >= 190);
	await w.finish(10);
	assert.equal(w.lost, 0);
});

/* Pieces as Mediabunny's packets: seconds, a type and clone( { timestamp } ). */
function packetMB() {
	const MB = fakeMB(0);
	MB.log.videoT = [];
	MB.EncodedVideoPacketSource = class { add(p) { MB.log.v++; MB.log.videoT.push(p.timestamp); return Promise.resolve(); } };
	const packet = (type, t) => ({ type, timestamp: t, clone: o => packet(type, o.timestamp) });
	MB.EncodedPacket = { fromEncodedChunk: c => packet(c.type, c.timestamp / 1e6) };
	return MB;
}

test('pieces handed back a frame late (Firefox on Windows) are written from 0, so no edit list', async () => {
	const MB = packetMB();
	const w = C.mp4Writer({ MB, fps: 30, sound: { codec: 'aac', bitrate: 128000, lead: 0 } });
	const f = 1e6 / 30;
	/* Decode order with a B-frame, every time one frame late: 1, 3, 2, 4. */
	[[1, 'key'], [3, 'delta'], [2, 'delta'], [4, 'delta']].forEach(([n, type]) => w.addVideoChunk({ type, timestamp: n * f }, {}));
	await w.finish(4);
	const want = [0, 2, 1, 3].map(n => n / 30);
	MB.log.videoT.forEach((t, i) => assert.ok(Math.abs(t - want[i]) < 1e-6, 'piece ' + i + ' at ' + t));
});

test('pieces already starting at 0 are written unchanged', async () => {
	const MB = packetMB();
	const w = C.mp4Writer({ MB, fps: 30, sound: { codec: 'aac', bitrate: 128000, lead: 0 } });
	const f = 1e6 / 30;
	[[0, 'key'], [2, 'delta'], [1, 'delta']].forEach(([n, type]) => w.addVideoChunk({ type, timestamp: n * f }, {}));
	await w.finish(3);
	assert.deepEqual(MB.log.videoT.map(t => Math.round(t * 30)), [0, 2, 1]);
});

test('the sound is handed over late by the encoder\'s lead, so its first piece sits at 0', async () => {
	const MB = fakeMB(0);
	const lead = 1024 / 48000;
	const w = C.mp4Writer({ MB, fps: 30, sound: { codec: 'aac', bitrate: 128000, lead } });
	w.addAudio(new Float32Array(1024), 1024, 1, 48000, 0);
	w.addAudio(new Float32Array(1024), 1024, 1, 48000, 1024 / 48000);
	await w.idle();
	assert.ok(Math.abs(MB.log.audioT[0] - lead) < 1e-9);
	assert.ok(Math.abs(MB.log.audioT[1] - 2 * lead) < 1e-9);
});

/* A minimal MP4: ftyp, then moov with two tracks (the first with an edit list), then mdat. */
function box(type, ...kids) {
	const body = Buffer.concat(kids.map(k => Buffer.isBuffer(k) ? k : Buffer.from(k)));
	const h = Buffer.alloc(8);
	h.writeUInt32BE(8 + body.length, 0);
	h.write(type, 4, 'latin1');
	return Buffer.concat([h, body]);
}
function mp4(withEdit) {
	return new Uint8Array(Buffer.concat([
		box('ftyp', 'isom\0\0\0\0'),
		box('moov', box('mvhd', Buffer.alloc(20)),
			box('trak', box('tkhd', Buffer.alloc(20)), ...(withEdit ? [box('edts', box('elst', Buffer.alloc(8)))] : []), box('mdia')),
			box('trak', box('tkhd', Buffer.alloc(20)), box('mdia'))),
		box('mdat', Buffer.from('edts inside the picture data does not count'))
	]));
}

test('edit lists are counted inside moov only', async () => {
	assert.equal(await C.editLists(mp4(true)), 1);
	assert.equal(await C.editLists(mp4(false)), 0, 'the word in mdat is ignored');
});

test('the clip maker runs Whisper as the studio does on a phone: the lighter build on one thread', () => {
	const cfg = { model: 'm', host: 'h' };
	const iphone = C.whisperCfg(cfg, { userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.0 Mobile/15E148 Safari/604.1', maxTouchPoints: 5, gpu: {} }, { touch: true, memory: 0 });
	assert.equal(iphone.lowMem, true);
	assert.equal(iphone.oldSafari, false);
	assert.equal(iphone.model, 'm');
	const ipad = C.whisperCfg(cfg, { userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.0 Safari/605.1.15', maxTouchPoints: 5 }, { touch: true, memory: 0 });
	assert.equal(ipad.lowMem, true);
	assert.equal(ipad.oldSafari, true);
	const laptop = C.whisperCfg(cfg, { userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0 Safari/537.36', deviceMemory: 16, gpu: {} }, { touch: false, memory: 16 });
	const android = C.whisperCfg(cfg, { userAgent: 'Mozilla/5.0 (Android 15; Mobile; rv:140.0) Gecko/140.0 Firefox/140.0' }, { touch: true, memory: 0 });
	assert.equal(android.lowMem, true, 'an Android phone in Firefox (no memory hint) gets the lighter build');
	assert.equal(laptop.lowMem, false);
	assert.equal(laptop.oldSafari, false);
	assert.equal(cfg.lowMem, undefined, 'the site settings themselves are left alone');
});
