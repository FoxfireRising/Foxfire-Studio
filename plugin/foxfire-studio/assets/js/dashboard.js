/*
 * Foxfire Studio dashboard (0.11.0): charts, sorting, CSV downloads and the
 * link builder. Everything is drawn here from data printed on the page, so
 * the dashboard makes no outside requests.
 *
 * Charts follow a few rules: one y axis, thin 2px lines, a legend whenever
 * there are two or more series, a crosshair with a tooltip listing every
 * series, and a table view of the same numbers under every chart.
 */
(function () {
	'use strict';

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

	var SVG = 'http://www.w3.org/2000/svg';

	function el(name, attrs, parent) {
		var e = document.createElementNS(SVG, name);
		Object.keys(attrs || {}).forEach(function (k) { e.setAttribute(k, attrs[k]); });
		if (parent) { parent.appendChild(e); }
		return e;
	}
	function h(tag, cls, text, parent) {
		var e = document.createElement(tag);
		if (cls) { e.className = cls; }
		if (text !== undefined && text !== null) { e.textContent = text; }
		if (parent) { parent.appendChild(e); }
		return e;
	}
	function clock(s) {
		s = Math.max(0, Math.round(s));
		return Math.floor(s / 60) + ':' + (s % 60 < 10 ? '0' : '') + (s % 60);
	}
	/* A round step (1, 2, 2.5 or 5 times a power of ten) for about four gridlines. */
	function niceStep(range) {
		if (range <= 0) { return 1; }
		var raw = range / 4;
		var p = Math.pow(10, Math.floor(Math.log10(raw)));
		var n = raw / p;
		var step = n <= 1 ? 1 : n <= 2 ? 2 : n <= 2.5 ? 2.5 : n <= 5 ? 5 : 10;
		return step * p;
	}

	/*
	 * Long daily series become a daily average for each week, so the shape
	 * shows instead of day-to-day noise. Markers move to their week.
	 */
	function weekly(d) {
		var n = d.x ? d.x.length : 0;
		if (d.type !== 'line' || d.small || n <= 70) { return d; }
		var out = { type: 'line', unit: d.unit, weekly: true, x: [], series: [], markers: [] };
		for (var i = 0; i < n; i += 7) { out.x.push(sprintf( /* translators: 1: d.x[i], 2: n - i < 7 ? sprintf( _n( ' (%s day so far)', ' (%s days so f. */ __( 'Week of %1$s%2$s', 'foxfire-studio' ), d.x[i], n - i < 7 ? sprintf( /* translators: %s: n - i. */ _n( ' (%s day so far)', ' (%s days so far)', n - i, 'foxfire-studio' ), n - i ) : '' )); }
		d.series.forEach(function (s) {
			var v = [];
			for (var i = 0; i < n; i += 7) {
				/* A daily average, so a week still in progress does not look like a fall. */
				var sum = 0, days = 0;
				for (var j = i; j < Math.min(n, i + 7); j++) { if (s.values[j] !== null) { sum += s.values[j]; days++; } }
				v.push(days ? Math.round(sum / days * 10) / 10 : null);
			}
			out.series.push({ name: s.name, values: v, faint: s.faint });
		});
		(d.markers || []).forEach(function (m) { out.markers.push({ i: Math.floor(m.i / 7), label: m.label }); });
		return out;
	}

	function fmt(v, d) {
		if (v === null || v === undefined) { return '–'; }
		if (d.pct) { return Math.round(v * 100) + '%'; }
		var r = Math.abs(v) >= 100 ? Math.round(v) : Math.round(v * 10) / 10;
		return String(r) + (d.unit && d.unit !== 'plays' ? ' ' + d.unit : '');
	}

	function xLabel(d, i) {
		if (d.type === 'curve') { return clock(i * (d.step || 5)); }
		return d.x ? d.x[i] : String(i);
	}

	function cueAt(d, i) {
		if (!d.cues) { return ''; }
		var t = i * (d.step || 5) + (d.step || 5) / 2;
		for (var k = 0; k < d.cues.length; k++) {
			if (d.cues[k].s <= t && d.cues[k].e >= t - (d.step || 5) / 2) { return d.cues[k].t; }
		}
		return '';
	}

	function draw(fig, d) {
		var series = d.series || [];
		var n = 0;
		series.forEach(function (s) { n = Math.max(n, s.values.length); });
		fig.innerHTML = '';
		if (!n) { h('p', 'rrt-empty', __( 'Nothing to show yet.', 'foxfire-studio' ), fig); return; }

		/* Legend, for two or more series; the title names a single one. */
		if (series.length > 1) {
			var lg = h('div', 'rrt-legend', null, fig);
			series.forEach(function (s, i) {
				var it = h('span', 'rrt-legend-item' + (s.faint ? ' is-faint' : ''), null, lg);
				var sw = h('span', 'rrt-swatch s' + (s.faint ? 'f' : i + 1), null, it);
				sw.setAttribute('aria-hidden', 'true');
				h('span', '', s.name, it);
			});
		}

		var W = Math.max(280, fig.clientWidth || 600);
		var H = d.small ? 150 : 260;
		var L = 46, R = 12, T = 12, B = 26;
		var svg = el('svg', { width: W, height: H, viewBox: '0 0 ' + W + ' ' + H, class: 'rrt-svg', role: 'img', 'aria-label': fig.getAttribute('aria-label') || '' }, fig);

		var max = 0, min = 0;
		series.forEach(function (s) { s.values.forEach(function (v) { if (v !== null) { max = Math.max(max, v); min = Math.min(min, v); } }); });
		if (d.band) {
			if (d.band.max !== null) { max = Math.max(max, d.band.max); }
			if (d.band.min !== null) { max = Math.max(max, d.band.min); }
		}
		var step;
		if (d.pct) {
			min = 0; max = 1; step = 0.25;
		} else if (d.small) {
			/* Trend charts zoom to the values, padded, so small changes show. */
			var lo = Infinity, hi = -Infinity;
			series.forEach(function (s) { s.values.forEach(function (v) { if (v !== null) { lo = Math.min(lo, v); hi = Math.max(hi, v); } }); });
			if (d.band) {
				if (d.band.min !== null) { lo = Math.min(lo, d.band.min); hi = Math.max(hi, d.band.min); }
				if (d.band.max !== null) { lo = Math.min(lo, d.band.max); hi = Math.max(hi, d.band.max); }
			}
			if (!isFinite(lo)) { lo = 0; hi = 1; }
			var pad = Math.max((hi - lo) * 0.15, Math.abs(hi) * 0.05, 0.5);
			step = niceStep(hi - lo + 2 * pad);
			min = Math.floor((lo - pad) / step) * step;
			max = Math.ceil((hi + pad) / step) * step;
			if (lo >= 0) { min = Math.max(0, min); }
		} else {
			step = niceStep(Math.max(max, 1) * 1.05);
			min = 0;
			max = Math.ceil(Math.max(max, 1) * 1.05 / step) * step;
		}
		var pw = W - L - R, ph = H - T - B;
		function X(i) { return L + (n === 1 ? pw / 2 : (i / (n - 1)) * pw); }
		function Y(v) { return T + ph - ((v - min) / ((max - min) || 1)) * ph; }

		/* Recessive grid and y labels. */
		var g = el('g', { class: 'rrt-grid' }, svg);
		for (var v = min; v <= max + step / 1000; v += step) {
			var y = Y(v);
			el('line', { x1: L, x2: W - R, y1: y, y2: y }, g);
			var t = el('text', { x: L - 6, y: y + 4, 'text-anchor': 'end', class: 'rrt-axis' }, g);
			t.textContent = d.pct ? Math.round(v * 100) + '%' : String(Math.round(v * 100) / 100);
		}
		/* X labels: about six. */
		var every = Math.max(1, Math.ceil(n / (d.small ? 3 : 7)));
		for (var i = 0; i < n; i += every) {
			var tx = el('text', { x: X(i), y: H - 6, 'text-anchor': 'middle', class: 'rrt-axis' }, svg);
			tx.textContent = d.weekly ? d.x[i].replace('Week of ', '').replace(/ \(.*\)$/, '') : xLabel(d, i);
		}

		/* Goal band. */
		if (d.band) {
			var y1 = d.band.max !== null ? Y(Math.min(max, d.band.max)) : T;
			var y2 = d.band.min !== null ? Y(Math.max(min, d.band.min)) : T + ph;
			el('rect', { x: L, y: y1, width: pw, height: Math.max(1, y2 - y1), class: 'rrt-band' }, svg);
		}

		/* Change markers and drop-off points. */
		(d.markers || []).forEach(function (m) {
			var mx = X(m.i);
			el('line', { x1: mx, x2: mx, y1: T, y2: T + ph, class: 'rrt-marker' }, svg);
			var right = mx > L + pw * 0.75;
			var f = el('text', { x: right ? mx - 3 : mx + 3, y: T + 10, 'text-anchor': right ? 'end' : 'start', class: 'rrt-marker-label' }, svg);
			f.textContent = '⚑ ' + m.label;
		});
		(d.drops || []).forEach(function (dr) {
			var dx = X(dr.bucket);
			el('line', { x1: dx, x2: dx, y1: T, y2: T + ph, class: 'rrt-drop' }, svg);
			var dt = el('text', { x: dx + 3, y: T + ph - 4, class: 'rrt-drop-label' }, svg);
			dt.textContent = '−' + Math.round(dr.fall * 100) + '%';
		});

		/* Lines: previous period and other faint series first, under the rest. */
		var order = series.map(function (s, i) { return i; }).sort(function (a, b) { return (series[b].faint ? 1 : 0) - (series[a].faint ? 1 : 0); });
		order.forEach(function (si) {
			var s = series[si];
			var dstr = '';
			var pen = false;
			s.values.forEach(function (val, j) {
				if (val === null) { pen = false; return; }
				dstr += (pen ? 'L' : 'M') + X(j).toFixed(1) + ' ' + Y(val).toFixed(1) + ' ';
				pen = true;
			});
			el('path', { d: dstr, class: 'rrt-line ' + (s.faint ? 'sf' : 's' + (si + 1)) }, svg);
			/* Single points (no neighbours) get a dot so they show. */
			if (d.small || n < 3) {
				s.values.forEach(function (val, j) {
					if (val !== null) { el('circle', { cx: X(j), cy: Y(val), r: 3.5, class: 'rrt-dot ' + (s.faint ? 'sf' : 's' + (si + 1)) }, svg); }
				});
			}
		});

		/* Crosshair and tooltip. */
		var cross = el('line', { y1: T, y2: T + ph, class: 'rrt-cross', visibility: 'hidden' }, svg);
		var tip = h('div', 'rrt-tip', null, fig);
		tip.hidden = true;
		var hit = el('rect', { x: L, y: T, width: pw, height: ph, fill: 'transparent', tabindex: '0', class: 'rrt-hit' }, svg);
		var cur = -1;
		function show(i) {
			cur = Math.max(0, Math.min(n - 1, i));
			var cx = X(cur);
			cross.setAttribute('x1', cx); cross.setAttribute('x2', cx); cross.setAttribute('visibility', 'visible');
			tip.innerHTML = '';
			h('div', 'rrt-tip-x', xLabel(d, cur), tip);
			series.forEach(function (s, si) {
				var row = h('div', 'rrt-tip-row', null, tip);
				h('span', 'rrt-swatch s' + (s.faint ? 'f' : si + 1), null, row);
				var v = s.values[cur];
				h('strong', '', fmt(v === undefined ? null : v, d), row);
				var extra = (s.counts && s.counts[cur] !== undefined) ? sprintf( /* translators: 1: s.counts[cur], 2: d.plays. */ __( ' (%1$s of %2$s)', 'foxfire-studio' ), s.counts[cur], d.plays ) : '';
				h('span', '', ' ' + s.name + extra, row);
			});
			var cue = cueAt(d, cur);
			if (cue) { h('div', 'rrt-tip-cue', '“' + cue + '”', tip); }
			(d.markers || []).forEach(function (m) { if (m.i === cur) { h('div', 'rrt-tip-cue', '⚑ ' + m.label, tip); } });
			tip.hidden = false;
			var left = cx + 14;
			if (left + 260 > W) { left = Math.max(0, cx - 274); }
			tip.style.left = left + 'px';
			tip.style.top = (T + (series.length > 1 ? 28 : 0)) + 'px';
		}
		function hide() { cross.setAttribute('visibility', 'hidden'); tip.hidden = true; }
		function idx(ev) {
			var r = svg.getBoundingClientRect();
			var x = (ev.clientX - r.left) * (W / r.width);
			return Math.round(((x - L) / pw) * (n - 1));
		}
		hit.addEventListener('pointermove', function (ev) { show(idx(ev)); });
		hit.addEventListener('pointerleave', hide);
		hit.addEventListener('focus', function () { show(cur < 0 ? 0 : cur); });
		hit.addEventListener('blur', hide);
		hit.addEventListener('keydown', function (ev) {
			if (ev.key === 'ArrowRight') { ev.preventDefault(); show(cur + 1); }
			if (ev.key === 'ArrowLeft') { ev.preventDefault(); show(cur - 1); }
			if (ev.key === 'Enter') { if (d.type === 'curve') { seek(cur * (d.step || 5)); } }
		});
		if (d.type === 'curve') {
			hit.style.cursor = 'pointer';
			hit.addEventListener('click', function (ev) { seek(idx(ev) * (d.step || 5)); });
		}

		/* The same numbers as a table. */
		var det = h('details', 'rrt-chart-table', null, fig);
		h('summary', '', __( 'Show as a table', 'foxfire-studio' ), det);
		var tbl = h('table', __( 'widefat striped', 'foxfire-studio' ), null, det);
		var tr = h('tr', '', null, h('thead', '', null, tbl));
		h('th', '', d.type === 'curve' ? __( 'Moment', 'foxfire-studio' ) : (d.weekly ? __( 'Week', 'foxfire-studio' ) : __( 'Day', 'foxfire-studio' )), tr);
		series.forEach(function (s) { h('th', '', s.name, tr); });
		if (d.cues) { h('th', '', __( 'Sentence', 'foxfire-studio' ), tr); }
		var tb = h('tbody', '', null, tbl);
		for (var r = 0; r < n; r++) {
			var row = h('tr', '', null, tb);
			h('td', '', xLabel(d, r), row);
			series.forEach(function (s) { h('td', '', fmt(s.values[r] === undefined ? null : s.values[r], d), row); });
			if (d.cues) { h('td', '', cueAt(d, r), row); }
		}
	}

	/* A suggested clip's ▶ (1.7.0) stops at the clip's end; any other seek plays on. */
	var stopAt = 0;
	var watchRun = 0;
	/*
	 * Checked every frame: timeupdate comes only about four times a second,
	 * enough to run into the next sentence. Stops watching when the listener
	 * moves the audio elsewhere or another ▶ is pressed.
	 */
	function watchStop(a, from, run, arrived) {
		if (run !== watchRun || stopAt <= 0) { return; }
		var here = a.currentTime >= from - 0.5 && a.currentTime <= stopAt + 1;
		/* Until the seek lands the time may still read elsewhere; after that, leaving the stretch means the listener moved on. */
		if (arrived && !here) { stopAt = 0; return; }
		if (here && !a.paused && a.currentTime >= stopAt) { stopAt = 0; a.pause(); return; }
		window.requestAnimationFrame(function () { watchStop(a, from, run, arrived || here); });
	}
	function seek(t, end) {
		var a = document.querySelector('.rrt-dash-audio');
		if (!a) { return; }
		stopAt = end > t ? end : 0;
		watchRun++;
		if (stopAt > 0) { var run = watchRun; window.requestAnimationFrame(function () { watchStop(a, t, run, false); }); }
		/* Before the audio's length is known, a seek can be lost: wait for it. */
		if (a.readyState < 1) {
			a.addEventListener('loadedmetadata', function once() { a.removeEventListener('loadedmetadata', once); try { a.currentTime = Math.max(0, t); } catch (e) {} });
			a.load();
		}
		try { a.currentTime = Math.max(0, t); } catch (e) {}
		var p = a.play();
		if (p) { if (p.catch) { p.catch(function () {}); } }
	}

	function charts() {
		document.querySelectorAll('script[data-chart]').forEach(function (s) {
			var fig = document.getElementById(s.getAttribute('data-chart'));
			if (!fig) { return; }
			var d;
			try { d = JSON.parse(s.textContent); } catch (e) { return; }
			d = weekly(d);
			if (d.weekly) {
				var note = document.createElement('p');
				note.className = 'rrt-small';
				note.textContent = __( 'Each point is the daily average for a week, since the period is long.', 'foxfire-studio' );
				fig.parentNode.insertBefore(note, fig.nextSibling);
			}
			draw(fig, d);
			var last = fig.clientWidth;
			window.addEventListener('resize', function () {
				if (Math.abs(fig.clientWidth - last) > 20) { last = fig.clientWidth; draw(fig, d); }
			});
		});
	}

	/* Recordings (1.36.2): Cancel closes the question that Delete or Clear opened. */
	function tidy() {
		document.querySelectorAll('.rrt-tidy-cancel').forEach(function (b) {
			b.addEventListener('click', function () {
				var d = b.closest('details');
				if (!d) { return; }
				d.open = false;
				var s = d.querySelector('summary');
				if (s) { s.focus(); }
			});
		});
		document.querySelectorAll('details.rrt-tidy-ask').forEach(function (d) {
			d.addEventListener('toggle', function () {
				if (!d.open) { return; }
				var yes = d.querySelector('button[type=submit]');
				if (yes) { yes.focus(); }
			});
		});
	}

	/* Sortable tables: click a column heading. */
	function sorting() {
		document.querySelectorAll('table.rrt-sortable').forEach(function (t) {
			t.querySelectorAll('thead th').forEach(function (th, col) {
				var b = th.querySelector('button');
				if (!b) { return; }
				b.addEventListener('click', function () {
					var dir = th.getAttribute('aria-sort') === 'descending' ? 'ascending' : 'descending';
					t.querySelectorAll('thead th').forEach(function (o) { o.removeAttribute('aria-sort'); });
					th.setAttribute('aria-sort', dir);
					var num = th.getAttribute('data-sort') === 'num';
					var body = t.tBodies[0];
					var rows = Array.prototype.slice.call(body.rows).filter(function (r) { return r.cells.length > col; });
					rows.sort(function (a, b2) {
						var x = a.cells[col].getAttribute('data-v');
						var y = b2.cells[col].getAttribute('data-v');
						var c = num ? (parseFloat(x || '-1e12') - parseFloat(y || '-1e12')) : String(x).localeCompare(String(y));
						return dir === 'ascending' ? c : -c;
					});
					rows.forEach(function (r) { body.appendChild(r); });
				});
			});
		});
	}

	/* CSV of any table, as shown. */
	function csv() {
		document.querySelectorAll('.rrt-csv').forEach(function (b) {
			b.addEventListener('click', function () {
				var t = document.getElementById(b.getAttribute('data-table'));
				if (!t) { return; }
				var lines = [];
				Array.prototype.forEach.call(t.rows, function (r) {
					if (r.hidden) { return; }
					lines.push(Array.prototype.filter.call(r.cells, function (c) { return !c.classList.contains('rrt-no-csv'); }).map(function (c) {
						var v = c.textContent.replace(/\s+/g, ' ').trim();
						/* Never a formula in a spreadsheet: visitors' campaign tags and referrers appear here. */
						if (/^(=|@|\t|\r|[+-](?!\d))/.test(v)) { v = "'" + v; }
						return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v;
					}).join(','));
				});
				var blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv' });
				var a = document.createElement('a');
				a.href = URL.createObjectURL(blob);
				a.download = (b.getAttribute('data-name') || 'table') + '.csv';
				document.body.appendChild(a);
				a.click();
				setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 500);
			});
		});
	}

	function filters() {
		document.querySelectorAll('.rrt-filter').forEach(function (s) {
			s.addEventListener('change', function () {
				var t = document.getElementById(s.getAttribute('data-table'));
				var v = s.value;
				Array.prototype.forEach.call(t.tBodies[0].rows, function (r) {
					var tags = (r.getAttribute('data-tags') || '').split('|');
					r.hidden = v !== '' && tags.indexOf(v) === -1;
				});
			});
		});
		document.querySelectorAll('.rrt-range-select').forEach(function (s) {
			s.addEventListener('change', function () {
				var c = s.form.querySelector('.rrt-range-custom');
				if (c) { c.hidden = s.value !== 'custom'; }
				if (s.value !== 'custom') { s.form.submit(); }
			});
		});
		document.querySelectorAll('.rrt-seek').forEach(function (b) {
			b.addEventListener('click', function () { seek(parseFloat(b.getAttribute('data-t')) || 0, parseFloat(b.getAttribute('data-end')) || 0); });
		});
	}

	function builder() {
		var box = document.querySelector('.rrt-builder');
		if (!box) { return; }
		var src = box.getAttribute('data-src') || 'src';
		var post = box.querySelector('.rrt-b-post');
		var ch = box.querySelector('.rrt-b-ch');
		var camp = box.querySelector('.rrt-b-camp');
		var start = box.querySelector('.rrt-b-t');
		var out = box.querySelector('.rrt-b-out');
		var msg = box.querySelector('.rrt-b-msg');
		function make() {
			var u;
			try { u = new URL(post.value); } catch (e) { return; }
			u.searchParams.set(src, ch.value);
			var c = camp.value.trim().toLowerCase().replace(/[^a-z0-9_-]+/g, '-').replace(/^-+|-+$/g, '');
			if (c) { u.searchParams.set('utm_campaign', c); } else { u.searchParams.delete('utm_campaign'); }
			var t = start.value.trim();
			if (/^\d+(:\d{1,2}){0,2}$/.test(t)) { u.searchParams.set('t', t); } else { u.searchParams.delete('t'); }
			out.value = u.toString();
			msg.textContent = '';
		}
		[post, ch, camp, start].forEach(function (e) { e.addEventListener('input', make); e.addEventListener('change', make); });
		box.querySelector('.rrt-b-copy').addEventListener('click', function () {
			out.select();
			var done = function () { msg.textContent = __( 'Copied.', 'foxfire-studio' ); };
			if (navigator.clipboard) {
				navigator.clipboard.writeText(out.value).then(done, function () { document.execCommand('copy'); done(); });
			} else { document.execCommand('copy'); done(); }
		});
		make();
	}

	function init() { charts(); sorting(); csv(); tidy(); filters(); builder(); }
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
}());
