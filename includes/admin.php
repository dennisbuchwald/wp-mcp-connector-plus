<?php
/**
 * Admin surface: connection details, the live-edit switch, and the audit
 * log — so a customer question about what the AI changed has an answer
 * that does not require database access.
 *
 * @package dbw-connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the admin page under Tools.
 */
function dbw_connector_admin_menu() {
	add_management_page(
		'dbw Connector',
		'dbw Connector',
		'manage_options',
		'dbw-connector',
		'dbw_connector_render_admin_page'
	);
}
add_action( 'admin_menu', 'dbw_connector_admin_menu' );

/**
 * Register settings.
 */
function dbw_connector_admin_init() {
	register_setting(
		'dbw_connector_settings',
		'dbw_connector_live_edit',
		array(
			'type'              => 'boolean',
			'sanitize_callback' => function ( $value ) {
				return empty( $value ) ? 0 : 1;
			},
			'default'           => false,
		)
	);
}
add_action( 'admin_init', 'dbw_connector_admin_init' );

/**
 * Render the admin page.
 */
function dbw_connector_render_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	global $wpdb;
	$table = dbw_connector_audit_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom plugin table, no core API available.
	$entries = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 100" );

	$ai_users = get_users( array( 'role' => DBW_CONNECTOR_ROLE ) );
	$endpoint = rest_url( 'dbw-connector/v1/mcp' );
	?>
	<div class="wrap">
		<h1>dbw Connector</h1>

		<h2>Verbindung</h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">MCP-Endpunkt</th>
				<td><code><?php echo esc_html( $endpoint ); ?></code></td>
			</tr>
			<tr>
				<th scope="row">KI-Benutzer</th>
				<td>
					<?php if ( empty( $ai_users ) ) : ?>
						<p>
							Noch kein Benutzer mit der Rolle <code>dbw KI-Redakteur</code>.
							Lege einen an (Benutzer &rarr; Neu hinzuf&uuml;gen), weise ihm diese Rolle zu
							und erzeuge in seinem Profil ein Anwendungspasswort.
						</p>
					<?php else : ?>
						<ul>
							<?php foreach ( $ai_users as $user ) : ?>
								<li>
									<code><?php echo esc_html( $user->user_login ); ?></code>
									&ndash; <a href="<?php echo esc_url( get_edit_user_link( $user->ID ) ); ?>">Anwendungspasswort verwalten</a>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
					<p class="description">
						Anwendungspassw&ouml;rter sind ausschlie&szlig;lich f&uuml;r diese Rolle freigeschaltet.
						F&uuml;r alle anderen Benutzer bleiben sie deaktiviert.
					</p>
				</td>
			</tr>
		</table>

		<h2>Einstellungen</h2>
		<form method="post" action="options.php">
			<?php settings_fields( 'dbw_connector_settings' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Ver&ouml;ffentlichte Seiten bearbeiten</th>
					<td>
						<label>
							<input type="checkbox" name="dbw_connector_live_edit" value="1"
								<?php checked( dbw_connector_live_edit_enabled() ); ?>
								<?php disabled( defined( 'DBW_CONNECTOR_LIVE_EDIT' ) ); ?> />
							Die KI darf ver&ouml;ffentlichte Inhalte direkt &auml;ndern
						</label>
						<p class="description">
							Aus: Die KI arbeitet nur an Entw&uuml;rfen und neuen Seiten &ndash; Live-Seiten sind
							f&uuml;r sie schreibgesch&uuml;tzt. Ver&ouml;ffentlichen kann sie in keinem Fall,
							das bleibt beim Menschen. Jede Schreibung erzeugt eine Revision.
							<?php if ( defined( 'DBW_CONNECTOR_LIVE_EDIT' ) ) : ?>
								<br><strong>Per Konstante in der wp-config.php festgelegt.</strong>
							<?php endif; ?>
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<h2>Protokoll</h2>
		<p class="description">Die letzten 100 Zugriffe.</p>
		<table class="widefat striped">
			<thead>
				<tr>
					<th>Zeit (UTC)</th>
					<th>Aktion</th>
					<th>Seite</th>
					<th>Modus</th>
					<th>Ergebnis</th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $entries ) ) : ?>
				<tr><td colspan="5">Noch keine Eintr&auml;ge.</td></tr>
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
						<td><?php echo $entry->dry_run ? 'Testlauf' : esc_html( $entry->operation ?: '&mdash;' ); ?></td>
						<td>
							<?php echo esc_html( $entry->summary ); ?>
							<?php if ( $entry->revision_id ) : ?>
								<br><a href="<?php echo esc_url( (string) get_edit_post_link( (int) $entry->revision_id ) ); ?>">Revision ansehen</a>
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
