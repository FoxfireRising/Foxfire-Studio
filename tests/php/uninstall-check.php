<?php
/**
 * Lists what Foxfire Studio left behind after it was deleted, and checks it
 * against what should stay. Run with wp eval-file after
 * `wp plugin uninstall foxfire-studio`, with "keep" or "delete" (the same
 * choice uninstall-seed.php was given).
 *
 * keep:   everything kept for a reinstall stays; settings, jobs, counters,
 *         the asset copies and the feed address go.
 * delete: nothing of the plugin's is left. The Media Library is untouched.
 *
 * @package FoxfireStudio
 */

$mode = in_array( 'delete', (array) ( isset( $args ) ? $args : array() ), true ) ? 'delete' : 'keep';
global $wpdb;
$pass = 0;
$fail = 0;
$check = function ( $ok, $name, $detail = '' ) use ( &$pass, &$fail ) {
	if ( $ok ) {
		++$pass;
		echo "ok   $name\n";
	} else {
		++$fail;
		echo "FAIL $name" . ( '' !== $detail ? " ($detail)" : '' ) . "\n";
	}
};

if ( function_exists( 'rrt_settings' ) ) {
	echo "FAIL the plugin is still loaded\n";
	exit( 1 );
}

wp_cache_flush();
$like    = '%' . $wpdb->esc_like( 'rrt' ) . '%';
$options = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s OR option_name LIKE %s", $like, '%foxfire%' ) );
$tables  = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'rrt_' ) . '%' ) );
$pmeta   = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_key FROM $wpdb->postmeta WHERE meta_key LIKE %s", $like ) );
$umeta   = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_key FROM $wpdb->usermeta WHERE meta_key LIKE %s", $like ) );
$cron    = array();
foreach ( (array) _get_cron_array() as $hooks ) {
	foreach ( array_keys( (array) $hooks ) as $h ) {
		if ( 0 === strpos( $h, 'rrt_' ) ) {
			$cron[] = $h;
		}
	}
}
$up   = wp_upload_dir( null, false );
$dirs = array_map( 'basename', (array) glob( $up['basedir'] . '/rrt-*', GLOB_ONLYDIR ) );
sort( $options );
sort( $pmeta );
sort( $umeta );
sort( $dirs );
$rules = get_option( 'rewrite_rules' );
$feed  = is_array( $rules ) && false !== strpos( implode( ' ', array_keys( $rules ) ), 'podcast' );

