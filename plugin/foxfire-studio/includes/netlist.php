<?php
/**
 * Data centre and VPN addresses, and the owner's shared networks (1.24.0).
 *
 * The IAB Tech Lab guidelines (v2.3, 5.5.2) ask that downloads from known
 * data centres, VPNs and other non-human sources are left out. The list is
 * X4BNet's open lists_vpn "datacenter" list (MIT), which covers cloud and
 * hosting companies and the networks most VPNs run on. A copy ships in
 * data/datacenters.bin (made by tools/lists/datacenters.php for each
 * release); while counting is on, the daily job downloads the current one
 * once a month from GitHub into uploads/rrt-assets/. Only the list file is
 * requested; nothing about visitors is sent.
 *
 * The file is a sorted list of merged ranges, searched by halving:
 *   "RRTD", version (2 bytes), IPv4 count, IPv6 count (4 bytes each), then
 *   IPv4 ranges as start and end (4 + 4 bytes), then IPv6 ranges as the
 *   first 64 bits of start and end (8 + 8 bytes).
 *
 * Shared networks are address ranges the owner trusts (an office, a school),
 * which the per-address limits then leave alone. The guidelines ask that such
 * a list is confirmed at least every 90 days, so the Podcast feed screen shows
 * when it was last confirmed and asks again after 90 days.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The two text files of the list, IPv4 and IPv6, one range per line. */
function rrt_net_urls() {
	return apply_filters(
		'rrt_net_urls',
		array(
			'https://raw-githubusercontent-com.072103.xyz/X4BNet/lists_vpn/main/output/datacenter/ipv4.txt',
			'https://raw-githubusercontent-com.072103.xyz/X4BNet/lists_vpn/main/output/datacenter/ipv6.txt',
		)
	);
}

/** The downloaded copy's place. */
function rrt_net_saved_file() {
	$up = wp_upload_dir( null, false );
	return trailingslashit( $up['basedir'] ) . 'rrt-assets/datacenters.bin';
}

/** The copy in use: the downloaded one when there is one, else the one in the plugin. */
function rrt_net_file() {
	$f = rrt_net_saved_file();
	return is_readable( $f ) ? $f : RRT_DIR . 'data/datacenters.bin';
}

