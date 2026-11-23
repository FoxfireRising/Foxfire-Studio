<?php
/**
 * The listening player on public posts.
 *
 * Shows a post's audio reading with play, a progress bar, time, skip back
 * and forward, speed, read along (the post text lights up as it is read),
 * resume where you left off, links that start at a moment (?t=1:45), and
 * lock screen controls. Without JavaScript it is a plain audio element.
 *
 * Nothing is measured or sent anywhere yet. The player announces what
 * listeners do as browser events (rrt:player) for the analytics in 0.4.0.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a stored value into a usable URL and attachment ID. Accepts an
 * attachment ID, an ACF-style array, or a plain URL.
 *
 * @param mixed $value Stored value.
 * @return array array( 'url' => string, 'id' => int ).
 */
function rrt_media_from_value( $value ) {
	$id  = 0;
	$url = '';
	if ( is_array( $value ) ) {
		if ( ! empty( $value['ID'] ) ) {
			$id = (int) $value['ID'];
		} elseif ( ! empty( $value['id'] ) ) {
			$id = (int) $value['id'];
		}
		if ( ! empty( $value['url'] ) ) {
			$url = $value['url'];
		}
	} elseif ( is_numeric( $value ) ) {
		$id = (int) $value;
	} elseif ( is_string( $value ) ) {
		$url = trim( $value );
	}
	if ( $id && ! $url ) {
		$url = (string) wp_get_attachment_url( $id );
	}
	if ( $url && ! preg_match( '#^(https?:)?//|^/#i', $url ) ) {
		$url = ''; // Only web addresses; never javascript: or data: values.
	}
	return array(
		'url' => $url,
		'id'  => $id,
	);
}

/** The reading for a post: the plugin's own field first, then the extra field from Settings. */
function rrt_reading_audio( $post_id ) {
	$cfg  = rrt_config();
	$keys = array( 'rrt_audio' );
	if ( ! empty( $cfg['player']['audio_key'] ) ) {
		$keys[] = $cfg['player']['audio_key'];
	}
	foreach ( $keys as $key ) {
		$m = rrt_media_from_value( get_post_meta( $post_id, $key, true ) );
		if ( $m['url'] ) {
			return $m;
		}
	}
	return array(
		'url' => '',
		'id'  => 0,
	);
}

/**
 * A post's "read by" credit (0.22.0): the Reading audio box's own field
 * first, then the extra field from Settings. Plain text, or ''.
 */
function rrt_reading_credit( $post_id ) {
	$cfg  = rrt_config();
	$keys = array( 'rrt_credit' );
	if ( ! empty( $cfg['player']['credit_key'] ) ) {
		$keys[] = $cfg['player']['credit_key'];
	}
	foreach ( $keys as $key ) {
		$v = get_post_meta( $post_id, $key, true );
		$v = is_string( $v ) ? trim( wp_strip_all_tags( $v ) ) : '';
		if ( '' !== $v ) {
			return mb_substr( $v, 0, 120 );
		}
	}
	return '';
}

/** The captions file (.vtt) for a post's reading, used for read along. */
function rrt_reading_captions( $post_id ) {
	return rrt_media_from_value( get_post_meta( $post_id, 'rrt_captions', true ) );
}

/** Whether the current visitor should see the player under the "Show the player to" setting. */
function rrt_player_visible() {
	$cfg = rrt_config();
	$who = $cfg['player']['show'];
	if ( 'everyone' === $who ) {
		return true;
	}
	if ( 'editors' === $who ) {
		return current_user_can( 'edit_posts' );
	}
	return false;
}

/** Whether the page being shown is the reading's own post (1.28.0). */
function rrt_player_on_own_page( $post ) {
	$post = get_post( $post );
	return $post && is_singular() && (int) get_queried_object_id() === (int) $post->ID;
}

/** Formats seconds as m:ss. */
function rrt_clock( $sec ) {
	$sec = max( 0, (int) round( $sec ) );
	return floor( $sec / 60 ) . ':' . str_pad( (string) ( $sec % 60 ), 2, '0', STR_PAD_LEFT );
}

