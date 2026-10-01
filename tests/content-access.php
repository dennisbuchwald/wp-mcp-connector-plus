<?php
/**
 * Which posts the agent may read and write, decided by the connector.
 *
 * Capabilities answer most of it, but not all: they can come from places
 * the plugin does not control (a second role on the account, a role
 * editor), and some lines have to hold regardless. Published pages stay
 * read-only unless live editing is on, whatever the capabilities say.
 *
 * Run: php tests/content-access.php
 *
 * @package wp-mcp-connector-plus
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['posts'] = array();
$GLOBALS['caps']  = array();
$GLOBALS['live']  = false;

function get_post( $id ) { return $GLOBALS['posts'][ (int) $id ] ?? null; }
function current_user_can( $cap, ...$args ) {
	if ( isset( $args[0] ) && in_array( $cap, array( 'edit_post', 'edit_page' ), true ) ) {
		return ! empty( $GLOBALS['caps'][ 'edit_post:' . (int) $args[0] ] );
	}
	return ! empty( $GLOBALS['caps'][ $cap ] );
}
function get_post_types( $args = array(), $output = 'names' ) {
	$types = array(
		'page' => (object) array( 'name' => 'page', 'public' => true ),
		'post' => (object) array( 'name' => 'post', 'public' => true ),
	);
	return 'names' === $output ? array_keys( $types ) : $types;
}
function post_type_exists( $type ) { return in_array( $type, array( 'page', 'post' ), true ); }
function wpmcp_pattern_access() { return 'read'; }
function wpmcp_extra_post_types() { return array(); }
function wpmcp_live_edit_enabled() { return $GLOBALS['live']; }
function wpmcp_privacy_policy_page_id() { return 0; }
function wpmcp_log( $ability, $data = array() ) {}
function wpmcp_work_session_active() { return false; }

require_once __DIR__ . '/kses-stub.php';
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

function post( $id, $status, array $extra = array() ) {
	$GLOBALS['posts'][ $id ] = (object) array_merge(
		array(
			'ID'            => $id,
			'post_type'     => 'page',
			'post_status'   => $status,
			'post_password' => '',
			'post_content'  => '',
			'post_title'    => 'Seite ' . $id,
			'post_name'     => 'seite-' . $id,
			'post_parent'   => 0,
			'post_modified_gmt' => '2026-10-01 10:00:00',
		),
		$extra
	);
}

echo "\n\033[1mVeroeffentlichtes bleibt ohne Live-Edit gesperrt\033[0m\n";

post( 10, 'publish' );
post( 11, 'draft' );

// The capabilities say yes, for instance through a second role.
$GLOBALS['caps'] = array( 'edit_post:10' => true, 'edit_post:11' => true );

$GLOBALS['live'] = false;
$out = wpmcp_get_writable_post( 10 );
check(
	is_wp_error( $out ) && 'wpmcp_live_edit_disabled' === $out->get_error_code(),
	'eine veroeffentlichte Seite ist ohne Live-Edit nicht schreibbar, auch wenn die Rechte es erlauben',
	is_wp_error( $out ) ? $out->get_error_code() : 'schreibbar'
);
check( ! is_wp_error( wpmcp_get_writable_post( 11 ) ), 'ein Entwurf schon' );

$GLOBALS['live'] = true;
check( ! is_wp_error( wpmcp_get_writable_post( 10 ) ), 'mit Live-Edit auch die veroeffentlichte Seite' );

$GLOBALS['caps'] = array();
$out = wpmcp_get_writable_post( 10 );
check( is_wp_error( $out ) && 'wpmcp_forbidden' === $out->get_error_code(), 'ohne Recht bleibt es beim Nein' );
$GLOBALS['live'] = false;

echo "\n";
if ( 0 === $fail ) {
	echo "\033[32mZugriff auf Inhalte in Ordnung.\033[0m\n";
	exit( 0 );
}
echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
exit( 1 );
