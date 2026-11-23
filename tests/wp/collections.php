<?php
/**
 * Three posts with readings for the collections browser test (1.19.0):
 *   wp eval-file tests/wp/collections.php <folder>   makes them from a.mp3, b.mp3 and c.mp3 in the folder (tests/wp/collections-mp3.js) and prints their ids as JSON
 *   wp eval-file tests/wp/collections.php off <ids>  deletes them, their files, and every collection the test made
 *
 * The first reading has captions of one cue a phrase.
 */

if ( isset( $args[0] ) && 'off' === $args[0] ) {
	$ids = array_map( 'intval', explode( ',', isset( $args[1] ) ? $args[1] : '' ) );
	foreach ( get_posts( array( 'post_type' => 'rrt_collection', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 's' => 'Collection test' ) ) as $c ) {
		$ids[] = (int) $c;
	}
	foreach ( array_filter( $ids ) as $id ) {
		foreach ( get_children( array( 'post_parent' => $id, 'post_type' => 'attachment', 'numberposts' => -1 ) ) as $a ) {
			wp_delete_attachment( $a->ID, true );
		}
		wp_delete_post( $id, true );
	}
	return;
}

$dir   = rtrim( isset( $args[0] ) ? $args[0] : '', '/' );
$up    = wp_upload_dir();
$posts = array();
foreach ( array(
	'a' => 'Collection test Samhain',
	'b' => 'Collection test Yule',
	'c' => 'Collection test Imbolc',
) as $k => $title ) {
	$p    = wp_insert_post( array( 'post_title' => $title, 'post_status' => 'publish', 'post_content' => '<p>A reading for the collections test.</p>' ) );
	$path = trailingslashit( $up['path'] ) . 'collection-test-' . $k . '-' . $p . '.mp3';
	copy( $dir . '/' . $k . '.mp3', $path );
	$a = wp_insert_attachment( array( 'post_title' => $title . ' (reading)', 'post_mime_type' => 'audio/mpeg', 'post_status' => 'inherit' ), $path, $p );
	update_attached_file( $a, $path );
	update_post_meta( $p, 'rrt_audio', $a );
	if ( 'a' === $k ) {
		$vtt  = "WEBVTT\n\n1\n00:00:00.600 --> 00:00:01.900\nThe first phrase.\n\n2\n00:00:02.500 --> 00:00:03.800\nThe second phrase.\n";
		$vp   = trailingslashit( $up['path'] ) . 'collection-test-a-' . $p . '.vtt';
		file_put_contents( $vp, $vtt ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$v = wp_insert_attachment( array( 'post_title' => $title . ' (captions)', 'post_mime_type' => 'text/vtt', 'post_status' => 'inherit' ), $vp, $p );
		update_attached_file( $v, $vp );
		update_post_meta( $p, 'rrt_captions', $v );
	}
	$posts[ $k ] = $p;
}
echo wp_json_encode( $posts );
