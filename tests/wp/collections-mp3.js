/*
 * Three readings for the collections browser test (1.19.0), made with the
 * plugin's own MP3 encoder:
 *   node tests/wp/collections-mp3.js <mp3.wasm> <folder>
 *
 * a.mp3  about 8 s, 128 kbps mono, at -19 LUFS (fits)
 * b.mp3  about 6 s, 128 kbps mono, at -23 LUFS (4 dB quieter than the rest)
 * c.mp3  about 5 s, 192 kbps stereo, at -16 LUFS (another format)
 *
 * Each is phrases of a voiced tone with pauses, the same every run.
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
	const make = (phrases, lufs, kbps, ch, name, pitch) => {
		const r = synth.reading(phrases, { len: 1.3, pause: 0.6, pitch, level: 0.3 });
		const x = r.data || r.pcm || r;
		const y = S.toLoudness(x, RATE, ch === 2 ? lufs - 3.01 : lufs, -1, 40).data;
		const out = M.encode(ex, y, RATE, kbps, null, ch);
		fs.writeFileSync(path.join(dir, name), Buffer.from(out));
		return { name, secs: y.length / RATE };
	};
	const files = [
		make(4, -19, 128, 1, 'a.mp3', 170),
		make(3, -23, 128, 1, 'b.mp3', 210),
		make(2, -16, 192, 2, 'c.mp3', 140)
	];
	process.stdout.write(JSON.stringify(files));
})().catch((e) => { console.error(e); process.exit(1); });
