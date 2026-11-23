<?php
/**
 * Help (1.32.0; groups, parts and section links since 1.36.0), run inside the test WordPress:
 *   php wp-cli.phar --path=<wordpress> eval-file tests/php/help.php
 * Every guide is complete, with no web addresses, and drawn on the Help screen; each screen's guide
 * exists; the Help tab and the heading link come on the plugin's screens and
 * nowhere else; the Help screen is for people who can edit posts; the
 * controls the guides name still exist under those names; the studio links
 * to the Help screen; docs/guide is up to date. Since 1.36.0 also: every guide
 * is in a group, parts and links are well formed, every plugin screen and every
 * section heading of Settings, Podcast feed and Recordings has a guide or part,
 * and those headings are the screens' own.
 * Prints one line per check and exits 1 if any fail. Leaves nothing behind.
 */

$GLOBALS['hp_pass'] = 0;
$GLOBALS['hp_fail'] = array();

function hp_t( $ok, $name, $detail = '' ) {
	if ( $ok ) {
		++$GLOBALS['hp_pass'];
		echo "ok   $name\n";
	} else {
		$GLOBALS['hp_fail'][] = $name;
		echo "FAIL $name" . ( '' !== (string) $detail ? ': ' . $detail : '' ) . "\n";
	}
}

wp_set_current_user( 1 );

/* ---- The guides ---- */
$hp_topics = rrt_help_topics();
hp_t( count( $hp_topics ) >= 25, 'there are at least twenty-five guides', count( $hp_topics ) );
hp_t( 'start' === array_key_first( $hp_topics ), 'Start here comes first' );
$hp_groups = rrt_help_groups();
$hp_bad   = array();
$hp_kinds = array( 'p', 'steps', 'list', 'note', 'go', 'h', 'links' );
$hp_words = '';
foreach ( $hp_topics as $hp_key => $hp_t ) {
	if ( ! preg_match( '/^[a-z]+$/', $hp_key ) || empty( $hp_t['title'] ) || empty( $hp_t['intro'] ) || empty( $hp_t['body'] ) ) {
		$hp_bad[] = $hp_key;
		continue;
	}
	if ( empty( $hp_t['group'] ) || ! isset( $hp_groups[ $hp_t['group'] ] ) ) {
		$hp_bad[] = $hp_key . ' group';
	}
	$hp_words .= ' ' . $hp_t['title'] . ' ' . $hp_t['intro'];
	$hp_pk     = array();
	foreach ( $hp_t['body'] as $hp_piece ) {
		if ( ! in_array( $hp_piece[0], $hp_kinds, true ) ) {
			$hp_bad[] = $hp_key . ' kind ' . $hp_piece[0];
		} elseif ( 'h' === $hp_piece[0] ) {
			if ( ! preg_match( '/^[a-z]+$/', (string) $hp_piece[1] ) || isset( $hp_pk[ $hp_piece[1] ] ) || '' === (string) $hp_piece[2] ) {
				$hp_bad[] = $hp_key . ' part ' . $hp_piece[1];
			}
			$hp_pk[ $hp_piece[1] ] = true;
			$hp_words             .= ' ' . $hp_piece[2];
		} elseif ( 'links' === $hp_piece[0] ) {
			foreach ( (array) $hp_piece[1] as $hp_l ) {
				if ( ! isset( $hp_topics[ $hp_l ] ) || $hp_l === $hp_key ) {
					$hp_bad[] = $hp_key . ' links to ' . $hp_l;
				}
			}
		} elseif ( in_array( $hp_piece[0], array( 'steps', 'list' ), true ) ) {
			if ( ! is_array( $hp_piece[1] ) || count( $hp_piece[1] ) < 2 ) {
				$hp_bad[] = $hp_key . ' short list';
			} else {
				$hp_words .= ' ' . implode( ' ', $hp_piece[1] );
			}
		} elseif ( 'go' === $hp_piece[0] ) {
			if ( ! preg_match( '#^(admin|options-general|edit)\.php\?#', (string) $hp_piece[2] ) ) {
				$hp_bad[] = $hp_key . ' go ' . $hp_piece[2];
			}
			$hp_words .= ' ' . $hp_piece[1];
		} else {
			$hp_words .= ' ' . $hp_piece[1];
		}
	}
}
hp_t( ! $hp_bad, 'every guide has a slug, a group, a title, an introduction and well-formed pieces, parts and links', implode( ', ', $hp_bad ) );
$hp_used = array_unique( wp_list_pluck( $hp_topics, 'group' ) );
hp_t( count( $hp_used ) === count( $hp_groups ), 'every group has a guide', implode( ',', array_diff( array_keys( $hp_groups ), $hp_used ) ) );
hp_t( str_word_count( $hp_words ) > 9000, 'the guides are full ones (over 9,000 words)', str_word_count( $hp_words ) );
hp_t( false === strpos( $hp_words, "\u{2014}" ) && false === strpos( $hp_words, "\u{2013}" ), 'no guide uses a long dash' );
hp_t( ! preg_match( '#https?://|www\.|\.(com|net|org)\b#i', $hp_words ), 'no guide carries a web address' );

