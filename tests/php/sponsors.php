<?php
/**
 * Sponsor messages (1.22.0), run inside the test WordPress:
 *   php wp-cli.phar --path=<wordpress> eval-file tests/php/sponsors.php
 * Prints one line per check and exits 1 if any fail. Leaves nothing behind.
 *
 * The MP3s are made here: real MPEG-1 Layer III frames whose side
 * information says how far back each frame's sound starts (the bit
 * reservoir), so the mid-roll's cut can be checked byte by byte. The
 * browser test (tests/browser/sponsors.py) decodes a real encoder's file.
 */

$GLOBALS['sp_pass']  = 0;
$GLOBALS['sp_fail']  = array();
$GLOBALS['sp_posts'] = array();
$GLOBALS['sp_files'] = array();

function sp_t( $ok, $name, $detail = '' ) {
	if ( $ok ) {
		++$GLOBALS['sp_pass'];
		echo "ok   $name\n";
	} else {
		$GLOBALS['sp_fail'][] = $name;
		echo "FAIL $name" . ( '' !== $detail ? ' (' . ( is_scalar( $detail ) ? $detail : wp_json_encode( $detail ) ) . ')' : '' ) . "\n";
	}
}

wp_set_current_user( 1 );
$sp_was_pc = get_option( 'rrt_podcast', null );
$sp_had_mf = is_dir( rrt_mfiles_dir() );
register_shutdown_function(
	function () use ( $sp_was_pc, $sp_had_mf ) {
		remove_all_filters( 'rrt_spot_today' );
		foreach ( array_reverse( $GLOBALS['sp_posts'] ) as $p ) {
			foreach ( get_children( array( 'post_parent' => $p, 'post_type' => 'attachment', 'numberposts' => -1, 'post_status' => 'any' ) ) as $a ) {
				wp_delete_attachment( $a->ID, true );
			}
			wp_delete_post( $p, true );
		}
		foreach ( $GLOBALS['sp_files'] as $f ) {
			if ( file_exists( $f ) ) {
				unlink( $f );
			}
		}
		$d = rrt_spot_dir();
		if ( is_dir( $d ) ) {
			foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $d, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $f ) {
				$f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
			}
			rmdir( $d );
		}
		/* The members folder, made by the check that a reading for members carries no messages. */
		if ( ! $sp_had_mf && is_dir( rrt_mfiles_dir() ) ) {
			foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( rrt_mfiles_dir(), FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $f ) {
				$f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
			}
			rmdir( rrt_mfiles_dir() );
		}
		if ( null === $sp_was_pc ) {
			delete_option( 'rrt_podcast' );
		} else {
			update_option( 'rrt_podcast', $sp_was_pc );
		}
		unset( $GLOBALS['rrt_spot_queue'] );
		if ( $GLOBALS['sp_fail'] ) {
			echo count( $GLOBALS['sp_fail'] ) . " failed\n";
			exit( 1 );
		}
		echo $GLOBALS['sp_pass'] . " passed\n";
	}
);

/**
 * Frames whose sound starts mdb( i ) bytes back, with a pattern in their
 * bytes so any byte out of place shows.
 */
function sp_frames( $n, $kbps, $ch, $seed, $mdb ) {
	$rate = 44100;
	$bri  = array_search( $kbps, rrt_col_bitrates(), true );
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
		$h    = 0xFFE00000 | ( 3 << 19 ) | ( 1 << 17 ) | ( 1 << 16 ) | ( $bri << 12 ) | ( 0 << 10 ) | ( $pad << 9 ) | ( $mode << 6 );
		$m    = is_callable( $mdb ) ? (int) $mdb( $i ) : (int) $mdb;
		$si   = chr( ( $m >> 1 ) & 255 ) . chr( ( ( $m & 1 ) << 7 ) | 0x11 ) . str_repeat( chr( 0x22 + ( $i & 7 ) ), $side - 2 );
		$body = $base + $pad - 4 - $side;
		$out .= pack( 'N', $h ) . $si . substr( str_repeat( chr( $seed ) . chr( $i & 255 ) . chr( ( $i >> 8 ) & 255 ), (int) ceil( $body / 3 ) ), 0, $body );
	}
	return $out;
}

function sp_mp3( $frames, $title ) {
	return rrt_col_id3( array( 'title' => $title ) ) . $frames;
}

function sp_attach( $post, $name, $bytes, $mime ) {
	$up   = wp_upload_dir();
	$path = trailingslashit( $up['path'] ) . 'sp-' . $post . '-' . wp_unique_filename( $up['path'], $name );
	file_put_contents( $path, $bytes );
	$GLOBALS['sp_files'][] = $path;
	$id = wp_insert_attachment( array( 'post_title' => $name, 'post_mime_type' => $mime, 'post_status' => 'inherit' ), $path, $post );
	update_attached_file( $id, $path );
	return (int) $id;
}

function sp_post( $title, $type = 'post', $status = 'publish' ) {
	$p                     = wp_insert_post( array( 'post_title' => $title, 'post_status' => $status, 'post_type' => $type, 'post_content' => '<p>Words.</p>' ) );
	$GLOBALS['sp_posts'][] = $p;
	return $p;
}

/** A reading with its loudness measured and its pauses found. */
function sp_reading( $title, $frames, $pauses, $lufs = -19.0, $vtt = '' ) {
	$p = sp_post( $title );
	update_post_meta( $p, 'rrt_audio', sp_attach( $p, 'reading.mp3', sp_mp3( $frames, $title ), 'audio/mpeg' ) );
	if ( '' !== $vtt ) {
		update_post_meta( $p, 'rrt_captions', sp_attach( $p, 'reading.vtt', $vtt, 'text/vtt' ) );
	}
	$key = rrt_col_file_key( rrt_col_reading_path( $p ) );
	update_post_meta( $p, RRT_LEVEL_META, array( 'key' => $key, 'lufs' => $lufs, 'at' => time() ) );
	if ( null !== $pauses ) {
		update_post_meta( $p, RRT_SPOT_PAUSES, array( 'key' => $key, 'list' => $pauses ) );
	}
	return $p;
}

