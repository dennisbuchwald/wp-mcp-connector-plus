<?php
/**
 * Admin surface: connection details, the live-edit switch, and the audit
 * log — so the question "what did the AI change on my site?" has an answer
 * that does not require database access.
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
		__( 'WP MCP Connector Plus', 'dbw-wp-mcp-connector-plus' ),
		__( 'MCP Connector', 'dbw-wp-mcp-connector-plus' ),
		'manage_options',
		'dbw-wp-mcp-connector-plus',
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
		'wpmcp_live_edit',
		array(
			'type'              => 'boolean',
			'sanitize_callback' => function ( $value ) {
				return empty( $value ) ? 0 : 1;
			},
			'default'           => false,
		)
	);
}
add_action( 'admin_init', 'wpmcp_admin_init' );

/**
 * Render the admin page.
 */
function wpmcp_render_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	global $wpdb;
	$table = wpmcp_audit_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom plugin table, no core API available.
	$entries = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 100" );

	$ai_users = get_users( array( 'role' => WPMCP_ROLE ) );
	$endpoint = rest_url( 'wpmcp/v1/mcp' );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'WP MCP Connector Plus', 'dbw-wp-mcp-connector-plus' ); ?></h1>

		<h2><?php esc_html_e( 'Connection', 'dbw-wp-mcp-connector-plus' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'MCP endpoint', 'dbw-wp-mcp-connector-plus' ); ?></th>
				<td><code><?php echo esc_html( $endpoint ); ?></code></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Agent user', 'dbw-wp-mcp-connector-plus' ); ?></th>
				<td>
					<?php if ( empty( $ai_users ) ) : ?>
						<p>
							<?php esc_html_e( 'No user has the AI Editor role yet. Create one under Users → Add New, give it that role, then generate an application password in its profile.', 'dbw-wp-mcp-connector-plus' ); ?>
						</p>
					<?php else : ?>
						<ul>
							<?php foreach ( $ai_users as $user ) : ?>
								<li>
									<code><?php echo esc_html( $user->user_login ); ?></code>
									&ndash;
									<a href="<?php echo esc_url( get_edit_user_link( $user->ID ) ); ?>">
										<?php esc_html_e( 'manage application passwords', 'dbw-wp-mcp-connector-plus' ); ?>
									</a>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
					<p class="description">
						<?php esc_html_e( 'Application passwords are enabled for this role only. For every other user they stay exactly as your site configured them.', 'dbw-wp-mcp-connector-plus' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Settings', 'dbw-wp-mcp-connector-plus' ); ?></h2>
		<form method="post" action="options.php">
			<?php settings_fields( 'wpmcp_settings' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Edit published content', 'dbw-wp-mcp-connector-plus' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="wpmcp_live_edit" value="1"
								<?php checked( wpmcp_live_edit_enabled() ); ?>
								<?php disabled( defined( 'WPMCP_LIVE_EDIT' ) ); ?> />
							<?php esc_html_e( 'Allow agents to change published pages directly', 'dbw-wp-mcp-connector-plus' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Off: agents work on drafts and new pages only, and published content is read-only to them. Publishing is never possible either way, that stays with a human. Every write creates a revision.', 'dbw-wp-mcp-connector-plus' ); ?>
							<?php if ( defined( 'WPMCP_LIVE_EDIT' ) ) : ?>
								<br><strong><?php esc_html_e( 'Currently fixed by a constant in wp-config.php.', 'dbw-wp-mcp-connector-plus' ); ?></strong>
							<?php endif; ?>
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<h2><?php esc_html_e( 'Activity log', 'dbw-wp-mcp-connector-plus' ); ?></h2>
		<p class="description"><?php esc_html_e( 'The last 100 calls.', 'dbw-wp-mcp-connector-plus' ); ?></p>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Time (UTC)', 'dbw-wp-mcp-connector-plus' ); ?></th>
					<th><?php esc_html_e( 'Ability', 'dbw-wp-mcp-connector-plus' ); ?></th>
					<th><?php esc_html_e( 'Content', 'dbw-wp-mcp-connector-plus' ); ?></th>
					<th><?php esc_html_e( 'Mode', 'dbw-wp-mcp-connector-plus' ); ?></th>
					<th><?php esc_html_e( 'Result', 'dbw-wp-mcp-connector-plus' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $entries ) ) : ?>
				<tr><td colspan="5"><?php esc_html_e( 'Nothing logged yet.', 'dbw-wp-mcp-connector-plus' ); ?></td></tr>
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
								? esc_html__( 'dry run', 'dbw-wp-mcp-connector-plus' )
								: esc_html( $entry->operation ? $entry->operation : '—' );
							?>
						</td>
						<td>
							<?php echo esc_html( $entry->summary ); ?>
							<?php if ( $entry->revision_id ) : ?>
								<br>
								<a href="<?php echo esc_url( (string) get_edit_post_link( (int) $entry->revision_id ) ); ?>">
									<?php esc_html_e( 'view revision', 'dbw-wp-mcp-connector-plus' ); ?>
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
