<?php
/**
 * Keys and arguments the connector does not know, against WordPress 6.9.8.
 *
 * On staging.maxport.ch a shortcode sent with "attributes" (the name
 * blocks-describe uses) instead of "attrs" was saved empty after a dry
 * run that said ok, and content-duplicate dropped a "slug" it does not
 * take. The Abilities API validates input against the schema, but a
 * schema without additionalProperties lets every unknown property
 * through, and with it the refusal would read "slug is not a valid
 * property of Object", without our code and without what is accepted.
 * So the connector checks, at the one place every ability passes.
 *
 * @package wp-mcp-connector-plus
 */

class UnknownKeysTest extends WPMCP_Real_TestCase {

	public function set_up() {
		parent::set_up();
		$this->set_level( 'draft' );
	}

	public function test_a_shortcode_sent_with_attributes_is_stored_as_its_markup() {
		$id = $this->page( '<!-- wp:paragraph --><p>Kontakt</p><!-- /wp:paragraph -->' );
		$this->act_as( $this->agent() );

		$tree   = array(
			array(
				'name'       => 'core/shortcode',
				'attributes' => array( 'text' => '[contact-form-7 id="1"]' ),
			),
		);
		$result = $this->execute(
			'wpmcp/content-write',
			array(
				'post_id' => $id,
				'tree'    => $tree,
				'dry_run' => false,
			)
		);

		$this->assertTrue( $result['ok'] ?? false, $this->explain( $result ) );
		$this->assertSame( '<!-- wp:shortcode -->[contact-form-7 id="1"]<!-- /wp:shortcode -->', $this->stored( $id ) );
	}

	public function test_an_unknown_node_key_is_refused_with_its_code_and_nothing_is_stored() {
		$content = '<!-- wp:paragraph --><p>Kontakt</p><!-- /wp:paragraph -->';
		$id      = $this->page( $content );
		$this->act_as( $this->agent() );

		$result = $this->execute(
			'wpmcp/content-write',
			array(
				'post_id' => $id,
				'tree'    => array(
					array(
						'name'     => 'core/group',
						'children' => array( array( 'name' => 'core/paragraph', 'html' => '<p>x</p>' ) ),
					),
				),
				'dry_run' => false,
			)
		);

		$this->assertFalse( $result['ok'] ?? true, $this->explain( $result ) );
		$this->assertSame( 'wpmcp_unknown_key', $result['code'] ?? null, $this->explain( $result ) );
		$this->assertStringContainsString( 'did you mean "innerBlocks"', implode( ' ', $result['errors'] ), $this->explain( $result ) );
		$this->assertSame( $content, $this->stored( $id ) );
	}

	public function test_content_create_refuses_an_unknown_node_key_before_creating_anything() {
		$this->act_as( $this->agent() );
		$before = (int) wp_count_posts( 'page' )->draft;

		$result = $this->execute(
			'wpmcp/content-create',
			array(
				'title'   => 'Neu',
				'tree'    => array( array( 'name' => 'core/paragraph', 'innerHTML' => '<p>x</p>' ) ),
				'dry_run' => false,
			)
		);

		$this->assertSame( 'wpmcp_unknown_key', $result['code'] ?? null, $this->explain( $result ) );
		wp_cache_flush();
		$this->assertSame( $before, (int) wp_count_posts( 'page' )->draft, 'no page created' );
	}

	public function test_an_unknown_argument_is_refused_by_every_tool_before_it_runs() {
		$this->set_level( 'full' );
		$this->act_as( $this->agent() );

		$this->assertSame( wpmcp_write_argument_names(), array_keys( wp_get_ability( 'wpmcp/content-write' )->get_input_schema()['properties'] ), 'batch items take what content-write takes' );

		$names = wpmcp_ability_names();
		$this->assertContains( 'wpmcp/content-write', $names );

		foreach ( $names as $name ) {
			$ability = wp_get_ability( $name );
			$this->assertInstanceOf( WP_Ability::class, $ability, $name );
			$schema = $ability->get_input_schema();
			$input  = array();
			foreach ( (array) ( $schema['required'] ?? array() ) as $required ) {
				$type               = (array) ( $schema['properties'][ $required ]['type'] ?? 'string' );
				$input[ $required ] = in_array( 'integer', $type, true ) ? 1 : ( 'names' === $required ? array( 'core/paragraph' ) : 'x' );
			}
			$input['zz_unknown'] = 1;

			$result = $ability->execute( $input );
			$this->assertWPError( $result, $name . ': ' . $this->explain( $result ) );
			$this->assertSame( 'wpmcp_unknown_argument', $result->get_error_code(), $name . ': ' . $this->explain( $result ) );
			$this->assertStringStartsWith( '[wpmcp_unknown_argument] ', $result->get_error_message() );
			$this->assertStringContainsString( '"zz_unknown"', $result->get_error_message() );
		}
	}

	public function test_the_refusal_names_the_argument_the_accepted_ones_and_the_nearest() {
		$id = $this->page( '<!-- wp:paragraph --><p>A</p><!-- /wp:paragraph -->' );
		$this->act_as( $this->agent() );

		$result = $this->execute(
			'wpmcp/content-write',
			array(
				'post_id' => $id,
				'ops'     => array(),
				'dryRun'  => false,
			)
		);
		$this->assertWPError( $result );
		$this->assertSame( 'wpmcp_unknown_argument', $result->get_error_code() );
		$this->assertStringContainsString( 'unknown argument "dryRun" (did you mean "dry_run"?)', $result->get_error_message() );
		$this->assertStringContainsString( 'post_id, meta, ops, tree, dry_run', $result->get_error_message() );
	}

