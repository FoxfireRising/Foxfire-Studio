<?php
/**
 * The full Podcasting 2.0 feed (1.10.0): what the podcast feed adds on top
 * of 0.22.0's tags.
 *
 * - Chapters, read on the site from the ID3 chapter frames of each MP3 and
 *   served as a Podcasting 2.0 chapters file at
 *   /podcast-chapters/<post id>.json while the feed is published.
 * - A soundbite for each episode: the post's pick from Use for the feed,
 *   else the top suggestion from its captions, else the opening.
 * - Lines of text for the show (podcast:txt), most often codes a directory
 *   asks for to prove the show is yours.
 * - Pings: when a published post gets a reading, the feed's address is sent
 *   to Podcast Index and a WebSub hub, at most once every 10 minutes. Off
 *   until switched on, and only while the feed is published.
 *
 * The person, funding, medium and location tags are printed by the feed in
 * podcast.php from the Podcast feed settings.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The first part of a chapters file's address. */
define( 'RRT_CHAPTERS_BASE', 'podcast-chapters' );

/** Where each post keeps what the feed worked out about its reading, until the reading changes. */
define( 'RRT_FEED_META', '_rrt_feed' );

/** The fewest seconds between two pings. */
define( 'RRT_PING_GAP', 600 );

/** Podcast Index's notify address; the feed's address is added as ?url=. */
define( 'RRT_PING_INDEX', 'https://api.podcastindex.org/api/1.0/hub/pubnotify' );

/** The WebSub hub used unless another is given. */
define( 'RRT_PING_HUB', 'https://pubsubhubbub.appspot.com/' );

/** The most bytes of an MP3's tag read when looking for chapters. */
define( 'RRT_ID3_MAX', 8388608 );

/*
 * ---------------------------------------------------------------------
 * Chapters from an MP3's ID3 tag
 * ---------------------------------------------------------------------
 */

/** A text frame's string as UTF-8. $enc: 0 Latin-1, 1 UTF-16 with a byte order mark, 2 UTF-16BE, 3 UTF-8. */
function rrt_id3_text( $b, $enc ) {
	$b = (string) $b;
	if ( 1 === $enc || 2 === $enc ) {
		$from = 'UTF-16BE';
		if ( 1 === $enc && strlen( $b ) >= 2 ) {
			if ( "\xff\xfe" === substr( $b, 0, 2 ) ) {
				$from = 'UTF-16LE';
				$b    = substr( $b, 2 );
			} elseif ( "\xfe\xff" === substr( $b, 0, 2 ) ) {
				$b = substr( $b, 2 );
			}
		}
		$out = '';
		for ( $i = 0; $i + 1 < strlen( $b ); $i += 2 ) {
			if ( "\0\0" === substr( $b, $i, 2 ) ) {
				break;
			}
			$out .= substr( $b, $i, 2 );
		}
		return (string) mb_convert_encoding( $out, 'UTF-8', $from );
	}
	$z = strpos( $b, "\0" );
	$b = false === $z ? $b : substr( $b, 0, $z );
	return 3 === $enc ? (string) wp_check_invalid_utf8( $b, true ) : (string) mb_convert_encoding( $b, 'UTF-8', 'ISO-8859-1' );
}

/** A string ending at the next zero byte, and where the next field starts. */
function rrt_id3_cstr( $b, $at ) {
	$z = strpos( $b, "\0", $at );
	if ( false === $z ) {
		return array( substr( $b, $at ), strlen( $b ) );
	}
	return array( substr( $b, $at, $z - $at ), $z + 1 );
}

