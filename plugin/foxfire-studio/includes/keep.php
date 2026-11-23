<?php
/**
 * Keep a reading aside (1.8.0).
 *
 * Take off the post moves a post's reading (its MP3 or WAV, and its
 * captions) out of the Media Library into uploads/rrt-kept, a folder that
 * refuses every direct request. The post loses its player, the podcast feed
 * loses the episode, and the post is back on Readings to make. Nothing about
 * the reading's listening history changes: its Recordings row stops being
 * current, as it would for any earlier reading.
 *
 * Put back returns the files to the Media Library at the address they had,
 * fills in the field the reading came from, and brings back the captions,
 * the music bed's credit and the pick for the feed. The Recordings row
 * becomes current again, so its listening history carries on. If the post
 * has a newer reading by then, that one is kept aside in its place.
 *
 * A reading kept aside stays until it is deleted for good. Each post keeps
 * its list in post meta (_rrt_kept), so no table is needed.
 *
 * A reading from the extra field in Settings (a theme's own field) is
 * handled the same way when that field points to a file in the Media
 * Library. When it points anywhere else, or the file is also another post's
 * reading, only the field is cleared and remembered; the file stays put.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The post meta that holds a post's readings kept aside. */
define( 'RRT_KEPT_META', '_rrt_kept' );

/** The most readings one post can keep aside. */
define( 'RRT_KEPT_MAX', 10 );

/** The folder that holds readings kept aside. */
function rrt_kept_dir() {
	$up = wp_upload_dir( null, false );
	return trailingslashit( $up['basedir'] ) . 'rrt-kept/';
}

