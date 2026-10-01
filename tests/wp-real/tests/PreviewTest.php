<?php
/**
 * Signed preview links against the real main query.
 *
 * The gate registers on init and hooks posts_results for the main query
 * only. Whether a logged-out visitor then really sees the draft, and only
 * with a valid token, is a question about WP_Query and WP::main().
 *
 * @package wp-mcp-connector-plus
 */

class PreviewTest extends WPMCP_Real_TestCase {

	public function test_a_valid_token_shows_the_draft_to_a_logged_out_visitor() {
		$id = $this->page( '<!-- wp:paragraph --><p>Entwurf</p><!-- /wp:paragraph -->' );

		$this->visit( wpmcp_preview_url( $id )['url'] );

		$this->assertSame( array( $id ), wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) );
		$this->assertTrue( is_singular() );
		$this->assertFalse( is_404() );
	}

	public function test_without_a_token_the_draft_stays_hidden() {
		$id = $this->page( '<!-- wp:paragraph --><p>Entwurf</p><!-- /wp:paragraph -->' );

		$this->visit( add_query_arg( array( 'p' => $id, 'post_type' => 'page' ), home_url( '/' ) ) );

		$this->assertSame( array(), $GLOBALS['wp_query']->posts );
	}

	public function test_a_tampered_token_shows_nothing() {
		$id  = $this->page( '<!-- wp:paragraph --><p>Entwurf</p><!-- /wp:paragraph -->' );
		$url = wpmcp_preview_url( $id )['url'];

		$tok = wp_parse_args( wp_parse_url( $url, PHP_URL_QUERY ) )['tok'];
		$this->visit( add_query_arg( 'tok', strrev( $tok ), $url ) );
		$this->assertSame( array(), $GLOBALS['wp_query']->posts, 'a different token' );

		$other = $this->page( '<!-- wp:paragraph --><p>Anderer Entwurf</p><!-- /wp:paragraph -->' );
		$this->visit( add_query_arg( 'p', $other, $url ) );
		$this->assertSame( array(), $GLOBALS['wp_query']->posts, 'the token of another post' );
	}

	public function test_an_expired_token_shows_nothing() {
		$id      = $this->page( '<!-- wp:paragraph --><p>Entwurf</p><!-- /wp:paragraph -->' );
		$expired = time() - 1;

		$this->visit(
			add_query_arg(
				array(
					'p'                 => $id,
					'post_type'         => 'page',
					WPMCP_PREVIEW_PARAM => '1',
					'exp'               => $expired,
					'tok'               => wpmcp_preview_token( $id, $expired ),
				),
				home_url( '/' )
			)
		);

		$this->assertSame( array(), $GLOBALS['wp_query']->posts );
	}

	public function test_a_private_page_is_never_previewed() {
		$id = $this->page( '<!-- wp:paragraph --><p>Privat</p><!-- /wp:paragraph -->', 'private' );

		$this->visit( wpmcp_preview_url( $id )['url'] );

		$this->assertSame( array(), $GLOBALS['wp_query']->posts );
	}

	/**
	 * Request a URL as a logged-out visitor: the init gate sees the query
	 * string, then WordPress runs its main query for that URL.
	 *
	 * @param string $url URL.
	 */
	private function visit( $url ) {
		wp_set_current_user( 0 );

		$_GET = array();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $_GET );

		$this->assertSame( 10, has_action( 'init', 'wpmcp_maybe_allow_preview' ) );
		wpmcp_maybe_allow_preview();

		$this->go_to( $url );
	}
}
