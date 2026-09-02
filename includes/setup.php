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
 * Show the generated credentials as a complete quickstart, once.
 *
 * Deliberately a full recipe rather than fragments: someone who has never
 * seen this plugin should get from here to a working session without
 * reading anything else.
 *
 * @param array $c Connection details.
 */
function wpmcp_render_connection_result( array $c ) {
	$slug   = $c['slug'];
	$file   = '~/.claude/mcp-' . $slug . '.json';
	$alias  = 'wp-' . $slug;

	$config = wp_json_encode(
		array(
			'mcpServers' => array(
				$slug => array(
					'type'    => 'http',
					'url'     => $c['endpoint'],
					'headers' => array( 'Authorization' => $c['header'] ),
				),
			),
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	);

	$write_file = "mkdir -p ~/.claude && cat > {$file} <<'JSON'\n{$config}\nJSON\nchmod 600 {$file}";
	$add_alias  = "echo \"alias {$alias}='claude --mcp-config {$file} --strict-mcp-config'\" >> ~/.zshrc && source ~/.zshrc";
	$simple     = sprintf(
		'claude mcp add --transport http %s %s --header "Authorization: %s"',
		$slug,
		$c['endpoint'],
		$c['header']
	);
	?>
	<h2><?php esc_html_e( 'Your connection', 'wp-mcp-connector-plus' ); ?></h2>
	<div class="notice notice-warning inline">
		<p><strong><?php esc_html_e( 'Copy this now — the password is shown only once and cannot be recovered.', 'wp-mcp-connector-plus' ); ?></strong></p>
	</div>

	<h3><?php esc_html_e( 'Claude Code — recommended setup', 'wp-mcp-connector-plus' ); ?></h3>
	<p class="description">
		<?php esc_html_e( 'Connects this one site and nothing else: no other MCP servers in the session, more context left for the work, and the credentials stay out of your repositories. Run each block in a terminal.', 'wp-mcp-connector-plus' ); ?>
	</p>

	<p><strong><?php esc_html_e( '1. Save the connection', 'wp-mcp-connector-plus' ); ?></strong></p>
	<textarea readonly rows="14" style="width:100%;font-family:monospace"
		onclick="this.select()"><?php echo esc_textarea( $write_file ); ?></textarea>

	<p><strong><?php esc_html_e( '2. Create a shortcut', 'wp-mcp-connector-plus' ); ?></strong></p>
	<p class="description"><?php esc_html_e( 'For bash, replace ~/.zshrc with ~/.bashrc.', 'wp-mcp-connector-plus' ); ?></p>
	<textarea readonly rows="3" style="width:100%;font-family:monospace"
		onclick="this.select()"><?php echo esc_textarea( $add_alias ); ?></textarea>

	<p><strong><?php esc_html_e( '3. Start working', 'wp-mcp-connector-plus' ); ?></strong></p>
	<textarea readonly rows="2" style="width:100%;font-family:monospace"
		onclick="this.select()"><?php echo esc_textarea( $alias ); ?></textarea>
	<p class="description">
		<?php
		printf(
			/* translators: %s: the /mcp command */
			esc_html__( 'Inside the session, %s shows whether this site is connected.', 'wp-mcp-connector-plus' ),
			'<code>/mcp</code>'
		);
		?>
	</p>

	<p><strong><?php esc_html_e( '4. Try it', 'wp-mcp-connector-plus' ); ?></strong></p>
	<p class="description"><?php esc_html_e( 'Read-only, nothing can change:', 'wp-mcp-connector-plus' ); ?></p>
	<textarea readonly rows="2" style="width:100%;font-family:monospace" onclick="this.select()"><?php
		esc_html_e( 'Describe this website: which pages exist, and how is the front page built?', 'wp-mcp-connector-plus' );
	?></textarea>

	<h3><?php esc_html_e( 'Alternative: add it to your usual servers', 'wp-mcp-connector-plus' ); ?></h3>
	<p class="description">
		<?php esc_html_e( 'Simpler, but the session then also carries every other MCP server you have configured.', 'wp-mcp-connector-plus' ); ?>
	</p>
	<textarea readonly rows="4" style="width:100%;font-family:monospace"
		onclick="this.select()"><?php echo esc_textarea( $simple ); ?></textarea>

	<h3><?php esc_html_e( 'Other clients', 'wp-mcp-connector-plus' ); ?></h3>
	<p class="description">
		<?php
		printf(
			/* translators: 1: endpoint URL, 2: authorization header value */
			esc_html__( 'Endpoint %1$s with header %2$s.', 'wp-mcp-connector-plus' ),
			'<code>' . esc_html( $c['endpoint'] ) . '</code>',
			'<code>Authorization: ' . esc_html( $c['header'] ) . '</code>'
		);
		?>
	</p>
	<?php
}
