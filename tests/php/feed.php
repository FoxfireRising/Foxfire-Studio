<?php
/**
 * The full Podcasting 2.0 feed (1.10.0), on the server, run inside the test WordPress:
 *   php wp-cli.phar --path=<wordpress> eval-file tests/php/feed.php
 * Chapters read from ID3 tags, the chapters file, text lines, the host and
 * support link, what the feed keeps about each reading and when it works
 * it out again, the soundbite, and the pings (sent to a stand-in, never to
 * the real services). Prints one line per check and exits 1 if any fail.
 * Leaves nothing behind.
 */

$GLOBALS['ff_pass'] = 0;
$GLOBALS['ff_fail'] = array();

function ff_t( $ok, $name, $detail = '' ) {
	if ( $ok ) {
		++$GLOBALS['ff_pass'];
		echo "ok   $name\n";
	} else {
		$GLOBALS['ff_fail'][] = $name;
		echo "FAIL $name" . ( '' !== $detail ? ": $detail" : '' ) . "\n";
	}
}

wp_set_current_user( 1 );
$ff_was_podcast = get_option( 'rrt_podcast', false );
$ff_was_ping    = get_option( 'rrt_podcast_ping', false );

/* Every request the pings make goes to this stand-in instead. */
$GLOBALS['ff_sent'] = array();
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		$GLOBALS['ff_sent'][] = array(
			'url'    => $url,
			'method' => isset( $args['method'] ) ? $args['method'] : 'GET',
			'body'   => isset( $args['body'] ) ? $args['body'] : null,
			'ua'     => isset( $args['user-agent'] ) ? $args['user-agent'] : '',
		);
		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array(
				'code'    => false !== strpos( $url, 'podcastindex' ) ? 200 : 204,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);

$ff_seed_args = $args;
$args         = array();
ob_start();
include dirname( __DIR__ ) . '/wp/feed.php';
$ff_ids = json_decode( ob_get_clean(), true );
$args   = $ff_seed_args;
$ff_post  = (int) $ff_ids['post'];
$ff_plain = (int) $ff_ids['plain'];

register_shutdown_function(
	function () use ( $ff_was_podcast, $ff_was_ping, $ff_post, $ff_plain ) {
		$GLOBALS['rrt_ping_now'] = false;
		false === $ff_was_podcast ? delete_option( 'rrt_podcast' ) : update_option( 'rrt_podcast', $ff_was_podcast );
		false === $ff_was_ping ? delete_option( 'rrt_podcast_ping' ) : update_option( 'rrt_podcast_ping', $ff_was_ping );
		wp_clear_scheduled_hook( 'rrt_podcast_ping' );
		foreach ( array( $ff_post, $ff_plain ) as $id ) {
			foreach ( get_children( array( 'post_parent' => $id, 'post_type' => 'attachment' ) ) as $a ) {
				wp_delete_attachment( $a->ID, true );
			}
			wp_delete_post( $id, true );
		}
		echo "\n" . $GLOBALS['ff_pass'] . ' passed, ' . count( $GLOBALS['ff_fail'] ) . " failed\n";
		if ( $GLOBALS['ff_fail'] ) {
			exit( 1 );
		}
	}
);

