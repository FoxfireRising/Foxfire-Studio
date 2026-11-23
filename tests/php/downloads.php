<?php
/**
 * Checks of the podcast download counting (1.2.0, to the IAB guidelines v2.3
 * since 1.24.0), run inside the test WordPress:
 *   php wp-cli.phar --path=<wordpress> eval-file tests/php/downloads.php
 *
 * The short address, ranges and their joining, the tag plus one minute, the
 * requests that never count, app, device and bot names from the OPAWG list,
 * Apple Watch, IPv6 cut to 64 bits, the data centre list, the hourly and daily
 * limits and shared networks, members, sponsor messages delivered,
 * prefixes, the method page, unusual days, the review, the old table's
 * counts, trimming, and the feed's enclosure. Everything made here is removed
 * afterwards.
 */

$GLOBALS['dl_pass'] = 0;
$GLOBALS['dl_fail'] = array();

function dl_t( $ok, $name, $detail = '' ) {
	if ( $ok ) {
		++$GLOBALS['dl_pass'];
		echo "ok   $name\n";
	} else {
		$GLOBALS['dl_fail'][] = $name;
		echo "FAIL $name" . ( '' !== $detail ? ': ' . $detail : '' ) . "\n";
	}
}

global $wpdb;
$dl_tc = rrt_table( 'dl_counts' );
$dl_ts = rrt_table( 'dl_seen' );
$dl_tp = rrt_table( 'dl_days' );
$dl_ta = rrt_table( 'dl_ads' );
$dl_to = rrt_table( 'downloads' );
$dl_keep_settings = get_option( 'rrt_dl', null );

$dl_clear = function () use ( $wpdb, $dl_tc, $dl_ts, $dl_tp, $dl_ta, $dl_to ) {
	foreach ( array( $dl_tc, $dl_ts, $dl_tp, $dl_ta ) as $t ) {
		$wpdb->query( "DELETE FROM $t" ); // phpcs:ignore
	}
	$wpdb->query( "DELETE FROM $dl_to WHERE post_id >= 900000" ); // phpcs:ignore
	$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE '\\_transient\\_rrt\\_dli%' OR option_name LIKE '\\_transient\\_timeout\\_rrt\\_dli%'" ); // phpcs:ignore
	wp_cache_flush();
};
$dl_clear();

/* The short address. */
dl_t( 12 === rrt_dl_match( '/podcast-file/12/samhain.mp3', '/' ), 'a short address gives its post id' );
dl_t( 12 === rrt_dl_match( '/podcast-file/12', '' ), 'the file name is optional' );
dl_t( 12 === rrt_dl_match( '/blog/podcast-file/12/a.mp3', '/blog/' ), 'a site in a folder' );
dl_t( 0 === rrt_dl_match( '/podcast-file/12/a.mp3', '/blog' ), 'outside the site\'s folder is not one' );
dl_t( 0 === rrt_dl_match( '/podcast-file/abc/a.mp3', '/' ), 'the id must be a number' );
dl_t( 0 === rrt_dl_match( '/other/podcast-file/1/a.mp3', '/' ), 'only at the start of the path' );
dl_t( 0 === rrt_dl_match( '/podcast-file/1/a/b.mp3', '/' ), 'one file name at most' );
dl_t( -1 === rrt_dl_match( '/podcast-file/0/abc.mp3', '/' ), 'post 0 is Check counting\'s own address' );
dl_t( 1 === preg_match( '#/podcast-file/7/ab_b-c\.mp3$#', rrt_dl_url( 7, 'https://example.com/wp-content/uploads/a%20b_b-c.mp3' ) ), 'the file name is kept, made safe' );

/* Ranges. */
dl_t( null === rrt_dl_ranges( '', 1000 ), 'no Range header: the whole file' );
dl_t( array( array( 0, 1 ) ) === rrt_dl_ranges( 'bytes=0-1', 1000 ), 'a two-byte range' );
dl_t( array( array( 500, 999 ) ) === rrt_dl_ranges( 'bytes=500-', 1000 ), 'an open range runs to the end' );
dl_t( array( array( 872, 999 ) ) === rrt_dl_ranges( 'bytes=-128', 1000 ), 'a suffix range is the last bytes' );
dl_t( array( array( 0, 99 ), array( 200, 299 ) ) === rrt_dl_ranges( 'bytes=0-99, 200-299', 1000 ), 'several ranges in one header' );
dl_t( array( array( 900, 999 ) ) === rrt_dl_ranges( 'bytes=900-5000', 1000 ), 'a range past the end stops at the end' );
dl_t( array() === rrt_dl_ranges( 'bytes=2000-3000', 1000 ), 'a range wholly past the end asks for nothing' );
dl_t( null === rrt_dl_ranges( 'items=0-5', 1000 ), 'a range that is not bytes: the whole file' );
dl_t( array( array( 0, 199 ), array( 300, 399 ) ) === rrt_dl_join( array( array( 100, 199 ), array( 0, 120 ), array( 300, 399 ), array( 300, 350 ) ) ), 'overlapping pieces are joined, others kept' );
dl_t( array( array( 0, 199 ) ) === rrt_dl_join( array( array( 0, 99 ), array( 100, 199 ) ) ), 'touching pieces are joined' );
$dl_many = array();
for ( $i = 0; $i < 50; $i++ ) {
	$dl_many[] = array( $i * 100, $i * 100 + 9 + ( 7 === $i ? 85 : 0 ) );
}
$dl_j = rrt_dl_join( $dl_many );
dl_t( RRT_DL_PIECES === count( $dl_j ) && 0 === $dl_j[0][0] && 4909 === end( $dl_j )[1], 'at most ' . RRT_DL_PIECES . ' pieces are kept, the closest joined first', count( $dl_j ) );
dl_t( 300 === rrt_dl_covered( array( array( 0, 99 ), array( 200, 399 ) ) ), 'covered bytes count each byte once' );
dl_t( array( array( 0, 99 ), array( 200, 399 ) ) === rrt_dl_spans_read( rrt_dl_spans_text( array( array( 0, 99 ), array( 200, 399 ) ) ) ), 'pieces are stored and read back' );

