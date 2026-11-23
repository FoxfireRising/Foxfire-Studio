<?php
/**
 * Reading languages (1.33.0), run inside the test WordPress:
 *   php wp-cli.phar --path=<wordpress> eval-file tests/php/languages.php
 * The language list and tags; the site's language and each post's; the
 * Reading audio box and its save; the multilingual Whisper models, pinned
 * and fingerprinted; How hard outside English; word keys in any script with
 * English keys unchanged; taught words per language on the site, exported
 * and erased; the feed's transcript, the player's captions and transcript
 * and the MP3's tag. Prints one line per check and exits 1 if any fail.
 * Leaves nothing behind.
 */

$GLOBALS['lg_pass'] = 0;
$GLOBALS['lg_fail'] = array();

function lg_t( $ok, $name, $detail = '' ) {
	if ( $ok ) {
		++$GLOBALS['lg_pass'];
		echo "ok   $name\n";
	} else {
		$GLOBALS['lg_fail'][] = $name;
		echo "FAIL $name" . ( '' !== (string) $detail ? ': ' . $detail : '' ) . "\n";
	}
}

wp_set_current_user( 1 );

/* ---- The list ---- */
$lg_all = rrt_languages();
lg_t( 'en' === array_key_first( $lg_all ), 'English comes first in the list' );
lg_t( count( $lg_all ) >= 90, 'about ninety languages are offered', count( $lg_all ) );
lg_t( ! array_intersect_key( $lg_all, rrt_languages_later() ), 'none of the languages written without spaces is offered yet' );
lg_t( isset( rrt_languages_later()['ja'], rrt_languages_later()['zh'], rrt_languages_later()['th'] ), 'Chinese, Japanese and Thai are listed as coming later' );
$lg_bad = array();
foreach ( array_keys( $lg_all ) as $lg_c ) {
	if ( ! preg_match( '/^[a-z]{2,3}$/', $lg_c ) || ( 'en' !== $lg_c && 'eng' === rrt_lang_iso3( $lg_c ) ) || ! preg_match( '/^[a-z]{3}$/', rrt_lang_iso3( $lg_c ) ) ) {
		$lg_bad[] = $lg_c;
	}
}
lg_t( ! $lg_bad, 'every language has a code and its own three-letter tag for the MP3', implode( ' ', $lg_bad ) );
lg_t( 'en' === rrt_lang_from_tag( 'en_US' ) && 'pt' === rrt_lang_from_tag( 'pt_BR' ) && 'no' === rrt_lang_from_tag( 'nb_NO' ) && 'de' === rrt_lang_from_tag( 'de-CH' ), 'WordPress locales map to reading languages' );
lg_t( '' === rrt_lang_from_tag( 'ja' ) && '' === rrt_lang_from_tag( 'zh_CN' ) && '' === rrt_lang_from_tag( 'xx' ), 'a locale the review cannot line up maps to nothing' );

/* ---- The site's language and the practice reading ---- */
lg_t( 'en' === rrt_site_lang(), 'an English site reads in English' );
$lg_loc = function () {
	return 'es_ES';
};
add_filter( 'locale', $lg_loc );
lg_t( 'es' === rrt_site_lang(), 'a Spanish site reads in Spanish' );
lg_t( 'en' === rrt_practice_lang(), 'the practice reading stays English while it has no Spanish translation' );
remove_filter( 'locale', $lg_loc );
$lg_ja = function () {
	return 'ja';
};
add_filter( 'locale', $lg_ja );
lg_t( 'en' === rrt_site_lang(), 'a Japanese site falls back to English for now' );
remove_filter( 'locale', $lg_ja );

/* ---- A post's language ---- */
$lg_post = wp_insert_post(
	array(
		'post_title'   => 'Lg test',
		'post_content' => '<p>Le hibou chante.</p>',
		'post_status'  => 'publish',
	)
);
lg_t( '' === rrt_get_post_lang( $lg_post ) && 'en' === rrt_post_lang( $lg_post ), 'a new post has no language of its own and reads in the site\'s' );
lg_t( 'fr' === rrt_save_post_lang( $lg_post, 'fr' ) && 'fr' === rrt_post_lang( $lg_post ), 'a post can be read in French' );
lg_t( '' === rrt_save_post_lang( $lg_post, 'ja' ) && 'en' === rrt_post_lang( $lg_post ), 'a language not offered is refused and the site\'s used' );
rrt_save_post_lang( $lg_post, 'fr' );
$lg_boot = rrt_lang_boot( $lg_post );
lg_t( 'fr' === $lg_boot['code'] && false === $lg_boot['en'] && 'French' === $lg_boot['name'] && 'fr' === $lg_boot['tag'], 'the studio is told the language' );
lg_t( true === rrt_lang_boot( 0 )['en'], 'the practice reading is English' );

