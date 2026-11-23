<?php
/**
 * Learned words per post (0.16.0). When Whisper mishears a word that belongs
 * to one piece (a name, an invented word), the reader can teach it for that
 * post only. Those pairs are kept with the post as hidden post meta, beside
 * the reader's own words for every post (user meta rrt_learned), so anyone
 * who can edit the post reviews it with the same words.
 *
 * Same shape as the words for every post: pairs (script word => heard words),
 * ignore (words Whisper imagines), labels (what the review shows).
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The post meta key; the underscore keeps it out of the Custom Fields box. */
define( 'RRT_LEARNED_POST_META', '_rrt_learned' );

/**
 * A post's own learned words.
 *
 * @param int $post_id Post ID.
 * @return array
 */
function rrt_get_post_learned( $post_id ) {
	return rrt_clean_learned( get_post_meta( (int) $post_id, RRT_LEARNED_POST_META, true ) );
}

/**
 * Saves a post's own learned words; an empty set removes the meta.
 *
 * @param int   $post_id Post ID.
 * @param mixed $learned As sent.
 * @param bool  $replace Replace (forgetting a word) instead of adding to what is saved.
 * @return array The words as saved.
 */
function rrt_save_post_learned( $post_id, $learned, $replace ) {
	/* Merged with what is saved; a forgotten word is kept as forgotten (1.15.0). */
	$out = rrt_learned_save_over( rrt_get_post_learned( $post_id ), $learned, (bool) $replace );
	if ( empty( $out['pairs'] ) && empty( $out['ignore'] ) && empty( $out['labels'] ) && empty( $out['forgot'] ) ) {
		delete_post_meta( (int) $post_id, RRT_LEARNED_POST_META );
	} else {
		update_post_meta( (int) $post_id, RRT_LEARNED_POST_META, $out );
	}
	return $out;
}

/**
 * What the teleprompter needs: the post's words and where to save them.
 *
 * @param int $post_id Post ID.
 * @return array|null
 */
function rrt_post_learned_boot( $post_id ) {
	$post_id = (int) $post_id;
	if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
		return null;
	}
	return array(
		'learned' => rrt_get_post_learned( $post_id ),
		'rest'    => esc_url_raw( rest_url( 'rrt/v1/learned-post' ) ),
		'nonce'   => wp_create_nonce( 'wp_rest' ),
	);
}

/* The REST route: anyone who can edit the post can teach it words. */
add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'rrt/v1',
			'/learned-post',
			array(
				'methods'             => 'POST',
				'permission_callback' => function ( $req ) {
					$p = (int) $req['post'];
					return is_user_logged_in() && $p > 0 && get_post( $p ) && current_user_can( 'edit_post', $p );
				},
				'callback'            => function ( $req ) {
					return rest_ensure_response( array( 'learned' => rrt_save_post_learned( (int) $req['post'], $req['learned'], (bool) $req['replace'] ) ) );
				},
			)
		);
	}
);
