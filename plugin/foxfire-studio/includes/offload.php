<?php
/**
 * Storage offload (1.23.0).
 *
 * The readings podcast apps and the player download (each published
 * reading's MP3 and captions, sponsored copies and collections' joined
 * files) can be served from somewhere else: a CDN in front of this site
 * (nothing uploaded, the CDN copies from the site), Bunny Storage (its own
 * HTTP API) or any S3-compatible storage (signed by hand with AWS Signature
 * Version 4, so no code library ships with the plugin).
 *
 * The site always keeps its own files: offload only adds copies, and every
 * address falls back to the site's own file whenever a copy is missing,
 * changed or unchecked, while offload is off, or after the outside storage
 * failed three checks in a row. Each copy's address is named by its content
 * (the first ten characters of its SHA-256), so an address never points at
 * different sound. A copy is deleted when its file leaves public use: at
 * once when the file is for members, kept aside, unpublished or gone, and
 * two days after a reading got a newer file (apps may still be playing it).
 *
 * Files for members, readings kept aside, audiobook copies, sent and shared
 * takes and the studio's own files never leave the site.
 *
 * Settings and the list of copies live in two options of their own, neither
 * loaded on every page: rrt_offload and rrt_offload_files.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The settings and state. */
define( 'RRT_OFF_OPT', 'rrt_offload' );
/** The list of copies: uploads-relative path => record. */
define( 'RRT_OFF_FILES', 'rrt_offload_files' );
/** Where push copies go in the bucket or storage zone. */
define( 'RRT_OFF_PREFIX', 'foxfire' );
/** The local folder for Check this setup's test file in CDN mode. */
define( 'RRT_OFF_CHECK_DIR', 'rrt-offload-check' );
/** A copy of a file a reading no longer uses stays this long (apps may be playing it). */
define( 'RRT_OFF_GRACE', 2 * DAY_IN_SECONDS );
/** Failed checks in a row before every address goes back to the site. */
define( 'RRT_OFF_FAILS', 3 );
/** Above this size, S3 uploads go in parts. */
define( 'RRT_OFF_PART_OVER', 64 * 1024 * 1024 );
/** The size of each part. */
define( 'RRT_OFF_PART', 8 * 1024 * 1024 );
/** How long an outside copy may be cached (a day, so a withdrawn file stops within a day even without a purge key). */
define( 'RRT_OFF_CACHE', 'public, max-age=86400' );

/*
 * ---------------------------------------------------------------------
 * Settings
 * ---------------------------------------------------------------------
 */

/** Bunny Storage's regions and their API hosts. */
function rrt_off_bunny_regions() {
	return array(
		'de'  => array( __( 'Falkenstein, Germany (the main region)', 'foxfire-studio' ), 'storage.bunnycdn.com' ),
		'uk'  => array( __( 'London, United Kingdom', 'foxfire-studio' ), 'uk.storage.bunnycdn.com' ),
		'se'  => array( __( 'Stockholm, Sweden', 'foxfire-studio' ), 'se.storage.bunnycdn.com' ),
		'ny'  => array( __( 'New York, United States', 'foxfire-studio' ), 'ny.storage.bunnycdn.com' ),
		'la'  => array( __( 'Los Angeles, United States', 'foxfire-studio' ), 'la.storage.bunnycdn.com' ),
		'sg'  => array( __( 'Singapore', 'foxfire-studio' ), 'sg.storage.bunnycdn.com' ),
		'syd' => array( __( 'Sydney, Australia', 'foxfire-studio' ), 'syd.storage.bunnycdn.com' ),
		'br'  => array( __( 'São Paulo, Brazil', 'foxfire-studio' ), 'br.storage.bunnycdn.com' ),
		'jh'  => array( __( 'Johannesburg, South Africa', 'foxfire-studio' ), 'jh.storage.bunnycdn.com' ),
	);
}

/** S3-compatible services with the values they need, for the preset menu. */
function rrt_off_s3_presets() {
	return array(
		'r2'     => array( 'Cloudflare R2', 'https://ACCOUNT_ID.r2.cloudflarestorage.com', 'auto' ),
		'b2'     => array( 'Backblaze B2', 'https://s3.us-west-004.backblazeb2.com', 'us-west-004' ),
		'wasabi' => array( 'Wasabi', 'https://s3.us-east-1.wasabisys.com', 'us-east-1' ),
		'aws'    => array( 'Amazon S3', 'https://s3.us-east-1.amazonaws.com', 'us-east-1' ),
		'do'     => array( 'DigitalOcean Spaces', 'https://nyc3.digitaloceanspaces.com', 'us-east-1' ),
		'bunny'  => array( __( 'Bunny Storage (S3, only for zones made with it)', 'foxfire-studio' ), 'https://ny-s3.storage.bunnycdn.com', 'ny' ),
	);
}

/** The settings and state, with defaults. */
function rrt_off_settings() {
	$s = get_option( RRT_OFF_OPT, array() );
	$s = is_array( $s ) ? $s : array();
	return array_merge(
		array(
			'on'          => false,
			'mode'        => 'bunny',  // cdn, bunny or s3.
			'use'         => 'all',    // all: feeds and the post's player; feeds: podcast apps only.
			'cdn_base'    => '',
			'b_zone'      => '',
			'b_region'    => 'de',
			'b_pass'      => '',
			'b_base'      => '',
			's3_endpoint' => '',
			's3_region'   => 'auto',
			's3_bucket'   => '',
			's3_key'      => '',
			's3_secret'   => '',
			's3_base'     => '',
			'purge_key'   => '',       // Optional: a bunny.net account API key, to clear a file from a pull zone at once.
			/* State. */
			'passed'      => '',       // The target that last passed Check this setup.
			'check'       => array(),  // The last check: at, ok, steps.
			'fails'       => 0,        // Failed health checks in a row.
			'down'        => 0,        // When every address went back to the site, or 0.
			'down_why'    => '',
			'seen'        => 0,        // The last health check.
		),
		$s
	);
}

/** Saves the settings and state. */
function rrt_off_save( $s ) {
	update_option( RRT_OFF_OPT, $s, false );
}

/**
 * A secret, from wp-config.php when it is set there, else from the settings.
 *
 * @param string $which key (an S3 access key id), secret (an S3 secret or a Bunny password) or purge.
 */
function rrt_off_secret( $which, $s = null ) {
	$s = $s ? $s : rrt_off_settings();
	if ( 'purge' === $which ) {
		return defined( 'FOXFIRE_STORAGE_PURGE_KEY' ) ? (string) FOXFIRE_STORAGE_PURGE_KEY : (string) $s['purge_key'];
	}
	if ( 'key' === $which ) {
		return defined( 'FOXFIRE_STORAGE_KEY' ) ? (string) FOXFIRE_STORAGE_KEY : (string) $s['s3_key'];
	}
	if ( defined( 'FOXFIRE_STORAGE_SECRET' ) ) {
		return (string) FOXFIRE_STORAGE_SECRET;
	}
	return 's3' === $s['mode'] ? (string) $s['s3_secret'] : (string) $s['b_pass'];
}

/** Whether a secret comes from wp-config.php. */
function rrt_off_secret_in_config( $which ) {
	$c = array(
		'key'    => 'FOXFIRE_STORAGE_KEY',
		'secret' => 'FOXFIRE_STORAGE_SECRET',
		'purge'  => 'FOXFIRE_STORAGE_PURGE_KEY',
	);
	return isset( $c[ $which ] ) && defined( $c[ $which ] );
}

/** The public address listeners get copies from, with no slash at the end. */
function rrt_off_base( $s = null ) {
	$s = $s ? $s : rrt_off_settings();
	$b = 'cdn' === $s['mode'] ? $s['cdn_base'] : ( 's3' === $s['mode'] ? $s['s3_base'] : $s['b_base'] );
	return untrailingslashit( trim( (string) $b ) );
}

/** Whether an address may be used: https, or http on this computer (tests). */
function rrt_off_url_ok( $url ) {
	$url  = (string) $url;
	$host = (string) wp_parse_url( $url, PHP_URL_HOST );
	if ( '' === $host ) {
		return false;
	}
	if ( 0 === stripos( $url, 'https://' ) ) {
		return true;
	}
	return 0 === stripos( $url, 'http://' ) && in_array( $host, array( '127.0.0.1', 'localhost' ), true );
}

/** What is missing before the setup can be checked, as sentences. Empty: ready to check. */
function rrt_off_missing( $s = null ) {
	$s   = $s ? $s : rrt_off_settings();
	$out = array();
	$b   = rrt_off_base( $s );
	if ( ! rrt_off_url_ok( $b ) ) {
		$out[] = 'cdn' === $s['mode']
			? __( 'Paste the CDN\'s address (it starts with https://).', 'foxfire-studio' )
			: __( 'Paste the public address listeners will use (it starts with https://).', 'foxfire-studio' );
	} elseif ( 'cdn' === $s['mode'] && strtolower( (string) wp_parse_url( $b, PHP_URL_HOST ) ) === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
		$out[] = __( 'The CDN\'s address is this site\'s own address. Paste the address the CDN gave you.', 'foxfire-studio' );
	}
	if ( 'bunny' === $s['mode'] ) {
		if ( '' === trim( (string) $s['b_zone'] ) ) {
			$out[] = __( 'Type the storage zone\'s name.', 'foxfire-studio' );
		}
		if ( '' === rrt_off_secret( 'secret', $s ) ) {
			$out[] = __( 'Paste the storage zone\'s password.', 'foxfire-studio' );
		}
	} elseif ( 's3' === $s['mode'] ) {
		if ( ! rrt_off_url_ok( $s['s3_endpoint'] ) ) {
			$out[] = __( 'Paste the S3 endpoint (it starts with https://).', 'foxfire-studio' );
		}
		if ( '' === trim( (string) $s['s3_bucket'] ) ) {
			$out[] = __( 'Type the bucket\'s name.', 'foxfire-studio' );
		}
		if ( '' === rrt_off_secret( 'key', $s ) || '' === rrt_off_secret( 'secret', $s ) ) {
			$out[] = __( 'Paste the access key and its secret.', 'foxfire-studio' );
		}
	}
	return $out;
}

