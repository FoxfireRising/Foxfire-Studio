/*
 * Made-up audio for the unit tests: a steady hush, and a "reading" of
 * phrases (a voiced tone that moves in pitch) with pauses between them.
 * The same numbers every run.
 */
'use strict';

const RATE = 44100;

function hush(n, level, seed) {
	let x = seed || 7;
	const out = new Float32Array(n);
	for (let i = 0; i < n; i++) {
		x = (x * 1103515245 + 12345) % 2147483648;
		out[i] = (x / 2147483648 - 0.5) * 2 * level;
	}
	return out;
}

/*
 * opts: pause (seconds, or a function of the phrase number), len (seconds),
 * hush (level), pitch (Hz), swing (Hz the pitch moves), fade (how far the
 * last fifth of each phrase drops, 0 to 1), level.
 */
function reading(phrases, opts) {
	opts = opts || {};
	const pauseOf = typeof opts.pause === 'function' ? opts.pause : function () { return opts.pause || 1.2; };
	const len = opts.len || 1.6;
	const pitch = opts.pitch || 180;
	const swing = opts.swing === undefined ? 40 : opts.swing;
	const level = opts.level || 0.3;
	const fade = opts.fade || 0;
	let total = pauseOf(0);
	for (let p = 0; p < phrases; p++) { total += len + pauseOf(p + 1); }
	const n = Math.round(RATE * total);
	const out = hush(n, opts.hush || 0.002);
	const starts = [];
	let t = pauseOf(0);
	for (let p = 0; p < phrases; p++) {
		const a = Math.round(RATE * t);
		starts.push(a);
		const m = Math.round(RATE * len);
		let phase = 0;
		for (let i = 0; i < m; i++) {
			const env = Math.min(1, i / 2000, (m - i) / 2000);
			const tail = i > m * 0.8 ? 1 - fade : 1;
			const f = pitch + swing * Math.sin(2 * Math.PI * i / m);
			phase += 2 * Math.PI * f / RATE;
			out[a + i] += level * env * tail * Math.sin(phase);
		}
		t += len + pauseOf(p + 1);
	}
	return { data: out, starts: starts, seconds: total };
}

function rms(x, a, b) {
	let s = 0;
	for (let i = a; i < b; i++) { s += x[i] * x[i]; }
	return Math.sqrt(s / Math.max(1, b - a));
}

module.exports = { RATE, hush, reading, rms };
