<?php
/**
 * Full-length video (1.18.0) on the server, run inside the test WordPress:
 *   php wp-cli.phar --path=<wordpress> eval-file tests/php/video.php
 * Prints one line per check and exits 1 if any fail. Leaves nothing behind.
 */

$GLOBALS['vd_pass'] = 0;
$GLOBALS['vd_fail'] = array();

function vd_t( $ok, $name ) {
	if ( $ok ) {
		++$GLOBALS['vd_pass'];
		echo "ok   $name\n";
	} else {
		$GLOBALS['vd_fail'][] = $name;
		echo "FAIL $name\n";
	}
}

wp_set_current_user( 1 );
$vd_podcast = get_option( 'rrt_podcast', null );
$vd_posts   = array();
register_shutdown_function(
	function () use ( &$vd_posts, $vd_podcast ) {
		foreach ( $vd_posts as $id ) {
			foreach ( get_children( array( 'post_parent' => $id, 'post_type' => 'attachment' ) ) as $a ) {
				wp_delete_attachment( $a->ID, true );
			}
			wp_delete_post( $id, true );
		}
		if ( null === $vd_podcast ) {
			delete_option( 'rrt_podcast' );
		} else {
			update_option( 'rrt_podcast', $vd_podcast );
		}
	}
);

vd_t( function_exists( 'rrt_video_section' ) && function_exists( 'rrt_video_boot' ), 'the video functions are loaded' );
vd_t( array( 'standard' => 5000000, 'smaller' => 2500000 ) === rrt_video_qualities(), 'Standard is 5 Mbps and Smaller file 2.5 Mbps' );

/* A post with no reading: no section. */
$plain      = wp_insert_post( array( 'post_title' => 'No reading', 'post_status' => 'publish', 'post_content' => '<p>Words.</p>' ) );
$vd_posts[] = $plain;
ob_start();
rrt_video_section( $plain );
vd_t( '' === ob_get_clean(), 'a post with no reading has no video section' );

/* A post with a reading, captions, an excerpt and chapters kept by the feed. */
$post       = wp_insert_post( array( 'post_title' => 'Video &amp; server', 'post_status' => 'publish', 'post_excerpt' => '', 'post_content' => '<p>' . str_repeat( 'Owls call softly to the moon. ', 30 ) . '[gallery]</p>' ) );
$vd_posts[] = $post;
update_post_meta( $post, 'rrt_audio', 'http://example.test/wp-content/uploads/reading.mp3' );
update_post_meta( $post, 'rrt_captions', 'http://example.test/wp-content/uploads/reading.vtt' );
/*
 * The feed's key includes the post's modified time, so the chapters are kept
 * again under the fresh key after any update to the post (on a slow machine
 * the update can land in a later second and the old record would not count).
 */
function vd_keep_chapters( $post ) {
	update_post_meta(
		$post,
		RRT_FEED_META,
		array(
			'key'      => rrt_feed_key( $post ),
			'chapters' => array(
				array( 'start' => 0, 'end' => 60, 'title' => 'One' ),
				array( 'start' => 60, 'end' => 120, 'title' => '' ),
				array( 'start' => 120.4567, 'end' => 180, 'title' => 'Three' ),
			),
			'top'      => null,
		)
	);
}
vd_keep_chapters( $post );
$clip = rrt_clip_boot( $post );
$boot = rrt_video_boot( $clip, $post );
vd_t( is_array( $boot ) && $boot['audio'] === $clip['audio'] && 'Video & server' === $boot['title'], 'the boot carries the reading and the plain title' );
vd_t( 'http://example.test/wp-content/uploads/reading.vtt' === $boot['captions'], 'the boot carries the captions\' address' );
vd_t( 2 === count( $boot['chapters'] ) && 'Three' === $boot['chapters'][1]['title'] && 120.457 === $boot['chapters'][1]['start'], 'chapters come from the feed\'s, untitled ones left out' );
$words = explode( ' ', $boot['excerpt'] );
vd_t( 55 === count( $words ) && '...' === substr( $boot['excerpt'], -3 ) && false === strpos( $boot['excerpt'], '[gallery]' ), 'with no excerpt, the description starts with the first 55 words, shortcodes gone' );
vd_t( '' === $boot['cover'], 'no cover art until the podcast feed has one' );
vd_t( true === $boot['choose'], 'someone who can upload can choose a picture from the Media Library' );
wp_update_post( array( 'ID' => $post, 'post_excerpt' => 'A <em>spell</em> &amp; a song.' ) );
vd_keep_chapters( $post );
vd_t( 'A spell & a song.' === rrt_video_excerpt( $post ), 'a written excerpt is used as plain text' );

