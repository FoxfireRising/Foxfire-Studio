<?php
/**
 * The podcast feed (0.22.0).
 *
 * Every published post with a reading becomes an episode of one podcast,
 * in an RSS 2.0 feed with the tags Apple Podcasts, Spotify and other apps
 * need, and the Podcasting 2.0 tags for the transcript (the reading's
 * captions), a permanent show id and the lock that keeps other hosts from
 * importing the show. Since 1.10.0 also chapters, a soundbite, the host,
 * a support link, the medium, a place, lines of text and pings (feed.php).
 * The feed is off until it is switched on in Foxfire Studio, Podcast feed,
 * which also checks the cover art and the details directories ask for.
 *
 * Settings live in their own option, rrt_podcast.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The most episodes the feed lists, newest first. */
define( 'RRT_PODCAST_MAX', 300 );

/** The Podcasting 2.0 namespace's UUID, from which a show's podcast:guid is made. */
define( 'RRT_PODCAST_NS', 'ead4c236-bf58-58c6-a2c6-a6b28d128cb6' );

/** The categories offered, as Apple names them: top level => its subcategories. */
function rrt_podcast_categories() {
	return array(
		'Arts'                    => array( 'Books', 'Performing Arts', 'Visual Arts' ),
		'Education'               => array( 'Self-Improvement' ),
		'Fiction'                 => array( 'Drama', 'Science Fiction' ),
		'Health &amp; Fitness'    => array( 'Mental Health' ),
		'History'                 => array(),
		'Religion &amp; Spirituality' => array( 'Spirituality', 'Religion' ),
		'Society &amp; Culture'   => array( 'Personal Journals', 'Philosophy', 'Relationships', 'Documentary' ),
	);
}

/** The feed's settings, with defaults. */
function rrt_podcast_settings() {
	$s = get_option( 'rrt_podcast', array() );
	$s = is_array( $s ) ? $s : array();
	return array_merge(
		array(
			'on'          => false,
			'title'       => '',
			'description' => '',
			'image'       => 0,
			'author'      => '',
			'email'       => '',
			'category'    => 'Religion &amp; Spirituality',
			'subcategory' => 'Spirituality',
			'explicit'    => false,
			'copyright'   => '',
			'locked'      => true,
			'guid'        => '',
			'count'       => false, // Count downloads by podcast apps through a short address on this site (1.2.0).
			/* Podcasting 2.0 (1.10.0). */
			'person'      => '',    // The host's name; empty: the author.
			'person_link' => '',    // The host's page; empty: the site's home page.
			'person_img'  => 0,     // A square picture of the host from the media library, or none.
			'funding'     => '',    // A support or donation page (https).
			'funding_txt' => '',    // The words apps show beside it; empty: "Support <show title>".
			'location'    => '',    // Where the show is made, as a place name; empty: no tag.
			'txt'         => '',    // podcast:txt lines, one a line; "purpose: text" gives a purpose.
			'pings'       => false, // Tell Podcast Index and a WebSub hub about new readings.
			'hub'         => '',    // The WebSub hub; empty: RRT_PING_HUB.
			/* Member feeds (1.21.0). */
			'm_on'        => false,   // Give members their own feed.
			'm_roles'     => array(), // The roles whose people are members.
			'm_via'       => 'roles', // Roles, or an active membership plugin's members.
			'm_title'     => '',      // The member feed's title; empty: "<show> (members)".
			'm_join'      => '',      // A Become a member page.
			'm_activity'  => false,   // Feed activity: last fetch, app and networks per member.
			'm_guid'      => '',      // The member feed's own podcast:guid, made once.
			/* Sponsor messages (1.22.0). */
			's_on'        => false,   // Place sponsor messages in the feed.
			's_mid_min'   => 10,      // Mid-rolls only in readings of this many minutes or more.
			's_kinds'     => array( 'prose', 'story' ), // The kinds of reading that take a mid-roll.
			's_chapters'  => true,    // A chapter for each message.
			's_thank'     => false,   // A thank-you line under the post's player.
			's_members'   => false,   // Members hear the messages too.
			's_line_paid' => '',      // The notes line for a paid message; empty: "Sponsored by %s".
			's_line_own'  => '',      // The notes line for your own message; empty: "Supported by %s".
		),
		$s
	);
}

/** The show's title: the setting, else the player's show name. */
function rrt_podcast_title( $s = null ) {
	$s = $s ? $s : rrt_podcast_settings();
	return '' !== trim( (string) $s['title'] ) ? trim( (string) $s['title'] ) : rrt_show_name();
}

/** The show's author: the setting, else the first administrator's display name. */
function rrt_podcast_author( $s = null ) {
	$s = $s ? $s : rrt_podcast_settings();
	if ( '' !== trim( (string) $s['author'] ) ) {
		return trim( (string) $s['author'] );
	}
	$admins = get_users(
		array(
			'role'    => 'administrator',
			'number'  => 1,
			'orderby' => 'ID',
		)
	);
	return $admins ? $admins[0]->display_name : rrt_podcast_title( $s );
}

/** The feed's address. */
function rrt_podcast_url() {
	return get_feed_link( 'podcast' );
}

/** A name-based (version 5) UUID, as the podcast:guid tag asks for. */
function rrt_podcast_uuid5( $ns, $name ) {
	$nb   = pack( 'H*', str_replace( '-', '', $ns ) );
	$h    = sha1( $nb . $name );
	$time = substr( $h, 12, 4 );
	$clk  = hexdec( substr( $h, 16, 4 ) );
	return sprintf(
		'%s-%s-%04x-%04x-%s',
		substr( $h, 0, 8 ),
		substr( $h, 8, 4 ),
		( hexdec( $time ) & 0x0fff ) | 0x5000,
		( $clk & 0x3fff ) | 0x8000,
		substr( $h, 20, 12 )
	);
}

/** The show's permanent podcast:guid, made once from the feed's first address and kept. */
function rrt_podcast_guid() {
	$s = rrt_podcast_settings();
	if ( '' !== $s['guid'] ) {
		return $s['guid'];
	}
	$name      = rtrim( preg_replace( '#^[a-z]+://#i', '', rrt_podcast_url() ), '/' );
	$s['guid'] = rrt_podcast_uuid5( RRT_PODCAST_NS, $name );
	update_option( 'rrt_podcast', $s );
	return $s['guid'];
}

/**
 * The cover art and what is wrong with it, if anything.
 *
 * @return array { url, w, h, type, bytes, problems[] }
 */
function rrt_podcast_art( $s = null ) {
	$s   = $s ? $s : rrt_podcast_settings();
	$id  = (int) $s['image'];
	$out = array(
		'url'      => '',
		'w'        => 0,
		'h'        => 0,
		'type'     => '',
		'bytes'    => 0,
		'problems' => array(),
	);
	if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
		$out['problems'][] = __( 'Choose cover art: a square JPEG or PNG between 1400 and 3000 pixels wide.', 'foxfire-studio' );
		return $out;
	}
	$out['url']  = (string) wp_get_attachment_url( $id );
	$meta        = wp_get_attachment_metadata( $id );
	$out['w']    = isset( $meta['width'] ) ? (int) $meta['width'] : 0;
	$out['h']    = isset( $meta['height'] ) ? (int) $meta['height'] : 0;
	$out['type'] = (string) get_post_mime_type( $id );
	$file        = get_attached_file( $id );
	$out['bytes'] = $file && file_exists( $file ) ? (int) filesize( $file ) : 0;
	if ( ! in_array( $out['type'], array( 'image/jpeg', 'image/png' ), true ) ) {
		$out['problems'][] = sprintf( /* translators: %s: file type. */ __( 'The cover art must be a JPEG or PNG (this one is %s).', 'foxfire-studio' ), $out['type'] ? $out['type'] : __( 'unknown', 'foxfire-studio' ) );
	}
	if ( $out['w'] !== $out['h'] ) {
		$out['problems'][] = sprintf( /* translators: 1: width, 2: height. */ __( 'The cover art must be square (this one is %1$d by %2$d pixels).', 'foxfire-studio' ), $out['w'], $out['h'] );
	} elseif ( $out['w'] < 1400 || $out['w'] > 3000 ) {
		$out['problems'][] = sprintf( /* translators: %d: width in pixels. */ __( 'The cover art must be between 1400 and 3000 pixels wide (this one is %d).', 'foxfire-studio' ), $out['w'] );
	}
	return $out;
}

