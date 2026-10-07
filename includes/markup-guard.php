<?php
/**
 * Saving markup without kses mangling it, and without letting anything
 * unsafe through.
 *
 * kses is skipped only for blocks that are byte-identical to what was
 * already stored (or known from a trusted revision); every changed block
 * must survive wp_kses_post() unchanged. JSON-LD gets its own narrow
 * exception. The unfiltered_html grant for dynamic data is per save and
 * never lands on the role.
 *
 * @package wp-mcp-connector-plus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Would saving this content lose anything, and if so, was it already there?
 *
 * The agent account deliberately has no `unfiltered_html`, so WordPress
 * runs its content through wp_kses_post on save. That is right for
 * anything the agent writes. It is wrong for content that was already
 * stored: editing one block of a page would quietly destroy a JSON-LD
 * script in another, which is not the agent's doing and not the site
 * owner's intention.
 *
 * Until 0.18.3 "introduces" only knew whole elements: script, style,
 * iframe, form, object, embed. Everything else kses removes - an onerror
 * on an image, a javascript: link, an svg with onload - left it false,
 * and false meant the save went past the filter. That was stored XSS at
 * the lowest access level. The whole-element comparison is still made,
 * for the labels it gives, but the deciding question is now asked of
 * kses itself, block by block: see wpmcp_unstable_blocks().
 *
 * @param string   $before     Content currently stored.
 * @param string   $after      Content about to be written.
 * @param string[] $also_known Further content whose blocks count as
 *                             already stored, e.g. the revision a restore
 *                             brings back.
 * @return array {
 *     @type bool     $alters     Whether the save would change the content.
 *     @type bool     $introduces Whether the change adds filtered markup.
 *     @type string[] $affected   Which constructs are involved.
 *     @type string[] $added      Whole elements that are new.
 *     @type array    $blocks     New or changed blocks kses would alter,
 *                                see wpmcp_unstable_blocks().
 * }
 */
function wpmcp_kses_impact( $before, $after, array $also_known = array() ) {
	$filtered = function_exists( 'wp_kses_post' ) ? wp_kses_post( $after ) : $after;

	$in_before = wpmcp_filtered_fragments( (string) $before );
	$in_after  = wpmcp_filtered_fragments( (string) $after );

	foreach ( $also_known as $known ) {
		foreach ( wpmcp_filtered_fragments( (string) $known ) as $fragment => $count ) {
			$in_before[ $fragment ] = max( $in_before[ $fragment ] ?? 0, $count );
		}
	}

	// Compare the fragments themselves, not how many there are. Counting
	// let a swap through: remove the page's JSON-LD block and add a script
	// of the agent's own in the same save, and the total never moved.
	$affected = array();
	$added    = array();

	foreach ( $in_after as $fragment => $count ) {
		$affected[] = wpmcp_fragment_label( $fragment );
		if ( $count > ( $in_before[ $fragment ] ?? 0 ) ) {
			$added[] = wpmcp_fragment_label( $fragment );
		}
	}

	$kept     = array();
	$unstable = ( $filtered !== $after )
		? wpmcp_unstable_blocks( (string) $after, array_merge( array( (string) $before ), $also_known ), $kept )
		: array();

	// Fragments a changed block keeps from the stored page are named with
	// the rest of what is preserved, in their own words.
	foreach ( $kept as $text ) {
		$affected[] = $text;
	}

	return array(
		'alters'     => ( $filtered !== $after ),
		'introduces' => ! empty( $added ) || ! empty( $unstable ),
		'affected'   => array_values( array_unique( $affected ) ),
		'added'      => array_values( array_unique( $added ) ),
		'blocks'     => $unstable,
	);
}

/**
 * The blocks of a write that the agent wrote and kses would alter.
 *
 * This is the whole rule for when a save may go past WordPress's content
 * filter, and it has two halves.
 *
 * A block that is byte-identical to a block already stored - at any depth,
 * compared by its serialized markup - is not the agent's doing. It keeps
 * its markup, whatever kses would think of it: that is what stops an edit
 * in one block from destroying the JSON-LD or the video embed in another.
 *
 * Every other block is the agent's, and it must come out of wp_kses_post
 * exactly as it went in, with one exception (since 0.19.2): what kses
 * would remove from it may stay if it was already stored. Then skipping
 * the filter adds nothing to the page that was not there, and the save
 * can go past the filter for the sake of the blocks that need it.
 * Anything else kses would remove or rewrite is refused, and named by
 * its path and in its own words. Asking kses itself, rather than keeping
 * a list of what it removes, is the point: every list so far had holes,
 * and kses is by definition what WordPress would have done.
 *
 * The exception exists because a block is edited, not rewritten: a
 * heading with a GenerateBlocks highlight (a style kses strips, see
 * tests/wp-real MarkupGuardTest) could not have one word changed, because
 * the highlight came back with the change. It is decided by
 * wpmcp_kses_removals(), which takes the block apart into the fragments
 * kses objects to, and is accepted only when
 *
 * - those fragments are all kses objects to: with them taken out, kses
 *   leaves the rest alone (otherwise the block is refused as before), and
 * - each of them occurs in the stored page at least as often as in the
 *   whole page after the change, counted as a multiset. A fragment moved
 *   stays, a fragment copied to a second place is refused, as is one that
 *   differs by a single character.
 *
 * A changed container is judged by its own markup only (the opening and
 * closing wrapper, its comment delimiter); its children are judged on
 * their own, so an untouched video inside a group survives a change to
 * the group's class.
 *
 * The one deliberate exception is structured data: a valid JSON-LD block
 * is taken out before the comparison, as everywhere else in the plugin.
 *
 * @param string   $after   Content about to be written.
 * @param string[] $sources Content whose blocks count as already stored;
 *                          the first is the post as stored now.
 * @param string[] $kept    Receives the fragments changed blocks keep
 *                          from the stored page (by reference).
 * @return array<int, array{path: string, constructs: string[], stored: string, fragments: array}>
 */
