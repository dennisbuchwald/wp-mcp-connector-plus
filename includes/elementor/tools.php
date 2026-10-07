<?php
/**
 * elementor-read and elementor-write.
 *
 * The write mirrors what Elementor's editor does when a person presses
 * "Update" (Documents_Manager::ajax_save()): the document is switched to,
 * Document::save() is called with the elements, and Elementor does the
 * rest itself: `_elementor_data` (slashed JSON), the plain-text copy in
 * post_content, the revision with the data copied onto it, the version
 * meta, and the per-page CSS file and element cache it deletes so they
 * are built again on the next view. Writing the meta directly would skip
 * all of that and leave a page whose CSS and revisions belong to a
 * different state.
 *
 * @package wp-mcp-connector-plus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The stored element tree of a post.
 *
 * @param int $post_id Post ID.
 * @return array|\WP_Error
 */
function wpmcp_elementor_stored_elements( $post_id ) {
	return wpmcp_elementor_decode( get_post_meta( (int) $post_id, '_elementor_data', true ) );
}

/**
 * Refuse a post that is not an Elementor page.
 *
 * @param \WP_Post $post Post.
 * @return \WP_Error|null
 */
function wpmcp_elementor_not_built_error( $post ) {
	if ( wpmcp_elementor_built( $post ) ) {
		return null;
	}
	return new \WP_Error(
		'wpmcp_not_elementor',
		sprintf( 'Post %d is not built with Elementor; its content is the block tree. Use content-read and content-write.', (int) $post->ID )
	);
}

/**
 * elementor-read: the element tree as an outline, or one element in full.
 *
 * @param array $args { post_id, element_id?, offset? }.
 * @return array|\WP_Error
 */
function wpmcp_elementor_read( array $args ) {
	$post = wpmcp_get_readable_post( (int) ( $args['post_id'] ?? 0 ) );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$not_built = wpmcp_elementor_not_built_error( $post );
	if ( $not_built ) {
		return $not_built;
	}

	$elements = wpmcp_elementor_stored_elements( $post->ID );
	if ( is_wp_error( $elements ) ) {
		return $elements;
	}

	$result = array(
		'id'               => $post->ID,
		'title'            => get_the_title( $post ),
		'type'             => $post->post_type,
		'status'           => $post->post_status,
		'url'              => get_permalink( $post ),
		'slug'             => $post->post_name,
		'builtWith'        => 'elementor',
		'elementorVersion' => (string) get_post_meta( $post->ID, '_elementor_version', true ),
		'elementCount'     => wpmcp_elementor_count_all( $elements ),
		// Hand this to elementor-write as expected_modified.
		'modified'         => $post->post_modified_gmt,
	);

	$atomic = wpmcp_elementor_atomic_ids( $elements );
	if ( ! empty( $atomic ) ) {
		$result['atomic'] = array(
			'ids'      => $atomic,
			'explains' => 'This page holds elements of Elementor\'s Atomic editor (e-heading, e-flexbox ...). They are listed for reading; elementor-write does not change pages that contain them.',
		);
	}

	$element_id = isset( $args['element_id'] ) && is_scalar( $args['element_id'] ) ? trim( (string) $args['element_id'] ) : '';

	if ( '' === $element_id ) {
		$result['outline'] = wpmcp_elementor_outline( $elements );
		$result           += wpmcp_elementor_widgets_report();
		$result['next']    = 'Ask for one element with element_id to get all its settings, and for an HTML widget its markup verbatim. Change it with elementor-write, by element id.';
		wpmcp_log(
			'wpmcp/elementor-read',
			array(
				'post_id' => $post->ID,
				'summary' => 'outline',
			)
		);
		return $result;
	}

	$index = wpmcp_elementor_index( $elements );
	if ( ! isset( $index[ $element_id ] ) ) {
		return wpmcp_elementor_not_found( 'elementor-read', $element_id );
	}
	$element  = wpmcp_elementor_at( $elements, $index[ $element_id ] );
	$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();

	$shown = array(
		'id'       => $element_id,
		'path'     => wpmcp_elementor_path_string( $index[ $element_id ] ),
		'elType'   => (string) ( $element['elType'] ?? '' ),
		'settings' => (object) $settings,
	);
	if ( isset( $element['widgetType'] ) ) {
		$shown['widgetType'] = (string) $element['widgetType'];
	}
	if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
		$shown['children'] = wpmcp_elementor_outline( $element['elements'], $index[ $element_id ] );
	}

	// The markup of an HTML widget is the thing to patch, so it comes
	// verbatim and on its own, in windows like content-preview.
	if ( 'html' === ( $element['widgetType'] ?? '' ) && is_string( $settings['html'] ?? null ) ) {
		unset( $settings['html'] );
		$shown['settings'] = (object) $settings;
		$shown['html']     = wpmcp_slice_text( $element['settings']['html'], WPMCP_WINDOW_BYTES, (int) ( $args['offset'] ?? 0 ) );
		$shown['outline']  = wpmcp_elementor_html_outline( $element['settings']['html'] );
	}

	$result['element'] = $shown;

	wpmcp_log(
		'wpmcp/elementor-read',
		array(
			'post_id' => $post->ID,
			'summary' => 'element ' . $element_id,
		)
	);

	return $result;
}

