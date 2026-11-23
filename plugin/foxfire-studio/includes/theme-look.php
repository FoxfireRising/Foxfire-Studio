<?php
/**
 * The teleprompter's two looks (0.18.0): the Foxfire Studio look (the night
 * sky, the fox and gold ornaments, in the colours set in Settings) or the
 * site's WordPress theme (no artwork or ornaments, in the theme's colours).
 *
 * The theme's colours are read from its palette: the one the block editor
 * shows. Each part of the page (background, text, accent, second accent)
 * is linked to one palette colour by its slug, so changing that colour in
 * the theme changes the teleprompter too. Themes that give their palette
 * as CSS variables (Astra's global colours) are resolved from the theme's
 * own saved setting.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The parts of the page whose colour can come from the theme. */
function rrt_theme_roles() {
	return array(
		'bg'      => __( 'Background', 'foxfire-studio' ),
		'text'    => __( 'Text', 'foxfire-studio' ),
		'accent'  => __( 'Accent (lit words, buttons, reading line)', 'foxfire-studio' ),
		'accent2' => __( 'Second accent (start of the progress line)', 'foxfire-studio' ),
	);
}

/** A colour as #rrggbb, or '' when it cannot be read. Takes #rgb, #rrggbb and rgb(). */
function rrt_hex( $c ) {
	$c = strtolower( trim( (string) $c ) );
	if ( preg_match( '/^#([0-9a-f]{3})$/', $c, $m ) ) {
		return '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
	}
	if ( preg_match( '/^#[0-9a-f]{6}$/', $c ) ) {
		return $c;
	}
	if ( preg_match( '/^#([0-9a-f]{6})[0-9a-f]{2}$/', $c, $m ) ) {
		return '#' . $m[1];
	}
	if ( preg_match( '/^rgba?\(\s*(\d{1,3})[\s,]+(\d{1,3})[\s,]+(\d{1,3})/', $c, $m ) ) {
		return sprintf( '#%02x%02x%02x', min( 255, (int) $m[1] ), min( 255, (int) $m[2] ), min( 255, (int) $m[3] ) );
	}
	return '';
}

/**
 * Astra's nine global colours, from its saved setting, when the theme is
 * Astra or a child of it. Newer versions keep several palettes and a current
 * one; older ones keep a single palette.
 *
 * @return array Index => #rrggbb.
 */
function rrt_astra_colors() {
	$list = array();
	$sets = get_option( 'astra-color-palettes' );
	if ( is_array( $sets ) && ! empty( $sets['currentPalette'] ) && isset( $sets['palettes'][ $sets['currentPalette'] ] ) && is_array( $sets['palettes'][ $sets['currentPalette'] ] ) ) {
		$list = $sets['palettes'][ $sets['currentPalette'] ];
	}
	if ( ! $list ) {
		$opt = get_option( 'astra-settings' );
		if ( is_array( $opt ) && isset( $opt['global-color-palette']['palette'] ) && is_array( $opt['global-color-palette']['palette'] ) ) {
			$list = $opt['global-color-palette']['palette'];
		}
	}
	$out = array();
	foreach ( array_values( $list ) as $i => $c ) {
		$h = rrt_hex( $c );
		if ( $h ) {
			$out[ $i ] = $h;
		}
	}
	return $out;
}

/**
 * The theme's colours as the block editor lists them: the theme's own, then
 * any added in the editor, then WordPress's defaults.
 *
 * @return array List of array( slug, name, color (#rrggbb), group ).
 */
