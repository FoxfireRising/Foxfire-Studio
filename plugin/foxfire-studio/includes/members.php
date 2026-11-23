<?php
/**
 * Member feeds (1.21.0): a private podcast feed for each member, and
 * readings that only members can hear, or hear first.
 *
 * Who is a member: Podcast feed, Member feeds decides, by the site's roles
 * with a choice per person on their profile (Follow their role, Always,
 * Never), or by an active membership plugin. Administrators always have a
 * feed, so the owner can check it. Code can answer through two filters,
 * rrt_member_is and rrt_member_can_hear.
 *
 * Which readings: each post (and collection) chooses Everyone, Members
 * first (for a number of days after it is published) or Members only. A
 * collection is as closed as the most closed reading in it.
 *
 * While a reading is for members, its files (the MP3, the captions and, for
 * a collection, its matched copies) live in uploads/rrt-members, a folder
 * that refuses every direct request. The attachments stay in the Media
 * Library; their address becomes /podcast-member/file/<id>/<name>, which
 * this file answers for signed-in people who may hear the reading. When the
 * reading opens to everyone, the files go back to their old place.
 *
 * Each member's feed is /podcast-member/<key>/ (feed.xml after it answers
 * too), with a random key
 * kept in their user meta. It holds every public episode plus the readings
 * the member may hear, whose audio, captions and chapters are served from
 * addresses with the same key. It works whether or not the public feed is
 * published, carries itunes:block and podcast:block, and says nothing about
 * the member. A reset key answers 410 Gone.
 *
 * Feed activity (off by default) keeps, for each member, the last time their
 * feed was fetched, the app's name and a count of the networks that fetched
 * it in the last seven days, as hashes that change every day. No IP address
 * is kept.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The first part of every member address. */
define( 'RRT_MEMBER_BASE', 'podcast-member' );

/** On a post or collection: who can hear its reading. */
define( 'RRT_ACCESS_META', '_rrt_access' );

/** On a person: their feed's key, and when it was made. */
define( 'RRT_MFEED_META', '_rrt_mfeed' );
define( 'RRT_MFEED_AT_META', '_rrt_mfeed_at' );

/** On a person: always or never a member, or empty to follow their role. */
define( 'RRT_MEMBER_META', '_rrt_member' );

/** On a person: their feed's activity, when Feed activity is on. */
define( 'RRT_MSEEN_META', '_rrt_mfeed_seen' );

/** On an attachment kept for members: the post whose reading it is. */
define( 'RRT_MFILE_META', '_rrt_member_of' );

/** The folder inside uploads, and the start of a protected file's relative path. */
define( 'RRT_MFILES', 'rrt-members' );

/** Members first: the days by default, and the most. */
define( 'RRT_MEMBER_DAYS', 7 );
define( 'RRT_MEMBER_DAYS_MAX', 365 );

/** More networks than this in a week marks an address as maybe shared. */
define( 'RRT_MEMBER_SHARED', 5 );

/*
 * ---------------------------------------------------------------------
 * Settings
 * ---------------------------------------------------------------------
 */

/** The member feed settings, from the podcast settings. */
function rrt_member_settings() {
	$s     = rrt_podcast_settings();
	$roles = array_values( array_filter( array_map( 'sanitize_key', (array) $s['m_roles'] ) ) );
	$via   = (string) $s['m_via'];
	if ( 'roles' !== $via && ! isset( rrt_member_plugins()[ $via ] ) ) {
		$via = 'roles';
	}
	return array(
		'on'       => ! empty( $s['m_on'] ),
		'roles'    => $roles,
		'via'      => $via,
		'title'    => trim( (string) $s['m_title'] ),
		'join'     => trim( (string) $s['m_join'] ),
		'activity' => ! empty( $s['m_activity'] ),
	);
}

/** Whether member feeds are switched on. */
function rrt_member_on() {
	return rrt_member_settings()['on'];
}

/**
 * The membership plugins that are active, by name: each can say whether a
 * person is a member and whether they may see a post.
 */
function rrt_member_plugins() {
	$out = array();
	if ( function_exists( 'pmpro_hasMembershipLevel' ) && function_exists( 'pmpro_has_membership_access' ) ) {
		$out['pmpro'] = 'Paid Memberships Pro';
	}
	if ( class_exists( 'MeprUser' ) ) {
		$out['memberpress'] = 'MemberPress';
	}
	if ( function_exists( 'rcp_user_has_active_membership' ) && function_exists( 'rcp_user_can_access' ) ) {
		$out['rcp'] = 'Restrict Content Pro';
	}
	if ( function_exists( 'wc_memberships_get_user_active_memberships' ) && function_exists( 'wc_memberships_user_can' ) ) {
		$out['wcm'] = 'WooCommerce Memberships';
	}
	if ( function_exists( 'memberful_wp_user_plans_subscribed_to' ) && function_exists( 'memberful_can_user_access_post' ) ) {
		$out['memberful'] = 'Memberful';
	}
	return $out;
}

/** Whether a membership plugin counts a person as a member. */
function rrt_member_plugin_is( $via, $uid ) {
	switch ( $via ) {
		case 'pmpro':
			return (bool) pmpro_hasMembershipLevel( null, $uid );
		case 'memberpress':
			$u = new MeprUser( $uid );
			return method_exists( $u, 'is_active' ) ? (bool) $u->is_active() : (bool) $u->active_product_subscriptions();
		case 'rcp':
			return (bool) rcp_user_has_active_membership( $uid );
		case 'wcm':
			return (bool) wc_memberships_get_user_active_memberships( $uid );
		case 'memberful':
			return (bool) memberful_wp_user_plans_subscribed_to( $uid );
	}
	return false;
}

/** Whether a membership plugin lets a member see a post. */
function rrt_member_plugin_can( $via, $uid, $post_id ) {
	switch ( $via ) {
		case 'pmpro':
			return (bool) pmpro_has_membership_access( $post_id, $uid );
		case 'memberpress':
			if ( class_exists( 'MeprRule' ) && method_exists( 'MeprRule', 'is_locked_for_user' ) ) {
				return ! MeprRule::is_locked_for_user( new MeprUser( $uid ), get_post( $post_id ) );
			}
			return true;
		case 'rcp':
			return (bool) rcp_user_can_access( $uid, $post_id );
		case 'wcm':
			return (bool) wc_memberships_user_can( $uid, 'view', array( 'post' => $post_id ) );
		case 'memberful':
			return (bool) memberful_can_user_access_post( $uid, $post_id );
	}
	return true;
}

/** A person's own choice: 'always', 'never' or '' (follow their role). */
function rrt_member_pref( $uid ) {
	$v = (string) get_user_meta( $uid, RRT_MEMBER_META, true );
	return in_array( $v, array( 'always', 'never' ), true ) ? $v : '';
}

/** Whether a person is a member, so has a feed. */
function rrt_member_is( $uid ) {
	$uid = (int) $uid;
	$m   = rrt_member_settings();
	$yes = false;
	$u   = $uid ? get_userdata( $uid ) : false;
	if ( $m['on'] && $u ) {
		$pref = rrt_member_pref( $uid );
		if ( user_can( $u, 'manage_options' ) ) {
			$yes = true;
		} elseif ( 'never' === $pref ) {
			$yes = false;
		} elseif ( 'always' === $pref ) {
			$yes = true;
		} elseif ( 'roles' === $m['via'] ) {
			$yes = (bool) array_intersect( (array) $u->roles, $m['roles'] );
		} else {
			$yes = rrt_member_plugin_is( $m['via'], $uid );
		}
	}
	/**
	 * Whether a person is a member and has a member feed (1.21.0).
	 *
	 * @param bool $yes Foxfire Studio's answer.
	 * @param int  $uid The person.
	 */
	return (bool) apply_filters( 'rrt_member_is', $yes, $uid );
}

/*
 * ---------------------------------------------------------------------
 * Who can hear a reading
 * ---------------------------------------------------------------------
 */

/** A post's own choice: who (everyone, first or members) and days. */
function rrt_access_choice( $post_id ) {
	$m    = get_post_meta( $post_id, RRT_ACCESS_META, true );
	$m    = is_array( $m ) ? $m : array();
	$who  = isset( $m['who'] ) && in_array( $m['who'], array( 'first', 'members' ), true ) ? $m['who'] : 'everyone';
	$days = isset( $m['days'] ) ? (int) $m['days'] : RRT_MEMBER_DAYS;
	return array(
		'who'  => $who,
		'days' => max( 1, min( RRT_MEMBER_DAYS_MAX, $days ) ),
	);
}

/** Saves a post's choice; Everyone removes it. */
function rrt_access_save( $post_id, $who, $days ) {
	if ( ! in_array( $who, array( 'first', 'members' ), true ) ) {
		delete_post_meta( $post_id, RRT_ACCESS_META );
		rrt_access_sync( $post_id );
		return;
	}
	update_post_meta(
		$post_id,
		RRT_ACCESS_META,
		array(
			'who'  => $who,
			'days' => max( 1, min( RRT_MEMBER_DAYS_MAX, (int) $days ) ),
		)
	);
	rrt_access_sync( $post_id );
}