/**
 * The player's HTML for a post. Themes can call this directly:
 * <?php if ( function_exists( 'rrt_player' ) ) { echo rrt_player(); } ?>
 *
 * @param int|WP_Post|null $post Post, or the current post.
 * @return string
 */
function rrt_player( $post = null ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return '';
	}
	/* Never reveal the reading of a post the visitor cannot read: drafts, private posts, or a password-protected post before the password is given. */
	if ( ! is_post_publicly_viewable( $post ) && ! current_user_can( 'read_post', $post->ID ) ) {
		return '';
	}
	if ( post_password_required( $post ) ) {
		return '';
	}
	$audio = rrt_reading_audio( $post->ID );
	if ( ! $audio['url'] ) {
		return '';
	}
	/* A reading for members (1.21.0): members hear it from its checked address; everyone else sees why not. */
	if ( function_exists( 'rrt_access_settle' ) && ! rrt_access_settle( $post->ID ) ) {
		if ( ! rrt_member_can_hear( $post->ID ) ) {
			return rrt_member_locked_html( $post );
		}
		$audio = rrt_reading_audio( $post->ID );
	}
	$cfg = rrt_config();
	$p   = $cfg['player'];
	$cap = rrt_reading_captions( $post->ID );

	$length = 0;
	if ( $audio['id'] ) {
		$meta = wp_get_attachment_metadata( $audio['id'] );
		if ( ! empty( $meta['length'] ) ) {
			$length = (int) $meta['length'];
		}
	}

	$art   = array();
	$thumb = get_post_thumbnail_id( $post );
	if ( $thumb ) {
		foreach ( array( 'thumbnail', 'medium', 'large' ) as $size ) {
			$img = wp_get_attachment_image_src( $thumb, $size );
			if ( $img ) {
				$art[] = array(
					'src'   => $img[0],
					'sizes' => $img[1] . 'x' . $img[2],
					'type'  => (string) get_post_mime_type( $thumb ),
				);
			}
		}
	}

	$data = array(
		'post'     => (int) $post->ID,
		'title'    => wp_strip_all_tags( get_the_title( $post ) ),
		'artist'   => wp_strip_all_tags( get_the_author_meta( 'display_name', $post->post_author ) ),
		'album'    => rrt_show_name( $cfg ),
		'artwork'  => $art,
		'back'     => (int) $p['skip_back'],
		'fwd'      => (int) $p['skip_fwd'],
		'speeds'   => array_map( 'floatval', array_filter( explode( ',', $p['speeds'] ) ) ),
		/* Read along only on the reading's own page (1.28.0): elsewhere the post's text is not there to follow. */
		'follow'   => ( $p['follow'] && $cap['url'] && rrt_player_on_own_page( $post ) ) ? 1 : 0,
		'captions' => $cap['url'],
		'content'  => $p['content_selector'],
		'resume'   => $p['resume'] ? 1 : 0,
		/* 1.33.0: the reading's language, so read along matches words of any script. */
		'lang'     => function_exists( 'rrt_post_lang' ) ? rrt_post_lang( $post->ID ) : 'en',
		/* The player's own words (0.25.0), translated here so public pages need no translation script. */
		'text'     => array(
			'pause'    => __( 'Pause the audio reading', 'foxfire-studio' ),
			'play'     => __( 'Play the audio reading', 'foxfire-studio' ),
			/* translators: %s: the speed, such as 1.5×. Shown on the speed button (1.26.0). */
			'speed'    => __( 'Speed %s', 'foxfire-studio' ),
			/* translators: %s: a time in the reading, such as 2:15. */
			'resume'   => __( 'Resume at %s', 'foxfire-studio' ),
			/* translators: 1: time played, 2: total length. */
			'of'       => __( '%1$s of %2$s', 'foxfire-studio' ),
			'chapters' => __( 'Chapters', 'foxfire-studio' ),
			/* translators: %s: a time in the reading, such as 2:15. */
			'playFrom' => __( 'Play from %s', 'foxfire-studio' ),
			'noText'   => __( 'The transcript could not be loaded.', 'foxfire-studio' ),
		),
	);
	$listen = function_exists( 'rrt_listen_boot' ) ? rrt_listen_boot( $cfg ) : null;
	if ( $listen ) {
		$data['listen'] = $listen;
	}
	/* The optional Google Analytics link (1.2.0), with the same people left out as Foxfire Studio's own measuring. */
	if ( ! empty( $cfg['listen']['ga'] ) && ! ( function_exists( 'rrt_listen_excluded_user' ) && rrt_listen_excluded_user() ) ) {
		$data['ga'] = array(
			't' => html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' ),
			'd' => empty( $cfg['listen']['dnt'] ) ? 0 : 1,
		);
	}

	rrt_player_enqueue();

	$label  = $cfg['text']['listen'];
	$credit = rrt_reading_credit( $post->ID );
	$pid    = (int) $post->ID;
	/* 1.33.0: the transcript and captions say the reading's language; a reading in the site's language prints as before. */
	$rlang  = function_exists( 'rrt_post_lang_tag' ) ? rrt_post_lang_tag( $post->ID ) : substr( get_locale(), 0, 2 );
	$other  = function_exists( 'rrt_post_lang' ) && rrt_post_lang( $post->ID ) !== rrt_site_lang();
	ob_start();
	?>
	<div class="rrt-player" role="group" aria-label="<?php esc_attr_e( 'Audio reading of this post', 'foxfire-studio' ); ?>" data-rrt="<?php echo esc_attr( wp_json_encode( $data ) ); ?>">
		<audio class="rrt-audio" src="<?php echo esc_url( function_exists( 'rrt_off_url' ) ? rrt_off_url( $audio['url'], 'player' ) : $audio['url'] ); ?>" preload="metadata" controls>
			<?php if ( $cap['url'] ) : ?>
				<track kind="captions" src="<?php echo esc_url( $cap['url'] ); ?>" srclang="<?php echo esc_attr( $other ? $rlang : substr( get_locale(), 0, 2 ) ); ?>" label="<?php esc_attr_e( 'Captions', 'foxfire-studio' ); ?>">
			<?php endif; ?>
		</audio>
		<div class="rrt-row">
			<button type="button" class="rrt-play" aria-label="<?php esc_attr_e( 'Play the audio reading', 'foxfire-studio' ); ?>">
				<svg class="rrt-i-play" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5.5v13l11-6.5z"/></svg>
				<svg class="rrt-i-pause" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 5h3.5v14H7zM13.5 5H17v14h-3.5z"/></svg>
			</button>
			<div class="rrt-main">
				<div class="rrt-label"><?php echo esc_html( $label ); ?></div>
				<?php if ( '' !== $credit ) : ?>
					<div class="rrt-credit"><?php echo esc_html( $credit ); ?></div>
				<?php endif; ?>
				<div class="rrt-track" role="slider" tabindex="0" aria-label="<?php esc_attr_e( 'Audio position', 'foxfire-studio' ); ?>" aria-valuemin="0" aria-valuemax="<?php echo (int) $length; ?>" aria-valuenow="0" aria-valuetext="0:00">
					<span class="rrt-fill"></span>
				</div>
			</div>
			<span class="rrt-time"><span class="rrt-now">0:00</span> <span class="rrt-sep"><?php echo esc_html_x( 'of', 'time played of total length', 'foxfire-studio' ); ?></span> <span class="rrt-len"><?php echo esc_html( $length ? rrt_clock( $length ) : '0:00' ); ?></span></span>
		</div>
		<div class="rrt-row rrt-tools">
			<?php /* 1.26.0: each button is named by its own words, so voice control can say what is shown. */ ?>
			<button type="button" class="rrt-btn rrt-back"><?php echo esc_html( sprintf( /* translators: %d: seconds. */ __( 'Back %ds', 'foxfire-studio' ), (int) $p['skip_back'] ) ); ?></button>
			<button type="button" class="rrt-btn rrt-fwd"><?php echo esc_html( sprintf( /* translators: %d: seconds. */ __( 'Ahead %ds', 'foxfire-studio' ), (int) $p['skip_fwd'] ) ); ?></button>
			<button type="button" class="rrt-btn rrt-speed"><?php echo esc_html( sprintf( /* translators: %s: the speed, such as 1.5×. */ __( 'Speed %s', 'foxfire-studio' ), '1×' ) ); ?></button>
			<?php if ( $data['follow'] ) : ?>
				<button type="button" class="rrt-btn rrt-follow" aria-pressed="false"><?php esc_html_e( 'Read along', 'foxfire-studio' ); ?></button>
			<?php endif; ?>
			<?php if ( $cap['url'] ) : ?>
				<button type="button" class="rrt-btn rrt-transcript-btn" aria-expanded="false" aria-controls="rrt-transcript-<?php echo esc_attr( $pid ); ?>"><?php esc_html_e( 'Transcript', 'foxfire-studio' ); ?></button>
			<?php endif; ?>
			<button type="button" class="rrt-btn rrt-resume" hidden></button>
			<?php if ( ! empty( $p['download'] ) ) : ?>
				<a class="rrt-btn rrt-download" href="<?php echo esc_url( $audio['url'] ); ?>" download><?php esc_html_e( 'Download', 'foxfire-studio' ); ?></a>
			<?php endif; ?>
		</div>
		<ol class="rrt-transcript" id="rrt-transcript-<?php echo esc_attr( $pid ); ?>"<?php echo $other ? ' lang="' . esc_attr( $rlang ) . '"' : ''; ?> hidden aria-label="<?php esc_attr_e( 'Transcript', 'foxfire-studio' ); ?>"></ol>
		<span class="rrt-sr rrt-say" aria-live="polite"></span>
	</div>
	<?php
	$html = trim( ob_get_clean() );
	/* Sponsor messages (1.22.0): the thank-you line, when it is switched on. */
	if ( function_exists( 'rrt_spot_thank_html' ) ) {
		$html .= rrt_spot_thank_html( $post );
	}
	return $html;
}

