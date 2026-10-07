<?php
/**
 * content-duplicate: where the copy goes, and a "modified" that works.
 *
 * On staging.maxport.ch content-duplicate was called with a "slug". It
 * takes none, the argument was dropped and the copy kept no slug at all.
 * The copy (1104) also reported "modified: 0000-00-00 00:00:00", which
 * reads like a broken post. Both are WordPress behaviour for a new draft:
 * it keeps post_name empty until it is published, and since its
 * post_date_gmt is zero, wp_insert_post() sets post_modified_gmt to that
 * zero as well. The connector now takes slug and parent for the copy and
 * reports a real time as "modified", one expected_modified accepts.
 *
 * @package wp-mcp-connector-plus
 */

class DuplicatePlacementTest extends WPMCP_Real_TestCase {

	public function set_up() {
		parent::set_up();
		$this->set_level( 'draft' );
	}

	private function source() {
		return $this->page( '<!-- wp:paragraph --><p>Vorlage</p><!-- /wp:paragraph -->', 'publish' );
	}

	public function test_wordpress_gives_a_new_draft_no_modified_time_in_gmt() {
		$id = $this->page( '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' );
		$this->assertSame( '0000-00-00 00:00:00', get_post( $id )->post_modified_gmt, 'the fact the stamp works around' );
		$this->assertSame( '', get_post( $id )->post_name );
	}

	public function test_the_copy_reports_a_real_modified_time_that_a_write_accepts() {
		$source = $this->source();
		$this->act_as( $this->agent() );

		$copy = $this->execute( 'wpmcp/content-duplicate', array( 'post_id' => $source ) );
		$this->assertTrue( $copy['ok'] ?? false, $this->explain( $copy ) );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $copy['modified'] ?? '' );
		$this->assertNotSame( '0000-00-00 00:00:00', $copy['modified'] );

		$read = $this->execute( 'wpmcp/content-read', array( 'post_id' => $copy['id'] ) );
		$this->assertSame( $copy['modified'], $read['modified'] ?? null, 'content-read says the same' );

		$write = $this->execute(
			'wpmcp/content-write',
			array(
				'post_id'           => $copy['id'],
				'ops'               => array(
					array(
						'op'      => 'patch_html',
						'path'    => '0',
						'find'    => 'Vorlage',
						'replace' => 'Kopie',
					),
				),
				'expected_modified' => $copy['modified'],
				'dry_run'           => false,
			)
		);
		$this->assertTrue( $write['ok'] ?? false, $this->explain( $write ) );
		$this->assertNotSame( '0000-00-00 00:00:00', $write['modified'] ?? '' );
	}

	public function test_the_zero_stamp_an_older_client_kept_is_still_accepted_while_it_is_true() {
		$source = $this->source();
		$this->act_as( $this->agent() );
		$copy = $this->execute( 'wpmcp/content-duplicate', array( 'post_id' => $source ) );

		$write = $this->execute(
			'wpmcp/content-write',
			array(
				'post_id'           => $copy['id'],
				'meta'              => array( 'rank_math_title' => 'T' ),
				'expected_modified' => '0000-00-00 00:00:00',
			)
		);
		$this->assertTrue( $write['ok'] ?? false, $this->explain( $write ) );
	}

	public function test_a_stale_stamp_is_still_refused() {
		$source = $this->source();
		$this->act_as( $this->agent() );
		$copy = $this->execute( 'wpmcp/content-duplicate', array( 'post_id' => $source ) );

		$write = $this->execute(
			'wpmcp/content-write',
			array(
				'post_id'           => $copy['id'],
				'meta'              => array( 'rank_math_title' => 'T' ),
				'expected_modified' => '2001-01-01 00:00:00',
			)
		);
		$this->assertWPError( $write );
		$this->assertSame( 'wpmcp_stale', $write->get_error_code() );
	}

	public function test_slug_title_and_parent_go_to_the_copy() {
		$source = $this->source();
		$parent = $this->page( '', 'publish' );
		$this->act_as( $this->agent() );

		$copy = $this->execute(
			'wpmcp/content-duplicate',
			array(
				'post_id' => $source,
				'title'   => 'Kurse Zürich',
				'slug'    => 'Kurse Zürich',
				'parent'  => $parent,
			)
		);
		$this->assertTrue( $copy['ok'] ?? false, $this->explain( $copy ) );
		$this->assertSame( 'kurse-zurich', $copy['slug'] );
		$this->assertSame( $parent, $copy['parent'] );

		$stored = get_post( $copy['id'] );
		$this->assertSame( 'kurse-zurich', $stored->post_name );
		$this->assertSame( $parent, (int) $stored->post_parent );
		$this->assertSame( 'Kurse Zürich', $stored->post_title );
		$this->assertSame( 'draft', $stored->post_status );
	}

	public function test_a_taken_slug_becomes_unique_and_says_so() {
		$source = $this->source();
		wp_update_post(
			array(
				'ID'        => $source,
				'post_name' => 'kurse',
			)
		);
		$this->act_as( $this->agent() );

		$copy = $this->execute( 'wpmcp/content-duplicate', array( 'post_id' => $source, 'slug' => 'kurse' ) );
		$this->assertTrue( $copy['ok'] ?? false, $this->explain( $copy ) );
		$this->assertSame( 'kurse-2', $copy['slug'] );
		$this->assertSame( 'kurse-2', get_post( $copy['id'] )->post_name );
		$this->assertStringContainsString( '"kurse" is taken', implode( ' ', $copy['warnings'] ?? array() ), $this->explain( $copy ) );
	}

	public function test_without_a_slug_the_copy_says_what_publishing_will_make_of_it() {
		$source = $this->source();
		$this->act_as( $this->agent() );

		$copy = $this->execute( 'wpmcp/content-duplicate', array( 'post_id' => $source, 'title' => 'Neue Seite' ) );
		$this->assertSame( '', $copy['slug'] );
		$this->assertSame( 'neue-seite', $copy['slugOnPublish'] ?? null, $this->explain( $copy ) );
	}

	public function test_a_parent_it_may_not_have_is_refused_and_nothing_is_created() {
		$source = $this->source();
		$post   = self::factory()->post->create( array( 'post_type' => 'post' ) );
		$this->act_as( $this->agent() );
		$before = (int) wp_count_posts( 'page' )->draft;

		foreach ( array( $post, 999999 ) as $parent ) {
			$copy = $this->execute( 'wpmcp/content-duplicate', array( 'post_id' => $source, 'parent' => $parent ) );
			$this->assertWPError( $copy, (string) $parent );
			$this->assertSame( 'wpmcp_bad_parent', $copy->get_error_code() );
		}

		$copy = $this->execute( 'wpmcp/content-duplicate', array( 'post_id' => $source, 'slug' => '!!!' ) );
		$this->assertWPError( $copy );
		$this->assertSame( 'wpmcp_bad_request', $copy->get_error_code() );

		wp_cache_flush();
		$this->assertSame( $before, (int) wp_count_posts( 'page' )->draft, 'no copy left behind' );
	}

	public function test_a_created_page_reports_a_real_modified_time_too() {
		$this->act_as( $this->agent() );
		$created = $this->execute( 'wpmcp/content-create', array( 'title' => 'Leer', 'dry_run' => false ) );
		$this->assertTrue( $created['ok'] ?? false, $this->explain( $created ) );
		$this->assertNotSame( '0000-00-00 00:00:00', $created['modified'] ?? '' );
		$this->assertMatchesRegularExpression( '/^\d{4}-/', $created['modified'] ?? '' );
	}
}
