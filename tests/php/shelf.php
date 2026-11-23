<?php
/**
 * The take shelf across computers (1.34.0), run inside the test WordPress:
 *   php wp-cli.phar --path=<wordpress> eval-file tests/php/shelf.php
 * Off by default and refused while off; a take started with its words, its
 * recording added in pieces (in order, twice, early, too large, past its
 * size), finished only when whole and a FLAC; the list with its words and the
 * site's numbers; the same take started twice; only its owner can list,
 * send or delete it (fetching is in tests/browser/shelf.py); the newest six a post and 30 days; a send given
 * up; the plan saved and cleaned; the space limit; the folder refusing
 * direct requests and its random name; Delete every shelf; export and erase;
 * the hourly job. Prints one line per check and exits 1 if any fail. Leaves
 * nothing behind.
 */

$GLOBALS['ts_pass'] = 0;
$GLOBALS['ts_fail'] = array();

function ts_t( $ok, $name, $detail = '' ) {
	if ( $ok ) {
		++$GLOBALS['ts_pass'];
		echo "ok   $name\n";
	} else {
		$GLOBALS['ts_fail'][] = $name;
		echo "FAIL $name" . ( '' !== $detail ? ": $detail" : '' ) . "\n";
	}
}

function ts_req( $method, $route, $params = array(), $body = null, $json = null ) {
	$r = new WP_REST_Request( $method, '/rrt/v1/' . $route );
	foreach ( $params as $k => $v ) {
		$r->set_param( $k, $v );
	}
	if ( null !== $json ) {
		$r->set_body( wp_json_encode( $json ) );
		$r->set_header( 'Content-Type', 'application/json' );
	} elseif ( null !== $body ) {
		$r->set_body( $body );
		$r->set_header( 'Content-Type', 'application/octet-stream' );
	}
	$res = rest_do_request( $r );
	return array( $res->get_status(), $res->get_data() );
}

/* A made-up take as the browser sends it. */
function ts_take( $id, $at_ms, $extra = array() ) {
	return array_merge(
		array(
			'id'       => $id,
			'at'       => $at_ms,
			'seconds'  => 12.5,
			'rate'     => 44100,
			'sig'      => 'abc123-40',
			'fp'       => array( 'n' => 551250, 'rate' => 44100, 'p' => array_fill( 0, 64, -30.5 ) ),
			'level'    => -24.2,
			'words'    => array( array( 0.5, 0.9, 'ok' ), null, array( 1.2, 1.6, 'miss' ) ),
			'problems' => array( array( 'kind' => 'miss', 'idx' => 2, 's' => 1.2, 'label' => 'Missed <b>word</b>', 'detail' => 'two' ) ),
			'chunks'   => array( array( 'text' => ' one', 'timestamp' => array( 0.5, 0.9 ) ) ),
			'accuracy' => 0.91,
			'wpm'      => 140,
			'mode'     => '',
		),
		$extra
	);
}

/* A FLAC-looking body of n bytes. */
function ts_flac( $n ) {
	return 'fLaC' . str_repeat( "\x07", $n - 4 );
}

