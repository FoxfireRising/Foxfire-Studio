<?php
/**
 * Accessibility (1.26.0), run inside the test WordPress:
 *   php wp-cli.phar --path=<wordpress> eval-file tests/php/a11y.php
 * The contrast maths, the Readability check and Make it readable, the
 * player's HTML (button names, the Transcript button) and the Reading audio
 * box. Prints one line per check and exits 1 if any fail. Leaves nothing behind.
 */

$GLOBALS['ay_pass'] = 0;
$GLOBALS['ay_fail'] = array();

function ay_t( $ok, $name, $detail = '' ) {
	if ( $ok ) {
		++$GLOBALS['ay_pass'];
		echo "ok   $name\n";
	} else {
		$GLOBALS['ay_fail'][] = $name;
		echo "FAIL $name" . ( '' !== (string) $detail ? ': ' . $detail : '' ) . "\n";
	}
}

wp_set_current_user( 1 );
$ay_was = get_option( 'rrt_settings', null );
$GLOBALS['ay_posts'] = array();
register_shutdown_function(
	function () use ( $ay_was ) {
		foreach ( $GLOBALS['ay_posts'] as $p ) {
			foreach ( get_children( array( 'post_parent' => $p, 'post_type' => 'attachment', 'numberposts' => -1, 'post_status' => 'any' ) ) as $a ) {
				wp_delete_attachment( $a->ID, true );
			}
			wp_delete_post( $p, true );
		}
		if ( null === $ay_was ) {
			delete_option( 'rrt_settings' );
		} else {
			update_option( 'rrt_settings', $ay_was );
		}
		echo $GLOBALS['ay_pass'] . ' passed, ' . count( $GLOBALS['ay_fail'] ) . " failed\n";
		if ( $GLOBALS['ay_fail'] ) {
			exit( 1 );
		}
	}
);

/* ---- The contrast maths ---- */
ay_t( abs( rrt_contrast( '#000000', '#ffffff' ) - 21 ) < 0.01, 'black on white is 21 to 1' );
ay_t( abs( rrt_contrast( '#767676', '#ffffff' ) - 4.54 ) < 0.01, '#767676 on white is 4.54 to 1 (the lightest grey that passes)' );
ay_t( abs( rrt_contrast( '#777777', '#ffffff' ) - 4.48 ) < 0.01, '#777777 on white is 4.48 to 1 (just short)' );

/* ---- Both presets read easily as shipped ---- */
foreach ( array_keys( rrt_presets() ) as $ay_pre ) {
	$ay_c   = rrt_defaults_for( $ay_pre )['colors'];
	$ay_bad = array();
	foreach ( rrt_a11y_check( $ay_c ) as $ay_row ) {
		if ( ! $ay_row['ok'] ) {
			$ay_bad[] = $ay_row['fg'] . ' on ' . $ay_row['bg'] . ' ' . $ay_row['ratio'];
		}
	}
	ay_t( ! $ay_bad, 'every pair in the ' . $ay_pre . ' preset passes', implode( ', ', $ay_bad ) );
	ay_t( rrt_a11y_fix( $ay_c ) === $ay_c, 'Make it readable changes nothing in the ' . $ay_pre . ' preset' );
}

/* ---- A light set with pale words ---- */
$ay_light = array(
	'bg'      => '#fbf8f2',
	'surface' => '#f6f2ea',
	'panel'   => '#f4f1ea',
	'text'    => '#2a2630',
	'muted'   => '#b9b2a6',
	'faint'   => '#cfc9bd',
	'accent'  => '#e0b04f',
	'accent2' => '#7a4a8c',
);
$ay_rows = rrt_a11y_check( $ay_light );
$ay_fail = array();
foreach ( $ay_rows as $ay_row ) {
	if ( ! $ay_row['ok'] ) {
		$ay_fail[] = $ay_row['fg'] . '-' . $ay_row['bg'];
	}
}
ay_t( in_array( 'muted-panel', $ay_fail, true ) && in_array( 'faint-panel', $ay_fail, true ) && in_array( 'accent-panel', $ay_fail, true ), 'pale labels, hints and buttons on a light panel fail', implode( ', ', $ay_fail ) );
ay_t( ! in_array( 'text-panel', $ay_fail, true ), 'dark text on the light panel passes' );
ay_t( count( rrt_a11y_pairs() ) === count( $ay_rows ), 'every pair is checked' );
$ay_fixed = rrt_a11y_fix( $ay_light );
ay_t( rrt_a11y_all_ok( $ay_fixed ), 'Make it readable makes every pair pass' );
ay_t( $ay_fixed['bg'] === $ay_light['bg'] && $ay_fixed['panel'] === $ay_light['panel'] && $ay_fixed['surface'] === $ay_light['surface'], 'the backgrounds stay as they were' );
ay_t( $ay_fixed['text'] === $ay_light['text'], 'a colour that already passes stays as it was' );
ay_t( $ay_fixed['accent2'] === $ay_light['accent2'], 'the progress line\'s start colour is never touched' );
ay_t( rrt_luma( $ay_fixed['muted'] ) < rrt_luma( $ay_light['muted'] ), 'on a light panel a pale colour is darkened' );
/* The smallest step: one percent less would fail again. */
$ay_step = 0;
for ( $i = 1; $i <= 100; $i++ ) {
	if ( rrt_mix( $ay_light['muted'], '#000000', $i / 100 ) === $ay_fixed['muted'] ) {
		$ay_step = $i;
		break;
	}
}
$ay_less = $ay_light;
$ay_less['muted'] = rrt_mix( $ay_light['muted'], '#000000', ( $ay_step - 1 ) / 100 );
$ay_lr = array();
foreach ( rrt_a11y_check( $ay_less ) as $ay_row ) {
	if ( 'muted' === $ay_row['fg'] ) {
		$ay_lr[] = $ay_row['ok'];
	}
}
ay_t( $ay_step > 1 && in_array( false, $ay_lr, true ), 'Make it readable moves a colour by the smallest step that is enough', 'step ' . $ay_step );

