<?php
/**
 * Fonts, the MP3 encoder and the noise model served from this site.
 *
 * Without these files the teleprompter page loads its fonts from Google Fonts
 * and the MP3 encoder (LAME) and noise model (RNNoise) from jsDelivr. The
 * button in Settings, then Foxfire Studio, downloads them into
 * uploads/rrt-assets/ so the page can run with no outside requests (apart
 * from Whisper, which has its own button).
 *
 * The local copies are only used when they are present and, for fonts, when
 * they were made from the font link currently saved in Settings. Anything
 * else falls back to the outside source, so a missing or old file never
 * breaks the page.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RRT_MP3_VERSION', '0.7.0' );
/*
 * SHA-256 of wasm/mp3.wasm (LAME as WebAssembly) from the official
 * wasm-media-encoders npm package. Checked before the file is saved here, and
 * again by the browser every time the encoder loads, from here or from
 * jsDelivr. assets/js/mp3.js also checks the version string inside it.
 */
define( 'RRT_MP3_SHA256', '85e81719250b9a667b1258143f689dda70e3e57a7e7c29ab0b4cef65c8f6eb9a' );

define( 'RRT_RNNOISE_VERSION', '0.2.1' );
/*
 * SHA-256 of dist/rnnoise.wasm from the official @jitsi/rnnoise-wasm npm
 * package. Checked before the file is saved here, and again by the browser
 * every time the model loads, from here or from jsDelivr. assets/js/denoise.js
 * relies on this exact file (its export names), so update both together.
 */
define( 'RRT_RNNOISE_SHA256', '677147b9248aedc0a00de79dc090d1ecead37c08c8bb773a790da16109d2c487' );

/** Where the noise model comes from when it is not on this site. */
function rrt_rnnoise_cdn() {
	return 'https://cdn.jsdelivr.net/npm/@jitsi/rnnoise-wasm@' . RRT_RNNOISE_VERSION . '/dist/rnnoise.wasm';
}

/** The local noise model's address, or '' when it is not on this site. */
function rrt_local_rnnoise_url() {
	list( $dir, $url ) = rrt_local_home();
	$file              = $dir . 'rnnoise.wasm';
	if ( ! file_exists( $file ) || filesize( $file ) < 1000 ) {
		return '';
	}
	return $url . 'rnnoise.wasm?ver=' . RRT_RNNOISE_VERSION;
}

/** Where the encoder comes from when it is not on this site. */
function rrt_mp3_cdn() {
	return 'https://cdn.jsdelivr.net/npm/wasm-media-encoders@' . RRT_MP3_VERSION . '/wasm/mp3.wasm';
}

/** The folder for local copies, as array( dir, url ). */
function rrt_local_home() {
	$up     = wp_upload_dir( null, false );
	$scheme = 0 === strpos( home_url(), 'https:' ) ? 'https' : null; // Match the site, so https sites never load these over http.
	return array(
		trailingslashit( $up['basedir'] ) . 'rrt-assets/',
		set_url_scheme( trailingslashit( $up['baseurl'] ) . 'rrt-assets/', $scheme ),
	);
}

/** What was last downloaded: the font link the fonts were made from, their files, and when. */
function rrt_local_saved() {
	$s = get_option( 'rrt_local', array() );
	return wp_parse_args(
		is_array( $s ) ? $s : array(),
		array(
			'fonts_from'  => '',
			'fonts_files' => array(),
			'fonts_at'    => 0,
		)
	);
}

/** The local encoder's address, or '' when it is not on this site. */
function rrt_local_mp3_url() {
	list( $dir, $url ) = rrt_local_home();
	$file              = $dir . 'mp3.wasm';
	if ( ! file_exists( $file ) || filesize( $file ) < 1000 ) {
		return '';
	}
	return $url . 'mp3.wasm?ver=' . RRT_MP3_VERSION;
}

/**
 * The local font stylesheet's address, or '' when there is none for this font
 * link. Fonts made from an older link are never used, so changing fonts in
 * Settings takes effect straight away (from Google) until the next download.
 */
