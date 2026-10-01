<?php
/**
 * The agent account reaches its endpoint and nothing else.
 *
 * An application password authenticates against the whole REST API and
 * against XML-RPC, not against one plugin's route. Until 0.19 the agent's
 * credential therefore also opened /wp/v2: its own profile, its own
 * application passwords, and every route any other plugin registers, none
 * of it behind the connector's checks or audit log.
 *
 * The other half is the reopening of application passwords: it used to
 * switch them on globally regardless of HTTPS, and switch them off for
 * every human on sites that had never disabled them.
 *
 * The hook system here honours priorities and passes every argument,
 * because the order of three filters on one hook is the whole point.
 *
 * Run: php tests/agent-fence.php
 *
 * @package wp-mcp-connector-plus
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['hooks'] = array();

function add_filter( $tag, $cb, $priority = 10, $args = 1 ) {
	$GLOBALS['hooks'][ $tag ][ $priority ][] = array( $cb, $args );
	return true;
}
function add_action( $tag, $cb, $priority = 10, $args = 1 ) {
	return add_filter( $tag, $cb, $priority, $args );
}
function apply_filters( $tag, $value, ...$rest ) {
	$buckets = $GLOBALS['hooks'][ $tag ] ?? array();
	ksort( $buckets );
	foreach ( $buckets as $bucket ) {
		foreach ( $bucket as list( $cb, $accepted ) ) {
			$value = $cb( ...array_slice( array_merge( array( $value ), $rest ), 0, $accepted ) );
		}
	}
	return $value;
}
function __( $t, $d = null ) { return $t; }
function __return_false() { return false; }
function is_wp_error( $t ) { return $t instanceof WP_Error; }

class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}

class WP_User {
	public $ID;
	public $roles;
	public $allcaps;
	public function __construct( $id, array $roles, array $allcaps = array() ) {
		$this->ID      = $id;
		$this->roles   = $roles;
		$this->allcaps = $allcaps;
	}
}

$GLOBALS['users'] = array(
	1 => new WP_User( 1, array( 'administrator' ) ),
	// An administrator given the marker capability for debugging.
	2 => new WP_User( 2, array( 'administrator' ), array( 'wpmcp_access' => true ) ),
	7 => new WP_User( 7, array( 'wpmcp_ai_editor' ), array( 'wpmcp_access' => true ) ),
);
$GLOBALS['current'] = 0;
$GLOBALS['ssl']     = true;
$GLOBALS['env']     = 'production';

function get_userdata( $id ) { return $GLOBALS['users'][ $id ] ?? false; }
function wp_get_current_user() { return $GLOBALS['users'][ $GLOBALS['current'] ] ?? new WP_User( 0, array() ); }
function is_user_logged_in() { return $GLOBALS['current'] > 0; }
function is_ssl() { return $GLOBALS['ssl']; }
function wp_get_environment_type() { return $GLOBALS['env']; }

// WordPress 6.9, as written in wp-includes/user.php.
function wp_is_application_passwords_supported() {
	return is_ssl() || 'local' === wp_get_environment_type();
}
function wp_is_application_passwords_available() {
	return apply_filters( 'wp_is_application_passwords_available', wp_is_application_passwords_supported() );
}
function wp_is_application_passwords_available_for_user( $user ) {
	if ( ! wp_is_application_passwords_available() ) {
		return false;
	}
	return apply_filters( 'wp_is_application_passwords_available_for_user', true, $user );
}

// The last step of map_meta_cap(), which every capability check passes.
function map_meta_cap_result( $cap, $user_id, ...$args ) {
	$caps = array( 'edit_user' === $cap && isset( $args[0] ) && (int) $args[0] === $user_id ? 'exist' : $cap );
	return apply_filters( 'map_meta_cap', $caps, $cap, $user_id, $args );
}

require_once dirname( __DIR__ ) . '/includes/auth.php';

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

/**
 * Run the REST authentication filter the way WP_REST_Server does, for a
 * request to $route by $user_id.
 */
