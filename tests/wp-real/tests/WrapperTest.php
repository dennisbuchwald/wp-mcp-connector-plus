<?php
/**
 * Containers sent without their wrapper markup, against WordPress 6.9.8
 * with GenerateBlocks 2.4.1 active.
 *
 * tests/wrappers.php proves the decisions against stand-ins and the
 * generated markup against what the block editor saves. This proves the
 * part a stand-in cannot: how the blocks are really registered, that a
 * write through the real ability stores the wrapper, and that the page a
 * visitor gets has it.
 *
 * @package wp-mcp-connector-plus
 */

class WrapperTest extends WPMCP_Real_TestCase {

	public function set_up() {
		parent::set_up();
		$this->set_level( 'draft' );
	}

	/**
	 * The facts the decision rests on. If GenerateBlocks or WordPress
	 * change them, this is the test that should say so first.
	 */
	public function test_how_the_blocks_are_registered() {
		$registry = WP_Block_Type_Registry::get_instance();

		$element = $registry->get_registered( 'generateblocks/element' );
		$this->assertSame( '2.4.1', GENERATEBLOCKS_VERSION );
		$this->assertSame( array( 'GenerateBlocks_Block_Element', 'render_block' ), $element->render_callback, 'GenerateBlocks gives the element a render callback' );
		$this->assertFalse( $element->supports['className'], 'and no generated wp-block-* class' );
		$this->assertArrayHasKey( 'className', $element->attributes, 'but a custom class name' );

		// The callback adds CSS to the saved markup and nothing else: what
		// it gets is what it returns, when the block has no styles.
		$saved = '<section class="x"><p>a</p></section>';
		$this->assertSame( $saved, GenerateBlocks_Block_Element::render_block( array( 'tagName' => 'section' ), $saved, null ), 'the render callback does not build the wrapper' );

		foreach ( array( 'core/group', 'core/columns', 'core/column', 'core/buttons' ) as $name ) {
			$this->assertNull( $registry->get_registered( $name )->render_callback, "{$name} is saved statically" );
		}
		foreach ( array( 'core/cover', 'core/list', 'core/media-text' ) as $name ) {
			$this->assertNotEmpty( $registry->get_registered( $name )->render_callback, "{$name} has a render callback and still keeps its wrapper in the saved markup" );
		}
	}

	/**
	 * The write from the field report: a section with a head and a grid
	 * of three cards, every container a GenerateBlocks element, none of
	 * them with a template.
	 */
	public function test_generateblocks_section_keeps_every_wrapper() {
		$id = $this->page( '<!-- wp:paragraph --><p>Vorher</p><!-- /wp:paragraph -->' );

		$card = function ( $title ) {
			return array(
				'name'        => 'generateblocks/element',
				'attrs'       => array( 'tagName' => 'div', 'className' => 'problem-card' ),
				'innerBlocks' => array(
					array( 'name' => 'core/heading', 'attrs' => array( 'level' => 3 ), 'html' => '<h3 class="wp-block-heading">' . $title . '</h3>' ),
					array( 'name' => 'core/paragraph', 'html' => '<p>Text</p>' ),
				),
			);
		};

		$this->act_as( $this->agent() );
		$result = $this->execute(
			'wpmcp/content-write',
			array(
				'post_id' => $id,
				'dry_run' => false,
				'ops'     => array(
					array(
						'op'    => 'insert',
						'path'  => '1',
						'block' => array(
							'name'        => 'generateblocks/element',
							'attrs'       => array( 'tagName' => 'section', 'globalClasses' => array( 'section--fullwidth' ), 'className' => 'problems' ),
							'innerBlocks' => array(
								array(
									'name'        => 'generateblocks/element',
									'attrs'       => array( 'tagName' => 'div', 'className' => 'problems__head' ),
									'innerBlocks' => array(
										array( 'name' => 'core/heading', 'html' => '<h2 class="wp-block-heading">Probleme</h2>' ),
										array( 'name' => 'core/paragraph', 'html' => '<p>Kennst du das?</p>' ),
									),
								),
								array(
									'name'        => 'generateblocks/element',
									'attrs'       => array( 'tagName' => 'div', 'className' => 'problems__grid' ),
									'innerBlocks' => array( $card( 'Eins' ), $card( 'Zwei' ), $card( 'Drei' ) ),
								),
							),
						),
					),
				),
			)
		);

		$this->assertFalse( $this->refused( $result ), $this->explain( $result ) );
		$this->assertCount( 6, $result['wrapperGenerated'] ?? array(), 'every wrapper is reported: ' . $this->explain( $result ) );
		$this->assertStringContainsString( 'generated from its attributes', implode( ' ', $result['warnings'] ) );

		$stored   = $this->stored( $id );
		$rendered = do_blocks( $stored );

		foreach ( array( 'stored' => $stored, 'rendered' => $rendered ) as $label => $html ) {
			$this->assertSame( 1, substr_count( $html, '<section class="section--fullwidth problems">' ), "{$label}: the section" );
			$this->assertSame( 1, substr_count( $html, '<div class="problems__head">' ), "{$label}: the head" );
			$this->assertSame( 1, substr_count( $html, '<div class="problems__grid">' ), "{$label}: the grid" );
			$this->assertSame( 3, substr_count( $html, '<div class="problem-card">' ), "{$label}: three cards" );
		}

		// Read back, the tree carries the wrappers as templates, so the
		// next write sends them on.
		$read = $this->execute( 'wpmcp/content-read', array( 'post_id' => $id, 'mode' => 'subtree', 'path' => '1' ) );
		$this->assertStringContainsString( 'section--fullwidth problems', wp_json_encode( $read ), 'content-read shows the wrapper' );
	}

