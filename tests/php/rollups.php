<?php
/**
 * Checks of the listener analytics rollups (0.25.1), run inside the test
 * WordPress:
 *   php wp-cli.phar --path=<wordpress> eval-file tests/php/rollups.php
 *
 * Made-up listeners on days of their own, for a post id no real post has,
 * rolled up and compared with numbers counted by hand. Everything made here
 * is removed afterwards.
 */

$GLOBALS['rrt_pass'] = 0;
$GLOBALS['rrt_fail'] = array();

function rrt_t( $ok, $name, $detail = '' ) {
	if ( $ok ) {
		++$GLOBALS['rrt_pass'];
		echo "ok   $name\n";
	} else {
		$GLOBALS['rrt_fail'][] = $name;
		echo "FAIL $name" . ( '' !== $detail ? ': ' . $detail : '' ) . "\n";
	}
}

global $wpdb;
/* wp eval-file runs this file inside a function, so the shared values are tied to the real globals. */
foreach (
	array(
		'rrt_post' => 900001,
		'rrt_rec'  => 77,
		'rrt_day'  => '2001-03-21',
		'rrt_ts'   => rrt_table( 'sessions' ),
		'rrt_tb'   => rrt_table( 'buckets' ),
		'rrt_td'   => rrt_table( 'daily' ),
		'rrt_tsrc' => rrt_table( 'daily_sources' ),
		'rrt_old'  => gmdate( 'Y-m-d H:i:s', time() - 3 * HOUR_IN_SECONDS ),
	) as $rrt_k => $rrt_v
) {
	$GLOBALS[ $rrt_k ] = $rrt_v;
}
$rrt_post = &$GLOBALS['rrt_post'];
$rrt_rec  = &$GLOBALS['rrt_rec'];
$rrt_day  = &$GLOBALS['rrt_day'];
$rrt_ts   = &$GLOBALS['rrt_ts'];
$rrt_tb   = &$GLOBALS['rrt_tb'];
$rrt_td   = &$GLOBALS['rrt_td'];
$rrt_tsrc = &$GLOBALS['rrt_tsrc'];
$rrt_old  = &$GLOBALS['rrt_old'];

