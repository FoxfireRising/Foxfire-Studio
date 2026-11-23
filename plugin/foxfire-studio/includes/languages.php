<?php
/**
 * Reading languages (1.33.0): which language a post is read in.
 *
 * The site's own language (Settings, General, Site Language) is every
 * reading's language unless the post's Reading audio box chooses another.
 * English readings are reviewed by the English Whisper models exactly as
 * before; any other language uses the multilingual models with that
 * language set, so Whisper never translates.
 *
 * Only languages written with spaces between words are offered: the
 * review lines up words, and Chinese, Japanese, Thai, Lao, Khmer, Burmese
 * and Tibetan are written without them. They are listed as coming later.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RRT_LANG_META', '_rrt_lang' );

/**
 * Every language a reading can be in, by Whisper's code, with its name.
 *
 * @return array<string,string>
 */
function rrt_languages() {
	$l = array(
		'en'  => __( 'English', 'foxfire-studio' ),
		'af'  => __( 'Afrikaans', 'foxfire-studio' ),
		'am'  => __( 'Amharic', 'foxfire-studio' ),
		'ar'  => __( 'Arabic', 'foxfire-studio' ),
		'as'  => __( 'Assamese', 'foxfire-studio' ),
		'az'  => __( 'Azerbaijani', 'foxfire-studio' ),
		'ba'  => __( 'Bashkir', 'foxfire-studio' ),
		'be'  => __( 'Belarusian', 'foxfire-studio' ),
		'bg'  => __( 'Bulgarian', 'foxfire-studio' ),
		'bn'  => __( 'Bengali', 'foxfire-studio' ),
		'br'  => __( 'Breton', 'foxfire-studio' ),
		'bs'  => __( 'Bosnian', 'foxfire-studio' ),
		'ca'  => __( 'Catalan', 'foxfire-studio' ),
		'cs'  => __( 'Czech', 'foxfire-studio' ),
		'cy'  => __( 'Welsh', 'foxfire-studio' ),
		'da'  => __( 'Danish', 'foxfire-studio' ),
		'de'  => __( 'German', 'foxfire-studio' ),
		'el'  => __( 'Greek', 'foxfire-studio' ),
		'es'  => __( 'Spanish', 'foxfire-studio' ),
		'et'  => __( 'Estonian', 'foxfire-studio' ),
		'eu'  => __( 'Basque', 'foxfire-studio' ),
		'fa'  => __( 'Persian', 'foxfire-studio' ),
		'fi'  => __( 'Finnish', 'foxfire-studio' ),
		'fo'  => __( 'Faroese', 'foxfire-studio' ),
		'fr'  => __( 'French', 'foxfire-studio' ),
		'gl'  => __( 'Galician', 'foxfire-studio' ),
		'gu'  => __( 'Gujarati', 'foxfire-studio' ),
		'ha'  => __( 'Hausa', 'foxfire-studio' ),
		'haw' => __( 'Hawaiian', 'foxfire-studio' ),
		'he'  => __( 'Hebrew', 'foxfire-studio' ),
		'hi'  => __( 'Hindi', 'foxfire-studio' ),
		'hr'  => __( 'Croatian', 'foxfire-studio' ),
		'ht'  => __( 'Haitian Creole', 'foxfire-studio' ),
		'hu'  => __( 'Hungarian', 'foxfire-studio' ),
		'hy'  => __( 'Armenian', 'foxfire-studio' ),
		'id'  => __( 'Indonesian', 'foxfire-studio' ),
		'is'  => __( 'Icelandic', 'foxfire-studio' ),
		'it'  => __( 'Italian', 'foxfire-studio' ),
		'jw'  => __( 'Javanese', 'foxfire-studio' ),
		'ka'  => __( 'Georgian', 'foxfire-studio' ),
		'kk'  => __( 'Kazakh', 'foxfire-studio' ),
		'kn'  => __( 'Kannada', 'foxfire-studio' ),
		'ko'  => __( 'Korean', 'foxfire-studio' ),
		'la'  => __( 'Latin', 'foxfire-studio' ),
		'lb'  => __( 'Luxembourgish', 'foxfire-studio' ),
		'ln'  => __( 'Lingala', 'foxfire-studio' ),
		'lt'  => __( 'Lithuanian', 'foxfire-studio' ),
		'lv'  => __( 'Latvian', 'foxfire-studio' ),
		'mg'  => __( 'Malagasy', 'foxfire-studio' ),
		'mi'  => __( 'Maori', 'foxfire-studio' ),
		'mk'  => __( 'Macedonian', 'foxfire-studio' ),
		'ml'  => __( 'Malayalam', 'foxfire-studio' ),
		'mn'  => __( 'Mongolian', 'foxfire-studio' ),
		'mr'  => __( 'Marathi', 'foxfire-studio' ),
		'ms'  => __( 'Malay', 'foxfire-studio' ),
		'mt'  => __( 'Maltese', 'foxfire-studio' ),
		'ne'  => __( 'Nepali', 'foxfire-studio' ),
		'nl'  => __( 'Dutch', 'foxfire-studio' ),
		'nn'  => __( 'Norwegian Nynorsk', 'foxfire-studio' ),
		'no'  => __( 'Norwegian', 'foxfire-studio' ),
		'oc'  => __( 'Occitan', 'foxfire-studio' ),
		'pa'  => __( 'Punjabi', 'foxfire-studio' ),
		'pl'  => __( 'Polish', 'foxfire-studio' ),
		'ps'  => __( 'Pashto', 'foxfire-studio' ),
		'pt'  => __( 'Portuguese', 'foxfire-studio' ),
		'ro'  => __( 'Romanian', 'foxfire-studio' ),
		'ru'  => __( 'Russian', 'foxfire-studio' ),
		'sa'  => __( 'Sanskrit', 'foxfire-studio' ),
		'sd'  => __( 'Sindhi', 'foxfire-studio' ),
		'si'  => __( 'Sinhala', 'foxfire-studio' ),
		'sk'  => __( 'Slovak', 'foxfire-studio' ),
		'sl'  => __( 'Slovenian', 'foxfire-studio' ),
		'sn'  => __( 'Shona', 'foxfire-studio' ),
		'so'  => __( 'Somali', 'foxfire-studio' ),
		'sq'  => __( 'Albanian', 'foxfire-studio' ),
		'sr'  => __( 'Serbian', 'foxfire-studio' ),
		'su'  => __( 'Sundanese', 'foxfire-studio' ),
		'sv'  => __( 'Swedish', 'foxfire-studio' ),
		'sw'  => __( 'Swahili', 'foxfire-studio' ),
		'ta'  => __( 'Tamil', 'foxfire-studio' ),
		'te'  => __( 'Telugu', 'foxfire-studio' ),
		'tg'  => __( 'Tajik', 'foxfire-studio' ),
		'tk'  => __( 'Turkmen', 'foxfire-studio' ),
		'tl'  => __( 'Tagalog', 'foxfire-studio' ),
		'tr'  => __( 'Turkish', 'foxfire-studio' ),
		'tt'  => __( 'Tatar', 'foxfire-studio' ),
		'uk'  => __( 'Ukrainian', 'foxfire-studio' ),
		'ur'  => __( 'Urdu', 'foxfire-studio' ),
		'uz'  => __( 'Uzbek', 'foxfire-studio' ),
		'vi'  => __( 'Vietnamese', 'foxfire-studio' ),
		'yi'  => __( 'Yiddish', 'foxfire-studio' ),
		'yo'  => __( 'Yoruba', 'foxfire-studio' ),
	);
	$en = array( 'en' => $l['en'] );
	unset( $l['en'] );
	asort( $l, SORT_LOCALE_STRING );
	return $en + $l;
}