/** A message with its recording, and a copy for each need given. */
function sp_spot( $title, $who, $choices, $frames, $needs = array(), $order = 0 ) {
	$s = sp_post( $title, RRT_SPOT_TYPE );
	wp_update_post( array( 'ID' => $s, 'menu_order' => $order ) );
	update_post_meta( $s, RRT_SPOT_META, rrt_spot_clean( array_merge( array( 'who' => $who, 'slots' => array( 'pre', 'mid', 'post' ) ), $choices ) ) );
	update_post_meta( $s, 'rrt_audio', sp_attach( $s, 'message.mp3', sp_mp3( $frames, $who ), 'audio/mpeg' ) );
	foreach ( $needs as $need ) {
		sp_copy( $s, $need, $frames );
	}
	return $s;
}

function sp_copy( $s, $need, $frames ) {
	$a = sp_attach( $s, 'copy.mp3', sp_mp3( $frames, 'copy' ), 'audio/mpeg' );
	update_post_meta( $a, RRT_SPOT_COPY, array( 'need' => $need, 'src' => rrt_spot_src_key( $s ), 'lu' => -19 ) );
	return $a;
}

function sp_settings( $over ) {
	$s = rrt_podcast_settings();
	update_option( 'rrt_podcast', array_merge( $s, $over ) );
}

function sp_feed( $m = null ) {
	ob_start();
	rrt_podcast_render( $m );
	return ob_get_clean();
}

function sp_item( $xml, $title ) {
	foreach ( explode( '<item>', $xml ) as $chunk ) {
		if ( false !== strpos( $chunk, '<title>' . $title . '</title>' ) ) {
			return $chunk;
		}
	}
	return '';
}

$fl = 1152 / 44100;

/*
 * ---------------------------------------------------------------------
 * Frames and the cut (pure)
 * ---------------------------------------------------------------------
 */

$f   = sp_frames( 3, 128, 1, 9, function ( $i ) { return array( 0, 300, 511 )[ $i ]; } );
$i0  = rrt_spot_frame_info( substr( $f, 0, 8 ) );
$len = $i0['len'];
$i1  = rrt_spot_frame_info( substr( $f, $len, 8 ) );
sp_t( 0 === $i0['mdb'] && 300 === $i1['mdb'] && 17 === $i0['side'] && 0 === $i0['crc'], 'a frame\'s back-reference is read from its side information', array( $i0, $i1 ) );
sp_t( $len - 21 === $i0['space'], 'a mono frame holds its length less the header and 17 bytes of side information as main data' );
$st = rrt_spot_frame_info( substr( sp_frames( 1, 128, 2, 1, 7 ), 0, 8 ) );
sp_t( 32 === $st['side'] && 7 === $st['mdb'], 'a stereo frame has 32 bytes of side information' );
$crc = substr( $f, 0, 1 ) . chr( ord( $f[1] ) & 0xFE ) . substr( $f, 2, $len - 2 );
sp_t( 2 === rrt_spot_frame_info( $crc )['crc'], 'a frame with a CRC is noticed' );

$one   = substr( $f, $len, $i1['len'] );
$muted = rrt_spot_mute( $one );
sp_t( strlen( $muted ) === strlen( $one ) && substr( $muted, 0, 4 ) === substr( $one, 0, 4 ), 'a muted frame keeps its header and length' );
sp_t( str_repeat( "\0", 17 ) === substr( $muted, 4, 17 ) && 0 === rrt_spot_frame_info( $muted )['mdb'], 'its side information becomes zeros, so it reads nothing back and decodes as silence' );
sp_t( substr( $muted, 21 ) === substr( $one, 21 ), 'its main data bytes stay, for the next frame to read back' );

$sp128 = array( 'space' => 396, 'mdb' => 0 );
sp_t( 1 === rrt_spot_bridge_count( array( $sp128, $sp128 ), array( array( 'mdb' => 300, 'space' => 396 ) ) ), 'a back-reference of 300 bytes at 128 kbps needs one frame copied after the message' );
sp_t( 3 === rrt_spot_bridge_count( array( array( 'space' => 187 ), array( 'space' => 187 ), array( 'space' => 187 ), array( 'space' => 187 ) ), array( array( 'mdb' => 400, 'space' => 187 ) ) ), 'a back-reference of 400 bytes at 64 kbps needs three' );
sp_t( 1 === rrt_spot_bridge_count( array( $sp128, $sp128 ), array( array( 'mdb' => 100, 'space' => 396 ), array( 'mdb' => 500, 'space' => 396 ) ) ), 'a frame after the cut reaching back past it counts too' );
sp_t( 0 === rrt_spot_bridge_count( array( $sp128 ), array( array( 'mdb' => 0, 'space' => 396 ) ) ), 'a frame that reads nothing back needs no copied frames' );
sp_t( -1 === rrt_spot_bridge_count( array( $sp128 ), array( array( 'mdb' => 511, 'space' => 396 ) ) ), 'too few frames before the cut says so' );

$map = array( 'pre' => 4.0, 'cut' => 30.0, 'mid' => 5.5 );
sp_t( 14.0 === rrt_spot_map( 10, $map ) && 39.5 === rrt_spot_map( 30, $map ) && 4.0 === rrt_spot_map( 0, $map ), 'a reading\'s time moves by the pre-roll, and by the mid-roll from the cut on' );
sp_t( 10.0 === rrt_spot_map( 10, array( 'pre' => 0, 'cut' => null, 'mid' => 0 ) ), 'no messages, no move' );