function rrt_test_clear() {
	global $wpdb, $rrt_post, $rrt_ts, $rrt_tb, $rrt_td, $rrt_tsrc;
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $rrt_ts WHERE post_id = %d", $rrt_post ) ); // phpcs:ignore
	if ( $ids ) {
		$wpdb->query( "DELETE FROM $rrt_tb WHERE session_id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ')' ); // phpcs:ignore
	}
	foreach ( array( $rrt_ts, $rrt_td, $rrt_tsrc ) as $t ) {
		$wpdb->delete( $t, array( 'post_id' => $rrt_post ) ); // phpcs:ignore
	}
}

/*
 * One page view. $buckets: bucket => rate (plays 1), or bucket => array( plays, rate ).
 */
function rrt_test_view( $hash, $o, $buckets = array() ) {
	global $wpdb, $rrt_post, $rrt_rec, $rrt_day, $rrt_ts, $rrt_tb, $rrt_old;
	static $n = 0;
	++$n;
	$row = array_merge(
		array(
			'sid'          => substr( md5( 'rollup-test-' . $n . '-' . microtime() ), 0, 32 ),
			'day_hash'     => str_pad( $hash, 32, '0' ),
			'post_id'      => $rrt_post,
			'recording_id' => $rrt_rec,
			'day'          => $rrt_day,
			'started_at'   => $rrt_old,
			'last_at'      => $rrt_old,
			'channel'      => 'direct',
			'duration_s'   => 100,
			'played'       => 0,
			'rolled'       => 0,
		),
		$o
	);
	$wpdb->insert( $rrt_ts, $row ); // phpcs:ignore
	$id = (int) $wpdb->insert_id;
	foreach ( $buckets as $b => $v ) {
		$v = is_array( $v ) ? $v : array( 1, $v );
		$wpdb->insert( $rrt_tb, array( 'session_id' => $id, 'bucket' => $b, 'plays' => $v[0], 'rate' => $v[1] ) ); // phpcs:ignore
	}
	return $id;
}
function rrt_test_range( $a, $b, $rate = 1 ) {
	$o = array();
	for ( $i = $a; $i <= $b; $i++ ) {
		$o[ $i ] = $rate;
	}
	return $o;
}

rrt_test_clear();

/*
 * The day, a reading of 100 seconds (20 stretches of 5 s):
 *   a: two page views. The first, from an Instagram clip link, plays stretches 0 to 9;
 *      the refresh plays 8 to 19 (8 and 9 again) and finishes.
 *   b: from Google, plays 0 to 4, jumps to 10 and 11 at 1.5x, stops.
 *   c: from Google, loads the page and never plays.
 *   d: plays stretch 0 at 0.75x and leaves.
 *   e: junk (a bot), plays everything; must be left out.
 */
rrt_test_view( 'a', array( 'channel' => 'instagram', 'utm_campaign' => 'clip-0m00s', 'played' => 1, 'first_play_s' => 2, 'rate_sum' => 10, 'rate_n' => 10, 'hidden_s' => 3, 'follow_used' => 1 ), rrt_test_range( 0, 9 ) );
rrt_test_view( 'a', array( 'channel' => 'direct', 'played' => 1, 'first_play_s' => 1, 'completed' => 1, 'rate_sum' => 12, 'rate_n' => 12, 'hidden_s' => 2 ), rrt_test_range( 8, 19 ) );
rrt_test_view( 'b', array( 'channel' => 'google', 'played' => 1, 'first_play_s' => 4, 'rate_sum' => 9, 'rate_n' => 6 ), rrt_test_range( 0, 4 ) + rrt_test_range( 10, 11, 1.5 ) );
rrt_test_view( 'c', array( 'channel' => 'google' ) );
rrt_test_view( 'd', array( 'played' => 1, 'first_play_s' => 6, 'rate_sum' => 0.75, 'rate_n' => 1 ), array( 0 => 0.75 ) );
rrt_test_view( 'e', array( 'played' => 1, 'junk' => 1, 'completed' => 1 ), rrt_test_range( 0, 19 ) );

rrt_listen_rollup_day( $rrt_day );
$rrt_rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $rrt_td WHERE post_id = %d AND day = %s", $rrt_post, $rrt_day ), ARRAY_A ); // phpcs:ignore
rrt_t( 1 === count( $rrt_rows ), 'one daily row for the reading', (string) count( $rrt_rows ) );
$rrt_d = $rrt_rows ? $rrt_rows[0] : array();
$rrt_g = function ( $k ) use ( $rrt_d ) {
	return isset( $rrt_d[ $k ] ) ? $rrt_d[ $k ] : null;
};

rrt_t( 5 === (int) $rrt_g( 'page_loads' ), 'every page view is a page load, junk left out', (string) $rrt_g( 'page_loads' ) );
rrt_t( 4 === (int) $rrt_g( 'views' ), 'a refresh by the same listener is one view', (string) $rrt_g( 'views' ) );
rrt_t( 3 === (int) $rrt_g( 'plays' ), 'listeners who pressed play are plays, once each', (string) $rrt_g( 'plays' ) );
rrt_t( 1 === (int) $rrt_g( 'completions' ), 'a finish in either page view counts', (string) $rrt_g( 'completions' ) );
/* a heard all 20 stretches (100 s), b 7 (35 s), d 1 (5 s). */
rrt_t( 140 === (int) $rrt_g( 'listened_s' ), 'listening time adds the stretches heard, each once', (string) $rrt_g( 'listened_s' ) );
/* a finished; b reached 60 s of 100; d 5 s. */
rrt_t( 2 === (int) $rrt_g( 'q25' ) && 2 === (int) $rrt_g( 'q50' ) && 1 === (int) $rrt_g( 'q75' ), 'quarter, half and three quarters reached', $rrt_g( 'q25' ) . ' ' . $rrt_g( 'q50' ) . ' ' . $rrt_g( 'q75' ) );
/* The opening hold for 100 s is a quarter of it, 25 s. */
rrt_t( 2 === (int) $rrt_g( 'held60' ), 'the opening hold of a short reading is a quarter of it', (string) $rrt_g( 'held60' ) );
rrt_t( 4.0 === (float) $rrt_g( 'first_play_s' ), 'time to first play is the median (a listener counts their earliest)', (string) $rrt_g( 'first_play_s' ) );
rrt_t( abs( (float) $rrt_g( 'avg_rate' ) - 1.095 ) < 0.0005, 'average speed weighs every reading of the speed', (string) $rrt_g( 'avg_rate' ) );
rrt_t( 5 === (int) $rrt_g( 'hidden_s' ) && 1 === (int) $rrt_g( 'follow_plays' ), 'hidden time adds up, read along counts once a listener', $rrt_g( 'hidden_s' ) . ' ' . $rrt_g( 'follow_plays' ) );

$rrt_b = json_decode( (string) $rrt_g( 'buckets' ), true );
rrt_t( is_array( $rrt_b ) && 5 === $rrt_b['size'] && 20 === count( $rrt_b['heard'] ), 'the timeline has 20 stretches of 5 seconds' );
$rrt_heard = array( 3, 2, 2, 2, 2, 1, 1, 1, 1, 1, 2, 2, 1, 1, 1, 1, 1, 1, 1, 1 );
rrt_t( $rrt_b && $rrt_heard === $rrt_b['heard'], 'heard: listeners at each stretch, each once', $rrt_b ? implode( ',', $rrt_b['heard'] ) : '' );
rrt_t( $rrt_b && 1 === $rrt_b['replayed'][8] && 1 === $rrt_b['replayed'][9] && 2 === array_sum( $rrt_b['replayed'] ), 'replayed: stretches heard again after the refresh', $rrt_b ? implode( ',', $rrt_b['replayed'] ) : '' );
rrt_t( $rrt_b && 1 === $rrt_b['faster'][10] && 1 === $rrt_b['faster'][11] && 2 === array_sum( $rrt_b['faster'] ), 'faster: stretches heard above normal speed' );
rrt_t( $rrt_b && 1 === $rrt_b['slower'][0] && 1 === array_sum( $rrt_b['slower'] ), 'slower: stretches heard below normal speed' );
rrt_t( $rrt_b && 5 === array_sum( $rrt_b['skipped'] ) && 1 === $rrt_b['skipped'][5] && 1 === $rrt_b['skipped'][9] && 0 === $rrt_b['skipped'][12], 'skipped: stretches jumped over between the first and last heard', $rrt_b ? implode( ',', $rrt_b['skipped'] ) : '' );

$rrt_src = array();
foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $rrt_tsrc WHERE post_id = %d AND day = %s", $rrt_post, $rrt_day ), ARRAY_A ) as $rrt_r ) { // phpcs:ignore
	$rrt_src[ $rrt_r['channel'] . '|' . $rrt_r['campaign'] ] = array( (int) $rrt_r['views'], (int) $rrt_r['plays'], (int) $rrt_r['listened_s'], (int) $rrt_r['completions'] );
}
ksort( $rrt_src );
rrt_t(
	array(
		'direct|'                => array( 1, 1, 5, 0 ),
		'google|'                => array( 2, 1, 35, 0 ),
		'instagram|clip-0m00s'   => array( 1, 1, 100, 1 ),
	) === $rrt_src,
	'sources: a listener counts under the first page view that played',
	wp_json_encode( $rrt_src )
);

