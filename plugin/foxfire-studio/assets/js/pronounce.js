/*
 * Foxfire Studio: the pronunciation list (1.15.0), Foxfire Studio, Pronunciation.
 *
 * Draws the list from window.RRTPronBoot (made by includes/pronounce.php):
 * search and filters, a note edited in place, Move to, Forget, Add a note,
 * the words that may need a note, Export CSV, Export backup and Import. Each
 * change goes to rrt/v1/pronounce, which answers with the whole new list.
 *
 * window.RRTPron holds the pure helpers (filter, sort, CSV), tested in Node.
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

	var P = {};

	/* Where a row is kept, in words. */
	P.whereText = function (r) {
		if (r.where === 'all') { return __( 'In every post', 'foxfire-studio' ); }
		if (r.where === 'post') { return r.title || __( '(no title)', 'foxfire-studio' ); }
		return r.para ? sprintf( /* translators: 1: post title, 2: paragraph number (the title counts as paragraph 1). */ __( 'Only here: %1$s, paragraph %2$d', 'foxfire-studio' ), r.title || __( '(no title)', 'foxfire-studio' ), r.para ) : sprintf( /* translators: %s: post title. */ __( 'Only here: %s', 'foxfire-studio' ), r.title || __( '(no title)', 'foxfire-studio' ) );
	};

	/* What Whisper hears, in words. */
	P.heardText = function (r) {
		return (r.taught || []).map(function (t) { return t.ign ? sprintf( /* translators: %s: a word Whisper heard that is not in the script. */ __( '“%s” ignored', 'foxfire-studio' ), t.heard ) : t.heard; }).join(', ');
	};

	/* True when a row has a taught word of this kind: 'pair' or 'ign'. */
	function hasTaught(r, ign) { return (r.taught || []).some(function (t) { return !!t.ign === ign; }); }

	/*
	 * The rows that pass the filters. f: q (search), kind (''/note/taught/ign),
	 * where (''/all/post/here), post (id or 0).
	 */
	P.filter = function (rows, f) {
		var q = String(f.q || '').toLowerCase().trim();
		return rows.filter(function (r) {
			if (f.kind === 'note' ? !r.note : false) { return false; }
			if (f.kind === 'taught' ? !hasTaught(r, false) : false) { return false; }
			if (f.kind === 'ign' ? !hasTaught(r, true) : false) { return false; }
			if (f.where ? r.where !== f.where : false) { return false; }
			if (f.post ? Number(r.post) !== Number(f.post) : false) { return false; }
			if (!q) { return true; }
			var hay = [r.word, r.key, r.note, P.heardText(r), r.title || ''].join(' ').toLowerCase();
			return hay.indexOf(q) >= 0;
		});
	};

	/* Sorted copy of rows by a column: word, note, heard, where, count. dir 1 or -1. */
	P.sort = function (rows, col, dir) {
		var val = {
			word: function (r) { return String(r.word).toLowerCase(); },
			note: function (r) { return String(r.note || '').toLowerCase(); },
			heard: function (r) { return P.heardText(r).toLowerCase(); },
			where: function (r) { return (r.where === 'all' ? '0' : (r.where === 'post' ? '1' : '2')) + P.whereText(r).toLowerCase(); },
			count: function (r) { return Number(r.count) || 0; }
		}[col] || function (r) { return String(r.word).toLowerCase(); };
		return rows.slice().sort(function (a, b) {
			var x = val(a);
			var y = val(b);
			var c = x < y ? -1 : (x > y ? 1 : 0);
			if (!c) { c = String(a.word).toLowerCase() < String(b.word).toLowerCase() ? -1 : (String(a.word).toLowerCase() > String(b.word).toLowerCase() ? 1 : 0); }
			return c * (dir < 0 ? -1 : 1);
		});
	};

	/* One CSV field, quoted when needed. A leading = + - @ is guarded so a spreadsheet does not run it. */
	function csvField(v) {
		var s = String(v === undefined || v === null ? '' : v);
		if (/^[=+\-@\t\r]/.test(s)) { s = "'" + s; }
		return /[",\r\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
	}

	/* The rows as CSV: Word, Note, Whisper hears it as, Where, In posts. */
	P.csv = function (rows) {
		var out = [[__( 'Word', 'foxfire-studio' ), __( 'Note', 'foxfire-studio' ), __( 'Whisper hears it as', 'foxfire-studio' ), __( 'Where', 'foxfire-studio' ), __( 'In posts', 'foxfire-studio' )].map(csvField).join(',')];
		rows.forEach(function (r) {
			out.push([r.word, r.note || '', P.heardText(r), P.whereText(r), r.count || 0].map(csvField).join(','));
		});
		return '﻿' + out.join('\r\n') + '\r\n';
	};

	root.RRTPron = P;
	if (typeof module === 'object' ? !!module.exports : false) { module.exports = P; return; }

	/*
	 * ---------------------------------------------------------------------
	 * The screen
	 * ---------------------------------------------------------------------
	 */
	var B = root.RRTPronBoot;
	var box = document.getElementById('rrt-pron');
	if (!B ? true : !box) { return; }

	var state = { rows: B.rows || [], suggest: B.suggest || { list: [], total: 0 } };
	var f = { q: '', kind: '', where: '', post: 0 };
	var sortCol = 'word';
	var sortDir = 1;
	var showAll = false;
	var busy = false;

	function el(tag, cls, text) {
		var e = document.createElement(tag);
		if (cls) { e.className = cls; }
		if (text !== undefined ? text !== null : false) { e.textContent = text; }
		return e;
	}
	function button(text, cls, fn) {
		var b = el('button', 'button' + (cls ? ' ' + cls : ''), text);
		b.type = 'button';
		b.addEventListener('click', fn);
		return b;
	}

	/* Sends one change; the answer replaces the list. */
	function send(body) {
		if (busy) { return Promise.reject(new Error('busy')); }
		busy = true;
		box.classList.add('is-busy');
		return window.fetch(B.rest, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': B.nonce },
			body: JSON.stringify(body)
		}).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (j) {
				if (!r.ok) {
					busy = false;
					box.classList.remove('is-busy');
					var err = new Error(j.message || sprintf( /* translators: %s: HTTP status. */ __( 'HTTP %s', 'foxfire-studio' ), r.status ));
					err.data = j.data || {};
					throw err;
				}
				if (j.rows) { state.rows = j.rows; }
				if (j.suggest) { state.suggest = j.suggest; }
				/* The list is drawn again before the screen stops being busy, so nothing half-open is swept away later. */
				draw();
				busy = false;
				box.classList.remove('is-busy');
				return j;
			});
		}, function (e) {
			busy = false;
			box.classList.remove('is-busy');
			throw e;
		});
	}

	/* The place a row is kept, as the site reads it. */
	function placeOf(r) {
		return r.where === 'here' ? { where: 'here', post: r.post, i: r.i, wkey: r.wkey } : { where: r.where, post: r.post, key: r.key };
	}

	box.textContent = '';
	var line = el('p', 'rrt-pron-line');
	line.setAttribute('role', 'status');
	line.setAttribute('aria-live', 'polite');
	function say(text, bad) {
		line.textContent = text;
		line.classList.toggle('is-bad', !!bad);
	}

	/* A menu of the posts the reader can edit. */
	function postSelect(withAll, chosen) {
		var s = el('select', 'rrt-pron-posts');
		if (withAll) {
			var o0 = el('option', '', __( 'All posts', 'foxfire-studio' ));
			o0.value = '0';
			s.appendChild(o0);
		}
		(B.posts || []).forEach(function (p) {
			var o = el('option', '', p.title);
			o.value = String(p.id);
			if (Number(chosen) === p.id) { o.selected = true; }
			s.appendChild(o);
		});
		return s;
	}

	/* ---- Words that may need a note ---- */
	var sugBox = el('details', 'rrt-pron-suggest');
	sugBox.open = true;
	var sugHead = el('summary');
	var sugBody = el('div');
	sugBox.appendChild(sugHead);
	sugBox.appendChild(sugBody);
	function drawSuggest() {
		var S = state.suggest || { list: [], total: 0 };
		sugHead.textContent = sprintf( /* translators: %d: how many words. */ __( 'Words that may need a note (%d)', 'foxfire-studio' ), S.total || 0 );
		sugBody.textContent = '';
		if (!S.list.length) {
			sugBody.appendChild(el('p', 'rrt-small', __( 'Nothing to suggest: every name and long word in your published posts has a note, a taught word, or was marked Not needed.', 'foxfire-studio' )));
			return;
		}
		sugBody.appendChild(el('p', 'rrt-small', __( 'Names and long words from your published posts with no note yet, posts still to read first. Add a note now and it shows the first time you read them.', 'foxfire-studio' )));
		var ul = el('ul', 'rrt-pron-sug');
		S.list.slice(0, showAll ? S.list.length : 12).forEach(function (s) {
			var li = el('li');
			li.appendChild(el('strong', '', s.w));
			var why = s.why === 'name' ? __( 'Name', 'foxfire-studio' ) : __( 'Long word', 'foxfire-studio' );
			var where = s.open ? sprintf( /* translators: 1: posts with no reading yet, 2: all posts with the word. */ _n( 'in %2$d post, %1$d still to read', 'in %2$d posts, %1$d still to read', s.n, 'foxfire-studio' ), s.open, s.n ) : sprintf( /* translators: %d: posts with the word. */ _n( 'in %d post', 'in %d posts', s.n, 'foxfire-studio' ), s.n );
			var meta = el('span', 'rrt-small', ' ' + why + ', ' + where);
			meta.title = s.titles.join('\n');
			li.appendChild(meta);
			var acts = el('span', 'rrt-pron-sug-do');
			acts.appendChild(button(__( 'Add a note', 'foxfire-studio' ), 'button-small', function () { openAdd(s.w); }));
			acts.appendChild(button(__( 'Not needed', 'foxfire-studio' ), 'button-small', function () {
				send({ do: 'skip', key: s.k }).then(function () {
					say(sprintf( /* translators: %s: a word. */ __( '“%s” will not be suggested again.', 'foxfire-studio' ), s.w ));
				})['catch'](function (e) { say(e.message, true); });
			}));
			li.appendChild(acts);
			ul.appendChild(li);
		});
		sugBody.appendChild(ul);
		if (S.list.length > 12) {
			sugBody.appendChild(button(showAll ? __( 'Show fewer', 'foxfire-studio' ) : sprintf( /* translators: %d: how many more words. */ __( 'Show %d more', 'foxfire-studio' ), S.list.length - 12 ), 'button-link', function () { showAll = !showAll; drawSuggest(); }));
		}
	}

	/* ---- The tools ---- */
	var tools = el('div', 'rrt-pron-tools');
	var search = el('input', 'rrt-pron-search');
	search.type = 'search';
	search.placeholder = __( 'Search words and notes', 'foxfire-studio' );
	search.setAttribute('aria-label', search.placeholder);
	search.addEventListener('input', function () { f.q = search.value; drawTable(); });
	var kind = el('select');
	kind.setAttribute('aria-label', __( 'Kind', 'foxfire-studio' ));
	[['', __( 'Notes and taught words', 'foxfire-studio' )], ['note', __( 'Notes', 'foxfire-studio' )], ['taught', __( 'Taught to Whisper', 'foxfire-studio' )], ['ign', __( 'Ignored by Whisper', 'foxfire-studio' )]].forEach(function (o) {
		var x = el('option', '', o[1]);
		x.value = o[0];
		kind.appendChild(x);
	});
	kind.addEventListener('change', function () { f.kind = kind.value; drawTable(); });
	var where = el('select');
	where.setAttribute('aria-label', __( 'Where', 'foxfire-studio' ));
	[['', __( 'Anywhere', 'foxfire-studio' )], ['all', __( 'In every post', 'foxfire-studio' )], ['post', __( 'Just one post', 'foxfire-studio' )], ['here', __( 'Only here', 'foxfire-studio' )]].forEach(function (o) {
		var x = el('option', '', o[1]);
		x.value = o[0];
		where.appendChild(x);
	});
	where.addEventListener('change', function () { f.where = where.value; drawTable(); });
	var postF = postSelect(true, 0);
	postF.setAttribute('aria-label', __( 'Post', 'foxfire-studio' ));
	postF.addEventListener('change', function () { f.post = Number(postF.value); drawTable(); });
	var left = el('div', 'rrt-pron-filters');
	[search, kind, where, postF].forEach(function (x) { left.appendChild(x); });
	var right = el('div', 'rrt-pron-actions');
	right.appendChild(button(__( 'Add a note', 'foxfire-studio' ), 'button-primary', function () { openAdd(''); }));
	right.appendChild(button(__( 'Export CSV', 'foxfire-studio' ), '', function () {
		download('pronunciation.csv', 'text/csv;charset=utf-8', P.csv(P.sort(P.filter(state.rows, f), sortCol, sortDir)));
	}));
	right.appendChild(button(__( 'Export backup', 'foxfire-studio' ), '', function () { backup(); }));
	var file = el('input');
	file.type = 'file';
	file.accept = '.json,application/json';
	file.hidden = true;
	file.addEventListener('change', function () { if (file.files[0]) { readImport(file.files[0]); } file.value = ''; });
	right.appendChild(button(__( 'Import', 'foxfire-studio' ), '', function () { file.click(); }));
	right.appendChild(file);
	tools.appendChild(left);
	tools.appendChild(right);

	/* The backup's address: the route's own, with /backup on the end (in the path, or in rest_route on a site with plain links). */
	function backupUrl() { return B.rest + '/backup'; }
	function backup() {
		window.fetch(backupUrl(), { credentials: 'same-origin', headers: { 'X-WP-Nonce': B.nonce } }).then(function (r) {
			if (!r.ok) { throw new Error(sprintf( /* translators: %s: HTTP status. */ __( 'HTTP %s', 'foxfire-studio' ), r.status )); }
			return r.text();
		}).then(function (t) {
			download('pronunciation-backup-' + new Date().toISOString().slice(0, 10) + '.json', 'application/json', t);
			say(__( 'Backup saved to your downloads.', 'foxfire-studio' ));
		})['catch'](function (e) { say(e.message, true); });
	}
	function download(name, type, text) {
		var a = el('a');
		a.href = URL.createObjectURL(new Blob([text], { type: type }));
		a.download = name;
		document.body.appendChild(a);
		a.click();
		setTimeout(function () { URL.revokeObjectURL(a.href); a.parentNode.removeChild(a); }, 1000);
	}

	/* ---- Add a note ---- */
	var add = el('form', 'rrt-pron-add');
	add.hidden = true;
	var addWord = el('input');
	addWord.placeholder = __( 'Word', 'foxfire-studio' );
	addWord.setAttribute('aria-label', __( 'Word', 'foxfire-studio' ));
	addWord.maxLength = 40;
	var addNote = el('input');
	addNote.placeholder = __( 'How to say it, like SOW-in', 'foxfire-studio' );
	addNote.setAttribute('aria-label', __( 'Note', 'foxfire-studio' ));
	addNote.maxLength = B.max || 40;
	var addWhere = el('select');
	addWhere.setAttribute('aria-label', __( 'Where', 'foxfire-studio' ));
	[['all', __( 'In every post', 'foxfire-studio' )], ['post', __( 'Just this post:', 'foxfire-studio' )]].forEach(function (o) {
		var x = el('option', '', o[1]);
		x.value = o[0];
		addWhere.appendChild(x);
	});
	var addPost = postSelect(false, 0);
	addPost.setAttribute('aria-label', __( 'Post', 'foxfire-studio' ));
	addPost.hidden = true;
	addWhere.addEventListener('change', function () { addPost.hidden = addWhere.value !== 'post'; });
	var addSave = el('button', 'button button-primary', __( 'Save', 'foxfire-studio' ));
	addSave.type = 'submit';
	var addClash = el('div', 'rrt-pron-clash');
	addClash.hidden = true;
	[addWord, addNote, addWhere, addPost, addSave, button(__( 'Cancel', 'foxfire-studio' ), '', function () { add.hidden = true; addClash.hidden = true; }), addClash].forEach(function (x) { add.appendChild(x); });
	function openAdd(word) {
		add.hidden = false;
		addClash.hidden = true;
		addWord.value = word || '';
		addNote.value = '';
		(word ? addNote : addWord).focus();
	}
	function addSend(keep) {
		var body = { do: 'add', word: addWord.value, note: addNote.value, where: addWhere.value, post: Number(addPost.value) || 0 };
		if (keep) { body.keep = keep; }
		send(body).then(function () {
			say(sprintf( /* translators: %s: a word. */ __( 'Note saved for “%s”.', 'foxfire-studio' ), addWord.value.trim() ));
			add.hidden = true;
			addClash.hidden = true;
		})['catch'](function (e) {
			if (e.data ? !!e.data.clash : false) {
				addClash.textContent = '';
				addClash.appendChild(el('span', '', sprintf( /* translators: %s: the note already kept. */ __( 'This word already has the note “%s” there.', 'foxfire-studio' ), e.data.clash.to ) + ' '));
				addClash.appendChild(button(sprintf( /* translators: %s: the new note. */ __( 'Replace it with “%s”', 'foxfire-studio' ), e.data.clash.from ), 'button-small', function () { addSend('replace'); }));
				addClash.appendChild(button(__( 'Keep it', 'foxfire-studio' ), 'button-small', function () { add.hidden = true; addClash.hidden = true; }));
				addClash.hidden = false;
				return;
			}
			say(e.message, true);
		});
	}
	add.addEventListener('submit', function (ev) { ev.preventDefault(); addSend(''); });

	/* ---- Import ---- */
	var imp = el('div', 'rrt-pron-import');
	imp.hidden = true;
	function readImport(fileObj) {
		var rd = new FileReader();
		rd.onload = function () {
			var data = null;
			try { data = JSON.parse(String(rd.result)); } catch (e) { data = null; }
			if (!data) { say(__( 'This file could not be read as a backup.', 'foxfire-studio' ), true); return; }
			send({ do: 'import', data: data, apply: false }).then(function (j) { showImport(data, j['import']); })['catch'](function (e) { say(e.message, true); });
		};
		rd.readAsText(fileObj);
	}
	function showImport(data, res) {
		imp.textContent = '';
		imp.hidden = false;
		imp.appendChild(el('h2', '', __( 'Import a backup', 'foxfire-studio' )));
		var parts = [sprintf( /* translators: %d: how many. */ _n( '%d to add', '%d to add', res.add, 'foxfire-studio' ), res.add ), sprintf( /* translators: %d: how many. */ _n( '%d already here', '%d already here', res.same, 'foxfire-studio' ), res.same ), sprintf( /* translators: %d: how many. */ _n( '%d different note', '%d different notes', res.clashes.length, 'foxfire-studio' ), res.clashes.length )];
		imp.appendChild(el('p', '', parts.join(', ') + '.'));
		if (res.skipped.length) {
			imp.appendChild(el('p', 'rrt-small', sprintf( /* translators: %s: post titles. */ __( 'Not on this site, so left out: %s', 'foxfire-studio' ), res.skipped.join(', ') )));
		}
		var keep = {};
		if (res.clashes.length) {
			imp.appendChild(el('p', 'rrt-small', __( 'Where a word already has a different note, choose which to keep. What is here stays unless you choose the file’s.', 'foxfire-studio' )));
			var t = el('table', 'widefat striped rrt-table');
			var hr = el('tr');
			[__( 'Word', 'foxfire-studio' ), __( 'Where', 'foxfire-studio' ), __( 'Keep', 'foxfire-studio' )].forEach(function (h) { hr.appendChild(el('th', '', h)); });
			var th = el('thead');
			th.appendChild(hr);
			t.appendChild(th);
			var tb = el('tbody');
			res.clashes.forEach(function (c, n) {
				var tr = el('tr');
				tr.appendChild(el('td', '', c.word));
				tr.appendChild(el('td', '', c.where === 'all' ? __( 'In every post', 'foxfire-studio' ) : (c.where === 'here' ? sprintf( /* translators: %s: post title. */ __( 'Only here: %s', 'foxfire-studio' ), c.title ) : c.title)));
				var td = el('td');
				[['mine', sprintf( /* translators: %s: a note. */ __( 'Here: “%s”', 'foxfire-studio' ), c.mine )], ['file', sprintf( /* translators: %s: a note. */ __( 'File: “%s”', 'foxfire-studio' ), c.file )]].forEach(function (o) {
					var lab = el('label', 'rrt-pron-pick');
					var r = el('input');
					r.type = 'radio';
					r.name = 'rrt-imp-' + n;
					r.value = o[0];
					r.checked = o[0] === 'mine';
					r.addEventListener('change', function () { keep[c.id] = r.value; });
					lab.appendChild(r);
					lab.appendChild(document.createTextNode(' ' + o[1]));
					td.appendChild(lab);
				});
				tr.appendChild(td);
				tb.appendChild(tr);
			});
			t.appendChild(tb);
			imp.appendChild(t);
		}
		var row = el('p');
		row.appendChild(button(__( 'Import', 'foxfire-studio' ), 'button-primary', function () {
			send({ do: 'import', data: data, apply: true, keep: keep }).then(function (j) {
				imp.hidden = true;
				var r2 = j['import'];
				say(sprintf( /* translators: %d: how many were added. */ _n( 'Imported: %d added.', 'Imported: %d added.', r2.add, 'foxfire-studio' ), r2.add ));
			})['catch'](function (e) { say(e.message, true); });
		}));
		row.appendChild(document.createTextNode(' '));
		row.appendChild(button(__( 'Cancel', 'foxfire-studio' ), '', function () { imp.hidden = true; }));
		imp.appendChild(row);
		imp.scrollIntoView({ block: 'nearest' });
	}

	/* ---- The table ---- */
	var count = el('p', 'rrt-small rrt-pron-count');
	var table = el('table', 'widefat striped rrt-table rrt-pron-table');
	var thead = el('thead');
	var tbody = el('tbody');
	var htr = el('tr');
	[['word', __( 'Word', 'foxfire-studio' )], ['note', __( 'Note', 'foxfire-studio' )], ['heard', __( 'Whisper hears it as', 'foxfire-studio' )], ['where', __( 'Where', 'foxfire-studio' )], ['count', __( 'In posts', 'foxfire-studio' )], ['', '']].forEach(function (h) {
		var th = el('th', h[0] === 'count' ? 'num' : '');
		if (h[0]) {
			var b = el('button', '', h[1]);
			b.type = 'button';
			b.addEventListener('click', function () {
				if (sortCol === h[0]) { sortDir = -sortDir; } else { sortCol = h[0]; sortDir = h[0] === 'count' ? -1 : 1; }
				drawTable();
			});
			th.appendChild(b);
			th.setAttribute('data-col', h[0]);
		}
		htr.appendChild(th);
	});
	thead.appendChild(htr);
	table.appendChild(thead);
	table.appendChild(tbody);

	/* The note cell: the note, or a box to change it. */
	function noteCell(r) {
		var td = el('td', 'rrt-pron-note');
		var shown = el('button', 'rrt-pron-note-btn', r.note || (r.where === 'here' ? '' : __( 'Add a note', 'foxfire-studio' )));
		shown.type = 'button';
		shown.classList.toggle('is-empty', !r.note);
		shown.title = __( 'Click to change', 'foxfire-studio' );
		shown.addEventListener('click', function () {
			var input = el('input');
			input.value = r.note || '';
			input.maxLength = B.max || 40;
			input.setAttribute('aria-label', sprintf( /* translators: %s: a word. */ __( 'Note for %s', 'foxfire-studio' ), r.word ));
			td.textContent = '';
			td.appendChild(input);
			input.focus();
			input.select();
			var done = false;
			function finish(save) {
				if (done) { return; }
				done = true;
				if (!save ? true : input.value.trim() === (r.note || '')) { drawTable(); return; }
				send({ do: 'note', place: placeOf(r), note: input.value }).then(function () {
					say(input.value.trim() ? sprintf( /* translators: %s: a word. */ __( 'Note saved for “%s”.', 'foxfire-studio' ), r.word ) : sprintf( /* translators: %s: a word. */ __( 'Note removed from “%s”.', 'foxfire-studio' ), r.word ));
				})['catch'](function (e) { say(e.message, true); drawTable(); });
			}
			input.addEventListener('keydown', function (ev) {
				if (ev.key === 'Enter') { ev.preventDefault(); finish(true); }
				if (ev.key === 'Escape') { ev.preventDefault(); finish(false); }
			});
			input.addEventListener('blur', function () { finish(true); });
		});
		td.appendChild(shown);
		return td;
	}

	/* The taught words cell, each with Forget. */
	function heardCell(r) {
		var td = el('td', 'rrt-pron-heard');
		(r.taught || []).forEach(function (t) {
			var span = el('span', 'rrt-pron-taught', t.ign ? sprintf( /* translators: %s: a word Whisper heard that is not in the script. */ __( '“%s” ignored', 'foxfire-studio' ), t.heard ) : t.heard);
			if (t.label) { span.title = t.label; }
			var x = button(__( 'Forget', 'foxfire-studio' ), 'button-link rrt-pron-forget', function () {
				send({ do: 'forget', place: placeOf(r), entry: t.e }).then(function () {
					say(sprintf( /* translators: %s: what was taught, like “Siobhan” heard as “shivon”. */ __( 'Forgotten: %s. Whisper’s review counts it as misheard again.', 'foxfire-studio' ), t.label || t.heard ));
				})['catch'](function (e) { say(e.message, true); });
			});
			x.setAttribute('aria-label', sprintf( /* translators: %s: a taught word. */ __( 'Forget %s', 'foxfire-studio' ), t.label || t.heard ));
			span.appendChild(document.createTextNode(' '));
			span.appendChild(x);
			td.appendChild(span);
		});
		return td;
	}

	/* Move to: the places a row can go. */
	function moveCell(r, tr) {
		var td = el('td', 'rrt-pron-do');
		var s = el('select');
		s.setAttribute('aria-label', sprintf( /* translators: %s: a word. */ __( 'Move %s to', 'foxfire-studio' ), r.word ));
		var opts = [['', __( 'Move to…', 'foxfire-studio' )]];
		if (r.where !== 'all') { opts.push(['all', __( 'Every post', 'foxfire-studio' )]); }
		if (r.where === 'here') { opts.push(['this', __( 'Every use in this post', 'foxfire-studio' )]); }
		opts.push(['other', __( 'Just one post…', 'foxfire-studio' )]);
		opts.forEach(function (o) {
			var x = el('option', '', o[1]);
			x.value = o[0];
			s.appendChild(x);
		});
		var pick = null;
		s.addEventListener('change', function () {
			if (pick) { pick.parentNode.removeChild(pick); pick = null; }
			if (s.value === 'all') { move(r, tr, { where: 'all', post: 0 }, ''); }
			else if (s.value === 'this') { move(r, tr, { where: 'post', post: r.post }, ''); }
			else if (s.value === 'other') {
				pick = el('span', 'rrt-pron-pick-post');
				/* Starts on the word's own post: its post, else the first post that has the word. */
				var ps = postSelect(false, r.post || r.first || 0);
				ps.setAttribute('aria-label', __( 'Post', 'foxfire-studio' ));
				pick.appendChild(ps);
				pick.appendChild(button(__( 'Move', 'foxfire-studio' ), 'button-small', function () { move(r, tr, { where: 'post', post: Number(ps.value) }, ''); }));
				td.appendChild(pick);
			}
		});
		td.appendChild(s);
		if (r.open) {
			var a = el('a', 'rrt-pron-open', __( 'Studio', 'foxfire-studio' ));
			a.href = r.open;
			a.target = '_blank';
			a.rel = 'noopener';
			a.title = __( 'Open this post in the studio, in a new tab', 'foxfire-studio' );
			td.appendChild(a);
		}
		return td;
	}

	function move(r, tr, to, keep) {
		send({ do: 'move', from: placeOf(r), to: to, keep: keep }).then(function () {
			say(sprintf( /* translators: %s: a word. */ __( '“%s” moved.', 'foxfire-studio' ), r.word ));
		})['catch'](function (e) {
			if (e.data ? !!e.data.clash : false) {
				var cl = el('tr', 'rrt-pron-clash-row');
				var td = el('td', 'rrt-pron-clash');
				td.colSpan = 6;
				td.appendChild(el('span', '', sprintf( /* translators: %s: a word. */ __( 'Both places have a note for “%s”. Keep:', 'foxfire-studio' ), r.word ) + ' '));
				td.appendChild(button('“' + e.data.clash.from + '”', 'button-small', function () { move(r, tr, to, 'from'); }));
				td.appendChild(button('“' + e.data.clash.to + '”', 'button-small', function () { move(r, tr, to, 'to'); }));
				td.appendChild(button(__( 'Cancel', 'foxfire-studio' ), 'button-link', function () { drawTable(); }));
				cl.appendChild(td);
				tr.parentNode.insertBefore(cl, tr.nextSibling);
				return;
			}
			say(e.message, true);
			drawTable();
		});
	}

	function drawTable() {
		var rows = P.sort(P.filter(state.rows, f), sortCol, sortDir);
		tbody.textContent = '';
		Array.prototype.forEach.call(htr.children, function (th) {
			var c = th.getAttribute('data-col');
			if (c === sortCol) { th.setAttribute('aria-sort', sortDir < 0 ? 'descending' : 'ascending'); } else { th.removeAttribute('aria-sort'); }
		});
		count.textContent = rows.length === state.rows.length ? sprintf( /* translators: %d: how many rows. */ _n( '%d word', '%d words', rows.length, 'foxfire-studio' ), rows.length ) : sprintf( /* translators: 1: rows shown, 2: all rows. */ __( '%1$d of %2$d words', 'foxfire-studio' ), rows.length, state.rows.length );
		rows.forEach(function (r) {
			var tr = el('tr');
			tr.setAttribute('data-id', r.id);
			var w = el('td', 'rrt-pron-word');
			w.appendChild(el('strong', '', r.word));
			if (r.snip) { w.title = r.snip; }
			if (r.moved) { w.appendChild(el('span', 'rrt-small', ' ' + __( '(word moved; opening the post in the studio puts it back)', 'foxfire-studio' ))); }
			tr.appendChild(w);
			tr.appendChild(noteCell(r));
			tr.appendChild(heardCell(r));
			var wh = el('td', 'rrt-pron-where', P.whereText(r));
			if (r.snip) { wh.title = '… ' + r.snip + ' …'; }
			tr.appendChild(wh);
			var c = el('td', 'num', String(r.count || 0));
			if (r['in'] ? r['in'].length : false) { c.title = r['in'].join('\n') + (r.count > r['in'].length ? '\n…' : ''); }
			tr.appendChild(c);
			tr.appendChild(moveCell(r, tr));
			tbody.appendChild(tr);
		});
		if (!rows.length) {
			var tr0 = el('tr');
			var td0 = el('td', '', state.rows.length ? __( 'No words match.', 'foxfire-studio' ) : __( 'No notes or taught words yet. Add a note above, or in the studio: Controls, then the PRACTICE heading, then Cues, and click a word while paused.', 'foxfire-studio' ));
			td0.colSpan = 6;
			tr0.appendChild(td0);
			tbody.appendChild(tr0);
		}
	}

	function draw() {
		drawSuggest();
		drawTable();
	}

	[sugBox, tools, add, imp, line, count, table].forEach(function (x) { box.appendChild(x); });
	draw();
	/* Opened from the studio's note menu with a word: search for it. */
	var m = /[?&]word=([^&]*)/.exec(window.location.search);
	if (m) {
		search.value = decodeURIComponent(m[1].replace(/\+/g, ' '));
		f.q = search.value;
		drawTable();
	}
})(typeof window !== 'undefined' ? window : globalThis);