/** When a Members first post opens to everyone (a time), or 0 while it is not published. */
function rrt_access_open_at( $post_id, $days ) {
	$p = get_post( $post_id );
	if ( ! $p || 'publish' !== $p->post_status ) {
		return 0;
	}
	$t = strtotime( $p->post_date_gmt . ' UTC' );
	return $t ? $t + (int) $days * DAY_IN_SECONDS : 0;
}

/**
 * Who can hear a post's reading now.
 *
 * @return array who (everyone, first or members), open (when a first opens, or 0), why (a reading that makes a collection closed, or 0).
 */
function rrt_access_state( $post_id, $now = null ) {
	$now  = null === $now ? time() : (int) $now;
	$c    = rrt_access_choice( $post_id );
	$st   = array(
		'who'  => 'everyone',
		'open' => 0,
		'why'  => 0,
	);
	if ( 'members' === $c['who'] ) {
		$st['who'] = 'members';
	} elseif ( 'first' === $c['who'] ) {
		$open = rrt_access_open_at( $post_id, $c['days'] );
		if ( ! $open || $open > $now ) {
			$st['who']  = 'first';
			$st['open'] = $open;
		}
	}
	/* A collection is as closed as the most closed reading in it. */
	if ( function_exists( 'rrt_col_is' ) && rrt_col_is( $post_id ) ) {
		foreach ( rrt_col_settings( $post_id )['items'] as $id ) {
			if ( rrt_col_is( $id ) ) {
				continue;
			}
			$r = rrt_access_state( $id, $now );
			if ( 'members' === $r['who'] && 'members' !== $st['who'] ) {
				$st = array(
					'who'  => 'members',
					'open' => 0,
					'why'  => (int) $id,
				);
			} elseif ( 'first' === $r['who'] && 'everyone' === $st['who'] ) {
				$st = array(
					'who'  => 'first',
					'open' => $r['open'],
					'why'  => (int) $id,
				);
			} elseif ( 'first' === $r['who'] && 'first' === $st['who'] && $st['open'] && ( ! $r['open'] || $r['open'] > $st['open'] ) ) {
				$st['open'] = $r['open'];
				$st['why']  = (int) $id;
			}
		}
	}
	return $st;
}

/** Whether a post's reading is only for members now. */
function rrt_access_locked( $post_id ) {
	return 'everyone' !== rrt_access_state( $post_id )['who'];
}

/** Whether a person may hear a post's reading. */
function rrt_member_can_hear( $post_id, $uid = null ) {
	$uid = null === $uid ? get_current_user_id() : (int) $uid;
	if ( ! rrt_access_locked( $post_id ) ) {
		return true;
	}
	$yes = false;
	if ( $uid && user_can( $uid, 'edit_post', $post_id ) ) {
		$yes = true;
	} elseif ( $uid && rrt_member_is( $uid ) ) {
		$m   = rrt_member_settings();
		$yes = 'roles' === $m['via'] || user_can( $uid, 'manage_options' ) || 'always' === rrt_member_pref( $uid ) ? true : rrt_member_plugin_can( $m['via'], $uid, $post_id );
	}
	/**
	 * Whether a person may hear a reading that is for members (1.21.0).
	 *
	 * @param bool $yes     Foxfire Studio's answer.
	 * @param int  $uid     The person, or 0.
	 * @param int  $post_id The post or collection.
	 */
	return (bool) apply_filters( 'rrt_member_can_hear', $yes, $uid, (int) $post_id );
}

/*
 * ---------------------------------------------------------------------
 * The protected folder
 * ---------------------------------------------------------------------
 */

/** The folder that holds files kept for members. */
function rrt_mfiles_dir() {
	$up = wp_upload_dir( null, false );
	return trailingslashit( $up['basedir'] ) . RRT_MFILES . '/';
}

