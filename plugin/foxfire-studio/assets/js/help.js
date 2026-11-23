/**
 * Help (1.32.0): the Help link beside a Foxfire Studio screen's heading, and
 * the search box on the Help screen, which only shows and hides the guides
 * already on the page. Since 1.36.0 also a Help link under each section of a
 * screen that has a guide or part of its own (window.rrtHelpParts), and the
 * search hides a group of guides when none of its guides matches.
 */
(function () {
	'use strict';
	var __ = window.wp.i18n.__;
	var _n = window.wp.i18n._n;
	var sprintf = window.wp.i18n.sprintf;

	/* The Help link, after the heading and any buttons beside it. */
	function addLink(cfg) {
		var h1 = document.querySelector('.wrap h1');
		if (!h1 || document.querySelector('.rrt-help-link')) { return; }
		var a = document.createElement('a');
		a.className = 'page-title-action rrt-help-link';
		a.href = cfg.url;
		a.textContent = __( 'Help', 'foxfire-studio' );
		var after = h1;
		while (after.nextElementSibling ? after.nextElementSibling.classList.contains('page-title-action') : false) {
			after = after.nextElementSibling;
		}
		after.parentNode.insertBefore(a, after.nextSibling);
		/* WordPress's own pattern for a heading with buttons: the heading inline, and a marker
		   after them, so notices are moved below the link instead of between it and the heading. */
		h1.classList.add('wp-heading-inline');
		if (!h1.parentNode.querySelector('.wp-header-end')) {
			var hr = document.createElement('hr');
			hr.className = 'wp-header-end';
			a.parentNode.insertBefore(hr, a.nextSibling);
		}
	}

	/* 1.36.0: "Help with this section" under each section heading that has a part of a guide. */
	function addParts(list) {
		var wrap = document.querySelector('#wpbody-content .wrap') || document.getElementById('wpbody-content');
		if (!wrap) { return; }
		var heads = Array.prototype.slice.call(wrap.querySelectorAll('h2, h3'));
		list.forEach(function (item) {
			heads.forEach(function (h) {
				if (h.textContent.replace(/\s+/g, ' ').trim() !== item.h) { return; }
				var next = h.nextElementSibling;
				if (next ? next.classList.contains('rrt-help-part-link') : false) { return; }
				var p = document.createElement('p');
				p.className = 'rrt-help-part-link';
				var a = document.createElement('a');
				a.href = item.url;
				a.textContent = __( 'Help with this section', 'foxfire-studio' );
				var hidden = document.createElement('span');
				hidden.className = 'screen-reader-text';
				hidden.textContent = ' ' + sprintf( /* translators: %s: a section heading, such as Look. */ __( '(%s)', 'foxfire-studio' ), item.h );
				a.appendChild(hidden);
				p.appendChild(a);
				h.parentNode.insertBefore(p, h.nextSibling);
			});
		});
	}

	function words(q) {
		return String(q).toLowerCase().split(/\s+/).filter(function (w) { return w.length > 0; });
	}

	function search(root) {
		var box = root.querySelector('.rrt-help-search');
		var input = root.querySelector('#rrt-help-q');
		var count = root.querySelector('#rrt-help-count');
		var none = root.querySelector('.rrt-help-none');
		if (!box || !input) { return; }
		box.hidden = false;
		var topics = Array.prototype.slice.call(root.querySelectorAll('.rrt-help-topic'));
		var texts = topics.map(function (t) { return t.textContent.toLowerCase(); });
		function run() {
			var w = words(input.value);
			var shown = 0;
			topics.forEach(function (t, i) {
				var hit = w.every(function (one) { return texts[i].indexOf(one) !== -1; });
				t.hidden = !hit;
				var entry = root.querySelector('[data-rrt-help-for="' + t.id.replace('rrt-help-', '') + '"]');
				if (entry) { entry.hidden = !hit; }
				if (hit) { shown++; }
			});
			Array.prototype.forEach.call(root.querySelectorAll('.rrt-help-group'), function (g) {
				g.hidden = !g.querySelector('li:not([hidden])');
			});
			none.hidden = shown > 0;
			count.textContent = w.length ? sprintf( /* translators: %d: how many guides match the search. */ _n( '%d guide matches.', '%d guides match.', shown, 'foxfire-studio' ), shown ) : '';
		}
		input.addEventListener('input', run);
		input.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' ? input.value !== '' : false) { e.preventDefault(); input.value = ''; run(); }
		});
	}

	/* Arriving at one guide: move the focus there so the keyboard and screen readers start in it. */
	function focusHash(root) {
		var id = window.location.hash.slice(1);
		if (id.indexOf('rrt-help-') !== 0) { return; }
		var t = document.getElementById(id);
		if (t ? root.contains(t) : false) { t.focus(); }
	}

	function start() {
		if (window.rrtHelpLink) { addLink(window.rrtHelpLink); }
		if (window.rrtHelpParts) { addParts(window.rrtHelpParts); }
		var root = document.querySelector('.rrt-help');
		if (root) {
			search(root);
			focusHash(root);
			window.addEventListener('hashchange', function () { focusHash(root); });
		}
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
}());
