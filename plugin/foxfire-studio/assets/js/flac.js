/*
 * Foxfire Studio: lossless recordings for the take shelf on the site (1.34.0).
 *
 * A take on the shelf is kept as 16-bit mono samples. To keep it on the site
 * as well, it is written as FLAC, which gives back every sample exactly and
 * is usually about half the size of a WAV for a voice. Only what FLAC calls
 * fixed prediction is written (orders 0 to 4, with Rice-coded residuals in
 * partitions), which is quick and needs no outside library. The decoder reads
 * those files, and LPC subframes too, so any mono 16-bit FLAC opens.
 *
 * The same samples always make the same bytes, so a send cut off partway can
 * carry on from where the site stopped. sum() is a short check of the
 * samples, kept with the take, so a damaged download is refused.
 *
 * Works on the page as window.RRTFlac, as a worker (messages 'encode' and
 * 'decode', answered with 'done' or 'error'), and in Node for tests.
 */
(function (root) {
	'use strict';

	var BLOCK = 4096; /* Samples in a frame. */
	var MAX_PART = 8; /* The finest partition order tried. */

	var CRC8 = new Uint8Array(256);
	var CRC16 = new Uint16Array(256);
	(function () {
		for (var i = 0; i < 256; i++) {
			var c = i;
			for (var j = 0; j < 8; j++) { c = (c & 0x80) ? ((c << 1) ^ 0x07) & 0xFF : (c << 1) & 0xFF; }
			CRC8[i] = c;
			var d = i << 8;
			for (var k = 0; k < 8; k++) { d = (d & 0x8000) ? ((d << 1) ^ 0x8005) & 0xFFFF : (d << 1) & 0xFFFF; }
			CRC16[i] = d;
		}
	})();
	function crc8(b, a, z) {
		var c = 0;
		for (var i = a; i < z; i++) { c = CRC8[c ^ b[i]]; }
		return c;
	}
	function crc16(b, a, z) {
		var c = 0;
		for (var i = a; i < z; i++) { c = ((c << 8) & 0xFFFF) ^ CRC16[(c >> 8) ^ b[i]]; }
		return c;
	}

	/* Bits written most significant first. put() takes up to 24 bits at a time. */
	function Writer(size) {
		this.b = new Uint8Array(size);
		this.p = 0;
		this.acc = 0;
		this.n = 0;
	}
	Writer.prototype.put = function (v, n) {
		if (!n) { return; }
		this.acc = (this.acc << n) | v;
		this.n += n;
		while (this.n >= 8) {
			this.n -= 8;
			this.b[this.p++] = (this.acc >>> this.n) & 255;
		}
		this.acc &= (1 << this.n) - 1;
	};
	Writer.prototype.zeros = function (n) {
		while (n > 24) { this.put(0, 24); n -= 24; }
		this.put(0, n);
	};
	Writer.prototype.align = function () {
		if (this.n) { this.put(0, 8 - this.n); }
	};
	Writer.prototype.bytes = function (arr) {
		for (var i = 0; i < arr.length; i++) { this.b[this.p++] = arr[i]; }
	};

	/* Residuals of fixed prediction of this order, for samples a..z of x, into r (from r[0]). */
	function residual(x, a, z, order, r) {
		var i;
		var k = 0;
		switch (order) {
		case 0: for (i = a; i < z; i++) { r[k++] = x[i]; } break;
		case 1: for (i = a + 1; i < z; i++) { r[k++] = x[i] - x[i - 1]; } break;
		case 2: for (i = a + 2; i < z; i++) { r[k++] = x[i] - 2 * x[i - 1] + x[i - 2]; } break;
		case 3: for (i = a + 3; i < z; i++) { r[k++] = x[i] - 3 * x[i - 1] + 3 * x[i - 2] - x[i - 3]; } break;
		default: for (i = a + 4; i < z; i++) { r[k++] = x[i] - 4 * x[i - 1] + 6 * x[i - 2] - 4 * x[i - 3] + x[i - 4]; } break;
		}
		return k;
	}
	function zig(v) { return v >= 0 ? v * 2 : -v * 2 - 1; }

	/* The Rice parameter for a partition of m values whose zigzag values add to s, and its cost in bits (close, from the sum). */
	function riceGuess(s, m) {
		if (!m) { return { k: 0, bits: 4 }; }
		var mean = s / m;
		var k = mean > 1 ? Math.floor(Math.log(mean) / Math.LN2) : 0;
		if (k > 14) { k = 14; }
		return { k: k, bits: 4 + m * (k + 1) + Math.floor(s / Math.pow(2, k)) };
	}
	/* The exact best Rice parameter near the guess, for values u[a..z). */
	function riceExact(u, a, z, k0) {
		var best = -1;
		var bestBits = Infinity;
		for (var k = Math.max(0, k0 - 1); k <= Math.min(14, k0 + 1); k++) {
			var bits = (z - a) * (k + 1);
			var d = Math.pow(2, k);
			for (var i = a; i < z; i++) { bits += Math.floor(u[i] / d); }
			if (bits < bestBits) { bestBits = bits; best = k; }
		}
		return best;
	}

	/* The plan for one block at one order: partition order, parameters, and bits. */
	function plan(u, m, n, order) {
		var best = null;
		var top = 0;
		while (top < MAX_PART ? ((n >> (top + 1)) << (top + 1)) === n ? (n >> (top + 1)) > order : false : false) { top++; }
		for (var p = 0; p <= top; p++) {
			var parts = 1 << p;
			var size = n >> p;
			var bits = 6;
			var ks = [];
			var at = 0;
			for (var j = 0; j < parts; j++) {
				var cnt = j === 0 ? size - order : size;
				var s = 0;
				for (var i = at; i < at + cnt; i++) { s += u[i]; }
				var g = riceGuess(s, cnt);
				ks.push(g.k);
				bits += g.bits;
				at += cnt;
			}
			if (!best || bits < best.bits) { best = { p: p, ks: ks, bits: bits }; }
		}
		return best;
	}

	/* Writes a frame header for a block of n samples, frame number f. */
	function header(w, n, f) {
		var start = w.p;
		w.put(0xFFF8 >> 8, 8);
		w.put(0xF8, 8);
		w.put(0x7, 4); /* Block size: 16 bits at the end of the header. */
		w.put(0x0, 4); /* Rate: from the stream's details. */
		w.put(0x0, 4); /* One channel. */
		w.put(0x4, 3); /* 16 bits. */
		w.put(0, 1);
		/* The frame number, coded as UTF-8 is. */
		if (f < 0x80) {
			w.put(f, 8);
		} else {
			var bytes = [];
			var room = 0x3F;
			while (f > room) {
				bytes.unshift(0x80 | (f & 0x3F));
				f = Math.floor(f / 64);
				room >>= 1;
			}
			bytes.unshift(((0xFF << (7 - bytes.length)) & 0xFF) | f);
			w.bytes(bytes);
		}
		w.put(n - 1, 16);
		w.put(crc8(w.b, start, w.p), 8);
	}

	/* One block as a subframe: constant, fixed prediction, or the samples as they are, whichever is smallest. */
	function subframe(w, x, a, n, r, u) {
		var same = true;
		for (var i = a + 1; i < a + n ? same : false; i++) { same = x[i] === x[a]; }
		if (same) {
			w.put(0, 8);
			w.put(x[a] & 0xFFFF, 16);
			return;
		}
		var bestOrder = -1;
		var bestPlan = null;
		for (var order = 0; order <= 4; order++) {
			if (n <= order) { break; }
			var m = residual(x, a, a + n, order, r);
			for (var k = 0; k < m; k++) { u[k] = zig(r[k]); }
			var pl = plan(u, m, n, order);
			pl.bits += order * 16;
			if (!bestPlan || pl.bits < bestPlan.bits) { bestPlan = pl; bestOrder = order; }
		}
		if (!bestPlan || bestPlan.bits >= n * 16) {
			w.put(2, 8); /* Verbatim: type 1, shifted past the padding bit. */
			for (var v = a; v < a + n; v++) { w.put(x[v] & 0xFFFF, 16); }
			return;
		}
		var mm = residual(x, a, a + n, bestOrder, r);
		for (var q = 0; q < mm; q++) { u[q] = zig(r[q]); }
		w.put(0, 1);
		w.put(8 + bestOrder, 6);
		w.put(0, 1);
		for (var o = 0; o < bestOrder; o++) { w.put(x[a + o] & 0xFFFF, 16); }
		w.put(0, 2); /* Rice with 4-bit parameters. */
		w.put(bestPlan.p, 4);
		var parts = 1 << bestPlan.p;
		var size = n >> bestPlan.p;
		var at = 0;
		for (var j = 0; j < parts; j++) {
			var cnt = j === 0 ? size - bestOrder : size;
			var kk = riceExact(u, at, at + cnt, bestPlan.ks[j]);
			w.put(kk, 4);
			var d = 1 << kk;
			for (var t = at; t < at + cnt; t++) {
				var val = u[t];
				var hi = Math.floor(val / d);
				w.zeros(hi);
				w.put(1, 1);
				w.put(val & (d - 1), kk);
			}
			at += cnt;
		}
	}

	/*
	 * Encodes 16-bit mono samples. pcm: Int16Array; rate: samples a second.
	 * Returns the FLAC file as a Uint8Array.
	 */
	function encode(pcm, rate) {
		var total = pcm.length;
		var frames = Math.max(1, Math.ceil(total / BLOCK));
		var w = new Writer(total * 2 + frames * 40 + 64);
		w.bytes([0x66, 0x4C, 0x61, 0x43]);
		/* STREAMINFO, the last metadata block, 34 bytes. */
		w.put(0x80, 8);
		w.put(34, 24);
		w.put(BLOCK, 16);
		w.put(BLOCK, 16);
		w.put(0, 24);
		w.put(0, 24);
		w.put(rate >> 4, 16);
		w.put(rate & 15, 4);
		w.put(0, 3);
		w.put(15, 5);
		/* 36 bits of the sample count. */
		w.put(Math.floor(total / 4294967296) & 15, 4);
		w.put((total >>> 16) & 0xFFFF, 16);
		w.put(total & 0xFFFF, 16);
		for (var z = 0; z < 16; z++) { w.put(0, 8); } /* No MD5: sum() is kept with the take in its place. */
		var r = new Int32Array(BLOCK);
		var u = new Float64Array(BLOCK);
		for (var f = 0; f * BLOCK < total; f++) {
			var a = f * BLOCK;
			var n = Math.min(BLOCK, total - a);
			var start = w.p;
			header(w, n, f);
			subframe(w, pcm, a, n, r, u);
			w.align();
			var c = crc16(w.b, start, w.p);
			w.put(c >> 8, 8);
			w.put(c & 255, 8);
		}
		return w.b.slice(0, w.p);
	}

	/* Bits read most significant first. */
	function Reader(b, p) {
		this.b = b;
		this.p = p;
		this.acc = 0;
		this.n = 0;
	}
	Reader.prototype.get = function (n) {
		if (!n) { return 0; }
		while (this.n < n) {
			if (this.p >= this.b.length) { throw new Error('the file ends early'); }
			this.acc = (this.acc << 8) | this.b[this.p++];
			this.n += 8;
		}
		this.n -= n;
		var v = (this.acc >>> this.n) & ((1 << n) - 1);
		this.acc &= (1 << this.n) - 1;
		return v;
	};
	Reader.prototype.big = function (n) {
		var v = 0;
		while (n > 16) { v = v * 65536 + this.get(16); n -= 16; }
		return v * Math.pow(2, n) + this.get(n);
	};
	Reader.prototype.signed = function (n) {
		if (!n) { return 0; }
		var v = n > 24 ? this.big(n) : this.get(n);
		var half = Math.pow(2, n - 1);
		return v >= half ? v - half * 2 : v;
	};
	Reader.prototype.unary = function () {
		var q = 0;
		for (;;) {
			if (!this.n) {
				if (this.p >= this.b.length) { throw new Error('the file ends early'); }
				this.acc = this.b[this.p++];
				this.n = 8;
			}
			if (!this.acc) { q += this.n; this.n = 0; continue; }
			var top = 31 - Math.clz32(this.acc);
			q += this.n - 1 - top;
			this.n = top;
			this.acc &= (1 << top) - 1;
			return q;
		}
	};
	Reader.prototype.align = function () { this.n = 0; this.acc = 0; };

	function readResidual(rd, out, at, n, order) {
		var method = rd.get(2);
		if (method > 1) { throw new Error('an unknown residual coding'); }
		var pbits = method ? 5 : 4;
		var esc = method ? 31 : 15;
		var p = rd.get(4);
		var parts = 1 << p;
		var size = n >> p;
		var k = at + order;
		for (var j = 0; j < parts; j++) {
			var cnt = j === 0 ? size - order : size;
			var param = rd.get(pbits);
			if (param === esc) {
				var raw = rd.get(5);
				for (var i = 0; i < cnt; i++) { out[k++] = rd.signed(raw); }
			} else {
				var d = Math.pow(2, param);
				for (var t = 0; t < cnt; t++) {
					var v = rd.unary() * d + rd.get(param);
					out[k++] = (v % 2) ? -(v + 1) / 2 : v / 2;
				}
			}
		}
	}

	/* Reads one subframe of n samples into out from at, as 16-bit values. */
	function readSubframe(rd, out, at, n, bps) {
		if (rd.get(1)) { throw new Error('a damaged subframe'); }
		var type = rd.get(6);
		var wasted = 0;
		if (rd.get(1)) { wasted = rd.unary() + 1; }
		var b = bps - wasted;
		var i;
		var j;
		if (type === 0) {
			var c = rd.signed(b);
			for (i = 0; i < n; i++) { out[at + i] = c; }
		} else if (type === 1) {
			for (i = 0; i < n; i++) { out[at + i] = rd.signed(b); }
		} else if (type >= 8 && type <= 12) {
			var order = type - 8;
			for (i = 0; i < order; i++) { out[at + i] = rd.signed(b); }
			readResidual(rd, out, at, n, order);
			for (i = at + order; i < at + n; i++) {
				switch (order) {
				case 1: out[i] += out[i - 1]; break;
				case 2: out[i] += 2 * out[i - 1] - out[i - 2]; break;
				case 3: out[i] += 3 * out[i - 1] - 3 * out[i - 2] + out[i - 3]; break;
				case 4: out[i] += 4 * out[i - 1] - 6 * out[i - 2] + 4 * out[i - 3] - out[i - 4]; break;
				default: break;
				}
			}
		} else if (type >= 32) {
			var lo = (type & 31) + 1;
			for (i = 0; i < lo; i++) { out[at + i] = rd.signed(b); }
			var prec = rd.get(4) + 1;
			var shift = rd.signed(5);
			var co = [];
			for (i = 0; i < lo; i++) { co.push(rd.signed(prec)); }
			readResidual(rd, out, at, n, lo);
			for (i = at + lo; i < at + n; i++) {
				var s = 0;
				for (j = 0; j < lo; j++) { s += co[j] * out[i - 1 - j]; }
				out[i] += Math.floor(s / Math.pow(2, shift));
			}
		} else {
			throw new Error('an unknown subframe');
		}
		if (wasted) { for (i = at; i < at + n; i++) { out[i] *= Math.pow(2, wasted); } }
	}

	/* Decodes a mono 16-bit FLAC file. Returns { pcm: Int16Array, rate }. */
	function decode(bytes) {
		var b = bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes);
		if (b.length < 42 || b[0] !== 0x66 || b[1] !== 0x4C || b[2] !== 0x61 || b[3] !== 0x43) { throw new Error('not a FLAC file'); }
		var p = 4;
		var rate = 0;
		var total = 0;
		var bps = 16;
		var maxBlock = BLOCK;
		var last = false;
		while (!last) {
			var rd0 = new Reader(b, p);
			last = !!rd0.get(1);
			var type = rd0.get(7);
			var len = rd0.get(24);
			if (type === 0) {
				var rs = new Reader(b, p + 4);
				rs.get(16);
				maxBlock = rs.get(16);
				rs.get(24);
				rs.get(24);
				rate = rs.get(20);
				if (rs.get(3) !== 0) { throw new Error('only one channel is read'); }
				bps = rs.get(5) + 1;
				total = rs.big(36);
			}
			p += 4 + len;
			if (p > b.length) { throw new Error('the file ends early'); }
		}
		if (bps !== 16) { throw new Error('only 16-bit files are read'); }
		var out = new Int32Array(total || maxBlock * 16);
		var at = 0;
		while (p + 2 <= b.length ? (total ? at < total : true) : false) {
			var start = p;
			if (b[p] !== 0xFF || (b[p + 1] & 0xFE) !== 0xF8) { throw new Error('a frame is out of place'); }
			var rd = new Reader(b, p + 2);
			var bsCode = rd.get(4);
			var rCode = rd.get(4);
			if (rd.get(4) !== 0) { throw new Error('only one channel is read'); }
			var sCode = rd.get(3);
			rd.get(1);
			if (sCode !== 0 && sCode !== 4) { throw new Error('only 16-bit files are read'); }
			/* The frame or sample number, coded as UTF-8 is. */
			var first = rd.get(8);
			var more = 0;
			while (first & (0x80 >> more)) { more++; }
			for (var m = 1; m < more; m++) { rd.get(8); }
			var n;
			if (bsCode === 1) { n = 192; } else if (bsCode >= 2 && bsCode <= 5) { n = 576 << (bsCode - 2); } else if (bsCode === 6) { n = rd.get(8) + 1; } else if (bsCode === 7) { n = rd.get(16) + 1; } else if (bsCode >= 8) { n = 256 << (bsCode - 8); } else { throw new Error('a damaged frame'); }
			if (rCode === 12) { rd.get(8); } else if (rCode === 13 || rCode === 14) { rd.get(16); }
			var hEnd = rd.p;
			if (rd.get(8) !== crc8(b, start, hEnd)) { throw new Error('a damaged frame header'); }
			if (at + n > out.length) {
				var grown = new Int32Array(Math.max(out.length * 2, at + n));
				grown.set(out);
				out = grown;
			}
			readSubframe(rd, out, at, n, 16);
			rd.align();
			var fEnd = rd.p;
			var c = rd.get(16);
			if (c !== crc16(b, start, fEnd)) { throw new Error('a damaged frame'); }
			at += n;
			p = rd.p;
		}
		if (total ? at !== total : false) { throw new Error('the file ends early'); }
		var pcm = new Int16Array(at);
		for (var i = 0; i < at; i++) { pcm[i] = out[i]; }
		return { pcm: pcm, rate: rate };
	}

	/*
	 * A short check of 16-bit samples (two 32-bit FNV-1a hashes and the
	 * count), so a recording can be told apart from a damaged copy of it.
	 */
	function sum(pcm) {
		var h1 = 0x811C9DC5;
		var h2 = 0x01000193 ^ 0x5BD1E995;
		for (var i = 0; i < pcm.length; i++) {
			var v = pcm[i] & 0xFFFF;
			h1 = Math.imul(h1 ^ (v & 255), 0x01000193) >>> 0;
			h1 = Math.imul(h1 ^ (v >> 8), 0x01000193) >>> 0;
			h2 = Math.imul(h2 ^ v, 0x01000193) >>> 0;
		}
		function hex(x) { return ('0000000' + x.toString(16)).slice(-8); }
		return hex(h1) + hex(h2) + '-' + pcm.length;
	}

	var api = { BLOCK: BLOCK, encode: encode, decode: decode, sum: sum, _internal: { crc8: crc8, crc16: crc16, zig: zig } };

	/* As a worker: { cmd: 'encode', pcm: ArrayBuffer of Int16, rate } or { cmd: 'decode', flac: ArrayBuffer }. */
	var isWorker = typeof root.importScripts === 'function' ? typeof root.document === 'undefined' : false;
	if (isWorker) {
		root.onmessage = function (e) {
			var d = e.data || {};
			try {
				if (d.cmd === 'encode') {
					var pcm = new Int16Array(d.pcm);
					var out = encode(pcm, d.rate);
					root.postMessage({ type: 'done', id: d.id, flac: out.buffer, sum: sum(pcm) }, [out.buffer]);
				} else if (d.cmd === 'decode') {
					var r = decode(new Uint8Array(d.flac));
					root.postMessage({ type: 'done', id: d.id, pcm: r.pcm.buffer, rate: r.rate, sum: sum(r.pcm) }, [r.pcm.buffer]);
				}
			} catch (err) {
				root.postMessage({ type: 'error', id: d.id, message: String(err && err.message ? err.message : err) });
			}
		};
	} else {
		root.RRTFlac = api;
	}
	if (typeof module !== 'undefined') {
		if (module.exports) { module.exports = api; }
	}
})(typeof self !== 'undefined' ? self : (typeof window !== 'undefined' ? window : this));
