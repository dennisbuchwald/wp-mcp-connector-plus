<?php
/**
 * One-click connection setup.
 *
 * Doing this by hand means: create a user, pick the right role, find the
 * application password panel, copy a value shown exactly once, base64 the
 * credentials, and assemble a command. Six steps with three places to get
 * it subtly wrong. This does all of it and hands back something to paste.
 *
 * @package wp-mcp-connector-plus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handle the setup form. Returns the generated connection details for the
 * one render that follows, or a WP_Error, or null when nothing was posted.
 *
 * The password exists in memory for exactly this request — WordPress
 * stores only a hash, so it can never be shown again.
 *
 * @return array|\WP_Error|null
 */
function wpmcp_handle_setup_post() {
	if ( ! isset( $_POST['wpmcp_setup_nonce'] ) ) {
		return null;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return new \WP_Error( 'wpmcp_forbidden', __( 'You are not allowed to do this.', 'wp-mcp-connector-plus' ) );
	}
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wpmcp_setup_nonce'] ) ), 'wpmcp_setup' ) ) {
		return new \WP_Error( 'wpmcp_nonce', __( 'Security check failed. Please try again.', 'wp-mcp-connector-plus' ) );
	}

	$login = isset( $_POST['wpmcp_login'] ) ? sanitize_user( wp_unslash( $_POST['wpmcp_login'] ) ) : '';
	if ( '' === $login ) {
		$login = 'ai-agent';
	}

	$user = get_user_by( 'login', $login );

	if ( ! $user ) {
		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $login . '@' . wp_parse_url( home_url(), PHP_URL_HOST ),
				'user_pass'    => wp_generate_password( 32, true, true ),
				'display_name' => __( 'AI Agent', 'wp-mcp-connector-plus' ),
				'role'         => WPMCP_ROLE,
			)
		);
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}
		$user = get_user_by( 'id', $user_id );
	} elseif ( ! in_array( WPMCP_ROLE, (array) $user->roles, true ) ) {
		// Existing user, wrong role — do not silently escalate someone.
		return new \WP_Error(
			'wpmcp_user_exists',
			sprintf(
				/* translators: %s: user login */
				__( 'A user named "%s" already exists with a different role. Pick another name, or assign the AI Editor role to that user first.', 'wp-mcp-connector-plus' ),
				$login
			)
		);
	}

	if ( ! class_exists( '\WP_Application_Passwords' ) ) {
		return new \WP_Error( 'wpmcp_no_app_passwords', __( 'Application passwords are not available on this site.', 'wp-mcp-connector-plus' ) );
	}

	$created = \WP_Application_Passwords::create_new_application_password(
		$user->ID,
		array( 'name' => 'MCP Connector ' . gmdate( 'Y-m-d H:i' ) )
	);

	if ( is_wp_error( $created ) ) {
		return $created;
	}

	$password = $created[0];
	$host     = wp_parse_url( home_url(), PHP_URL_HOST );
	$slug     = sanitize_title( $host );

	return array(
		'login'    => $user->user_login,
		'password' => $password,
		'endpoint' => rest_url( 'wpmcp/v1/mcp' ),
		'header'   => 'Basic ' . base64_encode( $user->user_login . ':' . $password ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth, not obfuscation.
		'slug'     => $slug,
	);
}

/**
 * Render the setup panel: either the form, or the generated connection.
 *
 * @param array|\WP_Error|null $result Result of the setup handler.
 */
function wpmcp_render_setup_panel( $result ) {
	if ( is_wp_error( $result ) ) {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html( $result->get_error_message() )
		);
	}

	if ( is_array( $result ) ) {
		wpmcp_render_connection_result( $result );
		return;
	}

	$existing = get_users( array( 'role' => WPMCP_ROLE, 'number' => 1 ) );
	?>
	<h2><?php esc_html_e( 'Set up a connection', 'wp-mcp-connector-plus' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Creates the agent user if needed, generates an application password, and gives you a ready-made command. The password is shown once and cannot be recovered afterwards — generate a new one instead.', 'wp-mcp-connector-plus' ); ?>
	</p>
	<form method="post">
		<?php wp_nonce_field( 'wpmcp_setup', 'wpmcp_setup_nonce' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="wpmcp_login"><?php esc_html_e( 'Agent user', 'wp-mcp-connector-plus' ); ?></label>
				</th>
				<td>
					<input type="text" id="wpmcp_login" name="wpmcp_login"
						value="<?php echo esc_attr( $existing ? $existing[0]->user_login : 'ai-agent' ); ?>"
						class="regular-text" />
					<p class="description">
						<?php
						echo $existing
							? esc_html__( 'This user already exists. A new application password will be added to it.', 'wp-mcp-connector-plus' )
							: esc_html__( 'Will be created with the AI Editor role: may edit content, may not publish, delete, upload or change settings.', 'wp-mcp-connector-plus' );
						?>
					</p>
				</td>
			</tr>
		</table>
		<?php submit_button( __( 'Generate connection', 'wp-mcp-connector-plus' ), 'primary', 'submit', true ); ?>
	</form>
	<?php
}

/**
 * Show the generated credentials, once.
 *
 * @param array $c Connection details.
 */
function wpmcp_render_connection_result( array $c ) {
	$command = sprintf(
		'claude mcp add --transport http %s %s --header "Authorization: %s"',
		$c['slug'],
		$c['endpoint'],
		$c['header']
	);

	$config = wp_json_encode(
		array(
			'mcpServers' => array(
				$c['slug'] => array(
					'type'    => 'http',
					'url'     => $c['endpoint'],
					'headers' => array( 'Authorization' => $c['header'] ),
				),
			),
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	);
	?>
	<h2><?php esc_html_e( 'Your connection', 'wp-mcp-connector-plus' ); ?></h2>
	<div class="notice notice-warning inline">
		<p><strong><?php esc_html_e( 'Copy this now — it is shown only once.', 'wp-mcp-connector-plus' ); ?></strong></p>
	</div>

	<h3><?php esc_html_e( 'Claude Code, quick', 'wp-mcp-connector-plus' ); ?></h3>
	<p class="description"><?php esc_html_e( 'Adds this site to your usual set of MCP servers.', 'wp-mcp-connector-plus' ); ?></p>
	<textarea readonly rows="3" style="width:100%;font-family:monospace"
		onclick="this.select()"><?php echo esc_textarea( $command ); ?></textarea>

	<h3><?php esc_html_e( 'Claude Code, isolated (recommended)', 'wp-mcp-connector-plus' ); ?></h3>
	<p class="description">
		<?php
		printf(
			/* translators: %s: suggested file name */
			esc_html__( 'Save as %s, then start Claude Code with only this site connected. Keeps other MCP servers out of the session and the credentials out of your repositories.', 'wp-mcp-connector-plus' ),
			'<code>~/.claude/mcp-' . esc_html( $c['slug'] ) . '.json</code>'
		);
		?>
	</p>
	<textarea readonly rows="12" style="width:100%;font-family:monospace"
		onclick="this.select()"><?php echo esc_textarea( $config ); ?></textarea>
	<p><?php esc_html_e( 'Then start it with:', 'wp-mcp-connector-plus' ); ?></p>
	<textarea readonly rows="2" style="width:100%;font-family:monospace" onclick="this.select()"><?php
		echo esc_textarea(
			sprintf(
				"claude --mcp-config ~/.claude/mcp-%s.json --strict-mcp-config",
				$c['slug']
			)
		);
	?></textarea>
	<?php
}
