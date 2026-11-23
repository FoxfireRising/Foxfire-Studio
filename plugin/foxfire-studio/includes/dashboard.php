<?php
/**
 * The Foxfire Studio dashboard in WP-admin (0.11.0).
 *
 * Screens: Overview, Readings (and each reading's detail), Sources, Your
 * progress, Insights (0.19.0, observations from insights.php), Recordings,
 * Reports (0.12.0, in report-admin.php), and a link to Settings. Numbers come from
 * dashboard-data.php; charts are drawn by assets/js/dashboard.js from data
 * printed on the page, so nothing loads from outside the site.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once RRT_DIR . 'includes/dashboard-data.php';

add_action(
	'admin_menu',
	function () {
		$listen = rrt_dash_cap();
		add_menu_page( __( 'Foxfire Studio', 'foxfire-studio' ), __( 'Foxfire Studio', 'foxfire-studio' ), 'edit_posts', 'rrt-dashboard', 'rrt_dash_page_home', 'dashicons-microphone', 26 );
		add_submenu_page( 'rrt-dashboard', __( 'Overview', 'foxfire-studio' ), __( 'Overview', 'foxfire-studio' ), 'edit_posts', 'rrt-dashboard', 'rrt_dash_page_home' );
		add_submenu_page( 'rrt-dashboard', __( 'Readings', 'foxfire-studio' ), __( 'Readings', 'foxfire-studio' ), $listen, 'rrt-readings', 'rrt_dash_page_readings' );
		/* Collections (1.19.0): the post type's own screens, listed here. */
		add_submenu_page( 'rrt-dashboard', __( 'Collections', 'foxfire-studio' ), __( 'Collections', 'foxfire-studio' ), 'edit_posts', 'edit.php?post_type=rrt_collection' );
		add_submenu_page( 'rrt-dashboard', __( 'Readings to make', 'foxfire-studio' ), __( 'Readings to make', 'foxfire-studio' ), $listen, 'rrt-backlog', 'rrt_backlog_page' );
		add_submenu_page( 'rrt-dashboard', __( 'Pronunciation', 'foxfire-studio' ), __( 'Pronunciation', 'foxfire-studio' ), 'edit_posts', 'rrt-pronounce', 'rrt_pron_page' );
		add_submenu_page( 'rrt-dashboard', __( 'Sources', 'foxfire-studio' ), __( 'Sources', 'foxfire-studio' ), $listen, 'rrt-sources', 'rrt_dash_page_sources' );
		add_submenu_page( 'rrt-dashboard', __( 'Your progress', 'foxfire-studio' ), __( 'Your progress', 'foxfire-studio' ), 'edit_posts', 'rrt-progress', 'rrt_dash_page_progress' );
		add_submenu_page( 'rrt-dashboard', __( 'Insights', 'foxfire-studio' ), __( 'Insights', 'foxfire-studio' ), 'edit_posts', 'rrt-insights', 'rrt_dash_page_insights' );
		add_submenu_page( 'rrt-dashboard', __( 'Recordings', 'foxfire-studio' ), __( 'Recordings', 'foxfire-studio' ), $listen, 'rrt-recordings', 'rrt_dash_page_recordings' );
		add_submenu_page( 'rrt-dashboard', __( 'Reports', 'foxfire-studio' ), __( 'Reports', 'foxfire-studio' ), 'manage_options', 'rrt-reports', 'rrt_report_page' );
		add_submenu_page( 'rrt-dashboard', __( 'Settings', 'foxfire-studio' ), __( 'Settings', 'foxfire-studio' ), 'manage_options', 'options-general.php?page=rrt-settings' );
	}
);

add_action(
	'admin_enqueue_scripts',
	function ( $hook ) {
		if ( false === strpos( (string) $hook, 'rrt-' ) ) {
			return;
		}
		wp_enqueue_style( 'rrt-dashboard', RRT_URL . 'assets/css/dashboard.css', array(), RRT_VERSION . '.' . filemtime( RRT_DIR . 'assets/css/dashboard.css' ) );
		wp_enqueue_script( 'rrt-dashboard', RRT_URL . 'assets/js/dashboard.js', array( 'wp-i18n' ), RRT_VERSION . '.' . filemtime( RRT_DIR . 'assets/js/dashboard.js' ), true );
		wp_set_script_translations( 'rrt-dashboard', 'foxfire-studio', rrt_languages_dir() );
	}
);

/*
 * ---------------------------------------------------------------------------
 * Small pieces
 * ---------------------------------------------------------------------------
 */

/**
 * A calendar day (Y-m-d, already in the site's time zone) as text. Formatted
 * as it stands, with no time zone conversion, so it never shifts a day.
 */
function rrt_dash_day( $d, $format = 'M j' ) {
	$t = strtotime( $d . ' 12:00:00 UTC' );
	return $t ? date_i18n( $format, $t ) : $d;
}

/** A share with the count behind it: "41% (12 of 29)". */
function rrt_dash_pct( $n, $d, $with_count = true ) {
	if ( ! $d ) {
		return '–';
	}
	$p = round( 100 * $n / $d );
	return $p . '%' . ( $with_count ? ' (' . number_format_i18n( $n ) . ' of ' . number_format_i18n( $d ) . ')' : '' );
}

/** Hours and minutes from seconds: "19.4 h", or "42 min" under two hours. */
function rrt_dash_hours( $s ) {
	if ( $s < 7200 ) {
		return number_format_i18n( round( $s / 60 ) ) . ' min';
	}
	return number_format_i18n( $s / 3600, 1 ) . ' h';
}

/**
 * The change against the previous period, or nothing when either period has
 * too few plays for the change to mean much.
 *
 * @param float  $now   This period's value.
 * @param float  $then  The previous period's value.
 * @param bool   $ok    Whether both periods have enough plays.
 * @param string $kind  'pct' for a relative change, 'pt' for percentage points, 'x' for a speed.
 */
function rrt_dash_delta( $now, $then, $ok, $kind = 'pct' ) {
	if ( ! $ok || null === $now || null === $then ) {
		return '';
	}
	if ( 'pt' === $kind ) {
		$d = round( 100 * ( $now - $then ) );
		$t = abs( $d ) . ' pt';
	} elseif ( 'x' === $kind ) {
		$d = round( $now - $then, 2 );
		$t = abs( $d ) . '×';
	} else {
		if ( ! $then ) {
			return '';
		}
		$d = round( 100 * ( $now - $then ) / $then );
		$t = abs( $d ) . '%';
	}
	if ( 0 == $d ) { // phpcs:ignore Universal.Operators.StrictComparisons
		return '<span class="rrt-d">' . esc_html__( 'no change', 'foxfire-studio' ) . '</span>';
	}
	$up = $d > 0;
	return '<span class="rrt-d ' . ( $up ? 'is-up' : 'is-down' ) . '"><span aria-hidden="true">' . ( $up ? '▲' : '▼' ) . '</span> ' . esc_html( ( $up ? 'up ' : 'down ' ) . $t ) . '</span>';
}

/** A headline number tile. */
function rrt_dash_tile( $label, $value, $sub = '', $delta = '' ) {
	echo '<div class="rrt-tile"><div class="rrt-tile-label">' . esc_html( $label ) . '</div><div class="rrt-tile-value">' . esc_html( $value ) . '</div>';
	if ( '' !== $sub ) {
		echo '<div class="rrt-tile-sub">' . esc_html( $sub ) . '</div>';
	}
	if ( '' !== $delta ) {
		echo '<div class="rrt-tile-delta">' . wp_kses( $delta, array( 'span' => array( 'class' => true, 'aria-hidden' => true ) ) ) . '</div>';
	}
	echo '</div>';
}

/** A "What these numbers mean" box. */
function rrt_dash_defs( $items ) {
	echo '<details class="rrt-defs"><summary><span aria-hidden="true">ⓘ</span> ' . esc_html__( 'What these numbers mean', 'foxfire-studio' ) . '</summary><dl>';
	foreach ( $items as $k => $v ) {
		echo '<dt>' . esc_html( $k ) . '</dt><dd>' . esc_html( $v ) . '</dd>';
	}
	echo '</dl></details>';
}

/** Data for a chart, printed as JSON for dashboard.js. */
function rrt_dash_chart( $id, $data, $label ) {
	echo '<figure class="rrt-chart" id="' . esc_attr( $id ) . '" aria-label="' . esc_attr( $label ) . '"></figure>';
	echo '<script type="application/json" data-chart="' . esc_attr( $id ) . '">' . wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP ) . '</script>';
}

/*
 * ---------------------------------------------------------------------------
 * Wider listening numbers (1.2.0)
 * ---------------------------------------------------------------------------
 */

/** Seconds as "8 s", "1:05" or "30+ min". */
function rrt_dash_secs( $s ) {
	if ( null === $s ) {
		return '–';
	}
	if ( $s >= 1800 ) {
		return __( '30+ min', 'foxfire-studio' );
	}
	return $s < 60 ? sprintf( /* translators: %d: seconds. */ __( '%d s', 'foxfire-studio' ), (int) $s ) : rrt_clock( $s );
}

/** Read or listen: a bar in four parts with the counts beside it. */
function rrt_dash_readlisten_block( $rl ) {
	echo '<h2>' . esc_html__( 'Read or listen', 'foxfire-studio' ) . '</h2>';
	if ( ! $rl['measured'] ) {
		echo '<p class="rrt-small">' . esc_html__( 'No visits in this period yet.', 'foxfire-studio' ) . '</p>';
		return;
	}
	$parts = array(
		'listen' => array( __( 'Listened only', 'foxfire-studio' ), 'rrt-s1' ),
		'both'   => array( __( 'Read and listened', 'foxfire-studio' ), 'rrt-s3' ),
		'read'   => array( __( 'Read only', 'foxfire-studio' ), 'rrt-s4' ),
		'pass'   => array( __( 'Passed through', 'foxfire-studio' ), 'rrt-sf' ),
	);
	$bar = '';
	$key = '';
	foreach ( $parts as $k => $p ) {
		$pc = 100 * $rl[ $k ] / $rl['measured'];
		if ( $rl[ $k ] ) {
			$bar .= '<span class="' . esc_attr( $p[1] ) . '" style="width:' . esc_attr( round( $pc, 2 ) ) . '%" title="' . esc_attr( $p[0] . ': ' . rrt_dash_pct( $rl[ $k ], $rl['measured'] ) ) . '"></span>';
		}
		$key .= '<tr><td><span class="rrt-key ' . esc_attr( $p[1] ) . '" aria-hidden="true"></span> ' . esc_html( $p[0] ) . '</td><td class="num">' . esc_html( rrt_dash_pct( $rl[ $k ], $rl['measured'] ) ) . '</td></tr>';
	}
	echo '<div class="rrt-stack" role="img" aria-label="' . esc_attr__( 'Visits by whether they read, listened, both or neither', 'foxfire-studio' ) . '">' . $bar . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts above.
	echo '<table class="widefat striped rrt-table rrt-key-table"><tbody>' . $key . '</tbody></table>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts above.
	if ( $rl['measured'] < $rl['views'] ) {
		echo '<p class="rrt-small">' . esc_html( sprintf( /* translators: 1: visits counted, 2: all visits. */ __( 'Counted for %1$s of %2$s visits. Days summarised before these numbers began, and whose visits are no longer kept, are left out.', 'foxfire-studio' ), number_format_i18n( $rl['measured'] ), number_format_i18n( $rl['views'] ) ) ) . '</p>';
	}
}

/** Time to press play: medians and bands, for phones and tablets and for computers. */
function rrt_dash_first_play_block( $fp ) {
	echo '<h2>' . esc_html__( 'Time to press play', 'foxfire-studio' ) . '</h2>';
	if ( ! $fp['all']['n'] ) {
		echo '<p class="rrt-small">' . esc_html__( 'No plays in this period yet.', 'foxfire-studio' ) . '</p>';
		return;
	}
	echo '<div class="rrt-tiles">';
	rrt_dash_tile( __( 'Median wait', 'foxfire-studio' ), rrt_dash_secs( $fp['all']['median'] ), sprintf( /* translators: %s: number of plays. */ _n( 'from %s play', 'from %s plays', $fp['all']['n'], 'foxfire-studio' ), number_format_i18n( $fp['all']['n'] ) ) );
	rrt_dash_tile( __( 'Phones and tablets', 'foxfire-studio' ), rrt_dash_secs( $fp['m']['median'] ), $fp['m']['n'] ? sprintf( /* translators: %s: number of plays. */ _n( 'from %s play', 'from %s plays', $fp['m']['n'], 'foxfire-studio' ), number_format_i18n( $fp['m']['n'] ) ) : '' );
	rrt_dash_tile( __( 'Computers', 'foxfire-studio' ), rrt_dash_secs( $fp['c']['median'] ), $fp['c']['n'] ? sprintf( /* translators: %s: number of plays. */ _n( 'from %s play', 'from %s plays', $fp['c']['n'], 'foxfire-studio' ), number_format_i18n( $fp['c']['n'] ) ) : '' );
	echo '</div>';
	$cols = array(
		'b10'  => __( 'Under 10 s', 'foxfire-studio' ),
		'b30'  => __( '10 to 30 s', 'foxfire-studio' ),
		'b120' => __( '30 s to 2 min', 'foxfire-studio' ),
		'more' => __( 'Over 2 min', 'foxfire-studio' ),
	);
	echo '<table class="widefat striped rrt-table"><thead><tr><th>' . esc_html__( 'Pressed play after', 'foxfire-studio' ) . '</th>';
	foreach ( $cols as $c ) {
		echo '<th class="num rrt-nowrap">' . esc_html( $c ) . '</th>';
	}
	echo '</tr></thead><tbody>';
	foreach ( array( 'm' => __( 'Phones and tablets', 'foxfire-studio' ), 'c' => __( 'Computers', 'foxfire-studio' ), 'all' => __( 'All', 'foxfire-studio' ) ) as $k => $label ) {
		if ( 'all' !== $k && ! $fp[ $k ]['n'] ) {
			continue;
		}
		echo '<tr><td>' . esc_html( $label ) . '</td>';
		foreach ( array_keys( $cols ) as $b ) {
			echo '<td class="num" title="' . esc_attr( rrt_dash_pct( $fp[ $k ]['bands'][ $b ], $fp[ $k ]['n'] ) ) . '">' . esc_html( rrt_dash_pct( $fp[ $k ]['bands'][ $b ], $fp[ $k ]['n'], false ) ) . '</td>';
		}
		echo '</tr>';
	}
	echo '</tbody></table>';
	echo '<p class="rrt-small">' . esc_html__( 'Long waits suggest visitors do not notice the player.', 'foxfire-studio' ) . '</p>';
}

