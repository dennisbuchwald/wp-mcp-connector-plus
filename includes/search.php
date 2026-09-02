<?php
/**
 * Find a string across the site, with the surrounding markup.
 *
 * Locating 31 occurrences of a phone number over 18 pages previously meant
 * reading every page and counting by hand. Worse than the cost was the
 * guessing: five pages had a <br> before an empty tel anchor and one did
 * not, and a change extrapolated from the five would have skipped the
 * sixth while reporting success.
 *
 * Hence the context around each hit. An agent that has to change markup
 * must never have to infer what the markup is.
 *
 * @package wp-mcp-connector-plus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Search post content for a string or regular expression.
 *
 * @param array $args { query, regex, post_types, post_status, context_chars, limit }.
 * @return array|\WP_Error
 */
function wpmcp_search_content( array $args ) {
	$query = isset( $args['query'] ) ? (string) $args['query'] : '';
	if ( '' === trim( $query ) ) {
		return new \WP_Error( 'wpmcp_bad_request', 'Provide a "query" to search for.' );
	}

	$is_regex = ! empty( $args['regex'] );
	$context  = max( 0, min( 400, (int) ( $args['context_chars'] ?? 80 ) ) );
	$limit    = max( 1, min( 500, (int) ( $args['limit'] ?? 200 ) ) );

	if ( $is_regex ) {
		$pattern = '/' . str_replace( '/', '\/', $query ) . '/u';
		// A malformed pattern must not surface as a PHP warning.
		if ( false === @preg_match( $pattern, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new \WP_Error( 'wpmcp_bad_regex', sprintf( '"%s" is not a valid regular expression.', $query ) );
		}
	}

	$post_types = ! empty( $args['post_types'] ) && is_array( $args['post_types'] )
		? array_values( array_intersect( $args['post_types'], wpmcp_allowed_post_types() ) )
		: wpmcp_allowed_post_types();

	$statuses = ! empty( $args['post_status'] ) && is_array( $args['post_status'] )
		? $args['post_status']
		: array( 'publish', 'draft', 'pending', 'future', 'private' );

	$posts = get_posts(
		array(
			'post_type'        => $post_types,
			'post_status'      => $statuses,
			'posts_per_page'   => -1,
			'suppress_filters' => true,
			'orderby'          => 'ID',
			'order'            => 'ASC',
		)
	);

	$matches   = array();
	$truncated = false;

	foreach ( $posts as $post ) {
		if ( ! current_user_can( 'edit_post', $post->ID ) && 'publish' !== $post->post_status ) {
			continue;
		}

		$found = wpmcp_search_in_post( $post, $query, $is_regex, $context );
		foreach ( $found as $hit ) {
			if ( count( $matches ) >= $limit ) {
				$truncated = true;
				break 2;
			}
			$matches[] = $hit;
		}
	}

	$posts_affected = array_unique( array_column( $matches, 'postId' ) );

	wpmcp_log(
		'wpmcp/content-search',
		array(
			'summary' => sprintf( '%d match(es) in %d post(s) for "%s".', count( $matches ), count( $posts_affected ), $query ),
		)
	);

	return array(
		'query'         => $query,
		'regex'         => $is_regex,
		'totalMatches'  => count( $matches ),
		'postsAffected' => count( $posts_affected ),
		'truncated'     => $truncated,
		'matches'       => $matches,
	);
}

/**
 * Every occurrence inside one post, located in the block tree.
 *
 * @param \WP_Post $post     Post.
 * @param string   $query    Needle or pattern.
 * @param bool     $is_regex Whether the query is a regular expression.
 * @param int      $context  Characters of context on each side.
 * @return array
 */
function wpmcp_search_in_post( $post, $query, $is_regex, $context ) {
	$blocks = parse_blocks( $post->post_content );
	$hits   = array();

	wpmcp_search_blocks( $blocks, array(), $query, $is_regex, $context, $post, $hits );

	return $hits;
}

/**
 * Walk the tree and record each occurrence with where it sits.
 *
 * @param array    $blocks   Parsed blocks.
 * @param array    $prefix   Path prefix.
 * @param string   $query    Needle or pattern.
 * @param bool     $is_regex Whether the query is a regular expression.
 * @param int      $context  Context characters.
 * @param \WP_Post $post     Post the blocks belong to.
 * @param array    $hits     Collected hits (by reference).
 */
function wpmcp_search_blocks( array $blocks, array $prefix, $query, $is_regex, $context, $post, array &$hits ) {
	$index = 0;

	foreach ( $blocks as $block ) {
		$name = $block['blockName'] ?? null;

		if ( null === $name && '' === trim( (string) ( $block['innerHTML'] ?? '' ) ) ) {
			continue;
		}

		$path = wpmcp_path_string( array_merge( $prefix, array( $index ) ) );

		// The block's own markup, and its attributes, are separate haystacks:
		// a phone number can sit in either and needs finding in both.
		$haystacks = array(
			'innerHTML' => (string) ( $block['innerHTML'] ?? '' ),
			'attrs'     => empty( $block['attrs'] ) ? '' : (string) wp_json_encode( $block['attrs'] ),
		);

		foreach ( $haystacks as $where => $haystack ) {
			if ( '' === $haystack ) {
				continue;
			}
			foreach ( wpmcp_find_offsets( $haystack, $query, $is_regex ) as $offset ) {
				$hits[] = array(
					'postId'    => $post->ID,
					'postTitle' => get_the_title( $post ),
					'postType'  => $post->post_type,
					'status'    => $post->post_status,
					'permalink' => get_permalink( $post ),
					'path'      => $path,
					'blockName' => $name,
					'uniqueId'  => $block['attrs']['uniqueId'] ?? ( $block['attrs']['uniqueID'] ?? null ),
					'in'        => $where,
					'offset'    => $offset[0],
					'match'     => $offset[1],
					'context'   => wpmcp_context_around( $haystack, $offset[0], strlen( $offset[1] ), $context ),
				);
			}
		}

		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			wpmcp_search_blocks(
				$block['innerBlocks'],
				array_merge( $prefix, array( $index ) ),
				$query,
				$is_regex,
				$context,
				$post,
				$hits
			);
		}

		++$index;
	}
}

