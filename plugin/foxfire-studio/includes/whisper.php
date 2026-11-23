<?php
/**
 * Whisper: where the model and its runtime load from, and the button in
 * Settings that downloads them onto this site.
 *
 * Whisper runs in the reader's browser. These files only decide where the
 * browser fetches it from. Files on this site are used when present; anything
 * missing falls back to Hugging Face (model) and jsDelivr (code).
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RRT_WHISPER_MODEL', 'onnx-community/whisper-base.en_timestamped' );
/*
 * The larger model (0.16.0): Whisper small, English only, with word timings.
 * About 250 MB with the q8 weights the review loads, against about 80 MB for
 * base. Downloads are pinned to one revision so its fingerprints never drift.
 */
define( 'RRT_WHISPER_SMALL', 'onnx-community/whisper-small.en_timestamped' );
define( 'RRT_WHISPER_SMALL_REV', '8085393831131554ad978a4e438295a734d3d2c0' );
/*
 * The multilingual models (1.33.0): the same two sizes for readings in any
 * language but English, pinned to the revisions checked on 21 November 2026.
 * English readings keep the English models above, which hear English better.
 */
define( 'RRT_WHISPER_MULTI', 'onnx-community/whisper-base_timestamped' );
define( 'RRT_WHISPER_MULTI_REV', '608c49e61301901684bc36cac8f74b95ff6b5a8e' );
define( 'RRT_WHISPER_MSMALL', 'onnx-community/whisper-small_timestamped' );
define( 'RRT_WHISPER_MSMALL_REV', '65caa70f294b46e1c33ff820aae6b16d048ab818' );
define( 'RRT_TJS_VERSION', '4.3.2' );
define( 'RRT_ORT_VERSION', '1.31.0-dev.20260914-8d85527a0' );

/**
 * Folders checked for self-hosted files, in order. rrt-whisper is the plugin's
 * own; gw-whisper is the folder used by versions before 0.1.0, still
 * checked so existing installs keep working.
 */
function rrt_whisper_dirs() {
	return array( 'rrt-whisper', 'gw-whisper' );
}

/**
 * SHA-256 fingerprints of every self-hosted file, keyed by where it goes.
 * Checked after each download, so a tampered or corrupted file is never
 * saved. The model files match Hugging Face's published LFS hashes and the
 * library files match the official npm packages. Update these together
 * with the version constants above.
 */
