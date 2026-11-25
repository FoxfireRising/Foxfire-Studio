<?php
/**
 * Readings of deleted posts (1.36.2).
 *
 * Says what became of each reading listed on Foxfire Studio, Recordings (on
 * its post, its post in the Trash, its post deleted, its file deleted), lets
 * readings that are no longer on a live post be deleted there one at a time
 * or all at once, and, while Settings, Reader stats, "When a post is deleted
 * for good" is on, deletes a post's readings, captions, waiting phone takes,
 * take shelf takes and Recordings rows when the post itself is deleted for
 * good. A file that another post still uses is never deleted.
 *
 * @package FoxfireStudio
 */

defined( 'ABSPATH' ) || exit;

/** Whether a post's readings go with it when it is deleted for good (on unless switched off). */
function rrt_tidy_on() {
	$s = rrt_settings();
	return ! isset( $s['stats']['tidy_on_delete'] ) || ! empty( $s['stats']['tidy_on_delete'] );
}

/** One Recordings row, or null. */
function rrt_tidy_row( $id ) {
	global $wpdb;
	$t = rrt_table( 'recordings' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the only variable is the plugin's own table name.
	$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", (int) $id ), ARRAY_A );
	return $r ? $r : null;
}

/** The Media Library item of a row's MP3, or 0 when there is none. */
function rrt_tidy_att( $r ) {
	$att = isset( $r['attachment_id'] ) ? (int) $r['attachment_id'] : 0;
	if ( $att && 'attachment' === get_post_type( $att ) ) {
		return $att;
	}
	if ( ! empty( $r['url'] ) ) {
		$id = (int) attachment_url_to_postid( (string) $r['url'] );
		if ( $id && 'attachment' === get_post_type( $id ) ) {
			return $id;
		}
	}
	return 0;
}

/** Whether a row's file lives in this site's uploads (so the plugin can tell whether it is still there). */
function rrt_tidy_local( $r ) {
	if ( rrt_tidy_att( $r ) || ! empty( $r['attachment_id'] ) ) {
		return true;
	}
	$up = wp_get_upload_dir();
	$u  = preg_replace( '#^https?:#i', '', (string) ( $r['url'] ?? '' ) );
	$b  = preg_replace( '#^https?:#i', '', (string) $up['baseurl'] );
	return '' !== $u && '' !== $b && 0 === strpos( $u, $b );
}

/** Whether a row's file is still on the site. A reading kept outside the site always counts as there. */
function rrt_tidy_file_there( $r ) {
	$att = rrt_tidy_att( $r );
	if ( $att ) {
		$path = get_attached_file( $att );
		return $path && file_exists( $path );
	}
	return ! rrt_tidy_local( $r );
}

/**
 * Where a row's reading stands: 'post' is live, trash or deleted; 'file' is
 * there or gone.
 *
 * @return array{post: string, file: string}
 */
function rrt_tidy_state( $r ) {
	$p = get_post( (int) $r['post_id'] );
	if ( ! $p ) {
		$where = 'deleted';
	} elseif ( 'trash' === $p->post_status ) {
		$where = 'trash';
	} else {
		$where = 'live';
	}
	return array(
		'post' => $where,
		'file' => rrt_tidy_file_there( $r ) ? 'there' : 'gone',
	);
}

/** The words for Recordings' "On the post now" column. */
function rrt_tidy_label( $r, $kept = false ) {
	$st    = rrt_tidy_state( $r );
	$parts = array();
	if ( 'deleted' === $st['post'] ) {
		$parts[] = __( 'post deleted', 'foxfire-studio' );
	} elseif ( 'trash' === $st['post'] ) {
		$parts[] = ! empty( $r['is_current'] ) ? __( 'yes, post in the Trash', 'foxfire-studio' ) : __( 'no, post in the Trash', 'foxfire-studio' );
	} elseif ( ! empty( $r['is_current'] ) ) {
		$parts[] = __( 'yes', 'foxfire-studio' );
	} elseif ( $kept ) {
		$parts[] = __( 'kept aside', 'foxfire-studio' );
	} else {
		$parts[] = __( 'no', 'foxfire-studio' );
	}
	if ( ! $kept && 'gone' === $st['file'] ) {
		$parts[] = __( 'file deleted', 'foxfire-studio' );
	}
	return implode( ', ', $parts );
}

/** Whether a row may be deleted on Recordings: its reading is not on a live post, and it is not kept aside. */
function rrt_tidy_can_delete( $r, $kept = false ) {
	if ( $kept ) {
		return false;
	}
	$st = rrt_tidy_state( $r );
	return ! ( 'live' === $st['post'] && ! empty( $r['is_current'] ) );
}

/** Whether a row belongs with "readings of deleted posts": its post is gone or in the Trash, or its file is gone. */
function rrt_tidy_is_leftover( $r, $kept = false ) {
	if ( $kept ) {
		return false;
	}
	$st = rrt_tidy_state( $r );
	return 'live' !== $st['post'] || ( 'gone' === $st['file'] && empty( $r['is_current'] ) );
}

/** The meta keys a post's reading and captions can sit in. */
function rrt_tidy_keys() {
	$keys = array( 'rrt_audio', 'rrt_captions' );
	$cfg  = rrt_config();
	if ( ! empty( $cfg['player']['audio_key'] ) ) {
		$keys[] = (string) $cfg['player']['audio_key'];
	}
	if ( function_exists( 'rrt_feed_audio_keys' ) ) {
		$keys = array_merge( $keys, (array) rrt_feed_audio_keys() );
	}
	return array_values( array_unique( array_filter( $keys ) ) );
}

/**
 * The post that still uses a Media Library item as its reading or captions,
 * or as its current reading on Recordings, or 0. Posts in the Trash do not
 * count, and neither does $skip_post (the post being deleted for good).
 */
function rrt_tidy_in_use( $att, $skip_post = 0 ) {
	global $wpdb;
	$url    = (string) wp_get_attachment_url( $att );
	$bare   = preg_replace( '#^https?:#i', '', $url );
	$values = array_values( array_filter( array( (string) $att, $url, $bare, 'http:' . $bare, 'https:' . $bare ) ) );
	$keys   = rrt_tidy_keys();
	$kin    = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
	$vin    = implode( ',', array_fill( 0, count( $values ), '%s' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built above.
	$posts = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM $wpdb->postmeta WHERE post_id <> %d AND meta_key IN ($kin) AND meta_value IN ($vin)", array_merge( array( (int) $skip_post ), $keys, $values ) ) );
	$t = rrt_table( 'recordings' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the only variable is the plugin's own table name.
	$rows  = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM $t WHERE post_id <> %d AND is_current = 1 AND attachment_id = %d", (int) $skip_post, (int) $att ) );
	foreach ( array_unique( array_merge( $posts, $rows ) ) as $pid ) {
		$p = get_post( (int) $pid );
		if ( $p && 'trash' !== $p->post_status && ! in_array( $p->post_type, array( 'revision', 'attachment' ), true ) ) {
			return (int) $pid;
		}
	}
	return 0;
}

/**
 * The captions that came with a reading: the post's captions while the row is
 * its current reading, and a .vtt uploaded with the MP3 (the next few items
 * in the Media Library, attached to the same post).
 *
 * @return int[]
 */
function rrt_tidy_captions_for( $att, $r ) {
	$out = array();
	$pid = (int) $r['post_id'];
	if ( ! empty( $r['is_current'] ) && get_post( $pid ) && function_exists( 'rrt_media_from_value' ) ) {
		$v = get_post_meta( $pid, 'rrt_captions', true );
		$m = rrt_media_from_value( $v );
		$c = ! empty( $m['id'] ) ? (int) $m['id'] : ( $m['url'] ? (int) attachment_url_to_postid( $m['url'] ) : 0 );
		if ( $c ) {
			$out[] = $c;
		}
	}
	if ( $att ) {
		$parent = (int) wp_get_post_parent_id( $att );
		for ( $c = $att + 1; $c <= $att + 3; $c++ ) {
			$p = get_post( $c );
			if ( $p && 'attachment' === $p->post_type && 'text/vtt' === $p->post_mime_type && $parent && (int) $p->post_parent === $parent ) {
				$out[] = $c;
				break;
			}
		}
	}
	return array_values( array_unique( $out ) );
}

/** Deletes an MP3 from the Media Library, with the cover picture WordPress made from it when nothing else uses that picture. */
function rrt_tidy_delete_audio( $att ) {
	global $wpdb;
	$img = (int) get_post_meta( $att, '_thumbnail_id', true );
	wp_delete_attachment( $att, true );
	if ( $img && 'attachment' === get_post_type( $img ) ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$others = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->postmeta WHERE meta_key = '_thumbnail_id' AND meta_value = %s", (string) $img ) );
		if ( 0 === $others ) {
			wp_delete_attachment( $img, true );
		}
	}
}

/**
 * Deletes a Recordings row and, when nothing else uses them, its MP3 and its
 * captions. $force is for a post being deleted for good: any row of it goes,
 * and the post itself no longer counts as using the files. Returns what was
 * deleted, or a WP_Error when the row may not be deleted.
 *
 * @return array{files: int, kept_for: int}|WP_Error
 */
function rrt_tidy_delete_row( $id, $kept = false, $force = false ) {
	global $wpdb;
	$r = rrt_tidy_row( $id );
	if ( ! $r ) {
		return new WP_Error( 'rrt_tidy', __( 'That recording is no longer listed.', 'foxfire-studio' ) );
	}
	if ( ! $force && ! rrt_tidy_can_delete( $r, $kept ) ) {
		return new WP_Error( 'rrt_tidy', __( 'That reading is on its post. Take it off the post first (on its page under Foxfire Studio, Readings).', 'foxfire-studio' ) );
	}
	$files = 0;
	$used  = 0;
	$att   = rrt_tidy_att( $r );
	$skip  = $force ? (int) $r['post_id'] : 0;
	if ( $att ) {
		$used = rrt_tidy_in_use( $att, $skip );
		if ( ! $used ) {
			foreach ( rrt_tidy_captions_for( $att, $r ) as $cap ) {
				if ( ! rrt_tidy_in_use( $cap, $skip ) ) {
					wp_delete_attachment( $cap, true );
					++$files;
				}
			}
			rrt_tidy_delete_audio( $att );
			++$files;
		}
	}
	$wpdb->delete( rrt_table( 'recordings' ), array( 'id' => (int) $r['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return array(
		'files'    => $files,
		'kept_for' => $used,
	);
}

/** Every row that belongs with "readings of deleted posts". */
function rrt_tidy_leftovers() {
	global $wpdb;
	$kept = function_exists( 'rrt_kept_rec_ids' ) ? rrt_kept_rec_ids() : array();
	$t    = rrt_table( 'recordings' );
	$out  = array();
	foreach ( (array) $wpdb->get_results( "SELECT * FROM $t", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table, nothing from outside.
		if ( rrt_tidy_is_leftover( $r, isset( $kept[ (int) $r['id'] ] ) ) ) {
			$out[] = $r;
		}
	}
	return $out;
}

/**
 * A post deleted for good takes its readings with it: every Recordings row of
 * the post and the files nothing else uses, the reading and captions still on
 * it, and each person's waiting phone takes and take shelf takes of it.
 * Readings kept aside already go (keep.php), and so do sponsored copies
 * (sponsors.php).
 */
function rrt_tidy_post( $post_id ) {
	global $wpdb;
	$post_id = (int) $post_id;
	$t       = rrt_table( 'recordings' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the only variable is the plugin's own table name.
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $t WHERE post_id = %d", $post_id ) );
	foreach ( $ids as $id ) {
		rrt_tidy_delete_row( (int) $id, false, true );
	}
	/* The reading and captions on the post, when no row listed them. */
	if ( function_exists( 'rrt_media_from_value' ) ) {
		foreach ( array( 'rrt_audio', 'rrt_captions' ) as $key ) {
			$m   = rrt_media_from_value( get_post_meta( $post_id, $key, true ) );
			$att = ! empty( $m['id'] ) ? (int) $m['id'] : ( $m['url'] ? (int) attachment_url_to_postid( $m['url'] ) : 0 );
			if ( $att && 'attachment' === get_post_type( $att ) && ! rrt_tidy_in_use( $att, $post_id ) ) {
				if ( 'rrt_audio' === $key ) {
					rrt_tidy_delete_audio( $att );
				} else {
					wp_delete_attachment( $att, true );
				}
			}
		}
	}
	/* Waiting phone takes and take shelf takes of this post, for everyone. */
	if ( function_exists( 'rrt_sent_delete' ) ) {
		foreach ( get_users( array( 'meta_key' => '_rrt_sent', 'fields' => 'ID' ) ) as $uid ) { // phpcs:ignore WordPress.DB.SlowDBQuery
			$list = get_user_meta( (int) $uid, '_rrt_sent', true );
			foreach ( is_array( $list ) ? $list : array() as $take ) {
				if ( isset( $take['post'], $take['id'] ) && (int) $take['post'] === $post_id ) {
					rrt_sent_delete( (int) $uid, (string) $take['id'] );
				}
			}
		}
	}
	if ( $post_id > 0 && function_exists( 'rrt_shelf_clear' ) ) {
		foreach ( get_users( array( 'meta_key' => '_rrt_shelf', 'fields' => 'ID' ) ) as $uid ) { // phpcs:ignore WordPress.DB.SlowDBQuery
			rrt_shelf_clear( (int) $uid, $post_id );
		}
	}
}

/* Runs before WordPress forgets the post's attachments and meta. */
add_action(
	'before_delete_post',
	function ( $post_id ) {
		$p = get_post( $post_id );
		if ( ! $p || in_array( $p->post_type, array( 'attachment', 'revision', 'nav_menu_item', 'customize_changeset', 'oembed_cache' ), true ) ) {
			return;
		}
		if ( ! rrt_tidy_on() ) {
			return;
		}
		rrt_tidy_post( (int) $post_id );
	},
	5
);

/** A message for the next Recordings page. */
function rrt_tidy_say( $kind, $text ) {
	set_transient( 'rrt_tidy_msg_' . get_current_user_id(), array( $kind, $text ), 120 );
}

/** Shows the message left by Delete or Clear. */
function rrt_tidy_notice() {
	$key = 'rrt_tidy_msg_' . get_current_user_id();
	$msg = get_transient( $key );
	if ( ! is_array( $msg ) || '' === (string) $msg[1] ) {
		return;
	}
	delete_transient( $key );
	echo '<div class="notice ' . ( 'error' === $msg[0] ? 'notice-error' : 'notice-success' ) . ' rrt-tidy-msg" role="status"><p>' . esc_html( $msg[1] ) . '</p></div>';
}

/**
 * One Delete (or Remove from list) on Recordings: a button that opens a
 * question on the page, and the form that answers it. Works without scripts.
 */
function rrt_tidy_row_form( $r ) {
	$gone  = 'gone' === rrt_tidy_state( $r )['file'] || ! rrt_tidy_att( $r );
	$label = $gone ? __( 'Remove from list', 'foxfire-studio' ) : __( 'Delete', 'foxfire-studio' );
	$ask   = $gone ? __( 'Remove this row from the list?', 'foxfire-studio' ) : __( 'Delete this reading and its captions for good?', 'foxfire-studio' );
	$yes   = $gone ? __( 'Yes, remove', 'foxfire-studio' ) : __( 'Yes, delete for good', 'foxfire-studio' );
	echo '<details class="rrt-tidy-ask"><summary class="button button-small">' . esc_html( $label ) . '</summary><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><p>' . esc_html( $ask ) . '</p>';
	echo '<input type="hidden" name="action" value="rrt_rec_delete"><input type="hidden" name="rec" value="' . (int) $r['id'] . '">';
	wp_nonce_field( 'rrt_rec_delete_' . (int) $r['id'] );
	echo '<button type="submit" class="button button-small button-link-delete">' . esc_html( $yes ) . '</button> <button type="button" class="button button-small rrt-tidy-cancel">' . esc_html__( 'Cancel', 'foxfire-studio' ) . '</button></form></details>';
}

/** The Clear readings of deleted posts button, shown only when there are any. */
function rrt_tidy_clear_form( $count ) {
	if ( $count < 1 ) {
		return;
	}
	/* translators: %d: how many rows */
	$text = sprintf( _n( 'Clear readings of deleted posts (%d)', 'Clear readings of deleted posts (%d)', $count, 'foxfire-studio' ), $count );
	echo '<details class="rrt-tidy-ask rrt-tidy-clear" id="rrt-tidy-clear"><summary class="button">' . esc_html( $text ) . '</summary><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><p>' . esc_html__( 'Delete the readings and captions of every post that is in the Trash or deleted, and remove the rows whose file is gone? Files another post still uses stay. This cannot be undone.', 'foxfire-studio' ) . '</p>';
	echo '<input type="hidden" name="action" value="rrt_rec_clear">';
	wp_nonce_field( 'rrt_rec_clear' );
	echo '<button type="submit" class="button button-link-delete">' . esc_html__( 'Yes, clear them', 'foxfire-studio' ) . '</button> <button type="button" class="button rrt-tidy-cancel">' . esc_html__( 'Cancel', 'foxfire-studio' ) . '</button></form></details>';
}

/** After Delete or Clear: back to Recordings. */
function rrt_tidy_back() {
	wp_safe_redirect( admin_url( 'admin.php?page=rrt-recordings' ) );
	exit;
}

add_action(
	'admin_post_rrt_rec_delete',
	function () {
		$id = isset( $_POST['rec'] ) ? absint( $_POST['rec'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- checked on the next line.
		check_admin_referer( 'rrt_rec_delete_' . $id );
		if ( ! current_user_can( rrt_dash_cap() ) || ! current_user_can( 'delete_posts' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'foxfire-studio' ) );
		}
		$kept = function_exists( 'rrt_kept_rec_ids' ) ? rrt_kept_rec_ids() : array();
		$res  = rrt_tidy_delete_row( $id, isset( $kept[ $id ] ) );
		if ( is_wp_error( $res ) ) {
			rrt_tidy_say( 'error', $res->get_error_message() );
		} elseif ( $res['kept_for'] ) {
			/* translators: %s: a post title */
			rrt_tidy_say( 'success', sprintf( __( 'Removed from the list. The file stays, because "%s" still uses it.', 'foxfire-studio' ), wp_strip_all_tags( get_the_title( $res['kept_for'] ) ) ) );
		} elseif ( $res['files'] ) {
			rrt_tidy_say( 'success', __( 'Deleted the reading and its captions.', 'foxfire-studio' ) );
		} else {
			rrt_tidy_say( 'success', __( 'Removed from the list.', 'foxfire-studio' ) );
		}
		rrt_tidy_back();
	}
);

add_action(
	'admin_post_rrt_rec_clear',
	function () {
		check_admin_referer( 'rrt_rec_clear' );
		if ( ! current_user_can( rrt_dash_cap() ) || ! current_user_can( 'delete_posts' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'foxfire-studio' ) );
		}
		$rows  = 0;
		$files = 0;
		foreach ( rrt_tidy_leftovers() as $r ) {
			$res = rrt_tidy_delete_row( (int) $r['id'] );
			if ( ! is_wp_error( $res ) ) {
				++$rows;
				$files += (int) $res['files'];
			}
		}
		/* translators: 1: rows removed, 2: files deleted */
		rrt_tidy_say( 'success', sprintf( __( 'Cleared %1$d rows and deleted %2$d files.', 'foxfire-studio' ), $rows, $files ) );
		rrt_tidy_back();
	}
);
