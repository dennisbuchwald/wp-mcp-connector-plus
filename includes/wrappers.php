<?php
/**
 * Container wrappers: what to do with a node that has children but no
 * markup of its own.
 *
 * A block's saved form is its children interleaved with whatever markup
 * its save function writes around them. The tree carries that markup as
 * "htmlTemplate". A node sent without it used to be serialized as its
 * children alone, which is right for a block that renders on the server
 * and saves only InnerBlocks.Content (the dbw-base kit, core/navigation),
 * and silent data loss for every block whose wrapper sits in the post
 * content: core/group, core/columns, core/buttons, GenerateBlocks 2's
 * element. The dry run said ok, the page lost its sections and cards,
 * and the only way to notice was to count classes on the live page.
 *
 * The decision is made from evidence, cheapest and most certain first:
 *
 * 0. The same block, children only, is already stored on this page: it
 *    is being kept, not written, and stays as it is.
 * 1. The connector knows how the block editor saves this block
 *    (core/group, generateblocks/element) and the attributes say all
 *    there is to say: the wrapper is generated and reported. When the
 *    attributes hold something that changes the wrapper in a way not
 *    reproduced here, the write is refused instead.
 * 2. A saved instance of the block type with children, on this page or
 *    on a published one, shows whether the saved form has markup around
 *    the children.
 * 3. No instance anywhere: a block with a render callback is taken to
 *    save its children only (said in a warning, never silently); one
 *    without is saved by WordPress as static markup, which for a block
 *    with children means a wrapper, and is refused.
 *
 * A render callback alone proves nothing. GenerateBlocks 2.4.1 registers
 * one for its element block that only adds CSS to the saved markup, and
 * core/cover, core/list and core/media-text have one too: all of them
 * keep their wrapper in the post content. That is why it is the last
 * resort, after the instances.
 *
 * @package wp-mcp-connector-plus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything the wrapper check may consult while one write is built.
 *
 * An object, so the nested calls that build a tree add to the same
 * report without threading references through every signature.
 *
 * @param array $stored  Blocks of the post as stored (parse_blocks()).
 * @param int   $post_id Post being written, 0 for a new one.
 * @return object
 */
function wpmcp_wrapper_context( array $stored = array(), $post_id = 0 ) {
	return (object) array(
		'stored'    => $stored,
		'post_id'   => (int) $post_id,
		'known'     => null,
		'instances' => array(),
		'generated' => array(),
		'missing'   => array(),
		'notes'     => array(),
		// Markup generated from, and nodes refused for, attributes that
		// live in the markup (includes/sourced.php).
		'markup'    => array(),
		'sourced'   => array(),
	);
}

/**
 * Decide the innerContent template for a node sent without html or htmlTemplate.
 *
 * @param string $name     Block name.
 * @param array  $attrs    Attributes as sent.
 * @param array  $children Child blocks, already built.
 * @param string $path     Path for messages.
 * @param object $context  See wpmcp_wrapper_context().
 * @param array  $errors   Errors (by reference).
 * @return array|null A template, or null for children only.
 */
