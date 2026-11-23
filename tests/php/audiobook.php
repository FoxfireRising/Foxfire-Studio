<?php
/**
 * Audiobook export (1.20.0) on the server, run inside the test WordPress:
 *   php wp-cli.phar --path=<wordpress> eval-file tests/php/audiobook.php
 * Prints one line per check and exits 1 if any fail. Leaves nothing behind.
 *
 * The MP3s are made here, as in tests/php/collections.php: real MPEG-1
 * Layer III frames that decode to silence, so no encoder is needed.
 */

$GLOBALS['ab_pass'] = 0;
$GLOBALS['ab_fail'] = array();

function ab_t( $ok, $name, $detail = '' ) {
	if ( $ok ) {
		++$GLOBALS['ab_pass'];
		echo "ok   $name\n";
	} else {
		$GLOBALS['ab_fail'][] = $name;
		echo "FAIL $name" . ( '' !== $detail ? ': ' . $detail : '' ) . "\n";
	}
}

wp_set_current_user( 1 );
$GLOBALS['ab_posts']    = array();
$GLOBALS['ab_files']    = array();
$GLOBALS['ab_settings'] = get_option( 'rrt_settings', null );
register_shutdown_function(
	function () {
		foreach ( array_reverse( $GLOBALS['ab_posts'] ) as $id ) {
			if ( get_post( $id ) ) {
				foreach ( get_children( array( 'post_parent' => $id, 'post_type' => 'attachment', 'numberposts' => -1 ) ) as $a ) {
					wp_delete_attachment( $a->ID, true );
				}
				wp_delete_post( $id, true );
			}
		}
		foreach ( $GLOBALS['ab_files'] as $f ) {
			if ( is_file( $f ) ) {
				unlink( $f );
			}
		}
		if ( null === $GLOBALS['ab_settings'] ) {
			delete_option( 'rrt_settings' );
		} else {
			update_option( 'rrt_settings', $GLOBALS['ab_settings'] );
		}
		$GLOBALS['rrt_col_queue'] = array();
		if ( $GLOBALS['ab_fail'] ) {
			echo count( $GLOBALS['ab_fail'] ) . " failed\n";
			exit( 1 );
		}
		echo $GLOBALS['ab_pass'] . " passed\n";
	}
);

/** Frames of a silent MP3. */
function ab_frames( $n, $kbps, $ch = 1 ) {
	$rate = 44100;
	$bri  = array_search( $kbps, rrt_col_bitrates(), true );
	$sri  = array_search( $rate, rrt_col_rates(), true );
	$mode = 1 === $ch ? 3 : 1;
	$side = 1 === $ch ? 17 : 32;
	$base = intdiv( 144000 * $kbps, $rate );
	$rest = ( 144000 * $kbps ) % $rate;
	$acc  = 0;
	$out  = '';
	for ( $i = 0; $i < $n; $i++ ) {
		$acc += $rest;
		$pad  = $acc >= $rate ? 1 : 0;
		$acc -= $pad * $rate;
		$h    = 0xFFE00000 | ( 3 << 19 ) | ( 1 << 17 ) | ( 1 << 16 ) | ( $bri << 12 ) | ( $sri << 10 ) | ( $pad << 9 ) | ( $mode << 6 );
		$out .= pack( 'N', $h ) . str_repeat( "\0", $base + $pad - 4 );
	}
	return $out;
}

/** A temporary file with these bytes. */
function ab_tmp( $bytes ) {
	$f = wp_tempnam( 'ab' );
	file_put_contents( $f, $bytes );
	$GLOBALS['ab_files'][] = $f;
	return $f;
}

/** A published post, with a reading when bytes are given. */
function ab_post( $title, $bytes = null, $type = 'post', $parent = 0 ) {
	$p = wp_insert_post( array( 'post_title' => $title, 'post_status' => 'publish', 'post_content' => '<p>Words.</p>', 'post_type' => $type, 'post_parent' => $parent ) );
	$GLOBALS['ab_posts'][] = $p;
	if ( null !== $bytes ) {
		$up   = wp_upload_dir();
		$path = trailingslashit( $up['path'] ) . 'ab-' . $p . '-reading.' . ( 'RIFF' === substr( $bytes, 0, 4 ) ? 'wav' : 'mp3' );
		file_put_contents( $path, $bytes );
		$GLOBALS['ab_files'][] = $path;
		$a = wp_insert_attachment( array( 'post_title' => 'r', 'post_mime_type' => 'audio/mpeg', 'post_status' => 'inherit' ), $path, $p );
		update_attached_file( $a, $path );
		update_post_meta( $p, 'rrt_audio', $a );
	}
	return $p;
}

