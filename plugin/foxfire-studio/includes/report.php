<?php
/**
 * The report email (0.12.0).
 *
 * A regular email that says how the readings did, so nobody has to remember to
 * check the dashboard. By default it covers each calendar month and is sent on
 * the 1st of the next one; weekly, every two weeks and quarterly also work.
 *
 * Every listener number comes from the same functions the dashboard uses
 * (dashboard-data.php), so the email and the dashboard always agree for the
 * same dates. The email holds totals only, never anything about one visitor.
 *
 * Pieces:
 * - Settings in the rrt_report option (kept apart from rrt_settings, which the
 *   main Settings screen saves whole).
 * - Periods: which dates a report covers, worked out in the site's time zone.
 * - The hourly check (WP-Cron hook rrt_report_check): sends the report for the
 *   period that has just ended, once, and catches up if cron ran late.
 * - The email itself: HTML with a plain-text copy, and optional CSV files.
 * - The archive: one row per email in the rrt_reports table, with its HTML.
 *
 * The Reports screen in WP-admin is in report-admin.php.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * ---------------------------------------------------------------------------
 * Settings
 * ---------------------------------------------------------------------------
 */

/** The parts of the email that can be switched off. The headline is always included. */
function rrt_report_sections() {
	return array(
		'top'          => __( 'Top readings', 'foxfire-studio' ),
		'held_lost'    => __( 'Held them longest and lost them soonest', 'foxfire-studio' ),
		'sources'      => __( 'Where listeners came from', 'foxfire-studio' ),
		'listening'    => __( 'How they listened', 'foxfire-studio' ),
		'new'          => __( 'New readings', 'foxfire-studio' ),
		'progress'     => __( 'Your recording', 'foxfire-studio' ),
		'observations' => __( 'Observations, when there is enough data', 'foxfire-studio' ),
	);
}

/** How often the email can go out. */
function rrt_report_intervals() {
	return array(
		'month'   => __( 'Every month, on the 1st, covering the month before', 'foxfire-studio' ),
		'week'    => __( 'Every week, on Monday, covering Monday to Sunday', 'foxfire-studio' ),
		'2weeks'  => __( 'Every two weeks, on Monday, covering the two weeks before', 'foxfire-studio' ),
		'quarter' => __( 'Every quarter, on the 1st of January, April, July and October', 'foxfire-studio' ),
	);
}

/** Defaults: off until someone switches it on, monthly, 8 am, to the site's admin email. */
function rrt_report_defaults() {
	return array(
		'on'         => false,
		'interval'   => 'month',
		'hour'       => 8,
		'recipients' => array(),
		'sections'   => array_keys( rrt_report_sections() ),
		'csv'        => false,
	);
}

/** The saved settings, filled in with defaults. */
function rrt_report_settings() {
	$s = get_option( 'rrt_report', array() );
	$s = is_array( $s ) ? $s : array();
	$s = array_merge( rrt_report_defaults(), $s );
	if ( ! isset( rrt_report_intervals()[ $s['interval'] ] ) ) {
		$s['interval'] = 'month';
	}
	$s['hour']       = max( 0, min( 23, (int) $s['hour'] ) );
	$s['recipients'] = array_values( array_filter( (array) $s['recipients'], 'is_email' ) );
	$s['sections']   = array_values( array_intersect( (array) $s['sections'], array_keys( rrt_report_sections() ) ) );
	$s['on']         = ! empty( $s['on'] );
	$s['csv']        = ! empty( $s['csv'] );
	return $s;
}

/**
 * Cleans settings from the Reports form.
 *
 * @param array $in Raw form values.
 * @return array Clean settings, and any addresses that were not valid under 'bad'.
 */
function rrt_report_clean( $in ) {
	$cur = rrt_report_settings();
	$out = $cur;
	$bad = array();

	$out['on'] = ! empty( $in['on'] );
	if ( isset( $in['interval'] ) && isset( rrt_report_intervals()[ $in['interval'] ] ) ) {
		$out['interval'] = $in['interval'];
	}
	if ( isset( $in['hour'] ) ) {
		$out['hour'] = max( 0, min( 23, (int) $in['hour'] ) );
	}
	if ( isset( $in['recipients'] ) ) {
		$list = array();
		foreach ( preg_split( '/[\s,;]+/', (string) $in['recipients'] ) as $a ) {
			$a = trim( $a );
			if ( '' === $a ) {
				continue;
			}
			$e = sanitize_email( $a );
			if ( $e && is_email( $e ) ) {
				$list[ strtolower( $e ) ] = $e;
			} else {
				$bad[] = $a;
			}
		}
		$out['recipients'] = array_slice( array_values( $list ), 0, 10 );
	}
	$out['sections'] = array();
	if ( isset( $in['sections'] ) && is_array( $in['sections'] ) ) {
		$out['sections'] = array_values( array_intersect( array_keys( rrt_report_sections() ), array_map( 'sanitize_key', $in['sections'] ) ) );
	}
	$out['csv'] = ! empty( $in['csv'] );
	$out['bad'] = $bad;
	return $out;
}

/**
 * Saves settings. Switching on, or changing how often, starts with the next
 * period, so nothing old is sent.
 *
 * @param array $settings Clean settings from rrt_report_clean().
 */
function rrt_report_save( $settings ) {
	$cur = rrt_report_settings();
	unset( $settings['bad'] );
	if ( ( $settings['on'] && ! $cur['on'] ) || $settings['interval'] !== $cur['interval'] ) {
		$p = rrt_report_last_complete( $settings['interval'] );
		update_option( 'rrt_report_last', $p['key'], false );
		delete_option( 'rrt_report_attempts' );
	}
	update_option( 'rrt_report', $settings, false );
}

/** Who gets the email: the addresses in the settings, or the site's admin email. */
function rrt_report_recipients( $s = null ) {
	$s = $s ? $s : rrt_report_settings();
	return $s['recipients'] ? $s['recipients'] : array( get_option( 'admin_email' ) );
}

/*
 * ---------------------------------------------------------------------------
 * Periods
 * ---------------------------------------------------------------------------
 */

/** Now, in the site's time zone. Filterable so tests can move the clock. */
function rrt_report_now() {
	$ts = (int) apply_filters( 'rrt_report_now', time() );
	return ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( wp_timezone() );
}

/**
 * The period of a kind that contains a day.
 *
 * Two-week periods are counted from Monday 1 January 2024, so they always fall
 * on the same Mondays whatever day the setting was chosen.
 *
 * @param string            $interval month, week, 2weeks or quarter.
 * @param DateTimeImmutable $day      Any moment on the day.
 * @return array from, to (Y-m-d, both included), key, interval.
 */