/**
 * The host's picture and what is wrong with it, if anything (1.10.0).
 *
 * @return array { url, problems[] }
 */
function rrt_podcast_person_img( $s = null ) {
	$s   = $s ? $s : rrt_podcast_settings();
	$id  = (int) $s['person_img'];
	$out = array(
		'url'      => '',
		'problems' => array(),
	);
	if ( ! $id ) {
		return $out;
	}
	if ( 'attachment' !== get_post_type( $id ) ) {
		$out['problems'][] = __( 'The host picture is no longer in the media library. Choose it again, or remove it.', 'foxfire-studio' );
		return $out;
	}
	$url  = (string) wp_get_attachment_url( $id );
	$meta = wp_get_attachment_metadata( $id );
	$w    = isset( $meta['width'] ) ? (int) $meta['width'] : 0;
	$h    = isset( $meta['height'] ) ? (int) $meta['height'] : 0;
	if ( ! in_array( (string) get_post_mime_type( $id ), array( 'image/jpeg', 'image/png' ), true ) ) {
		$out['problems'][] = __( 'The host picture must be a JPEG or PNG.', 'foxfire-studio' );
	}
	if ( ! $w || $w !== $h ) {
		$out['problems'][] = sprintf( /* translators: 1: width, 2: height. */ __( 'The host picture must be square (this one is %1$d by %2$d pixels).', 'foxfire-studio' ), $w, $h );
	}
	if ( ! preg_match( '#^https://#i', $url ) ) {
		$out['problems'][] = __( 'The host picture\'s address must start with https://.', 'foxfire-studio' );
	}
	$out['url'] = $out['problems'] ? '' : $url;
	return $out;
}

/** The host's name, page and picture for podcast:person, or null when there is no name (1.10.0). */
function rrt_podcast_person( $s = null ) {
	$s    = $s ? $s : rrt_podcast_settings();
	$name = trim( (string) $s['person'] );
	$name = '' !== $name ? $name : rrt_podcast_author( $s );
	if ( '' === $name ) {
		return null;
	}
	$link = trim( (string) $s['person_link'] );
	$link = '' !== $link ? $link : home_url( '/' );
	return array(
		'name' => $name,
		'href' => preg_match( '#^https://#i', $link ) ? $link : '',
		'img'  => rrt_podcast_person_img( $s )['url'],
	);
}

/** The support link and its words, or null (1.10.0). */
function rrt_podcast_funding( $s = null ) {
	$s   = $s ? $s : rrt_podcast_settings();
	$url = trim( (string) $s['funding'] );
	if ( ! preg_match( '#^https://[^\s]+$#i', $url ) ) {
		return null;
	}
	$txt = trim( (string) $s['funding_txt'] );
	/* translators: %s: the show's title. */
	$txt = '' !== $txt ? $txt : sprintf( __( 'Support %s', 'foxfire-studio' ), rrt_podcast_title( $s ) );
	return array(
		'url' => $url,
		'txt' => mb_substr( $txt, 0, 128 ),
	);
}

/**
 * Published posts with a reading, newest first. Readings for members (1.21.0)
 * are left out unless $all is true.
 */
function rrt_podcast_posts( $all = false ) {
	$cfg  = rrt_config();
	$keys = array( 'rrt_audio' );
	if ( ! empty( $cfg['player']['audio_key'] ) ) {
		$keys[] = $cfg['player']['audio_key'];
	}
	$mq = array( 'relation' => 'OR' );
	foreach ( $keys as $k ) {
		$mq[] = array(
			'key'     => $k,
			'compare' => 'EXISTS',
		);
	}
	$posts = get_posts(
		array(
			'post_type'        => $cfg['post_types'],
			'post_status'      => 'publish',
			'has_password'     => false,
			'numberposts'      => RRT_PODCAST_MAX,
			'orderby'          => 'date',
			'order'            => 'DESC',
			'meta_query'       => $mq, // phpcs:ignore WordPress.DB.SlowDBQuery
			'suppress_filters' => false,
		)
	);
	$posts = array_values(
		array_filter(
			$posts,
			function ( $p ) {
				$a = rrt_reading_audio( $p->ID );
				return '' !== $a['url'];
			}
		)
	);
	/* Collections (1.19.0) are episodes of their own, newest first with the rest. */
	if ( function_exists( 'rrt_col_feed_posts' ) ) {
		$cols = rrt_col_feed_posts();
		if ( $cols ) {
			$posts = array_merge( $posts, $cols );
			usort(
				$posts,
				function ( $a, $b ) {
					return strcmp( $b->post_date_gmt, $a->post_date_gmt );
				}
			);
			$posts = array_slice( $posts, 0, RRT_PODCAST_MAX );
		}
	}
	/* Member feeds (1.21.0): a reading for members stays out of the public feed. */
	if ( ! $all && function_exists( 'rrt_access_settle' ) ) {
		$posts = array_values( array_filter( $posts, 'rrt_podcast_open' ) );
	}
	return $posts;
}

/** Whether a post's reading is open to everyone, with its files moved back first if its day has come. */
function rrt_podcast_open( $p ) {
	return function_exists( 'rrt_access_settle' ) ? rrt_access_settle( $p->ID ) : true;
}

/** The local file behind an address in the uploads folder, or ''. */
function rrt_podcast_local_path( $url, $post_id = 0, $kind = '' ) {
	/* A file kept for members (1.21.0) has a checked address of its own, and is only ever its own post's (1.25.0). */
	if ( function_exists( 'rrt_mfile_from_url' ) ) {
		$att = rrt_mfile_from_url( $url );
		if ( $att ) {
			return rrt_att_file( $att, (int) $post_id, $kind );
		}
	}
	$up   = wp_upload_dir( null, false );
	$base = preg_replace( '#^https?:#i', '', $up['baseurl'] );
	$bare = preg_replace( '#^https?:#i', '', strtok( (string) $url, '?' ) );
	if ( 0 === strpos( $bare, $base . '/' ) ) {
		$path = $up['basedir'] . substr( $bare, strlen( $base ) );
		/* 1.25.0: never a file in a private or closed folder (rrt_upload_path_ok). */
		if ( false === strpos( $path, '..' ) && rrt_upload_path_ok( $path, $kind ) ) {
			return $path;
		}
	}
	return '';
}

/**
 * One episode: everything the feed says about a post's reading.
 *
 * @param WP_Post $post Post.
 * @return array|null
 */
