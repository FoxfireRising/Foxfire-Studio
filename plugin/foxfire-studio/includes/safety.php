<?php
/**
 * Which files on the site a reading may use (1.25.0).
 *
 * A reading's field can hold a Media Library file or a plain address, and
 * anyone who can edit a post can type an address. The short download
 * address, storage offload, collections and sponsor messages all turn that
 * address into a file on the server, and the web server's hand-off sends a
 * file without obeying its folder's .htaccess. So every one of them asks
 * here first. A file may be used when it is inside the uploads folder,
 * outside the plugin's private folders and outside any folder whose
 * .htaccess turns visitors away, and, when asked, has a listening file's
 * (or a captions or chapters file's) name and first bytes. A file kept for
 * members (1.21.0) is only ever its own post's.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The plugin's private folders in uploads: never a reading's file, never copied anywhere public. */
function rrt_private_folders() {
	return (array) apply_filters( 'rrt_private_folders', array( 'rrt-members', 'rrt-kept', 'rrt-sent', 'rrt-shares', 'rrt-audiobook', 'rrt-dl-check', 'rrt-shelf' ) );
}

/** The listening file extensions a reading may have. */
function rrt_listen_exts() {
	return array( 'mp3', 'm4a', 'mp4', 'm4v', 'ogg', 'oga', 'opus', 'wav', 'webm' );
}

/** The text files that travel with a reading: captions and chapters. */
function rrt_listen_text_exts() {
	return array( 'vtt', 'json', 'srt' );
}

/** The uploads folder, resolved, without a trailing slash, or ''. */
function rrt_uploads_real() {
	static $base = null;
	if ( null === $base ) {
		$up   = wp_upload_dir( null, false );
		$real = realpath( (string) $up['basedir'] );
		$base = $real ? untrailingslashit( str_replace( '\\', '/', $real ) ) : '';
	}
	return $base;
}

/**
 * Whether an .htaccess text turns visitors away from a file of this name.
 * A deny at the top level counts for every file; one inside a <Files> or
 * <FilesMatch> section counts only for names that section matches (security
 * plugins close PHP files in uploads this way, which must not close readings).
 *
 * @param string $text The .htaccess text.
 * @param string $name The file's name.
 */