function rrt_whisper_hashes() {
	return array(
		RRT_WHISPER_MODEL . '/config.json' => 'c8a0de5ed8a083565a4319db29d0c210fda35b4d6076c2d711cae53ae00f3cb1',
		RRT_WHISPER_MODEL . '/generation_config.json' => '3479b1f44a07e41db799e22599222fee5816738036def94a39841cb9cdbb4120',
		RRT_WHISPER_MODEL . '/preprocessor_config.json' => 'a6a76d28c93edb273669eb9e0b0636a2bddbb1272c3261e47b7ca6dfdbac1b8d',
		RRT_WHISPER_MODEL . '/tokenizer.json' => '5eb60cec1e77aeeb6869a2bb5a8e01a84c3fe5d072d75369343021fe6f5310d0',
		RRT_WHISPER_MODEL . '/tokenizer_config.json' => '93879c3dccdd4b976f709acd85b44778873f30c275e67026f30ca1e4c975230c',
		RRT_WHISPER_MODEL . '/onnx/encoder_model_quantized.onnx' => '9b4d25507aef3d3a8d718695b8adcb0488e66aad8e884333c3fa73a1c3baa906',
		RRT_WHISPER_MODEL . '/onnx/decoder_model_merged_quantized.onnx' => 'afa8065a5f023f6e524417f9459cc09969023dd38149cd4dce16628b2c3a1773',
		RRT_WHISPER_SMALL . '/config.json' => '8825c4174cb86f94d9fa67614942f8aa17bfbbdf2fae5426d4adfd0bc5893c43',
		RRT_WHISPER_SMALL . '/generation_config.json' => '5490747ca976d6b3765280a0697d66489020c2afa6e754244d9cd093e1639331',
		RRT_WHISPER_SMALL . '/preprocessor_config.json' => 'a6a76d28c93edb273669eb9e0b0636a2bddbb1272c3261e47b7ca6dfdbac1b8d',
		RRT_WHISPER_SMALL . '/tokenizer.json' => '5eb60cec1e77aeeb6869a2bb5a8e01a84c3fe5d072d75369343021fe6f5310d0',
		RRT_WHISPER_SMALL . '/tokenizer_config.json' => '93879c3dccdd4b976f709acd85b44778873f30c275e67026f30ca1e4c975230c',
		RRT_WHISPER_SMALL . '/onnx/encoder_model_quantized.onnx' => 'f37219c0fb60c10c8ad3a986119c805e99622e946864f0fa8e3e0c393126e3e0',
		RRT_WHISPER_SMALL . '/onnx/decoder_model_merged_quantized.onnx' => 'f0e6480e2bd279b223a82b20a203d4b9f87d437712983687080c3c7dcc677b80',
		RRT_WHISPER_SMALL . '/onnx/encoder_model_fp16.onnx' => '3533ef795a540b0257b4dfad9edabce0b69846ab09f0fc2313319dfebfddb53d',
		RRT_WHISPER_SMALL . '/onnx/decoder_model_merged_q4f16.onnx' => '0d38a3ab3d034990af3c73663acfedd7060aedfa41f4a2f74b0833aa5db5823b',
		RRT_WHISPER_MULTI . '/config.json' => 'f4d0608f7d918166da7edb3e188de5ef1bfe70d9802e785d271fd88111e9cf4b',
		RRT_WHISPER_MULTI . '/generation_config.json' => '61070cf8de25b1e9256e8e102ded49d8d24a8369ed36ef84fdf21549e68125a0',
		RRT_WHISPER_MULTI . '/preprocessor_config.json' => 'a6a76d28c93edb273669eb9e0b0636a2bddbb1272c3261e47b7ca6dfdbac1b8d',
		RRT_WHISPER_MULTI . '/tokenizer.json' => '27fc476bfe7f17299480be2273fc0608e4d5a99aba2ab5dec5374b4482d1a566',
		RRT_WHISPER_MULTI . '/tokenizer_config.json' => '2e036e4dbacfdeb7242c7d4ec4149f4a16e86026048f94d1637e3a8ee9c6a573',
		RRT_WHISPER_MULTI . '/onnx/encoder_model_quantized.onnx' => '2714484ebe1bae7c1646e8eadb768bb9d415cf11763466d21f23039a29c62e6f',
		RRT_WHISPER_MULTI . '/onnx/decoder_model_merged_quantized.onnx' => 'cf9a8d5bcddc0917a0078135b484cedcaf44f28909cd91910abd29dced9171db',
		RRT_WHISPER_MSMALL . '/config.json' => '457854d452f17661e197d74aee12b8e74fb75ba30ebfaa7426d0d61ea1e08a18',
		RRT_WHISPER_MSMALL . '/generation_config.json' => 'f538b28220c6a6d6f1af1458d4141cacb4ef4963df3de98a19490440c412ddf0',
		RRT_WHISPER_MSMALL . '/preprocessor_config.json' => 'a6a76d28c93edb273669eb9e0b0636a2bddbb1272c3261e47b7ca6dfdbac1b8d',
		RRT_WHISPER_MSMALL . '/tokenizer.json' => '27fc476bfe7f17299480be2273fc0608e4d5a99aba2ab5dec5374b4482d1a566',
		RRT_WHISPER_MSMALL . '/tokenizer_config.json' => '2a4c4281cf9f51ac6ccc406fdc711a087afe6530f671fa7b80953edc498275ce',
		RRT_WHISPER_MSMALL . '/onnx/encoder_model_quantized.onnx' => '9f5635b596e6084045c7426ca6f6f99db8ce606ea895397c3b4a3c0aa3f9b070',
		RRT_WHISPER_MSMALL . '/onnx/decoder_model_merged_quantized.onnx' => 'c831996a6d5a535cf17447b0d7ecc36da6b72696e6fb906852ba6136b9199abb',
		RRT_WHISPER_MSMALL . '/onnx/encoder_model_fp16.onnx' => 'ce444cbfbc96f079c861f696633d44ba4b48190916a078ddc5f56fce3d4509f9',
		RRT_WHISPER_MSMALL . '/onnx/decoder_model_merged_q4f16.onnx' => '708b42858883a7edc9a3edaf182dd4fa7a78af6963ea5a881decb6024acb1bd9',
		'lib/transformers.min.js' => '8b5a3171f8876bab2b1820a7d65eb87628e3d30bb5ea85b9dd398e76eb34decb',
		'lib/ort-wasm-simd-threaded.asyncify.js' => '0966b6105cd936744498aa60df7a22cbd47af3374dbc64a9ab561c08a71e3611',
		'lib/ort-wasm-simd-threaded.asyncify.wasm' => '49871f5a4409519797e127440868a6d1923339d9185907f301a5b2a1d90af082',
		'lib/ort-wasm-simd-threaded.js' => 'c57ca56328877353a575e51bbca6f18450027d6c9bf2307a2cb2c41363b4de9f',
		'lib/ort-wasm-simd-threaded.wasm' => '06ba057753da3847e4c24f02d91ab133455b0817c69a44993a9a53a2146df9e3',
	) + rrt_follow_hashes();
}

