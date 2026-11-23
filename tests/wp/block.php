<?php
/**
 * Seeds the player block test (1.28.0), run inside the test WordPress:
 *   php wp-cli.phar --path=<wordpress> eval-file tests/wp/block.php       (prints JSON)
 *   php wp-cli.phar --path=<wordpress> eval-file tests/wp/block.php off   (removes it all)
 *
 * Four published posts: "own" holds the block between two paragraphs and a
 * reading whose captions match its text; "plain" has a reading and no block;
 * "empty" has no reading; "page" shows the readings of "own" and "plain"
 * through two blocks. The player is shown to everyone at the top of the
 * post while the test runs; the settings as they were come back with "off".
 */

$bk_state = 'rrt_block_test_state';

if ( isset( $args[0] ) && 'off' === $args[0] ) {
	$st = get_option( $bk_state );
	if ( is_array( $st ) ) {
		foreach ( $st['posts'] as $p ) {
			foreach ( get_children( array( 'post_parent' => $p, 'post_type' => 'attachment', 'numberposts' => -1, 'post_status' => 'any' ) ) as $a ) {
				wp_delete_attachment( $a->ID, true );
			}
			wp_delete_post( $p, true );
		}
		if ( null === $st['settings'] ) {
			delete_option( 'rrt_settings' );
		} else {
			update_option( 'rrt_settings', $st['settings'] );
		}
	}
	delete_option( $bk_state );
	return;
}

require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$was = get_option( 'rrt_settings', null );

/* A 40-second MP3 of silent frames, enough for the browser to play. */
$frame = "\xFF\xFB\x10\xC4" . str_repeat( "\x00", 100 );
$mp3   = str_repeat( $frame, 1540 );

$posts = array();
$make  = function ( $title, $content, $vtt ) use ( $mp3, &$posts ) {
	$id      = wp_insert_post(
		array(
			'post_title'   => $title,
			'post_status'  => 'publish',
			'post_content' => $content,
		)
	);
	$posts[] = $id;
	if ( null === $vtt ) {
		return $id;
	}
	foreach ( array(
		'rrt_audio'    => array( 'block-reading-' . $id . '.mp3', $mp3 ),
		'rrt_captions' => array( 'block-reading-' . $id . '.vtt', $vtt ),
	) as $key => $f ) {
		$tmp = wp_tempnam( $f[0] );
		file_put_contents( $tmp, $f[1] );
		$att = media_handle_sideload(
			array(
				'name'     => $f[0],
				'tmp_name' => $tmp,
			),
			$id
		);
		if ( ! is_wp_error( $att ) ) {
			update_post_meta( $id, $key, $att );
		}
	}
	return $id;
};

$vtt   = "WEBVTT\n\n00:00:00.000 --> 00:00:05.000\nThe lantern swung above the narrow lane\n\n00:00:05.000 --> 00:00:10.000\nand every window answered with its glow\n";
$own   = $make(
	'Lantern lane',
	"<!-- wp:paragraph --><p>The lantern swung above the narrow lane.</p><!-- /wp:paragraph -->\n<!-- wp:foxfire-studio/player /-->\n<!-- wp:paragraph --><p>And every window answered with its glow.</p><!-- /wp:paragraph -->",
	$vtt
);
$plain = $make(
	'Orchard morning',
	'<!-- wp:paragraph --><p>Dew sat on the apples before anyone woke.</p><!-- /wp:paragraph -->',
	"WEBVTT\n\n00:00:00.000 --> 00:00:05.000\nDew sat on the apples before anyone woke\n"
);
$empty = $make( 'Nothing recorded yet', '<!-- wp:paragraph --><p>A post still waiting for its reading.</p><!-- /wp:paragraph -->', null );
$page  = $make(
	'Two readings',
	"<!-- wp:paragraph --><p>Listen to these.</p><!-- /wp:paragraph -->\n<!-- wp:foxfire-studio/player {\"reading\":$own} /-->\n<!-- wp:foxfire-studio/player {\"reading\":$plain} /-->",
	null
);

$s                       = rrt_settings();
$s['player']['show']     = 'everyone';
$s['player']['position'] = 'top';
$s['player']['follow']   = true;
update_option( 'rrt_settings', $s );
update_option(
	$bk_state,
	array(
		'posts'    => $posts,
		'settings' => $was,
	),
	false
);

echo wp_json_encode(
	array(
		'own'   => $own,
		'plain' => $plain,
		'empty' => $empty,
		'page'  => $page,
	)
);
