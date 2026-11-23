#!/usr/bin/env bash
# Rebuilds plugin/foxfire-studio/languages/foxfire-studio.pot, the list of
# every string translators work from. Run it after changing any text.
# Needs WP-CLI: uses $WP_CLI if set, else `wp`, else the test site's copy.
set -euo pipefail
REPO="$(cd "$(dirname "$0")/.." && pwd)"
WP="${WP_CLI:-}"
if [ -z "$WP" ]; then
	if command -v wp > /dev/null; then WP=wp; else WP="php ${WP_DIR:-/tmp/foxfire-wp}/wp-cli.phar"; fi
fi
cd "$REPO/plugin/foxfire-studio"
$WP i18n make-pot . languages/foxfire-studio.pot --allow-root --domain=foxfire-studio --exclude=assets/vendor \
	--headers='{"Report-Msgid-Bugs-To":"https://github.com/FoxfireRising/Foxfire-Studio/issues"}' 2>&1 | grep -v "different translator comments" || true
