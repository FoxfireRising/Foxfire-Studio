<?php
/**
 * The setup wizard (1.29.0), run inside the test WordPress:
 *   php wp-cli.phar --path=<wordpress> eval-file tests/php/setup.php
 * Which sites are offered setup, the notice and its Not now, the menu entry,
 * each step saving only its own settings (and nothing at all when nothing is
 * changed), the form's Next, Skip this step, Back and Skip setup, who may use
 * it, Run setup again, the practice script and practice takes kept as post 0.
 * Prints one line per check and exits 1 if any fail. Leaves nothing behind.
 */

$GLOBALS['su_pass'] = 0;
$GLOBALS['su_fail'] = array();

function su_t( $ok, $name, $detail = '' ) {
	if ( $ok ) {
		++$GLOBALS['su_pass'];
		echo "ok   $name\n";
	} else {
		$GLOBALS['su_fail'][] = $name;
		echo "FAIL $name" . ( '' !== (string) $detail ? ': ' . $detail : '' ) . "\n";
	}
}

class Su_Stop extends Exception {}

wp_set_current_user( 1 );
$su_was       = get_option( 'rrt_settings', null );
$su_setup_was = get_option( 'rrt_setup', null );
$su_db_was    = get_option( 'rrt_db_version', null );
$GLOBALS['su_users'] = array();
register_shutdown_function(
	function () use ( $su_was, $su_setup_was, $su_db_was ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . rrt_table( 'takes' ) . ' WHERE post_id = 0 AND at_ms BETWEEN %d AND %d', 1700000000000, 1700000099999 ) ); // phpcs:ignore WordPress.DB
		foreach ( $GLOBALS['su_users'] as $u ) {
			wp_delete_user( $u );
		}
		foreach ( array( 'rrt_settings' => $su_was, 'rrt_setup' => $su_setup_was, 'rrt_db_version' => $su_db_was ) as $k => $v ) {
			if ( null === $v ) {
				delete_option( $k );
			} else {
				update_option( $k, $v );
			}
		}
		echo $GLOBALS['su_pass'] . ' passed, ' . count( $GLOBALS['su_fail'] ) . " failed\n";
		if ( $GLOBALS['su_fail'] ) {
			exit( 1 );
		}
	}
);

/* ---- Which sites are offered setup ---- */
delete_option( 'rrt_setup' );
delete_option( 'rrt_settings' );
delete_option( 'rrt_db_version' );
rrt_stats_install();
su_t( 'new' === rrt_setup_state()['status'], 'a fresh install is new, looked at before the tables are made', wp_json_encode( get_option( 'rrt_setup' ) ) );
su_t( false !== get_option( 'rrt_db_version', false ), 'and the install still made the tables' );
su_t( rrt_setup_offered(), 'so setup is offered' );
$su_row = $GLOBALS['wpdb']->get_var( "SELECT autoload FROM {$GLOBALS['wpdb']->options} WHERE option_name = 'rrt_setup'" ); // phpcs:ignore WordPress.DB
su_t( in_array( $su_row, array( 'no', 'off' ), true ), 'its record is not autoloaded', $su_row );
rrt_setup_migrate();
su_t( 'new' === rrt_setup_state()['status'], 'it is looked at only once' );
delete_option( 'rrt_setup' );
rrt_setup_migrate();
su_t( 'existing' === rrt_setup_state()['status'], 'a site that already has the tables (the plugin before 1.29.0) is existing' );
su_t( ! rrt_setup_offered(), 'and is never offered setup' );
delete_option( 'rrt_setup' );
delete_option( 'rrt_db_version' );
update_option( 'rrt_settings', array( 'preset' => 'dark' ) );
rrt_setup_migrate();
su_t( 'existing' === rrt_setup_state()['status'], 'so is a site with saved settings' );
update_option( 'rrt_db_version', null === $su_db_was ? RRT_DB_VERSION : $su_db_was );
delete_option( 'rrt_settings' );

