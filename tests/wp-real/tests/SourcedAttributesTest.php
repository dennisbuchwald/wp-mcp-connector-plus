<?php
/**
 * Attributes a block reads from its markup, against WordPress 6.9.8.
 *
 * On staging.maxport.ch a core/shortcode was sent as attrs.text with no
 * "html"; the dry run said ok and the stored block was empty, because
 * WordPress reads "text" from the markup (source raw), never from the
 * comment. tests/sourced-attributes.php proves the decisions against
 * stand-ins; this proves them against the real block.json definitions,
 * the real ability and what the page renders.
 *
 * @package wp-mcp-connector-plus
 */

class SourcedAttributesTest extends WPMCP_Real_TestCase {

	public function set_up() {
		parent::set_up();
		$this->set_level( 'draft' );
		add_shortcode(
			'contact-form-7',
			function ( $atts ) {
				return '<form class="wpcf7" data-id="' . (int) ( $atts['id'] ?? 0 ) . '"></form>';
			}
		);
	}

	public function tear_down() {
		remove_shortcode( 'contact-form-7' );
		parent::tear_down();
	}

	/**
	 * The facts the decision rests on, from WordPress's own block.json.
	 */
	public function test_how_wordpress_defines_the_attributes() {
		$registry = WP_Block_Type_Registry::get_instance();
		$this->assertSame( 'raw', $registry->get_registered( 'core/shortcode' )->attributes['text']['source'] );
		$this->assertArrayNotHasKey( 'selector', $registry->get_registered( 'core/shortcode' )->attributes['text'] );
		$this->assertSame( 'raw', $registry->get_registered( 'core/html' )->attributes['content']['source'] );
		$this->assertSame( 'rich-text', $registry->get_registered( 'core/paragraph' )->attributes['content']['source'] );
		$this->assertSame( 'p', $registry->get_registered( 'core/paragraph' )->attributes['content']['selector'] );
		$this->assertSame( 'attribute', $registry->get_registered( 'core/image' )->attributes['url']['source'] );
	}

	public function test_a_shortcode_sent_as_text_is_stored_as_its_markup_and_renders() {
		$id        = $this->page( '<!-- wp:paragraph --><p>Kontakt</p><!-- /wp:paragraph -->' );
		$shortcode = '[contact-form-7 id="1"]';
		$this->act_as( $this->agent() );

		$ops = array(
			array(
				'op'    => 'insert',
				'path'  => '1',
				'block' => array(
					'name'  => 'core/shortcode',
					'attrs' => array( 'text' => $shortcode ),
				),
			),
		);

		$dry = $this->execute( 'wpmcp/content-write', array( 'post_id' => $id, 'ops' => $ops ) );
		$this->assertFalse( $this->refused( $dry ), $this->explain( $dry ) );
		$this->assertSame( array( array( 'path' => 'op0.0', 'block' => 'core/shortcode', 'attribute' => 'text' ) ), $dry['markupGenerated'] ?? null, $this->explain( $dry ) );

		$result = $this->execute( 'wpmcp/content-write', array( 'post_id' => $id, 'ops' => $ops, 'dry_run' => false ) );
		$this->assertFalse( $this->refused( $result ), $this->explain( $result ) );

		$stored = $this->stored( $id );
		$this->assertStringContainsString( '<!-- wp:shortcode -->' . $shortcode . '<!-- /wp:shortcode -->', $stored );
		$this->assertStringNotContainsString( '"text"', $stored, 'nothing in the comment' );
		$this->assertStringContainsString( '<form class="wpcf7" data-id="1"></form>', do_shortcode( do_blocks( $stored ) ), 'and the form renders' );
	}

	public function test_a_paragraph_sent_as_content_only_is_refused_with_the_markup_to_send() {
		$id     = $this->page( '<!-- wp:paragraph --><p>Vorher</p><!-- /wp:paragraph -->' );
		$before = $this->stored( $id );
		$this->act_as( $this->agent() );

		$tree   = array(
			array( 'name' => 'core/paragraph', 'html' => '<p>Vorher</p>' ),
			array( 'name' => 'core/paragraph', 'attrs' => array( 'content' => 'Neuer Absatz' ) ),
		);
		$result = $this->execute( 'wpmcp/content-write', array( 'post_id' => $id, 'tree' => $tree, 'dry_run' => false ) );
		$this->assertTrue( $this->refused( $result ), $this->explain( $result ) );
		$this->assertSame( 'wpmcp_sourced_attribute', $result['code'] ?? null, $this->explain( $result ) );
		$this->assertStringContainsString( '<p>Neuer Absatz</p>', implode( ' ', $result['errors'] ) );
		$this->assertSame( $before, $this->stored( $id ), 'nothing stored' );

		$ops = array( array( 'op' => 'insert', 'path' => '1', 'block' => $tree[1] ) );
		$op  = $this->execute( 'wpmcp/content-write', array( 'post_id' => $id, 'ops' => $ops ) );
		$this->assertTrue( is_wp_error( $op ), $this->explain( $op ) );
		$this->assertSame( 'wpmcp_sourced_attribute', $op->get_error_code() );

		$set = $this->execute( 'wpmcp/content-write', array( 'post_id' => $id, 'ops' => array( array( 'op' => 'set_attrs', 'path' => '0', 'attrs' => array( 'content' => 'x' ) ) ) ) );
		$this->assertTrue( is_wp_error( $set ) && 'wpmcp_sourced_attribute' === $set->get_error_code(), $this->explain( $set ) );
	}
}
