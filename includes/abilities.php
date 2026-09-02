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
 * Register all abilities.
 */
function wpmcp_register_abilities() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	$read_only = array(
		'annotations' => array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		),
		'show_in_rest' => true,
	);

	wp_register_ability(
		'wpmcp/site-info',
		array(
			'label'       => 'Site-Info',
			'description' => 'Fingerprint of this website: WordPress/theme/core versions, client name, active feature modules, editable post types, and the design tokens (colour slugs, font sizes, spacing) the design system allows. Call this first in any session — versions and available blocks differ per customer site, so never assume them.',
			'category'    => WPMCP_ABILITY_CATEGORY,
			'input_schema' => array(
				'type'       => 'object',
				'properties' => new stdClass(),
			),
			'output_schema' => array( 'type' => 'object' ),
			'permission_callback' => 'wpmcp_can',
			'execute_callback'    => function () {
				wpmcp_log( 'wpmcp/site-info' );
				return wpmcp_site_info();
			},
			'meta' => $read_only,
		)
	);

	wp_register_ability(
		'wpmcp/blocks-catalog',
		array(
			'label'       => 'Block-Katalog',
			'description' => 'The building kit of this site: every available block with its role (container / child / standalone), what it is for, what may go inside it, and its main variants — plus the editorial playbook (page dramaturgy, block choice, tone, house rules) that no schema can carry. Read the playbook before building anything. This is the overview; use blocks-describe for the full attribute schema of the few blocks you actually intend to use.',
			'category'    => WPMCP_ABILITY_CATEGORY,
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
			'output_schema' => array( 'type' => 'object' ),
			'permission_callback' => 'wpmcp_can',
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
			'meta' => $read_only,
		)
	);

	wp_register_ability(
		'wpmcp/blocks-describe',
		array(
			'label'       => 'Block-Details',
			'description' => 'Full schema for named blocks: every attribute with its meaning, type, default and allowed values, grouped into content/layout/behavior/legacy, plus nesting rules and a minimal example. Ask for the handful of blocks you are about to use — never for all of them. Attributes marked legacy exist only so old pages keep working; do not use them in new content.',
			'category'    => WPMCP_ABILITY_CATEGORY,
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'names' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'Block names as listed by blocks-catalog, e.g. ["core/heading", "acme/hero"].',
					),
				),
				'required'   => array( 'names' ),
			),
			'output_schema' => array( 'type' => 'object' ),
			'permission_callback' => 'wpmcp_can',
			'execute_callback'    => function ( $input ) {
				$names = array_slice( array_filter( (array) ( $input['names'] ?? array() ), 'is_string' ), 0, 15 );
				if ( empty( $names ) ) {
					return new \WP_Error( 'wpmcp_bad_request', 'Provide at least one block name.' );
				}
				wpmcp_log( 'wpmcp/blocks-describe', array( 'summary' => implode( ', ', $names ) ) );
				return array( 'blocks' => wpmcp_describe_blocks( $names ) );
			},
			'meta' => $read_only,
		)
	);

	wp_register_ability(
		'wpmcp/content-list',
		array(
			'label'       => 'Inhalte auflisten',
			'description' => 'List pages, posts and custom post types with status, URL and block count. Use uses_block to find real examples of a block in use on this very site — reading two or three existing pages teaches the site\'s tone and section rhythm faster than any guideline.',
			'category'    => WPMCP_ABILITY_CATEGORY,
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
						'description' => 'Post status filter, e.g. "publish" or "draft".',
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
			'output_schema' => array( 'type' => 'object' ),
			'permission_callback' => 'wpmcp_can',
			'execute_callback'    => function ( $input ) {
				wpmcp_log( 'wpmcp/content-list' );
				return wpmcp_list_content( is_array( $input ) ? $input : array() );
			},
			'meta' => $read_only,
		)
	);

	wp_register_ability(
		'wpmcp/content-read',
		array(
			'label'       => 'Seite als Blockbaum lesen',
			'description' => 'Read a page as a block tree. Start with mode "outline" (block names, nesting and a short label per block — cheap, gives you the page architecture), then "subtree" for the sections you care about, and only use "full" when you really need the whole page. In subtree mode pass "paths" to fetch several sections in one call rather than one request per section. Every block carries a "path" like "2.0.1"; those paths are what you address in content-write. Attributes left at their default are omitted, so what you see is what was actually decided — check blocks-describe for what those defaults are. The returned "modified" value should be handed to content-write, which then refuses to overwrite someone else\'s edit.',
			'category'    => WPMCP_ABILITY_CATEGORY,
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
				),
				'required'   => array( 'post_id' ),
			),
			'output_schema' => array( 'type' => 'object' ),
			'permission_callback' => 'wpmcp_can',
			'execute_callback'    => function ( $input ) {
				$paths  = isset( $input['paths'] ) && is_array( $input['paths'] ) ? $input['paths'] : array();
				$result = wpmcp_read_content(
					(int) ( $input['post_id'] ?? 0 ),
					(string) ( $input['mode'] ?? 'outline' ),
					(string) ( $input['path'] ?? '' ),
					! empty( $input['include_defaults'] ),
					$paths
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
			'meta' => $read_only,
		)
	);

	wp_register_ability(
		'wpmcp/content-write',
		array(
			'label'       => 'Blockbaum schreiben',
			'description' => 'Write blocks to a page. Two modes: "ops" applies surgical patches (insert, replace, remove, set_attrs, move) addressed by block path — use this for anything short of a rebuild; or "tree" replaces the entire page content — only when you really are rebuilding it. Runs as a dry run by default and returns a validation report plus a block-count diff; pass dry_run: false to actually save. Pass expected_modified (from content-read) so the write is refused if someone edited the page in the meantime. After a real write the stored content is compared against what was sent, and any difference is reported: WordPress strips script tags, iframes and some attributes from agent accounts, so structured data and embeds cannot be written and will come back as a warning. Every real write creates a WordPress revision. Slug, status and post type are never touched, so URLs and publication state stay as they are. If validation fails, the errors name the exact block path and reason — fix and call again.',
			'category'    => WPMCP_ABILITY_CATEGORY,
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => 'The post/page ID to write to.',
					),
					'ops'     => array(
						'type'        => 'array',
						'description' => 'Patch operations, applied in order. Each: {"op":"insert|replace|remove|set_attrs|move","path":"2.1", ...}. insert/replace take "block" or "blocks"; set_attrs takes "attrs" (null value removes a key); move takes "to". Insert places the block at that position, shifting the rest down.',
						'items'       => array( 'type' => 'object' ),
					),
					'tree'    => array(
						'type'        => 'array',
						'description' => 'Full replacement tree. Each node: {"name":"core/group","attrs":{...},"innerBlocks":[...]}. Leaf core blocks may carry "html".',
						'items'       => array( 'type' => 'object' ),
					),
					'dry_run' => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Validate without saving. Defaults to true — pass false to write.',
					),
					'expected_modified' => array(
						'type'        => 'string',
						'description' => 'The "modified" value from content-read. The write is refused if the page changed since, instead of overwriting the other edit.',
					),
				),
				'required'   => array( 'post_id' ),
			),
			'output_schema' => array( 'type' => 'object' ),
			'permission_callback' => 'wpmcp_can',
			'execute_callback'    => function ( $input ) {
				return wpmcp_write_content( is_array( $input ) ? $input : array() );
			},
			'meta' => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
			),
		)
	);

	wp_register_ability(
		'wpmcp/content-duplicate',
		array(
			'label'       => 'Seite duplizieren',
			'description' => 'Duplicate a page including its blocks, taxonomies and meta. The copy is always a draft. This is the preferred way to create a new page: an existing page already carries the site\'s structure, tone and section rhythm, so adapting a copy beats assembling one from scratch. Find a good source with content-list first.',
			'category'    => WPMCP_ABILITY_CATEGORY,
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => 'The page to copy.',
					),
					'title'   => array(
						'type'        => 'string',
						'description' => 'Title for the copy. Defaults to the original plus " (Kopie)".',
					),
				),
				'required'   => array( 'post_id' ),
			),
			'output_schema' => array( 'type' => 'object' ),
			'permission_callback' => 'wpmcp_can',
			'execute_callback'    => function ( $input ) {
				return wpmcp_duplicate_post(
					(int) ( $input['post_id'] ?? 0 ),
					(string) ( $input['title'] ?? '' )
				);
			},
			'meta' => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
			),
		)
	);

	wp_register_ability(
		'wpmcp/content-preview',
		array(
			'label'       => 'Vorschau',
			'description' => 'See a page as it actually renders: server-rendered HTML, its heading outline, and a signed preview URL that works without a login for 15 minutes. Use it to check your own work after writing, and equally to inspect any existing page — the block tree says what is configured, this says what a visitor gets, including whether every block renders without error.',
			'category'    => WPMCP_ABILITY_CATEGORY,
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
				),
				'required'   => array( 'post_id' ),
			),
			'output_schema' => array( 'type' => 'object' ),
			'permission_callback' => 'wpmcp_can',
			'execute_callback'    => function ( $input ) {
				return wpmcp_preview_content(
					(int) ( $input['post_id'] ?? 0 ),
					! isset( $input['include_html'] ) || (bool) $input['include_html']
				);
			},
			'meta' => $read_only,
		)
	);
}
