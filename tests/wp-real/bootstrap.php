<?php
/**
 * Boot a real WordPress with the plugin active.
 *
 * The WordPress test library installs a fresh site into the SQLite
 * database, then loads WordPress the way a request does. The plugin is not
 * required by hand: its slug goes into the stored active_plugins option
 * before WordPress reads it, so WordPress loads it from wp-content/plugins
 * like any active plugin, and then its activation hook runs, which is what
 * activate_plugin() does after including the file.
 *
 * GenerateBlocks is active next to it, as on the customer sites the
 * connector runs on (see setup.sh). It needs no activation step for what
 * the tests use: its blocks register on init.
 *
 * @package wp-mcp-connector-plus
 */

if ( ! is_file( __DIR__ . '/.cache/vendor/autoload.php' ) || ! is_dir( __DIR__ . '/.cache/wordpress' ) ) {
	fwrite( STDERR, "WordPress is not set up. Run: bash tests/wp-real/setup.sh\n" );
	exit( 1 );
}

require __DIR__ . '/.cache/vendor/autoload.php';

define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php' );
define( 'WPMCP_REAL_PLUGIN', 'wp-mcp-connector-plus/wp-mcp-connector-plus.php' );
define( 'WPMCP_REAL_GENERATEBLOCKS', 'generateblocks/plugin.php' );

$wpmcp_tests_lib = getenv( 'WP_PHPUNIT__DIR' );
require $wpmcp_tests_lib . '/includes/functions.php';

// muplugins_loaded fires right before WordPress reads active_plugins.
tests_add_filter(
	'muplugins_loaded',
	function () {
		update_option( 'active_plugins', array( WPMCP_REAL_GENERATEBLOCKS, WPMCP_REAL_PLUGIN ) );
	}
);

require $wpmcp_tests_lib . '/includes/bootstrap.php';

if ( ! defined( 'GENERATEBLOCKS_VERSION' ) ) {
	fwrite( STDERR, "GenerateBlocks did not load from wp-content/plugins. Run: bash tests/wp-real/setup.sh\n" );
	exit( 1 );
}

if ( ! function_exists( 'wpmcp_activate' ) ) {
	fwrite( STDERR, "The plugin did not load from wp-content/plugins. Run: bash tests/wp-real/setup.sh\n" );
	exit( 1 );
}

do_action( 'activate_' . WPMCP_REAL_PLUGIN, false );

require __DIR__ . '/includes/class-wpmcp-real-testcase.php';