/* The podcast's cover art as the picture to fall back on. */
$up   = wp_upload_dir();
$path = trailingslashit( $up['path'] ) . 'video-cover-test.png';
/* A 1 by 1 PNG is enough here (no image library needed on the oldest test setup). */
file_put_contents( $path, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' ) ); // phpcs:ignore
$att = wp_insert_attachment( array( 'post_title' => 'cover', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ), $path, $post );
wp_update_attachment_metadata( $att, array( 'width' => 1, 'height' => 1, 'file' => _wp_relative_upload_path( $path ) ) );
$pc          = get_option( 'rrt_podcast', array() );
$pc          = is_array( $pc ) ? $pc : array();
$pc['image'] = $att;
update_option( 'rrt_podcast', $pc );
vd_t( false !== strpos( rrt_video_cover(), 'video-cover-test' ), 'the show\'s cover art is offered as a picture' );

/* The section on the page. */
ob_start();
rrt_video_section( $post );
$html = ob_get_clean();
vd_t( false !== strpos( $html, 'id="rrt-video"' ) && false !== strpos( $html, 'data-rrt-video' ) && false !== strpos( $html, 'id="rrt-video-boot"' ), 'the section, its place for the maker and its boot are on the page' );
vd_t( false !== strpos( $html, 'nothing is kept on the site or sent anywhere' ), 'the section says nothing is kept or sent' );
vd_t( wp_script_is( 'rrt-video', 'enqueued' ) && wp_script_is( 'rrt-clip', 'enqueued' ) && wp_script_is( 'rrt-mediabunny', 'enqueued' ) && wp_script_is( 'rrt-can', 'enqueued' ) && wp_style_is( 'rrt-video', 'enqueued' ), 'the video maker, the clip drawing, Mediabunny, the browser check and the styles are loaded' );
preg_match( '#<script type="application/json" id="rrt-video-boot">(.*?)</script>#s', $html, $m );
$parsed = isset( $m[1] ) ? json_decode( $m[1], true ) : null;
vd_t( is_array( $parsed ) && 2 === count( $parsed['chapters'] ) && isset( $parsed['qualities']['standard'] ), 'the boot on the page reads back as JSON' );

/* Someone who cannot edit the post gets nothing. */
$sub = wp_insert_user( array( 'user_login' => 'vd_sub', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
wp_set_current_user( $sub );
ob_start();
rrt_video_section( $post );
vd_t( '' === ob_get_clean(), 'someone who cannot edit the post gets no video section' );
wp_set_current_user( 1 );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $sub );

/* 1.30.0: Mediabunny and the AAC add-on ship with the plugin, matching their fingerprints; mp4-muxer is gone. */
$vd_mb = true;
foreach ( rrt_clip_vendor_hashes() as $vd_n => $vd_h ) {
	$vd_p = RRT_DIR . 'assets/vendor/mediabunny/' . $vd_n;
	if ( ! file_exists( $vd_p ) || ! hash_equals( $vd_h, hash_file( 'sha256', $vd_p ) ) ) {
		$vd_mb = false;
		echo "     $vd_n does not match\n";
	}
}
vd_t( $vd_mb, 'Mediabunny and the AAC add-on match their fingerprints' );
vd_t( ! file_exists( RRT_DIR . 'assets/vendor/mp4-muxer.js' ), 'mp4-muxer is no longer shipped' );
$vd_assets = rrt_clip_assets();
vd_t( false !== strpos( $vd_assets['mediabunny'], 'vendor/mediabunny/mediabunny.min.js' ) && false !== strpos( $vd_assets['aac'], 'mediabunny-aac-encoder.min.js' ), 'the studio is told where Mediabunny and the add-on are' );

echo $GLOBALS['vd_pass'] . ' passed, ' . count( $GLOBALS['vd_fail'] ) . " failed\n";
if ( $GLOBALS['vd_fail'] ) {
	exit( 1 );
}
