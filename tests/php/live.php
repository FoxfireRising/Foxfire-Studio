<?php
/**
 * Phone take mode (1.12.0), run inside the test WordPress:
 *   php wp-cli.phar --path=<wordpress> eval-file tests/php/live.php
 * A take started on the site, its pieces added in order, twice, early and
 * too large, the WAV's sizes after each, finishing it and finishing one cut
 * off, its state while pieces arrive and once they stop, someone else's take,
 * the limit of waiting takes, and an empty take dropped. Prints one line per
 * check and exits 1 if any fail. Leaves nothing behind.
 */

$GLOBALS['lv_pass'] = 0;
$GLOBALS['lv_fail'] = array();

function lv_t( $ok, $name, $detail = '' ) {
	if ( $ok ) {
		++$GLOBALS['lv_pass'];
		echo "ok   $name\n";
	} else {
		$GLOBALS['lv_fail'][] = $name;
		echo "FAIL $name" . ( '' !== $detail ? ": $detail" : '' ) . "\n";
	}
}

function lv_req( $method, $route, $params = array(), $body = null ) {
	$r = new WP_REST_Request( $method, '/rrt/v1/' . $route );
	foreach ( $params as $k => $v ) {
		$r->set_param( $k, $v );
	}
	if ( null !== $body ) {
		$r->set_body( $body );
		$r->set_header( 'Content-Type', 'application/octet-stream' );
	}
	$res = rest_do_request( $r );
	return array( $res->get_status(), $res->get_data() );
}

/* What a WAV file's header says: data size, RIFF size, rate, bits. */
function lv_head( $path ) {
	clearstatcache( true, $path );
	$h = file_get_contents( $path, false, null, 0, 44 );
	$u = unpack( 'Vriff', substr( $h, 4, 4 ) ) + unpack( 'Vrate', substr( $h, 24, 4 ) ) + unpack( 'vbits', substr( $h, 34, 2 ) ) + unpack( 'Vdata', substr( $h, 40, 4 ) );
	$u['size'] = filesize( $path );
	return $u;
}

wp_set_current_user( 1 );
$lv_user = get_current_user_id();
$lv_was  = get_user_meta( $lv_user, '_rrt_sent', true );
delete_user_meta( $lv_user, '_rrt_sent' );
$lv_post = wp_insert_post( array( 'post_title' => 'Live test', 'post_status' => 'publish', 'post_content' => '<p>One two three.</p>' ) );
register_shutdown_function(
	function () use ( $lv_user, $lv_was, $lv_post ) {
		foreach ( rrt_sent_list( $lv_user ) as $t ) {
			rrt_sent_delete( $lv_user, $t['id'] );
		}
		if ( $lv_was ) {
			update_user_meta( $lv_user, '_rrt_sent', $lv_was );
		}
		wp_delete_post( $lv_post, true );
	}
);

/* Start. */
list( $st, $d ) = lv_req( 'POST', 'sent/live', array( 'post' => $lv_post, 'rate' => 48000, 'bits' => 24, 'drill' => '', 'focus' => '' ) );
lv_t( 200 === $st && ! empty( $d['take']['id'] ), 'a take is started on the site', $st . ' ' . wp_json_encode( $d ) );
$lv_id   = $d['take']['id'] ?? '';
$lv_list = get_user_meta( $lv_user, '_rrt_sent', true );
$lv_file = rrt_sent_dir() . $lv_list[0]['file'];
$h       = lv_head( $lv_file );
lv_t( 44 === $h['size'] && 0 === $h['data'] && 36 === $h['riff'] && 48000 === $h['rate'] && 24 === $h['bits'], 'it is a WAV with no audio yet, 48 kHz 24-bit', wp_json_encode( $h ) );
lv_t( 'live' === ( $d['take']['state'] ?? '' ), 'its state is live' );
lv_t( 200 === lv_req( 'POST', 'sent/live', array( 'post' => $lv_post, 'rate' => 48000, 'bits' => 20 ) )[0] ? false : true, 'a depth other than 16 or 24 is refused' );

