<?php
/**
 * Storage offload (1.23.0), run inside the test WordPress with the stand-in
 * storage (tests/wp/storage.py) and the test site's own server running:
 *   OFF_PORT=8090 php wp-cli.phar --path=<wordpress> eval-file tests/php/offload.php
 * Prints one line per check and exits 1 if any fail. Leaves nothing behind.
 */

$GLOBALS['of_pass'] = 0;
$GLOBALS['of_fail'] = array();

function of_t( $ok, $name, $why = "" ) {
	if ( $ok ) {
		++$GLOBALS['of_pass'];
		echo "ok   $name\n";
	} else {
		$GLOBALS['of_fail'][] = $name;
		echo "FAIL $name $why\n";
	}
}

$of_port = (int) ( getenv( 'OFF_PORT' ) ? getenv( 'OFF_PORT' ) : 8090 );
$of_st   = 'http://127.0.0.1:' . $of_port;

function of_state() {
	$r = wp_remote_get( $GLOBALS['of_st'] . '/admin/state', array( 'timeout' => 20 ) );
	return json_decode( (string) wp_remote_retrieve_body( $r ), true );
}
function of_admin( $q ) {
	wp_remote_get( $GLOBALS['of_st'] . '/admin/' . $q, array( 'timeout' => 20 ) );
}
$GLOBALS['of_st'] = $of_st;
of_admin( 'reset' );

add_filter(
	'rrt_offload_bunny_api',
	function () use ( $of_st ) {
		return $of_st . '/bunny';
	}
);
add_filter(
	'rrt_offload_purge_api',
	function () use ( $of_st ) {
		return $of_st . '/purge';
	}
);

wp_set_current_user( 1 );
$of_was_pc  = get_option( 'rrt_podcast', null );
$of_was_set = get_option( 'rrt_settings', array() );
$GLOBALS['of_clean'] = array( 'posts' => array(), 'atts' => array() );
register_shutdown_function(
	function () use ( $of_was_pc ) {
		foreach ( $GLOBALS['of_clean']['posts'] as $p ) {
			wp_delete_post( $p, true );
		}
		foreach ( $GLOBALS['of_clean']['atts'] as $a ) {
			wp_delete_attachment( $a, true );
		}
		if ( null === $of_was_pc ) {
			delete_option( 'rrt_podcast' );
		} else {
			update_option( 'rrt_podcast', $of_was_pc );
		}
		/* The members folder the Members only check made, when nothing else is in it. */
		$d = rrt_mfiles_dir();
		if ( is_dir( $d ) && ! array_filter( iterator_to_array( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $d, FilesystemIterator::SKIP_DOTS ) ) ), function ( $f ) { return ! in_array( $f->getFilename(), array( 'index.php', '.htaccess' ), true ); } ) ) {
			foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $d, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $f ) {
				$f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
			}
			rmdir( $d );
		}
		delete_option( 'rrt_offload' );
		delete_option( 'rrt_offload_files' );
		of_admin( 'reset' );
	}
);

/** A file in the Media Library, as a reading's file is. */
function of_attach( $name, $body, $mime ) {
	$up   = wp_upload_dir();
	$path = trailingslashit( $up['path'] ) . wp_unique_filename( $up['path'], $name );
	file_put_contents( $path, $body );
	$id = wp_insert_attachment(
		array(
			'post_mime_type' => $mime,
			'post_title'     => $name,
			'post_status'    => 'inherit',
		),
		$path
	);
	$GLOBALS['of_clean']['atts'][] = $id;
	return $id;
}

/** A published post with a reading and captions. */
function of_post( $title, $status = 'publish' ) {
	$id  = wp_insert_post(
		array(
			'post_title'   => $title,
			'post_content' => 'Words for the test.',
			'post_status'  => $status,
		)
	);
	$GLOBALS['of_clean']['posts'][] = $id;
	$mp3 = of_attach( sanitize_title( $title ) . '.mp3', str_repeat( "\xFF\xFB\x90\x64" . random_bytes( 412 ), 200 ), 'audio/mpeg' );
	$vtt = of_attach( sanitize_title( $title ) . '.vtt', "WEBVTT\n\n00:00.000 --> 00:01.000\nWords for the test.\n", 'text/vtt' );
	update_post_meta( $id, 'rrt_audio', $mp3 );
	update_post_meta( $id, 'rrt_captions', $vtt );
	return array( $id, $mp3, $vtt );
}

