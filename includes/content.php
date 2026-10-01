<?php
/**
 * Reading, writing, duplicating and previewing content.
 *
 * Guard rails that live here rather than in policy documents:
 * - slug, parent and status change only when a write asks for it
 *   (wpmcp_placement_diff), the post type never
 * - every real write goes through the validation pipeline first
 * - every content write leaves a revision, so rollback is one click
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
 * Which posts embed a synced pattern.
 *
 * The one query behind both the warning in a dry run (how many) and the
 * cache purge after a save (which). They used to be two queries with two
 * opinions: the count included other patterns and had no limit, the list
 * left patterns out and stopped at 200.
 *
 * Other patterns count: a pattern nested in another changes with it, and
 * so does every page embedding that one.
 *
 * The reference is matched with what follows it in the block comment,
 * '"ref":12}' or '"ref":12,' (pattern overrides add a "content" key
 * after it). A bare '"ref":12' also matched pattern 123 and 1200, and the
 * dry run warned about pages the edit would never touch.
 *
 * @param int $pattern_id Pattern post ID.
 * @return int[] Post IDs, at most 2000.
 */
function wpmcp_pattern_usage_ids( $pattern_id ) {
	global $wpdb;

	$pattern_id = (int) $pattern_id;
	if ( $pattern_id <= 0 ) {
		return array();
	}

	$needle = '"ref":' . $pattern_id;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- content search, no core API for this.
	return array_map(
		'intval',
		(array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_status NOT IN ('trash', 'auto-draft')
				   AND post_type != 'revision'
				   AND ID != %d
				   AND ( post_content LIKE %s OR post_content LIKE %s )
				 ORDER BY ID
				 LIMIT 2000",
				$pattern_id,
				'%' . $wpdb->esc_like( $needle . '}' ) . '%',
				'%' . $wpdb->esc_like( $needle . ',' ) . '%'
			)
		)
	);
}

/**
 * How many posts embed a given synced pattern.
 *
 * Editing a pattern changes every one of them at once, so the number
 * belongs in the dry run before anyone decides to save.
 *
 * @param int $pattern_id Pattern post ID.
 * @return int
 */
