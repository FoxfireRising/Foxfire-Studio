<?php
/**
 * Downloads by podcast apps, counted the way the IAB Tech Lab's Podcast
 * Technical Measurement Guidelines (version 2.3) describe (1.2.0, rebuilt
 * in 1.24.0).
 *
 * With Foxfire Studio, Podcast feed, Count downloads switched on, each
 * episode in the feed points to a short address on this site,
 * /podcast-file/<post id>/<file name>, which counts the request and then
 * sends the audio. Members' feeds (1.21.0) are counted where the plugin sends
 * members' files itself.
 *
 * How the audio is sent (Podcast feed, How files are sent):
 * - the hand-off (1.24.0): the web server is told which file to send, and
 *   sends it with byte ranges; the app never sees a redirect, so every range
 *   it asks for comes back here. LiteSpeed (X-LiteSpeed-Location), Apache
 *   with mod_xsendfile (X-Sendfile) and nginx with a short setup
 *   (X-Accel-Redirect). Check counting tests it on the site itself, and
 *   Automatic only hands off after that check has passed;
 * - a 302 redirect (1.2.0's way, and always for a file with an outside copy,
 *   1.23.0). Some apps then fetch the rest of the file straight from where
 *   the redirect pointed, so their later ranges are not seen.
 *
 * A download counts once per podcast consumer, file and day (a calendar day
 * in the site's time zone):
 * - the consumer is a scrambled code made from the IP address (IPv6 cut to
 *   its first 64 bits) and the user agent, with a random code that is
 *   replaced every day and never kept; for a member, the member (never
 *   stored, only used to make the code);
 * - every range a consumer asks for is joined (overlaps once, at most
 *   RRT_DL_PIECES pieces kept), and the download counts the moment the joined
 *   bytes reach the file's own ID3 tag plus one minute of its audio, or the
 *   whole file for a reading shorter than a minute. A GET with no Range
 *   header asks for the whole file;
 * - never counted: HEAD; a GET that only asks whether the file has changed
 *   (If-None-Match or If-Modified-Since, no Range); the two-byte probe
 *   (bytes=0-1); Apple Watch duplicates, bots, and empty or broken user
 *   agents (includes/agents.php); data centre and VPN addresses
 *   (includes/netlist.php); an address past RRT_DL_HOURLY counted downloads
 *   in an hour or RRT_DL_DAILY in a day, unless it is one of the owner's
 *   shared networks.
 *
 * A download is not a listen: many apps download new episodes by themselves.
 * The short address always works, counting or not, so episodes already saved
 * in apps keep playing if counting is switched off later. It only ever
 * reveals the reading of a published post, which is public anyway.
 *
 * Tables: rrt_dl_counts (day, post, app, device, feed, count), rrt_dl_days
 * (each day's podcast consumers), rrt_dl_ads (sponsor messages delivered,
 * 1.22.0's messages), rrt_dl_seen (the day's codes and the bytes each has
 * asked for, kept two days), and 1.2.0's rrt_downloads, kept for the counts
 * made before 1.24.0.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The short address's first path segment. */
define( 'RRT_DL_BASE', 'podcast-file' );

/** At most this many counted downloads an hour from one address. */
define( 'RRT_DL_HOURLY', 30 );

/** At most this many counted downloads a day from one address (1.24.0). */
define( 'RRT_DL_DAILY', 100 );

/** At most this many separate pieces of a file are remembered per consumer (1.24.0). */
define( 'RRT_DL_PIECES', 32 );

/** The guidelines followed, for the method page. */
define( 'RRT_DL_IAB', '2.3' );

/** Whether download counting is on (Foxfire Studio, Podcast feed). */
function rrt_dl_on() {
	return function_exists( 'rrt_podcast_settings' ) && ! empty( rrt_podcast_settings()['count'] );
}

/** Counting's own settings (1.24.0), apart from the switch itself, which stays in rrt_podcast. */
function rrt_dl_settings() {
	$s = get_option( 'rrt_dl', array() );
	return array_merge(
		array(
			'send'      => 'auto', // auto | apache | nginx | redirect.
			'check'     => array(), // The last Check counting: at, ok, how, sig, why.
			'shared'    => '', // Shared networks, one range per line.
			'shared_ok' => 0, // When they were last confirmed.
			'reviewed'  => 0, // The owner's last review of the counts.
			'op3'       => false, // OP3's prefix in front of public episode addresses.
			'prefixes'  => '', // Other prefixes, one per line.
			'page'      => 0, // The method page made by Settings.
			'since'     => '', // The first day counted the 1.24.0 way.
		),
		is_array( $s ) ? $s : array()
	);
}

/** Saves counting's settings. */
function rrt_dl_settings_save( $s ) {
	update_option( 'rrt_dl', array_intersect_key( array_merge( rrt_dl_settings(), $s ), rrt_dl_settings() ) );
}

/** Creates or updates the tables. Called from rrt_stats_install(). */
function rrt_dl_install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$c = $wpdb->get_charset_collate();
	/* 1.2.0's counts, kept as they were. */
	$t = rrt_table( 'downloads' );
	dbDelta(
		"CREATE TABLE $t (
		day date NOT NULL,
		post_id bigint(20) unsigned NOT NULL,
		app varchar(40) NOT NULL DEFAULT '',
		downloads int(10) unsigned NOT NULL DEFAULT 0,
		PRIMARY KEY  (day,post_id,app)
		) $c;"
	);
	$t = rrt_table( 'dl_seen' );
	dbDelta(
		"CREATE TABLE $t (
		day date NOT NULL,
		h char(32) NOT NULL,
		got bigint(20) unsigned NOT NULL DEFAULT 0,
		spans varchar(800) NOT NULL DEFAULT '',
		done tinyint(3) unsigned NOT NULL DEFAULT 0,
		ads tinyint(3) unsigned NOT NULL DEFAULT 0,
		PRIMARY KEY  (day,h)
		) $c;"
	);
	$t = rrt_table( 'dl_counts' );
	dbDelta(
		"CREATE TABLE $t (
		day date NOT NULL,
		post_id bigint(20) unsigned NOT NULL,
		app varchar(60) NOT NULL DEFAULT '',
		device varchar(16) NOT NULL DEFAULT '',
		feed varchar(8) NOT NULL DEFAULT 'public',
		downloads int(10) unsigned NOT NULL DEFAULT 0,
		PRIMARY KEY  (day,post_id,app,device,feed)
		) $c;"
	);
	$t = rrt_table( 'dl_days' );
	dbDelta(
		"CREATE TABLE $t (
		day date NOT NULL,
		consumers int(10) unsigned NOT NULL DEFAULT 0,
		members int(10) unsigned NOT NULL DEFAULT 0,
		PRIMARY KEY  (day)
		) $c;"
	);
	$t = rrt_table( 'dl_ads' );
	dbDelta(
		"CREATE TABLE $t (
		day date NOT NULL,
		spot_id bigint(20) unsigned NOT NULL,
		post_id bigint(20) unsigned NOT NULL,
		slot varchar(8) NOT NULL DEFAULT '',
		delivered int(10) unsigned NOT NULL DEFAULT 0,
		PRIMARY KEY  (day,spot_id,post_id,slot)
		) $c;"
	);
	$s = rrt_dl_settings();
	if ( '' === $s['since'] ) {
		$s['since'] = wp_date( 'Y-m-d' );
		rrt_dl_settings_save( $s );
	}
}

/** The short address for a post's reading; the file name is only for apps that look at it. */
function rrt_dl_url( $post_id, $audio_url ) {
	$name = basename( rawurldecode( (string) wp_parse_url( (string) $audio_url, PHP_URL_PATH ) ) );
	$name = preg_replace( '/[^A-Za-z0-9._-]/', '', $name );
	if ( '' === $name ) {
		$name = 'reading.mp3';
	}
	return home_url( '/' . RRT_DL_BASE . '/' . (int) $post_id . '/' . $name );
}

/**
 * The address the public feed gives an episode: the short address, with
 * any measurement prefixes the owner chose in front (1.24.0).
 */
function rrt_dl_feed_url( $post_id, $audio_url ) {
	return rrt_dl_prefixed( rrt_dl_url( $post_id, $audio_url ) );
}

/** The prefixes in order: OP3's first when ticked, then the owner's own. */
function rrt_dl_prefixes() {
	$s   = rrt_dl_settings();
	$out = array();
	if ( $s['op3'] ) {
		$out[] = 'https://op3.dev/e/';
	}
	foreach ( preg_split( '/\r?\n/', (string) $s['prefixes'] ) as $p ) {
		$p = trim( $p );
		if ( '' !== $p && preg_match( '#^https://[A-Za-z0-9.-]+(/[^\s]*)?$#', $p ) ) {
			$out[] = '/' === substr( $p, -1 ) ? $p : $p . '/';
		}
	}
	return array_values( array_unique( $out ) );
}

