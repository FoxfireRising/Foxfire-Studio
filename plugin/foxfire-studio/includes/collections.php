<?php
/**
 * Collections (1.19.0): several readings joined into one MP3 with a chapter
 * for each, with its own page, player, captions and podcast episode.
 *
 * A collection is a post of its own type (rrt_collection), edited on the
 * usual WordPress screen with a Readings in this collection box. Saving it
 * joins the readings on the site by copying their MP3 frames end to end,
 * with silent frames between them. Nothing is encoded again, so every
 * reading sounds exactly as it does on its post. The plugin's MP3s are
 * constant bitrate, which is what makes this possible.
 *
 * A reading in another format, or far from the others' loudness, can be
 * matched in the browser (assets/js/collections.js): that one reading is
 * decoded, leveled and encoded once in the collection's format, and the
 * copy is kept with the collection. The post's own reading is never touched.
 *
 * The joined MP3 and captions are attachments of the collection, and are
 * set as its reading (rrt_audio, rrt_captions), so the player, the feed,
 * the chapters file and the listening numbers work for it as for a post.
 *
 * When a reading in a collection changes, the collection is joined again at
 * the end of that request. When a reading is taken off its post, unpublished
 * or deleted, the collection keeps the file it has until it is saved again.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The post type. */
define( 'RRT_COL_TYPE', 'rrt_collection' );

/** A collection's choices: its readings in order, the gap, the feed and the episode type. */
define( 'RRT_COL_META', '_rrt_col' );

/** What the last join made, and when. */
define( 'RRT_COL_BUILT', '_rrt_col_built' );

/** On a matched copy (an attachment of the collection): the reading and file it was made from. */
define( 'RRT_COL_MATCH', '_rrt_col_match' );

/** On a reading's post: its file's loudness, measured in the browser, with the file it belongs to. */
define( 'RRT_LEVEL_META', '_rrt_level' );

/** The most readings in one collection. */
define( 'RRT_COL_MAX', 100 );

/** How far a reading's loudness may be from the rest before it is pointed out, in LU. */
define( 'RRT_COL_SLACK', 1.0 );

/** How much louder the same voice measures in two channels than in one (BS.1770). */
define( 'RRT_COL_STEREO_LU', 3.01 );

/*
 * ---------------------------------------------------------------------
 * MP3 frames (pure functions, no WordPress needed)
 * ---------------------------------------------------------------------
 */

/** MPEG-1 Layer III bitrates by index, in kbps. */
function rrt_col_bitrates() {
	return array( 0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 0 );
}

/** MPEG-1 sample rates by index. */
function rrt_col_rates() {
	return array( 44100, 48000, 32000, 0 );
}

/**
 * One four-byte frame header read: kbps, rate, ch, pad, len, or null when it
 * is not an MPEG-1 Layer III header.
 *
 * @param string $b Four bytes.
 */
function rrt_col_header( $b ) {
	if ( strlen( $b ) < 4 ) {
		return null;
	}
	$h = unpack( 'N', $b );
	$h = $h[1];
	if ( ( $h & 0xFFE00000 ) !== 0xFFE00000 ) {
		return null;
	}
	$ver  = ( $h >> 19 ) & 3;
	$lay  = ( $h >> 17 ) & 3;
	$bri  = ( $h >> 12 ) & 15;
	$sri  = ( $h >> 10 ) & 3;
	$pad  = ( $h >> 9 ) & 1;
	$mode = ( $h >> 6 ) & 3;
	$br   = rrt_col_bitrates();
	$sr   = rrt_col_rates();
	if ( 3 !== $ver || 1 !== $lay || ! $br[ $bri ] || ! $sr[ $sri ] ) {
		return null;
	}
	$kbps = $br[ $bri ];
	$rate = $sr[ $sri ];
	return array(
		'kbps' => $kbps,
		'rate' => $rate,
		'ch'   => 3 === $mode ? 1 : 2,
		'pad'  => $pad,
		'len'  => intdiv( 144000 * $kbps, $rate ) + $pad,
	);
}

/**
 * Walks an MP3's frames: where the audio starts and ends, its format, and how
 * long it is. Only constant-bitrate MPEG-1 Layer III files can be joined, as
 * the plugin makes them; anything else comes back with an error.
 *
 * @param string $path The file.
 * @return array ok, error ('missing', 'notmp3', 'vbr', 'damaged', 'empty'), kbps, rate, ch, start, end, frames, secs.
 */