/* The Reading audio box. */
ob_start();
rrt_reading_box( get_post( $lg_post ) );
$lg_box = (string) ob_get_clean();
lg_t( false !== strpos( $lg_box, 'id="rrt_lang"' ) && false !== strpos( $lg_box, 'Same as the site (English)' ), 'the box has the Reading language menu' );
lg_t( (bool) preg_match( '/<option value="fr" selected=\'selected\'>French<\/option>/', $lg_box ), 'the post\'s language is chosen in the menu', substr( $lg_box, strpos( $lg_box, 'rrt_lang' ), 200 ) );
lg_t( false !== strpos( $lg_box, 'Japanese' ) && false === strpos( $lg_box, '<option value="ja"' ), 'the box names the languages coming later without offering them' );
lg_t( strpos( $lg_box, 'id="rrt_kind"' ) < strpos( $lg_box, 'id="rrt_lang"' ), 'the menu comes after the kind of reading' );

/* Saving the box. */
$_POST['rrt_reading_nonce'] = wp_create_nonce( 'rrt_reading_box' );
$_POST['rrt_lang']          = 'de';
wp_update_post( array( 'ID' => $lg_post, 'post_title' => 'Lg test 2' ) );
lg_t( 'de' === rrt_get_post_lang( $lg_post ), 'saving the box keeps the language chosen' );
$_POST['rrt_lang'] = '';
wp_update_post( array( 'ID' => $lg_post, 'post_title' => 'Lg test 3' ) );
lg_t( '' === rrt_get_post_lang( $lg_post ), 'Same as the site clears the post\'s own choice' );
$_POST['rrt_lang']          = 'es';
$_POST['rrt_reading_nonce'] = 'wrong';
wp_update_post( array( 'ID' => $lg_post, 'post_title' => 'Lg test 4' ) );
lg_t( '' === rrt_get_post_lang( $lg_post ), 'a save without the box\'s nonce changes nothing' );
unset( $_POST['rrt_lang'], $_POST['rrt_reading_nonce'] );