function rrt_theme_palette() {
	$pal = function_exists( 'wp_get_global_settings' ) ? wp_get_global_settings( array( 'color', 'palette' ) ) : array();
	if ( ! is_array( $pal ) ) {
		$pal = array();
	}
	$astra  = null;
	$out    = array();
	$seen   = array();
	$groups = array(
		'theme'   => __( 'Your theme', 'foxfire-studio' ),
		'custom'  => __( 'Added in the editor', 'foxfire-studio' ),
		'default' => __( 'WordPress defaults', 'foxfire-studio' ),
	);
	foreach ( $groups as $key => $group ) {
		if ( empty( $pal[ $key ] ) || ! is_array( $pal[ $key ] ) ) {
			continue;
		}
		foreach ( $pal[ $key ] as $c ) {
			if ( empty( $c['slug'] ) || ! isset( $c['color'] ) ) {
				continue;
			}
			$slug = sanitize_key( $c['slug'] );
			if ( isset( $seen[ $slug ] ) ) {
				continue;
			}
			$hex = rrt_hex( $c['color'] );
			if ( ! $hex && preg_match( '/var\(\s*--ast-global-color-(\d)\s*\)/', $c['color'], $m ) ) {
				if ( null === $astra ) {
					$astra = rrt_astra_colors();
				}
				$hex = isset( $astra[ (int) $m[1] ] ) ? $astra[ (int) $m[1] ] : '';
			}
			if ( ! $hex ) {
				continue;
			}
			$seen[ $slug ] = true;
			$out[]         = array(
				'slug'  => $slug,
				'name'  => isset( $c['name'] ) && '' !== $c['name'] ? wp_strip_all_tags( $c['name'] ) : $slug,
				'color' => $hex,
				'group' => $group,
			);
		}
	}
	return $out;
}

/** Relative luminance of a #rrggbb colour, 0 (black) to 1 (white). */
function rrt_luma( $hex ) {
	$v = array();
	foreach ( array( 1, 3, 5 ) as $i ) {
		$c   = hexdec( substr( $hex, $i, 2 ) ) / 255;
		$v[] = $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
	}
	return 0.2126 * $v[0] + 0.7152 * $v[1] + 0.0722 * $v[2];
}

/** Contrast ratio between two #rrggbb colours, 1 to 21. */
function rrt_contrast( $a, $b ) {
	$x = rrt_luma( $a );
	$y = rrt_luma( $b );
	return ( max( $x, $y ) + 0.05 ) / ( min( $x, $y ) + 0.05 );
}

/** Mixes two #rrggbb colours: $t of $b into $a. */
function rrt_mix( $a, $b, $t ) {
	$out = '#';
	foreach ( array( 1, 3, 5 ) as $i ) {
		$x    = hexdec( substr( $a, $i, 2 ) );
		$y    = hexdec( substr( $b, $i, 2 ) );
		$out .= sprintf( '%02x', (int) round( $x + ( $y - $x ) * $t ) );
	}
	return $out;
}

/**
 * The slug the theme itself uses for its page background and text (block
 * themes say so in their styles), when it is in the palette.
 */
function rrt_theme_style_slug( $part, $palette ) {
	$styles = function_exists( 'wp_get_global_styles' ) ? wp_get_global_styles( array( 'color', $part ) ) : '';
	if ( is_string( $styles ) && preg_match( '/--wp--preset--color--([a-z0-9-]+)|\|color\|([a-z0-9-]+)/', $styles, $m ) ) {
		$slug = '' !== $m[1] ? $m[1] : $m[2];
		foreach ( $palette as $p ) {
			if ( $p['slug'] === $slug ) {
				return $slug;
			}
		}
	}
	return '';
}

/**
 * A best guess at which palette colour suits each part, used for "Automatic".
 *
 * @param array $palette From rrt_theme_palette().
 * @return array Role => slug ('' when nothing fits).
 */