function wpmcp_unstable_blocks( $after, array $sources, &$kept = null ) {
	$kept = array();

	if ( ! function_exists( 'wp_kses_post' ) ) {
		return array();
	}

	$known = array();
	foreach ( $sources as $source ) {
		if ( '' !== (string) $source ) {
			wpmcp_collect_known_markup( parse_blocks( (string) $source ), $known );
		}
	}

	$after_blocks = parse_blocks( (string) $after );

	$found = array();
	wpmcp_find_unstable_blocks( $after_blocks, $known, '', $found );

	if ( empty( $found ) ) {
		return $found;
	}

	// What the stored page holds of what kses removes, and what the page
	// would hold after the change, every block counted, untouched ones too.
	$budget = array();
	foreach ( $sources as $source ) {
		$counts = wpmcp_count_kses_removals( parse_blocks( (string) $source ) );
		foreach ( $counts as $key => $count ) {
			$budget[ $key ] = max( $budget[ $key ] ?? 0, $count );
		}
	}
	$usage = wpmcp_count_kses_removals( $after_blocks );

	$stored_blocks = '' === (string) ( $sources[0] ?? '' ) ? array() : parse_blocks( (string) $sources[0] );

	$refused = array();
	foreach ( $found as $block ) {
		$ok = $block['explained'] && ! empty( $block['fragments'] );
		foreach ( $block['fragments'] as $key => &$fragment ) {
			$fragment['before'] = $budget[ $key ] ?? 0;
			$fragment['after']  = $usage[ $key ] ?? 0;
			$fragment['inThis'] = wpmcp_fragment_in_stored_block( $stored_blocks, $block['path'], $block['name'], $key );
			if ( $fragment['after'] > $fragment['before'] ) {
				$ok = false;
			}
		}
		unset( $fragment );

		if ( $ok ) {
			foreach ( $block['fragments'] as $fragment ) {
				$kept[] = $fragment['text'];
			}
			continue;
		}
		$refused[] = $block;
	}

	$kept = array_values( array_unique( $kept ) );

	return $refused;
}

/**
 * The same rule as wpmcp_unstable_blocks(), for markup that is not a block.
 *
 * Elementor keeps a page as a tree of elements whose settings are
 * strings, and an HTML widget's "html" setting is a whole piece of raw
 * markup. Elementor runs kses over every one of those strings for an
 * account without unfiltered_html, the whole page on every save, so an
 * edit next to a slider script would destroy the script. The answer is
 * the one blocks get: skip the filter for the save, and in its place
 * judge every string the change touched.
 *
 * A unit is one string, keyed by where it sits ("a1b2c3d:html"). One that
 * is unchanged at its key is not the agent's doing and is not judged. A
 * changed or new one must come out of kses unchanged, or may keep what
 * kses would remove only if the stored page already holds each such
 * fragment at least as often as the page will after the change, every
 * unit counted. So an existing script stays when the text around it is
 * edited, and is refused when copied into a second widget, changed by a
 * character, or written new. Safe JSON-LD is taken out first, as for
 * blocks.
 *
 * Identity is by key, not by text anywhere on the page: a duplicated
 * widget gets new ids, so its copy of a script is a second script and is
 * counted as one.
 *
 * @param array<string, string> $after  Units after the change, key => markup.
 * @param array<string, string> $before Units as stored, key => markup.
 * @param string[]              $kept   Receives the fragments changed units
 *                                      keep from the stored page (by reference).
 * @return array<int, array{path: string, constructs: string[], stored: string, fragments: array, explained: bool}>
 */
