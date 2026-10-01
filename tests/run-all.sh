#!/usr/bin/env bash
# Run the whole test suite, the same way locally and in CI.
#
# Every test is a plain PHP script that exits non-zero on failure. This
# runner adds two things the scripts cannot do for themselves:
#
# - It finds them. A new tests/<name>.php runs without being registered
#   anywhere, so a test cannot be written and then silently never run.
#   Helpers are recognised by being required from another test
#   (bootstrap.php, kses-stub.php) and are not run on their own.
# - It treats a PHP warning, notice or deprecation as a failure. The
#   scripts check results, not the noise around them, and a "Deprecated:"
#   on a newer PHP is the first sign of the next breakage.
#
# The integration test needs the dbw-base-core block definitions. It runs
# when they are found (DBW_CORE_PATH, or the sibling checkout this repo
# usually lives next to) and is skipped with a notice otherwise, as in CI.
#
# Usage: bash tests/run-all.sh
set -euo pipefail

TESTS="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(dirname "$TESTS")"
PHP="${PHP:-php}"
PHP_FLAGS=(-d error_reporting=-1 -d display_errors=1 -d display_startup_errors=1 -d log_errors=0)
NOISE='(PHP )?(Warning|Notice|Deprecated|Fatal error|Parse error):'

for file in class-wp-block-parser.php class-wp-block-parser-block.php class-wp-block-parser-frame.php; do
	if [ ! -f "$TESTS/wp-shim/$file" ]; then
		echo "WordPress block parser missing, fetching it."
		bash "$TESTS/fetch-shim.sh"
		break
	fi
done

"$PHP" -r 'echo "PHP ", PHP_VERSION, "\n";'

passed=0
failed=0
skipped=0
failures=()

# run_test <label> <script> [args...]
run_test() {
	local label="$1"
	shift
	local output status
	set +e
	output="$("$PHP" "${PHP_FLAGS[@]}" "$@" 2>&1)"
	status=$?
	set -e
	if [ "$status" -ne 0 ]; then
		echo "FAIL  $label (exit $status)"
	elif printf '%s\n' "$output" | grep -Eq "$NOISE"; then
		echo "FAIL  $label (PHP warning, notice or deprecation)"
	else
		echo "ok    $label"
		passed=$((passed + 1))
		return 0
	fi
	printf '%s\n' "$output" | sed 's/^/      /'
	failed=$((failed + 1))
	failures+=("$label")
}

is_helper() {
	local name="$1"
	grep -lqF "__DIR__ . '/$name'" "$TESTS"/*.php
}

run_test run-tests.php "$TESTS/run-tests.php"

for path in "$TESTS"/*.php; do
	name="$(basename "$path")"
	case "$name" in
		run-*.php) continue ;;
	esac
	if is_helper "$name"; then
		continue
	fi
	run_test "$name" "$path"
done

core="${DBW_CORE_PATH:-$(dirname "$(dirname "$ROOT")")/01_Webprojekte/dbw-base-core}"
if [ -d "$core/blocks/src" ]; then
	run_test run-integration.php "$TESTS/run-integration.php" "$core"
else
	echo "skip  run-integration.php (dbw-base-core not found at $core; set DBW_CORE_PATH to run it)"
	skipped=$((skipped + 1))
fi

echo
echo "$passed passed, $failed failed, $skipped skipped."
if [ "$failed" -ne 0 ]; then
	printf 'Failed: %s\n' "${failures[@]}"
	exit 1
fi
