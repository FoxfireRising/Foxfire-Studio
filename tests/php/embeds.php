<?php
/**
 * YouTube and SoundCloud players that load on play (1.35.0), run inside the
 * test WordPress:
 *   php wp-cli.phar --path=<wordpress> eval-file tests/php/embeds.php
 * Off by default and then untouched; with a switch on, every way of adding a
 * player becomes a panel (the Embed block, a link on its own line, the
 * [embed] shortcode, copied embed code, a block widget); YouTube watch,
 * youtu.be, Shorts, playlists and start times; SoundCloud tracks, playlists
 * and the visual player; each service by its own switch; other players and
 * look-alike addresses untouched; feeds, the admin, the studio and REST get
 * the original; the title and picture fetched once and reused, from stand-in
 * answers (no real requests); a missing picture and its retry; titles
 * escaped; Clear saved pictures; the 90 days and stray files; the privacy
 * paragraph; the settings section; the prefetch on save. Prints one line per
 * check and exits 1 if any fail. Leaves nothing behind.
 */

$GLOBALS['em_pass'] = 0;
$GLOBALS['em_fail'] = array();

function em_t( $ok, $name, $detail = '' ) {
	if ( $ok ) {
		++$GLOBALS['em_pass'];
		echo "ok   $name\n";
	} else {
		$GLOBALS['em_fail'][] = $name;
		echo "FAIL $name" . ( '' !== $detail ? ": $detail" : '' ) . "\n";
	}
}

/* A 2 x 1 PNG, written from bytes (no image library needed). */
$GLOBALS['em_png'] = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAABCAIAAAB7QOjdAAAAD0lEQVR4nGM44OBwwMEBAAnHAoHjluSfAAAAAElFTkSuQmCC' );

/* Stand-in answers from YouTube and SoundCloud; every request is counted. */
$GLOBALS['em_reqs']    = array();
$GLOBALS['em_missing'] = array( 'MissingVid1' );
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		$GLOBALS['em_reqs'][] = $url;
		$ok                   = function ( $body, $type = 'application/json' ) {
			return array( 'headers' => array( 'content-type' => $type ), 'body' => $body, 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
		};
		$none                 = array( 'headers' => array(), 'body' => 'Not Found', 'response' => array( 'code' => 404, 'message' => 'Not Found' ), 'cookies' => array(), 'filename' => null );
		if ( preg_match( '#^https://(www\.)?youtube\.com/oembed\?#', $url ) ) {
			parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
			$target = (string) ( $q['url'] ?? '' );
			foreach ( $GLOBALS['em_missing'] as $m ) {
				if ( false !== strpos( $target, $m ) ) {
					return $none;
				}
			}
			$id   = preg_match( '#[?&]v=([A-Za-z0-9_-]{11})#', $target, $m ) ? $m[1] : ( preg_match( '#(?:youtu\.be/|shorts/)([A-Za-z0-9_-]{11})#', $target, $m ) ? $m[1] : '' );
			$list = preg_match( '#[?&]list=([A-Za-z0-9_-]+)#', $target, $m ) ? $m[1] : '';
			$src  = '' !== $id ? 'https://www.youtube.com/embed/' . $id . '?feature=oembed' : 'https://www.youtube.com/embed/videoseries?list=' . $list;
			$tall = false !== strpos( $target, '/shorts/' );
			$title = 'EvilTitle01' === $id ? 'A "quoted" <b>title</b> & more' : ( '' !== $id ? 'Video ' . $id : 'Playlist ' . $list );
			return $ok(
				wp_json_encode(
					array(
						'type'          => 'video',
						'title'         => $title,
						'width'         => $tall ? 113 : 500,
						'height'        => $tall ? 200 : 281,
						'thumbnail_url' => 'https://i.ytimg.com/vi/' . ( '' !== $id ? $id : 'list' ) . '/hqdefault.jpg',
						'html'          => '<iframe width="' . ( $tall ? 113 : 500 ) . '" height="' . ( $tall ? 200 : 281 ) . '" src="' . $src . '" title="' . esc_attr( $title ) . '" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>',
					)
				)
			);
		}
		if ( preg_match( '#^https://soundcloud\.com/oembed\?#', $url ) ) {
			parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
			$target = (string) ( $q['url'] ?? '' );
			$set    = false !== strpos( $target, '/sets/' ) || false !== strpos( $target, '/playlists/' );
			return $ok(
				wp_json_encode(
					array(
						'type'          => 'rich',
						'title'         => $set ? 'A made-up set' : 'A made-up track',
						'thumbnail_url' => 'https://i1.sndcdn.com/artworks-made-up-t500x500.png',
						'html'          => '<iframe width="100%" height="400" scrolling="no" frameborder="no" src="https://w.soundcloud.com/player/?visual=true&url=' . rawurlencode( 'https://api.soundcloud.com/' . ( $set ? 'playlists/55' : 'tracks/123' ) ) . '&show_artwork=true"></iframe>',
					)
				)
			);
		}
		if ( preg_match( '#^https://i\.ytimg\.com/#', $url ) || preg_match( '#^https://i1\.sndcdn\.com/#', $url ) ) {
			foreach ( $GLOBALS['em_missing'] as $m ) {
				if ( false !== strpos( $url, $m ) ) {
					return $none;
				}
			}
			return $ok( $GLOBALS['em_png'], 'image/png' );
		}
		return $none;
	},
	1,
	3
);