/** New and returning listeners, when the switch is on or there are numbers from when it was. */
function rrt_dash_returning_block( $rl ) {
	$on = ! empty( rrt_settings()['listen']['returning'] );
	$n  = $rl['new'] + $rl['back'];
	if ( ! $on && ! $n ) {
		return;
	}
	echo '<h2>' . esc_html__( 'New and returning listeners', 'foxfire-studio' ) . '</h2>';
	if ( ! $n ) {
		echo '<p class="rrt-small">' . esc_html__( 'Nothing yet. Each real listen from now on counts as new or returning.', 'foxfire-studio' ) . '</p>';
		return;
	}
	echo '<div class="rrt-tiles">';
	rrt_dash_tile( __( 'New listeners', 'foxfire-studio' ), rrt_dash_pct( $rl['new'], $n, false ), number_format_i18n( $rl['new'] ) . ' of ' . number_format_i18n( $n ) );
	rrt_dash_tile( __( 'Returning listeners', 'foxfire-studio' ), rrt_dash_pct( $rl['back'], $n, false ), number_format_i18n( $rl['back'] ) . ' of ' . number_format_i18n( $n ) );
	echo '</div>';
}

/** Podcast app downloads: on the Overview, when counting is on or downloads were ever counted (1.2.0; IAB v2.3 in 1.24.0). */
function rrt_dash_downloads_block( $range, $axis ) {
	if ( ! function_exists( 'rrt_dl_on' ) || ( ! rrt_dl_on() && ! rrt_dl_any() ) ) {
		return;
	}
	$st = rrt_dl_stats( $range['from'], $range['to'] );
	$pv = rrt_dl_stats( $range['pfrom'], $range['pto'] );
	echo '<h2 id="rrt-downloads">' . esc_html__( 'Podcast apps', 'foxfire-studio' ) . '</h2>';
	if ( function_exists( 'rrt_dl_review_html' ) ) {
		echo rrt_dl_review_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped where it is made.
	}
	$days  = rrt_dash_days( $range['from'], $range['to'] );
	$cons  = array_sum( $st['consumers'] );
	$cdays = count( $st['consumers'] );
	echo '<div class="rrt-tiles">';
	rrt_dash_tile( __( 'Downloads', 'foxfire-studio' ), number_format_i18n( $st['total'] ), '', rrt_dash_delta( $st['total'], $pv['total'], $st['total'] >= rrt_dash_min_plays() && $pv['total'] >= rrt_dash_min_plays() ) );
	if ( $cdays ) {
		/* translators: %s: number of days. */
		rrt_dash_tile( __( 'Podcast consumers a day', 'foxfire-studio' ), number_format_i18n( $cons / max( 1, count( $days ) ), 1 ), sprintf( _n( 'average over %s day', 'average over %s days', count( $days ), 'foxfire-studio' ), number_format_i18n( count( $days ) ) ) );
	}
	if ( ! empty( $st['feeds']['members'] ) ) {
		rrt_dash_tile( __( 'From members\' feeds', 'foxfire-studio' ), rrt_dash_pct( $st['feeds']['members'], $st['total'], false ), number_format_i18n( $st['feeds']['members'] ) );
	}
	echo '</div>';
	if ( ! $st['total'] ) {
		echo '<p class="rrt-small">' . esc_html( rrt_podcast_settings()['on'] ? __( 'No downloads in this period yet.', 'foxfire-studio' ) : __( 'No downloads in this period. The podcast feed is off, so apps cannot find the episodes yet (members\' feeds still count).', 'foxfire-studio' ) ) . '</p>';
		return;
	}
	$values = array();
	$people = array();
	foreach ( $days as $d ) {
		$values[] = isset( $st['days'][ $d ] ) ? $st['days'][ $d ] : 0;
		$people[] = isset( $st['consumers'][ $d ] ) ? $st['consumers'][ $d ] : 0;
	}
	$markers = $axis['markers'];
	$since   = rrt_dl_settings()['since'];
	$idx     = array_search( $since, $days, true );
	if ( $st['old'] && false !== $idx ) {
		$markers[] = array( 'i' => $idx, 'label' => __( 'Counted to the IAB guidelines from here', 'foxfire-studio' ) );
	}
	$series = array( array( 'name' => __( 'Downloads', 'foxfire-studio' ), 'values' => $values ) );
	if ( $cons ) {
		$series[] = array( 'name' => __( 'Podcast consumers', 'foxfire-studio' ), 'values' => $people );
	}
	rrt_dash_chart(
		'rrt-c-downloads',
		array(
			'type'    => 'line',
			'x'       => $axis['x'],
			'markers' => $markers,
			'unit'    => 'downloads',
			'series'  => $series,
		),
		__( 'Podcast app downloads each day', 'foxfire-studio' )
	);
	if ( $st['old'] ) {
		/* translators: %s: number of downloads. */
		echo '<p class="rrt-small">' . esc_html( sprintf( _n( '%s of these was counted the 1.2.0 way, before Foxfire Studio followed the IAB guidelines; those have no device or feed.', '%s of these were counted the 1.2.0 way, before Foxfire Studio followed the IAB guidelines; those have no device or feed.', $st['old'], 'foxfire-studio' ), number_format_i18n( $st['old'] ) ) ) . '</p>';
	}
	$odd = function_exists( 'rrt_dl_unusual' ) ? rrt_dl_unusual( $range['from'], $range['to'] ) : array();
	if ( $odd ) {
		echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Unusual days: worth a look, in case an app changed how it downloads or something automatic is asking.', 'foxfire-studio' ) . '</p><ul class="rrt-dl-odd">';
		foreach ( array_slice( $odd, 0, 8 ) as $o ) {
			$what = 'all' === $o['what'] ? __( 'all downloads', 'foxfire-studio' ) : rrt_ua_app_label( $o['what'] );
			/* translators: 1: a date, 2: what (all downloads or an app), 3: downloads that day, 4: the usual number. */
			echo '<li>' . esc_html( sprintf( __( '%1$s, %2$s: %3$s, where the 28 days before had a median of %4$s.', 'foxfire-studio' ), rrt_dash_day( $o['day'] ), $what, number_format_i18n( $o['n'] ), number_format_i18n( $o['median'], 1 ) ) ) . '</li>';
		}
		echo '</ul></div>';
	}
	echo '<div class="rrt-cols"><div><table class="widefat striped rrt-table"><thead><tr><th>' . esc_html__( 'App', 'foxfire-studio' ) . '</th><th class="num">' . esc_html__( 'Downloads', 'foxfire-studio' ) . '</th></tr></thead><tbody>';
	foreach ( array_slice( $st['apps'], 0, 15, true ) as $app => $n ) {
		echo '<tr><td>' . esc_html( function_exists( 'rrt_ua_app_label' ) ? rrt_ua_app_label( $app ) : $app ) . '</td><td class="num">' . esc_html( rrt_dash_pct( $n, $st['total'] ) ) . '</td></tr>';
	}
	echo '</tbody></table></div><div><table class="widefat striped rrt-table"><thead><tr><th>' . esc_html__( 'Episode', 'foxfire-studio' ) . '</th><th class="num">' . esc_html__( 'Downloads', 'foxfire-studio' ) . '</th></tr></thead><tbody>';
	foreach ( array_slice( $st['posts'], 0, 10, true ) as $pid => $n ) {
		echo '<tr><td><a href="' . esc_url( rrt_dash_url( 'rrt-readings', array( 'post' => $pid ) ) ) . '">' . esc_html( get_the_title( $pid ) ) . '</a></td><td class="num">' . esc_html( number_format_i18n( $n ) ) . '</td></tr>';
	}
	echo '</tbody></table></div>';
	$dev = array_diff_key( $st['devices'], array( '' => 0 ) );
	if ( $dev || ! empty( $st['feeds']['members'] ) ) {
		echo '<div><table class="widefat striped rrt-table"><thead><tr><th>' . esc_html__( 'Device', 'foxfire-studio' ) . '</th><th class="num">' . esc_html__( 'Downloads', 'foxfire-studio' ) . '</th></tr></thead><tbody>';
		foreach ( $st['devices'] as $d => $n ) {
			echo '<tr><td>' . esc_html( rrt_ua_device_label( $d ) ) . '</td><td class="num">' . esc_html( rrt_dash_pct( $n, $st['total'] - $st['old'] ) ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
	echo '</div>';
}

/** The definitions for the 1.2.0 numbers, for the "What these numbers mean" boxes. */
function rrt_dash_wider_defs() {
	$out = array(
		__( 'Read or listen', 'foxfire-studio' ) => sprintf(
			/* translators: 1: seconds, 2: a percentage, 3: words a minute. */
			__( 'Each visit (one visitor on one reading in one day) in one of four groups. Listened means at least %1$d seconds heard, or the whole reading. Read means scrolling at least %2$d%% of the way through the post\'s text with the page on screen for at least a third of the time an average reader needs for its words (%3$d words a minute). Passed through means neither.', 'foxfire-studio' ),
			RRT_REAL_LISTEN_S,
			RRT_READ_SCROLL,
			RRT_READ_WPM
		),
		__( 'Time to press play', 'foxfire-studio' ) => __( 'Seconds from the page opening to the first press of play. The median is the middle play: half pressed play sooner, half later.', 'foxfire-studio' ),
	);
	if ( ! empty( rrt_settings()['listen']['returning'] ) ) {
		$out[ __( 'New and returning listeners', 'foxfire-studio' ) ] = __( 'Of the visits that listened, those whose browser had already listened here within the time set in Settings, Returning listeners. A new browser, a private window or cleared site data counts as new.', 'foxfire-studio' );
	}
	if ( function_exists( 'rrt_dl_on' ) && ( rrt_dl_on() || rrt_dl_any() ) ) {
		$out[ __( 'Podcast apps', 'foxfire-studio' ) ] = __( 'Downloads of episodes through the feed\'s short addresses and members\' feeds, counted following the IAB Tech Lab guidelines (version 2.3): once per podcast consumer (an address and app, or a member), episode file and day, once the app has asked for the file\'s tags and a minute of audio, leaving out test requests, Apple Watch duplicates, bots, data centres and VPNs. Podcast consumers a day counts each consumer once a day across all episodes. A download is not a listen: many apps download new episodes by themselves. Spotify keeps its own copy, so most of its listeners show only in Spotify for Creators. The method page (Podcast feed, How downloads are counted) says it all in full.', 'foxfire-studio' );
	}
	return $out;
}

/** A link to a dashboard screen, keeping the date range. */
function rrt_dash_url( $page, $args = array() ) {
	$keep = array();
	foreach ( array( 'range', 'from', 'to' ) as $k ) {
		if ( isset( $_GET[ $k ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$keep[ $k ] = sanitize_text_field( wp_unslash( $_GET[ $k ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}
	}
	return add_query_arg( array_merge( $keep, $args, array( 'page' => $page ) ), admin_url( 'admin.php' ) );
}

/** A friendly channel name. */
function rrt_dash_channel( $c ) {
	$n = array(
		'instagram' => __( 'Instagram', 'foxfire-studio' ), 'facebook' => __( 'Facebook', 'foxfire-studio' ), 'threads' => __( 'Threads', 'foxfire-studio' ), 'bluesky' => __( 'Bluesky', 'foxfire-studio' ), 'tiktok' => 'TikTok',
		'x' => 'X', 'reddit' => __( 'Reddit', 'foxfire-studio' ), 'youtube' => 'YouTube', 'pinterest' => __( 'Pinterest', 'foxfire-studio' ), 'linkedin' => 'LinkedIn', 'mastodon' => __( 'Mastodon', 'foxfire-studio' ),
		'email' => __( 'Email', 'foxfire-studio' ), 'google' => __( 'Google', 'foxfire-studio' ), 'search' => __( 'Other search', 'foxfire-studio' ), 'internal' => __( 'This site', 'foxfire-studio' ), 'direct' => __( 'Direct or unknown', 'foxfire-studio' ),
		'campaign' => __( 'Tagged link', 'foxfire-studio' ), 'other' => __( 'Other sites', 'foxfire-studio' ),
	);
	return isset( $n[ $c ] ) ? $n[ $c ] : ( '' === $c ? __( 'Unknown', 'foxfire-studio' ) : $c );
}

/** The heading, screen links and date range picker shared by every screen. */
function rrt_dash_header( $title, $page, $range, $extra = array(), $show_range = true, $note = null ) {
	$tabs = array(
		'rrt-dashboard'  => __( 'Overview', 'foxfire-studio' ),
		'rrt-readings'   => __( 'Readings', 'foxfire-studio' ),
		'rrt-backlog'    => __( 'Readings to make', 'foxfire-studio' ),
		'rrt-pronounce'  => __( 'Pronunciation', 'foxfire-studio' ),
		'rrt-sources'    => __( 'Sources', 'foxfire-studio' ),
		'rrt-progress'   => __( 'Your progress', 'foxfire-studio' ),
		'rrt-insights'   => __( 'Insights', 'foxfire-studio' ),
		'rrt-recordings' => __( 'Recordings', 'foxfire-studio' ),
		'rrt-reports'    => __( 'Reports', 'foxfire-studio' ),
	);
	echo '<div class="wrap rrt-dash"><h1>' . esc_html( $title ) . '</h1>';
	echo '<nav class="nav-tab-wrapper rrt-tabs">';
	foreach ( $tabs as $slug => $name ) {
		if ( ! in_array( $slug, array( 'rrt-progress', 'rrt-dashboard', 'rrt-insights', 'rrt-pronounce' ), true ) && ! current_user_can( rrt_dash_cap() ) ) {
			continue;
		}
		if ( 'rrt-reports' === $slug && ! current_user_can( 'manage_options' ) ) {
			continue;
		}
		echo '<a class="nav-tab' . ( $slug === $page ? ' nav-tab-active' : '' ) . '" href="' . esc_url( rrt_dash_url( $slug ) ) . '">' . esc_html( $name ) . '</a>';
	}
	echo '</nav>';
	if ( ! $show_range ) {
		return;
	}
	echo '<form class="rrt-range" method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
	echo '<input type="hidden" name="page" value="' . esc_attr( $page ) . '">';
	foreach ( $extra as $k => $v ) {
		echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
	}
	echo '<label>' . esc_html__( 'Period', 'foxfire-studio' ) . ' <select name="range" class="rrt-range-select">';
	foreach ( array( '7' => 'Last 7 days', '28' => 'Last 28 days', '90' => 'Last 90 days', '365' => 'Last 12 months', 'custom' => __( 'Choose dates', 'foxfire-studio' ) ) as $k => $v ) {
		echo '<option value="' . esc_attr( $k ) . '"' . selected( $range['key'], $k, false ) . '>' . esc_html( $v ) . '</option>';
	}
	echo '</select></label> ';
	echo '<span class="rrt-range-custom"' . ( 'custom' === $range['key'] ? '' : ' hidden' ) . '><label>' . esc_html__( 'From', 'foxfire-studio' ) . ' <input type="date" name="from" value="' . esc_attr( $range['from'] ) . '"></label> <label>to <input type="date" name="to" value="' . esc_attr( $range['to'] ) . '"></label></span> ';
	echo '<button class="button">' . esc_html__( 'Show', 'foxfire-studio' ) . '</button>';
	if ( null !== $note ) {
		echo ' <span class="rrt-range-note">' . esc_html( $note ) . '</span></form>';
		return;
	}
	echo ' <span class="rrt-range-note">' . esc_html( sprintf( /* translators: 1: first day, 2: last day, 3: number of days, 4: time zone. */ __( '%1$s to %2$s, compared with the %3$d days before. Days run midnight to midnight in %4$s.', 'foxfire-studio' ), rrt_dash_day( $range['from'], substr( $range['from'], 0, 4 ) === substr( $range['to'], 0, 4 ) ? 'M j' : 'M j, Y' ), rrt_dash_day( $range['to'], 'M j, Y' ), $range['days'], $range['tz'] ) ) . '</span>';
	echo '</form>';
}

/** The "Mark a change" form. */
function rrt_dash_marker_form( $post_id = 0, $back = '' ) {
	echo '<form class="rrt-marker-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'rrt_marker' );
	echo '<input type="hidden" name="action" value="rrt_marker"><input type="hidden" name="post" value="' . (int) $post_id . '"><input type="hidden" name="back" value="' . esc_attr( $back ) . '">';
	echo '<strong>' . esc_html__( 'Mark a change', 'foxfire-studio' ) . '</strong> <label>on <input type="date" name="day" value="' . esc_attr( wp_date( 'Y-m-d' ) ) . '" required></label> ';
	echo '<label class="rrt-grow">' . esc_html__( 'what changed', 'foxfire-studio' ) . ' <input type="text" name="label" maxlength="140" required placeholder="' . esc_attr( $post_id ? __( 'Shorter introduction, new recording', 'foxfire-studio' ) : __( 'Posted at 7 pm, new microphone', 'foxfire-studio' ) ) . '"></label> ';
	echo '<button class="button">' . esc_html__( 'Add', 'foxfire-studio' ) . '</button></form>';
	echo '<p class="description">' . esc_html__( 'Changes appear as flags on the charts, so before and after can be compared.', 'foxfire-studio' ) . ' ' . ( $post_id ? esc_html__( 'This one belongs to this reading.', 'foxfire-studio' ) : esc_html__( 'These belong to the whole site.', 'foxfire-studio' ) ) . '</p>';
}

/** The list of markers in a period, each with a delete button. */
function rrt_dash_marker_list( $markers, $back ) {
	if ( ! $markers ) {
		return;
	}
	echo '<ul class="rrt-markers">';
	foreach ( $markers as $m ) {
		$del = wp_nonce_url( add_query_arg( array( 'action' => 'rrt_marker_delete', 'id' => (int) $m['id'], 'back' => rawurlencode( $back ) ), admin_url( 'admin-post.php' ) ), 'rrt_marker_delete_' . (int) $m['id'] );
		echo '<li><span class="rrt-flag" aria-hidden="true">⚑</span> ' . esc_html( rrt_dash_day( $m['day'] ) . ': ' . $m['label'] . ( $m['post_id'] ? ' (' . get_the_title( (int) $m['post_id'] ) . ')' : '' ) ) . ' <a class="rrt-del" href="' . esc_url( $del ) . '">remove</a></li>';
	}
	echo '</ul>';
}

/** A "Download as CSV" button for a table on the page. */
function rrt_dash_csv_button( $table_id, $name ) {
	echo '<button type="button" class="button button-small rrt-csv" data-table="' . esc_attr( $table_id ) . '" data-name="' . esc_attr( $name ) . '">' . esc_html__( 'Download CSV', 'foxfire-studio' ) . '</button>';
}

/** The day labels and markers a time chart needs. */
function rrt_dash_time_axis( $range, $markers ) {
	$days = rrt_dash_days( $range['from'], $range['to'] );
	$idx  = array_flip( $days );
	$m    = array();
	foreach ( $markers as $mk ) {
		if ( isset( $idx[ $mk['day'] ] ) ) {
			$m[] = array( 'i' => $idx[ $mk['day'] ], 'label' => $mk['label'] );
		}
	}
	return array( 'x' => array_map( function ( $d ) { return rrt_dash_day( $d ); }, $days ), 'markers' => $m );
}

/** Shown when there is nothing yet. */
function rrt_dash_empty( $text ) {
	echo '<p class="rrt-empty">' . esc_html( $text ) . '</p>';
}

/*
 * ---------------------------------------------------------------------------
 * Overview
 * ---------------------------------------------------------------------------
 */

function rrt_dash_page_home() {
	if ( ! current_user_can( rrt_dash_cap() ) ) {
		rrt_dash_page_progress();
		return;
	}
	rrt_dash_freshen();
	$range = rrt_dash_range();
	$min   = rrt_dash_min_plays();
	$now   = rrt_dash_totals( $range['from'], $range['to'] );
	$prev  = rrt_dash_totals( $range['pfrom'], $range['pto'] );
	$ok    = $now['plays'] >= $min && $prev['plays'] >= $min;
	$lst   = rrt_dash_listeners( $range['from'], $range['to'] );
	$share = function ( $t, $k ) { return $t['plays'] ? $t[ $k ] / $t['plays'] : null; };
	$back  = rrt_dash_url( 'rrt-dashboard' );

	rrt_dash_header( __( 'Foxfire Studio', 'foxfire-studio' ), 'rrt-dashboard', $range );

	if ( ! $now['views'] && ! $prev['views'] ) {
		rrt_dash_empty( __( 'No listens in this period yet. Once visitors play a reading, the numbers appear here within a few minutes.', 'foxfire-studio' ) );
	}

	echo '<div class="rrt-tiles">';
	rrt_dash_tile( __( 'Plays', 'foxfire-studio' ), number_format_i18n( $now['plays'] ), 'about ' . number_format_i18n( $lst ) . ' ' . ( 1 === $lst ? 'listener' : 'listeners' ), rrt_dash_delta( $now['plays'], $prev['plays'], $ok ) );
	rrt_dash_tile( __( 'Pressed play', 'foxfire-studio' ), rrt_dash_pct( $now['plays'], $now['views'], false ), number_format_i18n( $now['plays'] ) . ' of ' . number_format_i18n( $now['views'] ) . ' visitors', rrt_dash_delta( $now['views'] ? $now['plays'] / $now['views'] : null, $prev['views'] ? $prev['plays'] / $prev['views'] : null, $ok, 'pt' ) );
	rrt_dash_tile( __( 'Still listening at 1 minute', 'foxfire-studio' ), rrt_dash_pct( $now['held60'], $now['plays'], false ), number_format_i18n( $now['held60'] ) . ' of ' . number_format_i18n( $now['plays'] ) . ' plays', rrt_dash_delta( $share( $now, 'held60' ), $share( $prev, 'held60' ), $ok, 'pt' ) );
	rrt_dash_tile( __( 'Heard to the end', 'foxfire-studio' ), rrt_dash_pct( $now['completions'], $now['plays'], false ), number_format_i18n( $now['completions'] ) . ' of ' . number_format_i18n( $now['plays'] ) . ' plays', rrt_dash_delta( $share( $now, 'completions' ), $share( $prev, 'completions' ), $ok, 'pt' ) );
	rrt_dash_tile( __( 'Average speed', 'foxfire-studio' ), null === $now['rate'] ? '–' : $now['rate'] . '×', '', rrt_dash_delta( $now['rate'], $prev['rate'], $ok, 'x' ) );
	rrt_dash_tile( __( 'Listening time', 'foxfire-studio' ), rrt_dash_hours( $now['listened'] ), '', rrt_dash_delta( $now['listened'], $prev['listened'], $ok ) );
	echo '</div>';
	if ( ! $ok && ( $now['plays'] || $prev['plays'] ) ) {
		echo '<p class="rrt-small">' . esc_html( sprintf( /* translators: %d: plays. */ __( 'Changes against the previous period appear once both periods have at least %d plays. Until then, small differences are mostly chance.', 'foxfire-studio' ), (int) $min ) ) . '</p>';
	}

	$markers = rrt_dash_markers( $range['from'], $range['to'] );
	$series  = rrt_dash_series( $range['from'], $range['to'] );
	$pseries = rrt_dash_series( $range['pfrom'], $range['pto'] );
	$axis    = rrt_dash_time_axis( $range, $markers );
	echo '<h2>' . esc_html__( 'Plays each day', 'foxfire-studio' ) . '</h2>';
	rrt_dash_chart(
		'rrt-c-plays',
		array(
			'type'    => 'line',
			'x'       => $axis['x'],
			'markers' => $axis['markers'],
			'unit'    => 'plays',
			'series'  => array(
				array( 'name' => __( 'This period', 'foxfire-studio' ), 'values' => wp_list_pluck( $series, 'plays' ) ),
				array( 'name' => __( 'Previous period', 'foxfire-studio' ), 'values' => wp_list_pluck( $pseries, 'plays' ), 'faint' => true ),
			),
		),
		__( 'Plays each day', 'foxfire-studio' )
	);

	$readings = rrt_dash_readings( $range['from'], $range['to'] );
	usort( $readings, function ( $a, $b ) { return $b['plays'] <=> $a['plays']; } );
	$sources = rrt_dash_sources( $range['from'], $range['to'] );

	echo '<div class="rrt-cols"><div>';
	echo '<h2>' . esc_html__( 'Top readings', 'foxfire-studio' ) . '</h2><table class="widefat striped rrt-table"><thead><tr><th>' . esc_html__( 'Reading', 'foxfire-studio' ) . '</th><th class="num">' . esc_html__( 'Plays', 'foxfire-studio' ) . '</th><th class="num">' . esc_html__( 'Heard to the end', 'foxfire-studio' ) . '</th></tr></thead><tbody>';
	$shown = 0;
	foreach ( $readings as $r ) {
		if ( ! $r['plays'] || $shown >= 5 ) {
			continue;
		}
		++$shown;
		echo '<tr><td><a href="' . esc_url( rrt_dash_url( 'rrt-readings', array( 'post' => $r['post'] ) ) ) . '">' . esc_html( $r['title'] ) . '</a></td><td class="num">' . esc_html( number_format_i18n( $r['plays'] ) ) . '</td><td class="num">' . esc_html( rrt_dash_pct( $r['completions'], $r['plays'] ) ) . '</td></tr>';
	}
	if ( ! $shown ) {
		echo '<tr><td colspan="3">' . esc_html__( 'No plays yet.', 'foxfire-studio' ) . '</td></tr>';
	}
	echo '</tbody></table></div><div>';
	echo '<h2>' . esc_html__( 'Top sources', 'foxfire-studio' ) . '</h2><table class="widefat striped rrt-table"><thead><tr><th>' . esc_html__( 'Came from', 'foxfire-studio' ) . '</th><th class="num">' . esc_html__( 'Plays', 'foxfire-studio' ) . '</th><th class="num">' . esc_html__( 'Pressed play', 'foxfire-studio' ) . '</th><th class="num">' . esc_html__( 'Heard to the end', 'foxfire-studio' ) . '</th></tr></thead><tbody>';
	foreach ( array_slice( $sources, 0, 5 ) as $s ) {
		echo '<tr><td>' . esc_html( rrt_dash_channel( $s['channel'] ) ) . '</td><td class="num">' . esc_html( number_format_i18n( $s['plays'] ) ) . '</td><td class="num">' . esc_html( rrt_dash_pct( $s['plays'], $s['views'] ) ) . '</td><td class="num">' . esc_html( rrt_dash_pct( $s['completions'], $s['plays'] ) ) . '</td></tr>';
	}
	if ( ! $sources ) {
		echo '<tr><td colspan="4">' . esc_html__( 'No visits yet.', 'foxfire-studio' ) . '</td></tr>';
	}
	echo '</tbody></table></div></div>';

	/* Wider listening numbers (1.2.0). */
	$rl = rrt_dash_readlisten( $range['from'], $range['to'] );
	echo '<div class="rrt-cols"><div>';
	rrt_dash_readlisten_block( $rl );
	echo '</div><div>';
	rrt_dash_first_play_block( rrt_dash_first_play( $range['from'], $range['to'] ) );
	echo '</div></div>';
	rrt_dash_returning_block( $rl );
	rrt_dash_downloads_block( $range, $axis );

	/* Worth a look: the biggest drop-off among readings with enough plays to trust it. */
	$look = null;
	foreach ( $readings as $r ) {
		if ( $r['plays'] >= $min && null !== $r['drop'] && ( ! $look || $r['drop_fall'] > $look['drop_fall'] ) ) {
			$look = $r;
		}
	}
	echo '<div class="rrt-note"><strong>' . esc_html__( 'Worth a look:', 'foxfire-studio' ) . '</strong> ';
	if ( $look ) {
		echo '<a href="' . esc_url( rrt_dash_url( 'rrt-readings', array( 'post' => $look['post'] ) ) ) . '">' . esc_html( $look['title'] ) . '</a>' . ' ' . esc_html( sprintf( /* translators: 1: share of listeners, 2: time in the reading. */ __( 'loses %1$d%% of its listeners around %2$s. The reading detail shows the sentence playing there.', 'foxfire-studio' ), round( 100 * $look['drop_fall'] ), rrt_clock( $look['drop'] ) ) );
	} else {
		echo esc_html( sprintf( /* translators: %d: plays. */ __( 'nothing stands out yet. Drop-off points are suggested once a reading has %d plays in the period.', 'foxfire-studio' ), $min ) );
	}
	echo '</div>';

	rrt_insight_overview();

	echo '<h2>' . esc_html__( 'Changes', 'foxfire-studio' ) . '</h2>';
	rrt_dash_marker_list( $markers, $back );
	rrt_dash_marker_form( 0, $back );

	echo '<h2>' . esc_html__( 'Raw data', 'foxfire-studio' ) . '</h2><p>' . esc_html__( 'Every number here is built from each visit\'s record. Download them to check any number or to work with them elsewhere:', 'foxfire-studio' ) . ' ';
	echo '<a class="button button-small" href="' . esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'rrt_export', 'what' => 'visits', 'from' => $range['from'], 'to' => $range['to'] ), admin_url( 'admin-post.php' ) ), 'rrt_export' ) ) . '">' . esc_html__( 'Visits in this period (CSV)', 'foxfire-studio' ) . '</a> ';
	echo '<a class="button button-small" href="' . esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'rrt_export', 'what' => 'events', 'from' => $range['from'], 'to' => $range['to'] ), admin_url( 'admin-post.php' ) ), 'rrt_export' ) ) . '">' . esc_html( sprintf( /* translators: %d: days. */ __( 'Every event (CSV, last %d days only)', 'foxfire-studio' ), (int) rrt_settings()['listen']['events_days'] ) ) . '</a></p>';

	rrt_dash_defs(
		array(
			__( 'Plays', 'foxfire-studio' ) => __( 'Visitors who pressed play. One listener on one reading counts once a day, however often they reload or come back that day.', 'foxfire-studio' ),
			__( 'Listeners', 'foxfire-studio' ) => __( 'An estimate: different listeners each day, added up over the period. The same person on two days counts twice, and people sharing one connection may count once.', 'foxfire-studio' ),
			__( 'Pressed play', 'foxfire-studio' ) => __( 'Plays divided by visitors who opened a post with a reading.', 'foxfire-studio' ),
			__( 'Still listening at 1 minute', 'foxfire-studio' ) => __( 'Plays that reached one minute into the reading, or a quarter of the way through a reading shorter than four minutes. The clearest sign of whether the opening holds people.', 'foxfire-studio' ),
			__( 'Heard to the end', 'foxfire-studio' ) => __( 'Plays that reached the last 5 seconds of the reading.', 'foxfire-studio' ),
			__( 'Average speed', 'foxfire-studio' ) => __( 'The playback speed of every 5 seconds heard, averaged.', 'foxfire-studio' ),
			__( 'Listening time', 'foxfire-studio' ) => __( 'Seconds of audio heard, counted in 5 second stretches. A stretch counts once at least 2 seconds of it were played.', 'foxfire-studio' ),
			__( 'Changes', 'foxfire-studio' ) => sprintf( /* translators: %d: plays. */ __( 'Shown against the previous period of the same length, only once both periods have at least %d plays.', 'foxfire-studio' ), $min ),
		) + rrt_dash_wider_defs()
	);
	echo '</div>';
}

