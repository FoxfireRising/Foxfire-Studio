<?php
/**
 * The Reading player block and the shortcodes (1.28.0), run inside the test WordPress:
 *   php wp-cli.phar --path=<wordpress> eval-file tests/php/block.php
 * The block's registration, what it draws in a post, for another reading, in a
 * widget area and for readings a visitor may not hear; the top-of-post player
 * left out when the post or the template places it; each reading drawn once
 * per page; both shortcodes; the preview note; read along only on the
 * reading's own page; the editor's list of readings; Settings' wording.
 * Prints one line per check and exits 1 if any fail. Leaves nothing behind.
 */

$GLOBALS['bk_pass'] = 0;
$GLOBALS['bk_fail'] = array();

function bk_t( $ok, $name, $detail = '' ) {
	if ( $ok ) {
		++$GLOBALS['bk_pass'];
		echo "ok   $name\n";
	} else {
		$GLOBALS['bk_fail'][] = $name;
		echo "FAIL $name" . ( '' !== (string) $detail ? ': ' . $detail : '' ) . "\n";
	}
}

wp_set_current_user( 1 );
$bk_was = get_option( 'rrt_settings', null );
$GLOBALS['bk_posts'] = array();
register_shutdown_function(
	function () use ( $bk_was ) {
		foreach ( $GLOBALS['bk_posts'] as $p ) {
			foreach ( get_children( array( 'post_parent' => $p, 'post_type' => 'attachment', 'numberposts' => -1, 'post_status' => 'any' ) ) as $a ) {
				wp_delete_attachment( $a->ID, true );
			}
			wp_delete_post( $p, true );
		}
		if ( null === $bk_was ) {
			delete_option( 'rrt_settings' );
		} else {
			update_option( 'rrt_settings', $bk_was );
		}
		echo $GLOBALS['bk_pass'] . ' passed, ' . count( $GLOBALS['bk_fail'] ) . " failed\n";
		if ( $GLOBALS['bk_fail'] ) {
			exit( 1 );
		}
	}
);

require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

function bk_settings( $show, $position = 'top' ) {
	$s                       = rrt_settings();
	$s['player']['show']     = $show;
	$s['player']['position'] = $position;
	$s['player']['follow']   = true;
	update_option( 'rrt_settings', $s );
}
bk_settings( 'everyone' );

/** A post, with a short reading and captions unless $reading is false. */
function bk_post( $title, $content, $reading = true, $status = 'publish' ) {
	$id                    = wp_insert_post(
		array(
			'post_title'   => $title,
			'post_status'  => $status,
			'post_content' => $content,
		)
	);
	$GLOBALS['bk_posts'][] = $id;
	if ( $reading ) {
		foreach ( array(
			'rrt_audio'    => array( 'bk-reading-' . $id . '.mp3', "ID3\x03\x00\x00\x00\x00\x00\x00" . str_repeat( "\xFF\xFB\x10\xC4" . str_repeat( "\x00", 100 ), 40 ) ),
			'rrt_captions' => array( 'bk-reading-' . $id . '.vtt', "WEBVTT\n\n00:00:00.000 --> 00:00:01.000\nWords.\n" ),
		) as $key => $f ) {
			$tmp = wp_tempnam( $f[0] );
			file_put_contents( $tmp, $f[1] );
			$att = media_handle_sideload(
				array(
					'name'     => $f[0],
					'tmp_name' => $tmp,
				),
				$id
			);
			if ( ! is_wp_error( $att ) ) {
				update_post_meta( $id, $key, $att );
			}
		}
	}
	return $id;
}

/** Shows a post as its own page, as the front end would, and returns the filtered content. */
function bk_view( $id, $as = 0 ) {
	wp_set_current_user( $as );
	rrt_place_once( null, '' );
	$GLOBALS['wp_query']     = new WP_Query( array( 'p' => $id, 'post_type' => 'any' ) );
	$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
	$out                     = '';
	while ( have_posts() ) {
		the_post();
		$out = apply_filters( 'the_content', get_the_content() );
	}
	$GLOBALS['wp_query']->rewind_posts();
	return $out;
}