/** Requests our own look-ups made (WordPress's own oEmbed requests carry maxwidth). */
function em_ours( $from = 0 ) {
	$n = 0;
	foreach ( array_slice( $GLOBALS['em_reqs'], $from ) as $u ) {
		if ( false !== strpos( $u, '/oembed?format=json&url=' ) ) {
			++$n;
		}
	}
	return $n;
}

$em_post = wp_insert_post( array( 'post_title' => 'Embeds test (draft)', 'post_status' => 'draft', 'post_content' => 'x' ) );
$GLOBALS['post'] = get_post( $em_post ); // phpcs:ignore
setup_postdata( $GLOBALS['post'] );
global $wp_embed;
$wp_embed->post_ID = $em_post;

function em_render( $c ) {
	return apply_filters( 'the_content', $c );
}

function em_panels( $html ) {
	return substr_count( $html, 'class="rrt-embed ' );
}

delete_option( 'rrt_embeds' );
rrt_embeds_clear();
wp_set_current_user( 1 );
/* One page view fetches a few at most; this run is many page views in one. */
add_filter( 'rrt_embeds_fetch_max', function () { return 1000; } );

$em_block = "<!-- wp:embed {\"url\":\"https://www.youtube.com/watch?v=AbCdEfGhIj1\",\"type\":\"video\",\"providerNameSlug\":\"youtube\",\"responsive\":true,\"className\":\"wp-embed-aspect-16-9 wp-has-aspect-ratio\"} -->\n<figure class=\"wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio\"><div class=\"wp-block-embed__wrapper\">\nhttps://www.youtube.com/watch?v=AbCdEfGhIj1\n</div></figure>\n<!-- /wp:embed -->";
$em_iframe = '<!-- wp:html --><iframe width="560" height="315" src="https://www.youtube.com/embed/PastedVid01?start=30&amp;cc_load_policy=1" title="Pasted video" frameborder="0" allowfullscreen></iframe><!-- /wp:html -->';
$em_sc     = '<iframe width="100%" height="166" scrolling="no" frameborder="no" allow="autoplay" src="https://w.soundcloud.com/player/?url=https%3A//api.soundcloud.com/tracks/987654&amp;color=%23ff5500&amp;auto_play=false&amp;hide_related=false&amp;show_comments=true"></iframe>';
$em_vimeo  = '<iframe src="https://player.vimeo.com/video/76979871" width="640" height="360"></iframe>';

