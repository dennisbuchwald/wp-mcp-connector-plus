<?php
/**
 * The Elementor tools against Elementor 4.3.4 itself.
 *
 * Pages are built the way the first customer site is built: containers,
 * each holding one HTML widget with the section as raw markup (some of it
 * with a script), plus a heading widget. The agent account works on them
 * through the abilities, and what Elementor stored and renders afterwards
 * is read back from Elementor.
 *
 * @package wp-mcp-connector-plus
 */

class ElementorWriteTest extends WPMCP_Real_Elementor_TestCase {

	const SLIDER = '<script>window.wpmcpSlider = (window.wpmcpSlider || 0) + 1;</script>';

	/**
	 * The test page: a hero with a script, a heading widget, a text section.
	 *
	 * @return array
	 */
	private function elements() {
		return array(
			self::html_section( 'a000001', 'b000001', '<section class="hero"><h1>Bootsführerschein in Zürich</h1><p>Wir machen dich fit für den Schein.</p><img src="/boot.jpg" alt="Segelboot">' . self::SLIDER . '</section>' ),
			array(
				'id'       => 'a000002',
				'elType'   => 'container',
				'settings' => array(),
				'elements' => array(
					array(
						'id'         => 'b000002',
						'elType'     => 'widget',
						'widgetType' => 'heading',
						'settings'   => array(
							'title'       => 'Unsere Leistungen',
							'header_size' => 'h2',
						),
						'elements'   => array(),
					),
				),
				'isInner'  => false,
			),
			self::html_section( 'a000003', 'b000003', '<h3>Kurse</h3><p>Binnen und See, das ganze Jahr.</p>' ),
		);
	}

	private function write( $id, array $ops, $dry_run = false, array $extra = array() ) {
		return $this->execute(
			'wpmcp/elementor-write',
			array_merge(
				array(
					'post_id' => $id,
					'ops'     => $ops,
					'dry_run' => $dry_run,
				),
				$extra
			)
		);
	}

	private function html_of( array $elements, $id ) {
		$index = wpmcp_elementor_index( $elements );
		$this->assertArrayHasKey( $id, $index, "element {$id} is stored" );
		return wpmcp_elementor_at( $elements, $index[ $id ] )['settings']['html'] ?? null;
	}

	public function test_the_tools_exist_where_elementor_runs_and_follow_the_level() {
		$this->set_level( 'read' );
		wp_get_ability( 'wpmcp/site-info' );
		$this->assertTrue( wp_has_ability( 'wpmcp/elementor-read' ) );
		$this->assertFalse( wp_has_ability( 'wpmcp/elementor-write' ), 'no write tool at the read level' );

		$this->set_level( 'draft' );
		wp_get_ability( 'wpmcp/site-info' );
		$this->assertTrue( wp_has_ability( 'wpmcp/elementor-write' ) );
		$this->assertContains( 'wpmcp/elementor-write', wpmcp_write_ability_names() );

		$meta = wp_get_ability( 'wpmcp/elementor-write' )->get_meta();
		$this->assertTrue( $meta['annotations']['destructive'] );
		$this->assertFalse( $meta['annotations']['readonly'] );
	}

	public function test_read_gives_the_outline_with_what_each_html_widget_says() {
		$id = $this->elementor_page( $this->elements() );
		$this->act_as( $this->agent() );

		$read = $this->execute( 'wpmcp/elementor-read', array( 'post_id' => $id ) );
		$this->assertIsArray( $read, $this->explain( $read ) );
		$this->assertSame( 'elementor', $read['builtWith'] );
		$this->assertSame( 6, $read['elementCount'] );
		$this->assertSame( get_post( $id )->post_modified_gmt, $read['modified'] );

		$hero = $read['outline'][0]['elements'][0];
		$this->assertSame( 'b000001', $hero['id'] );
		$this->assertSame( '0.0', $hero['path'] );
		$this->assertSame( array( array( 'level' => 1, 'text' => 'Bootsführerschein in Zürich' ) ), $hero['html']['headings'] );
		$this->assertSame( 1, $hero['html']['scripts'] );
		$this->assertSame( 'Segelboot', $hero['html']['images'][0]['alt'] );
		$this->assertSame( 'Unsere Leistungen', $read['outline'][1]['elements'][0]['summary']['title'] );
	}

