<?php
/**
 * Block tree transforms: WordPress block arrays <-> the compact JSON tree
 * the AI works with, plus path addressing and patch operations.
 *
 * Design rule: the model never sees or writes serialized block markup.
 * Serialization happens here, in PHP, after validation.
 *
 * @package wp-mcp-connector-plus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Convert parsed WordPress blocks into the compact tree format.
 *
 * @param array $blocks           Result of parse_blocks().
 * @param array $prefix           Path prefix (ints) for addressing.
 * @param bool  $include_defaults Whether to keep attributes equal to their default.
 * @return array
 */
function wpmcp_blocks_to_tree( array $blocks, array $prefix = array(), $include_defaults = false ) {
	$out = array();
	$i   = 0;

	foreach ( $blocks as $block ) {
		// parse_blocks() emits whitespace-only "null blocks" between real ones.
		if ( null === $block['blockName'] ) {
			if ( '' !== trim( (string) ( $block['innerHTML'] ?? '' ) ) ) {
				$out[] = array(
					'path' => wpmcp_path_string( array_merge( $prefix, array( $i ) ) ),
					'name' => null,
					'html' => $block['innerHTML'],
				);
				++$i;
			}
			continue;
		}

		$path  = array_merge( $prefix, array( $i ) );
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();

		if ( ! $include_defaults ) {
			$attrs = wpmcp_strip_defaults( $block['blockName'], $attrs );
		}

		$node = array(
			'path'  => wpmcp_path_string( $path ),
			'name'  => $block['blockName'],
			'attrs' => (object) $attrs,
		);

		$inner_blocks = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array();
		$has_children = ! empty( $inner_blocks );
		$html         = (string) ( $block['innerHTML'] ?? '' );

		if ( $has_children ) {
			$node['innerBlocks'] = wpmcp_blocks_to_tree( $inner_blocks, $path, $include_defaults );

			// Mixed content (wrapper markup around children, e.g. core/group):
			// keep the original interleaving so we can rebuild it byte-exactly.
			if ( '' !== trim( $html ) ) {
				$node['htmlTemplate'] = array_values( (array) ( $block['innerContent'] ?? array() ) );
			}
		} elseif ( '' !== trim( $html ) ) {
			$node['html'] = $html;
		}

		$out[] = $node;
		++$i;
	}

	return $out;
}

/**
 * Convert the compact tree back into WordPress block arrays.
 *
 * @param array  $nodes  Tree nodes.
 * @param string $prefix Path prefix for error messages.
 * @param array  $errors Collected errors (by reference).
 * @return array Blocks ready for serialize_blocks().
 */
function wpmcp_tree_to_blocks( array $nodes, $prefix, array &$errors ) {
	$blocks = array();

	foreach ( array_values( $nodes ) as $i => $node ) {
		$path = ( '' === $prefix ) ? (string) $i : $prefix . '.' . $i;

		if ( ! is_array( $node ) ) {
			$errors[] = sprintf( '%s: node must be an object.', $path );
			continue;
		}

		$name = $node['name'] ?? null;

		// Freeform HTML passthrough (classic content).
		if ( null === $name ) {
			$blocks[] = array(
				'blockName'    => null,
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => (string) ( $node['html'] ?? '' ),
				'innerContent' => array( (string) ( $node['html'] ?? '' ) ),
			);
			continue;
		}

		if ( ! is_string( $name ) || '' === $name ) {
			$errors[] = sprintf( '%s: "name" must be a non-empty block name.', $path );
			continue;
		}

		$attrs = $node['attrs'] ?? array();
		$attrs = is_object( $attrs ) ? (array) $attrs : $attrs;
		if ( ! is_array( $attrs ) ) {
			$errors[] = sprintf( '%s: "attrs" must be an object.', $path );
			$attrs    = array();
		}

		$children = array();
		if ( ! empty( $node['innerBlocks'] ) && is_array( $node['innerBlocks'] ) ) {
			$children = wpmcp_tree_to_blocks( $node['innerBlocks'], $path, $errors );
		}

		$html     = (string) ( $node['html'] ?? '' );
		$template = ( isset( $node['htmlTemplate'] ) && is_array( $node['htmlTemplate'] ) ) ? $node['htmlTemplate'] : null;

		$inner_content = wpmcp_build_inner_content( $children, $html, $template, $path, $errors );

		$blocks[] = array(
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => $children,
			'innerHTML'    => wpmcp_inner_html_from_content( $inner_content ),
			'innerContent' => $inner_content,
		);
	}

	return $blocks;
}