function rrt_htaccess_denies( $text, $name ) {
	$deny = '/^\s*(Require\s+all\s+denied|Deny\s+from\s+all)\b/im';
	$top  = (string) preg_replace( '#<(Files|FilesMatch)\b[^>]*>.*?</\1\s*>#is', '', (string) $text );
	if ( preg_match( $deny, $top ) ) {
		return true;
	}
	if ( preg_match_all( '#<(Files|FilesMatch)\b\s*([^>]*)>(.*?)</\1\s*>#is', (string) $text, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $sec ) {
			if ( ! preg_match( $deny, $sec[3] ) ) {
				continue;
			}
			$arg = trim( trim( $sec[2] ), '"\'' );
			$hit = false;
			if ( 0 === strcasecmp( $sec[1], 'FilesMatch' ) || 0 === strpos( $arg, '~' ) ) {
				$re  = '#' . str_replace( '#', '\\#', ltrim( $arg, '~ ' ) ) . '#i';
				$hit = (bool) @preg_match( $re, $name ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a site's own pattern may not be valid PCRE.
			} else {
				foreach ( preg_split( '/\s+/', $arg ) as $glob ) {
					$glob = trim( $glob, '"\'' );
					if ( '' === $glob ) {
						continue;
					}
					$re = '#^' . str_replace( array( '\\*', '\\?' ), array( '.*', '.' ), preg_quote( $glob, '#' ) ) . '$#i';
					if ( preg_match( $re, $name ) ) {
						$hit = true;
					}
				}
			}
			if ( $hit ) {
				return true;
			}
		}
	}
	return false;
}

/**
 * Whether a folder inside uploads (or one above it, up to uploads) has an
 * .htaccess that turns visitors away from this file.
 *
 * @param string $dir  A resolved folder inside uploads.
 * @param string $base The resolved uploads folder.
 * @param string $name The file's name.
 */
function rrt_dir_denied( $dir, $base, $name = '' ) {
	static $texts = array();
	$dir = untrailingslashit( $dir );
	/* uploads' own .htaccess counts too (it closes every folder in it). */
	while ( strlen( $dir ) >= strlen( $base ) && ( $dir === $base || 0 === strpos( $dir, $base . '/' ) ) ) {
		if ( ! isset( $texts[ $dir ] ) ) {
			$f             = $dir . '/.htaccess';
			$texts[ $dir ] = is_readable( $f ) ? (string) file_get_contents( $f, false, null, 0, 65536 ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions -- reading a small local file.
		}
		if ( '' !== $texts[ $dir ] && rrt_htaccess_denies( $texts[ $dir ], $name ) ) {
			return true;
		}
		if ( $dir === $base ) {
			break;
		}
		$dir = dirname( $dir );
	}
	return false;
}

/**
 * Whether a file is the kind asked for, by its name and its first bytes.
 *
 * @param string $path The file.
 * @param string $kind '' (any), 'audio', 'text' (captions or chapters) or 'listen' (either).
 */
function rrt_file_kind_ok( $path, $kind ) {
	if ( '' === $kind ) {
		return true;
	}
	$ext = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
	if ( in_array( $kind, array( 'audio', 'listen' ), true ) && in_array( $ext, rrt_listen_exts(), true ) ) {
		return function_exists( 'rrt_share_sniff' ) && null !== rrt_share_sniff( $path );
	}
	if ( in_array( $kind, array( 'text', 'listen' ), true ) && in_array( $ext, rrt_listen_text_exts(), true ) ) {
		$h = ltrim( (string) file_get_contents( $path, false, null, 0, 64 ), "\xEF\xBB\xBF \t\r\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- reading a few bytes.
		if ( 'vtt' === $ext ) {
			return 0 === strpos( $h, 'WEBVTT' );
		}
		if ( 'json' === $ext ) {
			return '' !== $h && in_array( $h[0], array( '{', '[' ), true );
		}
		return '' !== $h && ctype_digit( $h[0] );
	}
	return false;
}

/**
 * Whether a file may be used as (or with) a reading: inside uploads, outside
 * the private folders and any denied folder, and of the kind asked for.
 *
 * @param string $path The file.
 * @param string $kind As rrt_file_kind_ok().
 */
function rrt_upload_path_ok( $path, $kind = '' ) {
	$base = rrt_uploads_real();
	$real = '' !== (string) $path ? realpath( (string) $path ) : false;
	if ( '' === $base || ! $real || ! is_file( $real ) ) {
		return false;
	}
	$real = str_replace( '\\', '/', $real );
	if ( 0 !== strpos( $real, $base . '/' ) ) {
		return false;
	}
	$rel   = substr( $real, strlen( $base ) + 1 );
	$first = strtok( $rel, '/' );
	if ( in_array( $first, rrt_private_folders(), true ) ) {
		return false;
	}
	if ( rrt_dir_denied( dirname( $real ), $base, basename( $real ) ) ) {
		return false;
	}
	return rrt_file_kind_ok( $real, $kind );
}

/**
 * An attachment's file, when the post may use it: a file kept for members
 * only for its own post, any other file by the rule above. '' otherwise.
 *
 * @param int    $att     The attachment.
 * @param int    $post_id The post that wants it (0 for none).
 * @param string $kind    As rrt_file_kind_ok().
 */
function rrt_att_file( $att, $post_id = 0, $kind = '' ) {
	$att = (int) $att;
	if ( $att <= 0 || 'attachment' !== get_post_type( $att ) ) {
		return '';
	}
	$path = (string) get_attached_file( $att, true );
	if ( '' === $path || ! is_file( $path ) ) {
		return '';
	}
	if ( function_exists( 'rrt_mfile_is' ) && rrt_mfile_is( $att ) ) {
		return ( $post_id > 0 && rrt_mfile_post( $att ) === (int) $post_id && rrt_file_kind_ok( $path, $kind ) ) ? $path : '';
	}
	return rrt_upload_path_ok( $path, $kind ) ? $path : '';
}

/** Whether an attachment may be chosen for a post's reading or captions in the Reading audio box. */
function rrt_att_choosable( $att, $post_id ) {
	if ( function_exists( 'rrt_mfile_is' ) && rrt_mfile_is( $att ) ) {
		return rrt_mfile_post( $att ) === (int) $post_id;
	}
	$path = (string) get_attached_file( $att, true );
	return '' === $path || ! is_file( $path ) || rrt_upload_path_ok( $path );
}

/* The plugin's own fields are set in the Reading audio box, never typed into the Custom Fields panel. */
add_filter(
	'is_protected_meta',
	function ( $protected, $key ) {
		return in_array( $key, array( 'rrt_audio', 'rrt_captions', 'rrt_credit' ), true ) ? true : $protected;
	},
	10,
	2
);

/*
 * ---------------------------------------------------------------------
 * Check private folders (1.25.0)
 * ---------------------------------------------------------------------
 *
 * The private folders keep visitors out with an .htaccess file, which
 * LiteSpeed and Apache obey and nginx ignores. The check puts a small test
 * file in each folder and the owner's browser asks for it the way a visitor
 * would (no sign-in, no cache). Any folder that answers is named.
 */

/** The folders the check looks at: name => the function that makes the folder and its .htaccess. */
function rrt_private_check_folders() {
	return array(
		'rrt-members'   => 'rrt_mfiles_ready',
		'rrt-kept'      => 'rrt_kept_dir_ready',
		'rrt-sent'      => 'rrt_sent_dir_ready',
		'rrt-shares'    => 'rrt_share_dir_ready',
		'rrt-audiobook' => 'rrt_book_dir_ready',
		'rrt-shelf'     => 'rrt_shelf_dir_ready',
	);
}

/** Whether the site runs on nginx, by what the server says it is. */
function rrt_is_nginx() {
	$sw = isset( $_SERVER['SERVER_SOFTWARE'] ) ? (string) $_SERVER['SERVER_SOFTWARE'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- only compared.
	return false !== stripos( $sw, 'nginx' );
}

/** The nginx lines that close the private folders. */
function rrt_private_nginx_lines() {
	$up   = wp_upload_dir( null, false );
	$path = (string) wp_parse_url( (string) $up['baseurl'], PHP_URL_PATH );
	return 'location ~ ^' . preg_quote( untrailingslashit( $path ), '/' ) . '/(' . implode( '|', array_keys( rrt_private_check_folders() ) ) . ")/ {\n\tdeny all;\n\treturn 403;\n}";
}

/** Part one: a test file in each folder, and the addresses to ask for. */
function rrt_private_check_start() {
	$up    = wp_upload_dir( null, false );
	$token = strtolower( wp_generate_password( 24, false, false ) );
	$files = array();
	$out   = array();
	foreach ( rrt_private_check_folders() as $name => $ready ) {
		if ( ! function_exists( $ready ) || ! call_user_func( $ready ) ) {
			continue;
		}
		$file = trailingslashit( $up['basedir'] ) . $name . '/rrt-probe-' . $token . '.txt';
		if ( false === file_put_contents( $file, $token ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- our own test file.
			continue;
		}
		$files[ $name ] = $file;
		$out[]          = array(
			'folder' => $name,
			'url'    => trailingslashit( $up['baseurl'] ) . $name . '/rrt-probe-' . $token . '.txt',
		);
	}
	set_transient( 'rrt_pcheck_' . $token, $files, 5 * MINUTE_IN_SECONDS );
	return array(
		'token'   => $token,
		'folders' => $out,
	);
}

/**
 * Part two: what the browser got, the test files deleted, the result kept.
 *
 * @param string $token   From part one.
 * @param array  $results folder => the text the browser read ('' when refused).
 */
function rrt_private_check_done( $token, $results ) {
	$token = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $token ) );
	$files = '' !== $token ? get_transient( 'rrt_pcheck_' . $token ) : false;
	if ( ! is_array( $files ) ) {
		return new WP_Error( 'rrt_pcheck', __( 'The check ran out of time. Press Check private folders again.', 'foxfire-studio' ), array( 'status' => 400 ) );
	}
	delete_transient( 'rrt_pcheck_' . $token );
	$open = array();
	foreach ( $files as $name => $file ) {
		if ( isset( $results[ $name ] ) && trim( (string) $results[ $name ] ) === $token ) {
			$open[] = $name;
		}
		if ( is_file( $file ) ) {
			wp_delete_file( $file );
		}
	}
	$res = array(
		'at'      => time(),
		'checked' => array_keys( $files ),
		'open'    => $open,
	);
	update_option( 'rrt_private_check', $res, false );
	return $res;
}

add_action(
	'rest_api_init',
	function () {
		$can = function () {
			return current_user_can( 'manage_options' );
		};
		register_rest_route(
			'rrt/v1',
			'/private-check/start',
			array(
				'methods'             => 'POST',
				'permission_callback' => $can,
				'callback'            => function () {
					return rest_ensure_response( rrt_private_check_start() );
				},
			)
		);
		register_rest_route(
			'rrt/v1',
			'/private-check/done',
			array(
				'methods'             => 'POST',
				'permission_callback' => $can,
				'callback'            => function ( $req ) {
					return rest_ensure_response( rrt_private_check_done( (string) $req->get_param( 'token' ), (array) $req->get_param( 'results' ) ) );
				},
			)
		);
	}
);

/** The section on Podcast feed. */
function rrt_private_check_section() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$last = get_option( 'rrt_private_check' );
	$last = is_array( $last ) ? $last : null;
	$boot = array(
		'rest'  => esc_url_raw( rest_url( 'rrt/v1/private-check/' ) ),
		'nonce' => wp_create_nonce( 'wp_rest' ),
	);
	wp_enqueue_script( 'rrt-privatecheck', RRT_URL . 'assets/js/privatecheck.js', array( 'wp-i18n' ), RRT_VERSION . '.' . (int) @filemtime( RRT_DIR . 'assets/js/privatecheck.js' ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	wp_set_script_translations( 'rrt-privatecheck', 'foxfire-studio', rrt_languages_dir() );
	$show_nginx = rrt_is_nginx() || ( $last && ! empty( $last['open'] ) );
	?>
	<h2 id="rrt-private"><?php esc_html_e( 'Private folders', 'foxfire-studio' ); ?></h2>
	<div id="rrt-private-check" data-rrt-pc="<?php echo esc_attr( wp_json_encode( $boot ) ); ?>">
		<p><?php esc_html_e( 'Readings for members, readings kept aside, takes sent from a phone, shared takes and audiobook copies are kept in folders in uploads that visitors are turned away from. Check private folders puts a small test file in each one and asks for it from this browser the way a visitor would, then deletes the test files.', 'foxfire-studio' ); ?></p>
		<p><button type="button" class="button" data-rrt-pc-run><?php esc_html_e( 'Check private folders', 'foxfire-studio' ); ?></button> <span data-rrt-pc-status aria-live="polite"><?php
		if ( $last ) {
			echo esc_html(
				empty( $last['open'] )
					/* translators: %s: a date and time. */
					? sprintf( __( 'Last checked %s: every folder turned visitors away.', 'foxfire-studio' ), wp_date( 'M j, g:i a', (int) $last['at'] ) )
					/* translators: 1: a date and time, 2: folder names. */
					: sprintf( __( 'Last checked %1$s: visitors could open %2$s.', 'foxfire-studio' ), wp_date( 'M j, g:i a', (int) $last['at'] ), implode( ', ', (array) $last['open'] ) )
			);
		}
		?></span></p>
		<?php if ( $show_nginx ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'nginx does not read .htaccess files, so on nginx these folders need lines in the site\'s nginx setup (ask your host to add them inside the server block, then press Check private folders again):', 'foxfire-studio' ); ?></p>
			<pre><code><?php echo esc_html( rrt_private_nginx_lines() ); ?></code></pre></div>
		<?php endif; ?>
	</div>
	<?php
}
