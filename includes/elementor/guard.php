<?php
/**
 * What an Elementor change may store.
 *
 * Elementor's own answer for an account without unfiltered_html is to run
 * wp_kses_post over every string of the page on every save
 * (Document::save() -> Utils::kses_post_deep()). For the agent that would
 * be wrong twice: an edit next to a slider's script would destroy the
 * script, and an edit in one widget would strip the scripts of every
 * other widget on the page, which nobody asked for and nobody would
 * notice until the slider stopped.
 *
 * So the save runs with unfiltered_html granted for that one call
 * (wpmcp_elementor_save() in tools.php), and this file is what takes the
 * place of kses: every string the change touched is judged, untouched
 * ones are left exactly as stored. The rule is the block rule
 * (wpmcp_unstable_strings() in markup-guard.php): what kses would remove
 * may stay only if the stored page already holds it, at least as often.
 *
 * @package wp-mcp-connector-plus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Does this text start with a URL scheme that runs code?
 *
 * Read the way a browser reads an attribute: entities decoded, whitespace
 * and control characters dropped. Elementor passes link controls through
 * esc_url, but not every widget that prints a setting does, and kses only
 * looks at tags, so a bare "javascript:..." in a setting would pass it.
 *
 * @param string $value Setting value.
 * @return bool
 */
function wpmcp_elementor_runs_code_scheme( $value ) {
	$plain = preg_replace_callback( '/&#x([0-9a-f]+);?/i', function ( $m ) { return mb_chr( (int) hexdec( $m[1] ), 'UTF-8' ); }, (string) $value );
	$plain = preg_replace_callback( '/&#([0-9]+);?/', function ( $m ) { return mb_chr( (int) $m[1], 'UTF-8' ); }, (string) $plain );
	$plain = html_entity_decode( (string) $plain, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$plain = strtolower( (string) preg_replace( '/[\x00-\x20]+/', '', $plain ) );

	return (bool) preg_match( '#^(?:javascript|vbscript|data):#', $plain );
}

/**
 * Settings of an element that hold CSS, as unit keys ("a1b2c3d:custom_css").
 *
 * A CSS setting is printed into a style sheet, never into the page as
 * markup, so kses is the wrong judge for it: it read the "<svg" of an
 * SVG data URL as a tag, and the background image was refused. A setting
 * is CSS when Elementor registered it as a code control in CSS (Elementor
 * Pro's custom_css is one), and by its name, custom_css, where the
 * controls cannot be asked: Pro is what registers it, and a page saved
 * with Pro keeps it after Pro is gone.
 *
 * @param array $elements Element list.
 * @return array<string, bool>
 */
function wpmcp_elementor_css_units( array $elements ) {
	$units = array();
	foreach ( $elements as $element ) {
		if ( ! is_array( $element ) ) {
			continue;
		}
		$id       = (string) ( $element['id'] ?? '' );
		$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
		$controls = array();
		if ( ! empty( $settings ) && class_exists( '\\Elementor\\Plugin' ) && isset( \Elementor\Plugin::$instance ) && function_exists( 'wpmcp_elementor_type_of' ) ) {
			$type     = wpmcp_elementor_type_of( $element );
			$controls = $type ? (array) $type->get_controls() : array();
		}
		foreach ( $settings as $key => $value ) {
			if ( ! is_string( $value ) ) {
				continue;
			}
			$control = $controls[ $key ] ?? null;
			$is_css  = is_array( $control )
				? ( 'code' === ( $control['type'] ?? '' ) && 'css' === strtolower( (string) ( $control['language'] ?? '' ) ) )
				: false;
			if ( $is_css || 'custom_css' === (string) $key ) {
				$units[ $id . ':' . $key ] = true;
			}
		}
		if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
			$units += wpmcp_elementor_css_units( $element['elements'] );
		}
	}
	return $units;
}

