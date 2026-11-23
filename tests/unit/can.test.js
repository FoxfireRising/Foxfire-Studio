'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { load } = require('./load');

/* What this browser can do (1.30.0): answers by tool, never by name. */
const Can = load('can.js', 'RRTCan');

const chrome = { encoder: true, h264: true, wasm: true, writer: true, opfs: true, clips: true, video: true, aacOwn: true, aac: true, gpu: true, worklet: true, fullscreen: true, persisted: true };
const firefox = Object.assign({}, chrome, { aacOwn: false });
const iphone = Object.assign({}, chrome, { fullscreen: false, persisted: false });
const privateSafari = Object.assign({}, chrome, { opfs: false, video: false });
const androidFirefox = Object.assign({}, chrome, { encoder: false, h264: false, clips: false, video: false, gpu: false });

test('a browser with every tool can make clips and the video', () => {
	assert.equal(Can.why('clips', chrome), '');
	assert.equal(Can.why('video', chrome), '');
});

test('no video encoder: the message names the tool and the browsers that have it, with no versions', () => {
	const m = Can.why('clips', androidFirefox);
	assert.match(m, /no H\.264 video encoder/);
	assert.match(m, /Chrome, Edge, Safari and Firefox on a computer/);
	assert.doesNotMatch(m, /version|or later|\d+\.\d+\.\d/);
});

test('no private file storage: clips yes, the full video no', () => {
	assert.equal(Can.why('clips', privateSafari), '');
	assert.match(Can.why('video', privateSafari), /private storage/);
});

test('the MP4 writer not loaded: a reload is suggested (only when making)', () => {
	const a = Object.assign({}, chrome, { writer: false });
	assert.match(Can.why('clips', a), /did not load/);
	assert.doesNotMatch(Can.line(a), /did not load/);
});

test('the "This browser" line says where the AAC comes from', () => {
	assert.match(Can.line(chrome), /AAC sound from its own encoder/);
	assert.match(Can.line(firefox), /AAC add-on/);
	assert.doesNotMatch(Can.line(chrome), /full screen|cleared/);
});

test('the line names the small gaps only where they apply', () => {
	const l = Can.line(iphone);
	assert.match(l, /no full screen for pages/);
	assert.match(l, /can be cleared/);
	assert.match(Can.line(androidFirefox), /cannot make clips/);
	assert.match(Can.line(androidFirefox), /on its processor/);
});

test('now() without a browser says no to everything, without throwing', () => {
	const a = Can.now();
	assert.equal(a.clips, false);
	assert.equal(a.video, false);
	assert.equal(a.touch, false);
});

test('phones by touch and memory, never by name (1.31.0)', () => {
	const cases = [
		['an iPhone (touch, no hint)', { touch: true, memory: 0 }, true, true],
		['a 16 GB iPad (touch, no hint)', { touch: true, memory: 0 }, true, true],
		['an Android phone in Chrome (touch, 8 GB)', { touch: true, memory: 8 }, true, false],
		['an Android phone in Firefox (touch, no hint)', { touch: true, memory: 0 }, true, true],
		['a touch laptop used with a mouse', { touch: false, memory: 8 }, false, false],
		['a desktop with 2 GB', { touch: false, memory: 2 }, false, true],
		['Safari or Firefox on a computer (no hint)', { touch: false, memory: 0 }, false, false]
	];
	cases.forEach(([name, a, phone, low]) => {
		assert.equal(Can.phone(a), phone, name + ': phone');
		assert.equal(Can.lowMem(a), low, name + ': small memory');
	});
});