/* The bar: tag plus one minute, or the whole file. */
dl_t( 250000 + 960000 === rrt_dl_bar( 250000 + 16000 * 853, 250000, 853 ), 'a 14:13 reading at 128 kbps with a 250 KB tag needs the tag and a minute' );
dl_t( 890000 === rrt_dl_bar( 890000, 250000, 40 ), 'a reading under a minute needs the whole file' );
dl_t( 5000 === rrt_dl_bar( 5000, 0, 0 ), 'a reading of unknown length needs the whole file' );
$dl_dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'dl-test';
wp_mkdir_p( $dl_dir );
$dl_mp3 = $dl_dir . '/tagged.mp3';
/* A tag of 1,000 bytes after its header: syncsafe 0x00 0x00 0x07 0x68 is 1000. */
file_put_contents( $dl_mp3, "ID3\x03\x00\x00\x00\x00\x07\x68" . str_repeat( "\0", 1000 ) . str_repeat( 'A', 50000 ) );
dl_t( 1010 === rrt_dl_tag_size( $dl_mp3 ), 'the tag is measured from the file', rrt_dl_tag_size( $dl_mp3 ) );
file_put_contents( $dl_dir . '/plain.mp3', str_repeat( 'B', 2000 ) );
dl_t( 0 === rrt_dl_tag_size( $dl_dir . '/plain.mp3' ), 'a file with no tag has none' );
file_put_contents( $dl_mp3, "ID3\x03\x00\x00\x00\x00\x0f\x50" . str_repeat( "\0", 2000 ) . str_repeat( 'A', 50000 ) );
touch( $dl_mp3, time() + 5 );
dl_t( 2010 === rrt_dl_tag_size( $dl_mp3 ), 'a changed file is measured again', rrt_dl_tag_size( $dl_mp3 ) );

