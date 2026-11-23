<?php
/**
 * YouTube and SoundCloud players that load on play (1.35.0).
 *
 * With Settings, Embedded players switched on for a service, every player
 * from it in post and page content and in block widgets is shown as a panel
 * instead: a picture of the video or track, kept on this site, with a play
 * button, at the size the player will take. Until a visitor presses play
 * their browser contacts neither service. One press loads the real player in
 * the panel's place and starts it (YouTube's from its privacy-enhanced
 * address). Without JavaScript the panel is a plain link to the video or
 * track.
 *
 * Nothing in the posts changes: the panels are made as the page is shown,
 * so switching off brings the ordinary players straight back. The editor,
 * feeds and the REST API always get the original players.
 *
 * The site asks YouTube or SoundCloud once for each video's or track's title
 * and picture, the way WordPress asks for embed code, and keeps the picture
 * in uploads/rrt-embeds (public on purpose: visitors see it). The list is
 * the option rrt_embeds_list. Pictures not shown for 90 days are removed by
 * the hourly job. Both switches are off by default.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Days a picture is kept after it was last shown. */
define( 'RRT_EMBEDS_DAYS', 90 );

/** Seconds before a failed look-up is tried again. */
define( 'RRT_EMBEDS_RETRY', DAY_IN_SECONDS );

/** Look-ups one page view may make while it is being shown. */
define( 'RRT_EMBEDS_FETCH_MAX', 4 );

/** The largest picture fetched, in bytes. */
define( 'RRT_EMBEDS_PIC_MAX', 4 * 1048576 );

/** The widest picture kept, in pixels. */
define( 'RRT_EMBEDS_PIC_W', 640 );

/** YouTube's privacy-enhanced player address. */
define( 'RRT_EMBEDS_YT_PLAYER', 'https://www.youtube-nocookie.com/embed/' );

/** SoundCloud's player address. */
define( 'RRT_EMBEDS_SC_PLAYER', 'https://w.soundcloud.com/player/' );

/** The settings: which services load on play. Both off by default. */
function rrt_embeds_settings() {
	$s = get_option( 'rrt_embeds', array() );
	$s = is_array( $s ) ? $s : array();
	return array(
		'yt' => ! empty( $s['yt'] ),
		'sc' => ! empty( $s['sc'] ),
	);
}

/** Whether a service ('yt' or 'sc') loads on play. */
function rrt_embeds_on( $service ) {
	$s = rrt_embeds_settings();
	return ! empty( $s[ $service ] );
}

/** Whether either service loads on play. */
function rrt_embeds_any_on() {
	$s = rrt_embeds_settings();
	return $s['yt'] || $s['sc'];
}

/** The pictures' folder, with a trailing slash. */
function rrt_embeds_dir() {
	$up = wp_upload_dir( null, false );
	return trailingslashit( $up['basedir'] ) . 'rrt-embeds/';
}

/** The pictures' folder's address, with a trailing slash. */
function rrt_embeds_url() {
	$up = wp_upload_dir( null, false );
	return trailingslashit( set_url_scheme( $up['baseurl'] ) ) . 'rrt-embeds/';
}

