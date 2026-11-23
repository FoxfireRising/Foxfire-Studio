<?php
/**
 * Country lookup for listener analytics (0.10.0).
 *
 * The country comes from the web host or CDN when it sends one (Cloudflare's
 * CF-IPCountry, or a server GeoIP variable). Otherwise it is looked up in a
 * local copy of DB-IP's free IP to Country Lite database, which the settings
 * screen downloads to this site and which is refreshed once a month.
 *
 * DB-IP publishes the data under Creative Commons Attribution 4.0, so pages
 * that show the results credit "IP Geolocation by DB-IP" with a link.
 *
 * The download (a large CSV) is turned into one small binary file, a sorted
 * list of range starts with their country codes, searched by halving:
 * - IPv4: 4 byte start, 2 byte country.
 * - IPv6: the first 8 bytes of the start (the /64 network; no country is
 *   assigned to anything smaller), 2 byte country.
 * Neighbouring ranges of the same country are merged, and gaps are stored
 * as "--" (unknown).
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Where the converted database lives. */
function rrt_geo_file() {
	$up = wp_upload_dir( null, false );
	return trailingslashit( $up['basedir'] ) . 'rrt-assets/country.bin';
}

/** Whether the database is on this site. */
function rrt_geo_ready() {
	return is_readable( rrt_geo_file() );
}

/** The download address for a month, as DB-IP names it. */
function rrt_geo_url( $ym ) {
	return apply_filters( 'rrt_geo_url', 'https://download.db-ip.com/free/dbip-country-lite-' . $ym . '.csv.gz', $ym );
}

/** Status for the settings screen. */
function rrt_geo_status() {
	$info = get_option( 'rrt_geo', array() );
	if ( ! rrt_geo_ready() ) {
		return __( 'Not on this site yet. Countries show as unknown unless your host sends them.', 'foxfire-studio' );
	}
	return sprintf(
		/* translators: 1: month of the edition, 2: number of ranges, 3: file size, 4: date saved. */
		__( 'DB-IP %1$s edition, %2$s ranges, %3$s, saved %4$s.', 'foxfire-studio' ),
		isset( $info['month'] ) ? $info['month'] : '?',
		number_format_i18n( isset( $info['ranges'] ) ? (int) $info['ranges'] : 0 ),
		rrt_size( filesize( rrt_geo_file() ) ),
		isset( $info['saved'] ) ? wp_date( get_option( 'date_format' ), (int) $info['saved'] ) : '?'
	);
}

/** Adds one to a big-endian binary number. */
function rrt_geo_next( $bin ) {
	$i = strlen( $bin ) - 1;
	while ( $i >= 0 ) {
		$c = ord( $bin[ $i ] );
		if ( 255 === $c ) {
			$bin[ $i ] = "\0";
			--$i;
			continue;
		}
		$bin[ $i ] = chr( $c + 1 );
		return $bin;
	}
	return null; // Past the last address.
}

/**
 * Turns DB-IP's CSV (start,end,country per line, IPv4 then IPv6) into the
 * binary file. Reads and writes as it goes, so memory use stays small.
 *
 * @param string $gz   Path to the downloaded .csv.gz.
 * @param string $dest Path to write.
 * @return int|WP_Error Number of ranges written.
 */
