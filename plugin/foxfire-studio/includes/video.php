<?php
/**
 * Full-length video (1.18.0): the Full-length video section on a reading's
 * page in the dashboard, and what the video maker needs to know about the
 * post. The video maker itself is assets/js/video.js; it runs in the browser,
 * keeps the video in the browser's own storage until it is downloaded, and
 * sends nothing anywhere.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The two quality choices, in bits a second of picture. Standard is the default. */
function rrt_video_qualities() {
	return array(
		'standard' => 5000000,
		'smaller'  => 2500000,
	);
}

/**
 * The post's chapters for the video and its description: the chapters in the
 * reading's MP3 tag, worked out once by the feed (1.10.0).
 *
 * @param int $post_id The post.
 * @return array[] start, end (seconds), title.
 */
function rrt_video_chapters( $post_id ) {
	if ( ! function_exists( 'rrt_feed_facts' ) ) {
		return array();
	}
	$facts = rrt_feed_facts( $post_id );
	$out   = array();
	foreach ( (array) ( isset( $facts['chapters'] ) ? $facts['chapters'] : array() ) as $c ) {
		if ( ! isset( $c['start'], $c['title'] ) || '' === trim( (string) $c['title'] ) ) {
			continue;
		}
		$out[] = array(
			'start' => round( (float) $c['start'], 3 ),
			'end'   => isset( $c['end'] ) ? round( (float) $c['end'], 3 ) : 0,
			'title' => (string) $c['title'],
		);
	}
	return $out;
}

/** A short description of the post for the video's upload: its excerpt, or its first 55 words. */
function rrt_video_excerpt( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return '';
	}
	$text = $post->post_excerpt ? $post->post_excerpt : wp_trim_words( strip_shortcodes( $post->post_content ), 55, '...' );
	$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );
	return trim( preg_replace( '/\s+/u', ' ', $text ) );
}

/** The show's cover art from the podcast feed's settings, as a fallback picture, or ''. */
function rrt_video_cover() {
	if ( ! function_exists( 'rrt_podcast_settings' ) ) {
		return '';
	}
	$s  = rrt_podcast_settings();
	$id = isset( $s['image'] ) ? (int) $s['image'] : 0;
	if ( ! $id ) {
		return '';
	}
	$src = wp_get_attachment_image_src( $id, 'large' );
	return $src ? (string) $src[0] : '';
}

/**
 * Everything the video maker needs for one post, on top of the clip maker's
 * boot (audio, title, show, domain, link, image, palette, fonts), or null when
 * the clip maker has nothing for it.
 *
 * @param array $clip    From rrt_clip_boot().
 * @param int   $post_id The post.
 */
function rrt_video_boot( $clip, $post_id ) {
	if ( ! $clip ) {
		return null;
	}
	$cap  = function_exists( 'rrt_reading_captions' ) ? rrt_reading_captions( $post_id ) : array( 'url' => '' );
	$boot = array(
		'audio'     => $clip['audio'],
		'title'     => $clip['title'],
		'show'      => $clip['show'],
		'domain'    => $clip['domain'],
		'link'      => $clip['link'],
		'src'       => $clip['src'],
		'slug'      => $clip['slug'],
		'palette'   => $clip['palette'],
		'fonts'     => $clip['fonts'],
		'image'     => $clip['image'],
		'cover'     => esc_url_raw( rrt_video_cover() ),
		'captions'  => empty( $cap['url'] ) ? '' : esc_url_raw( $cap['url'] ),
		'chapters'  => rrt_video_chapters( $post_id ),
		'excerpt'   => rrt_video_excerpt( $post_id ),
		'qualities' => rrt_video_qualities(),
		'choose'    => current_user_can( 'upload_files' ),
	);
	return apply_filters( 'rrt_video_boot', $boot, $post_id );
}

/**
 * The Full-length video section on a reading's page, under the clips.
 *
 * @param int $post_id The post.
 */
function rrt_video_section( $post_id ) {
	$clip = function_exists( 'rrt_clip_boot' ) ? rrt_clip_boot( $post_id ) : null;
	$boot = rrt_video_boot( $clip, $post_id );
	if ( ! $boot ) {
		return;
	}
	echo '<div class="rrt-video" id="rrt-video"><h3>' . esc_html__( 'Full-length video', 'foxfire-studio' ) . '</h3>';
	echo '<p class="rrt-small">' . esc_html__( 'The whole reading as a wide 1080p video, for YouTube, Spotify and other apps that show video. It is made in this browser on this computer and downloads when it is ready; nothing is kept on the site or sent anywhere. Upload it yourself in YouTube Studio or Spotify for Creators, with the description, captions and thumbnail below.', 'foxfire-studio' ) . '</p>';
	echo '<div class="rrt-video-app" data-rrt-video><p class="rrt-small">' . esc_html__( 'Loading the video maker...', 'foxfire-studio' ) . '</p></div>';
	echo '<script type="application/json" id="rrt-video-boot">' . wp_json_encode( $boot, JSON_HEX_TAG | JSON_HEX_AMP ) . '</script>';
	echo '</div>';
	$ver = function ( $rel ) {
		return RRT_VERSION . '.' . filemtime( RRT_DIR . $rel );
	};
	if ( $boot['choose'] ) {
		wp_enqueue_media();
	}
	wp_enqueue_style( 'rrt-video', RRT_URL . 'assets/css/video.css', array(), $ver( 'assets/css/video.css' ) );
	rrt_clip_enqueue_writer( $ver );
	/* The clip maker's picture (its background, card, title and waveform) is drawn the same way here. */
	wp_enqueue_script( 'rrt-clip', RRT_URL . 'assets/js/clip.js', array( 'rrt-mediabunny', 'rrt-can', 'wp-i18n' ), $ver( 'assets/js/clip.js' ), true );
	wp_enqueue_script( 'rrt-video', RRT_URL . 'assets/js/video.js', array( 'rrt-mediabunny', 'rrt-clip', 'wp-i18n' ), $ver( 'assets/js/video.js' ), true );
	wp_set_script_translations( 'rrt-video', 'foxfire-studio', rrt_languages_dir() );
}
