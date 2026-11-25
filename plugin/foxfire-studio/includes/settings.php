<?php
/**
 * Settings: defaults, presets, the saved option, and the merged config.
 *
 * Everything that used to live in the MAKE IT YOURS block is now saved in the
 * rrt_settings option and edited in Settings, then Foxfire Studio. Developers can
 * still change the final config with the rrt_config filter.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The looks that can be applied with one click. Each preset sets colours,
 * fonts and wording; everything else is shared.
 */
function rrt_presets() {
	return array(
		'neutral'  => array(
			'label'  => __( 'Neutral (charcoal and amber)', 'foxfire-studio' ),
			'text'   => array(
				'title'   => __( 'Teleprompter', 'foxfire-studio' ),
				'welcome' => __( 'The reading room', 'foxfire-studio' ),
				'intro'   => __( 'Choose a post above, or paste its address, and the words will light up one by one as you read.', 'foxfire-studio' ),
				'choose'  => __( 'Choose a post', 'foxfire-studio' ),
				'paste'   => __( 'or paste a post URL', 'foxfire-studio' ),
				'listen'  => __( 'Listen to this post', 'foxfire-studio' ),
				'tagline' => '',
			),
			'colors' => array(
				'bg'      => '#111114',
				'surface' => '#18171e',
				'panel'   => '#1e1c26',
				'text'    => '#f4f1ea',
				'muted'   => '#c9c5d3',
				'faint'   => '#8c8997',
				'accent'  => '#e0b04f',
				'accent2' => '#7a4a8c',
				'warn'    => '#d0743c',
			),
			'fonts'  => array(
				'reading'    => "'Lora', Georgia, serif",
				'headings'   => "'Lora', Georgia, serif",
				'interface'  => "'Inter', system-ui, sans-serif",
				'google_url' => 'https://fonts.googleapis.com/css2?family=Lora:ital,wght@0,400;0,600;1,400&family=Inter:wght@400;500&display=swap',
			),
		),
		'dark'     => array(
			'label'  => __( 'Dark (void, plum and gold)', 'foxfire-studio' ),
			'text'   => array(
				'title'   => __( 'Teleprompter', 'foxfire-studio' ),
				'welcome' => __( 'The reading room', 'foxfire-studio' ),
				'intro'   => __( 'Choose a whisper above, or paste its URL, and the words will light up one by one as you read.', 'foxfire-studio' ),
				'choose'  => __( 'Choose a whisper', 'foxfire-studio' ),
				'paste'   => __( 'or paste a post URL', 'foxfire-studio' ),
				'listen'  => __( 'Listen to this whisper', 'foxfire-studio' ),
				'tagline' => '',
			),
			'colors' => array(
				'bg'      => '#0d0b10',
				'surface' => '#1a1424',
				'panel'   => '#241a2c',
				'text'    => '#f5f0e8',
				'muted'   => '#c8bfd4',
				'faint'   => '#8a819a',
				'accent'  => '#d4a843',
				'accent2' => '#8b3a5a',
				'warn'    => '#c4622d',
			),
			'fonts'  => array(
				'reading'    => '"Cormorant Garamond", serif',
				'headings'   => '"Cinzel Decorative", serif',
				'interface'  => '"Raleway", sans-serif',
				'google_url' => 'https://fonts.googleapis.com/css2?family=Cinzel+Decorative:wght@400;700&family=Cormorant+Garamond:ital,wght@0,400;0,500;1,400&family=Raleway:wght@400;500&display=swap',
			),
		),
	);
}

/** What each colour controls, for the settings screen. */
function rrt_color_labels() {
	return array(
		'bg'      => __( 'Page background', 'foxfire-studio' ),
		'surface' => __( 'Cards and tiles in the review', 'foxfire-studio' ),
		'panel'   => __( 'Control bar and pop-up panels', 'foxfire-studio' ),
		'text'    => __( 'Main reading text', 'foxfire-studio' ),
		'muted'   => __( 'Labels and softer text', 'foxfire-studio' ),
		'faint'   => __( 'Status line and hints', 'foxfire-studio' ),
		'accent'  => __( 'Highlighted word, reading line and buttons', 'foxfire-studio' ),
		'accent2' => __( 'Start of the progress line', 'foxfire-studio' ),
		'warn'    => __( 'Messages and the "faster" end of the pace timeline', 'foxfire-studio' ),
	);
}

