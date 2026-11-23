<?php
/**
 * Voice polish (1.13.0), run inside the test WordPress:
 *   php wp-cli.phar --path=<wordpress> eval-file tests/php/polish.php
 * Each step's strength, read from a switch saved before 1.13.0 and saved
 * from the Settings form; slow leveling, the EQ and clicks; a setup's band
 * measurements kept through the REST route, with takes counted once, five
 * at most, twelve setups, bad numbers refused, forgetting a setup; a take
 * sent from the phone keeping its setup; and someone who may not record.
 * Prints one line per check and exits 1 if any fail. Leaves nothing behind.
 */

$GLOBALS['pq_pass'] = 0;
$GLOBALS['pq_fail'] = array();

function pq_t( $ok, $name, $detail = '' ) {
	if ( $ok ) {
		++$GLOBALS['pq_pass'];
		echo "ok   $name\n";
	} else {
		$GLOBALS['pq_fail'][] = $name;
		echo "FAIL $name" . ( '' !== $detail ? ": $detail" : '' ) . "\n";
	}
}

function pq_req( $method, $route, $params = array() ) {
	$r = new WP_REST_Request( $method, '/rrt/v1/' . $route );
	foreach ( $params as $k => $v ) {
		$r->set_param( $k, $v );
	}
	$res = rest_do_request( $r );
	return array( $res->get_status(), $res->get_data() );
}

wp_set_current_user( 1 );
$pq_user     = get_current_user_id();
$pq_settings = get_option( 'rrt_settings', null );
$pq_eq       = get_user_meta( $pq_user, '_rrt_eq', true );
$pq_sent     = get_user_meta( $pq_user, '_rrt_sent', true );
delete_user_meta( $pq_user, '_rrt_eq' );

/* Strengths. */
pq_t( 'standard' === rrt_polish_level( true ) && 'off' === rrt_polish_level( false ) && 'gentle' === rrt_polish_level( 'gentle' ) && 'standard' === rrt_polish_level( 'loud' ), 'a strength reads from a switch or a name' );
delete_option( 'rrt_settings' );
$s = rrt_settings();
pq_t( 'standard' === $s['sound']['plosives'] && 'standard' === $s['sound']['compress'] && true === $s['sound']['slow'] && false === $s['sound']['eq'] && false === $s['sound']['clicks'], 'a new install: every step Standard, slow leveling on, EQ and clicks off', wp_json_encode( $s['sound'] ) );
update_option( 'rrt_settings', array( 'sound' => array( 'plosives' => true, 'deess' => false, 'breaths' => true, 'compress' => true ) ) );
$s = rrt_settings();
pq_t( 'standard' === $s['sound']['plosives'] && 'off' === $s['sound']['deess'] && 'standard' === $s['sound']['breaths'], 'switches saved before 1.13.0 read as Standard and Off: the same sound', wp_json_encode( $s['sound'] ) );
pq_t( true === $s['sound']['slow'], 'a site from before 1.13.0 gets slow leveling on' );
$clean = rrt_sanitize_settings(
	array(
		'sound' => array(
			'finish'   => 'podcast_mono',
			'hp'       => '1',
			'plosives' => 'gentle',
			'deess'    => 'strong',
			'breaths'  => 'off',
			'compress' => 'nonsense',
			'eq'       => '1',
			'clicks'   => '1',
		),
	)
);
pq_t( 'gentle' === $clean['sound']['plosives'] && 'strong' === $clean['sound']['deess'] && 'off' === $clean['sound']['breaths'] && 'standard' === $clean['sound']['compress'], 'the Settings form saves each strength, an unknown one as Standard', wp_json_encode( $clean['sound'] ) );
pq_t( false === $clean['sound']['slow'] && true === $clean['sound']['eq'] && true === $clean['sound']['clicks'], 'the three polish switches save as ticked' );
update_option( 'rrt_settings', $clean );
$boot = rrt_settings();
pq_t( 'strong' === $boot['sound']['deess'], 'a saved strength comes back' );

