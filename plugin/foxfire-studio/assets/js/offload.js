/*
 * Foxfire Studio: storage offload on Podcast feed (1.23.0).
 *
 * Shows the fields for the chosen kind of storage, fills in a service's
 * endpoint, runs Check this setup (the site uploads a test file and reads it
 * back; this browser then reads it too, which only works when the storage
 * lets other sites' pages read its files, as the player needs), copies every
 * file with a progress line, deletes the outside copies after a second
 * press, and asks the site to try the storage again after it stopped.
 */
(function () {
	'use strict';

	var rrtI18n = (typeof wp === 'object' ? (wp ? wp.i18n : null) : null) || null;
	var __ = rrtI18n ? rrtI18n.__ : function (s) { return s; };
	var sprintf = rrtI18n ? rrtI18n.sprintf : function (f) {
		var a = arguments;
		var i = 0;
		return String(f).replace(/%(?:(\d+)\$)?([sd%])/g, function (m, pos, type) {
			if (type === '%') { return '%'; }
			var v = a[pos ? Number(pos) : ++i];
			return type === 'd' ? String(parseInt(v, 10)) : String(v);
		});
	};

	var form = document.getElementById('rrt-off-form');
	if (!form) { return; }
	var boot = {};
	try { boot = JSON.parse(form.getAttribute('data-rrt-off') || '{}'); } catch (e) { boot = {}; }

	function q(sel) { return document.querySelector(sel); }

	function api(path, body) {
		return window.fetch(boot.rest + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': boot.nonce },
			body: JSON.stringify(body || {})
		}).then(function (r) {
			return r.json().then(function (j) {
				if (!r.ok) { throw new Error((j && j.message) || String(r.status)); }
				return j;
			});
		});
	}

	/* The rows for the chosen kind of storage. */
	function showMode() {
		var on = form.querySelector('input[name="mode"]:checked');
		var mode = on ? on.value : 'bunny';
		Array.prototype.forEach.call(form.querySelectorAll('[data-rrt-off-for]'), function (row) {
			row.hidden = row.getAttribute('data-rrt-off-for') !== mode;
		});
	}
	Array.prototype.forEach.call(form.querySelectorAll('input[name="mode"]'), function (r) {
		r.addEventListener('change', showMode);
	});
	showMode();

	var preset = form.querySelector('[data-rrt-off-preset]');
	if (preset) {
		preset.addEventListener('change', function () {
			var o = preset.options[preset.selectedIndex];
			if (!o || !o.value) { return; }
			q('#rrt-off-s3_endpoint').value = o.getAttribute('data-endpoint') || '';
			q('#rrt-off-s3_region').value = o.getAttribute('data-region') || '';
		});
	}

	var stepsBox = q('[data-rrt-off-steps]');
	function showSteps(steps, extra) {
		stepsBox.textContent = '';
		var ul = document.createElement('ul');
		ul.className = 'ul-disc';
		steps.forEach(function (st) {
			var li = document.createElement('li');
			li.textContent = (st.ok ? '✓ ' : '✗ ') + st.text;
			ul.appendChild(li);
		});
		stepsBox.appendChild(ul);
		if (extra) { stepsBox.appendChild(extra); }
	}

	/* Reads the test file from this browser, as the player would (a cross-site read of the first bytes). */
	function tryInBrowser(url) {
		return window.fetch(url, { mode: 'cors', credentials: 'omit', cache: 'no-store', headers: { Range: 'bytes=0-9' } }).then(function (r) {
			return r.ok;
		}, function () { return false; });
	}

	var checkBtn = q('[data-rrt-off-check]');
	function runCheck() {
		checkBtn.disabled = true;
		showSteps([{ ok: true, text: __('Checking...', 'foxfire-studio') }]);
		var steps = [];
		var start;
		api('check').then(function (r) {
			start = r;
			steps = (r.steps || []).slice();
			if (!r.ok) { return false; }
			return tryInBrowser(r.url);
		}).then(function (cors) {
			var site = !!(start && start.ok);
			if (site) {
				steps.push({
					ok: !!cors,
					text: cors
						? __('This browser can read it too, so the player can show chapters.', 'foxfire-studio')
						: __('This browser could not read it: the storage does not let other sites\' pages read its files (CORS). Turn on CORS for mp3, vtt and json, or let the plugin set the rules for S3 storage.', 'foxfire-studio')
				});
			}
			return api('check/done', { key: start ? start.key : '', hash: start ? start.hash : '', site: site, cors: !!cors, steps: steps }).then(function (d) {
				var extra = null;
				if (d.ok) {
					extra = document.createElement('p');
					extra.textContent = __('Passed. Tick Serve readings from the storage below and save, then press Copy everything now.', 'foxfire-studio');
					var tick = form.querySelector('input[name="on"]');
					if (tick) { tick.disabled = false; }
				} else if (site && !cors && boot.mode === 's3') {
					extra = document.createElement('p');
					var b = document.createElement('button');
					b.type = 'button';
					b.className = 'button';
					b.textContent = __('Set the CORS rules for me', 'foxfire-studio');
					b.addEventListener('click', function () {
						b.disabled = true;
						api('cors').then(function (c) {
							if (c.ok) { runCheck(); return; }
							var pre = document.createElement('pre');
							pre.className = 'code';
							pre.style.whiteSpace = 'pre-wrap';
							pre.textContent = c.xml;
							var p = document.createElement('p');
							p.textContent = sprintf(__('%s Set these rules in the storage\'s own settings instead:', 'foxfire-studio'), c.text);
							stepsBox.appendChild(p);
							stepsBox.appendChild(pre);
						});
					});
					extra.appendChild(b);
				}
				showSteps(steps, extra);
			});
		}).catch(function (e) {
			showSteps(steps.concat([{ ok: false, text: sprintf(__('The check stopped: %s', 'foxfire-studio'), e.message) }]));
		}).then(function () { checkBtn.disabled = false; });
	}
	if (checkBtn) { checkBtn.addEventListener('click', runCheck); }

	var progress = q('[data-rrt-off-progress]');
	var copyBtn = q('[data-rrt-off-copy]');
	if (copyBtn) {
		copyBtn.addEventListener('click', function () {
			copyBtn.disabled = true;
			var fails = 0;
			function step() {
				return api('step').then(function (r) {
					progress.textContent = sprintf(__('%1$d of %2$d files copied.', 'foxfire-studio'), r.outside, r.wanted);
					if (r.local > 0) {
						progress.textContent += ' ' + sprintf(__('%d captions or chapters files stay on this site (the storage serves them as plain downloads).', 'foxfire-studio'), r.local);
					}
					var errs = Object.keys(r.errors || {});
					if (errs.length) {
						fails++;
						progress.textContent += ' ' + sprintf(__('%1$s: %2$s', 'foxfire-studio'), errs[0].split('/').pop(), r.errors[errs[0]]);
					}
					if (r.left > 0 && fails < 3) { return step(); }
					progress.textContent += ' ' + (r.left > 0 ? __('Stopped: fix the problem above, then try again.', 'foxfire-studio') : __('Done.', 'foxfire-studio'));
					return null;
				});
			}
			progress.textContent = __('Copying... keep this tab open.', 'foxfire-studio');
			step().catch(function (e) {
				progress.textContent = sprintf(__('Copying stopped: %s', 'foxfire-studio'), e.message);
			}).then(function () { copyBtn.disabled = false; });
		});
	}

	var clearBtn = q('[data-rrt-off-clear]');
	if (clearBtn) {
		var armed = false;
		var label = clearBtn.textContent;
		clearBtn.addEventListener('click', function () {
			if (!armed) {
				armed = true;
				clearBtn.textContent = __('Press again to delete every outside copy', 'foxfire-studio');
				window.setTimeout(function () { armed = false; clearBtn.textContent = label; }, 6000);
				return;
			}
			armed = false;
			clearBtn.disabled = true;
			clearBtn.textContent = label;
			function step() {
				return api('clear').then(function (r) {
					progress.textContent = sprintf(__('Deleting... %d left.', 'foxfire-studio'), r.left);
					return r.left > 0 ? step() : null;
				});
			}
			step().then(function () {
				progress.textContent = __('Every outside copy is deleted. The readings are served from this site.', 'foxfire-studio');
			}, function (e) {
				progress.textContent = sprintf(__('Deleting stopped: %s', 'foxfire-studio'), e.message);
				clearBtn.disabled = false;
			});
		});
	}

	var retry = q('[data-rrt-off-retry]');
	if (retry) {
		retry.addEventListener('click', function () {
			retry.disabled = true;
			api('retry').then(function (r) {
				retry.parentNode.textContent = r.ok
					? __('The outside storage answers again, so readings are served from it again.', 'foxfire-studio')
					: __('It still does not answer. Readings stay on this site; the plugin tries again every hour.', 'foxfire-studio');
			}, function () { retry.disabled = false; });
		});
	}
})();