	public function test_group_without_template_keeps_its_wrapper() {
		$id = $this->page( '<!-- wp:paragraph --><p>Vorher</p><!-- /wp:paragraph -->' );

		$this->act_as( $this->agent() );
		$result = $this->execute(
			'wpmcp/content-write',
			array(
				'post_id' => $id,
				'dry_run' => false,
				'tree'    => array(
					array(
						'name'        => 'core/group',
						'attrs'       => array( 'tagName' => 'section', 'className' => 'intro', 'layout' => array( 'type' => 'constrained' ) ),
						'innerBlocks' => array(
							array( 'name' => 'core/paragraph', 'html' => '<p>Eins</p>' ),
							array( 'name' => 'core/paragraph', 'html' => '<p>Zwei</p>' ),
						),
					),
				),
			)
		);

		$this->assertFalse( $this->refused( $result ), $this->explain( $result ) );
		$this->assertSame( '0', $result['wrapperGenerated'][0]['path'] ?? null );

		$stored = $this->stored( $id );
		$this->assertStringContainsString( '<section class="wp-block-group intro"><!-- wp:paragraph -->', $stored );
		$this->assertStringContainsString( '<!-- /wp:paragraph --></section>', $stored );

		// WordPress adds the layout classes when rendering, to the wrapper
		// it finds in the saved markup (on the test site's classic theme
		// inside the inner container it restores there). Without a wrapper
		// there was nothing to add them to.
		$rendered = do_blocks( $stored );
		$this->assertStringContainsString( '<section class="wp-block-group intro">', $rendered );
		$this->assertStringContainsString( 'is-layout-constrained', $rendered );
	}

