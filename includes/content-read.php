<?php
/**
 * Listing, reading and previewing content.
 *
 * Nothing here writes. content-list filters in the query rather than after
 * paging, content-read hands out the block tree with paths, and the
 * preview renders a page once and cuts it into windows.
 *
 * @package wp-mcp-connector-plus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Statuses content-list can be asked for.
 *
 * Trash, auto-drafts, revisions ("inherit") and "any" are not content
 * anybody is working on, and "any" would also bypass the per-type check
 * below.
 *
 * @return string[]
 */
function wpmcp_listable_statuses() {
	return array( 'publish', 'draft', 'pending', 'future', 'private' );
}

/**
 * Which statuses of a post type the current user may see in a list.
 *
 * The same line wpmcp_get_readable_post() draws for one post, drawn for a
 * whole type so that it can go into the query: what is filtered out after
 * paging leaves pages short and totals wrong. Published content is public;
 * anything else is readable only with the right to edit it, which for
 * another user's post means edit_others_*.
 *
 * @param string $type Post type.
 * @return array{statuses: string[], passwords: bool}
 */
function wpmcp_list_visibility( $type ) {
	$object = get_post_type_object( $type );
	$cap    = $object && isset( $object->cap ) ? $object->cap : null;

	$can = function ( $name ) use ( $cap ) {
		return $cap && isset( $cap->{$name} ) && current_user_can( $cap->{$name} );
	};

	$others = $can( 'edit_posts' ) && $can( 'edit_others_posts' );

	$statuses = array( 'publish' );
	if ( $others ) {
		$statuses = array_merge( $statuses, array( 'draft', 'pending', 'future' ) );
		if ( $can( 'edit_private_posts' ) ) {
			$statuses[] = 'private';
		}
	}

	return array(
		'statuses'  => $statuses,
		// A password-protected post is published, and editing someone
		// else's published post needs both of these.
		'passwords' => $others && $can( 'edit_published_posts' ),
	);
}

/**
 * The SQL that limits a list to what the current user may read.
 *
 * One clause per post type, because what is visible differs per type.
 *
 * @param array<string, array{statuses: string[], passwords: bool}> $visibility Per type.
 * @param string                                                     $block      Block name, or ''.
 * @return string Starts with " AND ".
 */
function wpmcp_list_where( array $visibility, $block = '' ) {
	global $wpdb;

	$per_type = array();
	foreach ( $visibility as $type => $rule ) {
		if ( empty( $rule['statuses'] ) ) {
			continue;
		}
		$clause = $wpdb->prepare( "{$wpdb->posts}.post_type = %s", $type )
			. " AND {$wpdb->posts}.post_status IN ('" . implode( "','", array_map( 'esc_sql', $rule['statuses'] ) ) . "')";
		if ( empty( $rule['passwords'] ) ) {
			$clause .= " AND ( {$wpdb->posts}.post_status <> 'publish' OR {$wpdb->posts}.post_password = '' )";
		}
		$per_type[] = '( ' . $clause . ' )';
	}

	$where = empty( $per_type ) ? ' AND 1 = 0' : ' AND ( ' . implode( ' OR ', $per_type ) . ' )';

	if ( '' !== $block ) {
		// WordPress writes core blocks without their namespace
		// (<!-- wp:paragraph -->), everything else with it, and always a
		// space after the name, also before "/-->". LIKE searches the
		// whole content, so nested blocks are found as well. has_block()
		// makes the same string test afterwards.
		$names = array( $block );
		if ( 0 === strpos( $block, 'core/' ) ) {
			$names[] = substr( $block, 5 );
		}
		$likes = array();
		foreach ( $names as $name ) {
			$likes[] = $wpdb->prepare( "{$wpdb->posts}.post_content LIKE %s", '%' . $wpdb->esc_like( '<!-- wp:' . $name . ' ' ) . '%' );
		}
		$where .= ' AND ( ' . implode( ' OR ', $likes ) . ' )';
	}

	return $where;
}

