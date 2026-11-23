<?php
/**
 * Who is asking: podcast apps, bots and devices from their user agent (1.24.0).
 *
 * The names and patterns come from OPAWG's open user-agents-v2 list and
 * OP3's extra bot list (data/agents.php, MIT, rebuilt for each release by
 * tools/lists/agents.py). Matching follows OPAWG's own order: bots, then
 * apps, then libraries, then browsers, the first pattern that matches wins,
 * and devices are looked up separately for anything that is not a bot.
 *
 * On top of the lists, as the IAB Tech Lab guidelines (v2.3) ask:
 * - Apple Watch duplicates (atc/... watchOS, or (null)/(null) watchOS) are
 *   never counted;
 * - an empty, overlong or broken user agent (control characters) is never
 *   counted;
 * - a user agent no list knows still counts, as "Other", unless it calls
 *   itself a bot, crawler or spider.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The lists, loaded once per request. */
function rrt_ua_lists() {
	static $lists = null;
	if ( null === $lists ) {
		$f     = RRT_DIR . 'data/agents.php';
		$lists = is_readable( $f ) ? include $f : array();
		if ( ! is_array( $lists ) ) {
			$lists = array();
		}
		foreach ( array( 'bots', 'apps', 'libraries', 'browsers', 'devices', 'extra' ) as $k ) {
			if ( ! isset( $lists[ $k ] ) || ! is_array( $lists[ $k ] ) ) {
				$lists[ $k ] = array();
			}
		}
	}
	return $lists;
}

/** When the lists were made, for the method page. */
function rrt_ua_lists_date() {
	$l = rrt_ua_lists();
	return isset( $l['source'] ) ? (string) $l['source'] : '';
}

/** Whether a user agent is one of Apple Watch's duplicate downloads (IAB v2.3, 5.5.2 and 8.1). */
function rrt_ua_is_watch( $ua ) {
	return (bool) preg_match( '#^atc/.*watchOS|\(null\)/\(null\) watchOS#i', (string) $ua );
}

/**
 * What a user agent is.
 *
 * @param string $ua The User-Agent header as it arrived.
 * @return array type ('bot', 'app', 'library', 'browser', 'unknown', 'watch'
 *               or 'bad'), name, device (a category or ''), and count (bool):
 *               whether a download from it may count.
 */
function rrt_ua_info( $ua ) {
	static $memo = array();
	$ua = (string) $ua;
	if ( isset( $memo[ $ua ] ) ) {
		return $memo[ $ua ];
	}
	$out = array(
		'type'   => 'unknown',
		'name'   => 'Other',
		'device' => '',
		'count'  => false,
	);
	$t = trim( $ua );
	if ( '' === $t ) {
		$out['type'] = 'bad';
		$out['name'] = '';
	} elseif ( strlen( $ua ) > 500 || preg_match( '/[\x00-\x1f\x7f]/', $ua ) ) {
		$out['type'] = 'bad';
		$out['name'] = '';
	} elseif ( rrt_ua_is_watch( $t ) ) {
		$out['type'] = 'watch';
		$out['name'] = 'Apple Watch';
	} else {
		$l = rrt_ua_lists();
		if ( isset( $l['extra'][ $t ] ) ) {
			$out['type'] = 'bot';
			$out['name'] = $t;
		} else {
			foreach ( array( 'bots' => 'bot', 'apps' => 'app', 'libraries' => 'library', 'browsers' => 'browser' ) as $k => $type ) {
				foreach ( $l[ $k ] as $e ) {
					if ( preg_match( $e[1], $t ) ) {
						$out['type'] = ( 'library' === $type && isset( $e[2] ) && 'bot' === $e[2] ) ? 'bot' : $type;
						$out['name'] = (string) $e[0];
						break 2;
					}
				}
			}
			if ( 'unknown' === $out['type'] && preg_match( '/bot\b|bot\/|crawl|spider|slurp|headless|phantomjs|lighthouse|pagespeed|pingdom|uptime|validator/i', $t ) ) {
				$out['type'] = 'bot';
				$out['name'] = substr( $t, 0, 60 );
			}
		}
		if ( 'bot' !== $out['type'] ) {
			$out['count'] = true;
			foreach ( $l['devices'] as $e ) {
				if ( preg_match( $e[1], $t ) ) {
					$out['device'] = isset( $e[2] ) ? (string) $e[2] : '';
					break;
				}
			}
		}
	}
	if ( count( $memo ) < 200 ) {
		$memo[ $ua ] = $out;
	}
	return $out;
}

/** A device category as people say it. */
function rrt_ua_device_label( $cat ) {
	$labels = array(
		'mobile'        => __( 'Phone or tablet', 'foxfire-studio' ),
		'computer'      => __( 'Computer', 'foxfire-studio' ),
		'smart_speaker' => __( 'Smart speaker', 'foxfire-studio' ),
		'watch'         => __( 'Watch', 'foxfire-studio' ),
		'auto'          => __( 'Car', 'foxfire-studio' ),
		'smart_tv'      => __( 'TV', 'foxfire-studio' ),
	);
	return isset( $labels[ $cat ] ) ? $labels[ $cat ] : __( 'Not known', 'foxfire-studio' );
}

/** An app's stored name as the Overview shows it. */
function rrt_ua_app_label( $name ) {
	if ( 'AppleCoreMedia' === $name ) {
		return __( 'Apple\'s player (AppleCoreMedia)', 'foxfire-studio' );
	}
	if ( 'Other' === $name || '' === $name ) {
		return __( 'Other', 'foxfire-studio' );
	}
	return $name;
}
