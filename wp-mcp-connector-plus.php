<?php
/**
 * Plugin Name:       WP MCP Connector Plus
 * Plugin URI:        https://dennisbuchwald.de/apps/wp-mcp-connector-plus
 * Description:       MCP server for WordPress that lets AI agents operate a site the way an editor does: reads and writes pages as a Gutenberg block tree, exposes the block kit with its schemas and nesting rules, and validates every change before it is saved.
 * Version:           0.6.1
 * Requires at least: 6.9
 * Tested up to:      7.0
 * Requires PHP:      8.1
 * Author:            Dennis Buchwald
 * Author URI:        https://dennisbuchwald.de
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-mcp-connector-plus
 * Domain Path:       /languages
 * Update URI:        https://github.com/dennisbuchwald/wp-mcp-connector-plus
 *
 * @package wp-mcp-connector-plus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPMCP_VERSION', '0.6.1' );
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
require_once WPMCP_DIR . 'includes/access.php';
require_once WPMCP_DIR . 'includes/audit.php';
require_once WPMCP_DIR . 'includes/preview.php';
require_once WPMCP_DIR . 'includes/updater.php';

/**
 * The ability category slug. Defined once and used both where the category
 * is registered and where every ability references it — a mismatch between
 * the two is silent (registration simply fails) and cost a debugging round
 * once already.
 */
const WPMCP_ABILITY_CATEGORY = 'wpmcp';

/**
 * Ability categories are registered on their own hook, which fires BEFORE
 * wp_abilities_api_init. It therefore cannot live in includes/abilities.php,
 * which is only loaded on that later hook — the category would never exist
 * and every ability registration would be rejected for naming a category
 * that is not there.
 *
 * Kept inline so nothing extra has to load this early.
 */
function wpmcp_register_category() {
	if ( ! function_exists( 'wp_register_ability_category' ) ) {
		return;
	}
	wp_register_ability_category(
		WPMCP_ABILITY_CATEGORY,
		array(
			'label'       => 'MCP Connector Plus',
			'description' => 'Block-tree level access to this WordPress site.',
		)
	);
}
add_action( 'wp_abilities_api_categories_init', 'wpmcp_register_category' );

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
	require_once WPMCP_DIR . 'includes/search.php';
	require_once WPMCP_DIR . 'includes/cache.php';
	require_once WPMCP_DIR . 'includes/abilities.php';

	wpmcp_register_abilities();
}
add_action( 'wp_abilities_api_init', 'wpmcp_load_abilities' );

/**
 * The mcp-adapter release this plugin was built and tested against.
 * It is still 0.x and has had breaking changes between minor versions.
 */
/**
 * mcp-adapter releases this plugin has been checked against. Used only for
 * the diagnostic message — compatibility itself is decided by the API
 * shape below, not by a version string.
 */
const WPMCP_MCP_ADAPTER_TESTED = array( '0.4.1', '0.5.0', '0.6.1' );

/**
 * Is the loaded mcp-adapter one we can drive?
 *
 * The adapter ships inside other plugins too (Rank Math SEO bundles it,
 * for one), and whichever copy loads first wins the autoloader. Pinning an
 * exact version would mean refusing to run on a large share of real sites
 * for no reason: the surface we touch — one method, two class constants,
 * two hooks — is identical across the versions in the wild.
 *
 * So check what we actually depend on instead of what it calls itself.
 *
 * @return bool
 */
function wpmcp_adapter_is_usable() {
	$needed = array(
		'\\WP\\MCP\\Core\\McpAdapter',
		'\\WP\\MCP\\Transport\\HttpTransport',
		'\\WP\\MCP\\Infrastructure\\ErrorHandling\\ErrorLogMcpErrorHandler',
	);
	foreach ( $needed as $class ) {
		if ( ! class_exists( $class ) ) {
			return false;
		}
	}

	if ( ! method_exists( '\\WP\\MCP\\Core\\McpAdapter', 'create_server' ) ) {
		return false;
	}

	// We pass thirteen positional arguments; anything narrower is a
	// different API and must not be called.
	try {
		$method = new ReflectionMethod( '\\WP\\MCP\\Core\\McpAdapter', 'create_server' );
	} catch ( \ReflectionException $e ) {
		return false;
	}

	return $method->getNumberOfParameters() >= 13;
}

/**
 * Boot the MCP adapter singleton (it arms its own rest_api_init handler)
 * and register our curated server.
 *
 * Our server has its own id and route, so it sits alongside any server
 * another plugin registers rather than replacing it.
 */
function wpmcp_boot_mcp() {
	if ( ! class_exists( '\\WP\\MCP\\Core\\McpAdapter' ) ) {
		return; // Composer deps missing — abilities still work via wp-abilities/v1 REST.
	}

	if ( ! wpmcp_adapter_is_usable() ) {
		$loaded = defined( '\\WP\\MCP\\Core\\McpAdapter::VERSION' ) ? \WP\MCP\Core\McpAdapter::VERSION : 'unknown';
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
						/* translators: 1: loaded mcp-adapter version, 2: comma-separated list of tested versions */
						esc_html__( 'The MCP server was not started. Another plugin loaded mcp-adapter %1$s, whose interface this plugin cannot drive (tested against %2$s). The abilities themselves remain reachable over wp-abilities/v1.', 'wp-mcp-connector-plus' ),
						esc_html( $loaded ),
						esc_html( implode( ', ', WPMCP_MCP_ADAPTER_TESTED ) )
					)
				);
			}
		);
		return;
	}

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
