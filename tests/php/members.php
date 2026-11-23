<?php
/**
 * Member feeds (1.21.0), run inside the test WordPress:
 *   php wp-cli.phar --path=<wordpress> eval-file tests/php/members.php
 * Prints one line per check and exits 1 if any fail. Leaves nothing behind.
 */

$GLOBALS['mb_pass'] = 0;
$GLOBALS['mb_fail'] = array();

function mb_t( $ok, $name ) {
	if ( $ok ) {
		++$GLOBALS['mb_pass'];
		echo "ok   $name\n";
	} else {
		$GLOBALS['mb_fail'][] = $name;
		echo "FAIL $name\n";
	}
}

wp_set_current_user( 1 );
$mb_was_pc  = get_option( 'rrt_podcast', null );
$mb_was_set = get_option( 'rrt_settings', array() );
$GLOBALS['mb_clean'] = array( 'posts' => array(), 'users' => array() );
register_shutdown_function(
	function () use ( $mb_was_pc, $mb_was_set ) {
		foreach ( $GLOBALS['mb_clean']['posts'] as $p ) {
			foreach ( get_children( array( 'post_parent' => $p, 'post_type' => 'attachment', 'numberposts' => -1, 'post_status' => 'any' ) ) as $a ) {
				wp_delete_attachment( $a->ID, true );
			}
			wp_delete_post( $p, true );
		}
		foreach ( $GLOBALS['mb_clean']['atts'] ?? array() as $a ) {
			wp_delete_attachment( $a, true );
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $GLOBALS['mb_clean']['users'] as $u ) {
			wp_delete_user( $u );
		}
		delete_metadata( 'user', 0, RRT_MFEED_META, '', true );
		delete_metadata( 'user', 0, RRT_MFEED_AT_META, '', true );
		delete_metadata( 'user', 0, RRT_MSEEN_META, '', true );
		delete_metadata( 'user', 0, RRT_MEMBER_META, '', true );
		if ( null === $mb_was_pc ) {
			delete_option( 'rrt_podcast' );
		} else {
			update_option( 'rrt_podcast', $mb_was_pc );
		}
		update_option( 'rrt_settings', $mb_was_set );
		$d = rrt_mfiles_dir();
		if ( is_dir( $d ) ) {
			foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $d, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $f ) {
				$f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
			}
			rmdir( $d );
		}
	}
);