/** Loads the player's styles and script, once, on pages that show it. */
function rrt_player_enqueue() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	wp_enqueue_style( 'rrt-player', RRT_URL . 'assets/css/player.css', array(), RRT_VERSION . '.' . filemtime( RRT_DIR . 'assets/css/player.css' ) );
	wp_add_inline_style( 'rrt-player', rrt_player_tokens_css( rrt_config() ) );
	/* id3.js reads chapters from the MP3's own tag (0.7.0). */
	wp_enqueue_script( 'rrt-id3', RRT_URL . 'assets/js/id3.js', array(), RRT_VERSION . '.' . filemtime( RRT_DIR . 'assets/js/id3.js' ), true );
	$deps = array( 'rrt-id3' );
	/* listen.js (0.10.0) runs first, so it hears the player's very first event. */
	if ( function_exists( 'rrt_listen_on' ) && rrt_listen_on() ) {
		wp_enqueue_script( 'rrt-listen', RRT_URL . 'assets/js/listen.js', array(), RRT_VERSION . '.' . filemtime( RRT_DIR . 'assets/js/listen.js' ), true );
		$deps[] = 'rrt-listen';
	}
	/* ga.js (1.2.0) also listens before the player starts, and only when the link is on. */
	if ( ! empty( rrt_settings()['listen']['ga'] ) ) {
		wp_enqueue_script( 'rrt-ga', RRT_URL . 'assets/js/ga.js', array(), RRT_VERSION . '.' . filemtime( RRT_DIR . 'assets/js/ga.js' ), true );
		$deps[] = 'rrt-ga';
	}
	wp_enqueue_script( 'rrt-player', RRT_URL . 'assets/js/player.js', $deps, RRT_VERSION . '.' . filemtime( RRT_DIR . 'assets/js/player.js' ), true );
}

