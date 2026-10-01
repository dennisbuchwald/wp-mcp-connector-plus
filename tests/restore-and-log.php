<?php
/**
 * A restore is a write, and the log says what a write did.
 *
 * content-restore had grown up next to content-write rather than out of
 * it. It skipped the gate for synced patterns and for elements that run
 * PHP, so a revision could be put back where a write was refused. It
 * never cleared a cache, so the live page kept the state the restore was
 * meant to undo, and it returned no stamp for the next write. And it
 * counted every revision's markup as already stored, including revisions
 * the agent had saved itself before 0.18.3, when markup kses removes could
 * still get through: the hole closed, and restore opened it again.
 *
 * The other half is what the log and the response claim. "revisionId"
 * named the newest revision of the post even when the save had not made
 * one; a rejected real write was logged as a dry run; a meta-only write
 * as "ops", and twice; a batch without the posts it touched.
 *
 * Run: php tests/restore-and-log.php
 *
 * @package wp-mcp-connector-plus
 */

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
function wp_get_post_revision( $id ) { return $GLOBALS['revisions'][ (int) $id ] ?? null; }
/** The newest stored revision, whether or not the last save made it. */
function wp_get_post_revisions( $id, $args = array() ) {
	return $GLOBALS['revision_list'] ?? array( 50 => (object) array( 'ID' => 50, 'post_parent' => (int) $id ) );
}
function get_userdata( $id ) {
	return in_array( (int) $id, $GLOBALS['users'], true ) ? (object) array( 'ID' => (int) $id ) : false;
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

$para  = '<!-- wp:paragraph --><p>Hallo</p><!-- /wp:paragraph -->';
$embed = '<!-- wp:html --><iframe src="https://player.test/1"></iframe><!-- /wp:html -->';
$xss   = '<!-- wp:html --><img src="x" onerror="alert(1)"><!-- /wp:html -->';

kses_on();

echo "\n\033[1mcontent-restore geht durch denselben Waechter wie content-write\033[0m\n";

post( 10, $para, 'wp_block' );
revision( 501, 10, $para . $para, 3 );
$GLOBALS['patterns'] = 'read';
$r = wpmcp_restore_revision( 10, 501, false );
check( is_wp_error( $r ) && 'wpmcp_pattern_readonly' === $r->get_error_code(), 'ein Muster bleibt ohne Schreibrecht fuer Muster gesperrt', message_of( $r ) );
check( $para === $GLOBALS['posts'][10]->post_content, 'und unveraendert' );

post( 11, $para, 'gp_elements' );
revision( 502, 11, $para . $para, 3 );
$GLOBALS['meta'][11]['_generate_hook_execute_php'] = 'true';
$r = wpmcp_restore_revision( 11, 502, true );
check( is_wp_error( $r ) && 'wpmcp_runs_code' === $r->get_error_code(), 'ein Element mit Execute PHP ebenso, schon im Probelauf', message_of( $r ) );

// A copy of an element that runs PHP is a second element that runs PHP.
$r = wpmcp_duplicate_post( 11 );
check( is_wp_error( $r ) && 'wpmcp_runs_code' === $r->get_error_code(), 'content-duplicate kopiert kein Element mit Execute PHP', message_of( $r ) );

$r = wpmcp_duplicate_post( 10 );
check( is_wp_error( $r ) && 'wpmcp_pattern_readonly' === $r->get_error_code(), 'und kein Muster, solange Muster nicht schreibbar sind', message_of( $r ) );

echo "\n\033[1mNach dem Wiederherstellen\033[0m\n";

$GLOBALS['patterns']         = 'write';
$GLOBALS['wpdb']->embedding  = array( 70, 71 );
$GLOBALS['purged']           = array();
$r = wpmcp_restore_revision( 10, 501, false );
check( ! is_wp_error( $r ) && ! empty( $r['ok'] ), 'mit Schreibrecht fuer Muster geht es', message_of( $r ) );
check( in_array( 10, $GLOBALS['purged'], true ), 'der Cache der Seite wird geleert', 'geleert: ' . implode( ', ', $GLOBALS['purged'] ) );
check( array( 10, 70, 71 ) === $GLOBALS['purged'], 'auch der Seiten, die das Muster einbinden', 'geleert: ' . implode( ', ', $GLOBALS['purged'] ) );
check( ! is_wp_error( $r ) && isset( $r['cache'] ), 'die Antwort sagt, was mit dem Cache passiert ist' );
check( ! is_wp_error( $r ) && '2026-10-01 12:34:56' === ( $r['modified'] ?? null ), 'und gibt den Stempel fuer den naechsten Schreibvorgang zurueck', var_export( $r['modified'] ?? null, true ) );

echo "\n\033[1mEin Muster in sehr vielen Seiten\033[0m\n";

// Up to 2000 embedding pages were purged inside the request, one page
// cache call each, before the agent got its answer. The first 200 still
// are; the rest go to WP-Cron in chunks.
if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( $time, $hook, $args = array() ) {
		$GLOBALS['single_events'][] = array( 'time' => $time, 'hook' => $hook, 'args' => $args );
		return true;
	}
}
$GLOBALS['single_events']   = array();
$GLOBALS['wpdb']->embedding = range( 1001, 1450 );
$GLOBALS['purged']          = array();
$r = wpmcp_restore_revision( 10, 501, false );
check( ! is_wp_error( $r ) && ! empty( $r['ok'] ), 'gespeichert', message_of( $r ) );
check( 201 === count( $GLOBALS['purged'] ), 'sofort geleert: die Seite selbst und 200 einbindende', count( $GLOBALS['purged'] ) . ' geleert' );
check( ! is_wp_error( $r ) && 200 === count( $r['cache']['alsoPurged'] ?? array() ) && 1001 === ( $r['cache']['alsoPurged'][0] ?? null ), 'alsoPurged nennt diese 200' );
check( ! is_wp_error( $r ) && 250 === ( $r['cache']['alsoScheduled'] ?? null ), 'alsoScheduled zaehlt die 250 uebrigen', wp_json_encode( $r['cache']['alsoScheduled'] ?? null ) );
$later = array();
foreach ( $GLOBALS['single_events'] as $event ) {
	if ( 'wpmcp_purge_posts' === $event['hook'] ) {
		$later = array_merge( $later, $event['args'][0] ?? array() );
		check( count( $event['args'][0] ?? array() ) <= 200, 'ein geplanter Lauf leert hoechstens 200 Seiten' );
	}
}
check( range( 1201, 1450 ) === $later, 'die uebrigen 250 sind eingeplant, keine doppelt, keine vergessen', count( $later ) . ' eingeplant' );

