<?php
/**
 * The audit log keeps what the Activity tab promises, and no more.
 *
 * The tab said "Entries are kept for 90 days", and nothing deleted
 * anything: every read, dry run and refusal of the agent stayed in the
 * table for good. On a site an agent works on daily that is tens of
 * thousands of rows a month, in a table read on every visit to the tab.
 *
 * Run: php tests/log-retention.php
 *
 * @package wp-mcp-connector-plus
 */

// dbDelta() lives in wp-admin/includes/upgrade.php, which audit.php
// requires from ABSPATH. A throwaway ABSPATH holds a stand-in.
$wpmcp_root = sys_get_temp_dir() . '/wpmcp-retention-' . getmypid();
@mkdir( $wpmcp_root . '/wp-admin/includes', 0777, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
file_put_contents( $wpmcp_root . '/wp-admin/includes/upgrade.php', '<?php function dbDelta( $sql ) { return array(); }' );
register_shutdown_function(
	function () use ( $wpmcp_root ) {
		unlink( $wpmcp_root . '/wp-admin/includes/upgrade.php' );
		rmdir( $wpmcp_root . '/wp-admin/includes' );
		rmdir( $wpmcp_root . '/wp-admin' );
		rmdir( $wpmcp_root );
	}
);
define( 'ABSPATH', $wpmcp_root . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['options']   = array( 'wpmcp_access_level' => 'draft' );
$GLOBALS['actions']   = array();
$GLOBALS['filters']   = array();
$GLOBALS['scheduled'] = array();
$GLOBALS['role']      = null;

function get_option( $n, $d = false ) { return $GLOBALS['options'][ $n ] ?? $d; }
function update_option( $n, $v, $autoload = null ) { $GLOBALS['options'][ $n ] = $v; return true; }
function delete_option( $n ) { unset( $GLOBALS['options'][ $n ] ); return true; }
function add_action( $tag, $cb, $priority = 10, $args = 1 ) { $GLOBALS['actions'][ $tag ][] = $cb; return true; }
function add_filter( $tag, $cb, $priority = 10, $args = 1 ) { $GLOBALS['filters'][ $tag ][] = $cb; return true; }
function apply_filters( $tag, $value, ...$args ) {
	foreach ( $GLOBALS['filters'][ $tag ] ?? array() as $cb ) {
		$value = $cb( $value, ...$args );
	}
	return $value;
}
function __( $t, $d = null ) { return $t; }
function is_multisite() { return false; }
function current_time( ...$a ) { return gmdate( 'Y-m-d H:i:s' ); }
function get_current_user_id() { return 1; }
function absint( $v ) { return abs( (int) $v ); }

function wp_next_scheduled( $hook ) { return $GLOBALS['scheduled'][ $hook ]['time'] ?? false; }
function wp_schedule_event( $time, $recurrence, $hook ) {
	$GLOBALS['scheduled'][ $hook ] = array( 'time' => $time, 'recurrence' => $recurrence );
	return true;
}
function wp_clear_scheduled_hook( $hook ) { unset( $GLOBALS['scheduled'][ $hook ] ); return 1; }

class StubRole {
	public $capabilities = array();
	public function add_cap( $cap, $grant = true ) { $this->capabilities[ $cap ] = $grant; }
	public function remove_cap( $cap ) { unset( $this->capabilities[ $cap ] ); }
}
function get_role( $r ) { return $GLOBALS['role']; }
function wpmcp_register_role() { $GLOBALS['role'] = new StubRole(); }

const WPMCP_CAP  = 'wpmcp_access';
const WPMCP_ROLE = 'wpmcp_ai_editor';

/*
 * The log table in SQLite. SQLite's DELETE takes no LIMIT, so the stand-in
 * turns "DELETE ... LIMIT n" into the equivalent subquery and records the
 * statement as sent.
 */
$GLOBALS['pdo'] = new PDO( 'sqlite::memory:' );
$GLOBALS['pdo']->exec( 'CREATE TABLE wp_wpmcp_log (id INTEGER PRIMARY KEY AUTOINCREMENT, created_at TEXT, summary TEXT)' );

$GLOBALS['wpdb'] = new class() {
	public $prefix  = 'wp_';
	public $queries = array();
	public function get_charset_collate() { return ''; }
	public function suppress_errors( $s = true ) { return false; }
	public function prepare( $query, ...$args ) {
		$args = isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : $args;
		return preg_replace_callback(
			'/%[sd]/',
			function ( $m ) use ( &$args ) {
				$value = array_shift( $args );
				return '%d' === $m[0] ? (string) (int) $value : $GLOBALS['pdo']->quote( (string) $value );
			},
			$query
		);
	}
	public function query( $sql ) {
		$this->queries[] = $sql;
		if ( preg_match( '/^DELETE FROM (\S+) WHERE (.+) LIMIT (\d+)$/s', trim( $sql ), $m ) ) {
			$sql = "DELETE FROM {$m[1]} WHERE id IN (SELECT id FROM {$m[1]} WHERE {$m[2]} LIMIT {$m[3]})";
		}
		return $GLOBALS['pdo']->exec( $sql );
	}
};

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

function seed( $count, $days_ago ) {
	$at   = gmdate( 'Y-m-d H:i:s', time() - $days_ago * DAY_IN_SECONDS );
	$stmt = $GLOBALS['pdo']->prepare( 'INSERT INTO wp_wpmcp_log (created_at, summary) VALUES (?, ?)' );
	$GLOBALS['pdo']->beginTransaction();
	for ( $i = 0; $i < $count; $i++ ) {
		$stmt->execute( array( $at, 'x' ) );
	}
	$GLOBALS['pdo']->commit();
}

function rows() {
	return (int) $GLOBALS['pdo']->query( 'SELECT COUNT(*) FROM wp_wpmcp_log' )->fetchColumn();
}

echo "\n\033[1mAlte Eintraege werden geloescht\033[0m\n";

seed( 12000, 120 );
seed( 30, 10 );

check( function_exists( 'wpmcp_prune_log' ), 'wpmcp_prune_log() gibt es' );
$deleted = function_exists( 'wpmcp_prune_log' ) ? wpmcp_prune_log() : 0;
check( 12000 === $deleted, 'alle Eintraege aelter als 90 Tage sind weg', $deleted . ' geloescht' );
check( 30 === rows(), 'die juengeren bleiben', rows() . ' uebrig' );
$deletes = array_filter( $GLOBALS['wpdb']->queries, function ( $q ) { return 0 === strpos( trim( $q ), 'DELETE' ); } );
check( count( $deletes ) >= 3, 'in Portionen, nicht in einer Abfrage', count( $deletes ) . ' DELETE' );
check( array() === array_filter( $deletes, function ( $q ) { return false === strpos( $q, 'LIMIT 5000' ); } ), 'zu hoechstens 5000 Zeilen' );
check( array() === array_filter( $deletes, function ( $q ) { return false === strpos( $q, 'created_at <' ); } ), 'nach dem Zeitpunkt (der Index auf created_at)' );

echo "\n\033[1mDie Einstellung\033[0m\n";

$GLOBALS['options']['wpmcp_log_retention_days'] = 0;
seed( 5, 4000 );
$GLOBALS['wpdb']->queries = array();
check( function_exists( 'wpmcp_prune_log' ) && 0 === wpmcp_prune_log() && 35 === rows(), '0 heisst: fuer immer behalten' );
check( array() === $GLOBALS['wpdb']->queries, 'ohne eine Abfrage' );

$GLOBALS['options']['wpmcp_log_retention_days'] = 3650;
add_filter( 'wpmcp_log_retention_days', function ( $days ) { return 30; } );
check( 30 === wpmcp_log_retention_days(), 'ein Filter kann die Zahl ueberschreiben' );
function_exists( 'wpmcp_prune_log' ) && wpmcp_prune_log();
check( 30 === rows(), 'und das Loeschen richtet sich danach', rows() . ' uebrig' );
$GLOBALS['filters'] = array();

check( function_exists( 'wpmcp_sanitize_retention_days' ), 'wpmcp_sanitize_retention_days() gibt es' );
if ( function_exists( 'wpmcp_sanitize_retention_days' ) ) {
	check( 30 === wpmcp_sanitize_retention_days( '30' ), 'eine Zahl wird gespeichert' );
	check( 3650 === wpmcp_sanitize_retention_days( 99999 ), 'hoechstens 3650 Tage (zehn Jahre)' );
	check( 15 === wpmcp_sanitize_retention_days( '-15' ), 'ein Minus wird ignoriert (absint)' );
	check( 0 === wpmcp_sanitize_retention_days( 'abc' ), 'Unsinn wird 0' );
}

echo "\n\033[1mDer taegliche Lauf\033[0m\n";

check( in_array( 'wpmcp_prune_log', $GLOBALS['actions']['wpmcp_prune_log'] ?? array(), true ), 'das Ereignis wpmcp_prune_log loescht' );

wpmcp_upgrade();
check( 'daily' === ( $GLOBALS['scheduled']['wpmcp_prune_log']['recurrence'] ?? null ), 'Aktivierung und Update planen ihn taeglich ein' );

$first = $GLOBALS['scheduled']['wpmcp_prune_log']['time'] ?? null;
wpmcp_upgrade();
check( $first === ( $GLOBALS['scheduled']['wpmcp_prune_log']['time'] ?? null ), 'ein zweiter Aufruf plant nichts doppelt' );

unset( $GLOBALS['scheduled']['wpmcp_prune_log'] );
check( in_array( 'wpmcp_schedule_log_pruning', $GLOBALS['actions']['admin_init'] ?? array(), true ), 'admin_init prueft, ob er eingeplant ist' );
function_exists( 'wpmcp_schedule_log_pruning' ) && wpmcp_schedule_log_pruning();
check( isset( $GLOBALS['scheduled']['wpmcp_prune_log'] ), 'und plant ihn neu ein, wenn er fehlt', 'z.B. nach einem Cron-Aufraeumer' );

wpmcp_deactivate();
check( ! isset( $GLOBALS['scheduled']['wpmcp_prune_log'] ), 'Deaktivieren meldet ihn ab' );

echo "\n";
if ( 0 === $fail ) {
	echo "\033[32mAufbewahrung des Protokolls in Ordnung.\033[0m\n";
	exit( 0 );
}
echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
exit( 1 );