function rrt_col_scan( $path ) {
	$out = array(
		'ok'     => false,
		'error'  => 'missing',
		'kbps'   => 0,
		'rate'   => 0,
		'ch'     => 0,
		'start'  => 0,
		'end'    => 0,
		'frames' => 0,
		'secs'   => 0.0,
	);
	if ( $path ) {
		clearstatcache( true, $path );
	}
	$fh = ( $path && is_file( $path ) && is_readable( $path ) ) ? fopen( $path, 'rb' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( ! $fh ) {
		return $out;
	}
	$size = (int) filesize( $path );
	$head = (string) fread( $fh, 10 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$pos  = 0;
	if ( strlen( $head ) === 10 && 'ID3' === substr( $head, 0, 3 ) ) {
		$s   = ( ( ord( $head[6] ) & 127 ) << 21 ) | ( ( ord( $head[7] ) & 127 ) << 14 ) | ( ( ord( $head[8] ) & 127 ) << 7 ) | ( ord( $head[9] ) & 127 );
		$pos = 10 + $s + ( ( ord( $head[5] ) & 0x10 ) ? 10 : 0 );
	}
	$end = $size;
	if ( $size >= 128 ) {
		fseek( $fh, $size - 128 );
		if ( 'TAG' === fread( $fh, 3 ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			$end -= 128;
		}
	}
	/* The first frame: right after the tag, or within the next 64 KB (some taggers leave padding), where two headers in a row agree. */
	fseek( $fh, $pos );
	$h = rrt_col_header( (string) fread( $fh, 4 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( ! $h ) {
		fseek( $fh, $pos );
		$block = (string) fread( $fh, 65536 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$found = -1;
		for ( $i = 0, $n = strlen( $block ) - 4; $i < $n; $i++ ) {
			if ( "\xFF" !== $block[ $i ] ) {
				continue;
			}
			$a = rrt_col_header( substr( $block, $i, 4 ) );
			if ( ! $a ) {
				continue;
			}
			fseek( $fh, $pos + $i + $a['len'] );
			$b = rrt_col_header( (string) fread( $fh, 4 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( $b && $b['rate'] === $a['rate'] ) {
				$found = $i;
				break;
			}
		}
		if ( $found < 0 ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$out['error'] = 'notmp3';
			return $out;
		}
		$pos += $found;
		fseek( $fh, $pos );
		$h = rrt_col_header( (string) fread( $fh, 4 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
	/* A Xing, Info or VBRI frame at the start holds only facts about the file: leave it out. */
	fseek( $fh, $pos );
	$first = (string) fread( $fh, min( $h['len'], 200 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$side  = 1 === $h['ch'] ? 21 : 36;
	$tag4  = substr( $first, $side, 4 );
	if ( 'Xing' === $tag4 || 'Info' === $tag4 || 'VBRI' === substr( $first, 36, 4 ) ) {
		if ( 'Xing' === $tag4 || 'VBRI' === substr( $first, 36, 4 ) ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$out['error'] = 'vbr';
			return $out;
		}
		$pos += $h['len'];
	}
	$start  = $pos;
	$frames = 0;
	$fmt    = null;
	while ( $pos + 4 <= $end ) {
		fseek( $fh, $pos );
		$f = rrt_col_header( (string) fread( $fh, 4 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $f ) {
			/* Anything after the last frame (a tag, a few stray bytes) is fine; a break in the middle is not. */
			fseek( $fh, $pos );
			$peek = (string) fread( $fh, 8 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( $end - $pos < 8192 || 'APETAGEX' === $peek || 'ID3' === substr( $peek, 0, 3 ) || 'TAG' === substr( $peek, 0, 3 ) ) {
				break;
			}
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$out['error'] = $frames ? 'damaged' : 'notmp3';
			return $out;
		}
		if ( null === $fmt ) {
			$fmt = $f;
		} elseif ( $f['kbps'] !== $fmt['kbps'] ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$out['error'] = 'vbr';
			return $out;
		} elseif ( $f['rate'] !== $fmt['rate'] || $f['ch'] !== $fmt['ch'] ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$out['error'] = 'damaged';
			return $out;
		}
		if ( $pos + $f['len'] > $end ) {
			break; // A last frame cut short is left out.
		}
		$pos += $f['len'];
		++$frames;
	}
	fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( ! $frames ) {
		$out['error'] = 'empty';
		return $out;
	}
	return array(
		'ok'     => true,
		'error'  => '',
		'kbps'   => $fmt['kbps'],
		'rate'   => $fmt['rate'],
		'ch'     => $fmt['ch'],
		'start'  => $start,
		'end'    => $pos,
		'frames' => $frames,
		'secs'   => $frames * 1152 / $fmt['rate'],
	);
}

/** A format as one string, for comparing: kbps-rate-channels. */
function rrt_col_fmt_key( $f ) {
	return (int) $f['kbps'] . '-' . (int) $f['rate'] . '-' . (int) $f['ch'];
}

/** A format in words, such as "128 kbps mono". */
function rrt_col_fmt_label( $f ) {
	$ch = 1 === (int) $f['ch'] ? __( 'mono', 'foxfire-studio' ) : __( 'stereo', 'foxfire-studio' );
	if ( 44100 === (int) $f['rate'] ) {
		/* translators: 1: bitrate in kbps, 2: mono or stereo. */
		return sprintf( __( '%1$d kbps %2$s', 'foxfire-studio' ), (int) $f['kbps'], $ch );
	}
	/* translators: 1: bitrate in kbps, 2: mono or stereo, 3: sample rate in kHz. */
	return sprintf( __( '%1$d kbps %2$s, %3$s kHz', 'foxfire-studio' ), (int) $f['kbps'], $ch, rtrim( rtrim( number_format( $f['rate'] / 1000, 2, '.', '' ), '0' ), '.' ) );
}

/**
 * Silent frames in a format: a header, side information of zeros (no main
 * data, so every sample decodes to zero) and zeros to the frame's length. The
 * padding bit keeps the byte rate exact, as LAME does.
 *
 * @param array $f      kbps, rate, ch.
 * @param int   $frames How many.
 */
function rrt_col_silence( $f, $frames ) {
	$bri  = array_search( (int) $f['kbps'], rrt_col_bitrates(), true );
	$sri  = array_search( (int) $f['rate'], rrt_col_rates(), true );
	$mode = 1 === (int) $f['ch'] ? 3 : 1;
	$base = intdiv( 144000 * $f['kbps'], $f['rate'] );
	$rest = ( 144000 * $f['kbps'] ) % $f['rate'];
	$out  = '';
	$acc  = 0;
	for ( $i = 0; $i < $frames; $i++ ) {
		$acc += $rest;
		$pad  = $acc >= $f['rate'] ? 1 : 0;
		$acc -= $pad * $f['rate'];
		$h    = 0xFFE00000 | ( 3 << 19 ) | ( 1 << 17 ) | ( 1 << 16 ) | ( $bri << 12 ) | ( $sri << 10 ) | ( $pad << 9 ) | ( $mode << 6 );
		$out .= pack( 'N', $h ) . str_repeat( "\0", $base + $pad - 4 );
	}
	return $out;
}

/** How many frames make a gap of so many seconds. */
function rrt_col_gap_frames( $secs, $rate ) {
	return $rate > 0 ? (int) round( max( 0, (float) $secs ) * $rate / 1152 ) : 0;
}

/*
 * ---------------------------------------------------------------------
 * The tag (ID3v2.3, as id3.js writes it)
 * ---------------------------------------------------------------------
 */

/** The Unicode code points of a UTF-8 string. */
function rrt_col_codepoints( $s ) {
	$out = array();
	foreach ( (array) preg_split( '//u', (string) $s, -1, PREG_SPLIT_NO_EMPTY ) as $c ) {
		$b0 = ord( $c[0] );
		if ( $b0 < 0x80 ) {
			$out[] = $b0;
		} elseif ( $b0 < 0xE0 ) {
			$out[] = ( ( $b0 & 0x1F ) << 6 ) | ( ord( $c[1] ) & 0x3F );
		} elseif ( $b0 < 0xF0 ) {
			$out[] = ( ( $b0 & 0x0F ) << 12 ) | ( ( ord( $c[1] ) & 0x3F ) << 6 ) | ( ord( $c[2] ) & 0x3F );
		} else {
			$out[] = ( ( $b0 & 0x07 ) << 18 ) | ( ( ord( $c[1] ) & 0x3F ) << 12 ) | ( ( ord( $c[2] ) & 0x3F ) << 6 ) | ( ord( $c[3] ) & 0x3F );
		}
	}
	return $out;
}

/** Text for a tag: Latin-1 when it fits (encoding 0), otherwise UTF-16 with a byte order mark (encoding 1). */
function rrt_col_id3_text( $s, $terminate = false ) {
	$cps = rrt_col_codepoints( $s );
	$max = $cps ? max( $cps ) : 0;
	if ( $max < 256 ) {
		$t = '';
		foreach ( $cps as $cp ) {
			$t .= chr( $cp );
		}
		return "\x00" . $t . ( $terminate ? "\x00" : '' );
	}
	$t = "\xFF\xFE";
	foreach ( $cps as $cp ) {
		if ( $cp > 0xFFFF ) {
			$cp -= 0x10000;
			$t  .= pack( 'v', 0xD800 | ( $cp >> 10 ) ) . pack( 'v', 0xDC00 | ( $cp & 0x3FF ) );
		} else {
			$t .= pack( 'v', $cp );
		}
	}
	return "\x01" . $t . ( $terminate ? "\x00\x00" : '' );
}

/** An address with anything outside plain ASCII percent-encoded, for a tag's Latin-1 link. */
function rrt_col_ascii_url( $url ) {
	return preg_replace_callback(
		'/[^\x21-\x7E]/',
		function ( $m ) {
			return rawurlencode( $m[0] );
		},
		(string) $url
	);
}

/** One frame. */
function rrt_col_id3_frame( $id, $body ) {
	return $id . pack( 'N', strlen( $body ) ) . "\0\0" . $body;
}

/**
 * The whole tag: title, artist, album, year, length, the collection's
 * address, the chapters (each with a title and a link), and the artwork last,
 * so a player that reads only the start of the file finds the chapters.
 *
 * @param array $m title, artist, album, year, ms, url, chapters (start, end, title, url), art (mime, data).
 */
function rrt_col_id3( $m ) {
	$f = '';
	foreach ( array(
		'TIT2' => 'title',
		'TPE1' => 'artist',
		'TALB' => 'album',
		'TYER' => 'year',
	) as $id => $k ) {
		if ( isset( $m[ $k ] ) && '' !== (string) $m[ $k ] ) {
			$f .= rrt_col_id3_frame( $id, rrt_col_id3_text( (string) $m[ $k ] ) );
		}
	}
	if ( ! empty( $m['ms'] ) ) {
		$f .= rrt_col_id3_frame( 'TLEN', rrt_col_id3_text( (string) (int) round( $m['ms'] ) ) );
	}
	$f .= rrt_col_id3_frame( 'TSSE', rrt_col_id3_text( 'Foxfire Studio ' . RRT_VERSION ) );
	if ( ! empty( $m['url'] ) ) {
		$f .= rrt_col_id3_frame( 'WOAS', rrt_col_ascii_url( $m['url'] ) );
	}
	$ch = isset( $m['chapters'] ) ? array_values( (array) $m['chapters'] ) : array();
	if ( $ch ) {
		$toc = "toc\0" . "\x03" . chr( min( 255, count( $ch ) ) );
		foreach ( array_slice( $ch, 0, 255 ) as $i => $c ) {
			$toc .= 'chp' . $i . "\0";
		}
		$f .= rrt_col_id3_frame( 'CTOC', $toc );
		foreach ( array_slice( $ch, 0, 255 ) as $i => $c ) {
			$sub = rrt_col_id3_frame( 'TIT2', rrt_col_id3_text( (string) $c['title'] ) );
			if ( ! empty( $c['url'] ) ) {
				$sub .= rrt_col_id3_frame( 'WXXX', "\x00\x00" . rrt_col_ascii_url( $c['url'] ) );
			}
			$f .= rrt_col_id3_frame( 'CHAP', 'chp' . $i . "\0" . pack( 'NNNN', (int) round( $c['start'] * 1000 ), (int) round( $c['end'] * 1000 ), 0xFFFFFFFF, 0xFFFFFFFF ) . $sub );
		}
	}
	if ( ! empty( $m['art']['data'] ) ) {
		$f .= rrt_col_id3_frame( 'APIC', "\x00" . ( isset( $m['art']['mime'] ) ? $m['art']['mime'] : 'image/jpeg' ) . "\x00\x03\x00" . $m['art']['data'] );
	}
	$f .= str_repeat( "\0", 1024 );
	$s  = strlen( $f );
	return 'ID3' . "\x03\x00\x00" . chr( ( $s >> 21 ) & 127 ) . chr( ( $s >> 14 ) & 127 ) . chr( ( $s >> 7 ) & 127 ) . chr( $s & 127 ) . $f;
}

/*
 * ---------------------------------------------------------------------
 * Chapters and captions
 * ---------------------------------------------------------------------
 */

/**
 * Each reading's place in the joined file, from its frames and the gap.
 *
 * @param array $scans  Each reading's scan (frames).
 * @param int   $rate   Sample rate.
 * @param int   $gapf   Frames of silence between readings.
 * @return array[] start, end (the reading's own audio), next (where the next one starts, or the end).
 */
function rrt_col_times( $scans, $rate, $gapf ) {
	$t   = 0;
	$out = array();
	$n   = count( $scans );
	foreach ( array_values( $scans ) as $i => $s ) {
		$start = $t;
		$t    += $s['frames'] * 1152 / $rate;
		$end   = $t;
		if ( $i < $n - 1 ) {
			$t += $gapf * 1152 / $rate;
		}
		$out[] = array(
			'start' => $start,
			'end'   => $end,
			'next'  => $t,
		);
	}
	return $out;
}

/**
 * The collection's chapters: one for each reading, titled with its post, and
 * a reading's own chapters (from its headings) carried in after it as
 * "Reading: heading". The list runs end to end, each chapter until the next.
 *
 * @param array $parts title, url, start, next, chapters (the reading's own: start, end, title, url).
 * @return array[] start, end, title, url.
 */
function rrt_col_chapters( $parts ) {
	$out = array();
	foreach ( $parts as $p ) {
		$own = array();
		foreach ( (array) ( isset( $p['chapters'] ) ? $p['chapters'] : array() ) as $c ) {
			if ( isset( $c['start'] ) && (float) $c['start'] < ( $p['next'] - $p['start'] ) ) {
				$own[] = $c;
			}
		}
		usort(
			$own,
			function ( $a, $b ) {
				return $a['start'] <=> $b['start'];
			}
		);
		if ( count( $own ) < 2 ) {
			$own = array();
		}
		if ( ! $own || (float) $own[0]['start'] > 0.5 ) {
			$out[] = array(
				'start' => $p['start'],
				'title' => $p['title'],
				'url'   => $p['url'],
			);
		}
		foreach ( $own as $k => $c ) {
			$head  = trim( (string) $c['title'] );
			$first = 0 === $k && (float) $c['start'] <= 0.5;
			$out[] = array(
				'start' => $p['start'] + ( $first ? 0 : (float) $c['start'] ),
				/* translators: 1: the reading's title, 2: a heading in it. */
				'title' => $first || '' === $head ? $p['title'] : sprintf( __( '%1$s: %2$s', 'foxfire-studio' ), $p['title'], $head ),
				'url'   => ! empty( $c['url'] ) ? (string) $c['url'] : $p['url'],
			);
		}
	}
	$total = $parts ? (float) end( $parts )['next'] : 0;
	foreach ( $out as $i => $c ) {
		$out[ $i ]['start'] = round( $c['start'], 3 );
		$out[ $i ]['end']   = round( isset( $out[ $i + 1 ] ) ? $out[ $i + 1 ]['start'] : $total, 3 );
	}
	return array_values(
		array_filter(
			$out,
			function ( $c ) {
				return $c['end'] > $c['start'];
			}
		)
	);
}

/** A VTT time (hh:mm:ss.mmm, or mm:ss.mmm, with a dot or comma) in seconds, or null. */
function rrt_col_vtt_time( $s ) {
	if ( ! preg_match( '/^(?:(\d+):)?(\d{1,2}):(\d{2})[.,](\d{1,3})$/', trim( (string) $s ), $m ) ) {
		return null;
	}
	return ( (int) $m[1] ) * 3600 + (int) $m[2] * 60 + (int) $m[3] + (int) str_pad( $m[4], 3, '0' ) / 1000;
}

/** Seconds as a VTT time. */
function rrt_col_vtt_stamp( $t ) {
	$ms = (int) round( max( 0, $t ) * 1000 );
	return sprintf( '%02d:%02d:%02d.%03d', intdiv( $ms, 3600000 ), intdiv( $ms, 60000 ) % 60, intdiv( $ms, 1000 ) % 60, $ms % 1000 );
}

/** The cues of a VTT file: start, end, text. Notes, styles and regions are left out. */
function rrt_col_vtt_cues( $vtt ) {
	$vtt  = str_replace( array( "\r\n", "\r" ), "\n", (string) $vtt );
	$vtt  = preg_replace( '/^\xEF\xBB\xBF/', '', $vtt );
	$out  = array();
	foreach ( preg_split( "/\n{2,}/", $vtt ) as $block ) {
		$lines = explode( "\n", trim( $block, "\n" ) );
		$at    = -1;
		foreach ( $lines as $i => $l ) {
			if ( false !== strpos( $l, '-->' ) ) {
				$at = $i;
				break;
			}
		}
		if ( $at < 0 || preg_match( '/^(NOTE|STYLE|REGION)\b/', $lines[0] ) ) {
			continue;
		}
		$pp = explode( '-->', $lines[ $at ], 2 );
		$s  = rrt_col_vtt_time( $pp[0] );
		$e  = rrt_col_vtt_time( preg_split( '/\s+/', trim( $pp[1] ) )[0] );
		$tx = trim( implode( "\n", array_slice( $lines, $at + 1 ) ) );
		if ( null === $s || null === $e || '' === $tx ) {
			continue;
		}
		$out[] = array(
			'start' => $s,
			'end'   => $e,
			'text'  => $tx,
		);
	}
	return $out;
}

/**
 * One captions file from several, each moved to its reading's place and kept
 * inside it.
 *
 * @param array $list Each: vtt (text), start, end (the reading's place in the collection).
 * @return string A VTT file, or '' when no reading had captions.
 */
function rrt_col_vtt_join( $list ) {
	$lines = array( 'WEBVTT', '' );
	$n     = 1;
	foreach ( $list as $p ) {
		foreach ( rrt_col_vtt_cues( $p['vtt'] ) as $c ) {
			$s = $p['start'] + $c['start'];
			$e = min( $p['end'], $p['start'] + $c['end'] );
			if ( $e <= $s ) {
				continue;
			}
			$lines[] = (string) $n++;
			$lines[] = rrt_col_vtt_stamp( $s ) . ' --> ' . rrt_col_vtt_stamp( $e );
			$lines[] = $c['text'];
			$lines[] = '';
		}
	}
	return $n > 1 ? implode( "\n", $lines ) : '';
}

/**
 * Writes the joined file: the tag, then each reading's frames with silence
 * between. Reads and writes a megabyte at a time.
 *
 * @param string $dest  Where to write.
 * @param string $tag   The tag.
 * @param array  $parts Each: path, start, end (byte offsets of its frames).
 * @param string $gap   The silent frames between readings.
 * @return int|false Bytes written.
 */
function rrt_col_write( $dest, $tag, $parts, $gap ) {
	$out = fopen( $dest, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( ! $out ) {
		return false;
	}
	$bytes = (int) fwrite( $out, $tag ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	foreach ( array_values( $parts ) as $i => $p ) {
		if ( $i > 0 && '' !== $gap ) {
			$bytes += (int) fwrite( $out, $gap ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$in = fopen( $p['path'], 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $in ) {
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return false;
		}
		fseek( $in, $p['start'] );
		$left = $p['end'] - $p['start'];
		while ( $left > 0 ) {
			$chunk = (string) fread( $in, min( 1048576, $left ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( '' === $chunk ) {
				fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				return false;
			}
			$bytes += (int) fwrite( $out, $chunk ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$left  -= strlen( $chunk );
		}
		fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	return $bytes;
}

/*
 * ---------------------------------------------------------------------
 * The post type
 * ---------------------------------------------------------------------
 */

add_action(
	'init',
	function () {
		register_post_type(
			RRT_COL_TYPE,
			array(
				'labels'          => array(
					'name'               => __( 'Collections', 'foxfire-studio' ),
					'singular_name'      => __( 'Collection', 'foxfire-studio' ),
					'add_new'            => __( 'Add New', 'foxfire-studio' ),
					'add_new_item'       => __( 'Add New Collection', 'foxfire-studio' ),
					'edit_item'          => __( 'Edit Collection', 'foxfire-studio' ),
					'new_item'           => __( 'New Collection', 'foxfire-studio' ),
					'view_item'          => __( 'View Collection', 'foxfire-studio' ),
					'view_items'         => __( 'View Collections', 'foxfire-studio' ),
					'search_items'       => __( 'Search Collections', 'foxfire-studio' ),
					'not_found'          => __( 'No collections yet.', 'foxfire-studio' ),
					'not_found_in_trash' => __( 'No collections in the trash.', 'foxfire-studio' ),
					'all_items'          => __( 'Collections', 'foxfire-studio' ),
					'archives'           => __( 'Collections', 'foxfire-studio' ),
				),
				'description'     => __( 'Several readings joined into one, with a chapter for each.', 'foxfire-studio' ),
				'public'          => true,
				'show_ui'         => true,
				'show_in_menu'    => false,
				'show_in_rest'    => false,
				'has_archive'     => 'collections',
				'rewrite'         => array(
					'slug'       => 'collections',
					'with_front' => false,
				),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
				'supports'        => array( 'title', 'editor', 'excerpt', 'thumbnail', 'author' ),
				'menu_icon'       => 'dashicons-playlist-audio',
			)
		);
		/* The addresses need the rewrite rules refreshed once after this version is installed. */
		if ( get_option( 'rrt_col_rewrite' ) !== '1' ) {
			update_option( 'rrt_col_rewrite', '1', false );
			flush_rewrite_rules( false );
		}
	},
	5
);

/* The edit screens sit under Foxfire Studio, Collections. */
add_filter(
	'parent_file',
	function ( $file ) {
		$s = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $s && RRT_COL_TYPE === $s->post_type ) {
			$GLOBALS['submenu_file'] = 'edit.php?post_type=' . RRT_COL_TYPE; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			return 'rrt-dashboard';
		}
		return $file;
	}
);

/** Whether a post is a collection. */
function rrt_col_is( $post ) {
	$post = get_post( $post );
	return $post && RRT_COL_TYPE === $post->post_type;
}

/** A collection's choices, filled in. */
function rrt_col_settings( $col_id ) {
	$m = get_post_meta( $col_id, RRT_COL_META, true );
	$m = is_array( $m ) ? $m : array();
	$items = array();
	foreach ( (array) ( isset( $m['items'] ) ? $m['items'] : array() ) as $id ) {
		$id = absint( $id );
		if ( $id && ! in_array( $id, $items, true ) && count( $items ) < RRT_COL_MAX ) {
			$items[] = $id;
		}
	}
	return array(
		'items' => $items,
		'gap'   => rrt_col_clean_gap( isset( $m['gap'] ) ? $m['gap'] : 2 ),
		'feed'  => ! isset( $m['feed'] ) || ! empty( $m['feed'] ),
		'type'  => ( isset( $m['type'] ) && 'full' === $m['type'] ) ? 'full' : 'bonus',
	);
}

/** The gap choices, in seconds. */
function rrt_col_gaps() {
	return array( 0, 0.5, 1, 1.5, 2, 3, 4, 5 );
}

/** A gap kept to one of the choices. */
function rrt_col_clean_gap( $g ) {
	$g    = is_numeric( $g ) ? (float) $g : 2;
	$best = 2;
	foreach ( rrt_col_gaps() as $c ) {
		if ( abs( $c - $g ) < abs( $best - $g ) ) {
			$best = $c;
		}
	}
	return (float) $best;
}

/** Every collection, in any state but the trash. */
function rrt_col_all() {
	return get_posts(
		array(
			'post_type'        => RRT_COL_TYPE,
			'post_status'      => array( 'publish', 'draft', 'pending', 'future', 'private' ),
			'numberposts'      => 500,
			'fields'           => 'ids',
			'suppress_filters' => true,
		)
	);
}

/** The collections a post's reading is in. */
function rrt_col_containing( $post_id ) {
	$out = array();
	foreach ( rrt_col_all() as $cid ) {
		if ( in_array( (int) $post_id, rrt_col_settings( $cid )['items'], true ) ) {
			$out[] = (int) $cid;
		}
	}
	return $out;
}

/*
 * ---------------------------------------------------------------------
 * Readings: their files, formats and levels
 * ---------------------------------------------------------------------
 */

/** What changes when a file changes: its path, size and time. */
function rrt_col_file_key( $path ) {
	if ( $path ) {
		clearstatcache( true, $path );
	}
	if ( ! $path || ! is_file( $path ) ) {
		return '';
	}
	return md5( $path . '|' . filesize( $path ) . '|' . filemtime( $path ) );
}

/** A reading's file on this site, or ''. */
function rrt_col_reading_path( $post_id ) {
	$a = rrt_reading_audio( $post_id );
	if ( '' === $a['url'] ) {
		return '';
	}
	/* 1.25.0: only a file the rule in safety.php allows, and a members' file only for its own post. */
	if ( $a['id'] ) {
		$f = rrt_att_file( $a['id'], $post_id, 'audio' );
		if ( '' !== $f ) {
			return $f;
		}
	}
	return rrt_podcast_local_path( $a['url'], $post_id, 'audio' );
}

/** A scan, kept for the rest of the request. */
function rrt_col_scan_cached( $path ) {
	static $seen = array();
	$k = rrt_col_file_key( $path );
	if ( '' === $k ) {
		return rrt_col_scan( '' );
	}
	if ( ! isset( $seen[ $k ] ) ) {
		$seen[ $k ] = rrt_col_scan( $path );
	}
	return $seen[ $k ];
}

/** A reading's measured loudness for this file, or null. */
function rrt_col_level( $post_id, $key ) {
	$l = get_post_meta( $post_id, RRT_LEVEL_META, true );
	return ( is_array( $l ) && isset( $l['key'], $l['lufs'] ) && $l['key'] === $key && is_numeric( $l['lufs'] ) ) ? (float) $l['lufs'] : null;
}

/** The matched copies kept with a collection: post => attachment, key, lufs. */
function rrt_col_matches( $col_id ) {
	$out = array();
	foreach ( get_children(
		array(
			'post_parent' => $col_id,
			'post_type'   => 'attachment',
			'numberposts' => -1,
		)
	) as $a ) {
		$m = get_post_meta( $a->ID, RRT_COL_MATCH, true );
		if ( is_array( $m ) && ! empty( $m['post'] ) ) {
			$out[ (int) $m['post'] ][] = array(
				'att'  => (int) $a->ID,
				'key'  => isset( $m['key'] ) ? (string) $m['key'] : '',
				'lufs' => isset( $m['lufs'] ) && is_numeric( $m['lufs'] ) ? (float) $m['lufs'] : null,
			);
		}
	}
	return $out;
}

/** A loudness as the same voice would measure in one channel, so mono and stereo files compare fairly. */
function rrt_col_mono_lu( $lufs, $ch ) {
	return null === $lufs ? null : (float) $lufs - ( 2 === (int) $ch ? RRT_COL_STEREO_LU : 0 );
}

/** The loudness readings should have, as one channel would measure it, from Finish for; null when the preset sets none. */
function rrt_col_target() {
	$spec = rrt_finish_spec( rrt_settings()['sound'] );
	if ( ! isset( $spec['lufs'] ) || ! is_numeric( $spec['lufs'] ) ) {
		return null;
	}
	return rrt_col_mono_lu( (float) $spec['lufs'], isset( $spec['ch'] ) ? (int) $spec['ch'] : 1 );
}

/** The format a collection is made in when none of its readings can say: Finish for's, as an MP3. */
function rrt_col_default_fmt() {
	$spec = rrt_finish_spec( rrt_settings()['sound'] );
	$mp3  = 'mp3' === $spec['format'];
	return array(
		'kbps' => $mp3 && ! empty( $spec['kbps'] ) ? (int) $spec['kbps'] : 128,
		'rate' => 44100,
		'ch'   => $mp3 && 2 === (int) $spec['ch'] ? 2 : 1,
	);
}

/**
 * Everything about a list of readings as a collection: each reading's state,
 * the collection's format and loudness, and whether it can be joined.
 *
 * Each item's kind: '' (fits), 'level' (fits, but far from the others'
 * loudness; it can be matched), 'format' (another format: it must be
 * matched), 'away' (its file is not on this site), 'none' (no reading),
 * 'unpublished', 'gone'.
 *
 * @param int[] $ids    The readings' posts, in order.
 * @param int   $col_id The collection, for its matched copies (0 for none).
 * @param float $gap    The gap in seconds.
 */
function rrt_col_plan( $ids, $col_id = 0, $gap = 2.0 ) {
	$matches = $col_id ? rrt_col_matches( $col_id ) : array();
	$rows    = array();
	$votes   = array();
	foreach ( $ids as $id ) {
		$id   = (int) $id;
		$post = get_post( $id );
		$row  = array(
			'id'      => $id,
			'title'   => $post ? html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' ) : '',
			'edit'    => $post ? (string) get_edit_post_link( $id, 'raw' ) : '',
			'link'    => $post ? (string) get_permalink( $post ) : '',
			'status'  => $post ? $post->post_status : '',
			'url'     => '',
			'key'     => '',
			'path'    => '',
			'scan'    => null,
			'fmt'     => '',
			'secs'    => 0,
			'lufs'    => null,
			'matched' => 0,
			'kind'    => '',
			'note'    => '',
		);
		if ( ! $post || RRT_COL_TYPE === $post->post_type || 'trash' === $post->post_status ) {
			$row['kind'] = 'gone';
			$row['note'] = __( 'This post is gone, so it is left out.', 'foxfire-studio' );
		} elseif ( 'publish' !== $post->post_status || '' !== (string) $post->post_password ) {
			$row['kind'] = 'unpublished';
			$row['note'] = __( 'Not published, so it is left out until it is.', 'foxfire-studio' );
		} else {
			$a = rrt_reading_audio( $id );
			if ( '' === $a['url'] ) {
				$row['kind'] = 'none';
				$row['note'] = __( 'This post has no reading now, so it is left out.', 'foxfire-studio' );
			} else {
				$row['url']  = $a['url'];
				$row['path'] = rrt_col_reading_path( $id );
				if ( '' === $row['path'] ) {
					$row['kind'] = 'away';
					$row['note'] = __( 'Its reading is not a file on this site, so it cannot be joined.', 'foxfire-studio' );
				} else {
					$row['key']  = rrt_col_file_key( $row['path'] );
					$row['scan'] = rrt_col_scan_cached( $row['path'] );
					$row['lufs'] = rrt_col_level( $id, $row['key'] );
					if ( $row['scan']['ok'] ) {
						$row['fmt']  = rrt_col_fmt_key( $row['scan'] );
						$row['secs'] = $row['scan']['secs'];
						$votes[ $row['fmt'] ] = ( isset( $votes[ $row['fmt'] ] ) ? $votes[ $row['fmt'] ] : 0 ) + 1;
					}
				}
			}
		}
		$rows[] = $row;
	}
	/* The collection's format: the one most of its readings are in, Finish for's on a tie (then the first of them), or Finish for's when none can say. */
	$fmt = rrt_col_default_fmt();
	if ( $votes ) {
		$best = max( $votes );
		$own  = rrt_col_fmt_key( $fmt );
		foreach ( $rows as $r ) {
			if ( isset( $votes[ $own ] ) && $votes[ $own ] === $best ) {
				break;
			}
			if ( '' !== $r['fmt'] && $votes[ $r['fmt'] ] === $best ) {
				$fmt = array(
					'kbps' => $r['scan']['kbps'],
					'rate' => $r['scan']['rate'],
					'ch'   => $r['scan']['ch'],
				);
				break;
			}
		}
	}
	$fkey = rrt_col_fmt_key( $fmt );
	/* The loudness to compare with: Finish for's, or the middle of the readings' own when it sets none. */
	$target = rrt_col_target();
	if ( null === $target ) {
		$known = array();
		foreach ( $rows as $r ) {
			if ( null !== $r['lufs'] && $r['scan'] && $r['scan']['ok'] ) {
				$known[] = rrt_col_mono_lu( $r['lufs'], $r['scan']['ch'] );
			}
		}
		sort( $known );
		$target = $known ? $known[ intdiv( count( $known ), 2 ) ] : null;
	}
	$secs  = 0;
	$count = 0;
	$ready = true;
	foreach ( $rows as $i => $r ) {
		if ( $r['kind'] ) {
			continue;
		}
		/* A matched copy made from this very file, in the collection's format, stands in for it. */
		foreach ( isset( $matches[ $r['id'] ] ) ? $matches[ $r['id'] ] : array() as $m ) {
			if ( $m['key'] !== $r['key'] ) {
				continue;
			}
			$mp = get_attached_file( $m['att'] );
			$ms = rrt_col_scan_cached( $mp );
			if ( $ms['ok'] && rrt_col_fmt_key( $ms ) === $fkey ) {
				$rows[ $i ]['matched'] = $m['att'];
				$rows[ $i ]['path']    = $mp;
				$rows[ $i ]['scan']    = $ms;
				$rows[ $i ]['secs']    = $ms['secs'];
				$rows[ $i ]['note']    = __( 'Matched in the browser for this collection.', 'foxfire-studio' );
				break;
			}
		}
		$r = $rows[ $i ];
		if ( ! $r['matched'] ) {
			if ( ! $r['scan']['ok'] ) {
				$rows[ $i ]['kind'] = 'format';
				$why = array(
					'vbr'     => __( 'It is a variable-bitrate MP3', 'foxfire-studio' ),
					'notmp3'  => __( 'It is not an MP3 (perhaps a WAV)', 'foxfire-studio' ),
					'damaged' => __( 'Its MP3 has a break in it', 'foxfire-studio' ),
					'empty'   => __( 'Its MP3 has no sound in it', 'foxfire-studio' ),
					'missing' => __( 'Its file could not be read', 'foxfire-studio' ),
				);
				/* translators: %s: why the file cannot be joined as it is, such as "It is a variable-bitrate MP3". */
				$rows[ $i ]['note'] = sprintf( __( '%s, so it needs matching before it can be joined.', 'foxfire-studio' ), isset( $why[ $r['scan']['error'] ] ) ? $why[ $r['scan']['error'] ] : $why['missing'] );
			} elseif ( $r['fmt'] !== $fkey ) {
				$rows[ $i ]['kind'] = 'format';
				/* translators: 1: the reading's format, 2: the collection's format. */
				$rows[ $i ]['note'] = sprintf( __( 'Made at %1$s, and the collection is %2$s, so it needs matching before it can be joined.', 'foxfire-studio' ), rrt_col_fmt_label( $r['scan'] ), rrt_col_fmt_label( $fmt ) );
			} elseif ( null !== $r['lufs'] && null !== $target ) {
				$off = rrt_col_mono_lu( $r['lufs'], $r['scan']['ch'] ) - $target;
				if ( abs( $off ) > RRT_COL_SLACK ) {
					$rows[ $i ]['kind'] = 'level';
					$rows[ $i ]['note'] = $off > 0
						/* translators: %s: decibels. */
						? sprintf( __( '%s dB louder than the rest. It can be joined as it is, or matched so it sits level with them.', 'foxfire-studio' ), number_format_i18n( abs( $off ), 1 ) )
						/* translators: %s: decibels. */
						: sprintf( __( '%s dB quieter than the rest. It can be joined as it is, or matched so it sits level with them.', 'foxfire-studio' ), number_format_i18n( abs( $off ), 1 ) );
				}
			}
		}
		if ( 'format' === $rows[ $i ]['kind'] ) {
			$ready = false;
		} else {
			$secs += $rows[ $i ]['secs'];
			++$count;
		}
	}
	if ( $count > 1 ) {
		$secs += ( $count - 1 ) * rrt_col_gap_frames( $gap, $fmt['rate'] ) * 1152 / $fmt['rate'];
	}
	return array(
		'rows'   => $rows,
		'fmt'    => $fmt,
		'label'  => rrt_col_fmt_label( $fmt ),
		'target' => $target,
		'secs'   => $secs,
		'count'  => $count,
		'ready'  => $ready && $count > 0,
	);
}

/** A plan's rows as the browser sees them (no server paths). */
function rrt_col_plan_public( $plan ) {
	$rows = array();
	foreach ( $plan['rows'] as $r ) {
		$rows[] = array(
			'id'      => $r['id'],
			'title'   => $r['title'],
			'edit'    => $r['edit'],
			'link'    => $r['link'],
			'url'     => $r['url'],
			'key'     => $r['key'],
			'secs'    => round( (float) $r['secs'], 3 ),
			'fmt'     => $r['scan'] && $r['scan']['ok'] ? rrt_col_fmt_label( $r['scan'] ) : '',
			'lufs'    => null === $r['lufs'] ? null : round( $r['lufs'], 2 ),
			'measure' => '' !== $r['key'] && null === $r['lufs'] && ! $r['matched'] && '' === $r['kind'],
			'matched' => $r['matched'] ? 1 : 0,
			'kind'    => $r['kind'],
			'note'    => $r['note'],
		);
	}
	$f = $plan['fmt'];
	return array(
		'rows'   => $rows,
		'fmt'    => array(
			'kbps' => (int) $f['kbps'],
			'rate' => (int) $f['rate'],
			'ch'   => (int) $f['ch'],
		),
		'label'  => $plan['label'],
		/* What a matched copy is leveled to, as its own channels measure it. */
		'target' => null === $plan['target'] ? null : round( $plan['target'] + ( 2 === (int) $f['ch'] ? RRT_COL_STEREO_LU : 0 ), 2 ),
		'tp'     => -1,
		'secs'   => round( $plan['secs'], 3 ),
		'count'  => $plan['count'],
		'ready'  => $plan['ready'],
	);
}

/*
 * ---------------------------------------------------------------------
 * Joining
 * ---------------------------------------------------------------------
 */

/** A picture for the tag: the collection's featured image, or the show's cover art, as a JPEG or PNG under 2 MB. */
function rrt_col_art( $col_id ) {
	$ids = array( (int) get_post_thumbnail_id( $col_id ) );
	if ( function_exists( 'rrt_podcast_settings' ) ) {
		$s     = rrt_podcast_settings();
		$ids[] = isset( $s['image'] ) ? (int) $s['image'] : 0;
	}
	foreach ( array_filter( $ids ) as $id ) {
		$src  = wp_get_attachment_image_src( $id, 'large' );
		$path = $src ? rrt_podcast_local_path( $src[0] ) : '';
		if ( ! $path ) {
			$path = (string) get_attached_file( $id );
		}
		if ( $path && is_file( $path ) && filesize( $path ) < 2097152 ) {
			$t = wp_check_filetype( $path );
			if ( in_array( $t['type'], array( 'image/jpeg', 'image/png' ), true ) ) {
				return array(
					'mime' => $t['type'],
					'data' => (string) file_get_contents( $path ), // phpcs:ignore WordPress.WP.AlternativeFunctions
					'id'   => $id,
				);
			}
		}
	}
	return array();
}

/**
 * Joins a collection's readings into its MP3 and captions, when anything
 * about them has changed since the last time.
 *
 * @param int    $col_id The collection.
 * @param string $why    'save' (asked for: readings that cannot be used are left out) or 'auto' (a reading changed: the file is kept as it is if any reading is missing).
 * @return array|WP_Error What was made (the RRT_COL_BUILT record).
 */
function rrt_col_build( $col_id, $why = 'save' ) {
	$col = get_post( $col_id );
	if ( ! $col || RRT_COL_TYPE !== $col->post_type ) {
		return new WP_Error( 'rrt_col', __( 'That is not a collection.', 'foxfire-studio' ) );
	}
	$set   = rrt_col_settings( $col_id );
	$plan  = rrt_col_plan( $set['items'], $col_id, $set['gap'] );
	$built = get_post_meta( $col_id, RRT_COL_BUILT, true );
	$built = is_array( $built ) ? $built : array();
	$use   = array();
	$out   = array();
	foreach ( $plan['rows'] as $r ) {
		if ( in_array( $r['kind'], array( '', 'level' ), true ) ) {
			$use[] = $r;
		} else {
			$out[] = $r;
		}
	}
	$keep = function ( $msg ) use ( $col_id, $built ) {
		$built['error'] = $msg;
		$built['tried'] = time();
		update_post_meta( $col_id, RRT_COL_BUILT, $built );
		return new WP_Error( 'rrt_col', $msg );
	};
	if ( ! $plan['ready'] ) {
		return $keep( $use ? __( 'Not joined: a reading in another format needs matching first.', 'foxfire-studio' ) : __( 'Not joined: there is no reading to join yet.', 'foxfire-studio' ) );
	}
	if ( 'auto' === $why && $out && ! empty( $built['att'] ) ) {
		return $keep( __( 'A reading in this collection is no longer available, so the collection keeps the file it has. Save it to join it again without that reading.', 'foxfire-studio' ) );
	}
	$fmt  = $plan['fmt'];
	$gapf = rrt_col_gap_frames( $set['gap'], $fmt['rate'] );
	$art  = rrt_col_art( $col_id );
	$key  = md5(
		wp_json_encode(
			array(
				2,
				array_map(
					function ( $r ) {
						return array( $r['id'], $r['title'], $r['link'], rrt_col_file_key( $r['path'] ), function_exists( 'rrt_feed_facts' ) ? rrt_feed_key( $r['id'] ) : '' );
					},
					$use
				),
				$gapf,
				$col->post_title,
				get_permalink( $col ),
				isset( $art['id'] ) ? $art['id'] : 0,
				$art ? md5( $art['data'] ) : '',
			)
		)
	);
	if ( ! empty( $built['key'] ) && $built['key'] === $key && ! empty( $built['att'] ) && get_attached_file( (int) $built['att'] ) && is_file( get_attached_file( (int) $built['att'] ) ) ) {
		$built['error']    = '';
		$built['left_out'] = wp_list_pluck( $out, 'id' );
		update_post_meta( $col_id, RRT_COL_BUILT, $built );
		return $built;
	}
	if ( get_transient( 'rrt_col_lock_' . $col_id ) ) {
		return new WP_Error( 'rrt_col', __( 'This collection is being joined already. Try again in a minute.', 'foxfire-studio' ) );
	}
	set_transient( 'rrt_col_lock_' . $col_id, 1, 5 * MINUTE_IN_SECONDS );
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	$times = rrt_col_times( wp_list_pluck( $use, 'scan' ), $fmt['rate'], $gapf );
	$parts = array();
	$vtts  = array();
	foreach ( $use as $i => $r ) {
		$own = array();
		if ( function_exists( 'rrt_feed_facts' ) ) {
			$facts = rrt_feed_facts( $r['id'] );
			$own   = isset( $facts['chapters'] ) ? (array) $facts['chapters'] : array();
		}
		$parts[] = array(
			'post'     => $r['id'],
			'title'    => $r['title'],
			'url'      => $r['link'],
			'start'    => $times[ $i ]['start'],
			'end'      => $times[ $i ]['end'],
			'next'     => $times[ $i ]['next'],
			'chapters' => $own,
			'matched'  => $r['matched'] ? 1 : 0,
			'img'      => (int) get_post_thumbnail_id( $r['id'] ),
		);
		$cap  = rrt_reading_captions( $r['id'] );
		$cp   = '' !== $cap['url'] ? ( $cap['id'] ? rrt_att_file( $cap['id'], $r['id'], 'text' ) : rrt_podcast_local_path( $cap['url'], $r['id'], 'text' ) ) : '';
		$text = ( $cp && is_file( $cp ) && filesize( $cp ) < 5242880 ) ? (string) file_get_contents( $cp ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
		/* A matched copy starts a moment later (the encoder's own delay); the captions follow it. */
		$vtts[] = array(
			'vtt'   => $text,
			'start' => $times[ $i ]['start'] + ( $r['matched'] ? 1105 / $fmt['rate'] : 0 ),
			'end'   => $times[ $i ]['end'],
		);
	}
	$total    = $times ? end( $times )['next'] : 0;
	$chapters = count( $parts ) > 1 || ( $parts && count( $parts[0]['chapters'] ) > 1 ) ? rrt_col_chapters( $parts ) : array();
	$title    = html_entity_decode( wp_strip_all_tags( get_the_title( $col ) ), ENT_QUOTES, 'UTF-8' );
	$tag      = rrt_col_id3(
		array(
			'title'    => $title,
			'artist'   => wp_strip_all_tags( get_the_author_meta( 'display_name', $col->post_author ) ),
			'album'    => rrt_show_name(),
			'year'     => get_the_date( 'Y', $col ),
			'ms'       => $total * 1000,
			'url'      => get_permalink( $col ),
			'chapters' => $chapters,
			'art'      => $art,
		)
	);

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$tmp   = wp_tempnam( 'rrt-collection.mp3' );
	$bytes = rrt_col_write(
		$tmp,
		$tag,
		array_map(
			function ( $r ) {
				return array(
					'path'  => $r['path'],
					'start' => $r['scan']['start'],
					'end'   => $r['scan']['end'],
				);
			},
			$use
		),
		rrt_col_silence( $fmt, $gapf )
	);
	if ( false === $bytes ) {
		wp_delete_file( $tmp );
		delete_transient( 'rrt_col_lock_' . $col_id );
		return $keep( __( 'The joined file could not be written. Check there is room on the site.', 'foxfire-studio' ) );
	}
	$slug = sanitize_title( $col->post_name ? $col->post_name : $title );
	$slug = '' !== $slug ? $slug : 'collection-' . $col_id;
	$att  = media_handle_sideload(
		array(
			'name'     => $slug . '.mp3',
			'type'     => 'audio/mpeg',
			'tmp_name' => $tmp,
			'error'    => 0,
			'size'     => $bytes,
		),
		$col_id,
		/* translators: %s: the collection's title. */
		sprintf( __( '%s (collection)', 'foxfire-studio' ), $title )
	);
	if ( is_wp_error( $att ) ) {
		wp_delete_file( $tmp );
		delete_transient( 'rrt_col_lock_' . $col_id );
		return $keep( $att->get_error_message() );
	}
	update_post_meta( $att, '_rrt_col_file', 'audio' );
	$vtt   = rrt_col_vtt_join( $vtts );
	$capid = 0;
	if ( '' !== $vtt ) {
		$vt = wp_tempnam( 'rrt-collection.vtt' );
		file_put_contents( $vt, $vtt ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$c = media_handle_sideload(
			array(
				'name'     => $slug . '.vtt',
				'type'     => 'text/vtt',
				'tmp_name' => $vt,
				'error'    => 0,
				'size'     => strlen( $vtt ),
			),
			$col_id,
			/* translators: %s: the collection's title. */
			sprintf( __( '%s (captions)', 'foxfire-studio' ), $title )
		);
		if ( is_wp_error( $c ) ) {
			wp_delete_file( $vt );
		} else {
			$capid = (int) $c;
			update_post_meta( $capid, '_rrt_col_file', 'captions' );
		}
	}
	/* The collection's reading is set without a Recordings row: a collection is not a new recording. */
	$GLOBALS['rrt_uploading'] = true;
	update_post_meta( $col_id, 'rrt_audio', (int) $att );
	if ( $capid ) {
		update_post_meta( $col_id, 'rrt_captions', $capid );
	} else {
		delete_post_meta( $col_id, 'rrt_captions' );
	}
	$GLOBALS['rrt_uploading'] = false;

	$record = array(
		'key'      => $key,
		'at'       => time(),
		'att'      => (int) $att,
		'vtt'      => $capid,
		'secs'     => round( $total, 3 ),
		'bytes'    => (int) $bytes,
		'fmt'      => $fmt,
		'parts'    => array_map(
			function ( $p ) {
				return array(
					'post'    => $p['post'],
					'title'   => $p['title'],
					'start'   => round( $p['start'], 3 ),
					'end'     => round( $p['end'], 3 ),
					'matched' => $p['matched'],
				);
			},
			$parts
		),
		'chapters' => array_map(
			function ( $c ) use ( $parts ) {
				foreach ( $parts as $p ) {
					if ( $c['start'] >= round( $p['start'], 3 ) - 0.001 && $c['start'] < $p['next'] ) {
						$c['img'] = $p['img'];
					}
				}
				return $c;
			},
			$chapters
		),
		'left_out' => wp_list_pluck( $out, 'id' ),
		'error'    => '',
		'tried'    => time(),
	);
	update_post_meta( $col_id, RRT_COL_BUILT, $record );
	/* The old joined file and captions go, and any matched copy no longer in use. */
	$using = array( (int) $att, $capid );
	foreach ( $use as $r ) {
		if ( $r['matched'] ) {
			$using[] = (int) $r['matched'];
		}
	}
	foreach ( get_children(
		array(
			'post_parent' => $col_id,
			'post_type'   => 'attachment',
			'numberposts' => -1,
		)
	) as $a ) {
		if ( in_array( (int) $a->ID, $using, true ) ) {
			continue;
		}
		$is_match = is_array( get_post_meta( $a->ID, RRT_COL_MATCH, true ) );
		$is_ours  = '' !== (string) get_post_meta( $a->ID, '_rrt_col_file', true );
		/* A matched copy for a reading still in the list but left out for now is kept. */
		if ( $is_match ) {
			$m = get_post_meta( $a->ID, RRT_COL_MATCH, true );
			if ( in_array( (int) $m['post'], $set['items'], true ) && ! in_array( (int) $m['post'], wp_list_pluck( $use, 'id' ), true ) ) {
				continue;
			}
		}
		if ( $is_match || $is_ours ) {
			wp_delete_attachment( $a->ID, true );
		}
	}
	delete_transient( 'rrt_col_lock_' . $col_id );
	if ( function_exists( 'rrt_podcast_purge' ) ) {
		rrt_podcast_purge( $col_id );
	}
	return $record;
}

/*
 * ---------------------------------------------------------------------
 * Keeping collections up to date
 * ---------------------------------------------------------------------
 */

/** Asks for a collection to be joined again at the end of this request. */
function rrt_col_queue( $col_id ) {
	$GLOBALS['rrt_col_queue'][ (int) $col_id ] = true;
}

/** A reading changed: every collection that has it and has been joined is joined again after the page is sent. */
function rrt_col_reading_changed( $meta_id, $post_id, $key ) {
	if ( ! in_array( $key, array( 'rrt_audio', 'rrt_captions' ), true ) && ! ( function_exists( 'rrt_feed_audio_keys' ) && in_array( $key, rrt_feed_audio_keys(), true ) ) ) {
		return;
	}
	if ( rrt_col_is( $post_id ) ) {
		return;
	}
	foreach ( rrt_col_containing( $post_id ) as $cid ) {
		$b = get_post_meta( $cid, RRT_COL_BUILT, true );
		if ( is_array( $b ) && ! empty( $b['att'] ) ) {
			rrt_col_queue( $cid );
		}
	}
}
add_action( 'added_post_meta', 'rrt_col_reading_changed', 30, 3 );
add_action( 'updated_post_meta', 'rrt_col_reading_changed', 30, 3 );
add_action( 'deleted_post_meta', 'rrt_col_reading_changed', 30, 3 );

/** Joins whatever was asked for during this request. */
function rrt_col_run_queue() {
	$q = isset( $GLOBALS['rrt_col_queue'] ) ? array_keys( (array) $GLOBALS['rrt_col_queue'] ) : array();
	$GLOBALS['rrt_col_queue'] = array();
	foreach ( $q as $cid ) {
		rrt_col_build( (int) $cid, 'auto' );
	}
	return $q;
}
add_action(
	'shutdown',
	function () {
		if ( empty( $GLOBALS['rrt_col_queue'] ) ) {
			return;
		}
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}
		rrt_col_run_queue();
	},
	5
);

/*
 * ---------------------------------------------------------------------
 * The edit screen: Readings in this collection
 * ---------------------------------------------------------------------
 */

add_action(
	'add_meta_boxes_' . RRT_COL_TYPE,
	function ( $post ) {
		add_meta_box( 'rrt-collection', __( 'Readings in this collection', 'foxfire-studio' ), 'rrt_col_box', RRT_COL_TYPE, 'normal', 'high' );
	}
);

/** What the box's script needs. */
function rrt_col_box_boot( $post ) {
	$set   = rrt_col_settings( $post->ID );
	$cfg   = rrt_config();
	$built = get_post_meta( $post->ID, RRT_COL_BUILT, true );
	return array(
		'col'     => (int) $post->ID,
		'items'   => $set['items'],
		'gap'     => $set['gap'],
		'plan'    => rrt_col_plan_public( rrt_col_plan( $set['items'], $post->ID, $set['gap'] ) ),
		'built'   => rrt_col_built_public( $post->ID, $built ),
		'rest'    => esc_url_raw( rest_url( 'rrt/v1/' ) ),
		'nonce'   => wp_create_nonce( 'wp_rest' ),
		'encoder' => array(
			'worker'   => rrt_js_url( 'mp3.js' ),
			'url'      => $cfg['mp3_url'],
			'fallback' => $cfg['mp3_url'] !== $cfg['mp3_cdn'] ? $cfg['mp3_cdn'] : '',
			'sha256'   => RRT_MP3_SHA256,
		),
	);
}

/** The last join, as the box shows it. */
function rrt_col_built_public( $col_id, $built ) {
	if ( ! is_array( $built ) || empty( $built ) ) {
		return null;
	}
	$att = ! empty( $built['att'] ) ? (int) $built['att'] : 0;
	$url = $att ? (string) wp_get_attachment_url( $att ) : '';
	return array(
		'url'      => $url,
		'secs'     => isset( $built['secs'] ) ? (float) $built['secs'] : 0,
		'bytes'    => isset( $built['bytes'] ) ? (int) $built['bytes'] : 0,
		'count'    => isset( $built['parts'] ) ? count( (array) $built['parts'] ) : 0,
		'at'       => isset( $built['at'] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $built['at'] ) : '',
		'error'    => isset( $built['error'] ) ? (string) $built['error'] : '',
		'left_out' => array_values( array_map( 'intval', isset( $built['left_out'] ) ? (array) $built['left_out'] : array() ) ),
		'chapters' => isset( $built['chapters'] ) ? count( (array) $built['chapters'] ) : 0,
	);
}

/** The box itself; collections.js fills it in. */
function rrt_col_box( $post ) {
	$set = rrt_col_settings( $post->ID );
	wp_nonce_field( 'rrt_col_save', 'rrt_col_nonce' );
	echo '<p class="rrt-small">' . esc_html__( 'Add readings in the order they should play. Saving joins them into one MP3 on this site, with a chapter for each reading. Each reading is copied exactly as it is on its post, with nothing encoded again.', 'foxfire-studio' ) . '</p>';
	echo '<input type="hidden" name="rrt_col_items" id="rrt-col-items" value="' . esc_attr( implode( ',', $set['items'] ) ) . '">';
	echo '<div class="rrt-col-app" data-rrt-col><p class="rrt-small">' . esc_html__( 'Loading...', 'foxfire-studio' ) . '</p></div>';
	echo '<div class="rrt-col-options">';
	echo '<p><label for="rrt-col-gap">' . esc_html__( 'Gap between readings', 'foxfire-studio' ) . '</label> <select name="rrt_col_gap" id="rrt-col-gap">';
	foreach ( rrt_col_gaps() as $g ) {
		/* translators: %s: seconds. */
		echo '<option value="' . esc_attr( $g ) . '"' . selected( (float) $g, $set['gap'], false ) . '>' . esc_html( 0.0 === (float) $g ? __( 'None', 'foxfire-studio' ) : sprintf( __( '%s s of silence', 'foxfire-studio' ), number_format_i18n( $g, (float) $g === floor( $g ) ? 0 : 1 ) ) ) . '</option>';
	}
	echo '</select></p>';
	echo '<p><label><input type="checkbox" name="rrt_col_feed" value="1"' . checked( $set['feed'], true, false ) . '> ' . esc_html__( 'In the podcast feed, as its own episode, once published', 'foxfire-studio' ) . '</label></p>';
	echo '<p><label for="rrt-col-type">' . esc_html__( 'Episode type', 'foxfire-studio' ) . '</label> <select name="rrt_col_type" id="rrt-col-type">';
	echo '<option value="bonus"' . selected( $set['type'], 'bonus', false ) . '>' . esc_html__( 'Bonus (its readings are also episodes of their own)', 'foxfire-studio' ) . '</option>';
	echo '<option value="full"' . selected( $set['type'], 'full', false ) . '>' . esc_html__( 'Full (new to the feed)', 'foxfire-studio' ) . '</option>';
	echo '</select></p>';
	echo '</div>';
	echo '<script type="application/json" id="rrt-col-boot">' . wp_json_encode( rrt_col_box_boot( $post ), JSON_HEX_TAG | JSON_HEX_AMP ) . '</script>';
}

add_action(
	'admin_enqueue_scripts',
	function ( $hook ) {
		$s = get_current_screen();
		if ( ! $s || RRT_COL_TYPE !== $s->post_type || ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$ver = function ( $rel ) {
			return RRT_VERSION . '.' . filemtime( RRT_DIR . $rel );
		};
		wp_enqueue_style( 'rrt-collections', RRT_URL . 'assets/css/collections.css', array(), $ver( 'assets/css/collections.css' ) );
		/* sound.js measures loudness and levels a matched copy, as it does for takes. */
		wp_enqueue_script( 'rrt-sound', RRT_URL . 'assets/js/sound.js', array( 'wp-i18n' ), $ver( 'assets/js/sound.js' ), true );
		wp_enqueue_script( 'rrt-collections', RRT_URL . 'assets/js/collections.js', array( 'rrt-sound', 'wp-i18n', 'jquery-ui-sortable' ), $ver( 'assets/js/collections.js' ), true );
		wp_set_script_translations( 'rrt-collections', 'foxfire-studio', rrt_languages_dir() );
		wp_set_script_translations( 'rrt-sound', 'foxfire-studio', rrt_languages_dir() );
	}
);

/** Saves the box, then joins the readings. */
function rrt_col_save( $post_id, $post ) {
	if ( ! isset( $_POST['rrt_col_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['rrt_col_nonce'] ) ), 'rrt_col_save' ) ) {
		return;
	}
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	$items = array();
	$raw   = isset( $_POST['rrt_col_items'] ) ? sanitize_text_field( wp_unslash( $_POST['rrt_col_items'] ) ) : '';
	foreach ( explode( ',', $raw ) as $id ) {
		$id = absint( $id );
		if ( $id && $id !== $post_id && ! in_array( $id, $items, true ) && count( $items ) < RRT_COL_MAX && current_user_can( 'read_post', $id ) ) {
			$items[] = $id;
		}
	}
	update_post_meta(
		$post_id,
		RRT_COL_META,
		array(
			'items' => $items,
			'gap'   => rrt_col_clean_gap( isset( $_POST['rrt_col_gap'] ) ? sanitize_text_field( wp_unslash( $_POST['rrt_col_gap'] ) ) : 2 ),
			'feed'  => ! empty( $_POST['rrt_col_feed'] ),
			'type'  => ( isset( $_POST['rrt_col_type'] ) && 'full' === $_POST['rrt_col_type'] ) ? 'full' : 'bonus',
		)
	);
	if ( 'auto-draft' !== $post->post_status ) {
		rrt_col_build( $post_id, 'save' );
	}
}
add_action( 'save_post_' . RRT_COL_TYPE, 'rrt_col_save', 10, 2 );

/* After saving, a line at the top says what the join did. */
add_action(
	'admin_notices',
	function () {
		$s = get_current_screen();
		if ( ! $s || 'post' !== $s->base || RRT_COL_TYPE !== $s->post_type || empty( $_GET['post'] ) || empty( $_GET['message'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$b = get_post_meta( absint( $_GET['post'] ), RRT_COL_BUILT, true ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( is_array( $b ) && ! empty( $b['error'] ) && ! empty( $b['tried'] ) && time() - (int) $b['tried'] < 120 ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( $b['error'] ) . '</p></div>';
		}
	}
);

/* The list of collections shows each one's readings and length. */
add_filter(
	'manage_' . RRT_COL_TYPE . '_posts_columns',
	function ( $cols ) {
		$out = array();
		foreach ( $cols as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'title' === $k ) {
				$out['rrt_col_readings'] = __( 'Readings', 'foxfire-studio' );
				$out['rrt_col_length']   = __( 'Length', 'foxfire-studio' );
			}
		}
		return $out;
	}
);
add_action(
	'manage_' . RRT_COL_TYPE . '_posts_custom_column',
	function ( $col, $post_id ) {
		$b = get_post_meta( $post_id, RRT_COL_BUILT, true );
		if ( 'rrt_col_readings' === $col ) {
			echo (int) count( rrt_col_settings( $post_id )['items'] );
		} elseif ( 'rrt_col_length' === $col ) {
			echo is_array( $b ) && ! empty( $b['secs'] ) ? esc_html( rrt_clock( (int) round( $b['secs'] ) ) ) : esc_html__( 'Not joined yet', 'foxfire-studio' );
		}
	},
	10,
	2
);

/*
 * ---------------------------------------------------------------------
 * REST routes for the box
 * ---------------------------------------------------------------------
 */

/** Posts with a reading that could go in a collection, for the box's search. */
function rrt_col_search( $q, $exclude = array() ) {
	$cfg  = rrt_config();
	$keys = array( 'rrt_audio' );
	if ( ! empty( $cfg['player']['audio_key'] ) ) {
		$keys[] = $cfg['player']['audio_key'];
	}
	$mq = array( 'relation' => 'OR' );
	foreach ( $keys as $k ) {
		$mq[] = array(
			'key'     => $k,
			'compare' => 'EXISTS',
		);
	}
	$args = array(
		'post_type'        => array_values( array_diff( (array) $cfg['post_types'], array( RRT_COL_TYPE ) ) ),
		'post_status'      => array( 'publish', 'draft', 'pending', 'future', 'private' ),
		'numberposts'      => 20,
		'orderby'          => 'date',
		'order'            => 'DESC',
		'meta_query'       => $mq, // phpcs:ignore WordPress.DB.SlowDBQuery
		'post__not_in'     => array_map( 'intval', $exclude ), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams
		'suppress_filters' => false,
	);
	if ( '' !== trim( (string) $q ) ) {
		$args['s'] = (string) $q;
	}
	$out = array();
	foreach ( get_posts( $args ) as $p ) {
		$a = rrt_reading_audio( $p->ID );
		if ( '' === $a['url'] || ! current_user_can( 'read_post', $p->ID ) ) {
			continue;
		}
		$path  = rrt_col_reading_path( $p->ID );
		$scan  = $path ? rrt_col_scan_cached( $path ) : null;
		$out[] = array(
			'id'     => (int) $p->ID,
			'title'  => html_entity_decode( wp_strip_all_tags( get_the_title( $p ) ), ENT_QUOTES, 'UTF-8' ),
			'secs'   => $scan && $scan['ok'] ? round( $scan['secs'], 1 ) : 0,
			'status' => 'publish' === $p->post_status ? '' : (string) get_post_status_object( $p->post_status )->label,
		);
	}
	return $out;
}

add_action(
	'rest_api_init',
	function () {
		$can = function () {
			return is_user_logged_in() && current_user_can( 'edit_posts' );
		};
		$col_ok = function ( $id ) {
			return $id > 0 && rrt_col_is( $id ) && current_user_can( 'edit_post', $id );
		};
		register_rest_route(
			'rrt/v1',
			'/collections/readings',
			array(
				'methods'             => 'GET',
				'permission_callback' => $can,
				'callback'            => function ( $req ) {
					$ex = array_filter( array_map( 'absint', explode( ',', (string) $req['exclude'] ) ) );
					return rest_ensure_response( array( 'readings' => rrt_col_search( sanitize_text_field( (string) $req['search'] ), $ex ) ) );
				},
			)
		);
		register_rest_route(
			'rrt/v1',
			'/collections/plan',
			array(
				'methods'             => 'POST',
				'permission_callback' => $can,
				'callback'            => function ( $req ) use ( $col_ok ) {
					$col = absint( $req['col'] );
					if ( $col && ! $col_ok( $col ) ) {
						return new WP_Error( 'rrt_col', __( 'That collection cannot be changed here.', 'foxfire-studio' ), array( 'status' => 403 ) );
					}
					$ids = array();
					foreach ( (array) $req['ids'] as $id ) {
						$id = absint( $id );
						if ( $id && $id !== $col && ! in_array( $id, $ids, true ) && count( $ids ) < RRT_COL_MAX && current_user_can( 'read_post', $id ) ) {
							$ids[] = $id;
						}
					}
					return rest_ensure_response( rrt_col_plan_public( rrt_col_plan( $ids, $col, rrt_col_clean_gap( $req['gap'] ) ) ) );
				},
			)
		);
		register_rest_route(
			'rrt/v1',
			'/collections/level',
			array(
				'methods'             => 'POST',
				'permission_callback' => $can,
				'callback'            => function ( $req ) {
					$post = absint( $req['post'] );
					$lufs = $req['lufs'];
					if ( ! $post || ! current_user_can( 'edit_post', $post ) ) {
						return new WP_Error( 'rrt_col', __( 'That post cannot be changed here.', 'foxfire-studio' ), array( 'status' => 403 ) );
					}
					if ( ! is_numeric( $lufs ) || (float) $lufs < -70 || (float) $lufs > 0 ) {
						return new WP_Error( 'rrt_col', __( 'That loudness is not a sensible number.', 'foxfire-studio' ), array( 'status' => 400 ) );
					}
					$key = rrt_col_file_key( rrt_col_reading_path( $post ) );
					if ( '' === $key || (string) $req['key'] !== $key ) {
						return new WP_Error( 'rrt_col', __( 'The reading changed while it was being measured. Reload the page to measure it again.', 'foxfire-studio' ), array( 'status' => 409 ) );
					}
					update_post_meta(
						$post,
						RRT_LEVEL_META,
						array(
							'key'  => $key,
							'lufs' => round( (float) $lufs, 2 ),
							'at'   => time(),
						)
					);
					return rest_ensure_response( array( 'ok' => true ) );
				},
			)
		);
		register_rest_route(
			'rrt/v1',
			'/collections/(?P<id>\d+)/match',
			array(
				'methods'             => 'POST',
				'permission_callback' => $can,
				'callback'            => function ( $req ) use ( $col_ok ) {
					return rrt_col_receive_match( $req, $col_ok );
				},
			)
		);
		register_rest_route(
			'rrt/v1',
			'/collections/(?P<id>\d+)/build',
			array(
				'methods'             => 'POST',
				'permission_callback' => $can,
				'callback'            => function ( $req ) use ( $col_ok ) {
					$id = absint( $req['id'] );
					if ( ! $col_ok( $id ) ) {
						return new WP_Error( 'rrt_col', __( 'That collection cannot be changed here.', 'foxfire-studio' ), array( 'status' => 403 ) );
					}
					$r   = rrt_col_build( $id, 'save' );
					$set = rrt_col_settings( $id );
					return rest_ensure_response(
						array(
							'ok'    => ! is_wp_error( $r ),
							'error' => is_wp_error( $r ) ? $r->get_error_message() : '',
							'built' => rrt_col_built_public( $id, get_post_meta( $id, RRT_COL_BUILT, true ) ),
							'plan'  => rrt_col_plan_public( rrt_col_plan( $set['items'], $id, $set['gap'] ) ),
						)
					);
				},
			)
		);
	}
);

/**
 * A matched copy arriving from the browser: an MP3 in the collection's
 * format, made from the reading's current file. Kept as an attachment of the
 * collection; any older copy of the same reading goes.
 */
function rrt_col_receive_match( $req, $col_ok ) {
	$col  = absint( $req['id'] );
	$post = absint( $req['post'] );
	if ( ! $col_ok( $col ) || ! $post || ! in_array( $post, rrt_col_settings( $col )['items'], true ) ) {
		return new WP_Error( 'rrt_col', __( 'That reading is not in this collection.', 'foxfire-studio' ), array( 'status' => 403 ) );
	}
	$key = rrt_col_file_key( rrt_col_reading_path( $post ) );
	if ( '' === $key || (string) $req['key'] !== $key ) {
		return new WP_Error( 'rrt_col', __( 'The reading changed while it was being matched. Reload the page and try again.', 'foxfire-studio' ), array( 'status' => 409 ) );
	}
	$files = $req->get_file_params();
	if ( empty( $files['mp3'] ) || ! empty( $files['mp3']['error'] ) ) {
		return new WP_Error( 'rrt_col', __( 'No file arrived. Try again.', 'foxfire-studio' ), array( 'status' => 400 ) );
	}
	$set  = rrt_col_settings( $col );
	$plan = rrt_col_plan( $set['items'], $col, $set['gap'] );
	$scan = rrt_col_scan( $files['mp3']['tmp_name'] );
	if ( ! $scan['ok'] || rrt_col_fmt_key( $scan ) !== rrt_col_fmt_key( $plan['fmt'] ) ) {
		return new WP_Error( 'rrt_col', __( 'The matched copy is not in the collection\'s format.', 'foxfire-studio' ), array( 'status' => 400 ) );
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$title = html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' );
	$f     = $files['mp3'];
	$f['name'] = sanitize_file_name( sanitize_title( $title ) . '-matched' ) . '.mp3';
	$f['type'] = 'audio/mpeg';
	/* translators: %s: the reading's title. */
	$att = media_handle_sideload( $f, $col, sprintf( __( '%s (matched for a collection)', 'foxfire-studio' ), $title ) );
	if ( is_wp_error( $att ) ) {
		return new WP_Error( 'rrt_col', $att->get_error_message(), array( 'status' => 500 ) );
	}
	$lufs = is_numeric( $req['lufs'] ) ? round( (float) $req['lufs'], 2 ) : null;
	update_post_meta(
		$att,
		RRT_COL_MATCH,
		array(
			'post' => $post,
			'key'  => $key,
			'lufs' => $lufs,
		)
	);
	foreach ( rrt_col_matches( $col ) as $p => $list ) {
		foreach ( $list as $m ) {
			if ( (int) $p === $post && (int) $m['att'] !== (int) $att ) {
				wp_delete_attachment( $m['att'], true );
			}
		}
	}
	$r = rrt_col_build( $col, 'save' );
	return rest_ensure_response(
		array(
			'ok'    => ! is_wp_error( $r ),
			'error' => is_wp_error( $r ) ? $r->get_error_message() : '',
			'built' => rrt_col_built_public( $col, get_post_meta( $col, RRT_COL_BUILT, true ) ),
			'plan'  => rrt_col_plan_public( rrt_col_plan( $set['items'], $col, $set['gap'] ) ),
		)
	);
}

/*
 * ---------------------------------------------------------------------
 * The collection's page
 * ---------------------------------------------------------------------
 */

/** The readings in a collection's page: each with its length, a play button and a link to its post. */
function rrt_col_list_html( $col_id ) {
	$b = get_post_meta( $col_id, RRT_COL_BUILT, true );
	if ( ! is_array( $b ) || empty( $b['parts'] ) ) {
		return '';
	}
	$play = function_exists( 'rrt_player_visible' ) && rrt_player_visible();
	$html = '<section class="rrt-col-list"><h2>' . esc_html__( 'In this collection', 'foxfire-studio' ) . '</h2><ol>';
	foreach ( $b['parts'] as $p ) {
		$post = get_post( (int) $p['post'] );
		$len  = rrt_clock( (int) round( (float) $p['end'] - (float) $p['start'] ) );
		$html .= '<li>';
		if ( $play ) {
			/* translators: %s: the reading's title. */
			$html .= '<button type="button" class="rrt-col-play" data-rrt-seek="' . esc_attr( number_format( (float) $p['start'], 3, '.', '' ) ) . '" aria-label="' . esc_attr( sprintf( __( 'Play %s', 'foxfire-studio' ), $p['title'] ) ) . '">&#9654;</button> ';
		}
		$title = esc_html( $p['title'] );
		if ( $post && 'publish' === $post->post_status ) {
			$title = '<a href="' . esc_url( get_permalink( $post ) ) . '">' . $title . '</a>';
		}
		$html .= '<span class="rrt-col-title">' . $title . '</span> <span class="rrt-col-at">' . esc_html( rrt_clock( (int) floor( (float) $p['start'] ) ) ) . '</span> <span class="rrt-col-len">(' . esc_html( $len ) . ')</span></li>';
	}
	return $html . '</ol></section>';
}

add_filter(
	'the_content',
	function ( $content ) {
		if ( ! is_singular( RRT_COL_TYPE ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$top = '';
		$cfg = rrt_config();
		if ( 'top' === $cfg['player']['position'] && function_exists( 'rrt_player_placed' ) && rrt_player_visible() && ! rrt_player_placed_by_hand( get_post() ) ) {
			$top = rrt_player_placed( null, 'content' );
		}
		wp_enqueue_style( 'rrt-player', RRT_URL . 'assets/css/player.css', array(), RRT_VERSION . '.' . filemtime( RRT_DIR . 'assets/css/player.css' ) );
		return $top . $content . rrt_col_list_html( get_the_ID() );
	},
	12
);

/*
 * ---------------------------------------------------------------------
 * In the podcast feed
 * ---------------------------------------------------------------------
 */

/** Whether a collection is in the feed: published, joined and with In the podcast feed ticked. */
function rrt_col_in_feed( $post ) {
	$post = get_post( $post );
	if ( ! $post || RRT_COL_TYPE !== $post->post_type || 'publish' !== $post->post_status || '' !== (string) $post->post_password ) {
		return false;
	}
	if ( ! rrt_col_settings( $post->ID )['feed'] ) {
		return false;
	}
	$a = rrt_reading_audio( $post->ID );
	return '' !== $a['url'];
}

/** The collections in the feed. */
function rrt_col_feed_posts() {
	$out = array();
	foreach ( get_posts(
		array(
			'post_type'        => RRT_COL_TYPE,
			'post_status'      => 'publish',
			'has_password'     => false,
			'numberposts'      => defined( 'RRT_PODCAST_MAX' ) ? RRT_PODCAST_MAX : 300,
			'orderby'          => 'date',
			'order'            => 'DESC',
			'suppress_filters' => false,
		)
	) as $p ) {
		if ( rrt_col_in_feed( $p ) ) {
			$out[] = $p;
		}
	}
	return $out;
}

/** The chapters file's chapters for a collection: the join's, with each reading's picture. */
function rrt_col_feed_chapters( $col_id ) {
	$b = get_post_meta( $col_id, RRT_COL_BUILT, true );
	if ( ! is_array( $b ) || empty( $b['chapters'] ) ) {
		return array();
	}
	$out = array();
	foreach ( $b['chapters'] as $c ) {
		if ( ! empty( $c['img'] ) ) {
			$src      = wp_get_attachment_image_src( (int) $c['img'], 'large' );
			$c['img'] = $src ? (string) $src[0] : '';
		} else {
			$c['img'] = '';
		}
		$out[] = $c;
	}
	return $out;
}
