<?php
/**
 * Finishing presets (1.6.0): one Finish for menu decides the loudness, the
 * true peak limit, mono or stereo and the file type of every finished
 * reading. Settings keep the choice in sound.finish (and the Custom numbers
 * in sound.custom_lufs, sound.custom_tp and sound.custom_ch); sound.mode and
 * sound.target are kept in step with it, so older code and saved settings
 * read the same as before.
 *
 * The browser does the work (assets/js/sound.js and master.js); this file
 * only describes each preset and turns the saved choice into the spec the
 * studio is given.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every preset, in the order of the menu. Loudness (lufs) and true peak (tp)
 * are what the finished file measures, both channels counted for stereo, as
 * BS.1770 and every loudness meter count them.
 */
function rrt_finish_presets() {
	return array(
		'podcast_mono'   => array(
			'label'  => __( 'Podcast, mono', 'foxfire-studio' ),
			'help'   => __( '-19 LUFS, peaks under -1 dBTP, mono MP3 at 128 kbps. Plays as loud as a -16 LUFS stereo show, at half the file size.', 'foxfire-studio' ),
			'kind'   => 'loudness',
			'lufs'   => -19,
			'tp'     => -1,
			'ch'     => 1,
			'format' => 'mp3',
			'kbps'   => 128,
		),
		'podcast_stereo' => array(
			'label'  => __( 'Podcast, stereo', 'foxfire-studio' ),
			'help'   => __( '-16 LUFS, peaks under -1 dBTP, stereo MP3 at 128 kbps (your voice in both channels). For hosts and apps that ask for stereo.', 'foxfire-studio' ),
			'kind'   => 'loudness',
			'lufs'   => -16,
			'tp'     => -1,
			'ch'     => 2,
			'format' => 'mp3',
			'kbps'   => 128,
		),
		'streaming'      => array(
			'label'  => __( 'Spotify and YouTube', 'foxfire-studio' ),
			'help'   => __( '-14 LUFS, peaks under -1 dBTP, stereo MP3 at 192 kbps. The level those services play everything at, so they leave it alone.', 'foxfire-studio' ),
			'kind'   => 'loudness',
			'lufs'   => -14,
			'tp'     => -1,
			'ch'     => 2,
			'format' => 'mp3',
			'kbps'   => 192,
		),
		'broadcast'      => array(
			'label'  => __( 'Broadcast (EBU R128)', 'foxfire-studio' ),
			'help'   => __( '-23 LUFS, peaks under -1 dBTP, stereo WAV at 48 kHz and 24 bits, as radio and TV stations ask for. Large files: about 17 MB a minute.', 'foxfire-studio' ),
			'kind'   => 'loudness',
			'lufs'   => -23,
			'tp'     => -1,
			'ch'     => 2,
			'format' => 'wav',
			'kbps'   => 0,
		),
		'acx'            => array(
			'label'  => __( 'Audiobook (ACX)', 'foxfire-studio' ),
			'help'   => __( 'Audible\'s rules: -20 dB RMS (allowed -23 to -18), peaks under -3 dB, room tone at both ends, mono MP3 at 192 kbps.', 'foxfire-studio' ),
			'kind'   => 'acx',
			'lufs'   => null,
			'tp'     => -3,
			'ch'     => 1,
			'format' => 'mp3',
			'kbps'   => 192,
		),
		'custom'         => array(
			'label'  => __( 'Custom', 'foxfire-studio' ),
			'help'   => __( 'Your own loudness (-24 to -12 LUFS), true peak limit (-3 to -0.5 dBTP) and mono or stereo, as an MP3 at 128 kbps.', 'foxfire-studio' ),
			'kind'   => 'loudness',
			'lufs'   => null,
			'tp'     => null,
			'ch'     => null,
			'format' => 'mp3',
			'kbps'   => 128,
		),
		'off'            => array(
			'label'  => __( 'Simple leveling', 'foxfire-studio' ),
			'help'   => __( 'The plain leveling used before version 0.5.0: no loudness standard, no studio check. Mono MP3 at 128 kbps.', 'foxfire-studio' ),
			'kind'   => 'off',
			'lufs'   => null,
			'tp'     => null,
			'ch'     => 1,
			'format' => 'mp3',
			'kbps'   => 128,
		),
	);
}

/** The Custom numbers kept in range: loudness -24 to -12 LUFS, true peak -3 to -0.5 dBTP in half steps, mono or stereo. */
function rrt_finish_clean_custom( $lufs, $tp, $ch ) {
	$lufs = is_numeric( $lufs ) ? (float) $lufs : -19;
	$lufs = max( -24, min( -12, round( $lufs * 2 ) / 2 ) );
	$tp   = is_numeric( $tp ) ? (float) $tp : -1;
	$tp   = max( -3, min( -0.5, round( $tp * 2 ) / 2 ) );
	$ch   = 'stereo' === $ch ? 'stereo' : 'mono';
	return array( $lufs, $tp, $ch );
}

