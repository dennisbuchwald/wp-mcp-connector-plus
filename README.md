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
| Publishing | usually allowed | only inside a time-boxed work session the site owner opens — the role itself has no publish capability |

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
| Abilities registered | every one of them made it into the registry |
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
that on, publishing and uploading happen only inside a work session you
open, and everything lands in the activity log.

## Troubleshooting

**Connected, but the agent says there are no tools.** Check the status
table under *Tools → MCP Connector*. If "Abilities registered" is red,
the MCP server is running but has nothing to offer.

**"This content contains dynamic data, which your account doesn't have
permission to save."** That message comes from a block library, and the
gate behind it is the `unfiltered_html` capability, which the agent role
does not have. Set *Dynamic data* to allowed under *Tools → MCP
Connector*; see the safety model for what that does and does not grant. If it
is already set to allowed and the message persists, check
`site-info` → `capabilities.dynamicData`: `effective: false` means
`DISALLOW_UNFILTERED_HTML` is defined in `wp-config.php`, which turns the
capability into a `do_not_allow` for every account, administrators
included. Nothing in this plugin can reach past that.
Editing the database with WP-CLI gets past it because WP-CLI runs without
a user, so none of these checks happen at all — a way around, not a fix.

**A fatal error naming `vendor/autoload_packages.php` in another plugin**
(Jetpack, WooCommerce, Germanized). That file belongs to the Jetpack
autoloader, which this plugin does not use — but the mcp-adapter depends on
the package, and up to 0.13.0 its manifests were shipped in
`vendor/composer/`. They announce an autoloader without providing one, so
every plugin sharing that mechanism picked this one as the newest and
required a file that was never generated. Fixed in 0.13.1: update, or
delete the folder over FTP to bring the site back first.

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

## The abilities

Seven are always present. The three write abilities exist only above the
read-only access level — at that level they are not registered, so the
agent never sees them.

| Ability | What it does |
|---|---|
| `wpmcp/site-info` | Versions, post types, design tokens from `theme.json`. The first call of any session, so nothing has to be guessed. |
| `wpmcp/blocks-catalog` | The site's block kit: every block with its role (container / child / standalone), purpose, nesting rules and main variants — plus your editorial playbook if you ship one. |
| `wpmcp/blocks-describe` | Attribute schema for named blocks, grouped into content / layout / behavior / legacy, with deprecated values flagged. Compact by default; `detail: "full"` adds the prose and an example. |
| `wpmcp/content-list` | Find pages and posts, optionally filtered by which block they use. |
| `wpmcp/content-read` | A page as a block tree: `outline` (cheap architecture view), `subtree` (one or several sections, each reporting its real path), or `full`. |
| `wpmcp/content-write` | *Write levels only.* Patch operations by block path (`insert`, `replace`, `remove`, `set_attrs`, `patch_html`, `move`), a full tree replacement, or SEO meta fields — alone or together. Dry run by default. |
| `wpmcp/content-create` | *Write levels only.* A new page with its title, slug, parent and status — and its content in the same call. A draft, unless published during a work session. The dry run validates the tree and meta exactly as the real call will. |
| `wpmcp/content-batch` | *Write levels only.* The same change on up to 20 posts in one call. Every item is dry-run first; nothing is saved unless all pass. |
| `wpmcp/content-duplicate` | *Write levels only.* Copy a page as a draft, including taxonomies and meta. |
| `wpmcp/content-preview` | Server-rendered HTML, heading outline, and a signed preview URL that works without a login. Long pages come back in windows; the answer names its own size and where to continue. |
| `wpmcp/content-revisions` | The saved history of a page: ids, timestamps, authors, block counts. |
| `wpmcp/content-restore` | *Write levels only.* Undo — put a page back to one of its own revisions. |
| `wpmcp/content-search` | Find a string or pattern across the whole site, with the raw text around every hit. |
| `wpmcp/content-fetch-live` | The public URL over HTTP: what a visitor receives, cache headers and a parsed `head` (title, description, canonical, robots, Open Graph) included. `contains` answers "is my change on the page?" in a few hundred bytes; `body_only` drops header, footer, styles and scripts. |
| `wpmcp/media-list` | Attachments with alt text, title and every post that embeds them. `missing_alt` narrows it to the ones with none. |
| `wpmcp/media-read` | One attachment in the same shape. |
| `wpmcp/media-update` | *Write levels only.* Sets alt text or title. No upload, no delete, no file replacement. |

