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
		$object = wp_using_ext_object_cache() ? 'post cache cleared' : 'not persistent, nothing to flush';
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
 * @param int    $offset       Byte to start the returned markup at.
 * @param string $contains     Only check whether this text is on the page.
 * @param bool   $body_only    Return the main content area alone.
 * @return array|\WP_Error
 */
function wpmcp_fetch_live( $post_id, $cache_buster = true, $offset = 0, $contains = '', $body_only = false ) {
	$post = wpmcp_get_readable_post( $post_id );
	if ( is_wp_error( $post ) ) {
		return $post;
	}

	// Only a public page has a version a visitor receives. Fetching a
	// draft or a password-protected page from outside returns a login
	// screen, a 404 or the password form, and reads like a broken page.
	if ( ! wpmcp_post_is_public( $post ) ) {
		return new \WP_Error(
			'wpmcp_not_public',
			sprintf(
				'Post %d is not public (%s), so there is no page a visitor could receive. content-preview renders the stored version and returns a signed preview link.',
				$post->ID,
				'' !== (string) $post->post_password ? 'password protected' : 'status "' . $post->post_status . '"'
			)
		);
	}

	$url = get_permalink( $post );
	if ( ! $url ) {
		return new \WP_Error( 'wpmcp_no_permalink', sprintf( 'Post %d has no public URL.', $post->ID ) );
	}

	if ( $cache_buster ) {
		$url = add_query_arg( 'wpmcp_cb', (string) time(), $url );
	}

	$fetched = wpmcp_fetch_own_url( $url );
	if ( is_wp_error( $fetched ) ) {
		return $fetched;
	}
	$response = $fetched['response'];
	$url      = $fetched['url'];

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

	$full = strlen( $body );
	$head = wpmcp_head_summary( $body );

	wpmcp_log(
		'wpmcp/content-fetch-live',
		array(
			'post_id' => $post->ID,
			'summary' => sprintf( 'Fetched %s (HTTP %d, %d bytes).', $url, $status, $full ),
		)
	);

	$base = array(
		'postId'       => $post->ID,
		'url'          => $url,
		'httpStatus'   => $status,
		'cacheHeaders' => $cache_headers,
		'head'         => $head,
		'source'       => 'the public URL, as a visitor receives it',
	);

	$hint = '' !== $fetched['hint'] ? $fetched['hint'] : wpmcp_fetch_status_hint( $status );
	if ( '' !== $hint ) {
		$base['hint'] = $hint;
	}

	// Most checks after a write are one question — is my change on the
	// page? — and the answer to it used to arrive as 200 KB of HTML that
	// had to be written to disk and searched.
	$contains = (string) $contains;
	if ( '' !== $contains ) {
		$hit  = wpmcp_find_in_page( $body, $contains );
		$main = wpmcp_find_in_page( wpmcp_main_content( $body ), $contains );

		return array_merge(
			$base,
			array(
				'contains'      => $contains,
				'found'         => $hit['found'],
				'count'         => $hit['count'],
				'inMainContent' => $main['found'],
				'snippets'      => $hit['snippets'],
				'bytes'         => $full,
			)
		);
	}

	$scope = $body_only ? wpmcp_main_content( $body ) : $body;

	if ( $body_only ) {
		$base['scope'] = 'main content only: header, footer, styles and scripts removed';
	}

	return array_merge( $base, wpmcp_slice_text( $scope, WPMCP_WINDOW_BYTES, $offset ) );
}

/**
 * Fetch one of this site's own URLs, the way an anonymous visitor would.
 *
 * Through wp_safe_remote_get, which refuses private and loopback addresses
 * unless they are this site's own host. Redirects are followed by hand and
 * only within this site's host: a permalink that redirects elsewhere is a
 * finding to report, not a page to fetch on the server's behalf.
 *
 * @param string $url Absolute URL.
 * @return array{response: array, url: string, hint: string}|\WP_Error
 */
