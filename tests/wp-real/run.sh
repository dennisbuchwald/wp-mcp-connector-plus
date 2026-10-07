#!/usr/bin/env bash
# Run the real-WordPress tests, setting them up first where needed.
#
# Three suites, three PHPUnit runs: the main one on WordPress with
# GenerateBlocks (phpunit.xml.dist), and the Elementor one on the same
# WordPress with Elementor active as well (phpunit-elementor.xml.dist).
# A plugin cannot be deactivated again inside one process, hence two;
# the third runs with SiteGround Speed Optimizer active
# (phpunit-siteground.xml.dist) for the same reason.
#
# setup.sh keeps what it already fetched, so calling it on every run costs
# a Composer check and nothing else. Extra arguments go to PHPUnit, e.g.
#   bash tests/wp-real/run.sh --filter RestFence
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
PHP="${PHP:-php}"

bash "$HERE/setup.sh"

cd "$HERE"
for config in phpunit.xml.dist phpunit-elementor.xml.dist phpunit-siteground.xml.dist; do
	"$PHP" -d error_reporting=-1 -d display_errors=1 -d display_startup_errors=1 -d log_errors=0 \
		.cache/vendor/bin/phpunit -c "$config" "$@"
done
