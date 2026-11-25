<?php
/**
 * Readings of deleted posts (1.36.2), run inside the test WordPress:
 *   php wp-cli.phar --path=<wordpress> eval-file tests/php/tidy.php
 * Prints one line per check and exits 1 if any fail. Leaves nothing behind.
 */

$GLOBALS['td_pass'] = 0;
$GLOBALS['td_fail'] = array();

function td_t( $ok, $name, $why = '' ) {
	if ( $ok ) {
		++$GLOBALS['td_pass'];
		echo "ok   $name\n";
	} else {
		$GLOBALS['td_fail'][] = $name;
		echo "FAIL $name" . ( '' !== $why ? " ($why)" : '' ) . "\n";
	}
}

global $wpdb;
wp_set_current_user( 1 );
rrt_stats_install();
$td_was_settings = get_option( 'rrt_settings', array() );
$td_was_shelf    = get_user_meta( 1, '_rrt_shelf', true );
$td_was_sent     = get_user_meta( 1, '_rrt_sent', true );
$GLOBALS['td_posts'] = array();
register_shutdown_function(
	function () use ( $td_was_settings, $td_was_shelf, $td_was_sent ) {
		update_option( 'rrt_settings', $td_was_settings );
		foreach ( array( '_rrt_shelf' => $td_was_shelf, '_rrt_sent' => $td_was_sent ) as $k => $v ) {
			if ( $v ) {
				update_user_meta( 1, $k, $v );
			} else {
				delete_user_meta( 1, $k );
			}
		}
	}
);

/* A small file made into a Media Library item of the post. */
function td_attach( $post_id, $name, $body, $mime ) {
	$up   = wp_upload_dir();
	$path = trailingslashit( $up['path'] ) . wp_unique_filename( $up['path'], $name );
	file_put_contents( $path, $body );
	$att = wp_insert_attachment(
		array(
			'post_title'     => 'tidytest-' . pathinfo( $path, PATHINFO_FILENAME ),
			'post_mime_type' => $mime,
			'post_status'    => 'inherit',
		),
		$path,
		$post_id
	);
	update_attached_file( $att, $path );
	return $att;
}
function td_post( $title ) {
	$id                    = wp_insert_post( array( 'post_title' => $title, 'post_status' => 'publish', 'post_content' => 'Words to read aloud.' ) );
	$GLOBALS['td_posts'][] = $id;
	return $id;
}
/* A reading with its captions put on the post, as Use this recording does. */
function td_reading( $post_id, $name ) {
	$mp3 = "\xFF\xFB\x10\xC4" . str_repeat( "\0", 100 );
	$a   = td_attach( $post_id, $name . '.mp3', $mp3, 'audio/mpeg' );
	$c   = td_attach( $post_id, $name . '.vtt', "WEBVTT\n", 'text/vtt' );
	update_post_meta( $post_id, 'rrt_audio', $a );
	update_post_meta( $post_id, 'rrt_captions', $c );
	$r = rrt_note_recording( $post_id, array( 'attachment_id' => $a, 'source' => 'upload' ) );
	return array( $a, $c, $r );
}
$gone = function ( $id ) { return null === get_post( $id ); };
$row  = function ( $id ) { return rrt_tidy_row( $id ); };

/* The setting. */
$s = rrt_settings();
td_t( ! isset( $td_was_settings['stats']['tidy_on_delete'] ) ? rrt_tidy_on() : true, 'deleting readings with their post is on unless switched off' );
$in = array( 'player' => array( 'present' => 1 ), 'stats' => array( 'present' => 1, 'server' => 1 ) );
$out = rrt_sanitize_settings( $in );
td_t( isset( $out['stats']['tidy_on_delete'] ) && false === $out['stats']['tidy_on_delete'], 'unticking When a post is deleted for good saves it off' );
$in['stats']['tidy_on_delete'] = '1';
$out = rrt_sanitize_settings( $in );
td_t( true === $out['stats']['tidy_on_delete'], 'ticking it saves it on' );

/* Labels and which rows may be deleted. */
$p = td_post( 'Tidy one' );
list( $a1, $c1, $r1 ) = td_reading( $p, 'tidytest-one-first' );
list( $a2, $c2, $r2 ) = td_reading( $p, 'tidytest-one-second' );
td_t( 'no' === rrt_tidy_label( $row( $r1 ) ) && 'yes' === rrt_tidy_label( $row( $r2 ) ), 'a replaced reading reads no, the current one yes' );
td_t( 'kept aside' === rrt_tidy_label( $row( $r1 ), true ), 'a reading kept aside reads kept aside' );
td_t( rrt_tidy_can_delete( $row( $r1 ) ) && ! rrt_tidy_can_delete( $row( $r2 ) ) && ! rrt_tidy_can_delete( $row( $r1 ), true ), 'a replaced reading may be deleted; the current one and one kept aside may not' );
td_t( ! rrt_tidy_is_leftover( $row( $r1 ) ) && ! rrt_tidy_is_leftover( $row( $r2 ) ), 'readings of a live post are not leftovers' );

