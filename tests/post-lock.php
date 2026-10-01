<?php
/**
 * A person in the editor, and the agent's footprint on the page.
 *
 * expected_modified only protects what was saved. Someone typing in the
 * block editor has saved nothing, so the agent's write went through, and
 * either their next save replaced it or a reload threw their unsaved work
 * away. WordPress knows they are there (the post lock the editor keeps
 * fresh); a real write, batch or restore now asks and refuses with
 * wpmcp_locked, a dry run warns.
 *
 * The other direction: every real save stamps the post, so the editor can
 * say "the AI agent changed this page 12 minutes ago".
 *
 * Run: php tests/post-lock.php
 *
 * @package wp-mcp-connector-plus
 */

define( 'DAY_IN_SECONDS', 86400 );

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/kses-stub.php';

$GLOBALS['posts']     = array();
$GLOBALS['revisions'] = array();
$GLOBALS['meta']      = array();
$GLOBALS['logged']    = array();
$GLOBALS['purged']    = array();
$GLOBALS['patterns']  = 'read';
$GLOBALS['next_rev']  = 900;
$GLOBALS['ai_users']  = array( 9 );
$GLOBALS['users']     = array( 3, 9 );

function get_post( $id ) {
	return $GLOBALS['posts'][ (int) $id ] ?? null;
}
function get_post_field( $field, $id, $context = 'display' ) {
	$post = get_post( $id );
	return $post ? $post->$field : '';
}
function get_post_types( $args = array(), $output = 'names' ) {
	$page = (object) array( 'name' => 'page', 'public' => true );
	return 'names' === $output ? array( 'page' ) : array( 'page' => $page );
}
function post_type_exists( $type ) { return 'page' === $type; }
function get_post_type_object( $type ) {
	return (object) array( 'name' => $type, 'cap' => (object) array( 'publish_posts' => 'publish_pages', 'create_posts' => 'edit_pages' ) );
}
function current_user_can( ...$args ) { return true; }
function get_current_user_id() { return 9; }
function is_multisite() { return false; }
function sanitize_title( $t ) { return strtolower( trim( preg_replace( '/[^A-Za-z0-9]+/', '-', (string) $t ), '-' ) ); }
function sanitize_text_field( $v ) { return trim( (string) $v ); }
function get_post_meta( $id, $key = '', $single = false ) {
	if ( '' === $key ) {
		return array();
	}
	return $GLOBALS['meta'][ (int) $id ][ $key ] ?? '';
}
function update_post_meta( $id, $key, $value ) {
	$GLOBALS['meta'][ (int) $id ][ $key ] = stripslashes( $value );
	return true;
}
function delete_post_meta( $id, $key ) {
	unset( $GLOBALS['meta'][ (int) $id ][ $key ] );
	return true;
}
function wp_slash( $v ) { return is_string( $v ) ? addslashes( $v ) : $v; }
function wp_http_validate_url( $url ) { return true; }
function esc_url_raw( $url ) { return (string) $url; }
function get_permalink( $post ) { return 'https://example.test/?p=' . ( is_object( $post ) ? $post->ID : (int) $post ); }
function wp_get_post_revision( &$id ) { return $GLOBALS['revisions'][ (int) $id ] ?? null; }
/** The newest stored revision, whether or not the last save made it. */
function wp_get_post_revisions( $id, $args = array() ) {
	return array( 50 => (object) array( 'ID' => 50, 'post_parent' => (int) $id ) );
}
function get_userdata( $id ) {
	return in_array( (int) $id, $GLOBALS['users'], true ) ? (object) array( 'ID' => (int) $id, 'display_name' => 'Lara' ) : false;
}

/**
 * The editor lock as WordPress reports it: the holder, unless that is
 * the current user, and only while it is fresh.
 */