/** The model files Whisper needs, in the order they are fetched. */
function rrt_whisper_model_files() {
	return array( 'config.json', 'generation_config.json', 'preprocessor_config.json', 'tokenizer.json', 'tokenizer_config.json', 'onnx/encoder_model_quantized.onnx', 'onnx/decoder_model_merged_quantized.onnx' );
}

/**
 * The larger model's files for the graphics chip (1.17.0): the encoder in
 * half precision and the decoder in 4-bit half precision, run with WebGPU.
 * About 322 MB, fetched by the same Download as the larger model's own.
 */
function rrt_whisper_gpu_files() {
	return array( 'onnx/encoder_model_fp16.onnx', 'onnx/decoder_model_merged_q4f16.onnx' );
}

/**
 * Whether these files of the larger model are all on this site (1.17.0).
 *
 * @param string[] $names File names inside the model's folder.
 */
function rrt_whisper_small_have( $names ) {
	return rrt_whisper_have( RRT_WHISPER_SMALL, $names );
}

/**
 * Whether these files of a model are all on this site (1.33.0, from rrt_whisper_small_have).
 *
 * @param string   $model The model's name, such as RRT_WHISPER_MULTI.
 * @param string[] $names File names inside the model's folder.
 */
function rrt_whisper_have( $model, $names ) {
	list( $dir ) = rrt_whisper_home();
	foreach ( $names as $p ) {
		$path = $dir . $model . '/' . $p;
		if ( ! file_exists( $path ) || filesize( $path ) < 1 ) {
			return false;
		}
	}
	return true;
}

/**
 * Every file the self-hosted copy needs: where it comes from and where it goes.
 *
 * @param string $set 'base' (the standard model and the code, the default),
 *                    'small' (the larger model's files only, with its graphics
 *                    chip files since 1.17.0; it shares the code) or
 *                    'follow' (voice following's model, 1.14.0; see follow.php) or
 *                    'multi' (both multilingual models, with the larger one's
 *                    graphics chip files, 1.33.0; they share the code).
 */
function rrt_whisper_files( $set = 'base' ) {
	if ( 'follow' === $set ) {
		return rrt_follow_files();
	}
	if ( 'multi' === $set ) {
		$f = array();
		foreach ( rrt_whisper_model_files() as $p ) {
			$f[] = array(
				'from' => 'https://huggingface.co/' . RRT_WHISPER_MULTI . '/resolve/' . RRT_WHISPER_MULTI_REV . '/' . $p,
				'to'   => RRT_WHISPER_MULTI . '/' . $p,
			);
		}
		foreach ( array_merge( rrt_whisper_model_files(), rrt_whisper_gpu_files() ) as $p ) {
			$f[] = array(
				'from' => 'https://huggingface.co/' . RRT_WHISPER_MSMALL . '/resolve/' . RRT_WHISPER_MSMALL_REV . '/' . $p,
				'to'   => RRT_WHISPER_MSMALL . '/' . $p,
			);
		}
		return $f;
	}
	if ( 'small' === $set ) {
		$f = array();
		foreach ( array_merge( rrt_whisper_model_files(), rrt_whisper_gpu_files() ) as $p ) {
			$f[] = array(
				'from' => 'https://huggingface.co/' . RRT_WHISPER_SMALL . '/resolve/' . RRT_WHISPER_SMALL_REV . '/' . $p,
				'to'   => RRT_WHISPER_SMALL . '/' . $p,
			);
		}
		return $f;
	}
	$hf  = 'https://huggingface.co/' . RRT_WHISPER_MODEL . '/resolve/main/';
	$ort = 'https://cdn.jsdelivr.net/npm/onnxruntime-web@' . RRT_ORT_VERSION . '/dist/';
	$m   = RRT_WHISPER_MODEL . '/';
	$f   = array();
	foreach ( rrt_whisper_model_files() as $p ) {
		$f[] = array(
			'from' => $hf . $p,
			'to'   => $m . $p,
		);
	}
	$f[] = array(
		'from' => 'https://cdn.jsdelivr.net/npm/@huggingface/transformers@' . RRT_TJS_VERSION . '/dist/transformers.min.js',
		'to'   => 'lib/transformers.min.js',
	);
	foreach ( array( '.asyncify', '' ) as $v ) {
		$f[] = array(
			'from' => $ort . 'ort-wasm-simd-threaded' . $v . '.mjs',
			'to'   => 'lib/ort-wasm-simd-threaded' . $v . '.js',
		);
		$f[] = array(
			'from' => $ort . 'ort-wasm-simd-threaded' . $v . '.wasm',
			'to'   => 'lib/ort-wasm-simd-threaded' . $v . '.wasm',
		);
	}
	return $f;
}

