'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { load } = require('./load');

const I = load('id3.js');

test('chapters written into a tag read back the same', () => {
	const tag = I.build({ title: 'A reading', artist: 'Reader', chapters: [{ start: 0, end: 5, title: 'One' }, { start: 5, end: 9.5, title: 'Two' }] });
	const back = I.read(tag);
	assert.equal(back.title, 'A reading');
	assert.deepEqual(back.chapters.map((c) => [c.start, c.end, c.title]), [[0, 5, 'One'], [5, 9.5, 'Two']]);
	assert.equal(I.tagLength(tag), tag.length);
});

test('text outside Latin-1 survives', () => {
	assert.equal(I.read(I.build({ title: 'Ørstedt ✦ Ōtsuki' })).title, 'Ørstedt ✦ Ōtsuki');
});

test('bytes with no tag read as empty', () => {
	assert.deepEqual(I.read(new Uint8Array(20)), { title: '', chapters: [] });
});
