<?php
/**
 * The teleprompter page. Included by rrt_render(), which sets:
 * $rrt_cfg, $rrt_posts, $rrt_sel, $rrt_blocks, $rrt_title, $rrt_slug, $rrt_boot.
 *
 * @package FoxfireStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<?php if ( ! empty( $rrt_mark['icon'] ) ) : ?>
<link rel="icon" type="image/png" href="<?php echo esc_url( $rrt_mark['icon'] ); ?>">
<?php endif; ?>
<title><?php echo esc_html( $rrt_title ? $rrt_cfg['text']['title'] . ': ' . $rrt_title : $rrt_cfg['text']['title'] ); ?></title>
<?php if ( ! empty( $rrt_cfg['fonts_local'] ) ) : ?>
<link rel="stylesheet" href="<?php echo esc_url( $rrt_cfg['fonts_local'] ); ?>">
<?php elseif ( ! empty( $rrt_cfg['fonts']['google_url'] ) ) : ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="<?php echo esc_url( $rrt_cfg['fonts']['google_url'] ); ?>">
<?php endif; ?>
<style><?php echo rrt_tokens_css( $rrt_cfg ); // phpcs:ignore WordPress.Security.EscapeOutput -- values are sanitised in rrt_tokens_css(). ?></style>
<link rel="stylesheet" href="<?php echo esc_url( RRT_URL . 'assets/css/teleprompter.css?ver=' . RRT_VERSION ); ?>">
<?php if ( ! empty( $rrt_boot['intro'] ) ) : ?>
<?php
/*
 * The intro's cover (0.24.0): when the intro is going to play, the page is
 * black from the first paint until the intro takes over, so the studio never
 * shows for a moment first. The same checks as teleprompter.js makes. The
 * cover lifts by itself after 4 seconds whatever happens. Kept free of double
 * ampersands and line comments, and marked so caching plugins leave it alone.
 */
?>
<style id="rrt-intro-cover">html.rrt-intro-wait, html.rrt-intro-wait body { background: #000 !important; } html.rrt-intro-wait body > * { visibility: hidden !important; } html.rrt-intro-wait body > .tp-intro { visibility: visible !important; }</style>
<script data-no-optimize="1" data-no-defer="1" data-no-minify="1" data-cfasync="false">(function () { try { var d = document.documentElement; if (window.matchMedia) { if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) { return; } } if (/[?&]rrt_sent=/.test(window.location.search)) { return; } if (window.sessionStorage.getItem('rrtIntroSeen') === '1') { return; } if (window.localStorage.getItem('rrtIntro') === 'off') { return; } d.classList.add('rrt-intro-wait'); window.setTimeout(function () { d.classList.remove('rrt-intro-wait'); }, 4000); } catch (e) {} })();</script>
<?php endif; ?>
</head>
<body class="tp-studio look-<?php echo esc_attr( $rrt_look ); ?><?php echo ! empty( $rrt_art ) ? ' has-art' : ''; ?><?php echo $rrt_light ? ' is-light' : ''; ?><?php echo empty( $rrt_blocks ) ? ' is-empty' : ''; ?>">

<?php
/* The page's main heading (1.27.0), hidden on screen: the script's own title line, which for a sponsor message is its first line rather than its name in the list. */
$rrt_h1 = ( ! empty( $rrt_blocks ) && 'title' === $rrt_blocks[0]['type'] ) ? $rrt_blocks[0]['text'] : (string) $rrt_title;
?>
<?php if ( '' !== $rrt_h1 ) : ?>
<h1 class="tp-vh"><?php echo esc_html( $rrt_h1 ); ?></h1>
<?php endif; ?>

<?php if ( ! empty( $rrt_art ) ) : ?>
<div class="tp-art" aria-hidden="true">
	<div class="tp-art-sky"<?php echo $rrt_art['sky'] ? ' style="background-image:url(&quot;' . esc_url( $rrt_art['sky'] ) . '&quot;)"' : ''; ?>></div>
	<?php if ( $rrt_art['fox'] ) : ?>
		<img class="tp-art-fox" src="<?php echo esc_url( $rrt_art['fox'] ); ?>" alt="">
	<?php endif; ?>
</div>
<?php endif; ?>

<div class="tp-intro" id="tpIntro" role="dialog" aria-label="<?php esc_attr_e( 'Foxfire Studio intro', 'foxfire-studio' ); ?>" hidden>
	<video id="tpIntroVideo" playsinline preload="auto"></video>
	<div class="tp-intro-bar">
		<button class="tp-btn" type="button" id="tpIntroSound" hidden><?php esc_html_e( 'Sound on', 'foxfire-studio' ); ?></button>
		<label class="tp-intro-off"><input type="checkbox" id="tpIntroOff"> <?php esc_html_e( 'Skip it from now on', 'foxfire-studio' ); ?></label>
		<button class="tp-btn primary" type="button" id="tpIntroSkip"><?php esc_html_e( 'Skip', 'foxfire-studio' ); ?></button>
	</div>
</div>

<header class="tp-top" id="tpBar">
	<a class="tp-logo" href="<?php echo esc_url( admin_url( 'admin.php?page=rrt-dashboard' ) ); ?>" target="_blank" rel="noopener" title="<?php esc_attr_e( 'Foxfire Studio overview (opens in a new tab)', 'foxfire-studio' ); ?>">
		<span class="tp-logo-mark" aria-hidden="true">
			<?php if ( ! empty( $rrt_art['mark'] ) ) : ?>
				<img src="<?php echo esc_url( $rrt_art['mark'] ); ?>" alt="">
			<?php elseif ( ! empty( $rrt_mark['mark'] ) ) : ?>
				<?php /* The theme look draws the mark as a shape in the accent colour. */ ?>
				<i class="tp-logo-shape" style="-webkit-mask-image:url(&quot;<?php echo esc_url( $rrt_mark['mark'] ); ?>&quot;);mask-image:url(&quot;<?php echo esc_url( $rrt_mark['mark'] ); ?>&quot;)"></i>
			<?php else : ?>
				<svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round"><path d="M9 6l8 13h14l8-13-3 18-12 17-12-17z"/><path d="M17 19l7 8 7-8"/><path d="M19 30l5 4 5-4"/><path d="M12 13l5 6M36 13l-5 6"/></svg>
			<?php endif; ?>
		</span>
		<span class="tp-logo-text">
			<span class="tp-brand"><?php esc_html_e( 'Foxfire Studio', 'foxfire-studio' ); ?></span>
			<span class="tp-sub"><?php echo esc_html( $rrt_cfg['text']['title'] ); ?></span>
		</span>
		<span class="tp-vh"><?php esc_html_e( '(opens in a new tab)', 'foxfire-studio' ); ?></span>
	</a>

	<nav class="tp-nav" aria-label="<?php esc_attr_e( 'Foxfire Studio', 'foxfire-studio' ); ?>">
		<span class="tp-nav-link is-current" aria-current="page"><?php echo esc_html( $rrt_cfg['text']['title'] ); ?></span>
		<?php foreach ( $rrt_nav as $rrt_n ) : ?>
			<a class="tp-nav-link" href="<?php echo esc_url( $rrt_n['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $rrt_n['label'] ); ?> <span class="tp-vh"><?php esc_html_e( '(opens in a new tab)', 'foxfire-studio' ); ?></span></a>
		<?php endforeach; ?>
	</nav>

	<span class="tp-top-end">
		<?php if ( current_user_can( 'manage_options' ) ) : ?>
			<a class="tp-icon-btn" href="<?php echo esc_url( admin_url( 'options-general.php?page=rrt-settings' ) ); ?>" target="_blank" rel="noopener" title="<?php esc_attr_e( 'Foxfire Studio settings (opens in a new tab)', 'foxfire-studio' ); ?>" aria-label="<?php esc_attr_e( 'Settings (opens in a new tab)', 'foxfire-studio' ); ?>">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3.2"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg>
			</a>
		<?php endif; ?>
		<button class="tp-btn tp-panel-toggle" type="button" id="tpPanelToggle" aria-controls="tpPanel" aria-expanded="true"><?php esc_html_e( 'Controls', 'foxfire-studio' ); ?></button>
	</span>
</header>

