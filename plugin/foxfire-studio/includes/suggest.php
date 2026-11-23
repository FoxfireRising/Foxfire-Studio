<?php
/**
 * Clip suggestions (1.7.0): good moments of a reading for a clip, worked out
 * on the site from the reading's captions and, once it has enough plays, its
 * listening curve. Shown on a reading's page and in the clip maker; one of
 * them can be kept as the post's pick for the podcast feed.
 *
 * Nothing new is collected: the captions are the reading's own and the curve
 * is the one the dashboard already shows.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Shortest and longest suggestion, and the longest before length starts to count against it, in seconds. */
define( 'RRT_SUGGEST_MIN', 30 );
define( 'RRT_SUGGEST_MAX', 60 );
define( 'RRT_SUGGEST_EASY', 45 );
/** Plays a reading needs before replays and listeners staying count. */
define( 'RRT_SUGGEST_PLAYS', 10 );
/** A pause longer than this inside a stretch counts against it, in seconds. */
define( 'RRT_SUGGEST_GAP', 2 );
/** The post meta holding the pick for the feed. */
define( 'RRT_SOUNDBITE_META', '_rrt_soundbite' );

/**
 * Words that point back to something said before, so a clip starting on one
 * begins in the middle of a thought. Lower case.
 */
function rrt_suggest_backrefs() {
	$words = array( 'and', 'but', 'so', 'or', 'nor', 'yet', 'then', 'also', 'too', 'this', 'that', 'these', 'those', 'it', 'its', "it's", 'they', 'them', 'their', "they're", 'he', 'she', 'him', 'her', 'his', 'such', 'which', 'because', 'however', 'still', 'instead', 'thus', 'therefore', 'otherwise', 'besides', 'again', 'meanwhile' );
	return (array) apply_filters( 'rrt_suggest_backrefs', $words );
}

/** A word for comparing: lower case, letters, digits and apostrophes only. */
function rrt_suggest_norm( $w ) {
	$w = function_exists( 'mb_strtolower' ) ? mb_strtolower( $w, 'UTF-8' ) : strtolower( $w );
	$w = str_replace( array( '’', '‘' ), "'", $w );
	return trim( (string) preg_replace( "/[^\p{L}\p{N}']+/u", '', $w ), "'" );
}

/**
 * The reading's sentences, from its caption cues and the post's blocks.
 *
 * Caption cues are one sentence each, or a part of a long one. The cues are
 * lined up with the post's words to find where paragraphs begin and end; a
 * cue that ends in a full stop, question or exclamation mark, or at the end
 * of a paragraph, ends a sentence.
 *
 * @param array $cues   Each: s, e, t (from rrt_dash_cues()).
 * @param array $blocks Each: type (title, h or p), text (from rrt_post_blocks()).
 * @return array Each: s, e, t, ps (starts a paragraph), pe (ends one), kind (title, h or p), gaps (pauses over the limit inside it, in seconds).
 */
function rrt_suggest_sentences( $cues, $blocks ) {
	$script = array();
	foreach ( array_values( (array) $blocks ) as $bi => $b ) {
		$words = preg_split( '/\s+/u', trim( (string) preg_replace( '/[\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', (string) $b['text'] ) ), -1, PREG_SPLIT_NO_EMPTY );
		$n     = count( $words );
		foreach ( $words as $k => $w ) {
			$script[] = array(
				'w'     => rrt_suggest_norm( $w ),
				'first' => 0 === $k,
				'last'  => $n - 1 === $k,
				'kind'  => isset( $b['type'] ) ? $b['type'] : 'p',
			);
		}
	}
	$at    = 0;
	$total = count( $script );
	$find  = function ( $w ) use ( &$at, $script, $total ) {
		if ( '' === $w ) {
			return -1;
		}
		for ( $k = $at; $k < min( $total, $at + 30 ); $k++ ) {
			if ( $script[ $k ]['w'] === $w ) {
				$at = $k + 1;
				return $k;
			}
		}
		return -1;
	};

	$sents = array();
	$cur   = null;
	$n     = count( $cues );
	foreach ( array_values( $cues ) as $ci => $c ) {
		/* Zero-width spaces and joiners, which some editors leave in a post's text, are dropped. */
		$words = preg_split( '/\s+/u', trim( (string) preg_replace( '/[\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', (string) $c['t'] ) ), -1, PREG_SPLIT_NO_EMPTY );
		if ( ! $words ) {
			continue;
		}
		$idx = array();
		foreach ( $words as $w ) {
			$idx[] = $find( rrt_suggest_norm( $w ) );
		}
		$fi   = $idx[0];
		$li   = $idx[ count( $idx ) - 1 ];
		$ps   = $fi >= 0 && $script[ $fi ]['first'];
		$pe   = $li >= 0 && $script[ $li ]['last'];
		$kind = $fi >= 0 ? $script[ $fi ]['kind'] : 'p';
		$end  = $pe || $ci === $n - 1 || preg_match( '/[.!?…]["\'”’)\]]*$/u', end( $words ) );
		if ( null === $cur || $ps ) {
			if ( null !== $cur ) {
				$sents[] = $cur;
			}
			$cur = array( 's' => (float) $c['s'], 'e' => (float) $c['e'], 't' => implode( ' ', $words ), 'ps' => $ps || ! $sents, 'pe' => $pe, 'kind' => $kind, 'gaps' => 0.0 );
		} else {
			$gap = (float) $c['s'] - $cur['e'];
			if ( $gap > RRT_SUGGEST_GAP ) {
				$cur['gaps'] += $gap - RRT_SUGGEST_GAP;
			}
			$cur['e']  = (float) $c['e'];
			$cur['t'] .= ' ' . implode( ' ', $words );
			$cur['pe'] = $pe;
		}
		if ( $end ) {
			$sents[] = $cur;
			$cur     = null;
		}
	}
	if ( null !== $cur ) {
		$sents[] = $cur;
	}
	return $sents;
}