function mb_attach( $post_id, $name, $body, $mime ) {
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
function mb_feed( $m ) {
	ob_start();
	rrt_podcast_render( $m );
	return ob_get_clean();
}
function mb_set( $changes ) {
	$s = rrt_podcast_settings();
	update_option( 'rrt_podcast', array_merge( $s, $changes ) );
}

$mb_mp3 = 'ID3' . str_repeat( "\x00", 7 ) . str_repeat( "\xFF\xFB\x90\x00", 500 );
$mb_vtt = "WEBVTT\n\n00:00.000 --> 00:02.000\nMember test.\n";

$mb_sub = wp_insert_user( array( 'user_login' => 'mb_friend', 'user_pass' => wp_generate_password(), 'user_email' => 'mb-friend@example.com', 'display_name' => 'Rowan Test', 'role' => 'subscriber' ) );
$mb_out = wp_insert_user( array( 'user_login' => 'mb_other', 'user_pass' => wp_generate_password(), 'user_email' => 'mb-other@example.com', 'display_name' => 'Other Test', 'role' => 'subscriber' ) );
$GLOBALS['mb_clean']['users'] = array( $mb_sub, $mb_out );

/* ---- Who is a member ---- */
mb_set( array( 'm_on' => false ) );
mb_t( ! rrt_member_on() && ! rrt_member_is( 1 ), 'off by default: no one is a member, not even the administrator' );
mb_t( false === rrt_podcast_settings()['m_activity'] && array() === rrt_podcast_settings()['m_roles'], 'feed activity off and no roles by default' );
mb_set( array( 'm_on' => true ) );
mb_t( rrt_member_is( 1 ), 'switched on, an administrator always has a feed' );
mb_t( ! rrt_member_is( $mb_sub ), 'a subscriber is not a member while no role is ticked' );
update_user_meta( $mb_sub, RRT_MEMBER_META, 'always' );
mb_t( rrt_member_is( $mb_sub ), 'Always on their profile makes one person a member' );
delete_user_meta( $mb_sub, RRT_MEMBER_META );
mb_set( array( 'm_roles' => array( 'subscriber' ) ) );
mb_t( rrt_member_is( $mb_sub ) && rrt_member_is( $mb_out ), 'a ticked role makes its people members' );
update_user_meta( $mb_out, RRT_MEMBER_META, 'never' );
mb_t( ! rrt_member_is( $mb_out ), 'Never wins over the role' );
update_user_meta( 1, RRT_MEMBER_META, 'never' );
mb_t( rrt_member_is( 1 ), 'an administrator cannot be set to Never' );
delete_user_meta( 1, RRT_MEMBER_META );
$mb_f = function ( $yes, $uid ) use ( $mb_sub ) {
	return $uid === $mb_sub ? false : $yes;
};
add_filter( 'rrt_member_is', $mb_f, 10, 2 );
mb_t( ! rrt_member_is( $mb_sub ), 'the rrt_member_is filter has the last word' );
remove_filter( 'rrt_member_is', $mb_f, 10 );
mb_set( array( 'm_via' => 'pmpro' ) );
mb_t( 'roles' === rrt_member_settings()['via'], 'a membership plugin that is not active falls back to roles' );
mb_set( array( 'm_via' => 'roles' ) );

/* ---- Keys and addresses ---- */
$mb_key = rrt_mfeed_key( $mb_sub );
mb_t( rrt_mfeed_key_ok( $mb_key ) && rrt_mfeed_user( $mb_key ) === $mb_sub, 'a member gets a 32-character key that finds them' );
mb_t( rrt_mfeed_key( $mb_sub ) === $mb_key, 'the key stays the same until reset' );
mb_t( '' === rrt_mfeed_key( $mb_out ), 'someone who is not a member gets no key' );
$mb_url = rrt_mfeed_url( $mb_sub );
mb_t( home_url( '/' . RRT_MEMBER_BASE . '/' . $mb_key . '/' ) === $mb_url, 'the feed address' );
$mb_r0 = rrt_member_route( '/' . RRT_MEMBER_BASE . '/' . $mb_key . '/feed.xml', '/' );
mb_t( $mb_r0 && 'feed' === $mb_r0['what'], 'feed.xml after it answers too' );
$mb_r = rrt_member_route( (string) wp_parse_url( $mb_url, PHP_URL_PATH ), (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
mb_t( $mb_r && 'feed' === $mb_r['what'] && $mb_key === $mb_r['key'], 'the address is recognised as a feed' );
$mb_r2 = rrt_member_route( '/sub/' . RRT_MEMBER_BASE . '/' . $mb_key . '/file/12/a.mp3', '/sub/' );
mb_t( $mb_r2 && 'file' === $mb_r2['what'] && 12 === $mb_r2['id'], 'a keyed file address, on a site in a folder' );
mb_t( null === rrt_member_route( '/' . RRT_MEMBER_BASE . '/short/feed.xml', '/' ) && null === rrt_member_route( '/other/x', '/' ), 'other addresses are left alone' );
$mb_new = rrt_mfeed_reset( $mb_sub );
mb_t( $mb_new !== $mb_key && 0 === rrt_mfeed_user( $mb_key ) && rrt_mfeed_user( $mb_new ) === $mb_sub, 'reset: a new key, and the old one finds no one' );
mb_t( rrt_mfeed_guid() !== rrt_podcast_guid() && rrt_mfeed_guid() === rrt_mfeed_guid(), 'the member feed has its own lasting podcast:guid' );

/* ---- A reading for members ---- */
$mb_open = wp_insert_post( array( 'post_title' => 'Member test open', 'post_status' => 'publish', 'post_content' => '<p>For all.</p>', 'post_date' => '2026-01-01 10:00:00' ) );
$mb_post = wp_insert_post( array( 'post_title' => 'Member test closed', 'post_status' => 'publish', 'post_content' => '<p>For members.</p>' ) );
$GLOBALS['mb_clean']['posts'] = array( $mb_open, $mb_post );
$mb_oatt = mb_attach( $mb_open, 'member-test-open.mp3', $mb_mp3, 'audio/mpeg' );
update_post_meta( $mb_open, 'rrt_audio', $mb_oatt );
$mb_att  = mb_attach( $mb_post, 'member-test-closed.mp3', $mb_mp3, 'audio/mpeg' );
$mb_cap  = mb_attach( $mb_post, 'member-test-closed.vtt', $mb_vtt, 'text/vtt' );
update_post_meta( $mb_post, 'rrt_audio', $mb_att );
update_post_meta( $mb_post, 'rrt_captions', $mb_cap );
$mb_path = get_attached_file( $mb_att );
$mb_purl = wp_get_attachment_url( $mb_att );
mb_t( 'everyone' === rrt_access_state( $mb_post )['who'] && ! rrt_mfile_is( $mb_att ), 'a reading is for everyone by default' );

rrt_access_save( $mb_post, 'members', 7 );
clearstatcache();
mb_t( 'members' === rrt_access_state( $mb_post )['who'], 'Members only is kept' );
mb_t( rrt_mfile_is( $mb_att ) && rrt_mfile_is( $mb_cap ), 'its MP3 and captions are kept for members' );
mb_t( ! file_exists( $mb_path ) && is_file( get_attached_file( $mb_att ) ) && 0 === strpos( get_attached_file( $mb_att ), rrt_mfiles_dir() ), 'the file moved into the protected folder' );
mb_t( is_file( rrt_mfiles_dir() . '.htaccess' ) && false !== strpos( file_get_contents( rrt_mfiles_dir() . '.htaccess' ), 'Require all denied' ), 'the folder refuses direct requests' );
$mb_gurl = wp_get_attachment_url( $mb_att );
mb_t( rrt_mfile_url( $mb_att ) === $mb_gurl && false !== strpos( $mb_gurl, '/' . RRT_MEMBER_BASE . '/file/' . $mb_att . '/' ), 'the attachment\'s address is the checked one' );
mb_t( rrt_reading_audio( $mb_post )['url'] === $mb_gurl, 'the post\'s reading gives the checked address' );
mb_t( rrt_podcast_local_path( $mb_gurl, $mb_post ) === get_attached_file( $mb_att ), 'the checked address still finds the file on the server, for its own post' );
mb_t( '' === rrt_podcast_local_path( $mb_gurl ), 'and for no other (1.25.0)' );
mb_t( function_exists( 'rrt_col_reading_path' ) && rrt_col_reading_path( $mb_post ) === get_attached_file( $mb_att ), 'collections and audiobooks still find the file' );

/* Who can hear it. */
mb_t( rrt_member_can_hear( $mb_post, 1 ), 'an editor can hear it' );
mb_t( rrt_member_can_hear( $mb_post, $mb_sub ), 'a member can hear it' );
mb_t( ! rrt_member_can_hear( $mb_post, $mb_out ) && ! rrt_member_can_hear( $mb_post, 0 ), 'someone who is not a member, and a visitor, cannot' );
mb_t( rrt_member_can_hear( $mb_open, 0 ), 'everyone can hear a reading for everyone' );

/* The public feed and the chapters file. */
mb_set( array( 'on' => true ) );
$mb_ids = wp_list_pluck( rrt_podcast_posts(), 'ID' );
mb_t( in_array( $mb_open, $mb_ids, true ) && ! in_array( $mb_post, $mb_ids, true ), 'the public feed leaves the reading for members out' );
mb_t( ! rrt_feed_has_post( $mb_post ), 'and its chapters file is not served publicly' );
$mb_pub = mb_feed( null );
mb_t( false === strpos( $mb_pub, 'Member test closed' ) && false === strpos( $mb_pub, 'itunes:block' ), 'the public feed text has neither it nor a block tag' );
mb_set( array( 'on' => false ) );

/* A member's feed. */
$mb_key = rrt_mfeed_key( $mb_sub );
$mb_x   = mb_feed( array( 'uid' => $mb_sub, 'key' => $mb_key, 'member' => true ) );
$mb_xml = simplexml_load_string( $mb_x );
mb_t( false !== $mb_xml, 'the member feed is well-formed XML, even with the public feed off' );
mb_t( false !== strpos( $mb_x, '<itunes:block>Yes</itunes:block>' ) && false !== strpos( $mb_x, '<podcast:block>yes</podcast:block>' ), 'it carries both block tags' );
mb_t( false !== strpos( $mb_x, rrt_mfeed_guid() ) && false === strpos( $mb_x, rrt_podcast_guid() ), 'with its own guid' );
mb_t( false !== strpos( $mb_x, 'Member test closed' ) && false !== strpos( $mb_x, 'Member test open' ), 'it holds the reading for members and the open one' );
mb_t( false !== strpos( $mb_x, esc_attr( rrt_mfile_keyed( $mb_gurl, $mb_key ) ) ), 'the reading for members comes from an address with the member\'s key' );
mb_t( false !== strpos( $mb_x, 'rel="self"' ) && false !== strpos( $mb_x, $mb_key . '/"' ), 'its self link is the member\'s own address' );
mb_t( false === strpos( $mb_x, 'Rowan Test' ) && false === strpos( $mb_x, 'mb-friend@example.com' ) && false === strpos( $mb_x, 'mb_friend' ), 'nothing in it names the member' );
mb_t( false !== strpos( $mb_x, 'This feed is yours alone' ), 'it asks for the address to be kept private' );
mb_t( false !== strpos( (string) $mb_xml->channel->title, '(members)' ), 'its title says members' );
$mb_lap = mb_feed( array( 'uid' => $mb_out, 'key' => 'x', 'member' => false ) );
mb_t( false === strpos( $mb_lap, 'Member test closed' ) && false !== strpos( $mb_lap, 'Member test open' ) && false !== strpos( $mb_lap, 'readings for everyone' ), 'a lapsed member keeps the open readings only, with a note' );

/* A new reading for a post for members goes straight into the folder. */
$mb_att2 = mb_attach( $mb_post, 'member-test-closed-2.mp3', $mb_mp3, 'audio/mpeg' );
$mb_p2   = get_attached_file( $mb_att2 );
update_post_meta( $mb_post, 'rrt_audio', $mb_att2 );
mb_t( rrt_mfile_is( $mb_att2 ) && ! file_exists( $mb_p2 ), 'a new reading on a post for members is kept for members at once' );
update_post_meta( $mb_post, 'rrt_audio', $mb_att );

/* A file another open post uses stays where it is. */
$mb_shared = wp_insert_post( array( 'post_title' => 'Member test shared', 'post_status' => 'publish' ) );
$GLOBALS['mb_clean']['posts'][] = $mb_shared;
update_post_meta( $mb_shared, 'rrt_audio', $mb_oatt );
rrt_access_save( $mb_shared, 'members', 7 );
mb_t( ! rrt_mfile_is( $mb_oatt ), 'a file another open post uses is never moved' );
ob_start();
rrt_access_box_fields( get_post( $mb_shared ) );
$mb_bx = ob_get_clean();
mb_t( false !== strpos( $mb_bx, 'is also the reading of' ) && false !== strpos( $mb_bx, 'Member test open' ), 'and the box says which post shares it' );
rrt_access_save( $mb_shared, 'everyone', 7 );
/* A post in the trash, or a URL copy of the address, does not count as sharing; a live one by address does. */
$mb_tp = wp_insert_post( array( 'post_title' => 'Member test trashed', 'post_status' => 'publish' ) );
$GLOBALS['mb_clean']['posts'][] = $mb_tp;
$mb_ta = mb_attach( $mb_tp, 'member-test-trashed.mp3', $mb_mp3, 'audio/mpeg' );
update_post_meta( $mb_tp, 'rrt_audio', $mb_ta );
$mb_tq = wp_insert_post( array( 'post_title' => 'Member test page in the trash', 'post_status' => 'publish', 'post_type' => 'page' ) );
$GLOBALS['mb_clean']['posts'][] = $mb_tq;
update_post_meta( $mb_tq, 'rrt_audio', $mb_ta );
wp_trash_post( $mb_tq );
mb_t( array() === rrt_access_sharers( $mb_ta, $mb_tp ), 'a post in the trash using the same file does not count' );
rrt_access_save( $mb_tp, 'members', 7 );
mb_t( rrt_mfile_is( $mb_ta ), 'so the file is kept for members' );
rrt_access_save( $mb_tp, 'everyone', 7 );
$mb_tr = wp_insert_post( array( 'post_title' => 'Member test by address', 'post_status' => 'publish' ) );
$GLOBALS['mb_clean']['posts'][] = $mb_tr;
update_post_meta( $mb_tr, 'rrt_audio', wp_get_attachment_url( $mb_ta ) );
mb_t( array( $mb_tr ) === rrt_access_sharers( $mb_ta, $mb_tp ), 'a live post using the file by its address counts' );

/* Back to everyone. */
rrt_access_save( $mb_post, 'everyone', 7 );
clearstatcache();
mb_t( ! rrt_mfile_is( $mb_att ) && ! rrt_mfile_is( $mb_cap ), 'Everyone puts the files back' );
mb_t( file_exists( $mb_path ) && wp_get_attachment_url( $mb_att ) === $mb_purl, 'at the same place and address' );
mb_t( in_array( $mb_post, wp_list_pluck( rrt_podcast_posts(), 'ID' ), true ), 'and the reading is in the public feed again' );

/* A field holding an address follows the file, both ways. */
$mb_cfg = rrt_settings();
$mb_cfg['player']['audio_key'] = 'mb_field';
update_option( 'rrt_settings', $mb_cfg );
$GLOBALS['rrt_config_cache'] = null;
$mb_fp = wp_insert_post( array( 'post_title' => 'Member test field', 'post_status' => 'publish' ) );
$GLOBALS['mb_clean']['posts'][] = $mb_fp;
$mb_fa = mb_attach( $mb_fp, 'member-test-field.mp3', $mb_mp3, 'audio/mpeg' );
$mb_fu = wp_get_attachment_url( $mb_fa );
update_post_meta( $mb_fp, 'mb_field', $mb_fu );
if ( in_array( 'mb_field', rrt_access_fields(), true ) ) {
	rrt_access_save( $mb_fp, 'members', 7 );
	mb_t( rrt_mfile_is( $mb_fa ) && get_post_meta( $mb_fp, 'mb_field', true ) === rrt_mfile_url( $mb_fa ), 'a reading kept as an address in the extra field moves, and the field follows' );
	rrt_access_save( $mb_fp, 'everyone', 7 );
	mb_t( ! rrt_mfile_is( $mb_fa ) && get_post_meta( $mb_fp, 'mb_field', true ) === $mb_fu, 'and comes back to its old address' );
} else {
	mb_t( false, 'the extra field from Settings is one of the fields that are protected' );
}
update_option( 'rrt_settings', $mb_was_set );

/* Members first. */
rrt_access_save( $mb_open, 'first', 7 );
mb_t( 'everyone' === rrt_access_state( $mb_open )['who'] && ! rrt_mfile_is( $mb_oatt ), 'Members first on a post published long ago is already open' );
$mb_t0 = strtotime( get_post( $mb_post )->post_date_gmt . ' UTC' );
rrt_access_save( $mb_post, 'first', 3 );
$mb_st = rrt_access_state( $mb_post );
mb_t( 'first' === $mb_st['who'] && $mb_t0 + 3 * DAY_IN_SECONDS === $mb_st['open'] && rrt_mfile_is( $mb_att ), 'Members first on a new post: kept for members until 3 days after publishing' );
mb_t( 'everyone' === rrt_access_state( $mb_post, $mb_t0 + 3 * DAY_IN_SECONDS + 1 )['who'], 'and open to everyone after that' );
$mb_ex = mb_feed( array( 'uid' => $mb_sub, 'key' => $mb_key, 'member' => true ) );
mb_t( false !== strpos( $mb_ex, 'For members first, and for everyone from' ), 'the member feed says when it opens' );
wp_update_post( array( 'ID' => $mb_post, 'post_date' => '2026-01-01 10:00:00', 'post_date_gmt' => '2026-01-01 16:00:00' ) );
rrt_access_tick();
mb_t( ! rrt_mfile_is( $mb_att ) && in_array( $mb_post, wp_list_pluck( rrt_podcast_posts(), 'ID' ), true ), 'the hourly check opens it once its day has come' );
rrt_access_save( $mb_post, 'members', 7 );
$mb_draft = wp_insert_post( array( 'post_title' => 'Member test draft', 'post_status' => 'draft' ) );
$GLOBALS['mb_clean']['posts'][] = $mb_draft;
rrt_access_save( $mb_draft, 'first', 7 );
mb_t( 'first' === rrt_access_state( $mb_draft )['who'] && 0 === rrt_access_state( $mb_draft )['open'], 'a draft counts its days from when it is published' );

/* A collection follows the most closed reading in it. */
if ( function_exists( 'rrt_col_is' ) ) {
	$mb_col = wp_insert_post( array( 'post_title' => 'Member test collection', 'post_status' => 'publish', 'post_type' => RRT_COL_TYPE ) );
	$GLOBALS['mb_clean']['posts'][] = $mb_col;
	update_post_meta( $mb_col, RRT_COL_META, array( 'items' => array( $mb_open, $mb_post ), 'gap' => 2, 'feed' => true, 'type' => 'bonus' ) );
	$mb_cst = rrt_access_state( $mb_col );
	mb_t( 'members' === $mb_cst['who'] && $mb_post === $mb_cst['why'], 'a collection with a reading for members is for members, and says which' );
	$mb_cmp = mb_attach( $mb_col, 'member-test-col.mp3', $mb_mp3, 'audio/mpeg' );
	update_post_meta( $mb_col, 'rrt_audio', $mb_cmp );
	mb_t( rrt_mfile_is( $mb_cmp ), 'its joined MP3 is kept for members too' );
	rrt_access_save( $mb_post, 'everyone', 7 );
	mb_t( ! rrt_mfile_is( $mb_cmp ) && 'everyone' === rrt_access_state( $mb_col )['who'], 'and it opens when that reading does' );
	rrt_access_save( $mb_post, 'members', 7 );
}

/* The player. */
wp_set_current_user( 0 );
$mb_html = rrt_player( $mb_post );
mb_t( false !== strpos( $mb_html, 'rrt-locked' ) && false !== strpos( $mb_html, 'This reading is for members.' ) && false === strpos( $mb_html, '<audio' ), 'a visitor sees the members line and no audio' );
mb_t( false !== strpos( $mb_html, 'Sign in' ), 'with a Sign in link' );
mb_set( array( 'm_join' => 'https://example.com/join/' ) );
mb_t( false !== strpos( rrt_player( $mb_post ), 'https://example.com/join/' ), 'and the Become a member link when set' );
wp_set_current_user( $mb_out );
$mb_html = rrt_player( $mb_post );
mb_t( false !== strpos( $mb_html, 'rrt-locked' ) && false === strpos( $mb_html, 'Sign in' ), 'someone signed in who is not a member sees no Sign in link' );
wp_set_current_user( $mb_sub );
$mb_html = rrt_player( $mb_post );
mb_t( false !== strpos( $mb_html, '<audio' ) && false !== strpos( $mb_html, esc_url( rrt_mfile_url( $mb_att ) ) ), 'a member gets the player, playing from the checked address' );

/* The shortcode. */
$mb_sc = do_shortcode( '[foxfire_member_feed]' );
mb_t( false !== strpos( $mb_sc, esc_attr( rrt_mfeed_url( $mb_sub ) ) ) && false !== strpos( $mb_sc, 'overcast://' ) && false !== strpos( $mb_sc, 'Spotify cannot take private feeds' ), 'the shortcode gives a member their address, Overcast and the steps' );
wp_set_current_user( 0 );
mb_t( false !== strpos( do_shortcode( '[foxfire_member_feed]' ), 'Sign in' ), 'and a visitor the way in' );
wp_set_current_user( 1 );

/* Feed activity. */
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$_SERVER['HTTP_USER_AGENT'] = 'Overcast/3.0 (+http://overcast.fm/; iOS podcast app)';
rrt_mfeed_seen( $mb_sub );
mb_t( '' === (string) get_user_meta( $mb_sub, RRT_MSEEN_META, true ), 'nothing is noted while Feed activity is off' );
mb_set( array( 'm_activity' => true ) );
rrt_mfeed_seen( $mb_sub );
$mb_a = rrt_mfeed_activity( $mb_sub );
mb_t( $mb_a && 'Overcast' === $mb_a['app'] && 1 === $mb_a['nets'] && ! $mb_a['shared'], 'switched on: the last fetch, its app and one network' );
mb_t( false === strpos( wp_json_encode( get_user_meta( $mb_sub, RRT_MSEEN_META, true ) ), '203.0.113.9' ), 'no IP address is kept' );
for ( $i = 1; $i <= 6; $i++ ) {
	$_SERVER['REMOTE_ADDR'] = '198.51.100.' . $i;
	rrt_mfeed_seen( $mb_sub );
}
mb_t( rrt_mfeed_activity( $mb_sub )['shared'], 'more than five networks in a week shows as maybe shared' );
mb_t( false !== strpos( rrt_listen_privacy_text(), 'members feed address that may have been shared' ), 'the privacy paragraph explains it while it is on' );
mb_set( array( 'm_activity' => false ) );
rrt_mfeed_seen_trim();
mb_t( '' === (string) get_user_meta( $mb_sub, RRT_MSEEN_META, true ), 'switched off, the notes are deleted' );
mb_t( false !== strpos( rrt_listen_privacy_text(), 'own podcast feed address' ), 'the privacy paragraph mentions member feeds while they are on' );

/* The post's box and the profile save what they show. */
wp_set_current_user( 1 );
$mb_bp = wp_insert_post( array( 'post_title' => 'Member test box', 'post_status' => 'publish' ) );
$GLOBALS['mb_clean']['posts'][] = $mb_bp;
$_POST = array( 'rrt_access_nonce' => wp_create_nonce( 'rrt_access_box' ), 'rrt_access' => 'first', 'rrt_access_days' => '12' );
do_action( 'save_post', $mb_bp, get_post( $mb_bp ), true );
$mb_c = rrt_access_choice( $mb_bp );
mb_t( 'first' === $mb_c['who'] && 12 === $mb_c['days'], 'the box saves Members first and its days' );
$_POST = array( 'rrt_access_nonce' => 'wrong', 'rrt_access' => 'members' );
do_action( 'save_post', $mb_bp, get_post( $mb_bp ), true );
mb_t( 'first' === rrt_access_choice( $mb_bp )['who'], 'not without its nonce' );
$_POST = array( 'rrt_access_nonce' => wp_create_nonce( 'rrt_access_box' ), 'rrt_access' => 'everyone' );
do_action( 'save_post', $mb_bp, get_post( $mb_bp ), true );
mb_t( '' === get_post_meta( $mb_bp, RRT_ACCESS_META, true ), 'Everyone leaves nothing stored' );
ob_start();
rrt_access_box_fields( get_post( $mb_post ) );
$mb_box = ob_get_clean();
mb_t( false !== strpos( $mb_box, "value=\"members\" selected='selected'" ) && false !== strpos( $mb_box, 'Now: only members can hear it.' ), 'the box shows the choice and what it means now' );
mb_set( array( 'm_on' => true ) );
$_POST = array( 'rrt_member_nonce' => wp_create_nonce( 'rrt_member_pref_' . $mb_out ), 'rrt_member' => 'always' );
rrt_mfeed_profile_save( $mb_out );
mb_t( 'always' === rrt_member_pref( $mb_out ) && rrt_member_is( $mb_out ), 'the profile saves Always' );
$mb_k1 = rrt_mfeed_key( $mb_out );
$_POST = array( 'rrt_mfeed_nonce' => wp_create_nonce( 'rrt_mfeed_reset_' . $mb_out ), 'rrt_mfeed_reset' => '1' );
rrt_mfeed_profile_save( $mb_out );
mb_t( rrt_mfeed_key( $mb_out ) !== $mb_k1, 'and makes a new address when asked' );
wp_set_current_user( $mb_sub );
$_POST = array( 'rrt_member_nonce' => wp_create_nonce( 'rrt_member_pref_' . $mb_sub ), 'rrt_member' => 'never' );
rrt_mfeed_profile_save( $mb_sub );
mb_t( 'never' !== rrt_member_pref( $mb_sub ), 'a member cannot change their own choice' );
wp_set_current_user( 1 );
$_POST = array();

/* Member feeds switched off. */
mb_set( array( 'm_on' => false ) );
mb_t( ! rrt_member_can_hear( $mb_post, $mb_sub ) && rrt_member_can_hear( $mb_post, 1 ), 'with member feeds off, only editors hear a reading for members' );
mb_t( false === strpos( rrt_listen_privacy_text(), 'own podcast feed address' ), 'and the privacy paragraph leaves them out' );

echo "\n" . $GLOBALS['mb_pass'] . ' passed, ' . count( $GLOBALS['mb_fail'] ) . " failed\n";
if ( $GLOBALS['mb_fail'] ) {
	exit( 1 );
}
