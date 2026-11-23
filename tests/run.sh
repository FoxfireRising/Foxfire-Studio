#!/usr/bin/env bash
# Runs every automatic check: syntax, unit tests, PHP checks inside a test
# WordPress, the browser tests, uninstalling and upgrading from older releases. The same script runs on GitHub for
# every push and pull request.
#
#   tests/run.sh            everything
#   tests/run.sh quick      syntax and unit tests only (no WordPress, no browser)
#   tests/run.sh firefox    clips and the full-length video in Firefox (1.30.0), local only: needs
#                           FIREFOX and GECKODRIVER (see CONTRIBUTING.md, Testing in other browsers) and Selenium
#   tests/run.sh oldest     the oldest WordPress the plugin supports (6.0): PHP syntax,
#                           the PHP checks, the rollups, podcast downloads and their hand-off on nginx and Apache, the scheduled jobs, keeping a
#                           reading aside, the Podcasting 2.0 feed, phone take mode, voice polish, voice following, the pronunciation list, the faster review, full-length video, collections, audiobook export, member feeds and storage offload on the server, the smoke test, the player block, the setup wizard and help; run it on PHP 7.4
set -euo pipefail

MODE="${1:-}"
if [ "$MODE" = "oldest" ]; then
	# WordPress 6.0 needs the SQLite plugin from before it dropped 6.0, and prints its own
	# deprecation notices on newer PHP, so they go to the log only.
	export WP_REF="${WP_REF:-6.0}" SQLITE_REF="${SQLITE_REF:-v2.1.3}" WP_DEBUG_DISPLAY=false
	WP_DIR="${WP_DIR:-/tmp/foxfire-wp-oldest}"
	php -r 'echo "PHP ", PHP_VERSION, "\n";'
fi

REPO="$(cd "$(dirname "$0")/.." && pwd)"
PLUGIN="$REPO/plugin/foxfire-studio"
WP_DIR="${WP_DIR:-/tmp/foxfire-wp}"
PORT="${WP_PORT:-8080}"
cd "$REPO"

echo "== PHP syntax"
find plugin -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null

echo "== Translated placeholders"
php tests/php/placeholders.php

echo "== Script addresses carry the file's time (1.36.1)"
# Every script the page loads later by itself (a worker) goes through rrt_js_url(),
# so a phone fetches a changed file even when the version number has not moved.
if grep -rnE "assets/js/[A-Za-z0-9_.-]+\.js(\?ver=' \. RRT_VERSION| ?'\))|'ver', RRT_VERSION, RRT_URL \. 'assets/js/" "$PLUGIN/includes"; then
	echo "A script address above carries the version only: use rrt_js_url()." >&2
	exit 1
fi

