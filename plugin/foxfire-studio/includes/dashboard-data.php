<?php
/**
 * The dashboard's numbers (0.11.0).
 *
 * Every listener number here is read from the daily summaries (rrt_daily,
 * rrt_daily_sources) that the hourly rollup builds from raw events, so the
 * dashboard stays fast however much data there is. Each function says how
 * its numbers are made, and the dashboard prints the same definitions.
 *
 * Reader numbers come from the takes table (0.9.0).
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Below these counts, numbers are labelled as small and comparisons are not drawn. */
function rrt_dash_min_plays() {
	return (int) apply_filters( 'rrt_dashboard_min_plays', 30 );
}

/** Who may see listener numbers. */
function rrt_dash_cap() {
	return (string) apply_filters( 'rrt_dashboard_cap', 'manage_options' );
}

/**
 * The date range from the request: last 7, 28, 90 or 365 days, or a custom
 * from/to, in the site's time zone, with the previous period of the same length.
 * Without a choice in the request, $default is used (Insights starts at 365).
 */
function rrt_dash_range( $default = '28' ) {
	$tz    = wp_timezone();
	$today = new DateTimeImmutable( 'today', $tz );
	$r     = isset( $_GET['range'] ) ? sanitize_key( wp_unslash( $_GET['range'] ) ) : $default; // phpcs:ignore WordPress.Security.NonceVerification
	$from  = null;
	$to    = $today;
	if ( 'custom' === $r ) {
		$f = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$t = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$fd = DateTimeImmutable::createFromFormat( '!Y-m-d', $f, $tz );
		$td = DateTimeImmutable::createFromFormat( '!Y-m-d', $t, $tz );
		if ( $fd && $td && $fd <= $td ) {
			$from = $fd;
			$to   = min( $td, $today );
		} else {
			$r = '28';
		}
	}
	if ( ! $from ) {
		$n    = in_array( $r, array( '7', '28', '90', '365' ), true ) ? (int) $r : 28;
		$r    = (string) $n;
		$from = $today->modify( '-' . ( $n - 1 ) . ' days' );
	}
	$days  = (int) $from->diff( $to )->days + 1;
	$pto   = $from->modify( '-1 day' );
	$pfrom = $pto->modify( '-' . ( $days - 1 ) . ' days' );
	return array(
		'key'   => $r,
		'from'  => $from->format( 'Y-m-d' ),
		'to'    => $to->format( 'Y-m-d' ),
		'pfrom' => $pfrom->format( 'Y-m-d' ),
		'pto'   => $pto->format( 'Y-m-d' ),
		'days'  => $days,
		'tz'    => wp_timezone_string(),
	);
}

/** Every day from one date to another, as Y-m-d strings. */
function rrt_dash_days( $from, $to ) {
	$out = array();
	$d   = new DateTimeImmutable( $from );
	$end = new DateTimeImmutable( $to );
	while ( $d <= $end ) {
		$out[] = $d->format( 'Y-m-d' );
		$d     = $d->modify( '+1 day' );
	}
	return $out;
}

/**
 * Brings today's summaries up to date before the dashboard reads them, at most
 * every five minutes, so a listen shows up without waiting for the hourly job.
 */
function rrt_dash_freshen() {
	if ( get_transient( 'rrt_dash_fresh' ) ) {
		return;
	}
	set_transient( 'rrt_dash_fresh', 1, 5 * MINUTE_IN_SECONDS );
	if ( function_exists( 'rrt_listen_rollup' ) ) {
		rrt_listen_rollup();
	}
	if ( function_exists( 'rrt_listen_rollup_day' ) ) {
		rrt_listen_rollup_day( wp_date( 'Y-m-d' ) );
	}
}

/** A WHERE clause piece for an optional post and recording. */
function rrt_dash_where_post( $post_id, $rec_id = null ) {
	global $wpdb;
	$w = '';
	if ( $post_id ) {
		$w .= $wpdb->prepare( ' AND post_id = %d', $post_id );
	}
	if ( null !== $rec_id ) {
		$w .= $wpdb->prepare( ' AND recording_id = %d', $rec_id );
	}
	return $w;
}

