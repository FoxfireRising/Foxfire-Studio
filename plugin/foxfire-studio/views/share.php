<?php
/**
 * A shared take (0.17.0), or the list of shares. Included by
 * rrt_share_render(), which sets $rrt_cfg, $rrt_share, $rrt_role, $rrt_is_list,
 * $rrt_list and $rrt_boot.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$rrt_page_title = $rrt_boot ? sprintf( /* translators: %s: title of the reading. */ __( 'Feedback: %s', 'foxfire-studio' ), $rrt_boot['title'] ) : __( 'Shared takes', 'foxfire-studio' );
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $rrt_page_title ); ?></title>
<?php if ( ! empty( $rrt_cfg['fonts_local'] ) ) : ?>
<link rel="stylesheet" href="<?php echo esc_url( $rrt_cfg['fonts_local'] ); ?>">
<?php elseif ( ! empty( $rrt_cfg['fonts']['google_url'] ) ) : ?>
<link rel="stylesheet" href="<?php echo esc_url( $rrt_cfg['fonts']['google_url'] ); ?>">
<?php endif; ?>
<style><?php echo rrt_tokens_css( $rrt_cfg ); // phpcs:ignore WordPress.Security.EscapeOutput -- values are sanitised in rrt_tokens_css(). ?></style>
<link rel="stylesheet" href="<?php echo esc_url( RRT_URL . 'assets/css/share.css?ver=' . RRT_VERSION ); ?>">
</head>
<body class="sh">
<?php if ( $rrt_is_list ) : ?>
	<main class="sh-list">
		<h1><?php esc_html_e( 'Shared takes', 'foxfire-studio' ); ?></h1>
		<?php if ( ! $rrt_list ) : ?>
			<p class="sh-muted"><?php esc_html_e( 'Nothing has been shared with you yet.', 'foxfire-studio' ); ?></p>
		<?php endif; ?>
		<ul>
			<?php foreach ( $rrt_list as $rrt_s ) : ?>
				<?php $rrt_o = get_userdata( (int) $rrt_s->owner_id ); ?>
				<li>
					<a href="<?php echo esc_url( rrt_share_url( $rrt_s->token ) ); ?>"><?php echo esc_html( $rrt_s->title ); ?></a>
					<span class="sh-muted"><?php
					$rrt_who  = (int) $rrt_s->owner_id === get_current_user_id() ? __( 'yours', 'foxfire-studio' ) : sprintf( /* translators: %s: name of the person who shared it. */ __( 'from %s', 'foxfire-studio' ), $rrt_o ? $rrt_o->display_name : '' );
					$rrt_open = rrt_share_open( $rrt_s ) ? sprintf( /* translators: %s: date. */ __( 'open until %s', 'foxfire-studio' ), wp_date( 'M j', strtotime( $rrt_s->ends_at . ' UTC' ) ) ) : __( 'ended', 'foxfire-studio' );
					/* translators: 1: whose take, 2: whether it is still open. */
					echo esc_html( sprintf( __( '%1$s, %2$s', 'foxfire-studio' ), $rrt_who, $rrt_open ) );
					?></span>
				</li>
			<?php endforeach; ?>
		</ul>
	</main>
<?php elseif ( ! $rrt_boot ) : ?>
	<main class="sh-list">
		<h1><?php esc_html_e( 'Not available', 'foxfire-studio' ); ?></h1>
		<p class="sh-muted"><?php esc_html_e( 'This take was not shared with your account, or its share has ended. Ask the person who shared it to share it again.', 'foxfire-studio' ); ?></p>
		<p><a href="<?php echo esc_url( rrt_share_url() ); ?>"><?php esc_html_e( 'Takes shared with you', 'foxfire-studio' ); ?></a></p>
	</main>
<?php else : ?>
	<header class="sh-head">
		<div class="sh-top">
			<span class="sh-brand"><?php esc_html_e( 'Feedback', 'foxfire-studio' ); ?></span>
			<h1 id="shTitle"></h1>
		</div>
		<p class="sh-sub" id="shSub"></p>
		<audio id="shAudio" controls preload="auto"></audio>
		<p class="sh-msg" id="shMsg" aria-live="polite"></p>
	</header>
	<div class="sh-body">
		<section class="sh-script" id="shScript" aria-label="<?php esc_attr_e( 'The script', 'foxfire-studio' ); ?>"></section>
		<aside class="sh-side">
			<div class="sh-form" id="shForm" hidden>
				<h2><?php esc_html_e( 'Add a comment', 'foxfire-studio' ); ?></h2>
				<p class="sh-at" id="shAt"></p>
				<textarea id="shText" rows="4" maxlength="2000" placeholder="<?php esc_attr_e( 'What did you hear? Pace, a word, a feeling, anything.', 'foxfire-studio' ); ?>"></textarea>
				<label class="sh-name"><?php esc_html_e( 'Your name', 'foxfire-studio' ); ?> <input type="text" id="shName" maxlength="100"></label>
				<div class="sh-row">
					<button type="button" class="sh-btn primary" id="shAdd"><?php esc_html_e( 'Add comment', 'foxfire-studio' ); ?></button>
					<button type="button" class="sh-btn" id="shNow" title="<?php esc_attr_e( 'Pin the comment to the moment the audio is at now', 'foxfire-studio' ); ?>"><?php esc_html_e( 'Use this moment', 'foxfire-studio' ); ?></button>
				</div>
			</div>
			<h2 id="shNotesHead"><?php esc_html_e( 'Comments', 'foxfire-studio' ); ?></h2>
			<ul class="sh-notes" id="shNotes"></ul>
			<div class="sh-row" id="shDoneRow" hidden>
				<button type="button" class="sh-btn primary" id="shDone"></button>
			</div>
			<div class="sh-row" id="shEndRow" hidden>
				<button type="button" class="sh-btn danger" id="shEnd"><?php esc_html_e( 'End this share now', 'foxfire-studio' ); ?></button>
			</div>
			<p class="sh-muted sh-help" id="shHelp"></p>
		</aside>
	</div>
	<script>window.RRTShare = <?php echo wp_json_encode( $rrt_boot ); ?>;</script>
	<?php
	rrt_register_script( 'rrt-share', 'share.js' );
	wp_print_scripts( array( 'rrt-share' ) );
	?>
<?php endif; ?>
</body>
</html>