function wpmcp_pattern_usage_count( $pattern_id ) {
	return count( wpmcp_pattern_usage_ids( $pattern_id ) );
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
	if ( ! wpmcp_post_is_public( $post ) && ! current_user_can( 'edit_post', $post->ID ) ) {
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
 * Run a save and catch the revision it stores, if it stores one.
 *
 * WordPress skips the revision when nothing it tracks changed, a status
 * or slug change for instance. Asking for the newest revision afterwards
 * then answered with an older one, which named a state from before this
 * save as its undo. The action fires inside the save, for exactly the
 * revision this save created.
 *
 * @param int      $post_id Post being saved.
 * @param callable $save    The save.
 * @return array{0: mixed, 1: int} What the save returned, and the revision ID or 0.
 */
function wpmcp_save_capturing_revision( $post_id, callable $save ) {
	$captured = 0;
	$listener = function ( $revision_id ) use ( $post_id, &$captured ) {
		$revision = wp_get_post_revision( (int) $revision_id );
		if ( $revision && (int) $revision->post_parent === (int) $post_id ) {
			$captured = (int) $revision_id;
		}
	};

	add_action( '_wp_put_post_revision', $listener );
	try {
		$result = $save();
	} finally {
		remove_action( '_wp_put_post_revision', $listener );
	}

	return array( $result, $captured );
}

/**
 * Everything a save owes its caller once the database is right.
 *
 * The fresh modified stamp for the next write, and the caches: the post's
 * own, and for a synced pattern those of every page embedding it, whose
 * cached copies still show the old version. Shared by write and restore,
 * which used to end differently: the restore cleared nothing.
 *
 * @param \WP_Post $post     Post that was saved.
 * @param array    $response Response, extended in place.
 * @return \WP_Post|null The post as stored now.
 */
function wpmcp_after_save( $post, array &$response ) {
	// The stamp for the next write on this page. Without it a sequence of
	// writes needs a read between every pair, purely to fetch this one
	// value, and dropping expected_modified to avoid that throws away the
	// protection it exists for.
	$fresh = get_post( $post->ID );
	if ( $fresh ) {
		$response['modified']  = $fresh->post_modified_gmt;
		$response['nextWrite'] = 'Pass this "modified" value as expected_modified on your next write to this page.';
	}

	// The database is now right; the delivered page may not be. Say which.
	$response['cache'] = wpmcp_purge_caches( $post->ID );

	// A pattern lives inside other pages, and their cached copies still
	// show the old version: the pattern's own cache was never where a
	// visitor saw it.
	if ( 'wp_block' === $post->post_type ) {
		$embedding = wpmcp_pattern_usage_ids( $post->ID );
		foreach ( $embedding as $embedding_id ) {
			wpmcp_purge_caches( $embedding_id );
		}
		if ( ! empty( $embedding ) ) {
			$response['cache']['alsoPurged'] = $embedding;
		}
	}
	$response['verify'] = 'content-read shows what is stored. Use content-fetch-live to see what a visitor gets.';

	return $fresh;
}

/**
 * What kind of write this was, for the log.
 *
 * @param bool $has_tree      A whole tree was sent.
 * @param bool $has_ops       Operations were sent.
 * @param bool $has_placement Slug, parent or status were sent.
 * @return string tree, ops, placement or meta.
 */
function wpmcp_write_operation( $has_tree, $has_ops, $has_placement ) {
	if ( $has_tree ) {
		return 'tree';
	}
	if ( $has_ops ) {
		return 'ops';
	}
	return $has_placement ? 'placement' : 'meta';
}

/**
 * Statuses content-list can be asked for.
 *
 * Trash, auto-drafts, revisions ("inherit") and "any" are not content
 * anybody is working on, and "any" would also bypass the per-type check
 * below.
 *
 * @return string[]
 */
function wpmcp_listable_statuses() {
	return array( 'publish', 'draft', 'pending', 'future', 'private' );
}

/**
 * Which statuses of a post type the current user may see in a list.
 *
 * The same line wpmcp_get_readable_post() draws for one post, drawn for a
 * whole type so that it can go into the query: what is filtered out after
 * paging leaves pages short and totals wrong. Published content is public;
 * anything else is readable only with the right to edit it, which for
 * another user's post means edit_others_*.
 *
 * @param string $type Post type.
 * @return array{statuses: string[], passwords: bool}
 */
function wpmcp_list_visibility( $type ) {
	$object = get_post_type_object( $type );
	$cap    = $object && isset( $object->cap ) ? $object->cap : null;

	$can = function ( $name ) use ( $cap ) {
		return $cap && isset( $cap->{$name} ) && current_user_can( $cap->{$name} );
	};

	$others = $can( 'edit_posts' ) && $can( 'edit_others_posts' );

	$statuses = array( 'publish' );
	if ( $others ) {
		$statuses = array_merge( $statuses, array( 'draft', 'pending', 'future' ) );
		if ( $can( 'edit_private_posts' ) ) {
			$statuses[] = 'private';
		}
	}

	return array(
		'statuses'  => $statuses,
		// A password-protected post is published, and editing someone
		// else's published post needs both of these.
		'passwords' => $others && $can( 'edit_published_posts' ),
	);
}

/**
 * The SQL that limits a list to what the current user may read.
 *
 * One clause per post type, because what is visible differs per type.
 *
 * @param array<string, array{statuses: string[], passwords: bool}> $visibility Per type.
 * @param string                                                     $block      Block name, or ''.
 * @return string Starts with " AND ".
 */
function wpmcp_list_where( array $visibility, $block = '' ) {
	global $wpdb;

	$per_type = array();
	foreach ( $visibility as $type => $rule ) {
		if ( empty( $rule['statuses'] ) ) {
			continue;
		}
		$clause = $wpdb->prepare( "{$wpdb->posts}.post_type = %s", $type )
			. " AND {$wpdb->posts}.post_status IN ('" . implode( "','", array_map( 'esc_sql', $rule['statuses'] ) ) . "')";
		if ( empty( $rule['passwords'] ) ) {
			$clause .= " AND ( {$wpdb->posts}.post_status <> 'publish' OR {$wpdb->posts}.post_password = '' )";
		}
		$per_type[] = '( ' . $clause . ' )';
	}

	$where = empty( $per_type ) ? ' AND 1 = 0' : ' AND ( ' . implode( ' OR ', $per_type ) . ' )';

	if ( '' !== $block ) {
		// WordPress writes core blocks without their namespace
		// (<!-- wp:paragraph -->), everything else with it, and always a
		// space after the name, also before "/-->". LIKE searches the
		// whole content, so nested blocks are found as well. has_block()
		// makes the same string test afterwards.
		$names = array( $block );
		if ( 0 === strpos( $block, 'core/' ) ) {
			$names[] = substr( $block, 5 );
		}
		$likes = array();
		foreach ( $names as $name ) {
			$likes[] = $wpdb->prepare( "{$wpdb->posts}.post_content LIKE %s", '%' . $wpdb->esc_like( '<!-- wp:' . $name . ' ' ) . '%' );
		}
		$where .= ' AND ( ' . implode( ' OR ', $likes ) . ' )';
	}

	return $where;
}

/**
 * List content with light metadata.
 *
 * Only what the agent may read: post types within the connector's scope,
 * statuses it may see per type, and password-protected posts only where
 * it could edit them. All of that goes into the query, so a page holds
 * per_page items and total counts what can actually be listed.
 *
 * @param array $args { post_type, status, search, uses_block, per_page, page }.
 * @return array|\WP_Error
 */
function wpmcp_list_content( array $args ) {
	$per_page = min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
	$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
	$allowed  = wpmcp_allowed_post_types();

	$types = $allowed;
	if ( ! empty( $args['post_type'] ) ) {
		$asked = array_filter( array_map( 'trim', is_array( $args['post_type'] ) ? $args['post_type'] : explode( ',', (string) $args['post_type'] ) ) );
		$types = array_values( array_intersect( $asked, $allowed ) );
		if ( empty( $types ) ) {
			return new \WP_Error(
				'wpmcp_forbidden_type',
				sprintf(
					'Post type "%s" is not exposed to the connector. Listable here: %s.',
					implode( ', ', $asked ),
					implode( ', ', $allowed )
				)
			);
		}
	}

	$statuses = wpmcp_listable_statuses();
	if ( ! empty( $args['status'] ) ) {
		$asked    = array_filter( array_map( 'trim', is_array( $args['status'] ) ? $args['status'] : explode( ',', (string) $args['status'] ) ) );
		$statuses = array_values( array_intersect( $asked, wpmcp_listable_statuses() ) );
		if ( count( $statuses ) !== count( $asked ) ) {
			return new \WP_Error(
				'wpmcp_bad_status',
				sprintf(
					'Status "%s" cannot be listed. Use one of: %s.',
					implode( ', ', array_diff( $asked, wpmcp_listable_statuses() ) ),
					implode( ', ', wpmcp_listable_statuses() )
				)
			);
		}
	}

	$visibility = array();
	foreach ( $types as $type ) {
		$rule               = wpmcp_list_visibility( $type );
		$rule['statuses']   = array_values( array_intersect( $rule['statuses'], $statuses ) );
		$visibility[ $type ] = $rule;
	}

	$block = trim( (string) ( $args['uses_block'] ?? '' ) );
	if ( '' !== $block && false === strpos( $block, '/' ) ) {
		$block = 'core/' . $block;
	}

	$query_args = array(
		'post_type'      => $types,
		'post_status'    => $statuses,
		'posts_per_page' => $per_page,
		'paged'          => $page,
		'orderby'        => 'modified',
		'order'          => 'DESC',
	);

	if ( ! empty( $args['search'] ) ) {
		$query_args['s'] = (string) $args['search'];
	}

	$where = wpmcp_list_where( $visibility, $block );
	$query = new \WP_Query();

	// Scoped to this one query object, so nothing else on the request is
	// narrowed by it, and removed again whatever happens.
	$narrow = function ( $sql, $q ) use ( $query, $where ) {
		return $q === $query ? $sql . $where : $sql;
	};
	add_filter( 'posts_where', $narrow, 10, 2 );
	try {
		$query->query( $query_args );
	} finally {
		remove_filter( 'posts_where', $narrow, 10 );
	}

	$items = array();

	foreach ( $query->posts as $post ) {
		// The query already drew the line; this is the same check a single
		// read makes, so a list can never show more than a read would.
		if ( is_wp_error( wpmcp_get_readable_post( $post->ID ) ) ) {
			continue;
		}
		if ( '' !== $block && ! has_block( $block, $post ) ) {
			continue;
		}

		$items[] = array(
			'id'       => $post->ID,
			'title'    => get_the_title( $post ),
			'type'     => $post->post_type,
			'status'   => $post->post_status,
			'slug'     => $post->post_name,
			'url'      => get_permalink( $post ),
			'parent'   => $post->post_parent,
			'modified' => $post->post_modified_gmt,
			'blocks'   => wpmcp_count_blocks( parse_blocks( $post->post_content ) ),
		);
	}

	return array(
		'items' => $items,
		'total' => (int) $query->found_posts,
		'pages' => (int) $query->max_num_pages,
		'page'  => $page,
	);
}

/**
 * Read a post as a block tree.
 *
 * @param int    $post_id          Post ID.
 * @param string $mode             'outline' | 'full' | 'subtree'.
 * @param string $path             Path when mode is 'subtree'.
 * @param bool   $include_defaults Keep default-valued attributes.
 * @return array|\WP_Error
 */
function wpmcp_read_content( $post_id, $mode = 'outline', $path = '', $include_defaults = false, array $paths = array(), $with_meta = false ) {
	$post = wpmcp_get_readable_post( $post_id );
	if ( is_wp_error( $post ) ) {
		return $post;
	}

	$blocks = parse_blocks( $post->post_content );

	$result = array(
		'id'         => $post->ID,
		'title'      => get_the_title( $post ),
		'type'       => $post->post_type,
		'status'     => $post->post_status,
		'url'        => get_permalink( $post ),
		// A draft's permalink is only ?page_id=…, so the slug has to be
		// stated rather than left to be derived from the URL.
		'slug'       => $post->post_name,
		'parent'     => (int) $post->post_parent,
		'blockCount' => wpmcp_count_blocks( $blocks ),
		'mode'       => $mode,
		// Hand this back in content-write to be told if someone edited the
		// page in the meantime, instead of silently overwriting them.
		'modified'   => $post->post_modified_gmt,
	);

	if ( $with_meta ) {
		$result['meta'] = wpmcp_read_meta( $post );
	}

	if ( 'outline' === $mode ) {
		$result['outline'] = wpmcp_blocks_to_outline( $blocks );
		return $result;
	}

	if ( 'subtree' === $mode ) {
		// One path or many: reading fifteen sections one request at a time
		// is a lot of round trips for no reason.
		$wanted = ! empty( $paths ) ? $paths : array( $path );
		$trees  = array();

		foreach ( $wanted as $one ) {
			$segments = wpmcp_path_parse( $one );
			if ( null === $segments || empty( $segments ) ) {
				return new \WP_Error( 'wpmcp_bad_path', sprintf( 'Mode "subtree" needs a path like "2" or "2.0.1", got "%s".', (string) $one ) );
			}
			$node = wpmcp_blocks_at_path( $blocks, $segments );
			if ( null === $node ) {
				return new \WP_Error( 'wpmcp_path_not_found', sprintf( 'Path "%s" does not exist in post %d.', (string) $one, $post->ID ) );
			}
			$prefix = $segments;
			$index  = array_pop( $prefix );
			$trees  = array_merge(
				$trees,
				wpmcp_blocks_to_tree( array( $node ), $prefix, $include_defaults, $index )
			);
		}

		$result['tree'] = $trees;
		return $result;
	}

	$result['tree'] = wpmcp_blocks_to_tree( $blocks, array(), $include_defaults );

	return $result;
}

/**
 * Everything a write checks before it saves, for a post or a post to be.
 *
 * The validation pipeline in one place: decode, build the blocks from a
 * tree or operations, validate them, the markup guard, meta and
 * placement. content-write runs it on the stored post. content-create
 * runs it on a stand-in (ID 0, empty content) before inserting anything,
 * dry run and real call alike, so the two cannot drift apart again: the
 * create dry run used to be a hand-made copy of this, and the real create
 * checked nothing until the page already existed.
 *
 * @param \WP_Post|object $post    Post, or a stand-in with ID 0.
 * @param array           $args    tree / ops / meta / slug / parent / status.
 * @param bool            $dry_run For the response only.
 * @return array|\WP_Error {
 *     @type array    $response The response as far as it is known before saving.
 *     @type string[] $errors   Everything that stops the save.
 *     ... and what the save needs: validation, impact, meta_diff, placement,
 *     diff, after_count and the has_* flags.
 * }
 */
function wpmcp_plan_write( $post, array $args, $dry_run ) {
	$before_blocks = parse_blocks( $post->post_content );
	$before_count  = wpmcp_count_blocks( $before_blocks );

	// Decode before deciding what was sent: a large argument may arrive as
	// text, or as the comma-split remains of text.
	foreach ( array( 'ops', 'tree', 'meta' ) as $key ) {
		if ( ! isset( $args[ $key ] ) || '' === $args[ $key ] || array() === $args[ $key ] ) {
			continue;
		}
		$decoded = wpmcp_decode_structure( $args[ $key ], $key );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}
		$args[ $key ] = $decoded;
	}

	$has_tree = isset( $args['tree'] ) && is_array( $args['tree'] );
	$has_ops  = isset( $args['ops'] ) && is_array( $args['ops'] ) && ! empty( $args['ops'] );
	$has_meta = isset( $args['meta'] ) && is_array( $args['meta'] ) && ! empty( $args['meta'] );

	// Read before the guard below, which until 0.18.2 asked for a variable
	// that was assigned further down: every write carrying nothing but a
	// slug, a parent or a status was refused by a message that listed
	// those three as valid input.
	$has_placement = array_key_exists( 'slug', $args ) || array_key_exists( 'parent', $args ) || array_key_exists( 'status', $args );

	if ( $has_tree && $has_ops ) {
		return new \WP_Error(
			'wpmcp_bad_request',
			'Provide either "tree" (replace the whole page) or "ops" (patch operations), not both.'
		);
	}

	// Meta stands on its own: correcting a canonical URL is not a reason to
	// touch the block tree.
	if ( ! $has_tree && ! $has_ops && ! $has_meta && ! $has_placement ) {
		return new \WP_Error(
			'wpmcp_bad_request',
			'Provide "ops" (patch operations), "tree" (replace the whole page), "meta" (SEO fields), or slug/parent/status.'
		);
	}

	$meta_diff = $has_meta
		? wpmcp_meta_diff( $post, $args['meta'] )
		: array( 'fields' => array(), 'errors' => array(), 'changes' => 0 );

	$placement = $has_placement
		? wpmcp_placement_diff( $post, $args )
		: array( 'fields' => array(), 'errors' => array(), 'changes' => 0 );

	$op_summary = array();
	$confirm    = array();

	if ( ! $has_tree && ! $has_ops ) {
		$blocks = $before_blocks;
	} elseif ( $has_tree ) {
		$errors = array();
		$blocks = wpmcp_tree_to_blocks( $args['tree'], '', $errors );
		if ( ! empty( $errors ) ) {
			return array(
				'response'      => array(
					'ok'       => false,
					'dryRun'   => $dry_run,
					'errors'   => $errors,
					'warnings' => array(),
				),
				'errors'        => $errors,
				'has_tree'      => true,
				'has_ops'       => false,
				'has_meta'      => $has_meta,
				'has_placement' => $has_placement,
			);
		}
	} else {
		$applied = wpmcp_apply_ops( $before_blocks, $args['ops'] );
		if ( is_wp_error( $applied ) ) {
			return $applied;
		}
		$blocks     = $applied['blocks'];
		$op_summary = $applied['summary'];
		$confirm    = wpmcp_patch_confirmations( $blocks, $args['ops'] );
	}

	$validation = wpmcp_validate_blocks( $blocks, $before_blocks );

	// Structured data is stored in its safe form; see wpmcp_normalize_jsonld().
	if ( '' !== $validation['serialized'] ) {
		$validation['serialized'] = wpmcp_normalize_jsonld( $validation['serialized'] );
	}
	$after_count = wpmcp_count_blocks( $blocks );

	$diff = array(
		'blocksBefore' => $before_count,
		'blocksAfter'  => $after_count,
		'delta'        => $after_count - $before_count,
	);
	if ( ! empty( $op_summary ) ) {
		$diff['operations'] = $op_summary;
	}

	$warnings = $validation['warnings'];

	if ( 'wp_block' === $post->post_type && $post->ID ) {
		$uses = wpmcp_pattern_usage_count( $post->ID );
		if ( $uses > 0 ) {
			$warnings[] = sprintf(
				'This is a synced pattern used on %d other piece(s) of content. Saving changes all of them at once, and a pattern has no draft state.',
				$uses
			);
		}
	}

	// A full-tree write that drops a lot of content is usually a mistake,
	// not an intention — say so loudly while there is still time.
	if ( $has_tree && $before_count > 0 && $after_count < $before_count * 0.5 ) {
		$warnings[] = sprintf(
			'This replaces the page with %d blocks where it had %d. If you meant a small change, use "ops" instead of "tree".',
			$after_count,
			$before_count
		);
	}

	// What WordPress will do to this content on save, known before saving.
	$impact = wpmcp_kses_impact( $post->post_content, $validation['serialized'] );
	$errors = $validation['errors'];

	if ( $impact['alters'] ) {
		// The filter has always been handed the post being written, and
		// nothing for content that does not exist yet.
		if ( $impact['introduces'] && ! wpmcp_filtered_markup_allowed( $post->ID ? $post : null ) ) {
			$errors[] = wpmcp_filtered_markup_error( $impact );
		} elseif ( $impact['introduces'] ) {
			$warnings[] = sprintf(
				'This change adds markup WordPress would normally refuse from an agent account (%s). It is being written because this site opened the wpmcp_allow_filtered_markup filter. Close it again when the repair is done.',
				! empty( $impact['blocks'] ) ? wpmcp_unstable_summary( $impact['blocks'] ) : implode( ', ', $impact['added'] )
			);
		} elseif ( ! empty( $impact['affected'] ) ) {
			$warnings[] = sprintf(
				'The page already contains markup WordPress would normally strip from an agent account (%s). It is preserved: the save keeps what was there rather than destroying it, and nothing new of that kind is added.',
				implode( ', ', $impact['affected'] )
			);
		}
	}

	$errors = array_merge( $errors, $meta_diff['errors'], $placement['errors'] );

	$response = array(
		'ok'       => empty( $errors ),
		'dryRun'   => $dry_run,
		'postId'   => $post->ID,
		'diff'     => $diff,
		'errors'   => $errors,
		'warnings' => $warnings,
	);

	// What each patched block reads now, so checking a text change does
	// not take a second call.
	if ( ! empty( $confirm ) ) {
		$response['patched'] = $confirm;
	}

	if ( $has_placement ) {
		$response['placement'] = array(
			'fields'  => $placement['fields'],
			'changes' => $placement['changes'],
		);
	}

	if ( $has_meta ) {
		$response['meta'] = array(
			'fields'  => $meta_diff['fields'],
			'changes' => $meta_diff['changes'],
			'note'    => 'WordPress revisions cover post content, not post meta. The previous values are listed above and in the activity log; there is no one-click rollback for these.',
		);
	}

	return array(
		'response'      => $response,
		'errors'        => $errors,
		'has_tree'      => $has_tree,
		'has_ops'       => $has_ops,
		'has_meta'      => $has_meta,
		'has_placement' => $has_placement,
		'validation'    => $validation,
		'impact'        => $impact,
		'meta_diff'     => $meta_diff,
		'placement'     => $placement,
		'diff'          => $diff,
		'after_count'   => $after_count,
	);
}

/**
 * Write a block tree to a post — full replacement or patch operations.
 *
 * @param array $args { post_id, tree?, ops?, dry_run }.
 * @return array|\WP_Error
 */
function wpmcp_write_content( array $args ) {
	$post_id = (int) ( $args['post_id'] ?? 0 );
	$dry_run = ! isset( $args['dry_run'] ) || (bool) $args['dry_run'];

	$post = wpmcp_get_writable_post( $post_id );
	if ( is_wp_error( $post ) ) {
		return $post;
	}

	$target = wpmcp_assert_writable_target( $post );
	if ( is_wp_error( $target ) ) {
		return $target;
	}

	// Optimistic locking. The agent reads, thinks, then writes; in between
	// a human may have saved the same page. Without this the human's work
	// disappears silently.
	$expected_modified = isset( $args['expected_modified'] ) ? trim( (string) $args['expected_modified'] ) : '';
	if ( '' !== $expected_modified && $expected_modified !== $post->post_modified_gmt ) {
		return new \WP_Error(
			'wpmcp_stale',
			sprintf(
				'Post %d changed after you read it (read: %s, now: %s). Someone edited it in the meantime. Read it again and redo the change on the current version.',
				$post->ID,
				$expected_modified,
				$post->post_modified_gmt
			)
		);
	}

	$plan = wpmcp_plan_write( $post, $args, $dry_run );
	if ( is_wp_error( $plan ) ) {
		return $plan;
	}

	$response      = $plan['response'];
	$errors        = $plan['errors'];
	$has_tree      = $plan['has_tree'];
	$has_ops       = $plan['has_ops'];
	$has_meta      = $plan['has_meta'];
	$has_placement = $plan['has_placement'];

	$operation = wpmcp_write_operation( $has_tree, $has_ops, $has_placement );

	if ( ! empty( $errors ) ) {
		// A real write that was refused is not a dry run, and the log used
		// to say it was: "what did the agent try?" had no answer there.
		wpmcp_log(
			'wpmcp/content-write',
			array(
				'post_id'   => $post->ID,
				'operation' => $dry_run ? $operation : 'rejected',
				'dry_run'   => $dry_run,
				'summary'   => sprintf( 'Rejected (%s): %d validation error(s).', $operation, count( $errors ) ),
			)
		);
		return $response;
	}

	if ( $dry_run ) {
		$response['message'] = 'Dry run only — nothing was saved. Call again with dry_run: false to write.';
		wpmcp_log(
			'wpmcp/content-write',
			array(
				'post_id'   => $post->ID,
				'operation' => $operation,
				'dry_run'   => true,
				'summary'   => sprintf( 'Dry run OK (%+d blocks).', $plan['diff']['delta'] ),
			)
		);
		return $response;
	}

	$validation  = $plan['validation'];
	$impact      = $plan['impact'];
	$meta_diff   = $plan['meta_diff'];
	$placement   = $plan['placement'];
	$diff        = $plan['diff'];
	$after_count = $plan['after_count'];

	$revision_id = 0;

	// Only touch post content when the change actually has content in it.
	// A meta-only write must not bump the modified date or spend a
	// revision on an identical page.
	$placement_fields = array(
		'slug'   => 'post_name',
		'parent' => 'post_parent',
		'status' => 'post_status',
	);

	if ( $has_tree || $has_ops || $placement['changes'] > 0 ) {
		// wp_slash() is essential: without it WordPress strips backslashes
		// out of the block attribute JSON.
		$postarr = array( 'ID' => $post->ID );

		if ( $has_tree || $has_ops ) {
			$postarr['post_content'] = wp_slash( $validation['serialized'] );
		}

		foreach ( $placement_fields as $key => $column ) {
			if ( ! empty( $placement['fields'][ $key ]['changed'] ) ) {
				$postarr[ $column ] = $placement['fields'][ $key ]['to'];
			}
		}

		// Dynamic data is gated by unfiltered_html in some block libraries.
		// Where the site has allowed it, the capability is granted for this
		// one call. WordPress then stops filtering, and what stands in its
		// place is the same check every other save gets, already made
		// above: no block the agent changed may hold anything kses would
		// alter. Until 0.18.3 this path had its own list of regular
		// expressions, and <svg/onload>, &#106;avascript: or a meta refresh
		// went straight past it.
		$elevate = wpmcp_dynamic_data_allowed();

		// Publishing inside a work session: the capability for this one save,
		// never on the role.
		$publishing = ! empty( $placement['fields']['status']['changed'] )
			&& 'publish' === $placement['fields']['status']['to'];
		$type_obj   = get_post_type_object( $post->post_type );
		$release    = $publishing && $type_obj
			? wpmcp_grant_caps_for_request( array( $type_obj->cap->publish_posts ) )
			: null;

		try {
			list( $updated, $revision_id ) = wpmcp_save_capturing_revision(
				$post->ID,
				function () use ( $elevate, $impact, $post, $postarr ) {
					if ( $elevate ) {
						return wpmcp_update_post_elevated( $postarr );
					}
					if ( wpmcp_should_preserve_markup( $impact, $post ) ) {
						return wpmcp_update_post_preserving( $postarr );
					}
					return wp_update_post( $postarr, true );
				}
			);
		} finally {
			if ( $release ) {
				$release();
			}
		}

		if ( $publishing && ! is_wp_error( $updated ) ) {
			$response['published'] = 'Published during a work session. It is live now; its revisions are the way back.';
		}

		if ( is_wp_error( $updated ) ) {
			return wpmcp_explain_save_refusal( $updated, $impact );
		}

		if ( $elevate ) {
			$response['elevated'] = 'unfiltered_html was granted for this save only, because "Dynamic data" is set to allowed. It is not on the agent role.';
		}

		// What we sent is not necessarily what got stored.
		$stored_warnings = ( $has_tree || $has_ops )
			? wpmcp_verify_stored( $post->ID, $validation['serialized'] )
			: array();
		if ( ! empty( $stored_warnings ) ) {
			$response['warnings'] = array_merge( $response['warnings'], $stored_warnings );
			$response['contentAltered'] = true;
		}
	}

	$meta_line = '';
	if ( $has_meta && $meta_diff['changes'] > 0 ) {
		$response['meta']['written'] = wpmcp_apply_meta( $post, $meta_diff['fields'] );
		$meta_line                   = wpmcp_meta_log_line( $meta_diff['fields'] );
	}

	$response['message']    = 'Saved.';
	$response['revisionId'] = $revision_id;
	$response['preview']    = wpmcp_preview_url( $post->ID );

	$fresh = wpmcp_after_save( $post, $response );

	// A slug or parent change moves the page. Say where it went, rather
	// than leaving the caller to work the URL out from the pieces.
	if ( $fresh && $placement['changes'] > 0 ) {
		$response['slug']   = $fresh->post_name;
		$response['parent'] = (int) $fresh->post_parent;
		$response['status'] = $fresh->post_status;
		$response['url']    = get_permalink( $fresh );
	}

	// One entry per write. The meta line goes into it rather than into an
	// entry of its own: it carries the old values, which revisions do not
	// keep, and two entries for one call read as two calls.
	$summary = ( $has_tree || $has_ops )
		? sprintf( 'Saved (%+d blocks, %d total).', $diff['delta'], $after_count )
		: 'Saved.';
	if ( $placement['changes'] > 0 ) {
		$moved = array();
		foreach ( $placement['fields'] as $key => $field ) {
			if ( ! empty( $field['changed'] ) ) {
				$moved[] = sprintf( '%s "%s" -> "%s"', $key, $field['from'], $field['to'] );
			}
		}
		$summary .= ' ' . implode( ', ', $moved ) . '.';
	}
	if ( '' !== $meta_line ) {
		$summary .= ' ' . $meta_line . '.';
	}
	if ( ! empty( $response['elevated'] ) ) {
		$summary .= ' unfiltered_html granted for this save only.';
	}

	wpmcp_log(
		'wpmcp/content-write',
		array(
			'post_id'     => $post->ID,
			'operation'   => $operation,
			'dry_run'     => false,
			'summary'     => $summary,
			'revision_id' => $revision_id,
		)
	);

	return $response;
}

/**
 * Would saving this content lose anything, and if so, was it already there?
 *
 * The agent account deliberately has no `unfiltered_html`, so WordPress
 * runs its content through wp_kses_post on save. That is right for
 * anything the agent writes. It is wrong for content that was already
 * stored: editing one block of a page would quietly destroy a JSON-LD
 * script in another, which is not the agent's doing and not the site
 * owner's intention.
 *
 * Until 0.18.3 "introduces" only knew whole elements: script, style,
 * iframe, form, object, embed. Everything else kses removes - an onerror
 * on an image, a javascript: link, an svg with onload - left it false,
 * and false meant the save went past the filter. That was stored XSS at
 * the lowest access level. The whole-element comparison is still made,
 * for the labels it gives, but the deciding question is now asked of
 * kses itself, block by block: see wpmcp_unstable_blocks().
 *
 * @param string   $before     Content currently stored.
 * @param string   $after      Content about to be written.
 * @param string[] $also_known Further content whose blocks count as
 *                             already stored, e.g. the revision a restore
 *                             brings back.
 * @return array {
 *     @type bool     $alters     Whether the save would change the content.
 *     @type bool     $introduces Whether the change adds filtered markup.
 *     @type string[] $affected   Which constructs are involved.
 *     @type string[] $added      Whole elements that are new.
 *     @type array    $blocks     New or changed blocks kses would alter,
 *                                see wpmcp_unstable_blocks().
 * }
 */
function wpmcp_kses_impact( $before, $after, array $also_known = array() ) {
	$filtered = function_exists( 'wp_kses_post' ) ? wp_kses_post( $after ) : $after;

	$in_before = wpmcp_filtered_fragments( (string) $before );
	$in_after  = wpmcp_filtered_fragments( (string) $after );

	foreach ( $also_known as $known ) {
		foreach ( wpmcp_filtered_fragments( (string) $known ) as $fragment => $count ) {
			$in_before[ $fragment ] = max( $in_before[ $fragment ] ?? 0, $count );
		}
	}

	// Compare the fragments themselves, not how many there are. Counting
	// let a swap through: remove the page's JSON-LD block and add a script
	// of the agent's own in the same save, and the total never moved.
	$affected = array();
	$added    = array();

	foreach ( $in_after as $fragment => $count ) {
		$affected[] = wpmcp_fragment_label( $fragment );
		if ( $count > ( $in_before[ $fragment ] ?? 0 ) ) {
			$added[] = wpmcp_fragment_label( $fragment );
		}
	}

	$unstable = ( $filtered !== $after )
		? wpmcp_unstable_blocks( (string) $after, array_merge( array( (string) $before ), $also_known ) )
		: array();

	return array(
		'alters'     => ( $filtered !== $after ),
		'introduces' => ! empty( $added ) || ! empty( $unstable ),
		'affected'   => array_values( array_unique( $affected ) ),
		'added'      => array_values( array_unique( $added ) ),
		'blocks'     => $unstable,
	);
}

/**
 * The blocks of a write that the agent wrote and kses would alter.
 *
 * This is the whole rule for when a save may go past WordPress's content
 * filter, and it has two halves.
 *
 * A block that is byte-identical to a block already stored - at any depth,
 * compared by its serialized markup - is not the agent's doing. It keeps
 * its markup, whatever kses would think of it: that is what stops an edit
 * in one block from destroying the JSON-LD or the video embed in another.
 *
 * Every other block is the agent's, and it must come out of wp_kses_post
 * exactly as it went in. Then skipping the filter changes nothing about
 * it, and the save can go past the filter for the sake of the blocks that
 * need it. Anything kses would remove or rewrite is refused instead, and
 * named by its path. Asking kses itself, rather than keeping a list of
 * what it removes, is the point: every list so far had holes, and kses is
 * by definition what WordPress would have done.
 *
 * A changed container is judged by its own markup only (the opening and
 * closing wrapper, its comment delimiter); its children are judged on
 * their own, so an untouched video inside a group survives a change to
 * the group's class.
 *
 * The one deliberate exception is structured data: a valid JSON-LD block
 * is taken out before the comparison, as everywhere else in the plugin.
 *
 * @param string   $after   Content about to be written.
 * @param string[] $sources Content whose blocks count as already stored.
 * @return array<int, array{path: string, constructs: string[], stored: string}>
 */
function wpmcp_unstable_blocks( $after, array $sources ) {
	if ( ! function_exists( 'wp_kses_post' ) ) {
		return array();
	}

	$known = array();
	foreach ( $sources as $source ) {
		if ( '' !== (string) $source ) {
			wpmcp_collect_known_markup( parse_blocks( (string) $source ), $known );
		}
	}

	$found = array();
	wpmcp_find_unstable_blocks( parse_blocks( (string) $after ), $known, '', $found );

	return $found;
}

/**
 * Remember every block of stored content by its exact markup.
 *
 * @param array $blocks Parsed blocks.
 * @param array $known  Markup => true (by reference).
 */
function wpmcp_collect_known_markup( array $blocks, array &$known ) {
	foreach ( $blocks as $block ) {
		if ( null === $block['blockName'] ) {
			$known[ (string) ( $block['innerHTML'] ?? '' ) ] = true;
			continue;
		}

		$known[ serialize_block( $block ) ] = true;

		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			wpmcp_collect_known_markup( $block['innerBlocks'], $known );
		}
	}
}

