<?php
/**
 * The take shelf across computers (1.34.0).
 *
 * Every reviewed take of a post goes on the post's shelf in the reader's
 * browser (1.3.0), for Combine takes and Continue. With Settings, Take shelf
 * switched on, each person's shelf is also kept on the site, so it follows
 * them to any computer or browser: the browser sends each take up in pieces
 * after its review, and the studio on any other computer lists it at once
 * and downloads its recording when Combine takes needs it.
 *
 * What is kept is what the browser keeps: the recording as Whisper heard it
 * (16-bit FLAC, made in the browser by flac.js, with a check of its samples),
 * the words and flags of its review, its fingerprint and speech level, and
 * each post's Combine takes plan. The same rules as the browser's shelf: the
 * newest six takes a post, each for 30 days. Off by default.
 *
 * Each person's list is in their user meta (_rrt_shelf); the recordings,
 * their words and the plans are files in uploads/rrt-shelf/<random>/, which
 * refuses every direct request, and are served only to their owner.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Takes kept a post, as the browser's shelf keeps them. */
define( 'RRT_SHELF_KEEP', 6 );

/** How long a take is kept, in days, from when it was recorded. */
define( 'RRT_SHELF_DAYS', 30 );

/** The largest piece of a recording the site takes at once, in bytes. */
define( 'RRT_SHELF_PIECE_MAX', 4 * 1048576 );

/** The largest recording, in bytes (about six hours of FLAC at 44.1 kHz). */
define( 'RRT_SHELF_MAX', 1024 * 1048576 );

/** The largest words-and-flags file, in bytes. */
define( 'RRT_SHELF_JSON_MAX', 8 * 1048576 );

/** The largest plan, in bytes. */
define( 'RRT_SHELF_PLAN_MAX', 262144 );

/** A send not heard from for this long, in seconds, is given up. */
define( 'RRT_SHELF_STALE', 2 * DAY_IN_SECONDS );

/** The setting: on (false by default) and the space limit for every shelf on the site, in MB. */
function rrt_shelf_settings() {
	$s = get_option( 'rrt_shelf', array() );
	$s = is_array( $s ) ? $s : array();
	return array(
		'on'    => ! empty( $s['on'] ),
		'limit' => isset( $s['limit'] ) ? max( 100, min( 1048576, (int) $s['limit'] ) ) : 2048,
	);
}

/** Whether shelves are kept on the site. */
function rrt_shelf_on() {
	$s = rrt_shelf_settings();
	return $s['on'];
}

/** The space limit, in bytes. */
function rrt_shelf_limit() {
	$s = rrt_shelf_settings();
	return (int) $s['limit'] * 1048576;
}

/** Whether the current person may keep a shelf on the site. */
function rrt_shelf_can() {
	return rrt_shelf_on() && is_user_logged_in() && current_user_can( 'edit_posts' );
}

/** Whether the current person may keep takes of this post. */
function rrt_shelf_post_ok( $post ) {
	$post = (int) $post;
	return $post > 0 && get_post( $post ) && current_user_can( 'edit_post', $post );
}

/** The folder name under uploads. */
function rrt_shelf_top() {
	$up = wp_upload_dir( null, false );
	return trailingslashit( $up['basedir'] ) . 'rrt-shelf/';
}

/** The folder that holds the files: a random folder of its own, so its address cannot be guessed (nginx ignores .htaccess). */
function rrt_shelf_dir() {
	$key = (string) get_option( 'rrt_shelf_key', '' );
	if ( ! preg_match( '/^[a-z0-9]{20}$/', $key ) ) {
		$key = strtolower( wp_generate_password( 20, false, false ) );
		update_option( 'rrt_shelf_key', $key, false );
	}
	return rrt_shelf_top() . $key . '/';
}