$GLOBALS['locks'] = array();
function wp_check_post_lock( $post_id ) {
	$holder = $GLOBALS['locks'][ (int) $post_id ] ?? 0;
	return ( $holder && $holder !== get_current_user_id() ) ? $holder : false;
}
function wpmcp_is_ai_user( $user ) {
	$id = is_object( $user ) ? (int) $user->ID : (int) $user;
	return in_array( $id, $GLOBALS['ai_users'], true );
}
function wpmcp_pattern_access() { return $GLOBALS['patterns']; }
function wpmcp_extra_post_types() { return array( 'gp_elements' ); }
function wpmcp_work_session_active() { return false; }
function wpmcp_live_edit_enabled() { return true; }
function wpmcp_privacy_policy_page_id() { return 0; }
function wpmcp_dynamic_data_allowed() { return false; }
function wpmcp_log( $ability, $data = array() ) { $GLOBALS['logged'][] = array_merge( array( 'ability' => $ability ), $data ); }
function wpmcp_preview_url( $id ) { return 'https://example.test/preview/' . $id; }
function wpmcp_purge_caches( $id ) {
	$GLOBALS['purged'][] = (int) $id;
	return array( 'object' => 'flushed' );
}

/** Pages embedding a pattern: only what the usage query needs. */
$GLOBALS['wpdb'] = new class() {
	public $posts = 'wp_posts';
	public $embedding = array();
	public function esc_like( $t ) { return $t; }
	public function prepare( $sql, ...$args ) { return $sql; }
	public function get_col( $sql ) { return $this->embedding; }
};

function kses_on() {
	$GLOBALS['dbw_filters']['content_save_pre']          = array( 'wp_filter_post_kses' );
	$GLOBALS['dbw_filters']['content_filtered_save_pre'] = array( 'wp_filter_post_kses' );
}
function remove_filter( $tag, $callback, $priority = 10 ) {
	$list = $GLOBALS['dbw_filters'][ $tag ] ?? array();
	foreach ( $list as $i => $registered ) {
		if ( $registered === $callback ) {
			unset( $list[ $i ] );
			$GLOBALS['dbw_filters'][ $tag ] = array_values( $list );
			return true;
		}
	}
	return false;
}
function kses_active() {
	return in_array( 'wp_filter_post_kses', $GLOBALS['dbw_filters']['content_save_pre'] ?? array(), true );
}

/**
 * Saves the way WordPress does: through kses unless the filter is off,
 * moving the modified stamp, and storing a revision only when the content
 * changed.
 */
function wp_update_post( $postarr, $error = false ) {
	$post = $GLOBALS['posts'][ (int) $postarr['ID'] ];
	$old  = $post->post_content;

	if ( isset( $postarr['post_content'] ) ) {
		$content            = stripslashes( $postarr['post_content'] );
		$post->post_content = kses_active() ? wp_kses_post( $content ) : $content;
	}
	foreach ( array( 'post_status', 'post_name', 'post_parent' ) as $column ) {
		if ( isset( $postarr[ $column ] ) ) {
			$post->$column = $postarr[ $column ];
		}
	}
	$post->post_modified_gmt = '2026-10-01 12:34:56';

	if ( $post->post_content !== $old ) {
		$id = ++$GLOBALS['next_rev'];
		$GLOBALS['revisions'][ $id ] = (object) array(
			'ID'           => $id,
			'post_parent'  => $post->ID,
			'post_author'  => get_current_user_id(),
			'post_content' => $post->post_content,
		);
		do_action( '_wp_put_post_revision', $id, $post->ID );
	}

	return $post->ID;
}

foreach ( array( 'core/html' => 'Custom HTML', 'core/block' => 'Pattern' ) as $name => $title ) {
	\WP_Block_Type_Registry::get_instance()->register( $name, array( 'title' => $title, 'attributes' => array() ) );
}

require_once dirname( __DIR__ ) . '/includes/content.php';
require_once dirname( __DIR__ ) . '/includes/editor.php';

$fail = 0;

