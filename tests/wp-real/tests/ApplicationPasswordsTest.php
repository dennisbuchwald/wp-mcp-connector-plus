<?php
/**
 * Application passwords: who gets them, with real filter priorities.
 *
 * The plugin hooks the global filter at 99 (note the site's answer) and
 * 100 (reopen it where transport is safe), and the per-user filter at 100.
 * Whether a site's own switch at the default priority 10 still holds for
 * people and not for the agent depends on WordPress running them in that
 * order, which only WordPress can show.
 *
 * @package wp-mcp-connector-plus
 */

class ApplicationPasswordsTest extends WPMCP_Real_TestCase {

	public function set_up() {
		parent::set_up();
		add_filter( 'application_password_is_api_request', '__return_true' );
	}

	public function test_over_https_both_have_them() {
		$_SERVER['HTTPS'] = 'on';

		$this->assertTrue( wp_is_application_passwords_available_for_user( $this->admin() ) );
		$this->assertTrue( wp_is_application_passwords_available_for_user( $this->agent() ) );
	}

	public function test_over_plain_http_in_production_the_agent_has_none_either() {
		unset( $_SERVER['HTTPS'] );
		$this->assertSame( 'production', wp_get_environment_type() );

		$this->assertFalse( wp_is_application_passwords_available_for_user( $this->admin() ), 'WordPress\'s own rule' );
		$this->assertFalse( wp_is_application_passwords_available_for_user( $this->agent() ), 'the plugin no longer overrides it' );
	}

	public function test_a_site_wide_switch_keeps_people_off_and_the_agent_on() {
		$_SERVER['HTTPS'] = 'on';
		add_filter( 'wp_is_application_passwords_available', '__return_false' );

		$admin = $this->admin();
		$agent = $this->agent();

		$this->assertFalse( wp_is_application_passwords_available_for_user( $admin ) );
		$this->assertTrue( wp_is_application_passwords_available_for_user( $agent ) );

		// And the real sign-in with a real password.
		list( $agent_password ) = WP_Application_Passwords::create_new_application_password( $agent->ID, array( 'name' => 'MCP' ) );
		list( $admin_password ) = WP_Application_Passwords::create_new_application_password( $admin->ID, array( 'name' => 'n8n' ) );

		$signed_in = wp_authenticate_application_password( null, $agent->user_login, $agent_password );
		$this->assertInstanceOf( WP_User::class, $signed_in );
		$this->assertSame( $agent->ID, $signed_in->ID );

		$refused = wp_authenticate_application_password( null, $admin->user_login, $admin_password );
		$this->assertWPError( $refused );
		$this->assertSame( 'application_passwords_disabled_for_user', $refused->get_error_code() );
	}

	public function test_a_per_user_switch_for_people_holds_and_does_not_reach_the_agent() {
		$_SERVER['HTTPS'] = 'on';
		add_filter( 'wp_is_application_passwords_available_for_user', '__return_false' );

		$this->assertFalse( wp_is_application_passwords_available_for_user( $this->admin() ) );
		$this->assertTrue( wp_is_application_passwords_available_for_user( $this->agent() ) );
	}

	public function test_a_site_that_never_switched_them_off_keeps_them_for_people() {
		$_SERVER['HTTPS'] = 'on';
		$editor = self::factory()->user->create_and_get( array( 'role' => 'editor' ) );

		$this->assertTrue( wp_is_application_passwords_available_for_user( $editor ) );
	}
}
