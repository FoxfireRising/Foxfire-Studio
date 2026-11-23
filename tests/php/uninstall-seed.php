<?php
/**
 * Fills the uninstall test site with something of every kind Foxfire Studio
 * keeps, so tests/php/uninstall-check.php can see what deleting the plugin
 * leaves behind. Run with wp eval-file, after the plugin is activated.
 * Pass "delete" to tick "also delete everything else" in the settings.
 *
 * @package FoxfireStudio
 */

$delete = in_array( 'delete', (array) ( isset( $args ) ? $args : array() ), true );
global $wpdb;

/* Settings, with the delete box as asked, and the podcast feed switched on. */
$s = rrt_settings();
$s['stats']['delete_on_uninstall'] = $delete;
update_option( 'rrt_settings', $s );
update_option( 'rrt_podcast', array( 'on' => true, 'title' => 'Test show' ) );
update_option( 'rrt_podcast_flush', 1 );
/* Storage offload (1.23.0): settings with a key, a list of copies and a test file left by an interrupted check. */
update_option( 'rrt_offload', array( 'on' => true, 'mode' => 's3', 's3_secret' => 'x' ), false );
update_option( 'rrt_offload_files', array( '2026/11/a.mp3' => array( 'k' => 'foxfire/0123456789/a.mp3' ) ), false );
update_option( 'rrt_backlog_hidden', array( 1 ), false );
update_option( 'rrt_report', array( 'on' => true ) );
update_option( 'rrt_report_last', '2001-01-01' );
update_option( 'rrt_report_attempts', array( 1 ) );
update_option( 'rrt_local', array( 'fonts' => 1 ) );
update_option( 'rrt_geo', array( 'updated' => 1 ) );
update_option( 'rrt_listen_rolled_at', 1 );
update_option( 'rrt_recordings_backfilled', 1 );
rrt_listen_salt();
rrt_dl_salt();

/* A row in every table the plugin made, so a table left behind is not empty. */
$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'rrt_' ) . '%' ) );
foreach ( array( 'takes', 'recordings', 'sessions', 'buckets', 'events', 'daily', 'daily_sources', 'markers', 'reports', 'shares', 'share_notes', 'downloads', 'dl_seen', 'dl_counts', 'dl_days', 'dl_ads' ) as $t ) {
	if ( ! in_array( $wpdb->prefix . 'rrt_' . $t, $tables, true ) ) {
		echo "MISSING TABLE rrt_$t\n";
	}
}

