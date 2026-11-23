<?php
/**
 * The pronunciation list (1.15.0), run inside the test WordPress:
 *   php wp-cli.phar --path=<wordpress> eval-file tests/php/pronounce.php
 * Taught words that stay forgotten, change-only saves of notes and cue marks,
 * and the Pronunciation screen's rows, changes, suggestions, backup and
 * import. Prints one line per check and exits 1 if any fail. Leaves nothing behind.
 */

$GLOBALS['pn_pass'] = 0;
$GLOBALS['pn_fail'] = array();

function pn_t( $ok, $name ) {
	if ( $ok ) {
		++$GLOBALS['pn_pass'];
		echo "ok   $name\n";
	} else {
		$GLOBALS['pn_fail'][] = $name;
		echo "FAIL $name\n";
	}
}

/* One REST request as the current user. */
function pn_rest( $method, $route, $body = array() ) {
	$req = new WP_REST_Request( $method, $route );
	if ( $body ) {
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( wp_json_encode( $body ) );
	}
	$res = rest_do_request( $req );
	return array( $res->get_status(), $res->get_data() );
}

/* The row with this id, or null. */
function pn_row( $rows, $id ) {
	foreach ( $rows as $r ) {
		if ( $r['id'] === $id ) {
			return $r;
		}
	}
	return null;
}

wp_set_current_user( 1 );
$pn_was = array(
	'notes'   => get_user_meta( 1, 'rrt_notes', true ),
	'learned' => get_user_meta( 1, 'rrt_learned', true ),
	'skip'    => get_user_meta( 1, RRT_PRON_SKIP, true ),
);
register_shutdown_function(
	function () use ( $pn_was ) {
		foreach ( array( 'rrt_notes' => 'notes', 'rrt_learned' => 'learned', RRT_PRON_SKIP => 'skip' ) as $k => $v ) {
			if ( $pn_was[ $v ] ) {
				update_user_meta( 1, $k, $pn_was[ $v ] );
			} else {
				delete_user_meta( 1, $k );
			}
		}
		delete_transient( RRT_PRON_INDEX );
	}
);
delete_user_meta( 1, 'rrt_notes' );
delete_user_meta( 1, 'rrt_learned' );
delete_user_meta( 1, RRT_PRON_SKIP );

$pn_a = wp_insert_post(
	array(
		'post_title'   => 'Pronounce test A',
		'post_name'    => 'pronounce-test-a',
		'post_status'  => 'publish',
		'post_content' => '<p>Lachesis spins the thread. Then Lachesis measures it, and Atropos cuts.</p><p>We light the candle for Brigid tonight, and the unconventionality of it all.</p>',
	)
);
$pn_b = wp_insert_post(
	array(
		'post_title'   => 'Pronounce test B',
		'post_name'    => 'pronounce-test-b',
		'post_status'  => 'publish',
		'post_content' => '<p>Ask Brigid for patience. The wind is cold.</p><p>Under the Moon we gather<br>Magic in our hands<br>and Morwenna sings of magic.</p>',
	)
);
update_post_meta( $pn_b, 'rrt_audio', 'http://localhost/wp-content/uploads/b.mp3' );

