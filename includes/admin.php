<?php
/**
 * Admin surface: a guided setup that doubles as a diagnostic, the
 * access-level settings, and the activity log — so the question "what did
 * the agent change on my site?" has an answer without database access.
 *
 * Each setup step checks a real precondition rather than just telling you
 * what to do next. A silent registration failure shows up here as a red
 * step instead of as an MCP server that connects and offers no tools.
 *
 * @package wp-mcp-connector-plus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the admin page under Tools.
 */
function wpmcp_admin_menu() {
	$hook = add_management_page(
		__( 'WP MCP Connector Plus', 'wp-mcp-connector-plus' ),
		__( 'MCP Connector', 'wp-mcp-connector-plus' ),
		'manage_options',
		'wp-mcp-connector-plus',
		'wpmcp_render_admin_page'
	);
	if ( $hook ) {
		add_action( 'load-' . $hook, 'wpmcp_admin_load' );
	}
}
add_action( 'admin_menu', 'wpmcp_admin_menu' );

/**
 * Before the page renders: tidy the URL of a filtered log.
 *
 * A list table prints a nonce and the current URL as _wp_http_referer
 * into its form. The log's filter form is a GET form, so both land in
 * the address, and each further filter nests the previous address inside
 * the next one. Core's own list screens redirect them away the same way.
 */
function wpmcp_admin_load() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only removes arguments, changes nothing.
	if ( empty( $_GET['_wp_http_referer'] ) || ! isset( $_SERVER['REQUEST_URI'] ) ) {
		return;
	}
	wpmcp_admin_redirect( remove_query_arg( array( '_wp_http_referer', '_wpnonce', 'filter_action' ), wp_unslash( $_SERVER['REQUEST_URI'] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passed through wp_safe_redirect.
}

/**
 * Register settings.
 */
function wpmcp_admin_init() {
	register_setting(
		'wpmcp_settings',
		'wpmcp_access_level',
		array(
			'type'              => 'string',
			'sanitize_callback' => function ( $value ) {
				return array_key_exists( $value, wpmcp_access_levels() ) ? $value : 'draft';
			},
			'default'           => 'draft',
		)
	);

	register_setting(
		'wpmcp_settings',
		'wpmcp_extra_post_types',
		array(
			'type'              => 'array',
			'sanitize_callback' => function ( $value ) {
				$value = is_array( $value ) ? $value : array();
				return array_values(
					array_filter( array_map( 'sanitize_key', $value ), 'post_type_exists' )
				);
			},
			'default'           => array(),
		)
	);

	register_setting(
		'wpmcp_settings',
		'wpmcp_dynamic_data',
		array(
			'type'              => 'string',
			'sanitize_callback' => function ( $value ) {
				return 'allowed' === $value ? 'allowed' : 'blocked';
			},
			'default'           => 'blocked',
		)
	);

	register_setting(
		'wpmcp_settings',
		'wpmcp_pattern_access',
		array(
			'type'              => 'string',
			'sanitize_callback' => function ( $value ) {
				return in_array( $value, array( 'none', 'read', 'write' ), true ) ? $value : 'read';
			},
			'default'           => 'read',
		)
	);

	register_setting(
		'wpmcp_settings',
		'wpmcp_log_retention_days',
		array(
			'type'              => 'integer',
			'sanitize_callback' => 'wpmcp_sanitize_retention_days',
			'default'           => 90,
		)
	);
}
add_action( 'admin_init', 'wpmcp_admin_init' );

/**
 * The settings page, optionally on a tab.
 *
 * @param string $tab  connection, access or activity; empty for the default.
 * @param array  $args Further query arguments.
 * @return string
 */
function wpmcp_admin_url( $tab = '', array $args = array() ) {
	$query = array( 'page' => 'wp-mcp-connector-plus' );
	if ( '' !== $tab ) {
		$query['tab'] = $tab;
	}
	return add_query_arg( array_merge( $query, $args ), admin_url( 'tools.php' ) );
}

/**
 * The tabs of the settings page, in order. The first is the default.
 *
 * @return array<string, string> Slug => label.
 */
function wpmcp_admin_tabs() {
	return array(
		'connection' => __( 'Connection', 'wp-mcp-connector-plus' ),
		'access'     => __( 'Access', 'wp-mcp-connector-plus' ),
		'activity'   => __( 'Activity', 'wp-mcp-connector-plus' ),
	);
}

/**
 * The tab asked for, or the default for anything else.
 *
 * @return string
 */
function wpmcp_current_admin_tab() {
	$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
	$tabs = wpmcp_admin_tabs();
	return isset( $tabs[ $tab ] ) ? $tab : (string) key( $tabs );
}

/**
 * Transient keys, bound to the admin who caused them.
 *
 * Bound to the user so that two administrators working at the same time
 * never see each other's password, and short-lived because the value in
 * it is a credential: it exists only to survive one redirect.
 *
 * @param string $what connection or flash.
 * @return string
 */
function wpmcp_admin_transient_key( $what ) {
	return 'wpmcp_' . $what . '_' . get_current_user_id();
}

/**
 * Remember a notice for the page the redirect lands on.
 *
 * @param string $type    success, error, warning or info.
 * @param string $message Plain text.
 */
function wpmcp_admin_flash( $type, $message ) {
	set_transient(
		wpmcp_admin_transient_key( 'flash' ),
		array(
			'type'    => $type,
			'message' => (string) $message,
		),
		60
	);
}

/**
 * Take a value out of its transient: it is shown once, then it is gone.
 *
 * @param string $what connection or flash.
 * @return mixed|null
 */
function wpmcp_admin_take( $what ) {
	$key   = wpmcp_admin_transient_key( $what );
	$value = get_transient( $key );
	if ( false === $value ) {
		return null;
	}
	delete_transient( $key );
	return $value;
}

/**
 * End an admin-post request where it came from, or on the given tab.
 *
 * @param string $url Where to go.
 */
function wpmcp_admin_redirect( $url ) {
	wp_safe_redirect( $url );
	exit;
}

/**
 * Only administrators get past this; everyone else gets WordPress' own 403.
 *
 * @param string $nonce_action Nonce action of the form or link.
 * @param string $nonce_field  Field carrying the nonce.
 */
function wpmcp_admin_post_guard( $nonce_action, $nonce_field = '_wpnonce' ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'wp-mcp-connector-plus' ), 403 );
	}
	check_admin_referer( $nonce_action, $nonce_field );
}

