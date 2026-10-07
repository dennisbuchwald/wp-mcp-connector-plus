<?php
/**
 * What an element says, short enough to read a whole page at once.
 *
 * On the first customer site every section is one HTML widget holding the
 * whole section as raw markup: headings, texts, cards, links, images and
 * sometimes a script for a slider. The element tree alone says nothing
 * about that ("html, html, html"), and the markup in full is tens of
 * kilobytes per page. So each HTML widget comes with a text outline: its
 * headings with their level, the first words of each paragraph, links,
 * images with their alt text, and whether it carries scripts or styles.
 * Enough to plan an SEO rework; the markup itself is one call away.
 *
 * No DOM extension: it is not on every host, and the outline is a summary,
 * not a parser anybody relies on for a decision. Changes are made against
 * the verbatim markup (patch_html), never against this.
 *
 * @package wp-mcp-connector-plus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Text of a piece of markup: tags out, entities decoded, spaces collapsed.
 *
 * @param string $html Markup.
 * @return string
 */
function wpmcp_elementor_text( $html ) {
	$text = html_entity_decode( strip_tags( (string) $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

	return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
}

/**
 * One attribute of a tag, decoded, or null.
 *
 * @param string $tag  Opening tag.
 * @param string $name Attribute name.
 * @return string|null
 */
function wpmcp_elementor_attr( $tag, $name ) {
	if ( ! preg_match( '/[\s\/]' . preg_quote( $name, '/' ) . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', (string) $tag, $m ) ) {
		return null;
	}
	$value = $m[1] ?? '';
	if ( '' === $value && isset( $m[2] ) && '' !== $m[2] ) {
		$value = $m[2];
	}
	if ( '' === $value && isset( $m[3] ) ) {
		$value = $m[3];
	}

	return html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
}

/**
 * The outline of an HTML widget's markup.
 *
 * @param string $html Markup.
 * @return array
 */
function wpmcp_elementor_html_outline( $html ) {
	$html  = (string) $html;
	$limit = array(
		'headings'   => 40,
		'paragraphs' => 20,
		'links'      => 30,
		'images'     => 30,
	);

	$outline = array(
		'bytes'      => strlen( $html ),
		'headings'   => array(),
		'paragraphs' => array(),
		'links'      => array(),
		'images'     => array(),
	);

	foreach ( array( 'script', 'style', 'iframe', 'form' ) as $tag ) {
		$count = preg_match_all( '#<' . $tag . '\b#i', $html );
		if ( $count ) {
			$outline[ $tag . 's' ] = $count;
		}
	}
	if ( preg_match_all( '#<script\s+type\s*=\s*["\']application/ld\+json["\']#i', $html ) ) {
		$outline['jsonLd'] = preg_match_all( '#<script\s+type\s*=\s*["\']application/ld\+json["\']#i', $html );
	}

	// What a visitor reads: no code, no comments.
	$visible = (string) preg_replace( array( '#<script\b.*?</script\s*>#is', '#<style\b.*?</style\s*>#is', '#<!--.*?-->#s' ), ' ', $html );

	$found = array(
		'headings'   => 0,
		'paragraphs' => 0,
		'links'      => 0,
		'images'     => 0,
	);

	if ( preg_match_all( '#<h([1-6])\b[^>]*>(.*?)</h\1\s*>#is', $visible, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $heading ) {
			if ( ++$found['headings'] <= $limit['headings'] ) {
				$outline['headings'][] = array(
					'level' => (int) $heading[1],
					'text'  => wpmcp_shorten( wpmcp_elementor_text( $heading[2] ), 120 ),
				);
			}
		}
	}

	if ( preg_match_all( '#<p\b[^>]*>(.*?)</p\s*>#is', $visible, $m ) ) {
		foreach ( $m[1] as $paragraph ) {
			$text = wpmcp_elementor_text( $paragraph );
			if ( '' === $text ) {
				continue;
			}
			if ( ++$found['paragraphs'] <= $limit['paragraphs'] ) {
				$outline['paragraphs'][] = wpmcp_shorten( $text, 80 );
			}
		}
	}

	if ( preg_match_all( '#(<a\b[^>]*>)(.*?)</a\s*>#is', $visible, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $link ) {
			if ( ++$found['links'] <= $limit['links'] ) {
				$outline['links'][] = array(
					'href' => (string) wpmcp_elementor_attr( $link[1], 'href' ),
					'text' => wpmcp_shorten( wpmcp_elementor_text( $link[2] ), 60 ),
				);
			}
		}
	}

	if ( preg_match_all( '#<img\b[^>]*>#i', $visible, $m ) ) {
		foreach ( $m[0] as $img ) {
			if ( ++$found['images'] <= $limit['images'] ) {
				$alt                 = wpmcp_elementor_attr( $img, 'alt' );
				$outline['images'][] = array(
					'src' => (string) wpmcp_elementor_attr( $img, 'src' ),
					// null: no alt attribute at all, which is not the same
					// as an empty one (decorative).
					'alt' => $alt,
				);
			}
		}
	}

	foreach ( $found as $key => $count ) {
		if ( 0 === $count ) {
			unset( $outline[ $key ] );
		} elseif ( $count > $limit[ $key ] ) {
			$outline['more'][ $key ] = $count - $limit[ $key ];
		}
	}

	return $outline;
}

/**
 * A short reading of a widget that is not an HTML widget.
 *
 * Generic on purpose: the text a widget shows sits in a handful of
 * setting names across Elementor's own widgets (heading: title, text
 * editor: editor, button: text ...). elementor-read with an element_id
 * gives every setting.
 *
 * @param array $settings Settings.
 * @return array
 */
function wpmcp_elementor_widget_summary( array $settings ) {
	$summary = array();

	foreach ( array( 'title', 'editor', 'text', 'title_text', 'description_text', 'caption' ) as $key ) {
		if ( isset( $settings[ $key ] ) && is_string( $settings[ $key ] ) && '' !== trim( $settings[ $key ] ) ) {
			$summary[ $key ] = wpmcp_shorten( wpmcp_elementor_text( $settings[ $key ] ), 120 );
		}
	}
	if ( isset( $settings['header_size'] ) && is_string( $settings['header_size'] ) ) {
		$summary['headerSize'] = $settings['header_size'];
	}
	if ( isset( $settings['link']['url'] ) && is_string( $settings['link']['url'] ) && '' !== $settings['link']['url'] ) {
		$summary['link'] = $settings['link']['url'];
	}
	if ( isset( $settings['image']['url'] ) && is_string( $settings['image']['url'] ) && '' !== $settings['image']['url'] ) {
		$summary['image'] = array_filter(
			array(
				'url' => $settings['image']['url'],
				'id'  => isset( $settings['image']['id'] ) ? (int) $settings['image']['id'] : null,
				'alt' => isset( $settings['image']['alt'] ) && is_string( $settings['image']['alt'] ) ? $settings['image']['alt'] : null,
			),
			function ( $value ) {
				return null !== $value;
			}
		);
	}

	return $summary;
}

/**
 * The page as an outline: every element with id, type and path, and what
 * it says.
 *
 * @param array $elements Element list.
 * @param int[] $prefix   Path of the list's parent.
 * @return array
 */
function wpmcp_elementor_outline( array $elements, array $prefix = array() ) {
	$out = array();

	foreach ( array_values( $elements ) as $i => $element ) {
		if ( ! is_array( $element ) ) {
			continue;
		}
		$path     = array_merge( $prefix, array( $i ) );
		$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();

		$node = array(
			'id'     => (string) ( $element['id'] ?? '' ),
			'path'   => wpmcp_elementor_path_string( $path ),
			'elType' => (string) ( $element['elType'] ?? '' ),
		);
		if ( isset( $element['widgetType'] ) ) {
			$node['widgetType'] = (string) $element['widgetType'];
		}
		if ( wpmcp_elementor_is_atomic( $element ) ) {
			$node['atomic'] = true;
		}
		// The name a person gave the element in Elementor's navigator.
		if ( isset( $settings['_title'] ) && is_string( $settings['_title'] ) && '' !== $settings['_title'] ) {
			$node['title'] = $settings['_title'];
		}

		if ( 'html' === ( $element['widgetType'] ?? '' ) ) {
			$node['html'] = wpmcp_elementor_html_outline( is_string( $settings['html'] ?? null ) ? $settings['html'] : '' );
		} elseif ( 'widget' === ( $element['elType'] ?? '' ) ) {
			$summary = wpmcp_elementor_widget_summary( $settings );
			if ( ! empty( $summary ) ) {
				$node['summary'] = $summary;
			}
		}
		if ( ! empty( $settings['__dynamic__'] ) ) {
			$node['dynamic'] = array_keys( (array) $settings['__dynamic__'] );
		}

		if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
			$node['elements'] = wpmcp_elementor_outline( $element['elements'], $path );
		}

		$out[] = $node;
	}

	return $out;
}