/**
 * Walk the blocks to be written and collect the ones kses would alter.
 *
 * Paths are counted the way content-read counts them: whitespace between
 * blocks is no block, freeform HTML is one.
 *
 * @param array  $blocks Parsed blocks.
 * @param array  $known  Markup already stored.
 * @param string $prefix Path prefix, for recursion.
 * @param array  $found  Result (by reference).
 */
function wpmcp_find_unstable_blocks( array $blocks, array $known, $prefix, array &$found ) {
	$index = 0;

	foreach ( $blocks as $block ) {
		$freeform = null === $block['blockName'];
		$own      = $freeform ? (string) ( $block['innerHTML'] ?? '' ) : '';

		if ( $freeform && '' === trim( $own ) ) {
			continue;
		}

		$path = ( '' === $prefix ) ? (string) $index : $prefix . '.' . $index;
		++$index;

		$markup = $freeform ? $own : serialize_block( $block );
		if ( isset( $known[ $markup ] ) ) {
			continue;
		}

		if ( ! $freeform ) {
			// The block's own markup without its children: the delimiter
			// with its attributes, and the wrapper chunks in order.
			$own = get_comment_delimited_block_content(
				$block['blockName'],
				is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array(),
				wpmcp_inner_html_from_content( (array) ( $block['innerContent'] ?? array() ) )
			);
		}

		$checked  = wpmcp_strip_safe_jsonld( $own );
		$filtered = wp_kses_post( $checked );

		if ( $filtered !== $checked && $filtered !== wpmcp_kses_equivalent( $checked ) ) {
			$found[] = array(
				'path'       => $path,
				'constructs' => wpmcp_kses_losses( $checked, $filtered ),
				'stored'     => $filtered,
			);
		}

		if ( ! $freeform && ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			wpmcp_find_unstable_blocks( $block['innerBlocks'], $known, $path, $found );
		}
	}
}

/**
 * The markup with the two rewrites kses makes that change nothing.
 *
 * kses rebuilds every tag it touches, and a self-closing one comes back
 * with " />" where Gutenberg writes "/>". It also turns a lone "&" into
 * "&amp;". Taken literally, "kses changes nothing" would therefore refuse
 * every image block and every "Mueller & Soehne" an agent writes.
 *
 * Only rewrites a browser reads exactly like the original are made here,
 * so markup equal to its kses version after them means the same thing
 * as that kses version:
 *
 * - "/>" right after a quoted value or a bare tag name becomes " />";
 *   the slash is ignored on void elements, the space is no attribute.
 *   After an unquoted value it is left alone, because there the slash
 *   belongs to the value.
 * - "&" followed by whitespace becomes "&amp;"; a character reference
 *   never starts like that. An "&" in front of letters is left alone:
 *   "&colon;" is a colon to a browser, so that difference stays a
 *   difference and the block is refused.
 *
 * @param string $html Markup.
 * @return string
 */
function wpmcp_kses_equivalent( $html ) {
	$html = (string) preg_replace( '#(["\'])\s*/>#', '$1 />', (string) $html );
	$html = (string) preg_replace( '#<([a-zA-Z][a-zA-Z0-9]*)\s*/>#', '<$1 />', $html );

	return (string) preg_replace( '/&(?=\s)/', '&amp;', $html );
}

/**
 * Name what kses takes out of a piece of markup.
 *
 * For the message only - the decision has already been made by comparing
 * the markup with kses's version of it. Elements, attributes and URL
 * schemes are counted on both sides; whatever kses has fewer of is what
 * it removed. When nothing is missing, kses only rewrote the markup
 * (quotes, spacing, an unescaped ampersand), and the caller gets told to
 * send it the way WordPress would store it.
 *
 * @param string $html     Markup as sent.
 * @param string $filtered The same after wp_kses_post.
 * @return string[]
 */
function wpmcp_kses_losses( $html, $filtered ) {
	$sent = wpmcp_markup_inventory( $html );
	$kept = wpmcp_markup_inventory( $filtered );

	$losses = array();
	foreach ( $sent as $item => $count ) {
		if ( $count > ( $kept[ $item ] ?? 0 ) ) {
			$losses[] = $item;
		}
	}

	return $losses;
}

/**
 * Elements, attributes and URL schemes in a piece of markup, counted.
 *
 * Attribute names are found after whitespace or a "/", because browsers
 * accept both as a separator. A scheme is read the way a browser reads
 * it: entities decoded, whitespace and control characters dropped.
 *
 * @param string $html Markup.
 * @return array<string, int> "<svg", "onload=", "javascript:" => count.
 */
