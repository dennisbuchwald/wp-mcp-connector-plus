<?php
/**
 * The markup guard against WordPress's own kses.
 *
 * tests/markup-guard.php proves the decision logic against a kses stub.
 * What it cannot prove is that the stub and wp_kses_post agree: every
 * vector here is judged by the real kses, through the real save, by an
 * account holding only the agent role, so the content filter is on for it
 * exactly as on a customer site.
 *
 * @package wp-mcp-connector-plus
 */

class MarkupGuardTest extends WPMCP_Real_TestCase {

	const PARAGRAPH = '<!-- wp:paragraph --><p>Ganz normaler Text</p><!-- /wp:paragraph -->';
	const EMBED     = '<!-- wp:html --><iframe src="https://player.example/1"></iframe><!-- /wp:html -->';

	// GenerateBlocks' inline highlight, as stored on dbw-media.de.
	const MARK      = '<mark style="background-color:rgba(0, 0, 0, 0)" class="has-inline-color has-accent-color">Website</mark>';
	const HIGHLIGHT = '<!-- wp:heading --><h2 class="wp-block-heading">Eine <mark style="background-color:rgba(0, 0, 0, 0)" class="has-inline-color has-accent-color">Website</mark> fuer dich</h2><!-- /wp:heading -->';

	public function set_up() {
		parent::set_up();
		$this->set_level( 'draft' );
	}

	/**
	 * Attribute and URL vectors: the class that went past the guard until
	 * 0.18.3, because none of them is a whole element.
	 */
	public static function attribute_vectors() {
		return array(
			'img onerror'                => array( '<p><img src="x" onerror="alert(1)"></p>' ),
			'img onerror unquoted'       => array( '<p><img src=x onerror=alert(1)></p>' ),
			'onerror after a slash'      => array( '<p><img src="x"/onerror="alert(1)"></p>' ),
			'a href javascript:'         => array( '<p><a href="javascript:alert(1)">x</a></p>' ),
			'javascript: entity-encoded' => array( '<p><a href="&#106;avascript:alert(1)">x</a></p>' ),
			'javascript: hex entity'     => array( '<p><a href="&#x6A;avascript:alert(1)">x</a></p>' ),
			'javascript: with a tab'     => array( "<p><a href=\"java\tscript:alert(1)\">x</a></p>" ),
			'javascript: with &colon;'   => array( '<p><a href="javascript&colon;alert(1)">x</a></p>' ),
			'vbscript:'                  => array( '<p><a href="vbscript:msgbox(1)">x</a></p>' ),
			'data:text/html'             => array( '<p><a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a></p>' ),
			'p onclick'                  => array( '<p onclick="alert(1)">x</p>' ),
			'svg onload'                 => array( '<p><svg onload="alert(1)"></svg></p>' ),
			'details ontoggle'           => array( '<details open ontoggle="alert(1)"><summary>x</summary></details>' ),
			'style url(javascript:)'     => array( '<p style="background:url(javascript:alert(1))">x</p>' ),
		);
	}

	/**
	 * @dataProvider attribute_vectors
	 */
	public function test_attribute_vector_is_refused_and_nothing_stored( $html ) {
		$this->assertNotSame( $html, wp_kses_post( $html ), 'precondition: real kses alters the vector' );

		$id     = $this->page( self::PARAGRAPH . self::EMBED );
		$before = $this->stored( $id );

		$this->act_as( $this->agent() );
		$kses_before = $this->kses_state();
		$this->assertSame( array( 10, 10 ), $kses_before, 'kses is on for the agent account' );

		$result = $this->execute(
			'wpmcp/content-write',
			array(
				'post_id' => $id,
				'dry_run' => false,
				'tree'    => array(
					array( 'name' => null, 'html' => $html ),
					array( 'name' => 'core/html', 'html' => '<iframe src="https://player.example/1"></iframe>' ),
				),
			)
		);

		$this->assertTrue( $this->refused( $result ), 'refused: ' . $this->explain( $result ) );
		$this->assertSame( $before, $this->stored( $id ), 'nothing was saved' );
		$this->assertSame( $kses_before, $this->kses_state(), 'the content filter is back as it was' );
	}

	public function test_existing_embed_survives_an_edit_next_to_it() {
		$id = $this->page( self::PARAGRAPH . self::EMBED );

		$this->act_as( $this->agent() );
		$kses_before = $this->kses_state();

		$result = $this->execute(
			'wpmcp/content-write',
			array(
				'post_id' => $id,
				'dry_run' => false,
				'tree'    => array(
					array( 'name' => 'core/paragraph', 'html' => '<p>Geaenderter Text</p>' ),
					array( 'name' => 'core/html', 'html' => '<iframe src="https://player.example/1"></iframe>' ),
				),
			)
		);

		$this->assertFalse( $this->refused( $result ), $this->explain( $result ) );
		$stored = $this->stored( $id );
		$this->assertStringContainsString( '<iframe src="https://player.example/1"></iframe>', $stored, 'the embed is still stored' );
		$this->assertStringContainsString( 'Geaenderter Text', $stored );
		$this->assertSame( $kses_before, $this->kses_state(), 'the content filter is back as it was' );
	}

