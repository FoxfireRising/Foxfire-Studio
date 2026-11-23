'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { load } = require('./load');

const align = load('player.js', 'RRTAlign');

test('caption words find their place in the post', () => {
	assert.deepEqual(Array.from(align(['the', 'fox', 'ran', 'home'], ['a', 'the', 'fox', 'ran', 'far', 'home'])), [1, 2, 3, 5]);
});

test('a common word does not jump far ahead on its own', () => {
	const post = ['once', 'upon', 'a', 'time'].concat(Array(50).fill('word'), ['of', 'the', 'end']);
	const out = align(['once', 'upon', 'of', 'a', 'time'], post);
	assert.ok(out[2] < 10);
});

const say = load('player.js', 'RRTSay');

test('the player uses its translated words, with times filled in', () => {
	assert.equal(say({}, 'of', '%1$s of %2$s', '1:00', '2:30'), '1:00 of 2:30');
	assert.equal(say({ speed: 'Vitesse %s' }, 'speed', 'Speed %s', '1,5×'), 'Vitesse 1,5×');
	assert.equal(say({ of: '%2$s, dont %1$s' }, 'of', '%1$s of %2$s', '1:00', '2:30'), '2:30, dont 1:00');
});