/**
 * An address with the prefixes in front. Prefix services take the rest of
 * the address without its scheme (op3.dev/e/example.com/file.mp3).
 */
function rrt_dl_prefixed( $url ) {
	$prefixes = rrt_dl_prefixes();
	if ( ! $prefixes ) {
		return $url;
	}
	$rest = preg_replace( '#^https?://#', '', (string) $url );
	foreach ( array_reverse( $prefixes ) as $p ) {
		$rest = preg_replace( '#^https?://#', '', $p ) . $rest;
	}
	return 'https://' . $rest;
}

/**
 * The post id in a request path, or 0 when the path is not a short address.
 * The check's own address (post 0) gives -1.
 *
 * @param string $path      The request's path, without the query.
 * @param string $home_path The path of the site's home address.
 */
function rrt_dl_match( $path, $home_path ) {
	$home_path = '/' . trim( (string) $home_path, '/' );
	$home_path = '/' === $home_path ? '' : $home_path;
	$path      = rawurldecode( (string) $path );
	if ( '' !== $home_path ) {
		if ( 0 !== strpos( $path, $home_path . '/' ) ) {
			return 0;
		}
		$path = substr( $path, strlen( $home_path ) );
	}
	if ( preg_match( '#^/' . RRT_DL_BASE . '/([0-9]{1,20})(?:/[^/]*)?/?$#', $path, $m ) ) {
		return '0' === $m[1] ? -1 : (int) $m[1];
	}
	return 0;
}

/** Bots, crawlers, feed checkers, scripts, Apple Watch duplicates and broken names (1.24.0: OPAWG's list). */
function rrt_dl_is_bot( $ua ) {
	return ! rrt_ua_info( $ua )['count'];
}

/** The app's name from its user agent, or 'Other'. */
function rrt_dl_app( $ua ) {
	$i = rrt_ua_info( $ua );
	return '' === $i['name'] ? 'Other' : $i['name'];
}

/*
 * ---------------------------------------------------------------------
 * Ranges and the one-minute rule
 * ---------------------------------------------------------------------
 */

/**
 * The byte ranges a request asks for.
 *
 * @param string $range The Range header, or ''.
 * @param int    $size  The file's size.
 * @return array[]|null Pieces as array( first, last ), or null for a header that is not a byte range (the whole file is sent).
 */
function rrt_dl_ranges( $range, $size ) {
	$range = trim( (string) $range );
	$size  = (int) $size;
	if ( '' === $range || $size <= 0 ) {
		return null;
	}
	if ( ! preg_match( '/^bytes\s*=\s*(.+)$/i', $range, $m ) ) {
		return null;
	}
	$out = array();
	foreach ( array_slice( explode( ',', $m[1] ), 0, 16 ) as $p ) {
		if ( ! preg_match( '/^\s*(\d*)\s*-\s*(\d*)\s*$/', $p, $q ) || ( '' === $q[1] && '' === $q[2] ) ) {
			continue;
		}
		if ( '' === $q[1] ) {
			$a = max( 0, $size - (int) $q[2] );
			$b = $size - 1;
		} else {
			$a = (int) $q[1];
			$b = '' === $q[2] ? $size - 1 : min( (int) $q[2], $size - 1 );
		}
		if ( $a <= $b && $a < $size ) {
			$out[] = array( $a, $b );
		}
	}
	return $out;
}

/** Joins pieces: sorted, overlapping and touching pieces made one, at most RRT_DL_PIECES (the closest are joined first). */
function rrt_dl_join( $spans ) {
	usort(
		$spans,
		function ( $x, $y ) {
			return $x[0] <=> $y[0];
		}
	);
	$o = array();
	foreach ( $spans as $s ) {
		$n = count( $o );
		if ( $n && $s[0] <= $o[ $n - 1 ][1] + 1 ) {
			$o[ $n - 1 ][1] = max( $o[ $n - 1 ][1], $s[1] );
		} else {
			$o[] = array( (int) $s[0], (int) $s[1] );
		}
	}
	while ( count( $o ) > RRT_DL_PIECES ) {
		$best = 1;
		for ( $i = 2; $i < count( $o ); $i++ ) {
			if ( $o[ $i ][0] - $o[ $i - 1 ][1] < $o[ $best ][0] - $o[ $best - 1 ][1] ) {
				$best = $i;
			}
		}
		$o[ $best - 1 ][1] = $o[ $best ][1];
		array_splice( $o, $best, 1 );
	}
	return $o;
}

/** How many bytes joined pieces cover. */
function rrt_dl_covered( $spans ) {
	$n = 0;
	foreach ( $spans as $s ) {
		$n += $s[1] - $s[0] + 1;
	}
	return $n;
}

/** Whether joined pieces hold a byte. */
function rrt_dl_holds( $spans, $byte ) {
	foreach ( $spans as $s ) {
		if ( $s[0] <= $byte && $byte <= $s[1] ) {
			return true;
		}
	}
	return false;
}

/** Pieces as stored ("0-99,500-999"), and back. */
function rrt_dl_spans_text( $spans ) {
	return implode(
		',',
		array_map(
			function ( $s ) {
				return $s[0] . '-' . $s[1];
			},
			$spans
		)
	);
}

/** Pieces from their stored text. */
function rrt_dl_spans_read( $text ) {
	$out = array();
	foreach ( explode( ',', (string) $text ) as $p ) {
		if ( preg_match( '/^(\d+)-(\d+)$/', $p, $m ) ) {
			$out[] = array( (int) $m[1], (int) $m[2] );
		}
	}
	return $out;
}

/**
 * The size of a file's ID3v2 tag (header, frames and footer), from its first
 * 10 bytes, or 0. Remembered with the file's size and time, so a new cover
 * or a new copy is measured again.
 */
