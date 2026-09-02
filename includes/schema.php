<?php
/**
 * Attribute validation against the block.json definitions held by the
 * WP_Block_Type_Registry. Deliberately strict: unknown attribute, wrong
 * type or enum violation is an error — hallucination protection.
 *
 * @package wp-mcp-connector-plus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validate one block's attrs against its registered definition.
 *
 * @param string $block_name Registered block name.
 * @param array  $attrs      Attributes to validate.
 * @param string $path       Human-readable tree path for messages.
 * @return array { errors: string[], warnings: string[] }
 */
function wpmcp_validate_attrs( $block_name, array $attrs, $path ) {
	$errors   = array();
	$warnings = array();

	$type = \WP_Block_Type_Registry::get_instance()->get_registered( $block_name );
	$defs = ( $type && is_array( $type->attributes ) ) ? $type->attributes : array();

	foreach ( $attrs as $key => $value ) {
		// Editor-managed universal attributes.
		if ( in_array( $key, array( 'anchor', 'align', 'className', 'lock', 'metadata' ), true ) ) {
			continue;
		}

		if ( ! isset( $defs[ $key ] ) ) {
			// Core blocks register many attributes dynamically; only enforce this for site kit blocks.
			if ( wpmcp_is_site_block( $block_name ) ) {
				$errors[] = sprintf( '%s: unknown attribute "%s" on %s.', $path, $key, $block_name );
			}
			continue;
		}

		$def = $defs[ $key ];

		$type_error = wpmcp_check_type( $value, $def['type'] ?? null );
		if ( $type_error ) {
			$errors[] = sprintf( '%s: attribute "%s" %s.', $path, $key, $type_error );
			continue;
		}

		if ( isset( $def['enum'] ) && is_array( $def['enum'] ) && ! in_array( $value, $def['enum'], true ) ) {
			$errors[] = sprintf(
				'%s: attribute "%s" value %s not in enum [%s].',
				$path,
				$key,
				wp_json_encode( $value ),
				implode( ', ', array_map( 'strval', $def['enum'] ) )
			);
			continue;
		}

		// Custom core-side marker for legacy enum values kept only for stored content.
		if ( isset( $def['deprecatedEnum'] ) && is_array( $def['deprecatedEnum'] ) && in_array( $value, $def['deprecatedEnum'], true ) ) {
			$warnings[] = sprintf(
				'%s: attribute "%s" uses deprecated value %s — valid for existing content, do not use for new content.',
				$path,
				$key,
				wp_json_encode( $value )
			);
		}

		// Shallow item validation for arrays of objects (e.g. buttons[]).
		if ( 'array' === ( $def['type'] ?? '' ) && isset( $def['items']['properties'] ) && is_array( $value ) ) {
			$props = $def['items']['properties'];
			foreach ( $value as $i => $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				foreach ( $item as $ikey => $ival ) {
					if ( ! isset( $props[ $ikey ] ) ) {
						$warnings[] = sprintf( '%s: "%s[%d].%s" is not a declared item property.', $path, $key, $i, $ikey );
						continue;
					}
					$ierr = wpmcp_check_type( $ival, $props[ $ikey ]['type'] ?? null );
					if ( $ierr ) {
						$errors[] = sprintf( '%s: "%s[%d].%s" %s.', $path, $key, $i, $ikey, $ierr );
					}
				}
			}
		}
	}

	// Several block libraries (GenerateBlocks, Kadence, Stackable) key their
	// generated CSS to a per-instance id. When it is missing the editor
	// creates one on open, which marks an untouched page as unsaved and, for
	// styled blocks, drops the styling that was keyed to the absent id.
	foreach ( array( 'uniqueId', 'uniqueID' ) as $id_attr ) {
		if ( isset( $defs[ $id_attr ] ) && empty( $attrs[ $id_attr ] ) ) {
			$warnings[] = sprintf(
				'%s: "%s" declares "%s" and none was given. The editor will generate one when the page is opened, marking it as changed without anyone editing it, and any CSS keyed to that id is lost. Copy the shape of an existing instance of this block, including the id, the matching class in the markup and the generated css attribute.',
				$path,
				$block_name,
				$id_attr
			);
		}
	}

	return array(
		'errors'   => $errors,
		'warnings' => $warnings,
	);
}

/**
 * Check a value against a block.json type declaration.
 *
 * @param mixed             $value Value.
 * @param string|array|null $type  Declared type(s).
 * @return string|null Error fragment or null if OK.
 */
function wpmcp_check_type( $value, $type ) {
	if ( null === $type ) {
		return null;
	}
	$types = (array) $type;
	foreach ( $types as $t ) {
		switch ( $t ) {
			case 'string':
				if ( is_string( $value ) ) {
					return null;
				}
				break;
			case 'number':
				if ( is_int( $value ) || is_float( $value ) ) {
					return null;
				}
				break;
			case 'integer':
				if ( is_int( $value ) ) {
					return null;
				}
				break;
			case 'boolean':
				if ( is_bool( $value ) ) {
					return null;
				}
				break;
			case 'array':
				if ( is_array( $value ) && ( array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 ) ) ) {
					return null;
				}
				break;
			case 'object':
				if ( is_array( $value ) || is_object( $value ) ) {
					return null;
				}
				break;
			case 'null':
				if ( null === $value ) {
					return null;
				}
				break;
		}
	}
	return sprintf( 'must be of type %s, got %s', implode( '|', $types ), gettype( $value ) );
}

/**
 * Is this a block from the site kit (theme or plugin), rather than a WordPress core block?
 *
 * @param string $block_name Block name.
 * @return bool
 */
function wpmcp_is_site_block( $block_name ) {
	return 0 !== strpos( $block_name, 'core/' ) && false !== strpos( $block_name, '/' );
}
