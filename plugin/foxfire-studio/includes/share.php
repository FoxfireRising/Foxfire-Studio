<?php
/**
 * Share a take for feedback (0.17.0).
 *
 * From the review studio, a reader shares one take with people who already
 * have an account on the site (a friend is usually added as a Subscriber).
 * The take's audio is uploaded to a folder that visitors cannot open, and
 * reaches the friend only through a REST route that checks who is asking.
 * The friend signs in, hears the take with the script lighting up, and
 * leaves comments pinned to a moment or a word. The reader gets one email
 * per visit, when the friend presses Done (or an hour after their last
 * comment, if they never do).
 *
 * A share lasts 14 days or until the reader ends it. Then the audio is
 * deleted and the friend loses access; the comments stay for the reader.
 *
 * Tables: rrt_shares and rrt_share_notes (database version 6).
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** How long a share lasts, in days. */
define( 'RRT_SHARE_DAYS', 14 );

/** The most comments one person can leave on one share. */
define( 'RRT_SHARE_MAX_NOTES', 200 );

/** The longest comment, in characters. */
define( 'RRT_SHARE_MAX_TEXT', 2000 );

/** Creates or updates the two tables. Called from rrt_stats_install(). */
function rrt_share_install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$c      = $wpdb->get_charset_collate();
	$shares = rrt_table( 'shares' );
	$notes  = rrt_table( 'share_notes' );
	dbDelta(
		"CREATE TABLE $shares (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		token varchar(40) NOT NULL,
		owner_id bigint(20) unsigned NOT NULL,
		post_id bigint(20) unsigned NOT NULL,
		take_at bigint(20) unsigned DEFAULT NULL,
		title varchar(255) NOT NULL DEFAULT '',
		created_at datetime NOT NULL,
		ends_at datetime NOT NULL,
		ended_at datetime DEFAULT NULL,
		audio_file varchar(100) NOT NULL DEFAULT '',
		audio_type varchar(40) NOT NULL DEFAULT '',
		duration_s float DEFAULT NULL,
		viewers text,
		words longtext,
		PRIMARY KEY  (id),
		UNIQUE KEY token (token),
		KEY owner_id (owner_id),
		KEY ends_at (ends_at)
		) $c;"
	);
	dbDelta(
		"CREATE TABLE $notes (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		share_id bigint(20) unsigned NOT NULL,
		user_id bigint(20) unsigned NOT NULL,
		name varchar(100) NOT NULL DEFAULT '',
		at_s float DEFAULT NULL,
		word int(10) DEFAULT NULL,
		body text,
		created_at datetime NOT NULL,
		notified tinyint(1) NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		KEY share_id (share_id),
		KEY user_id (user_id)
		) $c;"
	);
}

/*
 * ---------------------------------------------------------------------
 * The audio folder
 * ---------------------------------------------------------------------
 */

/** The folder that holds shared takes' audio, as array( dir, url ). Visitors are refused there. */
function rrt_share_dir() {
	$up  = wp_upload_dir( null, false );
	$dir = trailingslashit( $up['basedir'] ) . 'rrt-shares/';
	return $dir;
}