/* Pieces. */
$a = str_repeat( "\x01\x02\x03", 1000 );
$b = str_repeat( "\x04\x05\x06", 500 );
list( $st, $d ) = lv_req( 'POST', "sent/$lv_id/piece", array( 'offset' => 0, 'words' => 3 ), $a );
lv_t( 200 === $st && 3000 === $d['have'], 'the first piece is kept', $st . ' ' . wp_json_encode( $d ) );
$h = lv_head( $lv_file );
lv_t( 3000 === $h['data'] && 3036 === $h['riff'] && 3044 === $h['size'], 'the header says how much audio there is after each piece', wp_json_encode( $h ) );
list( $st, $d ) = lv_req( 'POST', "sent/$lv_id/piece", array( 'offset' => 0 ), $a );
lv_t( 200 === $st && 3000 === $d['have'] && 3044 === lv_head( $lv_file )['size'], 'the same piece sent twice is kept once' );
list( $st, $d ) = lv_req( 'POST', "sent/$lv_id/piece", array( 'offset' => 4500 ), $b );
lv_t( 409 === $st && 3000 === $d['have'], 'a piece that arrives early is refused with how much the site holds', $st . ' ' . wp_json_encode( $d ) );
list( $st, $d ) = lv_req( 'POST', "sent/$lv_id/piece", array( 'offset' => 1500 ), $a );
lv_t( 200 === $st && 4500 === $d['have'], 'a piece that overlaps what the site holds adds only the new part', $st . ' ' . wp_json_encode( $d ) );
lv_t( file_get_contents( $lv_file, false, null, 44 ) === $a . substr( $a, 1500 ), 'the audio is in order with nothing repeated' );
lv_t( 400 === lv_req( 'POST', "sent/$lv_id/piece", array( 'offset' => 4501 ), $b )[0], 'an offset inside a sample is refused' );
lv_t( 400 === lv_req( 'POST', "sent/$lv_id/piece", array( 'offset' => 4500 ), 'ab' )[0], 'a piece of part of a sample is refused' );
lv_t( 413 === lv_req( 'POST', "sent/$lv_id/piece", array( 'offset' => 4500 ), str_repeat( "\x00\x00\x00", (int) ( RRT_LIVE_PIECE_MAX / 3 ) + 1 ) )[0], 'a piece over the largest size is refused' );
$t = rrt_sent_find( $lv_id );
lv_t( 4544 === (int) $t['bytes'] && 3 === (int) $t['words'] && abs( (float) $t['seconds'] - 4500 / 144000 ) < 0.01, 'the list keeps the size, length and words reached', wp_json_encode( $t ) );

/* State over time. */
lv_t( 'live' === rrt_live_state( $t, time() + 30 ) && 'cut' === rrt_live_state( $t, time() + RRT_LIVE_STALE + 5 ), 'a take not heard from for 90 seconds counts as cut off' );