/**
 * CSS with its escapes resolved and its comments removed: what the
 * browser's tokenizer works with. "\6a avascript:" is "javascript:",
 * "expr/ * * /ession(" (without the spaces) is "expression(".
 *
 * @param string $css CSS.
 * @return string
 */
function wpmcp_css_plain( $css ) {
	$css = (string) preg_replace( '#/\*.*?(\*/|$)#s', '', (string) $css );
	$css = (string) preg_replace_callback(
		'/\\\\([0-9a-fA-F]{1,6})[ \t\n\r\f]?|\\\\([^\n0-9a-fA-F])/',
		function ( $m ) {
			if ( isset( $m[2] ) && '' !== $m[2] ) {
				return $m[2];
			}
			$code = hexdec( $m[1] );
			return ( $code > 0 && $code <= 0x10FFFF ) ? (string) mb_chr( (int) $code, 'UTF-8' ) : "\u{FFFD}";
		},
		$css
	);
	return $css;
}

/**
 * What in a piece of CSS could run code, load something, or break out
 * of the style sheet, each as the fragment found.
 *
 * - The end of the surrounding <style> element: "</style" in any case,
 *   also escaped as "<\/style", and "<!--", "-->", "<script". A general
 *   "</" is not refused: an SVG data URL closes its own tags ("</svg>"),
 *   and in a style element only "</style" ends it.
 * - expression( (old Internet Explorer runs it).
 * - javascript: and vbscript:, wherever they stand.
 * - behavior: and -moz-binding (they load code into the page).
 * - @import (loads a style sheet from anywhere, and with it selectors
 *   that can read the page out).
 * - A data: URL that is not an image (image/svg+xml, png, jpeg, gif,
 *   webp, avif).
 *
 * Judged on the CSS as written and on its plain form (escapes resolved,
 * comments gone), with spaces and control characters dropped for the
 * keywords.
 *
 * @param string $css CSS.
 * @return string[] Fragments, as found.
 */
function wpmcp_css_dangers( $css ) {
	$css    = (string) $css;
	$plain  = wpmcp_css_plain( $css );
	$tight  = strtolower( (string) preg_replace( '/[\x00-\x20]+/', '', $plain ) );
	$found  = array();
	$checks = array(
		'#<\\\\?/\s*style#i' => array( $css, $plain ),
		'#<!--#'            => array( $css, $plain ),
		'#-->#'             => array( $css, $plain ),
		'#<\s*script#i'     => array( $css, $plain ),
	);
	foreach ( $checks as $pattern => $texts ) {
		foreach ( $texts as $text ) {
			if ( preg_match( $pattern, $text, $m ) ) {
				$found[] = $m[0];
				break;
			}
		}
	}

	foreach ( array( 'expression(', 'javascript:', 'vbscript:', 'behavior:', '-moz-binding', '@import' ) as $word ) {
		if ( false !== strpos( $tight, $word ) ) {
			$found[] = $word;
		}
	}

	$images = array( 'image/svg+xml', 'image/png', 'image/jpeg', 'image/jpg', 'image/gif', 'image/webp', 'image/avif' );
	if ( preg_match_all( '#data:\s*([a-z0-9.+/-]*)#i', $plain, $m ) ) {
		foreach ( $m[1] as $i => $mime ) {
			if ( ! in_array( strtolower( $mime ), $images, true ) ) {
				$found[] = $m[0][ $i ];
			}
		}
	}

	return array_values( array_unique( $found ) );
}

/**
 * Store structured data safely in every string the change touched.
 *
 * The same normalisation the block path applies (wpmcp_normalize_jsonld):
 * a "<" inside JSON-LD becomes <. Untouched strings stay as stored.
 *
 * @param array $elements     Elements after the change.
 * @param array $before_units Strings as stored (wpmcp_elementor_strings()).
 * @return array
 */
