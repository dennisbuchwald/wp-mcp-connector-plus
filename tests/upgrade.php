<?php
/**
 * An update gets what an activation gets.
 *
 * WordPress runs the activation hook on activation only, never on an
 * update. Everything the plugin set up there - the audit table, the role -
 * was therefore only right on sites that had installed the current
 * version fresh. A changed table definition never reached an updated site,
 * and a role that had gone missing was not recreated by anything: the
 * reconciler skipped a missing role on purpose.
 *
 * And a log line must never break the answer it belongs to. With
 * WP_DEBUG_DISPLAY on, a failing insert into the audit table printed the
 * database error as HTML straight into the JSON response.
 *
 * Run: php tests/upgrade.php
 *
 * @package wp-mcp-connector-plus
 */

// dbDelta() lives in wp-admin/includes/upgrade.php, which audit.php
// requires from ABSPATH. A throwaway ABSPATH holds a stand-in that counts.
$wpmcp_root = sys_get_temp_dir() . '/wpmcp-upgrade-' . getmypid();
@mkdir( $wpmcp_root . '/wp-admin/includes', 0777, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
file_put_contents( $wpmcp_root . '/wp-admin/includes/upgrade.php', '<?php function dbDelta( $sql ) { ++$GLOBALS["created"]; return array(); }' );
register_shutdown_function(
	function () use ( $wpmcp_root ) {
		unlink( $wpmcp_root . '/wp-admin/includes/upgrade.php' );
		rmdir( $wpmcp_root . '/wp-admin/includes' );
		rmdir( $wpmcp_root . '/wp-admin' );
		rmdir( $wpmcp_root );
	}
);
define( 'ABSPATH', $wpmcp_root . '/' );

$GLOBALS['options'] = array( 'wpmcp_access_level' => 'draft' );
$GLOBALS['role']    = null;
$GLOBALS['created'] = 0;
$GLOBALS['actions'] = array();

function get_option( $n, $d = false ) { return $GLOBALS['options'][ $n ] ?? $d; }
function update_option( $n, $v, $autoload = null ) { $GLOBALS['options'][ $n ] = $v; return true; }
function delete_option( $n ) { unset( $GLOBALS['options'][ $n ] ); return true; }
function add_action( $tag, $cb, $priority = 10, $args = 1 ) { $GLOBALS['actions'][ $tag ][] = $cb; return true; }
function add_filter( ...$a ) { return true; }
function apply_filters( $t, $v ) { return $v; }
function __( $t, $d = null ) { return $t; }
function is_multisite() { return false; }
function current_time( ...$a ) { return '2026-10-01 12:00:00'; }
function get_current_user_id() { return 7; }

class StubRole {
	public $capabilities = array();
	public function add_cap( $cap, $grant = true ) { $this->capabilities[ $cap ] = $grant; }
	public function remove_cap( $cap ) { unset( $this->capabilities[ $cap ] ); }
}
function get_role( $r ) { return $GLOBALS['role']; }
function wpmcp_register_role() {
	$GLOBALS['role'] = new StubRole();
	foreach ( wpmcp_role_capabilities() as $cap => $grant ) {
		$GLOBALS['role']->add_cap( $cap, $grant );
	}
}

const WPMCP_CAP  = 'wpmcp_access';
const WPMCP_ROLE = 'wpmcp_ai_editor';

/** A wpdb that fails the insert the way the real one does: printing, unless suppressed. */
class Loud_Wpdb {
	public $prefix   = 'wp_';
	public $suppress = false;
	public function get_charset_collate() { return ''; }
	public function suppress_errors( $suppress = true ) {
		$previous       = $this->suppress;
		$this->suppress = (bool) $suppress;
		return $previous;
	}
	public function insert( ...$a ) {
		if ( ! $this->suppress ) {
			echo "<div id='error'><p class='wpdberror'><strong>WordPress database error:</strong> [Table 'wp_wpmcp_log' doesn't exist]</p></div>";
		}
		return false;
	}
}
$GLOBALS['wpdb'] = new Loud_Wpdb();

require_once dirname( __DIR__ ) . '/includes/access.php';
require_once dirname( __DIR__ ) . '/includes/audit.php';

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

echo "\n\033[1mNach einem Update\033[0m\n";

check( function_exists( 'wpmcp_maybe_upgrade' ), 'wpmcp_maybe_upgrade() gibt es' );
check(
	in_array( 'wpmcp_maybe_upgrade', $GLOBALS['actions']['plugins_loaded'] ?? array(), true ),
	'und haengt an plugins_loaded',
	'ein Update ruft keinen Aktivierungs-Hook auf'
);

if ( function_exists( 'wpmcp_maybe_upgrade' ) ) {
	// A site updated from 0.18: no version stored, no role.
	wpmcp_maybe_upgrade();
	check( 1 === $GLOBALS['created'], 'die Tabelle wird angelegt bzw. angeglichen (dbDelta)' );
	check( null !== $GLOBALS['role'], 'die fehlende Rolle wird neu angelegt' );
	check( wpmcp_role_caps_match(), 'mit genau dem Lese-Set' );
	check( WPMCP_DB_VERSION === (int) ( $GLOBALS['options']['wpmcp_db_version'] ?? 0 ), 'und die Version wird gemerkt' );

	wpmcp_maybe_upgrade();
	wpmcp_maybe_upgrade();
	check( 1 === $GLOBALS['created'], 'danach kostet jeder Aufruf nur einen Optionsvergleich' );

	// A site on an older schema.
	$GLOBALS['options']['wpmcp_db_version'] = 1;
	$GLOBALS['role']->add_cap( 'edit_published_pages' );
	wpmcp_maybe_upgrade();
	check( 2 === $GLOBALS['created'], 'eine aeltere Version laeuft noch einmal durch' );
	check( wpmcp_role_caps_match(), 'und raeumt dabei die Rolle auf' );
}

echo "\n\033[1mDer Abgleich in wp-admin und REST\033[0m\n";

$GLOBALS['role'] = null;
wpmcp_reconcile_role();
check( null !== $GLOBALS['role'], 'eine geloeschte Rolle wird wieder angelegt', 'vorher wurde eine fehlende Rolle bewusst uebersprungen' );
check( wpmcp_role_caps_match(), 'mit dem Lese-Set' );

echo "\n\033[1mDas Protokoll schweigt bei einem Datenbankfehler\033[0m\n";


ob_start();
wpmcp_log( 'wpmcp/content-write', array( 'summary' => 'x' ) );
$printed = ob_get_clean();
check( '' === $printed, 'kein Datenbankfehler landet in der Antwort', $printed );
check( false === $GLOBALS['wpdb']->suppress, 'und die Einstellung von wpdb ist danach wie vorher' );

$GLOBALS['wpdb']->suppress = true;
ob_start();
wpmcp_log( 'wpmcp/content-write', array( 'summary' => 'x' ) );
ob_end_clean();
check( true === $GLOBALS['wpdb']->suppress, 'auch wenn sie vorher schon unterdrueckt war' );

echo "\n";
if ( 0 === $fail ) {
	echo "\033[32mUpdate-Pfad in Ordnung.\033[0m\n";
	exit( 0 );
}
echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
exit( 1 );
