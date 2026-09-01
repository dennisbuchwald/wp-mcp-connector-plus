<?php
/**
 * Block discovery: the compact catalogue (always affordable to load) and
 * the detailed per-block schema (fetched only for blocks actually used).
 *
 * Everything is derived live from WP_Block_Type_Registry, so per-project
 * theme blocks and core updates are picked up without maintenance here.
 *
 * @package wp-mcp-connector-plus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Containers that accept arbitrary children.
 *
 * A closed container declares `allowedBlocks` in its block.json and is
 * detected automatically. An open container cannot express "anything" in
 * schema, and whether a block has InnerBlocks at all is not readable
 * server-side — so open containers have to be named.
 *
 * Ships with the WordPress core containers. Add your own kit's open
 * containers from your theme or plugin:
 *
 *     add_filter( 'wpmcp_open_containers', function ( $blocks ) {
 *         $blocks[] = 'acme/section';
 *         return $blocks;
 *     } );
 *
 * @return string[]
 */
function wpmcp_open_containers() {
	return apply_filters(
		'wpmcp_open_containers',
		array(
			'core/group',
			'core/columns',
			'core/column',
			'core/cover',
		)
	);
}

/**
 * Blocks not worth offering to an agent — editor plumbing, deprecated
 * blocks, anything that only makes sense through the UI.
 *
 *     add_filter( 'wpmcp_hidden_blocks', function ( $blocks ) {
 *         $blocks[] = 'acme/internal-widget';
 *         return $blocks;
 *     } );
 *
 * @return string[]
 */
function wpmcp_hidden_blocks() {
	return apply_filters( 'wpmcp_hidden_blocks', array() );
}

/**
 * Map of parent block name => child block names, inverted from every
 * block's declared `parent`. This is what makes "what goes in what"
 * answerable server-side.
 *
 * @return array<string, string[]>
 */
function wpmcp_child_map() {
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
function wpmcp_block_role( $type ) {
	if ( ! empty( $type->parent ) ) {
		return 'child';
	}
	$children = wpmcp_child_map();
	if ( isset( $children[ $type->name ] ) || ! empty( $type->allowed_blocks ) || in_array( $type->name, wpmcp_open_containers(), true ) ) {
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
function wpmcp_accepts( $type ) {
	if ( ! empty( $type->allowed_blocks ) && is_array( $type->allowed_blocks ) ) {
		return array(
			'mode'   => 'only',
			'blocks' => array_values( $type->allowed_blocks ),
		);
	}

	$children = wpmcp_child_map();
	if ( isset( $children[ $type->name ] ) ) {
		return array(
			'mode'   => 'only',
			'blocks' => array_values( array_unique( $children[ $type->name ] ) ),
		);
	}

	if ( in_array( $type->name, wpmcp_open_containers(), true ) ) {
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
 * @param string         $scope 'site' (blocks registered by this site's theme/plugins) or 'all'.
 * @return bool
 */
function wpmcp_include_block( $type, $scope ) {
	if ( in_array( $type->name, wpmcp_hidden_blocks(), true ) ) {
		return false;
	}
	if ( isset( $type->supports['inserter'] ) && false === $type->supports['inserter'] && empty( $type->parent ) ) {
		return false;
	}
	if ( 'all' === $scope ) {
		return true;
	}
	return wpmcp_is_site_block( $type->name );
}

/**
 * K1: the compact catalogue — one entry per block, cheap enough to always
 * have in context.
 *
 * @param string $scope 'site' or 'all'.
 * @return array
 */
function wpmcp_build_catalog( $scope = 'site' ) {
	$blocks = array();

	foreach ( \WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $type ) {
		if ( ! wpmcp_include_block( $type, $scope ) ) {
			continue;
		}

		$entry = array(
			'name'  => $name,
			'title' => (string) $type->title,
			'role'  => wpmcp_block_role( $type ),
		);

		$description = trim( (string) $type->description );
		if ( '' !== $description ) {
			$entry['description'] = $description;
		}

		if ( ! empty( $type->parent ) ) {
			$entry['parent'] = array_values( (array) $type->parent );
		}

		$accepts = wpmcp_accepts( $type );
		if ( 'none' !== $accepts['mode'] ) {
			$entry['accepts'] = ( 'any' === $accepts['mode'] ) ? 'any' : $accepts['blocks'];
		}

		$variants = wpmcp_key_variants( $type );
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
function wpmcp_key_variants( $type ) {
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
function wpmcp_describe_blocks( array $names ) {
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
			'role'        => wpmcp_block_role( $type ),
			'attributes'  => wpmcp_describe_attributes( $type ),
		);

		if ( ! empty( $type->parent ) ) {
			$entry['mustBeInside'] = array_values( (array) $type->parent );
		}
		if ( ! empty( $type->ancestor ) ) {
			$entry['mustHaveAncestor'] = array_values( (array) $type->ancestor );
		}

		$accepts = wpmcp_accepts( $type );
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

		$entry['example'] = wpmcp_block_example( $type, $accepts );

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
function wpmcp_describe_attributes( $type ) {
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

		$groups[ wpmcp_attribute_group( $key, $description ) ][ $key ] = $item;
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
function wpmcp_attribute_group( $key, $description ) {
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
function wpmcp_block_example( $type, array $accepts ) {
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
 * Editorial knowledge that no schema can carry: page dramaturgy, block
 * choice, tone, house rules. Shipped as a markdown file in the core and
 * optionally extended per project.
 *
 * Core first, project second — a project file adds to the house rules
 * rather than replacing them.
 *
 * @return string
 */
function wpmcp_playbook() {
	$theme = get_stylesheet_directory();

	$candidates = array(
		$theme . '/core/docs/ai-playbook.md',
		$theme . '/docs/ai-playbook.md',
	);

	$parts = array();
	foreach ( apply_filters( 'wpmcp_playbook_files', $candidates ) as $file ) {
		if ( is_readable( $file ) ) {
			$content = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- local theme file, not remote.
			if ( is_string( $content ) && '' !== trim( $content ) ) {
				$parts[] = trim( $content );
			}
		}
	}

	return implode( "\n\n---\n\n", $parts );
}

/**
 * Design tokens from theme.json: the palette a page may actually use.
 *
 * @return array
 */
function wpmcp_design_tokens() {
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
