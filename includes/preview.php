<?php
/**
 * Signed, time-limited preview links for drafts.
 *
 * A standard WP preview URL requires a logged-in session — dead end for an
 * AI client and for customers behind the hidden login. Instead we sign
 * post_id + expiry with the site's auth salt; the frontend gate only
 * engages when the query parameter is present (one isset() per request,
 * zero cost otherwise).
 *
 * @package wp-mcp-connector-plus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const WPMCP_PREVIEW_PARAM = 'wpmcp_preview';
const WPMCP_PREVIEW_TTL   = 900; // 15 minutes.

/**
 * Build the token for a post + expiry timestamp.
 *
 * @param int $post_id Post ID.
 * @param int $expires Unix timestamp.
 * @return string
 */
function wpmcp_preview_token( $post_id, $expires ) {
	return hash_hmac( 'sha256', $post_id . '|' . $expires, wp_salt( 'auth' ) );
}

/**
 * Generate a signed preview URL for a post.
 *
 * @param int $post_id Post ID.
 * @return array { url: string, expires: int }
 */
function wpmcp_preview_url( $post_id ) {
	$expires = time() + WPMCP_PREVIEW_TTL;
	$url     = add_query_arg(
		array(
			'p'                          => (int) $post_id,
			'post_type'                  => get_post_type( $post_id ) ?: 'page',
			WPMCP_PREVIEW_PARAM  => '1',
			'exp'                        => $expires,
			'tok'                        => wpmcp_preview_token( (int) $post_id, $expires ),
		),
		home_url( '/' )
	);
	return array(
		'url'     => $url,
		'expires' => $expires,
	);
}

/**
 * Frontend gate. Registered unconditionally but exits immediately unless
 * the preview parameter is present — this is the only frontend code path
 * of the whole plugin.
 */
function wpmcp_maybe_allow_preview() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- token-signed public preview, no session.
	if ( ! isset( $_GET[ WPMCP_PREVIEW_PARAM ] ) ) {
		return;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$post_id = isset( $_GET['p'] ) ? (int) $_GET['p'] : 0;
	$expires = isset( $_GET['exp'] ) ? (int) $_GET['exp'] : 0;
	$token   = isset( $_GET['tok'] ) ? (string) wp_unslash( $_GET['tok'] ) : '';
	// phpcs:enable

	if ( ! $post_id || ! $expires || '' === $token ) {
		return;
	}
	if ( $expires < time() ) {
		return;
	}
	if ( ! hash_equals( wpmcp_preview_token( $post_id, $expires ), $token ) ) {
		return;
	}

	// Token valid: let the main query return this draft.
	add_filter(
		'posts_results',
		function ( $posts, $query ) use ( $post_id ) {
			if ( ! $query->is_main_query() || ! empty( $posts ) ) {
				return $posts;
			}
			$post = get_post( $post_id );
			if ( $post && in_array( $post->post_status, array( 'draft', 'pending', 'future', 'publish' ), true ) ) {
				$post->post_status = 'publish'; // In-memory only, so templates render normally.
				return array( $post );
			}
			return $posts;
		},
		10,
		2
	);

	// Never let preview URLs leak into search engines.
	add_action(
		'wp_head',
		function () {
			echo '<meta name="robots" content="noindex, nofollow" />' . "\n";
		},
		1
	);
}
add_action( 'init', 'wpmcp_maybe_allow_preview' );