	public function test_one_html_widget_comes_verbatim_in_windows() {
		$long = '<p>' . str_repeat( 'Fährschein über den Zürichsee. ', 3000 ) . '</p>';
		$id   = $this->elementor_page( array( self::html_section( 'a000001', 'b000001', $long ) ) );
		$this->act_as( $this->agent() );

		$first = $this->execute( 'wpmcp/elementor-read', array( 'post_id' => $id, 'element_id' => 'b000001' ) );
		$this->assertTrue( $first['element']['html']['truncated'] );
		$rest = $this->execute( 'wpmcp/elementor-read', array( 'post_id' => $id, 'element_id' => 'b000001', 'offset' => $first['element']['html']['nextOffset'] ) );
		$this->assertSame( $long, $first['element']['html']['html'] . $rest['element']['html']['html'], 'the windows join up to the markup, umlauts whole' );
	}

	public function test_a_dry_run_stores_nothing() {
		$id = $this->elementor_page( $this->elements() );
		$this->act_as( $this->agent() );

		$dry = $this->write( $id, array( array( 'op' => 'patch_html', 'id' => 'b000001', 'find' => 'fit für', 'replace' => 'bereit für' ) ), true );
		$this->assertFalse( $this->refused( $dry ), $this->explain( $dry ) );
		$this->assertTrue( $dry['dryRun'] );
		$this->assertStringContainsString( 'bereit für den Schein', $dry['applied'][0]['now'] );
		$this->assertSame( $this->elements(), $this->stored_elements( $id ), 'nothing stored' );
		$this->assertCount( 0, wp_get_post_revisions( $id ) );
	}

	public function test_changing_a_heading_level_next_to_a_script_keeps_the_script() {
		$id = $this->elementor_page( $this->elements() );
		$this->act_as( $this->agent() );
		$this->assertFalse( current_user_can( 'unfiltered_html' ), 'precondition: the agent has no unfiltered_html' );

		$read  = $this->execute( 'wpmcp/elementor-read', array( 'post_id' => $id ) );
		$write = $this->write(
			$id,
			array( array( 'op' => 'patch_html', 'id' => 'b000001', 'find' => '<h1>Bootsführerschein in Zürich</h1>', 'replace' => '<h2>Bootsführerschein in Zürich und Luzern</h2>' ) ),
			false,
			array( 'expected_modified' => $read['modified'] )
		);

		$this->assertFalse( $this->refused( $write ), $this->explain( $write ) );
		$this->assertSame( 'Saved.', $write['message'] );
		$this->assertFalse( current_user_can( 'unfiltered_html' ), 'the grant ended with the save' );

		$html = $this->html_of( $this->stored_elements( $id ), 'b000001' );
		$this->assertStringContainsString( '<h2>Bootsführerschein in Zürich und Luzern</h2>', $html );
		$this->assertStringContainsString( self::SLIDER, $html, 'the script is stored as it was' );

		// Rendered by Elementor's frontend, script included.
		$rendered = $this->rendered( $id );
		$this->assertStringContainsString( '<h2>Bootsführerschein in Zürich und Luzern</h2>', $rendered );
		$this->assertStringContainsString( self::SLIDER, $rendered );

		// A revision, holding the element data of this save.
		$this->assertGreaterThan( 0, $write['revisionId'] );
		$revision_data = get_post_meta( $write['revisionId'], '_elementor_data', true );
		$this->assertStringContainsString( 'Luzern', $revision_data );

		// Elementor's plain-text copy, and the stamp for the next write.
		$this->assertStringContainsString( 'Luzern', $this->stored( $id ) );
		$this->assertSame( get_post( $id )->post_modified_gmt, $write['modified'] );
		$this->assertSame( '0.0', $write['applied'][0]['path'] );
	}

	public function test_elementor_alone_would_have_stripped_the_script() {
		// Why the grant is there: Elementor's own save, for this account.
		$id = $this->elementor_page( $this->elements() );
		$this->act_as( $this->agent() );

		$document = \Elementor\Plugin::$instance->documents->get( $id, false );
		$elements = $this->elements();
		$elements[2]['elements'][0]['settings']['html'] = '<h3>Kurse ab März</h3>';
		$document->save( array( 'elements' => $elements ) );

		$this->assertStringNotContainsString( '<script>', $this->html_of( $this->stored_elements( $id ), 'b000001' ), 'Elementor stripped a script nobody touched' );
	}

