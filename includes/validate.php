<?php
/**
 * The validation pipeline every write passes through — dry run and real
 * write run exactly the same checks; only the final save differs.
 *
 * 1. existence   block registered?
 * 2. schema      attributes typed, enum-clean, no invented keys
 * 3. structure   nesting legal (parent / ancestor / allowedBlocks)
 * 4. design      no free colours or sizes where theme.json locks them
 * 5. roundtrip   serialize -> parse -> compare, then render smoke test
 *
 * @package wp-mcp-connector-plus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stages 1 to 4 over a block array: existence, schema, structure, design.
 *
 * @param array $blocks Parsed blocks.
 * @return array { errors: string[], warnings: string[] }
 */
function wpmcp_collect_issues( array $blocks ) {
	$errors   = array();
	$warnings = array();

	wpmcp_walk_validate( $blocks, null, array(), $errors, $warnings );

	return array(
		'errors'   => $errors,
		'warnings' => $warnings,
	);
}

/**
 * Strip the leading block path from a message, so the same problem in a
 * different position counts as the same problem. Paths shift whenever a
 * block is inserted or removed.
 *
 * @param string $message Validation message.
 * @return string
 */
function wpmcp_issue_shape( $message ) {
	return (string) preg_replace( '/^[0-9]+(\.[0-9]+)*: /', '', $message );
}

/**
 * How often each kind of problem occurs, ignoring position.
 *
 * @param string[] $messages Messages.
 * @return array<string, int>
 */
function wpmcp_issue_counts( array $messages ) {
	$counts = array();
	foreach ( $messages as $message ) {
		$shape = wpmcp_issue_shape( $message );
		$counts[ $shape ] = ( $counts[ $shape ] ?? 0 ) + 1;
	}
	return $counts;
}

/**
 * Separate problems this change introduced from problems it inherited.
 *
 * A page saved through the block editor can hold values this validator
 * rejects — a literal colour where the design system wants a slug, say.
 * Refusing to write such a page at all would mean the agent can never
 * touch it, however clean its own edit is. So a problem that was already
 * there stays reported but stops being a veto; only what the change adds
 * blocks the save.
 *
 * Counted rather than matched, so adding a sixth violation of a kind that
 * already occurred five times is still caught.
 *
 * @param string[] $after  Errors after the change.
 * @param string[] $before Errors before the change.
 * @return array { errors: string[], inherited: string[] }
 */
function wpmcp_split_inherited_errors( array $after, array $before ) {
	$budget    = wpmcp_issue_counts( $before );
	$errors    = array();
	$inherited = array();

	foreach ( $after as $message ) {
		$shape = wpmcp_issue_shape( $message );
		if ( ! empty( $budget[ $shape ] ) ) {
			--$budget[ $shape ];
			$inherited[] = $message . ' (already present before this change, left as it was)';
			continue;
		}
		$errors[] = $message;
	}

	return array(
		'errors'    => $errors,
		'inherited' => $inherited,
	);
}

/**
 * Run the full pipeline over a block array.
 *
 * @param array $blocks Parsed blocks to validate.
 * @param array $before Blocks as they were, so pre-existing problems do
 *                      not veto a change that did not cause them.
 * @return array { errors: string[], warnings: string[], serialized: string }
 */
function wpmcp_validate_blocks( array $blocks, ?array $before = null ) {
	$issues   = wpmcp_collect_issues( $blocks );
	$errors   = $issues['errors'];
	$warnings = $issues['warnings'];

	if ( null !== $before ) {
		$baseline = wpmcp_collect_issues( $before );
		$split    = wpmcp_split_inherited_errors( $errors, $baseline['errors'] );
		$errors   = $split['errors'];
		$warnings = array_merge( $warnings, $split['inherited'] );
	}

	$serialized = '';

	// Stage 5 only makes sense once the tree itself is sound.
	if ( empty( $errors ) ) {
		$serialized = serialize_blocks( $blocks );

		$reparsed = parse_blocks( $serialized );
		if ( wpmcp_count_blocks( $reparsed ) !== wpmcp_count_blocks( $blocks ) ) {
			$errors[] = 'Roundtrip check failed: serialising and re-parsing changes the block count. The tree was not written.';
		} else {
			$before = wpmcp_block_names( $blocks );
			$after  = wpmcp_block_names( $reparsed );
			if ( $before !== $after ) {
				$errors[] = 'Roundtrip check failed: block order or names change when re-parsed. The tree was not written.';
			}
		}

		if ( empty( $errors ) ) {
			$render = wpmcp_render_smoke_test( $serialized );
			if ( is_wp_error( $render ) ) {
				$errors[] = 'Render check failed: ' . $render->get_error_message();
			} elseif ( ! empty( $render['notices'] ) ) {
				foreach ( $render['notices'] as $notice ) {
					$warnings[] = 'Render notice: ' . $notice;
				}
			}
		}
	}

	return array(
		'errors'     => $errors,
		'warnings'   => $warnings,
		'serialized' => $serialized,
	);
}

