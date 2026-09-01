<?php
/**
 * Block discovery: the compact catalogue (always affordable to load) and
 * the detailed per-block schema (fetched only for blocks actually used).
 *
 * Everything is derived live from WP_Block_Type_Registry, so per-project
 * theme blocks and core updates are picked up without maintenance here.
 *
 * @package dbw-connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Containers that accept arbitrary children. Closed containers declare
 * `allowedBlocks` in their block.json and need no entry here; this list
 * only covers the open ones, which cannot express "anything" in schema.
 *
 * Extend via the filter when a project adds an open container block.
 *
 * @return string[]
 */
function dbw_connector_open_containers() {
	return apply_filters(
		'dbw_connector_open_containers',
		array(
			'dbw-base/section',
			'dbw-base/popup',
			'dbw-base/scroll-scale-section',
			'core/group',
			'core/columns',
			'core/column',
		)
	);
}

/**
 * Block names never worth offering to the AI (editor plumbing, deprecated).
 *
 * @return string[]
 */
function dbw_connector_hidden_blocks() {
	return apply_filters( 'dbw_connector_hidden_blocks', array( 'dbw-base/career-quiz' ) );
}

/**
 * Map of parent block name => child block names, inverted from every
 * block's declared `parent`. This is what makes "what goes in what"
 * answerable server-side.
 *
 * @return array<string, string[]>
 */
function dbw_connector_child_map() {
	static $map = null;
	if ( null !== $map ) {
		return $map;
	}

	$map = array();
	foreach ( \WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $type ) {
		if ( empty( $type->parent ) || ! is_array( $type->parent ) ) {
			continue;
		}
		foreach ( $type->parent as $parent ) {
			$map[ $parent ][] = $name;
		}
	}

	return $map;
}

/**
 * Classify a block: container, child or standalone.
 *
 * @param \WP_Block_Type $type Block type.
 * @return string
 */
function dbw_connector_block_role( $type ) {
	if ( ! empty( $type->parent ) ) {
		return 'child';
	}
	$children = dbw_connector_child_map();
	if ( isset( $children[ $type->name ] ) || ! empty( $type->allowed_blocks ) || in_array( $type->name, dbw_connector_open_containers(), true ) ) {
		return 'container';
	}
	return 'standalone';
}

/**
 * Which blocks may go inside this one.
 *
 * @param \WP_Block_Type $type Block type.
 * @return array { mode: string, blocks: string[] }
 */
function dbw_connector_accepts( $type ) {
	if ( ! empty( $type->allowed_blocks ) && is_array( $type->allowed_blocks ) ) {
		return array(
			'mode'   => 'only',
			'blocks' => array_values( $type->allowed_blocks ),
		);
	}

	$children = dbw_connector_child_map();
	if ( isset( $children[ $type->name ] ) ) {
		return array(
			'mode'   => 'only',
			'blocks' => array_values( array_unique( $children[ $type->name ] ) ),
		);
	}

	if ( in_array( $type->name, dbw_connector_open_containers(), true ) ) {
		return array(
			'mode'   => 'any',
			'blocks' => array(),
		);
	}

	return array(
		'mode'   => 'none',
		'blocks' => array(),
	);
}

/**
 * Should this block appear in the catalogue at all?
 *
 * @param \WP_Block_Type $type  Block type.
 * @param string         $scope 'dbw' (own blocks) or 'all'.
 * @return bool
 */
function dbw_connector_include_block( $type, $scope ) {
	if ( in_array( $type->name, dbw_connector_hidden_blocks(), true ) ) {
		return false;
	}
	if ( isset( $type->supports['inserter'] ) && false === $type->supports['inserter'] && empty( $type->parent ) ) {
		return false;
	}
	if ( 'all' === $scope ) {
		return true;
	}
	return dbw_connector_is_dbw_block( $type->name );
}

/**
 * K1: the compact catalogue — one entry per block, cheap enough to always
 * have in context.
 *
 * @param string $scope 'dbw' or 'all'.
 * @return array
 */
