<?php
/**
 * Authentication: dedicated AI role with minimal capabilities, and a
 * surgical re-enable of Application Passwords for that role only.
 *
 * Context: dbw-base-core hard-disables Application Passwords globally
 * (security-hardening.php). We keep that stance for every human user and
 * open exactly one slit: users holding the dbw_ai_editor role may use
 * Application Passwords. XML-RPC, login hiding and REST discovery
 * hardening stay untouched.
 *
 * @package dbw-connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const DBW_CONNECTOR_ROLE = 'dbw_ai_editor';

/**
 * Marker capability — everything connector-specific checks this, so access
 * can also be granted to an admin for debugging via a role editor.
 */
const DBW_CONNECTOR_CAP = 'dbw_connector_access';

/**
 * Register the AI editor role. Draft-only by design: no publish_*, no
 * delete_*, no upload_files, no settings. Publishing stays human.
 *
 * edit_published_posts/pages are granted at role level but stripped at
 * runtime unless live-edit is enabled (see dbw_connector_filter_caps).
 */
function dbw_connector_register_role() {
	// Re-create on every activation so cap changes ship with updates.
	remove_role( DBW_CONNECTOR_ROLE );
	add_role(
		DBW_CONNECTOR_ROLE,
		'dbw KI-Redakteur',
		array(
			'read'                  => true,
			DBW_CONNECTOR_CAP       => true,
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
function dbw_connector_live_edit_enabled() {
	if ( defined( 'DBW_CONNECTOR_LIVE_EDIT' ) ) {
		return (bool) DBW_CONNECTOR_LIVE_EDIT;
	}
	return (bool) get_option( 'dbw_connector_live_edit', false );
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
function dbw_connector_filter_caps( $allcaps, $caps, $args, $user ) {
	if ( empty( $allcaps[ DBW_CONNECTOR_CAP ] ) ) {
		return $allcaps;
	}
	if ( ! dbw_connector_live_edit_enabled() ) {
		unset( $allcaps['edit_published_posts'], $allcaps['edit_published_pages'] );
	}
	return $allcaps;
}
add_filter( 'user_has_cap', 'dbw_connector_filter_caps', 10, 4 );

/**
 * Is the given user an AI connector user?
 *
 * @param int|\WP_User $user User ID or object.
 * @return bool
 */
function dbw_connector_is_ai_user( $user ) {
	$user = is_object( $user ) ? $user : get_userdata( (int) $user );
	if ( ! $user instanceof \WP_User ) {
		return false;
	}
	return ! empty( $user->allcaps[ DBW_CONNECTOR_CAP ] ) || in_array( DBW_CONNECTOR_ROLE, (array) $user->roles, true );
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
function dbw_connector_app_passwords_for_user( $available, $user ) {
	return dbw_connector_is_ai_user( $user );
}
add_filter( 'wp_is_application_passwords_available_for_user', 'dbw_connector_app_passwords_for_user', 100, 2 );

/**
 * Transport-level permission for the MCP endpoint: authenticated AI user
 * (or an admin explicitly given the marker cap). Runs before any tool call.
 *
 * @return bool
 */
function dbw_connector_transport_permission() {
	return is_user_logged_in() && current_user_can( DBW_CONNECTOR_CAP );
}