/**
 * Walk the tree and run stages 1-4 per block.
 *
 * @param array       $blocks   Blocks.
 * @param string|null $parent   Parent block name.
 * @param array       $ancestry Ancestor block names (outermost first).
 * @param array       $errors   Errors (by reference).
 * @param array       $warnings Warnings (by reference).
 * @param string      $prefix   Path prefix.
 */
function wpmcp_walk_validate( array $blocks, $parent, array $ancestry, array &$errors, array &$warnings, $prefix = '' ) {
	$registry = \WP_Block_Type_Registry::get_instance();
	$index    = 0;

	foreach ( $blocks as $block ) {
		$name = $block['blockName'] ?? null;

		if ( null === $name ) {
			if ( '' !== trim( (string) ( $block['innerHTML'] ?? '' ) ) ) {
				++$index;
			}
			continue;
		}

		$path = ( '' === $prefix ) ? (string) $index : $prefix . '.' . $index;

		// Stage 1: existence.
		$type = $registry->get_registered( $name );
		if ( ! $type ) {
			$errors[] = sprintf(
				'%s: block "%s" is not registered on this site. Use blocks-catalog to see what exists.',
				$path,
				$name
			);
			++$index;
			continue;
		}

		// Stage 2: attributes.
		$attrs  = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$result = wpmcp_validate_attrs( $name, $attrs, $path );
		$errors   = array_merge( $errors, $result['errors'] );
		$warnings = array_merge( $warnings, $result['warnings'] );

		// Stage 3: structure.
		wpmcp_validate_nesting( $type, $name, $parent, $ancestry, $path, $errors );
		wpmcp_validate_allowed_children( $type, $name, $block, $path, $errors );

		// A closed container with nothing in it renders as nothing. Usually
		// a leftover from a template that was adapted but not emptied.
		if ( ! empty( $type->allowed_blocks ) && empty( $block['innerBlocks'] ) ) {
			$warnings[] = sprintf(
				'%s: "%s" is a container and holds no blocks. It renders as an empty section — either fill it or remove it.',
				$path,
				$name
			);
		}

		// Stage 4: design contract.
		wpmcp_validate_design( $name, $attrs, $path, $errors, $warnings );

		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			wpmcp_walk_validate(
				$block['innerBlocks'],
				$name,
				array_merge( $ancestry, array( $name ) ),
				$errors,
				$warnings,
				$path
			);
		}

		++$index;
	}
}

/**
 * Stage 3a: does this block accept its parent?
 *
 * @param \WP_Block_Type $type     Block type.
 * @param string         $name     Block name.
 * @param string|null    $parent   Direct parent name.
 * @param array          $ancestry Ancestor names.
 * @param string         $path     Path.
 * @param array          $errors   Errors (by reference).
 */
function wpmcp_validate_nesting( $type, $name, $parent, array $ancestry, $path, array &$errors ) {
	if ( ! empty( $type->parent ) && is_array( $type->parent ) ) {
		if ( null === $parent || ! in_array( $parent, $type->parent, true ) ) {
			$errors[] = sprintf(
				'%s: "%s" must sit directly inside %s (found: %s).',
				$path,
				$name,
				implode( ' or ', $type->parent ),
				null === $parent ? 'top level' : $parent
			);
		}
	}

	if ( ! empty( $type->ancestor ) && is_array( $type->ancestor ) ) {
		if ( ! array_intersect( $type->ancestor, $ancestry ) ) {
			$errors[] = sprintf(
				'%s: "%s" must be nested somewhere inside %s.',
				$path,
				$name,
				implode( ' or ', $type->ancestor )
			);
		}
	}
}

/**
 * Stage 3b: does this container accept the children it was given?
 *
 * @param \WP_Block_Type $type   Block type.
 * @param string         $name   Block name.
 * @param array          $block  Block array.
 * @param string         $path   Path.
 * @param array          $errors Errors (by reference).
 */
