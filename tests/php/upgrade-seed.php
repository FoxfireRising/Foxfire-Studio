<?php
/**
 * For tests/upgrade.py: run inside a test site with an older release active.
 * Saves settings the way the settings screen would (the release's own full
 * settings, with a few choices changed), puts a made-up row in every table
 * the release made, and gives a post and a person every field the plugin
 * has kept over the years. Then writes a snapshot of all of it to the file
 * given as the first argument, for upgrade-check.php to compare with.
 *
 * @package FoxfireStudio
 */

global $wpdb;
$out = isset( $args[0] ) ? $args[0] : '/tmp/foxfire-upgrade-before.json';

/* Settings: the release's own, with choices a site owner might have made. */
$s           = function_exists( 'rrt_settings' ) ? rrt_settings() : array();
$s['preset'] = 'custom';
if ( isset( $s['player'] ) && is_array( $s['player'] ) ) {
	$s['player']['label'] = 'Listen to this upgrade';
}
if ( isset( $s['text'] ) && is_array( $s['text'] ) ) {
	foreach ( $s['text'] as $k => $v ) {
		if ( is_string( $v ) ) {
			$s['text'][ $k ] = 'Upgrade test ' . $k;
			break;
		}
	}
}
if ( isset( $s['listen'] ) && is_array( $s['listen'] ) ) {
	$s['listen']['on'] = true;
}
/* A podcast loudness other than -19, which 1.6.0 reads as Custom with the same number. */
if ( isset( $s['sound']['target'] ) && ! isset( $s['sound']['finish'] ) ) {
	$s['sound']['mode']   = 'podcast';
	$s['sound']['target'] = -16;
}
update_option( 'rrt_settings', $s );

/* A made-up row in every table, from its own columns. */
$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'rrt_' ) . '%' ) );
$rows   = array();
foreach ( $tables as $t ) {
	$row = array();
	foreach ( $wpdb->get_results( "SHOW COLUMNS FROM $t", ARRAY_A ) as $c ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( false !== strpos( (string) $c['Extra'], 'auto_increment' ) ) {
			continue;
		}
		$type = strtolower( $c['Type'] );
		if ( preg_match( '/int|decimal|float|double/', $type ) ) {
			$v = 1;
		} elseif ( preg_match( '/datetime|timestamp/', $type ) ) {
			$v = '2001-03-20 12:00:00';
		} elseif ( 0 === strpos( $type, 'date' ) ) {
			$v = '2001-03-20';
		} elseif ( preg_match( '/char\((\d+)\)/', $type, $m ) ) {
			$v = substr( 'upgrade-test-value-0123456789abcdef', 0, min( 20, (int) $m[1] ) );
		} else {
			$v = '[]';
		}
		$row[ $c['Field'] ] = $v;
	}
	$wpdb->insert( $t, $row );
	if ( $wpdb->last_error ) {
		echo 'Could not seed ' . $t . ': ' . $wpdb->last_error . "\n";
	}
	$rows[ substr( $t, strlen( $wpdb->prefix ) ) ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

/* Every post and person field the plugin has used. */
$post = (int) $wpdb->get_var( "SELECT ID FROM $wpdb->posts WHERE post_type = 'post' AND post_status = 'publish' ORDER BY ID LIMIT 1" );
$pm   = array(
	'rrt_audio'    => '123',
	'rrt_captions' => '124',
	'rrt_credit'   => 'read by a tester',
	'_rrt_cues'    => array( array( 'i' => 3, 'k' => 'pause' ) ),
	'_rrt_learned' => array( 'fox' ),
);
foreach ( $pm as $k => $v ) {
	update_post_meta( $post, $k, $v );
}
$um = array(
	'rrt_learned'        => array( 'owls' ),
	/* One real goal, and one for a measure that no longer exists. */
	'rrt_goals'          => array( array( 'metric' => 'wpm', 'min' => 120.0, 'max' => 150.0 ), array( 'metric' => 'gone', 'min' => 1.0, 'max' => null ) ),
	'rrt_practice_notes' => array( 'note' ),
);
foreach ( $um as $k => $v ) {
	update_user_meta( 1, $k, $v );
}

file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions
	$out,
	wp_json_encode(
		array(
			'version'  => defined( 'RRT_VERSION' ) ? RRT_VERSION : '',
			'db'       => get_option( 'rrt_db_version', '' ),
			'settings' => get_option( 'rrt_settings' ),
			'rows'     => $rows,
			'post'     => $post,
			'postmeta' => $pm,
			'usermeta' => $um,
		)
	)
);
echo 'seeded ' . ( defined( 'RRT_VERSION' ) ? RRT_VERSION : '?' ) . ', database ' . get_option( 'rrt_db_version', 'none' ) . ', ' . count( $tables ) . " tables\n";
