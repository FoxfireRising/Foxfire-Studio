<?php
/**
 * WP-admin: menu entries, the Teleprompter link on posts, and the settings
 * screen (Settings, then Foxfire Studio).
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'admin_menu',
	function () {
		// Tools, then Teleprompter, opens the teleprompter itself.
		$hook = add_management_page( __( 'Teleprompter', 'foxfire-studio' ), __( 'Teleprompter', 'foxfire-studio' ), 'edit_posts', 'rrt-open', '__return_null' );
		add_action(
			'load-' . $hook,
			function () {
				wp_safe_redirect( rrt_url() );
				exit;
			}
		);
		add_options_page( __( 'Foxfire Studio', 'foxfire-studio' ), __( 'Foxfire Studio', 'foxfire-studio' ), 'manage_options', 'rrt-settings', 'rrt_settings_page' );
	}
);

add_action(
	'admin_init',
	function () {
		register_setting(
			'rrt_settings',
			'rrt_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => 'rrt_sanitize_settings',
				'default'           => array(),
			)
		);
	}
);

/** A Teleprompter link on each row of the posts list. */
function rrt_row_action( $actions, $post ) {
	$cfg = rrt_config();
	if ( in_array( $post->post_type, $cfg['post_types'], true ) && current_user_can( 'edit_post', $post->ID ) ) {
		$actions['rrt'] = '<a href="' . esc_url( rrt_url( $post->ID ) ) . '" target="_blank" rel="noopener">Teleprompter</a>';
	}
	return $actions;
}
add_filter( 'post_row_actions', 'rrt_row_action', 10, 2 );
add_filter( 'page_row_actions', 'rrt_row_action', 10, 2 );

/** Settings and Open links on the Plugins screen. */
add_filter(
	'plugin_action_links_' . plugin_basename( RRT_FILE ),
	function ( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'options-general.php?page=rrt-settings' ) ) . '">Settings</a>',
			'<a href="' . esc_url( rrt_url() ) . '" target="_blank" rel="noopener">Open</a>'
		);
		return $links;
	}
);

/**
 * The shared downloader for the studio's files (1.29.0): its script, used by
 * Settings and by the setup wizard.
 */
