<?php
/**
 * The Reading player block and the shortcodes (1.28.0).
 *
 * One way into the player for every placement: the block, [foxfire_player],
 * the older [reading_room_player], and the top of the post. Each goes through
 * rrt_player_placed(), which checks who may see the player, adds the preview
 * note while it is shown to editors only, and draws each reading once per
 * page. The block is dynamic: a post stores only its name and its choice of
 * reading, and the player is drawn fresh on every page view.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RRT_BLOCK', 'foxfire-studio/player' );
define( 'RRT_SHORTCODES', array( 'foxfire_player', 'reading_room_player' ) );

/** Post types whose readings the block may show: the reading post types and collections. */
function rrt_block_types() {
	$types = (array) rrt_config()['post_types'];
	if ( defined( 'RRT_COL_TYPE' ) && ! in_array( RRT_COL_TYPE, $types, true ) ) {
		$types[] = RRT_COL_TYPE;
	}
	return array_values( $types );
}

/*
 * ---------------------------------------------------------------------
 * Placing the player
 * ---------------------------------------------------------------------
 */

/** Where a placement is being drawn: the post's content, a widget area, or the page's template. */
function rrt_place_source() {
	if ( ! empty( $GLOBALS['rrt_in_sidebar'] ) ) {
		return 'widget';
	}
	if ( doing_filter( 'the_content' ) ) {
		return 'content';
	}
	return 'template';
}

add_action(
	'dynamic_sidebar_before',
	function () {
		$GLOBALS['rrt_in_sidebar'] = true;
	}
);
add_action(
	'dynamic_sidebar_after',
	function () {
		$GLOBALS['rrt_in_sidebar'] = false;
	}
);

/**
 * Notes that a reading was drawn from a source, and says whether it may be.
 * A reading drawn once on a page is not drawn again from another place; the
 * same place may draw it again, because some themes and plugins run a post's
 * content through the_content more than once.
 *
 * @param int    $post_id The reading's post.
 * @param string $source  content, widget or template.
 * @return bool
 */
function rrt_place_once( $post_id, $source ) {
	static $drawn = array();
	if ( null === $post_id ) {
		$drawn = array(); // Tests start a fresh page.
		return true;
	}
	$post_id = (int) $post_id;
	if ( isset( $drawn[ $post_id ] ) && $drawn[ $post_id ] !== $source ) {
		return false;
	}
	$drawn[ $post_id ] = $source;
	return true;
}

/** Whether this request is the block editor's preview of the block. */
function rrt_block_previewing() {
	return ! empty( $GLOBALS['rrt_block_preview'] );
}

add_filter(
	'rest_request_before_callbacks',
	function ( $response, $handler, $request ) {
		if ( 0 === strpos( (string) $request->get_route(), '/wp/v2/block-renderer/' . RRT_BLOCK ) ) {
			$GLOBALS['rrt_block_preview'] = true;
		}
		return $response;
	},
	10,
	3
);

/**
 * The player for a placement, with the visibility setting, the preview note
 * and the once-per-page rule.
 *
 * @param int|WP_Post|null $post   The reading's post, or the current post.
 * @param string           $source Where it is drawn, or '' to work it out.
 * @return string
 */
function rrt_player_placed( $post = null, $source = '' ) {
	$post = get_post( $post );
	if ( ! $post || ! rrt_player_visible() ) {
		return '';
	}
	if ( ! rrt_place_once( $post->ID, $source ? $source : rrt_place_source() ) ) {
		return '';
	}
	$html = rrt_player( $post );
	if ( '' === $html ) {
		return '';
	}
	if ( 'editors' === rrt_config()['player']['show'] ) {
		$html = '<div class="rrt-preview-note">' . esc_html__( 'Preview: only editors see this player until it is switched on for everyone in Settings, then Foxfire Studio.', 'foxfire-studio' ) . '</div>' . $html;
	}
	return $html;
}

/**
 * Whether blocks in this content or template show the given post's own reading.
 * A block with no reading chosen shows the post it sits in.
 *
 * @param array $blocks  Parsed blocks.
 * @param int   $post_id The post.
 * @param bool  $own     Whether a block with no reading chosen counts as this post's.
 * @return bool
 */
