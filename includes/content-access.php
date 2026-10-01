<?php
/**
 * Which posts the agent may read and which it may write.
 *
 * Every tool that touches a post goes through wpmcp_get_readable_post() or
 * wpmcp_get_writable_post() first, so the rules (post types, statuses,
 * live edit, the privacy page, a person holding the edit lock) live in
 * one place and no tool can draw the line differently.
 *
 * @package wp-mcp-connector-plus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Post types the connector may touch.
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
		if ( in_array( $type->name, array( 'attachment' ), true ) ) {
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

/**
 * Resolve and permission-check a post for reading.
 *
 * @param int $post_id Post ID.
 * @return \WP_Post|\WP_Error
 */
function wpmcp_get_readable_post( $post_id ) {
	$post = get_post( (int) $post_id );
	if ( ! $post ) {
		return new \WP_Error( 'wpmcp_not_found', sprintf( 'No post with ID %d.', (int) $post_id ) );
	}
	if ( ! in_array( $post->post_type, wpmcp_allowed_post_types(), true ) ) {
		return new \WP_Error(
			'wpmcp_forbidden_type',
			sprintf(
				'Post type "%s" is not exposed to the connector. Public post types and pages are, on their own. Anything else — a theme\'s headers, footers, hooks or content templates — has to be ticked under Tools > MCP Connector, because a change there lands on every page at once rather than on one. In code, the wpmcp_allowed_post_types filter does the same.',
				$post->post_type
			)
		);
	}
	if ( ! wpmcp_user_can_read( $post ) ) {
		if ( '' !== (string) ( $post->post_password ?? '' ) ) {
			return new \WP_Error(
				'wpmcp_password_protected',
				sprintf( 'Post %d is password protected. Its content is not public, so reading it needs the right to edit it.', $post->ID )
			);
		}
		return new \WP_Error( 'wpmcp_forbidden', sprintf( 'No permission to read post %d.', $post->ID ) );
	}
	return $post;
}

/**
 * Can anyone on the internet read this post?
 *
 * Published and without a password. A password-protected page is
 * published in WordPress's sense, but its content is exactly what the
 * site owner decided not to show everybody, so it counts as non-public
 * here: reading it needs the right to edit it, like a draft.
 *
 * @param \WP_Post|object $post Post.
 * @return bool
 */
function wpmcp_post_is_public( $post ) {
	return 'publish' === $post->post_status && '' === (string) ( $post->post_password ?? '' );
}

/**
 * May the current user read this post's content?
 *
 * Public content, or content the user may edit. A read and a search
 * must draw this line identically, or a search shows what a read refuses.
 *
 * @param \WP_Post|object $post Post.
 * @return bool
 */
function wpmcp_user_can_read( $post ) {
	return wpmcp_post_is_public( $post ) || current_user_can( 'edit_post', $post->ID );
}

/**
 * Resolve and permission-check a post for writing, honouring the live-edit
 * toggle: while it is off, published content is read-only.
 *
 * @param int $post_id Post ID.
 * @return \WP_Post|\WP_Error
 */
function wpmcp_get_writable_post( $post_id ) {
	$post = wpmcp_get_readable_post( $post_id );
	if ( is_wp_error( $post ) ) {
		return $post;
	}

	// The setting decides on its own, before any capability is asked:
	// capabilities can come from places this plugin does not control (a
	// second role on the account, a role editor), and "published pages are
	// read-only" has to hold regardless.
	$live_refused = 'publish' === $post->post_status && ! wpmcp_live_edit_enabled();

	if ( $live_refused || ! current_user_can( 'edit_post', $post->ID ) ) {
		if ( $live_refused ) {
			return new \WP_Error(
				'wpmcp_live_edit_disabled',
				sprintf(
					'Post %d is published and live editing is switched off for this site. Duplicate it with content-duplicate and edit the draft instead.',
					$post->ID
				)
			);
		}
		// The one page WordPress guards with an administrator capability.
		// Without naming it, the refusal looks like an ordinary permission
		// problem and sends the caller hunting through role settings.
		if ( wpmcp_privacy_policy_page_id() === (int) $post->ID ) {
			return new \WP_Error(
				'wpmcp_privacy_policy_page',
				sprintf(
					'Post %d is set as this site\'s privacy policy page (Settings > Privacy). WordPress requires an administrator capability for it. The connector lifts that requirement only at the "Drafts and published pages" access level, and a site can switch it off with the wpmcp_allow_privacy_policy_edit filter.',
					$post->ID
				)
			);
		}

		return new \WP_Error( 'wpmcp_forbidden', sprintf( 'No permission to edit post %d.', $post->ID ) );
	}

	return $post;
}