function of_set( $vals ) {
	rrt_off_save( array_merge( rrt_off_settings(), $vals ) );
	rrt_off_forget();
}

/** Runs Check this setup as the screen does (the browser's part counted as passed). */
function of_check( $cors = true ) {
	$r = rrt_off_check_start();
	$ok = rrt_off_check_done( isset( $r['key'] ) ? $r['key'] : '', isset( $r['hash'] ) ? $r['hash'] : '', ! empty( $r['ok'] ), $cors, $r['steps'] );
	return array( $ok, $r );
}

/* ---- Signing ---- */
$of_aws = array_merge(
	rrt_off_settings(),
	array(
		'mode'        => 's3',
		's3_endpoint' => 'https://s3.amazonaws.com',
		's3_region'   => 'us-east-1',
		's3_bucket'   => 'examplebucket',
		's3_key'      => 'AKIAIOSFODNN7EXAMPLE',
		's3_secret'   => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
	)
);
$of_sig = rrt_off_s3_sign( 'GET', 'test.txt', array(), '', array( 'Range' => 'bytes=0-9' ), $of_aws, gmmktime( 0, 0, 0, 5, 24, 2013 ) );
of_t( false !== strpos( $of_sig['headers']['authorization'], 'Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41' ), 'signing matches AWS\'s published Signature Version 4 example' );
of_t( 'https://examplebucket.s3.amazonaws.com/test.txt' === $of_sig['url'], 'Amazon\'s endpoints take the bucket in the host name' );
$of_r2 = rrt_off_s3_sign( 'PUT', 'foxfire/ab/a b.mp3', array(), 'x', array(), array_merge( $of_aws, array( 's3_endpoint' => 'https://acct.r2.cloudflarestorage.com', 's3_bucket' => 'readings' ) ) );
of_t( 'https://acct.r2.cloudflarestorage.com/readings/foxfire/ab/a%20b.mp3' === $of_r2['url'], 'other endpoints take it in the path, with the key\'s spaces encoded' );
of_t( 'application/octet-stream' === $of_r2['headers']['content-type'], 'a body always gets a type (a form type would be read as empty)' );

/* ---- Which files ---- */
of_t( 'audio/mpeg' === rrt_off_type( '2026/11/a.mp3' ) && 'text/vtt' === rrt_off_type( '2026/11/a.vtt' ) && 'application/json' === rrt_off_type( 'rrt-sponsored/1/a.json' ), 'readings, captions and sponsored chapters may go' );
$of_priv = true;
foreach ( array( 'rrt-members/a.mp3', 'rrt-kept/a.mp3', 'rrt-audiobook/a.mp3', 'rrt-sent/a.wav', 'rrt-shares/a.mp3', 'rrt-whisper/x.json', 'rrt-assets/mp3.wasm', '2026/11/a.php', '2026/11/a.jpg', '../a.mp3' ) as $of_x ) {
	$of_priv = $of_priv && '' === rrt_off_type( $of_x );
}
of_t( $of_priv, 'files for members, kept aside, audiobooks, sent and shared takes, the studio\'s files, pages and pictures never go' );
$of_up = wp_upload_dir( null, false );
of_t( '2026/11/a b.mp3' === rrt_off_rel( $of_up['baseurl'] . '/2026/11/a%20b.mp3' ) && '2026/11/a.mp3' === rrt_off_rel( preg_replace( '#^http:#', 'https:', $of_up['baseurl'] ) . '/2026/11/a.mp3?x=1' ) && '' === rrt_off_rel( 'https://elsewhere.example/2026/11/a.mp3' ), 'addresses on this site\'s uploads are found, others are left alone' );

