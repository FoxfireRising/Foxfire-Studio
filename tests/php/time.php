<?php
/**
 * Checks of the scheduled jobs, as if days and weeks had passed, run inside
 * the test WordPress:
 *   php wp-cli.phar --path=<wordpress> eval-file tests/php/time.php
 *
 * Data is made with old dates, then each job runs the way WordPress's cron
 * runs it: shared takes and their late comment emails (hourly), takes sent
 * from a phone (hourly), the daily trim of old listener data, and the report
 * email on its day and hour. The report reads the clock through the
 * rrt_report_now filter, so the test moves that clock. No email leaves the
 * site: pre_wp_mail catches every one. Everything made here is removed.
 *
 * @package FoxfireStudio
 */

$GLOBALS['rrt_pass'] = 0;
$GLOBALS['rrt_fail'] = array();
$GLOBALS['rrt_mail'] = array();
$GLOBALS['rrt_mail_ok'] = true;
$GLOBALS['rrt_clock'] = time();

function rrt_t( $ok, $name, $detail = '' ) {
	if ( $ok ) {
		++$GLOBALS['rrt_pass'];
		echo "ok   $name\n";
	} else {
		$GLOBALS['rrt_fail'][] = $name;
		echo "FAIL $name" . ( '' !== $detail ? ': ' . $detail : '' ) . "\n";
	}
}

function rrt_when( $local ) {
	return ( new DateTimeImmutable( $local, wp_timezone() ) )->getTimestamp();
}

add_filter(
	'pre_wp_mail',
	function ( $r, $atts ) {
		$GLOBALS['rrt_mail'][] = $atts['subject'];
		return $GLOBALS['rrt_mail_ok'];
	},
	10,
	2
);
add_filter(
	'rrt_report_now',
	function () {
		return $GLOBALS['rrt_clock'];
	}
);

global $wpdb;
$ago  = function ( $s ) {
	return gmdate( 'Y-m-d H:i:s', time() - $s );
};
$soon = function ( $s ) {
	return gmdate( 'Y-m-d H:i:s', time() + $s );
};
$post = 900002;