function rrt_local_fonts_url( $cfg ) {
	$link = isset( $cfg['fonts']['google_url'] ) ? (string) $cfg['fonts']['google_url'] : '';
	$s    = rrt_local_saved();
	if ( '' === $link || $s['fonts_from'] !== $link ) {
		return '';
	}
	list( $dir, $url ) = rrt_local_home();
	if ( ! file_exists( $dir . 'fonts.css' ) ) {
		return '';
	}
	return $url . 'fonts.css?ver=' . (int) $s['fonts_at'];
}

/** Bytes used by a list of files in the local folder. */
function rrt_local_bytes( $names ) {
	list( $dir ) = rrt_local_home();
	$total       = 0;
	foreach ( $names as $n ) {
		if ( file_exists( $dir . $n ) ) {
			$total += filesize( $dir . $n );
		}
	}
	return $total;
}

/** A plain-language status for the settings screen. */
function rrt_local_status( $cfg ) {
	$s     = rrt_local_saved();
	$link  = (string) $cfg['fonts']['google_url'];
	$names = array();
	foreach ( (array) $s['fonts_files'] as $f ) {
		$names[] = 'fonts/' . $f;
	}
	$names[] = 'fonts.css';

	if ( '' === $link ) {
		$fonts = __( 'No Google Fonts link is set, so only fonts already on the reader\'s computer are used.', 'foxfire-studio' );
	} elseif ( rrt_local_fonts_url( $cfg ) ) {
		$fonts = sprintf( /* translators: 1: count( $s['fonts_files'] ), 2: rrt_size( rrt_local_bytes( $names ) ). */ __( 'On this site: %1$d files (%2$s), made from your current font link.', 'foxfire-studio' ), count( $s['fonts_files'] ), rrt_size( rrt_local_bytes( $names ) ) );
	} elseif ( $s['fonts_from'] ) {
		$fonts = __( 'The copy on this site was made from a different font link, so fonts load from Google Fonts until you download again.', 'foxfire-studio' );
	} else {
		$fonts = __( 'Not on this site yet. They load from Google Fonts.', 'foxfire-studio' );
	}

	$enc = rrt_local_mp3_url()
		? sprintf( /* translators: 1: RRT_MP3_VERSION, 2: rrt_size( rrt_local_bytes( array( 'mp3.wasm' ) ) ). */ __( 'On this site: LAME from wasm-media-encoders %1$s (%2$s).', 'foxfire-studio' ), RRT_MP3_VERSION, rrt_size( rrt_local_bytes( array( 'mp3.wasm' ) ) ) )
		: __( 'Not on this site yet. It loads from jsDelivr.', 'foxfire-studio' );

	$model = rrt_local_rnnoise_url()
		? sprintf( /* translators: 1: RRT_RNNOISE_VERSION, 2: rrt_size( rrt_local_bytes( array( 'rnnoise.wasm' ) ) ). */ __( 'On this site: RNNoise from @jitsi/rnnoise-wasm %1$s (%2$s).', 'foxfire-studio' ), RRT_RNNOISE_VERSION, rrt_size( rrt_local_bytes( array( 'rnnoise.wasm' ) ) ) )
		: __( 'Not on this site yet. It loads from jsDelivr when a reading is cleaned up.', 'foxfire-studio' );

	$fonts_ok = '' === $link || '' !== rrt_local_fonts_url( $cfg );
	return array(
		'fonts'    => $fonts,
		'encoder'  => $enc,
		'model'    => $model,
		'complete' => $fonts_ok && '' !== rrt_local_mp3_url() && '' !== rrt_local_rnnoise_url(),
	);
}

/**
 * Asks Google Fonts for the stylesheet the way a current browser would, so it
 * answers with woff2 files, and turns it into a list of font faces.
 *
 * Only @font-face rules are read, only a short list of properties is kept, and
 * every file must come from fonts.gstatic.com. The plugin writes the
 * stylesheet saved on this site itself, from that list.
 *
 * @return array|WP_Error List of faces, each with 'props' and 'url'.
 */