/* ---- The notice ---- */
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
function su_notice( $screen ) {
	set_current_screen( $screen );
	ob_start();
	do_action( 'admin_notices' );
	return (string) ob_get_clean();
}
rrt_setup_save_state( array( 'status' => 'new' ) );
$su_n = su_notice( 'plugins' );
su_t( false !== strpos( $su_n, 'Foxfire Studio is ready.' ) && false !== strpos( $su_n, 'page=rrt-setup' ) && false !== strpos( $su_n, 'rrt_setup_hide' ), 'a new site shows the notice on Plugins with Set up and Not now' );
su_t( false !== strpos( su_notice( 'toplevel_page_rrt-dashboard' ), 'Foxfire Studio is ready.' ), 'and on Foxfire Studio\'s own screens' );
su_t( false === strpos( su_notice( 'dashboard' ), 'Foxfire Studio is ready.' ), 'never on WordPress\'s Dashboard or other screens' );
su_t( false === strpos( su_notice( 'foxfire-studio_page_rrt-setup' ), 'Foxfire Studio is ready.' ), 'nor on the setup screen itself' );
$su_ed = wp_insert_user( array( 'user_login' => 'su_editor', 'user_pass' => wp_generate_password(), 'role' => 'editor' ) );
$GLOBALS['su_users'][] = $su_ed;
wp_set_current_user( $su_ed );
su_t( false === strpos( su_notice( 'plugins' ), 'Foxfire Studio is ready.' ), 'only administrators see it' );
wp_set_current_user( 1 );
rrt_setup_save_state( array( 'status' => 'existing' ) );
su_t( false === strpos( su_notice( 'plugins' ), 'Foxfire Studio is ready.' ), 'an existing site never sees it' );
rrt_setup_save_state( array( 'status' => 'new' ) );

/* The form handlers redirect and exit; here a redirect stops the call instead. */
add_filter(
	'wp_redirect',
	function ( $loc ) {
		throw new Su_Stop( $loc );
	},
	1
);
add_filter(
	'wp_die_handler',
	function () {
		return function ( $msg ) {
			throw new Su_Stop( 'die:' . ( is_string( $msg ) ? $msg : 'x' ) );
		};
	}
);
function su_post( $action, $nonce, $fields ) {
	$_POST = $fields;
	$_REQUEST = $fields;
	$_POST['_wpnonce'] = wp_create_nonce( $nonce );
	$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];
	$_REQUEST['_wp_http_referer'] = '';
	try {
		do_action( 'admin_post_' . $action );
	} catch ( Su_Stop $e ) {
		$_POST = array();
		$_REQUEST = array();
		return $e->getMessage();
	}
	$_POST = array();
	$_REQUEST = array();
	return '';
}
$_SERVER['HTTP_REFERER'] = admin_url( 'plugins.php' );
$su_loc = su_post( 'rrt_setup_hide', 'rrt_setup_hide', array() );
su_t( false !== strpos( $su_loc, 'plugins.php' ) && rrt_setup_state()['hidden'], 'Not now hides the notice for good and goes back', $su_loc );
su_t( false === strpos( su_notice( 'plugins' ), 'Foxfire Studio is ready.' ), 'after Not now the notice is gone' );
su_t( rrt_setup_offered(), 'but setup is still offered (the menu keeps it)' );
rrt_setup_save_state( array( 'status' => 'new' ) );

/* ---- The menu ---- */
function su_menu() {
	global $submenu, $menu, $_registered_pages;
	$submenu = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
	$menu    = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
	$_registered_pages = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
	do_action( 'admin_menu', '' );
	$in = false;
	foreach ( isset( $submenu['rrt-dashboard'] ) ? $submenu['rrt-dashboard'] : array() as $item ) {
		$in = $in || 'rrt-setup' === $item[2];
	}
	return array( $in, ! empty( $_registered_pages['admin_page_rrt-setup'] ) );
}
set_current_screen( 'plugins' );
$su_m = su_menu();
su_t( $su_m[0], 'while setup is offered, Foxfire Studio\'s menu has Set up' );
rrt_setup_save_state( array( 'status' => 'done' ) );
$su_m = su_menu();
su_t( ! $su_m[0] && $su_m[1], 'once it is done, Set up leaves the menu and its screen stays reachable' );
rrt_setup_save_state( array( 'status' => 'new' ) );