/* A dark set with a dim accent: lightened, and the button text (the background on the accent) still passes. */
$ay_dark           = rrt_defaults_for( 'neutral' )['colors'];
$ay_dark['accent'] = '#6b5420';
$ay_df             = rrt_a11y_fix( $ay_dark );
ay_t( rrt_luma( $ay_df['accent'] ) > rrt_luma( $ay_dark['accent'] ) && rrt_a11y_all_ok( $ay_df ), 'on a dark page a dim accent is lightened until every pair passes' );

/* Through the settings form. */
$ay_in                       = rrt_settings();
$ay_in['colors']             = $ay_light;
$ay_saved                    = rrt_sanitize_settings( $ay_in );
ay_t( $ay_saved['colors']['muted'] === $ay_light['muted'], 'saving without Make it readable keeps the colours as typed' );
$ay_in['a11y_fix']           = '1';
$ay_saved                    = rrt_sanitize_settings( $ay_in );
ay_t( rrt_a11y_all_ok( $ay_saved['colors'] ), 'saving with Make it readable stores colours that all pass' );
ay_t( ! isset( $ay_saved['a11y_fix'] ), 'the button itself is never stored' );

ob_start();
rrt_a11y_settings_rows( $ay_light, 'rrt_settings' );
$ay_html = ob_get_clean();
ay_t( false !== strpos( $ay_html, 'data-rrt-readable="fail"' ) && false !== strpos( $ay_html, 'name="rrt_settings[a11y_fix]"' ), 'the Readability row flags the set and offers Make it readable' );
ob_start();
rrt_a11y_settings_rows( rrt_defaults_for( 'neutral' )['colors'], 'rrt_settings' );
$ay_html = ob_get_clean();
ay_t( false !== strpos( $ay_html, 'data-rrt-readable="pass"' ) && false === strpos( $ay_html, 'a11y_fix' ), 'with every pair passing there is no button' );

/* ---- The player's HTML ---- */
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
$ay_s                     = rrt_settings();
$ay_s['player']['show']   = 'everyone';
$ay_s['player']['follow'] = true;
update_option( 'rrt_settings', $ay_s );
$ay_post = wp_insert_post( array( 'post_title' => 'Player markup test', 'post_status' => 'publish', 'post_content' => 'Words.' ) );
$GLOBALS['ay_posts'][] = $ay_post;
$ay_att = function ( $name, $bytes ) use ( $ay_post ) {
	$tmp = wp_tempnam( $name );
	file_put_contents( $tmp, $bytes );
	return media_handle_sideload( array( 'name' => $name, 'tmp_name' => $tmp ), $ay_post );
};
$ay_mp3 = $ay_att( 'ay-reading.mp3', "ID3\x03\x00\x00\x00\x00\x00\x00" . str_repeat( "\xFF\xFB\x10\xC4" . str_repeat( "\x00", 100 ), 40 ) );
update_post_meta( $ay_post, 'rrt_audio', $ay_mp3 );