function rest_request_as( $user_id, $route, $earlier = null ) {
	$GLOBALS['current'] = $user_id;
	$GLOBALS['wp']      = (object) array( 'query_vars' => array( 'rest_route' => $route ) );
	return apply_filters( 'rest_authentication_errors', $earlier );
}

echo "\n\033[1mDer Agent erreicht nur seinen Endpunkt\033[0m\n";

$out = rest_request_as( 7, '/wpmcp/v1/mcp', true );
check( true === $out, 'der MCP-Endpunkt bleibt offen', is_wp_error( $out ) ? $out->get_error_message() : '' );
check( true === rest_request_as( 7, '/wpmcp/v1/mcp/', true ), 'auch mit Schraegstrich am Ende' );
check( true === rest_request_as( 7, '/WPMCP/v1/MCP', true ), 'und in anderer Schreibweise, wie WordPress sie routet' );

$closed = array(
	'/wp/v2/users/me',
	'/wp/v2/users/7/application-passwords',
	'/wp/v2/pages/12',
	'/wp/v2/settings',
	'/wp-abilities/v1/abilities/wpmcp/content-write/run',
	'/mcp/mcp-adapter-default-server',
	'/batch/v1',
	'/wpmcp/v1/mcp-other',
	'/wpmcp/v1',
	'/',
	'',
);
foreach ( $closed as $route ) {
	$out = rest_request_as( 7, $route, true );
	check(
		is_wp_error( $out ) && 'wpmcp_rest_scope' === $out->get_error_code() && 403 === ( $out->get_error_data()['status'] ?? 0 ),
		sprintf( 'gesperrt mit 403: %s', '' === $route ? '(leer)' : $route )
	);
}

$out = rest_request_as( 7, '/wp/v2/users/me', true );
check(
	is_wp_error( $out ) && false !== strpos( $out->get_error_message(), '/wpmcp/v1/mcp' ),
	'die Meldung nennt den einen erlaubten Weg'
);

unset( $GLOBALS['wp'] );
$GLOBALS['current'] = 7;
$out = apply_filters( 'rest_authentication_errors', true );
check( is_wp_error( $out ), 'ohne erkennbare Route wird ebenfalls gesperrt', 'im Zweifel zu' );

echo "\n\033[1mAndere bleiben unberuehrt\033[0m\n";

check( true === rest_request_as( 1, '/wp/v2/users/me', true ), 'ein Administrator nutzt die REST-API wie immer' );
check(
	true === rest_request_as( 2, '/wp/v2/settings', true ),
	'auch einer mit dem Marker-Recht zum Debuggen',
	'die Sperre gilt der Agent-Rolle, nicht dem Recht'
);
check( null === rest_request_as( 0, '/wp/v2/posts', null ), 'anonyme Anfragen ebenso' );

$earlier = new WP_Error( 'incorrect_password', 'The provided password is an invalid application password.' );
check( $earlier === rest_request_as( 7, '/wp/v2/users/me', $earlier ), 'ein frueherer Fehler kommt unveraendert beim Client an' );

echo "\n\033[1mKein Zugriff aufs eigene Konto\033[0m\n";

foreach ( array( 'edit_user', 'create_app_password', 'edit_app_password', 'delete_app_password', 'delete_app_passwords', 'list_app_passwords', 'read_app_password', 'promote_user', 'delete_user' ) as $cap ) {
	check(
		array( 'do_not_allow' ) === map_meta_cap_result( $cap, 7, 7 ),
		"der Agent: kein {$cap} an sich selbst"
	);
}
check( array( 'do_not_allow' ) === map_meta_cap_result( 'edit_user', 7, 1 ), 'und auch nicht am Administrator' );
check( array( 'do_not_allow' ) === map_meta_cap_result( 'promote_users', 7 ), 'kein Rollenwechsel, auch wenn ein Rolleneditor das Recht vergibt' );