$fps = 44100 / 1152;

/*
 * The pieces are loaded.
 */
ab_t( function_exists( 'rrt_book_store' ) && function_exists( 'rrt_book_state' ) && post_type_exists( RRT_CREDIT_TYPE ), 'the audiobook functions and the credits post type are loaded' );
$pt = get_post_type_object( RRT_CREDIT_TYPE );
ab_t( $pt && ! $pt->public && ! $pt->show_ui && ! $pt->publicly_queryable, 'credits are never public and have no screen of their own' );
ab_t( false === rrt_settings()['sound']['book'], 'Keep an audiobook copy is off on a new install' );
$s = rrt_sanitize_settings( array( 'sound' => array( 'book' => '1', 'finish' => 'podcast_mono' ) ) );
ab_t( true === $s['sound']['book'], 'Keep an audiobook copy can be ticked' );
$s = rrt_sanitize_settings( array( 'sound' => array( 'finish' => 'podcast_mono' ) ) );
ab_t( false === $s['sound']['book'], 'and unticked' );

/*
 * Keeping a copy.
 */
$p1  = ab_post( 'The Turning Wheel: A Spell for Rain', ab_frames( (int) round( 20 * $fps ), 128 ) );
$ok  = ab_tmp( ab_frames( (int) round( 30 * $fps ), 192 ) );
$out = rrt_book_store( $p1, $ok, array( 'from' => 'take', 'rms' => '-20.04', 'peak' => -3.6, 'noise' => 'x', 'head' => 1.2, 'tail' => 3 ) );
ab_t( is_array( $out ) && abs( $out['secs'] - 30 ) < 0.05 && 192 === $out['kbps'] && 1 === $out['ch'], 'a 192 kbps copy is kept, with its length', wp_json_encode( $out ) );
$m = get_post_meta( $p1, RRT_BOOK_META, true );
ab_t( -20.04 === $m['rms'] && null === $m['noise'] && 'take' === $m['from'], 'its numbers are kept, and junk is dropped' );
$first = rrt_book_dir() . $m['file'];
ab_t( is_file( $first ) && 0 === strpos( $first, wp_upload_dir()['basedir'] . '/rrt-audiobook/' ), 'the copy is in uploads/rrt-audiobook' );
ab_t( is_file( rrt_book_dir() . '.htaccess' ) && false !== strpos( file_get_contents( rrt_book_dir() . '.htaccess' ), 'Require all denied' ) && is_file( rrt_book_dir() . 'index.php' ), 'the folder refuses direct requests' );
ab_t( 0 === strpos( $out['url'], admin_url( 'admin-post.php' ) ) && false !== strpos( $out['url'], 'action=rrt_book_audio' ) && false !== strpos( $out['url'], '_wpnonce=' ), 'it plays through admin-post with a nonce' );
$bad = rrt_book_store( $p1, ab_tmp( ab_frames( 200, 128 ) ), array() );
ab_t( is_wp_error( $bad ) && 400 === $bad->get_error_data()['status'], 'a 128 kbps file is refused' );
$bad = rrt_book_store( $p1, ab_tmp( 'RIFF....WAVEfmt ' . str_repeat( "\0", 400 ) ), array() );
ab_t( is_wp_error( $bad ), 'a WAV is refused' );
ab_t( is_file( $first ), 'a refused file leaves the copy as it was' );
$out2 = rrt_book_store( $p1, ab_tmp( ab_frames( (int) round( 10 * $fps ), 192 ) ), array( 'from' => 'nonsense' ) );
$m2   = get_post_meta( $p1, RRT_BOOK_META, true );
ab_t( abs( $out2['secs'] - 10 ) < 0.05 && ! is_file( $first ) && is_file( rrt_book_dir() . $m2['file'] ) && 'take' === $m2['from'], 'a new copy replaces the old one and its file' );

/*
 * Where each chapter comes from.
 */