wp_set_current_user( 1 );
$ts_user   = 1;
$ts_was    = get_option( 'rrt_shelf', null );
$ts_meta   = get_user_meta( $ts_user, '_rrt_shelf', true );
$ts_editor = get_user_by( 'login', 'editor' );
$ts_author = get_user_by( 'id', wp_insert_user( array( 'user_login' => 'shelf_author', 'user_pass' => wp_generate_password(), 'user_email' => 'shelf-author@example.org', 'role' => 'author' ) ) );
delete_user_meta( $ts_user, '_rrt_shelf' );
$ts_post  = wp_insert_post( array( 'post_title' => 'Shelf test', 'post_status' => 'publish', 'post_content' => '<p>One two three.</p>' ) );
$ts_post2 = wp_insert_post( array( 'post_title' => 'Shelf test two', 'post_status' => 'publish', 'post_content' => '<p>Four five six.</p>' ) );
/* A post the author cannot edit: the admin's own private post. */
$ts_priv = wp_insert_post( array( 'post_title' => 'Shelf private', 'post_status' => 'private', 'post_author' => 1, 'post_content' => '<p>Seven.</p>' ) );
register_shutdown_function(
	function () use ( $ts_user, $ts_was, $ts_meta, $ts_post, $ts_post2, $ts_priv, $ts_editor, $ts_author ) {
		rrt_shelf_clear( $ts_user, 0 );
		if ( $ts_editor ) {
			rrt_shelf_clear( $ts_editor->ID, 0 );
		}
		if ( $ts_author ) {
			rrt_shelf_clear( $ts_author->ID, 0 );
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $ts_author->ID );
		}
		if ( $ts_meta ) {
			update_user_meta( $ts_user, '_rrt_shelf', $ts_meta );
		}
		if ( null === $ts_was ) {
			delete_option( 'rrt_shelf' );
		} else {
			update_option( 'rrt_shelf', $ts_was );
		}
		wp_delete_post( $ts_post, true );
		wp_delete_post( $ts_post2, true );
		wp_delete_post( $ts_priv, true );
		echo "\n" . $GLOBALS['ts_pass'] . ' passed, ' . count( $GLOBALS['ts_fail'] ) . " failed\n";
		if ( $GLOBALS['ts_fail'] ) {
			exit( 1 );
		}
	}
);
$now = time();
$ms  = $now * 1000;

/* Off by default. */
delete_option( 'rrt_shelf' );
$s = rrt_shelf_settings();
ts_t( false === $s['on'] && 2048 === $s['limit'], 'off by default, with a 2 GB limit', wp_json_encode( $s ) );
ts_t( null === rrt_shelf_boot( $ts_post ), 'the studio gets nothing while it is off' );
ts_t( 403 === ts_req( 'GET', 'shelf', array( 'post' => $ts_post ) )[0] || 401 === ts_req( 'GET', 'shelf', array( 'post' => $ts_post ) )[0], 'the list is refused while it is off' );
ts_t( in_array( ts_req( 'POST', 'shelf/take', array( 'post' => $ts_post ), null, array( 'take' => ts_take( 'toffoff1', $ms ), 'bytes' => 100, 'sum' => str_repeat( 'a', 16 ) . '-10' ) )[0], array( 401, 403 ), true ), 'a send is refused while it is off' );

update_option( 'rrt_shelf', array( 'on' => true, 'limit' => 2048 ) );
$boot = rrt_shelf_boot( $ts_post );
ts_t( is_array( $boot ) && false !== strpos( $boot['worker'], 'flac.js' ) && $boot['piece'] >= 262144 && $boot['piece'] <= 4 * 1048576 && $ts_post === $boot['post'], 'once on, the studio gets where to send, the worker and the piece size', wp_json_encode( $boot ) );
ts_t( null === rrt_shelf_boot( 0 ), 'the practice reading (no post) gets nothing' );

/* Start a take. */
$sum            = '0123456789abcdef-551250';
list( $st, $d ) = ts_req( 'POST', 'shelf/take', array( 'post' => $ts_post ), null, array( 'take' => ts_take( 'tone00001', $ms - 5000 ), 'bytes' => 3000, 'sum' => $sum ) );
ts_t( 200 === $st && 'tone00001' === ( $d['take']['id'] ?? '' ) && 1 === $d['take']['n'] && 'up' === $d['take']['state'] && 0 === $d['take']['have'], 'a take is started with its words, as take 1', $st . ' ' . wp_json_encode( $d ) );
ts_t( file_exists( rrt_shelf_file( 1, 'tone00001', 'json' ) ) && 0 === filesize( rrt_shelf_file( 1, 'tone00001', 'flac' ) ), 'its words are a file, and its recording an empty one so far' );
$dir = rrt_shelf_dir();
ts_t( (bool) preg_match( '#/rrt-shelf/[a-z0-9]{20}/$#', $dir ) && file_exists( $dir . '.htaccess' ) && false !== strpos( (string) file_get_contents( rrt_shelf_top() . '.htaccess' ), 'Require all denied' ), 'the folder has a random name and refuses direct requests', $dir );
ts_t( in_array( 'rrt-shelf', rrt_private_folders(), true ) && isset( rrt_private_check_folders()['rrt-shelf'] ), 'it is one of the private folders, and Check private folders tries it' );

