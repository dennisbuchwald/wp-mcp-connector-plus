<?php
/**
 * Which post types are in the connector's scope.
 *
 * @package wp-mcp-connector-plus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Post types the connector may touch.
 *
 * Always loaded (through access.php), not with the content tools: the
 * settings screen needs it on a request where the tools never load
 * (tests/load-boundaries.php).
 *
 * @return string[]
 */
function wpmcp_allowed_post_types() {
	// Public is the whole test. Requiring show_ui as well used to hide any
	// post type a plugin registers in code without an admin screen — a
	// site-wide search then reported nothing and looked right doing it.
	$types = array();
	foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
		// Media has its own tools; an attachment holds no block tree.
		// Elementor's template library is public in WordPress's sense, but
		// its headers, footers and popups land on every page at once, like
		// a theme's elements: a decision for the settings screen, where it
		// is offered under "Additional post types", not a default.
		if ( in_array( $type->name, array( 'attachment', 'elementor_library' ), true ) ) {
			continue;
		}
		$types[] = $type->name;
	}

	// A page is not public in the post-type sense but is always in scope.
	if ( ! in_array( 'page', $types, true ) && post_type_exists( 'page' ) ) {
		$types[] = 'page';
	}

	// Synced patterns are not a public post type, so they need saying so.
	if ( 'none' !== wpmcp_pattern_access() ) {
		$types[] = 'wp_block';
	}

	// What the site owner ticked under Tools > MCP Connector.
	$types = array_merge( $types, wpmcp_extra_post_types() );

	/**
	 * Post types the connector may touch.
	 *
	 * The settings screen covers the ordinary case; this is for anything a
	 * project decides in code.
	 *
	 * @param string[] $types Post type slugs.
	 */
	return array_values( array_unique( apply_filters( 'wpmcp_allowed_post_types', $types ) ) );
}