/**
 * Save elements through Elementor's own document save.
 *
 * With unfiltered_html granted for this one call, where the site allows
 * it at all: without it Elementor runs kses over every string of the page
 * (see guard.php). Whoever calls this has run wpmcp_elementor_guard()
 * on exactly these elements, which is what stands in for that filter.
 *
 * @param int   $post_id  Post ID.
 * @param array $elements Elements to store.
 * @return true|\WP_Error
 */
function wpmcp_elementor_save( $post_id, array $elements ) {
	$plugin   = \Elementor\Plugin::$instance;
	$document = $plugin->documents->get( (int) $post_id, false );
	if ( ! $document ) {
		return new \WP_Error( 'wpmcp_save_failed', sprintf( 'Elementor has no document for post %d. Nothing was saved.', (int) $post_id ) );
	}

	$release = wpmcp_unfiltered_html_blocker() ? null : wpmcp_grant_caps_for_request( array( 'unfiltered_html' ) );

	// As the editor's save does: this document is the current one, and
	// the post is the global post while it saves.
	$plugin->documents->switch_to_document( $document );
	$plugin->db->switch_to_post( (int) $post_id );

	try {
		$saved = $document->save( array( 'elements' => $elements ) );
	} catch ( \Throwable $e ) {
		return new \WP_Error( 'wpmcp_save_failed', sprintf( 'Elementor stopped the save with an error: %s. Read the page again before retrying; a partial save is possible.', $e->getMessage() ) );
	} finally {
		if ( $release ) {
			$release();
		}
		$plugin->db->restore_current_post();
		$plugin->documents->restore_document();
	}

	if ( ! $saved ) {
		return new \WP_Error(
			'wpmcp_save_failed',
			sprintf( 'Elementor refused to save post %d: it does not consider the agent account an editor of this page in Elementor. Check Elementor > Role Manager (the "AI Editor" role must not be excluded). Nothing was saved.', (int) $post_id )
		);
	}

	return true;
}

/**
 * Compare what Elementor stored with what was sent.
 *
 * Elementor and the plugins hooked into its save may rewrite settings
 * (its own content sanitizer does, for heading titles), and an element
 * whose type it cannot load is dropped. Both are said, by element.
 *
 * @param array $sent   Elements sent.
 * @param array $stored Elements as stored now.
 * @return string[] Warnings.
 */
function wpmcp_elementor_verify_stored( array $sent, array $stored ) {
	$warnings = array();

	$missing = array_diff( array_keys( wpmcp_elementor_index( $sent ) ), array_keys( wpmcp_elementor_index( $stored ) ) );
	if ( ! empty( $missing ) ) {
		$warnings[] = sprintf( 'Elementor did not store these elements: %s. Read the page again.', implode( ', ', $missing ) );
	}

	$stored_units = wpmcp_elementor_strings( $stored );
	$altered      = array();
	foreach ( wpmcp_elementor_strings( $sent ) as $key => $value ) {
		if ( array_key_exists( $key, $stored_units ) && $stored_units[ $key ] !== $value ) {
			$altered[] = sprintf( '%s is stored as: %s', $key, wpmcp_shorten( $stored_units[ $key ], 120 ) );
		}
	}
	if ( ! empty( $altered ) ) {
		$warnings[] = 'Elementor stored some settings differently from what was sent: ' . implode( '; ', array_slice( $altered, 0, 10 ) );
	}

	return $warnings;
}

/**
 * Everything elementor-write checks before it saves.
 *
 * @param \WP_Post $post    Post.
 * @param mixed    $ops     Operations as received.
 * @param bool     $dry_run For the response.
 * @return array|\WP_Error { response, errors, elements, summary, before }
 */