/**
 * Languages Whisper knows that are written without spaces between words,
 * so the review cannot line their words up yet.
 *
 * @return array<string,string>
 */
function rrt_languages_later() {
	return array(
		'zh'  => __( 'Chinese', 'foxfire-studio' ),
		'yue' => __( 'Cantonese', 'foxfire-studio' ),
		'ja'  => __( 'Japanese', 'foxfire-studio' ),
		'th'  => __( 'Thai', 'foxfire-studio' ),
		'lo'  => __( 'Lao', 'foxfire-studio' ),
		'km'  => __( 'Khmer', 'foxfire-studio' ),
		'my'  => __( 'Burmese', 'foxfire-studio' ),
		'bo'  => __( 'Tibetan', 'foxfire-studio' ),
	);
}

/**
 * A WordPress locale or language tag (en_US, pt-BR, nb_NO) as a reading
 * language code, or '' when it is not one the review can line up.
 *
 * @param string $tag Locale or tag.
 * @return string
 */
function rrt_lang_from_tag( $tag ) {
	$p = strtolower( (string) preg_replace( '/[_-].*$/', '', (string) $tag ) );
	$map = array( 'nb' => 'no', 'iw' => 'he', 'jv' => 'jw', 'fil' => 'tl' );
	if ( isset( $map[ $p ] ) ) {
		$p = $map[ $p ];
	}
	return array_key_exists( $p, rrt_languages() ) ? $p : '';
}