/* Clear anything an interrupted earlier run left behind. */
$rrt_left = $wpdb->get_col( "SELECT id FROM " . rrt_table( 'shares' ) . " WHERE token LIKE 'timetest%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
if ( $rrt_left ) {
	$wpdb->query( 'DELETE FROM ' . rrt_table( 'share_notes' ) . ' WHERE share_id IN (' . implode( ',', array_map( 'intval', $rrt_left ) ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( 'DELETE FROM ' . rrt_table( 'shares' ) . ' WHERE id IN (' . implode( ',', array_map( 'intval', $rrt_left ) ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
$rrt_left = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . rrt_table( 'sessions' ) . ' WHERE post_id = %d', $post ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
if ( $rrt_left ) {
	$wpdb->query( 'DELETE FROM ' . rrt_table( 'buckets' ) . ' WHERE session_id IN (' . implode( ',', array_map( 'intval', $rrt_left ) ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( 'DELETE FROM ' . rrt_table( 'events' ) . ' WHERE session_id IN (' . implode( ',', array_map( 'intval', $rrt_left ) ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . rrt_table( 'sessions' ) . ' WHERE post_id = %d', $post ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

/*
 * Shared takes: one past its end, one ended by hand, one still running with a
 * comment left two hours ago and one left a minute ago.
 */
rrt_share_dir_ready();
$ts  = rrt_table( 'shares' );
$tn  = rrt_table( 'share_notes' );
$ids = array();
foreach ( array( 'past' => array( $ago( DAY_IN_SECONDS ), null ), 'ended' => array( $soon( DAY_IN_SECONDS ), $ago( 60 ) ), 'running' => array( $soon( 13 * DAY_IN_SECONDS ), null ) ) as $k => $v ) {
	$file = 'timetest-' . $k . '.webm';
	file_put_contents( rrt_share_dir() . $file, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$wpdb->insert(
		$ts,
		array(
			'token'      => 'timetest' . $k,
			'owner_id'   => 1,
			'post_id'    => $post,
			'title'      => 'Time test ' . $k,
			'created_at' => $ago( 14 * DAY_IN_SECONDS ),
			'ends_at'    => $v[0],
			'ended_at'   => $v[1],
			'audio_file' => $file,
			'audio_type' => 'audio/webm',
		)
	);
	$ids[ $k ] = (int) $wpdb->insert_id;
}
$wpdb->insert( $tn, array( 'share_id' => $ids['running'], 'user_id' => 2, 'name' => 'A friend', 'body' => 'Lovely start', 'created_at' => $ago( 2 * HOUR_IN_SECONDS ) ) );
$wpdb->insert( $tn, array( 'share_id' => $ids['running'], 'user_id' => 1, 'name' => 'Owner', 'body' => 'Still typing', 'created_at' => $ago( 60 ) ) );

$GLOBALS['rrt_mail'] = array();
do_action( 'rrt_share_hourly' );

$audio = function ( $k ) use ( $wpdb, $ts, $ids ) {
	return (string) $wpdb->get_var( $wpdb->prepare( "SELECT audio_file FROM $ts WHERE id = %d", $ids[ $k ] ) ); // get_var gives null for an empty value. // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
};
rrt_t( '' === $audio( 'past' ) && ! file_exists( rrt_share_dir() . 'timetest-past.webm' ), 'a share past its end loses its audio' );
rrt_t( '' === $audio( 'ended' ) && ! file_exists( rrt_share_dir() . 'timetest-ended.webm' ), 'a share ended by hand loses its audio' );
rrt_t( 'timetest-running.webm' === $audio( 'running' ) && file_exists( rrt_share_dir() . 'timetest-running.webm' ), 'a running share keeps its audio' );
$notified = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, notified FROM $tn WHERE share_id = %d ORDER BY user_id", $ids['running'] ), OBJECT_K ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
/* The SQLite stand-in that WordPress 6.0 needs (v2.1.3) turns HAVING into broken SQL; MySQL and MariaDB do not. */
$wpdb->suppress_errors( true );
$wpdb->get_results( "SELECT user_id, MAX(created_at) AS last FROM $tn GROUP BY user_id HAVING last <= '9999-01-01'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$rrt_having = '' === $wpdb->last_error;
$wpdb->suppress_errors( false );
if ( $rrt_having ) {
	rrt_t( 1 === count( $GLOBALS['rrt_mail'] ) && '1' === (string) $notified[2]->notified, 'a comment left two hours ago is emailed once', count( $GLOBALS['rrt_mail'] ) . ' emails' );
} else {
	echo "skip a comment left two hours ago is emailed once: this test database cannot run HAVING\n";
}
rrt_t( '0' === (string) $notified[1]->notified, 'a comment left a minute ago waits' );
$GLOBALS['rrt_mail'] = array();
do_action( 'rrt_share_hourly' );
rrt_t( 0 === count( $GLOBALS['rrt_mail'] ), 'the next hour sends nothing again' );

/* Takes sent from a phone: one past its 14 days, one with days to go. */
rrt_sent_dir_ready();
$keep_meta = get_user_meta( 1, '_rrt_sent', true );
$list      = array();
foreach ( array( 'old' => -HOUR_IN_SECONDS, 'new' => 3 * DAY_IN_SECONDS ) as $k => $left ) {
	$file = 'timetest-' . $k . '.wav';
	file_put_contents( rrt_sent_dir() . $file, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$list[] = array(
		'id'   => 'timetest' . $k,
		'file' => $file,
		'type' => 'audio/wav',
		'sent' => time() + $left - 14 * DAY_IN_SECONDS,
		'ends' => time() + $left,
	);
}
update_user_meta( 1, '_rrt_sent', $list );
do_action( 'rrt_share_hourly' );
$left = wp_list_pluck( (array) get_user_meta( 1, '_rrt_sent', true ), 'id' );
rrt_t( ! in_array( 'timetestold', $left, true ) && ! file_exists( rrt_sent_dir() . 'timetest-old.wav' ), 'a sent take past 14 days is deleted' );
rrt_t( in_array( 'timetestnew', $left, true ) && file_exists( rrt_sent_dir() . 'timetest-new.wav' ), 'a sent take with days to go is kept' );

/* The daily trim: listen events and visits on each side of their limits. */
$s  = rrt_settings();
$ev = max( 1, (int) $s['listen']['events_days'] );
$se = max( 30, (int) $s['listen']['sessions_days'] );
$tss = rrt_table( 'sessions' );
$tb  = rrt_table( 'buckets' );
$te  = rrt_table( 'events' );
$sess = array();
foreach ( array( 'old' => ( $se + 1 ) * DAY_IN_SECONDS, 'kept' => ( $se - 1 ) * DAY_IN_SECONDS ) as $k => $age ) {
	$wpdb->insert(
		$tss,
		array(
			'sid'        => md5( 'timetest' . $k ),
			'day_hash'   => md5( 'timetest' ),
			'post_id'    => $post,
			'day'        => gmdate( 'Y-m-d', time() - $age ),
			'started_at' => $ago( $age ),
			'last_at'    => $ago( $age ),
			'rolled'     => 1,
		)
	);
	$sess[ $k ] = (int) $wpdb->insert_id;
	$wpdb->insert( $tb, array( 'session_id' => $sess[ $k ], 'bucket' => 1 ) );
}
foreach ( array( 'old' => ( $ev + 1 ) * DAY_IN_SECONDS, 'kept' => ( $ev - 1 ) * DAY_IN_SECONDS ) as $k => $age ) {
	$wpdb->insert( $te, array( 'session_id' => $sess['kept'], 'type' => 'timetest' . $k, 'created_at' => $ago( $age ) ) );
}
do_action( 'rrt_listen_daily' );
$n = function ( $sql ) use ( $wpdb ) {
	return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
};
rrt_t( 0 === $n( "SELECT COUNT(*) FROM $te WHERE type = 'timetestold'" ), "listen events older than $ev days are trimmed" );
rrt_t( 1 === $n( "SELECT COUNT(*) FROM $te WHERE type = 'timetestkept'" ), 'newer listen events stay' );
rrt_t( 0 === $n( "SELECT COUNT(*) FROM $tss WHERE id = " . $sess['old'] ) && 0 === $n( "SELECT COUNT(*) FROM $tb WHERE session_id = " . $sess['old'] ), "visits older than $se days go, with their listening" );
rrt_t( 1 === $n( "SELECT COUNT(*) FROM $tss WHERE id = " . $sess['kept'] ) && 1 === $n( "SELECT COUNT(*) FROM $tb WHERE session_id = " . $sess['kept'] ), 'newer visits stay' );

/* The report email: monthly at 8 am, sent once on the 1st, retried at most four times. */
$keep_report = array( get_option( 'rrt_report' ), get_option( 'rrt_report_last' ), get_option( 'rrt_report_attempts' ) );
$tr          = rrt_table( 'reports' );
$first_id    = $n( "SELECT COALESCE(MAX(id), 0) FROM $tr" );
update_option( 'rrt_report', array_merge( rrt_report_defaults(), array( 'on' => true, 'interval' => 'month', 'hour' => 8 ) ) );
delete_option( 'rrt_report_last' );
delete_option( 'rrt_report_attempts' );
delete_transient( 'rrt_report_lock' );
$check = function ( $local ) {
	$GLOBALS['rrt_clock'] = rrt_when( $local );
	$GLOBALS['rrt_mail']  = array();
	do_action( 'rrt_report_check' );
	return count( $GLOBALS['rrt_mail'] );
};

update_option( 'rrt_report_last', rrt_report_last_complete( 'month' )['key'] );
$GLOBALS['rrt_clock'] = rrt_when( '2026-11-30 23:00' );
update_option( 'rrt_report_last', rrt_report_last_complete( 'month' )['key'] );
rrt_t( 0 === $check( '2026-11-30 23:00' ), 'no report on the last day of the month (October already sent)' );
rrt_t( 0 === $check( '2026-12-01 07:59' ), 'no report at 7:59 on the 1st' );
rrt_t( 1 === $check( '2026-12-01 08:00' ), 'the report goes at 8:00 on the 1st' );
$last = get_option( 'rrt_report_last' );
rrt_t( is_string( $last ) && false !== strpos( $last, '2026-11-01' ) && false !== strpos( $last, '2026-11-30' ), 'it covers November', (string) $last );
rrt_t( 0 === $check( '2026-12-01 09:00' ) && 0 === $check( '2026-12-15 08:00' ), 'it is not sent again that month' );
rrt_t( 1 === $check( '2027-01-01 08:15' ) && false !== strpos( (string) get_option( 'rrt_report_last' ), '2026-12-31' ), 'January 1st sends December' );

$GLOBALS['rrt_mail_ok'] = false;
$tries = 0;
foreach ( array( '08:00', '09:00', '10:00', '11:00', '12:00', '13:00' ) as $h ) {
	$tries += $check( '2027-02-01 ' . $h );
}
$GLOBALS['rrt_mail_ok'] = true;
rrt_t( 4 === $tries, 'a failing mail is tried four times, then left alone', $tries . ' tries' );
rrt_t( false === strpos( (string) get_option( 'rrt_report_last' ), '2027-01-31' ), 'a failed report is not marked sent' );
rrt_t( 'failed' === $wpdb->get_var( "SELECT status FROM $tr WHERE id > $first_id ORDER BY id DESC LIMIT 1" ), 'the failure is in the report history' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

/* Put everything back. */
foreach ( glob( rrt_share_dir() . 'timetest-*' ) as $f ) {
	wp_delete_file( $f );
}
foreach ( glob( rrt_sent_dir() . 'timetest-*' ) as $f ) {
	wp_delete_file( $f );
}
$wpdb->query( "DELETE FROM $tn WHERE share_id IN (" . implode( ',', $ids ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DELETE FROM $ts WHERE id IN (" . implode( ',', $ids ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DELETE FROM $te WHERE session_id IN (" . implode( ',', $sess ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DELETE FROM $tb WHERE session_id IN (" . implode( ',', $sess ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DELETE FROM $tss WHERE id IN (" . implode( ',', $sess ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DELETE FROM $tr WHERE id > $first_id" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$keep_meta ? update_user_meta( 1, '_rrt_sent', $keep_meta ) : delete_user_meta( 1, '_rrt_sent' );
foreach ( array( 'rrt_report', 'rrt_report_last', 'rrt_report_attempts' ) as $i => $o ) {
	false === $keep_report[ $i ] ? delete_option( $o ) : update_option( $o, $keep_report[ $i ] );
}
delete_transient( 'rrt_report_lock' );

echo "\n" . $GLOBALS['rrt_pass'] . ' passed, ' . count( $GLOBALS['rrt_fail'] ) . " failed\n";
if ( $GLOBALS['rrt_fail'] ) {
	exit( 1 );
}
