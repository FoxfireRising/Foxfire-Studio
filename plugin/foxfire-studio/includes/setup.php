<?php
/**
 * The setup wizard (1.29.0): first-run steps for a new site.
 *
 * Foxfire Studio, then Set up (admin.php?page=rrt-setup), for administrators.
 * One step per screen, each saved on Next: the show's name, the look,
 * readings and the player, privacy and files, and a first practice reading
 * in the studio. Every step opens with the site's current values, and only
 * the settings a step shows are ever written, so going through it on a site
 * that is already set up changes nothing unless a value is changed.
 *
 * A new install is offered setup by one notice; a site that had the plugin
 * before 1.29.0 never is, and Settings has Run setup again for everyone.
 *
 * Also here: the practice script the studio reads at ?teleprompter=1&tp_practice=1.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The steps in order; done is the closing screen. */
function rrt_setup_steps() {
	return array(
		'welcome' => __( 'Welcome', 'foxfire-studio' ),
		'show'    => __( 'Your show', 'foxfire-studio' ),
		'look'    => __( 'The look', 'foxfire-studio' ),
		'player'  => __( 'Readings and the player', 'foxfire-studio' ),
		'privacy' => __( 'Privacy and files', 'foxfire-studio' ),
		'first'   => __( 'Your first reading', 'foxfire-studio' ),
		'done'    => __( 'Done', 'foxfire-studio' ),
	);
}

/**
 * The wizard's own record: rrt_setup, not autoloaded.
 *
 * status: new (offer it), existing (the site had the plugin before 1.29.0),
 * skipped (Skip setup) or done. steps: each step's done or skipped. hidden:
 * the notice's Not now. finished: when setup finished.
 *
 * @return array
 */
function rrt_setup_state() {
	$s = get_option( 'rrt_setup', array() );
	$s = is_array( $s ) ? $s : array();
	return array(
		'status'   => isset( $s['status'] ) && in_array( $s['status'], array( 'new', 'existing', 'skipped', 'done' ), true ) ? $s['status'] : 'existing',
		'steps'    => isset( $s['steps'] ) && is_array( $s['steps'] ) ? $s['steps'] : array(),
		'hidden'   => ! empty( $s['hidden'] ),
		'finished' => isset( $s['finished'] ) ? (int) $s['finished'] : 0,
	);
}

/** Saves the wizard's record. */
function rrt_setup_save_state( $s ) {
	if ( false === get_option( 'rrt_setup', false ) ) {
		add_option( 'rrt_setup', $s, '', 'no' );
	} else {
		update_option( 'rrt_setup', $s );
	}
}

/**
 * Runs once, at the start of installation and early on every load, before
 * anything else creates options: a site that already has saved settings or
 * the plugin's tables had the plugin before 1.29.0 and is marked existing,
 * the same test 0.10.1 used for listener analytics. Anything else is new.
 */
function rrt_setup_migrate() {
	if ( false !== get_option( 'rrt_setup', false ) ) {
		return;
	}
	$existing = is_array( get_option( 'rrt_settings', null ) ) || false !== get_option( 'rrt_db_version', false );
	rrt_setup_save_state( array( 'status' => $existing ? 'existing' : 'new' ) );
}
add_action( 'plugins_loaded', 'rrt_setup_migrate', 1 );

/** Whether setup should still be offered: a new site that has not finished or skipped it. */
function rrt_setup_offered() {
	return 'new' === rrt_setup_state()['status'];
}

/** The wizard's address, at a step. */
function rrt_setup_url( $step = '' ) {
	$args = array( 'page' => 'rrt-setup' );
	if ( '' !== $step && 'welcome' !== $step ) {
		$args['step'] = $step;
	}
	return add_query_arg( $args, admin_url( 'admin.php' ) );
}

/** The practice reading's address in the studio; with $check the studio opens Check microphone by itself. */
function rrt_practice_url( $check = false ) {
	$args = array( 'tp_practice' => 1 );
	if ( $check ) {
		$args['tp_check'] = 1;
	}
	return add_query_arg( $args, rrt_url() );
}

/*
 * The menu entry: Foxfire Studio, then Set up, while setup is still offered.
 * Afterwards the screen stays reachable (Run setup again in Settings) but
 * leaves the menu.
 */
add_action(
	'admin_menu',
	function () {
		/* Out of the menu, the screen hangs under options.php, which WordPress never shows as a menu. */
		add_submenu_page( rrt_setup_offered() ? 'rrt-dashboard' : 'options.php', __( 'Set up Foxfire Studio', 'foxfire-studio' ), __( 'Set up', 'foxfire-studio' ), 'manage_options', 'rrt-setup', 'rrt_setup_page' );
	},
	11
);

/*
 * The notice on a new site: on the Plugins screen and Foxfire Studio's own
 * screens, for administrators, until setup is finished, skipped or the
 * notice is hidden with Not now. Never a redirect on activation.
 */
