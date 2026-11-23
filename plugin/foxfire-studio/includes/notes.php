<?php
/**
 * Pronunciation notes (1.1.0): a short note such as "SOW-in" shown small above
 * a word in the teleprompter, for names of people, places, herbs and deities
 * that are easy to say wrong.
 *
 * A note lives in one of three places:
 * - on one word only, as the n field of that word's cue mark (cues.php);
 * - every time the word appears in one post, in the post meta _rrt_notes;
 * - every time the word appears in any post, in the reader's own user meta
 *   rrt_notes.
 * The nearest one wins: one word, then the post, then the reader's own.
 *
 * Each note in a list: k (the word, cleaned as the teleprompter cleans words)
 * and n (the note). The post's text is never changed.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The post meta key for notes on every use of a word in one post. */
define( 'RRT_NOTES_META', '_rrt_notes' );

/** The user meta key for the reader's own notes, used in every post. */
define( 'RRT_NOTES_USER_META', 'rrt_notes' );

/** The most notes one list can hold. */
define( 'RRT_NOTES_MAX', 1000 );

/** The longest note, in characters. */
define( 'RRT_NOTE_LEN', 40 );

/**
 * A word cleaned the way the teleprompter cleans words: lower case, letters
 * (accented too) and digits only.
 *
 * @param mixed $k The word.
 * @return string
 */
function rrt_note_key( $k ) {
	if ( ! is_string( $k ) ) {
		return '';
	}
	$k = str_replace( array( "\u{2019}", "'" ), '', mb_strtolower( $k ) );
	/* 1.33.0: a word with letters of another script (or ß) keeps them; any other word is keyed exactly as before. */
	if ( preg_match( '/[^a-z0-9\x{00E0}-\x{024F}\p{P}\p{S}\p{Z}\s]/u', $k ) ) {
		return mb_substr( (string) preg_replace( '/[^\p{L}\p{M}\p{N}]/u', '', $k ), 0, 40 );
	}
	return mb_substr( preg_replace( '/[^a-z0-9\x{00E0}-\x{024F}]/u', '', $k ), 0, 40 );
}

/**
 * A note's text made safe: plain text on one line, at most RRT_NOTE_LEN characters.
 *
 * @param mixed $n The note.
 * @return string
 */
function rrt_note_text( $n ) {
	if ( ! is_string( $n ) && ! is_numeric( $n ) ) {
		return '';
	}
	return trim( mb_substr( sanitize_text_field( (string) $n ), 0, RRT_NOTE_LEN ) );
}

/**
 * A clean list of notes: one per word, valid entries only, sorted by word.
 *
 * @param mixed $in Notes as sent or as saved.
 * @return array
 */
function rrt_clean_notes( $in ) {
	$out = array();
	if ( ! is_array( $in ) ) {
		return array();
	}
	foreach ( $in as $x ) {
		if ( ! is_array( $x ) ) {
			continue;
		}
		$k = rrt_note_key( isset( $x['k'] ) ? $x['k'] : '' );
		$n = rrt_note_text( isset( $x['n'] ) ? $x['n'] : '' );
		if ( '' === $k || '' === $n || isset( $out[ $k ] ) ) {
			continue;
		}
		$out[ $k ] = $n;
		if ( count( $out ) >= RRT_NOTES_MAX ) {
			break;
		}
	}
	ksort( $out, SORT_STRING );
	$list = array();
	foreach ( $out as $k => $n ) {
		$list[] = array(
			'k' => (string) $k,
			'n' => $n,
		);
	}
	return $list;
}

/**
 * A post's notes for every use of a word.
 *
 * @param int $post_id Post ID.
 * @return array
 */
function rrt_get_post_notes( $post_id ) {
	return rrt_clean_notes( get_post_meta( (int) $post_id, RRT_NOTES_META, true ) );
}

/**
 * The reader's own notes, for every post.
 *
 * @param int $user_id User ID; 0 for the current user.
 * @return array
 */
function rrt_get_user_notes( $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	return $user_id ? rrt_clean_notes( get_user_meta( $user_id, RRT_NOTES_USER_META, true ) ) : array();
}