/**
 * Generate a connection: create the agent user if needed and a new
 * application password, then redirect.
 *
 * Post, redirect, get. The password used to be created while the page
 * rendered, so reloading the page with the generated command on screen
 * (or the browser's "resend the form?" after a back button) silently
 * created another password each time. Now the work happens here, once;
 * the result waits for the page in a transient that lives 60 seconds,
 * belongs to this administrator, and is deleted the moment it is shown.
 * WordPress stores only a hash of the password, so after that it is gone
 * for good, which is the point.
 */
function wpmcp_admin_post_setup() {
	wpmcp_admin_post_guard( 'wpmcp_setup', 'wpmcp_setup_nonce' );

	require_once WPMCP_DIR . 'includes/setup.php';
	$result = wpmcp_handle_setup_post();
	if ( is_wp_error( $result ) ) {
		wpmcp_admin_flash( 'error', $result->get_error_message() );
	} elseif ( is_array( $result ) ) {
		set_transient( wpmcp_admin_transient_key( 'connection' ), $result, 60 );
	}

	wpmcp_admin_redirect( wpmcp_admin_url( 'connection' ) );
}
add_action( 'admin_post_wpmcp_setup', 'wpmcp_admin_post_setup' );

/**
 * Start or stop a work session, from the Access tab or the admin bar.
 *
 * Its own form rather than part of the settings form: opening a window is
 * an action with a time attached, not a preference, and it must not ride
 * along with a Save somebody pressed for another reason. A GET with a
 * nonce is accepted for "close", so the admin bar can close a session
 * from any screen, the front end included; it returns to that screen.
 */
function wpmcp_admin_post_session() {
	wpmcp_admin_post_guard( 'wpmcp_work_session' );

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- checked in the guard.
	if ( isset( $_REQUEST['wpmcp_session_stop'] ) ) {
		$was_running = wpmcp_work_session_active();
		wpmcp_end_work_session();
		if ( $was_running ) {
			wpmcp_admin_flash( 'success', __( 'The work session is closed. Everything is back to the saved settings.', 'wp-mcp-connector-plus' ) );
		}
	} elseif ( isset( $_REQUEST['wpmcp_session_start'] ) ) {
		wpmcp_start_work_session( (int) $_REQUEST['wpmcp_session_start'] );
		wpmcp_admin_flash(
			'success',
			sprintf(
				/* translators: %s: remaining time, e.g. "4 hours". */
				__( 'Work session opened. It closes itself in %s.', 'wp-mcp-connector-plus' ),
				wpmcp_work_session_remaining()
			)
		);
	}
	// phpcs:enable

	$back = wp_get_referer();
	wpmcp_admin_redirect( $back ? $back : wpmcp_admin_url( 'access' ) );
}
add_action( 'admin_post_wpmcp_session', 'wpmcp_admin_post_session' );

/**
 * Put the agent role back to what it should store, from the status table.
 *
 * The same reconciliation admin_init and REST requests run on their own;
 * the button is for the administrator who just read "stores more than
 * reading" and wants it fixed now rather than told to deactivate.
 */
function wpmcp_admin_post_repair_role() {
	wpmcp_admin_post_guard( 'wpmcp_repair_role' );

	wpmcp_sync_role_capabilities();

	if ( wpmcp_role_caps_match() ) {
		wpmcp_admin_flash( 'success', __( 'The agent role is repaired: it stores reading only again.', 'wp-mcp-connector-plus' ) );
	} else {
		wpmcp_admin_flash( 'error', __( 'The agent role could not be repaired. Another plugin may be changing it back; deactivating and reactivating this plugin recreates it.', 'wp-mcp-connector-plus' ) );
	}

	wpmcp_admin_redirect( wpmcp_admin_url( 'connection' ) );
}
add_action( 'admin_post_wpmcp_repair_role', 'wpmcp_admin_post_repair_role' );

/**
 * The agent's most recent call, if it ever made one.
 *
 * Any entry by an account with the agent role. The log has no index on
 * user_id, but it is read newest first and stops at the first match,
 * which on a site where the agent works is near the top.
 *
 * @return object|null created_at (UTC) and ability, or null.
 */
