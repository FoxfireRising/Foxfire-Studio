#!/usr/bin/env bash
# Builds a throwaway WordPress with SQLite (no database server) and the plugin
# linked in, for the automatic tests. Usage: tests/wp/setup.sh [dir]
# Needs php, git and curl. Everything comes from GitHub.
#
#   WP_REF=6.0            build from that WordPress version instead of the newest
#   SQLITE_REF=v2.1.3     use that version of the SQLite plugin (older WordPress needs an older one)
#   WP_DEBUG_DISPLAY=false  log PHP notices without printing them on the page
set -euo pipefail

REPO="$(cd "$(dirname "$0")/../.." && pwd)"
DIR="${1:-${WP_DIR:-/tmp/foxfire-wp}}"
WP_REF="${WP_REF:-}"
SQLITE_REF="${SQLITE_REF:-}"
DEBUG_DISPLAY="${WP_DEBUG_DISPLAY:-true}"
PORT="${WP_PORT:-8080}"
WP="php $DIR/wp-cli.phar --path=$DIR/wordpress --allow-root"

mkdir -p "$DIR"
cd "$DIR"

if [ ! -d wordpress ]; then
	if [ -n "$WP_REF" ]; then
		git clone --quiet --depth 1 --branch "$WP_REF" https://github.com/WordPress/WordPress wordpress
	else
		git clone --quiet --depth 1 https://github.com/WordPress/WordPress wordpress
	fi
fi
if [ ! -f wp-cli.phar ]; then
	curl -sSL -o wp-cli.phar https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar
fi
if [ ! -d sqlite ]; then
	if [ -n "$SQLITE_REF" ]; then
		git clone --quiet --depth 1 --branch "$SQLITE_REF" https://github.com/WordPress/sqlite-database-integration sqlite
	else
		git clone --quiet --depth 1 https://github.com/WordPress/sqlite-database-integration sqlite
	fi
fi

PLUG="$DIR/wordpress/wp-content/plugins"
SRC="$DIR/sqlite"
[ -d "$SRC/packages/plugin-sqlite-database-integration" ] && SRC="$SRC/packages/plugin-sqlite-database-integration"
if [ ! -d "$PLUG/sqlite-database-integration" ]; then
	cp -rL "$SRC" "$PLUG/sqlite-database-integration"
fi
if [ ! -f "$DIR/wordpress/wp-content/db.php" ]; then
	sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$PLUG/sqlite-database-integration#" \
	    -e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
	    "$PLUG/sqlite-database-integration/db.copy" > "$DIR/wordpress/wp-content/db.php"
fi
ln -sfn "$REPO/plugin/foxfire-studio" "$PLUG/foxfire-studio"

if [ ! -f wordpress/wp-config.php ]; then
	$WP config create --dbname=wp --dbuser=wp --dbpass=wp --skip-check --quiet
	$WP config set WP_DEBUG true --raw --quiet
	$WP config set WP_DEBUG_LOG true --raw --quiet
	$WP config set WP_DEBUG_DISPLAY "$DEBUG_DISPLAY" --raw --quiet
fi
if ! $WP core is-installed 2>/dev/null; then
	$WP core install --url="http://localhost:$PORT" --title="Test site" --admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email --quiet
	$WP option update siteurl "http://localhost:$PORT" --quiet
	$WP option update home "http://localhost:$PORT" --quiet
	$WP user create editor editor@example.test --role=editor --user_pass=editor --quiet
	$WP post create --post_title="The Test Reading" --post_status=publish \
		--post_content='<p>The fox ran over the hill, under the moon.</p><h2>Second part</h2><p>Owls called across the river, and the night grew still.</p>' --quiet
fi
$WP plugin activate foxfire-studio --quiet
echo "WordPress ready in $DIR/wordpress (admin/admin, editor/editor)."
