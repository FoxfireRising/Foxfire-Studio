<?php
/**
 * Collections (1.19.0) on the server, run inside the test WordPress:
 *   php wp-cli.phar --path=<wordpress> eval-file tests/php/collections.php
 * Prints one line per check and exits 1 if any fail. Leaves nothing behind.
 *
 * The MP3s are made here: real MPEG-1 Layer III frames whose side information
 * is zero (so they decode to silence) and whose spare bytes carry a pattern
 * for each file, so a joined file can be checked byte for byte against the
 * readings it came from, with no encoder needed.
 */

$GLOBALS['cl_pass'] = 0;
$GLOBALS['cl_fail'] = array();

function cl_t( $ok, $name, $detail = '' ) {
	if ( $ok ) {
		++$GLOBALS['cl_pass'];
		echo "ok   $name\n";
	} else {
		$GLOBALS['cl_fail'][] = $name;
		echo "FAIL $name" . ( '' !== $detail ? ': ' . $detail : '' ) . "\n";
	}
}

wp_set_current_user( 1 );
$cl_podcast          = get_option( 'rrt_podcast', null );
$GLOBALS['cl_posts'] = array();
$GLOBALS['cl_files'] = array();
register_shutdown_function(
	function () use ( $cl_podcast ) {
		foreach ( $GLOBALS['cl_posts'] as $id ) {
			foreach ( get_children( array( 'post_parent' => $id, 'post_type' => 'attachment', 'numberposts' => -1 ) ) as $a ) {
				wp_delete_attachment( $a->ID, true );
			}
			wp_delete_post( $id, true );
		}
		foreach ( $GLOBALS['cl_files'] as $f ) {
			if ( is_file( $f ) ) {
				unlink( $f );
			}
		}
		if ( null === $cl_podcast ) {
			delete_option( 'rrt_podcast' );
		} else {
			update_option( 'rrt_podcast', $cl_podcast );
		}
		$GLOBALS['rrt_col_queue'] = array();
		if ( $GLOBALS['cl_fail'] ) {
			echo count( $GLOBALS['cl_fail'] ) . " failed\n";
			exit( 1 );
		}
		echo $GLOBALS['cl_pass'] . " passed\n";
	}
);

/** Frames of an MP3 with a pattern in their spare bytes. */
function cl_frames( $n, $kbps, $rate, $ch, $seed ) {
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
		$body = $base + $pad - 4 - $side;
		$out .= pack( 'N', $h ) . str_repeat( "\0", $side ) . substr( str_repeat( chr( $seed ) . chr( $i & 255 ) . chr( 0x5A ), (int) ceil( $body / 3 ) ), 0, $body );
	}
	return $out;
}

/** A whole MP3: a tag (with a picture to skip), the frames, and optionally an Info frame first or an ID3v1 tag last. */
function cl_mp3( $n, $seed, $opts = array() ) {
	$kbps = isset( $opts['kbps'] ) ? $opts['kbps'] : 128;
	$rate = isset( $opts['rate'] ) ? $opts['rate'] : 44100;
	$ch   = isset( $opts['ch'] ) ? $opts['ch'] : 1;
	$tag  = rrt_col_id3(
		array(
			'title' => 'Reading ' . $seed,
			'art'   => array(
				'mime' => 'image/jpeg',
				'data' => str_repeat( 'x', 3000 ),
			),
		)
	);
	$info = '';
	if ( ! empty( $opts['info'] ) ) {
		$f    = cl_frames( 1, $kbps, $rate, $ch, 0 );
		$at   = 1 === $ch ? 21 : 36;
		$info = substr( $f, 0, $at ) . 'Info' . substr( $f, $at + 4 );
	}
	$frames = cl_frames( $n, $kbps, $rate, $ch, $seed );
	$v1     = ! empty( $opts['v1'] ) ? 'TAG' . str_repeat( "\0", 125 ) : '';
	return array(
		'bytes'  => $tag . $info . $frames . $v1,
		'frames' => $frames,
	);
}

/** A file in uploads as an attachment of a post. */
function cl_attach( $post, $name, $bytes, $mime ) {
	$up   = wp_upload_dir();
	$path = trailingslashit( $up['path'] ) . 'cl-' . $post . '-' . $name;
	file_put_contents( $path, $bytes );
	$GLOBALS['cl_files'][] = $path;
	$id = wp_insert_attachment( array( 'post_title' => $name, 'post_mime_type' => $mime, 'post_status' => 'inherit' ), $path, $post );
	update_attached_file( $id, $path );
	return $id;
}