/** The first or last few words of a text, with an ellipsis where it is cut. */
function rrt_suggest_words( $text, $n, $from_end = false ) {
	$w = preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
	if ( count( $w ) <= $n ) {
		return implode( ' ', $w );
	}
	return $from_end ? '…' . implode( ' ', array_slice( $w, -$n ) ) : implode( ' ', array_slice( $w, 0, $n ) ) . '…';
}

/**
 * Every stretch of a reading that could be a clip, scored: 30 to 60 s from a
 * sentence start to a sentence end. Paragraph edges count for a stretch, as
 * does a length of 30 to 45 s; starting on a word that points back and long
 * pauses count against it. With 10 or more plays, the stretches listeners
 * replay or stay through count for more. Best first.
 *
 * @param array      $sents From rrt_suggest_sentences().
 * @param array|null $curve heard, replayed and plays per 5 s stretch, or null.
 * @return array Each: i and j (first and last sentence), s, e, d, score, reasons, cautions.
 */
function rrt_suggest_candidates( $sents, $curve = null ) {
	$n      = count( $sents );
	$back   = array_flip( array_map( 'rrt_suggest_norm', rrt_suggest_backrefs() ) );
	$listen = $curve && ! empty( $curve['heard'] ) && (int) $curve['plays'] >= RRT_SUGGEST_PLAYS;
	$heard  = $listen ? array_values( $curve['heard'] ) : array();
	$rep    = $listen && ! empty( $curve['replayed'] ) ? array_values( $curve['replayed'] ) : array();
	$rate0  = $listen && array_sum( $heard ) ? array_sum( $rep ) / array_sum( $heard ) : 0;

	$cands = array();
	for ( $i = 0; $i < $n; $i++ ) {
		$gaps = 0.0;
		for ( $j = $i; $j < $n; $j++ ) {
			$d = $sents[ $j ]['e'] - $sents[ $i ]['s'];
			if ( $d > RRT_SUGGEST_MAX ) {
				break;
			}
			$gaps += $sents[ $j ]['gaps'];
			if ( $j > $i ) {
				$g = $sents[ $j ]['s'] - $sents[ $j - 1 ]['e'];
				if ( $g > RRT_SUGGEST_GAP ) {
					$gaps += $g - RRT_SUGGEST_GAP;
				}
			}
			/*
			 * A clip never ends on a title or heading, or on words that lead
			 * into what follows ("Declare:", a closing dash or comma): they
			 * start what comes next.
			 */
			if ( $d < RRT_SUGGEST_MIN || 'p' !== $sents[ $j ]['kind'] || preg_match( '/[:;,\x{2013}\x{2014}-]["\'\x{201D}\x{2019})\]]*$/u', $sents[ $j ]['t'] ) ) {
				continue;
			}
			$score    = 0.0;
			$reasons  = array();
			$cautions = array();
			if ( 0 === $i ) {
				$reasons[] = __( 'Where every listener starts', 'foxfire-studio' );
			} elseif ( 'h' === $sents[ $i ]['kind'] ) {
				$score    += 2.5;
				$reasons[] = __( 'Starts a section', 'foxfire-studio' );
			} elseif ( $sents[ $i ]['ps'] ) {
				$score    += 2;
				$reasons[] = __( 'Starts a paragraph', 'foxfire-studio' );
			}
			if ( $sents[ $j ]['pe'] ) {
				$score    += 2;
				$reasons[] = __( 'Ends a paragraph', 'foxfire-studio' );
			}
			if ( $d > RRT_SUGGEST_EASY ) {
				/* 3 points at 60 s: a paragraph's end is worth reaching for up to about 55 s. */
				$score -= ( $d - RRT_SUGGEST_EASY ) / ( RRT_SUGGEST_MAX - RRT_SUGGEST_EASY ) * 3;
			}
			$fw = preg_split( '/\s+/u', $sents[ $i ]['t'], 2 );
			if ( 0 !== $i && isset( $back[ rrt_suggest_norm( $fw[0] ) ] ) ) {
				$score     -= 2.5;
				$cautions[] = sprintf( /* translators: %s: the first word of the clip. */ __( 'Starts with “%s”, which points back to what came before', 'foxfire-studio' ), trim( $fw[0], ',;:' ) );
			}
			if ( $gaps > 0 ) {
				$score     -= min( 3, 0.75 * $gaps );
				$cautions[] = __( 'Has a long pause', 'foxfire-studio' );
			}
			$cand = array(
				'i'        => $i,
				'j'        => $j,
				's'        => $sents[ $i ]['s'],
				'e'        => $sents[ $j ]['e'],
				'd'        => $d,
				'score'    => $score,
				'reasons'  => $reasons,
				'cautions' => $cautions,
			);
			if ( $listen ) {
				$b0 = (int) floor( $cand['s'] / 5 );
				$b1 = max( $b0, (int) floor( ( $cand['e'] - 0.01 ) / 5 ) );
				$h  = 0;
				$r  = 0;
				for ( $k = $b0; $k <= $b1; $k++ ) {
					$h += isset( $heard[ $k ] ) ? $heard[ $k ] : 0;
					$r += isset( $rep[ $k ] ) ? $rep[ $k ] : 0;
				}
				$first        = isset( $heard[ $b0 ] ) ? $heard[ $b0 ] : 0;
				$cand['held'] = $first ? ( isset( $heard[ $b1 ] ) ? $heard[ $b1 ] : 0 ) / $first : null;
				$cand['rate'] = $h ? $r / $h : 0;
				$cand['reps'] = $r;
			}
			$cands[] = $cand;
		}
	}
	if ( ! $cands ) {
		return array();
	}

	if ( $listen ) {
		$helds = array_values( array_filter( wp_list_pluck( $cands, 'held' ), 'is_numeric' ) );
		sort( $helds );
		$mid = $helds ? $helds[ (int) floor( ( count( $helds ) - 1 ) / 2 ) ] : null;
		foreach ( $cands as &$c ) {
			if ( $rate0 > 0 ) {
				$lift        = max( -1, min( 2, $c['rate'] / $rate0 - 1 ) );
				$c['score'] += 1.5 * $lift;
				if ( $c['rate'] >= 1.5 * $rate0 && $c['reps'] >= 3 ) {
					$c['reasons'][] = __( 'Listeners replay it', 'foxfire-studio' );
				}
			}
			if ( null !== $mid && null !== $c['held'] ) {
				$c['score'] += max( -1.5, min( 1.5, 6 * ( $c['held'] - $mid ) ) );
				if ( $c['held'] >= $mid + 0.05 && $c['held'] >= 0.9 ) {
					$c['reasons'][] = __( 'Listeners stay through it', 'foxfire-studio' );
				}
			}
		}
		unset( $c );
	}

	usort(
		$cands,
		function ( $a, $b ) {
			return $b['score'] <=> $a['score'] ?: $a['s'] <=> $b['s'];
		}
	);
	return $cands;
}