/** The look of the player, scoped to it so the theme's own variables are never touched. */
function rrt_player_tokens_css( $cfg ) {
	$c   = $cfg['colors'];
	$out = '.rrt-player,.rrt-follow-root,.rrt-embed{';
	foreach ( array( 'bg', 'surface', 'panel', 'text', 'muted', 'faint', 'accent', 'accent2' ) as $k ) {
		$out .= '--rrt-' . rrt_css( $k ) . ':' . rrt_css( $c[ $k ] ) . ';';
	}
	$out .= '--rrt-font-read:' . rrt_css( $cfg['fonts']['reading'] ) . ';';
	$out .= '--rrt-font-ui:' . rrt_css( $cfg['fonts']['interface'] ) . ';';
	$out .= '}';
	return $out;
}

/** Enqueue early on single posts with a reading, so the stylesheet lands in the head. */
add_action(
	'wp_enqueue_scripts',
	function () {
		if ( is_singular() && rrt_player_visible() ) {
			$cfg = rrt_config();
			if ( in_array( get_post_type(), $cfg['post_types'], true ) && rrt_reading_audio( get_queried_object_id() )['url'] ) {
				rrt_player_enqueue();
			}
		}
	}
);

/** Adds the player to the top of the post when Settings say so, unless the post or its template places it by hand (1.28.0). */
add_filter(
	'the_content',
	function ( $content ) {
		if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$cfg = rrt_config();
		if ( 'top' !== $cfg['player']['position'] || ! rrt_player_visible() ) {
			return $content;
		}
		if ( ! in_array( get_post_type(), $cfg['post_types'], true ) ) {
			return $content;
		}
		if ( rrt_player_placed_by_hand( get_post() ) ) {
			return $content;
		}
		return rrt_player_placed( null, 'content' ) . $content;
	},
	5
);