/** Settings that do not depend on the preset. */
function rrt_base_defaults() {
	return array(
		'preset'     => 'neutral',
		'post_types' => array( 'post' ),
		/* The teleprompter's look (0.18.0): studio (night sky, fox and gold ornaments, in the colours below) or theme (no artwork, in the WordPress theme's colours). */
		'look'         => 'studio',
		/* An intro video from the media library, played when the studio opens (0.23.0); 0 for none. */
		'intro'        => 0,
		/*
		 * The music bed (0.24.0): tracks from the media library (each with an
		 * optional credit), the show's default track (0 for none), how far
		 * under the voice it sits, and whether it rises a little in long pauses.
		 */
		'music'        => array(
			'beds'    => array(),
			'default' => 0,
			'depth'   => 20,
			'swell'   => false,
		),
		'theme_colors' => array(
			'bg'      => 'auto',
			'text'    => 'auto',
			'accent'  => 'auto',
			'accent2' => 'auto',
		),
		'defaults'   => array(
			'wpm'     => 140,
			'size'    => 40,
			'cols'    => 18,
			'line'    => 30,
			'breath'  => true,
			'rec'     => false,
			'clean'   => true,
			'whisper' => true,
		),
		/* Pace range of each kind of reading (1.11.0), low and high in words a minute; see kinds.php. */
		'kinds'      => array(
			'prose'  => array( 140, 160 ),
			'story'  => array( 130, 150 ),
			'verse'  => array( 110, 135 ),
			'ritual' => array( 95, 120 ),
		),
		'whisper'    => array(
			'source' => 'auto', // auto: this site if the files are here, otherwise Hugging Face and jsDelivr.
		),
		// Broadcast sound (0.5.0): the voice chain and loudness standard used for the MP3.
		'sound'      => array(
			'finish'      => 'podcast_mono', // Finishing preset (1.6.0), from rrt_finish_presets() in finish.php.
			'custom_lufs' => -19,            // Custom: loudness of the finished file, -24 to -12 LUFS.
			'custom_tp'   => -1,             // Custom: true peak limit, -3 to -0.5 dBTP.
			'custom_ch'   => 'mono',         // Custom: mono or stereo.
			'mode'        => 'podcast',      // Kept in step with finish (podcast, audiobook or off), as before 1.6.0.
			'target'      => -19,            // Kept in step with finish: the loudness of the finished file in LUFS.
			'hp'       => true,
			/* Voice polish (1.13.0): each step off, gentle, standard or strong (standard is the sound before 1.13.0). */
			'plosives' => 'standard',
			'deess'    => 'standard',
			'breaths'  => 'standard',
			'compress' => 'standard',
			'slow'     => true,          // Slow leveling (1.13.0): evens out a level that drifts over minutes.
			'eq'       => false,         // EQ per setup (1.13.0), suggested from each device and microphone's first takes.
			'clicks'   => false,         // Lower clicks between words (1.13.0), found from Whisper's word times.
			'denoise'  => 'medium',  // Speech-model noise reduction (0.6.0): off, gentle, medium or strong.
			'engine'   => 'standard', // Which model does it (1.16.0): standard (RNNoise) or stronger (DeepFilterNet 3).
			'book'     => false,      // Keep an audiobook copy (1.20.0): Use this recording also keeps the take finished for audiobooks.
		),
		'player'     => array(
			'show'             => 'editors', // off, editors (a private preview) or everyone.
			'position'         => 'top',     // top of the post, or manual (shortcode or rrt_player() in the theme).
			'audio_key'        => '',        // another custom field that may hold the reading, such as post_audio.
			'credit_key'       => '',        // another custom field that may hold a 'read by' credit (0.22.0).
			'skip_back'        => 15,
			'skip_fwd'         => 30,
			'speeds'           => '0.75,1,1.25,1.5,1.75,2',
			'follow'           => true,
			'resume'           => true,
			'download'         => false,
			'content_selector' => '.entry-content',
			'show_name'        => '',        // The show name in MP3 tags and on lock screens (0.7.0); empty uses the site title.
		),
		'stats'      => array(
			'server'              => true,   // Keep takes, recordings and taught words on this site (0.9.0); off keeps them in each browser.
			'delete_on_uninstall' => false,  // Remove all stats when the plugin is deleted.
			'tidy_on_delete'      => true,   // A post deleted for good takes its readings, captions and takes with it (1.36.2).
		),
		// Listener analytics (0.10.0): how visitors listen to the public player, measured on this site only.
		'listen'     => array(
			'on'            => false,     // Off on new installs from 0.10.1; sites that had 0.10.0 keep it on (rrt_listen_default_migrate).
			'exclude'       => 'editors', // editors (anyone who can edit posts), logged_in, or nobody.
			'dnt'           => true,      // Respect Do Not Track and Global Privacy Control.
			'src'           => 'src',     // Short campaign tag on links, as in ?src=instagram.
			'events_days'   => 30,        // Raw events.
			'sessions_days' => 395,       // One row per page view; about 13 months.
			'proxies'       => '',        // Trusted proxies (0.10.1): addresses or CIDR ranges, one per line. Empty: trust none.
			'proxy_header'  => 'x-forwarded-for', // Which header carries the visitor's address from those proxies.
			'returning'        => false, // Returning listeners (1.2.0): a yes or no from the player's own browser storage.
			'returning_months' => 12,    // How long a browser remembers a real listen: 1, 3, 6 or 12 months.
			'ga'               => false, // Also send play, halfway and finished events to the site's Google Analytics (1.2.0).
		),
	);
}