/* Rolling up the same day again gives the same numbers. */
rrt_listen_rollup_day( $rrt_day );
$rrt_again = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $rrt_td WHERE post_id = %d AND day = %s", $rrt_post, $rrt_day ), ARRAY_A ); // phpcs:ignore
rrt_t( 1 === count( $rrt_again ) && $rrt_again[0] == $rrt_d, 'rolling up a day twice changes nothing' ); // phpcs:ignore Universal.Operators.StrictComparisons
$rrt_src_n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $rrt_tsrc WHERE post_id = %d AND day = %s", $rrt_post, $rrt_day ) ); // phpcs:ignore
rrt_t( 3 === $rrt_src_n, 'and leaves no doubled source rows', (string) $rrt_src_n );

/* The day before: one more listener, so the curve over both days adds them up. */
$rrt_day = '2001-03-20';
rrt_test_view( 'f', array( 'played' => 1, 'first_play_s' => 1, 'rate_sum' => 1, 'rate_n' => 1 ), rrt_test_range( 0, 3 ) );
rrt_listen_rollup_day( $rrt_day );
$rrt_curve = rrt_dash_curve( $rrt_post, $rrt_rec, '2001-03-20', '2001-03-21' );
rrt_t( 4 === $rrt_curve['plays'], 'the curve counts the plays of both days', (string) $rrt_curve['plays'] );
rrt_t( 4 === $rrt_curve['heard'][0] && 3 === $rrt_curve['heard'][3] && 2 === $rrt_curve['heard'][4] && 20 === count( $rrt_curve['heard'] ), 'the curve adds the stretches of both days', implode( ',', $rrt_curve['heard'] ) );
rrt_t( 1 === rrt_dash_curve( $rrt_post, $rrt_rec, '2001-03-20', '2001-03-20' )['plays'], 'and a one-day period only that day' );
$rrt_sum = rrt_dash_sum_buckets( array( wp_json_encode( array( 'heard' => array( 1, 2 ) ) ), 'not json', wp_json_encode( array( 'heard' => array( 1, 1, 1 ), 'skipped' => array( 0, 1 ) ) ) ) );
rrt_t( array( 2, 3, 1 ) === $rrt_sum['heard'] && array( 0, 1, 0 ) === $rrt_sum['skipped'] && array( 0, 0, 0 ) === $rrt_sum['replayed'], 'summing timelines of different lengths, skipping a broken one' );

