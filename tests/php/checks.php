<?php
/**
 * PHP checks for Foxfire Studio, run inside the test WordPress:
 *   php wp-cli.phar --path=<wordpress> eval-file tests/php/checks.php
 * Prints one line per check and exits 1 if any fail.
 */

$GLOBALS['rrt_pass'] = 0;
$GLOBALS['rrt_fail'] = array();

function rrt_t( $ok, $name ) {
	if ( $ok ) {
		++$GLOBALS['rrt_pass'];
		echo "ok   $name\n";
	} else {
		$GLOBALS['rrt_fail'][] = $name;
		echo "FAIL $name\n";
	}
}

/* Version numbers agree. */
$rrt_head = get_file_data( WP_PLUGIN_DIR . '/foxfire-studio/foxfire-studio.php', array( 'v' => 'Version' ) );
rrt_t( defined( 'RRT_VERSION' ) && RRT_VERSION === $rrt_head['v'], 'RRT_VERSION matches the plugin header (' . $rrt_head['v'] . ')' );
$rrt_readme = file_get_contents( WP_PLUGIN_DIR . '/foxfire-studio/readme.txt' );
rrt_t( (bool) preg_match( '/Stable tag:\s*' . preg_quote( $rrt_head['v'], '/' ) . '/', $rrt_readme ), 'readme.txt stable tag matches' );

/* Listener addresses: proxies only trusted when configured. */
$rrt_r = rrt_proxy_ranges( array( '10.0.0.0/8' ) );
$rrt_c = rrt_client_ip_from( array( 'REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4' ), $rrt_r, 'HTTP_X_FORWARDED_FOR' );
rrt_t( '203.0.113.9' === $rrt_c['ip'], 'a forwarded address from an untrusted sender is ignored' );
$rrt_c = rrt_client_ip_from( array( 'REMOTE_ADDR' => '10.1.1.1', 'HTTP_X_FORWARDED_FOR' => '9.9.9.9, 198.51.100.7, 10.2.2.2' ), $rrt_r, 'HTTP_X_FORWARDED_FOR' );
rrt_t( '198.51.100.7' === $rrt_c['ip'], 'X-Forwarded-For is read from the right, skipping trusted proxies' );
$rrt_c = rrt_client_ip_from( array( 'REMOTE_ADDR' => '10.1.1.1', 'HTTP_X_FORWARDED_FOR' => 'nonsense' ), $rrt_r, 'HTTP_X_FORWARDED_FOR' );
rrt_t( '10.1.1.1' === $rrt_c['ip'], 'a malformed forwarded address falls back to the sender' );

/* CSV downloads cannot carry spreadsheet formulas. */
$rrt_row = rrt_csv_safe( array( '=SUM(A1)', '@x', '-5', '-x', 'plain', 12 ) );
rrt_t( array( "'=SUM(A1)", "'@x", '-5', "'-x", 'plain', 12 ) === $rrt_row, 'CSV cells that start a formula are defused' );

/* Cue marks are cleaned before they are saved. */
$rrt_cues = rrt_clean_cues(
	array(
		array( 'i' => 3, 'k' => 'Moon!', 'a' => 'pause' ),
		array( 'i' => 3, 'k' => 'again', 'a' => 'breath' ),
		array( 'i' => -1, 'k' => 'x', 'a' => 'pause' ),
		array( 'i' => 5, 'k' => 'y', 'a' => 'shout' ),
		'junk',
	)
);
rrt_t( 1 === count( $rrt_cues ) && 'moon' === $rrt_cues[0]['k'], 'cue marks keep one valid mark per word, cleaned' );