/*
 * ---------------------------------------------------------------------
 * Pauses, times, words (pure)
 * ---------------------------------------------------------------------
 */

$pz = rrt_spot_clean_pauses( array( array( 's' => 50, 'e' => 50.9 ), array( 's' => 10, 'e' => 10.4 ), array( 20, 21 ), array( 's' => 'x' ) ) );
sp_t( 2 === count( $pz ) && 20.0 === $pz[0]['s'] && 50.0 === $pz[1]['s'], 'pauses are kept in order and only from 0.6 s', $pz );
$list = array();
for ( $t = 10; $t < 600; $t += 20 ) {
	$list[] = array( 's' => $t, 'e' => $t + ( 290 === $t ? 1.8 : ( 330 === $t ? 1.2 : 0.7 ) ) );
}
$sg = rrt_spot_pause_suggest( $list, 600 );
sp_t( 3 === count( $sg ) && in_array( 290.9, wp_list_pluck( $sg, 't' ), true ) && in_array( 330.6, wp_list_pluck( $sg, 't' ), true ), 'the longest pauses near the middle are offered', $sg );
sp_t( $sg[0]['t'] < $sg[1]['t'] && $sg[1]['t'] < $sg[2]['t'], 'in time order' );
$edge = rrt_spot_pause_suggest( array( array( 's' => 20, 'e' => 21 ), array( 's' => 560, 'e' => 561 ) ), 600 );
sp_t( 2 === count( $edge ), 'with no pause near the middle, the nearest are offered' );
sp_t( array() === rrt_spot_pause_suggest( array( array( 's' => 1, 'e' => 2 ) ), 600 ), 'a pause in the first five seconds is never offered' );
$snap = rrt_spot_pause_snap( $list, 411, 600 );
sp_t( $snap && 410 === (int) $snap['s'], 'a typed time inside a pause snaps to it', $snap );
$snap = rrt_spot_pause_snap( $list, 405, 600 );
sp_t( $snap && 410 === (int) $snap['s'], 'between pauses, to the nearest edge', $snap );

sp_t( 432 === rrt_spot_parse_time( '7:12' ) && 3725 === rrt_spot_parse_time( '1:02:05' ) && null === rrt_spot_parse_time( 'later' ), 'times read as m:ss and h:mm:ss' );
sp_t( '7:12' === rrt_spot_clock( 432.4 ) && '1:02:05' === rrt_spot_clock( 3725 ), 'times written as m:ss and h:mm:ss' );

$vtt = "WEBVTT\n\n1\n00:00:00.500 --> 00:00:03.000\nThis reading is supported by Acme.\n\n2\n00:00:12.000 --> 00:00:14.000\nA paid word late on.\n";
sp_t( true === rrt_spot_disclosure_in( $vtt ), 'a disclosure in the first seconds is heard' );
sp_t( false === rrt_spot_disclosure_in( "WEBVTT\n\n1\n00:00:00.500 --> 00:00:03.000\nHello there friends.\n\n2\n00:00:12.000 --> 00:00:14.000\nThis is an ad.\n" ), 'one only after ten seconds is not' );
sp_t( false === rrt_spot_disclosure_in( "WEBVTT\n\n1\n00:00:00.500 --> 00:00:03.000\nAdd salt and adore it.\n" ), '"ad" inside other words is not a disclosure' );

$c = rrt_spot_clean( array( 'who' => ' Acme <b>Co</b> ', 'kind' => 'own', 'link' => 'javascript:alert(1)', 'start' => '2026-02-30', 'end' => '2026-12-01', 'slots' => array( 'mid', 'evil' ), 'where' => 'nowhere', 'posts' => array( '3', 'x' ) ) );
sp_t( 'Acme Co' === $c['who'] && 'own' === $c['kind'] && '' === $c['link'] && '' === $c['start'] && '2026-12-01' === $c['end'] && array( 'mid' ) === $c['slots'] && 'all' === $c['where'] && array( 3 ) === $c['posts'], 'a message\'s choices are made safe', $c );
sp_t( '128-44100-1|-19.0' === rrt_spot_need_key( array( 'kbps' => 128, 'rate' => 44100, 'ch' => 1 ), -19.1 ) && '128-44100-1|-19.5' === rrt_spot_need_key( array( 'kbps' => 128, 'rate' => 44100, 'ch' => 1 ), -19.3 ), 'readings within a quarter of a unit share a copy' );

/*
 * ---------------------------------------------------------------------
 * Off by default
 * ---------------------------------------------------------------------
 */

delete_option( 'rrt_podcast' );
$d = rrt_podcast_settings();
sp_t( false === $d['s_on'] && 10 === $d['s_mid_min'] && array( 'prose', 'story' ) === $d['s_kinds'] && true === $d['s_chapters'] && false === $d['s_thank'] && false === $d['s_members'], 'a new install has sponsor messages off, mid-rolls from 10 minutes in prose and story, chapters on' );

/* A 70 second reading at 128 kbps mono: every frame but the first reads back 450 bytes, more than one frame holds. */
$nfr     = (int) round( 70 / $fl );
$rframes = sp_frames( $nfr, 128, 1, 7, function ( $i ) { return 0 === $i ? 0 : 450; } );
$pauses  = array();
for ( $t = 3; $t < 68; $t += 4 ) {
	$pauses[] = array( 's' => $t, 'e' => $t + ( 35 === $t ? 1.6 : 0.8 ) );
}
$capt = "WEBVTT\n\n1\n00:00:01.000 --> 00:00:02.500\nThe first words.\n\n2\n00:00:40.000 --> 00:00:42.000\nWords after the middle.\n";
$r    = sp_reading( 'Sponsor test reading', $rframes, $pauses, -19.0, $capt );
$info = rrt_spot_reading( $r );
$need = rrt_spot_need_key( $info['scan'], $info['lu'] );
$mfr  = sp_frames( 115, 128, 1, 3, function ( $i ) { return 0 === $i ? 0 : 200; } );
$s1   = sp_spot( 'Acme spring', 'Acme', array( 'kind' => 'paid', 'link' => 'https://example.com/acme' ), $mfr, array( $need ) );
unset( $GLOBALS['rrt_spot_queue'] );