function wpmcp_elementor_normalize_jsonld( array $elements, array $before_units ) {
	foreach ( $elements as $i => $element ) {
		if ( ! is_array( $element ) ) {
			continue;
		}
		$id = (string) ( $element['id'] ?? '' );
		if ( isset( $element['settings'] ) && is_array( $element['settings'] ) ) {
			$element['settings'] = wpmcp_elementor_normalize_jsonld_in( $element['settings'], $id . ':', $before_units );
		}
		if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
			$element['elements'] = wpmcp_elementor_normalize_jsonld( $element['elements'], $before_units );
		}
		$elements[ $i ] = $element;
	}
	return $elements;
}

/**
 * Helper for wpmcp_elementor_normalize_jsonld().
 *
 * @param array  $value        Settings or part of them.
 * @param string $prefix       Unit key so far.
 * @param array  $before_units Strings as stored.
 * @return array
 */
function wpmcp_elementor_normalize_jsonld_in( array $value, $prefix, array $before_units ) {
	foreach ( $value as $key => $item ) {
		$name = ( ':' === substr( $prefix, -1 ) ) ? $prefix . $key : $prefix . '.' . $key;
		if ( is_array( $item ) ) {
			$value[ $key ] = wpmcp_elementor_normalize_jsonld_in( $item, $name, $before_units );
		} elseif ( is_string( $item ) && ( $before_units[ $name ] ?? null ) !== $item && false !== stripos( $item, 'application/ld+json' ) ) {
			$value[ $key ] = wpmcp_normalize_jsonld( $item );
		}
	}
	return $value;
}

/**
 * Judge a change: every string it touched, against the page as stored.
 *
 * - A dynamic tag (settings "__dynamic__") is not writable: it makes a
 *   setting render something else at view time (a custom field, a
 *   shortcode in Pro), which no check of the text here can see.
 * - A string holding markup must come out of kses unchanged, or keep only
 *   what the stored page already holds (wpmcp_unstable_strings()).
 * - A string without markup must not be a javascript:, vbscript: or data:
 *   URL. Other plain text goes through: kses changes nothing there but
 *   "&" into "&amp;", which in a URL or a CSS value would break it rather
 *   than protect anything.
 *
 * @param array         $before Elements as stored.
 * @param array         $after  Elements after the change.
 * @param \WP_Post|null $post   Post, for the repair filter.
 * @return array{errors: string[], warnings: string[], kept: string[], refused: array}
 */