/* Pronunciation notes (1.1.0): a note alone keeps a cue, and notes are cleaned before they are saved. */
$rrt_cn = rrt_clean_cues( array( array( 'i' => 2, 'k' => 'tear', 'a' => '', 'n' => ' <b>TAIR</b> ' ), array( 'i' => 4, 'k' => 'x', 'n' => '' ) ) );
rrt_t( 1 === count( $rrt_cn ) && 'TAIR' === $rrt_cn[0]['n'] && '' === $rrt_cn[0]['a'], 'a cue mark with only a note is kept, its note as plain text' );
$rrt_cl = rrt_clean_cues( array( array( 'i' => 1, 'k' => 'moon', 'a' => 'pause' ) ) );
rrt_t( '' === $rrt_cl[0]['n'], 'a cue mark saved before 1.1.0 comes back with an empty note' );
$rrt_notes = rrt_clean_notes(
	array(
		array( 'k' => 'Samhain', 'n' => 'SOW-in' ),
		array( 'k' => 'samhain', 'n' => 'second' ),
		array( 'k' => 'Brigid’s', 'n' => '<script>x</script>BRIDGE-id' ),
		array( 'k' => 'cerridwen', 'n' => str_repeat( 'k', 80 ) ),
		array( 'k' => '!!', 'n' => 'nothing' ),
		array( 'k' => 'empty', 'n' => '   ' ),
		'junk',
	)
);
rrt_t( 3 === count( $rrt_notes ), 'notes keep one valid note per word' );
rrt_t( 'brigids' === $rrt_notes[0]['k'] && 'BRIDGE-id' === $rrt_notes[0]['n'], 'a note is plain text, its word cleaned as the teleprompter cleans words' );
rrt_t( 'samhain' === $rrt_notes[2]['k'] && 'SOW-in' === $rrt_notes[2]['n'], 'the first note for a word wins' );
rrt_t( RRT_NOTE_LEN === mb_strlen( $rrt_notes[1]['n'] ), 'a long note is cut to the longest a note can be' );
global $wpdb;
$rrt_np = (int) $wpdb->get_var( "SELECT ID FROM $wpdb->posts WHERE post_type = 'post' AND post_status = 'publish' ORDER BY ID LIMIT 1" );
$rrt_was_post = get_post_meta( $rrt_np, '_rrt_notes', true );
$rrt_was_mine = get_user_meta( 1, 'rrt_notes', true );
wp_set_current_user( 1 );
rrt_save_notes( 'post', $rrt_np, array( array( 'k' => 'samhain', 'n' => 'SOW-in' ) ) );
rrt_save_notes( 'all', $rrt_np, array( array( 'k' => 'imbolc', 'n' => 'IM-bulk' ) ) );
$rrt_boot = rrt_notes_boot( $rrt_np );
rrt_t( 'SOW-in' === $rrt_boot['post'][0]['n'] && 'IM-bulk' === $rrt_boot['mine'][0]['n'] && 40 === $rrt_boot['max'], "the teleprompter gets the post's notes and the reader's own" );
$rrt_rq = new WP_REST_Request( 'POST', '/rrt/v1/notes' );
$rrt_rq->set_body_params( array( 'post' => $rrt_np, 'scope' => 'post', 'notes' => array() ) );
$rrt_rs = rest_do_request( $rrt_rq );
rrt_t( 200 === $rrt_rs->get_status() && '' === get_post_meta( $rrt_np, '_rrt_notes', true ), 'saving an empty list removes the post field' );
$rrt_rq = new WP_REST_Request( 'POST', '/rrt/v1/notes' );
$rrt_rq->set_body_params( array( 'post' => $rrt_np, 'scope' => 'all', 'notes' => 'nope' ) );
rrt_t( 400 === rest_do_request( $rrt_rq )->get_status(), 'notes that are not a list are refused' );
wp_set_current_user( 0 );
$rrt_rq = new WP_REST_Request( 'POST', '/rrt/v1/notes' );
$rrt_rq->set_body_params( array( 'post' => $rrt_np, 'scope' => 'post', 'notes' => array( array( 'k' => 'a', 'n' => 'b' ) ) ) );
rrt_t( in_array( rest_do_request( $rrt_rq )->get_status(), array( 401, 403 ), true ), 'visitors cannot save notes' );
$rrt_ex   = apply_filters( 'wp_privacy_personal_data_exporters', array() );
$rrt_exp  = call_user_func( $rrt_ex['rrt-reader-stats']['callback'], get_userdata( 1 )->user_email );
$rrt_grps = wp_list_pluck( $rrt_exp['data'], 'group_id' );
rrt_t( in_array( 'rrt-rrt_notes', $rrt_grps, true ), "the personal data export includes a reader's own pronunciation notes" );
'' === $rrt_was_post ? delete_post_meta( $rrt_np, '_rrt_notes' ) : update_post_meta( $rrt_np, '_rrt_notes', $rrt_was_post );
'' === $rrt_was_mine ? delete_user_meta( 1, 'rrt_notes' ) : update_user_meta( 1, 'rrt_notes', $rrt_was_mine );

/* The music bed setting only keeps audio attachments. */
$rrt_m = rrt_sanitize_music( array( 'beds' => array( array( 'id' => 999999, 'credit' => '<b>x</b>' ) ), 'depth' => 99 ) );
rrt_t( is_array( $rrt_m ) && empty( $rrt_m['beds'] ), 'music beds that are not audio in the Media Library are dropped' );

/* Insight rules stay within sensible limits. */
$rrt_rules = rrt_insight_rules();
rrt_t( is_array( $rrt_rules ) && ! empty( $rrt_rules ), 'insight rules load' );

/* The podcast feed is off until switched on. */
rrt_t( empty( rrt_podcast_settings()['on'] ), 'the podcast feed starts switched off' );

/* Listener analytics are off on a new install. */
$rrt_cfg = rrt_config();
rrt_t( empty( $rrt_cfg['listen']['on'] ), 'listener analytics start switched off on a new install' );

/* The Settings screen's "Off until you switch it on" advice shows only while measuring is off (1.0.0). */
require_once ABSPATH . 'wp-admin/includes/admin.php';
wp_set_current_user( 1 );
$rrt_keep = get_option( 'rrt_settings' );
$rrt_page = function ( $on ) {
	$s                 = rrt_settings();
	$s['listen']['on'] = $on;
	update_option( 'rrt_settings', $s );
	ob_start();
	rrt_settings_page();
	return ob_get_clean();
};
$rrt_off = $rrt_page( false );
$rrt_on  = $rrt_page( true );
false === $rrt_keep ? delete_option( 'rrt_settings' ) : update_option( 'rrt_settings', $rrt_keep );
wp_set_current_user( 0 );
rrt_t( false !== strpos( $rrt_off, 'Measure how visitors listen' ) && false !== strpos( $rrt_off, 'Off until you switch it on' ), 'with measuring off, Settings says how to switch it on' );
rrt_t( false !== strpos( $rrt_on, 'Measure how visitors listen' ) && false === strpos( $rrt_on, 'Off until you switch it on' ), 'with measuring on, Settings does not say it is off' );


/* Insights word their findings from synthetic readings. */
$rrt_rules = rrt_insight_rules();
$rrt_fair  = array();
for ( $rrt_i = 0; $rrt_i < 12; $rrt_i++ ) {
	$rrt_fair[] = array(
		'duration'  => 120 + 30 * $rrt_i,
		'tempo'     => 110 + 4 * $rrt_i,
		'rate'      => $rrt_i < 6 ? 0.8 : 0.4,
		'accuracy'  => 90 + $rrt_i * 0.5,
		'pitch'     => 2 + $rrt_i * 0.1,
		'ending'    => 3 + $rrt_i * 0.2,
		'terms'     => array(),
		'weekday'   => 1 + $rrt_i % 7,
		'hour'      => 8 + $rrt_i,
		'plays'     => 40,
	);
}
$rrt_len = rrt_insight_length( $rrt_fair, $rrt_rules );
rrt_t( 'found' === $rrt_len['state'] && false !== strpos( $rrt_len['text'], '80%' ) && false !== strpos( $rrt_len['basis'], '12 readings' ), 'the length insight finds the shorter readings held better' );
$rrt_tmp = rrt_insight_tempo( $rrt_fair, $rrt_rules );
rrt_t( '' !== $rrt_tmp['text'] && false !== strpos( $rrt_tmp['text'], 'words a minute' ), 'the tempo insight is worded' );
$rrt_wait = rrt_insight_length( array_slice( $rrt_fair, 0, 2 ), $rrt_rules );
rrt_t( 'waiting' === $rrt_wait['state'] && 0 === strpos( $rrt_wait['need'], 'Needs ' ) && false !== strpos( $rrt_wait['basis'], '2 readings' ), 'an insight without enough readings says what it needs' );
$rrt_del = rrt_insight_delivery( 'pitch', array_slice( $rrt_fair, 0, 2 ), $rrt_rules );
rrt_t( false !== strpos( $rrt_del['need'], 'recorded from a take with pitch measured' ), 'a delivery insight names what its readings need' );
/* A reader with no takes at all (user 1 may have some from the browser tests). */
$rrt_tk = rrt_insights_takes( 987654 );
rrt_t( count( $rrt_tk ) >= 4 && 'waiting' === $rrt_tk[0]['state'] && false !== strpos( $rrt_tk[0]['need'], 'more full take' ), 'take insights wait for takes' );

