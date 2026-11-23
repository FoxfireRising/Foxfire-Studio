/*
 * Foxfire Studio: voice following's listener (1.14.0), a Web Worker. It runs
 * a small streaming speech model (sherpa-onnx, in WebAssembly, on this
 * device) on the microphone's audio and says what it has heard so far. The
 * audio never leaves the browser and nothing is kept.
 *
 * Messages in:  { type: 'init', cfg }  cfg: engine (folder of the engine's
 *               files), v (its version, to keep caches fresh), files
 *               (the model's file names), names (which file is which
 *               part), hosts (folders to try for them, in order, each
 *               with a label), bytes (about how big, for the progress)
 *               { type: 'pcm', pcm: Float32Array, rate }
 *               { type: 'reset' }  (a new take: forget what was heard)
 * Messages out: { type: 'load', got, total }, { type: 'ready', from },
 *               { type: 'heard', done: [finished pieces since last time],
 *               p: the piece still being heard, at: seconds taken in },
 *               { type: 'error', message }
 */
/* global importScripts, createOnlineRecognizer */
var rec = null;
var stream = null;
var taken = 0;
var lastP = '';
var cfgNow = null;

function post(m) { self.postMessage(m); }

/* Fetches one file with progress, as bytes. */
function getFile(url, onBytes) {
	return fetch(url, { credentials: 'omit' }).then(function (r) {
		if (!r.ok) { throw new Error('HTTP ' + r.status); }
		if (!r.body || !r.body.getReader) { return r.arrayBuffer().then(function (b) { onBytes(b.byteLength); return new Uint8Array(b); }); }
		var reader = r.body.getReader(), parts = [], n = 0;
		function pump() {
			return reader.read().then(function (x) {
				if (x.done) {
					var out = new Uint8Array(n), o = 0;
					parts.forEach(function (p) { out.set(p, o); o += p.length; });
					return out;
				}
				parts.push(x.value);
				n += x.value.length;
				onBytes(x.value.length);
				return pump();
			});
		}
		return pump();
	});
}

/* The model's files from the first host that has them all. */
function getModel(cfg) {
	var total = cfg.bytes || 0, got = 0, i = 0;
	function tryHost() {
		if (i >= cfg.hosts.length) { return Promise.reject(new Error('the model could not be downloaded')); }
		var h = cfg.hosts[i++];
		var files = {}, startGot = got;
		return cfg.files.reduce(function (p, name) {
			return p.then(function () {
				return getFile(h.url + name, function (b) { got += b; post({ type: 'load', got: got, total: total }); }).then(function (bytes) { files[name] = bytes; });
			});
		}, Promise.resolve()).then(function () { return { files: files, from: h.label }; }, function () { got = startGot; return tryHost(); });
	}
	return tryHost();
}

function init(cfg) {
	cfgNow = cfg;
	var model = null;
	var ready = new Promise(function (res, rej) {
		self.Module = {
			locateFile: function (p) { return cfg.engine + p + (cfg.v ? '?v=' + cfg.v : ''); },
			/* The engine's own build carries a demo model; it is never fetched, as this model replaces it. */
			getPreloadedPackage: function () { return new ArrayBuffer(0); },
			setStatus: function () {},
			print: function () {},
			printErr: function () {},
			onRuntimeInitialized: function () { res(); },
			onAbort: function (w) { rej(new Error(String(w || 'the speech engine stopped'))); }
		};
	});
	try {
		var q = cfg.v ? '?v=' + cfg.v : '';
		importScripts(cfg.engine + 'sherpa-onnx-asr.js' + q, cfg.engine + 'sherpa-onnx-wasm-main-asr.js' + q);
	} catch (e) {
		post({ type: 'error', message: 'the speech engine could not load (' + (e && e.message ? e.message : e) + ')' });
		return;
	}
	Promise.all([ready, getModel(cfg).then(function (m) { model = m; })]).then(function () {
		var M = self.Module;
		M.FS_createPath('/', 'follow', true, true);
		Object.keys(model.files).forEach(function (n) { M.FS_createDataFile('/follow', n, model.files[n], true, false, false); });
		model.files = null;
		var f = cfg.names;
		rec = createOnlineRecognizer(M, {
			featConfig: { sampleRate: 16000, featureDim: 80 },
			modelConfig: {
				transducer: { encoder: '/follow/' + f.encoder, decoder: '/follow/' + f.decoder, joiner: '/follow/' + f.joiner },
				paraformer: { encoder: '', decoder: '' },
				zipformer2Ctc: { model: '' },
				nemoCtc: { model: '' },
				toneCtc: { model: '' },
				tokens: '/follow/' + f.tokens,
				numThreads: 1,
				provider: 'cpu',
				debug: 0,
				modelType: '',
				modelingUnit: 'cjkchar',
				bpeVocab: ''
			},
			decodingMethod: 'greedy_search',
			maxActivePaths: 4,
			enableEndpoint: 1,
			rule1MinTrailingSilence: 2.4,
			rule2MinTrailingSilence: 1.0,
			rule3MinUtteranceLength: 20,
			hotwordsFile: '',
			hotwordsScore: 1.5,
			ctcFstDecoderConfig: { graph: '', maxActive: 3000 },
			ruleFsts: '',
			ruleFars: ''
		});
		stream = rec.createStream();
		post({ type: 'ready', from: model.from });
	}).catch(function (e) {
		post({ type: 'error', message: String(e && e.message ? e.message : e) });
	});
}

function feed(pcm, rate) {
	if (!rec) { return; }
	stream.acceptWaveform(rate, pcm);
	taken += pcm.length / rate;
	while (rec.isReady(stream)) { rec.decode(stream); }
	var p = String(rec.getResult(stream).text || '').trim();
	var done = [];
	if (rec.isEndpoint(stream)) {
		if (p) { done.push(p); }
		rec.reset(stream);
		p = '';
	}
	if (done.length || p !== lastP) {
		lastP = p;
		post({ type: 'heard', done: done, p: p, at: taken });
	} else {
		post({ type: 'heard', done: null, p: null, at: taken });
	}
}

self.onmessage = function (e) {
	var d = e.data;
	if (d.type === 'init') { init(d.cfg); }
	else if (d.type === 'pcm') {
		try { feed(d.pcm, d.rate); } catch (err) { post({ type: 'error', message: String(err && err.message ? err.message : err) }); rec = null; }
	} else if (d.type === 'reset') {
		if (rec) {
			try { stream.free(); } catch (err) {}
			stream = rec.createStream();
		}
		taken = 0;
		lastP = '';
	}
};