sp_t( array() === rrt_spot_plan( $r )['slots'], 'while sponsor messages are off, no reading gets any' );
sp_t( null === rrt_spot_sync( $r ) && ! rrt_spot_built( $r ), 'and no copy is made' );
$x = sp_feed();
sp_t( false !== strpos( sp_item( $x, 'Sponsor test reading' ), 'sp-' . $r . '-reading' ) && false === strpos( $x, 'rrt-sponsored' ), 'the feed points at the reading itself' );

/*
 * ---------------------------------------------------------------------
 * The plan
 * ---------------------------------------------------------------------
 */

sp_settings( array( 's_on' => true, 's_mid_min' => 1 ) );
$plan = rrt_spot_plan( $r );
sp_t( array( 'pre', 'mid', 'post' ) === array_keys( $plan['slots'] ) && $s1 === $plan['slots']['pre']['spot'], 'with them on, the message takes every slot it allows', array_keys( $plan['slots'] ) );
sp_t( $plan['mid'] && abs( $plan['mid']['t'] - 35.8 ) < 0.01, 'the mid-roll goes in the longest pause near the middle', $plan['mid'] );
update_post_meta( $r, RRT_SPOT_POST, array( 'mid' => 27.5 ) );
sp_t( abs( rrt_spot_plan( $r )['mid']['t'] - 27.4 ) < 0.01, 'a chosen time snaps to its pause', rrt_spot_plan( $r )['mid'] );
delete_post_meta( $r, RRT_SPOT_POST );

sp_settings( array( 's_mid_min' => 5 ) );
sp_t( 'short' === rrt_spot_plan( $r )['mid_why'] && ! isset( rrt_spot_plan( $r )['slots']['mid'] ) && isset( rrt_spot_plan( $r )['slots']['post'] ), 'a reading shorter than the mid-roll minimum keeps its pre-roll and post-roll only' );
sp_settings( array( 's_mid_min' => 1 ) );
rrt_save_kind( $r, 'ritual' );
sp_t( 'kind' === rrt_spot_plan( $r )['mid_why'], 'ritual and chant takes no mid-roll by default' );
rrt_save_kind( $r, 'verse' );
sp_t( 'kind' === rrt_spot_plan( $r )['mid_why'], 'nor does verse' );
rrt_save_kind( $r, 'story' );
sp_t( '' === rrt_spot_plan( $r )['mid_why'], 'story does' );
rrt_save_kind( $r, '' );
sp_t( '' === rrt_spot_plan( $r )['mid_why'], 'and a reading with no kind counts as prose' );
update_post_meta( $r, '_rrt_music_used', array( 'audio' => (int) get_post_meta( $r, 'rrt_audio', true ), 'bed' => 1 ) );
sp_t( 'music' === rrt_spot_plan( $r )['mid_why'] && isset( rrt_spot_plan( $r )['slots']['pre'] ), 'a reading with the music bed takes no mid-roll, and still a pre-roll' );
delete_post_meta( $r, '_rrt_music_used' );
$kp = get_post_meta( $r, RRT_SPOT_PAUSES, true );
delete_post_meta( $r, RRT_SPOT_PAUSES );
sp_t( 'pauses' === rrt_spot_plan( $r )['mid_why'], 'until its pauses are found, a reading waits for its mid-roll' );
update_post_meta( $r, RRT_SPOT_PAUSES, array( 'key' => 'old', 'list' => $kp['list'] ) );
sp_t( 'pauses' === rrt_spot_plan( $r )['mid_why'], 'pauses found in an older file do not count' );
update_post_meta( $r, RRT_SPOT_PAUSES, $kp );
update_post_meta( $r, RRT_SPOT_POST, array( 'off' => 1 ) );
sp_t( array() === rrt_spot_plan( $r )['slots'], 'No sponsor messages on this reading keeps it clean' );
delete_post_meta( $r, RRT_SPOT_POST );

add_filter( 'rrt_spot_today', function () { return '2026-03-10'; } );
update_post_meta( $s1, RRT_SPOT_META, array_merge( rrt_spot_get( $s1 ), array( 'start' => '2026-03-11', 'end' => '2026-03-20' ) ) );
sp_t( 'early' === rrt_spot_runs( $s1 ) && array() === rrt_spot_plan( $r )['slots'], 'a message before its first day runs nowhere' );
remove_all_filters( 'rrt_spot_today' );
add_filter( 'rrt_spot_today', function () { return '2026-03-20'; } );
sp_t( '' === rrt_spot_runs( $s1 ), 'on its last day it runs' );
remove_all_filters( 'rrt_spot_today' );
add_filter( 'rrt_spot_today', function () { return '2026-03-21'; } );
sp_t( 'over' === rrt_spot_runs( $s1 ), 'after it, it does not' );
remove_all_filters( 'rrt_spot_today' );
update_post_meta( $s1, RRT_SPOT_META, array_merge( rrt_spot_get( $s1 ), array( 'start' => '', 'end' => '' ) ) );