/** Makes the folder, with files that refuse every direct request. */
function rrt_kept_dir_ready() {
	$dir = rrt_kept_dir();
	if ( ! wp_mkdir_p( $dir ) ) {
		return false;
	}
	if ( ! file_exists( $dir . '.htaccess' ) ) {
		/* Apache 2.4 and LiteSpeed read the first; older Apache the second. */
		file_put_contents( $dir . '.htaccess', "# Foxfire Studio: readings kept aside are served only to people who can edit their post.\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
	if ( ! file_exists( $dir . 'index.php' ) ) {
		file_put_contents( $dir . 'index.php', "<?php\n// Silence.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
	return true;
}

/** A post's readings kept aside, newest first. */
function rrt_kept_list( $post_id ) {
	$list = get_post_meta( $post_id, RRT_KEPT_META, true );
	$list = is_array( $list ) ? array_values( array_filter( $list, 'is_array' ) ) : array();
	usort(
		$list,
		function ( $a, $b ) {
			return (int) $b['at'] - (int) $a['at'];
		}
	);
	return $list;
}

/** Saves a post's list, removing the meta when it is empty. */
function rrt_kept_save( $post_id, $list ) {
	if ( $list ) {
		update_post_meta( $post_id, RRT_KEPT_META, array_values( $list ) );
	} else {
		delete_post_meta( $post_id, RRT_KEPT_META );
	}
}

/** One of a post's readings kept aside by id, or null. */
function rrt_kept_find( $post_id, $id ) {
	$id = preg_replace( '/[^a-zA-Z0-9]/', '', (string) $id );
	foreach ( rrt_kept_list( $post_id ) as $k ) {
		if ( '' !== $id && hash_equals( (string) $k['id'], $id ) ) {
			return $k;
		}
	}
	return null;
}

/** Every post with a reading kept aside, as post ids. */
function rrt_kept_posts() {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM $wpdb->postmeta WHERE meta_key = %s", RRT_KEPT_META ) ) );
}

/** The fields a post's reading may come from: the plugin's own, then the extra one from Settings. */
function rrt_kept_fields() {
	$cfg  = rrt_config();
	$keys = array( 'rrt_audio' );
	if ( ! empty( $cfg['player']['audio_key'] ) ) {
		$keys[] = $cfg['player']['audio_key'];
	}
	return $keys;
}

/** Whether a person may take readings off a post and put them back. */
function rrt_kept_can( $post_id ) {
	return $post_id > 0 && get_post( $post_id ) && current_user_can( 'edit_post', $post_id ) && current_user_can( 'upload_files' );
}

/**
 * The Media Library file behind a field's value, or 0. An attachment id,
 * or the address of a file in the uploads folder.
 */
function rrt_kept_attachment( $raw, $url ) {
	if ( is_numeric( $raw ) && (int) $raw > 0 && 'attachment' === get_post_type( (int) $raw ) ) {
		return (int) $raw;
	}
	if ( '' === (string) $url ) {
		return 0;
	}
	$id = attachment_url_to_postid( preg_replace( '#^//#', ( is_ssl() ? 'https' : 'http' ) . '://', (string) $url ) );
	return $id > 0 ? (int) $id : 0;
}

/**
 * Whether another post uses the same file in one of the given fields, by
 * attachment id or by its address. Such a file is never moved.
 */
function rrt_kept_shared( $post_id, $att, $keys ) {
	global $wpdb;
	$url    = (string) wp_get_attachment_url( $att );
	$bare   = preg_replace( '#^https?:#i', '', $url );
	$values = array_filter( array( (string) $att, $url, $bare, 'http:' . $bare, 'https:' . $bare ) );
	$kin    = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
	$vin    = implode( ',', array_fill( 0, count( $values ), '%s' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->postmeta WHERE post_id <> %d AND meta_key IN ($kin) AND meta_value IN ($vin)", array_merge( array( $post_id ), $keys, $values ) ) );
	return $n > 0;
}

/** Moves a file, across folders and disks. */
function rrt_kept_move( $from, $to ) {
	if ( @rename( $from, $to ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		return true;
	}
	if ( @copy( $from, $to ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_delete_file( $from );
		return true;
	}
	return false;
}

/**
 * Moves an attachment's file into the folder and removes the attachment from
 * the Media Library. Returns what Put back needs to make it again, or a
 * WP_Error. The attachment's own record is gone afterwards.
 */
function rrt_kept_stash( $att, $kid, $what ) {
	$path = get_attached_file( $att );
	if ( ! $path || ! file_exists( $path ) ) {
		return new WP_Error( 'rrt_kept', __( 'The file for this reading is missing from the uploads folder.', 'foxfire-studio' ) );
	}
	$p    = get_post( $att );
	$ext  = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
	$ext  = preg_match( '/^[a-z0-9]{1,5}$/', $ext ) ? $ext : 'bin';
	$name = $kid . '-' . $what . '.' . $ext;
	$out  = array(
		'file'     => $name,
		'rel'      => (string) get_post_meta( $att, '_wp_attached_file', true ),
		'title'    => $p ? $p->post_title : '',
		'content'  => $p ? $p->post_content : '',
		'excerpt'  => $p ? $p->post_excerpt : '',
		'date'     => $p ? $p->post_date : '',
		'date_gmt' => $p ? $p->post_date_gmt : '',
		'author'   => $p ? (int) $p->post_author : 0,
		'mime'     => (string) get_post_mime_type( $att ),
		'meta'     => wp_get_attachment_metadata( $att ),
		'bytes'    => (int) filesize( $path ),
		'att'      => (int) $att,
	);
	if ( '' === $out['rel'] ) {
		$out['rel'] = _wp_relative_upload_path( $path );
	}
	if ( ! rrt_kept_move( $path, rrt_kept_dir() . $name ) ) {
		return new WP_Error( 'rrt_kept', __( 'The file could not be moved. Check that the uploads folder is writable.', 'foxfire-studio' ) );
	}
	return $out;
}

/**
 * Makes a stashed file an attachment again, at the address it had where that
 * is free. Returns the new attachment id, or a WP_Error. The kept file is
 * moved, so it is gone from the folder afterwards.
 */
function rrt_kept_unstash( $f, $post_id ) {
	$src = rrt_kept_dir() . basename( (string) $f['file'] );
	if ( ! file_exists( $src ) ) {
		return new WP_Error( 'rrt_kept', __( 'The kept file is missing from the folder.', 'foxfire-studio' ) );
	}
	$up  = wp_upload_dir( null, false );
	$rel = ltrim( str_replace( '\\', '/', (string) $f['rel'] ), '/' );
	if ( '' === $rel || false !== strpos( $rel, '..' ) ) {
		$rel = gmdate( 'Y/m' ) . '/' . sanitize_file_name( (string) $f['title'] ) . '.' . pathinfo( $src, PATHINFO_EXTENSION );
	}
	$dir  = trailingslashit( $up['basedir'] ) . ( '.' === dirname( $rel ) ? '' : dirname( $rel ) );
	$name = wp_basename( $rel );
	if ( ! wp_mkdir_p( $dir ) ) {
		return new WP_Error( 'rrt_kept', __( 'The uploads folder is not writable.', 'foxfire-studio' ) );
	}
	if ( file_exists( trailingslashit( $dir ) . $name ) ) {
		$name = wp_unique_filename( $dir, $name );
	}
	$dest = trailingslashit( $dir ) . $name;
	if ( ! rrt_kept_move( $src, $dest ) ) {
		return new WP_Error( 'rrt_kept', __( 'The file could not be moved back. Check that the uploads folder is writable.', 'foxfire-studio' ) );
	}
	$args = array(
		'post_title'     => (string) $f['title'],
		'post_content'   => (string) $f['content'],
		'post_excerpt'   => (string) $f['excerpt'],
		'post_mime_type' => (string) $f['mime'],
		'post_status'    => 'inherit',
		'post_parent'    => $post_id,
	);
	if ( ! empty( $f['date'] ) && '0000-00-00 00:00:00' !== $f['date'] ) {
		$args['post_date']     = $f['date'];
		$args['post_date_gmt'] = $f['date_gmt'];
	}
	if ( ! empty( $f['author'] ) && get_userdata( (int) $f['author'] ) ) {
		$args['post_author'] = (int) $f['author'];
	}
	$att = wp_insert_attachment( $args, $dest, $post_id, true );
	if ( is_wp_error( $att ) || ! $att ) {
		rrt_kept_move( $dest, $src );
		return is_wp_error( $att ) ? $att : new WP_Error( 'rrt_kept', __( 'The Media Library entry could not be made.', 'foxfire-studio' ) );
	}
	if ( is_array( $f['meta'] ) && $f['meta'] ) {
		wp_update_attachment_metadata( $att, $f['meta'] );
	} else {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		wp_update_attachment_metadata( $att, wp_generate_attachment_metadata( $att, $dest ) );
	}
	return (int) $att;
}

/** Deletes a kept record's files from the folder. */
function rrt_kept_delete_files( $k ) {
	foreach ( array( 'audio', 'captions' ) as $w ) {
		if ( ! empty( $k[ $w ]['file'] ) ) {
			$f = rrt_kept_dir() . basename( (string) $k[ $w ]['file'] );
			if ( file_exists( $f ) ) {
				wp_delete_file( $f );
			}
		}
	}
}

/** Tells page caches, and the feed, that a post's reading changed. */
function rrt_kept_changed( $post_id ) {
	clean_post_cache( $post_id );
	do_action( 'litespeed_purge_post', $post_id );
	if ( function_exists( 'rrt_podcast_purge' ) ) {
		rrt_podcast_purge();
	}
	/** Fires after a reading is taken off a post or put back (1.8.0). */
	do_action( 'rrt_reading_kept', $post_id );
}

/** The Recordings row for a post's current reading, or 0. */
function rrt_kept_current_rec( $post_id ) {
	if ( ! function_exists( 'rrt_table' ) ) {
		return 0;
	}
	global $wpdb;
	$t = rrt_table( 'recordings' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE post_id = %d AND is_current = 1 ORDER BY id DESC LIMIT 1", $post_id ) );
}

/**
 * Takes a post's reading off the post and keeps it aside. Returns the kept
 * record, or a WP_Error.
 */
function rrt_kept_take_off( $post_id ) {
	$post_id = (int) $post_id;
	$audio   = rrt_reading_audio( $post_id );
	if ( '' === $audio['url'] ) {
		return new WP_Error( 'rrt_kept', __( 'This post has no reading to take off.', 'foxfire-studio' ) );
	}
	$list = rrt_kept_list( $post_id );
	if ( count( $list ) >= RRT_KEPT_MAX ) {
		return new WP_Error( 'rrt_kept', sprintf( /* translators: %d: number of readings. */ __( 'This post already keeps %d readings aside. Delete one for good first.', 'foxfire-studio' ), RRT_KEPT_MAX ) );
	}
	if ( ! rrt_kept_dir_ready() ) {
		return new WP_Error( 'rrt_kept', __( 'The folder for readings kept aside could not be made. Check that the uploads folder is writable.', 'foxfire-studio' ) );
	}
	/* Which field holds it, and in what form. */
	$field = '';
	$raw   = '';
	foreach ( rrt_kept_fields() as $key ) {
		$v = get_post_meta( $post_id, $key, true );
		$m = rrt_media_from_value( $v );
		if ( $m['url'] ) {
			$field = $key;
			$raw   = is_scalar( $v ) ? (string) $v : '';
			break;
		}
	}
	$kid = strtolower( wp_generate_password( 24, false, false ) );
	$k   = array(
		'id'       => $kid,
		'at'       => time(),
		'by'       => get_current_user_id(),
		'title'    => mb_substr( wp_strip_all_tags( get_the_title( $post_id ) ), 0, 250 ),
		'field'    => $field,
		'raw'      => $raw,
		'kind'     => is_numeric( $raw ) ? 'id' : 'url',
		'url'      => (string) $audio['url'],
		'audio'    => null,
		'captions' => null,
		'cap_raw'  => '',
		'music'    => get_post_meta( $post_id, '_rrt_music_used', true ),
		'pick'     => get_post_meta( $post_id, '_rrt_soundbite', true ),
		'rec'      => rrt_kept_current_rec( $post_id ),
		'seconds'  => 0,
		'stays'    => '',
	);
	/* The audio file, unless it is outside the Media Library or another post's reading too. */
	$keys = rrt_kept_fields();
	$att  = rrt_kept_attachment( $raw, $audio['url'] );
	if ( $att && rrt_kept_shared( $post_id, $att, $keys ) ) {
		$k['stays'] = 'shared';
	} elseif ( ! $att ) {
		$k['stays'] = 'outside';
	} else {
		$facts        = function_exists( 'rrt_attachment_audio_facts' ) ? rrt_attachment_audio_facts( $att ) : array();
		$k['seconds'] = isset( $facts['duration_s'] ) ? (float) $facts['duration_s'] : 0;
		$st           = rrt_kept_stash( $att, $kid, 'audio' );
		if ( is_wp_error( $st ) ) {
			return $st;
		}
		$k['audio'] = $st;
	}
	/* The captions, the same way. */
	$cap_v = get_post_meta( $post_id, 'rrt_captions', true );
	$cap   = rrt_media_from_value( $cap_v );
	if ( $cap['url'] ) {
		$catt = rrt_kept_attachment( $cap_v, $cap['url'] );
		if ( $catt && ! rrt_kept_shared( $post_id, $catt, array( 'rrt_captions' ) ) ) {
			$st = rrt_kept_stash( $catt, $kid, 'captions' );
			if ( ! is_wp_error( $st ) ) {
				$k['captions'] = $st;
			}
		}
		if ( ! $k['captions'] ) {
			$k['cap_raw'] = is_scalar( $cap_v ) ? (string) $cap_v : '';
		}
	}
	/* The record is saved before anything is removed, so a failure leaves nothing lost. */
	$list[] = $k;
	rrt_kept_save( $post_id, $list );
	foreach ( array( 'audio', 'captions' ) as $w ) {
		if ( ! empty( $k[ $w ]['att'] ) ) {
			wp_delete_attachment( (int) $k[ $w ]['att'], true );
		}
	}
	if ( $field ) {
		delete_post_meta( $post_id, $field );
	}
	delete_post_meta( $post_id, 'rrt_captions' );
	delete_post_meta( $post_id, '_rrt_music_used' );
	delete_post_meta( $post_id, '_rrt_soundbite' );
	if ( $k['rec'] && function_exists( 'rrt_table' ) ) {
		global $wpdb;
		$wpdb->update( rrt_table( 'recordings' ), array( 'is_current' => 0 ), array( 'id' => $k['rec'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
	rrt_kept_changed( $post_id );
	return $k;
}

/**
 * Puts a reading kept aside back on its post. If the post has a reading by
 * then, that one is kept aside first. Returns an array with 'swapped' (the
 * reading kept aside in its place, or null) and 'moved' (true when the
 * address changed because another file had taken it), or a WP_Error.
 */
function rrt_kept_put_back( $post_id, $id ) {
	$post_id = (int) $post_id;
	$k       = rrt_kept_find( $post_id, $id );
	if ( ! $k ) {
		return new WP_Error( 'rrt_kept', __( 'That reading is no longer kept aside.', 'foxfire-studio' ) );
	}
	foreach ( array( 'audio', 'captions' ) as $w ) {
		if ( ! empty( $k[ $w ]['file'] ) && ! file_exists( rrt_kept_dir() . basename( (string) $k[ $w ]['file'] ) ) ) {
			return new WP_Error( 'rrt_kept', __( 'The kept file is missing from the folder, so it cannot be put back.', 'foxfire-studio' ) );
		}
	}
	$swapped = null;
	if ( '' !== rrt_reading_audio( $post_id )['url'] ) {
		$swapped = rrt_kept_take_off( $post_id );
		if ( is_wp_error( $swapped ) ) {
			return $swapped;
		}
	}
	$att = 0;
	if ( ! empty( $k['audio'] ) ) {
		$att = rrt_kept_unstash( $k['audio'], $post_id );
		if ( is_wp_error( $att ) ) {
			return $att;
		}
	}
	$cap = 0;
	if ( ! empty( $k['captions'] ) ) {
		$cap = rrt_kept_unstash( $k['captions'], $post_id );
		$cap = is_wp_error( $cap ) ? 0 : $cap;
	}
	$url   = $att ? (string) wp_get_attachment_url( $att ) : (string) $k['url'];
	$moved = $att && $url !== (string) $k['url'];
	/* The field it came from, in the same form. A watcher would note it as a new reading; this is the same one. */
	$field = '' !== (string) $k['field'] ? (string) $k['field'] : 'rrt_audio';
	if ( ! in_array( $field, rrt_kept_fields(), true ) ) {
		$field = 'rrt_audio';
	}
	if ( $att ) {
		$value = 'id' === $k['kind'] || 'rrt_audio' === $field ? $att : $url;
	} else {
		$value = '' !== (string) $k['raw'] ? (string) $k['raw'] : $url;
	}
	$GLOBALS['rrt_uploading'] = true;
	update_post_meta( $post_id, $field, $value );
	if ( $cap ) {
		update_post_meta( $post_id, 'rrt_captions', $cap );
	} elseif ( '' !== (string) $k['cap_raw'] ) {
		update_post_meta( $post_id, 'rrt_captions', $k['cap_raw'] );
	}
	$GLOBALS['rrt_uploading'] = false;
	if ( is_array( $k['music'] ) && $k['music'] ) {
		$mu = $k['music'];
		if ( $att ) {
			$mu['audio'] = $att;
		}
		update_post_meta( $post_id, '_rrt_music_used', $mu );
	}
	if ( is_array( $k['pick'] ) && $k['pick'] ) {
		$pk          = $k['pick'];
		$pk['audio'] = rrt_reading_audio( $post_id )['url'];
		update_post_meta( $post_id, '_rrt_soundbite', $pk );
	}
	/* Its listening history carries on: the same Recordings row is current again. */
	if ( function_exists( 'rrt_table' ) && function_exists( 'rrt_stats_on' ) && rrt_stats_on() ) {
		global $wpdb;
		$t   = rrt_table( 'recordings' );
		$row = $k['rec'] ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE id = %d AND post_id = %d", $k['rec'], $post_id ) ) : 0; // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $row ) {
			$wpdb->update( $t, array( 'is_current' => 0 ), array( 'post_id' => $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$set = array( 'is_current' => 1 );
			if ( $att ) {
				$set['attachment_id'] = $att;
				$set['url']           = $url;
				$set['file_name']     = wp_basename( (string) get_attached_file( $att ) );
			}
			$wpdb->update( $t, $set, array( 'id' => $row ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} elseif ( function_exists( 'rrt_note_manual_recording' ) ) {
			rrt_note_manual_recording( $post_id, 'manual' );
		}
	}
	$keep = array();
	foreach ( rrt_kept_list( $post_id ) as $x ) {
		if ( (string) $x['id'] !== (string) $k['id'] ) {
			$keep[] = $x;
		}
	}
	rrt_kept_save( $post_id, $keep );
	rrt_kept_delete_files( $k );
	rrt_kept_changed( $post_id );
	return array(
		'swapped' => $swapped,
		'moved'   => $moved,
	);
}

/**
 * Deletes a reading kept aside for good: its files and its record. The
 * listening history stays with its Recordings row.
 */
function rrt_kept_delete( $post_id, $id ) {
	$k = rrt_kept_find( $post_id, $id );
	if ( ! $k ) {
		return false;
	}
	rrt_kept_delete_files( $k );
	$keep = array();
	foreach ( rrt_kept_list( $post_id ) as $x ) {
		if ( (string) $x['id'] !== (string) $k['id'] ) {
			$keep[] = $x;
		}
	}
	rrt_kept_save( $post_id, $keep );
	if ( ! empty( $k['rec'] ) && function_exists( 'rrt_table' ) ) {
		global $wpdb;
		$wpdb->update( rrt_table( 'recordings' ), array( 'url' => '' ), array( 'id' => (int) $k['rec'], 'is_current' => 0 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
	return true;
}

/** A post's readings kept aside go with the post when it is deleted for good. */
add_action(
	'before_delete_post',
	function ( $post_id ) {
		foreach ( rrt_kept_list( $post_id ) as $k ) {
			rrt_kept_delete_files( $k );
		}
	}
);

/** The address that plays or downloads a reading kept aside, for people who can edit its post. */
function rrt_kept_audio_url( $post_id, $id, $download = false ) {
	return add_query_arg(
		array(
			'action'   => 'rrt_kept_audio',
			'post'     => (int) $post_id,
			'id'       => $id,
			'download' => $download ? 1 : null,
			'_wpnonce' => wp_create_nonce( 'rrt_kept_audio_' . (int) $post_id ),
		),
		admin_url( 'admin-post.php' )
	);
}

/* Plays or downloads a reading kept aside. Byte ranges are answered, so the player can seek. */
add_action(
	'admin_post_rrt_kept_audio',
	function () {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce is checked on the next lines, after the id it names is read.
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'rrt_kept_audio_' . $post_id ) || ! rrt_kept_can( $post_id ) ) {
			status_header( 403 );
			exit;
		}
		$k = rrt_kept_find( $post_id, isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : '' );
		if ( ! $k || empty( $k['audio']['file'] ) ) {
			status_header( 404 );
			exit;
		}
		$path = rrt_kept_dir() . basename( (string) $k['audio']['file'] );
		if ( ! file_exists( $path ) ) {
			status_header( 410 );
			exit;
		}
		$size  = (int) filesize( $path );
		$start = 0;
		$end   = $size - 1;
		$range = isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '';
		if ( $range && preg_match( '/^bytes=(\d*)-(\d*)$/', $range, $mm ) && ( '' !== $mm[1] || '' !== $mm[2] ) ) {
			if ( '' === $mm[1] ) {
				$start = max( 0, $size - (int) $mm[2] );
			} else {
				$start = (int) $mm[1];
				$end   = '' === $mm[2] ? $end : min( $end, (int) $mm[2] );
			}
			if ( $start > $end || $start >= $size ) {
				status_header( 416 );
				header( 'Content-Range: bytes */' . $size );
				exit;
			}
			status_header( 206 );
			header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $size );
		}
		nocache_headers();
		do_action( 'litespeed_control_set_nocache', 'readings kept aside are private' );
		header( 'Content-Type: ' . ( '' !== (string) $k['audio']['mime'] ? $k['audio']['mime'] : 'application/octet-stream' ) );
		header( 'Accept-Ranges: bytes' );
		header( 'Content-Length: ' . ( $end - $start + 1 ) );
		header( 'Cache-Control: no-store, private' );
		header( 'X-Content-Type-Options: nosniff' );
		if ( ! empty( $_GET['download'] ) ) {
			$name = wp_basename( (string) $k['audio']['rel'] );
			$name = '' !== $name ? $name : sanitize_file_name( $k['title'] ) . '.' . pathinfo( $path, PATHINFO_EXTENSION );
			header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', $name ) . '"' );
		}
		$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fseek( $fh, $start );
		$left = $end - $start + 1;
		while ( $left > 0 && ! feof( $fh ) ) {
			$chunk = fread( $fh, (int) min( 1048576, $left ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$left -= strlen( $chunk );
			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput
			flush();
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}
);

/* Take off the post, Put back and Delete for good. */
add_action(
	'admin_post_rrt_kept',
	function () {
		$post_id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is checked on the next lines, after the post number it names is read.
		check_admin_referer( 'rrt_kept_' . $post_id );
		if ( ! rrt_kept_can( $post_id ) ) {
			wp_die( esc_html__( 'Not allowed.', 'foxfire-studio' ) );
		}
		$do    = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$id    = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		$title = wp_strip_all_tags( get_the_title( $post_id ) );
		$msg   = array( 'ok', '' );
		if ( 'off' === $do ) {
			$r = rrt_kept_take_off( $post_id );
			if ( is_wp_error( $r ) ) {
				$msg = array( 'error', $r->get_error_message() );
			} else {
				$msg = array( 'ok', sprintf( /* translators: %s: post title. */ __( 'The reading of "%s" is off the post and kept aside below. The post no longer shows the player, and the podcast feed no longer lists it.', 'foxfire-studio' ), $title ) );
				if ( 'outside' === $r['stays'] ) {
					$msg[1] .= ' ' . __( 'Its file is not in the Media Library, so only the field was cleared; the file itself is still at its address.', 'foxfire-studio' );
				} elseif ( 'shared' === $r['stays'] ) {
					$msg[1] .= ' ' . __( 'Its file is also another post\'s reading, so it stays in the Media Library; only this post\'s field was cleared.', 'foxfire-studio' );
				}
			}
		} elseif ( 'back' === $do ) {
			$r = rrt_kept_put_back( $post_id, $id );
			if ( is_wp_error( $r ) ) {
				$msg = array( 'error', $r->get_error_message() );
			} else {
				$msg = array( 'ok', sprintf( /* translators: %s: post title. */ __( 'The reading is back on "%s".', 'foxfire-studio' ), $title ) );
				if ( $r['swapped'] ) {
					$msg[1] .= ' ' . __( 'The reading it had until now is kept aside in its place.', 'foxfire-studio' );
				}
				if ( $r['moved'] ) {
					$msg[1] .= ' ' . __( 'Another file had taken its old address, so it has a new one.', 'foxfire-studio' );
				}
			}
		} elseif ( 'delete' === $do ) {
			$msg = rrt_kept_delete( $post_id, $id ) ? array( 'ok', __( 'The reading was deleted for good. Its listening history stays.', 'foxfire-studio' ) ) : array( 'error', __( 'That reading is no longer kept aside.', 'foxfire-studio' ) );
		}
		set_transient( 'rrt_kept_msg_' . get_current_user_id(), $msg, 120 );
		$back = isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( $_POST['back'] ) ) : '';
		wp_safe_redirect( $back ? $back : admin_url( 'admin.php?page=rrt-recordings#rrt-kept' ) );
		exit;
	}
);

/** Shows, once, what the last Take off, Put back or Delete did. */
function rrt_kept_notice() {
	$key = 'rrt_kept_msg_' . get_current_user_id();
	$msg = get_transient( $key );
	if ( ! is_array( $msg ) || '' === (string) $msg[1] ) {
		return;
	}
	delete_transient( $key );
	echo '<div class="notice ' . ( 'error' === $msg[0] ? 'notice-error' : 'notice-success' ) . ' rrt-kept-msg"><p>' . esc_html( $msg[1] ) . '</p></div>';
}

/** One of the forms: Take off the post, Put back or Delete for good. */
function rrt_kept_form( $post_id, $do, $label, $id = '', $back = '', $primary = false ) {
	$confirm = '';
	if ( 'delete' === $do ) {
		$confirm = __( 'Delete this reading for good? It cannot be put back afterwards. Its listening history stays.', 'foxfire-studio' );
	} elseif ( 'off' === $do ) {
		$confirm = __( 'Take this reading off the post? It is kept aside, and you can put it back at any time.', 'foxfire-studio' );
	}
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="rrt-kept-form" style="display:inline"' . ( $confirm ? ' data-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>';
	echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'rrt_kept_' . (int) $post_id ) ) . '"><input type="hidden" name="action" value="rrt_kept"><input type="hidden" name="do" value="' . esc_attr( $do ) . '"><input type="hidden" name="post" value="' . (int) $post_id . '">';
	if ( '' !== $id ) {
		echo '<input type="hidden" name="id" value="' . esc_attr( $id ) . '">';
	}
	if ( '' !== $back ) {
		echo '<input type="hidden" name="back" value="' . esc_attr( $back ) . '">';
	}
	echo '<button type="submit" class="button' . ( $primary ? ' button-primary' : '' ) . ( 'delete' === $do ? ' button-link-delete' : '' ) . '" data-kept="' . esc_attr( $do ) . '">' . esc_html( $label ) . '</button></form> ';
}

/* The confirmation for Take off and Delete for good, on the screens that show the forms. */
add_action(
	'admin_footer',
	function () {
		echo "<script>document.addEventListener('submit',function(e){var f=e.target;if(f.classList&&f.classList.contains('rrt-kept-form')){var q=f.getAttribute('data-confirm');if(q&&!window.confirm(q)){e.preventDefault();}}},true);</script>\n";
	}
);

/** What a kept record says about where its file is. */
function rrt_kept_where( $k ) {
	if ( 'outside' === $k['stays'] ) {
		return __( 'The file is outside the Media Library and stays at its address; only the field was cleared.', 'foxfire-studio' );
	}
	if ( 'shared' === $k['stays'] ) {
		return __( 'The file is also another post\'s reading, so it stays in the Media Library; only this post\'s field was cleared.', 'foxfire-studio' );
	}
	return '';
}

/** The section on Foxfire Studio, Recordings: every reading kept aside. */
function rrt_kept_admin_section() {
	$rows = array();
	foreach ( rrt_kept_posts() as $pid ) {
		if ( ! rrt_kept_can( $pid ) ) {
			continue;
		}
		foreach ( rrt_kept_list( $pid ) as $k ) {
			$rows[] = array( $pid, $k );
		}
	}
	usort(
		$rows,
		function ( $a, $b ) {
			return (int) $b[1]['at'] - (int) $a[1]['at'];
		}
	);
	$back = admin_url( 'admin.php?page=rrt-recordings#rrt-kept' );
	echo '<h2 id="rrt-kept">' . esc_html__( 'Kept aside', 'foxfire-studio' ) . '</h2>';
	echo '<p>' . esc_html__( 'Readings taken off their post. Each is kept in a folder visitors cannot open, until you put it back or delete it for good. Put back returns it to the Media Library at the address it had, with its captions, so it is the same episode in the podcast feed and its listening history carries on.', 'foxfire-studio' ) . '</p>';
	echo '<table class="widefat striped rrt-table" id="rrt-t-kept"><thead><tr><th>' . esc_html__( 'Post', 'foxfire-studio' ) . '</th><th>' . esc_html__( 'Reading', 'foxfire-studio' ) . '</th><th class="num">' . esc_html__( 'Length', 'foxfire-studio' ) . '</th><th class="num">' . esc_html__( 'Size', 'foxfire-studio' ) . '</th><th>' . esc_html__( 'Taken off', 'foxfire-studio' ) . '</th><th></th></tr></thead><tbody>';
	foreach ( $rows as $row ) {
		list( $pid, $k ) = $row;
		$title = get_the_title( $pid );
		echo '<tr data-kept="' . esc_attr( $k['id'] ) . '"><td><a href="' . esc_url( rrt_dash_url( 'rrt-readings', array( 'post' => $pid ) ) ) . '">' . esc_html( '' !== $title ? $title : $k['title'] ) . '</a>';
		if ( '' !== rrt_reading_audio( $pid )['url'] ) {
			echo '<br><span class="rrt-small">' . esc_html__( 'The post has another reading now; Put back swaps them.', 'foxfire-studio' ) . '</span>';
		}
		echo '</td><td>';
		if ( ! empty( $k['audio']['file'] ) ) {
			echo '<audio class="rrt-kept-audio" controls preload="none" src="' . esc_url( rrt_kept_audio_url( $pid, $k['id'] ) ) . '"></audio><br>';
			echo '<span class="rrt-small">' . esc_html( wp_basename( (string) $k['audio']['rel'] ) ) . ( ! empty( $k['captions'] ) ? ' · ' . esc_html__( 'with captions', 'foxfire-studio' ) : '' ) . '</span>';
		} else {
			echo '<span class="rrt-small">' . esc_html( wp_basename( (string) $k['url'] ) ) . '<br>' . esc_html( rrt_kept_where( $k ) ) . '</span>';
		}
		echo '</td>';
		echo '<td class="num">' . esc_html( $k['seconds'] ? rrt_clock( $k['seconds'] ) : '' ) . '</td>';
		echo '<td class="num">' . esc_html( ! empty( $k['audio']['bytes'] ) ? size_format( (int) $k['audio']['bytes'], 1 ) : '' ) . '</td>';
		$by = get_userdata( (int) $k['by'] );
		echo '<td>' . esc_html( wp_date( 'M j, Y, g:i a', (int) $k['at'] ) ) . ( $by ? '<br><span class="rrt-small">' . esc_html( $by->display_name ) . '</span>' : '' ) . '</td><td>';
		rrt_kept_form( $pid, 'back', __( 'Put back', 'foxfire-studio' ), $k['id'], $back, true );
		if ( ! empty( $k['audio']['file'] ) ) {
			echo '<a class="button" href="' . esc_url( rrt_kept_audio_url( $pid, $k['id'], true ) ) . '">' . esc_html__( 'Download', 'foxfire-studio' ) . '</a> ';
		}
		rrt_kept_form( $pid, 'delete', __( 'Delete for good', 'foxfire-studio' ), $k['id'], $back );
		echo '</td></tr>';
	}
	if ( ! $rows ) {
		echo '<tr><td colspan="6">' . esc_html__( 'Nothing kept aside. A reading\'s page under Foxfire Studio, Readings has Take off the post.', 'foxfire-studio' ) . '</td></tr>';
	}
	echo '</tbody></table>';
}

/**
 * The line on a reading's page: the reading on the post with Take off the
 * post, and any readings kept aside with Put back.
 */
function rrt_kept_reading_section( $post_id ) {
	if ( ! rrt_kept_can( $post_id ) ) {
		return;
	}
	$audio = rrt_reading_audio( $post_id );
	$list  = rrt_kept_list( $post_id );
	if ( '' === $audio['url'] && ! $list ) {
		return;
	}
	$back = rrt_dash_url( 'rrt-readings', array( 'post' => $post_id ) ) . '#rrt-keep';
	echo '<div class="rrt-keep" id="rrt-keep">';
	if ( '' !== $audio['url'] ) {
		echo '<p>' . esc_html( sprintf( /* translators: %s: file name. */ __( 'On the post: %s', 'foxfire-studio' ), wp_basename( strtok( $audio['url'], '?' ) ) ) ) . ' ';
		rrt_kept_form( $post_id, 'off', __( 'Take off the post', 'foxfire-studio' ), '', $back );
		echo '<span class="rrt-small">' . esc_html__( 'Keeps it aside, private, until you put it back or delete it.', 'foxfire-studio' ) . '</span></p>';
		/* Storage offload (1.23.0): where listeners get it from. */
		if ( function_exists( 'rrt_off_post_line' ) ) {
			$off = rrt_off_post_line( $post_id );
			if ( '' !== $off ) {
				echo '<p class="rrt-small">' . esc_html( $off ) . '</p>';
			}
		}
	}
	foreach ( $list as $k ) {
		echo '<p class="rrt-kept-line">' . esc_html( sprintf( /* translators: 1: file name, 2: date. */ __( 'Kept aside: %1$s, taken off %2$s.', 'foxfire-studio' ), wp_basename( ! empty( $k['audio']['rel'] ) ? (string) $k['audio']['rel'] : (string) $k['url'] ), wp_date( 'M j, Y', (int) $k['at'] ) ) ) . ' ';
		rrt_kept_form( $post_id, 'back', '' !== $audio['url'] ? __( 'Put back (swap)', 'foxfire-studio' ) : __( 'Put back', 'foxfire-studio' ), $k['id'], $back, '' === $audio['url'] );
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=rrt-recordings#rrt-kept' ) ) . '">' . esc_html__( 'Listen or delete', 'foxfire-studio' ) . '</a></p>';
	}
	echo '</div>';
}

/** The Recordings row ids that belong to a reading kept aside, for the Recordings table. */
function rrt_kept_rec_ids() {
	$out = array();
	foreach ( rrt_kept_posts() as $pid ) {
		foreach ( rrt_kept_list( $pid ) as $k ) {
			if ( ! empty( $k['rec'] ) ) {
				$out[ (int) $k['rec'] ] = true;
			}
		}
	}
	return $out;
}
