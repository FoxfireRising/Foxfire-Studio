<?php
/**
 * Download counting's hand-off on real web servers (1.24.0), run by
 * tests/wp/handoff.sh inside the test WordPress with the ports of the
 * servers that started (HO_NGINX, HO_APACHE; an empty one is skipped).
 *
 * For each server: Check counting passes through it; a podcast app's
 * requests to a short address come back as 206 with exactly the bytes asked
 * for, the right type and no redirect, every piece reaches the counter, and
 * the download counts once the tag and a minute were asked for. Choosing
 * Redirect sends a 302 instead. Everything made here is removed afterwards.
 */

$GLOBALS['ho_pass'] = 0;
$GLOBALS['ho_fail'] = array();

function ho_t( $ok, $name, $detail = '' ) {
	if ( $ok ) {
		++$GLOBALS['ho_pass'];
		echo "ok   $name\n";
	} else {
		$GLOBALS['ho_fail'][] = $name;
		echo "FAIL $name" . ( '' !== $detail ? ': ' . $detail : '' ) . "\n";
	}
}

global $wpdb;
$ho_servers = array_filter(
	array(
		'nginx'  => getenv( 'HO_NGINX' ),
		'apache' => getenv( 'HO_APACHE' ),
	)
);
if ( ! $ho_servers ) {
	echo "No server started; nothing to check.\n";
	return;
}
$ho_keep_dl = get_option( 'rrt_dl', null );
$ho_keep_pc = get_option( 'rrt_podcast', null );
update_option( 'rrt_podcast', array_merge( rrt_podcast_settings(), array( 'count' => true ) ) );

/* A two-minute reading: a 1,010-byte tag and 1,920,000 bytes of "audio" (128 kbps), every byte telling where it is. */
$ho_dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'ho-test';
wp_mkdir_p( $ho_dir );
$ho_path = $ho_dir . '/handoff-reading.mp3';
$ho_body = "ID3\x03\x00\x00\x00\x00\x07\x68" . str_repeat( "\0", 1000 );
for ( $i = 0; strlen( $ho_body ) < 1010 + 1920000; $i++ ) {
	$ho_body .= sprintf( '%07d|', $i );
}
$ho_body = substr( $ho_body, 0, 1010 + 1920000 );
file_put_contents( $ho_path, $ho_body );
$ho_att = wp_insert_attachment( array( 'post_mime_type' => 'audio/mpeg', 'post_title' => 'Hand-off reading', 'post_status' => 'inherit' ), $ho_path );
wp_update_attachment_metadata( $ho_att, array( 'length' => 120, 'filesize' => strlen( $ho_body ) ) );
$ho_post = wp_insert_post( array( 'post_title' => 'Hand-off test', 'post_content' => 'Words.', 'post_status' => 'publish' ) );
update_post_meta( $ho_post, 'rrt_audio', $ho_att );
$ho_url_path = (string) wp_parse_url( rrt_dl_url( $ho_post, wp_get_attachment_url( $ho_att ) ), PHP_URL_PATH );
$ho_tc       = rrt_table( 'dl_counts' );

