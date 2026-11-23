<?php
/**
 * Seeds the accessibility test (1.26.0), run inside the test WordPress:
 *   php wp-cli.phar --path=<wordpress> eval-file tests/wp/a11y.php       (prints JSON)
 *   php wp-cli.phar --path=<wordpress> eval-file tests/wp/a11y.php off   (removes it all)
 *
 * Two published posts with a 40-second reading: one whose captions match its
 * text (read along follows the post), one whose captions differ (read along
 * falls back to the transcript list). The player is shown to everyone while
 * the test runs; the settings as they were are kept and put back by "off".
 */

$a11y_state = 'rrt_a11y_test_state';

if ( isset( $args[0] ) && 'off' === $args[0] ) {
	$st = get_option( $a11y_state );
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
	delete_option( $a11y_state );
	return;
}

require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$was = get_option( 'rrt_settings', null );

/* A 40-second MP3: a silent ID3 tag and frames, enough for the browser to play and seek. */
$frame = "\xFF\xFB\x10\xC4" . str_repeat( "\x00", 100 ); // 32 kbps, 22.05 kHz mono: 104 bytes, about 26 ms.
$mp3   = '';
for ( $i = 0; $i < 1540; $i++ ) {
	$mp3 .= $frame;
}

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
	foreach ( array(
		'rrt_audio'    => array( 'a11y-reading-' . $id . '.mp3', $mp3 ),
		'rrt_captions' => array( 'a11y-reading-' . $id . '.vtt', $vtt ),
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

$vtt = "WEBVTT\n\n00:00:00.000 --> 00:00:05.000\nThe moon rose over the quiet hill\n\n00:00:05.000 --> 00:00:10.000\nand the river carried its light away\n\n00:00:10.000 --> 00:00:16.000\nNobody walked the road that night\n";
$follow = $make(
	'A quiet hill',
	"<!-- wp:paragraph --><p>The moon rose over the quiet hill and the river carried its light away.</p><!-- /wp:paragraph -->\n<!-- wp:paragraph --><p>Nobody walked the road that night.</p><!-- /wp:paragraph -->",
	$vtt
);
$listed = $make(
	'Another evening',
	'<!-- wp:paragraph --><p>A short note that is not what the reading says at all.</p><!-- /wp:paragraph -->',
	$vtt
);

$s = is_array( $was ) ? $was : rrt_settings();
$s['player']['show']     = 'everyone';
$s['player']['position'] = 'top';
$s['player']['follow']   = true;
$s['player']['download'] = 1;
update_option( 'rrt_settings', $s );

update_option(
	$a11y_state,
	array(
		'posts'    => $posts,
		'settings' => $was,
	),
	false
);

echo wp_json_encode(
	array(
		'follow' => $follow,
		'listed' => $listed,
	)
);
