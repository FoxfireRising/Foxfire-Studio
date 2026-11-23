#!/usr/bin/env bash
# Deletes the plugin the way the Plugins screen does, on a test site of its
# own with a real copy of the plugin (never the symlink the other tests use,
# since uninstalling that would delete the repo's files). Runs twice: once
# keeping data, once with "also delete everything else" ticked.
# Usage: tests/wp/uninstall.sh [dir]
set -euo pipefail

REPO="$(cd "$(dirname "$0")/../.." && pwd)"
DIR="${1:-${WP_UN_DIR:-/tmp/foxfire-un}}"
WP="php $DIR/wp-cli.phar --path=$DIR/wordpress --allow-root"
PLUG="$DIR/wordpress/wp-content/plugins/foxfire-studio"

WP_PORT=8091 "$REPO/tests/wp/setup.sh" "$DIR" > /dev/null
status=0
for mode in keep delete; do
	$WP plugin deactivate foxfire-studio --quiet 2>/dev/null || true
	rm -rf "$PLUG"
	cp -r "$REPO/plugin/foxfire-studio" "$PLUG"
	$WP plugin activate foxfire-studio --quiet
	$WP eval-file "$REPO/tests/php/uninstall-seed.php" "$mode"
	$WP plugin uninstall foxfire-studio --deactivate --quiet
	if [ -d "$PLUG" ]; then echo "FAIL [$mode] the plugin folder is still there"; status=1; fi
	$WP eval-file "$REPO/tests/php/uninstall-check.php" "$mode" || status=1
	# Put the symlink back, for the next round's setup.sh and any later run.
	rm -rf "$PLUG"
	ln -sfn "$REPO/plugin/foxfire-studio" "$PLUG"
done
exit $status