/** A published post with a reading. */
function cl_reading( $title, $bytes, $vtt = '' ) {
	$p                     = wp_insert_post( array( 'post_title' => $title, 'post_status' => 'publish', 'post_content' => '<p>Words.</p>' ) );
	$GLOBALS['cl_posts'][] = $p;
	$a          = cl_attach( $p, 'reading.mp3', $bytes, 'audio/mpeg' );
	update_post_meta( $p, 'rrt_audio', $a );
	if ( '' !== $vtt ) {
		update_post_meta( $p, 'rrt_captions', cl_attach( $p, 'reading.vtt', $vtt, 'text/vtt' ) );
	}
	return $p;
}

$fps = 44100 / 1152; // Frames a second at 44.1 kHz.

/*
 * ---------------------------------------------------------------------
 * Pure helpers
 * ---------------------------------------------------------------------
 */
cl_t( function_exists( 'rrt_col_build' ) && function_exists( 'rrt_col_scan' ) && post_type_exists( RRT_COL_TYPE ), 'the collection functions and post type are loaded' );
$pt = get_post_type_object( RRT_COL_TYPE );
cl_t( $pt && $pt->public && 'collections' === $pt->rewrite['slug'] && 'collections' === $pt->has_archive && ! $pt->show_in_rest, 'collections are public at /collections/, edited on the classic screen' );

$sil  = rrt_col_silence( array( 'kbps' => 128, 'rate' => 44100, 'ch' => 1 ), 77 );
$tmp  = wp_tempnam( 'cl-silence' );
$GLOBALS['cl_files'][] = $tmp;
file_put_contents( $tmp, $sil );
$s = rrt_col_scan( $tmp );
cl_t( $s['ok'] && 77 === $s['frames'] && 128 === $s['kbps'] && 1 === $s['ch'] && 0 === $s['start'] && strlen( $sil ) === $s['end'], 'silent frames scan as 77 frames of 128 kbps mono' );
cl_t( abs( strlen( $sil ) - 77 * 1152 / 44100 * 16000 ) < 2, 'silent frames keep the exact byte rate (padding as LAME does)', strlen( $sil ) );
cl_t( 77 === rrt_col_gap_frames( 2, 44100 ) && 0 === rrt_col_gap_frames( 0, 44100 ), 'a 2 s gap is 77 frames, none is 0' );
cl_t( 2.0 === rrt_col_clean_gap( '2.2' ) && 0.0 === rrt_col_clean_gap( '-1' ) && 5.0 === rrt_col_clean_gap( 9 ) && 1.5 === rrt_col_clean_gap( '1.5' ), 'the gap is kept to its choices' );

$m = cl_mp3( 100, 7, array( 'info' => true, 'v1' => true ) );
file_put_contents( $tmp, $m['bytes'] );
$s = rrt_col_scan( $tmp );
cl_t( $s['ok'] && 100 === $s['frames'] && substr( $m['bytes'], $s['start'], $s['end'] - $s['start'] ) === $m['frames'], 'the scan skips the tag, an Info frame and an ID3v1 tag, and finds exactly the audio frames' );
cl_t( abs( $s['secs'] - 100 / $fps ) < 1e-9, 'its length comes from its frames' );

$st = cl_mp3( 50, 8, array( 'kbps' => 192, 'ch' => 2 ) );
file_put_contents( $tmp, $st['bytes'] );
$s = rrt_col_scan( $tmp );
cl_t( $s['ok'] && 192 === $s['kbps'] && 2 === $s['ch'] && '192-44100-2' === rrt_col_fmt_key( $s ), 'a stereo 192 kbps file scans as such' );
cl_t( '192 kbps stereo' === rrt_col_fmt_label( $s ) && '128 kbps mono, 48 kHz' === rrt_col_fmt_label( array( 'kbps' => 128, 'rate' => 48000, 'ch' => 1 ) ), 'formats in words' );