/* -------------------------------------------------------------------------
 * The Reading audio box on the post edit screen.
 * ---------------------------------------------------------------------- */

add_action(
	'add_meta_boxes',
	function () {
		$cfg = rrt_config();
		foreach ( $cfg['post_types'] as $type ) {
			add_meta_box( 'rrt-reading', __( 'Reading audio', 'foxfire-studio' ), 'rrt_reading_box', $type, 'side' );
		}
	}
);

/** Prints the Reading audio box. */
function rrt_reading_box( $post ) {
	wp_nonce_field( 'rrt_reading_box', 'rrt_reading_nonce' );
	$cfg   = rrt_config();
	$audio = rrt_media_from_value( get_post_meta( $post->ID, 'rrt_audio', true ) );
	$cap   = rrt_media_from_value( get_post_meta( $post->ID, 'rrt_captions', true ) );
	$other = '';
	if ( ! $audio['url'] && ! empty( $cfg['player']['audio_key'] ) ) {
		$o = rrt_media_from_value( get_post_meta( $post->ID, $cfg['player']['audio_key'], true ) );
		if ( $o['url'] ) {
			$other = $o['url'];
		}
	}
	$fields = array(
		'rrt_audio'    => array( __( 'Reading (MP3)', 'foxfire-studio' ), $audio, 'audio', __( 'Choose the reading (MP3)', 'foxfire-studio' ), __( 'Remove the reading', 'foxfire-studio' ) ),
		'rrt_captions' => array( __( 'Captions for read along (.vtt)', 'foxfire-studio' ), $cap, '', __( 'Choose the captions (.vtt)', 'foxfire-studio' ), __( 'Remove the captions', 'foxfire-studio' ) ),
	);
	foreach ( $fields as $key => $f ) :
		?>
		<p class="rrt-box-field">
			<strong><?php echo esc_html( $f[0] ); ?></strong><br>
			<input type="hidden" id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $f[1]['id'] ? $f[1]['id'] : '' ); ?>">
			<input type="hidden" name="rrt_was[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $f[1]['id'] ? $f[1]['id'] : '' ); ?>">
			<span class="rrt-box-name" aria-live="polite" data-none="<?php esc_attr_e( 'None', 'foxfire-studio' ); ?>"><?php echo esc_html( $f[1]['url'] ? wp_basename( $f[1]['url'] ) : __( 'None', 'foxfire-studio' ) ); ?></span><br>
			<button type="button" class="button rrt-box-pick" data-for="<?php echo esc_attr( $key ); ?>" data-type="<?php echo esc_attr( $f[2] ); ?>" data-use="<?php esc_attr_e( 'Use this file', 'foxfire-studio' ); ?>" aria-label="<?php echo esc_attr( $f[3] ); ?>"><?php esc_html_e( 'Choose', 'foxfire-studio' ); ?></button>
			<button type="button" class="button-link rrt-box-clear" data-for="<?php echo esc_attr( $key ); ?>" aria-label="<?php echo esc_attr( $f[4] ); ?>"><?php esc_html_e( 'Remove', 'foxfire-studio' ); ?></button>
		</p>
		<?php
	endforeach;
	if ( $other ) {
		echo '<p class="description">' . esc_html( sprintf( /* translators: 1: custom field name, 2: file name. */ __( 'Currently playing the reading from the "%1$s" field: %2$s. Choosing one here takes its place.', 'foxfire-studio' ), $cfg['player']['audio_key'], wp_basename( $other ) ) ) . '</p>';
	}
	$credit = get_post_meta( $post->ID, 'rrt_credit', true );
	echo '<p class="rrt-box-field"><label for="rrt_credit"><strong>' . esc_html__( 'Read by', 'foxfire-studio' ) . '</strong> ' . esc_html__( '(optional)', 'foxfire-studio' ) . '</label><br><input type="text" class="widefat" id="rrt_credit" name="rrt_credit" maxlength="120" value="' . esc_attr( is_string( $credit ) ? $credit : '' ) . '" placeholder="' . esc_attr__( 'for example read by Robin', 'foxfire-studio' ) . '"></p>';
	if ( '' === ( is_string( $credit ) ? $credit : '' ) && ! empty( $cfg['player']['credit_key'] ) && '' !== rrt_reading_credit( $post->ID ) ) {
		echo '<p class="description">' . esc_html( sprintf( /* translators: 1: custom field name, 2: the credit. */ __( 'Currently showing the credit from the "%1$s" field: %2$s. Writing one here takes its place.', 'foxfire-studio' ), $cfg['player']['credit_key'], rrt_reading_credit( $post->ID ) ) ) . '</p>';
	}
	/* Kind of reading (1.11.0): its pace range and how the review treats trailing lines. */
	if ( function_exists( 'rrt_kinds' ) ) {
		$rrt_kind  = rrt_get_kind( $post->ID );
		$rrt_guess = '' === $rrt_kind ? rrt_kind_guess( $post->ID ) : '';
		$rrt_kr    = rrt_kind_ranges();
		echo '<p class="rrt-box-field"><label for="rrt_kind"><strong>' . esc_html__( 'Kind of reading', 'foxfire-studio' ) . '</strong></label><br><select class="widefat" id="rrt_kind" name="rrt_kind">';
		echo '<option value=""' . selected( '', $rrt_kind, false ) . '>' . esc_html__( 'Not chosen', 'foxfire-studio' ) . '</option>';
		foreach ( rrt_kinds() as $rrt_kk => $rrt_kl ) {
			echo '<option value="' . esc_attr( $rrt_kk ) . '"' . selected( $rrt_kk, $rrt_kind, false ) . '>' . esc_html( sprintf( /* translators: 1: kind of reading, 2: lowest pace, 3: highest pace. */ __( '%1$s (%2$d to %3$d wpm)', 'foxfire-studio' ), $rrt_kl, $rrt_kr[ $rrt_kk ][0], $rrt_kr[ $rrt_kk ][1] ) ) . '</option>';
		}
		echo '</select><input type="hidden" name="rrt_was_kind" value="' . esc_attr( $rrt_kind ) . '"></p>';
		if ( 'verse' === $rrt_guess ) {
			echo '<p class="description">' . esc_html__( 'This post has many short lines, so it may be verse.', 'foxfire-studio' ) . '</p>';
		}
	}
	/** Fires after the kind of reading (1.33.0: the reading language). */
	do_action( 'rrt_reading_box_lang', $post );
	/* The music bed (0.24.0): the show's default, none, or one of the tracks in Settings. */
	$rrt_mus = rrt_settings()['music'];
	if ( ! empty( $rrt_mus['beds'] ) ) {
		$rrt_pick = (string) get_post_meta( $post->ID, '_rrt_music', true );
		$rrt_def  = __( 'none', 'foxfire-studio' );
		foreach ( $rrt_mus['beds'] as $rrt_b ) {
			if ( (int) $rrt_b['id'] === (int) $rrt_mus['default'] ) {
				$rrt_def = get_the_title( $rrt_b['id'] );
			}
		}
		echo '<p class="rrt-box-field"><label for="rrt_music"><strong>' . esc_html__( 'Music bed', 'foxfire-studio' ) . '</strong></label><br><select class="widefat" id="rrt_music" name="rrt_music">';
		echo '<option value=""' . selected( '', $rrt_pick, false ) . '>' . esc_html( sprintf( /* translators: %s: track name. */ __( 'The show\'s default (%s)', 'foxfire-studio' ), $rrt_def ) ) . '</option>';
		echo '<option value="none"' . selected( 'none', $rrt_pick, false ) . '>' . esc_html__( 'None for this post', 'foxfire-studio' ) . '</option>';
		foreach ( $rrt_mus['beds'] as $rrt_b ) {
			echo '<option value="' . (int) $rrt_b['id'] . '"' . selected( (string) (int) $rrt_b['id'], $rrt_pick, false ) . '>' . esc_html( get_the_title( $rrt_b['id'] ) ) . '</option>';
		}
		echo '</select></p>';
	}
	/* Take off the post, and readings kept aside (1.8.0), on the reading's page under Foxfire Studio. */
	if ( function_exists( 'rrt_kept_list' ) && rrt_kept_can( $post->ID ) ) {
		$rrt_page = admin_url( 'admin.php?page=rrt-readings&post=' . (int) $post->ID . '#rrt-keep' );
		$rrt_kept = count( rrt_kept_list( $post->ID ) );
		if ( '' !== rrt_reading_audio( $post->ID )['url'] ) {
			echo '<p class="rrt-box-field"><a href="' . esc_url( $rrt_page ) . '">' . esc_html__( 'Take this reading off the post', 'foxfire-studio' ) . '</a></p>';
		}
		if ( $rrt_kept ) {
			echo '<p class="rrt-box-field">' . esc_html( sprintf( /* translators: %d: number of readings. */ _n( '%d reading kept aside.', '%d readings kept aside.', $rrt_kept, 'foxfire-studio' ), $rrt_kept ) ) . ' <a href="' . esc_url( $rrt_page ) . '">' . esc_html__( 'Put back', 'foxfire-studio' ) . '</a></p>';
		}
	}
	/** Fires at the end of the Reading audio box (1.21.0: who can hear the reading). */
	do_action( 'rrt_reading_box_after', $post );
	echo '<p class="description">' . esc_html__( 'The captions file comes from the review studio\'s Captions (.vtt) button. Chapters travel inside the MP3 itself, so they need no file here. The credit shows under the player\'s label and in the podcast feed.', 'foxfire-studio' ) . '</p>';
}