### Deliberately not covered

The agent works on the block tree, the SEO fields and the two text fields
of an attachment. It cannot upload or delete media, replace a file, set a
featured image, change categories or tags, or edit a post title after
creation. A generated draft is therefore complete as *content* and as
metadata, but a human still decides what gets published.

**Slug, parent and status** follow the page rather than the field. On a
page that has never been published, all three can be set — that is what
makes a page the connector just created finishable in one call. On a
**published** page all three stay put: its slug is what every link to it
points at, its parent is part of that URL, and taking it back to draft
removes it from the site. Those belong in the editor, where the redirect
is yours to set up.

No status publishes outside a work session. Post type is never writable at all.

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

Two things happen around the write itself, because validation alone was
not enough:

- **Before:** pass `expected_modified` (returned by `content-read`) and the
  write is refused if someone edited the page in the meantime, rather than
  silently overwriting them. A successful write returns the new `modified`
  value, so a sequence of writes needs no read between them just to fetch
  it.
- **Around it:** accounts without `unfiltered_html` — which the agent
  deliberately is — have their content run through `wp_kses_post` on save,
  which removes scripts and iframes. Writing such markup is therefore an
  error, reported in the dry run before anything is saved. Markup of that
  kind already on the page is *preserved*: WordPress saves the whole page
  on every write, so without this, editing one block would destroy the
  structured data in another. The agent can add none of it and can destroy
  none of it. The one exception is **structured data**: a
  `<script type="application/ld+json">` holding valid JSON is not code, and
  is accepted. A `<` inside the data is re-encoded as `\u003C`, which closes
  the only way out of the tag; another type, an extra attribute or content
  that is not JSON is treated as the script it is.
- **After:** the stored content is compared against what was sent, and any
  remaining difference is reported. The same check runs after duplicating.

**SEO fields** go through the same tool. `meta` can travel with `ops` or
on its own — correcting a canonical URL is not a reason to touch the block
tree, and a meta-only write leaves the content and its modified date
alone:

```json
{"post_id": 2816, "meta": {"rank_math_canonical_url": "https://example.com/x/"}, "dry_run": false}
```

Only SEO keys are accepted; anything else is refused by name. And unlike
content, **meta has no revision behind it** — WordPress does not version
post meta. The previous value is therefore in the dry run, in the
response, and in the activity log, because nothing else will hold it.

**Plugin settings on their own post type.** Some post types are nothing
but their plugin's settings — a GeneratePress element without its type,
location and display conditions saves cleanly and shows nowhere. For
those, the plugin's own meta prefix is readable and writable, arrays
included, on that post type only (`gp_elements`: `_generate_*`; more via
the `wpmcp_post_type_meta_prefixes` filter). Read an existing element
first with `include_meta` and copy its keys: they differ between plugin
versions, and a guessed key produces an element that displays nowhere.

Two things stay out regardless. No meta key that could make a post run
code — GeneratePress's "Execute PHP" switch among them — is ever written.
And an element that already has that switch on is not writable at all,
because its content is PHP evaluated on the server, which the markup
guard cannot see.

**Changing text inside a block** is `patch_html`, not `replace`. `replace`
demands the whole block back, which on a long legal page means retyping
tens of thousands of characters to correct a phone number — and every
character retyped is a character that can come back wrong. `patch_html`
names the text instead:

```json
{"op": "patch_html", "path": "4.2", "find": "07131 123456", "replace": "+49 7131 123456"}
```

The anchor must occur exactly once in that block. None means the caller is
working from a stale reading; several mean it cannot know which one it is
about to change. Both are refused rather than guessed at — and
`content-search` returns the surrounding text verbatim, which is what makes
a unique anchor easy to pick.

Reading a page also reports **structured data that lost its wrapper** —
JSON-LD sitting in the markup with no `<script>` around it, which renders
as a wall of text to visitors. It is the fingerprint of a script stripped
by an earlier unfiltered save, and otherwise only ever gets noticed by
someone looking at the page.

## Safety model

Write access to a live website is the sensitive part, so what the agent
may do is a setting, not a fixed assumption. Under *Tools → MCP
Connector*:

| Level | What the agent can do |
|---|---|
| **Read only** | Look at the site and explain it. The write tools do not exist. |
| **Drafts** (default) | Create pages, duplicate existing ones, edit drafts. Published pages are read-only. |
| **Drafts and published pages** | As above, plus editing published pages directly. |