/** Where copies go now, as a short code: a change of target starts the list of copies again. */
function rrt_off_target( $s = null ) {
	$s = $s ? $s : rrt_off_settings();
	$t = array( $s['mode'], rrt_off_base( $s ) );
	if ( 'bunny' === $s['mode'] ) {
		$t[] = $s['b_zone'];
		$t[] = $s['b_region'];
	} elseif ( 's3' === $s['mode'] ) {
		$t[] = untrailingslashit( $s['s3_endpoint'] );
		$t[] = $s['s3_bucket'];
	}
	return substr( md5( implode( '|', $t ) ), 0, 12 );
}

/** Whether offload is switched on and working: addresses may point outside. */
function rrt_off_on( $s = null ) {
	$s = $s ? $s : rrt_off_settings();
	return ! empty( $s['on'] ) && empty( $s['down'] ) && rrt_off_target( $s ) === $s['passed'] && ! rrt_off_missing( $s );
}

/*
 * ---------------------------------------------------------------------
 * Which files
 * ---------------------------------------------------------------------
 */

/** Folders under uploads that never leave the site. */
function rrt_off_private_dirs() {
	return array( 'rrt-members', 'rrt-kept', 'rrt-audiobook', 'rrt-sent', 'rrt-shares', 'rrt-whisper', 'rrt-assets', RRT_OFF_CHECK_DIR );
}

/** The types a copy may have, by extension. */
function rrt_off_types() {
	return array(
		'mp3'  => 'audio/mpeg',
		'm4a'  => 'audio/mp4',
		'mp4'  => 'video/mp4',
		'm4v'  => 'video/mp4',
		'ogg'  => 'audio/ogg',
		'opus' => 'audio/ogg',
		'wav'  => 'audio/wav',
		'vtt'  => 'text/vtt',
		'json' => 'application/json',
	);
}

/** An uploads-relative path's type, or '' when it may not leave the site. */
function rrt_off_type( $rel ) {
	$rel = ltrim( (string) $rel, '/' );
	if ( '' === $rel || false !== strpos( $rel, '..' ) ) {
		return '';
	}
	$top = strtok( $rel, '/' );
	if ( in_array( $top, rrt_off_private_dirs(), true ) ) {
		return '';
	}
	$ext   = strtolower( pathinfo( $rel, PATHINFO_EXTENSION ) );
	$types = rrt_off_types();
	return isset( $types[ $ext ] ) ? $types[ $ext ] : '';
}

/** A file address on this site's uploads as an uploads-relative path, or ''. */
function rrt_off_rel( $url ) {
	$url = (string) $url;
	if ( '' === $url ) {
		return '';
	}
	$up   = wp_upload_dir( null, false );
	$base = preg_replace( '#^https?:#i', '', untrailingslashit( $up['baseurl'] ) );
	$bare = preg_replace( '#^https?:#i', '', strtok( $url, '?#' ) );
	if ( 0 !== strpos( $bare, $base . '/' ) ) {
		return '';
	}
	$rel = rawurldecode( substr( $bare, strlen( $base ) + 1 ) );
	return false === strpos( $rel, '..' ) ? $rel : '';
}

/** An uploads-relative path's file on the site. */
function rrt_off_path( $rel ) {
	$up = wp_upload_dir( null, false );
	return trailingslashit( $up['basedir'] ) . ltrim( (string) $rel, '/' );
}

/**
 * Every file that should have a copy now: uploads-relative path => the post it belongs to.
 * Readings for everyone on published posts and collections, their captions, and their sponsored copies.
 */
function rrt_off_wanted() {
	if ( isset( $GLOBALS['rrt_off_wanted'] ) ) {
		return $GLOBALS['rrt_off_wanted'];
	}
	$out   = array();
	$posts = function_exists( 'rrt_podcast_posts' ) ? rrt_podcast_posts() : array();
	/* Every published collection, in the feed or not: its page has a player too. */
	if ( defined( 'RRT_COL_TYPE' ) ) {
		$have = wp_list_pluck( $posts, 'ID' );
		foreach ( get_posts(
			array(
				'post_type'        => RRT_COL_TYPE,
				'post_status'      => 'publish',
				'has_password'     => false,
				'numberposts'      => 300,
				'suppress_filters' => false,
			)
		) as $c ) {
			if ( ! in_array( $c->ID, $have, true ) ) {
				$posts[] = $c;
			}
		}
	}
	foreach ( $posts as $p ) {
		if ( function_exists( 'rrt_access_locked' ) && rrt_access_locked( $p->ID ) ) {
			continue;
		}
		$add = array( rrt_reading_audio( $p->ID )['url'], rrt_reading_captions( $p->ID )['url'] );
		if ( function_exists( 'rrt_spot_built' ) && defined( 'RRT_SPOT_DIR' ) ) {
			$b = rrt_spot_built( $p->ID );
			if ( $b ) {
				foreach ( array( 'rel', 'vtt', 'json' ) as $k ) {
					if ( ! empty( $b[ $k ] ) ) {
						$add[] = rrt_spot_url( $b[ $k ] );
					}
				}
			}
		}
		foreach ( $add as $u ) {
			$rel = rrt_off_rel( $u );
			/* 1.25.0: never a file in a private folder or one whose .htaccess turns visitors away (rrt_upload_path_ok). */
			if ( '' !== $rel && '' !== rrt_off_type( $rel ) && is_file( rrt_off_path( $rel ) ) && rrt_upload_path_ok( rrt_off_path( $rel ) ) && ! isset( $out[ $rel ] ) ) {
				$out[ $rel ] = (int) $p->ID;
			}
		}
	}
	$GLOBALS['rrt_off_wanted'] = $out;
	return $out;
}

/** Forgets this request's list of wanted files (after something changed). */
function rrt_off_forget() {
	unset( $GLOBALS['rrt_off_wanted'] );
}

/*
 * ---------------------------------------------------------------------
 * The list of copies
 * ---------------------------------------------------------------------
 */

/**
 * The copies, for the current target only.
 *
 * Each record: t (target), k (key, or the path from the site's root in CDN
 * mode), h (hash), b (bytes), m (the file's time), post, up (uploaded),
 * ok (checked), gone (first seen unwanted), err, tries.
 */
function rrt_off_files() {
	$f = get_option( RRT_OFF_FILES, array() );
	return is_array( $f ) ? $f : array();
}

/** Saves the list of copies. */
function rrt_off_files_save( $f ) {
	update_option( RRT_OFF_FILES, $f, false );
}

/** The first ten characters of a file's SHA-256. */
function rrt_off_hash( $path ) {
	$h = @hash_file( 'sha256', $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	return $h ? substr( $h, 0, 10 ) : '';
}

/** A copy's key: content-named in a bucket, the file's own path through a CDN. */
function rrt_off_key( $rel, $hash, $s = null ) {
	$s = $s ? $s : rrt_off_settings();
	if ( 'cdn' === $s['mode'] ) {
		$up   = wp_upload_dir( null, false );
		$path = (string) wp_parse_url( $up['baseurl'], PHP_URL_PATH );
		return ltrim( untrailingslashit( $path ) . '/' . ltrim( $rel, '/' ), '/' );
	}
	return RRT_OFF_PREFIX . '/' . $hash . '/' . wp_basename( $rel );
}

/** A key's address on the public side. */
function rrt_off_key_url( $key, $hash = '', $s = null ) {
	$s   = $s ? $s : rrt_off_settings();
	$url = rrt_off_base( $s ) . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $key ) ) );
	return ( 'cdn' === $s['mode'] && '' !== $hash ) ? $url . '?v=' . $hash : $url;
}

/** A record's public address. */
function rrt_off_rec_url( $r, $s = null ) {
	return rrt_off_key_url( $r['k'], $r['h'], $s );
}

/** Whether a record is a checked copy of the file as it is now. */
function rrt_off_rec_fresh( $r, $rel, $target ) {
	if ( empty( $r['ok'] ) || ! isset( $r['t'] ) || $r['t'] !== $target ) {
		return false;
	}
	$path = rrt_off_path( $rel );
	clearstatcache( true, $path );
	return is_file( $path ) && (int) filesize( $path ) === (int) $r['b'] && (int) filemtime( $path ) === (int) $r['m'];
}

/**
 * Whether a captions or chapters file stays on the site on purpose: the
 * storage served its copy under another type (Bunny Storage serves .vtt as a
 * download), and the file has not changed since.
 */
function rrt_off_rec_local( $r, $rel, $target ) {
	if ( empty( $r['local'] ) || ! isset( $r['t'] ) || $r['t'] !== $target ) {
		return false;
	}
	$path = rrt_off_path( $rel );
	clearstatcache( true, $path );
	return is_file( $path ) && (int) filesize( $path ) === (int) $r['b'] && (int) filemtime( $path ) === (int) $r['m'];
}

/*
 * ---------------------------------------------------------------------
 * Addresses
 * ---------------------------------------------------------------------
 */

/**
 * A file's address for listeners: its outside copy when there is a checked
 * one, else the address as it is.
 *
 * @param string $url   An address on this site.
 * @param string $where feed (podcast apps, and the short address) or player.
 */
function rrt_off_url( $url, $where = 'feed' ) {
	$url = (string) $url;
	if ( '' === $url ) {
		return $url;
	}
	$s = rrt_off_settings();
	if ( ! rrt_off_on( $s ) || ( 'player' === $where && 'feeds' === $s['use'] ) ) {
		return $url;
	}
	$rel = rrt_off_rel( $url );
	if ( '' === $rel || '' === rrt_off_type( $rel ) ) {
		return $url;
	}
	$f = rrt_off_files();
	if ( ! isset( $f[ $rel ] ) || ! rrt_off_rec_fresh( $f[ $rel ], $rel, rrt_off_target( $s ) ) ) {
		return $url;
	}
	return rrt_off_rec_url( $f[ $rel ], $s );
}

