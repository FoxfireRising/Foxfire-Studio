<?php
/**
 * Runs when the plugin is deleted from the Plugins screen. Always removes
 * the saved settings, scheduled jobs and short-lived counters, and the
 * plugin's own copies of the fonts, MP3 encoder, noise model and country
 * database in uploads/rrt-assets, and storage offload's settings and list of
 * copies (1.23.0; the outside storage itself is never touched), and download
 * counting's settings and its downloaded list of data centres (1.24.0), and
 * Embedded players' two switches (1.35.0).
 *
 * Everything else stays unless Settings, Reader stats asks for it to be
 * deleted too: reader stats and listener analytics (their tables), cue marks,
 * pronunciation notes (1.1.0), podcast download counts (1.2.0; with 1.24.0's counts by device and feed, podcast consumers a day and sponsor messages delivered), words taught, shared takes (with their comments and audio), takes sent from
 * a phone, take shelves kept on the site (1.34.0, uploads/rrt-shelf), the saved
 * pictures of YouTube and SoundCloud panels (1.35.0, uploads/rrt-embeds), each post's reading, captions and Read by links, readings kept
 * aside (1.8.0, uploads/rrt-kept), audiobook copies and credits (1.20.0,
 * uploads/rrt-audiobook), readings for members (1.21.0, uploads/rrt-members,
 * put back at their public place first) and each person's member feed, sponsor
 * messages and their sponsored copies (1.22.0, uploads/rrt-sponsored), and the
 * self-hosted Whisper files in uploads/rrt-whisper (0.26.0). MP3s and captions
 * in the Media Library are never deleted.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
/*
 * The same settings, tables and files belong to Reading Room Teleprompter,
 * this plugin's earlier name. If that copy is still installed, keep them.
 */
