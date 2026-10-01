<?php
/**
 * What reaches the database is what was sent, and the revision a save
 * leaves is the one the answer names.
 *
 * wp_insert_post, wp_update_post and update_post_meta unslash what they
 * get. A shim's wp_slash() returns its input, so the shim suite cannot
 * see a missing one. Here the stored bytes are read back with SQL.
 *
 * The revision half caught a fatal error the shims could not: the
 * revision listener passed an expression to wp_get_post_revision(), which
 * takes its argument by reference.
 *
 * @package wp-mcp-connector-plus
 */

class SlashingAndRevisionsTest extends WPMCP_Real_TestCase {

	public function set_up() {
		parent::set_up();
		$this->set_level( 'draft' );
	}

	public function test_backslashes_and_unicode_escapes_survive_a_save_byte_for_byte() {
		$id = $this->page( '<!-- wp:paragraph --><p>alt</p><!-- /wp:paragraph -->' );

		// Attributes with characters the block serializer escapes and kses
		// leaves alone: a quote, "--", "&" and a backslash become \u0022,
		// \u002d\u002d, \u0026 and \u005c. Each is a backslash in the
		// stored text, which an unslashed save would strip.
		$attrs = array(
			'className' => 'Mueller & Soehne "Q" -- x\\y',
			'metadata'  => array( 'name' => 'Pfad C:\\Kunden\\neu' ),
		);
		$html  = '<p class="wp-block-paragraph">Pfad C:\\Kunden\\neu und \\n bleibt</p>';

		// JSON-LD whose "<" is already escaped, the form the guard lets
		// through: valid JSON, no raw "<" in it.
		$json = '<script type="application/ld+json">{"@context":"https://schema.org","name":"a \\u003c b","path":"C:\\\\x"}</script>';

		$this->act_as( $this->agent() );
		$result = $this->execute(
			'wpmcp/content-write',
			array(
				'post_id' => $id,
				'dry_run' => false,
				'tree'    => array(
					array(
						'name'  => 'core/paragraph',
						'attrs' => $attrs,
						'html'  => $html,
					),
					array(
						'name' => 'core/html',
						'html' => $json,
					),
				),
			)
		);
		$this->assertFalse( $this->refused( $result ), $this->explain( $result ) );

		$expected = get_comment_delimited_block_content( 'core/paragraph', $attrs, $html )
			. get_comment_delimited_block_content( 'core/html', array(), $json );

		$this->assertStringContainsString( 'Mueller \\u0026 Soehne \\u0022Q\\u0022 \\u002d\\u002d x\\u005cy', $expected, 'precondition: the serializer escapes' );
		$this->assertSame( $expected, $this->stored( $id ), 'stored byte for byte as serialized' );

		$parsed = parse_blocks( $this->stored( $id ) );
		$this->assertSame( $attrs, $parsed[0]['attrs'], 'and the attributes parse back to what was sent' );
	}

	public function test_meta_with_backslashes_is_stored_as_sent() {
		$id    = $this->page( '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' );
		$title = 'Monitor 27\\" fuer C:\\Kunden \\ Test';

		$this->act_as( $this->agent() );
		$result = $this->execute(
			'wpmcp/content-write',
			array(
				'post_id' => $id,
				'dry_run' => false,
				'meta'    => array( 'rank_math_title' => $title ),
			)
		);
		$this->assertFalse( $this->refused( $result ), $this->explain( $result ) );

		global $wpdb;
		$stored = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = 'rank_math_title'", $id ) );
		$this->assertSame( $title, $stored );
	}

	public function test_the_answer_names_the_revision_this_save_stored() {
		$id = $this->page( '<!-- wp:paragraph --><p>eins</p><!-- /wp:paragraph -->' );

		$this->act_as( $this->agent() );
		$result = $this->execute(
			'wpmcp/content-write',
			array(
				'post_id' => $id,
				'dry_run' => false,
				'tree'    => array( array( 'name' => 'core/paragraph', 'html' => '<p>zwei</p>' ) ),
			)
		);
		$this->assertFalse( $this->refused( $result ), $this->explain( $result ) );

		$newest = wp_get_post_revisions( $id, array( 'posts_per_page' => 1 ) );
		$this->assertNotEmpty( $newest );
		$this->assertSame( (int) key( $newest ), $result['revisionId'] );
		$this->assertStringContainsString( 'zwei', get_post( $result['revisionId'] )->post_content );

		// A status-only change stores no revision; the answer says 0.
		$this->open_session();
		$this->act_as( $this->agent() );
		$status = $this->execute(
			'wpmcp/content-write',
			array(
				'post_id' => $id,
				'status'  => 'pending',
				'dry_run' => false,
			)
		);
		$this->assertFalse( $this->refused( $status ), $this->explain( $status ) );
		$this->assertSame( 0, $status['revisionId'] );
	}

	public function test_restore_puts_back_a_revision_a_person_saved() {
		$id = $this->page( '<!-- wp:paragraph --><p>Entwurf</p><!-- /wp:paragraph -->' );

		// The person saves once more in the editor: that save leaves a revision.
		wp_set_current_user( $this->admin()->ID );
		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => '<!-- wp:paragraph --><p>vom Menschen</p><!-- /wp:paragraph -->',
			)
		);
		$human = wp_get_post_revisions( $id );
		$this->assertCount( 1, $human, 'the person\'s save left a revision' );
		$human_revision = (int) key( $human );

		$this->act_as( $this->agent() );
		$this->execute(
			'wpmcp/content-write',
			array(
				'post_id' => $id,
				'dry_run' => false,
				'tree'    => array( array( 'name' => 'core/paragraph', 'html' => '<p>vom Agenten</p>' ) ),
			)
		);
		$this->assertStringContainsString( 'vom Agenten', $this->stored( $id ) );

		$result = $this->execute(
			'wpmcp/content-restore',
			array(
				'post_id'     => $id,
				'revision_id' => $human_revision,
				'dry_run'     => false,
			)
		);

		$this->assertFalse( $this->refused( $result ), $this->explain( $result ) );
		$this->assertStringContainsString( 'vom Menschen', $this->stored( $id ) );
		$this->assertGreaterThan( 0, $result['savedRevisionId'] ?? 0, $this->explain( $result ) );
	}
}
