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
			'description' => __( 'The agent can look at the site and explain it. No write tools exist at all. Note: published content only — WordPress requires an editing capability to read drafts, so drafts stay invisible at this level.', 'wp-mcp-connector-plus' ),
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

	// A work session opens everything for a fixed window. The constant
	// above still wins: a site that fixed the level in wp-config meant it.
	if ( wpmcp_work_session_active() ) {
		return 'full';
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

	if ( ! defined( 'WPMCP_PATTERN_ACCESS' ) && wpmcp_work_session_active() ) {
		return 'write';
	}

	// Patterns can never be more open than the site as a whole.
	if ( 'write' === $value && ! wpmcp_can_write() ) {
		return 'read';
	}

	return $value;
}

/**
 * Post types the site owner has added by hand.
 *
 * Public post types and pages are in scope on their own. A theme's
 * site-wide building blocks — headers, footers, hooks, content templates —
 * are not public and stay out until someone says otherwise, because a
 * change there lands on every page at once.
 *
 * That "otherwise" belongs in the admin next to the other decisions, not
 * in a functions.php: editing code on a customer site to tick a box is a
 * worse answer than a box.
 *
 * @return string[]
 */
function wpmcp_extra_post_types() {
	$stored = get_option( 'wpmcp_extra_post_types', array() );

	if ( ! is_array( $stored ) ) {
		return array();
	}

	// A post type can be deactivated with its plugin between two requests.
	$ticked = array_values( array_filter( array_map( 'strval', $stored ), 'post_type_exists' ) );

	// A session reaches the site's own building blocks without thirty ticks.
	// What was ticked by hand stays either way — including anything holding
	// personal data, which a session never adds on its own.
	if ( wpmcp_work_session_active() ) {
		return array_values( array_unique( array_merge( $ticked, wpmcp_session_post_types() ) ) );
	}

	return $ticked;
}

/**
 * Post types the site could add, with what they are called.
 *
 * Everything registered that is not already in scope, minus the ones that
 * never hold a block tree — revisions, menu items, the customizer's
 * scratch space and the like. Offering those would be noise in a list
 * whose whole job is to be short enough to read.
 *
 * @return array<string, string> Slug => label.
 */
function wpmcp_selectable_post_types() {
	$never   = wpmcp_never_offered_post_types();
	$already = wpmcp_allowed_post_types();
	$options = array();

	foreach ( get_post_types( array(), 'objects' ) as $type ) {
		if ( in_array( $type->name, $never, true ) || in_array( $type->name, $already, true ) ) {
			continue;
		}
		$options[ $type->name ] = $type->labels->name ?? $type->name;
	}

	// Anything already ticked stays listed, so it can be unticked.
	foreach ( wpmcp_extra_post_types() as $slug ) {
		if ( ! isset( $options[ $slug ] ) ) {
			$type              = get_post_type_object( $slug );
			$options[ $slug ] = $type ? ( $type->labels->name ?? $slug ) : $slug;
		}
	}

	ksort( $options );

	return $options;
}

/**
 * Post types that never hold a block tree, so offering them is noise.
 *
 * @return string[]
 */
function wpmcp_never_offered_post_types() {
	return array(
		'attachment',
		'revision',
		'nav_menu_item',
		'custom_css',
		'customize_changeset',
		'oembed_cache',
		'user_request',
		'wp_block',
	);
}

/**
 * What a work session adds to the scope on its own.
 *
 * Everything the site has, minus two kinds: what never holds a block tree,
 * and what holds other people's data. A session is a window on this site's
 * own building blocks — headers, templates, field groups — so that an hour
 * of work does not begin with thirty ticks.
 *
 * Orders, subscriptions and form entries are not that. They are somebody
 * else's name and address, and no length of window turns reading them into
 * a side effect of working on a page. Those stay a tick somebody makes
 * deliberately, and a tick already made still counts: it was a decision.
 *
 * Computed without wpmcp_allowed_post_types(), which would call back into
 * the function this feeds.
 *
 * @return string[]
 */
function wpmcp_session_post_types() {
	$never = wpmcp_never_offered_post_types();
	$types = array();

	foreach ( get_post_types( array(), 'objects' ) as $type ) {
		if ( in_array( $type->name, $never, true ) ) {
			continue;
		}
		if ( wpmcp_post_type_holds_personal_data( $type->name ) ) {
			continue;
		}
		$types[] = $type->name;
	}

	return $types;
}

/**
 * Does this post type usually hold other people's personal data?
 *
 * A shop lists thirty post types on the settings screen, and three or
 * four of them are orders: names, addresses, what someone bought. Ticking
 * a box there is not the same decision as ticking "Elements", and on a
 * list that long nobody reads thirty labels before clicking select-all.
 *
 * A guess by name, so it errs towards warning. It changes nothing about
 * what is allowed — it only stops the two kinds of box looking alike.
 *
 * @param string $slug Post type slug.
 * @return bool
 */