/** Calls $fn( id, body ) for each frame of a v2.3 or v2.4 body from $start to $end. Stops when $fn returns true. */
function rrt_id3_frames( $b, $start, $end, $v, $fn ) {
	$at = $start;
	while ( $at + 10 <= $end ) {
		$id = substr( $b, $at, 4 );
		if ( ! preg_match( '/^[A-Z0-9]{4}$/', $id ) ) {
			break;
		}
		$s    = array_values( unpack( 'C4', substr( $b, $at + 4, 4 ) ) );
		$size = 4 === $v ? ( $s[0] << 21 ) | ( $s[1] << 14 ) | ( $s[2] << 7 ) | $s[3] : ( $s[0] << 24 ) | ( $s[1] << 16 ) | ( $s[2] << 8 ) | $s[3];
		if ( $size <= 0 || $at + 10 + $size > $end ) {
			break;
		}
		if ( $fn( $id, substr( $b, $at + 10, $size ) ) ) {
			return;
		}
		$at += 10 + $size;
	}
}

/**
 * The chapters in the ID3 tag at the start of a file, by start time.
 *
 * @param string $path A local file.
 * @return array[] start, end (seconds), title, url.
 */
function rrt_id3_chapters( $path ) {
	$fh = ( $path && is_readable( $path ) ) ? fopen( $path, 'rb' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( ! $fh ) {
		return array();
	}
	$head = (string) fread( $fh, 10 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( strlen( $head ) < 10 || 'ID3' !== substr( $head, 0, 3 ) ) {
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return array();
	}
	$h = array_values( unpack( 'C10', $head ) );
	$v = $h[3];
	/* Unsynchronised tags are rare and not worth the code, as in id3.js. */
	if ( ( 3 !== $v && 4 !== $v ) || ( $h[5] & 0x80 ) ) {
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return array();
	}
	$size = ( $h[6] << 21 ) | ( $h[7] << 14 ) | ( $h[8] << 7 ) | $h[9];
	$body = $size > 0 ? (string) fread( $fh, min( $size, RRT_ID3_MAX ) ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
	fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$b     = $head . $body;
	$start = 10;
	if ( $h[5] & 0x40 && strlen( $b ) >= 14 ) {
		$e      = array_values( unpack( 'C4', substr( $b, 10, 4 ) ) );
		$start += 4 === $v ? ( ( $e[0] << 21 ) | ( $e[1] << 14 ) | ( $e[2] << 7 ) | $e[3] ) : ( ( ( $e[0] << 24 ) | ( $e[1] << 16 ) | ( $e[2] << 8 ) | $e[3] ) + 4 );
	}
	$chaps = array();
	rrt_id3_frames(
		$b,
		$start,
		strlen( $b ),
		$v,
		function ( $id, $f ) use ( &$chaps, $v ) {
			if ( 'CHAP' === $id ) {
				list( $cid, $p ) = rrt_id3_cstr( $f, 0 );
				if ( strlen( $f ) < $p + 16 ) {
					return false;
				}
				$t    = unpack( 'Ns/Ne', substr( $f, $p, 8 ) );
				$chap = array(
					'id'    => $cid,
					'start' => $t['s'] / 1000,
					'end'   => $t['e'] / 1000,
					'title' => '',
					'url'   => '',
				);
				rrt_id3_frames(
					$f,
					$p + 16,
					strlen( $f ),
					$v,
					function ( $sid, $sb ) use ( &$chap ) {
						if ( 'TIT2' === $sid && strlen( $sb ) > 1 ) {
							$chap['title'] = trim( rrt_id3_text( substr( $sb, 1 ), ord( $sb[0] ) ) );
						}
						if ( 'WXXX' === $sid && strlen( $sb ) > 1 ) {
							$enc  = ord( $sb[0] );
							$rest = substr( $sb, 1 );
							/* Skip the description: one zero byte, or two in UTF-16 (on an even boundary). */
							if ( 1 === $enc || 2 === $enc ) {
								$at = 0;
								while ( $at + 1 < strlen( $rest ) && "\0\0" !== substr( $rest, $at, 2 ) ) {
									$at += 2;
								}
								$rest = substr( $rest, $at + 2 );
							} else {
								list( , $at ) = rrt_id3_cstr( $rest, 0 );
								$rest         = substr( $rest, $at );
							}
							$url = trim( rrt_id3_text( $rest, 0 ) );
							if ( preg_match( '#^https?://#i', $url ) ) {
								$chap['url'] = $url;
							}
						}
						return false;
					}
				);
				if ( $chap['end'] > $chap['start'] ) {
					$chaps[] = $chap;
				}
			}
			/* The artwork comes last in tags made here, so nothing more is needed after it. */
			return 'APIC' === $id;
		}
	);
	/* Apps play chapters in time order, whatever order the tag lists them in. */
	usort(
		$chaps,
		function ( $a, $b ) {
			return $a['start'] <=> $b['start'];
		}
	);
	return array_map(
		function ( $c ) {
			unset( $c['id'] );
			return $c;
		},
		$chaps
	);
}

/**
 * A Podcasting 2.0 chapters file (version 1.2.0 of the format) for a list of
 * chapters, as id3.js's chaptersJson makes it.
 */
function rrt_chapters_json( $chapters, $title = '' ) {
	$out = array( 'version' => '1.2.0' );
	if ( '' !== (string) $title ) {
		$out['title'] = (string) $title;
	}
	$out['chapters'] = array();
	foreach ( $chapters as $i => $c ) {
		$o = array(
			'startTime' => round( (float) $c['start'], 3 ),
			'endTime'   => round( (float) $c['end'], 3 ),
			/* translators: %d: chapter number. */
			'title'     => '' !== (string) $c['title'] ? (string) $c['title'] : sprintf( __( 'Chapter %d', 'foxfire-studio' ), $i + 1 ),
		);
		if ( ! empty( $c['img'] ) ) {
			$o['img'] = (string) $c['img'];
		}
		if ( ! empty( $c['url'] ) ) {
			$o['url'] = (string) $c['url'];
		}
		$out['chapters'][] = $o;
	}
	return wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
}

/*
 * ---------------------------------------------------------------------
 * What the feed knows about each episode, kept until the reading changes
 * ---------------------------------------------------------------------
 */

/** The local file of a post's reading, or ''. */
function rrt_feed_audio_path( $post_id ) {
	$audio = rrt_reading_audio( $post_id );
	if ( '' === $audio['url'] ) {
		return '';
	}
	if ( $audio['id'] ) {
		$f = rrt_att_file( $audio['id'], $post_id, 'audio' );
		if ( '' !== $f && is_readable( $f ) ) {
			return $f;
		}
	}
	return rrt_podcast_local_path( $audio['url'], $post_id, 'audio' );
}

/** What makes a post's kept facts out of date: its reading, its captions and its text. */
function rrt_feed_key( $post_id ) {
	$audio = rrt_reading_audio( $post_id );
	$cap   = rrt_reading_captions( $post_id );
	$path  = rrt_feed_audio_path( $post_id );
	$cpath = ! empty( $cap['id'] ) ? get_attached_file( (int) $cap['id'] ) : '';
	$post  = get_post( $post_id );
	$parts = array(
		RRT_VERSION,
		$audio['url'],
		$path && file_exists( $path ) ? filesize( $path ) . '.' . filemtime( $path ) : '',
		$cap['url'],
		$cpath && file_exists( $cpath ) ? filesize( $cpath ) . '.' . filemtime( $cpath ) : '',
		$post ? $post->post_modified_gmt : '',
	);
	return md5( implode( '|', $parts ) );
}

/**
 * A post's chapters and top suggestion for the feed, worked out once and
 * kept with the post until its reading, captions or text change.
 *
 * @return array chapters (from rrt_id3_chapters()), top (start, len, text of the top suggestion, or null).
 */
function rrt_feed_facts( $post_id ) {
	$key  = rrt_feed_key( $post_id );
	$kept = get_post_meta( $post_id, RRT_FEED_META, true );
	if ( is_array( $kept ) && isset( $kept['key'] ) && $kept['key'] === $key ) {
		return $kept;
	}
	$path = rrt_feed_audio_path( $post_id );
	$top  = null;
	if ( function_exists( 'rrt_suggestions' ) ) {
		$sugg = rrt_suggestions( $post_id );
		/* The first suggestion is always the opening; the top one after it is the best of the rest. */
		$best = isset( $sugg[1] ) ? $sugg[1] : ( isset( $sugg[0] ) ? $sugg[0] : null );
		if ( $best ) {
			$top = array(
				'start' => (float) $best['start'],
				'len'   => (float) $best['len'],
				'text'  => (string) $best['text'],
			);
		}
	}
	$facts = array(
		'key'      => $key,
		'chapters' => ( $path && preg_match( '/\.mp3$/i', $path ) ) ? rrt_id3_chapters( $path ) : array(),
		'top'      => $top,
	);
	update_post_meta( $post_id, RRT_FEED_META, $facts );
	return $facts;
}

/** The address of a post's chapters file. */
function rrt_chapters_url( $post_id ) {
	return home_url( '/' . RRT_CHAPTERS_BASE . '/' . (int) $post_id . '.json' );
}

/** The first words of a text, at most $max characters, ending at a word. */
function rrt_feed_short( $text, $max = 60 ) {
	$text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
	if ( mb_strlen( $text ) <= $max ) {
		return $text;
	}
	$cut = mb_substr( $text, 0, $max - 3 );
	$sp  = mb_strrpos( $cut, ' ' );
	if ( false !== $sp && $sp > $max / 2 ) {
		$cut = mb_substr( $cut, 0, $sp );
	}
	return rtrim( $cut, " ,;:.!?-" ) . '...';
}

/**
 * The episode's soundbite: the pick from Use for the feed, else the top
 * suggestion, else none.
 *
 * @return array|null start, len, title, from ('pick' or 'top').
 */
function rrt_feed_soundbite( $post_id, $facts = null ) {
	$pick = function_exists( 'rrt_soundbite_pick' ) ? rrt_soundbite_pick( $post_id ) : null;
	if ( $pick ) {
		return array(
			'start' => (float) $pick['s'],
			'len'   => (float) $pick['d'],
			'title' => rrt_feed_short( isset( $pick['text'] ) ? $pick['text'] : '' ),
			'from'  => 'pick',
		);
	}
	$facts = $facts ? $facts : rrt_feed_facts( $post_id );
	if ( empty( $facts['top'] ) ) {
		return null;
	}
	return array(
		'start' => (float) $facts['top']['start'],
		'len'   => (float) $facts['top']['len'],
		'title' => rrt_feed_short( $facts['top']['text'] ),
		'from'  => 'top',
	);
}

/**
 * The show's podcast:txt lines from the setting: one a line, and a line
 * that starts with a single word and a colon (such as "verify: abc123")
 * gives that word as its purpose.
 *
 * @return array[] purpose, text.
 */
function rrt_feed_txt_lines( $raw ) {
	$out = array();
	foreach ( preg_split( '/\r\n|\r|\n/', (string) $raw ) as $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}
		$purpose = '';
		if ( preg_match( '/^([A-Za-z0-9_-]{1,128}):\s+(\S.*)$/', $line, $m ) ) {
			$purpose = $m[1];
			$line    = $m[2];
		}
		$out[] = array(
			'purpose' => $purpose,
			'text'    => mb_substr( $line, 0, 4000 ),
		);
	}
	return $out;
}

/*
 * ---------------------------------------------------------------------
 * The chapters file
 * ---------------------------------------------------------------------
 */

/**
 * The post id in a request path for a chapters file, or 0.
 *
 * @param string $path      The request's path, without the query.
 * @param string $home_path The path of the site's home address.
 */
function rrt_chapters_match( $path, $home_path ) {
	$home_path = '/' . trim( (string) $home_path, '/' );
	$home_path = '/' === $home_path ? '' : $home_path;
	$path      = rawurldecode( (string) $path );
	if ( '' !== $home_path ) {
		if ( 0 !== strpos( $path, $home_path . '/' ) ) {
			return 0;
		}
		$path = substr( $path, strlen( $home_path ) );
	}
	/* The feed gives the .json address; the same address without .json answers too, for servers that answer missing .json files themselves. */
	if ( preg_match( '#^/' . RRT_CHAPTERS_BASE . '/([0-9]{1,20})(?:\.json)?$#', $path, $m ) ) {
		return (int) $m[1];
	}
	return 0;
}

/** Whether a post is in the published feed. */
function rrt_feed_has_post( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post || 'publish' !== $post->post_status || '' !== (string) $post->post_password ) {
		return false;
	}
	/* A reading for members (1.21.0) has its chapters only in members' feeds. */
	if ( function_exists( 'rrt_access_locked' ) && rrt_access_locked( $post->ID ) ) {
		return false;
	}
	if ( function_exists( 'rrt_col_is' ) && rrt_col_is( $post ) ) {
		return rrt_col_in_feed( $post );
	}
	$cfg = rrt_config();
	if ( ! in_array( $post->post_type, (array) $cfg['post_types'], true ) ) {
		return false;
	}
	$audio = rrt_reading_audio( $post_id );
	return '' !== $audio['url'];
}

/** Answers a request for a chapters file. */
function rrt_chapters_serve() {
	$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
	$id   = rrt_chapters_match( $path, (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
	if ( ! $id ) {
		return;
	}
	header( 'X-Robots-Tag: noindex' );
	$s = rrt_podcast_settings();
	if ( empty( $s['on'] ) || ! rrt_feed_has_post( $id ) ) {
		status_header( 404 );
		exit;
	}
	$facts = rrt_feed_facts( $id );
	/* A collection's chapters (1.19.0) carry each reading's picture as well. */
	if ( function_exists( 'rrt_col_is' ) && rrt_col_is( $id ) ) {
		$facts['chapters'] = rrt_col_feed_chapters( $id );
	}
	if ( empty( $facts['chapters'] ) ) {
		status_header( 404 );
		exit;
	}
	status_header( 200 );
	header( 'Content-Type: application/json+chapters; charset=UTF-8' );
	/* Web players fetch it from their own pages. */
	header( 'Access-Control-Allow-Origin: *' );
	echo rrt_chapters_json( $facts['chapters'], html_entity_decode( wp_strip_all_tags( get_the_title( $id ) ), ENT_QUOTES, 'UTF-8' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
	exit;
}
add_action( 'init', 'rrt_chapters_serve', 1 );

/*
 * ---------------------------------------------------------------------
 * Pings
 * ---------------------------------------------------------------------
 */

/** Whether pings are switched on (they only go out while the feed is published). */
function rrt_ping_on( $s = null ) {
	$s = $s ? $s : rrt_podcast_settings();
	return ! empty( $s['on'] ) && ! empty( $s['pings'] );
}

/** The WebSub hub: the setting when it is an https address, else the default. */
function rrt_ping_hub( $s = null ) {
	$s   = $s ? $s : rrt_podcast_settings();
	$hub = trim( (string) $s['hub'] );
	return preg_match( '#^https://[^\s]+$#i', $hub ) ? $hub : RRT_PING_HUB;
}

/** The custom fields that hold a reading. */
function rrt_feed_audio_keys() {
	$cfg  = rrt_config();
	$keys = array( 'rrt_audio' );
	if ( ! empty( $cfg['player']['audio_key'] ) ) {
		$keys[] = $cfg['player']['audio_key'];
	}
	return $keys;
}

/** The last pings: at (time), index and hub (each an HTTP status, or an error's text). */
function rrt_ping_last() {
	$p = get_option( 'rrt_podcast_ping', array() );
	return is_array( $p ) ? array_merge(
		array(
			'at'    => 0,
			'index' => '',
			'hub'   => '',
		),
		$p
	) : array(
		'at'    => 0,
		'index' => '',
		'hub'   => '',
	);
}

/**
 * Asks for a ping: sent when this request ends, or, within 10 minutes of the
 * last one, as soon as the 10 minutes are up.
 */
function rrt_ping_due() {
	if ( ! rrt_ping_on() ) {
		return;
	}
	$last = rrt_ping_last();
	$wait = (int) $last['at'] + RRT_PING_GAP - time();
	if ( $wait > 0 ) {
		if ( ! wp_next_scheduled( 'rrt_podcast_ping' ) ) {
			wp_schedule_single_event( (int) $last['at'] + RRT_PING_GAP, 'rrt_podcast_ping' );
		}
		return;
	}
	$GLOBALS['rrt_ping_now'] = true;
}

/** Sends the pings now. Only the feed's address is sent. */
function rrt_ping_send() {
	if ( ! rrt_ping_on() ) {
		return;
	}
	$s    = rrt_podcast_settings();
	$feed = rrt_podcast_url();
	$last = rrt_ping_last();
	if ( time() - (int) $last['at'] < RRT_PING_GAP ) {
		return;
	}
	/* Noted first, so two requests ending together cannot both send. */
	update_option( 'rrt_podcast_ping', array_merge( $last, array( 'at' => time() ) ), false );
	$args = array(
		'timeout'    => 5,
		'user-agent' => 'FoxfireStudio/' . RRT_VERSION,
	);
	$code = function ( $r ) {
		return is_wp_error( $r ) ? $r->get_error_message() : (string) wp_remote_retrieve_response_code( $r );
	};
	$index = wp_remote_get( add_query_arg( 'url', rawurlencode( $feed ), RRT_PING_INDEX ), $args );
	$hub   = wp_remote_post(
		rrt_ping_hub( $s ),
		array_merge(
			$args,
			array(
				'body' => array(
					'hub.mode' => 'publish',
					'hub.url'  => $feed,
				),
			)
		)
	);
	update_option(
		'rrt_podcast_ping',
		array(
			'at'    => time(),
			'index' => $code( $index ),
			'hub'   => $code( $hub ),
		),
		false
	);
}
add_action( 'rrt_podcast_ping', 'rrt_ping_send' );

/* A ping asked for during this request goes out after the page has been sent. */
add_action(
	'shutdown',
	function () {
		if ( empty( $GLOBALS['rrt_ping_now'] ) ) {
			return;
		}
		$GLOBALS['rrt_ping_now'] = false;
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}
		rrt_ping_send();
	}
);

/** A reading added to or changed on a post: the feed changes, and a published post means a new episode. */
function rrt_feed_reading_changed( $meta_id, $post_id, $key ) {
	if ( ! in_array( $key, rrt_feed_audio_keys(), true ) ) {
		return;
	}
	if ( empty( rrt_podcast_settings()['on'] ) ) {
		return;
	}
	rrt_podcast_purge( $post_id );
	if ( rrt_feed_has_post( $post_id ) ) {
		rrt_ping_due();
	}
}
add_action( 'added_post_meta', 'rrt_feed_reading_changed', 20, 3 );
add_action( 'updated_post_meta', 'rrt_feed_reading_changed', 20, 3 );
add_action(
	'deleted_post_meta',
	function ( $meta_ids, $post_id, $key ) {
		if ( in_array( $key, rrt_feed_audio_keys(), true ) && ! empty( rrt_podcast_settings()['on'] ) ) {
			rrt_podcast_purge( $post_id );
		}
	},
	20,
	3
);

/* A post with a reading being published. */
add_action(
	'transition_post_status',
	function ( $new, $old, $post ) {
		if ( 'publish' === $new && 'publish' !== $old && rrt_feed_has_post( $post->ID ) ) {
			rrt_ping_due();
		}
	},
	20,
	3
);
