<?php
/**
 * Ability registration — the connector's public surface.
 *
 * The descriptions here are not documentation, they are working
 * instructions: they are always in the model's context and are what makes
 * it behave like an editor (look first, duplicate rather than invent,
 * dry-run before writing) instead of a CRUD client.
 *
 * @package dbw-connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the ability category.
 */
function dbw_connector_register_category() {
	if ( ! function_exists( 'wp_register_ability_category' ) ) {
		return;
	}
	wp_register_ability_category(
		'dbw-connector',
		array(
			'label'       => 'dbw Connector',
			'description' => 'Block-tree level access to this dbw media website.',
		)
	);
}
add_action( 'wp_abilities_api_categories_init', 'dbw_connector_register_category' );

/**
 * Shared permission callback for every ability.
 *
 * @return bool
 */
function dbw_connector_can() {
	return current_user_can( DBW_CONNECTOR_CAP );
}

/**
 * Register all abilities.
 */
function dbw_connector_register_abilities() {
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
		'dbw/site-info',
		array(
			'label'       => 'Site-Info',
			'description' => 'Fingerprint of this website: WordPress/theme/core versions, client name, active feature modules, editable post types, and the design tokens (colour slugs, font sizes, spacing) the design system allows. Call this first in any session — versions and available blocks differ per customer site, so never assume them.',
			'category'    => 'dbw-connector',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => new stdClass(),
			),
			'output_schema' => array( 'type' => 'object' ),
			'permission_callback' => 'dbw_connector_can',
			'execute_callback'    => function () {
				dbw_connector_log( 'dbw/site-info' );
				return dbw_connector_site_info();
			},
			'meta' => $read_only,
		)
	);

	wp_register_ability(
		'dbw/blocks-catalog',
		array(
			'label'       => 'Block-Katalog',
			'description' => 'The building kit of this site: every available block with its role (container / child / standalone), what it is for, what may go inside it, and its main variants — plus the editorial playbook (page dramaturgy, block choice, tone, house rules) that no schema can carry. Read the playbook before building anything. This is the overview; use blocks-describe for the full attribute schema of the few blocks you actually intend to use.',
			'category'    => 'dbw-connector',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'scope' => array(
						'type'        => 'string',
						'enum'        => array( 'dbw', 'all' ),
						'default'     => 'dbw',
						'description' => '"dbw" lists the design-system blocks (default, almost always what you want). "all" adds WordPress core blocks.',
					),
				),
			),
			'output_schema' => array( 'type' => 'object' ),
			'permission_callback' => 'dbw_connector_can',
			'execute_callback'    => function ( $input ) {
				$scope = ( isset( $input['scope'] ) && 'all' === $input['scope'] ) ? 'all' : 'dbw';
				dbw_connector_log( 'dbw/blocks-catalog', array( 'summary' => 'scope=' . $scope ) );

				$result = array( 'blocks' => dbw_connector_build_catalog( $scope ) );

				// House rules travel with the kit — this is the moment the
				// model is learning how to build here.
				$playbook = dbw_connector_playbook();
				if ( '' !== $playbook ) {
					$result['playbook'] = $playbook;
				}

				return $result;
			},
			'meta' => $read_only,
		)
	);

	wp_register_ability(
		'dbw/blocks-describe',
		array(
			'label'       => 'Block-Details',
			'description' => 'Full schema for named blocks: every attribute with its meaning, type, default and allowed values, grouped into content/layout/behavior/legacy, plus nesting rules and a minimal example. Ask for the handful of blocks you are about to use — never for all of them. Attributes marked legacy exist only so old pages keep working; do not use them in new content.',
			'category'    => 'dbw-connector',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'names' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'Block names, e.g. ["dbw-base/hero", "dbw-base/usp-list"].',
					),
				),
				'required'   => array( 'names' ),
			),
			'output_schema' => array( 'type' => 'object' ),
			'permission_callback' => 'dbw_connector_can',
			'execute_callback'    => function ( $input ) {
				$names = array_slice( array_filter( (array) ( $input['names'] ?? array() ), 'is_string' ), 0, 15 );
				if ( empty( $names ) ) {
					return new \WP_Error( 'dbw_bad_request', 'Provide at least one block name.' );
				}
				dbw_connector_log( 'dbw/blocks-describe', array( 'summary' => implode( ', ', $names ) ) );
				return array( 'blocks' => dbw_connector_describe_blocks( $names ) );
			},
			'meta' => $read_only,
		)
	);

	wp_register_ability(
		'dbw/content-list',
		array(
			'label'       => 'Inhalte auflisten',
			'description' => 'List pages, posts and custom post types with status, URL and block count. Use uses_block to find real examples of a block in use on this very site — reading two or three existing pages teaches the site\'s tone and section rhythm faster than any guideline.',
			'category'    => 'dbw-connector',
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
						'description' => 'Only content containing this block, e.g. "dbw-base/case-study-grid".',
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
			'permission_callback' => 'dbw_connector_can',
			'execute_callback'    => function ( $input ) {
				dbw_connector_log( 'dbw/content-list' );
				return dbw_connector_list_content( is_array( $input ) ? $input : array() );
			},
			'meta' => $read_only,
		)
	);

	wp_register_ability(
		'dbw/content-read',
		array(
			'label'       => 'Seite als Blockbaum lesen',
			'description' => 'Read a page as a block tree. Start with mode "outline" (block names, nesting and a short label per block — cheap, gives you the page architecture), then "subtree" with a path for the section you care about, and only use "full" when you really need the whole page. Every block carries a "path" like "2.0.1"; those paths are what you address in content-write operations. Attributes left at their default are omitted, so what you see is what was actually decided.',
			'category'    => 'dbw-connector',
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
						'description' => 'Required for mode "subtree": a block path like "2" or "2.0.1".',
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
			'permission_callback' => 'dbw_connector_can',
			'execute_callback'    => function ( $input ) {
				$result = dbw_connector_read_content(
					(int) ( $input['post_id'] ?? 0 ),
					(string) ( $input['mode'] ?? 'outline' ),
					(string) ( $input['path'] ?? '' ),
					! empty( $input['include_defaults'] )
				);
				if ( ! is_wp_error( $result ) ) {
					dbw_connector_log(
						'dbw/content-read',
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
		'dbw/content-write',
		array(
			'label'       => 'Blockbaum schreiben',
			'description' => 'Write blocks to a page. Two modes: "ops" applies surgical patches (insert, replace, remove, set_attrs, move) addressed by block path — use this for anything short of a rebuild; or "tree" replaces the entire page content — only when you really are rebuilding it. Runs as a dry run by default and returns a validation report plus a block-count diff; pass dry_run: false to actually save. Every real write creates a WordPress revision. Slug, status and post type are never touched, so URLs and publication state stay as they are. If validation fails, the errors name the exact block path and reason — fix and call again.',
			'category'    => 'dbw-connector',
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
						'description' => 'Full replacement tree. Each node: {"name":"dbw-base/section","attrs":{...},"innerBlocks":[...]}. Leaf core blocks may carry "html".',
						'items'       => array( 'type' => 'object' ),
					),
					'dry_run' => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Validate without saving. Defaults to true — pass false to write.',
					),
				),
				'required'   => array( 'post_id' ),
			),
			'output_schema' => array( 'type' => 'object' ),
			'permission_callback' => 'dbw_connector_can',
			'execute_callback'    => function ( $input ) {
				return dbw_connector_write_content( is_array( $input ) ? $input : array() );
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
		'dbw/content-duplicate',
		array(
			'label'       => 'Seite duplizieren',
			'description' => 'Duplicate a page including its blocks, taxonomies and meta. The copy is always a draft. This is the preferred way to create a new page: an existing page already carries the site\'s structure, tone and section rhythm, so adapting a copy beats assembling one from scratch. Find a good source with content-list first.',
			'category'    => 'dbw-connector',
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
			'permission_callback' => 'dbw_connector_can',
			'execute_callback'    => function ( $input ) {
				return dbw_connector_duplicate_post(
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
		'dbw/content-preview',
		array(
			'label'       => 'Vorschau',
			'description' => 'Check your own work: returns the server-rendered HTML of the page, its heading outline, and a signed preview URL that works without a login for 15 minutes. Read the headings and HTML to verify structure; open the preview URL in a browser to actually look at the result. Always do this after writing.',
			'category'    => 'dbw-connector',
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
			'permission_callback' => 'dbw_connector_can',
			'execute_callback'    => function ( $input ) {
				return dbw_connector_preview_content(
					(int) ( $input['post_id'] ?? 0 ),
					! isset( $input['include_html'] ) || (bool) $input['include_html']
				);
			},
			'meta' => $read_only,
		)
	);
}