function wpmcp_post_type_holds_personal_data( $slug ) {
	$patterns = array(
		'#^shop_order#i',
		'#(^|_)order(s)?($|_)#i',
		'#subscription#i',
		'#(^|_)customer#i',
		'#(entry|entries|submission|lead)#i',
		// Flamingo stores contact-form submissions under its own names.
		'#flamingo#i',
		'#user_request#i',
		'#booking|appointment|reservation#i',
		'#invoice|refund|payment#i',
	);

	foreach ( $patterns as $pattern ) {
		if ( preg_match( $pattern, $slug ) ) {
			return true;
		}
	}

	return false;
}

/**
 * May the agent save pages whose blocks carry dynamic data?
 *
 * A separate decision from the access level, for the same reason synced
 * patterns are: a different risk, not a wider one. Some block libraries
 * gate dynamic data behind unfiltered_html — the capability that lets an
 * account store arbitrary HTML and JavaScript. Whole service and industry
 * pages are unwritable without it, which is most of what this connector
 * exists for, and the alternative people reach for is editing the
 * database around the API, where nothing is checked at all.
 *
 * Off by default. When on, the capability is granted for the length of a
 * single save and never sits on the role.
 *
 * @return bool
 */
function wpmcp_dynamic_data_allowed() {
	if ( defined( 'WPMCP_DYNAMIC_DATA' ) ) {
		return (bool) WPMCP_DYNAMIC_DATA;
	}

	// Meaningless without write access in the first place.
	if ( ! wpmcp_can_write() ) {
		return false;
	}

	if ( wpmcp_work_session_active() ) {
		return true;
	}

	return 'allowed' === get_option( 'wpmcp_dynamic_data', 'blocked' );
}

/**
 * A work session: everything open, and it closes itself.
 *
 * The settings exist because the wide ones are dangerous. What actually
 * happens is that someone opens them for an afternoon of work and never
 * closes them again — so the site sits on the widest setting permanently,
 * which is exactly what the settings were meant to prevent.
 *
 * A session inverts that. It opens the same doors, and the closing is not
 * a thing anyone has to remember: it is a timestamp. Nothing here can be
 * left on by accident, only by choosing a longer window.
 *
 * What it does not touch: the post types, because which content is in
 * scope is not a risk window but a decision about the site — and on a shop
 * that list contains other people's orders. And publishing, which no
 * setting in this plugin has ever been able to reach.
 *
 * @return int Unix timestamp the session ends at, 0 when none is running.
 */
function wpmcp_work_session_expires() {
	$until = (int) get_option( 'wpmcp_work_session_until', 0 );

	return ( $until > time() ) ? $until : 0;
}

/**
 * Is a work session running right now?
 *
 * @return bool
 */
function wpmcp_work_session_active() {
	return wpmcp_work_session_expires() > 0;
}

/**
 * How much of it is left, for a human.
 *
 * @return string
 */
function wpmcp_work_session_remaining() {
	$until = wpmcp_work_session_expires();
	if ( ! $until ) {
		return '';
	}

	return human_time_diff( time(), $until );
}

/**
 * Windows a session can be opened for.
 *
 * Deliberately short. An open-ended option would be the permanent setting
 * again, wearing a different label.
 *
 * @return array<int, string> Hours => label.
 */
function wpmcp_work_session_lengths() {
	return array(
		1 => __( '1 hour', 'wp-mcp-connector-plus' ),
		4 => __( '4 hours', 'wp-mcp-connector-plus' ),
		8 => __( '8 hours', 'wp-mcp-connector-plus' ),
	);
}

/**
 * Open a session, or extend the one running.
 *
 * @param int $hours How long.
 * @return int The timestamp it now ends at.
 */
function wpmcp_start_work_session( $hours ) {
	$hours = (int) $hours;
	if ( ! array_key_exists( $hours, wpmcp_work_session_lengths() ) ) {
		$hours = 1;
	}

	$until = time() + ( $hours * HOUR_IN_SECONDS );
	update_option( 'wpmcp_work_session_until', $until, false );

	// The role has to follow, or the second line of defence is still narrow
	// while the first one is open.
	wpmcp_sync_role_capabilities();

	wpmcp_log(
		'wpmcp/work-session',
		array(
			'summary' => sprintf(
				'Work session opened for %d hour(s), everything wide until %s UTC.',
				$hours,
				gmdate( 'Y-m-d H:i', $until )
			),
		)
	);

	return $until;
}

/**
 * Close it now, without waiting for the clock.
 */
function wpmcp_end_work_session() {
	if ( ! wpmcp_work_session_active() ) {
		return;
	}

	delete_option( 'wpmcp_work_session_until' );
	wpmcp_sync_role_capabilities();

	wpmcp_log( 'wpmcp/work-session', array( 'summary' => 'Work session closed.' ) );
}

/**
 * Put the role back where the settings say, once a session has run out.
 *
 * The level drops on its own — it is read fresh every request — but the
 * capabilities are stored, and nothing writes them back unless somebody
 * opens wp-admin. This runs on the connector's own endpoint too, so the
 * second line of defence narrows at the same moment the first one does.
 */