/**
 * The show name for MP3 tags and lock screens (0.7.0): the setting, or the
 * site title, or the site's domain, so it is never blank.
 *
 * @param array|null $cfg Settings, or null to read them.
 * @return string
 */
function rrt_show_name( $cfg = null ) {
	$cfg  = $cfg ? $cfg : rrt_settings();
	$name = isset( $cfg['player']['show_name'] ) ? trim( (string) $cfg['player']['show_name'] ) : '';
	if ( '' === $name ) {
		$name = trim( wp_strip_all_tags( get_bloginfo( 'name' ) ) );
	}
	if ( '' === $name ) {
		$name = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}
	return $name;
}

/** The full default settings for a preset. */
function rrt_defaults_for( $preset ) {
	$presets = rrt_presets();
	if ( ! isset( $presets[ $preset ] ) ) {
		$preset = 'neutral';
	}
	$p                = $presets[ $preset ];
	$base             = rrt_base_defaults();
	$base['preset']   = $preset;
	$base['text']     = $p['text'];
	$base['colors']   = $p['colors'];
	$base['fonts']    = $p['fonts'];
	return $base;
}

/**
 * Listener analytics became opt-in for new installs in 0.10.1. Sites that ran
 * an earlier version had it on by default, so on their first load of 0.10.1
 * the old default is written into their saved settings and nothing changes
 * for them. A choice already saved, on or off, is never touched. Runs once,
 * early on every load and at the start of installation, before anything else
 * creates options, so it sees the site as it was.
 */
function rrt_listen_default_migrate() {
	if ( get_option( 'rrt_listen_default_v' ) ) {
		return;
	}
	$saved    = get_option( 'rrt_settings', null );
	$existing = is_array( $saved ) || false !== get_option( 'rrt_db_version', false );
	if ( $existing ) {
		$s = is_array( $saved ) ? $saved : array();
		if ( ! isset( $s['listen'] ) || ! is_array( $s['listen'] ) ) {
			$s['listen'] = array();
		}
		if ( ! array_key_exists( 'on', $s['listen'] ) ) {
			$s['listen']['on'] = true;
			update_option( 'rrt_settings', $s );
		}
	}
	update_option( 'rrt_listen_default_v', 1, true );
}
add_action( 'plugins_loaded', 'rrt_listen_default_migrate', 1 );

