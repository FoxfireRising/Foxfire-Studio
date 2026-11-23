'use strict';
/*
 * Finishing presets (1.6.0): stereo loudness, the loudness range, each
 * preset's plan, the chain landing on each preset's numbers, the check's
 * new items, the WAV writer, clip leveling and the encoder in stereo.
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const os = require('os');
const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');
const { load, JS } = require('./load');

const S = load('sound.js', 'RRTSound');
const RATE = 44100;

function sine(amp, hz, secs, rate) {
	rate = rate || RATE;
	const x = new Float32Array(Math.round(rate * secs));
	for (let i = 0; i < x.length; i++) { x[i] = amp * Math.sin(2 * Math.PI * hz * i / rate); }
	return x;
}
/* Something like a voice: tone bursts with pauses, rising and falling in level. */
function voiceLike(secs) {
	const x = new Float32Array(Math.round(RATE * secs));
	let seed = 7;
	function rnd() { seed = (seed * 16807) % 2147483647; return seed / 2147483647; }
	for (let i = 0; i < x.length; i++) {
		const t = i / RATE;
		const word = (Math.floor(t / 0.35) % 5) !== 4 ? 1 : 0;
		const env = word * (0.5 + 0.5 * Math.sin(2 * Math.PI * t / 0.35)) * (0.6 + 0.4 * Math.sin(2 * Math.PI * t / 7));
		x[i] = 0.25 * env * (Math.sin(2 * Math.PI * 180 * t) + 0.5 * Math.sin(2 * Math.PI * 360 * t) + 0.3 * (rnd() - 0.5));
	}
	return x;
}

test('a voice in both channels of a stereo file measures 3 LU louder than in a mono file (BS.1770)', () => {
	const x = sine(0.1, 997, 3);
	const mono = S.lufsMulti([x], RATE);
	const st = S.lufsMulti([x, x], RATE);
	assert.ok(Math.abs(mono - S.lufs(x, RATE)) < 1e-9);
	assert.ok(Math.abs(st - mono - 3.0103) < 0.01, String(st - mono));
	assert.ok(Math.abs(S.STEREO_LU - 3.0103) < 0.001);
});

test('loudness range of 20 s at -20 dBFS then 20 s at -30 dBFS is 10 LU (EBU Tech 3342 case 1)', () => {
	const a = sine(0.1, 1000, 20), b = sine(0.0316228, 1000, 20);
	const x = new Float32Array(a.length + b.length);
	x.set(a); x.set(b, a.length);
	const lra = S.loudnessRange(x, RATE);
	assert.ok(Math.abs(lra - 10) < 1, String(lra));
});

test('loudness range of a steady tone is about 0, and of a short piece is null', () => {
	assert.ok(S.loudnessRange(sine(0.1, 1000, 10), RATE) < 0.1);
	assert.equal(S.loudnessRange(sine(0.1, 1000, 2), RATE), null);
});

test('each preset becomes a plan: mono target, internal ceiling, channels and file', () => {
	const mono = S.plan({ key: 'podcast_mono', kind: 'loudness', lufs: -19, tp: -1, ch: 1, format: 'mp3', kbps: 128 });
	assert.equal(mono.mode, 'podcast');
	assert.equal(mono.target, -19);
	assert.equal(mono.fileTarget, -19);
	assert.ok(Math.abs(mono.ceilingTp - -1.8) < 1e-9, 'an MP3 at -1 is limited at -1.8, as before 1.6.0');
	assert.equal(mono.channels, 1);
	const st = S.plan({ key: 'podcast_stereo', kind: 'loudness', lufs: -16, tp: -1, ch: 2, format: 'mp3', kbps: 128 });
	assert.ok(Math.abs(st.target - (-16 - 3.0103)) < 0.001, 'the voice is processed 3 LU lower for stereo');
	assert.equal(st.channels, 2);
	const bc = S.plan({ key: 'broadcast', kind: 'loudness', lufs: -23, tp: -1, ch: 2, format: 'wav', rate: 48000, bits: 24 });
	assert.equal(bc.format, 'wav');
	assert.equal(bc.ext, 'wav');
	assert.equal(bc.rate, 48000);
	assert.equal(bc.bits, 24);
	assert.equal(bc.kbps, 0);
	assert.ok(Math.abs(bc.ceilingTp - -1.2) < 1e-9);
	const acx = S.plan({ key: 'acx', kind: 'acx', ch: 1, format: 'mp3', kbps: 192 });
	assert.equal(acx.mode, 'audiobook');
	assert.equal(acx.kbps, 192);
	assert.equal(acx.ceilingTp, S.PRESETS.audiobook.ceilingTp);
	assert.equal(S.plan({ kind: 'off' }), null);
	const cu = S.plan({ key: 'custom', kind: 'loudness', lufs: -12, tp: -0.5, ch: 1, format: 'mp3', kbps: 128 });
	assert.ok(Math.abs(cu.ceilingTp - -1.3) < 1e-9);
});

