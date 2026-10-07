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
 * boundary a second time, independently of this plugin's own logic. They
 * are worked out on every check rather than stored on the role, so a
 * setting or a session that ended cannot leave anything behind.
 *
 * One line is not configurable: no level lets the agent publish or upload.
 * Both need a work session, which only a human opens and which closes
 * itself; outside one, publishing stays with a human.
 *
 * @package wp-mcp-connector-plus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The settings screen needs the scope on requests where the tools never
// load, so it lives in a file of its own that is always there.
require_once __DIR__ . '/post-types.php';
require_once __DIR__ . '/builders.php';

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
 * The access level in force right now, a work session included.
 *
 * This is what the capabilities follow. The tool list does not: it
 * follows wpmcp_configured_access_level(), see wpmcp_ability_names().
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

	return wpmcp_configured_access_level();
}

/**
 * The access level the site owner set, without a work session lifting it.
 *
 * @return string
 */
function wpmcp_configured_access_level() {
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
 * The site's own building blocks: theme elements (headers, footers,
 * hooks), templates, template parts and navigation menus, so that an hour
 * of work does not begin with ticking boxes. Public post types are in
 * scope anyway and listed for completeness.
 *
 * An allowlist, not "everything minus a few". A non-public post type is
 * private for a reason the connector cannot see, and guessing it from the
 * name only works for the names someone thought of: orders, subscriptions
 * and form entries are somebody else's name and address, and so is
 * whatever the next plugin stores under a name nobody has heard of. Those
 * stay a tick somebody makes deliberately, and a tick already made still
 * counts: it was a decision.
 *
 * Computed without wpmcp_allowed_post_types(), which would call back into
 * the function this feeds.
 *
 * @return string[]
 */
function wpmcp_session_post_types() {
	$never = wpmcp_never_offered_post_types();
	$known = wpmcp_session_building_block_types();
	$types = array();

	foreach ( get_post_types( array(), 'objects' ) as $type ) {
		if ( in_array( $type->name, $never, true ) ) {
			continue;
		}
		if ( wpmcp_post_type_holds_personal_data( $type->name ) ) {
			continue;
		}
		if ( empty( $type->public ) && ! in_array( $type->name, $known, true ) ) {
			continue;
		}
		$types[] = $type->name;
	}

	return $types;
}

/**
 * Non-public post types a work session opens on its own.
 *
 * @return string[]
 */
