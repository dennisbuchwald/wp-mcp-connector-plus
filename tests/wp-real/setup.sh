#!/usr/bin/env bash
# Fetch what the real-WordPress tests run on, into tests/wp-real/.cache.
#
# The rest of the suite runs the plugin against shims: small stand-ins
# for the WordPress functions it calls. A shim answers what its author
# thought WordPress answers. Where that is the whole question (does kses
# really remove this, which filter priority really wins, does a save
# really keep a backslash) only WordPress itself can say, so this layer
# runs a real WordPress:
#
# - WordPress core, at an exact version.
# - The SQLite Database Integration drop-in, so no MySQL server is
#   needed, locally or in CI. It is the same drop-in WordPress Playground
#   and wp-now run on.
# - The WordPress PHPUnit test library at the same version (wp-phpunit),
#   PHPUnit 9.6 and the PHPUnit Polyfills it requires, from
#   tests/wp-real/composer.json. They install into .cache/vendor: the
#   plugin's own vendor/ ships to customers and must not carry them.
# - GenerateBlocks, at an exact version. Its element block keeps its
#   wrapper in the saved markup although it has a render callback, which
#   is the case the wrapper check in tree.php has to get right, and
#   customer sites run it. Active in every test, as on those sites.
#
# The plugin is linked into wp-content/plugins under its real slug and
# activated the way WordPress activates it (see bootstrap.php).
#
# Everything lands in .cache, which git ignores. Run again at any time:
# what is there and matches the pinned versions is kept.
#
# Usage: bash tests/wp-real/setup.sh
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(dirname "$(dirname "$HERE")")"
CACHE="$HERE/.cache"
PHP="${PHP:-php}"

# Pinned. Raise both WordPress numbers together: the test library has to
# be the one released with that core (wp-phpunit/wp-phpunit in
# composer.json), which is why this is 6.9.8 and not a newer 6.9 release
# the library has not been published for. The checksums are what was
# downloaded when the versions were pinned; WordPress.org publishes a
# SHA-1 for core as well, which is checked too.
WP_VERSION="6.9.8"
WP_SHA256="34d8ee108fd6eeaf068a24e50d6807226b38f2eeeb371b3c5f4694a333a93b43"
SQLITE_VERSION="3.0.2"
SQLITE_SHA256="1602e75577ad9b3a7e3e4a6a44a81b9541cdee2124d48928faf61c6fd3cd4f74"
GB_VERSION="2.4.1"
GB_SHA256="d270935aa81900889c8487c636e9a0f49e4cc655caebf3e4870e0b2b45c978b9"

DOWNLOADS="$CACHE/downloads"
WP_DIR="$CACHE/wordpress"
STAMP="$WP_DIR/.wpmcp-setup"
WANT="wordpress=$WP_VERSION sqlite=$SQLITE_VERSION generateblocks=$GB_VERSION"

# digest <bits> <file>
digest() {
	if command -v "sha$1sum" > /dev/null; then
		"sha$1sum" "$2" | cut -d' ' -f1
	else
		shasum -a "$1" "$2" | cut -d' ' -f1
	fi
}

# fetch <url> <file> <sha256>
fetch() {
	local url="$1" file="$2" sum="$3"
	if [ -f "$file" ] && [ "$(digest 256 "$file")" = "$sum" ]; then
		return 0
	fi
	echo "Downloading $url"
	curl -fsSL --retry 3 -o "$file.part" "$url"
	local got
	got="$(digest 256 "$file.part")"
	if [ "$got" != "$sum" ]; then
		rm -f "$file.part"
		echo "Checksum mismatch for $url: expected $sum, got $got" >&2
		exit 1
	fi
	mv "$file.part" "$file"
}

mkdir -p "$DOWNLOADS"

if [ ! -f "$STAMP" ] || [ "$(cat "$STAMP")" != "$WANT" ]; then
	wp_zip="$DOWNLOADS/wordpress-$WP_VERSION.zip"
	sqlite_zip="$DOWNLOADS/sqlite-database-integration.$SQLITE_VERSION.zip"
	gb_zip="$DOWNLOADS/generateblocks.$GB_VERSION.zip"

	fetch "https://downloads.wordpress.org/release/wordpress-$WP_VERSION.zip" "$wp_zip" "$WP_SHA256"
	official_sha1="$(curl -fsSL --retry 3 "https://downloads.wordpress.org/release/wordpress-$WP_VERSION.zip.sha1" | tr -d '[:space:]')"
	local_sha1="$(digest 1 "$wp_zip")"
	if [ "$official_sha1" != "$local_sha1" ]; then
		echo "WordPress $WP_VERSION does not match the SHA-1 WordPress.org publishes." >&2
		exit 1
	fi
	fetch "https://downloads.wordpress.org/plugin/sqlite-database-integration.$SQLITE_VERSION.zip" "$sqlite_zip" "$SQLITE_SHA256"
	fetch "https://downloads.wordpress.org/plugin/generateblocks.$GB_VERSION.zip" "$gb_zip" "$GB_SHA256"

	rm -rf "$WP_DIR"
	unzip -q "$wp_zip" -d "$CACHE"
	unzip -q "$sqlite_zip" -d "$WP_DIR/wp-content/plugins"
	unzip -q "$gb_zip" -d "$WP_DIR/wp-content/plugins"

	# The drop-in finds its implementation next to itself when the
	# placeholder path does not exist, so only the plugin slug is filled in.
	sed -e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
		"$WP_DIR/wp-content/plugins/sqlite-database-integration/db.copy" > "$WP_DIR/wp-content/db.php"

	echo "$WANT" > "$STAMP"
fi

# The plugin under test, under the slug it has on a real site.
ln -sfn "$ROOT" "$WP_DIR/wp-content/plugins/wp-mcp-connector-plus"

mkdir -p "$CACHE/db"

composer --working-dir="$HERE" install --no-interaction --no-progress --quiet

"$PHP" -r 'echo "Real-WordPress tests ready: WordPress ", $argv[1], ", SQLite integration ", $argv[2], ", GenerateBlocks ", $argv[3], ", PHP ", PHP_VERSION, "\n";' "$WP_VERSION" "$SQLITE_VERSION" "$GB_VERSION"