function rrt_blocks_place( $blocks, $post_id, $own ) {
	foreach ( (array) $blocks as $b ) {
		if ( ! is_array( $b ) ) {
			continue;
		}
		if ( RRT_BLOCK === ( isset( $b['blockName'] ) ? $b['blockName'] : '' ) ) {
			$r = isset( $b['attrs']['reading'] ) ? (int) $b['attrs']['reading'] : 0;
			if ( ( 0 === $r && $own ) || $r === (int) $post_id ) {
				return true;
			}
		}
		if ( ! empty( $b['innerBlocks'] ) && rrt_blocks_place( $b['innerBlocks'], $post_id, $own ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Whether a post's content or the page's template places the post's own
 * reading by hand, so the top-of-post player is left out (1.28.0).
 *
 * @param WP_Post $post The post.
 * @return bool
 */
function rrt_player_placed_by_hand( $post ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return false;
	}
	$content = (string) $post->post_content;
	if ( false !== strpos( $content, 'wp:' . RRT_BLOCK ) && function_exists( 'parse_blocks' ) && rrt_blocks_place( parse_blocks( $content ), $post->ID, true ) ) {
		return true;
	}
	foreach ( RRT_SHORTCODES as $tag ) {
		if ( false === strpos( $content, '[' . $tag ) ) {
			continue;
		}
		if ( preg_match_all( '/' . get_shortcode_regex( array( $tag ) ) . '/', $content, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $one ) {
				if ( '[' === $one[1] ) {
					continue; // [[escaped]] shortcodes are shown as text.
				}
				$atts = shortcode_parse_atts( $one[3] );
				$r    = is_array( $atts ) && isset( $atts['post'] ) ? (int) $atts['post'] : 0;
				if ( 0 === $r || $r === (int) $post->ID ) {
					return true;
				}
			}
		}
	}
	/* A block theme's template for this page (the Single Posts template, say). */
	$tpl = isset( $GLOBALS['_wp_current_template_content'] ) ? (string) $GLOBALS['_wp_current_template_content'] : '';
	if ( '' !== $tpl && false !== strpos( $tpl, 'wp:' . RRT_BLOCK ) && rrt_blocks_place( parse_blocks( $tpl ), $post->ID, true ) ) {
		return true;
	}
	return false;
}

/*
 * ---------------------------------------------------------------------
 * The shortcodes
 * ---------------------------------------------------------------------
 */

/** [foxfire_player] and [reading_room_player]: the player wherever it is placed, with post="123" for another reading. */
function rrt_shortcode_player( $atts, $content = '', $tag = 'foxfire_player' ) {
	$atts = shortcode_atts( array( 'post' => 0 ), $atts, $tag );
	$id   = absint( $atts['post'] );
	return rrt_player_placed( $id ? $id : null );
}
foreach ( RRT_SHORTCODES as $rrt_tag ) {
	add_shortcode( $rrt_tag, 'rrt_shortcode_player' );
}
unset( $rrt_tag );

/*
 * ---------------------------------------------------------------------
 * The block
 * ---------------------------------------------------------------------
 */

/**
 * Draws the block.
 *
 * @param array    $attrs   The block's attributes.
 * @param string   $content Nothing (a dynamic block saves no markup).
 * @param WP_Block $block   The block, with its context.
 * @return string
 */
function rrt_block_render( $attrs, $content = '', $block = null ) {
	$chosen = isset( $attrs['reading'] ) ? absint( $attrs['reading'] ) : 0;
	if ( $chosen ) {
		$id = $chosen;
	} elseif ( ! empty( $GLOBALS['rrt_in_sidebar'] ) ) {
		/* In a widget area: the post being viewed, and nothing on other pages. */
		$id = is_singular() ? (int) get_queried_object_id() : 0;
	} elseif ( $block instanceof WP_Block && ! empty( $block->context['postId'] ) ) {
		$id = (int) $block->context['postId'];
	} else {
		$id = (int) get_the_ID();
	}
	if ( ! $id ) {
		return '';
	}
	$post = get_post( $id );
	if ( ! $post || ! in_array( $post->post_type, rrt_block_types(), true ) ) {
		return '';
	}
	if ( rrt_block_previewing() ) {
		/* The editor's preview: the player as visitors see it, silent there. */
		$html = rrt_player( $post );
		if ( '' === $html ) {
			return '';
		}
		return '<div class="rrt-block-preview" inert>' . $html . '</div><p class="rrt-block-note">' . esc_html__( 'Plays on the published post.', 'foxfire-studio' ) . '</p>';
	}
	$html = rrt_player_placed( $post );
	if ( '' === $html ) {
		return '';
	}
	$wrap = function_exists( 'get_block_wrapper_attributes' ) ? get_block_wrapper_attributes() : 'class="wp-block-foxfire-studio-player"';
	return '<div ' . $wrap . '>' . $html . '</div>';
}

add_action(
	'init',
	function () {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		wp_register_style( 'rrt-player', RRT_URL . 'assets/css/player.css', array(), RRT_VERSION . '.' . filemtime( RRT_DIR . 'assets/css/player.css' ) );
		wp_register_script(
			'rrt-block-player',
			RRT_URL . 'assets/js/block-player.js',
			array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n', 'wp-api-fetch', 'wp-server-side-render', 'wp-url' ),
			RRT_VERSION . '.' . filemtime( RRT_DIR . 'assets/js/block-player.js' ),
			true
		);
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'rrt-block-player', 'foxfire-studio', RRT_DIR . 'languages' );
		}
		register_block_type(
			RRT_DIR . 'blocks/player',
			array(
				'render_callback' => 'rrt_block_render',
				'editor_script'   => 'rrt-block-player',
				'editor_style'    => 'rrt-player',
			)
		);
	},
	20
);

/* The look's colours and fonts, for the preview inside the editor. */
add_action(
	'enqueue_block_assets',
	function () {
		if ( is_admin() && wp_style_is( 'rrt-player', 'registered' ) ) {
			wp_add_inline_style( 'rrt-player', rrt_player_tokens_css( rrt_config() ) );
		}
	}
);

/*
 * ---------------------------------------------------------------------
 * What the block's editor side asks the site
 * ---------------------------------------------------------------------
 */

/**
 * Readings the block may show: posts and collections with a reading, newest first.
 *
 * @param string $q       Words to search for.
 * @param int[]  $include Only these posts, when given.
 * @return array[]
 */
function rrt_block_readings( $q = '', $include = array() ) {
	$cfg  = rrt_config();
	$keys = array( 'rrt_audio' );
	if ( ! empty( $cfg['player']['audio_key'] ) ) {
		$keys[] = $cfg['player']['audio_key'];
	}
	$args = array(
		'post_type'        => rrt_block_types(),
		'post_status'      => array( 'publish', 'draft', 'pending', 'future', 'private' ),
		'numberposts'      => 20,
		'orderby'          => 'date',
		'order'            => 'DESC',
		'suppress_filters' => false,
	);
	if ( $include ) {
		$args['post__in']    = array_map( 'intval', $include );
		$args['numberposts'] = count( $include );
	} else {
		$mq = array( 'relation' => 'OR' );
		foreach ( $keys as $k ) {
			$mq[] = array(
				'key'     => $k,
				'compare' => 'EXISTS',
			);
		}
		$args['meta_query'] = $mq; // phpcs:ignore WordPress.DB.SlowDBQuery
		if ( '' !== trim( (string) $q ) ) {
			$args['s'] = (string) $q;
		}
	}
	$out = array();
	foreach ( get_posts( $args ) as $p ) {
		if ( ! current_user_can( 'read_post', $p->ID ) ) {
			continue;
		}
		$a   = rrt_reading_audio( $p->ID );
		$len = 0;
		if ( $a['id'] ) {
			$meta = wp_get_attachment_metadata( $a['id'] );
			$len  = empty( $meta['length'] ) ? 0 : (int) $meta['length'];
		}
		if ( '' === $a['url'] && ! $include ) {
			continue;
		}
		$type  = get_post_type_object( $p->post_type );
		$out[] = array(
			'id'     => (int) $p->ID,
			'title'  => html_entity_decode( wp_strip_all_tags( get_the_title( $p ) ), ENT_QUOTES, 'UTF-8' ),
			'type'   => $type ? (string) $type->labels->singular_name : '',
			'status' => 'publish' === $p->post_status ? '' : (string) get_post_status_object( $p->post_status )->label,
			'length' => $len ? rrt_clock( $len ) : '',
			'has'    => '' !== $a['url'],
			'studio' => current_user_can( 'edit_post', $p->ID ) ? rrt_url( $p->ID ) : '',
		);
	}
	return $out;
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'rrt/v1',
			'/block/readings',
			array(
				'methods'             => 'GET',
				'permission_callback' => function () {
					return is_user_logged_in() && current_user_can( 'edit_posts' );
				},
				'callback'            => function ( $req ) {
					$inc = array_slice( array_filter( array_map( 'absint', explode( ',', (string) $req['include'] ) ) ), 0, 20 );
					$cfg = rrt_config();
					return rest_ensure_response(
						array(
							'readings' => rrt_block_readings( sanitize_text_field( (string) $req['search'] ), $inc ),
							'show'     => (string) $cfg['player']['show'],
							'settings' => current_user_can( 'manage_options' ) ? admin_url( 'options-general.php?page=rrt-settings' ) : '',
						)
					);
				},
			)
		);
	}
);
