<?php
/**
 * A post with a reading for the clip suggestions test (1.7.0):
 *   wp eval-file tests/wp/suggest.php <LUFS>   makes the post and prints its id and the reading's words
 *   wp eval-file tests/wp/suggest.php off <id> deletes it and its files
 *
 * The reading is about three minutes: a title, two paragraphs, a heading and four
 * more paragraphs,  read at 0.42 s a word with about 0.3 s between sentences and
 * 0.75 s between paragraphs. The second paragraph opens on "And". The audio is a
 * voice-like buzz, one syllable a word, made at the loudness asked for
 * (-19 LUFS is what a finished reading has; quieter is a reading put on by hand).
 * The captions are one cue a sentence, as Use this recording makes them.
 */

if ( isset( $args[0] ) && 'off' === $args[0] ) {
	$id = isset( $args[1] ) ? (int) $args[1] : 0;
	foreach ( get_children( array( 'post_parent' => $id, 'post_type' => 'attachment' ) ) as $a ) {
		wp_delete_attachment( $a->ID, true );
	}
	wp_delete_post( $id, true );
	return;
}

$lufs  = isset( $args[0] ) ? (float) $args[0] : -19.0;
$bank  = explode( ' ', 'leaves fall slowly over the quiet hill where owls call softly to the moon and rivers run cold under silver light while lanterns glow along the path through ancient woods of oak and ash' );
$plan  = array(
	array( 'p', 5, '' ),
	array( 'p', 6, 'And' ),
	array( 'h', 0, 'The second part' ),
	array( 'p', 6, '' ),
	array( 'p', 5, '' ),
	array( 'p', 7, '' ),
	array( 'p', 6, '' ),
);
$title = 'Suggest test';
$html  = '';
$cues  = array();
$words = array();
$t     = 0.4;
$n     = 0;
$sent  = 0;
$block = 0;
$say   = function ( $list, $kind ) use ( &$t, &$cues, &$words, &$sent, &$block ) {
	$s = $t;
	foreach ( $list as $w ) {
		$words[] = array( 't' => $w, 's' => round( $t, 3 ), 'e' => round( $t + 0.36, 3 ), 'sn' => $sent, 'b' => $block, 'k' => $kind );
		$t      += 0.42;
	}
	$cues[] = array( $s, $t - 0.06, implode( ' ', $list ) );
	++$sent;
};
$say( explode( ' ', $title ), 'title' );
$t += 1.0;
foreach ( $plan as $pi => $p ) {
	++$block;
	if ( 'h' === $p[0] ) {
		$say( explode( ' ', $p[2] ), 'h' );
		$html .= '<h2>' . $p[2] . '</h2>';
		$t    += 1.0;
		continue;
	}
	$sents = array();
	for ( $k = 0; $k < $p[1]; $k++ ) {
		$len = 9 + ( ( $pi + $k ) % 4 );
		$w   = array();
		for ( $j = 0; $j < $len; $j++ ) {
			$w[] = $bank[ ( $n++ ) % count( $bank ) ];
		}
		$w[0]         = ( 0 === $k && '' !== $p[2] ) ? $p[2] : ucfirst( $w[0] );
		$w[ $len - 1 ] .= '.';
		$say( $w, 'p' );
		$sents[] = implode( ' ', $w );
		/* About 0.3 s between sentences, as in a real reading (0.2 to 0.5 s). */
		$t += 0.25;
	}
	$html .= '<p>' . implode( ' ', $sents ) . '</p>';
	$t    += 0.5;
}
$dur = $t + 1.5;

$post = wp_insert_post( array( 'post_title' => $title, 'post_status' => 'publish', 'post_content' => $html ) );

/* The voice: a buzz at about 130 Hz with falling harmonics, shaped into one syllable a word. */
$rate = 24000;
$pcm  = array_fill( 0, (int) ceil( $dur * $rate ), 0.0 );
foreach ( $words as $i => $w ) {
	$a  = (int) ( $w['s'] * $rate );
	$b  = (int) ( $w['e'] * $rate );
	$f0 = 120 + 25 * sin( $i * 0.7 );
	$g  = 0.75 + 0.25 * sin( $i * 1.3 );
	for ( $k = $a; $k < $b; $k++ ) {
		$x   = ( $k - $a ) / ( $b - $a );
		$env = pow( sin( M_PI * $x ), 0.6 ) * $g;
		$ph  = 2 * M_PI * $f0 * $k / $rate;
		$v   = 0;
		for ( $h = 1; $h <= 8; $h++ ) {
			$v += sin( $h * $ph ) / $h;
		}
		$pcm[ $k ] = $v * $env;
	}
}
/* Room tone, very low. */
mt_srand( 7 );
foreach ( $pcm as $k => $v ) {
	$pcm[ $k ] = $v + ( mt_rand() / mt_getrandmax() - 0.5 ) * 0.0006;
}
/* 0.1 full scale of this buzz measures about -27.4 LUFS (checked in the browser with the plugin's own meter). */
$gain = 0.1 * pow( 10, ( $lufs + 27.4 ) / 20 );
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
	$path = trailingslashit( $up['path'] ) . 'suggest-test-' . $post . '.' . $ext;
	file_put_contents( $path, $f[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$ids[ $ext ] = wp_insert_attachment( array( 'post_title' => 'suggest-' . $ext, 'post_mime_type' => $f[1], 'post_status' => 'inherit' ), $path, $post );
	update_attached_file( $ids[ $ext ], $path );
}
update_post_meta( $post, 'rrt_captions', $ids['vtt'] );
update_post_meta( $post, 'rrt_audio', $ids['wav'] );

echo wp_json_encode( array( 'post' => $post, 'words' => $words, 'duration' => round( $dur, 2 ) ) );
