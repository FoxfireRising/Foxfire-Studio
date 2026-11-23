<?php
/**
 * Listener analytics (0.10.0).
 *
 * What listeners do with each reading, measured on this site only:
 *
 * - The player (listen.js) sends small batches of events to
 *   POST rrt/v1/events: play, pause, each 5 second stretch heard, seeks,
 *   speed and volume changes, the end, leaving the page, and so on.
 * - Each page view becomes one session row (rrt_sessions), with one row per
 *   5 second bucket heard (rrt_buckets). Raw events (rrt_events) are kept
 *   for a short time for checking and recomputing.
 * - Once an hour, finished sessions are rolled up into daily summaries per
 *   reading (rrt_daily) and per reading and source (rrt_daily_sources).
 *   Once a day, old raw events and sessions are deleted.
 *
 * Privacy, by design:
 * - No IP address is ever stored. The IP address, browser and site name are
 *   hashed with a random salt that is replaced every day and never kept, so
 *   the day's listener id cannot be linked to a person or to another day.
 * - The country is looked up while the request is being handled, and only
 *   the two-letter code is kept.
 * - No cookies and no browser storage are used for this.
 * - Do Not Track and Global Privacy Control are respected, and editors are
 *   left out by default so testing does not count.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Creates or updates the listener tables. Called by rrt_stats_install(). */
