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
 * Longest regular expression content-search accepts, in bytes.
 *
 * A pattern is run against every block of every candidate page, and the
 * cost of a bad one grows with its length. Anything an agent needs to
 * find a phone number or a class name fits well inside this.
 */
const WPMCP_SEARCH_MAX_PATTERN = 200;

/**
 * Posts loaded from the database at a time.
 */
const WPMCP_SEARCH_BATCH = 50;

/**
 * How much one search may read before it stops.
 *
 * Plain queries rarely come near this, because the database hands over
 * only pages that contain the text. A regular expression cannot be
 * prefiltered and reads every page in scope, so on a large site it stops
 * here and says so. Filter: wpmcp_search_limits.
 *
 * @return array{posts: int, bytes: int}
 */
function wpmcp_search_limits() {
	$limits = apply_filters(
		'wpmcp_search_limits',
		array(
			'posts' => 5000,
			'bytes' => 32 * 1024 * 1024,
		)
	);
	return array(
		'posts' => max( 1, (int) ( $limits['posts'] ?? 5000 ) ),
		'bytes' => max( 1, (int) ( $limits['bytes'] ?? 32 * 1024 * 1024 ) ),
	);
}

/**
 * Search post content for a string or regular expression.
 *
 * Scope and visibility are the ones content-list uses
 * (wpmcp_list_visibility): post types within the connector's reach,
 * statuses from wpmcp_listable_statuses(), drafts, private and
 * password-protected posts only where the account could edit them. All
 * of it is SQL, so the database returns IDs of readable posts only.
 *
 * The posts are then loaded fifty at a time and searched in order of ID,
 * and loading stops as soon as the page of hits is full. A plain query
 * also goes to the database as a LIKE on the content, so only pages that
 * can contain it are loaded at all; see wpmcp_search_like_needle() for
 * why that never drops a page the search itself would find.
 *
 * @param array $args { query, regex, post_types|post_type, post_status, context_chars, limit, offset }.
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
	$skip     = max( 0, (int) ( $args['offset'] ?? 0 ) );

	if ( $is_regex ) {
		if ( strlen( $query ) > WPMCP_SEARCH_MAX_PATTERN ) {
			return new \WP_Error(
				'wpmcp_bad_regex',
				sprintf( 'The regular expression is %d bytes long; at most %d are accepted. Search for a shorter, more specific pattern.', strlen( $query ), WPMCP_SEARCH_MAX_PATTERN )
			);
		}
		// A malformed pattern must not surface as a PHP warning.
		if ( false === @preg_match( wpmcp_search_pattern( $query ), '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new \WP_Error( 'wpmcp_bad_regex', sprintf( '"%s" is not a valid regular expression.', $query ) );
		}
	}

	// content-list and content-create take "post_type" as one string, so an
	// agent sends that here too. Accepted, as is a string for either list.
	$allowed     = wpmcp_allowed_post_types();
	$asked_types = wpmcp_search_list( $args['post_types'] ?? ( $args['post_type'] ?? null ) );
	$post_types  = ! empty( $asked_types ) ? array_values( array_intersect( $asked_types, $allowed ) ) : $allowed;
	if ( empty( $post_types ) ) {
		return new \WP_Error(
			'wpmcp_forbidden_type',
			sprintf( 'Post type "%s" is not exposed to the connector. Searchable here: %s.', implode( ', ', $asked_types ), implode( ', ', $allowed ) )
		);
	}

	// The same statuses content-list accepts. "trash", "inherit" or "any"
	// went to the query unchecked and found text in posts no other tool
	// shows.
	$asked_status = wpmcp_search_list( $args['post_status'] ?? null );
	$statuses     = wpmcp_listable_statuses();
	if ( ! empty( $asked_status ) ) {
		$statuses = array_values( array_intersect( $asked_status, wpmcp_listable_statuses() ) );
		if ( count( $statuses ) !== count( $asked_status ) ) {
			return new \WP_Error(
				'wpmcp_bad_status',
				sprintf(
					'Status "%s" cannot be searched. Use one of: %s.',
					implode( ', ', array_diff( $asked_status, wpmcp_listable_statuses() ) ),
					implode( ', ', wpmcp_listable_statuses() )
				)
			);
		}
	}

	$visibility = array();
	foreach ( $post_types as $type ) {
		$rule                = wpmcp_list_visibility( $type );
		$rule['statuses']    = array_values( array_intersect( $rule['statuses'], $statuses ) );
		$visibility[ $type ] = $rule;
	}

	$ids = wpmcp_search_candidate_ids( $visibility, $is_regex ? '' : wpmcp_search_like_needle( $query ) );

	$limits        = wpmcp_search_limits();
	$matches       = array();
	$truncated     = false;
	$scan_limited  = false;
	$seen          = 0;
	$scanned       = 0;
	$scanned_bytes = 0;

	foreach ( array_chunk( $ids, WPMCP_SEARCH_BATCH ) as $batch ) {
		$posts = get_posts(
			array(
				'post__in'               => $batch,
				'post_type'              => $post_types,
				'post_status'            => $statuses,
				'posts_per_page'         => count( $batch ),
				'orderby'                => 'post__in',
				'suppress_filters'       => true,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		foreach ( $posts as $post ) {
			if ( $scanned >= $limits['posts'] || $scanned_bytes >= $limits['bytes'] ) {
				$scan_limited = true;
				break 2;
			}
			// The query drew the line already. This is the per-post form
			// of it, so a search can never show more than a read would.
			if ( ! wpmcp_user_can_read( $post ) ) {
				continue;
			}
			++$scanned;
			$scanned_bytes += strlen( (string) $post->post_content );

			$found = wpmcp_search_in_post( $post, $query, $is_regex, $context );
			if ( is_wp_error( $found ) ) {
				return $found;
			}
			foreach ( $found as $hit ) {
				// Hits come in a fixed order (post ID, then block path), so an
				// offset into them names the same hit on the next call.
				if ( $seen++ < $skip ) {
					continue;
				}
				if ( count( $matches ) >= $limit ) {
					$truncated = true;
					break 3;
				}
				$matches[] = $hit;
			}
		}
	}

	$posts_affected = array_unique( array_column( $matches, 'postId' ) );

	wpmcp_log(
		'wpmcp/content-search',
		array(
			'summary' => sprintf( '%d match(es) in %d post(s) for "%s", %d post(s) read.', count( $matches ), count( $posts_affected ), $query, $scanned ),
		)
	);

	$result = array(
		'query'         => $query,
		'regex'         => $is_regex,
		'offset'        => $skip,
		'totalMatches'  => count( $matches ),
		'postsAffected' => count( $posts_affected ),
		'truncated'     => $truncated || $scan_limited,
		'scanned'       => $scanned,
		'matches'       => $matches,
	);

	// The limit stops at 500, and a site-wide search can find more. Without
	// a way past the first page the rest were simply unreachable.
	if ( $truncated ) {
		$result['nextOffset'] = $skip + count( $matches );
	} elseif ( $scan_limited ) {
		// No nextOffset: the next call would read the same pages up to the
		// same limit and stop at the same place.
		$result['scanLimitReached'] = true;
		$result['hint']             = sprintf(
			'Stopped after reading %d post(s) (%d bytes); pages after that were not searched. Narrow the search with post_type or post_status, or search for plain text instead of a regular expression: plain text is matched in the database first, so only pages containing it are read.',
			$scanned,
			$scanned_bytes
		);
	}

	return $result;
}

/**
 * IDs of the posts a search has to read, in ascending order.
 *
 * Only IDs: on a large site the content of every candidate at once is
 * what used to make a search expensive.
 *
 * @param array  $visibility Per type, from wpmcp_list_visibility().
 * @param string $needle     Text every candidate must contain, or ''.
 * @return int[]
 */
