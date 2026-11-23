<?php
/**
 * Posts for the full Podcasting 2.0 feed tests (1.10.0):
 *   wp eval-file tests/wp/feed.php        makes them and prints their ids as JSON
 *   wp eval-file tests/wp/feed.php off <id> <id>  deletes them and their files
 *
 * The first post is the clip suggestions test's three-minute reading and
 * captions (tests/wp/suggest.php), with its reading swapped for an MP3
 * whose ID3 tag carries three chapters, written out of time order (as a
 * tagger may), one with a link, with artwork after them. The second post has an MP3 with no tag and no captions.
 *
 * ff_id3() is shared with tests/php/feed.php.
 */

if ( ! function_exists( 'ff_id3' ) ) {
	/** A syncsafe 28-bit size. */
	function ff_syncsafe( $n ) {
		return chr( ( $n >> 21 ) & 127 ) . chr( ( $n >> 14 ) & 127 ) . chr( ( $n >> 7 ) & 127 ) . chr( $n & 127 );
	}

	/** An ID3 frame of tag version $v (3 or 4). */
	function ff_frame( $id, $body, $v = 3 ) {
		return $id . ( 4 === $v ? ff_syncsafe( strlen( $body ) ) : pack( 'N', strlen( $body ) ) ) . "\0\0" . $body;
	}

	/** A text frame body: Latin-1 unless $utf16, then UTF-16 with a byte order mark. */
	function ff_text( $s, $utf16 = false ) {
		if ( $utf16 ) {
			return "\x01\xff\xfe" . mb_convert_encoding( $s, 'UTF-16LE', 'UTF-8' );
		}
		return "\x00" . mb_convert_encoding( $s, 'ISO-8859-1', 'UTF-8' );
	}

	/**
	 * An ID3 tag with chapters.
	 *
	 * @param array $chapters [ id, start s, end s, title, url ] in the table of contents' order.
	 * @param array $o        v (3 or 4), utf16 (titles as UTF-16), art (artwork after the chapters), toc (write a table of contents).
	 */
	function ff_id3( $chapters, $o = array() ) {
		$o      = array_merge( array( 'v' => 3, 'utf16' => false, 'art' => true, 'toc' => true ), $o );
		$v      = (int) $o['v'];
		$frames = ff_frame( 'TIT2', ff_text( 'Feed test' ), $v );
		if ( $o['toc'] ) {
			$toc = "toc\0" . chr( 3 ) . chr( count( $chapters ) );
			foreach ( $chapters as $c ) {
				$toc .= $c[0] . "\0";
			}
			$frames .= ff_frame( 'CTOC', $toc, $v );
		}
		foreach ( $chapters as $c ) {
			$sub = ff_frame( 'TIT2', ff_text( $c[3], $o['utf16'] ), $v );
			if ( ! empty( $c[4] ) ) {
				$sub .= ff_frame( 'WXXX', "\x00\x00" . $c[4], $v );
			}
			$body    = $c[0] . "\0" . pack( 'NN', (int) round( $c[1] * 1000 ), (int) round( $c[2] * 1000 ) ) . str_repeat( "\xff", 8 ) . $sub;
			$frames .= ff_frame( 'CHAP', $body, $v );
		}
		if ( $o['art'] ) {
			$frames .= ff_frame( 'APIC', "\x00image/jpeg\0\x03\0" . str_repeat( "\xd8", 2000 ), $v );
		}
		$frames .= str_repeat( "\0", 256 );
		return 'ID3' . chr( $v ) . "\0\0" . ff_syncsafe( strlen( $frames ) ) . $frames;
	}

	/** A small file that looks like an MP3 after its tag, made into an attachment of the post. */
	function ff_attach( $post_id, $name, $body ) {
		$up   = wp_upload_dir();
		$path = trailingslashit( $up['path'] ) . wp_unique_filename( $up['path'], $name );
		file_put_contents( $path, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$att = wp_insert_attachment(
			array(
				'post_title'     => pathinfo( $path, PATHINFO_FILENAME ),
				'post_mime_type' => 'audio/mpeg',
				'post_status'    => 'inherit',
			),
			$path,
			$post_id
		);
		wp_update_attachment_metadata( $att, array( 'length' => 180, 'filesize' => strlen( $body ) ) );
		return (int) $att;
	}
}

if ( isset( $args[0] ) && 'off' === $args[0] ) {
	foreach ( array_slice( $args, 1 ) as $id ) {
		foreach ( get_children( array( 'post_parent' => (int) $id, 'post_type' => 'attachment' ) ) as $a ) {
			wp_delete_attachment( $a->ID, true );
		}
		wp_delete_post( (int) $id, true );
	}
	return;
}

/* The suggestions test's post, made quietly. */
$ff_args = $args;
$args    = array( '-19' );
ob_start();
include __DIR__ . '/suggest.php';
$ff_seed = json_decode( ob_get_clean(), true );
$args    = $ff_args;
$ff_post = (int) $ff_seed['post'];
wp_update_post( array( 'ID' => $ff_post, 'post_title' => 'Feed test' ) );

$ff_chapters = array(
	array( 'chp1', 60, 120, 'The second part', 'https://example.com/second' ),
	array( 'chp0', 0, 60, 'Opening' ),
	array( 'chp2', 120, 180, 'Close' ),
);
$ff_mp3 = ff_attach( $ff_post, 'feed-test-reading.mp3', ff_id3( $ff_chapters ) . str_repeat( "\xFF\xFB\x90\x00", 2000 ) );
update_post_meta( $ff_post, 'rrt_audio', $ff_mp3 );

$ff_plain = wp_insert_post( array( 'post_title' => 'Feed plain test', 'post_status' => 'publish', 'post_content' => '<p>No chapters here.</p>' ) );
$ff_pmp3  = ff_attach( $ff_plain, 'feed-plain-reading.mp3', str_repeat( "\xFF\xFB\x90\x00", 2000 ) );
update_post_meta( $ff_plain, 'rrt_audio', $ff_pmp3 );

echo wp_json_encode(
	array(
		'post'  => $ff_post,
		'plain' => (int) $ff_plain,
	)
);