/**
 * Rebuild the innerContent interleaving for one block.
 *
 * @param array       $children Child blocks.
 * @param string      $html     Leaf markup.
 * @param array|null  $template Preserved innerContent template.
 * @param string      $path     Path for errors.
 * @param array       $errors   Errors (by reference).
 * @return array
 */
function wpmcp_build_inner_content( array $children, $html, $template, $path, array &$errors ) {
	$child_count = count( $children );

	if ( 0 === $child_count ) {
		return ( '' === $html ) ? array() : array( $html );
	}

	// Children only (the InnerBlocks.Content pattern): pure null slots.
	if ( '' === trim( $html ) && null === $template ) {
		return array_fill( 0, $child_count, null );
	}

	// Wrapper markup around children: reuse the template when the slot count matches.
	if ( null !== $template ) {
		$slots = 0;
		foreach ( $template as $chunk ) {
			if ( null === $chunk ) {
				++$slots;
			}
		}
		if ( $slots === $child_count ) {
			return array_values( $template );
		}
		// Slot count changed: keep the outer shell, re-slot the children.
		$open  = ( isset( $template[0] ) && is_string( $template[0] ) ) ? $template[0] : '';
		$close = '';
		$last  = end( $template );
		if ( is_string( $last ) && count( $template ) > 1 ) {
			$close = $last;
		}
		return array_merge(
			'' === $open ? array() : array( $open ),
			array_fill( 0, $child_count, null ),
			'' === $close ? array() : array( $close )
		);
	}

	// Markup plus children but no template: we cannot know the interleaving.
	$errors[] = sprintf(
		'%s: block has both "html" and "innerBlocks" but no "htmlTemplate". Use a container block from the site kit, or keep the htmlTemplate you got from content-read.',
		$path
	);

	return array_fill( 0, $child_count, null );
}

/**
 * Flatten innerContent to innerHTML (WordPress keeps both in sync).
 *
 * @param array $inner_content Inner content chunks.
 * @return string
 */
function wpmcp_inner_html_from_content( array $inner_content ) {
	$html = '';
	foreach ( $inner_content as $chunk ) {
		if ( is_string( $chunk ) ) {
			$html .= $chunk;
		}
	}
	return $html;
}

/**
 * Drop attributes that equal their registered default, so the model only
 * sees what was actually decided on this page.
 *
 * @param string $block_name Block name.
 * @param array  $attrs      Attributes.
 * @return array
 */
function wpmcp_strip_defaults( $block_name, array $attrs ) {
	$type = \WP_Block_Type_Registry::get_instance()->get_registered( $block_name );
	if ( ! $type || ! is_array( $type->attributes ) ) {
		return $attrs;
	}

	foreach ( $attrs as $key => $value ) {
		$def = $type->attributes[ $key ] ?? null;
		if ( is_array( $def ) && array_key_exists( 'default', $def ) && $def['default'] === $value ) {
			unset( $attrs[ $key ] );
		}
	}

	return $attrs;
}

/**
 * Build a compact outline: block names, nesting and a short label per block.
 * Cheap enough to read several pages before deciding anything.
 *
 * @param array $blocks Parsed blocks.
 * @param array $prefix Path prefix.
 * @param int   $depth  Current depth.
 * @return array
 */
function wpmcp_blocks_to_outline( array $blocks, array $prefix = array(), $depth = 0 ) {
	$out = array();
	$i   = 0;

	foreach ( $blocks as $block ) {
		if ( null === $block['blockName'] ) {
			if ( '' === trim( (string) ( $block['innerHTML'] ?? '' ) ) ) {
				continue;
			}
		}

		$path = array_merge( $prefix, array( $i ) );
		$name = $block['blockName'] ?? '(html)';

		$entry = array(
			'path' => wpmcp_path_string( $path ),
			'name' => $name,
		);

		$label = wpmcp_block_label( $block );
		if ( '' !== $label ) {
			$entry['label'] = $label;
		}

		$children = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array();
		if ( ! empty( $children ) ) {
			$entry['children'] = wpmcp_blocks_to_outline( $children, $path, $depth + 1 );
		}

		$out[] = $entry;
		++$i;
	}

	return $out;
}

/**
 * Short human label for a block: the most heading-like attribute, else text.
 *
 * @param array $block Parsed block.
 * @return string
 */
function wpmcp_block_label( array $block ) {
	$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();

	$candidates = array( 'heading', 'sectionHeading', 'title', 'headline', 'question', 'name', 'label', 'text', 'quote', 'eyebrow' );
	foreach ( $candidates as $key ) {
		if ( ! empty( $attrs[ $key ] ) && is_string( $attrs[ $key ] ) ) {
			return wpmcp_shorten( wp_strip_all_tags( $attrs[ $key ] ) );
		}
	}

	$html = (string) ( $block['innerHTML'] ?? '' );
	if ( '' !== trim( $html ) ) {
		return wpmcp_shorten( wp_strip_all_tags( $html ) );
	}

	return '';
}