function wpmcp_elementor_plan_write( $post, $ops, $dry_run ) {
	if ( is_string( $ops ) || ( is_array( $ops ) && ! empty( $ops ) ) ) {
		$ops = wpmcp_decode_structure( $ops, 'ops' );
		if ( is_wp_error( $ops ) ) {
			return $ops;
		}
	}

	$before = wpmcp_elementor_stored_elements( $post->ID );
	if ( is_wp_error( $before ) ) {
		return $before;
	}

	$atomic = wpmcp_elementor_atomic_ids( $before );
	if ( ! empty( $atomic ) ) {
		return new \WP_Error(
			'wpmcp_elementor_atomic',
			sprintf( 'Post %d holds elements of Elementor\'s Atomic editor (%s). Their settings follow rules these tools do not know, so this page is read-only here; edit it in Elementor.', $post->ID, implode( ', ', array_slice( $atomic, 0, 10 ) ) )
		);
	}

	$applied = wpmcp_elementor_apply_ops( $before, $ops );
	if ( is_wp_error( $applied ) ) {
		return $applied;
	}

	$after = wpmcp_elementor_normalize_jsonld( $applied['elements'], wpmcp_elementor_strings( $before ) );

	$checks = wpmcp_elementor_check_elements( $before, $after );
	$guard  = wpmcp_elementor_guard( $before, $after, $post );

	$errors   = array_merge( $checks['errors'], $guard['errors'] );
	$warnings = array_merge( $checks['warnings'], $guard['warnings'] );

	// Where unfiltered_html cannot be granted, Elementor's own save runs
	// kses over the whole page; what it would change is lost, the agent's
	// part or not, so nothing is saved that would lose anything.
	$blocker = wpmcp_unfiltered_html_blocker();
	if ( $blocker && empty( $errors ) ) {
		$lost = wpmcp_elementor_kses_losses( $after );
		if ( ! empty( $lost ) ) {
			return new \WP_Error(
				'wpmcp_unfiltered_html_unavailable',
				sprintf( 'Saving would make Elementor strip markup from %s, because %s Nothing was saved.', implode( ', ', array_slice( $lost, 0, 10 ) ), lcfirst( $blocker ) )
			);
		}
	}

	$before_count = wpmcp_elementor_count_all( $before );
	$after_count  = wpmcp_elementor_count_all( $after );

	$response = array(
		'ok'       => empty( $errors ),
		'dryRun'   => $dry_run,
		'postId'   => $post->ID,
		'diff'     => array(
			'elementsBefore' => $before_count,
			'elementsAfter'  => $after_count,
			'delta'          => $after_count - $before_count,
			'operations'     => $applied['summary'],
		),
		'errors'   => $errors,
		'warnings' => $warnings,
		'applied'  => $applied['confirm'],
	);

	return array(
		'response' => $response,
		'errors'   => $errors,
		'elements' => $after,
		'summary'  => $applied['summary'],
		'delta'    => $after_count - $before_count,
		'total'    => $after_count,
	);
}

/**
 * elementor-write: operations on an Elementor page, by element id.
 *
 * @param array $args { post_id, ops, dry_run, expected_modified }.
 * @return array|\WP_Error
 */