/**
 * List content with light metadata.
 *
 * Only what the agent may read: post types within the connector's scope,
 * statuses it may see per type, and password-protected posts only where
 * it could edit them. All of that goes into the query, so a page holds
 * per_page items and total counts what can actually be listed.
 *
 * @param array $args { post_type, status, search, uses_block, per_page, page }.
 * @return array|\WP_Error
 */
function wpmcp_list_content( array $args ) {
	$per_page = min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
	$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
	$allowed  = wpmcp_allowed_post_types();

	$types = $allowed;
	if ( ! empty( $args['post_type'] ) ) {
		$asked = array_filter( array_map( 'trim', is_array( $args['post_type'] ) ? $args['post_type'] : explode( ',', (string) $args['post_type'] ) ) );
		$types = array_values( array_intersect( $asked, $allowed ) );
		if ( empty( $types ) ) {
			return new \WP_Error(
				'wpmcp_forbidden_type',
				sprintf(
					'Post type "%s" is not exposed to the connector. Listable here: %s.',
					implode( ', ', $asked ),
					implode( ', ', $allowed )
				)
			);
		}
	}

	$statuses = wpmcp_listable_statuses();
	if ( ! empty( $args['status'] ) ) {
		$asked    = array_filter( array_map( 'trim', is_array( $args['status'] ) ? $args['status'] : explode( ',', (string) $args['status'] ) ) );
		$statuses = array_values( array_intersect( $asked, wpmcp_listable_statuses() ) );
		if ( count( $statuses ) !== count( $asked ) ) {
			return new \WP_Error(
				'wpmcp_bad_status',
				sprintf(
					'Status "%s" cannot be listed. Use one of: %s.',
					implode( ', ', array_diff( $asked, wpmcp_listable_statuses() ) ),
					implode( ', ', wpmcp_listable_statuses() )
				)
			);
		}
	}

	$visibility = array();
	foreach ( $types as $type ) {
		$rule               = wpmcp_list_visibility( $type );
		$rule['statuses']   = array_values( array_intersect( $rule['statuses'], $statuses ) );
		$visibility[ $type ] = $rule;
	}

	$block = trim( (string) ( $args['uses_block'] ?? '' ) );
	if ( '' !== $block && false === strpos( $block, '/' ) ) {
		$block = 'core/' . $block;
	}

	$query_args = array(
		'post_type'      => $types,
		'post_status'    => $statuses,
		'posts_per_page' => $per_page,
		'paged'          => $page,
		'orderby'        => 'modified',
		'order'          => 'DESC',
	);

	if ( ! empty( $args['search'] ) ) {
		$query_args['s'] = (string) $args['search'];
	}

	$where = wpmcp_list_where( $visibility, $block );
	$query = new \WP_Query();

	// Scoped to this one query object, so nothing else on the request is
	// narrowed by it, and removed again whatever happens.
	$narrow = function ( $sql, $q ) use ( $query, $where ) {
		return $q === $query ? $sql . $where : $sql;
	};
	add_filter( 'posts_where', $narrow, 10, 2 );
	try {
		$query->query( $query_args );
	} finally {
		remove_filter( 'posts_where', $narrow, 10 );
	}

	$items = array();

	foreach ( $query->posts as $post ) {
		// The query already drew the line; this is the same check a single
		// read makes, so a list can never show more than a read would.
		if ( is_wp_error( wpmcp_get_readable_post( $post->ID ) ) ) {
			continue;
		}
		if ( '' !== $block && ! has_block( $block, $post ) ) {
			continue;
		}

		$items[] = array(
			'id'       => $post->ID,
			'title'    => get_the_title( $post ),
			'type'     => $post->post_type,
			'status'   => $post->post_status,
			'slug'     => $post->post_name,
			'url'      => get_permalink( $post ),
			'parent'   => $post->post_parent,
			'modified' => $post->post_modified_gmt,
			'blocks'   => wpmcp_count_blocks_in_markup( $post->post_content ),
		);
	}

	return array(
		'items' => $items,
		'total' => (int) $query->found_posts,
		'pages' => (int) $query->max_num_pages,
		'page'  => $page,
	);
}

