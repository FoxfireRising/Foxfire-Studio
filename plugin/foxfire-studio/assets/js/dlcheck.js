/*
 * Foxfire Studio: Check counting on Podcast feed (1.24.0).
 *
 * Asks the site for a one-time test address, requests it twice from this
 * browser with a byte range, as an app would (a host's firewall can turn away
 * a site's requests to itself), and shows each step of the answer. When the
 * site adds its .htaccess lines, it checks again.
 */
(function () {
	'use strict';

	var rrtI18n = (typeof wp === 'object' ? (wp ? wp.i18n : null) : null) || null;
	var __ = rrtI18n ? rrtI18n.__ : function (s) { return s; };

	var form = document.getElementById('rrt-dl-form');
	if (!form) { return; }
	var boot = {};
	try { boot = JSON.parse(form.getAttribute('data-rrt-dl') || '{}'); } catch (e) { boot = {}; }
	var btn = form.querySelector('[data-rrt-dl-check]');
	var status = form.querySelector('[data-rrt-dl-status]');
	var list = form.querySelector('[data-rrt-dl-steps]');
	if (!btn) { return; }

	function show(res) {
		list.textContent = '';
		(res.steps || []).forEach(function (s) {
			var li = document.createElement('li');
			li.className = s.ok ? 'is-ok' : 'is-bad';
			li.textContent = (s.ok ? '✓ ' : '✗ ') + s.name + (s.note ? ' (' + s.note + ')' : '');
			list.appendChild(li);
		});
		var fix = form.querySelector('[data-rrt-dl-fix]');
		if (fix) { fix.textContent = res.fix || ''; }
		if (res.ok) {
			status.textContent = (res.ranges === false || res.fresh === false)
				? __('The hand-off is in use, but not every step passed.', 'foxfire-studio') + ' ' + (res.why || '')
				: __('Passed: the hand-off is in use.', 'foxfire-studio');
		} else {
			status.textContent = __('In use: a redirect.', 'foxfire-studio') + ' ' + (res.why || '');
		}
		form.setAttribute('data-rrt-dl-done', res.ok ? (res.fresh === false ? 'cached' : (res.ranges === false ? 'noranges' : 'ok')) : 'redirect');
	}

	function post(path, body) {
		return window.fetch(boot.rest + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': boot.nonce },
			body: JSON.stringify(body || {})
		}).then(function (r) { return r.json(); });
	}

	/* One request to the test address, as a podcast app makes it: a byte range, no redirect followed. */
	function ask(url) {
		return window.fetch(url, {
			headers: { Range: 'bytes=10-29' },
			cache: 'no-store',
			credentials: 'omit',
			redirect: 'manual'
		}).then(function (r) {
			if (r.type === 'opaqueredirect') {
				return { code: 302, body: '', headers: {} };
			}
			var h = {};
			['content-type', 'server', 'x-hcdn-cache-status', 'cf-cache-status', 'x-cache', 'x-served-by', 'x-qc-cache'].forEach(function (k) {
				var v = r.headers.get(k);
				if (v !== null) { h[k] = v; }
			});
			return r.text().then(function (t) { return { code: r.status, body: t.slice(0, 4096), headers: h }; });
		}).catch(function (e) {
			return { error: String(e && e.message ? e.message : e) };
		});
	}

	function round(attempt, variant) {
		return post('check/start', { variant: variant || '' }).then(function (s) {
			if (s.done) { return s.result; }
			var got = [];
			return ask(s.url).then(function (a) {
				got.push(a);
				return ask(s.url);
			}).then(function (b) {
				got.push(b);
				return post('check/done', { token: s.token, results: got, attempt: attempt });
			}).then(function (d) {
				if (d && d.retry && attempt < 6) {
					status.textContent = __('Trying again with what the site changed\u2026', 'foxfire-studio');
					return new Promise(function (res) { window.setTimeout(res, 2500); }).then(function () { return round(attempt + 1, d.variant || ''); });
				}
				return d;
			});
		});
	}

	btn.addEventListener('click', function () {
		btn.disabled = true;
		form.removeAttribute('data-rrt-dl-done');
		status.textContent = __('Checking\u2026', 'foxfire-studio');
		round(0, '').then(function (j) {
			btn.disabled = false;
			show(j || {});
		}).catch(function () {
			btn.disabled = false;
			status.textContent = __('The check could not run. Reload the page and try again.', 'foxfire-studio');
			form.setAttribute('data-rrt-dl-done', 'error');
		});
	});
}());