update_post_meta( $s1, RRT_SPOT_META, array_merge( rrt_spot_get( $s1 ), array( 'where' => 'posts', 'posts' => array( 999999 ) ) ) );
sp_t( array() === rrt_spot_plan( $r )['slots'], 'a message for other readings stays off this one' );
$cat = wp_insert_term( 'Sponsor test category', 'category' );
update_post_meta( $s1, RRT_SPOT_META, array_merge( rrt_spot_get( $s1 ), array( 'where' => 'cats', 'cats' => array( $cat['term_id'] ) ) ) );
sp_t( array() === rrt_spot_plan( $r )['slots'], 'a message for a category the reading is not in stays off' );
wp_set_post_categories( $r, array( $cat['term_id'] ) );
sp_t( isset( rrt_spot_plan( $r )['slots']['pre'] ), 'and goes on once it is' );
wp_set_post_categories( $r, array() );
wp_delete_term( $cat['term_id'], 'category' );
update_post_meta( $s1, RRT_SPOT_META, array_merge( rrt_spot_get( $s1 ), array( 'where' => 'all', 'cats' => array() ) ) );

$s2 = sp_spot( 'House message', 'Our shop', array( 'kind' => 'own', 'slots' => array( 'pre' ) ), $mfr, array( $need ), -1 );
sp_t( $s2 === rrt_spot_plan( $r )['slots']['pre']['spot'] && $s1 === rrt_spot_plan( $r )['slots']['post']['spot'], 'the message with the lower Order wins the slot they share; the other keeps the rest' );
wp_update_post( array( 'ID' => $s2, 'menu_order' => 5 ) );
sp_t( $s1 === rrt_spot_plan( $r )['slots']['pre']['spot'], 'and the order can change' );
wp_update_post( array( 'ID' => $s2, 'post_status' => 'draft' ) );

$s3 = sp_spot( 'Uncopied', 'Nobody', array( 'slots' => array( 'pre' ) ), $mfr, array(), -5 );
$pl = rrt_spot_plan( $r );
sp_t( in_array( $s3, $pl['waiting'], true ) && $s1 === $pl['slots']['pre']['spot'], 'a message with no copy in the reading\'s sound waits, and the next one plays', $pl['waiting'] );
wp_update_post( array( 'ID' => $s3, 'post_status' => 'draft' ) );
unset( $GLOBALS['rrt_spot_queue'] );

/*
 * ---------------------------------------------------------------------
 * The sponsored copy
 * ---------------------------------------------------------------------
 */

$b = rrt_spot_sync( $r );
$path = $b ? rrt_spot_dir() . '/' . $b['rel'] : '';
sp_t( is_array( $b ) && is_file( $path ) && preg_match( '/^' . $r . '\/sponsor-test-reading-sp[0-9a-f]{8}\.mp3$/', $b['rel'] ), 'the sponsored copy is written with a new name in rrt-sponsored', $b ? $b['rel'] : '' );
$scan  = rrt_col_scan( $path );
$mscan = rrt_col_scan_cached( rrt_spot_copy_for( $s1, $need )['path'] );
$gapf  = rrt_col_gap_frames( RRT_SPOT_GAP, 44100 );
$midf  = rrt_spot_gapf_mid( 44100 );
$nb    = (int) $b['bridge'];
$want  = $mscan['frames'] * 3 + $gapf * 2 + $midf * 2 + $nb + $nfr;
sp_t( $scan['ok'] && $want === $scan['frames'], 'it holds the three messages, the silences, the copied frames and every frame of the reading', array( $scan['frames'], $want ) );
sp_t( 2 === $nb, 'frames reading back 450 bytes at 128 kbps need two frames copied after the mid-roll', $nb );
sp_t( abs( $b['secs'] - $want * $fl ) < 0.01, 'its length is counted from its frames' );
$out = (string) file_get_contents( $path );
$aud = substr( $out, $scan['start'] );
$k   = (int) floor( $plan['mid']['t'] / $fl );
sp_t( abs( $b['cut'] - $k * $fl ) < 0.001 && $k * $fl > 35 && $k * $fl < 36.6, 'the cut is on a frame inside the pause', $b['cut'] );
/* Byte offsets of the reading's frames. */
$offs = array();
for ( $i = 0, $o = 0; $i <= $nfr; $i++ ) {
	$offs[] = $o;
	if ( $i < $nfr ) {
		$o += rrt_col_header( substr( $rframes, $o, 4 ) )['len'];
	}
}
$mbytes = $mscan['end'] - $mscan['start'];
$sil    = rrt_col_silence( $info['scan'], $gapf );
sp_t( substr( $aud, $mbytes, strlen( $sil ) ) === $sil, 'the pre-roll is followed by silence' );
$p1 = $mbytes + strlen( $sil );
sp_t( substr( $aud, $p1, $offs[ $k ] ) === substr( $rframes, 0, $offs[ $k ] ), 'then the reading up to the cut, byte for byte' );
$p2 = $p1 + $offs[ $k ] + 2 * strlen( rrt_col_silence( $info['scan'], $midf ) ) + $mbytes;
$br = '';
for ( $i = $k - $nb; $i < $k; $i++ ) {
	$br .= rrt_spot_mute( substr( $rframes, $offs[ $i ], $offs[ $i + 1 ] - $offs[ $i ] ) );
}
sp_t( substr( $aud, $p2, strlen( $br ) ) === $br, 'after the mid-roll, the frames before the cut again, muted with their bytes kept' );
sp_t( substr( $aud, $p2 + strlen( $br ), $offs[ $nfr ] - $offs[ $k ] ) === substr( $rframes, $offs[ $k ] ), 'then the rest of the reading, byte for byte' );
sp_t( substr( $rframes, $offs[ $k ] - $nb * 0 - strlen( $br ), strlen( $br ) ) !== $br, 'the copied frames are not the originals (their side information is zeroed)' );