function wpmcp_resolve_wrapper( $name, array $attrs, array $children, $path, $context, array &$errors ) {
	$count = count( $children );
	$type  = \WP_Block_Type_Registry::get_instance()->get_registered( $name );

	// An unregistered block is refused by stage 1 of the validation; a
	// second message about its wrapper would only bury that one.
	if ( ! $type ) {
		return null;
	}

	// 0. Kept, not written.
	$plain = array(
		'blockName'    => $name,
		'attrs'        => wpmcp_restore_object_attrs( $name, $attrs ),
		'innerBlocks'  => $children,
		'innerHTML'    => '',
		'innerContent' => array_fill( 0, $count, null ),
	);
	if ( isset( wpmcp_wrapper_known_markup( $context )[ serialize_block( $plain ) ] ) ) {
		return null;
	}

	// 1. Generated from the attributes.
	$generated = wpmcp_generate_wrapper( $name, $attrs );
	if ( isset( $generated['open'] ) ) {
		$template             = wpmcp_wrapper_template( $generated['open'], $generated['close'], $count );
		$context->generated[] = array(
			'path'     => $path,
			'block'    => $name,
			'template' => $template,
		);
		return $template;
	}

	$evidence = wpmcp_wrapper_evidence( $name, $context );

	if ( null !== $generated ) {
		$why = sprintf( 'the connector generates it from attributes only when they say everything about it, and here %s', $generated['reason'] );
	} elseif ( $evidence && ! $evidence['wrapper'] ) {
		// 2. An instance saved with nothing around its children.
		if ( empty( $type->render_callback ) ) {
			$context->notes[] = sprintf(
				'%s: "%s" was sent without "htmlTemplate" and is saved as its children only, like the one in %s. WordPress saves this block type statically, so if that one lost its wrapper, send the wrapper as "htmlTemplate".',
				$path,
				$name,
				$evidence['source']
			);
		}
		return null;
	} elseif ( $evidence ) {
		$why = sprintf( 'the one in %s has markup around its children', $evidence['source'] );
	} elseif ( ! empty( $type->render_callback ) ) {
		// 3. Nothing to go by but the render callback.
		$context->notes[] = sprintf(
			'%s: "%s" was sent without "htmlTemplate" and is saved as its children only. It renders on the server and no saved instance on this site shows markup around its children, so that is taken to be its saved form. If this block keeps a wrapper element in the post content, send it as "htmlTemplate".',
			$path,
			$name
		);
		return null;
	} else {
		$why = 'WordPress saves this block type statically (it has no render callback), and a static block with children keeps its wrapper in the saved markup';
	}

	$suggested            = wpmcp_wrapper_suggestion( $name, $attrs, $count, $evidence, $type );
	$context->missing[]   = array(
		'path'      => $path,
		'block'     => $name,
		'suggested' => $suggested,
	);
	$errors[] = sprintf(
		'%s: "%s" has innerBlocks but neither "html" nor "htmlTemplate", and its wrapper element is part of the saved markup (%s). Without it only the children would be stored and the wrapper with its classes would be lost. Send "htmlTemplate": %s. The pattern is ["\n<tag class=\"...\">", null, "\n\n", null, "</tag>\n"]: the opening tag, one null per child with "\n\n" between them, the closing tag; check the classes against an instance from content-read. A block that really saves nothing but its children takes a template of nulls only.',
		$path,
		$name,
		$why,
		wp_json_encode( $suggested, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
	);

	return null;
}

/**
 * The stored blocks of the post, by their exact markup.
 *
 * @param object $context See wpmcp_wrapper_context().
 * @return array<string, true>
 */
function wpmcp_wrapper_known_markup( $context ) {
	if ( null === $context->known ) {
		$context->known = array();
		wpmcp_wrapper_collect_markup( $context->stored, $context->known );
	}
	return $context->known;
}

/**
 * Remember every block with a name, nested ones included.
 *
 * @param array $blocks Parsed blocks.
 * @param array $known  Markup => true (by reference).
 */
function wpmcp_wrapper_collect_markup( array $blocks, array &$known ) {
	foreach ( $blocks as $block ) {
		if ( empty( $block['blockName'] ) ) {
			continue;
		}
		$known[ serialize_block( $block ) ] = true;
		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			wpmcp_wrapper_collect_markup( $block['innerBlocks'], $known );
		}
	}
}

/**
 * Build the innerContent template for a wrapper.
 *
 * The layout the block editor writes: a line break before the opening
 * tag and after the closing one, children back to back with an empty
 * line between them.
 *
 * @param string $open  Opening tag.
 * @param string $close Closing tag.
 * @param int    $count Number of children.
 * @return array
 */
function wpmcp_wrapper_template( $open, $close, $count ) {
	$template = array( "\n" . $open );
	for ( $i = 0; $i < $count; $i++ ) {
		if ( $i > 0 ) {
			$template[] = "\n\n";
		}
		$template[] = null;
	}
	$template[] = $close . "\n";

	return $template;
}