/**
 * Trim a label to a readable length.
 *
 * @param string $text Text.
 * @param int    $max  Max characters.
 * @return string
 */
function wpmcp_shorten( $text, $max = 80 ) {
	$text = trim( preg_replace( '/\s+/', ' ', $text ) );
	if ( mb_strlen( $text ) <= $max ) {
		return $text;
	}
	return mb_substr( $text, 0, $max - 1 ) . '…';
}

/**
 * Path helpers: [2,0,1] <-> "2.0.1".
 *
 * @param array $path Path segments.
 * @return string
 */
function wpmcp_path_string( array $path ) {
	return implode( '.', $path );
}

/**
 * Parse a path string into segments.
 *
 * @param string $path Path like "2.0.1".
 * @return int[]|null Null when malformed.
 */
function wpmcp_path_parse( $path ) {
	$path = trim( (string) $path );
	if ( '' === $path ) {
		return array();
	}
	if ( ! preg_match( '/^\d+(\.\d+)*$/', $path ) ) {
		return null;
	}
	return array_map( 'intval', explode( '.', $path ) );
}

/**
 * Fetch the subtree at a path from parsed blocks.
 *
 * @param array $blocks Parsed blocks.
 * @param array $path   Path segments.
 * @return array|null
 */
function wpmcp_blocks_at_path( array $blocks, array $path ) {
	$current = null;
	$list    = $blocks;

	foreach ( $path as $index ) {
		$list = array_values(
			array_filter(
				$list,
				function ( $b ) {
					return null !== $b['blockName'] || '' !== trim( (string) ( $b['innerHTML'] ?? '' ) );
				}
			)
		);
		if ( ! isset( $list[ $index ] ) ) {
			return null;
		}
		$current = $list[ $index ];
		$list    = is_array( $current['innerBlocks'] ?? null ) ? $current['innerBlocks'] : array();
	}

	return $current;
}

/**
 * Apply patch operations to a block array.
 *
 * Operations are applied in order; each targets a path in the tree as it
 * looks at that moment. Deepest-first ordering is the caller's job when
 * mixing removals — the summary reports what happened either way.
 *
 * @param array $blocks Parsed blocks (by value).
 * @param array $ops    List of ops: { op, path, block?, blocks?, attrs?, to? }.
 * @return array|\WP_Error { blocks: array, summary: array }
 */