if [ "$MODE" != "oldest" ]; then
	echo "== JavaScript syntax"
	for f in "$PLUGIN"/assets/js/*.js; do node --check "$f"; done

	echo "== Unit tests"
	node --test tests/unit/*.test.js
fi

[ "$MODE" = "quick" ] && exit 0

echo "== Test WordPress"
WP_PORT="$PORT" tests/wp/setup.sh "$WP_DIR"
WP="php $WP_DIR/wp-cli.phar --path=$WP_DIR/wordpress --allow-root"

$WP option delete foxfire_test_locale --quiet 2>/dev/null || true

if [ "$MODE" = "firefox" ]; then
	echo "== Clips and the full-length video in Firefox (1.30.0)"
	SERVER=""
	if ! curl -s -o /dev/null "http://localhost:$PORT/"; then
		PHP_CLI_SERVER_WORKERS=4 php -d upload_max_filesize=64M -d post_max_size=64M -S "localhost:$PORT" -t "$WP_DIR/wordpress" > "$WP_DIR/server.log" 2>&1 &
		SERVER=$!
		trap '[ -n "$SERVER" ] && kill $SERVER' EXIT
		sleep 1
	fi
	$WP eval-file tests/wp/video.php > "$WP_DIR/video.json"
	VD_POST="$(python3 -c "import json,sys; print(json.load(open(sys.argv[1]))['post'])" "$WP_DIR/video.json")"
	set +e
	VD_SEED="$WP_DIR/video.json" python3 tests/browser/firefox.py "http://localhost:$PORT"
	T=$?
	set -e
	$WP eval-file tests/wp/video.php off "$VD_POST"
	[ $T -eq 0 ] || exit $T
	echo "All Firefox checks passed."
	exit 0
fi

echo "== PHP checks"
$WP eval-file tests/php/checks.php

echo "== Listener rollups"
$WP eval-file tests/php/rollups.php

echo "== Podcast downloads"
$WP eval-file tests/php/downloads.php

echo "== Download counting's hand-off, on nginx and Apache"
# Each server that is installed here (with PHP-FPM) is put in front of the test site (1.24.0); others are skipped.
tests/wp/handoff.sh "$WP_DIR"

echo "== Scheduled jobs, as if time had passed"
$WP eval-file tests/php/time.php

echo "== Keep a reading aside, on the server"
$WP eval-file tests/php/keep.php

echo "== The full Podcasting 2.0 feed, on the server"
$WP eval-file tests/php/feed.php

echo "== Phone take mode, on the server"
$WP eval-file tests/php/live.php

echo "== Voice polish, on the server"
$WP eval-file tests/php/polish.php

echo "== Voice following, on the server"
$WP eval-file tests/php/follow.php

echo "== The pronunciation list, on the server"
$WP eval-file tests/php/pronounce.php

echo "== The stronger cleanup, on the server"
$WP eval-file tests/php/stronger.php

echo "== The faster review, on the server"
$WP eval-file tests/php/faster.php

echo "== Full-length video, on the server"
$WP eval-file tests/php/video.php

echo "== Collections, on the server"
$WP eval-file tests/php/collections.php

echo "== Audiobook export, on the server"
$WP eval-file tests/php/audiobook.php

echo "== Member feeds, on the server"
$WP eval-file tests/php/members.php

echo "== Sponsor messages, on the server"
$WP eval-file tests/php/sponsors.php

echo "== The security review's fixes (1.25.0), on the server"
$WP eval-file tests/php/security.php

echo "== Accessibility (1.26.0), on the server"
$WP eval-file tests/php/a11y.php

echo "== The player block (1.28.0), on the server"
$WP eval-file tests/php/block.php

echo "== The setup wizard (1.29.0), on the server"
$WP eval-file tests/php/setup.php

echo "== Help (1.32.0), on the server"
$WP eval-file tests/php/help.php

echo "== Reading languages (1.33.0), on the server"
$WP eval-file tests/php/languages.php

echo "== The take shelf across computers (1.34.0), on the server"
$WP eval-file tests/php/shelf.php

echo "== YouTube and SoundCloud players that load on play (1.35.0), on the server"
$WP eval-file tests/php/embeds.php

echo "== Browser smoke test"
SERVER=""
if ! curl -s -o /dev/null "http://localhost:$PORT/"; then
	# A few workers, so a page is not held up behind WordPress calling its own cron.
	PHP_CLI_SERVER_WORKERS=4 php -d upload_max_filesize=64M -d post_max_size=64M -S "localhost:$PORT" -t "$WP_DIR/wordpress" > "$WP_DIR/server.log" 2>&1 &
	SERVER=$!
	trap '[ -n "$SERVER" ] && kill $SERVER' EXIT
	sleep 1
fi
# A published post with a reading, for the podcast short address (1.2.0).
DL_POST="$($WP post create --post_title='Short address test' --post_status=publish --porcelain)"
$WP post meta update "$DL_POST" rrt_audio "http://localhost:$PORT/wp-content/uploads/short-test.mp3" --quiet
set +e
DL_POST="$DL_POST" python3 tests/browser/smoke.py "http://localhost:$PORT"
T=$?
set -e
$WP post delete "$DL_POST" --force --quiet
[ $T -eq 0 ] || exit $T

echo "== Storage offload, on the server"
# The stand-in storage (1.23.0): S3, Bunny Storage, a public side and a CDN in front of the test site.
OFF_PORT="${OFF_PORT:-8090}"
python3 tests/wp/storage.py "$OFF_PORT" "http://localhost:$PORT" > "$WP_DIR/storage.log" 2>&1 &
STORAGE=$!
trap '[ -n "$SERVER" ] && kill $SERVER; kill $STORAGE 2>/dev/null' EXIT
for i in 1 2 3 4 5 6 7 8 9 10; do curl -s -o /dev/null "http://127.0.0.1:$OFF_PORT/admin/state" && break; sleep 0.5; done
OFF_PORT="$OFF_PORT" $WP eval-file tests/php/offload.php

echo "== The player block (1.28.0), with the site's theme and with a classic theme"
BK_THEME="$($WP theme list --status=active --field=name 2>/dev/null | head -1)"
$WP eval-file tests/wp/block.php > "$WP_DIR/block.json"
set +e
BK_SEED="$WP_DIR/block.json" python3 tests/browser/block.py "http://localhost:$PORT" "$BK_THEME"
T=$?
if [ $T -eq 0 ] && [ "$BK_THEME" != "twentytwentyone" ] && [ -d "$WP_DIR/wordpress/wp-content/themes/twentytwentyone" ]; then
	$WP eval-file tests/wp/block.php off > /dev/null
	$WP theme activate twentytwentyone > /dev/null
	$WP eval-file tests/wp/block.php > "$WP_DIR/block.json"
	BK_SEED="$WP_DIR/block.json" python3 tests/browser/block.py "http://localhost:$PORT" twentytwentyone
	T=$?
	$WP theme activate "$BK_THEME" > /dev/null
fi
set -e
$WP eval-file tests/wp/block.php off > /dev/null
[ $T -eq 0 ] || exit $T

echo "== The setup wizard (1.29.0), in the browser"
$WP eval-file tests/wp/setup.php > "$WP_DIR/setup.json"
set +e
SU_SEED="$WP_DIR/setup.json" python3 tests/browser/setup.py "http://localhost:$PORT" "$WP_DIR"
T=$?
set -e
$WP eval-file tests/wp/setup.php off > /dev/null
[ $T -eq 0 ] || exit $T

LOG="$WP_DIR/wordpress/wp-content/debug.log"
scan_log() {
	if [ -f "$LOG" ] && grep -E "PHP (Fatal|Warning|Notice|Deprecated).*foxfire-studio" "$LOG"; then
		echo "The plugin wrote PHP errors to the debug log (above)."
		exit 1
	fi
}

if [ "$MODE" = "oldest" ]; then
	scan_log
	echo "All checks passed on WordPress $WP_REF."
	exit 0
fi

echo "== A whole take, end to end"
$WP eval-file tests/wp/music.php
set +e
python3 tests/browser/take.py "http://localhost:$PORT"
T=$?
set -e
$WP eval-file tests/wp/music.php off
[ $T -eq 0 ] || exit $T

echo "== Phone level"
# A short post for phone.py (1.9.0): takes at a phone's level, on a phone and on a computer.
PH_POST="$($WP post create --post_title='Phone test' --post_status=publish --post_content='<p>Leaves fall slowly over the quiet garden. Rain taps the glass by the window. Owls call softly from the old oak. The lamp stays lit until the morning comes. A fox walks along the wall and looks back once.</p>' --porcelain)"
set +e
PH_POST="$PH_POST" python3 tests/browser/phone.py "http://localhost:$PORT"
T=$?
if [ $T -eq 0 ]; then
	# The take sent from the iPhone was deleted from the site once it was downloaded on the computer.
	LEFT="$($WP eval 'echo count( rrt_sent_list( 1 ) );')"
	if [ "$LEFT" = "0" ]; then echo "ok   the sent take is gone from the site once downloaded"; else echo "FAIL the sent take is gone from the site once downloaded: $LEFT left"; T=1; fi
fi
set -e
$WP post delete "$PH_POST" --force --quiet
[ $T -eq 0 ] || exit $T

echo "== Phone take mode"
# A post of several sentences for livetake.py (1.12.0): a phone take sent in pieces, one cut off and carried on.
LT_POST="$($WP post create --post_title='Phone take test' --post_status=publish --post_content='<p>Leaves fall slowly over the quiet garden. Rain taps the glass by the window. Owls call softly from the old oak. The lamp stays lit until the morning comes.</p><p>A fox walks along the wall and looks back once. The river runs high after the storm. Smoke curls from the chimney into the grey sky. Bread cools on the table by the door.</p><p>The candles burn low in the evening. A cat sleeps on the warm stone step. Stars come out one by one over the hill. The wind settles and the house grows still.</p><p>Morning comes with frost on every branch. The kettle sings and the window fogs. A robin waits on the fence for crumbs. The day begins again, slow and bright.</p>' --porcelain)"
set +e
LT_POST="$LT_POST" python3 tests/browser/livetake.py "http://localhost:$PORT"
T=$?
set -e
$WP eval 'foreach ( rrt_sent_list( 1 ) as $t ) { rrt_sent_delete( 1, $t["id"] ); }'
$WP post delete "$LT_POST" --force --quiet
[ $T -eq 0 ] || exit $T

echo "== One reading from several takes"
# A post of two paragraphs of two sentences, for combine.py (1.3.0).
CB_POST="$($WP post create --post_title='Combine test' --post_status=publish --post_content='<p>Leaves fall slowly. Rain taps the glass.</p><p>Owls call softly. The lamp stays lit.</p>' --porcelain)"
set +e
CB_POST="$CB_POST" python3 tests/browser/combine.py "http://localhost:$PORT"
T=$?
set -e
$WP post delete "$CB_POST" --force --quiet
[ $T -eq 0 ] || exit $T

echo "== The take shelf across computers (1.34.0)"
# The same short post, read on two computers and a phone, with the take shelf on the site switched on for the test only.
SH_POST="$($WP post create --post_title='Shelf test' --post_status=publish --post_content='<p>Leaves fall slowly. Rain taps the glass.</p><p>Owls call softly. The lamp stays lit.</p>' --porcelain)"
$WP option update rrt_shelf '{"on":true,"limit":2048}' --format=json --quiet
set +e
SH_POST="$SH_POST" python3 tests/browser/shelf.py "http://localhost:$PORT"
T=$?
set -e
$WP eval 'rrt_shelf_delete_all();'
$WP option delete rrt_shelf --quiet
$WP post delete "$SH_POST" --force --quiet
[ $T -eq 0 ] || exit $T

echo "== YouTube and SoundCloud players that load on play (1.35.0)"
# One post with a reading and four players, both switches on and their pictures saved, so nothing is asked of either service.
$WP eval-file tests/wp/embeds.php > "$WP_DIR/embeds.json"
set +e
EM_SEED="$WP_DIR/embeds.json" python3 tests/browser/embeds.py "http://localhost:$PORT"
T=$?
set -e
$WP eval-file tests/wp/embeds.php off > /dev/null
[ $T -eq 0 ] || exit $T

echo "== Prompter view"
# The same short post, for prompter.py (1.5.0).
PV_POST="$($WP post create --post_title='Prompter test' --post_status=publish --post_content='<p>Leaves fall slowly. Rain taps the glass.</p><p>Owls call softly. The lamp stays lit.</p>' --porcelain)"
set +e
PV_POST="$PV_POST" python3 tests/browser/prompter.py "http://localhost:$PORT"
T=$?
set -e
$WP post delete "$PV_POST" --force --quiet
[ $T -eq 0 ] || exit $T

echo "== Verse and pace"
# A post of short lines, for verse.py (1.11.0).
VS_POST="$($WP post create --post_title='Verse test' --post_status=publish --post_content='<p>The moon is round<br>the night is long<br>the owl is near</p><p>The water sings<br>the stones are cold<br>the reeds bow low</p>' --porcelain)"
set +e
VS_POST="$VS_POST" python3 tests/browser/verse.py "http://localhost:$PORT"
T=$?
set -e
$WP post delete "$VS_POST" --force --quiet
[ $T -eq 0 ] || exit $T

echo "== Keep a reading aside"
# A post with a 3 s reading and captions in the Media Library, for keep.py (1.8.0).
KP_POST="$($WP eval-file tests/wp/keep.php)"
set +e
KP_POST="$KP_POST" python3 tests/browser/keep.py "http://localhost:$PORT"
T=$?
set -e
$WP eval-file tests/wp/keep.php off "$KP_POST"
[ $T -eq 0 ] || exit $T

echo "== Finishing presets"
# The same short post, for finish.py (1.6.0).
FN_POST="$($WP post create --post_title='Finish test' --post_status=publish --post_content='<p>Leaves fall slowly. Rain taps the glass.</p><p>Owls call softly. The lamp stays lit.</p>' --porcelain)"
set +e
FN_POST="$FN_POST" python3 tests/browser/finish.py "http://localhost:$PORT"
T=$?
if [ $T -eq 0 ]; then
	# The WAV put on the post by Use this recording is in the media library as a WAV.
	MIME="$($WP eval "echo get_post_mime_type( (int) get_post_meta( $FN_POST, 'rrt_audio', true ) );")"
	if [ "$MIME" = "audio/wav" ] || [ "$MIME" = "audio/x-wav" ]; then echo "ok   the reading on the post is the WAV ($MIME)"; else echo "FAIL the reading on the post is the WAV: got '$MIME'"; T=1; fi
fi
set -e
for A in $($WP post list --post_type=attachment --post_parent="$FN_POST" --field=ID --format=ids); do $WP post delete "$A" --force --quiet; done
$WP post delete "$FN_POST" --force --quiet
[ $T -eq 0 ] || exit $T

echo "== Voice polish"
# A post of about 70 words, long enough (30 s or more) for a take to count towards its setup's EQ, for polish.py (1.13.0).
PL_POST="$($WP post create --post_title='Polish test' --post_status=publish --post_content='<p>Leaves fall slowly over the quiet garden. Rain taps the glass by the window. Owls call softly from the old oak. The lamp stays lit until the morning comes.</p><p>A fox walks along the wall and looks back once. The river runs high after the storm. Smoke curls from the chimney into the grey sky. Bread cools on the table by the door.</p><p>The candles burn low in the evening. A cat sleeps on the warm stone step. Stars come out one by one over the hill.</p>' --porcelain)"
set +e
PL_POST="$PL_POST" python3 tests/browser/polish.py "http://localhost:$PORT"
T=$?
set -e
$WP eval 'delete_user_meta( 1, "_rrt_eq" );'
$WP post delete "$PL_POST" --force --quiet
[ $T -eq 0 ] || exit $T

echo "== The stronger cleanup"
# About 40 words of plain sentences, for stronger.py (1.16.0), which runs the real DeepFilterNet 3 model.
SC_POST="$($WP post create --post_title='Stronger cleanup test' --post_status=publish --post_content='<p>Leaves fall slowly over the quiet garden. Rain taps the glass by the window. Owls call softly from the old oak. The lamp stays lit until the morning comes.</p><p>A fox walks along the wall and looks back once. The river runs high after the storm. Smoke curls from the chimney.</p>' --porcelain)"
set +e
SC_POST="$SC_POST" python3 tests/browser/stronger.py "http://localhost:$PORT"
T=$?
set -e
$WP eval '$s = get_option( "rrt_settings" ); if ( is_array( $s ) ) { $s["sound"]["engine"] = "standard"; $s["sound"]["denoise"] = "medium"; update_option( "rrt_settings", $s ); }'
$WP post delete "$SC_POST" --force --quiet
[ $T -eq 0 ] || exit $T

echo "== The faster review"
# About 30 words of plain sentences, for faster.py (1.17.0), which stands in for the graphics chip and Whisper.
FR_POST="$($WP post create --post_title='Faster review test' --post_status=publish --post_content='<p>Leaves fall slowly over the quiet garden. Rain taps the glass by the window. Owls call softly from the old oak.</p><p>A fox walks along the wall and looks back once. The river runs high after the storm.</p>' --porcelain)"
set +e
FR_POST="$FR_POST" python3 tests/browser/faster.py "http://localhost:$PORT"
T=$?
set -e
$WP post delete "$FR_POST" --force --quiet
[ $T -eq 0 ] || exit $T

echo "== Reading languages"
# An English, a Spanish and a Russian post, for languages.py (1.33.0), which stands in for Whisper.
LG_EN="$($WP post create --post_title='Language test en' --post_status=publish --post_content='<p>Leaves fall slowly over the quiet garden. Rain taps the glass by the window.</p>' --porcelain)"
LG_ES="$($WP post create --post_title='Language test es' --post_status=publish --post_content='<p>El búho canta sobre el río. La luna brilla esta noche sobre el bosque.</p>' --porcelain)"
LG_RU="$($WP post create --post_title='Language test ru' --post_status=publish --post_content='<p>Совы тихо кричат над рекой. Луна сегодня яркая и тихая.</p>' --porcelain)"
# A long last paragraph, for the end of the script at phone width.
LG_ES2="$($WP post create --post_title='Language test es long' --post_status=publish --post_content='<p>Una frase corta para empezar.</p><p>El viento soplaba entre los árboles del bosque mientras la luna subía despacio sobre el valle. En la casa del molino, las luces se encendían una por una y el olor del pan llegaba hasta el camino. Los niños corrían junto al agua, contaban las estrellas en voz alta y volvían a casa cantando una canción antigua que nadie recordaba entera.</p>' --porcelain)"
$WP post meta update "$LG_ES" _rrt_lang es --quiet
$WP post meta update "$LG_ES2" _rrt_lang es --quiet
$WP post meta update "$LG_RU" _rrt_lang ru --quiet
set +e
LG_EN="$LG_EN" LG_ES="$LG_ES" LG_ES2="$LG_ES2" LG_RU="$LG_RU" LG_SHOTS="${LANG_SHOTS:-}" python3 tests/browser/languages.py "http://localhost:$PORT"
T=$?
set -e
$WP post delete "$LG_EN" "$LG_ES" "$LG_ES2" "$LG_RU" --force --quiet
[ $T -eq 0 ] || exit $T

echo "== Voice following"
# Four short paragraphs, for follow.py (1.14.0), which stands in for the speech model.
FV_POST="$($WP post create --post_title='Follow test' --post_status=publish --post_content='<p>The lantern swings above the harbour gate while gulls argue over the catch.</p><p>Fishermen mend their nets beside the market and the smell of salt drifts up the lane.</p><p>Old Marisol keeps the tide tables pinned inside her window with a stub of red pencil.</p><p>By noon the fog lifts and children race along the breakwater counting waves in threes.</p>' --porcelain)"
set +e
FV_POST="$FV_POST" python3 tests/browser/follow.py "http://localhost:$PORT"
T=$?
set -e
$WP post delete "$FV_POST" --force --quiet
[ $T -eq 0 ] || exit $T

echo "== The pronunciation list"
# A post with names in it and no reading yet, for pronounce.py (1.15.0).
PN_POST="$($WP post create --post_title='Pronounce browser test' --post_status=publish --post_content='<p>Lachesis spins the thread and Atropos cuts it.</p><p>We light a candle for Brigid tonight.</p>' --porcelain)"
set +e
PN_POST="$PN_POST" python3 tests/browser/pronounce.py "http://localhost:$PORT"
T=$?
set -e
$WP eval 'delete_user_meta( 1, "rrt_pron_skip" );'
$WP post delete "$PN_POST" --force --quiet
[ $T -eq 0 ] || exit $T

echo "== Clip suggestions"
# Two posts with the same three-minute reading and captions, one at -19 LUFS and one at -26, for suggest.py (1.7.0).
$WP eval-file tests/wp/suggest.php -19 > "$WP_DIR/suggest.json"
$WP eval-file tests/wp/suggest.php -26 > "$WP_DIR/suggest-quiet.json"
SG_POST="$(python3 -c "import json,sys; print(json.load(open(sys.argv[1]))['post'])" "$WP_DIR/suggest.json")"
SG_QPOST="$(python3 -c "import json,sys; print(json.load(open(sys.argv[1]))['post'])" "$WP_DIR/suggest-quiet.json")"
set +e
SG_SEED="$WP_DIR/suggest.json" SG_QUIET="$WP_DIR/suggest-quiet.json" python3 tests/browser/suggest.py "http://localhost:$PORT"
T=$?
if [ $T -eq 0 ]; then
	# The browser test leaves the opening as the post's pick; a new reading on the post clears it.
	PICK="$($WP eval "\$p = rrt_soundbite_pick( $SG_POST ); echo \$p ? \$p['s'] . ' ' . \$p['d'] : 'none';")"
	# The opening: from 0.25 s, 30 to 60 s long.
	if python3 -c "import sys; s, d = map(float, sys.argv[1].split()); sys.exit(0 if s == 0.25 and 30 <= d <= 60.6 else 1)" "$PICK" 2>/dev/null; then echo "ok   the pick is kept in the post ($PICK)"; else echo "FAIL the pick is kept in the post: got '$PICK'"; T=1; fi
	$WP post meta update "$SG_POST" rrt_audio "http://localhost:$PORT/wp-content/uploads/another.mp3" --quiet
	PICK="$($WP eval "echo rrt_soundbite_pick( $SG_POST ) ? 'kept' : 'none'; echo get_post_meta( $SG_POST, '_rrt_soundbite', true ) ? ' left' : '';")"
	if [ "$PICK" = "none" ]; then echo "ok   a new reading on the post clears the pick"; else echo "FAIL a new reading on the post clears the pick: got '$PICK'"; T=1; fi
fi
set -e
$WP eval-file tests/wp/suggest.php off "$SG_POST"
$WP eval-file tests/wp/suggest.php off "$SG_QPOST"
[ $T -eq 0 ] || exit $T

echo "== Full-length video"
# A post with a 16 s reading, captions and chapters, for video.py (1.18.0).
$WP eval-file tests/wp/video.php > "$WP_DIR/video.json"
VD_POST="$(python3 -c "import json,sys; print(json.load(open(sys.argv[1]))['post'])" "$WP_DIR/video.json")"
set +e
VD_SEED="$WP_DIR/video.json" python3 tests/browser/video.py "http://localhost:$PORT"
T=$?
set -e
$WP eval-file tests/wp/video.php off "$VD_POST"
[ $T -eq 0 ] || exit $T

echo "== Collections"
# Three readings made with the plugin's own MP3 encoder, for collection.py (1.19.0).
CL_MP3="$(cd tests/browser && python3 -c "import take_assets, os; print(os.path.join(take_assets.NPM, 'wasm-media-encoders-' + take_assets.version('RRT_MP3_VERSION'), 'package', 'wasm', 'mp3.wasm'))")"
mkdir -p "$WP_DIR/collections"
node tests/wp/collections-mp3.js "$CL_MP3" "$WP_DIR/collections" > /dev/null
$WP eval-file tests/wp/collections.php "$WP_DIR/collections" > "$WP_DIR/collections.json"
CL_IDS="$(python3 -c "import json,sys; print(','.join(str(v) for v in json.load(open(sys.argv[1])).values()))" "$WP_DIR/collections.json")"
set +e
CL_SEED="$WP_DIR/collections.json" CL_MP3="$CL_MP3" python3 tests/browser/collection.py "http://localhost:$PORT"
T=$?
set -e
$WP eval-file tests/wp/collections.php off "$CL_IDS"
[ $T -eq 0 ] || exit $T

echo "== Audiobook export"
# A collection of three readings and its opening credits, made with the plugin's own MP3 encoder, for audiobook.py (1.20.0).
mkdir -p "$WP_DIR/audiobook"
node tests/wp/audiobook-mp3.js "$CL_MP3" "$WP_DIR/audiobook" > /dev/null
$WP eval-file tests/wp/audiobook.php "$WP_DIR/audiobook" > "$WP_DIR/audiobook.json"
AB_IDS="$(python3 -c "import json,sys; j=json.load(open(sys.argv[1])); print(','.join(str(j[k]) for k in ['a','b','c','col','open']))" "$WP_DIR/audiobook.json")"
set +e
AB_SEED="$WP_DIR/audiobook.json" AB_DIR="$WP_DIR/audiobook" python3 tests/browser/audiobook.py "http://localhost:$PORT"
T=$?
set -e
$WP eval-file tests/wp/audiobook.php off "$AB_IDS"
$WP eval 'foreach ( rrt_sent_list( 1 ) as $t ) { rrt_sent_delete( 1, $t["id"] ); }'
[ $T -eq 0 ] || exit $T

echo "== Member feeds"
# Two posts (one for members) and two subscribers, for members.py (1.21.0).
MB_WAS="$($WP option get rrt_podcast --format=json 2>/dev/null || echo none)"
MB_SET="$($WP option get rrt_settings --format=json)"
$WP eval-file tests/wp/members.php > "$WP_DIR/members.json"
MB_IDS="$(python3 -c "import json,sys; j=json.load(open(sys.argv[1])); print(j['open'], j['closed'], j['friend'], j['other'])" "$WP_DIR/members.json")"
set +e
MB_SEED="$WP_DIR/members.json" python3 tests/browser/members.py "http://localhost:$PORT"
T=$?
set -e
$WP eval-file tests/wp/members.php off $MB_IDS
if [ "$MB_WAS" = "none" ]; then $WP option delete rrt_podcast --quiet; else $WP option update rrt_podcast "$MB_WAS" --format=json --quiet; fi
$WP option update rrt_settings "$MB_SET" --format=json --quiet
[ $T -eq 0 ] || exit $T

echo "== Sponsor messages"
# A long reading and a recorded message, made with the plugin's own MP3 encoder, for sponsors.py (1.22.0).
SP_WAS="$($WP option get rrt_podcast --format=json 2>/dev/null || echo none)"
mkdir -p "$WP_DIR/sponsors"
node tests/wp/sponsors-mp3.js "$CL_MP3" "$WP_DIR/sponsors" > /dev/null
$WP eval-file tests/wp/sponsors.php "$WP_DIR/sponsors" > "$WP_DIR/sponsors.json"
SP_IDS="$(python3 -c "import json,sys; j=json.load(open(sys.argv[1])); print('%d,%d' % (j['reading'], j['spot']))" "$WP_DIR/sponsors.json")"
set +e
SP_SEED="$WP_DIR/sponsors.json" SP_MP3="$CL_MP3" python3 tests/browser/sponsors.py "http://localhost:$PORT"
T=$?
set -e
$WP eval-file tests/wp/sponsors.php off "$SP_IDS"
if [ "$SP_WAS" = "none" ]; then $WP option delete rrt_podcast --quiet; else $WP option update rrt_podcast "$SP_WAS" --format=json --quiet; fi
[ $T -eq 0 ] || exit $T

echo "== Storage offload"
# A post with a real MP3 reading (the sponsor test's), for offload.py (1.23.0).
$WP eval-file tests/wp/offload.php "$WP_DIR/sponsors/reading.mp3" > "$WP_DIR/offload.json"
OF_POST="$(python3 -c "import json,sys; print(json.load(open(sys.argv[1]))['post'])" "$WP_DIR/offload.json")"
set +e
OF_SEED="$WP_DIR/offload.json" OFF_PORT="$OFF_PORT" python3 tests/browser/offload.py "http://localhost:$PORT"
T=$?
set -e
$WP eval-file tests/wp/offload.php off "$OF_POST"
[ $T -eq 0 ] || exit $T

echo "== Download counting to the IAB guidelines"
$WP eval-file tests/wp/dlcount.php > "$WP_DIR/dlcount.json"
set +e
DC_SEED="$WP_DIR/dlcount.json" python3 tests/browser/dlcount.py "http://localhost:$PORT"
T=$?
set -e
$WP eval-file tests/wp/dlcount.php off > /dev/null
[ $T -eq 0 ] || exit $T

echo "== Check private folders (1.25.0)"
set +e
python3 tests/browser/privatecheck.py "http://localhost:$PORT" "$WP_DIR/wordpress/wp-content/uploads"
T=$?
set -e
$WP option delete rrt_private_check > /dev/null 2>&1 || true
[ $T -eq 0 ] || exit $T

echo "== Help (1.32.0)"
HELP_SHOTS="$WP_DIR" python3 tests/browser/help.py "http://localhost:$PORT"

echo "== The plugin's screens at an iPhone's width (1.33.0)"
PHONE_SHOTS="$WP_DIR" python3 tests/browser/phone_admin.py "http://localhost:$PORT"

echo "== Accessibility of the player and the admin screens (1.26.0)"
$WP eval-file tests/wp/a11y.php > "$WP_DIR/a11y.json"
set +e
A11Y_SEED="$WP_DIR/a11y.json" python3 tests/browser/a11y.py "http://localhost:$PORT"
T=$?
if [ $T -eq 0 ]; then
	echo "== Accessibility of the studio (1.27.0)"
	A11Y_SEED="$WP_DIR/a11y.json" A11Y_SHOTS="$WP_DIR" python3 tests/browser/a11y_studio.py "http://localhost:$PORT"
	T=$?
fi
set -e
$WP eval-file tests/wp/a11y.php off > /dev/null
[ $T -eq 0 ] || exit $T

echo "== The full Podcasting 2.0 feed"
# A post with a three-minute reading whose MP3 has chapters, and a plain one, for feed.py (1.10.0).
FF_WAS="$($WP option get rrt_podcast --format=json 2>/dev/null || echo none)"
$WP eval-file tests/wp/feed.php > "$WP_DIR/feed.json"
FF_IDS="$(python3 -c "import json,sys; j=json.load(open(sys.argv[1])); print(j['post'], j['plain'])" "$WP_DIR/feed.json")"
set +e
FF_SEED="$WP_DIR/feed.json" python3 tests/browser/feed.py "http://localhost:$PORT"
T=$?
set -e
$WP eval-file tests/wp/feed.php off $FF_IDS
if [ "$FF_WAS" = "none" ]; then $WP option delete rrt_podcast --quiet; else $WP option update rrt_podcast "$FF_WAS" --format=json --quiet; fi
$WP option delete rrt_podcast_ping --quiet 2>/dev/null || true
[ $T -eq 0 ] || exit $T

echo "== Translations"
php "$WP_DIR/wp-cli.phar" i18n make-pot "$PLUGIN" "$WP_DIR/check.pot" --allow-root --domain=foxfire-studio --exclude=assets/vendor > /dev/null 2>&1
msgids() { grep -E '^msgid(_plural)? ' "$1" | sort; }
if ! diff <(msgids "$WP_DIR/check.pot") <(msgids "$PLUGIN/languages/foxfire-studio.pot") > "$WP_DIR/pot.diff"; then
	head -20 "$WP_DIR/pot.diff"
	echo "languages/foxfire-studio.pot is out of date: run tools/make-pot.sh and commit it."
	exit 1
fi
python3 tests/wp/pseudo.py "$WP_DIR/wordpress"
$WP option update foxfire_test_locale 1 --quiet
set +e
python3 tests/browser/translated.py "http://localhost:$PORT"
T=$?
set -e
$WP option delete foxfire_test_locale --quiet
[ $T -eq 0 ] || exit $T

echo "== Uninstall, keeping and deleting data"
tests/wp/uninstall.sh

echo "== Upgrades from older releases"
python3 tests/upgrade.py

scan_log
echo "All checks passed."