/* ---- Each step writes only its own settings ---- */
delete_option( 'rrt_settings' );
rrt_setup_apply( 'show', array( 'show_name' => 'The Lantern Hour' ) );
$su_raw = get_option( 'rrt_settings' );
su_t( array( 'player' => array( 'show_name' => 'The Lantern Hour' ) ) === $su_raw, 'on a fresh site, Your show saves the show name and nothing else', wp_json_encode( $su_raw ) );
$su_def = rrt_defaults_for( 'neutral' );
$su_now = rrt_settings();
su_t( $su_now['defaults'] === $su_def['defaults'] && $su_now['sound']['finish'] === $su_def['sound']['finish'] && 'neutral' === $su_now['preset'], 'every starting value is still the default (no box was switched off)' );
rrt_setup_apply( 'show', array( 'show_name' => '<b>The Lantern Hour</b>' ) );
su_t( 'The Lantern Hour' === rrt_settings()['player']['show_name'], 'the show name is checked like Settings checks it' );
rrt_setup_apply( 'show', array( 'show_name' => '' ) );
su_t( '' === rrt_settings()['player']['show_name'] && rrt_show_name() === wp_strip_all_tags( get_bloginfo( 'name' ) ), 'an empty name falls back to the site title' );

delete_option( 'rrt_settings' );
rrt_setup_apply( 'look', array( 'preset' => 'dark', 'look' => 'studio' ) );
$su_s = rrt_settings();
$su_d = rrt_presets()['dark'];
su_t( 'dark' === $su_s['preset'] && $su_s['colors'] === $su_d['colors'] && $su_s['fonts'] === $su_d['fonts'] && $su_s['text'] === $su_d['text'], 'The look: choosing Dark applies its colours, fonts and wording' );
su_t( $su_s['defaults'] === $su_def['defaults'] && $su_s['player'] === $su_def['player'], 'and nothing outside the look moves' );
rrt_setup_apply( 'look', array( 'preset' => 'dark', 'look' => 'theme' ) );
su_t( 'theme' === rrt_settings()['look'] && $su_d['colors'] === rrt_settings()['colors'], 'the studio\'s look can be the theme\'s, keeping the colours' );
rrt_setup_apply( 'look', array( 'preset' => 'dark', 'look' => 'nonsense' ) );
su_t( 'theme' === rrt_settings()['look'], 'an unknown look is ignored' );
$su_s                     = get_option( 'rrt_settings' );
$su_s['preset']           = 'custom';
$su_s['colors']['muted']  = '#3a3440';
$su_s['colors']['faint']  = '#2a2530';
update_option( 'rrt_settings', $su_s );
su_t( ! rrt_a11y_all_ok( rrt_settings()['colors'] ), '(made colours too faint to read)' );
rrt_setup_apply( 'look', array( 'preset' => 'custom', 'look' => 'theme' ) );
su_t( '#3a3440' === rrt_settings()['colors']['muted'] && 'custom' === rrt_settings()['preset'], 'keeping your own colours changes none of them' );
rrt_setup_apply( 'look', array( 'preset' => 'custom', 'look' => 'theme' ), true );
su_t( rrt_a11y_all_ok( rrt_settings()['colors'] ) && 'custom' === rrt_settings()['preset'], 'Make it readable moves the faint colours and keeps your own preset' );

delete_option( 'rrt_settings' );
rrt_setup_apply( 'player', array( 'post_types' => array( 'post', 'page' ), 'show' => 'everyone', 'position' => 'manual' ) );
$su_s = rrt_settings();
su_t( array( 'post', 'page' ) === $su_s['post_types'] && 'everyone' === $su_s['player']['show'] && 'manual' === $su_s['player']['position'], 'Readings and the player saves the kinds of posts, the audience and the place' );
su_t( $su_s['player']['follow'] && $su_s['player']['resume'] && ! $su_s['player']['download'] && $su_s['defaults'] === $su_def['defaults'], 'and leaves the player\'s other switches alone' );
rrt_setup_apply( 'player', array( 'post_types' => array( 'no_such_type' ), 'show' => 'bogus', 'position' => 'bogus' ) );
$su_s = rrt_settings();
su_t( array( 'post' ) === $su_s['post_types'] && 'everyone' === $su_s['player']['show'] && 'manual' === $su_s['player']['position'], 'unknown values are refused as Settings refuses them' );

