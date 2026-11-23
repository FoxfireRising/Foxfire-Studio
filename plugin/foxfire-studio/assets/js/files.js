/*
 * Foxfire Studio: the studio's files on this site (1.29.0).
 *
 * Downloads Whisper (the standard set and the larger model), voice
 * following's model, and the fonts, MP3 encoder and noise model into the
 * site, one file at a time, through admin-ajax. Moved out of the Settings
 * screen in 1.29.0 so the setup wizard's Privacy and files step uses the
 * same code. The addresses and nonces come from the element with
 * data-rrt-files.
 *
 * window.RRTFiles.set(cfg)   one Whisper set; resolves to true when every file arrived.
 * window.RRTFiles.local(cfg) the fonts, encoder and noise model; resolves the same way.
 */
(function () {
	'use strict';
	/* Translations: WordPress's wp.i18n when the page has it, otherwise the English text as written. */
	var rrtI18n = (typeof wp === 'object' ? (wp ? wp.i18n : null) : null) || null;
	var __ = rrtI18n ? rrtI18n.__ : function (s) { return s; };
	var sprintf = rrtI18n ? rrtI18n.sprintf : function (s) { var a = arguments; var i = 1; return String(s).replace(/%(\d+\$)?[sd]/g, function () { return String(a[i++]); }); };

	function boot() {
		var el = document.querySelector('[data-rrt-files]');
		if (!el) { return null; }
		try { return JSON.parse(el.getAttribute('data-rrt-files')); } catch (e) { return null; }
	}
	function size(b) { return b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.round(b / 1024) + ' KB'; }
	function post(B, body) {
		return fetch(B.ajax, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (r) { return r.json(); });
	}

	/*
	 * One Whisper set: base (the standard model with its code), small (the
	 * larger model) or follow (voice following's model). Since 1.17.0 a set
	 * that is only partly here gets its missing files; a whole set is
	 * downloaded again.
	 *
	 * @param cfg btn (optional), items (the list's li elements, each with data-i and data-size), set.
	 */
	function set(cfg) {
		var B = boot();
		var btn = cfg.btn || null;
		var list = cfg.items || [];
		if (!B || !list.length) { return Promise.resolve(false); }
		if (btn) { btn.disabled = true; }
		var i = 0;
		var failed = 0;
		var have = Array.prototype.filter.call(list, function (li) { return Number(li.getAttribute('data-size') || 0) > 0; }).length;
		var skip = have > 0 ? have < list.length : false;
		if (cfg.onlyMissing) { skip = true; }
		return new Promise(function (resolve) {
			function next() {
				while (skip && i < list.length && Number(list[i].getAttribute('data-size') || 0) > 0) { i++; }
				if (i >= list.length) {
					if (btn) {
						btn.disabled = false;
						btn.textContent = failed ? __( 'Try again', 'foxfire-studio' ) : __( 'Download again', 'foxfire-studio' );
					}
					resolve(!failed);
					return;
				}
				var li = list[i];
				var span = li.querySelector('span');
				li.className = 'is-busy';
				span.textContent = __( 'downloading...', 'foxfire-studio' );
				var body = new FormData();
				body.append('action', 'rrt_whisper_fetch');
				body.append('_ajax_nonce', B.whisper);
				body.append('i', li.getAttribute('data-i'));
				body.append('set', cfg.set);
				post(B, body)
					.then(function (r) {
						if (r && r.success) { li.className = 'is-ok'; span.textContent = size(r.data.size); li.setAttribute('data-size', String(r.data.size)); }
						else { failed++; li.className = 'is-bad'; span.textContent = (r && r.data && r.data.message) || __( 'failed', 'foxfire-studio' ); }
					})
					['catch'](function () { failed++; li.className = 'is-bad'; span.textContent = __( 'failed (the request timed out or was refused)', 'foxfire-studio' ); })
					.then(function () { i++; next(); });
			}
			next();
		});
	}

	/*
	 * The fonts, the MP3 encoder and the noise model.
	 *
	 * @param cfg btn (optional), log (a list to write lines into), status (optional: fonts, encoder, model elements).
	 */
	function local(cfg) {
		var B = boot();
		var btn = cfg.btn || null;
		var log = cfg.log;
		var st = cfg.status || {};
		if (!B || !log) { return Promise.resolve(false); }
		function line(text, cls) {
			var li = document.createElement('li');
			li.className = cls || '';
			li.textContent = text;
			log.appendChild(li);
			return li;
		}
		function step(name, extra) {
			var body = new FormData();
			body.append('action', 'rrt_local_fetch');
			body.append('_ajax_nonce', B.local);
			body.append('step', name);
			if (extra !== undefined) { body.append('i', extra); }
			return post(B, body)
				['catch'](function () { return { success: false, data: { message: __( 'The request timed out or was refused.', 'foxfire-studio' ) } }; })
				.then(function (r) {
					if (!r || !r.success) { throw new Error((r && r.data && r.data.message) || __( 'Something went wrong.', 'foxfire-studio' )); }
					if (r.data.status) {
						if (st.fonts) { st.fonts.textContent = r.data.status.fonts; }
						if (st.encoder) { st.encoder.textContent = r.data.status.encoder; }
						if (st.model) { st.model.textContent = r.data.status.model; }
					}
					return r.data;
				});
		}
		if (btn) { btn.disabled = true; }
		log.innerHTML = '';
		var ok = true;
		var enc = line(__( 'MP3 encoder: downloading...', 'foxfire-studio' ), 'is-busy');
		return step('encoder')
			.then(function (d) { enc.className = 'is-ok'; enc.textContent = d.message; })
			['catch'](function (e) { ok = false; enc.className = 'is-bad'; enc.textContent = e.message; })
			.then(function () {
				var md = line(__( 'Noise model: downloading...', 'foxfire-studio' ), 'is-busy');
				return step('model')
					.then(function (d) { md.className = 'is-ok'; md.textContent = d.message; })
					['catch'](function (e) { ok = false; md.className = 'is-bad'; md.textContent = e.message; });
			})
			.then(function () {
				var fl = line(__( 'Fonts: reading the font list...', 'foxfire-studio' ), 'is-busy');
				var cur = fl;
				return step('fonts_plan').then(function (d) {
					fl.className = 'is-ok';
					fl.textContent = d.message;
					if (!d.count) { return null; }
					/* translators: %s: how many font files there are. */
					var prog = line(sprintf(__( 'Fonts: 0 of %s', 'foxfire-studio' ), d.count), 'is-busy');
					cur = prog;
					var i = 0;
					function next() {
						if (i >= d.count) { return step('fonts_finish'); }
						return step('font', i).then(function () {
							i++;
							/* translators: 1: how many font files are done, 2: how many there are. */
							prog.textContent = sprintf(__( 'Fonts: %1$s of %2$s', 'foxfire-studio' ), i, d.count);
							return next();
						});
					}
					return next().then(function (f) { prog.className = 'is-ok'; prog.textContent = f.message; });
				})['catch'](function (e) { ok = false; cur.className = 'is-bad'; cur.textContent = e.message; });
			})
			.then(function () {
				if (btn) {
					btn.disabled = false;
					btn.textContent = ok ? __( 'Download again', 'foxfire-studio' ) : __( 'Try again', 'foxfire-studio' );
				}
				line(ok ? __( 'Done. The teleprompter uses these copies from its next page load.', 'foxfire-studio' ) : __( 'Anything that failed keeps loading from outside, so the teleprompter still works.', 'foxfire-studio' ), ok ? 'is-ok' : '');
				return ok;
			});
	}

	window.RRTFiles = { set: set, local: local };

	/* The Settings screen's buttons, as before 1.29.0. */
	function settingsScreen() {
		[['rrt-whisper-get', '#rrt-whisper-files li', 'base'], ['rrt-whisper-small-get', '#rrt-whisper-small-files li', 'small'], ['rrt-follow-get', '#rrt-follow-files li', 'follow'], ['rrt-whisper-multi-get', '#rrt-whisper-multi-files li', 'multi']].forEach(function (c) {
			var btn = document.getElementById(c[0]);
			if (!btn) { return; }
			btn.addEventListener('click', function () { set({ btn: btn, items: document.querySelectorAll(c[1]), set: c[2] }); });
		});
		var lb = document.getElementById('rrt-local-get');
		if (lb) {
			lb.addEventListener('click', function () {
				local({ btn: lb, log: document.getElementById('rrt-local-log'), status: { fonts: document.getElementById('rrt-st-fonts'), encoder: document.getElementById('rrt-st-encoder'), model: document.getElementById('rrt-st-model') } });
			});
		}
	}

	/*
	 * The setup wizard's one button: the standard Whisper set, then the
	 * fonts, encoder and noise model, then voice following and the larger
	 * model when their boxes are ticked. Files already here are skipped.
	 */
	function setupScreen() {
		var btn = document.getElementById('rrt-setup-files-get');
		if (!btn) { return; }
		var msg = document.getElementById('rrt-setup-files-msg');
		btn.addEventListener('click', function () {
			btn.disabled = true;
			msg.textContent = __( 'Downloading. Keep this page open until it finishes.', 'foxfire-studio' );
			var ok = true;
			var followBox = document.getElementById('rrt-setup-files-follow');
			var smallBox = document.getElementById('rrt-setup-files-small');
			set({ items: document.querySelectorAll('#rrt-setup-base li'), set: 'base', onlyMissing: true })
				.then(function (r) { ok = ok ? r : false; return local({ log: document.getElementById('rrt-setup-local-log') }); })
				.then(function (r) { ok = ok ? r : false; return followBox ? (followBox.checked ? set({ items: document.querySelectorAll('#rrt-setup-follow li'), set: 'follow', onlyMissing: true }) : true) : true; })
				.then(function (r) { ok = ok ? r : false; return smallBox ? (smallBox.checked ? set({ items: document.querySelectorAll('#rrt-setup-small li'), set: 'small', onlyMissing: true }) : true) : true; })
				.then(function (r) {
					ok = ok ? r : false;
					btn.disabled = false;
					btn.textContent = ok ? __( 'Download again', 'foxfire-studio' ) : __( 'Try again', 'foxfire-studio' );
					msg.textContent = ok ? __( 'Done. The studio now loads these files from this site.', 'foxfire-studio' ) : __( 'Some files did not arrive. Press Try again; anything missing keeps loading from outside, so the studio still works.', 'foxfire-studio' );
				});
		});
	}

	function start() { settingsScreen(); setupScreen(); }
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
}());
