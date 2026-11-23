<?php
/**
 * Help (1.32.0; groups, parts and section links since 1.36.0): the Foxfire Studio, Help screen, the Help tab and Help link
 * on every Foxfire Studio screen, and the address the studio's Controls panel
 * links to. The words are in help-text.php; tools/help-export.php writes the
 * same guides into docs/guide/.
 *
 * Nothing here loads from outside the site: the search box filters the
 * guides already on the page.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once RRT_DIR . 'includes/help-text.php';

/** The Help screen's page slug. */
define( 'RRT_HELP_PAGE', 'rrt-help' );

/**
 * The keys and titles of a guide's parts (1.36.0), in order.
 *
 * @param string $topic A key of rrt_help_topics().
 * @return array<string, string>
 */
function rrt_help_parts( $topic ) {
	$all   = rrt_help_topics();
	$parts = array();
	if ( isset( $all[ $topic ] ) ) {
		foreach ( $all[ $topic ]['body'] as $piece ) {
			if ( 'h' === $piece[0] ) {
				$parts[ $piece[1] ] = $piece[2];
			}
		}
	}
	return $parts;
}

/**
 * The Help screen's address, opened at one guide, or one part of a guide
 * (1.36.0: guide:part), when one is given.
 *
 * @param string $topic A key of rrt_help_topics(), guide:part, or '' for the top.
 * @return string
 */
function rrt_help_url( $topic = '' ) {
	$url  = admin_url( 'admin.php?page=' . RRT_HELP_PAGE );
	$all  = rrt_help_topics();
	$bits = explode( ':', (string) $topic, 2 );
	if ( '' !== $bits[0] ? isset( $all[ $bits[0] ] ) : false ) {
		$parts = isset( $bits[1] ) ? rrt_help_parts( $bits[0] ) : array();
		$url  .= '#rrt-help-' . $bits[0] . ( isset( $bits[1] ) && isset( $parts[ $bits[1] ] ) ? '-' . $bits[1] : '' );
	}
	return $url;
}

/**
 * The Help screen's address at one group of guides (1.36.0).
 *
 * @param string $group A key of rrt_help_groups().
 * @return string
 */
function rrt_help_group_url( $group ) {
	$url    = admin_url( 'admin.php?page=' . RRT_HELP_PAGE );
	$groups = rrt_help_groups();
	return isset( $groups[ $group ] ) ? $url . '#rrt-help-group-' . $group : $url;
}

add_action(
	'admin_menu',
	function () {
		add_submenu_page( 'rrt-dashboard', __( 'Foxfire Studio help', 'foxfire-studio' ), __( 'Help', 'foxfire-studio' ), 'edit_posts', RRT_HELP_PAGE, 'rrt_help_page' );
	},
	99
);

/**
 * The guide a screen opens, or '' when the screen is not one of the plugin's.
 *
 * @param WP_Screen|null $screen The current screen.
 * @return string
 */
function rrt_help_screen_topic( $screen ) {
	if ( ! $screen ) {
		return '';
	}
	$map = rrt_help_screens();
	if ( ! empty( $screen->post_type ) ? isset( $map[ $screen->post_type ] ) : false ) {
		if ( in_array( $screen->base, array( 'edit', 'post' ), true ) ) {
			return $map[ $screen->post_type ];
		}
	}
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( '' === $page || RRT_HELP_PAGE === $page ) {
		return '';
	}
	if ( 'rrt-readings' === $page ? ! empty( $_GET['post'] ) : false ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return 'clips';
	}
	return isset( $map[ $page ] ) ? $map[ $page ] : '';
}