/**
 * Suggested clips from a reading's sentences: the best stretch from the
 * opening, then the best others, each overlapping the ones before it by at
 * most a quarter.
 *
 * @param array      $sents From rrt_suggest_sentences().
 * @param array|null $curve heard, replayed and plays per 5 s stretch, or null.
 * @param int        $count How many besides the opening.
 * @return array The opening first, then the best ones; each: s, e, d, start, len, first, last, text, score, reasons, cautions, opening.
 */
function rrt_suggest_clips( $sents, $curve = null, $count = 3 ) {
	$cands = rrt_suggest_candidates( $sents, $curve );
	$fits  = function ( $c, $picks ) {
		foreach ( $picks as $p ) {
			$over = min( $c['e'], $p['e'] ) - max( $c['s'], $p['s'] );
			if ( $over > 0.25 * min( $c['d'], $p['d'] ) ) {
				return false;
			}
		}
		return true;
	};
	$picks = array();
	foreach ( $cands as $c ) {
		if ( 0 === $c['i'] ) {
			$c['opening'] = true;
			$picks[]      = $c;
			break;
		}
	}
	/*
	 * The best that fits, one at a time. Among stretches that score the same,
	 * the one furthest from those already chosen, so equal choices spread
	 * across the reading instead of bunching at its start.
	 */
	$apart = function ( $c, $picks ) {
		$far = INF;
		foreach ( $picks as $p ) {
			$far = min( $far, abs( ( $c['s'] + $c['e'] ) - ( $p['s'] + $p['e'] ) ) / 2 );
		}
		return $far;
	};
	for ( $more = 0; $more < $count; $more++ ) {
		$best = null;
		foreach ( $cands as $c ) {
			if ( 0 === $c['i'] || ! $fits( $c, $picks ) ) {
				continue;
			}
			if ( null === $best ) {
				$best = $c;
				continue;
			}
			if ( $c['score'] < $best['score'] - 0.01 ) {
				break;
			}
			if ( $apart( $c, $picks ) > $apart( $best, $picks ) ) {
				$best = $c;
			}
		}
		if ( null === $best ) {
			break;
		}
		$best['opening'] = false;
		$picks[]         = $best;
	}

	$out = array();
	foreach ( $picks as $p ) {
		$text  = array();
		for ( $k = $p['i']; $k <= $p['j']; $k++ ) {
			$text[] = $sents[ $k ]['t'];
		}
		$text  = implode( ' ', $text );
		$bd    = rrt_suggest_bounds( $sents, $p['i'], $p['j'] );
		$out[] = array(
			's'        => round( $p['s'], 2 ),
			'e'        => round( $p['e'], 2 ),
			'd'        => round( $p['d'], 1 ),
			/* What the clip maker and ▶ use: inside the pauses either side, never into the next sentence. */
			'start'    => $bd['start'],
			'len'      => $bd['len'],
			'prev'     => $bd['prev'],
			'next'     => $bd['next'],
			'first'    => rrt_suggest_words( $sents[ $p['i'] ]['t'], 6 ),
			'last'     => rrt_suggest_words( $sents[ $p['j'] ]['t'], 6, true ),
			'text'     => $text,
			'score'    => round( $p['score'], 2 ),
			'reasons'  => $p['reasons'],
			'cautions' => $p['cautions'],
			'opening'  => ! empty( $p['opening'] ),
		);
	}
	return $out;
}