/* The same take again (a send cut off, or a second tab). */
list( $st, $d ) = ts_req( 'POST', 'shelf/take', array( 'post' => $ts_post ), null, array( 'take' => ts_take( 'tone00001', $ms - 5000 ), 'bytes' => 3000, 'sum' => $sum ) );
ts_t( 200 === $st && 1 === count( rrt_shelf_raw( 1 ) ), 'starting the same take twice keeps it once' );

/* Refused starts. */
ts_t( 400 === ts_req( 'POST', 'shelf/take', array( 'post' => $ts_post ), null, array( 'take' => ts_take( 'BAD!', $ms ), 'bytes' => 3000, 'sum' => $sum ) )[0], 'an id the browser would not make is refused' );
ts_t( 400 === ts_req( 'POST', 'shelf/take', array( 'post' => $ts_post ), null, array( 'take' => ts_take( 'toldtake1', $ms - 31 * DAY_IN_SECONDS * 1000 ), 'bytes' => 3000, 'sum' => $sum ) )[0], 'a take older than 30 days is refused' );
ts_t( 400 === ts_req( 'POST', 'shelf/take', array( 'post' => $ts_post ), null, array( 'take' => ts_take( 'tnosum001', $ms ), 'bytes' => 3000, 'sum' => 'x' ) )[0], 'a take without a check of its samples is refused' );
ts_t( 400 === ts_req( 'POST', 'shelf/take', array( 'post' => $ts_post ), null, array( 'take' => ts_take( 'tnorate01', $ms, array( 'rate' => 5 ) ), 'bytes' => 3000, 'sum' => $sum ) )[0], 'a take with an impossible rate is refused' );
ts_t( 413 === ts_req( 'POST', 'shelf/take', array( 'post' => $ts_post ), null, array( 'take' => ts_take( 'thuge0001', $ms ), 'bytes' => RRT_SHELF_MAX + 1, 'sum' => $sum ) )[0], 'a recording larger than the site keeps is refused' );

