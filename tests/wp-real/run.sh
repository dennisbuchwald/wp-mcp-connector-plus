#!/usr/bin/env bash
# Run the real-WordPress tests, setting them up first where needed.
#
# setup.sh keeps what it already fetched, so calling it on every run costs
# a Composer check and nothing else. Extra arguments go to PHPUnit, e.g.
#   bash tests/wp-real/run.sh --filter RestFence
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
PHP="${PHP:-php}"

bash "$HERE/setup.sh"

cd "$HERE"
exec "$PHP" -d error_reporting=-1 -d display_errors=1 -d display_startup_errors=1 -d log_errors=0 \
	.cache/vendor/bin/phpunit -c phpunit.xml.dist "$@"
