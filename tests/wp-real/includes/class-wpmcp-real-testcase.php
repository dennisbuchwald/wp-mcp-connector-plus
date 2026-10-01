<?php
/**
 * Base class for the real-WordPress tests.
 *
 * Every test runs inside a database transaction the test library rolls
 * back afterwards; on SQLite that includes dropped and created tables.
 * What lives in memory instead of the database is put back here: the
 * roles object, the Abilities API registries and the MCP adapter
 * (singletons that set up once per process, where a real site does so
 * once per request) and the request globals a test sets up.
 *
 * @package wp-mcp-connector-plus
 */

abstract class WPMCP_Real_TestCase extends WP_UnitTestCase {

	/** @var array Snapshot of $_SERVER, $_GET and $_REQUEST. */
	private $request_globals = array();

	public function set_up() {
		parent::set_up();
		$this->request_globals = array( $_SERVER, $_GET, $_REQUEST );
		self::reset_abilities();
		self::reset_mcp_adapter();
	}

	public function tear_down() {
		list( $_SERVER, $_GET, $_REQUEST ) = $this->request_globals;
		unset( $GLOBALS['HTTP_RAW_POST_DATA'], $GLOBALS['wp_rest_application_password_status'], $GLOBALS['wp_rest_application_password_uuid'] );
		$GLOBALS['wp']->query_vars = array();
		$GLOBALS['wp_rest_server'] = null;

		parent::tear_down();

		// The rollback restored the roles option; the object still holds
		// what the test did to it.
		wp_roles()->for_site();
		self::reset_abilities();
	}

	/**
	 * Forget the registered abilities, so the next look registers them
	 * again from the plugin's current settings, through the real
	 * wp_abilities_api_init hook.
	 */
	protected static function reset_abilities() {
		foreach ( array( 'WP_Abilities_Registry', 'WP_Ability_Categories_Registry' ) as $class ) {
			$property = new ReflectionProperty( $class, 'instance' );
			if ( PHP_VERSION_ID < 80100 ) {
				$property->setAccessible( true );
			}
			$property->setValue( null, null );
		}
	}

	/**
	 * Let the MCP adapter set up its servers again on the next
	 * rest_api_init, as on the first REST request of a real site. Its
	 * route hooks from an earlier test went with that test's hooks.
	 */
	protected static function reset_mcp_adapter() {
		$class = '\\WP\\MCP\\Core\\McpAdapter';
		if ( ! class_exists( $class ) ) {
			return;
		}
		$adapter = $class::instance();
		foreach ( array( 'servers' => array(), 'initialized' => false ) as $name => $value ) {
			$property = new ReflectionProperty( $class, $name );
			if ( PHP_VERSION_ID < 80100 ) {
				$property->setAccessible( true );
			}
			$property->isStatic() ? $property->setValue( null, $value ) : $property->setValue( $adapter, $value );
		}
	}

	/**
	 * A user holding only the agent role.
	 *
	 * @return WP_User
	 */
	protected function agent() {
		return self::factory()->user->create_and_get( array( 'role' => WPMCP_ROLE ) );
	}

	/**
	 * @return WP_User
	 */
	protected function admin() {
		return self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );
	}

	/**
	 * Act as this user, the way a request authenticated as them would.
	 *
	 * @param WP_User $user User.
	 */
	protected function act_as( WP_User $user ) {
		wp_set_current_user( $user->ID );
	}

	/**
	 * Set the access level the site owner chose.
	 *
	 * @param string $level read, draft or full.
	 */
	protected function set_level( $level ) {
		update_option( 'wpmcp_access_level', $level );
		self::reset_abilities();
	}

	/**
	 * Open a work session.
	 */
	protected function open_session() {
		wpmcp_start_work_session( 1 );
		self::reset_abilities();
	}

	/**
	 * A page stored by an administrator, with its content byte for byte
	 * as given (slashed for wp_insert_post, which unslashes).
	 *
	 * @param string $content Post content.
	 * @param string $status  Post status.
	 * @return int Post ID.
	 */
	protected function page( $content, $status = 'draft' ) {
		$previous = get_current_user_id();
		wp_set_current_user( $this->admin()->ID );
		$id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => 'page',
					'post_title'   => 'Test page',
					'post_status'  => $status,
					'post_content' => $content,
				)
			),
			true
		);
		wp_set_current_user( $previous );
		$this->assertIsInt( $id );
		return $id;
	}

	/**
	 * What the database holds for a post, past every cache.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $field   Column.
	 * @return string|null
	 */
	protected function stored( $post_id, $field = 'post_content' ) {
		global $wpdb;
		$allowed = array( 'post_content', 'post_status', 'post_title', 'post_type' );
		$this->assertContains( $field, $allowed );
		return $wpdb->get_var( $wpdb->prepare( "SELECT {$field} FROM {$wpdb->posts} WHERE ID = %d", $post_id ) );
	}

	/**
	 * Run an ability through the Abilities API, as wp-abilities/v1 and the
	 * MCP adapter do: input validation, permission callback, execute.
	 *
	 * @param string     $name  Ability name.
	 * @param array|null $input Input.
	 * @return mixed
	 */
	protected function execute( $name, $input = null ) {
		$ability = wp_get_ability( $name );
		$this->assertInstanceOf( WP_Ability::class, $ability, "{$name} is registered" );
		return $ability->execute( $input );
	}

	/**
	 * Was the call refused, with nothing saved?
	 *
	 * @param mixed $result Ability result.
	 * @return bool
	 */
	protected function refused( $result ) {
		return is_wp_error( $result ) || ( is_array( $result ) && empty( $result['ok'] ) );
	}

	/**
	 * The refusal as one line, for assertion messages.
	 *
	 * @param mixed $result Ability result.
	 * @return string
	 */
	protected function explain( $result ) {
		if ( is_wp_error( $result ) ) {
			return $result->get_error_code() . ': ' . $result->get_error_message();
		}
		return wp_json_encode( $result );
	}
}
