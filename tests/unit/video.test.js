'use strict';
/*
 * Full-length video (1.18.0): the helpers behind the video maker and its
 * upload kit.
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const { load } = require('./load');

const V = load('video.js');

const VTT = [
	'WEBVTT',
	'',
	'1',
	'00:00:00.500 --> 00:00:03.200',
	'The moon is <b>round</b>',
	'and the [night] is long',
	'',
	'2',
	'00:00:03.400 --> 00:00:06.000',
	'Owls &amp; foxes',
	'',
	'00:01:02.000 --> 01:00:03.500',
	'Much later',
	''
].join('\r\n');

test('captions come from the cues, tags and brackets gone, lines joined', () => {
	const c = V.cues(VTT);
	assert.equal(c.length, 3);
	assert.equal(c[0].text, 'The moon is round and the night is long');
	assert.equal(c[1].text, 'Owls & foxes');
	assert.equal(c[0].s, 0.5);
	assert.equal(c[2].s, 62);
	assert.equal(c[2].e, 3603.5);
});

test('a phrase is held until the next when the gap is under half a second', () => {
	const c = V.cues(VTT);
	assert.equal(c[0].e, 3.4);
	assert.equal(c[1].e, 6);
});

test('a short-form time and a cue without an id are read', () => {
	const c = V.cues('WEBVTT\n\n00:01.000 --> 00:02.500\nHello\n');
	assert.deepEqual(c.map((x) => [x.s, x.e, x.text]), [[1, 2.5, 'Hello']]);
});

test('the phrase showing at a time is found, and none in a gap', () => {
	const c = V.cues(VTT);
	assert.equal(V.at(c, 0.4), -1);
	assert.equal(V.at(c, 1), 0);
	assert.equal(V.at(c, 3.4), 1);
	assert.equal(V.at(c, 30), -1);
	assert.equal(V.at(c, 100), 2);
	assert.equal(V.at([], 1), -1);
});

test('the chapter being read is the last one started', () => {
	const ch = [{ start: 0, title: 'A' }, { start: 60, title: 'B' }];
	assert.equal(V.chapterAt(ch, 10).title, 'A');
	assert.equal(V.chapterAt(ch, 60).title, 'B');
	assert.equal(V.chapterAt([{ start: 5, title: 'C' }], 1), null);
});

test('chapter times are written as YouTube writes them', () => {
	assert.equal(V.timecode(0), '0:00');
	assert.equal(V.timecode(65.9), '1:05');
	assert.equal(V.timecode(3729), '1:02:09');
});

test('YouTube chapters start at 0:00, number three or more, each 10 s or longer', () => {
	const ch = [{ start: 30, title: 'Calling the quarters' }, { start: 120, title: 'The working' }, { start: 125, title: 'Too soon' }, { start: 400, title: 'Closing' }, { start: 595, title: 'At the very end' }];
	assert.deepEqual(V.ytChapters(ch, 600, 'Opening'), ['0:00 Opening', '0:30 Calling the quarters', '2:00 The working', '6:40 Closing']);
});

test('a first chapter at the start becomes 0:00, and too few chapters give none', () => {
	assert.deepEqual(V.ytChapters([{ start: 0.4, title: 'One' }, { start: 60, title: 'Two' }, { start: 120, title: 'Three' }], 300, 'Opening'), ['0:00 One', '1:00 Two', '2:00 Three']);
	assert.deepEqual(V.ytChapters([{ start: 0, title: 'One' }, { start: 60, title: 'Two' }], 300, 'Opening'), []);
	assert.deepEqual(V.ytChapters([], 300, 'Opening'), []);
	assert.deepEqual(V.ytChapters(null, 300, 'Opening'), []);
});

test('the description has the excerpt, the tagged link and the chapters', () => {
	const b = { excerpt: 'A spell for the season.', link: 'https://example.test/hex/', src: 'src', chapters: [{ start: 0, title: 'One' }, { start: 60, title: 'Two' }, { start: 120, title: 'Three' }] };
	const d = V.description(b, 'youtube', 300);
	const parts = d.split('\n\n');
	assert.equal(parts[0], 'A spell for the season.');
	const u = new URL(parts[1].replace('Read the post: ', ''));
	assert.equal(u.searchParams.get('src'), 'youtube');
	assert.equal(u.searchParams.get('utm_campaign'), 'video');
	assert.equal(parts[2], '0:00 One\n1:00 Two\n2:00 Three');
	const s = V.description({ excerpt: '', link: 'https://example.test/hex/', src: 'from', chapters: [] }, 'spotify', 300);
	assert.equal(s.split('\n\n').length, 1);
	assert.equal(new URL(s.replace('Read the post: ', '')).searchParams.get('from'), 'spotify');
});

test('the size estimate matches the research (Hex at 2.5 Mbps came to 275 MB)', () => {
	const est = V.estimate(853, 2500000);
	assert.ok(est > 260e6 && est < 330e6, String(est));
	assert.ok(V.estimate(853, 5000000) > 1.8 * est);
	assert.equal(V.size(274.8e6), '275 MB');
	assert.equal(V.size(32.1e6), '32.1 MB');
	assert.equal(V.size(1.24e9), '1.2 GB');
});

test('the file is named from the post', () => {
	assert.equal(V.fileName('hex-the-oligarchy'), 'hex-the-oligarchy-video.mp4');
	assert.equal(V.fileName('Ünïcode & Things'), 'n-code-things-video.mp4');
	assert.equal(V.fileName(''), 'reading-video.mp4');
});

/* A stand-in for measuring text: every character as wide as the size. */
const measure = (t, size) => String(t).length * size;

test('a caption takes the largest size that fits two lines', () => {
	const short = V.fitCaption(measure, 'The moon is round', 1200, [60, 54, 48, 44], 2);
	assert.equal(short.size, 60);
	assert.deepEqual(short.lines, ['The moon is round']);
	const long = V.fitCaption(measure, 'They violate the land and sea as well as the people who live there', 1000, [60, 54, 48, 44], 2);
	assert.ok(long.lines.length <= 2);
	assert.ok(long.lines.every((l) => measure(l, long.size) <= 1000));
	assert.ok(long.size < 60);
});

test('a phrase too long for two lines at the smallest size ends in an ellipsis', () => {
	const words = Array.from({ length: 40 }, () => 'word').join(' ');
	const f = V.fitCaption(measure, words, 1000, [60, 44], 2);
	assert.equal(f.size, 44);
	assert.equal(f.lines.length, 2);
	assert.ok(f.lines[1].endsWith('…'));
	assert.ok(measure(f.lines[1], 44) <= 1000);
});

test('a chapter title too long for its line is cut with an ellipsis', () => {
	const m = (t) => t.length * 30;
	assert.equal(V.cut(m, 'Short', 960), 'Short');
	const c = V.cut(m, 'A chapter title that is much too long to fit on the line it has', 960);
	assert.ok(c.endsWith('…'));
	assert.ok(m(c) <= 960);
});