$ch = json_decode( (string) file_get_contents( rrt_spot_dir() . '/' . $b['json'] ), true );
$ti = wp_list_pluck( $ch['chapters'], 'title' );
sp_t( array( 'A word from Acme', 'Sponsor test reading', 'A word from Acme', 'Sponsor test reading', 'A word from Acme' ) === $ti, 'the chapters: a word from the sponsor, the reading, the mid-roll, the reading again, the post-roll', $ti );
sp_t( 0 == $ch['chapters'][0]['startTime'] && abs( $ch['chapters'][1]['startTime'] - $b['map']['pre'] ) < 0.01 && 'https://example.com/acme' === $ch['chapters'][0]['url'], 'the first starts at 0 with the sponsor\'s link, the reading when the pre-roll ends' );
sp_t( abs( $ch['chapters'][3]['startTime'] - rrt_spot_map( $b['map']['cut'], $b['map'] ) ) < 0.01, 'the reading carries on where it resumes after the mid-roll' );
$vt = (string) file_get_contents( rrt_spot_dir() . '/' . $b['vtt'] );
$cues = rrt_col_vtt_cues( $vt );
$late = null;
$early = null;
$msg = 0;
foreach ( $cues as $cu ) {
	if ( 'Words after the middle.' === $cu['text'] ) {
		$late = $cu;
	}
	if ( 'The first words.' === $cu['text'] ) {
		$early = $cu;
	}
	if ( 'Sponsor message: Acme' === $cu['text'] ) {
		++$msg;
	}
}
sp_t( $early && abs( $early['start'] - ( 1 + $b['map']['pre'] ) ) < 0.01, 'captions before the cut move by the pre-roll', $early );
sp_t( $late && abs( $late['start'] - ( 40 + $b['map']['pre'] + $b['map']['mid'] ) ) < 0.01, 'captions after it move by the mid-roll too', $late );
sp_t( 3 === $msg, 'each message has a caption saying what it is' );
$tag = rrt_id3_chapters( $path );
sp_t( 5 === count( $tag ) && 'A word from Acme' === $tag[0]['title'], 'the copy\'s own tag carries the same chapters' );

/*
 * ---------------------------------------------------------------------
 * The feed
 * ---------------------------------------------------------------------
 */

$x  = sp_feed();
$it = sp_item( $x, 'Sponsor test reading' );
sp_t( false !== strpos( $it, '<enclosure url="' . esc_url( rrt_spot_url( $b['rel'] ) ) . '" length="' . $b['bytes'] . '"' ), 'the feed\'s enclosure is the sponsored copy, with its length', $it );
sp_t( false !== strpos( $it, '<itunes:duration>' . (int) round( $b['secs'] ) . '</itunes:duration>' ), 'and its duration' );
sp_t( false !== strpos( $it, '<guid isPermaLink="false">' . wp_parse_url( home_url(), PHP_URL_HOST ) . ':reading:' . $r . '</guid>' ), 'the episode keeps its GUID' );
sp_t( false !== strpos( $it, '<description>Sponsored by Acme: https://example.com/acme' ), 'the notes start with the sponsor line and link' );
sp_t( false !== strpos( $it, 'rel=&quot;sponsored&quot;' ), 'the link in the notes is marked sponsored' );
sp_t( false !== strpos( $it, esc_url( rrt_spot_url( $b['vtt'] ) ) ) && false !== strpos( $it, esc_url( rrt_spot_url( $b['json'] ) ) ), 'the transcript and chapters are the sponsored copy\'s' );

sp_settings( array( 'count' => true ) );
$x  = sp_feed();
$it = sp_item( $x, 'Sponsor test reading' );
sp_t( false !== strpos( $it, esc_url( rrt_dl_url( $r, rrt_spot_url( $b['rel'] ) ) ) ), 'with downloads counted, the short address carries the copy\'s name' );
$tg = rrt_dl_target( $r, wp_basename( $b['rel'] ) );
sp_t( $tg && rrt_spot_url( $b['rel'] ) === $tg['url'], 'and leads to the copy' );
$tg = rrt_dl_target( $r, 'reading.mp3' );
sp_t( $tg && false !== strpos( $tg['url'], 'sp-' . $r . '-reading' ), 'while the reading\'s own name leads to the reading' );
sp_settings( array( 'count' => false ) );

$uid = get_current_user_id();
sp_settings( array( 'm_on' => true ) );
$mm = array( 'uid' => $uid, 'key' => rrt_mfeed_key( $uid ), 'member' => true );
sp_t( false === strpos( sp_item( sp_feed( $mm ), 'Sponsor test reading' ), 'rrt-sponsored' ), 'member feeds carry the reading without messages' );
sp_settings( array( 's_members' => true ) );
sp_t( false !== strpos( sp_item( sp_feed( $mm ), 'Sponsor test reading' ), esc_url( rrt_spot_url( $b['rel'] ) ) ), 'unless Members hear sponsor messages too is on' );
sp_settings( array( 's_members' => false, 'm_on' => false ) );
delete_user_meta( $uid, RRT_MFEED_META );
delete_user_meta( $uid, RRT_MFEED_AT_META );

sp_t( '' === rrt_spot_thank_html( $r ), 'no thank-you line on the post unless it is switched on' );
sp_settings( array( 's_thank' => true ) );
$th = rrt_spot_thank_html( $r );
sp_t( false !== strpos( $th, 'rel="sponsored noopener"' ) && false !== strpos( $th, '>Acme</a>' ), 'the thank-you line names the sponsor, with the link marked sponsored', $th );
sp_settings( array( 's_thank' => false ) );

/*
 * ---------------------------------------------------------------------
 * Changes: a new name, the old file kept two days, dates ending
 * ---------------------------------------------------------------------
 */