function rrt_listen_install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$c = $wpdb->get_charset_collate();
	$sessions = rrt_table( 'sessions' );
	$buckets  = rrt_table( 'buckets' );
	$events   = rrt_table( 'events' );
	$daily    = rrt_table( 'daily' );
	$sources  = rrt_table( 'daily_sources' );
	dbDelta(
		"CREATE TABLE $sessions (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		sid char(32) NOT NULL,
		day_hash char(32) NOT NULL,
		post_id bigint(20) unsigned NOT NULL,
		recording_id bigint(20) unsigned NOT NULL DEFAULT 0,
		day date NOT NULL,
		started_at datetime NOT NULL,
		last_at datetime NOT NULL,
		channel varchar(20) NOT NULL DEFAULT '',
		referrer_domain varchar(190) NOT NULL DEFAULT '',
		utm_source varchar(100) NOT NULL DEFAULT '',
		utm_medium varchar(100) NOT NULL DEFAULT '',
		utm_campaign varchar(100) NOT NULL DEFAULT '',
		utm_content varchar(100) NOT NULL DEFAULT '',
		device varchar(10) NOT NULL DEFAULT '',
		country char(2) NOT NULL DEFAULT '',
		duration_s float DEFAULT NULL,
		played tinyint(1) NOT NULL DEFAULT 0,
		first_play_s float DEFAULT NULL,
		buckets_heard int(10) unsigned NOT NULL DEFAULT 0,
		max_position_s float NOT NULL DEFAULT 0,
		completed tinyint(1) NOT NULL DEFAULT 0,
		rate_sum float NOT NULL DEFAULT 0,
		rate_n int(10) unsigned NOT NULL DEFAULT 0,
		rate_changes smallint(5) unsigned NOT NULL DEFAULT 0,
		seeks_back smallint(5) unsigned NOT NULL DEFAULT 0,
		seeks_forward smallint(5) unsigned NOT NULL DEFAULT 0,
		hidden_s int(10) unsigned NOT NULL DEFAULT 0,
		hidden_at_ms bigint(20) DEFAULT NULL,
		follow_used tinyint(1) NOT NULL DEFAULT 0,
		downloaded tinyint(1) NOT NULL DEFAULT 0,
		errors smallint(5) unsigned NOT NULL DEFAULT 0,
		scroll_max tinyint(3) unsigned NOT NULL DEFAULT 0,
		time_on_page_s int(10) unsigned NOT NULL DEFAULT 0,
		came_back tinyint(1) DEFAULT NULL,
		exit_type varchar(10) NOT NULL DEFAULT '',
		events int(10) unsigned NOT NULL DEFAULT 0,
		last_ms bigint(20) NOT NULL DEFAULT 0,
		junk tinyint(1) NOT NULL DEFAULT 0,
		rolled tinyint(1) NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		UNIQUE KEY sid (sid),
		KEY day_hash (day_hash),
		KEY post_day (post_id,day),
		KEY day (day),
		KEY last_at (last_at),
		KEY rolled (rolled)
		) $c;"
	);
	dbDelta(
		"CREATE TABLE $buckets (
		session_id bigint(20) unsigned NOT NULL,
		bucket smallint(5) unsigned NOT NULL,
		plays smallint(5) unsigned NOT NULL DEFAULT 1,
		rate float NOT NULL DEFAULT 1,
		PRIMARY KEY  (session_id,bucket)
		) $c;"
	);
	dbDelta(
		"CREATE TABLE $events (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		session_id bigint(20) unsigned NOT NULL,
		type varchar(12) NOT NULL,
		t float NOT NULL DEFAULT 0,
		ms bigint(20) NOT NULL DEFAULT 0,
		created_at datetime NOT NULL,
		data text,
		PRIMARY KEY  (id),
		KEY session_id (session_id),
		KEY created_at (created_at)
		) $c;"
	);
	dbDelta(
		"CREATE TABLE $daily (
		day date NOT NULL,
		post_id bigint(20) unsigned NOT NULL,
		recording_id bigint(20) unsigned NOT NULL DEFAULT 0,
		page_loads int(10) unsigned NOT NULL DEFAULT 0,
		views int(10) unsigned NOT NULL DEFAULT 0,
		plays int(10) unsigned NOT NULL DEFAULT 0,
		listened_s int(10) unsigned NOT NULL DEFAULT 0,
		completions int(10) unsigned NOT NULL DEFAULT 0,
		q25 int(10) unsigned NOT NULL DEFAULT 0,
		q50 int(10) unsigned NOT NULL DEFAULT 0,
		q75 int(10) unsigned NOT NULL DEFAULT 0,
		held60 int(10) unsigned NOT NULL DEFAULT 0,
		avg_rate float DEFAULT NULL,
		first_play_s float DEFAULT NULL,
		hidden_s int(10) unsigned NOT NULL DEFAULT 0,
		follow_plays int(10) unsigned NOT NULL DEFAULT 0,
		v_listen int(10) unsigned NOT NULL DEFAULT 0,
		v_both int(10) unsigned NOT NULL DEFAULT 0,
		v_read int(10) unsigned NOT NULL DEFAULT 0,
		v_pass int(10) unsigned NOT NULL DEFAULT 0,
		l_new int(10) unsigned NOT NULL DEFAULT 0,
		l_back int(10) unsigned NOT NULL DEFAULT 0,
		first_hist text,
		buckets longtext,
		PRIMARY KEY  (day,post_id,recording_id)
		) $c;"
	);
	dbDelta(
		"CREATE TABLE $sources (
		day date NOT NULL,
		post_id bigint(20) unsigned NOT NULL,
		recording_id bigint(20) unsigned NOT NULL DEFAULT 0,
		channel varchar(20) NOT NULL DEFAULT '',
		campaign varchar(100) NOT NULL DEFAULT '',
		views int(10) unsigned NOT NULL DEFAULT 0,
		plays int(10) unsigned NOT NULL DEFAULT 0,
		listened_s int(10) unsigned NOT NULL DEFAULT 0,
		completions int(10) unsigned NOT NULL DEFAULT 0,
		PRIMARY KEY  (day,post_id,recording_id,channel,campaign)
		) $c;"
	);
	$markers = rrt_table( 'markers' );
	dbDelta(
		"CREATE TABLE $markers (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		day date NOT NULL,
		post_id bigint(20) unsigned NOT NULL DEFAULT 0,
		label varchar(140) NOT NULL DEFAULT '',
		created_by bigint(20) unsigned NOT NULL DEFAULT 0,
		created_at datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY day (day)
		) $c;"
	);
	/*
	 * 0.11.0 adds skipped stretches to the daily summaries, and 1.2.0 read or
	 * listen, time to press play and returning listeners: rebuild every day
	 * still kept on the next run.
	 */
	if ( version_compare( (string) get_option( 'rrt_db_version', '0' ), '7', '<' ) ) {
		$wpdb->query( "UPDATE $sessions SET rolled = 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}
}

/** Whether listener analytics is on. */
function rrt_listen_on() {
	$s = rrt_settings();
	return ! empty( $s['listen']['on'] );
}

/** The listen.js settings added to the player's data, or null when analytics is off. */
function rrt_listen_boot( $cfg ) {
	if ( empty( $cfg['listen']['on'] ) ) {
		return null;
	}
	return array(
		'u' => esc_url_raw( rest_url( 'rrt/v1/events' ) ),
		'd' => empty( $cfg['listen']['dnt'] ) ? 0 : 1,
		's' => $cfg['listen']['src'],
		'c' => $cfg['player']['content_selector'],
		/* Returning listeners (1.2.0): the window in months, or 0 when off. */
		'b' => empty( $cfg['listen']['returning'] ) ? 0 : (int) $cfg['listen']['returning_months'],
	);
}

/*
 * ---------------------------------------------------------------------------
 * Who is measured
 * ---------------------------------------------------------------------------
 */

/** Crawlers, link previews, monitors and scripts. Real browsers run the player; these mostly do not, but some do. */
function rrt_listen_is_bot( $ua ) {
	if ( '' === trim( $ua ) ) {
		return true;
	}
	$re = '/bot\b|bot\/|crawl|spider|slurp|mediapartners|facebookexternalhit|facebookcatalog|embedly|quora link|pinterest\/|bingpreview|headless|phantomjs|lighthouse|pagespeed|gtmetrix|pingdom|uptime|monitor|curl\/|wget|python-|httpclient|java\/|go-http|axios|node-fetch|okhttp|preview|scrapy|feedfetcher|validator/i';
	return (bool) preg_match( apply_filters( 'rrt_listen_bot_pattern', $re ), $ua );
}

/**
 * Whether this request comes from someone Settings leaves out. The beacon
 * carries the browser's cookies but no REST nonce, so WordPress treats it as
 * logged out; the login cookie is checked directly instead.
 */
function rrt_listen_excluded_user() {
	$s   = rrt_settings();
	$who = $s['listen']['exclude'];
	if ( 'nobody' === $who ) {
		return false;
	}
	$uid = wp_validate_auth_cookie( '', 'logged_in' );
	if ( ! $uid ) {
		return false;
	}
	if ( 'logged_in' === $who ) {
		return true;
	}
	return user_can( $uid, 'edit_posts' );
}

/** Whether the browser asked not to be tracked. */
function rrt_listen_opted_out() {
	if ( isset( $_SERVER['HTTP_SEC_GPC'] ) && '1' === trim( (string) $_SERVER['HTTP_SEC_GPC'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return true;
	}
	if ( isset( $_SERVER['HTTP_DNT'] ) && '1' === trim( (string) $_SERVER['HTTP_DNT'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return true;
	}
	return false;
}

/*
 * Client address and trusted proxies (0.10.1).
 *
 * The address a request came from is REMOTE_ADDR, the other end of the
 * connection, which a visitor cannot choose. Headers such as X-Forwarded-For or
 * CF-Connecting-IP can be sent by anyone, so they are read only when REMOTE_ADDR
 * is one of the proxies the site owner listed in Settings (or added with the
 * rrt_trusted_proxies filter). Nothing here is stored.
 */

/** Forwarding headers that can be chosen in Settings, and the $_SERVER key each arrives as. */
function rrt_proxy_headers() {
	return array(
		'x-forwarded-for'  => 'HTTP_X_FORWARDED_FOR',
		'cf-connecting-ip' => 'HTTP_CF_CONNECTING_IP',
		'x-real-ip'        => 'HTTP_X_REAL_IP',
		'true-client-ip'   => 'HTTP_TRUE_CLIENT_IP',
	);
}

/**
 * One address in canonical form, or '' if it is not a valid IP address.
 * Accepts the forms proxies write: surrounding spaces or quotes, IPv6 in
 * brackets, an IPv4 port suffix, and IPv4 written as IPv6 (::ffff:1.2.3.4).
 *
 * @param mixed $raw An address as received.
 * @return string
 */
function rrt_ip_normalize( $raw ) {
	if ( ! is_string( $raw ) ) {
		return '';
	}
	$ip = trim( $raw, " \t\"'" );
	if ( '' === $ip || strlen( $ip ) > 64 ) {
		return '';
	}
	if ( '[' === $ip[0] ) {
		$end = strpos( $ip, ']' );
		if ( false === $end ) {
			return '';
		}
		$ip = substr( $ip, 1, $end - 1 );
	} elseif ( 1 === substr_count( $ip, ':' ) && false !== strpos( $ip, '.' ) ) {
		$ip = substr( $ip, 0, strpos( $ip, ':' ) );
	}
	$pct = strpos( $ip, '%' );
	if ( false !== $pct ) {
		$ip = substr( $ip, 0, $pct );
	}
	if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
		return '';
	}
	$bin = inet_pton( $ip );
	if ( 16 === strlen( $bin ) && substr( $bin, 0, 12 ) === str_repeat( "\0", 10 ) . "\xff\xff" ) {
		$bin = substr( $bin, 12 );
	}
	return (string) inet_ntop( $bin );
}

/**
 * Parses a list of proxies (one address or CIDR range per line, or separated
 * by commas or spaces) into [ binary network, prefix length ] pairs. Entries
 * that are not valid are skipped.
 *
 * @param string|array $list Text from Settings, or a list of entries.
 * @return array
 */
function rrt_proxy_ranges( $list ) {
	$items = is_array( $list ) ? $list : preg_split( '/[\s,]+/', (string) $list, -1, PREG_SPLIT_NO_EMPTY );
	$out   = array();
	foreach ( (array) $items as $item ) {
		$item = trim( (string) $item );
		$bits = null;
		if ( false !== strpos( $item, '/' ) ) {
			list( $item, $bits ) = explode( '/', $item, 2 );
			if ( ! ctype_digit( $bits ) ) {
				continue;
			}
			$bits = (int) $bits;
		}
		$ip = rrt_ip_normalize( $item );
		if ( '' === $ip ) {
			continue;
		}
		$bin = inet_pton( $ip );
		$max = 8 * strlen( $bin );
		if ( null === $bits ) {
			$bits = $max;
		}
		if ( $bits > $max ) {
			continue;
		}
		$out[] = array( $bin, $bits );
	}
	return $out;
}

/** Whether a canonical address falls inside one of the ranges. */
function rrt_ip_in_ranges( $ip, $ranges ) {
	if ( '' === $ip ) {
		return false;
	}
	$bin = inet_pton( $ip );
	foreach ( $ranges as $r ) {
		if ( strlen( $r[0] ) !== strlen( $bin ) ) {
			continue;
		}
		$whole = intdiv( $r[1], 8 );
		if ( substr( $bin, 0, $whole ) !== substr( $r[0], 0, $whole ) ) {
			continue;
		}
		$rest = $r[1] % 8;
		if ( $rest ) {
			$mask = chr( ( 0xff << ( 8 - $rest ) ) & 0xff );
			if ( ( $bin[ $whole ] & $mask ) !== ( $r[0][ $whole ] & $mask ) ) {
				continue;
			}
		}
		return true;
	}
	return false;
}

/** The trusted proxies from Settings, plus any added by the rrt_trusted_proxies filter. */
function rrt_trusted_proxy_ranges() {
	static $cache = null;
	if ( null !== $cache && ! defined( 'RRT_TESTING' ) ) {
		return $cache;
	}
	$cfg   = function_exists( 'rrt_config' ) ? rrt_config() : array();
	$text  = isset( $cfg['listen']['proxies'] ) ? (string) $cfg['listen']['proxies'] : '';
	$extra = function_exists( 'apply_filters' ) ? (array) apply_filters( 'rrt_trusted_proxies', array() ) : array();
	$cache = array_merge( rrt_proxy_ranges( $text ), rrt_proxy_ranges( $extra ) );
	return $cache;
}

/** The forwarding header chosen in Settings, as its $_SERVER key. */
function rrt_proxy_header_key() {
	$cfg = function_exists( 'rrt_config' ) ? rrt_config() : array();
	$h   = isset( $cfg['listen']['proxy_header'] ) ? (string) $cfg['listen']['proxy_header'] : 'x-forwarded-for';
	$map = rrt_proxy_headers();
	return isset( $map[ $h ] ) ? $map[ $h ] : $map['x-forwarded-for'];
}

/**
 * Works out the client address from a request's server variables.
 * Pure, so it can be tested without WordPress.
 *
 * @param array  $server  $_SERVER or a test copy.
 * @param array  $ranges  Trusted proxies, from rrt_proxy_ranges().
 * @param string $key     The forwarding header's $_SERVER key.
 * @return array ip (canonical, or ''), via_proxy (REMOTE_ADDR is trusted), source.
 */
function rrt_client_ip_from( $server, $ranges, $key ) {
	$remote = rrt_ip_normalize( isset( $server['REMOTE_ADDR'] ) ? $server['REMOTE_ADDR'] : '' );
	$out    = array(
		'ip'        => $remote,
		'via_proxy' => false,
		'source'    => 'REMOTE_ADDR',
	);
	if ( '' === $remote || ! $ranges || ! rrt_ip_in_ranges( $remote, $ranges ) ) {
		return $out;
	}
	$out['via_proxy'] = true;
	$raw              = isset( $server[ $key ] ) && is_string( $server[ $key ] ) ? $server[ $key ] : '';
	if ( '' === $raw || strlen( $raw ) > 2048 ) {
		return $out;
	}
	if ( 'HTTP_X_FORWARDED_FOR' === $key ) {
		/*
		 * Each proxy appends the address it received from, so read from the
		 * right and skip our own proxies. The first address that is not one of
		 * them is the client. Anything to its left was written by the client
		 * and is ignored. A malformed entry stops the walk.
		 */
		$hops = array_reverse( explode( ',', $raw ) );
		foreach ( array_slice( $hops, 0, 20 ) as $hop ) {
			$ip = rrt_ip_normalize( $hop );
			if ( '' === $ip ) {
				break;
			}
			if ( ! rrt_ip_in_ranges( $ip, $ranges ) ) {
				$out['ip']     = $ip;
				$out['source'] = $key;
				return $out;
			}
		}
		return $out;
	}
	$ip = rrt_ip_normalize( $raw );
	if ( '' !== $ip ) {
		$out['ip']     = $ip;
		$out['source'] = $key;
	}
	return $out;
}

/** This request's client address details. */
function rrt_client_ip() {
	return rrt_client_ip_from( $_SERVER, rrt_trusted_proxy_ranges(), rrt_proxy_header_key() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated in rrt_ip_normalize().
}

/** The visitor's IP address, used only for the moment it takes to hash it and look up the country. */
function rrt_listen_ip() {
	$c = rrt_client_ip();
	return $c['ip'];
}

/** Whether this request reached the site through one of its trusted proxies. */
function rrt_listen_via_trusted_proxy() {
	$c = rrt_client_ip();
	return $c['via_proxy'];
}

/**
 * For the settings screen: how this very request would be placed, so a site
 * owner can tell whether the server sees visitors' own addresses or a proxy's.
 * Shown only to the person viewing, and never stored.
 *
 * @return array country, where it came from, whether a trusted proxy was used,
 *               and any forwarding header that arrived from an untrusted address.
 */
function rrt_listen_ip_check() {
	$c   = rrt_client_ip();
	$ip  = $c['ip'];
	$out = array(
		'country' => '',
		'from'    => 'database',
		'trusted' => $c['via_proxy'],
		'source'  => $c['source'],
		'proxy'   => false,
		'fwd'     => '',
	);
	$out['country'] = function_exists( 'rrt_geo_country' ) ? rrt_geo_country( $ip ) : '';
	if ( function_exists( 'rrt_geo_country_source' ) ) {
		$out['from'] = rrt_geo_country_source();
	}
	if ( ! $c['via_proxy'] ) {
		foreach ( rrt_proxy_headers() as $name => $k ) {
			if ( empty( $_SERVER[ $k ] ) ) {
				continue;
			}
			$first = rrt_ip_normalize( explode( ',', (string) $_SERVER[ $k ] )[0] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( '' !== $first && $first !== $ip ) {
				$out['proxy'] = $name;
				$out['fwd']   = function_exists( 'rrt_geo_lookup' ) ? rrt_geo_lookup( $first ) : '';
				break;
			}
		}
	}
	return $out;
}

/**
 * Hourly limits on new page views, checked before one is recorded (0.10.1).
 *
 * The listener id includes the browser name, so the 60-an-hour limit above can
 * be dodged by changing it. These two limits do not depend on it: one per
 * address (300 an hour by default, generous enough for a shared office or
 * school network) and one for the whole site (5,000 an hour). Both are counts
 * in short-lived transients that expire within the hour. The per-address key is
 * a keyed hash of the address and the hour, never the address itself.
 *
 * @param string $ip The client address.
 * @return bool Whether a new page view may be recorded.
 */
function rrt_listen_allow_new( $ip ) {
	$hour      = gmdate( 'YmdH' );
	$site_key  = 'rrt_lnew_' . $hour;
	$site_max  = (int) apply_filters( 'rrt_listen_hourly_session_limit', 5000 );
	$site_seen = (int) get_transient( $site_key );
	if ( $site_max > 0 && $site_seen >= $site_max ) {
		return false;
	}
	$addr_seen = 0;
	$addr_key  = '';
	if ( '' !== $ip ) {
		$addr_key  = 'rrt_lip_' . substr( hash_hmac( 'sha256', $ip . '|' . $hour, rrt_listen_salt() ), 0, 24 );
		$addr_max  = (int) apply_filters( 'rrt_listen_address_hourly_limit', 300 );
		$addr_seen = (int) get_transient( $addr_key );
		if ( $addr_max > 0 && $addr_seen >= $addr_max ) {
			return false;
		}
	}
	set_transient( $site_key, $site_seen + 1, HOUR_IN_SECONDS );
	if ( '' !== $addr_key ) {
		set_transient( $addr_key, $addr_seen + 1, HOUR_IN_SECONDS );
	}
	return true;
}

/**
 * Today's salt. A new random salt each day (UTC), replacing the old one, so
 * yesterday's listener ids can never be recreated or linked to today's.
 */
function rrt_listen_salt() {
	$today = gmdate( 'Y-m-d' );
	$s     = get_option( 'rrt_listen_salt' );
	if ( ! is_array( $s ) || empty( $s['day'] ) || $s['day'] !== $today || empty( $s['salt'] ) ) {
		$s = array(
			'day'  => $today,
			'salt' => wp_generate_password( 64, true, true ),
		);
		update_option( 'rrt_listen_salt', $s, true );
	}
	return $s['salt'];
}

/** The day's anonymous listener id: a hash of IP address, browser and site with today's salt. */
function rrt_listen_day_hash( $ip, $ua ) {
	$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	return substr( hash_hmac( 'sha256', $ip . '|' . $ua . '|' . $host, rrt_listen_salt() ), 0, 32 );
}

/** Phone, tablet or desktop, from the browser name and touch points. */
function rrt_listen_device( $ua, $touch, $width ) {
	if ( preg_match( '/ipad|tablet|kindle|silk|playbook/i', $ua ) ) {
		return 'tablet';
	}
	if ( preg_match( '/android/i', $ua ) && ! preg_match( '/mobile/i', $ua ) ) {
		return 'tablet';
	}
	if ( preg_match( '/mobi|iphone|ipod|android|windows phone/i', $ua ) ) {
		return 'phone';
	}
	/* iPads report themselves as Macs; a touch screen gives them away. */
	if ( $touch > 1 && preg_match( '/macintosh/i', $ua ) ) {
		return 'tablet';
	}
	if ( $touch > 1 && $width > 0 && $width < 700 ) {
		return 'phone';
	}
	return 'desktop';
}

/**
 * The channel a visit came from: a campaign tag on the link wins, then the
 * in-app browsers of Instagram and Facebook (which often send no referrer),
 * then the referring site.
 *
 * @return array channel, referrer domain.
 */
function rrt_listen_channel( $ref, $c, $ua ) {
	$ref  = strtolower( preg_replace( '/^www\./i', '', (string) $ref ) );
	$self = strtolower( preg_replace( '/^www\./i', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
	$tags = array(
		'instagram' => '/^(instagram|ig|insta)$/',
		'facebook'  => '/^(facebook|fb|meta)$/',
		'threads'   => '/^threads$/',
		'bluesky'   => '/^(bluesky|bsky)$/',
		'tiktok'    => '/^(tiktok|tt)$/',
		'x'         => '/^(x|twitter)$/',
		'reddit'    => '/^reddit$/',
		'youtube'   => '/^(youtube|yt)$/',
		'mastodon'  => '/^mastodon$/',
		'email'     => '/^(email|e-mail|newsletter|mail)$/',
	);
	$src = isset( $c['source'] ) ? strtolower( trim( $c['source'] ) ) : '';
	$med = isset( $c['medium'] ) ? strtolower( trim( $c['medium'] ) ) : '';
	foreach ( $tags as $ch => $re ) {
		if ( '' !== $src && preg_match( $re, $src ) ) {
			return array( $ch, $ref );
		}
	}
	if ( 'email' === $med || 'newsletter' === $med ) {
		return array( 'email', $ref );
	}
	$sites = array(
		'instagram' => '/(^|\.)instagram\.com$/',
		'facebook'  => '/(^|\.)(facebook\.com|fb\.com|fb\.me|messenger\.com)$/',
		'threads'   => '/(^|\.)threads\.(net|com)$/',
		'bluesky'   => '/(^|\.)(bsky\.app|bsky\.social)$/',
		'tiktok'    => '/(^|\.)tiktok\.com$/',
		'x'         => '/(^|\.)(t\.co|x\.com|twitter\.com)$/',
		'reddit'    => '/(^|\.)(reddit\.com|redd\.it)$/',
		'youtube'   => '/(^|\.)(youtube\.com|youtu\.be)$/',
		'pinterest' => '/(^|\.)pinterest\.[a-z.]+$/',
		'linkedin'  => '/(^|\.)(linkedin\.com|lnkd\.in)$/',
		'email'     => '/^(mail\.google\.com|outlook\.live\.com|outlook\.office\.com|mail\.yahoo\.com|mail\.proton\.me)$/',
		'google'    => '/(^|\.)google\.[a-z.]+$/',
		'search'    => '/(^|\.)(bing\.com|duckduckgo\.com|search\.yahoo\.com|yandex\.[a-z]+|ecosia\.org|search\.brave\.com|startpage\.com|kagi\.com|baidu\.com)$/',
	);
	if ( '' !== $ref ) {
		if ( '' !== $self && $ref === $self ) {
			return array( 'internal', $ref );
		}
		foreach ( $sites as $ch => $re ) {
			if ( preg_match( $re, $ref ) ) {
				return array( $ch, $ref );
			}
		}
		if ( '' !== $src ) {
			return array( 'campaign', $ref );
		}
		return array( 'other', $ref );
	}
	if ( preg_match( '/instagram/i', $ua ) ) {
		return array( 'instagram', '' );
	}
	if ( preg_match( '/FBAN|FBAV|FB_IAB/', $ua ) ) {
		return array( 'facebook', '' );
	}
	if ( '' !== $src ) {
		return array( 'campaign', '' );
	}
	return array( 'direct', '' );
}

/*
 * ---------------------------------------------------------------------------
 * Receiving events
 * ---------------------------------------------------------------------------
 */

/** The reading currently on a post, from the Recordings records (0.9.0). */
function rrt_listen_recording_id( $post_id ) {
	global $wpdb;
	$t = rrt_table( 'recordings' );
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE post_id = %d AND is_current = 1 ORDER BY id DESC LIMIT 1", $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
}

/** Whether a post is public and has a reading, so made-up post ids are refused. */
function rrt_listen_post_ok( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return false;
	}
	if ( ! is_post_publicly_viewable( $post ) || '' !== $post->post_password ) {
		return false;
	}
	$a = rrt_reading_audio( $post->ID );
	return ! empty( $a['url'] );
}

/** A number from an event, kept in range. */
function rrt_listen_num( $e, $k, $min = 0, $max = 86400 ) {
	if ( ! isset( $e[ $k ] ) || ! is_numeric( $e[ $k ] ) ) {
		return null;
	}
	return max( $min, min( $max, (float) $e[ $k ] ) );
}

/**
 * Handles one batch from listen.js. Always answers 204 with no body, so the
 * reply never tells anyone why a batch was or was not kept.
 */
function rrt_listen_receive( WP_REST_Request $req ) {
	$done = new WP_REST_Response( null, 204 );
	if ( ! rrt_listen_on() ) {
		return $done;
	}
	$s = rrt_settings();
	if ( ! empty( $s['listen']['dnt'] ) && rrt_listen_opted_out() ) {
		return $done;
	}
	$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ), 0, 500 ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	if ( rrt_listen_is_bot( $ua ) || rrt_listen_excluded_user() ) {
		return $done;
	}
	$raw = $req->get_body();
	if ( strlen( $raw ) > 24000 ) {
		return $done;
	}
	$b = json_decode( $raw, true );
	if ( ! is_array( $b ) || empty( $b['s'] ) || ! is_string( $b['s'] ) || ! preg_match( '/^[a-f0-9]{32}$/', $b['s'] ) ) {
		return $done;
	}
	$post_id = isset( $b['p'] ) ? absint( $b['p'] ) : 0;
	$events  = isset( $b['e'] ) && is_array( $b['e'] ) ? array_slice( $b['e'], 0, 60 ) : array();
	if ( ! $post_id || ! $events ) {
		return $done;
	}

	global $wpdb;
	$ts  = rrt_table( 'sessions' );
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $ts WHERE sid = %s", $b['s'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$now = current_time( 'mysql', true );

	if ( $row ) {
		if ( (int) $row['post_id'] !== $post_id || (int) $row['events'] > 1500 ) {
			return $done;
		}
	} else {
		if ( ! rrt_listen_post_ok( $post_id ) ) {
			return $done;
		}
		$ip   = rrt_listen_ip();
		$hash = rrt_listen_day_hash( $ip, $ua );
		/* Rate limit without storing anything new: at most 60 page views an hour from one listener id. */
		$recent = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $ts WHERE day_hash = %s AND started_at > %s", $hash, gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		if ( $recent >= 60 ) {
			return $done;
		}
		/* And per address, and for the whole site, however the browser name changes (0.10.1). */
		if ( ! rrt_listen_allow_new( $ip ) ) {
			return $done;
		}
		$m     = isset( $b['m'] ) && is_array( $b['m'] ) ? $b['m'] : array();
		$camp  = array();
		if ( isset( $m['c'] ) && is_array( $m['c'] ) ) {
			foreach ( array( 'source', 'medium', 'campaign', 'content' ) as $k ) {
				if ( isset( $m['c'][ $k ] ) && is_scalar( $m['c'][ $k ] ) ) {
					$camp[ $k ] = mb_substr( sanitize_text_field( (string) $m['c'][ $k ] ), 0, 100 );
				}
			}
		}
		$refd = isset( $m['r'] ) && is_string( $m['r'] ) ? strtolower( substr( preg_replace( '/[^A-Za-z0-9.\-]/', '', $m['r'] ), 0, 190 ) ) : '';
		list( $channel, $refd ) = rrt_listen_channel( $refd, $camp, $ua );
		$row = array(
			'sid'             => $b['s'],
			'day_hash'        => $hash,
			'post_id'         => $post_id,
			'recording_id'    => rrt_listen_recording_id( $post_id ),
			'day'             => wp_date( 'Y-m-d' ),
			'started_at'      => $now,
			'last_at'         => $now,
			'channel'         => $channel,
			'referrer_domain' => $refd,
			'utm_source'      => isset( $camp['source'] ) ? $camp['source'] : '',
			'utm_medium'      => isset( $camp['medium'] ) ? $camp['medium'] : '',
			'utm_campaign'    => isset( $camp['campaign'] ) ? $camp['campaign'] : '',
			'utm_content'     => isset( $camp['content'] ) ? $camp['content'] : '',
			'device'          => rrt_listen_device( $ua, isset( $m['tp'] ) ? (int) $m['tp'] : 0, isset( $m['w'] ) ? (int) $m['w'] : 0 ),
			'country'         => function_exists( 'rrt_geo_country' ) ? rrt_geo_country( $ip ) : '',
		);
		/* Returning listeners (1.2.0): only a yes or no, and only while the switch is on. */
		if ( ! empty( $s['listen']['returning'] ) && isset( $m['b'] ) && is_scalar( $m['b'] ) ) {
			$row['came_back'] = $m['b'] ? 1 : 0;
		}
		unset( $ip );
		$wpdb->insert( $ts, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			return $done;
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $ts WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	rrt_listen_apply( $row, $events, $now );
	return $done;
}

/** Applies a batch of events to a session: its summary columns, its buckets and the raw event log. */
function rrt_listen_apply( $row, $events, $now ) {
	global $wpdb;
	$sid  = (int) $row['id'];
	$up   = array();
	$get  = function ( $k ) use ( &$up, $row ) {
		return array_key_exists( $k, $up ) ? $up[ $k ] : $row[ $k ];
	};
	$heard = array();
	$log   = array();
	$types = array( 'view', 'ready', 'play', 'pause', 'progress', 'seek', 'rate', 'volume', 'ended', 'hidden', 'visible', 'leave', 'follow', 'download', 'error', 'scroll', 'page' );
	$last  = (int) $row['last_ms'];

	foreach ( $events as $e ) {
		if ( ! is_array( $e ) || empty( $e['k'] ) || ! in_array( $e['k'], $types, true ) ) {
			continue;
		}
		$k  = $e['k'];
		$t  = (float) rrt_listen_num( $e, 't', 0, 86400 );
		$ms = (int) rrt_listen_num( $e, 'ms', 0, 86400000 );
		$last = max( $last, $ms );
		$data = array();

		switch ( $k ) {
			case 'ready':
				$d = rrt_listen_num( $e, 'duration', 0, 86400 );
				if ( $d ) {
					$up['duration_s'] = $d;
					$data['duration'] = $d;
				}
				break;
			case 'play':
				$up['played'] = 1;
				if ( null === $get( 'first_play_s' ) ) {
					$up['first_play_s'] = round( $ms / 1000, 1 );
				}
				break;
			case 'progress':
				$bk = (int) rrt_listen_num( $e, 'bucket', 0, 20000 );
				$r  = rrt_listen_num( $e, 'rate', 0.25, 4 );
				$r  = $r ? $r : 1;
				$heard[] = array( $bk, $r );
				$up['rate_sum'] = (float) $get( 'rate_sum' ) + $r;
				$up['rate_n']   = (int) $get( 'rate_n' ) + 1;
				$dur = (float) $get( 'duration_s' );
				$up['max_position_s'] = max( (float) $get( 'max_position_s' ), $dur > 0 ? min( ( $bk + 1 ) * 5, $dur ) : ( $bk + 1 ) * 5 );
				if ( ! empty( $e['end'] ) || ( $dur > 0 && $bk >= floor( max( 0, $dur - 0.05 ) / 5 ) ) ) {
					$up['completed'] = 1;
				}
				$data = array( 'bucket' => $bk, 'rate' => $r );
				if ( ! empty( $e['end'] ) ) {
					$data['end'] = 1;
				}
				break;
			case 'seek':
				$from = (float) rrt_listen_num( $e, 'from', 0, 86400 );
				$to   = (float) rrt_listen_num( $e, 'to', 0, 86400 );
				if ( $to < $from - 1 ) {
					$up['seeks_back'] = (int) $get( 'seeks_back' ) + 1;
				} elseif ( $to > $from + 1 ) {
					$up['seeks_forward'] = (int) $get( 'seeks_forward' ) + 1;
				}
				$how  = isset( $e['how'] ) && is_string( $e['how'] ) ? preg_replace( '/[^a-z]/', '', substr( $e['how'], 0, 10 ) ) : '';
				$data = array( 'from' => $from, 'to' => $to, 'how' => $how );
				break;
			case 'rate':
				$up['rate_changes'] = (int) $get( 'rate_changes' ) + 1;
				$data = array( 'from' => rrt_listen_num( $e, 'from', 0, 4 ), 'to' => rrt_listen_num( $e, 'to', 0, 4 ) );
				break;
			case 'volume':
				$data = array( 'volume' => rrt_listen_num( $e, 'volume', 0, 1 ), 'muted' => empty( $e['muted'] ) ? 0 : 1 );
				break;
			case 'ended':
				$up['completed'] = 1;
				$up['exit_type'] = 'ended';
				break;
			case 'hidden':
				$up['hidden_at_ms'] = $ms;
				break;
			case 'visible':
			case 'leave':
				$h = $get( 'hidden_at_ms' );
				if ( null !== $h && '' !== $h ) {
					$up['hidden_s']     = (int) $get( 'hidden_s' ) + (int) round( max( 0, $ms - (int) $h ) / 1000 );
					$up['hidden_at_ms'] = null;
				}
				if ( 'leave' === $k ) {
					$playing = ! empty( $e['playing'] );
					if ( 'ended' !== $get( 'exit_type' ) || $playing ) {
						$up['exit_type'] = $playing ? 'closed' : ( $get( 'played' ) ? 'left' : 'noplay' );
					}
					$vis = rrt_listen_num( $e, 'vis', 0, 86400 );
					if ( null !== $vis ) {
						$up['time_on_page_s'] = max( (int) $get( 'time_on_page_s' ), (int) $vis );
					}
					$data = array( 'playing' => $playing ? 1 : 0 );
					if ( null !== $vis ) {
						$data['vis'] = (int) $vis;
					}
				}
				break;
			case 'page':
				$vis = rrt_listen_num( $e, 'vis', 0, 86400 );
				if ( null !== $vis ) {
					$up['time_on_page_s'] = max( (int) $get( 'time_on_page_s' ), (int) $vis );
					$data = array( 'vis' => (int) $vis );
				}
				break;
			case 'pause':
				if ( 'ended' !== $get( 'exit_type' ) ) {
					$up['exit_type'] = 'pause';
				}
				break;
			case 'follow':
				if ( ! empty( $e['on'] ) ) {
					$up['follow_used'] = 1;
				}
				$data = array( 'on' => empty( $e['on'] ) ? 0 : 1 );
				break;
			case 'download':
				$up['downloaded'] = 1;
				break;
			case 'error':
				$up['errors'] = (int) $get( 'errors' ) + 1;
				$data = array( 'code' => (int) rrt_listen_num( $e, 'code', 0, 99 ) );
				break;
			case 'scroll':
				$dp = (int) rrt_listen_num( $e, 'depth', 0, 100 );
				$up['scroll_max'] = max( (int) $get( 'scroll_max' ), $dp );
				$data = array( 'depth' => $dp );
				break;
		}
		$log[] = array( $k, round( $t, 1 ), $ms, $data ? wp_json_encode( $data ) : null );
	}

	/* Buckets heard: one row per session and bucket, counting replays. */
	$tb = rrt_table( 'buckets' );
	$new_buckets = 0;
	foreach ( $heard as $h ) {
		$n = $wpdb->query( $wpdb->prepare( "UPDATE $tb SET plays = plays + 1 WHERE session_id = %d AND bucket = %d", $sid, $h[0] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		if ( ! $n ) {
			$wpdb->insert( $tb, array( 'session_id' => $sid, 'bucket' => $h[0], 'plays' => 1, 'rate' => $h[1] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			++$new_buckets;
		}
	}
	if ( $new_buckets ) {
		$up['buckets_heard'] = (int) $row['buckets_heard'] + $new_buckets;
	}

	/*
	 * Impossible timing: more audio heard than the time the page has been open
	 * allows, even at the fastest speed. Real players never do that.
	 */
	$heard_s = ( isset( $up['buckets_heard'] ) ? $up['buckets_heard'] : (int) $row['buckets_heard'] ) * 5;
	if ( $heard_s > ( $last / 1000 ) * 2.5 + 15 ) {
		$up['junk'] = 1;
	}

	/* Raw events, for checking and recomputing; trimmed after the retention period. */
	if ( $log ) {
		$te     = rrt_table( 'events' );
		$values = array();
		$args   = array();
		foreach ( $log as $l ) {
			$values[] = '(%d, %s, %f, %d, %s, %s)';
			array_push( $args, $sid, $l[0], $l[1], $l[2], $now, (string) $l[3] );
		}
		$wpdb->query( $wpdb->prepare( "INSERT INTO $te (session_id, type, t, ms, created_at, data) VALUES " . implode( ',', $values ), $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- only the plugin's own table name and whole numbers
	}

	$up['events']  = (int) $row['events'] + count( $log );
	$up['last_ms'] = $last;
	$up['last_at'] = $now;
	$up['rolled']  = 0;
	$wpdb->update( rrt_table( 'sessions' ), $up, array( 'id' => $sid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'rrt/v1',
			'/events',
			array(
				'methods'             => 'POST',
				'callback'            => 'rrt_listen_receive',
				'permission_callback' => '__return_true', // Public by design: anonymous listeners. Everything is checked inside.
			)
		);
	}
);

/*
 * ---------------------------------------------------------------------------
 * Daily summaries and trimming
 * ---------------------------------------------------------------------------
 */

/** A session counts as finished 30 minutes after its last event. */
define( 'RRT_LISTEN_IDLE', 30 * MINUTE_IN_SECONDS );

/*
 * Read or listen (1.2.0). A real listen is at least this many seconds heard,
 * or the whole reading. Reading means scrolling this far through the post's
 * text, with the page on screen for at least this share of the time an
 * average silent reader needs for its words at this pace.
 */
define( 'RRT_REAL_LISTEN_S', 30 );
define( 'RRT_READ_SCROLL', 75 );
define( 'RRT_READ_WPM', 238 );
define( 'RRT_READ_SHARE', 1 / 3 );

/** The words in a post's text. */
function rrt_listen_words( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return 0;
	}
	$text = html_entity_decode( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), ENT_QUOTES, 'UTF-8' );
	return count( preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY ) );
}

/** Seconds on screen a visit needs to count as reading the post: at least 5. */
function rrt_listen_read_need( $post_id ) {
	static $cache = array();
	if ( ! isset( $cache[ $post_id ] ) ) {
		$cache[ $post_id ] = max( 5, (int) ceil( rrt_listen_words( $post_id ) / RRT_READ_WPM * 60 * RRT_READ_SHARE ) );
	}
	return $cache[ $post_id ];
}

/** Phones and tablets ('m') or computers ('c'). */
function rrt_listen_device_group( $device ) {
	return in_array( $device, array( 'phone', 'tablet' ), true ) ? 'm' : 'c';
}

/**
 * The histogram bin for seconds before the first play: whole seconds under a
 * minute, 5 seconds under 5 minutes, 30 seconds under half an hour, then one
 * bin for everything longer. Fine enough for an honest median.
 */
function rrt_listen_hist_bin( $s ) {
	$s = max( 0, (float) $s );
	if ( $s < 60 ) {
		return (int) floor( $s );
	}
	if ( $s < 300 ) {
		return (int) ( floor( $s / 5 ) * 5 );
	}
	if ( $s < 1800 ) {
		return (int) ( floor( $s / 30 ) * 30 );
	}
	return 1800;
}

/**
 * Rebuilds the daily summaries for one day from its sessions. Safe to run
 * any number of times: the day's rows are replaced, never added to.
 *
 * All the page views and plays of one listener id on one reading in one day
 * count once (the IAB's 24 hour deduplication, within the day the listener
 * id lasts), so refreshing the page does not add plays. Listening time adds
 * up across those page views; a stretch heard in any of them counts as heard.
 */
function rrt_listen_rollup_day( $day ) {
	global $wpdb;
	$ts = rrt_table( 'sessions' );
	$tb = rrt_table( 'buckets' );
	$sessions = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $ts WHERE day = %s AND junk = 0", $day ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

	$units = array();
	foreach ( $sessions as $r ) {
		$key = $r['day_hash'] . '|' . $r['post_id'] . '|' . $r['recording_id'];
		if ( ! isset( $units[ $key ] ) ) {
			$units[ $key ] = array(
				'post'     => (int) $r['post_id'],
				'rec'      => (int) $r['recording_id'],
				'channel'  => $r['channel'],
				'campaign' => $r['utm_campaign'],
				'loads'    => 0,
				'played'   => 0,
				'completed' => 0,
				'first'    => null,
				'dur'      => 0,
				'hidden'   => 0,
				'follow'   => 0,
				'rate_sum' => 0,
				'rate_n'   => 0,
				'ids'      => array(),
				'scroll'   => 0,
				'vis'      => 0,
				'back'     => null,
				'device'   => $r['device'],
			);
		}
		$u = &$units[ $key ];
		++$u['loads'];
		$u['ids'][] = (int) $r['id'];
		/* Read or listen (1.2.0): the furthest scroll and the time on screen of all the unit's page views. */
		$u['scroll'] = max( $u['scroll'], (int) $r['scroll_max'] );
		$u['vis']   += (int) $r['time_on_page_s'];
		if ( isset( $r['came_back'] ) && null !== $r['came_back'] ) {
			$u['back'] = max( (int) $u['back'], (int) $r['came_back'] );
		}
		if ( $r['played'] ) {
			if ( ! $u['played'] ) {
				/* The source and device of the first page view that played. */
				$u['channel']  = $r['channel'];
				$u['campaign'] = $r['utm_campaign'];
				$u['device']   = $r['device'];
			}
			$u['played'] = 1;
			if ( null !== $r['first_play_s'] ) {
				$u['first'] = null === $u['first'] ? (float) $r['first_play_s'] : min( $u['first'], (float) $r['first_play_s'] );
			}
		}
		$u['completed'] = max( $u['completed'], (int) $r['completed'] );
		$u['dur']       = max( $u['dur'], (float) $r['duration_s'] );
		$u['hidden']   += (int) $r['hidden_s'];
		$u['follow']    = max( $u['follow'], (int) $r['follow_used'] );
		$u['rate_sum'] += (float) $r['rate_sum'];
		$u['rate_n']   += (int) $r['rate_n'];
		unset( $u );
	}

	/* The buckets each unit heard, with replays and speed. */
	$ids = array();
	foreach ( $units as $u ) {
		$ids = array_merge( $ids, $u['ids'] );
	}
	$by_session = array();
	foreach ( array_chunk( $ids, 500 ) as $chunk ) {
		$in   = implode( ',', array_map( 'intval', $chunk ) );
		$rows = $wpdb->get_results( "SELECT session_id, bucket, plays, rate FROM $tb WHERE session_id IN ($in)", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		foreach ( $rows as $b ) {
			$by_session[ (int) $b['session_id'] ][] = $b;
		}
	}

	$daily   = array();
	$sources = array();
	$site    = array( 'loads' => 0, 'views' => 0, 'plays' => 0 );
	foreach ( $units as $u ) {
		$dk = $u['post'] . '|' . $u['rec'];
		if ( ! isset( $daily[ $dk ] ) ) {
			$daily[ $dk ] = array(
				'post_id' => $u['post'], 'recording_id' => $u['rec'], 'page_loads' => 0, 'views' => 0, 'plays' => 0,
				'listened_s' => 0, 'completions' => 0, 'q25' => 0, 'q50' => 0, 'q75' => 0, 'held60' => 0,
				'rate_sum' => 0, 'rate_n' => 0, 'first' => array(), 'hidden_s' => 0, 'follow_plays' => 0,
				'heard' => array(), 'replayed' => array(), 'faster' => array(), 'slower' => array(), 'skipped' => array(), 'dur' => 0,
				'v_listen' => 0, 'v_both' => 0, 'v_read' => 0, 'v_pass' => 0, 'l_new' => 0, 'l_back' => 0, 'hist' => array(),
			);
		}
		$d = &$daily[ $dk ];
		$d['page_loads'] += $u['loads'];
		++$d['views'];
		$heard = array();
		$replay = array();
		$speed = array();
		foreach ( $u['ids'] as $id ) {
			if ( empty( $by_session[ $id ] ) ) {
				continue;
			}
			foreach ( $by_session[ $id ] as $b ) {
				$k = (int) $b['bucket'];
				if ( isset( $heard[ $k ] ) || (int) $b['plays'] > 1 ) {
					$replay[ $k ] = 1;
				}
				$heard[ $k ] = 1;
				$speed[ $k ]  = (float) $b['rate'];
			}
		}
		$listened = count( $heard ) * 5;
		if ( $u['dur'] > 0 ) {
			$listened = min( $listened, (int) ceil( $u['dur'] ) );
		}
		/* Read or listen (1.2.0): every unit falls in exactly one of the four groups. */
		$real = $u['played'] && ( $listened >= RRT_REAL_LISTEN_S || $u['completed'] );
		$read = $u['scroll'] >= RRT_READ_SCROLL && $u['vis'] >= rrt_listen_read_need( $u['post'] );
		++$d[ $real ? ( $read ? 'v_both' : 'v_listen' ) : ( $read ? 'v_read' : 'v_pass' ) ];
		if ( $real && null !== $u['back'] ) {
			++$d[ $u['back'] ? 'l_back' : 'l_new' ];
		}
		if ( $u['played'] && null !== $u['first'] ) {
			$dev = rrt_listen_device_group( $u['device'] );
			$bin = (string) rrt_listen_hist_bin( $u['first'] );
			$d['hist'][ $dev ][ $bin ] = ( isset( $d['hist'][ $dev ][ $bin ] ) ? $d['hist'][ $dev ][ $bin ] : 0 ) + 1;
		}
		$sk = $dk . '|' . $u['channel'] . '|' . $u['campaign'];
		if ( ! isset( $sources[ $sk ] ) ) {
			$sources[ $sk ] = array( 'post_id' => $u['post'], 'recording_id' => $u['rec'], 'channel' => $u['channel'], 'campaign' => $u['campaign'], 'views' => 0, 'plays' => 0, 'listened_s' => 0, 'completions' => 0 );
		}
		++$sources[ $sk ]['views'];
		if ( $u['played'] ) {
			++$d['plays'];
			++$sources[ $sk ]['plays'];
			$d['listened_s']            += $listened;
			$sources[ $sk ]['listened_s'] += $listened;
			if ( $u['completed'] ) {
				++$d['completions'];
				++$sources[ $sk ]['completions'];
			}
			$dur = $u['dur'] > 0 ? $u['dur'] : 0;
			$d['dur'] = max( $d['dur'], $dur );
			if ( $dur > 0 ) {
				$reach = $u['completed'] ? $dur : ( $heard ? ( max( array_keys( $heard ) ) + 1 ) * 5 : 0 );
				foreach ( array( 'q25' => 0.25, 'q50' => 0.5, 'q75' => 0.75 ) as $q => $f ) {
					if ( $reach >= $dur * $f ) {
						++$d[ $q ];
					}
				}
				/* Opening hold: still listening at 60 seconds, or a quarter of a short reading. */
				$hold = min( 60, $dur / 4 );
				if ( $reach >= $hold ) {
					++$d['held60'];
				}
			}
			if ( null !== $u['first'] ) {
				$d['first'][] = $u['first'];
			}
			$d['rate_sum'] += $u['rate_sum'];
			$d['rate_n']   += $u['rate_n'];
			$d['hidden_s'] += $u['hidden'];
			$d['follow_plays'] += $u['follow'];
			foreach ( $heard as $k => $one ) {
				$d['heard'][ $k ] = ( isset( $d['heard'][ $k ] ) ? $d['heard'][ $k ] : 0 ) + 1;
				if ( isset( $replay[ $k ] ) ) {
					$d['replayed'][ $k ] = ( isset( $d['replayed'][ $k ] ) ? $d['replayed'][ $k ] : 0 ) + 1;
				}
				if ( $speed[ $k ] > 1.01 ) {
					$d['faster'][ $k ] = ( isset( $d['faster'][ $k ] ) ? $d['faster'][ $k ] : 0 ) + 1;
				} elseif ( $speed[ $k ] < 0.99 ) {
					$d['slower'][ $k ] = ( isset( $d['slower'][ $k ] ) ? $d['slower'][ $k ] : 0 ) + 1;
				}
			}
			/* Skipped: stretches jumped over, between the first and last stretch this listener heard. */
			if ( $heard ) {
				$lo = min( array_keys( $heard ) );
				$hi = max( array_keys( $heard ) );
				for ( $k = $lo + 1; $k < $hi; $k++ ) {
					if ( ! isset( $heard[ $k ] ) ) {
						$d['skipped'][ $k ] = ( isset( $d['skipped'][ $k ] ) ? $d['skipped'][ $k ] : 0 ) + 1;
					}
				}
			}
		}
		unset( $d );
	}

	$td = rrt_table( 'daily' );
	$tsrc = rrt_table( 'daily_sources' );
	$wpdb->delete( $td, array( 'day' => $day ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->delete( $tsrc, array( 'day' => $day ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	foreach ( $daily as $d ) {
		$n = $d['heard'] ? max( array_keys( $d['heard'] ) ) + 1 : 0;
		if ( $d['dur'] > 0 ) {
			$n = max( $n, (int) ceil( $d['dur'] / 5 ) );
		}
		$arr = function ( $m ) use ( $n ) {
			$o = array_fill( 0, $n, 0 );
			foreach ( $m as $k => $v ) {
				if ( $k < $n ) {
					$o[ $k ] = $v;
				}
			}
			return $o;
		};
		sort( $d['first'] );
		$median = $d['first'] ? $d['first'][ (int) floor( ( count( $d['first'] ) - 1 ) / 2 ) ] : null;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$td,
			array(
				'day'          => $day,
				'post_id'      => $d['post_id'],
				'recording_id' => $d['recording_id'],
				'page_loads'   => $d['page_loads'],
				'views'        => $d['views'],
				'plays'        => $d['plays'],
				'listened_s'   => $d['listened_s'],
				'completions'  => $d['completions'],
				'q25'          => $d['q25'],
				'q50'          => $d['q50'],
				'q75'          => $d['q75'],
				'held60'       => $d['held60'],
				'avg_rate'     => $d['rate_n'] ? round( $d['rate_sum'] / $d['rate_n'], 3 ) : null,
				'first_play_s' => $median,
				'hidden_s'     => $d['hidden_s'],
				'follow_plays' => $d['follow_plays'],
				'v_listen'     => $d['v_listen'],
				'v_both'       => $d['v_both'],
				'v_read'       => $d['v_read'],
				'v_pass'       => $d['v_pass'],
				'l_new'        => $d['l_new'],
				'l_back'       => $d['l_back'],
				'first_hist'   => $d['hist'] ? wp_json_encode( $d['hist'] ) : null,
				'buckets'      => wp_json_encode(
					array(
						'size'     => 5,
						'heard'    => $arr( $d['heard'] ),
						'replayed' => $arr( $d['replayed'] ),
						'faster'   => $arr( $d['faster'] ),
						'slower'   => $arr( $d['slower'] ),
						'skipped'  => $arr( $d['skipped'] ),
					)
				),
			)
		);
	}
	foreach ( $sources as $r ) {
		$r['day'] = $day;
		$wpdb->insert( $tsrc, $r ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}

/** The hourly job: rolls up days that have newly finished sessions. */
function rrt_listen_rollup() {
	global $wpdb;
	$ts   = rrt_table( 'sessions' );
	$cut  = gmdate( 'Y-m-d H:i:s', time() - RRT_LISTEN_IDLE );
	$days = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT day FROM $ts WHERE rolled = 0 AND last_at < %s ORDER BY day LIMIT 40", $cut ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	foreach ( $days as $day ) {
		/* Marked first, so a session that gets a late event during the rebuild is picked up next time. */
		$wpdb->query( $wpdb->prepare( "UPDATE $ts SET rolled = 1 WHERE day = %s AND rolled = 0 AND last_at < %s", $day, $cut ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		rrt_listen_rollup_day( $day );
	}
	update_option( 'rrt_listen_rolled_at', time(), false );
}

/** The daily job: removes raw events and sessions older than Settings keeps them. */
function rrt_listen_trim() {
	global $wpdb;
	$s  = rrt_settings();
	$ev = max( 1, (int) $s['listen']['events_days'] );
	$se = max( 30, (int) $s['listen']['sessions_days'] );
	$te = rrt_table( 'events' );
	$ts = rrt_table( 'sessions' );
	$tb = rrt_table( 'buckets' );
	$wpdb->query( $wpdb->prepare( "DELETE FROM $te WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - $ev * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$old = gmdate( 'Y-m-d H:i:s', time() - $se * DAY_IN_SECONDS );
	$wpdb->query( $wpdb->prepare( "DELETE FROM $tb WHERE session_id IN (SELECT id FROM $ts WHERE started_at < %s)", $old ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$wpdb->query( $wpdb->prepare( "DELETE FROM $ts WHERE started_at < %s", $old ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	/* Yesterday's salt is already gone; this makes sure even a quiet site replaces it. */
	rrt_listen_salt();
}

add_action( 'rrt_listen_hourly', 'rrt_listen_rollup' );
add_action( 'rrt_listen_daily', 'rrt_listen_trim' );
add_action(
	'init',
	function () {
		if ( ! wp_next_scheduled( 'rrt_listen_hourly' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'rrt_listen_hourly' );
		}
		if ( ! wp_next_scheduled( 'rrt_listen_daily' ) ) {
			wp_schedule_event( time() + 600, 'daily', 'rrt_listen_daily' );
		}
	}
);
register_deactivation_hook(
	RRT_FILE,
	function () {
		wp_clear_scheduled_hook( 'rrt_listen_hourly' );
		wp_clear_scheduled_hook( 'rrt_listen_daily' );
	}
);

/*
 * ---------------------------------------------------------------------------
 * For the settings screen
 * ---------------------------------------------------------------------------
 */

/** Counts for the settings screen. */
function rrt_listen_counts() {
	global $wpdb;
	$ts  = rrt_table( 'sessions' );
	$td  = rrt_table( 'daily' );
	$day = wp_date( 'Y-m-d' );
	return array(
		'today_views' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM (SELECT DISTINCT day_hash, post_id FROM $ts WHERE day = %s AND junk = 0) x", $day ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		'today_plays' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM (SELECT DISTINCT day_hash, post_id FROM $ts WHERE day = %s AND junk = 0 AND played = 1) x", $day ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		'sessions'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $ts" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		'days'        => (int) $wpdb->get_var( "SELECT COUNT(DISTINCT day) FROM $td" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	);
}

/** The most recent page views with a reading, newest first. */
function rrt_listen_recent( $limit = 25 ) {
	global $wpdb;
	$ts = rrt_table( 'sessions' );
	return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $ts ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
}

/** The paragraph for the site's privacy policy. */
function rrt_listen_privacy_text( $cfg = null ) {
	$cfg = $cfg ? $cfg : rrt_settings();
	$l   = $cfg['listen'];
	$p   = array();
	if ( empty( $l['on'] ) ) {
		return implode( "\n\n", rrt_listen_privacy_extra( $cfg ) );
	}
	$p[] = __( 'When you play the audio reading of a post, this site measures how the reading is listened to: when it is played and paused, which parts are heard, changes of speed and volume, jumps forward or back, whether it is heard to the end, how far down the page you scroll, how long the page is open, the site or link that brought you here (only the site\'s name and any campaign tag on the link), whether you use a phone, tablet or computer, and your country.', 'foxfire-studio' );
	$p[] = __( 'This is measured by this site itself. Nothing is sent to any other company, and no cookies are used for it. Your IP address is never stored: it is combined with your browser\'s name and scrambled with a random code that is replaced and thrown away every day, so the result cannot be traced back to you or linked from one day to the next. Your country is worked out from your IP address at the moment of the visit, and only the country is kept.', 'foxfire-studio' );
	if ( ! empty( $l['dnt'] ) ) {
		$p[] = __( 'If your browser sends a Do Not Track or Global Privacy Control signal, none of this is measured.', 'foxfire-studio' );
	}
	$p[] = sprintf( /* translators: 1: (int) $l['events_days'], 2: (int) $l['sessions_days']. */ __( 'The detailed record of each visit is deleted after %1$d days, and each visit\'s summary after %2$d days. Daily totals, which contain nothing about any one visitor, are kept.', 'foxfire-studio' ), (int) $l['events_days'], (int) $l['sessions_days'] );
	if ( function_exists( 'rrt_geo_ready' ) && rrt_geo_ready() ) {
		$p[] = __( 'Countries are looked up with IP Geolocation by DB-IP (https://db-ip.com), used under the Creative Commons Attribution 4.0 licence.', 'foxfire-studio' );
	}
	if ( ! empty( $l['returning'] ) ) {
		$p[] = sprintf(
			/* translators: %d: number of months. */
			_n(
				'To tell new listeners from returning ones, after you listen for 30 seconds your browser keeps the month in its own storage on your device. On a later visit within %d month, the player tells this site only that you have listened here before, never when. You can remove it by clearing this site\'s data in your browser.',
				'To tell new listeners from returning ones, after you listen for 30 seconds your browser keeps the month in its own storage on your device. On a later visit within %d months, the player tells this site only that you have listened here before, never when. You can remove it by clearing this site\'s data in your browser.',
				(int) $l['returning_months'],
				'foxfire-studio'
			),
			(int) $l['returning_months']
		);
	}
	return implode( "\n\n", array_merge( $p, rrt_listen_privacy_extra( $cfg ) ) );
}

/** Privacy paragraphs that apply whether or not Measure listening is on (1.2.0). */
function rrt_listen_privacy_extra( $cfg ) {
	$p = array();
	if ( ! empty( $cfg['listen']['ga'] ) ) {
		$p[] = __( 'When you play an audio reading, this site also tells Google Analytics that a reading was started, reached halfway or was heard to the end, with the reading\'s title. Google Analytics is subject to Google\'s own privacy policy.', 'foxfire-studio' );
	}
	if ( function_exists( 'rrt_dl_on' ) && rrt_dl_on() ) {
		$p[] = __( 'When a podcast app downloads an episode, this site counts the download and notes which app and kind of device it was, worked out from the app\'s name, following the IAB Tech Lab podcast measurement guidelines. The IP address is never stored: it is scrambled with a random code that is replaced every day, only to count each listener once a day, and is checked against a list of data centre and VPN networks, which this site downloads once a month (nothing about visitors is sent to get it). For members\' private feeds the member is counted the same way, and only totals are kept, never which member downloaded what.', 'foxfire-studio' );
		/* Measurement prefixes (1.24.0). */
		$hosts = array();
		foreach ( function_exists( 'rrt_dl_prefixes' ) ? rrt_dl_prefixes() : array() as $pre ) {
			$hosts[] = (string) wp_parse_url( $pre, PHP_URL_HOST );
		}
		if ( $hosts ) {
			/* translators: %s: a list of host names, such as op3.dev. */
			$p[] = sprintf( __( 'Podcast apps reach this site\'s episodes through %s first, which counts downloads for this podcast and sees the app\'s IP address and name, as any web server does. Those services have their own privacy policies.', 'foxfire-studio' ), implode( ', ', array_unique( $hosts ) ) );
		}
	}
	if ( function_exists( 'rrt_ping_on' ) && rrt_ping_on() ) {
		$hub = rrt_ping_hub();
		$p[] = RRT_PING_HUB === $hub
			? __( 'When a new episode is added to this site\'s podcast feed, the site tells Podcast Index and a public WebSub hub run by Google the feed\'s address, so podcast apps learn of it sooner. Nothing about visitors or listeners is sent.', 'foxfire-studio' )
			/* translators: %s: the hub's host name. */
			: sprintf( __( 'When a new episode is added to this site\'s podcast feed, the site tells Podcast Index and the WebSub hub at %s the feed\'s address, so podcast apps learn of it sooner. Nothing about visitors or listeners is sent.', 'foxfire-studio' ), (string) wp_parse_url( $hub, PHP_URL_HOST ) );
	}
	/* Member feeds (1.21.0). */
	if ( function_exists( 'rrt_member_on' ) && rrt_member_on() ) {
		$p[] = __( 'If you are a member, your account has its own podcast feed address, made of a random code kept with your account. The feed itself contains nothing about you. Podcast apps that fetch it on their own servers see only the address.', 'foxfire-studio' );
		if ( rrt_member_settings()['activity'] ) {
			$p[] = __( 'To notice a members feed address that may have been shared, this site keeps, for each member, when their feed was last fetched, which podcast app fetched it (from the app\'s name), and how many different networks fetched it in the last 7 days. The networks are counted by scrambling the IP address with a random code that is replaced every day, so no IP address is kept. These notes are deleted after 30 days.', 'foxfire-studio' );
		}
	}
	/* YouTube and SoundCloud players that load on play (1.35.0). */
	if ( function_exists( 'rrt_embeds_privacy_text' ) ) {
		$emb = rrt_embeds_privacy_text();
		if ( '' !== $emb ) {
			$p[] = $emb;
		}
	}
	/* Storage offload (1.23.0): who delivers the files. */
	if ( function_exists( 'rrt_off_privacy_text' ) ) {
		$off = rrt_off_privacy_text();
		if ( '' !== $off ) {
			$p[] = $off;
		}
	}
	return $p;
}

/** Whether there is anything for the privacy policy guide: measuring, or an option that sends or counts. */
function rrt_listen_privacy_needed() {
	$s = rrt_settings();
	return ! empty( $s['listen']['on'] ) || ! empty( $s['listen']['ga'] ) || ( function_exists( 'rrt_dl_on' ) && rrt_dl_on() ) || ( function_exists( 'rrt_ping_on' ) && rrt_ping_on() ) || ( function_exists( 'rrt_member_on' ) && rrt_member_on() ) || ( function_exists( 'rrt_off_privacy_text' ) && '' !== rrt_off_privacy_text() ) || ( function_exists( 'rrt_embeds_privacy_text' ) && '' !== rrt_embeds_privacy_text() );
}

/* Offer the paragraph in WordPress's privacy policy guide (Settings, Privacy). */
add_action(
	'admin_init',
	function () {
		if ( function_exists( 'wp_add_privacy_policy_content' ) && rrt_listen_privacy_needed() ) {
			wp_add_privacy_policy_content( __( 'Foxfire Studio', 'foxfire-studio' ), wpautop( esc_html( rrt_listen_privacy_text() ) ) );
		}
	}
);