add_action(
	'admin_enqueue_scripts',
	function ( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		wp_enqueue_media();
		$js = <<<'JS'
(function () {
	document.addEventListener('click', function (e) {
		var pick = e.target.closest('.rrt-box-pick');
		var clear = e.target.closest('.rrt-box-clear');
		var btn = pick || clear;
		if (!btn) { return; }
		e.preventDefault();
		var key = btn.getAttribute('data-for');
		var input = document.getElementById(key);
		var name = btn.parentNode.querySelector('.rrt-box-name');
		if (clear) { input.value = ''; name.textContent = name.getAttribute('data-none') || 'None'; return; }
		var opts = { title: btn.getAttribute('aria-label') || btn.textContent, button: { text: btn.getAttribute('data-use') || 'Use this file' }, multiple: false };
		if (btn.getAttribute('data-type')) { opts.library = { type: btn.getAttribute('data-type') }; }
		var frame = wp.media(opts);
		frame.on('select', function () {
			var a = frame.state().get('selection').first().toJSON();
			input.value = a.id;
			name.textContent = a.filename || a.title;
		});
		frame.open();
	});
})();
JS;
		wp_add_inline_script( 'media-editor', $js );
	}
);

add_action(
	'save_post',
	function ( $post_id ) {
		if ( ! isset( $_POST['rrt_reading_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rrt_reading_nonce'] ) ), 'rrt_reading_box' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		foreach ( array( 'rrt_audio', 'rrt_captions' ) as $key ) {
			if ( ! isset( $_POST[ $key ] ) ) {
				continue;
			}
			$id = absint( $_POST[ $key ] );
			/*
			 * Unchanged in the box since the screen was opened: leave the field alone, so an
			 * edit screen left open while a reading was taken off or put back (1.8.0) does not undo it.
			 */
			if ( isset( $_POST['rrt_was'][ $key ] ) && absint( $_POST['rrt_was'][ $key ] ) === $id ) {
				continue;
			}
			/* 1.25.0: never another post's members' file, or a file in a private or closed folder. */
			if ( $id && get_post( $id ) && 'attachment' === get_post_type( $id ) && current_user_can( 'read_post', $id ) && rrt_att_choosable( $id, $post_id ) ) {
				update_post_meta( $post_id, $key, $id );
			} else {
				delete_post_meta( $post_id, $key );
			}
		}
		/* The music bed for this post (0.24.0): empty for the show's default. */
		if ( isset( $_POST['rrt_music'] ) ) {
			$m = sanitize_key( wp_unslash( $_POST['rrt_music'] ) );
			if ( 'none' === $m || ( absint( $m ) && rrt_is_audio_attachment( absint( $m ) ) ) ) {
				update_post_meta( $post_id, '_rrt_music', 'none' === $m ? 'none' : (string) absint( $m ) );
			} else {
				delete_post_meta( $post_id, '_rrt_music' );
			}
		}
		/* Kind of reading (1.11.0), only when changed in the box, so the teleprompter's choice made meanwhile stands. */
		if ( isset( $_POST['rrt_kind'] ) && function_exists( 'rrt_save_kind' ) ) {
			$rrt_k   = sanitize_key( wp_unslash( $_POST['rrt_kind'] ) );
			$rrt_was = isset( $_POST['rrt_was_kind'] ) ? sanitize_key( wp_unslash( $_POST['rrt_was_kind'] ) ) : null;
			if ( $rrt_k !== $rrt_was ) {
				rrt_save_kind( $post_id, $rrt_k );
			}
		}
		/* The "read by" credit (0.22.0). */
		if ( isset( $_POST['rrt_credit'] ) ) {
			$c = mb_substr( sanitize_text_field( wp_unslash( $_POST['rrt_credit'] ) ), 0, 120 );
			if ( '' !== $c ) {
				update_post_meta( $post_id, 'rrt_credit', $c );
			} else {
				delete_post_meta( $post_id, 'rrt_credit' );
			}
		}
	}
);

/** Lets the captions (.vtt) file be uploaded to the media library. */
add_filter(
	'upload_mimes',
	function ( $mimes ) {
		if ( current_user_can( 'upload_files' ) ) {
			$mimes['vtt'] = 'text/vtt';
		}
		return $mimes;
	}
);
