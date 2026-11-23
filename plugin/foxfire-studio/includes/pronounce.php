<?php
/**
 * The pronunciation list (1.15.0): one screen, Foxfire Studio, Pronunciation,
 * for every pronunciation note and every word taught to Whisper, wherever it
 * is kept, with search, editing, moving a word to another place, export,
 * import, and words from the posts that may need a note.
 *
 * Where things are kept is unchanged (notes.php, cues.php, stats.php,
 * learned-post.php). This file only reads and changes them, plus:
 * - user meta rrt_pron_skip: the words the reader marked "Not needed" in
 *   the suggestions;
 * - the transient rrt_pron_index: a word index of the published posts, made
 *   again whenever a post is published or changed.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The user meta key for words marked "Not needed" in the suggestions. */
define( 'RRT_PRON_SKIP', 'rrt_pron_skip' );

/** The transient holding the word index of the published posts. */
define( 'RRT_PRON_INDEX', 'rrt_pron_index' );

/** The most posts listed for one word in the index. */
define( 'RRT_PRON_POSTS', 30 );

/** The most suggestions sent to the screen. */
define( 'RRT_PRON_SUGGEST', 60 );

/** The backup file's format name. */
define( 'RRT_PRON_FORMAT', 'foxfire-studio-pronunciation' );

/**
 * The words of a list of blocks as the teleprompter splits them: each word
 * with its key, its form without punctuation, and its block.
 *
 * @param array $blocks As rrt_blocks_from_html() gives them.
 * @return array Each: k, w (the word without punctuation), t (as written), b (block).
 */
function rrt_pron_tokens( $blocks ) {
	$out = array();
	foreach ( $blocks as $bi => $b ) {
		foreach ( preg_split( '/\s+/u', trim( $b['text'] ), -1, PREG_SPLIT_NO_EMPTY ) as $t ) {
			$bare  = preg_replace( '/[\x{2019}\']s$/u', '', preg_replace( '/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', $t ) );
			$out[] = array(
				'k' => rrt_note_key( $t ),
				'w' => $bare,
				't' => $t,
				'b' => $bi,
			);
		}
	}
	return $out;
}

/**
 * The runs of capitalised words in a block of text: each run's word keys,
 * its length (counting small joining words inside it), and the keys of the
 * capitalised words in it that do not start a sentence or follow a colon.
 *
 * @param string $text A block's text.
 * @return array Each: keys, len, mid.
 */
function rrt_pron_caps_runs( $text ) {
	$joiners = array_flip( array( 'of', 'the', 'and', 'in', 'a', 'an', 'to', 'for', 'on', 'at', 'de', 'la', 'le', 'du', 'von', 'van', 'da', 'del' ) );
	$out     = array();
	$run     = null;
	$pending = 0; // Joining words waiting for the next capital.
	$start   = true; // The next word starts a sentence.
	$close   = function () use ( &$run, &$out, &$pending ) {
		if ( $run ) {
			$out[] = $run;
		}
		$run     = null;
		$pending = 0;
	};
	foreach ( preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY ) as $t ) {
		$bare = preg_replace( '/[\x{2019}\']s$/u', '', preg_replace( '/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', $t ) );
		$k    = rrt_note_key( $bare );
		if ( '' === $k ) {
			$close();
		} elseif ( preg_match( '/^\p{Lu}/u', $bare ) ) {
			if ( ! $run ) {
				$run = array(
					'keys' => array(),
					'len'  => 0,
					'mid'  => array(),
				);
			}
			$run['len']   += 1 + $pending;
			$pending       = 0;
			$run['keys'][] = $k;
			if ( ! $start ) {
				$run['mid'][] = $k;
			}
		} elseif ( $run && isset( $joiners[ $k ] ) && $pending < 2 ) {
			$pending++;
		} else {
			$close();
		}
		$start = (bool) preg_match( '/(?:[.!?:;]|\x{2026}|\.\.\.)[\x{201D}\x{2019}"\')\]]*$/u', $t );
		if ( preg_match( '/[,.!?:;\x{2026})\]]["\x{201D}\x{2019}]*$/u', $t ) ) {
			$close();
		}
	}
	$close();
	return $out;
}

