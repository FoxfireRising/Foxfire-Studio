<?php
/**
 * The teleprompter page: access checks, turning a post into a script, and
 * handing everything to the view.
 *
 * The page lives at yoursite.com/?teleprompter=1 (with &tp_post=ID for a post)
 * and replaces the normal page output, so the theme never gets involved.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The teleprompter's address. */
function rrt_url( $post_id = 0 ) {
	$args = array( 'teleprompter' => 1 );
	if ( $post_id ) {
		$args['tp_post'] = (int) $post_id;
	}
	return add_query_arg( $args, home_url( '/' ) );
}

add_action(
	'template_redirect',
	function () {
		if ( empty( $_GET['teleprompter'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- read-only page switch.
			return;
		}
		rrt_render();
		exit;
	},
	0
);

/**
 * Reduce a post to the blocks a reader says aloud: title, headings and
 * paragraphs. Images, embeds, captions, forms and scripts are dropped.
 *
 * @param WP_Post $post The post.
 * @return array List of array( 'type' => title|h|p, 'text' => string ).
 */
function rrt_post_blocks( $post ) {
	$title           = get_the_title( $post );
	$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- needed for the_content filters.
	setup_postdata( $post );
	$h = apply_filters( 'the_content', $post->post_content );
	wp_reset_postdata();
	return rrt_blocks_from_html( $title, $h );
}

/**
 * The blocks of a post's HTML, as the teleprompter reads them (split out of
 * rrt_post_blocks in 1.4.0, so Readings to make can measure posts quickly
 * from their stored content).
 *
 * @param string $title The post's title.
 * @param string $h     The post's HTML.
 * @return array List of array( 'type' => title|h|p, 'text' => string ).
 */
function rrt_blocks_from_html( $title, $h ) {
	$h = preg_replace( '#<(script|style|figure|figcaption|iframe|noscript|svg|audio|video|object|button|form)\b[^>]*>.*?</\1>#is', ' ', $h );
	$h = preg_replace( '#<img\b[^>]*>#i', ' ', $h );
	$h = preg_replace( '#<h[1-6]\b[^>]*>#i', "\n\n[[RRTH]]", $h );
	$h = preg_replace( '#<(p|li|blockquote|div|pre|tr)\b[^>]*>#i', "\n\n", $h );
	$h = preg_replace( '#</(p|h[1-6]|li|blockquote|div|ul|ol|pre|table|tr)>#i', "\n\n", $h );
	$h = preg_replace( '#<br\s*/?>#i', ' ', $h );
	$t = wp_strip_all_tags( $h );
	$t = html_entity_decode( $t, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$t = str_replace( "\xC2\xA0", ' ', $t );

	$blocks = array(
		array(
			'type' => 'title',
			'text' => $title,
		),
	);
	foreach ( preg_split( "/\n\s*\n/", $t ) as $part ) {
		$is_heading = false;
		if ( 0 === strpos( ltrim( $part ), '[[RRTH]]' ) ) {
			$is_heading = true;
			$part       = str_replace( '[[RRTH]]', '', $part );
		}
		$part = trim( preg_replace( '/\s+/u', ' ', $part ) );
		if ( '' === $part ) {
			continue;
		}
		$blocks[] = array(
			'type' => $is_heading ? 'h' : 'p',
			'text' => $part,
		);
	}
	return $blocks;
}

/**
 * The MP3 tag details for a post: the same title, author and site name the
 * listening player gives the lock screen, plus the year, the post's address
 * and its featured image for artwork.
 *
 * @param WP_Post $post The post.
 * @return array
 */
function rrt_mp3_tags( $post ) {
	$art   = '';
	$thumb = get_post_thumbnail_id( $post );
	if ( $thumb ) {
		$img = wp_get_attachment_image_src( $thumb, 'full' );
		if ( $img ) {
			$art = $img[0];
		}
	}
	return array(
		'title'   => wp_strip_all_tags( get_the_title( $post ) ),
		'artist'  => wp_strip_all_tags( get_the_author_meta( 'display_name', $post->post_author ) ),
		'album'   => rrt_show_name(),
		'year'    => (int) get_the_date( 'Y', $post ),
		/* A draft has no pretty address yet; ?p= keeps working and redirects once it is published. */
		'url'     => 'publish' === get_post_status( $post ) ? get_permalink( $post ) : add_query_arg( 'p', $post->ID, home_url( '/' ) ),
		'artwork' => $art,
		'genre'   => __( 'Podcast', 'foxfire-studio' ),
		/* 1.33.0: a reading in another language says so in the MP3's language tag; English readings are tagged as before. */
		'lang'    => function_exists( 'rrt_post_lang' ) && 'en' !== rrt_post_lang( $post->ID ) ? rrt_lang_iso3( rrt_post_lang( $post->ID ) ) : '',
	);
}

/** Print the teleprompter page. */
function rrt_render() {
	if ( ! is_user_logged_in() ) {
		auth_redirect();
	}
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die( esc_html__( 'This page is only for site editors.', 'foxfire-studio' ), '', array( 'response' => 403 ) );
	}
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	nocache_headers();
	do_action( 'litespeed_control_set_nocache', 'teleprompter is private' );

	$rrt_cfg   = rrt_config();
	$rrt_posts = get_posts(
		array(
			'post_type'   => $rrt_cfg['post_types'],
			'post_status' => array( 'publish', 'draft', 'pending', 'future', 'private' ),
			'numberposts' => -1,
			'orderby'     => 'date',
			'order'       => 'DESC',
		)
	);
	/* Only list posts this person may edit, so a contributor never sees the titles of other people's drafts or private posts. */
	$rrt_posts = array_values(
		array_filter(
			$rrt_posts,
			function ( $p ) {
				return current_user_can( 'edit_post', $p->ID );
			}
		)
	);

	$rrt_sel    = isset( $_GET['tp_post'] ) ? absint( $_GET['tp_post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
	$rrt_blocks = array();
	$rrt_title  = '';
	$rrt_slug   = 'reading';
	$rrt_tags   = null;

	if ( $rrt_sel ) {
		$p = get_post( $rrt_sel );
		/* Audiobook credits (1.20.0) are read in the studio like any post. */
		/* So are sponsor messages (1.22.0). */
		if ( $p && ( in_array( $p->post_type, $rrt_cfg['post_types'], true ) || ( function_exists( 'rrt_book_is_credit' ) && rrt_book_is_credit( $p ) ) || ( function_exists( 'rrt_spot_is' ) && rrt_spot_is( $p ) ) ) && current_user_can( 'edit_post', $p->ID ) ) {
			$rrt_title  = get_the_title( $p );
			$rrt_slug   = $p->post_name ? $p->post_name : sanitize_title( $rrt_title );
			$rrt_blocks = rrt_post_blocks( $p );
			/* A sponsor message's title is for the owner's list: the studio reads its script, first line first. */
			if ( function_exists( 'rrt_spot_is' ) && rrt_spot_is( $p ) ) {
				$rrt_blocks = rrt_spot_studio_blocks( $p );
			}
			$rrt_tags   = rrt_mp3_tags( $p );
		} else {
			$rrt_sel = 0;
		}
	}

	/* The practice reading (1.29.0): the built-in script, read with no post. */
	$rrt_practice = ! $rrt_sel && ! empty( $_GET['tp_practice'] ) && function_exists( 'rrt_practice_blocks' ); // phpcs:ignore WordPress.Security.NonceVerification -- read-only page switch.
	if ( $rrt_practice ) {
		$rrt_title  = rrt_practice_title();
		$rrt_slug   = 'practice';
		$rrt_blocks = rrt_practice_blocks();
		$rrt_tags   = rrt_practice_tags();
	}

	$rrt_boot = array(
		'pageUrl'   => rrt_url(),
		/* The practice reading (1.29.0): whether this is it, and whether Check microphone opens by itself (from the setup wizard). */
		'practice'  => $rrt_practice ? array( 'check' => ! empty( $_GET['tp_check'] ) ) : null, // phpcs:ignore WordPress.Security.NonceVerification -- read-only page switch.
		/* The New here card (1.29.0): where Try it goes. */
		'practiceUrl' => function_exists( 'rrt_practice_url' ) ? rrt_practice_url( true ) : '',
		'postParam' => 'tp_post',
		'postId'    => (int) $rrt_sel,
		'siteName'  => wp_parse_url( home_url(), PHP_URL_HOST ),
		/* Reader stats on the server (0.9.0): where to save takes and taught words, for this reader only. */
		'stats'     => function_exists( 'rrt_stats_can' ) && rrt_stats_can() ? array(
			'rest'   => esc_url_raw( rest_url( 'rrt/v1/' ) ),
			'nonce'  => wp_create_nonce( 'wp_rest' ),
			'user'   => get_current_user_id(),
			'upload' => current_user_can( 'upload_files' ),
			/* Goals (0.11.0), set in Foxfire Studio, Your progress; each take is checked against them. */
			'goals'  => function_exists( 'rrt_get_goals' ) ? array_map(
				function ( $g ) {
					$g['text'] = rrt_goal_text( $g );
					return $g;
				},
				rrt_get_goals()
			) : array(),
			'progress' => admin_url( 'admin.php?page=rrt-progress' ),
			/* Shared takes (0.17.0). */
			'shares'   => admin_url( 'admin.php?page=rrt-shares' ),
		) : null,
		/* Send to my computer (0.21.0): takes sent from a phone, waiting here. */
		'sent'      => function_exists( 'rrt_sent_boot' ) ? rrt_sent_boot() : null,
		/* The take shelf across computers (1.34.0): where to send this post's takes, or null while it is off. */
		'shelfSite' => function_exists( 'rrt_shelf_boot' ) && $rrt_sel ? rrt_shelf_boot( (int) $rrt_sel ) : null,
		/* The intro video (0.23.0), when one is chosen in Settings. */
		'intro'     => rrt_intro_boot(),
		/* The music bed for this post (0.24.0), or null for none. */
		'music'     => $rrt_sel && ! ( function_exists( 'rrt_book_is_credit' ) && rrt_book_is_credit( $rrt_sel ) ) && ! ( function_exists( 'rrt_spot_is' ) && rrt_spot_is( $rrt_sel ) ) ? rrt_music_for( (int) $rrt_sel ) : null,
		/* Audiobook export (1.20.0): whether Use this recording also keeps an audiobook copy, and whether this post is a collection's credits. */
		'book'      => function_exists( 'rrt_book_boot' ) ? rrt_book_boot( (int) $rrt_sel ) : null,
		/* What goes into the MP3's tags (0.7.0): the same details the listening player shows on a lock screen. */
		'tags'      => $rrt_tags,
		'defaults'  => $rrt_cfg['defaults'],
		'whisper'   => rrt_whisper_boot( $rrt_cfg ),
		/* Voice following (1.14.0): the engine, its worker and where the model loads from. */
		'follow'    => rrt_follow_boot( $rrt_cfg ),
		/* Clips for social media (0.13.0): loaded only when "Make a clip" is pressed. */
		/* Cue marks (0.15.0): this post's marks and where to save them. */
		/* Learned words per post (0.16.0): this post's own words and where to save them. */
		'learnedPost' => function_exists( 'rrt_post_learned_boot' ) && $rrt_sel ? rrt_post_learned_boot( (int) $rrt_sel ) : null,
		'cues'      => function_exists( 'rrt_cues_boot' ) && $rrt_sel ? rrt_cues_boot( (int) $rrt_sel ) : null,
		'kind'      => function_exists( 'rrt_kind_boot' ) ? rrt_kind_boot( (int) $rrt_sel ) : null,
		/* The reading language (1.33.0): which Whisper hears the take, and how the review compares words. */
		'lang'      => function_exists( 'rrt_lang_boot' ) ? rrt_lang_boot( (int) $rrt_sel ) : null,
		'notes'     => function_exists( 'rrt_notes_boot' ) && $rrt_sel ? rrt_notes_boot( (int) $rrt_sel ) : null,
		/* Insights (0.19.0): how long and how quick readings were when listeners stayed, for a line in the script bar. */
		'insight'   => function_exists( 'rrt_insight_studio' ) && $rrt_sel ? rrt_insight_studio() : null,
		'clip'      => function_exists( 'rrt_clip_boot' ) && $rrt_sel ? array_merge( rrt_clip_assets(), array( 'boot' => rrt_clip_boot( (int) $rrt_sel ) ) ) : null,
		/* Finishing presets (1.6.0): the chosen one as spec, and every preset for the review's one-off Finish for menu. */
		'sound'     => array_merge(
			$rrt_cfg['sound'],
			array(
				'spec'  => rrt_finish_spec( $rrt_cfg['sound'] ),
				'specs' => rrt_finish_specs( $rrt_cfg['sound'] ),
			)
		),
		'soundUrl'  => rrt_js_url( 'sound.js' ),
		/* Voice polish (1.13.0): the reader's setups for the EQ, and where to save a take's measurement. */
		'eq'        => function_exists( 'rrt_eq_boot' ) ? rrt_eq_boot() : null,
		/* Speech-model noise reduction (0.6.0). The model is refused unless it matches the fingerprint. */
		'denoise'   => array(
			'strength' => $rrt_cfg['sound']['denoise'],
			'worker'   => rrt_js_url( 'denoise.js' ),
			'model'    => $rrt_cfg['rnnoise_url'],
			'fallback' => $rrt_cfg['rnnoise_url'] !== $rrt_cfg['rnnoise_cdn'] ? $rrt_cfg['rnnoise_cdn'] : '',
			'sha256'   => RRT_RNNOISE_SHA256,
			/* The stronger cleanup (1.16.0): which engine Settings chose, and how to run DeepFilterNet 3 (null if its files are missing). */
			'engine'   => $rrt_cfg['sound']['engine'],
			'stronger' => rrt_stronger_boot( $rrt_cfg ),
		),
		/* The MP3 encoder (0.7.0): LAME as WebAssembly, refused unless it matches the fingerprint. */
		'encoder'   => array(
			'worker'   => rrt_js_url( 'mp3.js' ),
			'url'      => $rrt_cfg['mp3_url'],
			'fallback' => $rrt_cfg['mp3_url'] !== $rrt_cfg['mp3_cdn'] ? $rrt_cfg['mp3_cdn'] : '',
			'sha256'   => RRT_MP3_SHA256,
			'version'  => 'wasm-media-encoders ' . RRT_MP3_VERSION,
		),
	);

	/* The studio look (0.18.0): links to the other Foxfire Studio screens, the artwork and the corner line. */
	$rrt_nav   = rrt_studio_nav();
	$rrt_look  = rrt_look( $rrt_cfg );
	$rrt_light = false;
	$rrt_mark  = rrt_studio_art();
	$rrt_art   = 'studio' === $rrt_look ? $rrt_mark : array();
	if ( 'theme' === $rrt_look ) {
		/* The theme's colours replace the look's for this page; the clip maker and share page keep the look's. */
		$rrt_tok           = rrt_theme_tokens( $rrt_cfg );
		$rrt_light         = $rrt_tok['light'];
		unset( $rrt_tok['light'] );
		$rrt_cfg['colors'] = $rrt_tok;
	}
	$rrt_boot['skin'] = array(
		'last'     => $rrt_sel && ! empty( $rrt_boot['stats'] ),
		'progress' => admin_url( 'admin.php?page=rrt-progress' ),
	);

	include RRT_DIR . 'views/teleprompter.php';
}

/**
 * The other Foxfire Studio screens, for the menu along the top of the
 * teleprompter. Each opens in a new tab, so a reading in progress is never
 * left behind, and only screens this person may open are listed.
 *
 * @return array List of array( 'label', 'url' ).
 */
function rrt_studio_nav() {
	$listen = function_exists( 'rrt_dash_cap' ) ? rrt_dash_cap() : 'edit_posts';
	$items  = array(
		array( __( 'Overview', 'foxfire-studio' ), 'admin.php?page=rrt-dashboard', 'edit_posts' ),
		array( __( 'Readings', 'foxfire-studio' ), 'admin.php?page=rrt-readings', $listen ),
		array( __( 'Your progress', 'foxfire-studio' ), 'admin.php?page=rrt-progress', 'edit_posts' ),
		array( __( 'Insights', 'foxfire-studio' ), 'admin.php?page=rrt-insights', 'edit_posts' ),
		array( __( 'Recordings', 'foxfire-studio' ), 'admin.php?page=rrt-recordings', $listen ),
		array( __( 'Shared takes', 'foxfire-studio' ), 'admin.php?page=rrt-shares', 'edit_posts' ),
		array( __( 'Reports', 'foxfire-studio' ), 'admin.php?page=rrt-reports', 'manage_options' ),
	);
	$out = array();
	foreach ( $items as $i ) {
		if ( current_user_can( $i[2] ) ) {
			$out[] = array(
				'label' => $i[0],
				'url'   => admin_url( $i[1] ),
			);
		}
	}
	return $out;
}

/**
 * The teleprompter's artwork: whichever of the fox, the night sky and the
 * logo mark are in the plugin's assets/img folder, and the mark's small copy
 * for the browser tab.
 *
 * @return array Keys icon, fox, sky, mark; each a URL or ''.
 */
function rrt_studio_art() {
	$out = array(
		'icon' => file_exists( RRT_DIR . 'assets/img/mark-icon.png' ) ? RRT_URL . 'assets/img/mark-icon.png?ver=' . RRT_VERSION : '',
	);
	foreach ( array( 'fox', 'sky', 'mark' ) as $k ) {
		$out[ $k ] = '';
		foreach ( array( 'webp', 'png' ) as $ext ) {
			if ( file_exists( RRT_DIR . 'assets/img/' . $k . '.' . $ext ) ) {
				$out[ $k ] = RRT_URL . 'assets/img/' . $k . '.' . $ext . '?ver=' . RRT_VERSION;
				break;
			}
		}
	}
	return $out;
}

/**
 * The intro video for the studio (0.23.0): its address and type, or null
 * when none is chosen or the file is gone.
 */
function rrt_intro_boot() {
	$s  = rrt_settings();
	$id = isset( $s['intro'] ) ? (int) $s['intro'] : 0;
	if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
		return null;
	}
	$type = (string) get_post_mime_type( $id );
	$url  = (string) wp_get_attachment_url( $id );
	if ( '' === $url || 0 !== strpos( $type, 'video/' ) ) {
		return null;
	}
	return array(
		'url'  => $url,
		'type' => $type,
	);
}
