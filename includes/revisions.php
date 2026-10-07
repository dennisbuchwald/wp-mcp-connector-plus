<?php
/**
 * Revisions: list them, and restore one as a write like any other.
 *
 * @package wp-mcp-connector-plus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
			'blockCount' => wpmcp_count_blocks_in_markup( $revision->post_content ),
		);
	}

	return array(
		'postId'    => $post->ID,
		'title'     => get_the_title( $post ),
		'current'   => array(
			'modified'   => $post->post_modified_gmt,
			'blockCount' => wpmcp_count_blocks_in_markup( $post->post_content ),
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

	// Putting post_content back restores nothing on an Elementor page,
	// whose content is its element data; Elementor's History panel
	// restores both together.
	if ( wpmcp_elementor_built( $post ) ) {
		return new \WP_Error(
			'wpmcp_elementor_page',
			sprintf(
				'Post %d is built with Elementor. A revision restore here would put back post_content, which is only Elementor\'s plain-text copy, and not the element data the page is rendered from. Restore it in Elementor (History > Revisions), or undo a change with elementor-write.',
				$post->ID
			)
		);
	}

	$revision_id = (int) $revision_id;
	$revision    = wp_get_post_revision( $revision_id ); // By reference: a variable, never an expression.
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

	$locked = wpmcp_post_lock_error( $post );
	if ( $locked && ! $dry_run ) {
		return $locked;
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
		'warnings'   => $locked ? array( $locked->get_error_message() . ' The real restore will be refused until then.' ) : array(),
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
