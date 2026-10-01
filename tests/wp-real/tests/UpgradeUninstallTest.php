<?php
/**
 * Update and uninstall on a real install: dbDelta, roles, cron, options.
 *
 * tests/upgrade.php and tests/uninstall.php count calls against shims.
 * Here the table is really dropped and really created, the role really
 * removed from the stored roles, the cron option really emptied.
 *
 * Both tests turn off the test library's habit of turning CREATE and DROP
 * TABLE into temporary-table statements: the point is the real table. On
 * SQLite the transaction around each test rolls DDL back as well.
 *
 * @package wp-mcp-connector-plus
 */

class UpgradeUninstallTest extends WPMCP_Real_TestCase {

	public function set_up() {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	public function test_an_update_recreates_table_role_option_and_cron_on_plugins_loaded() {
		global $wpdb;

		$this->assertSame( 10, has_action( 'plugins_loaded', 'wpmcp_maybe_upgrade' ) );

		// A site updated from a version that knew neither: no table, no
		// schema version, and the role deleted by hand.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . wpmcp_audit_table() );
		delete_option( 'wpmcp_db_version' );
		remove_role( WPMCP_ROLE );
		wp_clear_scheduled_hook( WPMCP_PRUNE_HOOK );
		$this->assertNull( $this->table() );

		// What plugins_loaded runs on the first request after the update.
		wpmcp_maybe_upgrade();

		$this->assertSame( wpmcp_audit_table(), $this->table(), 'the table is back' );
		$this->assertSame( WPMCP_DB_VERSION, (int) get_option( 'wpmcp_db_version' ) );
		$this->assertNotFalse( wp_next_scheduled( WPMCP_PRUNE_HOOK ), 'the log clean-up is scheduled' );

		$role = get_role( WPMCP_ROLE );
		$this->assertNotNull( $role, 'the role is back' );
		$this->assertSame( array( 'read' => true, WPMCP_CAP => true ), $role->capabilities );

		wpmcp_log( 'wpmcp/site-info', array( 'summary' => 'after the update' ) );
		$this->assertSame( '1', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . wpmcp_audit_table() ), 'the table takes entries' );

		// Once per version: the second request finds nothing to do.
		$wpdb->query( 'DROP TABLE ' . wpmcp_audit_table() );
		wpmcp_maybe_upgrade();
		$this->assertNull( $this->table(), 'a current site does not redo the work on every request' );
	}

	public function test_uninstall_removes_role_options_table_cron_and_credentials() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$agent = $this->agent();
		WP_Application_Passwords::create_new_application_password( $agent->ID, array( 'name' => 'MCP' ) );
		$this->assertCount( 1, WP_Application_Passwords::get_user_application_passwords( $agent->ID ) );

		$options = array(
			'wpmcp_access_level'                     => 'full',
			'wpmcp_live_edit'                        => 1,
			'wpmcp_pattern_access'                   => 'write',
			'wpmcp_extra_post_types'                 => array( 'product' ),
			'wpmcp_dynamic_data'                     => 'allow',
			'wpmcp_work_session_until'               => time() + 3600,
			'wpmcp_log_retention_days'               => 30,
			'external_updates-wp-mcp-connector-plus' => array( 'lastCheck' => time() ),
		);
		foreach ( $options as $name => $value ) {
			update_option( $name, $value );
		}

		$page = $this->page( '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' );
		update_post_meta( $page, '_wpmcp_last_write', time() );

		wp_schedule_single_event( time() + 60, 'wpmcp_purge_posts', array( array( $page ) ) );
		wp_schedule_event( time() + 60, 'daily', 'puc_cron_check_updates-wp-mcp-connector-plus' );
		$this->assertNotFalse( wp_next_scheduled( WPMCP_PRUNE_HOOK ) );
		$this->assertSame( wpmcp_audit_table(), $this->table() );

		$this->assertTrue( uninstall_plugin( WPMCP_REAL_PLUGIN ), 'WordPress found uninstall.php' );

		$this->assertNull( get_role( WPMCP_ROLE ), 'role gone' );
		$this->assertArrayNotHasKey( WPMCP_ROLE, get_option( wp_roles()->role_key ), 'and gone from the stored roles' );
		$this->assertNull( $this->table(), 'table gone' );

		foreach ( array_merge( array_keys( $options ), array( 'wpmcp_db_version' ) ) as $name ) {
			$this->assertFalse( get_option( $name ), "option {$name} gone" );
		}

		foreach ( array( WPMCP_PRUNE_HOOK, 'wpmcp_purge_posts', 'puc_cron_check_updates-wp-mcp-connector-plus' ) as $hook ) {
			$this->assertFalse( $this->scheduled( $hook ), "cron {$hook} gone" );
		}

		$this->assertSame( array(), WP_Application_Passwords::get_user_application_passwords( $agent->ID ), 'credentials revoked' );
		$this->assertSame( '', get_post_meta( $page, '_wpmcp_last_write', true ), 'editor stamp gone' );
		$this->assertInstanceOf( WP_User::class, get_userdata( $agent->ID ), 'the account itself stays' );
	}

	/**
	 * @return string|null The audit table, if it exists.
	 */
	private function table() {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', wpmcp_audit_table() ) );
	}

	/**
	 * Is any event of this hook scheduled, whatever its arguments?
	 *
	 * @param string $hook Hook.
	 * @return bool
	 */
	private function scheduled( $hook ) {
		foreach ( (array) _get_cron_array() as $events ) {
			if ( isset( $events[ $hook ] ) ) {
				return true;
			}
		}
		return false;
	}
}