/*
 * ---------------------------------------------------------------------------
 * Readings and reading detail
 * ---------------------------------------------------------------------------
 */

function rrt_dash_page_readings() {
	if ( ! current_user_can( rrt_dash_cap() ) ) {
		wp_die( esc_html__( 'Not allowed.', 'foxfire-studio' ) );
	}
	rrt_dash_freshen();
	$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
	if ( $post_id ) {
		rrt_dash_page_reading( $post_id );
		return;
	}
	$range = rrt_dash_range();
	$rows  = rrt_dash_readings( $range['from'], $range['to'] );
	rrt_dash_header( __( 'Readings', 'foxfire-studio' ), 'rrt-readings', $range );

	$tags = array();
	foreach ( $rows as $r ) {
		foreach ( $r['tags'] as $t ) {
			$tags[ $t ] = 1;
		}
	}
	ksort( $tags );
	echo '<div class="rrt-tools">';
	if ( $tags ) {
		echo '<label>' . esc_html__( 'Tag', 'foxfire-studio' ) . ' <select class="rrt-filter" data-table="rrt-t-readings" data-col="tags"><option value="">' . esc_html__( 'All', 'foxfire-studio' ) . '</option>';
		foreach ( array_keys( $tags ) as $t ) {
			echo '<option>' . esc_html( $t ) . '</option>';
		}
		echo '</select></label> ';
	}
	rrt_dash_csv_button( 'rrt-t-readings', 'readings-' . $range['from'] . '-to-' . $range['to'] );
	echo '</div>';

	echo '<table class="widefat striped rrt-table rrt-sortable" id="rrt-t-readings"><thead><tr>';
	foreach ( array( __( 'Reading', 'foxfire-studio' ), __( 'Published', 'foxfire-studio' ), __( 'Length', 'foxfire-studio' ), __( 'Tempo', 'foxfire-studio' ), __( 'Visitors', 'foxfire-studio' ), __( 'Plays', 'foxfire-studio' ), __( 'Pressed play', 'foxfire-studio' ), __( 'Average heard', 'foxfire-studio' ), __( 'Heard to the end', 'foxfire-studio' ), __( 'Speed', 'foxfire-studio' ), __( 'Biggest drop-off', 'foxfire-studio' ), __( 'Top source', 'foxfire-studio' ) ) as $i => $h ) {
		echo '<th' . ( $i > 1 ? ' class="num"' : '' ) . ' data-sort="' . ( $i < 2 || 11 === $i ? 'text' : 'num' ) . '"><button type="button">' . esc_html( $h ) . '</button></th>';
	}
	echo '</tr></thead><tbody>';
	foreach ( $rows as $r ) {
		$avg = $r['plays'] ? $r['listened'] / $r['plays'] : 0;
		echo '<tr data-tags="' . esc_attr( implode( '|', $r['tags'] ) ) . '">';
		/* 1.26.0: a reading without captions has no transcript for the player or the feed. */
		$nocap = function_exists( 'rrt_a11y_needs_captions' ) && rrt_a11y_needs_captions( (int) $r['post'] );
		echo '<td data-v="' . esc_attr( $r['title'] ) . '"><a href="' . esc_url( rrt_dash_url( 'rrt-readings', array( 'post' => $r['post'] ) ) ) . '">' . esc_html( $r['title'] ) . '</a>' . ( $nocap ? ' <span class="rrt-small rrt-nocap">' . esc_html__( 'No captions yet', 'foxfire-studio' ) . '</span>' : '' ) . '</td>';
		echo '<td data-v="' . esc_attr( $r['published'] ) . '">' . esc_html( $r['published'] ? wp_date( 'M j, Y', strtotime( $r['published'] . ' UTC' ) ) : '' ) . '</td>';
		echo '<td class="num" data-v="' . esc_attr( $r['duration'] ) . '">' . esc_html( $r['duration'] ? rrt_clock( $r['duration'] ) : '' ) . '</td>';
		echo '<td class="num" data-v="' . esc_attr( $r['tempo'] ) . '">' . esc_html( $r['tempo'] ? $r['tempo'] . ' wpm' : '' ) . '</td>';
		echo '<td class="num" data-v="' . (int) $r['views'] . '">' . esc_html( number_format_i18n( $r['views'] ) ) . '</td>';
		echo '<td class="num" data-v="' . (int) $r['plays'] . '">' . esc_html( number_format_i18n( $r['plays'] ) ) . '</td>';
		echo '<td class="num" data-v="' . esc_attr( $r['views'] ? $r['plays'] / $r['views'] : 0 ) . '">' . esc_html( rrt_dash_pct( $r['plays'], $r['views'], false ) ) . '</td>';
		echo '<td class="num" data-v="' . esc_attr( $avg ) . '">' . esc_html( $r['plays'] ? rrt_clock( $avg ) : '' ) . '</td>';
		echo '<td class="num" data-v="' . esc_attr( $r['plays'] ? $r['completions'] / $r['plays'] : 0 ) . '">' . esc_html( rrt_dash_pct( $r['completions'], $r['plays'] ) ) . '</td>';
		echo '<td class="num" data-v="' . esc_attr( $r['rate'] ) . '">' . esc_html( null === $r['rate'] ? '' : $r['rate'] . '×' ) . '</td>';
		echo '<td class="num" data-v="' . esc_attr( null === $r['drop'] ? -1 : $r['drop'] ) . '">' . esc_html( null === $r['drop'] ? '' : rrt_clock( $r['drop'] ) . ' (' . round( 100 * $r['drop_fall'] ) . '%)' ) . '</td>';
		echo '<td data-v="' . esc_attr( $r['source'] ) . '">' . esc_html( '' === $r['source'] ? '' : rrt_dash_channel( $r['source'] ) ) . '</td>';
		echo '</tr>';
	}
	if ( ! $rows ) {
		echo '<tr><td colspan="12">' . esc_html__( 'No readings yet.', 'foxfire-studio' ) . '</td></tr>';
	}
	echo '</tbody></table>';
	echo '<p class="rrt-small">' . esc_html__( 'Click a column to sort. Published and tempo come from the reading\'s Recordings record.', 'foxfire-studio' ) . '</p>';
	rrt_dash_defs(
		array(
			__( 'Visitors', 'foxfire-studio' ) => __( 'Listeners who opened the post, once per listener per day.', 'foxfire-studio' ),
			__( 'Average heard', 'foxfire-studio' ) => __( 'Listening time divided by plays.', 'foxfire-studio' ),
			__( 'Biggest drop-off', 'foxfire-studio' ) => __( 'The moment where the share still listening falls most steeply compared with the rest of the reading, with the share of plays lost there. Only falls of at least 5% are shown, and never the last 10 seconds.', 'foxfire-studio' ),
			__( 'Top source', 'foxfire-studio' ) => __( 'The channel that brought the most plays.', 'foxfire-studio' ),
		)
	);
	echo '</div>';
}

