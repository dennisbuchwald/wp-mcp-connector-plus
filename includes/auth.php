<?php
/**
 * Authentication: dedicated AI role with minimal capabilities, and a
 * surgical re-enable of Application Passwords for that role only.
 *
 * Context: some hardened setups (and security plugins) disable Application
 * Passwords globally. We keep that stance for every human user and
 * open exactly one slit: users holding the wpmcp_ai_editor role may use
 * Application Passwords, and only over HTTPS. The slit is narrow on the
 * other side too: the agent account reaches the MCP endpoint and nothing
 * else, neither the rest of the REST API nor XML-RPC, and it cannot manage
 * its own account or credentials.
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
 * edit_published_posts/pages are part of the full level only; the role's
 * capabilities follow the setting (see wpmcp_sync_role_capabilities).
 */
function wpmcp_register_role() {
	// Re-create on every activation so cap changes ship with updates.
	remove_role( WPMCP_ROLE );
	add_role(
		WPMCP_ROLE,
		__( 'AI Editor', 'wp-mcp-connector-plus' ),
		wpmcp_level_capabilities( wpmcp_access_level() )
	);
}

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
 * Does the user hold the agent role itself?
 *
 * Narrower than wpmcp_is_ai_user(), which also counts an administrator
 * given the marker capability for debugging. The fences below are about
 * the agent account: an administrator who ticked a box in a role editor
 * must not find the rest of the REST API or their own profile closed.
 *
 * @param int|\WP_User|object $user User ID or object.
 * @return bool
 */
function wpmcp_holds_agent_role( $user ) {
	if ( ! is_object( $user ) ) {
		$user = get_userdata( (int) $user );
	}
	if ( ! $user || ! isset( $user->roles ) ) {
		return false;
	}
	return in_array( WPMCP_ROLE, (array) $user->roles, true );
}

/**
 * The one REST route the agent account exists for.
 */
const WPMCP_MCP_ROUTE = '/wpmcp/v1/mcp';

/**
 * The REST route of the request being served.
 *
 * rest_authentication_errors runs before WordPress has matched a route, so
 * there is no request object to ask. The route is already decided by then,
 * though: rest_api_loaded() reads it from this query var and hands exactly
 * that string to the server. Reading the same place means judging the same
 * route WordPress is about to dispatch.
 *
 * @return string Empty when unknown.
 */
function wpmcp_current_rest_route() {
	global $wp;
	if ( is_object( $wp ) && isset( $wp->query_vars['rest_route'] ) && is_string( $wp->query_vars['rest_route'] ) ) {
		return $wp->query_vars['rest_route'];
	}
	return '';
}

/**
 * May the agent account call this REST route?
 *
 * Only the MCP endpoint. Compared exactly, after the same trailing-slash
 * trim WordPress applies: a spelling WordPress would still route to the
 * endpoint but that does not match here is refused, never the other way
 * round. Route matching in WordPress is case-insensitive, so this is too.
 *
 * @param string $route REST route, e.g. "/wpmcp/v1/mcp".
 * @return bool
 */
function wpmcp_agent_may_use_route( $route ) {
	$route = strtolower( rtrim( (string) $route, '/' ) );
	return WPMCP_MCP_ROUTE === $route;
}

/**
 * Keep the agent account on its own endpoint.
 *
 * An application password authenticates against the whole REST API, not
 * against one plugin's route. Without this fence the agent's credential
 * reaches /wp/v2 as well: users, settings it has caps for, the media
 * library, its own application passwords, and every route any other plugin
 * registers. None of that runs through this plugin's validation, its
 * access levels or its audit log.
 *
 * So the agent account gets exactly one route. Everything it does goes
 * through the MCP endpoint, where the tools decide what is allowed, and
 * nothing it does bypasses them. Internal REST calls made while a tool runs
 * are untouched: this filter only runs for the request as it arrived.
 *
 * Runs late so an error from an earlier check (a wrong password, say)
 * reaches the client unchanged.
 *
 * @param \WP_Error|null|true $result Result of the earlier checks.
 * @return \WP_Error|null|true
 */
function wpmcp_rest_scope( $result ) {
	if ( is_wp_error( $result ) || ! is_user_logged_in() ) {
		return $result;
	}

	if ( ! wpmcp_holds_agent_role( wp_get_current_user() ) ) {
		return $result;
	}

	$route = wpmcp_current_rest_route();
	if ( wpmcp_agent_may_use_route( $route ) ) {
		return $result;
	}

	return new \WP_Error(
		'wpmcp_rest_scope',
		sprintf(
			'This account is the MCP Connector agent and may only use the MCP endpoint %s. The route %s is closed to it, so that nothing the agent does bypasses the connector\'s checks and audit log.',
			WPMCP_MCP_ROUTE,
			'' === $route ? '(unknown)' : $route
		),
		array( 'status' => 403 )
	);
}
add_filter( 'rest_authentication_errors', 'wpmcp_rest_scope', 999 );

/**
 * Is this an XML-RPC request?
 *
 * @return bool
 */
function wpmcp_is_xmlrpc_request() {
	return defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST;
}

/**
 * No XML-RPC for the agent account.
 *
 * Application passwords authenticate XML-RPC too, which is an older and
 * wider API than REST and has none of the connector's checks. The agent
 * never needs it. Hooked on authenticate, which XML-RPC logins go through
 * whether the credential is a password or an application password; REST
 * authentication takes another path and is not affected.
 *
 * @param \WP_User|\WP_Error|null $user Result of the earlier checks.
 * @return \WP_User|\WP_Error|null
 */
