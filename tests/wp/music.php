<?php
/**
 * Gives the test site a music bed (0.25.2), for take.py: a made-up 10 second
 * WAV in the Media Library, set as the show's default bed. With "off" as the
 * first argument the bed is taken away again.
 *
 * Usage: wp eval-file tests/wp/music.php [off]
 */

$rrt_off  = isset( $args[0] ) && 'off' === $args[0];
$rrt_s    = get_option( 'rrt_settings', array() );
$rrt_s    = is_array( $rrt_s ) ? $rrt_s : array();
$rrt_find = get_posts(
	array(
		'post_type'   => 'attachment',
		'post_status' => 'inherit',
		'title'       => 'Test music bed',
		'numberposts' => 1,
		'fields'      => 'ids',
	)
);
$rrt_id   = $rrt_find ? (int) $rrt_find[0] : 0;

if ( $rrt_off ) {
	unset( $rrt_s['music'] );
	update_option( 'rrt_settings', $rrt_s );
	echo "Music bed off.\n";
	return;
}

if ( ! $rrt_id ) {
	$rrt_rate = 44100;
	$rrt_n    = $rrt_rate * 10;
	$rrt_pcm  = '';
	for ( $i = 0; $i < $rrt_n; $i++ ) {
		$t        = $i / $rrt_rate;
		$v        = 0.2 * sin( 2 * M_PI * 220 * $t ) + 0.15 * sin( 2 * M_PI * 330 * $t ) * ( 0.5 + 0.5 * sin( 2 * M_PI * 0.25 * $t ) );
		$rrt_pcm .= pack( 'v', (int) round( $v * 32767 ) & 0xFFFF );
	}
	$rrt_wav = 'RIFF' . pack( 'V', 36 + strlen( $rrt_pcm ) ) . 'WAVEfmt ' . pack( 'VvvVVvv', 16, 1, 1, $rrt_rate, $rrt_rate * 2, 2, 16 ) . 'data' . pack( 'V', strlen( $rrt_pcm ) ) . $rrt_pcm;
	$rrt_up  = wp_upload_bits( 'test-music-bed.wav', null, $rrt_wav );
	if ( ! empty( $rrt_up['error'] ) ) {
		echo 'Could not write the bed: ' . esc_html( $rrt_up['error'] ) . "\n";
		exit( 1 );
	}
	$rrt_id = wp_insert_attachment(
		array(
			'post_title'     => 'Test music bed',
			'post_mime_type' => 'audio/wav',
			'post_status'    => 'inherit',
		),
		$rrt_up['file']
	);
}

$rrt_s['music'] = array(
	'beds'    => array(
		array(
			'id'     => $rrt_id,
			'credit' => 'Test bed',
		),
	),
	'default' => $rrt_id,
	'depth'   => 20,
	'swell'   => false,
);
update_option( 'rrt_settings', $rrt_s );
echo 'Music bed ' . (int) $rrt_id . " set.\n";