/* Off by default. */
$s = rrt_embeds_settings();
em_t( false === $s['yt'] && false === $s['sc'] && ! rrt_embeds_any_on(), 'both switches are off on a new site' );
$out = em_render( $em_block . $em_iframe . $em_sc );
em_t( 0 === em_panels( $out ) && false !== strpos( $out, 'youtube.com/embed/AbCdEfGhIj1' ) && false !== strpos( $out, 'youtube.com/embed/PastedVid01' ) && false !== strpos( $out, 'w.soundcloud.com/player' ), 'switched off, every player is shown exactly as WordPress shows it' );
em_t( false === strpos( $out, 'data-rrt-from' ), 'and nothing is added to them' );
em_t( 0 === em_ours(), 'and nothing is fetched' );
em_t( '' === rrt_embeds_privacy_text(), 'and the privacy paragraph says nothing' );

/* YouTube on. */
update_option( 'rrt_embeds', array( 'yt' => true, 'sc' => false ) );
$r0  = count( $GLOBALS['em_reqs'] );
$out = em_render( $em_block );
em_t( 1 === em_panels( $out ) && false === stripos( $out, '<iframe' ), 'the Embed block becomes a panel, with no player left in the page' );
em_t( false !== strpos( $out, 'data-rrt-play="https://www.youtube-nocookie.com/embed/AbCdEfGhIj1?' ), 'the panel loads the privacy-enhanced player on play' );
preg_match( '/data-rrt-play="([^"]+)"/', $out, $m );
$play = html_entity_decode( $m[1] ?? '', ENT_QUOTES );
parse_str( (string) wp_parse_url( $play, PHP_URL_QUERY ), $pq );
em_t( '1' === ( $pq['autoplay'] ?? '' ) && '1' === ( $pq['enablejsapi'] ?? '' ) && '1' === ( $pq['playsinline'] ?? '' ) && ! empty( $pq['origin'] ), 'which starts at once and accepts pause messages from this page', $play );
em_t( false !== strpos( $out, 'href="https://www.youtube.com/watch?v=AbCdEfGhIj1"' ), 'without JavaScript the panel is a link to the video on YouTube' );
em_t( false !== strpos( $out, 'Video AbCdEfGhIj1' ) && false !== strpos( $out, '<span class="rrt-embed-sr">Play </span>Video AbCdEfGhIj1<span class="rrt-embed-sr">,</span>' ), 'it shows the title, and the button is named for it in its own words' );
em_t( false !== strpos( $out, 'Plays from YouTube' ), 'and says where it plays from' );
em_t( (bool) preg_match( '#<img class="rrt-embed-pic" src="[^"]*/uploads/rrt-embeds/yt-[a-z0-9_-]+\.(webp|jpg|png)" alt="" loading="lazy"#', $out ), 'its picture comes from this site, loaded when scrolled near', $out );
em_t( false === strpos( $out, 'i.ytimg.com' ) && false === strpos( $out, 'src="https://www.youtube' ), 'nothing on the page asks YouTube for anything before play' );
em_t( false !== strpos( $out, 'wp-block-embed__wrapper' ) && false !== strpos( $out, 'aspect-ratio:1.7794;' ), 'it stays in the block’s box, at the player’s shape', $out );
em_t( 1 === em_ours( $r0 ), 'the title and picture were asked for once' );
$list = rrt_embeds_list();
$key  = array_keys( $list )[0] ?? '';
em_t( 1 === count( $list ) && 0 === strpos( $key, 'yt-abcdefghij1' ) && file_exists( rrt_embeds_dir() . $list[ $key ]['f'] ) && 'Video AbCdEfGhIj1' === $list[ $key ]['t'], 'and kept in the list, with the picture in the folder', wp_json_encode( $list ) );
em_t( file_exists( rrt_embeds_dir() . 'index.php' ), 'the folder cannot be listed' );
$r1  = count( $GLOBALS['em_reqs'] );
$out = em_render( $em_block );
em_t( 1 === em_panels( $out ) && 0 === em_ours( $r1 ), 'shown again, nothing is asked again' );