function wpmcp_apply_ops( array $blocks, array $ops ) {
	$summary = array(
		'inserted' => 0,
		'replaced' => 0,
		'removed'  => 0,
		'moved'    => 0,
		'patched'  => 0,
	);

	foreach ( $ops as $n => $op ) {
		if ( ! is_array( $op ) ) {
			return new \WP_Error( 'wpmcp_bad_op', sprintf( 'Operation %d must be an object.', $n ) );
		}

		$kind = $op['op'] ?? '';
		$path = wpmcp_path_parse( $op['path'] ?? '' );

		if ( null === $path ) {
			return new \WP_Error( 'wpmcp_bad_path', sprintf( 'Operation %d: malformed path "%s".', $n, (string) ( $op['path'] ?? '' ) ) );
		}

		switch ( $kind ) {
			case 'insert':
				$nodes = isset( $op['blocks'] ) ? $op['blocks'] : ( isset( $op['block'] ) ? array( $op['block'] ) : null );
				if ( ! is_array( $nodes ) ) {
					return new \WP_Error( 'wpmcp_bad_op', sprintf( 'Operation %d (insert): "block" or "blocks" required.', $n ) );
				}
				$errors = array();
				$new    = wpmcp_tree_to_blocks( $nodes, 'op' . $n, $errors );
				if ( ! empty( $errors ) ) {
					return new \WP_Error( 'wpmcp_bad_block', implode( ' ', $errors ) );
				}
				$result = wpmcp_splice( $blocks, $path, $new, 0 );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$blocks              = $result;
				$summary['inserted'] += count( $new );
				break;

			case 'replace':
				$nodes = isset( $op['blocks'] ) ? $op['blocks'] : ( isset( $op['block'] ) ? array( $op['block'] ) : null );
				if ( ! is_array( $nodes ) ) {
					return new \WP_Error( 'wpmcp_bad_op', sprintf( 'Operation %d (replace): "block" or "blocks" required.', $n ) );
				}
				$errors = array();
				$new    = wpmcp_tree_to_blocks( $nodes, 'op' . $n, $errors );
				if ( ! empty( $errors ) ) {
					return new \WP_Error( 'wpmcp_bad_block', implode( ' ', $errors ) );
				}
				$result = wpmcp_splice( $blocks, $path, $new, 1 );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$blocks              = $result;
				$summary['replaced'] += 1;
				break;

			case 'remove':
				$result = wpmcp_splice( $blocks, $path, array(), 1 );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$blocks             = $result;
				$summary['removed'] += 1;
				break;

			case 'set_attrs':
				$attrs = $op['attrs'] ?? null;
				$attrs = is_object( $attrs ) ? (array) $attrs : $attrs;
				if ( ! is_array( $attrs ) ) {
					return new \WP_Error( 'wpmcp_bad_op', sprintf( 'Operation %d (set_attrs): "attrs" object required.', $n ) );
				}
				$result = wpmcp_patch_attrs( $blocks, $path, $attrs );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$blocks             = $result;
				$summary['patched'] += 1;
				break;

			case 'move':
				$to = wpmcp_path_parse( $op['to'] ?? '' );
				if ( null === $to ) {
					return new \WP_Error( 'wpmcp_bad_path', sprintf( 'Operation %d (move): malformed "to" path.', $n ) );
				}
				$node = wpmcp_blocks_at_path( $blocks, $path );
				if ( null === $node ) {
					return new \WP_Error( 'wpmcp_path_not_found', sprintf( 'Operation %d (move): path "%s" not found.', $n, wpmcp_path_string( $path ) ) );
				}
				$removed = wpmcp_splice( $blocks, $path, array(), 1 );
				if ( is_wp_error( $removed ) ) {
					return $removed;
				}
				$inserted = wpmcp_splice( $removed, $to, array( $node ), 0 );
				if ( is_wp_error( $inserted ) ) {
					return $inserted;
				}
				$blocks           = $inserted;
				$summary['moved'] += 1;
				break;

			default:
				return new \WP_Error(
					'wpmcp_bad_op',
					sprintf( 'Operation %d: unknown op "%s". Use insert, replace, remove, set_attrs or move.', $n, (string) $kind )
				);
		}
	}

	return array(
		'blocks'  => $blocks,
		'summary' => $summary,
	);
}

/**
 * Splice blocks at a path: insert before (delete 0) or replace/remove (delete 1).
 *
 * @param array $blocks      Parsed blocks.
 * @param array $path        Target path.
 * @param array $replacement Blocks to put in.
 * @param int   $delete      How many to remove at the position.
 * @return array|\WP_Error
 */
function wpmcp_splice( array $blocks, array $path, array $replacement, $delete ) {
	if ( empty( $path ) ) {
		return new \WP_Error( 'wpmcp_bad_path', 'Path must not be empty.' );
	}

	$index = array_pop( $path );

	if ( empty( $path ) ) {
		return wpmcp_splice_list( $blocks, $index, $replacement, $delete );
	}

	$parent = wpmcp_blocks_at_path( $blocks, $path );
	if ( null === $parent ) {
		return new \WP_Error( 'wpmcp_path_not_found', sprintf( 'Parent path "%s" not found.', wpmcp_path_string( $path ) ) );
	}

	$children = is_array( $parent['innerBlocks'] ?? null ) ? $parent['innerBlocks'] : array();
	$updated  = wpmcp_splice_list( $children, $index, $replacement, $delete );
	if ( is_wp_error( $updated ) ) {
		return $updated;
	}

	return wpmcp_set_children( $blocks, $path, $updated );
}

/**
 * Splice within one list of siblings, honouring the "visible index"
 * addressing (whitespace null-blocks do not count).
 *
 * @param array $list        Sibling blocks.
 * @param int   $index       Visible index.
 * @param array $replacement Replacement blocks.
 * @param int   $delete      Delete count.
 * @return array|\WP_Error
 */
function wpmcp_splice_list( array $list, $index, array $replacement, $delete ) {
	$visible = array();
	foreach ( $list as $real => $block ) {
		if ( null !== $block['blockName'] || '' !== trim( (string) ( $block['innerHTML'] ?? '' ) ) ) {
			$visible[] = $real;
		}
	}

	if ( $index < 0 || $index > count( $visible ) ) {
		return new \WP_Error( 'wpmcp_path_not_found', sprintf( 'Index %d is out of range (%d blocks).', $index, count( $visible ) ) );
	}

	// Appending past the last element.
	if ( $index === count( $visible ) ) {
		if ( $delete > 0 ) {
			return new \WP_Error( 'wpmcp_path_not_found', sprintf( 'Index %d does not exist.', $index ) );
		}
		return array_merge( $list, $replacement );
	}

	$real_index = $visible[ $index ];

	return array_merge(
		array_slice( $list, 0, $real_index ),
		$replacement,
		array_slice( $list, $real_index + $delete )
	);
}