function wpmcp_elementor_guard( array $before, array $after, $post = null ) {
	$before_units = wpmcp_elementor_strings( $before );
	$after_units  = wpmcp_elementor_strings( $after );

	// CSS is judged as CSS, never by kses (see wpmcp_css_dangers()).
	$css_units = wpmcp_elementor_css_units( $after ) + wpmcp_elementor_css_units( $before );

	$errors  = array();
	$dynamic = array();
	$schemes = array();
	$css     = array();

	foreach ( $after_units as $key => $value ) {
		if ( ( $before_units[ $key ] ?? null ) === $value ) {
			continue;
		}
		list( , $setting ) = explode( ':', $key, 2 );
		if ( '__dynamic__' === $setting || 0 === strpos( $setting, '__dynamic__.' ) ) {
			$dynamic[] = $key;
			continue;
		}
		if ( isset( $css_units[ $key ] ) ) {
			$found = wpmcp_css_dangers( $value );
			if ( ! empty( $found ) ) {
				$css[] = sprintf( '%s (%s)', $key, implode( ', ', array_map( function ( $f ) { return '"' . $f . '"'; }, $found ) ) );
			}
			continue;
		}
		if ( false === strpos( $value, '<' ) && wpmcp_elementor_runs_code_scheme( $value ) ) {
			$schemes[] = sprintf( '%s ("%s")', $key, wpmcp_shorten( $value, 60 ) );
		}
	}

	if ( ! empty( $dynamic ) ) {
		$errors[] = sprintf(
			'Dynamic tags cannot be written through the connector (%s): they make a setting show something else when the page is viewed, which no check here can see. Set the value itself, or have a person add the dynamic tag in Elementor.',
			implode( ', ', $dynamic )
		);
	}
	if ( ! empty( $schemes ) ) {
		$errors[] = sprintf(
			'A javascript:, vbscript: or data: URL cannot be written (%s). Use an http(s), mailto: or tel: link.',
			implode( ', ', $schemes )
		);
	}

	if ( ! empty( $css ) ) {
		$errors[] = sprintf(
			'This CSS could run code, load something from elsewhere or end the style sheet it is printed in: %s. Refused in CSS: "</style", "<!--", "-->", "<script", expression(), javascript: and vbscript:, behavior and -moz-binding, @import, and data: URLs other than images (image/svg+xml, png, jpeg, gif, webp, avif). CSS escapes count as what they stand for. An SVG or other image as a data: URL is fine.',
			implode( '; ', $css )
		);
	}

	// Only strings with markup are kses's business; see above. CSS is not
	// markup, whatever "<" an SVG data URL holds.
	$with_markup = function ( $units ) use ( $css_units ) {
		return array_filter(
			$units,
			function ( $value, $key ) use ( $css_units ) {
				return ! isset( $css_units[ $key ] ) && false !== strpos( (string) $value, '<' );
			},
			ARRAY_FILTER_USE_BOTH
		);
	};

	$kept    = array();
	$refused = wpmcp_unstable_strings( $with_markup( $after_units ), $with_markup( $before_units ), $kept );

	$warnings = array();
	if ( ! empty( $refused ) ) {
		if ( wpmcp_filtered_markup_allowed( $post ) ) {
			$warnings[] = sprintf(
				'This change adds markup WordPress would normally refuse from an agent account (%s). It is being written because this site opened the wpmcp_allow_filtered_markup filter. Close it again when the repair is done.',
				wpmcp_unstable_summary( $refused, 'element' )
			);
		} else {
			$errors[] = wpmcp_elementor_markup_error( $refused );
		}
	}
	if ( ! empty( $kept ) ) {
		$warnings[] = sprintf(
			'Markup WordPress would strip from an agent account stays as it was stored (%s): it was already on the page, and the change does not add to it.',
			implode( ', ', array_map( function ( $text ) { return wpmcp_shorten( $text, 80 ); }, $kept ) )
		);
	}

	return array(
		'errors'   => $errors,
		'warnings' => $warnings,
		'kept'     => $kept,
		'refused'  => $refused,
	);
}

/**
 * The refusal for markup kses would alter, in Elementor's terms.
 *
 * @param array $refused Result of wpmcp_unstable_strings().
 * @return string
 */
function wpmcp_elementor_markup_error( array $refused ) {
	return sprintf(
		'This change adds markup WordPress will not store from an agent account: %s. Scripts, event handlers (onclick, onerror ...), javascript: URLs, iframes, embeds and forms cannot be written; structured data can, as <script type="application/ld+json"> holding valid JSON. Markup of that kind already in a widget may stay when the text around it changes, as often as the page had it; a new one, a changed one or a copy may not. Remove it, or have a person add it in Elementor.',
		wpmcp_unstable_summary( $refused, 'element' )
	);
}

/**
 * Strings Elementor's own save would change for an account without
 * unfiltered_html, the whole page counted.
 *
 * Only asked when unfiltered_html cannot be granted at all
 * (DISALLOW_UNFILTERED_HTML, multisite): then Elementor runs kses over
 * every string of the page, and whatever it would change is lost, the
 * agent's part or not.
 *
 * @param array $elements Elements to save.
 * @return string[] Unit keys.
 */
function wpmcp_elementor_kses_losses( array $elements ) {
	$lost = array();
	foreach ( wpmcp_elementor_strings( $elements ) as $key => $value ) {
		$filtered = wp_kses_post( $value );
		if ( $filtered !== $value ) {
			$lost[] = $key;
		}
	}
	return $lost;
}
