<?php
/**
 * Saying how downloads are counted, and keeping an eye on them (1.24.0).
 *
 * The IAB Tech Lab guidelines (v2.3, 5.5) ask anyone who follows them to
 * publish the method, say where they differ and why, look for unusual spikes
 * and review the process at least twice a year. So:
 * - the [foxfire_download_method] shortcode writes the method out from the
 *   live settings, and Podcast feed can make a draft page holding it;
 * - the Overview and the monthly report flag unusual days;
 * - the Overview asks for a review six months after the last one.
 *
 * The plugin says it follows the guidelines and never that it is certified:
 * certification is a paid audit for companies.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** How long between reviews. */
define( 'RRT_DL_REVIEW', 182 * DAY_IN_SECONDS );

/**
 * The method, from the live settings, as headed paragraphs.
 *
 * @return array[] Each: title, text.
 */
function rrt_dl_method() {
	$s    = rrt_dl_settings();
	$net  = function_exists( 'rrt_net_info' ) ? rrt_net_info() : array( 'date' => '' );
	$how  = rrt_dl_handoff();
	$tz   = wp_timezone_string();
	$out  = array();
	$out[] = array(
		'title' => __( 'What this follows', 'foxfire-studio' ),
		/* translators: %s: a version number, such as 2.3. */
		'text'  => sprintf( __( 'Downloads of this podcast are counted on this site, following the IAB Tech Lab Podcast Technical Measurement Guidelines, version %s. This site follows the published guidelines and has had no audit or certification by IAB Tech Lab.', 'foxfire-studio' ), RRT_DL_IAB ),
	);
	$out[] = array(
		'title' => __( 'What counts as a download', 'foxfire-studio' ),
		'text'  => __( 'A request for an episode file counts as one download once the podcast app has asked for the file\'s tags plus one minute of its audio, or for the whole file when the episode is shorter than a minute. When an app asks for the file in pieces, the pieces it asks for are added together. The size of each file\'s tags is measured from the file itself.', 'foxfire-studio' ),
	);
	$out[] = array(
		'title' => __( 'Once a day', 'foxfire-studio' ),
		/* translators: %s: a time zone, such as America/Edmonton. */
		'text'  => sprintf( __( 'Each podcast consumer is counted at most once per episode file each calendar day, midnight to midnight in this site\'s time zone (%s). A podcast consumer is the IP address (an IPv6 address cut to its first 64 bits) together with the app\'s user agent. A podcast consumer each day is counted the same way, once across all episodes.', 'foxfire-studio' ), '' !== $tz ? $tz : 'UTC' ),
	);
	$out[] = array(
		'title' => __( 'What is left out', 'foxfire-studio' ),
		'text'  => __( 'HEAD requests; requests that only ask whether a file has changed; the two-byte test requests some apps send first (bytes 0 to 1); Apple Watch duplicates of a download on the paired phone; bots, crawlers, feed checkers and server libraries, and empty or broken user agents, using the open user agent list of the Open Podcast Analytics Working Group (OPAWG) and OP3\'s list of further bots; addresses of data centres, hosting companies and VPNs; and any address with more than 30 counted downloads in an hour or 100 in a day.', 'foxfire-studio' ),
	);
	$lists = array();
	$ua    = function_exists( 'rrt_ua_lists_date' ) ? rrt_ua_lists_date() : '';
	if ( '' !== $ua ) {
		/* translators: %s: the lists' editions, such as "OPAWG 2026-11-17, OP3 2026-11-18". */
		$lists[] = sprintf( __( 'User agents: %s.', 'foxfire-studio' ), $ua );
	}
	if ( ! empty( $net['date'] ) ) {
		/* translators: %s: a date. */
		$lists[] = sprintf( __( 'Data centres and VPNs: X4BNet lists_vpn, edition of %s, refreshed monthly (IP address data powered by IPLocate.io).', 'foxfire-studio' ), $net['date'] );
	}
	if ( function_exists( 'rrt_net_shared' ) && rrt_net_shared() ) {
		/* translators: %s: a date. */
		$lists[] = sprintf( __( 'Some shared networks (such as an office or a school) are exempt from the per-address limits; the list was last confirmed on %s and is confirmed again at least every 90 days.', 'foxfire-studio' ), $s['shared_ok'] ? wp_date( get_option( 'date_format' ), (int) $s['shared_ok'] ) : __( 'not yet', 'foxfire-studio' ) );
	}
	if ( $lists ) {
		$out[] = array(
			'title' => __( 'The lists used', 'foxfire-studio' ),
			'text'  => implode( ' ', $lists ),
		);
	}
	if ( function_exists( 'rrt_member_on' ) && rrt_member_on() ) {
		$out[] = array(
			'title' => __( 'Members\' feeds', 'foxfire-studio' ),
			'text'  => __( 'Episodes for members are counted in the same way, with the member as the podcast consumer in place of the address and user agent, so a member counts once a day however their address changes. The per-address limits and the data centre list do not apply to members. Only totals are kept, never which member downloaded what.', 'foxfire-studio' ),
		);
	}
	$diff = array();
	$diff[] = __( 'Counting sees the bytes an app asks for. It cannot see how many were actually delivered, so an app that asks for a whole file and stops early still counts.', 'foxfire-studio' );
	$chk = (array) $s['check'];
	if ( '' !== $how && isset( $chk['ranges'] ) && ! $chk['ranges'] ) {
		$diff[] = __( 'On this server counting cannot see which part of a file an app asks for, so every request is treated as a whole download, and test requests may be counted.', 'foxfire-studio' );
	}
	if ( '' === $how ) {
		$diff[] = __( 'Episode files are reached through a redirect. Some apps, Apple\'s player among them, can ask for the rest of a file straight from where the redirect points, so a few downloads that only sent their first test request through the counting address are not counted.', 'foxfire-studio' );
	} elseif ( function_exists( 'rrt_off_on' ) && rrt_off_on() ) {
		$diff[] = __( 'Files with a copy on outside storage are reached through a redirect, so some of Apple\'s streaming of those files may go uncounted.', 'foxfire-studio' );
	}
	$diff[] = __( 'The player on this site\'s own pages is not counted here; its plays are measured separately.', 'foxfire-studio' );
	$prefixes = rrt_dl_prefixes();
	if ( $prefixes ) {
		$hosts = array_map(
			function ( $p ) {
				return (string) wp_parse_url( $p, PHP_URL_HOST );
			},
			$prefixes
		);
		/* translators: %s: a list of host names. */
		$diff[] = sprintf( __( 'Episode addresses in the public feed also pass through %s, which count downloads their own way.', 'foxfire-studio' ), implode( ', ', $hosts ) );
	}
	$out[] = array(
		'title' => __( 'Where this differs', 'foxfire-studio' ),
		'text'  => implode( ' ', $diff ),
	);
	$out[] = array(
		'title' => __( 'Reviews', 'foxfire-studio' ),
		'text'  => trim(
			( $s['reviewed']
				/* translators: %s: a date. */
				? sprintf( __( 'The counts and this method were last reviewed on %s.', 'foxfire-studio' ), wp_date( get_option( 'date_format' ), (int) $s['reviewed'] ) )
				: '' ) . ' ' . __( 'They are reviewed at least twice a year, and unusual days are looked into.', 'foxfire-studio' )
		),
	);
	if ( '' !== $s['since'] ) {
		$out[] = array(
			'title' => __( 'Since when', 'foxfire-studio' ),
			/* translators: %s: a date. */
			'text'  => sprintf( __( 'Downloads have been counted this way since %s. Any earlier figures used a simpler method.', 'foxfire-studio' ), wp_date( get_option( 'date_format' ), strtotime( $s['since'] ) ) ),
		);
	}
	return $out;
}

