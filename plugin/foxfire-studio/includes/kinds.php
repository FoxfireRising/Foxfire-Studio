<?php
/**
 * Kind of reading (1.11.0): prose, story, verse, or ritual and chant, chosen
 * for each post. Each kind has a pace range (Settings, Reading), which the
 * prompter starts in the middle of and the review judges a take against.
 * In verse and ritual a trailing line is expected, so the review only flags
 * a deep fade. A post with no kind is read and reviewed as before.
 *
 * Saved as hidden post meta, the way cue marks are. For a post with many
 * short lines the studio suggests Verse; it never sets a kind by itself.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The post meta key. */
define( 'RRT_KIND_META', '_rrt_kind' );

/**
 * The kinds, in menu order, with their labels.
 *
 * @return array Key => label.
 */
function rrt_kinds() {
	return array(
		'prose'  => __( 'Prose', 'foxfire-studio' ),
		'story'  => __( 'Story', 'foxfire-studio' ),
		'verse'  => __( 'Verse', 'foxfire-studio' ),
		'ritual' => __( 'Ritual and chant', 'foxfire-studio' ),
	);
}

/**
 * Each kind's name inside a sentence, as in "inside your verse range".
 *
 * @return array Key => name.
 */
function rrt_kind_names() {
	return array(
		'prose'  => __( 'prose', 'foxfire-studio' ),
		'story'  => __( 'story', 'foxfire-studio' ),
		'verse'  => __( 'verse', 'foxfire-studio' ),
		'ritual' => __( 'ritual and chant', 'foxfire-studio' ),
	);
}

/**
 * The pace range of each kind, in words a minute, as first set up.
 *
 * @return array Key => array( low, high ).
 */
function rrt_kind_defaults() {
	return array(
		'prose'  => array( 140, 160 ),
		'story'  => array( 130, 150 ),
		'verse'  => array( 110, 135 ),
		'ritual' => array( 95, 120 ),
	);
}

/**
 * A clean set of ranges: every kind present, each between 60 and 220 wpm,
 * low below high by at least 5.
 *
 * @param mixed $in Ranges as sent or saved.
 * @return array Key => array( low, high ).
 */
function rrt_clean_kind_ranges( $in ) {
	$out = rrt_kind_defaults();
	if ( ! is_array( $in ) ) {
		return $out;
	}
	foreach ( array_keys( $out ) as $k ) {
		if ( ! isset( $in[ $k ] ) || ! is_array( $in[ $k ] ) ) {
			continue;
		}
		$r  = array_values( $in[ $k ] );
		$lo = isset( $r[0] ) && is_numeric( $r[0] ) ? (int) $r[0] : $out[ $k ][0];
		$hi = isset( $r[1] ) && is_numeric( $r[1] ) ? (int) $r[1] : $out[ $k ][1];
		$lo = max( 60, min( 215, $lo ) );
		$hi = max( 65, min( 220, $hi ) );
		if ( $hi < $lo + 5 ) {
			$hi = min( 220, $lo + 5 );
			$lo = $hi - 5;
		}
		$out[ $k ] = array( $lo, $hi );
	}
	return $out;
}

/**
 * The ranges in use, from Settings.
 *
 * @return array Key => array( low, high ).
 */
function rrt_kind_ranges() {
	$s = rrt_settings();
	return rrt_clean_kind_ranges( isset( $s['kinds'] ) ? $s['kinds'] : null );
}

/**
 * A post's kind, or '' for none.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function rrt_get_kind( $post_id ) {
	$k = get_post_meta( (int) $post_id, RRT_KIND_META, true );
	return is_string( $k ) && array_key_exists( $k, rrt_kinds() ) ? $k : '';
}

/**
 * Saves a post's kind; '' (or anything not a kind) removes it.
 *
 * @param int    $post_id Post ID.
 * @param string $kind    The kind.
 * @return string The kind as saved.
 */
