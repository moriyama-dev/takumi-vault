#!/usr/bin/env bash
#
# Build the distribution zip for WordPress.org.
#
# Everything that must not ship is excluded here rather than removed from the
# repository: dotfiles (Plugin Check rejects them), development tooling, and
# compiled translations. Only languages/*.pot is shipped - bundling .po/.mo
# breaks the guidelines, because translation is handled by
# translate.wordpress.org.
#
set -euo pipefail

SLUG="takumi-vault"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST="${ROOT}/dist"
STAGE="${DIST}/${SLUG}"

VERSION="$(grep -m1 '^ \* Version:' "${ROOT}/${SLUG}.php" | awk '{print $3}')"
STABLE="$(grep -m1 '^Stable tag:' "${ROOT}/readme.txt" | awk '{print $3}')"

if [ "${VERSION}" != "${STABLE}" ]; then
	echo "ERROR: plugin header Version (${VERSION}) != readme.txt Stable tag (${STABLE})" >&2
	exit 1
fi

rm -rf "${DIST}"
mkdir -p "${STAGE}"

# rsync is only a build-time convenience here; the plugin itself never shells out.
rsync -a \
	--exclude '.*' \
	--exclude 'bin/' \
	--exclude 'dist/' \
	--exclude 'node_modules/' \
	--exclude 'README.md' \
	--exclude 'languages/*.po' \
	--exclude 'languages/*.mo' \
	"${ROOT}/" "${STAGE}/"

# Built with Python's zipfile rather than the zip binary, which is not present
# on every machine. Nothing here needs a system package.
python3 - "${DIST}" "${SLUG}" "${VERSION}" <<'PY'
import os, sys, zipfile

dist, slug, version = sys.argv[1], sys.argv[2], sys.argv[3]
stage = os.path.join(dist, slug)
archive = os.path.join(dist, '{}-{}.zip'.format(slug, version))

with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED) as z:
    for root, dirs, files in os.walk(stage):
        dirs.sort()
        for name in sorted(files):
            path = os.path.join(root, name)
            z.write(path, os.path.relpath(path, dist))

print('Built {} (version {})'.format(archive, version))
print()
print('Contents:')
with zipfile.ZipFile(archive) as z:
    for info in z.infolist():
        print('  {:>8}  {}'.format(info.file_size, info.filename))
PY