/** The site's reading language: WordPress's Site Language, or English when that is not offered. */
function rrt_site_lang() {
	$code = rrt_lang_from_tag( get_locale() );
	return '' === $code ? 'en' : $code;
}

/**
 * A post's own choice, or '' for the site's language.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function rrt_get_post_lang( $post_id ) {
	$c = get_post_meta( (int) $post_id, RRT_LANG_META, true );
	return is_string( $c ) && array_key_exists( $c, rrt_languages() ) ? $c : '';
}

/**
 * The language a post is read in.
 *
 * @param int $post_id Post ID; 0 for the practice reading.
 * @return string
 */
function rrt_post_lang( $post_id ) {
	if ( (int) $post_id <= 0 ) {
		return rrt_practice_lang();
	}
	$c = rrt_get_post_lang( $post_id );
	return '' === $c ? rrt_site_lang() : $c;
}

/**
 * Saves a post's language; '' (or anything not offered) means the site's.
 *
 * @param int    $post_id Post ID.
 * @param string $code    Language code.
 * @return string As saved.
 */
function rrt_save_post_lang( $post_id, $code ) {
	$code = is_string( $code ) && array_key_exists( $code, rrt_languages() ) ? $code : '';
	if ( '' === $code ) {
		delete_post_meta( (int) $post_id, RRT_LANG_META );
	} else {
		update_post_meta( (int) $post_id, RRT_LANG_META, $code );
	}
	return $code;
}

/**
 * The practice reading's language: the site's, when this site has the
 * practice reading in it; otherwise English, the language it is written in.
 */
function rrt_practice_lang() {
	$site = rrt_site_lang();
	if ( 'en' === $site || ! function_exists( 'rrt_practice_paragraphs' ) ) {
		return 'en';
	}
	$first = rrt_practice_paragraphs();
	$first = isset( $first[0] ) ? $first[0] : '';
	/* The English text as written in the plugin: when the translation gives the same words back, there is none. */
	return '' !== $first && 0 !== strpos( $first, 'Welcome to your first practice reading.' ) ? $site : 'en';
}

/**
 * Whether How hard's name rule (a capital inside a sentence) can be used:
 * not in languages that give every noun a capital.
 *
 * @param string $code Language code.
 * @return bool
 */
function rrt_lang_name_rule( $code ) {
	return ! in_array( $code, array( 'de', 'lb' ), true );
}

/**
 * A language code as an HTML lang or feed language attribute.
 *
 * @param string $code Language code.
 * @return string
 */
function rrt_lang_attr( $code ) {
	$map = array( 'jw' => 'jv', 'haw' => 'haw' );
	return isset( $map[ $code ] ) ? $map[ $code ] : $code;
}

/**
 * A language code in ISO 639-2 (three letters), for the MP3's language tag.
 *
 * @param string $code Language code.
 * @return string
 */
