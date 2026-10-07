<?php
/**
 * site-info: the fingerprint an agent reads first in every session.
 *
 * @package wp-mcp-connector-plus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Active plugins that change what content work means here.
 *
 * Not an inventory. Which SEO plugin runs decides which meta keys exist,
 * a form or page-builder plugin decides what the markup on a page is
 * allowed to be, and a caching plugin decides whether a live check can be
 * trusted. Without this an agent infers all of it from failures.
 *
 * Only active plugins, and only what they are and which version — enough
 * to work with, not a security report for anyone who gets the credentials.
 *
 * @return array<int, array{name: string, version: string, kind: string}>
 */
function wpmcp_relevant_plugins() {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	if ( ! function_exists( 'get_plugins' ) ) {
		return array();
	}

	// What a plugin means for content work, by what its folder is called.
	$kinds = array(
		'seo'        => '/(seo|rank-math|yoast|aioseo|schema)/i',
		'forms'      => '/(form|contact|wpcf7|gravity|ninja|fluent)/i',
		'blocks'     => '/(block|generate|kadence|stackable|spectra|greenshift)/i',
		'builder'    => '/(elementor|beaver|divi|wpbakery|bricks|oxygen)/i',
		'cache'      => '/(cache|rocket|litespeed|speed|optimi[sz]e)/i',
		'multilang'  => '/(polylang|wpml|translat|weglot)/i',
		'commerce'   => '/(woocommerce|edd|easy-digital)/i',
		'legal'      => '/(borlabs|cookie|erecht|complianz|consent|dsgvo|gdpr)/i',
	);

	$out = array();

	foreach ( get_plugins() as $file => $data ) {
		if ( ! is_plugin_active( $file ) ) {
			continue;
		}

		$slug = strtok( $file, '/' );
		$kind = null;

		foreach ( $kinds as $label => $pattern ) {
			if ( preg_match( $pattern, $slug . ' ' . ( $data['Name'] ?? '' ) ) ) {
				$kind = $label;
				break;
			}
		}

		if ( null === $kind ) {
			continue;
		}

		$out[] = array(
			'name'    => (string) ( $data['Name'] ?? $slug ),
			'version' => (string) ( $data['Version'] ?? '' ),
			'kind'    => $kind,
		);
	}

	return $out;
}

/**
 * Ability name without the namespace, as the MCP tool is called.
 *
 * @param string $name Ability name.
 * @return string
 */
function wpmcp_short_ability_name( $name ) {
	return str_replace( 'wpmcp/', '', (string) $name );
}

/**
 * Version of the tool contract: names, arguments, answer shapes, error codes.
 *
 * An integer, separate from the plugin version, because a client cares
 * about one thing: whether what it learnt about these tools still holds.
 * Raised only when an existing name, field or meaning changes in a way a
 * client has to adapt to; a new tool, argument or field does not raise it.
 * The changelog marks every raise under "API".
 *
 * @return int
 */
function wpmcp_contract_version() {
	return 1;
}

/**
 * Site fingerprint: versions, modules, post types and design tokens.
 * Meant as the first call of any session, so nothing has to be assumed.
 *
 * @return array
 */
