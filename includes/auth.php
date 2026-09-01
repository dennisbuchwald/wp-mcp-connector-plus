<?php
/**
 * Authentication: dedicated AI role with minimal capabilities, and a
 * surgical re-enable of Application Passwords for that role only.
 *
 * Context: some hardened setups (and security plugins) disable Application
 * Passwords globally. We keep that stance for every human user and
 * open exactly one slit: users holding the wpmcp_ai_editor role may use
 * Application Passwords. XML-RPC, login hiding and REST discovery
 * hardening stay untouched.
 *
 * @package wp-mcp-connector-plus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const WPMCP_ROLE = 'wpmcp_ai_editor';

/**
 * Marker capability — everything connector-specific checks this, so access
 * can also be granted to an admin for debugging via a role editor.
 */
const WPMCP_CAP = 'wpmcp_access';

/**
 * Register the AI editor role. Draft-only by design: no publish_*, no
 * delete_*, no upload_files, no settings. Publishing stays human.
 *
 * edit_published_posts/pages are granted at role level but stripped at
 * runtime unless live-edit is enabled (see wpmcp_filter_caps).
 */
function wpmcp_register_role() {
	// Re-create on every activation so cap changes ship with updates.
	remove_role( WPMCP_ROLE );
	add_role(
		WPMCP_ROLE,
		__( 'AI Editor', 'wp-mcp-connector-plus' ),
		array(
			'read'                  => true,
			WPMCP_CAP              => true,
			'edit_posts'            => true,
			'edit_others_posts'     => true,
			'edit_published_posts'  => true,
			'edit_pages'            => true,
			'edit_others_pages'     => true,
			'edit_published_pages'  => true,
		)
	);
}

/**
 * Live-edit toggle. Default off: the AI can only touch drafts and create
 * new drafts; published content is read-only for it.
 *
 * @return bool
 */
function wpmcp_live_edit_enabled() {
	if ( defined( 'WPMCP_LIVE_EDIT' ) ) {
		return (bool) WPMCP_LIVE_EDIT;
	}
	return (bool) get_option( 'wpmcp_live_edit', false );
}

/**
 * Strip edit_published_* from AI users at runtime while live-edit is off.
 * Runtime filter instead of role surgery: stateless, survives role resets.
 *
 * @param array    $allcaps All capabilities of the user.
 * @param array    $caps    Required primitive capabilities.
 * @param array    $args    Arguments.
 * @param \WP_User $user    The user object.
 * @return array
 */
function wpmcp_filter_caps( $allcaps, $caps, $args, $user ) {
	if ( empty( $allcaps[ WPMCP_CAP ] ) ) {
		return $allcaps;
	}
	if ( ! wpmcp_live_edit_enabled() ) {
		unset( $allcaps['edit_published_posts'], $allcaps['edit_published_pages'] );
	}
	return $allcaps;
}
add_filter( 'user_has_cap', 'wpmcp_filter_caps', 10, 4 );

/**
 * Is the given user an AI connector user?
 *
 * @param int|\WP_User $user User ID or object.
 * @return bool
 */
function wpmcp_is_ai_user( $user ) {
	$user = is_object( $user ) ? $user : get_userdata( (int) $user );
	if ( ! $user instanceof \WP_User ) {
		return false;
	}
	return ! empty( $user->allcaps[ WPMCP_CAP ] ) || in_array( WPMCP_ROLE, (array) $user->roles, true );
}

/**
 * Application Passwords, step 1: flip global availability back on — after
 * the core hardening filter (priority 10) — so the per-user check runs at all.
 * WordPress calls wp_is_application_passwords_available() before the
 * per-user filter; a global false short-circuits everything.
 */
add_filter( 'wp_is_application_passwords_available', '__return_true', 100 );

/**
 * Application Passwords, step 2: restrict to AI users. This filter governs
 * both authentication and the profile UI, so for every human user the
 * feature stays exactly as dead as the core hardening intends.
 *
 * @param bool     $available Whether available for the user.
 * @param \WP_User $user      The user.
 * @return bool
 */
function wpmcp_app_passwords_for_user( $available, $user ) {
	return wpmcp_is_ai_user( $user );
}
add_filter( 'wp_is_application_passwords_available_for_user', 'wpmcp_app_passwords_for_user', 100, 2 );

/**
 * Transport-level permission for the MCP endpoint: authenticated AI user
 * (or an admin explicitly given the marker cap). Runs before any tool call.
 *
 * @return bool
 */
function wpmcp_transport_permission() {
	return is_user_logged_in() && current_user_can( WPMCP_CAP );
}