function wpmcp_unstable_strings( array $after, array $before, &$kept = null ) {
	$kept = array();

	if ( ! function_exists( 'wp_kses_post' ) ) {
		return array();
	}

	$found = array();
	foreach ( $after as $key => $html ) {
		$html = (string) $html;
		if ( isset( $before[ $key ] ) && (string) $before[ $key ] === $html ) {
			continue;
		}

		$checked  = wpmcp_strip_safe_jsonld( $html );
		$filtered = wp_kses_post( $checked );
		if ( $filtered === $checked || $filtered === wpmcp_kses_equivalent( $checked ) ) {
			continue;
		}

		$removals = wpmcp_kses_removals( $checked );
		$found[]  = array(
			'path'       => (string) $key,
			'constructs' => wpmcp_kses_losses( $checked, $filtered ),
			'stored'     => $filtered,
			'fragments'  => $removals['fragments'],
			'explained'  => $removals['explained'],
		);
	}

	if ( empty( $found ) ) {
		return $found;
	}

	$budget = wpmcp_count_kses_removals_in( $before );
	$usage  = wpmcp_count_kses_removals_in( $after );

	$refused = array();
	foreach ( $found as $unit ) {
		$ok        = $unit['explained'] && ! empty( $unit['fragments'] );
		$was_there = isset( $before[ $unit['path'] ] )
			? wpmcp_kses_removals( wpmcp_strip_safe_jsonld( (string) $before[ $unit['path'] ] ) )['fragments']
			: array();

		foreach ( $unit['fragments'] as $fragment_key => &$fragment ) {
			$fragment['before'] = $budget[ $fragment_key ] ?? 0;
			$fragment['after']  = $usage[ $fragment_key ] ?? 0;
			$fragment['inThis'] = isset( $was_there[ $fragment_key ] );
			if ( $fragment['after'] > $fragment['before'] ) {
				$ok = false;
			}
		}
		unset( $fragment );

		if ( $ok ) {
			foreach ( $unit['fragments'] as $fragment ) {
				$kept[] = $fragment['text'];
			}
			continue;
		}
		$refused[] = $unit;
	}

	$kept = array_values( array_unique( $kept ) );

	return $refused;
}

/**
 * How often each fragment kses would remove occurs in a set of strings.
 *
 * @param array<string, string> $units Key => markup.
 * @return array<string, int>
 */
function wpmcp_count_kses_removals_in( array $units ) {
	$counts = array();

	foreach ( $units as $html ) {
		$html = wpmcp_strip_safe_jsonld( (string) $html );
		if ( '' === trim( $html ) || wpmcp_kses_stable( $html ) ) {
			continue;
		}
		foreach ( wpmcp_kses_removals( $html )['fragments'] as $key => $fragment ) {
			$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + $fragment['count'];
		}
	}

	return $counts;
}

/**
 * The markup of one block without its children: the delimiter with its
 * attributes and the wrapper chunks in order, safe JSON-LD taken out.
 *
 * Safe JSON-LD comes out of the inner markup before the delimiter goes
 * around it. kses parses and reserializes block markup
 * (filter_block_content), and a block left empty by the strip comes back
 * in the void form "<!-- wp:html /-->", which read as a change: every
 * Custom HTML block holding structured data, the usual place for it, was
 * refused.
 *
 * @param array $block Parsed block.
 * @return string
 */
function wpmcp_block_own_markup( array $block ) {
	if ( null === $block['blockName'] ) {
		return wpmcp_strip_safe_jsonld( (string) ( $block['innerHTML'] ?? '' ) );
	}

	return wpmcp_strip_safe_jsonld(
		get_comment_delimited_block_content(
			$block['blockName'],
			is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array(),
			wpmcp_strip_safe_jsonld( wpmcp_inner_html_from_content( (array) ( $block['innerContent'] ?? array() ) ) )
		)
	);
}

/**
 * Is this markup what kses would store, give or take the rewrites that
 * change nothing (see wpmcp_kses_equivalent())?
 *
 * @param string $html Markup.
 * @return bool
 */
function wpmcp_kses_stable( $html ) {
	$filtered = wp_kses_post( $html );

	return $filtered === $html || $filtered === wpmcp_kses_equivalent( $html );
}

/**
 * Take a piece of markup apart into what kses would remove from it.
 *
 * Three kinds of fragment, each identified by its exact text:
 *
 * - script, style and iframe as whole elements, content included: their
 *   content is what they do, so a changed one is a different one;
 * - the tag of any other element kses does not allow at all (an svg,
 *   a meta), attributes included;
 * - an attribute kses removes or changes on an element it allows
 *   (onerror="...", a style it cannot vouch for, a javascript: href),
 *   keyed with its element, so the same handler on another element is
 *   another fragment.
 *
 * Each tag and each attribute is asked of kses on its own. Whether that
 * found everything is then asked of kses as well: with the fragments taken
 * out, the rest has to come back unchanged ("explained"). If it does not,
 * kses objects to something these pieces do not capture, and the caller
 * treats the block as before 0.19.2.
 *
 * @param string $html Markup, as wpmcp_block_own_markup() gives it.
 * @return array { fragments: array<string, array{text: string, count: int}>, explained: bool }
 */
