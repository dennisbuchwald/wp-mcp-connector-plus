<?php
/**
 * Page builders the connector knows.
 *
 * Always loaded (through access.php), because the tool list depends on
 * it, and loaded with the content tools as well (content.php), which ask
 * it page by page. The Elementor tools themselves load only where it
 * answers yes (includes/elementor/).
 *
 * @package wp-mcp-connector-plus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Is Elementor running on this site?
 *
 * Decides whether the Elementor tools are registered and their files
 * loaded (wpmcp_load_abilities()). Asked late enough: the tool list is
 * built on rest_api_init, long after plugins_loaded, where Elementor
 * announces itself.
 *
 * @return bool
 */
function wpmcp_elementor_active() {
	return function_exists( 'did_action' ) && did_action( 'elementor/loaded' ) > 0 && class_exists( '\\Elementor\\Plugin', false );
}