/**
 * Totals for a period, optionally for one post.
 *
 * - views: listeners who opened a page with a reading (one per listener id, reading and day)
 * - plays: those who pressed play
 * - listened_s: seconds heard (5 second stretches heard, capped at the reading's length)
 * - completions, held60, q25/q50/q75: plays that reached the end, one minute
 *   (or a quarter of a short reading), and each quarter
 * - rate: average speed, weighted by the stretches heard
 */
function rrt_dash_totals( $from, $to, $post_id = 0 ) {
	global $wpdb;
	$td  = rrt_table( 'daily' );
	$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare( "SELECT SUM(page_loads) loads, SUM(views) views, SUM(plays) plays, SUM(listened_s) listened, SUM(completions) completions, SUM(q25) q25, SUM(q50) q50, SUM(q75) q75, SUM(held60) held60, SUM(hidden_s) hidden_s, SUM(follow_plays) follow, SUM(avg_rate * plays) rate_w, SUM(CASE WHEN avg_rate IS NULL THEN 0 ELSE plays END) rate_p FROM $td WHERE day BETWEEN %s AND %s", $from, $to ) . rrt_dash_where_post( $post_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		ARRAY_A
	);
	$out = array();
	foreach ( array( 'loads', 'views', 'plays', 'listened', 'completions', 'q25', 'q50', 'q75', 'held60', 'hidden_s', 'follow' ) as $k ) {
		$out[ $k ] = (int) ( isset( $row[ $k ] ) ? $row[ $k ] : 0 );
	}
	$out['rate'] = ! empty( $row['rate_p'] ) ? round( (float) $row['rate_w'] / (float) $row['rate_p'], 2 ) : null;
	return $out;
}

/** Estimated listeners: distinct daily listener ids that pressed play, added up over the days. */
function rrt_dash_listeners( $from, $to, $post_id = 0 ) {
	global $wpdb;
	$ts = rrt_table( 'sessions' );
	return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare( "SELECT COUNT(*) FROM (SELECT DISTINCT day, day_hash FROM $ts WHERE day BETWEEN %s AND %s AND played = 1 AND junk = 0", $from, $to ) . rrt_dash_where_post( $post_id ) . ') x' // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	);
}

/** Plays, views and seconds heard for each day of a period. */
function rrt_dash_series( $from, $to, $post_id = 0 ) {
	global $wpdb;
	$td   = rrt_table( 'daily' );
	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare( "SELECT day, SUM(views) views, SUM(plays) plays, SUM(listened_s) listened FROM $td WHERE day BETWEEN %s AND %s", $from, $to ) . rrt_dash_where_post( $post_id ) . ' GROUP BY day', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		OBJECT_K
	);
	$out = array();
	foreach ( rrt_dash_days( $from, $to ) as $d ) {
		$r     = isset( $rows[ $d ] ) ? $rows[ $d ] : null;
		$out[] = array(
			'day'      => $d,
			'views'    => $r ? (int) $r->views : 0,
			'plays'    => $r ? (int) $r->plays : 0,
			'listened' => $r ? (int) $r->listened : 0,
		);
	}
	return $out;
}

/**
 * Read or listen (1.2.0): visits in four groups, from the daily summaries.
 * Days summarised before 1.2.0 have none of the four counts, so 'measured'
 * says how many visits the groups cover, against 'views' in all.
 */
function rrt_dash_readlisten( $from, $to, $post_id = 0 ) {
	global $wpdb;
	$td  = rrt_table( 'daily' );
	$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare( "SELECT SUM(views) views, SUM(v_listen) listen, SUM(v_both) both_, SUM(v_read) read_, SUM(v_pass) pass, SUM(l_new) l_new, SUM(l_back) l_back FROM $td WHERE day BETWEEN %s AND %s", $from, $to ) . rrt_dash_where_post( $post_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		ARRAY_A
	);
	$g = function ( $k ) use ( $row ) {
		return (int) ( isset( $row[ $k ] ) ? $row[ $k ] : 0 );
	};
	$out = array(
		'views'  => $g( 'views' ),
		'listen' => $g( 'listen' ),
		'both'   => $g( 'both_' ),
		'read'   => $g( 'read_' ),
		'pass'   => $g( 'pass' ),
		'new'    => $g( 'l_new' ),
		'back'   => $g( 'l_back' ),
	);
	$out['measured'] = $out['listen'] + $out['both'] + $out['read'] + $out['pass'];
	return $out;
}