function dbw_connector_build_catalog( $scope = 'dbw' ) {
	$blocks = array();

	foreach ( \WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $type ) {
		if ( ! dbw_connector_include_block( $type, $scope ) ) {
			continue;
		}

		$entry = array(
			'name'  => $name,
			'title' => (string) $type->title,
			'role'  => dbw_connector_block_role( $type ),
		);

		$description = trim( (string) $type->description );
		if ( '' !== $description ) {
			$entry['description'] = $description;
		}

		if ( ! empty( $type->parent ) ) {
			$entry['parent'] = array_values( (array) $type->parent );
		}

		$accepts = dbw_connector_accepts( $type );
		if ( 'none' !== $accepts['mode'] ) {
			$entry['accepts'] = ( 'any' === $accepts['mode'] ) ? 'any' : $accepts['blocks'];
		}

		$variants = dbw_connector_key_variants( $type );
		if ( ! empty( $variants ) ) {
			$entry['variants'] = $variants;
		}

		$blocks[] = $entry;
	}

	usort(
		$blocks,
		function ( $a, $b ) {
			return strcmp( $a['name'], $b['name'] );
		}
	);

	return $blocks;
}

/**
 * The two or three enum attributes that most define a block's look, so the
 * catalogue hints at variety without carrying full schemas.
 *
 * @param \WP_Block_Type $type Block type.
 * @return array<string, string[]>
 */
function dbw_connector_key_variants( $type ) {
	if ( ! is_array( $type->attributes ) ) {
		return array();
	}

	$priority = array( 'variant', 'style', 'layout', 'displayStyle', 'cardStyle', 'iconStyle', 'markerStyle', 'imagePosition', 'columns' );
	$out      = array();

	foreach ( $priority as $key ) {
		if ( isset( $type->attributes[ $key ]['enum'] ) && is_array( $type->attributes[ $key ]['enum'] ) ) {
			$out[ $key ] = array_values( array_filter( $type->attributes[ $key ]['enum'], 'strlen' ) );
		}
		if ( count( $out ) >= 2 ) {
			break;
		}
	}

	return $out;
}

/**
 * K2: full detail for named blocks — attributes with descriptions, grouped,
 * deprecated values flagged, nesting rules spelled out.
 *
 * @param string[] $names Block names.
 * @return array
 */
function dbw_connector_describe_blocks( array $names ) {
	$registry = \WP_Block_Type_Registry::get_instance();
	$out      = array();

	foreach ( $names as $name ) {
		$type = $registry->get_registered( $name );
		if ( ! $type ) {
			$out[] = array(
				'name'  => $name,
				'error' => 'Not registered on this site.',
			);
			continue;
		}

		$entry = array(
			'name'        => $name,
			'title'       => (string) $type->title,
			'description' => (string) $type->description,
			'role'        => dbw_connector_block_role( $type ),
			'attributes'  => dbw_connector_describe_attributes( $type ),
		);

		if ( ! empty( $type->parent ) ) {
			$entry['mustBeInside'] = array_values( (array) $type->parent );
		}
		if ( ! empty( $type->ancestor ) ) {
			$entry['mustHaveAncestor'] = array_values( (array) $type->ancestor );
		}

		$accepts = dbw_connector_accepts( $type );
		if ( 'any' === $accepts['mode'] ) {
			$entry['accepts'] = 'any';
		} elseif ( 'only' === $accepts['mode'] ) {
			$entry['accepts'] = $accepts['blocks'];
		}

		if ( ! empty( $type->supports ) && is_array( $type->supports ) ) {
			$supports = array();
			foreach ( array( 'anchor', 'align' ) as $key ) {
				if ( isset( $type->supports[ $key ] ) ) {
					$supports[ $key ] = $type->supports[ $key ];
				}
			}
			if ( ! empty( $supports ) ) {
				$entry['supports'] = $supports;
			}
		}

		$entry['example'] = dbw_connector_block_example( $type, $accepts );

		$out[] = $entry;
	}

	return $out;
}

/**
 * Attribute detail, split into groups so a 77-attribute block stays readable.
 *
 * @param \WP_Block_Type $type Block type.
 * @return array
 */