/* ---- Off by default ---- */
list( $of_a, $of_a_mp3, $of_a_vtt ) = of_post( 'Offload reading A' );
list( $of_b, $of_b_mp3 ) = of_post( 'Offload reading B' );
list( $of_c ) = of_post( 'Offload draft C', 'draft' );
$of_a_url = wp_get_attachment_url( $of_a_mp3 );
of_t( ! rrt_off_on() && $of_a_url === rrt_off_url( $of_a_url ), 'off by default: every address stays on this site' );
of_t( (bool) rrt_off_missing(), 'and the screen lists what is missing' );

/* ---- S3 ---- */
of_set(
	array(
		'mode'        => 's3',
		's3_endpoint' => $of_st . '/s3',
		's3_region'   => 'auto',
		's3_bucket'   => 'readings',
		's3_key'      => 'FFTESTKEY',
		's3_secret'   => 'wrongsecret',
		's3_base'     => $of_st . '/pub',
	)
);
list( $of_ok, $of_r ) = of_check();
of_t( ! $of_ok && false !== strpos( $of_r['steps'][0]['text'], 'refused (403)' ), 'a wrong secret fails Check this setup, with the storage\'s answer' );
of_set( array( 's3_secret' => 'fftestsecret' ) );
list( $of_ok, $of_r ) = of_check( false );
of_t( ! $of_ok && 3 === count( $of_r['steps'] ) && $of_r['steps'][2]['ok'], 'the site\'s part passes (upload, the public address, a byte range)' );
of_t( ! rrt_off_on(), 'but the check fails while this browser cannot read the file (CORS)' );
$of_s = of_state();
of_t( ! array_filter( array_keys( $of_s['objects'] ), function ( $k ) { return false !== strpos( $k, 'check-' ); } ), 'and the test file is deleted' );
list( $of_ok ) = of_check( true );
of_t( $of_ok && rrt_off_target() === rrt_off_settings()['passed'], 'with CORS it passes' );
of_t( ! rrt_off_on(), 'passing alone does not switch it on' );
of_set( array( 'on' => true ) );
of_t( rrt_off_on(), 'switched on' );

$of_w = rrt_off_wanted();
$of_a_rel = rrt_off_rel( $of_a_url );
of_t( isset( $of_w[ $of_a_rel ] ) && isset( $of_w[ rrt_off_rel( wp_get_attachment_url( $of_a_vtt ) ) ] ) && $of_a === $of_w[ $of_a_rel ], 'a published reading and its captions are wanted outside' );
of_t( ! in_array( $of_c, $of_w, true ), 'a draft\'s are not' );
of_t( $of_a_url === rrt_off_url( $of_a_url ), 'until a file has a checked copy it stays on this site' );

$of_sync = rrt_off_sync( 30.0, 50 );
of_t( $of_sync['done'] >= 4 && ! $of_sync['errors'], 'the copies go up and are checked' );
$of_s   = of_state();
$of_f   = rrt_off_files();
$of_rec = $of_f[ $of_a_rel ];
$of_obj = isset( $of_s['objects'][ $of_rec['k'] ] ) ? $of_s['objects'][ $of_rec['k'] ] : null;
of_t( $of_obj && hash_file( 'sha256', rrt_off_path( $of_a_rel ) ) === $of_obj['sha'], 'the copy is the same bytes as the file' );
of_t( preg_match( '#^foxfire/[0-9a-f]{10}/offload-reading-a(-\d+)?\.mp3$#', $of_rec['k'] ) && substr( $of_obj['sha'], 0, 10 ) === $of_rec['h'], 'its key is named by its content, with the file\'s own name' );
of_t( $of_obj && 'audio/mpeg' === $of_obj['type'] && RRT_OFF_CACHE === $of_obj['cache'], 'with the right type and a day\'s cache time' );
$of_pub = $of_st . '/pub/' . $of_rec['k'];
of_t( $of_pub === rrt_off_url( $of_a_url ), 'the reading\'s address is now the outside copy' );
of_t( $of_pub === rrt_off_url( $of_a_url, 'player' ), 'for the player too' );
of_set( array( 'use' => 'feeds' ) );
of_t( $of_a_url === rrt_off_url( $of_a_url, 'player' ) && $of_pub === rrt_off_url( $of_a_url ), 'with Podcast apps only, the player keeps this site\'s file' );
of_set( array( 'use' => 'all' ) );
$GLOBALS['post'] = get_post( $of_a );
$of_html = rrt_player( $of_a );
of_t( false !== strpos( $of_html, 'src="' . esc_url( $of_pub ) . '"' ) && ( false === strpos( $of_html, 'rrt-download' ) || false !== strpos( $of_html, 'href="' . esc_url( $of_a_url ) . '" download' ) ), 'the player plays the outside copy, and Download (when shown) keeps this site\'s file' );
of_t( false !== strpos( $of_html, esc_url( wp_get_attachment_url( $of_a_vtt ) ) ), 'and its captions stay on this site (a track from elsewhere would need CORS on the audio itself)' );

