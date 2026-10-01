<?php
/**
 * Capabilities granted for one save, and what the stored role holds.
 *
 * The plugin never puts publish_* or unfiltered_html on the role: it adds
 * them on user_has_cap for the length of one save and removes the filter
 * in a finally. Against a shim, "removed" means an array entry went away.
 * Here it means WP_User::has_cap(), with every filter WordPress and the
 * plugin hook in, says no again, also after a save that threw.
 *
 * @package wp-mcp-connector-plus
 */

class CapabilityGrantTest extends WPMCP_Real_TestCase {

	const CONTENT = '<!-- wp:paragraph --><p>Text</p><!-- /wp:paragraph -->';

	public function set_up() {
		parent::set_up();
		$this->set_level( 'draft' );
	}

	public function test_publishing_in_a_session_works_and_the_grant_ends_with_the_save() {
		$this->open_session();
		$id    = $this->page( self::CONTENT );
		$agent = $this->agent();
		$this->act_as( $agent );

		$this->assertFalse( current_user_can( 'publish_pages' ), 'no publishing before the save' );

		$result = $this->execute(
			'wpmcp/content-write',
			array(
				'post_id' => $id,
				'status'  => 'publish',
				'dry_run' => false,
			)
		);

		$this->assertFalse( $this->refused( $result ), $this->explain( $result ) );
		$this->assertSame( 'publish', $this->stored( $id, 'post_status' ) );
		$this->assertFalse( current_user_can( 'publish_pages' ), 'no publishing after the save' );
		$this->assertFalse( user_can( $agent->ID, 'publish_post', $id ) );
		$this->assertFalse( has_filter( 'user_has_cap' ) && $this->grants_at( 100 ), 'no grant filter left behind' );
	}

	public function test_publishing_outside_a_session_is_refused() {
		$id = $this->page( self::CONTENT );
		$this->act_as( $this->agent() );

		$result = $this->execute(
			'wpmcp/content-write',
			array(
				'post_id' => $id,
				'status'  => 'publish',
				'dry_run' => false,
			)
		);

		$this->assertTrue( $this->refused( $result ), $this->explain( $result ) );
		$this->assertSame( 'draft', $this->stored( $id, 'post_status' ) );
	}

	public function test_a_save_that_throws_leaves_no_grant_and_kses_in_place() {
		$this->open_session();
		$id = $this->page( self::CONTENT . '<!-- wp:html --><iframe src="https://player.example/1"></iframe><!-- /wp:html -->' );
		$this->act_as( $this->agent() );
		$kses = has_filter( 'content_save_pre', 'wp_filter_post_kses' );

		add_filter(
			'wp_insert_post_data',
			function () {
				throw new RuntimeException( 'save filter of another plugin' );
			}
		);

		// A status change through the publish grant, and a content change
		// next to an embed, which saves past kses: both put something on a
		// hook that must come off again.
		$calls = array(
			array( 'status' => 'publish' ),
			array(
				'tree' => array(
					array( 'name' => 'core/paragraph', 'html' => '<p>Neu</p>' ),
					array( 'name' => 'core/html', 'html' => '<iframe src="https://player.example/1"></iframe>' ),
				),
			),
		);

		foreach ( $calls as $call ) {
			$thrown = null;
			try {
				$this->execute( 'wpmcp/content-write', array_merge( $call, array( 'post_id' => $id, 'dry_run' => false ) ) );
			} catch ( RuntimeException $e ) {
				$thrown = $e;
			}
			$this->assertInstanceOf( RuntimeException::class, $thrown, 'the save really threw' );
			$this->assertFalse( current_user_can( 'publish_pages' ), 'the publish grant is gone' );
			$this->assertFalse( current_user_can( 'unfiltered_html' ) );
			$this->assertFalse( $this->grants_at( 100 ), 'no grant filter left behind' );
			$this->assertSame( $kses, has_filter( 'content_save_pre', 'wp_filter_post_kses' ), 'kses is back' );
		}

		$this->assertSame( 'draft', $this->stored( $id, 'post_status' ) );
	}

	public function test_the_stored_role_holds_only_read_and_the_marker() {
		$expected = array(
			'read'    => true,
			WPMCP_CAP => true,
		);

		foreach ( array( 'read', 'draft', 'full' ) as $level ) {
			$this->set_level( $level );
			$this->assertSame( $expected, $this->stored_role_caps(), "at level {$level}" );
		}

		$this->set_level( 'draft' );
		$this->open_session();
		$this->assertSame( $expected, $this->stored_role_caps(), 'during a session' );

		// What the level adds is worked out per check, not stored.
		$agent = $this->agent();
		$this->assertTrue( user_can( $agent->ID, 'edit_published_pages' ), 'the session gives it' );
		update_option( 'wpmcp_work_session_until', time() - 1 );
		$this->assertFalse( user_can( $agent->ID, 'edit_published_pages' ), 'gone the second the session ends, no request needed' );
		$this->assertTrue( user_can( $agent->ID, 'edit_pages' ), 'the configured level stays' );
	}

	public function test_caps_a_role_editor_stored_do_not_count_and_are_tidied_away() {
		get_role( WPMCP_ROLE )->add_cap( 'edit_published_pages' );

		$agent = $this->agent();
		$this->assertFalse( user_can( $agent->ID, 'edit_published_pages' ), 'the level decides, not the stored role' );

		// Admin and REST requests put the stored role back to the read set.
		$this->assertSame( 10, has_action( 'admin_init', 'wpmcp_reconcile_role' ) );
		$this->assertSame( 10, has_action( 'rest_api_init', 'wpmcp_reconcile_role' ) );
		wpmcp_reconcile_role();
		$this->assertSame( array( 'read' => true, WPMCP_CAP => true ), $this->stored_role_caps() );
	}

	/**
	 * The agent role's capabilities as stored in the roles option.
	 *
	 * @return array
	 */
	private function stored_role_caps() {
		$roles = get_option( wp_roles()->role_key );
		$caps  = $roles[ WPMCP_ROLE ]['capabilities'] ?? null;
		if ( is_array( $caps ) ) {
			ksort( $caps );
		}
		return $caps;
	}

	/**
	 * Is anything hooked into user_has_cap at this priority?
	 *
	 * @param int $priority Priority.
	 * @return bool
	 */
	private function grants_at( $priority ) {
		global $wp_filter;
		return ! empty( $wp_filter['user_has_cap']->callbacks[ $priority ] );
	}
}