/** Saved settings layered over the defaults for their preset. */
function rrt_settings() {
	$saved = get_option( 'rrt_settings', array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	$preset = isset( $saved['preset'] ) ? $saved['preset'] : 'neutral';
	$out    = rrt_merge( rrt_defaults_for( 'custom' === $preset ? 'neutral' : $preset ), $saved );
	/* Finishing presets (1.6.0): a choice saved before them is read as the preset it becomes. */
	$out['sound'] = rrt_finish_settle( $out['sound'], isset( $saved['sound'] ) ? $saved['sound'] : array() );
	/* Voice polish (1.13.0): a step saved as a switch reads as Standard or Off. */
	if ( function_exists( 'rrt_polish_settle' ) ) {
		$out['sound'] = rrt_polish_settle( $out['sound'] );
	}
	return $out;
}

/** Recursive merge where saved values win and lists are replaced whole. */
function rrt_merge( $base, $over ) {
	foreach ( $over as $k => $v ) {
		if ( is_array( $v ) && isset( $base[ $k ] ) && is_array( $base[ $k ] ) && array_keys( $v ) !== range( 0, count( $v ) - 1 ) ) {
			$base[ $k ] = rrt_merge( $base[ $k ], $v );
		} else {
			$base[ $k ] = $v;
		}
	}
	return $base;
}

/**
 * The config everything else reads. Filterable, as in earlier versions.
 */
function rrt_config() {
	$cfg = rrt_settings();
	/* The MP3 encoder: this site's copy when it is here, otherwise jsDelivr. Its fingerprint is checked in the browser either way. */
	$cfg['mp3_cdn'] = rrt_mp3_cdn();
	$local          = rrt_local_mp3_url();
	$cfg['mp3_url'] = $local ? $local : $cfg['mp3_cdn'];
	/* The noise model: this site's copy when it is here, otherwise jsDelivr. Its fingerprint is checked in the browser either way. */
	$cfg['rnnoise_cdn'] = rrt_rnnoise_cdn();
	$local              = rrt_local_rnnoise_url();
	$cfg['rnnoise_url'] = $local ? $local : $cfg['rnnoise_cdn'];
	/* The fonts: this site's copy when it matches the font link, otherwise '' and Google Fonts is used. */
	$cfg['fonts_local'] = rrt_local_fonts_url( $cfg );
	return apply_filters( 'rrt_config', $cfg );
}

/** Keeps a value safe to print inside the style block. */
function rrt_css( $value ) {
	return preg_replace( '/[<>{};\\\\]/', '', (string) $value );
}

/** The :root tokens for the current look. */
function rrt_tokens_css( $cfg ) {
	$out = ':root {';
	foreach ( $cfg['colors'] as $k => $v ) {
		$out .= '--' . rrt_css( $k ) . ':' . rrt_css( $v ) . ';';
	}
	$out .= '--font-read:' . rrt_css( $cfg['fonts']['reading'] ) . ';';
	$out .= '--font-head:' . rrt_css( $cfg['fonts']['headings'] ) . ';';
	$out .= '--font-ui:' . rrt_css( $cfg['fonts']['interface'] ) . ';';
	$out .= '--line:color-mix(in srgb, var(--muted) 16%, transparent);';
	/* Words ahead are held at 3 to 1 or more on the page background (1.27.0). */
	$out .= '--ahead:color-mix(in srgb, var(--text) ' . (int) ( function_exists( 'rrt_a11y_ahead_pct' ) ? rrt_a11y_ahead_pct( $cfg['colors'] ) : 42 ) . '%, transparent);';
	$out .= '--size:' . (int) $cfg['defaults']['size'] . 'px;';
	$out .= '--lineY:' . (int) $cfg['defaults']['line'] . '%;';
	$out .= '--cols:' . (int) $cfg['defaults']['cols'] . ';';
	$out .= '}';
	return $out;
}

/**
 * Cleans everything that comes in from the settings form.
 *
 * @param array $in Raw form values.
 * @return array
 */
function rrt_sanitize_settings( $in ) {
	if ( ! is_array( $in ) ) {
		return rrt_settings();
	}

	// "Apply this preset" replaces the look with the chosen preset.
	$presets = rrt_presets();
	if ( ! empty( $in['apply_preset'] ) && isset( $presets[ $in['apply_preset'] ] ) ) {
		$keep            = rrt_settings();
		$fresh           = rrt_defaults_for( $in['apply_preset'] );
		$fresh['post_types'] = $keep['post_types'];
		$fresh['defaults']   = $keep['defaults'];
		$fresh['kinds']      = isset( $keep['kinds'] ) ? $keep['kinds'] : $fresh['kinds'];
		$fresh['whisper']    = $keep['whisper'];
		$fresh['sound']      = $keep['sound'];
		$fresh['player']     = $keep['player'];
		$fresh['stats']      = $keep['stats'];
		$fresh['listen']     = $keep['listen'];
		$fresh['look']         = $keep['look'];
		$fresh['theme_colors'] = $keep['theme_colors'];
		$fresh['intro']        = isset( $keep['intro'] ) ? $keep['intro'] : 0;
		$fresh['music']        = $keep['music'];
		return $fresh;
	}

	$cur = rrt_settings();
	$out = $cur;

	$out['preset'] = 'custom';
	if ( isset( $in['preset'] ) && ( isset( $presets[ $in['preset'] ] ) || 'custom' === $in['preset'] ) ) {
		$out['preset'] = $in['preset'];
	}

	foreach ( array_keys( $cur['text'] ) as $k ) {
		if ( isset( $in['text'][ $k ] ) ) {
			$out['text'][ $k ] = sanitize_text_field( wp_unslash( $in['text'][ $k ] ) );
		}
	}
	if ( isset( $in['look'] ) && in_array( $in['look'], array( 'studio', 'theme' ), true ) ) {
		$out['look'] = $in['look'];
	}
	if ( isset( $in['intro'] ) ) {
		$rrt_iv       = absint( $in['intro'] );
		$out['intro'] = ( $rrt_iv && 'attachment' === get_post_type( $rrt_iv ) && 0 === strpos( (string) get_post_mime_type( $rrt_iv ), 'video/' ) ) ? $rrt_iv : 0;
	}
	if ( isset( $in['music'] ) && is_array( $in['music'] ) ) {
		$out['music'] = rrt_sanitize_music( $in['music'] );
	}
	if ( isset( $in['theme_colors'] ) && is_array( $in['theme_colors'] ) ) {
		foreach ( array_keys( rrt_theme_roles() ) as $k ) {
			if ( isset( $in['theme_colors'][ $k ] ) ) {
				$v                          = sanitize_key( $in['theme_colors'][ $k ] );
				$out['theme_colors'][ $k ] = '' === $v ? 'auto' : $v;
			}
		}
	}
	foreach ( array_keys( rrt_color_labels() ) as $k ) {
		if ( isset( $in['colors'][ $k ] ) ) {
			$c = sanitize_hex_color( trim( $in['colors'][ $k ] ) );
			if ( $c ) {
				$out['colors'][ $k ] = $c;
			}
		}
	}
	/* Make it readable (1.26.0): only the colours that fall short are moved. */
	if ( ! empty( $in['a11y_fix'] ) && function_exists( 'rrt_a11y_fix' ) ) {
		$out['colors'] = rrt_a11y_fix( $out['colors'] );
	}
	foreach ( array( 'reading', 'headings', 'interface' ) as $k ) {
		if ( isset( $in['fonts'][ $k ] ) ) {
			$out['fonts'][ $k ] = rrt_css( sanitize_text_field( wp_unslash( $in['fonts'][ $k ] ) ) );
		}
	}
	if ( isset( $in['fonts']['google_url'] ) ) {
		$u = esc_url_raw( trim( wp_unslash( $in['fonts']['google_url'] ) ), array( 'https' ) );
		$out['fonts']['google_url'] = ( '' === $u || 0 === strpos( $u, 'https://fonts.googleapis.com/' ) ) ? $u : $cur['fonts']['google_url'];
	}

	$types = array();
	if ( isset( $in['post_types'] ) && is_array( $in['post_types'] ) ) {
		foreach ( $in['post_types'] as $t ) {
			$t = sanitize_key( $t );
			if ( post_type_exists( $t ) ) {
				$types[] = $t;
			}
		}
	}
	$out['post_types'] = $types ? $types : array( 'post' );

	$ranges = array(
		'wpm'  => array( 80, 220 ),
		'size' => array( 24, 72 ),
		'cols' => array( 10, 30 ),
		'line' => array( 18, 50 ),
	);
	foreach ( $ranges as $k => $r ) {
		if ( isset( $in['defaults'][ $k ] ) ) {
			$out['defaults'][ $k ] = max( $r[0], min( $r[1], (int) $in['defaults'][ $k ] ) );
		}
	}
	foreach ( array( 'breath', 'rec', 'clean', 'whisper' ) as $k ) {
		$out['defaults'][ $k ] = ! empty( $in['defaults'][ $k ] );
	}
	/* Pace by kind of reading (1.11.0). */
	if ( isset( $in['kinds'] ) && is_array( $in['kinds'] ) && function_exists( 'rrt_clean_kind_ranges' ) ) {
		$out['kinds'] = rrt_clean_kind_ranges( $in['kinds'] );
	}

	if ( isset( $in['whisper']['source'] ) && in_array( $in['whisper']['source'], array( 'auto', 'remote' ), true ) ) {
		$out['whisper']['source'] = $in['whisper']['source'];
	}

	if ( isset( $in['sound'] ) && is_array( $in['sound'] ) ) {
		$so = $in['sound'];
		/* Finishing presets (1.6.0); mode and target follow from the choice. */
		if ( isset( $so['finish'] ) && array_key_exists( (string) $so['finish'], rrt_finish_presets() ) ) {
			$out['sound']['finish'] = (string) $so['finish'];
		}
		if ( isset( $so['custom_lufs'] ) || isset( $so['custom_tp'] ) || isset( $so['custom_ch'] ) ) {
			list( $out['sound']['custom_lufs'], $out['sound']['custom_tp'], $out['sound']['custom_ch'] ) = rrt_finish_clean_custom(
				isset( $so['custom_lufs'] ) ? wp_unslash( $so['custom_lufs'] ) : $out['sound']['custom_lufs'],
				isset( $so['custom_tp'] ) ? wp_unslash( $so['custom_tp'] ) : $out['sound']['custom_tp'],
				isset( $so['custom_ch'] ) ? wp_unslash( $so['custom_ch'] ) : $out['sound']['custom_ch']
			);
		}
		foreach ( array( 'hp', 'slow', 'eq', 'clicks', 'book' ) as $k ) {
			$out['sound'][ $k ] = ! empty( $so[ $k ] );
		}
		/* Voice polish (1.13.0): each step's strength. */
		foreach ( rrt_polish_steps() as $k ) {
			$out['sound'][ $k ] = rrt_polish_level( isset( $so[ $k ] ) ? sanitize_key( wp_unslash( $so[ $k ] ) ) : 'off' );
		}
		if ( isset( $so['denoise'] ) && in_array( $so['denoise'], array( 'off', 'gentle', 'medium', 'strong' ), true ) ) {
			$out['sound']['denoise'] = $so['denoise'];
		}
		if ( isset( $so['engine'] ) && in_array( $so['engine'], rrt_stronger_engines(), true ) ) {
			$out['sound']['engine'] = $so['engine'];
		}
		$out['sound'] = rrt_finish_settle( $out['sound'], $out['sound'] );
	}

	if ( isset( $in['player'] ) && is_array( $in['player'] ) ) {
		$pl = $in['player'];
		if ( isset( $pl['show'] ) && in_array( $pl['show'], array( 'off', 'editors', 'everyone' ), true ) ) {
			$out['player']['show'] = $pl['show'];
		}
		if ( isset( $pl['position'] ) && in_array( $pl['position'], array( 'top', 'manual' ), true ) ) {
			$out['player']['position'] = $pl['position'];
		}
		if ( isset( $pl['audio_key'] ) ) {
			$out['player']['audio_key'] = preg_replace( '/[^A-Za-z0-9_\-]/', '', wp_unslash( $pl['audio_key'] ) );
		}
		if ( isset( $pl['credit_key'] ) ) {
			$out['player']['credit_key'] = preg_replace( '/[^A-Za-z0-9_\-]/', '', wp_unslash( $pl['credit_key'] ) );
		}
		foreach ( array( 'skip_back', 'skip_fwd' ) as $k ) {
			if ( isset( $pl[ $k ] ) ) {
				$out['player'][ $k ] = max( 5, min( 120, (int) $pl[ $k ] ) );
			}
		}
		if ( isset( $pl['speeds'] ) ) {
			$sp = array();
			foreach ( explode( ',', (string) $pl['speeds'] ) as $x ) {
				$x = (float) trim( $x );
				if ( $x >= 0.5 && $x <= 3 ) {
					$sp[] = rtrim( rtrim( number_format( $x, 2, '.', '' ), '0' ), '.' );
				}
			}
			$sp = array_values( array_unique( $sp ) );
			if ( ! in_array( '1', $sp, true ) ) {
				$sp[] = '1';
			}
			usort( $sp, function ( $a, $b ) { return (float) $a <=> (float) $b; } );
			$out['player']['speeds'] = implode( ',', $sp );
		}
		foreach ( array( 'follow', 'resume', 'download' ) as $k ) {
			$out['player'][ $k ] = ! empty( $pl[ $k ] );
		}
		if ( isset( $pl['content_selector'] ) ) {
			$sel = trim( preg_replace( '/[^A-Za-z0-9_\-\.#\s>,\[\]="\':()]/', '', wp_unslash( $pl['content_selector'] ) ) );
			$out['player']['content_selector'] = '' === $sel ? '.entry-content' : $sel;
		}
		if ( isset( $pl['show_name'] ) ) {
			$out['player']['show_name'] = mb_substr( sanitize_text_field( wp_unslash( $pl['show_name'] ) ), 0, 120 );
		}
		/* Reader stats (0.9.0). The form sends stats[present], so a save from elsewhere never switches them off. */
		if ( isset( $in['stats'] ) && is_array( $in['stats'] ) && isset( $in['stats']['present'] ) ) {
			$out['stats']['server']              = ! empty( $in['stats']['server'] );
			$out['stats']['delete_on_uninstall'] = ! empty( $in['stats']['delete_on_uninstall'] );
			$out['stats']['tidy_on_delete']      = ! empty( $in['stats']['tidy_on_delete'] );
		}
		/* Listener analytics (0.10.0), the same way. */
		if ( isset( $in['listen'] ) && is_array( $in['listen'] ) && isset( $in['listen']['present'] ) ) {
			$li = $in['listen'];
			$out['listen']['on']  = ! empty( $li['on'] );
			$out['listen']['dnt'] = ! empty( $li['dnt'] );
			if ( isset( $li['exclude'] ) && in_array( $li['exclude'], array( 'editors', 'logged_in', 'nobody' ), true ) ) {
				$out['listen']['exclude'] = $li['exclude'];
			}
			if ( isset( $li['src'] ) ) {
				$src = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $li['src'] ) );
				$out['listen']['src'] = '' === $src ? 'src' : substr( $src, 0, 20 );
			}
			if ( isset( $li['events_days'] ) ) {
				$out['listen']['events_days'] = max( 1, min( 365, absint( $li['events_days'] ) ) );
			}
			if ( isset( $li['sessions_days'] ) ) {
				$out['listen']['sessions_days'] = max( 30, min( 1095, absint( $li['sessions_days'] ) ) );
			}
			if ( isset( $li['proxies'] ) && function_exists( 'rrt_proxy_ranges' ) ) {
				/* Keep only valid addresses and ranges, one per line, at most 100. */
				$keep = array();
				foreach ( preg_split( '/[\s,]+/', (string) $li['proxies'], -1, PREG_SPLIT_NO_EMPTY ) as $item ) {
					if ( rrt_proxy_ranges( array( $item ) ) ) {
						$keep[] = $item;
					}
				}
				$out['listen']['proxies'] = implode( "\n", array_slice( array_unique( $keep ), 0, 100 ) );
			}
			if ( isset( $li['proxy_header'] ) && function_exists( 'rrt_proxy_headers' ) && array_key_exists( $li['proxy_header'], rrt_proxy_headers() ) ) {
				$out['listen']['proxy_header'] = $li['proxy_header'];
			}
			$out['listen']['returning'] = ! empty( $li['returning'] );
			$out['listen']['ga']        = ! empty( $li['ga'] );
			if ( isset( $li['returning_months'] ) && in_array( (int) $li['returning_months'], array( 1, 3, 6, 12 ), true ) ) {
				$out['listen']['returning_months'] = (int) $li['returning_months'];
			}
		}
	}

	return $out;
}

