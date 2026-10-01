<?php
/**
 * The agent's footprint where people edit: a stamp on every page it saved,
 * and a notice in the block editor while that is recent.
 *
 * The other direction (a person in the editor, the agent about to save)
 * is wpmcp_post_lock_error() in content.php. This is the way back: a
 * person who opens a page the agent changed an hour ago should not find
 * out from a diff that it was not them.
 *
 * Loaded on every request, because the stamp is written during REST
 * requests. Two hooks, no work until one fires.
 *
 * @package wp-mcp-connector-plus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Post meta holding the time of the agent's last real save.
 */
const WPMCP_LAST_WRITE_META = '_wpmcp_last_write';

/**
 * How long the editor mentions a save, in seconds (a day). A literal:
 * the constant is defined while this file loads, before anything
 * guarantees DAY_IN_SECONDS.
 */
const WPMCP_LAST_WRITE_WINDOW = 86400;

/**
 * Remember when the agent saved a post.
 *
 * A timestamp and nothing else: who and what are in the activity log and
 * the revisions. The leading underscore keeps it out of the custom fields
 * panel and out of anything the agent can read or write.
 *
 * @param int $post_id Post the agent saved.
 */
function wpmcp_stamp_last_write( $post_id ) {
	update_post_meta( (int) $post_id, WPMCP_LAST_WRITE_META, time() );
}
add_action( 'wpmcp_saved', 'wpmcp_stamp_last_write' );

/**
 * What the editor should say about the agent's last save, if anything.
 *
 * @param int      $post_id Post being edited.
 * @param int|null $now     Current time, for tests.
 * @return string Empty when the agent never saved it or not within a day.
 */
function wpmcp_last_write_notice_text( $post_id, $now = null ) {
	$at  = (int) get_post_meta( (int) $post_id, WPMCP_LAST_WRITE_META, true );
	$now = null === $now ? time() : (int) $now;

	if ( $at <= 0 || $at > $now || $now - $at >= WPMCP_LAST_WRITE_WINDOW ) {
		return '';
	}

	return sprintf(
		/* translators: %s: time since the save, e.g. "12 mins". */
		__( 'The AI agent changed this page %s ago. Its revisions show what changed, and the activity log under Tools > MCP Connector shows why.', 'wp-mcp-connector-plus' ),
		human_time_diff( $at, $now )
	);
}

/**
 * Show that text as a notice in the block editor.
 *
 * The block editor does not render the classic admin_notices reliably,
 * so the notice goes through its own notices store, once, when the
 * editor has loaded. Only on the post screen (not the site editor), and
 * only for someone who may edit the post anyway.
 */
function wpmcp_enqueue_last_write_notice() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'post' !== $screen->base ) {
		return;
	}

	$post = get_post();
	if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
		return;
	}

	$text = wpmcp_last_write_notice_text( $post->ID );
	if ( '' === $text ) {
		return;
	}

	wp_add_inline_script(
		'wp-edit-post',
		sprintf(
			'wp.domReady(function(){wp.data.dispatch("core/notices").createNotice("info",%s,{id:"wpmcp-last-write",isDismissible:true});});',
			wp_json_encode( $text )
		)
	);
}
add_action( 'enqueue_block_editor_assets', 'wpmcp_enqueue_last_write_notice' );
