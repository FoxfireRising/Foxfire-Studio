<?php
/**
 * Keep a reading aside (1.8.0), run inside the test WordPress:
 *   php wp-cli.phar --path=<wordpress> eval-file tests/php/keep.php
 * Prints one line per check and exits 1 if any fail. Leaves nothing behind.
 */

$GLOBALS['kp_pass'] = 0;
$GLOBALS['kp_fail'] = array();

function kp_t( $ok, $name ) {
	if ( $ok ) {
		++$GLOBALS['kp_pass'];
		echo "ok   $name\n";
	} else {
		$GLOBALS['kp_fail'][] = $name;
		echo "FAIL $name\n";
	}
}

global $wpdb;
wp_set_current_user( 1 );
rrt_stats_install();
$kp_was_settings = get_option( 'rrt_settings', array() );
/* The extra field is set for part of the test; it is restored however the test ends. */
register_shutdown_function(
	function () use ( $kp_was_settings ) {
		update_option( 'rrt_settings', $kp_was_settings );
	}
);
$kp_up           = wp_upload_dir();

/* A small file that looks like an MP3, made into a Media Library attachment of the post. */
function kp_attach( $post_id, $name, $body, $mime ) {
	$up   = wp_upload_dir();
	$path = trailingslashit( $up['path'] ) . wp_unique_filename( $up['path'], $name );
	file_put_contents( $path, $body );
	$att = wp_insert_attachment(
		array(
			'post_title'     => pathinfo( $path, PATHINFO_FILENAME ),
			'post_mime_type' => $mime,
			'post_status'    => 'inherit',
		),
		$path,
		$post_id
	);
	wp_update_attachment_metadata( $att, array( 'length' => 72, 'filesize' => strlen( $body ) ) );
	return (int) $att;
}
$kp_mp3 = 'ID3' . str_repeat( "\x00", 7 ) . str_repeat( "\xFF\xFB\x90\x00", 500 );
$kp_vtt = "WEBVTT\n\n00:00.000 --> 00:02.000\nKeep test.\n";

$kp_post = wp_insert_post( array( 'post_title' => 'Keep test', 'post_status' => 'publish', 'post_content' => '<p>Leaves fall and rise again.</p>' ) );
$kp_att  = kp_attach( $kp_post, 'keep-test-reading.mp3', $kp_mp3, 'audio/mpeg' );
$kp_cap  = kp_attach( $kp_post, 'keep-test-captions.vtt', $kp_vtt, 'text/vtt' );
$kp_url  = wp_get_attachment_url( $kp_att );
$kp_path = get_attached_file( $kp_att );
$kp_cpath = get_attached_file( $kp_cap );
update_post_meta( $kp_post, 'rrt_audio', $kp_att );
update_post_meta( $kp_post, 'rrt_captions', $kp_cap );
update_post_meta( $kp_post, '_rrt_music_used', array( 'audio' => $kp_att, 'bed' => 9, 'credit' => 'Night music' ) );
update_post_meta( $kp_post, '_rrt_soundbite', array( 's' => 1.5, 'd' => 31, 'text' => 'x', 'audio' => $kp_url ) );
$kp_rec = rrt_kept_current_rec( $kp_post );
kp_t( $kp_rec > 0, 'the reading has a current Recordings row before it is taken off' );
$wpdb->insert( rrt_table( 'daily' ), array( 'day' => gmdate( 'Y-m-d' ), 'post_id' => $kp_post, 'recording_id' => $kp_rec, 'plays' => 3, 'views' => 5 ) );

