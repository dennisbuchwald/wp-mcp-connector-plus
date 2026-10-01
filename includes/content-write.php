<?php
/**
 * Writing content: plan, check, save.
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
		// wp_get_post_revision() takes its argument by reference, so it
		// must be a variable: an expression is a fatal Error in PHP 8.
		$revision_id = (int) $revision_id;
		$revision    = wp_get_post_revision( $revision_id );
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
	/**
	 * Fires after the agent saved a post for real (write, batch item,
	 * restore). Dry runs and refusals never reach it. The plugin itself
	 * uses it to remember when, for the notice in the block editor.
	 *
	 * @param int $post_id Post that was saved.
	 */
	do_action( 'wpmcp_saved', (int) $post->ID );

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
		$response['cache'] = array_merge( $response['cache'], wpmcp_purge_embedding_pages( wpmcp_pattern_usage_ids( $post->ID ) ) );
	}
	$response['verify'] = 'content-read shows what is stored. Use content-fetch-live to see what a visitor gets.';

	return $fresh;
}

/**
 * Post types whose content renders inside other posts.
 *
 * A synced pattern appears wherever a core/block references it, a
 * navigation menu wherever core/navigation does, template parts and
 * templates around every page. Changing one changes how other posts
 * render.
 *
 * @return string[]
 */
function wpmcp_embedded_post_types() {
	return array( 'wp_block', 'wp_navigation', 'wp_template_part', 'wp_template' );
}

/**
 * Pages purged inside the request after a pattern changed.
 *
 * Each purge is a call into the page cache plugin, and some of those
 * delete files or send an HTTP request to a CDN. A pattern on 2000 pages
 * (the most wpmcp_pattern_usage_ids returns) meant 2000 of them before
 * the agent got its answer, long enough for a proxy to cut the request
 * off after the save had gone through. Not measured on a real site; the
 * number is a judgement: enough for every ordinary pattern to be done at
 * once, small enough to stay well inside a request.
 */
const WPMCP_PURGE_NOW = 200;

/**
 * Clear the caches of the pages that embed a pattern.
 *
 * The first WPMCP_PURGE_NOW at once, the rest through WP-Cron in chunks
 * of the same size, one event a minute apart. Scheduled pages are
 * reported as a count: their caches are still stale for those minutes,
 * which is what an agent checking a live page needs to know.
 *
 * @param int[] $ids Embedding post IDs.
 * @return array { alsoPurged?: int[], alsoScheduled?: int }
 */
function wpmcp_purge_embedding_pages( array $ids ) {
	$ids = array_values( array_map( 'intval', $ids ) );
	if ( empty( $ids ) ) {
		return array();
	}

	$now = array_slice( $ids, 0, WPMCP_PURGE_NOW );
	foreach ( $now as $id ) {
		wpmcp_purge_caches( $id );
	}
	$report = array( 'alsoPurged' => $now );

	$later = array_slice( $ids, WPMCP_PURGE_NOW );
	if ( ! empty( $later ) ) {
		foreach ( array_chunk( $later, WPMCP_PURGE_NOW ) as $i => $chunk ) {
			wp_schedule_single_event( time() + 60 * ( $i + 1 ), 'wpmcp_purge_posts', array( $chunk ) );
		}
		$report['alsoScheduled'] = count( $later );
	}

	return $report;
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
 * The two optional parameters are for content-batch only, never for
 * anything an agent sends: a plan is the result of the whole validation,
 * and taking one from outside would skip it.
 *
 * @param array      $args     { post_id, tree?, ops?, dry_run }.
 * @param array|null $checked  A plan from this request's dry run of the
 *                             same item, with the post's modified time it
 *                             was made against (see wpmcp_batch_write).
 * @param array|null $plan_out Receives that pair, for a later save.
 * @return array|\WP_Error
 */
function wpmcp_write_content( array $args, $checked = null, &$plan_out = null ) {
	$timer   = wpmcp_debug_timer();
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

	// A person with the page open in the editor. A real write is refused;
	// a dry run says so and goes on, since checking costs them nothing.
	$locked = wpmcp_post_lock_error( $post );
	if ( $locked && ! $dry_run ) {
		return $locked;
	}

	// The plan depends on the stored post and the arguments. When the post
	// is exactly as it was for the dry run, a plan made then is this plan.
	$modified = (string) ( $post->post_modified_gmt ?? '' );
	if ( '' !== $modified && is_array( $checked ) && isset( $checked['plan'], $checked['modified'] ) && $checked['modified'] === $modified ) {
		$plan                       = $checked['plan'];
		$plan['response']['dryRun'] = $dry_run;
	} else {
		$plan = wpmcp_plan_write( $post, $args, $dry_run );
	}
	if ( is_wp_error( $plan ) ) {
		return $plan;
	}
	wpmcp_debug_mark( $timer, 'plan' );

	$plan_out = array(
		'plan'     => $plan,
		'modified' => $modified,
	);

	if ( $locked ) {
		$plan['response']['warnings'][] = $locked->get_error_message() . ' The real write will be refused until then.';
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
		return wpmcp_debug_attach( $response, $timer );
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
		return wpmcp_debug_attach( $response, $timer );
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

	wpmcp_debug_mark( $timer, 'save' );

	$response['message']    = 'Saved.';
	$response['revisionId'] = $revision_id;
	$response['preview']    = wpmcp_preview_url( $post->ID );

	$fresh = wpmcp_after_save( $post, $response );
	wpmcp_debug_mark( $timer, 'afterSave' );

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

	return wpmcp_debug_attach( $response, $timer );
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