	public function test_static_block_without_wrapper_is_refused_with_a_template() {
		$id     = $this->page( '<!-- wp:paragraph --><p>Vorher</p><!-- /wp:paragraph -->' );
		$before = $this->stored( $id );

		$columns = array(
			'name'        => 'core/columns',
			'innerBlocks' => array(
				array(
					'name'        => 'core/column',
					'innerBlocks' => array( array( 'name' => 'core/paragraph', 'html' => '<p>x</p>' ) ),
				),
			),
		);

		$this->act_as( $this->agent() );
		$result = $this->execute(
			'wpmcp/content-write',
			array(
				'post_id' => $id,
				'dry_run' => true,
				'tree'    => array( $columns ),
			)
		);

		$this->assertTrue( $this->refused( $result ) );
		$this->assertSame( 'wpmcp_wrapper_missing', $result['code'] ?? null, $this->explain( $result ) );
		$this->assertStringContainsString( '"htmlTemplate": ["\n<div class=\"wp-block-columns\">",null,"</div>\n"]', implode( ' ', $result['errors'] ) );

		$result = $this->execute(
			'wpmcp/content-write',
			array(
				'post_id' => $id,
				'dry_run' => false,
				'ops'     => array(
					array(
						'op'    => 'insert',
						'path'  => '1',
						'block' => $columns,
					),
				),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wpmcp_wrapper_missing', $result->get_error_code() );
		$this->assertStringStartsWith( '[wpmcp_wrapper_missing] ', $result->get_error_message() );
		$this->assertSame( $before, $this->stored( $id ), 'nothing was saved' );
	}

	/**
	 * Evidence from another page: a published one with the block decides,
	 * and its wrapper is what the refusal proposes.
	 */
	public function test_an_instance_on_a_published_page_is_the_evidence() {
		$this->page( '<!-- wp:buttons {"className":"cta"} --><div class="wp-block-buttons cta"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Los</a></div><!-- /wp:button --></div><!-- /wp:buttons -->', 'publish' );
		$id = $this->page( '<!-- wp:paragraph --><p>Vorher</p><!-- /wp:paragraph -->' );

		$this->act_as( $this->agent() );
		$result = $this->execute(
			'wpmcp/content-write',
			array(
				'post_id' => $id,
				'dry_run' => true,
				'tree'    => array(
					array(
						'name'        => 'core/buttons',
						'attrs'       => array( 'className' => 'neu' ),
						'innerBlocks' => array(
							array( 'name' => 'core/button', 'html' => '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Neu</a></div>' ),
						),
					),
				),
			)
		);

		$this->assertSame( 'wpmcp_wrapper_missing', $result['code'] ?? null, $this->explain( $result ) );
		$message = implode( ' ', $result['errors'] );
		$this->assertMatchesRegularExpression( '/the one in post \d+ at 0 has markup around its children/', $message );
		$this->assertStringContainsString( '["\n<div class=\"wp-block-buttons neu\">",null,"</div>\n"]', $message );
	}

	/**
	 * A block that renders on the server and saves its children only
	 * keeps working without a template, the way the dbw-base kit does.
	 */
	public function test_dynamic_children_only_block_needs_no_template() {
		register_block_type(
			'wpmcp-test/stack',
			array(
				'render_callback' => function ( $attributes, $content ) {
					return '<div class="stack">' . $content . '</div>';
				},
			)
		);

		try {
			$id   = $this->page( '<!-- wp:paragraph --><p>Vorher</p><!-- /wp:paragraph -->' );
			$tree = array(
				array(
					'name'        => 'wpmcp-test/stack',
					'innerBlocks' => array( array( 'name' => 'core/paragraph', 'html' => '<p>Kind</p>' ) ),
				),
			);

			$this->act_as( $this->agent() );
			$result = $this->execute( 'wpmcp/content-write', array( 'post_id' => $id, 'dry_run' => true, 'tree' => $tree ) );
			$this->assertFalse( $this->refused( $result ), $this->explain( $result ) );
			$this->assertStringContainsString( 'renders on the server', implode( ' ', $result['warnings'] ), 'without evidence it says what it assumed' );

			// With a published instance saved as children only, nothing
			// is left to assume.
			$this->page( '<!-- wp:wpmcp-test/stack --><!-- wp:paragraph --><p>Alt</p><!-- /wp:paragraph --><!-- /wp:wpmcp-test/stack -->', 'publish' );
			$result = $this->execute( 'wpmcp/content-write', array( 'post_id' => $id, 'dry_run' => false, 'tree' => $tree ) );
			$this->assertFalse( $this->refused( $result ), $this->explain( $result ) );
			$this->assertStringNotContainsString( 'htmlTemplate', implode( ' ', $result['warnings'] ) );
			$this->assertSame( '<!-- wp:wpmcp-test/stack --><!-- wp:paragraph --><p>Kind</p><!-- /wp:paragraph --><!-- /wp:wpmcp-test/stack -->', $this->stored( $id ) );
		} finally {
			unregister_block_type( 'wpmcp-test/stack' );
		}
	}
}