/* Pasted embed code, with its settings. */
$r1  = count( $GLOBALS['em_reqs'] );
$out = em_render( $em_iframe );
em_t( 1 === em_panels( $out ) && false === stripos( $out, '<iframe' ), 'embed code copied from YouTube becomes a panel' );
preg_match( '/data-rrt-play="([^"]+)"/', $out, $m );
parse_str( (string) wp_parse_url( html_entity_decode( $m[1] ?? '', ENT_QUOTES ), PHP_URL_QUERY ), $pq );
em_t( '30' === ( $pq['start'] ?? '' ) && '1' === ( $pq['cc_load_policy'] ?? '' ), 'keeping its start time and captions setting' );
em_t( false !== strpos( $out, 'width:560px;' ) && false !== strpos( $out, 'aspect-ratio:1.7778;' ), 'at its own size' );
em_t( 1 === em_ours( $r1 ), 'its picture is fetched once too' );

/* A link on its own line, the shortcode, youtu.be with a time, a Short, a playlist. */
$out = em_render( "Before.\n\nhttps://youtu.be/YoutuBeVid1?t=1m30s\n\nAfter." );
preg_match( '/data-rrt-play="([^"]+)"/', $out, $m );
parse_str( (string) wp_parse_url( html_entity_decode( $m[1] ?? '', ENT_QUOTES ), PHP_URL_QUERY ), $pq );
em_t( 1 === em_panels( $out ) && '90' === ( $pq['start'] ?? '' ), 'a youtu.be link on its own line becomes a panel that starts at 1:30', $m[1] ?? '' );
em_t( false !== strpos( $out, 'Before.' ) && false !== strpos( $out, 'After.' ), 'and the words around it are untouched' );
em_t( false !== strpos( $out, 'href="https://www.youtube.com/watch?v=YoutuBeVid1&#038;t=90s"' ), 'its link starts at the same moment' );
$out = em_render( '[embed]https://www.youtube.com/watch?v=Shortcode01[/embed]' );
em_t( 1 === em_panels( $out ) && false !== strpos( $out, 'youtube-nocookie.com/embed/Shortcode01' ), 'the [embed] shortcode becomes a panel' );
$out = em_render( "https://www.youtube.com/shorts/ShortVid001\n" );
em_t( 1 === em_panels( $out ) && false !== strpos( $out, 'is-tall' ) && false !== strpos( $out, 'aspect-ratio:0.5625;width:315px;' ), 'a Short is a tall panel, 9 by 16, as wide as YouTube shows one', $out );
em_t( false !== strpos( $out, 'href="https://www.youtube.com/shorts/ShortVid001"' ), 'whose link opens the Short' );
$out = em_render( "https://www.youtube.com/playlist?list=PLmadeUpList01\n" );
em_t( 1 === em_panels( $out ) && false !== strpos( $out, 'youtube-nocookie.com/embed/videoseries?' ) && false !== strpos( $out, 'list=PLmadeUpList01' ), 'a playlist becomes a panel that plays the playlist', $out );
em_t( false !== strpos( $out, 'Playlist PLmadeUpList01' ), 'with the playlist’s title' );

/* A missing picture and title: the panel still works, and the look-up waits a day. */
$r1  = count( $GLOBALS['em_reqs'] );
$out = em_render( '<iframe width="560" height="315" src="https://www.youtube.com/embed/MissingVid1" title="Its own title"></iframe>' );
em_t( 1 === em_panels( $out ) && false !== strpos( $out, 'is-nopic' ) && false === strpos( $out, '<img' ) && false !== strpos( $out, 'Its own title' ), 'with no picture, the panel shows the player’s own title on the site’s colours' );
$out = em_render( '<iframe width="560" height="315" src="https://www.youtube.com/embed/MissingVid1"></iframe>' );
em_t( false !== strpos( $out, '>YouTube video<' ) && 1 === em_ours( $r1 ), 'with no title either it says YouTube video, and the look-up is not repeated at once' );
$list = rrt_embeds_list();
foreach ( $list as $k => $e ) {
	if ( ! empty( $e['fail'] ) ) {
		$list[ $k ]['fail'] = time() - 2 * DAY_IN_SECONDS;
		$GLOBALS['em_missing'] = array();
	}
}
rrt_embeds_list_save( $list );
$r1  = count( $GLOBALS['em_reqs'] );
$out = em_render( '<iframe width="560" height="315" src="https://www.youtube.com/embed/MissingVid1"></iframe>' );
em_t( 1 === em_ours( $r1 ) && false === strpos( $out, 'is-nopic' ), 'a day later it is tried again, and the picture arrives' );