$ff_dir = trailingslashit( get_temp_dir() ) . 'ff-' . wp_generate_password( 6, false );
wp_mkdir_p( $ff_dir );
$ff_file = function ( $name, $body ) use ( $ff_dir ) {
	$p = $ff_dir . '/' . $name;
	file_put_contents( $p, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	return $p;
};
$ff_audio = str_repeat( "\xFF\xFB\x90\x00", 50 );

/* ---- Chapters from ID3 tags ---- */

$c = rrt_id3_chapters( get_attached_file( (int) get_post_meta( $ff_post, 'rrt_audio', true ) ) );
ff_t( 3 === count( $c ), 'three chapters are read from the seeded MP3', wp_json_encode( $c ) );
ff_t( isset( $c[2] ) && array( 0.0, 60.0, 120.0 ) === array( (float) $c[0]['start'], (float) $c[1]['start'], (float) $c[2]['start'] ), 'chapters come in time order, whatever order the tag lists them in' );
ff_t( isset( $c[2] ) && 'Opening' === $c[0]['title'] && 'The second part' === $c[1]['title'] && 'Close' === $c[2]['title'], 'chapter titles are read' );
ff_t( isset( $c[2] ) && 'https://example.com/second' === $c[1]['url'] && '' === $c[0]['url'], 'a chapter\'s link is read, and only where there is one' );
ff_t( isset( $c[2] ) && 120.0 === (float) $c[1]['end'], 'a chapter\'s end is read' );

$v4 = rrt_id3_chapters( $ff_file( 'v4.mp3', ff_id3( array( array( 'a', 0, 30.5, 'Ēostre ✶ the dawn' ), array( 'b', 30.5, 61.25, 'Nachtigall' ) ), array( 'v' => 4, 'utf16' => true ) ) . $ff_audio ) );
ff_t( 2 === count( $v4 ) && 'Ēostre ✶ the dawn' === $v4[0]['title'] && 61.25 === (float) $v4[1]['end'], 'a version 2.4 tag with UTF-16 titles', wp_json_encode( $v4 ) );
$notoc = rrt_id3_chapters( $ff_file( 'notoc.mp3', ff_id3( array( array( 'x', 10, 20, 'Late' ), array( 'y', 0, 10, 'Early' ) ), array( 'toc' => false, 'art' => false ) ) . $ff_audio ) );
ff_t( 2 === count( $notoc ) && 'Early' === $notoc[0]['title'], 'chapters without a table of contents, sorted by time' );
ff_t( array() === rrt_id3_chapters( $ff_file( 'plain.mp3', $ff_audio ) ), 'an MP3 without a tag has no chapters' );
ff_t( array() === rrt_id3_chapters( $ff_dir . '/missing.mp3' ), 'a missing file has no chapters' );
$unsync = ff_id3( array( array( 'a', 0, 5, 'A' ) ) );
$unsync[5] = "\x80";
ff_t( array() === rrt_id3_chapters( $ff_file( 'unsync.mp3', $unsync . $ff_audio ) ), 'an unsynchronised tag is skipped, as in id3.js' );
$cut = ff_id3( array( array( 'a', 0, 5, 'A' ), array( 'b', 5, 9, 'B' ) ), array( 'art' => false ) );
ff_t( is_array( rrt_id3_chapters( $ff_file( 'cut.mp3', substr( $cut, 0, 60 ) ) ) ), 'a cut-off tag reads without errors' );
$bad = rrt_id3_chapters( $ff_file( 'zero.mp3', ff_id3( array( array( 'a', 5, 5, 'Empty' ), array( 'b', 5, 9, 'Real' ) ) ) . $ff_audio ) );
ff_t( 1 === count( $bad ) && 'Real' === $bad[0]['title'], 'a chapter that ends where it starts is left out' );

/* ---- The chapters file ---- */

$j = json_decode( rrt_chapters_json( $c, 'Feed test' ), true );
ff_t( is_array( $j ) && '1.2.0' === $j['version'] && 'Feed test' === $j['title'] && 3 === count( $j['chapters'] ), 'the chapters file is Podcasting 2.0 JSON, version 1.2.0' );
ff_t( is_array( $j ) && 60 == $j['chapters'][1]['startTime'] && 120 == $j['chapters'][1]['endTime'] && 'https://example.com/second' === $j['chapters'][1]['url'] && ! isset( $j['chapters'][0]['url'] ), 'each chapter has its times, and a link only where it has one' );
$j = json_decode( rrt_chapters_json( array( array( 'start' => 0, 'end' => 4, 'title' => '' ) ) ), true );
ff_t( 'Chapter 1' === $j['chapters'][0]['title'] && ! isset( $j['title'] ), 'an untitled chapter gets a number' );
ff_t( false !== strpos( rrt_chapters_json( $v4 ), 'Ēostre ✶' ), 'titles are written as UTF-8, not escaped' );

ff_t( 42 === rrt_chapters_match( '/podcast-chapters/42.json', '/' ), 'the chapters address is recognised' );
ff_t( 42 === rrt_chapters_match( '/blog/podcast-chapters/42.json', '/blog' ), 'the chapters address is recognised on a site in a folder' );
ff_t( 42 === rrt_chapters_match( '/podcast-chapters/42', '/' ), 'and without .json, for servers that answer missing .json files themselves' );
ff_t( 0 === rrt_chapters_match( '/podcast-chapters/42.mp3', '/' ) && 0 === rrt_chapters_match( '/podcast-chapters/42/', '/' ) && 0 === rrt_chapters_match( '/podcast-chapters/x.json', '/' ) && 0 === rrt_chapters_match( '/other/podcast-chapters/4.json', '/' ), 'other addresses are not taken' );
ff_t( false !== strpos( rrt_chapters_url( 7 ), '/podcast-chapters/7.json' ), 'a post\'s chapters address' );

/* ---- Text lines, short titles ---- */

$tx = rrt_feed_txt_lines( "verify: S6lpp-7ZCn8\n\n  A plain line.  \nNote: two words\na b: c\nverify:nospace" );
ff_t( 5 === count( $tx ), 'empty lines are skipped', wp_json_encode( $tx ) );
ff_t( 'verify' === $tx[0]['purpose'] && 'S6lpp-7ZCn8' === $tx[0]['text'], 'a word and a colon give the line its purpose' );
ff_t( '' === $tx[1]['purpose'] && 'A plain line.' === $tx[1]['text'], 'a plain line has no purpose' );
ff_t( '' === $tx[3]['purpose'] && 'a b: c' === $tx[3]['text'] && '' === $tx[4]['purpose'], 'only a single word followed by a colon and a space counts' );
ff_t( array() === rrt_feed_txt_lines( '' ), 'no lines, no tags' );

$sh = rrt_feed_short( 'Leaves fall slowly over the quiet hill where owls call softly to the moon and rivers run cold.' );
ff_t( mb_strlen( $sh ) <= 60 && '...' === substr( $sh, -3 ) && false === strpos( $sh, 'moo...' ), 'a long soundbite title is cut at a word', $sh );
ff_t( 'Short words.' === rrt_feed_short( 'Short   words.' ), 'a short one is kept whole' );

/* ---- Host and support link ---- */

update_option( 'rrt_podcast', array() );
$s  = rrt_podcast_settings();
$pp = rrt_podcast_person( $s );
ff_t( $pp && rrt_podcast_author( $s ) === $pp['name'], 'the host is the author when no name is given' );
ff_t( $pp && ( 0 === strpos( home_url( '/' ), 'https://' ) ? home_url( '/' ) === $pp['href'] : '' === $pp['href'] ), 'the host links to the site, only over https' );
$s['person']      = 'Robin Ash';
$s['person_link'] = 'https://example.com/robin';
$pp               = rrt_podcast_person( $s );
ff_t( 'Robin Ash' === $pp['name'] && 'https://example.com/robin' === $pp['href'] && '' === $pp['img'], 'a given name and link, and no picture' );
$s['person_link'] = 'http://example.com/robin';
ff_t( '' === rrt_podcast_person( $s )['href'] && preg_grep( '/host\'s link/', rrt_podcast_checks( $s ) ), 'an http host link is left out and said' );

$up   = wp_upload_dir();
$img  = function ( $w, $h ) use ( $up ) {
	/* Only the size in its metadata is looked at, so the file need not be a real picture (and no image library is needed). */
	$path = trailingslashit( $up['path'] ) . 'ff-host-' . $w . 'x' . $h . '.jpg';
	file_put_contents( $path, "\xff\xd8\xff\xd9" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$id = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit', 'post_title' => 'ff host' ), $path );
	wp_update_attachment_metadata( $id, array( 'width' => $w, 'height' => $h, 'file' => _wp_relative_upload_path( $path ) ) );
	return $id;
};
$wide = $img( 400, 300 );
$sq   = $img( 400, 400 );
$s['person_img'] = $wide;
$pi              = rrt_podcast_person_img( $s );
ff_t( '' === $pi['url'] && preg_grep( '/square/', $pi['problems'] ), 'a host picture that is not square is refused and said' );
$s['person_img'] = $sq;
$pi              = rrt_podcast_person_img( $s );
$https           = 0 === strpos( (string) wp_get_attachment_url( $sq ), 'https://' );
ff_t( $https ? ( '' !== $pi['url'] && ! $pi['problems'] ) : ( '' === $pi['url'] && preg_grep( '/https/', $pi['problems'] ) ), 'a square host picture is used, if its address is https' );
$s['person_img'] = 999999;
ff_t( (bool) preg_grep( '/no longer/', rrt_podcast_person_img( $s )['problems'] ), 'a host picture deleted from the media library is said' );
wp_delete_attachment( $wide, true );
wp_delete_attachment( $sq, true );

$s['funding'] = 'https://www.paypal.com/donate/?hosted_button_id=TEST';
$f            = rrt_podcast_funding( $s );
ff_t( $f && 'https://www.paypal.com/donate/?hosted_button_id=TEST' === $f['url'] && sprintf( 'Support %s', rrt_podcast_title( $s ) ) === $f['txt'], 'the support link, with "Support <show>" by default' );
$s['funding_txt'] = 'Buy the fox a candle';
ff_t( 'Buy the fox a candle' === rrt_podcast_funding( $s )['txt'], 'the support link\'s own words' );
$s['funding'] = 'http://example.com/give';
ff_t( null === rrt_podcast_funding( $s ) && preg_grep( '/support link/', rrt_podcast_checks( $s ) ), 'an http support link is left out and said' );
$s['funding'] = '';
ff_t( null === rrt_podcast_funding( $s ) && ! preg_grep( '/support link/', rrt_podcast_checks( $s ) ), 'no support link, no tag and nothing to say' );
$s['hub'] = 'http://hub.example.com/';
ff_t( RRT_PING_HUB === rrt_ping_hub( $s ) && preg_grep( '/WebSub hub/', rrt_podcast_checks( $s ) ), 'an http hub is not used, and said' );
$s['hub'] = 'https://hub.example.com/';
ff_t( 'https://hub.example.com/' === rrt_ping_hub( $s ), 'another https hub is used' );

/* ---- What the feed keeps about each reading ---- */

delete_post_meta( $ff_post, RRT_FEED_META );
$sugg  = rrt_suggestions( $ff_post );
$facts = rrt_feed_facts( $ff_post );
ff_t( 3 === count( $facts['chapters'] ), 'the feed finds the post\'s chapters' );
ff_t( isset( $sugg[1] ) && $facts['top'] && (float) $sugg[1]['start'] === $facts['top']['start'] && (float) $sugg[1]['len'] === $facts['top']['len'], 'the top suggestion is the first after the opening', wp_json_encode( $facts['top'] ) );
$kept = get_post_meta( $ff_post, RRT_FEED_META, true );
ff_t( is_array( $kept ) && $kept['key'] === rrt_feed_key( $ff_post ), 'it is kept with the post' );
$kept['chapters'] = array( array( 'start' => 0, 'end' => 1, 'title' => 'Marker', 'url' => '' ) );
update_post_meta( $ff_post, RRT_FEED_META, $kept );
ff_t( 'Marker' === rrt_feed_facts( $ff_post )['chapters'][0]['title'], 'and used again while nothing has changed' );
$p = get_post( $ff_post );
/* An edit a second later, as when someone fixes a word in the post. */
global $wpdb;
$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', strtotime( $p->post_modified_gmt ) + 1 ) ), array( 'ID' => $ff_post ) );
clean_post_cache( $ff_post );
ff_t( 3 === count( rrt_feed_facts( $ff_post )['chapters'] ), 'an edit to the post works it out again' );
$kept = get_post_meta( $ff_post, RRT_FEED_META, true );
$kept['chapters'] = array();
update_post_meta( $ff_post, RRT_FEED_META, $kept );
$mp3 = get_attached_file( (int) get_post_meta( $ff_post, 'rrt_audio', true ) );
file_put_contents( $mp3, ff_id3( array( array( 'a', 0, 90, 'One' ), array( 'b', 90, 180, 'Two' ) ) ) . $ff_audio ); // phpcs:ignore WordPress.WP.AlternativeFunctions
clearstatcache();
ff_t( 2 === count( rrt_feed_facts( $ff_post )['chapters'] ), 'a new file under the same reading works it out again' );
file_put_contents( $mp3, ff_id3( array( array( 'chp1', 60, 120, 'The second part', 'https://example.com/second' ), array( 'chp0', 0, 60, 'Opening' ), array( 'chp2', 120, 180, 'Close' ) ) ) . str_repeat( "\xFF\xFB\x90\x00", 2000 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
clearstatcache();
ff_t( 3 === count( rrt_feed_facts( $ff_post )['chapters'] ), 'and back' );

$plain = rrt_feed_facts( $ff_plain );
ff_t( array() === $plain['chapters'] && null === $plain['top'], 'a reading with no tag and no captions has neither' );

/* ---- The soundbite ---- */

$sb = rrt_feed_soundbite( $ff_post );
ff_t( $sb && 'top' === $sb['from'] && $sb['start'] === $facts['top']['start'] && mb_strlen( $sb['title'] ) <= 60 && '' !== $sb['title'], 'with no pick, the soundbite is the top suggestion', wp_json_encode( $sb ) );
$audio = rrt_reading_audio( $ff_post );
update_post_meta( $ff_post, RRT_SOUNDBITE_META, array( 's' => 0.25, 'd' => 33.1, 'text' => 'Feed test. Leaves fall slowly over the quiet hill where owls call softly.', 'audio' => $audio['url'] ) );
$sb = rrt_feed_soundbite( $ff_post );
ff_t( $sb && 'pick' === $sb['from'] && 0.25 === $sb['start'] && 33.1 === $sb['len'] && 0 === strpos( $sb['title'], 'Feed test. Leaves' ), 'the pick from Use for the feed comes first' );
update_post_meta( $ff_post, RRT_SOUNDBITE_META, array( 's' => 0.25, 'd' => 33.1, 'text' => 'Old.', 'audio' => 'https://example.com/an-older-reading.mp3' ) );
$sb = rrt_feed_soundbite( $ff_post );
ff_t( $sb && 'top' === $sb['from'], 'a pick made for an older reading is not used' );
ff_t( null === rrt_feed_soundbite( $ff_plain ), 'a reading with no captions has no soundbite' );

$e = rrt_podcast_episode( get_post( $ff_post ) );
ff_t( rrt_chapters_url( $ff_post ) === $e['chapters'] && 3 === $e['nchapters'] && $e['soundbite'], 'the episode carries its chapters address and soundbite' );
$e = rrt_podcast_episode( get_post( $ff_plain ) );
ff_t( '' === $e['chapters'] && 0 === $e['nchapters'] && null === $e['soundbite'], 'a plain episode carries neither' );

/* ---- Pings ---- */

$GLOBALS['ff_sent']      = array();
$GLOBALS['rrt_ping_now'] = false;
delete_option( 'rrt_podcast_ping' );
wp_clear_scheduled_hook( 'rrt_podcast_ping' );
$base = rrt_podcast_settings();

update_option( 'rrt_podcast', array_merge( $base, array( 'on' => false, 'pings' => true ) ) );
update_post_meta( $ff_post, 'rrt_audio', (int) get_post_meta( $ff_plain, 'rrt_audio', true ) );
ff_t( ! rrt_ping_on() && empty( $GLOBALS['rrt_ping_now'] ) && ! wp_next_scheduled( 'rrt_podcast_ping' ), 'no pings while the feed is not published, even with the switch on' );
update_option( 'rrt_podcast', array_merge( $base, array( 'on' => true, 'pings' => false ) ) );
update_post_meta( $ff_post, 'rrt_audio', $ff_mp3 );
ff_t( empty( $GLOBALS['rrt_ping_now'] ) && ! wp_next_scheduled( 'rrt_podcast_ping' ), 'no pings with the switch off' );

update_option( 'rrt_podcast', array_merge( $base, array( 'on' => true, 'pings' => true, 'hub' => '' ) ) );
update_post_meta( $ff_post, 'rrt_audio', (int) get_post_meta( $ff_plain, 'rrt_audio', true ) );
ff_t( ! empty( $GLOBALS['rrt_ping_now'] ), 'a published post getting a new reading asks for a ping' );
ff_t( array() === $GLOBALS['ff_sent'], 'nothing is sent until the request ends' );
$GLOBALS['rrt_ping_now'] = false;
rrt_ping_send();
$sent = $GLOBALS['ff_sent'];
$feed = rrt_podcast_url();
ff_t( 2 === count( $sent ), 'two requests are sent', wp_json_encode( $sent ) );
ff_t( isset( $sent[0] ) && 'GET' === $sent[0]['method'] && RRT_PING_INDEX . '?url=' . rawurlencode( $feed ) === $sent[0]['url'], 'Podcast Index is told the feed\'s address', isset( $sent[0] ) ? $sent[0]['url'] : '' );
ff_t( isset( $sent[1] ) && 'POST' === $sent[1]['method'] && RRT_PING_HUB === $sent[1]['url'] && array( 'hub.mode' => 'publish', 'hub.url' => $feed ) === $sent[1]['body'], 'the hub is told to publish the feed\'s address, and nothing else' );
ff_t( isset( $sent[1] ) && 'FoxfireStudio/' . RRT_VERSION === $sent[1]['ua'] && 'FoxfireStudio/' . RRT_VERSION === $sent[0]['ua'], 'the requests name only the plugin, not the site' );
$last = rrt_ping_last();
ff_t( abs( time() - $last['at'] ) < 5 && '200' === $last['index'] && '204' === $last['hub'], 'the time and both answers are kept', wp_json_encode( $last ) );

$GLOBALS['ff_sent'] = array();
update_post_meta( $ff_post, 'rrt_audio', $ff_mp3 );
$next = wp_next_scheduled( 'rrt_podcast_ping' );
ff_t( empty( $GLOBALS['rrt_ping_now'] ) && $next && abs( $next - ( $last['at'] + RRT_PING_GAP ) ) < 2, 'within 10 minutes, the next ping waits for the 10 minutes to be up' );
update_post_meta( $ff_post, 'rrt_audio', (int) get_post_meta( $ff_plain, 'rrt_audio', true ) );
$all = _get_cron_array();
$n   = 0;
foreach ( $all as $ts => $hooks ) {
	$n += isset( $hooks['rrt_podcast_ping'] ) ? count( $hooks['rrt_podcast_ping'] ) : 0;
}
ff_t( 1 === $n, 'more changes meanwhile still wait for the one ping' );
rrt_ping_send();
ff_t( array() === $GLOBALS['ff_sent'], 'a ping that comes too early sends nothing' );
update_option( 'rrt_podcast_ping', array_merge( rrt_ping_last(), array( 'at' => time() - RRT_PING_GAP - 1 ) ), false );
do_action( 'rrt_podcast_ping' );
ff_t( 2 === count( $GLOBALS['ff_sent'] ), 'the waiting ping goes out once the 10 minutes are up' );
wp_clear_scheduled_hook( 'rrt_podcast_ping' );

update_option( 'rrt_podcast_ping', array_merge( rrt_ping_last(), array( 'at' => time() - RRT_PING_GAP - 1 ) ), false );
$GLOBALS['rrt_ping_now'] = false;
wp_update_post( array( 'ID' => $ff_plain, 'post_status' => 'draft' ) );
$GLOBALS['rrt_ping_now'] = false;
update_post_meta( $ff_plain, 'rrt_audio', $ff_mp3 );
ff_t( empty( $GLOBALS['rrt_ping_now'] ), 'a draft getting a reading asks for no ping' );
wp_update_post( array( 'ID' => $ff_plain, 'post_status' => 'publish' ) );
ff_t( ! empty( $GLOBALS['rrt_ping_now'] ), 'publishing a post that has a reading asks for a ping' );
$GLOBALS['rrt_ping_now'] = false;
$np = wp_insert_post( array( 'post_title' => 'Feed no reading', 'post_status' => 'publish' ) );
ff_t( empty( $GLOBALS['rrt_ping_now'] ), 'publishing a post with no reading asks for none' );
wp_delete_post( $np, true );
$GLOBALS['rrt_ping_now'] = false;
update_post_meta( $ff_post, 'rrt_credit', 'read by Robin' );
ff_t( empty( $GLOBALS['rrt_ping_now'] ), 'other fields of a post ask for no ping' );
delete_post_meta( $ff_post, 'rrt_credit' );

/* ---- The privacy paragraph ---- */

ff_t( false !== strpos( rrt_listen_privacy_text(), 'Podcast Index' ) && rrt_listen_privacy_needed(), 'the privacy paragraph says so while pings are on' );
update_option( 'rrt_podcast', array_merge( $base, array( 'on' => true, 'pings' => true, 'hub' => 'https://hub.example.com/' ) ) );
ff_t( false !== strpos( rrt_listen_privacy_text(), 'hub.example.com' ), 'and names another hub' );
update_option( 'rrt_podcast', array_merge( $base, array( 'on' => true, 'pings' => false ) ) );
ff_t( false === strpos( rrt_listen_privacy_text(), 'Podcast Index' ), 'and says nothing while they are off' );

/* ---- Uninstall knows these names ---- */

$un = (string) file_get_contents( RRT_DIR . 'uninstall.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
ff_t( false !== strpos( $un, "'" . RRT_FEED_META . "'" ) && false !== strpos( $un, "'rrt_podcast_ping'" ), 'uninstall removes the kept facts, the last pings and any waiting ping' );

array_map( 'unlink', glob( $ff_dir . '/*' ) );
rmdir( $ff_dir );
