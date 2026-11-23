#!/usr/bin/env bash
# Makes the docs site's five short videos again (docs/media), on the throwaway
# test WordPress the tests use. Needs what tests/run.sh needs, plus ffmpeg with
# libass. Silent, captioned, made-up content only: see README.md here.
#
#   bash tools/videos/make.sh           (builds /tmp/foxfire-wp first if needed)
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
REPO="$(cd "$HERE/../.." && pwd)"
DIR=/tmp/foxfire-wp
WP="php $DIR/wp-cli.phar --path=$DIR/wordpress --allow-root"
[ -d "$DIR/wordpress" ] || bash "$REPO/tests/wp/setup.sh" "$DIR"

# A made-up site, post and picture.
$WP option update blogname "Lantern Lane" >/dev/null
$WP option update rrt_setup '{"status":"done"}' --format=json >/dev/null
POST=$($WP post list --post_type=post --title="The Keeper of the Light" --field=ID --posts_per_page=1 || true)
if [ -z "$POST" ]; then
	POST=$($WP post create "$HERE/post.html" --post_title="The Keeper of the Light" --post_status=publish --porcelain)
	$WP media import "$HERE/lighthouse.jpg" --post_id="$POST" --featured_image --title="A lighthouse at dusk" --porcelain >/dev/null
fi
export VID_POST="$POST"

PHP_CLI_SERVER_WORKERS=4 php -d upload_max_filesize=64M -d post_max_size=64M -S localhost:8080 -t "$DIR/wordpress" >/tmp/foxfire-videos-php.log 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null || true' EXIT
sleep 2

cd "$HERE"
python3 put.py      # the made-up take becomes the post's reading
$WP eval '$s = get_option( "rrt_settings" ); if ( ! is_array( $s ) ) { $s = array(); } if ( ! isset( $s["player"] ) ) { $s["player"] = rrt_config()["player"]; } $s["player"]["show"] = "everyone"; update_option( "rrt_settings", $s );'
for v in v1 v2 v3 v4 v5; do python3 "$v.py"; done
ffmpeg -loglevel error -y -ss 19 -i "$REPO/docs/media/first-reading.mp4" -frames:v 1 -vf "crop=1280:620:0:0" -q:v 3 "$REPO/docs/media/studio.jpg"
echo "Made docs/media. Watch every video before committing it."