function rrt_report_period_of( $interval, $day ) {
	$tz  = wp_timezone();
	$day = new DateTimeImmutable( $day->format( 'Y-m-d' ), $tz );
	switch ( $interval ) {
		case 'week':
			$from = $day->modify( '-' . ( (int) $day->format( 'N' ) - 1 ) . ' days' );
			$to   = $from->modify( '+6 days' );
			break;
		case '2weeks':
			$anchor = new DateTimeImmutable( '2024-01-01', $tz );
			$diff   = (int) $anchor->diff( $day )->format( '%r%a' );
			$start  = (int) floor( $diff / 14 ) * 14;
			$from   = $anchor->modify( ( $start >= 0 ? '+' : '' ) . $start . ' days' );
			$to     = $from->modify( '+13 days' );
			break;
		case 'quarter':
			$m    = (int) $day->format( 'n' );
			$qm   = (int) ( floor( ( $m - 1 ) / 3 ) * 3 + 1 );
			$from = new DateTimeImmutable( $day->format( 'Y' ) . '-' . sprintf( '%02d', $qm ) . '-01', $tz );
			$to   = $from->modify( '+3 months' )->modify( '-1 day' );
			break;
		default:
			$interval = 'month';
			$from     = new DateTimeImmutable( $day->format( 'Y-m-01' ), $tz );
			$to       = $from->modify( '+1 month' )->modify( '-1 day' );
	}
	$f = $from->format( 'Y-m-d' );
	$t = $to->format( 'Y-m-d' );
	return array(
		'from'     => $f,
		'to'       => $t,
		'key'      => $interval . ':' . $f . ':' . $t,
		'interval' => $interval,
	);
}

/** The period before a given one. */
function rrt_report_previous( $p ) {
	$d = new DateTimeImmutable( $p['from'], wp_timezone() );
	return rrt_report_period_of( $p['interval'], $d->modify( '-1 day' ) );
}

/** The period running now. */
function rrt_report_current( $interval = null ) {
	$interval = $interval ? $interval : rrt_report_settings()['interval'];
	return rrt_report_period_of( $interval, rrt_report_now() );
}

/** The most recent period that has ended. */
function rrt_report_last_complete( $interval = null ) {
	return rrt_report_previous( rrt_report_current( $interval ) );
}

/** The current period so far: from its first day to today. */
function rrt_report_so_far( $interval = null ) {
	$p            = rrt_report_current( $interval );
	$p['to']      = rrt_report_now()->format( 'Y-m-d' );
	$p['key']     = $p['interval'] . ':' . $p['from'] . ':' . $p['to'] . ':partial';
	$p['partial'] = true;
	return $p;
}

/** When the next scheduled email is due: the morning after the current period ends. */
function rrt_report_next_due( $s = null ) {
	$s   = $s ? $s : rrt_report_settings();
	$cur = rrt_report_current( $s['interval'] );
	$d   = new DateTimeImmutable( $cur['to'], wp_timezone() );
	return $d->modify( '+1 day' )->setTime( (int) $s['hour'], 0 );
}

/** How a period reads: "October 2026", "Q4 2026", or "Oct 5 to Oct 11, 2026". */
function rrt_report_period_label( $p ) {
	$from = new DateTimeImmutable( $p['from'] );
	$to   = new DateTimeImmutable( $p['to'] );
	if ( 'month' === $p['interval'] && empty( $p['partial'] ) ) {
		return rrt_dash_day( $p['from'], 'F Y' );
	}
	if ( 'quarter' === $p['interval'] && empty( $p['partial'] ) ) {
		return 'Q' . (int) ceil( (int) $from->format( 'n' ) / 3 ) . ' ' . $from->format( 'Y' ) . ' (' . rrt_dash_day( $p['from'], 'M j' ) . ' to ' . rrt_dash_day( $p['to'], 'M j' ) . ')';
	}
	$same_year = $from->format( 'Y' ) === $to->format( 'Y' );
	return rrt_dash_day( $p['from'], $same_year ? 'M j' : 'M j, Y' ) . ' to ' . rrt_dash_day( $p['to'], 'M j, Y' );
}

/*
 * ---------------------------------------------------------------------------
 * The numbers
 * ---------------------------------------------------------------------------
 */

/**
 * The change between two values, in the same terms as the dashboard: nothing
 * when either period has too few plays for the change to mean much.
 *
 * @return array|null text and direction (1 up, -1 down, 0 none), or null.
 */
function rrt_report_change( $now, $then, $ok, $kind = 'pct' ) {
	if ( ! $ok || null === $now || null === $then ) {
		return null;
	}
	if ( 'pt' === $kind ) {
		$d = (int) round( 100 * ( $now - $then ) );
		$t = abs( $d ) . ' pt';
	} elseif ( 'x' === $kind ) {
		$d = round( $now - $then, 2 );
		$t = abs( $d ) . '×';
	} else {
		if ( ! $then ) {
			return null;
		}
		$d = (int) round( 100 * ( $now - $then ) / $then );
		$t = abs( $d ) . '%';
	}
	if ( 0 == $d ) { // phpcs:ignore Universal.Operators.StrictComparisons
		return array(
			'text' => __( 'no change', 'foxfire-studio' ),
			'dir'  => 0,
		);
	}
	return array(
		'text' => ( $d > 0 ? 'up ' : 'down ' ) . $t,
		'dir'  => $d > 0 ? 1 : -1,
	);
}