function bk_has( $html, $id ) {
	return false !== strpos( $html, '&quot;post&quot;:' . (int) $id . ',' );
}

function bk_count( $html ) {
	return substr_count( $html, 'class="rrt-player"' );
}

$bk_block = '<!-- wp:foxfire-studio/player /-->';

/* ---- Registration ---- */
$bk_type = WP_Block_Type_Registry::get_instance()->get_registered( 'foxfire-studio/player' );
bk_t( $bk_type instanceof WP_Block_Type, 'the Reading player block is registered' );
bk_t( $bk_type && is_callable( $bk_type->render_callback ), 'it is drawn by the site (a dynamic block)' );
bk_t( $bk_type && 3 === (int) $bk_type->api_version, 'it uses block API version 3, for the editor in a frame' );
bk_t( $bk_type && isset( $bk_type->attributes['reading'] ) && 0 === $bk_type->attributes['reading']['default'], 'its one attribute is the reading, 0 for this post' );
bk_t( $bk_type && in_array( 'postId', (array) $bk_type->uses_context, true ), 'it takes the post from its context (templates and Query Loops)' );
bk_t( $bk_type && 'Reading player' === $bk_type->title, 'it is called Reading player in the inserter' );
bk_t( shortcode_exists( 'foxfire_player' ) && shortcode_exists( 'reading_room_player' ), 'both shortcodes are registered' );
bk_t( wp_script_is( 'rrt-block-player', 'registered' ), 'the editor script is registered' );

/* ---- A post with the block ---- */
$bk_own = bk_post( 'A block in the middle', "<!-- wp:paragraph --><p>Before.</p><!-- /wp:paragraph -->\n$bk_block\n<!-- wp:paragraph --><p>After.</p><!-- /wp:paragraph -->" );
$bk_out = bk_view( $bk_own );
bk_t( 1 === bk_count( $bk_out ), 'a post with the block shows one player', bk_count( $bk_out ) );
bk_t( strpos( $bk_out, 'Before.' ) < strpos( $bk_out, 'rrt-player' ) && strpos( $bk_out, 'rrt-player' ) < strpos( $bk_out, 'After.' ), 'where the block is placed, in place of the top of the post' );
bk_t( false !== strpos( $bk_out, 'wp-block-foxfire-studio-player' ), 'inside the block\'s own wrapper' );
bk_t( false !== strpos( $bk_out, 'rrt-follow' ), 'read along is offered on the reading\'s own page' );

/* A post with no block keeps the top of the post. */
$bk_plain = bk_post( 'No block here', '<!-- wp:paragraph --><p>Only words.</p><!-- /wp:paragraph -->' );
$bk_out   = bk_view( $bk_plain );
bk_t( 1 === bk_count( $bk_out ) && strpos( $bk_out, 'rrt-player' ) < strpos( $bk_out, 'Only words.' ), 'a post without the block keeps the player at the top' );

/* Manual placement: no player unless placed. */
bk_settings( 'everyone', 'manual' );
bk_t( 0 === bk_count( bk_view( $bk_plain ) ), 'with Only where I put it, a post without the block has no player' );
bk_t( 1 === bk_count( bk_view( $bk_own ) ), 'and a post with the block has one' );
bk_settings( 'everyone' );

/* ---- Another reading ---- */
$bk_page = bk_post( 'A page of readings', "<!-- wp:foxfire-studio/player {\"reading\":$bk_plain} /-->\n<!-- wp:foxfire-studio/player {\"reading\":$bk_own} /-->", false );
$bk_out  = bk_view( $bk_page );
bk_t( 2 === bk_count( $bk_out ), 'a post without a reading can show two chosen readings', bk_count( $bk_out ) );
bk_t( bk_has( $bk_out, $bk_plain ) && bk_has( $bk_out, $bk_own ), 'each the reading chosen' );
bk_t( false === strpos( $bk_out, 'rrt-follow' ), 'away from their own posts, read along is not offered' );
bk_t( false !== strpos( $bk_out, 'rrt-transcript-btn' ), 'and Transcript still is' );