	public function test_set_html_insert_faq_move_duplicate_remove() {
		$id = $this->elementor_page( $this->elements() );
		$this->act_as( $this->agent() );

		$faq   = '<h2>Häufige Fragen</h2><h3>Wie lange dauert der Kurs?</h3><p>Zwei Wochenenden.</p>';
		$write = $this->write(
			$id,
			array(
				array( 'op' => 'set_html', 'id' => 'b000003', 'html' => '<h3>Kurse ab März</h3><p>Binnen und See.</p>' ),
				array(
					'op'      => 'insert',
					'after'   => 'a000003',
					'element' => array(
						'elType'   => 'container',
						'elements' => array(
							array(
								'elType'     => 'widget',
								'widgetType' => 'html',
								'settings'   => array( 'html' => $faq ),
							),
						),
					),
				),
				array( 'op' => 'move', 'id' => 'a000002', 'after' => 'a000003' ),
				array( 'op' => 'duplicate', 'id' => 'a000003' ),
			)
		);
		$this->assertFalse( $this->refused( $write ), $this->explain( $write ) );

		$stored = $this->stored_elements( $id );
		$faq_id = $write['applied'][1]['created'][0];
		$copy   = $write['applied'][3]['created'][0];
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{7}$/', $faq_id );
		$this->assertSame( array( 'a000001', 'a000003', $copy, 'a000002', $faq_id ), array_column( $stored, 'id' ) );
		$this->assertSame( '<h3>Kurse ab März</h3><p>Binnen und See.</p>', $this->html_of( $stored, 'b000003' ) );
		$this->assertSame( $faq, $stored[4]['elements'][0]['settings']['html'] );
		$this->assertNotSame( 'b000003', $stored[2]['elements'][0]['id'], 'the copy has ids of its own' );

		$rendered = $this->rendered( $id );
		$this->assertStringContainsString( 'Häufige Fragen', $rendered );
		$this->assertStringContainsString( 'elementor-element-' . $faq_id, $rendered );
		$this->assertSame( 2, substr_count( $rendered, 'Kurse ab März' ), 'the original and its copy render' );

		$remove = $this->write( $id, array( array( 'op' => 'remove', 'id' => $copy ) ), false, array( 'expected_modified' => $write['modified'] ) );
		$this->assertFalse( $this->refused( $remove ), $this->explain( $remove ) );
		$this->assertSame( array( 'a000001', 'a000003', 'a000002', $faq_id ), array_column( $this->stored_elements( $id ), 'id' ) );
	}

	public function test_a_stale_stamp_is_refused() {
		$id = $this->elementor_page( $this->elements() );
		$this->act_as( $this->agent() );

		$write = $this->write( $id, array( array( 'op' => 'set_html', 'id' => 'b000003', 'html' => '<p>x</p>' ) ), false, array( 'expected_modified' => '2001-01-01 00:00:00' ) );
		$this->assertWPError( $write );
		$this->assertSame( 'wpmcp_stale', $write->get_error_code() );
		$this->assertSame( $this->elements(), $this->stored_elements( $id ) );
	}

	/**
	 * @return array
	 */
	public static function refused_markup() {
		return array(
			'new script'      => array( '<h3>Kurse</h3><script>window.x = 1;</script>' ),
			'new onerror'     => array( '<h3>Kurse</h3><img src="x" onerror="window.x=1">' ),
			'javascript link' => array( '<h3><a href="javascript:void(window.x=1)">Kurse</a></h3>' ),
			'iframe'          => array( '<h3>Kurse</h3><iframe src="https://example.org/"></iframe>' ),
		);
	}

	/**
	 * @dataProvider refused_markup
	 */
	public function test_markup_the_agent_may_not_add_is_refused_and_nothing_stored( $html ) {
		$id = $this->elementor_page( $this->elements() );
		$this->act_as( $this->agent() );

		$write = $this->write( $id, array( array( 'op' => 'set_html', 'id' => 'b000003', 'html' => $html ) ) );
		$this->assertTrue( $this->refused( $write ), $this->explain( $write ) );
		$this->assertSame( 'wpmcp_validation_failed', $write['code'] );
		$this->assertStringContainsString( 'element b000003:html', implode( ' ', $write['errors'] ) );
		$this->assertSame( $this->elements(), $this->stored_elements( $id ) );
	}