/* The drop-off points: a fall much steeper than the usual one. */
$rrt_drops = rrt_dash_drops( array( 100, 98, 96, 94, 70, 68, 66, 64, 62, 60, 58 ), 100 );
rrt_t( 1 === count( $rrt_drops ) && 4 === $rrt_drops[0]['bucket'] && 24 === $rrt_drops[0]['count'], 'one steep fall is the drop-off point', wp_json_encode( $rrt_drops ) );
rrt_t( array() === rrt_dash_drops( array( 100, 98, 96, 94, 92, 90 ), 100 ), 'an even decline has no drop-off point' );

/* The hourly job rolls up only days whose sessions have gone quiet. */
rrt_test_clear();
$rrt_day = '2001-03-22';
$rrt_quiet = rrt_test_view( 'g', array( 'played' => 1 ), array( 0 => 1 ) );
$rrt_day = '2001-03-23';
$rrt_live = rrt_test_view( 'h', array( 'played' => 1, 'last_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 0 => 1 ) );
rrt_listen_rollup();
$rrt_rolled = $wpdb->get_results( $wpdb->prepare( "SELECT id, rolled FROM $rrt_ts WHERE post_id = %d", $rrt_post ), OBJECT_K ); // phpcs:ignore
rrt_t( 1 === (int) $rrt_rolled[ $rrt_quiet ]->rolled, 'a session quiet for half an hour is rolled up' );
rrt_t( 0 === (int) $rrt_rolled[ $rrt_live ]->rolled, 'a session still going is left for later' );
$rrt_days = $wpdb->get_col( $wpdb->prepare( "SELECT day FROM $rrt_td WHERE post_id = %d ORDER BY day", $rrt_post ) ); // phpcs:ignore
rrt_t( array( '2001-03-22' ) === $rrt_days, 'only the quiet day has a daily row', implode( ',', $rrt_days ) );

rrt_test_clear();

/*
 * Read or listen, time to press play and returning listeners (1.2.0), on a
 * real post of 476 words: an average reader needs 120 seconds, so reading
 * needs 40 seconds on screen and 75% scrolled.
 *   p: plays 30 s on a phone, never scrolls, has listened here before: listened only.
 *   q: plays 30 s on a computer, scrolls to the end in 40 s, new: read and listened.
 *   r: never plays, scrolls 75% in 45 s: read only.
 *   s: never plays, scrolls to the end in 10 s: too fast, passed through.
 *   t: plays 25 s on a tablet and stops, came back: not a real listen, passed through.
 *   u: two page views, 75% in 25 s and then 25% in 20 s: 45 s in all, read only.
 *   v: a whole 20 second reading on a computer: a real listen, though shorter than 30 s.
 */
$rrt_real_post = wp_insert_post( array( 'post_title' => 'Rollup words', 'post_content' => '<p>' . trim( str_repeat( 'word ', 476 ) ) . '</p>', 'post_status' => 'draft' ) );
rrt_t( 40 === rrt_listen_read_need( $rrt_real_post ), 'reading 476 words needs 40 seconds on screen', (string) rrt_listen_read_need( $rrt_real_post ) );
rrt_t( 5 === rrt_listen_read_need( 900002 ), 'a post with no words needs at least 5 seconds' );
$rrt_post = $rrt_real_post;
$rrt_day  = '2001-03-24';
rrt_test_view( 'p', array( 'played' => 1, 'first_play_s' => 3.4, 'device' => 'phone', 'came_back' => 1, 'time_on_page_s' => 60 ), rrt_test_range( 0, 5 ) );
rrt_test_view( 'q', array( 'played' => 1, 'first_play_s' => 12, 'device' => 'desktop', 'came_back' => 0, 'scroll_max' => 100, 'time_on_page_s' => 40 ), rrt_test_range( 0, 5 ) );
rrt_test_view( 'r', array( 'scroll_max' => 75, 'time_on_page_s' => 45 ) );
rrt_test_view( 's', array( 'scroll_max' => 100, 'time_on_page_s' => 10 ) );
rrt_test_view( 't', array( 'played' => 1, 'first_play_s' => 75, 'device' => 'tablet', 'came_back' => 1, 'scroll_max' => 50, 'time_on_page_s' => 30 ), rrt_test_range( 0, 4 ) );
rrt_test_view( 'u', array( 'scroll_max' => 75, 'time_on_page_s' => 25 ) );
rrt_test_view( 'u', array( 'scroll_max' => 25, 'time_on_page_s' => 20 ) );
rrt_test_view( 'v', array( 'played' => 1, 'first_play_s' => 400, 'device' => 'desktop', 'duration_s' => 20, 'completed' => 1 ), rrt_test_range( 0, 3 ) );
rrt_listen_rollup_day( $rrt_day );
$rrt_w = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $rrt_td WHERE post_id = %d AND day = %s", $rrt_post, $rrt_day ), ARRAY_A ); // phpcs:ignore
$rrt_groups = $rrt_w ? array( (int) $rrt_w['v_listen'], (int) $rrt_w['v_both'], (int) $rrt_w['v_read'], (int) $rrt_w['v_pass'] ) : array();
rrt_t( array( 2, 1, 2, 2 ) === $rrt_groups, 'read or listen: listened only, both, read only, passed through', implode( ',', $rrt_groups ) );
rrt_t( $rrt_w && 7 === (int) $rrt_w['views'] && array_sum( $rrt_groups ) === (int) $rrt_w['views'], 'every visit is in exactly one group' );
rrt_t( $rrt_w && 1 === (int) $rrt_w['l_back'] && 1 === (int) $rrt_w['l_new'], 'returning listeners: only real listens with a yes or no count', $rrt_w ? $rrt_w['l_new'] . ' ' . $rrt_w['l_back'] : '' );
$rrt_h = $rrt_w ? json_decode( (string) $rrt_w['first_hist'], true ) : array();
rrt_t( array( 'm' => array( '3' => 1, '75' => 1 ), 'c' => array( '12' => 1, '390' => 1 ) ) == $rrt_h, 'time to press play: one histogram for phones and tablets, one for computers', wp_json_encode( $rrt_h ) ); // phpcs:ignore Universal.Operators.StrictComparisons
$rrt_fp = rrt_dash_first_play( $rrt_day, $rrt_day, $rrt_post );
rrt_t( 4 === $rrt_fp['all']['n'] && 12 === $rrt_fp['all']['median'], 'the median wait is the middle play', wp_json_encode( $rrt_fp['all'] ) );
rrt_t( 3 === $rrt_fp['m']['median'] && 12 === $rrt_fp['c']['median'], 'and for each kind of device' );
rrt_t( array( 'b10' => 1, 'b30' => 1, 'b120' => 1, 'more' => 1 ) === $rrt_fp['all']['bands'], 'the four bands', wp_json_encode( $rrt_fp['all']['bands'] ) );
$rrt_rl = rrt_dash_readlisten( $rrt_day, $rrt_day, $rrt_post );
rrt_t( 7 === $rrt_rl['measured'] && 2 === $rrt_rl['read'] && 1 === $rrt_rl['back'], 'the dashboard adds up the groups', wp_json_encode( $rrt_rl ) );
/* A day summarised before 1.2.0 has none of the groups: it counts towards views, not measured. */
$wpdb->insert( $rrt_td, array( 'day' => '2001-03-25', 'post_id' => $rrt_post, 'recording_id' => $rrt_rec, 'views' => 5, 'plays' => 2 ) ); // phpcs:ignore
$rrt_rl = rrt_dash_readlisten( $rrt_day, '2001-03-25', $rrt_post );
rrt_t( 12 === $rrt_rl['views'] && 7 === $rrt_rl['measured'], 'older summaries are not counted as passed through', wp_json_encode( $rrt_rl ) );
$rrt_bins = array_map( 'rrt_listen_hist_bin', array( 0.4, 59.9, 61, 299, 301, 1799, 5000 ) );
rrt_t( array( 0, 59, 60, 295, 300, 1770, 1800 ) === $rrt_bins, 'histogram bins: seconds, then 5, then 30, then one for over half an hour', implode( ',', $rrt_bins ) );
rrt_t( null === rrt_dash_first_summary( array() )['median'], 'no plays, no median' );
rrt_test_clear();
wp_delete_post( $rrt_real_post, true );
$rrt_post = 900001;

/* The privacy paragraph mentions returning listeners only while that switch is on (1.2.0). */
$rrt_cfg = rrt_settings();
$rrt_cfg['listen']['on'] = true;
$rrt_cfg['listen']['returning'] = false;
rrt_t( false === strpos( rrt_listen_privacy_text( $rrt_cfg ), 'returning' ), 'the privacy paragraph leaves out returning listeners while off' );
$rrt_cfg['listen']['returning'] = true;
$rrt_cfg['listen']['returning_months'] = 6;
rrt_t( false !== strpos( rrt_listen_privacy_text( $rrt_cfg ), 'within 6 months' ), 'and includes them, with the months, while on' );
$rrt_cfg['listen']['on'] = false;
$rrt_cfg['listen']['ga'] = true;
rrt_t( false !== strpos( rrt_listen_privacy_text( $rrt_cfg ), 'Google Analytics' ), 'the Google Analytics paragraph is there even with measuring off' );

echo "\n" . $GLOBALS['rrt_pass'] . ' passed, ' . count( $GLOBALS['rrt_fail'] ) . " failed\n";
if ( $GLOBALS['rrt_fail'] ) {
	exit( 1 );
}
