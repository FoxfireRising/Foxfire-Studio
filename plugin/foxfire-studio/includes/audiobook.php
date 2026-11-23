<?php
/**
 * Audiobook export (1.20.0): a collection made into the files an audiobook
 * store asks for, to ACX's rules (Audible), which Spotify for Authors,
 * Authors Republic and Findaway also accept.
 *
 * Each reading's chapter comes from its audiobook copy when it has one: the
 * same take finished once more with the Audiobook (ACX) preset, voice only,
 * 192 kbps mono, kept here in a folder visitors cannot open
 * (uploads/rrt-audiobook). The studio makes it when a take is put on its
 * post (Settings, Broadcast sound, Keep an audiobook copy), or on request
 * from the review (Finish for, Keep as this post's audiobook chapter).
 *
 * A reading with no copy and no music bed can be made from its post's MP3
 * in the browser instead; a reading with the music bed cannot, since the
 * music fills every pause where the room should be heard.
 *
 * The opening and closing credits are short readings of their own (post
 * type rrt_credit, a child of the collection), recorded in the studio like
 * any post; only their audiobook copy is kept. The retail sample is cut from
 * a reading. The Audiobook box on the collection's screen (assets/js/
 * audiobook.js) makes every file in the browser, checks each against the
 * rules and downloads them as one ZIP with a check report. Nothing of the
 * ZIP is kept on the site.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** On a reading's post (or a credit): its audiobook copy. */
define( 'RRT_BOOK_META', '_rrt_book' );

/** On a collection: the retail sample's choices. */
define( 'RRT_BOOK_COL_META', '_rrt_book_col' );

/** The credits' post type. */
define( 'RRT_CREDIT_TYPE', 'rrt_credit' );

/** On a credit: open or close. */
define( 'RRT_CREDIT_KIND', '_rrt_credit_kind' );

/** Room tone wanted at the start and the end of every audiobook file, in seconds. */
define( 'RRT_BOOK_HEAD', 1.2 );
define( 'RRT_BOOK_TAIL', 3.0 );

/** The longest file ACX accepts, in minutes. */
define( 'RRT_BOOK_MAX_MIN', 120 );

/** The smallest cover ACX accepts, in pixels on each side. */
define( 'RRT_BOOK_COVER_MIN', 2400 );

/*
 * ---------------------------------------------------------------------
 * The folder and the copies
 * ---------------------------------------------------------------------
 */

/** The folder that holds audiobook copies. */
function rrt_book_dir() {
	$up = wp_upload_dir( null, false );
	return trailingslashit( $up['basedir'] ) . 'rrt-audiobook/';
}