The mechanism matters more than the list: **anything the level does not
allow is never registered**, so a disallowed tool is absent from the MCP
tool list rather than present and refusing. The agent's role capabilities
follow the same setting, so WordPress enforces the same boundary a second
time, independently of this plugin's own logic.

Synced patterns are a separate setting (hidden / readable / editable),
because editing one changes every page that embeds it at once and a
pattern has no draft state. When one is written, the dry run reports how
many pieces of content are affected.

**A work session** opens everything for a set window — one, four or eight
hours — and closes itself. While it runs, published pages are editable,
synced patterns are writable and dynamic data is allowed; when it ends,
the saved settings are what the site falls back to, and the role's
capabilities are pulled back with them.

It exists because the wide settings are the ones people switch on for an
afternoon and never switch off, which leaves a site permanently on the
widest setting — exactly what having settings was meant to prevent. The
closing is a timestamp, not something anyone has to remember.

A session also brings the site's own building blocks into scope —
headers, templates, field groups, whatever else is registered — so an hour
of work does not begin with thirty ticks. **Except** the post types
holding other people's data: orders, subscriptions, form entries,
bookings. No length of window turns reading a customer's address into a
side effect of editing a page, so those stay a tick somebody makes
deliberately. A tick already made survives the window either way.

**Publishing and uploading images happen only inside a session.** Opening
one is the human deciding that what gets built in it may go live — once,
instead of clicking publish twenty-two times afterwards. Outside a session
no status publishes at any access level, and the upload tool does not
exist. Inside one, the capability is granted for a single save or upload
and never lands on the role. A page created with `status: publish` goes
live only after its content is written; rejected content keeps it a draft.
A live page is still never taken back to draft.

Uploads are judged by their bytes, not their name: JPEG, PNG and WebP
only, SVG refused because it can carry script, anything with a PHP
opening tag refused, a size limit (`wpmcp_max_upload_bytes`, 8 MB), and
the extension replaced by the detected type. Alt text is required, or
`decorative: true` for an image with no meaning of its own. URL import is
deliberately absent: the server fetching an address the agent chooses is
a way into the host's internal network.

**Additional post types** is a fourth setting, empty by default. It lists
every post type the site has that is not already in scope — the theme's
site-wide building blocks in particular. See *Adapting it to your block
kit* for what that covers.

**Dynamic data** is a fifth setting, off by default. Some block libraries
refuse to save a page holding dynamic data unless the account has
`unfiltered_html` — the capability that permits storing arbitrary HTML and
JavaScript. That would be the widest permission in a role that
deliberately cannot publish, delete, upload or change settings, so it is
never given to the role. Switched on, it is granted around a single
`wp_update_post` and removed again in a `finally`, and the check below
takes the place of the filtering WordPress then skips:

> a write is refused if it **newly introduces** a script tag, an inline
> event handler (`onclick=`, `onerror=` …), a `javascript:` or
> `data:text/html` URL, or an iframe, object or embed — naming the element
> and the block it sits in. Only additions: a page that already carries a
> video embed stays editable.

Every save that used the elevated capability says so in its response and
in the activity log.

Two things put `unfiltered_html` out of reach whatever this setting says:
`DISALLOW_UNFILTERED_HTML` in `wp-config.php`, and multisite for anyone
who is not a super admin. Both are deliberate decisions by whoever set the
site up, and the plugin does not work around either — it reports them, on
the settings screen and in `site-info`, and refuses the save before
attempting it.

Regardless of level:

- **The agent cannot publish on its own.** No level grants `publish_*` to
  the role. Publishing is possible only inside a work session the site
  owner opens, for one save at a time; outside one, new pages and
  duplicates stay drafts until a human publishes them.
- **No deleting, no uploads, no settings access**, at any level.
- **Dry run is the default.** Writing requires `dry_run: false`.
- **Every write creates a revision** — rollback is one click.

**The agent cannot write scripts.** Markup that needs `unfiltered_html` —
inline scripts, iframes, embeds — is refused, and refused by identity
rather than by counting: swapping a page's own JSON-LD for a script of the
agent's making is recognised as new even though the number of scripts on
the page never changed. Markup that was already stored is left alone, so
editing one block never destroys the schema in another.

That also means the connector cannot repair such content once it is lost.
For that one job, a developer can open the door and close it again:

```php
add_filter( 'wpmcp_allow_filtered_markup', '__return_true' );
```