function wpmcp_last_agent_call() {
	global $wpdb;

	$ids = array_map(
		'intval',
		(array) get_users(
			array(
				'role'   => WPMCP_ROLE,
				'fields' => 'ID',
			)
		)
	);
	if ( empty( $ids ) ) {
		return null;
	}

	$table        = wpmcp_audit_table();
	$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table; the IN list is placeholders only.
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT created_at, ability FROM {$table} WHERE user_id IN ({$placeholders}) ORDER BY id DESC LIMIT 1", $ids ) );

	return $row ? $row : null;
}

/**
 * How many of our abilities actually made it into the registry.
 *
 * @return int|null Null when the Abilities API cannot be queried.
 */
function wpmcp_registered_ability_count() {
	if ( ! function_exists( 'wp_get_ability' ) ) {
		return null;
	}
	$count = 0;
	foreach ( wpmcp_ability_names() as $name ) {
		if ( wp_get_ability( $name ) ) {
			++$count;
		}
	}
	return $count;
}

/**
 * The agent user, if one exists.
 *
 * @return \WP_User|null
 */
function wpmcp_agent_user() {
	$users = get_users(
		array(
			'role'   => WPMCP_ROLE,
			'number' => 1,
		)
	);
	return $users ? $users[0] : null;
}

/**
 * Does the agent user hold at least one application password?
 *
 * @param \WP_User|null $user Agent user.
 * @return int
 */
function wpmcp_agent_password_count( $user ) {
	if ( ! $user || ! class_exists( '\WP_Application_Passwords' ) ) {
		return 0;
	}
	return count( (array) \WP_Application_Passwords::get_user_application_passwords( $user->ID ) );
}

/**
 * The setup steps, each with the state of the thing it checks.
 *
 * @return array
 */
function wpmcp_setup_steps() {
	global $wp_version;

	$steps = array();

	// 1. The Abilities API has to exist for any of this to mean anything.
	$has_api = function_exists( 'wp_register_ability' );
	$steps[] = array(
		'title'  => __( 'WordPress with the Abilities API', 'wp-mcp-connector-plus' ),
		'state'  => $has_api ? 'ok' : 'error',
		'detail' => $has_api
			/* translators: %s: WordPress version */
			? sprintf( __( 'WordPress %s.', 'wp-mcp-connector-plus' ), $wp_version )
			/* translators: %s: WordPress version */
			: sprintf( __( 'WordPress %s has no Abilities API. Version 6.9 or newer is required.', 'wp-mcp-connector-plus' ), $wp_version ),
	);

	// 2. Did our abilities actually register?
	$registered = wpmcp_registered_ability_count();
	$expected   = count( wpmcp_ability_names() );
	$steps[]    = array(
		'title'  => __( 'Abilities registered', 'wp-mcp-connector-plus' ),
		'state'  => ( null !== $registered && $registered === $expected ) ? 'ok' : 'error',
		'detail' => null === $registered
			? __( 'Cannot be determined without the Abilities API.', 'wp-mcp-connector-plus' )
			: sprintf(
				/* translators: 1: registered count, 2: expected count */
				__( '%1$d of %2$d.', 'wp-mcp-connector-plus' ),
				(int) $registered,
				(int) $expected
			) . ( $registered === $expected ? '' : ' ' . __( 'The MCP server will connect but expose no tools.', 'wp-mcp-connector-plus' ) ),
	);

	// 3. Is there a usable mcp-adapter? Other plugins bundle their own.
	$adapter_ok = wpmcp_adapter_is_usable();
	$adapter_v  = defined( '\WP\MCP\Core\McpAdapter::VERSION' ) ? \WP\MCP\Core\McpAdapter::VERSION : null;
	$steps[]    = array(
		'title'  => __( 'MCP transport', 'wp-mcp-connector-plus' ),
		'state'  => $adapter_ok ? 'ok' : 'error',
		'detail' => $adapter_ok
			? sprintf(
				/* translators: %s: mcp-adapter version */
				__( 'mcp-adapter %s. Endpoint: ', 'wp-mcp-connector-plus' ),
				$adapter_v ? $adapter_v : '?'
			) . '<code>' . esc_html( rest_url( 'wpmcp/v1/mcp' ) ) . '</code>'
			: __( 'No usable mcp-adapter. Abilities stay reachable over wp-abilities/v1, but there is no MCP endpoint.', 'wp-mcp-connector-plus' ),
		'raw'    => true,
	);

	// 4. Agent user with a credential.
	$user      = wpmcp_agent_user();
	$passwords = wpmcp_agent_password_count( $user );
	$steps[]   = array(
		'title'  => __( 'Agent user and credential', 'wp-mcp-connector-plus' ),
		'state'  => ( $user && $passwords > 0 ) ? 'ok' : 'todo',
		'detail' => $user
			? sprintf(
				/* translators: 1: user login, 2: number of application passwords */
				_n( '%1$s, %2$d application password.', '%1$s, %2$d application passwords.', $passwords, 'wp-mcp-connector-plus' ),
				'<code>' . esc_html( $user->user_login ) . '</code>',
				(int) $passwords
			)
			: __( 'Not created yet. Use the form below.', 'wp-mcp-connector-plus' ),
		'raw'    => true,
	);

	// 5. Do the granted capabilities actually match the chosen level?
	$levels     = wpmcp_access_levels();
	$level      = wpmcp_access_level();
	$caps_match = wpmcp_role_caps_match();
	$steps[]    = array(
		'title'  => __( 'Permissions in step', 'wp-mcp-connector-plus' ),
		'state'  => $caps_match ? 'ok' : 'error',
		'detail' => $caps_match
			? sprintf(
				/* translators: %s: name of the access level */
				__( 'Level "%s". The agent role stores reading only; the level is applied on every request, so a setting or a session that ended leaves nothing behind.', 'wp-mcp-connector-plus' ),
				$levels[ $level ]['label']
			)
			: __( 'The agent role is missing or stores more than reading.', 'wp-mcp-connector-plus' ),
		'repair' => ! $caps_match,
	);

	// 6. Application passwords: the agent's only way in. Asking WordPress
	// first records what the site decided before this plugin stepped in.
	if ( function_exists( 'wp_is_application_passwords_available' ) ) {
		wp_is_application_passwords_available();
	}
	$safe    = wpmcp_app_passwords_transport_safe();
	$before  = wpmcp_app_passwords_available_before();
	$steps[] = array(
		'title'  => __( 'Application passwords', 'wp-mcp-connector-plus' ),
		'state'  => $safe ? 'ok' : 'error',
		'detail' => ! $safe
			? __( 'This site is not served over HTTPS. An application password travels with every request, so WordPress allows them only over HTTPS or in an environment declared local. The agent cannot connect until the site runs on HTTPS.', 'wp-mcp-connector-plus' )
			: ( false === $before
				? __( 'Switched off for everyone by the theme or another plugin. The connector reopened them for the agent account only; every human account keeps them switched off.', 'wp-mcp-connector-plus' )
				: __( 'Available. The connector leaves them as they were for every human account.', 'wp-mcp-connector-plus' ) ),
	);

	// 7. Has the agent actually been here? Everything above can be green
	// on a site no client ever reached.
	$last    = wpmcp_last_agent_call();
	$at      = $last ? strtotime( $last->created_at . ' UTC' ) : 0;
	$steps[] = array(
		'title'  => __( 'Last connection', 'wp-mcp-connector-plus' ),
		'state'  => $at ? 'ok' : 'todo',
		'detail' => $at
			? sprintf(
				/* translators: 1: time since the call, e.g. "5 mins", 2: tool name, 3: date and time in the site's timezone */
				__( '%1$s ago (%2$s, %3$s).', 'wp-mcp-connector-plus' ),
				human_time_diff( $at, time() ),
				wpmcp_short_tool_name( $last->ability ),
				wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $at )
			)
			: __( 'No call from the agent yet. Its first call shows up here once a client has connected.', 'wp-mcp-connector-plus' ),
	);

	return $steps;
}

