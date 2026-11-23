<?php
/**
 * Voice following (1.14.0): where its speech model loads from, and what the
 * studio is told about it.
 *
 * The prompter follows the reader's voice with a small streaming speech model
 * (a 20M Zipformer trained on read speech, Apache-2.0) run on the reader's own
 * device by the sherpa-onnx engine (Apache-2.0), which ships with the plugin
 * in assets/vendor/sherpa-onnx. The audio never leaves the browser. The model
 * (about 45 MB) loads from this site when its files have been downloaded in
 * Settings, otherwise from Hugging Face, pinned to one revision. Downloads go
 * through the Whisper downloader (set 'follow') into the same folder, so the
 * same fingerprint check, status list and uninstall apply.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RRT_FOLLOW_MODEL', 'csukuangfj/sherpa-onnx-streaming-zipformer-en-20M-2023-02-17' );
define( 'RRT_FOLLOW_REV', 'd42f2d9f7ca24806fb667456a18a9f1b60f70d16' );
/* The engine shipped in assets/vendor/sherpa-onnx: the official sherpa-onnx WebAssembly build of this release. */
define( 'RRT_FOLLOW_ENGINE', '1.12.20' );

/** The model's files, by their part, in the order they are fetched. */
function rrt_follow_names() {
	return array(
		'tokens'  => 'tokens.txt',
		'joiner'  => 'joiner-epoch-99-avg-1.int8.onnx',
		'decoder' => 'decoder-epoch-99-avg-1.onnx',
		'encoder' => 'encoder-epoch-99-avg-1.int8.onnx',
	);
}

/** SHA-256 of each model file, matching Hugging Face's published hashes for the pinned revision. */
function rrt_follow_hashes() {
	$m = RRT_FOLLOW_MODEL . '/';
	return array(
		$m . 'tokens.txt'                       => '49e3c2646595fd907228b3c6787069658f67b17377c60aeb8619c4551b2316fb',
		$m . 'joiner-epoch-99-avg-1.int8.onnx'  => 'e085d73b593cf9b0707f370dbd656d58327d3fe36d80d849202ef81df02cb01e',
		$m . 'decoder-epoch-99-avg-1.onnx'      => '45a7f940ecfb53d89fa270ad11b88b961e53a317203eb24b1c8e95ed208b0f30',
		$m . 'encoder-epoch-99-avg-1.int8.onnx' => '3810755ce7c3ab26b42a8bcf39d191308fa27fb0f53358823ba46141d03b7eb3',
	);
}

/** About how many bytes the model is, for the loading line before the files have sizes. */
function rrt_follow_bytes() {
	return 45202074;
}

/** The model's files for the downloader: where each comes from and where it goes. */
function rrt_follow_files() {
	$f = array();
	foreach ( rrt_follow_names() as $n ) {
		$f[] = array(
			'from' => 'https://huggingface.co/' . RRT_FOLLOW_MODEL . '/resolve/' . RRT_FOLLOW_REV . '/' . $n,
			'to'   => RRT_FOLLOW_MODEL . '/' . $n,
		);
	}
	return $f;
}

/**
 * SHA-256 of the engine files shipped with the plugin, checked by the tests
 * so a changed or damaged copy is caught before a release.
 */
function rrt_follow_engine_hashes() {
	return array(
		'sherpa-onnx-asr.js'             => 'ab619fbad082d474ef35616072d3ace7e8f533d3725c611c1c60d7b97cac41c9',
		'sherpa-onnx-wasm-main-asr.js'   => 'dab54f2c65d9776f9c28e5cae0e42de1064e658d705ddefcee3402a09374049c',
		'sherpa-onnx-wasm-main-asr.wasm' => 'aba480b3c6a213b252a36040dea28c8b8746bda51ca21b5871d659382e4beeb4',
	);
}

/** What the studio needs to know to load voice following. */
function rrt_follow_boot( $cfg ) {
	$names = rrt_follow_names();
	$hosts = array();
	if ( 'remote' !== $cfg['whisper']['source'] && rrt_whisper_complete( 'follow' ) ) {
		list( , $url ) = rrt_whisper_home();
		if ( $url ) {
			$hosts[] = array(
				'url'   => $url . RRT_FOLLOW_MODEL . '/',
				'label' => 'site',
			);
		}
	}
	$hosts[] = array(
		'url'   => 'https://huggingface.co/' . RRT_FOLLOW_MODEL . '/resolve/' . RRT_FOLLOW_REV . '/',
		'label' => 'Hugging Face',
	);
	return array(
		'engine' => RRT_URL . 'assets/vendor/sherpa-onnx/',
		'v'      => RRT_FOLLOW_ENGINE,
		'worker' => rrt_js_url( 'follow-worker.js' ),
		'files'  => array_values( $names ),
		'names'  => $names,
		'hosts'  => $hosts,
		'bytes'  => rrt_follow_bytes(),
	);
}