add_action(
	'admin_notices',
	function () {
		if ( ! current_user_can( 'manage_options' ) || ! rrt_setup_offered() || rrt_setup_state()['hidden'] ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}
		$ours = 'plugins' === $screen->id || 'settings_page_rrt-settings' === $screen->id || 'toplevel_page_rrt-dashboard' === $screen->id || false !== strpos( (string) $screen->id, '_page_rrt-' );
		if ( ! $ours || false !== strpos( (string) $screen->id, '_page_rrt-setup' ) ) {
			return;
		}
		$hide = wp_nonce_url( admin_url( 'admin-post.php?action=rrt_setup_hide' ), 'rrt_setup_hide' );
		echo '<div class="notice notice-info rrt-setup-notice"><p><strong>' . esc_html__( 'Foxfire Studio is ready.', 'foxfire-studio' ) . '</strong> ' . esc_html__( 'Set it up in about five minutes: your show\'s name, the look, where the player goes, privacy, and a first practice reading.', 'foxfire-studio' ) . '</p><p><a class="button button-primary" href="' . esc_url( rrt_setup_url() ) . '">' . esc_html__( 'Set up', 'foxfire-studio' ) . '</a> <a class="button" href="' . esc_url( $hide ) . '">' . esc_html__( 'Not now', 'foxfire-studio' ) . '</a></p></div>';
	}
);

/* Not now: the notice goes for good; Set up stays in the menu until setup is finished. */
add_action(
	'admin_post_rrt_setup_hide',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'foxfire-studio' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'rrt_setup_hide' );
		$s           = rrt_setup_state();
		$s['hidden'] = true;
		rrt_setup_save_state( $s );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'plugins.php' ) );
		exit;
	}
);

/* Run setup again, at the top of Settings. */
add_action(
	'rrt_settings_top',
	function () {
		echo '<a class="button" id="rrt-setup-again" href="' . esc_url( rrt_setup_url() ) . '">' . esc_html__( 'Run setup again', 'foxfire-studio' ) . '</a> ';
	}
);

/**
 * A value inside a nested array by its path, such as player.show.
 *
 * @param array  $a    The array.
 * @param string $path Keys joined by dots.
 * @return mixed|null
 */
function rrt_setup_get( $a, $path ) {
	foreach ( explode( '.', $path ) as $k ) {
		if ( ! is_array( $a ) || ! array_key_exists( $k, $a ) ) {
			return null;
		}
		$a = $a[ $k ];
	}
	return $a;
}

/** Sets a value inside a nested array by its path. */
function rrt_setup_set( &$a, $path, $v ) {
	$keys = explode( '.', $path );
	$last = array_pop( $keys );
	$ref  = &$a;
	foreach ( $keys as $k ) {
		if ( ! isset( $ref[ $k ] ) || ! is_array( $ref[ $k ] ) ) {
			$ref[ $k ] = array();
		}
		$ref = &$ref[ $k ];
	}
	$ref[ $last ] = $v;
}

/**
 * Writes only the given settings into the saved option, and only those that
 * differ from what the site uses now, so nothing else in it moves. The
 * values come already checked by rrt_sanitize_settings(), so the option's
 * own sanitizer (which expects the whole Settings form) is set aside for
 * this one write.
 *
 * @param array $clean The checked settings, as rrt_sanitize_settings() returns them.
 * @param array $paths The settings to write, as paths such as player.show.
 * @return bool Whether anything changed.
 */
function rrt_setup_write( $clean, $paths ) {
	$cur   = rrt_settings();
	$saved = get_option( 'rrt_settings', array() );
	$saved = is_array( $saved ) ? $saved : array();
	$moved = false;
	foreach ( $paths as $p ) {
		$v = rrt_setup_get( $clean, $p );
		if ( null === $v || rrt_setup_get( $cur, $p ) === $v ) {
			continue;
		}
		rrt_setup_set( $saved, $p, $v );
		$moved = true;
	}
	if ( ! $moved ) {
		return false;
	}
	$had = has_filter( 'sanitize_option_rrt_settings', 'rrt_sanitize_settings' );
	if ( false !== $had ) {
		remove_filter( 'sanitize_option_rrt_settings', 'rrt_sanitize_settings', $had );
	}
	update_option( 'rrt_settings', $saved );
	if ( false !== $had ) {
		add_filter( 'sanitize_option_rrt_settings', 'rrt_sanitize_settings', $had );
	}
	return true;
}

/**
 * Saves one step from its form.
 *
 * @param string $step The step.
 * @param array  $in   The form's values (slashed, as $_POST is).
 * @param bool   $fix  Make it readable was pressed (the look step).
 */
function rrt_setup_apply( $step, $in, $fix = false ) {
	$form = rrt_settings();
	if ( 'show' === $step ) {
		$form['player']['show_name'] = isset( $in['show_name'] ) ? (string) $in['show_name'] : '';
		rrt_setup_write( rrt_sanitize_settings( $form ), array( 'player.show_name' ) );
	} elseif ( 'look' === $step ) {
		$presets = rrt_presets();
		$want    = isset( $in['preset'] ) ? sanitize_key( $in['preset'] ) : '';
		/* A preset changes the colours, fonts and wording at once, as Apply this preset does in Settings. */
		if ( isset( $presets[ $want ] ) && $want !== $form['preset'] ) {
			$p = rrt_sanitize_settings( array( 'apply_preset' => $want ) );
			rrt_setup_write( $p, array( 'preset', 'text', 'colors', 'fonts' ) );
			$form = rrt_settings();
		}
		if ( isset( $in['look'] ) ) {
			$form['look'] = sanitize_key( $in['look'] );
		}
		if ( $fix ) {
			$form['a11y_fix'] = 1;
		}
		rrt_setup_write( rrt_sanitize_settings( $form ), array( 'look', 'colors' ) );
	} elseif ( 'player' === $step ) {
		$form['post_types'] = isset( $in['post_types'] ) && is_array( $in['post_types'] ) ? array_map( 'sanitize_key', $in['post_types'] ) : array();
		if ( isset( $in['show'] ) ) {
			$form['player']['show'] = sanitize_key( $in['show'] );
		}
		if ( isset( $in['position'] ) ) {
			$form['player']['position'] = sanitize_key( $in['position'] );
		}
		rrt_setup_write( rrt_sanitize_settings( $form ), array( 'post_types', 'player.show', 'player.position' ) );
	} elseif ( 'privacy' === $step ) {
		$form['stats']['present'] = 1;
		$form['stats']['server']  = ! empty( $in['server'] );
		rrt_setup_write( rrt_sanitize_settings( $form ), array( 'stats.server' ) );
	}
}

