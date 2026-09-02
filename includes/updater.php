<?php
/**
 * Updates straight from GitHub, so a customer site can be kept current
 * from the WordPress update screen instead of by SFTP.
 *
 * Release flow: bump the version in wp-mcp-connector-plus.php, tag it, publish a
 * GitHub release. Sites pick it up within a day, or immediately via
 * "Nach Updates suchen" on the plugin page.
 *
 * The repository ships its own vendor/ directory, because WordPress
 * installs the ZIP as-is and never runs Composer.
 *
 * @package wp-mcp-connector-plus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GitHub repository the updates come from.
 * Override with WPMCP_REPO in wp-config.php when testing a fork.
 *
 * @return string
 */
function wpmcp_repo_url() {
	if ( defined( 'WPMCP_REPO' ) && WPMCP_REPO ) {
		return WPMCP_REPO;
	}
	return 'https://github.com/dennisbuchwald/wp-mcp-connector-plus';
}

/**
 * Wire up the update checker.
 *
 * Only runs where WordPress actually checks for updates (admin, cron,
 * WP-CLI) — a normal page view must not pay for this.
 */
function wpmcp_init_updater() {
	if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		return;
	}

	if ( ! class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
		return; // Dependencies missing — the connector itself still works.
	}

	$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		wpmcp_repo_url(),
		WPMCP_FILE,
		'dbw-wp-mcp-connector-plus'
	);

	/*
	 * Updates come from published releases only, never from every push to
	 * a branch — that is PUC's default behaviour for a GitHub URL.
	 *
	 * If a release carries an attached ZIP, prefer it; otherwise GitHub's
	 * auto-generated source ZIP is used. Both contain vendor/, since it is
	 * committed to the repository.
	 */
	$api = $checker->getVcsApi();
	if ( method_exists( $api, 'enableReleaseAssets' ) ) {
		$api->enableReleaseAssets( '/\.zip$/i' );
	}

	/*
	 * Private repositories need a token. Put this in wp-config.php:
	 *   define( 'WPMCP_GITHUB_TOKEN', 'ghp_...' );
	 * A fine-grained token with read access to this one repository is enough.
	 */
	if ( defined( 'WPMCP_GITHUB_TOKEN' ) && WPMCP_GITHUB_TOKEN ) {
		$checker->setAuthentication( WPMCP_GITHUB_TOKEN );
	}
}
add_action( 'init', 'wpmcp_init_updater' );