$same = rrt_spot_sync( $r );
sp_t( $same['rel'] === $b['rel'] && $same['at'] === $b['at'], 'nothing changed, nothing made again' );
wp_update_post( array( 'ID' => $r, 'post_content' => '<p>Words, with a typo fixed.</p>' ) );
unset( $GLOBALS['rrt_spot_queue'] );
$same = rrt_spot_sync( $r );
sp_t( $same['rel'] === $b['rel'], 'editing the post\'s text does not make a new file' );
update_post_meta( $s1, RRT_SPOT_META, array_merge( rrt_spot_get( $s1 ), array( 'who' => 'Acme and Co' ) ) );
$b2 = rrt_spot_sync( $r );
sp_t( $b2['rel'] !== $b['rel'] && is_file( rrt_spot_dir() . '/' . $b2['rel'] ), 'a change makes a copy with a new name', $b2['rel'] );
sp_t( is_file( $path ) && 1 === count( $b2['old'] ) && in_array( $b['rel'], $b2['old'][0]['rels'], true ), 'the old copy is kept for apps still playing it' );
rrt_spot_trim( $r, time() + DAY_IN_SECONDS );
sp_t( is_file( $path ), 'a day later it is still there' );
$tg = rrt_dl_target( $r, wp_basename( $b['rel'] ) );
sp_t( $tg && rrt_spot_url( $b['rel'] ) === $tg['url'], 'and the old short address still leads to it' );
rrt_spot_trim( $r, time() + 3 * DAY_IN_SECONDS );
sp_t( ! is_file( $path ) && is_file( rrt_spot_dir() . '/' . $b2['rel'] ) && ! is_file( rrt_spot_dir() . '/' . $b['vtt'] ), 'after two days it goes, and the current copy stays' );
$tg = rrt_dl_target( $r, wp_basename( $b['rel'] ) );
sp_t( $tg && rrt_spot_url( $b2['rel'] ) === $tg['url'], 'an old short address then leads to the current copy' );

$log = get_post_meta( $s1, RRT_SPOT_LOG, true );
sp_t( is_array( $log ) && 3 === count( $log ) && 0 === (int) $log[0]['to'], 'the message\'s log shows it running in three slots of the reading', $log );

/* The editor saves a post's boxes in a second request: a choice saved while a copy is being made wins. */
update_post_meta( $s1, RRT_SPOT_META, array_merge( rrt_spot_get( $s1 ), array( 'who' => 'Acme Ltd' ) ) );
$sp_race = function ( $mid, $pid, $k ) use ( $r ) {
	if ( (int) $pid === (int) $r && RRT_SPOT_BUILT === $k && ! did_action( 'sp_raced' ) ) {
		do_action( 'sp_raced' );
		update_post_meta( $r, RRT_SPOT_POST, array( 'off' => 1 ) );
	}
};
add_action( 'added_post_meta', $sp_race, 10, 3 );
add_action( 'updated_post_meta', $sp_race, 10, 3 );
$rr = rrt_spot_sync( $r );
remove_action( 'added_post_meta', $sp_race, 10 );
remove_action( 'updated_post_meta', $sp_race, 10 );
unset( $GLOBALS['rrt_spot_queue'] );
sp_t( null === $rr && ! rrt_spot_built( $r ), 'No sponsor messages saved while a copy was being made still takes the reading back', $rr );
delete_post_meta( $r, RRT_SPOT_POST );
update_post_meta( $s1, RRT_SPOT_META, array_merge( rrt_spot_get( $s1 ), array( 'who' => 'Acme and Co' ) ) );
$b2 = rrt_spot_sync( $r );
unset( $GLOBALS['rrt_spot_queue'] );
sp_t( is_array( $b2 ) && rrt_spot_built( $r ), 'and taking the tick off brings the copy back' );

add_filter( 'rrt_spot_today', function () { return '2030-01-01'; } );
update_post_meta( $s1, RRT_SPOT_META, array_merge( rrt_spot_get( $s1 ), array( 'end' => '2029-12-31' ) ) );
rrt_spot_sync_all();
remove_all_filters( 'rrt_spot_today' );
sp_t( ! rrt_spot_built( $r ) && is_file( rrt_spot_dir() . '/' . $b2['rel'] ), 'when its dates end, the hourly job takes the copy out of use, and keeps the file two days' );
sp_t( false === strpos( sp_item( sp_feed(), 'Sponsor test reading' ), 'rrt-sponsored' ), 'the feed goes back to the reading' );
$log = get_post_meta( $s1, RRT_SPOT_LOG, true );
sp_t( 0 < (int) $log[0]['to'], 'and the log closes' );
rrt_spot_trim( $r, time() + 3 * DAY_IN_SECONDS );
sp_t( ! is_file( rrt_spot_dir() . '/' . $b2['rel'] ) && '' === (string) get_post_meta( $r, RRT_SPOT_BUILT, true ), 'two days on, its files and record are gone' );
update_post_meta( $s1, RRT_SPOT_META, array_merge( rrt_spot_get( $s1 ), array( 'end' => '', 'who' => 'Acme' ) ) );
$rep = rrt_spot_report( $s1 );
sp_t( 3 <= count( $rep ) && 'Sponsor test reading' === $rep[0]['title'] && null === $rep[0]['downloads'], 'the report lists where it ran, without downloads while they are not counted' );

/* A reading for members carries no messages. */
rrt_access_save( $r, 'members', 7 );
sp_t( 'members' === rrt_spot_reading( $r )['why'] && array() === rrt_spot_plan( $r )['slots'], 'a reading for members carries no messages' );
rrt_access_save( $r, 'everyone', 7 );
sp_t( isset( rrt_spot_plan( $r )['slots']['pre'] ), 'and carries them again once it is for everyone' );