/* The feed. */
update_option( 'rrt_podcast', array_merge( rrt_podcast_settings(), array( 'on' => true ) ) );
ob_start();
rrt_podcast_render();
$of_feed = ob_get_clean();
of_t( false !== strpos( $of_feed, 'url="' . esc_url( $of_pub ) . '"' ), 'the feed\'s enclosure is the outside copy' );
of_t( false !== strpos( $of_feed, ':reading:' . $of_a . '</guid>' ), 'and the episode keeps its GUID' );
of_t( false !== strpos( $of_feed, esc_url( $of_st . '/pub/' . $of_f[ rrt_off_rel( wp_get_attachment_url( $of_a_vtt ) ) ]['k'] ) ), 'its transcript is the outside copy too' );
update_option( 'rrt_podcast', array_merge( rrt_podcast_settings(), array( 'count' => true ) ) );
ob_start();
rrt_podcast_render();
$of_feed = ob_get_clean();
of_t( false !== strpos( $of_feed, 'url="' . esc_url( rrt_dl_url( $of_a, $of_a_url ) ) . '"' ), 'with Count downloads, the enclosure stays the short address on this site' );
$of_t = rrt_dl_target( $of_a );
of_t( $of_pub === rrt_off_url( $of_t['url'] ), 'which sends apps on to the outside copy' );
update_option( 'rrt_podcast', array_merge( rrt_podcast_settings(), array( 'count' => false ) ) );

/* A file changed where it is. */
$of_path = rrt_off_path( $of_a_rel );
file_put_contents( $of_path, str_repeat( "\xFF\xFB\x90\x64" . random_bytes( 412 ), 210 ) );
touch( $of_path, time() + 5 );
clearstatcache();
of_t( $of_a_url === rrt_off_url( $of_a_url ), 'a file changed in place is served from this site until its new copy is checked' );
rrt_off_sync( 30.0, 50 );
$of_f2 = rrt_off_files();
$of_s  = of_state();
of_t( $of_f2[ $of_a_rel ]['k'] !== $of_rec['k'] && ! isset( $of_s['objects'][ $of_rec['k'] ] ) && isset( $of_s['objects'][ $of_f2[ $of_a_rel ]['k'] ] ), 'its new copy gets a new key and the old copy goes' );
$of_rec = $of_f2[ $of_a_rel ];

/* A new reading on the post: the old file's copy stays two days. */
$of_a2 = of_attach( 'offload-reading-a-new.mp3', str_repeat( "\xFF\xFB\x90\x64" . random_bytes( 412 ), 150 ), 'audio/mpeg' );
update_post_meta( $of_a, 'rrt_audio', $of_a2 );
rrt_off_forget();
$of_now = time();
rrt_off_sync( 30.0, 50, $of_now );
$of_s = of_state();
of_t( isset( $of_s['objects'][ $of_rec['k'] ] ) && ! empty( rrt_off_files()[ $of_a_rel ]['gone'] ), 'a replaced reading\'s copy stays while apps may still be playing it' );
rrt_off_sync( 30.0, 50, $of_now + RRT_OFF_GRACE + 60 );
$of_s = of_state();
of_t( ! isset( $of_s['objects'][ $of_rec['k'] ] ) && ! isset( rrt_off_files()[ $of_a_rel ] ), 'and goes two days later' );
of_t( false !== strpos( rrt_off_url( wp_get_attachment_url( $of_a2 ) ), '/pub/foxfire/' ), 'the new reading has its copy' );

