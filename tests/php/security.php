<?php
/**
 * The security review's fixes (1.25.0), run inside the test WordPress:
 *   php wp-cli.phar --path=<wordpress> eval-file tests/php/security.php
 * Prints one line per check and exits 1 if any fail. Leaves nothing behind.
 */

$GLOBALS['sc_pass'] = 0;
$GLOBALS['sc_fail'] = array();

function sc_t( $ok, $name, $detail = '' ) {
	if ( $ok ) {
		++$GLOBALS['sc_pass'];
		echo "ok   $name\n";
	} else {
		$GLOBALS['sc_fail'][] = $name;
		echo "FAIL $name" . ( '' !== (string) $detail ? ': ' . $detail : '' ) . "\n";
	}
}

wp_set_current_user( 1 );
$sc_up    = wp_upload_dir();
$sc_base  = trailingslashit( $sc_up['basedir'] );
$sc_burl  = trailingslashit( $sc_up['baseurl'] );
$sc_mp3   = "ID3\x03\x00\x00\x00\x00\x00\x00" . str_repeat( "\xFF\xFB\x90\x00", 64 );
$sc_was_pc = get_option( 'rrt_podcast', null );
$sc_was_sp = get_option( 'rrt_private_check', null );
$GLOBALS['sc_clean'] = array( 'posts' => array(), 'users' => array(), 'files' => array(), 'dirs' => array() );
register_shutdown_function(
	function () use ( $sc_was_pc, $sc_was_sp ) {
		foreach ( $GLOBALS['sc_clean']['posts'] as $p ) {
			foreach ( get_children( array( 'post_parent' => $p, 'post_type' => 'attachment', 'numberposts' => -1, 'post_status' => 'any' ) ) as $a ) {
				wp_delete_attachment( $a->ID, true );
			}
			wp_delete_post( $p, true );
		}
		foreach ( $GLOBALS['sc_clean']['atts'] ?? array() as $a ) {
			wp_delete_attachment( $a, true );
		}
		foreach ( $GLOBALS['sc_clean']['files'] as $f ) {
			if ( is_file( $f ) ) {
				unlink( $f );
			}
		}
		foreach ( array_reverse( $GLOBALS['sc_clean']['dirs'] ) as $d ) {
			@rmdir( $d ); // phpcs:ignore
		}
		/* Empty folders this test left inside the members' folder (the random ones). */
		$top = trailingslashit( wp_upload_dir()['basedir'] ) . 'rrt-members';
		if ( is_dir( $top ) ) {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $top, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $it as $f ) {
				if ( $f->isDir() ) {
					@rmdir( $f->getPathname() ); // phpcs:ignore
				}
			}
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $GLOBALS['sc_clean']['users'] as $u ) {
			wp_delete_user( $u );
		}
		null === $sc_was_pc ? delete_option( 'rrt_podcast' ) : update_option( 'rrt_podcast', $sc_was_pc );
		null === $sc_was_sp ? delete_option( 'rrt_private_check' ) : update_option( 'rrt_private_check', $sc_was_sp );
		delete_option( 'rrt_mfiles_layout' );
		remove_all_filters( 'pre_wp_mail' );
		$n = count( $GLOBALS['sc_fail'] );
		echo "\n" . $GLOBALS['sc_pass'] . ' passed, ' . $n . " failed\n";
		if ( $n ) {
			exit( 1 );
		}
	}
);

/** A file in uploads, made for this test. */
function sc_file( $rel, $body ) {
	$path = trailingslashit( wp_upload_dir()['basedir'] ) . $rel;
	$dir  = dirname( $path );
	$top  = trailingslashit( wp_upload_dir()['basedir'] );
	$add  = array();
	for ( $d = $dir; strlen( $d ) > strlen( $top ) - 1 && ! is_dir( $d ); $d = dirname( $d ) ) {
		$add[] = $d;
	}
	wp_mkdir_p( $dir );
	foreach ( array_reverse( $add ) as $d ) {
		$GLOBALS['sc_clean']['dirs'][] = $d;
	}
	file_put_contents( $path, $body );
	$GLOBALS['sc_clean']['files'][] = $path;
	return $path;
}

