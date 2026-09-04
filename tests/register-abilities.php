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
function is_admin() { return false; }
function wp_doing_cron() { return false; }
function __( $t, $d = null ) { return $t; }
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

// A site upgrading from an older version has no such option yet. Saving it
// the first time is an add_option, not an update_option — the hook that
// only listened for updates never ran, and the switch stayed decorative.
$GLOBALS['role']                          = new StubRole();
$GLOBALS['options']['wpmcp_access_level'] = 'draft';
wpmcp_sync_role_capabilities();
check(
	! isset( $GLOBALS['role']->capabilities['edit_published_pages'] ),
	'Entwurfsstufe: kein Recht auf Veroeffentlichtes'
);

$GLOBALS['options']['wpmcp_access_level'] = 'full';
do_action( 'add_option_wpmcp_access_level', 'wpmcp_access_level', 'full' );
check(
	isset( $GLOBALS['role']->capabilities['edit_published_pages'] ),
	'Wechsel auf Vollstufe vergibt das Recht auch beim ERSTEN Speichern',
	'add_option-Hook greift nicht — genau der gemeldete Fehler'
);

$GLOBALS['options']['wpmcp_access_level'] = 'draft';
do_action( 'update_option_wpmcp_access_level', 'full', 'draft', 'wpmcp_access_level' );
check(
	! isset( $GLOBALS['role']->capabilities['edit_published_pages'] ),
	'Zurueckschalten entzieht das Recht wieder'
);

// The reconciler must repair drift from any other source.
$GLOBALS['options']['wpmcp_access_level'] = 'full';
$GLOBALS['role']                          = new StubRole();
check( false === wpmcp_role_caps_match(), 'Abweichung wird erkannt' );
wpmcp_reconcile_role();
check( wpmcp_role_caps_match(), 'Abgleich stellt die Rechte wieder her' );

check(
	! isset( $GLOBALS['role']->capabilities['publish_pages'] ),
	'Auch auf der Vollstufe kein Veroeffentlichungsrecht'
);

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

echo "\n";
if ( 0 === $fail ) {
	echo "\033[32mRegistrierung in Ordnung.\033[0m\n";
	exit( 0 );
}
echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
exit( 1 );
