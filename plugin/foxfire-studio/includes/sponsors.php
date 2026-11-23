<?php
/**
 * Sponsor slots (1.22.0): short messages placed before, in the middle of and
 * after readings in the podcast feed, from a library of messages with dates.
 *
 * A message is a post of its own type (rrt_spot) under Foxfire Studio,
 * Sponsor messages: who it is for, its kind (paid or gifted, or your own),
 * a link, a script recorded in the studio like any post, its dates, the
 * slots it may use and the readings it goes on. A message is joined to a
 * reading by copying MP3 frames, as collections are (1.19.0), so it needs a
 * copy in each reading's exact format and loudness. Those copies are made in
 * the browser on the message's screen (assets/js/sponsors.js) and kept with
 * the message.
 *
 * For each reading in the feed the site makes a sponsored copy in
 * uploads/rrt-sponsored/<post>/: the pre-roll, the reading (opened at a pause
 * for the mid-roll) and the post-roll, with the reading's chapters and
 * captions moved to their new times. The reading itself is never touched,
 * and the page's player keeps playing it. Every change gets a file with a new
 * name; the previous one is kept for two days for apps still playing it.
 *
 * A mid-roll cuts the reading open in the middle of a pause. MP3 frames keep
 * part of their sound in the spare bytes of the frames before them (the bit
 * reservoir), so the frames just before the cut are copied a second time
 * after the message, with their side information zeroed: they decode as
 * silence, and their bytes give the next frame what it needs. Nothing is
 * encoded again.
 *
 * Every listener gets the same file. Nothing about listeners is used.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The messages' post type. */
define( 'RRT_SPOT_TYPE', 'rrt_spot' );

/** On a message: its choices. */
define( 'RRT_SPOT_META', '_rrt_spot' );

/** On a copy of a message (an attachment of it): its format, loudness and the recording it was made from. */
define( 'RRT_SPOT_COPY', '_rrt_spot_copy' );

/** On a message: the readings it has run on, and when. */
define( 'RRT_SPOT_LOG', '_rrt_spot_log' );

/** On a reading: no messages at all, and the chosen mid-roll time. */
define( 'RRT_SPOT_POST', '_rrt_spots' );

/** On a reading: the pauses in its file, found in the browser. */
define( 'RRT_SPOT_PAUSES', '_rrt_pauses' );

/** On a reading: its sponsored copy as last made, and older ones waiting to go. */
define( 'RRT_SPOT_BUILT', '_rrt_spot_built' );

/** The folder inside uploads. */
define( 'RRT_SPOT_DIR', 'rrt-sponsored' );

/** Silence between a pre-roll or post-roll and the reading, and either side of a mid-roll, in seconds. */
define( 'RRT_SPOT_GAP', 0.8 );
define( 'RRT_SPOT_MID_GAP', 0.3 );

/** The shortest pause a mid-roll can go in, in seconds. */
define( 'RRT_SPOT_PAUSE_MIN', 0.6 );

/** How long an older sponsored copy is kept for apps still playing it. */
define( 'RRT_SPOT_KEEP', 2 * DAY_IN_SECONDS );

/** The first seconds of a paid message, where its disclosure should be heard. */
define( 'RRT_SPOT_LEAD', 10 );

/** Changes when the way a sponsored copy is made changes, so every copy is made again. */
define( 'RRT_SPOT_FORMAT', 1 );

/** The most entries kept in a message's log. */
define( 'RRT_SPOT_LOG_MAX', 500 );

/*
 * ---------------------------------------------------------------------
 * Settings
 * ---------------------------------------------------------------------
 */

/** The sponsor settings, from the podcast settings. */
function rrt_spot_settings() {
	$s     = rrt_podcast_settings();
	$kinds = array_values( array_intersect( array_map( 'sanitize_key', (array) $s['s_kinds'] ), array_keys( rrt_kinds() ) ) );
	return array(
		'on'       => ! empty( $s['s_on'] ),
		'mid_min'  => max( 1, min( 180, (int) $s['s_mid_min'] ) ),
		'kinds'    => $kinds,
		'chapters' => ! empty( $s['s_chapters'] ),
		'thank'    => ! empty( $s['s_thank'] ),
		'members'  => ! empty( $s['s_members'] ),
		'line_paid' => trim( (string) $s['s_line_paid'] ),
		'line_own'  => trim( (string) $s['s_line_own'] ),
	);
}

/** Whether sponsor messages are switched on. */
function rrt_spot_on() {
	return rrt_spot_settings()['on'];
}

/** The slots, in the order they play. */
function rrt_spot_slots() {
	return array(
		'pre'  => __( 'Pre-roll', 'foxfire-studio' ),
		'mid'  => __( 'Mid-roll', 'foxfire-studio' ),
		'post' => __( 'Post-roll', 'foxfire-studio' ),
	);
}

/** The kinds of message. */
function rrt_spot_kinds() {
	return array(
		'paid' => __( 'Paid or gifted', 'foxfire-studio' ),
		'own'  => __( 'Your own', 'foxfire-studio' ),
	);
}

/*
 * ---------------------------------------------------------------------
 * MP3 frames for a mid-roll (pure functions, no WordPress needed)
 * ---------------------------------------------------------------------
 */

/**
 * The parts of one frame that matter for joining: its length, the size of
 * its side information, its CRC and how far back its sound starts.
 *
 * @param string $b The frame's first bytes (at least 6 plus its CRC).
 * @return array|null len, side, crc (bytes), mdb (main_data_begin), space (bytes of main data it holds), or null.
 */
function rrt_spot_frame_info( $b ) {
	$h = rrt_col_header( substr( $b, 0, 4 ) );
	if ( ! $h ) {
		return null;
	}
	$crc  = ( ord( $b[1] ) & 1 ) ? 0 : 2;
	$side = 1 === $h['ch'] ? 17 : 32;
	if ( strlen( $b ) < 4 + $crc + 2 ) {
		return null;
	}
	$o   = 4 + $crc;
	$mdb = ( ord( $b[ $o ] ) << 1 ) | ( ord( $b[ $o + 1 ] ) >> 7 );
	return array(
		'len'   => $h['len'],
		'side'  => $side,
		'crc'   => $crc,
		'mdb'   => $mdb,
		'space' => $h['len'] - 4 - $crc - $side,
	);
}

/**
 * A frame made silent with its bytes kept: the header stays, the side
 * information becomes zeros (no main data, so it decodes to silence), and
 * the main data area keeps the bytes a later frame may read back.
 *
 * @param string $frame The whole frame.
 * @return string
 */
function rrt_spot_mute( $frame ) {
	$i = rrt_spot_frame_info( $frame );
	if ( ! $i ) {
		return $frame;
	}
	return substr( $frame, 0, 4 + $i['crc'] ) . str_repeat( "\0", $i['side'] ) . substr( $frame, 4 + $i['crc'] + $i['side'] );
}

/**
 * How many frames before the cut must be copied (muted) after the message,
 * so every frame from the cut on finds the bytes it reads back.
 *
 * @param array $before The frames just before the cut, nearest last (each from rrt_spot_frame_info()).
 * @param array $after  The cut's frame and the few after it, in order.
 * @return int Frames, or -1 when not enough frames before the cut are given.
 */
function rrt_spot_bridge_count( $before, $after ) {
	$need  = 0;
	$space = 0;
	foreach ( $after as $f ) {
		$need   = max( $need, (int) $f['mdb'] - $space );
		$space += (int) $f['space'];
	}
	if ( $need <= 0 ) {
		return 0;
	}
	$got = 0;
	$n   = 0;
	for ( $i = count( $before ) - 1; $i >= 0; $i-- ) {
		$got += (int) $before[ $i ]['space'];
		++$n;
		if ( $got >= $need ) {
			return $n;
		}
	}
	return -1;
}

/**
 * The frames around a frame number in a scanned file: their byte offsets
 * and facts, for the cut.
 *
 * @param string $path The file.
 * @param array  $scan Its scan (rrt_col_scan()).
 * @param int    $k    The frame the reading resumes at (counted from the first audio frame).
 * @param int    $back Frames to give before it.
 * @param int    $fwd  Frames to give from it.
 * @return array|null before, after (each: off, info), off (where frame k starts), or null.
 */