function wpmcp_kses_removals( $html ) {
	$fragments = array();
	$add       = function ( $key, $text ) use ( &$fragments ) {
		if ( ! isset( $fragments[ $key ] ) ) {
			$fragments[ $key ] = array(
				'text'  => $text,
				'count' => 0,
			);
		}
		++$fragments[ $key ]['count'];
	};

	$rest = (string) preg_replace_callback(
		'#<(script|style|iframe)\b[^>]*>.*?</\1\s*>#is',
		function ( $m ) use ( $add ) {
			if ( wpmcp_kses_stable( $m[0] ) ) {
				return $m[0];
			}
			$add( $m[0], $m[0] );
			return '';
		},
		(string) $html
	);

	$rest = (string) preg_replace_callback(
		'#<(/?)([a-zA-Z][a-zA-Z0-9:-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>#',
		function ( $tag ) use ( $add ) {
			if ( wpmcp_kses_stable( $tag[0] ) ) {
				return $tag[0];
			}

			$name = strtolower( $tag[2] );

			// The element itself is not allowed: the whole tag goes.
			if ( '/' === $tag[1] || '' === wp_kses_post( '<' . $tag[2] . '>' ) ) {
				$add( $tag[0], $tag[0] );
				return '';
			}

			$attrs = (string) preg_replace_callback(
				'#[\s/]+([^\s"\'>/=]+)(?:\s*=\s*("[^"]*"|\'[^\']*\'|[^\s"\'>]+))?#',
				function ( $attr ) use ( $add, $tag, $name ) {
					$text = ltrim( $attr[0], " \t\n\r\f/" );
					if ( wpmcp_kses_stable( '<' . $tag[2] . ' ' . $text . '>' ) ) {
						return $attr[0];
					}
					$add( '<' . $name . ' ' . $text, $text );
					return '';
				},
				$tag[3]
			);

			return '<' . $tag[2] . $attrs . '>';
		},
		$rest
	);

	return array(
		'fragments' => $fragments,
		'explained' => wpmcp_kses_stable( $rest ),
	);
}

/**
 * How often each fragment kses would remove occurs in a block list, every
 * block counted by its own markup.
 *
 * @param array $blocks Parsed blocks.
 * @param array $counts Running counts, for recursion.
 * @return array<string, int>
 */
function wpmcp_count_kses_removals( array $blocks, array $counts = array() ) {
	foreach ( $blocks as $block ) {
		if ( null === $block['blockName'] && '' === trim( (string) ( $block['innerHTML'] ?? '' ) ) ) {
			continue;
		}

		$own = wpmcp_block_own_markup( $block );
		if ( ! wpmcp_kses_stable( $own ) ) {
			foreach ( wpmcp_kses_removals( $own )['fragments'] as $key => $fragment ) {
				$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + $fragment['count'];
			}
		}

		if ( null !== $block['blockName'] && ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			$counts = wpmcp_count_kses_removals( $block['innerBlocks'], $counts );
		}
	}

	return $counts;
}

/**
 * Was this fragment in the block at the same path before the change?
 *
 * For the message only: "already in this block" tells the agent it passed
 * through something that was there, rather than writing something new.
 *
 * @param array  $stored Parsed blocks of the stored post.
 * @param string $path   Path of the changed block.
 * @param string $name   Its block name, null for freeform.
 * @param string $key    Fragment key.
 * @return bool
 */
function wpmcp_fragment_in_stored_block( array $stored, $path, $name, $key ) {
	$segments = wpmcp_path_parse( $path );
	$block    = ( null === $segments || empty( $stored ) ) ? null : wpmcp_blocks_at_path( $stored, $segments );
	if ( null === $block || $block['blockName'] !== $name ) {
		return false;
	}

	return isset( wpmcp_kses_removals( wpmcp_block_own_markup( $block ) )['fragments'][ $key ] );
}

/**
 * Remember every block of stored content by its exact markup.
 *
 * @param array $blocks Parsed blocks.
 * @param array $known  Markup => true (by reference).
 */
function wpmcp_collect_known_markup( array $blocks, array &$known ) {
	foreach ( $blocks as $block ) {
		if ( null === $block['blockName'] ) {
			$known[ (string) ( $block['innerHTML'] ?? '' ) ] = true;
			continue;
		}

		$known[ serialize_block( $block ) ] = true;

		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			wpmcp_collect_known_markup( $block['innerBlocks'], $known );
		}
	}
}

/**
 * Walk the blocks to be written and collect the ones kses would alter.
 *
 * Paths are counted the way content-read counts them: whitespace between
 * blocks is no block, freeform HTML is one.
 *
 * @param array  $blocks Parsed blocks.
 * @param array  $known  Markup already stored.
 * @param string $prefix Path prefix, for recursion.
 * @param array  $found  Result (by reference).
 */