/** A feed episode with its files' outside copies (called after sponsor messages, before member feeds). */
function rrt_off_episode( $e, $post ) {
	if ( ! $e || ! rrt_off_on() ) {
		return $e;
	}
	$was      = $e['url'];
	$e['url'] = rrt_off_url( $e['url'] );
	/* The short address that counts downloads stays on the site; it sends apps on (rrt_dl_serve). */
	if ( $e['enclosure'] === $was ) {
		$e['enclosure'] = $e['url'];
	}
	if ( ! empty( $e['vtt'] ) ) {
		$e['vtt'] = rrt_off_url( $e['vtt'] );
	}
	/* A sponsored copy's chapters are a file; a reading's are answered by the plugin and stay. */
	if ( ! empty( $e['chapters'] ) && '' !== rrt_off_rel( $e['chapters'] ) ) {
		$e['chapters'] = rrt_off_url( $e['chapters'] );
	}
	return $e;
}

/*
 * ---------------------------------------------------------------------
 * Talking to the storage
 * ---------------------------------------------------------------------
 */

/** A request's error as one line. */
function rrt_off_err( $r, $what ) {
	if ( is_wp_error( $r ) ) {
		/* translators: 1: what was being done, 2: the error. */
		return sprintf( __( '%1$s did not reach the storage: %2$s', 'foxfire-studio' ), $what, $r->get_error_message() );
	}
	$code = (int) wp_remote_retrieve_response_code( $r );
	$body = (string) wp_remote_retrieve_body( $r );
	$msg  = '';
	if ( preg_match( '#<Message>(.*?)</Message>#s', $body, $m ) ) {
		$msg = $m[1];
	} elseif ( preg_match( '#"Message"\s*:\s*"([^"]+)"#', $body, $m ) ) {
		$msg = $m[1];
	}
	$hint = '';
	if ( 401 === $code || 403 === $code ) {
		$hint = __( 'The key or password was refused, or it may not write here.', 'foxfire-studio' );
	} elseif ( 404 === $code ) {
		$hint = __( 'The bucket or storage zone was not found: check its name and region.', 'foxfire-studio' );
	}
	/* translators: 1: what was being done, 2: the HTTP status code, 3: the storage's own words and a hint. */
	return trim( sprintf( __( '%1$s was refused (%2$d). %3$s', 'foxfire-studio' ), $what, $code, trim( wp_strip_all_tags( $msg ) . ' ' . $hint ) ) );
}

/** Bunny Storage's API address for a key. */
function rrt_off_bunny_url( $key, $s ) {
	$regions = rrt_off_bunny_regions();
	$host    = isset( $regions[ $s['b_region'] ] ) ? $regions[ $s['b_region'] ][1] : 'storage.bunnycdn.com';
	/** Filters Bunny Storage's API base (used by the tests). */
	$api = (string) apply_filters( 'rrt_offload_bunny_api', 'https://' . $host );
	return untrailingslashit( $api ) . '/' . rawurlencode( trim( $s['b_zone'] ) ) . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $key ) ) );
}

/**
 * A signed S3 request (AWS Signature Version 4).
 *
 * @param string $method  GET, HEAD, PUT, POST or DELETE.
 * @param string $key     The object's key, or '' for the bucket.
 * @param array  $query   Query parameters.
 * @param string $body    The body.
 * @param array  $headers More headers to sign and send.
 * @param array  $s       The settings.
 * @param int    $now     The time to sign with (tests).
 * @return array url, headers (to send, with authorization)
 */
function rrt_off_s3_sign( $method, $key, $query, $body, $headers, $s, $now = null ) {
	$now      = null === $now ? time() : (int) $now;
	$endpoint = untrailingslashit( trim( (string) $s['s3_endpoint'] ) );
	$host     = (string) wp_parse_url( $endpoint, PHP_URL_HOST );
	$port     = wp_parse_url( $endpoint, PHP_URL_PORT );
	$scheme   = (string) wp_parse_url( $endpoint, PHP_URL_SCHEME );
	$bucket   = trim( (string) $s['s3_bucket'] );
	$pre      = untrailingslashit( (string) wp_parse_url( $endpoint, PHP_URL_PATH ) );
	$enc      = implode( '/', array_map( 'rawurlencode', explode( '/', (string) $key ) ) );
	/* Amazon's own endpoints take the bucket in the host name; everything else in the path. */
	if ( preg_match( '/(^|\.)amazonaws\.com$/i', $host ) ) {
		$host = $bucket . '.' . $host;
		$path = '/' . $enc;
	} else {
		$path = '/' . rawurlencode( $bucket ) . ( '' !== (string) $key ? '/' . $enc : '' );
	}
	$path     = $pre . $path; // An endpoint behind a path of its own (a proxy, or the tests' stand-in).

	$hostport = $host . ( $port ? ':' . $port : '' );
	ksort( $query );
	$qs = array();
	foreach ( $query as $k => $v ) {
		$qs[] = rawurlencode( $k ) . '=' . rawurlencode( (string) $v );
	}
	$qs     = implode( '&', $qs );
	$date   = gmdate( 'Ymd\THis\Z', $now );
	$day    = substr( $date, 0, 8 );
	$region = '' !== trim( (string) $s['s3_region'] ) ? trim( (string) $s['s3_region'] ) : 'auto';
	$h      = array_change_key_case( (array) $headers, CASE_LOWER );
	if ( '' !== (string) $body && empty( $h['content-type'] ) ) {
		/* Left out, the HTTP library would label the body as a web form, and some servers then read it as empty. */
		$h['content-type'] = 'application/octet-stream';
	}
	$h['host']                 = $hostport;
	$h['x-amz-date']           = $date;
	$h['x-amz-content-sha256'] = isset( $h['x-amz-content-sha256'] ) ? $h['x-amz-content-sha256'] : hash( 'sha256', (string) $body );
	ksort( $h );
	$canon_h = '';
	foreach ( $h as $k => $v ) {
		$canon_h .= $k . ':' . trim( preg_replace( '/\s+/', ' ', (string) $v ) ) . "\n";
	}
	$signed = implode( ';', array_keys( $h ) );
	$canon  = $method . "\n" . $path . "\n" . $qs . "\n" . $canon_h . "\n" . $signed . "\n" . $h['x-amz-content-sha256'];
	$scope  = $day . '/' . $region . '/s3/aws4_request';
	$sts    = "AWS4-HMAC-SHA256\n" . $date . "\n" . $scope . "\n" . hash( 'sha256', $canon );
	$k      = hash_hmac( 'sha256', $day, 'AWS4' . rrt_off_secret( 'secret', $s ), true );
	$k      = hash_hmac( 'sha256', $region, $k, true );
	$k      = hash_hmac( 'sha256', 's3', $k, true );
	$k      = hash_hmac( 'sha256', 'aws4_request', $k, true );
	$h['authorization'] = 'AWS4-HMAC-SHA256 Credential=' . rrt_off_secret( 'key', $s ) . '/' . $scope . ', SignedHeaders=' . $signed . ', Signature=' . hash_hmac( 'sha256', $sts, $k );
	unset( $h['host'] );
	return array(
		'url'     => ( '' !== $scheme ? $scheme : 'https' ) . '://' . $hostport . $path . ( '' !== $qs ? '?' . $qs : '' ),
		'headers' => $h,
	);
}

/** Sends a signed S3 request. */
function rrt_off_s3( $method, $key, $query = array(), $body = '', $headers = array(), $s = null ) {
	$s   = $s ? $s : rrt_off_settings();
	$req = rrt_off_s3_sign( $method, $key, $query, $body, $headers, $s );
	return wp_remote_request(
		$req['url'],
		array(
			'method'      => $method,
			'headers'     => $req['headers'],
			'body'        => '' === (string) $body ? null : $body,
			'timeout'     => 120,
			'redirection' => 0,
		)
	);
}

/** Sends a Bunny Storage request. */
function rrt_off_bunny( $method, $key, $body = '', $headers = array(), $s = null ) {
	$s = $s ? $s : rrt_off_settings();
	return wp_remote_request(
		rrt_off_bunny_url( $key, $s ),
		array(
			'method'      => $method,
			'headers'     => array_merge( array( 'AccessKey' => rrt_off_secret( 'secret', $s ) ), $headers ),
			'body'        => '' === (string) $body ? null : $body,
			'timeout'     => 120,
			'redirection' => 0,
		)
	);
}

/** Whether a response is a success. */
function rrt_off_good( $r ) {
	if ( is_wp_error( $r ) ) {
		return false;
	}
	$c = (int) wp_remote_retrieve_response_code( $r );
	return $c >= 200 && $c < 300;
}

/**
 * Puts a file (or a string) into the storage under a key.
 *
 * @param string $key  The key.
 * @param string $path The file, or '' to send $data.
 * @param string $type Its type.
 * @param string $data The bytes when there is no file.
 * @return true|string True, or why not.
 */
function rrt_off_put( $key, $path, $type, $data = '', $s = null ) {
	$s    = $s ? $s : rrt_off_settings();
	$what = __( 'The upload', 'foxfire-studio' );
	$size = '' !== $path ? (int) filesize( $path ) : strlen( $data );
	if ( 's3' === $s['mode'] ) {
		$head = array(
			'Content-Type'  => $type,
			'Cache-Control' => RRT_OFF_CACHE,
		);
		/** Filters the size above which uploads to S3 go in parts (used by the tests). */
		if ( '' !== $path && $size > (int) apply_filters( 'rrt_offload_part_over', RRT_OFF_PART_OVER ) ) {
			return rrt_off_s3_parts( $key, $path, $head, $s );
		}
		$body = '' !== $path ? (string) file_get_contents( $path ) : $data; // phpcs:ignore WordPress.WP.AlternativeFunctions
		$r    = rrt_off_s3( 'PUT', $key, array(), $body, $head, $s );
		return rrt_off_good( $r ) ? true : rrt_off_err( $r, $what );
	}
	if ( 'bunny' === $s['mode'] ) {
		$body = '' !== $path ? (string) file_get_contents( $path ) : $data; // phpcs:ignore WordPress.WP.AlternativeFunctions
		$r    = rrt_off_bunny(
			'PUT',
			$key,
			$body,
			array(
				'Content-Type' => $type,
				'Checksum'     => strtoupper( hash( 'sha256', $body ) ),
			),
			$s
		);
		return rrt_off_good( $r ) ? true : rrt_off_err( $r, $what );
	}
	return true; // A CDN copies from the site by itself.
}