/** The method as HTML, for the shortcode. */
function rrt_dl_method_html() {
	$h = '<div class="rrt-dl-method">';
	foreach ( rrt_dl_method() as $p ) {
		$h .= '<h3>' . esc_html( $p['title'] ) . '</h3><p>' . esc_html( $p['text'] ) . '</p>';
	}
	return $h . '</div>';
}
add_shortcode( 'foxfire_download_method', 'rrt_dl_method_html' );

/** Makes the draft method page (or finds the one made before), and returns its id. */
function rrt_dl_method_page() {
	$s = rrt_dl_settings();
	if ( $s['page'] && get_post( $s['page'] ) && 'trash' !== get_post_status( $s['page'] ) ) {
		return (int) $s['page'];
	}
	$id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => __( 'How downloads are counted', 'foxfire-studio' ),
			'post_content' => "<!-- wp:shortcode -->\n[foxfire_download_method]\n<!-- /wp:shortcode -->",
		)
	);
	if ( is_wp_error( $id ) || ! $id ) {
		return 0;
	}
	$s['page'] = (int) $id;
	rrt_dl_settings_save( $s );
	return (int) $id;
}

/*
 * ---------------------------------------------------------------------
 * Unusual days, and the review
 * ---------------------------------------------------------------------
 */