/* Always gone. */
$always_gone = array( 'rrt_col_rewrite', 'rrt_settings', 'rrt_podcast', 'rrt_podcast_flush', 'rrt_backlog_hidden', 'rrt_report', 'rrt_report_last', 'rrt_report_attempts', 'rrt_local', 'rrt_geo', 'rrt_listen_salt', 'rrt_listen_default_v', 'rrt_dl_salt', 'rrt_podcast_ping', 'rrt_offload', 'rrt_offload_files', 'rrt_dl', 'rrt_net' );
$left        = array_values( array_intersect( $always_gone, $options ) );
$check( ! $left, "[$mode] settings and their options are gone", implode( ', ', $left ) );
$tr = array_values( preg_grep( '/^_transient/', $options ) );
$check( ! $tr, "[$mode] short-lived counters and locks are gone", implode( ', ', $tr ) );
$check( ! $cron, "[$mode] scheduled jobs are gone", implode( ', ', array_unique( $cron ) ) );
$check( ! in_array( 'rrt-assets', $dirs, true ), "[$mode] the copies of fonts, encoder, noise model and country database are gone" );
$check( ! in_array( 'rrt-offload-check', $dirs, true ), "[$mode] storage offload's test folder is gone" );
$check( ! in_array( 'rrt-dl-check', $dirs, true ), "[$mode] Check counting's folder is gone" );
$rrt_htx = (string) @file_get_contents( ABSPATH . '.htaccess' ); // phpcs:ignore
$check( false === strpos( $rrt_htx, 'Foxfire Studio download counting' ) && false !== strpos( $rrt_htx, '# BEGIN WordPress' ), "[$mode] its .htaccess lines are gone and the rest of the file stays", $rrt_htx );
@unlink( ABSPATH . '.htaccess' ); // phpcs:ignore
$check( ! $feed, "[$mode] the podcast feed's address is gone from the rewrite rules" );
$check( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'attachment' AND post_title = 'reading'" ) >= 1, "[$mode] the reading's file in the Media Library stays" );

if ( 'keep' === $mode ) {
	/* Every table but the day's download hashes (1.2.0), which are short-lived. */
	$check( count( $tables ) === 15 && ! in_array( $wpdb->prefix . 'rrt_dl_seen', $tables, true ), '[keep] all 15 lasting tables stay (1.24.0\'s download counts among them), and the day\'s download codes go', count( $tables ) . ': ' . implode( ', ', $tables ) );
	$want_opt = array( 'rrt_db_version', 'rrt_embeds_list', 'rrt_listen_rolled_at', 'rrt_recordings_backfilled', 'rrt_shelf_key' );
	$check( array_values( array_diff( $options, preg_grep( '/^_transient/', $options ) ) ) === $want_opt, '[keep] only the database markers, the take shelf folder\'s name and the list of saved pictures stay among the options (Embedded players\' switches go)', implode( ', ', $options ) );
	$want_pm = array( '_rrt_access', '_rrt_book', '_rrt_book_col', '_rrt_col', '_rrt_col_built', '_rrt_col_file', '_rrt_col_match', '_rrt_credit_kind', '_rrt_cues', '_rrt_kept', '_rrt_kind', '_rrt_lang', '_rrt_learned', '_rrt_member_of', '_rrt_music', '_rrt_music_used', '_rrt_notes', '_rrt_pauses', '_rrt_spot', '_rrt_spot_built', '_rrt_spot_copy', '_rrt_spot_log', '_rrt_spots', 'rrt_audio', 'rrt_captions', 'rrt_credit' );
	$check( $pmeta === $want_pm, "[keep] each post's reading, readings kept aside, cue marks, kind of reading, reading language, words, notes, music, collections, audiobook copies, who can hear each reading and sponsor messages stay, and its pick for the feed, what the feed worked out about it and its measured loudness go", implode( ', ', $pmeta ) );
	$check( 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'rrt_collection'" ) && 2 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'attachment' AND post_title LIKE 'collection %'" ), '[keep] collections stay, with their joined files and matched copies' );
	$check( 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'rrt_credit'" ), '[keep] a collection\'s audiobook credits stay' );
	$check( $umeta === array( '_rrt_eq', '_rrt_member', '_rrt_mfeed', '_rrt_mfeed_at', '_rrt_mfeed_seen', '_rrt_sent', '_rrt_shelf', 'rrt_goals', 'rrt_learned', 'rrt_learned_fr', 'rrt_notes', 'rrt_practice_notes', 'rrt_pron_skip' ), "[keep] each person's words (in each language), goals, notes, pronunciation notes, words marked Not needed, sent takes, take shelves, setups' EQ measurements and member feed stay", implode( ', ', $umeta ) );
	$check( $dirs === array( 'rrt-audiobook', 'rrt-embeds', 'rrt-kept', 'rrt-members', 'rrt-sent', 'rrt-shares', 'rrt-shelf', 'rrt-sponsored', 'rrt-whisper' ), '[keep] audiobook copies, saved pictures of YouTube and SoundCloud panels, readings kept aside, readings for members, shared and sent takes, take shelves, sponsored copies and the Whisper files stay', implode( ', ', $dirs ) );
	$check( 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'rrt_spot'" ) && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'attachment' AND post_title = 'message copy'" ), '[keep] sponsor messages stay, with their copies' );
	$mrel = (string) $wpdb->get_var( "SELECT m.meta_value FROM $wpdb->postmeta m JOIN $wpdb->posts p ON p.ID = m.post_id WHERE p.post_title = 'members reading' AND m.meta_key = '_wp_attached_file'" );
	$check( 0 === strpos( $mrel, 'rrt-members/' ) && is_file( $up['basedir'] . '/' . $mrel ), '[keep] a reading for members stays protected', $mrel );
} else {
	$check( ! $tables, '[delete] no tables are left', implode( ', ', $tables ) );
	$check( ! $options, '[delete] no options are left', implode( ', ', $options ) );
	$check( ! $pmeta, '[delete] no post fields are left', implode( ', ', $pmeta ) );
	$check( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'rrt_collection' OR ( post_type = 'attachment' AND post_title LIKE 'collection %' )" ), '[delete] collections are gone, with their joined files and matched copies' );
	$check( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'rrt_credit'" ), '[delete] audiobook credits are gone' );
	$check( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'rrt_spot' OR ( post_type = 'attachment' AND post_title = 'message copy' )" ), '[delete] sponsor messages and their copies are gone' );
	$check( 1 <= (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'attachment' AND post_title = 'message recording' AND post_parent = 0" ), '[delete] a message\'s own recording stays in the Media Library' );
	$check( ! $umeta, '[delete] no user fields are left', implode( ', ', $umeta ) );
	$check( ! $dirs, '[delete] no folders are left in uploads', implode( ', ', $dirs ) );
	$mrel = (string) $wpdb->get_var( "SELECT m.meta_value FROM $wpdb->postmeta m JOIN $wpdb->posts p ON p.ID = m.post_id WHERE p.post_title = 'members reading' AND m.meta_key = '_wp_attached_file'" );
	$check( '2026/11/members-test.mp3' === $mrel && 'members' === @file_get_contents( $up['basedir'] . '/' . $mrel ), '[delete] a reading for members is put back at its public place first, not lost', $mrel );
}

echo "\n$pass passed, $fail failed\n";
if ( $fail ) {
	exit( 1 );
}