/**
 * Render the admin page.
 *
 * Three tabs, each a link of its own so a tab can be bookmarked or sent:
 * Connection (status and setup), Access (session and settings), Activity
 * (the log). Nothing here changes anything: every form posts to
 * admin-post.php or options.php and comes back with a redirect, so a
 * reload only ever reloads.
 */
function wpmcp_render_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	require_once WPMCP_DIR . 'includes/setup.php';

	$tab   = wpmcp_current_admin_tab();
	$flash = wpmcp_admin_take( 'flash' );
	?>
	<div class="wrap wpmcp-admin">
		<h1><?php esc_html_e( 'WP MCP Connector Plus', 'wp-mcp-connector-plus' ); ?></h1>
		<?php wpmcp_admin_styles(); ?>

		<nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'Connector sections', 'wp-mcp-connector-plus' ); ?>">
			<?php foreach ( wpmcp_admin_tabs() as $slug => $label ) : ?>
				<a href="<?php echo esc_url( wpmcp_admin_url( $slug ) ); ?>"
					class="nav-tab<?php echo $slug === $tab ? ' nav-tab-active' : ''; ?>"
					<?php echo $slug === $tab ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>

		<?php if ( is_array( $flash ) && ! empty( $flash['message'] ) ) : ?>
			<div class="notice notice-<?php echo esc_attr( in_array( $flash['type'] ?? '', array( 'success', 'error', 'warning', 'info' ), true ) ? $flash['type'] : 'info' ); ?> is-dismissible">
				<p><?php echo esc_html( $flash['message'] ); ?></p>
			</div>
		<?php endif; ?>

		<?php
		if ( 'access' === $tab ) {
			settings_errors();
			wpmcp_render_access_tab();
		} elseif ( 'activity' === $tab ) {
			wpmcp_render_activity_tab();
		} else {
			wpmcp_render_connection_tab();
		}

		wpmcp_admin_script();
		?>
	</div>
	<?php
}

/**
 * Connection tab: the status table, then setup or the fresh connection.
 */