function check( $ok, $name, $detail = '' ) {
	global $fail;
	if ( $ok ) {
		echo "  \033[32m✓\033[0m {$name}\n";
		return;
	}
	echo "  \033[31m✗\033[0m {$name}\n";
	if ( '' !== $detail ) {
		echo "      {$detail}\n";
	}
	++$fail;
}

function message_of( $r ) {
	if ( is_wp_error( $r ) ) {
		return $r->get_error_code() . ': ' . $r->get_error_message();
	}
	return wp_json_encode( $r['errors'] ?? $r );
}

function post( $id, $content, $type = 'page', $status = 'draft' ) {
	$GLOBALS['posts'][ $id ] = (object) array(
		'ID'                => $id,
		'post_status'       => $status,
		'post_parent'       => 0,
		'post_name'         => 'seite-' . $id,
		'post_type'         => $type,
		'post_content'      => $content,
		'post_password'     => '',
		'post_author'       => 3,
		'post_modified_gmt' => '2026-10-01 10:00:00',
	);
}


function revision( $id, $parent, $content, $author ) {
	$GLOBALS['revisions'][ $id ] = (object) array(
		'ID'                => $id,
		'post_parent'       => $parent,
		'post_author'       => $author,
		'post_content'      => $content,
		'post_modified_gmt' => '2026-09-01 10:00:00',
	);
}
function human_time_diff( $from, $to ) { return (int) round( ( $to - $from ) / 60 ) . ' mins'; }

$para = '<!-- wp:paragraph --><p>Hallo</p><!-- /wp:paragraph -->';
$op   = array( array( 'op' => 'insert', 'path' => '1', 'block' => array( 'name' => 'core/paragraph', 'html' => '<p>Neu</p>' ) ) );

kses_on();

echo "\n\033[1mEin Mensch hat die Seite im Editor offen\033[0m\n";

post( 10, $para );
$GLOBALS['locks'][10] = 3;

$r = wpmcp_write_content( array( 'post_id' => 10, 'ops' => $op, 'dry_run' => false ) );
check( is_wp_error( $r ) && 'wpmcp_locked' === $r->get_error_code(), 'ein echter Schreibvorgang wird abgelehnt', message_of( $r ) );
check( is_wp_error( $r ) && 0 === strpos( $r->get_error_message(), 'Lara is editing this page right now' ), 'und nennt, wer gerade bearbeitet', message_of( $r ) );
check( $para === $GLOBALS['posts'][10]->post_content, 'nichts gespeichert' );
check( empty( $GLOBALS['meta'][10]['_wpmcp_last_write'] ), 'und kein Stempel' );

$r = wpmcp_write_content( array( 'post_id' => 10, 'ops' => $op ) );
check( ! is_wp_error( $r ) && ! empty( $r['ok'] ), 'ein Probelauf geht durch', message_of( $r ) );
$warned = ! is_wp_error( $r ) && (bool) preg_grep( '/Lara is editing/', $r['warnings'] ?? array() );
check( $warned, 'mit einer Warnung', wp_json_encode( $r['warnings'] ?? null ) );

$r = wpmcp_write_content( array( 'post_id' => 10, 'meta' => array( 'rank_math_title' => 'X' ), 'dry_run' => false ) );
check( is_wp_error( $r ) && 'wpmcp_locked' === $r->get_error_code(), 'auch ein reiner Meta-Schreibvorgang wartet', message_of( $r ) );

revision( 501, 10, $para . $para, 3 );
$r = wpmcp_restore_revision( 10, 501, false );
check( is_wp_error( $r ) && 'wpmcp_locked' === $r->get_error_code(), 'ein Wiederherstellen ebenso', message_of( $r ) );
$r = wpmcp_restore_revision( 10, 501, true );
check( ! is_wp_error( $r ) && (bool) preg_grep( '/Lara is editing/', $r['warnings'] ?? array() ), 'sein Probelauf warnt nur', message_of( $r ) );

echo "\n\033[1mBatch: der Lauf stoppt vor dem ersten Speichern\033[0m\n";