$GLOBALS['single_events']   = array();
$GLOBALS['wpdb']->embedding = array( 70, 71 );
$r = wpmcp_restore_revision( 10, 501, false );
check( ! is_wp_error( $r ) && ! isset( $r['cache']['alsoScheduled'] ) && array() === $GLOBALS['single_events'], 'bei wenigen Seiten wird nichts eingeplant' );

echo "\n\033[1mRevisionen des Agenten zaehlen nicht als bekannt\033[0m\n";

// Saved by the agent before 0.18.3, through the hole.
post( 20, $para );
revision( 503, 20, $para . $xss, 9 );
$r = wpmcp_restore_revision( 20, 503, false );
check( is_wp_error( $r ) && 'wpmcp_unsafe_markup' === $r->get_error_code(), 'eine Revision des Agenten mit onerror wird abgelehnt', message_of( $r ) );
check( $para === $GLOBALS['posts'][20]->post_content, 'und nichts gespeichert' );
check( is_wp_error( $r ) && false !== strpos( $r->get_error_message(), 'agent' ), 'die Meldung sagt, warum diese Revision anders zaehlt', message_of( $r ) );

// The same markup saved by a person is that person's decision.
revision( 504, 20, $para . $embed, 3 );
$r = wpmcp_restore_revision( 20, 504, false );
check( ! is_wp_error( $r ) && ! empty( $r['ok'] ), 'die Revision eines Menschen mit Embed geht', message_of( $r ) );
check( false !== strpos( $GLOBALS['posts'][20]->post_content, '<iframe' ), 'mit dem Embed' );

