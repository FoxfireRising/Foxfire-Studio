<?php
/**
 * Reader stats on the server (0.9.0).
 *
 * Every take is kept on the site, for every reader, so progress can be seen
 * over weeks and across computers. Three kinds of record:
 *
 * - Takes (table rrt_takes): one row per take, with the numbers the review
 *   and read-out measured and the full summary and flag list as JSON.
 * - Recordings (table rrt_recordings): one row per MP3 that became a post's
 *   reading, whether uploaded with "Use this recording", chosen by hand in the
 *   Reading audio box, or already there before 0.9.0.
 * - Taught words: what each reader has taught Whisper, kept in their own user
 *   data so teaching once works on every computer.
 *
 * Recordings themselves never leave the reader's computer unless they press
 * "Use this recording". Everything here is for logged-in readers who can edit
 * posts, about themselves; visitors are not involved.
 *
 * With server storage switched off in Settings, the teleprompter keeps its
 * history in each browser, as before 0.9.0.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RRT_DB_VERSION', '8' ); // 2: listener analytics tables (0.10.0). 3: change markers, skipped stretches in the daily summaries (0.11.0). 4: report archive (0.12.0). 5: opening drill and practice focus on takes (0.14.0). 6: shared takes and their comments (0.17.0). 7: read or listen, time to press play, returning listeners and podcast downloads (1.2.0). 8: downloads counted to the IAB guidelines v2.3: the bytes each consumer asked for, counts by device and feed, podcast consumers per day, sponsor messages delivered (1.24.0).

/** Table names with the site's prefix. */
function rrt_table( $name ) {
	global $wpdb;
	return $wpdb->prefix . 'rrt_' . $name;
}

/** Creates or updates the tables. Safe to run more than once. */
function rrt_stats_install() {
	/* The setup wizard (1.29.0) looks at the site before anything here creates options. */
	if ( function_exists( 'rrt_setup_migrate' ) ) {
		rrt_setup_migrate();
	}
	if ( function_exists( 'rrt_listen_default_migrate' ) ) {
		rrt_listen_default_migrate();
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$c     = $wpdb->get_charset_collate();
	$takes = rrt_table( 'takes' );
	$recs  = rrt_table( 'recordings' );
	dbDelta(
		"CREATE TABLE $takes (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		user_id bigint(20) unsigned NOT NULL,
		post_id bigint(20) unsigned NOT NULL,
		at_ms bigint(20) unsigned NOT NULL,
		created_at datetime NOT NULL,
		mode varchar(10) NOT NULL DEFAULT '',
		set_wpm smallint(5) unsigned DEFAULT NULL,
		duration_s float DEFAULT NULL,
		words_read int(10) unsigned DEFAULT NULL,
		wpm smallint(5) unsigned DEFAULT NULL,
		accuracy float DEFAULT NULL,
		skipped int(10) unsigned DEFAULT NULL,
		misread int(10) unsigned DEFAULT NULL,
		checks int(10) unsigned DEFAULT NULL,
		added int(10) unsigned DEFAULT NULL,
		repeated int(10) unsigned DEFAULT NULL,
		fillers int(10) unsigned DEFAULT NULL,
		long_pauses int(10) unsigned DEFAULT NULL,
		retake_sentences int(10) unsigned DEFAULT NULL,
		rest_share float DEFAULT NULL,
		pause_trend float DEFAULT NULL,
		ending_drop_db float DEFAULT NULL,
		pitch_spread float DEFAULT NULL,
		energy_db float DEFAULT NULL,
		room_noise_db float DEFAULT NULL,
		clicks_found int(10) unsigned DEFAULT NULL,
		whistle_share float DEFAULT NULL,
		cleanup_on tinyint(1) DEFAULT NULL,
		whisper_on tinyint(1) DEFAULT NULL,
		whisper_model varchar(100) NOT NULL DEFAULT '',
		drill varchar(10) NOT NULL DEFAULT '',
		drill_s smallint(5) unsigned DEFAULT NULL,
		focus varchar(12) NOT NULL DEFAULT '',
		became_recording_id bigint(20) unsigned DEFAULT NULL,
		summary longtext,
		flags longtext,
		PRIMARY KEY  (id),
		UNIQUE KEY user_post_at (user_id,post_id,at_ms),
		KEY post_id (post_id),
		KEY created_at (created_at)
		) $c;"
	);
	dbDelta(
		"CREATE TABLE $recs (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		post_id bigint(20) unsigned NOT NULL,
		attachment_id bigint(20) unsigned DEFAULT NULL,
		file_name varchar(255) NOT NULL DEFAULT '',
		url text,
		duration_s float DEFAULT NULL,
		size_bytes bigint(20) unsigned DEFAULT NULL,
		bitrate_kbps smallint(5) unsigned DEFAULT NULL,
		tempo_wpm smallint(5) unsigned DEFAULT NULL,
		take_id bigint(20) unsigned DEFAULT NULL,
		user_id bigint(20) unsigned DEFAULT NULL,
		edited tinyint(1) NOT NULL DEFAULT 0,
		source varchar(20) NOT NULL DEFAULT '',
		created_at datetime NOT NULL,
		published_at datetime DEFAULT NULL,
		is_current tinyint(1) NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		KEY post_id (post_id),
		KEY attachment_id (attachment_id)
		) $c;"
	);
	if ( function_exists( 'rrt_listen_install' ) ) {
		rrt_listen_install();
	}
	if ( function_exists( 'rrt_report_install' ) ) {
		rrt_report_install();
	}
	if ( function_exists( 'rrt_share_install' ) ) {
		rrt_share_install();
	}
	if ( function_exists( 'rrt_dl_install' ) ) {
		rrt_dl_install();
	}
	update_option( 'rrt_db_version', RRT_DB_VERSION, false );
}
register_activation_hook( RRT_FILE, 'rrt_stats_install' );
/* Plugin updates by upload do not run the activation hook, so check on every admin and REST load. */
add_action(
	'plugins_loaded',
	function () {
		if ( get_option( 'rrt_db_version' ) !== RRT_DB_VERSION ) {
			rrt_stats_install();
		}
	}
);