<div class="tp-script" id="tpScriptBar">
	<div class="tp-group tp-pickwrap">
		<select id="tpPick" aria-label="<?php esc_attr_e( 'Choose a post', 'foxfire-studio' ); ?>">
			<option value=""><?php echo esc_html( $rrt_cfg['text']['choose'] ); ?></option>
			<?php foreach ( $rrt_posts as $rrt_p ) : ?>
				<?php
				$rrt_label = get_the_title( $rrt_p );
				if ( '' === $rrt_label ) {
					$rrt_label = '(untitled)';
				}
				if ( 'publish' !== $rrt_p->post_status ) {
					$rrt_label .= ' (' . $rrt_p->post_status . ')';
				}
				?>
				<option value="<?php echo esc_attr( $rrt_p->ID ); ?>" data-url="<?php echo esc_attr( get_permalink( $rrt_p ) ); ?>" <?php selected( $rrt_sel, $rrt_p->ID ); ?>><?php echo esc_html( $rrt_label ); ?></option>
			<?php endforeach; ?>
		</select>
	</div>

	<div class="tp-group tp-urlwrap">
		<input type="text" id="tpUrl" placeholder="<?php echo esc_attr( $rrt_cfg['text']['paste'] ); ?>" aria-label="<?php esc_attr_e( 'Post URL', 'foxfire-studio' ); ?>">
		<button class="tp-btn" type="button" id="tpLoad"><?php esc_html_e( 'Load', 'foxfire-studio' ); ?></button>
	</div>

	<?php if ( ! empty( $rrt_blocks ) ) : ?>
		<button class="tp-btn tp-openrec" type="button" id="tpReviewFile" title="<?php esc_attr_e( 'Clean up, level and review a recording you already have', 'foxfire-studio' ); ?>"><?php esc_html_e( 'Open a recording', 'foxfire-studio' ); ?></button>
		<input type="file" id="tpFile" accept="audio/*,.mp3,.m4a,.webm,.ogg,.wav" hidden>
		<button class="tp-btn tp-openrec" type="button" id="tpCombineOpen" hidden title="<?php esc_attr_e( 'Build one reading from this reading’s takes: choose which take reads each paragraph or sentence', 'foxfire-studio' ); ?>"><?php esc_html_e( 'Combine takes', 'foxfire-studio' ); ?></button>
		<button class="tp-btn tp-openrec" type="button" id="tpContinue" hidden title="<?php esc_attr_e( 'Record the next part of this reading: starts one sentence before the first paragraph no take on the shelf has read', 'foxfire-studio' ); ?>"><?php esc_html_e( 'Continue', 'foxfire-studio' ); ?></button>
	<?php endif; ?>

	<span class="tp-spacer"></span>
	<span class="tp-hint" id="tpHint" hidden></span>
	<span class="tp-state" id="tpState" aria-live="polite"></span>
</div>

