<?php
/**
 * Open for an afternoon, not for good.
 *
 * The wide settings exist because they are dangerous, and what happens in
 * practice is that someone opens them for an afternoon of work and never
 * closes them again. The site then sits permanently on the widest setting
 * — precisely what the settings were there to prevent.
 *
 * A session inverts that: the closing is not something anyone has to
 * remember, it is a timestamp. These tests are about the closing, because
 * the opening is the easy half.
 *
 * Run: php tests/work-session.php
 *
 * @package wp-mcp-connector-plus
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['options'] = array();
$GLOBALS['logged']  = array();
$GLOBALS['synced']  = 0;

function get_option( $n, $d = false ) { return $GLOBALS['options'][ $n ] ?? $d; }
function update_option( $n, $v, $autoload = null ) { $GLOBALS['options'][ $n ] = $v; return true; }
function delete_option( $n ) { unset( $GLOBALS['options'][ $n ] ); return true; }
function add_action( ...$a ) { return true; }
function add_filter( ...$a ) { return true; }
function apply_filters( $t, $v ) { return $v; }
function __( $t, $d = null ) { return $t; }
function get_role( $r ) { return null; }
function is_multisite() { return false; }
function post_type_exists( $t ) { return true; }
function get_post_types( $a = array(), $o = 'names' ) {
	$all = array( 'gp_elements', 'wp_template', 'acf-field-group', 'product', 'shop_order', 'shop_order_refund', 'wc_subscription', 'revision', 'attachment' );
	if ( 'names' === $o ) { return $all; }
	$out = array();
	foreach ( $all as $n ) { $out[ $n ] = (object) array( 'name' => $n, 'public' => in_array( $n, array( 'product' ), true ), 'labels' => (object) array( 'name' => $n ) ); }
	return $out;
}
function get_post_type_object( $t ) { return null; }
function human_time_diff( $a, $b = 0 ) { return (int) ( ( $b - $a ) / 60 ) . ' Minuten'; }
function wpmcp_allowed_post_types() { return array( 'page' ); }
// sync_role_capabilities falls through to this when there is no role, so
// it is where a "the capabilities were pulled along" check can sit.
function wpmcp_register_role() { ++$GLOBALS['synced']; }
function wpmcp_is_ai_user( $u ) { return true; }
function wpmcp_log( $a, $args ) { $GLOBALS['logged'][] = $args['summary'] ?? ''; }

const WPMCP_CAP  = 'wpmcp_access';
const WPMCP_ROLE = 'wpmcp_ai_editor';

require_once dirname( __DIR__ ) . '/includes/access.php';

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

echo "\n\033[1mGeschlossen ist der Normalzustand\033[0m\n";

$GLOBALS['options'] = array( 'wpmcp_access_level' => 'draft', 'wpmcp_dynamic_data' => 'blocked' );

check( ! wpmcp_work_session_active(), 'ohne Sitzung laeuft keine' );
check( 'draft' === wpmcp_access_level(), 'die gespeicherte Stufe gilt' );
check( ! wpmcp_dynamic_data_allowed(), 'Dynamic Data bleibt gesperrt' );
check( 'read' === wpmcp_pattern_access(), 'Muster bleiben lesend' );

echo "\n\033[1mWaehrend sie laeuft\033[0m\n";

wpmcp_start_work_session( 4 );

check( wpmcp_work_session_active(), 'die Sitzung laeuft' );
check( 'full' === wpmcp_access_level(), 'die Stufe steht auf voll' );
check( wpmcp_dynamic_data_allowed(), 'Dynamic Data ist offen' );
check( 'write' === wpmcp_pattern_access(), 'Muster sind schreibbar' );
check( 1 === $GLOBALS['synced'], 'die Rollenrechte ziehen sofort nach', 'sonst ist die zweite Absicherung noch eng' );
check( (bool) preg_grep( '/opened for 4 hour/', $GLOBALS['logged'] ), 'und es steht im Protokoll' );

// The settings themselves are untouched — a session is a window, not a save.
check( 'draft' === get_option( 'wpmcp_access_level' ), 'die gespeicherte Einstellung bleibt, wie sie war' );

echo "\n\033[1mSie schliesst sich selbst\033[0m\n";

// Wind the clock past the end.
$GLOBALS['options']['wpmcp_work_session_until'] = time() - 60;

check( ! wpmcp_work_session_active(), 'nach Ablauf laeuft sie nicht mehr' );
check( 'draft' === wpmcp_access_level(), 'die Stufe faellt von selbst zurueck' );
check( ! wpmcp_dynamic_data_allowed(), 'Dynamic Data ebenfalls' );
check( 'read' === wpmcp_pattern_access(), 'und die Muster' );

// The level drops on its own; the stored capabilities do not.
$GLOBALS['synced'] = 0;
wpmcp_close_expired_work_session();
check( 1 === $GLOBALS['synced'], 'das Aufraeumen zieht die Rechte nach' );
check( ! isset( $GLOBALS['options']['wpmcp_work_session_until'] ), 'und raeumt den Eintrag weg' );
check( (bool) preg_grep( '/expired/', $GLOBALS['logged'] ), 'mit Vermerk im Protokoll' );

$GLOBALS['synced'] = 0;
wpmcp_close_expired_work_session();
check( 0 === $GLOBALS['synced'], 'ein zweiter Durchlauf tut nichts mehr', 'sonst schreibt jeder Request die Rolle neu' );

echo "\n\033[1mVon Hand schliessen\033[0m\n";

wpmcp_start_work_session( 8 );
check( wpmcp_work_session_active(), 'wieder geoeffnet' );
wpmcp_end_work_session();
check( ! wpmcp_work_session_active(), 'und sofort geschlossen' );
check( 'draft' === wpmcp_access_level(), 'alles zurueck auf die Einstellung' );

echo "\n\033[1mWas eine Sitzung nicht kann\033[0m\n";

wpmcp_start_work_session( 8 );

// Publishing is the one line no setting has ever reached.
$forbidden = array( 'publish_posts', 'publish_pages', 'delete_posts', 'upload_files', 'manage_options' );
$granted   = array_keys( wpmcp_level_capabilities( wpmcp_access_level() ) );
check(
	! array_intersect( $forbidden, $granted ),
	'sie vergibt kein Veroeffentlichungs-, Loesch- oder Upload-Recht',
	'vergeben: ' . implode( ', ', array_intersect( $forbidden, $granted ) )
);

// The building blocks come along: an hour of work must not start with
// thirty ticks. Somebody else's orders do not.
$GLOBALS['options']['wpmcp_extra_post_types'] = array();
$in_scope = wpmcp_extra_post_types();

check( in_array( 'gp_elements', $in_scope, true ), 'sie oeffnet die Bausteine der Seite' );
check( in_array( 'wp_template', $in_scope, true ), 'auch die Templates' );
check( in_array( 'acf-field-group', $in_scope, true ), 'und die Feldgruppen' );

check( ! in_array( 'shop_order', $in_scope, true ), 'aber keine Bestellungen', 'kein Zeitfenster macht fremde Adressen zum Nebeneffekt' );
check( ! in_array( 'shop_order_refund', $in_scope, true ), 'keine Erstattungen' );
check( ! in_array( 'wc_subscription', $in_scope, true ), 'keine Abos' );
check( ! in_array( 'revision', $in_scope, true ), 'und nichts ohne Blockbaum' );

// A tick made by hand was a decision and survives the window either way.
$GLOBALS['options']['wpmcp_extra_post_types'] = array( 'shop_order' );
check(
	in_array( 'shop_order', wpmcp_extra_post_types(), true ),
	'ein von Hand gesetzter Haken zaehlt weiterhin',
	'die Sitzung setzt ihn nicht, nimmt ihn aber auch niemandem weg'
);

// And he is gone again the moment the window closes.
$GLOBALS['options']['wpmcp_extra_post_types'] = array();
$GLOBALS['options']['wpmcp_work_session_until'] = time() - 60;
check( array() === wpmcp_extra_post_types(), 'nach Ablauf ist die Erweiterung weg' );
$GLOBALS['options']['wpmcp_work_session_until'] = time() + 3600;

// An unreasonable window falls back to the shortest.
$GLOBALS['options'] = array();
wpmcp_start_work_session( 9999 );
check(
	wpmcp_work_session_expires() <= time() + 3600 + 5,
	'ein erfundenes Fenster wird zur kuerzesten Dauer',
	'sonst waere "offen fuer immer" nur ein anderer Knopf'
);

echo "\n";
if ( 0 === $fail ) {
	echo "\033[32mArbeitssitzung in Ordnung.\033[0m\n";
	exit( 0 );
}
echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
exit( 1 );