function dbw_connector_describe_attributes( $type ) {
	if ( ! is_array( $type->attributes ) ) {
		return array();
	}

	$groups = array(
		'content'  => array(),
		'layout'   => array(),
		'behavior' => array(),
		'legacy'   => array(),
	);

	foreach ( $type->attributes as $key => $def ) {
		$description = (string) ( $def['description'] ?? '' );

		$item = array( 'type' => $def['type'] ?? 'string' );

		if ( '' !== $description ) {
			$item['description'] = $description;
		}
		if ( array_key_exists( 'default', $def ) ) {
			$item['default'] = $def['default'];
		}
		if ( isset( $def['enum'] ) && is_array( $def['enum'] ) ) {
			$deprecated = isset( $def['deprecatedEnum'] ) && is_array( $def['deprecatedEnum'] ) ? $def['deprecatedEnum'] : array();
			$current    = array_values( array_diff( $def['enum'], $deprecated ) );
			$item['enum'] = $current;
			if ( ! empty( $deprecated ) ) {
				$item['deprecatedValues'] = array_values( $deprecated );
			}
		}
		if ( isset( $def['items']['properties'] ) && is_array( $def['items']['properties'] ) ) {
			$props = array();
			foreach ( $def['items']['properties'] as $pkey => $pdef ) {
				$props[ $pkey ] = $pdef['type'] ?? 'string';
			}
			$item['itemProperties'] = $props;
		}

		$groups[ dbw_connector_attribute_group( $key, $description ) ][ $key ] = $item;
	}

	return array_filter(
		$groups,
		function ( $group ) {
			return ! empty( $group );
		}
	);
}

/**
 * Bucket an attribute by name and description.
 *
 * @param string $key         Attribute name.
 * @param string $description Attribute description.
 * @return string
 */
function dbw_connector_attribute_group( $key, $description ) {
	if ( 0 === stripos( $description, 'legacy' ) ) {
		return 'legacy';
	}

	if ( preg_match( '/^(show|enable|is|has|auto|animate|animation|speed|duration|delay|loop|pause|scroll|sticky|lazy|open|collapse|toggle)/i', $key ) ) {
		return 'behavior';
	}

	if ( preg_match( '/(padding|margin|gap|columns|width|height|align|layout|position|background|color|radius|size|spacing|variant|style|ratio|order|reverse|invert)/i', $key ) ) {
		return 'layout';
	}

	return 'content';
}

/**
 * A minimal, valid usage example for a block — the fastest way for a model
 * to get the shape right.
 *
 * @param \WP_Block_Type $type    Block type.
 * @param array          $accepts Accept rules.
 * @return array
 */
function dbw_connector_block_example( $type, array $accepts ) {
	$attrs = array();

	if ( is_array( $type->attributes ) ) {
		foreach ( array( 'heading', 'sectionHeading', 'title', 'text' ) as $key ) {
			if ( isset( $type->attributes[ $key ] ) ) {
				$attrs[ $key ] = 'Beispieltext';
				break;
			}
		}
	}

	$example = array(
		'name'  => $type->name,
		'attrs' => (object) $attrs,
	);

	if ( 'only' === $accepts['mode'] && ! empty( $accepts['blocks'] ) ) {
		$example['innerBlocks'] = array(
			array(
				'name'  => $accepts['blocks'][0],
				'attrs' => (object) array(),
			),
		);
	} elseif ( 'any' === $accepts['mode'] ) {
		$example['innerBlocks'] = array();
	}

	return $example;
}

/**
 * Design tokens from theme.json: the palette a page may actually use.
 *
 * @return array
 */
function dbw_connector_design_tokens() {
	if ( ! function_exists( 'wp_get_global_settings' ) ) {
		return array();
	}

	$settings = wp_get_global_settings();

	$colors = array();
	foreach ( (array) ( $settings['color']['palette']['theme'] ?? array() ) as $color ) {
		if ( isset( $color['slug'] ) ) {
			$colors[] = array(
				'slug'  => $color['slug'],
				'name'  => $color['name'] ?? $color['slug'],
				'color' => $color['color'] ?? '',
			);
		}
	}

	$font_sizes = array();
	foreach ( (array) ( $settings['typography']['fontSizes']['theme'] ?? array() ) as $size ) {
		if ( isset( $size['slug'] ) ) {
			$font_sizes[] = $size['slug'];
		}
	}

	$spacing = array();
	foreach ( (array) ( $settings['spacing']['spacingSizes']['theme'] ?? array() ) as $size ) {
		if ( isset( $size['slug'] ) ) {
			$spacing[] = $size['slug'];
		}
	}

	return array(
		'colors'           => $colors,
		'fontSizes'        => $font_sizes,
		'spacingSizes'     => $spacing,
		'customColors'     => (bool) ( $settings['color']['custom'] ?? true ),
		'customFontSizes'  => (bool) ( $settings['typography']['customFontSize'] ?? true ),
		'contentSize'      => (string) ( $settings['layout']['contentSize'] ?? '' ),
		'wideSize'         => (string) ( $settings['layout']['wideSize'] ?? '' ),
	);
}
