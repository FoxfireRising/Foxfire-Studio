<?php
/**
 * Seed for tests/browser/offload.py (1.23.0): a published post with a real
 * MP3 reading and captions.
 *   wp eval-file tests/wp/offload.php <mp3>    prints {"post", "mp3", "sha"}
 *   wp eval-file tests/wp/offload.php off <post>
 */

$args = isset( $args ) ? $args : array();

if ( isset( $args[0] ) && 'off' === $args[0] ) {
	$post = isset( $args[1] ) ? (int) $args[1] : 0;
	if ( $post ) {
		foreach ( array( 'rrt_audio', 'rrt_captions' ) as $k ) {
			$a = (int) get_post_meta( $post, $k, true );
			if ( $a ) {
				wp_delete_attachment( $a, true );
			}
		}
		wp_delete_post( $post, true );
	}
	delete_option( 'rrt_offload' );
	delete_option( 'rrt_offload_files' );
	return;
}

$src  = isset( $args[0] ) ? $args[0] : '';
$up   = wp_upload_dir();
$post = wp_insert_post(
	array(
		'post_title'   => 'Storage offload test reading',
		'post_content' => '<p>Words for the storage test.</p>',
		'post_status'  => 'publish',
	)
);
$dest = trailingslashit( $up['path'] ) . wp_unique_filename( $up['path'], 'storage-offload-test.mp3' );
copy( $src, $dest );
$mp3 = wp_insert_attachment(
	array(
		'post_mime_type' => 'audio/mpeg',
		'post_title'     => 'storage-offload-test.mp3',
		'post_status'    => 'inherit',
	),
	$dest
);
$vtt_path = trailingslashit( $up['path'] ) . wp_unique_filename( $up['path'], 'storage-offload-test.vtt' );
file_put_contents( $vtt_path, "WEBVTT\n\n00:00.000 --> 00:02.000\nWords for the storage test.\n" );
$vtt = wp_insert_attachment(
	array(
		'post_mime_type' => 'text/vtt',
		'post_title'     => 'storage-offload-test.vtt',
		'post_status'    => 'inherit',
	),
	$vtt_path
);
update_post_meta( $post, 'rrt_audio', $mp3 );
update_post_meta( $post, 'rrt_captions', $vtt );
delete_option( 'rrt_offload' );
delete_option( 'rrt_offload_files' );
echo wp_json_encode(
	array(
		'post' => $post,
		'mp3'  => wp_get_attachment_url( $mp3 ),
		'sha'  => hash_file( 'sha256', $dest ),
	)
);