/* A block for another reading does not take the place of this post's own player at the top. */
$bk_other = bk_post( 'Its own reading and another', "<!-- wp:paragraph --><p>Text.</p><!-- /wp:paragraph -->\n<!-- wp:foxfire-studio/player {\"reading\":$bk_plain} /-->" );
$bk_out   = bk_view( $bk_other );
bk_t( 2 === bk_count( $bk_out ) && bk_has( $bk_out, $bk_other ) && bk_has( $bk_out, $bk_plain ), 'a block for another reading leaves this post\'s own player at the top', bk_count( $bk_out ) );

/* The same reading twice on one page is drawn once (the template and the content). */
$bk_twice = bk_post( 'Placed twice', "$bk_block\n$bk_block" );
$bk_out   = bk_view( $bk_twice );
bk_t( 2 === bk_count( $bk_out ), 'two blocks in the same content both draw (the content is one place)', bk_count( $bk_out ) );
rrt_place_once( null, '' );
$GLOBALS['wp_query']     = new WP_Query( array( 'p' => $bk_own ) );
$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
the_post();
$bk_tpl = do_blocks( $bk_block );
bk_t( 1 === bk_count( $bk_tpl ), 'a block in the template draws the post\'s reading' );
$bk_out = apply_filters( 'the_content', get_the_content() );
bk_t( 0 === bk_count( $bk_out ), 'then the same reading in the content is not drawn again', bk_count( $bk_out ) );
$bk_out = apply_filters( 'the_content', get_the_content() );
bk_t( 0 === bk_count( $bk_out ), 'nor on a second pass of the content' );
rrt_place_once( null, '' );
$bk_a = apply_filters( 'the_content', get_the_content() );
$bk_b = apply_filters( 'the_content', get_the_content() );
bk_t( 1 === bk_count( $bk_a ) && 1 === bk_count( $bk_b ), 'content run twice (as some themes and plugins do) shows the player both times' );

/* The template places it: the top of the post is left out. */
rrt_place_once( null, '' );
$GLOBALS['wp_query']     = new WP_Query( array( 'p' => $bk_plain ) );
$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
the_post();
$GLOBALS['_wp_current_template_content'] = '<!-- wp:post-title /-->' . $bk_block . '<!-- wp:post-content /-->';
bk_t( rrt_player_placed_by_hand( $bk_plain ), 'a block in the Single Posts template counts as placed by hand' );
bk_t( 0 === bk_count( apply_filters( 'the_content', get_the_content() ) ), 'so the top of the post is left out' );
$GLOBALS['_wp_current_template_content'] = '<!-- wp:post-title /--><!-- wp:foxfire-studio/player {"reading":' . $bk_own . '} /--><!-- wp:post-content /-->';
bk_t( ! rrt_player_placed_by_hand( $bk_plain ), 'a template block for one chosen reading does not count for other posts' );
unset( $GLOBALS['_wp_current_template_content'] );
bk_t( rrt_blocks_place( parse_blocks( '<!-- wp:group --><div class="wp-block-group">' . $bk_block . '</div><!-- /wp:group -->' ), $bk_plain, true ), 'a block inside a group counts too' );

