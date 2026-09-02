<?php
/**
 * Render the admin page against a WordPress stub.
 *
 * The setup wizard is the one place where a typo or a missing function
 * only shows up when a human opens the page. This renders it in both
 * states — nothing set up, and fully set up — and fails on any PHP error.
 *
 * Run: php tests/render-admin.php
 *
 * @package wp-mcp-connector-plus
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'WPMCP_VERSION', 'test' );
define( 'WPMCP_DIR', dirname( __DIR__ ) . '/' );
define( 'WPMCP_FILE', dirname( __DIR__ ) . '/wp-mcp-connector-plus.php' );

$GLOBALS['wp_version'] = '7.0';
$GLOBALS['stub']       = array(
	'has_abilities_api' => true,
	'registered'        => 8,
	'agent_user'        => null,
	'passwords'         => 0,
	'level'             => 'draft',
	'patterns'          => 'read',
);

// --- WordPress stubs ----------------------------------------------------

function __( $t, $d = null ) { return $t; }
function esc_html__( $t, $d = null ) { return $t; }
function esc_html_e( $t, $d = null ) { echo $t; }
function _n( $s, $p, $n, $d = null ) { return 1 === $n ? $s : $p; }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_url( $t ) { return (string) $t; }
function esc_textarea( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function wp_kses( $t, $allowed ) { return $t; }
function add_action( ...$a ) { return true; }
function add_filter( ...$a ) { return true; }
function register_setting( ...$a ) { return true; }
function add_management_page( ...$a ) { return true; }
function settings_fields( $g ) { echo ''; }
function submit_button( $text = null, ...$rest ) { echo '<button>' . esc_html( (string) $text ) . '</button>'; }
function wp_nonce_field( ...$a ) { echo ''; }
function checked( $a, $b = true, $echo = true ) { return ''; }
function disabled( $a, $b = true, $echo = true ) { return ''; }
function current_user_can( $c ) { return true; }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . ltrim( $p, '/' ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function sanitize_title( $t ) { return strtolower( preg_replace( '/[^A-Za-z0-9]+/', '-', $t ) ); }
function sanitize_user( $t ) { return preg_replace( '/[^A-Za-z0-9_.\-]/', '', (string) $t ); }
function sanitize_text_field( $t ) { return trim( (string) $t ); }
function wp_unslash( $t ) { return $t; }
function wp_generate_password( ...$a ) { return 'stub-password'; }
function wp_json_encode( $d, $o = 0 ) { return json_encode( $d, $o ); }
function get_edit_post_link( $id ) { return 'https://example.test/edit/' . (int) $id; }
function get_the_title( $id ) { return 'Stub page'; }
function get_edit_user_link( $id ) { return 'https://example.test/user/' . (int) $id; }
function wp_verify_nonce( ...$a ) { return true; }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function get_user_by( $f, $v ) { return $GLOBALS['stub']['agent_user']; }
function wp_insert_user( $a ) { return 42; }

function get_users( $args = array() ) {
	return $GLOBALS['stub']['agent_user'] ? array( $GLOBALS['stub']['agent_user'] ) : array();
}

class WP_Error {
	private $c;
	private $m;
	public function __construct( $c = '', $m = '' ) { $this->c = $c; $this->m = $m; }
	public function get_error_message() { return $this->m; }
}

class WP_User {
	public $ID = 42;
	public $user_login = 'ai-agent';
	public $roles = array( 'wpmcp_ai_editor' );
}

class WP_Application_Passwords {
	public static function get_user_application_passwords( $id ) {
		return array_fill( 0, $GLOBALS['stub']['passwords'], array( 'name' => 'stub' ) );
	}
	public static function create_new_application_password( $id, $args ) {
		return array( 'abcd EFGH ijkl MNOP', array( 'name' => $args['name'] ) );
	}
}

if ( $GLOBALS['stub']['has_abilities_api'] ) {
	function wp_register_ability( ...$a ) { return true; }
	function wp_get_ability( $name ) {
		// Deterministic: the first N names of the current level count as
		// registered. A call counter would leak state between renders.
		$index = array_search( $name, wpmcp_ability_names(), true );
		return ( false !== $index && $index < $GLOBALS['stub']['registered'] )
			? (object) array( 'name' => $name )
			: null;
	}
}

// Pieces of the plugin the admin page calls into.
function wpmcp_ability_names() {
	$read = array(
		'wpmcp/site-info',
		'wpmcp/blocks-catalog',
		'wpmcp/blocks-describe',
		'wpmcp/content-list',
		'wpmcp/content-read',
		'wpmcp/content-preview',
	);
	return wpmcp_can_write()
		? array_merge( $read, array( 'wpmcp/content-write', 'wpmcp/content-duplicate' ) )
		: $read;
}
function wpmcp_adapter_is_usable() { return true; }
function wpmcp_live_edit_enabled() { return 'full' === wpmcp_access_level(); }
function wpmcp_can_write() { return 'read' !== wpmcp_access_level(); }
function wpmcp_access_level() { return $GLOBALS['stub']['level'] ?? 'draft'; }
function wpmcp_pattern_access() { return $GLOBALS['stub']['patterns'] ?? 'read'; }
function wpmcp_access_levels() {
	return array(
		'read'  => array( 'label' => 'Read only', 'description' => 'Look only.' ),
		'draft' => array( 'label' => 'Drafts', 'description' => 'Drafts and new pages.' ),
		'full'  => array( 'label' => 'Drafts and published pages', 'description' => 'Also published.' ),
	);
}
function wpmcp_audit_table() { return 'wp_wpmcp_log'; }
const WPMCP_ROLE = 'wpmcp_ai_editor';

class StubWpdb {
	public function get_results( $q ) { return array(); }
}
$GLOBALS['wpdb'] = new StubWpdb();

// --- Under test ---------------------------------------------------------

require_once dirname( __DIR__ ) . '/includes/admin.php';

$fail = 0;

function render_case( $name, callable $setup ) {
	global $fail;
	$setup();

	set_error_handler( // phpcs:ignore
		function ( $no, $str, $file, $line ) {
			throw new ErrorException( $str, 0, $no, $file, $line );
		}
	);
	try {
		ob_start();
		wpmcp_render_admin_page();
		$html = ob_get_clean();
	} catch ( Throwable $e ) {
		if ( ob_get_level() > 0 ) { ob_end_clean(); }
		restore_error_handler();
		echo "  \033[31m✗\033[0m {$name}\n      " . $e->getMessage() . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ")\n";
		++$fail;
		return '';
	}
	restore_error_handler();

	echo "  \033[32m✓\033[0m {$name} (" . strlen( $html ) . " Bytes)\n";
	return $html;
}

function expect_contains( $html, $needle, $name ) {
	global $fail;
	if ( false !== strpos( $html, $needle ) ) {
		echo "  \033[32m✓\033[0m {$name}\n";
		return;
	}
	echo "  \033[31m✗\033[0m {$name}\n      erwartet: {$needle}\n";
	++$fail;
}

echo "\n\033[1mAdmin-Seite rendern\033[0m\n";

$html = render_case(
	'frisch installiert, noch kein Agent-Benutzer',
	function () {
		$GLOBALS['stub']['agent_user'] = null;
		$GLOBALS['stub']['passwords']  = 0;
	}
);
expect_contains( $html, 'Set up a connection', 'zeigt das Setup-Formular' );
expect_contains( $html, 'dashicons-marker', 'Agent-Schritt steht auf offen' );

$html = render_case(
	'fertig eingerichtet',
	function () {
		$GLOBALS['stub']['agent_user'] = new WP_User();
		$GLOBALS['stub']['passwords']  = 1;
	}
);
expect_contains( $html, 'ai-agent', 'nennt den Agent-Benutzer' );

echo "\n\033[1mFehlerzustaende\033[0m\n";

$html = render_case(
	'Abilities nur teilweise registriert',
	function () {
		$GLOBALS['stub']['registered'] = 0;
	}
);
expect_contains( $html, 'expose no tools', 'warnt vor leerer Werkzeugliste' );
expect_contains( $html, 'dashicons-dismiss', 'markiert den Schritt als Fehler' );

echo "\n\033[1mZugriffsstufen\033[0m\n";

$GLOBALS['stub']['agent_user'] = new WP_User();
$GLOBALS['stub']['passwords']  = 1;
$GLOBALS['stub']['registered'] = 8;

$html = render_case( 'Stufe: Entwuerfe', function () { $GLOBALS['stub']['level'] = 'draft'; } );
expect_contains( $html, 'Publishing is never possible', 'nennt die harte Grenze' );
expect_contains( $html, 'Synced patterns', 'zeigt die Muster-Einstellung' );

$GLOBALS['stub']['registered'] = 6;
$html = render_case( 'Stufe: nur lesen', function () { $GLOBALS['stub']['level'] = 'read'; } );
expect_contains( $html, '6 of 6', 'zaehlt nur die Lese-Abilities' );

$GLOBALS['stub']['level']      = 'draft';
$GLOBALS['stub']['registered'] = 8;

echo "\n\033[1mVerbindung erzeugen\033[0m\n";

$_POST = array(
	'wpmcp_setup_nonce' => 'x',
	'wpmcp_login'       => 'ai-agent',
);
$GLOBALS['stub']['registered']  = 8;
$GLOBALS['stub']['agent_user']  = new WP_User();

$html = render_case( 'Formular abgeschickt', function () {} );
expect_contains( $html, 'claude mcp add', 'gibt den fertigen Befehl aus' );
expect_contains( $html, 'strict-mcp-config', 'gibt den isolierten Start aus' );
expect_contains( $html, 'mcpServers', 'gibt die JSON-Konfiguration aus' );
expect_contains( $html, 'shown only once', 'warnt, dass das Passwort einmalig ist' );

echo "\n";
if ( 0 === $fail ) {
	echo "\033[32mAdmin-Rendering in Ordnung.\033[0m\n";
	exit( 0 );
}
echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
exit( 1 );
