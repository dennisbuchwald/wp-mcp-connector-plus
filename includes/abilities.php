<?php
/**
 * Ability registration — the connector's public surface.
 *
 * The descriptions here are not documentation, they are working
 * instructions: they are always in the model's context and are what makes
 * it behave like an editor (look first, duplicate rather than invent,
 * dry-run before writing) instead of a CRUD client.
 *
 * @package wp-mcp-connector-plus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Shared permission callback for every ability.
 *
 * @return bool
 */
function wpmcp_can() {
	return current_user_can( WPMCP_CAP );
}

/**
 * What a tool does to the site, in the terms MCP clients understand.
 *
 * The adapter turns meta.annotations into the MCP tool hints (readonly to
 * readOnlyHint, destructive to destructiveHint, idempotent to
 * idempotentHint, openWorldHint as is). A hint left out does not mean
 * "no": MCP reads a missing destructiveHint as true and a missing
 * openWorldHint as true, so content-create, which only ever adds a draft,
 * was announced as destructive, and a client that asks before every
 * destructive call asked for it. Every hint is therefore set, for every
 * tool, in this one table; read or write follows
 * wpmcp_write_ability_names().
 *
 * destructive: may change or remove what is already there (rather than
 * only adding something new). idempotent: the same call twice leaves the
 * same state as once. openWorldHint: reaches beyond the site's own
 * database; only content-fetch-live does, by an HTTP request.
 *
 * @param string $name Ability name.
 * @return array Ability meta.
 */
function wpmcp_ability_meta( $name ) {
	$write = array(
		// name                     => array( destructive, idempotent )
		'wpmcp/content-write'     => array( true, false ),
		'wpmcp/content-batch'     => array( true, false ),
		'wpmcp/content-create'    => array( false, false ),
		'wpmcp/content-duplicate' => array( false, false ),
		'wpmcp/content-restore'   => array( true, true ),
		'wpmcp/media-update'      => array( true, true ),
		'wpmcp/media-upload'      => array( false, false ),
	);

	$writes = in_array( $name, wpmcp_write_ability_names(), true );
	$hints  = $writes ? ( $write[ $name ] ?? array( true, false ) ) : array( false, true );

	return array(
		'annotations'  => array(
			'readonly'      => ! $writes,
			'destructive'   => $hints[0],
			'idempotent'    => $hints[1],
			'openWorldHint' => 'wpmcp/content-fetch-live' === $name,
		),
		'show_in_rest' => true,
	);
}

/**
 * Register one ability, if the access level offers it.
 *
 * The MCP server only lists what wpmcp_ability_names() returns, but the
 * Abilities API is reachable on its own: wp-abilities/v1 runs anything
 * registered for anyone holding the marker capability. Registering every
 * ability and filtering only the MCP list left the write abilities
 * callable at the read level. So the rule this plugin states (what is not
 * permitted does not exist) is kept here, at the one place every ability
 * passes.
 *
 * The same place gives every ability its annotations (wpmcp_ability_meta)
 * and puts every answer into the contract's shape (wpmcp_contract_result:
 * error codes in the message, a code on every refusal), so no single tool
 * can forget either.
 *
 * @param string $name Ability name.
 * @param array  $args Registration arguments.
 * @return bool Whether it was registered.
 */
function wpmcp_register_ability( $name, array $args ) {
	if ( ! in_array( $name, wpmcp_ability_names(), true ) ) {
		return false;
	}

	$args['meta'] = wpmcp_ability_meta( $name );

	$execute                  = $args['execute_callback'];
	$args['execute_callback'] = function ( $input = null ) use ( $execute ) {
		// Called exactly as the Abilities API would have called the tool:
		// without an argument when it passes none.
		$result = null === $input ? call_user_func( $execute ) : call_user_func( $execute, $input );
		return wpmcp_contract_result( $result );
	};

	return (bool) wp_register_ability( $name, $args );
}

/**
 * What each ability is: label, description, input schema and what it runs.
 *
 * One entry per tool, in the order they are registered. What every tool
 * has in common (category, output schema, permission callback) is added
 * by wpmcp_register_abilities(), and annotations and the answer contract
 * by wpmcp_register_ability(), so an entry holds only what differs. The
 * table is built only once wp_register_ability exists, since the labels
 * are translated on the way.
 *
 * @return array<string, array{label: string, description: string, input_schema: array, execute_callback: callable}>
 */
