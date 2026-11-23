<?php
/**
 * A collection for the audiobook browser test (1.20.0):
 *   wp eval-file tests/wp/audiobook.php <folder>   makes it from the files tests/wp/audiobook-mp3.js wrote, and prints its ids as JSON
 *   wp eval-file tests/wp/audiobook.php off <ids>  deletes the posts, the collection and its credits, and their files
 *
 * Three readings: Samhain with an audiobook copy, Yule plain (made from its
 * post's MP3, with captions that start with its title), and Imbolc with the
 * music bed (it needs a copy; its text is the studio take's script). The
 * opening credits are saved and recorded; the closing credits are not. The
 * retail sample is a minute of Yule. The cover is a tiny JPEG said to be
 * 3000 by 3000.
 */

if ( isset( $args[0] ) && 'off' === $args[0] ) {
	$ids = array_map( 'intval', explode( ',', isset( $args[1] ) ? $args[1] : '' ) );
	foreach ( get_posts( array( 'post_type' => array( 'rrt_collection', 'post' ), 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 's' => 'Audiobook test' ) ) as $c ) {
		$ids[] = (int) $c;
	}
	foreach ( array_unique( array_filter( $ids ) ) as $id ) {
		if ( ! get_post( $id ) ) {
			continue;
		}
		foreach ( get_children( array( 'post_parent' => $id, 'post_type' => 'attachment', 'numberposts' => -1 ) ) as $a ) {
			wp_delete_attachment( $a->ID, true );
		}
		wp_delete_post( $id, true );
	}
	return;
}

$dir = rtrim( isset( $args[0] ) ? $args[0] : '', '/' );
$up  = wp_upload_dir();

/** A file in uploads as an attachment of a post. */
$attach = function ( $post, $name, $src, $mime ) use ( $up ) {
	$path = trailingslashit( $up['path'] ) . 'audiobook-test-' . $post . '-' . $name;
	copy( $src, $path );
	$a = wp_insert_attachment( array( 'post_title' => $name, 'post_mime_type' => $mime, 'post_status' => 'inherit' ), $path, $post );
	update_attached_file( $a, $path );
	return $a;
};
$tmp = function ( $bytes ) {
	$f = wp_tempnam( 'audiobook-test' );
	file_put_contents( $f, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	return $f;
};

$out = array();
foreach ( array(
	'a' => array( 'Audiobook test Samhain', '<p>A reading for the audiobook test.</p>' ),
	'b' => array( 'Audiobook test Yule', '<p>A reading for the audiobook test.</p>' ),
	'c' => array( 'Audiobook test Imbolc', '<p>Leaves fall slowly. Rain taps the glass.</p>' ),
) as $k => $p ) {
	$id = wp_insert_post( array( 'post_title' => $p[0], 'post_status' => 'publish', 'post_content' => $p[1] ) );
	$a  = $attach( $id, 'reading.mp3', $dir . '/' . $k . '.mp3', 'audio/mpeg' );
	update_post_meta( $id, 'rrt_audio', $a );
	$out[ $k ] = $id;
}
$v = $attach( $out['b'], 'reading.vtt', $tmp( "WEBVTT\n\n1\n00:00:00.250 --> 00:00:01.850\nAudiobook test Yule.\n\n2\n00:00:02.600 --> 00:00:04.200\nThe first phrase.\n" ), 'text/vtt' );
update_post_meta( $out['b'], 'rrt_captions', $v );
update_post_meta( $out['c'], '_rrt_music_used', array( 'audio' => (int) get_post_meta( $out['c'], 'rrt_audio', true ), 'bed' => 1, 'credit' => '' ) );
rrt_book_store( $out['a'], $dir . '/a-book.mp3', array( 'from' => 'take', 'rms' => -20 ) );

$col = wp_insert_post( array( 'post_title' => 'Audiobook test: the turning year', 'post_status' => 'draft', 'post_type' => 'rrt_collection', 'post_content' => 'Three readings.' ) );
update_post_meta( $col, RRT_COL_META, array( 'items' => array( $out['a'], $out['b'], $out['c'] ), 'gap' => 2, 'feed' => true, 'type' => 'bonus' ) );
update_post_meta( $col, RRT_BOOK_COL_META, array( 'post' => $out['b'], 'start' => null, 'len' => 1 ) );
$open = rrt_book_credit_save( $col, 'open', "Audiobook test: the turning year.\nWritten by Robin. Narrated by Robin." );
rrt_book_store( $open, $dir . '/open.mp3', array( 'from' => 'credit' ) );

/* The cover: the smallest JPEG there is, with sizes that say 3000 by 3000. */
$jpeg  = base64_decode( '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
$cover = $attach( $col, 'cover.jpg', $tmp( $jpeg ), 'image/jpeg' );
wp_update_attachment_metadata( $cover, array( 'width' => 3000, 'height' => 3000, 'file' => get_post_meta( $cover, '_wp_attached_file', true ), 'sizes' => array() ) );
set_post_thumbnail( $col, $cover );

$out['col']   = $col;
$out['open']  = $open;
$out['cover'] = md5( $jpeg );
echo wp_json_encode( $out );