<aside class="tp-panel" id="tpPanel" aria-label="<?php esc_attr_e( 'Controls', 'foxfire-studio' ); ?>">
	<div class="tp-panel-head">
		<span class="tp-panel-title"><?php esc_html_e( 'Controls', 'foxfire-studio' ); ?></span>
		<button class="tp-icon-btn" type="button" id="tpPanelClose" aria-label="<?php esc_attr_e( 'Fold the controls away', 'foxfire-studio' ); ?>" title="<?php esc_attr_e( 'Fold the controls away', 'foxfire-studio' ); ?>">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
		</button>
	</div>

	<section class="tp-sec" aria-labelledby="tpSecRead">
		<h2 id="tpSecRead"><?php esc_html_e( 'Reading', 'foxfire-studio' ); ?></h2>
		<label class="tp-voice-opt tp-switch" id="tpVoiceWrap"><input type="checkbox" id="tpVoice"><span><?php esc_html_e( 'Follow my voice', 'foxfire-studio' ); ?></span></label>

		<div class="tp-group tp-pace tp-slider">
			<label for="tpWpm"><?php esc_html_e( 'Pace', 'foxfire-studio' ); ?></label>
			<span class="tp-val" id="tpWpmVal"><?php esc_html_e( '140 wpm', 'foxfire-studio' ); ?></span>
			<input type="range" id="tpWpm" min="80" max="220" step="5" value="140">
		</div>

		<div class="tp-group tp-slider">
			<label for="tpSize"><?php esc_html_e( 'Size', 'foxfire-studio' ); ?></label>
			<span class="tp-val" id="tpSizeVal"></span>
			<input type="range" id="tpSize" min="24" max="96" step="2" value="40">
		</div>

		<div class="tp-group tp-slider">
			<label for="tpCols"><?php esc_html_e( 'Width', 'foxfire-studio' ); ?></label>
			<input type="range" id="tpCols" min="10" max="30" step="1" value="18">
		</div>

		<div class="tp-group tp-slider">
			<label for="tpLine"><?php esc_html_e( 'Line', 'foxfire-studio' ); ?></label>
			<span class="tp-val" id="tpLineVal"></span>
			<input type="range" id="tpLine" min="18" max="50" step="1" value="30">
		</div>

		<span class="tp-group tp-practice tp-field">
			<label for="tpFont" title="<?php esc_attr_e( 'The typeface for the script: the reading font, the cleaner interface font, or your computer\'s own', 'foxfire-studio' ); ?>"><?php esc_html_e( 'Font', 'foxfire-studio' ); ?></label>
			<select id="tpFont">
				<option value="serif"><?php esc_html_e( 'Serif', 'foxfire-studio' ); ?></option>
				<option value="sans"><?php esc_html_e( 'Sans', 'foxfire-studio' ); ?></option>
				<option value="system"><?php esc_html_e( 'System', 'foxfire-studio' ); ?></option>
			</select>
		</span>

		<label class="tp-pace tp-switch"><input type="checkbox" id="tpBreath" checked><span><?php esc_html_e( 'Breathe between paragraphs', 'foxfire-studio' ); ?></span></label>
		<label class="tp-switch" title="<?php esc_attr_e( 'P, B, the full stop, plus and minus, and [ and ] in the review act on a single key press while the script has focus. Switch them off if voice control or a stray key sets them off; the buttons do the same things.', 'foxfire-studio' ); ?>"><input type="checkbox" id="tpKeys" checked><span><?php esc_html_e( 'Single-key shortcuts', 'foxfire-studio' ); ?></span></label>
		<label class="tp-switch" id="tpIntroWrap" title="<?php esc_attr_e( 'Play the intro video when the studio opens in a new tab, in this browser', 'foxfire-studio' ); ?>" hidden><input type="checkbox" id="tpIntroOpt"><span><?php esc_html_e( 'Play the intro', 'foxfire-studio' ); ?></span></label>
	</section>

	<?php if ( ! empty( $rrt_blocks ) ) : ?>
		<section class="tp-sec tp-pv-sec" aria-labelledby="tpSecPv">
			<h2 id="tpSecPv"><?php esc_html_e( 'Prompter view', 'foxfire-studio' ); ?></h2>
			<button class="tp-btn" type="button" id="tpPvOpen" title="<?php esc_attr_e( 'Full screen with only the script, for a glass teleprompter rig or a screen across the room (P)', 'foxfire-studio' ); ?>"><?php esc_html_e( 'Open prompter view', 'foxfire-studio' ); ?></button>			<span class="tp-group tp-practice tp-field">
				<label for="tpPvMirror" title="<?php esc_attr_e( 'A glass rig shows the screen in a mirror, so the script is flipped to read the right way round. Most rigs need Left to right.', 'foxfire-studio' ); ?>"><?php esc_html_e( 'Mirror', 'foxfire-studio' ); ?></label>
				<select id="tpPvMirror">
					<option value="none"><?php esc_html_e( 'None', 'foxfire-studio' ); ?></option>
					<option value="h"><?php esc_html_e( 'Left to right', 'foxfire-studio' ); ?></option>
					<option value="v"><?php esc_html_e( 'Upside down', 'foxfire-studio' ); ?></option>
					<option value="hv"><?php esc_html_e( 'Both', 'foxfire-studio' ); ?></option>
				</select>
			</span>
			<div class="tp-group tp-slider">
				<label for="tpPvShift" title="<?php esc_attr_e( 'Moves the script left or right, to sit over the camera lens', 'foxfire-studio' ); ?>"><?php esc_html_e( 'Shift', 'foxfire-studio' ); ?></label>
				<span class="tp-val" id="tpPvShiftVal"></span>
				<input type="range" id="tpPvShift" min="-30" max="30" step="1" value="0">
			</div>
			<div class="tp-group tp-slider">
				<label for="tpPvFade" title="<?php esc_attr_e( 'Dims the script far above and below the reading line, so your eyes stay near the lens', 'foxfire-studio' ); ?>"><?php esc_html_e( 'Fade above and below', 'foxfire-studio' ); ?></label>
				<span class="tp-val" id="tpPvFadeVal"></span>
				<input type="range" id="tpPvFade" min="0" max="40" step="1" value="0">
			</div>
			<label class="tp-switch" title="<?php esc_attr_e( 'White text on pure black, which reflects most clearly in glass', 'foxfire-studio' ); ?>"><input type="checkbox" id="tpPvBright"><span><?php esc_html_e( 'Bright text on black', 'foxfire-studio' ); ?></span></label>
			<p class="tp-pv-note"><?php esc_html_e( 'Size, Width and Line are kept separately for prompter view: change them while it is open. Mirror, Shift and Fade only apply in prompter view.', 'foxfire-studio' ); ?></p>
		</section>
	<?php endif; ?>

	<section class="tp-sec" aria-labelledby="tpSecRec">
		<h2 id="tpSecRec"><?php esc_html_e( 'Recording', 'foxfire-studio' ); ?></h2>
		<label class="tp-switch"><input type="checkbox" id="tpRecOpt"><span><?php esc_html_e( 'Record my voice', 'foxfire-studio' ); ?></span></label>
		<label class="tp-switch" title="<?php esc_attr_e( 'Quiets clicks, stray sounds, background hush and whistling S sounds before the MP3 is made', 'foxfire-studio' ); ?>"><input type="checkbox" id="tpCleanOpt"><span><?php esc_html_e( 'Clean up audio', 'foxfire-studio' ); ?></span></label>
		<label class="tp-switch" title="<?php esc_attr_e( 'After recording, Whisper listens back and compares your reading with the script', 'foxfire-studio' ); ?>"><input type="checkbox" id="tpWhisperOpt"><span><?php esc_html_e( 'Whisper review', 'foxfire-studio' ); ?></span></label>
		<label class="tp-switch tp-phone-opt" id="tpPhoneWrap" hidden title="<?php esc_attr_e( 'On a phone: each take goes to the site in pieces while you record, so nothing is lost if the page closes, and waits there for the review on your computer. A 10-minute take sends about 86 MB.', 'foxfire-studio' ); ?>"><input type="checkbox" id="tpPhoneOpt"><span><?php esc_html_e( 'Phone take mode', 'foxfire-studio' ); ?></span></label>
		<span class="tp-group tp-practice tp-field">
			<label for="tpWModel"><?php esc_html_e( 'Model', 'foxfire-studio' ); ?></label>
			<select id="tpWModel" title="<?php esc_attr_e( 'Automatic uses the larger model on this computer\'s graphics chip when it can run there, and the standard model otherwise. Standard is quicker on the processor. Larger hears names, unusual words and quiet endings more accurately; on the processor it takes two to three times as long.', 'foxfire-studio' ); ?>">
				<option value="auto"><?php esc_html_e( 'Automatic', 'foxfire-studio' ); ?></option>
				<option value="base"><?php esc_html_e( 'Standard', 'foxfire-studio' ); ?></option>
				<option value="small"><?php esc_html_e( 'Larger', 'foxfire-studio' ); ?></option>
			</select>
		</span>
		<?php /* What this browser can do (1.30.0), filled in by can.js. */ ?>
		<p class="tp-browser-line" id="tpThisBrowser" data-rrt-can hidden></p>
	</section>

	<?php if ( ! empty( $rrt_blocks ) ) : ?>
		<section class="tp-sec" aria-labelledby="tpSecPrac">
			<h2 id="tpSecPrac"><?php esc_html_e( 'Practice', 'foxfire-studio' ); ?></h2>
			<div class="tp-btn-pair">
				<span class="tp-group tp-practice">
					<button class="tp-btn" type="button" id="tpWarm" title="<?php esc_attr_e( 'About three minutes of breathing, tongue twisters and pacing before you read', 'foxfire-studio' ); ?>"><?php esc_html_e( 'Warm up', 'foxfire-studio' ); ?></button>
				</span>
				<span class="tp-group tp-practice">
					<button class="tp-btn" type="button" id="tpMicCheck" title="<?php esc_attr_e( 'A ten second test of the room and your voice level before recording', 'foxfire-studio' ); ?>"><?php esc_html_e( 'Check microphone', 'foxfire-studio' ); ?></button>
				</span>
				<?php /* 1.33.1: the practice reading is always one button away (hidden on the practice reading itself). */ ?>
				<?php if ( empty( $rrt_boot['practice'] ) && function_exists( 'rrt_practice_url' ) ) : ?>
					<span class="tp-group tp-practice tp-practice-wide">
						<button class="tp-btn" type="button" id="tpPracticeOpen" data-href="<?php echo esc_url( rrt_practice_url() ); ?>" title="<?php esc_attr_e( 'A one-minute script that belongs to no post, for trying the studio or warming up. Its takes never go on a post.', 'foxfire-studio' ); ?>"><?php esc_html_e( 'Practice reading', 'foxfire-studio' ); ?></button>
					</span>
				<?php endif; ?>
			</div>
			<span class="tp-mic-last" id="tpMicLast"></span>
			<?php if ( empty( $rrt_boot['practice'] ) && ! empty( $rrt_boot['practiceUrl'] ) ) : ?>
				<span class="tp-mic-new" id="tpMicNew" hidden><?php esc_html_e( 'New here?', 'foxfire-studio' ); ?> <a href="<?php echo esc_url( $rrt_boot['practiceUrl'] ); ?>"><?php esc_html_e( 'Try the one-minute practice reading', 'foxfire-studio' ); ?></a></span>
			<?php endif; ?>
			<span class="tp-group tp-practice">
				<label class="tp-switch" title="<?php esc_attr_e( 'Click a word to mark a pause or a breath after it, or to emphasise it. Marks are saved with the post; its text is not changed.', 'foxfire-studio' ); ?>"><input type="checkbox" id="tpCues"><span><?php esc_html_e( 'Cues', 'foxfire-studio' ); ?></span></label>
			</span>
			<span class="tp-group tp-practice tp-drill">
				<label class="tp-switch" title="<?php esc_attr_e( 'Read just the opening, again and again, and compare each take with the last', 'foxfire-studio' ); ?>"><input type="checkbox" id="tpDrill"><span><?php esc_html_e( 'Opening drill', 'foxfire-studio' ); ?></span></label>
				<select id="tpDrillSecs" aria-label="<?php esc_attr_e( 'Opening drill length', 'foxfire-studio' ); ?>">
					<option value="20">20 s</option>
					<option value="30">30 s</option>
					<option value="45">45 s</option>
					<option value="60">60 s</option>
				</select>
			</span>
			<?php if ( ! empty( $rrt_boot['kind'] ) && ! empty( $rrt_sel ) ) : ?>
				<span class="tp-group tp-practice tp-field tp-kind">
					<label for="tpKind" title="<?php esc_attr_e( 'Saved with the post: the prompter starts in the middle of its pace range, and the review judges the pace against it. In Verse and Ritual and chant only a deep fade is flagged.', 'foxfire-studio' ); ?>"><?php esc_html_e( 'Kind', 'foxfire-studio' ); ?></label>
					<select id="tpKind">
						<option value=""><?php esc_html_e( 'Not chosen', 'foxfire-studio' ); ?></option>
						<?php foreach ( $rrt_boot['kind']['labels'] as $rrt_kk => $rrt_kl ) : ?>
							<option value="<?php echo esc_attr( $rrt_kk ); ?>"><?php echo esc_html( sprintf( /* translators: 1: kind of reading, 2: lowest pace, 3: highest pace. */ __( '%1$s (%2$d to %3$d wpm)', 'foxfire-studio' ), $rrt_kl, $rrt_boot['kind']['ranges'][ $rrt_kk ][0], $rrt_boot['kind']['ranges'][ $rrt_kk ][1] ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</span>
				<p class="tp-kind-hint" id="tpKindHint" hidden></p>
			<?php endif; ?>
			<span class="tp-group tp-practice tp-field">
				<label for="tpFocus" title="<?php esc_attr_e( 'One habit to work on: the review leads with it and lists its flags first', 'foxfire-studio' ); ?>"><?php esc_html_e( 'Focus', 'foxfire-studio' ); ?></label>
				<select id="tpFocus">
					<option value=""><?php esc_html_e( 'None', 'foxfire-studio' ); ?></option>
					<option value="pace"><?php esc_html_e( 'Pace', 'foxfire-studio' ); ?></option>
					<option value="endings"><?php esc_html_e( 'Endings', 'foxfire-studio' ); ?></option>
					<option value="fillers"><?php esc_html_e( 'Fillers', 'foxfire-studio' ); ?></option>
					<option value="pauses"><?php esc_html_e( 'Pauses', 'foxfire-studio' ); ?></option>
					<option value="pitch"><?php esc_html_e( 'Pitch', 'foxfire-studio' ); ?></option>
					<option value="energy"><?php esc_html_e( 'Energy', 'foxfire-studio' ); ?></option>
					<option value="accuracy"><?php esc_html_e( 'Accuracy', 'foxfire-studio' ); ?></option>
				</select>
			</span>
		</section>
	<?php endif; ?>

	<p class="tp-panel-keys" id="tpKeysHelp"><?php esc_html_e( 'Space or Page Down starts and pauses. Page Up goes back a sentence. Left and right arrows change the pace. Up and down step a paragraph. P opens prompter view. Click any word to start from there, or press Enter while paused. These keys work while the script has focus: Escape from any control goes back to it. Page Up and Page Down work from anywhere. With Cues on, left and right move a word and Enter opens its cues.', 'foxfire-studio' ); ?></p>
	<p class="tp-panel-keys tp-pv-keys"><?php esc_html_e( 'In prompter view: B or the full stop blanks the screen, plus and minus change the size, Escape leaves.', 'foxfire-studio' ); ?></p>
	<?php /* 1.32.0: the Help screen, in a new tab so an open take is never put at risk. */ ?>
	<p class="tp-panel-help"><a href="<?php echo esc_url( function_exists( 'rrt_help_group_url' ) ? rrt_help_group_url( 'record' ) : admin_url() ); ?>" target="_blank" rel="noopener" id="tpHelpLink"><?php esc_html_e( 'Help and guides', 'foxfire-studio' ); ?><span class="tp-vh"> <?php esc_html_e( '(opens in a new tab)', 'foxfire-studio' ); ?></span></a></p>
</aside>

<main class="tp-stage" id="tpStage">
	<div class="tp-frame" aria-hidden="true"><i></i><i></i></div>
	<?php if ( empty( $rrt_blocks ) ) : ?>
		<div class="tp-empty">
			<h1><?php echo esc_html( $rrt_cfg['text']['welcome'] ); ?></h1>
			<p><?php echo esc_html( $rrt_cfg['text']['intro'] ); ?></p>
			<p class="tp-empty-keys"><?php esc_html_e( 'Space starts and pauses. Left and right arrows slow down or speed up. Up and down step back or ahead a paragraph. Click any word to start from there, or press Enter while paused.', 'foxfire-studio' ); ?></p>
			<?php if ( function_exists( 'rrt_practice_url' ) ) : ?>
				<?php /* 1.33.1: a lasting way in, whenever New here is not showing. */ ?>
				<p class="tp-empty-practice" id="tpEmptyPractice"><?php esc_html_e( 'Want a script to try?', 'foxfire-studio' ); ?> <a href="<?php echo esc_url( rrt_practice_url() ); ?>"><?php esc_html_e( 'Open the practice reading', 'foxfire-studio' ); ?></a></p>
			<?php endif; ?>
			<?php if ( ! empty( $rrt_boot['practiceUrl'] ) ) : ?>
				<div class="tp-welcome" id="tpWelcome" hidden>
					<p><strong><?php esc_html_e( 'New here?', 'foxfire-studio' ); ?></strong> <?php esc_html_e( 'Check your microphone and try a one-minute practice reading.', 'foxfire-studio' ); ?></p>
					<?php /* 1.31.0: Follow my voice as a tick, with its size (filled in and shown by studio-core.js where it can run). */ ?>
					<p class="tp-welcome-voice" hidden><label class="tp-switch"><input type="checkbox" id="tpWelcomeVoice"><span><?php esc_html_e( 'Follow my voice as I read', 'foxfire-studio' ); ?></span></label> <span class="tp-welcome-size" id="tpWelcomeVoiceSize"></span></p>
					<p class="tp-welcome-btns"><a class="tp-btn primary" href="<?php echo esc_url( $rrt_boot['practiceUrl'] ); ?>"><?php esc_html_e( 'Try it', 'foxfire-studio' ); ?></a> <button class="tp-btn" type="button" id="tpWelcomeLater"><?php esc_html_e( 'Not now', 'foxfire-studio' ); ?></button></p>
				</div>
			<?php endif; ?>
		</div>
	<?php else : ?>
		<div class="tp-reading-line" aria-hidden="true"><span></span><span></span></div>
		<div class="tp-track" id="tpTrack" data-slug="<?php echo esc_attr( $rrt_slug ); ?>">
			<?php foreach ( $rrt_blocks as $rrt_b ) : ?>
				<div class="tp-block tp-<?php echo esc_attr( $rrt_b['type'] ); ?>"><?php echo esc_html( $rrt_b['text'] ); ?></div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</main>

<?php if ( ! empty( $rrt_blocks ) && ! empty( $rrt_boot['skin']['last'] ) ) : ?>
<section class="tp-last" id="tpLast" aria-labelledby="tpLastTitle" hidden>
	<div class="tp-last-head">
		<h2 id="tpLastTitle"><?php esc_html_e( 'Your last take', 'foxfire-studio' ); ?></h2>
		<span class="tp-last-when" id="tpLastWhen"></span>
		<span class="tp-spacer"></span>
		<a class="tp-btn" href="<?php echo esc_url( $rrt_boot['skin']['progress'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Your progress', 'foxfire-studio' ); ?> <span class="tp-vh"><?php esc_html_e( '(opens in a new tab)', 'foxfire-studio' ); ?></span></a>
		<button class="tp-icon-btn" type="button" id="tpLastClose" aria-label="<?php esc_attr_e( 'Close', 'foxfire-studio' ); ?>" title="<?php esc_attr_e( 'Close', 'foxfire-studio' ); ?>">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
		</button>
	</div>
	<div class="tp-last-tiles" id="tpLastTiles"></div>
	<ul class="tp-last-goals" id="tpLastGoals"></ul>
</section>
<?php endif; ?>

<div class="tp-count" id="tpCount" aria-live="assertive"></div>

<?php if ( ! empty( $rrt_blocks ) ) : ?>
	<div class="tp-pv-strip" id="tpPvStrip" aria-hidden="true">
		<span class="tp-pv-rec" id="tpPvRec"><span class="tp-rec-dot"></span><span id="tpPvRecTime"></span></span>
		<span class="tp-pv-left" id="tpPvLeft"></span>
		<span class="tp-pv-msg" id="tpPvMsg"></span>
	</div>
	<div class="tp-pv-blank" id="tpPvBlank" aria-hidden="true"></div>
<?php endif; ?>

<footer class="tp-dock">
	<div class="tp-pmeter" id="tpPMeter" hidden aria-hidden="true">
		<div class="tp-pmeter-bar"><span class="tp-pmeter-zone" id="tpPMeterZone"></span><i id="tpPMeterFill"></i><b id="tpPMeterPeak"></b></div>
		<div class="tp-pmeter-words"><span class="tp-pmeter-word" id="tpPMeterWord"></span><span class="tp-pmeter-site" id="tpPMeterSite"></span></div>
		<div class="tp-pmeter-energy" id="tpPMeterEnergy" role="status" hidden></div>
		<div class="tp-pmeter-pace" id="tpPMeterPace" hidden></div>
	</div>
	<div class="tp-status" id="tpStatus">
		<span class="tp-progress" id="tpProgress"></span>
		<div class="tp-dock-l">
			<span class="tp-where" id="tpWhere"></span>
			<span class="tp-left" id="tpLeft"></span>
			<span class="tp-heard" id="tpHeard"></span>
		</div>
		<div class="tp-dock-c">
			<span class="tp-wave" id="tpWaveL" aria-hidden="true"></span>
			<button class="tp-btn tp-top-btn" type="button" id="tpRestart" title="<?php esc_attr_e( 'Back to the top', 'foxfire-studio' ); ?>"><?php esc_html_e( 'Top', 'foxfire-studio' ); ?></button>
			<button class="tp-btn primary tp-play" type="button" id="tpPlay"><?php esc_html_e( 'Begin', 'foxfire-studio' ); ?></button>
			<button class="tp-btn danger" type="button" id="tpFinish" hidden><?php esc_html_e( 'Finish recording', 'foxfire-studio' ); ?></button>
			<span class="tp-wave" id="tpWaveR" aria-hidden="true"></span>
		</div>
		<div class="tp-dock-r">
			<span class="tp-rec" id="tpRec"><span class="tp-rec-dot"></span><span id="tpRecTime">0:00</span><span class="tp-meter"><i id="tpMeter"></i></span></span>
			<span class="tp-energy" id="tpEnergy" role="status" hidden></span>
			<?php if ( ! empty( $rrt_blocks ) && ! empty( $rrt_boot['skin']['last'] ) ) : ?>
				<button class="tp-btn tp-last-btn" type="button" id="tpLastBtn" aria-controls="tpLast" aria-expanded="false" hidden><?php esc_html_e( 'Last take', 'foxfire-studio' ); ?></button>
			<?php endif; ?>
			<?php if ( ! empty( $rrt_blocks ) ) : ?>
				<span class="tp-pv-dock">
					<button class="tp-btn" type="button" id="tpPvPanel"><?php esc_html_e( 'Controls', 'foxfire-studio' ); ?></button>
					<button class="tp-btn" type="button" id="tpPvLeave"><?php esc_html_e( 'Leave prompter view', 'foxfire-studio' ); ?></button>
				</span>
			<?php endif; ?>
			<?php if ( '' !== trim( (string) ( $rrt_cfg['text']['tagline'] ?? '' ) ) ) : ?>
				<span class="tp-tagline"><?php echo esc_html( $rrt_cfg['text']['tagline'] ); ?></span>
			<?php endif; ?>
		</div>
		<span class="tp-msg" id="tpMsg"></span>
	</div>
</footer>

<div class="tp-result" id="tpResult" role="dialog" aria-label="<?php esc_attr_e( 'Your recording', 'foxfire-studio' ); ?>">
	<h2><?php esc_html_e( 'Your reading is ready', 'foxfire-studio' ); ?></h2>
	<audio id="tpAudio" controls></audio>
	<p id="tpResultInfo"></p>
	<div class="tp-music" id="tpMusic" hidden>
		<label class="tp-switch" title="<?php esc_attr_e( 'Mix the music bed under this take when it is downloaded or put on the post', 'foxfire-studio' ); ?>"><input type="checkbox" id="tpMusicOn" checked><span><?php esc_html_e( 'Music bed:', 'foxfire-studio' ); ?> <b class="tp-music-name"></b></span></label>
		<button class="tp-btn" type="button" id="tpMusicListen" title="<?php esc_attr_e( 'Hear the take with the music bed before downloading it', 'foxfire-studio' ); ?>"><?php esc_html_e( 'Listen with music', 'foxfire-studio' ); ?></button>
		<span class="tp-music-status" id="tpMusicStatus" aria-live="polite"></span>
		<audio id="tpMusicAudio" preload="none" hidden></audio>
	</div>
	<p class="tp-firstlook" id="tpFirstLook" hidden></p>
	<div class="tp-readout" id="tpReadout" aria-live="polite"></div>
	<p class="tp-wstatus" id="tpWhisperStatus" aria-live="polite"></p>
	<p class="tp-wstatus tp-shelf-site" id="tpShelfSite" aria-live="polite" hidden></p>
	<div class="tp-row">
		<button class="tp-btn primary" type="button" id="tpOpenReview" hidden><?php esc_html_e( 'Open full review', 'foxfire-studio' ); ?></button>
		<a class="tp-btn primary" id="tpDownload" href="#" download hidden><?php esc_html_e( 'Download MP3', 'foxfire-studio' ); ?></a>
		<a class="tp-btn" id="tpDownloadRaw" href="#" download><?php esc_html_e( 'Original', 'foxfire-studio' ); ?></a>
		<button class="tp-btn primary" type="button" id="tpMakeHere" hidden title="<?php esc_attr_e( 'Clean, level and make the MP3 on this phone. The take stays on the site for your computer either way.', 'foxfire-studio' ); ?>"><?php esc_html_e( 'Make the MP3 here', 'foxfire-studio' ); ?></button>
		<button class="tp-btn" type="button" id="tpUseRec" hidden title="<?php esc_attr_e( 'Put this MP3 (and its captions, once the review is ready) on the post as its reading', 'foxfire-studio' ); ?>"><?php esc_html_e( 'Use this recording', 'foxfire-studio' ); ?></button>
		<button class="tp-btn" type="button" id="tpDismiss"><?php esc_html_e( 'Close', 'foxfire-studio' ); ?></button>
	</div>
</div>

<div class="tp-result" id="tpRecover" role="dialog" aria-label="<?php esc_attr_e( 'Unfinished recording', 'foxfire-studio' ); ?>">
	<h2><?php esc_html_e( 'An unfinished recording', 'foxfire-studio' ); ?></h2>
	<p id="tpRecoverInfo"></p>
	<div class="tp-row">
		<button class="tp-btn primary" type="button" id="tpRecoverGo"><?php esc_html_e( 'Recover it', 'foxfire-studio' ); ?></button>
		<button class="tp-btn" type="button" id="tpRecoverDrop"><?php esc_html_e( 'Discard', 'foxfire-studio' ); ?></button>
		<button class="tp-btn" type="button" id="tpRecoverLater"><?php esc_html_e( 'Not now', 'foxfire-studio' ); ?></button>
	</div>
</div>

<div class="tp-result" id="tpCarry" role="dialog" aria-label="<?php esc_attr_e( 'A take that was cut off', 'foxfire-studio' ); ?>">
	<h2><?php esc_html_e( 'A take that was cut off', 'foxfire-studio' ); ?></h2>
	<p id="tpCarryInfo"></p>
	<div class="tp-row">
		<button class="tp-btn primary" type="button" id="tpCarryGo"><?php esc_html_e( 'Carry on', 'foxfire-studio' ); ?></button>
		<button class="tp-btn" type="button" id="tpCarryLater"><?php esc_html_e( 'Not now', 'foxfire-studio' ); ?></button>
	</div>
</div>

<div class="tp-result" id="tpSent" role="dialog" aria-label="<?php esc_attr_e( 'A take from your phone', 'foxfire-studio' ); ?>">
	<h2><?php esc_html_e( 'A take from your phone', 'foxfire-studio' ); ?></h2>
	<p id="tpSentInfo"></p>
	<div class="tp-row">
		<button class="tp-btn primary" type="button" id="tpSentGo"><?php esc_html_e( 'Open it', 'foxfire-studio' ); ?></button>
		<button class="tp-btn" type="button" id="tpSentDrop"><?php esc_html_e( 'Delete', 'foxfire-studio' ); ?></button>
		<button class="tp-btn" type="button" id="tpSentLater"><?php esc_html_e( 'Not now', 'foxfire-studio' ); ?></button>
	</div>
</div>

<div class="tp-review" id="tpReview" hidden>
	<div class="tp-rv-head">
		<div class="tp-rv-top">
			<h2 class="tp-brand tp-rv-title" id="tpRvTitle" tabindex="-1"><?php esc_html_e( 'Review', 'foxfire-studio' ); ?></h2>
			<span class="tp-rv-info" id="tpRvHeadInfo"></span>
			<a class="tp-btn" id="tpRvVtt" href="#" download><?php esc_html_e( 'Captions (.vtt)', 'foxfire-studio' ); ?></a>
			<a class="tp-btn" id="tpRvChapters" href="#" download hidden><?php esc_html_e( 'Chapters (.json)', 'foxfire-studio' ); ?></a>
			<button class="tp-btn" type="button" id="tpRvClip" hidden title="<?php esc_attr_e( 'A short captioned video of a moment of this take, for social media', 'foxfire-studio' ); ?>"><?php esc_html_e( 'Make a clip', 'foxfire-studio' ); ?></button>
			<button class="tp-btn" type="button" id="tpRvShare" hidden title="<?php esc_attr_e( 'Let someone with an account on this site hear this take and leave comments at any moment, for 14 days', 'foxfire-studio' ); ?>"><?php esc_html_e( 'Share for feedback', 'foxfire-studio' ); ?></button>
			<button class="tp-btn" type="button" id="tpRvPolish" hidden aria-controls="tpPolishBox" aria-expanded="false" title="<?php esc_attr_e( 'Hear one sentence before and after the polish, and try other strengths, the EQ and click lowering on this take only', 'foxfire-studio' ); ?>"><?php esc_html_e( 'Hear the polish', 'foxfire-studio' ); ?></button>
			<button class="tp-btn" type="button" id="tpRvCombine" hidden title="<?php esc_attr_e( 'Build one reading from this reading’s takes: choose which take reads each paragraph or sentence', 'foxfire-studio' ); ?>"><?php esc_html_e( 'Combine takes', 'foxfire-studio' ); ?></button>
			<label class="tp-switch tp-rv-music" id="tpRvMusic" hidden title="<?php esc_attr_e( 'Mix the music bed under this take when it is downloaded or put on the post', 'foxfire-studio' ); ?>"><input type="checkbox" id="tpRvMusicOn" checked><span><?php esc_html_e( 'Music bed', 'foxfire-studio' ); ?></span></label>
			<select class="tp-rv-finish" id="tpRvFinish" hidden aria-label="<?php esc_attr_e( 'Finish for', 'foxfire-studio' ); ?>" title="<?php esc_attr_e( 'Download a one-off version for another destination. Use this recording always uses the choice in Settings.', 'foxfire-studio' ); ?>"></select>
			<button class="tp-btn" type="button" id="tpRvDownload" hidden title="<?php esc_attr_e( 'Download the latest MP3 of this take: the edited one once it is made, with the music bed when it is switched on', 'foxfire-studio' ); ?>"><?php esc_html_e( 'Download MP3', 'foxfire-studio' ); ?></button>
			<button class="tp-btn primary" type="button" id="tpRvUseRec" hidden title="<?php esc_attr_e( 'Put the MP3 behind the download button, with these captions, on the post as its reading', 'foxfire-studio' ); ?>"><?php esc_html_e( 'Use this recording', 'foxfire-studio' ); ?></button>
			<button class="tp-btn primary" type="button" id="tpRvClose"><?php esc_html_e( 'Back to teleprompter', 'foxfire-studio' ); ?></button>
		</div>
		<audio id="tpRvAudio" controls preload="auto"></audio>
		<div class="tp-rv-edit" id="tpRvEdit">
			<button class="tp-btn" type="button" id="tpEdToggle" aria-pressed="false" title="<?php esc_attr_e( 'Cut words, fillers and long pauses by editing the script', 'foxfire-studio' ); ?>"><?php esc_html_e( 'Edit audio', 'foxfire-studio' ); ?></button>
			<span class="tp-ed-tools" id="tpEdTools" hidden>
				<button class="tp-btn" type="button" id="tpEdSent" disabled title="<?php esc_attr_e( 'Widen the selection to whole sentences (or double-click a word)', 'foxfire-studio' ); ?>"><?php esc_html_e( 'Whole sentence', 'foxfire-studio' ); ?></button>
				<button class="tp-btn" type="button" id="tpEdPara" disabled title="<?php esc_attr_e( 'Widen the selection to the whole paragraph (or triple-click a word)', 'foxfire-studio' ); ?>"><?php esc_html_e( 'Whole paragraph', 'foxfire-studio' ); ?></button>
				<button class="tp-btn" type="button" id="tpEdCut" disabled title="<?php esc_attr_e( 'Cut the selected words (Delete)', 'foxfire-studio' ); ?>"><?php esc_html_e( 'Cut', 'foxfire-studio' ); ?></button>
				<button class="tp-btn" type="button" id="tpEdPreview" disabled title="<?php esc_attr_e( 'Hear a few seconds around the selection or the last edit, as it will sound', 'foxfire-studio' ); ?>"><?php esc_html_e( 'Preview', 'foxfire-studio' ); ?></button>
				<button class="tp-btn" type="button" id="tpEdRetake" disabled title="<?php esc_attr_e( 'Record the selected words again and splice them in (double-click a word to select its sentence)', 'foxfire-studio' ); ?>"><?php esc_html_e( 'Retake', 'foxfire-studio' ); ?></button>
				<button class="tp-btn" type="button" id="tpEdTidy" title="<?php esc_attr_e( 'Suggest cuts for fillers, repeats and long pauses', 'foxfire-studio' ); ?>"><?php esc_html_e( 'Tidy', 'foxfire-studio' ); ?></button>
				<button class="tp-btn" type="button" id="tpEdUndo" disabled title="<?php esc_attr_e( 'Undo the last change (Ctrl+Z)', 'foxfire-studio' ); ?>"><?php esc_html_e( 'Undo', 'foxfire-studio' ); ?></button>
				<button class="tp-btn" type="button" id="tpEdCheck" disabled title="<?php esc_attr_e( 'Whisper listens to the edited recording, and the flagged moments are worked out again for it', 'foxfire-studio' ); ?>"><?php esc_html_e( 'Check the edited version', 'foxfire-studio' ); ?></button>
				<button class="tp-btn primary" type="button" id="tpEdMake" disabled><?php esc_html_e( 'Make edited MP3', 'foxfire-studio' ); ?></button>
			</span>
			<span class="tp-ed-status" id="tpEdStatus" aria-live="polite"></span>
		</div>
		<div class="tp-ed-tidy tp-ed-retake" id="tpEdRetakeBox" hidden>
			<p class="tp-ed-retake-line" id="tpEdRetakeLine"></p>
			<p class="tp-ed-tidy-head" id="tpEdRetakeNote"></p>
			<div class="tp-ed-edges" id="tpEdEdges" hidden>
				<span class="tp-ed-edge" role="group" aria-label="<?php esc_attr_e( 'Start of the retake', 'foxfire-studio' ); ?>">
					<span class="tp-ed-edge-name"><?php esc_html_e( 'Start', 'foxfire-studio' ); ?></span>
					<button class="tp-btn tp-ed-nudge" type="button" data-side="s" data-dir="-1" title="<?php esc_attr_e( 'Start 10 ms earlier: cuts more of the old reading before the retake ([ key)', 'foxfire-studio' ); ?>" aria-label="<?php esc_attr_e( 'Start 10 ms earlier', 'foxfire-studio' ); ?>">&#9664;</button>
					<span class="tp-ed-edge-val" id="tpEdEdgeS"><?php esc_html_e( 'as found', 'foxfire-studio' ); ?></span>
					<button class="tp-btn tp-ed-nudge" type="button" data-side="s" data-dir="1" title="<?php esc_attr_e( 'Start 10 ms later: cuts less of the old reading before the retake (] key)', 'foxfire-studio' ); ?>" aria-label="<?php esc_attr_e( 'Start 10 ms later', 'foxfire-studio' ); ?>">&#9654;</button>
				</span>
				<span class="tp-ed-edge" role="group" aria-label="<?php esc_attr_e( 'End of the retake', 'foxfire-studio' ); ?>">
					<span class="tp-ed-edge-name"><?php esc_html_e( 'End', 'foxfire-studio' ); ?></span>
					<button class="tp-btn tp-ed-nudge" type="button" data-side="e" data-dir="-1" title="<?php esc_attr_e( 'End 10 ms earlier: cuts less of the old reading after the retake (Shift+[)', 'foxfire-studio' ); ?>" aria-label="<?php esc_attr_e( 'End 10 ms earlier', 'foxfire-studio' ); ?>">&#9664;</button>
					<span class="tp-ed-edge-val" id="tpEdEdgeE"><?php esc_html_e( 'as found', 'foxfire-studio' ); ?></span>
					<button class="tp-btn tp-ed-nudge" type="button" data-side="e" data-dir="1" title="<?php esc_attr_e( 'End 10 ms later: cuts more of the old reading after the retake (Shift+])', 'foxfire-studio' ); ?>" aria-label="<?php esc_attr_e( 'End 10 ms later', 'foxfire-studio' ); ?>">&#9654;</button>
				</span>
			</div>
			<div class="tp-ed-edges" id="tpEdTrim" hidden>
				<span class="tp-ed-edge" role="group" aria-label="<?php esc_attr_e( 'Start of the new recording', 'foxfire-studio' ); ?>">
					<span class="tp-ed-edge-name"><?php esc_html_e( 'Retake start', 'foxfire-studio' ); ?></span>
					<button class="tp-btn tp-ed-trim" type="button" data-side="s" data-dir="-1" title="<?php esc_attr_e( 'Retake start 10 ms earlier: keeps more of the new recording', 'foxfire-studio' ); ?>" aria-label="<?php esc_attr_e( 'Retake start 10 ms earlier', 'foxfire-studio' ); ?>">&#9664;</button>
					<span class="tp-ed-edge-val" id="tpEdTrimS"><?php esc_html_e( 'as fitted', 'foxfire-studio' ); ?></span>
					<button class="tp-btn tp-ed-trim" type="button" data-side="s" data-dir="1" title="<?php esc_attr_e( 'Retake start 10 ms later: trims a false start or breath off the new recording', 'foxfire-studio' ); ?>" aria-label="<?php esc_attr_e( 'Retake start 10 ms later', 'foxfire-studio' ); ?>">&#9654;</button>
				</span>
				<span class="tp-ed-edge" role="group" aria-label="<?php esc_attr_e( 'End of the new recording', 'foxfire-studio' ); ?>">
					<span class="tp-ed-edge-name"><?php esc_html_e( 'Retake end', 'foxfire-studio' ); ?></span>
					<button class="tp-btn tp-ed-trim" type="button" data-side="e" data-dir="-1" title="<?php esc_attr_e( 'Retake end 10 ms earlier: trims the end of the new recording', 'foxfire-studio' ); ?>" aria-label="<?php esc_attr_e( 'Retake end 10 ms earlier', 'foxfire-studio' ); ?>">&#9664;</button>
					<span class="tp-ed-edge-val" id="tpEdTrimE"><?php esc_html_e( 'as fitted', 'foxfire-studio' ); ?></span>
					<button class="tp-btn tp-ed-trim" type="button" data-side="e" data-dir="1" title="<?php esc_attr_e( 'Retake end 10 ms later: keeps more of the new recording', 'foxfire-studio' ); ?>" aria-label="<?php esc_attr_e( 'Retake end 10 ms later', 'foxfire-studio' ); ?>">&#9654;</button>
				</span>
			</div>
			<div class="tp-ed-edges" id="tpEdSpace" hidden>
				<span class="tp-ed-edge" role="group" aria-label="<?php esc_attr_e( 'Space before the retake', 'foxfire-studio' ); ?>">
					<span class="tp-ed-edge-name"><?php esc_html_e( 'Space before', 'foxfire-studio' ); ?></span>
					<button class="tp-btn tp-ed-space" type="button" data-side="s" data-dir="-1" title="<?php esc_attr_e( 'Less space before the retake', 'foxfire-studio' ); ?>" aria-label="<?php esc_attr_e( 'Less space before', 'foxfire-studio' ); ?>">&#9664;</button>
					<span class="tp-ed-edge-val" id="tpEdSpaceS"><?php echo esc_html_x( 'none', 'no added space', 'foxfire-studio' ); ?></span>
					<button class="tp-btn tp-ed-space" type="button" data-side="s" data-dir="1" title="<?php esc_attr_e( '50 ms more space before the retake, filled with your room’s own background', 'foxfire-studio' ); ?>" aria-label="<?php esc_attr_e( 'More space before', 'foxfire-studio' ); ?>">&#9654;</button>
				</span>
				<span class="tp-ed-edge" role="group" aria-label="<?php esc_attr_e( 'Space after the retake', 'foxfire-studio' ); ?>">
					<span class="tp-ed-edge-name"><?php esc_html_e( 'Space after', 'foxfire-studio' ); ?></span>
					<button class="tp-btn tp-ed-space" type="button" data-side="e" data-dir="-1" title="<?php esc_attr_e( 'Less space after the retake', 'foxfire-studio' ); ?>" aria-label="<?php esc_attr_e( 'Less space after', 'foxfire-studio' ); ?>">&#9664;</button>
					<span class="tp-ed-edge-val" id="tpEdSpaceE"><?php echo esc_html_x( 'none', 'no added space', 'foxfire-studio' ); ?></span>
					<button class="tp-btn tp-ed-space" type="button" data-side="e" data-dir="1" title="<?php esc_attr_e( '50 ms more space after the retake, filled with your room’s own background', 'foxfire-studio' ); ?>" aria-label="<?php esc_attr_e( 'More space after', 'foxfire-studio' ); ?>">&#9654;</button>
				</span>
				<button class="tp-btn" type="button" id="tpEdEdgeReset" disabled title="<?php esc_attr_e( 'Put both edges and both ends of the retake back where they were found, and take out any added space', 'foxfire-studio' ); ?>"><?php esc_html_e( 'Reset', 'foxfire-studio' ); ?></button>
			</div>
			<div class="tp-ed-tidy-actions">
				<button class="tp-btn primary" type="button" id="tpEdRecBtn"><?php esc_html_e( 'Record', 'foxfire-studio' ); ?></button>
				<button class="tp-btn" type="button" id="tpEdRecHear" hidden><?php esc_html_e( 'Hear it in place', 'foxfire-studio' ); ?></button>
				<button class="tp-btn primary" type="button" id="tpEdRecUse" hidden><?php esc_html_e( 'Use this take', 'foxfire-studio' ); ?></button>
				<button class="tp-btn" type="button" id="tpEdRecBack" hidden title="<?php esc_attr_e( 'Take this retake out and keep the words as first read', 'foxfire-studio' ); ?>"><?php esc_html_e( 'Bring back the original', 'foxfire-studio' ); ?></button>
				<button class="tp-btn" type="button" id="tpEdRecCancel"><?php esc_html_e( 'Cancel', 'foxfire-studio' ); ?></button>
			</div>
		</div>
		<div class="tp-ed-tidy tp-polish" id="tpPolishBox" hidden>
			<p class="tp-ed-tidy-head"><?php esc_html_e( 'Hear the polish: one sentence as recorded and as polished, at the same loudness, looping. Switch Before and After while it plays. Choices here are for this take only: Use for this take makes its MP3 again with them, for Download, Use this recording, clips and Share for feedback. Settings stays as it is.', 'foxfire-studio' ); ?></p>
			<div class="tp-polish-row">
				<label class="tp-polish-sent"><span><?php esc_html_e( 'Sentence', 'foxfire-studio' ); ?></span> <select id="tpPolishSent"></select></label>
				<button class="tp-btn primary" type="button" id="tpPolishPlay"><?php esc_html_e( 'Play', 'foxfire-studio' ); ?></button>
				<span class="tp-polish-ab" role="group" aria-label="<?php esc_attr_e( 'Before or after the polish', 'foxfire-studio' ); ?>">
					<button class="tp-btn" type="button" id="tpPolishBefore" aria-pressed="false"><?php esc_html_e( 'Before', 'foxfire-studio' ); ?></button>
					<button class="tp-btn" type="button" id="tpPolishAfter" aria-pressed="true"><?php esc_html_e( 'After', 'foxfire-studio' ); ?></button>
				</span>
			</div>
			<div class="tp-polish-row tp-polish-steps" id="tpPolishSteps"></div>
			<p class="tp-polish-eq" id="tpPolishEq"></p>
			<div class="tp-ed-tidy-actions">
				<button class="tp-btn primary" type="button" id="tpPolishUse"><?php esc_html_e( 'Use for this take', 'foxfire-studio' ); ?></button>
				<button class="tp-btn" type="button" id="tpPolishReset"><?php esc_html_e( 'Back to the Settings choice', 'foxfire-studio' ); ?></button>
				<button class="tp-btn" type="button" id="tpPolishClose"><?php esc_html_e( 'Close', 'foxfire-studio' ); ?></button>
			</div>
			<p class="tp-polish-status" id="tpPolishStatus" aria-live="polite"></p>
		</div>
		<div class="tp-ed-tidy" id="tpEdTidyBox" hidden>
			<p class="tp-ed-tidy-head" id="tpEdTidyHead"></p>
			<ul class="tp-ed-tidy-list" id="tpEdTidyList"></ul>
			<div class="tp-ed-tidy-actions">
				<button class="tp-btn primary" type="button" id="tpEdTidyApply"><?php esc_html_e( 'Apply the ticked cuts', 'foxfire-studio' ); ?></button>
				<button class="tp-btn" type="button" id="tpEdTidyCancel"><?php esc_html_e( 'Cancel', 'foxfire-studio' ); ?></button>
			</div>
		</div>
		<div class="tp-rv-timeline" id="tpRvTimeline" aria-hidden="true" title="<?php esc_attr_e( 'Click to play from here', 'foxfire-studio' ); ?>"></div>
		<div class="tp-rv-legend">
			<span><i style="background: color-mix(in srgb, var(--muted) 60%, transparent)"></i><?php esc_html_e( 'slower', 'foxfire-studio' ); ?></span>
			<span><i style="background: color-mix(in srgb, var(--muted) 35%, var(--panel))"></i><?php esc_html_e( 'this take\'s own pace', 'foxfire-studio' ); ?></span>
			<span><i style="background: var(--warn)"></i><?php esc_html_e( 'faster', 'foxfire-studio' ); ?></span>
			<span><i style="background: color-mix(in srgb, var(--accent) 40%, transparent)"></i><?php esc_html_e( 'long pause', 'foxfire-studio' ); ?></span>
		</div>
	</div>
	<div class="tp-combine" id="tpCombine" hidden>
		<div class="tp-cb-top">
			<h2><?php esc_html_e( 'Combine takes', 'foxfire-studio' ); ?></h2>
			<p class="tp-cb-lede"><?php esc_html_e( 'Choose which take reads each paragraph, or split a paragraph to choose sentence by sentence. Each button shows what that take’s review flagged there; hover it for the details, and press ▶ to hear that take. Where two takes meet, Hear the join plays it as it will sound, and End, Start and Space move the join 10 ms or 50 ms at a time. Build the reading puts it all together, cleans and levels it once, and opens it here like any take. Your takes are kept in this browser for 30 days, the newest six for each post.', 'foxfire-studio' ); ?></p>
			<ul class="tp-cb-takes" id="tpCbTakes"></ul>
			<div class="tp-cb-cover" id="tpCbCover" role="group" aria-label="<?php esc_attr_e( 'Which take reads each paragraph', 'foxfire-studio' ); ?>"></div>
			<div class="tp-cb-actions">
				<button class="tp-btn primary" type="button" id="tpCbBuild"><?php esc_html_e( 'Build the reading', 'foxfire-studio' ); ?></button>
				<button class="tp-btn" type="button" id="tpCbBest" title="<?php esc_attr_e( 'For each paragraph, the take with the fewest flags, switching takes only for a clear gain', 'foxfire-studio' ); ?>"><?php esc_html_e( 'Best of each', 'foxfire-studio' ); ?></button>
				<button class="tp-btn" type="button" id="tpCbClose"><?php esc_html_e( 'Back to the review', 'foxfire-studio' ); ?></button>
				<button class="tp-btn tp-cb-clear" type="button" id="tpCbClear"><?php esc_html_e( 'Clear the shelf', 'foxfire-studio' ); ?></button>
			</div>
			<p class="tp-cb-status" id="tpCbStatus" aria-live="polite"></p>
		</div>
		<div class="tp-cb-body" id="tpCbBody"></div>
	</div>
	<div class="tp-rv-body">
		<div class="tp-rv-side">
			<h3><?php esc_html_e( 'At a glance', 'foxfire-studio' ); ?></h3>
			<div class="tp-rv-stats" id="tpRvStats"></div>
			<h3><?php esc_html_e( 'How it sounded', 'foxfire-studio' ); ?></h3>
			<div class="tp-rv-notes" id="tpRvNotes"></div>
			<h3><?php esc_html_e( 'Pause map', 'foxfire-studio' ); ?></h3>
			<div class="tp-rv-pm" id="tpRvPauses"></div>
			<h3><?php esc_html_e( 'Flagged moments', 'foxfire-studio' ); ?></h3>
			<p class="tp-rv-muted tp-rv-filter" id="tpRvFilter"></p>
			<ul class="tp-rv-problems" id="tpRvProblems"></ul>
			<h3><?php esc_html_e( 'Words Whisper has learned', 'foxfire-studio' ); ?></h3>
			<div id="tpRvLearned"></div>
			<h3><?php esc_html_e( 'Your takes', 'foxfire-studio' ); ?></h3>
			<div id="tpRvTakes"></div>
			<h3><?php esc_html_e( 'Reading the marks', 'foxfire-studio' ); ?></h3>
			<p class="tp-rv-muted"><?php esc_html_e( 'Struck through: skipped. Wavy underline: misread (hover, or move to it with the arrow keys, to hear what was heard; Flagged moments lists it too). Dashed underline: worth a listen, where Whisper heard a slightly different form of the word, such as “coming” for “come.” These are often Whisper’s mistake, so they don’t count against your accuracy. Small rounded tags: words that were added, repeated, or filler. Shaded sentences were rushed, slow, or wandered from the script, and each starts with a small tag saying which (slow ones also have a dotted edge). A dotted underline means a flat sentence, and an arrow marks a faded ending. A gold tilde is a fade you marked, which is never flagged. With Show pauses on, each pause of a second or more shows its length: plain between sentences, underlined at a paragraph break, and in a box inside a sentence. The percentage beside each paragraph is how much of it was read as written. Click any word to hear it. From the keyboard, Tab to the script: left and right move word by word and Enter plays from the word. Space plays and pauses, Escape returns to the teleprompter. The timeline above repeats Flagged moments, whose buttons are the keyboard way in.', 'foxfire-studio' ); ?></p>
			<p class="tp-rv-muted"><?php esc_html_e( 'With Edit audio on, clicking selects instead of playing: drag across words or shift-click to select more, double-click for a whole sentence, triple-click for the whole paragraph, and Shift with the arrow keys starts a selection at the word cursor or makes it a word longer or shorter. Cut (or Delete) takes the selection out of the audio, and Retake records it again: the old words are taken out from where your voice really starts to where it stops, and anything you say before or after the selected words in the retake is trimmed away. A gold double strike marks a cut, a gold bar under words marks a retake, and a shortened pause shows its old and new length. Click a cut word to bring it back. Click a retaken word to open its retake again: Start and End move each side of the splice 10 ms at a time, cutting more or less of the old reading (the [ and ] keys do the same, with Shift for the end). If a word is heard twice at a join, such as “picture-icture”, cut more of the old reading on that side. Space before and Space after add a pause at a join, 50 ms at a time, filled with your room’s own background. Each step plays the join so you hear it at once, Reset puts everything back, and Bring back the original takes the retake out. Nothing changes your recording: Make edited MP3 builds a new file from it, with the captions and chapters moved to match.', 'foxfire-studio' ); ?></p>
			<p class="tp-rv-muted"><?php esc_html_e( 'Whisper sometimes mishears unusual words such as names and specialist terms, or tidies away ums and stumbles. When it gets something wrong, use “I said it right” and it will remember. Treat the counts as a guide and your ears as the judge.', 'foxfire-studio' ); ?></p>
		</div>
		<div class="tp-rv-script" id="tpRvScript"></div>
	</div>
</div>

<script>window.RRT = <?php echo wp_json_encode( $rrt_boot ); ?>;</script>
<?php
/* The studio's scripts, with wp.i18n and their translations in front (0.25.0). */
rrt_register_script( 'rrt-sound', 'sound.js' );
rrt_register_script( 'rrt-id3', 'id3.js' );
rrt_register_script( 'rrt-edit', 'edit.js' );
rrt_register_script( 'rrt-combine', 'combine.js', array( 'rrt-edit' ) );
rrt_register_script( 'rrt-practice', 'practice.js' );
rrt_register_script( 'rrt-clean', 'clean.js' );
rrt_register_script( 'rrt-readout', 'readout.js' );
rrt_register_script( 'rrt-compare', 'compare.js' );
rrt_register_script( 'rrt-whisper', 'whisper.js' );
rrt_register_script( 'rrt-live', 'live.js' );
rrt_register_script( 'rrt-polish', 'polish.js' );
rrt_register_script( 'rrt-follow', 'follow.js' );
/* What this browser can do (1.30.0), shared with the clip maker. */
rrt_register_script( 'rrt-can', 'can.js' );
/* The studio's parts (0.25.2), each needing the one before it, then the starter. */
$rrt_after = array( 'rrt-sound', 'rrt-id3', 'rrt-edit', 'rrt-combine', 'rrt-practice', 'rrt-clean', 'rrt-readout', 'rrt-compare', 'rrt-whisper', 'rrt-live', 'rrt-polish', 'rrt-follow', 'rrt-can' );
foreach ( array(
	'rrt-studio-core' => 'studio-core.js',
	'rrt-record' => 'record.js',
	'rrt-phonetake' => 'phonetake.js',
	'rrt-cues' => 'cues.js',
	'rrt-voice' => 'voice.js',
	'rrt-master' => 'master.js',
	'rrt-review' => 'review.js',
	'rrt-hearpolish' => 'hearpolish.js',
	'rrt-editor' => 'editor.js',
	'rrt-takes' => 'takes.js',
	'rrt-prompter' => 'prompter.js',
) as $rrt_handle => $rrt_file ) {
	rrt_register_script( $rrt_handle, $rrt_file, $rrt_after );
	$rrt_after = array( $rrt_handle );
}
rrt_register_script( 'rrt-teleprompter', 'teleprompter.js', $rrt_after );
rrt_register_script( 'rrt-skin', 'skin.js', array( 'rrt-teleprompter' ) );
$rrt_later = rrt_script_translations_inline(
	array(
		'rrt-clip'    => 'clip.js',
		'rrt-mp3'     => 'mp3.js',
		'rrt-denoise' => 'denoise.js',
		'rrt-deepfilter' => 'deepfilter.js',
	)
);
if ( '' !== $rrt_later ) {
	wp_add_inline_script( 'rrt-teleprompter', $rrt_later, 'before' );
}
wp_print_scripts( array( 'rrt-skin' ) );
?>
</body>
</html>