/**
 * Read a post as a block tree.
 *
 * @param int    $post_id          Post ID.
 * @param string $mode             'outline' | 'full' | 'subtree'.
 * @param string $path             Path when mode is 'subtree'.
 * @param bool   $include_defaults Keep default-valued attributes.
 * @return array|\WP_Error
 */
function wpmcp_read_content( $post_id, $mode = 'outline', $path = '', $include_defaults = false, array $paths = array(), $with_meta = false ) {
	$post = wpmcp_get_readable_post( $post_id );
	if ( is_wp_error( $post ) ) {
		return $post;
	}

	$blocks = parse_blocks( $post->post_content );

	$result = array(
		'id'         => $post->ID,
		'title'      => get_the_title( $post ),
		'type'       => $post->post_type,
		'status'     => $post->post_status,
		'url'        => get_permalink( $post ),
		// A draft's permalink is only ?page_id=…, so the slug has to be
		// stated rather than left to be derived from the URL.
		'slug'       => $post->post_name,
		'parent'     => (int) $post->post_parent,
		'blockCount' => wpmcp_count_blocks( $blocks ),
		'mode'       => $mode,
		// Hand this back in content-write to be told if someone edited the
		// page in the meantime, instead of silently overwriting them.
		'modified'   => $post->post_modified_gmt,
	);

	if ( $with_meta ) {
		$result['meta'] = wpmcp_read_meta( $post );
	}

	if ( 'outline' === $mode ) {
		$result['outline'] = wpmcp_blocks_to_outline( $blocks );
		return $result;
	}

	if ( 'subtree' === $mode ) {
		// One path or many: reading fifteen sections one request at a time
		// is a lot of round trips for no reason.
		$wanted = ! empty( $paths ) ? $paths : array( $path );
		$trees  = array();

		foreach ( $wanted as $one ) {
			$segments = wpmcp_path_parse( $one );
			if ( null === $segments || empty( $segments ) ) {
				return new \WP_Error( 'wpmcp_bad_path', sprintf( 'Mode "subtree" needs a path like "2" or "2.0.1", got "%s".', (string) $one ) );
			}
			$node = wpmcp_blocks_at_path( $blocks, $segments );
			if ( null === $node ) {
				return new \WP_Error( 'wpmcp_path_not_found', sprintf( 'Path "%s" does not exist in post %d.', (string) $one, $post->ID ) );
			}
			$prefix = $segments;
			$index  = array_pop( $prefix );
			$trees  = array_merge(
				$trees,
				wpmcp_blocks_to_tree( array( $node ), $prefix, $include_defaults, $index )
			);
		}

		$result['tree'] = $trees;
		return $result;
	}

	$result['tree'] = wpmcp_blocks_to_tree( $blocks, array(), $include_defaults );

	return $result;
}

/**
 * Render a post for self-inspection: server-rendered HTML plus a signed
 * preview link that works without a login.
 *
 * @param int  $post_id      Post ID.
 * @param bool $include_html Whether to return the rendered HTML.
 * @return array|\WP_Error
 */
function wpmcp_preview_content( $post_id, $include_html = true, $offset = 0 ) {
	$post = wpmcp_get_readable_post( $post_id );
	if ( is_wp_error( $post ) ) {
		return $post;
	}

	$preview = wpmcp_preview_url( $post->ID );

	$result = array(
		'id'          => $post->ID,
		'title'       => get_the_title( $post ),
		'status'      => $post->post_status,
		'previewUrl'  => $preview['url'],
		'expiresAt'   => gmdate( 'c', $preview['expires'] ),
		'permalink'   => get_permalink( $post ),
	);

	if ( $include_html ) {
		$rendered = wpmcp_render_post_html( $post, $offset );
		if ( is_wp_error( $rendered ) ) {
			$result['renderError'] = $rendered->get_error_message();
		} else {
			foreach ( array( 'html', 'headings', 'bytes', 'offset', 'truncated', 'nextOffset', 'note', 'debug' ) as $key ) {
				if ( isset( $rendered[ $key ] ) ) {
					$result[ $key ] = $rendered[ $key ];
				}
			}
			if ( ! empty( $rendered['notices'] ) ) {
				$result['renderNotices'] = $rendered['notices'];
			}
		}
	}

	wpmcp_log(
		'wpmcp/content-preview',
		array(
			'post_id'   => $post->ID,
			'operation' => 'preview',
			'summary'   => 'Preview link issued.',
		)
	);

	return $result;
}

