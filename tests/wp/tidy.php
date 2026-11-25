<?php
/**
 * Readings for the Recordings browser test (1.36.2):
 *   wp eval-file tests/wp/tidy.php   makes them and prints JSON with their ids
 *   wp eval-file tests/wp/tidy.php off   deletes everything it made
 *
 * A live post with a replaced reading and a current one, and a post in the
 * Trash with its reading. Every title starts with "Tidy browser".
 */

global $wpdb;
wp_set_current_user( 1 );
rrt_stats_install();

$tb_all = function () use ( $wpdb ) {
	return array_map( 'intval', $wpdb->get_col( "SELECT ID FROM $wpdb->posts WHERE post_title LIKE 'Tidy browser%'" ) );
};
if ( isset( $args[0] ) && 'off' === $args[0] ) {
	foreach ( $tb_all() as $id ) {
		foreach ( get_children( array( 'post_parent' => $id, 'post_type' => 'attachment' ) ) as $a ) {
			wp_delete_attachment( $a->ID, true );
		}
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . rrt_table( 'recordings' ) . ' WHERE post_id = %d', $id ) );
		wp_delete_post( $id, true );
	}
	foreach ( $wpdb->get_col( "SELECT ID FROM $wpdb->posts WHERE post_type = 'attachment' AND post_title LIKE 'tidy-browser-%'" ) as $a ) {
		wp_delete_attachment( (int) $a, true );
	}
	return;
}

$tb_mp3  = "\xFF\xFB\x10\xC4" . str_repeat( "\0", 100 );
$tb_read = function ( $post, $name ) use ( $tb_mp3 ) {
	$up  = wp_upload_dir();
	$ids = array();
	foreach ( array( $name . '.mp3' => array( $tb_mp3, 'audio/mpeg' ), $name . '.vtt' => array( "WEBVTT\n", 'text/vtt' ) ) as $file => $f ) {
		$path = trailingslashit( $up['path'] ) . wp_unique_filename( $up['path'], $file );
		file_put_contents( $path, $f[0] );
		$ids[] = wp_insert_attachment( array( 'post_title' => pathinfo( $file, PATHINFO_FILENAME ), 'post_mime_type' => $f[1], 'post_status' => 'inherit' ), $path, $post );
	}
	update_post_meta( $post, 'rrt_audio', $ids[0] );
	update_post_meta( $post, 'rrt_captions', $ids[1] );
	$rec = rrt_note_recording( $post, array( 'attachment_id' => $ids[0], 'source' => 'upload', 'duration_s' => 3 ) );
	return array( 'mp3' => $ids[0], 'vtt' => $ids[1], 'rec' => $rec );
};
$live  = wp_insert_post( array( 'post_title' => 'Tidy browser live', 'post_status' => 'publish', 'post_content' => 'Words.' ) );
$old   = $tb_read( $live, 'tidy-browser-old' );
$cur   = $tb_read( $live, 'tidy-browser-current' );
$gone  = wp_insert_post( array( 'post_title' => 'Tidy browser trashed', 'post_status' => 'publish', 'post_content' => 'Words.' ) );
$trash = $tb_read( $gone, 'tidy-browser-trashed' );
wp_trash_post( $gone );
echo wp_json_encode( array( 'live' => $live, 'old' => $old, 'cur' => $cur, 'trashed' => $gone, 'trash' => $trash ) );