/* Pieces. */
$body           = ts_flac( 3000 );
list( $st, $d ) = ts_req( 'POST', 'shelf/take/tone00001/piece', array( 'offset' => 0 ), substr( $body, 0, 1000 ) );
ts_t( 200 === $st && 1000 === $d['have'], 'the first piece is kept', $st . ' ' . wp_json_encode( $d ) );
list( $st, $d ) = ts_req( 'POST', 'shelf/take/tone00001/piece', array( 'offset' => 0 ), substr( $body, 0, 1000 ) );
ts_t( 200 === $st && 1000 === $d['have'], 'the same piece sent twice is kept once' );
list( $st, $d ) = ts_req( 'POST', 'shelf/take/tone00001/piece', array( 'offset' => 2000 ), substr( $body, 2000 ) );
ts_t( 409 === $st && 1000 === $d['have'], 'a piece that arrives early is refused with how much the site holds', $st . ' ' . wp_json_encode( $d ) );
list( $st, $d ) = ts_req( 'POST', 'shelf/take/tone00001/done' );
ts_t( 409 === $st && 1000 === $d['have'], 'a take is not finished while a piece is missing', $st . ' ' . wp_json_encode( $d ) );
list( $st, $d ) = ts_req( 'POST', 'shelf/take/tone00001/piece', array( 'offset' => 500 ), substr( $body, 500, 1500 ) );
ts_t( 200 === $st && 2000 === $d['have'], 'a piece that overlaps what is there adds only the rest' );
list( $st, $d ) = ts_req( 'POST', 'shelf/take/tone00001/piece', array( 'offset' => 2000 ), substr( $body, 2000 ) . 'extra' );
ts_t( 400 === $st, 'a piece past the recording’s size is refused' );
list( $st, $d ) = ts_req( 'POST', 'shelf/take/tone00001/piece', array( 'offset' => 2000 ), str_repeat( 'x', RRT_SHELF_PIECE_MAX + 1 ) );
ts_t( 413 === $st, 'a piece larger than the site takes is refused' );
list( $st, $d ) = ts_req( 'GET', 'shelf/take/tone00001/audio' );
ts_t( 410 === $st, 'a recording still being sent cannot be fetched' );
list( $st, $d ) = ts_req( 'GET', 'shelf', array( 'post' => $ts_post ) );
ts_t( 200 === $st && 1 === count( $d['takes'] ) && 'up' === $d['takes'][0]['state'] && 2000 === $d['takes'][0]['have'], 'the list shows a take still being sent, with how much is there, for the browser to carry on' );
ts_req( 'POST', 'shelf/take/tone00001/piece', array( 'offset' => 2000 ), substr( $body, 2000 ) );
list( $st, $d ) = ts_req( 'POST', 'shelf/take/tone00001/done' );
ts_t( 200 === $st && 'ready' === $d['take']['state'] && 3000 === $d['take']['have'], 'a whole recording finishes the take', $st . ' ' . wp_json_encode( $d ) );
ts_t( $body === file_get_contents( rrt_shelf_file( 1, 'tone00001', 'flac' ) ), 'the file is the recording, byte for byte' );
list( $st, $d ) = ts_req( 'POST', 'shelf/take/tone00001/piece', array( 'offset' => 0 ), 'xx' );
ts_t( 200 === $st && 3000 === $d['have'] && $body === file_get_contents( rrt_shelf_file( 1, 'tone00001', 'flac' ) ), 'a finished take is not changed by a late piece' );

/* A recording that is not FLAC is refused at the end, and deleted. */
ts_req( 'POST', 'shelf/take', array( 'post' => $ts_post ), null, array( 'take' => ts_take( 'twav00001', $ms - 4000 ), 'bytes' => 50, 'sum' => $sum ) );
ts_req( 'POST', 'shelf/take/twav00001/piece', array( 'offset' => 0 ), 'RIFF' . str_repeat( 'x', 46 ) );
list( $st, $d ) = ts_req( 'POST', 'shelf/take/twav00001/done' );
ts_t( 400 === $st && null === rrt_shelf_find( 'twav00001' ), 'a recording that is not FLAC is refused and deleted' );

/* The list. */
list( $st, $d ) = ts_req( 'GET', 'shelf', array( 'post' => $ts_post ) );
$t0             = $d['takes'][0] ?? array();
ts_t( 200 === $st && 1 === count( $d['takes'] ) && 'ready' === $t0['state'] && 'abc123-40' === $t0['sig'] && 3 === count( $t0['words'] ) && null === $t0['words'][1] && 'miss' === $t0['words'][2][2] && 64 === count( $t0['fp']['p'] ) && $sum === $t0['sum'] && 1 === $t0['n'] && true === $d['room'], 'the list gives each take with its words, flags, fingerprint, check and number', wp_json_encode( $t0 ) );
ts_t( 'Missed <b>word</b>' === $t0['problems'][0]['label'], 'a flag’s label comes back as it was typed (the studio shows it as text)' );
ts_t( 0 === count( ts_req( 'GET', 'shelf', array( 'post' => $ts_post2 ) )[1]['takes'] ), 'another post’s list is its own' );

$t = rrt_shelf_find( 'tone00001' );
ts_t( $t && 'ready' === $t['state'], 'the take can be found by its owner' );