/* Titles are escaped. */
$out = em_render( '<iframe width="560" height="315" src="https://www.youtube.com/embed/EvilTitle01"></iframe>' );
em_t( false === strpos( $out, '<b>title</b>' ) && false === strpos( $out, '"quoted"' ) && false !== strpos( $out, 'A &quot;quoted&quot; title &amp; more' ), 'a title’s marks and quotes are shown as text', $out );

/* Look-alikes and other players are left alone. */
$out = em_render( '<iframe src="https://www.youtube.com.example.org/embed/AbCdEfGhIj1"></iframe><iframe src="https://example.org/www.youtube.com/embed/AbCdEfGhIj1"></iframe><iframe src="https://www.youtube.com/embed/bad%22id"></iframe>' . $em_vimeo );
em_t( 0 === em_panels( $out ) && 4 === substr_count( $out, '<iframe' ), 'look-alike addresses, a bad id and other services’ players are left as they are' );
$out = em_render( $em_sc );
em_t( 0 === em_panels( $out ) && false !== strpos( $out, 'w.soundcloud.com/player' ), 'with only YouTube switched on, SoundCloud players are left as they are' );

/* SoundCloud on. */
update_option( 'rrt_embeds', array( 'yt' => false, 'sc' => true ) );
$out = em_render( $em_block );
em_t( 0 === em_panels( $out ) && false !== strpos( $out, 'youtube.com/embed/AbCdEfGhIj1' ), 'with only SoundCloud switched on, YouTube players are left as they are' );
$r1  = count( $GLOBALS['em_reqs'] );
$out = em_render( $em_sc );
em_t( 1 === em_panels( $out ) && false === stripos( $out, '<iframe' ) && false !== strpos( $out, 'rrt-embed-sc is-wide' ) && false !== strpos( $out, 'height:166px;' ) && false !== strpos( $out, 'width:100%;' ), 'SoundCloud embed code becomes a panel at the player’s own height', $out );
preg_match( '/data-rrt-play="([^"]+)"/', $out, $m );
$play = html_entity_decode( $m[1] ?? '', ENT_QUOTES );
parse_str( (string) wp_parse_url( $play, PHP_URL_QUERY ), $pq );
em_t( 0 === strpos( $play, 'https://w.soundcloud.com/player/?url=' ) && 'https://api.soundcloud.com/tracks/987654' === ( $pq['url'] ?? '' ) && 'true' === ( $pq['auto_play'] ?? '' ) && '#ff5500' === ( $pq['color'] ?? '' ) && 'true' === ( $pq['show_comments'] ?? '' ), 'which loads SoundCloud’s player for the same track, with its colour and settings, starting at once', $play );
em_t( false !== strpos( $out, 'Plays from SoundCloud' ) && false !== strpos( $out, 'A made-up track' ), 'it names the track and where it plays from' );
em_t( 1 === em_ours( $r1 ), 'its title and picture are fetched once' );
$out = em_render( "https://soundcloud.com/made-up-artist/sets/made-up-set\n" );
em_t( 1 === em_panels( $out ) && false !== strpos( $out, 'is-tall' ) && false !== strpos( $out, 'height:400px;' ), 'a SoundCloud page link on its own line becomes a panel at the visual player’s height', $out );
em_t( false !== strpos( $out, 'href="https://soundcloud.com/made-up-artist/sets/made-up-set"' ), 'whose link opens the page on SoundCloud' );
$out = em_render( '<iframe src="https://w.soundcloud.com/player/?url=https%3A//api.soundcloud.com/playlists/77&amp;visual=true"></iframe>' );
em_t( 1 === em_panels( $out ) && false !== strpos( $out, 'height:450px;' ), 'a playlist with no height of its own gets SoundCloud’s 450 px' );