function wpmcp_markup_inventory( $html ) {
	$items = array();
	$add   = function ( $key ) use ( &$items ) {
		$items[ $key ] = ( $items[ $key ] ?? 0 ) + 1;
	};

	if ( ! preg_match_all( '#<([a-zA-Z][a-zA-Z0-9:-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>?#', (string) $html, $tags, PREG_SET_ORDER ) ) {
		return $items;
	}

	foreach ( $tags as $tag ) {
		$add( '<' . strtolower( $tag[1] ) );

		if ( ! preg_match_all( '#([^\s"\'>/=]+)(?:\s*=\s*("[^"]*"|\'[^\']*\'|[^\s"\'>]+))?#', $tag[2], $attrs, PREG_SET_ORDER ) ) {
			continue;
		}

		foreach ( $attrs as $attr ) {
			$add( strtolower( $attr[1] ) . '=' );

			$value = html_entity_decode( trim( $attr[2] ?? '', '"\'' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$value = preg_replace( '/[\x00-\x20]+/', '', $value );
			if ( preg_match( '#^([a-z][a-z0-9+.-]*):#i', (string) $value, $scheme ) ) {
				$add( strtolower( $scheme[1] ) . ':' );
			}
		}
	}

	return $items;
}

/**
 * The refused blocks as one line: path and what kses would remove.
 *
 * @param array $blocks Result of wpmcp_unstable_blocks().
 * @return string
 */
function wpmcp_unstable_summary( array $blocks ) {
	$parts = array();

	foreach ( $blocks as $block ) {
		$parts[] = empty( $block['constructs'] )
			? sprintf( 'block %s (only rewritten; WordPress would store it as: %s)', $block['path'], wpmcp_shorten( $block['stored'], 120 ) )
			: sprintf( 'block %s (%s)', $block['path'], implode( ', ', $block['constructs'] ) );
	}

	return implode( '; ', $parts );
}

/**
 * The refusal for markup kses would alter, said once for every caller.
 *
 * @param array $impact Result of wpmcp_kses_impact().
 * @return string
 */
function wpmcp_filtered_markup_error( array $impact ) {
	$where = ! empty( $impact['blocks'] )
		? wpmcp_unstable_summary( $impact['blocks'] )
		: implode( ', ', $impact['added'] );

	return sprintf(
		'This change adds markup WordPress will not store from an agent account: %s. Event handlers, javascript: URLs, inline scripts, iframes, embeds and forms cannot be written this way; structured data can, as <script type="application/ld+json"> holding valid JSON. Remove it, or have a human add it in the editor. A block that only needs rewriting goes through when sent exactly as WordPress would store it. To repair content of this kind through the connector, a developer can open the door deliberately with the wpmcp_allow_filtered_markup filter.',
		$where
	);
}

/**
 * Say who refused a save, when it was not this plugin.
 *
 * Validation passed, the dry run said yes, and then wp_update_post came
 * back with an error — from a plugin filtering saves, in a message the
 * connector has never seen before. That reads like the connector broke,
 * and the last time it happened it cost most of an afternoon and ended in
 * a proposal to hand the agent unfiltered_html, which would not have
 * helped: the content filter does not refuse saves, it rewrites content
 * silently. That is the whole reason the impact check exists.
 *
 * So the error says where it came from and names the plugins that filter
 * saves on this site, which turns guesswork into one thing to look at.
 *
 * @param \WP_Error $error  Error from the save.
 * @param array     $impact Result of wpmcp_kses_impact().
 * @return \WP_Error
 */
function wpmcp_explain_save_refusal( $error, array $impact ) {
	$message = $error->get_error_message();

	// The one refusal with a setting behind it. Left unexplained it read as
	// "the plugin filters my content", and three separate sprints ended up
	// editing the database around the API instead of reporting it.
	if ( wpmcp_looks_like_unfiltered_html( $message ) ) {
		$user = wp_get_current_user();

		return new \WP_Error(
			'wpmcp_dynamic_data_blocked',
			sprintf(
				'%s The block library on this site requires the unfiltered_html capability to save a page holding dynamic data, and the agent account (%s) does not have it — deliberately, because it permits storing arbitrary HTML and JavaScript. The connector can grant it for the length of a single save: set "Dynamic data" to allowed under Tools > MCP Connector. A write that newly introduces a script tag, an inline event handler or a javascript: URL is still refused then, and the capability never sits on the role. Editing the database directly with WP-CLI gets past this because it runs without a user, which means none of these checks happen at all — that is a way around the problem, not a fix for it.',
				rtrim( $message, ' .' ) . '.',
				$user && $user->user_login ? $user->user_login : 'the agent'
			),
			$error->get_error_data()
		);
	}

	$lines = array(
		rtrim( $message, ' .' ) . '.',
		'This refusal comes from WordPress or another plugin at save time, not from the connector: its own validation passed and the dry run reported no errors.',
	);

	if ( empty( $impact['introduces'] ) ) {
		$lines[] = 'The change itself adds no markup WordPress filters, so the objection concerns content that was already stored on this page.';
	}

	$plugins = wpmcp_save_filter_plugins();
	if ( ! empty( $plugins ) ) {
		$lines[] = sprintf(
			'Plugins filtering saves on this site: %s. One of them is refusing — check its settings, or make this change in the block editor where it runs as your own user.',
			implode( ', ', $plugins )
		);
	}

	$lines[] = 'Granting the agent unfiltered_html will not help here. That capability governs the content filter, which strips markup silently and never refuses a save.';

	return new \WP_Error( $error->get_error_code(), implode( ' ', $lines ), $error->get_error_data() );
}

/**
 * Is this refusal the unfiltered_html one wearing another plugin's words?
 *
 * Plugins phrase it their own way — "dynamic data", "your account doesn't
 * have permission to save" — and none of them name the capability. The
 * shape is recognisable enough to answer usefully, and guessing wrong
 * only means the caller gets the general explanation instead.
 *
 * @param string $message Error message from the save.
 * @return bool
 */
function wpmcp_looks_like_unfiltered_html( $message ) {
	$message = strtolower( (string) $message );

	if ( false !== strpos( $message, 'unfiltered_html' ) ) {
		return true;
	}

	return false !== strpos( $message, 'dynamic data' )
		&& false !== strpos( $message, 'permission' );
}

/**
 * Which plugins have a hand in what gets saved.
 *
 * Read-only look at the hook registry, on the error path only. Resolving
 * a callback to the file it lives in is what turns "something refused"
 * into a plugin name.
 *
 * @return string[] Plugin directory names, deduplicated.
 */
function wpmcp_save_filter_plugins() {
	global $wp_filter;

	$hooks   = array( 'wp_insert_post_data', 'wp_insert_post_empty_content', 'content_save_pre' );
	$plugins = array();

	foreach ( $hooks as $hook ) {
		if ( empty( $wp_filter[ $hook ] ) || ! isset( $wp_filter[ $hook ]->callbacks ) ) {
			continue;
		}

		foreach ( $wp_filter[ $hook ]->callbacks as $bucket ) {
			foreach ( $bucket as $registered ) {
				$file = wpmcp_callback_file( $registered['function'] ?? null );
				if ( ! $file ) {
					continue;
				}
				$slug = wpmcp_plugin_slug_from_path( $file );
				if ( $slug && ! in_array( $slug, $plugins, true ) ) {
					$plugins[] = $slug;
				}
			}
		}
	}

	sort( $plugins );

	return $plugins;
}

/**
 * The file a callback is defined in, or null when it cannot be resolved.
 *
 * @param mixed $callback Anything add_filter accepts.
 * @return string|null
 */
function wpmcp_callback_file( $callback ) {
	try {
		if ( is_string( $callback ) && function_exists( $callback ) ) {
			$reflection = new \ReflectionFunction( $callback );
		} elseif ( $callback instanceof \Closure ) {
			$reflection = new \ReflectionFunction( $callback );
		} elseif ( is_array( $callback ) && 2 === count( $callback ) ) {
			$reflection = new \ReflectionMethod( $callback[0], $callback[1] );
		} elseif ( is_object( $callback ) && method_exists( $callback, '__invoke' ) ) {
			$reflection = new \ReflectionMethod( $callback, '__invoke' );
		} else {
			return null;
		}
	} catch ( \Throwable $e ) {
		return null;
	}

	$file = $reflection->getFileName();

	return $file ? $file : null;
}

/**
 * Name the plugin a file belongs to, skipping core and this plugin.
 *
 * @param string $file Absolute path.
 * @return string|null
 */
function wpmcp_plugin_slug_from_path( $file ) {
	$file = wp_normalize_path( $file );
	$dir  = wp_normalize_path( defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '' );

	if ( '' === $dir || 0 !== strpos( $file, $dir . '/' ) ) {
		return null;
	}

	$relative = substr( $file, strlen( $dir ) + 1 );
	$slug     = strtok( $relative, '/' );

	// Not news to anyone: this plugin also filters saves.
	if ( ! $slug || 0 === strpos( $slug, 'wp-mcp-connector-plus' ) ) {
		return null;
	}

	return $slug;
}

/**
 * Save with unfiltered_html, for exactly one call.
 *
 * Some block libraries refuse to store dynamic data unless the account
 * holds unfiltered_html. Putting that capability on the agent role would
 * undo the rest of the role: no publishing, no deleting, no uploads, no
 * settings — and then permission to store arbitrary HTML and JavaScript.
 *
 * So it is granted around one wp_update_post and taken away again. The
 * filter is scoped to the user being checked, and removed in a finally
 * block so a fatal inside the save cannot leave it standing.
 *
 * Callers must have refused the write first when wpmcp_kses_impact()
 * reports it introduces anything. With this capability WordPress stops
 * filtering the content, so that check is not a warning, it is the
 * replacement for what wp_kses would otherwise have done.
 *
 * @param array $postarr Arguments for wp_update_post.
 * @return int|\WP_Error
 */
function wpmcp_update_post_elevated( array $postarr ) {
	$release = wpmcp_grant_caps_for_request( array( 'unfiltered_html' ) );

	try {
		// Check that the grant took, rather than assuming it. unfiltered_html
		// is a meta capability: a site can turn it into do_not_allow from
		// wp-config, and then no amount of granting reaches it. Without this
		// the save goes ahead and fails with the block library's own message,
		// which says nothing about why — and the last time that happened the
		// conclusion was that hook priorities were wrong.
		$blocker = wpmcp_unfiltered_html_blocker();
		if ( $blocker ) {
			return new \WP_Error( 'wpmcp_unfiltered_html_unavailable', $blocker );
		}

		return wpmcp_update_post_preserving( $postarr );
	} finally {
		$release();
	}
}

/**
 * Why unfiltered_html cannot be granted here, if it cannot.
 *
 * Two ways a site puts it out of reach for everybody, administrators
 * included. Both are deliberate decisions by whoever set the site up, and
 * neither is this plugin's to overrule — so the answer is to name them.
 *
 * @return string|null Explanation, or null when the capability is reachable.
 */
function wpmcp_unfiltered_html_blocker() {
	if ( defined( 'DISALLOW_UNFILTERED_HTML' ) && DISALLOW_UNFILTERED_HTML ) {
		return 'This site defines DISALLOW_UNFILTERED_HTML in wp-config.php, which WordPress turns into a "do_not_allow" for every account, administrators included. No setting in this plugin can reach past that, and it should not: it is a decision the site was configured with on purpose. Either remove the constant, or leave pages with dynamic data to a human in the editor — where the same constant applies, so an administrator will have to lift it there too.';
	}

	if ( is_multisite() && function_exists( 'is_super_admin' ) && ! is_super_admin( get_current_user_id() ) ) {
		return 'On multisite WordPress reserves unfiltered_html for super admins, so it cannot be granted to the agent account here.';
	}

	return null;
}

/**
 * Dangerous markup this change would add that is not already there.
 *
 * The counterpart to the elevated save. Whole elements are only half of
 * it: with unfiltered_html an onclick attribute or a javascript: href
 * goes straight into the database too, and neither is a tag.
 *
 * Only additions count. A page that already embeds a video must stay
 * editable, or the guard blocks the ordinary work it was meant to
 * protect.
 *
 * Since 0.18.3 this is no longer what decides a save. A list of patterns
 * is a list of the attacks someone thought of: <svg/onload>,
 * &#106;avascript:, a tab inside the scheme, a meta refresh all went past
 * it. The decision is wpmcp_unstable_blocks(), which asks kses itself.
 * This stays as a quick, readable description of the obvious cases.
 *
 * @param string $before Content currently stored.
 * @param string $after  Content about to be written.
 * @return array<int, array{kind: string, sample: string}>
 */
function wpmcp_unsafe_additions( $before, $after ) {
	$found = array();

	// Whole elements, compared by their exact text.
	$impact = wpmcp_kses_impact( $before, $after );
	foreach ( $impact['added'] as $element ) {
		$found[] = array(
			'kind'   => $element . '>',
			'sample' => $element . '>',
		);
	}

	$patterns = array(
		// An inline event handler is script without a script tag.
		'event handler'   => '#\s(on[a-z]+)\s*=\s*["\']?[^"\'>\s]#i',
		// A URL that executes rather than navigates.
		'javascript: URL' => '#(?:href|src|action|formaction)\s*=\s*["\']?\s*javascript:#i',
		'data: document'  => '#(?:href|src)\s*=\s*["\']?\s*data:text/html#i',
	);

	foreach ( $patterns as $label => $pattern ) {
		$in_after  = wpmcp_match_counts( $pattern, wpmcp_strip_safe_jsonld( (string) $after ) );
		$in_before = wpmcp_match_counts( $pattern, wpmcp_strip_safe_jsonld( (string) $before ) );

		foreach ( $in_after as $sample => $count ) {
			if ( $count > ( $in_before[ $sample ] ?? 0 ) ) {
				$found[] = array(
					'kind'   => $label,
					'sample' => trim( $sample ),
				);
			}
		}
	}

	return $found;
}

/**
 * Say what was refused and where, so it can be fixed rather than retried.
 *
 * @param array $unsafe Result of wpmcp_unsafe_additions().
 * @param array $blocks The blocks that were about to be written.
 * @return string
 */
function wpmcp_unsafe_message( array $unsafe, array $blocks ) {
	$parts = array();

	foreach ( $unsafe as $item ) {
		$path    = wpmcp_locate_markup( $blocks, $item['sample'] );
		$parts[] = sprintf(
			'%s (%s)%s',
			$item['kind'],
			wpmcp_shorten( $item['sample'], 60 ),
			null === $path ? '' : ' in block ' . $path
		);
	}

	return sprintf(
		'This change adds markup that can run code: %s. It is refused because saving pages with dynamic data switches off WordPress\'s own content filtering for that save, so this check stands in its place. Remove it, or have a human add it in the editor.',
		implode( '; ', $parts )
	);
}

/**
 * Count each distinct match of a pattern.
 *
 * @param string $pattern Regular expression.
 * @param string $content Content.
 * @return array<string, int>
 */
function wpmcp_match_counts( $pattern, $content ) {
	$counts = array();

	if ( preg_match_all( $pattern, $content, $matches ) ) {
		foreach ( $matches[0] as $match ) {
			$key            = strtolower( trim( $match ) );
			$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
		}
	}

	return $counts;
}

/**
 * Which block a piece of markup sits in.
 *
 * A path is worth more than a byte offset: it is what the caller would
 * use to look at the block or fix it.
 *
 * @param array  $blocks Parsed blocks.
 * @param string $needle Markup to look for.
 * @param string $prefix Path prefix, for recursion.
 * @return string|null
 */
function wpmcp_locate_markup( array $blocks, $needle, $prefix = '' ) {
	$index = 0;

	foreach ( $blocks as $block ) {
		if ( null === $block['blockName'] ) {
			if ( '' !== trim( (string) ( $block['innerHTML'] ?? '' ) ) ) {
				++$index;
			}
			continue;
		}

		$path = ( '' === $prefix ) ? (string) $index : $prefix . '.' . $index;

		if ( '' !== $needle && false !== stripos( (string) ( $block['innerHTML'] ?? '' ), $needle ) ) {
			return $path;
		}

		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			$deeper = wpmcp_locate_markup( $block['innerBlocks'], $needle, $path );
			if ( null !== $deeper ) {
				return $deeper;
			}
		}

		++$index;
	}

	return null;
}

/**
 * The one kind of script the connector writes: structured data.
 *
 * JSON-LD is a standard part of every SEO-minded page, and browsers do not
 * execute it. What makes a script dangerous is code, and a JSON-LD block
 * holding valid JSON contains none. The single way out of it would be a
 * "</script>" inside the data, closing the tag early; a "<" anywhere in
 * the data is therefore re-encoded as \u003C before it is stored, which
 * JSON parsers read back as the same character.
 *
 * Anything else stays refused: another type, an extra attribute on the
 * tag, or content that is not valid JSON is treated as the script it is.
 *
 * @return string
 */
function wpmcp_jsonld_regex() {
	return '#<script\s+type\s*=\s*(["\'])application/ld\+json\1\s*>(.*?)</script\s*>#is';
}

/**
 * Is this JSON-LD body safe as it stands?
 *
 * @param string $inner Text between the script tags.
 * @return bool
 */
function wpmcp_jsonld_inner_safe( $inner ) {
	json_decode( (string) $inner );

	return JSON_ERROR_NONE === json_last_error() && false === strpos( (string) $inner, '<' );
}

/**
 * Make every JSON-LD block safe to store, leaving safe ones byte-identical.
 *
 * @param string $html Serialized content.
 * @return string
 */
function wpmcp_normalize_jsonld( $html ) {
	return (string) preg_replace_callback(
		wpmcp_jsonld_regex(),
		function ( $m ) {
			if ( wpmcp_jsonld_inner_safe( $m[2] ) ) {
				return $m[0];
			}

			$data = json_decode( $m[2], true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return $m[0];
			}

			$json = wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );

			return '<script type="application/ld+json">' . "\n" . $json . "\n" . '</script>';
		},
		(string) $html
	);
}

/**
 * Remove safe JSON-LD before looking for markup that runs.
 *
 * @param string $html Content.
 * @return string
 */
function wpmcp_strip_safe_jsonld( $html ) {
	return (string) preg_replace_callback(
		wpmcp_jsonld_regex(),
		function ( $m ) {
			return wpmcp_jsonld_inner_safe( $m[2] ) ? '' : $m[0];
		},
		(string) $html
	);
}

/**
 * Take a structured argument in whatever shape it survived the trip in.
 *
 * A 32 KB privacy policy could not be written in one call. The error said
 * `input[ops][0] is not of type object`, which sounds like a bad
 * operation and is not: past a certain size the client hands the argument
 * over as a JSON string, and WordPress's REST layer treats a scalar where
 * it wants an array by splitting it on commas (rest_is_array ->
 * wp_parse_list). Item 0 is then a fragment of JSON text, and the
 * complaint is literally true and completely misleading.
 *
 * The workaround was thirteen placeholder blocks and fifteen sequential
 * writes for one page. So a string is accepted and decoded here, and the
 * schema no longer insists on an array, which is what let the split
 * happen in the first place.
 *
 * The wreckage is not glued back together. Rejoining on commas would
 * return "Komma, Punkt" as "Komma,Punkt" — a silent change to the
 * customer's text, which is worse than any error message.
 *
 * @param mixed  $value Whatever arrived.
 * @param string $label Argument name, for the message.
 * @return array|\WP_Error
 */
function wpmcp_decode_structure( $value, $label ) {
	if ( is_array( $value ) ) {
		// Structured, as intended: every element is itself a structure.
		if ( ! empty( $value ) && count( array_filter( $value, 'is_scalar' ) ) !== count( $value ) ) {
			return $value;
		}

		// A map of named values, like meta: comma-split remains are always
		// a plain list, never keyed by name.
		if ( ! empty( $value ) && array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			return $value;
		}

		if ( ! empty( $value ) ) {
			return new \WP_Error(
				'wpmcp_bad_payload',
				sprintf(
					'"%s" arrived as a list of %d plain strings rather than operations. That is what WordPress leaves when a large argument is sent as text: it splits it on commas. Sending it as JSON text is fine — the connector decodes that — but it must arrive in one piece.',
					$label,
					count( $value )
				)
			);
		}

		return $value;
	}

	if ( ! is_string( $value ) ) {
		return new \WP_Error(
			'wpmcp_bad_request',
			sprintf( '"%s" must be a list or an object.', $label )
		);
	}

	$decoded = json_decode( $value, true );

	if ( ! is_array( $decoded ) ) {
		return new \WP_Error(
			'wpmcp_bad_payload',
			sprintf(
				'"%s" arrived as text (%d bytes) and is not valid JSON: %s. Large arguments are sometimes sent as a string; the connector decodes those, but this one did not survive the trip intact — it was probably truncated. Split the change into smaller operations.',
				$label,
				strlen( $value ),
				json_last_error_msg()
			)
		);
	}

	return $decoded;
}

/**
 * Should this save bypass WordPress's content filter?
 *
 * Two reasons, and only these two. Editing one block must not destroy
 * markup in another that the agent never touched — safe, because every
 * block the agent did touch would come out of kses unchanged ("introduces"
 * is false only then, see wpmcp_unstable_blocks()). And a repair explicitly opened by the site
 * has to reach the database, or opening it means nothing: the check would
 * let the script through and the save would drop it a moment later.
 *
 * The bypass is one call wide. It removes the filter, saves, puts it back.
 * Granting the agent unfiltered_html instead would leave the door open for
 * everything else that runs in the request, and for whatever forgets to
 * take it away again.
 *
 * @param array         $impact Result of wpmcp_kses_impact().
 * @param \WP_Post|null $post   Post being written.
 * @return bool
 */
function wpmcp_should_preserve_markup( array $impact, $post = null ) {
	if ( empty( $impact['alters'] ) ) {
		return false;
	}

	return empty( $impact['introduces'] ) || wpmcp_filtered_markup_allowed( $post );
}

/**
 * May this save introduce markup WordPress would strip?
 *
 * No, by default: an agent that can write a script tag can write anything
 * a script can do, and that is not what a remote editor is for.
 *
 * There is one case for opening it, and it is a repair. When a page lost
 * its JSON-LD to an unfiltered save, nobody can put it back through the
 * connector — the block editor is the only way in, page by page. A
 * developer with filesystem access can open this for the length of that
 * job and close it again. It is deliberately not a setting in the admin:
 * a checkbox invites being left on.
 *
 * @param \WP_Post $post Post being written.
 * @return bool
 */
function wpmcp_filtered_markup_allowed( $post ) {
	/**
	 * Whether the agent may write markup that requires unfiltered_html.
	 *
	 * @param bool     $allow Default false.
	 * @param \WP_Post $post  Post being written.
	 */
	return (bool) apply_filters( 'wpmcp_allow_filtered_markup', false, $post );
}

