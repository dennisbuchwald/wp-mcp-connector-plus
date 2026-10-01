<?php
/**
 * Configuration for the WordPress test library, on SQLite.
 *
 * The test library installs WordPress into this database at the start of
 * every run (dropping whatever was there), so the file is disposable. The
 * DB_* credentials are required by the library and ignored by the SQLite
 * drop-in, which reads DB_DIR and DB_FILE instead.
 *
 * @package wp-mcp-connector-plus
 */

define( 'ABSPATH', __DIR__ . '/.cache/wordpress/' );

define( 'DB_NAME', 'wpmcp_tests' );
define( 'DB_USER', '' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST', '' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
define( 'DB_DIR', __DIR__ . '/.cache/db/' );
define( 'DB_FILE', 'tests.sqlite' );

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'WP MCP Connector Plus' );

// The library installs in a child process; the same interpreter, so a
// run on PHP 8.1 installs on PHP 8.1 too.
define( 'WP_PHP_BINARY', PHP_BINARY );

define( 'WPLANG', '' );
define( 'WP_DEBUG', true );

$table_prefix = 'wptests_';
