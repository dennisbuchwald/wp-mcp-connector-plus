<?php
/**
 * Plugin Name:       dbw Connector
 * Plugin URI:        https://github.com/dbwmedia/dbw-connector
 * Description:       KI-Konnektor für WordPress: stellt den Gutenberg-Blockbaum als WordPress-Abilities und MCP-Server bereit, damit eine KI die Seite bedienen kann wie ein Redakteur.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Author:            dbw media
 * Author URI:        https://dbw-media.de
 * License:           proprietary
 * Text Domain:       dbw-connector
 * Update URI:        https://github.com/dbwmedia/dbw-connector
 *
 * @package dbw-connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DBW_CONNECTOR_VERSION', '0.1.0' );
define( 'DBW_CONNECTOR_DIR', plugin_dir_path( __FILE__ ) );
define( 'DBW_CONNECTOR_FILE', __FILE__ );

/**
 * Kill switch: define DBW_CONNECTOR_DISABLE in wp-config.php to turn the
 * connector off without deactivating the plugin (e.g. during incidents).
 */
if ( defined( 'DBW_CONNECTOR_DISABLE' ) && DBW_CONNECTOR_DISABLE ) {
	return;
}

// Composer autoloader (mcp-adapter + php-mcp-schema via Jetpack autoloader).
if ( file_exists( DBW_CONNECTOR_DIR . 'vendor/autoload_packages.php' ) ) {
	require_once DBW_CONNECTOR_DIR . 'vendor/autoload_packages.php';
} elseif ( file_exists( DBW_CONNECTOR_DIR . 'vendor/autoload.php' ) ) {
	require_once DBW_CONNECTOR_DIR . 'vendor/autoload.php';
}

// Always cheap to load: pure function definitions, no hooks with runtime cost.
require_once DBW_CONNECTOR_DIR . 'includes/auth.php';
require_once DBW_CONNECTOR_DIR . 'includes/audit.php';
require_once DBW_CONNECTOR_DIR . 'includes/preview.php';
require_once DBW_CONNECTOR_DIR . 'includes/updater.php';

/**
 * The heavy lifting (tree transforms, validation, ability registration) is
 * only loaded in contexts that can actually call abilities: REST requests
 * and WP-CLI. Frontend page views never pay for it — the only frontend
 * hook is the O(1) preview-token gate in preview.php.
 */
function dbw_connector_load_abilities() {
	require_once DBW_CONNECTOR_DIR . 'includes/schema.php';
	require_once DBW_CONNECTOR_DIR . 'includes/tree.php';
	require_once DBW_CONNECTOR_DIR . 'includes/validate.php';
	require_once DBW_CONNECTOR_DIR . 'includes/catalog.php';
	require_once DBW_CONNECTOR_DIR . 'includes/content.php';
	require_once DBW_CONNECTOR_DIR . 'includes/abilities.php';

	dbw_connector_register_abilities();
}
add_action( 'wp_abilities_api_init', 'dbw_connector_load_abilities' );

/**
 * Boot the MCP adapter singleton (arms its own rest_api_init handler) and
 * register our curated server. The default adapter server (generic
 * discover/execute meta-tools) is disabled — only the dbw server exists.
 */
/**
 * The mcp-adapter release this plugin was built and tested against.
 * It is still 0.x and has had breaking changes between minor versions.
 */
const DBW_CONNECTOR_MCP_ADAPTER_VERSION = '0.6.1';

function dbw_connector_boot_mcp() {
	if ( ! class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
		return; // Composer deps missing — abilities still work via wp-abilities/v1 REST.
	}

	/*
	 * Another plugin on this site may bundle its own copy of mcp-adapter
	 * and win the autoloader race. Refuse to drive a version we were not
	 * built against rather than fail in subtle ways at request time —
	 * the abilities themselves keep working over wp-abilities/v1 REST.
	 */
	$loaded = defined( '\WP\MCP\Core\McpAdapter::VERSION' ) ? \WP\MCP\Core\McpAdapter::VERSION : 'unknown';
	if ( $loaded !== DBW_CONNECTOR_MCP_ADAPTER_VERSION ) {
		add_action(
			'admin_notices',
			function () use ( $loaded ) {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}
				printf(
					'<div class="notice notice-error"><p><strong>dbw Connector:</strong> Der MCP-Server wurde nicht gestartet. Erwartet wird mcp-adapter %s, geladen ist %s (vermutlich von einem anderen Plugin). Die Fähigkeiten bleiben über <code>wp-abilities/v1</code> erreichbar.</p></div>',
					esc_html( DBW_CONNECTOR_MCP_ADAPTER_VERSION ),
					esc_html( $loaded )
				);
			}
		);
		return;
	}

	add_filter( 'mcp_adapter_create_default_server', '__return_false' );

	\WP\MCP\Core\McpAdapter::instance();

	add_action(
		'mcp_adapter_init',
		function ( $adapter ) {
			$adapter->create_server(
				'dbw',
				'dbw-connector/v1',
				'mcp',
				'dbw Connector',
				'Block-tree level access to this dbw media WordPress site: discover blocks, read/write pages as block trees, duplicate and preview.',
				DBW_CONNECTOR_VERSION,
				array( \WP\MCP\Transport\HttpTransport::class ),
				\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
				null,
				dbw_connector_ability_names(),
				array(),
				array(),
				'dbw_connector_transport_permission'
			);
		}
	);
}
add_action( 'plugins_loaded', 'dbw_connector_boot_mcp' );

/**
 * Ability names exposed as MCP tools.
 *
 * @return string[]
 */
function dbw_connector_ability_names() {
	return array(
		'dbw/site-info',
		'dbw/blocks-catalog',
		'dbw/blocks-describe',
		'dbw/content-list',
		'dbw/content-read',
		'dbw/content-write',
		'dbw/content-duplicate',
		'dbw/content-preview',
	);
}

// Admin: audit log page + settings (only in admin context).
if ( is_admin() ) {
	require_once DBW_CONNECTOR_DIR . 'includes/admin.php';
}

// Activation: role, capabilities, audit table.
register_activation_hook( __FILE__, 'dbw_connector_activate' );

function dbw_connector_activate() {
	dbw_connector_register_role();
	dbw_connector_create_audit_table();
}

// Deactivation intentionally keeps role + table (no data loss, cheap).