/* Apps, devices and bots from OPAWG's list. */
$dl_apps = array(
	'AppleCoreMedia/1.0.0.21E236 (iPhone; U; CPU OS 17_4 like Mac OS X; en_us)' => array( 'AppleCoreMedia', 'mobile' ),
	'Podcasts/1650.20 CFNetwork/1410.1 Darwin/22.6.0'                          => array( 'Apple Podcasts', '' ),
	'Overcast/3.0 (+http://overcast.fm/; iOS podcast app)'                     => array( 'Overcast', '' ),
	'PocketCasts/1.0 (Pocket Casts Android, 7.60)'                             => array( 'Pocket Casts', '' ),
	'AntennaPod/3.4.0'                                                         => array( 'AntennaPod', '' ),
	'SomethingNew/1.0'                                                         => array( 'Other', '' ),
);
$dl_wrong = array();
foreach ( $dl_apps as $ua => $want ) {
	$i = rrt_ua_info( $ua );
	if ( $i['name'] !== $want[0] || ! $i['count'] ) {
		$dl_wrong[] = $ua . ' => ' . $i['name'];
	}
}
dl_t( ! $dl_wrong, 'apps are named from OPAWG\'s list, and an unknown one still counts as Other', implode( '; ', $dl_wrong ) );
dl_t( 'mobile' === rrt_ua_info( 'AppleCoreMedia/1.0.0.21E236 (iPhone; U; CPU OS 17_4 like Mac OS X; en_us)' )['device'], 'an iPhone is a phone or tablet' );
dl_t( 'Apple\'s player (AppleCoreMedia)' === rrt_ua_app_label( 'AppleCoreMedia' ), 'AppleCoreMedia is shown as Apple\'s player' );
$dl_bots = array( '', '   ', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', 'iTMS', 'curl/8.5.0', 'acast-tester/1.0', 'Python/3.10 aiohttp/3.9.3', 'atc/1.0 watchOS/10.0 model/Watch6,1', 'Podcasts/1.0 (null)/(null) watchOS/9.1', "Overcast/3.0\r\nX: y", str_repeat( 'A', 600 ), 'MyCrawler/2.0', 'AudioCorpusPipeline/1.0', 'okhttp/4.12.0' );
$dl_let = array();
foreach ( $dl_bots as $ua ) {
	if ( rrt_ua_info( $ua )['count'] ) {
		$dl_let[] = substr( $ua, 0, 40 );
	}
}
dl_t( ! $dl_let, 'empty, broken and overlong names, bots, bot libraries (bare okhttp among them, as OPAWG has it), OP3\'s extra bots and Apple Watch duplicates never count', implode( '; ', $dl_let ) );
dl_t( rrt_ua_is_watch( 'atc/1.0 watchOS/10.0' ) && rrt_ua_is_watch( 'x (null)/(null) watchOS/9' ) && ! rrt_ua_is_watch( 'Podcasts/1650.20 CFNetwork/1410.1 Darwin/22.6.0' ), 'the two Apple Watch patterns the guidelines name' );
/* Every example in the list lands on its own entry (the list's own rule). */
$dl_l   = rrt_ua_lists();
dl_t( count( $dl_l['bots'] ) > 250 && count( $dl_l['apps'] ) > 500 && count( $dl_l['extra'] ) > 100, 'the lists are loaded', count( $dl_l['bots'] ) . ' bots, ' . count( $dl_l['apps'] ) . ' apps' );
$dl_badre = array();
foreach ( array( 'bots', 'apps', 'libraries', 'browsers', 'devices' ) as $k ) {
	foreach ( $dl_l[ $k ] as $e ) {
		if ( false === @preg_match( $e[1], '' ) ) { // phpcs:ignore
			$dl_badre[] = $e[0];
		}
	}
}
dl_t( ! $dl_badre, 'every pattern works in PHP', implode( ', ', $dl_badre ) );
dl_t( 1 === preg_match( '/^OPAWG \d{4}-\d\d-\d\d, OP3 \d{4}-\d\d-\d\d$/', rrt_ua_lists_date() ), 'the lists carry their editions', rrt_ua_lists_date() );

/* IPv6 cut to 64 bits; data centres. */
dl_t( rrt_dl_ip_key( '2001:db8:1:2:aaaa::1' ) === rrt_dl_ip_key( '2001:db8:1:2:bbbb::9' ), 'two IPv6 addresses in one /64 are one' );
dl_t( rrt_dl_ip_key( '2001:db8:1:2::1' ) !== rrt_dl_ip_key( '2001:db8:1:3::1' ), 'another /64 is another' );
dl_t( rrt_dl_ip_key( '203.0.113.5' ) === rrt_dl_ip_key( '::ffff:203.0.113.5' ), 'IPv4 written as IPv6 is the same IPv4' );
dl_t( rrt_net_is_datacenter( '34.80.0.10' ) && rrt_net_is_datacenter( '3.80.0.10' ) && rrt_net_is_datacenter( '2600:1f18::1' ), 'cloud addresses are data centres (Google, Amazon, Amazon IPv6)' );
dl_t( ! rrt_net_is_datacenter( '24.64.1.1' ) && ! rrt_net_is_datacenter( '142.59.1.1' ) && ! rrt_net_is_datacenter( '203.0.113.5' ), 'home addresses are not' );
dl_t( array( "\xcb\x00\x71\x00", "\xcb\x00\x71\xff" ) === rrt_net_range( '203.0.113.0/24' ), 'a CIDR range gives its first and last address' );
dl_t( null === rrt_net_range( '203.0.113.0/40' ) && null === rrt_net_range( 'nonsense' ) && null === rrt_net_range( '# note' ), 'a bad range or a comment gives none' );
$dl_bin  = $dl_dir . '/nets.bin';
add_filter( 'rrt_net_min', '__return_zero' );
$dl_conv = rrt_net_convert( array( '10.0.0.0/24', '10.0.1.0/24', '10.0.0.128/25', '192.0.2.0/24', '2001:db8::/48', 'junk' ), $dl_bin );
remove_filter( 'rrt_net_min', '__return_zero' );
dl_t( is_array( $dl_conv ) && 2 === $dl_conv['v4'] && 1 === $dl_conv['v6'], 'converting merges touching and nested ranges', wp_json_encode( $dl_conv ) );
dl_t( rrt_net_in_file( '10.0.1.200', $dl_bin ) && rrt_net_in_file( '192.0.2.1', $dl_bin ) && ! rrt_net_in_file( '10.0.2.0', $dl_bin ) && ! rrt_net_in_file( '9.255.255.255', $dl_bin ), 'looking up IPv4 at the edges' );
dl_t( rrt_net_in_file( '2001:db8:0:5::1', $dl_bin ) && ! rrt_net_in_file( '2001:db8:1::1', $dl_bin ), 'looking up IPv6' );
dl_t( is_wp_error( rrt_net_convert( array( '10.0.0.0/24' ), $dl_bin . '2' ) ), 'a list that looks too short is refused' );

/* The monthly download, with a stand-in for GitHub. */
$dl_http = function ( $pre, $args, $url ) {
	if ( false !== strpos( $url, 'lists_vpn' ) ) {
		$lines = array();
		for ( $i = 0; $i < 1200; $i++ ) {
			$lines[] = '100.' . intdiv( $i, 120 ) . '.' . ( 2 * ( $i % 120 ) ) . '.0/24';
		}
		return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => false !== strpos( $url, 'ipv6' ) ? "2001:db8:aa::/48\n" : implode( "\n", $lines ), 'headers' => array(), 'cookies' => array() );
	}
	return $pre;
};
add_filter( 'pre_http_request', $dl_http, 10, 3 );
dl_t( true === rrt_net_download(), 'the monthly download saves the list' );
remove_filter( 'pre_http_request', $dl_http, 10 );
$dl_ni = rrt_net_info();
dl_t( 'download' === $dl_ni['from'] && 1200 === $dl_ni['v4'] && 1 === $dl_ni['v6'] && rrt_net_is_datacenter( '100.2.4.4' ) && ! rrt_net_is_datacenter( '100.2.3.4' ), 'the downloaded list is then the one in use', wp_json_encode( $dl_ni ) );
wp_delete_file( rrt_net_saved_file() );
delete_option( 'rrt_net' );
dl_t( 'plugin' === rrt_net_info()['from'] && rrt_net_is_datacenter( '34.80.0.10' ), 'without it, the copy in the plugin is used' );