/** Whether server storage is on. */
function rrt_stats_on() {
	$s = rrt_settings();
	return ! empty( $s['stats']['server'] );
}

/** Who may keep stats: logged-in readers who can edit posts. */
function rrt_stats_can() {
	return is_user_logged_in() && current_user_can( 'edit_posts' ) && rrt_stats_on();
}

/* The numbers kept as their own columns, from the take summary the teleprompter sends. */
function rrt_take_columns( $s ) {
	$num = function ( $k ) use ( $s ) {
		return ( isset( $s[ $k ] ) && is_numeric( $s[ $k ] ) ) ? 0 + $s[ $k ] : null;
	};
	$int = function ( $k ) use ( $num ) {
		$v = $num( $k );
		return null === $v ? null : max( 0, (int) round( $v ) );
	};
	$bool = function ( $k ) use ( $s ) {
		return isset( $s[ $k ] ) ? ( $s[ $k ] ? 1 : 0 ) : null;
	};
	return array(
		'mode'             => isset( $s['mode'] ) ? substr( sanitize_key( $s['mode'] ), 0, 10 ) : '',
		'set_wpm'          => $int( 'setWpm' ),
		'duration_s'       => $num( 'secs' ),
		'words_read'       => $int( 'words' ),
		'wpm'              => $int( 'wpm' ),
		'accuracy'         => $num( 'acc' ),
		'skipped'          => $int( 'omit' ),
		'misread'          => $int( 'sub' ),
		'checks'           => $int( 'check' ),
		'added'            => $int( 'added' ),
		'repeated'         => $int( 'repeat' ),
		'fillers'          => $int( 'filler' ),
		'long_pauses'      => $int( 'pauses' ),
		'retake_sentences' => $int( 'retake' ),
		'rest_share'       => $num( 'rest' ),
		'pause_trend'      => $num( 'pauseTrend' ),
		'ending_drop_db'   => $num( 'endDrop' ),
		'pitch_spread'     => $num( 'pitch' ),
		'energy_db'        => $num( 'energy' ),
		'room_noise_db'    => $num( 'snr' ),
		'clicks_found'     => $int( 'clicks' ),
		'whistle_share'    => $num( 'whistle' ),
		'cleanup_on'       => $bool( 'clean' ),
		'whisper_on'       => $bool( 'whisper' ),
		'whisper_model'    => isset( $s['model'] ) ? substr( sanitize_text_field( $s['model'] ), 0, 100 ) : '',
		/* Practice (0.14.0): an opening drill and its length, and the habit being worked on. */
		'drill'            => isset( $s['drill'] ) && 'opening' === $s['drill'] ? 'opening' : '',
		'drill_s'          => isset( $s['drill'] ) && 'opening' === $s['drill'] ? $int( 'drillSecs' ) : null,
		'focus'            => isset( $s['focus'] ) && in_array( $s['focus'], array( 'pace', 'endings', 'fillers', 'pauses', 'pitch', 'energy', 'accuracy' ), true ) ? $s['focus'] : '',
	);
}