/**
 * The suggestions for one post's reading, or an empty list when the reading
 * has no captions.
 *
 * @param int        $post_id The post.
 * @param array|null $curve   The listening curve of the post's current reading, or null.
 */
function rrt_suggestions( $post_id, $curve = null ) {
	$cues = rrt_dash_cues( $post_id );
	if ( ! $cues ) {
		return array();
	}
	$post = get_post( $post_id );
	if ( ! $post ) {
		return array();
	}
	return rrt_suggest_clips( rrt_suggest_post_sentences( $post, $cues ), $curve );
}

/** A post's sentences as its reading's captions time them. */
function rrt_suggest_post_sentences( $post, $cues ) {
	$blocks = rrt_blocks_from_html( get_the_title( $post ), strip_shortcodes( (string) $post->post_content ) );
	return rrt_suggest_sentences( $cues, $blocks );
}

/**
 * A stretch of a post's reading for the feed, checked against its sentences:
 * it must start at a sentence start, end at a sentence end and run 30 to 60 s.
 *
 * @return array|null start, len (as the clip maker uses them), text.
 */
function rrt_suggest_stretch( $post_id, $s, $e ) {
	$post = get_post( $post_id );
	$cues = $post ? rrt_dash_cues( $post_id ) : array();
	if ( ! $cues ) {
		return null;
	}
	$sents = rrt_suggest_post_sentences( $post, $cues );
	$i     = null;
	$j     = null;
	foreach ( $sents as $k => $x ) {
		if ( null === $i && abs( $x['s'] - $s ) < 0.02 ) {
			$i = $k;
		}
		if ( null !== $i && abs( $x['e'] - $e ) < 0.02 ) {
			$j = $k;
			break;
		}
	}
	if ( null === $i || null === $j ) {
		return null;
	}
	$d = $sents[ $j ]['e'] - $sents[ $i ]['s'];
	if ( $d < RRT_SUGGEST_MIN - 0.01 || $d > RRT_SUGGEST_MAX + 0.01 ) {
		return null;
	}
	$text  = implode( ' ', wp_list_pluck( array_slice( $sents, $i, $j - $i + 1 ), 't' ) );
	$bd = rrt_suggest_bounds( $sents, $i, $j );
	return array(
		'start' => $bd['start'],
		'len'   => $bd['len'],
		'text'  => $text,
	);
}

