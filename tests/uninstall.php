<?php
/**
 * Deleting the plugin leaves nothing of it behind, on every site.
 *
 * The agent's credentials matter most: an application password that
 * survives the plugin is a login nobody remembers exists, on an account
 * nothing fences in any more.
 *
 * Run: php tests/uninstall.php
 *
 * @package wp-mcp-connector-plus
 */

$GLOBALS['blog']     = 1;
$GLOBALS['options']  = array();
$GLOBALS['roles']    = array();
$GLOBALS['tables']   = array();
$GLOBALS['revoked']  = array();
$GLOBALS['cleared']  = array();
$GLOBALS['multi']    = false;
$GLOBALS['switches'] = array();

$GLOBALS['wpdb'] = new class() {
	public $prefix = 'wp_';
	public $queries = array();
	public function query( $sql ) {
		$this->queries[] = $sql;
		if ( preg_match( '/DROP TABLE IF EXISTS (\S+)/', $sql, $m ) ) {
			unset( $GLOBALS['tables'][ $m[1] ] );
		}
		return true;
	}
};

class WP_Application_Passwords {
	public static function delete_all_application_passwords( $user_id ) {
		$GLOBALS['revoked'][] = $user_id;
		return 1;
	}
}

function is_multisite() { return $GLOBALS['multi']; }
function get_sites( $args ) { return array( 1, 2 ); }
function switch_to_blog( $id ) {
	$GLOBALS['switches'][] = $id;
	$GLOBALS['blog']       = $id;
	$GLOBALS['wpdb']->prefix = 1 === $id ? 'wp_' : "wp_{$id}_";
}
function restore_current_blog() { switch_to_blog( 1 ); }
function get_users( $args ) {
	// Agent 7 on site 1, agent 9 on site 2; the role filter matters.
	if ( 'wpmcp_ai_editor' !== ( $args['role'] ?? '' ) ) {
		return array( 1, 7, 9 );
	}
	return 1 === $GLOBALS['blog'] ? array( 7 ) : array( 9 );
}
function remove_role( $role ) { unset( $GLOBALS['roles'][ $GLOBALS['blog'] ][ $role ] ); }
function delete_option( $name ) { unset( $GLOBALS['options'][ $GLOBALS['blog'] ][ $name ] ); return true; }
function delete_post_meta_by_key( $key ) { $GLOBALS['meta_cleared'][ $GLOBALS['blog'] ][] = $key; return true; }
function wp_clear_scheduled_hook( $hook ) { $GLOBALS['cleared'][ $GLOBALS['blog'] ][] = $hook; }
function wp_unschedule_hook( $hook ) { $GLOBALS['cleared'][ $GLOBALS['blog'] ][] = $hook . ' (all)'; }

function seed( $blog ) {
	$prefix = 1 === $blog ? 'wp_' : "wp_{$blog}_";
	$GLOBALS['tables'][ $prefix . 'wpmcp_log' ] = true;
	$GLOBALS['tables'][ $prefix . 'posts' ]     = true;
	$GLOBALS['roles'][ $blog ]                  = array( 'wpmcp_ai_editor' => true, 'editor' => true );
	$GLOBALS['options'][ $blog ]                = array(
		'wpmcp_access_level'                     => 'full',
		'wpmcp_live_edit'                        => 1,
		'wpmcp_pattern_access'                   => 'write',
		'wpmcp_extra_post_types'                 => array( 'gp_elements' ),
		'wpmcp_dynamic_data'                     => 'allowed',
		'wpmcp_work_session_until'               => time() + 60,
		'wpmcp_db_version'                       => '1',
		'wpmcp_log_retention_days'               => 30,
		'external_updates-wp-mcp-connector-plus' => 'x',
		'blogname'                               => 'Kunde',
	);
}

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

echo "\n\033[1mNur ueber WordPress' Loeschen\033[0m\n";

$out = shell_exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( 'define("ABSPATH", "/"); require "' . dirname( __DIR__ ) . '/uninstall.php"; echo "LIEF";' ) );
check( false === strpos( (string) $out, 'LIEF' ), 'direkt aufgerufen tut die Datei nichts', 'WP_UNINSTALL_PLUGIN fehlt' );

define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_UNINSTALL_PLUGIN', 'wp-mcp-connector-plus/wp-mcp-connector-plus.php' );

echo "\n\033[1mMultisite\033[0m\n";

// The file itself runs here, loop and all. It declares functions, so it
// can be included once per process, as WordPress does.
$GLOBALS['multi'] = true;
seed( 1 );
seed( 2 );
require dirname( __DIR__ ) . '/uninstall.php';

check( array( 1, 2 ) === array_values( array_unique( array_filter( $GLOBALS['switches'], function ( $id ) { return $id > 0; } ) ) ), 'jede Seite des Netzwerks kommt dran' );
check( array( 7, 9 ) === $GLOBALS['revoked'], 'auf jeder Seite der Agent', 'widerrufen: ' . implode( ', ', $GLOBALS['revoked'] ) );
check( ! isset( $GLOBALS['tables']['wp_2_wpmcp_log'] ), 'jede Seite verliert ihr Protokoll' );
check( ! isset( $GLOBALS['roles'][2]['wpmcp_ai_editor'] ) && ! isset( $GLOBALS['options'][2]['wpmcp_access_level'] ), 'und ihre Rolle und Einstellungen' );
check( 1 === $GLOBALS['blog'], 'am Ende ist die eigene Seite wieder aktiv' );

echo "\n\033[1mEinzelne Seite\033[0m\n";

$GLOBALS['multi']    = false;
$GLOBALS['revoked']  = array();
$GLOBALS['switches'] = array();
seed( 1 );
wpmcp_uninstall_site();

check( array( 7 ) === $GLOBALS['revoked'], 'die Anwendungspasswoerter des Agenten sind widerrufen', 'widerrufen: ' . implode( ', ', $GLOBALS['revoked'] ) );
check( ! in_array( 1, $GLOBALS['revoked'], true ), 'die des Administrators nicht' );
check( ! isset( $GLOBALS['roles'][1]['wpmcp_ai_editor'] ), 'die Rolle ist entfernt' );
check( isset( $GLOBALS['roles'][1]['editor'] ), 'andere Rollen bleiben' );
check( ! isset( $GLOBALS['tables']['wp_wpmcp_log'] ), 'das Protokoll ist geloescht' );
check( isset( $GLOBALS['tables']['wp_posts'] ), 'sonst keine Tabelle' );
check( array( 'blogname' ) === array_keys( $GLOBALS['options'][1] ), 'alle Einstellungen sind weg, fremde bleiben', 'uebrig: ' . implode( ', ', array_keys( $GLOBALS['options'][1] ) ) );
check( in_array( 'puc_cron_check_updates-wp-mcp-connector-plus', $GLOBALS['cleared'][1] ?? array(), true ), 'und die Update-Pruefung ist abgemeldet' );
check( in_array( 'wpmcp_prune_log', $GLOBALS['cleared'][1] ?? array(), true ), 'die taegliche Protokoll-Bereinigung ist abgemeldet' );
check( in_array( 'wpmcp_purge_posts (all)', $GLOBALS['cleared'][1] ?? array(), true ), 'und geplante Cache-Leerungen, egal mit welchen Seiten' );
check( in_array( '_wpmcp_last_write', $GLOBALS['meta_cleared'][1] ?? array(), true ), 'der Stempel "vom Agenten geaendert" ist von allen Seiten entfernt' );

echo "\n";
if ( 0 === $fail ) {
	echo "\033[32mDeinstallation in Ordnung.\033[0m\n";
	exit( 0 );
}
echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
exit( 1 );
