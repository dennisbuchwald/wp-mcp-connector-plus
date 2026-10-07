<?php
/**
 * The agent account reaches its MCP endpoint and nothing else, over a
 * request that arrives the way a real one does.
 *
 * rest_do_request() is no help here: it dispatches without the
 * authentication step, and the fence judges the route the request arrived
 * with. So each request goes the whole way: the query string is parsed by
 * WP::parse_request(), the credential is a real application password in
 * the Basic auth headers, and the server serves the route exactly as
 * rest_api_loaded() hands it over, through check_authentication().
 *
 * @package wp-mcp-connector-plus
 */

class RestFenceTest extends WPMCP_Real_TestCase {

	/** @var WP_User */
	private $agent;
	/** @var string */
	private $agent_password;
	/** @var WP_User */
	private $admin;
	/** @var string */
	private $admin_password;

	public function set_up() {
		parent::set_up();
		$this->set_level( 'draft' );

		$this->agent = $this->agent();
		$this->admin = $this->admin();
		list( $this->agent_password ) = WP_Application_Passwords::create_new_application_password( $this->agent->ID, array( 'name' => 'MCP' ) );
		list( $this->admin_password ) = WP_Application_Passwords::create_new_application_password( $this->admin->ID, array( 'name' => 'n8n' ) );

		// What rest_api_loaded() defines; the constant itself would outlive
		// the test.
		add_filter( 'application_password_is_api_request', '__return_true' );
		add_filter(
			'wp_rest_server_class',
			function () {
				return 'Spy_REST_Server';
			}
		);
	}

	public function test_the_agent_is_refused_on_wp_v2() {
		foreach ( array( '/wp/v2/pages', '/wp/v2/users/me', '/wp/v2/users/me/application-passwords', '/wp-abilities/v1/abilities', '/' ) as $route ) {
			$response = $this->serve( $route, $this->agent, $this->agent_password );
			$this->assertSame( 403, $response['status'], $route );
			$this->assertSame( 'wpmcp_rest_scope', $response['body']['code'] ?? null, $route );
			$this->assertSame( $this->agent->ID, get_current_user_id(), 'it was the agent, signed in by its application password' );
		}
	}

	public function test_the_agent_reaches_the_mcp_endpoint() {
		$response = $this->serve(
			'/wpmcp/v1/mcp',
			$this->agent,
			$this->agent_password,
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
				'params'  => array(
					'protocolVersion' => '2025-06-18',
					'capabilities'    => new stdClass(),
					'clientInfo'      => array(
						'name'    => 'wp-real-test',
						'version' => '1',
					),
				),
			)
		);

		$this->assertNotSame( 'wpmcp_rest_scope', $response['body']['code'] ?? null );
		$this->assertSame( 200, $response['status'], wp_json_encode( $response['body'] ) );
		$this->assertSame( 'WP MCP Connector Plus', $response['body']['result']['serverInfo']['name'] ?? null, wp_json_encode( $response['body'] ) );
	}

	/**
	 * Why site-info tells the agent to reconnect after the level was
	 * raised, rather than the server telling the client: the bundled
	 * mcp-adapter (0.6.1) says it will not send tool list changes, and has
	 * no stream to send them over. If a later adapter does, this test is
	 * the one that should fail first.
	 */
	public function test_the_adapter_cannot_tell_a_client_its_tool_list_changed() {
		$response = $this->serve(
			'/wpmcp/v1/mcp',
			$this->agent,
			$this->agent_password,
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
				'params'  => array(
					'protocolVersion' => '2025-06-18',
					'capabilities'    => new stdClass(),
					'clientInfo'      => array(
						'name'    => 'wp-real-test',
						'version' => '1',
					),
				),
			)
		);
		$this->assertFalse( $response['body']['result']['capabilities']['tools']['listChanged'] ?? null, wp_json_encode( $response['body'] ) );

		// GET is the stream a server sends notifications over.
		$response = $this->serve( '/wpmcp/v1/mcp', $this->agent, $this->agent_password );
		$this->assertSame( 405, $response['status'], wp_json_encode( $response['body'] ) );
	}

	public function test_a_spelling_wordpress_still_routes_is_judged_the_same() {
		$response = $this->serve( '/wpmcp/v1/mcp/', $this->agent, $this->agent_password, array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping' ) );
		$this->assertNotSame( 'wpmcp_rest_scope', $response['body']['code'] ?? null, 'trailing slash' );
	}

	public function test_the_administrator_is_unaffected() {
		$response = $this->serve( '/wp/v2/pages', $this->admin, $this->admin_password );
		$this->assertSame( 200, $response['status'], wp_json_encode( $response['body'] ) );
		$this->assertSame( $this->admin->ID, get_current_user_id() );
	}

	public function test_an_earlier_refusal_reaches_the_client_unchanged() {
		// Another check before the fence (a security plugin, say) has
		// already said no. The fence runs at 999 and must not replace it.
		add_filter(
			'rest_authentication_errors',
			function () {
				return new WP_Error( 'other_check', 'Refused by another check.', array( 'status' => 401 ) );
			},
			50
		);

		$response = $this->serve( '/wp/v2/pages', $this->agent, $this->agent_password );
		$this->assertSame( 401, $response['status'] );
		$this->assertSame( 'other_check', $response['body']['code'] ?? null );
	}

	public function test_the_agent_cannot_manage_its_own_account() {
		foreach ( array( 'create_app_password', 'edit_app_password', 'delete_app_passwords', 'edit_user', 'promote_user' ) as $cap ) {
			$this->assertFalse( user_can( $this->agent->ID, $cap, $this->agent->ID ), "{$cap} on itself" );
		}
		$this->assertTrue( user_can( $this->admin->ID, 'create_app_password', $this->agent->ID ), 'an administrator still sets the agent up' );
		$this->assertTrue( user_can( $this->admin->ID, 'create_app_password', $this->admin->ID ), 'and manages their own' );
	}
}