function wpmcp_search_candidate_ids( array $visibility, $needle ) {
	global $wpdb;

	$where = wpmcp_list_where( $visibility );
	if ( '' !== $needle ) {
		$like = '%' . $wpdb->esc_like( $needle ) . '%';
		if ( function_exists( 'wpmcp_elementor_search' ) ) {
			// Elementor runs (its tools are loaded). An Elementor page holds its markup in _elementor_data; its
			// post_content is a plain-text copy without links or attributes.
			// The needle is cut at every character JSON escapes, so it
			// matches the stored JSON verbatim.
			$where .= $wpdb->prepare(
				" AND ( {$wpdb->posts}.post_content LIKE %s OR {$wpdb->posts}.ID IN ( SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data' AND meta_value LIKE %s ) )",
				$like,
				$like
			);
		} else {
			$where .= $wpdb->prepare( " AND {$wpdb->posts}.post_content LIKE %s", $like );
		}
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- every value in $where is prepared or escaped.
	$ids = $wpdb->get_col( "SELECT {$wpdb->posts}.ID FROM {$wpdb->posts} WHERE 1=1{$where} ORDER BY {$wpdb->posts}.ID ASC" );

	return array_map( 'intval', (array) $ids );
}

/**
 * The part of a plain query the database can safely look for.
 *
 * The search looks at two things: a block's markup, which is stored
 * exactly as it is searched, and its attributes, which are searched as
 * JSON re-encoded from the parsed block. That JSON and the stored one
 * differ in how some characters are written: WordPress stores "<", ">",
 * "&", '"' and "--" inside attributes as < and the like, older
 * content may have "\/" for "/" and ü for "ü", and the JSON escape
 * character itself is "\". A LIKE on the whole query would miss such a
 * page, and the search would report a confident zero.
 *
 * So the query is cut at every such character, and the longest piece
 * between them goes to the database. Every page that contains the query,
 * in markup or attributes, contains that piece verbatim. Under three
 * bytes a piece filters next to nothing, and the query is matched in PHP
 * only.
 *
 * @param string $query Plain search text.
 * @return string Text to require in the content, or '' for no prefilter.
 */
function wpmcp_search_like_needle( $query ) {
	$pieces = preg_split( '/[<>&"\\\\\\/\\-\\x00-\\x1f\\x7f-\\xff]+/', (string) $query );
	$best   = '';
	foreach ( (array) $pieces as $piece ) {
		if ( strlen( $piece ) > strlen( $best ) ) {
			$best = $piece;
		}
	}
	return strlen( $best ) >= 3 ? $best : '';
}

/**
 * The delimited pattern for a regex query.
 *
 * @param string $query Pattern without delimiters.
 * @return string
 */
function wpmcp_search_pattern( $query ) {
	return '/' . str_replace( '/', '\/', $query ) . '/u';
}

/**
 * A list argument that may arrive as a list or as one string.
 *
 * @param mixed $value Array, a string (comma-separated allowed), or null.
 * @return string[]
 */
function wpmcp_search_list( $value ) {
	if ( is_string( $value ) ) {
		$value = explode( ',', $value );
	}
	if ( ! is_array( $value ) ) {
		return array();
	}
	return array_values( array_filter( array_map( 'trim', array_filter( $value, 'is_string' ) ), 'strlen' ) );
}

/**
 * Every occurrence inside one post, located in the block tree.
 *
 * Title and permalink are looked up once per post and only when it has
 * hits: get_permalink() is not free, and a page with forty hits used to
 * ask for it forty times.
 *
 * @param \WP_Post $post     Post.
 * @param string   $query    Needle or pattern.
 * @param bool     $is_regex Whether the query is a regular expression.
 * @param int      $context  Characters of context on each side.
 * @return array|\WP_Error Hits, or an error when the pattern failed on this post.
 */
function wpmcp_search_in_post( $post, $query, $is_regex, $context ) {
	$hits = array();

	// An Elementor page is searched in its elements, not in its
	// plain-text copy: hits name the element id elementor-write takes.
	if ( function_exists( 'wpmcp_elementor_search' ) && wpmcp_elementor_built( $post ) ) {
		$failed = wpmcp_elementor_search( $post->ID, $query, $is_regex, $context, $hits );
	} else {
		$failed = wpmcp_search_blocks( parse_blocks( $post->post_content ), array(), $query, $is_regex, $context, $hits );
	}
	if ( is_wp_error( $failed ) ) {
		return new \WP_Error(
			'wpmcp_bad_regex',
			sprintf( 'The regular expression could not be run on post %d (%s). Nothing was skipped silently: make the pattern more specific, for example without nested repetition.', $post->ID, $failed->get_error_message() )
		);
	}

	if ( empty( $hits ) ) {
		return $hits;
	}

	$about = array(
		'postId'    => $post->ID,
		'postTitle' => get_the_title( $post ),
		'postType'  => $post->post_type,
		'status'    => $post->post_status,
		'permalink' => get_permalink( $post ),
	);

	foreach ( $hits as $i => $hit ) {
		$hits[ $i ] = array_merge( $about, $hit );
	}

	return $hits;
}

/**
 * Walk the tree and record each occurrence with where it sits.
 *
 * @param array  $blocks   Parsed blocks.
 * @param array  $prefix   Path prefix.
 * @param string $query    Needle or pattern.
 * @param bool   $is_regex Whether the query is a regular expression.
 * @param int    $context  Context characters.
 * @param array  $hits     Collected hits (by reference), without the post fields.
 * @return true|\WP_Error An error when the pattern failed to run.
 */
function wpmcp_search_blocks( array $blocks, array $prefix, $query, $is_regex, $context, array &$hits ) {
	$index = 0;

	foreach ( $blocks as $block ) {
		$name = $block['blockName'] ?? null;

		if ( ! wpmcp_is_visible_block( $block ) ) {
			continue;
		}

		$path = wpmcp_path_string( array_merge( $prefix, array( $index ) ) );

		// The block's own markup, and its attributes, are separate haystacks:
		// a phone number can sit in either and needs finding in both.
		$haystacks = array(
			'innerHTML' => (string) ( $block['innerHTML'] ?? '' ),
			// Encoded the way the serializer stores them. With the
			// defaults "Müller" became "M\u00fcller" and every "/" a "\/",
			// so a name with an umlaut or any URL was never found.
			'attrs'     => empty( $block['attrs'] ) ? '' : (string) wp_json_encode( $block['attrs'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
		);

		foreach ( $haystacks as $where => $haystack ) {
			if ( '' === $haystack ) {
				continue;
			}
			$offsets = wpmcp_find_offsets( $haystack, $query, $is_regex );
			if ( is_wp_error( $offsets ) ) {
				return $offsets;
			}
			foreach ( $offsets as $offset ) {
				$hits[] = array(
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
			$inner = wpmcp_search_blocks(
				$block['innerBlocks'],
				array_merge( $prefix, array( $index ) ),
				$query,
				$is_regex,
				$context,
				$hits
			);
			if ( is_wp_error( $inner ) ) {
				return $inner;
			}
		}

		++$index;
	}

	return true;
}

/**
 * Byte offsets and matched text of every occurrence.
 *
 * @param string $haystack Text to search.
 * @param string $query    Needle or pattern.
 * @param bool   $is_regex Whether the query is a regular expression.
 * A pattern that fails on a haystack (backtracking limit, invalid UTF-8)
 * is an error, not an empty result: preg_match_all() returns false, and
 * read as "no hits" that was a confident zero for a page never searched.
 *
 * @return array<int, array{0:int,1:string}>|\WP_Error
 */
function wpmcp_find_offsets( $haystack, $query, $is_regex ) {
	$out = array();

	if ( $is_regex ) {
		$count = preg_match_all( wpmcp_search_pattern( $query ), $haystack, $m, PREG_OFFSET_CAPTURE );
		if ( false === $count ) {
			return new \WP_Error( 'wpmcp_bad_regex', preg_last_error_msg() );
		}
		if ( $count ) {
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
	// Byte arithmetic, cut at character boundaries: a context that ends
	// in half an umlaut is not UTF-8, and the whole response fails to
	// encode over it.
	$start = wpmcp_utf8_boundary( $haystack, max( 0, $offset - $context ) );

	return array(
		'before' => (string) substr( $haystack, $start, $offset - $start ),
		'match'  => (string) substr( $haystack, $offset, $length ),
		'after'  => wpmcp_utf8_cut( $haystack, $offset + $length, $context ),
	);
}
