<?php
/**
 * Accessibility helpers (1.26.0): the readability check for the colours in
 * Settings, Make it readable, and the "no captions yet" line for readings
 * without a transcript.
 *
 * The check lists each pair of colours the player and the studio show text
 * with, its contrast ratio, and whether it reaches WCAG 2.2 AA (4.5 to 1 for
 * text). Make it readable moves only the colours that fall short, towards
 * white or black, by the smallest step that makes every pair they are in
 * pass. Backgrounds are never moved, so the look stays the owner's.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The ratio text needs under WCAG 2.2 AA. */
const RRT_A11Y_TEXT = 4.5;

/** The ratio words ahead of the studio's reading line are held at (1.27.0): large text, 24 px and over. */
const RRT_A11Y_AHEAD = 3.0;

/**
 * How much of the text colour the studio's words ahead are drawn with
 * (1.27.0): 42 percent over the page background, or more when that would
 * fall under 3 to 1. Used by the studio's tokens, the look preview and the
 * check.
 *
 * @param array $colors Key => colour, with text and bg.
 * @return int Percent, 42 to 100.
 */
function rrt_a11y_ahead_pct( $colors ) {
	$text = isset( $colors['text'] ) ? rrt_a11y_hex( $colors['text'], '' ) : '';
	$bg   = isset( $colors['bg'] ) ? rrt_a11y_hex( $colors['bg'], '' ) : '';
	if ( '' === $text || '' === $bg ) {
		return 42;
	}
	for ( $p = 42; $p < 100; $p++ ) {
		if ( rrt_contrast( rrt_mix( $bg, $text, $p / 100 ), $bg ) >= RRT_A11Y_AHEAD ) {
			return $p;
		}
	}
	return 100;
}

/** The colour words ahead are seen in, on the page background. */
function rrt_a11y_ahead( $colors ) {
	return rrt_mix( rrt_a11y_hex( $colors['bg'] ), rrt_a11y_hex( $colors['text'] ), rrt_a11y_ahead_pct( $colors ) / 100 );
}

/** The colours with the studio's words ahead added as their own key, for the check. */
function rrt_a11y_with_ahead( $colors ) {
	if ( isset( $colors['text'], $colors['bg'] ) ) {
		$colors['ahead'] = rrt_a11y_ahead( $colors );
	}
	return $colors;
}

/**
 * The pairs to check: array( foreground key, background key, ratio needed, what it is ).
 * Backgrounds are bg, surface and panel; the gold buttons' text is the page
 * background colour on the accent.
 *
 * @return array
 */
function rrt_a11y_pairs() {
	return array(
		array( 'text', 'panel', RRT_A11Y_TEXT, __( 'Main text on the player and the control bar, and titles on YouTube and SoundCloud panels', 'foxfire-studio' ) ),
		array( 'muted', 'panel', RRT_A11Y_TEXT, __( 'The player\'s label and credit, labels in pop-up panels, and where YouTube and SoundCloud panels play from', 'foxfire-studio' ) ),
		array( 'faint', 'panel', RRT_A11Y_TEXT, __( 'The player\'s times, and hints in pop-up panels', 'foxfire-studio' ) ),
		array( 'accent', 'panel', RRT_A11Y_TEXT, __( 'The player\'s buttons, and buttons in pop-up panels', 'foxfire-studio' ) ),
		array( 'text', 'bg', RRT_A11Y_TEXT, __( 'Reading text on the page background', 'foxfire-studio' ) ),
		array( 'muted', 'bg', RRT_A11Y_TEXT, __( 'Labels on the page background', 'foxfire-studio' ) ),
		array( 'faint', 'bg', RRT_A11Y_TEXT, __( 'The status line on the page background', 'foxfire-studio' ) ),
		array( 'accent', 'bg', RRT_A11Y_TEXT, __( 'The highlighted word and buttons on the page background, and the play button on YouTube and SoundCloud panels', 'foxfire-studio' ) ),
		array( 'muted', 'surface', RRT_A11Y_TEXT, __( 'Labels on the review\'s cards', 'foxfire-studio' ) ),
		array( 'bg', 'accent', RRT_A11Y_TEXT, __( 'Text on accent-coloured buttons', 'foxfire-studio' ) ),
		array( 'ahead', 'bg', RRT_A11Y_AHEAD, __( 'Words ahead of the reading line in the studio (large text, so 3 to 1; held there by itself)', 'foxfire-studio' ) ),
	);
}