/**
 * Render post content through the block renderer, capped in size, plus the
 * heading outline — enough for the AI to check its own work structurally.
 *
 * @param \WP_Post $post   Post.
 * @param int      $offset Byte to start the markup window at.
 * @return array|\WP_Error
 */
function wpmcp_render_post_html( $post, $offset = 0 ) {
	$timer = wpmcp_debug_timer();

	// One render: the check's result is the preview. Rendering again for
	// the answer doubled the cost of every preview, and a block that
	// echoes would have printed into the response outside any buffer.
	$smoke = wpmcp_render_smoke_test( $post->post_content );
	if ( is_wp_error( $smoke ) ) {
		return $smoke;
	}

	$html = do_shortcode( $smoke['html'] );
	wpmcp_debug_mark( $timer, 'render' );

	$headings = array();
	if ( preg_match_all( '/<h([1-6])[^>]*>(.*?)<\/h\1>/is', $html, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			$headings[] = array(
				'level' => (int) $match[1],
				'text'  => wpmcp_shorten( wp_strip_all_tags( $match[2] ), 120 ),
			);
		}
	}

	$slice = wpmcp_slice_text( $html, WPMCP_WINDOW_BYTES, $offset );

	return wpmcp_debug_attach(
		array_merge(
			array(
				'headings' => $headings,
				'notices'  => $smoke['notices'] ?? array(),
			),
			$slice
		),
		$timer
	);
}

/**
 * Bytes per window for content-preview and content-fetch-live.
 *
 * One number for both tools. content-fetch-live used 200000, more than an
 * agent takes in comfortably in one piece and more than three times what
 * content-preview returned for the same page, so the same page came back
 * cut differently depending on which tool asked.
 */
const WPMCP_WINDOW_BYTES = 60000;

/**
 * Cut a long text down to a window the caller can walk through.
 *
 * A comment in the middle of the markup saying it stopped there is easy to
 * miss and impossible to act on — a legal page was read, silently halved,
 * and judged on the half. The size and the next offset are fields, so
 * being cut off is a fact the caller can see and answer.
 *
 * @param string $text   Full text.
 * Offsets stay byte offsets, but both ends of a window sit on a character
 * boundary (wpmcp_utf8_boundary): a window ends before a character it
 * cannot hold whole, the next one starts with it, and an offset sent from
 * inside a character starts at that character. "offset" in the result is
 * where the window really begins.
 *
 * @param int    $max    Bytes to return at most.
 * @param int    $offset Byte to start at.
 * @return array { html: string, bytes: int, offset: int, truncated: bool, nextOffset?: int }
 */
function wpmcp_slice_text( $text, $max, $offset = 0 ) {
	$text   = (string) $text;
	$total  = strlen( $text );
	$offset = wpmcp_utf8_boundary( $text, (int) $offset );
	$slice  = wpmcp_utf8_cut( $text, $offset, $max );

	$result = array(
		'html'      => $slice,
		'bytes'     => $total,
		'offset'    => $offset,
		'truncated' => ( $offset + strlen( $slice ) ) < $total,
	);

	if ( $result['truncated'] ) {
		$result['nextOffset'] = $offset + strlen( $slice );
		$result['note']       = sprintf(
			'%d of %d bytes. Call again with offset: %d for the next part.',
			strlen( $slice ),
			$total,
			$result['nextOffset']
		);
	}

	return $result;
}