/**
 * Every piece of markup in this content that WordPress would filter,
 * counted by its exact text.
 *
 * Identity is what matters here, not quantity: a fragment that was in the
 * page before may stay, anything else is new. Whole elements are taken for
 * script, style and iframe, because their content is the point; for the
 * rest the opening tag is enough to identify them.
 *
 * @param string $content Post content.
 * @return array<string, int> Fragment text => occurrences.
 */
function wpmcp_filtered_fragments( $content ) {
	$patterns = array(
		'#<script\b[^>]*>.*?</script\s*>#is',
		'#<style\b[^>]*>.*?</style\s*>#is',
		'#<iframe\b[^>]*>.*?</iframe\s*>#is',
		'#<(?:form|object|embed)\b[^>]*>#i',
		// An unclosed script or iframe still gets filtered out.
		'#<(?:script|style|iframe)\b[^>]*>#i',
	);

	$found = array();
	$rest  = wpmcp_strip_safe_jsonld( $content );

	foreach ( $patterns as $pattern ) {
		if ( preg_match_all( $pattern, $rest, $matches ) ) {
			foreach ( $matches[0] as $fragment ) {
				$found[ $fragment ] = ( $found[ $fragment ] ?? 0 ) + 1;
			}
			// Take the matches out so the fallback pattern does not count
			// the opening tag of an element already matched whole.
			$rest = preg_replace( $pattern, '', $rest );
		}
	}

	return $found;
}

/**
 * Name a fragment by its element, for a message a human reads.
 *
 * @param string $fragment Matched markup.
 * @return string
 */
function wpmcp_fragment_label( $fragment ) {
	return preg_match( '#^<\s*([a-z]+)#i', $fragment, $m ) ? '<' . strtolower( $m[1] ) : $fragment;
}

/**
 * Run a save with WordPress's content filter switched off, and only that.
 *
 * kses sits on two filters for accounts without unfiltered_html. They are
 * removed for the length of the callback and put back in a finally, so a
 * fatal inside the save cannot leave the rest of the request unfiltered.
 * Only the filters that were actually there go back: an account that
 * never had them - one with unfiltered_html - must not leave this with a
 * filter it did not have before.
 *
 * Whoever calls this has decided the content may skip the filter. For
 * anything the agent wrote, that decision is wpmcp_should_preserve_markup().
 *
 * @param callable $save The save.
 * @return mixed Whatever the save returns.
 */
function wpmcp_without_kses( callable $save ) {
	$removed = array();

	foreach ( array( 'content_save_pre', 'content_filtered_save_pre' ) as $filter ) {
		if ( remove_filter( $filter, 'wp_filter_post_kses' ) ) {
			$removed[] = $filter;
		}
	}

	try {
		return $save();
	} finally {
		foreach ( $removed as $filter ) {
			add_filter( $filter, 'wp_filter_post_kses' );
		}
	}
}

/**
 * Insert a post without the content filter.
 *
 * A duplicate is a byte-for-byte copy of a post that already exists on
 * this site. Filtering it would strip markup the original is allowed to
 * hold — the copy would silently differ from what was copied, which is
 * the one thing a duplicate must never do. The agent supplies no content
 * here, only an id, so there is nothing it could smuggle in. The title it
 * may supply still goes through its own filter, which stays on.
 *
 * @param array $postarr Arguments for wp_insert_post.
 * @return int|\WP_Error
 */
function wpmcp_insert_post_preserving( array $postarr ) {
	return wpmcp_without_kses(
		function () use ( $postarr ) {
			return wp_insert_post( $postarr, true );
		}
	);
}

/**
 * Save without the kses filter, for the one case where it does harm.
 *
 * Only ever used when every block the agent changed would come out of
 * kses unchanged (wpmcp_should_preserve_markup), so skipping the filter
 * changes nothing about the agent's part. What it protects is the rest:
 * markup already stored in blocks the change did not touch.
 *
 * @param array $postarr Arguments for wp_update_post.
 * @return int|\WP_Error
 */
function wpmcp_update_post_preserving( array $postarr ) {
	return wpmcp_without_kses(
		function () use ( $postarr ) {
			return wp_update_post( $postarr, true );
		}
	);
}

/**
 * Compare what we sent against what WordPress actually stored.
 *
 * Between serialize_blocks() and the database sits WordPress itself.
 * Users without `unfiltered_html` — which the agent role deliberately is —
 * have their content run through wp_kses_post on save, and that silently
 * removes script tags, iframes and various attributes. A JSON-LD block
 * disappears without a word in any log.
 *
 * The validation pipeline checks what we are about to send. This checks
 * what arrived, which is the only thing that matters afterwards.
 *
 * @param int    $post_id  Post that was written.
 * @param string $expected Serialized markup we handed to WordPress.
 * @return string[] Warnings, empty when the content survived intact.
 */
function wpmcp_verify_stored( $post_id, $expected ) {
	$stored = get_post_field( 'post_content', $post_id, 'raw' );

	if ( (string) $stored === (string) $expected ) {
		return array();
	}

	$warnings = array();

	$expected_blocks = parse_blocks( $expected );
	$stored_blocks   = parse_blocks( (string) $stored );

	$before = wpmcp_count_blocks( $expected_blocks );
	$after  = wpmcp_count_blocks( $stored_blocks );
	if ( $before !== $after ) {
		$warnings[] = sprintf(
			'WordPress stored %d blocks where %d were sent. Some content was rejected on save.',
			$after,
			$before
		);
	}

	// Name the usual suspects, because "something changed" is not actionable.
	$stripped = array();
	foreach ( array( '<script' => 'script tags', '<iframe' => 'iframes', '<style' => 'style tags' ) as $needle => $label ) {
		$sent_count   = substr_count( $expected, $needle );
		$stored_count = substr_count( (string) $stored, $needle );
		if ( $sent_count > $stored_count ) {
			$stripped[] = sprintf( '%d %s', $sent_count - $stored_count, $label );
		}
	}

	if ( ! empty( $stripped ) ) {
		$warnings[] = sprintf(
			'WordPress removed %s while saving. The agent account has no unfiltered_html capability, so markup of this kind cannot be written — structured data, embeds and inline scripts are lost. Add them by hand.',
			implode( ' and ', $stripped )
		);
	} elseif ( empty( $warnings ) ) {
		$warnings[] = 'The stored content differs from what was sent. WordPress altered it on save; compare the result before relying on it.';
	}

	return $warnings;
}

/**
 * The title a duplicate gets: the one asked for, or the original's plus
 * " (Copy)" in the site's language.
 *
 * It was " (Kopie)" on every site, whatever its language.
 *
 * @param string $original Title of the source.
 * @param string $title    Title asked for, may be empty.
 * @return string
 */
function wpmcp_copy_title( $original, $title = '' ) {
	$title = trim( (string) $title );
	if ( '' !== $title ) {
		return $title;
	}

	/* translators: %s: title of the page that was duplicated */
	return sprintf( __( '%s (Copy)', 'wp-mcp-connector-plus' ), (string) $original );
}

/**
 * Duplicate a post as a draft — the preferred way to start a new page,
 * because it inherits the structure and tone the site already uses.
 *
 * Extracted from the core's duplicate-post.php, which is bound to $_GET
 * and wp_die() and cannot be called programmatically.
 *
 * @param int    $post_id Source post ID.
 * @param string $title   Optional new title.
 * @return array|\WP_Error
 */
function wpmcp_duplicate_post( $post_id, $title = '' ) {
	$post = wpmcp_get_readable_post( $post_id );
	if ( is_wp_error( $post ) ) {
		return $post;
	}

	// The copy is of the same kind as the original, meta included: a copy
	// of an element that runs PHP runs PHP too, and a copied pattern is a
	// pattern written by the agent.
	$target = wpmcp_assert_writable_target( $post );
	if ( is_wp_error( $target ) ) {
		return $target;
	}

	$type_object = get_post_type_object( $post->post_type );
	if ( ! $type_object || ! current_user_can( $type_object->cap->create_posts ) ) {
		return new \WP_Error( 'wpmcp_forbidden', sprintf( 'No permission to create %s content.', $post->post_type ) );
	}

	$new_title = wpmcp_copy_title( $post->post_title, $title );

	$new_id = wpmcp_insert_post_preserving(
		wp_slash(
			array(
				'post_title'     => $new_title,
				'post_content'   => $post->post_content,
				'post_excerpt'   => $post->post_excerpt,
				'post_type'      => $post->post_type,
				'post_parent'    => $post->post_parent,
				'menu_order'     => $post->menu_order,
				'comment_status' => $post->comment_status,
				'ping_status'    => $post->ping_status,
				'post_status'    => 'draft', // Always a draft: publishing stays human.
				'post_author'    => get_current_user_id(),
			)
		)
	);

	if ( is_wp_error( $new_id ) ) {
		return $new_id;
	}

	// Taxonomies.
	foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
		$terms = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'slugs' ) );
		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			wp_set_object_terms( $new_id, $terms, $taxonomy );
		}
	}

	// Meta, minus WordPress bookkeeping.
	$skip = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date' );
	foreach ( get_post_meta( $post->ID ) as $key => $values ) {
		if ( in_array( $key, $skip, true ) ) {
			continue;
		}
		foreach ( $values as $value ) {
			add_post_meta( $new_id, $key, wp_slash( maybe_unserialize( $value ) ) );
		}
	}

	$stored_warnings = wpmcp_verify_stored( $new_id, $post->post_content );

	wpmcp_log(
		'wpmcp/content-duplicate',
		array(
			'post_id'   => $new_id,
			'operation' => 'duplicate',
			'summary'   => sprintf( 'Duplicated from post %d as draft "%s".', $post->ID, $new_title ),
		)
	);

	$result = array(
		'ok'       => true,
		'id'       => (int) $new_id,
		'sourceId' => $post->ID,
		'title'    => $new_title,
		'status'   => 'draft',
		'preview'  => wpmcp_preview_url( (int) $new_id ),
		'message'  => 'Created as a draft. A human publishes it.',
	);

	if ( ! empty( $stored_warnings ) ) {
		$result['warnings']       = $stored_warnings;
		$result['contentAltered'] = true;
	}

	return $result;
}

/**
 * Render a post for self-inspection: server-rendered HTML plus a signed
 * preview link that works without a login.
 *
 * @param int  $post_id      Post ID.
 * @param bool $include_html Whether to return the rendered HTML.
 * @return array|\WP_Error
 */
function wpmcp_preview_content( $post_id, $include_html = true, $offset = 0 ) {
	$post = wpmcp_get_readable_post( $post_id );
	if ( is_wp_error( $post ) ) {
		return $post;
	}

	$preview = wpmcp_preview_url( $post->ID );

	$result = array(
		'id'          => $post->ID,
		'title'       => get_the_title( $post ),
		'status'      => $post->post_status,
		'previewUrl'  => $preview['url'],
		'expiresAt'   => gmdate( 'c', $preview['expires'] ),
		'permalink'   => get_permalink( $post ),
	);

	if ( $include_html ) {
		$rendered = wpmcp_render_post_html( $post, $offset );
		if ( is_wp_error( $rendered ) ) {
			$result['renderError'] = $rendered->get_error_message();
		} else {
			foreach ( array( 'html', 'headings', 'bytes', 'offset', 'truncated', 'nextOffset', 'note' ) as $key ) {
				if ( isset( $rendered[ $key ] ) ) {
					$result[ $key ] = $rendered[ $key ];
				}
			}
			if ( ! empty( $rendered['notices'] ) ) {
				$result['renderNotices'] = $rendered['notices'];
			}
		}
	}

	wpmcp_log(
		'wpmcp/content-preview',
		array(
			'post_id'   => $post->ID,
			'operation' => 'preview',
			'summary'   => 'Preview link issued.',
		)
	);

	return $result;
}

/**
 * Render post content through the block renderer, capped in size, plus the
 * heading outline — enough for the AI to check its own work structurally.
 *
 * @param \WP_Post $post   Post.
 * @param int      $offset Byte to start the markup window at.
 * @return array|\WP_Error
 */
function wpmcp_render_post_html( $post, $offset = 0 ) {
	$smoke = wpmcp_render_smoke_test( $post->post_content );
	if ( is_wp_error( $smoke ) ) {
		return $smoke;
	}

	$html = do_blocks( $post->post_content );
	$html = do_shortcode( $html );

	$headings = array();
	if ( preg_match_all( '/<h([1-6])[^>]*>(.*?)<\/h\1>/is', $html, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			$headings[] = array(
				'level' => (int) $match[1],
				'text'  => wpmcp_shorten( wp_strip_all_tags( $match[2] ), 120 ),
			);
		}
	}

	$slice = wpmcp_slice_text( $html, 60000, $offset );

	return array_merge(
		array(
			'headings' => $headings,
			'notices'  => $smoke['notices'] ?? array(),
		),
		$slice
	);
}

/**
 * Cut a long text down to a window the caller can walk through.
 *
 * A comment in the middle of the markup saying it stopped there is easy to
 * miss and impossible to act on — a legal page was read, silently halved,
 * and judged on the half. The size and the next offset are fields, so
 * being cut off is a fact the caller can see and answer.
 *
 * @param string $text   Full text.
 * Offsets stay byte offsets, but both ends of a window sit on a character
 * boundary (wpmcp_utf8_boundary): a window ends before a character it
 * cannot hold whole, the next one starts with it, and an offset sent from
 * inside a character starts at that character. "offset" in the result is
 * where the window really begins.
 *
 * @param int    $max    Bytes to return at most.
 * @param int    $offset Byte to start at.
 * @return array { html: string, bytes: int, offset: int, truncated: bool, nextOffset?: int }
 */
function wpmcp_slice_text( $text, $max, $offset = 0 ) {
	$text   = (string) $text;
	$total  = strlen( $text );
	$offset = wpmcp_utf8_boundary( $text, (int) $offset );
	$slice  = wpmcp_utf8_cut( $text, $offset, $max );

	$result = array(
		'html'      => $slice,
		'bytes'     => $total,
		'offset'    => $offset,
		'truncated' => ( $offset + strlen( $slice ) ) < $total,
	);

	if ( $result['truncated'] ) {
		$result['nextOffset'] = $offset + strlen( $slice );
		$result['note']       = sprintf(
			'%d of %d bytes. Call again with offset: %d for the next part.',
			strlen( $slice ),
			$total,
			$result['nextOffset']
		);
	}

	return $result;
}

/**
 * Post meta worth showing an agent, by key.
 *
 * An allowlist rather than everything: post meta is where plugins keep
 * licence keys, tokens and internal state, and none of that belongs in a
 * model's context. These are the fields a person doing an editorial review
 * would look at.
 *
 * @return array<string, string> Meta key => readable label.
 */
function wpmcp_readable_meta_keys() {
	return apply_filters(
		'wpmcp_readable_meta_keys',
		array(
			// Rank Math.
			'rank_math_title'          => 'SEO title',
			'rank_math_description'    => 'Meta description',
			'rank_math_focus_keyword'  => 'Focus keyword',
			'rank_math_canonical_url'  => 'Canonical URL',
			'rank_math_robots'         => 'Robots',
			'rank_math_facebook_image' => 'Social image',
			'rank_math_schema_type'    => 'Schema type',
			// Yoast.
			'_yoast_wpseo_title'         => 'SEO title',
			'_yoast_wpseo_metadesc'      => 'Meta description',
			'_yoast_wpseo_focuskw'       => 'Focus keyword',
			'_yoast_wpseo_canonical'     => 'Canonical URL',
			'_yoast_wpseo_meta-robots-noindex' => 'Robots: noindex',
			'_yoast_wpseo_opengraph-image'     => 'Social image',
		)
	);
}

/**
 * Active plugins that change what content work means here.
 *
 * Not an inventory. Which SEO plugin runs decides which meta keys exist,
 * a form or page-builder plugin decides what the markup on a page is
 * allowed to be, and a caching plugin decides whether a live check can be
 * trusted. Without this an agent infers all of it from failures.
 *
 * Only active plugins, and only what they are and which version — enough
 * to work with, not a security report for anyone who gets the credentials.
 *
 * @return array<int, array{name: string, version: string, kind: string}>
 */