$vbr = rrt_col_id3( array( 'title' => 'v' ) ) . cl_frames( 20, 128, 44100, 1, 3 ) . cl_frames( 20, 160, 44100, 1, 3 );
file_put_contents( $tmp, $vbr );
cl_t( 'vbr' === rrt_col_scan( $tmp )['error'], 'a file whose bitrate changes is refused as variable bitrate' );
$dam = cl_frames( 200, 128, 44100, 1, 4 ) . str_repeat( 'junk', 5000 ) . cl_frames( 200, 128, 44100, 1, 4 );
file_put_contents( $tmp, $dam );
cl_t( 'damaged' === rrt_col_scan( $tmp )['error'], 'a break in the middle is found' );
file_put_contents( $tmp, 'RIFF' . str_repeat( "\0", 70000 ) );
cl_t( 'notmp3' === rrt_col_scan( $tmp )['error'], 'a WAV is not an MP3' );
cl_t( 'missing' === rrt_col_scan( '/nonexistent.mp3' )['error'], 'a missing file says so' );
$pad = rrt_col_id3( array( 'title' => 'p' ) ) . str_repeat( "\0", 500 ) . cl_frames( 30, 128, 44100, 1, 5 );
file_put_contents( $tmp, $pad );
$s = rrt_col_scan( $tmp );
cl_t( $s['ok'] && 30 === $s['frames'], 'padding after the tag is passed over to the first frame' );

/* Times, chapters and captions. */
$times = rrt_col_times( array( array( 'frames' => 100 ), array( 'frames' => 50 ) ), 44100, 77 );
cl_t( 0.0 === (float) $times[0]['start'] && abs( $times[1]['start'] - 177 / $fps ) < 1e-9 && abs( $times[1]['next'] - 227 / $fps ) < 1e-9 && $times[0]['next'] === $times[1]['start'], 'each reading starts after the one before and its gap' );
$ch = rrt_col_chapters(
	array(
		array( 'title' => 'One', 'url' => 'u1', 'start' => 0, 'next' => 12, 'chapters' => array() ),
		array( 'title' => 'Two', 'url' => 'u2', 'start' => 12, 'next' => 40, 'chapters' => array( array( 'start' => 0, 'title' => 'Opening' ), array( 'start' => 10, 'title' => 'The middle', 'url' => 'u2#m' ) ) ),
		array( 'title' => 'Three', 'url' => 'u3', 'start' => 40, 'next' => 60, 'chapters' => array( array( 'start' => 5, 'title' => 'Late' ), array( 'start' => 9, 'title' => 'Later' ) ) ),
	)
);
$titles = wp_list_pluck( $ch, 'title' );
cl_t( array( 'One', 'Two', 'Two: The middle', 'Three', 'Three: Late', 'Three: Later' ) === $titles, 'chapters: one a reading, its own headings carried in after it', implode( ' | ', $titles ) );
cl_t( 22.0 === (float) $ch[2]['start'] && 'u2#m' === $ch[2]['url'] && 45.0 === (float) $ch[4]['start'] && 60.0 === (float) end( $ch )['end'], 'chapter times are moved to the reading\'s place' );
$runs = true;
foreach ( $ch as $i => $c ) {
	if ( $i && $ch[ $i - 1 ]['end'] !== $c['start'] ) {
		$runs = false;
	}
}
cl_t( $runs, 'the chapters run end to end' );
cl_t( 3725.5 === rrt_col_vtt_time( '01:02:05.500' ) && 65.25 === rrt_col_vtt_time( '01:05,25' ) && null === rrt_col_vtt_time( 'soon' ), 'caption times are read with or without hours, with a dot or comma' );
$vt = rrt_col_vtt_join(
	array(
		array( 'vtt' => "WEBVTT\n\nNOTE skip me\n\n1\n00:00:00.500 --> 00:00:02.000 align:start\nHello there.\n\n2\n00:00:03.000 --> 00:00:09.000\nRuns past the end.\n", 'start' => 0, 'end' => 5 ),
		array( 'vtt' => '', 'start' => 7, 'end' => 9 ),
		array( 'vtt' => "WEBVTT\r\n\r\n00:01.000 --> 00:02.500\r\nSecond reading.\r\n", 'start' => 10, 'end' => 20 ),
	)
);
cl_t( false !== strpos( $vt, "00:00:00.500 --> 00:00:02.000\nHello there." ) && false !== strpos( $vt, "00:00:03.000 --> 00:00:05.000\nRuns past the end." ) && false !== strpos( $vt, "3\n00:00:11.000 --> 00:00:12.500\nSecond reading." ) && false === strpos( $vt, 'skip me' ), 'captions are moved to each reading\'s place, kept inside it, and renumbered' );
cl_t( '' === rrt_col_vtt_join( array( array( 'vtt' => '', 'start' => 0, 'end' => 5 ) ) ), 'no captions anywhere means no captions file' );

