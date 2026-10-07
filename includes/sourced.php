<?php
/**
 * Attributes a block reads from its markup.
 *
 * An attribute with a "source" in block.json (html, text, rich-text, raw,
 * attribute, query, children, node, tag) lives in the block's markup, not
 * in the comment delimiter: core/paragraph's content is the text inside
 * its <p>, core/image's url the src of its <img>, core/shortcode's text
 * the markup itself. The editor never writes such an attribute into the
 * comment and ignores it there, and so does every save function. Sent in
 * "attrs" without "html", it used to be written into the comment and the
 * block stored empty, after a dry run that said ok (core/shortcode on
 * staging.maxport.ch).
 *
 * Now, for a node built from the tree:
 *
 * - Such attributes never go into the comment.
 * - With "html", the markup is what counts, and the agent is told that the
 *   attribute was not stored.
 * - Without "html", a block whose whole markup is the attribute (source
 *   raw or html with no selector: core/shortcode, core/html, core/freeform)
 *   gets its markup from it, reported in markupGenerated.
 * - Otherwise the node is refused (wpmcp_sourced_attribute), naming the
 *   attributes and where in the markup they belong, with the markup to
 *   send where its shape is known.
 *
 * @package wp-mcp-connector-plus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The attributes of a block type that come from its markup.
 *
 * "meta" is a source too, but it reads post meta, not markup.
 *
 * @param string $name Block name.
 * @return array<string, array> Attribute name => definition.
 */
function wpmcp_sourced_attribute_defs( $name ) {
	$type = \WP_Block_Type_Registry::get_instance()->get_registered( (string) $name );
	if ( ! $type || ! is_array( $type->attributes ) ) {
		return array();
	}
	$out = array();
	foreach ( $type->attributes as $key => $def ) {
		if ( is_array( $def ) && isset( $def['source'] ) && 'meta' !== $def['source'] ) {
			$out[ (string) $key ] = $def;
		}
	}
	return $out;
}

/**
 * Is this attribute the block's whole markup?
 *
 * @param array $def Attribute definition.
 * @return bool
 */
function wpmcp_sourced_is_whole_markup( array $def ) {
	return in_array( $def['source'] ?? '', array( 'raw', 'html' ), true ) && empty( $def['selector'] );
}

/**
 * Take markup-sourced attributes out of a node's attrs, and generate or
 * refuse where no markup was sent.
 *
 * @param string $name     Block name.
 * @param array  $attrs    Attributes as sent.
 * @param string $html     Markup as sent.
 * @param array  $children Child blocks.
 * @param string $path     Path for messages.
 * @param object $context  See wpmcp_wrapper_context(); collects `markup`
 *                         (generated), `sourced` (refused) and `notes`.
 * @param array  $errors   Errors (by reference).
 * @return array{attrs: array, html: string}
 */
function wpmcp_resolve_sourced_attrs( $name, array $attrs, $html, array $children, $path, $context, array &$errors ) {
	$defs = wpmcp_sourced_attribute_defs( $name );
	$sent = array();
	foreach ( $defs as $key => $def ) {
		if ( array_key_exists( $key, $attrs ) ) {
			if ( null !== $attrs[ $key ] ) {
				$sent[ $key ] = $attrs[ $key ];
			}
			unset( $attrs[ $key ] );
		}
	}

	$out = array(
		'attrs' => $attrs,
		'html'  => (string) $html,
	);

	if ( empty( $sent ) ) {
		return $out;
	}

	if ( '' !== trim( (string) $html ) ) {
		$context->notes[] = sprintf(
			'%s: "%s" reads %s from its markup, so %s not stored in the block comment; the markup in "html" is what the block holds.',
			$path,
			$name,
			wpmcp_sourced_quote( array_keys( $sent ) ),
			1 === count( $sent ) ? 'it was' : 'they were'
		);
		return $out;
	}

	$keys = array_keys( $sent );
	if ( 1 === count( $sent ) && empty( $children ) && wpmcp_sourced_is_whole_markup( $defs[ $keys[0] ] ) && is_string( $sent[ $keys[0] ] ) ) {
		$out['html']       = $sent[ $keys[0] ];
		$context->markup[] = array(
			'path'      => (string) $path,
			'block'     => (string) $name,
			'attribute' => $keys[0],
		);
		return $out;
	}

	$where = array();
	foreach ( $sent as $key => $value ) {
		$where[] = wpmcp_sourced_describe( $key, $defs[ $key ] );
	}
	$example = wpmcp_sourced_markup_example( $name, array_merge( $attrs, $sent ) );

	$errors[]           = sprintf(
		'%s: "%s" reads %s from its markup, not from the block comment, so sent in "attrs" without "html" %s would be lost and the block stored empty. Send the markup as "html"%s.',
		$path,
		$name,
		implode( ', ', $where ),
		1 === count( $sent ) ? 'it' : 'they',
		null === $example ? ' (content-read an existing one for its shape)' : ', for example: ' . $example
	);
	$context->sourced[] = (string) $path;

	return $out;
}

