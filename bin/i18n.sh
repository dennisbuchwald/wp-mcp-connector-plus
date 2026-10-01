#!/usr/bin/env bash
# Rebuild the translation template and the shipped translations.
#
# languages/wp-mcp-connector-plus.pot is extracted from the PHP sources
# with xgettext, using the same keywords WordPress' own tooling knows.
# Every languages/*.po is then merged with it (new strings arrive empty,
# vanished ones become obsolete) and compiled to its .mo.
#
# Needs GNU gettext (xgettext, msgmerge, msgfmt): `brew install gettext`
# on macOS, `apt install gettext` on Debian. WP-CLI's `wp i18n make-pot`
# produces an equivalent template if it is at hand instead.
#
# Usage: bash bin/i18n.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

POT=languages/wp-mcp-connector-plus.pot

xgettext --language=PHP --from-code=UTF-8 \
	--keyword=__ --keyword=_e --keyword=esc_html__ --keyword=esc_html_e \
	--keyword=esc_attr__ --keyword=esc_attr_e --keyword=_x:1,2c \
	--keyword=_n:1,2 --keyword=_nx:1,2,4c --keyword=esc_html_x:1,2c \
	--keyword=esc_attr_x:1,2c --keyword=_n_noop:1,2 \
	--add-comments=translators: \
	--package-name="WP MCP Connector Plus" \
	--msgid-bugs-address="https://github.com/dennisbuchwald/wp-mcp-connector-plus/issues" \
	--sort-by-file --no-wrap \
	-o "$POT" \
	wp-mcp-connector-plus.php uninstall.php includes/*.php

# A template, not a translation: no creation date, so an unchanged source
# gives an unchanged file and an empty diff.
sed -i.bak -e '/^"POT-Creation-Date:/d' \
	-e 's/^# SOME DESCRIPTIVE TITLE\./# WP MCP Connector Plus/' \
	-e "s/^# Copyright (C) YEAR THE PACKAGE'S COPYRIGHT HOLDER/# Copyright (C) Dennis Buchwald/" \
	-e 's/same license as the WP MCP Connector Plus package\./same licence as the plugin, GPL-2.0-or-later./' \
	-e '/^# FIRST AUTHOR/d' \
	-e 's/^"Content-Type: text\/plain; charset=CHARSET\\n"/"Content-Type: text\/plain; charset=UTF-8\\n"/' "$POT"
rm -f "$POT.bak"

for po in languages/*.po; do
	[ -e "$po" ] || continue
	msgmerge --quiet --update --backup=none --no-wrap --no-fuzzy-matching "$po" "$POT"
	msgfmt --check --statistics -o "${po%.po}.mo" "$po"
done