function rrt_save_kind( $post_id, $kind ) {
	$kind = is_string( $kind ) && array_key_exists( $kind, rrt_kinds() ) ? $kind : '';
	if ( '' === $kind ) {
		delete_post_meta( (int) $post_id, RRT_KIND_META );
	} else {
		update_post_meta( (int) $post_id, RRT_KIND_META, $kind );
	}
	return $kind;
}

/**
 * Whether a post's HTML looks like verse: mostly short lines, split by line
 * breaks or short paragraphs. Headings, lists and the title are left out.
 *
 * @param string $html The post's content.
 * @return string 'verse' or ''.
 */
function rrt_kind_guess_html( $html ) {
	$h = preg_replace( '#<(script|style|figure|figcaption|iframe|h[1-6]|ul|ol|table)\b[^>]*>.*?</\1>#is', ' ', (string) $html );
	$h = preg_replace( '#<br\s*/?>#i', "\n", $h );
	$h = preg_replace( '#</?(p|div|blockquote|pre)\b[^>]*>#i', "\n", $h );
	$t = html_entity_decode( wp_strip_all_tags( $h ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	/* A block-editor post keeps each paragraph on its own line already; empty lines are not lines. */
	$lines = array();
	foreach ( preg_split( '/\n/', $t ) as $line ) {
		$n = str_word_count( preg_replace( '/[^\p{L}\p{N}\'\s-]/u', ' ', $line ) );
		if ( $n > 0 ) {
			$lines[] = $n;
		}
	}
	if ( count( $lines ) < 6 ) {
		return '';
	}
	$short = 0;
	foreach ( $lines as $n ) {
		if ( $n <= 12 ) {
			++$short;
		}
	}
	sort( $lines );
	$mid = $lines[ (int) floor( count( $lines ) / 2 ) ];
	return ( $short / count( $lines ) >= 0.7 && $mid <= 10 ) ? 'verse' : '';
}

/**
 * The kind the studio suggests for a post with none chosen.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function rrt_kind_guess( $post_id ) {
	$post = get_post( (int) $post_id );
	return $post ? rrt_kind_guess_html( $post->post_content ) : '';
}

/**
 * What the studio needs: the ranges and names always, and for a post its kind,
 * the suggestion (shown only while it has none), and where to save a choice (for those who can edit it).
 *
 * @param int $post_id Post ID, or 0.
 * @return array
 */
function rrt_kind_boot( $post_id ) {
	$post_id = (int) $post_id;
	$out     = array(
		'ranges' => rrt_kind_ranges(),
		'labels' => rrt_kinds(),
		'names'  => rrt_kind_names(),
		'kind'   => '',
		'guess'  => '',
		'rest'   => '',
		'nonce'  => '',
	);
	if ( $post_id > 0 ) {
		$out['kind']  = rrt_get_kind( $post_id );
		$out['guess'] = rrt_kind_guess( $post_id );
		if ( current_user_can( 'edit_post', $post_id ) ) {
			$out['rest']  = esc_url_raw( rest_url( 'rrt/v1/kind' ) );
			$out['nonce'] = wp_create_nonce( 'wp_rest' );
		}
	}
	return $out;
}

/* The REST route: anyone who can edit the post can set its kind. */
add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'rrt/v1',
			'/kind',
			array(
				'methods'             => 'POST',
				'permission_callback' => function ( $req ) {
					$p = (int) $req['post'];
					return is_user_logged_in() && $p > 0 && get_post( $p ) && current_user_can( 'edit_post', $p );
				},
				'callback'            => function ( $req ) {
					$k = $req['kind'];
					if ( null !== $k && ! is_string( $k ) ) {
						return new WP_Error( 'rrt_kind', __( 'The kind of reading was not text.', 'foxfire-studio' ), array( 'status' => 400 ) );
					}
					return rest_ensure_response( array( 'kind' => rrt_save_kind( (int) $req['post'], (string) $k ) ) );
				},
			)
		);
	}
);