function rrt_dl_tag_size( $path ) {
	if ( '' === (string) $path || ! is_file( $path ) ) {
		return 0;
	}
	clearstatcache( true, $path );
	$key = 'rrt_dltag_' . md5( $path . '|' . filesize( $path ) . '|' . filemtime( $path ) );
	$got = get_transient( $key );
	if ( false !== $got ) {
		return (int) $got;
	}
	$n  = 0;
	$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( $fh ) {
		$h = fread( $fh, 10 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( is_string( $h ) && 10 === strlen( $h ) && 'ID3' === substr( $h, 0, 3 ) ) {
			$b = array_values( unpack( 'C4', substr( $h, 6, 4 ) ) );
			if ( $b[0] < 128 && $b[1] < 128 && $b[2] < 128 && $b[3] < 128 ) {
				$n = 10 + ( ( $b[0] << 21 ) | ( $b[1] << 14 ) | ( $b[2] << 7 ) | $b[3] ) + ( ( ord( $h[5] ) & 0x10 ) ? 10 : 0 );
			}
		}
	}
	set_transient( $key, $n, 30 * DAY_IN_SECONDS );
	return $n;
}

/**
 * How many bytes must have been asked for: the tag plus one minute of the
 * audio, or the whole file when the reading is under a minute or its length
 * is not known.
 */
function rrt_dl_bar( $size, $tag, $secs ) {
	$size = (int) $size;
	$tag  = min( max( 0, (int) $tag ), $size );
	if ( $secs < 60 || $size <= 0 ) {
		return max( 1, $size );
	}
	return min( $size, $tag + (int) ceil( ( $size - $tag ) * 60 / $secs ) );
}

/*
 * ---------------------------------------------------------------------
 * Counting
 * ---------------------------------------------------------------------
 */

/** Today's random code for the codes, replaced every day (site time) and never kept. */
function rrt_dl_salt() {
	$today = wp_date( 'Y-m-d' );
	$s     = get_option( 'rrt_dl_salt' );
	if ( ! is_array( $s ) || empty( $s['day'] ) || $s['day'] !== $today || empty( $s['salt'] ) ) {
		$s = array(
			'day'  => $today,
			'salt' => wp_generate_password( 64, true, true ),
		);
		update_option( 'rrt_dl_salt', $s, false );
	}
	return $s['salt'];
}

/** The address as counting uses it: IPv4 whole, IPv6 its first 64 bits (IAB v2.3, 5.5.4). */
function rrt_dl_ip_key( $ip ) {
	$k = function_exists( 'rrt_net_key' ) ? rrt_net_key( $ip ) : '';
	return '' === $k ? (string) $ip : bin2hex( $k );
}

/**
 * Whether one address may have another counted download: at most
 * RRT_DL_HOURLY an hour and RRT_DL_DAILY a day, unless it is a shared network.
 * Takes one from both when it may.
 */
function rrt_dl_address_ok( $ip ) {
	if ( function_exists( 'rrt_net_is_shared' ) && rrt_net_is_shared( $ip ) ) {
		return true;
	}
	$salt = rrt_dl_salt();
	$ipk  = rrt_dl_ip_key( $ip );
	$hk   = 'rrt_dlip_' . substr( hash_hmac( 'sha256', $ipk . '|' . gmdate( 'YmdH' ), $salt ), 0, 24 );
	$dk   = 'rrt_dlid_' . substr( hash_hmac( 'sha256', $ipk . '|' . wp_date( 'Ymd' ), $salt ), 0, 24 );
	$h    = (int) get_transient( $hk );
	$d    = (int) get_transient( $dk );
	if ( $h >= RRT_DL_HOURLY || $d >= RRT_DL_DAILY ) {
		return false;
	}
	set_transient( $hk, $h + 1, HOUR_IN_SECONDS );
	set_transient( $dk, $d + 1, DAY_IN_SECONDS );
	return true;
}

/**
 * Takes one request for a file and counts a download when it completes one.
 *
 * @param array $req  method, range, ua, ip, cond (an If-None-Match or If-Modified-Since header came), member (a user id, or 0).
 * @param array $file post, key (the file's own name or id), bytes, secs, tag, feed ('public' or 'members'), parts (sponsor messages: slot, spot, end byte).
 * @return string What happened: counted, more (bytes added, not yet a download), repeat, head, cond, probe, bot, datacenter, busy, none.
 */
function rrt_dl_take( $req, $file ) {
	global $wpdb;
	$req  = array_merge( array( 'method' => 'GET', 'range' => '', 'ua' => '', 'ip' => '', 'cond' => false, 'member' => 0 ), $req );
	$file = array_merge( array( 'post' => 0, 'key' => '', 'bytes' => 0, 'secs' => 0, 'tag' => 0, 'feed' => 'public', 'parts' => array() ), $file );
	if ( 'GET' !== strtoupper( (string) $req['method'] ) ) {
		return 'head';
	}
	$info = rrt_ua_info( $req['ua'] );
	if ( ! $info['count'] ) {
		return 'bot';
	}
	$size   = (int) $file['bytes'];
	$ranges = rrt_dl_ranges( $req['range'], $size );
	if ( null === $ranges ) {
		if ( $req['cond'] ) {
			return 'cond';
		}
		$ranges = array( array( 0, max( 0, $size - 1 ) ) );
	}
	if ( ! $ranges ) {
		return 'none';
	}
	if ( 1 === count( $ranges ) && 0 === $ranges[0][0] && 1 === $ranges[0][1] && $size > 2 ) {
		return 'probe';
	}
	if ( ! $req['member'] && function_exists( 'rrt_net_is_datacenter' ) && rrt_net_is_datacenter( $req['ip'] ) && ! ( function_exists( 'rrt_net_is_shared' ) && rrt_net_is_shared( $req['ip'] ) ) ) {
		return 'datacenter';
	}
	$day  = wp_date( 'Y-m-d' );
	$salt = rrt_dl_salt();
	$who  = $req['member'] ? 'member|' . (int) $req['member'] : rrt_dl_ip_key( $req['ip'] ) . '|' . (string) $req['ua'];
	$h    = substr( hash_hmac( 'sha256', $who . '|' . (int) $file['post'] . '|' . (string) $file['key'], $salt ), 0, 32 );
	$ts   = rrt_table( 'dl_seen' );
	$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $ts (day, h) VALUES (%s, %s)", $day, $h ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$row   = $wpdb->get_row( $wpdb->prepare( "SELECT got, spans, done, ads FROM $ts WHERE day = %s AND h = %s", $day, $h ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$done  = $row ? (int) $row['done'] : 0;
	$ads   = $row ? (int) $row['ads'] : 0;
	$parts = (array) $file['parts'];
	$all   = ( 1 << count( $parts ) ) - 1;
	if ( 2 === $done || ( 1 === $done && ( $ads & $all ) === $all ) ) {
		return 'repeat';
	}
	$spans = rrt_dl_join( array_merge( rrt_dl_spans_read( $row ? $row['spans'] : '' ), $ranges ) );
	$got   = rrt_dl_covered( $spans );
	$out   = 'more';
	if ( 0 === $done && $got >= rrt_dl_bar( $size, $file['tag'], (float) $file['secs'] ) ) {
		if ( ! $req['member'] && ! rrt_dl_address_ok( $req['ip'] ) ) {
			/* Past the address's limits: this consumer's file is settled for the day. */
			$wpdb->update( $ts, array( 'done' => 2, 'spans' => '', 'got' => $got ), array( 'day' => $day, 'h' => $h ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return 'busy';
		}
		/* Only the request that turns 0 into 1 counts, so two requests at once count once. */
		$won = $wpdb->query( $wpdb->prepare( "UPDATE $ts SET done = 1 WHERE day = %s AND h = %s AND done = 0", $day, $h ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		if ( $won ) {
			$done = 1;
			$out  = 'counted';
			rrt_dl_add( $day, (int) $file['post'], $info, 'members' === $file['feed'] ? 'members' : 'public' );
			rrt_dl_consumer( $day, $who, 'members' === $file['feed'] );
		} else {
			$done = 1;
			$out  = 'repeat';
		}
	} elseif ( 1 === $done ) {
		$out = 'repeat';
	}
	/* Sponsor messages delivered: a pre-roll with the download, the others once their last byte was asked for. */
	if ( 1 === $done && $parts ) {
		foreach ( array_values( $parts ) as $i => $p ) {
			$bit = 1 << $i;
			if ( $ads & $bit ) {
				continue;
			}
			$end = isset( $p['end'] ) ? (int) $p['end'] : $size - 1;
			if ( 'pre' === $p['slot'] || rrt_dl_holds( $spans, min( $end, $size - 1 ) ) ) {
				$ads |= $bit;
				rrt_dl_ad( $day, (int) $p['spot'], (int) $file['post'], (string) $p['slot'] );
			}
		}
	}
	$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ts,
		array(
			'got'   => $got,
			'spans' => substr( rrt_dl_spans_text( $spans ), 0, 800 ),
			'ads'   => $ads,
		),
		array(
			'day' => $day,
			'h'   => $h,
		)
	);
	return $out;
}

/** Adds one download to the day's counts. */
function rrt_dl_add( $day, $post_id, $info, $feed ) {
	global $wpdb;
	$t   = rrt_table( 'dl_counts' );
	$app = mb_substr( '' === $info['name'] ? 'Other' : (string) $info['name'], 0, 60 );
	$dev = substr( (string) $info['device'], 0, 16 );
	$wpdb->query( $wpdb->prepare( "INSERT INTO $t (day, post_id, app, device, feed, downloads) VALUES (%s, %d, %s, %s, %s, 1) ON DUPLICATE KEY UPDATE downloads = downloads + 1", $day, $post_id, $app, $dev, $feed ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
}

/** Notes a podcast consumer for the day, once (a code of its own in the day's list, done = 9). */
function rrt_dl_consumer( $day, $who, $member ) {
	global $wpdb;
	$ts  = rrt_table( 'dl_seen' );
	$h   = substr( hash_hmac( 'sha256', 'consumer|' . $who, rrt_dl_salt() ), 0, 32 );
	$new = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $ts (day, h, done) VALUES (%s, %s, 9)", $day, $h ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	if ( ! $new ) {
		return;
	}
	$t = rrt_table( 'dl_days' );
	$wpdb->query( $wpdb->prepare( "INSERT INTO $t (day, consumers, members) VALUES (%s, 1, %d) ON DUPLICATE KEY UPDATE consumers = consumers + 1, members = members + %d", $day, $member ? 1 : 0, $member ? 1 : 0 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
}

/** Adds one delivered sponsor message. */
function rrt_dl_ad( $day, $spot, $post_id, $slot ) {
	global $wpdb;
	if ( ! $spot ) {
		return;
	}
	$t = rrt_table( 'dl_ads' );
	$wpdb->query( $wpdb->prepare( "INSERT INTO $t (day, spot_id, post_id, slot, delivered) VALUES (%s, %d, %d, %s, 1) ON DUPLICATE KEY UPDATE delivered = delivered + 1", $day, $spot, $post_id, substr( $slot, 0, 8 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
}

/**
 * 1.2.0's call, kept for anything that still uses it: one whole download.
 *
 * @return bool Whether it was counted.
 */
function rrt_dl_record( $post_id, $ip, $ua ) {
	return 'counted' === rrt_dl_take(
		array(
			'ip' => $ip,
			'ua' => $ua,
		),
		array(
			'post'  => $post_id,
			'key'   => 'reading',
			'bytes' => 1,
			'secs'  => 0,
		)
	);
}

/*
 * ---------------------------------------------------------------------
 * Sending the file
 * ---------------------------------------------------------------------
 */

/**
 * The reading behind a short address: its audio address, size, length, the
 * file on this site (or ''), its tag size and its sponsor messages, or null.
 *
 * @param int    $post_id The post.
 * @param string $name    The file name in the address: a sponsored copy's name (1.22.0) gives that copy.
 */
function rrt_dl_target( $post_id, $name = '' ) {
	$post = get_post( $post_id );
	if ( ! $post || 'publish' !== $post->post_status || '' !== $post->post_password ) {
		return null;
	}
	$t = null;
	if ( '' !== $name && function_exists( 'rrt_spot_dl_target' ) ) {
		$t = rrt_spot_dl_target( $post_id, $name );
	}
	if ( ! $t && function_exists( 'rrt_podcast_episode' ) ) {
		$e = rrt_podcast_episode( $post );
		$t = $e ? array( 'url' => $e['url'], 'bytes' => (int) $e['bytes'], 'secs' => (int) $e['secs'] ) : null;
	}
	if ( ! $t ) {
		return null;
	}
	$t          = array_merge( array( 'parts' => array() ), $t );
	$t['path']  = rrt_dl_local_path( $t['url'] );
	/* 1.25.0: an address into uploads that the rule refuses (a private or closed folder, or not a listening file) is never sent or redirected to. */
	if ( '' === $t['path'] && rrt_dl_refused( $t['url'] ) ) {
		return null;
	}
	$t['tag']   = '' !== $t['path'] ? rrt_dl_tag_size( $t['path'] ) : 0;
	if ( '' !== $t['path'] ) {
		clearstatcache( true, $t['path'] );
		$t['bytes'] = (int) filesize( $t['path'] );
	}
	/* Where each message ends in the file, by its time (the copies are constant bitrate). */
	$parts = array();
	foreach ( (array) $t['parts'] as $p ) {
		$secs = (float) $t['secs'];
		$end  = $secs > 0 ? (int) floor( $t['tag'] + ( $t['bytes'] - $t['tag'] ) * min( 1, (float) $p['end'] / $secs ) ) - 1 : $t['bytes'] - 1;
		$parts[] = array(
			'slot' => (string) $p['slot'],
			'spot' => (int) $p['spot'],
			'end'  => max( 0, min( $end, $t['bytes'] - 1 ) ),
		);
	}
	$t['parts'] = $parts;
	return $t;
}

/** The file on this site behind an address in the uploads folder, or ''. */
function rrt_dl_local_path( $url ) {
	$up   = wp_upload_dir( null, false );
	$base = preg_replace( '#^https?:#', '', (string) $up['baseurl'] );
	$u    = preg_replace( '#^https?:#', '', (string) $url );
	if ( 0 === strpos( $u, '/' ) && 0 !== strpos( $u, '//' ) ) {
		$u = preg_replace( '#^https?:#', '', home_url( $u ) );
	}
	if ( '' === $base || 0 !== strpos( $u, $base . '/' ) ) {
		return '';
	}
	$rel = rawurldecode( (string) strtok( substr( $u, strlen( $base ) + 1 ), '?' ) );
	if ( '' === $rel || false !== strpos( $rel, '..' ) ) {
		return '';
	}
	$path = trailingslashit( $up['basedir'] ) . $rel;
	/* 1.25.0: only a listening file outside the private and closed folders; the hand-off would ignore a folder's .htaccess. */
	return rrt_upload_path_ok( $path, 'audio' ) ? $path : '';
}

/**
 * Whether an address into this site's uploads folder is one the rule refuses
 * (1.25.0): a file there that is not a listening file or sits in a closed
 * folder, or anything in a private folder. An address whose file is not on
 * this server (uploads served from elsewhere) is left to redirect, as before.
 */
function rrt_dl_refused( $url ) {
	$up   = wp_upload_dir( null, false );
	$base = preg_replace( '#^https?:#', '', (string) $up['baseurl'] );
	$u    = preg_replace( '#^https?:#', '', (string) $url );
	if ( 0 === strpos( $u, '/' ) && 0 !== strpos( $u, '//' ) ) {
		$u = preg_replace( '#^https?:#', '', home_url( $u ) );
	}
	if ( '' === $base || 0 !== strpos( $u, $base . '/' ) ) {
		return false;
	}
	$rel = rawurldecode( (string) strtok( substr( $u, strlen( $base ) + 1 ), '?' ) );
	if ( false !== strpos( $rel, '..' ) || in_array( strtok( $rel, '/' ), rrt_private_folders(), true ) ) {
		return true;
	}
	return file_exists( trailingslashit( $up['basedir'] ) . $rel );
}

/** Which web server this is, for the hand-off: litespeed, apache (with X-Sendfile), or ''. */
function rrt_dl_server() {
	$sw = isset( $_SERVER['SERVER_SOFTWARE'] ) ? (string) $_SERVER['SERVER_SOFTWARE'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	if ( false !== stripos( $sw, 'litespeed' ) ) {
		return 'litespeed';
	}
	if ( function_exists( 'apache_get_modules' ) && in_array( 'mod_xsendfile', (array) apache_get_modules(), true ) ) {
		return 'apache';
	}
	return '';
}

/** The hand-off the settings ask for on this server: litespeed, apache, nginx, or '' (redirect). */
function rrt_dl_how() {
	$s = rrt_dl_settings();
	if ( 'redirect' === $s['send'] ) {
		return '';
	}
	if ( 'nginx' === $s['send'] || 'apache' === $s['send'] ) {
		return $s['send'];
	}
	return rrt_dl_server();
}

/** A short fingerprint of how files would be sent, so a check stays valid only for the same setup. */
function rrt_dl_sig( $how ) {
	return substr( md5( $how . '|' . rrt_dl_server() . '|' . (string) get_option( 'home' ) ), 0, 12 );
}

/** The hand-off in use now: one that passed Check counting for this setup, or '' (redirect). */
function rrt_dl_handoff() {
	$how = rrt_dl_how();
	if ( '' === $how ) {
		return '';
	}
	$c = rrt_dl_settings()['check'];
	return ( ! empty( $c['ok'] ) && isset( $c['how'], $c['sig'] ) && $c['how'] === $how && $c['sig'] === rrt_dl_sig( $how ) ) ? $how : '';
}

/** The hand-off variant that passed Check counting ('' for the usual one). */
function rrt_dl_variant() {
	$c = rrt_dl_settings()['check'];
	return isset( $c['variant'] ) ? (string) $c['variant'] : '';
}

/** The variants to try on a server, in order. */
function rrt_dl_variants( $how ) {
	return 'litespeed' === $how ? array( '', 'b', 'c' ) : array( '' );
}

/** The internal address nginx is set up to serve the uploads folder from. */
function rrt_dl_nginx_base() {
	return (string) apply_filters( 'rrt_dl_nginx_base', '/foxfire-internal/' );
}

/** The two lines of nginx setup the nginx choice needs. */
function rrt_dl_nginx_lines() {
	$up = wp_upload_dir( null, false );
	return 'location ' . rrt_dl_nginx_base() . " {\n\tinternal;\n\talias " . trailingslashit( $up['basedir'] ) . ";\n}";
}

/**
 * Hands a file on this site to the web server. The response is the file,
 * with byte ranges, sent by the server itself.
 *
 * @param string $path The file.
 * @param string $how  litespeed, apache or nginx.
 */
function rrt_dl_send_handoff( $path, $how, $variant = '' ) {
	$up  = wp_upload_dir( null, false );
	$rel = ltrim( substr( $path, strlen( trailingslashit( $up['basedir'] ) ) ), '/' );
	if ( 'litespeed' === $how ) {
		$at = str_replace( '%2F', '/', rawurlencode( (string) wp_parse_url( trailingslashit( $up['baseurl'] ) . $rel, PHP_URL_PATH ) ) );
		/*
		 * Found live: with WordPress's no-cache headers and a 200 status set by
		 * PHP, LiteSpeed answered every closed byte range (bytes=0-1, and even
		 * the whole file given with an end) with 412. Check counting tries the
		 * ways LiteSpeed documents and keeps the first that answers a closed
		 * range: b sends only the hand-off header, c a plain Location header
		 * with status 200 (its internal redirect).
		 */
		if ( 'b' === $variant || 'c' === $variant ) {
			if ( function_exists( 'header_remove' ) ) {
				header_remove();
			}
			if ( 'c' === $variant ) {
				header( 'Location: ' . $at, true, 200 );
			} else {
				header( 'X-LiteSpeed-Location: ' . $at );
			}
			return;
		}
		status_header( 200 );
		header( 'Content-Type: ' . rrt_dl_type( $path ) );
		header( 'X-LiteSpeed-Location: ' . $at );
		return;
	}
	status_header( 200 );
	header( 'Content-Type: ' . rrt_dl_type( $path ) );
	if ( 'apache' === $how ) {
		header( 'X-Sendfile: ' . $path );
	} else {
		header( 'X-Accel-Redirect: ' . rrt_dl_nginx_base() . str_replace( '%2F', '/', rawurlencode( $rel ) ) );
	}
}

/** A listening file's type, by its extension. */
function rrt_dl_type( $path ) {
	$types = array(
		'mp3'  => 'audio/mpeg',
		'm4a'  => 'audio/mp4',
		'mp4'  => 'video/mp4',
		'm4v'  => 'video/mp4',
		'ogg'  => 'audio/ogg',
		'opus' => 'audio/ogg',
		'wav'  => 'audio/wav',
	);
	$ext = strtolower( pathinfo( (string) $path, PATHINFO_EXTENSION ) );
	return isset( $types[ $ext ] ) ? $types[ $ext ] : 'application/octet-stream';
}

/**
 * The Range header as this request carried it: the header itself, or, where
 * the web server keeps it from PHP (LiteSpeed does, so its cache can answer
 * ranges itself), the copy the .htaccess lines below hand on as RRT_RANGE
 * (REDIRECT_RRT_RANGE after WordPress's own rewrite).
 *
 * @return array range, from ('header', 'htaccess' or '').
 */
function rrt_dl_range_header() {
	$ran = '';
	foreach ( array( 'RRT_SEEN', 'REDIRECT_RRT_SEEN', 'REDIRECT_REDIRECT_RRT_SEEN' ) as $k ) {
		if ( ! empty( $_SERVER[ $k ] ) ) {
			$ran = '1';
		}
	}
	foreach ( array( 'HTTP_RANGE' => 'header', 'RRT_RANGE' => 'htaccess', 'REDIRECT_RRT_RANGE' => 'htaccess', 'REDIRECT_REDIRECT_RRT_RANGE' => 'htaccess' ) as $k => $from ) {
		$v = isset( $_SERVER[ $k ] ) ? trim( (string) wp_unslash( $_SERVER[ $k ] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' !== $v ) {
			return array(
				'range' => substr( $v, 0, 400 ),
				'from'  => $from,
				'rule'  => $ran,
			);
		}
	}
	return array(
		'range' => '',
		'from'  => '',
		'rule'  => $ran,
	);
}

/*
 * ---------------------------------------------------------------------
 * The .htaccess lines (1.24.0, second build)
 * ---------------------------------------------------------------------
 *
 * Found live on LiteSpeed: PHP never sees a request's Range header there,
 * so a two-byte probe looked like a whole download. These marked lines,
 * at the top of the site's .htaccess and only for the short addresses, hand
 * the header on as an environment variable. Check counting adds them when
 * it finds the header missing (while counting is on); switching counting
 * off, deactivating or uninstalling takes them out.
 */

/** The marker around the lines. */
define( 'RRT_DL_HT_MARK', 'Foxfire Studio download counting' );

/** The site's .htaccess. */
function rrt_dl_htaccess_file() {
	if ( ! function_exists( 'get_home_path' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	return (string) apply_filters( 'rrt_dl_htaccess_file', get_home_path() . '.htaccess' );
}

/** The lines, with their markers. */
function rrt_dl_htaccess_lines() {
	return array(
		'# BEGIN ' . RRT_DL_HT_MARK,
		'# Hands each podcast download\'s Range header to PHP, so a two-byte test request is not counted as a download.',
		'<IfModule mod_rewrite.c>',
		'RewriteEngine On',
		'RewriteRule ^' . RRT_DL_BASE . '/ - [E=Cache-Control:no-cache,E=RRT_SEEN:1]',
		'RewriteCond %{HTTP:Range} .',
		'RewriteRule ^' . RRT_DL_BASE . '/ - [E=RRT_RANGE:%{HTTP:Range}]',
		'</IfModule>',
		'# Asks any CDN in front of the site to keep no copy of the counting addresses, so every download reaches the counter.',
		'<IfModule mod_setenvif.c>',
		'SetEnvIf Request_URI "/' . RRT_DL_BASE . '/" RRT_DL_NOSTORE',
		'</IfModule>',
		'<IfModule mod_headers.c>',
		'Header always set Cache-Control "private, no-store, max-age=0" env=RRT_DL_NOSTORE',
		'Header always set Cache-Control "private, no-store, max-age=0" env=REDIRECT_RRT_DL_NOSTORE',
		'</IfModule>',
		'# END ' . RRT_DL_HT_MARK,
	);
}

/** The file's text without the lines. */
function rrt_dl_htaccess_strip( $text ) {
	$q = preg_quote( RRT_DL_HT_MARK, '/' );
	return (string) preg_replace( '/^# BEGIN ' . $q . '\R.*?^# END ' . $q . '\R?/sm', '', (string) $text );
}

/** Whether the lines in the file are this version's (an older build's are replaced). */
function rrt_dl_htaccess_current() {
	$f = rrt_dl_htaccess_file();
	return is_readable( $f ) && false !== strpos( (string) file_get_contents( $f ), implode( "\n", rrt_dl_htaccess_lines() ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
}

/** Whether the lines are in the file. */
function rrt_dl_htaccess_has() {
	$f = rrt_dl_htaccess_file();
	return is_readable( $f ) && false !== strpos( (string) file_get_contents( $f ), '# BEGIN ' . RRT_DL_HT_MARK ); // phpcs:ignore WordPress.WP.AlternativeFunctions
}

/**
 * Puts the lines at the top of the file (before WordPress's own rules, which
 * end the rewriting), once.
 *
 * @return true|WP_Error
 */
function rrt_dl_htaccess_add() {
	$f    = rrt_dl_htaccess_file();
	$text = is_readable( $f ) ? (string) file_get_contents( $f ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
	$new  = implode( "\n", rrt_dl_htaccess_lines() ) . "\n\n" . ltrim( rrt_dl_htaccess_strip( $text ) );
	if ( ( file_exists( $f ) && ! is_writable( $f ) ) || ( ! file_exists( $f ) && ! is_writable( dirname( $f ) ) ) ) {
		return new WP_Error( 'rrt_dl_ht', __( 'The site\'s .htaccess file cannot be written by WordPress.', 'foxfire-studio' ) );
	}
	if ( false === file_put_contents( $f, $new ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
		return new WP_Error( 'rrt_dl_ht', __( 'The site\'s .htaccess file could not be saved.', 'foxfire-studio' ) );
	}
	return true;
}

/** Takes the lines out again, leaving the rest of the file as it was. */
function rrt_dl_htaccess_remove() {
	$f = rrt_dl_htaccess_file();
	if ( ! is_readable( $f ) || ! is_writable( $f ) ) {
		return;
	}
	$text = (string) file_get_contents( $f ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( false === strpos( $text, '# BEGIN ' . RRT_DL_HT_MARK ) ) {
		return;
	}
	file_put_contents( $f, ltrim( rrt_dl_htaccess_strip( $text ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
}
register_deactivation_hook( RRT_FILE, 'rrt_dl_htaccess_remove' );

/* Counting switched off: the lines go (1.24.0). */
add_action(
	'update_option_rrt_podcast',
	function ( $old, $new ) {
		if ( ! empty( $old['count'] ) && empty( $new['count'] ) ) {
			rrt_dl_htaccess_remove();
		}
	},
	10,
	2
);

/** The request as counting reads it. */
function rrt_dl_request() {
	$g = function ( $k ) {
		return isset( $_SERVER[ $k ] ) ? (string) wp_unslash( $_SERVER[ $k ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	};
	$method = $g( 'REQUEST_METHOD' );
	return array(
		'method' => '' === $method ? 'GET' : strtoupper( $method ),
		'range'  => rrt_dl_range_header()['range'],
		'ua'     => substr( $g( 'HTTP_USER_AGENT' ), 0, 600 ),
		'ip'     => function_exists( 'rrt_listen_ip' ) ? rrt_listen_ip() : '',
		'cond'   => '' !== $g( 'HTTP_IF_NONE_MATCH' ) || '' !== $g( 'HTTP_IF_MODIFIED_SINCE' ),
	);
}

/** Answers a short address: counts the request when counting is on, then sends the audio. */
function rrt_dl_serve() {
	$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
	$id   = rrt_dl_match( $path, (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
	if ( ! $id ) {
		return;
	}
	/* Never cached: every request has to reach this code to be counted. */
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	do_action( 'litespeed_control_set_nocache', 'podcast download' );
	nocache_headers();
	header( 'X-Robots-Tag: noindex' );
	$name = preg_replace( '/[^A-Za-z0-9._-]/', '', basename( rawurldecode( $path ) ) );
	if ( -1 === $id ) {
		rrt_dl_check_answer( $name );
		exit;
	}
	$t = rrt_dl_target( $id, $name );
	if ( ! $t || '' === $t['url'] ) {
		status_header( 404 );
		exit;
	}
	$to = $t['url'];
	if ( 0 === strpos( $to, '/' ) && 0 !== strpos( $to, '//' ) ) {
		$to = home_url( $to );
	}
	/* Storage offload (1.23.0): on to the outside copy when there is a checked one. */
	$out = function_exists( 'rrt_off_url' ) ? rrt_off_url( $to ) : $to;
	/* 1.25.0: the hand-off only while counting is on; otherwise the plain redirect, as before 1.24.0. */
	$how = ( rrt_dl_on() && '' !== $t['path'] && $out === $to ) ? rrt_dl_handoff() : '';
	if ( rrt_dl_on() ) {
		rrt_dl_take(
			rrt_dl_request(),
			array(
				'post'  => $id,
				'key'   => $name,
				'bytes' => $t['bytes'],
				'secs'  => $t['secs'],
				'tag'   => $t['tag'],
				'feed'  => 'public',
				'parts' => $t['parts'],
			)
		);
	}
	if ( '' !== $how ) {
		rrt_dl_send_handoff( $t['path'], $how, rrt_dl_variant() );
		exit;
	}
	/* wp_redirect, not wp_safe_redirect: a reading may live on another host (a media server or storage service). */
	wp_redirect( $out, 302, 'Foxfire Studio' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
	exit;
}
add_action( 'init', 'rrt_dl_serve', 1 );

/*
 * ---------------------------------------------------------------------
 * Members' feeds (1.21.0's files, counted in 1.24.0)
 * ---------------------------------------------------------------------
 */

/**
 * Counts a request for a member's file, with the member as the consumer.
 * Only totals are kept: never which member.
 *
 * @param int    $post_id The reading.
 * @param int    $att     The file's attachment.
 * @param int    $uid     The member.
 * @param string $path    The file.
 */
function rrt_dl_take_member( $post_id, $att, $uid, $path ) {
	if ( ! rrt_dl_on() || ! $uid || ! is_file( $path ) ) {
		return '';
	}
	$meta = wp_get_attachment_metadata( $att );
	$secs = is_array( $meta ) && ! empty( $meta['length'] ) ? (float) $meta['length'] : 0;
	if ( ! $secs && function_exists( 'rrt_podcast_episode' ) ) {
		$e    = rrt_podcast_episode( get_post( $post_id ) );
		$secs = $e ? (float) $e['secs'] : 0;
	}
	clearstatcache( true, $path );
	$req           = rrt_dl_request();
	$req['member'] = (int) $uid;
	return rrt_dl_take(
		$req,
		array(
			'post'  => (int) $post_id,
			'key'   => 'att' . (int) $att,
			'bytes' => (int) filesize( $path ),
			'secs'  => $secs,
			'tag'   => rrt_dl_tag_size( $path ),
			'feed'  => 'members',
		)
	);
}

/*
 * ---------------------------------------------------------------------
 * Check counting
 * ---------------------------------------------------------------------
 */

/** The check's folder and its test file's bytes. */
function rrt_dl_check_dir() {
	$up = wp_upload_dir( null, false );
	return trailingslashit( $up['basedir'] ) . 'rrt-dl-check';
}

/**
 * Runs Check counting: asks this site's own short address for a test file
 * with a byte range, the way an app would, and checks the answer.
 *
 * @return array ok, how, why (when not ok), steps (each: name, ok, note).
 */
function rrt_dl_check_run() {
	$how   = rrt_dl_how();
	$steps = array();
	$res   = array(
		'at'  => time(),
		'ok'  => false,
		'how' => $how,
		'sig' => rrt_dl_sig( $how ),
		'why' => '',
	);
	if ( '' === $how ) {
		$res['why'] = 'redirect' === rrt_dl_settings()['send']
			? __( 'Files are sent with a redirect, as chosen.', 'foxfire-studio' )
			: __( 'This web server has no hand-off Foxfire Studio knows, so files are sent with a redirect. On nginx, choose nginx and add the setup shown.', 'foxfire-studio' );
		$res['steps'] = $steps;
		rrt_dl_check_save( $res );
		return $res;
	}
	$first = rrt_dl_check_once( $how );
	$steps = $first['steps'];
	$fix   = '';
	/* Delivery works but PHP cannot see the Range header (found live on LiteSpeed): add the .htaccess lines and look again. */
	if ( $first['delivered'] && ( ! $first['ranges'] || ! $first['fresh'] ) && in_array( $how, array( 'litespeed', 'apache' ), true ) ) {
		if ( ! rrt_dl_on() ) {
			$fix = __( 'Switch Count downloads on (above) and check again: the check then adds the lines that hand the Range header to counting.', 'foxfire-studio' );
		} elseif ( ! rrt_dl_htaccess_current() ) {
			$add = rrt_dl_htaccess_add();
			if ( is_wp_error( $add ) ) {
				$fix = $add->get_error_message() . ' ' . __( 'Add the lines shown below at the top of it yourself, then check again.', 'foxfire-studio' );
			} else {
				for ( $i = 0; $i < 3; $i++ ) {
					sleep( 2 );
					$again = rrt_dl_check_once( $how );
					if ( $again['ranges'] && $again['fresh'] ) {
						break;
					}
				}
				$steps = $again['steps'];
				$first = $again;
				$fix   = ( $again['ranges'] && $again['fresh'] )
					? __( 'Foxfire Studio\'s lines were added to the site\'s .htaccess file, and every step now passes.', 'foxfire-studio' )
					: rrt_dl_check_advice( $again );
			}
		} else {
			$fix = rrt_dl_check_advice( $first );
		}
	}
	return rrt_dl_check_finish( $res, $first, $fix );
}

/** Turns a round into the check's result, and keeps it. */
function rrt_dl_check_finish( $res, $round, $fix ) {
	$res['ok']     = $round['delivered'];
	$res['ranges'] = $round['ranges'];
	$res['fresh']  = $round['fresh'];
	$res['cdn']    = $round['cdn'];
	$res['fix']    = $fix;
	$res['variant'] = isset( $round['variant'] ) ? $round['variant'] : '';
	if ( ! $res['ok'] ) {
		$res['why'] = __( 'The hand-off did not work here, so files are sent with a redirect.', 'foxfire-studio' );
	} elseif ( ! $res['fresh'] ) {
		$res['why'] = __( 'Repeat requests are answered by a copy in front of the site, so most downloads would never reach counting.', 'foxfire-studio' );
	} elseif ( ! $res['ranges'] ) {
		$res['why'] = __( 'Files are sent by the hand-off, but counting cannot tell which part of a file an app asked for, so every request counts as a whole download.', 'foxfire-studio' );
	}
	$res['steps'] = $round['steps'];
	rrt_dl_check_save( $res );
	return $res;
}

/**
 * One round of the check: asks the site's own short address for a test file
 * with a byte range.
 *
 * @return array steps, delivered (the hand-off works), ranges (counting saw the Range header).
 */
function rrt_dl_check_once( $how ) {
	foreach ( rrt_dl_variants( $how ) as $v ) {
		$round = rrt_dl_check_once_with( $how, $v );
		if ( $round['delivered'] ) {
			break;
		}
	}
	return $round;
}

/** One round with one hand-off variant. */
function rrt_dl_check_once_with( $how, $variant ) {
	$prep  = rrt_dl_check_prepare( $how, $variant );
	$resps = array();
	for ( $i = 0; $i < 2; $i++ ) {
		/* The second request is the next app's: it must reach counting too (a CDN in front may answer it from a copy). */
		$r       = wp_remote_get( $prep['url'], $prep['args'] );
		$resps[] = is_wp_error( $r )
			? array( 'error' => $r->get_error_message() )
			: array(
				'code' => (int) wp_remote_retrieve_response_code( $r ),
				'body' => (string) wp_remote_retrieve_body( $r ),
				'type' => (string) wp_remote_retrieve_header( $r, 'content-type' ),
				'cdn'  => rrt_dl_cdn_name( $r ),
			);
	}
	return rrt_dl_check_judge( $prep, $resps );
}

/** Makes a round's test file and its one-time address. */
function rrt_dl_check_prepare( $how, $variant = '' ) {
	$dir = rrt_dl_check_dir();
	wp_mkdir_p( $dir );
	foreach ( array_merge( (array) glob( $dir . '/check-*.mp3' ), (array) glob( $dir . '/seen-*' ) ) as $old ) {
		if ( time() - (int) @filemtime( $old ) > 60 ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			wp_delete_file( $old );
		}
	}
	$token = strtolower( wp_generate_password( 12, false, false ) );
	$body  = 'ID3' . "\x03\x00\x00\x00\x00\x00\x00" . str_repeat( 'Foxfire check ', 400 );
	$file  = $dir . '/check-' . $token . '.mp3';
	file_put_contents( $file, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	set_transient( 'rrt_dl_check_' . $token, array( 'file' => $file, 'how' => $how, 'variant' => $variant ), 5 * MINUTE_IN_SECONDS );
	return array(
		'how'     => $how,
		'variant' => $variant,
		'token' => $token,
		'file'  => $file,
		'seen'  => $dir . '/seen-' . $token,
		'body'  => $body,
		'url'   => home_url( '/' . RRT_DL_BASE . '/0/' . $token . '.mp3' ),
		'args'  => apply_filters(
			'rrt_dl_check_args',
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'headers'     => array( 'Range' => 'bytes=10-29' ),
				'user-agent'  => 'FoxfireStudioCheck/' . RRT_VERSION,
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
			)
		),
	);
}

/**
 * Judges a round from its two answers (made by the site itself, or by the
 * owner's browser: a host's firewall can turn away a site's requests to
 * itself, found live with a 412), and tidies up.
 *
 * @param array   $prep  From rrt_dl_check_prepare().
 * @param array[] $resps Each: code, body, type, cdn (or error).
 * @return array steps, delivered, ranges, fresh, cdn.
 */
function rrt_dl_check_judge( $prep, $resps ) {
	$r     = isset( $resps[0] ) ? (array) $resps[0] : array( 'error' => 'no answer' );
	$r2    = isset( $resps[1] ) ? (array) $resps[1] : array();
	$err   = isset( $r['error'] ) ? (string) $r['error'] : '';
	$code  = '' === $err && isset( $r['code'] ) ? (int) $r['code'] : 0;
	$got   = isset( $r['body'] ) ? (string) $r['body'] : '';
	$type  = isset( $r['type'] ) ? (string) $r['type'] : '';
	$cdn   = isset( $r2['cdn'] ) && '' !== $r2['cdn'] ? (string) $r2['cdn'] : ( isset( $r['cdn'] ) ? (string) $r['cdn'] : '' );
	$seen  = $prep['seen'];
	$body  = $prep['body'];
	clearstatcache( true, $seen );
	$saw   = is_file( $seen );
	$lines = $saw ? array_values( array_filter( explode( "\n", (string) file_get_contents( $seen ) ) ) ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$rh    = $lines ? json_decode( $lines[0], true ) : null;
	$hits  = count( $lines );
	$rng   = is_array( $rh ) && isset( $rh['range'] ) && 'bytes=10-29' === str_replace( ' ', '', (string) $rh['range'] );
	$steps = array();
	$steps[] = array(
		'name' => __( 'The site answers its own short address', 'foxfire-studio' ),
		'ok'   => '' === $err && $code > 0,
		'note' => '' !== $err ? $err : 'HTTP ' . $code,
	);
	$steps[] = array(
		'name' => __( 'Counting saw the request', 'foxfire-studio' ),
		'ok'   => $saw,
		'note' => '',
	);
	$steps[] = array(
		'name' => __( 'The server sent the file itself, without a redirect', 'foxfire-studio' ),
		'ok'   => 206 === $code || 200 === $code,
		'note' => ( $code >= 300 && $code < 400 ) ? __( 'It answered with a redirect.', 'foxfire-studio' ) : '',
	);
	$steps[] = array(
		'name' => __( 'Only the bytes asked for came back (a byte range)', 'foxfire-studio' ),
		'ok'   => 206 === $code && substr( $body, 10, 20 ) === $got,
		'note' => 206 === $code ? '' : __( 'Podcast apps need byte ranges to play from the middle.', 'foxfire-studio' ),
	);
	$steps[] = array(
		'name' => __( 'The type is audio/mpeg', 'foxfire-studio' ),
		'ok'   => 0 === stripos( $type, 'audio/mpeg' ),
		'note' => $type,
	);
	$delivered = ! in_array(
		false,
		array_map(
			function ( $s ) {
				return $s['ok'];
			},
			$steps
		),
		true
	);
	$steps[] = array(
		'name' => __( 'A second request reached counting too (no copy kept in front of the site)', 'foxfire-studio' ),
		'ok'   => $hits >= 2,
		'note' => $hits >= 2 ? '' : ( '' !== $cdn ? sprintf( /* translators: %s: a CDN's name. */ __( '%s answered it from its own copy, so later downloads would not be counted.', 'foxfire-studio' ), $cdn ) : __( 'Something in front of the site answered it from a copy, so later downloads would not be counted.', 'foxfire-studio' ) ),
	);
	$steps[] = array(
		'name' => __( 'Counting saw which bytes were asked for', 'foxfire-studio' ),
		'ok'   => $rng,
		'note' => $rng ? ( 'htaccess' === $rh['from'] ? __( 'through the .htaccess lines', 'foxfire-studio' ) : '' ) : ( __( 'The web server kept the Range header from counting.', 'foxfire-studio' ) . ( is_array( $rh ) && ! empty( $rh['rule'] ) ? ' ' . __( 'The .htaccess lines ran, but the header had already gone.', 'foxfire-studio' ) : ( rrt_dl_htaccess_has() ? ' ' . __( 'The .htaccess lines did not reach counting.', 'foxfire-studio' ) : '' ) ) ),
	);
	wp_delete_file( $prep['file'] );
	wp_delete_file( $seen );
	delete_transient( 'rrt_dl_check_' . $prep['token'] );
	return array(
		'steps'     => $steps,
		'delivered' => $delivered,
		'ranges'    => $rng,
		'fresh'     => $hits >= 2,
		'variant'   => isset( $prep['variant'] ) ? (string) $prep['variant'] : '',
		'cdn'       => $cdn,
	);
}

/** The CDN in front of the site, from a response's headers, or ''. */
function rrt_dl_cdn_name( $r ) {
	if ( is_wp_error( $r ) ) {
		return '';
	}
	$h = function ( $k ) use ( $r ) {
		return (string) wp_remote_retrieve_header( $r, $k );
	};
	if ( '' !== $h( 'x-hcdn-cache-status' ) || 'hcdn' === strtolower( $h( 'server' ) ) ) {
		return __( 'Hostinger\'s CDN', 'foxfire-studio' );
	}
	if ( '' !== $h( 'cf-cache-status' ) ) {
		return 'Cloudflare';
	}
	if ( '' !== $h( 'x-qc-cache' ) || false !== stripos( $h( 'server' ), 'quic.cloud' ) ) {
		return 'QUIC.cloud';
	}
	if ( '' !== $h( 'x-cache' ) || '' !== $h( 'x-served-by' ) ) {
		return __( 'A CDN', 'foxfire-studio' );
	}
	return '';
}

/** The browser's check, part one: the test file and its address, or the result at once when files go by redirect. */
function rrt_dl_check_start( $variant = '' ) {
	$how = rrt_dl_how();
	if ( '' === $how ) {
		return array( 'done' => true, 'result' => rrt_dl_check_run() );
	}
	$variant = in_array( $variant, rrt_dl_variants( $how ), true ) ? $variant : '';
	$prep    = rrt_dl_check_prepare( $how, $variant );
	set_transient( 'rrt_dl_checkp_' . $prep['token'], array_diff_key( $prep, array( 'args' => 1 ) ), 5 * MINUTE_IN_SECONDS );
	return array(
		'done'  => false,
		'token' => $prep['token'],
		'url'   => $prep['url'],
	);
}

/**
 * The browser's check, part two: judges what the browser got. Adds the
 * .htaccess lines and asks for another round when they could help.
 *
 * @param string  $token   From part one.
 * @param array[] $results The browser's two answers: code, body, headers.
 * @param int     $attempt 0 for the first round, more after the lines were added.
 */
function rrt_dl_check_done( $token, $results, $attempt ) {
	$token = preg_replace( '/[^a-z0-9]/', '', strtolower( $token ) );
	$prep  = '' !== $token ? get_transient( 'rrt_dl_checkp_' . $token ) : false;
	if ( ! is_array( $prep ) ) {
		return new WP_Error( 'rrt_dl_check', __( 'The check ran out of time. Press Check counting again.', 'foxfire-studio' ), array( 'status' => 400 ) );
	}
	delete_transient( 'rrt_dl_checkp_' . $token );
	$resps = array();
	foreach ( array_slice( $results, 0, 2 ) as $x ) {
		$x       = (array) $x;
		$h       = array_change_key_case( isset( $x['headers'] ) ? (array) $x['headers'] : array(), CASE_LOWER );
		$resps[] = ! empty( $x['error'] )
			? array( 'error' => substr( sanitize_text_field( (string) $x['error'] ), 0, 200 ) )
			: array(
				'code' => isset( $x['code'] ) ? (int) $x['code'] : 0,
				'body' => isset( $x['body'] ) ? substr( (string) $x['body'], 0, 4096 ) : '',
				'type' => isset( $h['content-type'] ) ? substr( sanitize_text_field( (string) $h['content-type'] ), 0, 100 ) : '',
				'cdn'  => rrt_dl_cdn_name( array( 'headers' => array_map( 'strval', $h ) ) ),
			);
	}
	$how   = (string) $prep['how'];
	$round = rrt_dl_check_judge( $prep, $resps );
	$res   = array(
		'at'  => time(),
		'ok'  => false,
		'how' => $how,
		'sig' => rrt_dl_sig( $how ),
		'why' => '',
	);
	$fix   = '';
	/* The hand-off failed with this variant: try the next one LiteSpeed documents. */
	if ( ! $round['delivered'] ) {
		$list = rrt_dl_variants( $how );
		$at   = array_search( (string) $prep['variant'], $list, true );
		if ( false !== $at && isset( $list[ $at + 1 ] ) ) {
			return array(
				'retry'   => true,
				'variant' => $list[ $at + 1 ],
			);
		}
	}
	$needs = $round['delivered'] && ( ! $round['ranges'] || ! $round['fresh'] ) && in_array( $how, array( 'litespeed', 'apache' ), true );
	if ( $needs ) {
		if ( ! rrt_dl_on() ) {
			$fix = __( 'Switch Count downloads on (above) and check again: the check then adds the lines that hand the Range header to counting.', 'foxfire-studio' );
		} elseif ( ! rrt_dl_htaccess_current() ) {
			$add = rrt_dl_htaccess_add();
			if ( is_wp_error( $add ) ) {
				$fix = $add->get_error_message() . ' ' . __( 'Add the lines shown below at the top of it yourself, then check again.', 'foxfire-studio' );
			} else {
				return array(
					'retry'   => true,
					'variant' => (string) $prep['variant'],
				);
			}
		} elseif ( $attempt < 5 ) {
			return array(
				'retry'   => true,
				'variant' => (string) $prep['variant'],
			);
		} else {
			$fix = rrt_dl_check_advice( $round );
		}
	} elseif ( $attempt > 0 && $round['delivered'] ) {
		$fix = __( 'Foxfire Studio\'s lines were added to the site\'s .htaccess file, and every step now passes.', 'foxfire-studio' );
	}
	return array_merge( array( 'done' => true ), rrt_dl_check_finish( $res, $round, $fix ) );
}

/** What to do when the lines are in and a step still fails. */
function rrt_dl_check_advice( $round ) {
	if ( '' !== $round['cdn'] ) {
		/* translators: %s: a CDN's name. */
		return sprintf( __( 'Foxfire Studio\'s lines are in the site\'s .htaccess file, but %s, in front of the site, still keeps the Range header or a copy from counting. Wait a minute and check again; if it stays like this, switch that CDN off for this site, or keep it and accept that test requests count as downloads.', 'foxfire-studio' ), $round['cdn'] );
	}
	return __( 'Foxfire Studio\'s lines are in the site\'s .htaccess file, but counting still cannot see the Range header. Wait a minute and check again; if it stays like this, ask your host whether the Range header reaches PHP.', 'foxfire-studio' );
}

/** Keeps a check's result. */
function rrt_dl_check_save( $res ) {
	$s          = rrt_dl_settings();
	$s['check'] = array_intersect_key( $res, array_flip( array( 'at', 'ok', 'how', 'sig', 'why', 'steps', 'ranges', 'fix', 'fresh', 'cdn', 'variant' ) ) );
	rrt_dl_settings_save( $s );
}

/** Answers the check's own request: notes that it was seen, then hands the test file over. */
function rrt_dl_check_answer( $name ) {
	$token = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) preg_replace( '/\.mp3$/i', '', $name ) ) );
	$t     = '' !== $token ? get_transient( 'rrt_dl_check_' . $token ) : false;
	if ( ! is_array( $t ) || empty( $t['file'] ) || ! is_file( $t['file'] ) ) {
		status_header( 404 );
		return;
	}
	$rh = rrt_dl_range_header();
	file_put_contents( rrt_dl_check_dir() . '/seen-' . $token, wp_json_encode( $rh ) . "\n", FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	rrt_dl_send_handoff( $t['file'], $t['how'], isset( $t['variant'] ) ? (string) $t['variant'] : '' );
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'rrt/v1',
			'/dl/check',
			array(
				'methods'             => 'POST',
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'callback'            => function () {
					return rest_ensure_response( rrt_dl_check_run() );
				},
			)
		);
		/* The browser's way (second build of 1.24.0): the owner's browser makes the two requests, as an app would. */
		register_rest_route(
			'rrt/v1',
			'/dl/check/start',
			array(
				'methods'             => 'POST',
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'callback'            => function ( $req ) {
					return rest_ensure_response( rrt_dl_check_start( (string) $req->get_param( 'variant' ) ) );
				},
			)
		);
		register_rest_route(
			'rrt/v1',
			'/dl/check/done',
			array(
				'methods'             => 'POST',
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'callback'            => function ( $req ) {
					return rest_ensure_response( rrt_dl_check_done( (string) $req->get_param( 'token' ), (array) $req->get_param( 'results' ), (int) $req->get_param( 'attempt' ) ) );
				},
			)
		);
	}
);

/*
 * ---------------------------------------------------------------------
 * The daily job
 * ---------------------------------------------------------------------
 */

/** Forgets the codes from before yesterday, and the day's random code goes with them. */
function rrt_dl_trim() {
	global $wpdb;
	$ts = rrt_table( 'dl_seen' );
	$wpdb->query( $wpdb->prepare( "DELETE FROM $ts WHERE day < %s", wp_date( 'Y-m-d', time() - DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
}
add_action( 'rrt_listen_daily', 'rrt_dl_trim' );

/*
 * ---------------------------------------------------------------------
 * For the dashboard and the report
 * ---------------------------------------------------------------------
 */

/**
 * Downloads in a period: the total, by app, device, feed and episode (most
 * first), each day, the podcast consumers each day, and how many were
 * counted the 1.2.0 way (before 1.24.0).
 */
function rrt_dl_stats( $from, $to ) {
	global $wpdb;
	$out = array(
		'total'     => 0,
		'old'       => 0,
		'apps'      => array(),
		'devices'   => array(),
		'feeds'     => array(),
		'posts'     => array(),
		'days'      => array(),
		'consumers' => array(),
		'members'   => array(),
	);
	$add = function ( &$arr, $k, $n ) {
		$arr[ $k ] = ( isset( $arr[ $k ] ) ? $arr[ $k ] : 0 ) + $n;
	};
	$tc   = rrt_table( 'dl_counts' );
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT day, post_id, app, device, feed, downloads FROM $tc WHERE day BETWEEN %s AND %s", $from, $to ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	foreach ( (array) $rows as $r ) {
		$n             = (int) $r['downloads'];
		$out['total'] += $n;
		$add( $out['apps'], $r['app'], $n );
		$add( $out['devices'], $r['device'], $n );
		$add( $out['feeds'], $r['feed'], $n );
		$add( $out['posts'], (int) $r['post_id'], $n );
		$add( $out['days'], $r['day'], $n );
	}
	$td   = rrt_table( 'downloads' );
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT day, post_id, app, downloads FROM $td WHERE day BETWEEN %s AND %s", $from, $to ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	foreach ( (array) $rows as $r ) {
		$n             = (int) $r['downloads'];
		$out['total'] += $n;
		$out['old']   += $n;
		$add( $out['apps'], 'Web browser' === $r['app'] ? 'Web browser' : $r['app'], $n );
		$add( $out['posts'], (int) $r['post_id'], $n );
		$add( $out['days'], $r['day'], $n );
	}
	$tp   = rrt_table( 'dl_days' );
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT day, consumers, members FROM $tp WHERE day BETWEEN %s AND %s", $from, $to ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	foreach ( (array) $rows as $r ) {
		$out['consumers'][ $r['day'] ] = (int) $r['consumers'];
		$out['members'][ $r['day'] ]   = (int) $r['members'];
	}
	foreach ( array( 'apps', 'devices', 'feeds', 'posts' ) as $k ) {
		arsort( $out[ $k ] );
	}
	ksort( $out['days'] );
	ksort( $out['consumers'] );
	return $out;
}

/** Whether any download has ever been counted, either way. */
function rrt_dl_any() {
	global $wpdb;
	$tc = rrt_table( 'dl_counts' );
	$td = rrt_table( 'downloads' );
	return (bool) $wpdb->get_var( "SELECT 1 FROM $tc LIMIT 1" ) || (bool) $wpdb->get_var( "SELECT 1 FROM $td LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
}

/**
 * Sponsor messages delivered, for a message's screen.
 *
 * @return array[] post, slot, day, delivered.
 */
function rrt_dl_ads_for( $spot_id, $from, $to ) {
	global $wpdb;
	$t = rrt_table( 'dl_ads' );
	return (array) $wpdb->get_results( $wpdb->prepare( "SELECT post_id, slot, SUM(delivered) AS n FROM $t WHERE spot_id = %d AND day BETWEEN %s AND %s GROUP BY post_id, slot", $spot_id, $from, $to ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
}
