<?php
/**
 * Reading, writing, duplicating and previewing content: the loader.
 *
 * The content tools used to be one file of more than 4000 lines. They now
 * live in the files below, grouped by what they decide, and this file only
 * loads them. It stays the one name to require: the plugin loads it in
 * wpmcp_load_abilities() (REST and WP-CLI only, never on a page view), and
 * a test that needs the content tools requires this file rather than
 * having to know how they are split.
 *
 * Every function in these files may call any other one: they are always
 * loaded together.
 *
 * @package wp-mcp-connector-plus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/post-types.php';
require_once __DIR__ . '/builders.php';
require_once __DIR__ . '/content-access.php';
require_once __DIR__ . '/content-read.php';
require_once __DIR__ . '/content-write.php';
require_once __DIR__ . '/content-create.php';
require_once __DIR__ . '/revisions.php';
require_once __DIR__ . '/markup-guard.php';
require_once __DIR__ . '/save-errors.php';
require_once __DIR__ . '/meta.php';
require_once __DIR__ . '/site-info.php';
require_once __DIR__ . '/responses.php';
