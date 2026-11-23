<?php
/**
 * Cue marks (0.15.0): pauses, breaths and emphasis marked on words in the
 * teleprompter. They are saved with the post as hidden post meta, so they
 * follow the reader to any computer, and anyone who can edit the post sees
 * them. The post's text is never changed.
 *
 * Each cue: i (the word's number in the script), k (the word itself, cleaned,
 * so the teleprompter can find it again after the post is edited), a ('pause',
 * 'breath' or ''), e (emphasis, true or false), and since 1.1.0 n (a
 * pronunciation note for this one word, or ''; see notes.php), and since
 * 1.11.0 f (a fade meant at the end of this line, true or false: the review
 * does not flag its sentence as a faded ending).
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The post meta key. The leading underscore keeps it out of the Custom Fields box. */
define( 'RRT_CUES_META', '_rrt_cues' );

/** The most cues one post can hold. */
define( 'RRT_CUES_MAX', 3000 );

/**
 * A clean list of cues: valid entries only, one per word, in script order.
 *
 * @param mixed $in Cues as sent or as saved.
 * @return array
 */
function rrt_clean_cues( $in ) {
	$out  = array();
	$seen = array();
	if ( ! is_array( $in ) ) {
		return $out;
	}
	foreach ( $in as $c ) {
		if ( ! is_array( $c ) || ! isset( $c['i'] ) || ! is_numeric( $c['i'] ) ) {
			continue;
		}
		$i = (int) $c['i'];
		if ( $i < 0 || $i > 100000 || isset( $seen[ $i ] ) ) {
			continue;
		}
		$a = isset( $c['a'] ) && in_array( $c['a'], array( 'pause', 'breath' ), true ) ? $c['a'] : '';
		$e = ! empty( $c['e'] );
		$f = ! empty( $c['f'] );
		$n = function_exists( 'rrt_note_text' ) && isset( $c['n'] ) ? rrt_note_text( $c['n'] ) : '';
		if ( '' === $a && ! $e && ! $f && '' === $n ) {
			continue;
		}
		$k = isset( $c['k'] ) && is_string( $c['k'] ) ? $c['k'] : '';
		/* Letters (accented too) and digits only, as the teleprompter cleans words. */
		$k          = mb_substr( preg_replace( '/[^a-z0-9\x{00E0}-\x{024F}]/u', '', mb_strtolower( $k ) ), 0, 40 );
		$seen[ $i ] = true;
		$out[]      = array(
			'i' => $i,
			'k' => $k,
			'a' => $a,
			'e' => $e,
			'f' => $f,
			'n' => $n,
		);
		if ( count( $out ) >= RRT_CUES_MAX ) {
			break;
		}
	}
	usort(
		$out,
		function ( $x, $y ) {
			return $x['i'] - $y['i'];
		}
	);
	return $out;
}

/**
 * A post's saved cues.
 *
 * @param int $post_id Post ID.
 * @return array
 */
function rrt_get_cues( $post_id ) {
	return rrt_clean_cues( get_post_meta( (int) $post_id, RRT_CUES_META, true ) );
}

/**
 * Saves a post's cues; an empty list removes the meta.
 *
 * @param int   $post_id Post ID.
 * @param mixed $cues    Cues as sent.
 * @return array The cues as saved.
 */
function rrt_save_cues( $post_id, $cues ) {
	$clean = rrt_clean_cues( $cues );
	if ( empty( $clean ) ) {
		delete_post_meta( (int) $post_id, RRT_CUES_META );
	} else {
		update_post_meta( (int) $post_id, RRT_CUES_META, $clean );
	}
	return $clean;
}

/**
 * A cue list with changes applied (1.15.0): each change is i and cue, the
 * cue null to take word i's mark away.
 *
 * @param array $list    A clean list.
 * @param mixed $changes Changes as sent.
 * @return array A list to clean and save.
 */
function rrt_cues_apply( $list, $changes ) {
	$by = array();
	foreach ( $list as $c ) {
		$by[ $c['i'] ] = $c;
	}
	foreach ( array_slice( is_array( $changes ) ? $changes : array(), 0, RRT_CUES_MAX ) as $x ) {
		if ( ! is_array( $x ) || ! isset( $x['i'] ) || ! is_numeric( $x['i'] ) ) {
			continue;
		}
		$i = (int) $x['i'];
		if ( isset( $x['cue'] ) && is_array( $x['cue'] ) ) {
			$by[ $i ] = array_merge( $x['cue'], array( 'i' => $i ) );
		} else {
			unset( $by[ $i ] );
		}
	}
	return array_values( $by );
}

/**
 * What the teleprompter needs for a post: its cues, and where to save them.
 *
 * @param int $post_id Post ID.
 * @return array|null
 */
function rrt_cues_boot( $post_id ) {
	$post_id = (int) $post_id;
	if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
		return null;
	}
	return array(
		'list'  => rrt_get_cues( $post_id ),
		'rest'  => esc_url_raw( rest_url( 'rrt/v1/cues' ) ),
		'nonce' => wp_create_nonce( 'wp_rest' ),
	);
}

/* The REST route: anyone who can edit the post can save its cues. */
add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'rrt/v1',
			'/cues',
			array(
				'methods'             => 'POST',
				'permission_callback' => function ( $req ) {
					$p = (int) $req['post'];
					return is_user_logged_in() && $p > 0 && get_post( $p ) && current_user_can( 'edit_post', $p );
				},
				'callback'            => function ( $req ) {
					/* Since 1.15.0 the studio sends only what changed, so a change made elsewhere meanwhile is kept. */
					if ( is_array( $req['changes'] ) ) {
						return rest_ensure_response( array( 'cues' => rrt_save_cues( (int) $req['post'], rrt_cues_apply( rrt_get_cues( (int) $req['post'] ), $req['changes'] ) ) ) );
					}
					$cues = $req['cues'];
					if ( null !== $cues && ! is_array( $cues ) ) {
						return new WP_Error( 'rrt_cues', __( 'The cue marks were not a list.', 'foxfire-studio' ), array( 'status' => 400 ) );
					}
					return rest_ensure_response( array( 'cues' => rrt_save_cues( (int) $req['post'], $cues ) ) );
				},
			)
		);
	}
);
