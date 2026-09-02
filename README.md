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

## Setup

**1. Install.** Download the ZIP from
[Releases](https://github.com/dennisbuchwald/wp-mcp-connector-plus/releases)
and upload it under *Plugins → Add New → Upload Plugin*. Dependencies are
bundled; no Composer on the server required.

**2. Open *Tools → MCP Connector*.** The status table tells you whether
everything needed is actually in place:

| Step | What it checks |
|---|---|
| WordPress with the Abilities API | core 6.9+, otherwise nothing can register |
| Abilities registered | all eight made it into the registry |
| MCP transport | a usable mcp-adapter, and the endpoint URL |
| Agent user and credential | the account your agent will use |

Green all the way down means you are ready. A red row names the problem
rather than leaving you with a server that connects and does nothing.

**3. Click "Generate connection".** This creates the agent user if it does
not exist, generates an application password, and hands you a ready-made
command plus a config file — no copying credentials by hand, no base64 in
your shell history.

The password is shown **once**. Lose it and you generate a new one; there
is nothing to recover.

For development instead of a release ZIP:

```bash
git clone https://github.com/dennisbuchwald/wp-mcp-connector-plus.git
cd wp-mcp-connector-plus
composer install
```

## Connecting a client

The setup screen gives you both forms. The endpoint is always
`https://your-site.com/wp-json/wpmcp/v1/mcp`.

### Claude Code, isolated (recommended)

Save the JSON from the setup screen as `~/.claude/mcp-your-site.json` —
outside any repository, so credentials never land in git — and start
Claude Code with only this site connected:

```bash
claude --mcp-config ~/.claude/mcp-your-site.json --strict-mcp-config
```

`--strict-mcp-config` ignores every other MCP server you have configured.
Two reasons this is the better default:

- The agent cannot wander into an unrelated tool while editing a website.
- Fewer tool definitions means more context left for the actual work.

Worth an alias per site:

```bash
alias wp-acme='claude --mcp-config ~/.claude/mcp-acme.json --strict-mcp-config'
```

With write access, working on exactly one site at a time is the sane
operating mode anyway.

### Claude Code, added to your usual set

```bash
claude mcp add --transport http your-site https://your-site.com/wp-json/wpmcp/v1/mcp --header "Authorization: Basic <from the setup screen>"
```

### Claude Desktop / claude.ai custom connector

Point a custom connector at the same URL and supply the
`Authorization: Basic …` header. For clients that cannot send headers, put
[`@automattic/mcp-wordpress-remote`](https://www.npmjs.com/package/@automattic/mcp-wordpress-remote)
in front as a stdio proxy.

### Without MCP

Every ability is also reachable over REST at
`/wp-json/wp-abilities/v1/abilities/{name}/run`, because they are
registered with the core Abilities API. The MCP transport is swappable,
not load-bearing.

## Working with it

You do not call tools yourself — you describe what you want, and the tool
descriptions steer the agent through a sensible order: look at the site,
learn the kit, read a reference page, then build.

Start read-only to see what it understands:

> What kind of website is this, and how is the front page built?

Then the kit:

> Explain this site's block kit. Which block would you use for what?

Then real work. Duplicating beats building from scratch, because an
existing page already carries the site's structure and tone:

> I need a new service page for X. Look at how the existing service pages
> are built, duplicate the closest one and adapt it. Show me the dry run
> first.

What happens: the agent reads the catalogue and playbook, looks at a
reference page or two, duplicates (creating a draft), validates as a dry
run, writes, and fetches a preview URL to check its own work.

Throughout: drafts only, published pages are read-only unless you switch
that on, publishing is never possible, and everything lands in the
activity log.

## Troubleshooting

**Connected, but the agent says there are no tools.** Check the status
table under *Tools → MCP Connector*. If "Abilities registered" is red,
the MCP server is running but has nothing to offer.

**"Another plugin loaded mcp-adapter X".** The adapter is a library that
other plugins bundle too — Rank Math SEO ships one, for instance — and
whichever copy loads first wins. This plugin checks whether the loaded
copy exposes the interface it needs and runs with it if so; the notice
only appears when it genuinely cannot. To see every copy on a site:

```bash
wp eval 'foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(WP_PLUGIN_DIR)) as $f) { if ($f->getFilename() === "McpAdapter.php") { preg_match("/VERSION\s*=\s*.([0-9.]+)/", file_get_contents($f->getPathname()), $m); echo str_replace(WP_PLUGIN_DIR . "/", "", $f->getPathname()), " -> ", $m[1] ?? "?", "\n"; } }'
```

**No "Application Passwords" section on the user profile.** Hardened
setups and security plugins often disable them globally. This plugin
re-enables them for the agent role only, and leaves every other user
exactly as your site configured them. If the section is still missing,
something else is filtering it — check for a security plugin.

**Checking authentication by hand:**

```bash
curl -u 'agent-user:application password' https://your-site.com/wp-json/wp-abilities/v1/abilities
```

If that returns a list containing `wpmcp/…` entries, the connection works
and the problem is on the client side.

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
php tests/register-abilities.php                 # abilities actually register
php tests/render-admin.php                       # admin page renders in every state
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