/** The self-hosted folder in use, if any, as array( dir, url ). */
function rrt_whisper_home() {
	$up = wp_upload_dir( null, false );
	foreach ( rrt_whisper_dirs() as $d ) {
		$dir = trailingslashit( $up['basedir'] ) . $d . '/';
		if ( file_exists( $dir . RRT_WHISPER_MODEL . '/config.json' ) || file_exists( $dir . RRT_WHISPER_SMALL . '/config.json' ) || file_exists( $dir . 'lib/transformers.min.js' ) || file_exists( $dir . RRT_FOLLOW_MODEL . '/tokens.txt' ) || file_exists( $dir . RRT_WHISPER_MULTI . '/config.json' ) ) {
			$scheme = 0 === strpos( home_url(), 'https:' ) ? 'https' : null; // Match the site, so https sites never load Whisper over http.
			return array( $dir, set_url_scheme( trailingslashit( $up['baseurl'] ) . $d . '/', $scheme ) );
		}
	}
	return array( trailingslashit( $up['basedir'] ) . 'rrt-whisper/', '' );
}

/** What the page needs to know to load Whisper. */
function rrt_whisper_boot( $cfg ) {
	$boot = array(
		'model'    => RRT_WHISPER_MODEL,
		'fallback' => 'Xenova/whisper-base.en',
		'host'     => '',
		'lib'      => '',
		'wasm'     => '',
	);
	/*
	 * The larger model (0.16.0): the reader chooses it in the teleprompter; host is set only when every file
	 * it loads on the processor is here. Since 1.17.0 it can run on the graphics chip: gpuHost is set only
	 * when those files are here too, and rev pins Hugging Face's copy until then.
	 */
	$boot['small'] = array(
		'model'    => RRT_WHISPER_SMALL,
		'fallback' => 'Xenova/whisper-small.en',
		'host'     => '',
		'gpuHost'  => '',
		'rev'      => RRT_WHISPER_SMALL_REV,
	);
	/* The multilingual models (1.33.0), for readings in any language but English: pinned on Hugging Face until they are here. */
	$boot['multi'] = array(
		'model'    => RRT_WHISPER_MULTI,
		'fallback' => '',
		'host'     => '',
		'rev'      => RRT_WHISPER_MULTI_REV,
		'small'    => array(
			'model'    => RRT_WHISPER_MSMALL,
			'fallback' => '',
			'host'     => '',
			'gpuHost'  => '',
			'rev'      => RRT_WHISPER_MSMALL_REV,
		),
	);
	if ( 'remote' === $cfg['whisper']['source'] ) {
		return $boot;
	}
	list( $dir, $url ) = rrt_whisper_home();
	if ( ! $url ) {
		return $boot;
	}
	if ( file_exists( $dir . RRT_WHISPER_MODEL . '/config.json' ) ) {
		$boot['host'] = $url;
	}
	if ( file_exists( $dir . 'lib/transformers.min.js' ) ) {
		$boot['lib'] = $url . 'lib/transformers.min.js';
	}
	if ( file_exists( $dir . 'lib/ort-wasm-simd-threaded.asyncify.wasm' ) ) {
		$boot['wasm'] = $url . 'lib/';
	}
	if ( rrt_whisper_small_have( rrt_whisper_model_files() ) ) {
		$boot['small']['host'] = $url;
		if ( rrt_whisper_small_have( rrt_whisper_gpu_files() ) ) {
			$boot['small']['gpuHost'] = $url;
		}
	}
	if ( rrt_whisper_have( RRT_WHISPER_MULTI, rrt_whisper_model_files() ) ) {
		$boot['multi']['host'] = $url;
	}
	if ( rrt_whisper_have( RRT_WHISPER_MSMALL, rrt_whisper_model_files() ) ) {
		$boot['multi']['small']['host'] = $url;
		if ( rrt_whisper_have( RRT_WHISPER_MSMALL, rrt_whisper_gpu_files() ) ) {
			$boot['multi']['small']['gpuHost'] = $url;
		}
	}
	return $boot;
}

