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
- Content made of Gutenberg blocks, or classic Elementor pages (Elementor
  4.3 or newer, containers or sections; see [Elementor](#elementor)). Other
  page builders (WPBakery, Divi) have no tree for this to work on.

## Setup

**1. Install.** Download `wp-mcp-connector-plus.zip` from
[Releases](https://github.com/dennisbuchwald/wp-mcp-connector-plus/releases)
and upload it under *Plugins → Add New → Upload Plugin*. Dependencies are
bundled; no Composer on the server required.

Take the attached `wp-mcp-connector-plus.zip`, not the "Source code" ZIP
below it: the source archive's folder carries the version in its name, so
WordPress installs the plugin under that name and the next update arrives
as a second copy.

If the upload screen answers **403 Forbidden**, that is the server's web
application firewall, not WordPress. ModSecurity reads what is uploaded,
and the test suite in this repository holds real attack strings as
fixtures — its whole job is to prove the connector refuses them. The
release ZIP is built without them for exactly this reason. Should a
firewall still object, unpack the ZIP into `wp-content/plugins/` over
SFTP or the hosting panel's file manager; the folder must be named
`wp-mcp-connector-plus`.

**2. Open *Tools → MCP Connector*.** The page has three tabs, each with a
link of its own (`&tab=connection`, `access`, `activity`):
*Connection* (status and setup), *Access* (work session and settings) and
*Activity* (the log). The status table on *Connection* tells you whether
everything needed is actually in place:

| Step | What it checks |
|---|---|
| WordPress with the Abilities API | core 6.9+, otherwise nothing can register |
| Abilities registered | every one of them made it into the registry |
| MCP transport | a usable mcp-adapter, and the endpoint URL |
| Agent user and credential | the account your agent will use |
| Permissions in step | the agent role stores reading only; *Repair now* resets it if not |
| Application passwords | HTTPS, and whether the site offers them itself |
| Last connection | when the agent last called, and which tool |

Green all the way down means you are ready. A red row names the problem
rather than leaving you with a server that connects and does nothing.

**3. Click "Generate connection".** This creates the agent user if it does
not exist, generates an application password, and hands you a ready-made
command plus a config file — no copying credentials by hand, no base64 in
your shell history.

The password is shown **once**: the form posts, the page redirects, and
the result waits for exactly that one page view (60 seconds at most, for
the administrator who asked). Reloading never shows it again and never
creates a second one. Lose it and you generate a new one; there is
nothing to recover. Password and header stay behind *Show*; *Copy* works
without revealing them.

While a work session runs, the admin bar shows "MCP session: 2 h 14 min"
on every screen, front end included, with *Close session now* under it.

The *Activity* tab lists every call, 25 per page, filterable by tool,
result (saved, dry run, rejected), user and post ID, with times in the
site's timezone and a *Compare revisions* link for every save. Entries
older than 90 days are deleted once a day (WP-Cron event
`wpmcp_prune_log`); the number is set on the *Access* tab, 0 keeps
everything, and the `wpmcp_log_retention_days` filter overrides it.

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

The abilities are registered with the core Abilities API, so an
administrator can also run them over REST at
`/wp-json/wp-abilities/v1/abilities/{name}/run`. The agent account itself
cannot: it reaches `/wpmcp/v1/mcp` and nothing else, neither the rest of
the REST API nor XML-RPC, so nothing it does bypasses the connector.

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

**Raised the access level, but the agent still offers only read tools.**
A client reads the tool list once, when it connects, so a level raised
during a session reaches it only after a reconnect (Claude Code: `/mcp`,
then reconnect). `site-info` already reports the new level and its write
tools, and its `capabilities.explains` says this too. The server cannot
tell the client itself: the bundled mcp-adapter advertises
`tools.listChanged: false` and has no stream (GET answers 405) to send a
`notifications/tools/list_changed` over.

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
exactly as your site configured them. It does so only over HTTPS (or in
an environment declared `local`), like WordPress itself: the status table
says so when a site runs on plain HTTP. The agent account cannot manage
its own application passwords; create and revoke them as an administrator.

**Checking authentication by hand:**

```bash
curl -u 'agent-user:application password' -X POST https://your-site.com/wp-json/wpmcp/v1/mcp \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl","version":"1"}}}'
```

If that returns a `serverInfo` naming WP MCP Connector Plus, the
connection works and the problem is on the client side. Any other route
answers the agent account with a 403 on purpose.

## The abilities

Eleven read abilities are always present. The seven write abilities exist
only above the read-only access level; at that level they are not
registered, so the agent never sees them. The list depends on the level
the site owner set and on nothing else: a work session widens what the
write tools may do (publish, upload, edit published pages), never which
tools there are, because a connected client keeps the list it was given.
After raising the level, reconnect the client; `site-info` says so in
`capabilities.explains`.

Where Elementor runs, there is one more of each: `elementor-read` with the
read tools and `elementor-write` with the write tools. On any other site
they are not registered.

| Ability | What it does |
|---|---|
| `wpmcp/site-info` | Versions, post types, design tokens from `theme.json`. The first call of any session, so nothing has to be guessed. |
| `wpmcp/blocks-catalog` | The site's block kit: every block with its role (container / child / standalone), purpose, nesting rules and main variants — plus your editorial playbook if you ship one. |
| `wpmcp/blocks-describe` | Attribute schema for named blocks, grouped into content / layout / behavior / legacy, with deprecated values flagged. Compact by default; `detail: "full"` adds the prose and an example. |
| `wpmcp/content-list` | Find pages and posts, optionally filtered by which block they use. Lists only what the agent may read: drafts with the right to edit them, password-protected pages likewise, never trash or post types outside the connector's scope. |
| `wpmcp/content-read` | A page as a block tree: `outline` (cheap architecture view), `subtree` (one or several sections, each reporting its real path), or `full`. |
| `wpmcp/content-write` | *Write levels only.* Patch operations by block path (`insert`, `replace`, `remove`, `set_attrs`, `patch_html`, `move`), a full tree replacement, or SEO meta fields — alone or together. Dry run by default. |
| `wpmcp/content-create` | *Write levels only.* A new page with its title, slug, parent and status — and its content in the same call. A draft, unless published during a work session. The dry run validates the tree and meta exactly as the real call will. |
| `wpmcp/content-batch` | *Write levels only.* The same change on up to 20 posts in one call. Every item is dry-run first; nothing is saved unless all pass. |
| `wpmcp/content-duplicate` | *Write levels only.* Copy a page as a draft, including taxonomies and meta. Writes at once (no dry run); the default title is the original plus a translated " (Copy)". |
| `wpmcp/content-preview` | Server-rendered HTML, heading outline, and a signed preview URL that works without a login. Long pages come back in windows; the answer names its own size and where to continue. |
| `wpmcp/content-revisions` | The saved history of a page: ids, timestamps, authors, block counts. |
| `wpmcp/content-restore` | *Write levels only.* Undo — put a page back to one of its own revisions. |
| `wpmcp/content-search` | Find a string or pattern across the whole site, with the raw text around every hit. A long result names its `nextOffset`; `post_type` works as a single string too. Sees what `content-list` sees; a regular expression reads every page in scope and stops at a limit (`scanLimitReached`, filter `wpmcp_search_limits`). |
| `wpmcp/content-fetch-live` | The public URL over HTTP: what a visitor receives, cache headers and a parsed `head` (title, description, canonical, robots, Open Graph) included. `contains` answers "is my change on the page?" in a few hundred bytes; `body_only` drops header, footer, styles and scripts. |
| `wpmcp/media-list` | Attachments with alt text, title and every post that embeds them. `missing_alt` narrows it to the ones with none. |
| `wpmcp/media-read` | One attachment in the same shape. Takes `id`, or `post_id` like every other tool. |
| `wpmcp/media-update` | *Write levels only.* Sets alt text or title. No delete, no file replacement. |
| `wpmcp/media-upload` | *Write levels only, works only in a work session.* A JPEG, PNG or WebP into the media library, alt text required. Outside a session it answers `wpmcp_session_required`. |
| `wpmcp/elementor-read` | *Only where Elementor runs.* An Elementor page as an outline of its elements (id, path, type) with what each HTML widget says, or one element in full with its markup verbatim. |
| `wpmcp/elementor-write` | *Only where Elementor runs, write levels only.* Operations by element id (`patch_html`, `set_html`, `set_settings`, `insert`, `duplicate`, `move`, `remove`), saved through Elementor itself. Dry run by default. |

Every ability carries the MCP tool hints (`readOnlyHint`, `destructiveHint`,
`idempotentHint`, `openWorldHint`), all four set explicitly, because MCP
reads a missing one as the cautious default. `site-info` reports
`contractVersion`, an integer that rises only when an existing name,
field or meaning changes; the changelog marks each change under "API".

### Deliberately not covered

The agent works on the block tree, the SEO fields and the two text fields
of an attachment. It cannot delete media, replace a file, upload outside a
work session, set a
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

## Error codes

A tool that cannot do what was asked answers in one of two ways, and both
carry a code an agent can branch on:

- **An error.** The message starts with the code in brackets, exactly
  once: `[wpmcp_stale] Post 12 changed after you read it ...`. MCP clients
  receive only the message of a WordPress error, so the code travels in it.
- **A refusal with a report**, for writes that were understood but did not
  pass validation: `{ "ok": false, "code": "wpmcp_validation_failed",
  "errors": [...], "warnings": [...] }`. `content-batch` items have the
  same shape: `{ index, postId, ok, code, errors, warnings }`.

| Code | Meaning |
|---|---|
| `wpmcp_anchor_not_unique` | `patch_html`: the text to find occurs zero or several times in the block. |
| `wpmcp_bad_block` | An inserted or replacing block is not a valid block. |
| `wpmcp_bad_element` | `elementor-write`: an inserted element is malformed (no `elType`, a widget without `widgetType`, a child its type cannot hold). |
| `wpmcp_bad_op` | A patch operation is malformed or misses a required field. |
| `wpmcp_bad_parent` | The parent does not exist, is the page itself, another post type or would form a loop. |
| `wpmcp_bad_path` | A block path is malformed. |
| `wpmcp_bad_payload` | `ops`, `tree`, `meta` or `items` arrived in a shape that cannot be decoded. |
| `wpmcp_bad_regex` | `content-search`: the regular expression does not compile, is longer than 200 bytes, or failed while running on a page (the message names it). |
| `wpmcp_bad_request` | A required argument is missing or arguments contradict each other. |
| `wpmcp_bad_status` | `content-list`, `content-search`: a status that cannot be listed or searched. |
| `wpmcp_bad_upload` | `media-upload`: `data` is not valid base64. |
| `wpmcp_batch_incomplete` | `content-batch`: a save failed after every dry run passed; the posts before it are saved. |
| `wpmcp_dynamic_data_blocked` | The page holds dynamic data and the site has not allowed saving it. |
| `wpmcp_element_not_found` | `elementor-read`, `elementor-write`: no element with that id on the page. |
| `wpmcp_elementor_atomic` | `elementor-write`: the page holds elements of Elementor's Atomic editor (or one was sent); such pages are read-only here. |
| `wpmcp_elementor_data_invalid` | The page's stored Elementor data is not valid JSON. |
| `wpmcp_elementor_page` | `content-write` (with `ops` or `tree`), `content-batch`, `content-restore`: the post is built with Elementor; use `elementor-write`. Meta, slug, parent and status still go through `content-write`. |
| `wpmcp_forbidden` | The account lacks the WordPress capability for this post or attachment. |
| `wpmcp_forbidden_type` | The post type is outside the connector's scope. |
| `wpmcp_live_edit_disabled` | The post is published and the access level keeps published pages read-only. |
| `wpmcp_locked` | A person has the post open in the editor; a real write or restore waits until they close it (a dry run only warns). |
| `wpmcp_no_app_passwords` | Setup (admin): application passwords are not available on this site. |
| `wpmcp_no_permalink` | `content-fetch-live`: the post has no public URL. |
| `wpmcp_nonce` | Setup (admin): the form's security check failed. |
| `wpmcp_not_elementor` | `elementor-read`, `elementor-write`: the post is not built with Elementor; use the block tools. |
| `wpmcp_not_found` | No post or attachment with that ID. |
| `wpmcp_not_public` | `content-fetch-live`: the post is not public; use `content-preview`. |
| `wpmcp_password_protected` | The post is password protected; reading it needs the right to edit it. |
| `wpmcp_path_not_found` | A block path points at no block. |
| `wpmcp_pattern_readonly` | The post is a synced pattern and pattern editing is off. |
| `wpmcp_privacy_policy_page` | The privacy policy page is editable only at the published-pages level. |
| `wpmcp_render_failed` | A block threw while rendering. |
| `wpmcp_rest_scope` | The agent account called a REST route other than the MCP endpoint. |
| `wpmcp_runs_code` | The element executes its content as PHP and is not writable. |
| `wpmcp_save_failed` | WordPress did not save; nothing was changed (for `content-create`: the page was removed again; for `elementor-write`: Elementor refused the save or stopped with an error). |
| `wpmcp_session_required` | `media-upload` outside a work session; only the site owner can open one. |
| `wpmcp_sourced_attribute` | An attribute the block reads from its markup (block.json `source`: `core/paragraph` content, `core/image` url and alt ...) was sent in `attrs` without `html`; it would have been lost. The message names where it belongs and shows the markup to send. As the `code` of a refusal (`tree`) or of an error (`ops`, also `set_attrs`). |
| `wpmcp_stale` | `expected_modified` no longer matches: someone else saved in between. |
| `wpmcp_unfiltered_html_unavailable` | The site's configuration makes the dynamic-data save impossible, or (`elementor-write`) would make Elementor strip markup the page holds. |
| `wpmcp_unsafe_markup` | `content-restore`: the revision was not saved by a person and holds markup kses would filter. |
| `wpmcp_upload_failed` | `media-upload`: WordPress could not store the file. |
| `wpmcp_upload_needs_alt` | `media-upload`: no alt text and not marked decorative. |
| `wpmcp_upload_refused` | `media-upload`: the file contains a PHP tag. |
| `wpmcp_upload_too_large` | `media-upload`: the file exceeds the size limit. |
| `wpmcp_upload_too_many_pixels` | `media-upload`: the image exceeds the pixel limit (`wpmcp_max_upload_pixels`). |
| `wpmcp_upload_type` | `media-upload`: not a JPEG, PNG or WebP by its contents. |
| `wpmcp_user_exists` | Setup (admin): a user with that name exists with another role. |
| `wpmcp_validation_failed` | The write was understood and refused; `errors` says why, per block path. |
| `wpmcp_wrapper_missing` | A container was sent with `innerBlocks` but without `html` or `htmlTemplate`, and its wrapper element is part of the saved markup; the message proposes the `htmlTemplate` to send. As the `code` of a refusal (`tree`) or of an error (`ops`). |
| `wpmcp_wrong_revision` | The revision belongs to another post. |
| `wpmcp_xmlrpc` | The agent account tried XML-RPC. |

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

Attributes a block reads from its markup (a `source` in `block.json`)
are never written into the block comment, where WordPress and the editor
ignore them. Where the attribute is the block's whole markup
(`core/shortcode` text, `core/html` content) the markup is made from it
and reported in `markupGenerated`; any other such attribute sent without
`html` is refused with `wpmcp_sourced_attribute` and the markup to send.

Two things happen around the write itself, because validation alone was
not enough:

- **Before:** pass `expected_modified` (returned by `content-read`) and the
  write is refused if someone edited the page in the meantime, rather than
  silently overwriting them. A successful write returns the new `modified`
  value, so a sequence of writes needs no read between them just to fetch
  it. A page somebody has open in the block editor right now is refused
  with `wpmcp_locked` (the dry run only warns): they have saved nothing
  yet, so `expected_modified` cannot see them, and either their next save
  or a reload would lose one side's work.
- **Around it:** accounts without `unfiltered_html` — which the agent
  deliberately is — have their content run through `wp_kses_post` on save,
  which removes scripts and iframes. Writing such markup is therefore an
  error, reported in the dry run before anything is saved. Markup of that
  kind already on the page is *preserved*: WordPress saves the whole page
  on every write, so without this, editing one block would destroy the
  structured data in another. The agent can add none of it and can destroy
  none of it. That holds inside a block the agent changes, too: a
  fragment kses would remove (an attribute such as GenerateBlocks'
  `style="background-color:rgba(0, 0, 0, 0)"` on a highlight, which
  kses strips because `rgba()` is not among the CSS functions it lets
  through, or a whole iframe) may stay when the stored page already holds
  the same text on the same element, as often as the page will hold it
  after the change. Passing a highlight through `patch_html` unchanged
  works; a new one, one differing by a character, or a copy in a second
  place is refused, and the refusal quotes each fragment and says whether
  it was already in that block. The one exception is **structured data**: a
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

**Containers sent without their markup.** A block's saved form is its
children interleaved with the markup its save function writes around
them, which the tree carries as `htmlTemplate`. A node with `innerBlocks`
and neither `html` nor `htmlTemplate` used to be saved as its children
alone: right for a block that renders on the server and saves only its
children (the dbw-base kit), and silent loss of the wrapper and its
classes for everything else, `core/group`, `core/columns`,
`core/buttons` and GenerateBlocks 2's `element` among them. Now such a
node is decided on evidence, in this order:

1. The same block, children only, is already stored on the page: it is
   kept as it is.
2. `core/group` and `generateblocks/element` (GenerateBlocks 2): the
   wrapper is generated from the attributes exactly as the block editor
   saves it, and reported per path in `wrapperGenerated` and as a
   warning. Attributes that change the wrapper in ways not reproduced
   here (colours, spacing and other styles on a group, styles without a
   `uniqueId`, unusual HTML attributes on an element) are refused
   instead.
3. A saved instance of the block type with children, on the page or on
   one of the three newest published posts holding it, shows whether
   there is markup around the children.
4. Without one, a block with a render callback is taken to save its
   children only, said in a warning; a block without is saved statically
   by WordPress, which for a container means a wrapper, and is refused.

A refusal carries `wpmcp_wrapper_missing` and proposes an `htmlTemplate`,
copied from an existing instance with the node's `className` in place of
its own, or built from the tag and classes. The shape is always
`["\n<tag class=\"...\">", null, "\n\n", null, "</tag>\n"]`: one `null`
per child. A render callback alone proves nothing: GenerateBlocks
registers one for its element that only adds CSS, and `core/cover`,
`core/list` and `core/media-text` keep their wrapper in the post content
although they have one.

Reading a page also reports **structured data that lost its wrapper** —
JSON-LD sitting in the markup with no `<script>` around it, which renders
as a wall of text to visitors. It is the fingerprint of a script stripped
by an earlier unfiltered save, and otherwise only ever gets noticed by
someone looking at the page.

## Elementor

Classic Elementor pages, built with containers or with sections and
columns, keep their content in Elementor's own data (`_elementor_data`, a
JSON tree of elements); `post_content` only holds a plain-text copy for
search. The block tools see that copy, and what they would write there is
never shown and is replaced on Elementor's next save. So where Elementor
runs, the connector has two tools of its own, and the block tools behave
accordingly:

- `content-list` marks such pages with `builtWith: "elementor"`,
  `content-read` returns their fields and meta but no outline of the
  plain-text copy, `content-preview` renders them through Elementor, and
  `content-search` searches their elements (hits carry `elementId` and
  `setting` instead of a block path).
- `content-write` with `ops` or `tree`, `content-batch` items doing the
  same, and `content-restore` refuse them with `wpmcp_elementor_page`.
  SEO meta, slug, parent and status are not content and still go through
  `content-write`.
- `content-duplicate` copies the element data byte for byte, scripts
  included (Elementor would otherwise run kses over it for the agent
  account), and leaves out Elementor's caches of the original.

**Reading.** `elementor-read` returns every element with its id, path and
type. An HTML widget comes with an outline of what it says: headings with
their level, the first words of each paragraph, links, images with their
alt text (`null` when the attribute is missing, `""` when it is empty on
purpose), and how many scripts, styles, iframes and JSON-LD blocks it
holds, plus its size. Other widgets bring a short summary (title, text,
heading size, link, image). With `element_id` the tool returns that one
element with all its settings, and an HTML widget's markup verbatim, in
windows of 60000 bytes.

**Which widgets there are.** `elementor-read` (outline) and
`site-info.elementor` carry `availableWidgets` (count, and the names when
there are 20 or fewer) and `disabledByElementManager`. Where three or
fewer widgets are left, a `hint` says how to build there: a section is a
container with one html widget, in the classes and markup of a similar
section read first. That is the setup of staging.maxport.ch, where only
the HTML widget is switched on.

**Writing.** `elementor-write` takes operations by element id, applied in
order, dry run by default:

```json
{"post_id": 12, "expected_modified": "2026-10-07 09:12:44", "dry_run": false, "ops": [
  {"op": "patch_html", "id": "3f2a91c", "find": "<h1>Bootsfuehrerschein", "replace": "<h2>Bootsfuehrerschein"},
  {"op": "insert", "after": "8c01d2e", "element": {"elType": "container", "elements": [
    {"elType": "widget", "widgetType": "html", "settings": {"html": "<h2>FAQ</h2>..."}}]}}
]}
```

| Operation | Takes | Does |
|---|---|---|
| `patch_html` | `id`, `find`, `replace`, `all?`, `setting?` | Replaces text inside an HTML widget's markup (or the text setting named in `setting`). `find` must occur exactly once unless `all` is true. |
| `set_html` | `id`, `html` | Replaces an HTML widget's markup. |
| `set_settings` | `id`, `settings` | Merges settings; `null` removes one. Every changed key must be a registered control of the element, with the right type (text, object, number, one of a select's options). |
| `insert` | `element` or `elements`, `before`, `after` or `inside` | Adds new elements (`{elType, widgetType?, settings?, elements?}`). `inside` takes an id or `"root"` and appends. Ids are generated, seven hex digits like the editor's; ids sent along are ignored. |
| `duplicate` | `id` | Copies an element with new ids right after the original. |
| `move` | `id`, `before`, `after` or `inside` | Moves an element; not into itself. |
| `remove` | `id` | Removes an element and everything in it. |

Nesting follows the editor: the page holds containers or sections, a
container holds containers and widgets, a section holds columns, a column
holds widgets and inner sections. `isInner` is set as the editor sets it.
The answer confirms every operation (with the text around a patch as it
now reads, and the ids and paths of what was created), and after a real
write returns `revisionId`, the new `modified` and the cache purge.

**How it is saved.** Through Elementor's own document save, as the editor
does when a person clicks *Update*: `_elementor_data`, the plain-text copy,
a revision with the element data on it, and the page's generated CSS file
and element cache, which Elementor deletes so they are built again on the
next view. Then the connector purges page caches as after any write.

**What a change may store.** Elementor's answer for an account without
`unfiltered_html` is to run kses over every string of the page on every
save. For the agent that would strip the slider script in one widget
because a heading in another changed. So the save runs with
`unfiltered_html` granted for that one call, never on the role, and the
markup guard takes kses's place, string by string: every setting the
change touched must come out of kses unchanged, or may keep what kses
would remove only if the stored page already holds it, at least as often.
Editing the text next to an existing script works; a new script, a
changed one, an event handler, an iframe or a `javascript:` URL is refused
with the fragment quoted. A script is identified by where it sits, so
duplicating a section that carries one is refused too: the page would
have it twice. JSON-LD holding valid JSON may be written. Plain-text
settings are not run through kses (it would only turn `&` into `&amp;`
in a URL), but a `javascript:`, `vbscript:` or `data:` URL in any setting
is refused, and so is any change to a dynamic tag (`__dynamic__`), which
makes a setting render something else at view time. Where the site makes
`unfiltered_html` impossible (`DISALLOW_UNFILTERED_HTML`, multisite), a
save that would make Elementor strip anything is refused with
`wpmcp_unfiltered_html_unavailable`.

Everything else is as for blocks: `expected_modified`, `wpmcp_locked`
while a person has the page open (Elementor's editor holds the same post
lock), published pages only at the *Drafts and published pages* level or
in a work session, the activity log.

Elementor rewrites one thing itself: for an account that cannot manage
the site, its content sanitizer strips every attribute and image from a
heading widget's title. The dry run says what the title will become, and
after a save any setting Elementor stored differently is reported.

**Not covered.**

- Pages of Elementor's **Atomic editor** (`e-heading`, `e-flexbox` ...):
  read, never written (`wpmcp_elementor_atomic`). Their settings are typed
  props with rules these tools do not know.
- **Theme-builder templates** (headers, footers, popups in the
  `elementor_library` post type) are out of scope by default, though
  WordPress calls the post type public: they land on every page at once.
  Tick it ("My Templates", `elementor_library`) under *Additional post
  types* to bring them in; a work session brings them in for its length, like other
  building-block types.
- Element types Elementor cannot load (an addon switched off) block the
  save, because Elementor's save would silently drop them. A widget
  switched off under *Elementor > Element Manager* (option
  `elementor_disabled_elements`) is not loaded either; the refusal says
  so, as a site-wide setting for a person to decide, and names the widget
  types the site has.
- Page settings (`_elementor_page_settings`), global colours and fonts,
  Elementor Pro's custom code and display conditions.

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
the saved settings are what the site falls back to, and the agent's
capabilities with them, in the same second: they are worked out from the
timestamp on every check, never stored.

It exists because the wide settings are the ones people switch on for an
afternoon and never switch off, which leaves a site permanently on the
widest setting — exactly what having settings was meant to prevent. The
closing is a timestamp, not something anyone has to remember.

A session also brings the site's own building blocks into scope —
theme elements (`gp_elements`), templates, template parts and navigation
menus — so an hour of work does not begin with ticking boxes. It is an
allowlist: any other non-public post type, and above all the ones holding
other people's data (orders, subscriptions, form entries, bookings), is
private for a reason the connector cannot see. No length of window turns
reading a customer's address into a side effect of editing a page, so
those stay a tick somebody makes deliberately. A tick already made
survives the window either way, and a kit can name further building-block
types with the `wpmcp_session_post_types` filter.

**Publishing and uploading images happen only inside a session.** Opening
one is the human deciding that what gets built in it may go live — once,
instead of clicking publish twenty-two times afterwards. Outside a session
no status publishes at any access level, and the upload tool refuses with
`wpmcp_session_required` (it stays in the tool list, which never changes
with the session). Inside one, the capability is granted for a single save or upload
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
- **No deleting and no settings access** at any level, and no uploads
  outside a work session.
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
  stops the connector without deactivating the plugin. The agent account
  can no longer sign in with its application password while it is set.
- **Deactivating** ends a running work session and reduces the agent role
  to reading. Application passwords, settings and the audit log stay, so
  reactivating just works. Deleting the plugin removes all of it.

Do not work around a permission problem by giving the agent user a
built-in role such as Editor. Editor can publish and delete, which is
exactly what every level here withholds. The agent role itself stores
only reading; what a level or a work session adds is worked out on every
capability check, so a session that ran out or a level switched back
leaves nothing behind on the role.

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

## Translations

The admin screens and every notice are in English in the code and
translatable (text domain `wp-mcp-connector-plus`). A German translation
ships in `languages/`; a site in German gets it without doing anything.
A translation in `wp-content/languages/plugins/` takes precedence, so a
site's own corrections survive updates.

What the agent reads (tool descriptions, the messages in tool answers)
stays English on purpose: its reader is the agent, and those texts are
part of the contract it relies on.

After changing a translatable string, `bash bin/i18n.sh` rebuilds the
template (`languages/wp-mcp-connector-plus.pot`), merges it into every
`.po` and compiles the `.mo` files. It needs GNU gettext
(`brew install gettext`). `tests/shipped-files.php` fails when the
template no longer matches the code or a translation is incomplete.

## Updates

The plugin checks GitHub releases and reports updates in the WordPress
admin, via [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker).

For a private repository, add a fine-grained token with read access:

```php
define( 'WPMCP_GITHUB_TOKEN', 'github_pat_...' );
```

Releasing: bump the version in the plugin header **and** `WPMCP_VERSION`,
add an entry to [CHANGELOG.md](CHANGELOG.md), tag it, push. Pushing the
tag runs [the release workflow](.github/workflows/release.yml), which
builds `wp-mcp-connector-plus.zip` — right folder name, no test suite, no
Composer files — and publishes it as the release asset. Sites prefer that
asset; without one they fall back to the highest version tag, so an update
still arrives either way. The workflow runs the full test suite first and
stops when the tag, the header version and `WPMCP_VERSION` disagree, or
when the ZIP would carry `tests/` or an attack string a server firewall
refuses. The header version is what sites compare
against — a forgotten bump means no update appears.

`vendor/` is committed on purpose: WordPress installs the release ZIP as-is
and never runs Composer.

## Architecture

`wp-mcp-connector-plus.php` boots the plugin. What a page view needs is
loaded always and kept cheap; the tools load only in
`wpmcp_load_abilities()` (REST and WP-CLI, on `wp_abilities_api_init`);
the admin screens only in wp-admin. One line per file in `includes/`:

**Always loaded**

- `auth.php`: the AI role, application passwords for the agent only, the fence around the MCP route.
- `access.php`: access levels, work sessions, the capabilities the agent holds per check, role upkeep.
- `post-types.php`: which post types are in scope; loaded with `access.php`, because the settings screen needs it where the tools never load.
- `builders.php`: whether Elementor runs; loaded with `access.php` and with the content tools, because the tool list depends on it.
- `audit.php`: the log table, writing entries, retention.
- `preview.php`: signed, time-limited preview links for drafts.
- `editor.php`: the stamp on pages the agent saved and the notice in the block editor.
- `admin-bar.php`: a running work session in the admin bar.
- `updater.php`: updates from GitHub releases.

**Loaded with the tools**

- `schema.php`: attribute checks against `block.json`.
- `tree.php`: block arrays to the JSON tree and back, paths, patch operations.
- `wrappers.php`: containers sent without their markup: kept, generated (core/group, GenerateBlocks element), children only, or refused.
- `validate.php`: the validation pipeline every write passes (structure, nesting, roundtrip, render).
- `catalog.php`: `blocks-catalog` and `blocks-describe`, the playbook.
- `content.php`: loads the content files below, nothing else.
- `content-access.php`: which posts may be read and which written (statuses, live edit, edit lock).
- `content-read.php`: `content-list`, `content-read`, `content-preview`.
- `content-write.php`: `content-write`: plan, check, save, verify, placement, cache purge after a save.
- `content-create.php`: `content-create`, `content-duplicate`, `content-batch`.
- `revisions.php`: `content-revisions`, `content-restore`.
- `markup-guard.php`: what kses may skip (unchanged blocks, fragments already stored, safe JSON-LD) and the unfiltered_html grant.
- `save-errors.php`: explaining a save WordPress or a plugin refused.
- `meta.php`: readable and writable post meta (SEO fields, theme elements).
- `site-info.php`: `site-info`.
- `responses.php`: answer shape (error codes, `ok: false`), dry run default, debug timings.
- `search.php`: `content-search`.
- `cache.php`: cache purges and `content-fetch-live`; also loaded alone by the deferred purge cron event.
- `media.php`: `media-list`, `media-read`, `media-update`, `media-upload`.
- `abilities.php`: the table of tools (label, description, input schema, callback) and their registration.

**Loaded with the tools, only where Elementor runs** (`includes/elementor/`)

- `module.php`: loads the files below, nothing else.
- `data.php`: the element tree, ids, paths and operations; no WordPress.
- `outline.php`: what an HTML widget says (headings, paragraphs, links, images, scripts).
- `guard.php`: what a change may store, string by string, in place of Elementor's kses.
- `controls.php`: element types and changed settings checked against Elementor's registered controls.
- `tools.php`: `elementor-read`, `elementor-write`, the save through Elementor's document, rendering and search.

**wp-admin only**

- `admin.php`: Tools > MCP Connector (Connection, Access, Activity).
- `setup.php`: the one-click connection setup, loaded by `admin.php`.
- `class-wpmcp-log-table.php`: the activity log as a list table, loaded by `admin.php`.

## Tests

```bash
bash tests/run-all.sh                            # everything, as CI runs it
php tests/<name>.php                             # one suite on its own
DBW_CORE_PATH=/path/to/core bash tests/run-all.sh  # include the integration test
bash tests/wp-real/setup.sh                      # fetch the real-WordPress layer once
bash tests/wp-real/run.sh --filter RestFence     # run (part of) it on its own
```

`run-all.sh` fetches the WordPress block parser on first use (pinned to
one commit, see `tests/fetch-shim.sh`), runs every `tests/*.php` that is
not a helper, and fails on a PHP warning, notice or deprecation as well as
on a failed check. The integration test against real `block.json` files
runs when `DBW_CORE_PATH` (or a sibling `dbw-base-core` checkout) is found
and is skipped otherwise. [CI](.github/workflows/tests.yml) runs the same
script on PHP 8.1 and 8.4 for every push and pull request, and the release
workflow runs it before it builds.

The suite runs without a WordPress install but uses the **real** WordPress
block parser and serializer, so a passing round-trip here means the same
thing it would on a live site.

### Against a real WordPress

A shim answers what its author thought WordPress answers. Where that is
the whole question, `tests/wp-real` asks WordPress itself: WordPress core
(pinned, 6.9.8) on the SQLite Database Integration drop-in (pinned, 3.0.2,
so no MySQL server is needed), with GenerateBlocks (pinned, 2.4.1) active
as on the customer sites, Elementor (pinned, 4.3.4) for its own suite,
with the WordPress PHPUnit test library of
the same version (`wp-phpunit/wp-phpunit`), PHPUnit 9.6 and the PHPUnit
Polyfills. `setup.sh` downloads and checks them into the git-ignored
`tests/wp-real/.cache`; they never touch the plugin's own `vendor/`, which
ships. The plugin is linked into `wp-content/plugins` under its slug,
stored in `active_plugins` and activated through its activation hook, and
every test runs in a transaction that SQLite rolls back, dropped and
created tables included.

It covers only what the shims cannot see: kses and the markup guard,
capabilities granted for one save (also when the save throws) and what the
stored role holds, application passwords under real filter priorities, the
REST fence on a request that arrives with a real application password,
which abilities exist at each level and who may run them, slashing of
content, attributes and meta down to the stored bytes, the preview gate in
the real main query, update and uninstall on a real database,
`content-create` and the post lock, and how GenerateBlocks and core
register their containers and what a write without a template stores and
renders. Its first run found four bugs the shims had passed (see the
changelog).

The Elementor suite (`tests/wp-real/elementor`, `phpunit-elementor.xml.dist`)
runs in a second PHPUnit process with Elementor active as well, since a
plugin cannot be deactivated again inside one. Pages are stored as
Elementor stores them, the agent works on them through the abilities, and
what Elementor stored, renders and keeps in its revisions is read back
from Elementor. `run.sh` runs both suites. Elementor 4.3.4 raises PHP 8.4
deprecations from its own files and one warning from its content
sanitizer; the bootstrap drops exactly those, by folder and text.

`run-all.sh` runs it once `setup.sh` has been run, or with `WPMCP_REAL=1`,
and says it skipped it otherwise. CI runs it as a second job on PHP 8.1 and
8.4, with the downloads cached.

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
- **wrappers** — a GenerateBlocks section with three cards went live as a
  loose heading, a paragraph and three more headings, after a dry run
  that said ok: the containers had been sent without their markup, and
  the wrappers were dropped. Pins every decision of the wrapper check, and
  the generated wrappers byte for byte against what the block editor
  itself saved for the same attributes.
- **sourced-attributes** — a shortcode sent as `attrs.text` was stored as
  an empty block after a dry run that said ok: WordPress reads that
  attribute from the markup, never from the block comment. Pins which
  attributes become markup, which are refused, and the markup proposed.
- **elementor-data** — the first Elementor customer site keeps every
  section as one HTML widget, some with a slider script, and Elementor's
  own save would strip every script on the page for the agent account.
  Pins the element operations, the ids, the outline and the rule that
  replaces kses there, without WordPress or Elementor.
- **create-and-batch** — a 75-block dry run said "ok" without looking at
  the tree. Also that a batch with one bad item saves nothing.
- **run-integration** — loads real `block.json` files and checks the
  catalogue, detail view and validator against them.

## Status

See [CHANGELOG.md](CHANGELOG.md) for what changed and why.

**v0.20.1.** In daily use on customer sites, reading and writing. Whole
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