/* Someone else. */
wp_set_current_user( $ts_editor->ID );
ts_t( 0 === count( ts_req( 'GET', 'shelf', array( 'post' => $ts_post ) )[1]['takes'] ), 'another person sees an empty shelf for the same post' );
ts_t( in_array( ts_req( 'GET', 'shelf/take/tone00001/audio' )[0], array( 401, 403 ), true ), 'another person cannot fetch the recording' );
ts_t( in_array( ts_req( 'POST', 'shelf/take/tone00001/piece', array( 'offset' => 3000 ), 'x' )[0], array( 401, 403 ), true ), 'another person cannot add to it' );
ts_t( in_array( ts_req( 'DELETE', 'shelf/take/tone00001' )[0], array( 401, 403 ), true ) && null !== rrt_shelf_find( 'tone00001', 1 ), 'another person cannot delete it' );
wp_set_current_user( $ts_author->ID );
ts_t( in_array( ts_req( 'GET', 'shelf', array( 'post' => $ts_priv ) )[0], array( 401, 403 ), true ) && in_array( ts_req( 'POST', 'shelf/take', array( 'post' => $ts_priv ), null, array( 'take' => ts_take( 'tpriv0001', $ms ), 'bytes' => 3000, 'sum' => $sum ) )[0], array( 401, 403 ), true ), 'a post the person cannot edit is refused' );
wp_set_current_user( $ts_editor->ID );
list( $st, $d ) = ts_req( 'POST', 'shelf/take', array( 'post' => $ts_post ), null, array( 'take' => ts_take( 'tone00001', $ms - 5000 ), 'bytes' => 3000, 'sum' => $sum ) );
ts_t( 200 === $st && 1 === $d['take']['n'] && null !== rrt_shelf_find( 'tone00001', 1 ) && 'up' === rrt_shelf_find( 'tone00001', $ts_editor->ID )['state'], 'the same id from another person is their own take, numbered on their own shelf' );
rrt_shelf_clear( $ts_editor->ID, 0 );
wp_set_current_user( 0 );
ts_t( in_array( ts_req( 'GET', 'shelf', array( 'post' => $ts_post ) )[0], array( 401, 403 ), true ), 'a visitor is refused' );
wp_set_current_user( 1 );

/* Numbers from the site, and the newest six a post. */
for ( $i = 2; $i <= 7; $i++ ) {
	$id = 'tmany000' . $i;
	ts_req( 'POST', 'shelf/take', array( 'post' => $ts_post ), null, array( 'take' => ts_take( $id, $ms - 5000 + $i * 1000 ), 'bytes' => 50, 'sum' => $sum ) );
	ts_req( 'POST', "shelf/take/$id/piece", array( 'offset' => 0 ), ts_flac( 50 ) );
	ts_req( 'POST', "shelf/take/$id/done" );
}
list( $st, $d ) = ts_req( 'GET', 'shelf', array( 'post' => $ts_post ) );
$ns             = array_map(
	function ( $t ) {
		return $t['n'];
	},
	$d['takes']
);
ts_t( array( 2, 3, 4, 5, 6, 7 ) === $ns && null === rrt_shelf_find( 'tone00001' ) && ! file_exists( rrt_shelf_file( 1, 'tone00001', 'flac' ) ), 'the site numbers takes in order, and keeps the newest six a post (the oldest goes, with its files)', wp_json_encode( $ns ) );
/* A take of another post does not count towards this one's six. */
ts_req( 'POST', 'shelf/take', array( 'post' => $ts_post2 ), null, array( 'take' => ts_take( 'tother001', $ms ), 'bytes' => 50, 'sum' => $sum ) );
ts_req( 'POST', 'shelf/take/tother001/piece', array( 'offset' => 0 ), ts_flac( 50 ) );
ts_req( 'POST', 'shelf/take/tother001/done' );
ts_t( 6 === count( ts_req( 'GET', 'shelf', array( 'post' => $ts_post ) )[1]['takes'] ) && 1 === ts_req( 'GET', 'shelf', array( 'post' => $ts_post2 ) )[1]['takes'][0]['n'], 'each post has its own six and its own numbers' );

/* Delete one. */
list( $st, $d ) = ts_req( 'DELETE', 'shelf/take/tmany0002' );
ts_t( 200 === $st && true === $d['deleted'] && null === rrt_shelf_find( 'tmany0002' ) && ! file_exists( rrt_shelf_file( 1, 'tmany0002', 'json' ) ), 'the owner deletes one take, with its files' );