// A revision whose author no longer exists proves nothing about who saved it.
post( 21, $para );
revision( 505, 21, $para . $embed, 44 );
$r = wpmcp_restore_revision( 21, 505, true );
check( is_wp_error( $r ), 'eine Revision ohne auffindbaren Autor zaehlt nicht als bekannt', message_of( $r ) );

// What the agent saved cleanly can always come back.
post( 22, $para );
revision( 506, 22, $para . $para, 9 );
$r = wpmcp_restore_revision( 22, 506, false );
check( ! is_wp_error( $r ) && ! empty( $r['ok'] ), 'eine saubere Revision des Agenten geht', message_of( $r ) );

echo "\n\033[1mrevisionId nennt die Revision dieses Speicherns\033[0m\n";

post( 30, $para );
$r = wpmcp_write_content( array( 'post_id' => 30, 'ops' => array( array( 'op' => 'insert', 'path' => '1', 'block' => array( 'name' => 'core/paragraph', 'html' => '<p>Neu</p>' ) ) ), 'dry_run' => false ) );
check( ! is_wp_error( $r ) && ! empty( $r['ok'] ), 'ein Schreibvorgang mit Inhalt', message_of( $r ) );
check( ! is_wp_error( $r ) && $GLOBALS['next_rev'] === ( $r['revisionId'] ?? null ), 'meldet die neue Revision', var_export( $r['revisionId'] ?? null, true ) );

$r = wpmcp_write_content( array( 'post_id' => 30, 'status' => 'pending', 'dry_run' => false ) );
check( ! is_wp_error( $r ) && ! empty( $r['ok'] ), 'ein reiner Statuswechsel', message_of( $r ) );
check( ! is_wp_error( $r ) && 0 === ( $r['revisionId'] ?? null ), 'meldet keine, weil keine entstanden ist', 'vorher die neueste vorhandene: ' . var_export( $r['revisionId'] ?? null, true ) );

echo "\n\033[1mWas das Protokoll festhaelt\033[0m\n";

post( 40, $para );
$GLOBALS['logged'] = array();
$r = wpmcp_write_content( array( 'post_id' => 40, 'tree' => array( array( 'name' => 'acme/gibt-es-nicht' ) ), 'dry_run' => false ) );
$entry = end( $GLOBALS['logged'] );
check( ! is_wp_error( $r ) && false === $r['ok'], 'ein abgelehnter echter Schreibvorgang' );
check( $entry && 'rejected' === ( $entry['operation'] ?? '' ), 'steht als "rejected" im Protokoll', wp_json_encode( $entry ) );
check( $entry && empty( $entry['dry_run'] ), 'und nicht als Probelauf', 'er war keiner' );

$GLOBALS['logged'] = array();
wpmcp_write_content( array( 'post_id' => 40, 'tree' => array( array( 'name' => 'acme/gibt-es-nicht' ) ) ) );
$entry = end( $GLOBALS['logged'] );
check( $entry && ! empty( $entry['dry_run'] ), 'ein abgelehnter Probelauf bleibt ein Probelauf' );

$GLOBALS['logged'] = array();
$GLOBALS['meta'][40]['rank_math_title'] = 'Alter Titel';
$r = wpmcp_write_content( array( 'post_id' => 40, 'meta' => array( 'rank_math_title' => 'Neuer Titel' ), 'dry_run' => false ) );
check( ! is_wp_error( $r ) && ! empty( $r['ok'] ), 'ein reiner Meta-Schreibvorgang', message_of( $r ) );
check( 1 === count( $GLOBALS['logged'] ), 'steht einmal im Protokoll', count( $GLOBALS['logged'] ) . ' Eintraege' );
check( 'meta' === ( $GLOBALS['logged'][0]['operation'] ?? '' ), 'als "meta", nicht als "ops"', wp_json_encode( $GLOBALS['logged'][0] ?? null ) );
check( false !== strpos( $GLOBALS['logged'][0]['summary'] ?? '', 'Alter Titel' ), 'mit dem alten Wert' );