It is deliberately not a checkbox in the admin — a checkbox invites being
left on. While it is open, every write that uses it says so in its result.

One page is a special case. WordPress guards the page designated under
*Settings → Privacy* with `manage_privacy_options`, a meta capability that
maps to `manage_options` — full site administration. Granting that so an
agent can fix a paragraph would hand over the whole site, so the connector
drops the administrator requirement for exactly that one check instead:
**editing** (never deleting) **that one page**, at the *Drafts and
published pages* level only. Every other requirement stays in force, and
no level ever gains an administration capability. To keep the page out of
reach entirely:

```php
add_filter( 'wpmcp_allow_privacy_policy_edit', '__return_false' );
```
- **Slug, status and post type are never touched**, so URLs stay put.
- **Everything is logged** under *Tools → MCP Connector*.
- **Kill switch:** `define( 'WPMCP_DISABLE', true );` in `wp-config.php`
  stops the connector without deactivating the plugin.

Do not work around a permission problem by giving the agent user a
built-in role such as Editor. Editor can publish and delete, which is
exactly what every level here withholds. If the level and the granted
capabilities disagree, the status table says so and saving the settings
again repairs it.

To fix the level from code instead of the database, for instance on a
production site that should never move past read-only:

```php
define( 'WPMCP_ACCESS_LEVEL', 'read' );   // read | draft | full
define( 'WPMCP_PATTERN_ACCESS', 'none' ); // none | read | write
```

Both then show as locked in the admin.

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

Post types are a setting rather than a filter, because it is a decision
per site rather than per project. Public post types and pages are in scope
on their own; everything else a site has — a theme's headers, footers,
hooks and content templates among them — is listed under *Additional post
types* on the settings screen, unticked. Ticking one is a real decision:
those apply to every page at once, the way a synced pattern does.

The filter is still there for anything a project settles in code:

