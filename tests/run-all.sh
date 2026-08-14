#!/usr/bin/env bash
#
# Deploy the working tree and run every suite.
#
#   tests/run-all.sh              ordinary run
#   tests/run-all.sh --no-exec    with the process functions disabled
#
# The second form is the acceptance criterion: the plugin has to complete
# every feature on a host where exec, shell_exec, passthru, proc_open and
# system are all switched off in disable_functions, which is a common
# hardening on shared hosting. Run it before every release.
#
# Exits non-zero if any suite reports a failure, so it can gate a commit.
#
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WP="${TKVAULT_WP_ROOT:-/var/www/html}"

DISABLED='exec,shell_exec,passthru,proc_open,system,popen,proc_close,proc_get_status'
WP_BIN="$(command -v wp)"

if [ "${1:-}" = "--no-exec" ]; then
	RUN=( php -d "disable_functions=${DISABLED}" "${WP_BIN}" )
	echo "Running with disable_functions=${DISABLED}"
else
	RUN=( "${WP_BIN}" )
fi

"${ROOT}/bin/deploy.sh"

echo
echo "Snapshot the database first if you have not already:"
echo "  cd ${WP} && wp db export ~/db-snapshots/\$(date +%Y%m%d_%H%M%S).sql"
echo

failed=0

for suite in test-job-runner test-db-dump test-file-backup test-db-restore test-file-restore test-scheduler test-permissions test-restore-confirm; do
	echo "──────────────────────────────────────────────────────────"
	echo "  ${suite}"
	echo "──────────────────────────────────────────────────────────"

	output="$( cd "${WP}" && "${RUN[@]}" eval-file "${ROOT}/tests/${suite}.php" 2>&1 )"
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
