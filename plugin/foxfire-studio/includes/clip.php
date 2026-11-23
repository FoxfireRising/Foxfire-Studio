<?php
/**
 * Clips for social media (0.13.0): what the clip maker needs, and the
 * "Make a clip" button on a reading's page in the dashboard. The clip maker
 * itself is assets/js/clip.js; it runs in the browser and sends nothing
 * anywhere unless "Save to Media Library" is pressed.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The Whisper code's address on jsDelivr, used when the site has no copy (the same as the teleprompter's). */
if ( ! defined( 'RRT_TJS_CDN' ) ) {
	define( 'RRT_TJS_CDN', 'https://cdn.jsdelivr.net/npm/@huggingface/transformers@4.3.2/dist/transformers.min.js' );
}

/** The post's text as plain words, for spelling clip captions. */
function rrt_clip_script( $post_id ) {
	$html = (string) get_post_field( 'post_content', $post_id );
	$text = wp_strip_all_tags( strip_shortcodes( $html ) );
	$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
	return get_the_title( $post_id ) . "\n" . preg_replace( '/\s+/u', ' ', $text );
}

/** The scripts and styles the clip maker loads, with versions for caching. */
function rrt_clip_assets() {
	$v = function ( $rel ) {
		$f = RRT_DIR . $rel;
		return RRT_URL . $rel . '?ver=' . RRT_VERSION . ( file_exists( $f ) ? '.' . filemtime( $f ) : '' );
	};
	return array(
		'js'         => $v( 'assets/js/clip.js' ),
		'mediabunny' => $v( 'assets/vendor/mediabunny/mediabunny.min.js' ),
		'aac'        => $v( 'assets/vendor/mediabunny/mediabunny-aac-encoder.min.js' ),
		'css'        => $v( 'assets/css/clip.css' ),
	);
}

/**
 * The MP4 writer (Mediabunny, 1.30.0) and the shared browser check, for the
 * clip maker and the full-length video. The AAC add-on is not enqueued: clip.js
 * loads it from beside Mediabunny only in a browser with no AAC of its own.
 *
 * @param callable $ver Gives a file's version for caching.
 */
function rrt_clip_enqueue_writer( $ver ) {
	wp_enqueue_script( 'rrt-mediabunny', RRT_URL . 'assets/vendor/mediabunny/mediabunny.min.js', array(), $ver( 'assets/vendor/mediabunny/mediabunny.min.js' ), true );
	wp_enqueue_script( 'rrt-can', RRT_URL . 'assets/js/can.js', array( 'wp-i18n' ), $ver( 'assets/js/can.js' ), true );
	wp_set_script_translations( 'rrt-can', 'foxfire-studio', rrt_languages_dir() );
}

/** SHA-256 of the shipped Mediabunny files (assets/vendor/mediabunny/README.txt), checked by the tests. */
function rrt_clip_vendor_hashes() {
	return array(
		'mediabunny.min.js'             => '8a0e529432f11a94be04a737005eac7581c4b6687783e0b5fe7cb8c8fd510692',
		'mediabunny-aac-encoder.min.js' => '5c336845cbd7eca2b9abfb080f696b10c2be9b53a23662c69d6da14083b2620a',
	);
}

/**
 * Everything the clip maker needs for one post, or null when the post has no
 * reading or the user cannot edit it.
 *
 * @param int   $post_id The post.
 * @param array $curve   Optional listening curve (heard, replayed, plays) for suggestions.
 */