/**
 * Cleans the music bed settings (0.24.0).
 *
 * @param array $in Raw form values: beds (list of id and credit), default, depth, swell.
 * @return array
 */
function rrt_sanitize_music( $in ) {
	$beds = array();
	$seen = array();
	if ( isset( $in['beds'] ) && is_array( $in['beds'] ) ) {
		foreach ( $in['beds'] as $b ) {
			$id = is_array( $b ) && isset( $b['id'] ) ? absint( $b['id'] ) : 0;
			if ( ! $id || isset( $seen[ $id ] ) || ! rrt_is_audio_attachment( $id ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$credit      = isset( $b['credit'] ) ? mb_substr( sanitize_text_field( wp_unslash( $b['credit'] ) ), 0, 200 ) : '';
			$beds[]      = array(
				'id'     => $id,
				'credit' => $credit,
			);
			if ( count( $beds ) >= 20 ) {
				break;
			}
		}
	}
	$default = isset( $in['default'] ) ? absint( $in['default'] ) : 0;
	$depth   = isset( $in['depth'] ) ? (int) $in['depth'] : 20;
	return array(
		'beds'    => $beds,
		'default' => isset( $seen[ $default ] ) ? $default : 0,
		'depth'   => in_array( $depth, array( 16, 20, 24 ), true ) ? $depth : 20,
		'swell'   => ! empty( $in['swell'] ),
	);
}

/** Whether an id is an audio file in the media library. */
function rrt_is_audio_attachment( $id ) {
	return $id && 'attachment' === get_post_type( $id ) && 0 === strpos( (string) get_post_mime_type( $id ), 'audio/' );
}

/**
 * The music bed a post uses (0.24.0): its own choice in the Reading audio
 * box, else the show's default. Returns null for none, or
 * { id, url, title, credit, depth, swell, own }.
 *
 * @param int $post_id Post.
 * @return array|null
 */
function rrt_music_for( $post_id ) {
	$m    = rrt_settings()['music'];
	$pick = $post_id ? (string) get_post_meta( $post_id, '_rrt_music', true ) : '';
	if ( 'none' === $pick ) {
		return null;
	}
	$id  = '' !== $pick ? absint( $pick ) : (int) $m['default'];
	$own = '' !== $pick;
	foreach ( $m['beds'] as $b ) {
		if ( (int) $b['id'] !== $id || ! rrt_is_audio_attachment( $id ) ) {
			continue;
		}
		$url = (string) wp_get_attachment_url( $id );
		if ( '' === $url ) {
			return null;
		}
		return array(
			'id'     => $id,
			'url'    => $url,
			'title'  => html_entity_decode( wp_strip_all_tags( get_the_title( $id ) ), ENT_QUOTES, 'UTF-8' ),
			'credit' => (string) $b['credit'],
			'depth'  => (int) $m['depth'],
			'swell'  => (bool) $m['swell'],
			'own'    => $own,
		);
	}
	return null;
}

/**
 * The credit of the music under a post's current reading (0.24.0), when
 * "Use this recording" put a reading with music on it and that reading is
 * still the one in place. Empty otherwise.
 *
 * @param int $post_id Post.
 * @return string
 */
function rrt_music_credit_used( $post_id ) {
	$used = get_post_meta( $post_id, '_rrt_music_used', true );
	if ( ! is_array( $used ) || empty( $used['bed'] ) || empty( $used['audio'] ) ) {
		return '';
	}
	if ( (int) get_post_meta( $post_id, 'rrt_audio', true ) !== (int) $used['audio'] ) {
		return '';
	}
	foreach ( rrt_settings()['music']['beds'] as $b ) {
		if ( (int) $b['id'] === (int) $used['bed'] ) {
			return (string) $b['credit'];
		}
	}
	return isset( $used['credit'] ) ? (string) $used['credit'] : '';
}
