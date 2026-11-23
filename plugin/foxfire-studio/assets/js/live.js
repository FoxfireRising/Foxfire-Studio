/*
 * Foxfire Studio: phone take mode (1.12.0), the parts that need no page.
 *
 * On a phone the take goes to the site in pieces while it is recorded, so a
 * reload, a call or a closed page loses no more than the last few seconds.
 * This file holds what can be worked out without a browser: how big a piece
 * is, what is left to send after an interruption, where Carry on starts, the
 * large level meter's reading and its words, and the line saying how much is
 * on the site. The studio part phonetake.js does the sending and the screen.
 * window.RRTLive; tested in Node.
 */
(function () {
	var L = {};

	/* Seconds of audio in one piece: about 1.4 MB of 24-bit audio at 48 kHz. */
	L.PIECE_SECONDS = 10;
	/* A take the site last heard from this long ago, unfinished, was cut off (the site uses the same figure). */
	L.STALE_SECONDS = 90;
	/* The meter's scale, in dBFS. */
	L.FLOOR_DB = -72;
	/* Waits between tries when a piece does not go up, in seconds; the last repeats. */
	L.RETRY = [2, 5, 10, 20, 30];
	/* The power average of the voice's short stretches sits about 2.5 dB over its loudness in LUFS (measured on voice-like test readings). */
	L.VOICE_OFFSET = -2.5;

	/* Bytes in one sample of one channel. */
	L.frameBytes = function (bits) { return bits === 24 ? 3 : 2; };
	/* Bytes in a piece for this rate and depth, a whole number of samples. */
	L.pieceBytes = function (rate, bits) { return Math.round(rate * L.PIECE_SECONDS) * L.frameBytes(bits); };
	/* Seconds of audio in a number of bytes. */
	L.seconds = function (bytes, rate, bits) { return rate > 0 ? bytes / (rate * L.frameBytes(bits)) : 0; };

	/*
	 * After an interruption: what the phone still has to send. local is the
	 * bytes kept in the browser, site the bytes the site holds. Returns
	 * { from, bytes } (send local bytes from `from` on, `bytes` of them), with
	 * `from` cut back to a whole sample in case the site ever held a part one.
	 */
	L.resumePlan = function (local, site, bits) {
		var fb = L.frameBytes(bits);
		var s = Math.max(0, Math.floor((Number(site) || 0) / fb) * fb);
		var l = Math.max(0, Number(local) || 0);
		if (s >= l) { return { from: l, bytes: 0 }; }
		return { from: s, bytes: l - s };
	};

	/*
	 * Carry on: the first word of the sentence before the one the prompter
	 * was in when the take was cut off. sents: each word's sentence number,
	 * in order. pos: the word the prompter had reached.
	 */
	L.carryStart = function (sents, pos) {
		var n = sents ? sents.length : 0;
		if (!n) { return 0; }
		var p = Math.max(0, Math.min(n - 1, Math.floor(Number(pos) || 0)));
		var here = sents[p];
		var i = p;
		while (i > 0 && sents[i - 1] === here) { i--; }
		if (i === 0) { return 0; }
		var before = sents[i - 1];
		var j = i - 1;
		while (j > 0 && sents[j - 1] === before) { j--; }
		return j;
	};

	/*
	 * The large meter. Fed the level of each short stretch the browser hands
	 * the page (rms and peak, both 0 to 1, and its length in seconds), it
	 * keeps the last few seconds and says how the voice is doing:
	 * - voiceDb: the voice's level over the last 6 seconds, from the stretches
	 *   at least 10 dB over the room (a power average, brought to its
	 *   loudness in LUFS by VOICE_OFFSET);
	 * - roomDb: the quiet stretches of the last 20 seconds;
	 * - need: the lift a take at this level needs to reach the target, and
	 *   limit: the most the studio can raise it in this room (o.liftLimit is
	 *   RRTSound.liftLimit, with o.nrDb taken off the room for noise reduction);
	 * - word: 'wait' (no voice heard yet), 'good', 'quiet' (within 4 dB of the
	 *   limit), 'low' (over it), or 'loud' (a peak at -1 dBFS or above in the
	 *   last 1.5 seconds).
	 */
	L.meter = function (o) {
		o = o || {};
		var target = isFinite(Number(o.target)) && o.target !== null ? Number(o.target) : -19;
		var nrDb = Math.max(0, Number(o.nrDb) || 0);
		var hist = [];
		var t = 0;
		var shown = L.FLOOR_DB;
		var hold = L.FLOOR_DB;
		var holdT = -9;
		var lastPeakLoud = -9;
		var word = 'wait';
		var wordT = -9;
		function db(v) { return v > 0 ? 20 * Math.log10(v) : -120; }
		var m = {};
		m.push = function (rms, peak, dt) {
			dt = Math.max(0, Number(dt) || 0);
			t += dt;
			var d = db(rms), pk = db(peak);
			hist.push({ t: t, db: d, pk: pk });
			while (hist.length && hist[0].t < t - 20) { hist.shift(); }
			/* Quick to rise, slower to fall, so the bar is steady to read. */
			var fall = 24 * dt;
			shown = d > shown ? d : Math.max(d, shown - fall);
			if (pk >= hold || t - holdT > 1.5) { hold = pk; holdT = t; }
			if (pk >= -1) { lastPeakLoud = t; }
		};
		m.state = function () {
			var out = { level: Math.max(L.FLOOR_DB, shown), peak: Math.max(L.FLOOR_DB, hold), voiceDb: null, roomDb: null, need: null, limit: null, word: 'wait' };
			if (hist.length < 4) { word = 'wait'; return out; }
			var all = hist.map(function (h) { return h.db; }).sort(function (a, b) { return a - b; });
			var room = all[Math.floor(all.length * 0.1)];
			out.roomDb = room;
			var sum = 0, cnt = 0;
			hist.forEach(function (h) {
				if (h.t >= t - 6 && h.db >= room + 10) { sum += Math.pow(10, h.db / 10); cnt++; }
			});
			var w;
			if (cnt >= 3) {
				out.voiceDb = 10 * Math.log10(sum / cnt) + L.VOICE_OFFSET;
				out.need = target - out.voiceDb;
				var lim = typeof o.liftLimit === 'function' ? o.liftLimit(room - nrDb, target, 'podcast') : null;
				out.limit = lim ? lim.limit : 30;
				w = out.need > out.limit + 0.5 ? 'low' : (out.need > out.limit - 4 ? 'quiet' : 'good');
			} else {
				w = word === 'wait' ? 'wait' : word;
			}
			if (t - lastPeakLoud <= 1.5) { w = 'loud'; }
			/* The word changes at most twice a second, so it does not flicker. */
			if (w !== word && (t - wordT >= 0.5 || w === 'loud')) { word = w; wordT = t; }
			out.word = word;
			return out;
		};
		return m;
	};

	/* Where a level sits on the meter, 0 to 100. */
	L.meterPct = function (dbv) {
		var v = (Number(dbv) - L.FLOOR_DB) / -L.FLOOR_DB;
		return Math.max(0, Math.min(100, v * 100));
	};

	/* MB with one decimal. */
	L.mb = function (bytes) { return (Math.max(0, Number(bytes) || 0) / 1048576).toFixed(1); };

	/*
	 * The state of a take on the site, from what the site says: 'done' when
	 * finished, 'live' while pieces still arrive, 'cut' when it stopped
	 * without being finished. t: { live, cut, updated (seconds) }; now in seconds.
	 */
	L.state = function (t, now) {
		if (!t) { return ''; }
		if (t.cut) { return 'cut'; }
		if (!t.live) { return 'done'; }
		return (Number(now) || 0) - (Number(t.updated) || 0) > L.STALE_SECONDS ? 'cut' : 'live';
	};

	if (typeof window !== 'undefined') { window.RRTLive = L; }
	if (typeof module !== 'undefined' && module.exports) { module.exports = L; }
})();