/**
 * The wrapper the block editor would save, for the blocks the connector
 * knows how to reproduce exactly.
 *
 * Each generator was checked against the block editor's own serializer
 * (WordPress 6.9.8's block-library and blocks packages, and the
 * GenerateBlocks 2.4.1 editor script, run headless): tests/wrappers.php
 * holds what the editor wrote for each case.
 *
 * @param string $name  Block name.
 * @param array  $attrs Attributes.
 * @return array|null { open, close } when generated, { reason } when the
 *                    block is known but these attributes are not
 *                    reproduced, null when the block is not known here.
 */
function wpmcp_generate_wrapper( $name, array $attrs ) {
	switch ( $name ) {
		case 'core/group':
			return wpmcp_generate_group_wrapper( $attrs );
		case 'generateblocks/element':
			return wpmcp_generate_gb_element_wrapper( $attrs );
	}

	return null;
}

/**
 * core/group as WordPress 6.9 saves it.
 *
 * The save is `<Tag { ...useInnerBlocksProps.save( useBlockProps.save() ) } />`:
 * the generated class "wp-block-group", then "align*" from the align
 * support, then the custom class, and the anchor as id. Layout adds
 * nothing to the saved markup (its classes are added when rendering), and
 * neither do metadata, lock, allowedBlocks or templateLock. Colours,
 * borders, spacing, typography, an aria-label and the like do, each in
 * its own way: those are not reproduced, and the write is refused with a
 * template to fill in instead.
 *
 * @param array $attrs Attributes.
 * @return array
 */
function wpmcp_generate_group_wrapper( array $attrs ) {
	$neutral = array( 'tagName', 'className', 'layout', 'align', 'anchor', 'metadata', 'lock', 'allowedBlocks', 'templateLock' );
	$other   = array_diff( array_keys( $attrs ), $neutral );
	if ( ! empty( $other ) ) {
		return array( 'reason' => sprintf( '"%s" also changes the saved wrapper', implode( '", "', $other ) ) );
	}

	$tag = $attrs['tagName'] ?? 'div';
	if ( ! in_array( $tag, array( 'div', 'header', 'main', 'section', 'article', 'aside', 'footer' ), true ) ) {
		return array( 'reason' => sprintf( 'tagName "%s" is not one the block editor offers', is_scalar( $tag ) ? (string) $tag : gettype( $tag ) ) );
	}

	$align = $attrs['align'] ?? '';
	if ( '' !== $align && ! in_array( $align, array( 'wide', 'full' ), true ) ) {
		return array( 'reason' => sprintf( 'align "%s" is not wide or full', is_scalar( $align ) ? (string) $align : gettype( $align ) ) );
	}

	$class_name = $attrs['className'] ?? '';
	$anchor     = $attrs['anchor'] ?? '';
	if ( ! is_string( $class_name ) || ! is_string( $anchor ) ) {
		return array( 'reason' => 'className and anchor must be strings' );
	}

	$class = 'wp-block-group' . ( '' !== $align ? ' align' . $align : '' ) . ( '' !== $class_name ? ' ' . $class_name : '' );
	$open  = '<' . $tag . ' class="' . wpmcp_editor_escape_attribute( $class ) . '"';
	if ( '' !== $anchor ) {
		$open .= ' id="' . wpmcp_editor_escape_attribute( $anchor ) . '"';
	}

	return array(
		'open'  => $open . '>',
		'close' => '</' . $tag . '>',
	);
}

/**
 * generateblocks/element as GenerateBlocks 2.4.1 saves it.
 *
 * GenerateBlocks registers a render callback for it, but the callback only
 * adds the block's CSS: the element itself is saved markup, from this
 * save function in dist/blocks/element/index.js:
 *
 *     const classNames = [ ...globalClasses ];
 *     if ( Object.keys( styles ).length ) classNames.push( `gb-element-${ uniqueId }` );
 *     const blockProps = useBlockProps.save( { className: classNames.join( ' ' ).trim(), ...htmlAttributes } );
 *     return <TagName { ...useInnerBlocksProps.save( blockProps ) } />;
 *
 * The block has supports.className false, so there is no generated
 * "wp-block-*" class; the custom className is appended by the editor.
 * Attributes follow the class in their own order, escaped the way the
 * element serializer escapes them. Without tagName the save writes no
 * element at all, which is never what a container is sent for.
 *
 * Only the attribute names in the list below are reproduced (plus data-*
 * and aria-*): the serializer renames some (SVG ones), writes others bare
 * (boolean ones), and the connector does not repeat that table.
 *
 * @param array $attrs Attributes.
 * @return array
 */