function rrt_dash_page_reading( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		wp_die( esc_html__( 'No such post.', 'foxfire-studio' ) );
	}
	$range = rrt_dash_range();
	$min   = rrt_dash_min_plays();
	$recs  = rrt_dash_post_recordings( $post_id );
	$rec_id = isset( $_GET['rec'] ) ? absint( $_GET['rec'] ) : ( $recs ? (int) $recs[0]['id'] : 0 ); // phpcs:ignore WordPress.Security.NonceVerification
	$rec   = null;
	foreach ( $recs as $r ) {
		if ( (int) $r['id'] === $rec_id ) {
			$rec = $r;
		}
	}
	$back = rrt_dash_url( 'rrt-readings', array( 'post' => $post_id ) );
	rrt_dash_header( get_the_title( $post ), 'rrt-readings', $range, array( 'post' => $post_id, 'rec' => $rec_id ) );
	if ( function_exists( 'rrt_kept_notice' ) ) {
		rrt_kept_notice();
	}
	echo '<p><a href="' . esc_url( rrt_dash_url( 'rrt-readings' ) ) . '">' . esc_html__( '← All readings', 'foxfire-studio' ) . '</a> · <a href="' . esc_url( get_permalink( $post ) ) . '">' . esc_html__( 'View the post', 'foxfire-studio' ) . '</a>';
	if ( count( $recs ) > 1 ) {
		echo ' · Recording: ';
		$kept_recs = function_exists( 'rrt_kept_rec_ids' ) ? rrt_kept_rec_ids() : array();
		foreach ( $recs as $r ) {
			$label = ( $r['is_current'] ? 'current' : ( isset( $kept_recs[ (int) $r['id'] ] ) ? 'kept aside' : 'earlier' ) ) . ', ' . ( $r['published_at'] ? wp_date( 'M j, Y', strtotime( $r['published_at'] . ' UTC' ) ) : __( 'no date', 'foxfire-studio' ) );
			if ( (int) $r['id'] === $rec_id ) {
				echo '<strong>' . esc_html( $label ) . '</strong> ';
			} else {
				echo '<a href="' . esc_url( rrt_dash_url( 'rrt-readings', array( 'post' => $post_id, 'rec' => (int) $r['id'] ) ) ) . '">' . esc_html( $label ) . '</a> ';
			}
		}
	}
	echo '</p>';
	/* 1.26.0: without captions the reading has no transcript for listeners who cannot hear it. */
	if ( function_exists( 'rrt_a11y_needs_captions' ) && rrt_a11y_needs_captions( $post_id ) ) {
		echo '<div class="notice notice-info inline rrt-nocap-notice"><p>' . esc_html__( 'No captions yet: listeners who cannot hear the reading have no transcript in the player, and podcast apps have none either. In the studio, open the review of the take on this post, press Captions (.vtt), then choose that file under Captions in the post\'s Reading audio box, or use Use this recording, which adds them for you.', 'foxfire-studio' ) . '</p></div>';
	}
	/* Take off the post, and readings kept aside (1.8.0). */
	if ( function_exists( 'rrt_kept_reading_section' ) ) {
		rrt_kept_reading_section( $post_id );
	}
	/* Its audiobook copy (1.20.0). */
	if ( function_exists( 'rrt_book_reading_section' ) ) {
		rrt_book_reading_section( $post_id );
	}

	$now  = rrt_dash_totals( $range['from'], $range['to'], $post_id );
	$prev = rrt_dash_totals( $range['pfrom'], $range['pto'], $post_id );
	$ok   = $now['plays'] >= $min && $prev['plays'] >= $min;
	echo '<div class="rrt-tiles">';
	rrt_dash_tile( __( 'Plays', 'foxfire-studio' ), number_format_i18n( $now['plays'] ), number_format_i18n( $now['views'] ) . ' visitors', rrt_dash_delta( $now['plays'], $prev['plays'], $ok ) );
	rrt_dash_tile( __( 'Pressed play', 'foxfire-studio' ), rrt_dash_pct( $now['plays'], $now['views'], false ) );
	rrt_dash_tile( __( 'Still listening at 1 minute', 'foxfire-studio' ), rrt_dash_pct( $now['held60'], $now['plays'], false ), number_format_i18n( $now['held60'] ) . ' of ' . number_format_i18n( $now['plays'] ) );
	rrt_dash_tile( __( 'Heard to the end', 'foxfire-studio' ), rrt_dash_pct( $now['completions'], $now['plays'], false ), number_format_i18n( $now['completions'] ) . ' of ' . number_format_i18n( $now['plays'] ) );
	rrt_dash_tile( __( 'Average heard', 'foxfire-studio' ), $now['plays'] ? rrt_clock( $now['listened'] / $now['plays'] ) : '–', $rec && $rec['duration_s'] ? 'of ' . rrt_clock( $rec['duration_s'] ) : '' );
	rrt_dash_tile( __( 'Average speed', 'foxfire-studio' ), null === $now['rate'] ? '–' : $now['rate'] . '×' );
	echo '</div>';
	if ( $now['plays'] && $now['plays'] < $min ) {
		echo '<p class="rrt-small">' . esc_html( sprintf( /* translators: 1: plays so far, 2: plays needed. */ __( '%1$s plays so far. Patterns below are worth watching, but are not reliable until about %2$d.', 'foxfire-studio' ), number_format_i18n( $now['plays'] ), $min ) ) . '</p>';
	}

	if ( ! $rec ) {
		rrt_dash_empty( __( 'This post has no Recordings record yet, so there is no listening curve. It appears once a reading is on the post.', 'foxfire-studio' ) );
		echo '</div>';
		return;
	}

	$c    = rrt_dash_curve( $post_id, $rec_id, $range['from'], $range['to'] );
	$pc   = rrt_dash_curve( $post_id, $rec_id, $range['pfrom'], $range['pto'] );
	$dur  = (float) $rec['duration_s'];
	$n    = max( count( $c['heard'] ), $dur ? (int) ceil( $dur / 5 ) : 0 );
	$frac = function ( $arr, $plays ) use ( $n ) {
		$o = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$o[] = $plays ? round( ( isset( $arr[ $i ] ) ? $arr[ $i ] : 0 ) / $plays, 4 ) : null;
		}
		return $o;
	};
	$cues = rrt_dash_cues( $post_id );
	$audio = rrt_reading_audio( $post_id );
	$markers = rrt_dash_markers( $range['from'], $range['to'], $post_id );

	echo '<h2>' . esc_html__( 'Who is still listening', 'foxfire-studio' ) . '</h2>';
	echo '<p class="rrt-small">' . esc_html__( 'Each point is a 5 second stretch of the reading. Hover to see the sentence there; click to hear it.', 'foxfire-studio' ) . '</p>';
	if ( $audio['url'] ) {
		echo '<audio class="rrt-dash-audio" src="' . esc_url( $audio['url'] ) . '" preload="none" controls></audio>';
		/* Clips for social media (0.13.0). */
		if ( function_exists( 'rrt_clip_button' ) ) {
			rrt_clip_button( $post_id, $c, ! empty( $rec['is_current'] ) );
		}
		/* The whole reading as a video (1.18.0). */
		if ( function_exists( 'rrt_video_section' ) ) {
			rrt_video_section( $post_id );
		}
	}
	rrt_dash_chart(
		'rrt-c-curve',
		array(
			'type'   => 'curve',
			'step'   => 5,
			'plays'  => $c['plays'],
			'pct'    => true,
			'cues'   => $cues,
			'drops'  => $c['drops'],
			'series' => array(
				array( 'name' => __( 'This period', 'foxfire-studio' ), 'values' => $frac( $c['heard'], $c['plays'] ), 'counts' => array_slice( array_pad( $c['heard'], $n, 0 ), 0, $n ) ),
				array( 'name' => __( 'Previous period', 'foxfire-studio' ), 'values' => $frac( $pc['heard'], $pc['plays'] ), 'faint' => true ),
			),
		),
		__( 'Share of plays still listening at each moment', 'foxfire-studio' )
	);
	if ( $c['drops'] ) {
		echo '<h3>' . esc_html__( 'Where listeners leave', 'foxfire-studio' ) . '</h3><ul class="rrt-drops">';
		foreach ( $c['drops'] as $d ) {
			$t    = $d['bucket'] * 5;
			$line = '';
			foreach ( $cues as $q ) {
				if ( $q['s'] <= $t + 2.5 && $q['e'] >= $t ) {
					$line = $q['t'];
					break;
				}
			}
			echo '<li><button type="button" class="button-link rrt-seek" data-t="' . esc_attr( max( 0, $t - 3 ) ) . '">' . esc_html( rrt_clock( $t ) ) . '</button> ' . esc_html( '' !== $line ? sprintf( /* translators: 1: share of plays, 2: number of plays, 3: the words there. */ __( '%1$d%% of plays (%2$d) stop here: "%3$s"', 'foxfire-studio' ), round( 100 * $d['fall'] ), $d['count'], $line ) : sprintf( /* translators: 1: share of plays, 2: number of plays. */ __( '%1$d%% of plays (%2$d) stop here', 'foxfire-studio' ), round( 100 * $d['fall'] ), $d['count'] ) ) . '</li>';
		}
		echo '</ul>';
	}

	echo '<h2>' . esc_html__( 'Along the reading', 'foxfire-studio' ) . '</h2>';
	echo '<p class="rrt-small">' . esc_html__( 'For each stretch, the share of the plays that heard it which went back to hear it again, jumped over it, or were playing faster or slower than normal.', 'foxfire-studio' ) . '</p>';
	$of_heard = function ( $arr ) use ( $c, $n ) {
		$o = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$h   = isset( $c['heard'][ $i ] ) ? $c['heard'][ $i ] : 0;
			$o[] = $h ? round( ( isset( $arr[ $i ] ) ? $arr[ $i ] : 0 ) / $h, 4 ) : null;
		}
		return $o;
	};
	$skip = array();
	for ( $i = 0; $i < $n; $i++ ) {
		$skip[] = $c['plays'] ? round( ( isset( $c['skipped'][ $i ] ) ? $c['skipped'][ $i ] : 0 ) / $c['plays'], 4 ) : null;
	}
	rrt_dash_chart(
		'rrt-c-along',
		array(
			'type'   => 'curve',
			'step'   => 5,
			'pct'    => true,
			'cues'   => $cues,
			'series' => array(
				array( 'name' => __( 'Heard again', 'foxfire-studio' ), 'values' => $of_heard( $c['replayed'] ) ),
				array( 'name' => __( 'Jumped over', 'foxfire-studio' ), 'values' => $skip ),
				array( 'name' => __( 'Faster than normal', 'foxfire-studio' ), 'values' => $of_heard( $c['faster'] ) ),
				array( 'name' => __( 'Slower than normal', 'foxfire-studio' ), 'values' => $of_heard( $c['slower'] ) ),
			),
		),
		__( 'Replays, skips and speed changes along the reading', 'foxfire-studio' )
	);

	echo '<div class="rrt-cols"><div>';
	echo '<h2>' . esc_html__( 'How they listened', 'foxfire-studio' ) . '</h2><table class="widefat striped rrt-table"><tbody>';
	foreach ( array( 'q25' => __( 'Reached a quarter', 'foxfire-studio' ), 'q50' => __( 'Reached halfway', 'foxfire-studio' ), 'q75' => __( 'Reached three quarters', 'foxfire-studio' ), 'completions' => __( 'Reached the end', 'foxfire-studio' ) ) as $k => $v ) {
		echo '<tr><td>' . esc_html( $v ) . '</td><td class="num">' . esc_html( rrt_dash_pct( $now[ $k ], $now['plays'] ) ) . '</td></tr>';
	}
	echo '<tr><td>' . esc_html__( 'Listened with the tab in the background', 'foxfire-studio' ) . '</td><td class="num">' . esc_html( $now['listened'] ? sprintf( /* translators: %d: percentage. */ __( '%d%% of listening time', 'foxfire-studio' ), round( 100 * min( 1, $now['hidden_s'] / $now['listened'] ) ) ) : '–' ) . '</td></tr>';
	echo '<tr><td>' . esc_html__( 'Turned on read along', 'foxfire-studio' ) . '</td><td class="num">' . esc_html( rrt_dash_pct( $now['follow'], $now['plays'] ) ) . '</td></tr>';
	echo '</tbody></table>';
	$aud = rrt_dash_audience( $range['from'], $range['to'], $post_id );
	echo '<h2>' . esc_html__( 'Devices and countries', 'foxfire-studio' ) . '</h2><table class="widefat striped rrt-table"><tbody>';
	foreach ( array( 'device', 'country' ) as $col ) {
		$total = array_sum( wp_list_pluck( $aud[ $col ], 'n' ) );
		foreach ( $aud[ $col ] as $a ) {
			echo '<tr><td>' . esc_html( '' === $a['k'] ? 'unknown' : $a['k'] ) . '</td><td class="num">' . esc_html( rrt_dash_pct( (int) $a['n'], $total ) ) . '</td></tr>';
		}
	}
	echo '</tbody></table>';
	echo '</div><div>';
	echo '<h2>' . esc_html__( 'Where its listeners came from', 'foxfire-studio' ) . '</h2><table class="widefat striped rrt-table"><thead><tr><th>' . esc_html__( 'Came from', 'foxfire-studio' ) . '</th><th class="num">' . esc_html__( 'Plays', 'foxfire-studio' ) . '</th><th class="num">' . esc_html__( 'Heard to the end', 'foxfire-studio' ) . '</th></tr></thead><tbody>';
	$src = rrt_dash_sources( $range['from'], $range['to'], $post_id, true );
	foreach ( $src as $s ) {
		echo '<tr><td>' . esc_html( rrt_dash_channel( $s['channel'] ) . ( '' !== $s['campaign'] ? ' (' . $s['campaign'] . ')' : '' ) ) . '</td><td class="num">' . esc_html( number_format_i18n( $s['plays'] ) ) . '</td><td class="num">' . esc_html( rrt_dash_pct( $s['completions'], $s['plays'] ) ) . '</td></tr>';
	}
	if ( ! $src ) {
		echo '<tr><td colspan="3">' . esc_html__( 'No visits yet.', 'foxfire-studio' ) . '</td></tr>';
	}
	echo '</tbody></table>';

	echo '<h2>' . esc_html__( 'The take it came from', 'foxfire-studio' ) . '</h2>';
	$take = null;
	if ( $rec['take_id'] ) {
		global $wpdb;
		$take = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . rrt_table( 'takes' ) . ' WHERE id = %d', $rec['take_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- only the plugin's own table name and whole numbers
	}
	if ( $take ) {
		echo '<table class="widefat striped rrt-table"><tbody>';
		$rowsx = array(
			__( 'Recorded', 'foxfire-studio' ) => wp_date( 'M j, Y g:i a', (int) floor( $take['at_ms'] / 1000 ) ),
			__( 'Pace', 'foxfire-studio' ) => $take['wpm'] ? sprintf( /* translators: %s: words a minute. */ __( '%s wpm', 'foxfire-studio' ), $take['wpm'] ) : '',
			__( 'Accuracy', 'foxfire-studio' ) => null !== $take['accuracy'] ? round( $take['accuracy'], 1 ) . '%' : '',
			__( 'Fillers', 'foxfire-studio' ) => $take['fillers'],
			__( 'Sentences to retake', 'foxfire-studio' ) => $take['retake_sentences'],
			__( 'Pitch movement', 'foxfire-studio' ) => null !== $take['pitch_spread'] ? sprintf( /* translators: %s: semitones. */ __( '%s semitones', 'foxfire-studio' ), round( $take['pitch_spread'], 1 ) ) : '',
			__( 'Energy', 'foxfire-studio' ) => null !== $take['energy_db'] ? sprintf( /* translators: %s: decibels. */ __( '%s dB', 'foxfire-studio' ), round( $take['energy_db'], 1 ) ) : '',
			__( 'Room', 'foxfire-studio' ) => null !== $take['room_noise_db'] ? sprintf( /* translators: %s: decibels. */ __( '%s dB below the voice', 'foxfire-studio' ), round( $take['room_noise_db'] ) ) : '',
			__( 'Edited', 'foxfire-studio' ) => $rec['edited'] ? __( 'yes', 'foxfire-studio' ) : __( 'no', 'foxfire-studio' ),
		);
		foreach ( $rowsx as $k => $v ) {
			if ( '' !== (string) $v && null !== $v ) {
				echo '<tr><td>' . esc_html( $k ) . '</td><td>' . esc_html( $v ) . '</td></tr>';
			}
		}
		echo '</tbody></table>';
	} else {
		echo '<p class="rrt-small">' . esc_html( 'manual' === $rec['source'] || 'existing' === $rec['source'] ? __( 'This reading was put on the post by hand, so it is not linked to a take. Readings sent with "Use this recording" are.', 'foxfire-studio' ) : __( 'No take is linked to this reading.', 'foxfire-studio' ) ) . '</p>';
	}
	echo '</div></div>';

	/* Wider listening numbers (1.2.0). */
	$rl = rrt_dash_readlisten( $range['from'], $range['to'], $post_id );
	echo '<div class="rrt-cols"><div>';
	rrt_dash_readlisten_block( $rl );
	echo '</div><div>';
	rrt_dash_first_play_block( rrt_dash_first_play( $range['from'], $range['to'], $post_id ) );
	echo '</div></div>';
	rrt_dash_returning_block( $rl );

	echo '<h2>' . esc_html__( 'Changes', 'foxfire-studio' ) . '</h2>';
	rrt_dash_marker_list( $markers, $back );
	rrt_dash_marker_form( $post_id, $back );

	echo '<p><a class="button button-small" href="' . esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'rrt_export', 'what' => 'visits', 'post' => $post_id, 'from' => $range['from'], 'to' => $range['to'] ), admin_url( 'admin-post.php' ) ), 'rrt_export' ) ) . '">' . esc_html__( 'This reading\'s visits (CSV)', 'foxfire-studio' ) . '</a></p>';
	rrt_dash_defs(
		array(
			__( 'Who is still listening', 'foxfire-studio' ) => __( 'For each 5 second stretch, the share of plays that heard it. A stretch counts once at least 2 seconds of it were played. The faint line is the previous period.', 'foxfire-studio' ),
			__( 'Where listeners leave', 'foxfire-studio' ) => __( 'Stretches where the share still listening falls by at least 5% and at least two and a half times the usual fall, leaving out the last 10 seconds.', 'foxfire-studio' ),
			__( 'Heard again', 'foxfire-studio' ) => __( 'Of the plays that heard a stretch, the share that heard it more than once (by going back, or on a second visit that day).', 'foxfire-studio' ),
			__( 'Jumped over', 'foxfire-studio' ) => __( 'Of all plays, the share that heard something before and after a stretch but not the stretch itself.', 'foxfire-studio' ),
			__( 'Faster, slower', 'foxfire-studio' ) => __( 'Of the plays that heard a stretch, the share playing it above or below normal speed.', 'foxfire-studio' ),
		) + rrt_dash_wider_defs()
	);
	echo '</div>';
}