```php
add_filter( 'wpmcp_allowed_post_types', function ( $types ) {
    $types[] = 'gp_elements';   // GeneratePress Elements
    return $types;
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
add an entry to [CHANGELOG.md](CHANGELOG.md), tag it, push. A tag is
enough — the update checker uses the latest release and falls back to the
highest version tag, so there is nothing to click on GitHub. The header version is what sites compare
against — a forgotten bump means no update appears.

`vendor/` is committed on purpose: WordPress installs the release ZIP as-is
and never runs Composer.

## Tests

```bash
tests/fetch-shim.sh                              # once: fetch the real WP block parser
php tests/run-tests.php                          # unit tests: round trips, patches, validation
php tests/register-abilities.php                 # abilities actually register
php tests/verify-stored.php                      # saved content matches what was sent
php tests/kses-impact.php                        # existing markup survives an unrelated edit
php tests/duplicate-preserves.php                # a copy is a copy
php tests/search.php                             # site-wide search and its raw context
php tests/privacy-page.php                       # the privacy page exception stays narrow
php tests/patch-html.php                         # editing text inside a block, and when it refuses
php tests/long-output.php                        # long pages are windowed, never quietly halved
php tests/save-refusal.php                       # a refusal from elsewhere says where it came from
php tests/meta-write.php                         # SEO fields: whitelist, diff, and the old value
php tests/media.php                              # alt text, and what an image is used on
php tests/dynamic-data.php                       # the guard that replaces kses on an elevated save
php tests/large-payload.php                      # a 32 KB legal text in one call
php tests/head-and-plugins.php                   # does the meta title reach the page?
php tests/placement.php                          # slug, parent and status: where the line runs
php tests/shipped-files.php                      # what the vendor folder announces to other plugins
php tests/work-session.php                       # the window closes itself, and what it never opens
php tests/media-upload.php                       # an image judged by its bytes, only in a session
php tests/jsonld.php                             # structured data in, scripts still out
php tests/create-and-batch.php                   # a dry run that looks, a batch that is all or nothing
php tests/render-admin.php                       # admin page renders in every state
php tests/run-integration.php /path/to/your-theme-or-core
```

The suite runs without a WordPress install but uses the **real** WordPress
block parser and serializer, so a passing round-trip here means the same
thing it would on a live site.

Each suite exists because of a specific failure:

- **run-tests** — round trips, patch operations, validation. The core logic.
- **register-abilities** — loads the plugin and fires the ability hooks in
  WordPress order against an API stub that rejects unknown categories the
  way the real one does. Two separate bugs once made every registration
  fail silently, both invisible without a live site.
- **render-admin** — renders the admin page in every state including the
  broken ones. The one surface where a mistake otherwise only appears when
  a human opens the page.
- **verify-stored** — WordPress rewrites content from accounts without
  `unfiltered_html`. A JSON-LD block once vanished from a real page with
  nothing in any log; this pins down that such a change is reported.
- **duplicate-preserves** — duplicating once went through an unfiltered
  save path and silently dropped a page's JSON-LD schema before any edit
  happened. A copy has no before-state, so the preservation rule the write
  path uses cannot apply here.
- **privacy-page** — the narrow exception for the designated privacy
  policy page, pinned from every side: other pages, deleting, other users,
  lower access levels, multisite, and the off switch.
- **save-refusal** — a plugin refused a save with a message nobody
  recognised, right after a dry run had said yes. It read as a connector
  bug and cost an afternoon, so the error now says where it came from.
- **meta-write** — SEO meta is the one write with no revision behind it.
  These pin the whitelist and that the previous value reaches the log,
  because otherwise there is no way back.
- **media** — alt text lives on the attachment, not on the block, so an
  audit of the markup alone can count images and explain none of them.
- **dynamic-data** — when the content filter is switched off for a save,
  this guard is the only thing between an agent and stored JavaScript.
  Every vector it knows and every shape it must let through is pinned.
- **large-payload** — a 32 KB privacy policy could not be written in one
  call, and the error blamed the first operation. These pin the shapes a
  big argument arrives in, and that a mangled one is refused rather than
  quietly repaired.
- **head-and-plugins** — writing a meta title and reading it back proves
  only that it was stored. Whether the SEO plugin puts it in the head is
  a different question, and only the delivered page answers it.
- **placement** — the line between a draft, which the agent may move and
  rename, and a live page, whose address other people's links depend on.
  Every way past it, including the ones that would publish.
- **shipped-files** — a shop site went down on activation, from a fatal
  inside another plugin. Half a Jetpack autoloader was being shipped: the
  half that announces one, without the half anyone can load.
- **work-session** — mostly about the closing, because the opening is the
  easy half: that the level falls back on its own, that the capabilities
  follow, that a second cleanup does nothing, and that no window ever
  reaches publishing or ticks a post type.
- **jsonld** — every SEO article carries structured data, and it could not
  be written. Checks that it now can, byte-identical when it is safe, and
  that each way of smuggling a script inside it is still refused.
- **create-and-batch** — a 75-block dry run said "ok" without looking at
  the tree. Also that a batch with one bad item saves nothing.
- **run-integration** — loads real `block.json` files and checks the
  catalogue, detail view and validator against them.

## Status

See [CHANGELOG.md](CHANGELOG.md) for what changed and why.

**v0.18.0.** In daily use on customer sites, reading and writing. Whole
pages have been built through it — created, filled, given their SEO fields
and put in the right place in the tree — and long legal texts have been
corrected across eighteen pages at once.

Every release since 0.4.0 came out of a real job going wrong, which is why
the changelog reads the way it does. The things that cost the most time:

- **Other plugins bundle the same library.** Rank Math SEO ships
  `mcp-adapter`, and whichever copy loads first wins. Compatibility is
  therefore decided by the interface the loaded copy exposes, not by its
  version string — verified against 0.4.1, 0.5.0 and 0.6.1, which are
  identical in everything this plugin touches.
- **Registration can fail silently.** The result is a server that
  connects, completes the handshake and offers no tools, with nothing in
  any log. Hence the status table and the registration test.
- **Synced patterns were invisible**, which on a pattern-heavy site meant
  the agent was guessing at half the page.
- **WordPress rewrites what it stores.** Duplicating a page silently
  destroyed its JSON-LD schema, and the validation pipeline had no way of
  knowing: it checks what is about to be sent, not what arrived. Hence the
  comparison after every write.
- **A refusal that explains nothing gets worked around.** A block library
  refused every save on pages holding dynamic data, with a message naming
  neither the cause nor the fix. Three separate sessions read it as content
  filtering and went around the API through the database, where none of
  these checks exist. Errors now say where they came from and what settles
  them.
- **Half a dependency is worse than none.** The adapter pulls in the
  Jetpack autoloader, and shipping its manifests without the file they
  point at took down a WooCommerce site on activation — in someone else's
  plugin, on every request. Hence a test over what the package announces
  to its neighbours.

## Licence

GPL-2.0-or-later. Built by [dbw media](https://dbw-media.de).