/** Makes the folder, with files that refuse every direct request. */
function rrt_book_dir_ready() {
	$dir = rrt_book_dir();
	if ( ! wp_mkdir_p( $dir ) ) {
		return false;
	}
	if ( ! file_exists( $dir . '.htaccess' ) ) {
		/* Apache 2.4 and LiteSpeed read the first; older Apache the second. */
		file_put_contents( $dir . '.htaccess', "# Foxfire Studio: audiobook copies are served only to people who can edit their post.\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
	if ( ! file_exists( $dir . 'index.php' ) ) {
		file_put_contents( $dir . 'index.php', "<?php\n// Silence.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
	return true;
}

/** A post's audiobook copy (file, secs, bytes, at, kbps and the check's numbers), or null when it has none on the site. */
function rrt_book_get( $post_id ) {
	$m = get_post_meta( (int) $post_id, RRT_BOOK_META, true );
	if ( ! is_array( $m ) || empty( $m['file'] ) ) {
		return null;
	}
	$path = rrt_book_dir() . basename( (string) $m['file'] );
	if ( ! is_file( $path ) ) {
		return null;
	}
	$m['path'] = $path;
	return $m;
}

/** Removes a post's audiobook copy and its file. */
function rrt_book_delete( $post_id ) {
	$m = get_post_meta( (int) $post_id, RRT_BOOK_META, true );
	if ( is_array( $m ) && ! empty( $m['file'] ) ) {
		$path = rrt_book_dir() . basename( (string) $m['file'] );
		if ( is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}
	delete_post_meta( (int) $post_id, RRT_BOOK_META );
}

/** Whether a person may make, hear and remove a post's audiobook copy. */
function rrt_book_can( $post_id ) {
	/* A sponsor message (1.22.0) is never an audiobook chapter. */
	if ( function_exists( 'rrt_spot_is' ) && rrt_spot_is( $post_id ) ) {
		return false;
	}
	return $post_id > 0 && get_post( $post_id ) && current_user_can( 'edit_post', $post_id ) && current_user_can( 'upload_files' );
}

/** The address that plays (or downloads) a post's audiobook copy, for people who can edit the post. */
function rrt_book_audio_url( $post_id, $download = false ) {
	return add_query_arg(
		array(
			'action'   => 'rrt_book_audio',
			'post'     => (int) $post_id,
			'download' => $download ? 1 : null,
			'_wpnonce' => wp_create_nonce( 'rrt_book_audio_' . (int) $post_id ),
		),
		admin_url( 'admin-post.php' )
	);
}

/** A number from a request, rounded, or null. */
function rrt_book_num( $v, $places = 2 ) {
	return is_numeric( $v ) && is_finite( (float) $v ) ? round( (float) $v, $places ) : null;
}

/**
 * Keeps an uploaded audiobook copy for a post. The file must be an MP3 the
 * stores accept: constant bitrate, 192 kbps or more, 44.1 kHz.
 *
 * @param int    $post_id The post.
 * @param string $tmp     The uploaded file.
 * @param array  $facts   The check's numbers from the browser (rms, peak, tp, noise, head, tail) and from (take or credit).
 * @return array|WP_Error The copy as rrt_book_public() gives it.
 */
function rrt_book_store( $post_id, $tmp, $facts ) {
	$scan = rrt_col_scan( $tmp );
	if ( ! $scan['ok'] ) {
		return new WP_Error( 'rrt_book', __( 'That file is not a constant-bitrate MP3.', 'foxfire-studio' ), array( 'status' => 400 ) );
	}
	if ( (int) $scan['kbps'] < 192 || 44100 !== (int) $scan['rate'] ) {
		return new WP_Error( 'rrt_book', __( 'An audiobook copy must be an MP3 at 192 kbps or more and 44.1 kHz.', 'foxfire-studio' ), array( 'status' => 400 ) );
	}
	if ( ! rrt_book_dir_ready() ) {
		return new WP_Error( 'rrt_book', __( 'The folder for audiobook copies could not be made in the uploads folder.', 'foxfire-studio' ), array( 'status' => 500 ) );
	}
	$name = (int) $post_id . '-' . time() . '-' . strtolower( wp_generate_password( 8, false ) ) . '.mp3';
	$dest = rrt_book_dir() . $name;
	if ( ! @copy( $tmp, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return new WP_Error( 'rrt_book', __( 'The audiobook copy could not be saved on the site.', 'foxfire-studio' ), array( 'status' => 500 ) );
	}
	rrt_book_delete( $post_id );
	$m = array(
		'file'  => $name,
		'secs'  => round( (float) $scan['secs'], 2 ),
		'bytes' => (int) filesize( $dest ),
		'kbps'  => (int) $scan['kbps'],
		'ch'    => (int) $scan['ch'],
		'at'    => time(),
		'from'  => isset( $facts['from'] ) && in_array( $facts['from'], array( 'take', 'credit' ), true ) ? $facts['from'] : 'take',
	);
	foreach ( array( 'rms', 'peak', 'tp', 'noise', 'head', 'tail' ) as $k ) {
		$m[ $k ] = rrt_book_num( isset( $facts[ $k ] ) ? $facts[ $k ] : null );
	}
	update_post_meta( $post_id, RRT_BOOK_META, $m );
	return rrt_book_public( $post_id );
}

/** A post's audiobook copy as the screens show it, or null. */
function rrt_book_public( $post_id ) {
	$m = rrt_book_get( $post_id );
	if ( ! $m ) {
		return null;
	}
	return array(
		'secs'  => (float) $m['secs'],
		'bytes' => (int) $m['bytes'],
		'kbps'  => (int) $m['kbps'],
		'ch'    => isset( $m['ch'] ) ? (int) $m['ch'] : 1,
		'at'    => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $m['at'] ),
		'from'  => (string) $m['from'],
		'url'   => rrt_book_audio_url( $post_id ),
		'rms'   => isset( $m['rms'] ) ? $m['rms'] : null,
		'peak'  => isset( $m['peak'] ) ? $m['peak'] : null,
		'noise' => isset( $m['noise'] ) ? $m['noise'] : null,
	);
}

/* Plays or downloads an audiobook copy. Byte ranges are answered, so a player can seek. */
add_action(
	'admin_post_rrt_book_audio',
	function () {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce is checked on the next lines, after the id it names is read.
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'rrt_book_audio_' . $post_id ) || ! rrt_book_can( $post_id ) ) {
			status_header( 403 );
			exit;
		}
		$m = rrt_book_get( $post_id );
		if ( ! $m ) {
			status_header( 404 );
			exit;
		}
		$path  = $m['path'];
		$size  = (int) filesize( $path );
		$start = 0;
		$end   = $size - 1;
		$range = isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '';
		if ( $range && preg_match( '/^bytes=(\d*)-(\d*)$/', $range, $mm ) && ( '' !== $mm[1] || '' !== $mm[2] ) ) {
			if ( '' === $mm[1] ) {
				$start = max( 0, $size - (int) $mm[2] );
			} else {
				$start = (int) $mm[1];
				$end   = '' === $mm[2] ? $end : min( $end, (int) $mm[2] );
			}
			if ( $start > $end || $start >= $size ) {
				status_header( 416 );
				header( 'Content-Range: bytes */' . $size );
				exit;
			}
			status_header( 206 );
			header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $size );
		}
		nocache_headers();
		do_action( 'litespeed_control_set_nocache', 'audiobook copies are private' );
		header( 'Content-Type: audio/mpeg' );
		header( 'Accept-Ranges: bytes' );
		header( 'Content-Length: ' . ( $end - $start + 1 ) );
		header( 'Cache-Control: no-store, private' );
		header( 'X-Content-Type-Options: nosniff' );
		if ( ! empty( $_GET['download'] ) ) {
			$name = sanitize_file_name( wp_strip_all_tags( get_the_title( $post_id ) ) . ' (audiobook)' ) . '.mp3';
			header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', $name ) . '"' );
		}
		$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fseek( $fh, $start );
		$left = $end - $start + 1;
		while ( $left > 0 && ! feof( $fh ) ) {
			$chunk = fread( $fh, (int) min( 1048576, $left ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$left -= strlen( $chunk );
			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput
			flush();
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}
);

/* A post deleted for good takes its audiobook copy with it; a collection, its credits. */
add_action(
	'before_delete_post',
	function ( $post_id ) {
		if ( get_post_meta( $post_id, RRT_BOOK_META, true ) ) {
			rrt_book_delete( $post_id );
		}
		if ( function_exists( 'rrt_col_is' ) && rrt_col_is( $post_id ) ) {
			foreach ( rrt_book_credits( $post_id ) as $c ) {
				wp_delete_post( $c->ID, true );
			}
		}
	}
);

/*
 * ---------------------------------------------------------------------
 * The credits
 * ---------------------------------------------------------------------
 */

add_action(
	'init',
	function () {
		register_post_type(
			RRT_CREDIT_TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Audiobook credits', 'foxfire-studio' ),
					'singular_name' => __( 'Audiobook credits', 'foxfire-studio' ),
				),
				'public'          => false,
				'show_ui'         => false,
				'show_in_rest'    => false,
				'rewrite'         => false,
				'query_var'       => false,
				'capability_type' => 'post',
				'map_meta_cap'    => true,
				'supports'        => array( 'title', 'editor', 'author' ),
			)
		);
	},
	5
);

/** A collection's credits posts. */
function rrt_book_credits( $col_id ) {
	return get_posts(
		array(
			'post_type'        => RRT_CREDIT_TYPE,
			'post_parent'      => (int) $col_id,
			'post_status'      => 'any',
			'numberposts'      => 10,
			'suppress_filters' => true,
		)
	);
}

/** One of a collection's credits (open or close), or null. */
function rrt_book_credit( $col_id, $kind ) {
	foreach ( rrt_book_credits( $col_id ) as $c ) {
		if ( get_post_meta( $c->ID, RRT_CREDIT_KIND, true ) === $kind ) {
			return $c;
		}
	}
	return null;
}

/** Whether a post is audiobook credits. */
function rrt_book_is_credit( $post ) {
	$post = get_post( $post );
	return $post && RRT_CREDIT_TYPE === $post->post_type;
}

/** The author's name for a collection's credits: the name in its MP3s' tags. */
function rrt_book_author( $col_id ) {
	$p = get_post( $col_id );
	return $p ? wp_strip_all_tags( get_the_author_meta( 'display_name', $p->post_author ) ) : '';
}

/**
 * The credits' scripts as first written: the first line is read first (the
 * studio reads a post's title before its text), the rest after it.
 *
 * @param int    $col_id The collection.
 * @param string $kind   open or close.
 * @return array title, text.
 */
function rrt_book_credit_default( $col_id, $kind ) {
	$title  = wp_strip_all_tags( get_the_title( $col_id ) );
	$author = rrt_book_author( $col_id );
	if ( 'close' === $kind ) {
		return array(
			'title' => __( 'The end.', 'foxfire-studio' ),
			/* translators: 1: the audiobook's title, 2: the author's name. */
			'text'  => sprintf( __( '%1$s was written and narrated by %2$s.', 'foxfire-studio' ), $title, $author ),
		);
	}
	return array(
		'title' => $title . '.',
		/* translators: 1: the author's name. */
		'text'  => sprintf( __( 'Written by %1$s. Narrated by %1$s.', 'foxfire-studio' ), $author ),
	);
}

/** A credit's script as one text: the first line, then the rest. */
function rrt_book_credit_script( $col_id, $kind ) {
	$c = rrt_book_credit( $col_id, $kind );
	if ( $c ) {
		return trim( wp_strip_all_tags( $c->post_title ) . "\n" . trim( wp_strip_all_tags( $c->post_content ) ) );
	}
	$d = rrt_book_credit_default( $col_id, $kind );
	return $d['title'] . "\n" . $d['text'];
}

/**
 * Makes or updates a collection's credits post from its script.
 *
 * @param int    $col_id The collection.
 * @param string $kind   open or close.
 * @param string $script The first line, then the rest.
 * @return int The credits post, or 0.
 */
function rrt_book_credit_save( $col_id, $kind, $script ) {
	$lines = preg_split( '/\r\n|\r|\n/', trim( (string) $script ) );
	$lines = array_values( array_filter( array_map( 'trim', $lines ), 'strlen' ) );
	if ( ! $lines ) {
		$d     = rrt_book_credit_default( $col_id, $kind );
		$lines = array( $d['title'], $d['text'] );
	}
	$title = mb_substr( sanitize_text_field( array_shift( $lines ) ), 0, 200 );
	$text  = implode( "\n\n", array_map( 'sanitize_text_field', $lines ) );
	$text  = mb_substr( $text, 0, 2000 );
	$c     = rrt_book_credit( $col_id, $kind );
	$post  = array(
		'post_type'    => RRT_CREDIT_TYPE,
		'post_parent'  => (int) $col_id,
		'post_status'  => 'publish',
		'post_title'   => $title,
		'post_content' => $text,
		'post_author'  => (int) get_post_field( 'post_author', $col_id ),
	);
	if ( $c ) {
		if ( $c->post_title === $title && trim( $c->post_content ) === $text ) {
			return (int) $c->ID;
		}
		$post['ID'] = $c->ID;
		$id         = wp_update_post( $post, true );
	} else {
		$id = wp_insert_post( $post, true );
		if ( ! is_wp_error( $id ) ) {
			update_post_meta( $id, RRT_CREDIT_KIND, 'close' === $kind ? 'close' : 'open' );
		}
	}
	return is_wp_error( $id ) ? 0 : (int) $id;
}

/*
 * ---------------------------------------------------------------------
 * A collection as an audiobook
 * ---------------------------------------------------------------------
 */

/** The retail sample's choices: the reading, where it starts (seconds, or null for just after the title) and how many minutes. */
function rrt_book_sample( $col_id ) {
	$m     = get_post_meta( $col_id, RRT_BOOK_COL_META, true );
	$m     = is_array( $m ) ? $m : array();
	$items = rrt_col_settings( $col_id )['items'];
	$post  = isset( $m['post'] ) ? (int) $m['post'] : 0;
	if ( ! in_array( $post, $items, true ) ) {
		$post = $items ? (int) $items[0] : 0;
	}
	$len = isset( $m['len'] ) ? (int) $m['len'] : 3;
	return array(
		'post'  => $post,
		'start' => isset( $m['start'] ) && is_numeric( $m['start'] ) && $m['start'] >= 0 ? round( (float) $m['start'], 1 ) : null,
		'len'   => max( 1, min( 5, $len ) ),
	);
}

/**
 * Where each reading's chapter will come from: 'book' (its audiobook copy),
 * 'post' (its post's MP3, made in the browser) or 'need' (it needs a copy),
 * with the reason for 'need': music, none, notmp3, away, unpublished.
 *
 * @param int $post_id The reading's post.
 */
function rrt_book_source( $post_id ) {
	$p = get_post( $post_id );
	if ( ! $p || 'trash' === $p->post_status ) {
		return array( 'source' => 'need', 'why' => 'none' );
	}
	if ( rrt_book_get( $post_id ) ) {
		return array( 'source' => 'book', 'why' => '' );
	}
	$a = rrt_reading_audio( $post_id );
	if ( '' === $a['url'] ) {
		return array( 'source' => 'need', 'why' => 'none' );
	}
	$path = rrt_col_reading_path( $post_id );
	if ( '' === $path ) {
		return array( 'source' => 'need', 'why' => 'away' );
	}
	$scan = rrt_col_scan_cached( $path );
	if ( ! $scan['ok'] ) {
		return array( 'source' => 'need', 'why' => 'notmp3' );
	}
	$music = get_post_meta( $post_id, '_rrt_music_used', true );
	if ( is_array( $music ) && ! empty( $music['audio'] ) && (int) $music['audio'] === (int) $a['id'] ) {
		return array( 'source' => 'need', 'why' => 'music' );
	}
	return array( 'source' => 'post', 'why' => '' );
}

/** A file name with only the plain letters, numbers and spaces ACX allows. */
function rrt_book_file_name( $n, $title ) {
	$t = remove_accents( wp_strip_all_tags( (string) $title ) );
	$t = preg_replace( '/[^A-Za-z0-9 ]+/', ' ', $t );
	$t = trim( preg_replace( '/\s+/', ' ', $t ) );
	$t = '' === $t ? 'Chapter' : mb_substr( $t, 0, 60 );
	return sprintf( '%02d %s.mp3', (int) $n, trim( $t ) );
}

/** Everything the Audiobook box needs about a collection. */
function rrt_book_state( $col_id ) {
	$items = rrt_col_settings( $col_id )['items'];
	$rows  = array();
	foreach ( $items as $i => $id ) {
		$src  = rrt_book_source( $id );
		$a    = rrt_reading_audio( $id );
		$caps = rrt_reading_captions( $id );
		$rows[] = array(
			'id'       => (int) $id,
			'title'    => wp_strip_all_tags( get_the_title( $id ) ),
			'source'   => $src['source'],
			'why'      => $src['why'],
			'book'     => rrt_book_public( $id ),
			'url'      => 'post' === $src['source'] ? (string) $a['url'] : '',
			'captions' => (string) $caps['url'],
			'studio'   => current_user_can( 'edit_post', $id ) ? rrt_url( $id ) : '',
			'edit'     => (string) get_edit_post_link( $id, 'raw' ),
			'file'     => rrt_book_file_name( $i + 1, get_the_title( $id ) ),
		);
	}
	$credits = array();
	foreach ( array( 'open', 'close' ) as $kind ) {
		$c                = rrt_book_credit( $col_id, $kind );
		$credits[ $kind ] = array(
			'id'     => $c ? (int) $c->ID : 0,
			'script' => rrt_book_credit_script( $col_id, $kind ),
			'book'   => $c ? rrt_book_public( $c->ID ) : null,
			'studio' => $c ? rrt_url( $c->ID ) : '',
		);
	}
	$cover = null;
	$thumb = get_post_thumbnail_id( $col_id );
	if ( $thumb ) {
		$img = wp_get_attachment_image_src( $thumb, 'full' );
		if ( $img ) {
			$cover = array(
				'url'  => (string) $img[0],
				'w'    => (int) $img[1],
				'h'    => (int) $img[2],
				'mime' => (string) get_post_mime_type( $thumb ),
			);
		}
	}
	return array(
		'col'     => (int) $col_id,
		'title'   => wp_strip_all_tags( get_the_title( $col_id ) ),
		'slug'    => (string) get_post_field( 'post_name', $col_id ),
		'author'  => rrt_book_author( $col_id ),
		'rows'    => $rows,
		'credits' => $credits,
		'sample'  => rrt_book_sample( $col_id ),
		'cover'   => $cover,
		'closeN'  => count( $rows ) + 1,
		'rules'   => array(
			'head'     => RRT_BOOK_HEAD,
			'tail'     => RRT_BOOK_TAIL,
			'maxMin'   => RRT_BOOK_MAX_MIN,
			'coverMin' => RRT_BOOK_COVER_MIN,
		),
	);
}

/*
 * ---------------------------------------------------------------------
 * REST routes
 * ---------------------------------------------------------------------
 */

add_action(
	'rest_api_init',
	function () {
		$post_can = function ( $req ) {
			return rrt_book_can( (int) $req['post'] );
		};
		$col_can = function ( $req ) {
			$c = (int) $req['col'];
			return rrt_col_is( $c ) && current_user_can( 'edit_post', $c ) && current_user_can( 'upload_files' );
		};
		register_rest_route(
			'rrt/v1',
			'/audiobook/(?P<post>\d+)/copy',
			array(
				array(
					'methods'             => 'POST',
					'permission_callback' => $post_can,
					'callback'            => function ( $req ) {
						$files = $req->get_file_params();
						if ( empty( $files['mp3'] ) || ! empty( $files['mp3']['error'] ) ) {
							return new WP_Error( 'rrt_book', __( 'No audiobook copy arrived. Try again.', 'foxfire-studio' ), array( 'status' => 400 ) );
						}
						$facts = array( 'from' => sanitize_key( (string) $req['from'] ) );
						foreach ( array( 'rms', 'peak', 'tp', 'noise', 'head', 'tail' ) as $k ) {
							$facts[ $k ] = $req[ $k ];
						}
						$out = rrt_book_store( (int) $req['post'], $files['mp3']['tmp_name'], $facts );
						return is_wp_error( $out ) ? $out : rest_ensure_response( array( 'book' => $out ) );
					},
				),
				array(
					'methods'             => 'DELETE',
					'permission_callback' => $post_can,
					'callback'            => function ( $req ) {
						rrt_book_delete( (int) $req['post'] );
						return rest_ensure_response( array( 'book' => null ) );
					},
				),
			)
		);
		register_rest_route(
			'rrt/v1',
			'/audiobook/col/(?P<col>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => $col_can,
					'callback'            => function ( $req ) {
						return rest_ensure_response( rrt_book_state( (int) $req['col'] ) );
					},
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => $col_can,
					'callback'            => function ( $req ) {
						$col = (int) $req['col'];
						$s   = $req['sample'];
						if ( is_array( $s ) ) {
							$items = rrt_col_settings( $col )['items'];
							$post  = isset( $s['post'] ) ? (int) $s['post'] : 0;
							update_post_meta(
								$col,
								RRT_BOOK_COL_META,
								array(
									'post'  => in_array( $post, $items, true ) ? $post : 0,
									'start' => isset( $s['start'] ) && is_numeric( $s['start'] ) && $s['start'] >= 0 ? round( (float) $s['start'], 1 ) : null,
									'len'   => isset( $s['len'] ) ? max( 1, min( 5, (int) $s['len'] ) ) : 3,
								)
							);
						}
						$cr = $req['credits'];
						if ( is_array( $cr ) ) {
							foreach ( array( 'open', 'close' ) as $kind ) {
								if ( isset( $cr[ $kind ] ) && is_string( $cr[ $kind ] ) ) {
									rrt_book_credit_save( $col, $kind, $cr[ $kind ] );
								}
							}
						}
						return rest_ensure_response( rrt_book_state( $col ) );
					},
				),
			)
		);
	}
);

/*
 * ---------------------------------------------------------------------
 * The Audiobook box on a collection's screen
 * ---------------------------------------------------------------------
 */

add_action(
	'add_meta_boxes_' . RRT_COL_TYPE,
	function ( $post ) {
		add_meta_box( 'rrt-audiobook', __( 'Audiobook', 'foxfire-studio' ), 'rrt_book_box', RRT_COL_TYPE, 'normal', 'default' );
	}
);

/** The box; audiobook.js fills it in once the collection has been saved. */
function rrt_book_box( $post ) {
	if ( 'auto-draft' === $post->post_status ) {
		echo '<p class="rrt-small">' . esc_html__( 'Save the collection first. Its readings can then be made into an audiobook: one file for each reading, the opening and closing credits, a retail sample and a check against the rules of Audible (ACX), Spotify for Authors and the other stores.', 'foxfire-studio' ) . '</p>';
		return;
	}
	$cfg  = rrt_config();
	$boot = array(
		'rest'    => esc_url_raw( rest_url( 'rrt/v1/' ) ),
		'nonce'   => wp_create_nonce( 'wp_rest' ),
		'state'   => rrt_book_state( $post->ID ),
		'encoder' => array(
			'worker'   => rrt_js_url( 'mp3.js' ),
			'url'      => $cfg['mp3_url'],
			'fallback' => $cfg['mp3_url'] !== $cfg['mp3_cdn'] ? $cfg['mp3_cdn'] : '',
			'sha256'   => RRT_MP3_SHA256,
		),
	);
	echo '<p class="rrt-small">' . esc_html__( 'One file for each reading, the opening and closing credits, a retail sample and the cover, checked against the rules of Audible (ACX), which Spotify for Authors and the other stores also accept, and downloaded as one ZIP file. Everything is made in this browser; nothing of the ZIP is kept on the site.', 'foxfire-studio' ) . '</p>';
	echo '<div class="rrt-book-app" data-rrt-book><p class="rrt-small">' . esc_html__( 'Loading...', 'foxfire-studio' ) . '</p></div>';
	echo '<script type="application/json" id="rrt-book-boot">' . wp_json_encode( $boot, JSON_HEX_TAG | JSON_HEX_AMP ) . '</script>';
}

add_action(
	'admin_enqueue_scripts',
	function ( $hook ) {
		$s = get_current_screen();
		if ( ! $s || RRT_COL_TYPE !== $s->post_type || 'post.php' !== $hook ) {
			return;
		}
		$ver = function ( $rel ) {
			return RRT_VERSION . '.' . filemtime( RRT_DIR . $rel );
		};
		wp_enqueue_script( 'rrt-id3', RRT_URL . 'assets/js/id3.js', array(), $ver( 'assets/js/id3.js' ), true );
		wp_enqueue_script( 'rrt-audiobook', RRT_URL . 'assets/js/audiobook.js', array( 'rrt-sound', 'rrt-id3', 'wp-i18n' ), $ver( 'assets/js/audiobook.js' ), true );
		wp_set_script_translations( 'rrt-audiobook', 'foxfire-studio', rrt_languages_dir() );
	},
	20
);

/*
 * ---------------------------------------------------------------------
 * The studio and the reading's page
 * ---------------------------------------------------------------------
 */

/** What the studio needs for a post: whether to keep a copy with Use this recording, whether the post is credits, and the copy it has. */
function rrt_book_boot( $post_id ) {
	$post_id = (int) $post_id;
	$credit  = $post_id && rrt_book_is_credit( $post_id );
	return array(
		'keep'   => ! empty( rrt_settings()['sound']['book'] ),
		'credit' => $credit,
		'col'    => $credit ? (int) wp_get_post_parent_id( $post_id ) : 0,
		'colEdit' => $credit ? (string) get_edit_post_link( wp_get_post_parent_id( $post_id ), 'raw' ) : '',
		'can'    => $post_id ? rrt_book_can( $post_id ) : false,
		'have'   => $post_id ? rrt_book_public( $post_id ) : null,
		'head'   => RRT_BOOK_HEAD,
		'tail'   => RRT_BOOK_TAIL,
	);
}

/** The audiobook copy's line on a reading's page under Foxfire Studio, Readings. */
function rrt_book_reading_section( $post_id ) {
	$b = rrt_book_public( $post_id );
	if ( ! $b ) {
		return;
	}
	echo '<p class="rrt-small rrt-book-line">';
	/* translators: 1: length such as 14:20, 2: date and time. */
	echo esc_html( sprintf( __( 'Audiobook copy: %1$s, made %2$s.', 'foxfire-studio' ), rrt_clock( (int) round( $b['secs'] ) ), $b['at'] ) ) . ' ';
	echo '<audio controls preload="none" src="' . esc_url( $b['url'] ) . '"></audio> ';
	echo '<a href="' . esc_url( rrt_book_audio_url( $post_id, true ) ) . '">' . esc_html__( 'Download', 'foxfire-studio' ) . '</a>';
	echo '</p>';
}