function rrt_geo_convert( $gz, $dest ) {
	$in = gzopen( $gz, 'rb' );
	if ( ! $in ) {
		return new WP_Error( 'rrt_geo', __( 'The download could not be opened.', 'foxfire-studio' ) );
	}
	$tmp4 = $dest . '.v4.tmp';
	$tmp6 = $dest . '.v6.tmp';
	$out  = array( 4 => fopen( $tmp4, 'wb' ), 6 => fopen( $tmp6, 'wb' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$n    = array( 4 => 0, 6 => 0 );
	$st   = array(
		4 => array( 'cc' => null, 'end' => null, 'start' => null ),
		6 => array( 'cc' => null, 'end' => null, 'start' => null ),
	);
	$bad  = 0;
	$emit = function ( $fam, $start, $cc ) use ( &$out, &$n, &$st ) {
		/* A later range inside the same /64 as the last one adds nothing at this resolution. */
		if ( null !== $st[ $fam ]['start'] && strcmp( $start, $st[ $fam ]['start'] ) <= 0 ) {
			return;
		}
		fwrite( $out[ $fam ], $start . $cc ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$st[ $fam ]['start'] = $start;
		$st[ $fam ]['cc']    = $cc;
		++$n[ $fam ];
	};
	while ( ! gzeof( $in ) ) {
		$line = gzgets( $in, 512 );
		if ( false === $line ) {
			break;
		}
		$f = explode( ',', trim( $line ) );
		if ( count( $f ) < 3 ) {
			continue;
		}
		$a  = @inet_pton( trim( $f[0], '" ' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$b  = @inet_pton( trim( $f[1], '" ' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$cc = strtoupper( substr( trim( $f[ count( $f ) - 1 ], '" ' ), 0, 2 ) );
		if ( false === $a || false === $b || strlen( $a ) !== strlen( $b ) || ! preg_match( '/^[A-Z]{2}$/', $cc ) ) {
			++$bad;
			continue;
		}
		$fam = 4 === strlen( $a ) ? 4 : 6;
		if ( 6 === $fam ) {
			$a = substr( $a, 0, 8 );
			$b = substr( $b, 0, 8 );
		}
		$s = &$st[ $fam ];
		if ( null !== $s['end'] ) {
			$next = rrt_geo_next( $s['end'] );
			if ( null === $next ) {
				unset( $s );
				continue;
			}
			if ( strcmp( $a, $next ) > 0 && '--' !== $s['cc'] ) {
				$emit( $fam, $next, '--' );
			}
		} elseif ( strcmp( $a, str_repeat( "\0", strlen( $a ) ) ) > 0 ) {
			$emit( $fam, str_repeat( "\0", strlen( $a ) ), '--' );
		}
		if ( $cc !== $s['cc'] ) {
			$emit( $fam, $a, $cc );
		}
		if ( null === $s['end'] || strcmp( $b, $s['end'] ) > 0 ) {
			$s['end'] = $b;
		}
		unset( $s );
	}
	gzclose( $in );
	/* Everything after the last range is unknown too. */
	foreach ( array( 4, 6 ) as $fam ) {
		if ( null !== $st[ $fam ]['end'] ) {
			$next = rrt_geo_next( $st[ $fam ]['end'] );
			if ( null !== $next && '--' !== $st[ $fam ]['cc'] ) {
				$emit( $fam, $next, '--' );
			}
		}
	}
	fclose( $out[4] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	fclose( $out[6] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( $n[4] < 1000 ) {
		wp_delete_file( $tmp4 );
		wp_delete_file( $tmp6 );
		return new WP_Error( 'rrt_geo', sprintf( /* translators: 1: ranges read, 2: lines not understood. */ __( 'The download did not look like DB-IP\'s country database (%1$d ranges read, %2$d lines not understood).', 'foxfire-studio' ), (int) $n[4], (int) $bad ) );
	}
	/* Header: "RRTG", format 1, IPv4 count, IPv6 count. Then the IPv4 list, then the IPv6 list. */
	$part = $dest . '.part';
	$fh   = fopen( $part, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	fwrite( $fh, 'RRTG' . pack( 'nNN', 1, $n[4], $n[6] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	foreach ( array( $tmp4, $tmp6 ) as $t ) {
		$r = fopen( $t, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		stream_copy_to_stream( $r, $fh );
		fclose( $r ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		wp_delete_file( $t );
	}
	fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( ! rename( $part, $dest ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
		wp_delete_file( $part );
		return new WP_Error( 'rrt_geo', __( 'The database could not be saved.', 'foxfire-studio' ) );
	}
	return $n[4] + $n[6];
}

/**
 * Looks up an IP address's two-letter country code, or '' when unknown.
 * The address is used only for this and never written anywhere.
 */
function rrt_geo_country( $ip ) {
	/*
	 * A country set by the web server itself (GEOIP_COUNTRY_CODE) cannot be
	 * sent by a visitor. Country headers that arrive over HTTP can, so they
	 * count only when the request came through a trusted proxy (0.10.1).
	 */
	$keys = array( 'GEOIP_COUNTRY_CODE' );
	if ( function_exists( 'rrt_listen_via_trusted_proxy' ) && rrt_listen_via_trusted_proxy() ) {
		$keys = array( 'HTTP_CF_IPCOUNTRY', 'GEOIP_COUNTRY_CODE', 'HTTP_X_COUNTRY_CODE', 'HTTP_X_GEO_COUNTRY' );
	}
	foreach ( $keys as $k ) {
		if ( ! empty( $_SERVER[ $k ] ) && is_string( $_SERVER[ $k ] ) ) {
			$cc = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) $_SERVER[ $k ] ), 0, 2 ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( preg_match( '/^[A-Z]{2}$/', $cc ) && 'XX' !== $cc && 'T1' !== $cc ) {
				rrt_geo_country_source( $k );
				return $cc;
			}
		}
	}
	rrt_geo_country_source( 'database' );
	$cc = rrt_geo_lookup( $ip );
	return (string) apply_filters( 'rrt_listener_country', $cc );
}

/** Where the last country came from: a server variable or header name, or 'database'. */
function rrt_geo_country_source( $set = null ) {
	static $from = 'database';
	if ( null !== $set ) {
		$from = (string) $set;
	}
	return $from;
}

/** Searches the local database. */
function rrt_geo_lookup( $ip ) {
	$bin = @inet_pton( (string) $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	if ( false === $bin || ! rrt_geo_ready() ) {
		return '';
	}
	/* IPv4 written as IPv6 (::ffff:1.2.3.4) is looked up as IPv4. */
	if ( 16 === strlen( $bin ) && substr( $bin, 0, 12 ) === str_repeat( "\0", 10 ) . "\xff\xff" ) {
		$bin = substr( $bin, 12 );
	}
	$fh = fopen( rrt_geo_file(), 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( ! $fh ) {
		return '';
	}
	$head = fread( $fh, 14 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( 14 !== strlen( $head ) || 'RRTG' !== substr( $head, 0, 4 ) ) {
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return '';
	}
	$h = unpack( 'nver/Nv4/Nv6', substr( $head, 4 ) );
	if ( 4 === strlen( $bin ) ) {
		$len  = 4;
		$base = 14;
		$cnt  = $h['v4'];
	} else {
		$len  = 8;
		$bin  = substr( $bin, 0, 8 );
		$base = 14 + $h['v4'] * 6;
		$cnt  = $h['v6'];
	}
	$size = $len + 2;
	$lo   = 0;
	$hi   = $cnt - 1;
	$best = '';
	while ( $lo <= $hi ) {
		$mid = (int) ( ( $lo + $hi ) / 2 );
		fseek( $fh, $base + $mid * $size );
		$rec = fread( $fh, $size ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( strlen( $rec ) !== $size ) {
			break;
		}
		if ( strcmp( substr( $rec, 0, $len ), $bin ) <= 0 ) {
			$best = substr( $rec, $len, 2 );
			$lo   = $mid + 1;
		} else {
			$hi = $mid - 1;
		}
	}
	fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	return ( '--' === $best || '' === $best ) ? '' : $best;
}

/**
 * Downloads this month's database (or last month's, early in a month before
 * the new one is out) and converts it.
 *
 * @return true|WP_Error
 */
function rrt_geo_download() {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
	$dir = dirname( rrt_geo_file() );
	if ( ! wp_mkdir_p( $dir ) ) {
		return new WP_Error( 'rrt_geo', sprintf( /* translators: %s: folder path. */ __( 'The folder %s could not be created.', 'foxfire-studio' ), $dir ) );
	}
	$err = null;
	foreach ( array( gmdate( 'Y-m' ), gmdate( 'Y-m', strtotime( gmdate( 'Y-m-01' ) . ' -1 month' ) ) ) as $ym ) {
		$tmp = download_url( rrt_geo_url( $ym ), 240 );
		if ( is_wp_error( $tmp ) ) {
			$err = $tmp;
			continue;
		}
		$n = rrt_geo_convert( $tmp, rrt_geo_file() );
		wp_delete_file( $tmp );
		if ( is_wp_error( $n ) ) {
			$err = $n;
			continue;
		}
		update_option( 'rrt_geo', array( 'month' => $ym, 'ranges' => $n, 'saved' => time() ), false );
		return true;
	}
	return $err ? $err : new WP_Error( 'rrt_geo', __( 'The download failed.', 'foxfire-studio' ) );
}

add_action(
	'wp_ajax_rrt_geo_fetch',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'foxfire-studio' ) ), 403 );
		}
		check_ajax_referer( 'rrt_geo', 'nonce' );
		$r = rrt_geo_download();
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message(), 'status' => rrt_geo_status() ) );
		}
		wp_send_json_success( array( 'status' => rrt_geo_status() ) );
	}
);

/* Monthly refresh, by the daily job: a new edition comes out at the start of each month. */
add_action(
	'rrt_listen_daily',
	function () {
		if ( ! rrt_geo_ready() ) {
			return;
		}
		$info = get_option( 'rrt_geo', array() );
		if ( empty( $info['month'] ) || $info['month'] !== gmdate( 'Y-m' ) ) {
			if ( (int) gmdate( 'j' ) >= 3 ) {
				rrt_geo_download();
			}
		}
	},
	20
);