/* The controls a guide names must still exist under those names somewhere in the plugin. */
$hp_code = '';
foreach ( array_merge( glob( RRT_DIR . 'includes/*.php' ), glob( RRT_DIR . 'views/*.php' ), glob( RRT_DIR . 'assets/js/*.js' ) ) as $hp_f ) {
	if ( false === strpos( $hp_f, 'help' ) ) {
		$hp_code .= file_get_contents( $hp_f ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}
$hp_named = array( 'Use this recording', 'Send to my computer', 'Try it here anyway', 'Phone take mode', 'Record my voice', 'Clean up audio', 'Whisper review', 'Check microphone', 'Warm up', 'Begin', 'Finish recording', 'Your reading is ready', 'Open full review', 'Recover it', 'I said it right', 'Make edited MP3', 'Check the edited version', 'Tidy', 'Combine takes', 'Continue', 'Hear the polish', 'Share for feedback', 'Shared takes', 'Follow my voice', 'Prompter view', 'Single-key shortcuts', 'Readings to make', 'Your progress', 'Pronunciation', 'Reading audio', 'Only where I put it', 'At the top of the post, unless the post or its template places it', 'Readability', 'Make it readable', 'Take off the post', 'Make a clip', 'Suggested clips', 'Make the clip', 'Full-length video', 'Make the video', 'Publish the podcast feed', 'Ready for directories?', 'Member feeds', 'Download counting', 'Storage offload', 'Sponsor messages', 'Readings in this collection', 'Audiobook', 'For your privacy policy', 'Listener analytics', 'Returning listeners', 'Trusted proxies', 'Pings', 'Run setup again', 'New here', 'Broadcast sound', 'Listening player', 'Teleprompter', 'Standard', 'Larger', 'Automatic', 'Insights', 'Sources', 'Reports', 'Recordings', 'Kept aside', 'Sent from your phone', 'Your take shelf on the site', 'Put back', 'Delete for good', 'Open a recording', 'Edit audio', 'Whole sentence', 'Whole paragraph', 'Retake', 'Hear it in place', 'Use this take', 'Bring back the original', 'Apply the ticked cuts', 'Best of each', 'Build the reading', 'Clear the shelf', 'Captions (.vtt)', 'Captions for read along', 'Reading (MP3)', 'Read by', 'Kind of reading', 'Reading language', 'Who can hear this reading', 'Members first, then everyone', 'Members only', 'Finish for', 'Music bed', 'Add tracks', 'Listen with music', 'Under the voice', 'Keep an audiobook copy', 'Use for this take', 'Back to the Settings choice', 'Pace by kind of reading', 'Ritual and chant', 'Opening drill', 'Focus', 'Open prompter view', 'Mirror', 'Fade above and below', 'Bright text on black', 'Leave prompter view', 'Add a note', 'Not needed', 'Export CSV', 'Export backup', 'Set goals', 'Make the MP3 here', 'A take from your phone', 'Open it', 'Use for the feed', 'Show the player to', 'Show name', 'Give members their own feed', 'Who is a member', 'Your members podcast feed', 'Email them their feed address', 'Open in Overcast', 'Place sponsor messages in the feed', 'Thank sponsors under the player', 'Members hear sponsor messages too', 'Where it can go', 'Which readings', 'Count downloads by podcast apps', 'Check counting', 'Make the method page', 'Shared networks', 'Measurement prefixes', 'Check this setup', 'Serve readings from the storage below', 'Copy everything now', 'Delete the outside copies', 'Gap between readings', 'In the podcast feed', 'Record in the studio', 'Use for the audiobook', 'Retail sample', 'Make the audiobook', 'Opening credits', 'Closing credits', 'Link builder', 'Copy link', 'Changes', 'Send the report by email on a schedule', 'Past reports', 'Measure listening', 'Leave out', 'Keep stats on this site', 'When the plugin is deleted', 'Save take shelf settings', 'Delete every shelf on the site', 'Space for every shelf', 'Save embedded players', 'Clear saved pictures', 'Check private folders', 'Make it readable', 'Teleprompter look', 'Wording', 'Square', 'Vertical', 'Wide', 'Smaller file' );
$hp_gone  = array();
foreach ( $hp_named as $hp_n ) {
	if ( false === strpos( $hp_words, $hp_n ) ) {
		$hp_gone[] = $hp_n . ' (not in the guides)';
	} elseif ( false === strpos( $hp_code, "'" . $hp_n ) && false === strpos( $hp_code, $hp_n . "'" ) ) {
		$hp_gone[] = $hp_n;
	}
}
hp_t( ! $hp_gone, 'every control the guides name exists under that name', implode( ', ', $hp_gone ) );

/* ---- Screens and their guides ---- */
$hp_missing = array();
function hp_target_ok( $target ) {
	$b = explode( ':', $target, 2 );
	$t = rrt_help_topics();
	return isset( $t[ $b[0] ] ) && ( ! isset( $b[1] ) || isset( rrt_help_parts( $b[0] )[ $b[1] ] ) );
}
foreach ( rrt_help_screens() as $hp_s => $hp_k ) {
	if ( ! hp_target_ok( $hp_k ) ) {
		$hp_missing[] = $hp_s . ' => ' . $hp_k;
	}
}
foreach ( rrt_help_sections() as $hp_s => $hp_list ) {
	foreach ( $hp_list as $hp_h => $hp_k ) {
		if ( ! hp_target_ok( $hp_k ) ) {
			$hp_missing[] = $hp_s . ' ' . $hp_h . ' => ' . $hp_k;
		}
	}
}
hp_t( ! $hp_missing, 'each screen and section opens a guide or part that exists', implode( ', ', $hp_missing ) );
hp_t( false !== strpos( rrt_help_url( 'stats:reports' ), '#rrt-help-stats-reports' ) && false !== strpos( rrt_help_url( 'stats:nope' ), '#rrt-help-stats' ) && false === strpos( rrt_help_url( 'stats:nope' ), 'stats-' ), 'rrt_help_url opens one part, or its guide for an unknown part' );
hp_t( false !== strpos( rrt_help_group_url( 'record' ), '#rrt-help-group-record' ) && false === strpos( rrt_help_group_url( 'nope' ), '#' ), 'rrt_help_group_url opens a group' );
hp_t( false !== strpos( rrt_help_url( 'phone' ), 'page=rrt-help#rrt-help-phone' ), 'rrt_help_url opens one guide', rrt_help_url( 'phone' ) );
hp_t( false === strpos( rrt_help_url( 'nope' ), '#' ) && false === strpos( rrt_help_url(), '#' ), 'and the top for no guide or an unknown one' );

$hp_get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
function hp_topic_for( $page, $post_type = '', $base = '', $extra = array() ) {
	$_GET = array_merge( '' !== $page ? array( 'page' => $page ) : array(), $extra );
	$s    = (object) array( 'post_type' => $post_type, 'base' => $base, 'id' => $base );
	return rrt_help_screen_topic( $s );
}
hp_t( 'stats' === hp_topic_for( 'rrt-dashboard' ), 'Overview opens the numbers guide' );
hp_t( 'clips' === hp_topic_for( 'rrt-readings', '', '', array( 'post' => '5' ) ) && 'stats:readings' === hp_topic_for( 'rrt-readings' ), 'a reading\'s page opens the clips guide, the list its part of the numbers guide' );
hp_t( 'feed' === hp_topic_for( 'rrt-podcast' ) && 'settings' === hp_topic_for( 'rrt-settings' ) && 'recordings' === hp_topic_for( 'rrt-recordings' ), 'Podcast feed, Settings and Recordings open their own guides' );
hp_t( 'collections' === hp_topic_for( '', 'rrt_collection', 'edit' ) && 'collections' === hp_topic_for( '', 'rrt_collection', 'post' ) && 'sponsors' === hp_topic_for( '', 'rrt_spot', 'edit' ), 'collections and sponsor messages open theirs' );
hp_t( 'studio:backlog' === hp_topic_for( 'rrt-backlog' ) && 'studio:pronounce' === hp_topic_for( 'rrt-pronounce' ) && 'studio:progress' === hp_topic_for( 'rrt-progress' ) && 'share' === hp_topic_for( 'rrt-shares' ), 'Readings to make, Pronunciation, Your progress and Shared takes open their parts' );
hp_t( '' === hp_topic_for( 'rrt-help' ) && '' === hp_topic_for( 'rrt-setup' ) && '' === hp_topic_for( 'other-plugin' ) && '' === hp_topic_for( '', 'post', 'edit' ) && '' === rrt_help_screen_topic( null ), 'the Help screen, the wizard, other plugins and ordinary posts get no Help tab' );
$_GET = $hp_get;

/* The Help tab itself, on a real screen object. */
$_GET = array( 'page' => 'rrt-podcast' );
$hp_scr = WP_Screen::get( 'foxfire-studio_page_rrt-podcast' );
do_action( 'current_screen', $hp_scr );
$hp_tab = $hp_scr->get_help_tab( 'rrt-help' );
hp_t( $hp_tab && false !== strpos( $hp_tab['content'], 'rrt-help-feed' ) && false !== strpos( $hp_tab['content'], 'The podcast feed' ), 'Podcast feed gets a Help tab that links to its guide', $hp_tab ? $hp_tab['content'] : 'none' );
hp_t( false !== strpos( (string) $hp_scr->get_help_sidebar(), 'page=rrt-help' ), 'and a sidebar link to every guide' );
$_GET = $hp_get;

/* ---- The Help screen ---- */
global $submenu;
$hp_sub = isset( $submenu['rrt-dashboard'] ) ? wp_list_pluck( $submenu['rrt-dashboard'], 2 ) : array();
if ( ! $hp_sub ) {
	do_action( 'admin_menu' );
	$hp_sub = isset( $submenu['rrt-dashboard'] ) ? wp_list_pluck( $submenu['rrt-dashboard'], 2 ) : array();
}
hp_t( in_array( 'rrt-help', $hp_sub, true ), 'Help is in the Foxfire Studio menu', implode( ',', $hp_sub ) );
hp_t( 'rrt-help' === end( $hp_sub ), 'as its last entry', end( $hp_sub ) );

/* 1.36.0: every screen in the Foxfire Studio menu opens a guide. */
$hp_screens = rrt_help_screens();
$hp_none    = array();
foreach ( $hp_sub as $hp_slug ) {
	if ( in_array( $hp_slug, array( 'rrt-help', 'rrt-setup' ), true ) ) {
		continue;
	}
	$hp_k = $hp_slug;
	if ( preg_match( '#post_type=([a-z_]+)#', $hp_slug, $hp_m ) ) {
		$hp_k = $hp_m[1];
	} elseif ( preg_match( '#page=([a-z-]+)#', $hp_slug, $hp_m ) ) {
		$hp_k = $hp_m[1];
	}
	if ( ! isset( $hp_screens[ $hp_k ] ) ) {
		$hp_none[] = $hp_slug;
	}
}
hp_t( ! $hp_none, 'every screen in the menu opens a guide', implode( ', ', $hp_none ) );

/* 1.36.0: every section heading on Settings, Podcast feed and Recordings has a part, and the list's headings are the screens' own. */
$hp_was_shelf = get_option( 'rrt_shelf', null );
update_option( 'rrt_shelf', array( 'on' => true, 'limit' => 2048 ) );
/* Member feeds on too, so Members with a feed is drawn (found on the live site: a heading shown only while a feature is on). */
$hp_was_pod = get_option( 'rrt_podcast', null );
update_option( 'rrt_podcast', array_merge( is_array( $hp_was_pod ) ? $hp_was_pod : array(), array( 'm_on' => true ) ) );
$hp_pages = array(
	'rrt-settings'   => 'rrt_settings_page',
	'rrt-podcast'    => 'rrt_podcast_page',
	'rrt-recordings' => 'rrt_dash_page_recordings',
);
$hp_sec   = rrt_help_sections();
foreach ( $hp_pages as $hp_slug => $hp_fn ) {
	$_GET = array( 'page' => $hp_slug );
	ob_start();
	call_user_func( $hp_fn );
	$hp_out = (string) ob_get_clean();
	preg_match_all( '#<h2[^>]*>(.*?)</h2>#s', $hp_out, $hp_hs );
	$hp_heads = array_map(
		function ( $h ) {
			return trim( preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( $h ), ENT_QUOTES ) ) );
		},
		$hp_hs[1]
	);
	$hp_heads = array_values( array_filter( $hp_heads ) );
	$hp_no    = array_diff( $hp_heads, array_keys( $hp_sec[ $hp_slug ] ) );
	hp_t( count( $hp_heads ) >= 3 && ! $hp_no, $hp_slug . ': every section heading has a Help link', implode( ' | ', $hp_no ) . ' (of ' . implode( ' | ', $hp_heads ) . ')' );
	preg_match_all( '#<h[23][^>]*>(.*?)</h[23]>#s', $hp_out, $hp_all );
	$hp_all = array_map(
		function ( $h ) {
			return trim( preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( $h ), ENT_QUOTES ) ) );
		},
		$hp_all[1]
	);
	$hp_gone = array_diff( array_keys( $hp_sec[ $hp_slug ] ), $hp_all );
	hp_t( ! $hp_gone, $hp_slug . ': every heading in the list is on the screen', implode( ' | ', $hp_gone ) );
}
if ( null === $hp_was_pod ) {
	delete_option( 'rrt_podcast' );
} else {
	update_option( 'rrt_podcast', $hp_was_pod );
}
if ( null === $hp_was_shelf ) {
	delete_option( 'rrt_shelf' );
} else {
	update_option( 'rrt_shelf', $hp_was_shelf );
}
$_GET = $hp_get;