/**
 * Byte offsets and matched text of every occurrence.
 *
 * @param string $haystack Text to search.
 * @param string $query    Needle or pattern.
 * @param bool   $is_regex Whether the query is a regular expression.
 * @return array<int, array{0:int,1:string}>
 */
function wpmcp_find_offsets( $haystack, $query, $is_regex ) {
	$out = array();

	if ( $is_regex ) {
		$pattern = '/' . str_replace( '/', '\/', $query ) . '/u';
		if ( preg_match_all( $pattern, $haystack, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[0] as $hit ) {
				$out[] = array( (int) $hit[1], (string) $hit[0] );
			}
		}
		return $out;
	}

	$offset = 0;
	while ( true ) {
		$position = strpos( $haystack, $query, $offset );
		if ( false === $position ) {
			break;
		}
		$out[]  = array( $position, $query );
		$offset = $position + max( 1, strlen( $query ) );
	}

	return $out;
}

/**
 * The raw text around a hit, unmodified.
 *
 * No trimming and no entity handling: the point is to show exactly what is
 * stored, including whether a <br> sits before the match on one page and
 * not on another.
 *
 * @param string $haystack Text.
 * @param int    $offset   Start of the match.
 * @param int    $length   Length of the match.
 * @param int    $context  Characters either side.
 * @return array { before: string, match: string, after: string }
 */
function wpmcp_context_around( $haystack, $offset, $length, $context ) {
	$start = max( 0, $offset - $context );

	return array(
		'before' => substr( $haystack, $start, $offset - $start ),
		'match'  => substr( $haystack, $offset, $length ),
		'after'  => substr( $haystack, $offset + $length, $context ),
	);
}