function rrt_spot_frames_at( $path, $scan, $k, $back = 6, $fwd = 6 ) {
	if ( ! $scan['ok'] || $k < 1 || $k >= $scan['frames'] ) {
		return null;
	}
	$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( ! $fh ) {
		return null;
	}
	$pos    = $scan['start'];
	$i      = 0;
	$before = array();
	$after  = array();
	$off    = 0;
	$from   = max( 0, $k - $back );
	while ( $pos + 4 <= $scan['end'] && $i < $k + $fwd ) {
		fseek( $fh, $pos );
		$h = rrt_col_header( (string) fread( $fh, 4 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $h ) {
			break;
		}
		if ( $i >= $from ) {
			fseek( $fh, $pos );
			$info = rrt_spot_frame_info( (string) fread( $fh, 8 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( ! $info ) {
				break;
			}
			$row = array(
				'off'  => $pos,
				'info' => $info,
			);
			if ( $i < $k ) {
				$before[] = $row;
			} else {
				if ( $i === $k ) {
					$off = $pos;
				}
				$after[] = $row;
			}
		}
		$pos += $h['len'];
		++$i;
	}
	fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( ! $after || ! $off ) {
		return null;
	}
	return array(
		'before' => $before,
		'after'  => $after,
		'off'    => $off,
	);
}

/**
 * Writes a file from pieces: bytes given, or a stretch of another file.
 *
 * @param string $dest  Where to write.
 * @param array  $segs  Each: bytes (a string), or path, start, end.
 * @return int|false Bytes written.
 */
function rrt_spot_write( $dest, $segs ) {
	$out = fopen( $dest, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( ! $out ) {
		return false;
	}
	$bytes = 0;
	foreach ( $segs as $s ) {
		if ( isset( $s['bytes'] ) ) {
			$bytes += (int) fwrite( $out, $s['bytes'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			continue;
		}
		$in = fopen( $s['path'], 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $in ) {
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return false;
		}
		fseek( $in, $s['start'] );
		$left = $s['end'] - $s['start'];
		while ( $left > 0 ) {
			$chunk = (string) fread( $in, min( 1048576, $left ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( '' === $chunk ) {
				fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				return false;
			}
			$bytes += (int) fwrite( $out, $chunk ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$left  -= strlen( $chunk );
		}
		fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	return $bytes;
}

/**
 * A reading's time in the sponsored copy.
 *
 * @param float $t   Seconds in the reading.
 * @param array $map pre (seconds added before the reading), cut (where the mid-roll goes in the reading, or null), mid (seconds the mid-roll adds).
 * @return float
 */
function rrt_spot_map( $t, $map ) {
	$t = (float) $t;
	$o = $t + (float) $map['pre'];
	if ( null !== $map['cut'] && $t >= (float) $map['cut'] ) {
		$o += (float) $map['mid'];
	}
	return $o;
}

/*
 * ---------------------------------------------------------------------
 * Pauses
 * ---------------------------------------------------------------------
 */

/**
 * The pauses kept for a reading's current file, as the browser found them.
 *
 * @return array[]|null Each: s, e (seconds); null when they have not been found for this file.
 */
function rrt_spot_pauses( $post_id, $key ) {
	$p = get_post_meta( $post_id, RRT_SPOT_PAUSES, true );
	if ( ! is_array( $p ) || ! isset( $p['key'], $p['list'] ) || '' === $key || $p['key'] !== $key ) {
		return null;
	}
	return rrt_spot_clean_pauses( $p['list'] );
}

/** A list of pauses made safe: numbers, in order, long enough. */
function rrt_spot_clean_pauses( $list ) {
	$out = array();
	foreach ( (array) $list as $p ) {
		$s = isset( $p['s'] ) ? (float) $p['s'] : ( isset( $p[0] ) ? (float) $p[0] : -1 );
		$e = isset( $p['e'] ) ? (float) $p['e'] : ( isset( $p[1] ) ? (float) $p[1] : -1 );
		if ( $s >= 0 && $e - $s >= RRT_SPOT_PAUSE_MIN - 0.001 ) {
			$out[] = array(
				's' => round( $s, 3 ),
				'e' => round( $e, 3 ),
			);
		}
		if ( count( $out ) >= 5000 ) {
			break;
		}
	}
	usort(
		$out,
		function ( $a, $b ) {
			return $a['s'] <=> $b['s'];
		}
	);
	return $out;
}

/**
 * Up to three pauses to offer for a mid-roll: the longest near the middle
 * (between three tenths and seven tenths of the way), else the nearest to
 * it, in time order.
 *
 * @param array $pauses Each: s, e.
 * @param float $secs   The reading's length.
 * @return array[] Each: s, e, t (the middle, where the cut goes).
 */
function rrt_spot_pause_suggest( $pauses, $secs ) {
	$mid  = $secs / 2;
	$rows = array();
	foreach ( $pauses as $p ) {
		$t = ( $p['s'] + $p['e'] ) / 2;
		if ( $t < 5 || $t > $secs - 5 ) {
			continue;
		}
		$rows[] = array(
			's'    => $p['s'],
			'e'    => $p['e'],
			't'    => round( $t, 3 ),
			'in'   => $t >= 0.3 * $secs && $t <= 0.7 * $secs,
			'len'  => $p['e'] - $p['s'],
			'dist' => abs( $t - $mid ),
		);
	}
	$inside = array_values(
		array_filter(
			$rows,
			function ( $r ) {
				return $r['in'];
			}
		)
	);
	if ( $inside ) {
		usort(
			$inside,
			function ( $a, $b ) {
				return ( round( $b['len'], 1 ) <=> round( $a['len'], 1 ) ) ?: ( $a['dist'] <=> $b['dist'] );
			}
		);
		$pick = array_slice( $inside, 0, 3 );
	} else {
		usort(
			$rows,
			function ( $a, $b ) {
				return $a['dist'] <=> $b['dist'];
			}
		);
		$pick = array_slice( $rows, 0, 3 );
	}
	usort(
		$pick,
		function ( $a, $b ) {
			return $a['t'] <=> $b['t'];
		}
	);
	return array_map(
		function ( $r ) {
			return array(
				's' => $r['s'],
				'e' => $r['e'],
				't' => $r['t'],
			);
		},
		$pick
	);
}

/**
 * The pause a wanted time snaps to: the one it falls in, else the nearest.
 *
 * @return array|null s, e, t.
 */
function rrt_spot_pause_snap( $pauses, $want, $secs ) {
	$best = null;
	$bd   = INF;
	foreach ( $pauses as $p ) {
		$t = ( $p['s'] + $p['e'] ) / 2;
		if ( $t < 5 || $t > $secs - 5 ) {
			continue;
		}
		$d = ( $want >= $p['s'] && $want <= $p['e'] ) ? 0 : min( abs( $want - $p['s'] ), abs( $want - $p['e'] ) );
		if ( $d < $bd ) {
			$bd   = $d;
			$best = array(
				's' => $p['s'],
				'e' => $p['e'],
				't' => round( $t, 3 ),
			);
		}
	}
	return $best;
}

/*
 * ---------------------------------------------------------------------
 * The messages
 * ---------------------------------------------------------------------
 */

add_action(
	'init',
	function () {
		register_post_type(
			RRT_SPOT_TYPE,
			array(
				'labels'          => array(
					'name'               => __( 'Sponsor messages', 'foxfire-studio' ),
					'singular_name'      => __( 'Sponsor message', 'foxfire-studio' ),
					'add_new'            => __( 'Add New', 'foxfire-studio' ),
					'add_new_item'       => __( 'Add New Sponsor Message', 'foxfire-studio' ),
					'edit_item'          => __( 'Edit Sponsor Message', 'foxfire-studio' ),
					'new_item'           => __( 'New Sponsor Message', 'foxfire-studio' ),
					'search_items'       => __( 'Search Sponsor Messages', 'foxfire-studio' ),
					'not_found'          => __( 'No sponsor messages yet.', 'foxfire-studio' ),
					'not_found_in_trash' => __( 'No sponsor messages in the trash.', 'foxfire-studio' ),
					'all_items'          => __( 'Sponsor messages', 'foxfire-studio' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => false,
				'show_in_rest'    => false,
				'rewrite'         => false,
				'query_var'       => false,
				/* 1.25.0: permissions of their own, for the site's owner (rrt_spot_cap()), since a message is spliced into every reading. */
				'capability_type' => array( 'rrt_spot', 'rrt_spots' ),
				'map_meta_cap'    => true,
				'supports'        => array( 'title', 'page-attributes' ),
			)
		);
	},
	5
);

/* The screens sit under Foxfire Studio, Sponsor messages. */
add_filter(
	'parent_file',
	function ( $file ) {
		$s = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $s && RRT_SPOT_TYPE === $s->post_type ) {
			$GLOBALS['submenu_file'] = 'edit.php?post_type=' . RRT_SPOT_TYPE; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			return 'rrt-dashboard';
		}
		return $file;
	}
);

/** Whether a post is a sponsor message. */
function rrt_spot_is( $post ) {
	$post = get_post( $post );
	return $post && RRT_SPOT_TYPE === $post->post_type;
}

/** A message's choices, with the defaults filled in. */
function rrt_spot_get( $spot_id ) {
	$m = get_post_meta( $spot_id, RRT_SPOT_META, true );
	$m = is_array( $m ) ? $m : array();
	$m = array_merge(
		array(
			'who'   => '',
			'kind'  => 'paid',
			'link'  => '',
			'start' => '',
			'end'   => '',
			'slots' => array( 'pre' ),
			'where' => 'all',
			'cats'  => array(),
			'posts' => array(),
		),
		$m
	);
	$m['kind']  = isset( rrt_spot_kinds()[ $m['kind'] ] ) ? $m['kind'] : 'paid';
	$m['slots'] = array_values( array_intersect( array_keys( rrt_spot_slots() ), (array) $m['slots'] ) );
	$m['where'] = in_array( $m['where'], array( 'all', 'cats', 'posts' ), true ) ? $m['where'] : 'all';
	$m['cats']  = array_values( array_filter( array_map( 'absint', (array) $m['cats'] ) ) );
	$m['posts'] = array_values( array_filter( array_map( 'absint', (array) $m['posts'] ) ) );
	$m['start'] = rrt_spot_clean_date( $m['start'] );
	$m['end']   = rrt_spot_clean_date( $m['end'] );
	return $m;
}

/** A date as Y-m-d, or ''. */
function rrt_spot_clean_date( $d ) {
	$d = trim( (string) $d );
	return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) && checkdate( (int) substr( $d, 5, 2 ), (int) substr( $d, 8, 2 ), (int) substr( $d, 0, 4 ) ) ? $d : '';
}

/** Today in the site's time zone, as Y-m-d; the filter lets tests move the clock. */
function rrt_spot_today() {
	return (string) apply_filters( 'rrt_spot_today', wp_date( 'Y-m-d' ) );
}

/** The name a message shows: who it is for, else its title. */
function rrt_spot_who( $spot_id ) {
	$m = rrt_spot_get( $spot_id );
	$w = trim( (string) $m['who'] );
	return '' !== $w ? $w : html_entity_decode( wp_strip_all_tags( get_the_title( $spot_id ) ), ENT_QUOTES, 'UTF-8' );
}

/**
 * Who may make, record and publish sponsor messages (1.25.0): people with this
 * capability, Administrators by default. A site can give it to Editors with
 * the rrt_spot_capability filter (for example 'edit_others_posts').
 */
function rrt_spot_cap() {
	return (string) apply_filters( 'rrt_spot_capability', 'manage_options' );
}

/** The sponsor message post type's own capabilities. */
function rrt_spot_caps() {
	return array( 'edit_rrt_spots', 'edit_others_rrt_spots', 'edit_private_rrt_spots', 'edit_published_rrt_spots', 'publish_rrt_spots', 'read_private_rrt_spots', 'delete_rrt_spots', 'delete_others_rrt_spots', 'delete_private_rrt_spots', 'delete_published_rrt_spots' );
}

/* Anyone with rrt_spot_cap() holds every sponsor message capability; nobody else holds any. */
add_filter(
	'user_has_cap',
	function ( $allcaps ) {
		$need = rrt_spot_cap();
		if ( ! empty( $allcaps[ $need ] ) ) {
			foreach ( rrt_spot_caps() as $c ) {
				$allcaps[ $c ] = true;
			}
		}
		return $allcaps;
	}
);

/** Every published message, in the list's order: Order, then oldest first. */
function rrt_spot_all() {
	return get_posts(
		array(
			'post_type'        => RRT_SPOT_TYPE,
			'post_status'      => 'publish',
			'numberposts'      => 200,
			'orderby'          => array(
				'menu_order' => 'ASC',
				'date'       => 'ASC',
				'ID'         => 'ASC',
			),
			'suppress_filters' => true,
		)
	);
}

/**
 * Whether a message runs today: published, inside its dates, recorded.
 *
 * @return string '' when it runs, else why not: unpublished, early, over, silent.
 */
function rrt_spot_runs( $spot_id, $today = null ) {
	$p = get_post( $spot_id );
	if ( ! $p || RRT_SPOT_TYPE !== $p->post_type || 'publish' !== $p->post_status ) {
		return 'unpublished';
	}
	$m     = rrt_spot_get( $spot_id );
	$today = null === $today ? rrt_spot_today() : $today;
	if ( '' !== $m['start'] && $today < $m['start'] ) {
		return 'early';
	}
	if ( '' !== $m['end'] && $today > $m['end'] ) {
		return 'over';
	}
	if ( '' === rrt_col_reading_path( $spot_id ) ) {
		return 'silent';
	}
	return '';
}

/** Whether a message is meant for a reading (by its Which readings choice only). */
function rrt_spot_for_post( $spot_id, $post_id ) {
	$m = rrt_spot_get( $spot_id );
	if ( 'posts' === $m['where'] ) {
		return in_array( (int) $post_id, $m['posts'], true );
	}
	if ( 'cats' === $m['where'] ) {
		return $m['cats'] && is_object_in_term( $post_id, 'category', $m['cats'] ) === true;
	}
	return true;
}

/** The current recording's file key for a message, or ''. */
function rrt_spot_src_key( $spot_id ) {
	return rrt_col_file_key( rrt_col_reading_path( $spot_id ) );
}

/**
 * A loudness bucket: copies made for readings within a quarter of a unit
 * of each other are shared.
 */
function rrt_spot_lu_bucket( $lu ) {
	return round( (float) $lu * 2 ) / 2;
}

/** What a copy is for, as one string: format and loudness bucket. */
function rrt_spot_need_key( $fmt, $lu ) {
	return rrt_col_fmt_key( $fmt ) . '|' . number_format( rrt_spot_lu_bucket( $lu ), 1, '.', '' );
}

/** A message's copies: attachment, need (format and loudness), src (the recording it was made from). */
function rrt_spot_copies( $spot_id ) {
	$out = array();
	foreach ( get_children(
		array(
			'post_parent' => $spot_id,
			'post_type'   => 'attachment',
			'numberposts' => -1,
		)
	) as $a ) {
		$c = get_post_meta( $a->ID, RRT_SPOT_COPY, true );
		if ( is_array( $c ) && ! empty( $c['need'] ) ) {
			$out[] = array(
				'att'  => (int) $a->ID,
				'need' => (string) $c['need'],
				'src'  => isset( $c['src'] ) ? (string) $c['src'] : '',
				'lu'   => isset( $c['lu'] ) ? (float) $c['lu'] : null,
			);
		}
	}
	return $out;
}

/**
 * A message's copy for a need, made from its current recording.
 *
 * @return array|null att, path, scan.
 */
function rrt_spot_copy_for( $spot_id, $need ) {
	$src = rrt_spot_src_key( $spot_id );
	if ( '' === $src ) {
		return null;
	}
	foreach ( rrt_spot_copies( $spot_id ) as $c ) {
		if ( $c['need'] === $need && $c['src'] === $src ) {
			$path = (string) get_attached_file( $c['att'] );
			$scan = rrt_col_scan_cached( $path );
			if ( $scan['ok'] ) {
				return array(
					'att'  => $c['att'],
					'path' => $path,
					'scan' => $scan,
				);
			}
		}
	}
	return null;
}

/**
 * Whether a paid message's recording says what it is near its start: a
 * disclosure word in its captions' first seconds.
 *
 * @return bool|null Null when there are no captions to check.
 */
function rrt_spot_disclosed( $spot_id ) {
	$cap = rrt_reading_captions( $spot_id );
	$p   = $cap['id'] ? rrt_att_file( $cap['id'], $spot_id, 'text' ) : '';
	if ( '' === $p || ! is_file( $p ) || filesize( $p ) > 1048576 ) {
		return null;
	}
	return rrt_spot_disclosure_in( (string) file_get_contents( $p ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
}

/** Whether a captions file's first seconds hold a disclosure word. */
function rrt_spot_disclosure_in( $vtt ) {
	$text = '';
	foreach ( rrt_col_vtt_cues( $vtt ) as $c ) {
		if ( $c['start'] < RRT_SPOT_LEAD ) {
			$text .= ' ' . $c['text'];
		}
	}
	$text = strtolower( wp_strip_all_tags( $text ) );
	return (bool) preg_match( '/\b(sponsor\w*|supported|paid|partner\w*|gifted|ad|ads|advert\w*|promotion|promoted)\b/u', $text );
}

/*
 * ---------------------------------------------------------------------
 * A reading and its plan
 * ---------------------------------------------------------------------
 */

/** A reading's choices: no messages at all, and the wanted mid-roll time (or null). */
function rrt_spot_post_choice( $post_id ) {
	$c = get_post_meta( $post_id, RRT_SPOT_POST, true );
	$c = is_array( $c ) ? $c : array();
	return array(
		'off' => ! empty( $c['off'] ),
		'mid' => isset( $c['mid'] ) && is_numeric( $c['mid'] ) && (float) $c['mid'] > 0 ? (float) $c['mid'] : null,
	);
}

/** Whether a reading has the music bed under it now. */
function rrt_spot_has_music( $post_id ) {
	$a     = rrt_reading_audio( $post_id );
	$music = get_post_meta( $post_id, '_rrt_music_used', true );
	return is_array( $music ) && ! empty( $music['audio'] ) && $a['id'] && (int) $music['audio'] === (int) $a['id'];
}

/**
 * What a reading is, for placing messages: its file, format, length and
 * loudness, and whether it is in the public feed.
 *
 * @return array ok, why, path, key, scan, lu (one channel's loudness or null), col (whether a collection).
 */
function rrt_spot_reading( $post_id ) {
	$out  = array(
		'ok'   => false,
		'why'  => '',
		'path' => '',
		'key'  => '',
		'scan' => null,
		'lu'   => null,
		'col'  => function_exists( 'rrt_col_is' ) && rrt_col_is( $post_id ),
	);
	$post = get_post( $post_id );
	if ( ! $post || 'publish' !== $post->post_status || '' !== (string) $post->post_password ) {
		$out['why'] = 'unpublished';
		return $out;
	}
	if ( function_exists( 'rrt_access_locked' ) && rrt_access_locked( $post_id ) ) {
		$out['why'] = 'members';
		return $out;
	}
	$out['path'] = rrt_col_reading_path( $post_id );
	if ( '' === $out['path'] ) {
		$out['why'] = 'away';
		return $out;
	}
	$out['scan'] = rrt_col_scan_cached( $out['path'] );
	if ( ! $out['scan']['ok'] ) {
		$out['why'] = 'notmp3';
		return $out;
	}
	$out['key'] = rrt_col_file_key( $out['path'] );
	$l          = rrt_col_level( $post_id, $out['key'] );
	$out['lu']  = null === $l ? null : rrt_col_mono_lu( $l, $out['scan']['ch'] );
	$out['ok']  = true;
	return $out;
}

/**
 * Why a reading can take no mid-roll, or '' when it can.
 *
 * @return string short, kind, music, one (a collection of one reading), pauses (not found yet), nopause (none long enough), ''.
 */
function rrt_spot_mid_why( $post_id, $info ) {
	$set = rrt_spot_settings();
	if ( $info['col'] ) {
		return count( rrt_spot_col_gaps( $post_id ) ) ? '' : 'one';
	}
	if ( $info['scan']['secs'] < $set['mid_min'] * 60 ) {
		return 'short';
	}
	$kind = rrt_get_kind( $post_id );
	if ( ! in_array( '' === $kind ? 'prose' : $kind, $set['kinds'], true ) ) {
		return 'kind';
	}
	if ( rrt_spot_has_music( $post_id ) ) {
		return 'music';
	}
	$p = rrt_spot_pauses( $post_id, $info['key'] );
	if ( null === $p ) {
		return 'pauses';
	}
	return rrt_spot_pause_suggest( $p, $info['scan']['secs'] ) ? '' : 'nopause';
}

/** A collection's gaps between readings, as pauses (from its last join). */
function rrt_spot_col_gaps( $col_id ) {
	$b = get_post_meta( $col_id, RRT_COL_BUILT, true );
	if ( ! is_array( $b ) || empty( $b['parts'] ) || count( $b['parts'] ) < 2 ) {
		return array();
	}
	$out   = array();
	$parts = array_values( $b['parts'] );
	for ( $i = 0; $i < count( $parts ) - 1; $i++ ) {
		$s = (float) $parts[ $i ]['end'];
		$e = (float) $parts[ $i + 1 ]['start'];
		if ( $e - $s > 0.05 ) {
			$out[] = array(
				's' => $s,
				'e' => $e,
			);
		}
	}
	return $out;
}

/**
 * Where the mid-roll goes in a reading: the owner's time snapped to a
 * pause, else the first suggestion; for a collection, the gap nearest the
 * middle.
 *
 * @return array|null s, e, t.
 */
function rrt_spot_mid_at( $post_id, $info ) {
	$secs = $info['scan']['secs'];
	if ( $info['col'] ) {
		$best = null;
		foreach ( rrt_spot_col_gaps( $post_id ) as $g ) {
			$t = ( $g['s'] + $g['e'] ) / 2;
			if ( ! $best || abs( $t - $secs / 2 ) < abs( $best['t'] - $secs / 2 ) ) {
				$best = array(
					's' => $g['s'],
					'e' => $g['e'],
					't' => round( $t, 3 ),
				);
			}
		}
		return $best;
	}
	$p = rrt_spot_pauses( $post_id, $info['key'] );
	if ( ! $p ) {
		return null;
	}
	$want = rrt_spot_post_choice( $post_id )['mid'];
	if ( null !== $want ) {
		$snap = rrt_spot_pause_snap( $p, $want, $secs );
		if ( $snap ) {
			return $snap;
		}
	}
	$s = rrt_spot_pause_suggest( $p, $secs );
	if ( ! $s ) {
		return null;
	}
	/* With no choice, the longest of the three nearest the middle. */
	usort(
		$s,
		function ( $a, $b ) {
			return round( $b['e'] - $b['s'], 1 ) <=> round( $a['e'] - $a['s'], 1 );
		}
	);
	return $s[0];
}

/**
 * Which message goes in each slot of a reading today.
 *
 * @return array slots (slot => spot, copy), waiting (spot ids that fit but have no copy yet), mid (s, e, t or null), mid_why, info, off.
 */
function rrt_spot_plan( $post_id, $today = null ) {
	$out  = array(
		'slots'   => array(),
		'waiting' => array(),
		'mid'     => null,
		'mid_why' => '',
		'info'    => null,
		'off'     => false,
	);
	if ( ! rrt_spot_on() ) {
		return $out;
	}
	$out['off'] = rrt_spot_post_choice( $post_id )['off'];
	$info       = rrt_spot_reading( $post_id );
	$out['info'] = $info;
	if ( $out['off'] || ! $info['ok'] ) {
		return $out;
	}
	$out['mid_why'] = rrt_spot_mid_why( $post_id, $info );
	$mid            = '' === $out['mid_why'] ? rrt_spot_mid_at( $post_id, $info ) : null;
	$out['mid']     = $mid;
	$need           = null === $info['lu'] ? '' : rrt_spot_need_key( $info['scan'], $info['lu'] );
	foreach ( rrt_spot_all() as $spot ) {
		if ( '' !== rrt_spot_runs( $spot->ID, $today ) || ! rrt_spot_for_post( $spot->ID, $post_id ) ) {
			continue;
		}
		$m    = rrt_spot_get( $spot->ID );
		$copy = '' !== $need ? rrt_spot_copy_for( $spot->ID, $need ) : null;
		foreach ( $m['slots'] as $slot ) {
			if ( isset( $out['slots'][ $slot ] ) || ( 'mid' === $slot && ! $mid ) ) {
				continue;
			}
			if ( ! $copy ) {
				if ( ! in_array( $spot->ID, $out['waiting'], true ) ) {
					$out['waiting'][] = $spot->ID;
				}
				continue;
			}
			$out['slots'][ $slot ] = array(
				'spot' => (int) $spot->ID,
				'copy' => $copy,
			);
		}
	}
	$order = array();
	foreach ( array_keys( rrt_spot_slots() ) as $k ) {
		if ( isset( $out['slots'][ $k ] ) ) {
			$order[ $k ] = $out['slots'][ $k ];
		}
	}
	$out['slots'] = $order;
	return $out;
}

/*
 * ---------------------------------------------------------------------
 * The sponsored copy
 * ---------------------------------------------------------------------
 */

/** The folder, made and readable by visitors (the feed points into it). */
function rrt_spot_dir() {
	$up = wp_upload_dir( null, false );
	return trailingslashit( $up['basedir'] ) . RRT_SPOT_DIR;
}

/** A file's address in the folder. */
function rrt_spot_url( $rel ) {
	$up = wp_upload_dir( null, false );
	return trailingslashit( $up['baseurl'] ) . RRT_SPOT_DIR . '/' . ltrim( $rel, '/' );
}

/** A reading's sponsored copy as last made, or null. */
function rrt_spot_built( $post_id ) {
	$b = get_post_meta( $post_id, RRT_SPOT_BUILT, true );
	if ( ! is_array( $b ) || empty( $b['rel'] ) ) {
		return null;
	}
	$path = rrt_spot_dir() . '/' . $b['rel'];
	return is_file( $path ) ? $b : null;
}

/** Older copies waiting to go, for a reading. */
function rrt_spot_old( $post_id ) {
	$b = get_post_meta( $post_id, RRT_SPOT_BUILT, true );
	return is_array( $b ) && ! empty( $b['old'] ) ? (array) $b['old'] : array();
}

/** The base of a reading's file names: its slug. */
function rrt_spot_slug( $post_id ) {
	$p    = get_post( $post_id );
	$slug = $p ? sanitize_title( $p->post_name ? $p->post_name : $p->post_title ) : '';
	$slug = preg_replace( '/[^a-z0-9-]/', '', (string) $slug );
	return '' !== $slug ? mb_substr( $slug, 0, 60 ) : 'reading-' . (int) $post_id;
}

/** What makes a reading's sponsored copy out of date. */
function rrt_spot_key( $post_id, $plan ) {
	$set   = rrt_spot_settings();
	$cap   = rrt_reading_captions( $post_id );
	$cpath = $cap['id'] ? rrt_att_file( $cap['id'], $post_id, 'text' ) : '';
	$slots = array();
	foreach ( $plan['slots'] as $k => $s ) {
		$slots[] = array( $k, $s['spot'], rrt_col_file_key( $s['copy']['path'] ), rrt_spot_who( $s['spot'] ), rrt_spot_get( $s['spot'] )['kind'] );
	}
	return md5(
		wp_json_encode(
			array(
				RRT_SPOT_FORMAT,
				$plan['info']['key'],
				'' !== $cpath ? rrt_col_file_key( $cpath ) : '',
				/* The reading's own chapters, which the copy carries; not the post's edit time, so fixing a typo in the post does not make a new file. */
				function_exists( 'rrt_feed_facts' ) ? md5( wp_json_encode( rrt_feed_facts( $post_id )['chapters'] ) ) : '',
				$slots,
				isset( $plan['slots']['mid'] ) && $plan['mid'] ? $plan['mid']['t'] : null,
				$set['chapters'],
				get_the_title( $post_id ),
				get_permalink( $post_id ),
			)
		)
	);
}

/** Whether a reading (never a message) is members-only now or no longer published (1.25.0). */
function rrt_spot_now_private( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post || rrt_spot_is( $post ) ) {
		return false;
	}
	if ( 'publish' !== $post->post_status || '' !== $post->post_password ) {
		return true;
	}
	return function_exists( 'rrt_access_locked' ) && rrt_access_locked( $post_id );
}

/**
 * Makes, keeps or clears a reading's sponsored copy to match today's plan.
 *
 * @return array|null|WP_Error The built record, null when the reading has no messages now.
 */
function rrt_spot_sync( $post_id, $today = null ) {
	/*
	 * 1.25.0: a reading that became members-only or left publishing loses its
	 * sponsored copies at once. The two days older copies are kept are for apps
	 * that read the feed before a copy was remade; they would keep a reading
	 * that is now private open to anyone.
	 */
	if ( rrt_spot_now_private( $post_id ) ) {
		rrt_spot_clear( $post_id );
		rrt_spot_trim( $post_id, PHP_INT_MAX );
		return null;
	}
	$plan = rrt_spot_plan( $post_id, $today );
	if ( ! $plan['slots'] ) {
		rrt_spot_clear( $post_id );
		return null;
	}
	$key = rrt_spot_key( $post_id, $plan );
	$cur = rrt_spot_built( $post_id );
	if ( $cur && isset( $cur['key'] ) && $cur['key'] === $key ) {
		return $cur;
	}
	if ( get_transient( 'rrt_spot_lock_' . $post_id ) ) {
		return new WP_Error( 'rrt_spot', __( 'This reading\'s sponsored copy is being made already.', 'foxfire-studio' ) );
	}
	set_transient( 'rrt_spot_lock_' . $post_id, 1, 5 * MINUTE_IN_SECONDS );
	$r = rrt_spot_make( $post_id, $plan, $key );
	delete_transient( 'rrt_spot_lock_' . $post_id );
	/*
	 * Saving a post in the block editor is two requests (the post, then its
	 * boxes). If the choices changed while this copy was being made, the
	 * copy may already be out of date: look again once, with fresh values.
	 */
	static $again = 0;
	if ( is_array( $r ) && $again < 1 ) {
		wp_cache_delete( $post_id, 'post_meta' );
		$now = rrt_spot_plan( $post_id, $today );
		if ( ! $now['slots'] || rrt_spot_key( $post_id, $now ) !== $key ) {
			++$again;
			$r2 = rrt_spot_sync( $post_id, $today );
			--$again;
			return $r2;
		}
	}
	if ( is_array( $r ) ) {
		/* The log follows what the file holds (a mid-roll that found no clean cut is left out). */
		$slots = array();
		foreach ( $r['parts'] as $pp ) {
			$slots[ $pp['slot'] ] = array( 'spot' => $pp['spot'] );
		}
		rrt_spot_log( $post_id, array( 'slots' => $slots ) );
		rrt_spot_purge( $post_id );
	}
	return $r;
}

/** Writes the sponsored copy for a plan, with its captions and chapters files. */
function rrt_spot_make( $post_id, $plan, $key ) {
	$info  = $plan['info'];
	$scan  = $info['scan'];
	$fmt   = $scan;
	$rate  = (int) $fmt['rate'];
	$fl    = 1152 / $rate;
	$set   = rrt_spot_settings();
	$gapf  = rrt_col_gap_frames( RRT_SPOT_GAP, $rate );
	$midf  = rrt_spot_gapf_mid( $rate );
	$segs  = array();
	$parts = array();
	$t     = 0.0;
	$map   = array(
		'pre' => 0.0,
		'cut' => null,
		'mid' => 0.0,
	);

	if ( isset( $plan['slots']['pre'] ) ) {
		$c      = $plan['slots']['pre']['copy'];
		$segs[] = array(
			'path'  => $c['path'],
			'start' => $c['scan']['start'],
			'end'   => $c['scan']['end'],
		);
		$parts[] = array( 'pre', $plan['slots']['pre']['spot'], $t, $t + $c['scan']['frames'] * $fl );
		$t      += ( $c['scan']['frames'] + $gapf ) * $fl;
		$segs[]  = array( 'bytes' => rrt_col_silence( $fmt, $gapf ) );
		$map['pre'] = $t;
	}
	$cut = null;
	if ( isset( $plan['slots']['mid'] ) && $plan['mid'] ) {
		$k = (int) floor( $plan['mid']['t'] / $fl );
		$a = rrt_spot_frames_at( $info['path'], $scan, $k );
		if ( $a ) {
			$nb = rrt_spot_bridge_count( wp_list_pluck( $a['before'], 'info' ), wp_list_pluck( $a['after'], 'info' ) );
			/* The muted frames must sit inside the pause too. */
			if ( $nb >= 0 && ( $k - $nb ) * $fl >= $plan['mid']['s'] ) {
				$cut = array(
					'k'   => $k,
					'off' => $a['off'],
					'nb'  => $nb,
					'br'  => array_slice( $a['before'], count( $a['before'] ) - $nb ),
				);
			}
		}
	}
	if ( $cut ) {
		$segs[] = array(
			'path'  => $info['path'],
			'start' => $scan['start'],
			'end'   => $cut['off'],
		);
		$t     += $cut['k'] * $fl;
		$c      = $plan['slots']['mid']['copy'];
		$segs[] = array( 'bytes' => rrt_col_silence( $fmt, $midf ) );
		$t     += $midf * $fl;
		$segs[] = array(
			'path'  => $c['path'],
			'start' => $c['scan']['start'],
			'end'   => $c['scan']['end'],
		);
		$parts[] = array( 'mid', $plan['slots']['mid']['spot'], $t, $t + $c['scan']['frames'] * $fl );
		$t      += $c['scan']['frames'] * $fl;
		$segs[]  = array( 'bytes' => rrt_col_silence( $fmt, $midf ) );
		$t      += $midf * $fl;
		$bridge  = '';
		$fh      = fopen( $info['path'], 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $fh ) {
			return new WP_Error( 'rrt_spot', __( 'The reading could not be read.', 'foxfire-studio' ) );
		}
		foreach ( $cut['br'] as $f ) {
			fseek( $fh, $f['off'] );
			$bridge .= rrt_spot_mute( (string) fread( $fh, $f['info']['len'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( '' !== $bridge ) {
			$segs[] = array( 'bytes' => $bridge );
		}
		$t         += $cut['nb'] * $fl;
		$map['cut'] = $cut['k'] * $fl;
		$map['mid'] = ( 2 * $midf + $c['scan']['frames'] + $cut['nb'] ) * $fl;
		$segs[]     = array(
			'path'  => $info['path'],
			'start' => $cut['off'],
			'end'   => $scan['end'],
		);
		$t         += ( $scan['frames'] - $cut['k'] ) * $fl;
	} else {
		unset( $plan['slots']['mid'] );
		$segs[] = array(
			'path'  => $info['path'],
			'start' => $scan['start'],
			'end'   => $scan['end'],
		);
		$t     += $scan['frames'] * $fl;
	}
	if ( isset( $plan['slots']['post'] ) ) {
		$c       = $plan['slots']['post']['copy'];
		$segs[]  = array( 'bytes' => rrt_col_silence( $fmt, $gapf ) );
		$t      += $gapf * $fl;
		$segs[]  = array(
			'path'  => $c['path'],
			'start' => $c['scan']['start'],
			'end'   => $c['scan']['end'],
		);
		$parts[] = array( 'post', $plan['slots']['post']['spot'], $t, $t + $c['scan']['frames'] * $fl );
		$t      += $c['scan']['frames'] * $fl;
	}
	if ( ! $parts ) {
		rrt_spot_clear( $post_id );
		return null;
	}
	$total = $t;
	$title = html_entity_decode( wp_strip_all_tags( get_the_title( $post_id ) ), ENT_QUOTES, 'UTF-8' );

	/* Chapters: the reading's own at their new times, and one for each message. */
	$own = array();
	if ( function_exists( 'rrt_feed_facts' ) ) {
		$facts = rrt_feed_facts( $post_id );
		$own   = isset( $facts['chapters'] ) ? (array) $facts['chapters'] : array();
	}
	$chapters = rrt_spot_chapters( $own, $parts, $map, $title, get_permalink( $post_id ), $total, $set['chapters'] );

	/* Captions: the reading's at their new times, and each message's own (or a line saying what it is). */
	$cap  = rrt_reading_captions( $post_id );
	$cp   = $cap['id'] ? rrt_att_file( $cap['id'], $post_id, 'text' ) : ( '' !== $cap['url'] ? rrt_podcast_local_path( $cap['url'], $post_id, 'text' ) : '' );
	$vtt  = '';
	if ( $cp && is_file( $cp ) && filesize( $cp ) < 5242880 ) {
		$vtt = rrt_spot_vtt( (string) file_get_contents( $cp ), $parts, $map, $rate ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	$p   = get_post( $post_id );
	$tag = rrt_col_id3(
		array(
			'title'    => $title,
			'artist'   => $p ? wp_strip_all_tags( get_the_author_meta( 'display_name', $p->post_author ) ) : '',
			'album'    => rrt_show_name(),
			'year'     => get_the_date( 'Y', $post_id ),
			'ms'       => $total * 1000,
			'url'      => get_permalink( $post_id ),
			'chapters' => $chapters,
			'art'      => rrt_col_art( $post_id ),
		)
	);
	array_unshift( $segs, array( 'bytes' => $tag ) );

	$dir = rrt_spot_dir() . '/' . (int) $post_id;
	if ( ! wp_mkdir_p( $dir ) ) {
		return new WP_Error( 'rrt_spot', __( 'The folder for sponsored copies could not be made. Check there is room on the site.', 'foxfire-studio' ) );
	}
	$base  = rrt_spot_slug( $post_id ) . '-sp' . substr( $key, 0, 8 );
	$rel   = (int) $post_id . '/' . $base . '.mp3';
	$bytes = rrt_spot_write( rrt_spot_dir() . '/' . $rel, $segs );
	if ( false === $bytes ) {
		wp_delete_file( rrt_spot_dir() . '/' . $rel );
		return new WP_Error( 'rrt_spot', __( 'The sponsored copy could not be written. Check there is room on the site.', 'foxfire-studio' ) );
	}
	$vrel = '';
	if ( '' !== $vtt ) {
		$vrel = (int) $post_id . '/' . $base . '.vtt';
		file_put_contents( rrt_spot_dir() . '/' . $vrel, $vtt ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
	$jrel = '';
	if ( $chapters && function_exists( 'rrt_chapters_json' ) ) {
		$jrel = (int) $post_id . '/' . $base . '.json';
		file_put_contents( rrt_spot_dir() . '/' . $jrel, rrt_chapters_json( $chapters, $title ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	$prev = get_post_meta( $post_id, RRT_SPOT_BUILT, true );
	$old  = rrt_spot_old( $post_id );
	if ( is_array( $prev ) && ! empty( $prev['rel'] ) && $prev['rel'] !== $rel ) {
		$old[] = array(
			'rels'  => array_values( array_filter( array( $prev['rel'], isset( $prev['vtt'] ) ? $prev['vtt'] : '', isset( $prev['json'] ) ? $prev['json'] : '' ) ) ),
			'until' => time() + RRT_SPOT_KEEP,
		);
	}
	$sum = array();
	foreach ( $parts as $pp ) {
		$sum[] = array(
			'slot'  => $pp[0],
			'spot'  => (int) $pp[1],
			'who'   => rrt_spot_who( $pp[1] ),
			'kind'  => rrt_spot_get( $pp[1] )['kind'],
			'start' => round( $pp[2], 3 ),
			'end'   => round( $pp[3], 3 ),
		);
	}
	$rec = array(
		'key'      => $key,
		'at'       => time(),
		'rel'      => $rel,
		'vtt'      => $vrel,
		'json'     => $jrel,
		'bytes'    => (int) $bytes,
		'secs'     => round( $total, 3 ),
		'map'      => $map,
		'cut'      => $cut ? round( $cut['k'] * $fl, 3 ) : null,
		'bridge'   => $cut ? (int) $cut['nb'] : 0,
		'parts'    => $sum,
		'chapters' => count( $chapters ),
		'old'      => $old,
	);
	update_post_meta( $post_id, RRT_SPOT_BUILT, $rec );
	return $rec;
}

/** Frames of silence either side of a mid-roll. */
function rrt_spot_gapf_mid( $rate ) {
	return rrt_col_gap_frames( RRT_SPOT_MID_GAP, $rate );
}

/**
 * A sponsored copy's chapters.
 *
 * @param array  $own      The reading's own chapters (start, end, title, url).
 * @param array  $parts    Each message: slot, spot, start, end (in the copy).
 * @param array  $map      As rrt_spot_map() takes it.
 * @param string $title    The reading's title.
 * @param string $link     The reading's page.
 * @param float  $total    The copy's length.
 * @param bool   $marks    Whether messages get chapters of their own.
 * @return array[] start, end, title, url.
 */
function rrt_spot_chapters( $own, $parts, $map, $title, $link, $total, $marks ) {
	usort(
		$own,
		function ( $a, $b ) {
			return (float) $a['start'] <=> (float) $b['start'];
		}
	);
	if ( ! $marks ) {
		if ( ! $own ) {
			return array();
		}
		$out = array();
		foreach ( $own as $c ) {
			$out[] = array(
				'start' => rrt_spot_map( $c['start'], $map ),
				'title' => (string) $c['title'],
				'url'   => isset( $c['url'] ) ? (string) $c['url'] : '',
			);
		}
		/* The first chapter covers the pre-roll too. */
		if ( (float) $own[0]['start'] <= 0.5 ) {
			$out[0]['start'] = 0;
		}
		return rrt_spot_chapter_ends( $out, $total );
	}
	if ( ! $own || (float) $own[0]['start'] > 0.5 ) {
		array_unshift(
			$own,
			array(
				'start' => 0,
				'title' => $title,
				'url'   => $link,
			)
		);
	}
	$out = array();
	foreach ( $own as $c ) {
		$out[] = array(
			'start' => rrt_spot_map( $c['start'], $map ),
			'title' => (string) $c['title'],
			'url'   => isset( $c['url'] ) ? (string) $c['url'] : '',
			'src'   => (float) $c['start'],
		);
	}
	foreach ( $parts as $p ) {
		$who  = rrt_spot_who( $p[1] );
		$link2 = rrt_spot_get( $p[1] )['link'];
		/* translators: %s: who the sponsor message is for. */
		$name  = sprintf( __( 'A word from %s', 'foxfire-studio' ), $who );
		$out[] = array(
			'start' => (float) $p[2] - ( 'pre' === $p[0] ? 0 : ( 'mid' === $p[0] ? RRT_SPOT_MID_GAP : RRT_SPOT_GAP ) ),
			'title' => $name,
			'url'   => (string) $link2,
			'msg'   => true,
		);
		if ( 'mid' === $p[0] && null !== $map['cut'] ) {
			/* The reading's chapter that the mid-roll falls in carries on after it. */
			$back = null;
			foreach ( $own as $c ) {
				if ( (float) $c['start'] <= (float) $map['cut'] ) {
					$back = $c;
				}
			}
			if ( $back ) {
				$out[] = array(
					'start' => rrt_spot_map( $map['cut'], $map ) - 0.0,
					'title' => (string) $back['title'],
					'url'   => isset( $back['url'] ) ? (string) $back['url'] : '',
				);
			}
		}
	}
	usort(
		$out,
		function ( $a, $b ) {
			return $a['start'] <=> $b['start'];
		}
	);
	foreach ( $out as $i => $c ) {
		unset( $out[ $i ]['src'], $out[ $i ]['msg'] );
		$out[ $i ]['start'] = max( 0, $c['start'] );
	}
	return rrt_spot_chapter_ends( $out, $total );
}

/** Each chapter runs until the next; empty ones go. */
function rrt_spot_chapter_ends( $out, $total ) {
	$out = array_values( $out );
	foreach ( $out as $i => $c ) {
		$out[ $i ]['start'] = round( $c['start'], 3 );
		$out[ $i ]['end']   = round( isset( $out[ $i + 1 ] ) ? $out[ $i + 1 ]['start'] : $total, 3 );
	}
	return array_values(
		array_filter(
			$out,
			function ( $c ) {
				return $c['end'] - $c['start'] > 0.05;
			}
		)
	);
}

/**
 * A sponsored copy's captions: the reading's at their new times, and each
 * message's own captions in its place (or one line saying what it is).
 *
 * @param string $vtt   The reading's captions.
 * @param array  $parts Each message: slot, spot, start, end.
 * @param array  $map   As rrt_spot_map() takes it.
 * @param int    $rate  The sample rate.
 * @return string
 */
function rrt_spot_vtt( $vtt, $parts, $map, $rate ) {
	$cues = array();
	foreach ( rrt_col_vtt_cues( $vtt ) as $c ) {
		$s = rrt_spot_map( $c['start'], $map );
		$e = rrt_spot_map( $c['end'], $map );
		if ( null !== $map['cut'] && $c['start'] < $map['cut'] && $c['end'] > $map['cut'] ) {
			$e = rrt_spot_map( $map['cut'], $map ) - $map['mid'];
		}
		if ( $e > $s ) {
			$cues[] = array( $s, $e, $c['text'] );
		}
	}
	foreach ( $parts as $p ) {
		$cap  = rrt_reading_captions( $p[1] );
		$path = $cap['id'] ? rrt_att_file( $cap['id'], $p[1], 'text' ) : '';
		$own  = ( $path && is_file( $path ) && filesize( $path ) < 1048576 ) ? rrt_col_vtt_cues( (string) file_get_contents( $path ) ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions
		/* A copy made in the browser starts a moment later: the encoder's own delay. */
		$lead = 1105 / $rate;
		$len  = (float) $p[3] - (float) $p[2];
		$n    = 0;
		foreach ( $own as $c ) {
			$s = (float) $p[2] + $lead + $c['start'];
			$e = min( (float) $p[3], (float) $p[2] + $lead + $c['end'] );
			if ( $e > $s ) {
				$cues[] = array( $s, $e, $c['text'] );
				++$n;
			}
		}
		if ( ! $n && $len > 0 ) {
			/* translators: %s: who the sponsor message is for. */
			$cues[] = array( (float) $p[2], (float) $p[3], sprintf( __( 'Sponsor message: %s', 'foxfire-studio' ), rrt_spot_who( $p[1] ) ) );
		}
	}
	usort(
		$cues,
		function ( $a, $b ) {
			return $a[0] <=> $b[0];
		}
	);
	$lines = array( 'WEBVTT', '' );
	foreach ( $cues as $i => $c ) {
		$lines[] = (string) ( $i + 1 );
		$lines[] = rrt_col_vtt_stamp( $c[0] ) . ' --> ' . rrt_col_vtt_stamp( $c[1] );
		$lines[] = $c[2];
		$lines[] = '';
	}
	return implode( "\n", $lines );
}

/** Takes a reading's sponsored copy out of use: the feed goes back to the reading; the files wait two days. */
function rrt_spot_clear( $post_id ) {
	$prev = get_post_meta( $post_id, RRT_SPOT_BUILT, true );
	if ( ! is_array( $prev ) ) {
		return;
	}
	$old = rrt_spot_old( $post_id );
	if ( ! empty( $prev['rel'] ) ) {
		$old[] = array(
			'rels'  => array_values( array_filter( array( $prev['rel'], isset( $prev['vtt'] ) ? $prev['vtt'] : '', isset( $prev['json'] ) ? $prev['json'] : '' ) ) ),
			'until' => time() + RRT_SPOT_KEEP,
		);
		rrt_spot_log( $post_id, array( 'slots' => array() ) );
		rrt_spot_purge( $post_id );
	}
	if ( $old ) {
		update_post_meta(
			$post_id,
			RRT_SPOT_BUILT,
			array(
				'rel' => '',
				'old' => $old,
			)
		);
	} else {
		delete_post_meta( $post_id, RRT_SPOT_BUILT );
	}
}

/** Deletes a reading's older copies once their two days are over. */
function rrt_spot_trim( $post_id, $now = null ) {
	$now  = null === $now ? time() : $now;
	$b    = get_post_meta( $post_id, RRT_SPOT_BUILT, true );
	if ( ! is_array( $b ) || empty( $b['old'] ) ) {
		return;
	}
	$keep = array();
	foreach ( (array) $b['old'] as $o ) {
		if ( (int) $o['until'] > $now ) {
			$keep[] = $o;
			continue;
		}
		foreach ( (array) $o['rels'] as $rel ) {
			/* Never the file in use now (the same plan can come back with the same name). */
			if ( '' !== $rel && ( empty( $b['rel'] ) || $rel !== $b['rel'] ) && ( empty( $b['vtt'] ) || $rel !== $b['vtt'] ) && ( empty( $b['json'] ) || $rel !== $b['json'] ) && false === strpos( $rel, '..' ) ) {
				wp_delete_file( rrt_spot_dir() . '/' . $rel );
			}
		}
	}
	$b['old'] = $keep;
	if ( empty( $b['rel'] ) && ! $keep ) {
		delete_post_meta( $post_id, RRT_SPOT_BUILT );
		@rmdir( rrt_spot_dir() . '/' . (int) $post_id ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
	} else {
		update_post_meta( $post_id, RRT_SPOT_BUILT, $b );
	}
}

/** Asks the cache to drop the feed and the reading's page (its thank-you line). */
function rrt_spot_purge( $post_id ) {
	if ( function_exists( 'rrt_podcast_purge' ) ) {
		rrt_podcast_purge();
	}
	do_action( 'litespeed_purge_post', (int) $post_id );
}

/**
 * Notes in each message's log when it starts and stops running on a reading.
 *
 * @param int   $post_id The reading.
 * @param array $plan    Its plan (slots: slot => spot).
 */
function rrt_spot_log( $post_id, $plan ) {
	$now  = time();
	$want = array();
	foreach ( (array) $plan['slots'] as $slot => $s ) {
		$want[ (int) $s['spot'] . ':' . $slot ] = array( (int) $s['spot'], $slot );
	}
	$spots = array();
	foreach ( rrt_spot_all() as $sp ) {
		$spots[] = (int) $sp->ID;
	}
	foreach ( $want as $w ) {
		$spots[] = $w[0];
	}
	foreach ( array_unique( $spots ) as $sid ) {
		$log     = get_post_meta( $sid, RRT_SPOT_LOG, true );
		$log     = is_array( $log ) ? $log : array();
		$changed = false;
		$open    = array();
		foreach ( $log as $i => $e ) {
			if ( (int) $e['post'] !== (int) $post_id || ! empty( $e['to'] ) ) {
				continue;
			}
			$k = $sid . ':' . $e['slot'];
			if ( isset( $want[ $k ] ) ) {
				$open[ $k ] = true;
			} else {
				$log[ $i ]['to'] = $now;
				$changed         = true;
			}
		}
		foreach ( $want as $k => $w ) {
			if ( $w[0] === $sid && ! isset( $open[ $k ] ) ) {
				$log[]   = array(
					'post' => (int) $post_id,
					'slot' => $w[1],
					'from' => $now,
					'to'   => 0,
				);
				$changed = true;
			}
		}
		if ( $changed ) {
			update_post_meta( $sid, RRT_SPOT_LOG, array_slice( $log, -RRT_SPOT_LOG_MAX ) );
		}
	}
}

/*
 * ---------------------------------------------------------------------
 * When to make the copies again
 * ---------------------------------------------------------------------
 */

/** The readings that could carry messages: every episode of the public feed. */
function rrt_spot_candidates() {
	return function_exists( 'rrt_podcast_posts' ) ? rrt_podcast_posts( false ) : array();
}

/** Every reading that has a sponsored copy, or older ones waiting to go. */
function rrt_spot_with_copies() {
	return get_posts(
		array(
			'post_type'        => array_values( get_post_types() ),
			'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
			'numberposts'      => -1,
			'fields'           => 'ids',
			'meta_key'         => RRT_SPOT_BUILT, // phpcs:ignore WordPress.DB.SlowDBQuery
			'suppress_filters' => true,
		)
	);
}

/** Brings every reading's sponsored copy up to date, and deletes older copies whose time is over. */
function rrt_spot_sync_all( $today = null ) {
	$seen = array();
	if ( rrt_spot_on() ) {
		foreach ( rrt_spot_candidates() as $p ) {
			$seen[ (int) $p->ID ] = true;
			rrt_spot_sync( $p->ID, $today );
		}
	}
	foreach ( rrt_spot_with_copies() as $id ) {
		if ( ! isset( $seen[ (int) $id ] ) ) {
			rrt_spot_clear( $id );
		}
		rrt_spot_trim( $id );
	}
}

/* The hourly job: dates start and end, and old copies go. */
add_action( 'rrt_share_hourly', 'rrt_spot_sync_all' );

/** Asks for a reading (or every reading, with 0) to be brought up to date at the end of this request. */
function rrt_spot_queue( $post_id = 0 ) {
	if ( ! isset( $GLOBALS['rrt_spot_queue'] ) ) {
		$GLOBALS['rrt_spot_queue'] = array();
		add_action( 'shutdown', 'rrt_spot_run_queue', 20 );
	}
	$GLOBALS['rrt_spot_queue'][ (int) $post_id ] = true;
}

/** Runs the queue. */
function rrt_spot_run_queue() {
	$q = isset( $GLOBALS['rrt_spot_queue'] ) ? array_keys( $GLOBALS['rrt_spot_queue'] ) : array();
	unset( $GLOBALS['rrt_spot_queue'] );
	if ( ! $q ) {
		return;
	}
	if ( in_array( 0, $q, true ) ) {
		rrt_spot_sync_all();
		return;
	}
	foreach ( $q as $id ) {
		if ( rrt_spot_on() || rrt_spot_built( $id ) ) {
			rrt_spot_sync( $id );
		}
	}
}

/** A reading's audio, captions, pauses, choices, loudness, kind, music or access changed. */
function rrt_spot_meta_changed( $meta_id, $post_id, $key ) {
	$keys = array( 'rrt_audio', 'rrt_captions', RRT_SPOT_POST, RRT_SPOT_PAUSES, '_rrt_kind', '_rrt_music_used' );
	if ( defined( 'RRT_LEVEL_META' ) ) {
		$keys[] = RRT_LEVEL_META;
	}
	if ( defined( 'RRT_ACCESS_META' ) ) {
		$keys[] = RRT_ACCESS_META;
	}
	if ( defined( 'RRT_COL_BUILT' ) ) {
		$keys[] = RRT_COL_BUILT;
	}
	if ( ! in_array( $key, $keys, true ) ) {
		return;
	}
	if ( rrt_spot_is( $post_id ) ) {
		/* A message recorded again: its copies are out of date everywhere. */
		if ( 'rrt_audio' === $key || 'rrt_captions' === $key ) {
			rrt_spot_queue( 0 );
		}
		return;
	}
	if ( rrt_spot_on() || rrt_spot_built( $post_id ) ) {
		rrt_spot_queue( $post_id );
	}
}
add_action( 'added_post_meta', 'rrt_spot_meta_changed', 40, 3 );
add_action( 'updated_post_meta', 'rrt_spot_meta_changed', 40, 3 );
add_action( 'deleted_post_meta', 'rrt_spot_meta_changed', 40, 3 );

/* A post published, unpublished, trashed or changed: its copy follows; a message changed: every reading follows. */
add_action(
	'transition_post_status',
	function ( $new, $old, $post ) {
		if ( $new === $old && 'publish' !== $new ) {
			return;
		}
		if ( RRT_SPOT_TYPE === $post->post_type ) {
			rrt_spot_queue( 0 );
		} elseif ( rrt_spot_on() || rrt_spot_built( $post->ID ) ) {
			rrt_spot_queue( $post->ID );
		}
	},
	10,
	3
);

/* A message or a reading deleted. */
add_action(
	'before_delete_post',
	function ( $post_id ) {
		if ( rrt_spot_is( $post_id ) ) {
			foreach ( rrt_spot_copies( $post_id ) as $c ) {
				wp_delete_attachment( $c['att'], true );
			}
			rrt_spot_queue( 0 );
			return;
		}
		$b = get_post_meta( $post_id, RRT_SPOT_BUILT, true );
		if ( is_array( $b ) ) {
			$rels = array();
			if ( ! empty( $b['rel'] ) ) {
				$rels = array( $b['rel'], isset( $b['vtt'] ) ? $b['vtt'] : '', isset( $b['json'] ) ? $b['json'] : '' );
			}
			foreach ( (array) ( isset( $b['old'] ) ? $b['old'] : array() ) as $o ) {
				$rels = array_merge( $rels, (array) $o['rels'] );
			}
			foreach ( array_filter( $rels ) as $rel ) {
				if ( false === strpos( $rel, '..' ) ) {
					wp_delete_file( rrt_spot_dir() . '/' . $rel );
				}
			}
			@rmdir( rrt_spot_dir() . '/' . (int) $post_id ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}
	}
);

/*
 * ---------------------------------------------------------------------
 * The feed
 * ---------------------------------------------------------------------
 */

/**
 * An episode as the feed gives it while its reading has a sponsored copy:
 * the copy's audio, length, captions, chapters and soundbite, and a line in
 * the notes.
 *
 * @param array        $e    The episode (rrt_podcast_episode()).
 * @param WP_Post      $post The reading.
 * @param array|null   $m    For a member's feed: who it is for; null for the public feed.
 * @return array
 */
function rrt_spot_episode( $e, $post, $m = null ) {
	if ( ! $e || ! rrt_spot_on() ) {
		return $e;
	}
	$set = rrt_spot_settings();
	if ( $m && ! $set['members'] ) {
		return $e;
	}
	if ( function_exists( 'rrt_access_locked' ) && rrt_access_locked( $post->ID ) ) {
		return $e;
	}
	$b = rrt_spot_built( $post->ID );
	if ( ! $b || empty( $b['parts'] ) ) {
		return $e;
	}
	$url            = rrt_spot_url( $b['rel'] );
	$e['url']       = $url;
	$e['enclosure'] = ( function_exists( 'rrt_dl_on' ) && rrt_dl_on() ) ? rrt_dl_url( $post->ID, $url ) : $url;
	$e['bytes']     = (int) $b['bytes'];
	$e['secs']      = (int) round( (float) $b['secs'] );
	$e['type']      = 'audio/mpeg';
	if ( '' !== $e['vtt'] ) {
		$e['vtt'] = ! empty( $b['vtt'] ) ? rrt_spot_url( $b['vtt'] ) : '';
	}
	$e['chapters']  = ! empty( $b['json'] ) ? rrt_spot_url( $b['json'] ) : '';
	$e['nchapters'] = (int) $b['chapters'];
	if ( ! empty( $e['soundbite'] ) && isset( $b['map'] ) ) {
		$e['soundbite']['start'] = rrt_spot_map( $e['soundbite']['start'], $b['map'] );
	}
	$e['sponsor'] = rrt_spot_notes( $b );
	return $e;
}

/**
 * The lines at the top of an episode's notes: one for each message in it.
 *
 * @return array[] text (such as "Sponsored by Acme"), link (or ''), paid.
 */
function rrt_spot_notes( $b ) {
	$set   = rrt_spot_settings();
	$seen  = array();
	$lines = array();
	foreach ( (array) $b['parts'] as $p ) {
		if ( isset( $seen[ $p['spot'] ] ) ) {
			continue;
		}
		$seen[ $p['spot'] ] = true;
		$m    = rrt_spot_get( $p['spot'] );
		$who  = rrt_spot_who( $p['spot'] );
		$own  = 'own' === $m['kind'];
		/* translators: %s: who the message is for. */
		$f    = $own ? ( '' !== $set['line_own'] ? $set['line_own'] : __( 'Supported by %s', 'foxfire-studio' ) ) : ( '' !== $set['line_paid'] ? $set['line_paid'] : __( 'Sponsored by %s', 'foxfire-studio' ) );
		$line    = false !== strpos( $f, '%s' ) ? str_replace( '%s', $who, $f ) : $f . ' ' . $who;
		$lines[] = array(
			'text' => rtrim( trim( $line ), '.:' ),
			'link' => (string) $m['link'],
			'paid' => ! $own,
		);
	}
	return $lines;
}

/**
 * The sponsored file behind a short download address, when its name is the
 * name of a reading's sponsored copy (current or waiting to go).
 *
 * @return array|null url, bytes, secs.
 */
function rrt_spot_dl_target( $post_id, $name ) {
	if ( ! preg_match( '/-sp[0-9a-f]{8}\.mp3$/', (string) $name ) || rrt_spot_now_private( $post_id ) ) {
		return null;
	}
	$b = rrt_spot_built( $post_id );
	if ( $b && wp_basename( $b['rel'] ) === $name ) {
		return array(
			'url'   => rrt_spot_url( $b['rel'] ),
			'bytes' => (int) $b['bytes'],
			'secs'  => (int) round( (float) $b['secs'] ),
			/* Where each message sits, for counting messages delivered (1.24.0). */
			'parts' => isset( $b['parts'] ) ? (array) $b['parts'] : array(),
		);
	}
	foreach ( rrt_spot_old( $post_id ) as $o ) {
		foreach ( (array) $o['rels'] as $rel ) {
			if ( wp_basename( $rel ) === $name && is_file( rrt_spot_dir() . '/' . $rel ) ) {
				return array(
					'url'   => rrt_spot_url( $rel ),
					'bytes' => (int) filesize( rrt_spot_dir() . '/' . $rel ),
					'secs'  => 0,
				);
			}
		}
	}
	/* An older name with no file left: the current copy, or the reading. */
	if ( $b ) {
		return array(
			'url'   => rrt_spot_url( $b['rel'] ),
			'bytes' => (int) $b['bytes'],
			'secs'  => (int) round( (float) $b['secs'] ),
		);
	}
	return null;
}

/** The thank-you line under a reading's player, when it is switched on and the episode carries messages. */
function rrt_spot_thank_html( $post ) {
	$post = get_post( $post );
	if ( ! $post || ! rrt_spot_on() || ! rrt_spot_settings()['thank'] ) {
		return '';
	}
	$b = rrt_spot_built( $post->ID );
	if ( ! $b || empty( $b['parts'] ) ) {
		return '';
	}
	$paid = array();
	$own  = array();
	$seen = array();
	foreach ( $b['parts'] as $p ) {
		if ( isset( $seen[ $p['spot'] ] ) ) {
			continue;
		}
		$seen[ $p['spot'] ] = true;
		$m    = rrt_spot_get( $p['spot'] );
		$name = esc_html( rrt_spot_who( $p['spot'] ) );
		$html = '' !== $m['link'] ? '<a href="' . esc_url( $m['link'] ) . '" rel="' . ( 'own' === $m['kind'] ? 'noopener' : 'sponsored noopener' ) . '">' . $name . '</a>' : $name;
		if ( 'own' === $m['kind'] ) {
			$own[] = $html;
		} else {
			$paid[] = $html;
		}
	}
	$out = '';
	if ( $paid ) {
		/* translators: %s: one or more sponsors' names. */
		$out .= sprintf( esc_html__( 'This reading\'s podcast episode is sponsored by %s.', 'foxfire-studio' ), implode( ', ', $paid ) ) . ' ';
	}
	if ( $own ) {
		/* translators: %s: one or more names. */
		$out .= sprintf( esc_html__( 'This reading\'s podcast episode is supported by %s.', 'foxfire-studio' ), implode( ', ', $own ) );
	}
	return '<p class="rrt-sponsor-line">' . trim( $out ) . '</p>';
}

/*
 * ---------------------------------------------------------------------
 * The reading's box
 * ---------------------------------------------------------------------
 */

/** What the box on a reading's screen needs, for sponsors.js. */
function rrt_spot_box_boot( $post ) {
	$info  = rrt_spot_reading( $post->ID );
	$plan  = rrt_spot_plan( $post->ID );
	$why   = $info['ok'] ? rrt_spot_mid_why( $post->ID, $info ) : '';
	$secs  = $info['ok'] ? $info['scan']['secs'] : 0;
	$p     = $info['ok'] ? rrt_spot_pauses( $post->ID, $info['key'] ) : null;
	$audio = rrt_reading_audio( $post->ID );
	return array(
		'post'    => (int) $post->ID,
		'rest'    => esc_url_raw( rest_url( 'rrt/v1/' ) ),
		'nonce'   => wp_create_nonce( 'wp_rest' ),
		'url'     => $audio['url'],
		'key'     => $info['key'],
		'secs'    => round( $secs, 3 ),
		/* The pauses are found once per file in the browser, only when a mid-roll could go in. */
		'find'    => $info['ok'] && ! $info['col'] && 'pauses' === $why,
		'suggest' => $p ? rrt_spot_pause_suggest( $p, $secs ) : array(),
		'mid'     => $plan['mid'],
		'want'    => rrt_spot_post_choice( $post->ID )['mid'],
		'min'     => RRT_SPOT_PAUSE_MIN,
	);
}

/** A reason a reading takes no mid-roll, in words. */
function rrt_spot_mid_words( $why ) {
	$set = rrt_spot_settings();
	$map = array(
		/* translators: %d: minutes. */
		'short'   => sprintf( _n( 'No mid-roll: readings shorter than %d minute take a pre-roll and post-roll only (Podcast feed, Sponsor messages).', 'No mid-roll: readings shorter than %d minutes take a pre-roll and post-roll only (Podcast feed, Sponsor messages).', $set['mid_min'], 'foxfire-studio' ), $set['mid_min'] ),
		'kind'    => __( 'No mid-roll for this kind of reading (Podcast feed, Sponsor messages, Mid-rolls in).', 'foxfire-studio' ),
		'music'   => __( 'No mid-roll: the music bed plays through every pause, so a cut would stop the music. Pre-rolls and post-rolls still fit.', 'foxfire-studio' ),
		'one'     => __( 'No mid-roll: it goes between two readings, and this collection has one.', 'foxfire-studio' ),
		'pauses'  => __( 'Finding the pauses in this reading...', 'foxfire-studio' ),
		/* translators: %s: seconds. */
		'nopause' => sprintf( __( 'No mid-roll: no pause of %s seconds or more was found.', 'foxfire-studio' ), number_format_i18n( RRT_SPOT_PAUSE_MIN, 1 ) ),
	);
	return isset( $map[ $why ] ) ? $map[ $why ] : '';
}

/** Prints the Sponsor messages part of the Reading audio box (and a collection's own box). */
function rrt_spot_box_fields( $post ) {
	if ( ! rrt_spot_on() ) {
		return;
	}
	$c    = rrt_spot_post_choice( $post->ID );
	$info = rrt_spot_reading( $post->ID );
	$plan = rrt_spot_plan( $post->ID );
	$b    = rrt_spot_built( $post->ID );
	wp_nonce_field( 'rrt_spot_box', 'rrt_spot_nonce' );
	echo '<div class="rrt-spot-box" data-rrt-spot-box>';
	echo '<p class="rrt-box-field"><strong>' . esc_html__( 'Sponsor messages', 'foxfire-studio' ) . '</strong><br>';
	echo '<label><input type="checkbox" name="rrt_spot_off" value="1"' . checked( $c['off'], true, false ) . '> ' . esc_html__( 'No sponsor messages on this reading', 'foxfire-studio' ) . '</label></p>';
	if ( ! $c['off'] && $info['ok'] ) {
		$why = rrt_spot_mid_why( $post->ID, $info );
		if ( in_array( $why, array( '', 'pauses' ), true ) && ! $info['col'] ) {
			echo '<p class="rrt-box-field"><label for="rrt_spot_mid">' . esc_html__( 'Mid-roll goes in the pause at', 'foxfire-studio' ) . '</label><br>';
			echo '<select class="widefat" id="rrt_spot_mid" name="rrt_spot_mid" data-rrt-spot-mid>';
			echo '<option value="">' . esc_html__( 'The best pause near the middle', 'foxfire-studio' ) . '</option>';
			$p = rrt_spot_pauses( $post->ID, $info['key'] );
			foreach ( rrt_spot_pause_suggest( (array) $p, $info['scan']['secs'] ) as $s ) {
				/* translators: 1: a time such as 7:12, 2: the pause's length in seconds. */
				echo '<option value="' . esc_attr( $s['t'] ) . '"' . selected( null !== $c['mid'] && abs( $c['mid'] - $s['t'] ) < 0.01, true, false ) . '>' . esc_html( sprintf( __( '%1$s (a pause of %2$s s)', 'foxfire-studio' ), rrt_spot_clock( $s['t'] ), number_format_i18n( $s['e'] - $s['s'], 1 ) ) ) . '</option>';
			}
			echo '<option value="other"' . selected( null !== $c['mid'] && ! rrt_spot_is_suggested( $c['mid'], (array) $p, $info['scan']['secs'] ), true, false ) . '>' . esc_html__( 'Another time...', 'foxfire-studio' ) . '</option>';
			echo '</select></p>';
			if ( 'pauses' === $why ) {
				echo '<p class="description" data-rrt-spot-finding>' . esc_html( rrt_spot_mid_words( $why ) ) . '</p>';
			}
			echo '<p class="rrt-box-field" data-rrt-spot-other><label for="rrt_spot_at">' . esc_html__( 'Time (m:ss), snapped to the nearest pause', 'foxfire-studio' ) . '</label> <input type="text" class="small-text" id="rrt_spot_at" name="rrt_spot_at" value="' . esc_attr( null !== $c['mid'] ? rrt_spot_clock( $c['mid'] ) : '' ) . '" placeholder="7:00"></p>';
			echo '<p class="rrt-box-field"><button type="button" class="button" data-rrt-spot-hear>' . esc_html__( 'Hear 5 seconds either side', 'foxfire-studio' ) . '</button></p>';
		} elseif ( '' !== $why ) {
			echo '<p class="description" data-rrt-spot-why="' . esc_attr( $why ) . '">' . esc_html( rrt_spot_mid_words( $why ) ) . '</p>';
		}
	} elseif ( ! $c['off'] && ! $info['ok'] && 'members' === $info['why'] ) {
		echo '<p class="description">' . esc_html__( 'While this reading is for members, it carries no messages.', 'foxfire-studio' ) . '</p>';
	}
	if ( $b && ! empty( $b['parts'] ) ) {
		$bits = array();
		foreach ( $b['parts'] as $p ) {
			$slot   = rrt_spot_slots()[ $p['slot'] ];
			$bits[] = 'mid' === $p['slot'] && null !== $b['cut']
				/* translators: 1: Mid-roll, 2: a time such as 7:12, 3: who the message is for. */
				? sprintf( __( '%1$s at %2$s: %3$s', 'foxfire-studio' ), $slot, rrt_spot_clock( $b['cut'] ), $p['who'] )
				/* translators: 1: Pre-roll or Post-roll, 2: who the message is for. */
				: sprintf( __( '%1$s: %2$s', 'foxfire-studio' ), $slot, $p['who'] );
		}
		/* translators: 1: what the copy holds, 2: its length, 3: when it was made. */
		echo '<p class="description">' . esc_html( sprintf( __( 'The podcast episode now: %1$s. %2$s, made %3$s.', 'foxfire-studio' ), implode( '; ', $bits ), rrt_spot_clock( $b['secs'] ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $b['at'] ) ) ) . '</p>';
		echo '<p><audio controls preload="none" style="width:100%" src="' . esc_url( rrt_spot_url( $b['rel'] ) ) . '"></audio></p>';
	} elseif ( ! $c['off'] && $info['ok'] ) {
		echo '<p class="description">' . esc_html__( 'No message runs on this reading today.', 'foxfire-studio' ) . '</p>';
	}
	if ( $plan['waiting'] ) {
		$names = array_map( 'rrt_spot_who', $plan['waiting'] );
		/* translators: %s: names of messages. */
		echo '<p class="description">' . esc_html( sprintf( __( 'Waiting for a copy in this reading\'s sound: %s. Open the message and press Make them now.', 'foxfire-studio' ), implode( ', ', $names ) ) ) . '</p>';
	}
	if ( null === $info['lu'] && $info['ok'] && ! $c['off'] ) {
		echo '<p class="description">' . esc_html__( 'This reading\'s loudness is measured the first time a message\'s screen lists it.', 'foxfire-studio' ) . '</p>';
	}
	echo '<script type="application/json" class="rrt-spot-boot">' . wp_json_encode( rrt_spot_box_boot( $post ), JSON_HEX_TAG | JSON_HEX_AMP ) . '</script>';
	echo '</div>';
}
add_action( 'rrt_reading_box_after', 'rrt_spot_box_fields', 20 );

/** Whether a time is one of the suggestions. */
function rrt_spot_is_suggested( $t, $pauses, $secs ) {
	foreach ( rrt_spot_pause_suggest( $pauses, $secs ) as $s ) {
		if ( abs( $s['t'] - $t ) < 0.01 ) {
			return true;
		}
	}
	return false;
}

/** Seconds as m:ss (or h:mm:ss). */
function rrt_spot_clock( $s ) {
	$s = max( 0, (int) round( (float) $s ) );
	$h = intdiv( $s, 3600 );
	$m = intdiv( $s % 3600, 60 );
	$r = $s % 60;
	return $h ? sprintf( '%d:%02d:%02d', $h, $m, $r ) : sprintf( '%d:%02d', $m, $r );
}

/** m:ss, h:mm:ss or plain seconds as seconds, or null. */
function rrt_spot_parse_time( $s ) {
	$s = trim( (string) $s );
	if ( preg_match( '/^(\d+):(\d{1,2}):(\d{1,2})(?:[.,]\d+)?$/', $s, $m ) ) {
		return (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3];
	}
	if ( preg_match( '/^(\d+):(\d{1,2})(?:[.,](\d+))?$/', $s, $m ) ) {
		return (int) $m[1] * 60 + (int) $m[2] + ( isset( $m[3] ) ? (float) ( '0.' . $m[3] ) : 0 );
	}
	if ( preg_match( '/^\d+(?:\.\d+)?$/', $s ) ) {
		return (float) $s;
	}
	return null;
}

/* A collection's own Sponsor messages box. */
add_action(
	'add_meta_boxes',
	function () {
		if ( defined( 'RRT_COL_TYPE' ) && rrt_spot_on() ) {
			add_meta_box( 'rrt-spots', __( 'Sponsor messages', 'foxfire-studio' ), 'rrt_spot_box_fields', RRT_COL_TYPE, 'side' );
		}
	}
);

/* Saves the box. */
add_action(
	'save_post',
	function ( $post_id ) {
		if ( ! isset( $_POST['rrt_spot_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rrt_spot_nonce'] ) ), 'rrt_spot_box' ) ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		$c   = rrt_spot_post_choice( $post_id );
		$off = ! empty( $_POST['rrt_spot_off'] );
		$mid = $c['mid'];
		if ( isset( $_POST['rrt_spot_mid'] ) ) {
			$v = sanitize_text_field( wp_unslash( $_POST['rrt_spot_mid'] ) );
			if ( '' === $v ) {
				$mid = null;
			} elseif ( 'other' === $v ) {
				$t   = isset( $_POST['rrt_spot_at'] ) ? rrt_spot_parse_time( sanitize_text_field( wp_unslash( $_POST['rrt_spot_at'] ) ) ) : null;
				$mid = null !== $t && $t > 0 ? (float) $t : null;
			} elseif ( is_numeric( $v ) && (float) $v > 0 ) {
				$mid = (float) $v;
			}
		}
		$new = array_filter(
			array(
				'off' => $off ? 1 : 0,
				'mid' => null === $mid ? null : round( $mid, 3 ),
			)
		);
		if ( $new ) {
			update_post_meta( $post_id, RRT_SPOT_POST, $new );
		} else {
			delete_post_meta( $post_id, RRT_SPOT_POST );
		}
	},
	25
);

/*
 * ---------------------------------------------------------------------
 * The message's screen
 * ---------------------------------------------------------------------
 */

/** The readings a message could go on now, by its choices (whether or not it runs today). */
function rrt_spot_targets( $spot_id ) {
	$out = array();
	foreach ( rrt_spot_candidates() as $p ) {
		if ( rrt_spot_post_choice( $p->ID )['off'] || ! rrt_spot_for_post( $spot_id, $p->ID ) ) {
			continue;
		}
		$info = rrt_spot_reading( $p->ID );
		if ( $info['ok'] ) {
			$out[] = array(
				'post' => $p,
				'info' => $info,
			);
		}
	}
	return $out;
}

/**
 * The copies a message needs, and the readings that must be measured first.
 *
 * @return array needs (need, fmt, lu, label, have, titles), measure (post, url, key, title).
 */
function rrt_spot_needs( $spot_id ) {
	$needs   = array();
	$measure = array();
	foreach ( rrt_spot_targets( $spot_id ) as $t ) {
		$info  = $t['info'];
		$title = html_entity_decode( wp_strip_all_tags( get_the_title( $t['post'] ) ), ENT_QUOTES, 'UTF-8' );
		if ( null === $info['lu'] ) {
			$a         = rrt_reading_audio( $t['post']->ID );
			$measure[] = array(
				'post'  => (int) $t['post']->ID,
				'url'   => $a['url'],
				'key'   => $info['key'],
				'title' => $title,
			);
			continue;
		}
		$need = rrt_spot_need_key( $info['scan'], $info['lu'] );
		if ( ! isset( $needs[ $need ] ) ) {
			$needs[ $need ] = array(
				'need'   => $need,
				'fmt'    => array(
					'kbps' => (int) $info['scan']['kbps'],
					'rate' => (int) $info['scan']['rate'],
					'ch'   => (int) $info['scan']['ch'],
				),
				/* As one channel measures it; a stereo copy is leveled 3 LU lower in each channel. */
				'lu'     => rrt_spot_lu_bucket( $info['lu'] ),
				'label'  => sprintf(
					/* translators: 1: a format such as "128 kbps mono", 2: a loudness such as -19.0. */
					__( '%1$s at %2$s LUFS', 'foxfire-studio' ),
					rrt_col_fmt_label( $info['scan'] ),
					number_format_i18n( rrt_spot_lu_bucket( $info['lu'] ) + ( 2 === (int) $info['scan']['ch'] ? RRT_COL_STEREO_LU : 0 ), 1 )
				),
				'have'   => (bool) rrt_spot_copy_for( $spot_id, $need ),
				'titles' => array(),
			);
		}
		$needs[ $need ]['titles'][] = $title;
	}
	return array(
		'needs'   => array_values( $needs ),
		'measure' => $measure,
	);
}

/** What the copies box on a message's screen needs, for sponsors.js. */
function rrt_spot_screen_boot( $post ) {
	$cfg = rrt_config();
	$n   = rrt_spot_needs( $post->ID );
	$a   = rrt_reading_audio( $post->ID );
	return array(
		'spot'    => (int) $post->ID,
		'rest'    => esc_url_raw( rest_url( 'rrt/v1/' ) ),
		'nonce'   => wp_create_nonce( 'wp_rest' ),
		'audio'   => $a['url'],
		'src'     => rrt_spot_src_key( $post->ID ),
		'needs'   => $n['needs'],
		'measure' => $n['measure'],
		'encoder' => array(
			'worker'   => rrt_js_url( 'mp3.js' ),
			'url'      => $cfg['mp3_url'],
			'fallback' => $cfg['mp3_url'] !== $cfg['mp3_cdn'] ? $cfg['mp3_cdn'] : '',
			'sha256'   => RRT_MP3_SHA256,
		),
	);
}

add_action(
	'add_meta_boxes_' . RRT_SPOT_TYPE,
	function ( $post ) {
		add_meta_box( 'rrt-spot-message', __( 'The message', 'foxfire-studio' ), 'rrt_spot_message_box', RRT_SPOT_TYPE, 'normal', 'high' );
		add_meta_box( 'rrt-spot-copies', __( 'Copies in each reading\'s sound', 'foxfire-studio' ), 'rrt_spot_copies_box', RRT_SPOT_TYPE, 'normal', 'default' );
		add_meta_box( 'rrt-spot-recording', __( 'Recording', 'foxfire-studio' ), 'rrt_spot_recording_box', RRT_SPOT_TYPE, 'side', 'high' );
		add_meta_box( 'rrt-spot-runs', __( 'Where it runs', 'foxfire-studio' ), 'rrt_spot_runs_box', RRT_SPOT_TYPE, 'side', 'default' );
	}
);

/**
 * A message's script as the studio reads it: its first line in the place of
 * a post's title, then the rest. The message's own title is for the list.
 *
 * @return array Blocks, as rrt_blocks_from_html() gives them.
 */
function rrt_spot_studio_blocks( $post ) {
	$lines = preg_split( '/\r\n|\r|\n/', (string) $post->post_content );
	$lines = array_values( array_filter( array_map( 'trim', $lines ), 'strlen' ) );
	if ( ! $lines ) {
		return array();
	}
	$first = array_shift( $lines );
	$html  = $lines ? '<p>' . implode( '</p><p>', array_map( 'esc_html', $lines ) ) . '</p>' : '';
	return rrt_blocks_from_html( $first, $html );
}

/** The default script for a new message. */
function rrt_spot_default_script( $who, $kind ) {
	$who = '' !== trim( (string) $who ) ? $who : __( 'our sponsor', 'foxfire-studio' );
	return 'own' === $kind ? '' : sprintf(
		/* translators: %s: who the message is for. */
		__( 'This reading is supported by %s.', 'foxfire-studio' ),
		$who
	);
}

/** The message's choices. */
function rrt_spot_message_box( $post ) {
	$m      = rrt_spot_get( $post->ID );
	$script = trim( (string) $post->post_content );
	if ( '' === $script && 'auto-draft' === $post->post_status ) {
		$script = rrt_spot_default_script( '', 'paid' );
	}
	wp_nonce_field( 'rrt_spot_save', 'rrt_spot_save_nonce' );
	?>
	<p class="description"><?php esc_html_e( 'The title above is for your list only. Listeners hear the script and see who it is for.', 'foxfire-studio' ); ?></p>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="rrt-spot-who"><?php esc_html_e( 'Who it is for', 'foxfire-studio' ); ?></label></th>
			<td><input class="regular-text" id="rrt-spot-who" name="rrt_spot[who]" value="<?php echo esc_attr( $m['who'] ); ?>" maxlength="120">
			<p class="description"><?php esc_html_e( 'The sponsor\'s or cause\'s name, used in the episode notes and the chapter "A word from...".', 'foxfire-studio' ); ?></p></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Kind', 'foxfire-studio' ); ?></th>
			<td>
				<?php foreach ( rrt_spot_kinds() as $k => $label ) : ?>
					<label style="display:inline-block;margin-right:1.5em"><input type="radio" name="rrt_spot[kind]" value="<?php echo esc_attr( $k ); ?>" <?php checked( $m['kind'], $k ); ?>> <?php echo esc_html( $label ); ?></label>
				<?php endforeach; ?>
				<p class="description"><?php esc_html_e( 'Paid or gifted: anyone who gave money, products or anything else. The law in Canada and the US asks for the message itself to say so, near its start. Your own: your shop, a cause you run, an event or a support link.', 'foxfire-studio' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="rrt-spot-link"><?php esc_html_e( 'Link', 'foxfire-studio' ); ?></label></th>
			<td><input class="large-text code" type="url" id="rrt-spot-link" name="rrt_spot[link]" value="<?php echo esc_attr( $m['link'] ); ?>" placeholder="https://" style="max-width:40em">
			<p class="description"><?php esc_html_e( 'Optional. Shown in the episode notes and the message\'s chapter.', 'foxfire-studio' ); ?></p></td>
		</tr>
		<tr>
			<th scope="row"><label for="rrt-spot-script"><?php esc_html_e( 'Script', 'foxfire-studio' ); ?></label></th>
			<td><textarea class="large-text" rows="6" id="rrt-spot-script" name="rrt_spot_script"><?php echo esc_textarea( $script ); ?></textarea>
			<p class="description"><?php esc_html_e( 'One paragraph a line. The studio reads the first line first. Save, then record it (Recording, on the right).', 'foxfire-studio' ); ?></p></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Dates', 'foxfire-studio' ); ?></th>
			<td><label><?php esc_html_e( 'From', 'foxfire-studio' ); ?> <input type="date" name="rrt_spot[start]" value="<?php echo esc_attr( $m['start'] ); ?>"></label>
			<label style="margin-left:1em"><?php esc_html_e( 'to', 'foxfire-studio' ); ?> <input type="date" name="rrt_spot[end]" value="<?php echo esc_attr( $m['end'] ); ?>"></label>
			<p class="description"><?php esc_html_e( 'Both optional. Each day counts from midnight in the site\'s time zone; changes reach the feed within the hour.', 'foxfire-studio' ); ?></p></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Where it can go', 'foxfire-studio' ); ?></th>
			<td>
				<?php foreach ( rrt_spot_slots() as $k => $label ) : ?>
					<label style="display:inline-block;margin-right:1.5em"><input type="checkbox" name="rrt_spot[slots][]" value="<?php echo esc_attr( $k ); ?>" <?php checked( in_array( $k, $m['slots'], true ) ); ?>> <?php echo esc_html( $label ); ?></label>
				<?php endforeach; ?>
				<p class="description"><?php esc_html_e( 'When two messages want the same place in a reading, the one with the lower Order number (Post Attributes, on the right) wins.', 'foxfire-studio' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Which readings', 'foxfire-studio' ); ?></th>
			<td>
				<p><label><input type="radio" name="rrt_spot[where]" value="all" <?php checked( $m['where'], 'all' ); ?>> <?php esc_html_e( 'Every reading in the feed, older ones too', 'foxfire-studio' ); ?></label></p>
				<p><label><input type="radio" name="rrt_spot[where]" value="cats" <?php checked( $m['where'], 'cats' ); ?>> <?php esc_html_e( 'Readings in these categories:', 'foxfire-studio' ); ?></label></p>
				<div style="max-height:10em;overflow:auto;margin-left:1.6em">
					<?php foreach ( get_categories( array( 'hide_empty' => false ) ) as $cat ) : ?>
						<label style="display:block"><input type="checkbox" name="rrt_spot[cats][]" value="<?php echo (int) $cat->term_id; ?>" <?php checked( in_array( (int) $cat->term_id, $m['cats'], true ) ); ?>> <?php echo esc_html( $cat->name ); ?></label>
					<?php endforeach; ?>
				</div>
				<p><label><input type="radio" name="rrt_spot[where]" value="posts" <?php checked( $m['where'], 'posts' ); ?>> <?php esc_html_e( 'These readings:', 'foxfire-studio' ); ?></label></p>
				<div style="max-height:12em;overflow:auto;margin-left:1.6em">
					<?php foreach ( rrt_spot_candidates() as $p ) : ?>
						<label style="display:block"><input type="checkbox" name="rrt_spot[posts][]" value="<?php echo (int) $p->ID; ?>" <?php checked( in_array( (int) $p->ID, $m['posts'], true ) ); ?>> <?php echo esc_html( wp_strip_all_tags( get_the_title( $p ) ) ); ?></label>
					<?php endforeach; ?>
				</div>
			</td>
		</tr>
	</table>
	<?php
}

/** The copies box; sponsors.js fills it in. */
function rrt_spot_copies_box( $post ) {
	echo '<div data-rrt-spot-copies><p class="description">' . esc_html__( 'Loading...', 'foxfire-studio' ) . '</p></div>';
	echo '<script type="application/json" id="rrt-spot-screen-boot">' . wp_json_encode( rrt_spot_screen_boot( $post ), JSON_HEX_TAG | JSON_HEX_AMP ) . '</script>';
}

/** The recording box: the message's audio, the studio link, and the disclosure check. */
function rrt_spot_recording_box( $post ) {
	$a = rrt_reading_audio( $post->ID );
	if ( 'auto-draft' === $post->post_status ) {
		echo '<p class="description">' . esc_html__( 'Save the message first, then record it in the studio.', 'foxfire-studio' ) . '</p>';
		return;
	}
	if ( '' !== $a['url'] ) {
		$scan = rrt_col_scan_cached( rrt_col_reading_path( $post->ID ) );
		echo '<p><audio controls preload="none" style="width:100%" src="' . esc_url( $a['url'] ) . '"></audio></p>';
		if ( $scan['ok'] ) {
			/* translators: 1: length such as 0:32, 2: format. */
			echo '<p class="description">' . esc_html( sprintf( __( '%1$s, %2$s.', 'foxfire-studio' ), rrt_spot_clock( $scan['secs'] ), rrt_col_fmt_label( $scan ) ) ) . '</p>';
		}
	} else {
		echo '<p class="description">' . esc_html__( 'Not recorded yet.', 'foxfire-studio' ) . '</p>';
	}
	echo '<p><a class="button button-primary" href="' . esc_url( rrt_url( $post->ID ) ) . '" target="_blank" rel="noopener">' . esc_html( '' !== $a['url'] ? __( 'Record it again in the studio', 'foxfire-studio' ) : __( 'Record it in the studio', 'foxfire-studio' ) ) . '</a></p>';
	echo '<p class="description">' . esc_html__( 'In the studio, review the take, then press Use this recording. Reload this screen afterwards.', 'foxfire-studio' ) . '</p>';
	$m = rrt_spot_get( $post->ID );
	if ( 'paid' === $m['kind'] && '' !== $a['url'] ) {
		$d = rrt_spot_disclosed( $post->ID );
		if ( true === $d ) {
			/* translators: %d: seconds. */
			echo '<p class="rrt-spot-ok">' . esc_html( sprintf( __( 'Disclosure heard in the first %d seconds.', 'foxfire-studio' ), RRT_SPOT_LEAD ) ) . '</p>';
		} elseif ( false === $d ) {
			/* translators: %d: seconds. */
			echo '<p class="notice notice-warning inline" style="padding:.5em">' . esc_html( sprintf( __( 'No word like "sponsored", "supported by", "paid" or "partner" was heard in the first %d seconds. A paid or gifted message should say so near its start. This is a reminder only; the words are yours to choose.', 'foxfire-studio' ), RRT_SPOT_LEAD ) ) . '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'The disclosure check needs the take\'s captions: review it with Whisper before Use this recording.', 'foxfire-studio' ) . '</p>';
		}
	}
}

/** The box with where a message runs now and where it has run. */
function rrt_spot_runs_box( $post ) {
	$why  = rrt_spot_runs( $post->ID );
	$m    = rrt_spot_get( $post->ID );
	$text = array(
		'unpublished' => __( 'Not running: publish the message to start it.', 'foxfire-studio' ),
		/* translators: %s: a date. */
		'early'       => sprintf( __( 'Starts %s.', 'foxfire-studio' ), '' !== $m['start'] ? wp_date( get_option( 'date_format' ), strtotime( $m['start'] . ' 12:00:00' ) ) : '' ),
		'over'        => __( 'Its dates are over.', 'foxfire-studio' ),
		'silent'      => __( 'Not running: record it first.', 'foxfire-studio' ),
		''            => __( 'Running.', 'foxfire-studio' ),
	);
	if ( ! rrt_spot_on() ) {
		echo '<p class="notice notice-warning inline" style="padding:.5em">' . esc_html__( 'Sponsor messages are switched off: Foxfire Studio, Podcast feed, Sponsor messages.', 'foxfire-studio' ) . '</p>';
	}
	echo '<p>' . esc_html( $text[ $why ] ) . '</p>';
	$now = array();
	foreach ( rrt_spot_with_copies() as $id ) {
		$b = rrt_spot_built( $id );
		foreach ( $b ? (array) $b['parts'] : array() as $p ) {
			if ( (int) $p['spot'] === (int) $post->ID ) {
				$now[] = wp_strip_all_tags( get_the_title( $id ) ) . ' (' . strtolower( rrt_spot_slots()[ $p['slot'] ] ) . ')';
			}
		}
	}
	if ( $now ) {
		echo '<p><strong>' . esc_html__( 'In these episodes now:', 'foxfire-studio' ) . '</strong></p><ul style="list-style:disc;margin-left:1.5em">';
		foreach ( $now as $n ) {
			echo '<li>' . esc_html( $n ) . '</li>';
		}
		echo '</ul>';
	}
	$rows = rrt_spot_report( $post->ID );
	if ( $rows ) {
		echo '<p><strong>' . esc_html__( 'Where it has run', 'foxfire-studio' ) . '</strong></p><ul style="list-style:disc;margin-left:1.5em">';
		foreach ( $rows as $r ) {
			$line = sprintf(
				/* translators: 1: a reading's title, 2: Pre-roll, Mid-roll or Post-roll, 3: from date, 4: to date or "now". */
				__( '%1$s, %2$s, %3$s to %4$s', 'foxfire-studio' ),
				$r['title'],
				strtolower( $r['slot'] ),
				$r['from'],
				$r['to']
			);
			if ( null !== $r['downloads'] ) {
				/* translators: %s: number of downloads. */
				$line .= ': ' . sprintf( _n( '%s download', '%s downloads', $r['downloads'], 'foxfire-studio' ), number_format_i18n( $r['downloads'] ) );
			}
			if ( isset( $r['delivered'] ) && null !== $r['delivered'] ) {
				/* translators: %s: number of times the message was delivered. */
				$line .= ', ' . sprintf( __( 'delivered %s', 'foxfire-studio' ), number_format_i18n( $r['delivered'] ) );
			}
			echo '<li>' . esc_html( $line ) . '</li>';
		}
		echo '</ul>';
		if ( ! ( function_exists( 'rrt_dl_on' ) && rrt_dl_on() ) ) {
			echo '<p class="description">' . esc_html__( 'Downloads are shown when Count downloads is on (Podcast feed).', 'foxfire-studio' ) . '</p>';
		}
	}
}

/**
 * A message's log for its screen: each reading, slot and dates, with the
 * episode's downloads in those days when downloads are counted.
 *
 * @return array[] title, slot, from, to, downloads and delivered (1.24.0; each null when not counted).
 */
function rrt_spot_report( $spot_id ) {
	$log = get_post_meta( $spot_id, RRT_SPOT_LOG, true );
	$out = array();
	$dl  = function_exists( 'rrt_dl_on' ) && rrt_dl_on() && function_exists( 'rrt_dl_stats' );
	$fmt = get_option( 'date_format' );
	foreach ( array_reverse( is_array( $log ) ? $log : array() ) as $e ) {
		$to  = ! empty( $e['to'] ) ? (int) $e['to'] : time();
		$n   = null;
		$got = null;
		if ( $dl ) {
			$st = rrt_dl_stats( wp_date( 'Y-m-d', (int) $e['from'] ), wp_date( 'Y-m-d', $to ) );
			$n  = isset( $st['posts'][ (int) $e['post'] ] ) ? (int) $st['posts'][ (int) $e['post'] ] : 0;
			/* Delivered (1.24.0): downloads that reached this message's place in the file. */
			if ( function_exists( 'rrt_dl_ads_for' ) ) {
				$got = 0;
				foreach ( rrt_dl_ads_for( $spot_id, wp_date( 'Y-m-d', (int) $e['from'] ), wp_date( 'Y-m-d', $to ) ) as $a ) {
					if ( (int) $a['post_id'] === (int) $e['post'] && (string) $a['slot'] === (string) $e['slot'] ) {
						$got += (int) $a['n'];
					}
				}
			}
		}
		$slots = rrt_spot_slots();
		$out[] = array(
			'title'     => wp_strip_all_tags( get_the_title( (int) $e['post'] ) ),
			'slot'      => isset( $slots[ $e['slot'] ] ) ? $slots[ $e['slot'] ] : $e['slot'],
			'from'      => wp_date( $fmt, (int) $e['from'] ),
			'to'        => ! empty( $e['to'] ) ? wp_date( $fmt, (int) $e['to'] ) : __( 'now', 'foxfire-studio' ),
			'downloads' => $n,
			'delivered' => $got,
		);
		if ( count( $out ) >= 50 ) {
			break;
		}
	}
	return $out;
}

/* The script is the message's text, so the studio reads it. */
add_filter(
	'wp_insert_post_data',
	function ( $data, $raw ) {
		if ( RRT_SPOT_TYPE !== $data['post_type'] || ! isset( $_POST['rrt_spot_save_nonce'], $_POST['rrt_spot_script'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return $data;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rrt_spot_save_nonce'] ) ), 'rrt_spot_save' ) ) {
			return $data;
		}
		$lines = preg_split( '/\r\n|\r|\n/', (string) wp_unslash( $_POST['rrt_spot_script'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned on this line (esc_url_raw, or each line checked below).
		$lines = array_values( array_filter( array_map( 'trim', array_map( 'sanitize_text_field', $lines ) ), 'strlen' ) );
		$data['post_content'] = wp_slash( mb_substr( implode( "\n\n", $lines ), 0, 4000 ) );
		return $data;
	},
	10,
	2
);

/* Saves the message's choices. */
add_action(
	'save_post_' . RRT_SPOT_TYPE,
	function ( $post_id ) {
		if ( ! isset( $_POST['rrt_spot_save_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rrt_spot_save_nonce'] ) ), 'rrt_spot_save' ) ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		$in = isset( $_POST['rrt_spot'] ) && is_array( $_POST['rrt_spot'] ) ? wp_unslash( $_POST['rrt_spot'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		update_post_meta( $post_id, RRT_SPOT_META, rrt_spot_clean( $in ) );
		rrt_spot_queue( 0 );
	}
);

/** A message's choices from a form, made safe. */
function rrt_spot_clean( $in ) {
	$in = is_array( $in ) ? $in : array();
	return array(
		'who'   => isset( $in['who'] ) ? mb_substr( sanitize_text_field( $in['who'] ), 0, 120 ) : '',
		'kind'  => isset( $in['kind'] ) && 'own' === $in['kind'] ? 'own' : 'paid',
		'link'  => isset( $in['link'] ) ? esc_url_raw( trim( (string) $in['link'] ) ) : '',
		'start' => isset( $in['start'] ) ? rrt_spot_clean_date( $in['start'] ) : '',
		'end'   => isset( $in['end'] ) ? rrt_spot_clean_date( $in['end'] ) : '',
		'slots' => isset( $in['slots'] ) ? array_values( array_intersect( array_keys( rrt_spot_slots() ), array_map( 'sanitize_key', (array) $in['slots'] ) ) ) : array(),
		'where' => isset( $in['where'] ) && in_array( $in['where'], array( 'all', 'cats', 'posts' ), true ) ? $in['where'] : 'all',
		'cats'  => isset( $in['cats'] ) ? array_values( array_filter( array_map( 'absint', (array) $in['cats'] ) ) ) : array(),
		'posts' => isset( $in['posts'] ) ? array_values( array_filter( array_map( 'absint', (array) $in['posts'] ) ) ) : array(),
	);
}

/* The list's columns. */
add_filter(
	'manage_' . RRT_SPOT_TYPE . '_posts_columns',
	function ( $cols ) {
		$out = array();
		foreach ( $cols as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'title' === $k ) {
				$out['rrt_who']   = __( 'Who it is for', 'foxfire-studio' );
				$out['rrt_kind']  = __( 'Kind', 'foxfire-studio' );
				$out['rrt_dates'] = __( 'Dates', 'foxfire-studio' );
				$out['rrt_state'] = __( 'Now', 'foxfire-studio' );
				$out['rrt_order'] = __( 'Order', 'foxfire-studio' );
			}
		}
		unset( $out['date'] );
		return $out;
	}
);

add_action(
	'manage_' . RRT_SPOT_TYPE . '_posts_custom_column',
	function ( $col, $post_id ) {
		$m = rrt_spot_get( $post_id );
		if ( 'rrt_who' === $col ) {
			echo esc_html( $m['who'] );
		} elseif ( 'rrt_kind' === $col ) {
			echo esc_html( rrt_spot_kinds()[ $m['kind'] ] );
		} elseif ( 'rrt_dates' === $col ) {
			$fmt = get_option( 'date_format' );
			$a   = '' !== $m['start'] ? wp_date( $fmt, strtotime( $m['start'] . ' 12:00:00' ) ) : '';
			$b   = '' !== $m['end'] ? wp_date( $fmt, strtotime( $m['end'] . ' 12:00:00' ) ) : '';
			/* translators: 1: from date, 2: to date. */
			echo esc_html( '' === $a && '' === $b ? __( 'Any day', 'foxfire-studio' ) : sprintf( __( '%1$s to %2$s', 'foxfire-studio' ), '' !== $a ? $a : '...', '' !== $b ? $b : '...' ) );
		} elseif ( 'rrt_state' === $col ) {
			$why  = rrt_spot_runs( $post_id );
			$need = rrt_spot_needs( $post_id );
			$wait = count(
				array_filter(
					$need['needs'],
					function ( $n ) {
						return ! $n['have'];
					}
				)
			) + count( $need['measure'] );
			$map  = array(
				''            => __( 'Running', 'foxfire-studio' ),
				'unpublished' => __( 'Draft', 'foxfire-studio' ),
				'early'       => __( 'Not started', 'foxfire-studio' ),
				'over'        => __( 'Over', 'foxfire-studio' ),
				'silent'      => __( 'Not recorded', 'foxfire-studio' ),
			);
			echo esc_html( $map[ $why ] );
			if ( $wait && '' === $why ) {
				/* translators: %d: number of copies. */
				echo '<br><span class="description">' . esc_html( sprintf( _n( '%d copy still to make', '%d copies still to make', $wait, 'foxfire-studio' ), $wait ) ) . '</span>';
			}
		} elseif ( 'rrt_order' === $col ) {
			echo (int) get_post_field( 'menu_order', $post_id );
		}
	},
	10,
	2
);

/* The list in its Order. */
add_action(
	'pre_get_posts',
	function ( $q ) {
		if ( is_admin() && $q->is_main_query() && RRT_SPOT_TYPE === $q->get( 'post_type' ) && ! $q->get( 'orderby' ) ) {
			$q->set(
				'orderby',
				array(
					'menu_order' => 'ASC',
					'date'       => 'ASC',
				)
			);
		}
	}
);

/* The scripts on a message's screen and a reading's screen. */
add_action(
	'admin_enqueue_scripts',
	function ( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$s = get_current_screen();
		if ( ! $s ) {
			return;
		}
		$cfg  = rrt_config();
		$spot = RRT_SPOT_TYPE === $s->post_type;
		if ( ! $spot && ( ! rrt_spot_on() || ! ( in_array( $s->post_type, (array) $cfg['post_types'], true ) || ( defined( 'RRT_COL_TYPE' ) && RRT_COL_TYPE === $s->post_type ) ) ) ) {
			return;
		}
		$ver = function ( $rel ) {
			return RRT_VERSION . '.' . filemtime( RRT_DIR . $rel );
		};
		$deps = array( 'wp-i18n' );
		if ( $spot ) {
			wp_enqueue_script( 'rrt-sound', RRT_URL . 'assets/js/sound.js', array( 'wp-i18n' ), $ver( 'assets/js/sound.js' ), true );
			wp_set_script_translations( 'rrt-sound', 'foxfire-studio', rrt_languages_dir() );
			$deps[] = 'rrt-sound';
		}
		wp_enqueue_script( 'rrt-sponsors', RRT_URL . 'assets/js/sponsors.js', $deps, $ver( 'assets/js/sponsors.js' ), true );
		wp_add_inline_style( 'common', '.rrt-spot-needs{list-style:disc;margin-left:1.5em}.rrt-spot-needs .is-done strong{color:#1a7f37}.rrt-spot-note.is-bad{color:#b32d2e}.rrt-spot-ok{color:#1a7f37}' );
		wp_set_script_translations( 'rrt-sponsors', 'foxfire-studio', rrt_languages_dir() );
	}
);

/* The Sponsor messages menu item, under Collections. */
add_action(
	'admin_menu',
	function () {
		add_submenu_page( 'rrt-dashboard', __( 'Sponsor messages', 'foxfire-studio' ), __( 'Sponsor messages', 'foxfire-studio' ), 'edit_rrt_spots', 'edit.php?post_type=' . RRT_SPOT_TYPE );
	},
	20
);

/*
 * ---------------------------------------------------------------------
 * Routes for the browser
 * ---------------------------------------------------------------------
 */

add_action(
	'rest_api_init',
	function () {
		$can  = function () {
			return is_user_logged_in() && current_user_can( 'edit_posts' );
		};
		register_rest_route(
			'rrt/v1',
			'/spots/(?P<id>\d+)/needs',
			array(
				'methods'             => 'GET',
				'permission_callback' => $can,
				'callback'            => function ( $req ) {
					$id = absint( $req['id'] );
					if ( ! rrt_spot_is( $id ) || ! current_user_can( 'edit_post', $id ) ) {
						return new WP_Error( 'rrt_spot', __( 'That message cannot be changed here.', 'foxfire-studio' ), array( 'status' => 403 ) );
					}
					$n        = rrt_spot_needs( $id );
					$n['src'] = rrt_spot_src_key( $id );
					return rest_ensure_response( $n );
				},
			)
		);
		register_rest_route(
			'rrt/v1',
			'/spots/(?P<id>\d+)/copy',
			array(
				'methods'             => 'POST',
				'permission_callback' => function () use ( $can ) {
					return $can() && current_user_can( 'upload_files' );
				},
				'callback'            => 'rrt_spot_receive_copy',
			)
		);
		register_rest_route(
			'rrt/v1',
			'/spots/pauses',
			array(
				'methods'             => 'POST',
				'permission_callback' => $can,
				'callback'            => function ( $req ) {
					$post = absint( $req['post'] );
					if ( ! $post || ! current_user_can( 'edit_post', $post ) ) {
						return new WP_Error( 'rrt_spot', __( 'That post cannot be changed here.', 'foxfire-studio' ), array( 'status' => 403 ) );
					}
					$key = rrt_col_file_key( rrt_col_reading_path( $post ) );
					if ( '' === $key || (string) $req['key'] !== $key ) {
						return new WP_Error( 'rrt_spot', __( 'The reading changed while its pauses were being found. Reload the page.', 'foxfire-studio' ), array( 'status' => 409 ) );
					}
					$list = rrt_spot_clean_pauses( is_array( $req['pauses'] ) ? $req['pauses'] : array() );
					update_post_meta(
						$post,
						RRT_SPOT_PAUSES,
						array(
							'key'  => $key,
							'list' => $list,
							'at'   => time(),
						)
					);
					$info = rrt_spot_reading( $post );
					$secs = $info['ok'] ? $info['scan']['secs'] : 0;
					return rest_ensure_response(
						array(
							'ok'      => true,
							'count'   => count( $list ),
							'suggest' => rrt_spot_pause_suggest( $list, $secs ),
						)
					);
				},
			)
		);
	}
);

/**
 * A copy of a message arriving from the browser, in one reading format and
 * loudness. Kept as an attachment of the message; an older copy for the same
 * need goes.
 */
function rrt_spot_receive_copy( $req ) {
	$id = absint( $req['id'] );
	if ( ! rrt_spot_is( $id ) || ! current_user_can( 'edit_post', $id ) ) {
		return new WP_Error( 'rrt_spot', __( 'That message cannot be changed here.', 'foxfire-studio' ), array( 'status' => 403 ) );
	}
	$src = rrt_spot_src_key( $id );
	if ( '' === $src || (string) $req['src'] !== $src ) {
		return new WP_Error( 'rrt_spot', __( 'The message was recorded again while its copy was being made. Reload the page and try again.', 'foxfire-studio' ), array( 'status' => 409 ) );
	}
	$need  = (string) $req['need'];
	$found = null;
	foreach ( rrt_spot_needs( $id )['needs'] as $n ) {
		if ( $n['need'] === $need ) {
			$found = $n;
		}
	}
	if ( ! $found ) {
		return new WP_Error( 'rrt_spot', __( 'No reading needs that copy now. Reload the page.', 'foxfire-studio' ), array( 'status' => 400 ) );
	}
	$files = $req->get_file_params();
	if ( empty( $files['mp3'] ) || ! empty( $files['mp3']['error'] ) ) {
		return new WP_Error( 'rrt_spot', __( 'No file arrived. Try again.', 'foxfire-studio' ), array( 'status' => 400 ) );
	}
	$scan = rrt_col_scan( $files['mp3']['tmp_name'] );
	if ( ! $scan['ok'] || rrt_col_fmt_key( $scan ) !== rrt_col_fmt_key( $found['fmt'] ) ) {
		return new WP_Error( 'rrt_spot', __( 'The copy is not in the reading\'s format.', 'foxfire-studio' ), array( 'status' => 400 ) );
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$title     = rrt_spot_who( $id );
	$f         = $files['mp3'];
	$f['name'] = sanitize_file_name( sanitize_title( $title ) . '-message-' . str_replace( array( '|', '.' ), '-', $need ) ) . '.mp3';
	$f['type'] = 'audio/mpeg';
	/* translators: %s: who the message is for. */
	$att = media_handle_sideload( $f, $id, sprintf( __( '%s (sponsor message, matched)', 'foxfire-studio' ), $title ) );
	if ( is_wp_error( $att ) ) {
		return new WP_Error( 'rrt_spot', $att->get_error_message(), array( 'status' => 500 ) );
	}
	update_post_meta(
		$att,
		RRT_SPOT_COPY,
		array(
			'need' => $need,
			'src'  => $src,
			'lu'   => is_numeric( $req['lufs'] ) ? round( (float) $req['lufs'], 2 ) : null,
		)
	);
	foreach ( rrt_spot_copies( $id ) as $c ) {
		if ( $c['att'] !== (int) $att && ( $c['need'] === $need || $c['src'] !== $src ) ) {
			wp_delete_attachment( $c['att'], true );
		}
	}
	rrt_spot_queue( 0 );
	return rest_ensure_response(
		array(
			'ok'    => true,
			'needs' => rrt_spot_needs( $id )['needs'],
		)
	);
}

/*
 * ---------------------------------------------------------------------
 * Settings rows on the Podcast feed screen
 * ---------------------------------------------------------------------
 */

/** Prints the Sponsor messages section of the Podcast feed screen. */
function rrt_spot_settings_rows() {
	$m = rrt_spot_settings();
	?>
	<h2 id="rrt-sponsors"><?php esc_html_e( 'Sponsor messages', 'foxfire-studio' ); ?></h2>
	<p><?php
	echo wp_kses(
		sprintf(
			/* translators: %s: link to the Sponsor messages screen. */
			__( 'Short messages placed before, in the middle of and after readings in the podcast feed, from your %s. The post page\'s player keeps the reading as it is. Every listener gets the same file, and nothing about listeners is used.', 'foxfire-studio' ),
			'<a href="' . esc_url( admin_url( 'edit.php?post_type=' . RRT_SPOT_TYPE ) ) . '">' . esc_html__( 'Sponsor messages', 'foxfire-studio' ) . '</a>'
		),
		array( 'a' => array( 'href' => true ) )
	);
	?></p>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Sponsor messages', 'foxfire-studio' ); ?></th>
			<td><label><input type="checkbox" name="s_on" value="1" <?php checked( $m['on'] ); ?>> <?php esc_html_e( 'Place sponsor messages in the feed', 'foxfire-studio' ); ?></label></td>
		</tr>
		<tr>
			<th scope="row"><label for="rrt-s-min"><?php esc_html_e( 'Mid-rolls', 'foxfire-studio' ); ?></label></th>
			<td><?php
			echo wp_kses(
				sprintf(
					/* translators: %s: a number field. */
					__( 'Only in readings of %s minutes or more.', 'foxfire-studio' ),
					'<input type="number" class="small-text" id="rrt-s-min" name="s_mid_min" min="1" max="180" value="' . (int) $m['mid_min'] . '">'
				),
				array(
					'input' => array(
						'type'  => true,
						'class' => true,
						'id'    => true,
						'name'  => true,
						'min'   => true,
						'max'   => true,
						'value' => true,
					),
				)
			);
			?>
			<p><?php esc_html_e( 'Mid-rolls in:', 'foxfire-studio' ); ?>
			<?php foreach ( rrt_kinds() as $k => $label ) : ?>
				<label style="display:inline-block;margin-left:1em"><input type="checkbox" name="s_kinds[]" value="<?php echo esc_attr( $k ); ?>" <?php checked( in_array( $k, $m['kinds'], true ) ); ?>> <?php echo esc_html( $label ); ?></label>
			<?php endforeach; ?></p>
			<p class="description"><?php esc_html_e( 'A reading with no kind counts as prose. A reading with the music bed never takes a mid-roll, since the music would stop.', 'foxfire-studio' ); ?></p></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Chapters', 'foxfire-studio' ); ?></th>
			<td><label><input type="checkbox" name="s_chapters" value="1" <?php checked( $m['chapters'] ); ?>> <?php esc_html_e( 'Give each message a chapter ("A word from...") so listeners can see it and skip it', 'foxfire-studio' ); ?></label></td>
		</tr>
		<tr>
			<th scope="row"><label for="rrt-s-paid"><?php esc_html_e( 'Episode notes', 'foxfire-studio' ); ?></label></th>
			<td><p><label><?php esc_html_e( 'Paid or gifted:', 'foxfire-studio' ); ?> <input class="regular-text" id="rrt-s-paid" name="s_line_paid" value="<?php echo esc_attr( $m['line_paid'] ); ?>" placeholder="<?php echo esc_attr__( 'Sponsored by %s', 'foxfire-studio' ); ?>"></label></p>
			<p><label><?php esc_html_e( 'Your own:', 'foxfire-studio' ); ?> <input class="regular-text" name="s_line_own" value="<?php echo esc_attr( $m['line_own'] ); ?>" placeholder="<?php echo esc_attr__( 'Supported by %s', 'foxfire-studio' ); ?>"></label></p>
			<p class="description"><?php /* translators: %s is shown as it is: it marks where the name goes in the lines above. */ esc_html_e( 'The line at the start of an episode\'s notes. %s is the name; the link follows it.', 'foxfire-studio' ); ?></p></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'On the post', 'foxfire-studio' ); ?></th>
			<td><label><input type="checkbox" name="s_thank" value="1" <?php checked( $m['thank'] ); ?>> <?php esc_html_e( 'Thank sponsors under the player', 'foxfire-studio' ); ?></label>
			<p class="description"><?php esc_html_e( 'A short line naming who supports the reading\'s podcast episode. Paid links are marked as sponsored for search engines.', 'foxfire-studio' ); ?></p></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Member feeds', 'foxfire-studio' ); ?></th>
			<td><label><input type="checkbox" name="s_members" value="1" <?php checked( $m['members'] ); ?>> <?php esc_html_e( 'Members hear sponsor messages too', 'foxfire-studio' ); ?></label>
			<p class="description"><?php esc_html_e( 'Off: member feeds carry the readings without messages. Readings for members never carry messages.', 'foxfire-studio' ); ?></p></td>
		</tr>
	</table>
	<?php
}

/** Reads the sponsor settings from the Podcast feed form into the settings array. */
function rrt_spot_settings_from_post( $s ) {
	// phpcs:disable WordPress.Security.NonceVerification -- checked by the Podcast feed form's handler.
	$s['s_on']       = ! empty( $_POST['s_on'] );
	$s['s_mid_min']  = isset( $_POST['s_mid_min'] ) ? max( 1, min( 180, absint( $_POST['s_mid_min'] ) ) ) : 10;
	$kinds           = isset( $_POST['s_kinds'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['s_kinds'] ) ) : array();
	$s['s_kinds']    = array_values( array_intersect( array_keys( rrt_kinds() ), $kinds ) );
	$s['s_chapters'] = ! empty( $_POST['s_chapters'] );
	$s['s_thank']    = ! empty( $_POST['s_thank'] );
	$s['s_members']  = ! empty( $_POST['s_members'] );
	$s['s_line_paid'] = isset( $_POST['s_line_paid'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['s_line_paid'] ) ), 0, 120 ) : '';
	$s['s_line_own']  = isset( $_POST['s_line_own'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['s_line_own'] ) ), 0, 120 ) : '';
	// phpcs:enable
	rrt_spot_queue( 0 );
	return $s;
}
