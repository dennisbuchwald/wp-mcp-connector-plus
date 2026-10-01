<?php
/**
 * Explaining a save that WordPress or a plugin refused.
 *
 * A bare "could not save" leaves an agent guessing; these name the
 * plugin whose filter refused and the reason, where it can be found.
 *
 * @package wp-mcp-connector-plus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Say who refused a save, when it was not this plugin.
 *
 * Validation passed, the dry run said yes, and then wp_update_post came
 * back with an error — from a plugin filtering saves, in a message the
 * connector has never seen before. That reads like the connector broke,
 * and the last time it happened it cost most of an afternoon and ended in
 * a proposal to hand the agent unfiltered_html, which would not have
 * helped: the content filter does not refuse saves, it rewrites content
 * silently. That is the whole reason the impact check exists.
 *
 * So the error says where it came from and names the plugins that filter
 * saves on this site, which turns guesswork into one thing to look at.
 *
 * @param \WP_Error $error  Error from the save.
 * @param array     $impact Result of wpmcp_kses_impact().
 * @return \WP_Error
 */
function wpmcp_explain_save_refusal( $error, array $impact ) {
	$message = $error->get_error_message();

	// The one refusal with a setting behind it. Left unexplained it read as
	// "the plugin filters my content", and three separate sprints ended up
	// editing the database around the API instead of reporting it.
	if ( wpmcp_looks_like_unfiltered_html( $message ) ) {
		$user = wp_get_current_user();

		return new \WP_Error(
			'wpmcp_dynamic_data_blocked',
			sprintf(
				'%s The block library on this site requires the unfiltered_html capability to save a page holding dynamic data, and the agent account (%s) does not have it — deliberately, because it permits storing arbitrary HTML and JavaScript. The connector can grant it for the length of a single save: set "Dynamic data" to allowed under Tools > MCP Connector. A write that newly introduces a script tag, an inline event handler or a javascript: URL is still refused then, and the capability never sits on the role. Editing the database directly with WP-CLI gets past this because it runs without a user, which means none of these checks happen at all — that is a way around the problem, not a fix for it.',
				rtrim( $message, ' .' ) . '.',
				$user && $user->user_login ? $user->user_login : 'the agent'
			),
			$error->get_error_data()
		);
	}

	$lines = array(
		rtrim( $message, ' .' ) . '.',
		'This refusal comes from WordPress or another plugin at save time, not from the connector: its own validation passed and the dry run reported no errors.',
	);

	if ( empty( $impact['introduces'] ) ) {
		$lines[] = 'The change itself adds no markup WordPress filters, so the objection concerns content that was already stored on this page.';
	}

	$plugins = wpmcp_save_filter_plugins();
	if ( ! empty( $plugins ) ) {
		$lines[] = sprintf(
			'Plugins filtering saves on this site: %s. One of them is refusing — check its settings, or make this change in the block editor where it runs as your own user.',
			implode( ', ', $plugins )
		);
	}

	$lines[] = 'Granting the agent unfiltered_html will not help here. That capability governs the content filter, which strips markup silently and never refuses a save.';

	return new \WP_Error( $error->get_error_code(), implode( ' ', $lines ), $error->get_error_data() );
}

/**
 * Is this refusal the unfiltered_html one wearing another plugin's words?
 *
 * Plugins phrase it their own way — "dynamic data", "your account doesn't
 * have permission to save" — and none of them name the capability. The
 * shape is recognisable enough to answer usefully, and guessing wrong
 * only means the caller gets the general explanation instead.
 *
 * @param string $message Error message from the save.
 * @return bool
 */
function wpmcp_looks_like_unfiltered_html( $message ) {
	$message = strtolower( (string) $message );

	if ( false !== strpos( $message, 'unfiltered_html' ) ) {
		return true;
	}

	return false !== strpos( $message, 'dynamic data' )
		&& false !== strpos( $message, 'permission' );
}

/**
 * Which plugins have a hand in what gets saved.
 *
 * Read-only look at the hook registry, on the error path only. Resolving
 * a callback to the file it lives in is what turns "something refused"
 * into a plugin name.
 *
 * @return string[] Plugin directory names, deduplicated.
 */
function wpmcp_save_filter_plugins() {
	global $wp_filter;

	$hooks   = array( 'wp_insert_post_data', 'wp_insert_post_empty_content', 'content_save_pre' );
	$plugins = array();

	foreach ( $hooks as $hook ) {
		if ( empty( $wp_filter[ $hook ] ) || ! isset( $wp_filter[ $hook ]->callbacks ) ) {
			continue;
		}

		foreach ( $wp_filter[ $hook ]->callbacks as $bucket ) {
			foreach ( $bucket as $registered ) {
				$file = wpmcp_callback_file( $registered['function'] ?? null );
				if ( ! $file ) {
					continue;
				}
				$slug = wpmcp_plugin_slug_from_path( $file );
				if ( $slug && ! in_array( $slug, $plugins, true ) ) {
					$plugins[] = $slug;
				}
			}
		}
	}

	sort( $plugins );

	return $plugins;
}

/**
 * The file a callback is defined in, or null when it cannot be resolved.
 *
 * @param mixed $callback Anything add_filter accepts.
 * @return string|null
 */
function wpmcp_callback_file( $callback ) {
	try {
		if ( is_string( $callback ) && function_exists( $callback ) ) {
			$reflection = new \ReflectionFunction( $callback );
		} elseif ( $callback instanceof \Closure ) {
			$reflection = new \ReflectionFunction( $callback );
		} elseif ( is_array( $callback ) && 2 === count( $callback ) ) {
			$reflection = new \ReflectionMethod( $callback[0], $callback[1] );
		} elseif ( is_object( $callback ) && method_exists( $callback, '__invoke' ) ) {
			$reflection = new \ReflectionMethod( $callback, '__invoke' );
		} else {
			return null;
		}
	} catch ( \Throwable $e ) {
		return null;
	}

	$file = $reflection->getFileName();

	return $file ? $file : null;
}

/**
 * Name the plugin a file belongs to, skipping core and this plugin.
 *
 * @param string $file Absolute path.
 * @return string|null
 */
function wpmcp_plugin_slug_from_path( $file ) {
	$file = wp_normalize_path( $file );
	$dir  = wp_normalize_path( defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '' );

	if ( '' === $dir || 0 !== strpos( $file, $dir . '/' ) ) {
		return null;
	}

	$relative = substr( $file, strlen( $dir ) + 1 );
	$slug     = strtok( $relative, '/' );

	// Not news to anyone: this plugin also filters saves.
	if ( ! $slug || 0 === strpos( $slug, 'wp-mcp-connector-plus' ) ) {
		return null;
	}

	return $slug;
}