/* Deleting a replaced reading: its MP3, its captions and the cover picture go; the current reading stays. */
$img = td_attach( 0, 'tidytest-cover.jpg', 'not really a picture', 'image/jpeg' );
update_post_meta( $a1, '_thumbnail_id', $img );
$res = rrt_tidy_delete_row( $r1 );
td_t( ! is_wp_error( $res ) && 2 === $res['files'] && $gone( $a1 ) && $gone( $c1 ) && $gone( $img ) && null === $row( $r1 ), 'Delete removes the MP3, its captions, its cover picture and the row', wp_json_encode( $res ) );
td_t( ! $gone( $a2 ) && ! $gone( $c2 ) && $row( $r2 ), 'the reading on the post and its captions stay' );
td_t( is_wp_error( rrt_tidy_delete_row( $r2 ) ) && ! $gone( $a2 ), 'the reading on a live post is refused' );

/* A cover picture another MP3 also uses stays. */
list( $a3, $c3, $r3 ) = td_reading( $p, 'tidytest-one-third' );
$img2 = td_attach( 0, 'tidytest-cover2.jpg', 'not really a picture', 'image/jpeg' );
update_post_meta( $a2, '_thumbnail_id', $img2 );
update_post_meta( $a3, '_thumbnail_id', $img2 );
rrt_tidy_delete_row( $r2 );
td_t( $gone( $a2 ) && ! $gone( $img2 ), 'a cover picture another MP3 uses is kept' );

/* An old row that shares its file with the post's current reading keeps the file. */
$q = td_post( 'Tidy shared' );
list( $qa, $qc, $qr ) = td_reading( $q, 'tidytest-shared' );
$wpdb->insert( rrt_table( 'recordings' ), array( 'post_id' => $q, 'attachment_id' => $qa, 'file_name' => 'old', 'url' => wp_get_attachment_url( $qa ), 'source' => 'manual', 'created_at' => current_time( 'mysql', true ), 'is_current' => 0 ) );
$qold = (int) $wpdb->insert_id;
$res  = rrt_tidy_delete_row( $qold );
td_t( ! is_wp_error( $res ) && (int) $q === (int) $res['kept_for'] && ! $gone( $qa ) && null === $row( $qold ), 'an old row sharing the current reading’s file goes, the file stays', wp_json_encode( $res ) );

/* The post in the Trash. */
wp_trash_post( $p );
td_t( 'yes, post in the Trash' === rrt_tidy_label( $row( $r3 ) ) && rrt_tidy_is_leftover( $row( $r3 ) ) && rrt_tidy_can_delete( $row( $r3 ) ), 'a reading of a post in the Trash says so and may be deleted' );
td_t( ! $gone( $a3 ) && ! $gone( $c3 ), 'moving a post to the Trash deletes nothing' );

/* A file used by another live post stays; one used only by a post in the Trash does not count. */
$b = td_post( 'Tidy other' );
update_post_meta( $b, 'rrt_audio', $a3 );
$res = rrt_tidy_delete_row( $r3 );
td_t( ! is_wp_error( $res ) && (int) $b === (int) $res['kept_for'] && ! $gone( $a3 ) && null === $row( $r3 ), 'a file another live post uses is kept, the row goes', wp_json_encode( $res ) );
list( $ta, $tc, $tr ) = td_reading( $p, 'tidytest-trash-only' );
$t2 = td_post( 'Tidy other in Trash' );
update_post_meta( $t2, 'rrt_audio', $ta );
wp_trash_post( $t2 );
$res = rrt_tidy_delete_row( $tr );
td_t( ! is_wp_error( $res ) && 0 === (int) $res['kept_for'] && $gone( $ta ) && $gone( $tc ), 'a post in the Trash does not keep a file from being deleted', wp_json_encode( $res ) );