/* Readings to make (1.4.0): measuring a script, and which posts are listed. */
$rrt_bl = rrt_backlog_measure(
	array(
		array( 'type' => 'title', 'text' => 'The Wheel Of The Year' ),
		array( 'type' => 'p', 'text' => 'We sail to Inishmore and call on Siobhan’s kin. In November the nights grow long. I walk slowly.' ),
		array( 'type' => 'p', 'text' => '"Welcome," she said: Hello again. Unquestionably, the circle is cast.' ),
	),
	array( 'inishmore' => true )
);
rrt_t( array( 'Siobhan' ) === $rrt_bl['names'], 'names are capitals inside a sentence, not months, I, quotes, known words or after a colon (' . implode( ',', $rrt_bl['names'] ) . ')' );
rrt_t( array( 'Inishmore' ) === $rrt_bl['known'] && array( 'Unquestionably' ) === $rrt_bl['big'] && 0 === $rrt_bl['long'], 'known words and very long words are found' );
$rrt_long = rrt_backlog_measure( array( array( 'type' => 'p', 'text' => trim( str_repeat( 'and the sea ', 12 ) ) . '.' ) ), array() );
rrt_t( 1 === $rrt_long['long'] && 36 === $rrt_long['words'] && 'care' === $rrt_long['level'], 'a 36 word sentence is long, and How hard is per 100 words, a short post counting as 100 (' . $rrt_long['score'] . ')' );
rrt_t( 'easy' === rrt_backlog_measure( array( array( 'type' => 'p', 'text' => 'The sun is warm. We sit by the fire.' ) ), array() )['level'], 'a plain script is easy' );
$rrt_p1 = wp_insert_post( array( 'post_title' => 'Backlog check one', 'post_content' => '<p>A quiet walk by the river.</p>', 'post_status' => 'publish' ) );
$rrt_p2 = wp_insert_post( array( 'post_title' => 'Backlog check two', 'post_content' => '<p>Another walk.</p>', 'post_status' => 'publish' ) );
update_post_meta( $rrt_p2, 'rrt_audio', 'https://example.org/reading.mp3' );
$rrt_ids = function ( $hidden ) {
	$d = rrt_backlog_rows( 1, $hidden );
	return wp_list_pluck( $d['rows'], 'post' );
};
rrt_t( in_array( $rrt_p1, $rrt_ids( false ), true ) && ! in_array( $rrt_p2, $rrt_ids( false ), true ), 'posts with a reading are left off the list' );
update_option( RRT_BACKLOG_HIDDEN, array( $rrt_p1 ), false );
rrt_t( ! in_array( $rrt_p1, $rrt_ids( false ), true ) && in_array( $rrt_p1, $rrt_ids( true ), true ), 'a hidden post moves to the hidden list' );
wp_delete_post( $rrt_p1, true );
wp_delete_post( $rrt_p2, true );
rrt_t( false === get_option( RRT_BACKLOG_HIDDEN ), 'deleting a hidden post takes it off the hidden list' );
$rrt_pace = rrt_backlog_pace( 987654 );
rrt_t( 'setting' === $rrt_pace['from'] && $rrt_pace['wpm'] >= 60, 'with no takes the estimate uses the default pace' );

