<?php
/**
 * The stronger cleanup (1.16.0), on the server: the model and loader shipped
 * with the plugin match their fingerprints, the setting is saved and
 * checked, and the studio is told where everything is, the site's own
 * runtime first.
 *
 * Run inside WordPress: wp eval-file tests/php/stronger.php
 */

$GLOBALS['st_pass'] = 0;
$GLOBALS['st_fail'] = 0;
function st_t( $ok, $name, $detail = '' ) {
	if ( $ok ) {
		++$GLOBALS['st_pass'];
		echo "ok   $name\n";
	} else {
		++$GLOBALS['st_fail'];
		echo "FAIL $name" . ( '' !== $detail ? ": $detail" : '' ) . "\n";
	}
}

/* The files shipped with the plugin. */
$st_ok = true;
foreach ( rrt_stronger_models() as $st_m ) {
	$st_p = RRT_DIR . 'assets/vendor/deepfilternet3/' . $st_m['file'];
	if ( ! file_exists( $st_p ) || ! hash_equals( $st_m['sha256'], hash_file( 'sha256', $st_p ) ) ) {
		$st_ok = false;
		echo "     {$st_m['file']} does not match\n";
	}
}
st_t( $st_ok, 'the model files match their fingerprints' );
st_t( hash_equals( rrt_stronger_loader_sha(), (string) hash_file( 'sha256', RRT_DIR . 'assets/vendor/onnxruntime-web/ort.wasm.min.js' ) ), 'the runtime loader matches its fingerprint' );
st_t( file_exists( RRT_DIR . 'assets/vendor/deepfilternet3/LICENSE-MIT.txt' ) && file_exists( RRT_DIR . 'assets/vendor/deepfilternet3/LICENSE-APACHE.txt' ) && file_exists( RRT_DIR . 'assets/vendor/deepfilternet3/README.txt' ), 'the model ships with both licences and its notes' );
st_t( file_exists( RRT_DIR . 'assets/vendor/onnxruntime-web/README.txt' ), 'the loader ships with its licence and notes' );
st_t( rrt_stronger_ready(), 'the plugin knows its files are there' );
/* git archive builds the release zip, so the model must be in the repository, not only on disk (.gitignore leaves out *.onnx elsewhere). */
$st_repo = dirname( RRT_DIR, 2 );
if ( is_dir( $st_repo . '/.git' ) && function_exists( 'shell_exec' ) ) {
	$st_listed = (string) shell_exec( 'git -C ' . escapeshellarg( $st_repo ) . ' ls-files plugin/foxfire-studio/assets/vendor/deepfilternet3/' ); // phpcs:ignore
	st_t( 3 === substr_count( $st_listed, '.onnx' ), 'the model files are tracked by git, so the release zip has them', trim( $st_listed ) );
}

/* The setting. */
$st_saved = get_option( 'rrt_settings' );
delete_option( 'rrt_settings' );
$st_cfg = rrt_config();
st_t( 'standard' === $st_cfg['sound']['engine'], 'a new site uses the standard engine' );
$st_in            = rrt_settings();
$st_in['sound']['engine']  = 'stronger';
$st_in['sound']['denoise'] = 'strong';
update_option( 'rrt_settings', rrt_sanitize_settings( $st_in ) );
$st_cfg = rrt_config();
st_t( 'stronger' === $st_cfg['sound']['engine'] && 'strong' === $st_cfg['sound']['denoise'], 'Stronger and Strong are saved' );
$st_in['sound']['engine'] = 'loudest';
update_option( 'rrt_settings', rrt_sanitize_settings( $st_in ) );
st_t( 'stronger' === rrt_config()['sound']['engine'], 'an unknown engine is refused and the saved one kept' );

/* What the studio is told. */
$st_boot = rrt_stronger_boot( $st_cfg );
st_t( is_array( $st_boot ) && 0 === strpos( $st_boot['worker'], RRT_URL . 'assets/js/deepfilter.js' ), 'the studio is told where the worker is', wp_json_encode( $st_boot ) );
st_t( false !== strpos( $st_boot['ort'], 'assets/vendor/onnxruntime-web/ort.wasm.min.js' ) && RRT_URL . 'assets/vendor/deepfilternet3/' === $st_boot['model'], 'the loader and the model come from the plugin' );
$st_h = rrt_whisper_hashes();
st_t( $st_boot['wasmSha'] === $st_h['lib/ort-wasm-simd-threaded.wasm'] && $st_boot['glueSha'] === $st_h['lib/ort-wasm-simd-threaded.js'], 'the runtime is checked against the same fingerprints as Whisper\'s' );
st_t( 3 === count( $st_boot['models'] ) && $st_boot['maxRtf'] > 0, 'it gets the three model files and a speed limit' );
st_t( false !== strpos( end( $st_boot['wasm'] ), 'cdn.jsdelivr.net/npm/onnxruntime-web@' . RRT_ORT_VERSION . '/dist/ort-wasm-simd-threaded.wasm' ), 'without Whisper on the site the runtime comes from jsDelivr, at the pinned version', wp_json_encode( $st_boot['wasm'] ) );

/* With Whisper's runtime on the site, that copy comes first. */
list( $st_dir, $st_url ) = rrt_whisper_home();
$st_made = array();
$st_new  = ! is_dir( $st_dir . 'lib' ) ? array( $st_dir . 'lib', ( is_dir( $st_dir ) ? '' : $st_dir ) ) : array();
wp_mkdir_p( $st_dir . 'lib' );
foreach ( array( 'lib/ort-wasm-simd-threaded.wasm', 'lib/ort-wasm-simd-threaded.js', 'lib/transformers.min.js' ) as $st_f ) {
	if ( ! file_exists( $st_dir . $st_f ) ) {
		file_put_contents( $st_dir . $st_f, 'x' ); // phpcs:ignore
		$st_made[] = $st_dir . $st_f;
	}
}
list( $st_dir, $st_url ) = rrt_whisper_home();
$st_boot = rrt_stronger_boot( rrt_config() );
st_t( $st_url && $st_url . 'lib/ort-wasm-simd-threaded.wasm' === $st_boot['wasm'][0] && $st_url . 'lib/ort-wasm-simd-threaded.js' === $st_boot['glue'][0] && 2 === count( $st_boot['wasm'] ), 'with Whisper\'s files on the site, its runtime is tried first', wp_json_encode( $st_boot['wasm'] ) );
foreach ( $st_made as $st_f ) {
	wp_delete_file( $st_f );
}
foreach ( array_filter( $st_new ) as $st_d ) {
	if ( is_dir( $st_d ) && 2 === count( scandir( $st_d ) ) ) {
		rmdir( $st_d ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}

/* The studio page carries it, beside RNNoise's settings. */
$st_render = (string) file_get_contents( RRT_DIR . 'includes/render.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
st_t( false !== strpos( $st_render, "'stronger' => rrt_stronger_boot( \$rrt_cfg )" ) && false !== strpos( $st_render, "'engine'   => \$rrt_cfg['sound']['engine']" ), 'the studio is told the engine and how to run the stronger one' );
if ( false !== $st_saved ) {
	update_option( 'rrt_settings', $st_saved );
} else {
	delete_option( 'rrt_settings' );
}

echo "\n{$GLOBALS['st_pass']} passed, {$GLOBALS['st_fail']} failed\n";
if ( $GLOBALS['st_fail'] ) {
	exit( 1 );
}