/** A colour as #rrggbb, or the fallback. */
function rrt_a11y_hex( $c, $fallback = '#000000' ) {
	$h = function_exists( 'rrt_hex' ) ? rrt_hex( $c ) : '';
	return '' === $h ? $fallback : $h;
}

/**
 * Each pair's ratio for a set of colours.
 *
 * @param array $colors Key => #rrggbb.
 * @return array List of array( 'fg', 'bg', 'need', 'label', 'ratio', 'ok' ).
 */
function rrt_a11y_check( $colors ) {
	$colors = rrt_a11y_with_ahead( $colors );
	$out    = array();
	foreach ( rrt_a11y_pairs() as $p ) {
		if ( ! isset( $colors[ $p[0] ], $colors[ $p[1] ] ) ) {
			continue;
		}
		$r     = rrt_contrast( rrt_a11y_hex( $colors[ $p[0] ] ), rrt_a11y_hex( $colors[ $p[1] ] ) );
		$out[] = array(
			'fg'    => $p[0],
			'bg'    => $p[1],
			'need'  => $p[2],
			'label' => $p[3],
			'ratio' => floor( $r * 100 ) / 100,
			'ok'    => $r >= $p[2],
		);
	}
	return $out;
}

/** Whether every pair passes. */
function rrt_a11y_all_ok( $colors ) {
	foreach ( rrt_a11y_check( $colors ) as $row ) {
		if ( ! $row['ok'] ) {
			return false;
		}
	}
	return true;
}

/**
 * Make it readable: each foreground colour that falls short in any pair is
 * mixed towards white or black, whichever needs the smaller step, until all
 * its pairs pass. If neither gets there, it goes all the way in the
 * direction that does best. Backgrounds stay as they are.
 *
 * @param array $colors Key => #rrggbb.
 * @return array The colours, with only the failing ones changed.
 */
function rrt_a11y_fix( $colors ) {
	$fixed = array( 'text', 'muted', 'faint', 'accent' );
	foreach ( $fixed as $k ) {
		if ( ! isset( $colors[ $k ] ) ) {
			continue;
		}
		$against = array();
		foreach ( rrt_a11y_pairs() as $p ) {
			if ( $p[0] === $k && isset( $colors[ $p[1] ] ) ) {
				$against[] = array( $p[1], $p[2] );
			} elseif ( $p[1] === $k && isset( $colors[ $p[0] ] ) && ! in_array( $p[0], $fixed, true ) ) {
				$against[] = array( $p[0], $p[2] );
			}
		}
		$orig = rrt_a11y_hex( $colors[ $k ] );
		$worst = function ( $c ) use ( $against, $colors ) {
			$m = 99;
			foreach ( $against as $a ) {
				$m = min( $m, rrt_contrast( $c, rrt_a11y_hex( $colors[ $a[0] ] ) ) / $a[1] );
			}
			return $m;
		};
		if ( $worst( $orig ) >= 1 ) {
			continue;
		}
		$best = null;
		foreach ( array( '#ffffff', '#000000' ) as $target ) {
			for ( $i = 1; $i <= 100; $i++ ) {
				$c = rrt_mix( $orig, $target, $i / 100 );
				if ( $worst( $c ) >= 1 ) {
					if ( null === $best || $i < $best[1] ) {
						$best = array( $c, $i );
					}
					break;
				}
			}
		}
		if ( null === $best ) {
			$best = array( $worst( '#ffffff' ) >= $worst( '#000000' ) ? '#ffffff' : '#000000', 100 );
		}
		$colors[ $k ] = $best[0];
	}
	return $colors;
}