/** Merges the daily first-play histograms of a period: device group => bin => count. */
function rrt_dash_first_hist( $from, $to, $post_id = 0 ) {
	global $wpdb;
	$td   = rrt_table( 'daily' );
	$rows = $wpdb->get_col( $wpdb->prepare( "SELECT first_hist FROM $td WHERE day BETWEEN %s AND %s AND first_hist IS NOT NULL", $from, $to ) . rrt_dash_where_post( $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$out  = array( 'm' => array(), 'c' => array() );
	foreach ( (array) $rows as $json ) {
		$h = json_decode( (string) $json, true );
		if ( ! is_array( $h ) ) {
			continue;
		}
		foreach ( array( 'm', 'c' ) as $dev ) {
			if ( empty( $h[ $dev ] ) || ! is_array( $h[ $dev ] ) ) {
				continue;
			}
			foreach ( $h[ $dev ] as $bin => $n ) {
				$bin = (int) $bin;
				$out[ $dev ][ $bin ] = ( isset( $out[ $dev ][ $bin ] ) ? $out[ $dev ][ $bin ] : 0 ) + (int) $n;
			}
		}
	}
	ksort( $out['m'] );
	ksort( $out['c'] );
	return $out;
}

/**
 * Time to press play (1.2.0) from a histogram: the count, the median (the
 * lower edge of the bin the middle play falls in) and the four bands.
 */
function rrt_dash_first_summary( $hist ) {
	ksort( $hist );
	$n     = array_sum( $hist );
	$bands = array( 'b10' => 0, 'b30' => 0, 'b120' => 0, 'more' => 0 );
	foreach ( $hist as $bin => $c ) {
		$bin = (int) $bin;
		$k   = $bin < 10 ? 'b10' : ( $bin < 30 ? 'b30' : ( $bin < 120 ? 'b120' : 'more' ) );
		$bands[ $k ] += (int) $c;
	}
	$median = null;
	if ( $n ) {
		$mid  = (int) floor( ( $n - 1 ) / 2 );
		$seen = 0;
		foreach ( $hist as $bin => $c ) {
			$seen += (int) $c;
			if ( $seen > $mid ) {
				$median = (int) $bin;
				break;
			}
		}
	}
	return array( 'n' => $n, 'median' => $median, 'bands' => $bands );
}

/** Time to press play for a period: phones and tablets, computers, and both together. */
function rrt_dash_first_play( $from, $to, $post_id = 0 ) {
	$h   = rrt_dash_first_hist( $from, $to, $post_id );
	$all = $h['m'];
	foreach ( $h['c'] as $bin => $c ) {
		$all[ $bin ] = ( isset( $all[ $bin ] ) ? $all[ $bin ] : 0 ) + $c;
	}
	return array(
		'all' => rrt_dash_first_summary( $all ),
		'm'   => rrt_dash_first_summary( $h['m'] ),
		'c'   => rrt_dash_first_summary( $h['c'] ),
	);
}

/** Change markers in a period: for the whole site, or the whole site plus one post. */
function rrt_dash_markers( $from, $to, $post_id = null ) {
	global $wpdb;
	$tm  = rrt_table( 'markers' );
	$sql = $wpdb->prepare( "SELECT id, day, post_id, label FROM $tm WHERE day BETWEEN %s AND %s", $from, $to ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( null !== $post_id ) {
		$sql .= $wpdb->prepare( ' AND post_id IN (0, %d)', $post_id );
	}
	return $wpdb->get_results( $sql . ' ORDER BY day, id', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
}

/** Adds the per-stretch arrays of several daily rows together. */
function rrt_dash_sum_buckets( $rows ) {
	$sum = array( 'heard' => array(), 'replayed' => array(), 'faster' => array(), 'slower' => array(), 'skipped' => array() );
	foreach ( $rows as $r ) {
		$b = json_decode( (string) $r, true );
		if ( ! is_array( $b ) ) {
			continue;
		}
		foreach ( $sum as $k => $v ) {
			if ( empty( $b[ $k ] ) || ! is_array( $b[ $k ] ) ) {
				continue;
			}
			foreach ( $b[ $k ] as $i => $n ) {
				$sum[ $k ][ $i ] = ( isset( $sum[ $k ][ $i ] ) ? $sum[ $k ][ $i ] : 0 ) + (int) $n;
			}
		}
	}
	$len = 0;
	foreach ( $sum as $v ) {
		$len = max( $len, $v ? max( array_keys( $v ) ) + 1 : 0 );
	}
	foreach ( $sum as $k => $v ) {
		$o = array_fill( 0, $len, 0 );
		foreach ( $v as $i => $n ) {
			$o[ $i ] = $n;
		}
		$sum[ $k ] = $o;
	}
	return $sum;
}

/**
 * The listening curve for one reading in a period: for each 5 second stretch,
 * the share of plays that heard it, with replays, skips and speed changes.
 */
function rrt_dash_curve( $post_id, $rec_id, $from, $to ) {
	global $wpdb;
	$td    = rrt_table( 'daily' );
	$rows  = $wpdb->get_col( $wpdb->prepare( "SELECT buckets FROM $td WHERE post_id = %d AND recording_id = %d AND day BETWEEN %s AND %s", $post_id, $rec_id, $from, $to ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$plays = (int) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(plays) FROM $td WHERE post_id = %d AND recording_id = %d AND day BETWEEN %s AND %s", $post_id, $rec_id, $from, $to ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$b     = rrt_dash_sum_buckets( $rows );
	$b['plays'] = $plays;
	$b['drops'] = rrt_dash_drops( $b['heard'], $plays );
	return $b;
}

/**
 * Drop-off points: stretches where the share still listening falls much
 * faster than usual. A fall counts when it is at least 5 points and at least
 * two and a half times the typical fall, leaving out the last 10 seconds,
 * where everyone stops.
 *
 * @return array Each: bucket, fall (share of plays), count.
 */
function rrt_dash_drops( $heard, $plays ) {
	if ( $plays < 1 || count( $heard ) < 4 ) {
		return array();
	}
	$falls = array();
	$last  = count( $heard ) - 2;
	for ( $i = 1; $i < $last; $i++ ) {
		$falls[ $i ] = ( $heard[ $i - 1 ] - $heard[ $i ] ) / $plays;
	}
	$pos = array_values( array_filter( $falls, function ( $f ) { return $f > 0; } ) );
	sort( $pos );
	$typ = $pos ? $pos[ (int) floor( ( count( $pos ) - 1 ) / 2 ) ] : 0;
	$out = array();
	foreach ( $falls as $i => $f ) {
		if ( $f >= 0.05 && $f >= 2.5 * $typ ) {
			$out[] = array( 'bucket' => $i, 'fall' => round( $f, 3 ), 'count' => $heard[ $i - 1 ] - $heard[ $i ] );
		}
	}
	usort( $out, function ( $a, $b ) { return $b['fall'] <=> $a['fall']; } );
	return array_slice( $out, 0, 5 );
}

/** The recordings a post has had, newest first, with each one's plays in a period. */
function rrt_dash_post_recordings( $post_id ) {
	global $wpdb;
	$tr = rrt_table( 'recordings' );
	return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $tr WHERE post_id = %d ORDER BY is_current DESC, id DESC", $post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
}

/**
 * Every post with a reading or with listens in the period, with its numbers.
 *
 * @return array Rows, newest reading first.
 */
function rrt_dash_readings( $from, $to ) {
	global $wpdb;
	$td   = rrt_table( 'daily' );
	$tr   = rrt_table( 'recordings' );
	$tsrc = rrt_table( 'daily_sources' );
	$agg  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare( "SELECT post_id, SUM(views) views, SUM(plays) plays, SUM(listened_s) listened, SUM(completions) completions, SUM(held60) held60, SUM(avg_rate * plays) rate_w, SUM(CASE WHEN avg_rate IS NULL THEN 0 ELSE plays END) rate_p FROM $td WHERE day BETWEEN %s AND %s GROUP BY post_id", $from, $to ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		OBJECT_K
	);
	$recs = $wpdb->get_results( "SELECT * FROM $tr WHERE is_current = 1", OBJECT_K ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$by_post = array();
	foreach ( $recs as $r ) {
		$by_post[ (int) $r->post_id ] = $r;
	}
	$ids = array_unique( array_merge( array_keys( $by_post ), array_map( 'intval', array_keys( $agg ) ) ) );
	$top = array();
	foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT post_id, channel, SUM(plays) plays FROM $tsrc WHERE day BETWEEN %s AND %s GROUP BY post_id, channel", $from, $to ) ) as $s ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$p = (int) $s->post_id;
		if ( (int) $s->plays > 0 && ( ! isset( $top[ $p ] ) || (int) $s->plays > $top[ $p ][1] ) ) {
			$top[ $p ] = array( $s->channel, (int) $s->plays );
		}
	}
	$out = array();
	foreach ( $ids as $pid ) {
		$post = get_post( $pid );
		if ( ! $post ) {
			continue;
		}
		$a   = isset( $agg[ $pid ] ) ? $agg[ $pid ] : null;
		$rec = isset( $by_post[ $pid ] ) ? $by_post[ $pid ] : null;
		$plays = $a ? (int) $a->plays : 0;
		$drop  = null;
		if ( $rec && $plays ) {
			$c = rrt_dash_curve( $pid, (int) $rec->id, $from, $to );
			if ( $c['drops'] ) {
				$drop = $c['drops'][0];
			}
		}
		$tags  = wp_get_post_tags( $pid, array( 'fields' => 'names' ) );
		$out[] = array(
			'post'        => $pid,
			'title'       => get_the_title( $post ),
			'published'   => $rec && $rec->published_at ? $rec->published_at : '',
			'duration'    => $rec ? (float) $rec->duration_s : 0,
			'tempo'       => $rec ? (int) $rec->tempo_wpm : 0,
			'recording'   => $rec ? (int) $rec->id : 0,
			'views'       => $a ? (int) $a->views : 0,
			'plays'       => $plays,
			'listened'    => $a ? (int) $a->listened : 0,
			'completions' => $a ? (int) $a->completions : 0,
			'held60'      => $a ? (int) $a->held60 : 0,
			'rate'        => ( $a && $a->rate_p ) ? round( $a->rate_w / $a->rate_p, 2 ) : null,
			'drop'        => $drop ? $drop['bucket'] * 5 : null,
			'drop_fall'   => $drop ? $drop['fall'] : null,
			'source'      => isset( $top[ $pid ] ) ? $top[ $pid ][0] : '',
			'tags'        => $tags,
		);
	}
	usort( $out, function ( $a, $b ) { return strcmp( $b['published'], $a['published'] ) ?: $b['plays'] <=> $a['plays']; } );
	return $out;
}

/** Plays and listening by channel (and campaign) in a period, optionally for one post. */
function rrt_dash_sources( $from, $to, $post_id = 0, $by_campaign = false ) {
	global $wpdb;
	$tsrc  = rrt_table( 'daily_sources' );
	$group = $by_campaign ? 'channel, campaign' : 'channel';
	$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare( "SELECT $group, SUM(views) views, SUM(plays) plays, SUM(listened_s) listened, SUM(completions) completions FROM $tsrc WHERE day BETWEEN %s AND %s", $from, $to ) . rrt_dash_where_post( $post_id ) . " GROUP BY $group ORDER BY plays DESC, views DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		ARRAY_A
	);
	foreach ( $rows as &$r ) {
		foreach ( array( 'views', 'plays', 'listened', 'completions' ) as $k ) {
			$r[ $k ] = (int) $r[ $k ];
		}
	}
	return $rows;
}

/** Plays per channel per day, for the channel chart. */
function rrt_dash_source_series( $from, $to ) {
	global $wpdb;
	$tsrc = rrt_table( 'daily_sources' );
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT day, channel, SUM(plays) plays FROM $tsrc WHERE day BETWEEN %s AND %s GROUP BY day, channel", $from, $to ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$days = rrt_dash_days( $from, $to );
	$idx  = array_flip( $days );
	$out  = array();
	foreach ( $rows as $r ) {
		if ( ! isset( $out[ $r['channel'] ] ) ) {
			$out[ $r['channel'] ] = array_fill( 0, count( $days ), 0 );
		}
		if ( isset( $idx[ $r['day'] ] ) ) {
			$out[ $r['channel'] ][ $idx[ $r['day'] ] ] += (int) $r['plays'];
		}
	}
	uasort( $out, function ( $a, $b ) { return array_sum( $b ) <=> array_sum( $a ); } );
	return array( 'days' => $days, 'series' => $out );
}

/** Device classes and countries for a period, from each visit's summary (kept 13 months). */
function rrt_dash_audience( $from, $to, $post_id = 0 ) {
	global $wpdb;
	$ts  = rrt_table( 'sessions' );
	$w   = $wpdb->prepare( 'day BETWEEN %s AND %s AND junk = 0 AND played = 1', $from, $to ) . rrt_dash_where_post( $post_id );
	$out = array();
	foreach ( array( 'device', 'country' ) as $col ) {
		$out[ $col ] = $wpdb->get_results( "SELECT $col k, COUNT(*) n FROM (SELECT DISTINCT day, day_hash, post_id, $col FROM $ts WHERE $w) x GROUP BY $col ORDER BY n DESC LIMIT 8", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}
	return $out;
}

/** The captions of a post's reading as cues, for lining the script up with the curve. */
function rrt_dash_cues( $post_id ) {
	$cap = rrt_reading_captions( $post_id );
	$txt = '';
	if ( ! empty( $cap['id'] ) ) {
		$file = get_attached_file( (int) $cap['id'] );
		if ( $file && is_readable( $file ) && filesize( $file ) < 2000000 ) {
			$txt = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}
	if ( '' === $txt ) {
		return array();
	}
	$cues = array();
	foreach ( preg_split( "/\n\s*\n/", str_replace( "\r", '', $txt ) ) as $block ) {
		if ( preg_match( '/(?:(\d+):)?(\d+):(\d+)[.,](\d+)\s+-->\s+(?:(\d+):)?(\d+):(\d+)[.,](\d+)[^\n]*\n(.+)/s', $block, $m ) ) {
			$s = (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3] + (int) $m[4] / 1000;
			$e = (int) $m[5] * 3600 + (int) $m[6] * 60 + (int) $m[7] + (int) $m[8] / 1000;
			$t = trim( wp_strip_all_tags( str_replace( "\n", ' ', $m[9] ) ) );
			if ( '' !== $t ) {
				$cues[] = array( 's' => round( $s, 2 ), 'e' => round( $e, 2 ), 't' => $t );
			}
		}
	}
	return $cues;
}

/*
 * ---------------------------------------------------------------------------
 * Reader progress, goals and practice log
 * ---------------------------------------------------------------------------
 */

/** What can be set as a goal, how it reads, and which way is better. */
function rrt_goal_metrics() {
	return array(
		'wpm'         => array( 'label' => __( 'Pace', 'foxfire-studio' ), 'unit' => 'wpm', 'range' => true ),
		'accuracy'    => array( 'label' => __( 'Accuracy', 'foxfire-studio' ), 'unit' => '%', 'better' => 'up' ),
		'fillers'     => array( 'label' => __( 'Fillers', 'foxfire-studio' ), 'unit' => '', 'better' => 'down' ),
		'long_pauses' => array( 'label' => __( 'Long pauses', 'foxfire-studio' ), 'unit' => '', 'better' => 'down' ),
		'retakes'     => array( 'label' => __( 'Sentences to retake', 'foxfire-studio' ), 'unit' => '', 'better' => 'down' ),
		'ending_drop' => array( 'label' => __( 'Phrase-ending fade', 'foxfire-studio' ), 'unit' => 'dB', 'better' => 'down' ),
		'pitch'       => array( 'label' => __( 'Pitch movement', 'foxfire-studio' ), 'unit' => 'semitones', 'better' => 'up' ),
		'energy'      => array( 'label' => __( 'Energy', 'foxfire-studio' ), 'unit' => 'dB', 'better' => 'up' ),
		'room'        => array( 'label' => __( 'Room quiet', 'foxfire-studio' ), 'unit' => __( 'dB below voice', 'foxfire-studio' ), 'better' => 'up' ),
	);
}

/**
 * A reader's goals: up to three, each a metric with a minimum, a maximum, or
 * both. Cleaned on the way out too (0.26.0), so a goal saved in another shape
 * or for a measure that no longer exists is left out instead of breaking the
 * page.
 */
function rrt_get_goals( $user_id = 0 ) {
	$g = get_user_meta( $user_id ? $user_id : get_current_user_id(), 'rrt_goals', true );
	return is_array( $g ) ? rrt_clean_goals( $g ) : array();
}

/** Cleans goals from a form. */
function rrt_clean_goals( $in ) {
	$m   = rrt_goal_metrics();
	$out = array();
	if ( ! is_array( $in ) ) {
		return $out;
	}
	foreach ( array_slice( $in, 0, 3 ) as $g ) {
		if ( ! is_array( $g ) || empty( $g['metric'] ) || ! isset( $m[ $g['metric'] ] ) ) {
			continue;
		}
		$min = isset( $g['min'] ) && '' !== trim( (string) $g['min'] ) && is_numeric( $g['min'] ) ? (float) $g['min'] : null;
		$max = isset( $g['max'] ) && '' !== trim( (string) $g['max'] ) && is_numeric( $g['max'] ) ? (float) $g['max'] : null;
		if ( null === $min && null === $max ) {
			continue;
		}
		if ( null !== $min && null !== $max && $min > $max ) {
			list( $min, $max ) = array( $max, $min );
		}
		$out[] = array( 'metric' => $g['metric'], 'min' => $min, 'max' => $max );
	}
	return $out;
}

/** A take's value for a goal metric, from its table row. */
function rrt_take_value( $t, $metric ) {
	$map = array(
		'wpm'         => 'wpm',
		'accuracy'    => 'accuracy',
		'fillers'     => 'fillers',
		'long_pauses' => 'long_pauses',
		'retakes'     => 'retake_sentences',
		'ending_drop' => 'ending_drop_db',
		'pitch'       => 'pitch_spread',
		'energy'      => 'energy_db',
		'room'        => 'room_noise_db',
	);
	$k = isset( $map[ $metric ] ) ? $map[ $metric ] : '';
	return ( '' !== $k && isset( $t[ $k ] ) && null !== $t[ $k ] && '' !== $t[ $k ] ) ? (float) $t[ $k ] : null;
}

/** Whether a take meets a goal: true, false, or null when the take has no number for it. */
function rrt_goal_met( $t, $g ) {
	$v = rrt_take_value( $t, $g['metric'] );
	if ( null === $v ) {
		return null;
	}
	if ( null !== $g['min'] && $v < $g['min'] ) {
		return false;
	}
	if ( null !== $g['max'] && $v > $g['max'] ) {
		return false;
	}
	return true;
}

/** How a goal reads: "Pace 135 to 150 wpm", "Fillers at most 3". */
function rrt_goal_text( $g ) {
	$m = rrt_goal_metrics();
	$d = $m[ $g['metric'] ];
	$u = '' === $d['unit'] ? '' : ( '%' === $d['unit'] ? '%' : ' ' . $d['unit'] );
	$n = function ( $v ) { return rtrim( rtrim( number_format( $v, 1, '.', '' ), '0' ), '.' ); };
	if ( null !== $g['min'] && null !== $g['max'] ) {
		/* translators: 1: measure, 2: lowest, 3: highest, 4: unit. */
		return sprintf( __( '%1$s %2$s to %3$s%4$s', 'foxfire-studio' ), $d['label'], $n( $g['min'] ), $n( $g['max'] ), $u );
	}
	if ( null !== $g['min'] ) {
		/* translators: 1: measure, 2: lowest, 3: unit. */
		return sprintf( __( '%1$s at least %2$s%3$s', 'foxfire-studio' ), $d['label'], $n( $g['min'] ), $u );
	}
	/* translators: 1: measure, 2: highest, 3: unit. */
	return sprintf( __( '%1$s at most %2$s%3$s', 'foxfire-studio' ), $d['label'], $n( $g['max'] ), $u );
}

/** A reader's takes in a period, oldest first, as table rows. */
function rrt_dash_takes( $user_id, $from = '', $to = '' ) {
	global $wpdb;
	$tt  = rrt_table( 'takes' );
	$sql = $wpdb->prepare( "SELECT id, post_id, at_ms, created_at, mode, duration_s, words_read, wpm, accuracy, fillers, long_pauses, retake_sentences, ending_drop_db, pitch_spread, energy_db, room_noise_db, became_recording_id, drill, drill_s, focus FROM $tt WHERE user_id = %d", $user_id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( $from ) {
		/* created_at is UTC; widen by a day either side, then keep the site-local days asked for. */
		$sql .= $wpdb->prepare( ' AND created_at BETWEEN %s AND %s', gmdate( 'Y-m-d', strtotime( $from . ' -1 day' ) ), gmdate( 'Y-m-d 23:59:59', strtotime( $to . ' +1 day' ) ) );
	}
	$rows = $wpdb->get_results( $sql . ' ORDER BY at_ms', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	$out  = array();
	foreach ( $rows as $r ) {
		$r['day'] = wp_date( 'Y-m-d', (int) floor( $r['at_ms'] / 1000 ) );
		if ( $from && ( $r['day'] < $from || $r['day'] > $to ) ) {
			continue;
		}
		$out[] = $r;
	}
	return $out;
}

/**
 * Full readings only: opening drills (0.14.0) are kept apart so they do not skew a reader's numbers.
 *
 * @param array $takes Takes as rows.
 * @return array
 */
function rrt_full_takes( $takes ) {
	return array_values(
		array_filter(
			$takes,
			function ( $t ) {
				return empty( $t['drill'] );
			}
		)
	);
}

/**
 * Opening drills only.
 *
 * @param array $takes Takes as rows.
 * @return array
 */
function rrt_drill_takes( $takes ) {
	return array_values(
		array_filter(
			$takes,
			function ( $t ) {
				return ! empty( $t['drill'] );
			}
		)
	);
}

/** The practice focus names, for showing. */
function rrt_focus_labels() {
	return array( 'pace' => __( 'Pace', 'foxfire-studio' ), 'endings' => __( 'Endings', 'foxfire-studio' ), 'fillers' => __( 'Fillers', 'foxfire-studio' ), 'pauses' => __( 'Pauses', 'foxfire-studio' ), 'pitch' => __( 'Pitch', 'foxfire-studio' ), 'energy' => __( 'Energy', 'foxfire-studio' ), 'accuracy' => __( 'Accuracy', 'foxfire-studio' ) );
}

/** Readers with takes, for an administrator to choose between. */
function rrt_dash_readers() {
	global $wpdb;
	$tt  = rrt_table( 'takes' );
	$ids = $wpdb->get_col( "SELECT DISTINCT user_id FROM $tt" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$out = array();
	foreach ( $ids as $id ) {
		$u = get_userdata( (int) $id );
		if ( $u ) {
			$out[ (int) $id ] = $u->display_name;
		}
	}
	return $out;
}

/**
 * The practice log: one line per day with takes, from the takes themselves,
 * plus the reader's own note for the day.
 */
function rrt_practice_log( $takes, $goals, $user_id ) {
	$notes = get_user_meta( $user_id, 'rrt_practice_notes', true );
	$notes = is_array( $notes ) ? $notes : array();
	$days  = array();
	foreach ( $takes as $t ) {
		$d = $t['day'];
		if ( ! isset( $days[ $d ] ) ) {
			$days[ $d ] = array( 'day' => $d, 'takes' => 0, 'seconds' => 0, 'posts' => array(), 'best' => null, 'met' => null );
		}
		$x = &$days[ $d ];
		++$x['takes'];
		$x['seconds'] += (float) $t['duration_s'];
		$x['posts'][ (int) $t['post_id'] ] = 1;
		if ( null !== $t['accuracy'] && ( null === $x['best'] || (float) $t['accuracy'] > $x['best'] ) ) {
			$x['best'] = (float) $t['accuracy'];
		}
		if ( $goals ) {
			$met = 0;
			foreach ( $goals as $g ) {
				if ( true === rrt_goal_met( $t, $g ) ) {
					++$met;
				}
			}
			$x['met'] = max( (int) $x['met'], $met );
		}
		unset( $x );
	}
	foreach ( $days as $d => &$x ) {
		$x['note'] = isset( $notes[ $d ] ) ? $notes[ $d ] : '';
	}
	unset( $x );
	krsort( $days );
	return array_values( $days );
}
