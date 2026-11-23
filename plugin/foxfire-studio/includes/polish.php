<?php
/**
 * Voice polish (1.13.0): a strength for each Broadcast sound step, slow
 * leveling, lowering clicks between words, and an EQ for each recording
 * setup (the device and its microphone).
 *
 * The steps themselves run in the reader's browser (assets/js/sound.js).
 * This file keeps the settings in shape and, for the EQ, keeps each setup's
 * band measurements: six numbers per take, for at most five takes, saved as
 * the reader's own user meta, so a phone take reviewed on the computer gets
 * the phone's EQ. No audio and nothing about visitors is kept.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The user meta key for the setups' band measurements. */
define( 'RRT_EQ_META', '_rrt_eq' );
/** Takes kept for each setup: the EQ is suggested from the first ones. */
define( 'RRT_EQ_TAKES', 5 );
/** Setups kept for each reader. */
define( 'RRT_EQ_SETUPS', 12 );
/** Bands measured in each take (250 Hz to 8 kHz, one an octave). */
define( 'RRT_EQ_BANDS', 6 );

/** The steps with a strength. */
function rrt_polish_steps() {
	return array( 'plosives', 'deess', 'breaths', 'compress' );
}

/**
 * The strengths, in menu order, with their labels.
 *
 * @return array Key => label.
 */
function rrt_polish_levels() {
	return array(
		'off'      => __( 'Off', 'foxfire-studio' ),
		'gentle'   => __( 'Gentle', 'foxfire-studio' ),
		'standard' => __( 'Standard', 'foxfire-studio' ),
		'strong'   => __( 'Strong', 'foxfire-studio' ),
	);
}

/**
 * One step's strength from a saved value: before 1.13.0 each step was a
 * switch, so on reads as Standard (the same sound) and off as Off.
 *
 * @param mixed $v Saved value.
 * @return string off, gentle, standard or strong.
 */
function rrt_polish_level( $v ) {
	if ( is_string( $v ) && array_key_exists( $v, rrt_polish_levels() ) ) {
		return $v;
	}
	if ( false === $v || 0 === $v || '0' === $v || '' === $v || null === $v ) {
		return 'off';
	}
	return 'standard';
}

/**
 * The sound settings with every polish value in shape.
 *
 * @param array $sound Sound settings.
 * @return array
 */
function rrt_polish_settle( $sound ) {
	foreach ( rrt_polish_steps() as $k ) {
		$sound[ $k ] = rrt_polish_level( array_key_exists( $k, $sound ) ? $sound[ $k ] : 'standard' );
	}
	$sound['slow']   = array_key_exists( 'slow', $sound ) ? ! empty( $sound['slow'] ) : true;
	$sound['eq']     = ! empty( $sound['eq'] );
	$sound['clicks'] = ! empty( $sound['clicks'] );
	return $sound;
}

/** Who may keep setups: anyone who may record (the same people as Send to my computer). */
function rrt_eq_can() {
	return is_user_logged_in() && current_user_can( 'edit_posts' );
}

/**
 * A setup's key: the device class and the microphone's name, as the studio
 * makes them (polish.js). Letters, digits and a few marks, 120 at most.
 *
 * @param mixed $key The key.
 * @return string Empty when not usable.
 */
function rrt_eq_clean_key( $key ) {
	$key = strtolower( (string) $key );
	$key = preg_replace( '/[^a-z0-9 _.|()\-]/', '', $key );
	return substr( trim( $key ), 0, 120 );
}

/**
 * A reader's setups, cleaned: key => array( label, takes => list of
 * array( id, at, bands ) ).
 *
 * @param int $user_id User.
 * @return array
 */
function rrt_eq_get( $user_id ) {
	$all = get_user_meta( (int) $user_id, RRT_EQ_META, true );
	if ( ! is_array( $all ) ) {
		return array();
	}
	$out = array();
	foreach ( $all as $key => $s ) {
		$key = rrt_eq_clean_key( $key );
		if ( '' === $key || ! is_array( $s ) || empty( $s['takes'] ) || ! is_array( $s['takes'] ) ) {
			continue;
		}
		$takes = array();
		foreach ( $s['takes'] as $t ) {
			$b = isset( $t['bands'] ) ? rrt_eq_clean_bands( $t['bands'] ) : null;
			if ( null !== $b ) {
				$takes[] = array(
					'id'    => isset( $t['id'] ) ? substr( sanitize_key( (string) $t['id'] ), 0, 40 ) : '',
					'at'    => isset( $t['at'] ) ? (int) $t['at'] : 0,
					'bands' => $b,
				);
			}
		}
		if ( $takes ) {
			$out[ $key ] = array(
				'label' => isset( $s['label'] ) ? mb_substr( sanitize_text_field( (string) $s['label'] ), 0, 120 ) : $key,
				'takes' => array_slice( $takes, 0, RRT_EQ_TAKES ),
			);
		}
	}
	return $out;
}

