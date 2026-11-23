'use strict';
/*
 * Audiobook export (1.20.0): room tone made from a reading's own pauses, the
 * ACX leveling, the retail sample's stretch, the heading check, file names,
 * the check report's rules and the ZIP writer.
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const { spawnSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { load } = require('./load');
const { RATE, hush, reading } = require('./synth');

const B = load('audiobook.js');
const S = load('sound.js', 'RRTSound');

function db(x, a, b) {
	let s = 0;
	for (let i = a; i < b; i++) { s += x[i] * x[i]; }
	return 10 * Math.log10(s / Math.max(1, b - a) + 1e-20);
}

/* A podcast-style reading: trimmed to 0.25 s before the first word and 0.6 s after the last, with a room under it. */
function podcastReading(room) {
	const r = reading(8, { pause: (p) => (p === 0 ? 0.25 : (p === 8 ? 0.6 : 0.5 + (p % 3) * 0.4)), hush: room || 0.0006, level: 0.25 });
	return r;
}

test('pauses lists the quiet stretches between the first word and the last', () => {
	const r = podcastReading();
	const p = S.pauses(r.data, RATE, 0.3);
	assert.equal(p.length, 7);
	p.forEach((q) => assert.ok(q.e - q.s >= 0.3));
});

test('roomPad makes up room tone to the wanted seconds from the reading\'s own pauses', () => {
	const r = podcastReading(0.0006);
	const out = S.roomPad(r.data, RATE, 1.2, 3, null);
	const rep = out.report;
	assert.ok(Math.abs(rep.head - 1.2) < 0.03, 'head ' + rep.head);
	assert.ok(Math.abs(rep.tail - 3) < 0.03, 'tail ' + rep.tail);
	assert.ok(rep.addedHead > 0.9 && rep.addedTail > 2.3);
	/* The made-up room sounds like the room: within 3 dB of the pauses, never digital silence. */
	const roomDb = db(r.data, Math.round(RATE * 2.2), Math.round(RATE * 2.4));
	const headDb = db(out.data, 0, Math.round(RATE * 0.8));
	const tailDb = db(out.data, out.data.length - Math.round(RATE * 2), out.data.length);
	assert.ok(Math.abs(headDb - roomDb) < 3, headDb + ' vs ' + roomDb);
	assert.ok(Math.abs(tailDb - roomDb) < 3, tailDb + ' vs ' + roomDb);
	assert.ok(headDb > -100);
	/* The first word moved later by exactly the report's shift. */
	assert.equal(out.data.length, r.data.length + Math.round(RATE * rep.addedHead) + Math.round(RATE * rep.addedTail) - Math.round(RATE * Math.max(0, rep.headWas - rep.head)));
	const e = S.speechBounds(out.data, RATE);
	const e0 = S.speechBounds(r.data, RATE);
	assert.ok(Math.abs((e.first - e0.first) / RATE - rep.shift) < 0.03);
});

test('roomPad never leaves a jump at a join', () => {
	const r = podcastReading(0.001);
	const x = S.roomPad(r.data, RATE, 1.2, 3, null).data;
	let worst = 0;
	for (let i = 1; i < x.length; i++) { worst = Math.max(worst, Math.abs(x[i] - x[i - 1])); }
	/* The room is noise at 0.001: no step much larger than its own. */
	const head = x.subarray(0, Math.round(RATE * 1));
	let w2 = 0;
	for (let i = 1; i < head.length; i++) { w2 = Math.max(w2, Math.abs(head[i] - head[i - 1])); }
	assert.ok(w2 < 0.0025, 'step in the made-up room ' + w2);
});

test('roomPad keeps a file\'s own longer quiet up to the wanted seconds, and cuts the rest', () => {
	const r = reading(4, { pause: (p) => (p === 0 ? 4 : (p === 4 ? 7 : 0.8)), hush: 0.0006 });
	const out = S.roomPad(r.data, RATE, 1.2, 3, null);
	assert.ok(Math.abs(out.report.head - 1.2) < 0.03);
	assert.ok(Math.abs(out.report.tail - 3) < 0.03);
	assert.equal(out.report.addedHead, 0);
	assert.equal(out.report.addedTail, 0);
});

test('roomPad with keep uses no more than that much of a faded file\'s own ends', () => {
	const r = podcastReading();
	const out = S.roomPad(r.data, RATE, 1.2, 3, 0.2);
	assert.ok(out.report.addedHead >= 1.0 - 0.03);
	assert.ok(out.report.addedTail >= 2.8 - 0.03);
});

