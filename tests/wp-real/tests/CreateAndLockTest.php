<?php
/**
 * content-create all or nothing, and the editor's post lock.
 *
 * Both depend on WordPress doing what the shims assume: wp_insert_post
 * and wp_delete_post leaving no row behind, and wp_check_post_lock
 * reading the lock the editor's heartbeat writes.
 *
 * @package wp-mcp-connector-plus
 */

class CreateAndLockTest extends WPMCP_Real_TestCase {

	const CONTENT = '<!-- wp:paragraph --><p>Text</p><!-- /wp:paragraph -->';

	public function set_up() {
		parent::set_up();
		$this->set_level( 'draft' );
	}

	public function test_refused_content_creates_nothing() {
		$this->act_as( $this->agent() );
		$before = $this->page_rows();

		$result = $this->execute(
			'wpmcp/content-create',
			array(
				'title'   => 'Neue Seite',
				'dry_run' => false,
				'tree'    => array( array( 'name' => 'core/paragraph', 'html' => '<p><img src="x" onerror="alert(1)"></p>' ) ),
			)
		);

		$this->assertTrue( $this->refused( $result ), $this->explain( $result ) );
		$this->assertArrayNotHasKey( 'id', (array) $result );
		$this->assertSame( $before, $this->page_rows(), 'no empty draft left behind' );
	}

	public function test_a_save_failing_after_the_insert_removes_the_page_again() {
		$this->act_as( $this->agent() );
		$before = $this->page_rows();

		// Refuses every update and lets the first insert through: the shape
		// of a save filter that only objects once content arrives.
		add_filter(
			'wp_insert_post_empty_content',
			function ( $empty, $postarr ) {
				return ! empty( $postarr['ID'] );
			},
			10,
			2
		);

		$result = $this->execute(
			'wpmcp/content-create',
			array(
				'title'   => 'Neue Seite',
				'dry_run' => false,
				'tree'    => array( array( 'name' => 'core/paragraph', 'html' => '<p>Inhalt</p>' ) ),
			)
		);

		$this->assertTrue( $this->refused( $result ), $this->explain( $result ) );
		$this->assertSame( $before, $this->page_rows(), 'the page created a moment ago is gone again, revisions included' );
	}

	public function test_create_with_content_and_publish_in_a_session() {
		$this->open_session();
		$this->act_as( $this->agent() );

		$result = $this->execute(
			'wpmcp/content-create',
			array(
				'title'   => 'Neue Seite',
				'status'  => 'publish',
				'dry_run' => false,
				'tree'    => array( array( 'name' => 'core/paragraph', 'html' => '<p>Inhalt</p>' ) ),
			)
		);

		$this->assertFalse( $this->refused( $result ), $this->explain( $result ) );
		$this->assertSame( 'publish', $result['status'] ?? null, $this->explain( $result ) );
		$this->assertSame( 'publish', $this->stored( $result['id'], 'post_status' ) );
		$this->assertStringContainsString( '<p>Inhalt</p>', $this->stored( $result['id'] ) );
		$this->assertFalse( current_user_can( 'publish_pages' ) );
	}

	public function test_a_person_holding_the_lock_blocks_the_write() {
		require_once ABSPATH . 'wp-admin/includes/post.php';

		$id     = $this->page( self::CONTENT );
		$before = $this->stored( $id );
		$editor = self::factory()->user->create_and_get(
			array(
				'role'         => 'editor',
				'display_name' => 'Lara',
			)
		);

		// What the block editor's heartbeat does while the page is open.
		wp_set_current_user( $editor->ID );
		wp_set_post_lock( $id );

		$this->act_as( $this->agent() );
		$write = array(
			'post_id' => $id,
			'tree'    => array( array( 'name' => 'core/paragraph', 'html' => '<p>Neu</p>' ) ),
		);

		$dry = $this->execute( 'wpmcp/content-write', array_merge( $write, array( 'dry_run' => true ) ) );
		$this->assertFalse( $this->refused( $dry ), 'a dry run only warns: ' . $this->explain( $dry ) );
		$this->assertStringContainsString( 'Lara', implode( ' ', $dry['warnings'] ) );

		$real = $this->execute( 'wpmcp/content-write', array_merge( $write, array( 'dry_run' => false ) ) );
		$this->assertWPError( $real );
		$this->assertSame( 'wpmcp_locked', $real->get_error_code() );
		$this->assertStringStartsWith( '[wpmcp_locked] Lara', $real->get_error_message() );
		$this->assertSame( $before, $this->stored( $id ), 'nothing saved' );
	}

	public function test_a_lock_held_by_another_agent_account_does_not_block() {
		require_once ABSPATH . 'wp-admin/includes/post.php';

		$id = $this->page( self::CONTENT );
		wp_set_current_user( $this->agent()->ID );
		wp_set_post_lock( $id );

		$this->act_as( $this->agent() );
		$result = $this->execute(
			'wpmcp/content-write',
			array(
				'post_id' => $id,
				'dry_run' => false,
				'tree'    => array( array( 'name' => 'core/paragraph', 'html' => '<p>Neu</p>' ) ),
			)
		);

		$this->assertFalse( $this->refused( $result ), $this->explain( $result ) );
		$this->assertStringContainsString( '<p>Neu</p>', $this->stored( $id ) );
	}

	/**
	 * Every row in the posts table that is a page or a revision.
	 *
	 * @return int
	 */
	private function page_rows() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('page', 'revision')" );
	}
}