/**
 * Where a stretch from sentence $i to sentence $j starts and how long it
 * runs, with the breath before the first word and the tail of the last kept
 * inside the pauses either side: at most 0.15 s before and 0.25 s after, and
 * never more than 40% of the pause, so a clip never catches the end of the
 * sentence before it or the start of the one after. (A natural reading often
 * leaves only 0.2 to 0.5 s between sentences.)
 *
 * @return array start, len (rounded down to 0.01 s), prev (the end of the sentence before, or null), next (the start of the one after, or null).
 */
function rrt_suggest_bounds( $sents, $i, $j ) {
	$s    = (float) $sents[ $i ]['s'];
	$e    = (float) $sents[ $j ]['e'];
	$prev = $i > 0 ? (float) $sents[ $i - 1 ]['e'] : null;
	$next = $j < count( $sents ) - 1 ? (float) $sents[ $j + 1 ]['s'] : null;
	$lead = null === $prev ? 0.15 : max( 0, min( 0.15, ( $s - $prev ) * 0.4 ) );
	$tail = null === $next ? 0.3 : max( 0, min( 0.25, ( $next - $e ) * 0.4 ) );
	$start = max( 0, round( $s - $lead, 2 ) );
	return array(
		'start' => $start,
		'len'   => floor( ( $e + $tail - $start ) * 100 + 1e-6 ) / 100,
		'prev'  => null === $prev ? null : round( $prev, 2 ),
		'next'  => null === $next ? null : round( $next, 2 ),
	);
}

/**
 * The post's pick for the podcast feed, or null. A pick made for an earlier
 * reading is forgotten as soon as it is asked for.
 *
 * @return array|null s (start), d (length), text, audio (the reading it was made for).
 */
function rrt_soundbite_pick( $post_id ) {
	$p = get_post_meta( $post_id, RRT_SOUNDBITE_META, true );
	if ( ! is_array( $p ) || ! isset( $p['s'], $p['d'] ) ) {
		return null;
	}
	$audio = rrt_reading_audio( $post_id );
	if ( empty( $audio['url'] ) || ! isset( $p['audio'] ) || $p['audio'] !== $audio['url'] ) {
		delete_post_meta( $post_id, RRT_SOUNDBITE_META );
		return null;
	}
	return $p;
}

/* Use for the feed, and Stop using it, from a reading's page. */
add_action(
	'admin_post_rrt_soundbite',
	function () {
		$post_id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is checked on the next lines, after the post number it names is read.
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Not allowed.', 'foxfire-studio' ) );
		}
		check_admin_referer( 'rrt_soundbite_' . $post_id );
		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		if ( 'clear' === $do ) {
			delete_post_meta( $post_id, RRT_SOUNDBITE_META );
		} elseif ( 'use' === $do ) {
			$s     = isset( $_POST['s'] ) ? (float) $_POST['s'] : -1;
			$e     = isset( $_POST['e'] ) ? (float) $_POST['e'] : -1;
			$audio = rrt_reading_audio( $post_id );
			/* Only a stretch that starts and ends at a sentence of this reading can be kept. */
			$st = rrt_suggest_stretch( $post_id, $s, $e );
			if ( $st && ! empty( $audio['url'] ) ) {
				update_post_meta(
					$post_id,
					RRT_SOUNDBITE_META,
					array(
						's'     => $st['start'],
						'd'     => $st['len'],
						'text'  => $st['text'],
						'audio' => $audio['url'],
					)
				);
			}
		}
		$back = isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( $_POST['back'] ) ) : '';
		wp_safe_redirect( $back ? $back . '#rrt-suggest' : admin_url( 'admin.php?page=rrt-readings' ) );
		exit;
	}
);
