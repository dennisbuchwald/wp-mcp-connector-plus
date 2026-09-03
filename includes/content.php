<?php
/**
 * Reading, writing, duplicating and previewing content.
 *
 * Guard rails that live here rather than in policy documents:
 * - writes never touch slug, status or post type (URLs stay put)
 * - every real write goes through the validation pipeline first
 * - every real write leaves a revision, so rollback is one click
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

	return apply_filters( 'wpmcp_allowed_post_types', $types );
}

/**
 * How many published posts embed a given synced pattern.
 *
 * Editing a pattern changes every one of them at once, so the number
 * belongs in the dry run before anyone decides to save.
 *
 * @param int $pattern_id Pattern post ID.
 * @return int
 */
function wpmcp_pattern_usage_count( $pattern_id ) {
	global $wpdb;

	$needle = '"ref":' . (int) $pattern_id;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- content search, no core API for this.
	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			 WHERE post_status NOT IN ('trash', 'auto-draft')
			   AND post_type != 'revision'
			   AND post_content LIKE %s",
			'%' . $wpdb->esc_like( $needle ) . '%'
		)
	);
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
		return new \WP_Error( 'wpmcp_forbidden_type', sprintf( 'Post type "%s" is not exposed to the connector.', $post->post_type ) );
	}
	if ( ! current_user_can( 'edit_post', $post->ID ) && 'publish' !== $post->post_status ) {
		return new \WP_Error( 'wpmcp_forbidden', sprintf( 'No permission to read post %d.', $post->ID ) );
	}
	return $post;
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

	if ( ! current_user_can( 'edit_post', $post->ID ) ) {
		if ( 'publish' === $post->post_status && ! wpmcp_live_edit_enabled() ) {
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
 * List content with light metadata.
 *
 * @param array $args { post_type, status, search, uses_block, per_page, page }.
 * @return array
 */
function wpmcp_list_content( array $args ) {
	$per_page = min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );

	$query_args = array(
		'post_type'      => $args['post_type'] ?? wpmcp_allowed_post_types(),
		'post_status'    => $args['status'] ?? array( 'publish', 'draft', 'pending', 'future', 'private' ),
		'posts_per_page' => $per_page,
		'paged'          => max( 1, (int) ( $args['page'] ?? 1 ) ),
		'orderby'        => 'modified',
		'order'          => 'DESC',
	);

	if ( ! empty( $args['search'] ) ) {
		$query_args['s'] = (string) $args['search'];
	}

	$query = new \WP_Query( $query_args );
	$items = array();

	foreach ( $query->posts as $post ) {
		if ( ! empty( $args['uses_block'] ) && ! has_block( (string) $args['uses_block'], $post ) ) {
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
		'page'  => max( 1, (int) ( $args['page'] ?? 1 ) ),
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
			array_pop( $prefix );
			$trees = array_merge( $trees, wpmcp_blocks_to_tree( array( $node ), $prefix, $include_defaults ) );
		}

		$result['tree'] = $trees;
		return $result;
	}

	$result['tree'] = wpmcp_blocks_to_tree( $blocks, array(), $include_defaults );

	return $result;
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

	// Synced patterns need their own permission, and their own warning.
	if ( 'wp_block' === $post->post_type && 'write' !== wpmcp_pattern_access() ) {
		return new \WP_Error(
			'wpmcp_pattern_readonly',
			sprintf(
				'Post %d is a synced pattern, and pattern editing is switched off for this site. A pattern change would apply to every page embedding it.',
				$post->ID
			)
		);
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

	$before_blocks = parse_blocks( $post->post_content );
	$before_count  = wpmcp_count_blocks( $before_blocks );

	$has_tree = isset( $args['tree'] ) && is_array( $args['tree'] );
	$has_ops  = isset( $args['ops'] ) && is_array( $args['ops'] ) && ! empty( $args['ops'] );
	$has_meta = isset( $args['meta'] ) && is_array( $args['meta'] ) && ! empty( $args['meta'] );

	if ( $has_tree && $has_ops ) {
		return new \WP_Error(
			'wpmcp_bad_request',
			'Provide either "tree" (replace the whole page) or "ops" (patch operations), not both.'
		);
	}

	// Meta stands on its own: correcting a canonical URL is not a reason to
	// touch the block tree.
	if ( ! $has_tree && ! $has_ops && ! $has_meta ) {
		return new \WP_Error(
			'wpmcp_bad_request',
			'Provide "ops" (patch operations), "tree" (replace the whole page) or "meta" (SEO fields).'
		);
	}

	$meta_diff = $has_meta
		? wpmcp_meta_diff( $post, $args['meta'] )
		: array( 'fields' => array(), 'errors' => array(), 'changes' => 0 );

	$op_summary = array();

	if ( ! $has_tree && ! $has_ops ) {
		$blocks = $before_blocks;
	} elseif ( $has_tree ) {
		$errors = array();
		$blocks = wpmcp_tree_to_blocks( $args['tree'], '', $errors );
		if ( ! empty( $errors ) ) {
			return array(
				'ok'       => false,
				'dryRun'   => $dry_run,
				'errors'   => $errors,
				'warnings' => array(),
			);
		}
	} else {
		$applied = wpmcp_apply_ops( $before_blocks, $args['ops'] );
		if ( is_wp_error( $applied ) ) {
			return $applied;
		}
		$blocks     = $applied['blocks'];
		$op_summary = $applied['summary'];
	}

	$validation = wpmcp_validate_blocks( $blocks, $before_blocks );
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

	if ( 'wp_block' === $post->post_type ) {
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
		if ( $impact['introduces'] && ! wpmcp_filtered_markup_allowed( $post ) ) {
			$errors[] = sprintf(
				'This change adds markup WordPress will not store from an agent account (%s). Structured data, embeds and inline scripts cannot be written this way. Remove it, or have a human add it in the editor. To repair content of this kind through the connector, a developer can open the door deliberately with the wpmcp_allow_filtered_markup filter.',
				implode( ', ', $impact['added'] )
			);
		} elseif ( $impact['introduces'] ) {
			$warnings[] = sprintf(
				'This change adds markup WordPress would normally refuse from an agent account (%s). It is being written because this site opened the wpmcp_allow_filtered_markup filter. Close it again when the repair is done.',
				implode( ', ', $impact['added'] )
			);
		} else {
			$warnings[] = sprintf(
				'The page already contains markup WordPress would normally strip from an agent account (%s). It is preserved: the save keeps what was there rather than destroying it, and nothing new of that kind is added.',
				implode( ', ', $impact['affected'] )
			);
		}
	}

	$errors = array_merge( $errors, $meta_diff['errors'] );

	$response = array(
		'ok'       => empty( $errors ),
		'dryRun'   => $dry_run,
		'postId'   => $post->ID,
		'diff'     => $diff,
		'errors'   => $errors,
		'warnings' => $warnings,
	);

	if ( $has_meta ) {
		$response['meta'] = array(
			'fields'  => $meta_diff['fields'],
			'changes' => $meta_diff['changes'],
			'note'    => 'WordPress revisions cover post content, not post meta. The previous values are listed above and in the activity log; there is no one-click rollback for these.',
		);
	}

	if ( ! empty( $errors ) ) {
		wpmcp_log(
			'wpmcp/content-write',
			array(
				'post_id'   => $post->ID,
				'operation' => $has_tree ? 'tree' : 'ops',
				'dry_run'   => true,
				'summary'   => sprintf( 'Rejected: %d validation error(s).', count( $errors ) ),
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
				'operation' => $has_tree ? 'tree' : 'ops',
				'dry_run'   => true,
				'summary'   => sprintf( 'Dry run OK (%+d blocks).', $diff['delta'] ),
			)
		);
		return $response;
	}

	$revision_id = 0;

	// Only touch post content when the change actually has content in it.
	// A meta-only write must not bump the modified date or spend a
	// revision on an identical page.
	if ( $has_tree || $has_ops ) {
		// wp_slash() is essential: without it WordPress strips backslashes
		// out of the block attribute JSON.
		$postarr = array(
			'ID'           => $post->ID,
			'post_content' => wp_slash( $validation['serialized'] ),
		);

		// Dynamic data is gated by unfiltered_html in some block libraries.
		// Where the site has allowed it, the capability is granted for this
		// one call — and the guard below stands in for the filtering that
		// WordPress then stops doing.
		$elevate = wpmcp_dynamic_data_allowed();

		if ( $elevate ) {
			$unsafe = wpmcp_unsafe_additions( $post->post_content, $validation['serialized'] );
			if ( ! empty( $unsafe ) && ! wpmcp_filtered_markup_allowed( $post ) ) {
				return new \WP_Error(
					'wpmcp_unsafe_markup',
					wpmcp_unsafe_message( $unsafe, $blocks )
				);
			}
		}

		if ( $elevate ) {
			$updated = wpmcp_update_post_elevated( $postarr );
		} elseif ( wpmcp_should_preserve_markup( $impact, $post ) ) {
			$updated = wpmcp_update_post_preserving( $postarr );
		} else {
			$updated = wp_update_post( $postarr, true );
		}

		if ( is_wp_error( $updated ) ) {
			return wpmcp_explain_save_refusal( $updated, $impact );
		}

		if ( $elevate ) {
			$response['elevated'] = 'unfiltered_html was granted for this save only, because "Dynamic data" is set to allowed. It is not on the agent role.';
		}

		// What we sent is not necessarily what got stored.
		$stored_warnings = wpmcp_verify_stored( $post->ID, $validation['serialized'] );
		if ( ! empty( $stored_warnings ) ) {
			$response['warnings'] = array_merge( $response['warnings'], $stored_warnings );
			$response['contentAltered'] = true;
		}

		$revisions   = wp_get_post_revisions( $post->ID, array( 'numberposts' => 1 ) );
		$revision    = ! empty( $revisions ) ? reset( $revisions ) : null;
		$revision_id = $revision ? (int) $revision->ID : 0;
	}

	if ( $has_meta && $meta_diff['changes'] > 0 ) {
		$written = wpmcp_apply_meta( $post, $meta_diff['fields'] );
		$response['meta']['written'] = $written;

		wpmcp_log(
			'wpmcp/content-write',
			array(
				'post_id'   => $post->ID,
				'operation' => 'meta',
				'dry_run'   => false,
				'summary'   => wpmcp_meta_log_line( $meta_diff['fields'] ),
			)
		);
	}

	$response['message']    = 'Saved.';
	$response['revisionId'] = $revision_id;
	$response['preview']    = wpmcp_preview_url( $post->ID );

	// The database is now right; the delivered page may not be. Say which.
	$response['cache'] = wpmcp_purge_caches( $post->ID );
	$response['verify'] = 'content-read shows what is stored. Use content-fetch-live to see what a visitor gets.';

	wpmcp_log(
		'wpmcp/content-write',
		array(
			'post_id'     => $post->ID,
			'operation'   => $has_tree ? 'tree' : 'ops',
			'dry_run'     => false,
			'summary'     => sprintf(
				'Saved (%+d blocks, %d total).%s',
				$diff['delta'],
				$after_count,
				empty( $response['elevated'] ) ? '' : ' unfiltered_html granted for this save only.'
			),
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
 * @param string $before Content currently stored.
 * @param string $after  Content about to be written.
 * @return array {
 *     @type bool     $alters     Whether the save would change the content.
 *     @type bool     $introduces Whether the change adds filtered markup.
 *     @type string[] $affected   Which constructs are involved.
 * }
 */
function wpmcp_kses_impact( $before, $after ) {
	$filtered = function_exists( 'wp_kses_post' ) ? wp_kses_post( $after ) : $after;

	$in_before = wpmcp_filtered_fragments( (string) $before );
	$in_after  = wpmcp_filtered_fragments( (string) $after );

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

	return array(
		'alters'     => ( $filtered !== $after ),
		'introduces' => ! empty( $added ),
		'affected'   => array_values( array_unique( $affected ) ),
		'added'      => array_values( array_unique( $added ) ),
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
 * Callers must run wpmcp_unsafe_additions() first. With this capability
 * WordPress stops filtering the content, so that check is not a warning,
 * it is the replacement for what wp_kses would otherwise have done.
 *
 * @param array $postarr Arguments for wp_update_post.
 * @return int|\WP_Error
 */
function wpmcp_update_post_elevated( array $postarr ) {
	$user_id = get_current_user_id();

	$grant = function ( $allcaps, $caps, $args, $user ) use ( $user_id ) {
		if ( isset( $user->ID ) && (int) $user->ID === (int) $user_id ) {
			$allcaps['unfiltered_html'] = true;
		}
		return $allcaps;
	};

	add_filter( 'user_has_cap', $grant, 100, 4 );

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
		remove_filter( 'user_has_cap', $grant, 100 );
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
		$in_after  = wpmcp_match_counts( $pattern, (string) $after );
		$in_before = wpmcp_match_counts( $pattern, (string) $before );

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
 * Should this save bypass WordPress's content filter?
 *
 * Two reasons, and only these two. Editing one block must not destroy
 * markup in another that the agent never touched — safe, because nothing
 * of that kind is being added. And a repair explicitly opened by the site
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
	$rest  = $content;

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
 * Insert a post without the content filter.
 *
 * A duplicate is a byte-for-byte copy of a post that already exists on
 * this site. Filtering it would strip markup the original is allowed to
 * hold — the copy would silently differ from what was copied, which is
 * the one thing a duplicate must never do. The agent supplies no content
 * here, only an id, so there is nothing it could smuggle in.
 *
 * @param array $postarr Arguments for wp_insert_post.
 * @return int|\WP_Error
 */
function wpmcp_insert_post_preserving( array $postarr ) {
	$filters = array( 'content_save_pre', 'content_filtered_save_pre' );

	foreach ( $filters as $filter ) {
		remove_filter( $filter, 'wp_filter_post_kses' );
	}

	$result = wp_insert_post( $postarr, true );

	foreach ( $filters as $filter ) {
		add_filter( $filter, 'wp_filter_post_kses' );
	}

	return $result;
}

/**
 * Save without the kses filter, for the one case where it does harm.
 *
 * Only ever used when the content introduces no filtered markup beyond
 * what the page already held: the agent cannot smuggle a script in this
 * way, it can only fail to destroy one that was there already.
 *
 * @param array $postarr Arguments for wp_update_post.
 * @return int|\WP_Error
 */
function wpmcp_update_post_preserving( array $postarr ) {
	$filters = array( 'content_save_pre', 'content_filtered_save_pre' );

	foreach ( $filters as $filter ) {
		remove_filter( $filter, 'wp_filter_post_kses' );
	}

	$result = wp_update_post( $postarr, true );

	foreach ( $filters as $filter ) {
		add_filter( $filter, 'wp_filter_post_kses' );
	}

	return $result;
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

	$type_object = get_post_type_object( $post->post_type );
	if ( ! $type_object || ! current_user_can( $type_object->cap->create_posts ) ) {
		return new \WP_Error( 'wpmcp_forbidden', sprintf( 'No permission to create %s content.', $post->post_type ) );
	}

	$new_title = '' !== trim( (string) $title ) ? trim( (string) $title ) : $post->post_title . ' (Kopie)';

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
 * @param int    $max    Bytes to return at most.
 * @param int    $offset Byte to start at.
 * @return array { html: string, bytes: int, offset: int, truncated: bool, nextOffset?: int }
 */
function wpmcp_slice_text( $text, $max, $offset = 0 ) {
	$total  = strlen( $text );
	$offset = max( 0, min( (int) $offset, $total ) );
	$slice  = substr( $text, $offset, $max );

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
 * The allowlisted meta of a post, plus the fields WordPress itself keeps.
 *
 * Read-only. Writing these is a separate decision: a slug change moves a
 * URL, and this plugin promises never to do that.
 *
 * @param \WP_Post $post Post.
 * @return array
 */
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
		if ( ! isset( $allowed[ $key ] ) ) {
			$errors[] = sprintf(
				'"%s" is not a meta field this connector writes. Allowed: %s.',
				(string) $key,
				implode( ', ', array_keys( $allowed ) )
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
			wpmcp_shorten( $field['from'], 80 ),
			wpmcp_shorten( $field['to'], 80 )
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
			update_post_meta( $post->ID, $key, $field['to'] );
		}
		$written[] = $key;
	}

	return $written;
}

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

	return array(
		'seo'           => $fields,
		'featuredImage' => $thumbnail ? (int) $thumbnail : null,
		'excerpt'       => $post->post_excerpt,
		'template'      => get_page_template_slug( $post->ID ),
	);
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
 * Put a post back to the content of one of its revisions.
 *
 * The undo the agent lacked. Without it, a write that went wrong could
 * only be repaired by a human in the editor — and the one case where that
 * hurt most was a write that stripped markup the agent is not allowed to
 * write back, which left it unable to fix its own mistake.
 *
 * Restoring may therefore reintroduce markup that content-write refuses:
 * it is not agent-authored content but a state this very post was already
 * in, saved by whoever saved it. The agent cannot craft it, only return
 * to it.
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

	// Saved without the content filter on purpose: this is a state the post
	// already held, and filtering it again would repeat the very damage a
	// restore is meant to undo.
	$updated = wpmcp_update_post_preserving(
		array(
			'ID'           => $post->ID,
			'post_content' => wp_slash( $revision->post_content ),
		)
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
	$writing   = array( 'wpmcp/content-write', 'wpmcp/content-duplicate', 'wpmcp/content-restore' );

	$read_tools  = array_values( array_diff( $available, $writing ) );
	$write_tools = array_values( array_intersect( $available, $writing ) );

	$levels = wpmcp_access_levels();
	$level  = wpmcp_access_level();

	$info['capabilities'] = array(
		'accessLevel' => $level,
		'read'        => array_map( 'wpmcp_short_ability_name', $read_tools ),
		'write'       => array_map( 'wpmcp_short_ability_name', $write_tools ),
		'explains'    => empty( $write_tools )
			? sprintf(
				'This site is set to "%s". No write tools are registered — writing is not disabled, it is absent. Nothing you send can change content until the site owner raises the access level.',
				$levels[ $level ]['label']
			)
			: sprintf(
				'This site is set to "%s". %s',
				$levels[ $level ]['label'],
				wpmcp_live_edit_enabled()
					? 'Drafts and published pages may be edited. Publishing is never possible.'
					: 'Drafts and new pages may be edited; published pages are read-only. Publishing is never possible.'
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
	}

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