$p2 = ab_post( 'Happy Winter Solstice!', ab_frames( (int) round( 8 * $fps ), 96 ) );
$p3 = ab_post( 'With music', ab_frames( (int) round( 8 * $fps ), 128 ) );
update_post_meta( $p3, '_rrt_music_used', array( 'audio' => (int) get_post_meta( $p3, 'rrt_audio', true ), 'bed' => 1, 'credit' => '' ) );
$p4 = ab_post( 'No reading' );
$p5 = ab_post( 'A WAV', 'RIFF' . pack( 'V', 36 ) . 'WAVEfmt ' . str_repeat( "\0", 300 ) );
$p6 = ab_post( 'Music from an older reading', ab_frames( (int) round( 8 * $fps ), 128 ) );
update_post_meta( $p6, '_rrt_music_used', array( 'audio' => 999999, 'bed' => 1, 'credit' => '' ) );
ab_t( 'book' === rrt_book_source( $p1 )['source'], 'a reading with a copy uses it' );
ab_t( 'post' === rrt_book_source( $p2 )['source'], 'a plain reading is made from its post\'s MP3, whatever its bitrate' );
ab_t( array( 'source' => 'need', 'why' => 'music' ) === rrt_book_source( $p3 ), 'a reading with the music bed needs a copy' );
ab_t( 'post' === rrt_book_source( $p6 )['source'], 'a music bed on an earlier reading does not count' );
ab_t( array( 'source' => 'need', 'why' => 'none' ) === rrt_book_source( $p4 ), 'a post with no reading needs one' );
ab_t( array( 'source' => 'need', 'why' => 'notmp3' ) === rrt_book_source( $p5 ), 'a WAV reading needs a copy' );

/*
 * A collection, its credits and its sample.
 */
$col = ab_post( 'Spells for a Kinder World', null, RRT_COL_TYPE );
update_post_meta( $col, RRT_COL_META, array( 'items' => array( $p1, $p2, $p3 ), 'gap' => 2, 'feed' => true, 'type' => 'bonus' ) );
$author = rrt_book_author( $col );
$open   = rrt_book_credit_script( $col, 'open' );
ab_t( 0 === strpos( $open, "Spells for a Kinder World.\n" ) && false !== strpos( $open, 'Written by ' . $author ) && false !== strpos( $open, 'Narrated by ' . $author ), 'the opening credits start with the title, author and narrator', $open );
ab_t( 0 === strpos( rrt_book_credit_script( $col, 'close' ), "The end.\n" ), 'the closing credits start with The end' );
ab_t( null === rrt_book_credit( $col, 'open' ), 'no credits post is made until one is saved' );
$c1 = rrt_book_credit_save( $col, 'open', "Spells for a Kinder World.\nWritten by Robin.\nNarrated by Robin." );
$GLOBALS['ab_posts'][] = $c1;
$cp = get_post( $c1 );
ab_t( $c1 && RRT_CREDIT_TYPE === $cp->post_type && (int) $cp->post_parent === $col && 'open' === get_post_meta( $c1, RRT_CREDIT_KIND, true ), 'saving makes a credits post, a child of the collection' );
ab_t( 'Spells for a Kinder World.' === $cp->post_title && "Written by Robin.\n\nNarrated by Robin." === $cp->post_content, 'the first line is its title (read first in the studio), the rest its text' );
ab_t( "Spells for a Kinder World.\nWritten by Robin.\n\nNarrated by Robin." === rrt_book_credit_script( $col, 'open' ), 'and reads back as the script' );
ab_t( $c1 === rrt_book_credit_save( $col, 'open', "Spells for a Kinder World.\nWritten by Robin." ) && 'Written by Robin.' === get_post( $c1 )->post_content, 'saving again updates the same post' );
ab_t( $c1 === rrt_book_credit_save( $col, 'open', "  \n " ) && 0 === strpos( get_post( $c1 )->post_title, 'Spells for a Kinder World' ) && false !== strpos( get_post( $c1 )->post_content, 'Narrated by' ), 'an empty script goes back to the first wording' );
$boot = rrt_book_boot( $c1 );
ab_t( $boot['credit'] && $col === $boot['col'] && $boot['can'] && false !== strpos( $boot['colEdit'], 'post=' . $col ), 'the studio knows a credits post and its collection' );
ab_t( ! rrt_book_boot( $p1 )['credit'] && rrt_book_boot( $p1 )['have'] && abs( rrt_book_boot( $p1 )['head'] - 1.2 ) < 0.001, 'and a reading with its copy' );

$st = rrt_book_state( $col );
ab_t( 3 === count( $st['rows'] ) && 'book' === $st['rows'][0]['source'] && 'post' === $st['rows'][1]['source'] && 'need' === $st['rows'][2]['source'] && 'music' === $st['rows'][2]['why'], 'the box lists each reading\'s source' );
ab_t( '' === $st['rows'][0]['url'] && '' !== $st['rows'][1]['url'] && $st['rows'][0]['book']['url'], 'with the post\'s MP3 only where it is used, and the copy\'s address' );
ab_t( '01 The Turning Wheel A Spell for Rain.mp3' === $st['rows'][0]['file'] && '02 Happy Winter Solstice.mp3' === $st['rows'][1]['file'], 'file names keep plain letters and numbers', $st['rows'][0]['file'] );
ab_t( 4 === $st['closeN'] && $st['credits']['open']['id'] === $c1 && 0 === $st['credits']['close']['id'] && false !== strpos( $st['credits']['open']['studio'], 'tp_post=' . $c1 ), 'the credits, with the studio\'s address for each' );
ab_t( array( 'post' => $p1, 'start' => null, 'len' => 3 ) === $st['sample'], 'the sample starts as the first reading, after its title, about 3 minutes' );
ab_t( null === $st['cover'] && 1.2 === $st['rules']['head'] && 3.0 === $st['rules']['tail'] && 2400 === $st['rules']['coverMin'], 'no cover yet, and the rules' );