/** Makes the folder, with files that refuse every direct request. */
function rrt_share_dir_ready() {
	$dir = rrt_share_dir();
	if ( ! wp_mkdir_p( $dir ) ) {
		return false;
	}
	if ( ! file_exists( $dir . '.htaccess' ) ) {
		/* Apache 2.4 and LiteSpeed read the first; older Apache the second. */
		file_put_contents( $dir . '.htaccess', "# Foxfire Studio: shared takes are served only to the people they were shared with.\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
	if ( ! file_exists( $dir . 'index.php' ) ) {
		file_put_contents( $dir . 'index.php', "<?php\n// Silence.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
	return true;
}

/** The audio types a share accepts, by the first bytes of the file. */
function rrt_share_sniff( $path ) {
	$h = (string) file_get_contents( $path, false, null, 0, 12 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( strlen( $h ) < 12 ) {
		return null;
	}
	if ( 'ID3' === substr( $h, 0, 3 ) || ( 0xFF === ord( $h[0] ) && 0xE0 === ( ord( $h[1] ) & 0xE0 ) ) ) {
		return array( 'mp3', 'audio/mpeg' );
	}
	if ( 'RIFF' === substr( $h, 0, 4 ) && 'WAVE' === substr( $h, 8, 4 ) ) {
		return array( 'wav', 'audio/wav' );
	}
	if ( "\x1A\x45\xDF\xA3" === substr( $h, 0, 4 ) ) {
		return array( 'webm', 'audio/webm' );
	}
	if ( 'OggS' === substr( $h, 0, 4 ) ) {
		return array( 'ogg', 'audio/ogg' );
	}
	if ( 'ftyp' === substr( $h, 4, 4 ) ) {
		return array( 'm4a', 'audio/mp4' );
	}
	return null;
}

/** Deletes a share's audio file, if it is still there. */
function rrt_share_drop_audio( $share ) {
	if ( ! empty( $share->audio_file ) ) {
		$path = rrt_share_dir() . basename( $share->audio_file );
		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->update( rrt_table( 'shares' ), array( 'audio_file' => '' ), array( 'id' => (int) $share->id ) );
}

/*
 * ---------------------------------------------------------------------
 * Reading shares
 * ---------------------------------------------------------------------
 */

/** A share by its token, or null. */
function rrt_share_get( $token ) {
	global $wpdb;
	$token = preg_replace( '/[^a-zA-Z0-9]/', '', (string) $token );
	if ( '' === $token ) {
		return null;
	}
	$t = rrt_table( 'shares' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$s = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE token = %s", $token ) );
	return $s ? $s : null;
}

/** The user ids a share was shared with. */
function rrt_share_viewers( $share ) {
	$v = json_decode( (string) $share->viewers, true );
	return is_array( $v ) ? array_values( array_unique( array_map( 'intval', $v ) ) ) : array();
}

/** Whether a share is still open: not ended and not past its end date. */
function rrt_share_open( $share ) {
	return empty( $share->ended_at ) && strtotime( $share->ends_at . ' UTC' ) > time();
}

/**
 * What the current user may do with a share: 'owner', 'friend' (shared
 * with them and still open) or '' (nothing). Administrators see every share
 * as its owner would, so a share is never stranded.
 */
function rrt_share_role( $share ) {
	if ( ! $share || ! is_user_logged_in() ) {
		return '';
	}
	$me = get_current_user_id();
	if ( (int) $share->owner_id === $me || current_user_can( 'manage_options' ) ) {
		return 'owner';
	}
	if ( in_array( $me, rrt_share_viewers( $share ), true ) && rrt_share_open( $share ) ) {
		return 'friend';
	}
	return '';
}

/** The page where a share is heard and commented on. */
function rrt_share_url( $token = '' ) {
	return add_query_arg( array( 'rrt_share' => '' === $token ? 'list' : $token ), home_url( '/' ) );
}

/** A comment as the page shows it. */
function rrt_share_note_out( $n ) {
	return array(
		'id'   => (int) $n->id,
		'user' => (int) $n->user_id,
		'name' => (string) $n->name,
		'at'   => null === $n->at_s ? null : round( (float) $n->at_s, 2 ),
		'word' => null === $n->word ? null : (int) $n->word,
		'text' => (string) $n->body,
		'when' => (string) $n->created_at,
	);
}

/** Comments on a share: every comment for the owner, only their own for a friend. */
function rrt_share_notes( $share, $role ) {
	global $wpdb;
	$t = rrt_table( 'share_notes' );
	if ( 'owner' === $role ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE share_id = %d ORDER BY at_s, id", $share->id ) );
	} else {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE share_id = %d AND user_id = %d ORDER BY at_s, id", $share->id, get_current_user_id() ) );
	}
	return array_map( 'rrt_share_note_out', $rows ? $rows : array() );
}

/** Cleans the script and word timings sent with a share. */
function rrt_share_clean_words( $in ) {
	$out = array();
	if ( ! is_array( $in ) ) {
		return $out;
	}
	foreach ( array_slice( $in, 0, 20000 ) as $w ) {
		if ( ! is_array( $w ) ) {
			continue;
		}
		$s     = isset( $w['s'] ) && is_numeric( $w['s'] ) ? round( (float) $w['s'], 2 ) : null;
		$e     = isset( $w['e'] ) && is_numeric( $w['e'] ) ? round( (float) $w['e'], 2 ) : null;
		$out[] = array(
			't' => mb_substr( sanitize_text_field( isset( $w['t'] ) ? (string) $w['t'] : '' ), 0, 60 ),
			'b' => isset( $w['b'] ) ? max( 0, (int) $w['b'] ) : 0,
			'k' => isset( $w['k'] ) && in_array( $w['k'], array( 'title', 'h', 'p' ), true ) ? $w['k'] : 'p',
			's' => $s,
			'e' => $e,
		);
	}
	return $out;
}

/** The most invitation emails one person can send in a day (1.25.0). */
define( 'RRT_SHARE_INVITES_DAY', 20 );

/** Whether the current person may share with anyone on the site (1.25.0: Editors and Administrators). */
function rrt_share_with_anyone() {
	return current_user_can( (string) apply_filters( 'rrt_share_anyone_capability', 'edit_others_posts' ) );
}

/**
 * The people the current person may share with (1.25.0): everyone, for
 * Editors and Administrators; otherwise the site's Editors and
 * Administrators and the people who have shared a take with them, so an
 * Author or Contributor never sees the whole list of accounts.
 *
 * @return int[] User ids, or null for everyone.
 */
function rrt_share_allowed_ids() {
	if ( rrt_share_with_anyone() ) {
		return null;
	}
	global $wpdb;
	$me  = get_current_user_id();
	$ids = get_users(
		array(
			'capability' => (string) apply_filters( 'rrt_share_anyone_capability', 'edit_others_posts' ),
			'fields'     => 'ID',
			'number'     => 300,
		)
	);
	$t    = rrt_table( 'shares' );
	$like = '%' . $wpdb->esc_like( (string) $me ) . '%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is the plugin's own.
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT owner_id, viewers FROM $t WHERE viewers LIKE %s LIMIT 500", $like ) ) as $r ) {
		if ( in_array( $me, rrt_share_viewers( $r ), true ) ) {
			$ids[] = (int) $r->owner_id;
		}
	}
	return array_values( array_diff( array_unique( array_map( 'intval', $ids ) ), array( $me ) ) );
}

/** People a share can go to, by name: everyone but the one sharing, or (1.25.0) the narrower list above. */
function rrt_share_people() {
	$allowed = rrt_share_allowed_ids();
	if ( is_array( $allowed ) && ! $allowed ) {
		return array();
	}
	$args = array(
		'exclude' => array( get_current_user_id() ),
		'orderby' => 'display_name',
		'number'  => 300,
		'fields'  => array( 'ID', 'display_name', 'user_login' ),
	);
	if ( is_array( $allowed ) ) {
		$args['include'] = $allowed;
	}
	$users = get_users( $args );
	$out = array();
	foreach ( $users as $u ) {
		$wp    = get_userdata( $u->ID );
		$out[] = array(
			'id'   => (int) $u->ID,
			'name' => $u->display_name ? $u->display_name : $u->user_login,
			'role' => $wp && $wp->roles ? translate_user_role( ucfirst( (string) reset( $wp->roles ) ) ) : '',
		);
	}
	return $out;
}

/*
 * ---------------------------------------------------------------------
 * Emails
 * ---------------------------------------------------------------------
 */

/** Seconds as m:ss. */
function rrt_share_time( $s ) {
	$s = max( 0, (int) round( (float) $s ) );
	return floor( $s / 60 ) . ':' . str_pad( (string) ( $s % 60 ), 2, '0', STR_PAD_LEFT );
}

/**
 * Emails the owner about one person's comments that have not been sent yet,
 * then marks them sent. Returns the number of comments in the email.
 */
function rrt_share_notify( $share, $user_id ) {
	global $wpdb;
	$t = rrt_table( 'share_notes' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE share_id = %d AND user_id = %d AND notified = 0 ORDER BY at_s, id", $share->id, $user_id ) );
	if ( ! $rows ) {
		return 0;
	}
	$owner = get_userdata( (int) $share->owner_id );
	if ( ! $owner || ! $owner->user_email ) {
		return 0;
	}
	$name    = $rows[0]->name ? $rows[0]->name : __( 'Someone', 'foxfire-studio' );
	$count   = count( $rows );
	$subject = sprintf( /* translators: 1: $name, 2: $count, 3: 1 === $count ? 'comment' : 'comments', 4: $share->title. */ __( '%1$s left %2$d %3$s on your take of %4$s', 'foxfire-studio' ), $name, $count, 1 === $count ? 'comment' : 'comments', $share->title );
	$lines   = array( $subject . '.', '' );
	foreach ( $rows as $r ) {
		$lines[] = ( null === $r->at_s ? '' : rrt_share_time( $r->at_s ) . '  ' ) . wp_strip_all_tags( $r->body );
	}
	$lines[] = '';
	$lines[] = sprintf( /* translators: %s: link to the shared take. */ __( 'Hear each one in place: %s', 'foxfire-studio' ), rrt_share_url( $share->token ) );
	$sent    = wp_mail( $owner->user_email, $subject, implode( "\n", $lines ) );
	if ( $sent ) {
		$ids = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- only the plugin's own table name and whole numbers.
		$wpdb->query( "UPDATE $t SET notified = 1 WHERE id IN (" . implode( ',', $ids ) . ')' );
	}
	return $sent ? $count : 0;
}

/** Tells the people a take was shared with, when the reader asks for it. */
function rrt_share_invite( $share, $user_ids ) {
	$owner = get_userdata( (int) $share->owner_id );
	$who   = $owner ? $owner->display_name : __( 'Someone', 'foxfire-studio' );
	$sent  = 0;
	/* 1.25.0: at most RRT_SHARE_INVITES_DAY invitations a day from one person. */
	$key   = 'rrt_shinv_' . (int) $share->owner_id;
	$today = (int) get_transient( $key );
	foreach ( $user_ids as $id ) {
		$u = get_userdata( (int) $id );
		if ( ! $u || ! $u->user_email ) {
			continue;
		}
		if ( $today >= RRT_SHARE_INVITES_DAY ) {
			break;
		}
		++$today;
		set_transient( $key, $today, DAY_IN_SECONDS );
		$body = implode(
			"\n",
			array(
				sprintf( /* translators: 1: $who, 2: $share->title. */ __( '%1$s would like your feedback on a reading of %2$s.', 'foxfire-studio' ), $who, $share->title ),
				'',
				__( 'Listen, follow the script, and leave a comment at any moment or word:', 'foxfire-studio' ),
				rrt_share_url( $share->token ),
				'',
				sprintf( /* translators: 1: wp_parse_url( home_url(), PHP_URL_HOST ), 2: wp_date( 'F j', strtotime( $share->ends_at . ' UTC' ) ). */ __( 'Sign in with your account on %1$s. The link works until %2$s.', 'foxfire-studio' ), wp_parse_url( home_url(), PHP_URL_HOST ), wp_date( 'F j', strtotime( $share->ends_at . ' UTC' ) ) ),
			)
		);
		if ( wp_mail( $u->user_email, sprintf( /* translators: 1: $who, 2: $share->title. */ __( '%1$s shared a reading with you: %2$s', 'foxfire-studio' ), $who, $share->title ), $body ) ) {
			$sent++;
		}
	}
	return $sent;
}

/*
 * ---------------------------------------------------------------------
 * The hourly job: ending shares and late emails
 * ---------------------------------------------------------------------
 */

add_action(
	'init',
	function () {
		if ( ! wp_next_scheduled( 'rrt_share_hourly' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'rrt_share_hourly' );
		}
	}
);

/** Ends shares past their date (deleting the audio) and sends emails for comments left an hour ago without Done. */
function rrt_share_hourly() {
	global $wpdb;
	$s = rrt_table( 'shares' );
	$n = rrt_table( 'share_notes' );
	$now = gmdate( 'Y-m-d H:i:s' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$old = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $s WHERE audio_file <> '' AND ( ended_at IS NOT NULL OR ends_at <= %s )", $now ) );
	foreach ( (array) $old as $share ) {
		rrt_share_drop_audio( $share );
	}
	$hour = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$waiting = $wpdb->get_results( $wpdb->prepare( "SELECT share_id, user_id, MAX(created_at) AS last FROM $n WHERE notified = 0 GROUP BY share_id, user_id HAVING last <= %s", $hour ) );
	foreach ( (array) $waiting as $w ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$share = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $s WHERE id = %d", $w->share_id ) );
		if ( $share ) {
			rrt_share_notify( $share, (int) $w->user_id );
		}
	}
}
add_action( 'rrt_share_hourly', 'rrt_share_hourly' );

/** Ends a share now: no more listening or comments, and the audio is deleted. */
function rrt_share_end( $share ) {
	global $wpdb;
	if ( empty( $share->ended_at ) ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( rrt_table( 'shares' ), array( 'ended_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => (int) $share->id ) );
	}
	rrt_share_drop_audio( $share );
}

/*
 * ---------------------------------------------------------------------
 * REST routes
 * ---------------------------------------------------------------------
 */

add_action(
	'rest_api_init',
	function () {
		$editor = function ( $req ) {
			$p = (int) $req['post'];
			return is_user_logged_in() && current_user_can( 'edit_posts' ) && ( $p <= 0 || ( get_post( $p ) && current_user_can( 'edit_post', $p ) ) );
		};
		/* Who a take can be shared with. */
		register_rest_route(
			'rrt/v1',
			'/share-people',
			array(
				'methods'             => 'GET',
				'permission_callback' => $editor,
				'callback'            => function () {
					return rest_ensure_response( array( 'people' => rrt_share_people() ) );
				},
			)
		);
		/* Start a share: the audio, the script with its timings, and who it is for. */
		register_rest_route(
			'rrt/v1',
			'/shares',
			array(
				'methods'             => 'POST',
				'permission_callback' => $editor,
				'callback'            => 'rrt_rest_share_create',
			)
		);
		$token = '(?P<token>[a-zA-Z0-9]{20,40})';
		$may   = function ( $req ) {
			return '' !== rrt_share_role( rrt_share_get( $req['token'] ) );
		};
		register_rest_route(
			'rrt/v1',
			'/shares/' . $token . '/audio',
			array(
				'methods'             => 'GET',
				'permission_callback' => $may,
				'callback'            => 'rrt_rest_share_audio',
			)
		);
		register_rest_route(
			'rrt/v1',
			'/shares/' . $token . '/notes',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => $may,
					'callback'            => function ( $req ) {
						$s = rrt_share_get( $req['token'] );
						return rest_ensure_response( array( 'notes' => rrt_share_notes( $s, rrt_share_role( $s ) ) ) );
					},
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => function ( $req ) {
						return 'friend' === rrt_share_role( rrt_share_get( $req['token'] ) );
					},
					'callback'            => 'rrt_rest_share_note_add',
				),
			)
		);
		register_rest_route(
			'rrt/v1',
			'/shares/' . $token . '/notes/(?P<id>\d+)',
			array(
				'methods'             => 'DELETE',
				'permission_callback' => function ( $req ) {
					return 'friend' === rrt_share_role( rrt_share_get( $req['token'] ) );
				},
				'callback'            => function ( $req ) {
					global $wpdb;
					$s = rrt_share_get( $req['token'] );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$n = $wpdb->delete(
						rrt_table( 'share_notes' ),
						array(
							'id'       => (int) $req['id'],
							'share_id' => (int) $s->id,
							'user_id'  => get_current_user_id(),
							'notified' => 0,
						)
					);
					if ( ! $n ) {
						return new WP_Error( 'rrt_share', __( 'That comment was already sent, so it cannot be removed.', 'foxfire-studio' ), array( 'status' => 409 ) );
					}
					return rest_ensure_response( array( 'deleted' => true ) );
				},
			)
		);
		register_rest_route(
			'rrt/v1',
			'/shares/' . $token . '/done',
			array(
				'methods'             => 'POST',
				'permission_callback' => function ( $req ) {
					return 'friend' === rrt_share_role( rrt_share_get( $req['token'] ) );
				},
				'callback'            => function ( $req ) {
					$s = rrt_share_get( $req['token'] );
					return rest_ensure_response( array( 'sent' => rrt_share_notify( $s, get_current_user_id() ) ) );
				},
			)
		);
		register_rest_route(
			'rrt/v1',
			'/shares/' . $token . '/end',
			array(
				'methods'             => 'POST',
				'permission_callback' => function ( $req ) {
					return 'owner' === rrt_share_role( rrt_share_get( $req['token'] ) );
				},
				'callback'            => function ( $req ) {
					rrt_share_end( rrt_share_get( $req['token'] ) );
					return rest_ensure_response( array( 'ended' => true ) );
				},
			)
		);
	}
);

/** Starts a share. */
function rrt_rest_share_create( $req ) {
	global $wpdb;
	$post_id = (int) $req['post'];
	if ( $post_id <= 0 || ! get_post( $post_id ) ) {
		return new WP_Error( 'rrt_share', __( 'That post was not found.', 'foxfire-studio' ), array( 'status' => 400 ) );
	}
	$viewers = array_filter( array_map( 'intval', (array) json_decode( (string) $req['viewers'], true ) ) );
	$viewers = array_values(
		array_filter(
			array_unique( $viewers ),
			function ( $id ) {
				return $id !== get_current_user_id() && get_userdata( $id );
			}
		)
	);
	/* 1.25.0: only people this person may share with. */
	$allowed = rrt_share_allowed_ids();
	if ( is_array( $allowed ) ) {
		$viewers = array_values( array_intersect( $viewers, $allowed ) );
	}
	if ( ! $viewers ) {
		return new WP_Error( 'rrt_share', __( 'Choose at least one person to share with.', 'foxfire-studio' ), array( 'status' => 400 ) );
	}
	$files = $req->get_file_params();
	if ( empty( $files['audio'] ) || ! empty( $files['audio']['error'] ) ) {
		return new WP_Error( 'rrt_share', sprintf( /* translators: %s: largest upload size. */ __( 'The audio did not arrive. It may be larger than this site accepts (%s).', 'foxfire-studio' ), size_format( wp_max_upload_size() ) ), array( 'status' => 400 ) );
	}
	$kind = rrt_share_sniff( $files['audio']['tmp_name'] );
	if ( ! $kind ) {
		return new WP_Error( 'rrt_share', __( 'That audio is not a type a share can hold.', 'foxfire-studio' ), array( 'status' => 400 ) );
	}
	if ( ! rrt_share_dir_ready() ) {
		return new WP_Error( 'rrt_share', __( 'The folder for shared takes could not be made. Check that the uploads folder is writable.', 'foxfire-studio' ), array( 'status' => 500 ) );
	}
	$token = wp_generate_password( 32, false, false );
	$name  = $token . '.' . $kind[0];
	if ( ! @move_uploaded_file( $files['audio']['tmp_name'], rrt_share_dir() . $name ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
		/* Tests and some hosts hand over a file that is not an HTTP upload. */
		if ( ! @rename( $files['audio']['tmp_name'], rrt_share_dir() . $name ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			return new WP_Error( 'rrt_share', __( 'The audio could not be saved.', 'foxfire-studio' ), array( 'status' => 500 ) );
		}
	}
	$words = rrt_share_clean_words( json_decode( (string) $req['words'], true ) );
	$now   = time();
	$row   = array(
		'token'      => $token,
		'owner_id'   => get_current_user_id(),
		'post_id'    => $post_id,
		'take_at'    => $req['take_at'] ? (int) $req['take_at'] : null,
		'title'      => mb_substr( wp_strip_all_tags( get_the_title( $post_id ) ), 0, 250 ),
		'created_at' => gmdate( 'Y-m-d H:i:s', $now ),
		'ends_at'    => gmdate( 'Y-m-d H:i:s', $now + RRT_SHARE_DAYS * DAY_IN_SECONDS ),
		'audio_file' => $name,
		'audio_type' => $kind[1],
		'duration_s' => is_numeric( $req['duration'] ) ? (float) $req['duration'] : null,
		'viewers'    => wp_json_encode( $viewers ),
		'words'      => wp_json_encode( $words ),
	);
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( ! $wpdb->insert( rrt_table( 'shares' ), $row ) ) {
		wp_delete_file( rrt_share_dir() . $name );
		return new WP_Error( 'rrt_share', __( 'The share could not be saved.', 'foxfire-studio' ), array( 'status' => 500 ) );
	}
	$share   = rrt_share_get( $token );
	$invited = $req['invite'] ? rrt_share_invite( $share, $viewers ) : 0;
	return rest_ensure_response(
		array(
			'token'   => $token,
			'url'     => rrt_share_url( $token ),
			'ends'    => wp_date( 'l, F j', $now + RRT_SHARE_DAYS * DAY_IN_SECONDS ),
			'invited' => $invited,
		)
	);
}

/** Sends a share's audio to someone allowed to hear it. Nothing is cached. */
function rrt_rest_share_audio( $req ) {
	$s = rrt_share_get( $req['token'] );
	if ( ! $s->audio_file || ! file_exists( rrt_share_dir() . $s->audio_file ) ) {
		return new WP_Error( 'rrt_share', __( 'This take has ended, so its audio is no longer on the site.', 'foxfire-studio' ), array( 'status' => 410 ) );
	}
	$path = rrt_share_dir() . $s->audio_file;
	nocache_headers();
	header( 'Content-Type: ' . $s->audio_type );
	header( 'Content-Length: ' . filesize( $path ) );
	header( 'Cache-Control: no-store, private' );
	header( 'X-Content-Type-Options: nosniff' );
	readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit;
}

/** Adds a friend's comment. */
function rrt_rest_share_note_add( $req ) {
	global $wpdb;
	$s    = rrt_share_get( $req['token'] );
	$me   = get_current_user_id();
	$text = trim( sanitize_textarea_field( (string) $req['text'] ) );
	if ( '' === $text ) {
		return new WP_Error( 'rrt_share', __( 'Write something first.', 'foxfire-studio' ), array( 'status' => 400 ) );
	}
	$t = rrt_table( 'share_notes' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$have = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE share_id = %d AND user_id = %d", $s->id, $me ) );
	if ( $have >= RRT_SHARE_MAX_NOTES ) {
		return new WP_Error( 'rrt_share', __( 'That is the most comments one person can leave on a take.', 'foxfire-studio' ), array( 'status' => 429 ) );
	}
	/* No more than 20 a minute, so a stuck key or a script cannot flood the reader. */
	$key = 'rrt_shn_' . $me;
	$n   = (int) get_transient( $key );
	if ( $n >= 20 ) {
		return new WP_Error( 'rrt_share', __( 'That is a lot of comments at once. Wait a minute, then carry on.', 'foxfire-studio' ), array( 'status' => 429 ) );
	}
	set_transient( $key, $n + 1, MINUTE_IN_SECONDS );
	$user = wp_get_current_user();
	$name = trim( sanitize_text_field( (string) $req['name'] ) );
	$name = mb_substr( '' !== $name ? $name : $user->display_name, 0, 100 );
	$row  = array(
		'share_id'   => (int) $s->id,
		'user_id'    => $me,
		'name'       => $name,
		'at_s'       => is_numeric( $req['at'] ) ? max( 0, round( (float) $req['at'], 2 ) ) : null,
		'word'       => is_numeric( $req['word'] ) ? max( 0, (int) $req['word'] ) : null,
		'body'       => mb_substr( $text, 0, RRT_SHARE_MAX_TEXT ),
		'created_at' => gmdate( 'Y-m-d H:i:s' ),
		'notified'   => 0,
	);
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->insert( $t, $row );
	$row['id']      = $wpdb->insert_id;
	$row['user_id'] = $me;
	return rest_ensure_response( array( 'note' => rrt_share_note_out( (object) $row ) ) );
}

/*
 * ---------------------------------------------------------------------
 * The page a share is heard on
 * ---------------------------------------------------------------------
 */

add_action(
	'template_redirect',
	function () {
		if ( ! isset( $_GET['rrt_share'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- read-only page switch.
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		do_action( 'litespeed_control_set_nocache', __( 'shared takes are private', 'foxfire-studio' ) );
		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}
		$token = sanitize_text_field( wp_unslash( $_GET['rrt_share'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		rrt_share_render( 'list' === $token ? null : rrt_share_get( $token ), 'list' === $token );
		exit;
	},
	0
);

/** Shares the current user can open, newest first, for the list page. */
function rrt_share_mine() {
	global $wpdb;
	$t    = rrt_table( 'shares' );
	$me   = get_current_user_id();
	$like = '%' . $wpdb->esc_like( (string) $me ) . '%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE owner_id = %d OR viewers LIKE %s ORDER BY created_at DESC LIMIT 200", $me, $like ) );
	return array_values(
		array_filter(
			(array) $rows,
			function ( $s ) {
				return '' !== rrt_share_role( $s );
			}
		)
	);
}

/** Renders the share page (or the list of shares when $list is true). */
function rrt_share_render( $share, $list ) {
	$rrt_cfg   = rrt_config();
	$rrt_role  = $share ? rrt_share_role( $share ) : '';
	$rrt_share = $share;
	$rrt_is_list = (bool) $list;
	$rrt_list    = $list ? rrt_share_mine() : array();
	if ( ! $list && '' === $rrt_role ) {
		status_header( 404 );
	}
	$rrt_boot = null;
	if ( $share && '' !== $rrt_role ) {
		$owner    = get_userdata( (int) $share->owner_id );
		$me       = wp_get_current_user();
		$rrt_boot = array(
			'role'     => $rrt_role,
			'title'    => $share->title,
			'owner'    => $owner ? $owner->display_name : '',
			'me'       => $me->display_name,
			'meId'     => (int) $me->ID,
			'open'     => rrt_share_open( $share ),
			'audio'    => '' !== $share->audio_file,
			'ends'     => wp_date( 'l, F j', strtotime( $share->ends_at . ' UTC' ) ),
			'duration' => null === $share->duration_s ? null : (float) $share->duration_s,
			'words'    => json_decode( (string) $share->words, true ),
			'notes'    => rrt_share_notes( $share, $rrt_role ),
			'rest'     => esc_url_raw( rest_url( 'rrt/v1/shares/' . $share->token . '/' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'list'     => rrt_share_url(),
			'with'     => array_values(
				array_filter(
					array_map(
						function ( $id ) {
							$u = get_userdata( $id );
							return $u ? $u->display_name : '';
						},
						rrt_share_viewers( $share )
					)
				)
			),
		);
	}
	include RRT_DIR . 'views/share.php';
}

/*
 * ---------------------------------------------------------------------
 * Foxfire Studio, Shared takes
 * ---------------------------------------------------------------------
 */

add_action(
	'admin_menu',
	function () {
		add_submenu_page( 'rrt-dashboard', __( 'Shared takes', 'foxfire-studio' ), __( 'Shared takes', 'foxfire-studio' ), 'edit_posts', 'rrt-shares', 'rrt_share_admin_page' );
	},
	20
);

/** The list of the reader's shares, with End now. */
function rrt_share_admin_page() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}
	global $wpdb;
	$t  = rrt_table( 'shares' );
	$nt = rrt_table( 'share_notes' );
	if ( isset( $_POST['rrt_share_end'] ) ) {
		check_admin_referer( 'rrt_share_end' );
		$s = rrt_share_get( sanitize_text_field( wp_unslash( $_POST['rrt_share_end'] ) ) );
		if ( 'owner' === rrt_share_role( $s ) ) {
			rrt_share_end( $s );
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Ended. The audio is deleted from the site; the comments stay here.', 'foxfire-studio' ) . '</p></div>';
		}
	}
	$all = current_user_can( 'manage_options' );
	if ( $all ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT s.*, (SELECT COUNT(*) FROM $nt n WHERE n.share_id = s.id) AS notes FROM $t s ORDER BY s.created_at DESC LIMIT 300" );
	} else {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT s.*, (SELECT COUNT(*) FROM $nt n WHERE n.share_id = s.id) AS notes FROM $t s WHERE s.owner_id = %d ORDER BY s.created_at DESC LIMIT 300", get_current_user_id() ) );
	}
	echo '<div class="wrap"><h1>' . esc_html__( 'Shared takes', 'foxfire-studio' ) . '</h1>';
	echo '<p>' . esc_html( sprintf( /* translators: %d: number of days. */ __( 'Takes you shared for feedback from the review studio. A share lasts %d days; when it ends, its audio is deleted from the site and the people you chose can no longer open it. Their comments stay here.', 'foxfire-studio' ), (int) RRT_SHARE_DAYS ) ) . '</p>';
	if ( ! $rows ) {
		echo '<p><em>' . esc_html__( 'Nothing shared yet. After a take, open its review in the teleprompter and press Share for feedback.', 'foxfire-studio' ) . '</em></p></div>';
		return;
	}
	echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Reading', 'foxfire-studio' ) . '</th>' . ( $all ? '<th>' . esc_html__( 'Shared by', 'foxfire-studio' ) . '</th>' : '' ) . '<th>' . esc_html__( 'Shared with', 'foxfire-studio' ) . '</th><th>' . esc_html__( 'Shared', 'foxfire-studio' ) . '</th><th>' . esc_html__( 'Ends', 'foxfire-studio' ) . '</th><th>' . esc_html__( 'Comments', 'foxfire-studio' ) . '</th><th></th></tr></thead><tbody>';
	foreach ( $rows as $s ) {
		$open  = rrt_share_open( $s );
		$names = array_filter(
			array_map(
				function ( $id ) {
					$u = get_userdata( $id );
					return $u ? $u->display_name : '';
				},
				rrt_share_viewers( $s )
			)
		);
		$owner = get_userdata( (int) $s->owner_id );
		echo '<tr>';
		echo '<td><a href="' . esc_url( rrt_share_url( $s->token ) ) . '" target="_blank" rel="noopener">' . esc_html( $s->title ) . '</a></td>';
		if ( $all ) {
			echo '<td>' . esc_html( $owner ? $owner->display_name : '' ) . '</td>';
		}
		echo '<td>' . esc_html( implode( ', ', $names ) ) . '</td>';
		echo '<td>' . esc_html( wp_date( 'M j, g:i a', strtotime( $s->created_at . ' UTC' ) ) ) . '</td>';
		echo '<td>' . ( $open ? esc_html( wp_date( 'M j', strtotime( $s->ends_at . ' UTC' ) ) ) : '<em>' . esc_html__( 'Ended', 'foxfire-studio' ) . ( $s->ended_at ? ' ' . esc_html( wp_date( 'M j', strtotime( $s->ended_at . ' UTC' ) ) ) : '' ) . '</em>' ) . '</td>';
		echo '<td>' . (int) $s->notes . '</td>';
		echo '<td>';
		if ( $open ) {
			echo '<form method="post" style="margin:0">';
			wp_nonce_field( 'rrt_share_end' );
			echo '<button class="button" name="rrt_share_end" value="' . esc_attr( $s->token ) . '">' . esc_html__( 'End now', 'foxfire-studio' ) . '</button></form>';
		}
		echo '</td></tr>';
	}
	echo '</tbody></table></div>';
}

/*
 * ---------------------------------------------------------------------
 * Users and personal data
 * ---------------------------------------------------------------------
 */

/* A deleted user takes their comments with them, and their own shares (with the audio). */
add_action(
	'delete_user',
	function ( $user_id ) {
		global $wpdb;
		$t = rrt_table( 'shares' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$own = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE owner_id = %d", $user_id ) );
		foreach ( (array) $own as $s ) {
			rrt_share_drop_audio( $s );
			$wpdb->delete( rrt_table( 'share_notes' ), array( 'share_id' => (int) $s->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( $t, array( 'id' => (int) $s->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$wpdb->delete( rrt_table( 'share_notes' ), array( 'user_id' => (int) $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
);

add_filter(
	'wp_privacy_personal_data_exporters',
	function ( $ex ) {
		$ex['rrt-share-notes'] = array(
			'exporter_friendly_name' => __( 'Foxfire Studio feedback comments', 'foxfire-studio' ),
			'callback'               => function ( $email ) {
				$user = get_user_by( 'email', $email );
				$data = array();
				if ( $user ) {
					global $wpdb;
					$t = rrt_table( 'share_notes' );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE user_id = %d ORDER BY created_at", $user->ID ) );
					foreach ( (array) $rows as $r ) {
						$data[] = array(
							'group_id'    => 'rrt-share-notes',
							'group_label' => __( 'Foxfire Studio feedback comments', 'foxfire-studio' ),
							'item_id'     => 'rrt-share-note-' . $r->id,
							'data'        => array(
								array( 'name' => __( 'Name given', 'foxfire-studio' ), 'value' => $r->name ),
								array( 'name' => __( 'Written', 'foxfire-studio' ), 'value' => $r->created_at . ' UTC' ),
								array( 'name' => __( 'Moment (s)', 'foxfire-studio' ), 'value' => (string) $r->at_s ),
								array( 'name' => __( 'Comment', 'foxfire-studio' ), 'value' => $r->body ),
							),
						);
					}
				}
				return array( 'data' => $data, 'done' => true );
			},
		);
		return $ex;
	}
);
add_filter(
	'wp_privacy_personal_data_erasers',
	function ( $er ) {
		$er['rrt-share-notes'] = array(
			'eraser_friendly_name' => __( 'Foxfire Studio feedback comments', 'foxfire-studio' ),
			'callback'             => function ( $email ) {
				$user = get_user_by( 'email', $email );
				$n    = 0;
				if ( $user ) {
					global $wpdb;
					$n = (int) $wpdb->delete( rrt_table( 'share_notes' ), array( 'user_id' => $user->ID ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				}
				return array( 'items_removed' => $n > 0, 'items_retained' => false, 'messages' => array(), 'done' => true );
			},
		);
		return $er;
	}
);