function wpmcp_generate_gb_element_wrapper( array $attrs ) {
	if ( ! defined( 'GENERATEBLOCKS_VERSION' ) || 0 !== strpos( (string) GENERATEBLOCKS_VERSION, '2.' ) ) {
		return array( 'reason' => 'the save function is known for GenerateBlocks 2 only' );
	}

	$neutral = array( 'uniqueId', 'tagName', 'styles', 'css', 'globalClasses', 'htmlAttributes', 'className', 'align', 'metadata', 'lock' );
	$other   = array_diff( array_keys( $attrs ), $neutral );
	if ( ! empty( $other ) ) {
		return array( 'reason' => sprintf( '"%s" is not an attribute of this block', implode( '", "', $other ) ) );
	}

	$tags = array( 'div', 'section', 'article', 'aside', 'header', 'footer', 'nav', 'main', 'figure', 'a', 'ul', 'ol', 'li', 'dl', 'dt', 'dd' );
	$tag  = $attrs['tagName'] ?? '';
	if ( ! is_string( $tag ) || ! in_array( $tag, $tags, true ) ) {
		return array( 'reason' => '' === $tag ? 'tagName is missing, and without it GenerateBlocks saves no element at all' : 'tagName is not one GenerateBlocks offers' );
	}

	$styles    = $attrs['styles'] ?? array();
	$styles    = is_object( $styles ) ? (array) $styles : $styles;
	$unique_id = $attrs['uniqueId'] ?? '';
	if ( ! is_array( $styles ) || ! is_string( $unique_id ) ) {
		return array( 'reason' => 'styles must be an object and uniqueId a string' );
	}
	if ( ! empty( $styles ) && '' === $unique_id ) {
		return array( 'reason' => 'styles are set without a uniqueId, and the class "gb-element-<uniqueId>" they need cannot be made up here' );
	}

	$global = $attrs['globalClasses'] ?? array();
	if ( ! is_array( $global ) || count( array_filter( $global, 'is_string' ) ) !== count( $global ) ) {
		return array( 'reason' => 'globalClasses must be a list of strings' );
	}

	$class_name = $attrs['className'] ?? '';
	if ( ! is_string( $class_name ) ) {
		return array( 'reason' => 'className must be a string' );
	}

	$html_attrs = $attrs['htmlAttributes'] ?? array();
	$html_attrs = is_object( $html_attrs ) ? (array) $html_attrs : $html_attrs;
	if ( ! is_array( $html_attrs ) ) {
		return array( 'reason' => 'htmlAttributes must be an object' );
	}

	$names = array( 'id', 'href', 'target', 'rel', 'title', 'role', 'style', 'lang', 'tabindex', 'itemprop', 'itemtype', 'itemid', 'hreflang' );
	foreach ( $html_attrs as $key => $value ) {
		$key = (string) $key;
		if ( ! in_array( $key, $names, true ) && ! preg_match( '/^(data|aria)-[a-z0-9-]+$/', $key ) ) {
			return array( 'reason' => sprintf( 'the html attribute "%s" is not one reproduced here', $key ) );
		}
		if ( ! is_string( $value ) && ! is_int( $value ) ) {
			return array( 'reason' => sprintf( 'the html attribute "%s" is not a string', $key ) );
		}
	}

	// classNames.join( ' ' ).trim(), then clsx() with the custom class.
	$list = $global;
	if ( ! empty( $styles ) ) {
		$list[] = 'gb-element-' . $unique_id;
	}
	$class = trim( implode( ' ', $list ) );
	if ( '' !== $class_name ) {
		$class = '' === $class ? $class_name : $class . ' ' . $class_name;
	}

	$open = '<' . $tag;
	if ( '' !== $class ) {
		$open .= ' class="' . wpmcp_editor_escape_attribute( $class ) . '"';
	}
	foreach ( $html_attrs as $key => $value ) {
		$open .= ' ' . $key . '="' . ( is_int( $value ) ? (string) $value : wpmcp_editor_escape_attribute( $value ) ) . '"';
	}

	return array(
		'open'  => $open . '>',
		'close' => '</' . $tag . '>',
	);
}

