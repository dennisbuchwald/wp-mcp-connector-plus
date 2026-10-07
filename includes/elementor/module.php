<?php
/**
 * Elementor pages: the loader.
 *
 * Classic Elementor pages (containers, sections, widgets) keep their
 * content in the `_elementor_data` post meta as a JSON tree of elements,
 * and `post_content` only holds a plain-text copy for search. The block
 * tools cannot work there: what they read is that copy, and what they
 * write is replaced by Elementor on the next render. These files give the
 * agent the element tree instead, with the guarantees the block tools
 * give: dry run by default, expected_modified, the edit lock, the markup
 * guard per changed string, a revision, the activity log and a cache
 * purge.
 *
 * Loaded by wpmcp_load_abilities() only when Elementor is active
 * (wpmcp_elementor_active() in access.php), and after content.php, whose
 * functions these use. Nothing that loads on every request calls into
 * them (tests/load-boundaries.php).
 *
 * - data.php: the element tree, ids, paths, operations. No WordPress.
 * - outline.php: what an HTML widget says (headings, texts, links, images).
 * - guard.php: what a change may store, and how Elementor's save is run.
 * - controls.php: settings checked against Elementor's registered controls.
 * - tools.php: elementor-read and elementor-write.
 *
 * @package wp-mcp-connector-plus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/data.php';
require_once __DIR__ . '/outline.php';
require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/controls.php';
require_once __DIR__ . '/tools.php';