/* A post with every kind of post field: its reading, captions, credit, cue marks, words, pronunciation notes, music. */
$post = (int) $wpdb->get_var( "SELECT ID FROM $wpdb->posts WHERE post_type = 'post' AND post_status = 'publish' ORDER BY ID LIMIT 1" );
$att  = (int) $wpdb->get_var( "SELECT ID FROM $wpdb->posts WHERE post_type = 'attachment' AND post_title = 'reading' LIMIT 1" );
if ( ! $att ) {
	$att = wp_insert_attachment( array( 'post_title' => 'reading', 'post_mime_type' => 'audio/mpeg', 'post_status' => 'inherit' ), false, $post );
}
update_post_meta( $post, 'rrt_audio', $att );
update_post_meta( $post, 'rrt_captions', $att );
update_post_meta( $post, 'rrt_credit', 'read by a tester' );
update_post_meta( $post, '_rrt_cues', array( array( 'i' => 1, 'k' => 'pause' ) ) );
/* Kind of reading (1.11.0). */
update_post_meta( $post, '_rrt_kind', 'verse' );
/* Reading language (1.33.0). */
update_post_meta( $post, '_rrt_lang', 'fr' );
update_post_meta( $post, '_rrt_learned', array( 'fox' ) );
update_post_meta( $post, '_rrt_notes', array( array( 'k' => 'samhain', 'n' => 'SOW-in' ) ) );
update_post_meta( $post, '_rrt_soundbite', array( 's' => 4.85, 'd' => 38.4, 'text' => 'x', 'audio' => 'http://example.test/r.mp3' ) );
/* What the feed worked out about the reading (1.10.0). */
update_post_meta( $post, '_rrt_feed', array( 'key' => 'x', 'chapters' => array(), 'top' => null ) );
update_post_meta( $post, '_rrt_music', 'none' );
update_post_meta( $post, '_rrt_music_used', array( 'audio' => $att ) );
/* A collection (1.19.0) with its joined file, captions and a matched copy, and a reading's measured loudness. */
update_post_meta( $post, '_rrt_level', array( 'key' => 'x', 'lufs' => -19 ) );
$col = wp_insert_post( array( 'post_type' => 'rrt_collection', 'post_title' => 'A collection', 'post_status' => 'publish' ) );
update_post_meta( $col, '_rrt_col', array( 'items' => array( $post ), 'gap' => 2, 'feed' => true, 'type' => 'bonus' ) );
$cj = wp_insert_attachment( array( 'post_title' => 'collection mp3', 'post_mime_type' => 'audio/mpeg', 'post_status' => 'inherit' ), false, $col );
update_post_meta( $cj, '_rrt_col_file', 'audio' );
$cm = wp_insert_attachment( array( 'post_title' => 'collection match', 'post_mime_type' => 'audio/mpeg', 'post_status' => 'inherit' ), false, $col );
update_post_meta( $cm, '_rrt_col_match', array( 'post' => $post, 'key' => 'x', 'lufs' => -19 ) );
update_post_meta( $col, '_rrt_col_built', array( 'key' => 'x', 'att' => $cj ) );
update_post_meta( $col, 'rrt_audio', $cj );
/* Audiobook export (1.20.0): an audiobook copy, a collection's sample choice and its opening credits with their copy. */
update_post_meta( $post, '_rrt_book', array( 'file' => 'book.mp3', 'secs' => 10, 'at' => time() ) );
update_post_meta( $col, '_rrt_book_col', array( 'post' => $post, 'start' => null, 'len' => 3 ) );
$cr = wp_insert_post( array( 'post_type' => 'rrt_credit', 'post_title' => 'A collection.', 'post_content' => 'Written by Robin.', 'post_status' => 'publish', 'post_parent' => $col ) );
update_post_meta( $cr, '_rrt_credit_kind', 'open' );
update_post_meta( $cr, '_rrt_book', array( 'file' => 'credit.mp3', 'secs' => 5, 'at' => time() ) );
/* Member feeds (1.21.0): a post for members, its file kept for members, and a member's key, choice and activity. */
update_post_meta( $post, '_rrt_access', array( 'who' => 'members', 'days' => 7 ) );
$up0 = wp_upload_dir( null, false );
foreach ( (array) glob( $up0['basedir'] . '/2026/11/members-test*.mp3' ) as $old ) {
	unlink( $old ); // A file an earlier round put back.
}
wp_mkdir_p( $up0['basedir'] . '/rrt-members/2026/11' );
file_put_contents( $up0['basedir'] . '/rrt-members/2026/11/members-test.mp3', 'members' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
foreach ( get_posts( array( 'post_type' => 'attachment', 'title' => 'members reading', 'numberposts' => -1, 'post_status' => 'any', 'fields' => 'ids' ) ) as $old ) {
	$wpdb->delete( $wpdb->posts, array( 'ID' => $old ) );
	$wpdb->delete( $wpdb->postmeta, array( 'post_id' => $old ) );
}
$ma = wp_insert_attachment( array( 'post_title' => 'members reading', 'post_mime_type' => 'audio/mpeg', 'post_status' => 'inherit' ), false, $post );
update_post_meta( $ma, '_wp_attached_file', 'rrt-members/2026/11/members-test.mp3' );
update_post_meta( $ma, '_rrt_member_of', $post );
update_user_meta( 1, '_rrt_mfeed', str_repeat( 'a', 32 ) );
update_user_meta( 1, '_rrt_mfeed_at', time() );
update_user_meta( 1, '_rrt_member', 'always' );
update_user_meta( 1, '_rrt_mfeed_seen', array( 'at' => time(), 'app' => 'Overcast', 'nets' => array() ) );
/* Sponsor messages (1.22.0): a message with its recording and a copy, a reading's choices, pauses and sponsored copy, and the message's log. */
$sp = wp_insert_post( array( 'post_type' => 'rrt_spot', 'post_title' => 'A message', 'post_content' => 'Supported by Acme.', 'post_status' => 'publish' ) );
update_post_meta( $sp, '_rrt_spot', array( 'who' => 'Acme', 'kind' => 'paid' ) );
update_post_meta( $sp, '_rrt_spot_log', array( array( 'post' => $post, 'slot' => 'pre', 'from' => time(), 'to' => 0 ) ) );
$spr = wp_insert_attachment( array( 'post_title' => 'message recording', 'post_mime_type' => 'audio/mpeg', 'post_status' => 'inherit' ), false, $sp );
$spc = wp_insert_attachment( array( 'post_title' => 'message copy', 'post_mime_type' => 'audio/mpeg', 'post_status' => 'inherit' ), false, $sp );
update_post_meta( $spc, '_rrt_spot_copy', array( 'need' => '128-44100-1|-19.0', 'src' => 'x' ) );
update_post_meta( $sp, 'rrt_audio', $spr );
update_post_meta( $post, '_rrt_spots', array( 'mid' => 300 ) );
update_post_meta( $post, '_rrt_pauses', array( 'key' => 'x', 'list' => array() ) );
update_post_meta( $post, '_rrt_spot_built', array( 'key' => 'x', 'rel' => $post . '/a-reading-sp0123abcd.mp3' ) );
wp_mkdir_p( $up0['basedir'] . '/rrt-sponsored/' . $post );
file_put_contents( $up0['basedir'] . '/rrt-sponsored/' . $post . '/a-reading-sp0123abcd.mp3', 'sponsored' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
/* Download counting to the IAB guidelines (1.24.0): its settings, a downloaded list's facts and file, a check's leftovers, and a row in each new table. */
update_option( 'rrt_dl', array( 'send' => 'nginx', 'shared' => '203.0.113.0/24', 'shared_ok' => time() ) );
update_option( 'rrt_net', array( 'saved' => time(), 'v4' => 1, 'v6' => 0 ), false );
wp_mkdir_p( $up0['basedir'] . '/rrt-assets' );
file_put_contents( $up0['basedir'] . '/rrt-assets/datacenters.bin', 'RRTD' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
file_put_contents( ABSPATH . '.htaccess', implode( "\n", rrt_dl_htaccess_lines() ) . "\n\n# BEGIN WordPress\n# END WordPress\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
wp_mkdir_p( $up0['basedir'] . '/rrt-dl-check' );
file_put_contents( $up0['basedir'] . '/rrt-dl-check/seen-abcd', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
$wpdb->replace( rrt_table( 'dl_counts' ), array( 'day' => '2026-11-01', 'post_id' => $post, 'app' => 'Overcast', 'device' => 'mobile', 'feed' => 'public', 'downloads' => 2 ) );
$wpdb->replace( rrt_table( 'dl_days' ), array( 'day' => '2026-11-01', 'consumers' => 2, 'members' => 0 ) );
$wpdb->replace( rrt_table( 'dl_ads' ), array( 'day' => '2026-11-01', 'spot_id' => 1, 'post_id' => $post, 'slot' => 'pre', 'delivered' => 2 ) );
$wpdb->replace( rrt_table( 'dl_seen' ), array( 'day' => '2026-11-01', 'h' => str_repeat( 'b', 32 ), 'got' => 10, 'spans' => '0-9', 'done' => 0 ) );
wp_mkdir_p( $up0['basedir'] . '/rrt-offload-check' );
file_put_contents( $up0['basedir'] . '/rrt-offload-check/check-abcd1234.mp3', 'check' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
/* A reading kept aside (1.8.0). */
update_post_meta( $post, '_rrt_kept', array( array( 'id' => 'abc', 'at' => time(), 'audio' => array( 'file' => 'abc-audio.mp3' ) ) ) );

/* Each person's fields: taught words, goals, practice notes, a sent take, pronunciation notes, the setups' EQ measurements (1.13.0), words marked Not needed (1.15.0). */
foreach ( array( 'rrt_learned', 'rrt_learned_fr', 'rrt_goals', 'rrt_practice_notes', '_rrt_sent', 'rrt_notes', '_rrt_eq', 'rrt_pron_skip' ) as $k ) {
	update_user_meta( 1, $k, array( 'x' ) );
}

/* Short-lived counters and locks. */
set_transient( 'rrt_dash_fresh', 1, HOUR_IN_SECONDS );
set_transient( 'rrt_pron_index', array( 'sig' => 'x' ), HOUR_IN_SECONDS );
set_transient( 'rrt_report_lock', 1, HOUR_IN_SECONDS );
set_transient( 'rrt_fonts_plan', array( 1 ), HOUR_IN_SECONDS );
set_transient( 'rrt_dlip_uninstalltest', 1, HOUR_IN_SECONDS );
set_transient( 'rrt_dlid_uninstalltest', 1, DAY_IN_SECONDS );
set_transient( 'rrt_dltag_uninstalltest', 1010, DAY_IN_SECONDS );
set_transient( 'rrt_dl_check_uninstalltest', array( 'file' => 'x' ), HOUR_IN_SECONDS );
set_transient( 'rrt_lnew_2001010100', 1, HOUR_IN_SECONDS );
set_transient( 'rrt_lip_abcdef', 1, HOUR_IN_SECONDS );
set_transient( 'rrt_shn_1', 1, HOUR_IN_SECONDS );

/* The last pings, and one waiting (1.10.0). */
update_option( 'rrt_podcast_ping', array( 'at' => time(), 'index' => '200', 'hub' => '204' ), false );
if ( ! wp_next_scheduled( 'rrt_podcast_ping' ) ) {
	wp_schedule_single_event( time() + 600, 'rrt_podcast_ping' );
}

/* Scheduled jobs. */
foreach ( array( 'rrt_listen_hourly', 'rrt_listen_daily', 'rrt_report_check', 'rrt_share_hourly' ) as $h ) {
	if ( ! wp_next_scheduled( $h ) ) {
		wp_schedule_event( time() + 3600, 'hourly', $h );
	}
}

/* The plugin's folders in uploads. */
$up = wp_upload_dir( null, false );
foreach ( array( 'rrt-assets/fonts', 'rrt-whisper/onnx-community', 'rrt-shares', 'rrt-sent', 'rrt-kept', 'rrt-audiobook' ) as $d ) {
	wp_mkdir_p( $up['basedir'] . '/' . $d );
	file_put_contents( $up['basedir'] . '/' . $d . '/test.bin', 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
}

/* The take shelf on the site (1.34.0): the setting, a take of the admin's in the random folder, and its list. */
update_option( 'rrt_shelf', array( 'on' => true, 'limit' => 2048 ) );
wp_mkdir_p( rrt_shelf_dir() );
file_put_contents( rrt_shelf_dir() . '1-tseedtake1.flac', 'fLaC' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
file_put_contents( rrt_shelf_dir() . '1-tseedtake1.json', '{}' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
update_user_meta( 1, '_rrt_shelf', array( array( 'id' => 'tseedtake1', 'post' => 1, 'n' => 1, 'at' => time() * 1000, 'seconds' => 1, 'bytes' => 4, 'have' => 4, 'state' => 'ready', 'sum' => '0000000000000000-1', 'updated' => time() ) ) );

/* YouTube and SoundCloud players that load on play (1.35.0): both switches, a saved picture and its list. */
update_option( 'rrt_embeds', array( 'yt' => true, 'sc' => true ) );
rrt_embeds_dir_ready();
file_put_contents( rrt_embeds_dir() . 'yt-seedvideo01-abcdef-seed01.png', 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
rrt_embeds_list_save( array( 'yt-seedvideo01-abcdef' => array( 't' => 'Seed', 'f' => 'yt-seedvideo01-abcdef-seed01.png', 'w' => 2, 'h' => 1, 'at' => time(), 'seen' => time(), 'fail' => 0 ) ) );

/* The podcast feed's address in the saved rewrite rules. */
global $wp_rewrite;
$wp_rewrite->set_permalink_structure( '/%postname%/' );
add_feed( 'podcast', 'rrt_podcast_feed' );
flush_rewrite_rules( false );
$rules = get_option( 'rewrite_rules' );
echo ( is_array( $rules ) && false !== strpos( implode( ' ', array_keys( $rules ) ), 'podcast' ) ) ? "seeded\n" : "seeded, but the feed address is not in the rewrite rules\n";
