<?php
/**
 * Load the plugin and fire the ability hooks in the order WordPress fires
 * them, then check that all eight abilities actually register.
 *
 * This exists because two separate bugs made every registration fail
 * silently — the category hook was added from a file loaded too late, and
 * later the category slug drifted apart from the one the abilities named.
 * Both produced the same symptom: an MCP server that connects and offers
 * nothing. Neither was visible without a live site until now.
 *
 * Run: php tests/register-abilities.php
 *
 * @package wp-mcp-connector-plus
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['options']    = array( 'wpmcp_access_level' => 'draft' );
$GLOBALS['hooks']      = array();
$GLOBALS['categories'] = array();
$GLOBALS['abilities']  = array();

// --- Minimal hook system, order-preserving ------------------------------

function add_action( $tag, $cb, $priority = 10, $args = 1 ) {
	$GLOBALS['hooks'][ $tag ][ $priority ][] = $cb;
	return true;
}
function add_filter( $tag, $cb, $priority = 10, $args = 1 ) {
	return add_action( $tag, $cb, $priority, $args );
}
function apply_filters( $tag, $value ) {
	foreach ( $GLOBALS['hooks'][ $tag ] ?? array() as $bucket ) {
		foreach ( $bucket as $cb ) {
			$value = $cb( $value );
		}
	}
	return $value;
}
function do_action( $tag, ...$args ) {
	$buckets = $GLOBALS['hooks'][ $tag ] ?? array();
	ksort( $buckets );
	foreach ( $buckets as $bucket ) {
		foreach ( $bucket as $cb ) {
			$cb( ...$args );
		}
	}
}

// --- The Abilities API, behaving like the real one ----------------------

function wp_register_ability_category( $slug, $args = array() ) {
	$GLOBALS['categories'][ $slug ] = $args;
	return true;
}

function wp_register_ability( $name, $args = array() ) {
	// The real API rejects an ability naming a category that does not exist.
	$category = $args['category'] ?? null;
	if ( ! $category || ! isset( $GLOBALS['categories'][ $category ] ) ) {
		$GLOBALS['rejected'][ $name ] = $category;
		return false;
	}
	$GLOBALS['abilities'][ $name ] = $args;
	return true;
}

function wp_get_ability( $name ) {
	return $GLOBALS['abilities'][ $name ] ?? null;
}

// --- Everything else the plugin touches while loading -------------------

function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function register_activation_hook( ...$a ) { return true; }
function register_deactivation_hook( ...$a ) { return true; }
function is_admin() { return false; }
function wp_doing_cron() { return false; }
function __( $t, $d = null ) { $GLOBALS['translated'][] = $t; return $t; }
function esc_html__( $t, $d = null ) { return $t; }
function esc_html( $t ) { return $t; }
function remove_role( $r ) { return true; }
function add_role( ...$a ) { return true; }
function current_user_can( $c ) { return true; }
function is_user_logged_in() { return true; }
function get_userdata( $id ) { return null; }
function wp_salt( $s = 'auth' ) { return 'test-salt'; }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function get_post_type( $id ) { return 'page'; }
function get_option( $n, $d = false ) {
	return $GLOBALS['options'][ $n ] ?? $d;
}
class StubRole {
	public $capabilities = array();
	public function add_cap( $c, $g = true ) { $this->capabilities[ $c ] = $g; }
	public function remove_cap( $c ) { unset( $this->capabilities[ $c ] ); }
}
$GLOBALS['role'] = new StubRole();
function get_role( $r ) { return $GLOBALS['role']; }
function post_type_exists( $t ) { return in_array( $t, array( 'page', 'post', 'gp_elements' ), true ); }
function get_post_types( $args = array(), $output = 'names' ) {
	$types = array(
		'post' => (object) array( 'name' => 'post', 'public' => true, 'labels' => (object) array( 'name' => 'Beitraege' ) ),
		'page' => (object) array( 'name' => 'page', 'public' => true, 'labels' => (object) array( 'name' => 'Seiten' ) ),
		'gp_elements' => (object) array( 'name' => 'gp_elements', 'public' => false, 'labels' => (object) array( 'name' => 'Elements' ) ),
	);
	if ( ! empty( $args['public'] ) ) {
		$types = array_filter( $types, function ( $t ) { return $t->public; } );
	}
	return 'names' === $output ? array_keys( $types ) : $types;
}
function get_post_type_object( $t ) { $all = get_post_types( array(), 'objects' ); return $all[ $t ] ?? null; }
function apply_filters_deprecated() {}
class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }

// --- Load the plugin ----------------------------------------------------


require_once dirname( __DIR__ ) . '/wp-mcp-connector-plus.php';

// --- Fire the hooks in WordPress order ----------------------------------

do_action( 'wp_abilities_api_categories_init' );
do_action( 'wp_abilities_api_init' );

// --- Check --------------------------------------------------------------

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

echo "\n\033[1mAbility-Registrierung\033[0m\n";

check(
	! empty( $GLOBALS['categories'] ),
	'Kategorie ist registriert: ' . implode( ', ', array_keys( $GLOBALS['categories'] ) ),
	'Keine Kategorie registriert — jede Ability wird abgelehnt.'
);

$expected = wpmcp_ability_names();
$got      = array_keys( $GLOBALS['abilities'] );

check(
	count( $got ) === count( $expected ),
	sprintf( '%d von %d Abilities registriert', count( $got ), count( $expected ) ),
	empty( $GLOBALS['rejected'] )
		? ''
		: 'abgelehnt wegen unbekannter Kategorie: ' . implode(
			', ',
			array_map(
				function ( $n, $c ) {
					return "{$n} (Kategorie: " . var_export( $c, true ) . ')';
				},
				array_keys( $GLOBALS['rejected'] ),
				$GLOBALS['rejected']
			)
		)
);

foreach ( $expected as $name ) {
	check( isset( $GLOBALS['abilities'][ $name ] ), "registriert: {$name}" );
}

echo "\n\033[1mSchema jeder Ability\033[0m\n";

foreach ( $GLOBALS['abilities'] as $name => $args ) {
	$problems = array();
	foreach ( array( 'label', 'description', 'category', 'execute_callback', 'permission_callback' ) as $key ) {
		if ( empty( $args[ $key ] ) ) {
			$problems[] = "{$key} fehlt";
		}
	}
	if ( ! empty( $args['permission_callback'] ) && ! is_callable( $args['permission_callback'] ) ) {
		$problems[] = 'permission_callback nicht aufrufbar';
	}
	if ( ! empty( $args['execute_callback'] ) && ! is_callable( $args['execute_callback'] ) ) {
		$problems[] = 'execute_callback nicht aufrufbar';
	}
	check( empty( $problems ), "vollständig: {$name}", implode( ', ', $problems ) );
}

echo "\n\033[1mAnnotationen und Labels\033[0m\n";

// MCP reads a missing destructiveHint as true and a missing openWorldHint
// as true. Four abilities had no meta at all, so content-create (adds a
// draft, nothing else) was announced as destructive.
$GLOBALS['options']['wpmcp_access_level'] = 'draft';
$GLOBALS['abilities']                     = array();
$GLOBALS['translated']                    = array();
wpmcp_register_abilities();

$writes = wpmcp_write_ability_names();
foreach ( $GLOBALS['abilities'] as $name => $args ) {
	$a       = $args['meta']['annotations'] ?? array();
	$missing = array();
	foreach ( array( 'readonly', 'destructive', 'idempotent', 'openWorldHint' ) as $hint ) {
		if ( ! isset( $a[ $hint ] ) || ! is_bool( $a[ $hint ] ) ) {
			$missing[] = $hint;
		}
	}
	check( empty( $missing ) && true === ( $args['meta']['show_in_rest'] ?? null ), "{$name}: alle Hinweise gesetzt, show_in_rest", 'fehlt: ' . implode( ', ', $missing ) );
	check( ( ! in_array( $name, $writes, true ) ) === ( $a['readonly'] ?? null ), "{$name}: readonly passt zu Lesen/Schreiben" );
	check( in_array( $args['label'], $GLOBALS['translated'], true ), "{$name}: Label '{$args['label']}' laeuft durch __()" );
}
check( false === $GLOBALS['abilities']['wpmcp/content-create']['meta']['annotations']['destructive'], 'content-create ist nicht destruktiv' );
check( true === $GLOBALS['abilities']['wpmcp/content-write']['meta']['annotations']['destructive'], 'content-write schon' );
check( true === $GLOBALS['abilities']['wpmcp/content-fetch-live']['meta']['annotations']['openWorldHint'], 'content-fetch-live verlaesst die Datenbank (openWorldHint)' );
check( false === $GLOBALS['abilities']['wpmcp/content-read']['meta']['annotations']['openWorldHint'], 'content-read nicht' );

echo "\n\033[1mFehlercodes an der Grenze\033[0m\n";

// The adapter passes a WP_Error to the client as its message only. The
// code has to be in the message, once.
$describe = $GLOBALS['abilities']['wpmcp/blocks-describe']['execute_callback'];
$err      = $describe( array() );
check( is_wp_error( $err ) && 0 === strpos( $err->get_error_message(), '[wpmcp_bad_request] ' ), 'die Meldung beginnt mit [code]', is_wp_error( $err ) ? $err->get_error_message() : 'kein Fehler' );
check( is_wp_error( $err ) && 'wpmcp_bad_request' === $err->get_error_code(), 'der Code selbst bleibt' );
$media = $GLOBALS['abilities']['wpmcp/media-read']['execute_callback']( array() );
check( is_wp_error( $media ) && 0 === strpos( $media->get_error_message(), '[wpmcp_bad_request] ' ), 'media-read ohne id und post_id: Anfragefehler mit Code' );
$twice = wpmcp_contract_result( $err );
check( 1 === substr_count( $twice->get_error_message(), '[wpmcp_bad_request]' ), 'zweimal durch die Grenze: ein Praefix' );
$foreign = wpmcp_contract_result( new WP_Error( 'rest_forbidden', 'Nope.' ) );
check( 'Nope.' === $foreign->get_error_message(), 'fremde Codes bleiben unangetastet' );

$refused = wpmcp_contract_result( array( 'ok' => false, 'errors' => array( 'x' ) ) );
check( 'wpmcp_validation_failed' === ( $refused['code'] ?? null ), 'eine Ablehnung mit ok:false bekommt einen Code' );
check( array( 'ok', 'code', 'errors' ) === array_keys( $refused ), 'gleich hinter ok' );
$own = wpmcp_contract_result( array( 'ok' => false, 'code' => 'wpmcp_batch_incomplete' ) );
check( 'wpmcp_batch_incomplete' === $own['code'], 'ein eigener Code bleibt' );
check( ! isset( wpmcp_contract_result( array( 'ok' => true ) )['code'] ), 'ein Erfolg bekommt keinen' );

echo "\n\033[1msite-info sagt, was gilt\033[0m\n";

function get_bloginfo( $s ) { return 'Test'; }
function wp_get_theme() {
	return new class() {
		public function get( $k ) { return 'x'; }
	};
}
function get_plugins() { return array(); }
class WP_Block_Type_Registry {
	public static function get_instance() { return new self(); }
	public function get_all_registered() { return array(); }
}
function is_multisite() { return false; }
$GLOBALS['wp_version'] = '6.9';

$info = wpmcp_site_info();
check( 1 === ( $info['contractVersion'] ?? null ), 'contractVersion ist 1 (eine Zahl)' );
check( in_array( 'content-create', $info['capabilities']['write'], true ) && in_array( 'media-update', $info['capabilities']['write'], true ), 'content-create und media-update stehen unter write', 'standen unter read' );
check( ! array_intersect( array( 'content-batch', 'content-create', 'media-update', 'media-upload' ), $info['capabilities']['read'] ), 'und kein Schreibwerkzeug unter read' );
check( false === strpos( $info['capabilities']['explains'], 'never possible' ), 'ohne Sitzung: kein "Publishing is never possible"' );
check( false !== strpos( $info['capabilities']['explains'], 'work session' ), 'sondern der Hinweis auf die Arbeitssitzung' );

$GLOBALS['options']['wpmcp_work_session_until'] = time() + 600;
$info = wpmcp_site_info();
check( false !== strpos( $info['capabilities']['explains'], 'work until the work session ends' ), 'in einer Sitzung: Veroeffentlichen geht', $info['capabilities']['explains'] );
check( true === $info['capabilities']['workSession']['active'], 'und workSession sagt dasselbe' );

$GLOBALS['options']['wpmcp_access_level'] = 'read';
$info = wpmcp_site_info();
check( array() === $info['capabilities']['write'], 'Lesestufe mit Sitzung: keine Schreibwerkzeuge' );
check( false !== strpos( $info['capabilities']['explains'], 'Read only' ), 'und es heisst die eingestellte Stufe', $info['capabilities']['explains'] );
unset( $GLOBALS['options']['wpmcp_work_session_until'] );
$GLOBALS['options']['wpmcp_access_level'] = 'draft';

// --- The promise the access levels make ---------------------------------
echo "\n\033[1mZugriffsstufen\033[0m\n";

$write_tools = array( 'wpmcp/content-write', 'wpmcp/content-duplicate', 'wpmcp/content-restore' );

$GLOBALS['options']['wpmcp_access_level'] = 'read';
$read_names = wpmcp_ability_names();
check(
	! array_intersect( $write_tools, $read_names ),
	'Lesestufe bietet keine Schreib-Werkzeuge an',
	'gefunden: ' . implode( ', ', array_intersect( $write_tools, $read_names ) )
);
check( in_array( 'wpmcp/content-read', $read_names, true ), 'Lesestufe kann weiterhin lesen' );
check( false === wpmcp_can_write(), 'Lesestufe meldet: kein Schreibzugriff' );
check( false === wpmcp_live_edit_enabled(), 'Lesestufe erlaubt kein Live-Edit' );

$GLOBALS['options']['wpmcp_access_level'] = 'draft';
check( count( array_intersect( $write_tools, wpmcp_ability_names() ) ) === 3, 'Entwurfsstufe bietet die Schreib-Werkzeuge an' );
check( in_array( 'wpmcp/content-revisions', $read_names, true ), 'Revisionen lesen geht auch ohne Schreibrecht' );
check( false === wpmcp_live_edit_enabled(), 'Entwurfsstufe erlaubt kein Live-Edit' );

$GLOBALS['options']['wpmcp_access_level'] = 'full';
check( true === wpmcp_live_edit_enabled(), 'Vollstufe erlaubt Live-Edit' );

echo "\n\033[1mWas nicht erlaubt ist, existiert nicht\033[0m\n";

// wp-abilities/v1 runs whatever is registered, for anyone holding the
// marker capability. Filtering only the MCP list left the write abilities
// callable there at the read level.
function registered_at( $level, $session = false ) {
	$GLOBALS['options']['wpmcp_access_level'] = $level;
	if ( $session ) {
		$GLOBALS['options']['wpmcp_work_session_until'] = time() + 600;
	} else {
		unset( $GLOBALS['options']['wpmcp_work_session_until'] );
	}
	$GLOBALS['abilities'] = array();
	wpmcp_register_abilities();
	$names = array_keys( $GLOBALS['abilities'] );
	unset( $GLOBALS['options']['wpmcp_work_session_until'] );
	return $names;
}

$all_write = array( 'wpmcp/content-write', 'wpmcp/content-batch', 'wpmcp/content-create', 'wpmcp/content-duplicate', 'wpmcp/content-restore', 'wpmcp/media-update', 'wpmcp/media-upload' );

$names = registered_at( 'read' );
check(
	! array_intersect( $all_write, $names ),
	'Lesestufe: keine Schreib-Ability ist registriert',
	'registriert: ' . implode( ', ', array_intersect( $all_write, $names ) )
);
check( in_array( 'wpmcp/content-read', $names, true ), 'die Lese-Abilities schon' );

foreach ( array( 'read', 'draft', 'full' ) as $level ) {
	$GLOBALS['options']['wpmcp_access_level'] = $level;
	$expected_names = wpmcp_ability_names();
	$names          = registered_at( $level );
	sort( $names );
	sort( $expected_names );
	check( $expected_names === $names, "Stufe '{$level}': registriert ist genau, was die Stufe anbietet" );
}

// The tool list is fixed when a client connects and the server announces
// no changes. A tool that came and went with the session clock was
// invisible to an agent connected before the session, and a dead name to
// one connected during it. So the list follows the level the owner set,
// and nothing that runs out by itself.
foreach ( array( 'read', 'draft', 'full' ) as $level ) {
	$without = registered_at( $level );
	$with    = registered_at( $level, true );
	sort( $without );
	sort( $with );
	check( $without === $with, "Stufe '{$level}': eine Arbeitssitzung aendert die Werkzeugliste nicht", 'mit Sitzung: ' . implode( ', ', array_diff( $with, $without ) ) );
}
check( in_array( 'wpmcp/media-upload', registered_at( 'draft' ), true ), 'der Upload ist auf einer Schreibstufe immer da (und lehnt ohne Sitzung selbst ab)' );
check( ! in_array( 'wpmcp/media-upload', registered_at( 'read', true ), true ), 'auf der Lesestufe auch in einer Sitzung nicht' );

$GLOBALS['options']['wpmcp_access_level'] = 'draft';
$GLOBALS['abilities']                     = array();
wpmcp_register_abilities();

echo "\n\033[1mHarte Grenzen (auf jeder Stufe)\033[0m\n";

$forbidden = array( 'publish_posts', 'publish_pages', 'delete_posts', 'delete_pages', 'upload_files', 'manage_options' );
foreach ( array( 'read', 'draft', 'full' ) as $level ) {
	$caps  = array_keys( wpmcp_level_capabilities( $level ) );
	$found = array_intersect( $forbidden, $caps );
	check( empty( $found ), "Stufe '{$level}' vergibt keine gefaehrlichen Rechte", 'vergeben: ' . implode( ', ', $found ) );
}

check( ! in_array( 'edit_posts', array_keys( wpmcp_level_capabilities( 'read' ) ), true ), 'Lesestufe hat gar kein Bearbeitungsrecht' );
check( in_array( 'edit_published_pages', array_keys( wpmcp_level_capabilities( 'full' ) ), true ), 'Nur die Vollstufe darf Veroeffentlichtes bearbeiten' );
check( ! in_array( 'edit_published_pages', array_keys( wpmcp_level_capabilities( 'draft' ) ), true ), 'Entwurfsstufe darf Veroeffentlichtes nicht bearbeiten' );

$GLOBALS['options']['wpmcp_access_level'] = 'draft';

// --- The bug an independent test found ----------------------------------
echo "\n\033[1mRechte folgen der Stufe\033[0m\n";

// What the agent holds, worked out the way WordPress asks: everything
// stored on its account, then the user_has_cap filter.
function agent_caps( array $stored, array $roles = array( 'wpmcp_ai_editor' ) ) {
	$user = (object) array( 'ID' => 7, 'roles' => $roles );
	return array_keys( array_filter( wpmcp_agent_capabilities( $stored, array(), array(), $user ) ) );
}

// Since 0.19 the role stores only the read set at every level; the level
// is applied per check, so neither a forgotten hook nor an expired session
// can leave a capability behind.
$GLOBALS['role']                          = new StubRole();
$GLOBALS['options']['wpmcp_access_level'] = 'full';
wpmcp_sync_role_capabilities();
check(
	array( 'read', 'wpmcp_access' ) == array_keys( array_filter( $GLOBALS['role']->capabilities ) ),
	'die Rolle speichert auf jeder Stufe nur Lesen und das Marker-Recht',
	'gespeichert: ' . implode( ', ', array_keys( $GLOBALS['role']->capabilities ) )
);

$GLOBALS['options']['wpmcp_access_level'] = 'draft';
check( ! in_array( 'edit_published_pages', agent_caps( $GLOBALS['role']->capabilities ), true ), 'Entwurfsstufe: kein Recht auf Veroeffentlichtes' );
check( in_array( 'edit_others_pages', agent_caps( $GLOBALS['role']->capabilities ), true ), 'aber auf fremde Entwuerfe' );

// A site upgrading from an older version has no such option yet. Saving it
// the first time is an add_option, not an update_option; the hook that
// only listened for updates never ran, and the switch stayed decorative.
// Now no hook has to run at all.
$GLOBALS['options']['wpmcp_access_level'] = 'full';
check(
	in_array( 'edit_published_pages', agent_caps( $GLOBALS['role']->capabilities ), true ),
	'Wechsel auf Vollstufe gilt sofort, ohne dass ein Hook laufen muss',
	'genau der fruehere Fehler: Schalter umgelegt, Recht nicht da'
);

$GLOBALS['options']['wpmcp_access_level'] = 'read';
check( array( 'read', 'wpmcp_access' ) == agent_caps( $GLOBALS['role']->capabilities ), 'Lesestufe: kein Bearbeitungsrecht' );

// What an older version left on the role, or a role editor put there,
// does not count for an account holding only the agent role.
$stale = array( 'read' => true, 'wpmcp_access' => true, 'edit_pages' => true, 'edit_published_pages' => true, 'edit_published_posts' => true );
$GLOBALS['options']['wpmcp_access_level'] = 'draft';
check( ! in_array( 'edit_published_pages', agent_caps( $stale ), true ), 'gespeicherte Altrechte gelten nicht mehr', 'sonst bleibt die Rolle nach einem Update weit offen' );
$GLOBALS['options']['wpmcp_access_level'] = 'read';
check( ! in_array( 'edit_pages', agent_caps( $stale ), true ), 'auch nicht auf der Lesestufe' );

// An expired session: the timestamp alone decides, nobody has to tidy up.
$GLOBALS['options']['wpmcp_access_level']       = 'draft';
$GLOBALS['options']['wpmcp_work_session_until'] = time() + 600;
check( in_array( 'edit_published_pages', agent_caps( $GLOBALS['role']->capabilities ), true ), 'in einer Sitzung: Veroeffentlichtes bearbeitbar' );
$GLOBALS['options']['wpmcp_work_session_until'] = time() - 1;
check(
	! in_array( 'edit_published_pages', agent_caps( $GLOBALS['role']->capabilities ), true ),
	'abgelaufene Sitzung: sofort wieder weg',
	'auch wenn niemand wp-admin oeffnet und nichts aufraeumt'
);
unset( $GLOBALS['options']['wpmcp_work_session_until'] );

// A second role on the same account keeps what it grants.
check(
	in_array( 'edit_published_pages', agent_caps( $stale, array( 'wpmcp_ai_editor', 'editor' ) ), true ),
	'eine zweite Rolle behaelt ihre eigenen Rechte'
);
check(
	array( 'edit_posts' ) === agent_caps( array( 'edit_posts' => true ), array( 'editor' ) ),
	'Konten ohne Agent-Rolle bleiben unberuehrt'
);

// The reconciler brings an older role back to the read set.
$GLOBALS['options']['wpmcp_access_level'] = 'full';
$GLOBALS['role']                          = new StubRole();
foreach ( $stale as $cap => $grant ) {
	$GLOBALS['role']->add_cap( $cap );
}
check( false === wpmcp_role_caps_match(), 'Altrechte auf der Rolle werden erkannt' );
wpmcp_reconcile_role();
check( wpmcp_role_caps_match(), 'Abgleich setzt die Rolle auf das Lese-Set zurueck' );
check( ! isset( $GLOBALS['role']->capabilities['edit_published_pages'] ), 'ohne edit_published_pages' );

foreach ( array( 'read', 'draft', 'full' ) as $level ) {
	$GLOBALS['options']['wpmcp_access_level'] = $level;
	check(
		! array_intersect( array( 'publish_pages', 'publish_posts', 'upload_files', 'delete_pages' ), agent_caps( $GLOBALS['role']->capabilities ) ),
		"Stufe '{$level}': kein Veroeffentlichungs-, Upload- oder Loeschrecht"
	);
}

echo "\n\033[1mDeaktivieren\033[0m\n";

$GLOBALS['wpdb'] = new class() {
	public $prefix = 'wp_';
	public function insert( ...$a ) { return 1; }
	public function suppress_errors( $s = true ) { return false; }
};
function current_time( ...$a ) { return '2026-10-01 12:00:00'; }
function get_current_user_id() { return 1; }
function update_option( $n, $v, $a = null ) { $GLOBALS['options'][ $n ] = $v; return true; }
function delete_option( $n ) { unset( $GLOBALS['options'][ $n ] ); return true; }
function wp_clear_scheduled_hook( $hook ) { $GLOBALS['cron_cleared'][] = $hook; return 0; }

$GLOBALS['options']['wpmcp_work_session_until'] = time() + 3600;
wpmcp_sync_role_capabilities();
wpmcp_deactivate();
check( ! wpmcp_work_session_active(), 'eine laufende Sitzung endet' );
check(
	array( 'read' ) === array_keys( array_filter( $GLOBALS['role']->capabilities ) ),
	'die Rolle behaelt nur Lesen',
	'ohne Plugin begrenzt nichts mehr den Zugang auf den Endpunkt'
);
wpmcp_reconcile_role();
check( wpmcp_role_caps_match(), 'der naechste Abgleich stellt sie wieder her' );

$GLOBALS['options']['wpmcp_access_level'] = 'draft';

echo "\n\033[1mZusaetzliche Post-Types\033[0m\n";

// Site-wide building blocks are not public, so they stay out until the
// site owner ticks them — a decision, because a change there lands on
// every page at once.
$GLOBALS['options']['wpmcp_extra_post_types'] = array();
check( ! in_array( 'gp_elements', wpmcp_allowed_post_types(), true ), 'ohne Haken bleibt gp_elements draussen' );
check( in_array( 'page', wpmcp_allowed_post_types(), true ), 'Seiten sind ohnehin drin' );

$GLOBALS['options']['wpmcp_extra_post_types'] = array( 'gp_elements' );
check( in_array( 'gp_elements', wpmcp_allowed_post_types(), true ), 'mit Haken kommt er dazu' );

$GLOBALS['options']['wpmcp_extra_post_types'] = array( 'gibt_es_nicht' );
check( ! in_array( 'gibt_es_nicht', wpmcp_allowed_post_types(), true ), 'ein deaktiviertes Plugin hinterlaesst keinen toten Eintrag' );

$GLOBALS['options']['wpmcp_extra_post_types'] = 'kaputt';
check( is_array( wpmcp_allowed_post_types() ), 'und eine kaputte Option wirft nichts um' );

$GLOBALS['options']['wpmcp_extra_post_types'] = array( 'gp_elements' );
$selectable = wpmcp_selectable_post_types();
check( isset( $selectable['gp_elements'] ), 'die Auswahlliste zeigt auch schon Gewaehltes', 'sonst laesst es sich nicht wieder abwaehlen' );
check( ! isset( $selectable['page'] ), 'aber nichts, was ohnehin drin ist' );

$GLOBALS['options']['wpmcp_extra_post_types'] = array();

echo "\n\033[1mPost-Types mit Kundendaten\033[0m\n";

// A shop lists thirty of these, and three or four are orders. Ticking one
// is not the same decision as ticking "Elements", and nobody reads thirty
// labels before clicking select-all.
$expected = array(
	'shop_order'         => true,
	'shop_order_refund'  => true,
	'wc_subscription'    => true,
	'flamingo_inbound'   => true,
	'user_request'       => true,
	'booking'            => true,
	'gp_elements'        => false,
	'wp_template'        => false,
	'acf-field'          => false,
	'product'            => false,
	'shop_coupon'        => false,
	'wpcf7_contact_form' => false,
);

foreach ( $expected as $slug => $want ) {
	check(
		wpmcp_post_type_holds_personal_data( $slug ) === $want,
		sprintf( '%s: %s', $slug, $want ? 'markiert' : 'nicht markiert' ),
		$want ? 'Kundendaten wuerden ungekennzeichnet freigegeben' : 'eine falsche Warnung stumpft die echten ab'
	);
}

echo "\n";
if ( 0 === $fail ) {
	echo "\033[32mRegistrierung in Ordnung.\033[0m\n";
	exit( 0 );
}
echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
exit( 1 );
