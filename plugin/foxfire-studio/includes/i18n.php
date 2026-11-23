<?php
/**
 * Translations for the plugin's scripts (0.25.0).
 *
 * Scripts use WordPress's own wp.i18n (__, _n, sprintf), with their
 * translations in languages/foxfire-studio-<locale>-<hash>.json as made by
 * `wp i18n make-json`. The teleprompter and share pages are standalone pages,
 * so their scripts are registered here and printed with wp_print_scripts(),
 * which adds wp.i18n and the translations in front of them. The listening
 * player on public posts takes its few words from the server instead, so
 * visitors' pages need no translation script at all.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Where the plugin's translation files live. */
function rrt_languages_dir() {
	return RRT_DIR . 'languages';
}

/**
 * Registers one of the plugin's scripts with wp.i18n and its translations.
 *
 * @param string $handle Script handle.
 * @param string $file   File name in assets/js/.
 * @param array  $deps   Other handles it needs first.
 */
function rrt_register_script( $handle, $file, $deps = array() ) {
	if ( ! wp_script_is( $handle, 'registered' ) ) {
		/*
		 * The file's time joins the version (1.33.0), as the admin styles already do, so a
		 * browser that keeps scripts for a long time (Safari on a phone) fetches a changed
		 * file at once, even when the version number has not moved.
		 */
		$mtime = (int) @filemtime( RRT_DIR . 'assets/js/' . $file );
		wp_register_script( $handle, RRT_URL . 'assets/js/' . $file, array_merge( array( 'wp-i18n' ), $deps ), RRT_VERSION . ( $mtime ? '.' . $mtime : '' ), false );
		wp_set_script_translations( $handle, 'foxfire-studio', rrt_languages_dir() );
	}
}

/**
 * The address of a script in assets/js/ that the page loads later by itself,
 * such as a worker (1.36.1). The file's time joins the version, as in
 * rrt_register_script(), so a phone fetches a changed worker at once.
 *
 * @param string $file File name in assets/js/.
 * @return string
 */
function rrt_js_url( $file ) {
	$mtime = (int) @filemtime( RRT_DIR . 'assets/js/' . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	return RRT_URL . 'assets/js/' . $file . '?ver=' . RRT_VERSION . ( $mtime ? '.' . $mtime : '' );
}

/**
 * The translations of scripts that another script loads later by itself (the
 * clip maker, the MP3 encoder and the noise model in the teleprompter), as a
 * piece of JavaScript to run before it. Empty when there are none.
 *
 * @param array $files Handle => file name in assets/js/.
 * @return string
 */
function rrt_script_translations_inline( $files ) {
	$out = '';
	foreach ( $files as $handle => $file ) {
		rrt_register_script( $handle, $file );
		$json = load_script_textdomain( $handle, 'foxfire-studio', rrt_languages_dir() );
		if ( $json ) {
			$out .= '( function ( domain, translations ) { var data = translations.locale_data[ domain ] || translations.locale_data.messages; data[""].domain = domain; wp.i18n.setLocaleData( data, domain ); } )( "foxfire-studio", ' . $json . ' );' . "\n";
		}
	}
	return $out;
}
