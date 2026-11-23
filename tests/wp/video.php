<?php
/**
 * A post with a short reading for the full-length video test (1.18.0):
 *   wp eval-file tests/wp/video.php        makes the post and prints its id, length and captions as JSON
 *   wp eval-file tests/wp/video.php off <id> deletes it and its files
 *
 * The reading is about 16 s: five sentences of a voice-like buzz, one
 * syllable a word, at about -19 LUFS, with captions of one cue a sentence and
 * three chapters (kept as the feed keeps an MP3's chapters).
 */

if ( isset( $args[0] ) && 'off' === $args[0] ) {
	$id = isset( $args[1] ) ? (int) $args[1] : 0;
	foreach ( get_children( array( 'post_parent' => $id, 'post_type' => 'attachment' ) ) as $a ) {
		wp_delete_attachment( $a->ID, true );
	}
	wp_delete_post( $id, true );
	return;
}

$sents = array(
	'Leaves fall slowly over the quiet hill.',
	'Owls call softly to the moon.',
	'Rivers run cold under silver light.',
	'Lanterns glow along the path.',
	'The woods are old and still.',
);
$post  = wp_insert_post(
	array(
		'post_title'   => 'Video test',
		'post_status'  => 'publish',
		'post_excerpt' => 'A short test reading for the video maker.',
		'post_content' => '<p>' . implode( ' ', $sents ) . '</p>',
	)
);

$cues  = array();
$words = array();
$t     = 0.5;
foreach ( $sents as $sn ) {
	$s = $t;
	foreach ( explode( ' ', $sn ) as $w ) {
		$words[] = array( $t, $t + 0.3 );
		$t      += 0.38;
	}
	$cues[] = array( $s, $t - 0.05, $sn );
	$t     += 0.6;
}
$dur = $t + 1.0;

$rate = 24000;
$pcm  = array_fill( 0, (int) ceil( $dur * $rate ), 0.0 );
foreach ( $words as $i => $w ) {
	$a  = (int) ( $w[0] * $rate );
	$b  = (int) ( $w[1] * $rate );
	$f0 = 120 + 25 * sin( $i * 0.7 );
	for ( $k = $a; $k < $b; $k++ ) {
		$x   = ( $k - $a ) / ( $b - $a );
		$env = pow( sin( M_PI * $x ), 0.6 );
		$ph  = 2 * M_PI * $f0 * $k / $rate;
		$v   = 0;
		for ( $h = 1; $h <= 8; $h++ ) {
			$v += sin( $h * $ph ) / $h;
		}
		$pcm[ $k ] = $v * $env;
	}
}
/* 0.1 full scale of this buzz measures about -27.4 LUFS, so this is about -19. */
$gain = 0.1 * pow( 10, 8.4 / 20 );
$data = '';
foreach ( $pcm as $v ) {
	$data .= pack( 'v', (int) max( -32768, min( 32767, round( $v * $gain * 32767 ) ) ) );
}
$wav = 'RIFF' . pack( 'V', 36 + strlen( $data ) ) . 'WAVEfmt ' . pack( 'VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16 ) . 'data' . pack( 'V', strlen( $data ) ) . $data;

$stamp = function ( $x ) {
	return sprintf( '%02d:%02d:%06.3f', floor( $x / 3600 ), floor( fmod( $x, 3600 ) / 60 ), fmod( $x, 60 ) );
};
$vtt = "WEBVTT\n\n";
foreach ( $cues as $i => $c ) {
	$vtt .= ( $i + 1 ) . "\n" . $stamp( $c[0] ) . ' --> ' . $stamp( $c[1] ) . "\n" . $c[2] . "\n\n";
}

$up  = wp_upload_dir();
$ids = array();
foreach ( array( 'wav' => array( $wav, 'audio/wav' ), 'vtt' => array( $vtt, 'text/vtt' ) ) as $ext => $f ) {
	$path = trailingslashit( $up['path'] ) . 'video-test-' . $post . '.' . $ext;
	file_put_contents( $path, $f[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$ids[ $ext ] = wp_insert_attachment( array( 'post_title' => 'video-' . $ext, 'post_mime_type' => $f[1], 'post_status' => 'inherit' ), $path, $post );
	update_attached_file( $ids[ $ext ], $path );
}
update_post_meta( $post, 'rrt_captions', $ids['vtt'] );
update_post_meta( $post, 'rrt_audio', $ids['wav'] );

/* Chapters, as the feed keeps them for an MP3 whose tag has them (a WAV has none of its own). */
update_post_meta(
	$post,
	RRT_FEED_META,
	array(
		'key'      => rrt_feed_key( $post ),
		'chapters' => array(
			array( 'start' => 0.0, 'end' => 5.0, 'title' => 'The hill' ),
			array( 'start' => 5.0, 'end' => 10.0, 'title' => 'The river and the long road under the silver light of the moon' ),
			array( 'start' => 10.0, 'end' => $dur, 'title' => 'The woods' ),
		),
		'top'      => null,
	)
);

echo wp_json_encode(
	array(
		'post'     => $post,
		'duration' => round( $dur, 3 ),
		'cues'     => array_map(
			function ( $c ) {
				return array( 's' => round( $c[0], 3 ), 'e' => round( $c[1], 3 ), 't' => $c[2] );
			},
			$cues
		),
	)
);