/**
 * Whether every file of a set is on this site.
 *
 * @param string $set 'base', 'small', 'follow' or 'multi'.
 */
function rrt_whisper_complete( $set ) {
	$st = rrt_whisper_status( $set );
	return $st['have'] === $st['count'];
}

/**
 * Which self-hosted files are present, for the settings screen.
 *
 * @param string $set 'base', 'small', 'follow' or 'multi'.
 */
function rrt_whisper_status( $set = 'base' ) {
	list( $dir ) = rrt_whisper_home();
	$rows        = array();
	$total       = 0;
	$have        = 0;
	foreach ( rrt_whisper_files( $set ) as $i => $f ) {
		$path  = $dir . $f['to'];
		$size  = file_exists( $path ) ? filesize( $path ) : 0;
		$total += $size;
		if ( $size > 0 ) {
			$have++;
		}
		$rows[] = array(
			'i'    => $i,
			'name' => $f['to'],
			'size' => $size,
		);
	}
	return array(
		'dir'   => $dir,
		'files' => $rows,
		'have'  => $have,
		'count' => count( $rows ),
		'bytes' => $total,
	);
}

/**
 * Downloads one file into the self-hosted folder. Called once per file from
 * the settings screen, so no single request runs long enough to time out.
 */
add_action(
	'wp_ajax_rrt_whisper_fetch',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Only administrators can do this.', 'foxfire-studio' ) ), 403 );
		}
		check_ajax_referer( 'rrt_whisper_fetch' );
		$set   = isset( $_POST['set'] ) && in_array( $_POST['set'], array( 'small', 'follow', 'multi' ), true ) ? sanitize_key( $_POST['set'] ) : 'base';
		$files = rrt_whisper_files( $set );
		$i     = isset( $_POST['i'] ) ? absint( $_POST['i'] ) : -1;
		if ( ! isset( $files[ $i ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown file.', 'foxfire-studio' ) ), 400 );
		}
		list( $dir ) = rrt_whisper_home();
		$dest        = $dir . $files[ $i ]['to'];
		if ( ! wp_mkdir_p( dirname( $dest ) ) ) {
			wp_send_json_error( array( 'message' => sprintf( /* translators: %s: folder path. */ __( 'Could not create %s. Check that the uploads folder is writable.', 'foxfire-studio' ), dirname( $dest ) ) ) );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- not allowed on some hosts.
		}
		$tmp = $dest . '.part';
		$res = wp_remote_get(
			$files[ $i ]['from'],
			array(
				'timeout'  => 280,
				'stream'   => true,
				'filename' => $tmp,
			)
		);
		if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) || ! file_exists( $tmp ) || filesize( $tmp ) < 100 ) {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			$why = is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $res );
			wp_send_json_error( array( 'message' => sprintf( /* translators: 1: file name, 2: reason. */ __( '%1$s could not be downloaded (%2$s).', 'foxfire-studio' ), basename( $dest ), $why ) ) );
		}
		$hashes = rrt_whisper_hashes();
		$want   = isset( $hashes[ $files[ $i ]['to'] ] ) ? $hashes[ $files[ $i ]['to'] ] : '';
		if ( ! $want || ! hash_equals( $want, (string) hash_file( 'sha256', $tmp ) ) ) {
			wp_delete_file( $tmp );
			wp_send_json_error( array( 'message' => sprintf( /* translators: %s: file name. */ __( '%s did not match its expected fingerprint, so it was not saved. Try again later.', 'foxfire-studio' ), basename( $dest ) ) ) );
		}
		rename( $tmp, $dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- moving our own temp file.
		wp_send_json_success(
			array(
				'name' => $files[ $i ]['to'],
				'size' => filesize( $dest ),
			)
		);
	}
);
