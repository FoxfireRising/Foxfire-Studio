/*
 * The audio for the audiobook browser test (1.20.0), made with the plugin's
 * own MP3 encoder:
 *   node tests/wp/audiobook-mp3.js <mp3.wasm> <folder>
 *
 * a.mp3       about 20 s, 128 kbps mono at -19 LUFS: a reading on its post
 * a-book.mp3  the same reading as an audiobook copy: room tone made up to
 *             1.2 s and 3 s, -20 dB RMS, peaks under -3.5 dB, 192 kbps mono
 * b.mp3       about 80 s, 128 kbps mono at -19 LUFS, trimmed tight as a
 *             podcast reading is (for the chapter made from the post's MP3
 *             and the retail sample)
 * c.mp3       about 12 s, 128 kbps mono (the reading with the music bed)
 * open.mp3    about 5 s, the opening credits' audiobook copy
 *
 * Each is phrases of a voiced tone with pauses over a quiet room, the same every run.
 */
'use strict';
const fs = require('fs');
const path = require('path');
const synth = require('../unit/synth');
const S = require('../unit/load').load('sound.js', 'RRTSound');
const M = require('../../plugin/foxfire-studio/assets/js/mp3.js');

const [wasm, dir] = process.argv.slice(2);
const RATE = 44100;

(async () => {
	const ex = await M.load(fs.readFileSync(wasm), null);
	const voice = (phrases, pitch, pauseFn) => synth.reading(phrases, { len: 1.6, pause: pauseFn || ((p) => (p === 0 ? 0.25 : (p === phrases ? 0.6 : 0.5 + (p % 3) * 0.35))), pitch, level: 0.3, hush: 0.0004 }).data;
	const podcast = (x) => S.toLoudness(x, RATE, -19, -1, 40).data;
	const book = (x) => S.levelRms(S.roomPad(x, RATE, 1.2, 3, null).data, RATE, -20, -3.5);
	const write = (name, y, kbps) => {
		fs.writeFileSync(path.join(dir, name), Buffer.from(M.encode(ex, y, RATE, kbps, null, 1)));
		return { name, secs: y.length / RATE };
	};
	const a = voice(8, 170);
	const b = voice(30, 200);
	const c = voice(5, 150);
	const o = voice(2, 180);
	const out = [
		write('a.mp3', podcast(a), 128),
		write('a-book.mp3', book(a), 192),
		write('b.mp3', podcast(b), 128),
		write('c.mp3', podcast(c), 128),
		write('open.mp3', book(o), 192)
	];
	process.stdout.write(JSON.stringify(out));
})().catch((e) => { console.error(e); process.exit(1); });