/** Saves one take for the current user; a take already saved (same post and moment) is updated. Returns its id. */
function rrt_save_take( $post_id, $summary, $flags ) {
	global $wpdb;
	$user = get_current_user_id();
	$at   = isset( $summary['at'] ) ? (int) $summary['at'] : 0;
	if ( $at <= 0 ) {
		$at            = (int) round( microtime( true ) * 1000 );
		$summary['at'] = $at;
	}
	$row = array_merge(
		rrt_take_columns( $summary ),
		array(
			'user_id'    => $user,
			'post_id'    => $post_id,
			'at_ms'      => $at,
			'created_at' => gmdate( 'Y-m-d H:i:s', (int) floor( $at / 1000 ) ),
			'summary'    => wp_json_encode( $summary ),
			'flags'      => null === $flags ? null : wp_json_encode( $flags ),
		)
	);
	$table = rrt_table( 'takes' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE user_id = %d AND post_id = %d AND at_ms = %d", $user, $post_id, $at ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the only variable is the plugin's own table name.
	$row = array_filter( $row, function ( $v ) { return null !== $v; } );
	if ( $id ) {
		if ( ! isset( $row['flags'] ) ) {
			unset( $row['flags'] );
		}
		$wpdb->update( $table, $row, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $id;
	}
	$wpdb->insert( $table, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return (int) $wpdb->insert_id;
}

/** The current user's takes of a post, oldest first, as the summaries the teleprompter saved (with their ids). */
function rrt_get_takes( $post_id, $limit = 50 ) {
	global $wpdb;
	$table = rrt_table( 'takes' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, summary, became_recording_id FROM $table WHERE user_id = %d AND post_id = %d ORDER BY at_ms DESC LIMIT %d", get_current_user_id(), $post_id, $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the only variable is the plugin's own table name.
	$out  = array();
	foreach ( array_reverse( $rows ) as $r ) {
		$s = json_decode( (string) $r->summary, true );
		if ( ! is_array( $s ) ) {
			continue;
		}
		$s['id'] = (int) $r->id;
		if ( $r->became_recording_id ) {
			$s['recording'] = (int) $r->became_recording_id;
		}
		$out[] = $s;
	}
	return $out;
}

/*
 * Taught words, in the shape the teleprompter keeps them:
 * { pairs: { script: { heard: true } }, ignore: { word: true }, labels: { key: text } }.
 */
function rrt_get_learned( $user_id = 0, $lang = 'en' ) {
	$l = get_user_meta( $user_id ? $user_id : get_current_user_id(), rrt_learned_key( $lang ), true );
	$l = is_array( $l ) ? $l : array();
	foreach ( array( 'pairs', 'ignore', 'labels', 'at', 'forgot' ) as $k ) {
		if ( ! isset( $l[ $k ] ) || ! is_array( $l[ $k ] ) ) {
			$l[ $k ] = array();
		}
	}
	return $l;
}
/**
 * Where a reader's taught words for a language are kept (1.33.0): English
 * in rrt_learned as before, any other language in rrt_learned_<code>.
 *
 * @param string $lang Language code.
 * @return string
 */
function rrt_learned_key( $lang ) {
	return ( '' === (string) $lang || 'en' === $lang ) ? 'rrt_learned' : 'rrt_learned_' . $lang;
}

/**
 * The language a learned-words request is for: one the plugin offers, else English.
 *
 * @param WP_REST_Request $req The request.
 * @return string
 */
function rrt_learned_lang( $req ) {
	$l = sanitize_key( (string) $req->get_param( 'lang' ) );
	return function_exists( 'rrt_languages' ) && array_key_exists( $l, rrt_languages() ) ? $l : 'en';
}

/* Keeps only plain keys and short text, so nothing odd is stored. */
function rrt_clean_learned( $l ) {
	$out = array( 'pairs' => array(), 'ignore' => array(), 'labels' => array(), 'at' => array(), 'forgot' => array() );
	if ( ! is_array( $l ) ) {
		return $out;
	}
	$key = function ( $k ) {
		return mb_substr( preg_replace( '/[^\p{L}\p{N}\'\- ]/u', '', (string) $k ), 0, 60 );
	};
	if ( isset( $l['pairs'] ) && is_array( $l['pairs'] ) ) {
		foreach ( array_slice( $l['pairs'], 0, 2000, true ) as $s => $heard ) {
			if ( ! is_array( $heard ) ) {
				continue;
			}
			foreach ( array_slice( $heard, 0, 50, true ) as $h => $yes ) {
				if ( $yes && '' !== $key( $s ) && '' !== $key( $h ) ) {
					$out['pairs'][ $key( $s ) ][ $key( $h ) ] = true;
				}
			}
		}
	}
	if ( isset( $l['ignore'] ) && is_array( $l['ignore'] ) ) {
		foreach ( array_slice( $l['ignore'], 0, 2000, true ) as $w => $yes ) {
			if ( $yes && '' !== $key( $w ) ) {
				$out['ignore'][ $key( $w ) ] = true;
			}
		}
	}
	if ( isset( $l['labels'] ) && is_array( $l['labels'] ) ) {
		foreach ( array_slice( $l['labels'], 0, 4000, true ) as $k => $t ) {
			$out['labels'][ mb_substr( (string) $k, 0, 130 ) ] = mb_substr( sanitize_text_field( (string) $t ), 0, 100 );
		}
	}
	/* When each entry was taught and forgotten (1.15.0), in milliseconds. */
	foreach ( array( 'at', 'forgot' ) as $part ) {
		if ( isset( $l[ $part ] ) && is_array( $l[ $part ] ) ) {
			foreach ( $l[ $part ] as $k => $t ) {
				if ( is_numeric( $t ) && $t > 0 ) {
					$out[ $part ][ mb_substr( (string) $k, 0, 130 ) ] = (int) $t;
				}
			}
		}
	}
	/* The newest 2000 forgotten words are kept, and none older than 400 days. */
	if ( $out['forgot'] ) {
		arsort( $out['forgot'] );
		$cut           = ( time() - 400 * DAY_IN_SECONDS ) * 1000;
		$out['forgot'] = array_slice(
			array_filter(
				$out['forgot'],
				function ( $t ) use ( $cut ) {
					return $t >= $cut;
				}
			),
			0,
			2000,
			true
		);
	}
	return rrt_learned_settle( $out );
}

/**
 * Every entry in a set of taught words: "script|heard" for a pair, "+word" for an ignored word.
 *
 * @param array $l A clean set.
 * @return array Entries as keys.
 */
function rrt_learned_entries( $l ) {
	$out = array();
	foreach ( $l['pairs'] as $s => $heard ) {
		foreach ( $heard as $h => $yes ) {
			$out[ $s . '|' . $h ] = true;
		}
	}
	foreach ( $l['ignore'] as $w => $yes ) {
		$out[ '+' . $w ] = true;
	}
	foreach ( $l['labels'] as $e => $t ) {
		$out[ (string) $e ] = true;
	}
	return $out;
}

/**
 * Takes one entry out of a set: its pair or ignored word, its label and its time.
 *
 * @param array  $l     A clean set.
 * @param string $entry The entry.
 * @return array
 */
function rrt_learned_drop( $l, $entry ) {
	$entry = (string) $entry;
	if ( '+' === substr( $entry, 0, 1 ) ) {
		unset( $l['ignore'][ substr( $entry, 1 ) ] );
	} else {
		$cut = strpos( $entry, '|' );
		if ( false !== $cut ) {
			$s = substr( $entry, 0, $cut );
			unset( $l['pairs'][ $s ][ substr( $entry, $cut + 1 ) ] );
			if ( isset( $l['pairs'][ $s ] ) && ! $l['pairs'][ $s ] ) {
				unset( $l['pairs'][ $s ] );
			}
		}
	}
	unset( $l['labels'][ $entry ], $l['at'][ $entry ] );
	return $l;
}

/**
 * Leaves out each entry forgotten since it was last taught (1.15.0). An
 * entry with no time counts as taught long ago, so a forgotten word kept in
 * a browser from before 1.15.0 stays forgotten.
 *
 * @param array $l A clean set.
 * @return array
 */
function rrt_learned_settle( $l ) {
	foreach ( array_keys( rrt_learned_entries( $l ) ) as $e ) {
		if ( isset( $l['forgot'][ $e ] ) && $l['forgot'][ $e ] >= ( isset( $l['at'][ $e ] ) ? $l['at'][ $e ] : 0 ) ) {
			$l = rrt_learned_drop( $l, $e );
		}
	}
	return $l;
}

/**
 * Marks an entry forgotten now and takes it out.
 *
 * @param array  $l     A clean set.
 * @param string $entry The entry.
 * @return array
 */
function rrt_learned_forget( $l, $entry ) {
	$l['forgot'][ (string) $entry ] = (int) round( microtime( true ) * 1000 );
	return rrt_learned_drop( $l, $entry );
}
/** Adds b's taught words to a's. */
function rrt_merge_learned( $a, $b ) {
	foreach ( $b['pairs'] as $s => $heard ) {
		foreach ( $heard as $h => $yes ) {
			$a['pairs'][ $s ][ $h ] = true;
		}
	}
	foreach ( $b['ignore'] as $w => $yes ) {
		$a['ignore'][ $w ] = true;
	}
	foreach ( $b['labels'] as $k => $t ) {
		if ( ! isset( $a['labels'][ $k ] ) ) {
			$a['labels'][ $k ] = $t;
		}
	}
	/* The later time wins for each entry (1.15.0); then anything forgotten since it was taught goes. */
	foreach ( array( 'at', 'forgot' ) as $part ) {
		$a[ $part ] = isset( $a[ $part ] ) ? $a[ $part ] : array();
		foreach ( isset( $b[ $part ] ) ? $b[ $part ] : array() as $k => $t ) {
			$a[ $part ][ $k ] = max( isset( $a[ $part ][ $k ] ) ? $a[ $part ][ $k ] : 0, (int) $t );
		}
	}
	return rrt_learned_settle( $a );
}

/**
 * Saves a set sent from a browser on top of the saved one. Since 1.15.0
 * sets are always merged, with forgotten words recorded. A set from a
 * studio tab opened before 1.15.0 asks to replace when a word is forgotten:
 * each saved entry it leaves out is recorded as forgotten instead.
 *
 * @param array $saved   The saved set.
 * @param mixed $sent    The set as sent.
 * @param bool  $replace The old studio's "replace".
 * @return array
 */
function rrt_learned_save_over( $saved, $sent, $replace ) {
	$old_tab = $replace && ( ! is_array( $sent ) || ! isset( $sent['forgot'] ) );
	$in      = rrt_clean_learned( $sent );
	if ( $old_tab ) {
		$have = rrt_learned_entries( $in );
		foreach ( array_keys( rrt_learned_entries( $saved ) ) as $e ) {
			if ( ! isset( $have[ $e ] ) ) {
				$saved = rrt_learned_forget( $saved, $e );
			}
		}
	}
	return rrt_merge_learned( $saved, $in );
}

/**
 * Records that an MP3 became a post's reading. Earlier rows for the post stop
 * being current. Returns the row id.
 *
 * @param int   $post_id The post.
 * @param array $r       attachment_id, url, duration_s, size_bytes, bitrate_kbps, tempo_wpm, take_id, user_id, edited, source.
 */
function rrt_note_recording( $post_id, $r ) {
	global $wpdb;
	$table = rrt_table( 'recordings' );
	$att   = isset( $r['attachment_id'] ) ? (int) $r['attachment_id'] : 0;
	if ( $att ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$same = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE post_id = %d AND attachment_id = %d AND is_current = 1", $post_id, $att ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the only variable is the plugin's own table name.
		if ( $same ) {
			return $same;
		}
	}
	$wpdb->update( $table, array( 'is_current' => 0 ), array( 'post_id' => $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$now = current_time( 'mysql', true );
	$row = array(
		'post_id'      => $post_id,
		'attachment_id' => $att ? $att : null,
		'file_name'    => isset( $r['file_name'] ) ? $r['file_name'] : ( $att ? wp_basename( (string) get_attached_file( $att ) ) : wp_basename( (string) ( $r['url'] ?? '' ) ) ),
		'url'          => isset( $r['url'] ) ? $r['url'] : ( $att ? wp_get_attachment_url( $att ) : '' ),
		'duration_s'   => $r['duration_s'] ?? null,
		'size_bytes'   => $r['size_bytes'] ?? ( $att ? @filesize( get_attached_file( $att ) ) : null ), // phpcs:ignore WordPress.PHP.NoSilencedErrors
		'bitrate_kbps' => $r['bitrate_kbps'] ?? null,
		'tempo_wpm'    => $r['tempo_wpm'] ?? null,
		'take_id'      => $r['take_id'] ?? null,
		'user_id'      => $r['user_id'] ?? ( get_current_user_id() ? get_current_user_id() : null ),
		'edited'       => empty( $r['edited'] ) ? 0 : 1,
		'source'       => $r['source'] ?? 'manual',
		'created_at'   => $now,
		/* A reading that was already there when 0.9.0 arrived went live with its post. */
		'published_at' => 'publish' === get_post_status( $post_id ) ? ( ( isset( $r['source'] ) && 'existing' === $r['source'] ) ? get_post_field( 'post_date_gmt', $post_id ) : $now ) : null,
		'is_current'   => 1,
	);
	$row = array_filter( $row, function ( $v ) { return null !== $v; } );
	$wpdb->insert( $table, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return (int) $wpdb->insert_id;
}

/* Length, size and bitrate of an MP3 in the media library, from WordPress's own reading of it. */
function rrt_attachment_audio_facts( $att ) {
	$meta = wp_get_attachment_metadata( $att );
	$out  = array();
	if ( is_array( $meta ) ) {
		if ( ! empty( $meta['length'] ) ) {
			$out['duration_s'] = (float) $meta['length'];
		}
		if ( ! empty( $meta['bitrate'] ) ) {
			$out['bitrate_kbps'] = (int) round( $meta['bitrate'] / 1000 );
		}
		if ( ! empty( $meta['filesize'] ) ) {
			$out['size_bytes'] = (int) $meta['filesize'];
		}
	}
	return $out;
}
/* Words in a post, for an average tempo when no take says otherwise. */
function rrt_post_words( $post_id ) {
	$text = wp_strip_all_tags( strip_shortcodes( (string) get_post_field( 'post_content', $post_id ) ) );
	$text = get_the_title( $post_id ) . ' ' . $text;
	return count( preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY ) );
}
/* A Recordings row for a reading chosen by hand, or found when 0.9.0 was installed. */
function rrt_note_manual_recording( $post_id, $source ) {
	if ( ! function_exists( 'rrt_reading_audio' ) ) {
		return 0;
	}
	$m = rrt_reading_audio( $post_id );
	if ( ! $m || empty( $m['url'] ) ) {
		return 0;
	}
	$r = array(
		'attachment_id' => ! empty( $m['id'] ) ? (int) $m['id'] : 0,
		'url'           => $m['url'],
		'source'        => $source,
		'user_id'       => 0,
	);
	if ( $r['attachment_id'] ) {
		$r = array_merge( $r, rrt_attachment_audio_facts( $r['attachment_id'] ) );
	}
	if ( ! empty( $r['duration_s'] ) ) {
		$r['tempo_wpm'] = (int) round( rrt_post_words( $post_id ) / ( $r['duration_s'] / 60 ) );
	}
	unset( $r['user_id'] );
	return rrt_note_recording( $post_id, $r );
}
/* Choosing a reading in the Reading audio box (or the theme's own field) is noted too. */
add_action(
	'updated_post_meta',
	function ( $meta_id, $post_id, $key ) {
		rrt_watch_audio_meta( $post_id, $key );
	},
	10,
	3
);
add_action(
	'added_post_meta',
	function ( $meta_id, $post_id, $key ) {
		rrt_watch_audio_meta( $post_id, $key );
	},
	10,
	3
);
function rrt_watch_audio_meta( $post_id, $key ) {
	static $busy = false;
	/* An upload writes its own, fuller row. */
	if ( $busy || ! empty( $GLOBALS['rrt_uploading'] ) || ! rrt_stats_on() ) {
		return;
	}
	$s = rrt_settings();
	if ( 'rrt_audio' !== $key && ( empty( $s['player']['audio_key'] ) || $s['player']['audio_key'] !== $key ) ) {
		return;
	}
	$busy = true;
	rrt_note_manual_recording( (int) $post_id, 'manual' );
	$busy = false;
}
/* Once, after 0.9.0 is installed: a row for every reading already on a post. */
add_action(
	'admin_init',
	function () {
		if ( get_option( 'rrt_recordings_backfilled' ) || ! rrt_stats_on() || ! function_exists( 'rrt_reading_audio' ) ) {
			return;
		}
		update_option( 'rrt_recordings_backfilled', 1, false );
		$s    = rrt_settings();
		$keys = array( 'rrt_audio' );
		if ( ! empty( $s['player']['audio_key'] ) ) {
			$keys[] = $s['player']['audio_key'];
		}
		$ids = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'meta_query'     => array_merge( array( 'relation' => 'OR' ), array_map( function ( $k ) { return array( 'key' => $k, 'compare' => 'EXISTS' ); }, $keys ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		foreach ( $ids as $id ) {
			rrt_note_manual_recording( (int) $id, 'existing' );
		}
	}
);

/** The REST routes, all for logged-in readers who can edit posts. */
add_action(
	'rest_api_init',
	function () {
		$can = function () {
			return rrt_stats_can();
		};
		$post_ok = function ( $req ) {
			$p = (int) $req['post'];
			return $p > 0 && get_post( $p ) && current_user_can( 'edit_post', $p );
		};
		/* Practice takes (1.29.0) belong to no post: post 0, the reader's own like every take. Only takes allow it. */
		$take_ok = function ( $req ) use ( $post_ok ) {
			return ( isset( $req['post'] ) && '0' === (string) $req['post'] ) || $post_ok( $req );
		};
		register_rest_route(
			'rrt/v1',
			'/takes',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => $can,
					'callback'            => function ( $req ) use ( $take_ok ) {
						if ( ! $take_ok( $req ) ) {
							return new WP_Error( 'rrt_post', __( 'That post cannot be read here.', 'foxfire-studio' ), array( 'status' => 403 ) );
						}
						return rest_ensure_response( array( 'takes' => rrt_get_takes( (int) $req['post'] ) ) );
					},
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => $can,
					'callback'            => function ( $req ) use ( $take_ok ) {
						if ( ! $take_ok( $req ) ) {
							return new WP_Error( 'rrt_post', __( 'That post cannot be read here.', 'foxfire-studio' ), array( 'status' => 403 ) );
						}
						$s = $req['summary'];
						if ( ! is_array( $s ) ) {
							return new WP_Error( 'rrt_take', __( 'No take was sent.', 'foxfire-studio' ), array( 'status' => 400 ) );
						}
						$flags = is_array( $req['flags'] ) ? array_slice( $req['flags'], 0, 500 ) : null;
						return rest_ensure_response( array( 'id' => rrt_save_take( (int) $req['post'], $s, $flags ) ) );
					},
				),
			)
		);
		register_rest_route(
			'rrt/v1',
			'/learned',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => $can,
					'callback'            => function ( $req ) {
						return rest_ensure_response( array( 'learned' => rrt_get_learned( 0, rrt_learned_lang( $req ) ) ) );
					},
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => $can,
					'callback'            => function ( $req ) {
						/* Merged with what is saved; a forgotten word is kept as forgotten (1.15.0). */
						$lang = rrt_learned_lang( $req );
						$out  = rrt_learned_save_over( rrt_clean_learned( rrt_get_learned( 0, $lang ) ), $req['learned'], (bool) $req['replace'] );
						update_user_meta( get_current_user_id(), rrt_learned_key( $lang ), $out );
						return rest_ensure_response( array( 'learned' => $out ) );
					},
				),
			)
		);
		register_rest_route(
			'rrt/v1',
			'/import',
			array(
				'methods'             => 'POST',
				'permission_callback' => $can,
				'callback'            => function ( $req ) {
					$n     = 0;
					$takes = is_array( $req['takes'] ) ? $req['takes'] : array();
					foreach ( array_slice( $takes, 0, 200, true ) as $post_id => $list ) {
						$post_id = (int) $post_id;
						if ( $post_id <= 0 || ! get_post( $post_id ) || ! current_user_can( 'edit_post', $post_id ) || ! is_array( $list ) ) {
							continue;
						}
						foreach ( array_slice( $list, 0, 50 ) as $s ) {
							if ( is_array( $s ) && ! empty( $s['at'] ) ) {
								rrt_save_take( $post_id, $s, null );
								$n++;
							}
						}
					}
					$l = rrt_merge_learned( rrt_get_learned(), rrt_clean_learned( $req['learned'] ) );
					update_user_meta( get_current_user_id(), 'rrt_learned', $l );
					return rest_ensure_response( array( 'takes' => $n, 'learned' => $l ) );
				},
			)
		);
		register_rest_route(
			'rrt/v1',
			'/recording',
			array(
				'methods'             => 'POST',
				'permission_callback' => function ( $req ) use ( $can, $post_ok ) {
					return $can() && current_user_can( 'upload_files' ) && $post_ok( $req );
				},
				'callback'            => 'rrt_rest_upload_recording',
			)
		);
	}
);

/* Nothing from these routes is ever cached by a page cache. */
add_filter(
	'rest_post_dispatch',
	function ( $res, $server, $req ) {
		if ( 0 === strpos( $req->get_route(), '/rrt/v1/' ) ) {
			$res->header( 'Cache-Control', 'no-store, private' );
		}
		return $res;
	},
	10,
	3
);

/**
 * "Use this recording": the MP3 (and captions, if sent) go into the media
 * library, attached to the post, and become its reading.
 */
function rrt_rest_upload_recording( $req ) {
	$post_id = (int) $req['post'];
	$files   = $req->get_file_params();
	/* Too large for the server (1.6.0: a broadcast WAV is about 17 MB a minute): say so, with the limit. */
	$too_big = ! empty( $files['mp3']['error'] ) && in_array( (int) $files['mp3']['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true );
	if ( empty( $files['mp3'] ) && isset( $_SERVER['CONTENT_LENGTH'] ) && (int) $_SERVER['CONTENT_LENGTH'] > wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) ) ) {
		$too_big = true;
	}
	if ( $too_big ) {
		return new WP_Error(
			'rrt_upload',
			sprintf(
				/* translators: %s: the largest upload the server accepts, such as 8 MB. */
				__( 'The recording is larger than this site accepts (%s). Ask your host to raise upload_max_filesize and post_max_size, or choose an MP3 preset under Finish for.', 'foxfire-studio' ),
				size_format( wp_max_upload_size() )
			),
			array( 'status' => 413 )
		);
	}
	if ( empty( $files['mp3'] ) || ! empty( $files['mp3']['error'] ) ) {
		return new WP_Error( 'rrt_upload', __( 'No recording arrived. Try again.', 'foxfire-studio' ), array( 'status' => 400 ) );
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	/*
	 * Only an MP3, or (1.6.0, the broadcast preset) a PCM WAV, and a .vtt are
	 * accepted, checked by content as well as name. The field keeps its name.
	 */
	$mp3  = $files['mp3'];
	$head = (string) file_get_contents( $mp3['tmp_name'], false, null, 0, 12 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$wav  = strlen( $head ) >= 12 && 'RIFF' === substr( $head, 0, 4 ) && 'WAVE' === substr( $head, 8, 4 );
	if ( ! $wav && 'ID3' !== substr( $head, 0, 3 ) && ( strlen( $head ) < 2 || ( ord( $head[0] ) !== 0xFF || ( ord( $head[1] ) & 0xE0 ) !== 0xE0 ) ) ) {
		return new WP_Error( 'rrt_upload', __( 'That file is not an MP3 or a WAV.', 'foxfire-studio' ), array( 'status' => 400 ) );
	}
	$ext         = $wav ? 'wav' : 'mp3';
	$mp3['name'] = sanitize_file_name( preg_replace( '/\.(mp3|wav)$/i', '', (string) $mp3['name'] ) ) . '.' . $ext;
	$mp3['type'] = $wav ? 'audio/wav' : 'audio/mpeg';
	$title       = wp_strip_all_tags( get_the_title( $post_id ) ) . ' (reading)';
	$att         = media_handle_sideload( $mp3, $post_id, $title );
	if ( is_wp_error( $att ) ) {
		return new WP_Error( 'rrt_upload', $att->get_error_message(), array( 'status' => 500 ) );
	}
	$cap = 0;
	if ( ! empty( $files['vtt'] ) && empty( $files['vtt']['error'] ) ) {
		$vtt  = $files['vtt'];
		$head = (string) file_get_contents( $vtt['tmp_name'], false, null, 0, 6 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( 'WEBVTT' === $head ) {
			$vtt['name'] = sanitize_file_name( preg_replace( '/\.vtt$/i', '', (string) $vtt['name'] ) ) . '.vtt';
			$vtt['type'] = 'text/vtt';
			$c           = media_handle_sideload( $vtt, $post_id, wp_strip_all_tags( get_the_title( $post_id ) ) . ' (captions)' );
			if ( ! is_wp_error( $c ) ) {
				$cap = (int) $c;
			}
		}
	}
	$GLOBALS['rrt_uploading'] = true;
	update_post_meta( $post_id, 'rrt_audio', $att );
	if ( $cap ) {
		update_post_meta( $post_id, 'rrt_captions', $cap );
	}
	$GLOBALS['rrt_uploading'] = false;
	/* Which music bed is under this reading (0.24.0), for its credit in the podcast feed. */
	$bed = absint( $req['music'] );
	if ( $bed && rrt_is_audio_attachment( $bed ) ) {
		$credit = '';
		foreach ( rrt_settings()['music']['beds'] as $b ) {
			if ( (int) $b['id'] === $bed ) {
				$credit = (string) $b['credit'];
			}
		}
		update_post_meta(
			$post_id,
			'_rrt_music_used',
			array(
				'audio'  => (int) $att,
				'bed'    => $bed,
				'credit' => $credit,
			)
		);
	} else {
		delete_post_meta( $post_id, '_rrt_music_used' );
	}
	/* The Recordings row, linked to the take it came from. */
	global $wpdb;
	$take_id = 0;
	$at      = (int) $req['take_at'];
	if ( $at ) {
		$table = rrt_table( 'takes' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$take_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE user_id = %d AND post_id = %d AND at_ms = %d", get_current_user_id(), $post_id, $at ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the only variable is the plugin's own table name.
	}
	$facts = rrt_attachment_audio_facts( $att );
	$rec   = rrt_note_recording(
		$post_id,
		array_merge(
			$facts,
			array(
				'attachment_id' => $att,
				'duration_s'    => is_numeric( $req['duration'] ) ? (float) $req['duration'] : ( $facts['duration_s'] ?? null ),
				'bitrate_kbps'  => is_numeric( $req['kbps'] ) ? (int) $req['kbps'] : ( $facts['bitrate_kbps'] ?? null ),
				'tempo_wpm'     => is_numeric( $req['tempo'] ) ? (int) $req['tempo'] : null,
				'take_id'       => $take_id ? $take_id : null,
				'edited'        => ! empty( $req['edited'] ),
				'source'        => 'upload',
			)
		)
	);
	if ( $take_id ) {
		$wpdb->update( rrt_table( 'takes' ), array( 'became_recording_id' => $rec ), array( 'id' => $take_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
	return rest_ensure_response(
		array(
			'attachment' => $att,
			'captions'   => $cap,
			'url'        => wp_get_attachment_url( $att ),
			'recording'  => $rec,
			'post_link'  => get_permalink( $post_id ),
			'edit_link'  => get_edit_post_link( $post_id, 'raw' ),
		)
	);
}

/* A reader deleted from the site takes their stats with them. */
add_action(
	'delete_user',
	function ( $user_id ) {
		global $wpdb;
		$wpdb->delete( rrt_table( 'takes' ), array( 'user_id' => (int) $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
);

/* WordPress's own personal data tools (Tools, Export or Erase Personal Data). */
add_filter(
	'wp_privacy_personal_data_exporters',
	function ( $ex ) {
		$ex['rrt-reader-stats'] = array(
			'exporter_friendly_name' => __( 'Foxfire Studio reader stats', 'foxfire-studio' ),
			'callback'               => function ( $email ) {
				$user = get_user_by( 'email', $email );
				$data = array();
				if ( $user ) {
					global $wpdb;
					$table = rrt_table( 'takes' );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, post_id, created_at, duration_s, wpm, accuracy, fillers, skipped, misread FROM $table WHERE user_id = %d ORDER BY created_at", $user->ID ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the only variable is the plugin's own table name.
					foreach ( $rows as $r ) {
						$data[] = array(
							'group_id'    => 'rrt-takes',
							'group_label' => __( 'Foxfire Studio takes', 'foxfire-studio' ),
							'item_id'     => 'rrt-take-' . $r->id,
							'data'        => array(
								array( 'name' => __( 'Post', 'foxfire-studio' ), 'value' => get_the_title( $r->post_id ) ),
								array( 'name' => __( 'Recorded', 'foxfire-studio' ), 'value' => $r->created_at . ' UTC' ),
								array( 'name' => __( 'Length (s)', 'foxfire-studio' ), 'value' => (string) $r->duration_s ),
								array( 'name' => __( 'Words a minute', 'foxfire-studio' ), 'value' => (string) $r->wpm ),
								array( 'name' => __( 'Accuracy (%)', 'foxfire-studio' ), 'value' => (string) $r->accuracy ),
								array( 'name' => 'Skipped, misread, fillers', 'value' => $r->skipped . ', ' . $r->misread . ', ' . $r->fillers ),
							),
						);
					}
					/* 1.33.0: English as before, then each other language that has taught words. */
					foreach ( array_keys( function_exists( 'rrt_languages' ) ? rrt_languages() : array( 'en' => '' ) ) as $rrt_lc ) {
						$l = rrt_get_learned( $user->ID, $rrt_lc );
						if ( $l['pairs'] || $l['ignore'] ) {
							$data[] = array(
								'group_id'    => 'rrt-learned',
								'group_label' => __( 'Foxfire Studio taught words', 'foxfire-studio' ),
								'item_id'     => 'en' === $rrt_lc ? 'rrt-learned' : 'rrt-learned-' . $rrt_lc,
								'data'        => array( array( 'name' => 'en' === $rrt_lc ? __( 'Taught words', 'foxfire-studio' ) : sprintf( /* translators: %s: language name. */ __( 'Taught words (%s)', 'foxfire-studio' ), rrt_languages()[ $rrt_lc ] ), 'value' => wp_json_encode( $l ) ) ),
							);
						}
					}
					/* Goals and practice notes (0.11.0); pronunciation notes for every post (1.1.0); words marked Not needed (1.15.0). */
					/* The setups' band measurements for the EQ (1.13.0). */
					foreach ( array( 'rrt_goals' => __( 'Goals', 'foxfire-studio' ), 'rrt_practice_notes' => __( 'Practice log notes', 'foxfire-studio' ), 'rrt_notes' => __( 'Pronunciation notes', 'foxfire-studio' ), '_rrt_eq' => __( 'Recording setups', 'foxfire-studio' ), 'rrt_pron_skip' => __( 'Words not needing a note', 'foxfire-studio' ) ) as $key => $label ) {
						$v = get_user_meta( $user->ID, $key, true );
						if ( $v ) {
							$data[] = array(
								'group_id'    => 'rrt-' . $key,
								'group_label' => sprintf( /* translators: %s: kind of data, such as takes. */ __( 'Foxfire Studio %s', 'foxfire-studio' ), strtolower( $label ) ),
								'item_id'     => $key,
								'data'        => array( array( 'name' => $label, 'value' => wp_json_encode( $v ) ) ),
							);
						}
					}
				}
				return array( 'data' => $data, 'done' => true );
			},
		);
		return $ex;
	}
);
add_filter(
	'wp_privacy_personal_data_erasers',
	function ( $er ) {
		$er['rrt-reader-stats'] = array(
			'eraser_friendly_name' => __( 'Foxfire Studio reader stats', 'foxfire-studio' ),
			'callback'             => function ( $email ) {
				$user = get_user_by( 'email', $email );
				$n    = 0;
				if ( $user ) {
					global $wpdb;
					$n = (int) $wpdb->delete( rrt_table( 'takes' ), array( 'user_id' => $user->ID ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					delete_user_meta( $user->ID, 'rrt_learned' );
					foreach ( array_keys( function_exists( 'rrt_languages' ) ? rrt_languages() : array() ) as $rrt_lc ) {
						delete_user_meta( $user->ID, rrt_learned_key( $rrt_lc ) );
					}
					delete_user_meta( $user->ID, 'rrt_goals' );
					delete_user_meta( $user->ID, 'rrt_practice_notes' );
					delete_user_meta( $user->ID, 'rrt_notes' );
					delete_user_meta( $user->ID, '_rrt_eq' );
					delete_user_meta( $user->ID, 'rrt_pron_skip' );
				}
				return array( 'items_removed' => $n > 0, 'items_retained' => false, 'messages' => array(), 'done' => true );
			},
		);
		return $er;
	}
);

/** Counts for the settings screen. */
function rrt_stats_counts() {
	global $wpdb;
	$t = rrt_table( 'takes' );
	$r = rrt_table( 'recordings' );
	return array(
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		'takes'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the only variable is the plugin's own table name.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		'readers'    => (int) $wpdb->get_var( "SELECT COUNT(DISTINCT user_id) FROM $t" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the only variable is the plugin's own table name.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		'recordings' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $r WHERE is_current = 1" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the only variable is the plugin's own table name.
	);
}
