#!/usr/bin/env bash
# Fetch the real WordPress block parser for the test suite.
# The parser is not vendored so it always matches the WP branch we target.
set -euo pipefail

BRANCH="${1:-6.9-branch}"
DIR="$(cd "$(dirname "$0")" && pwd)/wp-shim"
BASE="https://raw.githubusercontent.com/WordPress/WordPress/${BRANCH}/wp-includes"

mkdir -p "$DIR"
for file in class-wp-block-parser.php class-wp-block-parser-block.php class-wp-block-parser-frame.php; do
	curl -sS --fail --max-time 30 -o "$DIR/$file" "$BASE/$file"
	echo "fetched $file"
done

echo "WordPress block parser ready (branch: $BRANCH)."
