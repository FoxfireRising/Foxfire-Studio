<?php
/**
 * Phone take mode (1.12.0): a take sent to the site in pieces while it is
 * recorded.
 *
 * On a phone the studio starts a waiting take on the site as recording
 * begins (POST rrt/v1/sent/live), sends the audio in pieces of about ten
 * seconds as it goes (POST rrt/v1/sent/<id>/piece), and finishes it at Stop
 * (POST rrt/v1/sent/<id>/done). The file is a WAV from the first moment,
 * with its sizes brought up to date after every piece, so a take cut off by
 * a call, a reload or a closed page is still a recording the computer can
 * open. Each piece says where it belongs (its byte offset), so a piece sent
 * twice is kept once, and one that arrives early is refused with how much
 * the site holds, for the phone to carry on from there.
 *
 * It lives beside Send to my computer (sent.php): the same refused folder,
 * the same list in the sender's user meta, the same 14 days and the same
 * limit of waiting takes. Only the account that started a take can add to it.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The largest piece the site takes, in bytes (a 10 s piece at 96 kHz and 24 bits is under 3 MB). */
define( 'RRT_LIVE_PIECE_MAX', 4 * 1048576 );

/** The largest take, in bytes of audio (about 20 hours of 24-bit audio at 48 kHz; a WAV holds at most 4 GB). */
define( 'RRT_LIVE_MAX', 1536 * 1048576 );

/** A live take not heard from for this long, in seconds, was cut off. */
define( 'RRT_LIVE_STALE', 90 );

/**
 * A 44-byte WAV header for mono PCM.
 *
 * @param int $rate  Samples a second.
 * @param int $bits  16 or 24.
 * @param int $bytes Bytes of audio.
 * @return string
 */
function rrt_live_header( $rate, $bits, $bytes ) {
	$block = $bits / 8;
	return 'RIFF' . pack( 'V', 36 + $bytes ) . 'WAVE' . 'fmt ' . pack( 'VvvVVvv', 16, 1, 1, $rate, $rate * $block, $block, $bits ) . 'data' . pack( 'V', $bytes );
}

/**
 * The state of a waiting take: done, live (pieces still arriving) or cut.
 *
 * @param array    $t   The take.
 * @param int|null $now Time, for tests.
 * @return string
 */
function rrt_live_state( $t, $now = null ) {
	if ( ! empty( $t['cut'] ) ) {
		return 'cut';
	}
	if ( empty( $t['live'] ) ) {
		return 'done';
	}
	$now = null === $now ? time() : (int) $now;
	return $now - (int) ( $t['updated'] ?? $t['sent'] ) > RRT_LIVE_STALE ? 'cut' : 'live';
}

/** Changes one of the current person's waiting takes. Returns the changed take, or null. */
function rrt_live_update( $id, $change ) {
	$user = get_current_user_id();
	$list = get_user_meta( $user, '_rrt_sent', true );
	$list = is_array( $list ) ? $list : array();
	$out  = null;
	foreach ( $list as $k => $t ) {
		if ( hash_equals( (string) $t['id'], (string) $id ) ) {
			$list[ $k ] = array_merge( $t, $change );
			$out        = $list[ $k ];
		}
	}
	if ( $out ) {
		rrt_sent_save( $user, $list );
	}
	return $out;
}

/** A live take of the current person's, by id, or null. */
function rrt_live_find( $id ) {
	$t = rrt_sent_find( $id );
	return ( $t && ! empty( $t['live'] ) ) ? $t : null;
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'rrt/v1',
			'/sent/live',
			array(
				'methods'             => 'POST',
				'permission_callback' => function ( $req ) {
					$p = (int) $req['post'];
					return rrt_sent_can() && $p > 0 && get_post( $p ) && current_user_can( 'edit_post', $p );
				},
				'callback'            => 'rrt_rest_live_start',
			)
		);
		$id = '(?P<id>[a-zA-Z0-9]{20,40})';
		$own = function ( $req ) {
			$t = rrt_live_find( $req['id'] );
			return rrt_sent_can() && $t && current_user_can( 'edit_post', (int) $t['post'] );
		};
		register_rest_route(
			'rrt/v1',
			'/sent/' . $id . '/piece',
			array(
				'methods'             => 'POST',
				'permission_callback' => $own,
				'callback'            => 'rrt_rest_live_piece',
			)
		);
		register_rest_route(
			'rrt/v1',
			'/sent/' . $id . '/done',
			array(
				'methods'             => 'POST',
				'permission_callback' => $own,
				'callback'            => 'rrt_rest_live_done',
			)
		);
	}
);