/*
 * ---------------------------------------------------------------------------
 * Sources and the link builder
 * ---------------------------------------------------------------------------
 */

function rrt_dash_page_sources() {
	if ( ! current_user_can( rrt_dash_cap() ) ) {
		wp_die( esc_html__( 'Not allowed.', 'foxfire-studio' ) );
	}
	rrt_dash_freshen();
	$range = rrt_dash_range();
	rrt_dash_header( __( 'Sources', 'foxfire-studio' ), 'rrt-sources', $range );
	$min = rrt_dash_min_plays();

	$table = function ( $rows, $id, $with_campaign ) use ( $range ) {
		echo '<table class="widefat striped rrt-table rrt-sortable" id="' . esc_attr( $id ) . '"><thead><tr>';
		$heads = $with_campaign ? array( __( 'Campaign', 'foxfire-studio' ), __( 'Channel', 'foxfire-studio' ) ) : array( __( 'Channel', 'foxfire-studio' ) );
		foreach ( array_merge( $heads, array( __( 'Visitors', 'foxfire-studio' ), __( 'Plays', 'foxfire-studio' ), __( 'Pressed play', 'foxfire-studio' ), __( 'Average heard', 'foxfire-studio' ), __( 'Heard to the end', 'foxfire-studio' ) ) ) as $i => $h ) {
			echo '<th' . ( $i >= count( $heads ) ? ' class="num"' : '' ) . ' data-sort="' . ( $i < count( $heads ) ? 'text' : 'num' ) . '"><button type="button">' . esc_html( $h ) . '</button></th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $s ) {
			echo '<tr>';
			if ( $with_campaign ) {
				echo '<td data-v="' . esc_attr( $s['campaign'] ) . '">' . esc_html( $s['campaign'] ) . '</td>';
			}
			echo '<td data-v="' . esc_attr( $s['channel'] ) . '">' . esc_html( rrt_dash_channel( $s['channel'] ) ) . '</td>';
			echo '<td class="num" data-v="' . (int) $s['views'] . '">' . esc_html( number_format_i18n( $s['views'] ) ) . '</td>';
			echo '<td class="num" data-v="' . (int) $s['plays'] . '">' . esc_html( number_format_i18n( $s['plays'] ) ) . '</td>';
			echo '<td class="num" data-v="' . esc_attr( $s['views'] ? $s['plays'] / $s['views'] : 0 ) . '">' . esc_html( rrt_dash_pct( $s['plays'], $s['views'] ) ) . '</td>';
			echo '<td class="num" data-v="' . esc_attr( $s['plays'] ? $s['listened'] / $s['plays'] : 0 ) . '">' . esc_html( $s['plays'] ? rrt_clock( $s['listened'] / $s['plays'] ) : '' ) . '</td>';
			echo '<td class="num" data-v="' . esc_attr( $s['plays'] ? $s['completions'] / $s['plays'] : 0 ) . '">' . esc_html( rrt_dash_pct( $s['completions'], $s['plays'] ) ) . '</td>';
			echo '</tr>';
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="7">' . esc_html__( 'Nothing yet.', 'foxfire-studio' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	};

	$ch = rrt_dash_sources( $range['from'], $range['to'] );
	echo '<div class="rrt-tools"><h2>' . esc_html__( 'Channels', 'foxfire-studio' ) . '</h2>';
	rrt_dash_csv_button( 'rrt-t-channels', 'channels-' . $range['from'] . '-to-' . $range['to'] );
	echo '</div>';
	$table( $ch, 'rrt-t-channels', false );
	echo '<p class="rrt-small">' . esc_html( sprintf( /* translators: %d: plays. */ __( 'Which channel brings people who stay matters more than which brings the most clicks: compare "Pressed play" and "Heard to the end". Channels with fewer than %d plays are too small to compare fairly.', 'foxfire-studio' ), (int) $min ) ) . '</p>';

	$ss   = rrt_dash_source_series( $range['from'], $range['to'] );
	$axis = rrt_dash_time_axis( $range, rrt_dash_markers( $range['from'], $range['to'] ) );
	$series = array();
	$other  = array_fill( 0, count( $ss['days'] ), 0 );
	$i      = 0;
	foreach ( $ss['series'] as $name => $vals ) {
		if ( $i < 4 ) {
			$series[] = array( 'name' => rrt_dash_channel( $name ), 'values' => $vals );
		} else {
			foreach ( $vals as $k => $v ) {
				$other[ $k ] += $v;
			}
		}
		++$i;
	}
	if ( $i > 4 ) {
		$series[] = array( 'name' => __( 'Everything else', 'foxfire-studio' ), 'values' => $other, 'faint' => true );
	}
	if ( $series ) {
		echo '<h2>' . esc_html__( 'Plays each day by channel', 'foxfire-studio' ) . '</h2>';
		rrt_dash_chart( 'rrt-c-channels', array( 'type' => 'line', 'x' => $axis['x'], 'markers' => $axis['markers'], 'unit' => 'plays', 'series' => $series ), __( 'Plays each day by channel', 'foxfire-studio' ) );
	}

	$camp = array_values( array_filter( rrt_dash_sources( $range['from'], $range['to'], 0, true ), function ( $r ) { return '' !== $r['campaign']; } ) );
	echo '<div class="rrt-tools"><h2>' . esc_html__( 'Campaigns', 'foxfire-studio' ) . '</h2>';
	rrt_dash_csv_button( 'rrt-t-campaigns', 'campaigns-' . $range['from'] . '-to-' . $range['to'] );
	echo '</div>';
	$table( $camp, 'rrt-t-campaigns', true );
	echo '<p class="rrt-small">' . sprintf(
		/* translators: %s: the tag name utm_campaign, shown as code. */
		esc_html__( 'A campaign is the name given to a link with %s. The link builder below adds one.', 'foxfire-studio' ),
		'<code>utm_campaign</code>'
	) . '</p>';

	/* Link builder. */
	$s     = rrt_settings();
	$posts = get_posts( array( 'post_type' => 'any', 'post_status' => 'publish', 'numberposts' => 200, 'meta_query' => array() ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	$with  = array();
	foreach ( $posts as $p ) {
		$a = rrt_reading_audio( $p->ID );
		if ( $a['url'] ) {
			$with[] = $p;
		}
	}
	echo '<h2>' . esc_html__( 'Link builder', 'foxfire-studio' ) . '</h2><p>' . esc_html__( 'Make a tagged link for each place you share a reading, so each one shows up as its own source.', 'foxfire-studio' ) . '</p>';
	echo '<div class="rrt-builder" data-src="' . esc_attr( $s['listen']['src'] ) . '">';
	echo '<label>' . esc_html__( 'Post', 'foxfire-studio' ) . ' <select class="rrt-b-post">';
	foreach ( $with as $p ) {
		echo '<option value="' . esc_url( get_permalink( $p ) ) . '">' . esc_html( get_the_title( $p ) ) . '</option>';
	}
	if ( ! $with ) {
		echo '<option value="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Home page', 'foxfire-studio' ) . '</option>';
	}
	echo '</select></label> ';
	echo '<label>' . esc_html__( 'Channel', 'foxfire-studio' ) . ' <select class="rrt-b-ch">';
	foreach ( array( 'instagram', 'facebook', 'threads', 'bluesky', 'tiktok', 'x', 'reddit', 'youtube', 'mastodon', 'email' ) as $c ) {
		echo '<option value="' . esc_attr( $c ) . '">' . esc_html( rrt_dash_channel( $c ) ) . '</option>';
	}
	echo '</select></label> ';
	echo '<label>' . esc_html__( 'Campaign (optional)', 'foxfire-studio' ) . ' <input type="text" class="rrt-b-camp" placeholder="' . esc_attr__( 'spring-story', 'foxfire-studio' ) . '" maxlength="60"></label> ';
	echo '<label>' . esc_html__( 'Start at (optional)', 'foxfire-studio' ) . ' <input type="text" class="rrt-b-t" placeholder="1:45" size="6"></label>';
	echo '<p><input type="text" class="large-text code rrt-b-out" readonly> <button type="button" class="button rrt-b-copy">' . esc_html__( 'Copy link', 'foxfire-studio' ) . '</button> <span class="rrt-b-msg" aria-live="polite"></span></p>';
	echo '</div>';
	rrt_dash_defs(
		array(
			__( 'Channel', 'foxfire-studio' ) => __( 'Worked out from a campaign tag on the link first, then from Instagram\'s and Facebook\'s in-app browsers, then from the site that linked to the post. "Direct or unknown" means the browser did not say where the visitor came from, which is common from apps and email.', 'foxfire-studio' ),
			__( 'Visitors', 'foxfire-studio' ) => __( 'Listeners who opened a post with a reading, once per listener per reading per day. A visitor is counted under the channel of their first visit that day that played, or of their first visit.', 'foxfire-studio' ),
			__( 'Average heard', 'foxfire-studio' ) => __( 'Listening time divided by plays.', 'foxfire-studio' ),
		)
	);
	echo '</div>';
}

/*
 * ---------------------------------------------------------------------------
 * Your progress: reader stats, goals and the practice log
 * ---------------------------------------------------------------------------
 */

function rrt_dash_page_progress() {
	$range   = rrt_dash_range();
	$user_id = get_current_user_id();
	$readers = current_user_can( 'manage_options' ) ? rrt_dash_readers() : array();
	if ( isset( $_GET['reader'] ) && isset( $readers[ absint( $_GET['reader'] ) ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$user_id = absint( $_GET['reader'] ); // phpcs:ignore WordPress.Security.NonceVerification
	}
	$own   = get_current_user_id() === $user_id;
	$page  = isset( $_GET['page'] ) && 'rrt-dashboard' === $_GET['page'] ? 'rrt-dashboard' : 'rrt-progress'; // phpcs:ignore WordPress.Security.NonceVerification
	rrt_dash_header( $own ? __( 'Your progress', 'foxfire-studio' ) : sprintf( /* translators: %s: reader's name. */ __( 'Progress: %s', 'foxfire-studio' ), $readers[ $user_id ] ), $page, $range, $own ? array() : array( 'reader' => $user_id ) );
	if ( count( $readers ) > 1 ) {
		echo '<p>' . esc_html__( 'Reader:', 'foxfire-studio' ) . ' ';
		foreach ( $readers as $id => $name ) {
			echo $id === $user_id ? '<strong>' . esc_html( $name ) . '</strong> ' : '<a href="' . esc_url( rrt_dash_url( 'rrt-progress', array( 'reader' => $id ) ) ) . '">' . esc_html( $name ) . '</a> ';
		}
		echo '</p>';
	}
	/* 1.33.1: a way to the practice reading; its takes are counted here like any other. */
	if ( $own && function_exists( 'rrt_practice_url' ) ) {
		echo '<p class="rrt-progress-practice"><a class="button" id="rrt-progress-practice" href="' . esc_url( rrt_practice_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open the practice reading', 'foxfire-studio' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'foxfire-studio' ) . '</span></a> ' . esc_html__( 'A one-minute script for warming up or trying the studio. Its takes show here as A practice reading.', 'foxfire-studio' ) . '</p>';
	}

	$goals = rrt_get_goals( $user_id );
	$every = rrt_dash_takes( $user_id, $range['from'], $range['to'] );
	/* Opening drills (0.14.0) have their own section; goals, trends and bests use full readings. */
	$drills = rrt_drill_takes( $every );
	$takes  = rrt_full_takes( $every );
	$all    = rrt_full_takes( rrt_dash_takes( $user_id ) );
	$flabel = rrt_focus_labels();
	$m     = rrt_goal_metrics();

	if ( ! $all ) {
		rrt_dash_empty( __( 'No takes yet. Every take made in the teleprompter appears here, with its pace, accuracy and delivery over time.', 'foxfire-studio' ) );
	}

	/* Goals. */
	echo '<h2>' . esc_html__( 'Goals', 'foxfire-studio' ) . '</h2>';
	if ( $goals ) {
		echo '<ul class="rrt-goals">';
		foreach ( $goals as $g ) {
			$met = 0;
			$n   = 0;
			foreach ( $takes as $t ) {
				$r = rrt_goal_met( $t, $g );
				if ( null !== $r ) {
					++$n;
					$met += $r ? 1 : 0;
				}
			}
			echo '<li><strong>' . esc_html( rrt_goal_text( $g ) ) . '</strong>: ' . esc_html( $n ? sprintf( /* translators: 1: takes that met the goal, 2: all takes. */ __( 'met in %1$d of %2$d takes this period', 'foxfire-studio' ), $met, $n ) : __( 'no takes with this number this period', 'foxfire-studio' ) ) . '</li>';
		}
		echo '</ul>';
	} else {
		echo '<p>' . esc_html__( 'Set one to three targets, such as a pace range or a most for fillers, and every take is checked against them here and in the teleprompter\'s read-out.', 'foxfire-studio' ) . '</p>';
	}
	if ( $own ) {
		echo '<details class="rrt-goal-edit"' . ( $goals ? '' : ' open' ) . '><summary>' . ( $goals ? esc_html__( 'Change goals', 'foxfire-studio' ) : esc_html__( 'Set goals', 'foxfire-studio' ) ) . '</summary>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'rrt_goals' );
		echo '<input type="hidden" name="action" value="rrt_goals">';
		for ( $i = 0; $i < 3; $i++ ) {
			$g = isset( $goals[ $i ] ) ? $goals[ $i ] : array( 'metric' => '', 'min' => null, 'max' => null );
			echo '<p class="rrt-goal-row"><select name="goals[' . (int) $i . '][metric]" aria-label="' . esc_attr( sprintf( /* translators: %d: 1, 2 or 3. */ __( 'Goal %d', 'foxfire-studio' ), $i + 1 ) ) . '"><option value="">' . esc_html__( 'No goal', 'foxfire-studio' ) . '</option>';
			foreach ( $m as $k => $d ) {
				echo '<option value="' . esc_attr( $k ) . '"' . selected( $g['metric'], $k, false ) . '>' . esc_html( $d['label'] . ( '' !== $d['unit'] ? ' (' . $d['unit'] . ')' : '' ) ) . '</option>';
			}
			echo '</select> <label>' . esc_html__( 'at least', 'foxfire-studio' ) . ' <input type="number" step="any" name="goals[' . (int) $i . '][min]" value="' . esc_attr( null === $g['min'] ? '' : $g['min'] ) . '"></label> <label>' . esc_html__( 'at most', 'foxfire-studio' ) . ' <input type="number" step="any" name="goals[' . (int) $i . '][max]" value="' . esc_attr( null === $g['max'] ? '' : $g['max'] ) . '"></label></p>';
		}
		echo '<p class="description">' . esc_html__( 'Leave either box empty for a goal in one direction only. Examples: pace at least 135 and at most 150; fillers at most 3; room quiet at least 30.', 'foxfire-studio' ) . '</p>';
		echo '<button class="button button-primary">' . esc_html__( 'Save goals', 'foxfire-studio' ) . '</button></form></details>';
	}

	/* Trends. */
	if ( $takes ) {
		echo '<h2>' . esc_html__( 'Over time', 'foxfire-studio' ) . '</h2><div class="rrt-multiples">';
		$axis_labels = array();
		foreach ( $takes as $t ) {
			$axis_labels[] = wp_date( 'M j', (int) floor( $t['at_ms'] / 1000 ) );
		}
		foreach ( array( 'wpm', 'accuracy', 'fillers', 'retakes', 'ending_drop', 'pitch', 'energy', 'room' ) as $metric ) {
			$vals = array();
			foreach ( $takes as $t ) {
				$vals[] = rrt_take_value( $t, $metric );
			}
			if ( ! array_filter( $vals, function ( $v ) { return null !== $v; } ) ) {
				continue;
			}
			$band = null;
			foreach ( $goals as $g ) {
				if ( $g['metric'] === $metric ) {
					$band = array( 'min' => $g['min'], 'max' => $g['max'] );
				}
			}
			$d = $m[ $metric ];
			echo '<div class="rrt-multiple"><h3>' . esc_html( $d['label'] . ( '' !== $d['unit'] ? ' (' . $d['unit'] . ')' : '' ) ) . '</h3>';
			rrt_dash_chart( 'rrt-c-' . $metric, array( 'type' => 'line', 'small' => true, 'x' => $axis_labels, 'band' => $band, 'unit' => $d['unit'], 'better' => isset( $d['better'] ) ? $d['better'] : '', 'series' => array( array( 'name' => $d['label'], 'values' => $vals ) ) ), sprintf( /* translators: %s: measure, such as Pace. */ __( '%s for each take', 'foxfire-studio' ), $d['label'] ) );
			echo '</div>';
		}
		echo '</div><p class="rrt-small">' . esc_html__( 'One point per take, oldest on the left. A shaded band is your goal.', 'foxfire-studio' ) . '</p>';
	}

	/* Personal bests, over all takes. */
	if ( $all ) {
		$best = array();
		foreach ( array( 'accuracy' => 'up', 'fillers' => 'down', 'retakes' => 'down', 'room' => 'up', 'pitch' => 'up', 'energy' => 'up' ) as $metric => $dir ) {
			$b = null;
			foreach ( $all as $t ) {
				$v = rrt_take_value( $t, $metric );
				if ( null === $v ) {
					continue;
				}
				if ( null === $b || ( 'up' === $dir ? $v > $b[0] : $v < $b[0] ) ) {
					$b = array( $v, $t );
				}
			}
			if ( $b ) {
				$best[ $metric ] = $b;
			}
		}
		echo '<h2>' . esc_html__( 'Personal bests', 'foxfire-studio' ) . '</h2><table class="widefat striped rrt-table"><tbody>';
		foreach ( $best as $metric => $b ) {
			$v = round( $b[0], 1 );
			echo '<tr><td>' . esc_html( sprintf( 'up' === ( isset( $m[ $metric ]['better'] ) ? $m[ $metric ]['better'] : 'up' ) ? /* translators: %s: measure, such as pace. */ __( 'Highest %s', 'foxfire-studio' ) : /* translators: %s: measure, such as fillers. */ __( 'Lowest %s', 'foxfire-studio' ), strtolower( $m[ $metric ]['label'] ) ) ) . '</td><td class="num">' . esc_html( $v . ( '' !== $m[ $metric ]['unit'] ? ' ' . $m[ $metric ]['unit'] : '' ) ) . '</td><td>' . esc_html( rrt_take_title( (int) $b[1]['post_id'] ) . ', ' . wp_date( 'M j, Y', (int) floor( $b[1]['at_ms'] / 1000 ) ) ) . '</td></tr>';
		}
		echo '</tbody></table><p class="rrt-small">' . esc_html( sprintf( /* translators: %s: number of takes. */ __( 'Over all %s takes. Fewest fillers and retakes count only takes the Whisper review checked.', 'foxfire-studio' ), number_format_i18n( count( $all ) ) ) ) . '</p>';
	}

	/* Takes in the period. */
	if ( $takes ) {
		echo '<div class="rrt-tools"><h2>' . esc_html__( 'Takes this period', 'foxfire-studio' ) . '</h2>';
		rrt_dash_csv_button( 'rrt-t-takes', 'takes-' . $range['from'] . '-to-' . $range['to'] );
		echo '</div><table class="widefat striped rrt-table rrt-sortable" id="rrt-t-takes"><thead><tr>';
		$heads = array( __( 'When', 'foxfire-studio' ), __( 'Post', 'foxfire-studio' ), __( 'Length', 'foxfire-studio' ), __( 'Pace', 'foxfire-studio' ), __( 'Accuracy', 'foxfire-studio' ), __( 'Fillers', 'foxfire-studio' ), __( 'Retakes', 'foxfire-studio' ), __( 'Pitch', 'foxfire-studio' ), __( 'Energy', 'foxfire-studio' ), __( 'Room', 'foxfire-studio' ), __( 'Focus', 'foxfire-studio' ) );
		if ( $goals ) {
			$heads[] = __( 'Goals met', 'foxfire-studio' );
		}
		foreach ( $heads as $i => $h ) {
			echo '<th' . ( $i > 1 ? ' class="num"' : '' ) . ' data-sort="' . ( in_array( $i, array( 1, 10 ), true ) ? 'text' : 'num' ) . '"><button type="button">' . esc_html( $h ) . '</button></th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( array_reverse( $takes ) as $t ) {
			$f = function ( $v, $dp = 0, $u = '' ) { return null === $v || '' === $v ? '' : round( (float) $v, $dp ) . $u; };
			echo '<tr><td data-v="' . esc_attr( $t['at_ms'] ) . '">' . esc_html( wp_date( 'M j, g:i a', (int) floor( $t['at_ms'] / 1000 ) ) ) . ( $t['became_recording_id'] ? ' <span class="rrt-pill">published</span>' : '' ) . '</td>';
			echo '<td data-v="' . esc_attr( rrt_take_title( (int) $t['post_id'] ) ) . '">' . esc_html( rrt_take_title( (int) $t['post_id'] ) ) . '</td>';
			echo '<td class="num" data-v="' . esc_attr( $t['duration_s'] ) . '">' . esc_html( $t['duration_s'] ? rrt_clock( $t['duration_s'] ) : '' ) . '</td>';
			foreach ( array( array( 'wpm', 0, '' ), array( 'accuracy', 1, '%' ), array( 'fillers', 0, '' ), array( 'retake_sentences', 0, '' ), array( 'pitch_spread', 1, '' ), array( 'energy_db', 1, '' ), array( 'room_noise_db', 0, '' ) ) as $c ) {
				echo '<td class="num" data-v="' . esc_attr( $t[ $c[0] ] ) . '">' . esc_html( $f( $t[ $c[0] ], $c[1], $c[2] ) ) . '</td>';
			}
			$fo = isset( $flabel[ $t['focus'] ] ) ? $flabel[ $t['focus'] ] : '';
			echo '<td class="num" data-v="' . esc_attr( $fo ) . '">' . esc_html( $fo ) . '</td>';
			if ( $goals ) {
				$met = 0;
				$n   = 0;
				foreach ( $goals as $g ) {
					$r = rrt_goal_met( $t, $g );
					if ( null !== $r ) {
						++$n;
						$met += $r ? 1 : 0;
					}
				}
				echo '<td class="num" data-v="' . (int) $met . '">' . esc_html( $n ? $met . ' of ' . $n : '' ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/* Opening drills (0.14.0). */
	if ( $drills ) {
		echo '<h2>' . esc_html__( 'Opening drills', 'foxfire-studio' ) . '</h2>';
		$first = $drills[0];
		$lastd = $drills[ count( $drills ) - 1 ];
		$bits  = array();
		if ( count( $drills ) > 1 ) {
			foreach ( array( array( 'accuracy', 'accuracy', 1, '%' ), array( 'wpm', 'pace', 0, ' wpm' ), array( 'fillers', 'fillers', 0, '' ), array( 'ending_drop_db', __( 'phrase-ending fade', 'foxfire-studio' ), 1, ' dB' ) ) as $c ) {
				if ( null !== $first[ $c[0] ] && null !== $lastd[ $c[0] ] && '' !== (string) $first[ $c[0] ] && '' !== (string) $lastd[ $c[0] ] ) {
					/* translators: 1: measure, 2: first value, 3: latest value, 4: unit. */
					$bits[] = sprintf( __( '%1$s %2$s%4$s to %3$s%4$s', 'foxfire-studio' ), $c[1], round( (float) $first[ $c[0] ], $c[2] ), round( (float) $lastd[ $c[0] ], $c[2] ), $c[3] );
				}
			}
		}
		echo '<p>' . esc_html( $bits ? sprintf( /* translators: 1: number of drills, 2: list of changes. */ _n( '%1$s drill this period. From the first to the latest: %2$s.', '%1$s drills this period. From the first to the latest: %2$s.', count( $drills ), 'foxfire-studio' ), number_format_i18n( count( $drills ) ), implode( ', ', $bits ) ) : sprintf( /* translators: %s: number of drills. */ _n( '%s drill this period.', '%s drills this period.', count( $drills ), 'foxfire-studio' ), number_format_i18n( count( $drills ) ) ) ) . ' ' . esc_html__( 'They are kept out of the goals, charts and bests above, which follow full readings.', 'foxfire-studio' ) . '</p>';
		echo '<table class="widefat striped rrt-table"><thead><tr><th>' . esc_html__( 'When', 'foxfire-studio' ) . '</th><th>' . esc_html__( 'Post', 'foxfire-studio' ) . '</th><th class="num">' . esc_html__( 'Length', 'foxfire-studio' ) . '</th><th class="num">' . esc_html__( 'Pace', 'foxfire-studio' ) . '</th><th class="num">' . esc_html__( 'Accuracy', 'foxfire-studio' ) . '</th><th class="num">' . esc_html__( 'Fillers', 'foxfire-studio' ) . '</th><th class="num">' . esc_html__( 'Ending fade', 'foxfire-studio' ) . '</th><th>' . esc_html__( 'Focus', 'foxfire-studio' ) . '</th></tr></thead><tbody>';
		foreach ( array_reverse( $drills ) as $t ) {
			$f = function ( $v, $dp = 0, $u = '' ) { return null === $v || '' === $v ? '' : round( (float) $v, $dp ) . $u; };
			echo '<tr><td>' . esc_html( wp_date( 'M j, g:i a', (int) floor( $t['at_ms'] / 1000 ) ) ) . '</td><td>' . esc_html( rrt_take_title( (int) $t['post_id'] ) ) . '</td><td class="num">' . esc_html( $t['duration_s'] ? rrt_clock( $t['duration_s'] ) : '' ) . '</td><td class="num">' . esc_html( $f( $t['wpm'] ) ) . '</td><td class="num">' . esc_html( $f( $t['accuracy'], 1, '%' ) ) . '</td><td class="num">' . esc_html( $f( $t['fillers'] ) ) . '</td><td class="num">' . esc_html( $f( $t['ending_drop_db'], 1, ' dB' ) ) . '</td><td>' . esc_html( isset( $flabel[ $t['focus'] ] ) ? $flabel[ $t['focus'] ] : '' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/* Practice log: every take counts as practice, drills included. */
	$log = rrt_practice_log( $every, $goals, $user_id );
	if ( $log ) {
		echo '<h2>' . esc_html__( 'Practice log', 'foxfire-studio' ) . '</h2><table class="widefat striped rrt-table"><thead><tr><th>' . esc_html__( 'Day', 'foxfire-studio' ) . '</th><th class="num">' . esc_html__( 'Takes', 'foxfire-studio' ) . '</th><th class="num">' . esc_html__( 'Time recorded', 'foxfire-studio' ) . '</th><th class="num">' . esc_html__( 'Posts', 'foxfire-studio' ) . '</th><th class="num">' . esc_html__( 'Best accuracy', 'foxfire-studio' ) . '</th>' . ( $goals ? '<th class="num">' . esc_html__( 'Most goals met', 'foxfire-studio' ) . '</th>' : '' ) . '<th>' . esc_html__( 'Note', 'foxfire-studio' ) . '</th></tr></thead><tbody>';
		foreach ( $log as $d ) {
			echo '<tr><td>' . esc_html( rrt_dash_day( $d['day'], 'D, M j' ) ) . '</td><td class="num">' . (int) $d['takes'] . '</td><td class="num">' . esc_html( rrt_clock( $d['seconds'] ) ) . '</td><td class="num">' . count( $d['posts'] ) . '</td><td class="num">' . esc_html( null === $d['best'] ? '' : round( $d['best'], 1 ) . '%' ) . '</td>' . ( $goals ? '<td class="num">' . esc_html( (int) $d['met'] . ' of ' . count( $goals ) ) . '</td>' : '' ) . '<td>';
			if ( $own ) {
				echo '<form method="post" class="rrt-note-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( 'rrt_practice_note' );
				echo '<input type="hidden" name="action" value="rrt_practice_note"><input type="hidden" name="day" value="' . esc_attr( $d['day'] ) . '">';
				echo '<input type="text" name="note" maxlength="200" value="' . esc_attr( $d['note'] ) . '" placeholder="' . esc_attr__( 'How it went', 'foxfire-studio' ) . '" aria-label="' . esc_attr( sprintf( /* translators: %s: day. */ __( 'Note for %s', 'foxfire-studio' ), $d['day'] ) ) . '"> <button class="button button-small">' . esc_html__( 'Save', 'foxfire-studio' ) . '</button></form>';
			} else {
				echo esc_html( $d['note'] );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/* Taught words. */
	if ( function_exists( 'rrt_get_learned' ) ) {
		$l = rrt_get_learned( $user_id );
		if ( ! empty( $l['labels'] ) ) {
			echo '<h2>' . esc_html__( 'Words you have taught Whisper', 'foxfire-studio' ) . '</h2><ul class="rrt-words">';
			foreach ( $l['labels'] as $label ) {
				echo '<li>' . esc_html( $label ) . '</li>';
			}
			echo '</ul><p class="rrt-small">' . esc_html__( 'Forget one, or move it to one post, on Foxfire Studio, Pronunciation.', 'foxfire-studio' ) . '</p>';
		}
	}
	rrt_dash_defs(
		array(
			__( 'Pace', 'foxfire-studio' ) => __( 'Words per minute over the take, from the read-out.', 'foxfire-studio' ),
			__( 'Accuracy', 'foxfire-studio' ) => __( 'Share of words read as written, from the Whisper review.', 'foxfire-studio' ),
			__( 'Fillers, retakes', 'foxfire-studio' ) => __( 'Counts from the Whisper review: fillers like "um", and sentences with a real slip.', 'foxfire-studio' ),
			__( 'Phrase-ending fade', 'foxfire-studio' ) => __( 'How much quieter the ends of phrases are than their middles. Less is clearer.', 'foxfire-studio' ),
			__( 'Pitch, energy', 'foxfire-studio' ) => __( 'How much the voice moves in pitch (semitones) and loudness (dB) within phrases. More movement usually sounds more alive.', 'foxfire-studio' ),
			__( 'Room quiet', 'foxfire-studio' ) => __( 'How far the room\'s background sits below the voice. More is quieter.', 'foxfire-studio' ),
			__( 'Practice log', 'foxfire-studio' ) => __( 'One line per day with takes, worked out from the takes themselves. The note is yours.', 'foxfire-studio' ),
		)
	);
	echo '</div>';
}

/*
 * ---------------------------------------------------------------------------
 * Recordings
 * ---------------------------------------------------------------------------
 */

function rrt_dash_page_recordings() {
	if ( ! current_user_can( rrt_dash_cap() ) ) {
		wp_die( esc_html__( 'Not allowed.', 'foxfire-studio' ) );
	}
	global $wpdb;
	$range = rrt_dash_range();
	rrt_dash_header( __( 'Recordings', 'foxfire-studio' ), 'rrt-recordings', $range, array(), false );
	if ( function_exists( 'rrt_kept_notice' ) ) {
		rrt_kept_notice();
	}
	$kept_recs = function_exists( 'rrt_kept_rec_ids' ) ? rrt_kept_rec_ids() : array();
	$rows = $wpdb->get_results( 'SELECT * FROM ' . rrt_table( 'recordings' ) . ' ORDER BY COALESCE(published_at, created_at) DESC', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- only the plugin's own table name and whole numbers
	echo '<div class="rrt-tools"><p>' . esc_html__( 'Every MP3 that has been a post\'s reading.', 'foxfire-studio' ) . '</p>';
	rrt_dash_csv_button( 'rrt-t-recordings', 'recordings' );
	echo '</div><table class="widefat striped rrt-table rrt-sortable" id="rrt-t-recordings"><thead><tr>';
	foreach ( array( __( 'Post', 'foxfire-studio' ), __( 'File', 'foxfire-studio' ), __( 'Length', 'foxfire-studio' ), __( 'Size', 'foxfire-studio' ), __( 'Bitrate', 'foxfire-studio' ), __( 'Tempo', 'foxfire-studio' ), __( 'Went live', 'foxfire-studio' ), __( 'On the post now', 'foxfire-studio' ), __( 'Edited', 'foxfire-studio' ), __( 'How it got there', 'foxfire-studio' ) ) as $i => $h ) {
		echo '<th' . ( in_array( $i, array( 2, 3, 4, 5 ), true ) ? ' class="num"' : '' ) . ' data-sort="' . ( in_array( $i, array( 2, 3, 4, 5 ), true ) ? 'num' : 'text' ) . '"><button type="button">' . esc_html( $h ) . '</button></th>';
	}
	echo '</tr></thead><tbody>';
	$how = array( 'upload' => __( 'Use this recording', 'foxfire-studio' ), 'manual' => __( 'Chosen by hand', 'foxfire-studio' ), 'existing' => __( 'Already there in 0.9.0', 'foxfire-studio' ) );
	foreach ( $rows as $r ) {
		/* 1.26.0: a recording whose post is gone, or has no title, still gets a link with words in it. */
		$rrt_rt = get_post( (int) $r['post_id'] ) ? get_the_title( (int) $r['post_id'] ) : '';
		if ( '' === trim( wp_strip_all_tags( $rrt_rt ) ) ) {
			$rrt_rt = get_post( (int) $r['post_id'] ) ? __( '(untitled)', 'foxfire-studio' ) : __( '(post deleted)', 'foxfire-studio' );
		}
		echo '<tr><td data-v="' . esc_attr( $rrt_rt ) . '">' . ( get_post( (int) $r['post_id'] ) ? '<a href="' . esc_url( rrt_dash_url( 'rrt-readings', array( 'post' => (int) $r['post_id'], 'rec' => (int) $r['id'] ) ) ) . '">' . esc_html( $rrt_rt ) . '</a>' : esc_html( $rrt_rt ) ) . '</td>';
		$is_kept = isset( $kept_recs[ (int) $r['id'] ] );
		echo '<td data-v="' . esc_attr( $r['file_name'] ) . '">' . ( $r['url'] && ! $is_kept && '' !== (string) $r['file_name'] ? '<a href="' . esc_url( $r['url'] ) . '">' . esc_html( $r['file_name'] ) . '</a>' : esc_html( $r['file_name'] ) ) . '</td>';
		echo '<td class="num" data-v="' . esc_attr( $r['duration_s'] ) . '">' . esc_html( $r['duration_s'] ? rrt_clock( $r['duration_s'] ) : '' ) . '</td>';
		echo '<td class="num" data-v="' . esc_attr( $r['size_bytes'] ) . '">' . esc_html( $r['size_bytes'] ? size_format( (int) $r['size_bytes'], 1 ) : '' ) . '</td>';
		echo '<td class="num" data-v="' . esc_attr( $r['bitrate_kbps'] ) . '">' . esc_html( $r['bitrate_kbps'] ? $r['bitrate_kbps'] . ' kbps' : '' ) . '</td>';
		echo '<td class="num" data-v="' . esc_attr( $r['tempo_wpm'] ) . '">' . esc_html( $r['tempo_wpm'] ? $r['tempo_wpm'] . ' wpm' : '' ) . '</td>';
		echo '<td data-v="' . esc_attr( $r['published_at'] ) . '">' . esc_html( $r['published_at'] ? wp_date( 'M j, Y', strtotime( $r['published_at'] . ' UTC' ) ) : '' ) . '</td>';
		echo '<td>' . ( $r['is_current'] ? 'yes' : ( $is_kept ? '<a href="#rrt-kept">' . esc_html__( 'kept aside', 'foxfire-studio' ) . '</a>' : 'no' ) ) . '</td><td>' . ( $r['edited'] ? 'yes' : 'no' ) . '</td>';
		echo '<td>' . esc_html( isset( $how[ $r['source'] ] ) ? $how[ $r['source'] ] : $r['source'] ) . '</td></tr>';
	}
	if ( ! $rows ) {
		echo '<tr><td colspan="10">' . esc_html__( 'No recordings yet.', 'foxfire-studio' ) . '</td></tr>';
	}
	echo '</tbody></table>';
	/* Readings kept aside (1.8.0). */
	if ( function_exists( 'rrt_kept_admin_section' ) ) {
		rrt_kept_admin_section();
	}
	/* Takes sent from a phone (0.21.0). */
	if ( function_exists( 'rrt_sent_admin_section' ) ) {
		rrt_sent_admin_section();
	}
	/* The take shelf on the site (1.34.0). */
	if ( function_exists( 'rrt_shelf_admin_section' ) ) {
		rrt_shelf_admin_section();
	}
	echo '</div>';
}

/*
 * ---------------------------------------------------------------------------
 * Form actions and CSV exports
 * ---------------------------------------------------------------------------
 */

/**
 * A CSV row that spreadsheet apps cannot run as formulas. Campaign tags and
 * referrers come from visitors' links, so a cell starting with =, @, a tab, a
 * carriage return, or + or - not followed by a digit gets a leading quote.
 *
 * @param array $row Cells.
 * @return array
 */
function rrt_csv_safe( $row ) {
	return array_map(
		function ( $v ) {
			return ( is_string( $v ) && preg_match( '/^(=|@|\t|\r|[+-](?!\d))/', $v ) ) ? "'" . $v : $v;
		},
		(array) $row
	);
}

/** Sends the browser back to a dashboard address, never anywhere else. */
function rrt_dash_back( $fallback = 'rrt-dashboard' ) {
	$back = isset( $_REQUEST['back'] ) ? esc_url_raw( wp_unslash( $_REQUEST['back'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	wp_safe_redirect( $back && 0 === strpos( $back, admin_url() ) ? $back : admin_url( 'admin.php?page=' . $fallback ) );
	exit;
}

add_action(
	'admin_post_rrt_marker',
	function () {
		if ( ! current_user_can( rrt_dash_cap() ) ) {
			wp_die( esc_html__( 'Not allowed.', 'foxfire-studio' ) );
		}
		check_admin_referer( 'rrt_marker' );
		global $wpdb;
		$day   = isset( $_POST['day'] ) ? sanitize_text_field( wp_unslash( $_POST['day'] ) ) : '';
		$label = isset( $_POST['label'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['label'] ) ), 0, 140 ) : '';
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) && '' !== $label ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				rrt_table( 'markers' ),
				array(
					'day'        => $day,
					'post_id'    => isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0,
					'label'      => $label,
					'created_by' => get_current_user_id(),
					'created_at' => current_time( 'mysql', true ),
				)
			);
		}
		rrt_dash_back();
	}
);

add_action(
	'admin_post_rrt_marker_delete',
	function () {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce is checked on the next lines, after the id it names is read.
		if ( ! current_user_can( rrt_dash_cap() ) ) {
			wp_die( esc_html__( 'Not allowed.', 'foxfire-studio' ) );
		}
		check_admin_referer( 'rrt_marker_delete_' . $id );
		global $wpdb;
		$wpdb->delete( rrt_table( 'markers' ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		rrt_dash_back();
	}
);

add_action(
	'admin_post_rrt_goals',
	function () {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'foxfire-studio' ) );
		}
		check_admin_referer( 'rrt_goals' );
		$in = isset( $_POST['goals'] ) ? wp_unslash( $_POST['goals'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		update_user_meta( get_current_user_id(), 'rrt_goals', rrt_clean_goals( $in ) );
		wp_safe_redirect( admin_url( 'admin.php?page=rrt-progress' ) );
		exit;
	}
);

add_action(
	'admin_post_rrt_practice_note',
	function () {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'foxfire-studio' ) );
		}
		check_admin_referer( 'rrt_practice_note' );
		$day  = isset( $_POST['day'] ) ? sanitize_text_field( wp_unslash( $_POST['day'] ) ) : '';
		$note = isset( $_POST['note'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['note'] ) ), 0, 200 ) : '';
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ) {
			$notes = get_user_meta( get_current_user_id(), 'rrt_practice_notes', true );
			$notes = is_array( $notes ) ? $notes : array();
			if ( '' === $note ) {
				unset( $notes[ $day ] );
			} else {
				$notes[ $day ] = $note;
			}
			update_user_meta( get_current_user_id(), 'rrt_practice_notes', $notes );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=rrt-progress' ) );
		exit;
	}
);

/** CSV downloads of the raw visit records and events, the source of every listener number. */
add_action(
	'admin_post_rrt_export',
	function () {
		if ( ! current_user_can( rrt_dash_cap() ) ) {
			wp_die( esc_html__( 'Not allowed.', 'foxfire-studio' ) );
		}
		check_admin_referer( 'rrt_export' );
		global $wpdb;
		$what = isset( $_GET['what'] ) && 'events' === $_GET['what'] ? 'events' : 'visits';
		$from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : wp_date( 'Y-m-d' );
		$to   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : wp_date( 'Y-m-d' );
		$post = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
			wp_die( esc_html__( 'Bad dates.', 'foxfire-studio' ) );
		}
		$ts   = rrt_table( 'sessions' );
		$cols = 'id, day, started_at, post_id, recording_id, channel, referrer_domain, utm_source, utm_medium, utm_campaign, utm_content, device, country, duration_s, played, first_play_s, buckets_heard, max_position_s, completed, rate_sum, rate_n, rate_changes, seeks_back, seeks_forward, hidden_s, follow_used, downloaded, errors, scroll_max, time_on_page_s, came_back, exit_type, events, junk';
		$where = $wpdb->prepare( 'day BETWEEN %s AND %s', $from, $to ) . rrt_dash_where_post( $post );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=reading-room-' . $what . '-' . $from . '-to-' . $to . '.csv' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( 'visits' === $what ) {
			fputcsv( $out, array_map( 'trim', explode( ',', $cols ) ) );
			$id = 0;
			do {
				$rows = $wpdb->get_results( "SELECT $cols FROM $ts WHERE $where AND id > " . (int) $id . ' ORDER BY id LIMIT 2000', ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
				foreach ( $rows as $r ) {
					fputcsv( $out, rrt_csv_safe( $r ) );
					$id = (int) $r[0];
				}
			} while ( count( $rows ) === 2000 );
		} else {
			$te = rrt_table( 'events' );
			fputcsv( $out, array( 'visit_id', 'type', 'position_s', 'ms_since_view', 'received_at', 'details' ) );
			$id = 0;
			do {
				$rows = $wpdb->get_results( "SELECT e.id, e.session_id, e.type, e.t, e.ms, e.created_at, e.data FROM $te e JOIN $ts s ON s.id = e.session_id WHERE s." . str_replace( array( 'day BETWEEN', 'AND post_id' ), array( 'day BETWEEN', 'AND s.post_id' ), $where ) . ' AND e.id > ' . (int) $id . ' ORDER BY e.id LIMIT 5000', ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
				foreach ( $rows as $r ) {
					$id = (int) array_shift( $r );
					fputcsv( $out, rrt_csv_safe( $r ) );
				}
			} while ( count( $rows ) === 5000 );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}
);