	public function test_copying_a_section_with_a_script_is_refused() {
		$id = $this->elementor_page( $this->elements() );
		$this->act_as( $this->agent() );

		$write = $this->write( $id, array( array( 'op' => 'duplicate', 'id' => 'a000001' ) ) );
		$this->assertTrue( $this->refused( $write ), $this->explain( $write ) );
		$this->assertStringContainsString( '2 times where it had it 1', implode( ' ', $write['errors'] ) );
	}

	public function test_settings_are_checked_against_the_widget_controls() {
		$id = $this->elementor_page( $this->elements() );
		$this->act_as( $this->agent() );

		$unknown = $this->write( $id, array( array( 'op' => 'set_settings', 'id' => 'b000002', 'settings' => array( 'headline' => 'x' ) ) ) );
		$this->assertTrue( $this->refused( $unknown ) );
		$this->assertStringContainsString( 'no setting "headline"', implode( ' ', $unknown['errors'] ) );

		$wrong = $this->write( $id, array( array( 'op' => 'set_settings', 'id' => 'b000002', 'settings' => array( 'link' => 'https://example.org/' ) ) ) );
		$this->assertTrue( $this->refused( $wrong ) );
		$this->assertStringContainsString( 'takes an object', implode( ' ', $wrong['errors'] ) );

		$good = $this->write(
			$id,
			array(
				array(
					'op'       => 'set_settings',
					'id'       => 'b000002',
					'settings' => array(
						'title'       => 'Unsere Kurse in Zürich',
						'header_size' => 'h3',
						'link'        => array( 'url' => 'https://example.org/kurse/', 'is_external' => '', 'nofollow' => '' ),
					),
				),
			)
		);
		$this->assertFalse( $this->refused( $good ), $this->explain( $good ) );
		$rendered = $this->rendered( $id );
		$this->assertMatchesRegularExpression( '#<h3 class="elementor-heading-title[^"]*"><a href="https://example.org/kurse/">Unsere Kurse in Zürich</a></h3>#', $rendered );
	}

	public function test_an_element_type_elementor_cannot_load_blocks_the_save() {
		$elements                               = $this->elements();
		$elements[1]['elements'][0]['widgetType'] = 'some-addon-widget';
		$id                                     = $this->elementor_page( $elements );
		$this->act_as( $this->agent() );

		$write = $this->write( $id, array( array( 'op' => 'set_html', 'id' => 'b000003', 'html' => '<p>x</p>' ) ) );
		$this->assertTrue( $this->refused( $write ), $this->explain( $write ) );
		$this->assertStringContainsString( 'would delete them', implode( ' ', $write['errors'] ) );
		$this->assertSame( $elements, $this->stored_elements( $id ) );
	}

	public function test_a_person_in_the_editor_blocks_the_save() {
		require_once ABSPATH . 'wp-admin/includes/post.php';
		$id     = $this->elementor_page( $this->elements() );
		$editor = self::factory()->user->create_and_get( array( 'role' => 'editor', 'display_name' => 'Lara' ) );
		wp_set_current_user( $editor->ID );
		wp_set_post_lock( $id );

		$this->act_as( $this->agent() );
		$ops = array( array( 'op' => 'set_html', 'id' => 'b000003', 'html' => '<p>x</p>' ) );

		$dry = $this->write( $id, $ops, true );
		$this->assertStringContainsString( 'Lara', implode( ' ', $dry['warnings'] ) );
		$real = $this->write( $id, $ops );
		$this->assertWPError( $real );
		$this->assertSame( 'wpmcp_locked', $real->get_error_code() );
	}

	public function test_a_published_page_follows_the_access_level() {
		$id = $this->elementor_page( $this->elements(), 'publish' );
		$this->act_as( $this->agent() );
		$ops = array( array( 'op' => 'set_html', 'id' => 'b000003', 'html' => '<p>x</p>' ) );

		$write = $this->write( $id, $ops );
		$this->assertWPError( $write );
		$this->assertSame( 'wpmcp_live_edit_disabled', $write->get_error_code() );

		$this->set_level( 'full' );
		$this->assertFalse( $this->refused( $this->write( $id, $ops ) ) );
	}