/* A reading set to Members only: its copy goes at once, and is cleared from the CDN. */
of_set( array( 'purge_key' => 'purgekey' ) );
$of_b_rel = rrt_off_rel( wp_get_attachment_url( $of_b_mp3 ) );
$of_b_rec = rrt_off_files()[ $of_b_rel ];
$of_mwas  = rrt_podcast_settings();
update_option( 'rrt_podcast', array_merge( $of_mwas, array( 'm_on' => true ) ) );
update_post_meta( $of_b, RRT_ACCESS_META, array( 'who' => 'members' ) );
rrt_access_settle( $of_b );
rrt_off_forget();
rrt_off_sync( 30.0, 50 );
$of_s = of_state();
of_t( ! isset( $of_s['objects'][ $of_b_rec['k'] ] ) && ! isset( rrt_off_files()[ $of_b_rel ] ), 'a reading set to Members only loses its outside copy at once' );
of_t( in_array( $of_st . '/pub/' . $of_b_rec['k'], $of_s['purges'], true ), 'and it is cleared from the CDN with the bunny.net API key' );
$of_bu = rrt_reading_audio( $of_b )['url'];
of_t( $of_bu === rrt_off_url( $of_bu ), 'its file for members never goes outside' );
delete_post_meta( $of_b, RRT_ACCESS_META );
rrt_access_settle( $of_b );
update_option( 'rrt_podcast', $of_mwas );
of_set( array( 'purge_key' => '' ) );

/* Unpublished: at once too. */
rrt_off_sync( 30.0, 50 );
$of_b_rec = rrt_off_files()[ rrt_off_rel( rrt_reading_audio( $of_b )['url'] ) ];
wp_update_post( array( 'ID' => $of_b, 'post_status' => 'draft' ) );
rrt_off_forget();
rrt_off_sync( 30.0, 50 );
$of_s = of_state();
of_t( ! isset( $of_s['objects'][ $of_b_rec['k'] ] ), 'an unpublished reading loses its outside copy at once' );

/* In parts. */
add_filter(
	'rrt_offload_part_over',
	function () {
		return 10 * 1024 * 1024;
	}
);
$of_big = of_attach( 'offload-big.mp3', str_repeat( random_bytes( 1024 ), 20 * 1024 ), 'audio/mpeg' );
update_post_meta( $of_a, 'rrt_audio', $of_big );
rrt_off_forget();
$of_sync = rrt_off_sync( 60.0, 50 );
$of_big_rel = rrt_off_rel( wp_get_attachment_url( $of_big ) );
$of_s = of_state();
$of_bk = rrt_off_files()[ $of_big_rel ]['k'];
of_t( ! $of_sync['errors'] && isset( $of_s['objects'][ $of_bk ] ) && 20 * 1024 * 1024 === $of_s['objects'][ $of_bk ]['size'] && hash_file( 'sha256', rrt_off_path( $of_big_rel ) ) === $of_s['objects'][ $of_bk ]['sha'], 'a large file goes up in parts, whole and the same' );
of_t( 'audio/mpeg' === $of_s['objects'][ $of_bk ]['type'], 'keeping its type' );
update_post_meta( $of_a, 'rrt_audio', $of_a2 );
rrt_off_forget();

/* When the storage stops answering. */
of_admin( 'set?fail=1' );
rrt_off_health();
rrt_off_health();
of_t( rrt_off_on(), 'two failed checks change nothing yet' );
rrt_off_health();
of_t( ! rrt_off_on() && rrt_off_settings()['down'] && wp_get_attachment_url( $of_a2 ) === rrt_off_url( wp_get_attachment_url( $of_a2 ) ), 'after three failed checks every address is this site\'s again' );
of_admin( 'set?fail=0' );
rrt_off_health();
of_t( rrt_off_on() && false !== strpos( rrt_off_url( wp_get_attachment_url( $of_a2 ) ), '/pub/' ), 'once it answers again, the copies are used again' );