function wpmcp_session_building_block_types() {
	$types = array( 'gp_elements', 'wp_template', 'wp_template_part', 'wp_navigation' );

	/**
	 * Non-public post types a work session brings into scope by itself.
	 *
	 * For a kit's own building blocks, the kind of post type where a
	 * change lands on many pages at once and holds nobody's personal data.
	 * Post types matching a personal-data pattern stay out regardless.
	 *
	 * @param string[] $types Post type slugs.
	 */
	return array_values( array_filter( (array) apply_filters( 'wpmcp_session_post_types', $types ), 'is_string' ) );
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
 * What it does not touch: post types beyond the site's own building
 * blocks, because which content is in scope is not a risk window but a
 * decision about the site, and on a shop that list contains other people's
 * orders.
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

	// The capabilities follow on their own (wpmcp_agent_capabilities works
	// them out on every check). Reconciling the role here as well keeps
	// anything an older version stored from outliving the switch.
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
 * Tidy up once a session has run out.
 *
 * Nothing depends on this any more: the level and the capabilities are
 * both worked out from the timestamp on every check, so they narrow the
 * second it passes. What is left to do is remove the stale entry and say
 * so in the log, on whichever request comes first.
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
 * This is what the agent effectively holds at a level. The role itself
 * stores only the read set (wpmcp_role_capabilities); the rest is added
 * per check by wpmcp_agent_capabilities.
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
 * What the agent role stores in the database.
 *
 * The read set and nothing more: the marker capability and read. Every
 * editing capability depends on the level and on a session, and both can
 * change without anyone opening wp-admin, so they are worked out on each
 * check instead (see wpmcp_agent_capabilities). Stored, they outlived
 * every change to either: a session that ran out at night kept the role
 * wide until somebody opened the admin the next morning.
 *
 * It also means the role is inert wherever this plugin is not running.
 *
 * @return array<string, bool>
 */
function wpmcp_role_capabilities() {
	return wpmcp_level_capabilities( 'read' );
}

/**
 * Capabilities that depend on the level or a session.
 *
 * @return string[]
 */
function wpmcp_level_dependent_capabilities() {
	return array_keys( array_diff_key( wpmcp_level_capabilities( 'full' ), wpmcp_role_capabilities() ) );
}

/**
 * Give the agent the capabilities of the level in force right now.
 *
 * Runs on every capability check for a user holding the agent role. The
 * level is read fresh, including whether a session is open, so nothing
 * has to be written back when either changes.
 *
 * For an account holding only the agent role, the level decides those
 * capabilities alone: whatever an older version stored on the role, or a
 * role editor put on the account, is taken away again. An account that
 * also holds another role keeps what that role grants; this only adds.
 *
 * Runs before the per-request grants (publishing in a session, uploads,
 * dynamic data), which hook in at a later priority and must win.
 *
 * @param array<string, bool> $allcaps Everything the user holds.
 * @param string[]            $caps    Capabilities being checked.
 * @param array               $args    Context.
 * @param \WP_User|object     $user    The user.
 * @return array<string, bool>
 */
function wpmcp_agent_capabilities( $allcaps, $caps, $args, $user ) {
	$roles = isset( $user->roles ) ? array_values( (array) $user->roles ) : array();
	if ( ! in_array( WPMCP_ROLE, $roles, true ) ) {
		return $allcaps;
	}

	if ( array( WPMCP_ROLE ) === $roles ) {
		foreach ( wpmcp_level_dependent_capabilities() as $cap ) {
			unset( $allcaps[ $cap ] );
		}
	}

	foreach ( wpmcp_level_capabilities( wpmcp_access_level() ) as $cap => $grant ) {
		$allcaps[ $cap ] = $grant;
	}

	return $allcaps;
}
add_filter( 'user_has_cap', 'wpmcp_agent_capabilities', 10, 4 );

/**
 * The abilities that change something. Everything else only reads.
 *
 * One list, so site-info, the annotations and the registration cannot
 * disagree about which tool is which.
 *
 * @return string[]
 */
function wpmcp_write_ability_names() {
	$names = array(
		'wpmcp/content-write',
		'wpmcp/content-batch',
		'wpmcp/content-create',
		'wpmcp/content-duplicate',
		'wpmcp/content-restore',
		'wpmcp/media-update',
		'wpmcp/media-upload',
		'wpmcp/elementor-write',
	);

	return wpmcp_offered_where_supported( $names );
}

/**
 * Abilities offered at the configured access level.
 *
 * The list depends on the level the site owner set and on nothing that
 * runs out by itself. MCP clients fetch the tool list once when they
 * connect, and the server announces no list changes; a tool that appears
 * when a work session opens is invisible to an agent already connected,
 * and one that disappears when the session ends leaves the agent calling
 * a name that no longer exists. So a session widens what the tools may do
 * (publish, upload, published pages), never which tools there are. A tool
 * that needs a session, media-upload, is always there at a write level
 * and refuses outside one with wpmcp_session_required. On a read-only site
 * a session adds no tools at all: that is a level change, not a window.
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
		'wpmcp/elementor-read',
	);

	$read = wpmcp_offered_where_supported( $read );

	if ( 'read' === wpmcp_configured_access_level() ) {
		return $read;
	}

	return array_merge( $read, wpmcp_write_ability_names() );
}

/**
 * Leave out the tools for a page builder this site does not run.
 *
 * The Elementor tools exist where Elementor runs, and nowhere else: on
 * any other site they would be two more descriptions in every session
 * and two tools that can only answer "not here".
 *
 * @param string[] $names Ability names.
 * @return string[]
 */
function wpmcp_offered_where_supported( array $names ) {
	if ( wpmcp_elementor_active() ) {
		return $names;
	}

	return array_values(
		array_filter(
			$names,
			function ( $name ) {
				return 0 !== strpos( $name, 'wpmcp/elementor-' );
			}
		)
	);
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
 * Does the role store exactly what it should?
 *
 * That is the read set, at every level: the level itself is applied per
 * check. Anything more on the role is a leftover from an older version or
 * the work of a role editor.
 *
 * @return bool False when the role is missing.
 */
function wpmcp_role_caps_match() {
	$role = get_role( WPMCP_ROLE );
	if ( ! $role ) {
		return false;
	}

	$wanted = wpmcp_role_capabilities();
	$actual = array_keys( array_filter( (array) $role->capabilities ) );

	sort( $actual );
	$expected = array_keys( $wanted );
	sort( $expected );

	return $actual === $expected;
}

/**
 * Put the role back to what it should store.
 *
 * Since 0.19 that is the read set at every level, so this mostly removes
 * what earlier versions stored. Still hooked to both add_option and
 * update_option of the level: WordPress fires update_option_{$option} only
 * when the option already existed, which once left a switch visibly
 * flipped and practically inert. Cheap enough to keep as a belt.
 */
function wpmcp_sync_role_capabilities() {
	$role = get_role( WPMCP_ROLE );
	if ( ! $role ) {
		wpmcp_register_role();
		return;
	}

	$wanted = wpmcp_role_capabilities();

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
 * Tidy the stored role on admin and REST requests.
 *
 * Not a line of defence any more (wpmcp_agent_capabilities decides per
 * check). It brings a role a role editor changed back to the read set,
 * and recreates it when it was deleted: without the role, the agent
 * account has no marker capability and every tool refuses it with a
 * permission error that points nowhere. Comparing is cheap (roles live in
 * one cached option) and it only writes when something actually drifted.
 */
function wpmcp_reconcile_role() {
	if ( ! wpmcp_role_caps_match() ) {
		wpmcp_sync_role_capabilities();
	}
}
add_action( 'admin_init', 'wpmcp_reconcile_role' );
add_action( 'admin_init', 'wpmcp_close_expired_work_session' );
add_action( 'rest_api_init', 'wpmcp_reconcile_role' );
add_action( 'rest_api_init', 'wpmcp_close_expired_work_session' );

/**
 * Schema and setup version. Raise it whenever an update has to redo what
 * activation does: a changed audit table, a changed role.
 */
const WPMCP_DB_VERSION = 2;

/**
 * Do what activation does, once per version, on an updated site.
 *
 * WordPress runs the activation hook on activation only. An update, by
 * upload or by the updater, never runs it, so the audit table and the
 * role were only ever right on sites that installed the current version
 * fresh. This compares one autoloaded option on every request and does
 * the work once when it differs. On multisite each site catches up on its
 * own first request, through the same check.
 */
function wpmcp_maybe_upgrade() {
	if ( WPMCP_DB_VERSION === (int) get_option( 'wpmcp_db_version', 0 ) ) {
		return;
	}
	wpmcp_upgrade();
}
add_action( 'plugins_loaded', 'wpmcp_maybe_upgrade' );

/**
 * Bring the table and the role to what this version expects.
 *
 * dbDelta creates a missing table and adds missing columns and keys; the
 * role sync recreates a missing role and resets a drifted one. Both are
 * safe to run twice, which is what happens when two requests arrive
 * during the same first second.
 */
function wpmcp_upgrade() {
	wpmcp_create_audit_table();
	wpmcp_sync_role_capabilities();
	wpmcp_schedule_log_pruning();
	update_option( 'wpmcp_db_version', WPMCP_DB_VERSION, true );
}

/**
 * Deactivation: leave nothing open behind.
 *
 * Ends a running session and reduces the role to read. With the plugin
 * inactive nothing fences the agent account to its endpoint any more, so
 * the role must not carry anything worth reaching. Application passwords
 * are kept, so reactivating simply works again; activation restores the
 * role. To remove the agent for good, uninstall the plugin. The daily log
 * clean-up is unscheduled.
 */
function wpmcp_deactivate() {
	if ( wpmcp_work_session_active() ) {
		wpmcp_end_work_session();
	}
	delete_option( 'wpmcp_work_session_until' );

	// The clean-up function is gone while the plugin is inactive; an event
	// left behind would fire into nothing every day. Activation or the
	// next update schedules it again.
	wp_clear_scheduled_hook( 'wpmcp_prune_log' );

	$role = get_role( WPMCP_ROLE );
	if ( ! $role ) {
		return;
	}
	foreach ( array_keys( (array) $role->capabilities ) as $cap ) {
		if ( 'read' !== $cap ) {
			$role->remove_cap( $cap );
		}
	}
	$role->add_cap( 'read' );
}