/** The first 64 bits of an IPv6 address, or the 4 bytes of an IPv4 one (IPv4 written as IPv6 too), or ''. */
function rrt_net_key( $ip ) {
	$bin = @inet_pton( trim( (string) $ip ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	if ( false === $bin ) {
		return '';
	}
	if ( 16 === strlen( $bin ) && substr( $bin, 0, 12 ) === str_repeat( "\0", 10 ) . "\xff\xff" ) {
		return substr( $bin, 12 );
	}
	return 16 === strlen( $bin ) ? substr( $bin, 0, 8 ) : $bin;
}

/**
 * A range written as CIDR (or a single address) as its first and last key.
 *
 * @return array|null array( start, end ), each 4 or 8 bytes.
 */
function rrt_net_range( $line ) {
	$line = trim( (string) $line );
	if ( '' === $line || '#' === $line[0] ) {
		return null;
	}
	$parts = explode( '/', $line, 2 );
	$bin   = @inet_pton( $parts[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	if ( false === $bin ) {
		return null;
	}
	$bits = 8 * strlen( $bin );
	$len  = isset( $parts[1] ) && '' !== $parts[1] ? (int) $parts[1] : $bits;
	if ( $len < 0 || $len > $bits || ( isset( $parts[1] ) && ! ctype_digit( $parts[1] ) ) ) {
		return null;
	}
	if ( 16 === strlen( $bin ) ) {
		$bin  = substr( $bin, 0, 8 );
		$bits = 64;
		$len  = min( $len, 64 );
	}
	$start = '';
	$end   = '';
	for ( $i = 0; $i < strlen( $bin ); $i++ ) {
		$keep   = max( 0, min( 8, $len - 8 * $i ) );
		$mask   = $keep ? ( 0xff << ( 8 - $keep ) ) & 0xff : 0;
		$b      = ord( $bin[ $i ] );
		$start .= chr( $b & $mask );
		$end   .= chr( ( $b & $mask ) | ( ~$mask & 0xff ) );
	}
	return array( $start, $end );
}

/** Adds one to a big-endian binary number ('' when it runs over). */
function rrt_net_next( $bin ) {
	for ( $i = strlen( $bin ) - 1; $i >= 0; $i-- ) {
		if ( "\xff" !== $bin[ $i ] ) {
			return substr( $bin, 0, $i ) . chr( ord( $bin[ $i ] ) + 1 ) . str_repeat( "\0", strlen( $bin ) - $i - 1 );
		}
	}
	return '';
}

/** Sorts and merges ranges of one length. */
function rrt_net_merge( $ranges ) {
	usort(
		$ranges,
		function ( $a, $b ) {
			return strcmp( $a[0], $b[0] );
		}
	);
	$out = array();
	foreach ( $ranges as $r ) {
		$n = count( $out );
		if ( $n ) {
			$next = rrt_net_next( $out[ $n - 1 ][1] );
			if ( '' === $next || strcmp( $r[0], $next ) <= 0 ) {
				if ( strcmp( $r[1], $out[ $n - 1 ][1] ) > 0 ) {
					$out[ $n - 1 ][1] = $r[1];
				}
				continue;
			}
		}
		$out[] = $r;
	}
	return $out;
}

/**
 * Turns lines of ranges into the lookup file.
 *
 * @param string[] $lines Ranges, IPv4 and IPv6 mixed.
 * @param string   $dest  The file to write.
 * @return array|WP_Error The counts: v4 and v6.
 */
function rrt_net_convert( $lines, $dest ) {
	$v4 = array();
	$v6 = array();
	foreach ( $lines as $line ) {
		$r = rrt_net_range( $line );
		if ( ! $r ) {
			continue;
		}
		if ( 4 === strlen( $r[0] ) ) {
			$v4[] = $r;
		} else {
			$v6[] = $r;
		}
	}
	$v4 = rrt_net_merge( $v4 );
	$v6 = rrt_net_merge( $v6 );
	if ( count( $v4 ) < (int) apply_filters( 'rrt_net_min', 1000 ) ) {
		return new WP_Error( 'rrt_net', __( 'The list of data centre addresses looked too short, so the old one was kept.', 'foxfire-studio' ) );
	}
	$body = 'RRTD' . pack( 'nNN', 1, count( $v4 ), count( $v6 ) );
	foreach ( $v4 as $r ) {
		$body .= $r[0] . $r[1];
	}
	foreach ( $v6 as $r ) {
		$body .= $r[0] . $r[1];
	}
	$tmp = $dest . '.tmp';
	if ( false === file_put_contents( $tmp, $body ) || ! rename( $tmp, $dest ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
		return new WP_Error( 'rrt_net', __( 'The list of data centre addresses could not be saved.', 'foxfire-studio' ) );
	}
	return array( 'v4' => count( $v4 ), 'v6' => count( $v6 ) );
}

/**
 * Whether an address is in a lookup file.
 *
 * @param string $ip   The address.
 * @param string $file The file (the data centre list by default).
 */
function rrt_net_in_file( $ip, $file = '' ) {
	$key = rrt_net_key( $ip );
	$file = '' === $file ? rrt_net_file() : $file;
	if ( '' === $key || ! is_readable( $file ) ) {
		return false;
	}
	$fh = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( ! $fh ) {
		return false;
	}
	$head = fread( $fh, 14 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( 14 !== strlen( $head ) || 'RRTD' !== substr( $head, 0, 4 ) ) {
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return false;
	}
	$h    = unpack( 'nver/Nv4/Nv6', substr( $head, 4 ) );
	$len  = strlen( $key );
	$base = 4 === $len ? 14 : 14 + $h['v4'] * 8;
	$cnt  = 4 === $len ? $h['v4'] : $h['v6'];
	$size = 2 * $len;
	$lo   = 0;
	$hi   = $cnt - 1;
	$hit  = false;
	while ( $lo <= $hi ) {
		$mid = (int) ( ( $lo + $hi ) / 2 );
		fseek( $fh, $base + $mid * $size );
		$rec = fread( $fh, $size ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( strlen( $rec ) !== $size ) {
			break;
		}
		if ( strcmp( substr( $rec, 0, $len ), $key ) <= 0 ) {
			$hit = strcmp( $key, substr( $rec, $len ) ) <= 0;
			$lo  = $mid + 1;
		} else {
			$hi = $mid - 1;
		}
	}
	fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	return $hit;
}

/** Whether an address belongs to a data centre or VPN network. */
function rrt_net_is_datacenter( $ip ) {
	return (bool) apply_filters( 'rrt_net_is_datacenter', rrt_net_in_file( $ip ), $ip );
}

/** Facts about the list in use, for the screen and the method page. */
function rrt_net_info() {
	$info = get_option( 'rrt_net', array() );
	$file = rrt_net_file();
	$out  = array(
		'from'  => rrt_net_saved_file() === $file ? 'download' : 'plugin',
		'date'  => '',
		'v4'    => 0,
		'v6'    => 0,
		'error' => is_array( $info ) && ! empty( $info['error'] ) ? (string) $info['error'] : '',
	);
	if ( 'download' === $out['from'] && is_array( $info ) && ! empty( $info['saved'] ) ) {
		$out['date'] = wp_date( 'Y-m-d', (int) $info['saved'] );
	} elseif ( 'plugin' === $out['from'] ) {
		$d           = @file_get_contents( RRT_DIR . 'data/datacenters-date.txt' ); // phpcs:ignore
		$out['date'] = false === $d ? '' : trim( $d );
	}
	$fh = is_readable( $file ) ? fopen( $file, 'rb' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( $fh ) {
		$head = fread( $fh, 14 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( 14 === strlen( $head ) && 'RRTD' === substr( $head, 0, 4 ) ) {
			$h         = unpack( 'nver/Nv4/Nv6', substr( $head, 4 ) );
			$out['v4'] = (int) $h['v4'];
			$out['v6'] = (int) $h['v6'];
		}
	}
	return $out;
}

/**
 * Downloads the current list and converts it.
 *
 * @return true|WP_Error
 */
function rrt_net_download() {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	$dest = rrt_net_saved_file();
	if ( ! wp_mkdir_p( dirname( $dest ) ) ) {
		return new WP_Error( 'rrt_net', __( 'The folder for the list could not be made.', 'foxfire-studio' ) );
	}
	$lines = array();
	foreach ( rrt_net_urls() as $url ) {
		$r = wp_remote_get( $url, array( 'timeout' => 60 ) );
		if ( is_wp_error( $r ) || 200 !== (int) wp_remote_retrieve_response_code( $r ) ) {
			$err = is_wp_error( $r ) ? $r->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $r );
			update_option( 'rrt_net', array_merge( (array) get_option( 'rrt_net', array() ), array( 'error' => $err, 'tried' => time() ) ), false );
			return new WP_Error( 'rrt_net', $err );
		}
		$lines = array_merge( $lines, preg_split( '/\r?\n/', (string) wp_remote_retrieve_body( $r ) ) );
	}
	$n = rrt_net_convert( $lines, $dest );
	if ( is_wp_error( $n ) ) {
		update_option( 'rrt_net', array_merge( (array) get_option( 'rrt_net', array() ), array( 'error' => $n->get_error_message(), 'tried' => time() ) ), false );
		return $n;
	}
	update_option( 'rrt_net', array( 'saved' => time(), 'tried' => time(), 'v4' => $n['v4'], 'v6' => $n['v6'], 'error' => '' ), false );
	return true;
}

/* Once a month while counting is on (the daily job; a failed try waits a day). */
add_action(
	'rrt_listen_daily',
	function () {
		if ( ! function_exists( 'rrt_dl_on' ) || ! rrt_dl_on() ) {
			return;
		}
		$info  = (array) get_option( 'rrt_net', array() );
		$saved = isset( $info['saved'] ) ? (int) $info['saved'] : 0;
		$tried = isset( $info['tried'] ) ? (int) $info['tried'] : 0;
		if ( time() - $saved > 30 * DAY_IN_SECONDS && time() - $tried > DAY_IN_SECONDS - HOUR_IN_SECONDS ) {
			rrt_net_download();
		}
	},
	25
);

/*
 * ---------------------------------------------------------------------
 * Shared networks
 * ---------------------------------------------------------------------
 */

/** The owner's shared networks as ranges (from the Podcast feed screen). */
function rrt_net_shared() {
	$s   = function_exists( 'rrt_dl_settings' ) ? rrt_dl_settings() : array();
	$out = array();
	foreach ( preg_split( '/[\s,]+/', isset( $s['shared'] ) ? (string) $s['shared'] : '' ) as $line ) {
		$r = rrt_net_range( $line );
		if ( $r ) {
			$out[] = $r;
		}
	}
	return $out;
}

/** Whether an address is in one of the owner's shared networks. */
function rrt_net_is_shared( $ip ) {
	$key = rrt_net_key( $ip );
	if ( '' === $key ) {
		return false;
	}
	foreach ( rrt_net_shared() as $r ) {
		if ( strlen( $r[0] ) === strlen( $key ) && strcmp( $r[0], $key ) <= 0 && strcmp( $key, $r[1] ) <= 0 ) {
			return true;
		}
	}
	return false;
}

/** Whether the shared networks are due to be confirmed again (90 days, IAB v2.3). */
function rrt_net_shared_due() {
	$s = function_exists( 'rrt_dl_settings' ) ? rrt_dl_settings() : array();
	if ( ! rrt_net_shared() ) {
		return false;
	}
	return empty( $s['shared_ok'] ) || time() - (int) $s['shared_ok'] > 90 * DAY_IN_SECONDS;
}