/* A sponsored copy (1.22.0) is wanted with its captions and chapters. */
$of_spd = rrt_spot_dir() . '/' . $of_a;
wp_mkdir_p( $of_spd );
foreach ( array( 'mp3', 'vtt', 'json' ) as $of_ext ) {
	file_put_contents( $of_spd . '/offload-reading-a-sp1234abcd.' . $of_ext, 'mp3' === $of_ext ? str_repeat( "\xFF\xFB\x90\x64" . random_bytes( 412 ), 20 ) : ( 'vtt' === $of_ext ? "WEBVTT\n" : '{}' ) );
}
update_post_meta(
	$of_a,
	RRT_SPOT_BUILT,
	array(
		'rel'   => $of_a . '/offload-reading-a-sp1234abcd.mp3',
		'vtt'   => $of_a . '/offload-reading-a-sp1234abcd.vtt',
		'json'  => $of_a . '/offload-reading-a-sp1234abcd.json',
		'parts' => array(),
	)
);
rrt_off_forget();
$of_w = rrt_off_wanted();
of_t( isset( $of_w[ RRT_SPOT_DIR . '/' . $of_a . '/offload-reading-a-sp1234abcd.mp3' ] ) && isset( $of_w[ RRT_SPOT_DIR . '/' . $of_a . '/offload-reading-a-sp1234abcd.json' ] ), 'a reading\'s sponsored copy and its chapters file are wanted outside' );
rrt_off_sync( 30.0, 50 );
of_t( false !== strpos( rrt_off_url( rrt_spot_url( $of_a . '/offload-reading-a-sp1234abcd.mp3' ) ), '/pub/foxfire/' ), 'and served from there' );
delete_post_meta( $of_a, RRT_SPOT_BUILT );
foreach ( glob( $of_spd . '/*' ) as $of_x ) {
	unlink( $of_x );
}
rmdir( $of_spd );
@rmdir( rrt_spot_dir() );
rrt_off_forget();
rrt_off_sync( 30.0, 50 );
of_t( ! array_filter( array_keys( rrt_off_files() ), function ( $k ) { return 0 === strpos( $k, RRT_SPOT_DIR ); } ), 'when the sponsored copy goes, its outside copies go too' );

/* The screen never shows a secret. */
ob_start();
rrt_off_section();
$of_screen = ob_get_clean();
of_t( false === strpos( $of_screen, 'fftestsecret' ) && false !== strpos( $of_screen, 'Saved. Paste a new one to replace it.' ), 'the screen never shows a saved secret' );
of_t( false !== strpos( rrt_listen_privacy_text(), '127.0.0.1' ), 'the privacy paragraph names where the readings come from' );

/* Switching off. */
of_set( array( 'on' => false ) );
$of_s = of_state();
of_t( wp_get_attachment_url( $of_a2 ) === rrt_off_url( wp_get_attachment_url( $of_a2 ) ) && count( $of_s['objects'] ) > 0, 'switched off: addresses come back here at once and the copies stay outside' );
of_t( '' === rrt_off_privacy_text(), 'and the privacy paragraph drops the line' );
of_set( array( 'on' => true ) );