/** The middle value of a list of numbers. */
function rrt_dl_median( $nums ) {
	sort( $nums );
	$n = count( $nums );
	if ( ! $n ) {
		return 0;
	}
	return 0 === $n % 2 ? ( $nums[ $n / 2 - 1 ] + $nums[ $n / 2 ] ) / 2 : $nums[ ( $n - 1 ) / 2 ];
}

/**
 * Unusual days in a period: a day's downloads, or one app's, at least 20 and
 * over three times the median of the 28 days before.
 *
 * @return array[] day, what ('all' or an app's name), n, median.
 */
function rrt_dl_unusual( $from, $to ) {
	global $wpdb;
	$start = wp_date( 'Y-m-d', strtotime( $from . ' 12:00' ) - 28 * DAY_IN_SECONDS );
	$tc    = rrt_table( 'dl_counts' );
	$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT day, app, SUM(downloads) AS n FROM $tc WHERE day BETWEEN %s AND %s GROUP BY day, app", $start, $to ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$by    = array( 'all' => array() );
	foreach ( (array) $rows as $r ) {
		$by['all'][ $r['day'] ] = ( isset( $by['all'][ $r['day'] ] ) ? $by['all'][ $r['day'] ] : 0 ) + (int) $r['n'];
		$by[ $r['app'] ][ $r['day'] ] = (int) $r['n'];
	}
	$out = array();
	$t   = strtotime( $from . ' 12:00' );
	$end = strtotime( $to . ' 12:00' );
	for ( ; $t <= $end; $t += DAY_IN_SECONDS ) {
		$day = wp_date( 'Y-m-d', $t );
		foreach ( $by as $what => $days ) {
			$n = isset( $days[ $day ] ) ? $days[ $day ] : 0;
			if ( $n < 20 ) {
				continue;
			}
			$before = array();
			for ( $i = 1; $i <= 28; $i++ ) {
				$d        = wp_date( 'Y-m-d', $t - $i * DAY_IN_SECONDS );
				$before[] = isset( $days[ $d ] ) ? $days[ $d ] : 0;
			}
			$med = rrt_dl_median( $before );
			if ( $n > 3 * $med ) {
				$out[] = array(
					'day'    => $day,
					'what'   => $what,
					'n'      => $n,
					'median' => $med,
				);
			}
		}
	}
	/* A day flagged as a whole needs no app line too, unless that app made it. */
	return $out;
}

/** Whether a review of the counts is due: six months after the last one, or after counting started. */
function rrt_dl_review_due() {
	if ( ! rrt_dl_on() ) {
		return false;
	}
	$s    = rrt_dl_settings();
	$last = $s['reviewed'] ? (int) $s['reviewed'] : ( '' !== $s['since'] ? (int) strtotime( $s['since'] ) : time() );
	return time() - $last > RRT_DL_REVIEW;
}

add_action(
	'admin_post_rrt_dl_reviewed',
	function () {
		check_admin_referer( 'rrt_dl_reviewed' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'foxfire-studio' ) );
		}
		$s             = rrt_dl_settings();
		$s['reviewed'] = time();
		rrt_dl_settings_save( $s );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=rrt-dashboard' ) );
		exit;
	}
);

/** The Overview's line asking for a review, with its button. */
function rrt_dl_review_html() {
	if ( ! rrt_dl_review_due() ) {
		return '';
	}
	return '<div class="notice notice-info inline"><p>' . esc_html__( 'Time to review how downloads are counted: look over the unusual days and the apps below, and check the method page still says what happens. The IAB guidelines ask for this at least twice a year.', 'foxfire-studio' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="rrt_dl_reviewed">' . wp_nonce_field( 'rrt_dl_reviewed', '_wpnonce', true, false ) . '<p><button type="submit" class="button" data-rrt-dl-reviewed>' . esc_html__( 'Reviewed', 'foxfire-studio' ) . '</button></p></form></div>';
}