/*
 * The REST routes.
 */
$r = new WP_REST_Request( 'POST', '/rrt/v1/audiobook/col/' . $col );
$r->set_header( 'content-type', 'application/json' );
$r->set_body( wp_json_encode( array( 'sample' => array( 'post' => $p2, 'start' => 150, 'len' => 9 ), 'credits' => array( 'close' => "The end.\nThank you for listening." ) ) ) );
$res = rest_do_request( $r );
$d   = $res->get_data();
ab_t( 200 === $res->get_status() && array( 'post' => $p2, 'start' => 150.0, 'len' => 5 ) === $d['sample'], 'the sample\'s choices are saved, the length kept to 5 minutes', wp_json_encode( $d['sample'] ) );
ab_t( $d['credits']['close']['id'] > 0 && "The end.\nThank you for listening." === $d['credits']['close']['script'], 'the closing credits are saved from the box' );
$GLOBALS['ab_posts'][] = $d['credits']['close']['id'];
$r->set_body( wp_json_encode( array( 'sample' => array( 'post' => 424242, 'start' => -4 ) ) ) );
$d = rest_do_request( $r )->get_data();
ab_t( $p1 === $d['sample']['post'] && null === $d['sample']['start'], 'a reading outside the collection, or a time before the start, is not kept' );
$res = rest_do_request( new WP_REST_Request( 'GET', '/rrt/v1/audiobook/col/' . $p1 ) );
ab_t( 403 === $res->get_status(), 'a post that is not a collection has no audiobook' );

$up             = new WP_REST_Request( 'POST', '/rrt/v1/audiobook/' . $p2 . '/copy' );
$tmp            = ab_tmp( ab_frames( (int) round( 12 * $fps ), 192 ) );
$up->set_file_params( array( 'mp3' => array( 'tmp_name' => $tmp, 'name' => 'x.mp3', 'error' => 0, 'size' => filesize( $tmp ), 'type' => 'audio/mpeg' ) ) );
$up->set_param( 'from', 'take' );
$up->set_param( 'rms', '-19.9' );
$res = rest_do_request( $up );
ab_t( 200 === $res->get_status() && abs( $res->get_data()['book']['secs'] - 12 ) < 0.05 && 'book' === rrt_book_source( $p2 )['source'], 'the studio\'s upload keeps a copy for the post' );

$sub = wp_insert_user( array( 'user_login' => 'ab_sub_' . wp_generate_password( 4, false ), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
wp_set_current_user( $sub );
ab_t( 403 === rest_do_request( new WP_REST_Request( 'GET', '/rrt/v1/audiobook/col/' . $col ) )->get_status(), 'a subscriber cannot see a collection\'s audiobook' );
ab_t( 403 === rest_do_request( $up )->get_status(), 'nor keep a copy' );
ab_t( ! rrt_book_can( $p1 ), 'nor play one' );
wp_set_current_user( 0 );
ab_t( 401 === rest_do_request( new WP_REST_Request( 'GET', '/rrt/v1/audiobook/col/' . $col ) )->get_status(), 'a visitor gets nothing' );
wp_set_current_user( 1 );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $sub );

$del = rest_do_request( new WP_REST_Request( 'DELETE', '/rrt/v1/audiobook/' . $p2 . '/copy' ) );
ab_t( 200 === $del->get_status() && 'post' === rrt_book_source( $p2 )['source'] && ! get_post_meta( $p2, RRT_BOOK_META, true ), 'a copy can be removed' );

/*
 * Clearing up.
 */
$file = rrt_book_get( $p1 )['path'];
wp_delete_post( $p1, true );
ab_t( ! is_file( $file ), 'deleting a post deletes its audiobook copy' );
$close = rrt_book_credit( $col, 'close' );
wp_delete_post( $col, true );
ab_t( ! get_post( $c1 ) && ! get_post( $close->ID ), 'deleting a collection deletes its credits' );