/* The plan. */
$plan           = array(
	'choice' => array( 'b1' => 'tmany0003', 's4' => 'tmany0004', 'bad key!' => 'tmany0003', 'b9' => 'NOT AN ID' ),
	'split'  => array( '2' => true, 'x' => false ),
	'joins'  => array( '4-5:tmany0003>tmany0004' => array( 'a' => 20, 'b' => -10, 'sp' => 150, 'evil' => '<script>' ) ),
);
list( $st, $d ) = ts_req( 'POST', 'shelf/plan', array( 'post' => $ts_post ), null, array( 'plan' => $plan, 'at' => $ms ) );
ts_t( 200 === $st && (float) $ms === (float) $d['at'], 'a plan is saved with its time', $st . ' ' . wp_json_encode( $d ) );
$got = ts_req( 'GET', 'shelf', array( 'post' => $ts_post ) )[1]['plan'];
ts_t( is_array( $got ) && array( 'b1' => 'tmany0003', 's4' => 'tmany0004', 'badkey' => 'tmany0003' ) === (array) $got['plan']['choice'] && array( '2' => true ) === (array) $got['plan']['split'] && array( 'a' => 20, 'b' => -10, 'sp' => 150 ) === (array) $got['plan']['joins']['4-5:tmany0003>tmany0004'] && (float) $ms === (float) $got['at'], 'the list brings the plan back, cleaned', wp_json_encode( $got ) );
ts_t( 413 === ts_req( 'POST', 'shelf/plan', array( 'post' => $ts_post ), null, array( 'plan' => array( 'choice' => array_fill_keys( array_map( function ( $i ) { return 'b' . $i; }, range( 1, 5000 ) ), 'tmany0003aaaaaaaaaaaaaaaaaaaaaa' ), 'joins' => array_fill_keys( array_map( function ( $i ) { return $i . '-' . $i . ':tmany0003>tmany0004'; }, range( 1, 5000 ) ), array( 'a' => 1 ) ) ), 'at' => $ms ) )[0], 'a plan too large is refused' );

/* Clearing a post: its takes and its plan; another post's stay. */
list( $st, $d ) = ts_req( 'DELETE', 'shelf', array( 'post' => $ts_post ) );
ts_t( 200 === $st && 5 === $d['cleared'] && ! ts_req( 'GET', 'shelf', array( 'post' => $ts_post ) )[1]['takes'] && null === ts_req( 'GET', 'shelf', array( 'post' => $ts_post ) )[1]['plan'] && 1 === count( ts_req( 'GET', 'shelf', array( 'post' => $ts_post2 ) )[1]['takes'] ), 'Clear the shelf deletes the post’s takes and plan, and leaves another post’s', wp_json_encode( $d ) );

/* 30 days, and a send given up. */
$list   = rrt_shelf_raw( 1 );
$list[] = array( 'id' => 'texpired1', 'post' => $ts_post, 'n' => 9, 'at' => ( $now - 31 * DAY_IN_SECONDS ) * 1000, 'seconds' => 1, 'bytes' => 4, 'have' => 4, 'state' => 'ready', 'sum' => $sum, 'updated' => $now );
$list[] = array( 'id' => 'tstale001', 'post' => $ts_post, 'n' => 10, 'at' => $ms, 'seconds' => 1, 'bytes' => 40, 'have' => 4, 'state' => 'up', 'sum' => $sum, 'updated' => $now - 3 * DAY_IN_SECONDS );
rrt_shelf_save( 1, $list );
foreach ( array( 'texpired1', 'tstale001' ) as $id ) {
	file_put_contents( rrt_shelf_file( 1, $id, 'json' ), '{}' );
	file_put_contents( rrt_shelf_file( 1, $id, 'flac' ), 'fLaC' );
}
rrt_shelf_hourly();
ts_t( null === rrt_shelf_find( 'texpired1' ) && null === rrt_shelf_find( 'tstale001' ) && ! file_exists( rrt_shelf_file( 1, 'texpired1', 'flac' ) ) && ! file_exists( rrt_shelf_file( 1, 'tstale001', 'flac' ) ) && null !== rrt_shelf_find( 'tother001' ), 'the hourly job removes takes past 30 days and sends given up, with their files, and keeps the rest' );

