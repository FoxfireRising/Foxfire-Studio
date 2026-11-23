<?php
/**
 * The Download counting section on Foxfire Studio, Podcast feed (1.24.0).
 *
 * How files are sent (with Check counting), shared networks, measurement
 * prefixes, the method page, and the lists in use. A form of its own below
 * the Podcast feed form, as Storage offload has; the Count downloads switch
 * itself stays in the Podcast feed form.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The section. */
function rrt_dl_section() {
	$s     = rrt_dl_settings();
	$c     = (array) $s['check'];
	$srv   = rrt_dl_server();
	$used  = rrt_dl_handoff();
	$net   = rrt_net_info();
	$names = array(
		'litespeed' => __( 'LiteSpeed\'s hand-off', 'foxfire-studio' ),
		'apache'    => __( 'Apache\'s X-Sendfile', 'foxfire-studio' ),
		'nginx'     => __( 'nginx\'s X-Accel-Redirect', 'foxfire-studio' ),
	);
	$boot  = array(
		'rest'  => esc_url_raw( rest_url( 'rrt/v1/dl/' ) ),
		'nonce' => wp_create_nonce( 'wp_rest' ),
	);
	wp_enqueue_script( 'rrt-dlcheck', RRT_URL . 'assets/js/dlcheck.js', array( 'wp-i18n' ), RRT_VERSION . '.' . (int) @filemtime( RRT_DIR . 'assets/js/dlcheck.js' ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	wp_set_script_translations( 'rrt-dlcheck', 'foxfire-studio', rrt_languages_dir() );
	?>
	<h2 id="rrt-dl"><?php esc_html_e( 'Download counting', 'foxfire-studio' ); ?></h2>
	<?php if ( isset( $_GET['dlsaved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Download counting saved.', 'foxfire-studio' ); ?></p></div>
	<?php endif; ?>
	<p><?php
	printf(
		/* translators: %s: a version number. */
		esc_html__( 'With Count downloads on (above), downloads are counted following the IAB Tech Lab Podcast Technical Measurement Guidelines, version %s. Following them is open to anyone; an IAB certificate is a paid audit for companies, so Foxfire Studio never claims one.', 'foxfire-studio' ),
		esc_html( RRT_DL_IAB )
	);
	?></p>
	<form id="rrt-dl-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-rrt-dl="<?php echo esc_attr( wp_json_encode( $boot ) ); ?>">
		<input type="hidden" name="action" value="rrt_dl_save">
		<?php wp_nonce_field( 'rrt_dl_save' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="rrt-dl-send"><?php esc_html_e( 'How files are sent', 'foxfire-studio' ); ?></label></th>
				<td>
					<select id="rrt-dl-send" name="send">
						<option value="auto" <?php selected( $s['send'], 'auto' ); ?>><?php echo esc_html( '' !== $srv ? sprintf( /* translators: %s: a server feature, such as LiteSpeed's hand-off. */ __( 'Automatic (%s)', 'foxfire-studio' ), $names[ $srv ] ) : __( 'Automatic', 'foxfire-studio' ) ); ?></option>
						<option value="apache" <?php selected( $s['send'], 'apache' ); ?>><?php esc_html_e( 'Apache with X-Sendfile', 'foxfire-studio' ); ?></option>
						<option value="nginx" <?php selected( $s['send'], 'nginx' ); ?>><?php esc_html_e( 'nginx with X-Accel-Redirect', 'foxfire-studio' ); ?></option>
						<option value="redirect" <?php selected( $s['send'], 'redirect' ); ?>><?php esc_html_e( 'Redirect to the file', 'foxfire-studio' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'With a hand-off, the web server sends the file itself after counting, so every piece an app asks for passes the counter. With a redirect, some apps (Apple\'s player among them) fetch the rest of a file straight from the file\'s own address, and those pieces are not seen. Files with an outside copy (Storage offload) are always sent with a redirect.', 'foxfire-studio' ); ?></p>
					<?php if ( 'nginx' === $s['send'] ) : ?>
						<p class="description"><?php esc_html_e( 'nginx needs these lines in the site\'s server block (ask your host if you cannot edit it):', 'foxfire-studio' ); ?></p>
						<pre class="rrt-dl-nginx"><?php echo esc_html( rrt_dl_nginx_lines() ); ?></pre>
					<?php endif; ?>
					<p><button type="button" class="button" data-rrt-dl-check><?php esc_html_e( 'Check counting', 'foxfire-studio' ); ?></button> <span data-rrt-dl-status><?php
					if ( '' !== $used ) {
						/* translators: 1: a server feature, 2: a date and time. */
						echo esc_html( sprintf( __( 'In use: %1$s, checked %2$s.', 'foxfire-studio' ), $names[ $used ], wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $c['at'] ) ) );
						if ( rrt_dl_htaccess_has() ) {
							echo ' ' . esc_html__( 'The Range header reaches counting through Foxfire Studio\'s lines in .htaccess.', 'foxfire-studio' );
						}
					} elseif ( ! empty( $c['at'] ) ) {
						echo esc_html( sprintf( /* translators: %s: why. */ __( 'In use: a redirect. %s', 'foxfire-studio' ), isset( $c['why'] ) ? $c['why'] : '' ) );
					} else {
						esc_html_e( 'In use: a redirect, until Check counting succeeds here.', 'foxfire-studio' );
					}
					?></span></p>
					<p class="description" data-rrt-dl-fix><?php echo esc_html( isset( $c['fix'] ) ? (string) $c['fix'] : '' ); ?></p>
					<?php if ( ! empty( $c['ok'] ) && ( ( isset( $c['ranges'] ) && ! $c['ranges'] ) || ( isset( $c['fresh'] ) && ! $c['fresh'] ) ) ) : ?>
						<div class="notice notice-warning inline"><p><?php echo esc_html( isset( $c['why'] ) ? (string) $c['why'] : '' ); ?> <?php esc_html_e( 'These lines at the top of the site\'s .htaccess file hand the Range header to counting and ask any CDN in front of the site to keep no copy of the counting addresses:', 'foxfire-studio' ); ?></p><pre class="rrt-dl-ht"><?php echo esc_html( implode( "\n", rrt_dl_htaccess_lines() ) ); ?></pre></div>
					<?php endif; ?>
					<ul class="rrt-dl-steps" data-rrt-dl-steps><?php
					foreach ( isset( $c['steps'] ) ? (array) $c['steps'] : array() as $step ) {
						echo '<li class="' . ( $step['ok'] ? 'is-ok' : 'is-bad' ) . '">' . ( $step['ok'] ? '&#10003; ' : '&#10007; ' ) . esc_html( $step['name'] ) . ( '' !== $step['note'] ? ' <span class="description">(' . esc_html( $step['note'] ) . ')</span>' : '' ) . '</li>';
					}
					?></ul>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="rrt-dl-shared"><?php esc_html_e( 'Shared networks', 'foxfire-studio' ); ?></label></th>
				<td>
					<textarea id="rrt-dl-shared" name="shared" rows="3" cols="40" class="code" placeholder="203.0.113.0/24"><?php echo esc_textarea( $s['shared'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Optional. Address ranges where many real listeners share one address, such as an office or a school, one per line. They are left out of the limit of 30 downloads an hour and 100 a day per address. The IAB guidelines ask that such a list is confirmed at least every 90 days.', 'foxfire-studio' ); ?></p>
					<?php if ( rrt_net_shared() ) : ?>
						<p><label><input type="checkbox" name="shared_ok" value="1"> <?php esc_html_e( 'I have checked these are still right', 'foxfire-studio' ); ?></label>
						<?php if ( rrt_net_shared_due() ) : ?>
							<strong><?php esc_html_e( 'Due: more than 90 days since they were confirmed.', 'foxfire-studio' ); ?></strong>
						<?php else : ?>
							<span class="description"><?php echo esc_html( sprintf( /* translators: %s: a date. */ __( 'Last confirmed %s.', 'foxfire-studio' ), wp_date( get_option( 'date_format' ), (int) $s['shared_ok'] ) ) ); ?></span>
						<?php endif; ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Measurement prefixes', 'foxfire-studio' ); ?></th>
				<td>
					<p><label><input type="checkbox" name="op3" value="1" <?php checked( (bool) $s['op3'] ); ?>> <?php esc_html_e( 'Also count with OP3 (op3.dev), the free and open prefix service', 'foxfire-studio' ); ?></label></p>
					<p><label for="rrt-dl-prefixes"><?php esc_html_e( 'Other prefixes, one per line', 'foxfire-studio' ); ?></label><br>
					<textarea id="rrt-dl-prefixes" name="prefixes" rows="2" cols="40" class="code" placeholder="https://dts.podtrac.com/redirect.mp3/"><?php echo esc_textarea( $s['prefixes'] ); ?></textarea></p>
					<p class="description"><?php esc_html_e( 'Off unless you add one. A prefix goes in front of every episode address in the public feed (never in members\' feeds), so the service sees each request before this site does, listeners\' IP addresses included: your privacy policy needs a line naming it (the paragraph in Settings includes one). OP3 shows its numbers publicly on its own site and counts a little differently, so its figures will not match these exactly. Decide before the feed is published: adding or removing a prefix afterwards makes some apps download every episode again.', 'foxfire-studio' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Method page', 'foxfire-studio' ); ?></th>
				<td>
					<?php if ( $s['page'] && get_post( $s['page'] ) && 'trash' !== get_post_status( $s['page'] ) ) : ?>
						<p><a href="<?php echo esc_url( (string) get_edit_post_link( $s['page'] ) ); ?>"><?php esc_html_e( 'How downloads are counted', 'foxfire-studio' ); ?></a> (<?php echo esc_html( 'publish' === get_post_status( $s['page'] ) ? __( 'published', 'foxfire-studio' ) : __( 'draft', 'foxfire-studio' ) ); ?>)</p>
					<?php else : ?>
						<p><button type="submit" name="make_page" value="1" class="button"><?php esc_html_e( 'Make the method page', 'foxfire-studio' ); ?></button></p>
					<?php endif; ?>
					<p class="description"><?php esc_html_e( 'A draft page holding [foxfire_download_method], which writes out how downloads are counted on this site from these settings. Publish it when the feed goes public, so sponsors and listeners can read it.', 'foxfire-studio' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Lists in use', 'foxfire-studio' ); ?></th>
				<td>
					<p><?php
					/* translators: %s: editions of the lists. */
					echo esc_html( sprintf( __( 'Apps and bots: %s (OPAWG and OP3, MIT licence, updated with each release).', 'foxfire-studio' ), rrt_ua_lists_date() ) );
					?><br><?php
					echo esc_html(
						sprintf(
							/* translators: 1: number of IPv4 ranges, 2: number of IPv6 ranges, 3: a date, 4: where it came from. */
							__( 'Data centres and VPNs: %1$s IPv4 and %2$s IPv6 ranges, edition of %3$s, %4$s (X4BNet lists_vpn, MIT licence; IP address data powered by IPLocate.io, CC BY-SA 4.0). Refreshed once a month while counting is on; only the list file is fetched, from GitHub.', 'foxfire-studio' ),
							number_format_i18n( $net['v4'] ),
							number_format_i18n( $net['v6'] ),
							'' !== $net['date'] ? $net['date'] : '?',
							'download' === $net['from'] ? __( 'downloaded', 'foxfire-studio' ) : __( 'the copy in the plugin', 'foxfire-studio' )
						)
					);
					if ( '' !== $net['error'] ) {
						echo ' ' . esc_html( sprintf( /* translators: %s: an error. */ __( 'The last refresh failed: %s', 'foxfire-studio' ), $net['error'] ) );
					}
					?></p>
				</td>
			</tr>
		</table>
		<?php submit_button( __( 'Save download counting', 'foxfire-studio' ), 'primary', 'save_dl' ); ?>
	</form>
	<?php
}

add_action(
	'admin_post_rrt_dl_save',
	function () {
		check_admin_referer( 'rrt_dl_save' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'foxfire-studio' ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification -- checked above.
		$s    = rrt_dl_settings();
		$send = isset( $_POST['send'] ) ? sanitize_key( wp_unslash( $_POST['send'] ) ) : 'auto';
		$s['send'] = in_array( $send, array( 'auto', 'apache', 'nginx', 'redirect' ), true ) ? $send : 'auto';
		$lines     = array();
		foreach ( preg_split( '/[\s,]+/', isset( $_POST['shared'] ) ? (string) wp_unslash( $_POST['shared'] ) : '' ) as $line ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned on this line (esc_url_raw, or each line checked below).
			if ( rrt_net_range( $line ) ) {
				$lines[] = trim( $line );
			}
		}
		$shared = implode( "\n", array_slice( array_unique( $lines ), 0, 50 ) );
		if ( $shared !== $s['shared'] || ! empty( $_POST['shared_ok'] ) ) {
			$s['shared_ok'] = '' === $shared ? 0 : time();
		}
		$s['shared'] = $shared;
		$s['op3']    = ! empty( $_POST['op3'] );
		$pre         = array();
		foreach ( preg_split( '/\r?\n/', isset( $_POST['prefixes'] ) ? (string) wp_unslash( $_POST['prefixes'] ) : '' ) as $p ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned on this line (esc_url_raw, or each line checked below).
			$p = esc_url_raw( trim( $p ), array( 'https' ) );
			if ( '' !== $p && false === stripos( $p, 'op3.dev' ) ) {
				$pre[] = $p;
			}
		}
		$s['prefixes'] = implode( "\n", array_slice( array_unique( $pre ), 0, 5 ) );
		$make          = ! empty( $_POST['make_page'] );
		// phpcs:enable
		rrt_dl_settings_save( $s );
		if ( $make ) {
			rrt_dl_method_page();
		}
		if ( function_exists( 'rrt_podcast_purge' ) ) {
			rrt_podcast_purge();
		}
		wp_safe_redirect( admin_url( 'admin.php?page=rrt-podcast&dlsaved=1#rrt-dl' ) );
		exit;
	}
);