/**
 * Saves one of the lists in full; an empty list removes the meta.
 *
 * @param string $scope   'post' or 'all'.
 * @param int    $post_id The post (for 'post').
 * @param mixed  $notes   Notes as sent.
 * @return array The notes as saved.
 */
function rrt_save_notes( $scope, $post_id, $notes ) {
	$clean = rrt_clean_notes( $notes );
	if ( 'all' === $scope ) {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return array();
		}
		if ( empty( $clean ) ) {
			delete_user_meta( $user_id, RRT_NOTES_USER_META );
		} else {
			update_user_meta( $user_id, RRT_NOTES_USER_META, $clean );
		}
		return $clean;
	}
	if ( empty( $clean ) ) {
		delete_post_meta( (int) $post_id, RRT_NOTES_META );
	} else {
		update_post_meta( (int) $post_id, RRT_NOTES_META, $clean );
	}
	return $clean;
}

/**
 * A list of notes with changes applied (1.15.0).
 *
 * @param array $list    A clean list.
 * @param mixed $changes Word: note, an empty note to remove the word's note.
 * @return array A list to clean and save.
 */
function rrt_notes_apply( $list, $changes ) {
	$by = array();
	foreach ( $list as $x ) {
		$by[ $x['k'] ] = $x['n'];
	}
	foreach ( array_slice( is_array( $changes ) ? $changes : array(), 0, RRT_NOTES_MAX, true ) as $k => $n ) {
		$k = rrt_note_key( (string) $k );
		if ( '' === $k ) {
			continue;
		}
		$n = rrt_note_text( $n );
		if ( '' === $n ) {
			unset( $by[ $k ] );
		} else {
			$by[ $k ] = $n;
		}
	}
	$out = array();
	foreach ( $by as $k => $n ) {
		$out[] = array(
			'k' => (string) $k,
			'n' => $n,
		);
	}
	return $out;
}

/**
 * What the teleprompter needs for a post: both lists, and where to save them.
 *
 * @param int $post_id Post ID.
 * @return array|null
 */
function rrt_notes_boot( $post_id ) {
	$post_id = (int) $post_id;
	if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
		return null;
	}
	return array(
		'post'  => rrt_get_post_notes( $post_id ),
		'mine'  => rrt_get_user_notes(),
		'max'   => RRT_NOTE_LEN,
		'list'  => current_user_can( 'edit_posts' ) ? admin_url( 'admin.php?page=rrt-pronounce' ) : '',
		'rest'  => esc_url_raw( rest_url( 'rrt/v1/notes' ) ),
		'nonce' => wp_create_nonce( 'wp_rest' ),
	);
}

/* The REST route: anyone who can edit the post can save its notes, and their own. */
add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'rrt/v1',
			'/notes',
			array(
				'methods'             => 'POST',
				'permission_callback' => function ( $req ) {
					$p = (int) $req['post'];
					return is_user_logged_in() && $p > 0 && get_post( $p ) && current_user_can( 'edit_post', $p );
				},
				'callback'            => function ( $req ) {
					$scope = 'all' === $req['scope'] ? 'all' : 'post';
					/* Since 1.15.0 the studio sends only the words it changed (word: note, '' to remove). */
					if ( is_array( $req['changes'] ) ) {
						$have = 'all' === $scope ? rrt_get_user_notes() : rrt_get_post_notes( (int) $req['post'] );
						return rest_ensure_response(
							array(
								'scope' => $scope,
								'notes' => rrt_save_notes( $scope, (int) $req['post'], rrt_notes_apply( $have, $req['changes'] ) ),
							)
						);
					}
					$notes = $req['notes'];
					if ( null !== $notes && ! is_array( $notes ) ) {
						return new WP_Error( 'rrt_notes', __( 'The pronunciation notes were not a list.', 'foxfire-studio' ), array( 'status' => 400 ) );
					}
					return rest_ensure_response(
						array(
							'scope' => $scope,
							'notes' => rrt_save_notes( $scope, (int) $req['post'], $notes ),
						)
					);
				},
			)
		);
	}
);