/* Both on: a block widget. */
update_option( 'rrt_embeds', array( 'yt' => true, 'sc' => true ) );
$out = apply_filters( 'widget_block_content', $em_block, array(), null );
em_t( 1 === em_panels( $out ), 'a block widget with a player gets a panel too' );
$out = em_render( $em_block . $em_iframe . $em_sc . $em_vimeo );
em_t( 3 === em_panels( $out ) && 1 === substr_count( $out, '<iframe' ), 'a page with several players gets a panel for each of them and leaves the others' );

/* Places that always get the original. */
global $wp_query;
$wp_query->is_feed = true;
$out = em_render( $em_iframe );
$wp_query->is_feed = false;
em_t( 0 === em_panels( $out ) && false !== strpos( $out, 'youtube.com/embed/PastedVid01' ), 'feeds get the original player' );
set_current_screen( 'edit-post' );
$out = em_render( $em_iframe );
$GLOBALS['current_screen'] = null;
em_t( 0 === em_panels( $out ), 'the admin gets the original player' );
$_GET['teleprompter'] = '1';
$out = em_render( $em_iframe );
unset( $_GET['teleprompter'] );
em_t( 0 === em_panels( $out ), 'the studio gets the original' );
em_t( false !== wp_style_is( 'rrt-player', 'enqueued' ) && false !== wp_script_is( 'rrt-embeds', 'enqueued' ), 'a page with a panel loads the player’s styles and embeds.js' );
$inline = implode( '', (array) wp_styles()->get_data( 'rrt-player', 'after' ) );
em_t( false !== strpos( $inline, '.rrt-embed{' ) || false !== strpos( $inline, ',.rrt-embed{' ), 'with the player’s colours' );

/* The privacy paragraph. */
em_t( false !== strpos( rrt_listen_privacy_text(), 'YouTube videos and SoundCloud tracks on this site are shown as a picture' ) && rrt_listen_privacy_needed(), 'while a switch is on, the privacy paragraph explains it' );
update_option( 'rrt_embeds', array( 'yt' => true, 'sc' => false ) );
em_t( false !== strpos( rrt_embeds_privacy_text(), 'does not contact YouTube when' ) && false === strpos( rrt_embeds_privacy_text(), 'SoundCloud' ), 'and names only the service switched on' );

/* The settings section. */
ob_start();
rrt_embeds_settings_section();
$sec = ob_get_clean();
em_t( false !== strpos( $sec, 'id="rrt-embeds-yt" checked' ) && false !== strpos( $sec, 'id="rrt-embeds-sc">' ) && false !== strpos( $sec, 'Clear saved pictures' ), 'Settings, Embedded players shows each switch and Clear saved pictures', $sec );
em_t( (bool) preg_match( '/Saved pictures: \d+\./', $sec ), 'and how many pictures are saved' );

/* Prefetch when a post is saved. */
rrt_embeds_clear();
$p2 = wp_insert_post( array( 'post_title' => 'Embeds prefetch (draft)', 'post_status' => 'draft', 'post_content' => "https://www.youtube.com/watch?v=Prefetch001\n" ) );
em_t( false !== wp_next_scheduled( 'rrt_embeds_prefetch', array( (int) $p2 ) ), 'saving a post with a player schedules its look-up' );
wp_clear_scheduled_hook( 'rrt_embeds_prefetch', array( (int) $p2 ) );
$r1 = count( $GLOBALS['em_reqs'] );
em_t( 1 === rrt_embeds_prefetch( $p2 ) && 1 === em_ours( $r1 ) && 1 === count( rrt_embeds_list() ), 'which fetches and keeps its picture before anyone visits' );
$p3 = wp_insert_post( array( 'post_title' => 'No players (draft)', 'post_status' => 'draft', 'post_content' => 'Words only.' ) );
em_t( false === wp_next_scheduled( 'rrt_embeds_prefetch', array( (int) $p3 ) ), 'a post with no players schedules nothing' );

