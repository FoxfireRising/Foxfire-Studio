/*
 * Foxfire Studio: Check private folders on Podcast feed (1.25.0).
 *
 * Asks the site for a test file in each private folder, requests each one
 * from this browser the way a visitor would (no sign-in, no cache), and
 * reports any folder that answered with the file.
 */
(function () {
	'use strict';

	var rrtI18n = (typeof wp === 'object' ? (wp ? wp.i18n : null) : null) || null;
	var __ = rrtI18n ? rrtI18n.__ : function (s) { return s; };
	var sprintf = rrtI18n ? rrtI18n.sprintf : function (s, a) { return s.replace('%s', a); };

	var box = document.getElementById('rrt-private-check');
	if (!box) { return; }
	var boot = {};
	try { boot = JSON.parse(box.getAttribute('data-rrt-pc') || '{}'); } catch (e) { boot = {}; }
	var btn = box.querySelector('[data-rrt-pc-run]');
	var status = box.querySelector('[data-rrt-pc-status]');
	if (!btn || !boot.rest) { return; }

	function post(path, body) {
		return window.fetch(boot.rest + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': boot.nonce },
			body: JSON.stringify(body || {})
		}).then(function (r) { return r.json(); });
	}

	/* The text a visitor would get, or '' when the folder turned them away. */
	function ask(url) {
		return window.fetch(url + '?nocache=' + Date.now(), { cache: 'no-store', credentials: 'omit', redirect: 'manual' })
			.then(function (r) { return r.ok ? r.text() : ''; })
			.catch(function () { return ''; });
	}

	btn.addEventListener('click', function () {
		btn.disabled = true;
		status.textContent = __('Checking...', 'foxfire-studio');
		box.removeAttribute('data-rrt-pc-done');
		post('start').then(function (s) {
			if (!s || !s.token) { throw new Error((s && s.message) || 'start'); }
			var results = {};
			return Promise.all((s.folders || []).map(function (f) {
				return ask(f.url).then(function (t) { results[f.folder] = t.slice(0, 100); });
			})).then(function () {
				return post('done', { token: s.token, results: results });
			});
		}).then(function (res) {
			if (!res || !res.checked) { throw new Error((res && res.message) || 'done'); }
			if (res.open && res.open.length) {
				status.textContent = sprintf(__('Visitors could open %s. Add the lines below to the site\'s nginx setup, or ask your host why these folders\' .htaccess files are ignored.', 'foxfire-studio'), res.open.join(', '));
				box.setAttribute('data-rrt-pc-done', 'open');
			} else {
				status.textContent = __('Every folder turned visitors away.', 'foxfire-studio');
				box.setAttribute('data-rrt-pc-done', 'closed');
			}
		}).catch(function (e) {
			status.textContent = __('The check could not finish.', 'foxfire-studio') + ' ' + (e && e.message ? e.message : '');
			box.setAttribute('data-rrt-pc-done', 'error');
		}).then(function () {
			btn.disabled = false;
		});
	});
})();