/* ---- Whisper's multilingual models ---- */
$lg_files  = rrt_whisper_files( 'multi' );
$lg_hashes = rrt_whisper_hashes();
$lg_bad    = array();
foreach ( $lg_files as $lg_f ) {
	if ( empty( $lg_hashes[ $lg_f['to'] ] ) || ! preg_match( '/^[0-9a-f]{64}$/', $lg_hashes[ $lg_f['to'] ] ) || ! preg_match( '#/resolve/[0-9a-f]{40}/#', $lg_f['from'] ) ) {
		$lg_bad[] = $lg_f['to'];
	}
}
lg_t( 16 === count( $lg_files ), 'the other-languages download has both models and the graphics chip files', count( $lg_files ) );
lg_t( ! $lg_bad, 'every file is pinned to a revision and has its fingerprint', implode( ' ', $lg_bad ) );
lg_t( 0 === strpos( $lg_files[0]['to'], RRT_WHISPER_MULTI . '/' ) && 0 === strpos( $lg_files[15]['to'], RRT_WHISPER_MSMALL . '/onnx/' ), 'the standard size comes first, the larger one\'s graphics chip files last' );
lg_t( false === strpos( RRT_WHISPER_MULTI, '.en' ) && false === strpos( RRT_WHISPER_MSMALL, '.en' ), 'the multilingual models are not the English ones' );
$lg_wb = rrt_whisper_boot( rrt_config() );
lg_t( RRT_WHISPER_MULTI === $lg_wb['multi']['model'] && RRT_WHISPER_MULTI_REV === $lg_wb['multi']['rev'] && RRT_WHISPER_MSMALL === $lg_wb['multi']['small']['model'] && '' === $lg_wb['multi']['fallback'], 'the studio is told where the multilingual models load from, pinned and with no fallback' );
lg_t( 'onnx-community/whisper-base.en_timestamped' === $lg_wb['model'], 'English keeps its own model' );
$lg_st = rrt_whisper_status( 'multi' );
lg_t( 16 === $lg_st['count'], 'Settings can show the other-languages files' );
$lg_src = (string) file_get_contents( RRT_DIR . 'includes/whisper.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
lg_t( false !== strpos( $lg_src, "array( 'small', 'follow', 'multi' )" ), 'the download accepts the other-languages set' );
ob_start();
rrt_settings_page();
$lg_set = (string) ob_get_clean();
lg_t( false !== strpos( $lg_set, 'id="rrt-whisper-multi-get"' ) && false !== strpos( $lg_set, 'id="rrt-whisper-multi-files"' ), 'Settings has the other-languages download' );

/* ---- How hard ---- */
$lg_de = array(
	array(
		'type' => 'p',
		'text' => 'Die Eule singt über dem Fluss. Der Mond scheint heute über dem Wald und dem Haus.',
	),
);
$lg_m_en = rrt_backlog_measure( $lg_de, array(), array(), 'en' );
$lg_m_de = rrt_backlog_measure( $lg_de, array(), array(), 'de' );
lg_t( count( $lg_m_en['names'] ) > 0 && 0 === count( $lg_m_de['names'] ), 'German nouns are not taken for names', implode( ',', $lg_m_en['names'] ) );
$lg_es = array(
	array(
		'type' => 'p',
		'text' => 'La responsabilidad es extraordinariamente importante para Margarita.',
	),
);
$lg_m_es = rrt_backlog_measure( $lg_es, array(), array(), 'es' );
lg_t( in_array( 'responsabilidad', $lg_m_es['big'], true ) && in_array( 'extraordinariamente', $lg_m_es['big'], true ) && ! in_array( 'importante', $lg_m_es['big'], true ), 'outside English a very long word is counted by its letters', implode( ',', $lg_m_es['big'] ) );
lg_t( array( 'Margarita' ) === $lg_m_es['names'], 'Spanish names are still counted' );
$lg_ru = array(
	array(
		'type' => 'p',
		'text' => 'Сова поёт над рекой. Вот Анна смотрит на луну.',
	),
);
$lg_m_ru = rrt_backlog_measure( $lg_ru, array( 'смотрит' => true ), array(), 'ru' );
lg_t( 9 === $lg_m_ru['words'] && array( 'Анна' ) === $lg_m_ru['names'] && array( 'смотрит' ) === $lg_m_ru['known'], 'a Russian post is measured in its own letters', wp_json_encode( $lg_m_ru ) );
$lg_en = array(
	array(
		'type' => 'p',
		'text' => 'The owls call over the river where Siobhan waits. Responsibility is extraordinary.',
	),
);
$lg_m_e1 = rrt_backlog_measure( $lg_en, array() );
lg_t( array( 'Siobhan' ) === $lg_m_e1['names'] && array( 'Responsibility', 'extraordinary' ) === $lg_m_e1['big'], 'an English post is measured exactly as before', wp_json_encode( $lg_m_e1 ) );

/* ---- Word keys ---- */
lg_t( 'siobhans' === rrt_note_key( 'Siobhan’s,' ) && 'naïve' === rrt_note_key( 'naïve' ) && 'hello' === rrt_note_key( '“Hello”' ) && '12th' === rrt_note_key( '12th' ), 'English word keys are unchanged' );
lg_t( 'привет' === rrt_note_key( 'Привет!' ) && 'καλημέρα' === rrt_note_key( 'Καλημέρα;' ), 'a word of another script keeps its letters' );

/* ---- Taught words per language ---- */
$lg_user = get_current_user_id();
$lg_had  = get_user_meta( $lg_user, 'rrt_learned', true );
update_user_meta( $lg_user, 'rrt_learned', array( 'pairs' => array( 'siobhan' => array( 'shivon' => true ) ) ) );
$lg_r = new WP_REST_Request( 'POST', '/rrt/v1/learned' );
$lg_r->set_param( 'lang', 'es' );
$lg_r->set_param( 'learned', array( 'pairs' => array( 'xochitl' => array( 'sochil' => true ) ), 'ignore' => array(), 'labels' => array(), 'at' => array(), 'forgot' => array() ) );
$lg_res = rest_do_request( $lg_r );
$lg_es_l = get_user_meta( $lg_user, 'rrt_learned_es', true );
lg_t( 200 === $lg_res->get_status() && isset( $lg_es_l['pairs']['xochitl'] ), 'a word taught in Spanish is kept as Spanish', wp_json_encode( $lg_res->get_data() ) );
$lg_en_l = get_user_meta( $lg_user, 'rrt_learned', true );
lg_t( isset( $lg_en_l['pairs']['siobhan'] ) && ! isset( $lg_en_l['pairs']['xochitl'] ), 'the English words are left alone' );
$lg_g = new WP_REST_Request( 'GET', '/rrt/v1/learned' );
$lg_g->set_param( 'lang', 'es' );
$lg_gd = rest_do_request( $lg_g )->get_data();
lg_t( isset( $lg_gd['learned']['pairs']['xochitl'] ) && ! isset( $lg_gd['learned']['pairs']['siobhan'] ), 'asking for Spanish gives the Spanish words' );
$lg_g2 = new WP_REST_Request( 'GET', '/rrt/v1/learned' );
$lg_gd2 = rest_do_request( $lg_g2 )->get_data();
lg_t( isset( $lg_gd2['learned']['pairs']['siobhan'] ) && ! isset( $lg_gd2['learned']['pairs']['xochitl'] ), 'asking with no language gives the English words, as before' );
$lg_g3 = new WP_REST_Request( 'GET', '/rrt/v1/learned' );
$lg_g3->set_param( 'lang', '../x' );
$lg_gd3 = rest_do_request( $lg_g3 )->get_data();
lg_t( isset( $lg_gd3['learned']['pairs']['siobhan'] ), 'an odd language is taken as English' );
$lg_exp = apply_filters( 'wp_privacy_personal_data_exporters', array() );
$lg_out = call_user_func( $lg_exp['rrt-reader-stats']['callback'], wp_get_current_user()->user_email, 1 );
$lg_ids = wp_list_pluck( $lg_out['data'], 'item_id' );
lg_t( in_array( 'rrt-learned', $lg_ids, true ) && in_array( 'rrt-learned-es', $lg_ids, true ), 'the personal data export has the words in each language' );
$lg_era = apply_filters( 'wp_privacy_personal_data_erasers', array() );
call_user_func( $lg_era['rrt-reader-stats']['callback'], wp_get_current_user()->user_email, 1 );
lg_t( '' === get_user_meta( $lg_user, 'rrt_learned_es', true ) && '' === get_user_meta( $lg_user, 'rrt_learned', true ), 'erasing a reader\'s data removes the words in every language' );
if ( $lg_had ) {
	update_user_meta( $lg_user, 'rrt_learned', $lg_had );
}

/* ---- The feed, the player and the MP3 ---- */
rrt_save_post_lang( $lg_post, 'fr' );
lg_t( 'fra' === rrt_mp3_tags( get_post( $lg_post ) )['lang'], 'a French reading\'s MP3 carries its language' );

$lg_att = wp_insert_attachment(
	array(
		'post_mime_type' => 'audio/mpeg',
		'post_title'     => 'lg reading',
		'post_status'    => 'inherit',
	),
	'lg-reading.mp3',
	$lg_post
);
$lg_vtt = wp_insert_attachment(
	array(
		'post_mime_type' => 'text/vtt',
		'post_title'     => 'lg captions',
		'post_status'    => 'inherit',
	),
	'lg-captions.vtt',
	$lg_post
);
update_post_meta( $lg_post, 'rrt_audio', $lg_att );
update_post_meta( $lg_post, 'rrt_captions', $lg_vtt );
rrt_save_post_lang( $lg_post, 'fr' );
$lg_ep = rrt_podcast_episode( get_post( $lg_post ) );
lg_t( 'fr' === $lg_ep['lang'], 'the feed gives a French reading\'s transcript as French', $lg_ep['lang'] );
rrt_save_post_lang( $lg_post, '' );
$lg_ep2 = rrt_podcast_episode( get_post( $lg_post ) );
lg_t( strtolower( str_replace( '_', '-', get_bloginfo( 'language' ) ) ) === $lg_ep2['lang'], 'a reading in the site\'s language keeps the site\'s full tag', $lg_ep2['lang'] );
lg_t( '' === rrt_mp3_tags( get_post( $lg_post ) )['lang'], 'an English reading\'s MP3 is tagged as before' );
rrt_save_post_lang( $lg_post, 'fr' );
$lg_html = (string) rrt_player( get_post( $lg_post ) );
lg_t( false !== strpos( $lg_html, 'srclang="fr"' ) && false !== strpos( $lg_html, 'class="rrt-transcript" id="rrt-transcript-' . $lg_post . '" lang="fr"' ), 'the player marks a French reading\'s captions and transcript as French', substr( $lg_html, 0, 300 ) );
lg_t( false !== strpos( $lg_html, '&quot;lang&quot;:&quot;fr&quot;' ), 'read along is told the language' );
rrt_save_post_lang( $lg_post, '' );
$lg_html2 = (string) rrt_player( get_post( $lg_post ) );
lg_t( false !== strpos( $lg_html2, 'srclang="' . substr( get_locale(), 0, 2 ) . '"' ) && false === strpos( $lg_html2, '" lang="' ), 'a reading in the site\'s language prints as before' );

wp_delete_attachment( $lg_att, true );
wp_delete_attachment( $lg_vtt, true );
wp_delete_post( $lg_post, true );

echo "\n" . $GLOBALS['lg_pass'] . ' passed, ' . count( $GLOBALS['lg_fail'] ) . " failed\n";
exit( $GLOBALS['lg_fail'] ? 1 : 0 );