/* The tag reads back through the feed's own reader, in UTF-16 where needed. */
$tag = rrt_col_id3(
	array(
		'title'    => 'Wheel of the Year ✶',
		'chapters' => array(
			array( 'start' => 0, 'end' => 5.5, 'title' => 'Samhain ✶ night', 'url' => 'https://example.com/samhain/' ),
			array( 'start' => 5.5, 'end' => 9, 'title' => 'Yule', 'url' => 'https://example.com/yülé/' ),
		),
	)
);
file_put_contents( $tmp, $tag . cl_frames( 10, 128, 44100, 1, 1 ) );
$rc = rrt_id3_chapters( $tmp );
cl_t( 2 === count( $rc ) && 'Samhain ✶ night' === $rc[0]['title'] && 5.5 === (float) $rc[1]['start'] && 'https://example.com/y%C3%BCl%C3%A9/' === $rc[1]['url'], 'the tag\'s chapters read back, with a title outside Latin-1 and a link made plain ASCII', wp_json_encode( $rc ) );

/*
 * ---------------------------------------------------------------------
 * A collection of three readings
 * ---------------------------------------------------------------------
 */
$vtt1 = "WEBVTT\n\n1\n00:00:00.200 --> 00:00:01.500\nFirst words.\n";
$m1   = cl_mp3( 400, 11, array( 'info' => true ) );
$m2   = cl_mp3( 300, 12, array( 'v1' => true ) );
$m3   = cl_mp3( 250, 13 );
$p1   = cl_reading( 'Samhain &amp; the Veil', $m1['bytes'], $vtt1 );
$p2   = cl_reading( 'Yule', $m2['bytes'] );
$p3   = cl_reading( 'Imbolc', $m3['bytes'], "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nThird words.\n" );
/* Yule has its own chapters, as the feed keeps them from an MP3's tag. */
update_post_meta(
	$p2,
	RRT_FEED_META,
	array(
		'key'      => rrt_feed_key( $p2 ),
		'chapters' => array(
			array( 'start' => 0.0, 'end' => 3.0, 'title' => 'Yule' ),
			array( 'start' => 3.0, 'end' => 7.8, 'title' => 'The long night' ),
		),
		'top'      => null,
	)
);

$col = wp_insert_post( array( 'post_type' => RRT_COL_TYPE, 'post_title' => 'Wheel of the Year', 'post_status' => 'draft', 'post_content' => 'The sabbats in order.' ) );
$GLOBALS['cl_posts'][] = $col;
update_post_meta( $col, RRT_COL_META, array( 'items' => array( $p1, $p2, $p3 ), 'gap' => 2, 'feed' => true, 'type' => 'bonus' ) );
$set = rrt_col_settings( $col );
cl_t( array( $p1, $p2, $p3 ) === $set['items'] && 2.0 === $set['gap'] && $set['feed'] && 'bonus' === $set['type'], 'a collection\'s choices read back' );
cl_t( array( $col ) === rrt_col_containing( $p2 ), 'a reading knows which collections have it' );

$plan = rrt_col_plan( $set['items'], $col, 2 );
cl_t( $plan['ready'] && 3 === $plan['count'] && '128-44100-1' === rrt_col_fmt_key( $plan['fmt'] ) && '' === $plan['rows'][0]['kind'], 'the plan: three readings that fit, at 128 kbps mono' );
cl_t( abs( $plan['secs'] - ( 950 + 2 * 77 ) / $fps ) < 1e-6, 'the plan\'s length counts the gaps' );
cl_t( -19.0 === (float) $plan['target'], 'the loudness to match is Finish for\'s (-19 for Podcast, mono)' );
$pub = rrt_col_plan_public( $plan );
cl_t( ! isset( $pub['rows'][0]['path'] ) && $pub['rows'][0]['measure'] && -19.0 === (float) $pub['target'], 'the browser\'s plan has no server paths, and asks for each loudness to be measured' );