/* Finishing presets (1.6.0): older choices read as the preset they become, and saving keeps mode and target in step. */
$rrt_saved_before = get_option( 'rrt_settings', null );
$rrt_old          = function ( $mode, $target ) {
	$s          = is_array( get_option( 'rrt_settings', null ) ) ? get_option( 'rrt_settings' ) : array();
	$s['sound'] = array(
		'mode'     => $mode,
		'target'   => $target,
		'hp'       => true,
		'denoise'  => 'medium',
	);
	update_option( 'rrt_settings', $s );
	return rrt_settings()['sound'];
};
$rrt_s = $rrt_old( 'podcast', -19 );
rrt_t( 'podcast_mono' === $rrt_s['finish'] && 'podcast' === $rrt_s['mode'] && -19 == $rrt_s['target'], 'podcasts at -19 saved before 1.6.0 read as Podcast, mono' ); // phpcs:ignore Universal.Operators.StrictComparisons
$rrt_s = $rrt_old( 'podcast', -16 );
rrt_t( 'custom' === $rrt_s['finish'] && -16 == $rrt_s['custom_lufs'] && -1 == $rrt_s['custom_tp'] && 'mono' === $rrt_s['custom_ch'] && -16 == $rrt_s['target'], 'any other podcast loudness reads as Custom with the same number, mono, -1 dBTP' ); // phpcs:ignore Universal.Operators.StrictComparisons
$rrt_s = $rrt_old( 'podcast', -23 );
rrt_t( 'custom' === $rrt_s['finish'] && -23 == $rrt_s['custom_lufs'], 'a podcast loudness of -23 reads as Custom at -23' ); // phpcs:ignore Universal.Operators.StrictComparisons
$rrt_s = $rrt_old( 'audiobook', -19 );
rrt_t( 'acx' === $rrt_s['finish'] && 'audiobook' === $rrt_s['mode'], 'audiobooks saved before 1.6.0 read as Audiobook (ACX)' );
$rrt_s = $rrt_old( 'off', -19 );
rrt_t( 'off' === $rrt_s['finish'] && 'off' === $rrt_s['mode'], 'the simple leveling saved before 1.6.0 stays the simple leveling' );
rrt_t( true === $rrt_s['hp'] && 'medium' === $rrt_s['denoise'], 'the other sound settings are kept' );
$rrt_in = rrt_sanitize_settings( array( 'sound' => array( 'finish' => 'streaming', 'custom_lufs' => '-40', 'custom_tp' => '0', 'custom_ch' => 'surround' ) ) );
rrt_t( 'streaming' === $rrt_in['sound']['finish'] && 'podcast' === $rrt_in['sound']['mode'] && -14 == $rrt_in['sound']['target'], 'saving Spotify and YouTube sets the target to -14' ); // phpcs:ignore Universal.Operators.StrictComparisons
rrt_t( -24 == $rrt_in['sound']['custom_lufs'] && -0.5 == $rrt_in['sound']['custom_tp'] && 'mono' === $rrt_in['sound']['custom_ch'], 'Custom numbers out of range are brought into range' ); // phpcs:ignore Universal.Operators.StrictComparisons
$rrt_in = rrt_sanitize_settings( array( 'sound' => array( 'finish' => 'custom', 'custom_lufs' => '-17.5', 'custom_tp' => '-1.5', 'custom_ch' => 'stereo' ) ) );
$rrt_sp = rrt_finish_spec( $rrt_in['sound'] );
rrt_t( 'custom' === $rrt_sp['key'] && -17.5 == $rrt_sp['lufs'] && -1.5 == $rrt_sp['tp'] && 2 === $rrt_sp['ch'] && -17.5 == $rrt_in['sound']['target'], 'Custom keeps its loudness, peak limit and stereo' ); // phpcs:ignore Universal.Operators.StrictComparisons
$rrt_in = rrt_sanitize_settings( array( 'sound' => array( 'finish' => 'nonsense' ) ) );
rrt_t( isset( rrt_finish_presets()[ $rrt_in['sound']['finish'] ] ), 'an unknown preset is refused' );
$rrt_bc = rrt_finish_spec( array(), 'broadcast' );
rrt_t( 'wav' === $rrt_bc['format'] && 48000 === $rrt_bc['rate'] && 24 === $rrt_bc['bits'] && -23 === $rrt_bc['lufs'], 'Broadcast is a 48 kHz 24-bit WAV at -23 LUFS' );
$rrt_all = wp_list_pluck( rrt_finish_specs( array() ), 'key' );
rrt_t( array( 'podcast_mono', 'podcast_stereo', 'streaming', 'broadcast', 'acx', 'custom', 'off' ) === $rrt_all, 'every preset is offered, in order' );
$rrt_fresh = rrt_finish_settle( rrt_base_defaults()['sound'], array() );
rrt_t( 'podcast_mono' === $rrt_fresh['finish'], 'a new site finishes as Podcast, mono' );
if ( null === $rrt_saved_before ) {
	delete_option( 'rrt_settings' );
} else {
	update_option( 'rrt_settings', $rrt_saved_before );
}