/**
 * May the connector write this kind of target at all?
 *
 * The questions that depend on what a post is rather than who asks:
 * a synced pattern needs pattern editing switched on, and an element
 * that runs its content as PHP is never written. One function for every
 * path that changes stored content (write, restore, create, duplicate),
 * because the restore had grown up without them and put back revisions
 * where a write was refused.
 *
 * Works on a stand-in object too (ID 0 and a post_type), for content
 * that does not exist yet.
 *
 * @param \WP_Post|object $post Post, or the post about to be created.
 * @return true|\WP_Error
 */
function wpmcp_assert_writable_target( $post ) {
	// Synced patterns need their own permission, and their own warning.
	if ( 'wp_block' === $post->post_type && 'write' !== wpmcp_pattern_access() ) {
		return new \WP_Error(
			'wpmcp_pattern_readonly',
			$post->ID
				? sprintf(
					'Post %d is a synced pattern, and pattern editing is switched off for this site. A pattern change would apply to every page embedding it.',
					$post->ID
				)
				: 'Pattern editing is switched off for this site, so no synced pattern can be written or created.'
		);
	}

	if ( $post->ID && wpmcp_runs_code( $post ) ) {
		return new \WP_Error(
			'wpmcp_runs_code',
			sprintf(
				'Post %d runs its content as PHP ("Execute PHP" is switched on for this element). Writing it would mean writing code onto the server, so it is not writable through the connector. Edit it in the editor.',
				$post->ID
			)
		);
	}

	return true;
}

/**
 * Refuse a save while a person has the page open in the editor.
 *
 * expected_modified only sees what was saved. A person typing in the
 * block editor has saved nothing yet, so the agent's write passes, and
 * then one of two things happens: their next save silently replaces the
 * agent's work, or the editor reloads and what they had not saved is
 * gone. WordPress already knows they are there: the editor keeps the
 * post lock fresh through the heartbeat, the same lock that shows other
 * people "X is currently editing". This asks for it.
 *
 * The lock is held by a person by construction: wp_check_post_lock()
 * ignores the current user, and the agent never opens the editor. A lock
 * held by another agent account is ignored as well, since nothing can be
 * lost in a browser it does not have.
 *
 * wp_check_post_lock() lives in wp-admin/includes/post.php, which a REST
 * request does not load; core's own autosave controller loads it the
 * same way.
 *
 * @param \WP_Post|object $post Post about to be saved.
 * @return \WP_Error|null wpmcp_locked, or null when nobody holds the lock.
 */
function wpmcp_post_lock_error( $post ) {
	if ( ! function_exists( 'wp_check_post_lock' ) && is_file( ABSPATH . 'wp-admin/includes/post.php' ) ) {
		require_once ABSPATH . 'wp-admin/includes/post.php';
	}
	if ( ! function_exists( 'wp_check_post_lock' ) || empty( $post->ID ) ) {
		return null;
	}

	$holder = (int) wp_check_post_lock( $post->ID );
	if ( ! $holder || wpmcp_is_ai_user( $holder ) ) {
		return null;
	}

	$user = get_userdata( $holder );
	$name = ( $user && ! empty( $user->display_name ) ) ? $user->display_name : sprintf( 'User %d', $holder );

	return new \WP_Error(
		'wpmcp_locked',
		sprintf(
			'%s is editing this page right now (post %d is open in the editor). Saving now would either be overwritten by their next save or throw away what they have not saved yet. Try again once they have closed it: the lock ends about two and a half minutes after the editor is closed. Then read the page again, since they may have changed it.',
			$name,
			(int) $post->ID
		)
	);
}

/**
 * Statuses the agent may set.
 *
 * Publishing was never the agent's decision, and it still is not: it
 * becomes possible only while the site owner has a work session open.
 * Opening one is the human deciding that what gets built in that window
 * may go live — once, instead of twenty-two clicks afterwards. Outside a
 * session there is no status here that publishes, at any access level.
 *
 * @return string[]
 */
function wpmcp_writable_statuses() {
	$statuses = array( 'draft', 'pending' );

	if ( function_exists( 'wpmcp_work_session_active' ) && wpmcp_work_session_active() ) {
		$statuses[] = 'publish';
	}

	return $statuses;
}

/**
 * Grant capabilities to the current user until the returned callback runs.
 *
 * The same shape as the unfiltered_html grant: nothing lands on the role,
 * and the caller removes it in a finally so a fatal cannot leave it behind.
 *
 * @param string[] $caps Capabilities to grant.
 * @return callable Removes the grant.
 */
function wpmcp_grant_caps_for_request( array $caps ) {
	$user_id = get_current_user_id();

	$grant = function ( $allcaps, $requested, $args, $user ) use ( $caps, $user_id ) {
		if ( isset( $user->ID ) && (int) $user->ID === (int) $user_id ) {
			foreach ( $caps as $cap ) {
				$allcaps[ $cap ] = true;
			}
		}
		return $allcaps;
	};

	add_filter( 'user_has_cap', $grant, 100, 4 );

	return function () use ( $grant ) {
		remove_filter( 'user_has_cap', $grant, 100 );
	};
}