/* Someone else may not add to it. */
$lv_other = wp_insert_user( array( 'user_login' => 'lv_other_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'editor' ) );
wp_set_current_user( $lv_other );
lv_t( 200 !== lv_req( 'POST', "sent/$lv_id/piece", array( 'offset' => 4500 ), $b )[0], 'another account cannot add to the take' );
lv_t( 200 !== lv_req( 'POST', "sent/$lv_id/done", array( 'words' => 1 ) )[0], 'another account cannot finish it' );
wp_set_current_user( 1 );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $lv_other );

/* Finish. */
list( $st, $d ) = lv_req( 'POST', "sent/$lv_id/done", array( 'words' => 3, 'cut' => 0 ) );
lv_t( 200 === $st && 'done' === $d['take']['state'] && 4544 === $d['take']['bytes'], 'Stop finishes the take on the site', $st . ' ' . wp_json_encode( $d ) );
lv_t( 200 !== lv_req( 'POST', "sent/$lv_id/piece", array( 'offset' => 4500 ), $b )[0], 'a finished take takes no more pieces' );
lv_t( 'RIFF' === substr( (string) file_get_contents( $lv_file ), 0, 4 ), 'the finished file is a WAV the computer can open' );
lv_t( 'wav' === ( rrt_sent_sniff( $lv_file )[0] ?? '' ), 'and it is recognised as one' );

/* A take cut off, finished from the next visit. */
list( $st, $d ) = lv_req( 'POST', 'sent/live', array( 'post' => $lv_post, 'rate' => 44100, 'bits' => 16 ) );
$lv_cut = $d['take']['id'];
lv_req( 'POST', "sent/$lv_cut/piece", array( 'offset' => 0, 'words' => 2 ), str_repeat( "\x01\x00", 4410 ) );
list( $st, $d ) = lv_req( 'POST', "sent/$lv_cut/done", array( 'words' => 2, 'cut' => 1 ) );
lv_t( 200 === $st && 'cut' === $d['take']['state'] && abs( $d['take']['seconds'] - 0.1 ) < 0.01, 'a take finished after an interruption is marked cut off', wp_json_encode( $d ) );

/* An empty take is dropped once it stops. */
list( $st, $d ) = lv_req( 'POST', 'sent/live', array( 'post' => $lv_post, 'rate' => 48000, 'bits' => 24 ) );
$lv_empty = $d['take']['id'];
list( $st, $d ) = lv_req( 'POST', "sent/$lv_empty/done", array( 'words' => 0 ) );
lv_t( 200 === $st && null === $d['take'] && null === rrt_sent_find( $lv_empty ), 'a take with no audio is deleted at Stop' );
list( $st, $d ) = lv_req( 'POST', 'sent/live', array( 'post' => $lv_post, 'rate' => 48000, 'bits' => 24 ) );
$lv_gone = $d['take']['id'];
$list    = get_user_meta( $lv_user, '_rrt_sent', true );
foreach ( $list as $k => $x ) {
	if ( $x['id'] === $lv_gone ) {
		$list[ $k ]['updated'] = time() - 600;
		$gone_file             = rrt_sent_dir() . $x['file'];
	}
}
update_user_meta( $lv_user, '_rrt_sent', $list );
rrt_sent_list( $lv_user );
lv_t( null === rrt_sent_find( $lv_gone ) && ! file_exists( $gone_file ), 'a take cut off before any audio arrived is dropped, with its file' );

/* The limit of waiting takes. */
for ( $i = count( rrt_sent_list( $lv_user ) ); $i < RRT_SENT_MAX; $i++ ) {
	$x = lv_req( 'POST', 'sent/live', array( 'post' => $lv_post, 'rate' => 48000, 'bits' => 24 ) );
	lv_req( 'POST', 'sent/' . $x[1]['take']['id'] . '/piece', array( 'offset' => 0 ), "\x00\x00\x00" );
}
list( $st, $d ) = lv_req( 'POST', 'sent/live', array( 'post' => $lv_post, 'rate' => 48000, 'bits' => 24 ) );
lv_t( 409 === $st && false !== strpos( (string) $d['message'], 'kept on the phone only' ), 'over the limit of waiting takes, the phone is told to keep the take', $st . ' ' . wp_json_encode( $d ) );

/* The Recordings table says which are cut off or still arriving. */
ob_start();
rrt_sent_admin_section();
$html = ob_get_clean();
lv_t( false !== strpos( $html, '(cut off partway)' ) && false !== strpos( $html, '(still being recorded on the phone)' ), 'Recordings says which takes were cut off or are still arriving' );

$n = count( $GLOBALS['lv_fail'] );
echo "\n" . $GLOBALS['lv_pass'] . " passed, $n failed\n";
if ( $n ) {
	exit( 1 );
}
