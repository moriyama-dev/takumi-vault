#!/usr/bin/env bash
#
# Deploy the working tree and run every suite.
#
#   tests/run-all.sh
#
# Exits non-zero if any suite reports a failure, so it can gate a commit.
#
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WP="${TKVAULT_WP_ROOT:-/var/www/html}"

"${ROOT}/bin/deploy.sh"

echo
echo "Snapshot the database first if you have not already:"
echo "  cd ${WP} && wp db export ~/db-snapshots/\$(date +%Y%m%d_%H%M%S).sql"
echo

failed=0

for suite in test-job-runner test-db-dump test-db-restore; do
	echo "──────────────────────────────────────────────────────────"
	echo "  ${suite}"
	echo "──────────────────────────────────────────────────────────"

	output="$( cd "${WP}" && wp eval-file "${ROOT}/tests/${suite}.php" 2>&1 )"
	echo "${output}" | grep -E '^\s+\[FAIL\]|^====' || true

	if ! echo "${output}" | grep -q '0 failed'; then
		failed=1
		echo "  ^ ${suite} reported failures; run it directly for the full output"
	fi
	echo
done

if [ "${failed}" -ne 0 ]; then
	echo "FAILED"
	exit 1
fi

echo "All suites passed."