function rrt_fonts_plan( $link ) {
	$res = wp_remote_get(
		$link,
		array(
			'timeout'    => 30,
			'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
		)
	);
	if ( is_wp_error( $res ) ) {
		return new WP_Error( 'rrt_fonts', sprintf( /* translators: %s: error message. */ __( 'Google Fonts could not be reached (%s).', 'foxfire-studio' ), $res->get_error_message() ) );
	}
	if ( 200 !== wp_remote_retrieve_response_code( $res ) ) {
		return new WP_Error( 'rrt_fonts', sprintf( /* translators: %s: HTTP status code. */ __( 'Google Fonts did not accept the font link (HTTP %s). Check the link in Fonts above.', 'foxfire-studio' ), wp_remote_retrieve_response_code( $res ) ) );
	}
	$css = (string) wp_remote_retrieve_body( $res );
	if ( ! preg_match_all( '/@font-face\s*\{([^{}]*)\}/i', $css, $blocks ) ) {
		return new WP_Error( 'rrt_fonts', __( 'Google Fonts answered, but with no fonts in it.', 'foxfire-studio' ) );
	}
	$keep  = array( 'font-family', 'font-style', 'font-weight', 'font-stretch', 'font-display', 'unicode-range' );
	$faces = array();
	foreach ( $blocks[1] as $body ) {
		if ( ! preg_match( '/src\s*:\s*url\(\s*[\'"]?(https:\/\/fonts\.gstatic\.com\/[A-Za-z0-9_\-\.\/]+\.woff2)[\'"]?\s*\)\s*format\(\s*[\'"]woff2[\'"]\s*\)/i', $body, $m ) ) {
			return new WP_Error( 'rrt_fonts', __( 'Google Fonts sent a font that is not a woff2 file from fonts.gstatic.com, so nothing was saved.', 'foxfire-studio' ) );
		}
		$props = array();
		foreach ( explode( ';', $body ) as $decl ) {
			$pair = explode( ':', $decl, 2 );
			if ( 2 !== count( $pair ) ) {
				continue;
			}
			$name = strtolower( trim( $pair[0] ) );
			$val  = trim( $pair[1] );
			if ( in_array( $name, $keep, true ) && preg_match( '/^[A-Za-z0-9 ,\'"\+\-\.%_]+$/', $val ) ) {
				$props[ $name ] = $val;
			}
		}
		if ( empty( $props['font-family'] ) ) {
			continue;
		}
		$faces[] = array(
			'props' => $props,
			'url'   => $m[1],
		);
	}
	if ( ! $faces ) {
		return new WP_Error( 'rrt_fonts', __( 'Google Fonts answered, but no usable fonts were found in it.', 'foxfire-studio' ) );
	}
	return $faces;
}

/** The local file name for a font address. Stable, so a download can be repeated safely. */
function rrt_font_file( $url ) {
	return substr( md5( $url ), 0, 16 ) . '.woff2';
}

/** The unique font files a plan needs, in order. */
function rrt_fonts_plan_files( $faces ) {
	$urls = array();
	foreach ( $faces as $f ) {
		$urls[ $f['url'] ] = rrt_font_file( $f['url'] );
	}
	return $urls;
}

/** Writes the rebuilt stylesheet, pointing at the files in fonts/. */
function rrt_fonts_css( $faces, $link ) {
	$out = '/* Made by Foxfire Studio from ' . str_replace( '*/', '', $link ) . "\n * The fonts are from Google Fonts and keep their own licences, mostly the SIL Open Font License. */\n";
	foreach ( $faces as $f ) {
		$out .= "@font-face {\n";
		foreach ( $f['props'] as $k => $v ) {
			if ( 'unicode-range' !== $k ) {
				$out .= '  ' . $k . ': ' . $v . ";\n";
			}
		}
		$out .= "  src: url('fonts/" . rrt_font_file( $f['url'] ) . "') format('woff2');\n";
		if ( isset( $f['props']['unicode-range'] ) ) {
			$out .= '  unicode-range: ' . $f['props']['unicode-range'] . ";\n";
		}
		$out .= "}\n";
	}
	return $out;
}