/* ---- Taught words that stay forgotten ---- */
$l0 = rrt_clean_learned(
	array(
		'pairs'  => array( 'siobhan' => array( 'shivon' => true ) ),
		'ignore' => array( 'um' => true ),
		'labels' => array(
			'siobhan|shivon' => '"Siobhan" heard as "shivon"',
			'+um'            => '"um" (ignored)',
		),
	)
);
$forgot = rrt_learned_forget( $l0, 'siobhan|shivon' );
pn_t( ! isset( $forgot['pairs']['siobhan'] ) && ! isset( $forgot['labels']['siobhan|shivon'] ) && isset( $forgot['forgot']['siobhan|shivon'] ), 'forgetting takes the pair and its label out and records the time' );
$back = rrt_merge_learned( $forgot, $l0 );
pn_t( ! isset( $back['pairs']['siobhan'] ), 'an old copy without times cannot bring a forgotten word back' );
pn_t( isset( $back['ignore']['um'] ), 'the other words stay' );
$retaught                          = $l0;
$retaught['at']['siobhan|shivon'] = $forgot['forgot']['siobhan|shivon'] + 5;
pn_t( isset( rrt_merge_learned( $forgot, $retaught )['pairs']['siobhan']['shivon'] ), 'a word taught again after it was forgotten comes back' );
pn_t( isset( rrt_merge_learned( $retaught, $forgot )['pairs']['siobhan']['shivon'] ), 'the merge gives the same answer either way round' );
$old           = rrt_clean_learned( array( 'forgot' => array( 'x|y' => ( time() - 500 * DAY_IN_SECONDS ) * 1000, 'a|b' => time() * 1000 ) ) );
pn_t( ! isset( $old['forgot']['x|y'] ) && isset( $old['forgot']['a|b'] ), 'forgotten words older than 400 days are let go' );

/* The REST route merges, and an old tab's replace records what it left out as forgotten. */
update_user_meta( 1, 'rrt_learned', $l0 );
list( $st, $d ) = pn_rest( 'POST', '/rrt/v1/learned', array( 'learned' => $l0 ) );
pn_t( 200 === $st && isset( $d['learned']['pairs']['siobhan'] ), 'a set sent from a browser is merged' );
list( $st, $d ) = pn_rest(
	'POST',
	'/rrt/v1/learned',
	array(
		'learned' => array(
			'pairs'  => array(),
			'ignore' => array( 'um' => true ),
			'labels' => array( '+um' => '"um" (ignored)' ),
		),
		'replace' => true,
	)
);
pn_t( 200 === $st && ! isset( $d['learned']['pairs']['siobhan'] ) && isset( $d['learned']['forgot']['siobhan|shivon'] ), 'an old tab forgetting with replace records the word as forgotten' );
list( $st, $d ) = pn_rest( 'POST', '/rrt/v1/learned', array( 'learned' => $l0 ) );
pn_t( ! isset( $d['learned']['pairs']['siobhan'] ), 'another browser sending its old copy afterwards does not bring it back' );

/* A post's taught words keep their forgotten record too. */
rrt_save_post_learned( $pn_a, rrt_learned_forget( $l0, '+um' ), false );
$pl = rrt_get_post_learned( $pn_a );
pn_t( isset( $pl['forgot']['+um'] ) && ! isset( $pl['ignore']['um'] ), 'a post keeps its forgotten words' );
rrt_save_post_learned( $pn_a, $l0, false );
pn_t( ! isset( rrt_get_post_learned( $pn_a )['ignore']['um'] ), 'a pending copy sent later does not bring it back to the post' );
delete_post_meta( $pn_a, RRT_LEARNED_POST_META );
delete_user_meta( 1, 'rrt_learned' );

/* ---- Change-only saves ---- */
rrt_save_notes( 'post', $pn_a, array( array( 'k' => 'brigid', 'n' => 'BRIDJ-id' ) ) );
list( $st, $d ) = pn_rest( 'POST', '/rrt/v1/notes', array( 'post' => $pn_a, 'scope' => 'post', 'changes' => array( 'lachesis' => 'LACK-eh-sis' ) ) );
$keys           = wp_list_pluck( rrt_get_post_notes( $pn_a ), 'n', 'k' );
pn_t( 200 === $st && 'BRIDJ-id' === $keys['brigid'] && 'LACK-eh-sis' === $keys['lachesis'], 'a note change keeps the notes the studio did not touch' );
pn_rest( 'POST', '/rrt/v1/notes', array( 'post' => $pn_a, 'scope' => 'post', 'changes' => array( 'brigid' => '' ) ) );
pn_t( array( 'lachesis' ) === wp_list_pluck( rrt_get_post_notes( $pn_a ), 'k' ), 'an empty note in the changes removes that word only' );
pn_rest( 'POST', '/rrt/v1/notes', array( 'post' => $pn_a, 'scope' => 'post', 'notes' => array( array( 'k' => 'atropos', 'n' => 'AT-ro-pos' ) ) ) );
pn_t( array( 'atropos' ) === wp_list_pluck( rrt_get_post_notes( $pn_a ), 'k' ), 'a whole list from an older studio still saves as before' );
delete_post_meta( $pn_a, RRT_NOTES_META );

