/**
 * The Reading player block in the editor (1.28.0).
 *
 * The block is drawn by the site (includes/block.php), so this file only
 * makes the editor side: the choice of reading in the sidebar, the preview
 * of the player through the site's own block renderer, and a small card
 * when there is nothing to play yet. Plain JavaScript on WordPress's own
 * editor packages, so the plugin needs no build step.
 */
(function (wp) {
	'use strict';
	if (!wp || !wp.blocks || !wp.element) { return; }

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var __ = wp.i18n.__;
	var be = wp.blockEditor || wp.editor;
	var c = wp.components;
	var apiFetch = wp.apiFetch;
	var SSR = wp.serverSideRender;
	var NAME = 'foxfire-studio/player';

	function ask(args) {
		var q = [];
		Object.keys(args).forEach(function (k) {
			if (args[k] !== '' && args[k] !== null && args[k] !== undefined) { q.push(encodeURIComponent(k) + '=' + encodeURIComponent(args[k])); }
		});
		return apiFetch({ path: '/rrt/v1/block/readings' + (q.length ? '?' + q.join('&') : '') });
	}

	function label(r) {
		var bits = [r.title || __('(no title)', 'foxfire-studio')];
		if (r.type) { bits.push(r.type); }
		if (r.length) { bits.push(r.length); }
		if (r.status) { bits.push(r.status); }
		return bits.join(' · ');
	}

	/** The search box for Another reading. */
	function Chooser(props) {
		var st = useState([]);
		var list = st[0], setList = st[1];
		var words = useState('');
		var q = words[0], setQ = words[1];
		useEffect(function () {
			var live = true;
			var t = setTimeout(function () {
				ask({ search: q }).then(function (r) { if (live) { setList(r.readings || []); } }).catch(function () {});
			}, q ? 250 : 0);
			return function () { live = false; clearTimeout(t); };
		}, [q]);
		var opts = list.map(function (r) { return { value: String(r.id), label: label(r) }; });
		if (props.current && !opts.some(function (o) { return o.value === String(props.current.id); })) {
			opts.unshift({ value: String(props.current.id), label: label(props.current) });
		}
		return el(c.ComboboxControl, {
			label: __('Reading to show', 'foxfire-studio'),
			help: __('Type to search posts and collections that have a reading.', 'foxfire-studio'),
			value: props.value ? String(props.value) : null,
			options: opts,
			onFilterValueChange: setQ,
			onChange: function (v) { props.onChange(v ? parseInt(v, 10) || 0 : 0); }
		});
	}

	function Edit(props) {
		var a = props.attributes;
		var ctx = props.context || {};
		var own = ctx.postId ? parseInt(ctx.postId, 10) || 0 : 0;
		var id = a.reading || own;
		var s1 = useState(null);
		var info = s1[0], setInfo = s1[1];
		var s2 = useState(!!a.reading);
		var choosing = s2[0], setChoosing = s2[1];
		var blockProps = be.useBlockProps ? be.useBlockProps() : { className: 'wp-block-foxfire-studio-player' };

		useEffect(function () {
			var live = true;
			setInfo(null);
			ask({ include: id || '' }).then(function (r) { if (live) { setInfo(r); } }).catch(function () { if (live) { setInfo({ readings: [], show: '', failed: true }); } });
			return function () { live = false; };
		}, [id]);

		var item = info && id ? (info.readings || []).filter(function (r) { return r.id === id; })[0] : null;
		var pick = function (v) { props.setAttributes({ reading: v }); };

		var side = el(be.InspectorControls, null,
			el(c.PanelBody, { title: __('Reading', 'foxfire-studio'), initialOpen: true },
				el(c.RadioControl, {
					label: __('Show', 'foxfire-studio'),
					selected: (a.reading || choosing) ? 'other' : 'own',
					options: [
						{ value: 'own', label: own ? __('This post\'s reading', 'foxfire-studio') : __('Each post\'s own reading', 'foxfire-studio') },
						{ value: 'other', label: __('Another reading', 'foxfire-studio') }
					],
					onChange: function (v) {
						if (v === 'own') { setChoosing(false); pick(0); } else { setChoosing(true); }
					}
				}),
				(a.reading || choosing) ? el(Chooser, { value: a.reading, current: a.reading ? item : null, onChange: pick }) : null
			)
		);

		var body;
		if (!id) {
			body = el(c.Placeholder, {
				icon: 'format-audio',
				label: __('Reading player', 'foxfire-studio'),
				instructions: __('Each post\'s reading shows here, on the post itself.', 'foxfire-studio')
			}, choosing ? el(Chooser, { value: 0, onChange: pick }) : el(c.Button, { variant: 'secondary', isSecondary: true, onClick: function () { setChoosing(true); } }, __('Choose another reading', 'foxfire-studio')));
		} else if (!info) {
			body = el('div', { className: 'rrt-block-wait' }, el(c.Spinner));
		} else if (!item || !item.has) {
			var text = a.reading ? __('That reading is not available. Choose another one.', 'foxfire-studio') : __('This post has no reading yet.', 'foxfire-studio');
			body = el(c.Placeholder, { icon: 'format-audio', label: __('Reading player', 'foxfire-studio'), instructions: text },
				el('div', { className: 'rrt-block-actions' },
					(item && item.studio && !a.reading) ? el(c.Button, { variant: 'primary', isPrimary: true, href: item.studio, target: '_blank', rel: 'noopener' }, __('Open the studio', 'foxfire-studio'), el(c.VisuallyHidden || 'span', { className: c.VisuallyHidden ? undefined : 'screen-reader-text' }, __('(opens in a new tab)', 'foxfire-studio'))) : null,
					choosing ? el(Chooser, { value: a.reading, onChange: pick }) : el(c.Button, { variant: 'secondary', isSecondary: true, onClick: function () { setChoosing(true); } }, __('Choose another reading', 'foxfire-studio'))
				)
			);
		} else {
			var note = null;
			if (info.show === 'off' || info.show === 'editors') {
				var words = info.show === 'off' ?
					__('The player is switched off for visitors in Settings, then Foxfire Studio.', 'foxfire-studio') :
					__('Only editors see the player for now. Settings, then Foxfire Studio, switches it on for everyone.', 'foxfire-studio');
				note = el('p', { className: 'rrt-block-off' }, words, info.settings ? ' ' : null, info.settings ? el('a', { href: info.settings, target: '_blank', rel: 'noopener' }, __('Open Settings', 'foxfire-studio')) : null);
			}
			body = el('div', null, note, el(SSR, { block: NAME, attributes: a, urlQueryArgs: own ? { post_id: own } : {} }));
		}
		return el('div', blockProps, side, body);
	}

	wp.blocks.registerBlockType(NAME, {
		apiVersion: 3,
		edit: Edit,
		save: function () { return null; },
		transforms: {
			from: [{
				type: 'shortcode',
				tag: ['foxfire_player', 'reading_room_player'],
				attributes: {
					reading: {
						type: 'number',
						shortcode: function (atts) {
							var p = parseInt(atts && atts.named ? atts.named.post : 0, 10);
							return p > 0 ? p : 0;
						}
					}
				}
			}]
		}
	});
})(window.wp);
