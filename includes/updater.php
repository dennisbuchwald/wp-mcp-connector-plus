<?php
/**
 * Updates straight from GitHub, so a customer site can be kept current
 * from the WordPress update screen instead of by SFTP.
 *
 * Release flow: bump the version in dbw-connector.php, tag it, publish a
 * GitHub release. Sites pick it up within a day, or immediately via
 * "Nach Updates suchen" on the plugin page.
 *
 * The repository ships its own vendor/ directory, because WordPress
 * installs the ZIP as-is and never runs Composer.
 *
 * @package dbw-connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GitHub repository the updates come from.
 * Override with DBW_CONNECTOR_REPO in wp-config.php when testing a fork.
 *
 * @return string
 */
function dbw_connector_repo_url() {
	if ( defined( 'DBW_CONNECTOR_REPO' ) && DBW_CONNECTOR_REPO ) {
		return DBW_CONNECTOR_REPO;
	}
	return 'https://github.com/dbwmedia/dbw-connector';
}

/**
 * Wire up the update checker.
 *
 * Only runs where WordPress actually checks for updates (admin, cron,
 * WP-CLI) — a normal page view must not pay for this.
 */
function dbw_connector_init_updater() {
	if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		return;
	}

	if ( ! class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
		return; // Dependencies missing — the connector itself still works.
	}

	$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		dbw_connector_repo_url(),
		DBW_CONNECTOR_FILE,
		'dbw-connector'
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
	 *   define( 'DBW_CONNECTOR_GITHUB_TOKEN', 'ghp_...' );
	 * A fine-grained token with read access to this one repository is enough.
	 */
	if ( defined( 'DBW_CONNECTOR_GITHUB_TOKEN' ) && DBW_CONNECTOR_GITHUB_TOKEN ) {
		$checker->setAuthentication( DBW_CONNECTOR_GITHUB_TOKEN );
	}
}
add_action( 'init', 'dbw_connector_init_updater' );