rrt_save_cues(
	$pn_a,
	array(
		array( 'i' => 3, 'k' => 'lachesis', 'a' => 'pause', 'e' => false, 'f' => false, 'n' => '' ),
		array( 'i' => 12, 'k' => 'atropos', 'a' => '', 'e' => true, 'f' => false, 'n' => 'AT-ro-pos' ),
	)
);
list( $st, $d ) = pn_rest(
	'POST',
	'/rrt/v1/cues',
	array(
		'post'    => $pn_a,
		'changes' => array(
			array( 'i' => 3, 'cue' => null ),
			array( 'i' => 6, 'cue' => array( 'k' => 'thread', 'a' => 'breath', 'e' => false, 'f' => false, 'n' => '' ) ),
		),
	)
);
$cues           = rrt_get_cues( $pn_a );
pn_t( 200 === $st && array( 6, 12 ) === wp_list_pluck( $cues, 'i' ) && 'AT-ro-pos' === $cues[1]['n'], 'cue changes keep the marks the studio did not touch' );

/* ---- The screen's rows ---- */
rrt_save_notes( 'all', 0, array( array( 'k' => 'brigid', 'n' => 'BRIDJ-id' ) ) );
rrt_save_notes( 'post', $pn_a, array( array( 'k' => 'lachesis', 'n' => 'LACK-eh-sis' ) ) );
update_user_meta(
	1,
	'rrt_learned',
	rrt_clean_learned(
		array(
			'pairs'  => array( 'brigid' => array( 'bridget' => true ) ),
			'labels' => array( 'brigid|bridget' => '"Brigid" heard as "bridget"' ),
		)
	)
);
delete_transient( RRT_PRON_INDEX );
$st   = rrt_pron_state();
$rows = $st['rows'];
$r    = pn_row( $rows, 'all:0:brigid' );
pn_t( $r && 'BRIDJ-id' === $r['note'] && 'bridget' === $r['taught'][0]['heard'], 'a word with a note and a taught word shares one row' );
pn_t( $r && 'Brigid' === $r['word'] && $r['count'] >= 2, 'the row shows the word as written and how many posts have it' );
$r = pn_row( $rows, 'post:' . $pn_a . ':lachesis' );
pn_t( $r && 'Pronounce test A' === $r['title'] && false !== strpos( $r['open'], 'tp_post=' . $pn_a ), 'a post note names its post and links to the studio' );
pn_t( $pn_a === pn_row( $rows, 'all:0:brigid' )['first'] || $pn_b === pn_row( $rows, 'all:0:brigid' )['first'], 'a row knows the first post with its word, for the post menu' );
$r = pn_row( $rows, 'here:' . $pn_a . ':12' );
pn_t( $r && 'AT-ro-pos' === $r['note'] && 'Atropos' === $r['word'] && 2 === $r['para'] && ! $r['moved'], 'a note on one word only shows its word and paragraph (the title is paragraph 1)' );
pn_t( null === pn_row( $rows, 'here:' . $pn_a . ':6' ), 'a cue mark with no note is not on the list' );