function wpmcp_validate_allowed_children( $type, $name, array $block, $path, array &$errors ) {
	$allowed = $type->allowed_blocks ?? null;
	if ( empty( $allowed ) || ! is_array( $allowed ) ) {
		return;
	}

	$children = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array();
	foreach ( $children as $child ) {
		$child_name = $child['blockName'] ?? null;
		if ( null === $child_name ) {
			continue;
		}
		if ( ! in_array( $child_name, $allowed, true ) ) {
			$errors[] = sprintf(
				'%s: "%s" does not accept "%s" as a child. Allowed: %s.',
				$path,
				$name,
				$child_name,
				implode( ', ', $allowed )
			);
		}
	}
}

/**
 * Stage 4: theme.json locks free colours and font sizes. Writing a raw hex
 * where only preset slugs are allowed breaks the design contract, so it is
 * an error rather than a warning.
 *
 * @param string $name     Block name.
 * @param array  $attrs    Attributes.
 * @param string $path     Path.
 * @param array  $errors   Errors (by reference).
 * @param array  $warnings Warnings (by reference).
 */
function wpmcp_validate_design( $name, array $attrs, $path, array &$errors, array &$warnings ) {
	$settings     = function_exists( 'wp_get_global_settings' ) ? wp_get_global_settings() : array();
	$custom_color = $settings['color']['custom'] ?? true;
	$custom_size  = $settings['typography']['customFontSize'] ?? true;

	$style = $attrs['style'] ?? null;
	if ( is_array( $style ) && false === $custom_color ) {
		$found = wpmcp_find_hex_values( $style );
		foreach ( $found as $hex ) {
			$errors[] = sprintf(
				'%s: literal colour "%s" in style — theme.json disables custom colours. Use a preset slug from site-info.designTokens.colors.',
				$path,
				$hex
			);
		}
	}

	if ( is_array( $style ) && false === $custom_size && isset( $style['typography']['fontSize'] ) ) {
		$errors[] = sprintf(
			'%s: custom fontSize is disabled by theme.json. Use a preset font size slug instead.',
			$path
		);
	}

	// Our own blocks take colours as slugs; a hex in a *Color attribute is
	// always wrong even where core would allow it.
	foreach ( $attrs as $key => $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			continue;
		}
		if ( preg_match( '/color$/i', $key ) && preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $value ) ) {
			if ( wpmcp_is_site_block( $name ) ) {
				$errors[] = sprintf(
					'%s: attribute "%s" is a hex value ("%s"). Site kit blocks expect preset colour slugs.',
					$path,
					$key,
					$value
				);
			} else {
				$warnings[] = sprintf( '%s: attribute "%s" uses a literal hex colour.', $path, $key );
			}
		}
	}
}

/**
 * Collect hex colour literals from a nested style array.
 *
 * @param array $style Style array.
 * @return string[]
 */
function wpmcp_find_hex_values( array $style ) {
	$found = array();
	array_walk_recursive(
		$style,
		function ( $value ) use ( &$found ) {
			if ( is_string( $value ) && preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $value ) ) {
				$found[] = $value;
			}
		}
	);
	return $found;
}

/**
 * Stage 5b: render the markup once with errors converted to exceptions, so
 * a broken render.php surfaces here instead of on the live page.
 *
 * @param string $serialized Serialized block markup.
 * @return array|\WP_Error { notices: string[] }
 */
function wpmcp_render_smoke_test( $serialized ) {
	$notices = array();

	set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_handler
		function ( $errno, $errstr, $errfile, $errline ) use ( &$notices ) {
			// Fatal-ish levels abort the render; lesser ones are reported.
			if ( in_array( $errno, array( E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) ) {
				throw new \ErrorException( $errstr, 0, $errno, $errfile, $errline );
			}
			$notices[] = sprintf( '%s (%s:%d)', $errstr, basename( (string) $errfile ), (int) $errline );
			return true;
		}
	);

	try {
		ob_start();
		do_blocks( $serialized );
		ob_end_clean();
	} catch ( \Throwable $e ) {
		if ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		restore_error_handler();
		return new \WP_Error(
			'wpmcp_render_failed',
			sprintf( '%s in %s:%d', $e->getMessage(), basename( $e->getFile() ), $e->getLine() )
		);
	}

	restore_error_handler();

	return array( 'notices' => array_slice( array_unique( $notices ), 0, 10 ) );
}