function wpmcp_relevant_plugins() {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	if ( ! function_exists( 'get_plugins' ) ) {
		return array();
	}

	// What a plugin means for content work, by what its folder is called.
	$kinds = array(
		'seo'        => '/(seo|rank-math|yoast|aioseo|schema)/i',
		'forms'      => '/(form|contact|wpcf7|gravity|ninja|fluent)/i',
		'blocks'     => '/(block|generate|kadence|stackable|spectra|greenshift)/i',
		'builder'    => '/(elementor|beaver|divi|wpbakery|bricks|oxygen)/i',
		'cache'      => '/(cache|rocket|litespeed|speed|optimi[sz]e)/i',
		'multilang'  => '/(polylang|wpml|translat|weglot)/i',
		'commerce'   => '/(woocommerce|edd|easy-digital)/i',
		'legal'      => '/(borlabs|cookie|erecht|complianz|consent|dsgvo|gdpr)/i',
	);

	$out = array();

	foreach ( get_plugins() as $file => $data ) {
		if ( ! is_plugin_active( $file ) ) {
			continue;
		}

		$slug = strtok( $file, '/' );
		$kind = null;

		foreach ( $kinds as $label => $pattern ) {
			if ( preg_match( $pattern, $slug . ' ' . ( $data['Name'] ?? '' ) ) ) {
				$kind = $label;
				break;
			}
		}

		if ( null === $kind ) {
			continue;
		}

		$out[] = array(
			'name'    => (string) ( $data['Name'] ?? $slug ),
			'version' => (string) ( $data['Version'] ?? '' ),
			'kind'    => $kind,
		);
	}

	return $out;
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

/**
 * Work out what a change to slug, parent or status would do.
 *
 * These three were untouchable, and the reason was sound for exactly one
 * of the two cases they cover. A published page's slug is what its URL
 * hangs on and what every link to it points at; its parent is part of
 * that URL too, and taking it back to draft removes it from the site.
 * None of that is an agent's call.
 *
 * A page that has never been published has none of those problems. It has
 * no URL anyone knows and no links pointing at it — and it is exactly
 * what the agent has just created. Refusing there meant building
 * twenty-two pages and then fixing twenty-two slugs and parents by hand
 * in wp-admin, which is not a safeguard, only work.
 *
 * So the line moves from "these fields" to "a page that is live". Nothing
 * published changes address or disappears, and a draft is a draft.
 *
 * @param \WP_Post $post Post being written.
 * @param array    $args { slug, parent, status } as supplied.
 * @return array { fields: array, errors: string[], changes: int }
 */
function wpmcp_placement_diff( $post, array $args ) {
	$fields  = array();
	$errors  = array();
	$changes = 0;
	$live    = in_array( $post->post_status, array( 'publish', 'future', 'private' ), true );

	$wanted = array();

	if ( array_key_exists( 'slug', $args ) && null !== $args['slug'] ) {
		$wanted['slug'] = array(
			'from' => $post->post_name,
			'to'   => sanitize_title( (string) $args['slug'] ),
		);
		if ( '' === $wanted['slug']['to'] ) {
			$errors[] = sprintf( '"%s" leaves nothing usable as a slug.', (string) $args['slug'] );
			unset( $wanted['slug'] );
		}
	}

	if ( array_key_exists( 'parent', $args ) && null !== $args['parent'] ) {
		$parent = (int) $args['parent'];
		$check  = wpmcp_check_parent( $post, $parent );
		if ( is_wp_error( $check ) ) {
			$errors[] = $check->get_error_message();
		} else {
			$wanted['parent'] = array(
				'from' => (int) $post->post_parent,
				'to'   => $parent,
			);
		}
	}

	if ( array_key_exists( 'status', $args ) && null !== $args['status'] ) {
		$status = (string) $args['status'];
		if ( ! in_array( $status, wpmcp_writable_statuses(), true ) ) {
			$errors[] = sprintf(
				'"%s" is not a status this connector sets%s. Allowed: %s.',
				$status,
				'publish' === $status
					? ' outside a work session — publishing becomes possible only while the site owner has one open'
					: '',
				implode( ', ', wpmcp_writable_statuses() )
			);
		} else {
			$wanted['status'] = array(
				'from' => $post->post_status,
				'to'   => $status,
			);
		}
	}

	foreach ( $wanted as $key => $field ) {
		$field['changed'] = ( (string) $field['from'] !== (string) $field['to'] );

		if ( $field['changed'] && $live ) {
			$errors[] = sprintf(
				'Post %d is published, so its %s stays as it is. Changing it would %s. Do it in the editor, where the redirect is yours to set up.',
				$post->ID,
				$key,
				'status' === $key ? 'take a live page off the site' : 'change the URL of a page that is already linked to'
			);
			continue;
		}

		$fields[ $key ] = $field;

		if ( $field['changed'] ) {
			++$changes;
		}
	}

	return array(
		'fields'  => $fields,
		'errors'  => $errors,
		'changes' => $changes,
	);
}

/**
 * Is this a parent the post may actually have?
 *
 * @param \WP_Post $post   Post being moved.
 * @param int      $parent Requested parent, 0 for none.
 * @return true|\WP_Error
 */
function wpmcp_check_parent( $post, $parent ) {
	if ( 0 === $parent ) {
		return true;
	}

	if ( $parent === (int) $post->ID ) {
		return new \WP_Error( 'wpmcp_bad_parent', 'A page cannot be its own parent.' );
	}

	$target = get_post( $parent );
	if ( ! $target ) {
		return new \WP_Error( 'wpmcp_bad_parent', sprintf( 'No post with ID %d to use as a parent.', $parent ) );
	}

	if ( $target->post_type !== $post->post_type ) {
		return new \WP_Error(
			'wpmcp_bad_parent',
			sprintf( 'Post %d is a "%s"; a "%s" cannot sit under it.', $parent, $target->post_type, $post->post_type )
		);
	}

	// A loop would take the page out of the tree entirely.
	$seen   = array( (int) $post->ID );
	$cursor = $target;
	while ( $cursor && (int) $cursor->post_parent ) {
		if ( in_array( (int) $cursor->post_parent, $seen, true ) ) {
			return new \WP_Error( 'wpmcp_bad_parent', sprintf( 'Post %d sits below this page; making it the parent would form a loop.', $parent ) );
		}
		$seen[]  = (int) $cursor->ID;
		$cursor = get_post( $cursor->post_parent );
	}

	return true;
}

/**
 * Meta prefixes a post type's own plugin keeps its settings under.
 *
 * A GeneratePress element is an empty shell without its meta: where it
 * shows, what kind it is, under which conditions. Creating one through the
 * connector and leaving those unset produces an element that displays
 * nowhere. So for post types that are nothing but their settings, the
 * plugin's own prefix is readable and writable — on that post type only,
 * never on a page.
 *
 * @return array<string, string[]> Post type => meta key prefixes.
 */
function wpmcp_post_type_meta_prefixes() {
	return apply_filters(
		'wpmcp_post_type_meta_prefixes',
		array(
			'gp_elements' => array( '_generate_' ),
		)
	);
}

/**
 * Meta keys that are never writable, whatever prefix they match.
 *
 * Some plugins keep a switch in meta that makes the post's content run as
 * PHP. Reachable through a prefix, that would be code execution on the
 * server by way of a settings field.
 *
 * @param string $key Meta key.
 * @return bool
 */
function wpmcp_meta_key_forbidden( $key ) {
	return (bool) preg_match( '/(php|execute|eval|(^|_)code($|_))/i', (string) $key );
}

/**
 * May this plugin meta key be read and written on this post?
 *
 * @param \WP_Post $post Post.
 * @param string   $key  Meta key.
 * @return bool
 */
function wpmcp_plugin_meta_allowed( $post, $key ) {
	if ( wpmcp_meta_key_forbidden( $key ) ) {
		return false;
	}

	$type     = isset( $post->post_type ) ? (string) $post->post_type : '';
	$prefixes = wpmcp_post_type_meta_prefixes()[ $type ] ?? array();

	foreach ( $prefixes as $prefix ) {
		if ( '' !== $prefix && 0 === strpos( (string) $key, $prefix ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Does this post run its own content as code?
 *
 * A GeneratePress hook element with "Execute PHP" switched on evaluates
 * its content on every page view. The script guard looks for markup that
 * runs in a browser; a line of PHP is plain text to it. Writing that
 * content would be writing code onto the server, so such a post is not
 * writable through the connector at all.
 *
 * @param \WP_Post $post Post.
 * @return bool
 */
function wpmcp_runs_code( $post ) {
	$flag = get_post_meta( $post->ID, '_generate_hook_execute_php', true );

	return ! empty( $flag ) && 'false' !== $flag;
}

/**
 * Clean a structured plugin meta value, keeping its shape.
 *
 * @param mixed $value Value as sent.
 * @return mixed
 */
function wpmcp_clean_plugin_meta( $value ) {
	if ( is_array( $value ) ) {
		$clean = array();
		foreach ( $value as $k => $v ) {
			$clean[ is_int( $k ) ? $k : sanitize_text_field( (string) $k ) ] = wpmcp_clean_plugin_meta( $v );
		}
		return $clean;
	}

	if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
		return $value;
	}

	return sanitize_text_field( (string) $value );
}

/**
 * Plugin meta on a post, as far as its post type exposes any.
 *
 * Reading comes first for a reason: the keys and value shapes differ per
 * plugin and version, and guessing them produces an element that saves
 * cleanly and displays nowhere. An existing element is the reference.
 *
 * @param \WP_Post $post Post.
 * @return array<string, mixed>
 */
function wpmcp_read_plugin_meta( $post ) {
	$out = array();

	foreach ( (array) get_post_meta( $post->ID ) as $key => $values ) {
		if ( ! wpmcp_plugin_meta_allowed( $post, $key ) ) {
			continue;
		}
		$raw         = is_array( $values ) ? ( $values[0] ?? '' ) : $values;
		$out[ $key ] = function_exists( 'maybe_unserialize' ) ? maybe_unserialize( $raw ) : $raw;
	}

	ksort( $out );

	return $out;
}

/**
 * Meta fields the agent may change.
 *
 * A whitelist, not an open door to post meta. Everything here belongs to
 * an SEO plugin and is a plain string that a human would otherwise retype
 * in a settings screen. Anything a block, a page builder or a licence
 * check keeps in meta stays out of reach.
 *
 * @return array<string, string> Key => how the value is cleaned.
 */
function wpmcp_writable_meta_keys() {
	return apply_filters(
		'wpmcp_writable_meta_keys',
		array(
			'rank_math_title'         => 'text',
			'rank_math_description'   => 'text',
			'rank_math_focus_keyword' => 'text',
			'rank_math_canonical_url' => 'url',
			'_yoast_wpseo_title'      => 'text',
			'_yoast_wpseo_metadesc'   => 'text',
			'_yoast_wpseo_focuskw'    => 'text',
			'_yoast_wpseo_canonical'  => 'url',
		)
	);
}

/**
 * Work out what a meta change would do, before it does it.
 *
 * Meta is the one thing a write cannot take back: WordPress revisions
 * cover post content, not post meta, so the usual one-click rollback does
 * not apply here. The previous value is therefore reported in the dry run
 * and recorded in the log, which is what makes the change reversible at
 * all.
 *
 * @param \WP_Post $post Post.
 * @param array    $meta Requested key => value.
 * @return array { fields: array, errors: string[], changes: int }
 */
function wpmcp_meta_diff( $post, array $meta ) {
	$allowed = wpmcp_writable_meta_keys();
	$labels  = wpmcp_readable_meta_keys();

	$fields  = array();
	$errors  = array();
	$changes = 0;

	foreach ( $meta as $key => $value ) {
		// A post type's own plugin settings, on that post type only.
		if ( ! isset( $allowed[ $key ] ) && wpmcp_plugin_meta_allowed( $post, $key ) ) {
			$from = get_post_meta( $post->ID, $key, true );
			$to   = ( null === $value ) ? '' : wpmcp_clean_plugin_meta( $value );

			$fields[ $key ] = array(
				'label'   => $key,
				'from'    => $from,
				'to'      => $to,
				'changed' => ( wp_json_encode( $from ) !== wp_json_encode( $to ) ),
			);

			if ( $fields[ $key ]['changed'] ) {
				++$changes;
			}
			continue;
		}

		if ( ! isset( $allowed[ $key ] ) ) {
			$type     = isset( $post->post_type ) ? (string) $post->post_type : '';
			$prefixes = wpmcp_post_type_meta_prefixes()[ $type ] ?? array();

			$errors[] = wpmcp_meta_key_forbidden( $key )
				? sprintf( '"%s" is never written through the connector: a key like this can make a post run its content as code.', (string) $key )
				: sprintf(
					'"%s" is not a meta field this connector writes. Allowed: %s%s.',
					(string) $key,
					implode( ', ', array_keys( $allowed ) ),
					empty( $prefixes ) ? '' : ', and on this post type keys starting with ' . implode( ', ', $prefixes )
				);
			continue;
		}

		if ( null !== $value && ! is_scalar( $value ) ) {
			$errors[] = sprintf( '"%s" must be a string, or null to clear it.', (string) $key );
			continue;
		}

		$from = (string) get_post_meta( $post->ID, $key, true );
		$to   = ( null === $value ) ? '' : wpmcp_clean_meta_value( (string) $value, $allowed[ $key ] );

		if ( 'url' === $allowed[ $key ] && '' !== $to && ! wp_http_validate_url( $to ) ) {
			$errors[] = sprintf( '"%s" is not a usable URL: %s', (string) $key, (string) $value );
			continue;
		}

		$fields[ $key ] = array(
			'label'   => $labels[ $key ] ?? $key,
			'from'    => $from,
			'to'      => $to,
			'changed' => ( $from !== $to ),
		);

		if ( $from !== $to ) {
			++$changes;
		}
	}

	return array(
		'fields'  => $fields,
		'errors'  => $errors,
		'changes' => $changes,
	);
}

/**
 * A log line that carries the old value, because nothing else will.
 *
 * The revision system does not cover meta. If this line does not say what
 * the field used to hold, nobody can put it back.
 *
 * @param array $fields Result of wpmcp_meta_diff()['fields'].
 * @return string
 */
function wpmcp_meta_log_line( array $fields ) {
	$parts = array();

	foreach ( $fields as $key => $field ) {
		if ( empty( $field['changed'] ) ) {
			continue;
		}
		$parts[] = sprintf(
			'%s: "%s" -> "%s"',
			$key,
			wpmcp_shorten( is_scalar( $field['from'] ) ? (string) $field['from'] : wp_json_encode( $field['from'] ), 80 ),
			wpmcp_shorten( is_scalar( $field['to'] ) ? (string) $field['to'] : wp_json_encode( $field['to'] ), 80 )
		);
	}

	return 'Meta ' . implode( '; ', $parts );
}

/**
 * Clean a meta value for its kind.
 *
 * @param string $value Raw value.
 * @param string $kind  'text' or 'url'.
 * @return string
 */
function wpmcp_clean_meta_value( $value, $kind ) {
	return 'url' === $kind ? esc_url_raw( trim( $value ) ) : sanitize_text_field( $value );
}

/**
 * Write the meta fields a diff decided on.
 *
 * @param \WP_Post $post   Post.
 * @param array    $fields Result of wpmcp_meta_diff()['fields'].
 * @return string[] Keys actually written.
 */
function wpmcp_apply_meta( $post, array $fields ) {
	$written = array();

	foreach ( $fields as $key => $field ) {
		if ( empty( $field['changed'] ) ) {
			continue;
		}
		if ( '' === $field['to'] ) {
			delete_post_meta( $post->ID, $key );
		} else {
			// update_post_meta() unslashes what it is given, as every
			// WordPress write function does. Unslashed, a backslash in a
			// title or a display rule was silently gone.
			update_post_meta( $post->ID, $key, wp_slash( $field['to'] ) );
		}
		$written[] = $key;
	}

	return $written;
}

/**
 * The allowlisted meta of a post, plus the fields WordPress itself keeps.
 *
 * Reading only. Writing goes through wpmcp_meta_diff() and
 * wpmcp_apply_meta(), with its own allowlist.
 *
 * @param \WP_Post $post Post.
 * @return array
 */
function wpmcp_read_meta( $post ) {
	$fields = array();

	foreach ( wpmcp_readable_meta_keys() as $key => $label ) {
		$value = get_post_meta( $post->ID, $key, true );
		if ( '' === $value || null === $value || array() === $value ) {
			continue;
		}
		$fields[ $key ] = array(
			'label' => $label,
			'value' => is_scalar( $value ) ? $value : wp_json_encode( $value ),
		);
	}

	$thumbnail = get_post_thumbnail_id( $post->ID );

	$result = array(
		'seo'           => $fields,
		'featuredImage' => $thumbnail ? (int) $thumbnail : null,
		'excerpt'       => $post->post_excerpt,
		'template'      => get_page_template_slug( $post->ID ),
	);

	$plugin = wpmcp_read_plugin_meta( $post );
	if ( ! empty( $plugin ) ) {
		$result['plugin'] = $plugin;
	}

	return $result;
}

/**
 * The revisions of a post, newest first.
 *
 * @param int $post_id Post ID.
 * @param int $limit   How many to return.
 * @return array|\WP_Error
 */
function wpmcp_list_revisions( $post_id, $limit = 15 ) {
	$post = wpmcp_get_readable_post( $post_id );
	if ( is_wp_error( $post ) ) {
		return $post;
	}

	$revisions = wp_get_post_revisions(
		$post->ID,
		array( 'numberposts' => max( 1, min( 50, (int) $limit ) ) )
	);

	$items = array();
	foreach ( $revisions as $revision ) {
		$author = get_userdata( (int) $revision->post_author );

		$items[] = array(
			'id'         => (int) $revision->ID,
			'date'       => $revision->post_modified_gmt,
			'author'     => $author ? $author->display_name : null,
			'autosave'   => wp_is_post_autosave( $revision ) ? true : false,
			'blockCount' => wpmcp_count_blocks( parse_blocks( $revision->post_content ) ),
		);
	}

	return array(
		'postId'    => $post->ID,
		'title'     => get_the_title( $post ),
		'current'   => array(
			'modified'   => $post->post_modified_gmt,
			'blockCount' => wpmcp_count_blocks( parse_blocks( $post->post_content ) ),
		),
		'revisions' => $items,
	);
}

/**
 * Was this revision saved by a person?
 *
 * Only then does its markup count as the post's own history for
 * content-restore. A revision records who saved it (WordPress stores the
 * current user as its author). One the agent saved is the agent's own
 * content, and one whose author cannot be found any more proves nothing
 * either way, so both are checked like a new write.
 *
 * @param \WP_Post|object $revision Revision.
 * @return bool
 */
function wpmcp_revision_counts_as_known( $revision ) {
	$author = (int) ( $revision->post_author ?? 0 );

	return $author > 0 && get_userdata( $author ) && ! wpmcp_is_ai_user( $author );
}

/**
 * Put a post back to the content of one of its revisions.
 *
 * The undo the agent lacked. Without it, a write that went wrong could
 * only be repaired by a human in the editor — and the one case where that
 * hurt most was a write that stripped markup the agent is not allowed to
 * write back, which left it unable to fix its own mistake.
 *
 * Restoring may therefore reintroduce markup that content-write refuses,
 * when a person saved it: it is not agent-authored content but a state
 * this very post was already in. A revision the agent saved itself is
 * different (see wpmcp_revision_counts_as_known()) and is checked like a
 * new write.
 *
 * Otherwise it is a write like any other: the same gate for patterns and
 * code-running elements, the same cache purge, the same stamp for the
 * next write.
 *
 * @param int  $post_id     Post ID.
 * @param int  $revision_id Revision to restore.
 * @param bool $dry_run     Report what would change without doing it.
 * @return array|\WP_Error
 */
function wpmcp_restore_revision( $post_id, $revision_id, $dry_run = true ) {
	$post = wpmcp_get_writable_post( $post_id );
	if ( is_wp_error( $post ) ) {
		return $post;
	}

	$revision = wp_get_post_revision( (int) $revision_id );
	if ( ! $revision ) {
		return new \WP_Error( 'wpmcp_not_found', sprintf( 'No revision with ID %d.', (int) $revision_id ) );
	}

	// A revision of a different post would be a way around every check.
	if ( (int) $revision->post_parent !== $post->ID ) {
		return new \WP_Error(
			'wpmcp_wrong_revision',
			sprintf( 'Revision %d belongs to post %d, not %d.', (int) $revision_id, (int) $revision->post_parent, $post->ID )
		);
	}

	$target = wpmcp_assert_writable_target( $post );
	if ( is_wp_error( $target ) ) {
		return $target;
	}

	$before = parse_blocks( $post->post_content );
	$after  = parse_blocks( $revision->post_content );

	$diff = array(
		'blocksBefore' => wpmcp_count_blocks( $before ),
		'blocksAfter'  => wpmcp_count_blocks( $after ),
	);
	$diff['delta'] = $diff['blocksAfter'] - $diff['blocksBefore'];

	$result = array(
		'ok'         => true,
		'dryRun'     => (bool) $dry_run,
		'postId'     => $post->ID,
		'revisionId' => (int) $revision_id,
		'revisionAt' => $revision->post_modified_gmt,
		'diff'       => $diff,
		'warnings'   => array(),
	);

	// The same guard as every other write. A revision a person saved counts
	// as already stored: it belongs to this post (checked above), and
	// WordPress fills a revision from what it stored, so its markup is the
	// post's own history, not something the agent supplies.
	//
	// A revision the agent saved does not count. Before 0.18.3 an agent
	// save could get markup past kses (an onerror, a javascript: link),
	// and each such save left a revision holding it. Counting those as
	// known would let restore bring back exactly what the fix keeps out.
	$known  = wpmcp_revision_counts_as_known( $revision );
	$impact = wpmcp_kses_impact( $post->post_content, $revision->post_content, $known ? array( $revision->post_content ) : array() );
	if ( $impact['introduces'] && ! wpmcp_filtered_markup_allowed( $post ) ) {
		$message = wpmcp_filtered_markup_error( $impact );
		if ( ! $known ) {
			$message = sprintf(
				'Revision %d was not saved by a person on this site (it was saved by the agent account, or its author no longer exists), so its markup is checked like a new write by the agent. %s',
				(int) $revision_id,
				$message
			);
		}
		return new \WP_Error( 'wpmcp_unsafe_markup', $message );
	}

	if ( $dry_run ) {
		$result['message'] = 'Dry run only — nothing was restored. Call again with dry_run: false to restore.';
		wpmcp_log(
			'wpmcp/content-restore',
			array(
				'post_id'     => $post->ID,
				'operation'   => 'restore',
				'dry_run'     => true,
				'summary'     => sprintf( 'Dry run: would restore revision %d (%+d blocks).', (int) $revision_id, $diff['delta'] ),
				'revision_id' => (int) $revision_id,
			)
		);
		return $result;
	}

	// Saved without the content filter when the revision holds markup kses
	// would strip: this is a state the post already held, and filtering it
	// again would repeat the very damage a restore is meant to undo.
	$postarr = array(
		'ID'           => $post->ID,
		'post_content' => wp_slash( $revision->post_content ),
	);

	list( $updated, $saved_revision ) = wpmcp_save_capturing_revision(
		$post->ID,
		function () use ( $impact, $post, $postarr ) {
			return wpmcp_should_preserve_markup( $impact, $post )
				? wpmcp_update_post_preserving( $postarr )
				: wp_update_post( $postarr, true );
		}
	);

	if ( is_wp_error( $updated ) ) {
		return $updated;
	}

	$stored = wpmcp_verify_stored( $post->ID, $revision->post_content );
	if ( ! empty( $stored ) ) {
		$result['warnings']       = $stored;
		$result['contentAltered'] = true;
	}

	$result['message'] = 'Restored.';
	$result['preview'] = wpmcp_preview_url( $post->ID );

	// The revision this restore itself left behind: the undo of the undo.
	$result['savedRevisionId'] = $saved_revision;

	wpmcp_after_save( $post, $result );

	wpmcp_log(
		'wpmcp/content-restore',
		array(
			'post_id'     => $post->ID,
			'operation'   => 'restore',
			'dry_run'     => false,
			'summary'     => sprintf( 'Restored revision %d (%+d blocks).', (int) $revision_id, $diff['delta'] ),
			'revision_id' => (int) $revision_id,
		)
	);

	return $result;
}

/**
 * Ability name without the namespace, as the MCP tool is called.
 *
 * @param string $name Ability name.
 * @return string
 */
function wpmcp_short_ability_name( $name ) {
	return str_replace( 'wpmcp/', '', (string) $name );
}

/**
 * A WP_Error as one line that still carries its code: "[code] message".
 *
 * The MCP adapter hands a WP_Error to the client as its message alone, so
 * the code, the one part an agent can branch on reliably, never arrived.
 * Prefixed exactly once: an error passed through two layers keeps one
 * prefix. Only the connector's own codes (wpmcp_*) are prefixed; a code
 * from WordPress or another plugin means nothing documented here.
 *
 * @param \WP_Error $error Error.
 * @return string
 */
function wpmcp_error_text( $error ) {
	$code    = (string) $error->get_error_code();
	$message = (string) $error->get_error_message();

	if ( 0 !== strpos( $code, 'wpmcp_' ) ) {
		return $message;
	}

	$prefix = '[' . $code . '] ';
	return 0 === strpos( $message, $prefix ) ? $message : $prefix . $message;
}

/**
 * Bring an ability's answer into the shape the contract promises.
 *
 * Called once, at the boundary every ability passes (see
 * wpmcp_register_ability), so no tool can forget it:
 *
 * - A WP_Error with a wpmcp_* code gets its message prefixed with the
 *   code, see wpmcp_error_text().
 * - An answer with "ok": false gets a top-level "code" if it has none,
 *   wpmcp_validation_failed: the request was understood and refused for
 *   the reasons in "errors". That way both kinds of failure carry a code,
 *   and an agent never has to tell them apart by reading prose.
 *
 * @param mixed $result Whatever the execute callback returned.
 * @return mixed
 */
function wpmcp_contract_result( $result ) {
	if ( is_wp_error( $result ) ) {
		$text = wpmcp_error_text( $result );
		if ( $text === $result->get_error_message() ) {
			return $result;
		}
		return new \WP_Error( $result->get_error_code(), $text, $result->get_error_data() );
	}

	if ( is_array( $result ) && array_key_exists( 'ok', $result ) && false === $result['ok'] && empty( $result['code'] ) ) {
		// Right after "ok", where a reader looks.
		$shaped = array();
		foreach ( $result as $key => $value ) {
			$shaped[ $key ] = $value;
			if ( 'ok' === $key ) {
				$shaped['code'] = 'wpmcp_validation_failed';
			}
		}
		return $shaped;
	}

	return $result;
}

/**
 * Version of the tool contract: names, arguments, answer shapes, error codes.
 *
 * An integer, separate from the plugin version, because a client cares
 * about one thing: whether what it learnt about these tools still holds.
 * Raised only when an existing name, field or meaning changes in a way a
 * client has to adapt to; a new tool, argument or field does not raise it.
 * The changelog marks every raise under "API".
 *
 * @return int
 */
function wpmcp_contract_version() {
	return 1;
}

/**
 * Site fingerprint: versions, modules, post types and design tokens.
 * Meant as the first call of any session, so nothing has to be assumed.
 *
 * @return array
 */
function wpmcp_site_info() {
	global $wp_version;

	$theme = wp_get_theme();

	$post_types = array();
	foreach ( wpmcp_allowed_post_types() as $name ) {
		$object = get_post_type_object( $name );
		if ( $object ) {
			$post_types[] = array(
				'name'  => $name,
				'label' => $object->labels->name ?? $name,
			);
		}
	}

	$info = array(
		'siteName'        => get_bloginfo( 'name' ),
		'siteUrl'         => home_url(),
		'wpVersion'       => $wp_version,
		'phpVersion'      => PHP_VERSION,
		'connectorVersion'=> WPMCP_VERSION,
		'contractVersion' => wpmcp_contract_version(),
		'theme'           => array(
			'name'    => $theme->get( 'Name' ),
			'version' => $theme->get( 'Version' ),
		),
		'coreVersion'     => defined( 'DBW_CORE_VERSION' ) ? DBW_CORE_VERSION : null,
		'requiredCore'    => defined( 'DBW_REQUIRED_CORE_VERSION' ) ? DBW_REQUIRED_CORE_VERSION : null,
		'clientName'      => defined( 'DBW_CLIENT_NAME' ) ? DBW_CLIENT_NAME : null,
		'postTypes'       => $post_types,
		'designTokens'    => wpmcp_design_tokens(),
		'blockCount'      => count( wpmcp_build_catalog( 'site' ) ),
	);

	/*
	 * What this connector can actually do, listed rather than implied.
	 *
	 * Reporting a switch like "liveEdit: false" on its own invites the
	 * reading that writing merely needs enabling, when at the read-only
	 * level the write tools are not registered at all. A flag without a
	 * tool behind it is worse than no flag: it cost a real session a
	 * reconnect cycle and a wrong conclusion.
	 */
	$available = wpmcp_ability_names();
	$writing   = wpmcp_write_ability_names();

	$read_tools  = array_values( array_diff( $available, $writing ) );
	$write_tools = array_values( array_intersect( $available, $writing ) );

	$levels  = wpmcp_access_levels();
	$level   = wpmcp_access_level();
	$session = wpmcp_work_session_active();

	// Built from the session state: "publishing is never possible" was
	// printed here while a session had made it possible, and an agent
	// believes the sentence it reads over the field next to it.
	$publishing = $session
		? 'Publishing (status publish) and media-upload work until the work session ends.'
		: 'Publishing and media-upload need a work session, which only the site owner can open; until then new pages stay drafts.';

	$info['capabilities'] = array(
		'accessLevel' => $level,
		'read'        => array_map( 'wpmcp_short_ability_name', $read_tools ),
		'write'       => array_map( 'wpmcp_short_ability_name', $write_tools ),
		'explains'    => empty( $write_tools )
			? sprintf(
				'This site is set to "%s". No write tools are registered — writing is not disabled, it is absent. Nothing you send can change content until the site owner raises the access level%s.',
				$levels[ wpmcp_configured_access_level() ]['label'],
				$session ? ' (a work session does not add tools on a read-only site)' : ''
			)
			: sprintf(
				'This site is set to "%s". %s %s',
				$levels[ $level ]['label'],
				wpmcp_live_edit_enabled()
					? 'Drafts and published pages may be edited.'
					: 'Drafts and new pages may be edited; published pages are read-only.',
				$publishing
			),
	);

	// Only meaningful once writing exists at all.
	if ( ! empty( $write_tools ) ) {
		$info['capabilities']['publishedPagesWritable'] = wpmcp_live_edit_enabled();

		// Whether pages with dynamic data can be saved, and if not, why —
		// so this is a fact to read rather than something to infer from a
		// failed write.
		$dynamic = array( 'allowed' => wpmcp_dynamic_data_allowed() );
		$blocker = wpmcp_unfiltered_html_blocker();

		if ( ! $dynamic['allowed'] ) {
			$dynamic['explains'] = 'Pages whose blocks carry dynamic data cannot be saved. Some block libraries gate them behind the unfiltered_html capability. The site owner can allow it under Tools > MCP Connector, where it is granted per save rather than to the role.';
		} elseif ( $blocker ) {
			$dynamic['effective'] = false;
			$dynamic['explains']  = $blocker;
		} else {
			$dynamic['effective'] = true;
			$dynamic['explains']  = 'Pages with dynamic data can be saved. The capability is granted for the duration of each save and removed again; a write that newly introduces a script tag, an inline event handler or a javascript: URL is still refused.';
		}

		$info['capabilities']['dynamicData'] = $dynamic;

		$info['capabilities']['workSession'] = $session
			? array(
				'active'   => true,
				'until'    => gmdate( 'c', wpmcp_work_session_expires() ),
				'explains' => 'A work session is open: status publish and media-upload work until it ends.',
			)
			: array(
				'active'   => false,
				'explains' => 'Publishing and media-upload only work while the site owner has a work session open. Outside one, status publish fails validation and media-upload is refused with wpmcp_session_required; the tool list stays the same either way.',
			);
	}

	$info['plugins'] = wpmcp_relevant_plugins();

	if ( function_exists( 'dbw_get_settings' ) ) {
		$settings = dbw_get_settings();
		$modules  = array();
		foreach ( $settings as $key => $value ) {
			if ( substr( (string) $key, -7 ) === '_module' ) {
				$modules[ $key ] = (bool) $value;
			}
		}
		$info['featureModules'] = $modules;
	}

	return $info;
}

/**
 * Create a page and, if content came with it, fill it in the same call.
 *
 * Duplicating was the only way to make a page, and a duplicate inherits
 * the parent it was copied from and gets whatever slug WordPress derives
 * from the title. Building twenty-two pages that way meant overwriting
 * twenty-two duplicates and then correcting twenty-two slugs and parents
 * in wp-admin — a whole afternoon of work the connector had created.
 *
 * A page with content is created whole or not at all. The tree and the
 * meta go through the same pipeline content-write uses (wpmcp_plan_write)
 * before anything is inserted, in the dry run and the real call alike.
 * Until 0.19 the real call inserted first and checked after, and every
 * refused tree left an empty draft behind, plus one more for each retry.
 * Should the save still fail once the page exists, the page is deleted
 * again and the answer says so.
 *
 * Created as a draft. "publish" is honoured only inside a work session,
 * and only as the last step, once the content is in.
 *
 * @param array $args { title, post_type, slug, parent, status, tree, meta, dry_run }.
 * @return array|\WP_Error
 */
function wpmcp_create_content( array $args ) {
	$title = trim( (string) ( $args['title'] ?? '' ) );
	if ( '' === $title ) {
		return new \WP_Error( 'wpmcp_bad_request', 'A new page needs a "title".' );
	}

	$post_type = (string) ( $args['post_type'] ?? 'page' );
	if ( ! in_array( $post_type, wpmcp_allowed_post_types(), true ) ) {
		return new \WP_Error(
			'wpmcp_forbidden_type',
			sprintf( 'Post type "%s" is not exposed to the connector.', $post_type )
		);
	}

	$type_object = get_post_type_object( $post_type );
	if ( ! $type_object || ! current_user_can( $type_object->cap->create_posts ) ) {
		return new \WP_Error( 'wpmcp_forbidden', sprintf( 'No permission to create %s content.', $post_type ) );
	}

	$status = (string) ( $args['status'] ?? 'draft' );
	if ( ! in_array( $status, wpmcp_writable_statuses(), true ) ) {
		return new \WP_Error(
			'wpmcp_bad_request',
			sprintf(
				'"%s" is not a status this connector sets. Allowed: %s. Publishing stays with a human.',
				$status,
				implode( ', ', wpmcp_writable_statuses() )
			)
		);
	}

	$parent = isset( $args['parent'] ) ? (int) $args['parent'] : 0;
	if ( $parent ) {
		$target = get_post( $parent );
		if ( ! $target || $target->post_type !== $post_type ) {
			return new \WP_Error(
				'wpmcp_bad_parent',
				sprintf( 'No "%s" with ID %d to use as a parent.', $post_type, $parent )
			);
		}
	}

	$slug = isset( $args['slug'] ) ? sanitize_title( (string) $args['slug'] ) : '';

	$dry_run = ! isset( $args['dry_run'] ) || (bool) $args['dry_run'];

	// The page that does not exist yet, for the checks that ask about one.
	$stand_in = (object) array(
		'ID'                => 0,
		'post_type'         => $post_type,
		'post_status'       => 'draft',
		'post_name'         => $slug,
		'post_parent'       => $parent,
		'post_content'      => '',
		'post_password'     => '',
		'post_modified_gmt' => '',
	);

	$target = wpmcp_assert_writable_target( $stand_in );
	if ( is_wp_error( $target ) ) {
		return $target;
	}

	// Content in the same call, so one page is one round trip.
	$content = array();
	foreach ( array( 'tree', 'meta' ) as $key ) {
		if ( ! empty( $args[ $key ] ) ) {
			$content[ $key ] = $args[ $key ];
		}
	}

	$report = array(
		'ok'       => true,
		'dryRun'   => $dry_run,
		'title'    => $title,
		'type'     => $post_type,
		'slug'     => '' !== $slug ? $slug : sanitize_title( $title ),
		'parent'   => $parent,
		'status'   => $status,
		'errors'   => array(),
		'warnings' => array(),
	);

	if ( ! empty( $content ) ) {
		$plan = wpmcp_plan_write( $stand_in, $content, $dry_run );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		$report['errors']   = $plan['errors'];
		$report['warnings'] = $plan['response']['warnings'] ?? array();
		if ( isset( $content['tree'] ) && isset( $plan['after_count'] ) ) {
			$report['blocks'] = $plan['after_count'];
		}
		$report['ok'] = empty( $report['errors'] );
	}

	if ( ! $report['ok'] ) {
		$report['message'] = $dry_run
			? 'The dry run found problems — nothing was created. Fix them and call again.'
			: 'The content was refused, so nothing was created. Fix it and call content-create again.';

		wpmcp_log(
			'wpmcp/content-create',
			array(
				'operation' => $dry_run ? 'create' : 'rejected',
				'dry_run'   => $dry_run,
				'summary'   => sprintf( 'Rejected "%s" (%s): %d error(s). Nothing created.', $title, $post_type, count( $report['errors'] ) ),
			)
		);
		return $report;
	}

	if ( $dry_run ) {
		$report['message'] = 'Dry run only — nothing was created. The tree and meta were checked exactly as the real call will write them. Call again with dry_run: false.';
		return $report;
	}

	$post_id = wp_insert_post(
		wp_slash(
			array(
				'post_title'  => $title,
				'post_type'   => $post_type,
				'post_name'   => $slug,
				'post_parent' => $parent,
				// Created as a draft even when publishing was asked for: a page
				// must not be live for the moment before its content arrives.
				'post_status' => 'publish' === $status ? 'draft' : $status,
				'post_author' => get_current_user_id(),
				'post_content' => '',
			)
		),
		true
	);

	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	$created = get_post( $post_id );

	$result = array(
		'ok'      => true,
		'dryRun'  => false,
		'id'      => (int) $post_id,
		'title'   => $created->post_title,
		'type'    => $created->post_type,
		'slug'    => $created->post_name,
		'parent'  => (int) $created->post_parent,
		'status'  => $created->post_status,
		'url'     => get_permalink( $created ),
		'message' => 'Created.',
	);

	if ( empty( $content ) ) {
		wpmcp_log(
			'wpmcp/content-create',
			array(
				'post_id'   => (int) $post_id,
				'operation' => 'create',
				'summary'   => sprintf( 'Created "%s" (%s, parent %d).', $title, $post_type, $parent ),
			)
		);

		$result['modified']  = $created->post_modified_gmt;
		$result['nextWrite'] = 'Pass this "modified" value as expected_modified when you write the content.';
		if ( 'publish' === $status ) {
			wpmcp_publish_created( (int) $post_id, $result );
		}
		return $result;
	}

	// The same pipeline once more, now on the real post, and the save.
	$written = wpmcp_write_content(
		array_merge(
			$content,
			array(
				'post_id' => (int) $post_id,
				'dry_run' => false,
			)
		)
	);

	if ( is_wp_error( $written ) || empty( $written['ok'] ) ) {
		// Checked beforehand and still refused: something only the save
		// itself could say (a plugin's save filter, the database). The page
		// is the connector's own from a moment ago and holds nothing, so it
		// goes again rather than staying behind as an empty draft.
		wp_delete_post( (int) $post_id, true );

		$why = is_wp_error( $written )
			? wpmcp_error_text( $written )
			: implode( ' ', $written['errors'] ?? array() );

		wpmcp_log(
			'wpmcp/content-create',
			array(
				'operation' => 'rejected',
				'summary'   => sprintf( 'Created "%s" (%s) as post %d and removed it again: the content was not saved.', $title, $post_type, (int) $post_id ),
			)
		);

		return array(
			'ok'       => false,
			'code'     => is_wp_error( $written ) ? $written->get_error_code() : 'wpmcp_save_failed',
			'dryRun'   => false,
			'title'    => $title,
			'type'     => $post_type,
			'errors'   => '' !== trim( $why ) ? array( $why ) : array( 'The content was not saved.' ),
			'warnings' => is_array( $written ) ? ( $written['warnings'] ?? array() ) : array(),
			'message'  => sprintf( 'The page was created, but its content could not be saved, so the page was removed again. Nothing was created. %s', $why ),
		);
	}

	wpmcp_log(
		'wpmcp/content-create',
		array(
			'post_id'   => (int) $post_id,
			'operation' => 'create',
			'summary'   => sprintf( 'Created "%s" (%s, parent %d) with its content.', $title, $post_type, $parent ),
		)
	);

	$result['content'] = $written;
	$result['message'] = 'Created and written.';

	// Only once the content is in.
	if ( 'publish' === $status ) {
		wpmcp_publish_created( (int) $post_id, $result );
	}

	return $result;
}

/**
 * Publish a page the connector just created, as the last step.
 *
 * @param int   $post_id Post ID.
 * @param array $result  Result of wpmcp_create_content(), updated in place.
 */
function wpmcp_publish_created( $post_id, array &$result ) {
	$published = wpmcp_write_content(
		array(
			'post_id' => $post_id,
			'status'  => 'publish',
			'dry_run' => false,
		)
	);

	if ( is_wp_error( $published ) || empty( $published['ok'] ) ) {
		$why = is_wp_error( $published )
			? $published->get_error_message()
			: ( ! empty( $published['errors'] ) ? implode( ' ', $published['errors'] ) : 'the status change was refused' );
		$result['message'] .= ' It was not published: ' . rtrim( $why, '. ' ) . '. It stays a draft.';
		return;
	}

	$result['status']  = 'publish';
	$result['url']     = $published['url'] ?? ( $result['url'] ?? '' );
	$result['message'] .= ' Published.';
}

/**
 * One entry of a content-batch answer, the same shape in both phases.
 *
 * The dry-run phase reported "errors" as a list and the save phase an
 * "error" string that stayed null when the save came back refused rather
 * than failed, and neither carried warnings. An agent had to know which
 * phase it was reading to find out why an item failed. Now every item is
 * { index, postId, ok, code (only when not ok), errors[], warnings[] },
 * and a saved item adds revisionId, modified and, for text patches,
 * patched.
 *
 * @param int   $index   Position in the request.
 * @param int   $post_id Post the item names, 0 if none.
 * @param mixed $result  What wpmcp_write_content() returned.
 * @param bool  $saving  Whether this was the real write.
 * @return array
 */
function wpmcp_batch_item( $index, $post_id, $result, $saving = false ) {
	$item = array(
		'index'  => (int) $index,
		'postId' => $post_id ? (int) $post_id : null,
	);

	if ( is_wp_error( $result ) ) {
		return $item + array(
			'ok'       => false,
			'code'     => $result->get_error_code(),
			'errors'   => array( wpmcp_error_text( $result ) ),
			'warnings' => array(),
		);
	}

	$ok    = ! empty( $result['ok'] );
	$item += array( 'ok' => $ok );
	if ( ! $ok ) {
		$item['code'] = ! empty( $result['code'] ) ? $result['code'] : 'wpmcp_validation_failed';
	}
	$item['errors']   = array_values( (array) ( $result['errors'] ?? array() ) );
	$item['warnings'] = array_values( (array) ( $result['warnings'] ?? array() ) );

	if ( $saving && $ok ) {
		$item['revisionId'] = $result['revisionId'] ?? 0;
		$item['modified']   = $result['modified'] ?? null;
		if ( ! empty( $result['patched'] ) ) {
			$item['patched'] = $result['patched'];
		}
	}

	return $item;
}

/**
 * Apply one change to several posts in a single call.
 *
 * Every item is dry-run first, and nothing is saved unless all of them
 * pass. WordPress has no transaction across posts, so this is as close to
 * all-or-nothing as it gets: a failure between the check and the save —
 * someone editing a page in that second — stops the run there and says
 * which posts were saved and which were not. Each post still gets its own
 * revision, which is where its own undo lives.
 *
 * @param array $args { items: [{ post_id, ops|tree|meta|..., expected_modified }], dry_run }.
 * @return array|\WP_Error
 */
function wpmcp_batch_write( array $args ) {
	$items = wpmcp_decode_structure( $args['items'] ?? array(), 'items' );
	if ( is_wp_error( $items ) ) {
		return $items;
	}

	if ( empty( $items ) ) {
		return new \WP_Error( 'wpmcp_bad_request', 'Provide "items": one entry per post, each with a post_id and the change for it.' );
	}

	if ( count( $items ) > 20 ) {
		return new \WP_Error( 'wpmcp_bad_request', sprintf( '%d items; the limit is 20 per call. Split the run.', count( $items ) ) );
	}

	$ids = array();
	foreach ( $items as $item ) {
		$id = is_array( $item ) ? (int) ( $item['post_id'] ?? 0 ) : 0;
		if ( $id && in_array( $id, $ids, true ) ) {
			return new \WP_Error(
				'wpmcp_bad_request',
				sprintf( 'Post %d appears twice. Put all its operations into one item: the second save would find the page already changed by the first.', $id )
			);
		}
		$ids[] = $id;
	}

	$dry_run = ! isset( $args['dry_run'] ) || (bool) $args['dry_run'];
	$checks  = array();
	$all_ok  = true;

	foreach ( $items as $i => $item ) {
		if ( ! is_array( $item ) || empty( $item['post_id'] ) ) {
			$checks[] = wpmcp_batch_item( $i, 0, new \WP_Error( 'wpmcp_bad_request', 'Each item needs a post_id.' ) );
			$all_ok   = false;
			continue;
		}

		$item['dry_run'] = true;
		$check           = wpmcp_batch_item( $i, (int) $item['post_id'], wpmcp_write_content( $item ) );
		$checks[]        = $check;

		if ( ! $check['ok'] ) {
			$all_ok = false;
		}
	}

	if ( $dry_run || ! $all_ok ) {
		return array(
			'ok'      => $all_ok,
			'dryRun'  => true,
			'items'   => $checks,
			'message' => $all_ok
				? 'Every item passed its dry run — nothing was saved. Call again with dry_run: false.'
				: 'At least one item failed its dry run, so nothing was saved. The items say which and why.',
		);
	}

	$results = array();
	$saved   = 0;

	foreach ( $items as $i => $item ) {
		$item['dry_run'] = false;
		$entry           = wpmcp_batch_item( $i, (int) $item['post_id'], wpmcp_write_content( $item ), true );
		$results[]       = $entry;

		if ( ! $entry['ok'] ) {
			break;
		}
		++$saved;
	}

	$complete = count( $items ) === $saved;

	// Each post has its own entry from its write; this one ties them
	// together, so it has to say which they were.
	$saved_ids = array();
	foreach ( $results as $entry ) {
		if ( $entry['ok'] ) {
			$saved_ids[] = $entry['postId'];
		}
	}

	wpmcp_log(
		'wpmcp/content-batch',
		array(
			'operation' => 'batch',
			'summary'   => sprintf(
				'Batch: %d of %d posts saved%s.%s',
				$saved,
				count( $items ),
				empty( $saved_ids ) ? '' : ' (' . implode( ', ', $saved_ids ) . ')',
				$complete ? '' : sprintf( ' Stopped at post %d.', (int) end( $results )['postId'] )
			),
		)
	);

	$answer = array(
		'ok'      => $complete,
		'code'    => 'wpmcp_batch_incomplete',
		'dryRun'  => false,
		'saved'   => $saved,
		'items'   => $results,
		'message' => $complete
			? 'All posts saved. Each has its own revision.'
			: sprintf( 'Stopped after %d of %d: the next post failed on save although its dry run passed, most likely because it changed in between. The posts before it are saved; the ones after it were not attempted.', $saved, count( $items ) ),
	);
	if ( $complete ) {
		unset( $answer['code'] );
	}

	return $answer;
}