test('the chain lands each loudness preset on its number, with peaks under its limit', () => {
	const v = voiceLike(12);
	const cases = [
		{ lufs: -19, tp: -1, ch: 1, format: 'mp3' },
		{ lufs: -16, tp: -1, ch: 2, format: 'mp3' },
		{ lufs: -14, tp: -1, ch: 2, format: 'mp3' },
		{ lufs: -23, tp: -1, ch: 2, format: 'wav' },
		{ lufs: -18, tp: -2, ch: 1, format: 'mp3' },
		{ lufs: -21, tp: -0.5, ch: 2, format: 'mp3' }
	];
	cases.forEach((c) => {
		const P = S.plan(Object.assign({ kind: 'loudness', kbps: 128 }, c));
		const r = S.process(v, RATE, { mode: P.mode, target: P.target, ceilingTp: P.ceilingTp });
		const chans = P.channels === 2 ? [r.data, r.data] : [r.data];
		const got = S.lufsMulti(chans, RATE);
		assert.ok(Math.abs(got - c.lufs) < 0.5, JSON.stringify(c) + ' measured ' + got);
		assert.ok(S.truePeakDb(r.data) <= P.ceilingTp + 0.1, JSON.stringify(c) + ' peak ' + S.truePeakDb(r.data));
		const chk = S.check(chans[0], RATE, P.mode, P.fileTarget, P.kbps, { chans: chans, tp: P.tp, format: P.format, bits: 24 });
		const loud = chk.items.find((it) => it.label === 'Loudness');
		const peak = chk.items.find((it) => it.label === 'True peak');
		assert.equal(loud.ok, true, JSON.stringify(c) + ' ' + loud.value);
		assert.equal(peak.ok, true, JSON.stringify(c) + ' ' + peak.value + ' ' + peak.rule);
		assert.equal(chk.measures.channels, P.channels);
	});
});

test('a quiet recording that reaches -19 also reaches the louder presets (the 24 dB limit follows the target)', () => {
	const v = voiceLike(12);
	/* Scaled so a podcast needs about 23 dB: just inside the 24 dB limit, as a laptop microphone gives. */
	const L = S.lufs(v, RATE);
	const g = Math.pow(10, (-19 - 23 - L) / 20);
	const q = v.map((a) => a * g);
	[{ lufs: -19, ch: 1 }, { lufs: -16, ch: 2 }, { lufs: -14, ch: 2 }].forEach((c) => {
		const P = S.plan({ kind: 'loudness', lufs: c.lufs, tp: -1, ch: c.ch, format: 'mp3', kbps: 128 });
		const r = S.process(q, RATE, { mode: P.mode, target: P.target, ceilingTp: P.ceilingTp, compress: false });
		const got = S.lufsMulti(c.ch === 2 ? [r.data, r.data] : [r.data], RATE);
		assert.equal(r.report.gainCapped, false, JSON.stringify(c) + ' was capped');
		assert.ok(Math.abs(got - c.lufs) < 0.5, JSON.stringify(c) + ' measured ' + got);
	});
	/* A recording needing 28 dB for a podcast (24 was the limit before 1.6.0) now reaches -19 and -14. */
	const q28 = v.map((a) => a * g * Math.pow(10, -5 / 20));
	[{ lufs: -19, ch: 1 }, { lufs: -14, ch: 2 }].forEach((c) => {
		const P = S.plan({ kind: 'loudness', lufs: c.lufs, tp: -1, ch: c.ch, format: 'mp3', kbps: 128 });
		const r = S.process(q28, RATE, { mode: P.mode, target: P.target, ceilingTp: P.ceilingTp, compress: false });
		const got = S.lufsMulti(c.ch === 2 ? [r.data, r.data] : [r.data], RATE);
		assert.equal(r.report.gainCapped, false, '28 dB ' + JSON.stringify(c) + ' was capped');
		assert.ok(Math.abs(got - c.lufs) < 0.5, '28 dB ' + JSON.stringify(c) + ' measured ' + got);
	});
	assert.equal(S.MAX_GAIN, 30);
	/* Near silence is still held back. */
	const tiny = v.map((a) => a * g * 0.1);
	const r = S.process(tiny, RATE, { mode: 'podcast', target: -17.01, ceilingTp: -1.8, compress: false });
	assert.equal(r.report.gainCapped, true);
});

