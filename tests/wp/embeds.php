<?php
/**
 * Seeds the embedded players test (1.35.0), run inside the test WordPress:
 *   php wp-cli.phar --path=<wordpress> eval-file tests/wp/embeds.php       (prints JSON)
 *   php wp-cli.phar --path=<wordpress> eval-file tests/wp/embeds.php off   (removes it all)
 *
 * One published post with a reading (a silent MP3), shown to everyone at
 * the top, then a YouTube video in an Embed block (its embed code made here
 * once from a stand-in answer, and kept by WordPress with the post), a
 * Short, a SoundCloud track and a SoundCloud playlist as copied embed code,
 * and a Vimeo player left alone. Both switches are on, and each video's and
 * track's title and picture are saved already, so the test site never asks
 * YouTube or SoundCloud for anything. "off" puts the settings back.
 */

$em_state = 'rrt_embeds_test_state';

if ( isset( $args[0] ) && 'off' === $args[0] ) {
	$st = get_option( $em_state );
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
	rrt_embeds_clear();
	delete_option( 'rrt_embeds' );
	delete_option( $em_state );
	return;
}

require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$was = get_option( 'rrt_settings', null );

$yt    = "<!-- wp:embed {\"url\":\"https://www.youtube.com/watch?v=FoxTestVid1\u0026t=12s\",\"type\":\"video\",\"providerNameSlug\":\"youtube\",\"responsive\":true,\"className\":\"wp-embed-aspect-16-9 wp-has-aspect-ratio\"} -->\n<figure class=\"wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio\"><div class=\"wp-block-embed__wrapper\">\nhttps://www.youtube.com/watch?v=FoxTestVid1&amp;t=12s\n</div></figure>\n<!-- /wp:embed -->";
$short = '<!-- wp:html --><iframe width="315" height="560" src="https://www.youtube.com/embed/FoxShortVid?si=x" title="A made-up Short" frameborder="0" allowfullscreen></iframe><!-- /wp:html -->';
$sc    = '<!-- wp:html --><iframe width="100%" height="166" scrolling="no" frameborder="no" allow="autoplay" src="https://w.soundcloud.com/player/?url=https%3A//api.soundcloud.com/tracks/1234567&amp;color=%23ff5500&amp;auto_play=false"></iframe><!-- /wp:html -->';
$scp   = '<!-- wp:html --><iframe width="100%" height="450" scrolling="no" frameborder="no" allow="autoplay" src="https://w.soundcloud.com/player/?url=https%3A//api.soundcloud.com/playlists/7654321&amp;visual=true"></iframe><!-- /wp:html -->';
$vimeo = '<!-- wp:html --><iframe src="https://player.vimeo.com/video/76979871" width="300" height="169" title="Another service"></iframe><!-- /wp:html -->';
$p     = '<!-- wp:paragraph --><p>%s</p><!-- /wp:paragraph -->';

$id = wp_insert_post(
	array(
		'post_title'   => 'Players on a page',
		'post_status'  => 'publish',
		'post_content' => sprintf( $p, 'The kettle sang while the rain kept time on the window.' ) . "\n" . $yt . "\n" . sprintf( $p, 'A short one next.' ) . "\n" . $short . "\n" . sprintf( $p, 'Then something to hear.' ) . "\n" . $sc . "\n" . $scp . "\n" . sprintf( $p, 'And another service.' ) . "\n" . $vimeo . "\n" . sprintf( $p, 'The end of the page.' ),
	)
);

/* A 40-second MP3 of silent frames, enough for the browser to play. */
$frame = "\xFF\xFB\x10\xC4" . str_repeat( "\x00", 100 );
$tmp   = wp_tempnam( 'embeds-reading.mp3' );
file_put_contents( $tmp, str_repeat( $frame, 1540 ) );
$att = media_handle_sideload(
	array(
		'name'     => 'embeds-reading-' . $id . '.mp3',
		'tmp_name' => $tmp,
	),
	$id
);
if ( ! is_wp_error( $att ) ) {
	update_post_meta( $id, 'rrt_audio', $att );
}

$s                       = rrt_settings();
$s['player']['show']     = 'everyone';
$s['player']['position'] = 'top';
update_option( 'rrt_settings', $s );
update_option( 'rrt_embeds', array( 'yt' => true, 'sc' => true ) );

/* WordPress makes and keeps the Embed block's code now, from a stand-in answer, as it would on the post's first view. */
add_filter(
	'pre_http_request',
	function ( $pre, $a, $url ) {
		if ( false === strpos( $url, 'youtube.com/oembed' ) ) {
			return $pre;
		}
		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'type'   => 'video',
					'title'  => 'A made-up video',
					'width'  => 500,
					'height' => 281,
					'html'   => '<iframe width="500" height="281" src="https://www.youtube.com/embed/FoxTestVid1?feature=oembed" title="A made-up video" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>',
				)
			),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	1,
	3
);
add_filter( 'rrt_embeds_context_ok', '__return_false' );
$GLOBALS['post'] = get_post( $id ); // phpcs:ignore
setup_postdata( $GLOBALS['post'] );
apply_filters( 'the_content', get_post( $id )->post_content );
wp_reset_postdata();
remove_filter( 'rrt_embeds_context_ok', '__return_false' );

/* Titles and pictures saved already: a 2 x 1 PNG for each, from bytes. */
$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAABCAIAAAB7QOjdAAAAD0lEQVR4nGM44OBwwMEBAAnHAoHjluSfAAAAAElFTkSuQmCC' );
rrt_embeds_dir_ready();
$list = array();
foreach ( array(
	rrt_embeds_yt_key( rrt_embeds_yt_parse( 'https://www.youtube.com/embed/FoxTestVid1' ) ) => 'A made-up video',
	rrt_embeds_yt_key( rrt_embeds_yt_parse( 'https://www.youtube.com/embed/FoxShortVid' ) ) => 'A made-up Short',
	rrt_embeds_sc_key( rrt_embeds_sc_parse( 'https://w.soundcloud.com/player/?url=https%3A//api.soundcloud.com/tracks/1234567' ) ) => 'A made-up track',
	rrt_embeds_sc_key( rrt_embeds_sc_parse( 'https://w.soundcloud.com/player/?url=https%3A//api.soundcloud.com/playlists/7654321' ) ) => 'A made-up playlist',
) as $key => $title ) {
	$f = $key . '-test01.png';
	file_put_contents( rrt_embeds_dir() . $f, $png );
	$list[ $key ] = array(
		't'    => $title,
		'f'    => $f,
		'w'    => 2,
		'h'    => 1,
		'at'   => time(),
		'seen' => time(),
		'fail' => 0,
	);
}
rrt_embeds_list_save( $list );

update_option(
	$em_state,
	array(
		'posts'    => array( $id ),
		'settings' => $was,
	),
	false
);

echo wp_json_encode(
	array(
		'post' => $id,
		'url'  => get_permalink( $id ),
	)
);