/** How fast people listened, from each play's average speed: slower, normal, faster. */
function rrt_report_speed_mix( $from, $to ) {
	global $wpdb;
	$ts   = rrt_table( 'sessions' );
	$rows = $wpdb->get_col( $wpdb->prepare( "SELECT rate_sum / rate_n FROM $ts WHERE day BETWEEN %s AND %s AND played = 1 AND junk = 0 AND rate_n > 0", $from, $to ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$mix  = array(
		'slower' => 0,
		'normal' => 0,
		'faster' => 0,
		'much'   => 0,
		'n'      => 0,
	);
	foreach ( $rows as $r ) {
		$r = (float) $r;
		++$mix['n'];
		if ( $r < 0.95 ) {
			++$mix['slower'];
		} elseif ( $r <= 1.05 ) {
			++$mix['normal'];
		} elseif ( $r < 1.45 ) {
			++$mix['faster'];
		} else {
			++$mix['much'];
		}
	}
	return $mix;
}

/** Readings whose current recording was published in the period. */
function rrt_report_new_readings( $from, $to ) {
	global $wpdb;
	$tr   = rrt_table( 'recordings' );
	$rows = $wpdb->get_results( "SELECT post_id, duration_s, tempo_wpm, published_at FROM $tr WHERE is_current = 1 AND published_at IS NOT NULL ORDER BY published_at", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$out  = array();
	foreach ( $rows as $r ) {
		/* published_at is stored in UTC. */
		$day = wp_date( 'Y-m-d', strtotime( $r['published_at'] . ' UTC' ) );
		if ( $day < $from || $day > $to || ! get_post( (int) $r['post_id'] ) ) {
			continue;
		}
		$out[] = array(
			'post'     => (int) $r['post_id'],
			'title'    => get_the_title( (int) $r['post_id'] ),
			'day'      => $day,
			'duration' => (float) $r['duration_s'],
			'tempo'    => (int) $r['tempo_wpm'],
		);
	}
	return $out;
}

/** Averages of a column over takes, ignoring takes without a number. */
function rrt_report_avg( $takes, $col ) {
	$v = array();
	foreach ( $takes as $t ) {
		if ( isset( $t[ $col ] ) && null !== $t[ $col ] && '' !== $t[ $col ] ) {
			$v[] = (float) $t[ $col ];
		}
	}
	return $v ? array_sum( $v ) / count( $v ) : null;
}

/** Each reader's takes in the period and the one before, with a few averages. */
function rrt_report_progress( $p, $prev ) {
	$out = array();
	foreach ( rrt_dash_readers() as $uid => $name ) {
		$every = rrt_dash_takes( $uid, $p['from'], $p['to'] );
		/* Opening drills (0.14.0) are counted, and kept out of the averages. */
		$takes = rrt_full_takes( $every );
		$drill = count( $every ) - count( $takes );
		if ( ! $every ) {
			continue;
		}
		$before = rrt_full_takes( rrt_dash_takes( $uid, $prev['from'], $prev['to'] ) );
		$best   = null;
		foreach ( $takes as $t ) {
			if ( null !== $t['accuracy'] && ( null === $best || (float) $t['accuracy'] > (float) $best['accuracy'] ) ) {
				$best = $t;
			}
		}
		$goals = rrt_get_goals( $uid );
		$met   = null;
		if ( $goals ) {
			$met = 0;
			foreach ( $takes as $t ) {
				$all = true;
				foreach ( $goals as $g ) {
					if ( true !== rrt_goal_met( $t, $g ) ) {
						$all = false;
					}
				}
				$met += $all ? 1 : 0;
			}
		}
		$days = array();
		foreach ( $every as $t ) {
			$days[ $t['day'] ] = 1;
		}
		$out[] = array(
			'name'       => $name,
			'takes'      => count( $takes ),
			'drills'     => $drill,
			'days'       => count( $days ),
			'seconds'    => array_sum(
				array_map(
					function ( $t ) {
						return (float) $t['duration_s']; },
					$every
				)
			),
			'accuracy'   => rrt_report_avg( $takes, 'accuracy' ),
			'accuracy_p' => rrt_report_avg( $before, 'accuracy' ),
			'wpm'        => rrt_report_avg( $takes, 'wpm' ),
			'wpm_p'      => rrt_report_avg( $before, 'wpm' ),
			'fillers'    => rrt_report_avg( $takes, 'fillers' ),
			'fillers_p'  => rrt_report_avg( $before, 'fillers' ),
			'takes_p'    => count( $before ),
			'best'       => $best ? array(
				'title'    => get_the_title( (int) $best['post_id'] ),
				'day'      => $best['day'],
				'accuracy' => (float) $best['accuracy'],
			) : null,
			'goals'      => $goals ? array_map( 'rrt_goal_text', $goals ) : array(),
			'goals_met'  => $met,
		);
	}
	return $out;
}

/**
 * Plain-language observations for the period, from the insights (0.19.0):
 * the two clearest listener observations, each with what it rests on, or
 * none when the data is too thin. The Insights screen uses the same rules.
 */
function rrt_report_observations( $p ) {
	return rrt_insights_report( $p['from'], $p['to'] );
}

/** The words spoken at a moment of a reading, from its captions, or ''. */
function rrt_report_words_at( $post_id, $second ) {
	foreach ( rrt_dash_cues( $post_id ) as $c ) {
		if ( $second >= $c['s'] - 0.5 && $second <= $c['e'] + 0.5 ) {
			return $c['t'];
		}
	}
	return '';
}

/** A time as m:ss. */
function rrt_report_clock( $s ) {
	return rrt_clock( $s );
}

/**
 * Everything the email shows for a period, worked out once.
 *
 * @param array $p A period from rrt_report_period_of() or rrt_report_so_far().
 * @return array
 */
function rrt_report_data( $p ) {
	$prev = rrt_report_previous( $p );
	if ( ! empty( $p['partial'] ) ) {
		/* A period so far is compared with the same number of days of the one before. */
		$days            = (int) ( new DateTimeImmutable( $p['from'] ) )->diff( new DateTimeImmutable( $p['to'] ) )->days;
		$prev['to']      = min( $prev['to'], ( new DateTimeImmutable( $prev['from'] ) )->modify( '+' . $days . ' days' )->format( 'Y-m-d' ) );
		$prev['partial'] = true;
	}
	$min   = rrt_dash_min_plays();
	$now   = rrt_dash_totals( $p['from'], $p['to'] );
	$then  = rrt_dash_totals( $prev['from'], $prev['to'] );
	$ok    = $now['plays'] >= $min && $then['plays'] >= $min;
	$share = function ( $t, $k ) {
		return $t['plays'] ? $t[ $k ] / $t['plays'] : null;
	};

	$readings = rrt_dash_readings( $p['from'], $p['to'] );
	$played   = array_values(
		array_filter(
			$readings,
			function ( $r ) {
				return $r['plays'] > 0;
			}
		)
	);
	usort(
		$played,
		function ( $a, $b ) {
			$c = $b['plays'] <=> $a['plays'];
			return 0 !== $c ? $c : $b['listened'] <=> $a['listened'];
		}
	);

	/* Held longest and lost soonest: readings with enough plays, by the share heard to the end. */
	$held = null;
	$lost = null;
	$fair = array_values(
		array_filter(
			$played,
			function ( $r ) use ( $min ) {
				return $r['plays'] >= $min;
			}
		)
	);
	if ( count( $fair ) >= 2 ) {
		usort(
			$fair,
			function ( $a, $b ) {
				return ( $b['completions'] / $b['plays'] ) <=> ( $a['completions'] / $a['plays'] );
			}
		);
		$held = $fair[0];
		$lost = $fair[ count( $fair ) - 1 ];
		if ( null !== $lost['drop'] ) {
			$lost['drop_words'] = rrt_report_words_at( $lost['post'], $lost['drop'] + 2.5 );
		}
	}

	$sources = rrt_dash_sources( $p['from'], $p['to'] );
	$lnow    = rrt_dash_listeners( $p['from'], $p['to'] );
	$lthen   = rrt_dash_listeners( $prev['from'], $prev['to'] );

	return array(
		'period'       => $p,
		'prev'         => $prev,
		'label'        => rrt_report_period_label( $p ),
		'prev_label'   => rrt_report_period_label( $prev ),
		'tz'           => wp_timezone_string(),
		'min'          => $min,
		'ok'           => $ok,
		'listen_on'    => rrt_listen_on(),
		'now'          => $now,
		'then'         => $then,
		'listeners'    => $lnow,
		'listeners_p'  => $lthen,
		'held60'       => $share( $now, 'held60' ),
		'held60_p'     => $share( $then, 'held60' ),
		'complete'     => $share( $now, 'completions' ),
		'complete_p'   => $share( $then, 'completions' ),
		'readings'     => $readings,
		'top'          => array_slice( $played, 0, 5 ),
		'held'         => $held,
		'lost'         => $lost,
		'sources'      => array_slice( $sources, 0, 6 ),
		'all_sources'  => $sources,
		'speed'        => rrt_report_speed_mix( $p['from'], $p['to'] ),
		/* Wider listening numbers (1.2.0). */
		'rl'           => rrt_dash_readlisten( $p['from'], $p['to'] ),
		'fp'           => rrt_dash_first_play( $p['from'], $p['to'] ),
		'dl'           => ( function_exists( 'rrt_dl_on' ) && ( rrt_dl_on() || rrt_dl_any() ) ) ? rrt_dl_stats( $p['from'], $p['to'] ) : null,
		'new'          => rrt_report_new_readings( $p['from'], $p['to'] ),
		'progress'     => rrt_report_progress( $p, $prev ),
		'observations' => rrt_report_observations( $p ),
		'link'         => add_query_arg(
			array(
				'page'  => 'rrt-dashboard',
				'range' => 'custom',
				'from'  => $p['from'],
				'to'    => $p['to'],
			),
			admin_url( 'admin.php' )
		),
	);
}

/** The headline numbers kept with each archived report. */
function rrt_report_summary( $d ) {
	return array(
		'plays'       => $d['now']['plays'],
		'listeners'   => $d['listeners'],
		'listened_s'  => $d['now']['listened'],
		'completions' => $d['now']['completions'],
		'held60'      => $d['now']['held60'],
		'views'       => $d['now']['views'],
	);
}

/*
 * ---------------------------------------------------------------------------
 * The email
 * ---------------------------------------------------------------------------
 */

/** The accent colour from the look settings, when it reads well on white. */
function rrt_report_accent() {
	$s = rrt_settings();
	$c = isset( $s['colors']['accent'] ) ? sanitize_hex_color( $s['colors']['accent'] ) : '';
	if ( $c && 7 === strlen( $c ) ) {
		$r = hexdec( substr( $c, 1, 2 ) );
		$g = hexdec( substr( $c, 3, 2 ) );
		$b = hexdec( substr( $c, 5, 2 ) );
		/* Light accents (gold on a dark preset) would be hard to read on white. */
		if ( ( 0.2126 * $r + 0.7152 * $g + 0.0722 * $b ) < 140 ) {
			return $c;
		}
	}
	return '#5b3a8c';
}

/**
 * Builds the email.
 *
 * @param array $d        From rrt_report_data().
 * @param array $sections Section keys to include.
 * @return array subject, html, text.
 */
function rrt_report_render( $d, $sections ) {
	$show    = rrt_show_name();
	$accent  = rrt_report_accent();
	$on      = array_flip( $sections );
	$p       = $d['period'];
	$partial = ! empty( $p['partial'] );
	$subject = $partial ? sprintf( /* translators: 1: show name, 2: period. */ __( '%1$s: your readings, %2$s (so far)', 'foxfire-studio' ), $show, $d['label'] ) : sprintf( /* translators: 1: show name, 2: period. */ __( '%1$s: your readings, %2$s', 'foxfire-studio' ), $show, $d['label'] );
	$heading = $partial ? sprintf( /* translators: %s: period. */ __( 'Your readings, %s so far', 'foxfire-studio' ), $d['label'] ) : sprintf( /* translators: %s: period. */ __( 'Your readings, %s', 'foxfire-studio' ), $d['label'] );
	/* translators: 1: the period before, 2: time zone. */
	$compared = sprintf( __( 'Compared with %1$s. Days run midnight to midnight in %2$s.', 'foxfire-studio' ), $d['prev_label'], $d['tz'] );

	$h = array();
	$t = array();

	/* Small builders. Everything printed is escaped here. */
	$font   = "font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;";
	$muted  = 'color:#646970;';
	$sect   = function ( $title ) use ( &$h, &$t, $font, $accent ) {
		$h[] = '<tr><td style="padding:28px 24px 8px;' . $font . '"><h2 style="margin:0;font-size:18px;line-height:1.3;color:#1d2327;border-bottom:2px solid ' . esc_attr( $accent ) . ';padding-bottom:6px;">' . esc_html( $title ) . '</h2></td></tr>';
		$t[] = '';
		$t[] = strtoupper( $title );
		$t[] = str_repeat( '-', min( 60, strlen( $title ) ) );
	};
	$para   = function ( $text, $style = '' ) use ( &$h, &$t, $font ) {
		$h[] = '<tr><td style="padding:6px 24px;' . $font . 'font-size:15px;line-height:1.5;color:#1d2327;' . $style . '">' . esc_html( $text ) . '</td></tr>';
		$t[] = $text;
	};
	$change = function ( $c ) use ( $muted ) {
		if ( ! $c ) {
			return '';
		}
		$col = 0 === $c['dir'] ? '#646970' : ( $c['dir'] > 0 ? '#0a7a35' : '#b32d2e' );
		$arr = 0 === $c['dir'] ? '' : ( $c['dir'] > 0 ? '▲ ' : '▼ ' );
		return '<div style="font-size:13px;color:' . $col . ';">' . esc_html( $arr . $c['text'] ) . '</div>';
	};
	/* A table: rows of cells, the first row is the header, right-aligned from the second column. */
	$table = function ( $rows ) use ( &$h, &$t, $font ) {
		$out = '<tr><td style="padding:6px 24px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;' . $font . 'font-size:14px;color:#1d2327;">';
		foreach ( $rows as $i => $r ) {
			$out .= '<tr>';
			foreach ( $r as $j => $c ) {
				$align = $j ? 'right' : 'left';
				$st    = 'padding:7px 6px;text-align:' . $align . ';border-bottom:1px solid #e0e0e0;vertical-align:top;';
				if ( 0 === $i ) {
					$out .= '<th style="' . $st . 'font-size:12px;color:#646970;font-weight:600;">' . esc_html( $c ) . '</th>';
				} else {
					$out .= '<td style="' . $st . ( $j ? 'white-space:nowrap;' : '' ) . '">' . esc_html( $c ) . '</td>';
				}
			}
			$out .= '</tr>';
			$t[]  = ( $i ? '  ' : '  ' ) . implode( ' | ', $r );
		}
		$h[] = $out . '</table></td></tr>';
	};

	$pct = function ( $n, $dd ) {
		return $dd ? round( 100 * $n / $dd ) . '%' : '–';
	};

	/* Header. */
	$h[] = '<tr><td style="padding:24px 24px 4px;' . $font . '"><div style="font-size:13px;letter-spacing:.04em;text-transform:uppercase;color:' . esc_attr( $accent ) . ';font-weight:600;">' . esc_html( $show ) . '</div>'
		. '<h1 style="margin:6px 0 0;font-size:24px;line-height:1.25;color:#1d2327;">' . esc_html( $heading ) . '</h1>'
		. '<p style="margin:8px 0 0;font-size:14px;line-height:1.5;' . $muted . '">' . esc_html( $compared ) . '</p></td></tr>';
	$t[] = $show;
	$t[] = $heading;
	$t[] = $compared;

	/* 1. Headline. */
	$now  = $d['now'];
	$then = $d['then'];
	$sect( __( 'The headline', 'foxfire-studio' ) );
	if ( ! $d['listen_on'] ) {
		$para( __( 'Listener measurement is switched off in Settings, so there are no listener numbers.', 'foxfire-studio' ) );
	} else {
		$tiles = array(
			array( __( 'Plays', 'foxfire-studio' ), number_format_i18n( $now['plays'] ), $d['listeners'] ? 'about ' . number_format_i18n( $d['listeners'] ) . ' listeners' : '', rrt_report_change( $now['plays'], $then['plays'], $d['ok'] ) ),
			array( __( 'Still listening at 1 minute', 'foxfire-studio' ), null === $d['held60'] ? '–' : round( 100 * $d['held60'] ) . '%', $now['plays'] ? sprintf( /* translators: 1: plays still listening, 2: all plays. */ __( '%1$s of %2$s plays', 'foxfire-studio' ), number_format_i18n( $now['held60'] ), number_format_i18n( $now['plays'] ) ) : '', rrt_report_change( $d['held60'], $d['held60_p'], $d['ok'], 'pt' ) ),
			array( __( 'Heard to the end', 'foxfire-studio' ), null === $d['complete'] ? '–' : round( 100 * $d['complete'] ) . '%', $now['plays'] ? sprintf( /* translators: 1: plays heard to the end, 2: all plays. */ __( '%1$s of %2$s plays', 'foxfire-studio' ), number_format_i18n( $now['completions'] ), number_format_i18n( $now['plays'] ) ) : '', rrt_report_change( $d['complete'], $d['complete_p'], $d['ok'], 'pt' ) ),
			array( __( 'Listening time', 'foxfire-studio' ), rrt_dash_hours( $now['listened'] ), $now['views'] ? sprintf( /* translators: %s: number of visits. */ __( '%s visits to a page with a reading', 'foxfire-studio' ), number_format_i18n( $now['views'] ) ) : '', rrt_report_change( $now['listened'], $then['listened'], $d['ok'] ) ),
		);
		$cells = '';
		foreach ( $tiles as $i => $tile ) {
			if ( 2 === $i ) {
				$cells .= '</tr><tr>';
			}
			$cells .= '<td width="50%" valign="top" style="padding:6px;"><div style="background:#f6f7f7;border-radius:8px;padding:12px 14px;' . $font . '">'
				. '<div style="font-size:12px;color:#646970;">' . esc_html( $tile[0] ) . '</div>'
				. '<div style="font-size:26px;font-weight:700;color:#1d2327;line-height:1.2;margin-top:2px;">' . esc_html( $tile[1] ) . '</div>'
				. ( '' !== $tile[2] ? '<div style="font-size:12px;color:#646970;">' . esc_html( $tile[2] ) . '</div>' : '' )
				. $change( $tile[3] ) . '</div></td>';
			$t[]    = $tile[0] . ': ' . $tile[1] . ( '' !== $tile[2] ? ' (' . $tile[2] . ')' : '' ) . ( $tile[3] ? ', ' . $tile[3]['text'] : '' );
		}
		$h[] = '<tr><td style="padding:4px 18px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>' . $cells . '</tr></table></td></tr>';
		if ( ! $d['ok'] ) {
			$para( sprintf( /* translators: 1: $d['prev_label'], 2: $d['min']. */ __( 'Changes from %1$s are shown once both periods have at least %2$d plays.', 'foxfire-studio' ), $d['prev_label'], $d['min'] ), 'font-size:13px;' . $muted );
		}
	}

	$has_plays = $d['listen_on'] && $now['plays'] > 0;

	/* 2. Top readings. */
	if ( isset( $on['top'] ) && $has_plays ) {
		$sect( __( 'Top readings', 'foxfire-studio' ) );
		$rows = array( array( __( 'Reading', 'foxfire-studio' ), __( 'Plays', 'foxfire-studio' ), __( 'Heard to the end', 'foxfire-studio' ), __( 'Average heard', 'foxfire-studio' ) ) );
		foreach ( $d['top'] as $r ) {
			$rows[] = array( $r['title'], number_format_i18n( $r['plays'] ), $pct( $r['completions'], $r['plays'] ), $r['plays'] ? rrt_report_clock( $r['listened'] / $r['plays'] ) : '–' );
		}
		$table( $rows );
	}

	/* 3. Held longest, lost soonest. */
	if ( isset( $on['held_lost'] ) && $has_plays ) {
		$sect( __( 'Held them longest, lost them soonest', 'foxfire-studio' ) );
		if ( $d['held'] && $d['lost'] ) {
			$hd = $d['held'];
			$ls = $d['lost'];
			$para( sprintf( /* translators: 1: $hd['title'], 2: $pct( $hd['completions'], $hd['plays'] ), 3: number_format_i18n( $hd['plays'] ). */ __( 'Held longest: %1$s. %2$s of %3$s plays were heard to the end.', 'foxfire-studio' ), $hd['title'], $pct( $hd['completions'], $hd['plays'] ), number_format_i18n( $hd['plays'] ) ) );
			$lt = sprintf( /* translators: 1: $ls['title'], 2: $pct( $ls['completions'], $ls['plays'] ), 3: number_format_i18n( $ls['plays'] ). */ __( 'Lost soonest: %1$s. %2$s of %3$s plays were heard to the end.', 'foxfire-studio' ), $ls['title'], $pct( $ls['completions'], $ls['plays'] ), number_format_i18n( $ls['plays'] ) );
			if ( null !== $ls['drop'] ) {
				$lt .= ' ' . ( ! empty( $ls['drop_words'] )
					/* translators: 1: time in the reading, 2: the words being read then. */
					? sprintf( __( 'Most people who left did so around %1$s, at "%2$s".', 'foxfire-studio' ), rrt_report_clock( $ls['drop'] ), rtrim( wp_trim_words( $ls['drop_words'], 18, '…' ), '.,;:!? ' ) )
					/* translators: %s: time in the reading. */
					: sprintf( __( 'Most people who left did so around %s.', 'foxfire-studio' ), rrt_report_clock( $ls['drop'] ) ) );
			}
			$para( $lt );
		} else {
			$para( sprintf( /* translators: %s: $d['min']. */ __( 'This needs at least two readings with %d plays each in the period.', 'foxfire-studio' ), $d['min'] ), $muted );
		}
	}

	/* 4. Sources. */
	if ( isset( $on['sources'] ) && $has_plays && $d['sources'] ) {
		$sect( __( 'Where listeners came from', 'foxfire-studio' ) );
		$rows = array( array( __( 'From', 'foxfire-studio' ), __( 'Visits', 'foxfire-studio' ), __( 'Played', 'foxfire-studio' ), __( 'Heard to the end', 'foxfire-studio' ) ) );
		foreach ( $d['sources'] as $s ) {
			$rows[] = array( rrt_dash_channel( $s['channel'] ), number_format_i18n( $s['views'] ), $pct( $s['plays'], $s['views'] ), $pct( $s['completions'], $s['plays'] ) );
		}
		$table( $rows );
		$para( __( '"Played" is the share of visits from that source where the reading was played.', 'foxfire-studio' ), 'font-size:13px;' . $muted );
	}

	/* 5. How they listened. */
	if ( isset( $on['listening'] ) && $has_plays ) {
		$sect( __( 'How they listened', 'foxfire-studio' ) );
		$sp = $d['speed'];
		if ( $sp['n'] ) {
			$bits = array();
			foreach ( array(
				'normal' => __( 'at normal speed', 'foxfire-studio' ),
				'faster' => __( 'a little faster (up to about 1.4×)', 'foxfire-studio' ),
				'much'   => __( 'at 1.5× or more', 'foxfire-studio' ),
				'slower' => __( 'slower than normal', 'foxfire-studio' ),
			) as $k => $label ) {
				if ( $sp[ $k ] ) {
					/* translators: 1: percentage, 2: speed, such as at normal speed. */
					$bits[] = sprintf( __( '%1$s %2$s', 'foxfire-studio' ), $pct( $sp[ $k ], $sp['n'] ), $label );
				}
			}
			$para( sprintf( /* translators: %s: list of speeds. */ __( 'Speed: %s.', 'foxfire-studio' ), implode( ', ', $bits ) ) . ( null !== $now['rate'] ? ' ' . sprintf( /* translators: %s: average speed, such as 1.05. */ __( 'Average %s×.', 'foxfire-studio' ), number_format_i18n( $now['rate'], 2 ) ) : '' ) );
		}
		if ( $now['listened'] ) {
			$para( sprintf( /* translators: %s: $pct( min( $now['hidden_s'], $now['listened'] ), $now['liste. */ __( 'Listening in the background (another tab or the screen off): %s of the listening time.', 'foxfire-studio' ), $pct( min( $now['hidden_s'], $now['listened'] ), $now['listened'] ) ) );
		}
		$para( sprintf( /* translators: %s: $pct( $now['follow'], $now['plays'] ). */ __( 'Read along was used in %1$s of plays.', 'foxfire-studio' ), $pct( $now['follow'], $now['plays'] ) ) );
		/* Wider listening numbers (1.2.0). */
		$rl = $d['rl'];
		if ( $rl['measured'] ) {
			$para( sprintf( /* translators: 1: number of visits, 2 to 5: numbers of visits in each group. */ __( 'Of %1$s visits, %2$s listened only, %3$s read and listened, %4$s read only, and %5$s passed through.', 'foxfire-studio' ), number_format_i18n( $rl['measured'] ), number_format_i18n( $rl['listen'] ), number_format_i18n( $rl['both'] ), number_format_i18n( $rl['read'] ), number_format_i18n( $rl['pass'] ) ) );
		}
		$fp = $d['fp'];
		if ( $fp['all']['n'] ) {
			$para( sprintf( /* translators: 1: a time, such as 8 s, 2: share of plays. */ __( 'Visitors pressed play after %1$s (the median); %2$s did so within 10 seconds.', 'foxfire-studio' ), rrt_dash_secs( $fp['all']['median'] ), $pct( $fp['all']['bands']['b10'], $fp['all']['n'] ) ) );
		}
		if ( $rl['new'] + $rl['back'] ) {
			$para( sprintf( /* translators: 1: share of listeners, 2: number of returning listeners, 3: all listeners counted. */ __( 'Returning listeners: %1$s (%2$s of %3$s).', 'foxfire-studio' ), $pct( $rl['back'], $rl['new'] + $rl['back'] ), number_format_i18n( $rl['back'] ), number_format_i18n( $rl['new'] + $rl['back'] ) ) );
		}
	}

	/* 5b. Podcast apps (1.2.0), with How they listened. */
	if ( isset( $on['listening'] ) && $d['dl'] ) {
		$sect( __( 'Podcast apps', 'foxfire-studio' ) );
		$dl = $d['dl'];
		if ( $dl['total'] ) {
			$apps = array();
			foreach ( array_slice( $dl['apps'], 0, 4, true ) as $app => $n ) {
				$apps[] = $app . ' ' . $pct( $n, $dl['total'] );
			}
			$para( sprintf( /* translators: 1: number of downloads, 2: list of apps with their shares. */ _n( '%1$s download: %2$s.', '%1$s downloads: %2$s.', $dl['total'], 'foxfire-studio' ), number_format_i18n( $dl['total'] ), implode( ', ', $apps ) ) );
			/* Unusual days (1.24.0). */
			$odd = function_exists( 'rrt_dl_unusual' ) ? rrt_dl_unusual( $d['period']['from'], $d['period']['to'] ) : array();
			if ( $odd ) {
				$list = array();
				foreach ( array_slice( $odd, 0, 4 ) as $o ) {
					$list[] = wp_date( 'j M', strtotime( $o['day'] . ' 12:00' ) ) . ' (' . ( 'all' === $o['what'] ? __( 'all downloads', 'foxfire-studio' ) : rrt_ua_app_label( $o['what'] ) ) . ', ' . number_format_i18n( $o['n'] ) . ')';
				}
				/* translators: %s: a list of days. */
				$para( sprintf( __( 'Unusual days worth a look on the Overview: %s.', 'foxfire-studio' ), implode( ', ', $list ) ) );
			}
		} else {
			$para( __( 'No downloads in this period.', 'foxfire-studio' ), $muted );
		}
		/* The twice-yearly review the IAB guidelines ask for (1.24.0). */
		if ( function_exists( 'rrt_dl_review_due' ) && rrt_dl_review_due() ) {
			$para( __( 'A review of how downloads are counted is due: open the Overview and press Reviewed once you have looked.', 'foxfire-studio' ) );
		}
	}

	/* 6. New readings. */
	if ( isset( $on['new'] ) ) {
		$sect( __( 'New readings', 'foxfire-studio' ) );
		if ( $d['new'] ) {
			$rows = array( array( __( 'Reading', 'foxfire-studio' ), __( 'Published', 'foxfire-studio' ), __( 'Length', 'foxfire-studio' ), __( 'Pace', 'foxfire-studio' ) ) );
			foreach ( $d['new'] as $n ) {
				$rows[] = array( $n['title'], rrt_dash_day( $n['day'] ), $n['duration'] ? rrt_report_clock( $n['duration'] ) : '–', $n['tempo'] ? sprintf( /* translators: %s: words a minute. */ __( '%s wpm', 'foxfire-studio' ), $n['tempo'] ) : '–' );
			}
			$table( $rows );
		} else {
			$para( __( 'No new readings were published in this period.', 'foxfire-studio' ), $muted );
		}
	}

	/* 7. Your recording. */
	if ( isset( $on['progress'] ) ) {
		$sect( __( 'Your recording', 'foxfire-studio' ) );
		if ( ! $d['progress'] ) {
			$para( __( 'No takes were recorded in this period.', 'foxfire-studio' ), $muted );
		}
		foreach ( $d['progress'] as $r ) {
			/* translators: %s: number of takes. */
			$takes = sprintf( _n( '%s take', '%s takes', $r['takes'], 'foxfire-studio' ), number_format_i18n( $r['takes'] ) );
			if ( $r['drills'] ) {
				/* translators: 1: takes, such as 3 takes, 2: number of opening drills. */
				$takes = sprintf( _n( '%1$s and %2$s opening drill', '%1$s and %2$s opening drills', $r['drills'], 'foxfire-studio' ), $takes, number_format_i18n( $r['drills'] ) );
			}
			/* translators: 1: reader, 2: takes, 3: number of days, 4: time spent reading. */
			$line = sprintf( _n( '%1$s: %2$s on %3$s day, %4$s of reading.', '%1$s: %2$s on %3$s days, %4$s of reading.', $r['days'], 'foxfire-studio' ), $r['name'], $takes, number_format_i18n( $r['days'] ), rrt_dash_hours( $r['seconds'] ) );
			$para( $line, 'font-weight:600;' );
			$facts = array();
			if ( null !== $r['accuracy'] ) {
				$facts[] = null !== $r['accuracy_p'] ? sprintf( /* translators: 1: accuracy, 2: accuracy the period before. */ __( 'accuracy %1$d%% (%2$d%% the period before)', 'foxfire-studio' ), round( $r['accuracy'] ), round( $r['accuracy_p'] ) ) : sprintf( /* translators: %d: accuracy. */ __( 'accuracy %d%%', 'foxfire-studio' ), round( $r['accuracy'] ) );
			}
			if ( null !== $r['wpm'] ) {
				$facts[] = null !== $r['wpm_p'] ? sprintf( /* translators: 1: words a minute, 2: the period before. */ __( 'pace %1$d wpm (%2$d)', 'foxfire-studio' ), round( $r['wpm'] ), round( $r['wpm_p'] ) ) : sprintf( /* translators: %d: words a minute. */ __( 'pace %d wpm', 'foxfire-studio' ), round( $r['wpm'] ) );
			}
			if ( null !== $r['fillers'] ) {
				$facts[] = null !== $r['fillers_p'] ? sprintf( /* translators: 1: fillers a take, 2: the period before. */ __( '%1$s fillers a take (%2$s)', 'foxfire-studio' ), number_format_i18n( $r['fillers'], 1 ), number_format_i18n( $r['fillers_p'], 1 ) ) : sprintf( /* translators: %s: fillers a take. */ __( '%s fillers a take', 'foxfire-studio' ), number_format_i18n( $r['fillers'], 1 ) );
			}
			if ( $facts ) {
				$para( sprintf( /* translators: %s: list of averages. */ __( 'On average: %s.', 'foxfire-studio' ), implode( ', ', $facts ) ) );
			}
			if ( $r['best'] ) {
				$para( sprintf( /* translators: 1: $r['best']['title'], 2: rrt_dash_day( $r['best']['day'] ), 3: round( $r['best']['accuracy'] ). */ __( 'Best take: %1$s on %2$s, %3$d%% accurate.', 'foxfire-studio' ), $r['best']['title'], rrt_dash_day( $r['best']['day'] ), round( $r['best']['accuracy'] ) ) );
			}
			if ( $r['goals'] && null !== $r['goals_met'] ) {
				$para( sprintf( /* translators: 1: implode( '; ', $r['goals'] ), 2: $r['goals_met'], 3: $r['takes']. */ __( 'Goals (%1$s): met in %2$d of %3$d takes.', 'foxfire-studio' ), implode( '; ', $r['goals'] ), $r['goals_met'], $r['takes'] ) );
			}
		}
	}

	/* 8. Observations: only when there is something to say. */
	if ( isset( $on['observations'] ) && $d['observations'] ) {
		$sect( __( 'Worth noticing', 'foxfire-studio' ) );
		foreach ( $d['observations'] as $o ) {
			$para( $o );
		}
	}

	/* 9. Link. */
	$h[] = '<tr><td style="padding:24px;' . $font . '"><a href="' . esc_url( $d['link'] ) . '" style="display:inline-block;background:' . esc_attr( $accent ) . ';color:#ffffff;text-decoration:none;font-weight:600;font-size:15px;padding:11px 18px;border-radius:6px;">' . esc_html__( 'See these dates in the dashboard', 'foxfire-studio' ) . '</a></td></tr>';
	$t[] = '';
	$t[] = sprintf( /* translators: %s: link. */ __( 'See these dates in the dashboard: %s', 'foxfire-studio' ), $d['link'] );

	$foot = sprintf( /* translators: %s: the site's address. */ __( 'Sent by Foxfire Studio on %s. Change who gets this, how often, or switch it off in Foxfire Studio, Reports.', 'foxfire-studio' ), wp_parse_url( home_url(), PHP_URL_HOST ) );
	$h[]  = '<tr><td style="padding:8px 24px 24px;' . $font . 'font-size:12px;line-height:1.5;' . $muted . '">' . esc_html( $foot ) . '<br>' . esc_html__( 'Numbers count plays and listening on this site only. Totals only: nothing in this email identifies a visitor.', 'foxfire-studio' ) . '</td></tr>';
	$t[]  = '';
	$t[]  = $foot;

	$html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><title>' . esc_html( $subject ) . '</title></head>'
		. '<body style="margin:0;padding:0;background:#eef0f2;">'
		. '<div style="display:none;max-height:0;overflow:hidden;">' . esc_html( $has_plays ? sprintf( /* translators: 1: number of plays, 2: listening time. */ __( '%1$s plays, %2$s of listening.', 'foxfire-studio' ), number_format_i18n( $now['plays'] ), rrt_dash_hours( $now['listened'] ) ) : __( 'Your readings report.', 'foxfire-studio' ) ) . '</div>'
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef0f2;"><tr><td align="center" style="padding:16px 8px;">'
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:10px;border-top:4px solid ' . esc_attr( $accent ) . ';">'
		. implode( '', $h )
		. '</table></td></tr></table></body></html>';

	return array(
		'subject' => $subject,
		'html'    => $html,
		'text'    => implode( "\n", $t ) . "\n",
	);
}

/** The period's readings and sources as CSV text, for attaching. */
function rrt_report_csv( $d ) {
	$files = array();
	$rows  = array( array( __( 'Reading', 'foxfire-studio' ), __( 'Post ID', 'foxfire-studio' ), __( 'Length (s)', 'foxfire-studio' ), __( 'Pace (wpm)', 'foxfire-studio' ), __( 'Page visits', 'foxfire-studio' ), __( 'Plays', 'foxfire-studio' ), __( 'Still listening at 1 minute', 'foxfire-studio' ), __( 'Heard to the end', 'foxfire-studio' ), __( 'Seconds heard', 'foxfire-studio' ), __( 'Average speed', 'foxfire-studio' ), __( 'Most left at (s)', 'foxfire-studio' ) ) );
	foreach ( $d['readings'] as $r ) {
		$rows[] = array( $r['title'], $r['post'], round( $r['duration'], 1 ), $r['tempo'], $r['views'], $r['plays'], $r['held60'], $r['completions'], $r['listened'], null === $r['rate'] ? '' : $r['rate'], null === $r['drop'] ? '' : $r['drop'] );
	}
	$files['readings'] = $rows;
	$rows              = array( array( __( 'Channel', 'foxfire-studio' ), __( 'Page visits', 'foxfire-studio' ), __( 'Plays', 'foxfire-studio' ), __( 'Heard to the end', 'foxfire-studio' ), __( 'Seconds heard', 'foxfire-studio' ) ) );
	foreach ( $d['all_sources'] as $s ) {
		$rows[] = array( rrt_dash_channel( $s['channel'] ), $s['views'], $s['plays'], $s['completions'], $s['listened'] );
	}
	$files['sources'] = $rows;
	$out              = array();
	foreach ( $files as $name => $rows ) {
		$fh = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		foreach ( $rows as $r ) {
			fputcsv( $fh, rrt_csv_safe( $r ) );
		}
		rewind( $fh );
		$out[ $name ] = stream_get_contents( $fh );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
	return $out;
}

/*
 * ---------------------------------------------------------------------------
 * Sending and the archive
 * ---------------------------------------------------------------------------
 */

/**
 * Sends the report for a period and keeps a copy.
 *
 * @param array  $p       The period.
 * @param string $trigger schedule, manual or test.
 * @param array  $to      Addresses; the settings' recipients when empty.
 * @return array ok (bool), error (string), id (archive row).
 */
function rrt_report_send( $p, $trigger = 'manual', $to = array() ) {
	if ( get_transient( 'rrt_report_lock' ) ) {
		return array(
			'ok'    => false,
			'error' => __( 'Another report is being sent right now. Try again in a minute.', 'foxfire-studio' ),
			'id'    => 0,
		);
	}
	set_transient( 'rrt_report_lock', 1, 5 * MINUTE_IN_SECONDS );

	/* Bring the daily summaries up to date first, so the email matches the dashboard. */
	if ( function_exists( 'rrt_listen_rollup' ) ) {
		rrt_listen_rollup();
	}
	if ( ! empty( $p['partial'] ) && function_exists( 'rrt_listen_rollup_day' ) ) {
		rrt_listen_rollup_day( wp_date( 'Y-m-d' ) );
	}

	$s    = rrt_report_settings();
	$to   = $to ? $to : rrt_report_recipients( $s );
	$d    = rrt_report_data( $p );
	$mail = rrt_report_render( $d, $s['sections'] );

	$files = array();
	if ( $s['csv'] ) {
		$dir = trailingslashit( get_temp_dir() ) . 'rrt-report-' . wp_generate_password( 12, false );
		if ( wp_mkdir_p( $dir ) ) {
			foreach ( rrt_report_csv( $d ) as $name => $csv ) {
				$f = $dir . '/' . $name . '-' . $p['from'] . '-to-' . $p['to'] . '.csv';
				file_put_contents( $f, $csv ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				$files[] = $f;
			}
		}
	}

	$error = '';
	$catch = function ( $e ) use ( &$error ) {
		$error = is_wp_error( $e ) ? $e->get_error_message() : __( 'The mail could not be sent.', 'foxfire-studio' );
	};
	$alt   = function ( $phpmailer ) use ( $mail ) {
		$phpmailer->AltBody = $mail['text']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
	};
	add_action( 'wp_mail_failed', $catch );
	add_action( 'phpmailer_init', $alt );
	$ok = wp_mail( $to, $mail['subject'], $mail['html'], array( 'Content-Type: text/html; charset=UTF-8' ), $files );
	remove_action( 'wp_mail_failed', $catch );
	remove_action( 'phpmailer_init', $alt );

	foreach ( $files as $f ) {
		wp_delete_file( $f );
	}
	if ( $files ) {
		@rmdir( dirname( $files[0] ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
	}

	global $wpdb;
	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		rrt_table( 'reports' ),
		array(
			'period_from' => $p['from'],
			'period_to'   => $p['to'],
			'kind'        => $p['interval'] . ( empty( $p['partial'] ) ? '' : '-partial' ),
			'trigger_by'  => $trigger,
			'sent_at'     => current_time( 'mysql', true ),
			'recipients'  => implode( ', ', $to ),
			'status'      => $ok ? 'sent' : 'failed',
			'error'       => substr( $error, 0, 250 ),
			'subject'     => substr( $mail['subject'], 0, 250 ),
			'summary'     => wp_json_encode( rrt_report_summary( $d ) ),
			'html'        => $mail['html'],
		)
	);
	$id = (int) $wpdb->insert_id;
	delete_transient( 'rrt_report_lock' );
	return array(
		'ok'    => (bool) $ok,
		'error' => $error,
		'id'    => $id,
	);
}

/**
 * The hourly check. Sends the report for the period that has just ended, once,
 * as soon as it is due. If cron was late or the site quiet, it sends at the next
 * run. A failed send is tried again at the next three runs, then left as failed
 * in the archive.
 */
function rrt_report_check() {
	$s = rrt_report_settings();
	if ( ! $s['on'] ) {
		return;
	}
	$p   = rrt_report_last_complete( $s['interval'] );
	$due = ( new DateTimeImmutable( $p['to'], wp_timezone() ) )->modify( '+1 day' )->setTime( (int) $s['hour'], 0 );
	if ( get_option( 'rrt_report_last' ) === $p['key'] || rrt_report_now() < $due ) {
		return;
	}
	$tries = get_option( 'rrt_report_attempts', array() );
	$tries = is_array( $tries ) && isset( $tries['key'] ) && $tries['key'] === $p['key'] ? $tries : array(
		'key' => $p['key'],
		'n'   => 0,
	);
	if ( $tries['n'] >= 4 ) {
		return;
	}
	$r = rrt_report_send( $p, 'schedule' );
	if ( $r['ok'] ) {
		update_option( 'rrt_report_last', $p['key'], false );
		delete_option( 'rrt_report_attempts' );
	} elseif ( __( 'Another report is being sent right now. Try again in a minute.', 'foxfire-studio' ) !== $r['error'] ) {
		++$tries['n'];
		update_option( 'rrt_report_attempts', $tries, false );
	}
}

add_action( 'rrt_report_check', 'rrt_report_check' );
add_action(
	'init',
	function () {
		if ( ! wp_next_scheduled( 'rrt_report_check' ) ) {
			wp_schedule_event( time() + 900, 'hourly', 'rrt_report_check' );
		}
	}
);
register_deactivation_hook(
	RRT_FILE,
	function () {
		wp_clear_scheduled_hook( 'rrt_report_check' );
	}
);

/** Creates the archive table. Called from rrt_stats_install(). */
function rrt_report_install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$t = rrt_table( 'reports' );
	dbDelta(
		"CREATE TABLE $t (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		period_from date NOT NULL,
		period_to date NOT NULL,
		kind varchar(20) NOT NULL DEFAULT '',
		trigger_by varchar(10) NOT NULL DEFAULT '',
		sent_at datetime NOT NULL,
		recipients text,
		status varchar(10) NOT NULL DEFAULT '',
		error varchar(255) NOT NULL DEFAULT '',
		subject varchar(255) NOT NULL DEFAULT '',
		summary text,
		html longtext,
		PRIMARY KEY  (id),
		KEY sent_at (sent_at)
		) {$wpdb->get_charset_collate()};"
	);
}

/** Past reports, newest first. */
function rrt_report_archive( $limit = 50 ) {
	global $wpdb;
	$t = rrt_table( 'reports' );
	return $wpdb->get_results( $wpdb->prepare( "SELECT id, period_from, period_to, kind, trigger_by, sent_at, recipients, status, error, subject, summary FROM $t ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
}

/** One archived report's HTML. */
function rrt_report_archived_html( $id ) {
	global $wpdb;
	$t = rrt_table( 'reports' );
	return (string) $wpdb->get_var( $wpdb->prepare( "SELECT html FROM $t WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
}

/*
 * ---------------------------------------------------------------------------
 * Cron health
 * ---------------------------------------------------------------------------
 */

/**
 * Whether scheduled jobs run on time. WP-Cron only runs when the site gets a
 * visit, so on a quiet site jobs can be hours late; a real cron job on the
 * server fixes that.
 *
 * @return array state (ok, late, off), late_by (seconds), server (bool: DISABLE_WP_CRON set).
 */
function rrt_report_cron_health() {
	$server = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
	$late   = 0;
	foreach ( array( 'rrt_report_check', 'rrt_listen_hourly' ) as $hook ) {
		$next = wp_next_scheduled( $hook );
		if ( $next ) {
			$late = max( $late, time() - (int) $next );
		}
	}
	$state = $late > 30 * MINUTE_IN_SECONDS ? 'late' : 'ok';
	return array(
		'state'   => $state,
		'late_by' => max( 0, $late ),
		'server'  => $server,
	);
}