/* ---- Bunny Storage ---- */
of_set(
	array(
		'mode'   => 'bunny',
		'b_zone' => 'ff-test',
		'b_pass' => 'nope',
		'b_base' => $of_st . '/pub',
	)
);
of_t( ! rrt_off_on(), 'a new target needs a new check' );
list( $of_ok, $of_r ) = of_check();
of_t( ! $of_ok && false !== strpos( $of_r['steps'][0]['text'], 'refused (401)' ), 'Bunny refuses a wrong password' );
of_set( array( 'b_pass' => 'bunnypass' ) );
list( $of_ok ) = of_check();
of_t( $of_ok && rrt_off_on(), 'Bunny Storage passes the check' );
of_admin( 'reset' );
$of_sync = rrt_off_sync( 30.0, 50 );
$of_s    = of_state();
$of_brec = rrt_off_files()[ rrt_off_rel( wp_get_attachment_url( $of_a2 ) ) ];
of_t( ! $of_sync['errors'] && isset( $of_s['objects'][ $of_brec['k'] ] ) && $of_brec['t'] === rrt_off_target(), 'the copies go to Bunny (with a checksum it checks)' );
of_t( false !== strpos( implode( "\n", $of_s['log'] ), 'PUT /bunny/ff-test/foxfire/' ), 'through Bunny\'s own API' );
$of_vrel = rrt_off_rel( wp_get_attachment_url( $of_a_vtt ) );
$of_vrec = rrt_off_files()[ $of_vrel ];
of_t( ! empty( $of_vrec['local'] ) && empty( $of_vrec['up'] ) && ! isset( $of_s['objects'][ $of_vrec['k'] ] ), 'captions Bunny would serve as a plain download stay on the site, and their copy goes' );
of_t( wp_get_attachment_url( $of_a_vtt ) === rrt_off_url( wp_get_attachment_url( $of_a_vtt ) ), 'so their address stays this site\'s' );
$of_sync = rrt_off_sync( 30.0, 50 );
of_t( 0 === $of_sync['waiting'] && ! $of_sync['errors'] && 0 === $of_sync['done'], 'and they are not tried again while unchanged' );
$of_ss = rrt_off_status();
of_t( $of_ss['local'] >= 1 && ! $of_ss['waiting'], 'the screen counts them as staying, not waiting', wp_json_encode( $of_ss ) );

/* ---- A CDN in front of the site ---- */
of_set(
	array(
		'mode'     => 'cdn',
		'cdn_base' => $of_st . '/cdn',
	)
);
of_admin( 'reset' );
list( $of_ok, $of_r ) = of_check();
of_t( $of_ok, 'a CDN in front of the site passes the check' );
of_t( ! is_dir( rrt_off_path( RRT_OFF_CHECK_DIR ) ) || ! glob( rrt_off_path( RRT_OFF_CHECK_DIR ) . '/*.mp3' ), 'its test file is deleted from the site' );
$of_sync = rrt_off_sync( 30.0, 50 );
$of_s    = of_state();
$of_cdn  = rrt_off_url( wp_get_attachment_url( $of_a2 ) );
of_t( ! $of_sync['errors'] && 0 === strpos( $of_cdn, $of_st . '/cdn/' ) && preg_match( '#\?v=[0-9a-f]{10}$#', $of_cdn ), 'the CDN\'s address is the file\'s own path with a code that changes with the file' );
of_t( ! array_filter( array_keys( $of_s['objects'] ) ), 'nothing is uploaded through a CDN' );
of_t( (bool) array_filter( $of_s['cdn'], function ( $p ) { return false !== strpos( $p, 'offload-reading-a-new' ); } ), 'the CDN copied the file from the site' );

/* Delete the outside copies. */
of_set( array( 'mode' => 'bunny' ) );
of_check();
rrt_off_sync( 30.0, 50 );
$of_back = rrt_off_files()[ rrt_off_rel( wp_get_attachment_url( $of_a2 ) ) ];
of_t( ! empty( $of_back['ok'] ) && isset( of_state()['objects'][ $of_back['k'] ] ), 'back on Bunny, the copies go up again' );
$of_n = count( rrt_off_files() );
while ( rrt_off_clear_step( 5 ) > 0 ) {
	if ( --$of_n < -5 ) {
		break;
	}
}
$of_s = of_state();
of_t( ! rrt_off_files() && ! array_filter( array_keys( $of_s['objects'] ), function ( $k ) { return 0 === strpos( $k, 'foxfire/' ); } ), 'Delete the outside copies empties the storage of every copy' );

/* Keys from wp-config.php. */
define( 'FOXFIRE_STORAGE_SECRET', 'from-config' );
of_t( 'from-config' === rrt_off_secret( 'secret' ), 'a secret set in wp-config.php wins' );
ob_start();
rrt_off_section();
of_t( false !== strpos( ob_get_clean(), 'Set in wp-config.php.' ), 'and the screen says so instead of a box' );

echo "\n" . $GLOBALS['of_pass'] . ' passed, ' . count( $GLOBALS['of_fail'] ) . " failed\n";
if ( $GLOBALS['of_fail'] ) {
	exit( 1 );
}