function rrt_files_enqueue() {
	wp_enqueue_script( 'rrt-files', RRT_URL . 'assets/js/files.js', array( 'wp-i18n' ), RRT_VERSION . '.' . (int) @filemtime( RRT_DIR . 'assets/js/files.js' ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	wp_set_script_translations( 'rrt-files', 'foxfire-studio', rrt_languages_dir() );
}

/** What the downloader needs: where to ask, and the nonces. */
function rrt_files_boot() {
	return array(
		'ajax'    => admin_url( 'admin-ajax.php' ),
		'whisper' => wp_create_nonce( 'rrt_whisper_fetch' ),
		'local'   => wp_create_nonce( 'rrt_local_fetch' ),
	);
}

/** Human file sizes. */
function rrt_size( $bytes ) {
	if ( $bytes >= 1048576 ) {
		return number_format_i18n( $bytes / 1048576, 1 ) . ' MB';
	}
	if ( $bytes >= 1024 ) {
		return number_format_i18n( $bytes / 1024 ) . ' KB';
	}
	return $bytes ? $bytes . ' bytes' : 'missing';
}

/** The settings screen. */
function rrt_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$s       = rrt_settings();
	$presets = rrt_presets();
	$opt     = 'rrt_settings';
	$wst     = rrt_whisper_status();
	$wsm     = rrt_whisper_status( 'small' );
	$wfo     = rrt_whisper_status( 'follow' );
	$wmu     = rrt_whisper_status( 'multi' );
	$lst     = rrt_local_status( rrt_config() );
	$types   = get_post_types( array( 'show_ui' => true ), 'objects' );
	unset( $types['attachment'], $types['wp_block'], $types['wp_navigation'], $types['wp_template'], $types['wp_template_part'], $types['wp_font_family'], $types['wp_font_face'] );
	?>
	<div class="wrap rrt-wrap">
		<h1><?php esc_html_e( 'Foxfire Studio', 'foxfire-studio' ); ?></h1>
		<p>
			<?php do_action( 'rrt_settings_top' ); ?>
			<a class="button button-primary" href="<?php echo esc_url( rrt_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open the teleprompter', 'foxfire-studio' ); ?></a>
			<span class="rrt-hint"><?php esc_html_e( 'Each person\'s own slider and checkbox changes are remembered in their browser. These are the starting values.', 'foxfire-studio' ); ?></span>
		</p>

		<form method="post" action="options.php" class="rrt-preset-form">
			<?php settings_fields( 'rrt_settings' ); ?>
			<h2><?php esc_html_e( 'Look', 'foxfire-studio' ); ?></h2>
			<p>
				<label for="rrt-apply"><?php esc_html_e( 'Start from a preset:', 'foxfire-studio' ); ?></label>
				<select id="rrt-apply" name="<?php echo esc_attr( $opt ); ?>[apply_preset]">
					<?php foreach ( $presets as $key => $p ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['preset'], $key ); ?>><?php echo esc_html( $p['label'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php submit_button( __( 'Apply preset', 'foxfire-studio' ), 'secondary', 'rrt-apply-btn', false ); ?>
				<span class="rrt-hint"><?php esc_html_e( 'Replaces the wording, colours and fonts below. Reading defaults, content types, sound and Whisper settings are kept.', 'foxfire-studio' ); ?></span>
			</p>
			<p><?php esc_html_e( 'Current look:', 'foxfire-studio' ); ?> <strong><?php echo esc_html( isset( $presets[ $s['preset'] ] ) ? $presets[ $s['preset'] ]['label'] : __( 'Custom', 'foxfire-studio' ) ); ?></strong></p>
		</form>

		<form method="post" action="options.php">
			<?php settings_fields( 'rrt_settings' ); ?>
			<input type="hidden" name="<?php echo esc_attr( $opt ); ?>[preset]" value="custom">

			<h2><?php esc_html_e( 'Wording', 'foxfire-studio' ); ?></h2>
			<table class="form-table" role="presentation">
				<?php
				$words = array(
					'title'   => __( 'Name in the control bar and browser tab', 'foxfire-studio' ),
					'welcome' => __( 'Welcome heading', 'foxfire-studio' ),
					'intro'   => __( 'Welcome text', 'foxfire-studio' ),
					'choose'  => __( 'Post menu prompt', 'foxfire-studio' ),
					'paste'   => __( 'Address box hint', 'foxfire-studio' ),
					'listen'  => __( 'Label on the listening player', 'foxfire-studio' ),
					'tagline' => __( 'Short line in the corner of the teleprompter (leave empty for none)', 'foxfire-studio' ),
				);
				foreach ( $words as $k => $label ) :
					?>
					<tr>
						<th scope="row"><label for="rrt-text-<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></label></th>
						<td><input class="regular-text" id="rrt-text-<?php echo esc_attr( $k ); ?>" name="<?php echo esc_attr( $opt ); ?>[text][<?php echo esc_attr( $k ); ?>]" value="<?php echo esc_attr( $s['text'][ $k ] ); ?>"></td>
					</tr>
				<?php endforeach; ?>
			</table>

			<h2><?php esc_html_e( 'Teleprompter look', 'foxfire-studio' ); ?></h2>
			<?php
			$rrt_pal  = rrt_theme_palette();
			$rrt_auto = rrt_theme_auto( $rrt_pal );
			$rrt_tok  = rrt_theme_tokens( $s );
			$rrt_by   = array();
			foreach ( $rrt_pal as $rrt_c ) {
				$rrt_by[ $rrt_c['slug'] ] = $rrt_c;
			}
			?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Look', 'foxfire-studio' ); ?></th>
					<td>
						<fieldset class="rrt-look">
							<label class="rrt-check"><input type="radio" name="<?php echo esc_attr( $opt ); ?>[look]" value="studio" <?php checked( 'studio', rrt_look( $s ) ); ?>> <strong><?php esc_html_e( 'Foxfire Studio:', 'foxfire-studio' ); ?></strong> <?php esc_html_e( 'the night sky, the fox and gold ornaments, in the colours under Colours below', 'foxfire-studio' ); ?></label><br>
							<label class="rrt-check"><input type="radio" name="<?php echo esc_attr( $opt ); ?>[look]" value="theme" <?php checked( 'theme', rrt_look( $s ) ); ?>> <strong><?php esc_html_e( 'My WordPress theme:', 'foxfire-studio' ); ?></strong> <?php esc_html_e( 'no artwork or ornaments, in your theme\'s colours, chosen below', 'foxfire-studio' ); ?></label>
						</fieldset>
						<p class="description"><?php esc_html_e( 'The layout, the controls and the review are the same in both. Either way the fonts come from Fonts below.', 'foxfire-studio' ); ?></p>
					</td>
				</tr>
				<?php
				$rrt_iv  = isset( $s['intro'] ) ? (int) $s['intro'] : 0;
				$rrt_ivu = $rrt_iv ? (string) wp_get_attachment_url( $rrt_iv ) : '';
				wp_enqueue_media();
				?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Intro video', 'foxfire-studio' ); ?></th>
					<td>
						<input type="hidden" id="rrt-intro" name="<?php echo esc_attr( $opt ); ?>[intro]" value="<?php echo (int) $rrt_iv; ?>">
						<span id="rrt-intro-view"><?php echo $rrt_ivu ? '<video src="' . esc_url( $rrt_ivu ) . '" muted playsinline preload="metadata" style="width:240px;border-radius:6px;vertical-align:middle"></video>' : esc_html__( 'None', 'foxfire-studio' ); ?></span>
						<button type="button" class="button" id="rrt-intro-pick"><?php esc_html_e( 'Choose', 'foxfire-studio' ); ?></button>
						<button type="button" class="button" id="rrt-intro-none"<?php echo $rrt_iv ? '' : ' hidden'; ?>><?php esc_html_e( 'Remove', 'foxfire-studio' ); ?></button>
						<p class="description"><?php esc_html_e( 'A short video from the media library, played when the teleprompter opens, once in each new tab. Anyone can skip it, or switch it off for their browser with Play the intro in the studio\'s Controls panel. MP4 (H.264) plays in every browser; keep it small so the studio is not kept waiting.', 'foxfire-studio' ); ?></p>
					</td>
				</tr>
				<?php foreach ( rrt_theme_roles() as $rrt_role => $rrt_label ) : ?>
					<?php
					$rrt_cur      = isset( $s['theme_colors'][ $rrt_role ] ) ? $s['theme_colors'][ $rrt_role ] : 'auto';
					$rrt_auto_s   = $rrt_auto[ $rrt_role ];
					$rrt_auto_txt = $rrt_auto_s && isset( $rrt_by[ $rrt_auto_s ] ) ? $rrt_by[ $rrt_auto_s ]['name'] : __( 'a readable default', 'foxfire-studio' );
					?>
					<tr class="rrt-theme-row">
						<th scope="row"><label for="rrt-tc-<?php echo esc_attr( $rrt_role ); ?>"><?php echo esc_html( $rrt_label ); ?></label></th>
						<td>
							<span class="rrt-swatch" aria-hidden="true" style="background:<?php echo esc_attr( $rrt_tok[ $rrt_role ] ); ?>"></span>
							<select id="rrt-tc-<?php echo esc_attr( $rrt_role ); ?>" class="rrt-tc" data-role="<?php echo esc_attr( $rrt_role ); ?>" name="<?php echo esc_attr( $opt ); ?>[theme_colors][<?php echo esc_attr( $rrt_role ); ?>]">
								<option value="auto" data-color="<?php echo esc_attr( $rrt_auto_s && isset( $rrt_by[ $rrt_auto_s ] ) ? $rrt_by[ $rrt_auto_s ]['color'] : $rrt_tok[ $rrt_role ] ); ?>" <?php selected( 'auto', $rrt_cur ); ?>><?php echo esc_html( sprintf( /* translators: %s: the colour chosen automatically. */ __( 'Automatic (%s)', 'foxfire-studio' ), $rrt_auto_txt ) ); ?></option>
								<?php
								$rrt_group = '';
								foreach ( $rrt_pal as $rrt_c ) :
									if ( $rrt_c['group'] !== $rrt_group ) :
										if ( '' !== $rrt_group ) :
											echo '</optgroup>';
										endif;
										$rrt_group = $rrt_c['group'];
										echo '<optgroup label="' . esc_attr( $rrt_group ) . '">';
									endif;
									?>
									<option value="<?php echo esc_attr( $rrt_c['slug'] ); ?>" data-color="<?php echo esc_attr( $rrt_c['color'] ); ?>" <?php selected( $rrt_c['slug'], $rrt_cur ); ?>><?php echo esc_html( $rrt_c['name'] . ' (' . $rrt_c['color'] . ')' ); ?></option>
								<?php endforeach; ?>
								<?php echo '' !== $rrt_group ? '</optgroup>' : ''; ?>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
				<tr class="rrt-theme-row">
					<th scope="row"><?php esc_html_e( 'Preview', 'foxfire-studio' ); ?></th>
					<td>
						<div class="rrt-look-preview" id="rrt-look-preview" role="img" aria-label="<?php esc_attr_e( 'A sample of the teleprompter in these colours: words already read, the word being read, words ahead, a button and the progress line', 'foxfire-studio' ); ?>" style="background:<?php echo esc_attr( $rrt_tok['bg'] ); ?>;color:<?php echo esc_attr( $rrt_tok['text'] ); ?>">
							<span class="rrt-look-said"><?php esc_html_e( 'The tide came in at', 'foxfire-studio' ); ?></span> <span class="rrt-look-now" style="color:<?php echo esc_attr( $rrt_tok['accent'] ); ?>"><?php esc_html_e( 'dusk,', 'foxfire-studio' ); ?></span> <span class="rrt-look-ahead" style="opacity:<?php echo esc_attr( rrt_a11y_ahead_pct( $rrt_tok ) / 100 ); ?>"><?php esc_html_e( 'silver and slow', 'foxfire-studio' ); ?></span>
							<span class="rrt-look-btn" style="background:<?php echo esc_attr( $rrt_tok['accent'] ); ?>;color:<?php echo esc_attr( $rrt_tok['bg'] ); ?>"><?php esc_html_e( 'Begin', 'foxfire-studio' ); ?></span>
							<span class="rrt-look-bar" style="background:linear-gradient(90deg,<?php echo esc_attr( $rrt_tok['accent2'] ); ?>,<?php echo esc_attr( $rrt_tok['accent'] ); ?>)"></span>
						</div>
						<?php if ( empty( $rrt_pal ) ) : ?>
							<p class="description"><?php esc_html_e( 'Your theme does not list any colours for the editor, so readable defaults are used.', 'foxfire-studio' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'Each choice stays linked to your theme\'s colour, so changing that colour in your theme changes the teleprompter too. If the text would be hard to read on the background, black or white is used instead.', 'foxfire-studio' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Colours', 'foxfire-studio' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Used by the player on your posts, by the Foxfire Studio look, by clips and by the share page. The My WordPress theme look takes the teleprompter\'s colours from your theme instead.', 'foxfire-studio' ); ?></p>
			<table class="form-table" role="presentation">
				<?php foreach ( rrt_color_labels() as $k => $label ) : ?>
					<tr>
						<th scope="row"><label for="rrt-color-<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></label></th>
						<td><input type="color" id="rrt-color-<?php echo esc_attr( $k ); ?>" name="<?php echo esc_attr( $opt ); ?>[colors][<?php echo esc_attr( $k ); ?>]" value="<?php echo esc_attr( $s['colors'][ $k ] ); ?>"> <code><?php echo esc_html( $s['colors'][ $k ] ); ?></code></td>
					</tr>
				<?php endforeach; ?>
				<?php rrt_a11y_settings_rows( $s['colors'], $opt ); ?>
			</table>

			<h2><?php esc_html_e( 'Fonts', 'foxfire-studio' ); ?></h2>
			<table class="form-table" role="presentation">
				<?php
				$fonts = array(
					'reading'   => __( 'Reading text', 'foxfire-studio' ),
					'headings'  => __( 'Title and headings', 'foxfire-studio' ),
					'interface' => __( 'Buttons and labels', 'foxfire-studio' ),
				);
				foreach ( $fonts as $k => $label ) :
					?>
					<tr>
						<th scope="row"><label for="rrt-font-<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></label></th>
						<td><input class="regular-text code" id="rrt-font-<?php echo esc_attr( $k ); ?>" name="<?php echo esc_attr( $opt ); ?>[fonts][<?php echo esc_attr( $k ); ?>]" value="<?php echo esc_attr( $s['fonts'][ $k ] ); ?>"></td>
					</tr>
				<?php endforeach; ?>
				<tr>
					<th scope="row"><label for="rrt-font-url"><?php esc_html_e( 'Google Fonts link', 'foxfire-studio' ); ?></label></th>
					<td>
						<input class="large-text code" id="rrt-font-url" name="<?php echo esc_attr( $opt ); ?>[fonts][google_url]" value="<?php echo esc_attr( $s['fonts']['google_url'] ); ?>">
						<p class="description"><?php esc_html_e( 'Loads the fonts named above. Leave empty to use only fonts already on the reader\'s computer.', 'foxfire-studio' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Reading', 'foxfire-studio' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Starting values', 'foxfire-studio' ); ?></th>
					<td class="rrt-nums">
						<label><?php esc_html_e( 'Pace', 'foxfire-studio' ); ?> <input type="number" min="80" max="220" step="5" name="<?php echo esc_attr( $opt ); ?>[defaults][wpm]" value="<?php echo (int) $s['defaults']['wpm']; ?>"> <?php esc_html_e( 'wpm', 'foxfire-studio' ); ?></label>
						<label><?php esc_html_e( 'Text size', 'foxfire-studio' ); ?> <input type="number" min="24" max="72" step="2" name="<?php echo esc_attr( $opt ); ?>[defaults][size]" value="<?php echo (int) $s['defaults']['size']; ?>"> <?php esc_html_e( 'px', 'foxfire-studio' ); ?></label>
						<label><?php esc_html_e( 'Column width', 'foxfire-studio' ); ?> <input type="number" min="10" max="30" name="<?php echo esc_attr( $opt ); ?>[defaults][cols]" value="<?php echo (int) $s['defaults']['cols']; ?>"></label>
						<label><?php esc_html_e( 'Reading line', 'foxfire-studio' ); ?> <input type="number" min="18" max="50" name="<?php echo esc_attr( $opt ); ?>[defaults][line]" value="<?php echo (int) $s['defaults']['line']; ?>"> <?php esc_html_e( '% from the top', 'foxfire-studio' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Pace by kind of reading', 'foxfire-studio' ); ?></th>
					<td class="rrt-nums rrt-kinds">
						<?php
						$rrt_kr = rrt_kind_ranges();
						foreach ( rrt_kinds() as $rrt_kk => $rrt_kl ) :
							?>
							<span class="rrt-kind-row"><strong><?php echo esc_html( $rrt_kl ); ?></strong>
							<label><?php esc_html_e( 'from', 'foxfire-studio' ); ?> <input type="number" min="60" max="215" step="5" name="<?php echo esc_attr( $opt ); ?>[kinds][<?php echo esc_attr( $rrt_kk ); ?>][0]" value="<?php echo (int) $rrt_kr[ $rrt_kk ][0]; ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: kind of reading, such as Verse. */ __( '%s: lowest pace', 'foxfire-studio' ), $rrt_kl ) ); ?>"></label>
							<label><?php esc_html_e( 'to', 'foxfire-studio' ); ?> <input type="number" min="65" max="220" step="5" name="<?php echo esc_attr( $opt ); ?>[kinds][<?php echo esc_attr( $rrt_kk ); ?>][1]" value="<?php echo (int) $rrt_kr[ $rrt_kk ][1]; ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: kind of reading, such as Verse. */ __( '%s: highest pace', 'foxfire-studio' ), $rrt_kl ) ); ?>"> <?php esc_html_e( 'wpm', 'foxfire-studio' ); ?></label></span><br>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'Each post can be given a kind of reading in its Reading audio box or in the teleprompter\'s Practice section. The teleprompter then starts in the middle of that range, and the review judges the pace of a take against it. In Verse and Ritual and chant, a line that trails off is expected, so only a deep fade is flagged. A post with no kind uses the starting pace above.', 'foxfire-studio' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Switched on to start', 'foxfire-studio' ); ?></th>
					<td>
						<?php
						$checks = array(
							'breath'  => __( 'Breathe between paragraphs', 'foxfire-studio' ),
							'rec'     => __( 'Record my voice', 'foxfire-studio' ),
							'clean'   => __( 'Clean up audio', 'foxfire-studio' ),
							'whisper' => __( 'Whisper review', 'foxfire-studio' ),
						);
						foreach ( $checks as $k => $label ) :
							?>
							<label class="rrt-check"><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[defaults][<?php echo esc_attr( $k ); ?>]" value="1" <?php checked( $s['defaults'][ $k ] ); ?>> <?php echo esc_html( $label ); ?></label>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Content that can be read', 'foxfire-studio' ); ?></th>
					<td>
						<?php foreach ( $types as $t ) : ?>
							<label class="rrt-check"><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[post_types][]" value="<?php echo esc_attr( $t->name ); ?>" <?php checked( in_array( $t->name, $s['post_types'], true ) ); ?>> <?php echo esc_html( $t->labels->name ); ?></label>
						<?php endforeach; ?>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Broadcast sound', 'foxfire-studio' ); ?></h2>
			<p><?php esc_html_e( 'How each reading is shaped and leveled before it becomes an MP3. The Whisper review always listens to the untouched recording, so none of this changes its results.', 'foxfire-studio' ); ?></p>
			<table class="form-table" role="presentation">
				<?php
				/* Finishing presets (1.6.0): one menu for the loudness, peaks, channels and file type. */
				$fin_all  = rrt_finish_specs( $s['sound'] );
				$fin_now  = rrt_finish_spec( $s['sound'] );
				$fin_data = array();
				foreach ( $fin_all as $f ) {
					$fin_data[ $f['key'] ] = array(
						'help' => $f['help'],
						'lufs' => $f['lufs'],
						'tp'   => $f['tp'],
						'ch'   => $f['ch'],
						'kind' => $f['kind'],
					);
				}
				?>
				<tr>
					<th scope="row"><label for="rrt-sound-finish"><?php esc_html_e( 'Finish for', 'foxfire-studio' ); ?></label></th>
					<td>
						<select id="rrt-sound-finish" name="<?php echo esc_attr( $opt ); ?>[sound][finish]" data-finish="<?php echo esc_attr( wp_json_encode( $fin_data ) ); ?>">
							<?php foreach ( $fin_all as $f ) : ?>
								<option value="<?php echo esc_attr( $f['key'] ); ?>" <?php selected( $fin_now['key'], $f['key'] ); ?>><?php echo esc_html( $f['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description" id="rrt-finish-help"><?php echo esc_html( $fin_now['help'] ); ?></p>
						<p class="description"><?php esc_html_e( 'Every new reading and Use this recording are finished this way. The review studio can also download a one-off version for another destination.', 'foxfire-studio' ); ?></p>
					</td>
				</tr>
				<tr class="rrt-finish-numbers">
					<th scope="row"><label for="rrt-sound-lufs"><?php esc_html_e( 'Loudness', 'foxfire-studio' ); ?></label></th>
					<td>
						<input type="number" id="rrt-sound-lufs" class="small-text" name="<?php echo esc_attr( $opt ); ?>[sound][custom_lufs]" min="-24" max="-12" step="0.5" value="<?php echo esc_attr( 'custom' === $fin_now['key'] || null === $fin_now['lufs'] ? $s['sound']['custom_lufs'] : $fin_now['lufs'] ); ?>" data-custom="<?php echo esc_attr( $s['sound']['custom_lufs'] ); ?>"> LUFS
						<span class="rrt-hint"><?php esc_html_e( 'Measured on the finished file, both channels counted for stereo. -24 to -12.', 'foxfire-studio' ); ?></span>
					</td>
				</tr>
				<tr class="rrt-finish-numbers">
					<th scope="row"><label for="rrt-sound-tp"><?php esc_html_e( 'True peak limit', 'foxfire-studio' ); ?></label></th>
					<td>
						<input type="number" id="rrt-sound-tp" class="small-text" name="<?php echo esc_attr( $opt ); ?>[sound][custom_tp]" min="-3" max="-0.5" step="0.5" value="<?php echo esc_attr( 'custom' === $fin_now['key'] || null === $fin_now['tp'] ? $s['sound']['custom_tp'] : $fin_now['tp'] ); ?>" data-custom="<?php echo esc_attr( $s['sound']['custom_tp'] ); ?>"> dBTP
						<span class="rrt-hint"><?php esc_html_e( 'No peak in the finished file goes above this, between samples included. MP3s are limited a little lower, since encoding lifts peaks slightly. -3 to -0.5.', 'foxfire-studio' ); ?></span>
					</td>
				</tr>
				<tr class="rrt-finish-numbers">
					<th scope="row"><label for="rrt-sound-ch"><?php esc_html_e( 'Channels', 'foxfire-studio' ); ?></label></th>
					<td>
						<select id="rrt-sound-ch" name="<?php echo esc_attr( $opt ); ?>[sound][custom_ch]" data-custom="<?php echo esc_attr( $s['sound']['custom_ch'] ); ?>">
							<option value="mono" <?php selected( 'custom' === $fin_now['key'] ? $s['sound']['custom_ch'] : ( 2 === $fin_now['ch'] ? 'stereo' : 'mono' ), 'mono' ); ?>><?php esc_html_e( 'Mono', 'foxfire-studio' ); ?></option>
							<option value="stereo" <?php selected( 'custom' === $fin_now['key'] ? $s['sound']['custom_ch'] : ( 2 === $fin_now['ch'] ? 'stereo' : 'mono' ), 'stereo' ); ?>><?php esc_html_e( 'Stereo (the voice in both channels)', 'foxfire-studio' ); ?></option>
						</select>
						<span class="rrt-hint"><?php esc_html_e( 'Each preset fills these in. Choose Custom to set your own.', 'foxfire-studio' ); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Steps', 'foxfire-studio' ); ?></th>
					<td>
						<label class="rrt-check"><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[sound][hp]" value="1" <?php checked( $s['sound']['hp'] ); ?>> <?php esc_html_e( 'Low rumble filter (below 80 Hz: desk bumps, traffic, handling)', 'foxfire-studio' ); ?></label>
						<?php
						/* Voice polish (1.13.0): a strength for each step. Standard is the sound before 1.13.0. */
						$rrt_steps  = array(
							'plosives' => array( __( 'Pops on P and B', 'foxfire-studio' ), __( 'Gentle softens only the strongest pops; Strong catches smaller ones and takes more of the rumble out.', 'foxfire-studio' ) ),
							'deess'    => array( __( 'Harsh S, Sh and Ch', 'foxfire-studio' ), __( 'Turned down by up to 4 dB (Gentle), 8 dB (Standard) or 12 dB (Strong).', 'foxfire-studio' ) ),
							'breaths'  => array( __( 'Breaths', 'foxfire-studio' ), __( 'Lowered by 3 dB (Gentle), 6 dB (Standard) or 10 dB (Strong), and always kept in.', 'foxfire-studio' ) ),
							'compress' => array( __( 'Compression', 'foxfire-studio' ), __( 'So loud words sit closer to the rest: 1.5 to 1 (Gentle), 2 to 1 (Standard) or 3 to 1 (Strong).', 'foxfire-studio' ) ),
						);
						$rrt_levels = rrt_polish_levels();
						?>
						<table class="rrt-polish-steps" role="presentation" style="margin-top:8px">
							<?php foreach ( $rrt_steps as $rrt_k => $rrt_st ) : ?>
								<tr>
									<td style="padding:4px 12px 4px 0"><label for="rrt-sound-<?php echo esc_attr( $rrt_k ); ?>"><?php echo esc_html( $rrt_st[0] ); ?></label></td>
									<td style="padding:4px 12px 4px 0">
										<select id="rrt-sound-<?php echo esc_attr( $rrt_k ); ?>" name="<?php echo esc_attr( $opt ); ?>[sound][<?php echo esc_attr( $rrt_k ); ?>]">
											<?php foreach ( $rrt_levels as $rrt_lk => $rrt_ll ) : ?>
												<option value="<?php echo esc_attr( $rrt_lk ); ?>" <?php selected( $s['sound'][ $rrt_k ], $rrt_lk ); ?>><?php echo esc_html( $rrt_ll ); ?></option>
											<?php endforeach; ?>
										</select>
									</td>
									<td style="padding:4px 0"><span class="rrt-hint"><?php echo esc_html( $rrt_st[1] ); ?></span></td>
								</tr>
							<?php endforeach; ?>
						</table>
						<p class="description"><?php esc_html_e( 'Standard is the sound every version before 1.13.0 made. In the review studio, Hear the polish plays one sentence before and after, and can try other strengths on that take only.', 'foxfire-studio' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Voice polish', 'foxfire-studio' ); ?></th>
					<td>
						<label class="rrt-check"><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[sound][slow]" value="1" <?php checked( $s['sound']['slow'] ); ?>> <?php esc_html_e( 'Slow leveling: even out a level that drifts over minutes, by at most 4 dB (2 dB in verse and ritual)', 'foxfire-studio' ); ?></label>
						<label class="rrt-check"><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[sound][clicks]" value="1" <?php checked( $s['sound']['clicks'] ); ?>> <?php esc_html_e( 'Lower clicks between words (under 15 ms, found from Whisper\'s word times; lowered, never cut)', 'foxfire-studio' ); ?></label>
						<label class="rrt-check"><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[sound][eq]" value="1" <?php checked( $s['sound']['eq'] ); ?>> <?php esc_html_e( 'EQ for each setup: a gentle EQ (at most 3 dB in a few broad bands) suggested for each device and microphone from its first three takes', 'foxfire-studio' ); ?></label>
						<?php
						$rrt_eq = function_exists( 'rrt_eq_get' ) ? rrt_eq_get( get_current_user_id() ) : array();
						if ( $rrt_eq ) :
							?>
							<p class="description"><?php esc_html_e( 'Your setups so far (each take measures the voice in six bands; nothing of the audio is kept):', 'foxfire-studio' ); ?></p>
							<ul class="rrt-eq-setups" style="margin:4px 0 0 18px;list-style:disc">
								<?php foreach ( $rrt_eq as $rrt_set ) : ?>
									<li><?php echo esc_html( sprintf( /* translators: 1: device and microphone, 2: takes measured, 3: takes needed. */ _n( '%1$s: %2$s take measured (an EQ is suggested from %3$s)', '%1$s: %2$s takes measured (an EQ is suggested from %3$s)', count( $rrt_set['takes'] ), 'foxfire-studio' ), $rrt_set['label'], count( $rrt_set['takes'] ), 3 ) ); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
						<p class="description"><?php esc_html_e( 'Polish shapes new takes, Use this recording, clips and Share for feedback. The Original download always stays as recorded, and readings already on posts stay as they are until remade. Hear the polish in the review studio can try the EQ and the click lowering on one take before you switch them on here.', 'foxfire-studio' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="rrt-sound-denoise"><?php esc_html_e( 'Background noise reduction', 'foxfire-studio' ); ?></label></th>
					<td>
						<?php /* The stronger cleanup (1.16.0): which model does it. */ ?>
						<label for="rrt-sound-engine" class="screen-reader-text"><?php esc_html_e( 'Engine', 'foxfire-studio' ); ?></label>
						<select id="rrt-sound-engine" name="<?php echo esc_attr( $opt ); ?>[sound][engine]">
							<option value="standard" <?php selected( $s['sound']['engine'], 'standard' ); ?>><?php esc_html_e( 'Engine: Standard (RNNoise, quick)', 'foxfire-studio' ); ?></option>
							<option value="stronger" <?php selected( $s['sound']['engine'], 'stronger' ); ?> <?php disabled( ! rrt_stronger_ready() ); ?>><?php esc_html_e( 'Engine: Stronger (DeepFilterNet 3)', 'foxfire-studio' ); ?></option>
						</select>
						<select id="rrt-sound-denoise" name="<?php echo esc_attr( $opt ); ?>[sound][denoise]">
							<?php
							$levels = array(
								'gentle' => __( 'Gentle: lowers the background by up to 12 dB', 'foxfire-studio' ),
								'medium' => __( 'Medium: up to 20 dB (recommended)', 'foxfire-studio' ),
								'strong' => __( 'Strong: as far as the model goes', 'foxfire-studio' ),
								'off'    => __( 'Off: the steady-hush reduction used before version 0.6.0', 'foxfire-studio' ),
							);
							foreach ( $levels as $k => $label ) :
								?>
								<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $s['sound']['denoise'], $k ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'A small speech model (RNNoise) that tells your voice from fans, traffic and room noise, run on the reader\'s own computer after the reading ends. It is part of cleanup, so it applies when the reader leaves "Clean up" on. If the words start to sound watery or word endings get cut, choose a gentler setting.', 'foxfire-studio' ); ?></p>
						<p class="description"><?php esc_html_e( 'Stronger uses DeepFilterNet 3, a larger model that comes with the plugin. It also takes out sounds with a shape to them, such as a bell, a creaking chair or mouth sounds, and takes about twice as long. With Stronger, the Strong amount usually sounds best: a lower amount lets some of the noise back in. If a device is too slow for it, or it cannot load, that take uses Standard and the Room line says so. Hear the polish in the review studio can compare the two engines on one sentence first.', 'foxfire-studio' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Audiobook copy', 'foxfire-studio' ); ?></th>
					<td>
						<label class="rrt-check"><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[sound][book]" value="1" <?php checked( ! empty( $s['sound']['book'] ) ); ?>> <?php esc_html_e( 'Keep an audiobook copy: Use this recording also finishes the take with the Audiobook (ACX) preset, voice only, and keeps it for the collections\' Audiobook box', 'foxfire-studio' ); ?></label>
						<p class="description"><?php esc_html_e( 'About 1.4 MB a minute, in a folder visitors cannot open. A reading already on its post can get one from the review studio: Finish for, then Keep as this post\'s audiobook chapter.', 'foxfire-studio' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Music bed', 'foxfire-studio' ); ?></h2>
			<p><?php esc_html_e( 'Quiet music under a reading. It plays alone for a few seconds, dips just before the first word, stays low under the voice, then rises again after the last word and fades out. It is mixed in last, after any editing, so the Whisper review always hears the voice alone and the Original download stays voice only. Use only music you own or have a licence for.', 'foxfire-studio' ); ?></p>
			<?php
			$rrt_mu = isset( $s['music'] ) ? $s['music'] : array( 'beds' => array(), 'default' => 0, 'depth' => 20, 'swell' => false );
			?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Tracks', 'foxfire-studio' ); ?></th>
					<td>
						<table class="widefat striped rrt-music-list" id="rrt-music-list" style="max-width:760px">
							<thead><tr><th style="width:90px"><?php esc_html_e( 'Default', 'foxfire-studio' ); ?></th><th><?php esc_html_e( 'Track', 'foxfire-studio' ); ?></th><th><?php esc_html_e( 'Credit (optional)', 'foxfire-studio' ); ?></th><th style="width:70px"></th></tr></thead>
							<tbody>
								<tr class="rrt-music-none">
									<td><input type="radio" name="<?php echo esc_attr( $opt ); ?>[music][default]" value="0" id="rrt-music-def-0" <?php checked( 0, (int) $rrt_mu['default'] ); ?>></td>
									<td colspan="3"><label for="rrt-music-def-0"><?php esc_html_e( 'No music bed unless a post chooses one', 'foxfire-studio' ); ?></label></td>
								</tr>
								<?php foreach ( $rrt_mu['beds'] as $rrt_i => $rrt_b ) : ?>
									<?php
									$rrt_bid  = (int) $rrt_b['id'];
									$rrt_burl = (string) wp_get_attachment_url( $rrt_bid );
									?>
									<tr class="rrt-music-row">
										<td><input type="radio" name="<?php echo esc_attr( $opt ); ?>[music][default]" value="<?php echo (int) $rrt_bid; ?>" <?php checked( $rrt_bid, (int) $rrt_mu['default'] ); ?> aria-label="<?php esc_attr_e( 'Default', 'foxfire-studio' ); ?>"></td>
										<td>
											<input type="hidden" class="rrt-music-id" name="<?php echo esc_attr( $opt ); ?>[music][beds][<?php echo (int) $rrt_i; ?>][id]" value="<?php echo (int) $rrt_bid; ?>">
											<strong><?php echo esc_html( get_the_title( $rrt_bid ) ); ?></strong><br>
											<?php if ( $rrt_burl ) : ?>
												<audio src="<?php echo esc_url( $rrt_burl ); ?>" controls preload="none" style="height:32px;max-width:260px"></audio>
											<?php endif; ?>
										</td>
										<td><input type="text" class="regular-text rrt-music-credit" maxlength="200" name="<?php echo esc_attr( $opt ); ?>[music][beds][<?php echo (int) $rrt_i; ?>][credit]" value="<?php echo esc_attr( $rrt_b['credit'] ); ?>" placeholder="<?php esc_attr_e( 'for example: Night Garden by A. Composer, CC BY 4.0', 'foxfire-studio' ); ?>"></td>
										<td><button type="button" class="button-link rrt-music-remove"><?php esc_html_e( 'Remove', 'foxfire-studio' ); ?></button></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<p><button type="button" class="button" id="rrt-music-add"><?php esc_html_e( 'Add tracks', 'foxfire-studio' ); ?></button></p>
						<p class="description"><?php esc_html_e( 'MP3, WAV, OGG or M4A from the media library. A track shorter than the reading loops with a smooth crossfade. The credit goes into the MP3\'s comment and the podcast feed\'s episode notes as "Music: ..." whenever a reading with that track is put on a post. Each post can choose its own track, or none, in its Reading audio box.', 'foxfire-studio' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="rrt-music-depth"><?php esc_html_e( 'Under the voice', 'foxfire-studio' ); ?></label></th>
					<td>
						<select id="rrt-music-depth" name="<?php echo esc_attr( $opt ); ?>[music][depth]">
							<?php
							$rrt_depths = array(
								24 => __( 'Softer: 24 dB under the voice', 'foxfire-studio' ),
								20 => __( 'Standard: 20 dB under the voice (recommended)', 'foxfire-studio' ),
								16 => __( 'Fuller: 16 dB under the voice', 'foxfire-studio' ),
							);
							foreach ( $rrt_depths as $k => $label ) :
								?>
								<option value="<?php echo (int) $k; ?>" <?php selected( (int) $rrt_mu['depth'], $k ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'On its own, before the first word and after the last, the music sits 6 dB under the voice.', 'foxfire-studio' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Long pauses', 'foxfire-studio' ); ?></th>
					<td>
						<label class="rrt-check"><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[music][swell]" value="1" <?php checked( ! empty( $rrt_mu['swell'] ) ); ?>> <?php esc_html_e( 'Let the music rise a little (6 dB) in pauses of over 2 seconds', 'foxfire-studio' ); ?></label>
						<p class="description"><?php esc_html_e( 'Left off, the music stays steady from the first word to the last.', 'foxfire-studio' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Whisper', 'foxfire-studio' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'This browser', 'foxfire-studio' ); ?></th>
					<td>
						<?php /* 1.30.0: filled in by can.js with what the browser in use can do here. */ ?>
						<p id="rrt-this-browser" data-rrt-can role="status"><?php esc_html_e( 'Checking what this browser can do...', 'foxfire-studio' ); ?></p>
						<p class="description"><?php esc_html_e( 'Each reader\'s browser is checked the same way in the studio (Controls panel, Recording). Clips and the full video work in Chrome, Edge, Safari and Firefox on a computer, and Safari on an iPhone or iPad has every tool they need.', 'foxfire-studio' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Load Whisper from', 'foxfire-studio' ); ?></th>
					<td>
						<label class="rrt-check"><input type="radio" name="<?php echo esc_attr( $opt ); ?>[whisper][source]" value="auto" <?php checked( 'auto', $s['whisper']['source'] ); ?>> <?php esc_html_e( 'This site when its files are here, otherwise Hugging Face and jsDelivr', 'foxfire-studio' ); ?></label>
						<label class="rrt-check"><input type="radio" name="<?php echo esc_attr( $opt ); ?>[whisper][source]" value="remote" <?php checked( 'remote', $s['whisper']['source'] ); ?>> <?php esc_html_e( 'Always Hugging Face and jsDelivr', 'foxfire-studio' ); ?></label>
						<p class="description"><?php esc_html_e( 'Either way, Whisper runs on the reader\'s own computer and recordings never leave it.', 'foxfire-studio' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Listening player', 'foxfire-studio' ); ?></h2>
			<p><?php
				printf(
					/* translators: %s: an example of what to add to a post's address, ?t=1:45, shown as code. */
					esc_html__( 'The player on your posts: play, skip back and ahead, speed, read along, resume where you left off, links that start at a moment (add %s to a post\'s address), and lock screen controls on phones.', 'foxfire-studio' ),
					'<code>?t=1:45</code>'
				);
				?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Show the player to', 'foxfire-studio' ); ?></th>
					<td>
						<?php
						$shows = array(
							'off'      => __( 'Nobody (switched off)', 'foxfire-studio' ),
							'editors'  => __( 'Only logged-in editors, as a preview', 'foxfire-studio' ),
							'everyone' => __( 'Everyone', 'foxfire-studio' ),
						);
						foreach ( $shows as $k => $label ) :
							?>
							<label class="rrt-check"><input type="radio" name="<?php echo esc_attr( $opt ); ?>[player][show]" value="<?php echo esc_attr( $k ); ?>" <?php checked( $k, $s['player']['show'] ); ?>> <?php echo esc_html( $label ); ?></label>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Where', 'foxfire-studio' ); ?></th>
					<td>
						<label class="rrt-check"><input type="radio" name="<?php echo esc_attr( $opt ); ?>[player][position]" value="top" <?php checked( 'top', $s['player']['position'] ); ?>> <?php esc_html_e( 'At the top of the post, unless the post or its template places it', 'foxfire-studio' ); ?></label>
						<label class="rrt-check"><input type="radio" name="<?php echo esc_attr( $opt ); ?>[player][position]" value="manual" <?php checked( 'manual', $s['player']['position'] ); ?>> <?php
						printf(
							/* translators: 1: the shortcode, [foxfire_player]; 2: the theme function, rrt_player(). Both shown as code. */
							esc_html__( 'Only where I put it: the Reading player block, the %1$s shortcode, or %2$s in the theme', 'foxfire-studio' ),
							'<code>[foxfire_player]</code>',
							'<code>rrt_player()</code>'
						);
						?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="rrt-audio-key"><?php esc_html_e( 'Also look for the reading in this custom field', 'foxfire-studio' ); ?></label></th>
					<td>
						<input class="regular-text code" id="rrt-audio-key" name="<?php echo esc_attr( $opt ); ?>[player][audio_key]" value="<?php echo esc_attr( $s['player']['audio_key'] ); ?>" placeholder="<?php esc_attr_e( 'for example post_audio', 'foxfire-studio' ); ?>">
						<p class="description"><?php esc_html_e( 'The Reading audio box on each post is used first. Fill this in if your readings are already stored in another field, such as one made with Advanced Custom Fields.', 'foxfire-studio' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="rrt-credit-key"><?php esc_html_e( 'Also look for a "read by" credit in this custom field', 'foxfire-studio' ); ?></label></th>
					<td>
						<input class="regular-text code" id="rrt-credit-key" name="<?php echo esc_attr( $opt ); ?>[player][credit_key]" value="<?php echo esc_attr( $s['player']['credit_key'] ); ?>" placeholder="<?php esc_attr_e( 'for example audio_credit', 'foxfire-studio' ); ?>">
						<p class="description"><?php esc_html_e( 'A credit such as "read by Robin" is shown under the player\'s label and in the podcast feed. The Read by box in each post\'s Reading audio panel is used first.', 'foxfire-studio' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Buttons', 'foxfire-studio' ); ?></th>
					<td class="rrt-nums">
						<label><?php esc_html_e( 'Skip back', 'foxfire-studio' ); ?> <input type="number" min="5" max="120" name="<?php echo esc_attr( $opt ); ?>[player][skip_back]" value="<?php echo (int) $s['player']['skip_back']; ?>"> <?php esc_html_e( 'seconds', 'foxfire-studio' ); ?></label>
						<label><?php esc_html_e( 'Skip ahead', 'foxfire-studio' ); ?> <input type="number" min="5" max="120" name="<?php echo esc_attr( $opt ); ?>[player][skip_fwd]" value="<?php echo (int) $s['player']['skip_fwd']; ?>"> <?php esc_html_e( 'seconds', 'foxfire-studio' ); ?></label>
						<label><?php esc_html_e( 'Speeds', 'foxfire-studio' ); ?> <input class="regular-text" style="width:220px" name="<?php echo esc_attr( $opt ); ?>[player][speeds]" value="<?php echo esc_attr( $s['player']['speeds'] ); ?>"></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Features', 'foxfire-studio' ); ?></th>
					<td>
						<label class="rrt-check"><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[player][follow]" value="1" <?php checked( $s['player']['follow'] ); ?>> <?php esc_html_e( 'Read along: light up the post text as it is read (needs a captions file on the post)', 'foxfire-studio' ); ?></label>
						<label class="rrt-check"><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[player][resume]" value="1" <?php checked( $s['player']['resume'] ); ?>> <?php esc_html_e( 'Offer to resume where a listener left off (remembered in their own browser only)', 'foxfire-studio' ); ?></label>
						<label class="rrt-check"><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[player][download]" value="1" <?php checked( $s['player']['download'] ); ?>> <?php esc_html_e( 'Show a Download button', 'foxfire-studio' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="rrt-content-sel"><?php esc_html_e( 'Post text is inside', 'foxfire-studio' ); ?></label></th>
					<td>
						<input class="regular-text code" id="rrt-content-sel" name="<?php echo esc_attr( $opt ); ?>[player][content_selector]" value="<?php echo esc_attr( $s['player']['content_selector'] ); ?>">
						<p class="description"><?php
						printf(
							/* translators: %s: a CSS selector, .entry-content, shown as code. */
							esc_html__( 'Where read along looks for the words. %s suits most themes.', 'foxfire-studio' ),
							'<code>.entry-content</code>'
						);
						?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="rrt-show-name"><?php esc_html_e( 'Show name', 'foxfire-studio' ); ?></label></th>
					<td>
						<input class="regular-text" id="rrt-show-name" name="<?php echo esc_attr( $opt ); ?>[player][show_name]" value="<?php echo esc_attr( $s['player']['show_name'] ); ?>" placeholder="<?php echo esc_attr( rrt_show_name( array( 'player' => array( 'show_name' => '' ) ) ) ); ?>">
						<p class="description"><?php esc_html_e( 'What podcast apps and phone lock screens show as the show, and what goes into each MP3\'s tags. Left empty, the site title is used.', 'foxfire-studio' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Reader stats', 'foxfire-studio' ); ?></h2>
			<?php $rrt_counts = function_exists( 'rrt_stats_counts' ) ? rrt_stats_counts() : array(); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Keep stats on this site', 'foxfire-studio' ); ?></th>
					<td>
						<input type="hidden" name="<?php echo esc_attr( $opt ); ?>[stats][present]" value="1">
						<label class="rrt-check"><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[stats][server]" value="1" <?php checked( $s['stats']['server'] ); ?>> <?php esc_html_e( 'Keep every take, the words you have taught Whisper, and a record of each post\'s reading on this site', 'foxfire-studio' ); ?></label>
						<p class="description"><?php esc_html_e( 'So your history follows you to any computer. Only the numbers from each take are kept, never the recording itself; a recording reaches the site only when you press "Use this recording". Each reader sees only their own takes. Switched off, each browser keeps its own history, as before version 0.9.0.', 'foxfire-studio' ); ?>
						<?php
						if ( $rrt_counts ) {
							echo ' ' . esc_html(
								sprintf(
									/* translators: 1: takes, such as 12 takes, 2: readers, such as 1 reader, 3: published readings, such as 2 published readings. */
									__( 'Kept now: %1$s from %2$s, and %3$s.', 'foxfire-studio' ),
									/* translators: %s: number of takes. */
									sprintf( _n( '%s take', '%s takes', $rrt_counts['takes'], 'foxfire-studio' ), number_format_i18n( $rrt_counts['takes'] ) ),
									/* translators: %s: number of readers. */
									sprintf( _n( '%s reader', '%s readers', $rrt_counts['readers'], 'foxfire-studio' ), number_format_i18n( $rrt_counts['readers'] ) ),
									/* translators: %s: number of readings. */
									sprintf( _n( '%s published reading', '%s published readings', $rrt_counts['recordings'], 'foxfire-studio' ), number_format_i18n( $rrt_counts['recordings'] ) )
								)
							);
						}
						?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'When the plugin is deleted', 'foxfire-studio' ); ?></th>
					<td>
						<label class="rrt-check"><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[stats][delete_on_uninstall]" value="1" <?php checked( $s['stats']['delete_on_uninstall'] ); ?>> <?php esc_html_e( 'When the plugin is deleted, also delete everything else it keeps: reader stats, listener analytics, cue marks, pronunciation notes, words taught for one post, shared and sent takes, each post\'s reading, captions and Read by links, readings kept aside, and the Whisper files on this site', 'foxfire-studio' ); ?></label>
						<p class="description"><?php esc_html_e( 'Left unticked, all of this stays, so reinstalling loses nothing. The settings and the copies of the fonts, MP3 encoder and noise model are always removed. MP3s and captions in the Media Library are never deleted. Readers can also ask for their own stats to be exported or erased with WordPress\'s personal data tools (Tools, Export or Erase Personal Data).', 'foxfire-studio' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Listener analytics', 'foxfire-studio' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Measure listening', 'foxfire-studio' ); ?></th>
					<td>
						<input type="hidden" name="<?php echo esc_attr( $opt ); ?>[listen][present]" value="1">
						<label class="rrt-check"><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[listen][on]" value="1" <?php checked( $s['listen']['on'] ); ?>> <?php esc_html_e( 'Measure how visitors listen to each reading', 'foxfire-studio' ); ?></label>
						<p class="description"><?php esc_html_e( 'Plays, which parts are heard, speed changes, skips, where listeners leave, and which sites and links bring them. Measured on this site only, with no cookies and no outside services. No IP address is stored: each visitor gets an anonymous id that changes every day and cannot be traced back. If your site has a page cache, empty it after switching this on or off.', 'foxfire-studio' ); ?></p>
						<?php if ( empty( $s['listen']['on'] ) ) : /* Only while it is off: the advice is for switching it on. */ ?>
						<p class="description"><?php
						printf(
							/* translators: %s: the name of a section of this page, For your privacy policy, in italics. */
							esc_html__( 'Off until you switch it on. Before you do, add the paragraph under %s at the bottom of this page to your site\'s privacy policy.', 'foxfire-studio' ),
							'<em>' . esc_html__( 'For your privacy policy', 'foxfire-studio' ) . '</em>'
						);
						?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="rrt-listen-exclude"><?php esc_html_e( 'Leave out', 'foxfire-studio' ); ?></label></th>
					<td>
						<select id="rrt-listen-exclude" name="<?php echo esc_attr( $opt ); ?>[listen][exclude]">
							<option value="editors" <?php selected( $s['listen']['exclude'], 'editors' ); ?>><?php esc_html_e( 'Editors and admins (anyone who can edit posts)', 'foxfire-studio' ); ?></option>
							<option value="logged_in" <?php selected( $s['listen']['exclude'], 'logged_in' ); ?>><?php esc_html_e( 'Everyone who is logged in', 'foxfire-studio' ); ?></option>
							<option value="nobody" <?php selected( $s['listen']['exclude'], 'nobody' ); ?>><?php esc_html_e( 'Nobody', 'foxfire-studio' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'So your own testing does not count. To test as a listener, use a browser where you are not logged in.', 'foxfire-studio' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Privacy signals', 'foxfire-studio' ); ?></th>
					<td><label class="rrt-check"><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[listen][dnt]" value="1" <?php checked( $s['listen']['dnt'] ); ?>> <?php esc_html_e( 'Do not measure visitors whose browser sends Do Not Track or Global Privacy Control', 'foxfire-studio' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Returning listeners', 'foxfire-studio' ); ?></th>
					<td>
						<label class="rrt-check"><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[listen][returning]" value="1" <?php checked( $s['listen']['returning'] ); ?>> <?php esc_html_e( 'Tell new listeners from returning ones', 'foxfire-studio' ); ?></label>
						<label><?php esc_html_e( 'Remember a listen for', 'foxfire-studio' ); ?>
							<select name="<?php echo esc_attr( $opt ); ?>[listen][returning_months]">
								<?php foreach ( array( 1, 3, 6, 12 ) as $rrt_m ) : ?>
									<option value="<?php echo (int) $rrt_m; ?>" <?php selected( (int) $s['listen']['returning_months'], $rrt_m ); ?>><?php echo esc_html( sprintf( /* translators: %d: number of months. */ _n( '%d month', '%d months', $rrt_m, 'foxfire-studio' ), $rrt_m ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<p class="description"><?php esc_html_e( 'After someone listens for 30 seconds (or to a whole short reading), the player keeps that month in their browser\'s own storage. On a later visit it sends only a yes or no: listened here before, within the time above. The month never leaves the browser, and nothing links one day\'s visits to another\'s. Off by default: before switching it on, add a line to your site\'s privacy policy (the paragraph at the bottom of this page includes one while it is on). In the EU and UK, browser storage is treated much like a cookie, so check whether your visitors there need to be asked first.', 'foxfire-studio' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Google Analytics', 'foxfire-studio' ); ?></th>
					<td>
						<label class="rrt-check"><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[listen][ga]" value="1" <?php checked( $s['listen']['ga'] ); ?>> <?php esc_html_e( 'Also send plays to the Google Analytics this site already loads', 'foxfire-studio' ); ?></label>
						<p class="description"><?php
						printf(
							/* translators: 1 to 3: the event names audio_start, audio_progress and audio_complete, shown as code. */
							esc_html__( 'Sends %1$s at the first play, %2$s at halfway and %3$s at the end, each with the reading\'s title. Works only where the page already has a Google Analytics tag; this setting adds none. Foxfire Studio\'s own numbers stay as they are, and the two will differ, because Google Analytics uses cookies and ad blockers stop it. Your privacy policy should say that listening is reported to Google Analytics. Works whether or not Measure listening is on, and leaves out the same visitors.', 'foxfire-studio' ),
							'<code>audio_start</code>',
							'<code>audio_progress</code>',
							'<code>audio_complete</code>'
						);
						?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="rrt-listen-src"><?php esc_html_e( 'Short campaign tag', 'foxfire-studio' ); ?></label></th>
					<td>
						<input type="text" id="rrt-listen-src" class="small-text" name="<?php echo esc_attr( $opt ); ?>[listen][src]" value="<?php echo esc_attr( $s['listen']['src'] ); ?>">
						<p class="description"><?php
						printf(
							/* translators: 1: an example tagged link; 2 to 5: the tag names utm_source, utm_medium, utm_campaign and utm_content. All shown as code. */
							esc_html__( 'Add it to links you share, so each one can be compared: %1$s. The usual %2$s, %3$s, %4$s and %5$s tags work too.', 'foxfire-studio' ),
							'<code>' . esc_html( home_url( '/your-post/?' . $s['listen']['src'] . '=instagram' ) ) . '</code>',
							'<code>utm_source</code>',
							'<code>utm_medium</code>',
							'<code>utm_campaign</code>',
							'<code>utm_content</code>'
						);
						?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Keep', 'foxfire-studio' ); ?></th>
					<td class="rrt-nums">
						<label><?php esc_html_e( 'Every event of each visit for', 'foxfire-studio' ); ?> <input type="number" min="1" max="365" name="<?php echo esc_attr( $opt ); ?>[listen][events_days]" value="<?php echo (int) $s['listen']['events_days']; ?>"> <?php esc_html_e( 'days', 'foxfire-studio' ); ?></label>
						<label><?php esc_html_e( 'Each visit\'s summary for', 'foxfire-studio' ); ?> <input type="number" min="30" max="1095" name="<?php echo esc_attr( $opt ); ?>[listen][sessions_days]" value="<?php echo (int) $s['listen']['sessions_days']; ?>"> <?php esc_html_e( 'days', 'foxfire-studio' ); ?></label>
						<p class="description"><?php esc_html_e( 'Daily totals, which say nothing about any one visitor, are kept.', 'foxfire-studio' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="rrt-listen-proxies"><?php esc_html_e( 'Trusted proxies', 'foxfire-studio' ); ?></label></th>
					<td>
						<textarea id="rrt-listen-proxies" class="large-text code" rows="3" name="<?php echo esc_attr( $opt ); ?>[listen][proxies]" placeholder="<?php esc_attr_e( 'Leave empty unless your site is behind a CDN or proxy', 'foxfire-studio' ); ?>"><?php echo esc_textarea( $s['listen']['proxies'] ); ?></textarea>
						<label><?php esc_html_e( 'Read the visitor\'s address from', 'foxfire-studio' ); ?>
							<select name="<?php echo esc_attr( $opt ); ?>[listen][proxy_header]">
								<?php
								$rrt_headers = array(
									'x-forwarded-for'  => 'X-Forwarded-For',
									'cf-connecting-ip' => __( 'CF-Connecting-IP (Cloudflare)', 'foxfire-studio' ),
									'x-real-ip'        => 'X-Real-IP',
									'true-client-ip'   => 'True-Client-IP',
								);
								foreach ( $rrt_headers as $rrt_hk => $rrt_hl ) :
									?>
									<option value="<?php echo esc_attr( $rrt_hk ); ?>" <?php selected( $s['listen']['proxy_header'], $rrt_hk ); ?>><?php echo esc_html( $rrt_hl ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<p class="description"><?php
						echo wp_kses(
							sprintf(
								/* translators: %s: an example address range. */
								__( 'Only if your site sits behind a CDN or reverse proxy: list its addresses or ranges (such as %s), one per line, as your provider publishes them. The header above is then read only on requests that arrive from those addresses; from anywhere else it is ignored, so visitors cannot fake their address. Empty means the connection\'s own address is used for every visit. Country headers sent by a proxy are trusted the same way.', 'foxfire-studio' ),
								'<code>203.0.113.0/24</code>'
							),
							array( 'code' => array() )
						);
						?></p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Save settings', 'foxfire-studio' ) ); ?>
		</form>

		<?php do_action( 'rrt_settings_files' ); ?>
		<?php rrt_files_enqueue(); ?>
		<?php
		wp_enqueue_script( 'rrt-can', RRT_URL . 'assets/js/can.js', array( 'wp-i18n' ), RRT_VERSION . '.' . (int) @filemtime( RRT_DIR . 'assets/js/can.js' ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_set_script_translations( 'rrt-can', 'foxfire-studio', rrt_languages_dir() );
		?>
		<div hidden data-rrt-files="<?php echo esc_attr( wp_json_encode( rrt_files_boot() ) ); ?>"></div>
		<h2><?php esc_html_e( 'Whisper on this site', 'foxfire-studio' ); ?></h2>
		<p>
			<?php
			echo esc_html(
				$wst['have']
					? sprintf( /* translators: 1: $wst['have'], 2: $wst['count'], 3: rrt_size( $wst['bytes'] ), 4: str_replace( ABSPATH, '', $wst['dir'] ). */ __( '%1$d of %2$d files present (%3$s) in %4$s', 'foxfire-studio' ), $wst['have'], $wst['count'], rrt_size( $wst['bytes'] ), str_replace( ABSPATH, '', $wst['dir'] ) )
					: __( 'Not on this site yet. Whisper currently loads from Hugging Face and jsDelivr.', 'foxfire-studio' )
			);
			?>
		</p>
		<p>
			<button type="button" class="button" id="rrt-whisper-get"><?php echo $wst['have'] === $wst['count'] ? esc_html__( 'Download again', 'foxfire-studio' ) : esc_html__( 'Download Whisper to this site', 'foxfire-studio' ); ?></button>
			<span class="rrt-hint"><?php esc_html_e( 'About 116 MB of your hosting space. Downloads one file at a time; keep this page open until it finishes.', 'foxfire-studio' ); ?></span>
		</p>
		<ul id="rrt-whisper-files" class="rrt-files">
			<?php foreach ( $wst['files'] as $f ) : ?>
				<li data-i="<?php echo (int) $f['i']; ?>"><code><?php echo esc_html( $f['name'] ); ?></code> <span><?php echo esc_html( rrt_size( $f['size'] ) ); ?></span></li>
			<?php endforeach; ?>
		</ul>

		<h3><?php esc_html_e( 'The larger model', 'foxfire-studio' ); ?></h3>
		<p><?php esc_html_e( 'Whisper small hears unusual words, names and quiet endings more accurately than the standard model, but on the processor it takes about two to three times as long and needs a faster computer. Readers choose it in the teleprompter, beside Whisper review; it uses the code above, so download that first.', 'foxfire-studio' ); ?></p>
		<p><?php esc_html_e( 'On a computer whose graphics chip can run it (WebGPU with half precision, as Chrome and Edge offer on most laptops), the larger model runs on the graphics chip instead: a 14-minute reading takes about 5 to 8 minutes there, against about 27 on the processor. The teleprompter\'s Model menu, under Recording in the Controls panel, is set to Automatic in every browser at first: full reviews then use the larger model wherever the graphics chip can run it, and the standard model everywhere else. If the graphics chip fails, the review carries on with the processor and says so. The graphics chip needs two more files of the model (about 322 MB), downloaded with the rest below; until they are on this site, they load from Hugging Face.', 'foxfire-studio' ); ?></p>
		<p><?php esc_html_e( 'A laptop with two graphics chips: Windows chooses which one the browser gets. To give it the stronger one, open Windows Settings, System, Display, Graphics, choose the browser and set it to High performance, then end the browser fully in Task Manager and open it again (closing its windows is not enough). The review\'s status line says when Whisper is listening on the graphics chip.', 'foxfire-studio' ); ?></p>
		<p>
			<?php
			echo esc_html(
				$wsm['have']
					? sprintf( /* translators: 1: $wsm['have'], 2: $wsm['count'], 3: rrt_size( $wsm['bytes'] ). */ __( '%1$d of %2$d files present (%3$s).', 'foxfire-studio' ), $wsm['have'], $wsm['count'], rrt_size( $wsm['bytes'] ) )
					: __( 'Not on this site yet. When chosen, the larger model loads from Hugging Face.', 'foxfire-studio' )
			);
			?>
		</p>
		<p>
			<button type="button" class="button" id="rrt-whisper-small-get"><?php echo $wsm['have'] === $wsm['count'] ? esc_html__( 'Download again', 'foxfire-studio' ) : esc_html__( 'Download the larger model to this site', 'foxfire-studio' ); ?></button>
			<span class="rrt-hint"><?php esc_html_e( 'About 570 MB of your hosting space, with the graphics chip\'s files. Downloads one file at a time, skipping any already here; the large files can take a few minutes each. Keep this page open until it finishes.', 'foxfire-studio' ); ?></span>
		</p>
		<ul id="rrt-whisper-small-files" class="rrt-files">
			<?php foreach ( $wsm['files'] as $f ) : ?>
				<li data-i="<?php echo (int) $f['i']; ?>" data-size="<?php echo (int) $f['size']; ?>"><code><?php echo esc_html( $f['name'] ); ?></code> <span><?php echo esc_html( rrt_size( $f['size'] ) ); ?></span></li>
			<?php endforeach; ?>
		</ul>

		<h3><?php esc_html_e( 'Other languages', 'foxfire-studio' ); ?></h3>
		<p><?php echo esc_html( sprintf( /* translators: %s: language name. */ __( 'A reading in any language but English is reviewed by Whisper\'s multilingual models, the same two sizes as above, with the reading\'s language set so Whisper writes down what it hears and never translates it. English readings keep the English models. Each post\'s language is chosen in its Reading audio box (Reading language); otherwise it is the site\'s language, %s now (Settings, General, Site Language).', 'foxfire-studio' ), rrt_languages()[ rrt_site_lang() ] ) ); ?></p>
		<p>
			<?php
			echo esc_html(
				$wmu['have']
					? sprintf( /* translators: 1: $wmu['have'], 2: $wmu['count'], 3: rrt_size( $wmu['bytes'] ). */ __( '%1$d of %2$d files present (%3$s).', 'foxfire-studio' ), $wmu['have'], $wmu['count'], rrt_size( $wmu['bytes'] ) )
					: __( 'Not on this site yet. When a reading in another language is reviewed, the models load from Hugging Face.', 'foxfire-studio' )
			);
			?>
		</p>
		<p>
			<button type="button" class="button" id="rrt-whisper-multi-get"><?php echo $wmu['have'] === $wmu['count'] ? esc_html__( 'Download again', 'foxfire-studio' ) : esc_html__( 'Download the other-languages models to this site', 'foxfire-studio' ); ?></button>
			<span class="rrt-hint"><?php esc_html_e( 'About 650 MB of your hosting space: the standard size (about 77 MB), the larger size (about 249 MB) and its graphics chip files (about 322 MB). Uses the code above, so download that first. Downloads one file at a time, skipping any already here; keep this page open until it finishes.', 'foxfire-studio' ); ?></span>
		</p>
		<ul id="rrt-whisper-multi-files" class="rrt-files">
			<?php foreach ( $wmu['files'] as $f ) : ?>
				<li data-i="<?php echo (int) $f['i']; ?>" data-size="<?php echo (int) $f['size']; ?>"><code><?php echo esc_html( $f['name'] ); ?></code> <span><?php echo esc_html( rrt_size( $f['size'] ) ); ?></span></li>
			<?php endforeach; ?>
		</ul>

		<h2><?php esc_html_e( 'Voice following on this site', 'foxfire-studio' ); ?></h2>
		<p><?php esc_html_e( 'Follow my voice (in the teleprompter\'s Controls, under Reading) scrolls the script as you read, with a small speech model that runs on your own device. Your voice never leaves the browser. The speech engine comes with the plugin; the model loads from Hugging Face unless it is on this site.', 'foxfire-studio' ); ?></p>
		<p>
			<?php
			echo esc_html(
				$wfo['have']
					? sprintf( /* translators: 1: $wfo['have'], 2: $wfo['count'], 3: rrt_size( $wfo['bytes'] ). */ __( '%1$d of %2$d files present (%3$s).', 'foxfire-studio' ), $wfo['have'], $wfo['count'], rrt_size( $wfo['bytes'] ) )
					: __( 'Not on this site yet. Voice following loads its model from Hugging Face.', 'foxfire-studio' )
			);
			?>
		</p>
		<p>
			<button type="button" class="button" id="rrt-follow-get"><?php echo $wfo['have'] === $wfo['count'] ? esc_html__( 'Download again', 'foxfire-studio' ) : esc_html__( 'Download voice following to this site', 'foxfire-studio' ); ?></button>
			<span class="rrt-hint"><?php esc_html_e( 'About 45 MB of your hosting space. Downloads one file at a time; keep this page open until it finishes.', 'foxfire-studio' ); ?></span>
		</p>
		<ul id="rrt-follow-files" class="rrt-files">
			<?php foreach ( $wfo['files'] as $f ) : ?>
				<li data-i="<?php echo (int) $f['i']; ?>"><code><?php echo esc_html( $f['name'] ); ?></code> <span><?php echo esc_html( rrt_size( $f['size'] ) ); ?></span></li>
			<?php endforeach; ?>
		</ul>

		<h2><?php esc_html_e( 'Fonts, MP3 encoder and noise model on this site', 'foxfire-studio' ); ?></h2>
		<p><?php esc_html_e( 'The teleprompter page loads its fonts from Google Fonts and its MP3 encoder and noise model from jsDelivr. With copies on this site, it makes no outside requests apart from Whisper and voice following (above) when their files are not here.', 'foxfire-studio' ); ?></p>
		<table class="form-table" role="presentation">
			<tr><th scope="row"><?php esc_html_e( 'Fonts', 'foxfire-studio' ); ?></th><td id="rrt-st-fonts"><?php echo esc_html( $lst['fonts'] ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'MP3 encoder', 'foxfire-studio' ); ?></th><td id="rrt-st-encoder"><?php echo esc_html( $lst['encoder'] ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Noise model', 'foxfire-studio' ); ?></th><td id="rrt-st-model"><?php echo esc_html( $lst['model'] ); ?></td></tr>
		</table>
		<p>
			<button type="button" class="button" id="rrt-local-get"><?php echo $lst['complete'] ? esc_html__( 'Download again', 'foxfire-studio' ) : esc_html__( 'Download to this site', 'foxfire-studio' ); ?></button>
			<span class="rrt-hint"><?php esc_html_e( 'A few hundred KB to a few MB, depending on the fonts. If you change the font link, save, then download again. Until then the new fonts load from Google Fonts.', 'foxfire-studio' ); ?></span>
		</p>
		<ul id="rrt-local-log" class="rrt-files"></ul>

		<?php if ( function_exists( 'rrt_listen_counts' ) ) : ?>
			<h2><?php esc_html_e( 'Listener countries', 'foxfire-studio' ); ?></h2>
			<p><?php esc_html_e( 'Each listener\'s country is worked out from their IP address while the visit is being recorded, and only the country is kept. Your host or CDN may send it; otherwise this site uses its own copy of DB-IP\'s free country database, refreshed each month.', 'foxfire-studio' ); ?></p>
			<p><strong><?php esc_html_e( 'Country database:', 'foxfire-studio' ); ?></strong> <span id="rrt-geo-status"><?php echo esc_html( rrt_geo_status() ); ?></span></p>
			<p>
				<button type="button" class="button" id="rrt-geo-get"><?php echo rrt_geo_ready() ? esc_html__( 'Download again', 'foxfire-studio' ) : esc_html__( 'Download to this site', 'foxfire-studio' ); ?></button>
				<span class="rrt-hint"><?php esc_html_e( 'A download of up to 30 MB, saved as a file of about 5 MB. It can take a minute.', 'foxfire-studio' ); ?></span>
				<span id="rrt-geo-msg"></span>
			</p>
			<?php $rrt_ipc = rrt_listen_ip_check(); ?>
			<p><strong><?php esc_html_e( 'Your visit right now:', 'foxfire-studio' ); ?></strong>
				<?php
				echo esc_html(
					( 'database' === $rrt_ipc['from']
						/* translators: %s: country. */
						? sprintf( __( '%s, from the country database.', 'foxfire-studio' ), '' !== $rrt_ipc['country'] ? $rrt_ipc['country'] : __( 'unknown', 'foxfire-studio' ) )
						/* translators: 1: country, 2: where it came from. */
						: sprintf( __( '%1$s, sent by your host or CDN (%2$s).', 'foxfire-studio' ), '' !== $rrt_ipc['country'] ? $rrt_ipc['country'] : __( 'unknown', 'foxfire-studio' ), $rrt_ipc['from'] ) )
					. ( $rrt_ipc['trusted'] ? ' ' . ( 'REMOTE_ADDR' === $rrt_ipc['source'] ? __( 'This visit came through one of your trusted proxies; the address was read from the connection, because the proxy sent no usable header.', 'foxfire-studio' ) : __( 'This visit came through one of your trusted proxies; the address was read from its header.', 'foxfire-studio' ) ) : ( $rrt_ipc['proxy'] ? ' ' . sprintf( /* translators: 1: forwarded address, 2: country. */ __( 'The server also received a forwarded address (%1$s) that the database places in %2$s. It is ignored. If your site is behind a CDN or proxy, add it under Trusted proxies above.', 'foxfire-studio' ), $rrt_ipc['proxy'], '' !== $rrt_ipc['fwd'] ? $rrt_ipc['fwd'] : __( 'an unknown country', 'foxfire-studio' ) ) : __( ' No proxy in between.', 'foxfire-studio' ) ) )
				);
				echo ' ' . esc_html__( 'If this is not where you are, visitors\' countries will be off in the same way.', 'foxfire-studio' );
				?>
			</p>
			<p class="description"><a href="https://db-ip.com">IP Geolocation by DB-IP</a>, <?php echo wp_kses( sprintf( /* translators: %s: link to the licence. */ __( 'used under the %s licence.', 'foxfire-studio' ), '<a href="https://creativecommons.org/licenses/by/4.0/">Creative Commons Attribution 4.0</a>' ), array( 'a' => array( 'href' => true ) ) ); ?></p>

			<h2><?php esc_html_e( 'Recent listens', 'foxfire-studio' ); ?></h2>
			<?php
			$rrt_lc  = rrt_listen_counts();
			$rrt_rec = rrt_listen_recent( 25 );
			$rrt_ago = get_option( 'rrt_listen_rolled_at' );
			?>
			<p>
				<?php
				/* translators: 1: number of visitors, 2: number who pressed play. */
				echo esc_html( sprintf( _n( 'Today: %1$s visitor to posts with a reading, %2$s pressed play (each listener counted once per reading per day).', 'Today: %1$s visitors to posts with a reading, %2$s pressed play (each listener counted once per reading per day).', $rrt_lc['today_views'], 'foxfire-studio' ), number_format_i18n( $rrt_lc['today_views'] ), number_format_i18n( $rrt_lc['today_plays'] ) ) );
				echo ' ';
				echo esc_html(
					$rrt_ago
						/* translators: 1: page views, 2: days, 3: how long ago, such as 5 mins. */
						? sprintf( _n( '%1$s page views kept; daily totals for %2$s day, last brought up to date %3$s ago.', '%1$s page views kept; daily totals for %2$s days, last brought up to date %3$s ago.', $rrt_lc['days'], 'foxfire-studio' ), number_format_i18n( $rrt_lc['sessions'] ), number_format_i18n( $rrt_lc['days'] ), human_time_diff( (int) $rrt_ago ) )
						/* translators: 1: page views, 2: days. */
						: sprintf( _n( '%1$s page views kept; daily totals for %2$s day.', '%1$s page views kept; daily totals for %2$s days.', $rrt_lc['days'], 'foxfire-studio' ), number_format_i18n( $rrt_lc['sessions'] ), number_format_i18n( $rrt_lc['days'] ) )
				);
				echo ' ' . esc_html__( 'This list is for checking that listens arrive; the full picture is in the Foxfire Studio menu.', 'foxfire-studio' );
				?>
			</p>
			<?php if ( $rrt_rec ) : ?>
				<table class="widefat striped rrt-listens">
					<thead><tr><th><?php esc_html_e( 'When', 'foxfire-studio' ); ?></th><th><?php esc_html_e( 'Post', 'foxfire-studio' ); ?></th><th><?php esc_html_e( 'Came from', 'foxfire-studio' ); ?></th><th><?php esc_html_e( 'Device', 'foxfire-studio' ); ?></th><th><?php esc_html_e( 'Country', 'foxfire-studio' ); ?></th><th><?php esc_html_e( 'Played', 'foxfire-studio' ); ?></th><th><?php esc_html_e( 'Heard', 'foxfire-studio' ); ?></th><th><?php esc_html_e( 'Furthest', 'foxfire-studio' ); ?></th><th><?php esc_html_e( 'Speed', 'foxfire-studio' ); ?></th><th><?php esc_html_e( 'Ended by', 'foxfire-studio' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $rrt_rec as $r ) : ?>
							<?php
							$rrt_from = $r['channel'] . ( '' !== $r['utm_campaign'] ? ' (' . $r['utm_campaign'] . ')' : '' );
							if ( '' !== $r['referrer_domain'] && ! in_array( $r['channel'], array( 'direct', 'internal' ), true ) ) {
								$rrt_from .= ', ' . $r['referrer_domain'];
							}
							$rrt_end = array( 'ended' => __( 'reaching the end', 'foxfire-studio' ), 'pause' => __( 'pausing', 'foxfire-studio' ), 'closed' => __( 'closing the page while playing', 'foxfire-studio' ), 'left' => __( 'leaving the page', 'foxfire-studio' ), 'noplay' => __( 'leaving without playing', 'foxfire-studio' ), '' => __( 'still open', 'foxfire-studio' ) );
							?>
							<tr<?php echo $r['junk'] ? ' class="rrt-junk" title="' . esc_attr__( 'Not counted: timing no real player can produce', 'foxfire-studio' ) . '"' : ''; ?>>
								<td><?php echo esc_html( wp_date( 'M j, g:i a', strtotime( $r['started_at'] . ' UTC' ) ) ); ?></td>
								<td><?php echo esc_html( get_the_title( (int) $r['post_id'] ) ); ?></td>
								<td><?php echo esc_html( $rrt_from ); ?></td>
								<td><?php echo esc_html( $r['device'] ); ?></td>
								<td><?php echo esc_html( '' !== $r['country'] ? $r['country'] : 'unknown' ); ?></td>
								<td><?php echo $r['played'] ? esc_html( 'yes, after ' . round( (float) $r['first_play_s'] ) . ' s' ) : 'no'; ?></td>
								<td><?php echo esc_html( rrt_clock( $r['duration_s'] ? min( (int) $r['buckets_heard'] * 5, (float) $r['duration_s'] ) : (int) $r['buckets_heard'] * 5 ) ); ?></td>
								<td><?php echo esc_html( rrt_clock( (float) $r['max_position_s'] ) . ( $r['duration_s'] ? ' of ' . rrt_clock( (float) $r['duration_s'] ) : '' ) ); ?></td>
								<td><?php echo esc_html( $r['rate_n'] ? round( $r['rate_sum'] / $r['rate_n'], 2 ) . '×' . ( $r['rate_changes'] ? ', changed ' . (int) $r['rate_changes'] . '×' : '' ) : '' ); ?></td>
								<td><?php echo esc_html( isset( $rrt_end[ $r['exit_type'] ] ) ? $rrt_end[ $r['exit_type'] ] : $r['exit_type'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p><em><?php esc_html_e( 'No listens yet.', 'foxfire-studio' ); ?></em></p>
			<?php endif; ?>

			<h2 id="rrt-privacy-text-h"><?php esc_html_e( 'For your privacy policy', 'foxfire-studio' ); ?></h2>
			<p><?php esc_html_e( 'A paragraph describing the listener analytics and any of its options that are on, ready to paste. It is also offered in WordPress\'s own privacy policy guide (Settings, Privacy).', 'foxfire-studio' ); ?></p>
			<textarea readonly rows="9" class="large-text" aria-labelledby="rrt-privacy-text-h" onclick="this.select()"><?php echo esc_textarea( rrt_listen_privacy_text() ); ?></textarea>
		<?php endif; ?>
	</div>
	<style>
		.rrt-hint { color: #646970; margin-left: 8px; }
		.rrt-nums label, .rrt-check { display: block; margin: 0 0 8px; }
		.rrt-nums input { width: 80px; }
		.rrt-files { margin-left: 1em; }
		.rrt-listens td, .rrt-listens th { font-size: 12px; }
		.rrt-listens .rrt-junk td { color: #a7aaad; text-decoration: line-through; }
		.rrt-files li span { color: #646970; margin-left: 6px; }
		.rrt-files li.is-ok span { color: #008a20; }
		.rrt-files li.is-bad span { color: #d63638; }
		.rrt-files li.is-busy span { color: #2271b1; }
		.rrt-swatch { display: inline-block; width: 22px; height: 22px; border-radius: 4px; border: 1px solid #c3c4c7; vertical-align: middle; margin-right: 8px; }
		.rrt-readable-table { max-width: 820px; margin-top: 6px; }
		.rrt-readable-sample { display: inline-block; min-width: 2.2em; padding: 0 6px; border: 1px solid #c3c4c7; border-radius: 3px; font-weight: 600; text-align: center; }
		.rrt-look-preview { position: relative; max-width: 520px; padding: 18px 20px 26px; border-radius: 8px; border: 1px solid #c3c4c7; font: 20px/1.5 Georgia, serif; }
		.rrt-look-btn { display: inline-block; margin-left: 12px; padding: 3px 14px; border-radius: 999px; font: 600 13px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; vertical-align: middle; }
		.rrt-look-bar { position: absolute; left: 0; bottom: 0; height: 4px; width: 45%; border-radius: 0 2px 0 8px; }
		.rrt-look-now { font-weight: 600; }
		input.rrt-filled, select.rrt-filled { background: #f6f7f7; color: #50575e; }
	</style>
	<script>
	/* Finishing presets (1.6.0): each preset fills in its numbers; Custom lets them be changed. */
	(function () {
		var sel = document.getElementById('rrt-sound-finish');
		if (!sel) { return; }
		var data = {};
		try { data = JSON.parse(sel.getAttribute('data-finish')) || {}; } catch (e) { data = {}; }
		var help = document.getElementById('rrt-finish-help');
		var lufs = document.getElementById('rrt-sound-lufs');
		var tp = document.getElementById('rrt-sound-tp');
		var ch = document.getElementById('rrt-sound-ch');
		var rows = document.querySelectorAll('.rrt-finish-numbers');
		function show() {
			var d = data[sel.value] || {};
			var custom = sel.value === 'custom';
			if (help) { help.textContent = d.help || ''; }
			Array.prototype.forEach.call(rows, function (r) { r.hidden = d.kind === 'off'; });
			[lufs, tp, ch].forEach(function (f) { f.readOnly = !custom; f.classList.toggle('rrt-filled', !custom); });
			ch.disabled = !custom;
			if (custom) {
				lufs.value = lufs.getAttribute('data-custom');
				tp.value = tp.getAttribute('data-custom');
				ch.value = ch.getAttribute('data-custom');
			} else {
				lufs.value = d.lufs === null ? '' : d.lufs;
				lufs.closest('tr').hidden = d.lufs === null || d.kind === 'off';
				tp.value = d.tp === null ? '' : d.tp;
				ch.value = d.ch === 2 ? 'stereo' : 'mono';
			}
			if (custom) { lufs.closest('tr').hidden = false; }
		}
		[lufs, tp, ch].forEach(function (f) {
			f.addEventListener('change', function () { if (sel.value === 'custom') { f.setAttribute('data-custom', f.value); } });
		});
		/* Only Custom's own numbers are sent, so a preset never overwrites them. */
		sel.form.addEventListener('submit', function () {
			if (sel.value !== 'custom') {
				lufs.value = lufs.getAttribute('data-custom');
				tp.value = tp.getAttribute('data-custom');
				ch.disabled = false;
				ch.value = ch.getAttribute('data-custom');
			}
		});
		sel.addEventListener('change', show);
		show();
	})();
	</script>
	<script>
	/* Music bed (0.24.0): add tracks from the media library, remove them. */
	(function () {
		var add = document.getElementById('rrt-music-add');
		var list = document.getElementById('rrt-music-list');
		if (!add || !list) { return; }
		var name = <?php echo wp_json_encode( $opt ); ?>;
		var body = list.tBodies[0];
		function next() {
			var n = 0;
			Array.prototype.forEach.call(body.querySelectorAll('.rrt-music-id'), function (i) {
				var m = /\[beds\]\[(\d+)\]/.exec(i.name);
				if (m) { n = Math.max(n, Number(m[1]) + 1); }
			});
			return n;
		}
		function has(id) {
			return Array.prototype.some.call(body.querySelectorAll('.rrt-music-id'), function (i) { return Number(i.value) === Number(id); });
		}
		function cell(tr) { var td = document.createElement('td'); tr.appendChild(td); return td; }
		function row(a) {
			var k = next();
			var tr = document.createElement('tr');
			tr.className = 'rrt-music-row';
			var r = document.createElement('input');
			r.type = 'radio'; r.name = name + '[music][default]'; r.value = a.id; r.setAttribute('aria-label', <?php echo wp_json_encode( __( 'Default', 'foxfire-studio' ) ); ?>);
			cell(tr).appendChild(r);
			var td = cell(tr);
			var h = document.createElement('input');
			h.type = 'hidden'; h.className = 'rrt-music-id'; h.name = name + '[music][beds][' + k + '][id]'; h.value = a.id;
			td.appendChild(h);
			var st = document.createElement('strong'); st.textContent = a.title || a.filename; td.appendChild(st);
			td.appendChild(document.createElement('br'));
			var au = document.createElement('audio'); au.src = a.url; au.controls = true; au.preload = 'none'; au.style.cssText = 'height:32px;max-width:260px';
			td.appendChild(au);
			var c = document.createElement('input');
			c.type = 'text'; c.className = 'regular-text rrt-music-credit'; c.maxLength = 200; c.name = name + '[music][beds][' + k + '][credit]';
			c.placeholder = <?php echo wp_json_encode( __( 'for example: Night Garden by A. Composer, CC BY 4.0', 'foxfire-studio' ) ); ?>;
			cell(tr).appendChild(c);
			var b = document.createElement('button'); b.type = 'button'; b.className = 'button-link rrt-music-remove'; b.textContent = <?php echo wp_json_encode( __( 'Remove', 'foxfire-studio' ) ); ?>;
			cell(tr).appendChild(b);
			body.appendChild(tr);
		}
		add.addEventListener('click', function (e) {
			e.preventDefault();
			/* The media library's scripts load at the foot of the page, so they are looked for on each click. */
			if (!window.wp || !wp.media) { return; }
			var f = wp.media({ title: <?php echo wp_json_encode( __( 'Choose music for the music bed', 'foxfire-studio' ) ); ?>, button: { text: <?php echo wp_json_encode( __( 'Add to the music bed', 'foxfire-studio' ) ); ?> }, library: { type: 'audio' }, multiple: true });
			f.on('select', function () {
				f.state().get('selection').each(function (m) {
					var a = m.toJSON();
					if (!has(a.id)) { row(a); }
				});
			});
			f.open();
		});
		body.addEventListener('click', function (e) {
			var b = e.target.closest('.rrt-music-remove');
			if (!b) { return; }
			e.preventDefault();
			var tr = b.closest('tr');
			var r = tr.querySelector('input[type=radio]');
			if (r ? r.checked : false) { document.getElementById('rrt-music-def-0').checked = true; }
			tr.parentNode.removeChild(tr);
		});
	})();
	/* Intro video (0.23.0): pick one from the media library, or none. */
	(function () {
		var pick = document.getElementById('rrt-intro-pick');
		var none = document.getElementById('rrt-intro-none');
		var field = document.getElementById('rrt-intro');
		var view = document.getElementById('rrt-intro-view');
		if (!pick) { return; }
		pick.addEventListener('click', function (e) {
			e.preventDefault();
			if (!window.wp || !wp.media) { return; }
			var f = wp.media({ title: <?php echo wp_json_encode( __( 'Choose the intro video', 'foxfire-studio' ) ); ?>, button: { text: <?php echo wp_json_encode( __( 'Use this video', 'foxfire-studio' ) ); ?> }, library: { type: 'video' }, multiple: false });
			f.on('select', function () {
				var a = f.state().get('selection').first().toJSON();
				field.value = a.id;
				view.innerHTML = '';
				var v = document.createElement('video');
				v.src = a.url; v.muted = true; v.playsInline = true; v.preload = 'metadata';
				v.style.cssText = 'width:240px;border-radius:6px;vertical-align:middle';
				view.appendChild(v);
				none.hidden = false;
			});
			f.open();
		});
		none.addEventListener('click', function (e) {
			e.preventDefault();
			field.value = '0';
			view.textContent = <?php echo wp_json_encode( __( 'None', 'foxfire-studio' ) ); ?>;
			none.hidden = true;
		});
	})();
	/* Teleprompter look (0.18.0): the swatches and the preview follow the menus. */
	(function () {
		var sel = document.querySelectorAll('select.rrt-tc');
		var pv = document.getElementById('rrt-look-preview');
		if (!sel.length || !pv) { return; }
		function col(role) {
			var s = document.querySelector('select.rrt-tc[data-role="' + role + '"]');
			var o = s ? s.options[s.selectedIndex] : null;
			return o ? o.getAttribute('data-color') : '';
		}
		function paint() {
			Array.prototype.forEach.call(sel, function (s) {
				var sw = s.previousElementSibling;
				if (sw) { sw.style.background = col(s.getAttribute('data-role')); }
			});
			pv.style.background = col('bg');
			pv.style.color = col('text');
			pv.querySelector('.rrt-look-now').style.color = col('accent');
			var b = pv.querySelector('.rrt-look-btn');
			b.style.background = col('accent');
			b.style.color = col('bg');
			pv.querySelector('.rrt-look-bar').style.background = 'linear-gradient(90deg,' + col('accent2') + ',' + col('accent') + ')';
		}
		Array.prototype.forEach.call(sel, function (s) { s.addEventListener('change', paint); });
	}());
	(function () {
		var btn = document.getElementById('rrt-geo-get');
		if (!btn) { return; }
		var msg = document.getElementById('rrt-geo-msg');
		btn.addEventListener('click', function () {
			btn.disabled = true;
			msg.textContent = <?php echo wp_json_encode( __( ' Downloading and converting...', 'foxfire-studio' ) ); ?>;
			var body = new FormData();
			body.append('action', 'rrt_geo_fetch');
			body.append('nonce', <?php echo wp_json_encode( wp_create_nonce( 'rrt_geo' ) ); ?>);
			fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, { method: 'POST', body: body, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.catch(function () { return { success: false, data: { message: <?php echo wp_json_encode( __( 'The request timed out or was refused.', 'foxfire-studio' ) ); ?> } }; })
				.then(function (r) {
					btn.disabled = false;
					if (r && r.data && r.data.status) { document.getElementById('rrt-geo-status').textContent = r.data.status; }
					msg.textContent = (r && r.success) ? <?php echo wp_json_encode( __( ' Done.', 'foxfire-studio' ) ); ?> : ' ' + ((r && r.data && r.data.message) || <?php echo wp_json_encode( __( 'Something went wrong.', 'foxfire-studio' ) ); ?>);
					if (r && r.success) { btn.textContent = <?php echo wp_json_encode( __( 'Download again', 'foxfire-studio' ) ); ?>; }
				});
		});
	})();
	</script>
	<?php
}