/** A large file into S3 in parts. */
function rrt_off_s3_parts( $key, $path, $head, $s ) {
	$what = __( 'The upload', 'foxfire-studio' );
	$r    = rrt_off_s3( 'POST', $key, array( 'uploads' => '' ), '', $head, $s );
	if ( ! rrt_off_good( $r ) || ! preg_match( '#<UploadId>(.*?)</UploadId>#', (string) wp_remote_retrieve_body( $r ), $m ) ) {
		return rrt_off_err( $r, $what );
	}
	$id  = $m[1];
	$fh  = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$n   = 0;
	$xml = '<CompleteMultipartUpload>';
	while ( $fh && ! feof( $fh ) ) {
		$chunk = fread( $fh, RRT_OFF_PART ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( '' === $chunk || false === $chunk ) {
			break;
		}
		++$n;
		$p = rrt_off_s3(
			'PUT',
			$key,
			array(
				'partNumber' => $n,
				'uploadId'   => $id,
			),
			$chunk,
			array(),
			$s
		);
		$etag = rrt_off_good( $p ) ? (string) wp_remote_retrieve_header( $p, 'etag' ) : '';
		if ( '' === $etag ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			rrt_off_s3( 'DELETE', $key, array( 'uploadId' => $id ), '', array(), $s );
			return rrt_off_err( $p, $what );
		}
		$xml .= '<Part><PartNumber>' . $n . '</PartNumber><ETag>' . esc_html( $etag ) . '</ETag></Part>';
	}
	if ( $fh ) {
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
	$xml .= '</CompleteMultipartUpload>';
	$r    = rrt_off_s3( 'POST', $key, array( 'uploadId' => $id ), $xml, array( 'Content-Type' => 'application/xml' ), $s );
	/* A completion can answer 200 with an error inside. */
	if ( ! rrt_off_good( $r ) || false !== strpos( (string) wp_remote_retrieve_body( $r ), '<Error>' ) ) {
		rrt_off_s3( 'DELETE', $key, array( 'uploadId' => $id ), '', array(), $s );
		return rrt_off_err( $r, $what );
	}
	return true;
}

/** Deletes a key from the storage (nothing to do through a CDN), then clears it from the CDN when a purge key is set. */
function rrt_off_del( $key, $hash = '', $s = null ) {
	$s  = $s ? $s : rrt_off_settings();
	$ok = true;
	if ( 's3' === $s['mode'] ) {
		$r  = rrt_off_s3( 'DELETE', $key, array(), '', array(), $s );
		$ok = rrt_off_good( $r ) || 404 === (int) wp_remote_retrieve_response_code( $r );
	} elseif ( 'bunny' === $s['mode'] ) {
		$r  = rrt_off_bunny( 'DELETE', $key, '', array(), $s );
		$ok = rrt_off_good( $r ) || 404 === (int) wp_remote_retrieve_response_code( $r );
	}
	rrt_off_purge( rrt_off_key_url( $key, $hash, $s ), $s );
	return $ok;
}

/** Clears an address from a bunny.net pull zone at once, when an account API key is set. */
function rrt_off_purge( $url, $s = null ) {
	$s   = $s ? $s : rrt_off_settings();
	$key = rrt_off_secret( 'purge', $s );
	if ( '' === $key ) {
		return null;
	}
	/** Filters bunny.net's purge address (used by the tests). */
	$api = (string) apply_filters( 'rrt_offload_purge_api', 'https://api.bunny.net/purge' );
	$r   = wp_remote_post(
		add_query_arg( 'url', rawurlencode( strtok( $url, '?' ) ), $api ),
		array(
			'headers' => array( 'AccessKey' => $key ),
			'timeout' => 20,
		)
	);
	return rrt_off_good( $r );
}

/**
 * Checks a copy through the public address: it answers, with the right size and type.
 *
 * @return true|string True, or what was wrong.
 */
function rrt_off_check( $url, $bytes, $type, &$why = null ) {
	$why = '';
	$r = wp_remote_head(
		$url,
		array(
			'timeout'     => 20,
			'redirection' => 3,
			'headers'     => array( 'Cache-Control' => 'no-cache' ),
		)
	);
	if ( is_wp_error( $r ) ) {
		/* translators: %s: the error. */
		return sprintf( __( 'The public address did not answer: %s', 'foxfire-studio' ), $r->get_error_message() );
	}
	$code = (int) wp_remote_retrieve_response_code( $r );
	if ( 200 !== $code ) {
		/* translators: %d: the HTTP status code. */
		return sprintf( __( 'The public address answered %d. Check that the bucket or pull zone lets anyone read its files.', 'foxfire-studio' ), $code );
	}
	$len = (string) wp_remote_retrieve_header( $r, 'content-length' );
	if ( '' !== $len && (int) $len !== (int) $bytes ) {
		/* translators: 1: the size the address gave, 2: the file's size. */
		return sprintf( __( 'The public address gave %1$d bytes for a file of %2$d.', 'foxfire-studio' ), (int) $len, (int) $bytes );
	}
	$got = strtolower( trim( strtok( (string) wp_remote_retrieve_header( $r, 'content-type' ), ';' ) ) );
	$want = strtolower( $type );
	$alike = array(
		'audio/mpeg' => array( 'audio/mpeg', 'audio/mp3' ),
		'audio/mp4'  => array( 'audio/mp4', 'audio/x-m4a', 'audio/m4a' ),
		'text/vtt'   => array( 'text/vtt' ),
	);
	$ok = isset( $alike[ $want ] ) ? in_array( $got, $alike[ $want ], true ) : ( '' === $got || $got === $want );
	if ( ! $ok ) {
		$why = 'type';
		/* translators: 1: the type the address gave, 2: the type it should be. */
		return sprintf( __( 'The public address calls the file %1$s, and podcast apps need %2$s.', 'foxfire-studio' ), '' !== $got ? $got : __( 'nothing', 'foxfire-studio' ), $type );
	}
	return true;
}

/*
 * ---------------------------------------------------------------------
 * Keeping the copies up to date
 * ---------------------------------------------------------------------
 */

/**
 * Brings the copies up to date: uploads and checks the files waiting (as many
 * as fit in the time), and deletes copies whose files left public use.
 *
 * @param float $secs Time allowed for uploads.
 * @param int   $max  The most uploads.
 * @param int   $now  The time (tests).
 * @return array done, waiting, deleted, errors (path => why)
 */
function rrt_off_sync( $secs = 20.0, $max = 50, $now = null ) {
	$now = null === $now ? time() : (int) $now;
	$s   = rrt_off_settings();
	$out = array(
		'done'    => 0,
		'waiting' => 0,
		'deleted' => 0,
		'errors'  => array(),
	);
	if ( empty( $s['on'] ) || rrt_off_target( $s ) !== $s['passed'] || rrt_off_missing( $s ) ) {
		return $out;
	}
	$target  = rrt_off_target( $s );
	$f       = rrt_off_files();
	rrt_off_forget();
	$want    = rrt_off_wanted();
	$changed = array();
	$start   = microtime( true );
	/* Copies made for another target belong to the old storage: forget them (they stay there). */
	foreach ( $f as $rel => $r ) {
		if ( ! isset( $r['t'] ) || $r['t'] !== $target ) {
			unset( $f[ $rel ] );
		}
	}
	/* Copies whose files left public use. */
	foreach ( $f as $rel => $r ) {
		if ( isset( $want[ $rel ] ) ) {
			if ( ! empty( $r['gone'] ) ) {
				unset( $f[ $rel ]['gone'] );
			}
			continue;
		}
		$path  = rrt_off_path( $rel );
		$owner = isset( $r['post'] ) ? (int) $r['post'] : 0;
		/* Replaced on a reading that is still public: apps may be playing the old file for a while. */
		$grace = is_file( $path ) && $owner && 'publish' === get_post_status( $owner ) && ! ( function_exists( 'rrt_access_locked' ) && rrt_access_locked( $owner ) ) && '' !== rrt_reading_audio( $owner )['url'];
		if ( $grace ) {
			if ( empty( $r['gone'] ) ) {
				$f[ $rel ]['gone'] = $now;
				continue;
			}
			if ( $now - (int) $r['gone'] < RRT_OFF_GRACE ) {
				continue;
			}
		}
		if ( empty( $r['up'] ) || rrt_off_del( $r['k'], $r['h'], $s ) ) {
			unset( $f[ $rel ] );
			++$out['deleted'];
			$changed[ $owner ] = true;
		}
	}
	/* Files waiting for a copy. */
	foreach ( $want as $rel => $post ) {
		if ( isset( $f[ $rel ] ) && ( rrt_off_rec_fresh( $f[ $rel ], $rel, $target ) || rrt_off_rec_local( $f[ $rel ], $rel, $target ) ) ) {
			continue;
		}
		if ( $out['done'] >= $max || microtime( true ) - $start > $secs ) {
			++$out['waiting'];
			continue;
		}
		$r = rrt_off_copy( $rel, $post, isset( $f[ $rel ] ) ? $f[ $rel ] : null, $s, $now );
		$f[ $rel ] = $r;
		if ( ! empty( $r['ok'] ) ) {
			++$out['done'];
			$changed[ $post ] = true;
		} elseif ( ! empty( $r['local'] ) ) {
			++$out['done'];
		} else {
			++$out['waiting'];
			$out['errors'][ $rel ] = (string) $r['err'];
		}
	}
	rrt_off_files_save( $f );
	if ( $changed ) {
		rrt_off_purge_pages( array_keys( $changed ) );
	}
	return $out;
}

/** Makes and checks one file's copy, returning its record. */
function rrt_off_copy( $rel, $post, $old, $s, $now ) {
	$path = rrt_off_path( $rel );
	clearstatcache( true, $path );
	$type = rrt_off_type( $rel );
	$hash = rrt_off_hash( $path );
	$r    = array(
		't'     => rrt_off_target( $s ),
		'k'     => rrt_off_key( $rel, $hash, $s ),
		'h'     => $hash,
		'b'     => (int) filesize( $path ),
		'm'     => (int) filemtime( $path ),
		'post'  => (int) $post,
		'up'    => 0,
		'ok'    => 0,
		'err'   => '',
		'tries' => $old && isset( $old['tries'] ) ? (int) $old['tries'] + 1 : 1,
	);
	/* The file changed in place: its old copy goes (a new content means a new key). */
	if ( $old && ! empty( $old['up'] ) && isset( $old['k'] ) && $old['k'] !== $r['k'] ) {
		rrt_off_del( $old['k'], $old['h'], $s );
	}
	/* The same content is already there (the list was lost, or the file was touched): only check it. */
	$there = $old && ! empty( $old['up'] ) && isset( $old['k'] ) && $old['k'] === $r['k'];
	if ( ! $there ) {
		$put = rrt_off_put( $r['k'], $path, $type, '', $s );
		if ( true !== $put ) {
			$r['err'] = $put;
			return $r;
		}
	}
	$r['up'] = $now;
	$why     = '';
	$chk     = rrt_off_check( rrt_off_rec_url( $r, $s ), $r['b'], $type, $why );
	/*
	 * A captions or chapters file the storage serves under another type
	 * (Bunny Storage gives .vtt as a download) stays on the site: it is small,
	 * and browsers and apps go by its type. Its copy goes.
	 */
	if ( true !== $chk && 'type' === $why && in_array( $type, array( 'text/vtt', 'application/json' ), true ) ) {
		rrt_off_del( $r['k'], $r['h'], $s );
		$r['up']    = 0;
		$r['local'] = $now;
		$r['tries'] = 0;
		return $r;
	}
	if ( true !== $chk ) {
		$r['err'] = $chk;
		return $r;
	}
	$r['ok']    = $now;
	$r['tries'] = 0;
	return $r;
}

/** Asks the cache to drop the feeds and the pages whose files changed. */
function rrt_off_purge_pages( $posts ) {
	if ( function_exists( 'rrt_podcast_purge' ) ) {
		rrt_podcast_purge();
	}
	foreach ( array_filter( array_map( 'intval', (array) $posts ) ) as $id ) {
		do_action( 'litespeed_purge_post', $id );
	}
}

/** Asks for the copies to be brought up to date at the end of this request. */
function rrt_off_queue() {
	if ( ! empty( $GLOBALS['rrt_off_queued'] ) ) {
		return;
	}
	$s = rrt_off_settings();
	if ( empty( $s['on'] ) ) {
		return;
	}
	$GLOBALS['rrt_off_queued'] = true;
	add_action(
		'shutdown',
		function () {
			/* After the page has gone to the browser, and only for the owner's own changes (never a visitor's request). */
			if ( ! current_user_can( 'edit_posts' ) && ! wp_doing_cron() ) {
				return;
			}
			if ( function_exists( 'fastcgi_finish_request' ) ) {
				fastcgi_finish_request();
			}
			rrt_off_sync( 20.0, 10 );
		},
		30
	);
}

/** A reading's file, captions, access, sponsored copy or collection changed. */
function rrt_off_meta_changed( $meta_id, $post_id, $key ) {
	$keys = array( 'rrt_audio', 'rrt_captions' );
	foreach ( array( 'RRT_ACCESS_META', 'RRT_SPOT_BUILT', 'RRT_COL_BUILT' ) as $c ) {
		if ( defined( $c ) ) {
			$keys[] = constant( $c );
		}
	}
	if ( function_exists( 'rrt_feed_audio_keys' ) ) {
		$keys = array_merge( $keys, rrt_feed_audio_keys() );
	}
	if ( in_array( $key, $keys, true ) ) {
		rrt_off_queue();
	}
}
add_action( 'added_post_meta', 'rrt_off_meta_changed', 50, 3 );
add_action( 'updated_post_meta', 'rrt_off_meta_changed', 50, 3 );
add_action( 'deleted_post_meta', 'rrt_off_meta_changed', 50, 3 );

add_action(
	'transition_post_status',
	function ( $new, $old ) {
		if ( $new !== $old ) {
			rrt_off_queue();
		}
	},
	20,
	2
);
add_action( 'before_delete_post', 'rrt_off_queue' );
add_action( 'delete_attachment', 'rrt_off_queue' );

/**
 * The hourly job: brings the copies up to date, then checks one copy. After
 * three failed checks in a row, every address goes back to the site.
 */
function rrt_off_hourly( $now = null ) {
	$s = rrt_off_settings();
	if ( empty( $s['on'] ) ) {
		return;
	}
	if ( empty( $s['down'] ) ) {
		rrt_off_sync( 20.0, 50, $now );
	}
	rrt_off_health( $now );
}
add_action( 'rrt_share_hourly', 'rrt_off_hourly', 30 );

/** Checks one copy (the oldest checked) through the public address and keeps count of failures. */
function rrt_off_health( $now = null ) {
	$now = null === $now ? time() : (int) $now;
	$s   = rrt_off_settings();
	if ( empty( $s['on'] ) || rrt_off_target( $s ) !== $s['passed'] ) {
		return null;
	}
	$f      = rrt_off_files();
	$target = rrt_off_target( $s );
	$pick   = null;
	foreach ( $f as $rel => $r ) {
		if ( ! empty( $r['ok'] ) && isset( $r['t'] ) && $r['t'] === $target && ( ! $pick || (int) $r['ok'] < (int) $f[ $pick ]['ok'] ) ) {
			$pick = $rel;
		}
	}
	if ( null === $pick ) {
		return null;
	}
	$chk       = rrt_off_check( rrt_off_rec_url( $f[ $pick ], $s ), $f[ $pick ]['b'], rrt_off_type( $pick ) );
	$s['seen'] = $now;
	if ( true === $chk ) {
		$f[ $pick ]['ok'] = $now;
		rrt_off_files_save( $f );
		$back          = ! empty( $s['down'] );
		$s['fails']    = 0;
		$s['down']     = 0;
		$s['down_why'] = '';
		rrt_off_save( $s );
		if ( $back ) {
			rrt_off_purge_all();
		}
		return true;
	}
	$s['fails'] = (int) $s['fails'] + 1;
	if ( $s['fails'] >= RRT_OFF_FAILS && empty( $s['down'] ) ) {
		$s['down']     = $now;
		$s['down_why'] = $chk;
		rrt_off_save( $s );
		rrt_off_purge_all();
		return false;
	}
	rrt_off_save( $s );
	return false;
}

/** Asks the cache to drop everything (every page's player may point elsewhere now). */
function rrt_off_purge_all() {
	do_action( 'litespeed_purge_all' );
	if ( function_exists( 'rrt_podcast_purge' ) ) {
		rrt_podcast_purge();
	}
}

/** Counts for the screen: files outside, their size, waiting, with reasons. */
function rrt_off_status() {
	$s      = rrt_off_settings();
	$target = rrt_off_target( $s );
	$f      = rrt_off_files();
	$want   = rrt_off_wanted();
	$out    = array(
		'outside' => 0,
		'bytes'   => 0,
		'newest'  => 0,
		'wanted'  => count( $want ),
		'waiting' => array(),
		'leaving' => 0,
		'local'   => 0,
	);
	foreach ( $want as $rel => $post ) {
		if ( isset( $f[ $rel ] ) && rrt_off_rec_fresh( $f[ $rel ], $rel, $target ) ) {
			++$out['outside'];
			$out['bytes'] += (int) $f[ $rel ]['b'];
			$out['newest'] = max( $out['newest'], (int) $f[ $rel ]['ok'] );
		} elseif ( isset( $f[ $rel ] ) && rrt_off_rec_local( $f[ $rel ], $rel, $target ) ) {
			++$out['local'];
		} else {
			$out['waiting'][ $rel ] = isset( $f[ $rel ]['err'] ) && isset( $f[ $rel ]['t'] ) && $f[ $rel ]['t'] === $target ? (string) $f[ $rel ]['err'] : '';
		}
	}
	foreach ( $f as $rel => $r ) {
		if ( ! isset( $want[ $rel ] ) && ! empty( $r['up'] ) ) {
			++$out['leaving'];
		}
	}
	return $out;
}

/** Whether a post's reading is served from outside now. */
function rrt_off_post_line( $post_id ) {
	if ( ! rrt_off_on() ) {
		return '';
	}
	$url = rrt_reading_audio( $post_id )['url'];
	$rel = rrt_off_rel( $url );
	$f   = rrt_off_files();
	if ( '' !== $rel && isset( $f[ $rel ] ) && rrt_off_rec_fresh( $f[ $rel ], $rel, rrt_off_target() ) ) {
		/* translators: %s: a date. */
		return sprintf( __( 'Served from outside storage since %s.', 'foxfire-studio' ), wp_date( get_option( 'date_format' ), (int) $f[ $rel ]['up'] ) );
	}
	return __( 'Served from this site.', 'foxfire-studio' );
}

/** The privacy policy's line while offload is switched on, or ''. */
function rrt_off_privacy_text() {
	$s = rrt_off_settings();
	if ( empty( $s['on'] ) ) {
		return '';
	}
	$host = (string) wp_parse_url( rrt_off_base( $s ), PHP_URL_HOST );
	$out  = sprintf(
		/* translators: %s: the host name the readings come from, such as example.b-cdn.net. */
		__( 'The audio readings on this site and in its podcast feed are delivered from %s, a storage and content delivery service. When you play or download a reading, your browser or podcast app fetches the file from there, so that service sees your IP address and which app or browser you use, as any web server does. This site does not send it anything about you.', 'foxfire-studio' ),
		$host
	);
	if ( 'bunny' === $s['mode'] || preg_match( '/(^|\.)b-cdn\.net$/i', $host ) || '' !== rrt_off_secret( 'purge', $s ) ) {
		$out .= ' ' . __( 'The service, bunny.net, blurs IP addresses in its logs by default and keeps its logs for 3 days.', 'foxfire-studio' );
	}
	return $out;
}

/*
 * ---------------------------------------------------------------------
 * Check this setup
 * ---------------------------------------------------------------------
 */

/**
 * The site's half of Check this setup: puts a small test file in place,
 * reads it back through the public address (HEAD, then one byte range) and
 * returns its address for the browser to try (CORS). Done deletes it.
 *
 * @return array ok, steps (each ok and text), url, key
 */
function rrt_off_check_start() {
	$s     = rrt_off_settings();
	$steps = array();
	$miss  = rrt_off_missing( $s );
	if ( $miss ) {
		return array(
			'ok'    => false,
			'steps' => array(
				array(
					'ok'   => false,
					'text' => implode( ' ', $miss ),
				),
			),
		);
	}
	$code = strtolower( wp_generate_password( 8, false ) );
	/* A small MPEG frame header, repeated: enough for a type and a range. */
	$data = str_repeat( "\xFF\xFB\x90\x64" . str_repeat( "\0", 412 ), 8 );
	$hash = substr( hash( 'sha256', $data ), 0, 10 );
	if ( 'cdn' === $s['mode'] ) {
		$up  = wp_upload_dir( null, false );
		$rel = RRT_OFF_CHECK_DIR . '/check-' . $code . '.mp3';
		wp_mkdir_p( trailingslashit( $up['basedir'] ) . RRT_OFF_CHECK_DIR );
		$ok  = false !== file_put_contents( rrt_off_path( $rel ), $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$key = rrt_off_key( $rel, $hash, $s );
		$steps[] = array(
			'ok'   => $ok,
			'text' => $ok ? __( 'A test file was put on this site.', 'foxfire-studio' ) : __( 'A test file could not be written to the uploads folder.', 'foxfire-studio' ),
		);
	} else {
		$key = RRT_OFF_PREFIX . '/check-' . $code . '.mp3';
		$put = rrt_off_put( $key, '', 'audio/mpeg', $data, $s );
		$ok  = true === $put;
		$steps[] = array(
			'ok'   => $ok,
			'text' => $ok ? __( 'A test file was uploaded.', 'foxfire-studio' ) : $put,
		);
	}
	$url = rrt_off_key_url( $key, $hash, $s );
	if ( $ok ) {
		$chk     = rrt_off_check( $url, strlen( $data ), 'audio/mpeg' );
		$ok      = true === $chk;
		$steps[] = array(
			'ok'   => $ok,
			'text' => $ok ? __( 'It answers at the public address, with the right size and type.', 'foxfire-studio' ) : $chk,
		);
	}
	if ( $ok ) {
		$r = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array( 'Range' => 'bytes=0-9' ),
			)
		);
		$ok = ! is_wp_error( $r ) && 206 === (int) wp_remote_retrieve_response_code( $r ) && 10 === strlen( (string) wp_remote_retrieve_body( $r ) );
		$steps[] = array(
			'ok'   => $ok,
			'text' => $ok ? __( 'It answers a request for part of the file, as podcast apps need.', 'foxfire-studio' ) : __( 'It did not answer a request for part of the file (a byte range). Apple Podcasts needs this to play from the middle.', 'foxfire-studio' ),
		);
	}
	return array(
		'ok'    => $ok,
		'steps' => $steps,
		'url'   => $url,
		'key'   => $key,
		'hash'  => $hash,
	);
}

/** The end of Check this setup: deletes the test file and keeps the result. */
function rrt_off_check_done( $key, $hash, $site_ok, $cors_ok, $steps ) {
	$s = rrt_off_settings();
	if ( preg_match( '#^' . preg_quote( RRT_OFF_PREFIX, '#' ) . '/check-[a-z0-9]{8}\.mp3$#', $key ) ) {
		rrt_off_del( $key, '', $s );
	} elseif ( preg_match( '#/' . preg_quote( RRT_OFF_CHECK_DIR, '#' ) . '/(check-[a-z0-9]{8}\.mp3)$#', '/' . $key, $m ) ) {
		wp_delete_file( rrt_off_path( RRT_OFF_CHECK_DIR . '/' . $m[1] ) );
		@rmdir( rrt_off_path( RRT_OFF_CHECK_DIR ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions -- only when empty.
		rrt_off_purge( rrt_off_key_url( $key, $hash, $s ), $s );
	}
	$ok         = $site_ok && $cors_ok;
	$s['check'] = array(
		'at'    => time(),
		'ok'    => $ok,
		'steps' => array_slice( (array) $steps, 0, 8 ),
	);
	$s['passed'] = $ok ? rrt_off_target( $s ) : ( rrt_off_target( $s ) === $s['passed'] ? '' : $s['passed'] );
	if ( $ok ) {
		$s['fails']    = 0;
		$s['down']     = 0;
		$s['down_why'] = '';
	}
	rrt_off_save( $s );
	return $ok;
}

/** S3 CORS rules that let browsers read the copies (offered when the browser's try fails). */
function rrt_off_cors_xml() {
	$origin = untrailingslashit( home_url() );
	$scheme = (string) wp_parse_url( $origin, PHP_URL_SCHEME );
	$host   = (string) wp_parse_url( $origin, PHP_URL_HOST );
	return '<CORSConfiguration><CORSRule><AllowedOrigin>' . esc_html( $scheme . '://' . $host ) . '</AllowedOrigin><AllowedOrigin>*</AllowedOrigin><AllowedMethod>GET</AllowedMethod><AllowedMethod>HEAD</AllowedMethod><AllowedHeader>Range</AllowedHeader><ExposeHeader>Content-Range</ExposeHeader><ExposeHeader>Content-Length</ExposeHeader><ExposeHeader>Accept-Ranges</ExposeHeader><MaxAgeSeconds>86400</MaxAgeSeconds></CORSRule></CORSConfiguration>';
}

/** Sets the bucket's CORS rules (S3 only). */
function rrt_off_set_cors() {
	$s = rrt_off_settings();
	if ( 's3' !== $s['mode'] ) {
		return __( 'Only S3-compatible storage can be set from here.', 'foxfire-studio' );
	}
	$xml = rrt_off_cors_xml();
	$r   = rrt_off_s3(
		'PUT',
		'',
		array( 'cors' => '' ),
		$xml,
		array(
			'Content-Type' => 'application/xml',
			'Content-MD5'  => base64_encode( md5( $xml, true ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		),
		$s
	);
	return rrt_off_good( $r ) ? true : rrt_off_err( $r, __( 'Setting the CORS rules', 'foxfire-studio' ) );
}

/** Deletes up to $n outside copies; returns how many are left. */
function rrt_off_clear_step( $n = 5 ) {
	$s    = rrt_off_settings();
	$f    = rrt_off_files();
	$done = 0;
	foreach ( $f as $rel => $r ) {
		if ( $done >= $n ) {
			break;
		}
		if ( empty( $r['up'] ) || ! isset( $r['t'] ) || $r['t'] !== rrt_off_target( $s ) || rrt_off_del( $r['k'], $r['h'], $s ) ) {
			unset( $f[ $rel ] );
		}
		++$done;
	}
	rrt_off_files_save( $f );
	if ( $done ) {
		rrt_off_purge_all();
	}
	return count( $f );
}

/*
 * ---------------------------------------------------------------------
 * Routes for the browser
 * ---------------------------------------------------------------------
 */

add_action(
	'rest_api_init',
	function () {
		$can = function () {
			return current_user_can( 'manage_options' );
		};
		register_rest_route(
			'rrt/v1',
			'/offload/check',
			array(
				'methods'             => 'POST',
				'permission_callback' => $can,
				'callback'            => function () {
					return rest_ensure_response( rrt_off_check_start() );
				},
			)
		);
		register_rest_route(
			'rrt/v1',
			'/offload/check/done',
			array(
				'methods'             => 'POST',
				'permission_callback' => $can,
				'callback'            => function ( $req ) {
					$steps = array();
					foreach ( (array) $req['steps'] as $st ) {
						if ( is_array( $st ) ) {
							$steps[] = array(
								'ok'   => ! empty( $st['ok'] ),
								'text' => mb_substr( sanitize_text_field( (string) ( isset( $st['text'] ) ? $st['text'] : '' ) ), 0, 400 ),
							);
						}
					}
					$ok = rrt_off_check_done( (string) $req['key'], preg_replace( '/[^0-9a-f]/', '', (string) $req['hash'] ), ! empty( $req['site'] ), ! empty( $req['cors'] ), $steps );
					return rest_ensure_response( array( 'ok' => $ok ) );
				},
			)
		);
		register_rest_route(
			'rrt/v1',
			'/offload/cors',
			array(
				'methods'             => 'POST',
				'permission_callback' => $can,
				'callback'            => function () {
					$r = rrt_off_set_cors();
					return rest_ensure_response(
						array(
							'ok'   => true === $r,
							'text' => true === $r ? '' : $r,
							'xml'  => rrt_off_cors_xml(),
						)
					);
				},
			)
		);
		register_rest_route(
			'rrt/v1',
			'/offload/step',
			array(
				'methods'             => 'POST',
				'permission_callback' => $can,
				'callback'            => function () {
					$r  = rrt_off_sync( 25.0, 1 );
					$st = rrt_off_status();
					return rest_ensure_response(
						array(
							'done'    => $r['done'],
							'errors'  => $r['errors'],
							'outside' => $st['outside'],
							'wanted'  => $st['wanted'] - $st['local'],
							'local'   => $st['local'],
							'left'    => count( $st['waiting'] ),
						)
					);
				},
			)
		);
		register_rest_route(
			'rrt/v1',
			'/offload/clear',
			array(
				'methods'             => 'POST',
				'permission_callback' => $can,
				'callback'            => function () {
					return rest_ensure_response( array( 'left' => rrt_off_clear_step( 5 ) ) );
				},
			)
		);
		register_rest_route(
			'rrt/v1',
			'/offload/retry',
			array(
				'methods'             => 'POST',
				'permission_callback' => $can,
				'callback'            => function () {
					$ok = rrt_off_health();
					return rest_ensure_response(
						array(
							'ok'   => true === $ok,
							'down' => (bool) rrt_off_settings()['down'],
						)
					);
				},
			)
		);
	}
);

/*
 * ---------------------------------------------------------------------
 * The section on Podcast feed
 * ---------------------------------------------------------------------
 */

/** A secret's box: never shows the saved value. */
function rrt_off_secret_field( $name, $which, $saved, $label ) {
	if ( rrt_off_secret_in_config( $which ) ) {
		echo '<p class="description">' . esc_html__( 'Set in wp-config.php.', 'foxfire-studio' ) . '</p>';
		return;
	}
	$ph = '' !== $saved ? __( 'Saved. Paste a new one to replace it.', 'foxfire-studio' ) : '';
	echo '<input class="regular-text code" type="password" autocomplete="new-password" id="rrt-off-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="" placeholder="' . esc_attr( $ph ) . '" aria-label="' . esc_attr( $label ) . '">';
	if ( '' !== $saved ) {
		echo ' <label><input type="checkbox" name="' . esc_attr( $name ) . '_clear" value="1"> ' . esc_html__( 'Remove', 'foxfire-studio' ) . '</label>';
	}
}

/** The Storage offload section, below the Podcast feed form. */
function rrt_off_section() {
	$s       = rrt_off_settings();
	$st      = rrt_off_status();
	$miss    = rrt_off_missing( $s );
	$passed  = rrt_off_target( $s ) === $s['passed'];
	$check   = (array) $s['check'];
	$regions = rrt_off_bunny_regions();
	$boot    = array(
		'rest'  => esc_url_raw( rest_url( 'rrt/v1/offload/' ) ),
		'nonce' => wp_create_nonce( 'wp_rest' ),
		'mode'  => $s['mode'],
	);
	wp_enqueue_script( 'rrt-offload', RRT_URL . 'assets/js/offload.js', array( 'wp-i18n' ), RRT_VERSION . '.' . (int) @filemtime( RRT_DIR . 'assets/js/offload.js' ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	wp_set_script_translations( 'rrt-offload', 'foxfire-studio', rrt_languages_dir() );
	?>
	<h2 id="rrt-offload"><?php esc_html_e( 'Storage offload', 'foxfire-studio' ); ?></h2>
	<?php if ( isset( $_GET['offsaved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Storage offload saved.', 'foxfire-studio' ); ?></p></div>
	<?php endif; ?>
	<?php if ( ! empty( $s['down'] ) ) : ?>
		<div class="notice notice-warning inline"><p><?php echo esc_html( sprintf( /* translators: 1: a date and time, 2: what went wrong. */ __( 'Every reading has been served from this site again since %1$s, because the outside storage failed three checks in a row. The last check said: %2$s', 'foxfire-studio' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $s['down'] ), $s['down_why'] ) ); ?> <button type="button" class="button" data-rrt-off-retry><?php esc_html_e( 'Try again', 'foxfire-studio' ); ?></button></p></div>
	<?php endif; ?>
	<p><?php esc_html_e( 'Podcast apps download whole readings, often all at once when a new one comes out. Serving them from a CDN or outside storage takes that load off this site. The site always keeps its own files, and every address comes back here whenever a copy is missing, while this is off, and if the outside storage stops answering. Files for members, readings kept aside and everything private stay on this site.', 'foxfire-studio' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="rrt-off-form" data-rrt-off="<?php echo esc_attr( wp_json_encode( $boot ) ); ?>">
		<input type="hidden" name="action" value="rrt_offload_save">
		<?php wp_nonce_field( 'rrt_offload_save' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Serve readings from', 'foxfire-studio' ); ?></th>
				<td>
					<label><input type="checkbox" name="on" value="1" <?php checked( ! empty( $s['on'] ) ); ?> <?php disabled( ! $passed && empty( $s['on'] ) ); ?>> <?php esc_html_e( 'Serve readings from the storage below', 'foxfire-studio' ); ?></label>
					<?php if ( ! $passed ) : ?>
						<p class="description"><?php esc_html_e( 'Fill in the storage, Save, then press Check this setup. This can be switched on once the check passes.', 'foxfire-studio' ); ?></p>
					<?php endif; ?>
					<fieldset style="margin-top:.6em">
						<label><input type="radio" name="mode" value="bunny" <?php checked( 'bunny', $s['mode'] ); ?>> <?php esc_html_e( 'Bunny Storage', 'foxfire-studio' ); ?></label><br>
						<label><input type="radio" name="mode" value="s3" <?php checked( 's3', $s['mode'] ); ?>> <?php esc_html_e( 'S3-compatible storage (Cloudflare R2, Backblaze B2, Wasabi, Amazon S3 and others)', 'foxfire-studio' ); ?></label><br>
						<label><input type="radio" name="mode" value="cdn" <?php checked( 'cdn', $s['mode'] ); ?>> <?php esc_html_e( 'A CDN in front of this site (it copies the files from here by itself; nothing is uploaded)', 'foxfire-studio' ); ?></label>
					</fieldset>
				</td>
			</tr>
			<tr data-rrt-off-for="bunny">
				<th scope="row"><?php esc_html_e( 'Bunny Storage', 'foxfire-studio' ); ?></th>
				<td>
					<p><label for="rrt-off-b_zone"><?php esc_html_e( 'Storage zone name', 'foxfire-studio' ); ?></label><br><input class="regular-text code" type="text" id="rrt-off-b_zone" name="b_zone" value="<?php echo esc_attr( $s['b_zone'] ); ?>"></p>
					<p><label for="rrt-off-b_region"><?php esc_html_e( 'Main region', 'foxfire-studio' ); ?></label><br><select id="rrt-off-b_region" name="b_region">
						<?php foreach ( $regions as $code => $r ) : ?>
							<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $code, $s['b_region'] ); ?>><?php echo esc_html( $r[0] ); ?></option>
						<?php endforeach; ?>
					</select></p>
					<p><label for="rrt-off-b_pass"><?php esc_html_e( 'Password (the storage zone\'s FTP and API access password)', 'foxfire-studio' ); ?></label><br><?php rrt_off_secret_field( 'b_pass', 'secret', $s['b_pass'], __( 'Password', 'foxfire-studio' ) ); ?></p>
					<p><label for="rrt-off-b_base"><?php esc_html_e( 'Public address (the pull zone connected to this storage zone)', 'foxfire-studio' ); ?></label><br><input class="regular-text code" type="url" id="rrt-off-b_base" name="b_base" value="<?php echo esc_attr( $s['b_base'] ); ?>" placeholder="https://example.b-cdn.net"></p>
					<p class="description"><?php esc_html_e( 'In bunny.net: Storage, Add Storage Zone; then its Connected Pull Zones, Connect Pull Zone. The password is under the storage zone\'s FTP & API Access. In the pull zone\'s Headers settings, add mp3, vtt and json to the CORS extensions, so the player can read a reading\'s chapters.', 'foxfire-studio' ); ?></p>
				</td>
			</tr>
			<tr data-rrt-off-for="s3">
				<th scope="row"><?php esc_html_e( 'S3-compatible storage', 'foxfire-studio' ); ?></th>
				<td>
					<p><label for="rrt-off-preset"><?php esc_html_e( 'Fill in for', 'foxfire-studio' ); ?></label><br><select id="rrt-off-preset" data-rrt-off-preset>
						<option value=""><?php esc_html_e( 'Choose a service...', 'foxfire-studio' ); ?></option>
						<?php foreach ( rrt_off_s3_presets() as $code => $p ) : ?>
							<option value="<?php echo esc_attr( $code ); ?>" data-endpoint="<?php echo esc_attr( $p[1] ); ?>" data-region="<?php echo esc_attr( $p[2] ); ?>"><?php echo esc_html( $p[0] ); ?></option>
						<?php endforeach; ?>
					</select></p>
					<p><label for="rrt-off-s3_endpoint"><?php esc_html_e( 'Endpoint', 'foxfire-studio' ); ?></label><br><input class="regular-text code" type="url" id="rrt-off-s3_endpoint" name="s3_endpoint" value="<?php echo esc_attr( $s['s3_endpoint'] ); ?>"></p>
					<p><label for="rrt-off-s3_region"><?php esc_html_e( 'Region', 'foxfire-studio' ); ?></label><br><input class="regular-text code" type="text" id="rrt-off-s3_region" name="s3_region" value="<?php echo esc_attr( $s['s3_region'] ); ?>"></p>
					<p><label for="rrt-off-s3_bucket"><?php esc_html_e( 'Bucket', 'foxfire-studio' ); ?></label><br><input class="regular-text code" type="text" id="rrt-off-s3_bucket" name="s3_bucket" value="<?php echo esc_attr( $s['s3_bucket'] ); ?>"></p>
					<p><label for="rrt-off-s3_key"><?php esc_html_e( 'Access key ID', 'foxfire-studio' ); ?></label><br><?php rrt_off_secret_field( 's3_key', 'key', $s['s3_key'], __( 'Access key ID', 'foxfire-studio' ) ); ?></p>
					<p><label for="rrt-off-s3_secret"><?php esc_html_e( 'Secret access key', 'foxfire-studio' ); ?></label><br><?php rrt_off_secret_field( 's3_secret', 'secret', $s['s3_secret'], __( 'Secret access key', 'foxfire-studio' ) ); ?></p>
					<p><label for="rrt-off-s3_base"><?php esc_html_e( 'Public address (the bucket\'s own domain or a CDN in front of it)', 'foxfire-studio' ); ?></label><br><input class="regular-text code" type="url" id="rrt-off-s3_base" name="s3_base" value="<?php echo esc_attr( $s['s3_base'] ); ?>" placeholder="https://media.example.com"></p>
					<p class="description"><?php esc_html_e( 'Make a key that may only read and write this one bucket, and let anyone read the bucket\'s files at the public address. Cloudflare R2 needs a custom domain for this (its r2.dev address is for testing only). Backblaze B2 on its own only gives downloads free up to three times what you store, which a podcast passes quickly; put a CDN in front of it.', 'foxfire-studio' ); ?></p>
				</td>
			</tr>
			<tr data-rrt-off-for="cdn">
				<th scope="row"><?php esc_html_e( 'CDN', 'foxfire-studio' ); ?></th>
				<td>
					<p><label for="rrt-off-cdn_base"><?php esc_html_e( 'The CDN\'s address', 'foxfire-studio' ); ?></label><br><input class="regular-text code" type="url" id="rrt-off-cdn_base" name="cdn_base" value="<?php echo esc_attr( $s['cdn_base'] ); ?>" placeholder="https://example.b-cdn.net"></p>
					<p class="description"><?php echo esc_html( sprintf( /* translators: %s: this site's address. */ __( 'Make a pull zone (Bunny) or distribution with %s as its origin, and paste its address. Let it pass on query strings (each file\'s address ends with a short code that changes with the file), and turn on CORS for mp3, vtt and json. A file that goes to members stops answering here at once, but the CDN may keep handing out its copy until its cache runs out; with a bunny.net API key below, the plugin clears it at once.', 'foxfire-studio' ), untrailingslashit( home_url() ) ) ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="rrt-off-purge_key"><?php esc_html_e( 'bunny.net API key (optional)', 'foxfire-studio' ); ?></label></th>
				<td><?php rrt_off_secret_field( 'purge_key', 'purge', $s['purge_key'], __( 'bunny.net API key', 'foxfire-studio' ) ); ?>
				<p class="description"><?php esc_html_e( 'With a bunny.net pull zone: the account\'s API key (Account settings, API key) lets the plugin clear a file from the CDN the moment it leaves public use, such as a reading set to Members only. Without it, a copy can stay in the CDN\'s cache for up to a day.', 'foxfire-studio' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Use it for', 'foxfire-studio' ); ?></th>
				<td>
					<label><input type="radio" name="use" value="all" <?php checked( 'all', $s['use'] ); ?>> <?php esc_html_e( 'Podcast apps and the player on posts', 'foxfire-studio' ); ?></label><br>
					<label><input type="radio" name="use" value="feeds" <?php checked( 'feeds', $s['use'] ); ?>> <?php esc_html_e( 'Podcast apps only', 'foxfire-studio' ); ?></label>
					<p class="description"><?php esc_html_e( 'The player\'s Download button and captions always come from this site. Your own tools (the studio, collections, audiobooks, videos) always read this site\'s files.', 'foxfire-studio' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Keys in wp-config.php', 'foxfire-studio' ); ?></th>
				<td><p class="description"><?php esc_html_e( 'Safer than saving them here: add these lines to wp-config.php, above the line that says to stop editing, and leave the boxes above empty.', 'foxfire-studio' ); ?></p>
				<pre class="code" style="white-space:pre-wrap">define( 'FOXFIRE_STORAGE_KEY', 'the access key ID (S3 only)' );
define( 'FOXFIRE_STORAGE_SECRET', 'the secret access key or the Bunny password' );
define( 'FOXFIRE_STORAGE_PURGE_KEY', 'the bunny.net API key (optional)' );</pre></td>
			</tr>
		</table>
		<?php submit_button( __( 'Save storage offload', 'foxfire-studio' ), 'secondary' ); ?>
	</form>
	<h3><?php esc_html_e( 'Check this setup', 'foxfire-studio' ); ?></h3>
	<p><?php esc_html_e( 'Uploads a small test file, reads it back through the public address the way podcast apps do, tries it from this browser, then deletes it. Save first.', 'foxfire-studio' ); ?></p>
	<p><button type="button" class="button button-primary" data-rrt-off-check <?php disabled( (bool) $miss ); ?>><?php esc_html_e( 'Check this setup', 'foxfire-studio' ); ?></button></p>
	<?php if ( $miss ) : ?>
		<ul class="ul-disc"><?php foreach ( $miss as $m ) : ?><li><?php echo esc_html( $m ); ?></li><?php endforeach; ?></ul>
	<?php endif; ?>
	<div data-rrt-off-steps aria-live="polite">
		<?php if ( ! empty( $check['at'] ) ) : ?>
			<p><?php echo esc_html( sprintf( /* translators: 1: a date and time, 2: passed or did not pass. */ __( 'Last checked %1$s: %2$s', 'foxfire-studio' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $check['at'] ), ! empty( $check['ok'] ) ? __( 'passed.', 'foxfire-studio' ) : __( 'did not pass.', 'foxfire-studio' ) ) ); ?></p>
			<ul class="ul-disc">
				<?php foreach ( (array) $check['steps'] as $step ) : ?>
					<li><?php echo esc_html( ( ! empty( $step['ok'] ) ? '✓ ' : '✗ ' ) . $step['text'] ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>
	<h3><?php esc_html_e( 'Files outside', 'foxfire-studio' ); ?></h3>
	<p data-rrt-off-count><?php echo esc_html( sprintf( /* translators: 1: files with a copy, 2: files that should have one, 3: their size. */ __( '%1$d of %2$d files have a copy outside (%3$s).', 'foxfire-studio' ), $st['outside'], $st['wanted'] - $st['local'], size_format( $st['bytes'] ) ) ); ?>
	<?php
	if ( $st['newest'] ) {
		echo ' ' . esc_html( sprintf( /* translators: %s: a date and time. */ __( 'Newest checked %s.', 'foxfire-studio' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $st['newest'] ) ) );
	}
	if ( $st['local'] ) {
		echo ' ' . esc_html( sprintf( /* translators: %d: a number of files. */ _n( '%d captions or chapters file stays on this site, because the storage serves it as a plain download and browsers and podcast apps go by its type.', '%d captions or chapters files stay on this site, because the storage serves them as plain downloads and browsers and podcast apps go by their type.', $st['local'], 'foxfire-studio' ), $st['local'] ) );
	}
	if ( $st['leaving'] ) {
		echo ' ' . esc_html( sprintf( /* translators: %d: a number of files. */ _n( '%d older copy goes once its two days are over.', '%d older copies go once their two days are over.', $st['leaving'], 'foxfire-studio' ), $st['leaving'] ) );
	}
	?></p>
	<?php if ( $st['waiting'] && rrt_off_on( $s ) ) : ?>
		<p><strong><?php esc_html_e( 'Files waiting', 'foxfire-studio' ); ?></strong></p>
		<ul class="ul-disc">
			<?php foreach ( array_slice( $st['waiting'], 0, 20, true ) as $rel => $why ) : ?>
				<li><code><?php echo esc_html( wp_basename( $rel ) ); ?></code> <?php echo esc_html( '' !== $why ? $why : __( 'goes with the next upload.', 'foxfire-studio' ) ); ?></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
	<p>
		<button type="button" class="button" data-rrt-off-copy <?php disabled( ! rrt_off_on( $s ) ); ?>><?php esc_html_e( 'Copy everything now', 'foxfire-studio' ); ?></button>
		<button type="button" class="button" data-rrt-off-clear <?php disabled( ! rrt_off_files() ); ?>><?php esc_html_e( 'Delete the outside copies', 'foxfire-studio' ); ?></button>
	</p>
	<p data-rrt-off-progress aria-live="polite"></p>
	<p class="description"><?php esc_html_e( 'Switching this off brings every address back to this site at once and leaves the outside copies where they are. Deleting the plugin never touches the outside storage: press Delete the outside copies first, or empty the bucket yourself.', 'foxfire-studio' ); ?></p>
	<?php
}

/** The section's form. */
add_action(
	'admin_post_rrt_offload_save',
	function () {
		check_admin_referer( 'rrt_offload_save' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'foxfire-studio' ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification -- checked above.
		$old  = rrt_off_settings();
		$s    = $old;
		$text = function ( $k, $max = 200 ) {
			return isset( $_POST[ $k ] ) ? mb_substr( trim( sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) ), 0, $max ) : '';
		};
		$mode      = $text( 'mode', 10 );
		$s['mode'] = in_array( $mode, array( 'cdn', 'bunny', 's3' ), true ) ? $mode : 'bunny';
		$s['use']  = 'feeds' === $text( 'use', 10 ) ? 'feeds' : 'all';
		foreach ( array( 'cdn_base', 'b_base', 's3_base', 's3_endpoint' ) as $k ) {
			$v       = isset( $_POST[ $k ] ) ? esc_url_raw( trim( wp_unslash( $_POST[ $k ] ) ), array( 'https', 'http' ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned on this line (esc_url_raw, or each line checked below).
			$s[ $k ] = untrailingslashit( $v );
		}
		$s['b_zone']    = preg_replace( '/[^A-Za-z0-9-]/', '', $text( 'b_zone', 64 ) );
		$region         = $text( 'b_region', 8 );
		$s['b_region']  = isset( rrt_off_bunny_regions()[ $region ] ) ? $region : 'de';
		$s['s3_region'] = preg_replace( '/[^A-Za-z0-9-]/', '', $text( 's3_region', 40 ) );
		$s['s3_bucket'] = preg_replace( '/[^A-Za-z0-9._-]/', '', $text( 's3_bucket', 63 ) );
		/* Secrets: an empty box keeps the saved one; Remove clears it. */
		foreach ( array( 'b_pass', 's3_key', 's3_secret', 'purge_key' ) as $k ) {
			$v = isset( $_POST[ $k ] ) ? trim( (string) wp_unslash( $_POST[ $k ] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$v = preg_replace( '/[^\x21-\x7E]/', '', $v );
			if ( '' !== $v ) {
				$s[ $k ] = mb_substr( $v, 0, 200 );
			} elseif ( ! empty( $_POST[ $k . '_clear' ] ) ) {
				$s[ $k ] = '';
			}
		}
		$want    = ! empty( $_POST['on'] );
		// phpcs:enable
		$s['on'] = $want && rrt_off_target( $s ) === $s['passed'] && ! rrt_off_missing( $s );
		rrt_off_save( $s );
		if ( (bool) $s['on'] !== (bool) $old['on'] || $s['use'] !== $old['use'] || rrt_off_target( $s ) !== rrt_off_target( $old ) ) {
			rrt_off_purge_all();
		}
		if ( $s['on'] ) {
			rrt_off_queue();
		}
		wp_safe_redirect( admin_url( 'admin.php?page=rrt-podcast&offsaved=1#rrt-offload' ) );
		exit;
	}
);

/* A notice on every admin page when the outside storage stopped answering. */
add_action(
	'admin_notices',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = rrt_off_settings();
		if ( empty( $s['on'] ) || empty( $s['down'] ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && false !== strpos( (string) $screen->id, 'rrt-podcast' ) ) {
			return; // The section says it there.
		}
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Foxfire Studio: the outside storage stopped answering, so every reading is served from this site again.', 'foxfire-studio' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=rrt-podcast#rrt-offload' ) ) . '">' . esc_html__( 'See Storage offload', 'foxfire-studio' ) . '</a></p></div>';
	}
);