/* The wizard's form: Next saves the step, Skip this step and Back move without saving. */
add_action(
	'admin_post_rrt_setup',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'foxfire-studio' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'rrt_setup' );
		$steps = array_keys( rrt_setup_steps() );
		$step  = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : '';
		$go    = isset( $_POST['go'] ) ? sanitize_key( wp_unslash( $_POST['go'] ) ) : 'next';
		$at    = array_search( $step, $steps, true );
		if ( false === $at || 'done' === $step ) {
			wp_safe_redirect( rrt_setup_url() );
			exit;
		}
		$s = rrt_setup_state();
		if ( 'skipall' === $go ) {
			if ( 'done' !== $s['status'] ) {
				$s['status'] = 'skipped';
			}
			rrt_setup_save_state( $s );
			wp_safe_redirect( admin_url( 'options-general.php?page=rrt-settings' ) );
			exit;
		}
		if ( 'back' === $go ) {
			wp_safe_redirect( rrt_setup_url( $steps[ max( 0, $at - 1 ) ] ) );
			exit;
		}
		if ( 'fix' === $go ) {
			rrt_setup_apply( $step, $_POST, true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each value is checked in rrt_setup_apply() and rrt_sanitize_settings().
			wp_safe_redirect( rrt_setup_url( $step ) . '#rrt-setup-readable' );
			exit;
		}
		if ( 'next' === $go ) {
			rrt_setup_apply( $step, $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each value is checked in rrt_setup_apply() and rrt_sanitize_settings().
		}
		$s['steps'][ $step ] = 'next' === $go ? 'done' : 'skipped';
		if ( 'first' === $step ) {
			$s['status']   = 'done';
			$s['finished'] = time();
		}
		rrt_setup_save_state( $s );
		wp_safe_redirect( rrt_setup_url( $steps[ $at + 1 ] ) );
		exit;
	}
);

/** The kinds of posts that can have readings, as Settings lists them. */
function rrt_setup_types() {
	$types = get_post_types( array( 'show_ui' => true ), 'objects' );
	unset( $types['attachment'], $types['wp_block'], $types['wp_navigation'], $types['wp_template'], $types['wp_template_part'], $types['wp_font_family'], $types['wp_font_face'] );
	foreach ( array( 'rrt_collection', 'rrt_credit', 'rrt_spot' ) as $own ) {
		unset( $types[ $own ] );
	}
	return $types;
}

/**
 * The switches that touch visitors, each with whether it is on and where it
 * lives. The wizard only lists them: each needs a line in the privacy policy
 * before it is switched on.
 *
 * @return array List of array( label, what, where, on ).
 */
function rrt_setup_visitor_switches() {
	$s    = rrt_settings();
	$feed = admin_url( 'admin.php?page=rrt-podcast' );
	$set  = admin_url( 'options-general.php?page=rrt-settings' );
	$pc   = function_exists( 'rrt_podcast_settings' ) ? rrt_podcast_settings() : array();
	$mem  = function_exists( 'rrt_member_settings' ) ? rrt_member_settings() : array();
	return array(
		array( __( 'Listener analytics', 'foxfire-studio' ), __( 'How visitors listen to the player: plays, how far they get, speed changes, where they came from. Kept on this site only.', 'foxfire-studio' ), $set, ! empty( $s['listen']['on'] ) ),
		array( __( 'Returning listeners', 'foxfire-studio' ), __( 'A yes or no kept in each listener\'s browser, so a returning listener can be counted.', 'foxfire-studio' ), $set, ! empty( $s['listen']['returning'] ) ),
		array( __( 'Google Analytics', 'foxfire-studio' ), __( 'Sends play, halfway and finished events to the site\'s Google Analytics, when it has one.', 'foxfire-studio' ), $set, ! empty( $s['listen']['ga'] ) ),
		array( __( 'Count downloads', 'foxfire-studio' ), __( 'Counts podcast downloads to the IAB guidelines, with each listener\'s address kept for a day.', 'foxfire-studio' ), $feed, function_exists( 'rrt_dl_on' ) ? rrt_dl_on() : false ),
		array( __( 'Pings', 'foxfire-studio' ), __( 'Tells Podcast Index and a WebSub hub when a new reading is out.', 'foxfire-studio' ), $feed, ! empty( $pc['pings'] ) ),
		array( __( 'Feed activity', 'foxfire-studio' ), __( 'Watches members\' private feeds for an address that may be shared.', 'foxfire-studio' ), $feed, ! empty( $mem['activity'] ) ),
		array( __( 'Storage offload', 'foxfire-studio' ), __( 'Serves readings from outside storage or a CDN, which then sees each listener\'s address.', 'foxfire-studio' ), $feed, function_exists( 'rrt_off_on' ) ? rrt_off_on() : false ),
	);
}

