/*
 * Foxfire Studio: cue marks (0.15.0): placing saved marks on the words, drawing them, the
 * click menu while Cues is on, saving them to the site, and the reading's
 * total weight with them.
 * Shares window.RRTStudio with the studio's other files (made in studio-core.js;
 * docs/ARCHITECTURE.md maps them). Moved from teleprompter.js in 0.25.2.
 */
(function () {
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
	var studio = window.RRTStudio;
	/* From the studio's other files. */
	var RRT = studio.RRT;
	var body = studio.body;
	var track = studio.track;
	var msgEl = studio.msgEl;
	var PR = studio.PR;
	var cuesIn = studio.cuesIn;
	var POST_ID = studio.POST_ID;
	var W = studio.W;
	var measure = studio.measure;
	var status = studio.status;
	studio.cueSecs = cueSecs;
	studio.cueWeight = cueWeight;
	studio.cueMenuClose = cueMenuClose;
	studio.cueMenuOpen = cueMenuOpen;
	studio.noteAt = noteAt;
	studio.noteSet = noteSet;
	studio.noteRemove = noteRemove;
	if (!track) { return; }

	/*
	 * Cue marks (0.15.0): a pause, a breath or an emphasis on a word, saved on
	 * the site with the post (never in the post's text). cueOf[i] is word i's
	 * cue or null. Pauses and breaths hold the timed scroll; emphasis gives
	 * the word a little more time and draws a line under it.
	 */
	var CUE = RRT.cues || null;
	var cueOf = [];
	studio.cueOf = cueOf;
	var cueMarks = [];
	var fadeMarks = [];
	var CUE_LOCAL = 'rrtCuesPending_' + POST_ID;
	function cueSecs(i) { return PR ? PR.cueSecs(cueOf[i]) : 0; }
	function cueWeight(i) { return (cueOf[i] ? cueOf[i].e : false) ? (PR ? PR.CUE_EM : 1) : 1; }
	function cueList() {
		var out = [];
		cueOf.forEach(function (c, i) { if (c) { out.push({ i: i, k: W[i].key, a: c.a, e: c.e, f: !!c.f, n: c.n || '' }); } });
		return out;
	}
	/* Draws word i's cue: a line under an emphasised word, a mark after a pause or a breath. */
	function cueDraw(i) {
		var c = cueOf[i];
		var w = W[i].el;
		w.classList.toggle('tp-cue-em', c ? c.e : false);
		if (cueMarks[i]) { cueMarks[i].parentNode.removeChild(cueMarks[i]); cueMarks[i] = null; }
		if (fadeMarks[i]) { fadeMarks[i].parentNode.removeChild(fadeMarks[i]); fadeMarks[i] = null; }
		/* A fade meant (1.11.0): a tilde after the word (and after any pause or breath mark). */
		if (c ? !!c.f : false) {
			var fm = document.createElement('span');
			fm.className = 'tp-cue tp-cue-fade';
			fm.textContent = '~';
			fm.title = __( 'Let this line fade', 'foxfire-studio' );
			fm.setAttribute('aria-label', fm.title);
			w.parentNode.insertBefore(fm, w.nextSibling);
			fadeMarks[i] = fm;
		}
		if (c ? !!c.a : false) {
			var m = document.createElement('span');
			m.className = 'tp-cue tp-cue-' + c.a;
			m.textContent = 'pause' === c.a ? '/' : '\u2713';
			m.title = 'pause' === c.a ? __( 'Pause here', 'foxfire-studio' ) : __( 'Breathe here', 'foxfire-studio' );
			m.setAttribute('aria-label', m.title);
			w.parentNode.insertBefore(m, w.nextSibling);
			cueMarks[i] = m;
		}
	}
	function recalcTotal() {
		studio.totalWeight = W.reduce(function (a, w, i) { return a + w.weight * cueWeight(i) + w.beat; }, 0);
	}
	var cueTimer = 0;
	var cueSaving = false;
	/* Saves to the site a moment after the last change. If that fails, a copy waits in this browser and is sent next time. */
	/*
	 * Since 1.15.0 only the marks changed since the site last answered are
	 * sent (cueBase is the site's list as last known), so a change made on the
	 * Pronunciation screen while this tab was open is kept.
	 */
	var cueBase = PR ? PR.cueClean(CUE ? CUE.list : []) : [];
	function cueSave() {
		window.clearTimeout(cueTimer);
		var changes = PR ? PR.cueDiff(cueList(), cueBase) : [];
		try { window.localStorage.setItem(CUE_LOCAL, JSON.stringify({ at: Date.now(), changes: changes })); } catch (e) {}
		if (!CUE || !CUE.rest) { return; }
		cueTimer = window.setTimeout(function () {
			if (cueSaving) { cueSave(); return; }
			var sent = PR ? PR.cueDiff(cueList(), cueBase) : [];
			if (!sent.length) {
				try { window.localStorage.removeItem(CUE_LOCAL); } catch (e) {}
				return;
			}
			cueSaving = true;
			window.fetch(CUE.rest, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CUE.nonce },
				body: JSON.stringify({ post: POST_ID, changes: sent })
			}).then(function (r) {
				if (!r.ok) { throw new Error(sprintf( /* translators: %s: r.status. */ __( 'HTTP %s', 'foxfire-studio' ), r.status )); }
				return r.json();
			}).then(function () {
				cueSaving = false;
				cueBase = PR.cueApply(cueBase, sent);
				try { window.localStorage.removeItem(CUE_LOCAL); } catch (e) {}
				msgEl.textContent = __( 'Cue marks saved.', 'foxfire-studio' );
			})['catch'](function () {
				cueSaving = false;
				msgEl.textContent = __( 'The cue marks could not be saved to the site just now. They are kept in this browser and will be sent with the next change or visit.', 'foxfire-studio' );
			});
		}, 700);
	}
	(function cueLoad() {
		if (!PR) { return; }
		var list = CUE ? CUE.list : [];
		var pending = null;
		try { pending = JSON.parse(window.localStorage.getItem(CUE_LOCAL) || 'null'); } catch (e) {}
		/* Changes that never reached the site are put back on top (1.15.0); a whole list kept by an older version wins as before. */
		if (pending ? Array.isArray(pending.changes) : false) { list = PR.cueApply(list || [], pending.changes); }
		else if (pending ? Array.isArray(pending.cues) : false) { list = pending.cues; }
		var placed = PR.cuePlace(list || [], W.map(function (w) { return w.key; }));
		placed.cues.forEach(function (c) { cueOf[c.i] = { a: c.a, e: c.e, f: !!c.f, n: c.n || '' }; });
		cueOf.forEach(function (c, i) { if (c) { cueDraw(i); } });
		if (placed.lost) {
			msgEl.textContent = placed.lost === 1 ? __( '1 cue mark was left off because its word is no longer in the post.', 'foxfire-studio' ) : sprintf( /* translators: %s: placed.lost. */ __( '%s cue marks were left off because their words are no longer in the post.', 'foxfire-studio' ), placed.lost );
		}
		if (pending ? CUE : false) { cueSave(); }
	})();

	/*
	 * Pronunciation notes (1.1.0). A note shows small above its word. It is
	 * kept on one word only (the cue's n, saved with the cue marks), on every
	 * use of the word in this post (postNotes), or on every use in any post
	 * (mineNotes, the reader's own). PR.noteFor picks the nearest.
	 */
	var NOTES = RRT.notes || null;
	var postNotes = {};
	var mineNotes = {};
	var NOTE_LOCAL = { post: 'rrtNotesPending_' + POST_ID, all: 'rrtNotesMinePending' };
	var noteTimer = { post: 0, all: 0 };
	var noteSaving = { post: false, all: false };
	/* The site's lists as last known (1.15.0): only the difference is sent, word by word. */
	var noteBase = { post: {}, all: {} };
	function noteAt(i) {
		if (!PR || !W[i]) { return null; }
		return PR.noteFor(cueOf[i], W[i].key, postNotes, mineNotes);
	}
	/* Shows word i's note, or takes it away. The note is drawn by the style sheet, so the word's own text never changes. */
	function noteDraw(i) {
		var f = noteAt(i);
		var w = W[i].el;
		if (f) {
			w.setAttribute('data-note', f.n);
			w.setAttribute('aria-description', sprintf( /* translators: %s: how to say the word, as the reader wrote it. */ __( 'Say it as %s', 'foxfire-studio' ), f.n ));
		} else {
			w.removeAttribute('data-note');
			w.removeAttribute('aria-description');
		}
	}
	function noteDrawAll() { W.forEach(function (w, i) { noteDraw(i); }); }
	/* Saves one list to the site a moment after the last change. If that fails, a copy waits in this browser. */
	function noteSave(scope) {
		window.clearTimeout(noteTimer[scope]);
		var map = scope === 'all' ? mineNotes : postNotes;
		try { window.localStorage.setItem(NOTE_LOCAL[scope], JSON.stringify({ at: Date.now(), changes: PR.noteDiff(map, noteBase[scope]) })); } catch (e) {}
		if (!NOTES || !NOTES.rest) { return; }
		noteTimer[scope] = window.setTimeout(function () {
			if (noteSaving[scope]) { noteSave(scope); return; }
			var sent = PR.noteDiff(scope === 'all' ? mineNotes : postNotes, noteBase[scope]);
			if (!Object.keys(sent).length) {
				try { window.localStorage.removeItem(NOTE_LOCAL[scope]); } catch (e) {}
				return;
			}
			noteSaving[scope] = true;
			window.fetch(NOTES.rest, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NOTES.nonce },
				body: JSON.stringify({ post: POST_ID, scope: scope, changes: sent })
			}).then(function (r) {
				if (!r.ok) { throw new Error(sprintf( /* translators: %s: r.status. */ __( 'HTTP %s', 'foxfire-studio' ), r.status )); }
				return r.json();
			}).then(function () {
				noteSaving[scope] = false;
				noteBase[scope] = PR.noteApply(noteBase[scope], sent);
				try { window.localStorage.removeItem(NOTE_LOCAL[scope]); } catch (e) {}
				msgEl.textContent = __( 'Pronunciation note saved.', 'foxfire-studio' );
			})['catch'](function () {
				noteSaving[scope] = false;
				msgEl.textContent = __( 'The pronunciation note could not be saved to the site just now. It is kept in this browser and will be sent with the next change or visit.', 'foxfire-studio' );
			});
		}, 700);
	}
	function noteLoad() {
		if (!PR) { return; }
		postNotes = PR.noteMap(NOTES ? NOTES.post : []);
		mineNotes = PR.noteMap(NOTES ? NOTES.mine : []);
		noteBase = { post: PR.noteMap(NOTES ? NOTES.post : []), all: PR.noteMap(NOTES ? NOTES.mine : []) };
		['post', 'all'].forEach(function (scope) {
			var pending = null;
			try { pending = JSON.parse(window.localStorage.getItem(NOTE_LOCAL[scope]) || 'null'); } catch (e) {}
			var now = null;
			/* Changes that never reached the site are put back on top (1.15.0); a whole list kept by an older version wins as before. */
			if (pending ? (pending.changes ? typeof pending.changes === 'object' : false) : false) { now = PR.noteApply(noteBase[scope], pending.changes); }
			else if (pending ? Array.isArray(pending.notes) : false) { now = PR.noteMap(pending.notes); }
			if (now) {
				if (scope === 'all') { mineNotes = now; } else { postNotes = now; }
				if (NOTES) { noteSave(scope); }
			}
		});
		noteDrawAll();
	}
	/* Removes the note kept at one scope for word i. */
	function noteDrop(i, scope) {
		if (scope === 'here') {
			var c = cueOf[i];
			if (c ? !!c.n : false) {
				c.n = '';
				cueOf[i] = (c.a || c.e || c.f) ? c : null;
				cueSave();
			}
			return;
		}
		var map = scope === 'all' ? mineNotes : postNotes;
		if (Object.prototype.hasOwnProperty.call(map, W[i].key)) {
			delete map[W[i].key];
			noteSave(scope);
		}
	}
	/*
	 * Sets word i's note at a scope: 'here', 'post' or 'all'. A note moved to
	 * another scope leaves the one it came from, so the word has one note.
	 */
	function noteSet(i, text, scope) {
		var n = PR ? PR.noteText(text) : '';
		var was = noteAt(i);
		if (!n) { noteRemove(i); return; }
		if (was ? was.scope !== scope : false) { noteDrop(i, was.scope); }
		if (scope === 'here') {
			var c = cueOf[i] || { a: '', e: false, f: false, n: '' };
			c.n = n;
			cueOf[i] = c;
			cueSave();
		} else if (W[i].key) {
			(scope === 'all' ? mineNotes : postNotes)[W[i].key] = n;
			noteSave(scope);
		}
		noteDrawAll();
	}
	/* Removes the note word i shows. A note under it (for example the reader's own, under this post's) then shows. */
	function noteRemove(i) {
		var was = noteAt(i);
		if (!was) { return; }
		noteDrop(i, was.scope);
		noteDrawAll();
	}

	noteLoad();

	/* The small menu that sets a word's cue while Cues is on. */
	studio.cueMenu = null;
	function cueMenuClose() {
		if (studio.cueMenu) { studio.cueMenu.parentNode.removeChild(studio.cueMenu); studio.cueMenu = null; }
	}
	/* Places the menu under word i, or above it near the bottom of the screen. */
	function cueMenuPlace(menu, i) {
		var r = W[i].el.getBoundingClientRect();
		var mw = menu.offsetWidth;
		var x = Math.max(8, Math.min(window.innerWidth - mw - 8, r.left + r.width / 2 - mw / 2));
		var y = r.bottom + 8;
		if (y + menu.offsetHeight > window.innerHeight - 8) { y = Math.max(8, r.top - menu.offsetHeight - 8); }
		menu.style.left = x + 'px';
		menu.style.top = y + 'px';
	}
	function cueApplied() {
		recalcTotal();
		measure();
		status();
	}
	function cueMenuOpen(i) {
		cueMenuClose();
		var c = cueOf[i] || { a: '', e: false, n: '' };
		var note = noteAt(i);
		var menu = document.createElement('div');
		menu.className = 'tp-cue-menu';
		/* A small dialog of buttons (1.27.0): Tab moves between them, Escape closes it and focus goes back to the script. */
		menu.setAttribute('role', 'dialog');
		menu.setAttribute('aria-label', sprintf( /* translators: %s: W[i].el.textContent. */ __( 'Cue for “%s”', 'foxfire-studio' ), W[i].el.textContent ));
		var opts = [['pause', __( 'Pause after', 'foxfire-studio' )], ['breath', __( 'Breath after', 'foxfire-studio' )], ['em', __( 'Emphasis', 'foxfire-studio' )], ['fade', __( 'Fade meant', 'foxfire-studio' )]];
		if (PR) {
			opts.push(['note', note ? __( 'Edit note', 'foxfire-studio' ) : __( 'Pronunciation', 'foxfire-studio' )]);
			if (note) { opts.push(['unnote', __( 'Remove note', 'foxfire-studio' )]); }
		}
		opts.push(['clear', __( 'Clear', 'foxfire-studio' )]);
		opts.forEach(function (o) {
			var b = document.createElement('button');
			b.type = 'button';
			b.className = 'tp-btn';
			b.textContent = o[1];
			b.setAttribute('data-cue', o[0]);
			var on = 'em' === o[0] ? c.e : ('fade' === o[0] ? !!c.f : c.a === o[0]);
			if ('pause' === o[0] || 'breath' === o[0] || 'em' === o[0] || 'fade' === o[0]) { b.setAttribute('aria-pressed', on ? 'true' : 'false'); }
			if ('fade' === o[0]) { b.title = __( 'This line is meant to trail off, so the review does not flag its sentence as a faded ending', 'foxfire-studio' ); }
			if ('clear' === o[0] ? (!c.a ? (!c.e ? (!c.f ? !note : false) : false) : false) : false) { b.disabled = true; }
			menu.appendChild(b);
		});
		menu.addEventListener('click', function (e) {
			var k = e.target.getAttribute('data-cue');
			if (!k) { return; }
			if ('note' === k) { noteForm(menu, i); return; }
			if ('unnote' === k) { noteRemove(i); cueMenuClose(); return; }
			var n = { a: c.a, e: c.e, f: !!c.f, n: c.n || '' };
			if ('em' === k) { n.e = !n.e; }
			else if ('fade' === k) { n.f = !n.f; }
			else if ('clear' === k) {
				/* Clear takes everything off the word, its note included. */
				if (note ? note.scope !== 'here' : false) { noteDrop(i, note.scope); }
				n = { a: '', e: false, f: false, n: '' };
			}
			else { n.a = n.a === k ? '' : k; }
			cueOf[i] = (n.a || n.e || n.f || n.n) ? n : null;
			cueDraw(i);
			noteDrawAll();
			cueApplied();
			cueSave();
			cueMenuClose();
		});
		document.body.appendChild(menu);
		cueMenuPlace(menu, i);
		studio.cueMenu = menu;
		menu.querySelector('button').focus();
	}
	/* The note form, in place of the menu's buttons. */
	function noteForm(menu, i) {
		var was = noteAt(i);
		menu.innerHTML = '';
		menu.classList.add('is-note');
		menu.setAttribute('role', 'dialog');
		var id = 'tpNote' + i;
		var lab = document.createElement('label');
		lab.className = 'tp-note-label';
		lab.setAttribute('for', id);
		lab.textContent = sprintf( /* translators: %s: the word in the script. */ __( 'Say “%s” as', 'foxfire-studio' ), W[i].el.textContent.replace(/[^\w\u00C0-\u024F'\u2019-]/g, '') );
		var input = document.createElement('input');
		input.type = 'text';
		input.id = id;
		input.className = 'tp-note-input';
		input.maxLength = PR.NOTE_LEN;
		input.placeholder = __( 'for example SOW-in', 'foxfire-studio' );
		input.value = was ? was.n : '';
		menu.appendChild(lab);
		menu.appendChild(input);
		var where = document.createElement('div');
		where.className = 'tp-note-where';
		where.setAttribute('role', 'radiogroup');
		where.setAttribute('aria-label', __( 'Show this note', 'foxfire-studio' ));
		var radios = {};
		[['all', __( 'In every post', 'foxfire-studio' )], ['post', __( 'Just this post', 'foxfire-studio' )]].forEach(function (o) {
			var l = document.createElement('label');
			var r = document.createElement('input');
			r.type = 'radio';
			r.name = 'tpNoteWhere' + i;
			r.value = o[0];
			r.checked = was ? (was.scope === 'here' ? o[0] === 'all' : was.scope === o[0]) : o[0] === 'all';
			radios[o[0]] = r;
			l.appendChild(r);
			l.appendChild(document.createTextNode(' ' + o[1]));
			where.appendChild(l);
		});
		menu.appendChild(where);
		var onlyL = document.createElement('label');
		onlyL.className = 'tp-note-only';
		var only = document.createElement('input');
		only.type = 'checkbox';
		only.checked = was ? was.scope === 'here' : false;
		onlyL.appendChild(only);
		onlyL.appendChild(document.createTextNode(' ' + __( 'Only here', 'foxfire-studio' )));
		onlyL.title = __( 'Show the note on this one word only, for a word said differently elsewhere (such as “tear” or “wind”).', 'foxfire-studio' );
		menu.appendChild(onlyL);
		function sync() { radios.all.disabled = only.checked; radios.post.disabled = only.checked; }
		only.addEventListener('change', sync);
		sync();
		var row = document.createElement('div');
		row.className = 'tp-note-row';
		var save = document.createElement('button');
		save.type = 'button';
		save.className = 'tp-btn tp-note-save';
		save.textContent = __( 'Save', 'foxfire-studio' );
		var cancel = document.createElement('button');
		cancel.type = 'button';
		cancel.className = 'tp-btn';
		cancel.textContent = __( 'Cancel', 'foxfire-studio' );
		row.appendChild(save);
		row.appendChild(cancel);
		menu.appendChild(row);
		/* Every note and taught word on one screen (1.15.0), in a new tab so this take is not lost. */
		if (NOTES ? !!NOTES.list : false) {
			var all = document.createElement('a');
			all.className = 'tp-note-all';
			all.href = NOTES.list + '&word=' + encodeURIComponent(W[i].key || '');
			all.target = '_blank';
			all.rel = 'noopener';
			all.textContent = __( 'All notes', 'foxfire-studio' );
			all.title = __( 'Foxfire Studio, Pronunciation: every note and taught word, in a new tab', 'foxfire-studio' );
			menu.appendChild(all);
		}
		function done() {
			var scope = only.checked ? 'here' : (radios.post.checked ? 'post' : 'all');
			noteSet(i, input.value, scope);
			cueDraw(i);
			cueMenuClose();
		}
		save.addEventListener('click', done);
		cancel.addEventListener('click', cueMenuClose);
		/* Enter saves from anywhere in the form: the note box, the choices or the tick box. */
		menu.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' ? e.target.tagName === 'INPUT' : false) { e.preventDefault(); done(); }
		});
		cueMenuPlace(menu, i);
		input.focus();
		input.select();
	}
	document.addEventListener('mousedown', function (e) {
		if (studio.cueMenu ? !studio.cueMenu.contains(e.target) : false) {
			if (!(e.target.classList ? e.target.classList.contains('tp-w') : false)) { cueMenuClose(); }
		}
	});
	if (cuesIn) {
		if (!PR) { cuesIn.parentNode.hidden = true; }
		cuesIn.checked = false;
		cuesIn.addEventListener('change', function () {
			body.classList.toggle('is-cueing', cuesIn.checked);
			if (!cuesIn.checked) { cueMenuClose(); }
			msgEl.textContent = cuesIn.checked ? __( 'Click a word to mark a pause or a breath after it, to emphasise it, or to let its line fade. From the keyboard: Escape to the script, left and right to move a word, Enter for its cues. The post itself is not changed.', 'foxfire-studio' ) : '';
		});
	}

	studio.totalWeight = 0;
	recalcTotal();
})();