/* The space limit. */
update_option( 'rrt_shelf', array( 'on' => true, 'limit' => 100 ) );
list( $st, $d ) = ts_req( 'POST', 'shelf/take', array( 'post' => $ts_post ), null, array( 'take' => ts_take( 'tfull0001', $ms ), 'bytes' => 101 * 1048576 - 5, 'sum' => $sum ) );
ts_t( 507 === $st && 'rrt_shelf_full' === ( $d['code'] ?? '' ), 'a take that would pass the space limit is refused as full', $st . ' ' . wp_json_encode( $d ) );
ts_t( rrt_shelf_used() === 50, 'the space used counts every recording on the site', (string) rrt_shelf_used() );
update_option( 'rrt_shelf', array( 'on' => true, 'limit' => 2048 ) );

/* Settings and Recordings. */
ob_start();
rrt_shelf_settings_section();
$html = ob_get_clean();
ts_t( false !== strpos( $html, 'id="rrt-shelf-on"' ) && false !== strpos( $html, 'checked' ) && false !== strpos( $html, 'Used now:' ) && false !== strpos( $html, 'Delete every shelf on the site' ), 'Settings shows the switch, the space used and Delete every shelf on the site' );
ob_start();
rrt_shelf_admin_section();
$html = ob_get_clean();
ts_t( false !== strpos( $html, 'id="rrt-t-shelf"' ) && false !== strpos( $html, 'Shelf test two' ) && false !== strpos( $html, 'Take 1' ), 'Recordings lists the person’s takes by post' );

/* Export and erase. */
$ex = apply_filters( 'wp_privacy_personal_data_exporters', array() );
$er = apply_filters( 'wp_privacy_personal_data_erasers', array() );
$u  = get_userdata( 1 );
$out = call_user_func( $ex['rrt-shelf']['callback'], $u->user_email, 1 );
ts_t( 1 === count( $out['data'] ) && 'rrt-shelf' === $out['data'][0]['group_id'], 'Export Personal Data lists each take kept on the site' );
$out = call_user_func( $er['rrt-shelf']['callback'], $u->user_email, 1 );
ts_t( true === $out['items_removed'] && ! rrt_shelf_raw( 1 ), 'Erase Personal Data deletes the person’s shelf' );

/* Delete every shelf. */
ts_req( 'POST', 'shelf/take', array( 'post' => $ts_post ), null, array( 'take' => ts_take( 'twipe0001', $ms ), 'bytes' => 50, 'sum' => $sum ) );
ts_req( 'POST', 'shelf/plan', array( 'post' => $ts_post ), null, array( 'plan' => $plan, 'at' => $ms ) );
wp_set_current_user( $ts_editor->ID );
ts_req( 'POST', 'shelf/take', array( 'post' => $ts_post ), null, array( 'take' => ts_take( 'twipe0002', $ms ), 'bytes' => 50, 'sum' => $sum ) );
wp_set_current_user( 1 );
ts_t( 2 === rrt_shelf_delete_all() && ! rrt_shelf_users() && ! glob( rrt_shelf_dir() . '*.json' ) && ! glob( rrt_shelf_dir() . '*.flac' ), 'Delete every shelf on the site deletes everyone’s takes and plans' );

/* Switched off: nothing more is taken. */
update_option( 'rrt_shelf', array( 'on' => false, 'limit' => 2048 ) );
ts_t( in_array( ts_req( 'POST', 'shelf/take', array( 'post' => $ts_post ), null, array( 'take' => ts_take( 'tafter001', $ms ), 'bytes' => 50, 'sum' => $sum ) )[0], array( 401, 403 ), true ), 'switched off again, nothing more is taken' );