delete_option( 'rrt_settings' );
rrt_setup_apply( 'privacy', array() );
su_t( false === rrt_settings()['stats']['server'] && array( 'stats' => array( 'server' => false ) ) === get_option( 'rrt_settings' ), 'Privacy and files: unticked, takes stay in each browser, and only that is saved', wp_json_encode( get_option( 'rrt_settings' ) ) );
rrt_setup_apply( 'privacy', array( 'server' => '1' ) );
su_t( true === rrt_settings()['stats']['server'] && false === rrt_settings()['listen']['on'], 'ticked, takes are kept on the site; listener analytics stays off' );
$su_sw = rrt_setup_visitor_switches();
su_t( 7 === count( $su_sw ) && ! array_filter( wp_list_pluck( $su_sw, 3 ) ), 'it lists seven switches that touch visitors, all off', wp_json_encode( wp_list_pluck( $su_sw, 3 ) ) );

/* A site set up already: going through every step with its own values changes nothing. */
$su_full                          = rrt_defaults_for( 'dark' );
$su_full['text']['welcome']       = 'The candle room';
$su_full['colors']['accent']      = '#d4a843';
$su_full['player']['show_name']   = 'Whispers';
$su_full['player']['show']        = 'everyone';
$su_full['player']['download']    = true;
$su_full['post_types']            = array( 'post', 'page' );
$su_full['defaults']['rec']       = true;
$su_full['sound']['finish']       = 'podcast_stereo';
$su_full['listen']['on']          = true;
$su_full['stats']['server']       = true;
$su_full['kinds']['verse']        = array( 112, 134 );
update_option( 'rrt_settings', $su_full );
$su_raw = get_option( 'rrt_settings' );
$su_cur = rrt_settings();
rrt_setup_apply( 'show', array( 'show_name' => $su_cur['player']['show_name'] ) );
rrt_setup_apply( 'look', array( 'preset' => $su_cur['preset'], 'look' => rrt_look( $su_cur ) ) );
rrt_setup_apply( 'player', array( 'post_types' => $su_cur['post_types'], 'show' => $su_cur['player']['show'], 'position' => $su_cur['player']['position'] ) );
rrt_setup_apply( 'privacy', array( 'server' => $su_cur['stats']['server'] ? '1' : '' ) );
su_t( get_option( 'rrt_settings' ) === $su_raw, 'on a site that is set up, Next on every step with its own values leaves the saved settings exactly as they were' );