test('levelRms brings a file to -20 dB RMS with its peaks under -3.5 dB', () => {
	const r = podcastReading();
	const x = S.roomPad(r.data, RATE, 1.2, 3, null).data;
	const y = S.levelRms(x, RATE, -20, -3.5);
	assert.ok(Math.abs(S.rmsDb(y) - -20) < 0.3, String(S.rmsDb(y)));
	assert.ok(S.samplePeakDb(y) <= -3.4);
	const c = S.check(y, RATE, 'audiobook', null, 192, {});
	const room = c.items.filter((it) => /Room tone/.test(it.label));
	assert.equal(room.length, 2);
	room.forEach((it) => assert.equal(it.ok, true, it.label + ' ' + it.value));
	assert.equal(c.items.find((it) => it.label === 'Background').ok, true);
});

test('a reading with no long pause still gets room tone, from its quietest stretch', () => {
	const r = reading(3, { pause: 0.2, hush: 0.0006 });
	const out = S.roomPad(r.data, RATE, 1.2, 3, null);
	assert.ok(out.report.toneDb > -100);
	assert.ok(Math.abs(out.report.tail - 3) < 0.03);
});

test('the retail sample starts after the title and ends in a pause near the length asked for', () => {
	const pauses = [];
	for (let t = 2; t < 600; t += 9) { pauses.push({ s: t, e: t + 0.6 }); }
	const sp = B.sampleSpan(pauses, null, 3, 600, 0.3);
	assert.ok(sp.a > 1.8 && sp.a < 2.7, String(sp.a));
	assert.ok(Math.abs(sp.b - sp.a - 180) < 6, String(sp.b - sp.a));
	const sp2 = B.sampleSpan(pauses, 100, 5, 600, 0.3);
	assert.ok(Math.abs(sp2.a - 101.3) < 0.01);
	assert.ok(sp2.b - sp2.a <= 300 && sp2.b - sp2.a > 290);
	/* A short reading gives what it has. */
	const sp3 = B.sampleSpan([], null, 3, 50, 0);
	assert.equal(sp3.a, 0);
	assert.equal(sp3.b, 50);
});

test('times are read as minutes and seconds', () => {
	assert.equal(B.parseClock('2:30'), 150);
	assert.equal(B.parseClock('90'), 90);
	assert.equal(B.parseClock('1:02:03'), 3723);
	assert.equal(B.parseClock(''), null);
	assert.equal(B.parseClock('abc'), null);
	assert.equal(B.clock(150), '2:30');
});

test('the heading check finds the title among the captions\' first words', () => {
	const vtt = 'WEBVTT\n\n1\n00:00:00.000 --> 00:00:03.000\nThe Turning Wheel: A Spell for Rain.\n\n2\n00:00:03.500 --> 00:00:06.000\nGather your candles.\n';
	assert.equal(B.headingCheck(vtt, 'The Turning Wheel: A Spell for Rain').ok, true);
	assert.equal(B.headingCheck(vtt, 'Happy Winter Solstice!').ok, false);
	assert.equal(B.headingCheck('', 'Anything').ok, null);
});

test('file names keep only plain letters, numbers and spaces', () => {
	assert.equal(B.fileName(1, 'The Turning Wheel: A Spell for Rain'), '01 The Turning Wheel A Spell for Rain.mp3');
	assert.equal(B.fileName(12, 'Happy Winter Solstice!'), '12 Happy Winter Solstice.mp3');
	assert.equal(B.fileName(3, 'Café über'), '03 Cafe uber.mp3');
	assert.equal(B.fileName(4, '!!!'), '04 Chapter.mp3');
	assert.equal(B.zipName('wheel-of-the-year', 'Wheel'), 'wheel-of-the-year-audiobook.zip');
});

test('the MP3 header\'s bitrate and channels are read, past an ID3 tag', () => {
	/* 192 kbps (index 11), 44.1 kHz, mono (mode 3). */
	const frame = [0xFF, 0xFB, 0xB0, 0xC0];
	const tag = [0x49, 0x44, 0x33, 3, 0, 0, 0, 0, 0, 4, 0, 0, 0, 0];
	const u8 = new Uint8Array(tag.concat(frame, new Array(20).fill(0)));
	assert.equal(B.mp3Kbps(u8), 192);
	assert.equal(B.mp3Channels(u8), 1);
	assert.equal(B.mp3Channels(new Uint8Array([0xFF, 0xFB, 0xB0, 0x00, 0, 0])), 2);
});