function wpmcp_site_info() {
	global $wp_version;

	$theme = wp_get_theme();

	$post_types = array();
	foreach ( wpmcp_allowed_post_types() as $name ) {
		$object = get_post_type_object( $name );
		if ( $object ) {
			$post_types[] = array(
				'name'  => $name,
				'label' => $object->labels->name ?? $name,
			);
		}
	}

	$info = array(
		'siteName'        => get_bloginfo( 'name' ),
		'siteUrl'         => home_url(),
		'wpVersion'       => $wp_version,
		'phpVersion'      => PHP_VERSION,
		'connectorVersion'=> WPMCP_VERSION,
		'contractVersion' => wpmcp_contract_version(),
		'theme'           => array(
			'name'    => $theme->get( 'Name' ),
			'version' => $theme->get( 'Version' ),
		),
		'coreVersion'     => defined( 'DBW_CORE_VERSION' ) ? DBW_CORE_VERSION : null,
		'requiredCore'    => defined( 'DBW_REQUIRED_CORE_VERSION' ) ? DBW_REQUIRED_CORE_VERSION : null,
		'clientName'      => defined( 'DBW_CLIENT_NAME' ) ? DBW_CLIENT_NAME : null,
		'postTypes'       => $post_types,
		'designTokens'    => wpmcp_design_tokens(),
		'blockCount'      => count( wpmcp_build_catalog( 'site' ) ),
	);

	/*
	 * What this connector can actually do, listed rather than implied.
	 *
	 * Reporting a switch like "liveEdit: false" on its own invites the
	 * reading that writing merely needs enabling, when at the read-only
	 * level the write tools are not registered at all. A flag without a
	 * tool behind it is worse than no flag: it cost a real session a
	 * reconnect cycle and a wrong conclusion.
	 *
	 * The tool list is the client's, though, not ours: it is read once,
	 * when the connection is made. A level raised afterwards shows up here
	 * with its write tools while the client still offers none (field
	 * report dbw-media.de, 05.10.2026), so "explains" says how to get them.
	 *
	 * Telling the client instead was looked into. MCP has the means,
	 * capabilities.tools.listChanged and a notifications/tools/list_changed
	 * the server sends; vendor/wordpress/mcp-adapter 0.6.1 has neither. Its
	 * initialize answer sets listChanged to false on purpose ("only
	 * advertised if implemented end-to-end"), a GET on the endpoint, the
	 * stream a server would send notifications over, answers 405 (SSE "not
	 * yet implemented"), and every POST is answered as plain JSON in that
	 * one request. The level is changed in wp-admin, in a request no client
	 * is listening to. Advertising listChanged through the
	 * mcp_adapter_initialize_response filter would therefore promise a
	 * notification that can never arrive.
	 */
	$available = wpmcp_ability_names();
	$writing   = wpmcp_write_ability_names();

	$read_tools  = array_values( array_diff( $available, $writing ) );
	$write_tools = array_values( array_intersect( $available, $writing ) );

	$levels  = wpmcp_access_levels();
	$level   = wpmcp_access_level();
	$session = wpmcp_work_session_active();

	// Built from the session state: "publishing is never possible" was
	// printed here while a session had made it possible, and an agent
	// believes the sentence it reads over the field next to it.
	$publishing = $session
		? 'Publishing (status publish) and media-upload work until the work session ends.'
		: 'Publishing and media-upload need a work session, which only the site owner can open; until then new pages stay drafts.';

	$info['capabilities'] = array(
		'accessLevel' => $level,
		'read'        => array_map( 'wpmcp_short_ability_name', $read_tools ),
		'write'       => array_map( 'wpmcp_short_ability_name', $write_tools ),
		'explains'    => empty( $write_tools )
			? sprintf(
				'This site is set to "%s". No write tools are registered — writing is not disabled, it is absent. Nothing you send can change content until the site owner raises the access level%s. A client that is already connected gets the write tools only after it reconnects (Claude Code: /mcp, then reconnect): it reads the tool list once, when it connects.',
				$levels[ wpmcp_configured_access_level() ]['label'],
				$session ? ' (a work session does not add tools on a read-only site)' : ''
			)
			: sprintf(
				'This site is set to "%s". %s %s If your client loaded its tool list before the access level was raised, reload the MCP connection (Claude Code: /mcp, then reconnect) to get the write tools.',
				$levels[ $level ]['label'],
				wpmcp_live_edit_enabled()
					? 'Drafts and published pages may be edited.'
					: 'Drafts and new pages may be edited; published pages are read-only.',
				$publishing
			),
	);

	// Only meaningful once writing exists at all.
	if ( ! empty( $write_tools ) ) {
		$info['capabilities']['publishedPagesWritable'] = wpmcp_live_edit_enabled();

		// Whether pages with dynamic data can be saved, and if not, why —
		// so this is a fact to read rather than something to infer from a
		// failed write.
		$dynamic = array( 'allowed' => wpmcp_dynamic_data_allowed() );
		$blocker = wpmcp_unfiltered_html_blocker();

		if ( ! $dynamic['allowed'] ) {
			$dynamic['explains'] = 'Pages whose blocks carry dynamic data cannot be saved. Some block libraries gate them behind the unfiltered_html capability. The site owner can allow it under Tools > MCP Connector, where it is granted per save rather than to the role.';
		} elseif ( $blocker ) {
			$dynamic['effective'] = false;
			$dynamic['explains']  = $blocker;
		} else {
			$dynamic['effective'] = true;
			$dynamic['explains']  = 'Pages with dynamic data can be saved. The capability is granted for the duration of each save and removed again; a write that newly introduces a script tag, an inline event handler or a javascript: URL is still refused.';
		}

		$info['capabilities']['dynamicData'] = $dynamic;

		$info['capabilities']['workSession'] = $session
			? array(
				'active'   => true,
				'until'    => gmdate( 'c', wpmcp_work_session_expires() ),
				'explains' => 'A work session is open: status publish and media-upload work until it ends.',
			)
			: array(
				'active'   => false,
				'explains' => 'Publishing and media-upload only work while the site owner has a work session open. Outside one, status publish fails validation and media-upload is refused with wpmcp_session_required; the tool list stays the same either way.',
			);
	}

	$info['plugins'] = wpmcp_relevant_plugins();

	// Which pages are blocks and which are Elementor decides which tools
	// apply, page by page; content-list says it per page ("builtWith").
	if ( wpmcp_elementor_active() ) {
		$info['elementor'] = array(
			'version'  => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
			'pro'      => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : null,
			'tools'    => array_map( 'wpmcp_short_ability_name', array_values( array_intersect( array( 'wpmcp/elementor-read', 'wpmcp/elementor-write' ), $available ) ) ),
			'explains' => 'Elementor runs here. A page built with it (content-list: builtWith "elementor") keeps its content in Elementor\'s element tree: read it with elementor-read and change it with elementor-write, by element id. The block tools refuse to write such a page; SEO meta, slug and status still go through content-write. Pages of Elementor\'s Atomic editor are read-only, and its theme-builder templates (headers, footers) are out of scope unless the site owner adds the "elementor_library" post type.',
		) + wpmcp_elementor_widgets_report();
	}

	if ( function_exists( 'dbw_get_settings' ) ) {
		$settings = dbw_get_settings();
		$modules  = array();
		foreach ( $settings as $key => $value ) {
			if ( substr( (string) $key, -7 ) === '_module' ) {
				$modules[ $key ] = (bool) $value;
			}
		}
		$info['featureModules'] = $modules;
	}

	return $info;
}