	public function test_a_changed_block_holding_an_embed_is_the_agents_block() {
		$id     = $this->page( self::PARAGRAPH . self::EMBED );
		$before = $this->stored( $id );

		$this->act_as( $this->agent() );
		$result = $this->execute(
			'wpmcp/content-write',
			array(
				'post_id' => $id,
				'dry_run' => false,
				'tree'    => array(
					array( 'name' => 'core/paragraph', 'html' => '<p>Ganz normaler Text</p>' ),
					array( 'name' => 'core/html', 'html' => '<iframe src="https://player.example/2"></iframe>' ),
				),
			)
		);

		$this->assertTrue( $this->refused( $result ), 'a new iframe is new markup: ' . $this->explain( $result ) );
		$this->assertSame( $before, $this->stored( $id ) );
	}

	public function test_json_ld_is_stored() {
		$id   = $this->page( self::PARAGRAPH );
		$json = '<script type="application/ld+json">{"@context":"https://schema.org","@type":"FAQPage","mainEntity":[]}</script>';

		$this->act_as( $this->agent() );
		$kses_before = $this->kses_state();

		$result = $this->execute(
			'wpmcp/content-write',
			array(
				'post_id' => $id,
				'dry_run' => false,
				'tree'    => array(
					array( 'name' => 'core/paragraph', 'html' => '<p>Ganz normaler Text</p>' ),
					array( 'name' => 'core/html', 'html' => $json ),
				),
			)
		);

		$this->assertFalse( $this->refused( $result ), $this->explain( $result ) );
		$this->assertStringContainsString( $json, $this->stored( $id ) );
		$this->assertSame( $kses_before, $this->kses_state(), 'the content filter is back as it was' );
	}

	public function test_json_ld_with_an_extra_attribute_is_refused() {
		$id     = $this->page( self::PARAGRAPH );
		$before = $this->stored( $id );

		$this->act_as( $this->agent() );
		$result = $this->execute(
			'wpmcp/content-write',
			array(
				'post_id' => $id,
				'dry_run' => false,
				'tree'    => array(
					array( 'name' => 'core/html', 'html' => '<script type="application/ld+json" onload="x()">{}</script>' ),
				),
			)
		);

		$this->assertTrue( $this->refused( $result ), $this->explain( $result ) );
		$this->assertSame( $before, $this->stored( $id ) );
	}

	/**
	 * Why kses takes the GenerateBlocks inline highlight apart at all.
	 *
	 * safecss_filter_attr() allows background-color, but after the
	 * property check it refuses any declaration whose value still holds
	 * "(" once the functions it knows are taken out: var(), calc(), min(),
	 * max(), minmax(), clamp() and repeat(). rgb() and rgba() are not on
	 * that list (only inside a gradient), so the whole declaration goes,
	 * spaces or not, and the attribute with it.
	 */
	public function test_kses_strips_rgba_in_a_style_attribute() {
		$this->assertSame( '', safecss_filter_attr( 'background-color:rgba(0, 0, 0, 0)' ) );
		$this->assertSame( '', safecss_filter_attr( 'background-color:rgba(0,0,0,0)' ), 'not the spaces' );
		$this->assertSame( '', safecss_filter_attr( 'color:rgb(1,2,3)' ), 'rgb() alike' );
		$this->assertSame( 'background-color:#000', safecss_filter_attr( 'background-color:#000' ), 'the property itself is allowed' );
		$this->assertSame( 'background-color:var(--x)', safecss_filter_attr( 'background-color:var(--x)' ), 'a known function is' );
		$this->assertSame( 'background-image:linear-gradient(rgba(0,0,0,0),#fff)', safecss_filter_attr( 'background-image:linear-gradient(rgba(0,0,0,0),#fff)' ), 'and rgba() inside a gradient is' );

		$this->assertSame(
			'<mark class="has-inline-color has-accent-color">Website</mark>',
			wp_kses_post( '<mark style="background-color:rgba(0, 0, 0, 0)" class="has-inline-color has-accent-color">Website</mark>' ),
			'so the attribute goes and the element stays'
		);
	}