function wpmcp_deny_agent_xmlrpc( $user ) {
	if ( ! wpmcp_is_xmlrpc_request() ) {
		return $user;
	}
	if ( $user instanceof \WP_User && wpmcp_holds_agent_role( $user ) ) {
		return new \WP_Error(
			'wpmcp_xmlrpc',
			'This account is the MCP Connector agent and cannot use XML-RPC.'
		);
	}
	return $user;
}
add_filter( 'authenticate', 'wpmcp_deny_agent_xmlrpc', 999 );

/**
 * Account capabilities the agent account never holds, on itself or anyone.
 *
 * WordPress lets every user edit their own profile and manage their own
 * application passwords without any capability at all. For the agent that
 * means: a leaked credential mints more credentials, changes the account's
 * e-mail address and so its password reset, and survives revoking the one
 * that leaked. The role management caps are listed too, so that a role
 * editor ticking them cannot hand them over either.
 *
 * @return string[]
 */
function wpmcp_agent_denied_account_caps() {
	return array(
		'edit_user',
		'edit_users',
		'promote_user',
		'promote_users',
		'remove_user',
		'remove_users',
		'delete_user',
		'delete_users',
		'create_users',
		'add_users',
		'list_users',
		'create_app_password',
		'list_app_passwords',
		'read_app_password',
		'edit_app_password',
		'delete_app_password',
		'delete_app_passwords',
	);
}

/**
 * Close the account capabilities for the agent account.
 *
 * Decided by who is acting, not by whose account is the target: an
 * administrator creating the agent's application password from the
 * connector's own screen is unaffected.
 *
 * @param string[] $caps    Primitive capabilities the check requires.
 * @param string   $cap     Capability being checked.
 * @param int      $user_id User being checked.
 * @return string[]
 */
function wpmcp_restrict_agent_account( $caps, $cap, $user_id ) {
	if ( ! in_array( $cap, wpmcp_agent_denied_account_caps(), true ) ) {
		return $caps;
	}
	if ( ! wpmcp_holds_agent_role( (int) $user_id ) ) {
		return $caps;
	}
	return array( 'do_not_allow' );
}
add_filter( 'map_meta_cap', 'wpmcp_restrict_agent_account', 999, 3 );

/**
 * Can application passwords travel safely on this site?
 *
 * The same test WordPress makes by default: HTTPS, or an environment
 * declared local. An application password is sent with every request, so
 * over plain HTTP it is readable by anyone on the way.
 *
 * @return bool
 */
function wpmcp_app_passwords_transport_safe() {
	if ( function_exists( 'wp_is_application_passwords_supported' ) ) {
		return (bool) wp_is_application_passwords_supported();
	}
	return is_ssl() || ( function_exists( 'wp_get_environment_type' ) && 'local' === wp_get_environment_type() );
}

/**
 * What the site said about application passwords before this plugin did.
 *
 * Recorded on the way through the global filter, one step before the
 * plugin's own override, so human accounts can be given exactly that
 * answer back.
 *
 * @param bool|null $set Value to record; null only reads.
 * @return bool|null Null when the global filter has not run yet.
 */
function wpmcp_app_passwords_available_before( $set = null ) {
	static $before = null;
	if ( null !== $set ) {
		$before = (bool) $set;
	}
	return $before;
}

/**
 * Application Passwords, step 0: remember the site's own answer.
 *
 * @param bool $available Availability as decided so far.
 * @return bool Unchanged.
 */
function wpmcp_note_app_password_availability( $available ) {
	wpmcp_app_passwords_available_before( (bool) $available );
	return $available;
}
add_filter( 'wp_is_application_passwords_available', 'wpmcp_note_app_password_availability', 99 );

/**
 * Application Passwords, step 1: reopen them globally where the site's
 * hardening switched them off, so the per-user check runs at all.
 * WordPress calls wp_is_application_passwords_available() before the
 * per-user filter; a global false short-circuits everything.
 *
 * Only where they can travel safely. Until 0.19 this returned true
 * unconditionally and so also overrode WordPress's own HTTPS requirement.
 *
 * @param bool $available Availability as decided so far.
 * @return bool
 */
function wpmcp_reopen_app_passwords( $available ) {
	if ( $available ) {
		return true;
	}
	return wpmcp_app_passwords_transport_safe();
}
add_filter( 'wp_is_application_passwords_available', 'wpmcp_reopen_app_passwords', 100 );

/**
 * Application Passwords, step 2: the reopening is for the agent only.
 *
 * The agent gets them wherever they can travel safely. Every human account
 * gets exactly what it had before this plugin: off for everyone where the
 * site's hardening switched them off, and otherwise whatever WordPress and
 * the other plugins decided for that user. Until 0.19 this returned false
 * for every human, which also broke the mobile app and automations on
 * sites that had never disabled the feature.
 *
 * @param bool     $available Whether available for the user.
 * @param \WP_User $user      The user.
 * @return bool
 */
function wpmcp_app_passwords_for_user( $available, $user ) {
	if ( wpmcp_is_ai_user( $user ) ) {
		return wpmcp_app_passwords_transport_safe();
	}
	if ( false === wpmcp_app_passwords_available_before() ) {
		return false;
	}
	return $available;
}
add_filter( 'wp_is_application_passwords_available_for_user', 'wpmcp_app_passwords_for_user', 100, 2 );

/**
 * Transport-level permission for the MCP endpoint: authenticated AI user
 * (or an admin explicitly given the marker cap). Runs before any tool call.
 *
 * @return bool
 */
function wpmcp_transport_permission() {
	// A session that ran out between two requests has to close here too:
	// nothing else runs on this endpoint, and waiting for someone to open
	// wp-admin would leave the capabilities wide for as long as nobody does.
	if ( function_exists( 'wpmcp_close_expired_work_session' ) ) {
		wpmcp_close_expired_work_session();
	}

	return is_user_logged_in() && current_user_can( WPMCP_CAP );
}