/** Makes the folder, with an empty index so it cannot be listed. */
function rrt_embeds_dir_ready() {
	$dir = rrt_embeds_dir();
	if ( ! wp_mkdir_p( $dir ) ) {
		return false;
	}
	if ( ! file_exists( $dir . 'index.php' ) ) {
		file_put_contents( $dir . 'index.php', "<?php\n// Silence.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
	return true;
}

/** The saved list: key => array( t title, f file, w, h, at fetched, seen, fail ). */
function rrt_embeds_list() {
	$l = get_option( 'rrt_embeds_list', array() );
	return is_array( $l ) ? $l : array();
}

/** Saves the list. */
function rrt_embeds_list_save( $list ) {
	if ( $list ) {
		update_option( 'rrt_embeds_list', $list, false );
	} else {
		delete_option( 'rrt_embeds_list' );
	}
}

/** A key as the list keeps it: yt- or sc- and safe letters, or ''. */
function rrt_embeds_clean_key( $key ) {
	$key = (string) $key;
	return preg_match( '/^(yt|sc)-[a-z0-9_-]{2,70}$/', $key ) ? $key : '';
}

/** Whether this view of a page gets panels: the public site only, never feeds, REST, the editor or the studio. */
function rrt_embeds_context_ok() {
	if ( is_admin() && ! wp_doing_ajax() ) {
		return false;
	}
	if ( is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
		return false;
	}
	if ( function_exists( 'is_embed' ) && is_embed() ) {
		return false;
	}
	if ( ! empty( $_GET['teleprompter'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return false;
	}
	return (bool) apply_filters( 'rrt_embeds_context_ok', true );
}

/**
 * A YouTube start time in seconds from a link's t or start ('90', '90s', '1m30s', '1h2m3s'), or 0.
 *
 * @param string $t The value.
 */
function rrt_embeds_yt_time( $t ) {
	$t = strtolower( trim( (string) $t ) );
	if ( preg_match( '/^\d+$/', $t ) ) {
		return (int) $t;
	}
	if ( preg_match( '/^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/', $t, $m ) && '' !== $t ) {
		return (int) ( $m[1] ?? 0 ) * 3600 + (int) ( $m[2] ?? 0 ) * 60 + (int) ( $m[3] ?? 0 );
	}
	return 0;
}

/**
 * What a YouTube address shows: array( id, list, start, short ), or null.
 * Takes watch, youtu.be, shorts, live, embed (either host) and playlist links.
 *
 * @param string $url The address.
 */
function rrt_embeds_yt_parse( $url ) {
	$url  = html_entity_decode( (string) $url, ENT_QUOTES );
	$p    = wp_parse_url( $url );
	$host = strtolower( (string) ( $p['host'] ?? '' ) );
	$host = preg_replace( '/^(www\.|m\.|music\.)/', '', $host );
	if ( ! in_array( $host, array( 'youtube.com', 'youtu.be', 'youtube-nocookie.com' ), true ) ) {
		return null;
	}
	$path = (string) ( $p['path'] ?? '' );
	$q    = array();
	if ( ! empty( $p['query'] ) ) {
		wp_parse_str( $p['query'], $q );
	}
	$id    = '';
	$short = false;
	if ( 'youtu.be' === $host ) {
		$id = trim( $path, '/' );
	} elseif ( preg_match( '#^/(?:embed|shorts|live|v)/([^/?]+)#', $path, $m ) ) {
		$id    = $m[1];
		$short = 0 === strpos( $path, '/shorts/' );
	} elseif ( '/watch' === rtrim( $path, '/' ) ) {
		$id = (string) ( $q['v'] ?? '' );
	} elseif ( '/playlist' !== rtrim( $path, '/' ) ) {
		return null;
	}
	$list = isset( $q['list'] ) && preg_match( '/^[A-Za-z0-9_-]{2,64}$/', (string) $q['list'] ) ? (string) $q['list'] : '';
	if ( 'videoseries' === $id ) {
		$id = '';
	}
	if ( '' !== $id && ! preg_match( '/^[A-Za-z0-9_-]{11}$/', $id ) ) {
		return null;
	}
	if ( '' === $id && '' === $list ) {
		return null;
	}
	$start = 0;
	foreach ( array( 'start', 't' ) as $k ) {
		if ( isset( $q[ $k ] ) && ! $start ) {
			$start = rrt_embeds_yt_time( $q[ $k ] );
		}
	}
	if ( ! $start && ! empty( $p['fragment'] ) && preg_match( '/^t=(.+)$/', (string) $p['fragment'], $m ) ) {
		$start = rrt_embeds_yt_time( $m[1] );
	}
	return array(
		'id'    => $id,
		'list'  => $list,
		'start' => $start,
		'short' => $short,
		'q'     => $q,
	);
}

/**
 * What a SoundCloud player or page address shows: array( url, playlist, visual, q ), or null.
 *
 * @param string $src A w.soundcloud.com player address, or a soundcloud.com page.
 */
function rrt_embeds_sc_parse( $src ) {
	$src  = html_entity_decode( (string) $src, ENT_QUOTES );
	$p    = wp_parse_url( $src );
	$host = strtolower( (string) ( $p['host'] ?? '' ) );
	$q    = array();
	if ( ! empty( $p['query'] ) ) {
		wp_parse_str( $p['query'], $q );
	}
	if ( 'w.soundcloud.com' === $host && 0 === strpos( (string) ( $p['path'] ?? '' ), '/player' ) ) {
		$url = (string) ( $q['url'] ?? '' );
	} elseif ( in_array( $host, array( 'soundcloud.com', 'www.soundcloud.com', 'm.soundcloud.com', 'on.soundcloud.com' ), true ) ) {
		$url = 'https://soundcloud.com' . (string) ( $p['path'] ?? '' );
		$q   = array();
	} else {
		return null;
	}
	$u = wp_parse_url( $url );
	$h = strtolower( (string) ( $u['host'] ?? '' ) );
	if ( ! in_array( $h, array( 'api.soundcloud.com', 'soundcloud.com', 'www.soundcloud.com' ), true ) || empty( $u['path'] ) || '/' === $u['path'] ) {
		return null;
	}
	$url = 'https://' . $h . $u['path'];
	return array(
		'url'      => $url,
		'playlist' => (bool) preg_match( '#/(playlists|sets)/#', $url ),
		'visual'   => isset( $q['visual'] ) && 'true' === strtolower( (string) $q['visual'] ),
		'q'        => $q,
	);
}

/** The list key for a YouTube video or playlist. */
function rrt_embeds_yt_key( $yt ) {
	return '' !== $yt['id'] ? 'yt-' . strtolower( preg_replace( '/[^A-Za-z0-9_-]/', '', $yt['id'] ) ) . '-' . substr( md5( $yt['id'] ), 0, 6 ) : 'yt-pl-' . substr( md5( $yt['list'] ), 0, 16 );
}

/** The list key for a SoundCloud track or playlist. */
function rrt_embeds_sc_key( $sc ) {
	return 'sc-' . substr( md5( strtolower( $sc['url'] ) ), 0, 16 );
}

/**
 * The YouTube player address loaded on play: privacy-enhanced, started at once,
 * with the settings of the original player that still apply.
 *
 * @param array $yt  From rrt_embeds_yt_parse().
 * @param array $src The original player's query.
 */
function rrt_embeds_yt_player_url( $yt, $src = array() ) {
	$keep = array( 'start', 'end', 'list', 'listType', 'index', 'playlist', 'loop', 'controls', 'cc_load_policy', 'cc_lang_pref', 'hl', 'rel', 'color', 'iv_load_policy', 'mute' );
	$args = array();
	foreach ( $keep as $k ) {
		if ( isset( $src[ $k ] ) && is_scalar( $src[ $k ] ) && preg_match( '/^[A-Za-z0-9_,.-]{1,80}$/', (string) $src[ $k ] ) ) {
			$args[ $k ] = (string) $src[ $k ];
		}
	}
	if ( $yt['start'] && ! isset( $args['start'] ) ) {
		$args['start'] = (string) (int) $yt['start'];
	}
	if ( '' !== $yt['list'] && ! isset( $args['list'] ) ) {
		$args['list'] = $yt['list'];
	}
	$home                = wp_parse_url( home_url() );
	$args['autoplay']    = '1';
	$args['playsinline'] = '1';
	$args['enablejsapi'] = '1';
	$args['origin']      = ( $home['scheme'] ?? 'https' ) . '://' . ( $home['host'] ?? '' ) . ( isset( $home['port'] ) ? ':' . (int) $home['port'] : '' );
	$path                = '' !== $yt['id'] ? $yt['id'] : 'videoseries';
	return RRT_EMBEDS_YT_PLAYER . rawurlencode( $path ) . '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
}

/** The address of a YouTube video or playlist on YouTube itself, for the link without JavaScript. */
function rrt_embeds_yt_page_url( $yt ) {
	if ( '' === $yt['id'] ) {
		return 'https://www.youtube.com/playlist?list=' . rawurlencode( $yt['list'] );
	}
	if ( $yt['short'] ) {
		return 'https://www.youtube.com/shorts/' . rawurlencode( $yt['id'] );
	}
	$u = 'https://www.youtube.com/watch?v=' . rawurlencode( $yt['id'] );
	if ( '' !== $yt['list'] ) {
		$u .= '&list=' . rawurlencode( $yt['list'] );
	}
	if ( $yt['start'] ) {
		$u .= '&t=' . (int) $yt['start'] . 's';
	}
	return $u;
}

/** The SoundCloud player address loaded on play, with the original player's settings and auto_play on. */
function rrt_embeds_sc_player_url( $sc ) {
	$args = array();
	foreach ( array( 'color', 'hide_related', 'show_comments', 'show_user', 'show_reposts', 'show_teaser', 'visual', 'show_artwork', 'show_playcount', 'sharing', 'buying', 'download', 'start_track', 'single_active', 'secret_token' ) as $k ) {
		if ( isset( $sc['q'][ $k ] ) && is_scalar( $sc['q'][ $k ] ) && preg_match( '/^[A-Za-z0-9_%#-]{1,80}$/', (string) $sc['q'][ $k ] ) ) {
			$args[ $k ] = (string) $sc['q'][ $k ];
		}
	}
	$args['auto_play'] = 'true';
	return RRT_EMBEDS_SC_PLAYER . '?url=' . rawurlencode( $sc['url'] ) . '&' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
}

/** The SoundCloud address for the link without JavaScript: the page, or the player on its own. */
function rrt_embeds_sc_page_url( $sc ) {
	if ( 0 === strpos( $sc['url'], 'https://soundcloud.com/' ) || 0 === strpos( $sc['url'], 'https://www.soundcloud.com/' ) ) {
		return $sc['url'];
	}
	return RRT_EMBEDS_SC_PLAYER . '?url=' . rawurlencode( $sc['url'] );
}

/**
 * Asks the service once for a video's or track's title and picture, and keeps
 * the picture in the folder. Returns the list entry (which may say it failed).
 *
 * @param string $key     The list key.
 * @param string $oembed  The service's oEmbed address for it.
 * @param string $picture A picture address to try when the answer has none.
 */
function rrt_embeds_fetch( $key, $oembed, $picture = '' ) {
	$entry = array(
		't'    => '',
		'f'    => '',
		'w'    => 0,
		'h'    => 0,
		'at'   => time(),
		'seen' => time(),
		'fail' => 0,
	);
	$r     = wp_safe_remote_get(
		$oembed,
		array(
			'timeout'     => 6,
			'redirection' => 2,
		)
	);
	$data  = array();
	if ( ! is_wp_error( $r ) && 200 === (int) wp_remote_retrieve_response_code( $r ) ) {
		$data = json_decode( (string) wp_remote_retrieve_body( $r ), true );
		$data = is_array( $data ) ? $data : array();
	}
	if ( isset( $data['title'] ) && is_string( $data['title'] ) ) {
		$entry['t'] = wp_strip_all_tags( html_entity_decode( $data['title'], ENT_QUOTES ) );
		$entry['t'] = function_exists( 'mb_substr' ) ? mb_substr( $entry['t'], 0, 200 ) : substr( $entry['t'], 0, 200 );
	}
	$pic = isset( $data['thumbnail_url'] ) && is_string( $data['thumbnail_url'] ) ? $data['thumbnail_url'] : $picture;
	if ( '' !== $pic && preg_match( '#^https://#', $pic ) ) {
		$saved = rrt_embeds_save_picture( $key, $pic );
		if ( $saved ) {
			$entry = array_merge( $entry, $saved );
		}
	}
	if ( '' === $entry['t'] && '' === $entry['f'] ) {
		$entry['fail'] = time();
	}
	return $entry;
}

/**
 * Downloads a picture and keeps a small copy: WebP where the server can make
 * one, otherwise JPEG; the original bytes when the server cannot resize.
 * Returns array( f, w, h ), or null.
 *
 * @param string $key The list key (the file's name starts with it).
 * @param string $url The picture's address.
 */
function rrt_embeds_save_picture( $key, $url ) {
	if ( ! rrt_embeds_clean_key( $key ) || ! rrt_embeds_dir_ready() ) {
		return null;
	}
	$r = wp_safe_remote_get(
		$url,
		array(
			'timeout'             => 8,
			'redirection'         => 2,
			'limit_response_size' => RRT_EMBEDS_PIC_MAX,
		)
	);
	if ( is_wp_error( $r ) || 200 !== (int) wp_remote_retrieve_response_code( $r ) ) {
		return null;
	}
	$bytes = (string) wp_remote_retrieve_body( $r );
	$ext   = rrt_embeds_image_ext( $bytes );
	if ( '' === $ext || strlen( $bytes ) >= RRT_EMBEDS_PIC_MAX ) {
		return null;
	}
	$dir  = rrt_embeds_dir();
	$base = $key . '-' . strtolower( wp_generate_password( 6, false, false ) );
	$tmp  = $dir . $base . '-in.' . $ext;
	file_put_contents( $tmp, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$out  = null;
	$size = @getimagesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	if ( ! $size || $size[0] < 1 || $size[1] < 1 ) {
		wp_delete_file( $tmp );
		return null;
	}
	if ( function_exists( 'wp_get_image_editor' ) ) {
		$ed = wp_get_image_editor( $tmp );
		if ( ! is_wp_error( $ed ) ) {
			if ( $size[0] > RRT_EMBEDS_PIC_W ) {
				$ed->resize( RRT_EMBEDS_PIC_W, null, false );
			}
			$ed->set_quality( 78 );
			$mime = wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ? 'image/webp' : 'image/jpeg';
			$file = $dir . $base . ( 'image/webp' === $mime ? '.webp' : '.jpg' );
			$res  = $ed->save( $file, $mime );
			if ( ! is_wp_error( $res ) && ! empty( $res['path'] ) && file_exists( $res['path'] ) ) {
				$out = array(
					'f' => basename( $res['path'] ),
					'w' => (int) $res['width'],
					'h' => (int) $res['height'],
				);
			}
		}
	}
	if ( ! $out ) {
		$file = $dir . $base . '.' . $ext;
		if ( @rename( $tmp, $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			$out = array(
				'f' => basename( $file ),
				'w' => (int) $size[0],
				'h' => (int) $size[1],
			);
		}
	}
	if ( file_exists( $tmp ) ) {
		wp_delete_file( $tmp );
	}
	return $out;
}

/** A picture's file extension from its first bytes (JPEG, PNG or WebP), or ''. */
function rrt_embeds_image_ext( $bytes ) {
	if ( 0 === strncmp( $bytes, "\xFF\xD8\xFF", 3 ) ) {
		return 'jpg';
	}
	if ( 0 === strncmp( $bytes, "\x89PNG\r\n\x1A\n", 8 ) ) {
		return 'png';
	}
	if ( 0 === strncmp( $bytes, 'RIFF', 4 ) && 'WEBP' === substr( $bytes, 8, 4 ) ) {
		return 'webp';
	}
	return '';
}

/**
 * A video's or track's saved title and picture, fetched now if they are not
 * yet saved (a few a page view; the rest wait for the next view).
 *
 * @param string $key     The list key.
 * @param string $oembed  The oEmbed address.
 * @param string $picture A picture address to try when the answer has none.
 * @param bool   $fetch   Whether a look-up may happen now.
 */
function rrt_embeds_info( $key, $oembed, $picture = '', $fetch = true ) {
	static $fetched = 0;
	$list  = rrt_embeds_list();
	$entry = isset( $list[ $key ] ) && is_array( $list[ $key ] ) ? $list[ $key ] : null;
	if ( $entry && ! empty( $entry['f'] ) && ! file_exists( rrt_embeds_dir() . $entry['f'] ) ) {
		$entry = null;
	}
	$retry = $entry && ! empty( $entry['fail'] ) && ( time() - (int) $entry['fail'] ) > RRT_EMBEDS_RETRY;
	if ( ( ! $entry || $retry ) && $fetch && $fetched < (int) apply_filters( 'rrt_embeds_fetch_max', RRT_EMBEDS_FETCH_MAX ) ) {
		++$fetched;
		$entry        = rrt_embeds_fetch( $key, $oembed, $picture );
		$list         = rrt_embeds_list();
		$list[ $key ] = $entry;
		rrt_embeds_list_save( $list );
		return $entry;
	}
	if ( $entry && ( time() - (int) ( $entry['seen'] ?? 0 ) ) > DAY_IN_SECONDS ) {
		$entry['seen'] = time();
		$list[ $key ]  = $entry;
		rrt_embeds_list_save( $list );
	}
	return $entry ? $entry : array(
		't' => '',
		'f' => '',
		'w' => 0,
		'h' => 0,
	);
}

/**
 * Notes the address a WordPress embed was made from on its player, so a
 * panel knows a Short, a start time or a SoundCloud page.
 *
 * @param string $html The embed code.
 * @param string $url  The address it was made from.
 */
function rrt_embeds_mark_oembed( $html, $url ) {
	if ( ! is_string( $html ) || false === stripos( $html, '<iframe' ) || ! rrt_embeds_any_on() ) {
		return $html;
	}
	if ( ! rrt_embeds_yt_parse( $url ) && ! rrt_embeds_sc_parse( $url ) ) {
		return $html;
	}
	return preg_replace( '/<iframe\b/i', '<iframe data-rrt-from="' . esc_attr( $url ) . '"', $html, 1 );
}
add_filter(
	'embed_oembed_html',
	function ( $html, $url ) {
		return rrt_embeds_mark_oembed( $html, $url );
	},
	20,
	2
);

/**
 * Turns every YouTube and SoundCloud player in a piece of HTML into a panel,
 * for the services switched on. Everything else is left exactly as it was.
 *
 * @param string $html The HTML.
 */
function rrt_embeds_filter_html( $html ) {
	if ( ! is_string( $html ) || false === stripos( $html, '<iframe' ) || ! rrt_embeds_any_on() || ! rrt_embeds_context_ok() ) {
		return $html;
	}
	if ( false === stripos( $html, 'youtu' ) && false === stripos( $html, 'soundcloud' ) ) {
		return $html;
	}
	return (string) preg_replace_callback(
		'#<iframe\b([^>]*)>.*?</iframe>#is',
		function ( $m ) {
			$panel = rrt_embeds_panel_for( $m[1] );
			return null === $panel ? $m[0] : $panel;
		},
		$html
	);
}

/**
 * A panel for one player's attributes, or null to leave the player as it is.
 *
 * @param string $attrs The iframe's attribute text.
 */
function rrt_embeds_panel_for( $attrs ) {
	$a = array();
	foreach ( wp_kses_hair( $attrs, array( 'http', 'https' ) ) as $name => $v ) {
		$a[ strtolower( $name ) ] = (string) $v['value'];
	}
	$src = $a['src'] ?? ( $a['data-src'] ?? '' );
	if ( '' === $src ) {
		return null;
	}
	if ( 0 === strpos( $src, '//' ) ) {
		$src = 'https:' . $src;
	}
	$from = $a['data-rrt-from'] ?? '';
	$host = strtolower( (string) wp_parse_url( html_entity_decode( $src, ENT_QUOTES ), PHP_URL_HOST ) );
	if ( preg_match( '/(^|\.)(youtube\.com|youtube-nocookie\.com)$/', $host ) ) {
		if ( ! rrt_embeds_on( 'yt' ) || ! preg_match( '#/embed/#', $src ) ) {
			return null;
		}
		$yt = rrt_embeds_yt_parse( $src );
		if ( ! $yt ) {
			return null;
		}
		$orig = $from ? rrt_embeds_yt_parse( $from ) : null;
		if ( $orig ) {
			$yt['short'] = $orig['short'];
			$yt['start'] = $yt['start'] ? $yt['start'] : $orig['start'];
			$yt['list']  = '' !== $yt['list'] ? $yt['list'] : $orig['list'];
		}
		return rrt_embeds_panel_yt( $yt, $a );
	}
	if ( 'w.soundcloud.com' === $host ) {
		if ( ! rrt_embeds_on( 'sc' ) ) {
			return null;
		}
		$sc = rrt_embeds_sc_parse( $src );
		if ( ! $sc ) {
			return null;
		}
		$page = $from ? rrt_embeds_sc_parse( $from ) : null;
		if ( $page ) {
			$sc['page'] = $page['url'];
		}
		return rrt_embeds_panel_sc( $sc, $a );
	}
	return null;
}

/** A length from an iframe's width or height: array( number, unit ) with unit 'px' or '%', or null. */
function rrt_embeds_len( $v ) {
	$v = trim( (string) $v );
	if ( preg_match( '/^(\d{1,4}(?:\.\d+)?)\s*(px)?$/i', $v, $m ) && (float) $m[1] > 0 ) {
		return array( (float) $m[1], 'px' );
	}
	if ( preg_match( '/^(\d{1,3}(?:\.\d+)?)\s*%$/', $v, $m ) && (float) $m[1] > 0 ) {
		return array( min( 100, (float) $m[1] ), '%' );
	}
	return null;
}

/** A YouTube panel. */
function rrt_embeds_panel_yt( $yt, $a ) {
	$key  = rrt_embeds_yt_key( $yt );
	$q    = '' !== $yt['id'] ? 'https://www.youtube.com/watch?v=' . rawurlencode( $yt['id'] ) : 'https://www.youtube.com/playlist?list=' . rawurlencode( $yt['list'] );
	$info = rrt_embeds_info( $key, 'https://www.youtube.com/oembed?format=json&url=' . rawurlencode( $q ), '' !== $yt['id'] ? 'https://i.ytimg.com/vi/' . rawurlencode( $yt['id'] ) . '/hqdefault.jpg' : '' );
	$w    = rrt_embeds_len( $a['width'] ?? '' );
	$h    = rrt_embeds_len( $a['height'] ?? '' );
	$tall = $yt['short'] || ( $w && $h && 'px' === $w[1] && 'px' === $h[1] && $h[0] > $w[0] );
	$ar   = $tall ? 0.5625 : 1.7778;
	if ( $w && $h && 'px' === $w[1] && 'px' === $h[1] && ! $yt['short'] ) {
		$ar = round( $w[0] / $h[0], 4 );
	}
	$style = 'aspect-ratio:' . $ar . ';';
	if ( $yt['short'] ) {
		/* A Short is shown 9 by 16 at YouTube's own width, whatever size its embed code asks for. */
		$style .= 'width:315px;';
	} elseif ( $w && '%' === $w[1] ) {
		$style .= 'width:' . $w[0] . '%;';
	} elseif ( $w ) {
		$style .= 'width:' . $w[0] . 'px;';
	} else {
		$style .= 'width:' . ( $tall ? 315 : 560 ) . 'px;';
	}
	$title = '' !== $info['t'] ? $info['t'] : ( isset( $a['title'] ) && '' !== trim( $a['title'] ) ? html_entity_decode( $a['title'], ENT_QUOTES ) : ( '' !== $yt['id'] ? __( 'YouTube video', 'foxfire-studio' ) : __( 'YouTube playlist', 'foxfire-studio' ) ) );
	return rrt_embeds_panel_html(
		array(
			'service' => 'yt',
			'key'     => $key,
			'kind'    => $tall ? 'tall' : 'wide',
			'style'   => $style,
			'play'    => rrt_embeds_yt_player_url( $yt, $yt['q'] ),
			'link'    => rrt_embeds_yt_page_url( $yt ),
			'title'   => $title,
			'info'    => $info,
			'allow'   => 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share',
			'full'    => true,
		)
	);
}

/** A SoundCloud panel. */
function rrt_embeds_panel_sc( $sc, $a ) {
	$key   = rrt_embeds_sc_key( $sc );
	$info  = rrt_embeds_info( $key, 'https://soundcloud.com/oembed?format=json&url=' . rawurlencode( $sc['page'] ?? $sc['url'] ) );
	$w     = rrt_embeds_len( $a['width'] ?? '' );
	$h     = rrt_embeds_len( $a['height'] ?? '' );
	$px    = $h && 'px' === $h[1] ? (int) round( $h[0] ) : ( $sc['playlist'] || $sc['visual'] ? 450 : 166 );
	$px    = max( 60, min( 1200, $px ) );
	$style = 'height:' . $px . 'px;';
	$style .= $w ? 'width:' . $w[0] . $w[1] . ';' : 'width:100%;';
	$title = '' !== $info['t'] ? $info['t'] : ( isset( $a['title'] ) && '' !== trim( $a['title'] ) ? html_entity_decode( $a['title'], ENT_QUOTES ) : ( $sc['playlist'] ? __( 'SoundCloud playlist', 'foxfire-studio' ) : __( 'SoundCloud track', 'foxfire-studio' ) ) );
	if ( isset( $sc['page'] ) ) {
		$sc_link        = $sc;
		$sc_link['url'] = $sc['page'];
		$link           = rrt_embeds_sc_page_url( $sc_link );
	} else {
		$link = rrt_embeds_sc_page_url( $sc );
	}
	return rrt_embeds_panel_html(
		array(
			'service' => 'sc',
			'key'     => $key,
			'kind'    => $px >= 300 ? 'tall' : 'wide',
			'style'   => $style,
			'play'    => rrt_embeds_sc_player_url( $sc ),
			'link'    => $link,
			'title'   => $title,
			'info'    => $info,
			'allow'   => 'autoplay',
			'full'    => false,
		)
	);
}

/** A panel's HTML: a link to the video or track that embeds.js turns into a play button. */
function rrt_embeds_panel_html( $p ) {
	rrt_embeds_enqueue();
	$yt    = 'yt' === $p['service'];
	$where = $yt ? __( 'Plays from YouTube', 'foxfire-studio' ) : __( 'Plays from SoundCloud', 'foxfire-studio' );
	$pic  = '';
	if ( ! empty( $p['info']['f'] ) ) {
		$pic = '<img class="rrt-embed-pic" src="' . esc_url( rrt_embeds_url() . $p['info']['f'] ) . '" alt="" loading="lazy" decoding="async"' . ( ! empty( $p['info']['w'] ) ? ' width="' . (int) $p['info']['w'] . '" height="' . (int) $p['info']['h'] . '"' : '' ) . '>';
	}
	$html  = '<div class="rrt-embed rrt-embed-' . esc_attr( $p['service'] ) . ' is-' . esc_attr( $p['kind'] ) . ( '' === $pic ? ' is-nopic' : '' ) . '"';
	$html .= ' data-rrt-embed="' . esc_attr( $p['service'] ) . '" data-rrt-key="' . esc_attr( $p['key'] ) . '"';
	$html .= ' data-rrt-play="' . esc_attr( $p['play'] ) . '" data-rrt-title="' . esc_attr( $p['title'] ) . '"';
	$html .= ' data-rrt-allow="' . esc_attr( $p['allow'] ) . '"' . ( $p['full'] ? ' data-rrt-full="1"' : '' );
	$html .= ' style="' . esc_attr( $p['style'] ) . '">';
	/* The button's name is its own words, "Play" first, so what is seen is what is said (WCAG 2.5.3). */
	$html .= '<a class="rrt-embed-go" href="' . esc_url( $p['link'] ) . '">';
	$html .= $pic;
	$html .= '<span class="rrt-embed-btn" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M8 5v14l11-7z"/></svg></span>';
	$html .= '<span class="rrt-embed-cap"><span class="rrt-embed-title"><span class="rrt-embed-sr">' . esc_html__( 'Play', 'foxfire-studio' ) . ' </span>' . esc_html( $p['title'] ) . '<span class="rrt-embed-sr">,</span></span> <span class="rrt-embed-from">' . esc_html( $where ) . '</span></span>';
	$html .= '</a></div>';
	return $html;
}

/** Loads the panels' styles, with the player's colours, once (the player's own stylesheet). */
function rrt_embeds_style() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	if ( wp_style_is( 'rrt-player', 'enqueued' ) || wp_style_is( 'rrt-player', 'done' ) ) {
		return;
	}
	wp_enqueue_style( 'rrt-player', RRT_URL . 'assets/css/player.css', array(), RRT_VERSION . '.' . filemtime( RRT_DIR . 'assets/css/player.css' ) );
	wp_add_inline_style( 'rrt-player', rrt_player_tokens_css( rrt_config() ) );
}

/** Loads the panels' styles and script, once. */
function rrt_embeds_enqueue() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	rrt_embeds_style();
	wp_enqueue_script( 'rrt-embeds', RRT_URL . 'assets/js/embeds.js', array(), RRT_VERSION . '.' . filemtime( RRT_DIR . 'assets/js/embeds.js' ), true );
}

/* Panels in post and page content, after WordPress has made every embed (and shortcodes and blocks have run). */
add_filter( 'the_content', 'rrt_embeds_filter_html', 99 );

/* Panels in block widgets, which are made the same way. */
add_filter( 'widget_block_content', 'rrt_embeds_filter_html', 99 );

/* On a single post or page that has a player, the styles go in the head, so the panel is drawn right the first time. */
add_action(
	'wp_enqueue_scripts',
	function () {
		if ( ! rrt_embeds_any_on() || ! is_singular() ) {
			return;
		}
		$post = get_queried_object();
		if ( $post instanceof WP_Post && ( false !== stripos( $post->post_content, 'youtu' ) || false !== stripos( $post->post_content, 'soundcloud' ) ) ) {
			rrt_embeds_style();
		}
	},
	20
);

/**
 * When a post is saved, fetch its pictures then, so its first visitor does not wait.
 * Runs after the save, as the next page view would.
 */
add_action(
	'save_post',
	function ( $post_id, $post ) {
		if ( ! rrt_embeds_any_on() || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! in_array( $post->post_status, array( 'publish', 'draft', 'private', 'future' ), true ) ) {
			return;
		}
		if ( false === stripos( $post->post_content, 'youtu' ) && false === stripos( $post->post_content, 'soundcloud' ) ) {
			return;
		}
		if ( ! wp_next_scheduled( 'rrt_embeds_prefetch', array( (int) $post_id ) ) ) {
			wp_schedule_single_event( time() + 5, 'rrt_embeds_prefetch', array( (int) $post_id ) );
		}
	},
	20,
	2
);

/** Makes a post's panels once, so their titles and pictures are fetched and saved. */
function rrt_embeds_prefetch( $post_id ) {
	$post = get_post( (int) $post_id );
	if ( ! $post ) {
		return 0;
	}
	add_filter( 'rrt_embeds_context_ok', '__return_true', 99 );
	$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
	setup_postdata( $post );
	$html = rrt_embeds_render_post( $post );
	wp_reset_postdata();
	remove_filter( 'rrt_embeds_context_ok', '__return_true', 99 );
	return substr_count( $html, 'class="rrt-embed ' );
}
add_action( 'rrt_embeds_prefetch', 'rrt_embeds_prefetch' );

/** A post's content as the public page would show it, for the prefetch. */
function rrt_embeds_render_post( $post ) {
	global $wp_embed;
	$c = (string) $post->post_content;
	if ( $wp_embed ) {
		$wp_embed->post_ID = (int) $post->ID;
		$c                 = $wp_embed->run_shortcode( $c );
		$c                 = $wp_embed->autoembed( $c );
	}
	$c = do_blocks( $c );
	$c = do_shortcode( $c );
	return rrt_embeds_filter_html( $c );
}

/** The hourly job: pictures not shown for 90 days go, and any file the list no longer names. */
function rrt_embeds_hourly() {
	$list    = rrt_embeds_list();
	$changed = false;
	$cut     = time() - RRT_EMBEDS_DAYS * DAY_IN_SECONDS;
	foreach ( $list as $k => $e ) {
		if ( ! rrt_embeds_clean_key( $k ) || (int) ( $e['seen'] ?? 0 ) < $cut ) {
			if ( ! empty( $e['f'] ) ) {
				rrt_embeds_unlink( (string) $e['f'] );
			}
			unset( $list[ $k ] );
			$changed = true;
		}
	}
	if ( $changed ) {
		rrt_embeds_list_save( $list );
	}
	$keep = array();
	foreach ( $list as $e ) {
		if ( ! empty( $e['f'] ) ) {
			$keep[ (string) $e['f'] ] = true;
		}
	}
	foreach ( (array) glob( rrt_embeds_dir() . '*.{jpg,png,webp}', GLOB_BRACE ) as $f ) {
		if ( $f && ! isset( $keep[ basename( $f ) ] ) && filemtime( $f ) < time() - HOUR_IN_SECONDS ) {
			wp_delete_file( $f );
		}
	}
}
add_action( 'rrt_share_hourly', 'rrt_embeds_hourly' );

/** Deletes one saved picture by its file name. */
function rrt_embeds_unlink( $file ) {
	$file = basename( (string) $file );
	if ( preg_match( '/^(yt|sc)-[a-z0-9_-]+\.(jpg|png|webp)$/', $file ) && file_exists( rrt_embeds_dir() . $file ) ) {
		wp_delete_file( rrt_embeds_dir() . $file );
	}
}

/** Clear saved pictures: every picture and the list go; they come back when next needed. Returns how many went. */
function rrt_embeds_clear() {
	$n = 0;
	foreach ( (array) glob( rrt_embeds_dir() . '*.{jpg,png,webp}', GLOB_BRACE ) as $f ) {
		if ( $f ) {
			wp_delete_file( $f );
			++$n;
		}
	}
	delete_option( 'rrt_embeds_list' );
	return $n;
}

/** The privacy paragraph, while either switch is on, or ''. */
function rrt_embeds_privacy_text() {
	$s = rrt_embeds_settings();
	if ( ! $s['yt'] && ! $s['sc'] ) {
		return '';
	}
	if ( $s['yt'] && $s['sc'] ) {
		$names = __( 'YouTube videos and SoundCloud tracks', 'foxfire-studio' );
		$which = __( 'YouTube or SoundCloud', 'foxfire-studio' );
	} elseif ( $s['yt'] ) {
		$names = __( 'YouTube videos', 'foxfire-studio' );
		$which = __( 'YouTube', 'foxfire-studio' );
	} else {
		$names = __( 'SoundCloud tracks', 'foxfire-studio' );
		$which = __( 'SoundCloud', 'foxfire-studio' );
	}
	/* translators: 1: YouTube videos and SoundCloud tracks, or one of them, 2: YouTube or SoundCloud, or one of them. */
	return sprintf( __( '%1$s on this site are shown as a picture with a play button. The picture and title are fetched once by this site itself and kept here, so your browser does not contact %2$s when you open a page. Only when you press play does your browser load the player from %2$s, and from then on that service\'s own privacy policy applies. YouTube videos play from YouTube\'s privacy-enhanced address. This site keeps nothing about you for this.', 'foxfire-studio' ), $names, $which );
}

/** Settings, Embedded players: the two switches and Clear saved pictures. */
function rrt_embeds_settings_section() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$s    = rrt_embeds_settings();
	$list = rrt_embeds_list();
	$pics = 0;
	foreach ( $list as $e ) {
		if ( ! empty( $e['f'] ) ) {
			++$pics;
		}
	}
	echo '<h2 id="rrt-embeds-settings">' . esc_html__( 'Embedded players', 'foxfire-studio' ) . '</h2>';
	echo '<p>' . esc_html__( 'YouTube and SoundCloud players load all of their files, and set their cookies, as soon as a visitor scrolls near them, whether or not anyone presses play. Switched on here, each one is shown as a picture with a play button, at the size the player will take, and the real player loads only when a visitor presses play. Pages load faster, and visitors contact the service only if they choose to play. Nothing in your posts changes, so you can switch these off at any time.', 'foxfire-studio' ) . '</p>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="rrt-embeds-form">';
	wp_nonce_field( 'rrt_embeds_save' );
	echo '<input type="hidden" name="action" value="rrt_embeds_save">';
	echo '<table class="form-table" role="presentation"><tr><th scope="row">' . esc_html__( 'Load on play', 'foxfire-studio' ) . '</th><td>';
	echo '<label class="rrt-check"><input type="checkbox" name="yt" value="1" id="rrt-embeds-yt"' . checked( $s['yt'], true, false ) . '> ' . esc_html__( 'YouTube videos load when a visitor presses play', 'foxfire-studio' ) . '</label><br>';
	echo '<label class="rrt-check"><input type="checkbox" name="sc" value="1" id="rrt-embeds-sc"' . checked( $s['sc'], true, false ) . '> ' . esc_html__( 'SoundCloud players load when a visitor presses play', 'foxfire-studio' ) . '</label>';
	echo '<p class="description">' . esc_html__( 'Every YouTube and SoundCloud player in your posts, pages and block widgets, however it was added. YouTube videos then play from YouTube\'s privacy-enhanced address. The site fetches each picture and title once and keeps the picture in its uploads folder. Add the paragraph under For your privacy policy to your policy.', 'foxfire-studio' ) . '</p>';
	echo '</td></tr></table>';
	submit_button( __( 'Save embedded players', 'foxfire-studio' ), 'secondary', 'save_embeds' );
	echo '</form>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="rrt-embeds-clear">';
	wp_nonce_field( 'rrt_embeds_clear' );
	echo '<input type="hidden" name="action" value="rrt_embeds_clear">';
	echo '<p class="description" id="rrt-embeds-saved">' . esc_html( sprintf( /* translators: 1: how many pictures, 2: days. */ _n( 'Saved pictures: %1$d. Each is removed %2$d days after it was last shown, and fetched again when next needed.', 'Saved pictures: %1$d. Each is removed %2$d days after it was last shown, and fetched again when next needed.', $pics, 'foxfire-studio' ), $pics, (int) RRT_EMBEDS_DAYS ) ) . '</p>';
	submit_button( __( 'Clear saved pictures', 'foxfire-studio' ), 'secondary', 'clear_embeds', false );
	echo '</form>';
}
add_action( 'rrt_settings_files', 'rrt_embeds_settings_section', 20 );

add_action(
	'admin_post_rrt_embeds_save',
	function () {
		check_admin_referer( 'rrt_embeds_save' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'foxfire-studio' ) );
		}
		update_option(
			'rrt_embeds',
			array(
				'yt' => ! empty( $_POST['yt'] ),
				'sc' => ! empty( $_POST['sc'] ),
			),
			false
		);
		wp_safe_redirect( admin_url( 'options-general.php?page=rrt-settings&rrt_embeds=saved#rrt-embeds-settings' ) );
		exit;
	}
);

add_action(
	'admin_post_rrt_embeds_clear',
	function () {
		check_admin_referer( 'rrt_embeds_clear' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'foxfire-studio' ) );
		}
		rrt_embeds_clear();
		wp_safe_redirect( admin_url( 'options-general.php?page=rrt-settings&rrt_embeds=cleared#rrt-embeds-settings' ) );
		exit;
	}
);