/* Suggestions: names and long words with no note, posts still to read first. */
$sug = wp_list_pluck( $st['suggest']['list'], 'k' );
pn_t( in_array( 'atropos', $sug, true ) === false, 'a word with a note only here is not suggested' );
pn_t( ! in_array( 'brigid', $sug, true ) && ! in_array( 'lachesis', $sug, true ), 'words with notes are not suggested' );
pn_t( in_array( 'unconventionality', $sug, true ), 'a word of five syllables or more is suggested' );
pn_t( in_array( 'morwenna', $sug, true ), 'a name inside a line of verse is suggested' );
pn_t( ! in_array( 'magic', $sug, true ), 'a capital at the start of a verse line is not taken for a name' );
$pn_c = wp_insert_post( array( 'post_title' => 'Pronounce test C', 'post_status' => 'publish', 'post_content' => '<p>We speak of Mother Earth. My mother laughs. Her new book, Where Witches Gather, is out, after The Art of Ritual. Where are you? Then Fiona Horne spoke, and Horne smiled.</p>' ) );
delete_transient( RRT_PRON_INDEX );
$pn_s = wp_list_pluck( rrt_pron_suggest( 1, array() )['list'], 'k' );
pn_t( ! in_array( 'mother', $pn_s, true ), 'a word also written in lower case is not suggested as a name' );
pn_t( ! in_array( 'gather', $pn_s, true ) && ! in_array( 'art', $pn_s, true ), 'words of a title in capitals are not taken for names' );
pn_t( in_array( 'horne', $pn_s, true ) && in_array( 'fiona', $pn_s, true ), 'a person’s name of two words still is' );
$pn_run = rrt_pron_caps_runs( 'Later The Art of Ritual, and' );
pn_t( 1 === count( $pn_run ) && 5 === $pn_run[0]['len'] && array( 'the', 'art', 'ritual' ) === $pn_run[0]['mid'], 'a run counts its joining words, and the words inside a sentence' );

/* How hard on Readings to make (1.15.1), while test C has "My mother" and the title "Where Witches Gather". */
/* How hard on Readings to make (1.15.1) follows the same rules for names. */
$pn_line = str_repeat( 'and the wind goes over the hill ', 3 );
$pn_d    = wp_insert_post( array( 'post_title' => 'Pronounce test D', 'post_status' => 'publish', 'post_content' => '<p>Magic walks the meadow<br>Mother keeps the flame<br>Where Witches Gather sings<br>And Morwenna calls my name<br>Unconventionality ' . $pn_line . '<br>' . $pn_line . '<br>' . $pn_line . '</p>' ) );
delete_transient( RRT_PRON_INDEX );
function pn_hard( $post ) {
	foreach ( rrt_backlog_rows( 1 )['rows'] as $r ) {
		if ( $r['post'] === $post ) {
			return $r['measure'];
		}
	}
	return null;
}
$pn_m = pn_hard( $pn_d );
pn_t( $pn_m && ! array_intersect( array( 'Magic', 'Mother', 'Gather' ), $pn_m['names'] ), 'How hard: line capitals, lower-case words and title words are not names' );
pn_t( $pn_m && in_array( 'Morwenna', $pn_m['names'], true ), 'How hard: a real name in a verse line still counts' );
pn_t( $pn_m && 0 === $pn_m['long'], 'How hard: the end of a verse line is a place to breathe, so no long sentence' );
pn_t( isset( rrt_pron_index()['notname']['mother'] ) && ! isset( rrt_pron_index()['notname']['morwenna'] ), 'the index keeps the words that are never names' );
update_user_meta( 1, RRT_PRON_SKIP, array( 'morwenna' ) );
$pn_m2 = pn_hard( $pn_d );
pn_t( $pn_m2 && ! in_array( 'Morwenna', $pn_m2['names'], true ) && $pn_m2['score'] < $pn_m['score'], 'How hard: a word marked Not needed is not a name' );
delete_user_meta( 1, RRT_PRON_SKIP );
wp_delete_post( $pn_d, true );
wp_delete_post( $pn_c, true );
delete_transient( RRT_PRON_INDEX );
list( $st2, $d ) = pn_rest( 'POST', '/rrt/v1/pronounce', array( 'do' => 'skip', 'key' => 'unconventionality' ) );
pn_t( 200 === $st2 && ! in_array( 'unconventionality', wp_list_pluck( $d['suggest']['list'], 'k' ), true ), 'Not needed takes a word off the suggestions' );
pn_rest( 'POST', '/rrt/v1/pronounce', array( 'do' => 'unskip', 'key' => 'unconventionality' ) );
pn_t( ! get_user_meta( 1, RRT_PRON_SKIP, true ), 'bringing the last one back removes the list' );

