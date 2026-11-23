<?php
/**
 * Send to my computer (0.21.0).
 *
 * A phone records well but cannot run the full Whisper review, which needs
 * more memory than phones give a web page. From the phone, Send to my
 * computer uploads the take's original WAV to a folder that visitors cannot
 * open. The next time the same person opens the teleprompter on a computer,
 * it offers the take and opens it for review, just as it recovers an
 * unfinished one. A sent take reaches only the account that sent it, through
 * a REST route that checks who is asking.
 *
 * A sent take stays until it is opened and then used, downloaded or deleted
 * on the computer, and 14 days at most. The list lives in the sender's own
 * user meta (_rrt_sent), so no table is needed.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** How long a sent take waits on the site, in days. */
define( 'RRT_SENT_DAYS', 14 );

/** The most takes one person can have waiting at once. */
define( 'RRT_SENT_MAX', 5 );

/** The folder that holds sent takes. */
function rrt_sent_dir() {
	$up = wp_upload_dir( null, false );
	return trailingslashit( $up['basedir'] ) . 'rrt-sent/';
}

/** Makes the folder, with files that refuse every direct request. */
function rrt_sent_dir_ready() {
	$dir = rrt_sent_dir();
	if ( ! wp_mkdir_p( $dir ) ) {
		return false;
	}
	if ( ! file_exists( $dir . '.htaccess' ) ) {
		/* Apache 2.4 and LiteSpeed read the first; older Apache the second. */
		file_put_contents( $dir . '.htaccess', "# Foxfire Studio: takes sent from a phone are served only to the person who sent them.\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
	if ( ! file_exists( $dir . 'index.php' ) ) {
		file_put_contents( $dir . 'index.php', "<?php\n// Silence.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
	return true;
}

/** The audio types a sent take may be, by its first bytes: WAV, or what a phone's recorder makes when it cannot record losslessly. */
function rrt_sent_sniff( $path ) {
	$kind = function_exists( 'rrt_share_sniff' ) ? rrt_share_sniff( $path ) : null;
	if ( $kind && in_array( $kind[0], array( 'wav', 'webm', 'm4a', 'ogg' ), true ) ) {
		return $kind;
	}
	return null;
}

/**
 * A person's waiting takes, newest first, with expired ones (and any whose
 * file is gone) removed.
 *
 * @param int $user_id User.
 * @return array
 */
function rrt_sent_list( $user_id ) {
	$list = get_user_meta( $user_id, '_rrt_sent', true );
	$list = is_array( $list ) ? $list : array();
	$keep = array();
	$now  = time();
	foreach ( $list as $t ) {
		$file = rrt_sent_dir() . basename( (string) $t['file'] );
		/* A take begun in phone take mode (1.12.0) that was cut off before any audio arrived is dropped. */
		$empty = ! empty( $t['live'] ) && (int) $t['bytes'] <= 44 && ( function_exists( 'rrt_live_state' ) ? 'live' !== rrt_live_state( $t, $now ) : true );
		if ( (int) $t['ends'] <= $now || ! file_exists( $file ) || $empty ) {
			if ( file_exists( $file ) ) {
				wp_delete_file( $file );
			}
			continue;
		}
		$keep[] = $t;
	}
	if ( count( $keep ) !== count( $list ) ) {
		rrt_sent_save( $user_id, $keep );
	}
	usort(
		$keep,
		function ( $a, $b ) {
			return (int) $b['sent'] - (int) $a['sent'];
		}
	);
	return $keep;
}

/** Saves a person's list, removing the meta when it is empty. */
function rrt_sent_save( $user_id, $list ) {
	if ( $list ) {
		update_user_meta( $user_id, '_rrt_sent', array_values( $list ) );
	} else {
		delete_user_meta( $user_id, '_rrt_sent' );
	}
}

/** One of the current person's waiting takes by id, or null. */
function rrt_sent_find( $id ) {
	$id = preg_replace( '/[^a-zA-Z0-9]/', '', (string) $id );
	foreach ( rrt_sent_list( get_current_user_id() ) as $t ) {
		if ( $id !== '' && hash_equals( (string) $t['id'], $id ) ) {
			return $t;
		}
	}
	return null;
}

/** Deletes one of a person's waiting takes and its file. Returns true if it was there. */
function rrt_sent_delete( $user_id, $id ) {
	$list  = get_user_meta( $user_id, '_rrt_sent', true );
	$list  = is_array( $list ) ? $list : array();
	$found = false;
	$keep  = array();
	foreach ( $list as $t ) {
		if ( (string) $t['id'] === (string) $id ) {
			$found = true;
			$file  = rrt_sent_dir() . basename( (string) $t['file'] );
			if ( file_exists( $file ) ) {
				wp_delete_file( $file );
			}
			continue;
		}
		$keep[] = $t;
	}
	if ( $found ) {
		rrt_sent_save( $user_id, $keep );
	}
	return $found;
}

/** What the page is told about a waiting take. Never the file name. */
function rrt_sent_out( $t ) {
	$post = get_post( (int) $t['post'] );
	return array(
		'id'      => (string) $t['id'],
		'post'    => (int) $t['post'],
		'title'   => $post ? wp_strip_all_tags( get_the_title( $post ) ) : (string) $t['title'],
		'seconds' => (float) $t['seconds'],
		'bytes'   => (int) $t['bytes'],
		'words'   => (int) $t['words'],
		'drill'   => (string) $t['drill'],
		'drill_s' => (int) $t['drill_s'],
		'focus'   => (string) $t['focus'],
		'setup'   => isset( $t['setup'] ) ? (string) $t['setup'] : '',
		'setup_label' => isset( $t['setup_label'] ) ? (string) $t['setup_label'] : '',
		'sent'    => (int) $t['sent'],
		'when'    => wp_date( 'D M j, g:i a', (int) $t['sent'] ),
		'ends'    => wp_date( 'l, F j', (int) $t['ends'] ),
		'type'    => (string) $t['type'],
		/* Phone take mode (1.12.0): done, live (still arriving) or cut (stopped partway); when the site last heard from it. */
		'state'   => function_exists( 'rrt_live_state' ) ? rrt_live_state( $t ) : 'done',
		'live'    => ! empty( $t['live'] ),
		'cut'     => ! empty( $t['cut'] ),
		'updated' => (int) ( $t['updated'] ?? $t['sent'] ),
		'rate'    => (int) ( $t['rate'] ?? 0 ),
		'bits'    => (int) ( $t['bits'] ?? 0 ),
	);
}

/** Whether the current person may send and open takes. */
function rrt_sent_can() {
	return is_user_logged_in() && current_user_can( 'edit_posts' );
}

/** What the teleprompter needs: where to send, and what is waiting. */
function rrt_sent_boot() {
	if ( ! rrt_sent_can() ) {
		return null;
	}
	return array(
		'rest'    => esc_url_raw( rest_url( 'rrt/v1/' ) ),
		'nonce'   => wp_create_nonce( 'wp_rest' ),
		'waiting' => array_map( 'rrt_sent_out', rrt_sent_list( get_current_user_id() ) ),
		'max'     => wp_max_upload_size(),
		'list'    => admin_url( 'admin.php?page=rrt-recordings#rrt-sent' ),
	);
}

add_action(
	'rest_api_init',
	function () {
		$can = function () {
			return rrt_sent_can();
		};
		register_rest_route(
			'rrt/v1',
			'/sent',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => $can,
					'callback'            => function () {
						return rest_ensure_response( array( 'waiting' => array_map( 'rrt_sent_out', rrt_sent_list( get_current_user_id() ) ) ) );
					},
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => function ( $req ) {
						$p = (int) $req['post'];
						return rrt_sent_can() && $p > 0 && get_post( $p ) && current_user_can( 'edit_post', $p );
					},
					'callback'            => 'rrt_rest_sent_create',
				),
			)
		);
		$id = '(?P<id>[a-zA-Z0-9]{20,40})';
		register_rest_route(
			'rrt/v1',
			'/sent/' . $id . '/audio',
			array(
				'methods'             => 'GET',
				'permission_callback' => function ( $req ) {
					return rrt_sent_can() && null !== rrt_sent_find( $req['id'] );
				},
				'callback'            => 'rrt_rest_sent_audio',
			)
		);
		register_rest_route(
			'rrt/v1',
			'/sent/' . $id,
			array(
				'methods'             => 'DELETE',
				'permission_callback' => function ( $req ) {
					return rrt_sent_can() && null !== rrt_sent_find( $req['id'] );
				},
				'callback'            => function ( $req ) {
					return rest_ensure_response( array( 'deleted' => rrt_sent_delete( get_current_user_id(), $req['id'] ) ) );
				},
			)
		);
	}
);

/** Saves a take sent from a phone. */
function rrt_rest_sent_create( $req ) {
	$user = get_current_user_id();
	$post = (int) $req['post'];
	if ( count( rrt_sent_list( $user ) ) >= RRT_SENT_MAX ) {
		return new WP_Error( 'rrt_sent', sprintf( /* translators: %d: number of takes. */ __( '%d takes are already waiting for your computer. Open or delete one there first.', 'foxfire-studio' ), RRT_SENT_MAX ), array( 'status' => 409 ) );
	}
	$files = $req->get_file_params();
	if ( empty( $files['audio'] ) || ! empty( $files['audio']['error'] ) ) {
		return new WP_Error( 'rrt_sent', sprintf( /* translators: %s: largest upload size. */ __( 'The take did not arrive. It may be larger than this site accepts (%s).', 'foxfire-studio' ), size_format( wp_max_upload_size() ) ), array( 'status' => 400 ) );
	}
	$kind = rrt_sent_sniff( $files['audio']['tmp_name'] );
	if ( ! $kind ) {
		return new WP_Error( 'rrt_sent', __( 'That is not a recording the teleprompter can open.', 'foxfire-studio' ), array( 'status' => 400 ) );
	}
	if ( ! rrt_sent_dir_ready() ) {
		return new WP_Error( 'rrt_sent', __( 'The folder for sent takes could not be made. Check that the uploads folder is writable.', 'foxfire-studio' ), array( 'status' => 500 ) );
	}
	$id   = wp_generate_password( 32, false, false );
	$name = $id . '.' . $kind[0];
	$dest = rrt_sent_dir() . $name;
	if ( ! @move_uploaded_file( $files['audio']['tmp_name'], $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
		/* Tests and some hosts hand over a file that is not an HTTP upload. */
		if ( ! @rename( $files['audio']['tmp_name'], $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			return new WP_Error( 'rrt_sent', __( 'The take could not be saved.', 'foxfire-studio' ), array( 'status' => 500 ) );
		}
	}
	$now  = time();
	$drill = sanitize_key( (string) $req['drill'] );
	$t    = array(
		'id'      => $id,
		'post'    => $post,
		'title'   => mb_substr( wp_strip_all_tags( get_the_title( $post ) ), 0, 250 ),
		'file'    => $name,
		'type'    => $kind[1],
		'bytes'   => (int) filesize( $dest ),
		'seconds' => is_numeric( $req['seconds'] ) ? max( 0, (float) $req['seconds'] ) : 0,
		'words'   => is_numeric( $req['words'] ) ? max( 0, (int) $req['words'] ) : 0,
		'drill'   => 'opening' === $drill ? 'opening' : '',
		'drill_s' => is_numeric( $req['drill_s'] ) ? max( 0, (int) $req['drill_s'] ) : 0,
		'focus'   => substr( sanitize_key( (string) $req['focus'] ), 0, 20 ),
		/* The device and microphone it was recorded on (1.13.0), for the setup's EQ. */
		'setup'   => rrt_eq_clean_key( $req['setup'] ),
		'setup_label' => mb_substr( sanitize_text_field( (string) $req['setup_label'] ), 0, 120 ),
		'sent'    => $now,
		'ends'    => $now + RRT_SENT_DAYS * DAY_IN_SECONDS,
	);
	$list   = get_user_meta( $user, '_rrt_sent', true );
	$list   = is_array( $list ) ? $list : array();
	$list[] = $t;
	rrt_sent_save( $user, $list );
	return rest_ensure_response( array( 'take' => rrt_sent_out( $t ) ) );
}

/** Sends a waiting take's audio to the person who sent it. Nothing is cached. */
function rrt_rest_sent_audio( $req ) {
	$t    = rrt_sent_find( $req['id'] );
	$path = rrt_sent_dir() . basename( (string) $t['file'] );
	if ( ! file_exists( $path ) ) {
		return new WP_Error( 'rrt_sent', __( 'That take is no longer on the site.', 'foxfire-studio' ), array( 'status' => 410 ) );
	}
	nocache_headers();
	header( 'Content-Type: ' . $t['type'] );
	header( 'Content-Length: ' . filesize( $path ) );
	header( 'Cache-Control: no-store, private' );
	header( 'X-Content-Type-Options: nosniff' );
	readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit;
}

/** The hourly job (shared with shared takes): takes past 14 days are deleted. */
function rrt_sent_hourly() {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$users = $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM $wpdb->usermeta WHERE meta_key = %s", '_rrt_sent' ) );
	foreach ( (array) $users as $u ) {
		rrt_sent_list( (int) $u );
	}
}
add_action( 'rrt_share_hourly', 'rrt_sent_hourly' );

/** A person's waiting takes go when their account is deleted. */
add_action(
	'delete_user',
	function ( $user_id ) {
		$list = get_user_meta( $user_id, '_rrt_sent', true );
		foreach ( is_array( $list ) ? $list : array() as $t ) {
			rrt_sent_delete( $user_id, $t['id'] );
		}
	}
);

/** The table on Foxfire Studio, Recordings: takes waiting for this computer. */
function rrt_sent_admin_section() {
	if ( ! rrt_sent_can() ) {
		return;
	}
	$list = rrt_sent_list( get_current_user_id() );
	echo '<h2 id="rrt-sent">' . esc_html__( 'Sent from your phone', 'foxfire-studio' ) . '</h2>';
	echo '<p>' . esc_html( sprintf( /* translators: %d: number of days. */ __( 'Takes recorded on a phone in phone take mode, or sent with Send to my computer, waiting to be reviewed here. Each is deleted from the site once you use, download or delete it in the teleprompter, and after %d days at most.', 'foxfire-studio' ), (int) RRT_SENT_DAYS ) ) . '</p>';
	echo '<table class="widefat striped rrt-table" id="rrt-t-sent"><thead><tr><th>' . esc_html__( 'Post', 'foxfire-studio' ) . '</th><th class="num">' . esc_html__( 'Length', 'foxfire-studio' ) . '</th><th class="num">' . esc_html__( 'Size', 'foxfire-studio' ) . '</th><th>' . esc_html__( 'Sent', 'foxfire-studio' ) . '</th><th>' . esc_html__( 'Deleted by', 'foxfire-studio' ) . '</th><th></th></tr></thead><tbody>';
	foreach ( $list as $t ) {
		$o    = rrt_sent_out( $t );
		$open = add_query_arg( array( 'rrt_sent' => $o['id'] ), rrt_url( $o['post'] ) );
		$note = '';
		if ( $o['drill'] ) {
			$note = __( '(opening drill)', 'foxfire-studio' );
		} elseif ( 'live' === $o['state'] ) {
			$note = __( '(still being recorded on the phone)', 'foxfire-studio' );
		} elseif ( 'cut' === $o['state'] ) {
			$note = __( '(cut off partway)', 'foxfire-studio' );
		}
		echo '<tr><td>' . esc_html( $o['title'] ) . ( '' !== $note ? ' <span class="description">' . esc_html( $note ) . '</span>' : '' ) . '</td>';
		echo '<td class="num">' . esc_html( rrt_clock( $o['seconds'] ) ) . '</td>';
		echo '<td class="num">' . esc_html( size_format( $o['bytes'], 1 ) ) . '</td>';
		echo '<td>' . esc_html( $o['when'] ) . '</td><td>' . esc_html( $o['ends'] ) . '</td>';
		echo '<td><a class="button button-primary" href="' . esc_url( $open ) . '">' . esc_html__( 'Open in the teleprompter', 'foxfire-studio' ) . '</a> ';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
		wp_nonce_field( 'rrt_sent_delete' );
		echo '<input type="hidden" name="action" value="rrt_sent_delete"><input type="hidden" name="id" value="' . esc_attr( $o['id'] ) . '">';
		echo '<button type="submit" class="button">' . esc_html__( 'Delete', 'foxfire-studio' ) . '</button></form></td></tr>';
	}
	if ( ! $list ) {
		echo '<tr><td colspan="6">' . esc_html__( 'Nothing waiting. On a phone, phone take mode saves each take here as it is recorded.', 'foxfire-studio' ) . '</td></tr>';
	}
	echo '</tbody></table>';
}

add_action(
	'admin_post_rrt_sent_delete',
	function () {
		check_admin_referer( 'rrt_sent_delete' );
		if ( ! rrt_sent_can() ) {
			wp_die( esc_html__( 'Not allowed.', 'foxfire-studio' ) );
		}
		$id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		rrt_sent_delete( get_current_user_id(), $id );
		wp_safe_redirect( admin_url( 'admin.php?page=rrt-recordings#rrt-sent' ) );
		exit;
	}
);
