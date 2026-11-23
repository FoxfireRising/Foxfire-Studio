<?php
/**
 * What works: insights (0.19.0).
 *
 * Plain-language observations built from the numbers the plugin already
 * keeps. Two kinds:
 *
 * - Listener observations compare how often readings were heard to the end
 *   against something about the reading: its length, its tempo, a tag or
 *   category, the day and time it went out, where listeners came from, and
 *   how it was delivered (from the take each recording came from). Each one
 *   needs a minimum number of readings with enough plays, and a clear gap,
 *   before it says anything.
 * - Reading observations use only the reader's own full takes (opening drills
 *   are left out): pace, accuracy, fillers and phrase-ending fade, and
 *   whether each is moving.
 *
 * Every observation says what it rests on. Below its minimum it says how much
 * more it needs instead. The minimums are filterable through
 * `rrt_insight_rules`.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The minimums every observation must pass.
 *
 * - plays: plays a reading needs before it counts (the dashboard's minimum, 30)
 * - readings: readings with that many plays an observation needs in all
 * - side: readings each side of a comparison needs
 * - gap: points between the sides before a difference is reported
 * - takes: full takes a reading observation needs
 * - trend: full takes before the latest five are compared with the five before
 */
function rrt_insight_rules() {
	$r = apply_filters(
		'rrt_insight_rules',
		array(
			'plays'    => rrt_dash_min_plays(),
			'readings' => 6,
			'side'     => 3,
			'gap'      => 10,
			'takes'    => 5,
			'trend'    => 10,
		)
	);
	foreach ( $r as $k => $v ) {
		$r[ $k ] = max( 1, (int) $v );
	}
	return $r;
}

/** The period insights look at by default: the last 12 months, in the site's time zone. */
function rrt_insight_default_range() {
	$to = new DateTimeImmutable( 'today', wp_timezone() );
	return array(
		'from' => $to->modify( '-364 days' )->format( 'Y-m-d' ),
		'to'   => $to->format( 'Y-m-d' ),
	);
}

/*
 * ---------------------------------------------------------------------------
 * The readings, one row per recording with listens in the period
 * ---------------------------------------------------------------------------
 */

/**
 * Every recording with plays in a period, with what insights compare it by.
 *
 * Each row: recording, post, title, plays, completions, held60, rate (share
 * of plays heard to the end), duration, tempo, terms (tags and categories),
 * weekday and hour it went out (site time), and the delivery numbers of the
 * take it came from, when it came from one.
 */