/** Downloads one file to a temporary name. Returns the temporary path or a WP_Error. */
function rrt_local_download( $from, $dest ) {
	if ( ! wp_mkdir_p( dirname( $dest ) ) ) {
		return new WP_Error( 'rrt_local', sprintf( /* translators: %s: folder path. */ __( 'Could not create %s. Check that the uploads folder is writable.', 'foxfire-studio' ), dirname( $dest ) ) );
	}
	$tmp = $dest . '.part';
	$res = wp_remote_get(
		$from,
		array(
			'timeout'  => 60,
			'stream'   => true,
			'filename' => $tmp,
		)
	);
	if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) || ! file_exists( $tmp ) || filesize( $tmp ) < 100 ) {
		if ( file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
		}
		$why = is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $res );
		return new WP_Error( 'rrt_local', sprintf( /* translators: 1: file name, 2: reason. */ __( '%1$s could not be downloaded (%2$s).', 'foxfire-studio' ), basename( $dest ), $why ) );
	}
	return $tmp;
}

/**
 * The download, one small step per request so nothing runs long enough to
 * time out: encoder, model, fonts_plan, font (one file each), fonts_finish.
 */
add_action(
	'wp_ajax_rrt_local_fetch',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Only administrators can do this.', 'foxfire-studio' ) ), 403 );
		}
		check_ajax_referer( 'rrt_local_fetch' );
		$step        = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : '';
		list( $dir ) = rrt_local_home();
		$cfg         = rrt_config();
		$link        = (string) $cfg['fonts']['google_url'];

		if ( 'encoder' === $step ) {
			$dest = $dir . 'mp3.wasm';
			$tmp  = rrt_local_download( rrt_mp3_cdn(), $dest );
			if ( is_wp_error( $tmp ) ) {
				wp_send_json_error( array( 'message' => $tmp->get_error_message() ) );
			}
			if ( ! hash_equals( RRT_MP3_SHA256, (string) hash_file( 'sha256', $tmp ) ) ) {
				wp_delete_file( $tmp );
				wp_send_json_error( array( 'message' => __( 'The MP3 encoder did not match its expected fingerprint, so it was not saved. Try again later.', 'foxfire-studio' ) ) );
			}
			rename( $tmp, $dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- moving our own temp file.
			/* The encoder used before 0.7.0 is no longer needed. */
			if ( file_exists( $dir . 'lame.min.js' ) ) {
				wp_delete_file( $dir . 'lame.min.js' );
			}
			wp_send_json_success(
				array(
					'message' => sprintf( /* translators: %s: file size. */ __( 'MP3 encoder saved (%s).', 'foxfire-studio' ), rrt_size( filesize( $dest ) ) ),
					'status'  => rrt_local_status( rrt_config() ),
				)
			);
		}

		if ( 'model' === $step ) {
			$dest = $dir . 'rnnoise.wasm';
			$tmp  = rrt_local_download( rrt_rnnoise_cdn(), $dest );
			if ( is_wp_error( $tmp ) ) {
				wp_send_json_error( array( 'message' => $tmp->get_error_message() ) );
			}
			if ( ! hash_equals( RRT_RNNOISE_SHA256, (string) hash_file( 'sha256', $tmp ) ) ) {
				wp_delete_file( $tmp );
				wp_send_json_error( array( 'message' => __( 'The noise model did not match its expected fingerprint, so it was not saved. Try again later.', 'foxfire-studio' ) ) );
			}
			rename( $tmp, $dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- moving our own temp file.
			wp_send_json_success(
				array(
					'message' => sprintf( /* translators: %s: file size. */ __( 'Noise model saved (%s).', 'foxfire-studio' ), rrt_size( filesize( $dest ) ) ),
					'status'  => rrt_local_status( rrt_config() ),
				)
			);
		}

		if ( 'fonts_plan' === $step ) {
			if ( '' === $link ) {
				wp_send_json_success(
					array(
						'count'   => 0,
						'message' => __( 'No Google Fonts link is set, so there are no fonts to download.', 'foxfire-studio' ),
					)
				);
			}
			$faces = rrt_fonts_plan( $link );
			if ( is_wp_error( $faces ) ) {
				wp_send_json_error( array( 'message' => $faces->get_error_message() ) );
			}
			$files = array_values( rrt_fonts_plan_files( $faces ) );
			set_transient(
				'rrt_fonts_plan',
				array(
					'link'  => $link,
					'faces' => $faces,
				),
				HOUR_IN_SECONDS
			);
			wp_send_json_success(
				array(
					'count'   => count( $files ),
					'message' => sprintf( /* translators: %s: count( $files ). */ __( 'Font list read: %d files to download.', 'foxfire-studio' ), count( $files ) ),
				)
			);
		}

		$plan = get_transient( 'rrt_fonts_plan' );
		if ( ! is_array( $plan ) || $plan['link'] !== $link ) {
			wp_send_json_error( array( 'message' => __( 'The font list has expired or the font link changed. Start the download again.', 'foxfire-studio' ) ) );
		}
		$urls  = rrt_fonts_plan_files( $plan['faces'] );
		$order = array_keys( $urls );

		if ( 'font' === $step ) {
			$i = isset( $_POST['i'] ) ? absint( $_POST['i'] ) : -1;
			if ( ! isset( $order[ $i ] ) ) {
				wp_send_json_error( array( 'message' => __( 'Unknown font file.', 'foxfire-studio' ) ), 400 );
			}
			$from = $order[ $i ];
			$dest = $dir . 'fonts/' . $urls[ $from ];
			$tmp  = rrt_local_download( $from, $dest );
			if ( is_wp_error( $tmp ) ) {
				wp_send_json_error( array( 'message' => $tmp->get_error_message() ) );
			}
			/* A woff2 file starts with "wOF2". Anything else is thrown away. */
			$fh    = fopen( $tmp, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- reading our own temp file.
			$magic = $fh ? fread( $fh, 4 ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( $fh ) {
				fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
			if ( 'wOF2' !== $magic || filesize( $tmp ) > 5 * MB_IN_BYTES ) {
				wp_delete_file( $tmp );
				wp_send_json_error( array( 'message' => __( 'A font file was not a valid woff2 font, so it was not saved.', 'foxfire-studio' ) ) );
			}
			rename( $tmp, $dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- moving our own temp file.
			wp_send_json_success( array( 'size' => filesize( $dest ) ) );
		}

		if ( 'fonts_finish' === $step ) {
			foreach ( $urls as $file ) {
				if ( ! file_exists( $dir . 'fonts/' . $file ) ) {
					wp_send_json_error( array( 'message' => __( 'Some font files are missing, so the fonts were not switched over. Try again.', 'foxfire-studio' ) ) );
				}
			}
			$css = rrt_fonts_css( $plan['faces'], $link );
			$tmp = $dir . 'fonts.css.part';
			if ( false === file_put_contents( $tmp, $css ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- writing our own file.
				wp_send_json_error( array( 'message' => __( 'Could not write fonts.css. Check that the uploads folder is writable.', 'foxfire-studio' ) ) );
			}
			rename( $tmp, $dir . 'fonts.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- moving our own temp file.

			/* Fonts from an older link are no longer needed. */
			$keep = array_flip( array_values( $urls ) );
			foreach ( (array) glob( $dir . 'fonts/*.woff2' ) as $old ) {
				if ( $old && ! isset( $keep[ basename( $old ) ] ) ) {
					wp_delete_file( $old );
				}
			}
			update_option(
				'rrt_local',
				array(
					'fonts_from'  => $link,
					'fonts_files' => array_values( $urls ),
					'fonts_at'    => time(),
				),
				true // Tiny, and read on every post with a player, so it loads with the other options.
			);
			delete_transient( 'rrt_fonts_plan' );
			wp_send_json_success( array( 'status' => rrt_local_status( rrt_config() ), 'message' => sprintf( /* translators: 1: count( $urls ), 2: rrt_size( rrt_local_bytes( array_merge( array( 'fonts.css' ). */ __( 'Fonts saved: %1$d files (%2$s).', 'foxfire-studio' ), count( $urls ), rrt_size( rrt_local_bytes( array_merge( array( 'fonts.css' ), array_map( function ( $f ) { return 'fonts/' . $f; }, array_values( $urls ) ) ) ) ) ) ) );
		}

		wp_send_json_error( array( 'message' => __( 'Unknown step.', 'foxfire-studio' ) ), 400 );
	}
);
