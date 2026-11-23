'use strict';
/* Prompter view (1.5.0): stored settings, the mirror, the fade mask and the keys. */
const test = require('node:test');
const assert = require('node:assert/strict');
const { load } = require('./load');

const P = load('prompter.js', 'RRTPrompter');

test('stored settings are checked and filled in', () => {
	assert.deepEqual(JSON.parse(JSON.stringify(P.clean(null))), { size: 56, cols: 16, line: 30, mirror: 'none', shift: 0, fade: 0, bright: false });
	const c = P.clean({ size: 200, cols: 3, line: '44', mirror: 'hv', shift: -99, fade: 12.4, bright: true });
	assert.equal(c.size, 96);
	assert.equal(c.cols, 10);
	assert.equal(c.line, 44);
	assert.equal(c.mirror, 'hv');
	assert.equal(c.shift, -30);
	assert.equal(c.fade, 12);
	assert.equal(c.bright, true);
	assert.equal(P.clean({ mirror: 'sideways', bright: 'yes', size: 'big' }).mirror, 'none');
	assert.equal(P.clean({ bright: 'yes' }).bright, false);
	assert.equal(P.clean({ size: 'big' }).size, 56);
});

test('each mirror has its transform', () => {
	assert.equal(P.transform('none'), 'none');
	assert.equal(P.transform('h'), 'scale(-1, 1)');
	assert.equal(P.transform('v'), 'scale(1, -1)');
	assert.equal(P.transform('hv'), 'scale(-1, -1)');
	assert.equal(P.transform('nonsense'), 'none');
});

test('the fade keeps a band around the reading line', () => {
	assert.equal(P.mask(30, 0), '');
	const m = P.mask(30, 20);
	/* Half the band is 30%: full strength from 0% to 60%, dimmed from 70%. */
	assert.equal(m, 'linear-gradient(180deg, rgba(0, 0, 0, 0.15) 0%, #000 0%, #000 60%, rgba(0, 0, 0, 0.15) 70%)');
	const narrow = P.mask(40, 40);
	assert.match(narrow, /#000 30%, #000 50%, rgba\(0, 0, 0, 0\.15\) 60%/);
	assert.ok(!/\//.test(narrow), 'no slashes in the value');
});

test('keys belong to prompter view only while it is open, except P', () => {
	assert.equal(P.keyAction('p', false), 'toggle');
	assert.equal(P.keyAction('P', true), 'toggle');
	assert.equal(P.keyAction('b', false), '');
	assert.equal(P.keyAction('Escape', false), '');
	assert.equal(P.keyAction('Escape', true), 'leave');
	assert.equal(P.keyAction('b', true), 'blank');
	assert.equal(P.keyAction('.', true), 'blank');
	assert.equal(P.keyAction('+', true), 'bigger');
	assert.equal(P.keyAction('=', true), 'bigger');
	assert.equal(P.keyAction('-', true), 'smaller');
	assert.equal(P.keyAction('F5', true), 'swallow');
	assert.equal(P.keyAction('F5', false), '');
	assert.equal(P.keyAction(' ', true), '');
	assert.equal(P.keyAction('PageDown', true), '');
});
