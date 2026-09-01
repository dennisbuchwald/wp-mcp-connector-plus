<?php
/**
 * Audit log: every connector action lands in a dedicated table, so any
 * customer question "what did the AI do to my site?" has a precise answer.
 *
 * @package dbw-connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Table name (with prefix).
 *
 * @return string
 */
function dbw_connector_audit_table() {
	global $wpdb;
	return $wpdb->prefix . 'dbw_connector_log';
}

/**
 * Create the audit table (activation).
 */
function dbw_connector_create_audit_table() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table   = dbw_connector_audit_table();
	$charset = $wpdb->get_charset_collate();

	dbDelta(
		"CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			ability VARCHAR(64) NOT NULL,
			post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			operation VARCHAR(32) NOT NULL DEFAULT '',
			dry_run TINYINT(1) NOT NULL DEFAULT 0,
			summary TEXT NOT NULL,
			revision_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY post_id (post_id),
			KEY created_at (created_at)
		) {$charset};"
	);
}

/**
 * Write one audit entry. Never throws — logging must not break the action.
 *
 * @param string $ability     Ability name (e.g. 'dbw/content-write').
 * @param array  $data        Optional: post_id, operation, dry_run, summary, revision_id.
 */
function dbw_connector_log( $ability, array $data = array() ) {
	global $wpdb;

	$wpdb->insert(
		dbw_connector_audit_table(),
		array(
			'created_at'  => current_time( 'mysql', true ),
			'user_id'     => get_current_user_id(),
			'ability'     => substr( (string) $ability, 0, 64 ),
			'post_id'     => (int) ( $data['post_id'] ?? 0 ),
			'operation'   => substr( (string) ( $data['operation'] ?? '' ), 0, 32 ),
			'dry_run'     => empty( $data['dry_run'] ) ? 0 : 1,
			'summary'     => (string) ( $data['summary'] ?? '' ),
			'revision_id' => (int) ( $data['revision_id'] ?? 0 ),
		),
		array( '%s', '%d', '%s', '%d', '%s', '%d', '%s', '%d' )
	);
}