/** Makes the folder, with files that refuse every direct request. */
function rrt_mfiles_ready() {
	$dir = rrt_mfiles_dir();
	if ( ! wp_mkdir_p( $dir ) ) {
		return false;
	}
	if ( ! file_exists( $dir . '.htaccess' ) ) {
		file_put_contents( $dir . '.htaccess', "# Foxfire Studio: readings for members are served only through the plugin's checked addresses.\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
	if ( ! file_exists( $dir . 'index.php' ) ) {
		file_put_contents( $dir . 'index.php', "<?php\n// Silence.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
	return true;
}

/** An attachment's path relative to uploads, as WordPress keeps it. */
function rrt_mfile_rel( $att ) {
	return ltrim( str_replace( '\\', '/', (string) get_post_meta( $att, '_wp_attached_file', true ) ), '/' );
}

/** Whether an attachment's file is kept for members. */
function rrt_mfile_is( $att ) {
	return 0 === strpos( rrt_mfile_rel( $att ), RRT_MFILES . '/' );
}

/** The post whose reading a kept file belongs to. */
function rrt_mfile_post( $att ) {
	$p = (int) get_post_meta( $att, RRT_MFILE_META, true );
	return $p ? $p : (int) wp_get_post_parent_id( $att );
}

/** The signed-in address of a kept file. */
function rrt_mfile_url( $att ) {
	$name = preg_replace( '/[^A-Za-z0-9._-]/', '', wp_basename( rrt_mfile_rel( $att ) ) );
	return home_url( '/' . RRT_MEMBER_BASE . '/file/' . (int) $att . '/' . ( '' === $name ? 'file' : $name ) );
}

/** The same address with a member's key, for their feed. */
function rrt_mfile_keyed( $url, $key ) {
	return str_replace( '/' . RRT_MEMBER_BASE . '/file/', '/' . RRT_MEMBER_BASE . '/' . $key . '/file/', (string) $url );
}

/* A kept file's address is the checked one, everywhere WordPress gives it. */
add_filter(
	'wp_get_attachment_url',
	function ( $url, $att ) {
		return rrt_mfile_is( $att ) ? rrt_mfile_url( $att ) : $url;
	},
	20,
	2
);

/** The attachment behind a signed-in or keyed file address, or 0. */
function rrt_mfile_from_url( $url ) {
	$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
	$m    = rrt_member_route( $path, (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
	return $m && 'file' === $m['what'] ? (int) $m['id'] : 0;
}

/** Moves a file, across folders and disks. */
function rrt_mfile_move( $from, $to ) {
	if ( ! wp_mkdir_p( dirname( $to ) ) ) {
		return false;
	}
	if ( @rename( $from, $to ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		return true;
	}
	if ( @copy( $from, $to ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_delete_file( $from );
		return true;
	}
	return false;
}

/** Moves an attachment's file into the folder. Returns true when it is there. */
function rrt_mfile_protect( $att, $post_id ) {
	update_post_meta( $att, RRT_MFILE_META, (int) $post_id );
	if ( rrt_mfile_is( $att ) ) {
		return true;
	}
	$rel  = rrt_mfile_rel( $att );
	$path = get_attached_file( $att, true );
	if ( '' === $rel || ! $path || ! file_exists( $path ) || false !== strpos( $rel, '..' ) || ! rrt_mfiles_ready() ) {
		return false;
	}
	/* 1.25.0: a random folder of its own, so the old public address cannot be turned into this one (nginx ignores the folder's .htaccess). */
	$rel = rrt_mfile_key() . '/' . $rel;
	$to  = rrt_mfiles_dir() . $rel;
	if ( ! rrt_mfile_move( $path, $to ) ) {
		return false;
	}
	update_post_meta( $att, '_wp_attached_file', RRT_MFILES . '/' . $rel );
	clean_post_cache( $att );
	return true;
}

/** Moves a kept file back to where it was. Returns true when it is back. */
function rrt_mfile_release( $att ) {
	if ( ! rrt_mfile_is( $att ) ) {
		delete_post_meta( $att, RRT_MFILE_META );
		return true;
	}
	$krel = rrt_mfile_rel( $att );
	$rel  = rrt_mfile_unkeyed( substr( $krel, strlen( RRT_MFILES ) + 1 ) );
	$up   = wp_upload_dir( null, false );
	$from = trailingslashit( $up['basedir'] ) . $krel;
	$to   = trailingslashit( $up['basedir'] ) . $rel;
	if ( '' === $rel || false !== strpos( $rel, '..' ) || ! file_exists( $from ) ) {
		return false;
	}
	if ( file_exists( $to ) ) {
		$to  = trailingslashit( dirname( $to ) ) . wp_unique_filename( dirname( $to ), wp_basename( $to ) );
		$rel = substr( $to, strlen( trailingslashit( $up['basedir'] ) ) );
	}
	if ( ! rrt_mfile_move( $from, $to ) ) {
		return false;
	}
	update_post_meta( $att, '_wp_attached_file', $rel );
	delete_post_meta( $att, RRT_MFILE_META );
	clean_post_cache( $att );
	rrt_mfile_tidy( dirname( $from ) );
	return true;
}

/** A new random folder name for a file kept for members (1.25.0). */
function rrt_mfile_key() {
	return strtolower( wp_generate_password( 20, false, false ) );
}

/** A kept file's path inside the folder without its random folder (1.25.0), as it was before it was kept. */
function rrt_mfile_unkeyed( $rel ) {
	return (string) preg_replace( '#^[a-z0-9]{20}/#', '', (string) $rel );
}

/** Removes empty folders left inside the members' folder, up to the folder itself. */
function rrt_mfile_tidy( $dir ) {
	$top = untrailingslashit( rrt_mfiles_dir() );
	$dir = untrailingslashit( (string) $dir );
	while ( strlen( $dir ) > strlen( $top ) && 0 === strpos( $dir, $top . '/' ) && is_dir( $dir ) ) {
		$left = array_diff( (array) scandir( $dir ), array( '.', '..' ) );
		if ( $left || ! @rmdir( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			return;
		}
		$dir = dirname( $dir );
	}
}

/**
 * Files kept before 1.25.0 sat at their old year and month: each moves into
 * a random folder of its own, once. Their checked addresses use the
 * attachment number, so nothing a member has changes.
 */
function rrt_mfiles_upgrade() {
	if ( '2' === (string) get_option( 'rrt_mfiles_layout' ) ) {
		return;
	}
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s", $wpdb->esc_like( RRT_MFILES . '/' ) . '%' ) );
	$all  = true;
	foreach ( (array) $rows as $r ) {
		$inner = substr( (string) $r->meta_value, strlen( RRT_MFILES ) + 1 );
		if ( '' === $inner || false !== strpos( $inner, '..' ) || preg_match( '#^[a-z0-9]{20}/#', $inner ) ) {
			continue;
		}
		$from = rrt_mfiles_dir() . $inner;
		if ( ! is_file( $from ) ) {
			continue;
		}
		$rel = rrt_mfile_key() . '/' . $inner;
		if ( rrt_mfile_move( $from, rrt_mfiles_dir() . $rel ) ) {
			update_post_meta( (int) $r->post_id, '_wp_attached_file', RRT_MFILES . '/' . $rel );
			clean_post_cache( (int) $r->post_id );
			rrt_mfile_tidy( dirname( $from ) );
		} else {
			$all = false;
		}
	}
	if ( $all ) {
		update_option( 'rrt_mfiles_layout', '2', false );
	}
}
add_action( 'admin_init', 'rrt_mfiles_upgrade' );
add_action( 'rrt_share_hourly', 'rrt_mfiles_upgrade' );

/**
 * The fields of a post that may hold its reading's files, with what is in
 * them now: the plugin's own reading and captions, and the extra field from
 * Settings.
 */
function rrt_access_fields() {
	$cfg  = rrt_config();
	$keys = array( 'rrt_audio', 'rrt_captions' );
	if ( ! empty( $cfg['player']['audio_key'] ) ) {
		$keys[] = (string) $cfg['player']['audio_key'];
	}
	return $keys;
}

/** The attachment a field's value points to, or 0. */
function rrt_access_field_att( $raw ) {
	if ( is_array( $raw ) ) {
		$raw = isset( $raw['ID'] ) ? $raw['ID'] : ( isset( $raw['id'] ) ? $raw['id'] : ( isset( $raw['url'] ) ? $raw['url'] : '' ) );
	}
	if ( is_numeric( $raw ) && (int) $raw > 0 && 'attachment' === get_post_type( (int) $raw ) ) {
		return (int) $raw;
	}
	if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
		return 0;
	}
	$id = rrt_mfile_from_url( $raw );
	if ( $id ) {
		return $id;
	}
	$id = attachment_url_to_postid( preg_replace( '#^//#', ( is_ssl() ? 'https' : 'http' ) . '://', trim( $raw ) ) );
	return $id > 0 ? (int) $id : 0;
}

/** Every attachment that carries a post's reading: its audio, captions and, for a collection, its matched copies. */
function rrt_access_atts( $post_id ) {
	$out = array();
	foreach ( rrt_access_fields() as $k ) {
		$a = rrt_access_field_att( get_post_meta( $post_id, $k, true ) );
		if ( $a ) {
			$out[ $a ] = $k;
		}
	}
	if ( function_exists( 'rrt_col_is' ) && rrt_col_is( $post_id ) && defined( 'RRT_COL_MATCH' ) ) {
		foreach ( get_children(
			array(
				'post_parent' => (int) $post_id,
				'post_type'   => 'attachment',
				'numberposts' => -1,
				'post_status' => 'any',
				'fields'      => 'ids',
			)
		) as $a ) {
			if ( get_post_meta( $a, RRT_COL_MATCH, true ) && ! isset( $out[ $a ] ) ) {
				$out[ (int) $a ] = '';
			}
		}
	}
	return $out;
}

/**
 * The other posts whose open reading uses the same file, by attachment id or
 * by address. Such a file stays where it is. Posts in the trash, automatic
 * drafts and revisions do not count.
 */
function rrt_access_sharers( $att, $post_id ) {
	global $wpdb;
	$keys   = rrt_access_fields();
	$url    = (string) wp_get_attachment_url( $att );
	$values = array_values( array_unique( array_filter( array( (string) $att, $url, preg_replace( '#^https?:#i', '', $url ) ) ) ) );
	$kin    = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
	$vin    = implode( ',', array_fill( 0, count( $values ), '%s' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM $wpdb->postmeta WHERE post_id <> %d AND meta_key IN ($kin) AND meta_value IN ($vin)", array_merge( array( (int) $post_id ), $keys, $values ) ) );
	$out = array();
	foreach ( $ids as $other ) {
		$p = get_post( (int) $other );
		if ( ! $p || in_array( $p->post_status, array( 'trash', 'auto-draft', 'inherit' ), true ) || 'revision' === $p->post_type ) {
			continue;
		}
		if ( ! rrt_access_locked( $p->ID ) ) {
			$out[] = (int) $p->ID;
		}
	}
	return $out;
}

/** Whether another post's open reading uses the same file. */
function rrt_access_shared( $att, $post_id ) {
	return (bool) rrt_access_sharers( $att, $post_id );
}
/**
 * Puts a post's files where its access says: kept for members while the
 * reading is for members, back in their place when it opens to everyone.
 * A field that holds an address instead of an attachment id is updated to
 * the file's new address. Collections holding the post follow it.
 *
 * @return bool Whether anything moved.
 */
function rrt_access_sync( $post_id ) {
	static $busy = array();
	$post_id = (int) $post_id;
	if ( ! $post_id || isset( $busy[ $post_id ] ) || ! get_post( $post_id ) ) {
		return false;
	}
	$busy[ $post_id ] = true;
	$lock             = rrt_access_locked( $post_id );
	$moved            = false;
	foreach ( rrt_access_atts( $post_id ) as $att => $key ) {
		$before = rrt_mfile_is( $att );
		if ( $lock && ! $before && ! rrt_access_shared( $att, $post_id ) ) {
			rrt_mfile_protect( $att, $post_id );
		} elseif ( $lock && $before ) {
			update_post_meta( $att, RRT_MFILE_META, $post_id );
		} elseif ( ! $lock && $before && rrt_mfile_post( $att ) === $post_id ) {
			rrt_mfile_release( $att );
		}
		if ( rrt_mfile_is( $att ) !== $before ) {
			$moved = true;
			/* A field holding an address follows the file. */
			$raw = '' !== $key ? get_post_meta( $post_id, $key, true ) : null;
			if ( is_string( $raw ) && ! is_numeric( $raw ) && '' !== trim( $raw ) ) {
				update_post_meta( $post_id, $key, (string) wp_get_attachment_url( $att ) );
			}
		}
	}
	if ( $moved ) {
		/* Readings' measured facts and picks for the feed name the old address. */
		$pick = get_post_meta( $post_id, '_rrt_soundbite', true );
		if ( is_array( $pick ) && isset( $pick['audio'] ) ) {
			$pick['audio'] = rrt_reading_audio( $post_id )['url'];
			update_post_meta( $post_id, '_rrt_soundbite', $pick );
		}
		clean_post_cache( $post_id );
		do_action( 'litespeed_purge_post', $post_id );
		if ( function_exists( 'rrt_podcast_purge' ) ) {
			rrt_podcast_purge( $post_id );
		}
	}
	if ( function_exists( 'rrt_col_containing' ) && ! rrt_col_is( $post_id ) ) {
		foreach ( rrt_col_containing( $post_id ) as $cid ) {
			rrt_access_sync( $cid );
		}
	}
	unset( $busy[ $post_id ] );
	return $moved;
}

/** Syncs a post only when its files are not where its access says. Cheap enough for every page. */
function rrt_access_settle( $post_id ) {
	$lock = rrt_access_locked( $post_id );
	foreach ( rrt_access_atts( $post_id ) as $att => $key ) {
		$kept = rrt_mfile_is( $att );
		if ( $kept !== $lock && ( $kept || ! rrt_access_shared( $att, $post_id ) ) ) {
			rrt_access_sync( $post_id );
			break;
		}
	}
	return ! $lock;
}

/** Every post with a choice of its own, as ids. */
function rrt_access_posts() {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM $wpdb->postmeta WHERE meta_key = %s", RRT_ACCESS_META ) ) );
}

/** The hourly check: Members first readings whose day has come open to everyone. */
function rrt_access_tick() {
	foreach ( rrt_access_posts() as $id ) {
		if ( 'first' === rrt_access_choice( $id )['who'] ) {
			rrt_access_settle( $id );
			if ( function_exists( 'rrt_col_containing' ) ) {
				foreach ( rrt_col_containing( $id ) as $cid ) {
					rrt_access_settle( $cid );
				}
			}
		}
	}
	rrt_mfeed_seen_trim();
}
add_action( 'rrt_share_hourly', 'rrt_access_tick' );

/* A new reading or captions on a post for members goes straight to the folder. */
function rrt_access_meta_changed( $meta_id, $post_id, $key ) {
	if ( in_array( $key, rrt_access_fields(), true ) && ( rrt_access_locked( $post_id ) || rrt_access_atts_kept( $post_id ) ) ) {
		rrt_access_sync( $post_id );
	}
}
add_action( 'added_post_meta', 'rrt_access_meta_changed', 30, 3 );
add_action( 'updated_post_meta', 'rrt_access_meta_changed', 30, 3 );

/** Whether any of a post's files are kept for members. */
function rrt_access_atts_kept( $post_id ) {
	foreach ( array_keys( rrt_access_atts( $post_id ) ) as $att ) {
		if ( rrt_mfile_is( $att ) ) {
			return true;
		}
	}
	return false;
}

/* Publishing, unpublishing or saving a collection can change who can hear it. */
add_action(
	'save_post',
	function ( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || 'attachment' === $post->post_type ) {
			return;
		}
		if ( get_post_meta( $post_id, RRT_ACCESS_META, true ) || ( function_exists( 'rrt_col_is' ) && rrt_col_is( $post_id ) ) || rrt_access_atts_kept( $post_id ) ) {
			rrt_access_sync( $post_id );
		}
	},
	40,
	2
);

/*
 * ---------------------------------------------------------------------
 * Member keys and addresses
 * ---------------------------------------------------------------------
 */

/** Whether a string looks like a key. */
function rrt_mfeed_key_ok( $key ) {
	return is_string( $key ) && (bool) preg_match( '/^[A-Za-z0-9]{32}$/', $key );
}

/** A person's key, made the first time it is needed. Empty for someone who is not a member. */
function rrt_mfeed_key( $uid, $make = true ) {
	$key = (string) get_user_meta( $uid, RRT_MFEED_META, true );
	if ( rrt_mfeed_key_ok( $key ) ) {
		return $key;
	}
	if ( ! $make || ! rrt_member_is( $uid ) ) {
		return '';
	}
	return rrt_mfeed_reset( $uid );
}

/** Gives a person a new key; the old address stops working. */
function rrt_mfeed_reset( $uid ) {
	$key = wp_generate_password( 32, false, false );
	update_user_meta( $uid, RRT_MFEED_META, $key );
	update_user_meta( $uid, RRT_MFEED_AT_META, time() );
	delete_user_meta( $uid, RRT_MSEEN_META );
	return $key;
}

/** Removes a person's key and activity. */
function rrt_mfeed_forget( $uid ) {
	delete_user_meta( $uid, RRT_MFEED_META );
	delete_user_meta( $uid, RRT_MFEED_AT_META );
	delete_user_meta( $uid, RRT_MSEEN_META );
}

/** The person a key belongs to, or 0. */
function rrt_mfeed_user( $key ) {
	if ( ! rrt_mfeed_key_ok( $key ) ) {
		return 0;
	}
	$u = get_users(
		array(
			'meta_key'   => RRT_MFEED_META, // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value' => $key, // phpcs:ignore WordPress.DB.SlowDBQuery
			'number'     => 1,
			'fields'     => 'ID',
		)
	);
	return $u ? (int) $u[0] : 0;
}

/** A person's feed address, or ''. */
function rrt_mfeed_url( $uid, $make = true ) {
	$key = rrt_mfeed_key( $uid, $make );
	return '' === $key ? '' : home_url( '/' . RRT_MEMBER_BASE . '/' . $key . '/' );
}

/** The member feed's own permanent podcast:guid, apart from the public show's. */
function rrt_mfeed_guid() {
	$s = rrt_podcast_settings();
	if ( ! empty( $s['m_guid'] ) ) {
		return (string) $s['m_guid'];
	}
	$name        = rtrim( preg_replace( '#^[a-z]+://#i', '', home_url( '/' . RRT_MEMBER_BASE . '/' ) ), '/' );
	$s['m_guid'] = rrt_podcast_uuid5( RRT_PODCAST_NS, $name );
	update_option( 'rrt_podcast', $s );
	return $s['m_guid'];
}

/** The member feed's title. */
function rrt_mfeed_title() {
	$m = rrt_member_settings();
	return '' !== $m['title'] ? $m['title'] : sprintf( /* translators: %s: the show's title. */ __( '%s (members)', 'foxfire-studio' ), rrt_podcast_title() );
}

/** The Become a member link, or ''. */
function rrt_member_join_url() {
	$j = rrt_member_settings()['join'];
	return preg_match( '#^https?://#i', $j ) ? $j : '';
}

/*
 * ---------------------------------------------------------------------
 * The addresses this file answers
 * ---------------------------------------------------------------------
 */

/**
 * What a request path asks for, or null.
 *
 * @param string $path      The request's path, without the query.
 * @param string $home_path The path of the site's home address.
 * @return array|null key ('' for a signed-in file), what (feed, file or chapters), id.
 */
function rrt_member_route( $path, $home_path ) {
	$home_path = '/' . trim( (string) $home_path, '/' );
	$home_path = '/' === $home_path ? '' : $home_path;
	$path      = rawurldecode( (string) $path );
	if ( '' !== $home_path ) {
		if ( 0 !== strpos( $path, $home_path . '/' ) ) {
			return null;
		}
		$path = substr( $path, strlen( $home_path ) );
	}
	$b = preg_quote( RRT_MEMBER_BASE, '#' );
	if ( preg_match( '#^/' . $b . '/file/([0-9]{1,20})(?:/[^/]*)?$#', $path, $m ) ) {
		return array(
			'key'  => '',
			'what' => 'file',
			'id'   => (int) $m[1],
		);
	}
	if ( preg_match( '#^/' . $b . '/([A-Za-z0-9]{32})(?:/(?:feed(?:\.xml)?)?)?/?$#', $path, $m ) ) {
		return array(
			'key'  => $m[1],
			'what' => 'feed',
			'id'   => 0,
		);
	}
	if ( preg_match( '#^/' . $b . '/([A-Za-z0-9]{32})/file/([0-9]{1,20})(?:/[^/]*)?$#', $path, $m ) ) {
		return array(
			'key'  => $m[1],
			'what' => 'file',
			'id'   => (int) $m[2],
		);
	}
	if ( preg_match( '#^/' . $b . '/([A-Za-z0-9]{32})/chapters/([0-9]{1,20})(?:\.json)?$#', $path, $m ) ) {
		return array(
			'key'  => $m[1],
			'what' => 'chapters',
			'id'   => (int) $m[2],
		);
	}
	return null;
}

/** Headers that keep a member's answer out of every shared cache. */
function rrt_member_nocache() {
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	nocache_headers();
	header( 'Cache-Control: private, no-store, max-age=0' );
	header( 'X-Robots-Tag: noindex, nofollow' );
}

/** Answers the member addresses. */
function rrt_member_serve() {
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$r   = rrt_member_route( (string) wp_parse_url( $uri, PHP_URL_PATH ), (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
	if ( ! $r ) {
		return;
	}
	rrt_member_nocache();
	$uid = 0;
	if ( '' !== $r['key'] ) {
		if ( ! rrt_member_on() ) {
			status_header( 404 );
			exit;
		}
		$uid = rrt_mfeed_user( $r['key'] );
		if ( ! $uid ) {
			/* An address that was reset, or never made: apps should stop asking. */
			status_header( 410 );
			exit;
		}
	} else {
		$uid = get_current_user_id();
	}
	if ( 'feed' === $r['what'] ) {
		rrt_mfeed_seen( $uid );
		rrt_podcast_render(
			array(
				'uid'    => $uid,
				'key'    => $r['key'],
				'member' => rrt_member_is( $uid ),
			)
		);
		exit;
	}
	if ( 'chapters' === $r['what'] ) {
		rrt_member_chapters( $r['id'], $uid );
		exit;
	}
	rrt_member_file( $r['id'], $uid, '' !== $r['key'] );
	exit;
}
add_action( 'init', 'rrt_member_serve', 1 );

/** Sends a kept file to a person who may hear it, with byte ranges so players can seek. */
function rrt_member_file( $att, $uid, $keyed ) {
	if ( 'attachment' !== get_post_type( $att ) ) {
		status_header( 404 );
		return;
	}
	if ( ! rrt_mfile_is( $att ) ) {
		/* Opened to everyone since: the file is at its own address again. */
		wp_safe_redirect( (string) wp_get_attachment_url( $att ), 302 );
		return;
	}
	$post = rrt_mfile_post( $att );
	if ( ! $post || ! get_post( $post ) ) {
		status_header( 404 );
		return;
	}
	$may = $keyed ? ( rrt_member_is( $uid ) && rrt_member_can_hear( $post, $uid ) ) : ( $uid && rrt_member_can_hear( $post, $uid ) );
	if ( ! $may ) {
		status_header( $uid ? 403 : 401 );
		return;
	}
	$path = get_attached_file( $att, true );
	if ( ! $path || ! is_file( $path ) ) {
		status_header( 410 );
		return;
	}
	/* Download counting (1.24.0), from a member's feed (the post's player is measured as plays): the member is the consumer, and only totals are kept. */
	if ( $keyed && function_exists( 'rrt_dl_take_member' ) ) {
		rrt_dl_take_member( $post, $att, $uid, $path );
	}
	rrt_member_send( $path, (string) get_post_mime_type( $att ) );
}

/** Sends a file, answering a single byte range. */
function rrt_member_send( $path, $type ) {
	$size  = (int) filesize( $path );
	$start = 0;
	$end   = $size - 1;
	$range = isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '';
	if ( $range && preg_match( '/^bytes=(\d*)-(\d*)$/', $range, $mm ) && ( '' !== $mm[1] || '' !== $mm[2] ) ) {
		if ( '' === $mm[1] ) {
			$start = max( 0, $size - (int) $mm[2] );
		} else {
			$start = (int) $mm[1];
			$end   = '' !== $mm[2] ? min( (int) $mm[2], $size - 1 ) : $size - 1;
		}
		if ( $start > $end || $start >= $size ) {
			status_header( 416 );
			header( 'Content-Range: bytes */' . $size );
			return;
		}
		status_header( 206 );
		header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $size );
	} else {
		status_header( 200 );
	}
	header( 'Content-Type: ' . ( '' !== $type ? $type : 'application/octet-stream' ) );
	header( 'Accept-Ranges: bytes' );
	header( 'Content-Length: ' . ( $end - $start + 1 ) );
	header( 'Content-Disposition: inline; filename="' . preg_replace( '/[^A-Za-z0-9._-]/', '', wp_basename( $path ) ) . '"' );
	if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'HEAD' === $_SERVER['REQUEST_METHOD'] ) {
		return;
	}
	while ( ob_get_level() ) {
		ob_end_clean();
	}
	$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( ! $fh ) {
		return;
	}
	fseek( $fh, $start );
	$left = $end - $start + 1;
	while ( $left > 0 && ! feof( $fh ) ) {
		$chunk = fread( $fh, (int) min( 262144, $left ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $chunk || '' === $chunk ) {
			break;
		}
		echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput
		$left -= strlen( $chunk );
		flush();
	}
	fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
}

/** A member's chapters file for a reading in their feed. */
function rrt_member_chapters( $post_id, $uid ) {
	$post = get_post( $post_id );
	if ( ! $post || 'publish' !== $post->post_status || ! rrt_member_is( $uid ) || ! rrt_member_can_hear( $post_id, $uid ) ) {
		status_header( 404 );
		return;
	}
	$facts = rrt_feed_facts( $post_id );
	if ( function_exists( 'rrt_col_is' ) && rrt_col_is( $post_id ) ) {
		$facts['chapters'] = rrt_col_feed_chapters( $post_id );
	}
	if ( empty( $facts['chapters'] ) ) {
		status_header( 404 );
		return;
	}
	status_header( 200 );
	header( 'Content-Type: application/json+chapters; charset=UTF-8' );
	echo rrt_chapters_json( $facts['chapters'], html_entity_decode( wp_strip_all_tags( get_the_title( $post_id ) ), ENT_QUOTES, 'UTF-8' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
}

/**
 * One episode as a member's feed gives it: a reading for members gets its
 * audio, captions and chapters from addresses with the member's key.
 */
function rrt_member_episode( $e, $post, $key ) {
	$st = rrt_access_state( $post->ID );
	if ( 'everyone' === $st['who'] ) {
		return $e;
	}
	$e['url']       = rrt_mfile_keyed( $e['url'], $key );
	$e['enclosure'] = $e['url'];
	$e['vtt']       = '' !== $e['vtt'] ? rrt_mfile_keyed( $e['vtt'], $key ) : '';
	$e['chapters']  = $e['nchapters'] ? home_url( '/' . RRT_MEMBER_BASE . '/' . $key . '/chapters/' . (int) $post->ID . '.json' ) : '';
	$e['text']      = trim(
		( 'first' === $st['who'] && $st['open']
			/* translators: %s: a date. */
			? sprintf( __( 'For members first, and for everyone from %s.', 'foxfire-studio' ), wp_date( get_option( 'date_format' ), $st['open'] ) )
			: __( 'For members.', 'foxfire-studio' ) ) . ' ' . $e['text']
	);
	return $e;
}

/** The note in a member feed's description. */
function rrt_mfeed_note( $member ) {
	if ( $member ) {
		return __( 'This feed is yours alone. Please keep its address private.', 'foxfire-studio' );
	}
	$j = rrt_member_join_url();
	return '' !== $j
		/* translators: %s: the Become a member link. */
		? sprintf( __( 'This feed now has the readings for everyone. To hear the readings for members again: %s', 'foxfire-studio' ), $j )
		: __( 'This feed now has the readings for everyone.', 'foxfire-studio' );
}

/*
 * ---------------------------------------------------------------------
 * Feed activity (off by default)
 * ---------------------------------------------------------------------
 */

/** Notes a fetch of a member's feed, when Feed activity is on. */
function rrt_mfeed_seen( $uid ) {
	if ( ! $uid || ! rrt_member_settings()['activity'] ) {
		return;
	}
	$ua   = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$day  = wp_date( 'Y-m-d' );
	$salt = function_exists( 'rrt_dl_salt' ) ? rrt_dl_salt() : wp_salt( 'nonce' ) . $day;
	$h    = substr( hash_hmac( 'sha256', $ip, $salt ), 0, 12 );
	$s    = get_user_meta( $uid, RRT_MSEEN_META, true );
	$s    = is_array( $s ) ? $s : array();
	$nets = isset( $s['nets'] ) && is_array( $s['nets'] ) ? $s['nets'] : array();
	$keep = wp_date( 'Y-m-d', time() - 6 * DAY_IN_SECONDS );
	foreach ( array_keys( $nets ) as $d ) {
		if ( $d < $keep ) {
			unset( $nets[ $d ] );
		}
	}
	$today = isset( $nets[ $day ] ) ? (array) $nets[ $day ] : array();
	if ( ! in_array( $h, $today, true ) && count( $today ) < 50 ) {
		$today[] = $h;
	}
	$nets[ $day ] = $today;
	update_user_meta(
		$uid,
		RRT_MSEEN_META,
		array(
			'at'   => time(),
			'app'  => function_exists( 'rrt_dl_app' ) ? rrt_dl_app( $ua ) : '',
			'nets' => $nets,
		)
	);
}

/** A member's activity: when, which app, how many networks this week, and whether that looks shared. */
function rrt_mfeed_activity( $uid ) {
	$s = get_user_meta( $uid, RRT_MSEEN_META, true );
	if ( ! is_array( $s ) || empty( $s['at'] ) ) {
		return null;
	}
	$keep = wp_date( 'Y-m-d', time() - 6 * DAY_IN_SECONDS );
	$all  = array();
	foreach ( (array) ( isset( $s['nets'] ) ? $s['nets'] : array() ) as $d => $list ) {
		if ( $d >= $keep ) {
			$all = array_merge( $all, (array) $list );
		}
	}
	$n = count( array_unique( $all ) );
	return array(
		'at'     => (int) $s['at'],
		'app'    => (string) $s['app'],
		'nets'   => $n,
		'shared' => $n > RRT_MEMBER_SHARED,
	);
}

/** Clears activity when Feed activity is off, and any older than 30 days. */
function rrt_mfeed_seen_trim() {
	if ( ! rrt_member_settings()['activity'] ) {
		delete_metadata( 'user', 0, RRT_MSEEN_META, '', true );
		return;
	}
	foreach ( get_users(
		array(
			'meta_key' => RRT_MSEEN_META, // phpcs:ignore WordPress.DB.SlowDBQuery
			'fields'   => 'ID',
			'number'   => 2000,
		)
	) as $uid ) {
		$s = get_user_meta( $uid, RRT_MSEEN_META, true );
		if ( ! is_array( $s ) || empty( $s['at'] ) || (int) $s['at'] < time() - 30 * DAY_IN_SECONDS ) {
			delete_user_meta( $uid, RRT_MSEEN_META );
		}
	}
}

/*
 * ---------------------------------------------------------------------
 * The post page: a reading for members
 * ---------------------------------------------------------------------
 */

/** The player's place for a reading the visitor cannot hear. */
function rrt_member_locked_html( $post ) {
	$cfg = rrt_config();
	$st  = rrt_access_state( $post->ID );
	$a   = rrt_reading_audio( $post->ID );
	$len = 0;
	if ( $a['id'] ) {
		$meta = wp_get_attachment_metadata( $a['id'] );
		$len  = ! empty( $meta['length'] ) ? (int) $meta['length'] : 0;
	}
	if ( function_exists( 'rrt_player_enqueue' ) ) {
		rrt_player_enqueue();
	}
	$join = rrt_member_join_url();
	$msg  = __( 'This reading is for members.', 'foxfire-studio' );
	if ( 'first' === $st['who'] && $st['open'] ) {
		/* translators: %s: a date. */
		$msg .= ' ' . sprintf( __( 'Everyone can hear it from %s.', 'foxfire-studio' ), wp_date( get_option( 'date_format' ), $st['open'] ) );
	}
	ob_start();
	?>
	<div class="rrt-player rrt-locked is-ready" role="group" aria-label="<?php esc_attr_e( 'Audio reading of this post', 'foxfire-studio' ); ?>">
		<div class="rrt-row">
			<span class="rrt-play rrt-lock" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 2a5 5 0 0 0-5 5v3H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2h-1V7a5 5 0 0 0-5-5zm-3 8V7a3 3 0 0 1 6 0v3z"/></svg></span>
			<div class="rrt-main">
				<div class="rrt-label"><?php echo esc_html( $cfg['text']['listen'] ); ?></div>
				<div class="rrt-locked-msg"><?php echo esc_html( $msg ); ?></div>
				<?php if ( ! is_user_logged_in() || '' !== $join ) : ?>
				<div class="rrt-locked-links">
					<?php if ( ! is_user_logged_in() ) : ?>
						<a href="<?php echo esc_url( wp_login_url( (string) get_permalink( $post ) ) ); ?>"><?php esc_html_e( 'Sign in', 'foxfire-studio' ); ?></a>
					<?php endif; ?>
					<?php if ( '' !== $join ) : ?>
						<a href="<?php echo esc_url( $join ); ?>"><?php esc_html_e( 'Become a member', 'foxfire-studio' ); ?></a>
					<?php endif; ?>
				</div>
				<?php endif; ?>
			</div>
			<?php if ( $len ) : ?>
				<span class="rrt-time"><span class="rrt-len"><?php echo esc_html( rrt_clock( $len ) ); ?></span></span>
			<?php endif; ?>
		</div>
	</div>
	<?php
	return trim( ob_get_clean() );
}

/*
 * ---------------------------------------------------------------------
 * The choice on a post and on a collection
 * ---------------------------------------------------------------------
 */

/** Prints the Who can hear this reading choice. */
function rrt_access_box_fields( $post ) {
	$c  = rrt_access_choice( $post->ID );
	$st = rrt_access_state( $post->ID );
	wp_nonce_field( 'rrt_access_box', 'rrt_access_nonce' );
	$col = function_exists( 'rrt_col_is' ) && rrt_col_is( $post );
	echo '<p class="rrt-box-field"><label for="rrt_access"><strong>' . esc_html( $col ? __( 'Who can hear this collection', 'foxfire-studio' ) : __( 'Who can hear this reading', 'foxfire-studio' ) ) . '</strong></label><br>';
	echo '<select class="widefat" id="rrt_access" name="rrt_access">';
	echo '<option value="everyone"' . selected( 'everyone', $c['who'], false ) . '>' . esc_html__( 'Everyone', 'foxfire-studio' ) . '</option>';
	echo '<option value="first"' . selected( 'first', $c['who'], false ) . '>' . esc_html__( 'Members first, then everyone', 'foxfire-studio' ) . '</option>';
	echo '<option value="members"' . selected( 'members', $c['who'], false ) . '>' . esc_html__( 'Members only', 'foxfire-studio' ) . '</option>';
	echo '</select></p>';
	echo '<p class="rrt-box-field"><label for="rrt_access_days">' . esc_html__( 'Days for members first', 'foxfire-studio' ) . '</label> <input type="number" class="small-text" id="rrt_access_days" name="rrt_access_days" min="1" max="' . (int) RRT_MEMBER_DAYS_MAX . '" value="' . (int) $c['days'] . '"></p>';
	$lines = array();
	if ( 'members' === $st['who'] ) {
		$lines[] = __( 'Now: only members can hear it.', 'foxfire-studio' );
	} elseif ( 'first' === $st['who'] ) {
		$lines[] = $st['open']
			/* translators: %s: a date. */
			? sprintf( __( 'Now: members only, and everyone from %s.', 'foxfire-studio' ), wp_date( get_option( 'date_format' ), $st['open'] ) )
			: __( 'Now: members only. The days count from when it is published.', 'foxfire-studio' );
	} elseif ( 'first' === $c['who'] ) {
		$lines[] = __( 'Now: everyone can hear it (its days for members are over).', 'foxfire-studio' );
	}
	if ( $st['why'] ) {
		/* translators: %s: a post's title. */
		$lines[] = sprintf( __( 'Because "%s" in it is for members, so is the collection.', 'foxfire-studio' ), wp_strip_all_tags( get_the_title( $st['why'] ) ) );
	}
	if ( 'everyone' !== $st['who'] && ! rrt_member_on() ) {
		$lines[] = __( 'Member feeds are off (Foxfire Studio, Podcast feed, Member feeds), so only editors can hear it for now.', 'foxfire-studio' );
	}
	if ( 'everyone' !== $c['who'] || 'everyone' !== $st['who'] ) {
		$lines[] = __( 'While it is for members, its files are kept where visitors cannot open them, and it stays out of the public podcast feed.', 'foxfire-studio' );
	}
	if ( 'everyone' !== $st['who'] ) {
		foreach ( array_keys( rrt_access_atts( $post->ID ) ) as $att ) {
			$sh = rrt_mfile_is( $att ) ? array() : rrt_access_sharers( $att, $post->ID );
			if ( $sh ) {
				/* translators: 1: a file name, 2: a post's title. */
				$lines[] = sprintf( __( 'Its file %1$s is also the reading of "%2$s", which is for everyone, so it stays at its public address. Give this post its own copy to keep it for members.', 'foxfire-studio' ), wp_basename( (string) get_attached_file( $att ) ), wp_strip_all_tags( get_the_title( $sh[0] ) ) );
			}
		}
	}
	foreach ( $lines as $l ) {
		echo '<p class="description">' . esc_html( $l ) . '</p>';
	}
}
add_action( 'rrt_reading_box_after', 'rrt_access_box_fields' );

/* A collection gets its own small box. */
add_action(
	'add_meta_boxes',
	function () {
		if ( defined( 'RRT_COL_TYPE' ) ) {
			add_meta_box( 'rrt-access', __( 'Who can hear this', 'foxfire-studio' ), 'rrt_access_box_fields', RRT_COL_TYPE, 'side' );
		}
	}
);

/* Saves the choice. */
add_action(
	'save_post',
	function ( $post_id ) {
		if ( ! isset( $_POST['rrt_access_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rrt_access_nonce'] ) ), 'rrt_access_box' ) ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		$who  = isset( $_POST['rrt_access'] ) ? sanitize_key( wp_unslash( $_POST['rrt_access'] ) ) : 'everyone';
		$days = isset( $_POST['rrt_access_days'] ) ? absint( $_POST['rrt_access_days'] ) : RRT_MEMBER_DAYS;
		rrt_access_save( $post_id, $who, $days ? $days : RRT_MEMBER_DAYS );
	},
	20
);

/*
 * ---------------------------------------------------------------------
 * Where a member finds their feed
 * ---------------------------------------------------------------------
 */

/** The box with a member's address, the app steps and a new address button. */
function rrt_mfeed_box( $uid, $reset_field = '' ) {
	$url = rrt_mfeed_url( $uid );
	if ( '' === $url ) {
		return '';
	}
	ob_start();
	?>
	<div class="rrt-mfeed">
		<p><input type="text" class="large-text code rrt-mfeed-url" readonly value="<?php echo esc_attr( $url ); ?>" onclick="this.select()" style="max-width:44em">
			<button type="button" class="button rrt-mfeed-copy" onclick="var i=this.parentNode.querySelector('input');i.select();if(navigator.clipboard){navigator.clipboard.writeText(i.value);}this.textContent=<?php echo esc_attr( wp_json_encode( __( 'Copied', 'foxfire-studio' ) ) ); ?>;"><?php esc_html_e( 'Copy', 'foxfire-studio' ); ?></button>
			<a class="button" href="<?php echo esc_url( 'overcast://x-callback-url/add?url=' . rawurlencode( $url ), array( 'overcast' ) ); ?>"><?php esc_html_e( 'Open in Overcast', 'foxfire-studio' ); ?></a></p>
		<ul class="ul-disc">
			<li><?php esc_html_e( 'Apple Podcasts on an iPhone or iPad: Library, then the ... menu, then Follow a Show by URL, and paste the address. On a Mac: File, then Follow a Show by URL.', 'foxfire-studio' ); ?></li>
			<li><?php esc_html_e( 'Pocket Casts: paste the address into the search box, then follow the show that appears.', 'foxfire-studio' ); ?></li>
			<li><?php esc_html_e( 'Most other podcast apps have an Add by URL or RSS choice. Spotify cannot take private feeds.', 'foxfire-studio' ); ?></li>
			<li><?php esc_html_e( 'The address is yours alone, so please keep it private. If it gets out, make a new one: the old one stops working and you add the new one to your app.', 'foxfire-studio' ); ?></li>
		</ul>
		<?php if ( '' !== $reset_field ) : ?>
			<?php echo $reset_field; // phpcs:ignore WordPress.Security.EscapeOutput -- built by the caller from escaped parts. ?>
		<?php endif; ?>
	</div>
	<?php
	return trim( ob_get_clean() );
}

/* On the profile screen. */
function rrt_mfeed_profile( $user ) {
	if ( ! rrt_member_on() ) {
		return;
	}
	$own   = get_current_user_id() === (int) $user->ID;
	$owner = current_user_can( 'manage_options' );
	if ( ! $own && ! $owner ) {
		return;
	}
	echo '<h2 id="rrt-mfeed">' . esc_html( $own ? __( 'Your members podcast feed', 'foxfire-studio' ) : __( 'Members podcast feed', 'foxfire-studio' ) ) . '</h2>';
	echo '<table class="form-table" role="presentation">';
	if ( $owner && ! user_can( $user, 'manage_options' ) ) {
		$pref = rrt_member_pref( $user->ID );
		echo '<tr><th scope="row"><label for="rrt_member">' . esc_html__( 'Members feed', 'foxfire-studio' ) . '</label></th><td><select id="rrt_member" name="rrt_member">';
		echo '<option value=""' . selected( '', $pref, false ) . '>' . esc_html__( 'Follow their role', 'foxfire-studio' ) . '</option>';
		echo '<option value="always"' . selected( 'always', $pref, false ) . '>' . esc_html__( 'Always', 'foxfire-studio' ) . '</option>';
		echo '<option value="never"' . selected( 'never', $pref, false ) . '>' . esc_html__( 'Never', 'foxfire-studio' ) . '</option>';
		echo '</select>';
		wp_nonce_field( 'rrt_member_pref_' . (int) $user->ID, 'rrt_member_nonce' );
		echo '<p class="description">' . esc_html( rrt_member_is( $user->ID ) ? __( 'They are a member now.', 'foxfire-studio' ) : __( 'They are not a member now.', 'foxfire-studio' ) ) . '</p></td></tr>';
	}
	echo '<tr><th scope="row">' . esc_html__( 'Feed address', 'foxfire-studio' ) . '</th><td>';
	if ( rrt_member_is( $user->ID ) ) {
		$reset = wp_nonce_field( 'rrt_mfeed_reset_' . (int) $user->ID, 'rrt_mfeed_nonce', true, false ) . '<p><label><input type="checkbox" name="rrt_mfeed_reset" value="1"> ' . esc_html__( 'Make a new address when I update the profile (the old one stops working)', 'foxfire-studio' ) . '</label></p>';
		echo rrt_mfeed_box( $user->ID, $reset ); // phpcs:ignore WordPress.Security.EscapeOutput
	} else {
		echo '<p>' . esc_html__( 'No members feed: this account is not a member.', 'foxfire-studio' ) . '</p>';
	}
	echo '</td></tr></table>';
}
add_action( 'show_user_profile', 'rrt_mfeed_profile' );
add_action( 'edit_user_profile', 'rrt_mfeed_profile' );

/* Saves the profile's choices. */
function rrt_mfeed_profile_save( $uid ) {
	$uid = (int) $uid;
	if ( current_user_can( 'manage_options' ) && isset( $_POST['rrt_member_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rrt_member_nonce'] ) ), 'rrt_member_pref_' . $uid ) ) {
		$v = isset( $_POST['rrt_member'] ) ? sanitize_key( wp_unslash( $_POST['rrt_member'] ) ) : '';
		if ( in_array( $v, array( 'always', 'never' ), true ) ) {
			update_user_meta( $uid, RRT_MEMBER_META, $v );
		} else {
			delete_user_meta( $uid, RRT_MEMBER_META );
		}
	}
	if ( ! empty( $_POST['rrt_mfeed_reset'] ) && isset( $_POST['rrt_mfeed_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rrt_mfeed_nonce'] ) ), 'rrt_mfeed_reset_' . $uid ) && ( get_current_user_id() === $uid || current_user_can( 'manage_options' ) ) ) {
		rrt_mfeed_reset( $uid );
	}
}
add_action( 'personal_options_update', 'rrt_mfeed_profile_save' );
add_action( 'edit_user_profile_update', 'rrt_mfeed_profile_save' );

/* The shortcode: the member's box, or the way in. */
add_shortcode(
	'foxfire_member_feed',
	function () {
		if ( ! rrt_member_on() ) {
			return '';
		}
		$join = rrt_member_join_url();
		$uid  = get_current_user_id();
		$out  = '<div class="rrt-mfeed-sc">';
		if ( $uid && rrt_member_is( $uid ) ) {
			$reset = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="rrt_mfeed_self_reset">' . wp_nonce_field( 'rrt_mfeed_self_reset', '_wpnonce', true, false ) . '<button type="submit" class="button">' . esc_html__( 'Make a new address', 'foxfire-studio' ) . '</button></form>';
			$out  .= rrt_mfeed_box( $uid, $reset );
		} elseif ( $uid ) {
			$out .= '<p>' . esc_html__( 'Your account has no members podcast feed.', 'foxfire-studio' ) . '</p>';
			if ( '' !== $join ) {
				$out .= '<p><a href="' . esc_url( $join ) . '">' . esc_html__( 'Become a member', 'foxfire-studio' ) . '</a></p>';
			}
		} else {
			$out .= '<p>' . esc_html__( 'Members can sign in to get their own podcast feed.', 'foxfire-studio' ) . '</p><p><a href="' . esc_url( wp_login_url( (string) get_permalink() ) ) . '">' . esc_html__( 'Sign in', 'foxfire-studio' ) . '</a>';
			if ( '' !== $join ) {
				$out .= ' &middot; <a href="' . esc_url( $join ) . '">' . esc_html__( 'Become a member', 'foxfire-studio' ) . '</a>';
			}
			$out .= '</p>';
		}
		return $out . '</div>';
	}
);

add_action(
	'admin_post_rrt_mfeed_self_reset',
	function () {
		check_admin_referer( 'rrt_mfeed_self_reset' );
		$uid = get_current_user_id();
		if ( $uid && rrt_member_is( $uid ) ) {
			rrt_mfeed_reset( $uid );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : home_url( '/' ) );
		exit;
	}
);

/*
 * ---------------------------------------------------------------------
 * For the owner: Podcast feed, Member feeds
 * ---------------------------------------------------------------------
 */

/** Everyone who is a member now, up to 500. */
function rrt_member_list() {
	if ( ! rrt_member_on() ) {
		return array();
	}
	$out = array();
	foreach ( get_users(
		array(
			'number'  => 500,
			'orderby' => 'display_name',
		)
	) as $u ) {
		if ( rrt_member_is( $u->ID ) ) {
			$out[] = $u;
		}
	}
	return $out;
}

/** Prints the settings rows, inside the Podcast feed form. */
function rrt_member_settings_rows() {
	$m       = rrt_member_settings();
	$plugins = rrt_member_plugins();
	?>
	<h2 id="rrt-members"><?php esc_html_e( 'Member feeds', 'foxfire-studio' ); ?></h2>
	<p><?php esc_html_e( 'A private feed for each member, with the readings for members alongside the rest. It works whether or not the feed above is published. Choose who can hear each reading in its Reading audio box: Everyone, Members first, or Members only.', 'foxfire-studio' ); ?></p>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Member feeds', 'foxfire-studio' ); ?></th>
			<td><label><input type="checkbox" name="m_on" value="1" <?php checked( $m['on'] ); ?>> <?php esc_html_e( 'Give members their own feed', 'foxfire-studio' ); ?></label></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Who is a member', 'foxfire-studio' ); ?></th>
			<td>
				<?php if ( $plugins ) : ?>
					<p><select name="m_via">
						<option value="roles" <?php selected( 'roles', $m['via'] ); ?>><?php esc_html_e( 'The roles ticked below', 'foxfire-studio' ); ?></option>
						<?php foreach ( $plugins as $k => $label ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $k, $m['via'] ); ?>><?php echo esc_html( sprintf( /* translators: %s: a membership plugin's name. */ __( 'Use %s\'s members', 'foxfire-studio' ), $label ) ); ?></option>
						<?php endforeach; ?>
					</select></p>
				<?php else : ?>
					<input type="hidden" name="m_via" value="roles">
				<?php endif; ?>
				<?php foreach ( wp_roles()->get_names() as $role => $name ) : ?>
					<?php
					if ( 'administrator' === $role ) {
						continue;
					}
					?>
					<label style="display:inline-block;margin-right:1em"><input type="checkbox" name="m_roles[]" value="<?php echo esc_attr( $role ); ?>" <?php checked( in_array( $role, $m['roles'], true ) ); ?>> <?php echo esc_html( translate_user_role( $name ) ); ?></label>
				<?php endforeach; ?>
				<p class="description"><?php esc_html_e( 'Administrators always have a feed, so you can check it. Each person\'s profile (Users, Edit) can also say Always or Never.', 'foxfire-studio' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="rrt-m-title"><?php esc_html_e( 'Members feed title', 'foxfire-studio' ); ?></label></th>
			<td><input class="regular-text" id="rrt-m-title" name="m_title" value="<?php echo esc_attr( $m['title'] ); ?>" placeholder="<?php echo esc_attr( sprintf( /* translators: %s: the show's title. */ __( '%s (members)', 'foxfire-studio' ), rrt_podcast_title() ) ); ?>"></td>
		</tr>
		<tr>
			<th scope="row"><label for="rrt-m-join"><?php esc_html_e( 'Become a member link', 'foxfire-studio' ); ?></label></th>
			<td><input class="large-text code" type="url" id="rrt-m-join" name="m_join" value="<?php echo esc_attr( $m['join'] ); ?>" placeholder="https://" style="max-width:40em">
			<p class="description"><?php esc_html_e( 'Optional: a page about becoming a member or supporting the show. Shown under a reading for members, and in a lapsed member\'s feed.', 'foxfire-studio' ); ?></p></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Feed activity', 'foxfire-studio' ); ?></th>
			<td><label><input type="checkbox" name="m_activity" value="1" <?php checked( $m['activity'] ); ?>> <?php esc_html_e( 'Watch for an address that may be shared', 'foxfire-studio' ); ?></label>
			<p class="description"><?php echo esc_html( sprintf( /* translators: %d: number of networks. */ __( 'Keeps, for each member, when their feed was last fetched, by which app, and how many different networks fetched it in the last 7 days. Networks are counted by a code that changes every day, so no IP address is kept, and it all goes after 30 days. An address fetched from more than %d networks in a week shows as Maybe shared. Before switching it on, add a line to your privacy policy (the paragraph at the bottom of Foxfire Studio, Settings includes one while it is on).', 'foxfire-studio' ), RRT_MEMBER_SHARED ) ); ?></p></td>
		</tr>
	</table>
	<?php
}

/** Prints the members table, under the form. */
function rrt_member_table() {
	$m = rrt_member_settings();
	if ( ! $m['on'] ) {
		return;
	}
	$list = rrt_member_list();
	echo '<h2>' . esc_html__( 'Members with a feed', 'foxfire-studio' ) . '</h2>';
	if ( isset( $_GET['mdone'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$d = sanitize_key( wp_unslash( $_GET['mdone'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$t = array(
			'reset' => __( 'A new address was made. The old one stops working.', 'foxfire-studio' ),
			'off'   => __( 'That person no longer has a members feed.', 'foxfire-studio' ),
			'sent'  => __( 'The email with their feed address was sent.', 'foxfire-studio' ),
			'fail'  => __( 'The email could not be sent. Check the site\'s mail setup.', 'foxfire-studio' ),
		);
		if ( isset( $t[ $d ] ) ) {
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( $t[ $d ] ) . '</p></div>';
		}
	}
	if ( ! $list ) {
		echo '<p>' . esc_html__( 'No one is a member yet.', 'foxfire-studio' ) . '</p>';
		return;
	}
	echo '<table class="widefat striped rrt-members" style="max-width:70em"><thead><tr><th>' . esc_html__( 'Member', 'foxfire-studio' ) . '</th><th>' . esc_html__( 'Role', 'foxfire-studio' ) . '</th><th>' . esc_html__( 'Address made', 'foxfire-studio' ) . '</th>';
	if ( $m['activity'] ) {
		echo '<th>' . esc_html__( 'Last fetched', 'foxfire-studio' ) . '</th>';
	}
	echo '<th></th></tr></thead><tbody>';
	$roles = wp_roles()->get_names();
	foreach ( $list as $u ) {
		$furl = rrt_mfeed_url( $u->ID );
		$at   = (int) get_user_meta( $u->ID, RRT_MFEED_AT_META, true );
		$rn   = array();
		foreach ( (array) $u->roles as $r ) {
			$rn[] = isset( $roles[ $r ] ) ? translate_user_role( $roles[ $r ] ) : $r;
		}
		echo '<tr><td><a href="' . esc_url( get_edit_user_link( $u->ID ) . '#rrt-mfeed' ) . '">' . esc_html( $u->display_name ) . '</a>';
		if ( 'always' === rrt_member_pref( $u->ID ) ) {
			echo ' <span class="description">(' . esc_html__( 'Always', 'foxfire-studio' ) . ')</span>';
		}
		if ( '' !== $furl ) {
			echo '<br><button type="button" class="button-link rrt-mfeed-copy-row" data-url="' . esc_attr( $furl ) . '" onclick="var b=this;if(navigator.clipboard){navigator.clipboard.writeText(b.getAttribute(\'data-url\'));}b.textContent=' . esc_attr( wp_json_encode( __( 'Copied', 'foxfire-studio' ) ) ) . ';">' . esc_html__( 'Copy their feed address', 'foxfire-studio' ) . '</button>';
		}
		echo '</td><td>' . esc_html( implode( ', ', $rn ) ) . '</td><td>' . esc_html( $at ? wp_date( get_option( 'date_format' ), $at ) : '' ) . '</td>';
		if ( $m['activity'] ) {
			$a = rrt_mfeed_activity( $u->ID );
			echo '<td>';
			if ( $a ) {
				echo esc_html( sprintf( /* translators: 1: date and time, 2: app name, 3: number of networks. */ _n( '%1$s, %2$s, %3$d network this week', '%1$s, %2$s, %3$d networks this week', $a['nets'], 'foxfire-studio' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $a['at'] ), $a['app'], $a['nets'] ) );
				if ( $a['shared'] ) {
					echo ' <strong class="rrt-maybe-shared">' . esc_html__( 'Maybe shared', 'foxfire-studio' ) . '</strong>';
				}
			} else {
				esc_html_e( 'Not fetched yet', 'foxfire-studio' );
			}
			echo '</td>';
		}
		echo '<td>';
		foreach ( array(
			'send'  => __( 'Email them their feed address', 'foxfire-studio' ),
			'reset' => __( 'Reset', 'foxfire-studio' ),
			'off'   => __( 'Turn off', 'foxfire-studio' ),
		) as $do => $label ) {
			if ( 'off' === $do && user_can( $u, 'manage_options' ) ) {
				continue;
			}
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 .4em .2em 0"><input type="hidden" name="action" value="rrt_member_do"><input type="hidden" name="do" value="' . esc_attr( $do ) . '"><input type="hidden" name="user" value="' . (int) $u->ID . '">';
			wp_nonce_field( 'rrt_member_do_' . (int) $u->ID );
			echo '<button type="submit" class="button button-small">' . esc_html( $label ) . '</button></form>';
		}
		echo '</td></tr>';
	}
	echo '</tbody></table>';
	echo '<p class="description">' . esc_html__( 'Members find their address on their profile (Users, Profile, under Your members podcast feed), or on any page where you place the shortcode [foxfire_member_feed]. Each address is private to its member: copy one here only to send it to them. Turn off sets that person to Never; their profile can change it back.', 'foxfire-studio' ) . '</p>';
}

/** Emails a member their address. */
function rrt_mfeed_send( $uid ) {
	$u   = get_userdata( $uid );
	$url = $u ? rrt_mfeed_url( $uid ) : '';
	if ( ! $u || '' === $url || ! is_email( $u->user_email ) ) {
		return false;
	}
	$show = rrt_podcast_title();
	/* translators: %s: the show's title. */
	$subject = sprintf( __( 'Your members podcast feed for %s', 'foxfire-studio' ), $show );
	$body    = sprintf(
		/* translators: 1: the person's name, 2: the show's title, 3: the feed address. */
		__( "Hello %1\$s,\n\nHere is your own podcast feed for %2\$s, with the readings for members:\n\n%3\$s\n\nIn Apple Podcasts on an iPhone or iPad: Library, then the ... menu, then Follow a Show by URL, and paste the address. In Pocket Casts: paste it into the search box. Most other podcast apps have an Add by URL choice. Spotify cannot take private feeds.\n\nThe address is yours alone, so please keep it private. You can make a new one at any time on your profile on the site.", 'foxfire-studio' ),
		$u->display_name,
		$show,
		$url
	);
	return (bool) wp_mail( $u->user_email, $subject, $body );
}

add_action(
	'admin_post_rrt_member_do',
	function () {
		$uid = isset( $_POST['user'] ) ? absint( $_POST['user'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is checked on the next lines, after the post number it names is read.
		check_admin_referer( 'rrt_member_do_' . $uid );
		if ( ! current_user_can( 'manage_options' ) || ! get_userdata( $uid ) ) {
			wp_die( esc_html__( 'Not allowed.', 'foxfire-studio' ) );
		}
		$do   = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$done = '';
		if ( 'reset' === $do && rrt_member_is( $uid ) ) {
			rrt_mfeed_reset( $uid );
			$done = 'reset';
		} elseif ( 'off' === $do && ! user_can( $uid, 'manage_options' ) ) {
			update_user_meta( $uid, RRT_MEMBER_META, 'never' );
			rrt_mfeed_forget( $uid );
			$done = 'off';
		} elseif ( 'send' === $do ) {
			$done = rrt_mfeed_send( $uid ) ? 'sent' : 'fail';
		}
		wp_safe_redirect( admin_url( 'admin.php?page=rrt-podcast&mdone=' . $done . '#rrt-members' ) );
		exit;
	}
);

/** Reads the member settings from the Podcast feed form into the settings array. */
function rrt_member_settings_from_post( $s ) {
	// phpcs:disable WordPress.Security.NonceVerification -- checked by the Podcast feed form's handler.
	$s['m_on']  = ! empty( $_POST['m_on'] );
	$roles      = isset( $_POST['m_roles'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['m_roles'] ) ) : array();
	$all        = array_keys( wp_roles()->get_names() );
	$s['m_roles'] = array_values( array_intersect( $roles, $all ) );
	$via        = isset( $_POST['m_via'] ) ? sanitize_key( wp_unslash( $_POST['m_via'] ) ) : 'roles';
	$s['m_via'] = 'roles' === $via || isset( rrt_member_plugins()[ $via ] ) ? $via : 'roles';
	$s['m_title'] = isset( $_POST['m_title'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['m_title'] ) ), 0, 200 ) : '';
	$s['m_join']  = isset( $_POST['m_join'] ) ? esc_url_raw( trim( wp_unslash( $_POST['m_join'] ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned on this line (esc_url_raw, or each line checked below).
	$s['m_activity'] = ! empty( $_POST['m_activity'] );
	// phpcs:enable
	return $s;
}
