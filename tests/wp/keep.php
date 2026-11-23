<?php
/**
 * A post with a reading for the Keep a reading aside test (1.8.0):
 *   wp eval-file tests/wp/keep.php          makes the post and prints its id
 *   wp eval-file tests/wp/keep.php off <id> deletes it, its files and anything it kept aside
 *
 * The reading is a 3 s WAV in the Media Library (a soft tone, 8 kHz mono), with
 * a captions file, put on the post the way Use this recording does.
 */

if ( isset( $args[0] ) && 'off' === $args[0] ) {
	$id = isset( $args[1] ) ? (int) $args[1] : 0;
	foreach ( get_children( array( 'post_parent' => $id, 'post_type' => 'attachment' ) ) as $a ) {
		wp_delete_attachment( $a->ID, true );
	}
	global $wpdb;
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . rrt_table( 'recordings' ) . ' WHERE post_id = %d', $id ) );
	wp_delete_post( $id, true );
	return;
}

$rate = 8000;
$pcm  = '';
for ( $i = 0; $i < 3 * $rate; $i++ ) {
	$pcm .= pack( 'v', (int) round( 6000 * sin( 2 * M_PI * 220 * $i / $rate ) ) & 0xFFFF );
}
$wav  = 'RIFF' . pack( 'V', 36 + strlen( $pcm ) ) . 'WAVEfmt ' . pack( 'VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16 ) . 'data' . pack( 'V', strlen( $pcm ) ) . $pcm;
$post = wp_insert_post( array( 'post_title' => 'Keep test', 'post_status' => 'publish', 'post_content' => '<p>Leaves fall slowly. Rain taps the glass.</p>' ) );
wp_set_current_user( 1 );
$up   = wp_upload_dir();
$made = array();
foreach ( array( 'keep-test-reading.wav' => array( $wav, 'audio/wav' ), 'keep-test-captions.vtt' => array( "WEBVTT\n\n00:00.000 --> 00:03.000\nLeaves fall slowly.\n", 'text/vtt' ) ) as $name => $f ) {
	$path = trailingslashit( $up['path'] ) . wp_unique_filename( $up['path'], $name );
	file_put_contents( $path, $f[0] );
	$att = wp_insert_attachment( array( 'post_title' => pathinfo( $name, PATHINFO_FILENAME ), 'post_mime_type' => $f[1], 'post_status' => 'inherit' ), $path, $post );
	wp_update_attachment_metadata( $att, array( 'length' => 3, 'filesize' => strlen( $f[0] ) ) );
	$made[] = $att;
}
update_post_meta( $post, 'rrt_audio', $made[0] );
update_post_meta( $post, 'rrt_captions', $made[1] );
echo $post;