/* Counting a file: 14:13 at 128 kbps with a 250 KB tag. */
$dl_size = 250000 + 16000 * 853;
$dl_file = array( 'post' => 900101, 'key' => 'reading.mp3', 'bytes' => $dl_size, 'secs' => 853, 'tag' => 250000 );
$dl_ip   = 0;
$dl_go   = function ( $reqs, $file = null, $ip = null ) use ( &$dl_file, &$dl_ip ) {
	$out = array();
	$ip  = null === $ip ? '198.51.100.' . ( ++$dl_ip ) : $ip;
	foreach ( $reqs as $r ) {
		$out[] = rrt_dl_take( array_merge( array( 'ip' => $ip ), $r ), null === $file ? $dl_file : $file );
	}
	return $out;
};
$dl_ap = 'AppleCoreMedia/1.0.0.21E236 (iPhone; U; CPU OS 17_4 like Mac OS X; en_us)';
$dl_ov = 'Overcast/3.0 (+http://overcast.fm/; iOS podcast app)';
$dl_pc = 'PocketCasts/1.0 (Pocket Casts iOS)';
$dl_chunks = function ( $from, $to, $step, $ua ) {
	$o = array();
	for ( $a = $from; $a < $to; $a += $step ) {
		$o[] = array( 'range' => 'bytes=' . $a . '-' . ( $a + $step - 1 ), 'ua' => $ua );
	}
	return $o;
};
$dl_r = $dl_go( array( array( 'ua' => $dl_ov ) ) );
dl_t( array( 'counted' ) === $dl_r, 'a whole file with no Range counts' );
$dl_r = $dl_go( array( array( 'method' => 'HEAD', 'ua' => $dl_ov ), array( 'ua' => $dl_ov ) ) );
dl_t( array( 'head', 'counted' ) === $dl_r, 'HEAD never counts, the GET after it does' );
$dl_r = $dl_go( array( array( 'range' => 'bytes=0-1', 'ua' => 'Podcasts/4025.1' ), array( 'range' => 'bytes=0-', 'ua' => 'Podcasts/4025.1' ) ) );
dl_t( array( 'probe', 'counted' ) === $dl_r, 'Apple\'s download: the probe, then the file' );
$dl_r = $dl_go( array( array( 'range' => 'bytes=0-1', 'ua' => $dl_ap ) ) );
dl_t( array( 'probe' ) === $dl_r, 'a probe alone never counts' );
$dl_r = $dl_go( $dl_chunks( 0, 1048576, 1048576, $dl_ap ) );
dl_t( array( 'more' ) === $dl_r, 'a 1 MB start (30 s plus the tag) does not count yet' );
$dl_r = $dl_go( $dl_chunks( 0, 3 * 1048576, 1048576, $dl_ap ) );
dl_t( array( 'more', 'counted', 'repeat' ) === $dl_r, 'streaming in 1 MB pieces counts at the piece that passes the bar, once', implode( ',', $dl_r ) );
$dl_r = $dl_go( $dl_chunks( 0, 4 * 524288, 524288, $dl_pc ) );
dl_t( array( 'more', 'more', 'counted', 'repeat' ) === $dl_r, 'an app fetching 512 KB pieces counts (1.2.0 never counted it)', implode( ',', $dl_r ) );
$dl_r = $dl_go( array_fill( 0, 5, array( 'range' => 'bytes=0-524287', 'ua' => $dl_pc ) ) );
dl_t( array( 'more', 'more', 'more', 'more', 'more' ) === $dl_r, 'the same piece asked again never adds up' );
$dl_r = $dl_go( array( array( 'range' => 'bytes=0-400000', 'ua' => $dl_ap ), array( 'range' => 'bytes=9850000-11250000', 'ua' => $dl_ap ) ) );
dl_t( array( 'more', 'counted' ) === $dl_r, 'starting, then skipping ahead, counts once the pieces add up' );
$dl_r = $dl_go( array( array( 'range' => 'bytes=-128', 'ua' => 'SomeApp/1.0' ) ) );
dl_t( array( 'more' ) === $dl_r, 'a tag read from the end does not count' );
$dl_r = $dl_go( array( array( 'ua' => 'atc/1.0 watchOS/10.0 model/Watch6,1' ) ) );
dl_t( array( 'bot' ) === $dl_r, 'an Apple Watch duplicate does not count' );
$dl_r = $dl_go( array( array( 'ua' => $dl_ov, 'cond' => true ) ) );
dl_t( array( 'cond' ) === $dl_r, 'a GET that only asks whether the file changed does not count' );
$dl_r = $dl_go( array( array( 'ua' => $dl_ov, 'cond' => true, 'range' => 'bytes=0-' ) ) );
dl_t( array( 'counted' ) === $dl_r, 'with a Range too, it is a real request' );
$dl_r = $dl_go( array( array( 'ua' => $dl_ov ) ), null, '34.80.0.10' );
dl_t( array( 'datacenter' ) === $dl_r, 'a data centre address does not count' );
$dl_short = array( 'post' => 900102, 'key' => 'short.mp3', 'bytes' => 890000, 'secs' => 40, 'tag' => 250000 );
dl_t( array( 'more' ) === $dl_go( array( array( 'range' => 'bytes=0-801000', 'ua' => $dl_ap ) ), $dl_short ), 'a short reading: 90% is not enough' );
dl_t( array( 'counted' ) === $dl_go( array( array( 'ua' => $dl_ov ) ), $dl_short ), 'a short reading: the whole file counts' );
/* The same consumer: once a day per file, separate per file and per app. */
$dl_r = $dl_go( array( array( 'ua' => $dl_ov ), array( 'ua' => $dl_ov ) ), null, '203.0.113.5' );
dl_t( array( 'counted', 'repeat' ) === $dl_r, 'the same consumer again that day does not count' );
dl_t( array( 'counted' ) === $dl_go( array( array( 'ua' => $dl_ap, 'range' => 'bytes=0-' ) ), null, '203.0.113.5' ), 'another app at the same address does' );
dl_t( array( 'counted' ) === $dl_go( array( array( 'ua' => $dl_ov ) ), array_merge( $dl_file, array( 'key' => 'other-sp12345678.mp3' ) ), '203.0.113.5' ), 'the same consumer on another file does' );
dl_t( array( 'counted', 'repeat' ) === array( rrt_dl_take( array( 'ip' => '2001:db8:9:9:aaaa::1', 'ua' => $dl_ov ), $dl_file ), rrt_dl_take( array( 'ip' => '2001:db8:9:9:bbbb::2', 'ua' => $dl_ov ), $dl_file ) ), 'a phone hopping inside its IPv6 /64 counts once' );
/* What was stored. */
$dl_seen = (string) $wpdb->get_var( "SELECT GROUP_CONCAT(h) FROM $dl_ts" ); // phpcs:ignore
dl_t( false === strpos( $dl_seen, '203.0.113' ) && false === strpos( $dl_seen, 'Overcast' ) && 1 === preg_match( '/^[a-f0-9]{32}(,[a-f0-9]{32})*$/', $dl_seen ), 'only codes are kept, never an address or an app\'s name' );
$dl_st = rrt_dl_stats( wp_date( 'Y-m-d' ), wp_date( 'Y-m-d' ) );
dl_t( 12 === $dl_st['total'] && 0 === $dl_st['old'], 'twelve downloads today', $dl_st['total'] );
dl_t( isset( $dl_st['apps']['Overcast'], $dl_st['apps']['AppleCoreMedia'] ) && 'mobile' === array_key_first( array_diff_key( $dl_st['devices'], array( '' => 1 ) ) ), 'by app and device', wp_json_encode( $dl_st['apps'] ) . wp_json_encode( $dl_st['devices'] ) );
dl_t( 11 === $dl_st['posts'][900101] && 1 === $dl_st['posts'][900102] && array( 'public' => 12 ) === $dl_st['feeds'], 'by episode and feed', wp_json_encode( $dl_st['posts'] ) );
$dl_cons = (int) $dl_st['consumers'][ wp_date( 'Y-m-d' ) ];
dl_t( 11 === $dl_cons, 'podcast consumers a day: each address and app once, across files', $dl_cons );

