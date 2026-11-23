<?php
/**
 * Writes the Help screen's guides into docs/guide/ as Markdown (1.32.0), so
 * the repository, and one day a public docs site, carry the same words as the
 * plugin. The words live in plugin/foxfire-studio/includes/help-text.php;
 * change them there and run this again.
 *
 *   php tools/help-export.php          write docs/guide/
 *   php tools/help-export.php --check  exit 1 if docs/guide/ is out of date
 *
 * Runs without WordPress: the translation functions are stood in for and
 * return the English text.
 *
 * @package FoxfireStudio
 */

define( 'ABSPATH', __DIR__ . '/' );
if ( ! function_exists( '__' ) ) {
	/**
	 * Stand-in for WordPress's __(): the English text.
	 *
	 * @param string $text   The text.
	 * @param string $domain Unused.
	 * @return string
	 */
	function __( $text, $domain = '' ) { // phpcs:ignore
		return $text;
	}
}

$root = dirname( __DIR__ );
require $root . '/plugin/foxfire-studio/includes/help-text.php';

$out_dir = $root . '/docs/guide';
$check   = in_array( '--check', $argv, true );
$head    = '<!-- Written by tools/help-export.php from plugin/foxfire-studio/includes/help-text.php. Change the words there, then run the tool again. -->';

/**
 * One piece of a guide as Markdown, or '' for pieces that only make sense in
 * the plugin (links to screens).
 *
 * @param array $piece See help-text.php.
 * @return string
 */
function rrt_help_md_piece( $piece ) {
	switch ( $piece[0] ) {
		case 'h':
			return '## ' . $piece[2];
		case 'links':
			$all   = rrt_help_topics();
			$lines = array();
			foreach ( $piece[1] as $key ) {
				$lines[] = '- [' . $all[ $key ]['title'] . '](' . $key . '.md): ' . $all[ $key ]['intro'];
			}
			return implode( "\n", $lines );
		case 'p':
			return $piece[1];
		case 'note':
			return '> ' . $piece[1];
		case 'steps':
			$lines = array();
			foreach ( array_values( $piece[1] ) as $i => $line ) {
				$lines[] = ( $i + 1 ) . '. ' . $line;
			}
			return implode( "\n", $lines );
		case 'list':
			return '- ' . implode( "\n- ", $piece[1] );
	}
	return '';
}

$files  = array();
$topics = rrt_help_topics();

$index   = array( $head, '', '# Foxfire Studio guide', '' );
$index[] = 'Guides to each part of the plugin, in groups. The same guides are inside the plugin, under Foxfire Studio, Help, and each screen of the plugin links to the one that fits it. New here? Begin with [Start here](start.md).';
$index[] = '';
$index[] = 'Every change is in [CHANGELOG.md](https://github.com/FoxfireRising/Foxfire-Studio/blob/main/CHANGELOG.md).';
foreach ( rrt_help_groups() as $group => $label ) {
	$in = array();
	foreach ( $topics as $key => $t ) {
		if ( $group === $t['group'] ) {
			$in[] = '- [' . $t['title'] . '](' . $key . '.md): ' . $t['intro'];
		}
	}
	if ( $in ) {
		$index[] = '';
		$index[] = '## ' . $label;
		$index[] = '';
		$index = array_merge( $index, $in );
	}
}
foreach ( $topics as $key => $t ) {

	$md = array( $head, '', '# ' . $t['title'], '', '*' . $t['intro'] . '*' );
	foreach ( $t['body'] as $piece ) {
		$text = rrt_help_md_piece( $piece );
		if ( '' !== $text ) {
			$md[] = '';
			$md[] = $text;
		}
	}
	$md[] = '';
	$md[] = '[All guides](README.md)';
	$files[ $key . '.md' ] = implode( "\n", $md ) . "\n";
}
$index[] = '';
$index[] = '## What\'s new';
foreach ( rrt_help_news() as $ver => $lines ) {
	$index[] = '';
	$index[] = '### ' . $ver;
	$index[] = '';
	$index[] = '- ' . implode( "\n- ", $lines );
}
$files['README.md'] = implode( "\n", $index ) . "\n";

$stale = array();
foreach ( $files as $name => $text ) {
	$path = $out_dir . '/' . $name;
	$was  = is_file( $path ) ? file_get_contents( $path ) : null; // phpcs:ignore
	if ( $was !== $text ) {
		$stale[] = $name;
		if ( ! $check ) {
			if ( ! is_dir( $out_dir ) ) {
				mkdir( $out_dir, 0755, true );
			}
			file_put_contents( $path, $text ); // phpcs:ignore
		}
	}
}
foreach ( glob( $out_dir . '/*.md' ) ?: array() as $path ) {
	if ( ! isset( $files[ basename( $path ) ] ) ) {
		$stale[] = basename( $path ) . ' (no longer a guide)';
		if ( ! $check ) {
			unlink( $path ); // phpcs:ignore
		}
	}
}

if ( $check ) {
	if ( $stale ) {
		fwrite( STDERR, "docs/guide is out of date: " . implode( ', ', $stale ) . ". Run php tools/help-export.php\n" ); // phpcs:ignore
		exit( 1 );
	}
	echo 'docs/guide is up to date (' . count( $files ) . " files).\n";
	exit( 0 );
}
echo $stale ? 'Wrote ' . implode( ', ', $stale ) . "\n" : "Nothing to change.\n";