$rec_before = (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . rrt_table( 'recordings' ) );
$b          = rrt_col_build( $col, 'save' );
cl_t( is_array( $b ) && ! empty( $b['att'] ) && '' === $b['error'], 'the collection joins', is_wp_error( $b ) ? $b->get_error_message() : '' );
$jp = get_attached_file( $b['att'] );
$jb = (string) file_get_contents( $jp );
$js = rrt_col_scan( $jp );
cl_t( $js['ok'] && 950 + 154 === $js['frames'] && '128-44100-1' === rrt_col_fmt_key( $js ), 'the joined file is one constant-bitrate MP3 of every frame and both gaps' );
$at  = $js['start'];
$gap = rrt_col_silence( $plan['fmt'], 77 );
$exact = substr( $jb, $at, strlen( $m1['frames'] ) ) === $m1['frames'];
$at   += strlen( $m1['frames'] );
$exact = $exact && substr( $jb, $at, strlen( $gap ) ) === $gap;
$at   += strlen( $gap );
$exact = $exact && substr( $jb, $at, strlen( $m2['frames'] ) ) === $m2['frames'];
$at   += strlen( $m2['frames'] ) + strlen( $gap );
$exact = $exact && substr( $jb, $at, strlen( $m3['frames'] ) ) === $m3['frames'] && $at + strlen( $m3['frames'] ) === strlen( $jb );
cl_t( $exact, 'each reading is copied byte for byte, with silence between, and nothing else (no Info frame, no ID3v1 tag)' );
$rc = rrt_id3_chapters( $jp );
cl_t( array( 'Samhain & the Veil', 'Yule', 'Yule: The long night', 'Imbolc' ) === wp_list_pluck( $rc, 'title' ), 'the joined file\'s chapters: each reading, and Yule\'s own heading', wp_json_encode( wp_list_pluck( $rc, 'title' ) ) );
cl_t( abs( $rc[1]['start'] - round( 477 / $fps, 3 ) ) < 0.002 && abs( $rc[2]['start'] - round( 477 / $fps + 3, 3 ) ) < 0.002 && get_permalink( $p2 ) === $rc[1]['url'], 'chapter times and links are right' );
cl_t( abs( $b['secs'] - ( 1104 / $fps ) ) < 0.001 && 3 === count( $b['parts'] ) && strlen( $jb ) === (int) $b['bytes'], 'the record of the join has its length, parts and size' );
cl_t( (int) get_post_meta( $col, 'rrt_audio', true ) === (int) $b['att'] && rrt_reading_audio( $col )['url'] === wp_get_attachment_url( $b['att'] ), 'the joined file is the collection\'s reading' );
$cap = rrt_reading_captions( $col );
$cv  = $cap['id'] ? (string) file_get_contents( get_attached_file( $cap['id'] ) ) : '';
$off = rrt_col_vtt_stamp( 854 / $fps + 1 );
cl_t( false !== strpos( $cv, "00:00:00.200 --> 00:00:01.500\nFirst words." ) && false !== strpos( $cv, $off . ' --> ' ) && false !== strpos( $cv, 'Third words.' ), 'the collection\'s captions are each reading\'s, moved to its place', $cv );
cl_t( $rec_before === (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . rrt_table( 'recordings' ) ), 'a collection adds no Recordings row (it is not a new recording)' );
cl_t( 'audio' === get_post_meta( $b['att'], '_rrt_col_file', true ) && (int) get_post( $b['att'] )->post_parent === $col, 'the joined file is the collection\'s own attachment' );

$b2 = rrt_col_build( $col, 'save' );
cl_t( (int) $b2['att'] === (int) $b['att'], 'saving again with nothing changed keeps the same file' );

/* The title of a reading changes: the tag must too. */
wp_update_post( array( 'ID' => $p3, 'post_title' => 'Imbolc, the first stirring' ) );
$b3 = rrt_col_build( $col, 'save' );
cl_t( (int) $b3['att'] !== (int) $b['att'] && ! get_post( $b['att'] ) && 'Imbolc, the first stirring' === end( $b3['parts'] )['title'], 'a reading\'s new title joins it again, and the old file goes' );

/*
 * ---------------------------------------------------------------------
 * Readings that do not fit
 * ---------------------------------------------------------------------
 */
