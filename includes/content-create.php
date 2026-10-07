<?php
/**
 * New pages: create, duplicate, and several writes in one batch.
 *
 * All of them end in the same write pipeline as content-write, so a new
 * page passes the same checks as an edited one.
 *
 * @package wp-mcp-connector-plus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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

	// An Elementor page is its meta. Elementor runs kses over
	// _elementor_data written by an account without unfiltered_html (its
	// REST meta sanitizer), so the copy lost every script the original
	// had, the way duplicating once lost a page's JSON-LD. The copy is the
	// original's own data, nothing the agent wrote, so it is stored with
	// the capability for that copy only. Elementor's caches of the
	// original (CSS file, assets, element cache, screenshot) are not
	// copied: they describe the original and are built again for the copy.
	$elementor = wpmcp_elementor_built( $post );
	if ( $elementor ) {
		$skip = array_merge( $skip, array( '_elementor_css', '_elementor_page_assets', '_elementor_element_cache', '_elementor_screenshot', '_elementor_controls_usage' ) );
	}

	foreach ( get_post_meta( $post->ID ) as $key => $values ) {
		if ( in_array( $key, $skip, true ) ) {
			continue;
		}
		$release = ( $elementor && 0 === strpos( $key, '_elementor' ) && ! wpmcp_unfiltered_html_blocker() )
			? wpmcp_grant_caps_for_request( array( 'unfiltered_html' ) )
			: null;
		try {
			foreach ( $values as $value ) {
				add_post_meta( $new_id, $key, wp_slash( maybe_unserialize( $value ) ) );
			}
		} finally {
			if ( $release ) {
				$release();
			}
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

	$dry_run = wpmcp_is_dry_run( $args );

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
		if ( ! empty( $plan['response']['code'] ) ) {
			$report['code'] = $plan['response']['code'];
		}
		foreach ( array( 'wrapperGenerated', 'markupGenerated' ) as $field ) {
			if ( ! empty( $plan['response'][ $field ] ) ) {
				$report[ $field ] = $plan['response'][ $field ];
			}
		}
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
	foreach ( array( 'wrapperGenerated', 'markupGenerated' ) as $field ) {
		if ( ! empty( $result[ $field ] ) ) {
			$item[ $field ] = $result[ $field ];
		}
	}

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

	$dry_run = wpmcp_is_dry_run( $args );
	$checks  = array();
	$plans   = array();
	$all_ok  = true;

	// Plans from the dry run are carried into the save, so a real batch
	// validates and renders each page once instead of twice. Only for
	// posts unchanged since (wpmcp_write_content compares the modified
	// time), and not at all when the batch holds a post that renders
	// inside other content: saving that first changes how the pages after
	// it render, and their render check has to see it.
	$reuse = true;

	foreach ( $items as $i => $item ) {
		if ( ! is_array( $item ) || empty( $item['post_id'] ) ) {
			$checks[] = wpmcp_batch_item( $i, 0, new \WP_Error( 'wpmcp_bad_request', 'Each item needs a post_id.' ) );
			$all_ok   = false;
			continue;
		}

		$target = get_post( (int) $item['post_id'] );
		if ( $target && in_array( $target->post_type, wpmcp_embedded_post_types(), true ) ) {
			$reuse = false;
		}

		$item['dry_run'] = true;
		$plans[ $i ]     = null;
		$check           = wpmcp_batch_item( $i, (int) $item['post_id'], wpmcp_write_content( $item, null, $plans[ $i ] ) );

		// A dry run only warns about a page open in the editor. For a real
		// run that warning is a certain refusal halfway through, after the
		// posts before it are saved, so it stops the run before any save.
		if ( ! $dry_run && $check['ok'] ) {
			$locked = $target ? wpmcp_post_lock_error( $target ) : null;
			if ( $locked ) {
				$check = wpmcp_batch_item( $i, (int) $item['post_id'], $locked );
			}
		}

		$checks[] = $check;

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
				: 'At least one item failed its dry run or is open in the editor, so nothing was saved. The items say which and why.',
		);
	}

	$results = array();
	$saved   = 0;

	foreach ( $items as $i => $item ) {
		$item['dry_run'] = false;
		$entry           = wpmcp_batch_item( $i, (int) $item['post_id'], wpmcp_write_content( $item, $reuse ? $plans[ $i ] : null ), true );
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