ob_start();
rrt_help_page();
$hp_html = ob_get_clean();
$hp_all  = true;
foreach ( $hp_topics as $hp_key => $hp_t ) {
	if ( false === strpos( $hp_html, 'id="rrt-help-' . $hp_key . '"' ) || false === strpos( $hp_html, 'href="#rrt-help-' . $hp_key . '"' ) || false === strpos( $hp_html, esc_html( $hp_t['title'] ) ) ) {
		$hp_all = false;
	}
}
hp_t( $hp_all, 'the Help screen draws every guide with its entry in the list' );
hp_t( 1 === substr_count( $hp_html, '<h1' ), 'it has one main heading' );
hp_t( false !== strpos( $hp_html, 'class="rrt-help-search" hidden' ) && false !== strpos( $hp_html, '<label for="rrt-help-q"' ), 'the search box is labelled and waits for the script' );
hp_t( strpos( $hp_html, 'rrt-help-news' ) < strpos( $hp_html, 'rrt-help-toc' ), 'What\'s new comes before the guides' );
$hp_news = rrt_help_news();
hp_t( isset( $hp_news[ RRT_VERSION ] ) && false !== strpos( $hp_html, '(this version)' ), 'What\'s new has this version, marked as such', RRT_VERSION );
hp_t( false !== strpos( $hp_html, esc_url( admin_url( 'admin.php?page=rrt-podcast' ) ) ) || false !== strpos( $hp_html, esc_url( admin_url( 'options-general.php?page=rrt-settings' ) ) ), 'a guide\'s button opens its screen' );
$hp_gall = true;
foreach ( rrt_help_groups() as $hp_g => $hp_label ) {
	if ( false === strpos( $hp_html, 'id="rrt-help-group-' . $hp_g . '"' ) ) {
		$hp_gall = false;
	}
}
hp_t( $hp_gall, 'the list of guides is in groups, each one reachable' );
hp_t( false !== strpos( $hp_html, 'id="rrt-help-settings-look"' ) && false !== strpos( $hp_html, 'class="rrt-help-links"' ), 'parts have their own anchors, and Start here links to guides' );

