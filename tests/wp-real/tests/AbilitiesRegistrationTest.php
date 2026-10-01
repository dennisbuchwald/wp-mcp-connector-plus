<?php
/**
 * What the Abilities API holds at each access level, and who may run it.
 *
 * Registration goes through the real wp_abilities_api_init hook each time
 * (the base class resets the registries), so the level is read exactly
 * when WordPress asks for it.
 *
 * @package wp-mcp-connector-plus
 */

class AbilitiesRegistrationTest extends WPMCP_Real_TestCase {

	public function test_the_read_level_registers_no_write_ability() {
		$this->set_level( 'read' );

		$registered = $this->registered();
		$this->assertSame( $this->sorted( wpmcp_ability_names() ), $registered );
		$this->assertNotEmpty( $registered );

		foreach ( wpmcp_write_ability_names() as $name ) {
			$this->assertFalse( wp_has_ability( $name ), "{$name} does not exist at the read level" );
		}
	}

	public function test_a_session_on_the_read_level_adds_no_tools() {
		$this->set_level( 'read' );
		$this->open_session();

		$this->assertFalse( wp_has_ability( 'wpmcp/content-write' ) );
	}

	public function test_the_write_levels_register_everything() {
		foreach ( array( 'draft', 'full' ) as $level ) {
			$this->set_level( $level );
			$registered = $this->registered();
			foreach ( wpmcp_write_ability_names() as $name ) {
				$this->assertContains( $name, $registered, "{$name} at {$level}" );
			}
		}
	}

	public function test_only_the_marker_capability_may_run_them() {
		$this->set_level( 'draft' );

		$people = array(
			'administrator' => $this->admin(),
			'editor'        => self::factory()->user->create_and_get( array( 'role' => 'editor' ) ),
		);
		foreach ( $people as $role => $user ) {
			$this->act_as( $user );
			$ability = wp_get_ability( 'wpmcp/site-info' );
			$this->assertFalse( $ability->check_permissions( array() ), "{$role} without the marker capability" );
			$result = $ability->execute( array() );
			$this->assertWPError( $result );
			$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
		}

		$this->act_as( $this->agent() );
		$this->assertTrue( wp_get_ability( 'wpmcp/site-info' )->check_permissions( array() ) );
		$this->assertTrue( wp_get_ability( 'wpmcp/content-write' )->check_permissions( array() ) );
	}

	public function test_wp_abilities_rest_lists_and_runs_only_what_the_level_registers() {
		$this->set_level( 'read' );
		$this->act_as( $this->agent() );

		$list = rest_do_request( new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities' ) );
		$this->assertSame( 200, $list->get_status() );
		$names = array_values(
			array_filter(
				wp_list_pluck( $list->get_data(), 'name' ),
				function ( $name ) {
					return 0 === strpos( $name, 'wpmcp/' );
				}
			)
		);
		sort( $names );
		$this->assertSame( $this->sorted( wpmcp_ability_names() ), $names );

		// The REST controller asks the registry, which reports a missing name.
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::get_registered' );
		$run = new WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/wpmcp/content-write/run' );
		$run->set_body_params( array( 'input' => array( 'post_id' => 1 ) ) );
		$response = rest_do_request( $run );
		$this->assertSame( 404, $response->get_status(), 'the write ability is not there to run' );
	}

	/**
	 * The plugin's abilities as the registry holds them.
	 *
	 * @return string[]
	 */
	private function registered() {
		$names = array();
		foreach ( wp_get_abilities() as $ability ) {
			if ( 0 === strpos( $ability->get_name(), 'wpmcp/' ) ) {
				$names[] = $ability->get_name();
			}
		}
		sort( $names );
		return $names;
	}

	private function sorted( array $names ) {
		sort( $names );
		return $names;
	}
}
