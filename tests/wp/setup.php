<?php
/**
 * Seeds the setup wizard's browser test (1.29.0), run inside the test WordPress:
 *   php wp-cli.phar --path=<wordpress> eval-file tests/wp/setup.php       (prints JSON)
 *   php wp-cli.phar --path=<wordpress> eval-file tests/wp/setup.php off   (puts everything back)
 *
 * Makes the site look like a new install (no saved settings, setup offered)
 * and adds one published post for the studio's New here line. "off" puts the
 * settings and the wizard's record back as they were, deletes the post and
 * the practice takes the test made.
 */

$su_state = 'rrt_setup_test_state';

if ( isset( $args[0] ) && 'off' === $args[0] ) {
	$st = get_option( $su_state );
	if ( is_array( $st ) ) {
		wp_delete_post( (int) $st['post'], true );
		foreach ( array( 'rrt_settings', 'rrt_setup' ) as $k ) {
			if ( null === $st[ $k ] ) {
				delete_option( $k );
			} else {
				update_option( $k, $st[ $k ] );
			}
		}
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . rrt_table( 'takes' ) . ' WHERE post_id = 0' ); // phpcs:ignore WordPress.DB
	}
	delete_option( $su_state );
	return;
}

$post = wp_insert_post(
	array(
		'post_title'   => 'A setup test post',
		'post_content' => '<p>The kettle sang while the lamps came on along the quiet street.</p>',
		'post_status'  => 'publish',
		'post_author'  => 1,
	)
);
update_option(
	$su_state,
	array(
		'post'         => $post,
		'rrt_settings' => get_option( 'rrt_settings', null ),
		'rrt_setup'    => get_option( 'rrt_setup', null ),
	),
	false
);
delete_option( 'rrt_settings' );
update_option( 'rrt_setup', array( 'status' => 'new' ), false );
echo wp_json_encode( array( 'post' => $post ) );