$p4 = cl_reading( 'Ostara in stereo', $st['bytes'] );
update_post_meta( $col, RRT_COL_META, array( 'items' => array( $p1, $p2, $p3, $p4 ), 'gap' => 2, 'feed' => true, 'type' => 'bonus' ) );
$plan = rrt_col_plan( rrt_col_settings( $col )['items'], $col, 2 );
$r4   = end( $plan['rows'] );
cl_t( ! $plan['ready'] && 'format' === $r4['kind'] && false !== strpos( $r4['note'], '192 kbps stereo' ) && false !== strpos( $r4['note'], '128 kbps mono' ), 'a reading in another format must be matched, and says why', $r4['note'] );
$keep = (int) get_post_meta( $col, 'rrt_audio', true );
$e    = rrt_col_build( $col, 'save' );
cl_t( is_wp_error( $e ) && $keep === (int) get_post_meta( $col, 'rrt_audio', true ) && '' !== get_post_meta( $col, RRT_COL_BUILT, true )['error'], 'it is not joined until then, and the collection keeps its file' );

/* A matched copy made from that file, in the collection's format, stands in for it. */
$mc  = cl_mp3( 80, 14 );
$mid = cl_attach( $col, 'ostara-matched.mp3', $mc['bytes'], 'audio/mpeg' );
update_post_meta( $mid, RRT_COL_MATCH, array( 'post' => $p4, 'key' => rrt_col_file_key( rrt_col_reading_path( $p4 ) ), 'lufs' => -19.1 ) );
$plan = rrt_col_plan( rrt_col_settings( $col )['items'], $col, 2 );
cl_t( $plan['ready'] && $mid === (int) end( $plan['rows'] )['matched'] && '' === end( $plan['rows'] )['kind'], 'a matched copy of the reading\'s current file stands in for it' );
$b4 = rrt_col_build( $col, 'save' );
$jb = (string) file_get_contents( get_attached_file( $b4['att'] ) );
cl_t( is_array( $b4 ) && 4 === count( $b4['parts'] ) && 1 === (int) end( $b4['parts'] )['matched'] && substr( $jb, -strlen( $mc['frames'] ) ) === $mc['frames'], 'the collection joins with the matched copy\'s frames' );
cl_t( (bool) get_post( $mid ), 'the matched copy is kept while it is in use' );

/* A matched copy of an older file is not used. */
update_post_meta( $mid, RRT_COL_MATCH, array( 'post' => $p4, 'key' => 'an-older-file', 'lufs' => -19.1 ) );
$plan = rrt_col_plan( rrt_col_settings( $col )['items'], $col, 2 );
cl_t( ! $plan['ready'] && ! end( $plan['rows'] )['matched'], 'a matched copy of an older file is not used' );
update_post_meta( $mid, RRT_COL_MATCH, array( 'post' => $p4, 'key' => rrt_col_file_key( rrt_col_reading_path( $p4 ) ), 'lufs' => -19.1 ) );

/* Loudness. */
update_post_meta( $p2, RRT_LEVEL_META, array( 'key' => rrt_col_file_key( rrt_col_reading_path( $p2 ) ), 'lufs' => -21.4 ) );
update_post_meta( $p3, RRT_LEVEL_META, array( 'key' => 'an-older-file', 'lufs' => -30 ) );
update_post_meta( $p1, RRT_LEVEL_META, array( 'key' => rrt_col_file_key( rrt_col_reading_path( $p1 ) ), 'lufs' => -19.6 ) );
$plan = rrt_col_plan( rrt_col_settings( $col )['items'], $col, 2 );
cl_t( 'level' === $plan['rows'][1]['kind'] && false !== strpos( $plan['rows'][1]['note'], '2.4 dB quieter' ), 'a reading 2.4 dB under the target is pointed out', $plan['rows'][1]['note'] );
cl_t( '' === $plan['rows'][0]['kind'] && '' === $plan['rows'][2]['kind'] && null === $plan['rows'][2]['lufs'], 'within 1 LU is fine, and a level measured on an older file is not used' );
cl_t( $plan['ready'], 'a reading far from the others\' loudness can still be joined' );
cl_t( -16.0 === round( rrt_col_mono_lu( -12.99, 2 ), 2 ) && -19.0 === rrt_col_mono_lu( -19.0, 1 ), 'stereo loudness compares with mono as the same voice in one channel' );

/* A tie between formats goes to Finish for's (128 kbps mono here), even when another reading comes first. */
$m96  = cl_mp3( 60, 31, array( 'kbps' => 96 ) );
$p96  = cl_reading( 'An older reading at 96 kbps', $m96['bytes'] );
$plan = rrt_col_plan( array( $p96, $p1 ), 0, 2 );
cl_t( '128-44100-1' === rrt_col_fmt_key( $plan['fmt'] ) && 'format' === $plan['rows'][0]['kind'] && '' === $plan['rows'][1]['kind'], 'a tie between formats goes to Finish for\'s, so the older reading is the one to match' );
$plan = rrt_col_plan( array( $p96, cl_reading( 'Another at 96 kbps', cl_mp3( 40, 32, array( 'kbps' => 96 ) )['bytes'] ), $p1 ), 0, 2 );
cl_t( '96-44100-1' === rrt_col_fmt_key( $plan['fmt'] ), 'when most readings share another format, the collection takes it' );