/**
 * Band levels from the page: six numbers in dB (-200 to 20), or null for a
 * band out of reach. Null when the list is not usable.
 *
 * @param mixed $bands List.
 * @return array|null
 */
function rrt_eq_clean_bands( $bands ) {
	if ( is_string( $bands ) ) {
		$bands = json_decode( $bands, true );
	}
	if ( ! is_array( $bands ) || RRT_EQ_BANDS !== count( $bands ) ) {
		return null;
	}
	$out  = array();
	$real = 0;
	foreach ( array_values( $bands ) as $v ) {
		if ( null === $v || '' === $v ) {
			$out[] = null;
			continue;
		}
		if ( ! is_numeric( $v ) ) {
			return null;
		}
		$out[] = round( max( -200, min( 20, (float) $v ) ), 1 );
		++$real;
	}
	return $real >= 3 ? $out : null;
}

/**
 * Adds one take's bands to a setup. A take already counted (the same id)
 * is not counted twice, and a setup with five takes keeps them as they are.
 *
 * @param int    $user_id User.
 * @param string $key     Setup key.
 * @param string $label   Setup's name, for people.
 * @param array  $bands   Cleaned band levels.
 * @param string $take    Take id.
 * @return array The reader's setups afterwards.
 */
function rrt_eq_add( $user_id, $key, $label, $bands, $take ) {
	$all = rrt_eq_get( $user_id );
	if ( ! isset( $all[ $key ] ) ) {
		if ( count( $all ) >= RRT_EQ_SETUPS ) {
			/* The setup heard from longest ago makes room. */
			$oldest = null;
			$when   = PHP_INT_MAX;
			foreach ( $all as $k => $s ) {
				$last = 0;
				foreach ( $s['takes'] as $t ) {
					$last = max( $last, (int) $t['at'] );
				}
				if ( $last < $when ) {
					$when   = $last;
					$oldest = $k;
				}
			}
			unset( $all[ $oldest ] );
		}
		$all[ $key ] = array(
			'label' => $label,
			'takes' => array(),
		);
	}
	$take = substr( sanitize_key( (string) $take ), 0, 40 );
	foreach ( $all[ $key ]['takes'] as $t ) {
		if ( '' !== $take && $t['id'] === $take ) {
			return $all;
		}
	}
	if ( count( $all[ $key ]['takes'] ) < RRT_EQ_TAKES ) {
		$all[ $key ]['takes'][] = array(
			'id'    => $take,
			'at'    => time(),
			'bands' => $bands,
		);
	}
	if ( '' !== $label ) {
		$all[ $key ]['label'] = $label;
	}
	update_user_meta( (int) $user_id, RRT_EQ_META, $all );
	return $all;
}

/** What the teleprompter needs: where to save, and the reader's setups. */
function rrt_eq_boot() {
	if ( ! rrt_eq_can() ) {
		return null;
	}
	return array(
		'rest'   => esc_url_raw( rest_url( 'rrt/v1/' ) ),
		'nonce'  => wp_create_nonce( 'wp_rest' ),
		'setups' => (object) rrt_eq_get( get_current_user_id() ),
		'takes'  => RRT_EQ_TAKES,
	);
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'rrt/v1',
			'/eq',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => 'rrt_eq_can',
					'callback'            => function () {
						return rest_ensure_response( array( 'setups' => (object) rrt_eq_get( get_current_user_id() ) ) );
					},
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => 'rrt_eq_can',
					'callback'            => function ( $req ) {
						$key   = rrt_eq_clean_key( $req['setup'] );
						$bands = rrt_eq_clean_bands( $req['bands'] );
						if ( '' === $key || null === $bands ) {
							return new WP_Error( 'rrt_eq', __( 'That measurement could not be saved.', 'foxfire-studio' ), array( 'status' => 400 ) );
						}
						$label = mb_substr( sanitize_text_field( (string) $req['label'] ), 0, 120 );
						$all   = rrt_eq_add( get_current_user_id(), $key, $label, $bands, (string) $req['take'] );
						return rest_ensure_response( array( 'setups' => (object) $all ) );
					},
				),
				array(
					'methods'             => 'DELETE',
					'permission_callback' => 'rrt_eq_can',
					'callback'            => function ( $req ) {
						$key = rrt_eq_clean_key( $req['setup'] );
						$all = rrt_eq_get( get_current_user_id() );
						unset( $all[ $key ] );
						if ( $all ) {
							update_user_meta( get_current_user_id(), RRT_EQ_META, $all );
						} else {
							delete_user_meta( get_current_user_id(), RRT_EQ_META );
						}
						return rest_ensure_response( array( 'setups' => (object) $all ) );
					},
				),
			)
		);
	}
);
