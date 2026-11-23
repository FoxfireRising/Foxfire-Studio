/*
 * Foxfire Studio: the comparison of a take with the script (moved here from
 * teleprompter.js in 0.25.1). compareReading( script, chunks, frames,
 * learned, cues ) lines up what Whisper heard against the script, word by
 * word, then looks at each sentence for pace, pauses, flatness and faded
 * endings. Also the word helpers the studio shares: clean, norm, similar
 * and the list of common words (STOP). Loaded before teleprompter.js as
 * window.RRTCompare, and by Node for the unit tests.
 */
(function (root) {
	/* Translations (0.25.0): WordPress's wp.i18n when the page has it, otherwise the English text as written. */
	var rrtI18n = (typeof wp === 'object' ? (wp ? wp.i18n : null) : null) || null;
	var __ = rrtI18n ? rrtI18n.__ : function (s) { return s; };
	var _n = rrtI18n ? rrtI18n._n : function (one, many, n) { return n === 1 ? one : many; };
	var sprintf = rrtI18n ? rrtI18n.sprintf : function (f) {
		var a = arguments;
		var i = 0;
		return String(f).replace(/%(?:(\d+)\$)?([sd%])/g, function (m, pos, type) {
			if (type === '%') { return '%'; }
			var v = a[pos ? Number(pos) : ++i];
			return type === 'd' ? String(parseInt(v, 10)) : String(v);
		});
	};
	/*
	 * The reading's language (1.33.0), set by the studio from RRT.lang with
	 * setLang() before any word is cleaned. English keeps every rule below
	 * exactly as before; other languages keep letters of every script and
	 * leave out the English-only rules (sound-alikes, number words,
	 * contractions, word endings).
	 */
	var LANG = 'en';
	var LTAG = 'en';
	var NOT_WORD = null;
	function setLang(l) {
		var code = l ? (typeof l === 'string' ? l : l.code) : '';
		LANG = code ? String(code) : 'en';
		LTAG = l ? (typeof l === 'object' ? (l.tag || LANG) : LANG) : 'en';
		return LANG;
	}
	function lang() { return LANG; }
	/* Letters, marks and digits of any script; built once, so a browser without Unicode classes keeps the Latin rule. */
	function notWord() {
		if (NOT_WORD === null) {
			try { NOT_WORD = new RegExp('[^\\p{L}\\p{M}\\p{N}]', 'gu'); } catch (e) { NOT_WORD = /[^a-z0-9\u00E0-\u024F]/g; }
		}
		return NOT_WORD;
	}
	/* A heard word without the punctuation around it, for showing (1.33.0: any script outside English). */
	var EDGES = null;
	function trimWord(p) {
		if (LANG === 'en') { return p.replace(/^[^\wÀ-ɏ']+|[^\wÀ-ɏ']+$/g, ''); }
		if (EDGES === null) {
			try { EDGES = new RegExp('^[^\\p{L}\\p{M}\\p{N}\']+|[^\\p{L}\\p{M}\\p{N}\']+$', 'gu'); } catch (e) { EDGES = /^[^\wÀ-ɏ']+|[^\wÀ-ɏ']+$/g; }
		}
		return p.replace(EDGES, '');
	}
	function lower(s) {
		try { return s.toLocaleLowerCase(LTAG); } catch (e) { return s.toLowerCase(); }
	}
	/* Word helpers, shared with the studio's voice following and editing. */
	function clean(s) {
		if (LANG === 'en') {
			return String(s).toLowerCase().replace(/[\u2019']/g, '').replace(/[^a-z0-9\u00E0-\u024F]/g, '');
		}
		var t = String(s);
		if (t.normalize) { t = t.normalize('NFC'); }
		return lower(t).replace(/[\u2019']/g, '').replace(notWord(), '');
	}
	var STOP = {};
	('the and that with this from have were they what when your which there their them then into been will would could should about just like more some than only also over very here where while each every these those because').split(' ').forEach(function (w) { STOP[w] = true; });

	function lev(a, b) {
		var m = a.length;
		var n = b.length;
		var prev = [];
		var cur = [];
		var i;
		var j;
		for (j = 0; j <= n; j++) { prev[j] = j; }
		for (i = 1; i <= m; i++) {
			cur = [i];
			for (j = 1; j <= n; j++) {
				var cost = a.charAt(i - 1) === b.charAt(j - 1) ? 0 : 1;
				cur[j] = Math.min(prev[j] + 1, cur[j - 1] + 1, prev[j - 1] + cost);
			}
			prev = cur;
		}
		return prev[n];
	}
	function similar(a, b) {
		if (a === b) { return 1; }
		if (!a) { return 0; }
		if (!b) { return 0; }
		if (Math.min(a.length, b.length) <= 3) { return 0; }
		return 1 - lev(a, b) / Math.max(a.length, b.length);
	}

	/*
	 * Full review: line up what Whisper heard against the script, word by word,
	 * then look at each sentence for pace, pauses, flatness and faded endings.
	 */
	var FILLERS = { um: 1, umm: 1, uh: 1, uhh: 1, uhm: 1, er: 1, erm: 1, ah: 1, hmm: 1, hm: 1, mm: 1, mhm: 1 };
	/*
	 * Other languages' fillers (1.33.0): only sounds that are never words in
	 * that language, as clean() writes them. Every language also has the
	 * shared hums.
	 */
	var FILLERS_SHARED = 'hmm hm mm mhm mmm';
	var FILLERS_BY = {
		fr: 'euh heu euhm bah ben hum',
		de: 'äh ähm öh öhm hm',
		lb: 'äh ähm',
		nl: 'eh ehm uh uhm',
		es: 'eh em ehm emm',
		ca: 'eh em ehm',
		gl: 'eh em',
		pt: 'é hã ãh hum',
		it: 'ehm eh uhm',
		ro: 'ăă ăăă îî',
		pl: 'yyy yy eee em',
		cs: 'ehm eee hm',
		sk: 'ehm eee',
		sv: 'öh öhm eh',
		no: 'eh ehm øh',
		nn: 'eh ehm øh',
		da: 'øh øhm',
		fi: 'öö tota',
		hu: 'öö ööö izé',
		tr: 'ıı ııı ee eee',
		el: 'εε εεε εμ',
		ru: 'э ээ эм мм',
		uk: 'е ее ем мм',
		bg: 'ъъ ъ ем',
		he: 'אה אמ',
		ar: 'اه امم',
		hi: 'उम अं'
	};
	var FILLER_SETS = {};
	function fillers() {
		if (LANG === 'en') { return FILLERS; }
		if (!FILLER_SETS[LANG]) {
			var f = {};
			(FILLERS_SHARED + ' ' + (FILLERS_BY[LANG] || '')).split(' ').forEach(function (w) { if (w) { f[clean(w)] = 1; } });
			FILLER_SETS[LANG] = f;
		}
		return FILLER_SETS[LANG];
	}

	/*
	 * Whisper writes down what it hears, so words that sound the same but are
	 * spelled differently, numbers written as digits, and contractions are all
	 * treated as the same word before the comparison.
	 */
	var SOUNDALIKE = {};
	('to too two|there their theyre|your youre|here hear|know no|write right rite|whole hole|one won|for four fore|ate eight|hour our|knight night|would wood|peace piece|weather whether|which witch|sun son|see sea|by buy bye|new knew|threw through|wear where|break brake|flower flour|mourning morning|rain reign rein|waste waist|wait weight|so sew sow|i eye|be bee|dear deer|made maid|way weigh|tail tale|mist missed|rose rows|past passed|allowed aloud|altar alter|bare bear|berry bury|cent scent sent|die dye|fair fare|grown groan|heal heel|hymn him|idle idol|led lead|meat meet|pair pear|plain plane|pray prey|profit prophet|sole soul|steal steel|tide tied|vain vein|week weak|whine wine|yoke yolk|bore boar|blue blew|red read|knot not|knows nose|holy wholly|hi high|hallow halo|herd heard|seen scene|aisle isle|all awl|wade weighed|wail whale|wave waive|ware wear|band banned|tea tee|toe tow|cell sell|creek creak|flu flew|guest guessed|loan lone|mail male|oar or ore|pause paws|peak peek|poll pole|real reel|role roll|sail sale|seam seem|stair stare|suite sweet|throne thrown|vary very|wax whacks|yew you').split('|').forEach(function (g) {
		var ws = g.split(' ');
		ws.forEach(function (w) { SOUNDALIKE[w] = ws[0]; });
	});
	var ONES = 'zero one two three four five six seven eight nine ten eleven twelve thirteen fourteen fifteen sixteen seventeen eighteen nineteen'.split(' ');
	var TENS = ' ten twenty thirty forty fifty sixty seventy eighty ninety'.split(' ');
	function numberWords(n) {
		if (n < 20) { return ONES[n]; }
		if (n < 100) { return TENS[Math.floor(n / 10)] + (n % 10 ? ONES[n % 10] : ''); }
		if (n < 1000) { return ONES[Math.floor(n / 100)] + 'hundred' + (n % 100 ? numberWords(n % 100) : ''); }
		if (n < 10000) { return numberWords(Math.floor(n / 1000)) + 'thousand' + (n % 1000 ? numberWords(n % 1000) : ''); }
		return String(n);
	}
	/*
	 * Every way a number Whisper wrote as digits may have been read (1.29.1),
	 * each as its words run together the way the script's keys join: 1200 as
	 * "one thousand two hundred", "twelve hundred" or "a thousand two
	 * hundred", 1984 as "nineteen eighty four", 1905 as "nineteen oh five",
	 * 14th as "fourteenth", 5% as "five percent". Returns [] for anything else.
	 */
	function numWords(n, and) {
		if (n < 20) { return [ONES[n]]; }
		if (n < 100) { return n % 10 ? [TENS[Math.floor(n / 10)], ONES[n % 10]] : [TENS[n / 10]]; }
		if (n < 1000) { return [ONES[Math.floor(n / 100)], 'hundred'].concat(n % 100 ? (and ? ['and'] : []).concat(numWords(n % 100, and)) : []); }
		return numWords(Math.floor(n / 1000), and).concat(['thousand'], n % 1000 ? (n % 1000 < 100 && and ? ['and'] : []).concat(numWords(n % 1000, and)) : []);
	}
	var ORDINAL = { one: 'first', two: 'second', three: 'third', five: 'fifth', eight: 'eighth', nine: 'ninth', twelve: 'twelfth' };
	function ordinal(w) {
		if (ORDINAL[w]) { return ORDINAL[w]; }
		return /y$/.test(w) ? w.replace(/y$/, 'ieth') : w + 'th';
	}
	function numberSpellings(text) {
		if (LANG !== 'en') { return []; }
		var t = String(text || '').replace(/[,’']/g, '').replace(/^[^0-9]+|[^0-9a-z%]+$/gi, '').toLowerCase();
		var m = /^(\d{1,6})(st|nd|rd|th|%)?$/.exec(t);
		if (!m || (m[1].length > 1 && '0' === m[1].charAt(0))) { return []; }
		var n = parseInt(m[1], 10);
		var lists = [numWords(n, false), numWords(n, true)];
		var hi = Math.floor(n / 100);
		var lo = n % 100;
		if (n >= 1100 && n < 10000 && hi % 10 !== 0) {
			// "twelve hundred", "twelve hundred and five".
			lists.push(numWords(hi, false).concat(['hundred'], lo ? numWords(lo, false) : []));
			if (lo) { lists.push(numWords(hi, false).concat(['hundred', 'and'], numWords(lo, false))); }
		}
		if (n >= 1000 && n < 10000 && hi >= 10 && lo) {
			// Years: "nineteen eighty four", "nineteen oh five".
			lists.push(numWords(hi, false).concat(lo < 10 ? ['oh'] : [], numWords(lo, false)));
		}
		lists.slice().forEach(function (l) { if ('one' === l[0] && ('hundred' === l[1] || 'thousand' === l[1])) { lists.push(['a'].concat(l.slice(1))); } });
		if (m[2] && '%' !== m[2]) {
			lists = lists.map(function (l) { return l.slice(0, -1).concat([ordinal(l[l.length - 1])]); });
		} else if ('%' === m[2]) {
			lists = lists.map(function (l) { return l.concat(['percent']); });
		}
		var seen = {};
		var out = [];
		lists.forEach(function (l) {
			var j = l.join('');
			if (!seen[j]) { seen[j] = true; out.push(j); }
		});
		return out;
	}
	function norm(key) {
		if (LANG !== 'en') { return key; }
		if (/^\d+$/.test(key) && key.length <= 4) { key = numberWords(parseInt(key, 10)); }
		return SOUNDALIKE[key] || key;
	}
	function stem(w) {
		if (LANG !== 'en') { return w; }
		var x = w.replace(/(ings|ing|ed|es|s|d|ly)$/, '');
		if (x.length < 3) { x = w; }
		return x.replace(/e$/, '');
	}
	var CONTRACTIONS = {
		its: ['it is', 'it has'], im: ['i am'], youre: ['you are'], were: [], theyre: ['they are'], weve: ['we have'], ive: ['i have'],
		youve: ['you have'], theyve: ['they have'], ill: ['i will'], youll: ['you will'], well: [], theyll: ['they will'], itll: ['it will'],
		id: ['i would', 'i had'], youd: ['you would', 'you had'], hed: ['he would', 'he had'], shed: [], wed: [], theyd: ['they would', 'they had'],
		hes: ['he is', 'he has'], shes: ['she is', 'she has'], thats: ['that is', 'that has'], theres: ['there is', 'there has'], whats: ['what is', 'what has'],
		whos: ['who is', 'who has'], heres: ['here is'], lets: ['let us'], isnt: ['is not'], arent: ['are not'], wasnt: ['was not'], werent: ['were not'],
		dont: ['do not'], doesnt: ['does not'], didnt: ['did not'], cant: ['can not', 'cannot'], couldnt: ['could not'], wouldnt: ['would not'],
		shouldnt: ['should not'], wont: ['will not'], havent: ['have not'], hasnt: ['has not'], hadnt: ['had not'], mustnt: ['must not'], aint: [],
		weare: [], ya: []
	};
	var UNCONTRACT = {};
	Object.keys(CONTRACTIONS).forEach(function (c) {
		CONTRACTIONS[c].forEach(function (ex) { UNCONTRACT[ex.replace(' ', '|')] = c; });
	});

	/*
	 * Join or split what Whisper heard so it lines up with how the script writes it.
	 * scriptRaw (1.29.1): the script's keys before norm(), for numbers the script spells out.
	 */
	function reshapeHeard(hyp, scriptKeys, learnedHeard, scriptRaw) {
		var have = {};
		var pairs = {};
		var q;
		for (q = 0; q < scriptKeys.length; q++) {
			have[scriptKeys[q]] = 1;
			if (q > 0) { pairs[scriptKeys[q - 1] + '|' + scriptKeys[q]] = 1; }
		}
		/* The first place the script spells out this heard number: its script positions, or null. */
		function spelledRun(hw) {
			var ways = numberSpellings(hw.raw || hw.text);
			if (!ways.length) { return null; }
			var want = {};
			ways.forEach(function (w) { want[w] = true; });
			for (var q0 = 0; q0 < scriptRaw.length; q0++) {
				var acc = '';
				for (var n0 = 1; n0 <= 8 && q0 + n0 <= scriptRaw.length; n0++) {
					acc += scriptRaw[q0 + n0 - 1];
					if (want[acc]) {
						var run = [];
						for (var r0 = 0; r0 < n0; r0++) { run.push(q0 + r0); }
						return run;
					}
				}
			}
			return null;
		}
		/* Numbers the script writes as digits, by every way they may be read aloud (1.29.1). */
		var numAt = {};
		var numMax = 0;
		(scriptRaw || []).forEach(function (raw, q) {
			if (!/^\d/.test(raw)) { return; }
			numberSpellings(raw).forEach(function (w) { if (!(w in numAt)) { numAt[w] = scriptKeys[q]; } });
			numMax = 8;
		});
		var out = [];
		var j = 0;
		while (j < hyp.length) {
			var h = hyp[j];
			var done = false;
			// "nineteen eighty four" heard where the script says "1984" (1.29.1).
			for (var nn = Math.min(numMax, hyp.length - j); nn >= 1 && !done; nn--) {
				var joinedN = '';
				for (var tn = 0; tn < nn; tn++) { joinedN += hyp[j + tn].key; }
				if (numAt[joinedN] !== undefined && !have[joinedN]) {
					out.push({ text: hyp.slice(j, j + nn).map(function (x) { return x.text; }).join(' '), key: joinedN, nk: numAt[joinedN], s: h.s, e: hyp[j + nn - 1].e });
					j += nn;
					done = true;
				}
			}
			if (done) { continue; }
			// "blue collar" heard for "blue-collar", "jack o lanterns" for "jack-o-lanterns".
			var n;
			for (n = 3; n >= 2 && !done; n--) {
				if (j + n > hyp.length) { continue; }
				var joined = '';
				var t;
				for (t = 0; t < n; t++) { joined += hyp[j + t].nk; }
				var separate = n === 2 ? pairs[hyp[j].nk + '|' + hyp[j + 1].nk] : false;
				if ((have[joined] || learnedHeard[joined]) && !separate) {
					out.push({ text: hyp.slice(j, j + n).map(function (x) { return x.text; }).join(' '), key: joined, nk: joined, s: h.s, e: hyp[j + n - 1].e });
					j += n;
					done = true;
				}
			}
			if (done) { continue; }
			// "1200" heard where the script says "twelve hundred", "14th" for "fourteenth" (1.29.1).
			var spelled = scriptRaw && (!have[h.nk] || /%$/.test(h.raw || '')) ? spelledRun(h) : null;
			if (spelled) {
				var spanN = (h.e - h.s) / spelled.length;
				spelled.forEach(function (q, t2) {
					out.push({ text: h.text, key: scriptRaw[q], nk: scriptKeys[q], s: h.s + spanN * t2, e: h.s + spanN * (t2 + 1), num: spelled.length > 1 });
				});
				j += 1;
				continue;
			}
			// "it is" heard where the script says "it's".
			if (LANG === 'en' && j + 1 < hyp.length) {
				var con = UNCONTRACT[h.nk + '|' + hyp[j + 1].nk];
				if (con && have[con] && !pairs[h.nk + '|' + hyp[j + 1].nk]) {
					out.push({ text: h.text + ' ' + hyp[j + 1].text, key: con, nk: con, s: h.s, e: hyp[j + 1].e });
					j += 2;
					continue;
				}
			}
			// "it's" heard where the script says "it has".
			if (LANG === 'en' && /['’]/.test(h.text) && CONTRACTIONS[h.key]) {
				var exp = null;
				CONTRACTIONS[h.key].forEach(function (ex) {
					var ws = ex.split(' ');
					if (!exp && pairs[norm(ws[0]) + '|' + norm(ws[1])]) { exp = ws; }
				});
				if (exp) {
					var mid = (h.s + h.e) / 2;
					out.push({ text: h.text, key: exp[0], nk: norm(exp[0]), s: h.s, e: mid });
					out.push({ text: h.text, key: exp[1], nk: norm(exp[1]), s: mid, e: h.e });
					j += 1;
					continue;
				}
			}
			out.push(h);
			j += 1;
		}
		return out;
	}

	/* Two words that start the same for most of the shorter one (1.33.0): a different ending, worth a look. */
	function sameStart(x, y) {
		var n = Math.min(x.length, y.length);
		if (n < 4) { return false; }
		var k = 0;
		while (k < n && x.charAt(k) === y.charAt(k)) { k++; }
		return k >= 4 && k >= n * 0.7;
	}
	/*
	 * A word without its accents (1.33.0): Whisper sometimes leaves an accent
	 * off ("rio" for "río"). A long word already counts as the same with one
	 * letter changed, so a short one gets the same allowance for accents only.
	 * Only the combining accents of Latin, Greek and Cyrillic letters go; the
	 * vowel signs of other scripts are letters and stay.
	 */
	function bare(w) {
		if (!w.normalize) { return w; }
		return w.normalize('NFD').replace(/[̀-ͯ]/g, '').normalize('NFC');
	}
	function relation(x, y, learned) {
		if (x === y) { return 'same'; }
		if (learned && learned[x] && learned[x][y]) { return 'same'; }
		if (LANG !== 'en') {
			/* A number heard as digits where the script spells it out (or the other way round): worth a look, not a miss. */
			if (/^\d+$/.test(x) !== /^\d+$/.test(y) && /\d/.test(x + y)) { return 'check'; }
			if (bare(x) === bare(y)) { return 'same'; }
			if (similar(x, y) >= 0.75) { return 'same'; }
			return sameStart(x, y) ? 'check' : 'diff';
		}
		if (x.length >= 3 && y.length >= 3 && stem(x) === stem(y)) { return 'check'; }
		if (similar(x, y) >= 0.75) { return 'same'; }
		return 'diff';
	}

	function alignWords(a, b, learned) {
		var n = a.length;
		var m = b.length;
		var cols = m + 1;
		var H = new Int32Array((n + 1) * cols);
		var T = new Uint8Array((n + 1) * cols);
		var GAP = -2;
		var i;
		var j;
		for (j = 1; j <= m; j++) { H[j] = j * GAP; T[j] = 3; }
		for (i = 1; i <= n; i++) { H[i * cols] = 0; T[i * cols] = 2; }
		// Words repeat a lot, so score each distinct pair of words only once.
		var ids = {};
		var nextId = 0;
		function idOf(w) { if (!(w in ids)) { ids[w] = nextId++; } return ids[w]; }
		var ai = a.map(idOf);
		var bi2 = b.map(idOf);
		var memo = new Map();
		function score(p, q) {
			var x = a[p];
			var y = b[q];
			if (x === y) { return 3; }
			var mk = ai[p] * 65536 + bi2[q];
			var v = memo.get(mk);
			if (v !== undefined) { return v; }
			var r = relation(x, y, learned);
			if (r === 'same') { v = 3; }
			else if (r === 'check') { v = 2; }
			else {
				var la = x.length;
				var lb = y.length;
				if (Math.abs(la - lb) > Math.max(la, lb) * 0.5) { v = -3; }
				else { v = similar(x, y) >= 0.5 ? -1 : -3; }
			}
			memo.set(mk, v);
			return v;
		}
		for (i = 1; i <= n; i++) {
			for (j = 1; j <= m; j++) {
				var d = H[(i - 1) * cols + j - 1] + score(i - 1, j - 1);
				var u = H[(i - 1) * cols + j] + GAP;
				var l = H[i * cols + j - 1] + GAP;
				var best = d;
				var t = 1;
				if (u > best) { best = u; t = 2; }
				if (l > best) { best = l; t = 3; }
				H[i * cols + j] = best;
				T[i * cols + j] = t;
			}
		}
		// The reading may start or stop partway through the script, so
		// unread script at either end costs nothing.
		var bi = 0;
		var bs = -1e9;
		for (i = 0; i <= n; i++) { if (H[i * cols + m] > bs) { bs = H[i * cols + m]; bi = i; } }
		var ops = [];
		i = bi;
		j = m;
		while (j > 0) {
			var tt = i > 0 ? T[i * cols + j] : 3;
			if (tt === 1) {
				var x = a[i - 1];
				var y = b[j - 1];
				var rel = relation(x, y, learned);
				ops.push({ t: rel === 'same' ? 'M' : (rel === 'check' ? 'C' : 'S'), si: i - 1, hj: j - 1 });
				i--;
				j--;
			} else if (tt === 2) {
				ops.push({ t: 'D', si: i - 1, hj: -1 });
				i--;
			} else {
				ops.push({ t: 'I', si: i, hj: j - 1 });
				j--;
			}
		}
		ops.reverse();
		return ops;
	}

	/* Whisper's word chunks, cleaned up into one entry per spoken word. */
	function heardWords(chunks) {
		var out = [];
		chunks.forEach(function (c) {
			var ts = c.timestamp || [0, 0];
			var s = typeof ts[0] === 'number' ? ts[0] : 0;
			var e = typeof ts[1] === 'number' ? ts[1] : s + 0.3;
			var parts = String(c.text || '').trim().split(/\s+/).filter(function (p) { return clean(p) !== ''; });
			parts.forEach(function (p, k) {
				var span = (e - s) / parts.length;
				var hk = clean(p);
				out.push({ nk: norm(hk), raw: p, text: trimWord(p), key: clean(p), s: s + span * k, e: s + span * (k + 1) });
			});
		});
		return out;
	}

	/*
	 * cues: the post's cue marks by word index, as the studio keeps them, so a
	 * marked pause is never flagged (and, since 1.11.0, a sentence with a fade
	 * meant is never flagged Faded ending).
	 * opts (1.11.0, from RRTPractice.kindReview): rushed and slow, the edges
	 * of the Rushed and Slow flags in words a minute (200 and 105 without a
	 * kind), and fadeDb, how much quieter a sentence's end must be to be
	 * flagged Faded ending (8 dB, or 14 in verse and ritual).
	 */
	function compareReading(script, chunks, frames, learned, cues, opts) {
		learned = learned || {};
		cues = cues || [];
		opts = opts || {};
		var rushedAt = opts.rushed > 0 ? opts.rushed : 200;
		var slowAt = opts.slow > 0 ? opts.slow : 105;
		var fadeDb = opts.fadeDb > 0 ? opts.fadeDb : 8;
		var pairsL = learned.pairs || {};
		var ignore = learned.ignore || {};
		var scriptNk = script.map(function (w) { return norm(w.key); });
		var learnedHeard = {};
		Object.keys(pairsL).forEach(function (sk) { Object.keys(pairsL[sk]).forEach(function (hk) { learnedHeard[hk] = 1; }); });
		var R = { words: [], inserts: {}, sentences: [], paragraphs: [], pauses: [], gaps: [], cueMissed: [], problems: [], heardCount: 0 };
		var keyed = [];
		script.forEach(function (w, k) {
			R.words.push({ status: 'out', heard: '', s: null, e: null });
			if (w.key !== '') { keyed.push(k); }
		});
		var hyp = reshapeHeard(heardWords(chunks), keyed.map(function (k) { return scriptNk[k]; }), learnedHeard, keyed.map(function (k) { return script[k].key; }));
		R.heardCount = hyp.length;
		if (!hyp.length || !keyed.length) { return null; }

		var ops = alignWords(keyed.map(function (k) { return scriptNk[k]; }), hyp.map(function (h) { return h.nk; }), pairsL);
		var firstRead = -1;
		var lastRead = -1;
		ops.forEach(function (op) {
			if (op.t === 'M' || op.t === 'S' || op.t === 'C') {
				var k = keyed[op.si];
				var h = hyp[op.hj];
				R.words[k].status = op.t === 'M' ? 'ok' : (op.t === 'C' ? 'check' : 'sub');
				R.words[k].heard = h.text;
				R.words[k].hk = h.nk;
				R.words[k].s = h.s;
				R.words[k].e = h.e;
				if (firstRead < 0) { firstRead = k; }
				lastRead = k;
			}
		});
		if (firstRead < 0) { return null; }

		/*
		 * The edges of the reading (1.11.1). The alignment lets a reading start or
		 * stop partway through the script for free, so words Whisper heard before
		 * the first matched word (or after the last) were set aside, and the
		 * script words they stood for were left unread. Whisper often runs the
		 * first words together ("Hex the" heard as "Hexa"). Here those heard
		 * words are paired with the unread script words next to them, when they
		 * sound alike enough and sit close in time; anything else (a stray
		 * "thank you" before the reading) is left alone.
		 */
		var firstOp = -1;
		var lastOp = -1;
		ops.forEach(function (op, i) { if (op.t === 'M' || op.t === 'S' || op.t === 'C') { if (firstOp < 0) { firstOp = i; } lastOp = i; } });
		var usedHyp = {};
		function edge(heardIdx, scriptIdx, gapOk) {
			// heardIdx: heard words in time order; scriptIdx: unread script words in order (keyed), both next to the reading.
			if (!heardIdx.length || !scriptIdx.length || !gapOk) { return null; }
			var joinH = heardIdx.map(function (j) { return hyp[j].nk; }).join('');
			var best = null;
			for (var n = 1; n <= scriptIdx.length; n++) {
				var take = scriptIdx.slice(scriptIdx.length - n);
				var joinS = take.map(function (k) { return scriptNk[k]; }).join('');
				var sc = joinS === joinH ? 1 : similar(joinS, joinH);
				if (heardIdx.length === n && heardIdx.every(function (j, q) { return relation(scriptNk[take[q]], hyp[j].nk, pairsL) !== 'diff'; })) { sc = Math.max(sc, 0.9); }
				if (!best || sc > best.sc) { best = { sc: sc, take: take }; }
			}
			return best && best.sc >= 0.4 ? best.take : null;
		}
		function place(take, heardIdx) {
			var hs = heardIdx.map(function (j) { return hyp[j]; });
			if (hs.length === take.length) {
				take.forEach(function (k, q) {
					var rel = relation(scriptNk[k], hs[q].nk, pairsL);
					R.words[k].status = rel === 'same' ? 'ok' : (rel === 'check' ? 'check' : 'sub');
					R.words[k].heard = hs[q].text;
					R.words[k].hk = hs[q].nk;
					R.words[k].s = hs[q].s;
					R.words[k].e = hs[q].e;
				});
			} else {
				// Run together: the first script word carries what was heard; the others share its time and follow it.
				var s0 = hs[0].s;
				var e0 = hs[hs.length - 1].e;
				var joinS = take.map(function (k) { return scriptNk[k]; }).join('');
				var joinH = hs.map(function (h) { return h.nk; }).join('');
				var rel2 = relation(joinS, joinH, pairsL);
				var span = (e0 - s0) / take.length;
				take.forEach(function (k, q) {
					R.words[k].status = rel2 === 'same' ? 'ok' : (rel2 === 'check' ? 'check' : 'sub');
					R.words[k].heard = hs.map(function (h) { return h.text; }).join(' ');
					R.words[k].hk = joinH;
					R.words[k].s = s0 + span * q;
					R.words[k].e = s0 + span * (q + 1);
					if (q > 0) { R.words[k].joinedTo = take[0]; } else { R.words[k].joined = take.length; }
				});
			}
			heardIdx.forEach(function (j) { usedHyp[j] = true; });
		}
		/*
		 * The script's very first or last word misheard on its own (1.36.1): one heard
		 * word right beside the reading, and the one script word left on that side is
		 * the script's own first or last word. Words of three letters or fewer never
		 * count as alike, so "end" heard as "and" scored nothing above; it is a misread.
		 * A filler is never taken for the word, and two or more stray words still are not.
		 */
		function lone(heardIdx, scriptIdx, edgeKey) {
			if (heardIdx.length !== 1 || scriptIdx.length !== 1 || scriptIdx[0] !== edgeKey) { return false; }
			var h1 = hyp[heardIdx[0]];
			return !fillers()[h1.key] && !ignore[h1.nk];
		}
		var lead = [];
		for (var lo = 0; lo < firstOp; lo++) { if (ops[lo].t === 'I') { lead.push(ops[lo].hj); } }
		lead = lead.slice(-4);
		var unreadBefore = keyed.filter(function (k) { return k < firstRead; }).slice(-4);
		var takeLead = edge(lead, unreadBefore, lead.length ? R.words[firstRead].s - hyp[lead[lead.length - 1]].e <= 2.0 : false);
		if (!takeLead && lone(lead, unreadBefore, keyed[0]) && R.words[firstRead].s - hyp[lead[0]].e <= 1.5) { takeLead = unreadBefore; }
		if (takeLead) { place(takeLead, lead); firstRead = takeLead[0]; }
		var trail = [];
		for (var tr = lastOp + 1; tr < ops.length; tr++) { if (ops[tr].t === 'I') { trail.push(ops[tr].hj); } }
		trail = trail.slice(0, 4);
		var unreadAfter = keyed.filter(function (k) { return k > lastRead; }).slice(0, 4);
		var takeTrail = null;
		if (trail.length && unreadAfter.length && hyp[trail[0]].s - R.words[lastRead].e <= 2.0) {
			// The same test, read from the end: the script words right after the reading.
			var joinT = trail.map(function (j) { return hyp[j].nk; }).join('');
			var bestT = null;
			for (var nT = 1; nT <= unreadAfter.length; nT++) {
				var tk = unreadAfter.slice(0, nT);
				var jS = tk.map(function (k) { return scriptNk[k]; }).join('');
				var scT = jS === joinT ? 1 : similar(jS, joinT);
				if (trail.length === nT && trail.every(function (j, q) { return relation(scriptNk[tk[q]], hyp[j].nk, pairsL) !== 'diff'; })) { scT = Math.max(scT, 0.9); }
				if (!bestT || scT > bestT.sc) { bestT = { sc: scT, take: tk }; }
			}
			takeTrail = bestT && bestT.sc >= 0.4 ? bestT.take : null;
		}
		if (!takeTrail && trail.length && lone(trail, unreadAfter, keyed[keyed.length - 1]) && hyp[trail[0]].s - R.words[lastRead].e <= 1.5) { takeTrail = unreadAfter; }
		if (takeTrail) { place(takeTrail, trail); lastRead = takeTrail[takeTrail.length - 1]; }

		ops.forEach(function (op) {
			if (op.t === 'D') {
				var k = keyed[op.si];
				if (k > firstRead && k < lastRead && R.words[k].status === 'out') { R.words[k].status = 'omit'; }
			}
		});
		// Punctuation-only marks inside the read range simply ride along.
		var k;
		for (k = firstRead; k <= lastRead; k++) { if (script[k].key === '') { R.words[k].status = 'ok'; } }

		// Extra words: fillers, repeats, or words that are not in the script.
		ops.forEach(function (op) {
			if (op.t !== 'I') { return; }
			if (usedHyp[op.hj]) { return; }
			var h = hyp[op.hj];
			var at = op.si < keyed.length ? keyed[op.si] : lastRead + 1;
			if (at <= firstRead || at > lastRead + 1) { return; }
			if (ignore[h.nk]) { return; }
			var kind = 'added';
			if (fillers()[h.key]) { kind = 'filler'; }
			else {
				var prevH = hyp[op.hj - 1];
				var nextH = hyp[op.hj + 1];
				if ((prevH && prevH.key === h.key) || (nextH && nextH.key === h.key)) { kind = 'repeat'; }
				else {
					var near = [at - 1, at, at - 2];
					near.forEach(function (q) { if (q >= 0 && q < script.length && script[q].key === h.key) { kind = 'repeat'; } });
				}
			}
			if (!R.inserts[at]) { R.inserts[at] = []; }
			R.inserts[at].push({ text: h.text, kind: kind, s: h.s, e: h.e, hk: h.nk });
		});

		// Fill in times for skipped words so every word can be clicked.
		var lastE = R.words[firstRead].s;
		for (k = firstRead; k <= lastRead; k++) {
			var w = R.words[k];
			if (w.s === null) {
				var nx = k + 1;
				while (nx <= lastRead && R.words[nx].s === null) { nx++; }
				var nextS = nx <= lastRead ? R.words[nx].s : lastE;
				w.s = lastE;
				w.e = Math.max(lastE, nextS);
			} else {
				lastE = w.e;
			}
		}
		R.firstRead = firstRead;
		R.lastRead = lastRead;
		R.start = R.words[firstRead].s;
		R.end = R.words[lastRead].e;

		// Totals.
		var c = { ok: 0, check: 0, sub: 0, omit: 0, added: 0, repeat: 0, filler: 0 };
		for (k = firstRead; k <= lastRead; k++) {
			if (script[k].key === '') { continue; }
			c[R.words[k].status] += 1;
		}
		Object.keys(R.inserts).forEach(function (at) {
			R.inserts[at].forEach(function (x) { c[x.kind] += 1; });
		});
		R.counts = c;
		var scored = c.ok + c.check + c.sub + c.omit;
		R.accuracy = scored ? (c.ok + c.check) / scored : 0;
		var spokenSecs = Math.max(1, R.end - R.start);
		R.wpm = Math.round((c.ok + c.check + c.sub) / (spokenSecs / 60));

		// Paragraph scores.
		var paras = {};
		for (k = firstRead; k <= lastRead; k++) {
			if (script[k].key === '') { continue; }
			var b = script[k].block;
			if (!paras[b]) { paras[b] = { block: b, ok: 0, total: 0 }; }
			paras[b].total += 1;
			if (R.words[k].status === 'ok' || R.words[k].status === 'check') { paras[b].ok += 1; }
		}
		Object.keys(paras).forEach(function (b) {
			var p = paras[b];
			p.score = p.total ? p.ok / p.total : 1;
			R.paragraphs[p.block] = p;
		});

		/*
		 * The longest silence between two times (1.29.1): heard words that are
		 * not in the script (a restart, a repeat, an added word) fill the gap
		 * between two script words, so only the quiet between them counts as a
		 * pause. Without this, a restarted opening read as one long pause inside
		 * the title.
		 */
		var byStart = hyp.map(function (h) { return { s: h.s, e: h.e }; }).sort(function (x, y) { return x.s - y.s; });
		function silence(a, b) {
			var lo = 0;
			var hi = byStart.length;
			while (lo < hi) {
				var mid = (lo + hi) >> 1;
				if (byStart[mid].s < a - 1e-6) { lo = mid + 1; } else { hi = mid; }
			}
			var from = a;
			var most = 0;
			for (var z = lo; z < byStart.length && byStart[z].s < b - 1e-6; z++) {
				if (byStart[z].e > b + 1e-6) { continue; }
				most = Math.max(most, byStart[z].s - from);
				from = Math.max(from, byStart[z].e);
			}
			return Math.max(most, b - from);
		}

		// Pauses between words.
		var prev = -1;
		for (k = firstRead; k <= lastRead; k++) {
			if (script[k].key === '' || R.words[k].status === 'omit') { continue; }
			if (prev >= 0) {
				var raw = R.words[k].s - R.words[prev].e;
				var gap = raw >= 1.0 ? silence(R.words[prev].e, R.words[k].s) : raw;
				var sameSentence = script[k].sent === script[prev].sent;
				/*
				 * The pause map (1.11.0): every gap of a second or more, by where it falls:
				 * inside a sentence, between sentences, or at a paragraph (stanza) break.
				 */
				if (gap >= 1.0) {
					R.gaps.push({ after: prev, before: k, len: gap, s: R.words[prev].e, type: script[k].block !== script[prev].block ? 'break' : (sameSentence ? 'inside' : 'sentence'), cued: cues[prev] ? !!cues[prev].a : false });
				}
				/* A pause or breath was cued after this word, but the reading ran straight on. */
				if (gap < 0.3 && (cues[prev] ? !!cues[prev].a : false)) {
					R.cueMissed.push({ idx: prev, s: R.words[prev].e, len: Math.max(0, gap), a: cues[prev].a });
				}
				if (gap >= 2.0 || (sameSentence && gap >= 1.1)) {
					/* cued: the reader marked a pause or a breath here (0.15.0), so it is meant and never flagged. */
					R.pauses.push({ after: prev, before: k, len: gap, inSentence: sameSentence, s: R.words[prev].e, cued: cues[prev] ? !!cues[prev].a : false });
				}
			}
			prev = k;
		}

		// Sentences.
		var sents = {};
		for (k = firstRead; k <= lastRead; k++) {
			var sid = script[k].sent;
			if (!sents[sid]) { sents[sid] = { id: sid, first: k, last: k, words: 0, ok: 0, omitTail: 0 }; }
			var S = sents[sid];
			S.last = k;
			if (script[k].key === '') { continue; }
			S.words += 1;
			if (R.words[k].status === 'ok' || R.words[k].status === 'check') { S.ok += 1; }
		}
		var ids = Object.keys(sents).map(Number).sort(function (x, y) { return x - y; });
		ids.forEach(function (sid) {
			var S = sents[sid];
			S.s = R.words[S.first].s;
			S.e = R.words[S.last].e;
			S.dur = Math.max(0.1, S.e - S.s);
			var spoken = 0;
			var q;
			for (q = S.first; q <= S.last; q++) { if (script[q].key !== '' && R.words[q].status !== 'omit') { spoken++; } }
			S.wpm = Math.round(spoken / (S.dur / 60));
			S.spoken = spoken;
		});
		// A sentence's pace is judged against your typical sentence pace.
		var paces = ids.map(function (sid) { return sents[sid]; }).filter(function (S) { return S.spoken >= 4; }).map(function (S) { return S.wpm; }).sort(function (x, y) { return x - y; });
		R.sentWpm = paces.length ? paces[Math.floor(paces.length / 2)] : R.wpm;
		ids.forEach(function (sid) {
			var S = sents[sid];
			var q;
			var tail = 0;
			for (q = S.last; q >= S.first; q--) {
				if (script[q].key === '') { continue; }
				if (R.words[q].status === 'omit') { tail++; } else { break; }
			}
			S.flags = [];
			if (tail >= 2 && S.ok >= (S.words - tail) * 0.5 && S.ok >= 2) { S.flags.push('unfinished'); }
			if (S.words >= 5 && S.ok / S.words < 0.6) { S.flags.push('rough'); }
			if (S.words >= 8 && S.dur > 2) {
				if (S.wpm > Math.max(rushedAt, R.sentWpm * 1.32)) { S.flags.push('rushed'); }
				else if (S.wpm < Math.min(slowAt, R.sentWpm * 0.68)) { S.flags.push('slow'); }
			}
			if (R.pauses.some(function (p) { return p.inSentence && !p.cued && p.after >= S.first && p.before <= S.last; })) { S.flags.push('hesitant'); }
			if (frames && S.dur >= 2.5) {
				var fa = Math.floor(S.s / frames.step);
				var fb = Math.ceil(S.e / frames.step);
				var sp = frames.spread(fa, fb);
				if (sp !== null && sp < 1.0) { S.flags.push('flat'); }
				var n = fb - fa;
				var tl = Math.max(10, Math.round(n * 0.2));
				var en = function (x, y) {
					var e = 0;
					var cc = 0;
					for (var z = x; z < y && z < frames.db.length; z++) { if (frames.db[z] > frames.gate) { e += Math.pow(10, frames.db[z] / 10); cc++; } }
					return cc >= 4 ? 10 * Math.log10(e / cc) : null;
				};
				var bodyE = en(fa, fb - tl);
				var tailE = en(fb - tl, fb);
				if (bodyE !== null && tailE !== null && bodyE - tailE > 8) {
					/* An intended fade (1.11.0): a Fade meant cue on the sentence, or a gentle trail in verse and ritual. */
					var meant = false;
					for (q = S.first; q <= S.last; q++) { if (cues[q] ? !!cues[q].f : false) { meant = true; } }
					S.fadeDb = Math.round((bodyE - tailE) * 10) / 10;
					if (meant) { S.fadeMeant = 'cue'; }
					else if (bodyE - tailE <= fadeDb) { S.fadeMeant = 'kind'; }
					else { S.flags.push('faded'); }
				}
			}
			R.sentences.push(S);
		});

		// The pause map's summary (1.11.0).
		var PM = { count: R.gaps.length, sentence: 0, inside: 0, 'break': 0, cued: 0, longest: 0, total: 0 };
		R.gaps.forEach(function (g) {
			PM[g.type] += 1;
			if (g.cued) { PM.cued += 1; }
			PM.longest = Math.max(PM.longest, g.len);
			PM.total += g.len;
		});
		R.pauseMap = PM;

		// Problems, in the order they happen.
		var P = R.problems;
		function text(a, b) {
			var t = [];
			for (var z = a; z <= b; z++) { t.push(script[z].text); }
			return t.join(' ');
		}
		for (k = firstRead; k <= lastRead; k++) {
			var st = R.words[k].status;
			if (st === 'omit') {
				var z = k;
				while (z + 1 <= lastRead && (R.words[z + 1].status === 'omit' || script[z + 1].key === '')) { z++; }
				P.push({ kind: 'omit', idx: k, s: R.words[k].s, label: __( 'Skipped', 'foxfire-studio' ), detail: '“' + text(k, z) + '”' });
				k = z;
			} else if ((st === 'sub' || st === 'check') && R.words[k].joinedTo !== undefined) {
				// Part of a run-together word at the edge of the reading (1.11.1): reported with the first word.
				continue;
			} else if ((st === 'sub' || st === 'check') && R.words[k].joined > 1) {
				var jt = text(k, k + R.words[k].joined - 1).replace(/[^\w\u00C0-\u024F'\u2019\s-]/g, '');
				P.push({
					kind: st, idx: k, s: R.words[k].s, sk: scriptNk[k], hk: R.words[k].hk, teach: false,
					label: st === 'sub' ? __( 'Misread', 'foxfire-studio' ) : __( 'Worth a listen', 'foxfire-studio' ),
					detail: sprintf( /* translators: 1: the words in the script, 2: what Whisper heard. */ __( '“%1$s” heard as “%2$s”', 'foxfire-studio' ), jt, R.words[k].heard )
				});
			} else if (st === 'sub' || st === 'check') {
				// Words Whisper added just before a misheard word are usually part of
				// the same mishearing ("Jacquelyn terms" for "jack-o-lanterns").
				var hk = R.words[k].hk;
				var heardText = R.words[k].heard;
				var before = R.inserts[k];
				if (st === 'sub' && before && before.length <= 2 && before.every(function (x) { return x.kind === 'added'; })) {
					hk = before.map(function (x) { return x.hk; }).join('') + hk;
					heardText = before.map(function (x) { return x.text; }).join(' ') + ' ' + heardText;
					before.forEach(function (x) { x.merged = true; });
				}
				P.push({
					kind: st, idx: k, s: R.words[k].s, sk: scriptNk[k], hk: hk, teach: true,
					label: st === 'sub' ? __( 'Misread', 'foxfire-studio' ) : __( 'Worth a listen', 'foxfire-studio' ),
					detail: sprintf( /* translators: 1: script[k].text.replace(/[^\w\u00C0-\u024F'\u2019-]/g, ''), 2: heardText. */ __( '“%1$s” heard as “%2$s”', 'foxfire-studio' ), script[k].text.replace(/[^\w\u00C0-\u024F'\u2019-]/g, ''), heardText )
				});
			}
		}
		Object.keys(R.inserts).forEach(function (at) {
			var list = R.inserts[at];
			var byKind = {};
			list.forEach(function (x) { (byKind[x.kind] = byKind[x.kind] || []).push(x); });
			Object.keys(byKind).forEach(function (kind) {
				var xs = byKind[kind];
				var label = kind === 'filler' ? __( 'Filler', 'foxfire-studio' ) : (kind === 'repeat' ? __( 'Repeated', 'foxfire-studio' ) : __( 'Added', 'foxfire-studio' ));
				if (xs.every(function (x) { return x.merged; })) { return; }
				var teach = kind === 'added' && xs.length === 1 && xs[0].hk.length >= 4 && !STOP[xs[0].hk];
				P.push({ kind: kind, idx: Math.min(Number(at), script.length - 1), s: xs[0].s, label: label, hk: xs[0].hk, teach: teach, detail: '“' + xs.map(function (x) { return x.text; }).join(' ') + '”' });
			});
		});
		// Words folded into a mishearing are not counted as added words.
		Object.keys(R.inserts).forEach(function (at) {
			R.inserts[at].forEach(function (x) { if (x.merged && x.kind === 'added') { R.counts.added -= 1; } });
		});
		R.pauses.forEach(function (p) {
			if (!p.inSentence ? !p.cued : false) { P.push({ kind: 'pause', idx: p.before, s: p.s, label: __( 'Long pause', 'foxfire-studio' ), detail: sprintf( /* translators: 1: p.len.toFixed(1), 2: script[p.before].text. */ __( '%1$s seconds before “%2$s”', 'foxfire-studio' ), p.len.toFixed(1), script[p.before].text ) }); }
		});
		var FLAGTEXT = {
			unfinished: [__( 'Unfinished', 'foxfire-studio' ), __( 'the end of this sentence was not read', 'foxfire-studio' )],
			rough: [__( 'Paraphrased', 'foxfire-studio' ), __( 'this sentence wandered from the script', 'foxfire-studio' )],
			rushed: [__( 'Rushed', 'foxfire-studio' ), null],
			slow: [__( 'Slow', 'foxfire-studio' ), null],
			hesitant: [__( 'Hesitant', 'foxfire-studio' ), __( 'a pause in the middle of the sentence', 'foxfire-studio' )],
			flat: [__( 'Flat', 'foxfire-studio' ), __( 'very little pitch movement', 'foxfire-studio' )],
			faded: [__( 'Faded ending', 'foxfire-studio' ), __( 'the last words dropped in volume', 'foxfire-studio' )]
		};
		R.sentences.forEach(function (S) {
			S.flags.forEach(function (f) {
				var d = FLAGTEXT[f][1];
				if (f === 'rushed' || f === 'slow') { d = sprintf( /* translators: 1: S.wpm, 2: R.sentWpm. */ __( '%1$s words a minute, against %2$s for this take', 'foxfire-studio' ), S.wpm, R.sentWpm ); }
				P.push({ kind: f, idx: S.first, s: S.s, sent: S.id, label: FLAGTEXT[f][0], detail: d + ': “' + text(S.first, Math.min(S.last, S.first + 6)) + (S.last > S.first + 6 ? '…' : '') + '”' });
			});
		});
		P.sort(function (a, b) { return a.s - b.s; });

		// Passages worth another attempt: sentences with a word-level slip or a serious flag.
		var needs = {};
		P.forEach(function (p) {
			if (p.kind === 'pause' || p.kind === 'filler' || p.kind === 'check') { return; }
			var sid = script[Math.min(p.idx, script.length - 1)].sent;
			needs[sid] = true;
		});
		R.retake = Object.keys(needs).length;
		return R;
	}

	var api = {
		clean: clean,
		lev: lev,
		similar: similar,
		STOP: STOP,
		FILLERS: FILLERS,
		fillers: fillers,
		setLang: setLang,
		lang: lang,
		numberWords: numberWords,
		numberSpellings: numberSpellings,
		norm: norm,
		stem: stem,
		reshapeHeard: reshapeHeard,
		relation: relation,
		alignWords: alignWords,
		heardWords: heardWords,
		compareReading: compareReading
	};
	root.RRTCompare = api;
	if (typeof module !== 'undefined') {
		if (module.exports) { module.exports = api; }
	}
})(typeof window !== 'undefined' ? window : this);