function rrt_clip_boot( $post_id, $curve = null ) {
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return null;
	}
	$audio = rrt_reading_audio( $post_id );
	if ( empty( $audio['url'] ) ) {
		return null;
	}
	$cfg   = rrt_config();
	$image = get_the_post_thumbnail_url( $post_id, 'large' );
	$boot  = array(
		'audio'    => esc_url_raw( $audio['url'] ),
		'title'    => html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' ),
		'show'     => rrt_show_name( $cfg ),
		'domain'   => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
		'link'     => esc_url_raw( get_permalink( $post_id ) ),
		'image'    => $image ? esc_url_raw( $image ) : '',
		'palette'  => array(
			'bg'      => $cfg['colors']['bg'],
			'panel'   => $cfg['colors']['panel'],
			'text'    => $cfg['colors']['text'],
			'accent'  => $cfg['colors']['accent'],
			'accent2' => $cfg['colors']['accent2'],
		),
		'fonts'    => array(
			'reading'   => $cfg['fonts']['reading'],
			'headings'  => $cfg['fonts']['headings'],
			'interface' => $cfg['fonts']['interface'],
		),
		'fontsCss' => $cfg['fonts_local'] ? esc_url_raw( $cfg['fonts_local'] ) : '',
		'script'   => rrt_clip_script( $post_id ),
		/* 1.33.0: language set for a reading in any language but English, so the multilingual model hears it. */
		'whisper'  => array_merge( rrt_whisper_boot( $cfg ), array( 'cdn' => RRT_TJS_CDN, 'language' => function_exists( 'rrt_post_lang' ) && 'en' !== rrt_post_lang( $post_id ) ? rrt_post_lang( $post_id ) : '' ) ),
		'src'      => isset( $cfg['listen']['src'] ) ? $cfg['listen']['src'] : 'src',
		'slug'     => (string) get_post_field( 'post_name', $post_id ),
		'upload'   => current_user_can( 'upload_files' ) ? array(
			'rest'  => esc_url_raw( rest_url( 'wp/v2/media' ) ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'post'  => (int) $post_id,
		) : null,
		'curve'    => $curve,
	);
	return apply_filters( 'rrt_clip_boot', $boot, $post_id );
}

/**
 * The suggestions as the clip maker shows them: where each starts, its exact
 * length, a label and why it is suggested.
 *
 * @param array $sugg From rrt_suggestions().
 */
function rrt_clip_suggest_boot( $sugg ) {
	$out = array();
	$n   = 0;
	foreach ( $sugg as $sg ) {
		$out[] = array(
			'start' => $sg['start'],
			'len'   => $sg['len'],
			'label' => $sg['opening'] ? __( 'The opening', 'foxfire-studio' ) : sprintf( /* translators: %d: the number of the suggestion. */ __( 'Suggestion %d', 'foxfire-studio' ), ++$n ),
			'why'   => implode( '. ', array_merge( $sg['reasons'], $sg['cautions'] ) ),
			/* The first word's start, the last word's end and the sentences either side, for settling the edges on the audio. */
			's'     => $sg['s'],
			'e'     => $sg['e'],
			'prev'  => $sg['prev'],
			'next'  => $sg['next'],
		);
	}
	return $out;
}

/**
 * The "Make a clip" button on a reading's page in the dashboard, with the
 * reading's suggested clips (1.7.0) under it.
 *
 * @param int   $post_id The post.
 * @param array $c       The listening curve from rrt_dash_curve().
 * @param bool  $current Whether the curve is of the reading now on the post.
 */