function wpmcp_ability_definitions() {
	return array(
		'wpmcp/site-info' => array(
			'label'       => __( 'Site info', 'wp-mcp-connector-plus' ),
			'description' => 'Fingerprint of this website: WordPress/theme/core versions, client name, active feature modules, editable post types, and the design tokens (colour slugs, font sizes, spacing) the design system allows. Call this first in any session — versions and available blocks differ per customer site, so never assume them.',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => new stdClass(),
			),
			'execute_callback'    => function () {
				wpmcp_log( 'wpmcp/site-info' );
				return wpmcp_site_info();
			},
		),

		'wpmcp/blocks-catalog' => array(
			'label'       => __( 'Block catalog', 'wp-mcp-connector-plus' ),
			'description' => 'The building kit of this site: every available block with its role (container / child / standalone), what it is for, what may go inside it, and its main variants — plus the editorial playbook (page dramaturgy, block choice, tone, house rules) that no schema can carry. Read the playbook before building anything. This is the overview; use blocks-describe for the full attribute schema of the few blocks you actually intend to use.',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'scope' => array(
						'type'        => 'string',
						'enum'        => array( 'site', 'all' ),
						'default'     => 'site',
						'description' => '"site" lists the design-system blocks (default, almost always what you want). "all" adds WordPress core blocks.',
					),
				),
			),
			'execute_callback'    => function ( $input ) {
				$scope = ( isset( $input['scope'] ) && 'all' === $input['scope'] ) ? 'all' : 'site';
				wpmcp_log( 'wpmcp/blocks-catalog', array( 'summary' => 'scope=' . $scope ) );

				$result = array( 'blocks' => wpmcp_build_catalog( $scope ) );

				// House rules travel with the kit — this is the moment the
				// model is learning how to build here.
				$playbook = wpmcp_playbook();
				if ( '' !== $playbook ) {
					$result['playbook'] = $playbook;
				}

				return $result;
			},
		),

		'wpmcp/blocks-describe' => array(
			'label'       => __( 'Block details', 'wp-mcp-connector-plus' ),
			'description' => 'Describes block TYPES, not the content of any page — schemas and rules, never the markup of a particular instance; for that read the page with content-read, which returns innerHTML verbatim. Every attribute with its type, default and allowed values, grouped into content/layout/behavior/legacy, plus nesting rules. Defaults to "compact", which is what you want: it leaves out the prose description of each attribute, and on a design system with dozens of attributes per block that prose is most of the answer. Pass detail: "full" for the descriptions and a worked example, and then for the one block you are unsure about rather than for ten. Ask for the handful of blocks you are about to use — never for all of them. Attributes marked legacy exist only so old pages keep working; do not use them in new content.',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'names' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'Block names as listed by blocks-catalog, e.g. ["core/heading", "acme/hero"].',
					),
					'detail' => array(
						'type'        => 'string',
						'enum'        => array( 'compact', 'full' ),
						'default'     => 'compact',
						'description' => 'How much to return per attribute. "compact" gives name, type, allowed values and default — enough to build a block correctly, and a fraction of the size. "full" adds the prose description of every attribute and a worked example; ask for it when a specific attribute is unclear, ideally for that one block rather than ten.',
					),
				),
				'required'   => array( 'names' ),
			),
			'execute_callback'    => function ( $input ) {
				$names = array_slice( array_filter( (array) ( $input['names'] ?? array() ), 'is_string' ), 0, 15 );
				if ( empty( $names ) ) {
					return new \WP_Error(
						'wpmcp_bad_request',
						'Provide "names": a list of block names to describe, as blocks-catalog spells them, for example ["core/heading", "acme/hero"]. It is the only required argument of this tool.'
					);
				}
				$detail = ( 'full' === ( $input['detail'] ?? 'compact' ) ) ? 'full' : 'compact';
				wpmcp_log( 'wpmcp/blocks-describe', array( 'summary' => implode( ', ', $names ) . ' (' . $detail . ')' ) );
				return array(
					'blocks' => wpmcp_describe_blocks( $names, $detail ),
					'detail' => $detail,
				);
			},
		),

		'wpmcp/content-list' => array(
			'label'       => __( 'List content', 'wp-mcp-connector-plus' ),
			'description' => 'Reads from the database. Lists pages, posts and custom post types with status, URL and block count. Use uses_block to find real examples of a block in use on this very site — reading two or three existing pages teaches the site\'s tone and section rhythm faster than any guideline.',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'post_type'  => array(
						'type'        => 'string',
						'description' => 'Restrict to one post type, e.g. "page".',
					),
					'search'     => array(
						'type'        => 'string',
						'description' => 'Free-text search over title and content.',
					),
					'uses_block' => array(
						'type'        => 'string',
						'description' => 'Only content containing this block, e.g. "core/gallery".',
					),
					'status'     => array(
						'type'        => 'string',
						'description' => 'Post status filter: publish, draft, pending, future or private, several separated by commas.',
					),
					'per_page'   => array(
						'type'        => 'integer',
						'default'     => 20,
						'description' => 'Results per page (max 100).',
					),
					'page'       => array(
						'type'        => 'integer',
						'default'     => 1,
						'description' => 'Page number.',
					),
				),
			),
			'execute_callback'    => function ( $input ) {
				wpmcp_log( 'wpmcp/content-list' );
				return wpmcp_list_content( is_array( $input ) ? $input : array() );
			},
		),

		'wpmcp/content-read' => array(
			'label'       => __( 'Read page as block tree', 'wp-mcp-connector-plus' ),
			'description' => 'Reads from the database. Read a page as a block tree. Start with mode "outline" (block names, nesting and a short label per block — cheap, gives you the page architecture), then "subtree" for the sections you care about, and only use "full" when you really need the whole page. In subtree mode pass "paths" to fetch several sections in one call rather than one request per section. Every block carries a "path" like "2.0.1"; those paths are what you address in content-write. Attributes left at their default are omitted, so what you see is what was actually decided — check blocks-describe for what those defaults are. The returned "modified" value should be handed to content-write, which then refuses to overwrite someone else\'s edit.',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => 'The post/page ID.',
					),
					'mode'    => array(
						'type'        => 'string',
						'enum'        => array( 'outline', 'full', 'subtree' ),
						'default'     => 'outline',
						'description' => 'How much to return.',
					),
					'path'    => array(
						'type'        => 'string',
						'description' => 'For mode "subtree": a block path like "2" or "2.0.1".',
					),
					'paths'   => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'For mode "subtree": several paths at once, e.g. ["0","1","2"]. Preferred over repeated calls.',
					),
					'include_defaults' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Also return attributes that still hold their default value.',
					),
					'include_meta'     => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Also return SEO fields (Rank Math, Yoast), featured image, excerpt and template. Read-only.',
					),
				),
				'required'   => array( 'post_id' ),
			),
			'execute_callback'    => function ( $input ) {
				$paths  = isset( $input['paths'] ) && is_array( $input['paths'] ) ? $input['paths'] : array();
				$result = wpmcp_read_content(
					(int) ( $input['post_id'] ?? 0 ),
					(string) ( $input['mode'] ?? 'outline' ),
					(string) ( $input['path'] ?? '' ),
					! empty( $input['include_defaults'] ),
					$paths,
					! empty( $input['include_meta'] )
				);
				if ( ! is_wp_error( $result ) ) {
					wpmcp_log(
						'wpmcp/content-read',
						array(
							'post_id' => (int) ( $input['post_id'] ?? 0 ),
							'summary' => 'mode=' . (string) ( $input['mode'] ?? 'outline' ),
						)
					);
				}
				return $result;
			},
		),

		'wpmcp/content-write' => array(
			'label'       => __( 'Write block tree', 'wp-mcp-connector-plus' ),
			'description' => 'Write to a page: block content, SEO meta, or where the page sits — alone or together. Dry run by default; pass dry_run: false to save. Returns a validation report, a block-count diff, and the new "modified" value to pass as expected_modified on the next write. \n\nContent comes as "ops" (patch operations by block path) or "tree" (replace the page). Use ops for anything short of a rebuild. To change text inside a block use patch_html with "find" and "replace", not replace — replace demands the block\'s entire markup back, and everything retyped can come back wrong. The anchor must occur exactly once; content-search returns the surrounding text verbatim, which is how to pick one that does. The answer shows each patched block as it now reads. \n\nValidation judges the change, not the page: a problem that already existed in a block you did not touch is a warning, anything the change introduces is an error naming the block path. Markup WordPress strips from an agent account (scripts, iframes, event handlers such as onerror, javascript: URLs) cannot be written and is refused in the dry run, naming the block path, except structured data: <script type="application/ld+json"> holding valid JSON is accepted and stored safely; markup of that kind already on the page is preserved, so editing one block never breaks structured data in another. After a real write the stored content is compared against what was sent. Every write leaves a revision. \n\nslug, parent and status apply only while a page has never been published — a live page keeps its address. publish is accepted only during a work session. Post type is never touched. \n\nBefore inserting a block type you have not written before, read an existing instance with content-read and mirror its shape: some libraries keep a per-instance id, generated CSS and matching classes that must agree, and a block that merely validates can still be wrong.',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => 'The post/page ID to write to.',
					),
					'meta'    => array(
						'type'        => array( 'object', 'string' ),
						'description' => 'SEO meta fields to set, as key => value: rank_math_title, rank_math_description, rank_math_focus_keyword, rank_math_canonical_url, and the _yoast_wpseo_ equivalents. null clears a field. Can be sent on its own, without ops or tree, when only the meta needs changing — that leaves the page content and its modified date untouched. On post types that are nothing but their plugin settings, the prefix of the owning plugin is writable too (gp_elements: _generate_*), arrays included — read an existing element with content-read include_meta first and copy its keys and shapes, since they differ per plugin version. Keys that could make a post run code are never accepted. Anything else is an error naming the allowed set. The dry run reports the previous and new value of every field. Note that WordPress revisions do not cover post meta, so unlike a content change this cannot be rolled back with one click; the previous values are in the response and the activity log.',
					),
					'ops'     => array(
						// Not "array" alone, and no items schema: a large
						// argument sometimes arrives as a JSON string, which
						// the REST layer then splits on commas and rejects
						// with a message about item 0 not being an object.
						// The operations are validated properly further in,
						// where the errors can name the operation and path.
						'type'        => array( 'array', 'string' ),
						'description' => 'Patch operations, applied in order. Each: {"op":"insert|replace|remove|set_attrs|patch_html|move","path":"2.1", ...}. insert/replace take "block" or "blocks"; set_attrs takes "attrs" (null value removes a key); patch_html takes "find" and "replace"; move takes "to". Insert places the block at that position, shifting the rest down.',
					),
					'tree'    => array(
						'type'        => array( 'array', 'string' ),
						'description' => 'Full replacement tree. Each node: {"name":"core/group","attrs":{...},"innerBlocks":[...]}. Leaf core blocks may carry "html".',
					),
					'dry_run' => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Validate without saving. Defaults to true — pass false to write.',
					),
					'slug'    => array(
						'type'        => 'string',
						'description' => 'New URL slug. Only while the page has never been published — a published page keeps its address, because that is what every link to it points at. Change that one in the editor, where the redirect is yours to set up.',
					),
					'parent'  => array(
						'type'        => 'integer',
						'description' => 'ID of the page this one sits under, 0 for none. Same rule as slug: only while the page is not live, because the parent is part of the URL. Must be the same post type, and cannot form a loop.',
					),
					'status'  => array(
						'type'        => 'string',
						'enum'        => array( 'draft', 'pending', 'publish' ),
						'description' => 'draft or pending; publish only while the site owner has a work session open. A published page cannot be taken back to draft — that removes it from the site, which is not a write.',
					),
					'expected_modified' => array(
						'type'        => 'string',
						'description' => 'The "modified" value from content-read. The write is refused if the page changed since, instead of overwriting the other edit.',
					),
				),
				'required'   => array( 'post_id' ),
			),
			'execute_callback'    => function ( $input ) {
				return wpmcp_write_content( is_array( $input ) ? $input : array() );
			},
		),

		'wpmcp/content-duplicate' => array(
			'label'       => __( 'Duplicate page', 'wp-mcp-connector-plus' ),
			'description' => 'Duplicate a page including its blocks, taxonomies and meta. Writes immediately, there is no dry run: the copy is created as a draft and its id returned. This is the preferred way to create a new page: an existing page already carries the site\'s structure, tone and section rhythm, so adapting a copy beats assembling one from scratch. Find a good source with content-list first.',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => 'The page to copy.',
					),
					'title'   => array(
						'type'        => 'string',
						'description' => 'Title for the copy. Defaults to the original plus " (Copy)", translated.',
					),
				),
				'required'   => array( 'post_id' ),
			),
			'execute_callback'    => function ( $input ) {
				return wpmcp_duplicate_post(
					(int) ( $input['post_id'] ?? 0 ),
					(string) ( $input['title'] ?? '' )
				);
			},
		),

		'wpmcp/content-preview' => array(
			'label'       => __( 'Preview', 'wp-mcp-connector-plus' ),
			'description' => 'Renders the stored content server-side — this is the database put through the block renderer, NOT the page a visitor receives; with a page cache in front the two differ, and content-fetch-live is the one that settles it. Returns the rendered HTML, its heading outline, and a signed preview URL that works without a login for 15 minutes. Use it to check your own work after writing, and equally to inspect any existing page — the block tree says what is configured, this says what a visitor gets, including whether every block renders without error.',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'post_id'      => array(
						'type'        => 'integer',
						'description' => 'The page to preview.',
					),
					'include_html' => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Return the rendered HTML, not just the link.',
					),
					'offset'       => array(
						'type'        => 'integer',
						'default'     => 0,
						'description' => 'Byte to start the returned HTML at. Long pages come back in windows of 60000 bytes; when one is not the whole page the answer says so in "truncated" and names the "nextOffset" to ask for.',
					),
				),
				'required'   => array( 'post_id' ),
			),
			'execute_callback'    => function ( $input ) {
				return wpmcp_preview_content(
					(int) ( $input['post_id'] ?? 0 ),
					! isset( $input['include_html'] ) || (bool) $input['include_html'],
					(int) ( $input['offset'] ?? 0 )
				);
			},
		),

		'wpmcp/content-revisions' => array(
			'label'       => __( 'Revisions', 'wp-mcp-connector-plus' ),
			'description' => 'The saved history of a page: revision ids, when each was made, by whom, and how many blocks it held. Use it to find the state to go back to when a change turned out wrong — content-restore takes an id from here.',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => 'The post/page ID.',
					),
					'limit'   => array(
						'type'        => 'integer',
						'default'     => 15,
						'description' => 'How many revisions to return, newest first (max 50).',
					),
				),
				'required'   => array( 'post_id' ),
			),
			'execute_callback'    => function ( $input ) {
				wpmcp_log( 'wpmcp/content-revisions', array( 'post_id' => (int) ( $input['post_id'] ?? 0 ) ) );
				return wpmcp_list_revisions(
					(int) ( $input['post_id'] ?? 0 ),
					(int) ( $input['limit'] ?? 15 )
				);
			},
		),

		'wpmcp/content-restore' => array(
			'label'       => __( 'Restore revision', 'wp-mcp-connector-plus' ),
			'description' => 'Undo: put a page back to one of its own revisions, listed by content-revisions. Dry run by default, and the current state becomes a revision of its own first, so restoring is itself reversible. This is also the only way to bring back markup that content-write cannot produce — a restored state is one the page already held, not something the agent authored.',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'post_id'     => array(
						'type'        => 'integer',
						'description' => 'The post/page ID.',
					),
					'revision_id' => array(
						'type'        => 'integer',
						'description' => 'Revision to restore, from content-revisions. Must belong to this post.',
					),
					'dry_run'     => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Report what would change without restoring. Defaults to true.',
					),
				),
				'required'   => array( 'post_id', 'revision_id' ),
			),
			'execute_callback'    => function ( $input ) {
				return wpmcp_restore_revision(
					(int) ( $input['post_id'] ?? 0 ),
					(int) ( $input['revision_id'] ?? 0 ),
					wpmcp_is_dry_run( $input )
				);
			},
		),

		'wpmcp/content-search' => array(
			'label'       => __( 'Search content', 'wp-mcp-connector-plus' ),
			'description' => 'Reads from the database. Finds a string or regular expression across the whole site in one call, and returns for every occurrence: the post, the block path, the block type, its per-instance id, whether the hit sits in the markup or in an attribute, and the raw text around it. Use this before changing anything that appears in several places — the context shows what the markup actually is at each site, so a change never has to be extrapolated from the cases you happened to look at. Searches what content-list would list: statuses publish, draft, pending, future and private, each only where you may read it. "scanned" says how many posts were read. Prefer plain text: it is matched in the database first. A regular expression (at most 200 bytes) reads every post in scope and stops at a limit; then the answer has scanLimitReached and a hint, and post_type or post_status narrow it.',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'query'         => array(
						'type'        => 'string',
						'description' => 'Text to find, or a regular expression when regex is true.',
					),
					'regex'         => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Treat the query as a regular expression (without delimiters).',
					),
					'post_types'    => array(
						'type'        => array( 'array', 'string' ),
						'items'       => array( 'type' => 'string' ),
						'description' => 'Restrict to these post types. Defaults to all the connector may read.',
					),
					'post_type'     => array(
						'type'        => 'string',
						'description' => 'One post type, e.g. "page"; same as post_types with one entry.',
					),
					'post_status'   => array(
						'type'        => array( 'array', 'string' ),
						'items'       => array( 'type' => 'string' ),
						'description' => 'Restrict to these statuses, e.g. ["publish"]. One of publish, draft, pending, future, private.',
					),
					'context_chars' => array(
						'type'        => 'integer',
						'default'     => 80,
						'description' => 'Characters of raw context on each side of a hit (max 400).',
					),
					'limit'         => array(
						'type'        => 'integer',
						'default'     => 200,
						'description' => 'Maximum hits to return (max 500). When cut short, the answer has "truncated" and the "nextOffset" to continue from.',
					),
					'offset'        => array(
						'type'        => 'integer',
						'default'     => 0,
						'description' => 'Hits to skip, for the next page of a long result.',
					),
				),
				'required'   => array( 'query' ),
			),
			'execute_callback'    => function ( $input ) {
				return wpmcp_search_content( is_array( $input ) ? $input : array() );
			},
		),

		'wpmcp/content-fetch-live' => array(
			'label'       => __( 'Fetch delivered page', 'wp-mcp-connector-plus' ),
			'description' => 'Reads the public URL over HTTP, with a cache buster — what a visitor actually receives, not what is stored. This is the only honest check after a write: with a page cache in front, the database can be correct while the delivered page is still the old one. The response includes any cache headers, so a stale answer is recognisable, and a "head" summary with the title, meta description, canonical, robots and Open Graph tags plus a count of JSON-LD blocks (head and body) — which is the only way to confirm that an SEO field you wrote actually reaches the page, since content-preview renders the body alone. Note that a maintenance-mode plugin answers this request too, and its holding page has a head of its own. To check a change, pass contains rather than reading the page.',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'post_id'      => array(
						'type'        => 'integer',
						'description' => 'The post/page ID.',
					),
					'cache_buster' => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Append a unique query parameter to bypass caches. Turn off to see exactly what a normal visitor gets.',
					),
					'offset'       => array(
						'type'        => 'integer',
						'default'     => 0,
						'description' => 'Byte to start the returned HTML at. Long pages come back in windows of 60000 bytes; when one is not the whole page the answer says so in "truncated" and names the "nextOffset" to ask for.',
					),
					'contains'     => array(
						'type'        => 'string',
						'description' => 'Check whether this text is on the delivered page instead of receiving the page: found, how often, whether it is in the main content rather than only header or footer, and the text around the first hits. The usual check after a write, at a fraction of the size.',
					),
					'body_only'    => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Return only the main content area, without header, footer, styles and scripts.',
					),
				),
				'required'   => array( 'post_id' ),
			),
			'execute_callback'    => function ( $input ) {
				return wpmcp_fetch_live(
					(int) ( $input['post_id'] ?? 0 ),
					! isset( $input['cache_buster'] ) || (bool) $input['cache_buster'],
					(int) ( $input['offset'] ?? 0 ),
					(string) ( $input['contains'] ?? '' ),
					! empty( $input['body_only'] )
				);
			},
		),

		'wpmcp/content-create' => array(
			'label'       => __( 'Create page', 'wp-mcp-connector-plus' ),
			'description' => 'Creates a page with its title, slug, parent and status, and writes the content in the same call when "tree" and "meta" come with it. Use this to build a page rather than duplicating one and overwriting everything: a duplicate inherits the parent it was copied from and a slug derived from the old title, both of which then have to be corrected by hand. Duplicating is still right when an existing page is the template — content-duplicate keeps its taxonomies and meta. A draft unless status publish is given during a work session, and then only once the content is written. Dry run by default, and the dry run validates the tree and meta exactly as the real call will. The page is created with its content or not at all: content that is refused leaves nothing behind, so fix it and call content-create again.',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'title'     => array(
						'type'        => 'string',
						'description' => 'The page title.',
					),
					'post_type' => array(
						'type'        => 'string',
						'default'     => 'page',
						'description' => 'Post type, as listed by site-info. Defaults to "page".',
					),
					'slug'      => array(
						'type'        => 'string',
						'description' => 'URL slug. Left out, WordPress derives one from the title.',
					),
					'parent'    => array(
						'type'        => 'integer',
						'default'     => 0,
						'description' => 'ID of the page this one sits under. Must be the same post type.',
					),
					'status'    => array(
						'type'        => 'string',
						'enum'        => array( 'draft', 'pending', 'publish' ),
						'default'     => 'draft',
						'description' => 'draft or pending; publish only during a work session, and then only once the content is written.',
					),
					'tree'      => array(
						'type'        => array( 'array', 'string' ),
						'description' => 'The block tree for the new page, in the same shape content-write takes. Written in the same call, through the same validation.',
					),
					'meta'      => array(
						'type'        => array( 'object', 'string' ),
						'description' => 'Meta fields, same rules as content-write: SEO keys, plus the plugin prefix on post types like gp_elements.',
					),
					'dry_run'   => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Report what would be created without creating it. Defaults to true.',
					),
				),
				'required'   => array( 'title' ),
			),
			'execute_callback'    => function ( $input ) {
				return wpmcp_create_content( is_array( $input ) ? $input : array() );
			},
		),

		'wpmcp/media-list' => array(
			'label'       => __( 'Browse media library', 'wp-mcp-connector-plus' ),
			'description' => 'Lists attachments with their alt text, title and — the reason the tool exists — every post that embeds them. Alt text usually lives on the attachment, not on the block that displays the image, so a page can look like it has no alt text while the theme fills one in from here, or the other way round. Pass missing_alt: true to see only attachments with none. "usedIn" counts both markup references and featured images, so an image used only as a featured image does not look unused.',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'search'      => array(
						'type'        => 'string',
						'description' => 'Filter by filename, title or caption.',
					),
					'mime_type'   => array(
						'type'        => 'string',
						'description' => 'Filter by MIME type or prefix, e.g. "image" or "image/png".',
					),
					'missing_alt' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Only attachments whose alt text is empty or unset.',
					),
					'per_page'    => array(
						'type'        => 'integer',
						'default'     => 25,
						'description' => 'Attachments per page, up to 100.',
					),
					'page'        => array(
						'type'        => 'integer',
						'default'     => 1,
						'description' => 'Page of results.',
					),
				),
			),
			'execute_callback'    => function ( $input ) {
				return wpmcp_media_list( is_array( $input ) ? $input : array() );
			},
		),

		'wpmcp/media-read' => array(
			'label'       => __( 'Read media item', 'wp-mcp-connector-plus' ),
			'description' => 'One attachment with its alt text, title, caption, URL, MIME type and the posts that embed it. Use it to check a single image before or after changing it.',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'id'      => array(
						'type'        => 'integer',
						'description' => 'The attachment ID.',
					),
					'post_id' => array(
						'type'        => 'integer',
						'description' => 'Same as id.',
					),
				),
			),
			'execute_callback'    => function ( $input ) {
				$id = wpmcp_attachment_id_arg( $input );
				return is_wp_error( $id ) ? $id : wpmcp_media_read( $id );
			},
		),

		'wpmcp/media-update' => array(
			'label'       => __( 'Label media item', 'wp-mcp-connector-plus' ),
			'description' => 'Sets the alt text or title of an attachment. Nothing else: no upload, no delete, no replacing the file, and no other field. Dry run by default; pass dry_run: false to save. Alt text describes what the image shows to someone who cannot see it — it is not a place for keywords, and a decorative image is better with an empty alt than with an invented one. Attachment fields have no revisions, so the previous values are reported and logged.',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'id'      => array(
						'type'        => 'integer',
						'description' => 'The attachment ID.',
					),
					'post_id' => array(
						'type'        => 'integer',
						'description' => 'Same as id.',
					),
					'alt'     => array(
						'type'        => 'string',
						'description' => 'New alt text. Pass an empty string to clear it, which is right for a purely decorative image.',
					),
					'title'   => array(
						'type'        => 'string',
						'description' => 'New title, as shown in the media library.',
					),
					'dry_run' => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Report the change without saving. Defaults to true.',
					),
				),
			),
			'execute_callback'    => function ( $input ) {
				return wpmcp_media_update( is_array( $input ) ? $input : array() );
			},
		),

		'wpmcp/content-batch' => array(
			'label'       => __( 'Write several pages', 'wp-mcp-connector-plus' ),
			'description' => 'Applies changes to up to 20 posts in one call: items is a list of what content-write takes, one per post, each with its own expected_modified. Every item is dry-run first and nothing is saved unless all pass; dry run by default. There is no transaction across posts, so if one fails on save after passing — it changed in between — the run stops there and says which posts were saved. Each post gets its own revision. Put all changes to one post into a single item. Every item answers { postId, ok, code, errors, warnings }.',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'items'   => array(
						'type'        => array( 'array', 'string' ),
						'description' => 'One entry per post: { post_id, ops or tree or meta, expected_modified }.',
					),
					'dry_run' => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Check every item without saving. Defaults to true.',
					),
				),
				'required'   => array( 'items' ),
			),
			'execute_callback'    => function ( $input ) {
				return wpmcp_batch_write( is_array( $input ) ? $input : array() );
			},
		),

		// Always there at a write level; refuses outside a work session.
		'wpmcp/media-upload' => array(
			'label'       => __( 'Upload image', 'wp-mcp-connector-plus' ),
			'description' => 'Uploads an image into the media library and returns its id and url for the image block. Works only during a work session the site owner opened; outside one it answers wpmcp_session_required. Send the file base64-encoded as "data". JPEG, PNG and WebP only, judged by the bytes, not the name; SVG is refused. Alt text is required: describe what the image shows, or pass decorative: true for an image with no meaning of its own. Dry run by default.',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'filename'   => array(
						'type'        => 'string',
						'description' => 'Suggested file name. The extension is replaced by the detected type.',
					),
					'data'       => array(
						'type'        => 'string',
						'description' => 'The file contents, base64-encoded, or a data: URL.',
					),
					'alt'        => array(
						'type'        => 'string',
						'description' => 'What the image shows, for someone who cannot see it.',
					),
					'decorative' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'True for a purely decorative image; stores an empty alt deliberately.',
					),
					'title'      => array(
						'type'        => 'string',
						'description' => 'Title in the media library. Defaults to the file name.',
					),
					'dry_run'    => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Check the file without storing it. Defaults to true.',
					),
				),
				'required'   => array( 'filename', 'data' ),
			),
			'execute_callback'    => function ( $input ) {
				return wpmcp_media_upload( is_array( $input ) ? $input : array() );
			},
		),
	);
}

/**
 * Register the abilities the access level offers.
 */
function wpmcp_register_abilities() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	foreach ( wpmcp_ability_definitions() as $name => $definition ) {
		wpmcp_register_ability(
			$name,
			array(
				'label'               => $definition['label'],
				'description'         => $definition['description'],
				'category'            => WPMCP_ABILITY_CATEGORY,
				'input_schema'        => $definition['input_schema'],
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => 'wpmcp_can',
				'execute_callback'    => $definition['execute_callback'],
			)
		);
	}
}