$GLOBALS['logged'] = array();
$r = wpmcp_write_content( array( 'post_id' => 40, 'status' => 'pending', 'dry_run' => false ) );
check( 1 === count( $GLOBALS['logged'] ) && 'placement' === ( $GLOBALS['logged'][0]['operation'] ?? '' ), 'ein reiner Statuswechsel als "placement"', wp_json_encode( $GLOBALS['logged'] ) );

$GLOBALS['logged'] = array();
$r = wpmcp_write_content(
	array(
		'post_id' => 40,
		'tree'    => array( array( 'name' => 'core/paragraph', 'html' => '<p>Anders</p>' ) ),
		'meta'    => array( 'rank_math_title' => 'Dritter Titel' ),
		'dry_run' => false,
	)
);
check( 1 === count( $GLOBALS['logged'] ), 'Baum und Meta zusammen: ein Eintrag', count( $GLOBALS['logged'] ) . ' Eintraege' );
check( 'tree' === ( $GLOBALS['logged'][0]['operation'] ?? '' ) && false !== strpos( $GLOBALS['logged'][0]['summary'] ?? '', 'Neuer Titel' ), 'als "tree", und der alte Meta-Wert steht darin', wp_json_encode( $GLOBALS['logged'][0] ?? null ) );

echo "\n\033[1mBatch\033[0m\n";

post( 41, $para );
post( 42, $para );
$GLOBALS['logged'] = array();
$op = array( array( 'op' => 'insert', 'path' => '1', 'block' => array( 'name' => 'core/paragraph', 'html' => '<p>B</p>' ) ) );
$r  = wpmcp_batch_write( array( 'items' => array( array( 'post_id' => 41, 'ops' => $op ), array( 'post_id' => 42, 'ops' => $op ) ), 'dry_run' => false ) );
$entry = end( $GLOBALS['logged'] );
check( ! is_wp_error( $r ) && ! empty( $r['ok'] ), 'zwei Posten gespeichert', message_of( $r ) );
check( 'wpmcp/content-batch' === ( $entry['ability'] ?? '' ) && false !== strpos( $entry['summary'], '41' ) && false !== strpos( $entry['summary'], '42' ), 'die Zusammenfassung nennt die Beitraege', $entry['summary'] ?? '' );

echo "\n\033[1mBatch prueft einmal\033[0m\n";

// A real batch ran every item through the full check twice: once in the
// dry run that guards the whole run, once more in the save. The second
// check of an unchanged post repeats the first exactly, render included.
$GLOBALS['patterns']        = 'write';
$GLOBALS['wpdb']->embedding = array();
$op = array( array( 'op' => 'insert', 'path' => '1', 'block' => array( 'name' => 'core/paragraph', 'html' => '<p>C</p>' ) ) );
post( 43, $para );
post( 44, $para );
$GLOBALS['dbw_do_blocks_calls'] = 0;
$r = wpmcp_batch_write( array( 'items' => array( array( 'post_id' => 43, 'ops' => $op ), array( 'post_id' => 44, 'ops' => $op ) ), 'dry_run' => false ) );
check( ! is_wp_error( $r ) && ! empty( $r['ok'] ) && 2 === $r['saved'], 'zwei Posten gespeichert', message_of( $r ) );
check( 2 === $GLOBALS['dbw_do_blocks_calls'], 'jede Seite wird einmal geprueft (und gerendert), nicht zweimal', $GLOBALS['dbw_do_blocks_calls'] . ' Renderlaeufe' );
check( false !== strpos( $GLOBALS['posts'][44]->post_content, '<p>C</p>' ), 'und das Gepruefte ist gespeichert' );

