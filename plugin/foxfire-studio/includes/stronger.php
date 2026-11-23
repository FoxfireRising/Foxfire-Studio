<?php
/**
 * The stronger cleanup (1.16.0): DeepFilterNet 3 beside RNNoise.
 *
 * The model (MIT or Apache-2.0, about 8.6 MB) ships with the plugin in
 * assets/vendor/deepfilternet3, as does ONNX Runtime Web's small loader in
 * assets/vendor/onnxruntime-web. The runtime's WebAssembly is the one the
 * Whisper review already uses: from this site's Whisper files when they are
 * here, otherwise from jsDelivr at the same pinned version. The browser
 * checks every one of these files against the fingerprints below before
 * using it (assets/js/deepfilter.js).
 *
 * Settings, Broadcast sound, Background noise reduction chooses the engine
 * (standard or stronger) beside the amount (gentle, medium or strong), which
 * applies to either.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The model's three files and their SHA-256, as in the official DeepFilterNet3_onnx.tar.gz. */
function rrt_stronger_models() {
	return array(
		'enc'     => array(
			'file'   => 'enc.onnx',
			'sha256' => '7c5399d3da8a50ebef1c1a0ae421b33376aa5e45d0e92df16da7e83c9c131916',
		),
		'erb_dec' => array(
			'file'   => 'erb_dec.onnx',
			'sha256' => 'ab669a1d10afe20911728b33053a452071042317a90581092b325da7b2f9d895',
		),
		'df_dec'  => array(
			'file'   => 'df_dec.onnx',
			'sha256' => '23114ce3b0f6464b763ee62f7bb8aab6b2a129a21eabd5bcfe59413db05f278a',
		),
	);
}

/** SHA-256 of assets/vendor/onnxruntime-web/ort.wasm.min.js (onnxruntime-web at RRT_ORT_VERSION), checked by the tests. */
function rrt_stronger_loader_sha() {
	return '7719df5621a8646640c00e27f89a45a07afa8e3e48b335146daeec0d0ebcb7b1';
}

/** The engines the setting accepts. */
function rrt_stronger_engines() {
	return array( 'standard', 'stronger' );
}

/** Whether the model files are where the plugin put them (a damaged copy of the plugin could lack them). */
function rrt_stronger_ready() {
	foreach ( rrt_stronger_models() as $m ) {
		if ( ! file_exists( RRT_DIR . 'assets/vendor/deepfilternet3/' . $m['file'] ) ) {
			return false;
		}
	}
	return file_exists( RRT_DIR . 'assets/vendor/onnxruntime-web/ort.wasm.min.js' );
}

/**
 * What the studio needs to run the stronger cleanup: the worker, the loader,
 * where the runtime's two files can come from (this site first), the model's
 * folder and its files with their fingerprints.
 */
function rrt_stronger_boot( $cfg ) {
	if ( ! rrt_stronger_ready() ) {
		return null;
	}
	$hashes = rrt_whisper_hashes();
	$cdn    = 'https://cdn.jsdelivr.net/npm/onnxruntime-web@' . RRT_ORT_VERSION . '/dist/';
	$glue   = array();
	$wasm   = array();
	if ( 'remote' !== $cfg['whisper']['source'] ) {
		list( $dir, $url ) = rrt_whisper_home();
		if ( $url && file_exists( $dir . 'lib/ort-wasm-simd-threaded.wasm' ) && file_exists( $dir . 'lib/ort-wasm-simd-threaded.js' ) ) {
			$glue[] = $url . 'lib/ort-wasm-simd-threaded.js';
			$wasm[] = $url . 'lib/ort-wasm-simd-threaded.wasm';
		}
	}
	$glue[] = $cdn . 'ort-wasm-simd-threaded.mjs';
	$wasm[] = $cdn . 'ort-wasm-simd-threaded.wasm';
	return array(
		'worker'  => rrt_js_url( 'deepfilter.js' ),
		'ort'     => add_query_arg( 'ver', RRT_ORT_VERSION, RRT_URL . 'assets/vendor/onnxruntime-web/ort.wasm.min.js' ),
		'glue'    => $glue,
		'wasm'    => $wasm,
		'glueSha' => $hashes['lib/ort-wasm-simd-threaded.js'],
		'wasmSha' => $hashes['lib/ort-wasm-simd-threaded.wasm'],
		'model'   => RRT_URL . 'assets/vendor/deepfilternet3/',
		'models'  => rrt_stronger_models(),
		/* Give up after the first 10 s if the device needs longer than this share of the audio's length, and use RNNoise instead. */
		'maxRtf'  => 0.6,
	);
}
