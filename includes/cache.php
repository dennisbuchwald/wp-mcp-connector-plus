<?php
/**
 * Clearing caches after a write, and reading what a visitor really gets.
 *
 * A write leaves the database correct and the delivered page stale. Any
 * verification against the live URL would then fail for the wrong reason,
 * or — worse — a check against the database would pass while visitors keep
 * seeing the old content.
 *
 * @package wp-mcp-connector-plus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Flush what can be flushed for one post, and say what happened.
 *
 * Deliberately reports failure rather than swallowing it: "cache not
 * clearable" is something the caller must know before trusting a live
 * check.
 *
 * @param int $post_id Post ID.
 * @return array { object: string, page: string, notes: string[] }
 */
function wpmcp_purge_caches( $post_id ) {
	$notes = array();

	// Object cache: always available as an API, not always persistent.
	$object = 'skipped';
	if ( function_exists( 'wp_cache_flush' ) ) {
		clean_post_cache( (int) $post_id );
		$object = wp_using_ext_object_cache() ? 'flushed' : 'not persistent, nothing to flush';
	}

	// Page caches announce themselves through their own hooks and functions.
	$page    = 'no page cache detected';
	$handled = false;

	/**
	 * Fires so a page cache can clear one post.
	 *
	 * @param int $post_id Post that changed.
	 */
	do_action( 'wpmcp_purge_post_cache', (int) $post_id );

	// WP Rocket, LiteSpeed, W3 Total Cache, WP Super Cache, AccelerateWP.
	$candidates = array(
		'rocket_clean_post'          => 'WP Rocket',
		'w3tc_flush_post'            => 'W3 Total Cache',
		'wp_cache_post_change'       => 'WP Super Cache',
		'wpfc_clear_post_cache_by_id' => 'WP Fastest Cache',
	);

	foreach ( $candidates as $callable => $label ) {
		if ( function_exists( $callable ) ) {
			$callable( (int) $post_id );
			$notes[] = sprintf( 'Cleared via %s.', $label );
			$handled = true;
		}
	}

	// LiteSpeed and AccelerateWP (LiteSpeed-based) use an action.
	if ( has_action( 'litespeed_purge_post' ) ) {
		do_action( 'litespeed_purge_post', (int) $post_id );
		$notes[] = 'Cleared via LiteSpeed.';
		$handled = true;
	}

	if ( $handled ) {
		$page = 'purged';
	} elseif ( wpmcp_page_cache_suspected() ) {
		$page    = 'present but not clearable from here';
		$notes[] = 'A page cache appears active but exposes no purge hook this plugin knows. Verify against the live URL with a cache buster, or clear it by hand.';
	}

	return array(
		'object' => $object,
		'page'   => $page,
		'notes'  => $notes,
	);
}

/**
 * Is a page cache plugin active without a purge function we recognise?
 *
 * @return bool
 */
function wpmcp_page_cache_suspected() {
	if ( defined( 'WP_CACHE' ) && WP_CACHE ) {
		return true;
	}

	foreach ( array( 'LSCWP_V', 'WPO_VERSION', 'CACHE_ENABLER_VERSION' ) as $marker ) {
		if ( defined( $marker ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Fetch the public URL of a post and return what was actually delivered.
 *
 * The database is not the page. With a page cache in front, content-read
 * and content-preview say what is stored; only this says what a visitor
 * receives. That difference is the whole point of the tool.
 *
 * @param int  $post_id      Post ID.
 * @param bool $cache_buster Append a unique query parameter.
 * @return array|\WP_Error
 */
function wpmcp_fetch_live( $post_id, $cache_buster = true ) {
	$post = wpmcp_get_readable_post( $post_id );
	if ( is_wp_error( $post ) ) {
		return $post;
	}

	$url = get_permalink( $post );
	if ( ! $url ) {
		return new \WP_Error( 'wpmcp_no_permalink', sprintf( 'Post %d has no public URL.', $post->ID ) );
	}

	if ( $cache_buster ) {
		$url = add_query_arg( 'wpmcp_cb', (string) time(), $url );
	}

	$response = wp_remote_get(
		$url,
		array(
			'timeout'     => 20,
			'redirection' => 3,
			'headers'     => array(
				'Cache-Control' => 'no-cache',
				'Pragma'        => 'no-cache',
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$body   = (string) wp_remote_retrieve_body( $response );
	$status = (int) wp_remote_retrieve_response_code( $response );

	// Header evidence of a cache hit, so a stale answer is recognisable.
	$cache_headers = array();
	foreach ( array( 'x-litespeed-cache', 'x-cache', 'cf-cache-status', 'x-rocket-nginx-serving-static', 'age' ) as $header ) {
		$value = wp_remote_retrieve_header( $response, $header );
		if ( '' !== $value && null !== $value ) {
			$cache_headers[ $header ] = $value;
		}
	}

	$max  = 200000;
	$full = strlen( $body );
	if ( $full > $max ) {
		$body = substr( $body, 0, $max ) . "\n<!-- truncated by wp-mcp-connector-plus -->";
	}

	wpmcp_log(
		'wpmcp/content-fetch-live',
		array(
			'post_id' => $post->ID,
			'summary' => sprintf( 'Fetched %s (HTTP %d, %d bytes).', $url, $status, $full ),
		)
	);

	return array(
		'postId'       => $post->ID,
		'url'          => $url,
		'httpStatus'   => $status,
		'bytes'        => $full,
		'cacheHeaders' => $cache_headers,
		'html'         => $body,
		'source'       => 'the public URL, as a visitor receives it',
	);
}