function rrt_lang_iso3( $code ) {
	$map = array(
		'af' => 'afr', 'am' => 'amh', 'ar' => 'ara', 'as' => 'asm', 'az' => 'aze', 'ba' => 'bak', 'be' => 'bel', 'bg' => 'bul',
		'bn' => 'ben', 'br' => 'bre', 'bs' => 'bos', 'ca' => 'cat', 'cs' => 'ces', 'cy' => 'cym', 'da' => 'dan', 'de' => 'deu',
		'el' => 'ell', 'en' => 'eng', 'es' => 'spa', 'et' => 'est', 'eu' => 'eus', 'fa' => 'fas', 'fi' => 'fin', 'fo' => 'fao',
		'fr' => 'fra', 'gl' => 'glg', 'gu' => 'guj', 'ha' => 'hau', 'haw' => 'haw', 'he' => 'heb', 'hi' => 'hin', 'hr' => 'hrv',
		'ht' => 'hat', 'hu' => 'hun', 'hy' => 'hye', 'id' => 'ind', 'is' => 'isl', 'it' => 'ita', 'jw' => 'jav', 'ka' => 'kat',
		'kk' => 'kaz', 'kn' => 'kan', 'ko' => 'kor', 'la' => 'lat', 'lb' => 'ltz', 'ln' => 'lin', 'lt' => 'lit', 'lv' => 'lav',
		'mg' => 'mlg', 'mi' => 'mri', 'mk' => 'mkd', 'ml' => 'mal', 'mn' => 'mon', 'mr' => 'mar', 'ms' => 'msa', 'mt' => 'mlt',
		'ne' => 'nep', 'nl' => 'nld', 'nn' => 'nno', 'no' => 'nor', 'oc' => 'oci', 'pa' => 'pan', 'pl' => 'pol', 'ps' => 'pus',
		'pt' => 'por', 'ro' => 'ron', 'ru' => 'rus', 'sa' => 'san', 'sd' => 'snd', 'si' => 'sin', 'sk' => 'slk', 'sl' => 'slv',
		'sn' => 'sna', 'so' => 'som', 'sq' => 'sqi', 'sr' => 'srp', 'su' => 'sun', 'sv' => 'swe', 'sw' => 'swa', 'ta' => 'tam',
		'te' => 'tel', 'tg' => 'tgk', 'tk' => 'tuk', 'tl' => 'tgl', 'tr' => 'tur', 'tt' => 'tat', 'uk' => 'ukr', 'ur' => 'urd',
		'uz' => 'uzb', 'vi' => 'vie', 'yi' => 'yid', 'yo' => 'yor',
	);
	return isset( $map[ $code ] ) ? $map[ $code ] : 'eng';
}

/**
 * A post's language for the feed and the player: the site's full tag
 * (en-us, pt-br) when the post is in the site's language, else its code.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function rrt_post_lang_tag( $post_id ) {
	$code = rrt_post_lang( $post_id );
	$site = strtolower( str_replace( '_', '-', get_bloginfo( 'language' ) ) );
	if ( rrt_lang_from_tag( $site ) === $code ) {
		return $site;
	}
	return rrt_lang_attr( $code );
}

/**
 * What the studio needs to know.
 *
 * @param int $post_id Post ID; 0 for the practice reading.
 * @return array
 */
function rrt_lang_boot( $post_id ) {
	$code  = rrt_post_lang( $post_id );
	$names = rrt_languages();
	return array(
		'code' => $code,
		'name' => $names[ $code ],
		'tag'  => rrt_lang_attr( $code ),
		'en'   => 'en' === $code,
	);
}

/* The Reading language menu in the Reading audio box, after the kind of reading. */
add_action(
	'rrt_reading_box_lang',
	function ( $post ) {
		$mine  = rrt_get_post_lang( $post->ID );
		$names = rrt_languages();
		$site  = rrt_site_lang();
		echo '<p class="rrt-box-field"><label for="rrt_lang"><strong>' . esc_html__( 'Reading language', 'foxfire-studio' ) . '</strong></label><br><select class="widefat" id="rrt_lang" name="rrt_lang">';
		echo '<option value=""' . selected( '', $mine, false ) . '>' . esc_html( sprintf( /* translators: %s: language name. */ __( 'Same as the site (%s)', 'foxfire-studio' ), $names[ $site ] ) ) . '</option>';
		foreach ( $names as $code => $name ) {
			echo '<option value="' . esc_attr( $code ) . '"' . selected( $code, $mine, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select></p>';
		echo '<p class="description">' . esc_html( sprintf( /* translators: %s: a list of language names. */ __( 'Whisper hears this language when it reviews a take. Not offered yet: %s, which are written without spaces between words.', 'foxfire-studio' ), implode( ', ', rrt_languages_later() ) ) ) . '</p>';
	}
);

add_action(
	'save_post',
	function ( $post_id ) {
		if ( ! isset( $_POST['rrt_reading_nonce'], $_POST['rrt_lang'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rrt_reading_nonce'] ) ), 'rrt_reading_box' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		rrt_save_post_lang( $post_id, sanitize_key( wp_unslash( $_POST['rrt_lang'] ) ) );
	}
);