	public function test_the_aliases_and_text_arguments_keep_working() {
		$id = $this->page( '<!-- wp:paragraph --><p>Hallo Welt</p><!-- /wp:paragraph -->', 'publish' );
		$this->act_as( $this->agent() );

		$by_type  = $this->execute( 'wpmcp/content-search', array( 'query' => 'Hallo Welt', 'post_type' => 'page' ) );
		$by_types = $this->execute( 'wpmcp/content-search', array( 'query' => 'Hallo Welt', 'post_types' => 'page' ) );
		$this->assertFalse( is_wp_error( $by_type ), $this->explain( $by_type ) );
		$this->assertFalse( is_wp_error( $by_types ), $this->explain( $by_types ) );

		$draft = $this->page( '<!-- wp:paragraph --><p>A</p><!-- /wp:paragraph -->' );
		$ops   = wp_json_encode(
			array(
				array(
					'op'      => 'patch_html',
					'path'    => '0',
					'find'    => 'A',
					'replace' => 'B, C',
				),
			)
		);
		$text  = $this->execute( 'wpmcp/content-write', array( 'post_id' => $draft, 'ops' => $ops, 'dry_run' => false ) );
		$this->assertTrue( $text['ok'] ?? false, $this->explain( $text ) );
		$this->assertStringContainsString( '<p>B, C</p>', $this->stored( $draft ) );

		$media = $this->execute( 'wpmcp/media-read', array( 'post_id' => 999999 ) );
		$this->assertSame( 'wpmcp_not_found', is_wp_error( $media ) ? $media->get_error_code() : null, $this->explain( $media ) );
	}

	public function test_a_batch_item_with_an_unknown_key_stops_the_batch() {
		$a = $this->page( '<!-- wp:paragraph --><p>A</p><!-- /wp:paragraph -->' );
		$b = $this->page( '<!-- wp:paragraph --><p>B</p><!-- /wp:paragraph -->' );
		$this->act_as( $this->agent() );

		$patch  = function ( $find, $replace ) {
			return array(
				array(
					'op'      => 'patch_html',
					'path'    => '0',
					'find'    => $find,
					'replace' => $replace,
				),
			);
		};
		$result = $this->execute(
			'wpmcp/content-batch',
			array(
				'items'   => array(
					array( 'post_id' => $a, 'ops' => $patch( 'A', 'A2' ) ),
					array( 'post_id' => $b, 'ops' => $patch( 'B', 'B2' ), 'expectedModified' => 'x' ),
				),
				'dry_run' => false,
			)
		);

		$this->assertFalse( $result['ok'] ?? true, $this->explain( $result ) );
		$this->assertTrue( $result['items'][0]['ok'] );
		$this->assertSame( 'wpmcp_unknown_argument', $result['items'][1]['code'] ?? null, $this->explain( $result ) );
		$this->assertStringContainsString( 'did you mean "expected_modified"', implode( ' ', $result['items'][1]['errors'] ) );
		$this->assertStringContainsString( '<p>A</p>', $this->stored( $a ), 'nothing saved' );
	}

	public function test_dry_run_inside_a_batch_item_must_agree_with_the_batch() {
		$a = $this->page( '<!-- wp:paragraph --><p>A</p><!-- /wp:paragraph -->' );
		$this->act_as( $this->agent() );
		$ops = array(
			array(
				'op'      => 'patch_html',
				'path'    => '0',
				'find'    => 'A',
				'replace' => 'A2',
			),
		);

		$same = $this->execute( 'wpmcp/content-batch', array( 'items' => array( array( 'post_id' => $a, 'ops' => $ops, 'dry_run' => true ) ) ) );
		$this->assertTrue( $same['ok'] ?? false, $this->explain( $same ) );

		$contradicts = $this->execute( 'wpmcp/content-batch', array( 'items' => array( array( 'post_id' => $a, 'ops' => $ops, 'dry_run' => false ) ) ) );
		$this->assertFalse( $contradicts['ok'] ?? true, $this->explain( $contradicts ) );
		$this->assertSame( 'wpmcp_bad_request', $contradicts['items'][0]['code'] ?? null, $this->explain( $contradicts ) );
		$this->assertStringContainsString( '<p>A</p>', $this->stored( $a ) );
	}

	public function test_over_the_mcp_endpoint_the_code_leads_the_message() {
		$this->set_level( 'draft' );
		$agent = $this->agent();
		list( $password ) = WP_Application_Passwords::create_new_application_password( $agent->ID, array( 'name' => 'MCP' ) );
		$id = $this->page( '<!-- wp:paragraph --><p>A</p><!-- /wp:paragraph -->' );

		$call = $this->mcp_call( $agent, $password, 'wpmcp-content-duplicate', array( 'post_id' => $id, 'status' => 'publish' ) );
		$this->assertTrue( $call['isError'], $call['text'] );
		$this->assertStringStartsWith( '[wpmcp_unknown_argument]', $call['text'] );
	}
}