/* The limits per address. */
$dl_clear();
$dl_n = 0;
for ( $i = 0; $i < RRT_DL_HOURLY + 5; $i++ ) {
	$dl_n += 'counted' === rrt_dl_take( array( 'ip' => '192.0.2.44', 'ua' => 'SomeApp/' . $i ), $dl_file ) ? 1 : 0;
}
dl_t( RRT_DL_HOURLY === $dl_n, 'at most ' . RRT_DL_HOURLY . ' an hour from one address, however the app\'s name changes', $dl_n );
dl_t( 'repeat' === rrt_dl_take( array( 'ip' => '192.0.2.44', 'ua' => 'SomeApp/' . ( RRT_DL_HOURLY + 1 ) ), $dl_file ), 'a refused consumer stays settled for the day' );
$dl_s = rrt_dl_settings();
rrt_dl_settings_save( array_merge( $dl_s, array( 'shared' => "192.0.2.0/24\n", 'shared_ok' => time() ) ) );
dl_t( 'counted' === rrt_dl_take( array( 'ip' => '192.0.2.44', 'ua' => 'SomeApp/new' ), $dl_file ), 'a shared network is left out of the limits' );
dl_t( ! rrt_net_shared_due(), 'shared networks just confirmed are not due' );
rrt_dl_settings_save( array_merge( rrt_dl_settings(), array( 'shared_ok' => time() - 91 * DAY_IN_SECONDS ) ) );
dl_t( rrt_net_shared_due(), 'after 90 days they are due again' );
rrt_dl_settings_save( array_merge( rrt_dl_settings(), array( 'shared' => '', 'shared_ok' => 0 ) ) );
/* The daily limit: count the transient past the hourly one. */
$dl_clear();
$dl_dk = 'rrt_dlid_' . substr( hash_hmac( 'sha256', rrt_dl_ip_key( '192.0.2.77' ) . '|' . wp_date( 'Ymd' ), rrt_dl_salt() ), 0, 24 );
set_transient( $dl_dk, RRT_DL_DAILY, DAY_IN_SECONDS );
dl_t( 'busy' === rrt_dl_take( array( 'ip' => '192.0.2.77', 'ua' => $dl_ov ), $dl_file ), 'at most ' . RRT_DL_DAILY . ' a day from one address' );

/* Members: the member is the consumer, totals only. */
$dl_clear();
$dl_mf = array_merge( $dl_file, array( 'key' => 'att55', 'feed' => 'members' ) );
dl_t( 'counted' === rrt_dl_take( array( 'ip' => '203.0.113.9', 'ua' => $dl_ov, 'member' => 7 ), $dl_mf ), 'a member\'s download counts' );
dl_t( 'repeat' === rrt_dl_take( array( 'ip' => '198.51.100.200', 'ua' => $dl_ap, 'member' => 7 ), $dl_mf ), 'the same member from another address and app does not count again' );
dl_t( 'counted' === rrt_dl_take( array( 'ip' => '34.80.0.10', 'ua' => $dl_ov, 'member' => 8 ), $dl_mf ), 'a member is not refused for a data centre address' );
$dl_st = rrt_dl_stats( wp_date( 'Y-m-d' ), wp_date( 'Y-m-d' ) );
dl_t( array( 'members' => 2 ) === $dl_st['feeds'] && 2 === $dl_st['members'][ wp_date( 'Y-m-d' ) ], 'members\' downloads are their own feed, and members are consumers', wp_json_encode( $dl_st['feeds'] ) );
$dl_cols = $wpdb->get_col( "SHOW COLUMNS FROM $dl_tc" ); // phpcs:ignore
dl_t( ! array_intersect( array( 'user_id', 'member', 'uid' ), $dl_cols ), 'no member is stored with the counts', implode( ',', $dl_cols ) );

/* Sponsor messages delivered: a pre-roll, a mid-roll ending at byte 6,000,000, a post-roll. */
$dl_clear();
$dl_sf = array_merge(
	$dl_file,
	array(
		'key'   => 'hex-sp1234abcd.mp3',
		'parts' => array(
			array( 'slot' => 'pre', 'spot' => 77, 'end' => 400000 ),
			array( 'slot' => 'mid', 'spot' => 77, 'end' => 6000000 ),
			array( 'slot' => 'post', 'spot' => 78, 'end' => $dl_size - 1 ),
		),
	)
);
$dl_go( array( array( 'range' => 'bytes=0-1300000', 'ua' => $dl_ap ), array( 'range' => 'bytes=1300001-6100000', 'ua' => $dl_ap ), array( 'range' => 'bytes=6100001-', 'ua' => $dl_ap ) ), $dl_sf );
$dl_go( array( array( 'range' => 'bytes=0-1300000', 'ua' => $dl_ap ) ), $dl_sf );
$dl_go( array( array( 'range' => 'bytes=0-200000', 'ua' => $dl_ap ) ), $dl_sf );
$dl_ads = array();
foreach ( $wpdb->get_results( "SELECT spot_id, slot, delivered FROM $dl_ta", ARRAY_A ) as $r ) { // phpcs:ignore
	$dl_ads[ $r['spot_id'] . $r['slot'] ] = (int) $r['delivered'];
}
dl_t( array( '77pre' => 2, '77mid' => 1, '78post' => 1 ) == $dl_ads, 'pre-rolls with each download, mid- and post-rolls once their last byte was asked for, none without a download', wp_json_encode( $dl_ads ) );
$dl_af = rrt_dl_ads_for( 77, wp_date( 'Y-m-d' ), wp_date( 'Y-m-d' ) );
dl_t( 2 === count( $dl_af ), 'a message\'s deliveries by slot', wp_json_encode( $dl_af ) );

