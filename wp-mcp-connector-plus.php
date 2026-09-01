<?php
/**
 * Plugin Name:       WP MCP Connector Plus
 * Plugin URI:        https://github.com/dennisbuchwald/wp-mcp-connector-plus
 * Description:       MCP server for WordPress that lets AI agents operate a site the way an editor does: reads and writes pages as a Gutenberg block tree, exposes the block kit with its schemas and nesting rules, and validates every change before it is saved.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Author:            dbw media
 * Author URI:        https://dbw-media.de
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-mcp-connector-plus
 * Update URI:        https://github.com/dennisbuchwald/wp-mcp-connector-plus
 *
 * @package wp-mcp-connector-plus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPMCP_VERSION', '0.1.0' );
define( 'WPMCP_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPMCP_FILE', __FILE__ );

/**
 * Kill switch: define WPMCP_DISABLE in wp-config.php to turn the
 * connector off without deactivating the plugin (e.g. during incidents).
 */
if ( defined( 'WPMCP_DISABLE' ) && WPMCP_DISABLE ) {
	return;
}

/**
 * Requirements notice. The abilities hook simply never fires without the
 * Abilities API (WordPress 6.9), so nothing breaks — it just silently does
 * nothing, which is the worst way to fail. Check late, so an Abilities API
 * supplied by another plugin still counts, and never bail out early.
 */
function wpmcp_requirements_notice() {
	if ( function_exists( 'wp_register_ability' ) || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	global $wp_version;
	printf(
		'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
		esc_html__( 'WP MCP Connector Plus is inactive.', 'wp-mcp-connector-plus' ),
		sprintf(
			/* translators: %s: current WordPress version */
			esc_html__( 'It needs the Abilities API, which ships with WordPress 6.9. This site runs %s, so no abilities were registered. Update WordPress to use the connector.', 'wp-mcp-connector-plus' ),
			esc_html( $wp_version )
		)
	);
}
add_action( 'admin_notices', 'wpmcp_requirements_notice' );

// Composer autoloader (mcp-adapter + php-mcp-schema via Jetpack autoloader).
if ( file_exists( WPMCP_DIR . 'vendor/autoload_packages.php' ) ) {
	require_once WPMCP_DIR . 'vendor/autoload_packages.php';
} elseif ( file_exists( WPMCP_DIR . 'vendor/autoload.php' ) ) {
	require_once WPMCP_DIR . 'vendor/autoload.php';
}

// Always cheap to load: pure function definitions, no hooks with runtime cost.
require_once WPMCP_DIR . 'includes/auth.php';
require_once WPMCP_DIR . 'includes/audit.php';
require_once WPMCP_DIR . 'includes/preview.php';
require_once WPMCP_DIR . 'includes/updater.php';

/**
 * The heavy lifting (tree transforms, validation, ability registration) is
 * only loaded in contexts that can actually call abilities: REST requests
 * and WP-CLI. Frontend page views never pay for it — the only frontend
 * hook is the O(1) preview-token gate in preview.php.
 */
function wpmcp_load_abilities() {
	require_once WPMCP_DIR . 'includes/schema.php';
	require_once WPMCP_DIR . 'includes/tree.php';
	require_once WPMCP_DIR . 'includes/validate.php';
	require_once WPMCP_DIR . 'includes/catalog.php';
	require_once WPMCP_DIR . 'includes/content.php';
	require_once WPMCP_DIR . 'includes/abilities.php';

	wpmcp_register_abilities();
}
add_action( 'wp_abilities_api_init', 'wpmcp_load_abilities' );

/**
 * The mcp-adapter release this plugin was built and tested against.
 * It is still 0.x and has had breaking changes between minor versions.
 */
const WPMCP_MCP_ADAPTER_VERSION = '0.6.1';

/**
 * Boot the MCP adapter singleton (it arms its own rest_api_init handler)
 * and register our curated server. The adapter's own default server —
 * generic discover/execute meta-tools — is switched off, so a client sees
 * only the eight content abilities and nothing else.
 */
function wpmcp_boot_mcp() {
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
	if ( $loaded !== WPMCP_MCP_ADAPTER_VERSION ) {
		add_action(
			'admin_notices',
			function () use ( $loaded ) {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}
				printf(
					'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
					esc_html__( 'WP MCP Connector Plus:', 'wp-mcp-connector-plus' ),
					sprintf(
						/* translators: 1: expected mcp-adapter version, 2: version actually loaded */
						esc_html__( 'The MCP server was not started. This plugin expects mcp-adapter %1$s, but %2$s is loaded — most likely bundled by another plugin. The abilities themselves remain reachable over wp-abilities/v1.', 'wp-mcp-connector-plus' ),
						esc_html( WPMCP_MCP_ADAPTER_VERSION ),
						esc_html( $loaded )
					)
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
				'site',
				'wpmcp/v1',
				'mcp',
				'WP MCP Connector Plus',
				'Block-tree level access to this WordPress site: discover the block kit with its schemas and nesting rules, read and write pages as block trees, duplicate pages and preview the result.',
				WPMCP_VERSION,
				array( \WP\MCP\Transport\HttpTransport::class ),
				\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
				null,
				wpmcp_ability_names(),
				array(),
				array(),
				'wpmcp_transport_permission'
			);
		}
	);
}
add_action( 'plugins_loaded', 'wpmcp_boot_mcp' );

/**
 * Ability names exposed as MCP tools.
 *
 * @return string[]
 */
function wpmcp_ability_names() {
	return array(
		'wpmcp/site-info',
		'wpmcp/blocks-catalog',
		'wpmcp/blocks-describe',
		'wpmcp/content-list',
		'wpmcp/content-read',
		'wpmcp/content-write',
		'wpmcp/content-duplicate',
		'wpmcp/content-preview',
	);
}

// Admin: audit log page + settings (only in admin context).
if ( is_admin() ) {
	require_once WPMCP_DIR . 'includes/admin.php';
}

// Activation: role, capabilities, audit table.
register_activation_hook( __FILE__, 'wpmcp_activate' );

function wpmcp_activate() {
	wpmcp_register_role();
	wpmcp_create_audit_table();
}

// Deactivation intentionally keeps role + table (no data loss, cheap).