function wpmcp_elementor_write( array $args ) {
	$timer   = wpmcp_debug_timer();
	$dry_run = wpmcp_is_dry_run( $args );

	$post = wpmcp_get_writable_post( (int) ( $args['post_id'] ?? 0 ) );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$target = wpmcp_assert_writable_target( $post );
	if ( is_wp_error( $target ) ) {
		return $target;
	}
	$not_built = wpmcp_elementor_not_built_error( $post );
	if ( $not_built ) {
		return $not_built;
	}

	$expected = isset( $args['expected_modified'] ) ? trim( (string) $args['expected_modified'] ) : '';
	if ( '' !== $expected && $expected !== $post->post_modified_gmt ) {
		return new \WP_Error(
			'wpmcp_stale',
			sprintf(
				'Post %d changed after you read it (read: %s, now: %s). Someone edited it in the meantime. Read it again with elementor-read and redo the change on the current version.',
				$post->ID,
				$expected,
				$post->post_modified_gmt
			)
		);
	}

	$locked = wpmcp_post_lock_error( $post );
	if ( $locked && ! $dry_run ) {
		return $locked;
	}

	$plan = wpmcp_elementor_plan_write( $post, $args['ops'] ?? null, $dry_run );
	if ( is_wp_error( $plan ) ) {
		return $plan;
	}
	wpmcp_debug_mark( $timer, 'plan' );

	$response = $plan['response'];
	if ( $locked ) {
		$response['warnings'][] = $locked->get_error_message() . ' The real write will be refused until then.';
	}

	if ( ! empty( $plan['errors'] ) ) {
		wpmcp_log(
			'wpmcp/elementor-write',
			array(
				'post_id'   => $post->ID,
				'operation' => $dry_run ? 'ops' : 'rejected',
				'dry_run'   => $dry_run,
				'summary'   => sprintf( 'Rejected: %d validation error(s).', count( $plan['errors'] ) ),
			)
		);
		return wpmcp_debug_attach( $response, $timer );
	}

	if ( $dry_run ) {
		$response['message'] = 'Dry run only — nothing was saved. Call again with dry_run: false to write.';
		wpmcp_log(
			'wpmcp/elementor-write',
			array(
				'post_id'   => $post->ID,
				'operation' => 'ops',
				'dry_run'   => true,
				'summary'   => sprintf( 'Dry run OK (%+d elements): %s.', $plan['delta'], implode( '; ', $plan['summary'] ) ),
			)
		);
		return wpmcp_debug_attach( $response, $timer );
	}

	list( $saved, $revision_id ) = wpmcp_save_capturing_revision(
		$post->ID,
		function () use ( $post, $plan ) {
			return wpmcp_elementor_save( $post->ID, $plan['elements'] );
		}
	);
	if ( is_wp_error( $saved ) ) {
		return $saved;
	}
	wpmcp_debug_mark( $timer, 'save' );

	$stored = wpmcp_elementor_stored_elements( $post->ID );
	$check  = is_wp_error( $stored ) ? array( $stored->get_error_message() ) : wpmcp_elementor_verify_stored( $plan['elements'], $stored );
	if ( ! empty( $check ) ) {
		$response['warnings']       = array_merge( $response['warnings'], $check );
		$response['contentAltered'] = true;
	}

	$response['message']    = 'Saved.';
	$response['revisionId'] = $revision_id;
	$response['preview']    = wpmcp_preview_url( $post->ID );
	$response['css']        = 'Elementor removed this page\'s generated CSS file; it is built again on the next view.';

	wpmcp_after_save( $post, $response );
	$response['verify'] = 'elementor-read shows what is stored, content-preview renders it through Elementor, content-fetch-live shows what a visitor gets.';

	wpmcp_log(
		'wpmcp/elementor-write',
		array(
			'post_id'     => $post->ID,
			'operation'   => 'ops',
			'dry_run'     => false,
			'summary'     => sprintf( 'Saved (%+d elements, %d total): %s.', $plan['delta'], $plan['total'], implode( '; ', $plan['summary'] ) ),
			'revision_id' => $revision_id,
		)
	);

	return wpmcp_debug_attach( $response, $timer );
}

/**
 * The page as Elementor renders it, for content-preview.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function wpmcp_elementor_render( $post_id ) {
	$plugin = \Elementor\Plugin::$instance;
	$plugin->documents->get( (int) $post_id, false );
	return (string) $plugin->frontend->get_builder_content( (int) $post_id, false );
}

/**
 * content-search on an Elementor page: every string setting of every
 * element, the HTML widget's markup included.
 *
 * @param int    $post_id  Post ID.
 * @param string $query    Needle or pattern.
 * @param bool   $is_regex Whether the query is a regular expression.
 * @param int    $context  Context characters.
 * @param array  $hits     Collected hits (by reference), without the post fields.
 * @return true|\WP_Error An error when the pattern failed to run.
 */
function wpmcp_elementor_search( $post_id, $query, $is_regex, $context, array &$hits ) {
	$elements = wpmcp_elementor_stored_elements( $post_id );
	if ( is_wp_error( $elements ) ) {
		// Unreadable data has nothing to find; elementor-read says why.
		return true;
	}

	$index = wpmcp_elementor_index( $elements );
	$by_id = wpmcp_elementor_by_id( $elements );

	foreach ( wpmcp_elementor_strings( $elements ) as $key => $haystack ) {
		if ( '' === $haystack ) {
			continue;
		}
		$offsets = wpmcp_find_offsets( $haystack, $query, $is_regex );
		if ( is_wp_error( $offsets ) ) {
			return $offsets;
		}
		list( $id, $setting ) = explode( ':', $key, 2 );
		foreach ( $offsets as $offset ) {
			$hits[] = array_filter(
				array(
					'path'       => isset( $index[ $id ] ) ? wpmcp_elementor_path_string( $index[ $id ] ) : null,
					'blockName'  => null,
					'elementId'  => $id,
					'elType'     => $by_id[ $id ]['elType'] ?? null,
					'widgetType' => $by_id[ $id ]['widgetType'] ?? null,
					'setting'    => $setting,
					'in'         => 'elementor',
					'offset'     => $offset[0],
					'match'      => $offset[1],
					'context'    => wpmcp_context_around( $haystack, $offset[0], strlen( $offset[1] ), $context ),
				),
				function ( $value, $name ) {
					return null !== $value || 'blockName' === $name;
				},
				ARRAY_FILTER_USE_BOTH
			);
		}
	}

	return true;
}