/* Clean up: 90 days, stray files, Clear saved pictures. */
$list = rrt_embeds_list();
$k    = array_keys( $list )[0];
$file = rrt_embeds_dir() . $list[ $k ]['f'];
$list[ $k ]['seen'] = time() - 91 * DAY_IN_SECONDS;
rrt_embeds_list_save( $list );
$stray = rrt_embeds_dir() . 'yt-stray-abcdef.jpg';
file_put_contents( $stray, 'x' );
touch( $stray, time() - 2 * HOUR_IN_SECONDS );
$fresh = rrt_embeds_dir() . 'yt-fresh-abcdef.jpg';
file_put_contents( $fresh, 'x' );
do_action( 'rrt_share_hourly' );
clearstatcache();
em_t( ! isset( rrt_embeds_list()[ $k ] ) && ! file_exists( $file ), 'a picture not shown for 90 days is removed by the hourly job' );
em_t( ! file_exists( $stray ) && file_exists( $fresh ), 'and a stray file is removed once it is an hour old' );
em_render( $em_iframe );
em_t( count( glob( rrt_embeds_dir() . '*.{jpg,png,webp}', GLOB_BRACE ) ) >= 2, 'pictures come back when next needed' );
$n = rrt_embeds_clear();
clearstatcache();
em_t( $n >= 2 && ! glob( rrt_embeds_dir() . '*.{jpg,png,webp}', GLOB_BRACE ) && ! get_option( 'rrt_embeds_list' ), 'Clear saved pictures removes every picture and the list' );
em_t( '' === rrt_embeds_clean_key( '../x' ) && '' === rrt_embeds_clean_key( 'yt-AB' ) && 'yt-ab' === rrt_embeds_clean_key( 'yt-ab' ), 'list keys are only safe names' );

/* Parsing. */
$yt = rrt_embeds_yt_parse( 'https://m.youtube.com/watch?v=AbCdEfGhIj1&t=1h2m3s' );
em_t( $yt && 'AbCdEfGhIj1' === $yt['id'] && 3723 === $yt['start'], 'YouTube times in hours, minutes and seconds' );
em_t( null === rrt_embeds_yt_parse( 'https://www.youtube.com/channel/UCxyz' ) && null === rrt_embeds_yt_parse( 'https://youtube.com.evil.example/watch?v=AbCdEfGhIj1' ), 'channel pages and look-alike hosts are not videos' );
em_t( null === rrt_embeds_sc_parse( 'https://w.soundcloud.com/player/?url=https%3A//evil.example/tracks/1' ), 'a SoundCloud player pointed elsewhere is not a track' );

/* REST last, since it cannot be undone: the API gets the original. */
update_option( 'rrt_embeds', array( 'yt' => true, 'sc' => true ) );
define( 'REST_REQUEST', true );
$out = em_render( $em_iframe . $em_sc );
em_t( 0 === em_panels( $out ) && 2 === substr_count( $out, '<iframe' ), 'the REST API, and so the block editor, gets the original players' );

/* Leave nothing behind. */
rrt_embeds_clear();
delete_option( 'rrt_embeds' );
foreach ( array( $em_post, $p2, $p3 ) as $id ) {
	wp_delete_post( $id, true );
}
wp_clear_scheduled_hook( 'rrt_embeds_prefetch' );
wp_delete_file( rrt_embeds_dir() . 'index.php' );
@rmdir( rtrim( rrt_embeds_dir(), '/' ) );

echo "\n" . $GLOBALS['em_pass'] . ' passed, ' . count( $GLOBALS['em_fail'] ) . " failed\n";
if ( $GLOBALS['em_fail'] ) {
	exit( 1 );
}
