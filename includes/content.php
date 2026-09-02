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
	$types = array();
	foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $type ) {
		if ( ! $type->public && 'page' !== $type->name ) {
			continue;
		}
		if ( in_array( $type->name, array( 'attachment' ), true ) ) {
			continue;
		}
		$types[] = $type->name;
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
function wpmcp_read_content( $post_id, $mode = 'outline', $path = '', $include_defaults = false, array $paths = array() ) {
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
		'blockCount' => wpmcp_count_blocks( $blocks ),
		'mode'       => $mode,
		// Hand this back in content-write to be told if someone edited the
		// page in the meantime, instead of silently overwriting them.
		'modified'   => $post->post_modified_gmt,
	);

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

	if ( $has_tree === $has_ops ) {
		return new \WP_Error(
			'wpmcp_bad_request',
			'Provide either "tree" (replace the whole page) or "ops" (patch operations), not both and not neither.'
		);
	}

	$op_summary = array();

	if ( $has_tree ) {
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

	$response = array(
		'ok'       => empty( $validation['errors'] ),
		'dryRun'   => $dry_run,
		'postId'   => $post->ID,
		'diff'     => $diff,
		'errors'   => $validation['errors'],
		'warnings' => $warnings,
	);

	if ( ! empty( $validation['errors'] ) ) {
		wpmcp_log(
			'wpmcp/content-write',
			array(
				'post_id'   => $post->ID,
				'operation' => $has_tree ? 'tree' : 'ops',
				'dry_run'   => true,
				'summary'   => sprintf( 'Rejected: %d validation error(s).', count( $validation['errors'] ) ),
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

	// Real write. wp_slash() is essential: without it WordPress strips
	// backslashes out of the block attribute JSON.
	$updated = wp_update_post(
		array(
			'ID'           => $post->ID,
			'post_content' => wp_slash( $validation['serialized'] ),
		),
		true
	);

	if ( is_wp_error( $updated ) ) {
		return $updated;
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

	$response['message']    = 'Saved.';
	$response['revisionId'] = $revision_id;
	$response['preview']    = wpmcp_preview_url( $post->ID );

	wpmcp_log(
		'wpmcp/content-write',
		array(
			'post_id'     => $post->ID,
			'operation'   => $has_tree ? 'tree' : 'ops',
			'dry_run'     => false,
			'summary'     => sprintf( 'Saved (%+d blocks, %d total).', $diff['delta'], $after_count ),
			'revision_id' => $revision_id,
		)
	);

	return $response;
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

	$new_id = wp_insert_post(
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
		),
		true
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
function wpmcp_preview_content( $post_id, $include_html = true ) {
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
		$rendered = wpmcp_render_post_html( $post );
		if ( is_wp_error( $rendered ) ) {
			$result['renderError'] = $rendered->get_error_message();
		} else {
			$result['html']     = $rendered['html'];
			$result['headings'] = $rendered['headings'];
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
 * @param \WP_Post $post Post.
 * @return array|\WP_Error
 */
function wpmcp_render_post_html( $post ) {
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

	$max = 60000;
	if ( strlen( $html ) > $max ) {
		$html = substr( $html, 0, $max ) . "\n<!-- truncated by wp-mcp-connector-plus -->";
	}

	return array(
		'html'     => $html,
		'headings' => $headings,
		'notices'  => $smoke['notices'] ?? array(),
	);
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
		'liveEdit'        => wpmcp_live_edit_enabled(),
		'blockCount'      => count( wpmcp_build_catalog( 'site' ) ),
	);

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