// Changed between its dry run and its save: checked again, on what is
// stored now.
post( 45, $para );
post( 46, $para );
$GLOBALS['dbw_actions']['wpmcp_saved'][] = function ( $id ) {
	if ( 45 === $id ) {
		$GLOBALS['posts'][46]->post_content      = '<!-- wp:paragraph --><p>Von jemand anderem</p><!-- /wp:paragraph -->';
		$GLOBALS['posts'][46]->post_modified_gmt = '2026-10-01 11:11:11';
	}
};
$GLOBALS['dbw_do_blocks_calls'] = 0;
$r = wpmcp_batch_write( array( 'items' => array( array( 'post_id' => 45, 'ops' => $op ), array( 'post_id' => 46, 'ops' => $op ) ), 'dry_run' => false ) );
array_pop( $GLOBALS['dbw_actions']['wpmcp_saved'] );
check( 3 === $GLOBALS['dbw_do_blocks_calls'], 'eine Seite, die sich dazwischen geaendert hat, wird neu geprueft', $GLOBALS['dbw_do_blocks_calls'] . ' Renderlaeufe' );
check( false !== strpos( $GLOBALS['posts'][46]->post_content, 'Von jemand anderem' ) && false !== strpos( $GLOBALS['posts'][46]->post_content, '<p>C</p>' ), 'und die Aenderung auf den neuen Stand angewandt' );

// A pattern renders inside other pages. Saved first, it changes how the
// pages after it render, so nothing in that batch is carried over.
post( 47, $para, 'wp_block' );
post( 48, $para );
$GLOBALS['dbw_do_blocks_calls'] = 0;
$r = wpmcp_batch_write( array( 'items' => array( array( 'post_id' => 47, 'ops' => $op ), array( 'post_id' => 48, 'ops' => $op ) ), 'dry_run' => false ) );
check( ! is_wp_error( $r ) && ! empty( $r['ok'] ), 'ein Batch mit einem Muster wird gespeichert', message_of( $r ) );
check( 4 === $GLOBALS['dbw_do_blocks_calls'], 'und jede Seite beim Speichern neu geprueft', $GLOBALS['dbw_do_blocks_calls'] . ' Renderlaeufe' );

echo "\n\033[1mRevisionsliste ohne Parser\033[0m\n";

// blockCount per revision used to parse each one, up to 50 per call.
if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $post ) { return 'Seite'; }
}
if ( ! function_exists( 'wp_is_post_autosave' ) ) {
	function wp_is_post_autosave( $post ) { return false; }
}
$nested = '<!-- wp:group --><div><!-- wp:paragraph --><p>a</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
post( 60, $nested );
$GLOBALS['revision_list'] = array(
	61 => (object) array( 'ID' => 61, 'post_parent' => 60, 'post_author' => 99, 'post_modified_gmt' => '2026-10-01 09:00:00', 'post_content' => $nested . '<!-- wp:image /-->' ),
);
$GLOBALS['dbw_parse_blocks_calls'] = 0;
$r = wpmcp_list_revisions( 60 );
unset( $GLOBALS['revision_list'] );
check( ! is_wp_error( $r ) && 2 === ( $r['current']['blockCount'] ?? null ), 'der aktuelle Stand zaehlt 2 Bloecke, der verschachtelte mit', wp_json_encode( $r['current'] ?? null ) );
check( ! is_wp_error( $r ) && 3 === ( $r['revisions'][0]['blockCount'] ?? null ), 'die Revision 3', wp_json_encode( $r['revisions'][0] ?? null ) );
check( 0 === $GLOBALS['dbw_parse_blocks_calls'], 'ohne zu parsen', $GLOBALS['dbw_parse_blocks_calls'] . ' Aufrufe von parse_blocks' );

echo "\n";
if ( 0 === $fail ) {
	echo "\033[32mWiederherstellen und Protokoll in Ordnung.\033[0m\n";
	exit( 0 );
}
echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
exit( 1 );