/* ---- Changes from the screen ---- */
list( $s, $d ) = pn_rest( 'POST', '/rrt/v1/pronounce', array( 'do' => 'note', 'place' => array( 'where' => 'all', 'key' => 'brigid' ), 'note' => 'BREED' ) );
pn_t( 200 === $s && 'BREED' === pn_row( $d['rows'], 'all:0:brigid' )['note'], 'a note changed on the screen is saved' );
list( $s, $d ) = pn_rest( 'POST', '/rrt/v1/pronounce', array( 'do' => 'note', 'place' => array( 'where' => 'here', 'post' => $pn_a, 'i' => 12, 'wkey' => 'atropos' ), 'note' => 'AT-ruh-pos' ) );
pn_t( 200 === $s && 'AT-ruh-pos' === rrt_get_cues( $pn_a )[1]['n'] && rrt_get_cues( $pn_a )[1]['e'], 'a note on one word only is changed and its emphasis kept' );
list( $s, $d ) = pn_rest( 'POST', '/rrt/v1/pronounce', array( 'do' => 'note', 'place' => array( 'where' => 'here', 'post' => $pn_a, 'i' => 3, 'wkey' => 'atropos' ), 'note' => 'X' ) );
pn_t( 409 === $s, 'a note on one word only that is not there any more is refused' );
list( $s, $d ) = pn_rest( 'POST', '/rrt/v1/pronounce', array( 'do' => 'add', 'word' => 'Patience,', 'note' => 'PAY-shunce', 'where' => 'post', 'post' => $pn_b ) );
pn_t( 200 === $s && pn_row( $d['rows'], 'post:' . $pn_b . ':patience' ), 'Add a note cleans the word and saves it for one post' );
list( $s, $d ) = pn_rest( 'POST', '/rrt/v1/pronounce', array( 'do' => 'add', 'word' => 'patience', 'note' => 'other', 'where' => 'post', 'post' => $pn_b ) );
pn_t( 409 === $s && 'PAY-shunce' === $d['data']['clash']['to'], 'adding over a different note asks first' );
list( $s, $d ) = pn_rest( 'POST', '/rrt/v1/pronounce', array( 'do' => 'add', 'word' => 'patience', 'note' => 'other', 'where' => 'post', 'post' => $pn_b, 'keep' => 'replace' ) );
pn_t( 'other' === pn_row( $d['rows'], 'post:' . $pn_b . ':patience' )['note'], 'and replaces it when asked' );