/* ---- The form ---- */
update_option( 'rrt_settings', $su_full );
rrt_setup_save_state( array( 'status' => 'new' ) );
$su_loc = su_post( 'rrt_setup', 'rrt_setup', array( 'step' => 'show', 'go' => 'next', 'show_name' => 'Night Pages' ) );
su_t( false !== strpos( $su_loc, 'step=look' ) && 'Night Pages' === rrt_settings()['player']['show_name'] && 'done' === rrt_setup_state()['steps']['show'], 'Next saves the step, marks it done and goes to the next', $su_loc );
$su_loc = su_post( 'rrt_setup', 'rrt_setup', array( 'step' => 'show', 'go' => 'skip', 'show_name' => 'Not saved' ) );
su_t( false !== strpos( $su_loc, 'step=look' ) && 'Night Pages' === rrt_settings()['player']['show_name'] && 'skipped' === rrt_setup_state()['steps']['show'], 'Skip this step moves on without saving' );
$su_loc = su_post( 'rrt_setup', 'rrt_setup', array( 'step' => 'look', 'go' => 'back', 'preset' => 'neutral' ) );
su_t( false !== strpos( $su_loc, 'step=show' ) && 'dark' === rrt_settings()['preset'], 'Back goes to the step before without saving', $su_loc );
$su_loc = su_post( 'rrt_setup', 'rrt_setup', array( 'step' => 'show', 'go' => 'back' ) );
su_t( false !== strpos( $su_loc, 'page=rrt-setup' ) && false === strpos( $su_loc, 'step=' ), 'Back from the second step goes to the welcome', $su_loc );
$su_loc = su_post( 'rrt_setup', 'rrt_setup', array( 'step' => 'first', 'go' => 'next' ) );
su_t( false !== strpos( $su_loc, 'step=done' ) && 'done' === rrt_setup_state()['status'] && rrt_setup_state()['finished'] > 0, 'Finish on the last step finishes setup' );
su_t( ! rrt_setup_offered() && false === strpos( su_notice( 'plugins' ), 'Foxfire Studio is ready.' ), 'after which it is no longer offered' );
rrt_setup_save_state( array( 'status' => 'new' ) );
$su_loc = su_post( 'rrt_setup', 'rrt_setup', array( 'step' => 'welcome', 'go' => 'skipall' ) );
su_t( false !== strpos( $su_loc, 'page=rrt-settings' ) && 'skipped' === rrt_setup_state()['status'] && ! rrt_setup_offered(), 'Skip setup goes to Settings and stops offering it', $su_loc );
rrt_setup_save_state( array( 'status' => 'done' ) );
su_post( 'rrt_setup', 'rrt_setup', array( 'step' => 'welcome', 'go' => 'skipall' ) );
su_t( 'done' === rrt_setup_state()['status'], 'Skip setup on a finished setup leaves it finished' );
$su_loc = su_post( 'rrt_setup', 'rrt_setup', array( 'step' => 'no_such', 'go' => 'next' ) );
su_t( false !== strpos( $su_loc, 'page=rrt-setup' ), 'an unknown step goes back to the start', $su_loc );
$_POST = array( 'step' => 'show', 'go' => 'next', 'show_name' => 'Forged', '_wpnonce' => 'nope' );
$_REQUEST = $_POST;
try {
	do_action( 'admin_post_rrt_setup' );
	$su_loc = '';
} catch ( Su_Stop $e ) {
	$su_loc = $e->getMessage();
}
$_POST = array();
$_REQUEST = array();
su_t( 0 === strpos( $su_loc, 'die:' ) && 'Night Pages' === rrt_settings()['player']['show_name'], 'a form without its nonce is refused', $su_loc );
wp_set_current_user( $su_ed );
$su_loc = su_post( 'rrt_setup', 'rrt_setup', array( 'step' => 'show', 'go' => 'next', 'show_name' => 'Editor' ) );
su_t( 0 === strpos( $su_loc, 'die:' ) && 'Night Pages' === rrt_settings()['player']['show_name'], 'editors may not use the wizard', $su_loc );
$su_loc = su_post( 'rrt_setup_hide', 'rrt_setup_hide', array() );
su_t( 0 === strpos( $su_loc, 'die:' ), 'nor hide its notice' );
wp_set_current_user( 1 );

/* ---- The screen ---- */
$su_html = array();
foreach ( array_keys( rrt_setup_steps() ) as $su_k ) {
	$_GET['step'] = $su_k;
	ob_start();
	rrt_setup_page();
	$su_html[ $su_k ] = (string) ob_get_clean();
}
unset( $_GET['step'] );
su_t( false !== strpos( $su_html['welcome'], 'Step 1 of 6' ) && false !== strpos( $su_html['first'], 'Step 6 of 6' ) && false === strpos( $su_html['done'], 'Step ' ), 'each step says where it is, Step 1 of 6 to Step 6 of 6' );
su_t( false !== strpos( $su_html['show'], 'aria-current="step"' ) && false !== strpos( $su_html['show'], 'value="Night Pages"' ), 'Your show opens with the saved name and marks the current step' );
su_t( false !== strpos( $su_html['look'], 'name="preset" value="dark" checked' ) || false !== strpos( $su_html['look'], "name=\"preset\" value=\"dark\"  checked='checked'" ) || preg_match( '/value="dark"\s+checked/', $su_html['look'] ), 'The look opens with the saved preset chosen' );
su_t( false !== strpos( $su_html['player'], 'value="page"' ) && false === strpos( $su_html['player'], 'value="rrt_collection"' ) && false === strpos( $su_html['player'], 'value="rrt_spot"' ), 'Readings and the player lists the site\'s kinds of posts, without the plugin\'s own' );
su_t( false !== strpos( $su_html['privacy'], 'id="rrt-setup-files-get"' ) && false !== strpos( $su_html['privacy'], 'data-rrt-files' ) && false !== strpos( $su_html['privacy'], 'privacy-policy-guide.php' ), 'Privacy and files has the download button, the downloader\'s data and the Privacy Policy Guide' );
su_t( false !== strpos( $su_html['first'], 'tp_practice=1' ) && false !== strpos( $su_html['first'], 'tp_check=1' ) && false !== strpos( $su_html['first'], 'target="_blank"' ), 'Your first reading opens the practice reading, with the microphone check, in a new tab' );
su_t( false !== strpos( $su_html['done'], 'page=rrt-podcast' ) && false === strpos( $su_html['done'], '<form' ), 'Done has the next steps and no form' );
ob_start();
do_action( 'rrt_settings_top' );
su_t( false !== strpos( (string) ob_get_clean(), 'Run setup again' ), 'Settings has Run setup again' );