/**
 * Quote attribute names for a message.
 *
 * @param string[] $keys Names.
 * @return string
 */
function wpmcp_sourced_quote( array $keys ) {
	return implode(
		', ',
		array_map(
			function ( $key ) {
				return '"' . $key . '"';
			},
			$keys
		)
	);
}

/**
 * Where an attribute sits in the markup, in words.
 *
 * @param string $key Attribute name.
 * @param array  $def Definition.
 * @return string
 */
function wpmcp_sourced_describe( $key, array $def ) {
	$selector = isset( $def['selector'] ) ? (string) $def['selector'] : '';
	if ( 'attribute' === ( $def['source'] ?? '' ) ) {
		return sprintf( '"%s" (the %s attribute of "%s")', $key, (string) ( $def['attribute'] ?? '' ), $selector );
	}
	if ( '' === $selector ) {
		return sprintf( '"%s" (the whole markup)', $key );
	}
	return sprintf( '"%s" (%s, inside "%s")', $key, (string) $def['source'], $selector );
}

/**
 * The markup the block editor saves for a few core blocks, as an example.
 *
 * @param string $name  Block name.
 * @param array  $attrs Attributes, sourced ones included.
 * @return string|null
 */
function wpmcp_sourced_markup_example( $name, array $attrs ) {
	$text = function ( $key ) use ( $attrs ) {
		return isset( $attrs[ $key ] ) && is_scalar( $attrs[ $key ] ) ? wpmcp_shorten( (string) $attrs[ $key ], 200 ) : '...';
	};
	$attr = function ( $key ) use ( $attrs ) {
		return isset( $attrs[ $key ] ) && is_scalar( $attrs[ $key ] ) ? htmlspecialchars( (string) $attrs[ $key ], ENT_QUOTES ) : '';
	};

	switch ( $name ) {
		case 'core/paragraph':
			return '<p>' . $text( 'content' ) . '</p>';
		case 'core/heading':
			$level = isset( $attrs['level'] ) && is_numeric( $attrs['level'] ) ? max( 1, min( 6, (int) $attrs['level'] ) ) : 2;
			return sprintf( '<h%1$d class="wp-block-heading">%2$s</h%1$d>', $level, $text( 'content' ) );
		case 'core/list-item':
			return '<li>' . $text( 'content' ) . '</li>';
		case 'core/preformatted':
			return '<pre class="wp-block-preformatted">' . $text( 'content' ) . '</pre>';
		case 'core/verse':
			return '<pre class="wp-block-verse">' . $text( 'content' ) . '</pre>';
		case 'core/code':
			return '<pre class="wp-block-code"><code>' . $text( 'content' ) . '</code></pre>';
		case 'core/image':
			$class = isset( $attrs['id'] ) && is_numeric( $attrs['id'] ) ? sprintf( ' class="wp-image-%d"', (int) $attrs['id'] ) : '';
			return sprintf( '<figure class="wp-block-image"><img src="%s" alt="%s"%s/></figure>', $attr( 'url' ), $attr( 'alt' ), $class );
		case 'core/button':
			return sprintf( '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="%s">%s</a></div>', $attr( 'url' ), $text( 'text' ) );
	}
	return null;
}

/**
 * Refuse set_attrs on attributes that live in the markup.
 *
 * @param string $name  Block name.
 * @param array  $attrs Attributes to set; null removes and is fine (it
 *                      clears a leftover from the comment).
 * @param int    $n     Operation index.
 * @return \WP_Error|null
 */
function wpmcp_sourced_set_attrs_error( $name, array $attrs, $n ) {
	$defs = wpmcp_sourced_attribute_defs( $name );
	$hit  = array();
	foreach ( $attrs as $key => $value ) {
		if ( null !== $value && isset( $defs[ $key ] ) ) {
			$hit[] = wpmcp_sourced_describe( (string) $key, $defs[ $key ] );
		}
	}
	if ( empty( $hit ) ) {
		return null;
	}
	return new \WP_Error(
		'wpmcp_sourced_attribute',
		sprintf(
			'Operation %d (set_attrs): "%s" reads %s from its markup, not from the block comment; set there, nothing would change. Change the markup with patch_html, or replace the block with its "html".',
			$n,
			(string) $name,
			implode( ', ', $hit )
		)
	);
}
