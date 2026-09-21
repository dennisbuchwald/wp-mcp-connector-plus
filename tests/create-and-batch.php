<?php
/**
 * The dry run checks what the real call writes, and a batch is all or nothing.
 *
 * A seventy-five block tree sent to content-create as a dry run came back
 * "ok" without the tree having been looked at; the real call then created
 * the page. For a batch across posts, one bad item must stop the lot
 * before anything is saved.
 *
 * Run: php tests/create-and-batch.php
 *
 * @package wp-mcp-connector-plus
 */

require_once __DIR__ . '/bootstrap.php';

function wp_kses_post( $c ) { return preg_replace( '#<script\b[^>]*>.*?</script>#is', '', (string) $c ); }
function get_post_types( $args = array(), $output = 'names' ) {
	$page = (object) array( 'name' => 'page', 'public' => true );
	return 'names' === $output ? array( 'page' ) : array( 'page' => $page );
}
function post_type_exists( $t ) { return 'page' === $t; }
function get_post_type_object( $t ) {
	return (object) array( 'name' => $t, 'cap' => (object) array( 'create_posts' => 'edit_pages', 'publish_posts' => 'publish_pages' ) );
}
function current_user_can( ...$a ) { return true; }
function sanitize_title( $t ) { return strtolower( trim( preg_replace( '/[^A-Za-z0-9]+/', '-', (string) $t ), '-' ) ); }
function sanitize_text_field( $v ) { return trim( (string) $v ); }
function get_post_meta( ...$a ) { return ''; }
function get_post( $id ) { return null; }
function wpmcp_pattern_access() { return 'read'; }
function wpmcp_extra_post_types() { return array(); }
function wpmcp_work_session_active() { return false; }

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

echo "\n\033[1mDer Probelauf von content-create prueft den Baum\033[0m\n";

$r = wpmcp_create_content( array( 'title' => 'Motor', 'tree' => array( array( 'name' => 'dbw-base/hero', 'attrs' => array( 'heading' => 'Hallo' ) ) ) ) );
check( ! is_wp_error( $r ) && true === $r['ok'] && true === $r['dryRun'], 'ein gueltiger Baum besteht', is_wp_error( $r ) ? $r->get_error_message() : wp_json_encode( $r['errors'] ?? null ) );
check( ! is_wp_error( $r ) && 1 === ( $r['blocks'] ?? null ), 'mit Blockzahl im Bericht' );

$r = wpmcp_create_content( array( 'title' => 'Motor', 'tree' => array( array( 'name' => 'acme/gibt-es-nicht' ) ) ) );
check( ! is_wp_error( $r ) && false === $r['ok'], 'ein unbekannter Block faellt schon im Probelauf durch', 'vorher meldete der Probelauf hier ok' );
check( ! is_wp_error( $r ) && ! empty( $r['errors'] ), 'mit der Fehlermeldung' );

$r = wpmcp_create_content( array( 'title' => 'FAQ', 'tree' => array( array( 'name' => null, 'html' => '<script type="application/ld+json">{"@type":"FAQPage"}</script>' ) ) ) );
check( ! is_wp_error( $r ) && true === $r['ok'], 'JSON-LD im Baum ist erlaubt', is_wp_error( $r ) ? '' : wp_json_encode( $r['errors'] ) );

$r = wpmcp_create_content( array( 'title' => 'X', 'tree' => array( array( 'name' => null, 'html' => '<script>alert(1)</script>' ) ) ) );
check( ! is_wp_error( $r ) && false === $r['ok'], 'ein ausfuehrbares Script nicht' );

$r = wpmcp_create_content( array( 'title' => 'X', 'meta' => array( 'erfundener_schluessel' => 'x' ) ) );
check( ! is_wp_error( $r ) && false === $r['ok'], 'auch die Meta wird im Probelauf geprueft' );

echo "\n\033[1mBatch: nichts wird gespeichert, solange ein Posten scheitert\033[0m\n";

check( is_wp_error( wpmcp_batch_write( array( 'items' => array() ) ) ), 'eine leere Liste ist ein Fehler' );
check( is_wp_error( wpmcp_batch_write( array( 'items' => array_fill( 0, 21, array( 'post_id' => 1 ) ) ) ) ), 'mehr als 20 Posten ebenfalls' );

$dup = wpmcp_batch_write( array( 'items' => array( array( 'post_id' => 5, 'ops' => array() ), array( 'post_id' => 5, 'ops' => array() ) ) ) );
check( is_wp_error( $dup ) && false !== strpos( $dup->get_error_message(), 'twice' ), 'derselbe Beitrag zweimal wird abgelehnt', 'der zweite Save faende die Seite schon veraendert' );

$r = wpmcp_batch_write( array( 'items' => array( array( 'post_id' => 999, 'ops' => array( array( 'op' => 'remove', 'path' => '0' ) ) ) ), 'dry_run' => false ) );
check( ! is_wp_error( $r ) && false === $r['ok'], 'ein scheiternder Posten stoppt den Lauf' );
check( ! is_wp_error( $r ) && true === $r['dryRun'], 'bevor irgendetwas gespeichert wird', 'auch wenn dry_run: false verlangt war' );
check( ! is_wp_error( $r ) && ! empty( $r['items'][0]['error'] ), 'und der Posten sagt warum' );

$r = wpmcp_batch_write( array( 'items' => wp_json_encode( array( array( 'post_id' => 999 ) ) ) ) );
check( ! is_wp_error( $r ) && isset( $r['items'] ), 'items kommt auch als JSON-Text an' );

echo "\n";
if ( 0 === $fail ) {
	echo "\033[32mAnlegen und Batch in Ordnung.\033[0m\n";
	exit( 0 );
}
echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
exit( 1 );
