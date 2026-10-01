<?php
/**
 * Deleting the plugin removes what it put on the site.
 *
 * Deactivating keeps everything, so that reactivating just works. Deleting
 * is the other decision: the agent is gone for good, so its credentials go
 * first, then its role, the audit log and every setting. The agent's user
 * accounts stay (they may have authored revisions and pages, and deleting
 * people is not a plugin's call) but they hold no role and no application
 * password any more, so they can no longer do anything.
 *
 * Runs without the plugin loaded, so it depends on nothing in includes/.
 *
 * @package wp-mcp-connector-plus
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Options this plugin writes.
 *
 * @return string[]
 */
function wpmcp_uninstall_options() {
	return array(
		'wpmcp_access_level',
		'wpmcp_live_edit',
		'wpmcp_pattern_access',
		'wpmcp_extra_post_types',
		'wpmcp_dynamic_data',
		'wpmcp_work_session_until',
		'wpmcp_db_version',
		// Written by the bundled update checker.
		'external_updates-wp-mcp-connector-plus',
	);
}

/**
 * Remove everything from the current site.
 */
function wpmcp_uninstall_site() {
	global $wpdb;

	// Credentials first: whatever else fails, the agent must not be able
	// to sign in afterwards.
	if ( class_exists( 'WP_Application_Passwords' ) ) {
		$agents = get_users(
			array(
				'role'   => 'wpmcp_ai_editor',
				'fields' => 'ID',
			)
		);
		foreach ( $agents as $user_id ) {
			WP_Application_Passwords::delete_all_application_passwords( (int) $user_id );
		}
	}

	remove_role( 'wpmcp_ai_editor' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table, name built from the prefix.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wpmcp_log" );

	foreach ( wpmcp_uninstall_options() as $option ) {
		delete_option( $option );
	}

	// The stamp behind the "changed by the AI agent" notice in the editor.
	delete_post_meta_by_key( '_wpmcp_last_write' );

	wp_clear_scheduled_hook( 'puc_cron_check_updates-wp-mcp-connector-plus' );
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $wpmcp_blog_id ) {
		switch_to_blog( (int) $wpmcp_blog_id );
		wpmcp_uninstall_site();
		restore_current_blog();
	}
} else {
	wpmcp_uninstall_site();
}