/* ---- The practice reading ---- */
$su_b = rrt_practice_blocks();
$su_w = 0;
foreach ( $su_b as $b ) {
	$su_w += str_word_count( $b['text'] );
}
su_t( 'title' === $su_b[0]['type'] && 'A practice reading' === $su_b[0]['text'] && 6 === count( $su_b ), 'the practice script is a title and five paragraphs', count( $su_b ) );
su_t( $su_w >= 120 && $su_w <= 180, 'of about a minute read aloud', $su_w . ' words' );
su_t( false !== strpos( implode( ' ', wp_list_pluck( $su_b, 'text' ) ), '?' ), 'with a question in it' );
su_t( false !== strpos( rrt_practice_url( true ), 'tp_practice=1' ) && false !== strpos( rrt_practice_url( true ), 'tp_check=1' ) && false === strpos( rrt_practice_url(), 'tp_check' ), 'its address, with and without the microphone check' );
su_t( 'A practice reading' === rrt_take_title( 0 ) && get_the_title( 1 ) === rrt_take_title( 1 ), 'Your progress names a practice take as the practice reading' );

function su_rest( $method, $route, $params ) {
	$r = new WP_REST_Request( $method, $route );
	foreach ( $params as $k => $v ) {
		$r->set_param( $k, $v );
	}
	return rest_do_request( $r );
}
$su_res = su_rest( 'POST', '/rrt/v1/takes', array( 'post' => 0, 'summary' => array( 'at' => 1700000012345, 'mode' => 'timed', 'secs' => 61, 'wpm' => 142, 'words' => 150 ) ) );
su_t( 200 === $su_res->get_status() && (int) $su_res->get_data()['id'] > 0, 'a practice take is saved as post 0', $su_res->get_status() . ' ' . wp_json_encode( $su_res->get_data() ) );
$su_res = su_rest( 'GET', '/rrt/v1/takes', array( 'post' => '0' ) );
$su_ats = wp_list_pluck( (array) $su_res->get_data()['takes'], 'at' );
su_t( 200 === $su_res->get_status() && in_array( 1700000012345, array_map( 'intval', $su_ats ), true ), 'and read back with the reader\'s other practice takes' );
$su_res = su_rest( 'GET', '/rrt/v1/takes', array( 'post' => 1 ) );
su_t( ! in_array( 1700000012345, array_map( 'intval', wp_list_pluck( (array) $su_res->get_data()['takes'], 'at' ) ), true ), 'it is never on a post\'s takes' );
$su_res = su_rest( 'POST', '/rrt/v1/takes', array( 'summary' => array( 'at' => 1700000012346 ) ) );
su_t( 403 === $su_res->get_status(), 'a take with no post named is still refused', $su_res->get_status() );
$su_res = su_rest( 'POST', '/rrt/v1/recording', array( 'post' => 0 ) );
su_t( in_array( $su_res->get_status(), array( 401, 403 ), true ), 'a recording can never be put on post 0', $su_res->get_status() );
wp_set_current_user( $su_ed );
$su_res = su_rest( 'GET', '/rrt/v1/takes', array( 'post' => '0' ) );
$su_mine = wp_list_pluck( (array) $su_res->get_data()['takes'], 'at' );
su_t( 200 === $su_res->get_status() && ! in_array( 1700000012345, array_map( 'intval', $su_mine ), true ), 'each reader sees only their own practice takes' );
wp_set_current_user( 1 );

/* ---- Uninstall ---- */
$su_un = (string) file_get_contents( RRT_DIR . 'uninstall.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
su_t( false !== strpos( $su_un, "delete_option( 'rrt_setup' )" ), 'uninstalling removes the wizard\'s record' );
