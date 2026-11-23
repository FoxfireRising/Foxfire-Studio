/*
 * A reading and a message for the sponsor messages browser test (1.22.0),
 * made with the plugin's own MP3 encoder, whose frames keep part of their
 * sound in the frames before them (the bit reservoir), as real readings do:
 *   node tests/wp/sponsors-mp3.js <mp3.wasm> <folder>
 *
 * reading.mp3  about 70 s, 128 kbps mono, -19 LUFS: phrases with pauses of
 *              1.4 s and 0.4 s, and a quiet room under them
 * message.mp3  about 4 s, 128 kbps mono: a higher tone, louder than it should be
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
	const r = synth.reading(30, { len: 1.3, pause: (p) => (p % 2 ? 1.4 : 0.4), pitch: 170, level: 0.3, hush: 0.0015 });
	const y = S.toLoudness(r.data, RATE, -19, -1, 40).data;
	fs.writeFileSync(path.join(dir, 'reading.mp3'), Buffer.from(M.encode(ex, y, RATE, 128, null, 1)));
	const m = synth.reading(2, { len: 1.6, pause: 0.3, pitch: 320, level: 0.5, hush: 0.0015 });
	const z = S.toLoudness(m.data, RATE, -14, -1, 40).data;
	fs.writeFileSync(path.join(dir, 'message.mp3'), Buffer.from(M.encode(ex, z, RATE, 128, null, 1)));
	process.stdout.write(JSON.stringify({ reading: y.length / RATE, message: z.length / RATE }));
})().catch((e) => { console.error(e); process.exit(1); });
