<?php
/**
 * Plugin Name:       Foxfire Studio
 * Plugin URI:        https://github.com/FoxfireRising/Foxfire-Studio
 * Description:       A private studio for reading your posts aloud: a teleprompter that follows your voice, recording with podcast-ready MP3s, a Whisper review of your delivery, text-based editing, a listening player for visitors, a dashboard, a report by email, and captioned clips for social media. Formerly Reading Room Teleprompter. Open it from Tools, then Teleprompter, or from the Foxfire Studio menu.
 * Version:           1.36.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            FoxfireRising
 * Author URI:        https://github.com/FoxfireRising
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       foxfire-studio
 * Domain Path:       /languages
 *
 * Only logged-in users who can edit posts can open it. Visitors never see it,
 * and it changes nothing on the public side of the site.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Foxfire Studio was called Reading Room Teleprompter, in the folder
 * reading-room-teleprompter, until 0.11.1. Both copies define the same
 * functions, so they must never run together. If the old copy is already
 * loaded in this request (it is active while this one is being activated),
 * this copy stays idle and, on activation, switches the old one off. From
 * the next page load only Foxfire Studio runs, with the same settings and
 * data.
 */
if ( defined( 'RRT_VERSION' ) ) {
	register_activation_hook(
		__FILE__,
		function () {
			deactivate_plugins( 'reading-room-teleprompter/reading-room-teleprompter.php', true );
		}
	);
	return;
}

define( 'RRT_VERSION', '1.36.1' );
define( 'RRT_FILE', __FILE__ );
define( 'RRT_DIR', plugin_dir_path( __FILE__ ) );
define( 'RRT_URL', plugin_dir_url( __FILE__ ) );

/* Translations (0.25.0): languages/ in this folder, or wp-content/languages/plugins/. */
add_action(
	'init',
	function () {
		load_plugin_textdomain( 'foxfire-studio', false, dirname( plugin_basename( RRT_FILE ) ) . '/languages' );
	},
	0
);

require_once RRT_DIR . 'includes/i18n.php';
require_once RRT_DIR . 'includes/settings.php';
require_once RRT_DIR . 'includes/safety.php';
require_once RRT_DIR . 'includes/finish.php';
require_once RRT_DIR . 'includes/polish.php';
require_once RRT_DIR . 'includes/whisper.php';
require_once RRT_DIR . 'includes/follow.php';
require_once RRT_DIR . 'includes/stronger.php';
require_once RRT_DIR . 'includes/local.php';
require_once RRT_DIR . 'includes/theme-look.php';
require_once RRT_DIR . 'includes/a11y.php';
require_once RRT_DIR . 'includes/render.php';
require_once RRT_DIR . 'includes/player.php';
require_once RRT_DIR . 'includes/block.php';
require_once RRT_DIR . 'includes/embeds.php';
require_once RRT_DIR . 'includes/admin.php';
require_once RRT_DIR . 'includes/setup.php';
require_once RRT_DIR . 'includes/stats.php';
require_once RRT_DIR . 'includes/listen.php';
require_once RRT_DIR . 'includes/geo.php';
require_once RRT_DIR . 'includes/dashboard.php';
require_once RRT_DIR . 'includes/help.php';
require_once RRT_DIR . 'includes/insights.php';
require_once RRT_DIR . 'includes/report.php';
require_once RRT_DIR . 'includes/report-admin.php';
require_once RRT_DIR . 'includes/clip.php';
require_once RRT_DIR . 'includes/video.php';
require_once RRT_DIR . 'includes/suggest.php';
require_once RRT_DIR . 'includes/keep.php';
require_once RRT_DIR . 'includes/notes.php';
require_once RRT_DIR . 'includes/cues.php';
require_once RRT_DIR . 'includes/kinds.php';
require_once RRT_DIR . 'includes/languages.php';
require_once RRT_DIR . 'includes/learned-post.php';
require_once RRT_DIR . 'includes/share.php';
require_once RRT_DIR . 'includes/sent.php';
require_once RRT_DIR . 'includes/live.php';
require_once RRT_DIR . 'includes/shelf.php';
require_once RRT_DIR . 'includes/podcast.php';
require_once RRT_DIR . 'includes/feed.php';
require_once RRT_DIR . 'includes/collections.php';
require_once RRT_DIR . 'includes/audiobook.php';
require_once RRT_DIR . 'includes/members.php';
require_once RRT_DIR . 'includes/sponsors.php';
require_once RRT_DIR . 'includes/offload.php';
require_once RRT_DIR . 'includes/backlog.php';
require_once RRT_DIR . 'includes/pronounce.php';
require_once RRT_DIR . 'includes/agents.php';
require_once RRT_DIR . 'includes/netlist.php';
require_once RRT_DIR . 'includes/downloads.php';
require_once RRT_DIR . 'includes/dl-method.php';
require_once RRT_DIR . 'includes/dl-admin.php';
require_once RRT_DIR . 'includes/rename.php';