function wpmcp_find_unstable_blocks( array $blocks, array $known, $prefix, array &$found ) {
	$index = 0;

	foreach ( $blocks as $block ) {
		$freeform = null === $block['blockName'];
		$own      = $freeform ? (string) ( $block['innerHTML'] ?? '' ) : '';

		if ( $freeform && '' === trim( $own ) ) {
			continue;
		}

		$path = ( '' === $prefix ) ? (string) $index : $prefix . '.' . $index;
		++$index;

		$markup = $freeform ? $own : serialize_block( $block );
		if ( isset( $known[ $markup ] ) ) {
			continue;
		}

		// The block's own markup without its children, see
		// wpmcp_block_own_markup().
		$checked  = wpmcp_block_own_markup( $block );
		$filtered = wp_kses_post( $checked );

		if ( $filtered !== $checked && $filtered !== wpmcp_kses_equivalent( $checked ) ) {
			$removals = wpmcp_kses_removals( $checked );
			$found[]  = array(
				'path'       => $path,
				'name'       => $block['blockName'],
				'constructs' => wpmcp_kses_losses( $checked, $filtered ),
				'stored'     => $filtered,
				'fragments'  => $removals['fragments'],
				'explained'  => $removals['explained'],
			);
		}

		if ( ! $freeform && ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			wpmcp_find_unstable_blocks( $block['innerBlocks'], $known, $path, $found );
		}
	}
}

/**
 * The markup with the two rewrites kses makes that change nothing.
 *
 * kses rebuilds every tag it touches, and a self-closing one comes back
 * with " />" where Gutenberg writes "/>". It also turns a lone "&" into
 * "&amp;". Taken literally, "kses changes nothing" would therefore refuse
 * every image block and every "Mueller & Soehne" an agent writes.
 *
 * Only rewrites a browser reads exactly like the original are made here,
 * so markup equal to its kses version after them means the same thing
 * as that kses version:
 *
 * - "/>" right after a quoted value or a bare tag name becomes " />";
 *   the slash is ignored on void elements, the space is no attribute.
 *   After an unquoted value it is left alone, because there the slash
 *   belongs to the value.
 * - "&" followed by whitespace becomes "&amp;"; a character reference
 *   never starts like that. An "&" in front of letters is left alone:
 *   "&colon;" is a colon to a browser, so that difference stays a
 *   difference and the block is refused.
 * - The same "&" inside block attributes. The serializer writes it there
 *   as \u0026, and kses filters every attribute value on its own and
 *   serializes again, so "Mueller & Soehne" comes back as
 *   "Mueller \u0026amp; Soehne". Without this rule every attribute with
 *   such a name in it was refused, which on a design system with its
 *   texts in attributes is most headings.
 *
 * @param string $html Markup.
 * @return string
 */
function wpmcp_kses_equivalent( $html ) {
	$html = (string) preg_replace( '#(["\'])\s*/>#', '$1 />', (string) $html );
	$html = (string) preg_replace( '#<([a-zA-Z][a-zA-Z0-9]*)\s*/>#', '<$1 />', $html );
	$html = (string) preg_replace( '/\\\\u0026(?=\s)/', '\\\\u0026amp;', $html );

	return (string) preg_replace( '/&(?=\s)/', '&amp;', $html );
}

/**
 * Name what kses takes out of a piece of markup.
 *
 * For the message only - the decision has already been made by comparing
 * the markup with kses's version of it. Elements, attributes and URL
 * schemes are counted on both sides; whatever kses has fewer of is what
 * it removed. When nothing is missing, kses only rewrote the markup
 * (quotes, spacing, an unescaped ampersand), and the caller gets told to
 * send it the way WordPress would store it.
 *
 * @param string $html     Markup as sent.
 * @param string $filtered The same after wp_kses_post.
 * @return string[]
 */
function wpmcp_kses_losses( $html, $filtered ) {
	$sent = wpmcp_markup_inventory( $html );
	$kept = wpmcp_markup_inventory( $filtered );

	$losses = array();
	foreach ( $sent as $item => $count ) {
		if ( $count > ( $kept[ $item ] ?? 0 ) ) {
			$losses[] = $item;
		}
	}

	return $losses;
}

/**
 * Elements, attributes and URL schemes in a piece of markup, counted.
 *
 * Attribute names are found after whitespace or a "/", because browsers
 * accept both as a separator. A scheme is read the way a browser reads
 * it: entities decoded, whitespace and control characters dropped.
 *
 * @param string $html Markup.
 * @return array<string, int> "<svg", "onload=", "javascript:" => count.
 */