/** The screen. */
function rrt_setup_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$steps = rrt_setup_steps();
	$keys  = array_keys( $steps );
	$step  = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : 'welcome'; // phpcs:ignore WordPress.Security.NonceVerification -- which step to show.
	if ( ! isset( $steps[ $step ] ) ) {
		$step = 'welcome';
	}
	$at    = array_search( $step, $keys, true );
	$count = count( $keys ) - 1;
	$s     = rrt_settings();
	?>
	<div class="wrap rrt-setup">
		<h1><?php esc_html_e( 'Set up Foxfire Studio', 'foxfire-studio' ); ?></h1>
		<nav aria-label="<?php esc_attr_e( 'Setup steps', 'foxfire-studio' ); ?>">
			<ol class="rrt-setup-steps">
				<?php foreach ( $keys as $i => $k ) : ?>
					<?php if ( 'done' === $k ) { continue; } ?>
					<li class="<?php echo $i < $at ? 'is-past' : ( $i === $at ? 'is-now' : '' ); ?>"<?php echo $i === $at ? ' aria-current="step"' : ''; ?>><?php echo esc_html( $steps[ $k ] ); ?></li>
				<?php endforeach; ?>
			</ol>
		</nav>
		<?php if ( 'done' !== $step ) : ?>
			<p class="rrt-setup-count"><?php echo esc_html( sprintf( /* translators: 1: this step's number, 2: how many steps there are. */ __( 'Step %1$d of %2$d', 'foxfire-studio' ), $at + 1, $count ) ); ?></p>
		<?php endif; ?>
		<h2 id="rrt-setup-h"><?php echo esc_html( $steps[ $step ] ); ?></h2>
		<?php if ( 'done' === $step ) : ?>
			<?php rrt_setup_step_done(); ?>
		<?php else : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" aria-labelledby="rrt-setup-h">
			<input type="hidden" name="action" value="rrt_setup">
			<input type="hidden" name="step" value="<?php echo esc_attr( $step ); ?>">
			<?php wp_nonce_field( 'rrt_setup' ); ?>
			<?php call_user_func( 'rrt_setup_step_' . $step, $s ); ?>
			<p class="rrt-setup-buttons">
				<?php if ( 'welcome' === $step ) : ?>
					<button type="submit" class="button button-primary" name="go" value="next"><?php esc_html_e( 'Start', 'foxfire-studio' ); ?></button>
					<button type="submit" class="button" name="go" value="skipall"><?php esc_html_e( 'Skip setup', 'foxfire-studio' ); ?></button>
				<?php else : ?>
					<button type="submit" class="button button-primary" name="go" value="next"><?php echo 'first' === $step ? esc_html__( 'Finish', 'foxfire-studio' ) : esc_html__( 'Next', 'foxfire-studio' ); ?></button>
					<button type="submit" class="button" name="go" value="skip"><?php esc_html_e( 'Skip this step', 'foxfire-studio' ); ?></button>
					<button type="submit" class="button-link rrt-setup-back" name="go" value="back"><?php esc_html_e( 'Back', 'foxfire-studio' ); ?></button>
				<?php endif; ?>
			</p>
		</form>
		<?php endif; ?>
	</div>
	<style>
		.rrt-setup { max-width: 760px; }
		.rrt-setup-steps { display: flex; flex-wrap: wrap; gap: 4px 18px; margin: 14px 0 4px; padding: 0; list-style: none; counter-reset: rrtstep; }
		.rrt-setup-steps li { margin: 0; color: #50575e; counter-increment: rrtstep; }
		.rrt-setup-steps li::before { content: counter(rrtstep) ". "; }
		.rrt-setup-steps li.is-now { color: #1d2327; font-weight: 600; }
		.rrt-setup-steps li.is-past { color: #1d2327; }
		.rrt-setup-count { margin: 0; color: #50575e; }
		.rrt-setup h2 { font-size: 1.5em; margin-top: 18px; }
		.rrt-setup fieldset { margin: 18px 0; }
		.rrt-setup legend { font-weight: 600; font-size: 14px; margin-bottom: 6px; }
		.rrt-setup .rrt-check { display: block; margin: 6px 0; }
		.rrt-setup .description { margin: 4px 0 0; }
		.rrt-setup-buttons { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-top: 24px; }
		.rrt-setup-back { margin-left: 8px; }
		.rrt-setup-presets { display: flex; flex-wrap: wrap; gap: 12px; }
		.rrt-setup-preset { display: block; flex: 1 1 220px; padding: 12px; border: 1px solid #c3c4c7; border-radius: 6px; background: #fff; }
		.rrt-setup-preset:has(input:checked) { border-color: #2271b1; box-shadow: 0 0 0 1px #2271b1; }
		.rrt-setup-swatch { display: block; margin-top: 10px; padding: 12px 14px; border-radius: 6px; font: 24px/1.4 Georgia, serif; }
		.rrt-setup-swatch .now { font-weight: 600; }
		.rrt-setup-swatch .btn { display: inline-block; margin-left: 8px; padding: 1px 10px; border-radius: 999px; font: 600 12px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
		.rrt-setup-list { margin: 8px 0 0 1.2em; list-style: disc; }
		.rrt-setup-list li { margin: 4px 0; }
		.rrt-setup-table { max-width: 760px; }
		.rrt-setup-table td, .rrt-setup-table th { vertical-align: top; }
		.rrt-files { margin: 6px 0 0 1.2em; list-style: disc; }
		.rrt-files li.is-ok span { color: #007017; }
		.rrt-files li.is-bad span, .rrt-files li.is-bad { color: #b32d2e; }
		.rrt-readable-sample { display: inline-block; min-width: 2.2em; padding: 0 6px; border: 1px solid #c3c4c7; border-radius: 3px; font-weight: 600; text-align: center; }
		.rrt-setup-next { margin: 8px 0 0 1.2em; list-style: disc; }
		@media (max-width: 600px) { .rrt-setup-back { margin-left: 0; } }
	</style>
	<?php
}

/** Step 1. */
function rrt_setup_step_welcome( $s ) {
	?>
	<p><?php esc_html_e( 'Foxfire Studio is a teleprompter and recording studio for reading your posts aloud, with a listening player for your visitors and a podcast feed.', 'foxfire-studio' ); ?></p>
	<p><?php esc_html_e( 'Setup takes about five minutes. It covers the choices that matter on the first day:', 'foxfire-studio' ); ?></p>
	<ul class="rrt-setup-list">
		<li><?php esc_html_e( 'your show\'s name', 'foxfire-studio' ); ?></li>
		<li><?php esc_html_e( 'how the studio looks', 'foxfire-studio' ); ?></li>
		<li><?php esc_html_e( 'which posts can have readings, and where the player goes', 'foxfire-studio' ); ?></li>
		<li><?php esc_html_e( 'privacy, and whether the studio\'s files come from this site', 'foxfire-studio' ); ?></li>
		<li><?php esc_html_e( 'a one-minute practice reading, with a microphone check first', 'foxfire-studio' ); ?></li>
	</ul>
	<p><?php esc_html_e( 'Each step shows what the site uses now, and nothing changes unless you change it. Everything here can be changed later in Settings, then Foxfire Studio.', 'foxfire-studio' ); ?></p>
	<?php
}

/** Step 2. */
function rrt_setup_step_show( $s ) {
	$me = wp_get_current_user();
	?>
	<p>
		<label for="rrt-setup-show-name"><strong><?php esc_html_e( 'Show name', 'foxfire-studio' ); ?></strong></label><br>
		<input class="regular-text" id="rrt-setup-show-name" name="show_name" value="<?php echo esc_attr( $s['player']['show_name'] ); ?>" placeholder="<?php echo esc_attr( rrt_show_name( array( 'player' => array( 'show_name' => '' ) ) ) ); ?>" aria-describedby="rrt-setup-show-help">
	</p>
	<p class="description" id="rrt-setup-show-help"><?php esc_html_e( 'Used in each MP3\'s tags, on lock screens while a reading plays, in clips, and as the podcast\'s title unless Podcast feed sets its own. Left empty, the site\'s title is used.', 'foxfire-studio' ); ?></p>
	<p><strong><?php esc_html_e( 'Read by', 'foxfire-studio' ); ?></strong><br>
	<?php
	printf(
		/* translators: 1: the person's display name, 2: a link to their profile, with the words "your profile". */
		esc_html__( 'Your readings are credited to %1$s, your display name in WordPress. To change it, edit %2$s.', 'foxfire-studio' ),
		'<strong>' . esc_html( $me->display_name ) . '</strong>',
		'<a href="' . esc_url( admin_url( 'profile.php' ) ) . '">' . esc_html__( 'your profile', 'foxfire-studio' ) . '</a>'
	);
	?>
	</p>
	<?php
}

/** A small sample of a preset's colours: words read, the word being read, words ahead and a button. */
function rrt_setup_swatch( $c ) {
	$ahead = function_exists( 'rrt_a11y_ahead' ) ? rrt_a11y_ahead( $c ) : $c['muted'];
	echo '<span class="rrt-setup-swatch" aria-hidden="true" style="background:' . esc_attr( $c['bg'] ) . ';color:' . esc_attr( $c['text'] ) . '">';
	echo esc_html__( 'The words light up', 'foxfire-studio' ) . ' <span class="now" style="color:' . esc_attr( $c['accent'] ) . '">' . esc_html__( 'one', 'foxfire-studio' ) . '</span> <span style="color:' . esc_attr( $ahead ) . '">' . esc_html__( 'by one', 'foxfire-studio' ) . '</span>';
	echo '<span class="btn" style="background:' . esc_attr( $c['accent'] ) . ';color:' . esc_attr( $c['bg'] ) . '">' . esc_html__( 'Begin', 'foxfire-studio' ) . '</span></span>';
}

/** Step 3. */
function rrt_setup_step_look( $s ) {
	$presets = rrt_presets();
	$own     = ! isset( $presets[ $s['preset'] ] );
	?>
	<fieldset>
		<legend><?php esc_html_e( 'Colours and fonts', 'foxfire-studio' ); ?></legend>
		<div class="rrt-setup-presets">
			<?php foreach ( $presets as $k => $p ) : ?>
				<label class="rrt-setup-preset"><input type="radio" name="preset" value="<?php echo esc_attr( $k ); ?>" <?php checked( $k, $s['preset'] ); ?>> <?php echo esc_html( $p['label'] ); ?>
					<?php rrt_setup_swatch( $p['colors'] ); ?>
				</label>
			<?php endforeach; ?>
			<?php if ( $own ) : ?>
				<label class="rrt-setup-preset"><input type="radio" name="preset" value="custom" checked> <?php esc_html_e( 'Your own colours, as set in Settings', 'foxfire-studio' ); ?>
					<?php rrt_setup_swatch( $s['colors'] ); ?>
				</label>
			<?php endif; ?>
		</div>
		<p class="description"><?php esc_html_e( 'Choosing the other preset changes the colours, fonts and the studio\'s wording together. Single colours and fonts can be changed in Settings.', 'foxfire-studio' ); ?></p>
	</fieldset>
	<fieldset>
		<legend><?php esc_html_e( 'The studio\'s look', 'foxfire-studio' ); ?></legend>
		<label class="rrt-check"><input type="radio" name="look" value="studio" <?php checked( 'studio', rrt_look( $s ) ); ?>> <strong><?php esc_html_e( 'Foxfire Studio:', 'foxfire-studio' ); ?></strong> <?php esc_html_e( 'the night sky, the fox and gold ornaments, in the colours above', 'foxfire-studio' ); ?></label>
		<label class="rrt-check"><input type="radio" name="look" value="theme" <?php checked( 'theme', rrt_look( $s ) ); ?>> <strong><?php esc_html_e( 'My WordPress theme:', 'foxfire-studio' ); ?></strong> <?php esc_html_e( 'no artwork or ornaments, in your theme\'s colours', 'foxfire-studio' ); ?></label>
	</fieldset>
	<?php
	if ( function_exists( 'rrt_a11y_check' ) ) :
		$rows  = rrt_a11y_check( $s['colors'] );
		$fails = count(
			array_filter(
				$rows,
				function ( $r ) {
					return ! $r['ok'];
				}
			)
		);
		?>
		<div id="rrt-setup-readable" data-rrt-readable="<?php echo $fails ? 'fail' : 'pass'; ?>">
			<p><strong><?php esc_html_e( 'Readability', 'foxfire-studio' ); ?></strong><br>
			<?php
			if ( $fails ) {
				echo esc_html( sprintf( /* translators: %d: number of colour pairs. */ _n( 'With the colours saved now, %d pair of colours is too faint to read easily.', 'With the colours saved now, %d pairs of colours are too faint to read easily.', $fails, 'foxfire-studio' ), $fails ) );
			} else {
				esc_html_e( 'With the colours saved now, every pair of colours is easy to read.', 'foxfire-studio' );
			}
			?>
			</p>
			<?php if ( $fails ) : ?>
				<p><button type="submit" class="button" name="go" value="fix"><?php esc_html_e( 'Make it readable', 'foxfire-studio' ); ?></button>
				<span class="description"><?php esc_html_e( 'Moves only the colours that fall short, and saves this step.', 'foxfire-studio' ); ?></span></p>
			<?php endif; ?>
		</div>
	<?php endif; ?>
	<?php
}

/** Step 4. */
function rrt_setup_step_player( $s ) {
	$shows = array(
		'editors'  => __( 'Only logged-in editors, as a preview', 'foxfire-studio' ),
		'everyone' => __( 'Everyone', 'foxfire-studio' ),
		'off'      => __( 'Nobody (switched off)', 'foxfire-studio' ),
	);
	?>
	<fieldset>
		<legend><?php esc_html_e( 'Which posts can have readings', 'foxfire-studio' ); ?></legend>
		<?php foreach ( rrt_setup_types() as $t ) : ?>
			<label class="rrt-check"><input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $t->name ); ?>" <?php checked( in_array( $t->name, $s['post_types'], true ) ); ?>> <?php echo esc_html( $t->labels->name ); ?></label>
		<?php endforeach; ?>
		<p class="description"><?php esc_html_e( 'These appear in the studio\'s list of posts to read, and get the Reading audio box on their edit screen.', 'foxfire-studio' ); ?></p>
	</fieldset>
	<fieldset>
		<legend><?php esc_html_e( 'Show the player to', 'foxfire-studio' ); ?></legend>
		<?php foreach ( $shows as $k => $label ) : ?>
			<label class="rrt-check"><input type="radio" name="show" value="<?php echo esc_attr( $k ); ?>" <?php checked( $k, $s['player']['show'] ); ?>> <?php echo esc_html( $label ); ?></label>
		<?php endforeach; ?>
		<p class="description"><?php esc_html_e( 'Start with the preview: once a post has its reading, look at it while logged in, then come back to Settings and choose Everyone. Visitors never see the preview.', 'foxfire-studio' ); ?></p>
	</fieldset>
	<fieldset>
		<legend><?php esc_html_e( 'Where it goes', 'foxfire-studio' ); ?></legend>
		<label class="rrt-check"><input type="radio" name="position" value="top" <?php checked( 'top', $s['player']['position'] ); ?>> <?php esc_html_e( 'At the top of the post, unless the post or its template places it', 'foxfire-studio' ); ?></label>
		<label class="rrt-check"><input type="radio" name="position" value="manual" <?php checked( 'manual', $s['player']['position'] ); ?>> <?php
			printf(
				/* translators: 1: the shortcode, [foxfire_player]; 2: the theme function, rrt_player(). Both shown as code. */
				esc_html__( 'Only where I put it: the Reading player block, the %1$s shortcode, or %2$s in the theme', 'foxfire-studio' ),
				'<code>[foxfire_player]</code>',
				'<code>rrt_player()</code>'
			);
			?></label>
		<p class="description"><?php esc_html_e( 'Either way, a post can place the player itself: in the block editor, press the + button, type "reading" and choose Reading player. In the classic editor, type [foxfire_player] where it should go.', 'foxfire-studio' ); ?></p>
	</fieldset>
	<?php
}

/** A list of one Whisper set's files, for the downloader. */
function rrt_setup_file_list( $id, $st ) {
	echo '<ul class="rrt-files" id="' . esc_attr( $id ) . '">';
	foreach ( $st['files'] as $f ) {
		echo '<li data-i="' . (int) $f['i'] . '" data-size="' . (int) $f['size'] . '"><code>' . esc_html( $f['name'] ) . '</code> <span>' . esc_html( rrt_size( $f['size'] ) ) . '</span></li>';
	}
	echo '</ul>';
}

/** Step 5. */
function rrt_setup_step_privacy( $s ) {
	$base  = rrt_whisper_status();
	$small = rrt_whisper_status( 'small' );
	$fol   = rrt_whisper_status( 'follow' );
	$lst   = rrt_local_status( rrt_config() );
	$whole = $base['have'] === $base['count'] && $lst['complete'];
	rrt_files_enqueue();
	?>
	<fieldset>
		<legend><?php esc_html_e( 'Takes', 'foxfire-studio' ); ?></legend>
		<label class="rrt-check"><input type="checkbox" name="server" value="1" <?php checked( ! empty( $s['stats']['server'] ) ); ?>> <?php esc_html_e( 'Keep every take\'s numbers, the words taught to Whisper, and a record of each post\'s reading on this site', 'foxfire-studio' ); ?></label>
		<p class="description"><?php esc_html_e( 'So each reader\'s history follows them to any computer. Only the numbers are kept, never the recording; a recording reaches the site only when a reader presses Use this recording. Each reader sees only their own takes. Unticked, each browser keeps its own history.', 'foxfire-studio' ); ?></p>
	</fieldset>
	<fieldset>
		<legend><?php esc_html_e( 'The studio\'s files', 'foxfire-studio' ); ?></legend>
		<p><?php esc_html_e( 'Until they are on this site, the studio loads Whisper (which listens to each take) from Hugging Face and jsDelivr, its MP3 encoder and noise model from jsDelivr, and its fonts from Google Fonts. Those services then see the address of whoever is recording. Visitors are never affected: the public player loads none of these.', 'foxfire-studio' ); ?></p>
		<div hidden data-rrt-files="<?php echo esc_attr( wp_json_encode( rrt_files_boot() ) ); ?>"></div>
		<p>
			<?php if ( $whole ) : ?>
				<strong><?php esc_html_e( 'Whisper, the encoder, the noise model and the fonts are already on this site.', 'foxfire-studio' ); ?></strong>
			<?php else : ?>
				<?php esc_html_e( 'Download them to this site: Whisper is about 116 MB of your hosting space, the rest a few MB.', 'foxfire-studio' ); ?>
			<?php endif; ?>
		</p>
		<label class="rrt-check"><input type="checkbox" id="rrt-setup-files-follow"> <?php echo esc_html( $fol['have'] === $fol['count'] ? __( 'Voice following\'s model (already on this site; ticked, it is checked again)', 'foxfire-studio' ) : __( 'Also voice following\'s model, about 45 MB, for Follow my voice', 'foxfire-studio' ) ); ?></label>
		<label class="rrt-check"><input type="checkbox" id="rrt-setup-files-small"> <?php echo esc_html( $small['have'] === $small['count'] ? __( 'The larger Whisper model (already on this site; ticked, it is checked again)', 'foxfire-studio' ) : __( 'Also the larger Whisper model, about 570 MB with the graphics chip\'s files, for more accurate reviews', 'foxfire-studio' ) ); ?></label>
		<p>
			<button type="button" class="button" id="rrt-setup-files-get"><?php echo $whole ? esc_html__( 'Check the files again', 'foxfire-studio' ) : esc_html__( 'Download to this site', 'foxfire-studio' ); ?></button>
			<span id="rrt-setup-files-msg" role="status"></span>
		</p>
		<details>
			<summary><?php esc_html_e( 'The files', 'foxfire-studio' ); ?></summary>
			<?php
			rrt_setup_file_list( 'rrt-setup-base', $base );
			echo '<ul class="rrt-files" id="rrt-setup-local-log"></ul>';
			rrt_setup_file_list( 'rrt-setup-follow', $fol );
			rrt_setup_file_list( 'rrt-setup-small', $small );
			?>
		</details>
		<p class="description"><?php esc_html_e( 'Keep this page open while it downloads; you can press Next once it says Done. On a host that cannot fetch large files, SETUP.md has a way to do it from the command line, and anything missing simply keeps loading from outside.', 'foxfire-studio' ); ?></p>
	</fieldset>
	<fieldset>
		<legend><?php esc_html_e( 'Everything that touches visitors', 'foxfire-studio' ); ?></legend>
		<p><?php esc_html_e( 'These start switched off. Each needs a line in your privacy policy before it is switched on, so they are switched on later, where each one lives.', 'foxfire-studio' ); ?></p>
		<table class="widefat striped rrt-setup-table">
			<thead><tr><th scope="col"><?php esc_html_e( 'Switch', 'foxfire-studio' ); ?></th><th scope="col"><?php esc_html_e( 'What it does', 'foxfire-studio' ); ?></th><th scope="col"><?php esc_html_e( 'Now', 'foxfire-studio' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( rrt_setup_visitor_switches() as $sw ) : ?>
				<tr><td><a href="<?php echo esc_url( $sw[2] ); ?>"><?php echo esc_html( $sw[0] ); ?></a></td><td><?php echo esc_html( $sw[1] ); ?></td><td><?php echo $sw[3] ? esc_html__( 'On', 'foxfire-studio' ) : esc_html__( 'Off', 'foxfire-studio' ); ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description">
			<?php
			printf(
				/* translators: %s: a link to WordPress's Privacy Policy Guide, with those words. */
				esc_html__( 'The plugin\'s suggested policy text is in WordPress\'s %s, and in Settings, then Foxfire Studio, under For your privacy policy.', 'foxfire-studio' ),
				'<a href="' . esc_url( admin_url( 'privacy-policy-guide.php' ) ) . '">' . esc_html__( 'Privacy Policy Guide', 'foxfire-studio' ) . '</a>'
			);
			?>
		</p>
	</fieldset>
	<?php
}

/** Step 6. */
function rrt_setup_step_first( $s ) {
	?>
	<p><?php esc_html_e( 'Now try the studio with a one-minute practice script. It opens in a new tab, with a microphone check first: about ten seconds of quiet, then a line read aloud, so the studio can measure your room and your voice.', 'foxfire-studio' ); ?></p>
	<p><?php esc_html_e( 'Then press Begin and read the script aloud. When you stop, the review shows your pace, pauses and any words that were hard to hear. A practice take never goes on a post; it is kept with your other takes, so Your progress has a starting point.', 'foxfire-studio' ); ?></p>
	<p><a class="button button-primary" id="rrt-setup-practice" href="<?php echo esc_url( rrt_practice_url( true ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open the studio for a practice reading', 'foxfire-studio' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'foxfire-studio' ); ?></span></a></p>
	<p class="description"><?php esc_html_e( 'Use the computer and microphone you will record with. Allow the microphone when the browser asks. You can come back to this tab and press Finish at any time.', 'foxfire-studio' ); ?></p>
	<?php
}

/** The closing screen. */
function rrt_setup_step_done() {
	?>
	<p><?php esc_html_e( 'Foxfire Studio is set up. Here is where to go next:', 'foxfire-studio' ); ?></p>
	<ul class="rrt-setup-next">
		<li><a href="<?php echo esc_url( rrt_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Give a post its reading: open the studio and choose a post', 'foxfire-studio' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'foxfire-studio' ); ?></span></a></li>
		<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=rrt-podcast' ) ); ?>"><?php esc_html_e( 'Set up the podcast feed', 'foxfire-studio' ); ?></a></li>
		<li><a href="<?php echo esc_url( admin_url( 'options-general.php?page=rrt-settings' ) ); ?>"><?php esc_html_e( 'Everything else is in Settings, then Foxfire Studio', 'foxfire-studio' ); ?></a></li>
	</ul>
	<p><?php esc_html_e( 'Setup can be run again at any time from the top of Settings, then Foxfire Studio.', 'foxfire-studio' ); ?></p>
	<?php
}

/*
 * The practice reading (1.29.0): a short script shipped with the plugin,
 * read in the studio with no post. Plain sentences, a longer one with a
 * comma pause, a question, a name and numbers, so the review has something
 * to say about pace, pauses and hard words.
 */

/** The practice script's title. */
function rrt_practice_title() {
	return __( 'A practice reading', 'foxfire-studio' );
}

/** The practice script's paragraphs. */
function rrt_practice_paragraphs() {
	return array(
		__( 'Welcome to your first practice reading. There is nothing to get right today. Read at a pace that feels natural, as if you were talking to one friend across a small table.', 'foxfire-studio' ),
		__( 'Take a breath at the end of each sentence. Short sentences are easy. Longer ones, like this one, ask you to keep your voice up all the way to the final word, so the listener never loses the thread.', 'foxfire-studio' ),
		__( 'Have you noticed how a question lifts at the end? Try it once more. Is the kettle on yet?', 'foxfire-studio' ),
		__( 'Names and numbers are where most readers stumble. Margaret Okafor walked twelve hundred steps to the market on the fourteenth of March, and she counted every one.', 'foxfire-studio' ),
		__( 'When you reach the last line, wait a moment before you stop. That quiet gives the review a clean ending. Well done. You have finished your first reading.', 'foxfire-studio' ),
	);
}

/** The practice script as the studio reads it. */
function rrt_practice_blocks() {
	$html = '<p>' . implode( '</p><p>', array_map( 'esc_html', rrt_practice_paragraphs() ) ) . '</p>';
	return rrt_blocks_from_html( rrt_practice_title(), $html );
}

/** The practice take's MP3 tags: the reader, the show and the script's title. */
function rrt_practice_tags() {
	$me = wp_get_current_user();
	return array(
		'title'   => rrt_practice_title(),
		'artist'  => $me ? wp_strip_all_tags( $me->display_name ) : '',
		'album'   => rrt_show_name(),
		'year'    => (int) wp_date( 'Y' ),
		'url'     => '',
		'artwork' => '',
		'genre'   => __( 'Podcast', 'foxfire-studio' ),
	);
}

/**
 * A take's post title for the dashboard: practice takes (post 0) are named
 * as the practice reading.
 *
 * @param int $post_id The take's post.
 * @return string
 */
function rrt_take_title( $post_id ) {
	return (int) $post_id ? get_the_title( (int) $post_id ) : rrt_practice_title();
}
