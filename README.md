# WP MCP Connector Plus

**An MCP server for WordPress that lets AI agents operate a site the way an editor does.**

Most WordPress MCP servers hand an AI a `post_content` field and hope for
the best. It writes a wall of HTML, the block editor flags it as invalid,
and nobody can tell what changed. This one works on the Gutenberg **block
tree** instead: the agent sees your block kit with its real schemas and
nesting rules, edits pages by block path, and every write is validated
before it is saved.

Built on the [WordPress Abilities API](https://developer.wordpress.org/apis/abilities-api/)
(core since 6.9) and served over MCP through the official
[WordPress/mcp-adapter](https://github.com/WordPress/mcp-adapter).

```
Claude / any MCP client
        │  Model Context Protocol
        ▼
  mcp-adapter            ← transport, protocol, sessions (official, unmodified)
        ▼
  WP MCP Connector Plus  ← block tree, schemas, validation, guardrails
        ▼
  WordPress Abilities API (core)
```

---

## Why not just use an MCP server for WordPress?

| | Typical WordPress MCP server | WP MCP Connector Plus |
|---|---|---|
| Content format | `post_content` as an HTML string | Gutenberg block tree as JSON |
| Block awareness | none | full `block.json` schemas, types, enums, defaults |
| Nesting rules | none | `parent`, `ancestor`, `allowedBlocks` enforced |
| Partial edits | rewrite the whole page | patch operations by block path |
| Design system | ignored | `theme.json` palette and lockdown enforced |
| Before saving | write and pray | five-stage validation with a dry run |
| If it goes wrong | manual cleanup | WordPress revision, one-click rollback |
| Publishing | usually allowed | never — the agent role has no publish capability |

The agent never writes serialized block markup. It sends a JSON tree;
serialization happens server-side, in PHP, after validation. That single
decision removes the most common failure mode of AI-generated Gutenberg
content — subtly malformed markup that looks fine and breaks silently.

## Requirements

- WordPress **6.9 or newer** (the Abilities API lives in core from 6.9)
- PHP **8.1 or newer**
- Content made of Gutenberg blocks. Page-builder sites (Elementor, WPBakery)
  have no block tree for this to work on.

## Install

Download the ZIP from [Releases](https://github.com/dennisbuchwald/wp-mcp-connector-plus/releases)
and upload it under *Plugins → Add New → Upload Plugin*. Dependencies are
bundled; no Composer on the server required.

For development:

```bash
git clone https://github.com/dennisbuchwald/wp-mcp-connector-plus.git
cd wp-mcp-connector-plus
composer install
```

Activating the plugin creates the **AI Editor** role and an activity log
table. Then, under *Tools → MCP Connector*:

1. Create a user and give it the **AI Editor** role
2. Generate an application password in that user's profile
3. Copy the endpoint shown on the page

## Connect an AI client

The endpoint is `https://your-site.com/wp-json/wpmcp/v1/mcp`.

### Claude Code

```bash
claude mcp add --transport http wp https://your-site.com/wp-json/wpmcp/v1/mcp --header "Authorization: Basic $(printf '%s' 'ai-editor:APPLICATION PASSWORD' | base64)"
```

### Claude Desktop / claude.ai custom connector

Add a custom connector pointing at the same URL and supply the
`Authorization: Basic …` header. For clients that cannot send headers,
put [`@automattic/mcp-wordpress-remote`](https://www.npmjs.com/package/@automattic/mcp-wordpress-remote)
in front as a stdio proxy.

### Without MCP

Every ability is also reachable over REST at
`/wp-json/wp-abilities/v1/abilities/{name}/run`, because they are
registered with the core Abilities API. The MCP transport is swappable,
not load-bearing.

## The eight abilities

| Ability | What it does |
|---|---|
| `wpmcp/site-info` | Versions, post types, design tokens from `theme.json`. The first call of any session, so nothing has to be guessed. |
| `wpmcp/blocks-catalog` | The site's block kit: every block with its role (container / child / standalone), purpose, nesting rules and main variants — plus your editorial playbook if you ship one. |
| `wpmcp/blocks-describe` | Full attribute schema for named blocks, grouped into content / layout / behavior / legacy, with deprecated values flagged. |
| `wpmcp/content-list` | Find pages and posts, optionally filtered by which block they use. |
| `wpmcp/content-read` | A page as a block tree: `outline` (cheap architecture view), `subtree` (one section), or `full`. |
| `wpmcp/content-write` | Patch operations by block path (`insert`, `replace`, `remove`, `set_attrs`, `move`) or a full tree replacement. Dry run by default. |
| `wpmcp/content-duplicate` | Copy a page as a draft, including taxonomies and meta. |
| `wpmcp/content-preview` | Server-rendered HTML, heading outline, and a signed preview URL that works without a login. |

### Context budget

Measured against a real 47-block design system with 975 attributes:
the whole catalogue is **11.3 KB (~2,900 tokens)**, and the heaviest
single block detail (77 attributes) is 12.7 KB. An agent can hold the
entire kit in context and still have room to work.

## How a write is validated

Every write — dry run and real — passes the same five stages:

1. **Existence** — is the block actually registered on this site?
2. **Schema** — types, enums, no invented attributes
3. **Structure** — `parent`, `ancestor` and `allowedBlocks` are enforced
4. **Design contract** — no literal colours or sizes where `theme.json` locks them
5. **Round trip and render** — serialize, re-parse, compare, then run
   `do_blocks()` behind an error handler as a smoke test

Errors name the exact block path and reason, so the agent can fix a
specific node instead of retrying the whole page.

## Safety model

Write access to a live website is the sensitive part, so the defaults are
conservative:

- **The agent cannot publish.** The role has no `publish_*` capability.
  New pages and duplicates are drafts until a human publishes them —
  enforced by WordPress, not by plugin logic.
- **No deleting, no uploads, no settings access.**
- **Published content is read-only** until you switch live editing on.
- **Application passwords are enabled for the agent role only**, and left
  untouched for everyone else.
- **Dry run is the default.** Writing requires `dry_run: false`.
- **Every write creates a revision** — rollback is one click.
- **Slug, status and post type are never touched**, so URLs stay put.
- **Everything is logged** under *Tools → MCP Connector*.
- **Kill switch:** `define( 'WPMCP_DISABLE', true );` in `wp-config.php`
  stops the connector without deactivating the plugin.

## Performance

Content logic loads only in REST and WP-CLI context; the updater only in
admin and cron. The single front-end code path is one `isset()` on a query
parameter for signed preview links. Normal page views cost nothing.

## Adapting it to your block kit

The plugin reads everything it can from the block registry, so a
well-described kit needs no configuration. Two filters cover the rest:

```php
// Containers that accept arbitrary children. Closed containers are
// detected automatically from their allowedBlocks declaration.
add_filter( 'wpmcp_open_containers', function ( $blocks ) {
    $blocks[] = 'acme/section';
    return $blocks;
} );

// Blocks that should never be offered to an agent.
add_filter( 'wpmcp_hidden_blocks', function ( $blocks ) {
    $blocks[] = 'acme/internal-widget';
    return $blocks;
} );
```

**Get the most out of it** by describing your blocks properly in
`block.json` — the plugin surfaces all of it:

- `description` on every attribute, so an agent knows what `bgShapeColor` does
- `allowedBlocks` on containers, so nesting rules are readable server-side
  (the editor's `useInnerBlocksProps` still wins at runtime, so adding this
  changes no behaviour)
- `deprecatedEnum` — a custom key this plugin understands — to mark legacy
  enum values that must stay valid for stored content but should not be
  chosen for new content

### Editorial playbook

Knowledge that no schema can carry — page dramaturgy, when to use which
block, tone of voice, house rules — goes in a markdown file that ships
with `blocks-catalog`:

```
wp-content/themes/your-theme/docs/ai-playbook.md
```

## Updates

The plugin checks GitHub releases and reports updates in the WordPress
admin, via [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker).

For a private repository, add a fine-grained token with read access:

```php
define( 'WPMCP_GITHUB_TOKEN', 'github_pat_...' );
```

Releasing: bump the version in the plugin header **and** `WPMCP_VERSION`,
tag it, publish a GitHub release. The header version is what sites compare
against — a forgotten bump means no update appears.

`vendor/` is committed on purpose: WordPress installs the release ZIP as-is
and never runs Composer.

## Tests

```bash
tests/fetch-shim.sh                              # once: fetch the real WP block parser
php tests/run-tests.php                          # 97 unit tests
php tests/run-integration.php /path/to/your-theme-or-core
```

The suite runs without a WordPress install but uses the **real** WordPress
block parser and serializer, so a passing round-trip here means the same
thing it would on a live site. The integration test loads real `block.json`
files and checks the catalogue, detail view and validator against them.

## Status

**v0.1.0.** Built, tested, and not yet proven on a production site. The
`mcp-adapter` dependency is pinned because it is still 0.x with breaking
changes between minor versions; if a different version is loaded by another
plugin, the MCP server refuses to start and says so rather than failing
subtly at request time.

## Licence

GPL-2.0-or-later. Built by [dbw media](https://dbw-media.de).
