/*
 * Foxfire Studio: the Whisper worker (moved here from teleprompter.js in
 * 0.25.1), shared by the review studio and the clip maker so both run the
 * same code. start() gives a module Web Worker that loads transformers.js
 * (the site's own copy, else jsDelivr) and the model (the site's copy,
 * else Hugging Face, else the fallback model), then answers each message
 * { cfg, audio } with "load" progress, "working" and then "done" with the
 * word chunks, or "error".
 *
 * cfg: lib, cdn, site (the name reported when the site's copy is used),
 * wasm, host, model, fallback, lowMem (one thread and the smaller build,
 * for phones), oldSafari, and since 1.17.0 gpu (run the model on the
 * graphics chip with WebGPU: the encoder in half precision and the decoder
 * in 4-bit half precision, from host, else Hugging Face at revision rev,
 * with no fallback model, so the page can fall back to the processor). Loaded as window.RRTWhisper, and by Node for the
 * unit tests.
 */
(function (root) {
	/*
	 * Where to cut a recording into pieces Whisper hears one at a time
	 * (1.9.1), as [start, end] sample pairs. A recording of up to MAX
	 * seconds is one piece. A longer one is cut at the quietest 300 ms
	 * between MIN and MAX seconds into what is left, never leaving less than
	 * TAIL seconds for the last piece. Until 1.9.1 the library cut long audio
	 * into 30 s windows overlapping by 5 s and joined their words where the
	 * windows overlapped, and on a real 72 s phone reading that join lost the
	 * first 25 s. Pieces that do not overlap need no join.
	 */
	function pieces(audio, rate) {
		var MAX = 28, MIN = 15, TAIL = 5;
		var n = audio.length, out = [], hop = Math.max(1, Math.round(rate * 0.02)), nf = Math.floor(n / hop), e = new Float64Array(nf + 1), f, i;
		for (f = 0; f < nf; f++) {
			var s = 0;
			for (i = f * hop; i < (f + 1) * hop; i++) { s += audio[i] * audio[i]; }
			e[f + 1] = e[f] + s;
		}
		var win = 15, at = 0;
		while (n - at > MAX * rate) {
			var lo = Math.floor((at + MIN * rate) / hop), hi = Math.floor(Math.min(at + MAX * rate, n - TAIL * rate) / hop) - win;
			var best = -1, bestE = Infinity;
			for (f = lo; f <= hi; f++) {
				var en = e[f + win] - e[f];
				if (en < bestE) { bestE = en; best = f; }
			}
			var cut = best < 0 ? at + MAX * rate : (best + Math.floor(win / 2)) * hop;
			out.push([at, cut]);
			at = cut;
		}
		out.push([at, n]);
		return out;
	}

	/*
	 * Where the speech is in audio[a, b) (1.9.1), as [start, end] seconds
	 * from a: 50 ms frames over the louder of 12 dB above the stretch's room
	 * (10th percentile) and 35 dB under its loud parts (95th percentile),
	 * with gaps under 0.3 s joined.
	 */
	function speechSpans(audio, rate, a, b) {
		var w = Math.max(1, Math.round(rate * 0.05)), db = [], f, i;
		for (f = a; f + w <= b; f += w) {
			var s = 0;
			for (i = f; i < f + w; i++) { s += audio[i] * audio[i]; }
			db.push(10 * Math.log10(s / w + 1e-20));
		}
		if (db.length < 4) { return []; }
		var sorted = db.slice().sort(function (x, y) { return x - y; });
		var room = sorted[Math.floor(sorted.length * 0.1)], loud = sorted[Math.floor(sorted.length * 0.95)];
		var thr = Math.max(room + 12, loud - 35), out = [], step = w / rate;
		for (f = 0; f < db.length; f++) {
			if (db[f] <= thr) { continue; }
			var t0 = f * step, t1 = (f + 1) * step;
			if (out.length && t0 - out[out.length - 1][1] < 0.3) { out[out.length - 1][1] = t1; } else { out.push([t0, t1]); }
		}
		return out;
	}
	/*
	 * Speech in a piece that Whisper gave no word for (1.9.1): the speech
	 * spans less what lies within 0.6 s of a word, as { total, longest }
	 * seconds, longest being the longest unbroken stretch (a skipped passage
	 * is one long stretch; breaths between phrases are short ones). A word
	 * timed past the piece's end (Whisper losing its place) means nothing
	 * in the piece can be trusted: all its speech counts as missed.
	 */
	function missedSpeech(spans, words, len) {
		var cover = [], speech = 0, k;
		for (k = 0; k < spans.length; k++) { speech += spans[k][1] - spans[k][0]; }
		for (k = 0; k < words.length; k++) {
			var t = words[k].timestamp || [null, null];
			if (t[0] === null) { continue; }
			if (t[0] > len + 0.5) { return { total: speech, longest: speech }; }
			cover.push([t[0] - 0.6, (t[1] === null ? t[0] : t[1]) + 0.6]);
		}
		cover.sort(function (x, y) { return x[0] - y[0]; });
		var total = 0, longest = 0, run = 0, runEnd = -1;
		spans.forEach(function (sp) {
			/* The parts of this span outside every word's reach, in order. */
			var at = sp[0];
			cover.forEach(function (c) {
				if (c[1] <= at || c[0] >= sp[1]) { return; }
				if (c[0] > at) { gap(at, c[0]); }
				at = Math.max(at, c[1]);
			});
			if (at < sp[1]) { gap(at, sp[1]); }
		});
		/* Uncovered pieces of speech join into one stretch across pauses under 1.5 s. */
		function gap(x, y) {
			total += y - x;
			run = runEnd >= 0 ? (x - runEnd < 1.5 ? run + (y - x) : y - x) : y - x;
			runEnd = y;
			if (run > longest) { longest = run; }
		}
		return { total: total, longest: longest };
	}
	/* The quietest 300 ms of audio[a, b) at least edge seconds from either end, as a sample index; -1 when too short. */
	function quietPoint(audio, rate, a, b, edge) {
		var hop = Math.max(1, Math.round(rate * 0.02)), win = 15, lo = a + Math.round(edge * rate), hi = b - Math.round(edge * rate) - win * hop;
		var best = -1, bestE = Infinity, f, i;
		for (f = lo; f <= hi; f += hop) {
			var e = 0;
			for (i = f; i < f + win * hop; i += 2) { e += audio[i] * audio[i]; }
			if (e < bestE) { bestE = e; best = f; }
		}
		return best < 0 ? -1 : best + Math.floor(win / 2) * hop;
	}

	/*
	 * Hears audio[a, b) (1.9.1) and checks the answer: when a stretch of
	 * over 2.5 s of its speech got no word, or a word is timed past its end, the piece
	 * is cut at its quietest pause (at least 2 s from either end) and each
	 * half heard on its own, two levels deep at most, keeping whichever
	 * answer leaves less speech without words. Whisper can stop after a
	 * phrase and a pause as if the rest were silent: on a real reading it
	 * heard the title, then nothing of the 18 s that followed. Returns
	 * { chunks (times in the whole recording), text, missed (seconds) }.
	 */
	async function hear(asr, audio, rate, a, b, depth) {
		var part = audio.subarray(a, b), off = a / rate, len = (b - a) / rate;
		part.rrtOffset = off;
		part.rrtTotal = audio.length / rate;
		part.rrtLast = b === audio.length;
		var out = await asr(part, { return_timestamps: 'word' });
		var words = out.chunks || [];
		var miss = missedSpeech(speechSpans(audio, rate, a, b), words, len);
		var missed = miss.total;
		var mine = {
			chunks: words.map(function (c) {
				var t = c.timestamp || [null, null];
				function at(v) { return v === null ? null : Math.round((Math.max(0, Math.min(len, v)) + off) * 1000) / 1000; }
				return { text: c.text, timestamp: [at(t[0]), at(t[1])] };
			}),
			text: out.text || '',
			missed: missed
		};
		if (miss.longest <= 2.5 || depth >= 2 || len < 8) { return mine; }
		var cut = quietPoint(audio, rate, a, b, 2);
		if (cut < 0) { return mine; }
		var left = await hear(asr, audio, rate, a, cut, depth + 1);
		var right = await hear(asr, audio, rate, cut, b, depth + 1);
		if (left.missed + right.missed >= missed) { return mine; }
		return { chunks: left.chunks.concat(right.chunks), text: left.text + right.text, missed: left.missed + right.missed };
	}

	var SOURCE = [
		'var lib = null;',
		'var asr = null;',
		'var from = { code: "", model: "", gpu: false };',
		'function report(p) { self.postMessage({ type: "load", p: { status: p.status, file: p.file || "", loaded: p.loaded || 0, total: p.total || 0 } }); }',
		'async function getLib(cfg) {',
		'  if (cfg.lib) {',
		'    try { lib = await import(cfg.lib); from.code = cfg.site; }',
		'    catch (err) { lib = null; }',
		'  }',
		'  if (!lib) { lib = await import(cfg.cdn); from.code = "jsDelivr"; }',
		'  if (from.code === cfg.site && cfg.wasm) {',
		'    var plain = cfg.gpu ? ".asyncify" : (cfg.oldSafari ? true : cfg.lowMem) ? "" : ".asyncify";',
		'    lib.env.backends.onnx.wasm.wasmPaths = { mjs: cfg.wasm + "ort-wasm-simd-threaded" + plain + ".js", wasm: cfg.wasm + "ort-wasm-simd-threaded" + plain + ".wasm" };',
		'  }',
		'}',
		'async function load(cfg) {',
		'  await getLib(cfg);',
		'  var env = lib.env;',
		'  if (cfg.lowMem) { try { env.backends.onnx.wasm.numThreads = 1; } catch (err) {} }',
		'  env.allowLocalModels = false;',
		'  var tries = [];',
		'  if (cfg.host) { tries.push({ host: cfg.host, tpl: "{model}/", model: cfg.model, label: cfg.site }); }',
		'  tries.push({ host: "https://huggingface.co/", tpl: "{model}/resolve/{revision}/", model: cfg.model, label: "Hugging Face" });',
		'  if (!cfg.gpu && cfg.fallback) { tries.push({ host: "https://huggingface.co/", tpl: "{model}/resolve/{revision}/", model: cfg.fallback, label: "Hugging Face" }); }',
		'  var opts = cfg.gpu ? { dtype: { encoder_model: "fp16", decoder_model_merged: "q4f16" }, device: "webgpu" } : { dtype: "q8", device: "wasm" };',
		'  var last = null;',
		'  for (var i = 0; i < tries.length; i++) {',
		'    env.remoteHost = tries[i].host;',
		'    env.remotePathTemplate = tries[i].tpl;',
		'    try {',
		'      var o = { dtype: opts.dtype, device: opts.device, progress_callback: report };',
		'      if (cfg.rev ? (cfg.gpu || !!cfg.language) : false) { o.revision = cfg.rev; }',
		'      var p = await lib.pipeline("automatic-speech-recognition", tries[i].model, o);',
		'      from.model = tries[i].label;',
		'      from.gpu = !!cfg.gpu;',
		'      return p;',
		'    } catch (err) { last = err; }',
		'  }',
		'  throw last;',
		'}',
		pieces.toString(),
		speechSpans.toString(),
		missedSpeech.toString(),
		quietPoint.toString(),
		hear.toString(),
		/* 1.33.0: a reading in another language is heard in that language, and only written down, never translated. */
		'function hearIn(language) {',
		'  if (!language) { return asr; }',
		'  return function (part, o) { return asr(part, Object.assign({}, o, { language: language, task: "transcribe" })); };',
		'}',
		'async function listen(audio, fromS, language) {',
		'  var run = hearIn(language);',
		'  var rate = 16000, cuts = pieces(audio, rate), chunks = [], text = "";',
		'  for (var i = 0; i < cuts.length; i++) {',
		'    if (fromS ? cuts[i][1] <= Math.round(fromS * rate) : false) { continue; }',
		'    var got = await hear(run, audio, rate, cuts[i][0], cuts[i][1], 0);',
		'    chunks = chunks.concat(got.chunks);',
		'    text += got.text;',
		'    self.postMessage({ type: "piece", i: i, n: cuts.length, a: cuts[i][0] / rate, b: cuts[i][1] / rate, chunks: got.chunks });',
		'  }',
		'  return { chunks: chunks, text: text };',
		'}',
		'self.onmessage = async function (e) {',
		'  var d = e.data;',
		'  try {',
		'    if (!asr) { asr = await load(d.cfg); }',
		'    self.postMessage({ type: "working", from: from });',
		'    var out = await listen(d.audio, d.cfg.fromS || 0, d.cfg.language || "");',
		'    self.postMessage({ type: "done", chunks: out.chunks, text: out.text });',
		'  } catch (err) {',
		'    self.postMessage({ type: "error", message: String((err && err.message) || err) });',
		'  }',
		'};'
	].join('\n');

	function start() {
		return new Worker(URL.createObjectURL(new Blob([SOURCE], { type: 'text/javascript' })), { type: 'module' });
	}

	var api = {
		source: SOURCE,
		start: start,
		pieces: pieces,
		speechSpans: speechSpans,
		missedSpeech: missedSpeech,
		quietPoint: quietPoint,
		hear: hear
	};
	root.RRTWhisper = api;
	if (typeof module !== 'undefined') {
		if (module.exports) { module.exports = api; }
	}
})(typeof window !== 'undefined' ? window : this);