/** Starts a take that will arrive in pieces. */
function rrt_rest_live_start( $req ) {
	$user = get_current_user_id();
	$post = (int) $req['post'];
	if ( count( rrt_sent_list( $user ) ) >= RRT_SENT_MAX ) {
		return new WP_Error( 'rrt_sent', sprintf( /* translators: %d: number of takes. */ __( '%d takes are already waiting for your computer, so this take is kept on the phone only. Open or delete one on the computer first.', 'foxfire-studio' ), RRT_SENT_MAX ), array( 'status' => 409 ) );
	}
	$rate = (int) $req['rate'];
	$bits = (int) $req['bits'];
	if ( $rate < 8000 || $rate > 192000 || ! in_array( $bits, array( 16, 24 ), true ) ) {
		return new WP_Error( 'rrt_sent', __( 'The recording settings were not understood.', 'foxfire-studio' ), array( 'status' => 400 ) );
	}
	if ( ! rrt_sent_dir_ready() ) {
		return new WP_Error( 'rrt_sent', __( 'The folder for sent takes could not be made. Check that the uploads folder is writable.', 'foxfire-studio' ), array( 'status' => 500 ) );
	}
	$id   = wp_generate_password( 32, false, false );
	$name = $id . '.wav';
	if ( false === file_put_contents( rrt_sent_dir() . $name, rrt_live_header( $rate, $bits, 0 ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
		return new WP_Error( 'rrt_sent', __( 'The take could not be saved.', 'foxfire-studio' ), array( 'status' => 500 ) );
	}
	$now    = time();
	$drill  = sanitize_key( (string) $req['drill'] );
	$t      = array(
		'id'      => $id,
		'post'    => $post,
		'title'   => mb_substr( wp_strip_all_tags( get_the_title( $post ) ), 0, 250 ),
		'file'    => $name,
		'type'    => 'audio/wav',
		'bytes'   => 44,
		'seconds' => 0,
		'words'   => 0,
		'drill'   => 'opening' === $drill ? 'opening' : '',
		'drill_s' => is_numeric( $req['drill_s'] ) ? max( 0, (int) $req['drill_s'] ) : 0,
		'focus'   => substr( sanitize_key( (string) $req['focus'] ), 0, 20 ),
		/* The device and microphone it was recorded on (1.13.0), for the setup's EQ. */
		'setup'   => rrt_eq_clean_key( $req['setup'] ),
		'setup_label' => mb_substr( sanitize_text_field( (string) $req['setup_label'] ), 0, 120 ),
		'sent'    => $now,
		'ends'    => $now + RRT_SENT_DAYS * DAY_IN_SECONDS,
		'live'    => 1,
		'cut'     => 0,
		'rate'    => $rate,
		'bits'    => $bits,
		'updated' => $now,
	);
	$list   = get_user_meta( $user, '_rrt_sent', true );
	$list   = is_array( $list ) ? $list : array();
	$list[] = $t;
	rrt_sent_save( $user, $list );
	return rest_ensure_response( array( 'take' => rrt_sent_out( $t ) ) );
}

/** Adds a piece to a live take, at the offset it says. Answers with how many bytes of audio the site holds. */
function rrt_rest_live_piece( $req ) {
	$t      = rrt_live_find( $req['id'] );
	$path   = rrt_sent_dir() . basename( (string) $t['file'] );
	$offset = is_numeric( $req['offset'] ) ? (int) $req['offset'] : -1;
	$body   = (string) $req->get_body();
	$len    = strlen( $body );
	$frame  = 24 === (int) $t['bits'] ? 3 : 2;
	if ( $offset < 0 || 0 !== $offset % $frame || 0 !== $len % $frame ) {
		return new WP_Error( 'rrt_sent', __( 'That piece of the take was not understood.', 'foxfire-studio' ), array( 'status' => 400 ) );
	}
	if ( $len > RRT_LIVE_PIECE_MAX ) {
		return new WP_Error( 'rrt_sent', __( 'That piece of the take is too large.', 'foxfire-studio' ), array( 'status' => 413 ) );
	}
	$fh = file_exists( $path ) ? fopen( $path, 'r+b' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( ! $fh ) {
		return new WP_Error( 'rrt_sent', __( 'That take is no longer on the site.', 'foxfire-studio' ), array( 'status' => 410 ) );
	}
	flock( $fh, LOCK_EX );
	clearstatcache( true, $path );
	$have = max( 0, (int) filesize( $path ) - 44 );
	if ( $offset > $have ) {
		flock( $fh, LOCK_UN );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return new WP_REST_Response(
			array(
				'code'    => 'rrt_sent_gap',
				'message' => __( 'A piece of the take is missing on the site.', 'foxfire-studio' ),
				'have'    => $have,
			),
			409
		);
	}
	$add = $offset + $len > $have ? substr( $body, $have - $offset ) : '';
	if ( '' !== $add ) {
		if ( $have + strlen( $add ) > RRT_LIVE_MAX ) {
			flock( $fh, LOCK_UN );
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new WP_Error( 'rrt_sent', __( 'This take is longer than the site keeps.', 'foxfire-studio' ), array( 'status' => 413 ) );
		}
		fseek( $fh, 44 + $have );
		$wrote = fwrite( $fh, $add ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( strlen( $add ) !== $wrote ) {
			/* A full disk: put the file back to what it held, so it stays a whole WAV. */
			ftruncate( $fh, 44 + $have );
			flock( $fh, LOCK_UN );
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new WP_Error( 'rrt_sent', __( 'The site could not keep that piece of the take. It may be out of space.', 'foxfire-studio' ), array( 'status' => 507 ) );
		}
		$have += $wrote;
		fseek( $fh, 4 );
		fwrite( $fh, pack( 'V', 36 + $have ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fseek( $fh, 40 );
		fwrite( $fh, pack( 'V', $have ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fflush( $fh );
	}
	flock( $fh, LOCK_UN );
	fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$change = array(
		'bytes'   => 44 + $have,
		'seconds' => round( $have / ( $frame * max( 1, (int) $t['rate'] ) ), 2 ),
		'updated' => time(),
	);
	if ( is_numeric( $req['words'] ) ) {
		$change['words'] = max( (int) $t['words'], (int) $req['words'] );
	}
	rrt_live_update( $t['id'], $change );
	return rest_ensure_response( array( 'have' => $have ) );
}

/** Finishes a live take: at Stop, or (cut) after an interruption once the phone has sent what it kept. */
function rrt_rest_live_done( $req ) {
	$t      = rrt_live_find( $req['id'] );
	$path   = rrt_sent_dir() . basename( (string) $t['file'] );
	clearstatcache( true, $path );
	$have   = file_exists( $path ) ? max( 0, (int) filesize( $path ) - 44 ) : 0;
	$frame  = 24 === (int) $t['bits'] ? 3 : 2;
	if ( 0 === $have ) {
		/* Nothing arrived, so there is nothing to wait for. */
		rrt_sent_delete( get_current_user_id(), $t['id'] );
		return rest_ensure_response( array( 'take' => null ) );
	}
	$change = array(
		'live'    => 0,
		'cut'     => empty( $req['cut'] ) ? 0 : 1,
		'bytes'   => 44 + $have,
		'seconds' => round( $have / ( $frame * max( 1, (int) $t['rate'] ) ), 2 ),
		'updated' => time(),
	);
	if ( is_numeric( $req['words'] ) ) {
		$change['words'] = max( 0, (int) $req['words'] );
	}
	$t = rrt_live_update( $t['id'], $change );
	return rest_ensure_response( array( 'take' => rrt_sent_out( $t ) ) );
}
