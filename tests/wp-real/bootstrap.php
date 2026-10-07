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
 * SiteGround Speed Optimizer is active only in the SiteGround suite
 * (phpunit-siteground.xml.dist sets WPMCP_REAL_SITEGROUND), for the same
 * reason.
 *
 * Elementor is active only in the Elementor suite
 * (phpunit-elementor.xml.dist sets WPMCP_REAL_ELEMENTOR): everything else
 * keeps running on a site without it, the way the connector ran before it
 * had Elementor tools, and the Elementor tests run on a site that has it.
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
define( 'WPMCP_REAL_ELEMENTOR', 'elementor/elementor.php' );
define( 'WPMCP_REAL_WITH_ELEMENTOR', '1' === getenv( 'WPMCP_REAL_ELEMENTOR' ) );
define( 'WPMCP_REAL_SITEGROUND', 'sg-cachepress/sg-cachepress.php' );
define( 'WPMCP_REAL_WITH_SITEGROUND', '1' === getenv( 'WPMCP_REAL_SITEGROUND' ) );

/**
 * Elementor 4.3.4 is not clean on PHP 8.4+: some of its files declare
 * implicitly nullable parameters, which PHP reports as deprecated when it
 * compiles the file. That is Elementor's noise, not the connector's, and
 * the runner fails on every "Deprecated:" it sees. So deprecations raised
 * from inside the Elementor folder are dropped, and only those: anything
 * from the connector, WordPress or the tests still counts.
 *
 * One warning is dropped as well, by its exact text: Elementor's content
 * sanitizer (modules/content-sanitizer/module.php) reads "widgetType" on
 * every element of a page saved by an account without manage_options,
 * containers included, which have none. Every save of a page with a
 * heading widget by an editor raises it on a real site too.
 *
 * Installed for the bootstrap only, and removed again once WordPress has
 * loaded: PHPUnit 9 installs its own handler for a test only when none is
 * set, so one left standing would turn every warning in the suite into
 * noise instead of a failure. WPMCP_Real_Elementor_TestCase puts the
 * filter in front of PHPUnit's handler for each test.
 *
 * @param callable|null $next Handler to pass everything else to.
 * @return callable
 */
function wpmcp_real_elementor_noise_filter( $next = null ) {
	$folder = wpmcp_real_normalize_path( __DIR__ . '/.cache/wordpress/wp-content/plugins/elementor/' );
	return function ( $errno, $errstr, $errfile = '', $errline = 0 ) use ( $next, $folder ) {
		$file = wpmcp_real_normalize_path( (string) $errfile );
		if ( 0 === strpos( $file, $folder ) ) {
			if ( in_array( $errno, array( E_DEPRECATED, E_USER_DEPRECATED ), true ) ) {
				return true;
			}
			if ( E_WARNING === $errno && 'Undefined array key "widgetType"' === $errstr && 'modules/content-sanitizer/module.php' === substr( $file, strlen( $folder ) ) ) {
				return true;
			}
		}
		return $next ? $next( $errno, $errstr, $errfile, $errline ) : false;
	};
}

/**
 * Forward slashes, before WordPress (and its wp_normalize_path) exists.
 *
 * @param string $path Path.
 * @return string
 */
function wpmcp_real_normalize_path( $path ) {
	return str_replace( '\\', '/', (string) $path );
}

if ( WPMCP_REAL_WITH_ELEMENTOR ) {
	set_error_handler( wpmcp_real_elementor_noise_filter() );
}

$wpmcp_tests_lib = getenv( 'WP_PHPUNIT__DIR' );
require $wpmcp_tests_lib . '/includes/functions.php';

/*
 * Speed Optimizer 7.8.4 loads its translations while it is loaded, before
 * init, and WordPress 6.7+ reports that as doing it wrong. Its notice,
 * not the connector's: dropped for that one domain and nothing else.
 */
if ( WPMCP_REAL_WITH_SITEGROUND ) {
	tests_add_filter(
		'doing_it_wrong_trigger_error',
		function ( $trigger, $function_name, $message ) {
			if ( '_load_textdomain_just_in_time' === $function_name && false !== strpos( (string) $message, '<code>sg-cachepress</code>' ) ) {
				return false;
			}
			return $trigger;
		},
		10,
		3
	);
}

// muplugins_loaded fires right before WordPress reads active_plugins.
tests_add_filter(
	'muplugins_loaded',
	function () {
		$active = array( WPMCP_REAL_GENERATEBLOCKS, WPMCP_REAL_PLUGIN );
		if ( WPMCP_REAL_WITH_ELEMENTOR ) {
			array_unshift( $active, WPMCP_REAL_ELEMENTOR );
		}
		if ( WPMCP_REAL_WITH_SITEGROUND ) {
			array_unshift( $active, WPMCP_REAL_SITEGROUND );
		}
		update_option( 'active_plugins', $active );
	}
);

require $wpmcp_tests_lib . '/includes/bootstrap.php';

if ( ! defined( 'GENERATEBLOCKS_VERSION' ) ) {
	fwrite( STDERR, "GenerateBlocks did not load from wp-content/plugins. Run: bash tests/wp-real/setup.sh\n" );
	exit( 1 );
}

if ( WPMCP_REAL_WITH_ELEMENTOR ) {
	if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
		fwrite( STDERR, "Elementor did not load from wp-content/plugins. Run: bash tests/wp-real/setup.sh\n" );
		exit( 1 );
	}
	restore_error_handler();
}

if ( WPMCP_REAL_WITH_SITEGROUND && ! function_exists( 'sg_cachepress_purge_cache' ) ) {
	fwrite( STDERR, "SiteGround Speed Optimizer did not load from wp-content/plugins. Run: bash tests/wp-real/setup.sh\n" );
	exit( 1 );
}

if ( ! function_exists( 'wpmcp_activate' ) ) {
	fwrite( STDERR, "The plugin did not load from wp-content/plugins. Run: bash tests/wp-real/setup.sh\n" );
	exit( 1 );
}

do_action( 'activate_' . WPMCP_REAL_PLUGIN, false );

require __DIR__ . '/includes/class-wpmcp-real-testcase.php';
if ( WPMCP_REAL_WITH_ELEMENTOR ) {
	require __DIR__ . '/includes/class-wpmcp-real-elementor-testcase.php';
}
