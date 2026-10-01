<?php
/**
 * A stand-in for wp_kses_post that strips what WordPress strips.
 *
 * The earlier stubs removed script and iframe elements and nothing else.
 * Against that, an <img onerror> or a javascript: link looked harmless,
 * and the tests proved a markup guard that let both straight into the
 * database. A stub that is blind to an attack cannot show a guard
 * missing it.
 *
 * So this one behaves like kses where it matters for security, and
 * leaves clean markup byte for byte alone, which is what makes "kses
 * would change nothing" a meaningful test:
 *
 * - only allowlisted elements survive; anything else loses its tags
 *   (script, style, iframe, object, embed, form, meta, base, link, svg,
 *   math, animate, set, input, ...),
 * - every on* attribute goes, wherever it sits, including after a "/"
 *   used as a separator,
 * - xlink:href, srcdoc, formaction and other unknown-risk attributes go,
 * - a URL attribute whose scheme is javascript:, vbscript: or data: goes,
 *   also when the scheme is entity-encoded (&#106; &#x6A &colon;) or
 *   broken up by tabs, newlines or control characters,
 * - a style attribute that could load or run something goes,
 * - and, like kses, it rewrites two harmless things: a self-closing slash
 *   becomes " />", and an "&" that starts no entity becomes "&amp;".
 *   Without those the tests could not see the guard refusing ordinary
 *   image markup, which is what Gutenberg saves as <img ... />.
 *
 * It is stricter than kses in places (form is allowed by WordPress, data:
 * images arguably harmless). For a security guard that errs on the right
 * side: a test passing here passes against the real thing.
 *
 * Include it before includes/content.php.
 *
 * @package wp-mcp-connector-plus
 */

if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $content ) {
		$allowed = array(
			'a', 'abbr', 'address', 'article', 'aside', 'audio', 'b', 'bdi', 'bdo', 'blockquote', 'br',
			'button', 'caption', 'cite', 'code', 'col', 'colgroup', 'dd', 'del', 'details', 'dfn', 'div',
			'dl', 'dt', 'em', 'figcaption', 'figure', 'footer', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
			'header', 'hgroup', 'hr', 'i', 'img', 'ins', 'kbd', 'label', 'li', 'main', 'mark', 'nav',
			'ol', 'p', 'picture', 'pre', 'q', 's', 'samp', 'section', 'small', 'source', 'span',
			'strong', 'sub', 'summary', 'sup', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'time',
			'tr', 'track', 'u', 'ul', 'var', 'video',
		);

		// kses normalises entities over the whole string first.
		$content = preg_replace( '/&(?!(?:[a-zA-Z][a-zA-Z0-9]*|#[0-9]+|#[xX][0-9a-fA-F]+);)/', '&amp;', (string) $content );

		return (string) preg_replace_callback(
			'#<(/?)([a-zA-Z][a-zA-Z0-9:-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>#',
			function ( $m ) use ( $allowed ) {
				if ( ! in_array( strtolower( $m[2] ), $allowed, true ) ) {
					return '';
				}
				if ( '/' === $m[1] || '' === trim( $m[3] ) ) {
					return $m[0];
				}

				// kses rebuilds a self-closing tag with " />".
				$slash = '';
				if ( preg_match( '#\s*/\s*$#', $m[3] ) ) {
					$slash = ' /';
					$m[3]  = preg_replace( '#\s*/\s*$#', '', $m[3] );
					if ( '' === trim( $m[3] ) ) {
						return '<' . $m[2] . $slash . '>';
					}
				}

				$changed = '' !== $slash;
				$attrs   = preg_replace_callback(
					'#([^\s"\'>/=]+)(\s*=\s*("[^"]*"|\'[^\']*\'|[^\s"\'>]+))?#',
					function ( $a ) use ( &$changed ) {
						if ( ! wpmcp_test_kses_attr_ok( $a[1], isset( $a[3] ) ? $a[3] : '' ) ) {
							$changed = true;
							return '';
						}
						return $a[0];
					},
					$m[3]
				);

				return $changed ? '<' . $m[2] . rtrim( $attrs ) . $slash . '>' : $m[0];
			},
			(string) $content
		);
	}

	/**
	 * Would kses keep this attribute as it is?
	 *
	 * @param string $name  Attribute name.
	 * @param string $value Raw value, quotes included.
	 * @return bool
	 */
	function wpmcp_test_kses_attr_ok( $name, $value ) {
		$name = strtolower( $name );

		if ( 0 === strpos( $name, 'on' ) || in_array( $name, array( 'xlink:href', 'srcdoc', 'formaction', 'values', 'from', 'to', 'by', 'http-equiv' ), true ) ) {
			return false;
		}

		$value = trim( $value, '"\'' );

		if ( 'style' === $name ) {
			$plain = strtolower( wpmcp_test_decode_value( $value ) );
			foreach ( array( 'url(', 'expression', 'javascript', '@import', 'behavior', '\\' ) as $bad ) {
				if ( false !== strpos( $plain, $bad ) ) {
					return false;
				}
			}
			return true;
		}

		if ( in_array( $name, array( 'href', 'src', 'action', 'cite', 'poster', 'data', 'background', 'srcset', 'longdesc', 'usemap' ), true ) ) {
			return ! preg_match( '#^(?:javascript|vbscript|data):#i', wpmcp_test_decode_value( $value ) );
		}

		return true;
	}

	/**
	 * An attribute value as the browser reads it, minus what it ignores.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	function wpmcp_test_decode_value( $value ) {
		// Numeric references, with or without the closing semicolon:
		// browsers accept both inside attributes.
		$value = preg_replace_callback( '/&#x([0-9a-f]+);?/i', function ( $m ) { return mb_chr( hexdec( $m[1] ), 'UTF-8' ); }, $value );
		$value = preg_replace_callback( '/&#([0-9]+);?/', function ( $m ) { return mb_chr( (int) $m[1], 'UTF-8' ); }, $value );
		$value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Whitespace and control characters inside a scheme are skipped.
		return (string) preg_replace( '/[\x00-\x20]+/', '', $value );
	}
}