function wpmcp_fetch_own_url( $url ) {
	$home = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	$hint = '';

	for ( $hop = 0; ; $hop++ ) {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'headers'     => array(
					'Cache-Control' => 'no-cache',
					'Pragma'        => 'no-cache',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status   = (int) wp_remote_retrieve_response_code( $response );
		$location = (string) wp_remote_retrieve_header( $response, 'location' );
		if ( $status < 300 || $status >= 400 || '' === $location ) {
			break;
		}

		$next = class_exists( '\WP_Http' ) ? \WP_Http::make_absolute_url( $location, $url ) : $location;
		$host = strtolower( (string) wp_parse_url( $next, PHP_URL_HOST ) );

		if ( $host !== $home ) {
			$hint = sprintf( 'The page redirects to %s, which is not this site, so the redirect was not followed. Check the permalink and any redirect rules.', $next );
			break;
		}
		if ( $hop >= 3 ) {
			$hint = sprintf( 'The page redirected more than three times (last to %s). Check for a redirect loop.', $next );
			break;
		}

		$url = $next;
	}

	return array(
		'response' => $response,
		'url'      => $url,
		'hint'     => $hint,
	);
}

/**
 * What an HTTP status from the site's own page most likely means.
 *
 * @param int $status HTTP status.
 * @return string Empty when there is nothing to say.
 */
function wpmcp_fetch_status_hint( $status ) {
	if ( 401 === $status || 403 === $status ) {
		return 'The site refused an anonymous visitor. Usually something in front of it: password protection on a staging site, a firewall or bot protection, or a coming-soon or maintenance mode. The page itself may be fine; content-preview shows the stored version.';
	}
	if ( 404 === $status ) {
		return 'The site answered 404 for this post\'s own URL. If the slug or a parent changed recently, the permalinks may need flushing.';
	}
	if ( $status >= 500 ) {
		return 'The site answered with a server error. content-preview shows whether the content itself renders; the PHP error log says why the page does not.';
	}
	return '';
}

/**
 * The main content of a page, without what surrounds it.
 *
 * The first <main>, else the first <article>, else the body — and within
 * it no styles and no scripts other than structured data. Those are most
 * of a page's weight and none of what a text change needs checking.
 *
 * @param string $html Delivered HTML.
 * @return string
 */
function wpmcp_main_content( $html ) {
	$patterns = array(
		'#<main\b[^>]*>(.*)</main\s*>#is',
		'#<article\b[^>]*>(.*)</article\s*>#is',
		'#<body\b[^>]*>(.*)</body\s*>#is',
	);

	foreach ( $patterns as $pattern ) {
		if ( preg_match( $pattern, $html, $m ) ) {
			$html = $m[1];
			break;
		}
	}

	$html = preg_replace( '#<style\b[^>]*>.*?</style\s*>#is', '', $html );
	$html = preg_replace( '#<script\b(?![^>]*application/ld\+json)[^>]*>.*?</script\s*>#is', '', (string) $html );

	return (string) $html;
}

/**
 * Where a string occurs in a page, with the text around it.
 *
 * @param string $html   Page or part of it.
 * @param string $needle Text to find, case-insensitive.
 * @param int    $limit  How many snippets to return.
 * @return array{found: bool, count: int, snippets: string[]}
 */
function wpmcp_find_in_page( $html, $needle, $limit = 5 ) {
	$html     = (string) $html;
	$needle   = (string) $needle;
	$snippets = array();
	$count    = 0;

	if ( '' === $needle ) {
		return array( 'found' => false, 'count' => 0, 'snippets' => array() );
	}

	$offset = 0;
	$length = strlen( $needle );

	while ( false !== ( $pos = stripos( $html, $needle, $offset ) ) ) {
		++$count;
		if ( count( $snippets ) < $limit ) {
			// Cut at character boundaries, or a snippet starting in half
			// an umlaut breaks the encoding of the whole response.
			$start      = wpmcp_utf8_boundary( $html, max( 0, $pos - 100 ) );
			$snippets[] = trim( preg_replace( '/\s+/', ' ', wpmcp_utf8_cut( $html, $start, ( $pos - $start ) + $length + 100 ) ) );
		}
		$offset = $pos + $length;
	}

	return array(
		'found'    => $count > 0,
		'count'    => $count,
		'snippets' => $snippets,
	);
}

/**
 * What the delivered page says about itself in its head.
 *
 * Writing a meta title and reading it back from the database only proves
 * the value was stored. Whether the SEO plugin actually puts it in the
 * head is a different question, and the rendered body — which is all
 * content-preview returns — cannot answer it. This can, because it is the
 * page a visitor receives.
 *
 * Parsed with a regular expression on purpose: the head is a handful of
 * flat tags, and loading a DOM parser for them would cost more than it
 * settles.
 *
 * @param string $html Delivered HTML.
 * @return array
 */
function wpmcp_head_summary( $html ) {
	$head     = $html;
	$body     = $html;
	$has_head = false;
	if ( preg_match( '#<head\b[^>]*>(.*?)</head\s*>#is', $html, $m, PREG_OFFSET_CAPTURE ) ) {
		$head     = $m[1][0];
		$body     = substr( $html, $m[0][1] + strlen( $m[0][0] ) );
		$has_head = true;
	}

	$summary = array();

	if ( preg_match( '#<title[^>]*>(.*?)</title\s*>#is', $head, $m ) ) {
		$summary['title'] = wpmcp_head_text( $m[1] );
	}

	// name= and property= both occur; og: and twitter: use property.
	if ( preg_match_all( '#<meta\b[^>]*>#i', $head, $tags ) ) {
		foreach ( $tags[0] as $tag ) {
			if ( ! preg_match( '#(?:name|property)\s*=\s*["\']([^"\']+)["\']#i', $tag, $key ) ) {
				continue;
			}
			if ( ! preg_match( '#content\s*=\s*["\']([^"\']*)["\']#is', $tag, $value ) ) {
				continue;
			}
			$name = strtolower( $key[1] );
			if ( in_array( $name, array( 'description', 'robots', 'og:title', 'og:description', 'og:image', 'twitter:card' ), true ) ) {
				$summary[ $name ] = wpmcp_head_text( $value[1] );
			}
		}
	}

	if ( preg_match( '#<link\b[^>]*rel\s*=\s*["\']canonical["\'][^>]*>#i', $head, $m )
		&& preg_match( '#href\s*=\s*["\']([^"\']+)["\']#i', $m[0], $href ) ) {
		$summary['canonical'] = wpmcp_head_text( $href[1] );
	}

	// Structured data is a yes/no question far more often than a content
	// one. Counted in the head only, an FAQ schema written into the page
	// (a core/html block, which is where content-write puts it) read as 0
	// on the page that carried it. Now both, and the total that answers
	// "is it delivered?". Without a <head> element everything counts as
	// body, so nothing is counted twice.
	$jsonld                  = '#<script\b[^>]*application/ld\+json[^>]*>#i';
	$in_head                 = $has_head ? preg_match_all( $jsonld, $head ) : 0;
	$in_body                 = preg_match_all( $jsonld, $body );
	$summary['jsonLdBlocks'] = $in_head + $in_body;
	$summary['jsonLdInHead'] = $in_head;
	$summary['jsonLdInBody'] = $in_body;

	return $summary;
}

/**
 * Decode and tidy one head value.
 *
 * @param string $value Raw attribute or element text.
 * @return string
 */
function wpmcp_head_text( $value ) {
	return trim( html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES, 'UTF-8' ) );
}