function wpmcp_render_connection_tab() {
	$icons = array(
		'ok'    => array( 'dashicons-yes-alt', 'is-ok', __( 'OK', 'wp-mcp-connector-plus' ) ),
		'todo'  => array( 'dashicons-marker', 'is-todo', __( 'To do', 'wp-mcp-connector-plus' ) ),
		'error' => array( 'dashicons-dismiss', 'is-error', __( 'Problem', 'wp-mcp-connector-plus' ) ),
	);
	?>
	<h2><?php esc_html_e( 'Status', 'wp-mcp-connector-plus' ); ?></h2>
	<table class="widefat striped wpmcp-status">
		<tbody>
		<?php foreach ( wpmcp_setup_steps() as $i => $step ) : ?>
			<?php list( $class, $state_class, $state_label ) = $icons[ $step['state'] ]; ?>
			<tr>
				<td class="wpmcp-status-num"><?php echo (int) ( $i + 1 ); ?></td>
				<td class="wpmcp-status-icon">
					<span class="dashicons <?php echo esc_attr( $class . ' ' . $state_class ); ?>" aria-hidden="true"></span>
					<span class="screen-reader-text"><?php echo esc_html( $state_label ); ?></span>
				</td>
				<th scope="row"><?php echo esc_html( $step['title'] ); ?></th>
				<td>
					<?php
					// Some details carry a <code> element built above.
					echo empty( $step['raw'] )
						? esc_html( $step['detail'] )
						: wp_kses( $step['detail'], array( 'code' => array() ) );
					?>
					<?php if ( ! empty( $step['repair'] ) ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpmcp-inline-form">
							<input type="hidden" name="action" value="wpmcp_repair_role" />
							<?php wp_nonce_field( 'wpmcp_repair_role' ); ?>
							<button type="submit" class="button button-small"><?php esc_html_e( 'Repair now', 'wp-mcp-connector-plus' ); ?></button>
						</form>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php
	$connection = wpmcp_admin_take( 'connection' );
	if ( is_array( $connection ) ) {
		wpmcp_render_connection_result( $connection );
	} else {
		wpmcp_render_setup_panel();
	}
}

/**
 * Access tab: the work session, then the settings it falls back to.
 */
function wpmcp_render_access_tab() {
	$session_until = wpmcp_work_session_expires();
	$levels        = wpmcp_access_levels();
	$read_only     = 'read' === wpmcp_configured_access_level();
	?>
	<h2><?php esc_html_e( 'Work session', 'wp-mcp-connector-plus' ); ?></h2>
	<div class="notice inline wpmcp-session <?php echo $session_until ? 'notice-warning' : 'notice-info'; ?>">
		<?php if ( $session_until ) : ?>
			<p>
				<strong><?php esc_html_e( 'A work session is running.', 'wp-mcp-connector-plus' ); ?></strong>
				<?php
				printf(
					/* translators: %s: remaining time, e.g. "3 hours". */
					esc_html__( 'Everything is open for another %s: published pages, synced patterns, dynamic data, theme building blocks and templates, and the agent may publish and upload images. It closes itself, you do not have to remember it.', 'wp-mcp-connector-plus' ),
					esc_html( wpmcp_work_session_remaining() )
				);
				?>
			</p>
		<?php else : ?>
			<p>
				<strong><?php esc_html_e( 'Working on the site?', 'wp-mcp-connector-plus' ); ?></strong>
				<?php esc_html_e( 'Open everything for a set time instead of leaving a wide setting on. A session lifts the access level to published pages, makes synced patterns editable, allows dynamic data and brings theme building blocks, templates and navigation menus into scope. Other non-public post types, customer data above all, stay a tick somebody makes on purpose. It is also the only time the agent may publish and upload images: opening a session is deciding that what gets built in it may go live. It closes itself when the time is up; the settings below are what the site falls back to.', 'wp-mcp-connector-plus' ); ?>
			</p>
		<?php endif; ?>

		<p class="description">
			<?php esc_html_e( 'A session widens what the agent may do with the tools it already has. It does not add tools: the tool list follows the level below, because MCP clients read it once when they connect and never ask again.', 'wp-mcp-connector-plus' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpmcp-session-actions">
			<input type="hidden" name="action" value="wpmcp_session" />
			<?php wp_nonce_field( 'wpmcp_work_session' ); ?>
			<?php if ( $session_until ) : ?>
				<button type="submit" class="button" name="wpmcp_session_stop" value="1"><?php esc_html_e( 'Close now', 'wp-mcp-connector-plus' ); ?></button>
			<?php else : ?>
				<?php foreach ( wpmcp_work_session_lengths() as $hours => $label ) : ?>
					<button type="submit" class="button" name="wpmcp_session_start" value="<?php echo esc_attr( $hours ); ?>"><?php echo esc_html( $label ); ?></button>
				<?php endforeach; ?>
			<?php endif; ?>
			<?php if ( $read_only ) : ?>
				<span class="wpmcp-session-note">
					<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
					<?php
					printf(
						/* translators: 1: name of the drafts level, 2: name of the widest level */
						esc_html__( 'The level is "Read only", so the agent has no write tools and a session cannot give it any. Set the level to "%1$s" or "%2$s" to let the agent write during a session.', 'wp-mcp-connector-plus' ),
						esc_html( $levels['draft']['label'] ),
						esc_html( $levels['full']['label'] )
					);
					?>
				</span>
			<?php endif; ?>
		</form>
	</div>

	<h2><?php esc_html_e( 'Settings', 'wp-mcp-connector-plus' ); ?></h2>
	<form method="post" action="options.php">
		<?php settings_fields( 'wpmcp_settings' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'What the agent may do', 'wp-mcp-connector-plus' ); ?></th>
				<td>
					<?php $current = wpmcp_configured_access_level(); ?>
					<?php foreach ( wpmcp_access_levels() as $key => $level ) : ?>
						<p>
							<label>
								<input type="radio" name="wpmcp_access_level"
									value="<?php echo esc_attr( $key ); ?>"
									<?php checked( $current, $key ); ?>
									<?php disabled( defined( 'WPMCP_ACCESS_LEVEL' ) ); ?> />
								<strong><?php echo esc_html( $level['label'] ); ?></strong>
							</label>
							<span class="description wpmcp-indent"><?php echo esc_html( $level['description'] ); ?></span>
						</p>
					<?php endforeach; ?>
					<p class="description">
						<strong><?php esc_html_e( 'No level lets the agent publish or upload images.', 'wp-mcp-connector-plus' ); ?></strong>
						<?php esc_html_e( 'Both are possible only during a work session you open (see above). Deleting and changing settings are never possible. Tools the level does not allow are not registered at all, so the agent never sees them, and a work session does not add any. Every write creates a revision.', 'wp-mcp-connector-plus' ); ?>
						<?php if ( defined( 'WPMCP_ACCESS_LEVEL' ) ) : ?>
							<br><strong><?php esc_html_e( 'Currently fixed by a constant in wp-config.php.', 'wp-mcp-connector-plus' ); ?></strong>
						<?php endif; ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Additional post types', 'wp-mcp-connector-plus' ); ?></th>
				<td>
					<?php
					$extra_options  = wpmcp_selectable_post_types();
					$extra_selected = wpmcp_extra_post_types();
					?>
					<?php if ( empty( $extra_options ) ) : ?>
						<p class="description"><?php esc_html_e( 'This site has no other post types to add.', 'wp-mcp-connector-plus' ); ?></p>
					<?php else : ?>
						<div id="wpmcp-post-types">
							<?php if ( count( $extra_options ) > 5 ) : ?>
								<p>
									<label>
										<input type="checkbox" id="wpmcp-toggle-all" />
										<strong><?php esc_html_e( 'Select all', 'wp-mcp-connector-plus' ); ?></strong>
										<span class="description" id="wpmcp-post-type-count"></span>
									</label>
								</p>
							<?php endif; ?>
							<?php foreach ( $extra_options as $slug => $label ) : ?>
								<?php $sensitive = wpmcp_post_type_holds_personal_data( $slug ); ?>
								<p>
									<label>
										<input type="checkbox" name="wpmcp_extra_post_types[]"
											value="<?php echo esc_attr( $slug ); ?>"
											<?php checked( in_array( $slug, $extra_selected, true ) ); ?> />
										<?php echo esc_html( $label ); ?>
										<code><?php echo esc_html( $slug ); ?></code>
										<?php if ( $sensitive ) : ?>
											<span class="wpmcp-sensitive" title="<?php esc_attr_e( 'This post type usually holds other people\'s data.', 'wp-mcp-connector-plus' ); ?>">
												<?php esc_html_e( 'personal data', 'wp-mcp-connector-plus' ); ?>
											</span>
										<?php endif; ?>
									</label>
								</p>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
					<p class="description">
						<?php esc_html_e( 'Public post types and pages are already in scope. Everything else is listed here — a theme\'s headers, footers, hooks and content templates among them. Adding one is a real decision: those apply to every page at once, the way a synced pattern does, so a change there is not confined to the page being edited. In code, the wpmcp_allowed_post_types filter does the same thing.', 'wp-mcp-connector-plus' ); ?>
						<br>
						<strong><?php esc_html_e( 'The ones marked "personal data" are a different question.', 'wp-mcp-connector-plus' ); ?></strong>
						<?php esc_html_e( 'Orders, subscriptions and form entries hold your customers\' names and addresses. Ticking one lets the agent read them. That is rarely what you want from a tool for editing pages, and on a shop it is a decision your privacy policy has to cover.', 'wp-mcp-connector-plus' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Dynamic data', 'wp-mcp-connector-plus' ); ?></th>
				<td>
					<?php
					$dynamic_current = wpmcp_dynamic_data_allowed() ? 'allowed' : 'blocked';
					$dynamic_options = array(
						'blocked' => __( 'Blocked — pages containing dynamic data stay read-only', 'wp-mcp-connector-plus' ),
						'allowed' => __( 'Allowed — the agent may save them too', 'wp-mcp-connector-plus' ),
					);
					?>
					<?php foreach ( $dynamic_options as $key => $label ) : ?>
						<p>
							<label>
								<input type="radio" name="wpmcp_dynamic_data"
									value="<?php echo esc_attr( $key ); ?>"
									<?php checked( $dynamic_current, $key ); ?>
									<?php disabled( defined( 'WPMCP_DYNAMIC_DATA' ) || ( 'allowed' === $key && $read_only ) ); ?> />
								<?php echo esc_html( $label ); ?>
							</label>
						</p>
					<?php endforeach; ?>
					<?php $dynamic_blocker = function_exists( 'wpmcp_unfiltered_html_blocker' ) ? wpmcp_unfiltered_html_blocker() : null; ?>
					<?php if ( $dynamic_blocker && 'allowed' === $dynamic_current ) : ?>
						<p class="description wpmcp-error-text">
							<strong><?php esc_html_e( 'This setting cannot take effect on this site.', 'wp-mcp-connector-plus' ); ?></strong>
							<?php echo esc_html( $dynamic_blocker ); ?>
						</p>
					<?php endif; ?>
					<p class="description">
						<?php esc_html_e( 'Some block libraries refuse to save a page holding dynamic data unless the account has unfiltered_html — the capability that permits storing arbitrary HTML and JavaScript. That would be the widest permission in a role that deliberately cannot publish, delete, upload or change settings, so it is never given to the role. When this is allowed, it is granted for the length of one save and taken away again, and a write that newly introduces a script tag, an inline event handler or a javascript: URL is still refused. Every such save is marked in the activity log.', 'wp-mcp-connector-plus' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Synced patterns', 'wp-mcp-connector-plus' ); ?></th>
				<td>
					<?php
					$pattern_current = wpmcp_pattern_access();
					$pattern_options = array(
						'none'  => __( 'Hidden — the agent cannot see what is inside them', 'wp-mcp-connector-plus' ),
						'read'  => __( 'Readable — the agent can look inside but not change them', 'wp-mcp-connector-plus' ),
						'write' => __( 'Editable — the agent may change them', 'wp-mcp-connector-plus' ),
					);
					?>
					<?php foreach ( $pattern_options as $key => $label ) : ?>
						<p>
							<label>
								<input type="radio" name="wpmcp_pattern_access"
									value="<?php echo esc_attr( $key ); ?>"
									<?php checked( $pattern_current, $key ); ?>
									<?php disabled( defined( 'WPMCP_PATTERN_ACCESS' ) || ( 'write' === $key && $read_only ) ); ?> />
								<?php echo esc_html( $label ); ?>
							</label>
						</p>
					<?php endforeach; ?>
					<p class="description">
						<?php esc_html_e( 'A synced pattern appears on every page that embeds it, so changing one changes all of them at once — and a pattern has no draft state. The dry run says how many pieces of content are affected before anything is saved.', 'wp-mcp-connector-plus' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wpmcp-log-retention"><?php esc_html_e( 'Keep the activity log', 'wp-mcp-connector-plus' ); ?></label></th>
				<td>
					<input type="number" id="wpmcp-log-retention" name="wpmcp_log_retention_days" class="small-text"
						min="0" max="3650" step="1"
						value="<?php echo esc_attr( (string) (int) get_option( 'wpmcp_log_retention_days', 90 ) ); ?>" />
					<?php esc_html_e( 'days', 'wp-mcp-connector-plus' ); ?>
					<p class="description">
						<?php esc_html_e( 'Older entries are deleted once a day. 0 keeps them forever. Every read, dry run and refusal is an entry, so on a site the agent works on daily the log grows by thousands of rows a month.', 'wp-mcp-connector-plus' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php submit_button(); ?>
	</form>
	<?php
}

/**
 * A tool name as the agent sees it, without the namespace.
 *
 * @param string $ability Ability name, e.g. wpmcp/content-write.
 * @return string
 */
function wpmcp_short_tool_name( $ability ) {
	return str_replace( 'wpmcp/', '', (string) $ability );
}

/**
 * Activity tab: the log as a list table with filters and paging.
 */
function wpmcp_render_activity_tab() {
	require_once WPMCP_DIR . 'includes/class-wpmcp-log-table.php';

	$table = new WPMCP_Log_Table();
	$table->prepare_items();

	$days = wpmcp_log_retention_days();
	?>
	<h2><?php esc_html_e( 'Activity log', 'wp-mcp-connector-plus' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Every call the agent made, newest first: what it read, what it tried, what it saved and what was refused. Saves link to the revision they left, so the change can be compared and undone in the editor.', 'wp-mcp-connector-plus' ); ?>
		<?php
		echo esc_html(
			0 === $days
				? __( 'Entries are kept forever.', 'wp-mcp-connector-plus' )
				: sprintf(
					/* translators: %d: number of days */
					_n( 'Entries are kept for %d day.', 'Entries are kept for %d days.', $days, 'wp-mcp-connector-plus' ),
					$days
				)
		);
		?>
	</p>
	<form method="get" action="<?php echo esc_url( admin_url( 'tools.php' ) ); ?>">
		<input type="hidden" name="page" value="wp-mcp-connector-plus" />
		<input type="hidden" name="tab" value="activity" />
		<?php
		$table->search_box( __( 'Search summaries', 'wp-mcp-connector-plus' ), 'wpmcp-log' );
		$table->display();
		?>
	</form>
	<?php
}

/**
 * The page's styles, once, in one block.
 *
 * Inline and only on this screen: a stylesheet request for a handful of
 * rules on a page few people open is not worth a file. Colours are the
 * admin's own (the status colours of core notices), so the page follows
 * whatever admin colour scheme is chosen for everything else.
 */
function wpmcp_admin_styles() {
	?>
	<style>
		.wpmcp-admin .nav-tab-wrapper { margin-bottom: 1em; }
		.wpmcp-status { max-width: 60rem; }
		.wpmcp-status th { font-weight: 600; width: 14rem; }
		.wpmcp-status-num { width: 2rem; text-align: center; font-weight: 600; }
		.wpmcp-status-icon { width: 2rem; }
		.wpmcp-status .is-ok { color: #008a20; }
		.wpmcp-status .is-todo { color: #996800; }
		.wpmcp-status .is-error { color: #d63638; }
		.wpmcp-inline-form { display: inline-block; margin-left: .5em; }
		.wpmcp-session { max-width: 60rem; padding: 12px; }
		.wpmcp-session p { margin: 0 0 8px; }
		.wpmcp-session-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
		.wpmcp-session-note { display: inline-flex; align-items: flex-start; gap: 4px; margin-left: 6px; color: #996800; }
		.wpmcp-indent { display: block; margin-left: 1.7em; }
		.wpmcp-sensitive, .wpmcp-error-text { color: #d63638; }
		.wpmcp-field { max-width: 60rem; margin: 0 0 1em; }
		.wpmcp-field textarea { width: 100%; font-family: Consolas, Monaco, monospace; font-size: 12px; }
		.wpmcp-field-actions { display: flex; align-items: center; gap: 8px; margin-top: 4px; }
		.wpmcp-copied { color: #008a20; }
		.wpmcp-secret > summary { cursor: pointer; display: inline-block; }
		.wpmcp-secret[open] > summary { margin-bottom: 4px; }
		.wpmcp-credentials { max-width: 60rem; }
		.wpmcp-credentials th { width: 14rem; }
		.wpmcp-log-filters { display: inline-flex; flex-wrap: wrap; gap: 4px; align-items: center; }
		.wpmcp-log-filters input[type=number] { width: 7em; }
		.wpmcp-muted { color: #646970; }
		.wpmcp-op { display: inline-block; padding: 0 6px; border-radius: 2px; background: #f0f0f1; white-space: nowrap; }
		.wpmcp-op.is-rejected { background: #fcf0f1; color: #8a2424; }
		.wpmcp-op.is-dry-run { background: #f0f6fc; color: #0a4b78; }
		.wpmcp-op.is-saved { background: #edfaef; color: #00450c; }
		.wpmcp-admin .column-time { width: 11rem; }
		.wpmcp-admin .column-user, .wpmcp-admin .column-tool, .wpmcp-admin .column-operation { width: 9rem; }
		.wpmcp-admin .column-post { width: 14rem; }
	</style>
	<?php
}

/**
 * The page's behaviour, once, in one block: copy buttons and the
 * "select all" box for the post types.
 *
 * Copy uses the Clipboard API where the browser grants it (HTTPS, which
 * this page needs anyway for application passwords) and falls back to
 * selecting the text and execCommand, which also works inside a closed
 * <details>: it is opened for the moment of copying and closed again.
 * Without JavaScript the fields stay readable and selectable.
 */
function wpmcp_admin_script() {
	$copied = __( 'Copied', 'wp-mcp-connector-plus' );
	$failed = __( 'Press Ctrl+C (Cmd+C) to copy', 'wp-mcp-connector-plus' );
	?>
	<script>
	( function () {
		var copied = <?php echo wp_json_encode( $copied ); ?>;
		var failed = <?php echo wp_json_encode( $failed ); ?>;

		function say( button, text ) {
			var status = button.parentNode.querySelector( '.wpmcp-copied' );
			if ( ! status ) { return; }
			status.textContent = text;
			clearTimeout( button.wpmcpTimer );
			button.wpmcpTimer = setTimeout( function () { status.textContent = ''; }, 2500 );
		}

		function fallback( field, button ) {
			var details = field.closest( 'details' );
			var closed  = details && ! details.open;
			if ( closed ) { details.open = true; }
			field.focus();
			field.select();
			var ok = false;
			try { ok = document.execCommand( 'copy' ); } catch ( e ) { ok = false; }
			if ( closed ) { details.open = false; }
			say( button, ok ? copied : failed );
		}

		document.querySelectorAll( '.wpmcp-copy' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var field = document.getElementById( button.getAttribute( 'data-copy' ) );
				if ( ! field ) { return; }
				if ( navigator.clipboard && window.isSecureContext ) {
					navigator.clipboard.writeText( field.value ).then(
						function () { say( button, copied ); },
						function () { fallback( field, button ); }
					);
				} else {
					fallback( field, button );
				}
			} );
		} );

		var wrap = document.getElementById( 'wpmcp-post-types' );
		if ( ! wrap ) { return; }

		var all   = wrap.querySelector( '#wpmcp-toggle-all' );
		var boxes = wrap.querySelectorAll( 'input[name="wpmcp_extra_post_types[]"]' );
		var count = wrap.querySelector( '#wpmcp-post-type-count' );

		function sync() {
			var on = 0;
			boxes.forEach( function ( box ) { if ( box.checked ) { on++; } } );
			if ( count ) {
				count.textContent = '(' + on + ' / ' + boxes.length + ')';
			}
			if ( all ) {
				all.checked = ( on === boxes.length );
				// Half-selected has to look like half-selected, or the
				// next click is a guess.
				all.indeterminate = ( on > 0 && on < boxes.length );
			}
		}

		if ( all ) {
			all.addEventListener( 'change', function () {
				boxes.forEach( function ( box ) { box.checked = all.checked; } );
				sync();
			} );
		}
		boxes.forEach( function ( box ) { box.addEventListener( 'change', sync ); } );
		sync();
	}() );
	</script>
	<?php
}