/**
 * Escape an attribute value exactly as the block editor's serializer does
 * (escapeAttribute in @wordpress/escape-html): an "&" that does not start
 * a character reference, then double quotes, then ">". A single quote
 * and "<" stay as they are.
 *
 * @param string $value Value.
 * @return string
 */
function wpmcp_editor_escape_attribute( $value ) {
	$value = (string) preg_replace( '/&(?!([a-z0-9]+|#[0-9]+|#x[a-f0-9]+);)/i', '&amp;', (string) $value );

	return str_replace( array( '"', '>' ), array( '&quot;', '&gt;' ), $value );
}

/**
 * What a saved instance of the block type says about its wrapper.
 *
 * The post being written first, then at most three published posts.
 * Published only: the suggestion quotes the instance's opening tag, and
 * nothing of a draft or a private page should travel into an answer that
 * way. Asked once per block type and write.
 *
 * @param string $name    Block name.
 * @param object $context See wpmcp_wrapper_context().
 * @return array|null { wrapper: bool, source: string, innerContent: array, className: string }
 */
function wpmcp_wrapper_evidence( $name, $context ) {
	if ( array_key_exists( $name, $context->instances ) ) {
		return $context->instances[ $name ];
	}

	$found  = wpmcp_find_container_instance( $context->stored, $name, '' );
	$source = null === $found ? '' : sprintf( 'this page at %s', $found['path'] );

	if ( null === $found ) {
		foreach ( wpmcp_wrapper_instance_posts( $name, $context->post_id ) as $post ) {
			$found = wpmcp_find_container_instance( parse_blocks( (string) $post->post_content ), $name, '' );
			if ( null !== $found ) {
				$source = sprintf( 'post %d at %s', (int) $post->ID, $found['path'] );
				break;
			}
		}
	}

	$evidence = null;
	if ( null !== $found ) {
		$wrapper = false;
		foreach ( (array) ( $found['block']['innerContent'] ?? array() ) as $chunk ) {
			if ( is_string( $chunk ) && '' !== trim( $chunk ) ) {
				$wrapper = true;
				break;
			}
		}
		$evidence = array(
			'wrapper'      => $wrapper,
			'source'       => $source,
			'innerContent' => array_values( (array) $found['block']['innerContent'] ),
			'className'    => is_string( $found['block']['attrs']['className'] ?? null ) ? $found['block']['attrs']['className'] : '',
		);
	}

	$context->instances[ $name ] = $evidence;

	return $evidence;
}

/**
 * The first block of a type that has children, depth first.
 *
 * @param array  $blocks Parsed blocks.
 * @param string $name   Block name.
 * @param string $prefix Path prefix.
 * @return array|null { block, path }
 */
function wpmcp_find_container_instance( array $blocks, $name, $prefix ) {
	$index = 0;
	foreach ( $blocks as $block ) {
		if ( ! wpmcp_is_visible_block( $block ) ) {
			continue;
		}
		$path = '' === $prefix ? (string) $index : $prefix . '.' . $index;
		++$index;

		$children = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array();
		if ( empty( $children ) ) {
			continue;
		}
		if ( $name === $block['blockName'] ) {
			return array(
				'block' => $block,
				'path'  => $path,
			);
		}
		$found = wpmcp_find_container_instance( $children, $name, $path );
		if ( null !== $found ) {
			return $found;
		}
	}

	return null;
}

