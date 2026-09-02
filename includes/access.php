<?php
/**
 * What the agent is allowed to do, decided by the site owner.
 *
 * The guiding rule: anything not permitted is never registered. In read
 * mode the write abilities do not exist as MCP tools at all, rather than
 * existing and refusing — a tool that cannot be called is a stronger
 * guarantee than one that checks, and it costs the agent no context.
 *
 * Capabilities follow the same setting, so WordPress enforces the same
 * boundary a second time, independently of this plugin's own logic.
 *
 * One line is not configurable: the agent can never publish. Every level
 * leaves that with a human.
 *
 * @package wp-mcp-connector-plus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Access levels, widest last.
 *
 * @return array<string, array{label: string, description: string}>
 */
function wpmcp_access_levels() {
	return array(
		'read'  => array(
			'label'       => __( 'Read only', 'wp-mcp-connector-plus' ),
			'description' => __( 'The agent can look at the site and explain it. No write tools exist at all.', 'wp-mcp-connector-plus' ),
		),
		'draft' => array(
			'label'       => __( 'Drafts', 'wp-mcp-connector-plus' ),
			'description' => __( 'The agent can create new pages, duplicate existing ones and edit drafts. Published pages are read-only to it.', 'wp-mcp-connector-plus' ),
		),
		'full'  => array(
			'label'       => __( 'Drafts and published pages', 'wp-mcp-connector-plus' ),
			'description' => __( 'As above, and the agent may change published pages directly. Every change still creates a revision.', 'wp-mcp-connector-plus' ),
		),
	);
}

/**
 * The configured access level.
 *
 * @return string
 */
function wpmcp_access_level() {
	if ( defined( 'WPMCP_ACCESS_LEVEL' ) && array_key_exists( WPMCP_ACCESS_LEVEL, wpmcp_access_levels() ) ) {
		return WPMCP_ACCESS_LEVEL;
	}

	$level = get_option( 'wpmcp_access_level', null );

	// Migrate the older boolean switch on first read.
	if ( null === $level ) {
		$level = get_option( 'wpmcp_live_edit' ) ? 'full' : 'draft';
	}

	return array_key_exists( $level, wpmcp_access_levels() ) ? $level : 'draft';
}

/**
 * May the agent write at all?
 *
 * @return bool
 */
function wpmcp_can_write() {
	return 'read' !== wpmcp_access_level();
}

/**
 * May the agent change published content?
 *
 * @return bool
 */
function wpmcp_live_edit_enabled() {
	return 'full' === wpmcp_access_level();
}

/**
 * How the agent may treat synced patterns (reusable blocks).
 *
 * Kept separate from the access level on purpose: editing a pattern
 * changes every page that embeds it at once, which is a different blast
 * radius from editing one page, and warrants its own decision.
 *
 * @return string 'none' | 'read' | 'write'
 */
function wpmcp_pattern_access() {
	if ( defined( 'WPMCP_PATTERN_ACCESS' ) ) {
		$value = WPMCP_PATTERN_ACCESS;
	} else {
		$value = get_option( 'wpmcp_pattern_access', 'read' );
	}

	if ( ! in_array( $value, array( 'none', 'read', 'write' ), true ) ) {
		return 'read';
	}

	// Patterns can never be more open than the site as a whole.
	if ( 'write' === $value && ! wpmcp_can_write() ) {
		return 'read';
	}

	return $value;
}

/**
 * Capabilities for a given access level.
 *
 * Never contains publish_*, delete_*, upload_files or manage_options —
 * not at any level, not through any setting.
 *
 * @param string $level Access level.
 * @return array<string, bool>
 */
function wpmcp_level_capabilities( $level ) {
	$caps = array(
		'read'            => true,
		WPMCP_CAP         => true,
	);

	if ( 'read' === $level ) {
		// Reading published content needs no editing capability at all.
		return $caps;
	}

	$caps['edit_posts']        = true;
	$caps['edit_others_posts'] = true;
	$caps['edit_pages']        = true;
	$caps['edit_others_pages'] = true;

	if ( 'full' === $level ) {
		$caps['edit_published_posts'] = true;
		$caps['edit_published_pages'] = true;
	}

	return $caps;
}

/**
 * Abilities available at the current access level.
 *
 * @return string[]
 */
function wpmcp_ability_names() {
	$read = array(
		'wpmcp/site-info',
		'wpmcp/blocks-catalog',
		'wpmcp/blocks-describe',
		'wpmcp/content-list',
		'wpmcp/content-read',
		'wpmcp/content-preview',
	);

	if ( ! wpmcp_can_write() ) {
		return $read;
	}

	return array_merge(
		$read,
		array(
			'wpmcp/content-write',
			'wpmcp/content-duplicate',
		)
	);
}

/**
 * Keep the role's capabilities in step with the access level.
 *
 * Runs on the settings save and on activation, so the role always matches
 * what the settings screen claims.
 */
function wpmcp_sync_role_capabilities() {
	$role = get_role( WPMCP_ROLE );
	if ( ! $role ) {
		wpmcp_register_role();
		return;
	}

	$wanted = wpmcp_level_capabilities( wpmcp_access_level() );

	// Remove anything no longer granted, then add what is.
	foreach ( array_keys( (array) $role->capabilities ) as $cap ) {
		if ( ! isset( $wanted[ $cap ] ) ) {
			$role->remove_cap( $cap );
		}
	}
	foreach ( array_keys( $wanted ) as $cap ) {
		$role->add_cap( $cap );
	}
}
add_action( 'update_option_wpmcp_access_level', 'wpmcp_sync_role_capabilities' );
