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
	add_management_page(
		__( 'WP MCP Connector Plus', 'wp-mcp-connector-plus' ),
		__( 'MCP Connector', 'wp-mcp-connector-plus' ),
		'manage_options',
		'wp-mcp-connector-plus',
		'wpmcp_render_admin_page'
	);
}
add_action( 'admin_menu', 'wpmcp_admin_menu' );

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
}
add_action( 'admin_init', 'wpmcp_admin_init' );

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
	$adapter_ok = function_exists( 'wpmcp_adapter_is_usable' ) && wpmcp_adapter_is_usable();
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
	$caps_match = function_exists( 'wpmcp_role_caps_match' ) ? wpmcp_role_caps_match() : true;
	$steps[]    = array(
		'title'  => __( 'Permissions in step', 'wp-mcp-connector-plus' ),
		'state'  => $caps_match ? 'ok' : 'error',
		'detail' => $caps_match
			? sprintf(
				/* translators: %s: name of the access level */
				__( 'Level "%s", and the agent role grants exactly that.', 'wp-mcp-connector-plus' ),
				$levels[ $level ]['label']
			)
			: __( 'The agent role does not grant what the selected level promises. Saving the settings again repairs it.', 'wp-mcp-connector-plus' ),
	);

	return $steps;
}

/**
 * Render the admin page.
 */