	public function test_the_block_tools_refuse_an_elementor_page() {
		$id = $this->elementor_page( $this->elements() );
		$this->act_as( $this->agent() );

		$read = $this->execute( 'wpmcp/content-read', array( 'post_id' => $id ) );
		$this->assertSame( 'elementor', $read['builtWith'] );
		$this->assertSame( array(), $read['outline'], 'no outline of the plain-text copy' );

		$write = $this->execute(
			'wpmcp/content-write',
			array(
				'post_id' => $id,
				'dry_run' => true,
				'tree'    => array( array( 'name' => 'core/paragraph', 'html' => '<p>Neu</p>' ) ),
			)
		);
		$this->assertWPError( $write );
		$this->assertSame( 'wpmcp_elementor_page', $write->get_error_code() );
		$this->assertStringContainsString( 'elementor-write', $write->get_error_message() );

		$batch = $this->execute(
			'wpmcp/content-batch',
			array(
				'items' => array(
					array(
						'post_id' => $id,
						'ops'     => array( array( 'op' => 'patch_html', 'path' => '0', 'find' => 'Kurse', 'replace' => 'x' ) ),
					),
				),
			)
		);
		$this->assertSame( 'wpmcp_elementor_page', $batch['items'][0]['code'] ?? null, wp_json_encode( $batch ) );

		$meta = $this->execute(
			'wpmcp/content-write',
			array(
				'post_id' => $id,
				'dry_run' => false,
				'meta'    => array( 'rank_math_title' => 'Bootsführerschein Zürich' ),
			)
		);
		$this->assertFalse( $this->refused( $meta ), 'SEO meta stays writable: ' . $this->explain( $meta ) );

		$restore = $this->execute( 'wpmcp/content-restore', array( 'post_id' => $id, 'revision_id' => 1 ) );
		$this->assertWPError( $restore );
		$this->assertSame( 'wpmcp_elementor_page', $restore->get_error_code() );

		$list = $this->execute( 'wpmcp/content-list', array( 'post_type' => 'page' ) );
		$this->assertSame( 'elementor', wp_list_pluck( $list['items'], 'builtWith', 'id' )[ $id ] ?? null );
	}

	public function test_preview_and_search_go_through_the_elements() {
		$id = $this->elementor_page( $this->elements() );
		$this->act_as( $this->agent() );

		$preview = $this->execute( 'wpmcp/content-preview', array( 'post_id' => $id ) );
		$this->assertStringContainsString( 'elementor-element-b000001', $preview['html'] );
		$this->assertSame( 1, $preview['headings'][0]['level'] );

		// A link target is in the element data only, not in the plain-text copy.
		$search = $this->execute( 'wpmcp/content-search', array( 'query' => 'boot.jpg' ) );
		$this->assertSame( 1, $search['totalMatches'], wp_json_encode( $search ) );
		$this->assertSame( 'b000001', $search['matches'][0]['elementId'] );
		$this->assertSame( 'html', $search['matches'][0]['setting'] );
	}

	public function test_site_info_names_elementor_and_its_templates_stay_out_of_scope() {
		$this->act_as( $this->agent() );
		$info = $this->execute( 'wpmcp/site-info', array() );
		$this->assertIsArray( $info, $this->explain( $info ) );
		$this->assertSame( ELEMENTOR_VERSION, $info['elementor']['version'] );
		$this->assertSame( array( 'elementor-read', 'elementor-write' ), $info['elementor']['tools'] );

		$this->assertTrue( post_type_exists( 'elementor_library' ) );
		$this->assertNotContains( 'elementor_library', wpmcp_allowed_post_types() );
		$this->assertArrayHasKey( 'elementor_library', wpmcp_selectable_post_types(), 'offered to tick' );
	}

	public function test_the_css_file_record_is_dropped_so_elementor_builds_it_again() {
		$id = $this->elementor_page( $this->elements() );
		update_post_meta( $id, '_elementor_css', array( 'time' => 1, 'fonts' => array(), 'icons' => array(), 'dynamic_elements_ids' => array(), 'status' => 'file', 'css' => '' ) );
		$this->act_as( $this->agent() );

		$write = $this->write( $id, array( array( 'op' => 'set_html', 'id' => 'b000003', 'html' => '<p>x</p>' ) ) );
		$this->assertFalse( $this->refused( $write ), $this->explain( $write ) );
		$this->assertSame( '', get_post_meta( $id, '_elementor_css', true ) );
	}
}
