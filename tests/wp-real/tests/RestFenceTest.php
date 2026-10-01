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

	/**
	 * Serve one request as it arrives from outside.
	 *
	 * @param string     $route    REST route.
	 * @param WP_User    $user     Whose application password signs it.
	 * @param string     $password The password.
	 * @param array|null $body     JSON body; a POST when given, else a GET.
	 * @return array { status: int, body: array|null }
	 */
	private function serve( $route, WP_User $user, $password, $body = null ) {
		$_SERVER['HTTPS']         = 'on';
		$_SERVER['PHP_AUTH_USER'] = $user->user_login;
		$_SERVER['PHP_AUTH_PW']   = $password;
		$_SERVER['REQUEST_METHOD'] = null === $body ? 'GET' : 'POST';
		$_SERVER['QUERY_STRING']  = 'rest_route=' . rawurlencode( $route );
		$_SERVER['REQUEST_URI']   = '/?' . $_SERVER['QUERY_STRING'];
		$_GET                     = array( 'rest_route' => $route );

		unset( $_SERVER['CONTENT_TYPE'], $_SERVER['HTTP_ACCEPT'] );
		if ( null !== $body ) {
			$_SERVER['CONTENT_TYPE']      = 'application/json';
			$_SERVER['HTTP_ACCEPT']       = 'application/json, text/event-stream';
			$GLOBALS['HTTP_RAW_POST_DATA'] = wp_json_encode( $body );
		} else {
			unset( $GLOBALS['HTTP_RAW_POST_DATA'] );
		}

		// Nobody is signed in when a request starts.
		wp_set_current_user( 0 );

		// WP::parse_request() fires parse_request, where rest_api_loaded()
		// would serve the request and exit. The same steps follow below.
		// The test library gives every test a fresh WP object; on a real
		// request rest_api_init() registers the query var on init.
		remove_action( 'parse_request', 'rest_api_loaded' );
		$GLOBALS['wp']->add_query_var( 'rest_route' );
		$GLOBALS['wp']->query_vars = array();
		$GLOBALS['wp']->parse_request();
		add_action( 'parse_request', 'rest_api_loaded' );

		$this->assertSame( $route, $GLOBALS['wp']->query_vars['rest_route'] ?? null, 'WordPress read the route from the request' );

		$GLOBALS['wp_rest_server'] = null;
		$server                    = rest_get_server();
		$served                    = untrailingslashit( $GLOBALS['wp']->query_vars['rest_route'] );
		$server->serve_request( '' === $served ? '/' : $served );

		return array(
			'status' => (int) $server->status,
			'body'   => json_decode( $server->sent_body, true ),
		);
	}
}