/**
 * The preset an older saved choice becomes (from before 1.6.0, when there
 * was a mode and a podcast loudness): podcasts at -19 become Podcast, mono;
 * any other podcast loudness becomes Custom with the same number, mono, with
 * peaks under -1 dBTP as before; audiobooks stay audiobooks; off stays off.
 *
 * @return array finish, custom_lufs, custom_tp, custom_ch.
 */
function rrt_finish_from_old( $mode, $target ) {
	$out = array(
		'finish'      => 'podcast_mono',
		'custom_lufs' => -19,
		'custom_tp'   => -1,
		'custom_ch'   => 'mono',
	);
	if ( 'audiobook' === $mode ) {
		$out['finish'] = 'acx';
	} elseif ( 'off' === $mode ) {
		$out['finish'] = 'off';
	} elseif ( is_numeric( $target ) && -19 !== (int) $target ) {
		list( $l ) = rrt_finish_clean_custom( $target, -1, 'mono' );
		$out['finish']      = 'custom';
		$out['custom_lufs'] = $l;
	}
	return $out;
}

/**
 * Settings' sound with finish filled in (from the older keys when it was
 * saved before 1.6.0) and mode and target kept in step with it.
 *
 * @param array $sound The merged sound settings.
 * @param array $saved The sound settings as saved, or an empty array.
 */
function rrt_finish_settle( $sound, $saved ) {
	$presets = rrt_finish_presets();
	if ( ! is_array( $saved ) || ! isset( $saved['finish'] ) || ! isset( $presets[ $saved['finish'] ] ) ) {
		if ( is_array( $saved ) && ( isset( $saved['mode'] ) || isset( $saved['target'] ) ) ) {
			$sound = array_merge( $sound, rrt_finish_from_old( isset( $saved['mode'] ) ? $saved['mode'] : 'podcast', isset( $saved['target'] ) ? $saved['target'] : -19 ) );
		} elseif ( ! isset( $presets[ isset( $sound['finish'] ) ? $sound['finish'] : '' ] ) ) {
			$sound['finish'] = 'podcast_mono';
		}
	}
	list( $sound['custom_lufs'], $sound['custom_tp'], $sound['custom_ch'] ) = rrt_finish_clean_custom(
		isset( $sound['custom_lufs'] ) ? $sound['custom_lufs'] : -19,
		isset( $sound['custom_tp'] ) ? $sound['custom_tp'] : -1,
		isset( $sound['custom_ch'] ) ? $sound['custom_ch'] : 'mono'
	);
	$spec = rrt_finish_spec( $sound );
	if ( 'acx' === $spec['kind'] ) {
		$sound['mode'] = 'audiobook';
	} elseif ( 'off' === $spec['kind'] ) {
		$sound['mode'] = 'off';
	} else {
		$sound['mode']   = 'podcast';
		$sound['target'] = $spec['lufs'];
	}
	return $sound;
}

/**
 * The full spec for a preset, with the Custom numbers filled in. Given the
 * sound settings, or a preset key and the sound settings for Custom.
 *
 * @param array       $sound Sound settings (finish, custom_*).
 * @param string|null $key   A preset key, or null for the saved choice.
 */
function rrt_finish_spec( $sound, $key = null ) {
	$presets = rrt_finish_presets();
	if ( null === $key ) {
		$key = isset( $sound['finish'] ) ? $sound['finish'] : 'podcast_mono';
	}
	if ( ! isset( $presets[ $key ] ) ) {
		$key = 'podcast_mono';
	}
	$p        = $presets[ $key ];
	$p['key'] = $key;
	if ( 'custom' === $key ) {
		list( $l, $t, $c ) = rrt_finish_clean_custom(
			isset( $sound['custom_lufs'] ) ? $sound['custom_lufs'] : -19,
			isset( $sound['custom_tp'] ) ? $sound['custom_tp'] : -1,
			isset( $sound['custom_ch'] ) ? $sound['custom_ch'] : 'mono'
		);
		$p['lufs'] = $l;
		$p['tp']   = $t;
		$p['ch']   = 'stereo' === $c ? 2 : 1;
	}
	$p['rate'] = 'wav' === $p['format'] ? 48000 : 44100;
	$p['bits'] = 'wav' === $p['format'] ? 24 : 0;
	$p['ext']  = $p['format'];
	return $p;
}

/** Every preset's spec, for the review's one-off Finish for menu. */
function rrt_finish_specs( $sound ) {
	$out = array();
	foreach ( array_keys( rrt_finish_presets() ) as $k ) {
		$out[] = rrt_finish_spec( $sound, $k );
	}
	return $out;
}
