/*
 * Foxfire Studio: full-length video (1.18.0).
 *
 * Turns a whole published reading into a wide 1080p video for YouTube,
 * Spotify and other apps that show video: the clip maker's picture (a picture
 * softened behind, the card, the show, the title and the moving waveform),
 * the chapter being read, the captions a phrase at a time, a progress line
 * and the clip maker's end card.
 *
 * Everything runs in the browser. The video is made with WebCodecs (H.264)
 * and AAC sound (the browser's own encoder, or since 1.30.0 the AAC add-on),
 * and packed by Mediabunny (RRTClip.mp4Writer) straight into the browser's
 * own private storage as it goes, so a long reading never
 * has to sit in memory; it downloads when it is ready. The loop waits on the
 * encoder itself and never on a timer, because Chrome slows a page's timers
 * to about once a second when its window is not in front.
 *
 * Beside it, the upload kit: a description with the tagged link and YouTube
 * chapter times, the captions file, and a thumbnail.
 *
 * window.RRTVideo.mount(el, boot) builds the section; it mounts itself on any
 * [data-rrt-video] element, with its boot from #rrt-video-boot. The helpers at
 * the top are pure functions, also used by the tests in Node.
 */
(function (root) {
	'use strict';

	/* Translations: WordPress's wp.i18n when the page has it, otherwise the English text as written. */
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

	var V = {};

	/* Frames a second, and a keyframe every second (as Spotify asks). */
	V.FPS = 30;
	V.W = 1920;
	V.H = 1080;
	/* The end card, after the reading, in seconds. */
	V.END = 3;
	/* The sound: AAC-LC stereo, the voice in both sides. */
	V.AUDIO_BPS = 192000;
	/* Files this maker leaves in the browser's private storage start with this. */
	V.PREFIX = 'rrt-video-';

	/*
	 * ---------------------------------------------------------------------
	 * Pure helpers
	 * ---------------------------------------------------------------------
	 */

	/* Seconds from a WebVTT time: hh:mm:ss.mmm or mm:ss.mmm. */
	function vttTime(s) {
		var p = String(s).trim().replace(',', '.').split(':');
		var n = 0;
		for (var i = 0; i < p.length; i++) { n = n * 60 + parseFloat(p[i]); }
		return isFinite(n) ? n : NaN;
	}

	/*
	 * The cues of a WebVTT file, in time order: start, end and plain text (tags
	 * and editor's brackets removed, lines joined). A cue that ends within half
	 * a second of the next one's start is held until it, so captions never
	 * flicker off between phrases.
	 */
	V.cues = function (text) {
		var blocks = String(text || '').replace(/\r\n?/g, '\n').split(/\n{2,}/);
		var out = [];
		blocks.forEach(function (b) {
			var lines = b.split('\n');
			for (var i = 0; i < lines.length; i++) {
				var m = /^\s*([\d:.,]+)\s*-->\s*([\d:.,]+)/.exec(lines[i]);
				if (!m) { continue; }
				var s = vttTime(m[1]);
				var e = vttTime(m[2]);
				var t = lines.slice(i + 1).join(' ').replace(/<[^>]*>/g, '').replace(/[\[\]{}]/g, '').replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/\s+/g, ' ').trim();
				if (isFinite(s) && isFinite(e) && e > s && t) { out.push({ s: s, e: e, text: t }); }
				break;
			}
		});
		out.sort(function (a, b) { return a.s - b.s; });
		for (var k = 0; k < out.length - 1; k++) {
			if (out[k + 1].s - out[k].e < 0.5) { out[k].e = Math.max(out[k].e, out[k + 1].s); }
		}
		return out;
	};

	/* The index of the item showing at time t (items in time order with s and e), or -1. */
	V.at = function (list, t) {
		var lo = 0;
		var hi = list.length - 1;
		var found = -1;
		while (lo <= hi) {
			var mid = (lo + hi) >> 1;
			if (list[mid].s <= t) { found = mid; lo = mid + 1; } else { hi = mid - 1; }
		}
		return found >= 0 && t < list[found].e ? found : -1;
	};

	/* The chapter being read at time t (chapters by start), or null. */
	V.chapterAt = function (chapters, t) {
		var c = null;
		for (var i = 0; i < chapters.length; i++) {
			if (chapters[i].start <= t + 0.001) { c = chapters[i]; }
		}
		return c;
	};

	/* "4:05" or "1:02:09", as YouTube writes chapter times. */
	V.timecode = function (sec) {
		sec = Math.max(0, Math.floor(sec + 1e-6));
		var h = Math.floor(sec / 3600);
		var m = Math.floor((sec % 3600) / 60);
		var s = sec % 60;
		var ss = (s < 10 ? '0' : '') + s;
		return h ? h + ':' + (m < 10 ? '0' : '') + m + ':' + ss : m + ':' + ss;
	};

	/*
	 * Chapter lines for a YouTube description, or [] when the reading's
	 * chapters cannot make a list YouTube accepts: the first at 0:00, at least
	 * three, each at least 10 seconds long. A first chapter after the start
	 * gets an opening line at 0:00; a chapter that would start within 10 s of
	 * the one before it is left out (the one before runs on through it).
	 */
	V.ytChapters = function (chapters, len, opening) {
		var list = (chapters || []).filter(function (c) { return c && isFinite(c.start) && c.start < len && String(c.title || '').trim(); })
			.map(function (c) { return { start: Math.max(0, +c.start), title: String(c.title).replace(/\s+/g, ' ').trim() }; })
			.sort(function (a, b) { return a.start - b.start; });
		if (!list.length) { return []; }
		if (list[0].start >= 1) { list.unshift({ start: 0, title: opening }); } else { list[0].start = 0; }
		var kept = [list[0]];
		for (var i = 1; i < list.length; i++) {
			if (list[i].start - kept[kept.length - 1].start >= 10) { kept.push(list[i]); }
		}
		while (kept.length > 1 && len - kept[kept.length - 1].start < 10) { kept.pop(); }
		if (kept.length < 3) { return []; }
		return kept.map(function (c) { return V.timecode(c.start) + ' ' + c.title; });
	};

	/* The post's address tagged for where the video goes: the place in the short tag, "video" as the campaign. */
	V.link = function (postUrl, param, where) {
		var u;
		try { u = new URL(postUrl); } catch (e) { return postUrl; }
		u.searchParams.set(param || 'src', where);
		u.searchParams.set('utm_campaign', 'video');
		return u.toString();
	};

	/*
	 * The description for the upload: the post's excerpt, the tagged link to
	 * the post, and the chapter times when YouTube would accept them.
	 *
	 * @param b     The boot: excerpt, link, src, chapters.
	 * @param where youtube or spotify.
	 * @param len   The reading's length in seconds.
	 */
	V.description = function (b, where, len) {
		var parts = [];
		if (b.excerpt) { parts.push(b.excerpt); }
		parts.push(sprintf( /* translators: %s: the post's address. */ __( 'Read the post: %s', 'foxfire-studio' ), V.link(b.link, b.src, where) ));
		var ch = V.ytChapters(b.chapters, len, __( 'Opening', 'foxfire-studio' ));
		if (ch.length) { parts.push(ch.join('\n')); }
		return parts.join('\n\n');
	};

	/* About how big the video will be, in bytes, for a reading of len seconds. */
	V.estimate = function (len, videoBps) {
		return Math.round((len + V.END) * (videoBps + V.AUDIO_BPS) / 8 * 1.03);
	};

	/* "12.3 MB" or "1.2 GB". */
	V.size = function (bytes) {
		if (bytes >= 1e9) { return sprintf( /* translators: %s: size in gigabytes. */ __( '%s GB', 'foxfire-studio' ), (bytes / 1e9).toFixed(1) ); }
		return sprintf( /* translators: %s: size in megabytes. */ __( '%s MB', 'foxfire-studio' ), (bytes / 1e6).toFixed(bytes < 1e8 ? 1 : 0) );
	};

	/* The downloaded file's name. */
	V.fileName = function (slug) {
		var s = String(slug || '').toLowerCase().replace(/[^a-z0-9-]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 80);
		return (s || 'reading') + '-video.mp4';
	};

	/* Words into lines no wider than maxW, measured by measure(text). */
	V.wrap = function (measure, text, maxW) {
		var words = String(text).split(/\s+/).filter(Boolean);
		var lines = [];
		var line = '';
		words.forEach(function (w) {
			var test = line ? line + ' ' + w : w;
			if (line && measure(test) > maxW) { lines.push(line); line = w; } else { line = test; }
		});
		if (line) { lines.push(line); }
		return lines;
	};

	/*
	 * The largest caption size that fits a phrase in maxLines lines; at the
	 * smallest size, the last line ends in an ellipsis if the phrase still runs
	 * over. measure(text, size) gives a width.
	 */
	V.fitCaption = function (measure, text, maxW, sizes, maxLines) {
		var lines;
		for (var i = 0; i < sizes.length; i++) {
			var sz = sizes[i];
			lines = V.wrap(function (t) { return measure(t, sz); }, text, maxW);
			if (lines.length <= maxLines) { return { size: sz, lines: lines }; }
		}
		var small = sizes[sizes.length - 1];
		lines = lines.slice(0, maxLines);
		var last = lines[maxLines - 1];
		while (last && measure(last + '…', small) > maxW) { last = last.replace(/\s*\S+$/, ''); }
		lines[maxLines - 1] = (last || '') + '…';
		return { size: small, lines: lines };
	};

	/* One line cut to fit maxW with an ellipsis. */
	V.cut = function (measure, text, maxW) {
		text = String(text);
		if (measure(text) <= maxW) { return text; }
		while (text && measure(text + '…') > maxW) { text = text.slice(0, -1); }
		return text.replace(/\s+$/, '') + '…';
	};

	/*
	 * ---------------------------------------------------------------------
	 * Drawing
	 * ---------------------------------------------------------------------
	 */

	function hexA(hex, a) {
		var h = String(hex || '#000000').replace('#', '');
		if (h.length === 3) { h = h.charAt(0) + h.charAt(0) + h.charAt(1) + h.charAt(1) + h.charAt(2) + h.charAt(2); }
		var n = parseInt(h, 16) || 0;
		return 'rgba(' + ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + a + ')';
	}

	/* Caption sizes tried, largest first, and where captions and the chapter go (the clip maker's wide layout). */
	var CAP_SIZES = [60, 54, 48, 44];
	var CAP = { x: 1340, y: 770, w: 1000 };
	var CHAP = { x: 860, y: 452, w: 960 };

	/*
	 * One frame at time t. The clip maker draws the picture, waveform,
	 * progress line and end card (with no caption groups); the chapter and the
	 * phrase go on top while the reading runs.
	 */
	function drawFrame(x, o, st, t, f) {
		root.RRTClip._drawFrame(x, o, 'wide', st, t, f);
		if (t >= o.len) { return; }
		var P = o.palette;
		x.save();
		x.textBaseline = 'alphabetic';
		x.textAlign = 'left';
		var ch = st.chapters.length ? V.chapterAt(st.chapters, t) : null;
		if (ch) {
			if (!ch.shown) {
				x.font = '600 30px ' + o.fonts.interface;
				ch.shown = V.cut(function (s) { return x.measureText(s).width; }, ch.title, CHAP.w);
			}
			x.font = '600 30px ' + o.fonts.interface;
			x.fillStyle = hexA(P.accent, 0.9);
			x.fillText(ch.shown, CHAP.x, CHAP.y);
		}
		var k = st.cues.length ? V.at(st.cues, t) : -1;
		if (k >= 0) {
			var c = st.cues[k];
			if (!c.fit) {
				c.fit = V.fitCaption(function (s, sz) { x.font = '600 ' + sz + 'px ' + o.fonts.reading; return x.measureText(s).width; }, c.text, CAP.w, CAP_SIZES, 2);
			}
			var sz = c.fit.size;
			x.font = '600 ' + sz + 'px ' + o.fonts.reading;
			x.textAlign = 'center';
			x.globalAlpha = Math.max(0, Math.min(1, (t - c.s + 0.05) / 0.15));
			x.shadowColor = 'rgba(0,0,0,0.65)';
			x.shadowBlur = 16;
			x.fillStyle = P.text;
			var n = c.fit.lines.length;
			c.fit.lines.forEach(function (line, li) {
				x.fillText(line, CAP.x, CAP.y + (li - (n - 1) / 2) * sz * 1.18);
			});
		}
		x.restore();
	}
	V._drawFrame = drawFrame;

	/* The thumbnail: the picture softened behind, the card, the show and the title large, at 1280 by 720. */
	V.thumbnail = function (o, img) {
		var bare = {};
		Object.keys(o).forEach(function (k) { bare[k] = o[k]; });
		bare.title = '';
		bare.domain = '';
		var big = root.RRTClip._staticLayer(bare, 'wide', img);
		var x = big.getContext('2d');
		x.textAlign = 'left';
		x.textBaseline = 'alphabetic';
		x.fillStyle = o.palette.text;
		var size = 100;
		var lines;
		do {
			x.font = size + 'px ' + o.fonts.headings;
			lines = V.wrap(function (s) { return x.measureText(s).width; }, o.title, 960);
			size -= 6;
		} while (lines.length > 3 && size > 50);
		size += 6;
		x.font = size + 'px ' + o.fonts.headings;
		lines.slice(0, 3).forEach(function (line, i) { x.fillText(line, 860, 360 + size + i * size * 1.15); });
		var cv = document.createElement('canvas');
		cv.width = 1280;
		cv.height = 720;
		var y = cv.getContext('2d');
		y.imageSmoothingQuality = 'high';
		y.drawImage(big, 0, 0, 1280, 720);
		return new Promise(function (res) { cv.toBlob(res, 'image/jpeg', 0.9); });
	};

	/*
	 * ---------------------------------------------------------------------
	 * Making the video
	 * ---------------------------------------------------------------------
	 */

	/* Whether this browser can make a video here (1.30.0: RRTCan's answer when it is loaded). */
	V.supported = function () {
		if (root.RRTCan) { return root.RRTCan.now().video && !!root.Mediabunny && !!root.RRTClip; }
		return !!(root.VideoEncoder && root.VideoFrame && root.WebAssembly && root.Mediabunny && root.RRTClip &&
			root.navigator && root.navigator.storage && root.navigator.storage.getDirectory &&
			root.FileSystemFileHandle && root.FileSystemFileHandle.prototype.createWritable);
	};

	/* The first encoder setting the browser accepts: the graphics chip's encoder first, High profile first. */
	function pickVideo(bps) {
		var tries = [];
		['prefer-hardware', 'no-preference'].forEach(function (hw) {
			['avc1.640028', 'avc1.4d0028', 'avc1.42e028'].forEach(function (codec) {
				tries.push({ codec: codec, width: V.W, height: V.H, bitrate: bps, framerate: V.FPS, hardwareAcceleration: hw, latencyMode: 'quality', avc: { format: 'avc' } });
			});
		});
		/* 1.30.0: Mediabunny stores frames that arrive out of display order, so the first accepted setting is used. */
		return root.RRTClip.firstSupported(tries);
	}

	/* AAC where it can be made here (the browser's own, or the add-on), otherwise Opus (RRTClip.pickSound). */
	function pickAudio() {
		return root.RRTClip.pickSound(2, V.AUDIO_BPS, 160000);
	}

	/* A turn for the page between batches of frames, with no timer (timers slow to once a second behind other windows). */
	function yieldNow() {
		return new Promise(function (res) {
			var c = new MessageChannel();
			c.port1.onmessage = function () { c.port1.close(); res(); };
			c.port2.postMessage(0);
		});
	}

	/* Until the encoder's waiting list is at most max long. */
	function drain(enc, max) {
		if (enc.encodeQueueSize <= max) { return Promise.resolve(); }
		return new Promise(function (res) {
			var done = false;
			function check() {
				if (done) { return; }
				if (enc.encodeQueueSize <= max || 'configured' !== enc.state) {
					done = true;
					enc.removeEventListener('dequeue', check);
					res();
				}
			}
			enc.addEventListener('dequeue', check);
			check();
		});
	}

	/* Removes this maker's files from the browser's private storage. */
	V.clearStore = function () {
		if (!root.navigator || !root.navigator.storage || !root.navigator.storage.getDirectory) { return Promise.resolve(0); }
		return root.navigator.storage.getDirectory().then(function (dir) {
			var names = [];
			var it = dir.keys();
			function walk() {
				return it.next().then(function (r) {
					if (r.done) { return null; }
					if (0 === String(r.value).indexOf(V.PREFIX)) { names.push(r.value); }
					return walk();
				});
			}
			return walk().then(function () {
				return Promise.all(names.map(function (n) { return dir.removeEntry(n).catch(function () { return null; }); }));
			}).then(function () { return names.length; });
		}).catch(function () { return 0; });
	};

	/*
	 * Makes the video into the browser's private storage.
	 *
	 * @param o    palette, fonts, title, show, domain, endLine, len (the reading's length).
	 * @param st   bg (the static picture), env (loudness per frame), cues, chapters, left and right (Float32 at 48 kHz).
	 * @param opts bps (picture bits a second), name (the stored file's name), cancelled() (true to stop).
	 * @param onProgress Called with 0 to 1.
	 * @return {file, bytes, sound, hw, secs}
	 */
	V.render = function (o, st, opts, onProgress) {
		var fps = V.FPS;
		var total = o.len + V.END;
		var frames = Math.round(total * fps);
		var rate = 48000;
		var need = Math.round(total * rate);
		var reading = Math.round(o.len * rate);
		var cv = document.createElement('canvas');
		cv.width = V.W;
		cv.height = V.H;
		var x = cv.getContext('2d', { alpha: false });
		var t0 = Date.now();
		var handle = null;
		var ws = null;
		var dir = null;
		var vcfg = null;
		var acfg = null;
		return Promise.all([pickVideo(opts.bps), pickAudio(), root.navigator.storage.getDirectory()]).then(function (r) {
			vcfg = r[0];
			acfg = r[1];
			dir = r[2];
			return dir.getFileHandle(opts.name, { create: true });
		}).then(function (h) {
			handle = h;
			return h.createWritable();
		}).then(function (w) {
			ws = w;
			var failed = null;
			var writer = root.RRTClip.mp4Writer({ fps: fps, sound: acfg, stream: ws, chunkSize: 8 * 1024 * 1024 }, function (e) { failed = failed || e; });
			var venc = new root.VideoEncoder({ output: writer.addVideoChunk, error: function (e) { failed = failed || e; } });
			venc.configure(vcfg);

			var aPos = 0;
			function feedAudio(untilSec) {
				var until = Math.min(need, Math.round(untilSec * rate));
				while (aPos < until) {
					var nf = Math.min(1024, until - aPos);
					var d = new Float32Array(nf * 2);
					for (var i = 0; i < nf; i++) {
						var k = aPos + i;
						if (k < reading) {
							d[i] = k < st.left.length ? st.left[k] : 0;
							d[nf + i] = k < st.right.length ? st.right[k] : 0;
						}
					}
					writer.addAudio(d, nf, 2, rate, aPos / rate);
					aPos += nf;
				}
			}

			var f = 0;
			function step() {
				if (failed) { return Promise.reject(failed); }
				if (opts.cancelled && opts.cancelled()) { return Promise.reject(new Error('cancelled')); }
				var until = Math.min(frames, f + 15);
				for (; f < until; f++) {
					drawFrame(x, o, st, f / fps, f);
					var vf = new root.VideoFrame(cv, { timestamp: Math.round(f * 1e6 / fps), duration: Math.round(1e6 / fps) });
					venc.encode(vf, { keyFrame: f % fps === 0 });
					vf.close();
				}
				feedAudio(f / fps);
				if (onProgress) { onProgress(f / frames); }
				if (f >= frames) { return Promise.resolve(); }
				/* The writer's queue is waited on too, so a slow disk holds the page back (1.30.0). */
				return drain(venc, 8).then(function () {
					return writer.pending > 60 ? writer.idle() : null;
				}).then(yieldNow).then(step);
			}
			return step().then(function () {
				feedAudio(total);
				return venc.flush();
			}).then(function () {
				return writer.settle(frames);
			}).then(function () {
				if (failed) { throw failed; }
				venc.close();
				/* Mediabunny closes the stored file when it finishes. */
				return writer.finish(frames);
			}).then(function () {
				ws = null;
				return handle.getFile();
			}).then(function (file) {
				return root.RRTClip.fileEditLists(file).then(function (edits) {
					return { file: file, bytes: file.size, sound: acfg.sound, addOn: acfg.addOn, hw: vcfg.hardwareAcceleration, codec: vcfg.codec, frames: frames, lost: writer.lost, edits: edits, secs: (Date.now() - t0) / 1000 };
				});
			}, function (err) {
				try { if ('closed' !== venc.state) { venc.close(); } } catch (e1) { /* already closed */ }
				return writer.cancel().then(function () { throw err; });
			});
		}).catch(function (err) {
			var clean = ws ? ws.abort().catch(function () { return null; }) : Promise.resolve();
			return clean.then(function () {
				return dir && handle ? dir.removeEntry(opts.name).catch(function () { return null; }) : null;
			}).then(function () { throw err; });
		});
	};

	/*
	 * ---------------------------------------------------------------------
	 * The section on a reading's page
	 * ---------------------------------------------------------------------
	 */

	function el(tag, cls, text) {
		var e = document.createElement(tag);
		if (cls) { e.className = cls; }
		if (undefined !== text && null !== text) { e.textContent = text; }
		return e;
	}

	function loadImage(url) {
		return new Promise(function (resolve) {
			if (!url) { resolve(null); return; }
			var im = new Image();
			im.crossOrigin = 'anonymous';
			im.onload = function () { resolve(im); };
			im.onerror = function () { resolve(null); };
			im.src = url;
		});
	}

	function download(blob, name) {
		var a = document.createElement('a');
		var url = URL.createObjectURL(blob);
		a.href = url;
		a.download = name;
		document.body.appendChild(a);
		a.click();
		a.parentNode.removeChild(a);
		root.setTimeout(function () { URL.revokeObjectURL(url); }, 60000);
	}

	function mmss(s) {
		s = Math.max(0, Math.round(s));
		return Math.floor(s / 60) + ':' + (s % 60 < 10 ? '0' : '') + (s % 60);
	}

	V.mount = function (host, boot) {
		host.innerHTML = '';
		var o = {
			palette: boot.palette,
			fonts: boot.fonts,
			title: boot.title || '',
			show: boot.show || '',
			domain: boot.domain || root.location.hostname,
			endLine: __( 'Hear the whole reading at', 'foxfire-studio' ),
			len: 0
		};
		var picture = { url: boot.image || boot.cover || '' };
		var source = null;
		var cues = null;
		var busy = false;
		var stop = false;
		var made = null;

		/* Leftovers from an earlier visit (the download was long finished) go first. */
		V.clearStore();

		/* The video. */
		var make = el('div', 'rrt-video-step');
		host.appendChild(make);
		var row = el('div', 'rrt-video-row');
		make.appendChild(row);

		var picLabel = el('label', '', __( 'Picture', 'foxfire-studio' ) + ' ');
		var pic = el('select');
		pic.id = 'rrt-video-picture';
		function addOpt(sel, value, text) { var op = el('option', '', text); op.value = value; sel.appendChild(op); return op; }
		if (boot.image) { addOpt(pic, 'post', __( 'This post\'s featured image', 'foxfire-studio' )); }
		if (boot.cover) { addOpt(pic, 'cover', __( 'The show\'s cover art', 'foxfire-studio' )); }
		addOpt(pic, 'none', __( 'No picture (the look\'s colours)', 'foxfire-studio' ));
		if (boot.choose && root.wp && root.wp.media) { addOpt(pic, 'choose', __( 'Choose from the Media Library...', 'foxfire-studio' )); }
		pic.value = boot.image ? 'post' : (boot.cover ? 'cover' : 'none');
		picLabel.appendChild(pic);
		row.appendChild(picLabel);

		var capLabel = el('label');
		var capBox = el('input');
		capBox.type = 'checkbox';
		capBox.id = 'rrt-video-captions';
		capBox.checked = !!boot.captions;
		capBox.disabled = !boot.captions;
		capLabel.appendChild(capBox);
		capLabel.appendChild(document.createTextNode(' ' + __( 'Captions on the picture', 'foxfire-studio' )));
		row.appendChild(capLabel);

		var qLabel = el('label', '', __( 'Quality', 'foxfire-studio' ) + ' ');
		var q = el('select');
		q.id = 'rrt-video-quality';
		addOpt(q, 'standard', __( 'Standard (5 Mbps)', 'foxfire-studio' ));
		addOpt(q, 'smaller', __( 'Smaller file (2.5 Mbps)', 'foxfire-studio' ));
		qLabel.appendChild(q);
		row.appendChild(qLabel);

		var note = el('p', 'rrt-small rrt-video-note');
		make.appendChild(note);
		if (!boot.captions) {
			make.appendChild(el('p', 'rrt-small', __( 'This reading has no captions, so the video has none on the picture. Captions come with a reading put on the post with Use this recording in the studio.', 'foxfire-studio' )));
		}

		var go = el('button', 'button button-primary rrt-video-make', __( 'Make the video', 'foxfire-studio' ));
		go.type = 'button';
		var cancel = el('button', 'button rrt-video-cancel', __( 'Cancel', 'foxfire-studio' ));
		cancel.type = 'button';
		cancel.hidden = true;
		var btns = el('div', 'rrt-video-row');
		btns.appendChild(go);
		btns.appendChild(cancel);
		make.appendChild(btns);
		var prog = el('progress', 'rrt-video-prog');
		prog.max = 1;
		prog.value = 0;
		prog.hidden = true;
		make.appendChild(prog);
		var status = el('p', 'rrt-video-status');
		status.setAttribute('role', 'status');
		make.appendChild(status);
		var result = el('div', 'rrt-video-result');
		make.appendChild(result);

		function bps() { return boot.qualities && boot.qualities[q.value] ? boot.qualities[q.value] : (q.value === 'smaller' ? 2500000 : 5000000); }
		function showNote() {
			if (!o.len) { note.textContent = ''; return; }
			note.textContent = sprintf( /* translators: 1: the reading's length (m:ss), 2: the video's size. */ __( 'The reading runs %1$s, so the video will be at most about %2$s (a still picture usually needs less). It takes a few minutes on most computers; keep this tab open while it is made.', 'foxfire-studio' ), mmss(o.len), V.size(V.estimate(o.len, bps())) );
		}
		q.addEventListener('change', showNote);

		pic.addEventListener('change', function () {
			if ('choose' !== pic.value) {
				picture.url = 'post' === pic.value ? boot.image : ('cover' === pic.value ? boot.cover : '');
				return;
			}
			var frame = root.wp.media({ title: __( 'A picture for the video', 'foxfire-studio' ), library: { type: 'image' }, multiple: false, button: { text: __( 'Use this picture', 'foxfire-studio' ) } });
			frame.on('select', function () {
				var a = frame.state().get('selection').first().toJSON();
				var sz = a.sizes && a.sizes.large ? a.sizes.large : a;
				picture.url = sz.url || a.url;
				var op = pic.querySelector('option[value="picked"]') || addOpt(pic, 'picked', '');
				op.textContent = sprintf( /* translators: %s: the picture's title. */ __( 'Chosen: %s', 'foxfire-studio' ), a.title || a.filename || '' );
				pic.insertBefore(op, pic.querySelector('option[value="choose"]'));
				pic.value = 'picked';
			});
			frame.on('close', function () { if ('choose' === pic.value) { pic.value = picture.url === boot.image ? 'post' : (picture.url === boot.cover ? 'cover' : 'none'); } });
			frame.open();
		});

		/* The reading's sound, decoded once. */
		function loadSource() {
			if (source) { return Promise.resolve(source); }
			return fetch(boot.audio, { credentials: 'same-origin' }).then(function (r) {
				if (!r.ok) { throw new Error(sprintf( /* translators: %d: HTTP status. */ __( 'the reading could not be loaded (%d)', 'foxfire-studio' ), r.status )); }
				return r.arrayBuffer();
			}).then(function (ab) {
				var Ctx = root.AudioContext || root.webkitAudioContext;
				var ac = new Ctx({ sampleRate: 48000 });
				return ac.decodeAudioData(ab).then(function (buf) {
					if (ac.close) { ac.close(); }
					source = { left: buf.getChannelData(0), right: buf.numberOfChannels > 1 ? buf.getChannelData(1) : buf.getChannelData(0), len: buf.duration };
					return source;
				});
			});
		}
		function loadCues() {
			if (cues || !boot.captions) { return Promise.resolve(cues || []); }
			return fetch(boot.captions, { credentials: 'same-origin' }).then(function (r) { return r.ok ? r.text() : ''; }).then(function (t) {
				cues = V.cues(t);
				return cues;
			}, function () { cues = []; return cues; });
		}

		/* The length is known from the player on the page without loading the whole file. */
		(function () {
			var a = new Audio();
			a.preload = 'metadata';
			a.addEventListener('loadedmetadata', function () { if (isFinite(a.duration)) { o.len = a.duration; showNote(); } });
			a.src = boot.audio;
		})();

		function fontsReady() {
			if (!document.fonts || !document.fonts.load) { return Promise.resolve(); }
			return Promise.all([
				document.fonts.load('600 60px ' + o.fonts.reading),
				document.fonts.load('60px ' + o.fonts.headings),
				document.fonts.load('600 30px ' + o.fonts.interface)
			]).catch(function () { return null; });
		}

		function leaving(e) {
			if (!busy) { return undefined; }
			e.preventDefault();
			e.returnValue = '';
			return '';
		}
		root.addEventListener('beforeunload', leaving);

		/* 1.30.0: the message names the missing tool and the browsers that have it (RRTCan). */
		if (!V.supported()) {
			go.disabled = true;
			status.textContent = (root.RRTCan ? root.RRTCan.why('video') : '') || __( 'This browser cannot make the video here. Chrome, Edge, Safari and Firefox on a computer can.', 'foxfire-studio' );
		} else if (root.RRTCan) {
			root.RRTCan.check().then(function (a) {
				var why = root.RRTCan.why('video', a);
				if (why) { go.disabled = true; status.textContent = why; }
			});
		}

		cancel.addEventListener('click', function () { stop = true; status.textContent = __( 'Stopping...', 'foxfire-studio' ); });

		go.addEventListener('click', function () {
			if (busy) { return; }
			busy = true;
			stop = false;
			go.disabled = true;
			cancel.hidden = false;
			prog.hidden = false;
			prog.value = 0;
			result.innerHTML = '';
			if (made) { URL.revokeObjectURL(made.url); made = null; }
			status.textContent = __( 'Loading the reading...', 'foxfire-studio' );
			var name = V.PREFIX + Date.now() + '.mp4';
			var started = 0;
			V.clearStore().then(function () {
				return Promise.all([loadSource(), loadCues(), loadImage(picture.url), fontsReady()]);
			}).then(function (r) {
				var src = r[0];
				o.len = src.len;
				showNote();
				var want = V.estimate(src.len, bps());
				return (root.navigator.storage.estimate ? root.navigator.storage.estimate() : Promise.resolve(null)).then(function (est) {
					if (est && est.quota && est.quota - (est.usage || 0) < want * 1.2) {
						throw new Error(sprintf( /* translators: %s: the video's size. */ __( 'this computer does not have room for it: the video needs about %s free', 'foxfire-studio' ), V.size(want * 1.2) ));
					}
					var st = {
						bg: root.RRTClip._staticLayer(o, 'wide', r[2]),
						env: root.RRTClip.envelope(src.left, 48000, 0, src.len + V.END, V.FPS),
						cues: capBox.checked ? r[1].map(function (c) { return { s: c.s, e: c.e, text: c.text }; }) : [],
						chapters: (boot.chapters || []).map(function (c) { return { start: +c.start, title: String(c.title) }; }).sort(function (a, b) { return a.start - b.start; }),
						groups: [],
						left: src.left,
						right: src.right
					};
					started = Date.now();
					status.textContent = __( 'Making the video...', 'foxfire-studio' );
					return V.render(o, st, { bps: bps(), name: name, cancelled: function () { return stop; } }, function (p) {
						prog.value = p;
						var el2 = (Date.now() - started) / 1000;
						if (p > 0.02 && el2 > 3) {
							status.textContent = sprintf( /* translators: 1: percent done, 2: time left (m:ss). */ __( 'Making the video: %1$s%% done, about %2$s left. Keep this tab open.', 'foxfire-studio' ), Math.floor(p * 100), mmss(el2 / p - el2) );
						}
					});
				});
			}).then(function (res) {
				var fname = V.fileName(boot.slug);
				made = { file: res.file, url: URL.createObjectURL(res.file), name: fname };
				download(res.file, fname);
				prog.value = 1;
				status.textContent = sprintf( /* translators: 1: file name, 2: size, 3: time taken (m:ss). */ __( 'Your video is ready and downloading as %1$s (%2$s, made in %3$s).', 'foxfire-studio' ), fname, V.size(res.bytes), mmss(res.secs) );
				var card = el('div', 'rrt-video-card');
				/*
				 * 1.31.0: a phone shows no player for the full video. Safari on the
				 * iPhone would not play a 76 MB video inside the page (a slashed
				 * play button), though the file itself was fine.
				 */
				var onPhone = root.RRTCan ? root.RRTCan.phone() : false;
				if (onPhone) {
					card.appendChild(el('p', 'rrt-video-phone', __( 'Your video is in Files, Downloads (or your browser’s downloads). Open it there to watch it.', 'foxfire-studio' )));
				} else {
					var vid = el('video');
					vid.controls = true;
					vid.preload = 'metadata';
					vid.src = made.url;
					card.appendChild(vid);
				}
				var again = el('button', 'button rrt-video-again', __( 'Download again', 'foxfire-studio' ));
				again.type = 'button';
				again.addEventListener('click', function () { download(made.file, made.name); });
				card.appendChild(again);
				if (res.lost) {
					card.appendChild(el('p', 'rrt-small', sprintf( /* translators: 1: frames the encoder did not return, 2: frames in all. */ _n( 'The encoder skipped %1$d of %2$s frames; the frame before it stays on screen a moment longer, and the sound is unaffected.', 'The encoder skipped %1$d of %2$s frames; the frames before them stay on screen a moment longer, and the sound is unaffected.', res.lost, 'foxfire-studio' ), res.lost, res.frames.toLocaleString() )));
				}
				if ('opus' === res.sound) {
					card.appendChild(el('p', 'rrt-video-warn', __( 'The sound was made as Opus, because the AAC encoder did not load. YouTube and Spotify want AAC: reload the page and make the video again.', 'foxfire-studio' )));
				}
				card.appendChild(el('p', 'rrt-small', __( 'The video stays in this browser until you leave this page, so Download again works until then.', 'foxfire-studio' )));
				result.appendChild(card);
			}).catch(function (err) {
				var msg = err && err.message ? err.message : String(err);
				status.textContent = 'cancelled' === msg ? __( 'Stopped. Nothing was kept.', 'foxfire-studio' ) : sprintf( /* translators: %s: the reason. */ __( 'The video could not be made: %s.', 'foxfire-studio' ), msg );
				prog.hidden = true;
			}).then(function () {
				busy = false;
				go.disabled = false;
				cancel.hidden = true;
			});
		});

		/* The upload kit. */
		var kit = el('div', 'rrt-video-step rrt-video-kit');
		host.appendChild(kit);
		kit.appendChild(el('h4', '', __( 'For the upload', 'foxfire-studio' )));
		var dRow = el('div', 'rrt-video-row');
		var forLabel = el('label', '', __( 'Description for', 'foxfire-studio' ) + ' ');
		var where = el('select');
		where.id = 'rrt-video-where';
		addOpt(where, 'youtube', 'YouTube');
		addOpt(where, 'spotify', 'Spotify');
		forLabel.appendChild(where);
		dRow.appendChild(forLabel);
		var copy = el('button', 'button', __( 'Copy', 'foxfire-studio' ));
		copy.type = 'button';
		dRow.appendChild(copy);
		var copied = el('span', 'rrt-small rrt-video-copied');
		dRow.appendChild(copied);
		kit.appendChild(dRow);
		var desc = el('textarea', 'rrt-video-desc');
		desc.id = 'rrt-video-desc';
		desc.setAttribute('aria-label', __( 'Description for the upload', 'foxfire-studio' ));
		desc.rows = 8;
		desc.readOnly = true;
		kit.appendChild(desc);
		var chNote = el('p', 'rrt-small');
		kit.appendChild(chNote);
		function fillDesc() {
			var len = o.len || 0;
			desc.value = V.description(boot, where.value, len || 1e9);
			var ch = V.ytChapters(boot.chapters, len || 1e9, '');
			chNote.textContent = ch.length ? __( 'The chapter times become chapters on YouTube.', 'foxfire-studio' ) : (boot.chapters && boot.chapters.length ? __( 'This reading\'s chapters are too few or too short for YouTube\'s chapters (it needs at least three, each 10 seconds or more), so they are left out.', 'foxfire-studio' ) : __( 'This reading has no chapters. Headings in the post become chapters when the reading is made with the Whisper review on.', 'foxfire-studio' ));
		}
		where.addEventListener('change', fillDesc);
		copy.addEventListener('click', function () {
			var done = function () { copied.textContent = __( 'Copied.', 'foxfire-studio' ); root.setTimeout(function () { copied.textContent = ''; }, 2500); };
			if (root.navigator.clipboard && root.navigator.clipboard.writeText) {
				root.navigator.clipboard.writeText(desc.value).then(done, function () { desc.select(); document.execCommand('copy'); done(); });
			} else { desc.select(); document.execCommand('copy'); done(); }
		});
		fillDesc();
		var lenWatch = root.setInterval(function () { if (o.len) { fillDesc(); root.clearInterval(lenWatch); } }, 500);

		var files = el('div', 'rrt-video-row');
		if (boot.captions) {
			var capA = el('a', 'button', __( 'Download the captions (VTT)', 'foxfire-studio' ));
			capA.href = boot.captions;
			capA.setAttribute('download', V.fileName(boot.slug).replace(/-video\.mp4$/, '-captions.vtt'));
			capA.id = 'rrt-video-vtt';
			files.appendChild(capA);
		}
		var thumbBtn = el('button', 'button', __( 'Download the thumbnail', 'foxfire-studio' ));
		thumbBtn.type = 'button';
		thumbBtn.id = 'rrt-video-thumb';
		files.appendChild(thumbBtn);
		kit.appendChild(files);
		kit.appendChild(el('p', 'rrt-small', __( 'In YouTube Studio: upload the video, paste the description, add the captions under Subtitles, and the thumbnail under Thumbnail. In Spotify for Creators: open the episode and upload the video to it, so listeners there can watch as well as listen.', 'foxfire-studio' )));
		thumbBtn.addEventListener('click', function () {
			thumbBtn.disabled = true;
			Promise.all([loadImage(picture.url), fontsReady()]).then(function (r) {
				return V.thumbnail(o, r[0]);
			}).then(function (blob) {
				if (blob) { download(blob, V.fileName(boot.slug).replace(/-video\.mp4$/, '-thumbnail.jpg')); }
			}).then(function () { thumbBtn.disabled = false; }, function () { thumbBtn.disabled = false; });
		});
	};

	root.RRTVideo = V;
	if (typeof module === 'object' && module.exports) { module.exports = V; }

	if (typeof document !== 'undefined' && document.querySelector && !(typeof module === 'object' && module.exports)) {
		var start = function () {
			var host = document.querySelector('[data-rrt-video]');
			var data = document.getElementById('rrt-video-boot');
			if (!host || !data) { return; }
			var boot;
			try { boot = JSON.parse(data.textContent); } catch (e) { return; }
			V.mount(host, boot);
		};
		if ('loading' === document.readyState) { document.addEventListener('DOMContentLoaded', start); } else { start(); }
	}
})(typeof window !== 'undefined' ? window : globalThis);
