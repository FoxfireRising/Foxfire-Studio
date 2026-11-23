/*
 * Foxfire Studio: voice polish (1.13.0), the parts that need no page.
 *
 * The sound itself is shaped in sound.js. This file holds what the studio
 * works out around it: a recording setup's name (the device and its
 * microphone), the gaps between words where clicks are looked for, and the
 * live energy note, which watches the voice while a take is recorded and
 * says when it has dropped since the first minute. window.RRTPolish; tested
 * in Node.
 */
(function () {
	var P = {};

	/*
	 * A setup: what kind of device, and which microphone. ua: the browser's
	 * user agent; touch: navigator.maxTouchPoints; mic: the microphone's
	 * name as the browser gives it (empty before permission, or on some
	 * phones). Returns { key, device, mic }: key is lower case, as the site
	 * keeps it; device and mic are for people.
	 */
	P.setup = function (ua, touch, mic) {
		ua = String(ua || '');
		var device;
		if (/iPhone|iPod/.test(ua)) { device = 'iPhone'; }
		else if (/iPad/.test(ua) || (/Macintosh/.test(ua) && Number(touch) > 1)) { device = 'iPad'; }
		else if (/Android/.test(ua)) { device = /Mobile/.test(ua) ? 'Android phone' : 'Android tablet'; }
		else { device = 'Computer'; }
		var name = String(mic || '')
			/* Chrome on Windows puts the system's choice in front: "Default - Microphone Array (...)". */
			.replace(/^(default|communications)\s*-\s*/i, '')
			/* A USB device's id at the end, such as "(046d:0825)", changes nothing about the sound. */
			.replace(/\s*\([0-9a-f]{4}:[0-9a-f]{4}\)\s*$/i, '')
			.replace(/\s+/g, ' ')
			.trim()
			.slice(0, 80);
		var key = (device + '|' + (name || 'built-in')).toLowerCase().replace(/[^a-z0-9 _.|()\-]/g, '').slice(0, 120);
		return { key: key, device: device, mic: name, label: name ? device + ', ' + name : device };
	};

	/*
	 * The gaps between heard words, for lowering clicks: chunks are
	 * Whisper's words ({ text, timestamp: [s, e] }) on the take's timeline.
	 * Only gaps of at least minGap seconds (0.1 by default) count, and the
	 * time before the first word and after the last is left out. Returns
	 * [[start, end], ...] in seconds.
	 */
	P.gaps = function (chunks, minGap) {
		var min = minGap === undefined ? 0.1 : Number(minGap);
		var spans = [];
		(chunks || []).forEach(function (c) {
			var t = c ? c.timestamp : null;
			if (!t || !isFinite(t[0])) { return; }
			var e = isFinite(t[1]) && t[1] !== null ? t[1] : t[0];
			if (String(c.text || '').trim() === '') { return; }
			spans.push([Number(t[0]), Math.max(Number(t[0]), Number(e))]);
		});
		spans.sort(function (a, b) { return a[0] - b[0]; });
		var out = [];
		var end = null;
		spans.forEach(function (s) {
			if (end !== null && s[0] - end >= min) { out.push([end, s[0]]); }
			end = end === null ? s[1] : Math.max(end, s[1]);
		});
		return out;
	};

	/*
	 * The live energy note. Fed the level of each short stretch while a take
	 * is recorded (rms, 0 to 1, and its length in seconds), it learns the
	 * voice's level in the first minute, then compares the last 20 seconds of
	 * voice with it. When the voice sits dropDb (4) or more below its start,
	 * note() returns true, at most once a minute, and the studio shows "Your
	 * voice has dropped a little since the start" for a few seconds. Voice
	 * means the stretches at least 10 dB over the room (the quietest tenth
	 * of the last 20 seconds).
	 */
	P.ENERGY = { firstS: 60, recentS: 20, needFirstS: 15, needRecentS: 8, dropDb: 4, everyS: 60, showS: 8 };
	P.energy = function (o) {
		o = o || {};
		var E = P.ENERGY;
		var drop = isFinite(Number(o.dropDb)) && o.dropDb !== null ? Number(o.dropDb) : E.dropDb;
		var t = 0;
		var hist = [];
		var firstP = 0;
		var firstS = 0;
		var shownAt = -1e9;
		function db(v) { return v > 0 ? 20 * Math.log10(v) : -120; }
		var m = {};
		m.push = function (rms, dt) {
			dt = Math.max(0, Number(dt) || 0);
			if (!dt) { return; }
			t += dt;
			var d = db(rms);
			hist.push({ t: t, db: d, dt: dt });
			while (hist.length && hist[0].t < t - E.recentS) { hist.shift(); }
			if (t <= E.firstS) {
				var room = m.room();
				if (room !== null && d >= room + 10) { firstP += Math.pow(10, d / 10) * dt; firstS += dt; }
			}
		};
		/* The room, worked out again at most twice a second. */
		var roomAt = -1;
		var roomDb = null;
		m.room = function () {
			if (hist.length < 20) { return null; }
			if (roomAt >= 0 && t - roomAt < 0.5) { return roomDb; }
			var all = hist.map(function (h) { return h.db; }).sort(function (a, b) { return a - b; });
			roomAt = t;
			roomDb = all[Math.floor(all.length * 0.1)];
			return roomDb;
		};
		/* The voice's first-minute level, and its level over the last 20 s, in dBFS (null until known). */
		m.state = function () {
			var out = { t: t, first: firstS >= E.needFirstS ? 10 * Math.log10(firstP / firstS) : null, recent: null, drop: null };
			var room = m.room();
			if (room !== null && t > E.firstS) {
				var p = 0;
				var s = 0;
				hist.forEach(function (h) { if (h.db >= room + 10) { p += Math.pow(10, h.db / 10) * h.dt; s += h.dt; } });
				if (s >= E.needRecentS) { out.recent = 10 * Math.log10(p / s); }
			}
			if (out.first !== null && out.recent !== null) { out.drop = out.first - out.recent; }
			return out;
		};
		/* True when the note should show now. */
		m.note = function () {
			var st = m.state();
			if (st.drop === null || st.drop < drop) { return false; }
			if (t - shownAt < E.everyS) { return false; }
			shownAt = t;
			return true;
		};
		return m;
	};
	/* Whether the energy note may run for this take: never in an opening drill, never on a Ritual and chant post. */
	P.energyWanted = function (kind, drill) { return !drill && kind !== 'ritual'; };

	/* The strengths, for menus. */
	P.LEVELS = ['off', 'gentle', 'standard', 'strong'];
	P.STEPS = ['plosives', 'deess', 'breaths', 'compress'];

	/*
	 * The polish for one take: the Settings choice (sound: RRT.sound) with
	 * a one-off for this take (oneOff, from Hear the polish) laid over it.
	 * eqFor: the EQ for this take's setup ([{ f, g }] or null). gaps: the
	 * gaps between words, or null. kind: the post's kind of reading.
	 * Returns the options sound.js process() takes for the polish.
	 */
	P.options = function (sound, oneOff, eqFor, gaps, kind) {
		sound = sound || {};
		oneOff = oneOff || {};
		var out = {};
		P.STEPS.forEach(function (k) {
			var v = oneOff[k] !== undefined && oneOff[k] !== '' ? oneOff[k] : sound[k];
			out[k] = v === false || v === 'off' ? 'off' : (v === 'gentle' || v === 'strong' ? v : 'standard');
		});
		out.slow = oneOff.slow !== undefined ? !!oneOff.slow : sound.slow !== false;
		out.slowMax = kind === 'verse' || kind === 'ritual' ? 2 : 4;
		var eqOn = oneOff.eq !== undefined ? !!oneOff.eq : !!sound.eq;
		out.eq = eqOn && eqFor && eqFor.length ? eqFor : null;
		var clicksOn = oneOff.clicks !== undefined ? !!oneOff.clicks : !!sound.clicks;
		out.gaps = clicksOn && gaps && gaps.length ? gaps : null;
		return out;
	};
	/* Whether a take's polish waits for the Whisper review: clicks are found from its word times. */
	P.needsWords = function (sound, oneOff) {
		oneOff = oneOff || {};
		return oneOff.clicks !== undefined ? !!oneOff.clicks : !!(sound ? sound.clicks : false);
	};

	if (typeof window !== 'undefined') { window.RRTPolish = P; }
	if (typeof module !== 'undefined' && module.exports) { module.exports = P; }
})();
