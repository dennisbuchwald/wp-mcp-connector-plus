<?php
/**
 * A running work session, visible on every screen.
 *
 * A session is the widest state the site can be in, and the settings
 * page is the one place that said so. Someone who opened it in the
 * morning and is now editing a page in the front end had no reminder.
 * The admin bar is on every screen they see, front end included; while a
 * session runs it shows how long is left and offers to close it.
 *
 * Costs one capability check and, for administrators, one option read
 * per page view; nothing at all for anyone else.
 *
 * @package wp-mcp-connector-plus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Time left, short and rounded to the minute: "2 h 14 min", "45 min".
 *
 * @param int $seconds Seconds left.
 * @return string
 */
function wpmcp_session_time_left( $seconds ) {
	$minutes = max( 1, (int) round( $seconds / 60 ) );
	$hours   = intdiv( $minutes, 60 );
	$minutes = $minutes % 60;

	if ( $hours > 0 ) {
		/* translators: 1: hours, 2: minutes */
		return sprintf( __( '%1$d h %2$d min', 'wp-mcp-connector-plus' ), $hours, $minutes );
	}
	/* translators: %d: minutes */
	return sprintf( __( '%d min', 'wp-mcp-connector-plus' ), $minutes );
}

/**
 * Add the node while a session runs, for those who can close it.
 *
 * @param \WP_Admin_Bar $bar The admin bar.
 */
function wpmcp_admin_bar_session( $bar ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$until = wpmcp_work_session_expires();
	if ( ! $until ) {
		return;
	}

	$settings = add_query_arg(
		array(
			'page' => 'wp-mcp-connector-plus',
			'tab'  => 'access',
		),
		admin_url( 'tools.php' )
	);

	$bar->add_node(
		array(
			'id'    => 'wpmcp-session',
			/* translators: %s: time left, e.g. "2 h 14 min" */
			'title' => esc_html( sprintf( __( 'MCP session: %s', 'wp-mcp-connector-plus' ), wpmcp_session_time_left( $until - time() ) ) ),
			'href'  => $settings,
			'meta'  => array(
				'title' => __( 'An MCP work session is running: the agent may change published pages, publish and upload images until it ends.', 'wp-mcp-connector-plus' ),
			),
		)
	);

	$bar->add_node(
		array(
			'parent' => 'wpmcp-session',
			'id'     => 'wpmcp-session-close',
			'title'  => esc_html__( 'Close session now', 'wp-mcp-connector-plus' ),
			'href'   => wp_nonce_url(
				add_query_arg(
					array(
						'action'             => 'wpmcp_session',
						'wpmcp_session_stop' => 1,
					),
					admin_url( 'admin-post.php' )
				),
				'wpmcp_work_session'
			),
		)
	);
}
add_action( 'admin_bar_menu', 'wpmcp_admin_bar_session', 100 );