function wpmcp_render_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	require_once WPMCP_DIR . 'includes/setup.php';
	$setup_result = wpmcp_handle_setup_post();

	global $wpdb;
	$table = wpmcp_audit_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom plugin table, no core API available.
	$entries = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 100" );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'WP MCP Connector Plus', 'wp-mcp-connector-plus' ); ?></h1>

		<h2><?php esc_html_e( 'Status', 'wp-mcp-connector-plus' ); ?></h2>
		<table class="widefat striped" style="max-width:60rem">
			<tbody>
			<?php foreach ( wpmcp_setup_steps() as $i => $step ) : ?>
				<tr>
					<td style="width:2.5rem;text-align:center;font-weight:600"><?php echo (int) ( $i + 1 ); ?></td>
					<td style="width:2rem">
						<?php
						$icon = array(
							'ok'    => array( 'dashicons-yes-alt', '#008a20' ),
							'todo'  => array( 'dashicons-marker', '#996800' ),
							'error' => array( 'dashicons-dismiss', '#b32d2e' ),
						);
						list( $class, $colour ) = $icon[ $step['state'] ];
						printf(
							'<span class="dashicons %s" style="color:%s"></span>',
							esc_attr( $class ),
							esc_attr( $colour )
						);
						?>
					</td>
					<td><strong><?php echo esc_html( $step['title'] ); ?></strong></td>
					<td>
						<?php
						// Some details carry a <code> element built above.
						echo empty( $step['raw'] )
							? esc_html( $step['detail'] )
							: wp_kses( $step['detail'], array( 'code' => array() ) );
						?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php wpmcp_render_setup_panel( $setup_result ); ?>

		<h2><?php esc_html_e( 'Settings', 'wp-mcp-connector-plus' ); ?></h2>
		<form method="post" action="options.php">
			<?php settings_fields( 'wpmcp_settings' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'What the agent may do', 'wp-mcp-connector-plus' ); ?></th>
					<td>
						<?php $current = wpmcp_access_level(); ?>
						<?php foreach ( wpmcp_access_levels() as $key => $level ) : ?>
							<p>
								<label>
									<input type="radio" name="wpmcp_access_level"
										value="<?php echo esc_attr( $key ); ?>"
										<?php checked( $current, $key ); ?>
										<?php disabled( defined( 'WPMCP_ACCESS_LEVEL' ) ); ?> />
									<strong><?php echo esc_html( $level['label'] ); ?></strong>
								</label>
								<br>
								<span class="description" style="margin-left:1.7em"><?php echo esc_html( $level['description'] ); ?></span>
							</p>
						<?php endforeach; ?>
						<p class="description">
							<strong><?php esc_html_e( 'Publishing is never possible, at any level.', 'wp-mcp-connector-plus' ); ?></strong>
							<?php esc_html_e( 'Neither is deleting, uploading files or changing settings. Tools the level does not allow are not registered at all, so the agent never sees them. Every write creates a revision.', 'wp-mcp-connector-plus' ); ?>
							<?php if ( defined( 'WPMCP_ACCESS_LEVEL' ) ) : ?>
								<br><strong><?php esc_html_e( 'Currently fixed by a constant in wp-config.php.', 'wp-mcp-connector-plus' ); ?></strong>
							<?php endif; ?>
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
										<?php disabled( defined( 'WPMCP_DYNAMIC_DATA' ) || ( 'allowed' === $key && ! wpmcp_can_write() ) ); ?> />
									<?php echo esc_html( $label ); ?>
								</label>
							</p>
						<?php endforeach; ?>
						<?php $dynamic_blocker = function_exists( 'wpmcp_unfiltered_html_blocker' ) ? wpmcp_unfiltered_html_blocker() : null; ?>
						<?php if ( $dynamic_blocker && 'allowed' === $dynamic_current ) : ?>
							<p class="description" style="color:#b32d2e">
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
										<?php disabled( defined( 'WPMCP_PATTERN_ACCESS' ) || ( 'write' === $key && ! wpmcp_can_write() ) ); ?> />
									<?php echo esc_html( $label ); ?>
								</label>
							</p>
						<?php endforeach; ?>
						<p class="description">
							<?php esc_html_e( 'A synced pattern appears on every page that embeds it, so changing one changes all of them at once — and a pattern has no draft state. The dry run says how many pieces of content are affected before anything is saved.', 'wp-mcp-connector-plus' ); ?>
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<h2><?php esc_html_e( 'Activity log', 'wp-mcp-connector-plus' ); ?></h2>
		<p class="description"><?php esc_html_e( 'The last 100 calls.', 'wp-mcp-connector-plus' ); ?></p>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Time (UTC)', 'wp-mcp-connector-plus' ); ?></th>
					<th><?php esc_html_e( 'Ability', 'wp-mcp-connector-plus' ); ?></th>
					<th><?php esc_html_e( 'Content', 'wp-mcp-connector-plus' ); ?></th>
					<th><?php esc_html_e( 'Mode', 'wp-mcp-connector-plus' ); ?></th>
					<th><?php esc_html_e( 'Result', 'wp-mcp-connector-plus' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $entries ) ) : ?>
				<tr><td colspan="5"><?php esc_html_e( 'Nothing logged yet.', 'wp-mcp-connector-plus' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $entries as $entry ) : ?>
					<tr>
						<td><?php echo esc_html( $entry->created_at ); ?></td>
						<td><code><?php echo esc_html( $entry->ability ); ?></code></td>
						<td>
							<?php if ( $entry->post_id ) : ?>
								<a href="<?php echo esc_url( (string) get_edit_post_link( (int) $entry->post_id ) ); ?>">
									<?php echo esc_html( get_the_title( (int) $entry->post_id ) ?: '#' . (int) $entry->post_id ); ?>
								</a>
							<?php else : ?>
								&mdash;
							<?php endif; ?>
						</td>
						<td>
							<?php
							echo $entry->dry_run
								? esc_html__( 'dry run', 'wp-mcp-connector-plus' )
								: esc_html( $entry->operation ? $entry->operation : '—' );
							?>
						</td>
						<td>
							<?php echo esc_html( $entry->summary ); ?>
							<?php if ( $entry->revision_id ) : ?>
								<br>
								<a href="<?php echo esc_url( (string) get_edit_post_link( (int) $entry->revision_id ) ); ?>">
									<?php esc_html_e( 'view revision', 'wp-mcp-connector-plus' ); ?>
								</a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
	</div>
	<?php
}
