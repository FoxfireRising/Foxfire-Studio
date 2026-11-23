<?php
/**
 * For tests/upgrade.py: run after the upgrade. Compares the site with the
 * snapshot upgrade-seed.php wrote (first argument) and with a fresh install
 * of the build under test (second argument, written by the "schema" mode of
 * this file: `wp eval-file upgrade-check.php schema /path.json`).
 *
 * @package FoxfireStudio
 */

global $wpdb;

/** Every table of the plugin with its columns, in order, and their types. */
$schema = function () use ( $wpdb ) {
	$out = array();
	foreach ( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'rrt_' ) . '%' ) ) as $t ) {
		$cols = array();
		foreach ( $wpdb->get_results( "SHOW COLUMNS FROM $t", ARRAY_A ) as $c ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$cols[ $c['Field'] ] = strtolower( $c['Type'] );
		}
		ksort( $cols );
		$out[ substr( $t, strlen( $wpdb->prefix ) ) ] = $cols;
	}
	ksort( $out );
	return $out;
};

if ( isset( $args[0] ) && 'schema' === $args[0] ) {
	file_put_contents( $args[1], wp_json_encode( array( 'db' => get_option( 'rrt_db_version' ), 'tables' => $schema(), 'version' => RRT_VERSION ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	echo 'schema of a fresh ' . RRT_VERSION . " written\n";
	return;
}

$before = json_decode( file_get_contents( $args[0] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
$fresh  = json_decode( file_get_contents( $args[1] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
$from   = $before['version'];
$pass   = 0;
$fail   = 0;
$check  = function ( $ok, $name, $detail = '' ) use ( &$pass, &$fail, $from ) {
	if ( $ok ) {
		++$pass;
		echo "ok   [$from] $name\n";
	} else {
		++$fail;
		echo "FAIL [$from] $name" . ( '' !== $detail ? " ($detail)" : '' ) . "\n";
	}
};

$check( defined( 'RRT_VERSION' ) && RRT_VERSION === $fresh['version'], 'the new version is the one running', defined( 'RRT_VERSION' ) ? RRT_VERSION : 'none' );
$active = (array) get_option( 'active_plugins', array() );
$check( in_array( 'foxfire-studio/foxfire-studio.php', $active, true ), 'Foxfire Studio is active' );
$check( ! in_array( 'reading-room-teleprompter/reading-room-teleprompter.php', $active, true ), 'the old copy is not active' );
$check( get_option( 'rrt_db_version' ) === $fresh['db'], 'the database is at version ' . $fresh['db'], (string) get_option( 'rrt_db_version' ) );

/* Tables and columns match a fresh install exactly. */
$now = $schema();
$check( array_keys( $now ) === array_keys( $fresh['tables'] ), 'every table of a fresh install is there, and no others', implode( ', ', array_keys( $now ) ) );
foreach ( $fresh['tables'] as $t => $cols ) {
	$have = isset( $now[ $t ] ) ? $now[ $t ] : array();
	$miss = array_diff_key( $cols, $have );
	$more = array_diff_key( $have, $cols );
	$diff = array();
	foreach ( $cols as $c => $type ) {
		if ( isset( $have[ $c ] ) && $have[ $c ] !== $type ) {
			$diff[] = "$c $have[$c] (fresh: $type)";
		}
	}
	$check( ! $miss && ! $more && ! $diff, "$t has the same columns as a fresh install", trim( ( $miss ? 'missing ' . implode( ' ', array_keys( $miss ) ) . '. ' : '' ) . ( $more ? 'extra ' . implode( ' ', array_keys( $more ) ) . '. ' : '' ) . implode( '; ', $diff ) ) );
}

/* Rows are kept. */
foreach ( $before['rows'] as $t => $n ) {
	$have = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}$t" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$check( $have >= $n, "the rows in $t are kept", "$have of $n" );
}

/* 1.2.0 (database 7): every visit still kept is summarised again, for read or listen and the rest. */
if ( version_compare( (string) $before['db'], '7', '<' ) ) {
	$waiting = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}rrt_sessions WHERE rolled = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$check( 0 === $waiting, 'every visit kept waits to be summarised again', "$waiting already marked as done" );
	$check( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}rrt_downloads" ), 'the new downloads table starts empty' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

/* Every saved setting the new version still has keeps its value. */
$saved_new = get_option( 'rrt_settings' );
$merged    = rrt_settings();
$lost      = array();
$walk      = function ( $old, $new, $path ) use ( &$walk, &$lost ) {
	foreach ( (array) $old as $k => $v ) {
		if ( ! is_array( $new ) || ! array_key_exists( $k, $new ) ) {
			continue; // A setting the new version no longer has.
		}
		if ( is_array( $v ) && array_keys( $v ) !== range( 0, count( $v ) - 1 ) ) {
			$walk( $v, $new[ $k ], $path . $k . '.' );
		} elseif ( 'sound.' === $path && in_array( $k, array( 'plosives', 'deess', 'breaths', 'compress' ), true ) && is_bool( $v ) ) {
			/* Voice polish (1.13.0): a step's switch becomes its strength, on as Standard (the same sound) and off as Off. */
			if ( $new[ $k ] !== ( $v ? 'standard' : 'off' ) ) {
				$lost[] = $path . $k . ': ' . wp_json_encode( $v ) . ' became ' . wp_json_encode( $new[ $k ] );
			}
		} elseif ( $new[ $k ] !== $v && ! ( is_scalar( $v ) && is_scalar( $new[ $k ] ) && (string) $new[ $k ] === (string) $v ) ) {
			$lost[] = $path . $k . ': ' . wp_json_encode( $v ) . ' became ' . wp_json_encode( $new[ $k ] );
		}
	}
};
$walk( $before['settings'], $merged, '' );
$check( ! $lost, 'every saved setting keeps its value', implode( '; ', array_slice( $lost, 0, 8 ) ) );
$check( 'custom' === $merged['preset'], 'the chosen look is kept', (string) $merged['preset'] );
if ( isset( $before['settings']['player']['label'] ) ) {
	$check( 'Listen to this upgrade' === $merged['player']['label'], 'the player label is kept', (string) $merged['player']['label'] );
}
if ( isset( $before['settings']['listen'] ) ) {
	$check( ! empty( $merged['listen']['on'] ), 'listener analytics that were on stay on' );
}
$check( is_array( $saved_new ), 'the settings are still saved' );
/* Finishing presets (1.6.0): an older podcast loudness of -16 becomes Custom at -16, mono, -1 dBTP. */
if ( isset( $before['settings']['sound']['target'] ) && ! isset( $before['settings']['sound']['finish'] ) ) {
	$snd = $merged['sound'];
	$check( 'custom' === $snd['finish'] && -16 == $snd['custom_lufs'] && 'mono' === $snd['custom_ch'] && -1 == $snd['custom_tp'], 'a podcast loudness of -16 becomes Custom at -16 LUFS, mono, -1 dBTP', wp_json_encode( $snd ) ); // phpcs:ignore Universal.Operators.StrictComparisons
	$spec = rrt_finish_spec( $snd );
	$check( -16 == $spec['lufs'] && 1 === $spec['ch'] && 'mp3' === $spec['format'], 'and the studio is given that preset', wp_json_encode( $spec ) ); // phpcs:ignore Universal.Operators.StrictComparisons
}

/* Post and person fields are kept. */
foreach ( $before['postmeta'] as $k => $v ) {
	$check( get_post_meta( (int) $before['post'], $k, true ) == $v, "the post field $k is kept" ); // phpcs:ignore Universal.Operators.StrictComparisons
}
foreach ( $before['usermeta'] as $k => $v ) {
	$check( get_user_meta( 1, $k, true ) == $v, "the person's field $k is kept" ); // phpcs:ignore Universal.Operators.StrictComparisons
}

/* The setup wizard (1.29.0): a site that had the plugin before is marked existing and never offered it. */
if ( function_exists( 'rrt_setup_state' ) ) {
	$check( 'existing' === rrt_setup_state()['status'] && ! rrt_setup_offered(), 'an upgraded site is not offered the setup wizard', wp_json_encode( get_option( 'rrt_setup' ) ) );
}

/* Embedded players (1.35.0): an upgraded site's YouTube and SoundCloud players stay as they were until someone switches this on. */
if ( function_exists( 'rrt_embeds_settings' ) ) {
	$check( array( 'yt' => false, 'sc' => false ) === rrt_embeds_settings() && ! is_dir( rrt_embeds_dir() ), 'an upgraded site has both Embedded players switches off, and no saved pictures', wp_json_encode( rrt_embeds_settings() ) );
}

echo "\n$pass passed, $fail failed\n";
if ( $fail ) {
	exit( 1 );
}
