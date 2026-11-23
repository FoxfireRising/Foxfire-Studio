<?php
/**
 * Readings to make (1.4.0): the published posts that have no reading yet,
 * each with its length, an estimate of how long it will run at the reader's
 * own pace, and how hard it looks to read aloud, with Start and Hide.
 *
 * How hard is measured per 100 words (a shorter post counts as 100), so a
 * long post is not called hard for being long, nor a short one for one name. It counts long sentences (over RRT_BACKLOG_LONG_SENTENCE
 * words), names (capitalised words inside a sentence), very long words (five
 * syllables or more) and words that have tripped the reader or Whisper
 * before: words with a pronunciation note (every post, or this post) and
 * words taught to Whisper (every post, or this post).
 *
 * The only thing stored is the list of hidden posts, in the option
 * rrt_backlog_hidden. Nothing about visitors is involved.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RRT_BACKLOG_HIDDEN', 'rrt_backlog_hidden' );
define( 'RRT_BACKLOG_LONG_SENTENCE', 30 );
define( 'RRT_BACKLOG_MAX', 3000 ); // The most posts the list looks at.
define( 'RRT_BACKLOG_LONG_WORD', 13 ); // 1.33.0: letters that make a very long word outside English.

/** Syllables in a word, the way the studio counts them for its timing. */
function rrt_backlog_syllables( $word ) {
	$w = rrt_note_key( $word );
	if ( '' === $w ) {
		return 1;
	}
	$digits = strlen( preg_replace( '/[^0-9]/', '', $w ) );
	if ( $digits ) {
		return max( 1, (int) round( $digits * 1.5 ) );
	}
	if ( mb_strlen( $w ) <= 3 ) {
		return 1;
	}
	$w = preg_replace( '/(?:[^laeiouy]es|[^laeiouy]ed|[^laeiouy]e)$/u', '', $w );
	$w = preg_replace( '/^y/u', '', $w );
	$n = preg_match_all( '/[aeiouy\x{00E0}-\x{00FF}]+/u', $w );
	return max( 1, (int) $n );
}

