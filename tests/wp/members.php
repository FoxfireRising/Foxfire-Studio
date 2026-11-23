<?php
/**
 * Two posts and two subscribers for the member feeds test (1.21.0):
 *   wp eval-file tests/wp/members.php           makes them and prints their ids as JSON
 *   wp eval-file tests/wp/members.php off <ids> deletes them, their files, the users and every member key
 *
 * Each reading is a 3 s WAV in the Media Library (a soft tone, 8 kHz mono),
 * the second with captions. The second post is set to Members only, so its
 * files are kept for members from the start.
 */

if ( isset( $args[0] ) && 'off' === $args[0] ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( array_slice( $args, 1 ) as $i => $id ) {
		$id = (int) $id;
		if ( $i < 2 ) {
			foreach ( get_children( array( 'post_parent' => $id, 'post_type' => 'attachment', 'numberposts' => -1, 'post_status' => 'any' ) ) as $a ) {
				wp_delete_attachment( $a->ID, true );
			}
			wp_delete_post( $id, true );
		} else {
			wp_delete_user( $id );
		}
	}
	foreach ( array( RRT_MFEED_META, RRT_MFEED_AT_META, RRT_MSEEN_META, RRT_MEMBER_META ) as $k ) {
		delete_metadata( 'user', 0, $k, '', true );
	}
	$d = rrt_mfiles_dir();
	if ( is_dir( $d ) ) {
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $d, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $f ) {
			$f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
		}
		rmdir( $d );
	}
	return;
}

$rate = 8000;
$pcm  = '';
for ( $i = 0; $i < 3 * $rate; $i++ ) {
	$pcm .= pack( 'v', (int) round( 6000 * sin( 2 * M_PI * 220 * $i / $rate ) ) & 0xFFFF );
}
$wav = 'RIFF' . pack( 'V', 36 + strlen( $pcm ) ) . 'WAVEfmt ' . pack( 'VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16 ) . 'data' . pack( 'V', strlen( $pcm ) ) . $pcm;
wp_set_current_user( 1 );
$up  = wp_upload_dir();
$out = array();
foreach ( array( 'open' => 'Member feed open test', 'closed' => 'Member feed closed test' ) as $k => $title ) {
	$post  = wp_insert_post( array( 'post_title' => $title, 'post_status' => 'publish', 'post_content' => '<p>Leaves fall slowly. Rain taps the glass.</p>' ) );
	$files = array( $k . '-member-reading.wav' => array( $wav, 'audio/wav' ) );
	if ( 'closed' === $k ) {
		$files[ $k . '-member-captions.vtt' ] = array( "WEBVTT\n\n00:00.000 --> 00:03.000\nLeaves fall slowly.\n", 'text/vtt' );
	}
	$made = array();
	foreach ( $files as $name => $f ) {
		$path = trailingslashit( $up['path'] ) . wp_unique_filename( $up['path'], $name );
		file_put_contents( $path, $f[0] );
		$att = wp_insert_attachment( array( 'post_title' => pathinfo( $name, PATHINFO_FILENAME ), 'post_mime_type' => $f[1], 'post_status' => 'inherit' ), $path, $post );
		wp_update_attachment_metadata( $att, array( 'length' => 3, 'filesize' => strlen( $f[0] ) ) );
		$made[] = $att;
	}
	update_post_meta( $post, 'rrt_audio', $made[0] );
	if ( isset( $made[1] ) ) {
		update_post_meta( $post, 'rrt_captions', $made[1] );
	}
	$out[ $k ]          = $post;
	$out[ $k . '_url' ] = wp_get_attachment_url( $made[0] );
}
$out['closed_old'] = $out['closed_url'];
rrt_access_save( $out['closed'], 'members', 7 );
$out['closed_url'] = wp_get_attachment_url( (int) get_post_meta( $out['closed'], 'rrt_audio', true ) );
foreach ( array( 'friend' => 'mbfriend', 'other' => 'mbother' ) as $k => $login ) {
	$out[ $k ] = wp_insert_user( array( 'user_login' => $login, 'user_pass' => $login, 'user_email' => $login . '@example.com', 'display_name' => ucfirst( $k ) . ' Tester', 'role' => 'subscriber' ) );
}
/* Visitors see the player on this test (the test site shows it to editors only); run.sh puts the settings back. */
$cfg                     = rrt_settings();
$cfg['player']['show']   = 'everyone';
$cfg['player']['position'] = 'top';
update_option( 'rrt_settings', $cfg );
echo wp_json_encode( $out );