/* Use this recording (1.6.0) takes a WAV as well as an MP3, checked by its content. */
$rrt_up_post = wp_insert_post( array( 'post_title' => 'Upload check', 'post_status' => 'publish' ) );
$rrt_wav     = wp_tempnam( 'rrt-wav' );
file_put_contents( $rrt_wav, 'RIFF' . pack( 'V', 36 ) . 'WAVEfmt ' . pack( 'VvvVVvv', 16, 1, 1, 48000, 144000, 3, 24 ) . 'data' . pack( 'V', 0 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
$rrt_bad = wp_tempnam( 'rrt-bad' );
file_put_contents( $rrt_bad, 'hello, not audio' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
$rrt_req = new WP_REST_Request( 'POST', '/rrt/v1/recording' );
$rrt_req->set_param( 'post', $rrt_up_post );
$rrt_req->set_file_params( array( 'mp3' => array( 'name' => 'x.mp3', 'tmp_name' => $rrt_bad, 'error' => 0, 'size' => 16, 'type' => 'audio/mpeg' ) ) );
$rrt_res = rrt_rest_upload_recording( $rrt_req );
rrt_t( is_wp_error( $rrt_res ) && false !== strpos( $rrt_res->get_error_message(), 'not an MP3 or a WAV' ), 'a file that is neither an MP3 nor a WAV is refused' );
$rrt_req->set_file_params( array( 'mp3' => array( 'name' => 'x.wav', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0, 'type' => '' ) ) );
$rrt_res = rrt_rest_upload_recording( $rrt_req );
rrt_t( is_wp_error( $rrt_res ) && false !== strpos( $rrt_res->get_error_message(), 'larger than this site accepts' ), 'a recording too large for the server says so, with the limit' );
@unlink( $rrt_bad ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
@unlink( $rrt_wav ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
wp_delete_post( $rrt_up_post, true );

/*
 * Clip suggestions (1.7.0). A made-up reading: words 0.4 s apart, 0.5 s
 * between sentences and 1 s between paragraphs. Each sentence is given as
 * its word count, or as array( count, first word ).
 */
$rrt_sg_make = function ( $title, $paras, $long_cue = 0 ) {
	$cues   = array();
	$blocks = array( array( 'type' => 'title', 'text' => $title ) );
	$t      = 0.3;
	$tw     = explode( ' ', $title );
	$cues[] = array( 's' => $t, 'e' => $t + 0.4 * count( $tw ), 't' => $title );
	$t     += 0.4 * count( $tw ) + 1;
	$n      = 0;
	foreach ( $paras as $pi => $para ) {
		$kind = 'p';
		if ( is_string( $para ) ) {
			$blocks[] = array( 'type' => 'h', 'text' => $para );
			$hw       = count( explode( ' ', $para ) );
			$cues[]   = array( 's' => $t, 'e' => $t + 0.4 * $hw, 't' => $para );
			$t       += 0.4 * $hw + 1;
			continue;
		}
		$ptext = array();
		foreach ( $para as $sent ) {
			$count = is_array( $sent ) ? $sent[0] : $sent;
			$first = is_array( $sent ) ? $sent[1] : 'W' . ( ++$n );
			$gap   = is_array( $sent ) && isset( $sent[2] ) ? $sent[2] : 0;
			$t    += $gap;
			$words = array( $first );
			for ( $k = 1; $k < $count; $k++ ) {
				$words[] = 'w' . ( ++$n );
			}
			$words[ $count - 1 ] .= is_array( $sent ) && isset( $sent[3] ) ? $sent[3] : '.';
			$ptext[] = implode( ' ', $words );
			/* Captions split a long sentence into parts of up to 12 words, as the review does. */
			$parts = $long_cue ? array_chunk( $words, 12 ) : array( $words );
			foreach ( $parts as $pw ) {
				$cues[] = array( 's' => round( $t, 2 ), 'e' => round( $t + 0.4 * count( $pw ) - 0.1, 2 ), 't' => implode( ' ', $pw ) );
				$t     += 0.4 * count( $pw );
			}
			$t += 0.5;
		}
		$blocks[] = array( 'type' => $kind, 'text' => implode( ' ', $ptext ) );
		$t       += 0.5;
	}
	return array( $cues, $blocks );
};
$rrt_sg_ok = function ( $sugg, $sents ) {
	$starts = wp_list_pluck( $sents, 's' );
	$ends   = wp_list_pluck( $sents, 'e' );
	foreach ( $sugg as $sg ) {
		if ( $sg['d'] < 30 || $sg['d'] > 60 || ! in_array( $sg['s'], array_map( function ( $x ) { return round( $x, 2 ); }, $starts ), true ) || ! in_array( $sg['e'], array_map( function ( $x ) { return round( $x, 2 ); }, $ends ), true ) ) {
			return false;
		}
	}
	return true;
};
$rrt_sg_overlap = function ( $sugg ) {
	$worst = 0;
	foreach ( $sugg as $a => $x ) {
		foreach ( $sugg as $b => $y ) {
			if ( $a < $b ) {
				$worst = max( $worst, ( min( $x['e'], $y['e'] ) - max( $x['s'], $y['s'] ) ) / min( $x['d'], $y['d'] ) );
			}
		}
	}
	return $worst;
};

/* A long sentence split over caption cues is one sentence; paragraph edges come from the post's blocks. */
list( $rrt_cq, $rrt_bk ) = $rrt_sg_make( 'Under The Moon', array( array( 20, 8 ), array( 6, 9 ) ), 1 );
$rrt_ss = rrt_suggest_sentences( $rrt_cq, $rrt_bk );
rrt_t( 6 === count( $rrt_cq ) && 5 === count( $rrt_ss ), 'caption cues of one sentence (a long one in two parts) make the sentences', count( $rrt_ss ) . ' sentences' );
rrt_t( 'title' === $rrt_ss[0]['kind'] && $rrt_ss[0]['ps'] && $rrt_ss[0]['pe'], 'the title is a sentence and a block of its own' );
rrt_t( $rrt_ss[1]['ps'] && ! $rrt_ss[1]['pe'] && ! $rrt_ss[2]['ps'] && $rrt_ss[2]['pe'] && $rrt_ss[3]['ps'], 'sentences know where paragraphs start and end' );
rrt_t( 20 === count( explode( ' ', $rrt_ss[1]['t'] ) ), 'the two parts of the long sentence are joined', $rrt_ss[1]['t'] );

/* A reading of about three minutes: two paragraphs of ten 10-word sentences, a heading, and two more paragraphs. */
$rrt_body                = array( array_fill( 0, 10, 10 ), array_fill( 0, 10, 10 ), 'A New Season', array_fill( 0, 10, 10 ), array_fill( 0, 10, 10 ) );
list( $rrt_cq, $rrt_bk ) = $rrt_sg_make( 'Hex the Oligarchy', $rrt_body );
$rrt_ss                  = rrt_suggest_sentences( $rrt_cq, $rrt_bk );
$rrt_sg                  = rrt_suggest_clips( $rrt_ss );
rrt_t( 4 === count( $rrt_sg ) && $rrt_sg[0]['opening'] && abs( $rrt_sg[0]['s'] - $rrt_ss[0]['s'] ) < 0.01, 'the opening first, then three more', count( $rrt_sg ) . ' suggestions' );
rrt_t( $rrt_sg_ok( $rrt_sg, $rrt_ss ), 'every suggestion runs 30 to 60 s from a sentence start to a sentence end' );
rrt_t( $rrt_sg_overlap( $rrt_sg ) <= 0.25 + 1e-6, 'suggestions overlap by at most a quarter', round( $rrt_sg_overlap( $rrt_sg ), 2 ) );
$rrt_starts = array();
foreach ( array_slice( $rrt_sg, 1 ) as $rrt_x ) {
	foreach ( $rrt_ss as $rrt_y ) {
		if ( abs( $rrt_y['s'] - $rrt_x['s'] ) < 0.01 ) {
			$rrt_starts[] = $rrt_y['ps'];
		}
	}
}
rrt_t( ! in_array( false, $rrt_starts, true ), 'with paragraphs to choose from, suggestions start at paragraph starts' );
$rrt_sec = array_filter( $rrt_sg, function ( $x ) { return in_array( 'Starts a section', $x['reasons'], true ); } );
rrt_t( 1 === count( $rrt_sec ), 'the stretch from the heading is suggested, as the start of a section' );
$rrt_head_end = false;
foreach ( $rrt_sg as $rrt_x ) {
	foreach ( $rrt_ss as $rrt_y ) {
		if ( abs( $rrt_y['e'] - $rrt_x['e'] ) < 0.01 && 'p' !== $rrt_y['kind'] ) {
			$rrt_head_end = true;
		}
	}
}
rrt_t( ! $rrt_head_end, 'no suggestion ends on a title or heading' );
rrt_t( $rrt_sg[0]['len'] > $rrt_sg[0]['d'] && $rrt_sg[0]['start'] <= $rrt_sg[0]['s'] && $rrt_sg[0]['start'] >= $rrt_sg[0]['s'] - 0.16 && null !== $rrt_sg[0]['next'], 'the clip maker gets a breath before the first word, the last word\'s tail and the sentence after' );
rrt_t( 0 === strpos( $rrt_sg[0]['first'], 'Hex the Oligarchy' ) && '…' === mb_substr( $rrt_sg[0]['last'], 0, 1 ), 'each suggestion has its first and last words' );

/* Starting on a word that points back, and a long pause, count against a stretch. */
$rrt_and                 = array( array( 10, 10, 10 ), array( array( 10, 'And' ), 10, 10, 10, 10 ), array( array( 10, 'But' ), 10, 10, 10, 10 ), array( 10, 10, 10, 10, 10 ) );
list( $rrt_cq, $rrt_bk ) = $rrt_sg_make( 'Embracing Change', $rrt_and );
$rrt_sg                  = rrt_suggest_clips( rrt_suggest_sentences( $rrt_cq, $rrt_bk ) );
$rrt_bad                 = array_filter( $rrt_sg, function ( $x ) { return preg_match( '/^(And|But) /', $x['first'] ); } );
rrt_t( ! $rrt_bad, 'a paragraph opening on "And" or "But" is passed over for a clean start', implode( ' | ', wp_list_pluck( $rrt_sg, 'first' ) ) );
$rrt_all = rrt_suggest_candidates( rrt_suggest_sentences( $rrt_cq, $rrt_bk ) );
$rrt_but = array_values( array_filter( $rrt_all, function ( $x ) { return $x['cautions'] && false !== strpos( $x['cautions'][0], '“But”' ); } ) );
rrt_t( (bool) $rrt_but, 'a stretch starting on "But" says the start points back' );
$rrt_ten                 = array_fill( 0, 8, 10 );
$rrt_gap                 = array( $rrt_ten, array( 10, 10, array( 10, 'Wa', 6 ), 10, 10, 10, 10, 10 ), $rrt_ten, $rrt_ten, $rrt_ten );
list( $rrt_cq, $rrt_bk ) = $rrt_sg_make( 'A Pause', $rrt_gap );
$rrt_ss                  = rrt_suggest_sentences( $rrt_cq, $rrt_bk );
$rrt_g                   = array_filter( rrt_suggest_candidates( $rrt_ss ), function ( $x ) { return in_array( 'Has a long pause', $x['cautions'], true ); } );
$rrt_top                 = rrt_suggest_clips( $rrt_ss );
rrt_t( $rrt_g && ! array_filter( $rrt_top, function ( $x ) { return in_array( 'Has a long pause', $x['cautions'], true ); } ), 'a stretch with a 6 s pause in it is marked and not among the suggestions', implode( ' | ', wp_list_pluck( $rrt_top, 's' ) ) );

/* The listening curve: with 10 or more plays, the stretch listeners replay rises to the top; with fewer it counts for nothing. */
list( $rrt_cq, $rrt_bk ) = $rrt_sg_make( 'One Long Paragraph', array( array_fill( 0, 40, 10 ) ) );
$rrt_ss                  = rrt_suggest_sentences( $rrt_cq, $rrt_bk );
$rrt_len                 = (int) ceil( end( $rrt_ss )['e'] / 5 );
$rrt_cv                  = function ( $plays ) use ( $rrt_len ) {
	$h = array();
	$r = array();
	for ( $k = 0; $k < $rrt_len; $k++ ) {
		$h[] = (int) round( $plays * ( 1 - 0.5 * $k / $rrt_len ) );
		$r[] = ( $k >= 20 && $k <= 24 ) ? (int) ceil( $plays / 2 ) : 0;
	}
	return array( 'heard' => $h, 'replayed' => $r, 'plays' => $plays );
};
$rrt_plain = rrt_suggest_clips( $rrt_ss );
rrt_t( 4 === count( $rrt_plain ) && max( wp_list_pluck( $rrt_plain, 'd' ) ) <= 45, 'with no paragraph edges to reach for, every suggestion is 30 to 45 s', implode( ', ', wp_list_pluck( $rrt_plain, 'd' ) ) );
$rrt_lc = rrt_suggest_clips( $rrt_ss, $rrt_cv( 20 ) );
rrt_t( $rrt_lc[1]['s'] < 125 && $rrt_lc[1]['e'] > 100 && in_array( 'Listeners replay it', $rrt_lc[1]['reasons'], true ), 'with 20 plays, the stretch listeners replay is the top suggestion', $rrt_lc[1]['s'] . ' to ' . $rrt_lc[1]['e'] );
rrt_t( rrt_suggest_clips( $rrt_ss, $rrt_cv( 9 ) ) == rrt_suggest_clips( $rrt_ss ), 'with 9 plays the curve changes nothing' ); // phpcs:ignore Universal.Operators.StrictComparisons
$rrt_held = $rrt_cv( 20 );
for ( $k = 0; $k < $rrt_len; $k++ ) {
	/* Many leave between 0:50 and 1:40; those still there at 1:40 stay to the end. */
	$rrt_held['heard'][ $k ]    = $k < 10 ? 20 : ( $k < 20 ? 20 - ( $k - 10 ) : 10 );
	$rrt_held['replayed'][ $k ] = 0;
}
$rrt_lh    = rrt_suggest_clips( $rrt_ss, $rrt_held );
$rrt_stays = array_filter( array_slice( $rrt_lh, 1 ), function ( $x ) { return in_array( 'Listeners stay through it', $x['reasons'], true ) && $x['s'] >= 95; } );
$rrt_falls = array_filter( array_slice( $rrt_lh, 1 ), function ( $x ) { return $x['s'] > 45 && $x['e'] < 100; } );
rrt_t( count( $rrt_stays ) >= 2 && ! $rrt_falls, 'stretches listeners stay through count for more than ones they leave', implode( ' | ', wp_list_pluck( $rrt_lh, 's' ) ) );

/* Live test on a real reading (16 Nov): a paragraph ending "Declare:" leads into a chant, so no stretch ends there. */
$rrt_lead                = array( array_fill( 0, 8, 10 ), array( 10, 10, 10, 10, 10, 10, 10, array( 10, 'Then', 0, ':' ) ), array( 4, 4, 4, 4 ), array_fill( 0, 8, 10 ), array_fill( 0, 8, 10 ) );
list( $rrt_cq, $rrt_bk ) = $rrt_sg_make( 'A Chant', $rrt_lead );
$rrt_ss                  = rrt_suggest_sentences( $rrt_cq, $rrt_bk );
$rrt_colon               = array_values( array_filter( $rrt_ss, function ( $x ) { return ':' === substr( $x['t'], -1 ); } ) );
$rrt_on                  = array_filter( rrt_suggest_candidates( $rrt_ss ), function ( $x ) use ( $rrt_colon ) { return abs( $x['e'] - $rrt_colon[0]['e'] ) < 0.01; } );
rrt_t( 1 === count( $rrt_colon ) && $rrt_colon[0]['pe'] && ! $rrt_on, 'no stretch ends on a paragraph that leads in with a colon' );

/* Equal choices spread across the reading instead of bunching at its start. */
list( $rrt_cq, $rrt_bk ) = $rrt_sg_make( 'Even Paragraphs', array_fill( 0, 12, array_fill( 0, 8, 10 ) ) );
$rrt_ss                  = rrt_suggest_sentences( $rrt_cq, $rrt_bk );
$rrt_even                = rrt_suggest_clips( $rrt_ss );
$rrt_late                = end( $rrt_ss )['e'] / 2;
rrt_t( 4 === count( $rrt_even ) && count( array_filter( array_slice( $rrt_even, 1 ), function ( $x ) use ( $rrt_late ) { return $x['s'] > $rrt_late; } ) ) >= 2, 'equal suggestions spread across the reading', implode( ', ', wp_list_pluck( $rrt_even, 's' ) ) );

/* Zero-width spaces from the post's text never reach a suggestion's words. */
$rrt_zw = array(
	array( 's' => 0.3, 'e' => 2, 't' => 'Zero Width' ),
	array( 's' => 3, 'e' => 33, 't' => "\u{200B}Gather a bowl of water and salt." ),
);
$rrt_zs = rrt_suggest_sentences( $rrt_zw, array( array( 'type' => 'title', 'text' => 'Zero Width' ), array( 'type' => 'p', 'text' => "\u{200B}Gather a bowl of water and salt." ) ) );
rrt_t( 0 === strpos( $rrt_zs[1]['t'], 'Gather' ) && $rrt_zs[1]['ps'], 'a zero-width space at a paragraph start is dropped and the paragraph still found' );

/* Live test on a real reading (16 Nov): sentences only 0.2 to 0.5 s apart. A clip stays inside the pauses either side. */
$rrt_tight = array(
	array( 's' => 4.41, 'e' => 8.71, 't' => 'x', 'kind' => 'title' ),
	array( 's' => 9.29, 'e' => 37.49, 't' => 'x', 'kind' => 'p' ),
	array( 's' => 37.75, 'e' => 39.57, 't' => 'x', 'kind' => 'p' ),
);
$rrt_bd  = rrt_suggest_bounds( $rrt_tight, 1, 1 );
$rrt_end = $rrt_bd['start'] + $rrt_bd['len'];
rrt_t( $rrt_bd['start'] > 8.71 && $rrt_bd['start'] < 9.29 && $rrt_end > 37.49 && $rrt_end < 37.75, 'a clip starts after the sentence before ends and stops before the next one starts', $rrt_bd['start'] . ' to ' . $rrt_end );
rrt_t( 37.75 === $rrt_bd['next'] && 8.71 === $rrt_bd['prev'], 'the clip maker gets the sentences either side, to settle the edges on the audio' );
$rrt_bd = rrt_suggest_bounds( $rrt_tight, 0, 2 );
rrt_t( abs( $rrt_bd['start'] - 4.26 ) < 0.001 && abs( $rrt_bd['start'] + $rrt_bd['len'] - 39.87 ) < 0.011, 'with nothing before or after, a 0.15 s breath and a 0.3 s tail' );

/* A reading under 30 s has no suggestions. */
list( $rrt_cq, $rrt_bk ) = $rrt_sg_make( 'A Cloak', array( array( 10, 10, 10 ) ) );
rrt_t( array() === rrt_suggest_clips( rrt_suggest_sentences( $rrt_cq, $rrt_bk ) ), 'a reading too short for a 30 s stretch has no suggestions' );
rrt_t( array() === rrt_suggest_clips( array() ), 'a reading with no captions has no suggestions' );

/* The pick for the feed: kept per post, forgotten when the reading changes. */
$rrt_sp = wp_insert_post( array( 'post_title' => 'Soundbite test', 'post_status' => 'publish', 'post_content' => '<p>Leaves fall.</p>' ) );
update_post_meta( $rrt_sp, 'rrt_audio', 'http://example.test/one.mp3' );
update_post_meta( $rrt_sp, RRT_SOUNDBITE_META, array( 's' => 12.35, 'd' => 38.4, 'text' => 'x', 'audio' => 'http://example.test/one.mp3' ) );
$rrt_pk = rrt_soundbite_pick( $rrt_sp );
rrt_t( $rrt_pk && 12.35 === $rrt_pk['s'], 'the pick for the feed is kept with the post' );
update_post_meta( $rrt_sp, 'rrt_audio', 'http://example.test/two.mp3' );
rrt_t( null === rrt_soundbite_pick( $rrt_sp ) && '' === get_post_meta( $rrt_sp, RRT_SOUNDBITE_META, true ), 'a new reading on the post clears the pick' );
rrt_t( null === rrt_suggest_stretch( $rrt_sp, 0, 30 ), 'a post with no captions has no stretch to keep' );
wp_delete_post( $rrt_sp, true );

/* Kind of reading (1.11.0). */
$rrt_cf = rrt_clean_cues( array( array( 'i' => 3, 'k' => 'night', 'a' => '', 'f' => 1 ), array( 'i' => 4, 'k' => 'x', 'f' => 0 ) ) );
rrt_t( 1 === count( $rrt_cf ) && true === $rrt_cf[0]['f'], 'a cue mark with only a fade meant is kept' );
rrt_t( false === $rrt_cl[0]['f'], 'a cue mark saved before 1.11.0 comes back with no fade' );
rrt_t( rrt_kind_defaults() === rrt_kind_ranges(), 'a new site has the four kinds at their first ranges' );
rrt_t( array( 'prose', 'story', 'verse', 'ritual' ) === array_keys( rrt_kinds() ), 'the kinds come in menu order' );
$rrt_kr = rrt_clean_kind_ranges( array( 'verse' => array( '100', '125' ), 'ritual' => array( 300, 10 ), 'story' => 'junk', 'shout' => array( 1, 2 ) ) );
rrt_t( array( 100, 125 ) === $rrt_kr['verse'] && array( 130, 150 ) === $rrt_kr['story'] && ! isset( $rrt_kr['shout'] ), 'kind ranges are cleaned, a bad one left at its default' );
rrt_t( $rrt_kr['ritual'][0] >= 60 && $rrt_kr['ritual'][1] <= 220 && $rrt_kr['ritual'][1] >= $rrt_kr['ritual'][0] + 5, 'a range outside the limits is pulled inside them' );
$rrt_ss = rrt_sanitize_settings( array_merge( rrt_settings(), array( 'kinds' => array( 'verse' => array( 105, 130 ) ) ) ) );
rrt_t( array( 105, 130 ) === $rrt_ss['kinds']['verse'] && array( 140, 160 ) === $rrt_ss['kinds']['prose'], 'Settings saves the kind ranges' );
$rrt_poem = '<!-- wp:paragraph --><p>The moon is round<br>the night is long<br>the owl is near</p><!-- /wp:paragraph --><p>The water sings<br>the stones are cold<br>the reeds bow low</p>';
$rrt_essay = '<p>' . str_repeat( 'This is a long sentence of ordinary prose that runs on for a good while. ', 3 ) . '</p><p>' . str_repeat( 'Another paragraph of plain prose with many words in every line of it. ', 3 ) . '</p>';
rrt_t( 'verse' === rrt_kind_guess_html( $rrt_poem ), 'short lines split by line breaks look like verse' );
rrt_t( '' === rrt_kind_guess_html( $rrt_essay ), 'long paragraphs do not look like verse' );
rrt_t( '' === rrt_kind_guess_html( '<p>One line.</p><p>Two lines.</p>' ), 'a post too short to tell is not called verse' );
$rrt_kp = wp_insert_post( array( 'post_title' => 'Kind test', 'post_status' => 'publish', 'post_content' => $rrt_poem ) );
rrt_t( '' === rrt_get_kind( $rrt_kp ) && 'verse' === rrt_kind_guess( $rrt_kp ), 'a post with no kind has none, and the guess is Verse' );
rrt_t( 'ritual' === rrt_save_kind( $rrt_kp, 'ritual' ) && 'ritual' === rrt_get_kind( $rrt_kp ), 'a kind is saved with the post' );
rrt_t( '' === rrt_save_kind( $rrt_kp, 'shout' ) && '' === get_post_meta( $rrt_kp, RRT_KIND_META, true ), 'an unknown kind removes it' );
update_post_meta( $rrt_kp, RRT_KIND_META, 'nonsense' );
rrt_t( '' === rrt_get_kind( $rrt_kp ), 'a stored value that is not a kind reads as none' );
/* The Reading audio box saves the kind only when it was changed there. */
wp_set_current_user( 1 );
$rrt_box = function ( $kind, $was ) use ( $rrt_kp ) {
	$_POST = array( 'rrt_reading_nonce' => wp_create_nonce( 'rrt_reading_box' ), 'rrt_kind' => $kind, 'rrt_was_kind' => $was );
	do_action( 'save_post', $rrt_kp, get_post( $rrt_kp ), true );
	$_POST = array();
};
delete_post_meta( $rrt_kp, RRT_KIND_META );
$rrt_box( 'verse', '' );
rrt_t( 'verse' === rrt_get_kind( $rrt_kp ), 'the Reading audio box saves a kind chosen in it' );
rrt_save_kind( $rrt_kp, 'ritual' );
$rrt_box( 'verse', 'verse' );
rrt_t( 'ritual' === rrt_get_kind( $rrt_kp ), 'an edit screen left open does not undo a kind chosen in the studio meanwhile' );
$rrt_box( '', 'verse' );
rrt_t( '' === rrt_get_kind( $rrt_kp ), 'Not chosen in the box removes the kind' );
wp_set_current_user( 0 );
$rrt_kb = rrt_kind_boot( $rrt_kp );
rrt_t( '' === $rrt_kb['rest'] && isset( $rrt_kb['ranges']['verse'] ), 'the studio gets the ranges, but no save address for a visitor' );
wp_delete_post( $rrt_kp, true );

echo "\n" . $GLOBALS['rrt_pass'] . ' passed, ' . count( $GLOBALS['rrt_fail'] ) . " failed\n";
if ( $GLOBALS['rrt_fail'] ) {
	exit( 1 );
}
