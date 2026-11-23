/*
 * Foxfire Studio: a shared take (0.17.0). The person it was shared with
 * hears it with the script lighting up, and pins comments to a moment or a
 * word. The person who shared it sees every comment, and can end the share.
 * Settings arrive in window.RRTShare from includes/share.php.
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
	var B = window.RRTShare;
	if (!B) { return; }
	var audio = document.getElementById('shAudio');
	var scriptEl = document.getElementById('shScript');
	var msg = document.getElementById('shMsg');
	var form = document.getElementById('shForm');
	var atEl = document.getElementById('shAt');
	var text = document.getElementById('shText');
	var nameIn = document.getElementById('shName');
	var addBtn = document.getElementById('shAdd');
	var nowBtn = document.getElementById('shNow');
	var notesEl = document.getElementById('shNotes');
	var doneRow = document.getElementById('shDoneRow');
	var doneBtn = document.getElementById('shDone');
	var endRow = document.getElementById('shEndRow');
	var endBtn = document.getElementById('shEnd');
	var help = document.getElementById('shHelp');
	var friend = B.role === 'friend';
	var words = Array.isArray(B.words) ? B.words : [];
	var notes = Array.isArray(B.notes) ? B.notes : [];
	var spans = [];
	var timed = [];
	var target = { at: null, word: null };

	function el(tag, cls, txt) {
		var e = document.createElement(tag);
		if (cls) { e.className = cls; }
		if (txt !== undefined) { e.textContent = txt; }
		return e;
	}
	function fmt(s) {
		s = Math.max(0, Math.round(s || 0));
		return Math.floor(s / 60) + ':' + (s % 60 < 10 ? '0' : '') + (s % 60);
	}
	/* A route under this share, for pretty and plain permalinks alike. */
	function route(path) {
		return B.rest.indexOf('rest_route=') !== -1 ? B.rest.replace(/(rest_route=[^&]*)/, '$1' + path) : B.rest + path;
	}
	function api(path, body, method) {
		var opts = { method: method || (body ? 'POST' : 'GET'), credentials: 'same-origin', headers: { 'X-WP-Nonce': B.nonce } };
		if (body) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
		return fetch(route(path), opts).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (j) {
				if (!r.ok) { throw new Error(j ? (j.message || sprintf( /* translators: %s: r.status. */ __( 'HTTP %s', 'foxfire-studio' ), r.status )) : sprintf( /* translators: %s: r.status. */ __( 'HTTP %s', 'foxfire-studio' ), r.status )); }
				return j;
			});
		});
	}

	/* The heading. */
	document.getElementById('shTitle').textContent = B.title;
	var sub = friend ? sprintf( /* translators: 1: B.owner, 2: B.ends. */ __( 'Shared by %1$s for your feedback. Open until %2$s.', 'foxfire-studio' ), B.owner, B.ends ) : (B.open ? sprintf( /* translators: 1: B.with.join(', ') || 'nobody', 2: B.ends. */ __( 'Shared with %1$s, open until %2$s.', 'foxfire-studio' ), B.with.join(', ') || 'nobody', B.ends ) : sprintf( /* translators: %s: B.with.join(', ') || 'nobody'. */ __( 'Shared with %s. This share has ended; the comments stay here.', 'foxfire-studio' ), B.with.join(', ') || 'nobody' ));
	document.getElementById('shSub').textContent = sub;

	/* The audio, fetched with the reader's sign-in, so it never sits at a public address. */
	if (B.audio) {
		msg.textContent = __( 'Loading the take...', 'foxfire-studio' );
		fetch(route('audio'), { credentials: 'same-origin', headers: { 'X-WP-Nonce': B.nonce } }).then(function (r) {
			if (!r.ok) { throw new Error(sprintf( /* translators: %s: r.status. */ __( 'HTTP %s', 'foxfire-studio' ), r.status )); }
			return r.blob();
		}).then(function (blob) {
			audio.src = URL.createObjectURL(blob);
			msg.textContent = '';
		})['catch'](function () {
			msg.textContent = __( 'The take could not be loaded. Refresh the page to try again.', 'foxfire-studio' );
		});
	} else {
		audio.hidden = true;
		msg.textContent = friend ? '' : __( 'The audio was deleted when this share ended. The script and comments are below.', 'foxfire-studio' );
	}

	/* The script, word by word, in its paragraphs. */
	var para = null;
	var lastBlock = -1;
	words.forEach(function (w, i) {
		if (w.b !== lastBlock) {
			lastBlock = w.b;
			para = el('p', 'sh-p sh-' + (w.k || 'p'));
			scriptEl.appendChild(para);
		} else {
			para.appendChild(document.createTextNode(' '));
		}
		var s = el('span', 'sh-w', w.t);
		s.setAttribute('data-i', String(i));
		if (w.s === null || w.s === undefined) { s.classList.add('is-unheard'); }
		para.appendChild(s);
		spans.push(s);
		if (typeof w.s === 'number') { timed.push({ s: w.s, e: typeof w.e === 'number' ? w.e : w.s + 0.3, i: i }); }
	});
	timed.sort(function (a, b) { return a.s - b.s; });
	if (!words.length) { scriptEl.appendChild(el('p', 'sh-muted', __( 'The script was not sent with this take.', 'foxfire-studio' ))); }

	/* Words light up as the take plays. */
	var lit = -1;
	function sync() {
		var t = audio.currentTime;
		var now = -1;
		var lo = 0;
		var hi = timed.length - 1;
		while (lo <= hi) {
			var mid = (lo + hi) >> 1;
			if (timed[mid].s <= t) { now = mid; lo = mid + 1; } else { hi = mid - 1; }
		}
		var idx = now >= 0 ? timed[now].i : -1;
		if (idx !== lit) {
			if (lit >= 0) { spans[lit].classList.remove('is-now'); }
			if (idx >= 0) {
				spans[idx].classList.add('is-now');
				var r = spans[idx].getBoundingClientRect();
				if (!audio.paused ? (r.top < 80 || r.bottom > window.innerHeight - 40) : false) { spans[idx].scrollIntoView({ block: 'center', behavior: 'smooth' }); }
			}
			lit = idx;
		}
		timed.forEach(function (x) { spans[x.i].classList.toggle('is-said', x.e <= t); });
	}
	audio.addEventListener('timeupdate', sync);
	audio.addEventListener('seeked', sync);
	function playFrom(t) {
		if (!audio.src) { return; }
		audio.currentTime = Math.max(0, t);
		audio.play()['catch'](function () {});
	}

	/* Where a new comment goes: a word (clicked) or the moment the audio is at. */
	function setTarget(at, word) {
		target.at = at;
		target.word = word;
		spans.forEach(function (s) { s.classList.remove('is-target'); });
		if (word !== null) { spans[word].classList.add('is-target'); }
		atEl.textContent = word !== null ? sprintf( /* translators: 1: words[word].t, 2: at !== null ? sprintf( __( ' at %s', 'foxfire-studio' ), fmt. */ __( 'On “%1$s”%2$s', 'foxfire-studio' ), words[word].t, at !== null ? sprintf( /* translators: %s: fmt(at). */ __( ' at %s', 'foxfire-studio' ), fmt(at) ) : '' ) : (at !== null ? sprintf( /* translators: %s: fmt(at). */ __( 'At %s', 'foxfire-studio' ), fmt(at) ) : __( 'Click a word in the script, or press Use this moment while it plays.', 'foxfire-studio' ));
	}
	scriptEl.addEventListener('click', function (e) {
		var t = e.target;
		if (!t.classList.contains('sh-w')) { return; }
		var i = parseInt(t.getAttribute('data-i'), 10);
		var w = words[i];
		var at = typeof w.s === 'number' ? w.s : null;
		if (friend ? B.open : false) {
			setTarget(at, i);
			text.focus({ preventScroll: true });
		}
		if (at !== null) { playFrom(at - 0.3); }
	});

	/* The list of comments, in time order. */
	function renderNotes() {
		notesEl.innerHTML = '';
		spans.forEach(function (s) { s.classList.remove('has-note'); });
		var list = notes.slice().sort(function (a, b) { return (a.at === null ? 1e9 : a.at) - (b.at === null ? 1e9 : b.at) || a.id - b.id; });
		if (!list.length) {
			notesEl.appendChild(el('li', 'sh-muted', friend ? __( 'No comments yet. Yours appear here as you add them.', 'foxfire-studio' ) : __( 'No comments yet.', 'foxfire-studio' )));
		}
		list.forEach(function (n) {
			if (n.word !== null ? !!spans[n.word] : false) { spans[n.word].classList.add('has-note'); }
			var li = el('li', 'sh-note');
			var head = el('button', 'sh-note-head');
			head.type = 'button';
			head.appendChild(el('span', 'sh-note-time', n.at === null ? '' : fmt(n.at)));
			if (n.word !== null ? !!words[n.word] : false) { head.appendChild(el('span', 'sh-note-word', '“' + words[n.word].t + '”')); }
			if (!friend) { head.appendChild(el('span', 'sh-note-name', n.name)); }
			head.addEventListener('click', function () {
				if (n.at !== null) { playFrom(n.at - 1); }
				if (n.word !== null ? !!spans[n.word] : false) { spans[n.word].scrollIntoView({ block: 'center', behavior: 'smooth' }); }
			});
			li.appendChild(head);
			li.appendChild(el('p', 'sh-note-text', n.text));
			if (friend ? B.open : false) {
				var x = el('button', 'sh-note-x', __( 'Remove', 'foxfire-studio' ));
				x.type = 'button';
				x.addEventListener('click', function () {
					api('notes/' + n.id, null, 'DELETE').then(function () {
						notes = notes.filter(function (m) { return m.id !== n.id; });
						renderNotes();
					})['catch'](function (err) { msg.textContent = err.message; });
				});
				li.appendChild(x);
			}
			notesEl.appendChild(li);
		});
		if (friend) {
			doneBtn.textContent = sprintf( /* translators: %s: B.owner. */ __( 'Done: send my comments to %s', 'foxfire-studio' ), B.owner );
			doneRow.hidden = !(B.open ? notes.length : false);
		}
	}

	if (friend ? B.open : false) {
		form.hidden = false;
		nameIn.value = B.me;
		setTarget(null, null);
		nowBtn.addEventListener('click', function () { setTarget(audio.currentTime, null); text.focus({ preventScroll: true }); });
		addBtn.addEventListener('click', function () {
			var body = text.value.trim();
			if (!body) { text.focus(); return; }
			if (target.at === null ? target.word === null : false) { target.at = audio.currentTime || 0; }
			addBtn.disabled = true;
			api('notes', { text: body, name: nameIn.value, at: target.at, word: target.word }).then(function (j) {
				notes.push(j.note);
				text.value = '';
				setTarget(null, null);
				renderNotes();
				msg.textContent = __( 'Comment saved. Add more, then press Done when you have finished.', 'foxfire-studio' );
			})['catch'](function (err) { msg.textContent = err.message; }).then(function () { addBtn.disabled = false; });
		});
		text.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' ? (e.ctrlKey || e.metaKey) : false) { e.preventDefault(); addBtn.click(); }
		});
		doneBtn.addEventListener('click', function () {
			doneBtn.disabled = true;
			api('done', {}).then(function (j) {
				msg.textContent = j.sent ? sprintf( /* translators: 1: B.owner, 2: j.sent, 3: B.ends. */ _n( 'Sent. %1$s has an email with your %2$s comment. You can come back and add more until %3$s.', 'Sent. %1$s has an email with your %2$s comments. You can come back and add more until %3$s.', j.sent, 'foxfire-studio' ), B.owner, j.sent, B.ends ) : sprintf( /* translators: %s: B.owner. */ __( 'Nothing new to send: your comments have already gone to %s.', 'foxfire-studio' ), B.owner );
			})['catch'](function (err) { msg.textContent = err.message; }).then(function () { doneBtn.disabled = false; });
		});
		help.textContent = sprintf( /* translators: %s: B.owner. */ __( 'Click any word to hear it and pin a comment to it, or press Use this moment while the take plays. Ctrl+Enter adds a comment. Only %s sees your comments. If you leave without pressing Done, they are sent an hour after your last one.', 'foxfire-studio' ), B.owner );
	} else if (friend) {
		help.textContent = __( 'This share has ended.', 'foxfire-studio' );
	} else {
		help.textContent = __( 'Click a comment to hear its moment. People you shared with see only their own comments.', 'foxfire-studio' );
		if (B.open) {
			endRow.hidden = false;
			endBtn.addEventListener('click', function () {
				if (endBtn.textContent !== __( 'Press again to end it', 'foxfire-studio' )) { endBtn.textContent = __( 'Press again to end it', 'foxfire-studio' ); return; }
				endBtn.disabled = true;
				api('end', {}).then(function () {
					endRow.hidden = true;
					audio.removeAttribute('src');
					audio.hidden = true;
					msg.textContent = __( 'Ended. The audio is deleted from the site; the comments stay here.', 'foxfire-studio' );
				})['catch'](function (err) { msg.textContent = err.message; endBtn.disabled = false; });
			});
		}
	}
	document.getElementById('shNotesHead').textContent = friend ? __( 'Your comments', 'foxfire-studio' ) : __( 'Comments', 'foxfire-studio' );
	renderNotes();

	/* Space plays and pauses, except while typing. */
	document.addEventListener('keydown', function (e) {
		var tag = e.target.tagName;
		if (tag === 'TEXTAREA' || tag === 'INPUT' || tag === 'BUTTON' || tag === 'AUDIO') { return; }
		if (e.key === ' ' || e.code === 'Space') {
			e.preventDefault();
			if (audio.paused) { audio.play()['catch'](function () {}); } else { audio.pause(); }
		}
	});
})();