$hp_ed = wp_insert_user( array( 'user_login' => 'hp_ed', 'user_pass' => wp_generate_password(), 'role' => 'editor' ) );
$hp_sb = wp_insert_user( array( 'user_login' => 'hp_sub', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
wp_set_current_user( $hp_ed );
ob_start();
rrt_help_page();
hp_t( false !== strpos( ob_get_clean(), 'rrt-help-first' ), 'editors may read the Help screen' );
wp_set_current_user( $hp_sb );
$hp_died = false;
add_filter(
	'wp_die_handler',
	function () {
		return function () {
			throw new Exception( 'died' );
		};
	}
);
try {
	ob_start();
	rrt_help_page();
} catch ( Exception $e ) {
	$hp_died = true;
}
ob_end_clean();
hp_t( $hp_died, 'subscribers may not' );
wp_set_current_user( 1 );
wp_delete_user( $hp_ed );
wp_delete_user( $hp_sb );

/* ---- The studio's link ---- */
$hp_view = (string) file_get_contents( RRT_DIR . 'views/teleprompter.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
hp_t( false !== strpos( $hp_view, "rrt_help_group_url( 'record' )" ) && false !== strpos( $hp_view, 'target="_blank"' ) && false !== strpos( $hp_view, 'id="tpHelpLink"' ), 'the studio\'s Controls panel links to the recording guides in a new tab' );

/* ---- docs/guide ---- */
$hp_tool = dirname( RRT_DIR, 2 ) . '/tools/help-export.php';
if ( is_file( $hp_tool ) ) {
	$hp_out = array();
	exec( 'php ' . escapeshellarg( $hp_tool ) . ' --check 2>&1', $hp_out, $hp_rc ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	hp_t( 0 === $hp_rc, 'docs/guide matches the guides', implode( ' ', $hp_out ) );
} else {
	echo "skip docs/guide (the plugin is not inside the repository)\n";
}

echo "\n" . $GLOBALS['hp_pass'] . ' passed, ' . count( $GLOBALS['hp_fail'] ) . " failed\n";
exit( $GLOBALS['hp_fail'] ? 1 : 0 );