/** What the index depends on: the number of published posts and the latest change to one. */
function rrt_pron_signature() {
	global $wpdb;
	$row = $wpdb->get_row( "SELECT COUNT(*) AS n, MAX(post_modified_gmt) AS m FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish'", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return md5( RRT_VERSION . '|4|' . ( $row ? $row['n'] . '|' . $row['m'] : '' ) );
}

/**
 * The word index of the published posts: for each word, how it is written
 * and which posts have it; the words that may need a note (names and very
 * long words, as How hard counts them); and the posts with no reading yet.
 *
 * @return array words (key: w, p, n), cand (key: w, why, p), open (post ids as keys), titles (id: title),
 *               notname (keys never taken for names, 1.15.1).
 */
function rrt_pron_index() {
	$sig = rrt_pron_signature();
	$got = get_transient( RRT_PRON_INDEX );
	if ( is_array( $got ) && isset( $got['sig'] ) && $got['sig'] === $sig ) {
		return $got;
	}
	$q     = new WP_Query(
		array(
			'post_type'              => 'post',
			'post_status'            => 'publish',
			'posts_per_page'         => RRT_BACKLOG_MAX,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'ignore_sticky_posts'    => true,
		)
	);
	$words  = array();
	$cand   = array();
	$lower  = array(); // Words written in lower case somewhere, so ordinary words, never names.
	$runs   = array(); // Capitalised words in a row, inside sentences, to tell titles from names.
	$open   = array();
	$titles = array();
	$named  = array(); // Every word taken for a name in some post, before the rules below.
	foreach ( $q->posts as $p ) {
		$titles[ $p->ID ] = get_the_title( $p );
		$blocks           = rrt_blocks_from_html( get_the_title( $p ), strip_shortcodes( (string) $p->post_content ) );
		$audio            = rrt_reading_audio( $p->ID );
		if ( '' === $audio['url'] ) {
			$open[ $p->ID ] = true;
		}
		$seen = array();
		foreach ( rrt_pron_tokens( $blocks ) as $t ) {
			if ( '' !== $t['k'] && preg_match( '/^\p{Ll}/u', $t['w'] ) ) {
				$lower[ $t['k'] ] = true;
			}
			if ( '' === $t['k'] || isset( $seen[ $t['k'] ] ) ) {
				continue;
			}
			$seen[ $t['k'] ] = true;
			if ( ! isset( $words[ $t['k'] ] ) ) {
				$words[ $t['k'] ] = array(
					'w' => $t['w'],
					'p' => array(),
					'n' => 0,
				);
			}
			$words[ $t['k'] ]['n']++;
			if ( count( $words[ $t['k'] ]['p'] ) < RRT_PRON_POSTS ) {
				$words[ $t['k'] ]['p'][] = $p->ID;
			}
		}
		/*
		 * For names, each line of verse counts as its own block, so the capital a
		 * line starts with is not taken for a name (the teleprompter joins lines).
		 */
		$lines = rrt_backlog_lines( $p );
		$m     = rrt_backlog_measure( $lines, array(), array(), function_exists( 'rrt_post_lang' ) ? rrt_post_lang( $p->ID ) : 'en' );
		foreach ( $lines as $bl ) {
			if ( 'p' === $bl['type'] && count( $runs ) < 20000 ) {
				$runs = array_merge( $runs, rrt_pron_caps_runs( $bl['text'] ) );
			}
		}
		foreach ( $m['names'] as $w ) {
			$named[ rrt_note_key( $w ) ] = true;
		}
		foreach ( array( 'name' => $m['names'], 'long' => $m['big'] ) as $why => $list ) {
			foreach ( $list as $w ) {
				$k = rrt_note_key( $w );
				if ( '' !== $k && ! isset( $cand[ $k ] ) ) {
					$cand[ $k ] = array(
						'w'   => $w,
						'why' => $why,
					);
				}
			}
		}
	}
	/*
	 * A run of three words or more in capitals (small joining words such as
	 * "of" allowed) with an ordinary word in it is a title, like "Where
	 * Witches Gather" or "The Art of Witch": its words are only names if they
	 * also stand as one somewhere else.
	 */
	$titled = array();
	$free   = array();
	foreach ( $runs as $run ) {
		$is_title = false;
		if ( $run['len'] >= 3 ) {
			/* A capital that only starts the sentence ("Then Fiona Horne") does not make a title. */
			foreach ( $run['mid'] as $k ) {
				if ( isset( $lower[ $k ] ) ) {
					$is_title = true;
				}
			}
		}
		foreach ( $run['mid'] as $k ) {
			if ( $is_title ) {
				$titled[ $k ] = true;
			} else {
				$free[ $k ] = true;
			}
		}
	}
	/* 1.15.1: the same words are not names for How hard on Readings to make either. */
	$notname = array();
	foreach ( $named as $k => $yes ) {
		if ( isset( $lower[ $k ] ) || ( isset( $titled[ $k ] ) && ! isset( $free[ $k ] ) ) ) {
			$notname[ $k ] = true;
		}
	}
	foreach ( $cand as $k => $c ) {
		if ( 'name' === $c['why'] && isset( $notname[ $k ] ) ) {
			unset( $cand[ $k ] );
		}
	}
	$out = array(
		'sig'     => $sig,
		'words'   => $words,
		'cand'    => $cand,
		'open'    => $open,
		'titles'  => $titles,
		'notname' => $notname,
	);
	set_transient( RRT_PRON_INDEX, $out, DAY_IN_SECONDS );
	return $out;
}

/* The index is made again after a post is saved, trashed or deleted. */
foreach ( array( 'save_post_post', 'trashed_post', 'deleted_post' ) as $rrt_hook ) {
	add_action(
		$rrt_hook,
		function () {
			delete_transient( RRT_PRON_INDEX );
		}
	);
}

/** The words the reader marked "Not needed", as keys. */
function rrt_pron_skipped( $user_id = 0 ) {
	$s = get_user_meta( $user_id ? (int) $user_id : get_current_user_id(), RRT_PRON_SKIP, true );
	return is_array( $s ) ? array_fill_keys( array_map( 'strval', $s ), true ) : array();
}

/**
 * The posts with notes, taught words or cue marks that the reader may edit.
 *
 * @return int[]
 */
function rrt_pron_posts_with_words() {
	$q = new WP_Query(
		array(
			'post_type'              => 'any',
			'post_status'            => array( 'publish', 'draft', 'pending', 'future', 'private' ),
			'posts_per_page'         => RRT_BACKLOG_MAX,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'meta_query'             => array(
				'relation' => 'OR',
				array( 'key' => RRT_NOTES_META ),
				array( 'key' => RRT_LEARNED_POST_META ),
				array( 'key' => RRT_CUES_META ),
			),
		)
	);
	$out = array();
	foreach ( $q->posts as $id ) {
		if ( current_user_can( 'edit_post', $id ) ) {
			$out[] = (int) $id;
		}
	}
	return $out;
}

/**
 * Adds a set of taught words to the rows.
 *
 * @param array  $rows  Rows by id, changed in place.
 * @param array  $l     A clean set of taught words.
 * @param string $where 'all' or 'post'.
 * @param int    $post  The post, for 'post'.
 */
function rrt_pron_add_taught( &$rows, $l, $where, $post ) {
	foreach ( $l['pairs'] as $s => $heard ) {
		foreach ( $heard as $h => $yes ) {
			$e     = $s . '|' . $h;
			$key   = rrt_note_key( (string) $s );
			$row   = rrt_pron_row( $rows, $where, $post, '' !== $key ? $key : (string) $s, (string) $s );
			$label = isset( $l['labels'][ $e ] ) ? $l['labels'][ $e ] : '';
			$rows[ $row ]['taught'][] = array(
				'e'     => $e,
				'heard' => (string) $h,
				'label' => $label,
				'ign'   => false,
			);
		}
	}
	foreach ( $l['ignore'] as $w => $yes ) {
		$e   = '+' . $w;
		$key = rrt_note_key( (string) $w );
		$row = rrt_pron_row( $rows, $where, $post, '' !== $key ? $key : (string) $w, (string) $w );
		$rows[ $row ]['taught'][] = array(
			'e'     => $e,
			'heard' => (string) $w,
			'label' => isset( $l['labels'][ $e ] ) ? $l['labels'][ $e ] : '',
			'ign'   => true,
		);
	}
}

/**
 * The id of the row for a word at a place, made if needed.
 *
 * @param array  $rows  Rows by id, changed in place.
 * @param string $where 'all', 'post' or 'here'.
 * @param int    $post  The post (0 for 'all').
 * @param string $key   The word's key (for 'here', the word's place).
 * @param string $word  How to show the word when the posts do not have it.
 * @return string
 */
function rrt_pron_row( &$rows, $where, $post, $key, $word ) {
	$id = $where . ':' . (int) $post . ':' . $key;
	if ( ! isset( $rows[ $id ] ) ) {
		$rows[ $id ] = array(
			'id'     => $id,
			'where'  => $where,
			'post'   => (int) $post,
			'key'    => (string) $key,
			'word'   => (string) $word,
			'note'   => '',
			'taught' => array(),
		);
	}
	return $id;
}

/**
 * Every row of the list for a reader: their own notes and taught words, and
 * those of each post they may edit, including notes on one word only.
 *
 * @param int $user_id The reader.
 * @return array Rows, sorted by word.
 */
function rrt_pron_rows( $user_id ) {
	$index = rrt_pron_index();
	$rows  = array();
	foreach ( rrt_get_user_notes( $user_id ) as $n ) {
		$rows[ rrt_pron_row( $rows, 'all', 0, $n['k'], $n['k'] ) ]['note'] = $n['n'];
	}
	rrt_pron_add_taught( $rows, rrt_clean_learned( rrt_get_learned( $user_id ) ), 'all', 0 );
	foreach ( rrt_pron_posts_with_words() as $post_id ) {
		foreach ( rrt_get_post_notes( $post_id ) as $n ) {
			$rows[ rrt_pron_row( $rows, 'post', $post_id, $n['k'], $n['k'] ) ]['note'] = $n['n'];
		}
		rrt_pron_add_taught( $rows, rrt_get_post_learned( $post_id ), 'post', $post_id );
		$here = array();
		foreach ( rrt_get_cues( $post_id ) as $c ) {
			if ( '' !== $c['n'] ) {
				$here[] = $c;
			}
		}
		if ( $here ) {
			$tokens = rrt_pron_tokens( rrt_post_blocks( get_post( $post_id ) ) );
			foreach ( $here as $c ) {
				$id                   = rrt_pron_row( $rows, 'here', $post_id, (string) $c['i'], $c['k'] );
				$rows[ $id ]['note']  = $c['n'];
				$rows[ $id ]['i']     = $c['i'];
				$rows[ $id ]['wkey']  = $c['k'];
				$at                   = isset( $tokens[ $c['i'] ] ) && $tokens[ $c['i'] ]['k'] === $c['k'] ? $c['i'] : -1;
				$rows[ $id ]['para']  = $at >= 0 ? $tokens[ $at ]['b'] + 1 : 0;
				$rows[ $id ]['moved'] = $at < 0;
				if ( $at >= 0 ) {
					$rows[ $id ]['word'] = $tokens[ $at ]['w'];
					$snip                = array();
					for ( $j = max( 0, $at - 5 ); $j <= min( count( $tokens ) - 1, $at + 5 ); $j++ ) {
						$snip[] = $tokens[ $j ]['t'];
					}
					$rows[ $id ]['snip'] = implode( ' ', $snip );
				}
			}
		}
	}
	$out = array();
	foreach ( $rows as $r ) {
		$wk = 'here' === $r['where'] ? $r['wkey'] : $r['key'];
		if ( 'here' !== $r['where'] && isset( $index['words'][ $wk ] ) ) {
			$r['word'] = $index['words'][ $wk ]['w'];
		}
		$r['count'] = isset( $index['words'][ $wk ] ) ? $index['words'][ $wk ]['n'] : 0;
		$r['in']    = array();
		$r['first'] = isset( $index['words'][ $wk ] ) ? (int) $index['words'][ $wk ]['p'][0] : 0;
		if ( isset( $index['words'][ $wk ] ) ) {
			foreach ( array_slice( $index['words'][ $wk ]['p'], 0, 5 ) as $p ) {
				$r['in'][] = isset( $index['titles'][ $p ] ) ? $index['titles'][ $p ] : '';
			}
		}
		if ( $r['post'] ) {
			$r['title'] = get_the_title( $r['post'] );
			$r['open']  = rrt_url( $r['post'] );
		}
		$out[] = $r;
	}
	usort(
		$out,
		function ( $a, $b ) {
			$c = strcmp( mb_strtolower( $a['word'] ), mb_strtolower( $b['word'] ) );
			return $c ? $c : strcmp( $a['id'], $b['id'] );
		}
	);
	return $out;
}

/**
 * Words from the published posts that may need a note: names and very long
 * words with no note, no taught word and no "Not needed" yet. Posts with no
 * reading come first, since those are still to be read.
 *
 * @param int   $user_id The reader.
 * @param array $rows    The reader's rows, from rrt_pron_rows().
 * @return array Each: k, w, why, n (posts), open (posts with no reading), titles, post (one with no reading, or 0).
 */
function rrt_pron_suggest( $user_id, $rows ) {
	$index = rrt_pron_index();
	$known = rrt_pron_skipped( $user_id );
	foreach ( $rows as $r ) {
		$known[ 'here' === $r['where'] ? $r['wkey'] : $r['key'] ] = true;
	}
	$out = array();
	foreach ( $index['cand'] as $k => $c ) {
		if ( isset( $known[ $k ] ) || ! isset( $index['words'][ $k ] ) ) {
			continue;
		}
		$posts = $index['words'][ $k ]['p'];
		$open  = array();
		foreach ( $posts as $p ) {
			if ( isset( $index['open'][ $p ] ) ) {
				$open[] = $p;
			}
		}
		$titles = array();
		foreach ( array_slice( $open ? $open : $posts, 0, 3 ) as $p ) {
			$titles[] = isset( $index['titles'][ $p ] ) ? $index['titles'][ $p ] : '';
		}
		$out[] = array(
			'k'      => (string) $k,
			'w'      => $c['w'],
			'why'    => $c['why'],
			'n'      => $index['words'][ $k ]['n'],
			'open'   => count( $open ),
			'titles' => $titles,
			'post'   => $open ? (int) $open[0] : 0,
		);
	}
	usort(
		$out,
		function ( $a, $b ) {
			if ( $a['open'] !== $b['open'] ) {
				return $b['open'] - $a['open'];
			}
			if ( $a['n'] !== $b['n'] ) {
				return $b['n'] - $a['n'];
			}
			return strcmp( mb_strtolower( $a['w'] ), mb_strtolower( $b['w'] ) );
		}
	);
	foreach ( $out as &$s ) {
		if ( $s['post'] ) {
			$s['openUrl'] = rrt_url( $s['post'] );
		}
	}
	unset( $s );
	return array(
		'list'  => array_slice( $out, 0, RRT_PRON_SUGGEST ),
		'total' => count( $out ),
	);
}

/**
 * The posts the reader can choose for "Just this post": every post they may
 * edit, by title.
 *
 * @return array Each: id, title.
 */
function rrt_pron_post_choices() {
	$q   = new WP_Query(
		array(
			'post_type'              => 'post',
			'post_status'            => array( 'publish', 'draft', 'pending', 'future', 'private' ),
			'posts_per_page'         => RRT_BACKLOG_MAX,
			'orderby'                => 'title',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
		)
	);
	$out = array();
	foreach ( $q->posts as $p ) {
		if ( current_user_can( 'edit_post', $p->ID ) ) {
			$t     = get_the_title( $p );
			$out[] = array(
				'id'    => $p->ID,
				'title' => '' !== $t ? $t : __( '(no title)', 'foxfire-studio' ),
			);
		}
	}
	return $out;
}

/** Everything the screen shows, for the current reader. */
function rrt_pron_state() {
	$user_id = get_current_user_id();
	$rows    = rrt_pron_rows( $user_id );
	return array(
		'rows'    => $rows,
		'suggest' => rrt_pron_suggest( $user_id, $rows ),
	);
}

/*
 * ---------------------------------------------------------------------------
 * Changes
 * ---------------------------------------------------------------------------
 */

/**
 * An error the screen shows in its line.
 *
 * @param string $msg    The message.
 * @param int    $status HTTP status.
 * @param array  $more   More data for the screen.
 * @return WP_Error
 */
function rrt_pron_error( $msg, $status = 400, $more = array() ) {
	return new WP_Error( 'rrt_pron', $msg, array_merge( array( 'status' => $status ), $more ) );
}

/** True when the reader may change words kept at a place. */
function rrt_pron_can( $where, $post ) {
	if ( 'all' === $where ) {
		return is_user_logged_in();
	}
	return $post > 0 && get_post( $post ) && current_user_can( 'edit_post', $post );
}

/** A post's or the reader's taught words. */
function rrt_pron_learned( $where, $post ) {
	return 'all' === $where ? rrt_clean_learned( rrt_get_learned() ) : rrt_get_post_learned( $post );
}

/** Saves a post's or the reader's taught words in full (they are already merged and settled). */
function rrt_pron_learned_put( $where, $post, $l ) {
	if ( 'all' === $where ) {
		update_user_meta( get_current_user_id(), 'rrt_learned', $l );
		return;
	}
	if ( empty( $l['pairs'] ) && empty( $l['ignore'] ) && empty( $l['labels'] ) && empty( $l['forgot'] ) ) {
		delete_post_meta( $post, RRT_LEARNED_POST_META );
	} else {
		update_post_meta( $post, RRT_LEARNED_POST_META, $l );
	}
}

/** The note kept for a word at a place ('all' or 'post'), or ''. */
function rrt_pron_note_at( $where, $post, $key ) {
	foreach ( 'all' === $where ? rrt_get_user_notes() : rrt_get_post_notes( $post ) as $n ) {
		if ( $n['k'] === $key ) {
			return $n['n'];
		}
	}
	return '';
}

/** Sets or removes ('') the note for a word at a place ('all' or 'post'). */
function rrt_pron_note_put( $where, $post, $key, $note ) {
	$have = 'all' === $where ? rrt_get_user_notes() : rrt_get_post_notes( $post );
	rrt_save_notes( 'all' === $where ? 'all' : 'post', $post, rrt_notes_apply( $have, array( $key => $note ) ) );
}

/**
 * Sets or removes the note on one word only (kept in the post's cue marks).
 *
 * @return true|WP_Error
 */
function rrt_pron_here_put( $post, $i, $wkey, $note ) {
	$cues = rrt_get_cues( $post );
	foreach ( $cues as $j => $c ) {
		if ( $c['i'] === $i && $c['k'] === $wkey ) {
			$cues[ $j ]['n'] = $note;
			rrt_save_cues( $post, $cues );
			return true;
		}
	}
	return rrt_pron_error( __( 'That note is no longer on its word. Open the post in the studio once, so its marks find their words again, then try here.', 'foxfire-studio' ), 409 );
}

/** The note on one word only, or ''. */
function rrt_pron_here_note( $post, $i, $wkey ) {
	foreach ( rrt_get_cues( $post ) as $c ) {
		if ( $c['i'] === $i && $c['k'] === $wkey ) {
			return $c['n'];
		}
	}
	return '';
}

/**
 * Moves a row to another place: its note, and its taught words unless it is
 * a note on one word only. If both places have different notes for the
 * word, nothing changes until $keep says which ('from' or 'to').
 *
 * @return true|WP_Error
 */
function rrt_pron_move( $from, $to, $keep ) {
	$from_note = 'here' === $from['where'] ? rrt_pron_here_note( $from['post'], $from['i'], $from['wkey'] ) : rrt_pron_note_at( $from['where'], $from['post'], $from['key'] );
	$key       = 'here' === $from['where'] ? $from['wkey'] : $from['key'];
	$to_note   = rrt_pron_note_at( $to['where'], $to['post'], $key );
	if ( '' !== $from_note && '' !== $to_note && $from_note !== $to_note && ! in_array( $keep, array( 'from', 'to' ), true ) ) {
		return rrt_pron_error(
			__( 'Both places have a note for this word. Choose which to keep.', 'foxfire-studio' ),
			409,
			array(
				'clash' => array(
					'from' => $from_note,
					'to'   => $to_note,
				),
			)
		);
	}
	if ( '' !== $from_note && 'to' !== $keep ) {
		rrt_pron_note_put( $to['where'], $to['post'], $key, $from_note );
	}
	if ( 'here' === $from['where'] ) {
		$done = rrt_pron_here_put( $from['post'], $from['i'], $from['wkey'], '' );
		if ( is_wp_error( $done ) ) {
			return $done;
		}
		return true;
	}
	if ( '' !== $from_note ) {
		rrt_pron_note_put( $from['where'], $from['post'], $key, '' );
	}
	/* Taught words go too: added to the new place as taught now, and forgotten at the old one. */
	$src  = rrt_pron_learned( $from['where'], $from['post'] );
	$dst  = rrt_pron_learned( $to['where'], $to['post'] );
	$now  = (int) round( microtime( true ) * 1000 );
	$move = false;
	foreach ( array_keys( rrt_learned_entries( $src ) ) as $e ) {
		$word = '+' === substr( $e, 0, 1 ) ? substr( $e, 1 ) : substr( $e, 0, (int) strpos( $e, '|' ) );
		if ( rrt_note_key( $word ) !== $key && $word !== $key ) {
			continue;
		}
		if ( '+' === substr( $e, 0, 1 ) ) {
			$dst['ignore'][ substr( $e, 1 ) ] = true;
		} else {
			$dst['pairs'][ $word ][ substr( $e, strpos( $e, '|' ) + 1 ) ] = true;
		}
		if ( isset( $src['labels'][ $e ] ) ) {
			$dst['labels'][ $e ] = $src['labels'][ $e ];
		}
		$dst['at'][ $e ] = $now;
		$src             = rrt_learned_forget( $src, $e );
		$move            = true;
	}
	if ( $move ) {
		rrt_pron_learned_put( $to['where'], $to['post'], rrt_learned_settle( $dst ) );
		rrt_pron_learned_put( $from['where'], $from['post'], $src );
	}
	return true;
}

/**
 * A place from a request: where, post, and for one word only its place i and word key.
 *
 * @param mixed $p As sent.
 * @return array|null
 */
function rrt_pron_place( $p ) {
	if ( ! is_array( $p ) || ! isset( $p['where'] ) || ! in_array( $p['where'], array( 'all', 'post', 'here' ), true ) ) {
		return null;
	}
	$out = array(
		'where' => $p['where'],
		'post'  => 'all' === $p['where'] ? 0 : ( isset( $p['post'] ) ? absint( $p['post'] ) : 0 ),
		'key'   => rrt_note_key( isset( $p['key'] ) ? (string) $p['key'] : '' ),
		'i'     => isset( $p['i'] ) ? (int) $p['i'] : -1,
		'wkey'  => rrt_note_key( isset( $p['wkey'] ) ? (string) $p['wkey'] : '' ),
	);
	if ( ! rrt_pron_can( $out['where'], $out['post'] ) ) {
		return null;
	}
	if ( 'here' === $out['where'] ? ( $out['i'] < 0 || '' === $out['wkey'] ) : '' === $out['key'] ) {
		return null;
	}
	return $out;
}

/*
 * ---------------------------------------------------------------------------
 * Backup and import
 * ---------------------------------------------------------------------------
 */

/** Everything on the list for the current reader, as a backup. */
function rrt_pron_backup() {
	$posts = array();
	foreach ( rrt_pron_posts_with_words() as $id ) {
		$p    = get_post( $id );
		$here = array();
		foreach ( rrt_get_cues( $id ) as $c ) {
			if ( '' !== $c['n'] ) {
				$here[] = array(
					'i' => $c['i'],
					'k' => $c['k'],
					'n' => $c['n'],
				);
			}
		}
		$notes   = rrt_get_post_notes( $id );
		$learned = rrt_get_post_learned( $id );
		if ( ! $notes && ! $here && ! rrt_learned_entries( $learned ) ) {
			continue;
		}
		$posts[] = array(
			'id'      => $id,
			'slug'    => $p->post_name,
			'title'   => get_the_title( $p ),
			'notes'   => $notes,
			'learned' => $learned,
			'here'    => $here,
		);
	}
	return array(
		'format'   => RRT_PRON_FORMAT,
		'version'  => 1,
		'exported' => gmdate( 'c' ),
		'site'     => home_url( '/' ),
		'mine'     => array(
			'notes'   => rrt_get_user_notes(),
			'learned' => rrt_clean_learned( rrt_get_learned() ),
		),
		'posts'    => $posts,
	);
}

/**
 * The post a backup's post belongs to on this site: the same post on the
 * same site, otherwise the post with the same slug. 0 when there is none.
 */
function rrt_pron_match_post( $fp, $same_site ) {
	$slug = isset( $fp['slug'] ) ? sanitize_title( (string) $fp['slug'] ) : '';
	if ( $same_site && ! empty( $fp['id'] ) ) {
		$p = get_post( absint( $fp['id'] ) );
		if ( $p && 'trash' !== $p->post_status && ( '' === $slug || $p->post_name === $slug ) ) {
			return current_user_can( 'edit_post', $p->ID ) ? $p->ID : 0;
		}
	}
	if ( '' !== $slug ) {
		$found = get_posts(
			array(
				'name'           => $slug,
				'post_type'      => 'any',
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		if ( $found && current_user_can( 'edit_post', $found[0] ) ) {
			return (int) $found[0];
		}
	}
	return 0;
}

/**
 * Reads a backup and, when $apply is true, adds it to what is here. Nothing
 * here is replaced unless $keep says 'file' for that clash; a clash with no
 * choice keeps what is here.
 *
 * @param mixed $data  The backup as sent.
 * @param bool  $apply False for a preview.
 * @param mixed $keep  Clash id: 'file' or 'mine'.
 * @return array|WP_Error add, same, clashes (each id, word, where, title, mine, file), skipped (titles).
 */
function rrt_pron_import( $data, $apply, $keep ) {
	if ( ! is_array( $data ) || ! isset( $data['format'] ) || RRT_PRON_FORMAT !== $data['format'] ) {
		return rrt_pron_error( __( 'This file is not a Foxfire Studio pronunciation backup.', 'foxfire-studio' ) );
	}
	$keep      = is_array( $keep ) ? $keep : array();
	$same_site = isset( $data['site'] ) && untrailingslashit( (string) $data['site'] ) === untrailingslashit( home_url( '/' ) );
	$res       = array(
		'add'     => 0,
		'same'    => 0,
		'clashes' => array(),
		'skipped' => array(),
	);
	$places    = array();
	$index     = rrt_pron_index();
	$show      = function ( $k ) use ( $index ) {
		return isset( $index['words'][ $k ] ) ? $index['words'][ $k ]['w'] : $k;
	};
	if ( isset( $data['mine'] ) && is_array( $data['mine'] ) ) {
		$places[] = array(
			'where'   => 'all',
			'post'    => 0,
			'title'   => '',
			'notes'   => isset( $data['mine']['notes'] ) ? $data['mine']['notes'] : array(),
			'learned' => isset( $data['mine']['learned'] ) ? $data['mine']['learned'] : array(),
			'here'    => array(),
		);
	}
	foreach ( isset( $data['posts'] ) && is_array( $data['posts'] ) ? array_slice( $data['posts'], 0, RRT_BACKLOG_MAX ) : array() as $fp ) {
		if ( ! is_array( $fp ) ) {
			continue;
		}
		$id = rrt_pron_match_post( $fp, $same_site );
		if ( ! $id ) {
			$res['skipped'][] = isset( $fp['title'] ) ? sanitize_text_field( (string) $fp['title'] ) : '';
			continue;
		}
		$places[] = array(
			'where'   => 'post',
			'post'    => $id,
			'title'   => get_the_title( $id ),
			'notes'   => isset( $fp['notes'] ) ? $fp['notes'] : array(),
			'learned' => isset( $fp['learned'] ) ? $fp['learned'] : array(),
			'here'    => isset( $fp['here'] ) && is_array( $fp['here'] ) ? $fp['here'] : array(),
		);
	}
	foreach ( $places as $pl ) {
		/* Notes for every use of a word. */
		$have    = array();
		$changes = array();
		foreach ( 'all' === $pl['where'] ? rrt_get_user_notes() : rrt_get_post_notes( $pl['post'] ) as $n ) {
			$have[ $n['k'] ] = $n['n'];
		}
		foreach ( rrt_clean_notes( $pl['notes'] ) as $n ) {
			if ( ! isset( $have[ $n['k'] ] ) ) {
				$res['add']++;
				$changes[ $n['k'] ] = $n['n'];
			} elseif ( $have[ $n['k'] ] === $n['n'] ) {
				$res['same']++;
			} else {
				$cid                = $pl['where'] . ':' . $pl['post'] . ':' . $n['k'];
				$res['clashes'][]   = array(
					'id'    => $cid,
					'word'  => $show( $n['k'] ),
					'where' => $pl['where'],
					'title' => $pl['title'],
					'mine'  => $have[ $n['k'] ],
					'file'  => $n['n'],
				);
				if ( isset( $keep[ $cid ] ) && 'file' === $keep[ $cid ] ) {
					$changes[ $n['k'] ] = $n['n'];
				}
			}
		}
		if ( $apply && $changes ) {
			$list = 'all' === $pl['where'] ? rrt_get_user_notes() : rrt_get_post_notes( $pl['post'] );
			rrt_save_notes( 'all' === $pl['where'] ? 'all' : 'post', $pl['post'], rrt_notes_apply( $list, $changes ) );
		}
		/* Taught words: merged, so a word forgotten here after the file was made stays forgotten. */
		$mine   = rrt_pron_learned( $pl['where'], $pl['post'] );
		$file   = rrt_clean_learned( $pl['learned'] );
		$merged = rrt_merge_learned( $mine, $file );
		$before = rrt_learned_entries( $mine );
		$after  = rrt_learned_entries( $merged );
		foreach ( array_keys( rrt_learned_entries( $file ) ) as $e ) {
			if ( isset( $before[ $e ] ) ) {
				$res['same']++;
			} elseif ( isset( $after[ $e ] ) ) {
				$res['add']++;
			}
		}
		if ( $apply && ! rrt_learned_same( $mine, $merged ) ) {
			rrt_pron_learned_put( $pl['where'], $pl['post'], $merged );
		}
		/* Notes on one word only, kept in the cue marks. */
		if ( 'post' === $pl['where'] && $pl['here'] ) {
			$cues = rrt_get_cues( $pl['post'] );
			$by   = array();
			foreach ( $cues as $j => $c ) {
				$by[ $c['i'] ] = $j;
			}
			$changed = false;
			foreach ( array_slice( $pl['here'], 0, RRT_CUES_MAX ) as $h ) {
				if ( ! is_array( $h ) || ! isset( $h['i'] ) || ! is_numeric( $h['i'] ) ) {
					continue;
				}
				$i = (int) $h['i'];
				$k = rrt_note_key( isset( $h['k'] ) ? (string) $h['k'] : '' );
				$n = rrt_note_text( isset( $h['n'] ) ? $h['n'] : '' );
				if ( '' === $n || '' === $k ) {
					continue;
				}
				if ( isset( $by[ $i ] ) && $cues[ $by[ $i ] ]['k'] === $k ) {
					$was = $cues[ $by[ $i ] ]['n'];
					if ( $was === $n ) {
						$res['same']++;
						continue;
					}
					if ( '' !== $was ) {
						$cid              = 'here:' . $pl['post'] . ':' . $i;
						$res['clashes'][] = array(
							'id'    => $cid,
							'word'  => $show( $k ),
							'where' => 'here',
							'title' => $pl['title'],
							'mine'  => $was,
							'file'  => $n,
						);
						if ( ! ( isset( $keep[ $cid ] ) && 'file' === $keep[ $cid ] ) ) {
							continue;
						}
					} else {
						$res['add']++;
					}
					$cues[ $by[ $i ] ]['n'] = $n;
					$changed                = true;
				} elseif ( ! isset( $by[ $i ] ) ) {
					$res['add']++;
					$cues[]     = array(
						'i' => $i,
						'k' => $k,
						'a' => '',
						'e' => false,
						'f' => false,
						'n' => $n,
					);
					$by[ $i ]   = count( $cues ) - 1;
					$changed    = true;
				}
			}
			if ( $apply && $changed ) {
				rrt_save_cues( $pl['post'], $cues );
			}
		}
	}
	return $res;
}

/** True when two clean sets of taught words hold the same words and times. */
function rrt_learned_same( $a, $b ) {
	$sig = function ( $l ) {
		$e = array_keys( rrt_learned_entries( $l ) );
		sort( $e );
		$at = $l['at'];
		ksort( $at );
		$fo = $l['forgot'];
		ksort( $fo );
		return wp_json_encode( array( $e, $at, $fo ) );
	};
	return $sig( $a ) === $sig( $b );
}

/*
 * ---------------------------------------------------------------------------
 * REST routes
 * ---------------------------------------------------------------------------
 */
add_action(
	'rest_api_init',
	function () {
		$can = function () {
			return is_user_logged_in() && current_user_can( 'edit_posts' );
		};
		register_rest_route(
			'rrt/v1',
			'/pronounce',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => $can,
					'callback'            => function () {
						return rest_ensure_response( rrt_pron_state() );
					},
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => $can,
					'callback'            => 'rrt_pron_route',
				),
			)
		);
		register_rest_route(
			'rrt/v1',
			'/pronounce/backup',
			array(
				'methods'             => 'GET',
				'permission_callback' => $can,
				'callback'            => function () {
					return rest_ensure_response( rrt_pron_backup() );
				},
			)
		);
	}
);

/**
 * One change from the screen. do: note, add, move, forget, skip, unskip or
 * import. Answers with the screen's new state (and an import's summary).
 *
 * @param WP_REST_Request $req The request.
 * @return WP_REST_Response|WP_Error
 */
function rrt_pron_route( $req ) {
	$do = (string) $req['do'];
	if ( 'note' === $do ) {
		$at = rrt_pron_place( $req['place'] );
		if ( ! $at ) {
			return rrt_pron_error( __( 'You cannot change notes there.', 'foxfire-studio' ), 403 );
		}
		$note = rrt_note_text( $req['note'] );
		if ( 'here' === $at['where'] ) {
			$done = rrt_pron_here_put( $at['post'], $at['i'], $at['wkey'], $note );
			if ( is_wp_error( $done ) ) {
				return $done;
			}
		} else {
			rrt_pron_note_put( $at['where'], $at['post'], $at['key'], $note );
		}
	} elseif ( 'add' === $do ) {
		$where = 'post' === $req['where'] ? 'post' : 'all';
		$post  = 'post' === $where ? absint( $req['post'] ) : 0;
		$key   = rrt_note_key( (string) $req['word'] );
		$note  = rrt_note_text( $req['note'] );
		if ( '' === $key || '' === $note ) {
			return rrt_pron_error( __( 'Write the word and how to say it.', 'foxfire-studio' ) );
		}
		if ( ! rrt_pron_can( $where, $post ) ) {
			return rrt_pron_error( __( 'Choose a post you can edit.', 'foxfire-studio' ), 403 );
		}
		$was = rrt_pron_note_at( $where, $post, $key );
		if ( '' !== $was && $was !== $note && 'replace' !== $req['keep'] ) {
			return rrt_pron_error(
				__( 'This word already has a note there.', 'foxfire-studio' ),
				409,
				array(
					'clash' => array(
						'from' => $note,
						'to'   => $was,
					),
				)
			);
		}
		rrt_pron_note_put( $where, $post, $key, $note );
	} elseif ( 'move' === $do ) {
		$from = rrt_pron_place( $req['from'] );
		$to   = rrt_pron_place( is_array( $req['to'] ) ? array_merge( $req['to'], array( 'key' => 'x' ) ) : null );
		if ( ! $from || ! $to || 'here' === $to['where'] ) {
			return rrt_pron_error( __( 'You cannot move this word there.', 'foxfire-studio' ), 403 );
		}
		if ( $from['where'] === $to['where'] && $from['post'] === $to['post'] ) {
			return rrt_pron_error( __( 'The word is already kept there.', 'foxfire-studio' ) );
		}
		$done = rrt_pron_move( $from, $to, (string) $req['keep'] );
		if ( is_wp_error( $done ) ) {
			return $done;
		}
	} elseif ( 'forget' === $do ) {
		$at    = rrt_pron_place( $req['place'] );
		$entry = mb_substr( (string) $req['entry'], 0, 130 );
		if ( ! $at || 'here' === $at['where'] || '' === $entry ) {
			return rrt_pron_error( __( 'You cannot change taught words there.', 'foxfire-studio' ), 403 );
		}
		rrt_pron_learned_put( $at['where'], $at['post'], rrt_learned_forget( rrt_pron_learned( $at['where'], $at['post'] ), $entry ) );
	} elseif ( 'skip' === $do || 'unskip' === $do ) {
		$key  = rrt_note_key( (string) $req['key'] );
		$list = array_keys( rrt_pron_skipped() );
		if ( '' !== $key ) {
			$list = array_values( array_diff( $list, array( $key ) ) );
			if ( 'skip' === $do ) {
				$list[] = $key;
			}
			$list = array_slice( $list, -2000 );
			if ( $list ) {
				update_user_meta( get_current_user_id(), RRT_PRON_SKIP, $list );
			} else {
				delete_user_meta( get_current_user_id(), RRT_PRON_SKIP );
			}
		}
	} elseif ( 'import' === $do ) {
		$res = rrt_pron_import( $req['data'], (bool) $req['apply'], $req['keep'] );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return rest_ensure_response( array_merge( rrt_pron_state(), array( 'import' => $res ) ) );
	} else {
		return rrt_pron_error( __( 'Unknown change.', 'foxfire-studio' ) );
	}
	return rest_ensure_response( rrt_pron_state() );
}

/*
 * ---------------------------------------------------------------------------
 * The screen
 * ---------------------------------------------------------------------------
 */
/* The menu item is added in dashboard.php, after Readings to make. */

/** Foxfire Studio, Pronunciation. */
function rrt_pron_page() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die( esc_html__( 'Not allowed.', 'foxfire-studio' ) );
	}
	wp_enqueue_script( 'rrt-pronounce', RRT_URL . 'assets/js/pronounce.js', array( 'wp-i18n' ), RRT_VERSION . '.' . filemtime( RRT_DIR . 'assets/js/pronounce.js' ), true );
	wp_set_script_translations( 'rrt-pronounce', 'foxfire-studio', rrt_languages_dir() );
	$boot = array_merge(
		rrt_pron_state(),
		array(
			'posts' => rrt_pron_post_choices(),
			'rest'  => esc_url_raw( rest_url( 'rrt/v1/pronounce' ) ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'max'   => RRT_NOTE_LEN,
		)
	);
	wp_add_inline_script( 'rrt-pronounce', 'window.RRTPronBoot = ' . wp_json_encode( $boot ) . ';', 'before' );
	rrt_dash_header( __( 'Pronunciation', 'foxfire-studio' ), 'rrt-pronounce', array(), array(), false );
	echo '<p>' . esc_html__( 'Every pronunciation note and every word taught to Whisper, wherever it is kept. Click a note to change it. A studio tab that is already open shows changes made here once it is reloaded (Ctrl+Shift+R).', 'foxfire-studio' ) . '</p>';
	echo '<div id="rrt-pron" class="rrt-pron"><p class="rrt-small">' . esc_html__( 'Loading…', 'foxfire-studio' ) . '</p></div>';
	rrt_dash_defs(
		array(
			__( 'Note', 'foxfire-studio' )                  => __( 'How to say the word, shown small in gold above it in the teleprompter. At most 40 characters.', 'foxfire-studio' ),
			__( 'Whisper hears it as', 'foxfire-studio' )   => __( 'What Whisper wrote down when you said the word correctly and clicked “I said it right” in the review. The review then counts it as read. An ignored word is one Whisper heard that is not in the script.', 'foxfire-studio' ),
			__( 'Where', 'foxfire-studio' )                 => __( 'In every post: your own, used in all your readings. A post’s title: every use of the word in that post, for anyone who edits it. Only here: that one word in that post. The nearest one wins: only here, then the post, then every post.', 'foxfire-studio' ),
			__( 'In posts', 'foxfire-studio' )              => __( 'How many published posts have the word. Hover for some of their titles.', 'foxfire-studio' ),
			__( 'Words that may need a note', 'foxfire-studio' ) => __( 'Names (a capital inside a sentence) and words of five syllables or more in your published posts, with no note or taught word yet. Posts with no reading come first. Not needed takes a word off this list for good, until you bring it back.', 'foxfire-studio' ),
			__( 'Backup', 'foxfire-studio' )                => __( 'A file with everything on this list, which Import can read on this site or another. Import shows what it would add first, adds to what is here, and only replaces a note where you choose to.', 'foxfire-studio' ),
		)
	);
	echo '</div>';
}