/* Move: every post to one post, with its taught words. */
list( $s, $d ) = pn_rest( 'POST', '/rrt/v1/pronounce', array( 'do' => 'move', 'from' => array( 'where' => 'all', 'key' => 'brigid' ), 'to' => array( 'where' => 'post', 'post' => $pn_b ) ) );
$r             = pn_row( $d['rows'], 'post:' . $pn_b . ':brigid' );
pn_t( 200 === $s && $r && 'BREED' === $r['note'] && 'bridget' === $r['taught'][0]['heard'], 'moving a word to one post takes its note and taught words' );
pn_t( null === pn_row( $d['rows'], 'all:0:brigid' ), 'and it leaves every post' );
$ul = rrt_clean_learned( rrt_get_learned( 1 ) );
pn_t( isset( $ul['forgot']['brigid|bridget'] ), 'the taught word is recorded as forgotten in your own words, so browsers drop it' );
/* Move back with a clash. */
rrt_save_notes( 'all', 0, array( array( 'k' => 'brigid', 'n' => 'BRIJ' ) ) );
list( $s, $d ) = pn_rest( 'POST', '/rrt/v1/pronounce', array( 'do' => 'move', 'from' => array( 'where' => 'post', 'post' => $pn_b, 'key' => 'brigid' ), 'to' => array( 'where' => 'all' ) ) );
pn_t( 409 === $s && 'BREED' === $d['data']['clash']['from'] && 'BRIJ' === $d['data']['clash']['to'], 'moving onto a different note asks which to keep' );
pn_t( 'BREED' === rrt_pron_note_at( 'post', $pn_b, 'brigid' ), 'and changes nothing until it is answered' );
list( $s, $d ) = pn_rest( 'POST', '/rrt/v1/pronounce', array( 'do' => 'move', 'from' => array( 'where' => 'post', 'post' => $pn_b, 'key' => 'brigid' ), 'to' => array( 'where' => 'all' ), 'keep' => 'to' ) );
$r             = pn_row( $d['rows'], 'all:0:brigid' );
pn_t( 200 === $s && 'BRIJ' === $r['note'] && 'bridget' === $r['taught'][0]['heard'] && null === pn_row( $d['rows'], 'post:' . $pn_b . ':brigid' ), 'keeping the other note still moves the taught words' );
/* Move: only here to every use in the post. */
list( $s, $d ) = pn_rest( 'POST', '/rrt/v1/pronounce', array( 'do' => 'move', 'from' => array( 'where' => 'here', 'post' => $pn_a, 'i' => 12, 'wkey' => 'atropos' ), 'to' => array( 'where' => 'post', 'post' => $pn_a ) ) );
pn_t( 200 === $s && 'AT-ruh-pos' === rrt_pron_note_at( 'post', $pn_a, 'atropos' ) && '' === rrt_get_cues( $pn_a )[1]['n'] && rrt_get_cues( $pn_a )[1]['e'], 'a note on one word moves to every use in its post, and the emphasis stays' );
/* Forget. */
list( $s, $d ) = pn_rest( 'POST', '/rrt/v1/pronounce', array( 'do' => 'forget', 'place' => array( 'where' => 'all', 'key' => 'brigid' ), 'entry' => 'brigid|bridget' ) );
pn_t( 200 === $s && array() === pn_row( $d['rows'], 'all:0:brigid' )['taught'], 'Forget takes a taught word off' );

/* Someone who cannot edit a post cannot change its notes. */
$pn_ed = wp_insert_user( array( 'user_login' => 'pn_author', 'user_pass' => wp_generate_password(), 'role' => 'author' ) );
wp_set_current_user( $pn_ed );
list( $s ) = pn_rest( 'POST', '/rrt/v1/pronounce', array( 'do' => 'note', 'place' => array( 'where' => 'post', 'post' => $pn_a, 'key' => 'lachesis' ), 'note' => 'no' ) );
pn_t( 403 === $s && 'LACK-eh-sis' === rrt_pron_note_at( 'post', $pn_a, 'lachesis' ), 'an author cannot change notes on another person’s post' );
$st = rrt_pron_state();
pn_t( null === pn_row( $st['rows'], 'post:' . $pn_a . ':lachesis' ), 'and does not see them' );
wp_set_current_user( 0 );
list( $s ) = pn_rest( 'GET', '/rrt/v1/pronounce' );
pn_t( 401 === $s, 'the list needs a login' );
wp_set_current_user( 1 );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $pn_ed );