/**
 * Replace the innerBlocks of the block at a path.
 *
 * @param array $blocks   Parsed blocks.
 * @param array $path     Path.
 * @param array $children New children.
 * @return array|\WP_Error
 */
function wpmcp_set_children( array $blocks, array $path, array $children ) {
	return wpmcp_mutate_at( $blocks, $path, function ( $block ) use ( $children ) {
		$old_count = count( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array() );
		$block['innerBlocks'] = $children;

		// Keep innerContent slot count in sync with the child count.
		$content = is_array( $block['innerContent'] ?? null ) ? $block['innerContent'] : array();
		if ( count( $children ) !== $old_count ) {
			$errors  = array();
			$html    = '' === trim( (string) ( $block['innerHTML'] ?? '' ) ) ? '' : (string) $block['innerHTML'];
			$content = wpmcp_build_inner_content(
				$children,
				$html,
				empty( $content ) ? null : $content,
				'',
				$errors
			);
		}
		$block['innerContent'] = $content;
		$block['innerHTML']    = wpmcp_inner_html_from_content( $content );

		return $block;
	} );
}

/**
 * Merge attributes into the block at a path (null value removes a key).
 *
 * @param array $blocks Parsed blocks.
 * @param array $path   Path.
 * @param array $attrs  Attributes to merge.
 * @return array|\WP_Error
 */
function wpmcp_patch_attrs( array $blocks, array $path, array $attrs ) {
	return wpmcp_mutate_at( $blocks, $path, function ( $block ) use ( $attrs ) {
		$current = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		foreach ( $attrs as $key => $value ) {
			if ( null === $value ) {
				unset( $current[ $key ] );
			} else {
				$current[ $key ] = $value;
			}
		}
		$block['attrs'] = $current;
		return $block;
	} );
}

/**
 * Apply a callback to the block at a path, rebuilding the spine.
 *
 * @param array    $blocks   Parsed blocks.
 * @param array    $path     Path.
 * @param callable $callback Receives and returns a block array.
 * @return array|\WP_Error
 */
function wpmcp_mutate_at( array $blocks, array $path, callable $callback ) {
	if ( empty( $path ) ) {
		return new \WP_Error( 'wpmcp_bad_path', 'Path must not be empty.' );
	}

	$index   = array_shift( $path );
	$visible = array();
	foreach ( $blocks as $real => $block ) {
		if ( null !== $block['blockName'] || '' !== trim( (string) ( $block['innerHTML'] ?? '' ) ) ) {
			$visible[] = $real;
		}
	}

	if ( ! isset( $visible[ $index ] ) ) {
		return new \WP_Error( 'wpmcp_path_not_found', sprintf( 'Index %d not found.', $index ) );
	}

	$real = $visible[ $index ];

	if ( empty( $path ) ) {
		$blocks[ $real ] = $callback( $blocks[ $real ] );
		return $blocks;
	}

	$children = is_array( $blocks[ $real ]['innerBlocks'] ?? null ) ? $blocks[ $real ]['innerBlocks'] : array();
	$updated  = wpmcp_mutate_at( $children, $path, $callback );
	if ( is_wp_error( $updated ) ) {
		return $updated;
	}

	$blocks[ $real ]['innerBlocks'] = $updated;

	return $blocks;
}

/**
 * Count blocks in a tree (for diff summaries).
 *
 * @param array $blocks Parsed blocks.
 * @return int
 */
function wpmcp_count_blocks( array $blocks ) {
	$count = 0;
	foreach ( $blocks as $block ) {
		if ( null === $block['blockName'] && '' === trim( (string) ( $block['innerHTML'] ?? '' ) ) ) {
			continue;
		}
		++$count;
		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			$count += wpmcp_count_blocks( $block['innerBlocks'] );
		}
	}
	return $count;
}

/**
 * Flat list of block names used in a tree (for validation and reporting).
 *
 * @param array $blocks Parsed blocks.
 * @return string[]
 */
function wpmcp_block_names( array $blocks ) {
	$names = array();
	foreach ( $blocks as $block ) {
		if ( ! empty( $block['blockName'] ) ) {
			$names[] = $block['blockName'];
		}
		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			$names = array_merge( $names, wpmcp_block_names( $block['innerBlocks'] ) );
		}
	}
	return $names;
}