/* ---- The shortcodes ---- */
foreach ( array( 'foxfire_player', 'reading_room_player' ) as $bk_tag ) {
	$bk_sc  = bk_post( 'Shortcode ' . $bk_tag, "<p>Before.</p>\n[$bk_tag]\n<p>After.</p>" );
	$bk_out = bk_view( $bk_sc );
	bk_t( 1 === bk_count( $bk_out ) && strpos( $bk_out, 'Before.' ) < strpos( $bk_out, 'rrt-player' ), "[$bk_tag] in a post shows one player where it is placed", bk_count( $bk_out ) );
	$bk_sc2 = bk_post( 'Shortcode for another ' . $bk_tag, "<p>Text.</p>\n[$bk_tag post=\"$bk_plain\"]", false );
	$bk_out = bk_view( $bk_sc2 );
	bk_t( 1 === bk_count( $bk_out ) && bk_has( $bk_out, $bk_plain ), "[$bk_tag post=\"…\"] shows that reading" );
}
$bk_esc = bk_post( 'Escaped shortcode', "<p>Write [[foxfire_player]] to place it.</p>" );
$bk_out = bk_view( $bk_esc );
bk_t( 1 === bk_count( $bk_out ) && false !== strpos( $bk_out, '[foxfire_player]' ), 'an escaped [[foxfire_player]] is text, and the top player stays' );

/* ---- Who may see it ---- */
bk_settings( 'off' );
bk_t( 0 === bk_count( bk_view( $bk_own, 1 ) ), 'switched off, the block draws nothing, even for an administrator' );
bk_settings( 'editors' );
bk_t( 0 === bk_count( bk_view( $bk_own, 0 ) ), 'for editors only, a visitor sees nothing' );
$bk_out = bk_view( $bk_own, 1 );
bk_t( 1 === bk_count( $bk_out ) && false !== strpos( $bk_out, 'rrt-preview-note' ), 'and an editor sees the player with the preview note' );
$bk_sc  = bk_post( 'Shortcode preview', '[foxfire_player]' );
bk_t( false !== strpos( bk_view( $bk_sc, 1 ), 'rrt-preview-note' ), 'the shortcode shows the preview note too' );
bk_settings( 'everyone' );

$bk_draft = bk_post( 'A draft reading', 'Words.', true, 'draft' );
$bk_show  = bk_post( 'Shows a draft', "<!-- wp:foxfire-studio/player {\"reading\":$bk_draft} /-->", false );
bk_t( 0 === bk_count( bk_view( $bk_show, 0 ) ), 'a draft\'s reading is never shown to a visitor' );
bk_t( 2 === bk_count( bk_view( $bk_page, 0 ) ), 'published readings are shown to a visitor' );
$bk_pw = bk_post( 'A protected reading', 'Words.' );
wp_update_post( array( 'ID' => $bk_pw, 'post_password' => 'secret' ) );
$bk_pws = bk_post( 'Shows a protected one', "<!-- wp:foxfire-studio/player {\"reading\":$bk_pw} /-->", false );
bk_t( 0 === bk_count( bk_view( $bk_pws, 0 ) ), 'nor a password-protected post\'s reading' );
$bk_attpage = bk_post( 'Points at an attachment', '<!-- wp:foxfire-studio/player {"reading":' . get_post_meta( $bk_plain, 'rrt_audio', true ) . '} /-->', false );
bk_t( 0 === bk_count( bk_view( $bk_attpage, 0 ) ), 'a chosen id that is not a post with readings draws nothing' );

/* ---- Widget areas ---- */
rrt_place_once( null, '' );
$GLOBALS['wp_query']     = new WP_Query( array( 'p' => $bk_plain ) );
$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
$GLOBALS['wp_query']->is_single   = true;
$GLOBALS['wp_query']->is_singular = true;
$GLOBALS['wp_query']->queried_object    = get_post( $bk_plain );
$GLOBALS['wp_query']->queried_object_id = $bk_plain;
do_action( 'dynamic_sidebar_before', 'sidebar-1', true );
$bk_w = do_blocks( $bk_block );
do_action( 'dynamic_sidebar_after', 'sidebar-1', true );
bk_t( 1 === bk_count( $bk_w ) && bk_has( $bk_w, $bk_plain ), 'in a widget area on a post, the block shows that post\'s reading' );
$GLOBALS['wp_query'] = new WP_Query( array( 'post_type' => 'post', 'posts_per_page' => 3 ) );
$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
rrt_place_once( null, '' );
do_action( 'dynamic_sidebar_before', 'sidebar-1', true );
$bk_w = do_blocks( $bk_block );
do_action( 'dynamic_sidebar_after', 'sidebar-1', true );
bk_t( 0 === bk_count( $bk_w ), 'on a list of posts, a widget block draws nothing' );

