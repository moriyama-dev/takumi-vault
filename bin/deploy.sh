#!/usr/bin/env bash
#
# Copy the working tree into the local WordPress install for testing.
#
# The plugin cannot be symlinked from the developer's home directory: it is
# mode 750, so the web server cannot traverse into it and the plugin silently
# never loads over HTTP while still working perfectly under WP-CLI. That
# failure looked like "the AJAX endpoint returns 400" and cost an afternoon.
#
# Every test script calls this first. Testing a stale copy is otherwise only
# ever one forgotten command away.
#
set -euo pipefail

SLUG="takumi-vault"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${TKVAULT_WP_PLUGINS:-/var/www/html/wp-content/plugins}/${SLUG}"

mkdir -p "${TARGET}"

rsync -a --delete \
	--exclude '.git' \
	--exclude '.gitignore' \
	--exclude 'bin/' \
	--exclude 'tests/' \
	--exclude 'dist/' \
	--exclude '*.md' \
	--exclude 'languages/*.po' \
	--exclude 'languages/*.mo' \
	"${ROOT}/" "${TARGET}/"

# The web server runs as a different account than the developer.
chmod -R o+rX "${TARGET}"

echo "Deployed $(git -C "${ROOT}" rev-parse --short HEAD 2>/dev/null || echo 'working tree') to ${TARGET}"