/** The readability table under Colours in Settings. */
function rrt_a11y_settings_rows( $colors, $opt ) {
	$rows   = rrt_a11y_check( $colors );
	$colors = rrt_a11y_with_ahead( $colors );
	$fails = 0;
	foreach ( $rows as $row ) {
		$fails += $row['ok'] ? 0 : 1;
	}
	$labels          = rrt_color_labels();
	$labels['ahead'] = __( 'Words ahead', 'foxfire-studio' );
	echo '<tr id="rrt-readable"><th scope="row">' . esc_html__( 'Readability', 'foxfire-studio' ) . '</th><td>';
	echo '<p class="rrt-readable-sum" data-rrt-readable="' . ( $fails ? 'fail' : 'pass' ) . '">';
	if ( $fails ) {
		echo esc_html( sprintf( /* translators: %d: number of colour pairs. */ _n( '%d pair of colours is too faint to read easily.', '%d pairs of colours are too faint to read easily.', $fails, 'foxfire-studio' ), $fails ) );
	} else {
		esc_html_e( 'Every pair of colours is easy to read.', 'foxfire-studio' );
	}
	echo '</p>';
	echo '<table class="widefat striped rrt-readable-table"><thead><tr><th scope="col">' . esc_html__( 'Where', 'foxfire-studio' ) . '</th><th scope="col">' . esc_html__( 'Colours', 'foxfire-studio' ) . '</th><th scope="col" class="num">' . esc_html__( 'Contrast', 'foxfire-studio' ) . '</th><th scope="col">' . esc_html__( 'Result', 'foxfire-studio' ) . '</th></tr></thead><tbody>';
	foreach ( $rows as $row ) {
		$fg = rrt_a11y_hex( $colors[ $row['fg'] ] );
		$bg = rrt_a11y_hex( $colors[ $row['bg'] ] );
		echo '<tr data-pair="' . esc_attr( $row['fg'] . '-' . $row['bg'] ) . '" data-ok="' . ( $row['ok'] ? '1' : '0' ) . '">';
		echo '<td>' . esc_html( $row['label'] ) . '</td>';
		echo '<td><span class="rrt-readable-sample" style="color:' . esc_attr( $fg ) . ';background:' . esc_attr( $bg ) . '">Aa</span> ' . esc_html( sprintf( /* translators: 1: a colour's name, 2: another colour's name. */ __( '%1$s on %2$s', 'foxfire-studio' ), isset( $labels[ $row['fg'] ] ) ? $labels[ $row['fg'] ] : $row['fg'], isset( $labels[ $row['bg'] ] ) ? $labels[ $row['bg'] ] : $row['bg'] ) ) . '</td>';
		echo '<td class="num">' . esc_html( number_format_i18n( $row['ratio'], 2 ) . ' : 1' ) . '</td>';
		echo '<td>' . ( $row['ok'] ? esc_html__( 'Easy to read', 'foxfire-studio' ) : '<strong>' . esc_html( sprintf( /* translators: %s: the ratio needed, such as 4.5. */ __( 'Too faint (needs %s : 1)', 'foxfire-studio' ), number_format_i18n( $row['need'], 1 ) ) ) . '</strong>' ) . '</td>';
		echo '</tr>';
	}
	echo '</tbody></table>';
	if ( $fails ) {
		echo '<p><button type="submit" class="button" name="' . esc_attr( $opt ) . '[a11y_fix]" value="1">' . esc_html__( 'Make it readable', 'foxfire-studio' ) . '</button></p>';
	}
	echo '<p class="description">' . esc_html__( 'Checked against WCAG 2.2 AA, which asks for 4.5 to 1 for text, and 3 to 1 for large text such as the studio\'s script. Make it readable saves the form, then lightens or darkens only the colours that fall short, by the smallest step that is enough; backgrounds stay as they are. The check uses the colours as saved: save after changing a colour to check it again.', 'foxfire-studio' ) . '</p>';
	echo '</td></tr>';
}

/** Whether a post has a reading but no captions (so no transcript for the player or the feed). */
function rrt_a11y_needs_captions( $post_id ) {
	if ( ! function_exists( 'rrt_reading_audio' ) || '' === rrt_reading_audio( $post_id )['url'] ) {
		return false;
	}
	return '' === rrt_reading_captions( $post_id )['url'];
}
