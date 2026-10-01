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
 * Handle the setup form. Returns the generated connection details, or a
 * WP_Error, or null when nothing was posted.
 *
 * Called from the admin-post handler (wpmcp_admin_post_setup), never while
 * a page renders. WordPress stores only a hash of the password, so the
 * handler keeps the result for one page view and no longer.
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
 * Render the setup form.
 *
 * It posts to admin-post.php, which creates the password and redirects
 * back here; see wpmcp_admin_post_setup().
 */
function wpmcp_render_setup_panel() {
	$existing = get_users( array( 'role' => WPMCP_ROLE, 'number' => 1 ) );
	?>
	<h2><?php esc_html_e( 'Set up a connection', 'wp-mcp-connector-plus' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Creates the agent user if needed, generates an application password, and gives you a ready-made command. The password is shown once and cannot be recovered afterwards; generate a new one instead.', 'wp-mcp-connector-plus' ); ?>
	</p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="wpmcp_setup" />
		<?php wp_nonce_field( 'wpmcp_setup', 'wpmcp_setup_nonce' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="wpmcp_login"><?php esc_html_e( 'Agent user', 'wp-mcp-connector-plus' ); ?></label>
				</th>
				<td>
					<input type="text" id="wpmcp_login" name="wpmcp_login"
						value="<?php echo esc_attr( $existing ? $existing[0]->user_login : 'ai-agent' ); ?>"
						class="regular-text" aria-describedby="wpmcp_login_description" />
					<p class="description" id="wpmcp_login_description">
						<?php
						echo $existing
							? esc_html__( 'This user already exists. A new application password will be added to it.', 'wp-mcp-connector-plus' )
							: esc_html__( 'Will be created with the AI Editor role: may edit content, may never delete or change settings, and may publish or upload images only during a work session you open.', 'wp-mcp-connector-plus' );
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
 * One read-only field with a Copy button, optionally behind "Show".
 *
 * A field holding the password (or the header carrying it) starts hidden
 * in a <details>: the page is often open on a shared screen or in a
 * screen recording, and copying works without ever showing it.
 *
 * @param string $id     Element ID.
 * @param string $label  Visible label.
 * @param string $value  Field content.
 * @param int    $rows   Height.
 * @param bool   $secret Hide behind "Show".
 */
function wpmcp_render_copy_field( $id, $label, $value, $rows = 2, $secret = false ) {
	?>
	<div class="wpmcp-field">
		<p><label for="<?php echo esc_attr( $id ); ?>"><strong><?php echo esc_html( $label ); ?></strong></label></p>
		<?php if ( $secret ) : ?>
			<details class="wpmcp-secret">
				<summary class="button button-small"><?php esc_html_e( 'Show', 'wp-mcp-connector-plus' ); ?></summary>
		<?php endif; ?>
		<textarea readonly id="<?php echo esc_attr( $id ); ?>" rows="<?php echo (int) $rows; ?>" class="large-text code" spellcheck="false"><?php echo esc_textarea( $value ); ?></textarea>
		<?php if ( $secret ) : ?>
			</details>
		<?php endif; ?>
		<div class="wpmcp-field-actions">
			<button type="button" class="button wpmcp-copy" data-copy="<?php echo esc_attr( $id ); ?>">
				<?php esc_html_e( 'Copy', 'wp-mcp-connector-plus' ); ?>
			</button>
			<span class="wpmcp-copied" role="status" aria-live="polite"></span>
		</div>
	</div>
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
	$slug  = $c['slug'];
	$file  = '~/.claude/mcp-' . $slug . '.json';
	$alias = 'wp-' . $slug;

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

	$add_cmd = sprintf(
		'claude mcp add --transport http -s user %s %s --header "Authorization: %s"',
		$slug,
		$c['endpoint'],
		$c['header']
	);

	$write_file = "mkdir -p ~/.claude && cat > {$file} <<'JSON'\n{$config}\nJSON\nchmod 600 {$file}";
	$add_alias  = "echo \"alias {$alias}='claude --mcp-config {$file}'\" >> ~/.zshrc && source ~/.zshrc";
	?>
	<h2><?php esc_html_e( 'Your connection', 'wp-mcp-connector-plus' ); ?></h2>
	<div class="notice notice-warning inline">
		<p><strong><?php esc_html_e( 'Copy this now: the password is shown only once and cannot be recovered. Reloading this page will not show it again.', 'wp-mcp-connector-plus' ); ?></strong></p>
	</div>

	<h3><?php esc_html_e( 'Claude Code: recommended setup', 'wp-mcp-connector-plus' ); ?></h3>
	<p class="description">
		<?php esc_html_e( 'Registers this site as a permanent MCP server. It loads alongside your other servers (SEO tools, calendars, etc.) in every Claude Code session. Run this once in a terminal.', 'wp-mcp-connector-plus' ); ?>
	</p>

	<?php wpmcp_render_copy_field( 'wpmcp-cmd-add', __( '1. Register the server', 'wp-mcp-connector-plus' ), $add_cmd, 4, true ); ?>
	<?php wpmcp_render_copy_field( 'wpmcp-cmd-start', __( '2. Start working', 'wp-mcp-connector-plus' ), 'claude', 1 ); ?>
	<p class="description">
		<?php
		printf(
			/* translators: %s: the /mcp command */
			esc_html__( 'Inside the session, %s shows whether this site is connected.', 'wp-mcp-connector-plus' ),
			'<code>/mcp</code>'
		);
		?>
	</p>
	<p class="description"><?php esc_html_e( 'A first prompt that only reads, nothing can change:', 'wp-mcp-connector-plus' ); ?></p>
	<?php wpmcp_render_copy_field( 'wpmcp-cmd-try', __( '3. Try it', 'wp-mcp-connector-plus' ), __( 'Describe this website: which pages exist, and how is the front page built?', 'wp-mcp-connector-plus' ), 2 ); ?>

	<h3><?php esc_html_e( 'Alternative: isolated config file', 'wp-mcp-connector-plus' ); ?></h3>
	<p class="description">
		<?php esc_html_e( 'Keeps credentials in a separate file and starts a session with only this site connected. Other MCP servers will not be available.', 'wp-mcp-connector-plus' ); ?>
	</p>
	<?php wpmcp_render_copy_field( 'wpmcp-cmd-file', __( 'Write the config file', 'wp-mcp-connector-plus' ), $write_file, 14, true ); ?>
	<?php wpmcp_render_copy_field( 'wpmcp-cmd-alias', __( 'Add a shell alias', 'wp-mcp-connector-plus' ), $add_alias, 3 ); ?>

	<h3><?php esc_html_e( 'Other clients', 'wp-mcp-connector-plus' ); ?></h3>
	<p class="description"><?php esc_html_e( 'Any MCP client that speaks HTTP: the endpoint, and the header to send with every request.', 'wp-mcp-connector-plus' ); ?></p>
	<?php wpmcp_render_copy_field( 'wpmcp-endpoint', __( 'Endpoint', 'wp-mcp-connector-plus' ), $c['endpoint'], 1 ); ?>
	<?php wpmcp_render_copy_field( 'wpmcp-user', __( 'User', 'wp-mcp-connector-plus' ), $c['login'], 1 ); ?>
	<?php wpmcp_render_copy_field( 'wpmcp-password', __( 'Application password', 'wp-mcp-connector-plus' ), $c['password'], 1, true ); ?>
	<?php wpmcp_render_copy_field( 'wpmcp-header', __( 'Authorization header', 'wp-mcp-connector-plus' ), 'Authorization: ' . $c['header'], 2, true ); ?>
	<?php
}