/**
 * Published posts that hold the block type somewhere, at most three.
 *
 * Block comments name core blocks without their namespace. The space
 * after the name is part of the needle: "<!-- wp:group " matches
 * "<!-- wp:group -->" and "<!-- wp:group {" but not "<!-- wp:group-x".
 *
 * @param string $name    Block name.
 * @param int    $exclude Post already looked at.
 * @return object[] Rows with ID and post_content.
 */
function wpmcp_wrapper_instance_posts( $name, $exclude ) {
	global $wpdb;

	if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) ) {
		return array();
	}

	$needle = '<!-- wp:' . strip_core_block_namespace( $name ) . ' ';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- content search, no core API for this.
	return (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT ID, post_content FROM {$wpdb->posts}
			 WHERE post_status = 'publish'
			   AND post_type != 'revision'
			   AND ID != %d
			   AND post_content LIKE %s
			 ORDER BY ID DESC
			 LIMIT 3",
			(int) $exclude,
			'%' . $wpdb->esc_like( $needle ) . '%'
		)
	);
}

/**
 * The htmlTemplate to propose when the wrapper is missing.
 *
 * From a saved instance when there is one, with its custom class swapped
 * for the node's; otherwise the minimum the block editor writes: the tag
 * (tagName, else div), the generated "wp-block-*" class unless the block
 * turns it off, and the node's className.
 *
 * @param string         $name     Block name.
 * @param array          $attrs    Attributes as sent.
 * @param int            $count    Number of children.
 * @param array|null     $evidence See wpmcp_wrapper_evidence().
 * @param \WP_Block_Type $type     Block type.
 * @return array
 */
function wpmcp_wrapper_suggestion( $name, array $attrs, $count, $evidence, $type ) {
	$class_name = is_string( $attrs['className'] ?? null ) ? $attrs['className'] : '';

	if ( $evidence && $evidence['wrapper'] ) {
		$chunks = $evidence['innerContent'];
		$first  = array_search( null, $chunks, true );
		$open   = false === $first ? '' : trim( implode( '', array_filter( array_slice( $chunks, 0, $first ), 'is_string' ) ) );
		$last   = false === $first ? '' : trim( (string) end( $chunks ) );

		if ( '' !== $open && preg_match( '#^<[a-zA-Z]#', $open ) ) {
			$open = wpmcp_swap_custom_class( $open, $evidence['className'], $class_name );
			return wpmcp_wrapper_template( $open, $last, $count );
		}
	}

	$tag = is_string( $attrs['tagName'] ?? null ) && preg_match( '/^[a-z][a-z0-9]*$/', $attrs['tagName'] ) ? $attrs['tagName'] : 'div';

	$classes = array();
	if ( false !== ( $type->supports['className'] ?? true ) ) {
		$classes[] = 'wp-block-' . str_replace( '/', '-', strip_core_block_namespace( $name ) );
	}
	if ( '' !== $class_name ) {
		$classes[] = $class_name;
	}

	$open = '<' . $tag . ( empty( $classes ) ? '' : ' class="' . wpmcp_editor_escape_attribute( implode( ' ', $classes ) ) . '"' ) . '>';

	return wpmcp_wrapper_template( $open, '</' . $tag . '>', $count );
}

/**
 * Put the node's custom class where the instance had its own.
 *
 * @param string $open     Opening tag of the instance.
 * @param string $previous The instance's className.
 * @param string $wanted   The node's className.
 * @return string
 */
function wpmcp_swap_custom_class( $open, $previous, $wanted ) {
	return (string) preg_replace_callback(
		'#\sclass="([^"]*)"#',
		function ( $m ) use ( $previous, $wanted ) {
			$classes = preg_split( '/\s+/', trim( $m[1] ) );
			if ( '' !== $previous ) {
				$classes = array_diff( $classes, preg_split( '/\s+/', trim( $previous ) ) );
			}
			if ( '' !== $wanted ) {
				$classes[] = $wanted;
			}
			return ' class="' . trim( implode( ' ', $classes ) ) . '"';
		},
		$open,
		1
	);
}