foreach ( $ho_servers as $ho_name => $ho_port ) {
	$ho_base = 'http://127.0.0.1:' . $ho_port;
	$ho_wpdb_clear = function () use ( $wpdb, $ho_tc ) {
		$wpdb->query( "DELETE FROM $ho_tc" ); // phpcs:ignore
		$wpdb->query( 'DELETE FROM ' . rrt_table( 'dl_seen' ) ); // phpcs:ignore
		$wpdb->query( 'DELETE FROM ' . rrt_table( 'dl_days' ) ); // phpcs:ignore
	};
	$ho_wpdb_clear();
	rrt_dl_settings_save( array_merge( rrt_dl_settings(), array( 'send' => $ho_name, 'check' => array() ) ) );

	/* Check counting, with the site's address pointed at this server. */
	$ho_home = function ( $url ) use ( $ho_base ) {
		return preg_replace( '#^https?://[^/]+#', $ho_base, $url );
	};
	add_filter( 'home_url', $ho_home );
	$ho_c = rrt_dl_check_run();
	remove_filter( 'home_url', $ho_home );
	ho_t( ! empty( $ho_c['ok'] ), "[$ho_name] Check counting passes", wp_json_encode( $ho_c ) );
	ho_t( $ho_name === rrt_dl_handoff(), "[$ho_name] and the hand-off is then in use" );
	ho_t( ! glob( rrt_dl_check_dir() . '/check-*.mp3' ), "[$ho_name] the check's test file is gone" );
	ho_t( ! empty( $ho_c['fresh'] ) && '' === $ho_c['cdn'] && false !== strpos( wp_json_encode( $ho_c['steps'] ), 'A second request reached counting' ), "[$ho_name] a second request reaches counting too, with no CDN in front" );

	/* An app's requests, as Apple's player makes them. */
	$ho_get = function ( $range, $method = 'GET' ) use ( $ho_base, $ho_url_path ) {
		$args = array(
			'method'      => $method,
			'timeout'     => 20,
			'redirection' => 0,
			'user-agent'  => 'AppleCoreMedia/1.0.0.21E236 (iPhone; U; CPU OS 17_4 like Mac OS X; en_us)',
			'headers'     => '' !== $range ? array( 'Range' => $range ) : array(),
		);
		return wp_remote_request( $ho_base . $ho_url_path, $args );
	};
	$ho_count = function () use ( $wpdb, $ho_tc, $ho_post ) {
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(downloads) FROM $ho_tc WHERE post_id = %d", $ho_post ) ); // phpcs:ignore
	};
	$r = $ho_get( 'bytes=0-1' );
	ho_t( 206 === wp_remote_retrieve_response_code( $r ) && 'ID' === wp_remote_retrieve_body( $r ), "[$ho_name] the probe gets 206 and 2 bytes", wp_remote_retrieve_response_code( $r ) );
	ho_t( 0 === stripos( (string) wp_remote_retrieve_header( $r, 'content-type' ), 'audio/mpeg' ), "[$ho_name] as audio/mpeg", (string) wp_remote_retrieve_header( $r, 'content-type' ) );
	ho_t( '' === (string) wp_remote_retrieve_header( $r, 'location' ) && '' === (string) wp_remote_retrieve_header( $r, 'x-accel-redirect' ) && '' === (string) wp_remote_retrieve_header( $r, 'x-sendfile' ), "[$ho_name] with no redirect and no hand-off header showing" );
	$r = $ho_get( 'bytes=1010-500000' );
	ho_t( 206 === wp_remote_retrieve_response_code( $r ) && substr( $ho_body, 1010, 498991 ) === wp_remote_retrieve_body( $r ), "[$ho_name] a range comes back byte for byte" );
	ho_t( 0 === $ho_count(), "[$ho_name] half a minute has not counted yet" );
	$r = $ho_get( 'bytes=500001-1100000' );
	ho_t( 206 === wp_remote_retrieve_response_code( $r ) && 1 === $ho_count(), "[$ho_name] the piece that passes the tag and a minute counts it", $ho_count() );
	$r = $ho_get( 'bytes=1100001-' );
	ho_t( 206 === wp_remote_retrieve_response_code( $r ) && strlen( $ho_body ) - 1100001 === strlen( wp_remote_retrieve_body( $r ) ) && 1 === $ho_count(), "[$ho_name] the rest of the file comes, still one download" );
	$r = $ho_get( '', 'HEAD' );
	ho_t( 200 === wp_remote_retrieve_response_code( $r ) && (int) wp_remote_retrieve_header( $r, 'content-length' ) === strlen( $ho_body ), "[$ho_name] HEAD gives the size", (string) wp_remote_retrieve_header( $r, 'content-length' ) );

	/* 1.25.0: a reading pointed at a file in a closed folder is never handed off or redirected to (the hand-off ignores the folder's .htaccess). */
	$ho_closed = trailingslashit( wp_upload_dir()['basedir'] ) . 'ho-closed';
	wp_mkdir_p( $ho_closed );
	file_put_contents( $ho_closed . '/.htaccess', "Require all denied\n" );
	file_put_contents( $ho_closed . '/private.mp3', $ho_body );
	$ho_cp = wp_insert_post( array( 'post_title' => 'Hand-off closed test', 'post_content' => 'Words.', 'post_status' => 'publish' ) );
	update_post_meta( $ho_cp, 'rrt_audio', trailingslashit( wp_upload_dir()['baseurl'] ) . 'ho-closed/private.mp3' );
	$r = wp_remote_request( $ho_base . (string) wp_parse_url( rrt_dl_url( $ho_cp, get_post_meta( $ho_cp, 'rrt_audio', true ) ), PHP_URL_PATH ), array( 'timeout' => 20, 'redirection' => 0, 'headers' => array( 'Range' => 'bytes=0-1' ) ) );
	ho_t( 404 === wp_remote_retrieve_response_code( $r ) && 'ID' !== wp_remote_retrieve_body( $r ) && '' === (string) wp_remote_retrieve_header( $r, 'location' ), "[$ho_name] a reading pointed into a closed folder gets 404, never the file", wp_remote_retrieve_response_code( $r ) );
	wp_delete_post( $ho_cp, true );
	wp_delete_file( $ho_closed . '/private.mp3' );
	wp_delete_file( $ho_closed . '/.htaccess' );
	@rmdir( $ho_closed ); // phpcs:ignore
	/* 1.25.0: with counting off, the short address redirects, as before 1.24.0. */
	update_option( 'rrt_podcast', array_merge( rrt_podcast_settings(), array( 'count' => false ) ) );
	$r = $ho_get( 'bytes=0-1' );
	ho_t( 302 === wp_remote_retrieve_response_code( $r ) && false !== strpos( (string) wp_remote_retrieve_header( $r, 'location' ), 'handoff-reading.mp3' ), "[$ho_name] with counting off the short address redirects to the file", wp_remote_retrieve_response_code( $r ) );
	update_option( 'rrt_podcast', array_merge( rrt_podcast_settings(), array( 'count' => true ) ) );

	/* A server that keeps the Range header from PHP, as LiteSpeed does (the test's must-use plugin hides it when asked). */
	$ho_hide = function ( $args ) {
		$args['headers']['X-Foxfire-Hide-Range'] = '1';
		return $args;
	};
	rrt_dl_htaccess_remove();
	add_filter( 'rrt_dl_check_args', $ho_hide );
	add_filter( 'home_url', $ho_home );
	$ho_c = rrt_dl_check_run();
	remove_filter( 'home_url', $ho_home );
	remove_filter( 'rrt_dl_check_args', $ho_hide );
	if ( 'apache' === $ho_name ) {
		ho_t( ! empty( $ho_c['ok'] ) && ! empty( $ho_c['ranges'] ) && rrt_dl_htaccess_has() && false !== strpos( wp_json_encode( $ho_c['steps'] ), 'htaccess' ), "[$ho_name] with the Range header hidden, Check counting adds the .htaccess lines and then sees the range through them", wp_json_encode( $ho_c ) );
		$ho_ht = (string) file_get_contents( rrt_dl_htaccess_file() );
		ho_t( 0 === strpos( $ho_ht, '# BEGIN Foxfire Studio download counting' ) && false !== strpos( $ho_ht, '# BEGIN WordPress' ), "[$ho_name] the lines go at the top, before WordPress's own" );
		$ho_wpdb_clear();
		$r  = wp_remote_request( $ho_base . $ho_url_path, array( 'timeout' => 20, 'redirection' => 0, 'user-agent' => 'Overcast/3.0 (+http://overcast.fm/; iOS podcast app)', 'headers' => array( 'Range' => 'bytes=0-1', 'X-Foxfire-Hide-Range' => '1' ) ) );
		ho_t( false !== stripos( (string) wp_remote_retrieve_header( $r, 'cache-control' ), 'no-store' ), "[$ho_name] the lines ask any CDN to keep no copy of a counting address (Cache-Control: no-store on the file)", (string) wp_remote_retrieve_header( $r, 'cache-control' ) );
		ho_t( 206 === wp_remote_retrieve_response_code( $r ) && 0 === $ho_count(), "[$ho_name] a two-byte probe with the header hidden does not count" );
		$r  = wp_remote_request( $ho_base . $ho_url_path, array( 'timeout' => 20, 'redirection' => 0, 'user-agent' => 'Overcast/3.0 (+http://overcast.fm/; iOS podcast app)', 'headers' => array( 'Range' => 'bytes=0-', 'X-Foxfire-Hide-Range' => '1' ) ) );
		ho_t( 1 === $ho_count(), "[$ho_name] and the whole file then does" );
		update_option( 'rrt_podcast', array_merge( rrt_podcast_settings(), array( 'count' => false ) ) );
		ho_t( ! rrt_dl_htaccess_has() && false !== strpos( (string) file_get_contents( rrt_dl_htaccess_file() ), '# BEGIN WordPress' ), "[$ho_name] switching counting off takes the lines out and leaves the rest" );
		update_option( 'rrt_podcast', array_merge( rrt_podcast_settings(), array( 'count' => true ) ) );
	} else {
		ho_t( ! empty( $ho_c['ok'] ) && isset( $ho_c['ranges'] ) && ! $ho_c['ranges'] && ! rrt_dl_htaccess_has() && '' !== $ho_c['why'], "[$ho_name] with the Range header hidden, the check says counting cannot see ranges (nginx reads no .htaccess)", wp_json_encode( $ho_c ) );
	}
	rrt_dl_htaccess_remove();
	rrt_dl_settings_save( array_merge( rrt_dl_settings(), array( 'send' => $ho_name, 'check' => array() ) ) );

	/* The browser's way (found live: a host's firewall turned away the site's request to itself with a 412): part one gives a test address, the browser asks twice, part two judges. Here the CLI plays the browser. */
	$ho_browser = function ( $hide ) use ( $ho_home ) {
		$out = array();
		for ( $attempt = 0; $attempt < 4; $attempt++ ) {
			add_filter( 'home_url', $ho_home );
			$st = rrt_dl_check_start();
			remove_filter( 'home_url', $ho_home );
			if ( ! empty( $st['done'] ) ) {
				return $st['result'];
			}
			$got = array();
			for ( $i = 0; $i < 2; $i++ ) {
				$r     = wp_remote_get( $st['url'], array( 'timeout' => 20, 'redirection' => 0, 'headers' => array_merge( array( 'Range' => 'bytes=10-29' ), $hide ? array( 'X-Foxfire-Hide-Range' => '1' ) : array() ) ) );
				$got[] = array( 'code' => wp_remote_retrieve_response_code( $r ), 'body' => wp_remote_retrieve_body( $r ), 'headers' => array( 'content-type' => wp_remote_retrieve_header( $r, 'content-type' ) ) );
			}
			$out = rrt_dl_check_done( $st['token'], $got, $attempt );
			if ( empty( $out['retry'] ) ) {
				$out['attempts'] = $attempt + 1;
				return $out;
			}
			sleep( 1 );
		}
		return $out;
	};
	rrt_dl_htaccess_remove();
	rrt_dl_settings_save( array_merge( rrt_dl_settings(), array( 'send' => $ho_name, 'check' => array() ) ) );
	$ho_b = $ho_browser( false );
	ho_t( ! empty( $ho_b['ok'] ) && ! empty( $ho_b['ranges'] ) && ! empty( $ho_b['fresh'] ) && 1 === $ho_b['attempts'] && $ho_name === rrt_dl_handoff(), "[$ho_name] the browser's way passes in one round, and the hand-off is in use", wp_json_encode( $ho_b ) );
	if ( 'apache' === $ho_name ) {
		rrt_dl_htaccess_remove();
		$ho_b = $ho_browser( true );
		ho_t( ! empty( $ho_b['ok'] ) && ! empty( $ho_b['ranges'] ) && 2 === $ho_b['attempts'] && rrt_dl_htaccess_has() && false !== strpos( (string) $ho_b['fix'], 'every step now passes' ), "[$ho_name] with the Range header hidden, the browser's way adds the lines, checks again and passes", wp_json_encode( $ho_b ) );
	}
	rrt_dl_htaccess_remove();
	ho_t( ! get_transient( 'rrt_dl_checkp_x' ) && ! glob( rrt_dl_check_dir() . '/check-*.mp3' ), "[$ho_name] the browser's way leaves no test file behind" );

	/* Redirect, when chosen. */
	rrt_dl_settings_save( array_merge( rrt_dl_settings(), array( 'send' => 'redirect' ) ) );
	$r = $ho_get( 'bytes=0-1' );
	ho_t( 302 === wp_remote_retrieve_response_code( $r ) && false !== strpos( (string) wp_remote_retrieve_header( $r, 'location' ), 'handoff-reading.mp3' ), "[$ho_name] Redirect sends a 302 to the file", wp_remote_retrieve_response_code( $r ) );
	$ho_wpdb_clear();
}

/* Tidy up. */
wp_delete_post( $ho_post, true );
wp_delete_attachment( $ho_att, true );
foreach ( (array) glob( $ho_dir . '/*' ) as $f ) {
	wp_delete_file( $f );
}
@rmdir( $ho_dir ); // phpcs:ignore
null === $ho_keep_dl ? delete_option( 'rrt_dl' ) : update_option( 'rrt_dl', $ho_keep_dl );
null === $ho_keep_pc ? delete_option( 'rrt_podcast' ) : update_option( 'rrt_podcast', $ho_keep_pc );

echo "\n" . $GLOBALS['ho_pass'] . ' passed, ' . count( $GLOBALS['ho_fail'] ) . " failed\n";
if ( $GLOBALS['ho_fail'] ) {
	exit( 1 );
}
