<?php
/**
 * Voice following (1.14.0), run inside the test WordPress:
 *   php wp-cli.phar --path=<wordpress> eval-file tests/php/follow.php
 * The model's files and their fingerprints, pinned to one revision; the
 * downloader taking the 'follow' set; what the studio is told (Hugging Face
 * only, then this site first once every file is here, and Hugging Face only
 * again when Whisper is set to load from outside); the engine files shipped
 * with the plugin matching their fingerprints; and an unknown set falling
 * back to Whisper's own. Prints one line per check and exits 1 if any fail.
 * Leaves nothing behind.
 */

$GLOBALS['fo_pass'] = 0;
$GLOBALS['fo_fail'] = array();

function fo_t( $ok, $name, $detail = '' ) {
	if ( $ok ) {
		++$GLOBALS['fo_pass'];
		echo "ok   $name\n";
	} else {
		$GLOBALS['fo_fail'][] = $name;
		echo "FAIL $name" . ( '' !== $detail ? ": $detail" : '' ) . "\n";
	}
}

/* The files and their fingerprints. */
$fo_files  = rrt_whisper_files( 'follow' );
$fo_hashes = rrt_whisper_hashes();
fo_t( 4 === count( $fo_files ), 'four model files', (string) count( $fo_files ) );
fo_t( (bool) preg_match( '/^[0-9a-f]{40}$/', RRT_FOLLOW_REV ), 'the model is pinned to one revision' );
$fo_ok = true;
foreach ( $fo_files as $f ) {
	if ( 0 !== strpos( $f['from'], 'https://huggingface.co/' . RRT_FOLLOW_MODEL . '/resolve/' . RRT_FOLLOW_REV . '/' ) ) {
		$fo_ok = false;
	}
	if ( empty( $fo_hashes[ $f['to'] ] ) || ! preg_match( '/^[0-9a-f]{64}$/', $fo_hashes[ $f['to'] ] ) ) {
		$fo_ok = false;
	}
}
fo_t( $fo_ok, 'every file comes from the pinned revision and has a fingerprint' );
fo_t( count( rrt_whisper_files( 'base' ) ) === count( rrt_whisper_files( 'nonsense' ) ), 'an unknown set is the standard Whisper set' );
$fo_names = rrt_follow_names();
fo_t( array( 'tokens', 'joiner', 'decoder', 'encoder' ) === array_keys( $fo_names ), 'the four parts are named for the worker' );

/* The engine shipped with the plugin. */
$fo_eng = true;
foreach ( rrt_follow_engine_hashes() as $n => $h ) {
	$p = RRT_DIR . 'assets/vendor/sherpa-onnx/' . $n;
	if ( ! file_exists( $p ) || ! hash_equals( $h, hash_file( 'sha256', $p ) ) ) {
		$fo_eng = false;
		echo "     $n does not match\n";
	}
}
fo_t( $fo_eng, 'the engine files match their fingerprints' );
fo_t( file_exists( RRT_DIR . 'assets/vendor/sherpa-onnx/LICENSE.txt' ) && file_exists( RRT_DIR . 'assets/vendor/sherpa-onnx/README.txt' ), 'the engine ships with its licence and notes' );

/* What the studio is told. */
$fo_cfg  = rrt_config();
$fo_boot = rrt_follow_boot( $fo_cfg );
list( $fo_dir ) = rrt_whisper_home();
$fo_have = file_exists( $fo_dir . RRT_FOLLOW_MODEL . '/tokens.txt' );
if ( ! $fo_have ) {
	fo_t( 1 === count( $fo_boot['hosts'] ) && 'Hugging Face' === $fo_boot['hosts'][0]['label'], 'with no files here, only Hugging Face' );
}
fo_t( false !== strpos( $fo_boot['worker'], 'assets/js/follow-worker.js' ) && false !== strpos( $fo_boot['worker'], 'ver=' . RRT_VERSION ), 'the worker address carries the version' );
fo_t( false !== strpos( $fo_boot['engine'], 'assets/vendor/sherpa-onnx/' ), 'the engine loads from the plugin' );
fo_t( $fo_boot['bytes'] > 40000000 && $fo_boot['bytes'] < 50000000, 'the model size for the loading line', (string) $fo_boot['bytes'] );

/* Every file on this site: the site comes first. Made-up files, removed afterwards. */
$fo_made = array();
if ( ! $fo_have ) {
	foreach ( $fo_files as $f ) {
		$p = $fo_dir . $f['to'];
		wp_mkdir_p( dirname( $p ) );
		file_put_contents( $p, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$fo_made[] = $p;
	}
	$fo_boot = rrt_follow_boot( $fo_cfg );
	fo_t( 2 === count( $fo_boot['hosts'] ) && 'site' === $fo_boot['hosts'][0]['label'] && false !== strpos( $fo_boot['hosts'][0]['url'], RRT_FOLLOW_MODEL . '/' ), 'with every file here, this site first, Hugging Face after' );
	$fo_st = rrt_whisper_status( 'follow' );
	fo_t( 4 === $fo_st['have'] && 4 === $fo_st['count'], 'the status list counts them' );
	$fo_cfg['whisper']['source'] = 'remote';
	$fo_boot = rrt_follow_boot( $fo_cfg );
	fo_t( 1 === count( $fo_boot['hosts'] ), 'set to load from outside: Hugging Face only' );
	/* One file missing: not complete, so not offered. */
	wp_delete_file( $fo_made[3] );
	$fo_cfg['whisper']['source'] = 'auto';
	$fo_boot = rrt_follow_boot( $fo_cfg );
	fo_t( 1 === count( $fo_boot['hosts'] ), 'a file missing: Hugging Face only' );
	foreach ( $fo_made as $p ) {
		if ( file_exists( $p ) ) {
			wp_delete_file( $p );
		}
	}
	$d = dirname( $fo_made[0] );
	while ( strlen( $d ) > strlen( $fo_dir ) && is_dir( $d ) && 2 === count( scandir( $d ) ) ) {
		rmdir( $d ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$d = dirname( $d );
	}
	if ( is_dir( $fo_dir ) && 2 === count( scandir( $fo_dir ) ) ) {
		rmdir( $fo_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}

/* The studio page's boot data carries it. */
fo_t( false !== strpos( (string) file_get_contents( RRT_DIR . 'includes/render.php' ), "'follow'    => rrt_follow_boot( \$rrt_cfg )" ), 'the studio is told about voice following' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

echo "\n{$GLOBALS['fo_pass']} passed, " . count( $GLOBALS['fo_fail'] ) . " failed\n";
if ( $GLOBALS['fo_fail'] ) {
	exit( 1 );
}