test('the check report\'s rows for a file and for the whole book', () => {
	const r = podcastReading();
	const y = S.levelRms(S.roomPad(r.data, RATE, 1.2, 3, null).data, RATE, -20, -3.5);
	const c = S.check(y, RATE, 'audiobook', null, 192, {});
	const items = B.fileItems(c, y.length / RATE, 1, { ok: true, heard: 'the turning wheel' });
	assert.ok(items.some((it) => it.label === 'True peak' && it.ok === true));
	assert.ok(items.some((it) => it.label === 'Length' && it.ok === true));
	assert.ok(items.some((it) => it.label === 'Channels' && it.ok === true));
	assert.ok(items.some((it) => it.label === 'Heading read aloud' && it.ok === true));
	assert.ok(B.fileItems(c, 121 * 60, 2, null).some((it) => it.label === 'Length' && it.ok === false));
	const parts = [{ kind: 'open', secs: 6, rms: -20.1 }, { kind: 'chapter', secs: 600, rms: -20 }, { kind: 'missing' }, { kind: 'close', secs: 5, rms: -23.5 }, { kind: 'sample', secs: 180, rms: -20 }];
	const book = B.bookItems(parts, { w: 2000, h: 2000 });
	const get = (l) => book.find((it) => it.label === l);
	assert.equal(get('Opening credits').ok, true);
	assert.equal(get('Chapters').ok, false);
	assert.equal(get('Retail sample').ok, true);
	assert.equal(get('Levels from file to file').ok, false);
	assert.equal(get('Cover').ok, false);
	assert.equal(B.bookItems([{ kind: 'chapter', secs: 60, rms: -20 }], { w: 3000, h: 3000 }).find((it) => it.label === 'Cover').ok, true);
	assert.equal(B.bookItems([], null).find((it) => it.label === 'Opening credits').ok, false);
	const html = B.reportHtml({ title: 'A <b> & "c"', author: 'R', made: 'now', book: book, files: [{ name: '01 X.mp3', items: items }] });
	assert.ok(html.indexOf('A &lt;b&gt; &amp; &quot;c&quot;') > 0);
	assert.ok(html.indexOf('<b>') < 0);
});

test('the ZIP holds every file exactly, as Python\'s zipfile reads it', () => {
	const files = [
		{ name: '00 Opening credits.mp3', data: new Uint8Array([1, 2, 3, 4, 5]) },
		{ name: '01 The Turning Wheel.mp3', data: new Uint8Array(70000).map((_, i) => (i * 7) & 255) },
		{ name: 'Check report.html', data: new TextEncoder().encode('<p>ok</p>') }
	];
	const parts = B.zip(files, new Date(2026, 10, 18, 7, 30, 10));
	let len = 0;
	parts.forEach((p) => { len += p.length; });
	const all = new Uint8Array(len);
	let at = 0;
	parts.forEach((p) => { all.set(p, at); at += p.length; });
	const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'rrt-zip-'));
	const f = path.join(dir, 'a.zip');
	fs.writeFileSync(f, all);
	const py = 'import sys,zipfile,hashlib\nz=zipfile.ZipFile(sys.argv[1])\nassert z.testzip() is None\nfor i in z.infolist(): print(i.filename, i.file_size, hashlib.md5(z.read(i)).hexdigest(), i.date_time)';
	const r = spawnSync('python3', ['-I', '-c', py, f], { encoding: 'utf8' });
	assert.equal(r.status, 0, r.stderr);
	const lines = r.stdout.trim().split('\n');
	assert.equal(lines.length, 3);
	assert.ok(lines[0].startsWith('00 Opening credits.mp3 5 '));
	assert.ok(lines[1].startsWith('01 The Turning Wheel.mp3 70000 '));
	assert.ok(lines[0].endsWith('(2026, 11, 18, 7, 30, 10)'));
	const md5 = require('crypto').createHash('md5').update(Buffer.from(files[1].data)).digest('hex');
	assert.ok(lines[1].indexOf(md5) > 0);
	fs.rmSync(dir, { recursive: true });
	assert.equal(B.crc32(new TextEncoder().encode('123456789')), 0xCBF43926);
});

test('hush is reachable for other tests', () => { assert.equal(hush(4, 0.1).length, 4); });
