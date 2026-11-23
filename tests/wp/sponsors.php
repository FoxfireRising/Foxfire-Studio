<?php
/**
 * A reading and a recorded sponsor message for the browser test (1.22.0):
 *   wp eval-file tests/wp/sponsors.php <folder>    makes them from reading.mp3 and message.mp3 (tests/wp/sponsors-mp3.js), switches the feed and sponsor messages on, and prints their ids as JSON
 *   wp eval-file tests/wp/sponsors.php off <ids>   deletes them, their files and sponsored copies
 *
 * run.sh saves rrt_podcast before and puts it back after.
 */

if ( isset( $args[0] ) && 'off' === $args[0] ) {
	foreach ( array_filter( array_map( 'intval', explode( ',', isset( $args[1] ) ? $args[1] : '' ) ) ) as $id ) {
		foreach ( get_children( array( 'post_parent' => $id, 'post_type' => 'attachment', 'numberposts' => -1 ) ) as $a ) {
			wp_delete_attachment( $a->ID, true );
		}
		wp_delete_post( $id, true );
	}
	$d = rrt_spot_dir();
	if ( is_dir( $d ) ) {
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $d, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $f ) {
			$f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
		}
		rmdir( $d );
	}
	return;
}

$dir = rtrim( isset( $args[0] ) ? $args[0] : '', '/' );
$up  = wp_upload_dir();

function sps_attach( $post, $src, $name, $mime ) {
	$up   = wp_upload_dir();
	$path = trailingslashit( $up['path'] ) . $name;
	copy( $src, $path );
	$a = wp_insert_attachment( array( 'post_title' => $name, 'post_mime_type' => $mime, 'post_status' => 'inherit' ), $path, $post );
	update_attached_file( $a, $path );
	return (int) $a;
}

$r = wp_insert_post( array( 'post_title' => 'Sponsor test: a long reading', 'post_status' => 'publish', 'post_content' => '<p>A reading for the sponsor messages test.</p>' ) );
update_post_meta( $r, 'rrt_audio', sps_attach( $r, $dir . '/reading.mp3', 'sponsor-test-reading-' . $r . '.mp3', 'audio/mpeg' ) );

$s = wp_insert_post( array( 'post_title' => 'Sponsor test: spring message', 'post_status' => 'publish', 'post_type' => RRT_SPOT_TYPE, 'post_content' => "This reading is supported by Acme Candles.\n\nFind them at example.com." ) );
/* Only this reading: other tests' posts with readings may be on the site. */
update_post_meta( $s, RRT_SPOT_META, rrt_spot_clean( array( 'who' => 'Acme Candles', 'kind' => 'paid', 'link' => 'https://example.com/candles', 'slots' => array( 'pre', 'mid' ), 'where' => 'posts', 'posts' => array( $r ) ) ) );
update_post_meta( $s, 'rrt_audio', sps_attach( $s, $dir . '/message.mp3', 'sponsor-test-message-' . $s . '.mp3', 'audio/mpeg' ) );
$vtt = "WEBVTT\n\n1\n00:00:00.300 --> 00:00:01.900\nThis reading is supported by Acme Candles.\n";
file_put_contents( trailingslashit( $up['path'] ) . 'sponsor-test-message-' . $s . '.vtt', $vtt ); // phpcs:ignore WordPress.WP.AlternativeFunctions
update_post_meta( $s, 'rrt_captions', sps_attach( $s, trailingslashit( $up['path'] ) . 'sponsor-test-message-' . $s . '.vtt', 'sponsor-test-message-' . $s . '-c.vtt', 'text/vtt' ) );

$pc = rrt_podcast_settings();
$pc['on']        = true;
$pc['s_on']      = true;
$pc['s_mid_min'] = 1;
$pc['s_thank']   = false;
update_option( 'rrt_podcast', $pc );
update_option( 'rrt_podcast_flush', 1 );
unset( $GLOBALS['rrt_spot_queue'] );
echo wp_json_encode( array( 'reading' => $r, 'spot' => $s, 'url' => wp_get_attachment_url( (int) get_post_meta( $r, 'rrt_audio', true ) ) ) );
