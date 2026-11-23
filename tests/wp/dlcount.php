<?php
/**
 * Seeds the download counting browser test (1.24.0), or tidies up after it:
 *   wp eval-file tests/wp/dlcount.php        prints JSON for tests/browser/dlcount.py
 *   wp eval-file tests/wp/dlcount.php off    removes what it made
 *
 * Counting on, a review long due, 28 ordinary days of Overcast downloads and
 * today an unusual day of Apple's player on phones, with podcast consumers,
 * and a published reading whose short address the test asks for.
 */
global $wpdb;
$args = isset( $args ) ? $args : array();
$tc   = rrt_table( 'dl_counts' );
$tp   = rrt_table( 'dl_days' );
if ( isset( $args[0] ) && 'off' === $args[0] ) {
	$wpdb->query( "DELETE FROM $tc WHERE post_id >= 0" ); // phpcs:ignore
	$wpdb->query( "DELETE FROM $tp" ); // phpcs:ignore
	$wpdb->query( 'DELETE FROM ' . rrt_table( 'dl_seen' ) ); // phpcs:ignore
	$s = rrt_dl_settings();
	if ( $s['page'] ) {
		wp_delete_post( $s['page'], true );
	}
	foreach ( get_posts( array( 'post_type' => 'post', 'title' => 'Download counting test', 'post_status' => 'any', 'numberposts' => -1 ) ) as $p ) {
		wp_delete_post( $p->ID, true );
	}
	delete_option( 'rrt_dl' );
	update_option( 'rrt_podcast', array_merge( rrt_podcast_settings(), array( 'count' => false ) ) );
	echo "tidied\n";
	return;
}
update_option( 'rrt_podcast', array_merge( rrt_podcast_settings(), array( 'count' => true ) ) );
delete_option( 'rrt_dl' );
rrt_dl_settings_save( array_merge( rrt_dl_settings(), array( 'since' => wp_date( 'Y-m-d', time() - 300 * DAY_IN_SECONDS ), 'reviewed' => time() - 200 * DAY_IN_SECONDS ) ) );
$post = wp_insert_post( array( 'post_title' => 'Download counting test', 'post_content' => 'Words to read.', 'post_status' => 'publish' ) );
update_post_meta( $post, 'rrt_audio', 'https://example.com/wp-content/uploads/dl-browser-test.mp3' );
for ( $i = 1; $i <= 28; $i++ ) {
	$d = wp_date( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
	$wpdb->insert( $tc, array( 'day' => $d, 'post_id' => $post, 'app' => 'Overcast', 'device' => '', 'feed' => 'public', 'downloads' => 5 ) ); // phpcs:ignore
	$wpdb->insert( $tp, array( 'day' => $d, 'consumers' => 4, 'members' => 0 ) ); // phpcs:ignore
}
$today = wp_date( 'Y-m-d' );
$wpdb->insert( $tc, array( 'day' => $today, 'post_id' => $post, 'app' => 'AppleCoreMedia', 'device' => 'mobile', 'feed' => 'public', 'downloads' => 40 ) ); // phpcs:ignore
$wpdb->insert( $tp, array( 'day' => $today, 'consumers' => 38, 'members' => 0 ) ); // phpcs:ignore
echo wp_json_encode( array( 'post' => $post ) );