/* A file already gone: Remove from list. */
$g = td_post( 'Tidy gone' );
list( $ga, $gc, $gr ) = td_reading( $g, 'tidytest-gone-first' );
list( $ga2, $gc2, $gr2 ) = td_reading( $g, 'tidytest-gone-second' );
wp_delete_file( get_attached_file( $ga ) );
td_t( 'no, file deleted' === rrt_tidy_label( $row( $gr ) ) && rrt_tidy_is_leftover( $row( $gr ) ), 'a row whose file is gone says file deleted and counts as a leftover' );
ob_start();
rrt_tidy_row_form( $row( $gr ) );
$html = ob_get_clean();
td_t( false !== strpos( $html, 'Remove from list' ) && false !== strpos( $html, '<details' ) && false !== strpos( $html, 'rrt_rec_delete' ) && false !== strpos( $html, '_wpnonce' ), 'its button is Remove from list, asked on the page with a nonce' );
$wpdb->insert( rrt_table( 'recordings' ), array( 'post_id' => 999999, 'file_name' => 'lost.mp3', 'url' => wp_upload_dir()['baseurl'] . '/1999/01/lost.mp3', 'source' => 'manual', 'created_at' => current_time( 'mysql', true ), 'is_current' => 1 ) );
$lost = (int) $wpdb->insert_id;
td_t( 'post deleted, file deleted' === rrt_tidy_label( $row( $lost ) ), 'a row of a deleted post with no file says post deleted, file deleted', rrt_tidy_label( $row( $lost ) ) );
$wpdb->insert( rrt_table( 'recordings' ), array( 'post_id' => $g, 'file_name' => 'outside.mp3', 'url' => 'https://example.com/outside.mp3', 'source' => 'manual', 'created_at' => current_time( 'mysql', true ), 'is_current' => 0 ) );
$outside = (int) $wpdb->insert_id;
td_t( 'no' === rrt_tidy_label( $row( $outside ) ) && ! rrt_tidy_is_leftover( $row( $outside ) ), 'a reading kept outside the site is never called deleted' );

/* Clear: every leftover goes, live readings stay. */
$ids = array_map( function ( $r ) { return (int) $r['id']; }, rrt_tidy_leftovers() );
td_t( in_array( $gr, $ids, true ) && in_array( $lost, $ids, true ) && ! in_array( $gr2, $ids, true ) && ! in_array( $outside, $ids, true ), 'the leftovers are the rows of deleted or trashed posts and rows whose file is gone' );
foreach ( rrt_tidy_leftovers() as $r ) {
	if ( in_array( (int) $r['id'], array( $gr, $lost ), true ) ) {
		rrt_tidy_delete_row( (int) $r['id'] );
	}
}
td_t( null === $row( $gr ) && null === $row( $lost ) && $row( $gr2 ) && ! $gone( $ga2 ), 'clearing removes them and leaves the live reading' );

/* The Clear button shows only with leftovers, and the screen shows the new words. */
ob_start();
rrt_tidy_clear_form( 0 );
$none = ob_get_clean();
ob_start();
rrt_tidy_clear_form( 3 );
$some = ob_get_clean();
td_t( '' === $none && false !== strpos( $some, 'Clear readings of deleted posts (3)' ) && false !== strpos( $some, 'rrt_rec_clear' ), 'Clear readings of deleted posts shows only when there are any, with the count' );