function wpmcp_markup_inventory( $html ) {
	$items = array();
	$add   = function ( $key ) use ( &$items ) {
		$items[ $key ] = ( $items[ $key ] ?? 0 ) + 1;
	};

	if ( ! preg_match_all( '#<([a-zA-Z][a-zA-Z0-9:-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>?#', (string) $html, $tags, PREG_SET_ORDER ) ) {
		return $items;
	}

	foreach ( $tags as $tag ) {
		$add( '<' . strtolower( $tag[1] ) );

		if ( ! preg_match_all( '#([^\s"\'>/=]+)(?:\s*=\s*("[^"]*"|\'[^\']*\'|[^\s"\'>]+))?#', $tag[2], $attrs, PREG_SET_ORDER ) ) {
			continue;
		}

		foreach ( $attrs as $attr ) {
			$add( strtolower( $attr[1] ) . '=' );

			$value = html_entity_decode( trim( $attr[2] ?? '', '"\'' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$value = preg_replace( '/[\x00-\x20]+/', '', $value );
			if ( preg_match( '#^([a-z][a-z0-9+.-]*):#i', (string) $value, $scheme ) ) {
				$add( strtolower( $scheme[1] ) . ':' );
			}
		}
	}

	return $items;
}

/**
 * The refused blocks as one line: path and what kses would remove.
 *
 * @param array  $blocks Result of wpmcp_unstable_blocks() or wpmcp_unstable_strings().
 * @param string $noun   What a path names: "block", or "element" for the
 *                       Elementor tools, whose paths are element settings.
 * @return string
 */
function wpmcp_unstable_summary( array $blocks, $noun = 'block' ) {
	$parts = array();

	foreach ( $blocks as $block ) {
		if ( empty( $block['constructs'] ) && empty( $block['fragments'] ) ) {
			$parts[] = sprintf( '%s %s (only rewritten; WordPress would store it as: %s)', $noun, $block['path'], wpmcp_shorten( $block['stored'], 120 ) );
			continue;
		}

		$part = sprintf( '%s %s (%s)', $noun, $block['path'], implode( ', ', $block['constructs'] ) );

		// Each fragment in its own words, and whether it was there before:
		// a highlight passed through reads very differently from a style
		// the agent wrote.
		$said = array();
		foreach ( (array) ( $block['fragments'] ?? array() ) as $fragment ) {
			$said[] = wpmcp_shorten( $fragment['text'], 160 ) . ' ' . wpmcp_fragment_status( $fragment );
		}
		if ( ! empty( $said ) ) {
			$part .= ': ' . implode( '; ', $said );
		}
		if ( isset( $block['explained'] ) && ! $block['explained'] ) {
			$part .= sprintf( '. WordPress would also change more of this block; it would store it as: %s', wpmcp_shorten( $block['stored'], 120 ) );
		}

		$parts[] = $part;
	}

	return implode( '; ', $parts );
}

/**
 * Whether a fragment of a refused block was there before, in words.
 *
 * @param array $fragment { before, after, inThis }.
 * @return string
 */
function wpmcp_fragment_status( array $fragment ) {
	$before = (int) ( $fragment['before'] ?? 0 );
	$after  = (int) ( $fragment['after'] ?? 0 );
	$where  = empty( $fragment['inThis'] ) ? 'already on the page' : 'already in this block before the change';

	if ( 0 === $before ) {
		return '(new: not in the stored page)';
	}
	if ( $after > $before ) {
		return sprintf( '(%s, but the page would have it %d times where it had it %d)', $where, $after, $before );
	}

	return sprintf( '(%s, may stay)', $where );
}

/**
 * The refusal for markup kses would alter, said once for every caller.
 *
 * @param array $impact Result of wpmcp_kses_impact().
 * @return string
 */
function wpmcp_filtered_markup_error( array $impact ) {
	$where = ! empty( $impact['blocks'] )
		? wpmcp_unstable_summary( $impact['blocks'] )
		: implode( ', ', $impact['added'] );

	return sprintf(
		'This change adds markup WordPress will not store from an agent account: %s. Event handlers, javascript: URLs, inline scripts, iframes, embeds and forms cannot be written this way; structured data can, as <script type="application/ld+json"> holding valid JSON. Markup of that kind already stored may be passed through unchanged, as often as it was there; what is new may not. Remove it, or have a human add it in the editor. A block that only needs rewriting goes through when sent exactly as WordPress would store it. To repair content of this kind through the connector, a developer can open the door deliberately with the wpmcp_allow_filtered_markup filter.',
		$where
	);
}

/**
 * Save with unfiltered_html, for exactly one call.
 *
 * Some block libraries refuse to store dynamic data unless the account
 * holds unfiltered_html. Putting that capability on the agent role would
 * undo the rest of the role: no publishing, no deleting, no uploads, no
 * settings — and then permission to store arbitrary HTML and JavaScript.
 *
 * So it is granted around one wp_update_post and taken away again. The
 * filter is scoped to the user being checked, and removed in a finally
 * block so a fatal inside the save cannot leave it standing.
 *
 * Callers must have refused the write first when wpmcp_kses_impact()
 * reports it introduces anything. With this capability WordPress stops
 * filtering the content, so that check is not a warning, it is the
 * replacement for what wp_kses would otherwise have done.
 *
 * @param array $postarr Arguments for wp_update_post.
 * @return int|\WP_Error
 */
function wpmcp_update_post_elevated( array $postarr ) {
	$release = wpmcp_grant_caps_for_request( array( 'unfiltered_html' ) );

	try {
		// Check that the grant took, rather than assuming it. unfiltered_html
		// is a meta capability: a site can turn it into do_not_allow from
		// wp-config, and then no amount of granting reaches it. Without this
		// the save goes ahead and fails with the block library's own message,
		// which says nothing about why — and the last time that happened the
		// conclusion was that hook priorities were wrong.
		$blocker = wpmcp_unfiltered_html_blocker();
		if ( $blocker ) {
			return new \WP_Error( 'wpmcp_unfiltered_html_unavailable', $blocker );
		}

		return wpmcp_update_post_preserving( $postarr );
	} finally {
		$release();
	}
}

/**
 * Why unfiltered_html cannot be granted here, if it cannot.
 *
 * Two ways a site puts it out of reach for everybody, administrators
 * included. Both are deliberate decisions by whoever set the site up, and
 * neither is this plugin's to overrule — so the answer is to name them.
 *
 * @return string|null Explanation, or null when the capability is reachable.
 */
function wpmcp_unfiltered_html_blocker() {
	if ( defined( 'DISALLOW_UNFILTERED_HTML' ) && DISALLOW_UNFILTERED_HTML ) {
		return 'This site defines DISALLOW_UNFILTERED_HTML in wp-config.php, which WordPress turns into a "do_not_allow" for every account, administrators included. No setting in this plugin can reach past that, and it should not: it is a decision the site was configured with on purpose. Either remove the constant, or leave pages with dynamic data to a human in the editor — where the same constant applies, so an administrator will have to lift it there too.';
	}

	if ( is_multisite() && function_exists( 'is_super_admin' ) && ! is_super_admin( get_current_user_id() ) ) {
		return 'On multisite WordPress reserves unfiltered_html for super admins, so it cannot be granted to the agent account here.';
	}

	return null;
}

/**
 * The one kind of script the connector writes: structured data.
 *
 * JSON-LD is a standard part of every SEO-minded page, and browsers do not
 * execute it. What makes a script dangerous is code, and a JSON-LD block
 * holding valid JSON contains none. The single way out of it would be a
 * "</script>" inside the data, closing the tag early; a "<" anywhere in
 * the data is therefore re-encoded as \u003C before it is stored, which
 * JSON parsers read back as the same character.
 *
 * Anything else stays refused: another type, an extra attribute on the
 * tag, or content that is not valid JSON is treated as the script it is.
 *
 * @return string
 */
function wpmcp_jsonld_regex() {
	return '#<script\s+type\s*=\s*(["\'])application/ld\+json\1\s*>(.*?)</script\s*>#is';
}

/**
 * Is this JSON-LD body safe as it stands?
 *
 * @param string $inner Text between the script tags.
 * @return bool
 */
function wpmcp_jsonld_inner_safe( $inner ) {
	json_decode( (string) $inner );

	return JSON_ERROR_NONE === json_last_error() && false === strpos( (string) $inner, '<' );
}

/**
 * Make every JSON-LD block safe to store, leaving safe ones byte-identical.
 *
 * @param string $html Serialized content.
 * @return string
 */
function wpmcp_normalize_jsonld( $html ) {
	return (string) preg_replace_callback(
		wpmcp_jsonld_regex(),
		function ( $m ) {
			if ( wpmcp_jsonld_inner_safe( $m[2] ) ) {
				return $m[0];
			}

			$data = json_decode( $m[2], true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return $m[0];
			}

			$json = wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );

			return '<script type="application/ld+json">' . "\n" . $json . "\n" . '</script>';
		},
		(string) $html
	);
}

/**
 * Remove safe JSON-LD before looking for markup that runs.
 *
 * @param string $html Content.
 * @return string
 */
function wpmcp_strip_safe_jsonld( $html ) {
	return (string) preg_replace_callback(
		wpmcp_jsonld_regex(),
		function ( $m ) {
			return wpmcp_jsonld_inner_safe( $m[2] ) ? '' : $m[0];
		},
		(string) $html
	);
}

/**
 * Should this save bypass WordPress's content filter?
 *
 * Two reasons, and only these two. Editing one block must not destroy
 * markup in another that the agent never touched — safe, because every
 * block the agent did touch would come out of kses unchanged ("introduces"
 * is false only then, see wpmcp_unstable_blocks()). And a repair explicitly opened by the site
 * has to reach the database, or opening it means nothing: the check would
 * let the script through and the save would drop it a moment later.
 *
 * The bypass is one call wide. It removes the filter, saves, puts it back.
 * Granting the agent unfiltered_html instead would leave the door open for
 * everything else that runs in the request, and for whatever forgets to
 * take it away again.
 *
 * @param array         $impact Result of wpmcp_kses_impact().
 * @param \WP_Post|null $post   Post being written.
 * @return bool
 */
function wpmcp_should_preserve_markup( array $impact, $post = null ) {
	if ( empty( $impact['alters'] ) ) {
		return false;
	}

	return empty( $impact['introduces'] ) || wpmcp_filtered_markup_allowed( $post );
}

/**
 * May this save introduce markup WordPress would strip?
 *
 * No, by default: an agent that can write a script tag can write anything
 * a script can do, and that is not what a remote editor is for.
 *
 * There is one case for opening it, and it is a repair. When a page lost
 * its JSON-LD to an unfiltered save, nobody can put it back through the
 * connector — the block editor is the only way in, page by page. A
 * developer with filesystem access can open this for the length of that
 * job and close it again. It is deliberately not a setting in the admin:
 * a checkbox invites being left on.
 *
 * @param \WP_Post $post Post being written.
 * @return bool
 */
function wpmcp_filtered_markup_allowed( $post ) {
	/**
	 * Whether the agent may write markup that requires unfiltered_html.
	 *
	 * @param bool     $allow Default false.
	 * @param \WP_Post $post  Post being written.
	 */
	return (bool) apply_filters( 'wpmcp_allow_filtered_markup', false, $post );
}

/**
 * Every piece of markup in this content that WordPress would filter,
 * counted by its exact text.
 *
 * Identity is what matters here, not quantity: a fragment that was in the
 * page before may stay, anything else is new. Whole elements are taken for
 * script, style and iframe, because their content is the point; for the
 * rest the opening tag is enough to identify them.
 *
 * @param string $content Post content.
 * @return array<string, int> Fragment text => occurrences.
 */
function wpmcp_filtered_fragments( $content ) {
	$patterns = array(
		'#<script\b[^>]*>.*?</script\s*>#is',
		'#<style\b[^>]*>.*?</style\s*>#is',
		'#<iframe\b[^>]*>.*?</iframe\s*>#is',
		'#<(?:form|object|embed)\b[^>]*>#i',
		// An unclosed script or iframe still gets filtered out.
		'#<(?:script|style|iframe)\b[^>]*>#i',
	);

	$found = array();
	$rest  = wpmcp_strip_safe_jsonld( $content );

	foreach ( $patterns as $pattern ) {
		if ( preg_match_all( $pattern, $rest, $matches ) ) {
			foreach ( $matches[0] as $fragment ) {
				$found[ $fragment ] = ( $found[ $fragment ] ?? 0 ) + 1;
			}
			// Take the matches out so the fallback pattern does not count
			// the opening tag of an element already matched whole.
			$rest = preg_replace( $pattern, '', $rest );
		}
	}

	return $found;
}

/**
 * Name a fragment by its element, for a message a human reads.
 *
 * @param string $fragment Matched markup.
 * @return string
 */
function wpmcp_fragment_label( $fragment ) {
	return preg_match( '#^<\s*([a-z]+)#i', $fragment, $m ) ? '<' . strtolower( $m[1] ) : $fragment;
}

/**
 * Run a save with WordPress's content filter switched off, and only that.
 *
 * kses sits on two filters for accounts without unfiltered_html. They are
 * removed for the length of the callback and put back in a finally, so a
 * fatal inside the save cannot leave the rest of the request unfiltered.
 * Only the filters that were actually there go back: an account that
 * never had them - one with unfiltered_html - must not leave this with a
 * filter it did not have before.
 *
 * Whoever calls this has decided the content may skip the filter. For
 * anything the agent wrote, that decision is wpmcp_should_preserve_markup().
 *
 * @param callable $save The save.
 * @return mixed Whatever the save returns.
 */
function wpmcp_without_kses( callable $save ) {
	$removed = array();

	foreach ( array( 'content_save_pre', 'content_filtered_save_pre' ) as $filter ) {
		if ( remove_filter( $filter, 'wp_filter_post_kses' ) ) {
			$removed[] = $filter;
		}
	}

	try {
		return $save();
	} finally {
		foreach ( $removed as $filter ) {
			add_filter( $filter, 'wp_filter_post_kses' );
		}
	}
}

/**
 * Insert a post without the content filter.
 *
 * A duplicate is a byte-for-byte copy of a post that already exists on
 * this site. Filtering it would strip markup the original is allowed to
 * hold — the copy would silently differ from what was copied, which is
 * the one thing a duplicate must never do. The agent supplies no content
 * here, only an id, so there is nothing it could smuggle in. The title it
 * may supply still goes through its own filter, which stays on.
 *
 * @param array $postarr Arguments for wp_insert_post.
 * @return int|\WP_Error
 */
function wpmcp_insert_post_preserving( array $postarr ) {
	return wpmcp_without_kses(
		function () use ( $postarr ) {
			return wp_insert_post( $postarr, true );
		}
	);
}

/**
 * Save without the kses filter, for the one case where it does harm.
 *
 * Only ever used when every block the agent changed would come out of
 * kses unchanged (wpmcp_should_preserve_markup), so skipping the filter
 * changes nothing about the agent's part. What it protects is the rest:
 * markup already stored in blocks the change did not touch.
 *
 * @param array $postarr Arguments for wp_update_post.
 * @return int|\WP_Error
 */
function wpmcp_update_post_preserving( array $postarr ) {
	return wpmcp_without_kses(
		function () use ( $postarr ) {
			return wp_update_post( $postarr, true );
		}
	);
}