function rrt_insight_readings( $from, $to ) {
	global $wpdb;
	$td   = rrt_table( 'daily' );
	$tr   = rrt_table( 'recordings' );
	$tt   = rrt_table( 'takes' );
	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare( "SELECT recording_id, post_id, SUM(plays) plays, SUM(completions) completions, SUM(held60) held60 FROM $td WHERE day BETWEEN %s AND %s AND recording_id > 0 GROUP BY recording_id, post_id", $from, $to ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		ARRAY_A
	);
	if ( ! $rows ) {
		return array();
	}
	$ids   = array_map( 'intval', wp_list_pluck( $rows, 'recording_id' ) );
	$in    = implode( ',', $ids );
	$recs  = $wpdb->get_results( "SELECT id, post_id, duration_s, tempo_wpm, take_id, published_at FROM $tr WHERE id IN ($in)", OBJECT_K ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$tids  = array();
	foreach ( $recs as $r ) {
		if ( $r->take_id ) {
			$tids[] = (int) $r->take_id;
		}
	}
	$takes = $tids ? $wpdb->get_results( 'SELECT id, accuracy, pitch_spread, ending_drop_db, fillers, duration_s FROM ' . $tt . ' WHERE id IN (' . implode( ',', $tids ) . ')', OBJECT_K ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	$skip  = (int) get_option( 'default_category' );
	$tz    = wp_timezone();
	$out   = array();
	foreach ( $rows as $row ) {
		$rid  = (int) $row['recording_id'];
		$pid  = (int) $row['post_id'];
		$post = get_post( $pid );
		$rec  = isset( $recs[ $rid ] ) ? $recs[ $rid ] : null;
		if ( ! $post || ! $rec ) {
			continue;
		}
		$plays = (int) $row['plays'];
		/* When it went out: the recording's own date, or the post's when the recording was added to a draft. */
		$when = $rec->published_at ? $rec->published_at : ( 'publish' === $post->post_status ? $post->post_date_gmt : '' );
		$day  = null;
		$hour = null;
		if ( $when && '0000-00-00 00:00:00' !== $when ) {
			$dt   = ( new DateTimeImmutable( $when, new DateTimeZone( 'UTC' ) ) )->setTimezone( $tz );
			$day  = (int) $dt->format( 'N' );
			$hour = (int) $dt->format( 'G' );
		}
		$terms = array();
		foreach ( wp_get_post_tags( $pid ) as $t ) {
			$terms[ 'post_tag:' . $t->term_id ] = array( 'kind' => 'tag', 'name' => $t->name );
		}
		foreach ( wp_get_post_categories( $pid, array( 'fields' => 'all' ) ) as $t ) {
			if ( (int) $t->term_id !== $skip ) {
				$terms[ 'category:' . $t->term_id ] = array( 'kind' => 'category', 'name' => $t->name );
			}
		}
		$take  = ( $rec->take_id && isset( $takes[ (int) $rec->take_id ] ) ) ? $takes[ (int) $rec->take_id ] : null;
		$num   = function ( $v ) {
			return ( null === $v || '' === $v ) ? null : (float) $v;
		};
		$out[] = array(
			'recording'   => $rid,
			'post'        => $pid,
			'title'       => get_the_title( $post ),
			'plays'       => $plays,
			'completions' => (int) $row['completions'],
			'held60'      => (int) $row['held60'],
			'rate'        => $plays ? (int) $row['completions'] / $plays : 0,
			'duration'    => (float) $rec->duration_s,
			'tempo'       => (int) $rec->tempo_wpm,
			'terms'       => $terms,
			'weekday'     => $day,
			'hour'        => $hour,
			'accuracy'    => $take ? $num( $take->accuracy ) : null,
			'pitch'       => $take ? $num( $take->pitch_spread ) : null,
			'ending'      => $take ? $num( $take->ending_drop_db ) : null,
		);
	}
	return $out;
}

/*
 * ---------------------------------------------------------------------------
 * Small pieces
 * ---------------------------------------------------------------------------
 */

/** The average share heard to the end across readings, each reading counting once. */
function rrt_insight_rate( $set ) {
	if ( ! $set ) {
		return 0;
	}
	return array_sum( array_column( $set, 'rate' ) ) / count( $set );
}

/** A share as a whole percentage. */
function rrt_insight_pct( $v ) {
	return (int) round( 100 * $v );
}

/** A number of readings, in words. */
function rrt_insight_n( $n, $one = null, $many = null ) {
	if ( null === $one ) {
		/* translators: %s: number of readings. */
		return sprintf( _n( '%s reading', '%s readings', (int) $n, 'foxfire-studio' ), number_format_i18n( $n ) );
	}
	return number_format_i18n( $n ) . ' ' . ( 1 === (int) $n ? $one : $many );
}

/** A number without needless decimals: 2.0 becomes 2, 2.45 becomes 2.5. */
function rrt_insight_num( $v, $dec = 1 ) {
	$s = number_format( (float) $v, max( 0, (int) $dec ), '.', '' );
	return false === strpos( $s, '.' ) ? $s : rtrim( rtrim( $s, '0' ), '.' );
}

/** Whether a reading has a number: a length or tempo of 0 means unknown; other numbers are unknown only when missing. */
function rrt_insight_has( $r, $key ) {
	if ( ! isset( $r[ $key ] ) || null === $r[ $key ] ) {
		return false;
	}
	return in_array( $key, array( 'duration', 'tempo' ), true ) ? $r[ $key ] > 0 : true;
}

/**
 * Splits readings into a lower and an upper half by one of their numbers.
 * Readings without the number are left out; with an odd count the middle one
 * is left out too, so the halves are the same size.
 *
 * @return array|null low, high, cut (the highest value in the lower half), or null when there are too few.
 */
function rrt_insight_halves( $set, $key, $side ) {
	$set = array_values(
		array_filter(
			$set,
			function ( $r ) use ( $key ) {
				return rrt_insight_has( $r, $key );
			}
		)
	);
	usort(
		$set,
		function ( $a, $b ) use ( $key ) {
			return $a[ $key ] <=> $b[ $key ];
		}
	);
	$half = (int) floor( count( $set ) / 2 );
	if ( $half < $side ) {
		return null;
	}
	$low  = array_slice( $set, 0, $half );
	$high = array_slice( $set, count( $set ) - $half );
	return array(
		'low'  => $low,
		'high' => $high,
		'cut'  => $low[ $half - 1 ][ $key ],
		'top'  => $high[0][ $key ],
	);
}

/**
 * One observation, in the shape the screens use.
 *
 * - key, kind (listen or take), title
 * - state: found (a clear difference or movement), even (enough data, no clear difference), waiting (not enough data yet)
 * - text: the sentence; basis: what it rests on; need: what it still needs (waiting only)
 * - gap: the size of the difference in points, for ordering
 * - have, needed: counts behind the waiting line
 * - data: extra numbers for the teleprompter's suggestion
 */
function rrt_insight_make( $key, $kind, $title, $args ) {
	return array_merge(
		array(
			'key'    => $key,
			'kind'   => $kind,
			'title'  => $title,
			'state'  => 'waiting',
			'text'   => '',
			'basis'  => '',
			'need'   => '',
			'gap'    => 0,
			'have'   => 0,
			'needed' => 0,
			'data'   => array(),
		),
		$args
	);
}

/** The waiting line for an observation that counts readings. */
function rrt_insight_wait( $key, $title, $have, $rules, $what = '' ) {
	$more = max( 1, $rules['readings'] - $have );
	/* translators: %s: number of readings still needed. */
	$more_n = sprintf( _n( '%s more reading', '%s more readings', $more, 'foxfire-studio' ), number_format_i18n( $more ) );
	$need   = '' === $what
		/* translators: 1: readings needed, such as 3 more readings, 2: plays each needs. */
		? sprintf( __( 'Needs %1$s with at least %2$d plays.', 'foxfire-studio' ), $more_n, $rules['plays'] )
		/* translators: 1: readings needed, such as 3 more readings, 2: plays each needs, 3: what else each needs, such as and a known tempo. */
		: sprintf( __( 'Needs %1$s with at least %2$d plays %3$s.', 'foxfire-studio' ), $more_n, $rules['plays'], $what );
	return rrt_insight_make(
		$key,
		'listen',
		$title,
		array(
			'need'   => $need,
			'have'   => $have,
			'needed' => $rules['readings'],
			/* translators: %s: number of readings, such as 2 readings. */
			'basis'  => sprintf( __( '%s with enough plays so far.', 'foxfire-studio' ), rrt_insight_n( $have ) ),
		)
	);
}

/** The basis line shared by the reading comparisons. */
function rrt_insight_basis( $n, $rules ) {
	/* translators: 1: number of readings, such as 8 readings, 2: plays each. */
	return sprintf( __( 'Based on %1$s with at least %2$d plays each.', 'foxfire-studio' ), rrt_insight_n( $n ), $rules['plays'] );
}

/**
 * Compares two sides of readings and words the result.
 *
 * @param string $key    Observation key.
 * @param string $title  Its title.
 * @param array  $a      One side's readings.
 * @param array  $b      The other side's readings.
 * @param string $name_a How side a reads in a sentence ("Readings up to 4:10 long").
 * @param string $name_b How side b reads after "against" ("longer ones").
 * @param array  $rules  The minimums.
 * @param array  $data   Extra numbers to keep.
 */
function rrt_insight_two( $key, $title, $a, $b, $name_a, $name_b, $rules, $data = array() ) {
	$ra  = rrt_insight_rate( $a );
	$rb  = rrt_insight_rate( $b );
	$gap = abs( rrt_insight_pct( $ra ) - rrt_insight_pct( $rb ) );
	$n   = count( $a ) + count( $b );
	$hi  = $ra >= $rb;
	$data['better'] = $hi ? 'a' : 'b';
	$data['rates']  = array( rrt_insight_pct( $ra ), rrt_insight_pct( $rb ) );
	if ( $gap < $rules['gap'] ) {
		return rrt_insight_make(
			$key,
			'listen',
			$title,
			array(
				'state' => 'even',
				/* translators: 1: one group of readings, 2: its share heard to the end, 3: the other group, 4: its share. */
				'text'  => sprintf( __( 'No clear difference yet. %1$s were heard to the end by %2$d%% of plays on average, and %3$s by %4$d%%.', 'foxfire-studio' ), $name_a, rrt_insight_pct( $ra ), $name_b, rrt_insight_pct( $rb ) ),
				/* translators: %d: points. */
				'basis' => rrt_insight_basis( $n, $rules ) . ' ' . sprintf( __( 'A gap of %d points or more counts as clear.', 'foxfire-studio' ), $rules['gap'] ),
				'gap'   => $gap,
				'have'  => $n,
				'data'  => $data,
			)
		);
	}
	$text = $hi
		/* translators: 1: the group heard to the end more often, 2: its share, 3: the other group's share, 4: the other group. */
		? sprintf( __( '%1$s were heard to the end more often: by %2$d%% of plays on average, against %3$d%% for %4$s.', 'foxfire-studio' ), $name_a, rrt_insight_pct( $ra ), rrt_insight_pct( $rb ), $name_b )
		/* translators: 1: the group heard to the end more often, 2: its share, 3: the other group's share, 4: the other group. */
		: sprintf( __( '%1$s were heard to the end more often: by %2$d%% of plays on average, against %3$d%% for %4$s.', 'foxfire-studio' ), ucfirst( $name_b ), rrt_insight_pct( $rb ), rrt_insight_pct( $ra ), lcfirst( $name_a ) );
	return rrt_insight_make(
		$key,
		'listen',
		$title,
		array(
			'state' => 'found',
			'text'  => $text,
			'basis' => rrt_insight_basis( $n, $rules ),
			'gap'   => $gap,
			'have'  => $n,
			'data'  => $data,
		)
	);
}

/*
 * ---------------------------------------------------------------------------
 * Listener observations
 * ---------------------------------------------------------------------------
 */

/** Length: shorter half against longer half. */
function rrt_insight_length( $fair, $rules ) {
	$h = rrt_insight_halves( $fair, 'duration', $rules['side'] );
	$n = count( array_filter( $fair, function ( $r ) { return $r['duration'] > 0; } ) );
	if ( ! $h || $n < $rules['readings'] ) {
		return rrt_insight_wait( 'length', __( 'Length', 'foxfire-studio' ), $n, $rules );
	}
	$cut = rrt_clock( $h['cut'] );
	return rrt_insight_two( 'length', __( 'Length', 'foxfire-studio' ), $h['low'], $h['high'], sprintf( /* translators: %s: length, such as 4:10. */ __( 'Readings up to %s long', 'foxfire-studio' ), $cut ), __( 'longer ones', 'foxfire-studio' ), $rules, array( 'cut' => (int) round( $h['cut'] ) ) );
}

/** Tempo: slower half against quicker half, by each recording's words a minute. */
function rrt_insight_tempo( $fair, $rules ) {
	$h = rrt_insight_halves( $fair, 'tempo', $rules['side'] );
	$n = count( array_filter( $fair, function ( $r ) { return $r['tempo'] > 0; } ) );
	if ( ! $h || $n < $rules['readings'] ) {
		return rrt_insight_wait( 'tempo', __( 'Tempo', 'foxfire-studio' ), $n, $rules, __( 'and a known tempo', 'foxfire-studio' ) );
	}
	return rrt_insight_two( 'tempo', __( 'Tempo', 'foxfire-studio' ), $h['low'], $h['high'], sprintf( /* translators: %d: words a minute. */ __( 'Readings at up to %d words a minute', 'foxfire-studio' ), (int) $h['cut'] ), __( 'quicker ones', 'foxfire-studio' ), $rules, array( 'cut' => (int) $h['cut'] ) );
}

/** Tag or category: the term with the clearest difference between readings that have it and readings that do not. */
function rrt_insight_topic( $fair, $rules ) {
	$n     = count( $fair );
	$terms = array();
	foreach ( $fair as $r ) {
		foreach ( $r['terms'] as $id => $t ) {
			$terms[ $id ] = $t;
		}
	}
	$best = null;
	foreach ( $terms as $id => $t ) {
		$with = array_values( array_filter( $fair, function ( $r ) use ( $id ) { return isset( $r['terms'][ $id ] ); } ) );
		$rest = array_values( array_filter( $fair, function ( $r ) use ( $id ) { return ! isset( $r['terms'][ $id ] ); } ) );
		if ( count( $with ) < $rules['side'] || count( $rest ) < $rules['side'] ) {
			continue;
		}
		$name = 'tag' === $t['kind'] ? sprintf( /* translators: %s: tag. */ __( 'Readings tagged %s', 'foxfire-studio' ), $t['name'] ) : sprintf( /* translators: %s: category. */ __( 'Readings in %s', 'foxfire-studio' ), $t['name'] );
		$o    = rrt_insight_two( 'topic', __( 'Tag or category', 'foxfire-studio' ), $with, $rest, $name, __( 'the others', 'foxfire-studio' ), $rules, array( 'term' => $t['name'] ) );
		if ( ! $best || $o['gap'] > $best['gap'] ) {
			$best = $o;
		}
	}
	if ( $best && $n >= $rules['readings'] ) {
		if ( 'found' === $best['state'] ) {
			$best['basis'] .= ' ' . __( 'The tag or category with the clearest difference is shown.', 'foxfire-studio' );
		}
		return $best;
	}
	$o         = rrt_insight_wait( 'topic', __( 'Tag or category', 'foxfire-studio' ), $n, $rules );
	$o['need'] = $n < $rules['readings']
		/* translators: %d: number of readings. */
		? $o['need'] . ' ' . sprintf( __( 'A tag or category also needs %1$d of them, and %1$d without it.', 'foxfire-studio' ), $rules['side'] )
		/* translators: 1: number of readings, 2: plays each. */
		: sprintf( __( 'Needs a tag or category on at least %1$d readings with %2$d plays, and %1$d without it.', 'foxfire-studio' ), $rules['side'], $rules['plays'] );
	return $o;
}

/** Day: readings that went out on a weekday against those that went out at the weekend. */
function rrt_insight_day( $fair, $rules ) {
	$dated = array_values( array_filter( $fair, function ( $r ) { return null !== $r['weekday']; } ) );
	$wk    = array_values( array_filter( $dated, function ( $r ) { return $r['weekday'] <= 5; } ) );
	$we    = array_values( array_filter( $dated, function ( $r ) { return $r['weekday'] >= 6; } ) );
	if ( count( $dated ) < $rules['readings'] || count( $wk ) < $rules['side'] || count( $we ) < $rules['side'] ) {
		$o = rrt_insight_wait( 'day', __( 'Day published', 'foxfire-studio' ), count( $dated ), $rules );
		if ( count( $dated ) >= $rules['readings'] ) {
			/* translators: 1: readings needed on each side, 2: plays each, 3: weekend readings so far, 4: weekday readings so far. */
			$o['need'] = sprintf( __( 'Needs at least %1$d readings with %2$d plays that went out at the weekend, and %1$d on weekdays. So far: %3$d and %4$d.', 'foxfire-studio' ), $rules['side'], $rules['plays'], count( $we ), count( $wk ) );
		}
		return $o;
	}
	return rrt_insight_two( 'day', __( 'Day published', 'foxfire-studio' ), $we, $wk, __( 'Readings that went out at the weekend', 'foxfire-studio' ), __( 'weekday ones', 'foxfire-studio' ), $rules );
}

/** Time of day: morning, afternoon and evening, best against worst among those with enough readings. */
function rrt_insight_time( $fair, $rules ) {
	$names  = array( 'morning' => __( 'Readings that went out before noon', 'foxfire-studio' ), 'afternoon' => __( 'Readings that went out between noon and 5 pm', 'foxfire-studio' ), 'evening' => __( 'Readings that went out after 5 pm', 'foxfire-studio' ) );
	$short  = array( 'morning' => __( 'morning ones', 'foxfire-studio' ), 'afternoon' => __( 'afternoon ones', 'foxfire-studio' ), 'evening' => __( 'evening ones', 'foxfire-studio' ) );
	$groups = array( 'morning' => array(), 'afternoon' => array(), 'evening' => array() );
	$dated  = 0;
	foreach ( $fair as $r ) {
		if ( null === $r['hour'] ) {
			continue;
		}
		++$dated;
		$groups[ $r['hour'] < 12 ? 'morning' : ( $r['hour'] < 17 ? 'afternoon' : 'evening' ) ][] = $r;
	}
	$groups = array_filter( $groups, function ( $g ) use ( $rules ) { return count( $g ) >= $rules['side']; } );
	if ( $dated < $rules['readings'] || count( $groups ) < 2 ) {
		$o = rrt_insight_wait( 'time', __( 'Time published', 'foxfire-studio' ), $dated, $rules );
		if ( $dated >= $rules['readings'] ) {
			/* translators: 1: readings needed, 2: plays each. */
			$o['need'] = sprintf( __( 'Needs at least %1$d readings with %2$d plays in two of these: before noon, noon to 5 pm, after 5 pm.', 'foxfire-studio' ), $rules['side'], $rules['plays'] );
		}
		return $o;
	}
	uasort( $groups, function ( $a, $b ) { return rrt_insight_rate( $b ) <=> rrt_insight_rate( $a ); } );
	$keys = array_keys( $groups );
	$top  = $keys[0];
	$low  = $keys[ count( $keys ) - 1 ];
	return rrt_insight_two( 'time', __( 'Time published', 'foxfire-studio' ), $groups[ $top ], $groups[ $low ], $names[ $top ], $short[ $low ], $rules );
}

/** Delivery: the take each recording came from, split by one of its numbers. */
function rrt_insight_delivery( $key, $fair, $rules ) {
	$def = array(
		'accuracy' => array( __( 'Slips', 'foxfire-studio' ), __( 'from a take with a Whisper review', 'foxfire-studio' ) ),
		'pitch'    => array( __( 'Pitch movement', 'foxfire-studio' ), __( 'from a take with pitch measured', 'foxfire-studio' ) ),
		'ending'   => array( __( 'Phrase endings', 'foxfire-studio' ), __( 'from a take with endings measured', 'foxfire-studio' ) ),
	);
	list( $title, $what ) = $def[ $key ];
	$k = 'delivery_' . $key;
	$h = rrt_insight_halves( $fair, $key, $rules['side'] );
	$n = count( array_filter( $fair, function ( $r ) use ( $key ) { return rrt_insight_has( $r, $key ); } ) );
	if ( ! $h || $n < $rules['readings'] ) {
		return rrt_insight_wait( $k, $title, $n, $rules, sprintf( /* translators: %s: where the numbers come from, such as from a take with pitch measured. */ __( 'recorded %s', 'foxfire-studio' ), $what ) );
	}
	if ( 'accuracy' === $key ) {
		/* Higher accuracy means fewer slips, so the upper half reads first. */
		return rrt_insight_two( $k, $title, $h['high'], $h['low'], sprintf( /* translators: %s: accuracy percentage. */ __( 'Readings recorded with fewer slips (accuracy %s%% or higher)', 'foxfire-studio' ), rrt_insight_num( $h['top'] ) ), __( 'ones with more slips', 'foxfire-studio' ), $rules );
	}
	if ( 'pitch' === $key ) {
		return rrt_insight_two( $k, $title, $h['high'], $h['low'], sprintf( /* translators: %s: semitones. */ __( 'Readings with more pitch movement (%s semitones or more)', 'foxfire-studio' ), rrt_insight_num( $h['top'] ) ), __( 'flatter ones', 'foxfire-studio' ), $rules );
	}
	return rrt_insight_two( $k, $title, $h['low'], $h['high'], sprintf( /* translators: %s: decibels. */ __( 'Readings with firmer phrase endings (a fade of %s dB or less)', 'foxfire-studio' ), rrt_insight_num( $h['cut'] ) ), __( 'ones whose endings faded more', 'foxfire-studio' ), $rules );
}

/** Source: the channel whose listeners heard readings to the end most often, against the one least often. */
function rrt_insight_source( $from, $to, $rules ) {
	$ch = array_values(
		array_filter(
			rrt_dash_sources( $from, $to ),
			function ( $s ) use ( $rules ) {
				return $s['plays'] >= $rules['plays'];
			}
		)
	);
	if ( count( $ch ) < 2 ) {
		return rrt_insight_make(
			'source',
			'listen',
			__( 'Where listeners came from', 'foxfire-studio' ),
			array(
				/* translators: 1: plays each, 2: places so far. */
				'need'   => sprintf( __( 'Needs two places listeners come from with at least %1$d plays each. So far: %2$d.', 'foxfire-studio' ), $rules['plays'], count( $ch ) ),
				'basis'  => '',
				'have'   => count( $ch ),
				'needed' => 2,
			)
		);
	}
	foreach ( $ch as &$c ) {
		$c['rate'] = $c['completions'] / $c['plays'];
	}
	unset( $c );
	usort( $ch, function ( $a, $b ) { return $b['rate'] <=> $a['rate']; } );
	$top   = $ch[0];
	$low   = $ch[ count( $ch ) - 1 ];
	$gap   = abs( rrt_insight_pct( $top['rate'] ) - rrt_insight_pct( $low['rate'] ) );
	$plays = array_sum( array_column( $ch, 'plays' ) );
	/* translators: 1: number of plays, 2: number of places, 3: plays each. */
	$basis = sprintf( __( 'Based on %1$s plays from %2$d places with at least %3$d plays each.', 'foxfire-studio' ), number_format_i18n( $plays ), count( $ch ), $rules['plays'] );
	if ( $gap < $rules['gap'] ) {
		return rrt_insight_make(
			'source',
			'listen',
			__( 'Where listeners came from', 'foxfire-studio' ),
			array(
				'state' => 'even',
				/* translators: 1: one place, 2: its share, 3: another place, 4: its share. */
				'text'  => sprintf( __( 'No clear difference yet. Listeners from %1$s heard readings to the end %2$d%% of the time, and listeners from %3$s %4$d%%.', 'foxfire-studio' ), rrt_dash_channel( $top['channel'] ), rrt_insight_pct( $top['rate'] ), rrt_dash_channel( $low['channel'] ), rrt_insight_pct( $low['rate'] ) ),
				/* translators: %d: points. */
				'basis' => $basis . ' ' . sprintf( __( 'A gap of %d points or more counts as clear.', 'foxfire-studio' ), $rules['gap'] ),
				'gap'   => $gap,
				'have'  => count( $ch ),
			)
		);
	}
	return rrt_insight_make(
		'source',
		'listen',
		__( 'Where listeners came from', 'foxfire-studio' ),
		array(
			'state' => 'found',
			'text'  => sprintf( /* translators: 1: rrt_dash_channel( $top['channel'] ), 2: rrt_insight_pct( $top['rate'] ), 3: number_format_i18n( $top['plays'] ), 4: rrt_dash_channel( $low['channel'] ), 5: rrt_insight_pct( $low['rate'] ), 6: number_format_i18n( $low['plays'] ). */ __( 'Listeners from %1$s heard readings to the end most often (%2$d%% of %3$s plays), and listeners from %4$s least often (%5$d%% of %6$s plays).', 'foxfire-studio' ), rrt_dash_channel( $top['channel'] ), rrt_insight_pct( $top['rate'] ), number_format_i18n( $top['plays'] ), rrt_dash_channel( $low['channel'] ), rrt_insight_pct( $low['rate'] ), number_format_i18n( $low['plays'] ) ),
			'basis' => $basis,
			'gap'   => $gap,
			'have'  => count( $ch ),
		)
	);
}

/**
 * Every listener observation for a period.
 *
 * @return array Observations, in a fixed order.
 */
function rrt_insights_listen( $from, $to ) {
	$rules = rrt_insight_rules();
	$all   = rrt_insight_readings( $from, $to );
	$fair  = array_values(
		array_filter(
			$all,
			function ( $r ) use ( $rules ) {
				return $r['plays'] >= $rules['plays'];
			}
		)
	);
	return array(
		rrt_insight_length( $fair, $rules ),
		rrt_insight_tempo( $fair, $rules ),
		rrt_insight_topic( $fair, $rules ),
		rrt_insight_day( $fair, $rules ),
		rrt_insight_time( $fair, $rules ),
		rrt_insight_source( $from, $to, $rules ),
		rrt_insight_delivery( 'accuracy', $fair, $rules ),
		rrt_insight_delivery( 'pitch', $fair, $rules ),
		rrt_insight_delivery( 'ending', $fair, $rules ),
	);
}

/*
 * ---------------------------------------------------------------------------
 * Reading observations, from the reader's own full takes
 * ---------------------------------------------------------------------------
 */

/** What each reading observation measures, which way is better, and the smallest change worth a word. */
function rrt_insight_take_metrics() {
	return array(
		'pace'     => array( 'title' => __( 'Pace', 'foxfire-studio' ), 'unit' => /* translators: %s: words a minute. */ __( '%s wpm', 'foxfire-studio' ), 'better' => '', 'step' => 5, 'dec' => 0 ),
		'accuracy' => array( 'title' => __( 'Accuracy', 'foxfire-studio' ), 'unit' => '%s%%', 'better' => 'up', 'step' => 1, 'dec' => 1 ),
		'fillers'  => array( 'title' => __( 'Fillers', 'foxfire-studio' ), 'unit' => /* translators: %s: fillers a minute. */ __( '%s a minute', 'foxfire-studio' ), 'better' => 'down', 'step' => 0.3, 'dec' => 1 ),
		'ending'   => array( 'title' => __( 'Phrase-ending fade', 'foxfire-studio' ), 'unit' => /* translators: %s: decibels. */ __( '%s dB', 'foxfire-studio' ), 'better' => 'down', 'step' => 1, 'dec' => 1 ),
	);
}

/** A take's number for a reading observation, or null. */
function rrt_insight_take_value( $t, $key ) {
	$v = function ( $k ) use ( $t ) {
		return ( isset( $t[ $k ] ) && null !== $t[ $k ] && '' !== $t[ $k ] ) ? (float) $t[ $k ] : null;
	};
	if ( 'pace' === $key ) {
		$w = $v( 'wpm' );
		return $w > 0 ? $w : null;
	}
	if ( 'accuracy' === $key ) {
		return $v( 'accuracy' );
	}
	if ( 'ending' === $key ) {
		return $v( 'ending_drop_db' );
	}
	/* Fillers a minute, so long and short takes compare fairly. */
	$f = $v( 'fillers' );
	$d = $v( 'duration_s' );
	return ( null === $f || ! $d || $d < 20 ) ? null : $f / ( $d / 60 );
}

/**
 * Every reading observation for one reader.
 *
 * With fewer full takes than the minimum, each says how many more it needs.
 * From the minimum, it says where the reader stands over their latest takes.
 * From the trend count, it compares the latest five with the five before.
 */
function rrt_insights_takes( $user_id ) {
	$rules = rrt_insight_rules();
	$takes = rrt_full_takes( rrt_dash_takes( $user_id ) );
	$out   = array();
	$five  = $rules['takes'];
	foreach ( rrt_insight_take_metrics() as $key => $m ) {
		$vals = array();
		foreach ( $takes as $t ) {
			$x = rrt_insight_take_value( $t, $key );
			if ( null !== $x ) {
				$vals[] = $x;
			}
		}
		$n    = count( $vals );
		$f    = function ( $v ) use ( $m ) {
			return sprintf( $m['unit'], rrt_insight_num( $v, $m['dec'] ) );
		};
				if ( $n < $five ) {
			$out[] = rrt_insight_make(
				'take_' . $key,
				'take',
				$m['title'],
				array(
					'need'   => 'accuracy' === $key
						/* translators: %s: number of takes. */
						? sprintf( _n( 'Needs %s more full take with the Whisper review on.', 'Needs %s more full takes with the Whisper review on.', $five - $n, 'foxfire-studio' ), number_format_i18n( $five - $n ) )
						/* translators: %s: number of takes. */
						: sprintf( _n( 'Needs %s more full take.', 'Needs %s more full takes.', $five - $n, 'foxfire-studio' ), number_format_i18n( $five - $n ) ),
					/* translators: %s: number of takes. */
					'basis'  => sprintf( _n( '%s take so far.', '%s takes so far.', $n, 'foxfire-studio' ), number_format_i18n( $n ) ),
					'have'   => $n,
					'needed' => $five,
				)
			);
			continue;
		}
		$latest = array_slice( $vals, -$five );
		$avg    = array_sum( $latest ) / $five;
		if ( $n < $rules['trend'] ) {
			$out[] = rrt_insight_make(
				'take_' . $key,
				'take',
				$m['title'],
				array(
					'state'  => 'even',
					/* translators: 1: number of takes, 2: average, 3: lowest, 4: highest. */
					'text'   => sprintf( __( 'Over your latest %1$d full takes: %2$s on average, from %3$s to %4$s.', 'foxfire-studio' ), $five, $f( $avg ), $f( min( $latest ) ), $f( max( $latest ) ) ),
					/* translators: 1: number of takes, 2: takes needed for a trend. */
					'basis'  => sprintf( __( 'Based on %1$d takes. From %2$d, this compares your latest %1$d with the %1$d before, to show which way it is moving.', 'foxfire-studio' ), $five, $rules['trend'] ),
					'have'   => $n,
					'needed' => $rules['trend'],
				)
			);
			continue;
		}
		$before = array_slice( $vals, -2 * $five, $five );
		$was    = array_sum( $before ) / $five;
		$moved  = $avg - $was;
		$basis  = sprintf( /* translators: %d: number of takes. */ __( 'Your latest %1$d full takes against the %1$d before them.', 'foxfire-studio' ), $five );
		if ( abs( $moved ) < $m['step'] ) {
			$out[] = rrt_insight_make(
				'take_' . $key,
				'take',
				$m['title'],
				array(
					'state' => 'even',
					'text'  => sprintf( /* translators: 1: average now, 2: average before. */ __( 'Steady: %1$s on average, against %2$s before.', 'foxfire-studio' ), $f( $avg ), $f( $was ) ),
					'basis' => $basis,
					'have'  => $n,
				)
			);
			continue;
		}
		$good = null;
		if ( '' === $m['better'] ) {
			$text = $moved > 0
				/* translators: 1: average now, 2: average before. */
				? sprintf( __( 'Your pace has picked up: %1$s on average, against %2$s before.', 'foxfire-studio' ), $f( $avg ), $f( $was ) )
				/* translators: 1: average now, 2: average before. */
				: sprintf( __( 'Your pace has slowed: %1$s on average, against %2$s before.', 'foxfire-studio' ), $f( $avg ), $f( $was ) );
		} else {
			$good = ( 'up' === $m['better'] ) === ( $moved > 0 );
			$verb = array(
				'accuracy' => $good ? __( 'Your accuracy is rising', 'foxfire-studio' ) : __( 'Your accuracy has slipped', 'foxfire-studio' ),
				'fillers'  => $good ? __( 'You are using fewer fillers', 'foxfire-studio' ) : __( 'Fillers are creeping in', 'foxfire-studio' ),
				'ending'   => $good ? __( 'Your phrase endings are holding up better', 'foxfire-studio' ) : __( 'Your phrase endings are fading more', 'foxfire-studio' ),
			);
			/* translators: 1: what changed, such as Your accuracy is rising, 2: average now, 3: average before. */
			$text = sprintf( __( '%1$s: %2$s on average, against %3$s before.', 'foxfire-studio' ), $verb[ $key ], $f( $avg ), $f( $was ) );
		}
		$out[] = rrt_insight_make(
			'take_' . $key,
			'take',
			$m['title'],
			array(
				'state' => 'found',
				'text'  => $text,
				'basis' => $basis,
				/* Ordered after listener observations: a movement of one step counts as 5 points. */
				'gap'   => (int) round( 5 * abs( $moved ) / $m['step'] ),
				'have'  => $n,
				'data'  => array( 'good' => $good ),
			)
		);
	}
	return $out;
}

/*
 * ---------------------------------------------------------------------------
 * Picking and showing
 * ---------------------------------------------------------------------------
 */

/** Found observations first, biggest gap first; then even ones; then those still waiting, nearest first. */
function rrt_insight_sort( $list ) {
	$rank = array( 'found' => 0, 'even' => 1, 'waiting' => 2 );
	usort(
		$list,
		function ( $a, $b ) use ( $rank ) {
			$c = $rank[ $a['state'] ] <=> $rank[ $b['state'] ];
			if ( 0 !== $c ) {
				return $c;
			}
			if ( 'waiting' === $a['state'] ) {
				return ( $b['needed'] ? $b['have'] / $b['needed'] : 0 ) <=> ( $a['needed'] ? $a['have'] / $a['needed'] : 0 );
			}
			return $b['gap'] <=> $a['gap'];
		}
	);
	return $list;
}

/** The clearest found observations, listener ones first, at most $limit. */
function rrt_insight_top( $listen, $takes, $limit = 2 ) {
	$pick = function ( $list ) {
		return array_values( array_filter( rrt_insight_sort( $list ), function ( $o ) { return 'found' === $o['state']; } ) );
	};
	return array_slice( array_merge( $pick( $listen ), $pick( $takes ) ), 0, $limit );
}

/** One observation as a single sentence with its basis, for the email. */
function rrt_insight_line( $o ) {
	return trim( $o['text'] . ' ' . $o['basis'] );
}

/**
 * The report email's observations for a period: the two clearest listener
 * observations, as sentences.
 */
function rrt_insights_report( $from, $to ) {
	return array_map( 'rrt_insight_line', rrt_insight_top( rrt_insights_listen( $from, $to ), array(), 2 ) );
}

/**
 * A suggestion for the teleprompter, from the last 12 months: how long and
 * how quick readings were when listeners stayed. The teleprompter compares
 * it with the loaded script's own length and the reader's pace, and shows it
 * only when the script runs past it. Null when there is nothing to say, and
 * for anyone who may not see listener numbers.
 */
function rrt_insight_studio() {
	if ( ! function_exists( 'rrt_dash_cap' ) || ! current_user_can( rrt_dash_cap() ) ) {
		return null;
	}
	$r   = rrt_insight_default_range();
	$out = array();
	foreach ( rrt_insights_listen( $r['from'], $r['to'] ) as $o ) {
		if ( 'found' !== $o['state'] || ! isset( $o['data']['better'] ) || 'a' !== $o['data']['better'] ) {
			continue;
		}
		if ( 'length' === $o['key'] ) {
			$out['length'] = array(
				'cut'  => $o['data']['cut'],
				/* translators: 1: length, 2: their share heard to the end, 3: the others' share, 4: number of readings. */
				'text' => sprintf( __( 'Readings up to %1$s were heard to the end more often (%2$d%% against %3$d%%, %4$s).', 'foxfire-studio' ), rrt_clock( $o['data']['cut'] ), $o['data']['rates'][0], $o['data']['rates'][1], rrt_insight_n( $o['have'] ) ),
			);
		}
		if ( 'tempo' === $o['key'] ) {
			$out['tempo'] = array(
				'cut'  => $o['data']['cut'],
				/* translators: 1: words a minute, 2: their share heard to the end, 3: the others' share, 4: number of readings. */
				'text' => sprintf( __( 'Readings at up to %1$d wpm were heard to the end more often (%2$d%% against %3$d%%, %4$s).', 'foxfire-studio' ), $o['data']['cut'], $o['data']['rates'][0], $o['data']['rates'][1], rrt_insight_n( $o['have'] ) ),
			);
		}
	}
	return $out ? $out : null;
}

/** Prints one observation as a card. */
function rrt_insight_card( $o, $link = false ) {
	$cls = 'rrt-insight is-' . $o['state'];
	echo '<div class="' . esc_attr( $cls ) . '" data-key="' . esc_attr( $o['key'] ) . '">';
	echo '<div class="rrt-insight-title">' . esc_html( $o['title'] ) . '</div>';
	if ( 'waiting' === $o['state'] ) {
		echo '<p class="rrt-insight-need">' . esc_html( $o['need'] ) . '</p>';
		if ( $o['needed'] ) {
			$pct = min( 100, (int) round( 100 * $o['have'] / $o['needed'] ) );
			echo '<div class="rrt-insight-bar" role="img" aria-label="' . esc_attr( sprintf( /* translators: 1: how many so far, 2: how many needed. */ __( '%1$d of %2$d', 'foxfire-studio' ), $o['have'], $o['needed'] ) ) . '"><span style="width:' . (int) $pct . '%"></span></div>';
		}
		if ( '' !== $o['basis'] ) {
			echo '<p class="rrt-insight-basis">' . esc_html( $o['basis'] ) . '</p>';
		}
	} else {
		echo '<p class="rrt-insight-text">' . esc_html( $o['text'] ) . '</p>';
		echo '<p class="rrt-insight-basis">' . esc_html( $o['basis'] ) . '</p>';
	}
	echo '</div>';
}

/** The Overview's insights: the two clearest from the last 12 months, with a link to the rest. */
function rrt_insight_overview() {
	$r   = rrt_insight_default_range();
	$top = rrt_insight_top( rrt_insights_listen( $r['from'], $r['to'] ), rrt_insights_takes( get_current_user_id() ), 2 );
	echo '<div class="rrt-tools"><h2>' . esc_html__( 'Insights', 'foxfire-studio' ) . '</h2><a href="' . esc_url( rrt_dash_url( 'rrt-insights' ) ) . '">' . esc_html__( 'All insights, and what each one still needs', 'foxfire-studio' ) . '</a></div>';
	if ( ! $top ) {
		rrt_dash_empty( __( 'Nothing clear enough to report yet. Insights appear here by themselves as readings, plays and takes come in.', 'foxfire-studio' ) );
		return;
	}
	echo '<div class="rrt-insights">';
	foreach ( $top as $o ) {
		rrt_insight_card( $o );
	}
	echo '</div>';
	echo '<p class="rrt-small">' . esc_html__( 'From the last 12 months.', 'foxfire-studio' ) . '</p>';
}

/*
 * ---------------------------------------------------------------------------
 * The Insights screen
 * ---------------------------------------------------------------------------
 */

function rrt_dash_page_insights() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die( esc_html__( 'Not allowed.', 'foxfire-studio' ) );
	}
	$listen = current_user_can( rrt_dash_cap() );
	if ( $listen ) {
		rrt_dash_freshen();
	}
	$range = rrt_dash_range( '365' );
	$rules = rrt_insight_rules();
	rrt_dash_header( __( 'Insights', 'foxfire-studio' ), 'rrt-insights', $range, array(), $listen, sprintf( /* translators: 1: first day, 2: last day, 3: time zone. */ __( '%1$s to %2$s. Listener observations use the readings heard in this period. Days run midnight to midnight in %3$s.', 'foxfire-studio' ), rrt_dash_day( $range['from'], 'M j, Y' ), rrt_dash_day( $range['to'], 'M j, Y' ), $range['tz'] ) );

	echo '<p class="rrt-insights-intro">' . esc_html__( 'Observations to help plan the next reading. Each one says what it rests on, and stays quiet until there is enough to go on. They turn on by themselves as readings, plays and takes come in.', 'foxfire-studio' ) . '</p>';

	if ( $listen ) {
		echo '<h2>' . esc_html__( 'What your listeners tell you', 'foxfire-studio' ) . '</h2>';
		if ( ! rrt_listen_on() ) {
			echo '<p class="rrt-note">' . esc_html__( 'Listener analytics is switched off in Settings, so no new listens are being counted. These observations use what was counted while it was on.', 'foxfire-studio' ) . '</p>';
		}
		echo '<div class="rrt-insights">';
		foreach ( rrt_insight_sort( rrt_insights_listen( $range['from'], $range['to'] ) ) as $o ) {
			rrt_insight_card( $o );
		}
		echo '</div>';
	}

	echo '<h2>' . esc_html__( 'Your reading', 'foxfire-studio' ) . '</h2>';
	echo '<p class="rrt-small">' . esc_html__( 'From your own full takes, in the order you made them. Opening drills are left out.', 'foxfire-studio' ) . '</p>';
	echo '<div class="rrt-insights">';
	foreach ( rrt_insight_sort( rrt_insights_takes( get_current_user_id() ) ) as $o ) {
		rrt_insight_card( $o );
	}
	echo '</div>';

	$defs = array(
		__( 'Heard to the end', 'foxfire-studio' ) => __( 'Plays that reached the last 5 seconds of the reading. Each reading counts once in an average, so one busy reading cannot outweigh the rest.', 'foxfire-studio' ),
		__( 'Readings', 'foxfire-studio' ) => sprintf( /* translators: %d: plays. */ __( 'Each recording counts as its own reading. A reading counts once it has at least %d plays in the period.', 'foxfire-studio' ), $rules['plays'] ),
		__( 'When it is clear', 'foxfire-studio' ) => sprintf( /* translators: 1: readings, 2: readings on each side, 3: points. */ __( 'A comparison needs at least %1$d readings, at least %2$d on each side, and a gap of %3$d points or more. Below that, differences are mostly chance.', 'foxfire-studio' ), $rules['readings'], $rules['side'], $rules['gap'] ),
		__( 'Halves', 'foxfire-studio' ) => __( 'Length, tempo and the delivery comparisons sort the readings by that number and compare the lower half with the upper half. With an odd number, the middle reading is left out.', 'foxfire-studio' ),
		__( 'Tag or category', 'foxfire-studio' ) => __( 'Each tag and category with enough readings on both sides is tried, and the one with the clearest difference is shown.', 'foxfire-studio' ),
		__( 'Day and time', 'foxfire-studio' ) => __( 'When the recording went out, in the site\'s time zone, or when its post was published if the recording was added before that.', 'foxfire-studio' ),
		__( 'Delivery', 'foxfire-studio' ) => __( 'Slips, pitch movement and phrase endings come from the take each recording was made from, so they only count recordings made in the teleprompter.', 'foxfire-studio' ),
		__( 'Your reading', 'foxfire-studio' ) => sprintf( /* translators: 1: takes needed, 2: takes needed for a trend. */ __( 'Needs %1$d full takes. From %2$d, your latest %1$d are compared with the %1$d before. Fillers are counted per minute, so long and short takes compare fairly.', 'foxfire-studio' ), $rules['takes'], $rules['trend'] ),
	);
	if ( ! $listen ) {
		$defs = array_slice( $defs, -1, 1, true );
	}
	rrt_dash_defs( $defs );
	echo '</div>';
}