function rrt_clip_button( $post_id, $c, $current = true ) {
	$curve = null;
	if ( ! empty( $c['heard'] ) ) {
		$curve = array(
			'heard'    => array_map( 'intval', $c['heard'] ),
			'replayed' => array_map( 'intval', $c['replayed'] ),
			'plays'    => (int) $c['plays'],
		);
	}
	$boot = rrt_clip_boot( $post_id, $curve );
	if ( ! $boot ) {
		return;
	}
	$sugg            = function_exists( 'rrt_suggestions' ) ? rrt_suggestions( $post_id, $current ? $curve : null ) : array();
	$boot['suggest'] = $sugg ? rrt_clip_suggest_boot( $sugg ) : null;
	if ( $boot['fontsCss'] ) {
		echo '<link rel="stylesheet" href="' . esc_url( $boot['fontsCss'] ) . '">'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
	}
	echo '<div class="rrt-dash-clip"><button type="button" class="button button-primary" data-rrt-clip>' . esc_html__( 'Make a clip', 'foxfire-studio' ) . '</button>';
	echo '<span class="rrt-small">' . esc_html__( 'A short captioned video of a moment of this reading, for Instagram, Facebook, TikTok or YouTube, with its own tagged link.', 'foxfire-studio' ) . '</span></div>';
	rrt_clip_suggest_table( $post_id, $sugg, $curve && $current );
	echo '<script type="application/json" id="rrt-clip-boot">' . wp_json_encode( $boot, JSON_HEX_TAG | JSON_HEX_AMP ) . '</script>';
	$ver = function ( $rel ) {
		return RRT_VERSION . '.' . filemtime( RRT_DIR . $rel );
	};
	wp_enqueue_style( 'rrt-clip', RRT_URL . 'assets/css/clip.css', array(), $ver( 'assets/css/clip.css' ) );
	rrt_clip_enqueue_writer( $ver );
	wp_enqueue_script( 'rrt-whisper', RRT_URL . 'assets/js/whisper.js', array(), $ver( 'assets/js/whisper.js' ), true );
	/* The sound engine levels each clip to -14 LUFS (1.6.0). */
	wp_enqueue_script( 'rrt-sound', RRT_URL . 'assets/js/sound.js', array( 'wp-i18n' ), $ver( 'assets/js/sound.js' ), true );
	wp_set_script_translations( 'rrt-sound', 'foxfire-studio', rrt_languages_dir() );
	wp_enqueue_script( 'rrt-clip', RRT_URL . 'assets/js/clip.js', array( 'rrt-mediabunny', 'rrt-can', 'rrt-whisper', 'rrt-sound', 'wp-i18n' ), $ver( 'assets/js/clip.js' ), true );
	wp_set_script_translations( 'rrt-clip', 'foxfire-studio', rrt_languages_dir() );
	/* A suggestion's "Make this clip" opens the clip maker at its start, with its exact length. */
	wp_add_inline_script(
		'rrt-clip',
		'document.addEventListener("click",function(e){var b=e.target.closest("[data-rrt-clip]");if(!b){return;}var d=document.getElementById("rrt-clip-boot");if(!d){return;}var o=JSON.parse(d.textContent);if(b.hasAttribute("data-s")){o.start=parseFloat(b.getAttribute("data-s"));o.len=parseFloat(b.getAttribute("data-len"));}window.RRTClip.open(o);});'
	);
}

/**
 * The Suggested clips table on a reading's page.
 *
 * @param int   $post_id The post.
 * @param array $sugg    From rrt_suggestions().
 * @param bool  $listen  Whether the listening curve was used.
 */