/* A post deleted for good takes its readings, captions, rows and takes. */
$d = td_post( 'Tidy delete for good' );
list( $da, $dc, $dr ) = td_reading( $d, 'tidytest-forgood' );
$wpdb->insert( rrt_table( 'recordings' ), array( 'post_id' => $d, 'attachment_id' => $da, 'file_name' => 'x', 'url' => wp_get_attachment_url( $da ), 'source' => 'manual', 'created_at' => current_time( 'mysql', true ), 'is_current' => 0 ) );
$ddup = (int) $wpdb->insert_id;
$keep = td_post( 'Tidy kept' );
list( $ka, $kc, $kr ) = td_reading( $keep, 'tidytest-kept' );
$shelf   = rrt_shelf_raw( 1 );
$shelf[] = array( 'id' => 'ttidy0001', 'post' => $d, 'n' => 1, 'at' => time() * 1000, 'seconds' => 1, 'bytes' => 4, 'have' => 4, 'state' => 'ready', 'sum' => 'x', 'updated' => time() );
$shelf[] = array( 'id' => 'ttidy0002', 'post' => $keep, 'n' => 1, 'at' => time() * 1000, 'seconds' => 1, 'bytes' => 4, 'have' => 4, 'state' => 'ready', 'sum' => 'x', 'updated' => time() );
rrt_shelf_save( 1, $shelf );
foreach ( array( 'ttidy0001', 'ttidy0002' ) as $sid ) {
	wp_mkdir_p( dirname( rrt_shelf_file( 1, $sid, 'flac' ) ) );
	file_put_contents( rrt_shelf_file( 1, $sid, 'flac' ), 'fLaC' );
	file_put_contents( rrt_shelf_file( 1, $sid, 'json' ), '{}' );
}
wp_mkdir_p( rrt_sent_dir() );
file_put_contents( rrt_sent_dir() . 'tidytest-sent-1.wav', 'RIFF' );
file_put_contents( rrt_sent_dir() . 'tidytest-sent-2.wav', 'RIFF' );
update_user_meta(
	1,
	'_rrt_sent',
	array(
		array( 'id' => str_repeat( 'a', 32 ), 'post' => $d, 'file' => 'tidytest-sent-1.wav', 'bytes' => 4, 'seconds' => 1, 'sent' => time() ),
		array( 'id' => str_repeat( 'b', 32 ), 'post' => $keep, 'file' => 'tidytest-sent-2.wav', 'bytes' => 4, 'seconds' => 1, 'sent' => time() ),
	)
);
wp_trash_post( $d );
td_t( ! $gone( $da ) && $row( $dr ) && null !== rrt_shelf_find( 'ttidy0001' ), 'the Trash keeps everything, ready to restore' );
wp_delete_post( $d, true );
$sent_left = get_user_meta( 1, '_rrt_sent', true );
td_t( $gone( $da ) && $gone( $dc ) && null === $row( $dr ) && null === $row( $ddup ), 'deleting the post for good deletes its reading, captions and rows' );
td_t( null === rrt_shelf_find( 'ttidy0001' ) && ! file_exists( rrt_shelf_file( 1, 'ttidy0001', 'flac' ) ) && null !== rrt_shelf_find( 'ttidy0002' ), 'and its take shelf takes, keeping another post’s' );
td_t( is_array( $sent_left ) && 1 === count( $sent_left ) && $keep === (int) $sent_left[0]['post'] && ! file_exists( rrt_sent_dir() . 'tidytest-sent-1.wav' ) && file_exists( rrt_sent_dir() . 'tidytest-sent-2.wav' ), 'and its waiting phone takes, keeping another post’s' );
td_t( ! $gone( $ka ) && ! $gone( $kc ) && $row( $kr ), 'another post’s reading is untouched' );

/* Switched off: WordPress's usual way. */
$off                              = rrt_settings();
$off['stats']['tidy_on_delete'] = false;
update_option( 'rrt_settings', $off );
$o = td_post( 'Tidy switched off' );
list( $oa, $oc, $or ) = td_reading( $o, 'tidytest-off' );
wp_delete_post( $o, true );
td_t( ! $gone( $oa ) && ! $gone( $oc ) && $row( $or ), 'with the switch off, deleting a post leaves its files and rows' );
update_option( 'rrt_settings', $td_was_settings );
rrt_tidy_delete_row( $or, false, true );

/* Delete needs the right person and a nonce. */
td_t( has_action( 'admin_post_rrt_rec_delete' ) && has_action( 'admin_post_rrt_rec_clear' ), 'Delete and Clear are answered by admin-post' );
$src = file_get_contents( RRT_DIR . 'includes/tidy.php' );
td_t( 2 === substr_count( $src, 'check_admin_referer(' ) && 2 === substr_count( $src, "current_user_can( 'delete_posts' )" ), 'both check a nonce and that the person may delete posts' );

/* Tidy up. */
rrt_shelf_delete( 1, 'ttidy0002' );
@unlink( rrt_sent_dir() . 'tidytest-sent-2.wav' ); // phpcs:ignore
foreach ( array_merge( $GLOBALS['td_posts'], array( $t2 ) ) as $pid ) {
	if ( get_post( $pid ) ) {
		wp_delete_post( $pid, true );
	}
}
foreach ( $wpdb->get_col( "SELECT ID FROM $wpdb->posts WHERE post_type = 'attachment' AND post_title LIKE 'tidytest-%'" ) as $att ) {
	wp_delete_attachment( (int) $att, true );
}
$wpdb->query( 'DELETE FROM ' . rrt_table( 'recordings' ) . " WHERE file_name LIKE 'tidytest-%' OR file_name IN ('old','x','lost.mp3','outside.mp3') OR post_id = 999999" );
td_t( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_title LIKE 'tidytest-%' OR post_title LIKE 'Tidy %'" ), 'nothing is left behind' );

echo "\n" . $GLOBALS['td_pass'] . ' passed, ' . count( $GLOBALS['td_fail'] ) . " failed\n";
if ( $GLOBALS['td_fail'] ) {
	exit( 1 );
}