/* Recording the message again makes its copies out of date. */
update_post_meta( $s1, 'rrt_audio', sp_attach( $s1, 'message2.mp3', sp_mp3( sp_frames( 90, 128, 1, 4, 0 ), 'Acme' ), 'audio/mpeg' ) );
sp_t( null === rrt_spot_copy_for( $s1, $need ) && in_array( $s1, rrt_spot_plan( $r )['waiting'], true ), 'a message recorded again needs new copies' );
unset( $GLOBALS['rrt_spot_queue'] );

/*
 * ---------------------------------------------------------------------
 * Routes
 * ---------------------------------------------------------------------
 */

$req = new WP_REST_Request( 'GET', '/rrt/v1/spots/' . $s1 . '/needs' );
$res = rest_do_request( $req );
$nd  = $res->get_data();
$row = null;
foreach ( $nd['needs'] as $n ) {
	if ( $n['need'] === $need ) {
		$row = $n;
	}
}
sp_t( 200 === $res->get_status() && $row && false === $row['have'] && in_array( 'Sponsor test reading', $row['titles'], true ) && '128 kbps mono at -19.0 LUFS' === $row['label'], 'the message\'s screen is told which copies to make, and for which readings', $row );

$tmp = wp_tempnam( 'sp.mp3' );
file_put_contents( $tmp, sp_mp3( sp_frames( 60, 192, 2, 4, 0 ), 'x' ) );
$req = new WP_REST_Request( 'POST', '/rrt/v1/spots/' . $s1 . '/copy' );
$req->set_param( 'need', $need );
$req->set_param( 'src', rrt_spot_src_key( $s1 ) );
$req->set_file_params( array( 'mp3' => array( 'name' => 'm.mp3', 'type' => 'audio/mpeg', 'tmp_name' => $tmp, 'error' => 0, 'size' => filesize( $tmp ) ) ) );
sp_t( 400 === rest_do_request( $req )->get_status(), 'a copy in another format is refused' );
$req->set_param( 'src', 'stale' );
sp_t( 409 === rest_do_request( $req )->get_status(), 'a copy made from an older recording is refused' );
file_put_contents( $tmp, sp_mp3( sp_frames( 90, 128, 1, 4, 0 ), 'x' ) );
$req = new WP_REST_Request( 'POST', '/rrt/v1/spots/' . $s1 . '/copy' );
$req->set_param( 'need', $need );
$req->set_param( 'src', rrt_spot_src_key( $s1 ) );
$req->set_param( 'lufs', -19.02 );
$req->set_file_params( array( 'mp3' => array( 'name' => 'm.mp3', 'type' => 'audio/mpeg', 'tmp_name' => $tmp, 'error' => 0, 'size' => filesize( $tmp ) ) ) );
$res = rest_do_request( $req );
sp_t( 200 === $res->get_status() && rrt_spot_copy_for( $s1, $need ), 'a copy in the reading\'s format is kept', $res->get_data() );
sp_t( 1 === count( rrt_spot_copies( $s1 ) ), 'and the copy from the older recording goes', count( rrt_spot_copies( $s1 ) ) );
unset( $GLOBALS['rrt_spot_queue'] );

$req = new WP_REST_Request( 'POST', '/rrt/v1/spots/pauses' );
$req->set_param( 'post', $r );
$req->set_param( 'key', 'stale' );
$req->set_param( 'pauses', array( array( 's' => 30, 'e' => 31 ) ) );
sp_t( 409 === rest_do_request( $req )->get_status(), 'pauses found in an older file are refused' );
$req->set_param( 'key', rrt_col_file_key( rrt_col_reading_path( $r ) ) );
$res = rest_do_request( $req );
sp_t( 200 === $res->get_status() && 1 === $res->get_data()['count'] && 1 === count( $res->get_data()['suggest'] ), 'pauses for the current file are kept, and the places to offer come back', $res->get_data() );
update_post_meta( $r, RRT_SPOT_PAUSES, $kp );
unset( $GLOBALS['rrt_spot_queue'] );

wp_set_current_user( 0 );
sp_t( 401 === rest_do_request( new WP_REST_Request( 'GET', '/rrt/v1/spots/' . $s1 . '/needs' ) )->get_status(), 'visitors cannot use the routes' );
wp_set_current_user( 1 );

/*
 * ---------------------------------------------------------------------
 * A collection: the mid-roll between two readings
 * ---------------------------------------------------------------------
 */

$col   = sp_post( 'Sponsor test collection', RRT_COL_TYPE );
$cfr   = sp_frames( (int) round( 30 / $fl ), 128, 1, 11, 0 );
update_post_meta( $col, 'rrt_audio', sp_attach( $col, 'collection.mp3', sp_mp3( $cfr, 'col' ), 'audio/mpeg' ) );
update_post_meta( $col, RRT_COL_META, array( 'items' => array(), 'gap' => 2, 'feed' => 1, 'type' => 'bonus' ) );
update_post_meta( $col, RRT_COL_BUILT, array( 'parts' => array( array( 'post' => 1, 'start' => 0, 'end' => 14 ), array( 'post' => 2, 'start' => 16, 'end' => 30 ) ) ) );
$ckey = rrt_col_file_key( rrt_col_reading_path( $col ) );
update_post_meta( $col, RRT_LEVEL_META, array( 'key' => $ckey, 'lufs' => -19.0, 'at' => time() ) );
$cinfo = rrt_spot_reading( $col );
sp_t( $cinfo['col'] && '' === rrt_spot_mid_why( $col, $cinfo ), 'a collection of two readings takes a mid-roll' );
$cm = rrt_spot_mid_at( $col, $cinfo );
sp_t( $cm && 15.0 === (float) $cm['t'], 'in the gap between them', $cm );
unset( $GLOBALS['rrt_spot_queue'] );