	/**
	 * The field report: a patch_html around a highlight that was already
	 * in the heading, passed through unchanged.
	 */
	public function test_markup_already_in_the_block_may_stay_when_the_block_changes() {
		$id = $this->page( self::HIGHLIGHT . self::PARAGRAPH );

		$this->act_as( $this->agent() );
		$result = $this->execute(
			'wpmcp/content-write',
			array(
				'post_id' => $id,
				'dry_run' => false,
				'ops'     => array(
					array(
						'op'      => 'patch_html',
						'path'    => '0',
						'find'    => 'Eine ' . self::MARK . ' fuer dich',
						'replace' => 'Deine neue ' . self::MARK . ' fuer Handwerker',
					),
				),
			)
		);

		$this->assertFalse( $this->refused( $result ), $this->explain( $result ) );
		$stored = $this->stored( $id );
		$this->assertStringContainsString( 'Deine neue ' . self::MARK . ' fuer Handwerker', $stored, 'the highlight keeps its style' );
		$this->assertStringContainsString( 'style="background-color:rgba(0, 0, 0, 0)"', implode( ' ', $result['warnings'] ), 'and the answer says it was preserved' );
	}

	public function test_a_new_style_kses_strips_is_still_refused() {
		$id     = $this->page( self::HIGHLIGHT . self::PARAGRAPH );
		$before = $this->stored( $id );

		$this->act_as( $this->agent() );
		$result = $this->patch( $id, 'Eine ' . self::MARK, 'Eine <mark style="background-color:rgba(255, 0, 0, 1)" class="has-inline-color">Seite</mark>' );

		$this->assertTrue( $this->refused( $result ), $this->explain( $result ) );
		$message = $this->errors_of( $result );
		$this->assertStringContainsString( 'style="background-color:rgba(255, 0, 0, 1)"', $message, 'the message quotes the fragment' );
		$this->assertStringContainsString( 'new', $message );
		$this->assertSame( $before, $this->stored( $id ) );
	}

	public function test_a_new_event_handler_next_to_an_existing_style_is_refused() {
		$id     = $this->page( self::HIGHLIGHT . self::PARAGRAPH );
		$before = $this->stored( $id );

		$this->act_as( $this->agent() );
		$result = $this->patch( $id, 'fuer dich', 'fuer dich <img src="x" onerror="alert(1)">' );

		$this->assertTrue( $this->refused( $result ), $this->explain( $result ) );
		$this->assertStringContainsString( 'onerror="alert(1)"', $this->errors_of( $result ) );
		$this->assertSame( $before, $this->stored( $id ) );
	}

	public function test_copying_an_existing_fragment_to_more_places_is_refused() {
		$id     = $this->page( self::HIGHLIGHT . self::PARAGRAPH );
		$before = $this->stored( $id );

		$this->act_as( $this->agent() );

		// Twice in the same block.
		$result = $this->patch( $id, 'fuer dich', 'fuer dich und ' . self::MARK );
		$this->assertTrue( $this->refused( $result ), $this->explain( $result ) );
		$this->assertStringContainsString( 'already in this block', $this->errors_of( $result ) );
		$this->assertSame( $before, $this->stored( $id ) );

		// Once more in another block, the first one left in place.
		$result = $this->execute(
			'wpmcp/content-write',
			array(
				'post_id' => $id,
				'dry_run' => false,
				'ops'     => array(
					array(
						'op'      => 'patch_html',
						'path'    => '1',
						'find'    => 'Ganz normaler Text',
						'replace' => 'Ganz normaler ' . self::MARK,
					),
				),
			)
		);
		$this->assertTrue( $this->refused( $result ), $this->explain( $result ) );
		$this->assertSame( $before, $this->stored( $id ) );
	}

	/**
	 * The refusal's messages, without the rest of the answer (which
	 * quotes the patched block and would match anything in it).
	 *
	 * @param mixed $result Ability result.
	 * @return string
	 */
	private function errors_of( $result ) {
		return is_wp_error( $result ) ? $result->get_error_message() : implode( ' ', (array) ( $result['errors'] ?? array() ) );
	}

	/**
	 * One patch_html on block 0 of a page, for real.
	 *
	 * @param int    $id      Post ID.
	 * @param string $find    Text to find.
	 * @param string $replace Replacement.
	 * @return mixed
	 */
	private function patch( $id, $find, $replace ) {
		return $this->execute(
			'wpmcp/content-write',
			array(
				'post_id' => $id,
				'dry_run' => false,
				'ops'     => array(
					array(
						'op'      => 'patch_html',
						'path'    => '0',
						'find'    => $find,
						'replace' => $replace,
					),
				),
			)
		);
	}

	/**
	 * Where kses sits: the priority on each of its two filters, false
	 * when it is not there.
	 *
	 * @return array
	 */
	private function kses_state() {
		return array(
			has_filter( 'content_save_pre', 'wp_filter_post_kses' ),
			has_filter( 'content_filtered_save_pre', 'wp_filter_post_kses' ),
		);
	}
}
