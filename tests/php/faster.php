<?php
/**
 * The faster review (1.17.0), on the server: the larger model's graphics
 * chip files are pinned like its others, the Download fetches them with the
 * rest, and the studio is told the site's copy only when every file a way
 * of running needs is here, so a site with the older seven files keeps its
 * processor copy while the graphics chip loads from Hugging Face.
 *
 * Run inside WordPress: wp eval-file tests/php/faster.php
 */

$GLOBALS['fr_pass'] = 0;
$GLOBALS['fr_fail'] = 0;
function fr_t( $ok, $name, $detail = '' ) {
	if ( $ok ) {
		++$GLOBALS['fr_pass'];
		echo "ok   $name\n";
	} else {
		++$GLOBALS['fr_fail'];
		echo "FAIL $name" . ( '' !== $detail ? ": $detail" : '' ) . "\n";
	}
}

/* The files. */
$fr_gpu   = rrt_whisper_gpu_files();
$fr_files = rrt_whisper_files( 'small' );
$fr_to    = wp_list_pluck( $fr_files, 'to' );
fr_t( array( 'onnx/encoder_model_fp16.onnx', 'onnx/decoder_model_merged_q4f16.onnx' ) === $fr_gpu, 'the graphics chip needs the half-precision encoder and the 4-bit half-precision decoder' );
fr_t( 9 === count( $fr_files ), 'the larger model\'s Download now has nine files', wp_json_encode( $fr_to ) );
$fr_h  = rrt_whisper_hashes();
$fr_ok = true;
foreach ( $fr_files as $fr_f ) {
	if ( empty( $fr_h[ $fr_f['to'] ] ) || 64 !== strlen( $fr_h[ $fr_f['to'] ] ) ) {
		$fr_ok = false;
		echo "     no fingerprint for {$fr_f['to']}\n";
	}
	if ( false === strpos( $fr_f['from'], '/resolve/' . RRT_WHISPER_SMALL_REV . '/' ) ) {
		$fr_ok = false;
		echo "     {$fr_f['from']} is not pinned\n";
	}
}
fr_t( $fr_ok, 'every file has a fingerprint and comes from the pinned revision' );
fr_t( '3533ef795a540b0257b4dfad9edabce0b69846ab09f0fc2313319dfebfddb53d' === $fr_h[ RRT_WHISPER_SMALL . '/onnx/encoder_model_fp16.onnx' ] && '0d38a3ab3d034990af3c73663acfedd7060aedfa41f4a2f74b0833aa5db5823b' === $fr_h[ RRT_WHISPER_SMALL . '/onnx/decoder_model_merged_q4f16.onnx' ], 'the fingerprints are Hugging Face\'s published ones' );
fr_t( 12 === count( rrt_whisper_files( 'base' ) ), 'the standard set is unchanged', (string) count( rrt_whisper_files( 'base' ) ) );

/* What the studio is told, as the files arrive. */
$fr_cfg                      = rrt_config();
$fr_cfg['whisper']['source'] = 'auto';
list( $fr_dir ) = rrt_whisper_home();
$fr_made        = array();
$fr_new_dir     = ! is_dir( $fr_dir );
function fr_put( $p ) {
	if ( ! file_exists( $p ) ) {
		wp_mkdir_p( dirname( $p ) );
		file_put_contents( $p, 'x' ); // phpcs:ignore
		$GLOBALS['fr_made'][] = $p;
	}
}
$GLOBALS['fr_made'] = array();
$fr_b               = rrt_whisper_boot( $fr_cfg );
fr_t( '' === $fr_b['small']['host'] && '' === $fr_b['small']['gpuHost'] && RRT_WHISPER_SMALL_REV === $fr_b['small']['rev'], 'with nothing here, both come from Hugging Face at the pinned revision', wp_json_encode( $fr_b['small'] ) );
foreach ( rrt_whisper_model_files() as $fr_p ) {
	fr_put( $fr_dir . RRT_WHISPER_SMALL . '/' . $fr_p );
}
list( $fr_dir, $fr_url ) = rrt_whisper_home();
$fr_b                    = rrt_whisper_boot( $fr_cfg );
fr_t( $fr_url === $fr_b['small']['host'] && '' === $fr_b['small']['gpuHost'], 'with the older seven files, the processor copy is the site\'s and the graphics chip still loads from Hugging Face', wp_json_encode( $fr_b['small'] ) );
$fr_st = rrt_whisper_status( 'small' );
fr_t( 7 === $fr_st['have'] && 9 === $fr_st['count'], 'Settings shows 7 of 9 files, so the Download is offered again' );
fr_put( $fr_dir . RRT_WHISPER_SMALL . '/onnx/encoder_model_fp16.onnx' );
fr_t( '' === rrt_whisper_boot( $fr_cfg )['small']['gpuHost'], 'one graphics chip file is not enough' );
fr_put( $fr_dir . RRT_WHISPER_SMALL . '/onnx/decoder_model_merged_q4f16.onnx' );
$fr_b = rrt_whisper_boot( $fr_cfg );
fr_t( $fr_url === $fr_b['small']['host'] && $fr_url === $fr_b['small']['gpuHost'], 'with all nine, both come from this site', wp_json_encode( $fr_b['small'] ) );
$fr_cfg['whisper']['source'] = 'remote';
$fr_b                        = rrt_whisper_boot( $fr_cfg );
fr_t( '' === $fr_b['small']['gpuHost'], 'with Whisper set to load from outside, the graphics chip does too' );

foreach ( array_reverse( $GLOBALS['fr_made'] ) as $fr_p ) {
	wp_delete_file( $fr_p );
}
foreach ( array( $fr_dir . RRT_WHISPER_SMALL . '/onnx', $fr_dir . RRT_WHISPER_SMALL, dirname( $fr_dir . RRT_WHISPER_SMALL ) ) as $fr_d ) {
	if ( is_dir( $fr_d ) && ! array_diff( scandir( $fr_d ), array( '.', '..' ) ) ) {
		rmdir( $fr_d ); // phpcs:ignore
	}
}
if ( $fr_new_dir && is_dir( $fr_dir ) && ! array_diff( scandir( $fr_dir ), array( '.', '..' ) ) ) {
	rmdir( $fr_dir ); // phpcs:ignore
}

echo "faster.php: {$GLOBALS['fr_pass']} passed, {$GLOBALS['fr_fail']} failed\n";
if ( $GLOBALS['fr_fail'] ) {
	exit( 1 );
}