/*
 * ---------------------------------------------------------------------
 * Keeping it up to date
 * ---------------------------------------------------------------------
 */
$before = (int) get_post_meta( $col, 'rrt_audio', true );
$GLOBALS['rrt_col_queue'] = array();
$m2b    = cl_mp3( 360, 21 );
$a2b    = cl_attach( $p2, 'reading-2.mp3', $m2b['bytes'], 'audio/mpeg' );
update_post_meta( $p2, 'rrt_audio', $a2b );
cl_t( array( $col ) === array_keys( (array) $GLOBALS['rrt_col_queue'] ), 'a new reading on a post asks for its collection to be joined again' );
rrt_col_run_queue();
$b5 = get_post_meta( $col, RRT_COL_BUILT, true );
cl_t( (int) $b5['att'] !== $before && abs( ( $b5['parts'][1]['end'] - $b5['parts'][1]['start'] ) - 360 / $fps ) < 0.001, 'it is joined again with the new reading' );

$before = (int) $b5['att'];
delete_post_meta( $p3, 'rrt_audio' );
rrt_col_run_queue();
$b6 = get_post_meta( $col, RRT_COL_BUILT, true );
cl_t( $before === (int) $b6['att'] && '' !== $b6['error'], 'a reading taken off its post: the collection keeps the file it has, and says so' );
$plan = rrt_col_plan( rrt_col_settings( $col )['items'], $col, 2 );
cl_t( 'none' === $plan['rows'][2]['kind'], 'the box shows that reading as missing' );
$b7 = rrt_col_build( $col, 'save' );
cl_t( is_array( $b7 ) && array( $p3 ) === $b7['left_out'] && 3 === count( $b7['parts'] ), 'saving joins it again without that reading, and names it' );
update_post_meta( $p3, 'rrt_audio', cl_attach( $p3, 'reading-3.mp3', $m3['bytes'], 'audio/mpeg' ) );
rrt_col_run_queue();
cl_t( 4 === count( get_post_meta( $col, RRT_COL_BUILT, true )['parts'] ), 'when the reading comes back, it is joined in again' );

wp_update_post( array( 'ID' => $p1, 'post_status' => 'draft' ) );
$plan = rrt_col_plan( rrt_col_settings( $col )['items'], $col, 2 );
cl_t( 'unpublished' === $plan['rows'][0]['kind'], 'an unpublished reading is left out' );
wp_update_post( array( 'ID' => $p1, 'post_status' => 'publish' ) );

/*
 * ---------------------------------------------------------------------
 * The box's routes and its save
 * ---------------------------------------------------------------------
 */
$r = rest_do_request( ( function () use ( $p1, $p2, $col ) {
	$q = new WP_REST_Request( 'POST', '/rrt/v1/collections/plan' );
	$q->set_body_params( array( 'col' => $col, 'ids' => array( $p2, $p1, $col, $p2 ), 'gap' => 0 ) );
	return $q;
} )() );
$d = $r->get_data();
cl_t( 200 === $r->get_status() && array( $p2, $p1 ) === wp_list_pluck( $d['rows'], 'id' ) && 2 === $d['count'], 'the plan route takes the ids in order, without repeats or the collection itself' );
$q = new WP_REST_Request( 'GET', '/rrt/v1/collections/readings' );
$q->set_query_params( array( 'search' => 'Yule', 'exclude' => '' ) );
$d = rest_do_request( $q )->get_data();
cl_t( in_array( $p2, wp_list_pluck( $d['readings'], 'id' ), true ) && ! in_array( $col, wp_list_pluck( $d['readings'], 'id' ), true ), 'the search finds posts with a reading, never a collection' );
$q = new WP_REST_Request( 'POST', '/rrt/v1/collections/level' );
$q->set_body_params( array( 'post' => $p1, 'key' => 'wrong', 'lufs' => -19 ) );
cl_t( 409 === rest_do_request( $q )->get_status(), 'a level for another file is refused' );
$q->set_body_params( array( 'post' => $p1, 'key' => rrt_col_file_key( rrt_col_reading_path( $p1 ) ), 'lufs' => -18.7 ) );
cl_t( 200 === rest_do_request( $q )->get_status() && -18.7 === rrt_col_level( $p1, rrt_col_file_key( rrt_col_reading_path( $p1 ) ) ), 'a measured level is kept with the reading\'s file' );
$q->set_body_params( array( 'post' => $p1, 'key' => rrt_col_file_key( rrt_col_reading_path( $p1 ) ), 'lufs' => 'loud' ) );
cl_t( 400 === rest_do_request( $q )->get_status(), 'a level that is not a number is refused' );
wp_set_current_user( 0 );
$q = new WP_REST_Request( 'POST', '/rrt/v1/collections/' . $col . '/build' );
cl_t( in_array( rest_do_request( $q )->get_status(), array( 401, 403 ), true ), 'visitors cannot join a collection' );
wp_set_current_user( 1 );