test('the check reports the loudness range, notes a wide one, and names a WAV', () => {
	const quiet = sine(0.05, 300, 15), loud = sine(0.5, 300, 15);
	const x = new Float32Array(quiet.length + loud.length);
	x.set(quiet); x.set(loud, quiet.length);
	const c = S.check(x, RATE, 'podcast', -19, 128, { tp: -1 });
	const lr = c.items.find((it) => it.label === 'Loudness range');
	assert.ok(lr, 'a loudness range item');
	assert.equal(lr.ok, null, 'information only');
	assert.equal(lr.wide, true);
	assert.ok(/wide for speech/.test(lr.rule));
	const steady = S.check(sine(0.1, 300, 10), RATE, 'podcast', -19, 128, { format: 'wav', bits: 24, chans: [sine(0.1, 300, 10), sine(0.1, 300, 10)] });
	const st = steady.items.find((it) => it.label === 'Loudness range');
	assert.equal(st.wide, false);
	const w = steady.items.find((it) => it.label === 'WAV');
	assert.ok(w && /stereo/.test(w.value) && /24-bit/.test(w.value), w ? w.value : 'none');
});

test('the true peak rule follows the preset\'s limit', () => {
	const x = sine(fromDb(-1.5), 997, 3);
	function fromDb(d) { return Math.pow(10, d / 20); }
	const at1 = S.check(x, RATE, 'podcast', -19, 128, { tp: -1 }).items.find((it) => it.label === 'True peak');
	const at2 = S.check(x, RATE, 'podcast', -19, 128, { tp: -2 }).items.find((it) => it.label === 'True peak');
	assert.equal(at1.ok, true);
	assert.equal(at2.ok, false);
	assert.ok(/-2 dBTP/.test(at2.rule));
	const old = S.check(x, RATE, 'podcast', -19, 128).items.find((it) => it.label === 'True peak');
	assert.ok(/-1 dBTP/.test(old.rule), 'without a limit, -1 as before');
});

test('adjust keeps a preset\'s own ceiling', () => {
	const x = sine(0.5, 440, 2);
	const y = S.adjust(x, RATE, 'podcast', 6, -3);
	assert.ok(S.truePeakDb(y) <= -2.9, String(S.truePeakDb(y)));
});

test('a WAV: header, 24-bit samples, both channels', () => {
	const L = new Float32Array([0, 0.5, -0.5, 1, -1.2]);
	const R = new Float32Array([0.25, 0, 0, 0, 0]);
	const u = S.wav([L, R], 48000, 24);
	const v = new DataView(u.buffer, u.byteOffset, u.byteLength);
	const str = (o, n) => String.fromCharCode.apply(null, Array.from(u.subarray(o, o + n)));
	assert.equal(str(0, 4), 'RIFF');
	assert.equal(str(8, 4), 'WAVE');
	assert.equal(str(12, 4), 'fmt ');
	assert.equal(v.getUint16(20, true), 1, 'PCM');
	assert.equal(v.getUint16(22, true), 2, 'two channels');
	assert.equal(v.getUint32(24, true), 48000);
	assert.equal(v.getUint32(28, true), 48000 * 2 * 3);
	assert.equal(v.getUint16(32, true), 6);
	assert.equal(v.getUint16(34, true), 24);
	assert.equal(str(36, 4), 'data');
	assert.equal(v.getUint32(40, true), 5 * 2 * 3);
	assert.equal(u.length, 44 + 30);
	function s24(o) { let q = u[o] | (u[o + 1] << 8) | (u[o + 2] << 16); if (q & 0x800000) { q -= 0x1000000; } return q; }
	assert.equal(s24(44), 0);
	assert.equal(s24(47), Math.round(0.25 * 8388607), 'right channel second');
	assert.equal(s24(50), Math.round(0.5 * 8388607));
	assert.equal(s24(56), Math.round(-0.5 * 8388607));
	assert.equal(s24(62), 8388607);
	assert.equal(s24(68), -8388607, 'clipped to full scale');
});