function rrt_theme_auto( $palette ) {
	$find = function ( $re, $skip = array() ) use ( $palette ) {
		foreach ( $palette as $p ) {
			if ( __( 'WordPress defaults', 'foxfire-studio' ) === $p['group'] || in_array( $p['slug'], $skip, true ) ) {
				continue;
			}
			if ( preg_match( $re, $p['name'] . ' ' . $p['slug'] ) ) {
				return $p['slug'];
			}
		}
		return '';
	};
	$bg   = rrt_theme_style_slug( 'background', $palette );
	$bg   = $bg ? $bg : $find( '/primary background|^background|\bbase\b|^bg/i' );
	$bg   = $bg ? $bg : $find( '/background/i' );
	$text = rrt_theme_style_slug( 'text', $palette );
	$text = $text ? $text : $find( '/^text\b|\bcontrast\b|foreground|body text/i', array( $bg ) );
	/*
	 * The accent must stand out on the background: theme colours named like
	 * an accent come first, and the first that is clearly visible wins.
	 */
	$bgc   = '#ffffff';
	$cands = array();
	foreach ( $palette as $p ) {
		if ( $p['slug'] === $bg ) {
			$bgc = $p['color'];
		}
	}
	foreach ( $palette as $p ) {
		if ( __( 'WordPress defaults', 'foxfire-studio' ) === $p['group'] || in_array( $p['slug'], array( $bg, $text ), true ) || preg_match( '/background/i', $p['name'] ) ) {
			continue;
		}
		$named   = preg_match( '/brand|primary|accent|link/i', $p['name'] . ' ' . $p['slug'] ) ? 1 : 0;
		$cands[] = array( $p['slug'], $named, rrt_contrast( $p['color'], $bgc ), $p['name'] );
	}
	usort(
		$cands,
		function ( $x, $y ) {
			return $y[1] - $x[1];
		}
	);
	$acc = '';
	foreach ( $cands as $c ) {
		if ( $c[2] >= 3 ) {
			$acc = $c[0];
			break;
		}
	}
	if ( ! $acc && $cands ) {
		$best = $cands[0];
		foreach ( $cands as $c ) {
			$best = $c[2] > $best[2] ? $c : $best;
		}
		$acc = $best[0];
	}
	$acc2 = '';
	foreach ( $cands as $c ) {
		if ( $c[0] !== $acc && $c[2] >= 1.5 && preg_match( '/alternate|secondary|accent|brand/i', $c[3] . ' ' . $c[0] ) ) {
			$acc2 = $c[0];
			break;
		}
	}
	return array(
		'bg'      => $bg,
		'text'    => $text,
		'accent'  => $acc,
		'accent2' => $acc2 ? $acc2 : $acc,
	);
}

/**
 * The full set of colour tokens for the theme look: the four chosen colours,
 * and the panels, softer text and hints mixed from them. Text is swapped for
 * black or white when the theme's pair would be hard to read.
 *
 * @param array $cfg Settings.
 * @return array Colours, plus 'light' (true for a light background).
 */
function rrt_theme_tokens( $cfg ) {
	$palette = rrt_theme_palette();
	$auto    = rrt_theme_auto( $palette );
	$chosen  = isset( $cfg['theme_colors'] ) && is_array( $cfg['theme_colors'] ) ? $cfg['theme_colors'] : array();
	$by      = array();
	foreach ( $palette as $p ) {
		$by[ $p['slug'] ] = $p['color'];
	}
	$pick = function ( $role, $fallback ) use ( $chosen, $auto, $by ) {
		$slug = isset( $chosen[ $role ] ) && 'auto' !== $chosen[ $role ] ? $chosen[ $role ] : $auto[ $role ];
		return ( $slug && isset( $by[ $slug ] ) ) ? $by[ $slug ] : $fallback;
	};
	$bg     = $pick( 'bg', '#ffffff' );
	$text   = $pick( 'text', rrt_luma( $bg ) > 0.4 ? '#1e1e1e' : '#f2f2f2' );
	if ( rrt_contrast( $text, $bg ) < 4.5 ) {
		$text = rrt_contrast( '#111111', $bg ) > rrt_contrast( '#f5f5f5', $bg ) ? '#111111' : '#f5f5f5';
	}
	$accent  = $pick( 'accent', rrt_luma( $bg ) > 0.4 ? '#2271b1' : '#e0b04f' );
	$accent2 = $pick( 'accent2', $accent );
	$light   = rrt_luma( $bg ) > 0.4;
	return array(
		'bg'      => $bg,
		'surface' => rrt_mix( $bg, $text, $light ? 0.04 : 0.06 ),
		'panel'   => rrt_mix( $bg, $text, $light ? 0.07 : 0.1 ),
		'text'    => $text,
		'muted'   => rrt_mix( $text, $bg, 0.25 ),
		'faint'   => rrt_mix( $text, $bg, 0.48 ),
		'accent'  => $accent,
		'accent2' => $accent2,
		'warn'    => $light ? '#b4541c' : '#d0743c',
		'light'   => $light,
	);
}

/** Which look the teleprompter uses: 'studio' or 'theme'. */
function rrt_look( $cfg ) {
	return isset( $cfg['look'] ) && 'theme' === $cfg['look'] ? 'theme' : 'studio';
}