function rrt_podcast_episode( $post ) {
	global $wpdb;
	$audio = rrt_reading_audio( $post->ID );
	if ( '' === $audio['url'] ) {
		return null;
	}
	$bytes = 0;
	$secs  = 0;
	$type  = '';
	if ( $audio['id'] ) {
		$file  = get_attached_file( $audio['id'] );
		$bytes = $file && file_exists( $file ) ? (int) filesize( $file ) : 0;
		$meta  = wp_get_attachment_metadata( $audio['id'] );
		$secs  = ! empty( $meta['length'] ) ? (int) $meta['length'] : 0;
		$type  = (string) get_post_mime_type( $audio['id'] );
	}
	if ( ! $bytes ) {
		$path  = rrt_podcast_local_path( $audio['url'], $post->ID, 'audio' );
		$bytes = $path ? (int) filesize( $path ) : 0;
	}
	if ( ! $secs && function_exists( 'rrt_table' ) ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT duration_s, size_bytes FROM ' . rrt_table( 'recordings' ) . ' WHERE post_id = %d AND url = %s ORDER BY id DESC LIMIT 1', $post->ID, $audio['url'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- only the plugin's own table name and whole numbers.
		if ( $row ) {
			$secs  = (int) round( (float) $row->duration_s );
			$bytes = $bytes ? $bytes : (int) $row->size_bytes;
		}
	}
	if ( ! $type ) {
		$ext  = strtolower( pathinfo( (string) wp_parse_url( $audio['url'], PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		$map  = array(
			'mp3' => 'audio/mpeg',
			'm4a' => 'audio/x-m4a',
			'mp4' => 'audio/mp4',
			'ogg' => 'audio/ogg',
			'wav' => 'audio/wav',
		);
		$type = isset( $map[ $ext ] ) ? $map[ $ext ] : 'audio/mpeg';
	}
	$text = $post->post_excerpt ? $post->post_excerpt : wp_trim_words( strip_shortcodes( $post->post_content ), 55, '...' );
	$text = trim( preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' ) ) );
	$cap   = rrt_reading_captions( $post->ID );
	$facts = rrt_feed_facts( $post->ID );
	$col   = function_exists( 'rrt_col_is' ) && rrt_col_is( $post );
	return array(
		'title'   => html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' ),
		'link'    => get_permalink( $post ),
		/* Never changes for a post, even when its reading is recorded again. */
		'guid'    => wp_parse_url( home_url(), PHP_URL_HOST ) . ( $col ? ':collection:' : ':reading:' ) . $post->ID,
		/* A collection (1.19.0) is a bonus episode unless its screen says full. */
		'etype'   => $col ? rrt_col_settings( $post->ID )['type'] : 'full',
		/* 1.33.0: the reading's language, for its transcript. */
		'lang'    => function_exists( 'rrt_post_lang_tag' ) ? rrt_post_lang_tag( $post->ID ) : '',
		'more'    => $col ? __( 'See the readings in this collection', 'foxfire-studio' ) : __( 'Read the post', 'foxfire-studio' ),
		'date'    => mysql2date( 'D, d M Y H:i:s +0000', $post->post_date_gmt, false ),
		'text'    => $text,
		'credit'  => function_exists( 'rrt_reading_credit' ) ? rrt_reading_credit( $post->ID ) : '',
		'music'   => function_exists( 'rrt_music_credit_used' ) ? rrt_music_credit_used( $post->ID ) : '',
		'url'     => $audio['url'],
		/* What the feed's enclosure points to: the short address that counts downloads (1.2.0), or the file itself. */
		'enclosure' => ( function_exists( 'rrt_dl_on' ) && rrt_dl_on() ) ? rrt_dl_url( $post->ID, $audio['url'] ) : $audio['url'],
		'bytes'   => $bytes,
		'type'    => $type,
		'secs'    => $secs,
		'vtt'     => $cap['url'],
		/* Podcasting 2.0 (1.10.0). */
		'chapters'  => $facts['chapters'] ? rrt_chapters_url( $post->ID ) : '',
		'nchapters' => count( $facts['chapters'] ),
		'soundbite' => rrt_feed_soundbite( $post->ID, $facts ),
	);
}

/** Text for XML, without control characters XML forbids. */
function rrt_podcast_x( $s ) {
	$s = preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string) $s );
	return htmlspecialchars( (string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8' );
}

/** An absolute https-or-http address for XML, or ''. */
function rrt_podcast_u( $url ) {
	$url = (string) $url;
	if ( 0 === strpos( $url, '//' ) ) {
		$url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
	} elseif ( 0 === strpos( $url, '/' ) ) {
		$url = home_url( $url );
	}
	return preg_match( '#^https?://#i', $url ) ? rrt_podcast_x( $url ) : '';
}

/** Prints the public feed. */
function rrt_podcast_feed() {
	$s = rrt_podcast_settings();
	if ( empty( $s['on'] ) ) {
		status_header( 404 );
		exit;
	}
	rrt_podcast_render( null );
	exit;
}

/**
 * Prints a feed: the public one, or a member's (1.21.0).
 *
 * @param array|null $m For a member's feed: uid, key, and member (whether they are still a member).
 */
function rrt_podcast_render( $m = null ) {
	$s       = rrt_podcast_settings();
	$title   = $m ? rrt_mfeed_title() : rrt_podcast_title( $s );
	$author  = rrt_podcast_author( $s );
	$art     = rrt_podcast_art( $s );
	$lang    = strtolower( str_replace( '_', '-', get_bloginfo( 'language' ) ) );
	$desc    = trim( (string) $s['description'] );
	$desc    = '' !== $desc ? $desc : rrt_podcast_title( $s );
	if ( $m ) {
		$desc .= "\n\n" . rrt_mfeed_note( ! empty( $m['member'] ) );
	}
	$cats    = rrt_podcast_categories();
	$top     = isset( $cats[ $s['category'] ] ) ? $s['category'] : 'Religion &amp; Spirituality';
	$sub     = in_array( $s['subcategory'], $cats[ $top ], true ) ? $s['subcategory'] : '';
	$explict = ! empty( $s['explicit'] ) ? 'true' : 'false';
	if ( $m ) {
		/* A member's feed: every open reading, plus those for members they may hear. */
		$eps = array();
		foreach ( rrt_podcast_posts( ! empty( $m['member'] ) ) as $p ) {
			$open = rrt_access_settle( $p->ID );
			if ( ! $open && ! rrt_member_can_hear( $p->ID, (int) $m['uid'] ) ) {
				continue;
			}
			$e = rrt_podcast_episode( $p );
			if ( $e ) {
				/* Sponsor messages (1.22.0), when members hear them too. */
				if ( function_exists( 'rrt_spot_episode' ) ) {
					$e = rrt_spot_episode( $e, $p, $m );
				}
				/* Storage offload (1.23.0): copies of files for everyone. */
				if ( function_exists( 'rrt_off_episode' ) ) {
					$e = rrt_off_episode( $e, $p );
				}
				$eps[] = rrt_member_episode( $e, $p, (string) $m['key'] );
			}
		}
	} else {
		$eps = array();
		foreach ( rrt_podcast_posts() as $p ) {
			$e = rrt_podcast_episode( $p );
			/* Sponsor messages (1.22.0): the episode's sponsored copy, when it has one. */
			if ( $e && function_exists( 'rrt_spot_episode' ) ) {
				$e = rrt_spot_episode( $e, $p, null );
			}
			/* Storage offload (1.23.0). */
			if ( $e && function_exists( 'rrt_off_episode' ) ) {
				$e = rrt_off_episode( $e, $p );
			}
			if ( $e ) {
				/* Measurement prefixes (1.24.0): the public feed only. */
				if ( function_exists( 'rrt_dl_prefixed' ) ) {
					$e['enclosure'] = rrt_dl_prefixed( $e['enclosure'] );
				}
				$eps[] = $e;
			}
		}
	}
	$built   = $eps ? reset( $eps )['date'] : gmdate( 'D, d M Y H:i:s +0000' );
	$person  = rrt_podcast_person( $s );
	$fund    = rrt_podcast_funding( $s );
	$txts    = rrt_feed_txt_lines( $s['txt'] );
	$place   = trim( (string) $s['location'] );

	$self = $m ? home_url( '/' . RRT_MEMBER_BASE . '/' . $m['key'] . '/' ) : rrt_podcast_url();
	if ( ! headers_sent() ) {
		header( 'Content-Type: application/rss+xml; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' );
	}
	echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	?>
<rss version="2.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd" xmlns:podcast="https://podcastindex.org/namespace/1.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:content="http://purl.org/rss/1.0/modules/content/">
<channel>
	<title><?php echo rrt_podcast_x( $title ); // phpcs:ignore WordPress.Security.EscapeOutput ?></title>
	<link><?php echo rrt_podcast_u( home_url( '/' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></link>
	<atom:link href="<?php echo rrt_podcast_u( $self ); // phpcs:ignore WordPress.Security.EscapeOutput ?>" rel="self" type="application/rss+xml"/>
	<?php if ( ! $m && rrt_ping_on( $s ) ) : ?>
	<atom:link href="<?php echo rrt_podcast_u( rrt_ping_hub( $s ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>" rel="hub"/>
	<?php endif; ?>
	<language><?php echo rrt_podcast_x( $lang ); // phpcs:ignore WordPress.Security.EscapeOutput ?></language>
	<description><?php echo rrt_podcast_x( $desc ); // phpcs:ignore WordPress.Security.EscapeOutput ?></description>
	<lastBuildDate><?php echo rrt_podcast_x( $built ); // phpcs:ignore WordPress.Security.EscapeOutput ?></lastBuildDate>
	<generator>Foxfire Studio <?php echo rrt_podcast_x( RRT_VERSION ); // phpcs:ignore WordPress.Security.EscapeOutput ?></generator>
	<?php if ( '' !== trim( (string) $s['copyright'] ) ) : ?>
	<copyright><?php echo rrt_podcast_x( $s['copyright'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></copyright>
	<?php endif; ?>
	<itunes:author><?php echo rrt_podcast_x( $author ); // phpcs:ignore WordPress.Security.EscapeOutput ?></itunes:author>
	<itunes:summary><?php echo rrt_podcast_x( $desc ); // phpcs:ignore WordPress.Security.EscapeOutput ?></itunes:summary>
	<itunes:type>episodic</itunes:type>
	<?php if ( $m ) : ?>
	<itunes:block>Yes</itunes:block>
	<podcast:block>yes</podcast:block>
	<?php endif; ?>
	<itunes:explicit><?php echo $explict; // phpcs:ignore WordPress.Security.EscapeOutput ?></itunes:explicit>
	<?php if ( '' !== trim( (string) $s['email'] ) ) : ?>
	<itunes:owner>
		<itunes:name><?php echo rrt_podcast_x( $author ); // phpcs:ignore WordPress.Security.EscapeOutput ?></itunes:name>
		<itunes:email><?php echo rrt_podcast_x( $s['email'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></itunes:email>
	</itunes:owner>
	<?php endif; ?>
	<itunes:category text="<?php echo $top; // phpcs:ignore WordPress.Security.EscapeOutput -- from the fixed list, already escaped. ?>"<?php echo '' !== $sub ? '><itunes:category text="' . rrt_podcast_x( $sub ) . '"/></itunes:category>' : '/>'; // phpcs:ignore WordPress.Security.EscapeOutput ?>

	<?php if ( '' !== $art['url'] ) : ?>
	<itunes:image href="<?php echo rrt_podcast_u( $art['url'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>"/>
	<image>
		<url><?php echo rrt_podcast_u( $art['url'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></url>
		<title><?php echo rrt_podcast_x( $title ); // phpcs:ignore WordPress.Security.EscapeOutput ?></title>
		<link><?php echo rrt_podcast_u( home_url( '/' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></link>
	</image>
	<?php endif; ?>
	<podcast:guid><?php echo rrt_podcast_x( $m ? rrt_mfeed_guid() : rrt_podcast_guid() ); // phpcs:ignore WordPress.Security.EscapeOutput ?></podcast:guid>
	<?php if ( '' !== trim( (string) $s['email'] ) ) : ?>
	<podcast:locked owner="<?php echo rrt_podcast_x( $s['email'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>"><?php echo ( $m || ! empty( $s['locked'] ) ) ? 'yes' : 'no'; ?></podcast:locked>
	<?php endif; ?>
	<podcast:medium>podcast</podcast:medium>
	<?php if ( $person ) : ?>
	<podcast:person role="host"<?php echo '' !== $person['href'] ? ' href="' . rrt_podcast_u( $person['href'] ) . '"' : ''; echo '' !== $person['img'] ? ' img="' . rrt_podcast_u( $person['img'] ) . '"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>><?php echo rrt_podcast_x( $person['name'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></podcast:person>
	<?php endif; ?>
	<?php if ( $fund ) : ?>
	<podcast:funding url="<?php echo rrt_podcast_u( $fund['url'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>"><?php echo rrt_podcast_x( $fund['txt'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></podcast:funding>
	<?php endif; ?>
	<?php if ( '' !== $place ) : ?>
	<podcast:location><?php echo rrt_podcast_x( mb_substr( $place, 0, 128 ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></podcast:location>
	<?php endif; ?>
	<?php foreach ( $txts as $t ) : ?>
	<podcast:txt<?php echo '' !== $t['purpose'] ? ' purpose="' . rrt_podcast_x( $t['purpose'] ) . '"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>><?php echo rrt_podcast_x( $t['text'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></podcast:txt>
	<?php endforeach; ?>
	<?php foreach ( $eps as $e ) : ?>
	<item>
		<title><?php echo rrt_podcast_x( $e['title'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></title>
		<link><?php echo rrt_podcast_u( $e['link'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></link>
		<guid isPermaLink="false"><?php echo rrt_podcast_x( $e['guid'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></guid>
		<pubDate><?php echo rrt_podcast_x( $e['date'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></pubDate>
		<?php
		/* Sponsor messages (1.22.0): a line for each, at the start of the notes. */
		$spon_txt  = '';
		$spon_html = '';
		foreach ( isset( $e['sponsor'] ) ? (array) $e['sponsor'] : array() as $sl ) {
			$spon_txt  .= $sl['text'] . ( '' !== $sl['link'] ? ': ' . $sl['link'] : '.' ) . "\n";
			$spon_html .= '<p>' . esc_html( $sl['text'] ) . ( '' !== $sl['link'] ? ': <a href="' . esc_url( $sl['link'] ) . '"' . ( $sl['paid'] ? ' rel="sponsored"' : '' ) . '>' . esc_html( $sl['link'] ) . '</a>' : '.' ) . '</p>';
		}
		$spon_txt = '' !== $spon_txt ? $spon_txt . "\n" : '';
		?>
		<description><?php echo rrt_podcast_x( $spon_txt . $e['text'] . ( '' !== $e['credit'] ? ' (' . $e['credit'] . ')' : '' ) . ( '' !== $e['music'] ? ' ' . sprintf( /* translators: %s: music credit. */ __( 'Music: %s', 'foxfire-studio' ), $e['music'] ) : '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></description>
		<content:encoded><?php echo rrt_podcast_x( $spon_html . '<p>' . esc_html( $e['text'] ) . '</p>' . ( '' !== $e['credit'] ? '<p>' . esc_html( ucfirst( $e['credit'] ) ) . '.</p>' : '' ) . ( '' !== $e['music'] ? '<p>' . esc_html( sprintf( /* translators: %s: music credit. */ __( 'Music: %s', 'foxfire-studio' ), $e['music'] ) ) . '</p>' : '' ) . '<p><a href="' . esc_url( $e['link'] ) . '">' . esc_html( $e['more'] ) . '</a></p>' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></content:encoded>
		<enclosure url="<?php echo rrt_podcast_u( $e['enclosure'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>" length="<?php echo (int) $e['bytes']; ?>" type="<?php echo rrt_podcast_x( $e['type'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>"/>
		<?php if ( $e['secs'] ) : ?>
		<itunes:duration><?php echo (int) $e['secs']; ?></itunes:duration>
		<?php endif; ?>
		<itunes:author><?php echo rrt_podcast_x( $author ); // phpcs:ignore WordPress.Security.EscapeOutput ?></itunes:author>
		<itunes:explicit><?php echo $explict; // phpcs:ignore WordPress.Security.EscapeOutput ?></itunes:explicit>
		<itunes:episodeType><?php echo 'bonus' === $e['etype'] ? 'bonus' : 'full'; ?></itunes:episodeType>
		<?php if ( '' !== $e['vtt'] ) : ?>
		<podcast:transcript url="<?php echo rrt_podcast_u( $e['vtt'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>" type="text/vtt" language="<?php echo rrt_podcast_x( ! empty( $e['lang'] ) ? $e['lang'] : $lang ); // phpcs:ignore WordPress.Security.EscapeOutput ?>" rel="captions"/>
		<?php endif; ?>
		<?php if ( '' !== $e['chapters'] ) : ?>
		<podcast:chapters url="<?php echo rrt_podcast_u( $e['chapters'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>" type="application/json+chapters"/>
		<?php endif; ?>
		<?php if ( $e['soundbite'] ) : ?>
		<podcast:soundbite startTime="<?php echo esc_attr( number_format( $e['soundbite']['start'], 2, '.', '' ) ); ?>" duration="<?php echo esc_attr( number_format( $e['soundbite']['len'], 2, '.', '' ) ); ?>"><?php echo rrt_podcast_x( $e['soundbite']['title'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></podcast:soundbite>
		<?php endif; ?>
	</item>
	<?php endforeach; ?>
</channel>
</rss>
	<?php
}

/* The feed exists only while it is switched on; a new address needs the rewrite rules refreshed once. */
add_action(
	'init',
	function () {
		$s = rrt_podcast_settings();
		if ( empty( $s['on'] ) ) {
			return;
		}
		add_feed( 'podcast', 'rrt_podcast_feed' );
		if ( get_option( 'rrt_podcast_flush' ) ) {
			delete_option( 'rrt_podcast_flush' );
			flush_rewrite_rules( false );
		}
	}
);

/** Asks LiteSpeed (or any cache listening) to drop its copy of the feed, and of a post's chapters file when one is given. */
function rrt_podcast_purge( $post_id = 0 ) {
	do_action( 'litespeed_purge_url', rrt_podcast_url() );
	if ( $post_id && function_exists( 'rrt_chapters_url' ) ) {
		do_action( 'litespeed_purge_url', rrt_chapters_url( $post_id ) );
	}
}

/* A post's reading changing changes the feed. */
add_action(
	'save_post',
	function ( $post_id ) {
		if ( wp_is_post_revision( $post_id ) || empty( rrt_podcast_settings()['on'] ) ) {
			return;
		}
		rrt_podcast_purge( $post_id );
	},
	30
);

/*
 * ---------------------------------------------------------------------
 * Foxfire Studio, Podcast feed
 * ---------------------------------------------------------------------
 */

add_action(
	'admin_menu',
	function () {
		add_submenu_page( 'rrt-dashboard', __( 'Podcast feed', 'foxfire-studio' ), __( 'Podcast feed', 'foxfire-studio' ), 'manage_options', 'rrt-podcast', 'rrt_podcast_page' );
	},
	20
);

/** What the feed still needs before directories will take it. */
function rrt_podcast_checks( $s ) {
	$out = array();
	$art = rrt_podcast_art( $s );
	foreach ( $art['problems'] as $p ) {
		$out[] = $p;
	}
	if ( '' === trim( (string) $s['description'] ) ) {
		$out[] = __( 'Write a description of the show.', 'foxfire-studio' );
	}
	if ( ! is_email( (string) $s['email'] ) ) {
		$out[] = __( 'Give an owner email. Directories send their confirmation there, and anyone reading the feed can see it.', 'foxfire-studio' );
	}
	/* Podcasting 2.0 (1.10.0): a link or picture that apps would refuse is left out of the feed, and said here. */
	foreach ( rrt_podcast_person_img( $s )['problems'] as $p ) {
		$out[] = $p;
	}
	if ( '' !== trim( (string) $s['person_link'] ) && ! preg_match( '#^https://#i', trim( (string) $s['person_link'] ) ) ) {
		$out[] = __( 'The host\'s link must start with https://, so it is left out of the feed for now.', 'foxfire-studio' );
	}
	if ( '' !== trim( (string) $s['funding'] ) && ! rrt_podcast_funding( $s ) ) {
		$out[] = __( 'The support link must start with https://, so it is left out of the feed for now.', 'foxfire-studio' );
	}
	if ( '' !== trim( (string) $s['hub'] ) && rrt_ping_hub( $s ) !== trim( (string) $s['hub'] ) ) {
		$out[] = __( 'The WebSub hub must start with https://, so the usual hub is used for now.', 'foxfire-studio' );
	}
	return $out;
}

/** Prints the Podcast feed screen. */
function rrt_podcast_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Not allowed.', 'foxfire-studio' ) );
	}
	$s      = rrt_podcast_settings();
	$art    = rrt_podcast_art( $s );
	$checks = rrt_podcast_checks( $s );
	$eps    = array_filter( array_map( 'rrt_podcast_episode', rrt_podcast_posts() ) );
	$cats   = rrt_podcast_categories();
	$pimg   = rrt_podcast_person_img( $s );
	wp_enqueue_media();
	?>
	<div class="wrap rrt-podcast">
		<h1><?php esc_html_e( 'Podcast feed', 'foxfire-studio' ); ?></h1>
		<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved.', 'foxfire-studio' ); ?></p></div>
		<?php endif; ?>
		<p><?php esc_html_e( 'Every published post with a reading becomes an episode, newest first. Podcast apps fetch the readings straight from this site, so those plays are not counted in Foxfire Studio\'s listener numbers. Count downloads, below, counts their downloads instead.', 'foxfire-studio' ); ?></p>
		<?php if ( $s['on'] ) : ?>
			<p><strong><?php esc_html_e( 'The feed\'s address:', 'foxfire-studio' ); ?></strong> <input type="text" class="large-text code" readonly value="<?php echo esc_attr( rrt_podcast_url() ); ?>" onclick="this.select()" style="max-width:40em"> <a class="button" href="<?php echo esc_url( rrt_podcast_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open it', 'foxfire-studio' ); ?></a></p>
		<?php endif; ?>
		<h2><?php esc_html_e( 'Ready for directories?', 'foxfire-studio' ); ?></h2>
		<?php if ( $checks ) : ?>
			<ul class="ul-disc">
				<?php foreach ( $checks as $c ) : ?>
					<li><?php echo esc_html( $c ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php else : ?>
			<p><?php echo $s['on'] ? esc_html__( 'Yes: cover art, description and owner email are all set.', 'foxfire-studio' ) : esc_html__( 'Yes: cover art, description and owner email are all set. Switch the feed on to publish it.', 'foxfire-studio' ); ?></p>
		<?php endif; ?>
		<p><?php echo esc_html( sprintf( /* translators: %s: number of episodes. */ _n( '%s episode would be in the feed.', '%s episodes would be in the feed.', count( $eps ), 'foxfire-studio' ), number_format_i18n( count( $eps ) ) ) ); ?>
		<?php
		$nosize = array_filter(
			$eps,
			function ( $e ) {
				return ! $e['bytes'];
			}
		);
		if ( $nosize ) {
			echo ' ' . esc_html( sprintf( /* translators: %s: number of episodes. */ __( '%s of them could not be measured (their file is not in the media library), so their size shows as 0; apps still play them.', 'foxfire-studio' ), number_format_i18n( count( $nosize ) ) ) );
		}
		?>
		</p>
		<?php if ( $eps ) : ?>
			<table class="widefat striped rrt-pc-eps" style="max-width:60em">
				<thead><tr><th><?php esc_html_e( 'Episode', 'foxfire-studio' ); ?></th><th><?php esc_html_e( 'Chapters', 'foxfire-studio' ); ?></th><th><?php esc_html_e( 'Transcript', 'foxfire-studio' ); ?></th><th><?php esc_html_e( 'Soundbite', 'foxfire-studio' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $eps as $e ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( $e['link'] ); ?>"><?php echo esc_html( $e['title'] ); ?></a></td>
						<td><?php echo $e['nchapters'] ? esc_html( number_format_i18n( $e['nchapters'] ) ) : esc_html__( 'None', 'foxfire-studio' ); ?></td>
						<td><?php echo '' !== $e['vtt'] ? esc_html__( 'Yes', 'foxfire-studio' ) : esc_html__( 'None', 'foxfire-studio' ); ?></td>
						<td><?php
						if ( $e['soundbite'] ) {
							$sb = $e['soundbite'];
							echo esc_html( sprintf( /* translators: 1: start time (m:ss), 2: length in seconds, 3: where it came from. */ __( '%1$s, %2$s s, %3$s', 'foxfire-studio' ), rrt_clock( $sb['start'] ), number_format_i18n( $sb['len'], 0 ), 'pick' === $sb['from'] ? __( 'your pick', 'foxfire-studio' ) : __( 'top suggestion', 'foxfire-studio' ) ) );
							echo '<br><span class="description">' . esc_html( $sb['title'] ) . '</span>';
						} else {
							esc_html_e( 'None (the reading has no captions)', 'foxfire-studio' );
						}
						?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'rrt_podcast_save' ); ?>
			<input type="hidden" name="action" value="rrt_podcast_save">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Feed', 'foxfire-studio' ); ?></th>
					<td><label><input type="checkbox" name="on" value="1" <?php checked( $s['on'] ); ?>> <?php esc_html_e( 'Publish the podcast feed', 'foxfire-studio' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="rrt-pc-title"><?php esc_html_e( 'Show title', 'foxfire-studio' ); ?></label></th>
					<td><input class="regular-text" id="rrt-pc-title" name="title" value="<?php echo esc_attr( $s['title'] ); ?>" placeholder="<?php echo esc_attr( rrt_show_name() ); ?>">
					<p class="description"><?php esc_html_e( 'Left empty, the show name from Settings, Listening player is used.', 'foxfire-studio' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="rrt-pc-desc"><?php esc_html_e( 'Description', 'foxfire-studio' ); ?></label></th>
					<td><textarea class="large-text" rows="4" id="rrt-pc-desc" name="description" maxlength="4000"><?php echo esc_textarea( $s['description'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'What listeners see in podcast apps. Plain text.', 'foxfire-studio' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Cover art', 'foxfire-studio' ); ?></th>
					<td>
						<input type="hidden" id="rrt-pc-image" name="image" value="<?php echo (int) $s['image']; ?>">
						<span id="rrt-pc-image-view"><?php echo $art['url'] ? '<img src="' . esc_url( $art['url'] ) . '" alt="" style="width:120px;height:120px;object-fit:cover;vertical-align:middle;border-radius:6px">' : esc_html__( 'None', 'foxfire-studio' ); ?></span>
						<button type="button" class="button" id="rrt-pc-pick"><?php esc_html_e( 'Choose', 'foxfire-studio' ); ?></button>
						<p class="description"><?php esc_html_e( 'A square JPEG or PNG, 1400 to 3000 pixels wide, ideally under 512 KB.', 'foxfire-studio' ); ?><?php echo $art['w'] ? ' ' . esc_html( sprintf( /* translators: 1: width, 2: height, 3: file size. */ __( 'This one: %1$d by %2$d pixels, %3$s.', 'foxfire-studio' ), $art['w'], $art['h'], size_format( $art['bytes'], 0 ) ) ) : ''; ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="rrt-pc-author"><?php esc_html_e( 'Author', 'foxfire-studio' ); ?></label></th>
					<td><input class="regular-text" id="rrt-pc-author" name="author" value="<?php echo esc_attr( $s['author'] ); ?>" placeholder="<?php echo esc_attr( rrt_podcast_author( array_merge( $s, array( 'author' => '' ) ) ) ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="rrt-pc-email"><?php esc_html_e( 'Owner email', 'foxfire-studio' ); ?></label></th>
					<td><input class="regular-text" type="email" id="rrt-pc-email" name="email" value="<?php echo esc_attr( $s['email'] ); ?>">
					<p class="description"><?php esc_html_e( 'Public: anyone reading the feed can see it. Apple and Spotify send their confirmation codes here.', 'foxfire-studio' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="rrt-pc-cat"><?php esc_html_e( 'Category', 'foxfire-studio' ); ?></label></th>
					<td><select id="rrt-pc-cat" name="cat">
						<?php
						foreach ( $cats as $top => $subs ) {
							$label = html_entity_decode( $top, ENT_QUOTES, 'UTF-8' );
							printf( '<option value="%s" %s>%s</option>', esc_attr( $label ), selected( $s['category'] === $top && '' === $s['subcategory'], true, false ), esc_html( $label ) );
							foreach ( $subs as $sub ) {
								printf( '<option value="%s" %s>%s</option>', esc_attr( $label . '|' . $sub ), selected( $s['category'] === $top && $s['subcategory'] === $sub, true, false ), esc_html( $label . ', ' . $sub ) );
							}
						}
						?>
					</select></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Content', 'foxfire-studio' ); ?></th>
					<td>
						<label><input type="radio" name="explicit" value="0" <?php checked( ! $s['explicit'] ); ?>> <?php esc_html_e( 'Clean', 'foxfire-studio' ); ?></label><br>
						<label><input type="radio" name="explicit" value="1" <?php checked( (bool) $s['explicit'] ); ?>> <?php esc_html_e( 'Explicit (strong language or adult themes)', 'foxfire-studio' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="rrt-pc-copy"><?php esc_html_e( 'Copyright', 'foxfire-studio' ); ?></label></th>
					<td><input class="regular-text" id="rrt-pc-copy" name="copyright" value="<?php echo esc_attr( $s['copyright'] ); ?>" placeholder="<?php echo esc_attr( '© ' . gmdate( 'Y' ) . ' ' . rrt_podcast_author( $s ) ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Lock', 'foxfire-studio' ); ?></th>
					<td><label><input type="checkbox" name="locked" value="1" <?php checked( (bool) $s['locked'] ); ?>> <?php esc_html_e( 'Ask other podcast hosts not to import this show', 'foxfire-studio' ); ?></label>
					<p class="description"><?php esc_html_e( 'Recommended. Untick only when moving the show to a hosting service yourself.', 'foxfire-studio' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Count downloads', 'foxfire-studio' ); ?></th>
					<td><label><input type="checkbox" name="count" value="1" <?php checked( (bool) $s['count'] ); ?>> <?php esc_html_e( 'Count downloads by podcast apps', 'foxfire-studio' ); ?></label>
					<p class="description"><?php
					printf(
						/* translators: %s: an example short address, shown as code. */
						esc_html__( 'Each episode in the feed then points to a short address on this site, such as %s, which counts the download and sends the app the audio. Counted following the IAB Tech Lab guidelines (version 2.3, see Download counting below): once per podcast consumer, episode file and day, once the app has asked for a minute of audio; test requests, Apple Watch duplicates, bots, data centres and VPNs are left out, and the app is named from its user agent. Members\' feeds are counted too. No IP address is stored and nothing is sent elsewhere. The numbers appear on the Overview. Short addresses keep working if you switch this off later. Spotify keeps its own copy of each episode, so most of its listeners show only in Spotify for Creators. Before switching it on, add a line to your site\'s privacy policy (the paragraph at the bottom of Foxfire Studio, Settings includes one while it is on).', 'foxfire-studio' ),
						'<code>' . esc_html( rrt_dl_url( 123, 'reading.mp3' ) ) . '</code>'
					);
					?></p></td>
				</tr>
			</table>
			<h2><?php esc_html_e( 'More for podcast apps', 'foxfire-studio' ); ?></h2>
			<p><?php esc_html_e( 'Apps that read the Podcasting 2.0 tags show these; the rest ignore them. Each episode also gets its chapters (when its MP3 has them), its transcript and a soundbite: the stretch chosen with Use for the feed on the reading\'s page, else the top suggested clip.', 'foxfire-studio' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="rrt-pc-person"><?php esc_html_e( 'Host', 'foxfire-studio' ); ?></label></th>
					<td>
						<input class="regular-text" id="rrt-pc-person" name="person" value="<?php echo esc_attr( $s['person'] ); ?>" placeholder="<?php echo esc_attr( rrt_podcast_author( $s ) ); ?>">
						<p><label for="rrt-pc-plink"><?php esc_html_e( 'Link', 'foxfire-studio' ); ?></label><br><input class="regular-text code" type="url" id="rrt-pc-plink" name="person_link" value="<?php echo esc_attr( $s['person_link'] ); ?>" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>"></p>
						<p><?php esc_html_e( 'Picture', 'foxfire-studio' ); ?><br>
						<input type="hidden" id="rrt-pc-pimg" name="person_img" value="<?php echo (int) $s['person_img']; ?>">
						<span id="rrt-pc-pimg-view"><?php echo $pimg['url'] ? '<img src="' . esc_url( $pimg['url'] ) . '" alt="" style="width:80px;height:80px;object-fit:cover;vertical-align:middle;border-radius:50%">' : esc_html__( 'None', 'foxfire-studio' ); ?></span>
						<button type="button" class="button" id="rrt-pc-ppick"><?php esc_html_e( 'Choose', 'foxfire-studio' ); ?></button>
						<button type="button" class="button-link" id="rrt-pc-pclear"><?php esc_html_e( 'Remove', 'foxfire-studio' ); ?></button></p>
						<p class="description"><?php esc_html_e( 'Shown in apps that list the people on a show. Left empty, the name is the author and the link is this site. The picture is optional: a square JPEG or PNG.', 'foxfire-studio' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="rrt-pc-fund"><?php esc_html_e( 'Support link', 'foxfire-studio' ); ?></label></th>
					<td>
						<input class="large-text code" type="url" id="rrt-pc-fund" name="funding" value="<?php echo esc_attr( $s['funding'] ); ?>" placeholder="https://" style="max-width:40em">
						<p><label for="rrt-pc-fundtxt"><?php esc_html_e( 'Words beside it', 'foxfire-studio' ); ?></label><br><input class="regular-text" id="rrt-pc-fundtxt" name="funding_txt" maxlength="128" value="<?php echo esc_attr( $s['funding_txt'] ); ?>" placeholder="<?php echo esc_attr( sprintf( /* translators: %s: the show's title. */ __( 'Support %s', 'foxfire-studio' ), rrt_podcast_title( $s ) ) ); ?>"></p>
						<p class="description"><?php esc_html_e( 'A page where listeners can support the show, such as a donation page. Apps show it as a button with these words. It must start with https://.', 'foxfire-studio' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="rrt-pc-loc"><?php esc_html_e( 'Place', 'foxfire-studio' ); ?></label></th>
					<td><input class="regular-text" id="rrt-pc-loc" name="location" maxlength="128" value="<?php echo esc_attr( $s['location'] ); ?>">
					<p class="description"><?php esc_html_e( 'Optional: where the show is made or what place it is about, as a name. Left empty, no place is given.', 'foxfire-studio' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="rrt-pc-txt"><?php esc_html_e( 'Text lines', 'foxfire-studio' ); ?></label></th>
					<td><textarea class="large-text code" rows="3" id="rrt-pc-txt" name="txt"><?php echo esc_textarea( $s['txt'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Optional, one a line. Some directories ask you to put a code in the feed to prove the show is yours: paste it here as they say, such as "verify: abc123" (a single word and a colon at the start gives the line that purpose). Anyone reading the feed can see these lines.', 'foxfire-studio' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Pings', 'foxfire-studio' ); ?></th>
					<td>
						<label><input type="checkbox" name="pings" value="1" <?php checked( rrt_ping_on( $s ) ); ?> <?php disabled( ! $s['on'] ); ?>> <?php esc_html_e( 'Tell podcast apps when a new reading is in the feed', 'foxfire-studio' ); ?></label>
						<p><label for="rrt-pc-hub"><?php esc_html_e( 'WebSub hub', 'foxfire-studio' ); ?></label><br><input class="regular-text code" type="url" id="rrt-pc-hub" name="hub" value="<?php echo esc_attr( $s['hub'] ); ?>" placeholder="<?php echo esc_attr( RRT_PING_HUB ); ?>"></p>
						<p class="description"><?php echo esc_html( $s['on'] ? __( 'When a published post gets a reading, the feed\'s address is sent to Podcast Index and the WebSub hub, so apps fetch the feed within minutes instead of hours. Only the feed\'s address is sent, at most once every 10 minutes. Off until switched on.', 'foxfire-studio' ) : __( 'Available once the feed is published. When a published post gets a reading, the feed\'s address is sent to Podcast Index and the WebSub hub, so apps fetch the feed within minutes instead of hours. Only the feed\'s address is sent, at most once every 10 minutes.', 'foxfire-studio' ) ); ?>
						<?php
						$pl = rrt_ping_last();
						if ( $pl['at'] ) {
							echo ' ' . esc_html( sprintf( /* translators: 1: date and time, 2: Podcast Index's answer, 3: the hub's answer. */ __( 'Last sent %1$s: Podcast Index answered %2$s, the hub %3$s.', 'foxfire-studio' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $pl['at'] ), '' !== $pl['index'] ? $pl['index'] : '-', '' !== $pl['hub'] ? $pl['hub'] : '-' ) );
						}
						?></p>
					</td>
				</tr>
			</table>
			<?php
			/* Member feeds (1.21.0). */
			if ( function_exists( 'rrt_member_settings_rows' ) ) {
				rrt_member_settings_rows();
			}
			/* Sponsor messages (1.22.0). */
			if ( function_exists( 'rrt_spot_settings_rows' ) ) {
				rrt_spot_settings_rows();
			}
			?>
			<?php submit_button( __( 'Save', 'foxfire-studio' ) ); ?>
		</form>
		<?php
		if ( function_exists( 'rrt_member_table' ) ) {
			rrt_member_table();
		}
		/* Storage offload (1.23.0): a form of its own. */
		if ( function_exists( 'rrt_off_section' ) ) {
			rrt_off_section();
		}
		/* Download counting (1.24.0): a form of its own too. */
		if ( function_exists( 'rrt_dl_section' ) ) {
			rrt_dl_section();
		}
		/* Check private folders (1.25.0). */
		if ( function_exists( 'rrt_private_check_section' ) ) {
			rrt_private_check_section();
		}
		?>
		<?php if ( $s['on'] ) : ?>
			<h2><?php esc_html_e( 'Next', 'foxfire-studio' ); ?></h2>
			<p><?php
			echo wp_kses(
				sprintf(
					/* translators: 1: link to the podba.se validator, 2: link to Apple Podcasts Connect, 3: link to Spotify for Creators. */
					__( 'Check the feed at %1$s, then add it in %2$s and %3$s. New readings appear in the feed by themselves.', 'foxfire-studio' ),
					'<a href="https://podba.se/validate/?url=' . rawurlencode( rrt_podcast_url() ) . '" target="_blank" rel="noopener">podba.se</a>',
					'<a href="https://podcastsconnect.apple.com/" target="_blank" rel="noopener">Apple Podcasts Connect</a>',
					'<a href="https://creators.spotify.com/" target="_blank" rel="noopener">Spotify for Creators</a>'
				),
				array( 'a' => array( 'href' => true, 'target' => true, 'rel' => true ) )
			);
			?></p>
		<?php endif; ?>
	</div>
	<script>
	(function () {
		if (!window.wp || !wp.media) { return; }
		function picker(button, field, view, title, css) {
			var b = document.getElementById(button);
			if (!b) { return; }
			b.addEventListener('click', function (e) {
				e.preventDefault();
				var f = wp.media({ title: title, button: { text: <?php echo wp_json_encode( __( 'Use this image', 'foxfire-studio' ) ); ?> }, library: { type: 'image' }, multiple: false });
				f.on('select', function () {
					var a = f.state().get('selection').first().toJSON();
					document.getElementById(field).value = a.id;
					var v = document.getElementById(view);
					v.innerHTML = '';
					var img = document.createElement('img');
					img.src = a.url;
					img.alt = '';
					img.style.cssText = css;
					v.appendChild(img);
				});
				f.open();
			});
		}
		picker('rrt-pc-pick', 'rrt-pc-image', 'rrt-pc-image-view', <?php echo wp_json_encode( __( 'Choose cover art', 'foxfire-studio' ) ); ?>, 'width:120px;height:120px;object-fit:cover;vertical-align:middle;border-radius:6px');
		picker('rrt-pc-ppick', 'rrt-pc-pimg', 'rrt-pc-pimg-view', <?php echo wp_json_encode( __( 'Choose the host picture', 'foxfire-studio' ) ); ?>, 'width:80px;height:80px;object-fit:cover;vertical-align:middle;border-radius:50%');
		var c = document.getElementById('rrt-pc-pclear');
		if (c) {
			c.addEventListener('click', function (e) {
				e.preventDefault();
				document.getElementById('rrt-pc-pimg').value = '0';
				document.getElementById('rrt-pc-pimg-view').textContent = <?php echo wp_json_encode( __( 'None', 'foxfire-studio' ) ); ?>;
			});
		}
	})();
	</script>
	<?php
}

add_action(
	'admin_post_rrt_podcast_save',
	function () {
		check_admin_referer( 'rrt_podcast_save' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'foxfire-studio' ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification -- checked above.
		$old = rrt_podcast_settings();
		$s   = $old;
		$s['on']          = ! empty( $_POST['on'] );
		$s['title']       = isset( $_POST['title'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['title'] ) ), 0, 200 ) : '';
		$s['description'] = isset( $_POST['description'] ) ? mb_substr( sanitize_textarea_field( wp_unslash( $_POST['description'] ) ), 0, 4000 ) : '';
		$img              = isset( $_POST['image'] ) ? absint( $_POST['image'] ) : 0;
		$s['image']       = ( $img && 'attachment' === get_post_type( $img ) ) ? $img : 0;
		$s['author']      = isset( $_POST['author'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['author'] ) ), 0, 200 ) : '';
		$email            = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$s['email']       = is_email( $email ) ? $email : '';
		$cat              = isset( $_POST['cat'] ) ? sanitize_text_field( wp_unslash( $_POST['cat'] ) ) : '';
		$parts            = explode( '|', $cat, 2 );
		$top              = str_replace( '&', '&amp;', $parts[0] );
		$cats             = rrt_podcast_categories();
		if ( isset( $cats[ $top ] ) ) {
			$s['category']    = $top;
			$s['subcategory'] = ( isset( $parts[1] ) && in_array( $parts[1], $cats[ $top ], true ) ) ? $parts[1] : '';
		}
		$s['explicit']  = ! empty( $_POST['explicit'] );
		$s['copyright'] = isset( $_POST['copyright'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['copyright'] ) ), 0, 200 ) : '';
		$s['locked']    = ! empty( $_POST['locked'] );
		$s['count']     = ! empty( $_POST['count'] );
		/* Podcasting 2.0 (1.10.0). */
		$s['person']      = isset( $_POST['person'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['person'] ) ), 0, 128 ) : '';
		$s['person_link'] = isset( $_POST['person_link'] ) ? esc_url_raw( trim( wp_unslash( $_POST['person_link'] ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned on this line (esc_url_raw, or each line checked below).
		$pimg             = isset( $_POST['person_img'] ) ? absint( $_POST['person_img'] ) : 0;
		$s['person_img']  = ( $pimg && 'attachment' === get_post_type( $pimg ) ) ? $pimg : 0;
		$s['funding']     = isset( $_POST['funding'] ) ? esc_url_raw( trim( wp_unslash( $_POST['funding'] ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned on this line (esc_url_raw, or each line checked below).
		$s['funding_txt'] = isset( $_POST['funding_txt'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['funding_txt'] ) ), 0, 128 ) : '';
		$s['location']    = isset( $_POST['location'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['location'] ) ), 0, 128 ) : '';
		$s['txt']         = isset( $_POST['txt'] ) ? mb_substr( sanitize_textarea_field( wp_unslash( $_POST['txt'] ) ), 0, 8000 ) : '';
		/* Pings are only allowed while the feed is published. */
		$s['pings'] = $s['on'] && ! empty( $_POST['pings'] );
		$s['hub']   = isset( $_POST['hub'] ) ? esc_url_raw( trim( wp_unslash( $_POST['hub'] ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned on this line (esc_url_raw, or each line checked below).
		// phpcs:enable
		/* Member feeds (1.21.0). */
		if ( function_exists( 'rrt_member_settings_from_post' ) ) {
			$s = rrt_member_settings_from_post( $s );
		}
		/* Sponsor messages (1.22.0). */
		if ( function_exists( 'rrt_spot_settings_from_post' ) ) {
			$s = rrt_spot_settings_from_post( $s );
		}
		update_option( 'rrt_podcast', $s );
		if ( $s['on'] && ! $old['on'] ) {
			update_option( 'rrt_podcast_flush', 1 );
		}
		if ( $s['on'] ) {
			rrt_podcast_guid();
		}
		rrt_podcast_purge();
		wp_safe_redirect( admin_url( 'admin.php?page=rrt-podcast&saved=1' ) );
		exit;
	}
);