/* The old table still counts, labelled. */
$wpdb->insert( $dl_to, array( 'day' => wp_date( 'Y-m-d' ), 'post_id' => 900101, 'app' => 'Overcast', 'downloads' => 3 ) ); // phpcs:ignore
$dl_st = rrt_dl_stats( wp_date( 'Y-m-d' ), wp_date( 'Y-m-d' ) );
dl_t( 3 === $dl_st['old'] && $dl_st['total'] >= 5, '1.2.0\'s counts are added and shown as counted the old way', wp_json_encode( array( $dl_st['old'], $dl_st['total'] ) ) );
dl_t( rrt_dl_any(), 'downloads were counted' );

/* Trimming keeps today's and yesterday's codes only. */
$wpdb->insert( $dl_ts, array( 'day' => wp_date( 'Y-m-d', time() - 3 * DAY_IN_SECONDS ), 'h' => str_repeat( 'a', 32 ) ) ); // phpcs:ignore
$dl_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $dl_ts" ); // phpcs:ignore
rrt_dl_trim();
dl_t( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $dl_ts" ) === $dl_before - 1, 'the daily job forgets codes older than yesterday' ); // phpcs:ignore

/* Unusual days and the review. */
$dl_clear();
for ( $i = 1; $i <= 28; $i++ ) {
	$wpdb->insert( $dl_tc, array( 'day' => wp_date( 'Y-m-d', time() - $i * DAY_IN_SECONDS ), 'post_id' => 900101, 'app' => 'Overcast', 'device' => '', 'feed' => 'public', 'downloads' => 8 ) ); // phpcs:ignore
}
$wpdb->insert( $dl_tc, array( 'day' => wp_date( 'Y-m-d' ), 'post_id' => 900101, 'app' => 'Overcast', 'device' => '', 'feed' => 'public', 'downloads' => 9 ) ); // phpcs:ignore
$wpdb->insert( $dl_tc, array( 'day' => wp_date( 'Y-m-d' ), 'post_id' => 900101, 'app' => 'NewApp', 'device' => '', 'feed' => 'public', 'downloads' => 30 ) ); // phpcs:ignore
$dl_odd = rrt_dl_unusual( wp_date( 'Y-m-d', time() - 7 * DAY_IN_SECONDS ), wp_date( 'Y-m-d' ) );
$dl_whats = wp_list_pluck( $dl_odd, 'what' );
dl_t( 2 === count( $dl_odd ) && in_array( 'all', $dl_whats, true ) && in_array( 'NewApp', $dl_whats, true ), 'a day over three times the median is flagged, and the app that made it', wp_json_encode( $dl_odd ) );
dl_t( ! rrt_dl_unusual( wp_date( 'Y-m-d', time() - 7 * DAY_IN_SECONDS ), wp_date( 'Y-m-d', time() - DAY_IN_SECONDS ) ), 'ordinary days are not' );
$dl_pc_opt = get_option( 'rrt_podcast', null );
update_option( 'rrt_podcast', array_merge( rrt_podcast_settings(), array( 'count' => true ) ) );
rrt_dl_settings_save( array_merge( rrt_dl_settings(), array( 'reviewed' => time() - 10 * DAY_IN_SECONDS ) ) );
dl_t( ! rrt_dl_review_due() && '' === rrt_dl_review_html(), 'a recent review: nothing due' );
rrt_dl_settings_save( array_merge( rrt_dl_settings(), array( 'reviewed' => time() - 190 * DAY_IN_SECONDS ) ) );
dl_t( rrt_dl_review_due() && false !== strpos( rrt_dl_review_html(), 'data-rrt-dl-reviewed' ), 'six months on, a review is asked for' );

/* Prefixes. */
rrt_dl_settings_save( array_merge( rrt_dl_settings(), array( 'op3' => true, 'prefixes' => "https://dts.podtrac.com/redirect.mp3/\nnot a url\n" ) ) );
dl_t( 'https://op3.dev/e/dts.podtrac.com/redirect.mp3/example.com/podcast-file/5/a.mp3' === rrt_dl_prefixed( 'https://example.com/podcast-file/5/a.mp3' ), 'prefixes go in front in order, OP3 first, a bad line left out', rrt_dl_prefixed( 'https://example.com/podcast-file/5/a.mp3' ) );
rrt_dl_settings_save( array_merge( rrt_dl_settings(), array( 'op3' => false, 'prefixes' => '' ) ) );
dl_t( 'https://example.com/a.mp3' === rrt_dl_prefixed( 'https://example.com/a.mp3' ), 'no prefixes, no change' );

/* The method page. */
rrt_dl_settings_save( array_merge( rrt_dl_settings(), array( 'op3' => true ) ) );
$dl_m = rrt_dl_method_html();
dl_t( false !== strpos( $dl_m, 'version 2.3' ) && false !== strpos( $dl_m, 'no audit or certification' ) && false === stripos( $dl_m, 'is certified' ), 'the method names the version and claims no certificate' );
dl_t( false !== strpos( $dl_m, wp_timezone_string() ) && false !== strpos( $dl_m, 'op3.dev' ) && false !== strpos( $dl_m, 'redirect' ), 'it says the time zone, the prefix and the redirect in use' );
rrt_dl_settings_save( array_merge( rrt_dl_settings(), array( 'op3' => false ) ) );
$dl_page = rrt_dl_method_page();
dl_t( $dl_page && 'draft' === get_post_status( $dl_page ) && false !== strpos( get_post( $dl_page )->post_content, '[foxfire_download_method]' ), 'the method page is made as a draft holding the shortcode' );
dl_t( $dl_page === rrt_dl_method_page(), 'and only once' );
dl_t( false !== strpos( do_shortcode( '[foxfire_download_method]' ), 'What counts as a download' ), 'the shortcode writes the method out' );
wp_delete_post( $dl_page, true );

/* The feed's enclosure: the short address only while counting is on, prefixes only in the public feed. */
$dl_clear();
$dl_real = wp_insert_post( array( 'post_title' => 'Download test', 'post_content' => 'Words.', 'post_status' => 'publish' ) );
update_post_meta( $dl_real, 'rrt_audio', 'https://example.com/wp-content/uploads/download-test.mp3' );
update_option( 'rrt_podcast', array_merge( rrt_podcast_settings(), array( 'count' => false ) ) );
$dl_e = rrt_podcast_episode( get_post( $dl_real ) );
dl_t( $dl_e && 'https://example.com/wp-content/uploads/download-test.mp3' === $dl_e['enclosure'], 'counting off: the feed points to the file' );
update_option( 'rrt_podcast', array_merge( rrt_podcast_settings(), array( 'count' => true ) ) );
$dl_e = rrt_podcast_episode( get_post( $dl_real ) );
dl_t( $dl_e && home_url( '/podcast-file/' . $dl_real . '/download-test.mp3' ) === $dl_e['enclosure'], 'counting on: the feed points to the short address' );
$dl_t = rrt_dl_target( $dl_real );
dl_t( $dl_t && 'https://example.com/wp-content/uploads/download-test.mp3' === $dl_t['url'] && '' === $dl_t['path'], 'the short address leads to the reading; a file elsewhere has no local path' );
dl_t( null === rrt_dl_target( 999999 ), 'a post that does not exist has no target' );
$dl_local = trailingslashit( wp_upload_dir()['baseurl'] ) . 'dl-test/tagged.mp3';
dl_t( $dl_mp3 === rrt_dl_local_path( $dl_local ) && '' === rrt_dl_local_path( trailingslashit( wp_upload_dir()['baseurl'] ) . 'dl-test/../../wp-config.php' ) && '' === rrt_dl_local_path( 'https://elsewhere.example/a.mp3' ), 'local paths: only real files in the uploads folder' );
rrt_dl_settings_save( array_merge( rrt_dl_settings(), array( 'op3' => true ) ) );
ob_start();
rrt_podcast_render();
$dl_feed = ob_get_clean();
dl_t( false !== strpos( $dl_feed, 'op3.dev/e/' ) || false === strpos( $dl_feed, '<item>' ), 'the public feed carries the prefix' );
rrt_dl_settings_save( array_merge( rrt_dl_settings(), array( 'op3' => false ) ) );
dl_t( false !== strpos( rrt_listen_privacy_text( array_merge( rrt_settings(), array( 'listen' => array_merge( rrt_settings()['listen'], array( 'on' => false ) ) ) ) ), 'IAB Tech Lab' ), 'while counting is on, the privacy paragraph says so' );

/* The Range header and the .htaccess lines (second build: found live on LiteSpeed, which keeps the header from PHP). */
$dl_srv = $_SERVER;
unset( $_SERVER['HTTP_RANGE'], $_SERVER['RRT_RANGE'], $_SERVER['REDIRECT_RRT_RANGE'] );
dl_t( array( 'range' => '', 'from' => '', 'rule' => '' ) === rrt_dl_range_header(), 'no Range header: none' );
$_SERVER['REDIRECT_RRT_SEEN'] = '1';
dl_t( '1' === rrt_dl_range_header()['rule'], 'it says when the .htaccess lines ran without a header to pass on' );
unset( $_SERVER['REDIRECT_RRT_SEEN'] );
$_SERVER['REDIRECT_RRT_RANGE'] = 'bytes=0-1';
dl_t( array( 'range' => 'bytes=0-1', 'from' => 'htaccess', 'rule' => '' ) === rrt_dl_range_header() && 'bytes=0-1' === rrt_dl_request()['range'], 'the copy the .htaccess lines pass on is read (after WordPress\'s own rewrite)' );
$_SERVER['HTTP_RANGE'] = 'bytes=5-9';
dl_t( 'header' === rrt_dl_range_header()['from'] && 'bytes=5-9' === rrt_dl_range_header()['range'], 'the header itself wins when PHP has it' );
$_SERVER = $dl_srv;
$dl_ht = $dl_dir . '/htaccess-test';
$dl_htf = function () use ( $dl_ht ) {
	return $dl_ht;
};
add_filter( 'rrt_dl_htaccess_file', $dl_htf );
$dl_wpblock = "# BEGIN WordPress\nRewriteEngine On\nRewriteRule . /index.php [L]\n# END WordPress\n";
file_put_contents( $dl_ht, $dl_wpblock );
dl_t( ! rrt_dl_htaccess_has() && true === rrt_dl_htaccess_add() && rrt_dl_htaccess_has(), 'the lines are added' );
rrt_dl_htaccess_add();
$dl_txt = (string) file_get_contents( $dl_ht );
dl_t( 1 === substr_count( $dl_txt, '# BEGIN Foxfire Studio download counting' ) && 0 === strpos( $dl_txt, '# BEGIN Foxfire Studio download counting' ) && false !== strpos( $dl_txt, $dl_wpblock ), 'once, at the top, with WordPress\'s rules kept below' );
dl_t( false !== strpos( $dl_txt, 'RewriteRule ^podcast-file/ - [E=RRT_RANGE:%{HTTP:Range}]' ) && false !== strpos( $dl_txt, 'RewriteRule ^podcast-file/ - [E=Cache-Control:no-cache,E=RRT_SEEN:1]' ), 'only for the short addresses, with the server cache skipped for them' );
dl_t( false !== strpos( $dl_txt, 'Header always set Cache-Control "private, no-store, max-age=0" env=RRT_DL_NOSTORE' ) && false !== strpos( $dl_txt, 'SetEnvIf Request_URI "/podcast-file/" RRT_DL_NOSTORE' ), 'and they ask any CDN to keep no copy of the counting addresses' );
dl_t( rrt_dl_htaccess_current(), 'they are this build\'s lines' );
$dl_cdn = function ( $h ) {
	return array( 'headers' => $h, 'body' => '', 'response' => array( 'code' => 206 ) );
};
dl_t( 'Hostinger\'s CDN' === rrt_dl_cdn_name( $dl_cdn( array( 'server' => 'hcdn', 'x-hcdn-cache-status' => 'HIT' ) ) ) && 'Cloudflare' === rrt_dl_cdn_name( $dl_cdn( array( 'cf-cache-status' => 'HIT' ) ) ) && '' === rrt_dl_cdn_name( $dl_cdn( array( 'server' => 'LiteSpeed' ) ) ), 'a CDN in front is named from its headers' );
file_put_contents( $dl_ht, str_replace( 'E=Cache-Control:no-cache,E=RRT_SEEN:1', 'E=OLD:1', (string) file_get_contents( $dl_ht ) ) );
dl_t( rrt_dl_htaccess_has() && ! rrt_dl_htaccess_current(), 'an older build\'s lines are told apart' );
rrt_dl_htaccess_add();
dl_t( rrt_dl_htaccess_current() && 1 === substr_count( (string) file_get_contents( $dl_ht ), '# BEGIN Foxfire Studio download counting' ), 'and replaced, once' );
$dl_pc_ht = get_option( 'rrt_podcast', null );
update_option( 'rrt_podcast', array_merge( rrt_podcast_settings(), array( 'count' => true ) ) );
update_option( 'rrt_podcast', array_merge( rrt_podcast_settings(), array( 'count' => false ) ) );
dl_t( ! rrt_dl_htaccess_has() && $dl_wpblock === file_get_contents( $dl_ht ), 'switching counting off takes them out, and the file is as it was' );
rrt_dl_htaccess_remove();
dl_t( $dl_wpblock === file_get_contents( $dl_ht ), 'taking out lines that are not there changes nothing' );
null === $dl_pc_ht ? delete_option( 'rrt_podcast' ) : update_option( 'rrt_podcast', $dl_pc_ht );
remove_filter( 'rrt_dl_htaccess_file', $dl_htf );
wp_delete_file( $dl_ht );

/* LiteSpeed's hand-off variants (found live: closed byte ranges got 412 with the usual one). */
dl_t( array( '', 'b', 'c' ) === rrt_dl_variants( 'litespeed' ) && array( '' ) === rrt_dl_variants( 'nginx' ) && array( '' ) === rrt_dl_variants( 'apache' ), 'LiteSpeed has three ways to try, the others one' );
rrt_dl_settings_save( array_merge( rrt_dl_settings(), array( 'check' => array( 'variant' => 'b' ) ) ) );
dl_t( 'b' === rrt_dl_variant(), 'the way that passed is the one used' );
rrt_dl_settings_save( array_merge( rrt_dl_settings(), array( 'check' => array() ) ) );

/* How files are sent. */
rrt_dl_settings_save( array_merge( rrt_dl_settings(), array( 'send' => 'redirect', 'check' => array() ) ) );
dl_t( '' === rrt_dl_how() && '' === rrt_dl_handoff(), 'Redirect: no hand-off' );
rrt_dl_settings_save( array_merge( rrt_dl_settings(), array( 'send' => 'nginx' ) ) );
dl_t( 'nginx' === rrt_dl_how() && '' === rrt_dl_handoff(), 'nginx chosen: no hand-off until a check passes' );
rrt_dl_settings_save( array_merge( rrt_dl_settings(), array( 'check' => array( 'ok' => true, 'how' => 'nginx', 'sig' => rrt_dl_sig( 'nginx' ), 'at' => time() ) ) ) );
dl_t( 'nginx' === rrt_dl_handoff(), 'after a passing check, the hand-off is used' );
rrt_dl_settings_save( array_merge( rrt_dl_settings(), array( 'send' => 'apache' ) ) );
dl_t( '' === rrt_dl_handoff(), 'a check for another way does not count' );
dl_t( false !== strpos( rrt_dl_nginx_lines(), 'internal;' ) && false !== strpos( rrt_dl_nginx_lines(), wp_upload_dir()['basedir'] ), 'the nginx lines name the uploads folder' );
rrt_dl_settings_save( array_merge( rrt_dl_settings(), array( 'send' => 'auto', 'check' => array() ) ) );

if ( null === $dl_pc_opt ) {
	delete_option( 'rrt_podcast' );
} else {
	update_option( 'rrt_podcast', $dl_pc_opt );
}
wp_delete_post( $dl_real, true );
$dl_clear();
foreach ( (array) glob( $dl_dir . '/*' ) as $f ) {
	wp_delete_file( $f );
}
@rmdir( $dl_dir ); // phpcs:ignore
if ( null === $dl_keep_settings ) {
	delete_option( 'rrt_dl' );
} else {
	update_option( 'rrt_dl', $dl_keep_settings );
}

echo "\n" . $GLOBALS['dl_pass'] . ' passed, ' . count( $GLOBALS['dl_fail'] ) . " failed\n";
if ( $GLOBALS['dl_fail'] ) {
	exit( 1 );
}
