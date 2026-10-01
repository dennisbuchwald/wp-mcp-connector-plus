<?php
/**
 * content-create creates a page with its content, or nothing.
 *
 * The real call inserted the page first and checked the content after.
 * Every tree with an unknown block, every onerror, every meta key off the
 * list left an empty draft behind, and the answer said "created, but the
 * content was rejected, fix it and write to this id". An agent that then
 * retried with content-create, as agents do, left a second one. A site
 * collected empty drafts named after pages that never came to be.
 *
 * Now everything that can be checked is checked before the insert, by the
 * same pipeline content-write uses, in the dry run and the real call
 * alike. Should the save still fail after the insert, the page that was
 * just created is deleted again, and the answer says so.
 *
 * Run: php tests/create-atomic.php
 *
 * @package wp-mcp-connector-plus
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/kses-stub.php';

$GLOBALS['posts']     = array();
$GLOBALS['revisions'] = array();
$GLOBALS['meta']      = array();
$GLOBALS['logged']    = array();
$GLOBALS['inserted']  = array();
$GLOBALS['deleted']   = array();
$GLOBALS['next_id']   = 100;
$GLOBALS['patterns']  = 'read';
$GLOBALS['session']   = false;
$GLOBALS['save_fails'] = false;

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
	return '' === $key ? array() : ( $GLOBALS['meta'][ (int) $id ][ $key ] ?? '' );
}
function update_post_meta( $id, $key, $value ) {
	$GLOBALS['meta'][ (int) $id ][ $key ] = stripslashes( $value );
	return true;
}
function delete_post_meta( $id, $key ) { return true; }
function wp_slash( $v ) { return is_array( $v ) ? array_map( 'wp_slash', $v ) : ( is_string( $v ) ? addslashes( $v ) : $v ); }
function wp_http_validate_url( $url ) { return true; }
function esc_url_raw( $url ) { return (string) $url; }
function get_permalink( $post ) { return 'https://example.test/?p=' . ( is_object( $post ) ? $post->ID : (int) $post ); }
function wp_get_post_revision( &$id ) { return $GLOBALS['revisions'][ (int) $id ] ?? null; }
function wpmcp_pattern_access() { return $GLOBALS['patterns']; }
function wpmcp_extra_post_types() { return array(); }
function wpmcp_work_session_active() { return $GLOBALS['session']; }
function wpmcp_live_edit_enabled() { return true; }
function wpmcp_privacy_policy_page_id() { return 0; }
function wpmcp_dynamic_data_allowed() { return false; }
function wpmcp_log( $ability, $data = array() ) { $GLOBALS['logged'][] = array_merge( array( 'ability' => $ability ), $data ); }
function wpmcp_preview_url( $id ) { return 'https://example.test/preview/' . $id; }
function wpmcp_purge_caches( $id ) { return array(); }

function wp_insert_post( $postarr, $error = false ) {
	$postarr = array_map( 'stripslashes', $postarr );
	$id      = ++$GLOBALS['next_id'];

	$GLOBALS['posts'][ $id ] = (object) array(
		'ID'                => $id,
		'post_title'        => $postarr['post_title'],
		'post_type'         => $postarr['post_type'],
		'post_name'         => '' !== $postarr['post_name'] ? $postarr['post_name'] : sanitize_title( $postarr['post_title'] ),
		'post_parent'       => (int) $postarr['post_parent'],
		'post_status'       => $postarr['post_status'],
		'post_content'      => $postarr['post_content'],
		'post_password'     => '',
		'post_modified_gmt' => '2026-10-01 12:00:00',
	);
	$GLOBALS['inserted'][] = $id;

	return $id;
}
function wp_update_post( $postarr, $error = false ) {
	if ( $GLOBALS['save_fails'] ) {
		return new WP_Error( 'db_update_error', 'Could not update post in the database.' );
	}
	$post = $GLOBALS['posts'][ (int) $postarr['ID'] ];
	if ( isset( $postarr['post_content'] ) ) {
		$post->post_content = stripslashes( $postarr['post_content'] );
	}
	if ( isset( $postarr['post_status'] ) ) {
		$post->post_status = $postarr['post_status'];
	}
	return $post->ID;
}
function remove_filter( $tag, $callback, $priority = 10 ) {
	foreach ( $GLOBALS['dbw_filters'][ $tag ] ?? array() as $i => $registered ) {
		if ( $registered === $callback ) {
			unset( $GLOBALS['dbw_filters'][ $tag ][ $i ] );
			return true;
		}
	}
	return false;
}
function wp_delete_post( $id, $force = false ) {
	$GLOBALS['deleted'][] = array( (int) $id, $force );
	unset( $GLOBALS['posts'][ (int) $id ] );
	return true;
}

\WP_Block_Type_Registry::get_instance()->register( 'core/html', array( 'title' => 'Custom HTML', 'attributes' => array() ) );

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
	return wp_json_encode( $r );
}

function reset_site() {
	$GLOBALS['posts']    = array();
	$GLOBALS['inserted'] = array();
	$GLOBALS['deleted']  = array();
	$GLOBALS['logged']   = array();
}

$hero = array( array( 'name' => 'dbw-base/hero', 'attrs' => array( 'heading' => 'Hallo' ) ) );

echo "\n\033[1mWas nicht durchgeht, legt nichts an\033[0m\n";

$refused = array(
	'unbekannter Block'      => array( 'tree' => array( array( 'name' => 'acme/gibt-es-nicht' ) ) ),
	'onerror im Baum'        => array( 'tree' => array( array( 'name' => 'core/html', 'html' => '<img src="x" onerror="alert(1)">' ) ) ),
	'fremder Meta-Schluessel' => array( 'tree' => $hero, 'meta' => array( 'erfundener_schluessel' => 'x' ) ),
);

foreach ( $refused as $label => $content ) {
	reset_site();
	$r = wpmcp_create_content( array_merge( array( 'title' => 'Motor', 'dry_run' => false ), $content ) );
	check( ! is_wp_error( $r ) && false === $r['ok'], "{$label}: abgelehnt", message_of( $r ) );
	check( empty( $GLOBALS['inserted'] ), "{$label}: kein leerer Entwurf bleibt zurueck", 'angelegt: ' . implode( ', ', $GLOBALS['inserted'] ) );
	check( ! is_wp_error( $r ) && ! empty( $r['errors'] ), "{$label}: mit den Fehlern" );
	check( ! is_wp_error( $r ) && empty( $r['id'] ), "{$label}: und ohne ID, an die man schreiben koennte" );
}

reset_site();
$r     = wpmcp_create_content( array( 'title' => 'Motor', 'dry_run' => false, 'tree' => array( array( 'name' => 'acme/gibt-es-nicht' ) ) ) );
$entry = end( $GLOBALS['logged'] );
check( $entry && 'rejected' === ( $entry['operation'] ?? '' ), 'die Ablehnung steht im Protokoll', wp_json_encode( $entry ) );

echo "\n\033[1mProbelauf und echter Aufruf pruefen dasselbe\033[0m\n";

foreach ( $refused as $label => $content ) {
	reset_site();
	$dry  = wpmcp_create_content( array_merge( array( 'title' => 'Motor' ), $content ) );
	$real = wpmcp_create_content( array_merge( array( 'title' => 'Motor', 'dry_run' => false ), $content ) );
	check(
		! is_wp_error( $dry ) && ! is_wp_error( $real ) && $dry['errors'] === $real['errors'],
		"{$label}: dieselben Fehler im Probelauf und im echten Aufruf",
		wp_json_encode( array( $dry['errors'] ?? null, $real['errors'] ?? null ) )
	);
}

echo "\n\033[1mScheitert das Speichern danach, wird der Entwurf wieder entfernt\033[0m\n";

reset_site();
$GLOBALS['save_fails'] = true;
$r = wpmcp_create_content( array( 'title' => 'Motor', 'tree' => $hero, 'dry_run' => false ) );
$GLOBALS['save_fails'] = false;
check( ! is_wp_error( $r ) && false === $r['ok'], 'das Ergebnis ist kein Erfolg', message_of( $r ) );
check( 1 === count( $GLOBALS['inserted'] ) && empty( $GLOBALS['posts'] ), 'der angelegte Entwurf ist wieder weg' );
check( ! empty( $GLOBALS['deleted'] ) && true === $GLOBALS['deleted'][0][1], 'endgueltig, nicht im Papierkorb' );
check( ! is_wp_error( $r ) && false !== strpos( $r['message'] ?? '', 'removed' ), 'und die Antwort sagt das', $r['message'] ?? '' );
check( ! is_wp_error( $r ) && empty( $r['id'] ), 'ohne ID' );

echo "\n\033[1mWas gelingt, gelingt wie bisher\033[0m\n";

reset_site();
$r = wpmcp_create_content( array( 'title' => 'Motor', 'tree' => $hero, 'meta' => array( 'rank_math_title' => 'Titel' ), 'dry_run' => false ) );
check( ! is_wp_error( $r ) && true === $r['ok'], 'Seite mit Inhalt und Meta wird angelegt', message_of( $r ) );
$id = is_wp_error( $r ) ? 0 : (int) ( $r['id'] ?? 0 );
check( $id && false !== strpos( $GLOBALS['posts'][ $id ]->post_content, 'dbw-base/hero' ), 'mit dem Inhalt' );
check( $id && 'Titel' === ( $GLOBALS['meta'][ $id ]['rank_math_title'] ?? '' ), 'und der Meta' );
check( empty( $GLOBALS['deleted'] ), 'nichts wurde geloescht' );

reset_site();
$r = wpmcp_create_content( array( 'title' => 'Leer', 'dry_run' => false ) );
check( ! is_wp_error( $r ) && true === $r['ok'] && ! empty( $r['id'] ), 'eine Seite ohne Inhalt wird angelegt, wie bisher', message_of( $r ) );

reset_site();
$GLOBALS['session'] = true;
$r = wpmcp_create_content( array( 'title' => 'Live', 'tree' => $hero, 'status' => 'publish', 'dry_run' => false ) );
$id = is_wp_error( $r ) ? 0 : (int) ( $r['id'] ?? 0 );
check( ! is_wp_error( $r ) && true === $r['ok'] && 'publish' === ( $r['status'] ?? '' ), 'in einer Arbeitssitzung: angelegt, befuellt, veroeffentlicht', message_of( $r ) );
check( $id && 'publish' === $GLOBALS['posts'][ $id ]->post_status, 'und so gespeichert' );

reset_site();
$r = wpmcp_create_content( array( 'title' => 'Live', 'tree' => array( array( 'name' => 'acme/gibt-es-nicht' ) ), 'status' => 'publish', 'dry_run' => false ) );
check( ! is_wp_error( $r ) && false === $r['ok'] && empty( $GLOBALS['inserted'] ), 'abgelehnter Inhalt: nichts angelegt, nichts veroeffentlicht', message_of( $r ) );
$GLOBALS['session'] = false;

echo "\n\033[1mMuster brauchen das Schreibrecht fuer Muster\033[0m\n";

reset_site();
$GLOBALS['patterns'] = 'read';
$r = wpmcp_create_content( array( 'title' => 'Muster', 'post_type' => 'wp_block', 'tree' => $hero, 'dry_run' => false ) );
check( is_wp_error( $r ) && 'wpmcp_pattern_readonly' === $r->get_error_code(), 'ohne Schreibrecht kein neues Muster', message_of( $r ) );
check( empty( $GLOBALS['inserted'] ), 'und kein leerer Entwurf' );

echo "\n";
if ( 0 === $fail ) {
	echo "\033[32mAnlegen in einem Schritt in Ordnung.\033[0m\n";
	exit( 0 );
}
echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
exit( 1 );