/* Take off the post. */
$kp_k = rrt_kept_take_off( $kp_post );
kp_t( is_array( $kp_k ) && '' === $kp_k['stays'], 'Take off the post keeps the reading aside' );
kp_t( '' === rrt_reading_audio( $kp_post )['url'], 'the post has no reading afterwards, so no player' );
kp_t( ! file_exists( $kp_path ) && ! file_exists( $kp_cpath ), 'the MP3 and captions are gone from their public addresses' );
kp_t( null === get_post( $kp_att ) && null === get_post( $kp_cap ), 'both are gone from the Media Library' );
$kp_dir = rrt_kept_dir();
kp_t( file_exists( $kp_dir . $kp_k['audio']['file'] ) && file_exists( $kp_dir . $kp_k['captions']['file'] ), 'both files are in the private folder' );
kp_t( $kp_mp3 === file_get_contents( $kp_dir . $kp_k['audio']['file'] ), 'the kept MP3 is unchanged' );
kp_t( false !== strpos( (string) file_get_contents( $kp_dir . '.htaccess' ), 'Require all denied' ) && file_exists( $kp_dir . 'index.php' ), 'the folder refuses direct requests' );
kp_t( 0 === strpos( $kp_k['audio']['file'], $kp_k['id'] ) && false === strpos( $kp_k['audio']['file'], 'keep-test' ), 'a kept file is named by its random id, never its title' );
kp_t( '' === get_post_meta( $kp_post, 'rrt_captions', true ) && '' === get_post_meta( $kp_post, '_rrt_music_used', true ) && '' === get_post_meta( $kp_post, '_rrt_soundbite', true ), 'captions, music bed credit and the pick for the feed leave the post' );
kp_t( 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT is_current FROM ' . rrt_table( 'recordings' ) . ' WHERE id = %d', $kp_rec ) ), 'its Recordings row stops being current' );
kp_t( 3 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT plays FROM ' . rrt_table( 'daily' ) . ' WHERE post_id = %d AND recording_id = %d', $kp_post, $kp_rec ) ), 'its listening history stays' );
kp_t( ! in_array( $kp_post, wp_list_pluck( rrt_podcast_posts(), 'ID' ), true ), 'the podcast feed no longer lists the post' );
kp_t( isset( rrt_kept_rec_ids()[ $kp_rec ] ), 'the Recordings table knows the row is kept aside' );
kp_t( 1 === count( rrt_kept_list( $kp_post ) ) && in_array( $kp_post, rrt_kept_posts(), true ), 'the post lists one reading kept aside' );
kp_t( is_wp_error( rrt_kept_take_off( $kp_post ) ), 'a post with no reading has nothing to take off' );

/* Readings to make shows it again, marked. */
ob_start();
rrt_backlog_page();
$kp_html = ob_get_clean();
kp_t( false !== strpos( $kp_html, 'Keep test' ) && false !== strpos( $kp_html, 'Reading kept aside' ), 'Readings to make lists the post, marked Reading kept aside' );

/* The screens. */
ob_start();
rrt_kept_admin_section();
$kp_html = ob_get_clean();
kp_t( false !== strpos( $kp_html, 'keep-test-reading.mp3' ) && false !== strpos( $kp_html, 'action=rrt_kept_audio' ) && false !== strpos( $kp_html, 'Delete for good' ), 'Recordings lists it with Listen, Download, Put back and Delete for good' );
kp_t( false === strpos( $kp_html, $kp_k['audio']['file'] ), 'the screen never shows the kept file\'s own name' );
ob_start();
rrt_kept_reading_section( $kp_post );
$kp_html = ob_get_clean();
kp_t( false !== strpos( $kp_html, 'Kept aside: keep-test-reading.mp3' ) && false === strpos( $kp_html, 'Take off the post' ), 'the reading\'s page offers Put back and no Take off' );
ob_start();
rrt_reading_box( get_post( $kp_post ) );
$kp_html = ob_get_clean();
kp_t( false !== strpos( $kp_html, '1 reading kept aside.' ) && false === strpos( $kp_html, 'Take this reading off the post' ), 'the post\'s Reading audio box says one reading is kept aside, with Put back' );

/* Put back. */
$kp_r   = rrt_kept_put_back( $kp_post, $kp_k['id'] );
$kp_new = (int) get_post_meta( $kp_post, 'rrt_audio', true );
kp_t( is_array( $kp_r ) && null === $kp_r['swapped'] && ! $kp_r['moved'], 'Put back works with nothing to swap' );
kp_t( $kp_url === rrt_reading_audio( $kp_post )['url'] && file_exists( $kp_path ), 'the reading is back at the same address' );
kp_t( 'attachment' === get_post_type( $kp_new ) && (int) get_post_field( 'post_parent', $kp_new ) === $kp_post && 'audio/mpeg' === get_post_mime_type( $kp_new ), 'it is a Media Library file of the post again' );
kp_t( 72 === (int) wp_get_attachment_metadata( $kp_new )['length'], 'its length and details come back with it' );
$kp_nc = (int) get_post_meta( $kp_post, 'rrt_captions', true );
kp_t( $kp_nc && file_exists( get_attached_file( $kp_nc ) ) && get_attached_file( $kp_nc ) === $kp_cpath, 'the captions come back at their address' );
$kp_mu = get_post_meta( $kp_post, '_rrt_music_used', true );
kp_t( is_array( $kp_mu ) && $kp_new === (int) $kp_mu['audio'] && 'Night music' === $kp_mu['credit'], 'the music bed credit comes back, for the new file' );
$kp_pk = rrt_soundbite_pick( $kp_post );
kp_t( $kp_pk && 1.5 === (float) $kp_pk['s'], 'the pick for the feed comes back' );
kp_t( 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT is_current FROM ' . rrt_table( 'recordings' ) . ' WHERE id = %d', $kp_rec ) ) && $kp_rec === rrt_kept_current_rec( $kp_post ), 'the same Recordings row is current again, so its history carries on' );
kp_t( 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . rrt_table( 'recordings' ) . ' WHERE post_id = %d', $kp_post ) ), 'no new Recordings row is made' );
kp_t( $kp_new === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT attachment_id FROM ' . rrt_table( 'recordings' ) . ' WHERE id = %d', $kp_rec ) ), 'the row points at the file in the Media Library again' );
kp_t( array() === rrt_kept_list( $kp_post ) && ! file_exists( $kp_dir . $kp_k['audio']['file'] ), 'nothing is left kept aside' );
kp_t( in_array( $kp_post, wp_list_pluck( rrt_podcast_posts(), 'ID' ), true ), 'the podcast feed lists the post again' );

ob_start();
rrt_reading_box( get_post( $kp_post ) );
$kp_html = ob_get_clean();
kp_t( false !== strpos( $kp_html, 'Take this reading off the post' ) && false === strpos( $kp_html, 'kept aside.' ), 'with the reading back, the box offers Take this reading off the post' );

/* An edit screen opened before the reading was taken off and put back, saved afterwards, does not undo it. */
$_POST = array(
	'rrt_reading_nonce' => wp_create_nonce( 'rrt_reading_box' ),
	'rrt_audio'         => (string) $kp_att,
	'rrt_captions'      => (string) $kp_cap,
	'rrt_was'           => array( 'rrt_audio' => (string) $kp_att, 'rrt_captions' => (string) $kp_cap ),
);
do_action( 'save_post', $kp_post, get_post( $kp_post ), true );
kp_t( $kp_new === (int) get_post_meta( $kp_post, 'rrt_audio', true ) && $kp_nc === (int) get_post_meta( $kp_post, 'rrt_captions', true ), 'saving an edit screen left open from before keeps the reading that was put back' );
$_POST['rrt_captions'] = '';
$_POST['rrt_was']['rrt_captions'] = (string) $kp_nc;
do_action( 'save_post', $kp_post, get_post( $kp_post ), true );
kp_t( '' === get_post_meta( $kp_post, 'rrt_captions', true ), 'Remove in the box still removes a file' );
update_post_meta( $kp_post, 'rrt_captions', $kp_nc );
$_POST = array();

/* Put back onto a post with a newer reading swaps them. */
$kp_k1  = rrt_kept_take_off( $kp_post );
$kp_two = kp_attach( $kp_post, 'keep-test-second.mp3', $kp_mp3 . 'x', 'audio/mpeg' );
update_post_meta( $kp_post, 'rrt_audio', $kp_two );
$kp_rec2 = rrt_kept_current_rec( $kp_post );
kp_t( $kp_rec2 && $kp_rec2 !== $kp_rec, 'a newer reading gets its own Recordings row' );
$kp_r = rrt_kept_put_back( $kp_post, $kp_k1['id'] );
kp_t( is_array( $kp_r ) && is_array( $kp_r['swapped'] ), 'Put back onto a post with a newer reading swaps them' );
kp_t( $kp_url === rrt_reading_audio( $kp_post )['url'] && $kp_rec === rrt_kept_current_rec( $kp_post ), 'the older reading is back on the post, its row current' );
$kp_l = rrt_kept_list( $kp_post );
kp_t( 1 === count( $kp_l ) && false !== strpos( $kp_l[0]['audio']['rel'], 'keep-test-second' ) && $kp_rec2 === (int) $kp_l[0]['rec'], 'the newer one is kept aside in its place' );

/* An address taken by another file in the meantime: a new address. */
$kp_sec_path = trailingslashit( $kp_up['basedir'] ) . $kp_l[0]['audio']['rel'];
file_put_contents( $kp_sec_path, 'someone else' );
$kp_other = rrt_kept_take_off( $kp_post );
$kp_r     = rrt_kept_put_back( $kp_post, $kp_l[0]['id'] );
kp_t( is_array( $kp_r ) && $kp_r['moved'] && 'someone else' === file_get_contents( $kp_sec_path ), 'an address taken in the meantime is never overwritten; the reading gets a new one' );
wp_delete_file( $kp_sec_path );

/* Delete for good. */
$kp_l  = rrt_kept_list( $kp_post );
$kp_kf = $kp_dir . $kp_l[0]['audio']['file'];
kp_t( true === rrt_kept_delete( $kp_post, $kp_l[0]['id'] ) && ! file_exists( $kp_kf ) && array() === rrt_kept_list( $kp_post ), 'Delete for good removes the file and the record' );
kp_t( '' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT url FROM ' . rrt_table( 'recordings' ) . ' WHERE id = %d', (int) $kp_l[0]['rec'] ) ) && 2 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . rrt_table( 'recordings' ) . ' WHERE post_id = %d', $kp_post ) ), 'its Recordings row stays, without an address' );
kp_t( false === rrt_kept_delete( $kp_post, 'nothere' ), 'deleting a reading that is not kept aside does nothing' );
kp_t( is_wp_error( rrt_kept_put_back( $kp_post, 'nothere' ) ), 'putting back a reading that is not kept aside is refused' );

/* A reading from the extra field in Settings, by address, in the Media Library. */
$kp_s                        = get_option( 'rrt_settings', array() );
$kp_s['player']['audio_key'] = 'kp_theme_audio';
update_option( 'rrt_settings', $kp_s );
$kp_tp  = wp_insert_post( array( 'post_title' => 'Keep theme test', 'post_status' => 'publish', 'post_content' => '<p>Moon.</p>' ) );
$kp_ta  = kp_attach( $kp_tp, 'keep-theme.mp3', $kp_mp3, 'audio/mpeg' );
$kp_tu  = wp_get_attachment_url( $kp_ta );
update_post_meta( $kp_tp, 'kp_theme_audio', $kp_tu );
$kp_tk = rrt_kept_take_off( $kp_tp );
kp_t( is_array( $kp_tk ) && 'kp_theme_audio' === $kp_tk['field'] && 'url' === $kp_tk['kind'] && ! empty( $kp_tk['audio'] ), 'a reading in the extra field, by address, is kept aside the same way' );
kp_t( '' === get_post_meta( $kp_tp, 'kp_theme_audio', true ) && null === get_post( $kp_ta ), 'its field is cleared and its file leaves the Media Library' );
rrt_kept_put_back( $kp_tp, $kp_tk['id'] );
kp_t( $kp_tu === get_post_meta( $kp_tp, 'kp_theme_audio', true ) && '' === get_post_meta( $kp_tp, 'rrt_audio', true ), 'Put back fills in the extra field again with the same address' );

/* The same field pointing outside the Media Library: only the field is cleared and remembered. */
update_post_meta( $kp_tp, 'kp_theme_audio', 'https://elsewhere.example/reading.mp3' );
$kp_ok = rrt_kept_take_off( $kp_tp );
kp_t( is_array( $kp_ok ) && 'outside' === $kp_ok['stays'] && null === $kp_ok['audio'] && '' === get_post_meta( $kp_tp, 'kp_theme_audio', true ), 'a reading outside the Media Library: only the field is cleared' );
rrt_kept_put_back( $kp_tp, $kp_ok['id'] );
kp_t( 'https://elsewhere.example/reading.mp3' === get_post_meta( $kp_tp, 'kp_theme_audio', true ), 'Put back writes the same address back' );
delete_post_meta( $kp_tp, 'kp_theme_audio' );

/* A file that is also another post's reading is never moved. */
$kp_new = (int) get_post_meta( $kp_post, 'rrt_audio', true );
$kp_url = rrt_reading_audio( $kp_post )['url'];
update_post_meta( $kp_tp, 'rrt_audio', $kp_new );
$kp_sh = rrt_kept_take_off( $kp_tp );
kp_t( is_array( $kp_sh ) && 'shared' === $kp_sh['stays'] && file_exists( get_attached_file( $kp_new ) ) && $kp_url === rrt_reading_audio( $kp_post )['url'], 'a file another post also uses stays in the Media Library, and that post keeps its reading' );
rrt_kept_put_back( $kp_tp, $kp_sh['id'] );
kp_t( $kp_new === (int) get_post_meta( $kp_tp, 'rrt_audio', true ), 'Put back gives the post the shared file again' );
delete_post_meta( $kp_tp, 'rrt_audio' );
update_option( 'rrt_settings', $kp_was_settings );

/* Deleting a post for good deletes what it kept aside. */
$kp_k3 = rrt_kept_take_off( $kp_post );
$kp_f3 = $kp_dir . $kp_k3['audio']['file'];
kp_t( file_exists( $kp_f3 ), 'a reading is kept aside before its post is deleted' );
wp_delete_post( $kp_post, true );
kp_t( ! file_exists( $kp_f3 ), 'deleting the post for good deletes its readings kept aside' );
wp_delete_post( $kp_tp, true );

/* Tidy up. */
foreach ( array( $kp_new, $kp_nc, $kp_two ) as $kp_a ) {
	if ( get_post( $kp_a ) ) {
		wp_delete_attachment( $kp_a, true );
	}
}
foreach ( $wpdb->get_col( "SELECT ID FROM $wpdb->posts WHERE post_type = 'attachment' AND post_title LIKE 'keep-%'" ) as $kp_a ) {
	wp_delete_attachment( (int) $kp_a, true );
}
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . rrt_table( 'daily' ) . ' WHERE post_id = %d', $kp_post ) );
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . rrt_table( 'recordings' ) . ' WHERE post_id IN (%d, %d)', $kp_post, $kp_tp ) );
kp_t( array() === rrt_kept_posts(), 'nothing is left kept aside after the test' );

echo "\n" . $GLOBALS['kp_pass'] . ' passed, ' . count( $GLOBALS['kp_fail'] ) . " failed\n";
if ( $GLOBALS['kp_fail'] ) {
	exit( 1 );
}