/* ---- The editor's preview ---- */
wp_set_current_user( 1 );
$bk_req = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/foxfire-studio/player' );
$bk_req->set_param( 'context', 'edit' );
$bk_req->set_param( 'post_id', $bk_own );
$bk_req->set_param( 'attributes', array( 'reading' => 0 ) );
$bk_res = rest_do_request( $bk_req );
$bk_r   = $bk_res->get_data();
bk_t( 200 === $bk_res->get_status() && isset( $bk_r['rendered'] ) && false !== strpos( $bk_r['rendered'], 'rrt-block-preview' ), 'the editor\'s preview draws the player through the block renderer', $bk_res->get_status() );
bk_t( isset( $bk_r['rendered'] ) && false !== strpos( $bk_r['rendered'], 'inert' ) && false !== strpos( $bk_r['rendered'], 'Plays on the published post.' ), 'silent there, with its note' );
unset( $GLOBALS['rrt_block_preview'] );

/* ---- The editor's list of readings ---- */
$bk_req = new WP_REST_Request( 'GET', '/rrt/v1/block/readings' );
$bk_req->set_param( 'search', 'No block here' );
$bk_r   = rest_do_request( $bk_req )->get_data();
$bk_ids = wp_list_pluck( $bk_r['readings'], 'id' );
bk_t( in_array( $bk_plain, $bk_ids, true ), 'the list finds a reading by its words' );
bk_t( 'everyone' === $bk_r['show'] && '' !== $bk_r['settings'], 'and says who sees the player, with a link to Settings for an administrator' );
$bk_req = new WP_REST_Request( 'GET', '/rrt/v1/block/readings' );
$bk_r   = rest_do_request( $bk_req )->get_data();
$bk_ids = wp_list_pluck( $bk_r['readings'], 'id' );
bk_t( ! in_array( $bk_page, $bk_ids, true ), 'posts without a reading are not listed' );
$bk_req = new WP_REST_Request( 'GET', '/rrt/v1/block/readings' );
$bk_req->set_param( 'include', (string) $bk_page );
$bk_r = rest_do_request( $bk_req )->get_data();
bk_t( 1 === count( $bk_r['readings'] ) && false === $bk_r['readings'][0]['has'] && '' !== $bk_r['readings'][0]['studio'], 'asked about one post, it says it has no reading yet, with the studio\'s address' );
$bk_r = rrt_block_readings( '', array( $bk_own ) );
bk_t( $bk_r && $bk_r[0]['has'], 'and a post with a reading says so' );
wp_set_current_user( 0 );
$bk_res = rest_do_request( new WP_REST_Request( 'GET', '/rrt/v1/block/readings' ) );
bk_t( in_array( $bk_res->get_status(), array( 401, 403 ), true ), 'visitors may not ask for the list', $bk_res->get_status() );
$bk_sub = wp_insert_user( array( 'user_login' => 'bk_sub', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
wp_set_current_user( $bk_sub );
$bk_res = rest_do_request( new WP_REST_Request( 'GET', '/rrt/v1/block/readings' ) );
bk_t( 403 === $bk_res->get_status(), 'nor may subscribers', $bk_res->get_status() );
wp_set_current_user( 1 );
wp_delete_user( $bk_sub );

/* ---- Settings' wording ---- */
$bk_admin = (string) file_get_contents( RRT_DIR . 'includes/admin.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
bk_t( false !== strpos( $bk_admin, 'At the top of the post, unless the post or its template places it' ), 'Settings says the top of the post gives way to a placed player' );
bk_t( false !== strpos( $bk_admin, 'the Reading player block, the %1$s shortcode, or %2$s in the theme' ) && false !== strpos( $bk_admin, '[foxfire_player]' ), 'and names the block and the new shortcode' );