ay_t( rrt_a11y_needs_captions( $ay_post ), 'a reading without captions is noticed' );
$ay_html = rrt_player( $ay_post );
ay_t( false === strpos( $ay_html, 'rrt-transcript-btn' ), 'without captions there is no Transcript button' );
ay_t( 1 === preg_match( '#<button type="button" class="rrt-btn rrt-back">Back 15s</button>#', $ay_html ), 'the back button reads Back 15s, with no other name' );
ay_t( 1 === preg_match( '#<button type="button" class="rrt-btn rrt-fwd">Ahead 30s</button>#', $ay_html ), 'the ahead button reads Ahead 30s, with no other name' );
ay_t( 1 === preg_match( '#<button type="button" class="rrt-btn rrt-speed">Speed 1×</button>#u', $ay_html ), 'the speed button reads Speed 1×, with no other name' );
ay_t( false !== strpos( $ay_html, 'class="rrt-sr rrt-say" aria-live="polite"' ), 'the player has a quiet line for screen readers' );

$ay_vtt = $ay_att( 'ay-reading.vtt', "WEBVTT\n\n00:00:00.000 --> 00:00:01.000\nWords.\n" );
update_post_meta( $ay_post, 'rrt_captions', $ay_vtt );
ay_t( ! rrt_a11y_needs_captions( $ay_post ), 'with captions the reading no longer needs them' );
$ay_html = rrt_player( $ay_post );
ay_t( 1 === preg_match( '#class="rrt-btn rrt-transcript-btn" aria-expanded="false" aria-controls="rrt-transcript-' . $ay_post . '"#', $ay_html ), 'with captions the Transcript button is there, closed, naming its list' );
ay_t( false !== strpos( $ay_html, 'id="rrt-transcript-' . $ay_post . '"' ), 'the list it names is on the page' );

$ay_s['player']['skip_back'] = 10;
update_option( 'rrt_settings', $ay_s );
ay_t( false !== strpos( rrt_player( $ay_post ), '>Back 10s</button>' ), 'the back button follows the skip setting' );

/* ---- The Reading audio box ---- */
ob_start();
rrt_reading_box( get_post( $ay_post ) );
$ay_box = ob_get_clean();
ay_t( false !== strpos( $ay_box, 'aria-label="Choose the reading (MP3)"' ) && false !== strpos( $ay_box, 'aria-label="Choose the captions (.vtt)"' ), 'the box\'s Choose buttons say which file they are for' );
ay_t( false !== strpos( $ay_box, 'aria-label="Remove the reading"' ) && false !== strpos( $ay_box, 'aria-label="Remove the captions"' ), 'so do its Remove buttons' );
ay_t( false === strpos( $ay_box, '<label for="rrt_audio"' ), 'no label points at a hidden field' );

wp_delete_post( $ay_post, true );
ay_t( ! rrt_a11y_needs_captions( $ay_post ), 'a post that is gone needs nothing' );

/* ---- The studio (1.27.0): words ahead of the reading line, held at 3 to 1 ---- */
$ay_dark = array( 'bg' => '#0d0b10', 'text' => '#f2ece0' );
ay_t( 42 === rrt_a11y_ahead_pct( $ay_dark ), 'on a dark page words ahead keep the usual 42 percent', rrt_a11y_ahead_pct( $ay_dark ) );
$ay_pale = array( 'bg' => '#ffffff', 'text' => '#555555' );
$ay_pct  = rrt_a11y_ahead_pct( $ay_pale );
ay_t( $ay_pct > 42, 'on a light page with grey text words ahead are drawn stronger', $ay_pct );
ay_t( rrt_contrast( rrt_a11y_ahead( $ay_pale ), '#ffffff' ) >= RRT_A11Y_AHEAD, 'and reach 3 to 1', rrt_contrast( rrt_a11y_ahead( $ay_pale ), '#ffffff' ) );
ay_t( rrt_contrast( rrt_mix( '#ffffff', '#555555', ( $ay_pct - 1 ) / 100 ), '#ffffff' ) < RRT_A11Y_AHEAD, 'by the smallest step that is enough' );
$ay_ahead_row = null;
foreach ( rrt_a11y_check( $ay_pale + array( 'panel' => '#ffffff', 'surface' => '#ffffff', 'muted' => '#555555', 'faint' => '#555555', 'accent' => '#1d4ed8' ) ) as $ay_row ) {
	if ( 'ahead' === $ay_row['fg'] ) {
		$ay_ahead_row = $ay_row;
	}
}
ay_t( $ay_ahead_row && $ay_ahead_row['ok'] && 3.0 === $ay_ahead_row['need'], 'the Readability check lists words ahead, needing 3 to 1, and they pass' );
$ay_cfg = rrt_config();
$ay_cfg['colors'] = $ay_pale + $ay_cfg['colors'];
ay_t( false !== strpos( rrt_tokens_css( $ay_cfg ), '--ahead:color-mix(in srgb, var(--text) ' . $ay_pct . '%, transparent)' ), 'the studio\'s tokens use the same strength' );