$_POST = array(
	'rrt_col_nonce' => wp_create_nonce( 'rrt_col_save' ),
	'rrt_col_items' => $p3 . ',' . $p1 . ',' . $col . ',x,' . $p3,
	'rrt_col_gap'   => '0',
	'rrt_col_type'  => 'full',
);
rrt_col_save( $col, get_post( $col ) );
$_POST = array();
$set   = rrt_col_settings( $col );
$b8    = get_post_meta( $col, RRT_COL_BUILT, true );
cl_t( array( $p3, $p1 ) === $set['items'] && 0.0 === $set['gap'] && ! $set['feed'] && 'full' === $set['type'], 'saving the box keeps its choices (no repeats, not itself)' );
cl_t( 2 === count( $b8['parts'] ) && abs( $b8['parts'][1]['start'] - $b8['parts'][0]['end'] ) < 1e-6, 'and joins it, with no gap when none is chosen' );

/*
 * ---------------------------------------------------------------------
 * The page and the feed
 * ---------------------------------------------------------------------
 */
$html = rrt_col_list_html( $col );
cl_t( false !== strpos( $html, 'Imbolc, the first stirring' ) && false !== strpos( $html, 'data-rrt-seek="' . number_format( $b8['parts'][1]['start'], 3, '.', '' ) . '"' ) && false !== strpos( $html, get_permalink( $p1 ) ), 'the page lists the readings, each with a play button at its time and a link to its post' );

update_option( 'rrt_podcast', array_merge( (array) get_option( 'rrt_podcast', array() ), array( 'on' => 1 ) ) );
wp_update_post( array( 'ID' => $col, 'post_status' => 'publish' ) );
rrt_col_run_queue();
cl_t( ! rrt_feed_has_post( $col ), 'a collection with In the podcast feed unticked is not an episode' );
update_post_meta( $col, RRT_COL_META, array_merge( rrt_col_settings( $col ), array( 'feed' => true, 'type' => 'bonus' ) ) );
cl_t( rrt_feed_has_post( $col ) && in_array( $col, wp_list_pluck( rrt_podcast_posts(), 'ID' ), true ), 'a published collection is an episode of the feed' );
$ep = rrt_podcast_episode( get_post( $col ) );
cl_t( 'bonus' === $ep['etype'] && false !== strpos( $ep['guid'], ':collection:' . $col ) && $ep['bytes'] > 0 && '' !== $ep['vtt'], 'its episode is a bonus, with its own id, file and captions' );
cl_t( 2 <= count( rrt_col_feed_chapters( $col ) ) && 'Imbolc, the first stirring' === rrt_col_feed_chapters( $col )[0]['title'], 'its chapters file lists the readings' );
cl_t( false !== strpos( rrt_chapters_json( array( array( 'start' => 0, 'end' => 1, 'title' => 'a', 'img' => 'https://example.com/a.jpg' ) ) ), '"img": "https://example.com/a.jpg"' ), 'a chapter can carry a picture in the chapters file' );
$normal = rrt_podcast_episode( get_post( $p2 ) );
cl_t( 'full' === $normal['etype'] && false !== strpos( $normal['guid'], ':reading:' ), 'a post\'s own episode stays full' );

/* Deleting a collection's choices leaves the readings alone. */
cl_t( (int) get_post_meta( $p2, 'rrt_audio', true ) === $a2b, 'the readings\' own files are never touched' );