post( 11, $para );
$r = wpmcp_batch_write( array( 'items' => array( array( 'post_id' => 11, 'ops' => $op ), array( 'post_id' => 10, 'ops' => $op ) ), 'dry_run' => false ) );
check( ! is_wp_error( $r ) && false === $r['ok'], 'der Batch ist nicht ok', message_of( $r ) );
check( ! is_wp_error( $r ) && 'wpmcp_locked' === ( $r['items'][1]['code'] ?? '' ), 'der gesperrte Posten traegt wpmcp_locked', wp_json_encode( $r['items'] ?? null ) );
check( $para === $GLOBALS['posts'][11]->post_content, 'und auch der freie Posten davor ist nicht gespeichert' );

$r = wpmcp_batch_write( array( 'items' => array( array( 'post_id' => 11, 'ops' => $op ), array( 'post_id' => 10, 'ops' => $op ) ) ) );
check( ! is_wp_error( $r ) && true === $r['ok'], 'ein Probelauf-Batch bleibt ok', message_of( $r ) );
check( ! is_wp_error( $r ) && (bool) preg_grep( '/Lara is editing/', $r['items'][1]['warnings'] ?? array() ), 'und warnt beim gesperrten Posten', wp_json_encode( $r['items'] ?? null ) );

echo "\n\033[1mKeine Sperre durch den Agenten selbst\033[0m\n";

$GLOBALS['locks'][10] = 9;
$r = wpmcp_write_content( array( 'post_id' => 10, 'ops' => $op, 'dry_run' => false ) );
check( ! is_wp_error( $r ) && ! empty( $r['ok'] ), 'die eigene Sperre haelt nichts auf', message_of( $r ) );

$GLOBALS['locks'][10] = 0;
$r = wpmcp_write_content( array( 'post_id' => 10, 'ops' => $op, 'dry_run' => false ) );
check( ! is_wp_error( $r ) && ! empty( $r['ok'] ), 'ohne Sperre geht es wie immer', message_of( $r ) );
check( ! is_wp_error( $r ) && ! preg_grep( '/is editing/', $r['warnings'] ?? array() ), 'ohne Warnung' );

echo "\n\033[1mDer Stempel fuer den Editor\033[0m\n";

$stamp = (int) ( $GLOBALS['meta'][10]['_wpmcp_last_write'] ?? 0 );
check( $stamp > 0 && abs( time() - $stamp ) < 5, 'ein echtes Speichern stempelt die Seite', var_export( $stamp, true ) );

post( 12, $para );
wpmcp_write_content( array( 'post_id' => 12, 'ops' => $op ) );
check( empty( $GLOBALS['meta'][12]['_wpmcp_last_write'] ), 'ein Probelauf stempelt nicht' );

revision( 502, 11, $para . $para, 3 );
wpmcp_restore_revision( 11, 502, false );
check( ! empty( $GLOBALS['meta'][11]['_wpmcp_last_write'] ), 'ein Wiederherstellen stempelt' );

$now = 1800000000;
$GLOBALS['meta'][20]['_wpmcp_last_write'] = $now - 12 * 60;
$text = wpmcp_last_write_notice_text( 20, $now );
check( false !== strpos( $text, 'The AI agent changed this page 12 mins ago' ), 'der Editor sagt, wann', $text );
$GLOBALS['meta'][20]['_wpmcp_last_write'] = $now - DAY_IN_SECONDS - 1;
check( '' === wpmcp_last_write_notice_text( 20, $now ), 'nach einem Tag nicht mehr' );
check( '' === wpmcp_last_write_notice_text( 21, $now ), 'und nie bei einer Seite, die der Agent nicht gespeichert hat' );

echo "\n";
if ( 0 === $fail ) {
	echo "\033[32mBearbeitungssperre und Stempel in Ordnung.\033[0m\n";
	exit( 0 );
}
echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
exit( 1 );