test('a clip is leveled to -14 LUFS with peaks held, and raised 12 dB at most', () => {
	const x = sine(0.2, 300, 5, 48000);
	const r = S.toLoudness(x, 48000, -14, -1.5, 12);
	assert.ok(Math.abs(r.lufs - -14) < 0.5, String(r.lufs));
	assert.ok(S.truePeakDb(r.data) <= -1.4, String(S.truePeakDb(r.data)));
	const tiny = sine(0.001, 300, 5, 48000);
	const t = S.toLoudness(tiny, 48000, -14, -1.5, 12);
	assert.ok(t.gainDb <= 12.0001, String(t.gainDb));
	const C = load('clip.js', 'RRTClip');
	assert.equal(C.CLIP_LUFS, -14);
});

/* The real encoder, from npm as the browser tests fetch it; skipped if it cannot be had. */
function encoderBytes() {
	const local = fs.readFileSync(path.join(JS, '..', '..', 'includes', 'local.php'), 'utf8');
	const ver = /define\( 'RRT_MP3_VERSION', '([^']+)' \)/.exec(local)[1];
	const sha = /define\( 'RRT_MP3_SHA256', '([0-9a-f]+)' \)/.exec(local)[1];
	const npm = process.env.NPM_CACHE || path.join(os.tmpdir(), 'foxfire-npm');
	const folder = path.join(npm, 'wasm-media-encoders-' + ver);
	const file = path.join(folder, 'package', 'wasm', 'mp3.wasm');
	if (!fs.existsSync(file)) {
		try {
			fs.mkdirSync(folder, { recursive: true });
			const out = execFileSync('npm', ['pack', '--silent', 'wasm-media-encoders@' + ver], { cwd: npm, stdio: ['ignore', 'pipe', 'ignore'] }).toString().trim().split('\n').pop();
			execFileSync('tar', ['xzf', path.join(npm, out), '-C', folder]);
		} catch (e) { return null; }
	}
	const data = fs.readFileSync(file);
	if (crypto.createHash('sha256').update(data).digest('hex') !== sha) { return null; }
	return { data, sha };
}
/* The channel mode in the first MPEG frame header: 0 stereo, 1 joint stereo, 2 dual, 3 mono. */
function channelMode(u) {
	for (let i = 0; i + 4 < u.length; i++) {
		if (u[i] === 0xff && (u[i + 1] & 0xe0) === 0xe0) { return (u[i + 3] >> 6) & 3; }
	}
	return -1;
}

test('the MP3 encoder makes mono, stereo from one channel, and stereo from two', async (t) => {
	const enc = encoderBytes();
	if (!enc) { t.skip('the encoder could not be fetched from npm'); return; }
	const M = require(path.join(JS, 'mp3.js'));
	const ex = await M.load(new Uint8Array(enc.data).buffer, enc.sha);
	const x = sine(0.3, 440, 2);
	const mono = M.encode(ex, x, 44100, 128);
	const dup = M.encode(ex, x, 44100, 128, null, 2);
	const two = M.encode(ex, [x, sine(0.3, 1000, 2)], 44100, 128);
	assert.equal(channelMode(mono), 3, 'mono');
	assert.ok([0, 1].indexOf(channelMode(dup)) >= 0, 'stereo or joint stereo: ' + channelMode(dup));
	assert.ok([0, 1].indexOf(channelMode(two)) >= 0, 'stereo or joint stereo: ' + channelMode(two));
	/* Constant bitrate: the same size whatever the channels. */
	assert.ok(Math.abs(dup.length - mono.length) < 2000, mono.length + ' ' + dup.length);
	assert.throws(() => M.encode(ex, [x, x, x], 44100, 128), /one or two channels/);
	assert.throws(() => M.encode(ex, [x, x.subarray(10)], 44100, 128), /same length/);
});