/** Makes the folders, with files that refuse every direct request. */
function rrt_shelf_dir_ready() {
	$dir = rrt_shelf_dir();
	if ( ! wp_mkdir_p( $dir ) ) {
		return false;
	}
	foreach ( array( rrt_shelf_top(), $dir ) as $d ) {
		if ( ! file_exists( $d . '.htaccess' ) ) {
			file_put_contents( $d . '.htaccess', "# Foxfire Studio: take shelves are served only to the person whose takes they are.\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		if ( ! file_exists( $d . 'index.php' ) ) {
			file_put_contents( $d . 'index.php', "<?php\n// Silence.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}
	return true;
}

/** A take's id, as the browser makes it ('t' and letters or digits), or ''. */
function rrt_shelf_clean_id( $id ) {
	$id = (string) $id;
	return preg_match( '/^t[a-z0-9]{6,30}$/', $id ) ? $id : '';
}

/** A take's file path; kind is 'flac' or 'json'. */
function rrt_shelf_file( $user_id, $id, $kind ) {
	return rrt_shelf_dir() . (int) $user_id . '-' . rrt_shelf_clean_id( $id ) . '.' . ( 'json' === $kind ? 'json' : 'flac' );
}

/** A post's plan file. */
function rrt_shelf_plan_file( $user_id, $post ) {
	return rrt_shelf_dir() . (int) $user_id . '-plan-' . (int) $post . '.json';
}

/** Removes a file if it is there. */
function rrt_shelf_unlink( $path ) {
	if ( file_exists( $path ) ) {
		wp_delete_file( $path );
	}
}

/** A person's list, as stored. */
function rrt_shelf_raw( $user_id ) {
	$list = get_user_meta( (int) $user_id, '_rrt_shelf', true );
	return is_array( $list ) ? array_values( $list ) : array();
}

/** Saves a person's list, removing the meta when it is empty. */
function rrt_shelf_save( $user_id, $list ) {
	if ( $list ) {
		update_user_meta( (int) $user_id, '_rrt_shelf', array_values( $list ) );
	} else {
		delete_user_meta( (int) $user_id, '_rrt_shelf' );
	}
}

/** Deletes a take's files. */
function rrt_shelf_drop_files( $user_id, $t ) {
	rrt_shelf_unlink( rrt_shelf_file( $user_id, $t['id'], 'flac' ) );
	rrt_shelf_unlink( rrt_shelf_file( $user_id, $t['id'], 'json' ) );
}

/**
 * A person's takes with the shelf's rules applied: past 30 days, sends given
 * up, takes whose file is gone, and all but the newest six of a post leave.
 *
 * @param int      $user_id User.
 * @param int|null $now     Time, for tests.
 * @return array
 */
function rrt_shelf_list( $user_id, $now = null ) {
	$now  = null === $now ? time() : (int) $now;
	$list = rrt_shelf_raw( $user_id );
	$keep = array();
	foreach ( $list as $t ) {
		$old   = (int) floor( (float) $t['at'] / 1000 ) + RRT_SHELF_DAYS * DAY_IN_SECONDS <= $now;
		$stale = 'up' === $t['state'] && (int) $t['updated'] + RRT_SHELF_STALE <= $now;
		$gone  = ! file_exists( rrt_shelf_file( $user_id, $t['id'], 'json' ) );
		if ( $old || $stale || $gone ) {
			rrt_shelf_drop_files( $user_id, $t );
			continue;
		}
		$keep[] = $t;
	}
	/* The newest six a post, by when they were recorded. */
	usort(
		$keep,
		function ( $a, $b ) {
			return (float) $b['at'] <=> (float) $a['at'];
		}
	);
	$count = array();
	$final = array();
	foreach ( $keep as $t ) {
		$p = (int) $t['post'];
		$count[ $p ] = isset( $count[ $p ] ) ? $count[ $p ] + 1 : 1;
		if ( $count[ $p ] > RRT_SHELF_KEEP ) {
			rrt_shelf_drop_files( $user_id, $t );
			continue;
		}
		$final[] = $t;
	}
	if ( count( $final ) !== count( $list ) ) {
		rrt_shelf_save( $user_id, $final );
	}
	return $final;
}

/** One of the current person's takes by id, or null. */
function rrt_shelf_find( $id, $user_id = 0 ) {
	$id      = rrt_shelf_clean_id( $id );
	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	if ( '' === $id ) {
		return null;
	}
	foreach ( rrt_shelf_raw( $user_id ) as $t ) {
		if ( hash_equals( (string) $t['id'], $id ) ) {
			return $t;
		}
	}
	return null;
}

/** Changes one of a person's takes. Returns the changed take, or null. */
function rrt_shelf_update( $user_id, $id, $change ) {
	$list = rrt_shelf_raw( $user_id );
	$out  = null;
	foreach ( $list as $k => $t ) {
		if ( hash_equals( (string) $t['id'], (string) $id ) ) {
			$list[ $k ] = array_merge( $t, $change );
			$out        = $list[ $k ];
		}
	}
	if ( $out ) {
		rrt_shelf_save( $user_id, $list );
	}
	return $out;
}

/** Deletes one of a person's takes and its files. Returns true if it was there. */
function rrt_shelf_delete( $user_id, $id ) {
	$list = rrt_shelf_raw( $user_id );
	$keep = array();
	$hit  = false;
	foreach ( $list as $t ) {
		if ( hash_equals( (string) $t['id'], (string) $id ) ) {
			rrt_shelf_drop_files( $user_id, $t );
			$hit = true;
			continue;
		}
		$keep[] = $t;
	}
	if ( $hit ) {
		rrt_shelf_save( $user_id, $keep );
	}
	return $hit;
}

/** Deletes a person's takes of one post and its plan (0: every post). Returns how many takes went. */
function rrt_shelf_clear( $user_id, $post = 0 ) {
	$n    = 0;
	$keep = array();
	foreach ( rrt_shelf_raw( $user_id ) as $t ) {
		if ( ! $post || (int) $t['post'] === (int) $post ) {
			rrt_shelf_drop_files( $user_id, $t );
			++$n;
			continue;
		}
		$keep[] = $t;
	}
	rrt_shelf_save( $user_id, $keep );
	if ( $post ) {
		rrt_shelf_unlink( rrt_shelf_plan_file( $user_id, $post ) );
	} else {
		foreach ( (array) glob( rrt_shelf_dir() . (int) $user_id . '-plan-*.json' ) as $f ) {
			rrt_shelf_unlink( $f );
		}
	}
	return $n;
}

/** Bytes kept for every shelf on the site (the recordings, as their size was given when they were started). */
function rrt_shelf_used() {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$rows = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM $wpdb->usermeta WHERE meta_key = %s", '_rrt_shelf' ) );
	$sum  = 0;
	foreach ( (array) $rows as $r ) {
		$list = maybe_unserialize( $r );
		foreach ( is_array( $list ) ? $list : array() as $t ) {
			$sum += (int) $t['bytes'];
		}
	}
	return $sum;
}

/** The users who have a shelf on the site. */
function rrt_shelf_users() {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM $wpdb->usermeta WHERE meta_key = %s", '_rrt_shelf' ) ) );
}

/** A number, or null. */
function rrt_shelf_num( $v, $places = 3 ) {
	return is_numeric( $v ) && is_finite( (float) $v ) ? round( (float) $v, $places ) : null;
}

/** A short line of text. */
function rrt_shelf_str( $v, $max ) {
	return is_string( $v ) || is_numeric( $v ) ? mb_substr( wp_check_invalid_utf8( (string) $v ), 0, $max ) : '';
}

/**
 * A take's details as the browser sent them, cleaned: every field the
 * plugin reads back, with its type, and nothing else. They are only ever
 * served to the same person, but are still treated as typed by them.
 *
 * @param array $in The take.
 * @return array|null
 */
function rrt_shelf_clean_take( $in ) {
	if ( ! is_array( $in ) ) {
		return null;
	}
	$words = array();
	foreach ( array_slice( is_array( $in['words'] ?? null ) ? $in['words'] : array(), 0, 40000 ) as $w ) {
		$words[] = is_array( $w ) ? array( rrt_shelf_num( $w[0] ?? null ), rrt_shelf_num( $w[1] ?? null ), sanitize_key( rrt_shelf_str( $w[2] ?? '', 16 ) ) ) : null;
	}
	$problems = array();
	foreach ( array_slice( is_array( $in['problems'] ?? null ) ? $in['problems'] : array(), 0, 400 ) as $p ) {
		if ( ! is_array( $p ) ) {
			continue;
		}
		$problems[] = array(
			'kind'   => sanitize_key( rrt_shelf_str( $p['kind'] ?? '', 24 ) ),
			'idx'    => isset( $p['idx'] ) && is_numeric( $p['idx'] ) ? (int) $p['idx'] : -1,
			's'      => rrt_shelf_num( $p['s'] ?? null, 2 ),
			'label'  => rrt_shelf_str( $p['label'] ?? '', 200 ),
			'detail' => rrt_shelf_str( $p['detail'] ?? '', 600 ),
		);
	}
	$chunks = array();
	foreach ( array_slice( is_array( $in['chunks'] ?? null ) ? $in['chunks'] : array(), 0, 40000 ) as $c ) {
		if ( ! is_array( $c ) ) {
			continue;
		}
		$ts       = is_array( $c['timestamp'] ?? null ) ? $c['timestamp'] : array();
		$chunks[] = array(
			'text'      => rrt_shelf_str( $c['text'] ?? '', 400 ),
			'timestamp' => array( rrt_shelf_num( $ts[0] ?? null, 2 ), rrt_shelf_num( $ts[1] ?? null, 2 ) ),
		);
	}
	$fp = is_array( $in['fp'] ?? null ) ? $in['fp'] : array();
	$pp = array();
	foreach ( array_slice( is_array( $fp['p'] ?? null ) ? $fp['p'] : array(), 0, 64 ) as $v ) {
		$pp[] = rrt_shelf_num( $v, 1 );
	}
	$rate = (int) ( $in['rate'] ?? 0 );
	$secs = rrt_shelf_num( $in['seconds'] ?? null, 3 );
	if ( $rate < 8000 || $rate > 192000 || null === $secs || $secs <= 0 ) {
		return null;
	}
	return array(
		'seconds'  => $secs,
		'rate'     => $rate,
		'sig'      => rrt_shelf_str( preg_replace( '/[^a-z0-9-]/', '', (string) ( $in['sig'] ?? '' ) ), 40 ),
		'fp'       => array(
			'n'    => max( 0, (int) ( $fp['n'] ?? 0 ) ),
			'rate' => (int) ( $fp['rate'] ?? $rate ),
			'p'    => $pp,
		),
		'level'    => rrt_shelf_num( $in['level'] ?? null, 2 ),
		'words'    => $words,
		'problems' => $problems,
		'chunks'   => $chunks,
		'accuracy' => rrt_shelf_num( $in['accuracy'] ?? null, 4 ),
		'wpm'      => rrt_shelf_num( $in['wpm'] ?? null, 1 ),
		'mode'     => sanitize_key( rrt_shelf_str( $in['mode'] ?? '', 20 ) ),
	);
}

/** What the browser gets for a take: its list entry, with its words when asked. */
function rrt_shelf_out( $user_id, $t, $full = true ) {
	$out = array(
		'id'      => (string) $t['id'],
		'post'    => (int) $t['post'],
		'n'       => (int) $t['n'],
		'at'      => (float) $t['at'],
		'seconds' => (float) $t['seconds'],
		'bytes'   => (int) $t['bytes'],
		'have'    => (int) $t['have'],
		'state'   => (string) $t['state'],
		'sum'     => (string) $t['sum'],
	);
	if ( $full ) {
		$raw  = file_exists( rrt_shelf_file( $user_id, $t['id'], 'json' ) ) ? file_get_contents( rrt_shelf_file( $user_id, $t['id'], 'json' ) ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
		$meta = json_decode( (string) $raw, true );
		if ( is_array( $meta ) ) {
			$out = array_merge( $meta, $out );
		}
	}
	return $out;
}

/** A post's plan for a person: array( 'plan' => ..., 'at' => ms ), or null. */
function rrt_shelf_plan( $user_id, $post ) {
	$f = rrt_shelf_plan_file( $user_id, $post );
	if ( ! file_exists( $f ) ) {
		return null;
	}
	$p = json_decode( (string) file_get_contents( $f ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	return is_array( $p ) && isset( $p['plan'] ) ? $p : null;
}

/** Whether a new recording of this many bytes fits: under the limit, and on the disk. */
function rrt_shelf_fits( $bytes ) {
	if ( rrt_shelf_used() + (int) $bytes > rrt_shelf_limit() ) {
		return false;
	}
	$free = function_exists( 'disk_free_space' ) ? @disk_free_space( rrt_shelf_top() ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
	return false === $free || $free > (int) $bytes * 2 + 50 * 1048576;
}

/** What the studio needs: where to send, the worker that writes FLAC, and how big a piece may be. */
function rrt_shelf_boot( $post ) {
	if ( ! rrt_shelf_can() || ! rrt_shelf_post_ok( $post ) ) {
		return null;
	}
	$max = function_exists( 'wp_max_upload_size' ) ? (int) wp_max_upload_size() : 0;
	return array(
		'rest'   => esc_url_raw( rest_url( 'rrt/v1/' ) ),
		'nonce'  => wp_create_nonce( 'wp_rest' ),
		'post'   => (int) $post,
		'piece'  => (int) max( 262144, min( RRT_SHELF_PIECE_MAX, $max > 0 ? (int) floor( $max / 2 ) : RRT_SHELF_PIECE_MAX ) ),
		'worker' => rrt_js_url( 'flac.js' ),
		'list'   => admin_url( 'admin.php?page=rrt-recordings#rrt-shelf' ),
	);
}

add_action(
	'rest_api_init',
	function () {
		$post_ok = function ( $req ) {
			return rrt_shelf_can() && rrt_shelf_post_ok( $req['post'] );
		};
		$own     = function ( $req ) {
			$t = rrt_shelf_find( $req['id'] );
			return rrt_shelf_can() && $t && rrt_shelf_post_ok( $t['post'] );
		};
		register_rest_route(
			'rrt/v1',
			'/shelf',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => $post_ok,
					'callback'            => 'rrt_rest_shelf_list',
				),
				array(
					'methods'             => 'DELETE',
					'permission_callback' => $post_ok,
					'callback'            => function ( $req ) {
						return rest_ensure_response( array( 'cleared' => rrt_shelf_clear( get_current_user_id(), (int) $req['post'] ) ) );
					},
				),
			)
		);
		register_rest_route(
			'rrt/v1',
			'/shelf/take',
			array(
				'methods'             => 'POST',
				'permission_callback' => $post_ok,
				'callback'            => 'rrt_rest_shelf_start',
			)
		);
		register_rest_route(
			'rrt/v1',
			'/shelf/plan',
			array(
				'methods'             => 'POST',
				'permission_callback' => $post_ok,
				'callback'            => 'rrt_rest_shelf_plan',
			)
		);
		$id = '(?P<id>t[a-z0-9]{6,30})';
		register_rest_route(
			'rrt/v1',
			'/shelf/take/' . $id . '/piece',
			array(
				'methods'             => 'POST',
				'permission_callback' => $own,
				'callback'            => 'rrt_rest_shelf_piece',
			)
		);
		register_rest_route(
			'rrt/v1',
			'/shelf/take/' . $id . '/done',
			array(
				'methods'             => 'POST',
				'permission_callback' => $own,
				'callback'            => 'rrt_rest_shelf_done',
			)
		);
		register_rest_route(
			'rrt/v1',
			'/shelf/take/' . $id . '/audio',
			array(
				'methods'             => 'GET',
				'permission_callback' => $own,
				'callback'            => 'rrt_rest_shelf_audio',
			)
		);
		register_rest_route(
			'rrt/v1',
			'/shelf/take/' . $id,
			array(
				'methods'             => 'DELETE',
				'permission_callback' => $own,
				'callback'            => function ( $req ) {
					return rest_ensure_response( array( 'deleted' => rrt_shelf_delete( get_current_user_id(), $req['id'] ) ) );
				},
			)
		);
	}
);

/** A post's shelf for the current person: every take (with its words), the plan, and whether there is room for more. */
function rrt_rest_shelf_list( $req ) {
	$user  = get_current_user_id();
	$post  = (int) $req['post'];
	$takes = array();
	foreach ( rrt_shelf_list( $user ) as $t ) {
		if ( (int) $t['post'] === $post ) {
			$takes[] = rrt_shelf_out( $user, $t, true );
		}
	}
	usort(
		$takes,
		function ( $a, $b ) {
			return $a['at'] <=> $b['at'];
		}
	);
	return rest_ensure_response(
		array(
			'takes' => $takes,
			'plan'  => rrt_shelf_plan( $user, $post ),
			'room'  => rrt_shelf_fits( 0 ),
		)
	);
}

/** Starts a take on the site: its words and details now, its recording in pieces after. */
function rrt_rest_shelf_start( $req ) {
	$user  = get_current_user_id();
	$post  = (int) $req['post'];
	$body  = $req->get_json_params();
	$body  = is_array( $body ) ? $body : array();
	$in    = is_array( $body['take'] ?? null ) ? $body['take'] : array();
	$id    = rrt_shelf_clean_id( $in['id'] ?? '' );
	$bytes = (int) ( $body['bytes'] ?? 0 );
	$sum   = (string) ( $body['sum'] ?? '' );
	$at    = is_numeric( $in['at'] ?? null ) ? (float) $in['at'] : 0;
	$now   = time();
	if ( '' === $id || $bytes < 42 || ! preg_match( '/^[0-9a-f]{16}-\d{1,12}$/', $sum ) ) {
		return new WP_Error( 'rrt_shelf', __( 'That take was not understood.', 'foxfire-studio' ), array( 'status' => 400 ) );
	}
	if ( $at <= 0 || $at / 1000 > $now + DAY_IN_SECONDS || $at / 1000 + RRT_SHELF_DAYS * DAY_IN_SECONDS <= $now ) {
		return new WP_Error( 'rrt_shelf', __( 'That take is older than the shelf keeps.', 'foxfire-studio' ), array( 'status' => 400 ) );
	}
	$have = rrt_shelf_find( $id, $user );
	if ( $have ) {
		/* Started before (a send cut off, or the same take from a second tab): carry on from what the site holds. */
		if ( (int) $have['post'] !== $post ) {
			return new WP_Error( 'rrt_shelf', __( 'That take was not understood.', 'foxfire-studio' ), array( 'status' => 400 ) );
		}
		return rest_ensure_response( array( 'take' => rrt_shelf_out( $user, $have, false ) ) );
	}
	if ( $bytes > RRT_SHELF_MAX ) {
		return new WP_Error( 'rrt_shelf', __( 'This take is longer than the site keeps.', 'foxfire-studio' ), array( 'status' => 413 ) );
	}
	$take = rrt_shelf_clean_take( $in );
	if ( ! $take ) {
		return new WP_Error( 'rrt_shelf', __( 'That take was not understood.', 'foxfire-studio' ), array( 'status' => 400 ) );
	}
	$json = wp_json_encode( $take );
	if ( ! $json || strlen( $json ) > RRT_SHELF_JSON_MAX ) {
		return new WP_Error( 'rrt_shelf', __( 'This take is longer than the site keeps.', 'foxfire-studio' ), array( 'status' => 413 ) );
	}
	$list = rrt_shelf_list( $user );
	if ( ! rrt_shelf_fits( $bytes ) ) {
		return new WP_Error( 'rrt_shelf_full', __( 'The site’s space for take shelves is full.', 'foxfire-studio' ), array( 'status' => 507 ) );
	}
	if ( ! rrt_shelf_dir_ready() ) {
		return new WP_Error( 'rrt_shelf', __( 'The folder for take shelves could not be made. Check that the uploads folder is writable.', 'foxfire-studio' ), array( 'status' => 500 ) );
	}
	if ( false === file_put_contents( rrt_shelf_file( $user, $id, 'json' ), $json ) || false === file_put_contents( rrt_shelf_file( $user, $id, 'flac' ), '' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
		return new WP_Error( 'rrt_shelf', __( 'The take could not be saved.', 'foxfire-studio' ), array( 'status' => 500 ) );
	}
	/* The site gives the take its number, so Take 3 is Take 3 on every computer. */
	$n = 1;
	foreach ( $list as $t ) {
		if ( (int) $t['post'] === $post ) {
			$n = max( $n, (int) $t['n'] + 1 );
		}
	}
	$t      = array(
		'id'      => $id,
		'post'    => $post,
		'n'       => $n,
		'at'      => $at,
		'seconds' => $take['seconds'],
		'bytes'   => $bytes,
		'have'    => 0,
		'state'   => 'up',
		'sum'     => $sum,
		'updated' => $now,
	);
	$list[] = $t;
	rrt_shelf_save( $user, $list );
	return rest_ensure_response( array( 'take' => rrt_shelf_out( $user, $t, false ) ) );
}

/** Adds a piece of a take's recording, at the offset it says. Answers with how many bytes the site holds. */
function rrt_rest_shelf_piece( $req ) {
	$user   = get_current_user_id();
	$t      = rrt_shelf_find( $req['id'], $user );
	if ( 'up' !== $t['state'] ) {
		return rest_ensure_response( array( 'have' => (int) $t['bytes'] ) );
	}
	$path   = rrt_shelf_file( $user, $t['id'], 'flac' );
	$offset = is_numeric( $req['offset'] ) ? (int) $req['offset'] : -1;
	$body   = (string) $req->get_body();
	$len    = strlen( $body );
	if ( $offset < 0 || 0 === $len ) {
		return new WP_Error( 'rrt_shelf', __( 'That piece of the take was not understood.', 'foxfire-studio' ), array( 'status' => 400 ) );
	}
	if ( $len > RRT_SHELF_PIECE_MAX ) {
		return new WP_Error( 'rrt_shelf', __( 'That piece of the take is too large.', 'foxfire-studio' ), array( 'status' => 413 ) );
	}
	$fh = file_exists( $path ) ? fopen( $path, 'r+b' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( ! $fh ) {
		return new WP_Error( 'rrt_shelf', __( 'That take is no longer on the site.', 'foxfire-studio' ), array( 'status' => 410 ) );
	}
	flock( $fh, LOCK_EX );
	clearstatcache( true, $path );
	$have = (int) filesize( $path );
	if ( $offset > $have ) {
		flock( $fh, LOCK_UN );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return new WP_REST_Response(
			array(
				'code'    => 'rrt_shelf_gap',
				'message' => __( 'A piece of the take is missing on the site.', 'foxfire-studio' ),
				'have'    => $have,
			),
			409
		);
	}
	$add = $offset + $len > $have ? substr( $body, $have - $offset ) : '';
	if ( '' !== $add ) {
		if ( $have + strlen( $add ) > (int) $t['bytes'] ) {
			flock( $fh, LOCK_UN );
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new WP_Error( 'rrt_shelf', __( 'That piece of the take was not understood.', 'foxfire-studio' ), array( 'status' => 400 ) );
		}
		fseek( $fh, $have );
		$wrote = fwrite( $fh, $add ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( strlen( $add ) !== $wrote ) {
			ftruncate( $fh, $have );
			flock( $fh, LOCK_UN );
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new WP_Error( 'rrt_shelf_full', __( 'The site could not keep that piece of the take. It may be out of space.', 'foxfire-studio' ), array( 'status' => 507 ) );
		}
		$have += $wrote;
		fflush( $fh );
	}
	flock( $fh, LOCK_UN );
	fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	rrt_shelf_update(
		$user,
		$t['id'],
		array(
			'have'    => $have,
			'updated' => time(),
		)
	);
	return rest_ensure_response( array( 'have' => $have ) );
}

/** Finishes a take's send once the whole recording is there. */
function rrt_rest_shelf_done( $req ) {
	$user = get_current_user_id();
	$t    = rrt_shelf_find( $req['id'], $user );
	if ( 'ready' === $t['state'] ) {
		return rest_ensure_response( array( 'take' => rrt_shelf_out( $user, $t, false ) ) );
	}
	$path = rrt_shelf_file( $user, $t['id'], 'flac' );
	clearstatcache( true, $path );
	$have = file_exists( $path ) ? (int) filesize( $path ) : 0;
	if ( $have !== (int) $t['bytes'] ) {
		return new WP_REST_Response(
			array(
				'code'    => 'rrt_shelf_gap',
				'message' => __( 'A piece of the take is missing on the site.', 'foxfire-studio' ),
				'have'    => $have,
			),
			409
		);
	}
	$fh   = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$head = $fh ? fread( $fh, 4 ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( $fh ) {
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
	if ( 'fLaC' !== $head ) {
		rrt_shelf_delete( $user, $t['id'] );
		return new WP_Error( 'rrt_shelf', __( 'That is not a recording the take shelf keeps.', 'foxfire-studio' ), array( 'status' => 400 ) );
	}
	$t = rrt_shelf_update(
		$user,
		$t['id'],
		array(
			'state'   => 'ready',
			'have'    => $have,
			'updated' => time(),
		)
	);
	rrt_shelf_list( $user );
	$t = rrt_shelf_find( $t['id'], $user );
	return rest_ensure_response( array( 'take' => $t ? rrt_shelf_out( $user, $t, false ) : null ) );
}

/** Sends a take's recording to its owner. Nothing is cached. */
function rrt_rest_shelf_audio( $req ) {
	$user = get_current_user_id();
	$t    = rrt_shelf_find( $req['id'], $user );
	$path = rrt_shelf_file( $user, $t['id'], 'flac' );
	if ( 'ready' !== $t['state'] || ! file_exists( $path ) ) {
		return new WP_Error( 'rrt_shelf', __( 'That take is no longer on the site.', 'foxfire-studio' ), array( 'status' => 410 ) );
	}
	nocache_headers();
	header( 'Content-Type: audio/flac' );
	header( 'Content-Length: ' . filesize( $path ) );
	header( 'Cache-Control: no-store, private' );
	header( 'X-Content-Type-Options: nosniff' );
	readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit;
}

/** Saves a post's Combine takes plan. The later save wins. */
function rrt_rest_shelf_plan( $req ) {
	$user = get_current_user_id();
	$post = (int) $req['post'];
	$body = $req->get_json_params();
	$plan = is_array( $body ) ? ( $body['plan'] ?? null ) : null;
	if ( null === $plan ) {
		rrt_shelf_unlink( rrt_shelf_plan_file( $user, $post ) );
		return rest_ensure_response( array( 'plan' => null ) );
	}
	$clean = rrt_shelf_clean_plan( $plan );
	$at    = is_numeric( $body['at'] ?? null ) ? (float) $body['at'] : round( microtime( true ) * 1000 );
	$json  = wp_json_encode( array( 'plan' => $clean, 'at' => $at ) );
	if ( ! $json || strlen( $json ) > RRT_SHELF_PLAN_MAX ) {
		return new WP_Error( 'rrt_shelf', __( 'That plan is too large.', 'foxfire-studio' ), array( 'status' => 413 ) );
	}
	if ( ! rrt_shelf_dir_ready() || false === file_put_contents( rrt_shelf_plan_file( $user, $post ), $json ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
		return new WP_Error( 'rrt_shelf', __( 'The plan could not be saved.', 'foxfire-studio' ), array( 'status' => 500 ) );
	}
	return rest_ensure_response( array( 'at' => $at ) );
}

/** A Combine takes plan, cleaned: choices (piece to take id), splits (paragraph to true) and joins (key to nudges). */
function rrt_shelf_clean_plan( $plan ) {
	$plan = is_array( $plan ) ? $plan : array();
	$out  = array(
		'choice' => array(),
		'split'  => array(),
		'joins'  => array(),
	);
	foreach ( array_slice( is_array( $plan['choice'] ?? null ) ? $plan['choice'] : array(), 0, 5000, true ) as $k => $v ) {
		$k = preg_replace( '/[^a-z0-9]/', '', (string) $k );
		$v = rrt_shelf_clean_id( $v );
		if ( '' !== $k && '' !== $v ) {
			$out['choice'][ $k ] = $v;
		}
	}
	foreach ( array_slice( is_array( $plan['split'] ?? null ) ? $plan['split'] : array(), 0, 5000, true ) as $k => $v ) {
		$k = preg_replace( '/[^a-z0-9]/', '', (string) $k );
		if ( '' !== $k && $v ) {
			$out['split'][ $k ] = true;
		}
	}
	foreach ( array_slice( is_array( $plan['joins'] ?? null ) ? $plan['joins'] : array(), 0, 5000, true ) as $k => $v ) {
		$k = preg_replace( '/[^a-z0-9:>-]/', '', (string) $k );
		if ( '' === $k || ! is_array( $v ) ) {
			continue;
		}
		$out['joins'][ $k ] = array(
			'a'  => (int) ( $v['a'] ?? 0 ),
			'b'  => (int) ( $v['b'] ?? 0 ),
			'sp' => (int) ( $v['sp'] ?? 0 ),
		);
	}
	foreach ( $out as $k => $v ) {
		if ( ! $v ) {
			$out[ $k ] = new stdClass();
		}
	}
	return $out;
}

/** The hourly job (shared with shared takes): every shelf's rules applied, so old takes and given-up sends go. */
function rrt_shelf_hourly() {
	foreach ( rrt_shelf_users() as $u ) {
		rrt_shelf_list( $u );
	}
}
add_action( 'rrt_share_hourly', 'rrt_shelf_hourly' );

/** Deletes every shelf on the site. Returns how many takes went. */
function rrt_shelf_delete_all() {
	$n = 0;
	foreach ( rrt_shelf_users() as $u ) {
		$n += rrt_shelf_clear( $u, 0 );
	}
	foreach ( (array) glob( rrt_shelf_dir() . '*-plan-*.json' ) as $f ) {
		rrt_shelf_unlink( $f );
	}
	return $n;
}

/** A person's shelf goes when their account is deleted. */
add_action(
	'delete_user',
	function ( $user_id ) {
		rrt_shelf_clear( $user_id, 0 );
	}
);

/** Settings, Take shelf: the switch, the limit, the space used and Delete every shelf on the site. */
function rrt_shelf_settings_section() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$s     = rrt_shelf_settings();
	$used  = rrt_shelf_used();
	$users = count( rrt_shelf_users() );
	echo '<h2 id="rrt-shelf-settings">' . esc_html__( 'Take shelf', 'foxfire-studio' ) . '</h2>';
	echo '<p>' . esc_html( sprintf( /* translators: 1: takes kept a post, 2: days. */ __( 'Every take a person reviews goes on its post’s shelf in their browser, for Combine takes and Continue: the newest %1$d takes a post, each for %2$d days. Keep the shelves on the site as well, and each person’s shelf follows them to any computer or browser they sign in on. Their recordings are then kept on this site, where only they can open them.', 'foxfire-studio' ), (int) RRT_SHELF_KEEP, (int) RRT_SHELF_DAYS ) ) . '</p>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="rrt-shelf-form">';
	wp_nonce_field( 'rrt_shelf_save' );
	echo '<input type="hidden" name="action" value="rrt_shelf_save">';
	echo '<table class="form-table" role="presentation"><tr><th scope="row">' . esc_html__( 'On the site', 'foxfire-studio' ) . '</th><td>';
	echo '<label class="rrt-check"><input type="checkbox" name="on" value="1" id="rrt-shelf-on"' . checked( $s['on'], true, false ) . '> ' . esc_html__( 'Keep each person’s take shelf on the site, so it follows them to any computer or browser', 'foxfire-studio' ) . '</label>';
	echo '<p class="description">' . esc_html__( 'Off: shelves stay in each browser only. Switching it off later stops sending and fetching; the takes already on the site then leave within 30 days, or at once with Delete every shelf on the site below.', 'foxfire-studio' ) . '</p>';
	echo '</td></tr><tr><th scope="row"><label for="rrt-shelf-limit">' . esc_html__( 'Space for every shelf', 'foxfire-studio' ) . '</label></th><td>';
	echo '<input type="number" min="100" max="1048576" step="1" name="limit" id="rrt-shelf-limit" value="' . esc_attr( (string) $s['limit'] ) . '" class="small-text"> MB';
	echo '<p class="description" id="rrt-shelf-used">' . esc_html( sprintf( /* translators: 1: space used, 2: how many people. */ _n( 'Used now: %1$s, by %2$d person. When the space is used up, new takes stay in the browser only, and the studio says so.', 'Used now: %1$s, by %2$d people. When the space is used up, new takes stay in the browser only, and the studio says so.', $users, 'foxfire-studio' ), size_format( $used, 1 ) ? size_format( $used, 1 ) : '0 B', $users ) ) . '</p>';
	echo '</td></tr></table>';
	submit_button( __( 'Save take shelf settings', 'foxfire-studio' ), 'secondary', 'save_shelf' );
	echo '</form>';
	if ( $used > 0 || $users > 0 ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="rrt-shelf-wipe">';
		wp_nonce_field( 'rrt_shelf_delete_all' );
		echo '<input type="hidden" name="action" value="rrt_shelf_delete_all">';
		echo '<p><label class="rrt-check"><input type="checkbox" name="sure" value="1" required> ' . esc_html__( 'Yes, delete every take shelf kept on the site (each browser keeps its own copy)', 'foxfire-studio' ) . '</label>';
		submit_button( __( 'Delete every shelf on the site', 'foxfire-studio' ), 'delete', 'delete_shelves', false );
		echo '</p></form>';
	}
}
add_action( 'rrt_settings_files', 'rrt_shelf_settings_section' );

add_action(
	'admin_post_rrt_shelf_save',
	function () {
		check_admin_referer( 'rrt_shelf_save' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'foxfire-studio' ) );
		}
		$limit = isset( $_POST['limit'] ) ? (int) $_POST['limit'] : 2048;
		update_option(
			'rrt_shelf',
			array(
				'on'    => ! empty( $_POST['on'] ),
				'limit' => max( 100, min( 1048576, $limit ) ),
			),
			false
		);
		wp_safe_redirect( admin_url( 'options-general.php?page=rrt-settings&rrt_shelf=saved#rrt-shelf-settings' ) );
		exit;
	}
);

add_action(
	'admin_post_rrt_shelf_delete_all',
	function () {
		check_admin_referer( 'rrt_shelf_delete_all' );
		if ( ! current_user_can( 'manage_options' ) || empty( $_POST['sure'] ) ) {
			wp_die( esc_html__( 'Not allowed.', 'foxfire-studio' ) );
		}
		rrt_shelf_delete_all();
		wp_safe_redirect( admin_url( 'options-general.php?page=rrt-settings&rrt_shelf=deleted#rrt-shelf-settings' ) );
		exit;
	}
);

/** Recordings, Your take shelf on the site: the current person's takes by post, with Delete. */
function rrt_shelf_admin_section() {
	if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
		return;
	}
	$user = get_current_user_id();
	$list = rrt_shelf_list( $user );
	if ( ! $list && ! rrt_shelf_on() ) {
		return;
	}
	usort(
		$list,
		function ( $a, $b ) {
			return ( (int) $a['post'] <=> (int) $b['post'] ) ?: ( (int) $a['n'] <=> (int) $b['n'] );
		}
	);
	echo '<h2 id="rrt-shelf">' . esc_html__( 'Your take shelf on the site', 'foxfire-studio' ) . '</h2>';
	echo '<p>' . esc_html( sprintf( /* translators: 1: takes kept a post, 2: days. */ __( 'Your reviewed takes, kept on the site for Combine takes and Continue on any computer: the newest %1$d a post, each for %2$d days from when it was recorded. Deleting one here deletes it on every computer the next time the studio opens that post.', 'foxfire-studio' ), (int) RRT_SHELF_KEEP, (int) RRT_SHELF_DAYS ) ) . '</p>';
	echo '<table class="widefat striped rrt-table" id="rrt-t-shelf"><thead><tr><th>' . esc_html__( 'Post', 'foxfire-studio' ) . '</th><th>' . esc_html__( 'Take', 'foxfire-studio' ) . '</th><th class="num">' . esc_html__( 'Length', 'foxfire-studio' ) . '</th><th class="num">' . esc_html__( 'Size', 'foxfire-studio' ) . '</th><th>' . esc_html__( 'Recorded', 'foxfire-studio' ) . '</th><th></th></tr></thead><tbody>';
	foreach ( $list as $t ) {
		$post  = get_post( (int) $t['post'] );
		$title = $post ? wp_strip_all_tags( get_the_title( $post ) ) : __( '(a post no longer here)', 'foxfire-studio' );
		$note  = 'up' === $t['state'] ? ' <span class="description">' . esc_html__( '(still being sent)', 'foxfire-studio' ) . '</span>' : '';
		echo '<tr><td>' . esc_html( $title ) . '</td><td>' . esc_html( sprintf( /* translators: %d: the take's number on the shelf. */ __( 'Take %d', 'foxfire-studio' ), (int) $t['n'] ) ) . $note . '</td>';
		echo '<td class="num">' . esc_html( function_exists( 'rrt_clock' ) ? rrt_clock( (float) $t['seconds'] ) : (string) round( (float) $t['seconds'] ) ) . '</td>';
		echo '<td class="num">' . esc_html( size_format( (int) $t['bytes'], 1 ) ) . '</td>';
		echo '<td>' . esc_html( wp_date( 'D M j, g:i a', (int) floor( (float) $t['at'] / 1000 ) ) ) . '</td><td>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
		wp_nonce_field( 'rrt_shelf_delete' );
		echo '<input type="hidden" name="action" value="rrt_shelf_delete"><input type="hidden" name="id" value="' . esc_attr( (string) $t['id'] ) . '">';
		echo '<button type="submit" class="button">' . esc_html__( 'Delete', 'foxfire-studio' ) . '</button></form></td></tr>';
	}
	if ( ! $list ) {
		echo '<tr><td colspan="6">' . esc_html__( 'Nothing on the site yet. Each take you review in the studio is sent here by itself.', 'foxfire-studio' ) . '</td></tr>';
	}
	echo '</tbody></table>';
}

add_action(
	'admin_post_rrt_shelf_delete',
	function () {
		check_admin_referer( 'rrt_shelf_delete' );
		if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'foxfire-studio' ) );
		}
		$id = isset( $_POST['id'] ) ? rrt_shelf_clean_id( sanitize_text_field( wp_unslash( $_POST['id'] ) ) ) : '';
		if ( '' !== $id ) {
			rrt_shelf_delete( get_current_user_id(), $id );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=rrt-recordings#rrt-shelf' ) );
		exit;
	}
);

add_filter(
	'wp_privacy_personal_data_exporters',
	function ( $ex ) {
		$ex['rrt-shelf'] = array(
			'exporter_friendly_name' => __( 'Foxfire Studio take shelf', 'foxfire-studio' ),
			'callback'               => function ( $email ) {
				$user = get_user_by( 'email', $email );
				$data = array();
				foreach ( $user ? rrt_shelf_raw( $user->ID ) : array() as $t ) {
					$post   = get_post( (int) $t['post'] );
					$data[] = array(
						'group_id'    => 'rrt-shelf',
						'group_label' => __( 'Foxfire Studio take shelf', 'foxfire-studio' ),
						'item_id'     => 'rrt-shelf-' . $t['id'],
						'data'        => array(
							array( 'name' => __( 'Post', 'foxfire-studio' ), 'value' => $post ? wp_strip_all_tags( get_the_title( $post ) ) : (string) (int) $t['post'] ),
							array( 'name' => __( 'Take', 'foxfire-studio' ), 'value' => (string) (int) $t['n'] ),
							array( 'name' => __( 'Recorded', 'foxfire-studio' ), 'value' => gmdate( 'Y-m-d H:i', (int) floor( (float) $t['at'] / 1000 ) ) . ' UTC' ),
							array( 'name' => __( 'Length (s)', 'foxfire-studio' ), 'value' => (string) round( (float) $t['seconds'], 1 ) ),
							array( 'name' => __( 'Recording', 'foxfire-studio' ), 'value' => __( 'A FLAC recording of the take, kept on the site for the take shelf.', 'foxfire-studio' ) ),
						),
					);
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
		$er['rrt-shelf'] = array(
			'eraser_friendly_name' => __( 'Foxfire Studio take shelf', 'foxfire-studio' ),
			'callback'             => function ( $email ) {
				$user = get_user_by( 'email', $email );
				$n    = $user ? rrt_shelf_clear( $user->ID, 0 ) : 0;
				return array( 'items_removed' => $n > 0, 'items_retained' => false, 'messages' => array(), 'done' => true );
			},
		);
		return $er;
	}
);