if ( file_exists( WP_PLUGIN_DIR . '/reading-room-teleprompter/reading-room-teleprompter.php' ) ) {
	return;
}
/* Reader stats (0.9.0) are removed only when Settings asks for it. */
$rrt_saved = get_option( 'rrt_settings', array() );
if ( ! empty( $rrt_saved['stats']['delete_on_uninstall'] ) ) {
	global $wpdb;
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rrt_takes" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rrt_recordings" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
	/* Listener analytics (0.10.0). */
	foreach ( array( 'sessions', 'buckets', 'events', 'daily', 'daily_sources', 'markers', 'reports', 'shares', 'share_notes', 'downloads', 'dl_counts', 'dl_days', 'dl_ads' ) as $rrt_t ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rrt_{$rrt_t}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
	delete_option( 'rrt_listen_rolled_at' );
	delete_metadata( 'user', 0, 'rrt_learned', '', true );
	delete_metadata( 'user', 0, 'rrt_goals', '', true );
	delete_metadata( 'user', 0, 'rrt_practice_notes', '', true );
	/* Cue marks (0.15.0), kept with each post. */
	delete_metadata( 'post', 0, '_rrt_cues', '', true );
	/* Kind of reading (1.11.0), kept with each post like the cue marks. */
	delete_metadata( 'post', 0, '_rrt_kind', '', true );
	/* Reading language (1.33.0), kept with each post, and each reader's taught words in other languages. */
	delete_metadata( 'post', 0, '_rrt_lang', '', true );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( 'rrt_learned_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	/* Learned words per post (0.16.0). */
	delete_metadata( 'post', 0, '_rrt_learned', '', true );
	/* Pronunciation notes (1.1.0): each post's, and each reader's own. */
	delete_metadata( 'post', 0, '_rrt_notes', '', true );
	delete_metadata( 'user', 0, 'rrt_notes', '', true );
	/* The pronunciation list (1.15.0): the words each reader marked Not needed in its suggestions. */
	delete_metadata( 'user', 0, 'rrt_pron_skip', '', true );
	/* Voice polish (1.13.0): each reader's setups and their band measurements for the EQ. */
	delete_metadata( 'user', 0, '_rrt_eq', '', true );
	/* The music bed (0.24.0): each post's choice, and which track is under its reading. */
	delete_metadata( 'post', 0, '_rrt_music', '', true );
	delete_metadata( 'post', 0, '_rrt_music_used', '', true );
	/* Each post's reading, captions and Read by credit (0.26.0); the files stay in the Media Library. */
	delete_metadata( 'post', 0, 'rrt_audio', '', true );
	delete_metadata( 'post', 0, 'rrt_captions', '', true );
	delete_metadata( 'post', 0, 'rrt_credit', '', true );
	/* Shared takes (0.17.0): the audio still waiting in uploads/rrt-shares. */
	$rrt_up2 = wp_upload_dir( null, false );
	$rrt_sh  = trailingslashit( $rrt_up2['basedir'] ) . 'rrt-shares';
	if ( is_dir( $rrt_sh ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( WP_Filesystem() ) {
			global $wp_filesystem;
			$wp_filesystem->delete( $rrt_sh, true );
		}
	}
	/* Takes sent from a phone (0.21.0): uploads/rrt-sent and each person's list. */
	$rrt_se = trailingslashit( $rrt_up2['basedir'] ) . 'rrt-sent';
	if ( is_dir( $rrt_se ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( WP_Filesystem() ) {
			global $wp_filesystem;
			$wp_filesystem->delete( $rrt_se, true );
		}
	}
	delete_metadata( 'user', 0, '_rrt_sent', '', true );
	/* The take shelf on the site (1.34.0): uploads/rrt-shelf, each person's list and the folder's random name. */
	$rrt_tsh = trailingslashit( $rrt_up2['basedir'] ) . 'rrt-shelf';
	if ( is_dir( $rrt_tsh ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( WP_Filesystem() ) {
			global $wp_filesystem;
			$wp_filesystem->delete( $rrt_tsh, true );
		}
	}
	delete_metadata( 'user', 0, '_rrt_shelf', '', true );
	delete_option( 'rrt_shelf_key' );
	/* YouTube and SoundCloud players that load on play (1.35.0): the saved pictures in uploads/rrt-embeds and their list. */
	$rrt_emb = trailingslashit( $rrt_up2['basedir'] ) . 'rrt-embeds';
	if ( is_dir( $rrt_emb ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( WP_Filesystem() ) {
			global $wp_filesystem;
			$wp_filesystem->delete( $rrt_emb, true );
		}
	}
	delete_option( 'rrt_embeds_list' );
	/* Readings kept aside (1.8.0): uploads/rrt-kept and each post's list. */
	$rrt_ke = trailingslashit( $rrt_up2['basedir'] ) . 'rrt-kept';
	if ( is_dir( $rrt_ke ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( WP_Filesystem() ) {
			global $wp_filesystem;
			$wp_filesystem->delete( $rrt_ke, true );
		}
	}
	delete_metadata( 'post', 0, '_rrt_kept', '', true );
	/* The self-hosted Whisper files (0.26.0). */
	$rrt_wh = trailingslashit( $rrt_up2['basedir'] ) . 'rrt-whisper';
	if ( is_dir( $rrt_wh ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( WP_Filesystem() ) {
			global $wp_filesystem;
			$wp_filesystem->delete( $rrt_wh, true );
		}
	}
	/* Collections (1.19.0): each collection, with its joined MP3, captions and matched copies. The readings' own files stay. */
	$rrt_cols = $wpdb->get_col( "SELECT ID FROM $wpdb->posts WHERE post_type = 'rrt_collection'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	foreach ( $rrt_cols as $rrt_c ) {
		foreach ( get_children( array( 'post_parent' => (int) $rrt_c, 'post_type' => 'attachment', 'numberposts' => -1, 'post_status' => 'any' ) ) as $rrt_a ) {
			wp_delete_attachment( $rrt_a->ID, true );
		}
		wp_delete_post( (int) $rrt_c, true );
	}
	/* Audiobook export (1.20.0): the audiobook copies in uploads/rrt-audiobook, each post's record of its copy, each collection's sample choice, and the credits. */
	$rrt_bk = trailingslashit( $rrt_up2['basedir'] ) . 'rrt-audiobook';
	if ( is_dir( $rrt_bk ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( WP_Filesystem() ) {
			global $wp_filesystem;
			$wp_filesystem->delete( $rrt_bk, true );
		}
	}
	delete_metadata( 'post', 0, '_rrt_book', '', true );
	delete_metadata( 'post', 0, '_rrt_book_col', '', true );
	$rrt_crs = $wpdb->get_col( "SELECT ID FROM $wpdb->posts WHERE post_type = 'rrt_credit'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	foreach ( $rrt_crs as $rrt_cr ) {
		wp_delete_post( (int) $rrt_cr, true );
	}
	/* Member feeds (1.21.0): every reading kept for members goes back to its public place first, so none is lost or left hidden; then the folder, each post's choice and each person's key, choice and activity go. */
	$rrt_mrows = $wpdb->get_results( "SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_value LIKE 'rrt-members/%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	foreach ( $rrt_mrows as $rrt_m ) {
		/* 1.25.0 keeps each file in a random folder of its own: that folder is left out. */
		$rrt_rel  = (string) preg_replace( '#^[a-z0-9]{20}/#', '', substr( (string) $rrt_m->meta_value, strlen( 'rrt-members/' ) ) );
		$rrt_from = trailingslashit( $rrt_up2['basedir'] ) . $rrt_m->meta_value;
		if ( '' === $rrt_rel || false !== strpos( $rrt_rel, '..' ) || ! file_exists( $rrt_from ) ) {
			continue;
		}
		$rrt_to = trailingslashit( $rrt_up2['basedir'] ) . $rrt_rel;
		wp_mkdir_p( dirname( $rrt_to ) );
		if ( file_exists( $rrt_to ) ) {
			$rrt_to  = trailingslashit( dirname( $rrt_to ) ) . wp_unique_filename( dirname( $rrt_to ), wp_basename( $rrt_to ) );
			$rrt_rel = substr( $rrt_to, strlen( trailingslashit( $rrt_up2['basedir'] ) ) );
		}
		if ( @rename( $rrt_from, $rrt_to ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			update_post_meta( (int) $rrt_m->post_id, '_wp_attached_file', $rrt_rel );
			/* A field that held the file's checked address gets its public one back. */
			$rrt_url = (string) wp_get_attachment_url( (int) $rrt_m->post_id );
			$wpdb->query( $wpdb->prepare( "UPDATE $wpdb->postmeta SET meta_value = %s WHERE meta_value LIKE %s", $rrt_url, '%/podcast-member/file/' . (int) $rrt_m->post_id . '/%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}
	$rrt_mf = trailingslashit( $rrt_up2['basedir'] ) . 'rrt-members';
	if ( is_dir( $rrt_mf ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( WP_Filesystem() ) {
			global $wp_filesystem;
			$wp_filesystem->delete( $rrt_mf, true );
		}
	}
	delete_option( 'rrt_mfiles_layout' );
	delete_metadata( 'post', 0, '_rrt_access', '', true );
	delete_metadata( 'post', 0, '_rrt_member_of', '', true );
	foreach ( array( '_rrt_mfeed', '_rrt_mfeed_at', '_rrt_member', '_rrt_mfeed_seen' ) as $rrt_k ) {
		delete_metadata( 'user', 0, $rrt_k, '', true );
	}
	/* Sponsor messages (1.22.0): the sponsored copies in uploads/rrt-sponsored, each message's copies in its readings' sound, the messages themselves, and each reading's choices, pauses and record. The readings were never changed, and the messages' own recordings stay in the Media Library. */
	$rrt_sp = trailingslashit( $rrt_up2['basedir'] ) . 'rrt-sponsored';
	if ( is_dir( $rrt_sp ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( WP_Filesystem() ) {
			global $wp_filesystem;
			$wp_filesystem->delete( $rrt_sp, true );
		}
	}
	$rrt_spc = $wpdb->get_col( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_rrt_spot_copy'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	foreach ( $rrt_spc as $rrt_c ) {
		wp_delete_attachment( (int) $rrt_c, true );
	}
	$rrt_sps = $wpdb->get_col( "SELECT ID FROM $wpdb->posts WHERE post_type = 'rrt_spot'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	foreach ( $rrt_sps as $rrt_s ) {
		/* Their recordings are kept: detached, so deleting the message leaves them in the Media Library. */
		$wpdb->query( $wpdb->prepare( "UPDATE $wpdb->posts SET post_parent = 0 WHERE post_parent = %d AND post_type = 'attachment'", (int) $rrt_s ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_delete_post( (int) $rrt_s, true );
	}
	foreach ( array( '_rrt_spots', '_rrt_pauses', '_rrt_spot_built' ) as $rrt_k ) {
		delete_metadata( 'post', 0, $rrt_k, '', true );
	}
	delete_option( 'rrt_db_version' );
	delete_option( 'rrt_recordings_backfilled' );
}
delete_option( 'rrt_settings' );
/* The podcast feed's settings (0.22.0). */
delete_option( 'rrt_podcast' );
delete_option( 'rrt_podcast_flush' );
/* Storage offload (1.23.0): its settings, keys and list of copies. The outside storage itself is never touched. */
delete_option( 'rrt_offload' );
delete_option( 'rrt_offload_files' );
/* Readings to make (1.4.0): the posts taken off the list, a setting like the others. */
delete_option( 'rrt_backlog_hidden' );
/* Clip suggestions (1.7.0): each post's pick for the feed, a choice like the others. */
delete_metadata( 'post', 0, '_rrt_soundbite', '', true );
/* The full Podcasting 2.0 feed (1.10.0): what the feed worked out about each reading (chapters, top suggestion), the last pings and any ping waiting to go. */
delete_metadata( 'post', 0, '_rrt_feed', '', true );
/* Collections (1.19.0): each reading's measured loudness (worked out again when needed) and the marker for the addresses. */
delete_metadata( 'post', 0, '_rrt_level', '', true );
delete_option( 'rrt_col_rewrite' );
delete_option( 'rrt_podcast_ping' );
wp_clear_scheduled_hook( 'rrt_podcast_ping' );
delete_option( 'rrt_local' );
delete_option( 'rrt_listen_salt' );
/* Podcast downloads (1.2.0): the day's hashes and their code are short-lived, so they always go. */
delete_option( 'rrt_dl_salt' );
/* Download counting to the IAB guidelines (1.24.0): its settings and the downloaded data centre list's facts (the list itself is in rrt-assets). A method page you made stays: it is yours. */
delete_option( 'rrt_dl' );
delete_option( 'rrt_net' );
delete_option( 'rrt_private_check' );
delete_option( 'rrt_setup' );
delete_option( 'rrt_shelf' );
delete_option( 'rrt_embeds' );
/* Its marked lines in the site's .htaccess (second build of 1.24.0), if Check counting added them. The rest of the file stays as it was. */
if ( ! function_exists( 'get_home_path' ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
}
$rrt_ht = get_home_path() . '.htaccess';
if ( is_readable( $rrt_ht ) && is_writable( $rrt_ht ) ) {
	$rrt_ht_text = (string) file_get_contents( $rrt_ht ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( false !== strpos( $rrt_ht_text, '# BEGIN Foxfire Studio download counting' ) ) {
		file_put_contents( $rrt_ht, ltrim( (string) preg_replace( '/^# BEGIN Foxfire Studio download counting\R.*?^# END Foxfire Studio download counting\R?/sm', '', $rrt_ht_text ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}
global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rrt_dl_seen" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
delete_option( 'rrt_listen_default_v' );
delete_option( 'rrt_geo' );
wp_clear_scheduled_hook( 'rrt_listen_hourly' );
wp_clear_scheduled_hook( 'rrt_listen_daily' );
/* The report email (0.12.0): its settings and schedule. The archive table goes with the stats above. */
delete_option( 'rrt_report' );
delete_option( 'rrt_report_last' );
delete_option( 'rrt_report_attempts' );
wp_clear_scheduled_hook( 'rrt_report_check' );
wp_clear_scheduled_hook( 'rrt_share_hourly' );
delete_transient( 'rrt_report_lock' );
delete_transient( 'rrt_fonts_plan' );
delete_transient( 'rrt_dash_fresh' );
delete_transient( 'rrt_pron_index' );
/* The analytics rate-limit counters (0.10.1) expire within the hour; remove any still waiting. */
global $wpdb;
$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE '\_transient\_rrt\_lnew\_%' OR option_name LIKE '\_transient\_timeout\_rrt\_lnew\_%' OR option_name LIKE '\_transient\_rrt\_lip\_%' OR option_name LIKE '\_transient\_timeout\_rrt\_lip\_%' OR option_name LIKE '\_transient\_rrt\_shn\_%' OR option_name LIKE '\_transient\_timeout\_rrt\_shn\_%' OR option_name LIKE '\_transient\_rrt\_dlip\_%' OR option_name LIKE '\_transient\_timeout\_rrt\_dlip\_%' OR option_name LIKE '\_transient\_rrt\_dlid\_%' OR option_name LIKE '\_transient\_timeout\_rrt\_dlid\_%' OR option_name LIKE '\_transient\_rrt\_dltag\_%' OR option_name LIKE '\_transient\_timeout\_rrt\_dltag\_%' OR option_name LIKE '\_transient\_rrt\_dl\_check%' OR option_name LIKE '\_transient\_timeout\_rrt\_dl\_check%' OR option_name LIKE '\_transient\_rrt\_shinv\_%' OR option_name LIKE '\_transient\_timeout\_rrt\_shinv\_%' OR option_name LIKE '\_transient\_rrt\_pcheck\_%' OR option_name LIKE '\_transient\_timeout\_rrt\_pcheck\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

$rrt_up  = wp_upload_dir( null, false );
foreach ( array( 'rrt-assets', 'rrt-offload-check', 'rrt-dl-check' ) as $rrt_d ) {
	$rrt_dir = trailingslashit( $rrt_up['basedir'] ) . $rrt_d;
	if ( is_dir( $rrt_dir ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( WP_Filesystem() ) {
			global $wp_filesystem;
			$wp_filesystem->delete( $rrt_dir, true );
		}
	}
}

/* The podcast feed's address (0.22.0) leaves WordPress's saved rewrite rules. */
delete_option( 'rewrite_rules' );