/** Capitalised words that are easy to say however often they come up. */
function rrt_backlog_plain_capitals() {
	return array( 'i', 'im', 'ive', 'ill', 'id', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday', 'january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december', 'god', 'mr', 'mrs', 'ms', 'dr' );
}

/**
 * Words that have tripped the reader or Whisper before, for one post: the
 * reader's notes and taught words for every post, and the post's own.
 *
 * @param int   $post_id Post ID.
 * @param array $own     The reader's own words (from rrt_backlog_own_words()).
 * @return array Word keys as keys.
 */
function rrt_backlog_known_words( $post_id, $own ) {
	$out = $own;
	foreach ( rrt_get_post_notes( $post_id ) as $n ) {
		$out[ $n['k'] ] = true;
	}
	$l = rrt_get_post_learned( $post_id );
	foreach ( array_merge( array_keys( $l['pairs'] ), array_keys( $l['ignore'] ) ) as $w ) {
		$k = rrt_note_key( (string) $w );
		if ( '' !== $k ) {
			$out[ $k ] = true;
		}
	}
	return $out;
}

/** The reader's own notes and taught words, for every post. */
function rrt_backlog_own_words( $user_id ) {
	$out = array();
	foreach ( rrt_get_user_notes( $user_id ) as $n ) {
		$out[ $n['k'] ] = true;
	}
	$l = rrt_get_learned( $user_id );
	foreach ( array_merge( array_keys( $l['pairs'] ), array_keys( $l['ignore'] ) ) as $w ) {
		$k = rrt_note_key( (string) $w );
		if ( '' !== $k ) {
			$out[ $k ] = true;
		}
	}
	return $out;
}

/**
 * A post's script with each line of verse as its own block (1.15.1), so the
 * capital a line starts with is not taken for a name and the end of a line
 * counts as a place to breathe. The words are the same as the teleprompter's.
 *
 * @param WP_Post $p The post.
 * @return array As rrt_blocks_from_html() gives them.
 */
function rrt_backlog_lines( $p ) {
	return rrt_blocks_from_html( get_the_title( $p ), preg_replace( '#<br\s*/?>#i', '</p><p>', strip_shortcodes( (string) $p->post_content ) ) );
}

/**
 * Word keys never counted as names on this site for this reader (1.15.1):
 * those the Pronunciation screen's index found written in lower case or only
 * inside titles, and those the reader marked Not needed.
 *
 * @param int $user_id The reader.
 * @return array Keys as keys.
 */
function rrt_backlog_not_names( $user_id ) {
	if ( ! function_exists( 'rrt_pron_index' ) ) {
		return array();
	}
	$index = rrt_pron_index();
	$not   = isset( $index['notname'] ) && is_array( $index['notname'] ) ? $index['notname'] : array();
	return $not + rrt_pron_skipped( $user_id );
}

/**
 * Measures a script: words, sentences and what makes it harder to read.
 *
 * @param array $blocks As rrt_blocks_from_html() gives them.
 * @param array $known  Word keys that tripped the reader or Whisper before, as keys.
 * @param array $plain_words Word keys that are never names here, as keys (1.15.1: words written in lower case
 *                           somewhere, words only seen in titles, and words the reader marked Not needed).
 * @param string $lang       The post's reading language (1.33.0). Outside English a very long word is one of
 *                           RRT_BACKLOG_LONG_WORD letters or more (syllables are counted the English way), and
 *                           names are not counted in languages that give every noun a capital.
 * @return array words, long (sentences over the limit), names, big (very long words) and known (each a list of distinct words), score (per 100 words) and level (easy, care or hard).
 */
function rrt_backlog_measure( $blocks, $known, $plain_words = array(), $lang = 'en' ) {
	$plain = array_flip( rrt_backlog_plain_capitals() ) + $plain_words;
	$en    = 'en' === $lang;
	$named = $en || ! function_exists( 'rrt_lang_name_rule' ) || rrt_lang_name_rule( $lang );
	$words = 0;
	$long  = 0;
	$names = array();
	$big   = array();
	$met   = array();
	foreach ( $blocks as $b ) {
		$tokens = preg_split( '/\s+/u', trim( $b['text'] ), -1, PREG_SPLIT_NO_EMPTY );
		$words += count( $tokens );
		$in     = 0; // Words so far in the sentence.
		$after  = false; // The word before ended a clause that may start with a capital.
		foreach ( $tokens as $t ) {
			$in++;
			/* The word without the punctuation around it, or a possessive 's, so "Siobhan's" is Siobhan. */
			$bare = preg_replace( '/[\x{2019}\']s$/u', '', preg_replace( '/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', $t ) );
			$k    = rrt_note_key( $bare );
			if ( '' !== $k ) {
				if ( isset( $known[ $k ] ) ) {
					$met[ $k ] = $bare;
				}
				if ( ! preg_match( '/\d/u', $bare ) && ( $en ? rrt_backlog_syllables( $t ) >= 5 : mb_strlen( $bare ) >= RRT_BACKLOG_LONG_WORD ) ) {
					$big[ $k ] = $bare;
				}
				$opens = (bool) preg_match( '/^[\x{201C}\x{2018}"\'(\[]/u', $t );
				if ( $named && 'p' === $b['type'] && $in > 1 && ! $after && ! $opens && preg_match( '/^\p{Lu}.*\p{Ll}/u', $bare ) && ! isset( $plain[ $k ] ) && ! isset( $known[ $k ] ) ) {
					$names[ $k ] = $bare;
				}
			}
			$after = (bool) preg_match( '/[:;]$/u', $t );
			if ( preg_match( '/(?:[.!?\x{061F}\x{0964}\x{0965}\x{06D4}]|\x{2026}|\.\.\.)[\x{201D}\x{2019}"\')\]]*$/u', $t ) ) {
				if ( $in > RRT_BACKLOG_LONG_SENTENCE ) {
					$long++;
				}
				$in = 0;
			}
		}
		if ( $in > RRT_BACKLOG_LONG_SENTENCE ) {
			$long++;
		}
	}
	$points = 2 * $long + count( $names ) + count( $big ) + 2 * count( $met );
	$score  = 100 * $points / max( 100, $words ); // A post under 100 words counts as 100, so one name does not make a short post hard.
	return array(
		'words' => $words,
		'long'  => $long,
		'names' => array_values( $names ),
		'big'   => array_values( $big ),
		'known' => array_values( $met ),
		'score' => round( $score, 2 ),
		'level' => $score < 2 ? 'easy' : ( $score < 5 ? 'care' : 'hard' ),
	);
}

/** How hard, in words. */
function rrt_backlog_level_name( $level ) {
	$names = array(
		'easy' => __( 'Easy', 'foxfire-studio' ),
		'care' => __( 'Some care', 'foxfire-studio' ),
		'hard' => __( 'Demanding', 'foxfire-studio' ),
	);
	return isset( $names[ $level ] ) ? $names[ $level ] : '';
}

/** The reasons behind How hard, one line each. */
function rrt_backlog_reasons( $m ) {
	$list = function ( $words ) {
		return implode( ', ', array_slice( $words, 0, 6 ) ) . ( count( $words ) > 6 ? ', ...' : '' );
	};
	$out = array();
	if ( $m['known'] ) {
		$out[] = sprintf( /* translators: 1: how many words, 2: the words. */ _n( '%1$d word that tripped you or Whisper before: %2$s', '%1$d words that tripped you or Whisper before: %2$s', count( $m['known'] ), 'foxfire-studio' ), count( $m['known'] ), $list( $m['known'] ) );
	}
	if ( $m['long'] ) {
		$out[] = sprintf( /* translators: 1: how many sentences, 2: the word limit. */ _n( '%1$d sentence over %2$d words', '%1$d sentences over %2$d words', $m['long'], 'foxfire-studio' ), $m['long'], RRT_BACKLOG_LONG_SENTENCE );
	}
	if ( $m['names'] ) {
		$out[] = sprintf( /* translators: 1: how many names, 2: the names. */ _n( '%1$d name: %2$s', '%1$d names: %2$s', count( $m['names'] ), 'foxfire-studio' ), count( $m['names'] ), $list( $m['names'] ) );
	}
	if ( $m['big'] ) {
		$out[] = sprintf( /* translators: 1: how many words, 2: the words. */ _n( '%1$d very long word: %2$s', '%1$d very long words: %2$s', count( $m['big'] ), 'foxfire-studio' ), count( $m['big'] ), $list( $m['big'] ) );
	}
	return $out;
}

/**
 * The reader's pace for the estimate: the median of their latest five full
 * takes of 20 seconds or more, or the studio's default pace.
 *
 * @param int $user_id User ID.
 * @return array wpm, and from (takes or setting) with n, the takes it rests on.
 */
function rrt_backlog_pace( $user_id ) {
	$wpm = array();
	if ( rrt_stats_on() ) {
		foreach ( array_reverse( rrt_full_takes( rrt_dash_takes( $user_id ) ) ) as $t ) {
			if ( (int) $t['wpm'] > 0 && (float) $t['duration_s'] >= 20 ) {
				$wpm[] = (int) $t['wpm'];
			}
			if ( count( $wpm ) >= 5 ) {
				break;
			}
		}
	}
	if ( $wpm ) {
		sort( $wpm );
		$c   = count( $wpm );
		$mid = (int) floor( $c / 2 );
		return array(
			'wpm'  => (int) round( $c % 2 ? $wpm[ $mid ] : ( $wpm[ $mid - 1 ] + $wpm[ $mid ] ) / 2 ),
			'from' => 'takes',
			'n'    => $c,
		);
	}
	$cfg = rrt_config();
	return array(
		'wpm'  => max( 60, (int) ( isset( $cfg['defaults']['wpm'] ) ? $cfg['defaults']['wpm'] : 140 ) ),
		'from' => 'setting',
		'n'    => 0,
	);
}

/** The hidden posts' IDs. */
function rrt_backlog_hidden() {
	$h = get_option( RRT_BACKLOG_HIDDEN, array() );
	return is_array( $h ) ? array_values( array_unique( array_map( 'absint', $h ) ) ) : array();
}

/**
 * The posts on the list: published posts with no reading, newest first.
 *
 * @param int  $user_id     The reader, for pace and their own words.
 * @param bool $show_hidden True for the hidden posts instead.
 * @return array Rows: post, title, date, words, seconds, takes, and the measure.
 */
function rrt_backlog_rows( $user_id, $show_hidden = false ) {
	$hidden = array_flip( rrt_backlog_hidden() );
	$q      = new WP_Query(
		array(
			'post_type'              => 'post',
			'post_status'            => 'publish',
			'posts_per_page'         => RRT_BACKLOG_MAX,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'ignore_sticky_posts'    => true,
			'suppress_filters'       => false,
		)
	);
	$pace  = rrt_backlog_pace( $user_id );
	$own   = rrt_backlog_own_words( $user_id );
	$not   = rrt_backlog_not_names( $user_id );
	$takes = array();
	if ( rrt_stats_on() ) {
		foreach ( rrt_full_takes( rrt_dash_takes( $user_id ) ) as $t ) {
			$takes[ (int) $t['post_id'] ] = ( isset( $takes[ (int) $t['post_id'] ] ) ? $takes[ (int) $t['post_id'] ] : 0 ) + 1;
		}
	}
	$rows = array();
	foreach ( $q->posts as $p ) {
		if ( isset( $hidden[ $p->ID ] ) !== $show_hidden ) {
			continue;
		}
		$audio = rrt_reading_audio( $p->ID );
		if ( '' !== $audio['url'] ) {
			continue;
		}
		$blocks = rrt_blocks_from_html( get_the_title( $p ), strip_shortcodes( (string) $p->post_content ) );
		$m      = rrt_backlog_measure( rrt_backlog_lines( $p ), rrt_backlog_known_words( $p->ID, $own ), $not, function_exists( 'rrt_post_lang' ) ? rrt_post_lang( $p->ID ) : 'en' );
		if ( count( $blocks ) < 2 ) {
			continue; // A title and nothing to read.
		}
		$rows[] = array(
			'post'    => $p->ID,
			'title'   => get_the_title( $p ),
			'date'    => get_post_time( 'Y-m-d', false, $p ),
			'words'   => $m['words'],
			'seconds' => (int) round( 60 * $m['words'] / $pace['wpm'] ),
			'takes'   => isset( $takes[ $p->ID ] ) ? $takes[ $p->ID ] : 0,
			'measure' => $m,
		);
	}
	return array(
		'rows' => $rows,
		'pace' => $pace,
	);
}

/** The Readings to make screen. */
function rrt_backlog_page() {
	if ( ! current_user_can( rrt_dash_cap() ) ) {
		wp_die( esc_html__( 'Not allowed.', 'foxfire-studio' ) );
	}
	$show_hidden = ! empty( $_GET['hidden'] ); // phpcs:ignore WordPress.Security.NonceVerification
	$user_id     = get_current_user_id();
	$data        = rrt_backlog_rows( $user_id, $show_hidden );
	$rows        = $data['rows'];
	$pace        = $data['pace'];
	$n_hidden    = count( rrt_backlog_hidden() );
	rrt_dash_header( $show_hidden ? __( 'Readings to make: hidden posts', 'foxfire-studio' ) : __( 'Readings to make', 'foxfire-studio' ), 'rrt-backlog', array(), array(), false );

	if ( $show_hidden ) {
		echo '<p>' . esc_html__( 'Posts taken off the list. Bring one back to see it with the others again.', 'foxfire-studio' ) . ' <a href="' . esc_url( rrt_dash_url( 'rrt-backlog' ) ) . '">' . esc_html__( 'Back to the list', 'foxfire-studio' ) . '</a></p>';
	} else {
		echo '<p>' . esc_html__( 'Your published posts that have no reading yet, newest first. Click a column to sort; Start opens the studio on the post in a new tab.', 'foxfire-studio' );
		if ( $n_hidden ) {
			echo ' <a href="' . esc_url( rrt_dash_url( 'rrt-backlog', array( 'hidden' => 1 ) ) ) . '">' . esc_html( sprintf( /* translators: %d: how many posts are hidden. */ __( 'Show hidden (%d)', 'foxfire-studio' ), $n_hidden ) ) . '</a>';
		}
		echo '</p>';
		$total = 0;
		foreach ( $rows as $r ) {
			$total += $r['seconds'];
		}
		if ( $rows ) {
			echo '<div class="rrt-tiles">';
			rrt_dash_tile( __( 'Posts to read', 'foxfire-studio' ), number_format_i18n( count( $rows ) ) );
			rrt_dash_tile( __( 'All of them would run', 'foxfire-studio' ), rrt_dash_hours( $total ) );
			rrt_dash_tile(
				__( 'Your pace', 'foxfire-studio' ),
				sprintf( /* translators: %d: words a minute. */ __( '%d wpm', 'foxfire-studio' ), $pace['wpm'] ),
				'takes' === $pace['from']
					? sprintf( /* translators: %d: how many takes. */ _n( 'the median of your latest %d full take', 'the median of your latest %d full takes', $pace['n'], 'foxfire-studio' ), $pace['n'] )
					: __( 'the studio’s default pace, until you have full takes', 'foxfire-studio' )
			);
			echo '</div>';
		}
	}

	echo '<table class="widefat striped rrt-table rrt-sortable rrt-backlog" id="rrt-t-backlog"><thead><tr>';
	$heads = array(
		array( __( 'Post', 'foxfire-studio' ), 'text', '' ),
		array( __( 'Published', 'foxfire-studio' ), 'text', '' ),
		array( __( 'Words', 'foxfire-studio' ), 'num', 'num' ),
		array( __( 'Would run', 'foxfire-studio' ), 'num', 'num' ),
		array( __( 'How hard', 'foxfire-studio' ), 'num', '' ),
		array( __( 'Takes', 'foxfire-studio' ), 'num', 'num' ),
	);
	foreach ( $heads as $h ) {
		echo '<th' . ( $h[2] ? ' class="' . esc_attr( $h[2] ) . '"' : '' ) . ' data-sort="' . esc_attr( $h[1] ) . '"><button type="button">' . esc_html( $h[0] ) . '</button></th>';
	}
	echo '<th></th></tr></thead><tbody>';
	$nonce   = wp_create_nonce( 'rrt_backlog' );
	$post_to = admin_url( 'admin-post.php' );
	foreach ( $rows as $r ) {
		$m       = $r['measure'];
		$reasons = rrt_backlog_reasons( $m );
		echo '<tr>';
		echo '<td data-v="' . esc_attr( $r['title'] ) . '"><a href="' . esc_url( get_permalink( $r['post'] ) ) . '" target="_blank" rel="noopener">' . esc_html( '' !== $r['title'] ? $r['title'] : __( '(no title)', 'foxfire-studio' ) ) . '</a>';
		/* A reading taken off the post (1.8.0) can be put back instead of read again. */
		if ( function_exists( 'rrt_kept_list' ) && rrt_kept_list( $r['post'] ) ) {
			echo ' <a class="rrt-pill rrt-kept-pill" href="' . esc_url( admin_url( 'admin.php?page=rrt-readings&post=' . (int) $r['post'] . '#rrt-keep' ) ) . '" title="' . esc_attr__( 'This post has a reading kept aside. Put it back from its page.', 'foxfire-studio' ) . '">' . esc_html__( 'Reading kept aside', 'foxfire-studio' ) . '</a>';
		}
		echo '</td>';
		echo '<td data-v="' . esc_attr( $r['date'] ) . '">' . esc_html( rrt_dash_day( $r['date'], 'M j, Y' ) ) . '</td>';
		echo '<td class="num" data-v="' . (int) $r['words'] . '">' . esc_html( number_format_i18n( $r['words'] ) ) . '</td>';
		echo '<td class="num" data-v="' . (int) $r['seconds'] . '">' . esc_html( rrt_clock( $r['seconds'] ) ) . '</td>';
		echo '<td data-v="' . esc_attr( $m['score'] ) . '" title="' . esc_attr( $reasons ? implode( "\n", $reasons ) : __( 'Nothing stands out.', 'foxfire-studio' ) ) . '"><span class="rrt-hard rrt-hard-' . esc_attr( $m['level'] ) . '">' . esc_html( rrt_backlog_level_name( $m['level'] ) ) . '</span>';
		if ( $reasons ) {
			echo '<span class="rrt-small rrt-hard-why">' . esc_html( implode( '; ', array_slice( $reasons, 0, 2 ) ) ) . '</span>';
		}
		echo '</td>';
		echo '<td class="num" data-v="' . (int) $r['takes'] . '">' . esc_html( $r['takes'] ? number_format_i18n( $r['takes'] ) : '' ) . '</td>';
		echo '<td class="rrt-backlog-do">';
		if ( ! $show_hidden ) {
			echo '<a class="button button-primary" href="' . esc_url( rrt_url( $r['post'] ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Start', 'foxfire-studio' ) . '</a> ';
		}
		echo '<form method="post" action="' . esc_url( $post_to ) . '"><input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '"><input type="hidden" name="action" value="rrt_backlog_hide"><input type="hidden" name="post" value="' . (int) $r['post'] . '"><input type="hidden" name="do" value="' . ( $show_hidden ? 'show' : 'hide' ) . '">';
		echo '<button class="button" title="' . esc_attr( $show_hidden ? __( 'Put this post back on the list', 'foxfire-studio' ) : __( 'Take this post off the list, for a post that should not be read aloud', 'foxfire-studio' ) ) . '">' . esc_html( $show_hidden ? __( 'Bring back', 'foxfire-studio' ) : __( 'Hide', 'foxfire-studio' ) ) . '</button></form>';
		echo '</td></tr>';
	}
	if ( ! $rows ) {
		echo '<tr><td colspan="7">' . esc_html( $show_hidden ? __( 'No hidden posts.', 'foxfire-studio' ) : __( 'Every published post has a reading.', 'foxfire-studio' ) ) . '</td></tr>';
	}
	echo '</tbody></table>';
	rrt_dash_defs(
		array(
			__( 'Would run', 'foxfire-studio' ) => __( 'The post’s words at your pace: the median of your latest five full takes of 20 seconds or more, or the studio’s default pace until there are some. Your pace already includes your pauses.', 'foxfire-studio' ),
			__( 'How hard', 'foxfire-studio' )  => sprintf( /* translators: %d: the word limit for a long sentence. */ __( 'Worked out per 100 words (a shorter post counts as 100), so a long post is not called hard for being long, nor a short one for a single name. Each word that tripped you or Whisper before (a pronunciation note, or a word taught to Whisper, for every post or this one) counts 2, each sentence over %d words 2, each name (a capital inside a sentence or a line of verse, in a word never written in lower case in your posts, outside a book or event title, and not marked Not needed) 1 and each word of five syllables or more 1. Under 2 points per 100 words is Easy, under 5 Some care, and 5 or more Demanding. Hover for the reasons.', 'foxfire-studio' ), RRT_BACKLOG_LONG_SENTENCE ) . ' ' . sprintf( /* translators: %d: letters in a very long word. */ __( 'For a post read in another language (its Reading language), a very long word is one of %d letters or more, and names are not counted in German or Luxembourgish, where every noun has a capital.', 'foxfire-studio' ), RRT_BACKLOG_LONG_WORD ),
			__( 'Takes', 'foxfire-studio' )     => __( 'Your takes of the post kept on the site, partial takes counted and opening drills left out.', 'foxfire-studio' ),
		)
	);
	echo '</div>';
}

add_action(
	'admin_post_rrt_backlog_hide',
	function () {
		if ( ! current_user_can( rrt_dash_cap() ) ) {
			wp_die( esc_html__( 'Not allowed.', 'foxfire-studio' ) );
		}
		check_admin_referer( 'rrt_backlog' );
		$post_id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$do      = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$hidden  = rrt_backlog_hidden();
		if ( $post_id && 'post' === get_post_type( $post_id ) ) {
			if ( 'hide' === $do && ! in_array( $post_id, $hidden, true ) ) {
				$hidden[] = $post_id;
			}
			if ( 'show' === $do ) {
				$hidden = array_values( array_diff( $hidden, array( $post_id ) ) );
			}
			if ( $hidden ) {
				update_option( RRT_BACKLOG_HIDDEN, $hidden, false );
			} else {
				delete_option( RRT_BACKLOG_HIDDEN );
			}
		}
		$args = 'show' === $do && $hidden ? array( 'hidden' => 1 ) : array();
		wp_safe_redirect( rrt_dash_url( 'rrt-backlog', $args ) );
		exit;
	}
);

/* A deleted post leaves the hidden list. */
add_action(
	'deleted_post',
	function ( $post_id ) {
		$hidden = rrt_backlog_hidden();
		if ( in_array( (int) $post_id, $hidden, true ) ) {
			$hidden = array_values( array_diff( $hidden, array( (int) $post_id ) ) );
			if ( $hidden ) {
				update_option( RRT_BACKLOG_HIDDEN, $hidden, false );
			} else {
				delete_option( RRT_BACKLOG_HIDDEN );
			}
		}
	}
);