/** A user with a role, made for this test. */
function sc_user( $role ) {
	$id = wp_insert_user(
		array(
			'user_login' => 'sc_' . $role . '_' . wp_generate_password( 6, false ),
			'user_pass'  => wp_generate_password(),
			'user_email' => 'sc_' . wp_generate_password( 6, false ) . '@example.com',
			'role'       => $role,
		)
	);
	$GLOBALS['sc_clean']['users'][] = $id;
	return $id;
}

/* -------------------------------------------------------------------
 * 1. Which files a reading may use
 * ---------------------------------------------------------------- */

echo "== Which files a reading may use\n";
$sc_ok = sc_file( 'sc-test/2026/11/reading.mp3', $sc_mp3 );
sc_t( rrt_upload_path_ok( $sc_ok, 'audio' ), 'an ordinary MP3 in uploads may be used' );
foreach ( rrt_private_folders() as $sc_pf ) {
	$f = sc_file( $sc_pf . '/sc-test-' . wp_generate_password( 6, false ) . '.mp3', $sc_mp3 );
	sc_t( ! rrt_upload_path_ok( $f, 'audio' ), "a file in $sc_pf may never be used" );
}
sc_file( 'sc-closed/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n" );
$sc_closed = sc_file( 'sc-closed/inner/customers.mp3', $sc_mp3 );
sc_t( ! rrt_upload_path_ok( $sc_closed, 'audio' ), 'a file below a folder whose .htaccess turns visitors away may not be used' );
sc_file( 'sc-phponly/.htaccess', "<Files *.php>\ndeny from all\n</Files>\n<FilesMatch \"\\.(phtml|phar)$\">\nRequire all denied\n</FilesMatch>\n" );
$sc_phponly = sc_file( 'sc-phponly/reading.mp3', $sc_mp3 );
sc_t( rrt_upload_path_ok( $sc_phponly, 'audio' ), 'a folder closed only to PHP files (as security plugins do) still allows a reading' );
sc_t( rrt_htaccess_denies( "<Files *.php>\ndeny from all\n</Files>\n", 'x.php' ) && rrt_htaccess_denies( "Order allow,deny\nDeny from all\n", 'a.mp3' ) && ! rrt_htaccess_denies( "php_flag engine 0\n", 'a.mp3' ), 'the .htaccess reading: a PHP-only section, an old-style deny, no deny' );
$sc_fake = sc_file( 'sc-test/2026/11/not-audio.mp3', "<?php echo 'hello';" );
sc_t( ! rrt_upload_path_ok( $sc_fake, 'audio' ), 'a file named .mp3 that is not audio may not be used' );
$sc_csv = sc_file( 'sc-test/2026/11/list.csv', "a,b\n" );
sc_t( rrt_upload_path_ok( $sc_csv, '' ) && ! rrt_upload_path_ok( $sc_csv, 'audio' ) && ! rrt_upload_path_ok( $sc_csv, 'listen' ), 'another type may be read where any file is asked for, never as a reading' );
$sc_vtt = sc_file( 'sc-test/2026/11/captions.vtt', "WEBVTT\n\n00:00.000 --> 00:01.000\nHello\n" );
sc_t( rrt_upload_path_ok( $sc_vtt, 'text' ) && rrt_upload_path_ok( $sc_vtt, 'listen' ) && ! rrt_upload_path_ok( $sc_vtt, 'audio' ), 'captions are allowed as captions, never as audio' );
sc_t( ! rrt_upload_path_ok( $sc_base . 'sc-test/../sc-closed/inner/customers.mp3', 'audio' ) && ! rrt_upload_path_ok( '/etc/passwd' ) && ! rrt_upload_path_ok( ABSPATH . 'wp-config.php' ), 'nothing outside uploads, and no way round a closed folder with ..' );

/* The four places that turn a reading's address into a file. */
$sc_author = sc_user( 'author' );
wp_set_current_user( $sc_author );
$sc_ap = wp_insert_post( array( 'post_title' => 'Security test reading', 'post_content' => 'Words.', 'post_status' => 'publish', 'post_author' => $sc_author ) );
$GLOBALS['sc_clean']['posts'][] = $sc_ap;
sc_t( is_protected_meta( 'rrt_audio', 'post' ) && is_protected_meta( 'rrt_captions', 'post' ) && is_protected_meta( 'rrt_credit', 'post' ), 'the plugin\'s own fields are protected, so the Custom Fields panel never offers them' );
update_post_meta( $sc_ap, 'rrt_audio', $sc_burl . 'sc-closed/inner/customers.mp3' );
sc_t( null === rrt_dl_target( $sc_ap, '' ), 'the short address answers nothing for a reading pointed into a closed folder' );
sc_t( '' === rrt_dl_local_path( $sc_burl . 'sc-closed/inner/customers.mp3' ), 'and the hand-off is never given its path' );
unset( $GLOBALS['rrt_off_wanted'] );
sc_t( ! isset( rrt_off_wanted()[ 'sc-closed/inner/customers.mp3' ] ) && ! rrt_upload_path_ok( rrt_off_path( rrt_off_rel( $sc_burl . 'sc-closed/inner/customers.mp3' ) ) ), 'storage offload never copies it' );
sc_t( '' === rrt_col_reading_path( $sc_ap ), 'a collection or a sponsored copy never reads it' );
sc_t( '' === rrt_podcast_local_path( $sc_burl . 'sc-closed/inner/customers.mp3', $sc_ap, 'audio' ), 'nor does the feed' );
update_post_meta( $sc_ap, 'rrt_audio', $sc_burl . 'rrt-kept/whatever.mp3' );
sc_t( null === rrt_dl_target( $sc_ap, '' ), 'nor a reading pointed into a private folder' );
update_post_meta( $sc_ap, 'rrt_audio', $sc_burl . 'sc-test/2026/11/reading.mp3' );
$sc_t = rrt_dl_target( $sc_ap, '' );
sc_t( $sc_t && realpath( $sc_t['path'] ) === realpath( $sc_ok ), 'an ordinary file at a plain address still works' );
sc_t( realpath( rrt_col_reading_path( $sc_ap ) ) === realpath( $sc_ok ), 'for collections too' );
wp_set_current_user( 1 );

/* A members' file is only ever its own post's. */
$sc_mp = wp_insert_post( array( 'post_title' => 'Security test members reading', 'post_content' => 'Words.', 'post_status' => 'publish' ) );
$GLOBALS['sc_clean']['posts'][] = $sc_mp;
$sc_mpath = sc_file( 'sc-test/2026/11/members-reading.mp3', $sc_mp3 );
$sc_matt  = wp_insert_attachment( array( 'post_mime_type' => 'audio/mpeg', 'post_title' => 'Members reading', 'post_status' => 'inherit' ), $sc_mpath, $sc_mp );
update_post_meta( $sc_mp, 'rrt_audio', $sc_matt );
sc_t( rrt_mfile_protect( $sc_matt, $sc_mp ) && rrt_mfile_is( $sc_matt ), 'a reading can be kept for members' );
$sc_krel = rrt_mfile_rel( $sc_matt );
$GLOBALS['sc_clean']['files'][] = $sc_base . $sc_krel;
sc_t( (bool) preg_match( '#^rrt-members/[a-z0-9]{20}/sc-test/2026/11/members-reading\.mp3$#', $sc_krel ), 'kept in a random folder of its own', $sc_krel );
sc_t( ! is_file( $sc_mpath ) && is_file( $sc_base . $sc_krel ), 'the file moved' );
sc_t( '' !== rrt_att_file( $sc_matt, $sc_mp, 'audio' ) && '' === rrt_att_file( $sc_matt, $sc_ap, 'audio' ) && '' === rrt_att_file( $sc_matt, 0, 'audio' ), 'its own post may use it; no other post may' );
sc_t( rrt_att_choosable( $sc_matt, $sc_mp ) && ! rrt_att_choosable( $sc_matt, $sc_ap ), 'the Reading audio box offers it only to its own post' );
$_POST = array( 'rrt_reading_nonce' => wp_create_nonce( 'rrt_reading_box' ), 'rrt_audio' => (string) $sc_matt );
delete_post_meta( $sc_ap, 'rrt_audio' );
do_action( 'save_post', $sc_ap, get_post( $sc_ap ), true );
sc_t( '' === (string) get_post_meta( $sc_ap, 'rrt_audio', true ), 'saving another post with that file chosen leaves its reading empty' );
$_POST = array();
update_post_meta( $sc_ap, 'rrt_audio', rrt_mfile_url( $sc_matt ) );
sc_t( '' === rrt_col_reading_path( $sc_ap ) && '' === rrt_podcast_local_path( rrt_mfile_url( $sc_matt ), $sc_ap, 'audio' ), 'and its checked address typed into another post gives nothing' );
sc_t( realpath( rrt_col_reading_path( $sc_mp ) ) === realpath( $sc_base . $sc_krel ), 'its own post still reads it (for its own collection and copies)' );
$sc_url = wp_get_attachment_url( $sc_matt );
sc_t( rrt_mfile_release( $sc_matt ) && 'sc-test/2026/11/members-reading.mp3' === rrt_mfile_rel( $sc_matt ) && is_file( $sc_mpath ), 'opened to everyone again, it goes back to its old place' );
sc_t( ! is_dir( $sc_base . dirname( $sc_krel ) ), 'and its random folder is gone' );
sc_t( false !== strpos( $sc_url, '/podcast-member/file/' . $sc_matt . '/' ), 'while kept, its address was the checked one' );

/* Files kept before 1.25.0 move into a random folder once. */
$sc_old = sc_file( 'rrt-members/sc-old/2025/04/old-members.mp3', $sc_mp3 );
$GLOBALS['sc_clean']['dirs'][] = $sc_base . 'rrt-members/sc-old';
$sc_oatt = wp_insert_attachment( array( 'post_mime_type' => 'audio/mpeg', 'post_title' => 'Old members reading', 'post_status' => 'inherit' ), $sc_old, $sc_mp );
update_post_meta( $sc_oatt, '_wp_attached_file', 'rrt-members/sc-old/2025/04/old-members.mp3' );
update_post_meta( $sc_oatt, RRT_MFILE_META, $sc_mp );
$GLOBALS['sc_clean']['atts'][] = $sc_oatt;
$sc_ourl = wp_get_attachment_url( $sc_oatt );
delete_option( 'rrt_mfiles_layout' );
rrt_mfiles_upgrade();
$sc_orel = rrt_mfile_rel( $sc_oatt );
$GLOBALS['sc_clean']['files'][] = $sc_base . $sc_orel;
$GLOBALS['sc_clean']['dirs'][]  = $sc_base . dirname( $sc_orel );
sc_t( (bool) preg_match( '#^rrt-members/[a-z0-9]{20}/sc-old/2025/04/old-members\.mp3$#', $sc_orel ) && is_file( $sc_base . $sc_orel ) && ! is_file( $sc_old ), 'the upgrade moves a file kept before into a random folder', $sc_orel );
sc_t( wp_get_attachment_url( $sc_oatt ) === $sc_ourl, 'its address stays the same' );
sc_t( '2' === get_option( 'rrt_mfiles_layout' ), 'and the upgrade runs once' );
rrt_mfiles_upgrade();
sc_t( rrt_mfile_rel( $sc_oatt ) === $sc_orel, 'running it again changes nothing' );
sc_t( '2026/11/a.mp3' === rrt_mfile_unkeyed( 'abcdefghij0123456789/2026/11/a.mp3' ), 'a random folder is taken off when a file goes back' );

/* -------------------------------------------------------------------
 * 2. Sponsor messages are the owner's
 * ---------------------------------------------------------------- */

echo "== Who may make sponsor messages\n";
$sc_obj = get_post_type_object( RRT_SPOT_TYPE );
$sc_editor = sc_user( 'editor' );
foreach ( array( 'contributor' => false, 'author' => false, 'editor' => false, 'administrator' => true ) as $sc_role => $sc_may ) {
	$u = 'administrator' === $sc_role ? 1 : ( 'editor' === $sc_role ? $sc_editor : ( 'author' === $sc_role ? $sc_author : sc_user( 'contributor' ) ) );
	wp_set_current_user( $u );
	sc_t( $sc_may === ( current_user_can( $sc_obj->cap->create_posts ) && current_user_can( $sc_obj->cap->publish_posts ) ), ( 'administrator' === $sc_role || 'author' === $sc_role || 'editor' === $sc_role ? 'an ' : 'a ' ) . $sc_role . ( $sc_may ? ' may' : ' may not' ) . ' make and publish sponsor messages' );
}
wp_set_current_user( 1 );
$sc_spot = wp_insert_post( array( 'post_type' => RRT_SPOT_TYPE, 'post_title' => 'Security test message', 'post_status' => 'publish' ) );
$GLOBALS['sc_clean']['posts'][] = $sc_spot;
wp_set_current_user( $sc_editor );
sc_t( ! current_user_can( 'edit_post', $sc_spot ) && ! current_user_can( 'delete_post', $sc_spot ), 'an editor may not change or delete one' );
add_filter( 'rrt_spot_capability', function () { return 'edit_others_posts'; } );
sc_t( current_user_can( 'edit_post', $sc_spot ) && current_user_can( $sc_obj->cap->publish_posts ), 'a site can give them to Editors with the filter' );
remove_all_filters( 'rrt_spot_capability' );
wp_set_current_user( 1 );
sc_t( current_user_can( 'edit_post', $sc_spot ) && current_user_can( 'delete_post', $sc_spot ), 'the administrator may change and delete it' );

/* -------------------------------------------------------------------
 * 3. A reading that becomes private loses its sponsored copies at once
 * ---------------------------------------------------------------- */

echo "== Sponsored copies of a reading that becomes private\n";
$sc_rp = wp_insert_post( array( 'post_title' => 'Security test sponsored', 'post_content' => 'Words.', 'post_status' => 'publish' ) );
$GLOBALS['sc_clean']['posts'][] = $sc_rp;
$sc_now = sc_file( RRT_SPOT_DIR . '/' . $sc_rp . '/security-test-sp0000aaaa.mp3', $sc_mp3 );
$sc_was = sc_file( RRT_SPOT_DIR . '/' . $sc_rp . '/security-test-sp0000bbbb.mp3', $sc_mp3 );
$GLOBALS['sc_clean']['dirs'][] = rrt_spot_dir() . '/' . $sc_rp;
update_post_meta(
	$sc_rp,
	RRT_SPOT_BUILT,
	array(
		'rel'   => $sc_rp . '/security-test-sp0000aaaa.mp3',
		'bytes' => strlen( $sc_mp3 ),
		'secs'  => 1,
		'key'   => 'x',
		'old'   => array( array( 'rels' => array( $sc_rp . '/security-test-sp0000bbbb.mp3' ), 'until' => time() + DAY_IN_SECONDS ) ),
	)
);
sc_t( ! rrt_spot_now_private( $sc_rp ) && null !== rrt_spot_dl_target( $sc_rp, 'security-test-sp0000bbbb.mp3' ), 'while public, an older copy is still sent for two days' );
update_post_meta( $sc_rp, RRT_ACCESS_META, array( 'who' => 'members', 'days' => 7 ) );
wp_cache_delete( $sc_rp, 'post_meta' );
sc_t( rrt_spot_now_private( $sc_rp ) && null === rrt_spot_dl_target( $sc_rp, 'security-test-sp0000bbbb.mp3' ) && null === rrt_spot_dl_target( $sc_rp, 'security-test-sp0000aaaa.mp3' ), 'members-only: the short address sends no copy' );
rrt_spot_sync( $sc_rp );
sc_t( ! is_file( $sc_now ) && ! is_file( $sc_was ) && '' === (string) get_post_meta( $sc_rp, RRT_SPOT_BUILT, true ), 'and every copy, older ones too, is deleted at once' );
delete_post_meta( $sc_rp, RRT_ACCESS_META );
wp_update_post( array( 'ID' => $sc_rp, 'post_status' => 'draft' ) );
sc_t( rrt_spot_now_private( $sc_rp ), 'a reading taken back to a draft counts as private too' );
sc_t( ! rrt_spot_now_private( $sc_spot ), 'a message itself never does' );

/* -------------------------------------------------------------------
 * 4. Share for feedback
 * ---------------------------------------------------------------- */

echo "== Who a take can be shared with\n";
$sc_sub = sc_user( 'subscriber' );
wp_set_current_user( $sc_editor );
$sc_ids = wp_list_pluck( rrt_share_people(), 'id' );
sc_t( in_array( $sc_sub, $sc_ids, true ) && in_array( $sc_author, $sc_ids, true ), 'an editor may share with anyone' );
wp_set_current_user( $sc_author );
$sc_ids = wp_list_pluck( rrt_share_people(), 'id' );
sc_t( ! in_array( $sc_sub, $sc_ids, true ) && in_array( $sc_editor, $sc_ids, true ) && in_array( 1, $sc_ids, true ), 'an author sees the editors and administrators, never every account' );
global $wpdb;
$wpdb->insert( rrt_table( 'shares' ), array( 'token' => 'sctest' . wp_generate_password( 20, false ), 'owner_id' => $sc_sub, 'post_id' => $sc_ap, 'title' => 'x', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'ends_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ), 'viewers' => wp_json_encode( array( $sc_author ) ), 'words' => '[]' ) ); // phpcs:ignore
$sc_sid = (int) $wpdb->insert_id;
sc_t( in_array( $sc_sub, rrt_share_allowed_ids(), true ), 'and the people who shared a take with them' );
$wpdb->delete( rrt_table( 'shares' ), array( 'id' => $sc_sid ) ); // phpcs:ignore

$sc_sent = 0;
add_filter(
	'pre_wp_mail',
	function () use ( &$sc_sent ) {
		++$sc_sent;
		return true;
	}
);
wp_set_current_user( 1 );
delete_transient( 'rrt_shinv_1' );
set_transient( 'rrt_shinv_1', RRT_SHARE_INVITES_DAY - 1, DAY_IN_SECONDS );
$sc_n = rrt_share_invite( (object) array( 'owner_id' => 1, 'title' => 'x', 'token' => 'abc', 'ends_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ), array( $sc_sub, $sc_author, $sc_editor ) );
sc_t( 1 === $sc_n && 1 === $sc_sent, 'at most ' . RRT_SHARE_INVITES_DAY . ' invitations a day from one person', $sc_n );
delete_transient( 'rrt_shinv_1' );

/* -------------------------------------------------------------------
 * 5. Check private folders
 * ---------------------------------------------------------------- */

echo "== Check private folders\n";
$sc_s = rrt_private_check_start();
sc_t( 6 === count( $sc_s['folders'] ) && 24 === strlen( $sc_s['token'] ), 'a test file goes into each of the six private folders (1.34.0 added the take shelf)' );
$sc_files = get_transient( 'rrt_pcheck_' . $sc_s['token'] );
sc_t( is_array( $sc_files ) && count( array_filter( array_map( 'is_file', $sc_files ) ) ) === 6, 'and they are there' );
foreach ( array_keys( rrt_private_check_folders() ) as $sc_f ) {
	sc_t( is_file( $sc_base . $sc_f . '/.htaccess' ) && false !== strpos( (string) file_get_contents( $sc_base . $sc_f . '/.htaccess' ), 'Require all denied' ), "$sc_f has its .htaccess" );
}
$sc_r = rrt_private_check_done( $sc_s['token'], array( 'rrt-members' => $sc_s['token'], 'rrt-kept' => '' ) );
sc_t( array( 'rrt-members' ) === $sc_r['open'] && 6 === count( $sc_r['checked'] ), 'a folder that answered with the test file is named' );
sc_t( ! array_filter( array_map( 'is_file', $sc_files ) ), 'the test files are deleted' );
sc_t( get_option( 'rrt_private_check' ) === $sc_r, 'the result is kept for the screen' );
sc_t( is_wp_error( rrt_private_check_done( $sc_s['token'], array() ) ), 'a token works once' );
sc_t( false !== strpos( rrt_private_nginx_lines(), 'rrt-members|rrt-kept|rrt-sent|rrt-shares|rrt-audiobook' ) && false !== strpos( rrt_private_nginx_lines(), 'deny all;' ), 'the nginx lines name every folder' );
wp_set_current_user( $sc_editor );
$sc_req = new WP_REST_Request( 'POST', '/rrt/v1/private-check/start' );
sc_t( 403 === rest_do_request( $sc_req )->get_status() || 401 === rest_do_request( $sc_req )->get_status(), 'only an administrator may run it' );
wp_set_current_user( 1 );