/* ---- Backup and import ---- */
list( $s, $bk ) = pn_rest( 'GET', '/rrt/v1/pronounce/backup' );
pn_t( 200 === $s && RRT_PRON_FORMAT === $bk['format'] && count( $bk['posts'] ) >= 2, 'the backup holds your words and each post’s' );
$json = wp_json_encode( $bk );
pn_t( false === strpos( $json, 'rrt_audio' ) && false === strpos( $json, 'b.mp3' ), 'the backup holds nothing but words and notes' );
/* Empty everything, then import the backup back. */
delete_user_meta( 1, 'rrt_notes' );
delete_user_meta( 1, 'rrt_learned' );
delete_post_meta( $pn_a, RRT_NOTES_META );
delete_post_meta( $pn_b, RRT_NOTES_META );
update_post_meta( $pn_a, RRT_CUES_META, array() );
rrt_save_notes( 'post', $pn_b, array( array( 'k' => 'patience', 'n' => 'PATCH' ) ) );
list( $s, $d ) = pn_rest( 'POST', '/rrt/v1/pronounce', array( 'do' => 'import', 'data' => json_decode( $json, true ), 'apply' => false ) );
pn_t( 200 === $s && $d['import']['add'] >= 3 && 1 === count( $d['import']['clashes'] ) && 'PATCH' === $d['import']['clashes'][0]['mine'], 'the preview counts what it would add and lists the clash' );
pn_t( '' === rrt_pron_note_at( 'post', $pn_a, 'lachesis' ), 'the preview changes nothing' );
$cid           = $d['import']['clashes'][0]['id'];
list( $s, $d ) = pn_rest( 'POST', '/rrt/v1/pronounce', array( 'do' => 'import', 'data' => json_decode( $json, true ), 'apply' => true ) );
pn_t( 'LACK-eh-sis' === rrt_pron_note_at( 'post', $pn_a, 'lachesis' ) && 'BRIJ' === rrt_pron_note_at( 'all', 0, 'brigid' ), 'import puts the notes back' );
pn_t( 'PATCH' === rrt_pron_note_at( 'post', $pn_b, 'patience' ), 'a clash with no choice keeps what is here' );
pn_t( isset( rrt_clean_learned( rrt_get_learned( 1 ) )['forgot']['brigid|bridget'] ) && ! isset( rrt_clean_learned( rrt_get_learned( 1 ) )['pairs']['brigid'] ), 'a word forgotten before the backup stays forgotten' );
pn_rest( 'POST', '/rrt/v1/pronounce', array( 'do' => 'import', 'data' => json_decode( $json, true ), 'apply' => true, 'keep' => array( $cid => 'file' ) ) );
pn_t( 'other' === rrt_pron_note_at( 'post', $pn_b, 'patience' ), 'choosing the file’s note replaces it' );
$other         = json_decode( $json, true );
$other['site'] = 'https://elsewhere.example/';
foreach ( $other['posts'] as &$pn_p ) {
	$pn_p['id'] = 999999;
}
unset( $pn_p );
$other['posts'][] = array( 'id' => 5, 'slug' => 'not-here', 'title' => 'Missing post', 'notes' => array( array( 'k' => 'x', 'n' => 'y' ) ) );
list( $s, $d )    = pn_rest( 'POST', '/rrt/v1/pronounce', array( 'do' => 'import', 'data' => $other, 'apply' => false ) );
pn_t( 200 === $s && array( 'Missing post' ) === $d['import']['skipped'], 'a backup from another site finds posts by slug and leaves out the rest' );
list( $s ) = pn_rest( 'POST', '/rrt/v1/pronounce', array( 'do' => 'import', 'data' => array( 'format' => 'something' ), 'apply' => true ) );
pn_t( 400 === $s, 'a file that is not a backup is refused' );

/* The index is made again when a post changes. */
rrt_pron_index();
wp_update_post( array( 'ID' => $pn_b, 'post_content' => '<p>Ask Morrigan for patience.</p>' ) );
pn_t( false === get_transient( RRT_PRON_INDEX ), 'saving a post clears the word index' );
pn_t( isset( rrt_pron_index()['words']['morrigan'] ), 'and the new index has the new word' );

wp_delete_post( $pn_a, true );
wp_delete_post( $pn_b, true );

echo "\n" . $GLOBALS['pn_pass'] . ' passed, ' . count( $GLOBALS['pn_fail'] ) . " failed\n";
if ( $GLOBALS['pn_fail'] ) {
	exit( 1 );
}
