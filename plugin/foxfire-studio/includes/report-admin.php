<?php
/**
 * The Reports screen in WP-admin (0.12.0): when the email goes out and to
 * whom, a preview, Send now, a check that scheduled jobs run on time, and the
 * archive of past reports.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Which period the preview and Send now use: the last full period, or the current one so far. */
function rrt_report_pick_period( $which, $interval = null ) {
	return 'sofar' === $which ? rrt_report_so_far( $interval ) : rrt_report_last_complete( $interval );
}

/** The Reports screen. */
function rrt_report_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$s = rrt_report_settings();

	/* One archived report on its own. */
	$view = isset( $_GET['view'] ) ? absint( $_GET['view'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
	if ( $view ) {
		rrt_dash_header( __( 'Foxfire Studio', 'foxfire-studio' ), 'rrt-reports', null, array(), false );
		$html = rrt_report_archived_html( $view );
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=rrt-reports' ) ) . '">' . esc_html__( '← All reports', 'foxfire-studio' ) . '</a></p>';
		if ( '' === $html ) {
			rrt_dash_empty( __( 'That report is not in the archive.', 'foxfire-studio' ) );
		} else {
			echo '<iframe class="rrt-report-frame" title="' . esc_attr__( 'Archived report', 'foxfire-studio' ) . '" sandbox srcdoc="' . esc_attr( $html ) . '"></iframe>';
		}
		echo '</div>';
		return;
	}

	rrt_dash_header( __( 'Foxfire Studio', 'foxfire-studio' ), 'rrt-reports', null, array(), false );

	/* Messages after a save or a send. */
	$msg = isset( $_GET['rrt_msg'] ) ? sanitize_key( wp_unslash( $_GET['rrt_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$err = isset( $_GET['rrt_err'] ) ? sanitize_text_field( wp_unslash( $_GET['rrt_err'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$bad = isset( $_GET['rrt_bad'] ) ? sanitize_text_field( wp_unslash( $_GET['rrt_bad'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	if ( 'saved' === $msg ) {
		echo '<div class="notice notice-success"><p>' . esc_html__( 'Saved.', 'foxfire-studio' ) . '</p></div>';
	} elseif ( 'sent' === $msg ) {
		echo '<div class="notice notice-success"><p>' . esc_html( sprintf( /* translators: %s: email addresses. */ __( 'Sent to %s. It is in the list of past reports below.', 'foxfire-studio' ), implode( ', ', rrt_report_recipients( $s ) ) ) ) . '</p></div>';
	} elseif ( 'failed' === $msg ) {
		echo '<div class="notice notice-error"><p>' . ( '' !== $err ? esc_html( sprintf( /* translators: %s: error message. */ __( 'The email could not be sent: %s', 'foxfire-studio' ), $err ) ) : esc_html__( 'The email could not be sent.', 'foxfire-studio' ) ) . ' ' . esc_html__( 'Check the site\'s mail setup (an SMTP plugin is the usual fix).', 'foxfire-studio' ) . '</p></div>';
	}
	if ( '' !== $bad ) {
		echo '<div class="notice notice-warning"><p>' . esc_html( sprintf( /* translators: %s: what was typed. */ __( 'These were left out because they are not email addresses: %s', 'foxfire-studio' ), $bad ) ) . '</p></div>';
	}

	echo '<h2>' . esc_html__( 'Reports', 'foxfire-studio' ) . '</h2>';
	echo '<p class="rrt-lede">' . esc_html__( 'An email that says how your readings did, with the same numbers as the dashboard. It holds totals only, never anything about one visitor.', 'foxfire-studio' ) . '</p>';

	/* Status. */
	$last = rrt_report_archive( 1 );
	echo '<div class="rrt-report-status">';
	if ( $s['on'] ) {
		$due  = rrt_report_next_due( $s );
		$next = rrt_report_current( $s['interval'] );
		echo '<p><strong>' . esc_html__( 'Next report:', 'foxfire-studio' ) . '</strong> ' . esc_html( sprintf( /* translators: 1: date, 2: time, 3: period covered, 4: email addresses. */ __( '%1$s around %2$s, covering %3$s, to %4$s.', 'foxfire-studio' ), wp_date( __( 'l, F j, Y', 'foxfire-studio' ), $due->getTimestamp() ), wp_date( get_option( 'time_format' ), $due->getTimestamp() ), rrt_report_period_label( $next ), implode( ', ', rrt_report_recipients( $s ) ) ) ) . '</p>';
	} else {
		echo '<p><strong>' . esc_html__( 'Scheduled reports are off.', 'foxfire-studio' ) . '</strong> ' . esc_html__( 'Preview and Send now still work.', 'foxfire-studio' ) . '</p>';
	}
	if ( $last ) {
		$l = $last[0];
		echo '<p>' . esc_html( sprintf( 'sent' === $l['status'] ? /* translators: 1: when, 2: first day, 3: last day. */ __( 'Last sent: %1$s, %2$s to %3$s.', 'foxfire-studio' ) : /* translators: 1: when, 2: first day, 3: last day. */ __( 'Last sent: %1$s, %2$s to %3$s (failed).', 'foxfire-studio' ), wp_date( __( 'F j, Y', 'foxfire-studio' ) . ', ' . get_option( 'time_format' ), strtotime( $l['sent_at'] . ' UTC' ) ), rrt_dash_day( $l['period_from'] ), rrt_dash_day( $l['period_to'], 'M j, Y' ) ) ) . '</p>';
	}
	echo '</div>';

	/* Cron health. */
	$c = rrt_report_cron_health();
	if ( 'late' === $c['state'] ) {
		$cmd = 'curl -s ' . esc_url_raw( site_url( 'wp-cron.php?doing_wp_cron' ) ) . ' > /dev/null 2>&1';
		echo '<div class="notice notice-warning inline rrt-cron"><p><strong>' . esc_html__( 'Scheduled jobs are running late', 'foxfire-studio' ) . '</strong> ' . esc_html( sprintf( /* translators: %s: how long, such as 2 hours. */ __( '(about %s behind).', 'foxfire-studio' ), human_time_diff( time() - $c['late_by'], time() ) ) ) . ' ';
		if ( $c['server'] ) {
			echo esc_html__( 'This site has DISABLE_WP_CRON set, so it relies on a cron job on the server, and that job does not seem to be running. Check it in your hosting control panel.', 'foxfire-studio' ) . '</p>';
		} else {
			echo esc_html__( 'WordPress only runs them when someone visits the site, so on a quiet day a report can arrive hours late. To fix it, in this order:', 'foxfire-studio' ) . '</p><ol>';
			echo '<li>' . esc_html__( 'In your hosting control panel, add a cron job that runs every 15 minutes:', 'foxfire-studio' ) . '<br><code>' . esc_html( $cmd ) . '</code></li>';
			echo '<li>' . wp_kses( sprintf( /* translators: %s: the file name wp-config.php. */ __( 'Once it is running, add this line to %s, above "That\'s all, stop editing":', 'foxfire-studio' ), '<code>wp-config.php</code>' ), array( 'code' => array() ) ) . '<br><code>define( \'DISABLE_WP_CRON\', true );</code></li>';
			echo '</ol><p>' . esc_html__( 'Doing step 2 before step 1 would stop scheduled jobs altogether.', 'foxfire-studio' ) . '</p>';
		}
		echo '</div>';
	} else {
		echo '<p class="description rrt-cron-ok">' . ( $c['server'] ? esc_html__( 'Scheduled jobs are running on time, from a cron job on the server.', 'foxfire-studio' ) : esc_html__( 'Scheduled jobs are running on time. WordPress runs them when the site gets visits; a cron job on the server makes them punctual on quiet days (see SETUP.md).', 'foxfire-studio' ) ) . '</p>';
	}

	/* Settings form. */
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="rrt-report-form">';
	wp_nonce_field( 'rrt_report_save' );
	echo '<input type="hidden" name="action" value="rrt_report_save">';
	echo '<table class="form-table" role="presentation"><tbody>';
	echo '<tr><th scope="row">' . esc_html__( 'Scheduled reports', 'foxfire-studio' ) . '</th><td><label><input type="checkbox" name="r[on]" value="1"' . checked( $s['on'], true, false ) . '> ' . esc_html__( 'Send the report by email on a schedule', 'foxfire-studio' ) . '</label></td></tr>';
	echo '<tr><th scope="row"><label for="rrt-r-interval">' . esc_html__( 'How often', 'foxfire-studio' ) . '</label></th><td><select id="rrt-r-interval" name="r[interval]">';
	foreach ( rrt_report_intervals() as $k => $v ) {
		echo '<option value="' . esc_attr( $k ) . '"' . selected( $s['interval'], $k, false ) . '>' . esc_html( $v ) . '</option>';
	}
	echo '</select></td></tr>';
	echo '<tr><th scope="row"><label for="rrt-r-hour">' . esc_html__( 'Time of day', 'foxfire-studio' ) . '</label></th><td><select id="rrt-r-hour" name="r[hour]">';
	for ( $i = 0; $i < 24; $i++ ) {
		echo '<option value="' . (int) $i . '"' . selected( $s['hour'], $i, false ) . '>' . esc_html( wp_date( get_option( 'time_format' ), ( new DateTimeImmutable( 'today', wp_timezone() ) )->setTime( $i, 0 )->getTimestamp() ) ) . '</option>';
	}
	echo '</select><p class="description">' . esc_html( sprintf( /* translators: %s: time zone. */ __( 'In the site\'s time zone, %s. The email goes out at the first scheduled run after this time.', 'foxfire-studio' ), wp_timezone_string() ) ) . '</p></td></tr>';
	echo '<tr><th scope="row"><label for="rrt-r-to">' . esc_html__( 'Send to', 'foxfire-studio' ) . '</label></th><td><textarea id="rrt-r-to" name="r[recipients]" rows="3" cols="40" class="regular-text" placeholder="' . esc_attr( get_option( 'admin_email' ) ) . '">' . esc_textarea( implode( "\n", $s['recipients'] ) ) . '</textarea><p class="description">' . esc_html( sprintf( /* translators: %s: the admin email address. */ __( 'One address per line, up to 10. Leave empty to use the site\'s admin email (%s). The sender is the site\'s own mail setup.', 'foxfire-studio' ), get_option( 'admin_email' ) ) ) . '</p></td></tr>';
	echo '<tr><th scope="row">' . esc_html__( 'Include', 'foxfire-studio' ) . '</th><td><fieldset><p>' . esc_html__( 'The headline numbers are always included.', 'foxfire-studio' ) . '</p>';
	foreach ( rrt_report_sections() as $k => $v ) {
		echo '<label><input type="checkbox" name="r[sections][]" value="' . esc_attr( $k ) . '"' . checked( in_array( $k, $s['sections'], true ), true, false ) . '> ' . esc_html( $v ) . '</label><br>';
	}
	echo '</fieldset></td></tr>';
	echo '<tr><th scope="row">' . esc_html__( 'Attachments', 'foxfire-studio' ) . '</th><td><label><input type="checkbox" name="r[csv]" value="1"' . checked( $s['csv'], true, false ) . '> ' . esc_html__( 'Attach the period\'s readings and sources as CSV files', 'foxfire-studio' ) . '</label></td></tr>';
	echo '</tbody></table>';
	submit_button( __( 'Save', 'foxfire-studio' ) );
	echo '</form>';

	/* Preview and Send now. */
	$which = isset( $_GET['which'] ) && 'sofar' === $_GET['which'] ? 'sofar' : 'last'; // phpcs:ignore WordPress.Security.NonceVerification
	$p     = rrt_report_pick_period( $which, $s['interval'] );
	echo '<h2 id="preview">' . esc_html__( 'Preview', 'foxfire-studio' ) . '</h2>';
	echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '#preview" class="rrt-report-which"><input type="hidden" name="page" value="rrt-reports"><label>' . esc_html__( 'Period', 'foxfire-studio' ) . ' <select name="which">';
	echo '<option value="last"' . selected( $which, 'last', false ) . '>' . esc_html( sprintf( /* translators: %s: period. */ __( 'Last full period: %s', 'foxfire-studio' ), rrt_report_period_label( rrt_report_last_complete( $s['interval'] ) ) ) ) . '</option>';
	echo '<option value="sofar"' . selected( $which, 'sofar', false ) . '>' . esc_html( sprintf( /* translators: %s: period. */ __( 'This period so far: %s', 'foxfire-studio' ), rrt_report_period_label( rrt_report_so_far( $s['interval'] ) ) ) ) . '</option>';
	echo '</select></label> <button class="button">' . esc_html__( 'Show', 'foxfire-studio' ) . '</button></form>';

	rrt_dash_freshen();
	$mail = rrt_report_render( rrt_report_data( $p ), $s['sections'] );
	echo '<p><strong>' . esc_html__( 'Subject:', 'foxfire-studio' ) . '</strong> ' . esc_html( $mail['subject'] ) . '</p>';
	echo '<div class="rrt-report-previews"><figure><figcaption>' . esc_html__( 'As on a computer', 'foxfire-studio' ) . '</figcaption><iframe class="rrt-report-frame" title="' . esc_attr__( 'Report preview', 'foxfire-studio' ) . '" sandbox srcdoc="' . esc_attr( $mail['html'] ) . '"></iframe></figure>';
	echo '<figure class="rrt-phone"><figcaption>' . esc_html__( 'As on a phone', 'foxfire-studio' ) . '</figcaption><iframe class="rrt-report-frame is-phone" title="' . esc_attr__( 'Report preview at phone width', 'foxfire-studio' ) . '" sandbox srcdoc="' . esc_attr( $mail['html'] ) . '"></iframe></figure></div>';

	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="rrt-report-send">';
	wp_nonce_field( 'rrt_report_send' );
	echo '<input type="hidden" name="action" value="rrt_report_send"><input type="hidden" name="which" value="' . esc_attr( $which ) . '">';
	echo '<button class="button button-primary">' . esc_html__( 'Send this report now', 'foxfire-studio' ) . '</button> <span class="description">' . esc_html( sprintf( /* translators: %s: email addresses. */ __( 'to %s. Sending now does not change the schedule.', 'foxfire-studio' ), implode( ', ', rrt_report_recipients( $s ) ) ) ) . '</span></form>';

	/* Archive. */
	echo '<h2>' . esc_html__( 'Past reports', 'foxfire-studio' ) . '</h2>';
	$rows = rrt_report_archive( 50 );
	if ( ! $rows ) {
		rrt_dash_empty( __( 'No reports have been sent yet.', 'foxfire-studio' ) );
	} else {
		echo '<table class="widefat striped rrt-table"><thead><tr><th>' . esc_html__( 'Sent', 'foxfire-studio' ) . '</th><th>' . esc_html__( 'Covering', 'foxfire-studio' ) . '</th><th>' . esc_html__( 'How', 'foxfire-studio' ) . '</th><th>' . esc_html__( 'To', 'foxfire-studio' ) . '</th><th>' . esc_html__( 'Plays', 'foxfire-studio' ) . '</th><th>' . esc_html__( 'Status', 'foxfire-studio' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$sum = json_decode( (string) $r['summary'], true );
			$how = array(
				'schedule' => __( 'On schedule', 'foxfire-studio' ),
				'manual'   => __( 'Send now', 'foxfire-studio' ),
				'test'     => __( 'Test', 'foxfire-studio' ),
			);
			echo '<tr><td>' . esc_html( wp_date( 'M j, Y ' . get_option( 'time_format' ), strtotime( $r['sent_at'] . ' UTC' ) ) ) . '</td>';
			echo '<td>' . esc_html( sprintf( false !== strpos( $r['kind'], 'partial' ) ? /* translators: 1: first day, 2: last day. */ __( '%1$s to %2$s (so far)', 'foxfire-studio' ) : /* translators: 1: first day, 2: last day. */ __( '%1$s to %2$s', 'foxfire-studio' ), rrt_dash_day( $r['period_from'] ), rrt_dash_day( $r['period_to'], 'M j, Y' ) ) ) . '</td>';
			echo '<td>' . esc_html( isset( $how[ $r['trigger_by'] ] ) ? $how[ $r['trigger_by'] ] : $r['trigger_by'] ) . '</td>';
			echo '<td>' . esc_html( $r['recipients'] ) . '</td>';
			echo '<td>' . esc_html( is_array( $sum ) && isset( $sum['plays'] ) ? number_format_i18n( $sum['plays'] ) : '–' ) . '</td>';
			echo '<td>' . esc_html( 'sent' === $r['status'] ? __( 'Sent', 'foxfire-studio' ) : ( '' !== $r['error'] ? sprintf( /* translators: %s: error message. */ __( 'Failed: %s', 'foxfire-studio' ), $r['error'] ) : __( 'Failed', 'foxfire-studio' ) ) ) . '</td>';
			echo '<td><a href="' . esc_url(
				add_query_arg(
					array(
						'page' => 'rrt-reports',
						'view' => (int) $r['id'],
					),
					admin_url( 'admin.php' )
				)
			) . '">View</a></td></tr>';
		}
		echo '</tbody></table>';
	}
	echo '</div>';
}

add_action(
	'admin_post_rrt_report_save',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'foxfire-studio' ) );
		}
		check_admin_referer( 'rrt_report_save' );
		$in  = isset( $_POST['r'] ) && is_array( $_POST['r'] ) ? wp_unslash( $_POST['r'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$new = rrt_report_clean( $in );
		$bad = $new['bad'];
		rrt_report_save( $new );
		$args = array(
			'page'    => 'rrt-reports',
			'rrt_msg' => 'saved',
		);
		if ( $bad ) {
			$args['rrt_bad'] = rawurlencode( implode( ', ', array_slice( $bad, 0, 5 ) ) );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
);

add_action(
	'admin_post_rrt_report_send',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'foxfire-studio' ) );
		}
		check_admin_referer( 'rrt_report_send' );
		$which = isset( $_POST['which'] ) && 'sofar' === $_POST['which'] ? 'sofar' : 'last';
		$r     = rrt_report_send( rrt_report_pick_period( $which ), 'manual' );
		$args  = array(
			'page'    => 'rrt-reports',
			'which'   => $which,
			'rrt_msg' => $r['ok'] ? 'sent' : 'failed',
		);
		if ( ! $r['ok'] && '' !== $r['error'] ) {
			$args['rrt_err'] = rawurlencode( substr( $r['error'], 0, 200 ) );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
);