function wpmcp_close_expired_work_session() {
	if ( ! get_option( 'wpmcp_work_session_until', 0 ) || wpmcp_work_session_active() ) {
		return;
	}

	delete_option( 'wpmcp_work_session_until' );
	wpmcp_sync_role_capabilities();

	wpmcp_log( 'wpmcp/work-session', array( 'summary' => 'Work session expired; everything back to the saved settings.' ) );
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
		'wpmcp/content-revisions',
		'wpmcp/content-search',
		'wpmcp/content-fetch-live',
		'wpmcp/media-list',
		'wpmcp/media-read',
	);

	if ( ! wpmcp_can_write() ) {
		return $read;
	}

	$write = array(
		'wpmcp/content-write',
		'wpmcp/content-batch',
		'wpmcp/content-create',
		'wpmcp/content-duplicate',
		'wpmcp/content-restore',
		'wpmcp/media-update',
	);

	// Only while a work session is open: outside one the tool does not exist.
	if ( wpmcp_work_session_active() ) {
		$write[] = 'wpmcp/media-upload';
	}

	return array_merge( $read, $write );
}

/**
 * The page the site has designated as its privacy policy, if any.
 *
 * @return int Post ID, or 0.
 */
function wpmcp_privacy_policy_page_id() {
	return (int) get_option( 'wp_page_for_privacy_policy' );
}

/**
 * Let the agent edit the privacy policy page like any other published page.
 *
 * WordPress guards that one page with manage_privacy_options. That is a
 * meta capability: it maps to manage_options (manage_network on multisite),
 * which is full site administration. Granting it to the agent so it can fix
 * a paragraph would hand over the entire site — settings, plugins, users.
 *
 * So the admin requirement is dropped for exactly one check instead:
 * editing that one page, by the agent, at the level that already allows
 * editing published pages. Deleting is never touched, and every other
 * requirement of the edit — edit_others_pages, edit_published_pages —
 * stays in force. The page ends up neither better nor worse protected
 * than the rest of the site.
 *
 * @param string[] $caps    Primitive capabilities the check requires.
 * @param string   $cap     Capability being checked.
 * @param int      $user_id User being checked.
 * @param array    $args    Context; $args[0] is the post ID.
 * @return string[]
 */
function wpmcp_allow_privacy_policy_edit( $caps, $cap, $user_id, $args ) {
	if ( 'edit_post' !== $cap && 'edit_page' !== $cap ) {
		return $caps;
	}

	$post_id = isset( $args[0] ) ? (int) $args[0] : 0;
	if ( ! $post_id || wpmcp_privacy_policy_page_id() !== $post_id ) {
		return $caps;
	}

	if ( ! wpmcp_live_edit_enabled() || ! wpmcp_is_ai_user( $user_id ) ) {
		return $caps;
	}

	/**
	 * Whether the agent may edit the designated privacy policy page.
	 *
	 * Return false to keep WordPress's administrator requirement, which
	 * puts that page out of the agent's reach entirely.
	 *
	 * @param bool $allow   Default true at the full access level.
	 * @param int  $post_id The privacy policy page.
	 * @param int  $user_id The user being checked.
	 */
	if ( ! apply_filters( 'wpmcp_allow_privacy_policy_edit', true, $post_id, (int) $user_id ) ) {
		return $caps;
	}

	$admin = is_multisite() ? 'manage_network' : 'manage_options';

	return array_values( array_diff( (array) $caps, array( $admin ) ) );
}
add_filter( 'map_meta_cap', 'wpmcp_allow_privacy_policy_edit', 10, 4 );

/**
 * Do the role's capabilities match the configured level?
 *
 * @return bool True also when the role is missing, so callers can tell
 *              "in step" from "cannot tell" via wpmcp_agent_role_exists().
 */
function wpmcp_role_caps_match() {
	$role = get_role( WPMCP_ROLE );
	if ( ! $role ) {
		return false;
	}

	$wanted = wpmcp_level_capabilities( wpmcp_access_level() );
	$actual = array_keys( array_filter( (array) $role->capabilities ) );

	sort( $actual );
	$expected = array_keys( $wanted );
	sort( $expected );

	return $actual === $expected;
}

/**
 * Keep the role's capabilities in step with the access level.
 *
 * Hooked to both add_option and update_option: WordPress fires
 * update_option_{$option} only when the option already existed, so on any
 * site upgrading from a version that had no such setting the first save
 * creates it and the update hook never runs. That left the switch visibly
 * flipped and practically inert.
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
add_action( 'add_option_wpmcp_access_level', 'wpmcp_sync_role_capabilities' );

/**
 * Last line of defence: reconcile on any admin request.
 *
 * Hooks can be missed — a level set by constant, an option written
 * directly, a role edited by another plugin. Comparing is cheap (roles
 * live in one cached option) and it only writes when something actually
 * drifted, so the setting can never quietly mean less than it says.
 */
function wpmcp_reconcile_role() {
	if ( ! wpmcp_role_caps_match() && get_role( WPMCP_ROLE ) ) {
		wpmcp_sync_role_capabilities();
	}
}
add_action( 'admin_init', 'wpmcp_reconcile_role' );
add_action( 'admin_init', 'wpmcp_close_expired_work_session' );