/* The Help tab (top right) on the plugin's screens. */
add_action(
	'current_screen',
	function ( $screen ) {
		$topic = rrt_help_screen_topic( $screen );
		if ( '' === $topic ? true : ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$all   = rrt_help_topics();
		$bits  = explode( ':', $topic, 2 );
		$t     = $all[ $bits[0] ];
		$parts = isset( $bits[1] ) ? rrt_help_parts( $bits[0] ) : array();
		$part  = isset( $bits[1] ) && isset( $parts[ $bits[1] ] ) ? '<p>' . esc_html( sprintf( /* translators: %s: the title of a part of a guide. */ __( 'This screen: %s', 'foxfire-studio' ), $parts[ $bits[1] ] ) ) . '</p>' : '';
		$screen->add_help_tab(
			array(
				'id'      => 'rrt-help',
				'title'   => __( 'Foxfire Studio', 'foxfire-studio' ),
				'content' => '<p><strong>' . esc_html( $t['title'] ) . '</strong></p><p>' . esc_html( $t['intro'] ) . '</p>' . $part . '<p><a href="' . esc_url( rrt_help_url( $topic ) ) . '">' . esc_html__( 'Read the guide', 'foxfire-studio' ) . '</a></p>',
			)
		);
		$screen->set_help_sidebar( '<p><strong>' . esc_html__( 'More help', 'foxfire-studio' ) . '</strong></p><p><a href="' . esc_url( rrt_help_url() ) . '">' . esc_html__( 'Every guide', 'foxfire-studio' ) . '</a></p>' );
	}
);

/* The Help link beside each screen's heading, and the Help screen's search. */
add_action(
	'admin_enqueue_scripts',
	function () {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$on     = RRT_HELP_PAGE === $page;
		$topic  = rrt_help_screen_topic( $screen );
		if ( ( $on || '' !== $topic ) ? ! current_user_can( 'edit_posts' ) : true ) {
			return;
		}
		wp_enqueue_style( 'rrt-help', RRT_URL . 'assets/css/help.css', array(), RRT_VERSION . '.' . (int) @filemtime( RRT_DIR . 'assets/css/help.css' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_enqueue_script( 'rrt-help', RRT_URL . 'assets/js/help.js', array( 'wp-i18n' ), RRT_VERSION . '.' . (int) @filemtime( RRT_DIR . 'assets/js/help.js' ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_set_script_translations( 'rrt-help', 'foxfire-studio', rrt_languages_dir() );
		if ( '' !== $topic ) {
			wp_add_inline_script( 'rrt-help', 'window.rrtHelpLink = ' . wp_json_encode( array( 'url' => rrt_help_url( $topic ) ) ) . ';', 'before' );
		}
		/* 1.36.0: a Help link beside each section that has a guide or part of its own. */
		$sections = rrt_help_sections();
		$slug     = '' !== $page ? $page : '';
		if ( isset( $sections[ $slug ] ) ) {
			$list = array();
			foreach ( $sections[ $slug ] as $heading => $target ) {
				$list[] = array(
					'h'   => $heading,
					'url' => rrt_help_url( $target ),
				);
			}
			wp_add_inline_script( 'rrt-help', 'window.rrtHelpParts = ' . wp_json_encode( $list ) . ';', 'before' );
		}
	}
);

/**
 * One piece of a guide as HTML.
 *
 * @param array  $piece See help-text.php.
 * @param string $topic The guide's key, for the ids of its parts.
 * @return string
 */
function rrt_help_piece_html( $piece, $topic = '' ) {
	$kind = $piece[0];
	if ( 'h' === $kind ) {
		return '<h3 class="rrt-help-part" id="rrt-help-' . esc_attr( $topic . '-' . $piece[1] ) . '" tabindex="-1">' . esc_html( $piece[2] ) . '</h3>';
	}
	if ( 'links' === $kind ) {
		$all = rrt_help_topics();
		$out = '<ul class="rrt-help-links">';
		foreach ( $piece[1] as $key ) {
			if ( isset( $all[ $key ] ) ) {
				$out .= '<li><a href="#rrt-help-' . esc_attr( $key ) . '">' . esc_html( $all[ $key ]['title'] ) . '</a> <span class="rrt-help-intro">' . esc_html( $all[ $key ]['intro'] ) . '</span></li>';
			}
		}
		return $out . '</ul>';
	}
	if ( 'p' === $kind ) {
		return '<p>' . esc_html( $piece[1] ) . '</p>';
	}
	if ( 'note' === $kind ) {
		return '<p class="rrt-help-note">' . esc_html( $piece[1] ) . '</p>';
	}
	if ( 'go' === $kind ) {
		return '<p class="rrt-help-go"><a class="button" href="' . esc_url( admin_url( $piece[2] ) ) . '">' . esc_html( $piece[1] ) . '</a></p>';
	}
	if ( 'steps' === $kind || 'list' === $kind ) {
		$tag = 'steps' === $kind ? 'ol' : 'ul';
		$out = '<' . $tag . '>';
		foreach ( $piece[1] as $line ) {
			$out .= '<li>' . esc_html( $line ) . '</li>';
		}
		return $out . '</' . $tag . '>';
	}
	return '';
}

/** The Help screen. */
function rrt_help_page() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die( esc_html__( 'Not allowed.', 'foxfire-studio' ) );
	}
	$topics = rrt_help_topics();
	echo '<div class="wrap rrt-help">';
	echo '<h1>' . esc_html__( 'Foxfire Studio help', 'foxfire-studio' ) . '</h1>';
	echo '<p class="rrt-help-lead">' . esc_html__( 'Guides to each part of the plugin, in groups. Each one says which screen and which button to use. New here? Begin with Start here.', 'foxfire-studio' ) . '</p>';

	echo '<section class="rrt-help-news" aria-labelledby="rrt-help-news-h"><h2 id="rrt-help-news-h">' . esc_html__( 'What\'s new', 'foxfire-studio' ) . '</h2>';
	foreach ( rrt_help_news() as $ver => $lines ) {
		echo '<h3>' . esc_html( $ver ) . ( RRT_VERSION === $ver ? ' <span class="rrt-help-this">' . esc_html__( '(this version)', 'foxfire-studio' ) . '</span>' : '' ) . '</h3><ul>';
		foreach ( $lines as $line ) {
			echo '<li>' . esc_html( $line ) . '</li>';
		}
		echo '</ul>';
	}
	echo '<p>' . esc_html__( 'Every change is listed in the plugin\'s readme.txt, under Changelog.', 'foxfire-studio' ) . '</p></section>';
	echo '<div class="rrt-help-search" hidden><label for="rrt-help-q">' . esc_html__( 'Search the guides', 'foxfire-studio' ) . '</label> ';
	echo '<input type="search" id="rrt-help-q" class="regular-text" autocomplete="off" aria-describedby="rrt-help-count">';
	echo ' <span id="rrt-help-count" class="rrt-help-count" aria-live="polite"></span></div>';

	/* 1.36.0: the guides in groups, Start here first. */
	echo '<nav class="rrt-help-toc" aria-labelledby="rrt-help-toc-h"><h2 id="rrt-help-toc-h">' . esc_html__( 'Guides', 'foxfire-studio' ) . '</h2>';
	foreach ( rrt_help_groups() as $group => $label ) {
		$in = array_filter(
			$topics,
			function ( $t ) use ( $group ) {
				return isset( $t['group'] ) && $group === $t['group'];
			}
		);
		if ( ! $in ) {
			continue;
		}
		echo '<div class="rrt-help-group" data-rrt-help-group="' . esc_attr( $group ) . '"><h3 id="rrt-help-group-' . esc_attr( $group ) . '" tabindex="-1">' . esc_html( $label ) . '</h3><ul>';
		foreach ( $in as $key => $t ) {
			echo '<li data-rrt-help-for="' . esc_attr( $key ) . '"><a href="#rrt-help-' . esc_attr( $key ) . '">' . esc_html( $t['title'] ) . '</a> <span class="rrt-help-intro">' . esc_html( $t['intro'] ) . '</span></li>';
		}
		echo '</ul></div>';
	}
	echo '</nav>';

	echo '<p class="rrt-help-none" hidden>' . esc_html__( 'No guide mentions that. Try a shorter word, or read the list of guides above.', 'foxfire-studio' ) . '</p>';

	foreach ( $topics as $key => $t ) {
		echo '<section class="rrt-help-topic" id="rrt-help-' . esc_attr( $key ) . '" aria-labelledby="rrt-help-h-' . esc_attr( $key ) . '" tabindex="-1">';
		echo '<h2 id="rrt-help-h-' . esc_attr( $key ) . '">' . esc_html( $t['title'] ) . '</h2>';
		echo '<p class="rrt-help-intro">' . esc_html( $t['intro'] ) . '</p>';
		foreach ( $t['body'] as $piece ) {
			echo rrt_help_piece_html( $piece, $key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped piece by piece.
		}
		echo '<p class="rrt-help-top"><a href="#rrt-help-toc-h">' . esc_html__( 'Back to the guides', 'foxfire-studio' ) . '</a></p>';
		echo '</section>';
	}

	echo '</div>';
}