function rrt_clip_suggest_table( $post_id, $sugg, $listen ) {
	echo '<div class="rrt-suggest" id="rrt-suggest"><h3>' . esc_html__( 'Suggested clips', 'foxfire-studio' ) . '</h3>';
	if ( ! $sugg ) {
		$has_cues = function_exists( 'rrt_dash_cues' ) && rrt_dash_cues( $post_id );
		echo '<p class="rrt-small">' . esc_html(
			$has_cues
				? __( 'This reading is too short for a 30 second clip that starts and ends with a sentence. The clip maker still suggests moments by sound.', 'foxfire-studio' )
				: __( 'This reading has no captions, so there are no suggestions here. Captions come with a reading put on the post with Use this recording in the studio. The clip maker still suggests moments by sound.', 'foxfire-studio' )
		) . '</p></div>';
		return;
	}
	echo '<p class="rrt-small">' . esc_html(
		$listen
			? __( 'Stretches of 30 to 60 seconds that start and end with a sentence, chosen from the captions and from how listeners heard this reading. The opening first, then the best three.', 'foxfire-studio' )
			: __( 'Stretches of 30 to 60 seconds that start and end with a sentence, chosen from the captions. The opening first, then the best three. Once the reading has 10 plays, what listeners replay and stay through counts too.', 'foxfire-studio' )
	) . '</p>';
	$pick = function_exists( 'rrt_soundbite_pick' ) ? rrt_soundbite_pick( $post_id ) : null;
	$back = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	$form = function ( $do, $label, $s = 0, $e = 0, $primary = false ) use ( $post_id, $back ) {
		$h  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="rrt-inline-form">';
		$h .= '<input type="hidden" name="action" value="rrt_soundbite"><input type="hidden" name="post" value="' . esc_attr( $post_id ) . '"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
		$h .= '<input type="hidden" name="s" value="' . esc_attr( $s ) . '"><input type="hidden" name="e" value="' . esc_attr( $e ) . '"><input type="hidden" name="back" value="' . esc_attr( $back ) . '">';
		$h .= wp_nonce_field( 'rrt_soundbite_' . $post_id, '_wpnonce', true, false );
		$h .= '<button type="submit" class="button' . ( $primary ? ' button-primary' : '' ) . ' button-small">' . esc_html( $label ) . '</button></form>';
		return $h;
	};
	$matched = false;
	echo '<table class="widefat striped rrt-table rrt-suggest-table"><thead><tr><th>' . esc_html__( 'When', 'foxfire-studio' ) . '</th><th>' . esc_html__( 'From and to', 'foxfire-studio' ) . '</th><th>' . esc_html__( 'Why', 'foxfire-studio' ) . '</th><th></th></tr></thead><tbody>';
	$n = 0;
	foreach ( $sugg as $sg ) {
		$label = $sg['opening'] ? __( 'The opening', 'foxfire-studio' ) : sprintf( /* translators: %d: the number of the suggestion. */ __( 'Suggestion %d', 'foxfire-studio' ), ++$n );
		$mine  = $pick && abs( $pick['s'] - $sg['start'] ) < 0.02 && abs( $pick['d'] - $sg['len'] ) < 0.02;
		$matched = $matched || $mine;
		echo '<tr' . ( $mine ? ' class="is-feed"' : '' ) . ' data-s="' . esc_attr( $sg['start'] ) . '" data-len="' . esc_attr( $sg['len'] ) . '">';
		echo '<td><strong>' . esc_html( $label ) . '</strong><br><span class="rrt-suggest-when">' . esc_html( sprintf( /* translators: 1: start time, 2: end time, 3: length in seconds. */ __( '%1$s to %2$s (%3$s s)', 'foxfire-studio' ), rrt_clock( $sg['start'] ), rrt_clock( $sg['start'] + $sg['len'] ), number_format_i18n( $sg['len'], 0 ) ) ) . '</span></td>';
		echo '<td class="rrt-suggest-words">“' . esc_html( $sg['first'] ) . '” … “' . esc_html( $sg['last'] ) . '”</td>';
		echo '<td>' . esc_html( implode( ', ', $sg['reasons'] ) );
		foreach ( $sg['cautions'] as $cn ) {
			echo '<br><span class="rrt-small rrt-suggest-caution">' . esc_html( $cn ) . '</span>';
		}
		echo '</td><td class="rrt-suggest-do">';
		echo '<button type="button" class="button button-small rrt-seek" data-t="' . esc_attr( $sg['start'] ) . '" data-end="' . esc_attr( round( $sg['start'] + $sg['len'], 2 ) ) . '" aria-label="' . esc_attr( sprintf( /* translators: %s: the suggestion's label. */ __( 'Hear %s', 'foxfire-studio' ), $label ) ) . '">▶</button> ';
		echo '<button type="button" class="button button-small" data-rrt-clip data-s="' . esc_attr( $sg['start'] ) . '" data-len="' . esc_attr( $sg['len'] ) . '">' . esc_html( sprintf( /* translators: %s: length in seconds. */ __( 'Make this clip (%s s)', 'foxfire-studio' ), number_format_i18n( $sg['len'], 1 ) ) ) . '</button> ';
		if ( $mine ) {
			echo '<span class="rrt-suggest-feed">' . esc_html__( 'Used for the feed', 'foxfire-studio' ) . '</span> ' . $form( 'clear', __( 'Stop using', 'foxfire-studio' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			echo $form( 'use', __( 'Use for the feed', 'foxfire-studio' ), $sg['s'], $sg['e'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</td></tr>';
	}
	echo '</tbody></table>';
	if ( $pick && ! $matched ) {
		echo '<p class="rrt-small">' . esc_html( sprintf( /* translators: 1: start time, 2: length in seconds. */ __( 'Used for the feed: from %1$s for %2$s s, chosen earlier.', 'foxfire-studio' ), rrt_clock( $pick['s'] ), number_format_i18n( $pick['d'], 1 ) ) ) . ' ' . $form( 'clear', __( 'Stop using', 'foxfire-studio' ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	echo '<p class="rrt-small">' . esc_html__( 'Use for the feed keeps that stretch as this episode\'s soundbite, a preview some podcast apps play. Without a choice, the feed will use the top suggestion. A new reading on the post clears the choice.', 'foxfire-studio' ) . '</p>';
	echo '</div>';
}