check(
	array( 'create_app_password' ) === map_meta_cap_result( 'create_app_password', 1, 7 ),
	'der Administrator legt dem Agenten weiter ein Passwort an',
	'sonst funktioniert die Einrichtung nicht mehr'
);
check( array( 'exist' ) === map_meta_cap_result( 'edit_user', 1, 1 ), 'und bearbeitet sein eigenes Profil wie immer' );
check( array( 'edit_posts' ) === map_meta_cap_result( 'edit_posts', 7 ), 'die Inhaltsrechte des Agenten sind nicht betroffen' );

echo "\n\033[1mAnwendungspasswoerter\033[0m\n";

$agent = $GLOBALS['users'][7];
$human = $GLOBALS['users'][1];

// A site that never disabled them.
check( wp_is_application_passwords_available_for_user( $agent ), 'HTTPS: der Agent hat sie' );
check(
	wp_is_application_passwords_available_for_user( $human ),
	'HTTPS: ein Mensch behaelt sie',
	'bis 0.18 hiess das fuer jeden Menschen nein, auch fuer die Mobile-App und Automationen'
);

// A security plugin that disables them for one particular human.
add_filter(
	'wp_is_application_passwords_available_for_user',
	function ( $available, $user ) { return 1 === $user->ID ? false : $available; },
	10,
	2
);
check( ! wp_is_application_passwords_available_for_user( $human ), 'eine Sperre pro Person bleibt bestehen' );
check( wp_is_application_passwords_available_for_user( $agent ), 'und trifft den Agenten nicht' );
$GLOBALS['hooks']['wp_is_application_passwords_available_for_user'][10] = array();

// The theme's hardening switches them off globally, at the default priority.
add_filter( 'wp_is_application_passwords_available', '__return_false', 10 );
check( wp_is_application_passwords_available_for_user( $agent ), 'global abgeschaltet: der Agent bekommt sie zurueck' );
check( ! wp_is_application_passwords_available_for_user( $human ), 'global abgeschaltet: Menschen bleiben ohne' );
check( false === wpmcp_app_passwords_available_before(), 'und das Plugin weiss, dass es sie wieder geoeffnet hat', 'fuer die Statuszeile im Admin' );

// Plain HTTP: an application password would travel readable.
$GLOBALS['ssl'] = false;
check( ! wp_is_application_passwords_available_for_user( $agent ), 'ohne HTTPS: auch der Agent nicht', 'bis 0.18 ueberging das Plugin die HTTPS-Pflicht' );
check( ! wp_is_application_passwords_available_for_user( $human ), 'ohne HTTPS: Menschen ebenso nicht' );
$GLOBALS['hooks']['wp_is_application_passwords_available'][10] = array();
check( ! wp_is_application_passwords_available_for_user( $agent ), 'ohne HTTPS und ohne Haertung: der Agent nicht', 'WordPress verlangt HTTPS ohnehin' );

$GLOBALS['env'] = 'local';
check( wp_is_application_passwords_available_for_user( $agent ), 'lokale Umgebung ohne HTTPS: der Agent darf', 'wie WordPress selbst' );
$GLOBALS['env'] = 'production';
$GLOBALS['ssl'] = true;

echo "\n\033[1mKein XML-RPC fuer den Agenten\033[0m\n";

// Logins outside XML-RPC are not this filter's business.
check( $agent === apply_filters( 'authenticate', $agent ), 'ausserhalb von XML-RPC unveraendert' );

define( 'XMLRPC_REQUEST', true );
$out = apply_filters( 'authenticate', $agent );
check( is_wp_error( $out ) && 'wpmcp_xmlrpc' === $out->get_error_code(), 'XML-RPC-Anmeldung des Agenten wird abgelehnt' );
check( $human === apply_filters( 'authenticate', $human ), 'ein Administrator meldet sich weiter an' );
check( null === apply_filters( 'authenticate', null ), 'und ein gescheiterter Versuch bleibt gescheitert' );

echo "\n";
if ( 0 === $fail ) {
	echo "\033[32mAgent-Zaun in Ordnung.\033[0m\n";
	exit( 0 );
}
echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
exit( 1 );