/* The EQ's measurements. */
$b = array( -40.1, -38.2, -44, -49.5, -55, -61.2 );
list( $st, $d ) = pq_req( 'POST', 'eq', array( 'setup' => 'iphone|iphone microphone', 'label' => 'iPhone, iPhone Microphone', 'bands' => wp_json_encode( $b ), 'take' => 'take1' ) );
pq_t( 200 === $st && isset( $d['setups']->{'iphone|iphone microphone'} ), 'a take\'s bands are kept for its setup', $st . ' ' . wp_json_encode( $d ) );
pq_req( 'POST', 'eq', array( 'setup' => 'iphone|iphone microphone', 'label' => 'iPhone, iPhone Microphone', 'bands' => wp_json_encode( $b ), 'take' => 'take1' ) );
$all = rrt_eq_get( $pq_user );
pq_t( 1 === count( $all['iphone|iphone microphone']['takes'] ), 'the same take is counted once' );
for ( $i = 2; $i <= 7; $i++ ) {
	pq_req( 'POST', 'eq', array( 'setup' => 'iphone|iphone microphone', 'label' => 'iPhone, iPhone Microphone', 'bands' => wp_json_encode( $b ), 'take' => 'take' . $i ) );
}
$all = rrt_eq_get( $pq_user );
pq_t( 5 === count( $all['iphone|iphone microphone']['takes'] ), 'five takes at most', (string) count( $all['iphone|iphone microphone']['takes'] ) );
pq_t( 'iPhone, iPhone Microphone' === $all['iphone|iphone microphone']['label'], 'the setup keeps its name for people' );
list( $st ) = pq_req( 'POST', 'eq', array( 'setup' => 'computer|x', 'bands' => wp_json_encode( array( 1, 2, 3 ) ), 'take' => 'a' ) );
pq_t( 400 === $st, 'a list that is not six bands is refused' );
list( $st ) = pq_req( 'POST', 'eq', array( 'setup' => 'computer|x', 'bands' => wp_json_encode( array( 'a', 1, 2, 3, 4, 5 ) ), 'take' => 'a' ) );
pq_t( 400 === $st, 'a band that is not a number is refused' );
list( $st ) = pq_req( 'POST', 'eq', array( 'setup' => '', 'bands' => wp_json_encode( $b ), 'take' => 'a' ) );
pq_t( 400 === $st, 'a take with no setup is refused' );
list( $st ) = pq_req( 'POST', 'eq', array( 'setup' => 'computer|x', 'bands' => wp_json_encode( array( -40, -50, null, null, null, null ) ), 'take' => 'a' ) );
pq_t( 400 === $st, 'fewer than three bands measured is refused' );
list( $st ) = pq_req( 'POST', 'eq', array( 'setup' => 'computer|x', 'bands' => wp_json_encode( array( -40, -50, -60, -70, -80, null ) ), 'take' => 'a' ) );
pq_t( 200 === $st && null === rrt_eq_get( $pq_user )['computer|x']['takes'][0]['bands'][5], 'a band out of reach is kept as unknown' );
for ( $i = 0; $i < 14; $i++ ) {
	pq_req( 'POST', 'eq', array( 'setup' => 'computer|mic ' . $i, 'bands' => wp_json_encode( $b ), 'take' => 'm' . $i ) );
}
pq_t( RRT_EQ_SETUPS === count( rrt_eq_get( $pq_user ) ), 'twelve setups at most', (string) count( rrt_eq_get( $pq_user ) ) );
list( $st, $d ) = pq_req( 'GET', 'eq' );
pq_t( 200 === $st && isset( $d['setups']->{'computer|mic 13'} ), 'the setups are read back' );
list( $st, $d ) = pq_req( 'DELETE', 'eq', array( 'setup' => 'computer|mic 13' ) );
pq_t( 200 === $st && ! isset( rrt_eq_get( $pq_user )['computer|mic 13'] ), 'a setup can be forgotten' );
$boot = rrt_eq_boot();
pq_t( is_array( $boot ) && ! empty( $boot['nonce'] ) && 5 === $boot['takes'], 'the studio is told the setups and where to save' );

/* Someone who may not record. */
$sub = wp_insert_user( array( 'user_login' => 'pq_sub', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
wp_set_current_user( $sub );
list( $st ) = pq_req( 'POST', 'eq', array( 'setup' => 'computer|x', 'bands' => wp_json_encode( $b ), 'take' => 'z' ) );
pq_t( 403 === $st || 401 === $st, 'someone who may not record cannot save', (string) $st );
pq_t( null === rrt_eq_boot(), 'and the studio gets no EQ data for them' );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $sub );
wp_set_current_user( 1 );

/* A take sent from the phone keeps its setup. */
$post = wp_insert_post( array( 'post_title' => 'Polish test', 'post_status' => 'publish' ) );
list( $st, $d ) = pq_req( 'POST', 'sent/live', array( 'post' => $post, 'rate' => 48000, 'bits' => 24, 'setup' => 'iPhone|iPhone Microphone', 'setup_label' => 'iPhone, iPhone Microphone' ) );
pq_t( 200 === $st && 'iphone|iphone microphone' === $d['take']['setup'] && 'iPhone, iPhone Microphone' === $d['take']['setup_label'], 'a phone take keeps the setup it was recorded on', wp_json_encode( $d ) );
if ( 200 === $st ) {
	rrt_sent_delete( $pq_user, $d['take']['id'] );
}
wp_delete_post( $post, true );

/* Put everything back. */
if ( null === $pq_settings ) {
	delete_option( 'rrt_settings' );
} else {
	update_option( 'rrt_settings', $pq_settings );
}
if ( $pq_eq ) {
	update_user_meta( $pq_user, '_rrt_eq', $pq_eq );
} else {
	delete_user_meta( $pq_user, '_rrt_eq' );
}
if ( $pq_sent ) {
	update_user_meta( $pq_user, '_rrt_sent', $pq_sent );
} else {
	delete_user_meta( $pq_user, '_rrt_sent' );
}

$n = count( $GLOBALS['pq_fail'] );
echo "\n" . $GLOBALS['pq_pass'] . " passed, $n failed\n";
if ( $n ) {
	exit( 1 );
}
