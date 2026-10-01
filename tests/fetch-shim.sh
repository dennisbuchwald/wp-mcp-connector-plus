#!/usr/bin/env bash
# Fetch the real WordPress block parser for the test suite.
#
# The parser is not vendored, but it is pinned: a branch name moves, and
# a test run that passed yesterday could fail today for a change nobody
# made here. The default is one commit of WordPress/WordPress 6.9-branch
# (looked up with `git ls-remote https://github.com/WordPress/WordPress
# refs/heads/6.9-branch`). To try another state, pass a ref:
#
#   tests/fetch-shim.sh 7.0-branch
#   tests/fetch-shim.sh <commit sha>
set -euo pipefail

# WordPress/WordPress 6.9-branch, 2026-10-01.
PINNED="ad03a653e035e3f4a892575ddba73b8ef9db128d"
REF="${1:-$PINNED}"
DIR="$(cd "$(dirname "$0")" && pwd)/wp-shim"
BASE="https://raw.githubusercontent.com/WordPress/WordPress/${REF}/wp-includes"

mkdir -p "$DIR"
for file in class-wp-block-parser.php class-wp-block-parser-block.php class-wp-block-parser-frame.php; do
	curl -sS --fail --retry 3 --max-time 30 -o "$DIR/$file" "$BASE/$file"
	echo "fetched $file"
done

echo "WordPress block parser ready (ref: $REF)."
