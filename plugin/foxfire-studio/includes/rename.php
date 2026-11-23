<?php
/**
 * The move from Reading Room Teleprompter to Foxfire Studio (0.11.1).
 *
 * The plugin moved to a new folder, so WordPress sees the old copy as a
 * separate plugin. Its settings, tables and files are the same ones Foxfire
 * Studio uses. Deleting the old copy from the Plugins screen would normally
 * run its uninstall routine, which removes the settings and the self-hosted
 * fonts, encoder, noise model and country database. While Foxfire Studio is
 * active, that routine is skipped for the old copy, so only its files go.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The old copy's plugin file, relative to the plugins folder. */
function rrt_old_plugin() {
	return 'reading-room-teleprompter/reading-room-teleprompter.php';
}

/** Whether the old copy is still installed. */
function rrt_old_plugin_present() {
	return file_exists( WP_PLUGIN_DIR . '/' . rrt_old_plugin() );
}

/*
 * Deleting the old copy: remove its uninstall.php just before WordPress would
 * run it, so the shared settings and files stay. WordPress then deletes the
 * old folder as usual.
 */
add_action(
	'pre_uninstall_plugin',
	function ( $plugin ) {
		if ( rrt_old_plugin() !== $plugin ) {
			return;
		}
		$file = WP_PLUGIN_DIR . '/' . dirname( rrt_old_plugin() ) . '/uninstall.php';
		if ( file_exists( $file ) ) {
			wp_delete_file( $file );
		}
	}
);

/* A note on the Plugins screen while the old copy is still installed. */
add_action(
	'admin_notices',
	function () {
		if ( ! current_user_can( 'delete_plugins' ) || ! rrt_old_plugin_present() ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'settings_page_rrt-settings', 'toplevel_page_rrt-dashboard' ), true ) ) {
			return;
		}
		echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'Reading Room Teleprompter is now Foxfire Studio.', 'foxfire-studio' ) . '</strong> ' . esc_html__( 'Your settings, readings, stats and self-hosted files carry over. The old copy is switched off and can be deleted from the Plugins screen; while Foxfire Studio is active, deleting it keeps all of your data.', 'foxfire-studio' ) . '</p></div>';
	}
);

/*
 * If the old copy is ever switched on again while Foxfire Studio is active,
 * it does nothing (its code is already loaded) apart from PHP warnings. Switch
 * it off on the next load so only one copy is ever active.
 */
add_action(
	'plugins_loaded',
	function () {
		if ( ! in_array( rrt_old_plugin(), (array) get_option( 'active_plugins', array() ), true ) ) {
			return;
		}
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		deactivate_plugins( rrt_old_plugin(), true );
	},
	0
);
