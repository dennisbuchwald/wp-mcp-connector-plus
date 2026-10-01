<?php
/**
 * Render the admin page against a WordPress stub.
 *
 * The setup wizard is the one place where a typo or a missing function
 * only shows up when a human opens the page. This renders every tab in
 * its states and fails on any PHP error. It also runs the admin-post
 * handlers (post, redirect, get: a reload must never create a second
 * password or reopen a session), the admin bar node and the activity
 * log table against fixture rows.
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
	'registered'        => 16,
	'agent_user'        => null,
	'passwords'         => 0,
	'level'             => 'draft',
	'patterns'          => 'read',
	'caps_match'        => true,
	'extra'             => array( 'gp_elements' ),
	'session'           => 0,
	'selectable'        => array(
		'gp_elements'   => 'Elements',
		'wp_template'   => 'Templates',
		'wp_navigation' => 'Menues',
		'gp_font'       => 'Fonts',
		'acf-field'     => 'Felder',
		'shop_order'    => 'Bestellungen',
	),
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
function current_user_can( $c ) { return $GLOBALS['stub']['can'] ?? true; }
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
function get_post( $id = null ) {
	$id = (int) ( is_object( $id ) ? $id->ID : $id );
	return in_array( $id, $GLOBALS['stub']['existing_posts'] ?? array(), true ) ? (object) array( 'ID' => $id ) : null;
}
function get_userdata( $id ) {
	return 7 === (int) $id ? (object) array( 'ID' => 7, 'display_name' => 'Lara <Admin>' ) : false;
}
function _prime_post_caches( $ids, $a = true, $b = true ) { $GLOBALS['stub']['primed'] = $ids; }
function cache_users( $ids ) { $GLOBALS['stub']['cached_users'] = $ids; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . ltrim( $p, '/' ); }
function add_query_arg( $args, $url, $third = null ) {
	if ( ! is_array( $args ) ) {
		$args = array( $args => $url );
		$url  = $third;
	}
	$sep = false === strpos( $url, '?' ) ? '?' : '&';
	return $url . $sep . http_build_query( $args );
}
function remove_query_arg( $keys, $url ) {
	$parts = explode( '?', $url, 2 );
	parse_str( $parts[1] ?? '', $q );
	foreach ( (array) $keys as $k ) {
		unset( $q[ $k ] );
	}
	return $parts[0] . ( $q ? '?' . http_build_query( $q ) : '' );
}
function wp_nonce_url( $url, $action ) { return $url . '&_wpnonce=nonce-' . $action; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function selected( $a, $b, $echo = true ) { $r = (string) $a === (string) $b ? ' selected="selected"' : ''; if ( $echo ) { echo $r; } return $r; }
function settings_errors() { echo ''; }
function esc_attr__( $t, $d = null ) { return $t; }
function get_current_user_id() { return 1; }
function get_option( $name, $default = false ) { return $GLOBALS['stub']['options'][ $name ] ?? $default; }
function wp_date( $format, $ts ) { return gmdate( 'Y-m-d H:i', $ts + 7200 ) . ' (site)'; }
function get_transient( $k ) { return $GLOBALS['stub']['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl ) { $GLOBALS['stub']['transients'][ $k ] = $v; $GLOBALS['stub']['ttl'][ $k ] = $ttl; return true; }
function delete_transient( $k ) { unset( $GLOBALS['stub']['transients'][ $k ] ); return true; }
function check_admin_referer( $action, $field = '_wpnonce' ) {
	if ( ( $_REQUEST[ $field ] ?? '' ) !== 'nonce-' . $action ) {
		throw new Halt( 'nonce' );
	}
	return 1;
}
function wp_get_referer() { return $GLOBALS['stub']['referer'] ?? false; }
function wp_die( $message = '', $code = 0 ) { throw new Halt( 'die:' . $code ); }
function wp_safe_redirect( $url ) { throw new Halt( 'redirect:' . $url ); }

/** Ends a request the way wp_safe_redirect + exit or wp_die would. */
class Halt extends Exception {}

/** What a list table needs from WP_List_Table, and no more. */
class WP_List_Table {
	public $items = array();
	protected $_column_headers;
	protected $_pagination_args = array();
	public function __construct( $args = array() ) {}
	public function get_pagenum() { return max( 1, (int) ( $_REQUEST['paged'] ?? 1 ) ); }
	public function set_pagination_args( $args ) { $this->_pagination_args = $args; }
	public function get_pagination_arg( $key ) { return $this->_pagination_args[ $key ] ?? null; }
	public function search_box( $text, $id ) { echo '<p class="search-box"><input type="search" name="s" /> ' . esc_html( $text ) . '</p>'; }
	public function display() {
		$this->extra_tablenav( 'top' );
		echo '<table class="wp-list-table"><thead><tr>';
		foreach ( $this->get_columns() as $key => $label ) {
			echo '<th class="column-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( empty( $this->items ) ) {
			echo '<tr class="no-items"><td>';
			$this->no_items();
			echo '</td></tr>';
		}
		foreach ( $this->items as $item ) {
			echo '<tr>';
			foreach ( array_keys( $this->get_columns() ) as $key ) {
				$method = 'column_' . $key;
				echo '<td class="column-' . esc_attr( $key ) . '">' . ( method_exists( $this, $method ) ? $this->$method( $item ) : $this->column_default( $item, $key ) ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	}
}

/** Collects nodes. */
class WP_Admin_Bar {
	public $nodes = array();
	public function add_node( $node ) { $this->nodes[ $node['id'] ] = $node; }
}
function get_edit_user_link( $id ) { return 'https://example.test/user/' . (int) $id; }
function wp_verify_nonce( ...$a ) { return true; }
function wpmcp_end_work_session() { $GLOBALS['stub']['calls'][] = 'end'; $GLOBALS['stub']['session'] = 0; }
function wpmcp_start_work_session( $h ) { $GLOBALS['stub']['calls'][] = 'start:' . $h; $GLOBALS['stub']['session'] = time() + 3600 * $h; return $GLOBALS['stub']['session']; }
function wpmcp_work_session_active() { return wpmcp_work_session_expires() > 0; }
function wpmcp_sync_role_capabilities() { $GLOBALS['stub']['calls'][] = 'sync'; $GLOBALS['stub']['caps_match'] = true; }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function get_user_by( $f, $v ) { return $GLOBALS['stub']['agent_user']; }
function wp_insert_user( $a ) { return 42; }

function get_users( $args = array() ) {
	$user = $GLOBALS['stub']['agent_user'];
	if ( ! $user ) {
		return array();
	}
	return 'ID' === ( $args['fields'] ?? '' ) ? array( $user->ID ) : array( $user );
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
		$GLOBALS['stub']['created'] = ( $GLOBALS['stub']['created'] ?? 0 ) + 1;
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
		'wpmcp/content-revisions',
		'wpmcp/content-search',
		'wpmcp/content-fetch-live',
		'wpmcp/media-list',
		'wpmcp/media-read',
	);
	return wpmcp_can_write()
		? array_merge( $read, array( 'wpmcp/content-write', 'wpmcp/content-create', 'wpmcp/content-duplicate', 'wpmcp/content-restore', 'wpmcp/media-update' ) )
		: $read;
}
function wpmcp_adapter_is_usable() { return true; }
function wpmcp_live_edit_enabled() { return 'full' === wpmcp_access_level(); }
function wpmcp_can_write() { return 'read' !== wpmcp_access_level(); }
function wpmcp_access_level() { return $GLOBALS['stub']['level'] ?? 'draft'; }
function wpmcp_configured_access_level() { return $GLOBALS['stub']['level'] ?? 'draft'; }
function wpmcp_pattern_access() { return $GLOBALS['stub']['patterns'] ?? 'read'; }
function wpmcp_dynamic_data_allowed() { return ! empty( $GLOBALS['stub']['dynamic'] ); }
function wpmcp_extra_post_types() { return $GLOBALS['stub']['extra'] ?? array(); }
function wpmcp_selectable_post_types() { return $GLOBALS['stub']['selectable'] ?? array(); }
function wpmcp_post_type_holds_personal_data( $slug ) { return false !== strpos( $slug, 'order' ); }
function wpmcp_work_session_expires() { return $GLOBALS['stub']['session'] ?? 0; }
function wpmcp_work_session_remaining() { return '3 Stunden'; }
function wpmcp_work_session_lengths() { return array( 1 => '1 hour', 4 => '4 hours', 8 => '8 hours' ); }
function human_time_diff( $a, $b = 0 ) { return '3 Stunden'; }
function esc_attr_e( $t, $d = null ) { echo htmlspecialchars( (string) $t, ENT_QUOTES ); }
function wpmcp_access_levels() {
	return array(
		'read'  => array( 'label' => 'Read only', 'description' => 'Look only.' ),
		'draft' => array( 'label' => 'Drafts', 'description' => 'Drafts and new pages.' ),
		'full'  => array( 'label' => 'Drafts and published pages', 'description' => 'Also published.' ),
	);
}
function wpmcp_role_caps_match() { return $GLOBALS['stub']['caps_match'] ?? true; }
function wp_is_application_passwords_available() {
	// The way WordPress runs it: the plugin records the site's own answer
	// on the way through the global filter.
	wpmcp_app_passwords_available_before( $GLOBALS['stub']['app_pw_before'] ?? true );
	return true;
}
function wpmcp_app_passwords_transport_safe() { return $GLOBALS['stub']['https'] ?? true; }
function wpmcp_app_passwords_available_before( $set = null ) {
	static $before = null;
	if ( null !== $set ) { $before = (bool) $set; }
	return $before;
}
const WPMCP_ROLE = 'wpmcp_ai_editor';

/**
 * Records every query; answers from fixtures. Placeholders are filled the
 * way wpdb::prepare would, so a test can read what was asked.
 */
class StubWpdb {
	public $prefix  = 'wp_';
	public $queries = array();
	public function prepare( $sql, ...$args ) {
		$args = ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args;
		$i    = 0;
		return preg_replace_callback(
			'/%[dsf]/',
			function ( $m ) use ( $args, &$i ) {
				$v = $args[ $i++ ] ?? '';
				return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
			},
			$sql
		);
	}
	public function esc_like( $t ) { return addcslashes( $t, '_%\\' ); }
	public function get_results( $q ) { $this->queries[] = $q; return $GLOBALS['stub']['log'] ?? array(); }
	public function get_var( $q ) { $this->queries[] = $q; return count( $GLOBALS['stub']['log'] ?? array() ); }
	public function get_col( $q ) {
		$this->queries[] = $q;
		$col = false !== strpos( $q, 'DISTINCT ability' ) ? 'ability' : 'user_id';
		return array_values( array_unique( array_map( function ( $r ) use ( $col ) { return $r->$col; }, $GLOBALS['stub']['log'] ?? array() ) ) );
	}
	public function get_row( $q ) { $this->queries[] = $q; return $GLOBALS['stub']['last_call'] ?? null; }
	public function last( $needle ) {
		foreach ( array_reverse( $this->queries ) as $q ) {
			if ( false !== strpos( $q, $needle ) ) {
				return $q;
			}
		}
		return '';
	}
}
$GLOBALS['wpdb'] = new StubWpdb();

// --- Under test ---------------------------------------------------------

require_once dirname( __DIR__ ) . '/includes/audit.php';
require_once dirname( __DIR__ ) . '/includes/admin.php';
require_once dirname( __DIR__ ) . '/includes/admin-bar.php';

$fail = 0;

function render_case( $name, callable $setup, $tab = '' ) {
	global $fail;
	$_GET = '' === $tab ? array() : array( 'tab' => $tab );
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

function expect_not_contains( $html, $needle, $name ) {
	global $fail;
	if ( false === strpos( $html, $needle ) ) {
		echo "  \033[32m✓\033[0m {$name}\n";
		return;
	}
	echo "  \033[31m✗\033[0m {$name}\n      unerwartet gefunden: {$needle}\n";
	++$fail;
}

/**
 * Run an admin-post handler to its redirect (or wp_die / failed nonce).
 *
 * @return string What ended the request: "redirect:<url>", "die:403", "nonce".
 */
function run_handler( callable $handler, array $request ) {
	$_POST    = $request;
	$_REQUEST = $request;
	try {
		$handler();
	} catch ( Halt $h ) {
		return $h->getMessage();
	} finally {
		$_POST    = array();
		$_REQUEST = array();
	}
	return 'fell through';
}

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
expect_contains( $html, 'No call from the agent yet', 'letzte Verbindung: noch keine' );

$html = render_case(
	'fertig eingerichtet',
	function () {
		$GLOBALS['stub']['agent_user'] = new WP_User();
		$GLOBALS['stub']['passwords']  = 1;
		$GLOBALS['stub']['last_call']  = (object) array( 'created_at' => '2026-10-01 10:00:00', 'ability' => 'wpmcp/content-read' );
	}
);
expect_contains( $html, 'ai-agent', 'nennt den Agent-Benutzer' );
expect_contains( $html, 'Last connection', 'Statuszeile "Last connection"' );
expect_contains( $html, '3 Stunden ago (content-read, 2026-10-01 12:00 (site))', 'mit Abstand, Werkzeug und Ortszeit der Seite' );
expect_contains( $GLOBALS['wpdb']->last( 'user_id IN' ), 'user_id IN (42)', 'gesucht wird nach Eintraegen des Agent-Kontos' );

echo "\n\033[1mTabs\033[0m\n";

$html = render_case( 'ohne tab', function () {} );
expect_contains( $html, 'nav-tab-wrapper', 'zeigt die Tab-Leiste' );
expect_contains( $html, 'class="nav-tab nav-tab-active"' . "\n\t\t\t\t\t" . 'aria-current="page">Connection', 'Connection ist der Standard' );
expect_not_contains( $html, 'What the agent may do', 'die Einstellungen stehen nicht auf dem Verbindungs-Tab' );
expect_contains( $html, 'tools.php?page=wp-mcp-connector-plus&tab=access', 'jeder Tab ist ein eigener Link' );

$html = render_case( 'tab=access', function () {}, 'access' );
expect_contains( $html, 'What the agent may do', 'zeigt die Einstellungen' );
expect_contains( $html, 'action="options.php"', 'das Einstellungsformular geht weiter an options.php' );
expect_not_contains( $html, 'Set up a connection', 'aber nicht das Setup' );

$html = render_case( 'tab=activity', function () {}, 'activity' );
expect_contains( $html, 'Activity log', 'zeigt das Protokoll' );
expect_not_contains( $html, 'What the agent may do', 'und sonst nichts' );

$html = render_case( 'unbekannter tab', function () {}, '<script>' );
expect_contains( $html, 'Set up a connection', 'faellt auf Connection zurueck' );
expect_not_contains( $html, '<script>"', 'und gibt den Wert nicht aus' );

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
$GLOBALS['stub']['registered'] = 16;

$html = render_case( 'Stufe: Entwuerfe', function () { $GLOBALS['stub']['level'] = 'draft'; }, 'access' );
expect_contains( $html, 'No level lets the agent publish or upload images.', 'nennt die harte Grenze' );
expect_contains( $html, 'only during a work session you open', 'und den einzigen Weg darum herum' );
expect_not_contains( $html, 'Publishing is never possible', 'kein "Publishing is never possible" mehr' );
expect_contains( $html, 'Synced patterns', 'zeigt die Muster-Einstellung' );
expect_contains( $html, 'Dynamic data', 'zeigt die Dynamic-Data-Einstellung' );
expect_contains( $html, 'Additional post types', 'zeigt die Post-Type-Auswahl' );
expect_contains( $html, 'gp_elements', 'listet einen vorhandenen Post-Type' );
expect_contains( $html, 'wpmcp-toggle-all', 'bietet "Alle auswaehlen" bei langer Liste' );
expect_contains( $html, 'indeterminate', 'halb ausgewaehlt sieht auch halb aus' );
expect_contains( $html, 'personal data', 'markiert Post-Types mit Kundendaten' );
expect_contains( $html, 'unfiltered_html', 'und benennt die Capability dahinter' );
expect_contains( $html, 'does not add tools', 'sagt, dass eine Sitzung keine Werkzeuge hinzufuegt' );
expect_not_contains( $html, 'a session cannot give it any', 'auf einer Schreibstufe ohne Lese-Hinweis' );
expect_not_contains( preg_replace( '#<style>.*?</style>|<script>.*?</script>#s', '', $html ), 'style="', 'keine Inline-Styles mehr, alles im einen style-Block' );
expect_contains( $html, 'wpmcp_extra_post_types[]', 'die Post-Type-Haken sind im Formular' );

$GLOBALS['stub']['registered'] = 11;
$html = render_case( 'Stufe: nur lesen', function () { $GLOBALS['stub']['level'] = 'read'; } );
expect_contains( $html, '11 of 11', 'zaehlt nur die Lese-Abilities' );
$html = render_case( 'Stufe: nur lesen, Tab Access', function () {}, 'access' );
expect_contains( $html, 'Set the level to "Drafts" or "Drafts and published pages" to let the agent write during a session.', 'Hinweis neben den Sitzungs-Knoepfen' );

$GLOBALS['stub']['level']      = 'draft';
$GLOBALS['stub']['registered'] = 8;

$html = render_case( 'Rechte laufen auseinander', function () { $GLOBALS['stub']['caps_match'] = false; } );
expect_contains( $html, 'stores more than reading', 'meldet nicht passende Rechte' );
expect_contains( $html, 'value="wpmcp_repair_role"', 'mit einem Knopf "Repair now"' );
expect_contains( $html, 'Repair now', 'beschriftet' );
$html = render_case( 'Rechte stimmen', function () { $GLOBALS['stub']['caps_match'] = true; } );
expect_not_contains( $html, 'Repair now', 'ohne Abweichung kein Reparatur-Knopf' );

echo "\n\033[1mAnwendungspasswoerter\033[0m\n";

$html = render_case( 'HTTPS, nichts abgeschaltet', function () { $GLOBALS['stub']['https'] = true; $GLOBALS['stub']['app_pw_before'] = true; } );
expect_contains( $html, 'leaves them as they were for every human account', 'sagt, dass Menschen nichts geaendert bekommen' );

$html = render_case( 'von der Haertung abgeschaltet', function () { $GLOBALS['stub']['app_pw_before'] = false; } );
expect_contains( $html, 'reopened them for the agent account only', 'sagt, dass nur der Agent sie bekommt' );

$html = render_case( 'kein HTTPS', function () { $GLOBALS['stub']['https'] = false; } );
expect_contains( $html, 'not served over HTTPS', 'warnt ohne HTTPS' );
$GLOBALS['stub']['https']         = true;
$GLOBALS['stub']['app_pw_before'] = true;

echo "\n\033[1mArbeitssitzung\033[0m\n";

$html = render_case( 'keine Sitzung laeuft', function () { $GLOBALS['stub']['session'] = 0; }, 'access' );
expect_contains( $html, 'Working on the site?', 'bietet den Start an' );
expect_contains( $html, '4 hours', 'mit den moeglichen Fenstern' );
expect_contains( $html, 'action="https://example.test/wp-admin/admin-post.php"', 'das Formular geht an admin-post.php' );
expect_contains( $html, 'value="wpmcp_session"', 'mit der eigenen Aktion' );

$html = render_case( 'Sitzung laeuft', function () { $GLOBALS['stub']['session'] = time() + 9000; }, 'access' );
expect_contains( $html, 'A work session is running', 'sagt, dass sie laeuft' );
expect_contains( $html, '3 Stunden', 'und wie lange noch' );
expect_contains( $html, 'Close now', 'und laesst sie sofort schliessen' );
$GLOBALS['stub']['session'] = 0;

echo "\n\033[1mPost, Redirect, Get\033[0m\n";

// Rendering with a posted form, the way the page used to work, creates
// nothing: only the admin-post handler does.
$GLOBALS['stub']['created'] = 0;
$_POST = array( 'wpmcp_setup_nonce' => 'nonce-wpmcp_setup', 'wpmcp_login' => 'ai-agent' );
$html  = render_case( 'Seite mit gepostetem Formular', function () {} );
$_POST = array();
check( 0 === $GLOBALS['stub']['created'], 'das Rendern legt kein Passwort an', $GLOBALS['stub']['created'] . ' angelegt' );
expect_not_contains( $html, 'abcd EFGH', 'und zeigt keins' );

$end = run_handler( 'wpmcp_admin_post_setup', array( 'wpmcp_setup_nonce' => 'falsch', 'wpmcp_login' => 'ai-agent' ) );
check( 'nonce' === $end && 0 === $GLOBALS['stub']['created'], 'falsche Nonce: nichts angelegt', $end );

$GLOBALS['stub']['can'] = false;
$end = run_handler( 'wpmcp_admin_post_setup', array( 'wpmcp_setup_nonce' => 'nonce-wpmcp_setup', 'wpmcp_login' => 'ai-agent' ) );
check( 'die:403' === $end && 0 === $GLOBALS['stub']['created'], 'ohne manage_options: 403, nichts angelegt', $end );
$GLOBALS['stub']['can'] = true;

$end = run_handler( 'wpmcp_admin_post_setup', array( 'wpmcp_setup_nonce' => 'nonce-wpmcp_setup', 'wpmcp_login' => 'ai-agent' ) );
check( 1 === $GLOBALS['stub']['created'], 'der Handler legt genau ein Passwort an', $GLOBALS['stub']['created'] . ' angelegt' );
check( 0 === strpos( $end, 'redirect:https://example.test/wp-admin/tools.php?page=wp-mcp-connector-plus&tab=connection' ), 'und leitet auf den Verbindungs-Tab um', $end );
check( 60 === ( $GLOBALS['stub']['ttl']['wpmcp_connection_1'] ?? null ), 'das Ergebnis wartet 60 Sekunden, an diesen Admin gebunden', wp_json_encode( $GLOBALS['stub']['ttl'] ) );

$html = render_case( 'erster Aufruf nach dem Redirect', function () {} );
expect_contains( $html, 'claude mcp add --transport http -s user', 'empfiehlt claude mcp add als primaeren Weg' );
expect_not_contains( $html, 'strict-mcp-config', 'kein isolierter Start, damit andere MCPs verfuegbar bleiben' );
expect_contains( $html, 'mcpServers', 'gibt die JSON-Konfiguration als Alternative aus' );
expect_contains( $html, 'shown only once', 'warnt, dass das Passwort einmalig ist' );
expect_contains( $html, 'abcd EFGH ijkl MNOP', 'zeigt das Passwort' );
expect_contains( $html, '<details class="wpmcp-secret">', 'Passwort und Header liegen hinter "Show"' );
expect_contains( $html, 'data-copy="wpmcp-password"', 'mit Kopier-Knopf fuer das Passwort' );
expect_contains( $html, 'data-copy="wpmcp-cmd-add"', 'und fuer den Befehl' );
expect_contains( $html, 'navigator.clipboard', 'kopiert ueber die Clipboard-API' );
expect_contains( $html, "execCommand( 'copy' )", 'mit Rueckfall auf Markieren und Kopieren' );
expect_contains( $html, 'aria-live="polite"', 'und meldet "Copied" auch Screenreadern' );
check( ! isset( $GLOBALS['stub']['transients']['wpmcp_connection_1'] ), 'nach dem Anzeigen ist das Passwort aus dem Transient geloescht' );

$html = render_case( 'Neu laden', function () {} );
expect_not_contains( $html, 'abcd EFGH', 'zeigt das Passwort nicht noch einmal' );
expect_contains( $html, 'Set up a connection', 'sondern wieder das Formular' );
check( 1 === $GLOBALS['stub']['created'], 'und legt kein zweites an', $GLOBALS['stub']['created'] . ' angelegt' );

$GLOBALS['stub']['referer'] = 'https://example.test/wp-admin/tools.php?page=wp-mcp-connector-plus&tab=access';
$end = run_handler( 'wpmcp_admin_post_session', array( '_wpnonce' => 'nonce-wpmcp_work_session', 'wpmcp_session_start' => '4' ) );
check( array( 'start:4' ) === ( $GLOBALS['stub']['calls'] ?? array() ), 'Sitzung starten: genau ein Start', wp_json_encode( $GLOBALS['stub']['calls'] ?? null ) );
check( 'redirect:' . $GLOBALS['stub']['referer'] === $end, 'und zurueck, woher die Anfrage kam', $end );
$html = render_case( 'nach dem Start', function () {}, 'access' );
expect_contains( $html, 'Work session opened.', 'eine Meldung sagt, was passiert ist' );
$html = render_case( 'nach dem Start, neu geladen', function () {}, 'access' );
expect_not_contains( $html, 'Work session opened.', 'die Meldung kommt nur einmal' );
check( array( 'start:4' ) === $GLOBALS['stub']['calls'], 'Neu laden startet nichts', wp_json_encode( $GLOBALS['stub']['calls'] ) );

$GLOBALS['stub']['calls'] = array();
$end = run_handler( 'wpmcp_admin_post_session', array( '_wpnonce' => 'nonce-wpmcp_work_session', 'wpmcp_session_stop' => '1' ) );
check( array( 'end' ) === $GLOBALS['stub']['calls'] && 0 === $GLOBALS['stub']['session'], 'Sitzung schliessen', wp_json_encode( $GLOBALS['stub']['calls'] ) );

$GLOBALS['stub']['calls'] = array();
$end = run_handler( 'wpmcp_admin_post_session', array( '_wpnonce' => 'nonce-wpmcp_setup', 'wpmcp_session_start' => '8' ) );
check( 'nonce' === $end && array() === $GLOBALS['stub']['calls'], 'mit fremder Nonce passiert nichts', $end );

$GLOBALS['stub']['caps_match'] = false;
$end = run_handler( 'wpmcp_admin_post_repair_role', array( '_wpnonce' => 'nonce-wpmcp_repair_role' ) );
check( array( 'sync' ) === $GLOBALS['stub']['calls'], 'Repair now gleicht die Rolle ab', wp_json_encode( $GLOBALS['stub']['calls'] ) );
check( 0 === strpos( $end, 'redirect:https://example.test/wp-admin/tools.php?page=wp-mcp-connector-plus&tab=connection' ), 'und leitet zurueck zum Status', $end );
$html = render_case( 'nach der Reparatur', function () {} );
expect_contains( $html, 'The agent role is repaired', 'mit Erfolgsmeldung' );
expect_not_contains( $html, 'Repair now', 'und ohne Knopf' );
$GLOBALS['stub']['calls']   = array();
$GLOBALS['stub']['referer'] = false;

echo "\n\033[1mAdmin-Leiste\033[0m\n";

check( '2 h 14 min' === wpmcp_session_time_left( 2 * 3600 + 14 * 60 + 10 ), 'Restzeit "2 h 14 min"', wpmcp_session_time_left( 2 * 3600 + 14 * 60 + 10 ) );
check( '45 min' === wpmcp_session_time_left( 45 * 60 - 20 ), 'unter einer Stunde nur Minuten, gerundet', wpmcp_session_time_left( 45 * 60 - 20 ) );
check( '1 h 0 min' === wpmcp_session_time_left( 3600 ), 'eine volle Stunde', wpmcp_session_time_left( 3600 ) );

$bar = new WP_Admin_Bar();
$GLOBALS['stub']['session'] = 0;
wpmcp_admin_bar_session( $bar );
check( array() === $bar->nodes, 'ohne Sitzung kein Eintrag' );

$GLOBALS['stub']['session'] = time() + 2 * 3600 + 14 * 60 + 10;
$GLOBALS['stub']['can']     = false;
wpmcp_admin_bar_session( $bar );
check( array() === $bar->nodes, 'ohne manage_options kein Eintrag' );

$GLOBALS['stub']['can'] = true;
wpmcp_admin_bar_session( $bar );
check( 'MCP session: 2 h 14 min' === ( $bar->nodes['wpmcp-session']['title'] ?? '' ), 'mit Sitzung: "MCP session: 2 h 14 min"', $bar->nodes['wpmcp-session']['title'] ?? '' );
check( false !== strpos( $bar->nodes['wpmcp-session']['href'] ?? '', 'tab=access' ), 'verlinkt auf die Einstellungen' );
$close = $bar->nodes['wpmcp-session-close'] ?? array();
check( 'wpmcp-session' === ( $close['parent'] ?? '' ) && 'Close session now' === ( $close['title'] ?? '' ), 'mit Unterpunkt "Close session now"' );
check( false !== strpos( $close['href'] ?? '', 'admin-post.php?action=wpmcp_session&wpmcp_session_stop=1&_wpnonce=nonce-wpmcp_work_session' ), 'ueber admin-post mit Nonce', $close['href'] ?? '' );

// The link the admin bar gives really closes the session.
parse_str( (string) parse_url( $close['href'] ?? '', PHP_URL_QUERY ), $query );
$GLOBALS['stub']['referer'] = 'https://example.test/eine-seite/';
$end = run_handler( 'wpmcp_admin_post_session', $query );
check( 0 === $GLOBALS['stub']['session'] && 'redirect:https://example.test/eine-seite/' === $end, 'der Link schliesst sie und fuehrt zurueck auf die Seite', $end );
$GLOBALS['stub']['referer'] = false;
$GLOBALS['stub']['calls']   = array();
wpmcp_admin_take( 'flash' );

echo "\n\033[1mProtokoll\033[0m\n";

$GLOBALS['stub']['existing_posts'] = array( 12, 901 );
$html = render_case(
	'mit Eintraegen im Protokoll',
	function () {
		$GLOBALS['stub']['log'] = array(
			(object) array( 'id' => 4, 'created_at' => '2026-10-01 12:02:00', 'user_id' => 7, 'ability' => 'wpmcp/content-write', 'post_id' => 12, 'operation' => 'tree', 'dry_run' => 0, 'summary' => 'Saved (+1 blocks, 9 total). <b>', 'revision_id' => 901 ),
			(object) array( 'id' => 3, 'created_at' => '2026-10-01 12:00:00', 'user_id' => 42, 'ability' => 'wpmcp/content-write', 'post_id' => 12, 'operation' => 'rejected', 'dry_run' => 0, 'summary' => 'Rejected (tree): 1 validation error(s).', 'revision_id' => 0 ),
			(object) array( 'id' => 2, 'created_at' => '2026-10-01 12:01:00', 'user_id' => 42, 'ability' => 'wpmcp/content-write', 'post_id' => 77, 'operation' => 'tree', 'dry_run' => 1, 'summary' => 'Dry run OK (+1 blocks).', 'revision_id' => 0 ),
			(object) array( 'id' => 1, 'created_at' => '2026-10-01 11:00:00', 'user_id' => 0, 'ability' => 'wpmcp/work-session', 'post_id' => 0, 'operation' => '', 'dry_run' => 0, 'summary' => 'Work session expired.', 'revision_id' => 0 ),
		);
	},
	'activity'
);
expect_contains( $html, '<span class="wpmcp-op is-rejected">Rejected</span>', 'zeigt "Rejected" als eigene Art' );
expect_contains( $html, '<span class="wpmcp-op is-dry-run">Dry run</span> <code>tree</code>', 'einen Probelauf als Probelauf' );
expect_contains( $html, '<span class="wpmcp-op is-saved">Saved</span> <code>tree</code>', 'und ein Speichern als Speichern' );
expect_contains( $html, 'revision.php?revision=901', 'mit Link zum Revisionsvergleich' );
expect_contains( $html, 'Compare revisions', 'beschriftet "Compare revisions"' );
expect_contains( $html, '2026-10-01 14:02 (site)', 'Zeit in der Zeitzone der Seite' );
expect_contains( $html, 'datetime="2026-10-01T12:02:00+00:00"', 'UTC bleibt im Markup' );
expect_contains( $html, 'Lara &lt;Admin&gt;', 'Benutzer mit Namen, escaped' );
expect_contains( $html, 'Deleted user #42', 'ein nicht mehr vorhandenes Konto' );
expect_contains( $html, 'Saved (+1 blocks, 9 total). &lt;b&gt;', 'die Zusammenfassung ist escaped' );
expect_contains( $html, '#77', 'eine geloeschte Seite mit ihrer ID' );
expect_contains( $html, '(deleted)', 'und als geloescht markiert' );
expect_contains( $html, 'href="https://example.test/edit/12"', 'eine vorhandene mit Link in den Editor' );
expect_contains( $html, 'Entries are kept for 90 days.', 'nennt die Aufbewahrung' );
expect_contains( $html, 'name="log_tool"', 'Filter nach Werkzeug' );
expect_contains( $html, 'name="log_result"', 'nach Ergebnis' );
expect_contains( $html, 'name="log_user"', 'nach Benutzer' );
expect_contains( $html, 'name="log_post"', 'nach Seite' );
expect_contains( $html, 'name="s"', 'und Suche in den Zusammenfassungen' );
expect_not_contains( $html, 'bulk', 'keine Sammelaktionen' );
check( array( 12, 901, 77 ) === array_values( $GLOBALS['stub']['primed'] ?? array() ), 'Seiten und Revisionen in einer Abfrage vorgeladen', wp_json_encode( $GLOBALS['stub']['primed'] ?? null ) );
check( array( 7, 42 ) === array_values( $GLOBALS['stub']['cached_users'] ?? array() ), 'Benutzer ebenso', wp_json_encode( $GLOBALS['stub']['cached_users'] ?? null ) );
expect_contains( $GLOBALS['wpdb']->last( 'ORDER BY id DESC LIMIT' ), 'LIMIT 25 OFFSET 0', '25 je Seite' );

$GLOBALS['stub']['options']['wpmcp_log_retention_days'] = 0;
$_REQUEST = array( 'paged' => 3 );
$html = render_case(
	'gefiltert, Seite 3',
	function () {
		$_GET = array( 'tab' => 'activity', 'log_tool' => 'wpmcp/content-write', 'log_result' => 'rejected', 'log_user' => '42', 'log_post' => '12', 's' => "50%_x'" );
	},
	'activity'
);
$_REQUEST = array();
$query = $GLOBALS['wpdb']->last( 'ORDER BY id DESC LIMIT' );
expect_contains( $query, "ability = 'wpmcp/content-write'", 'filtert nach Werkzeug' );
expect_contains( $query, "operation = 'rejected'", 'nach Ergebnis' );
expect_contains( $query, 'user_id = 42', 'nach Benutzer' );
expect_contains( $query, 'post_id = 12', 'nach Seite' );
expect_contains( $query, "summary LIKE '%50\\\\%\\\\_x\\'%'", 'sucht mit escaptem LIKE' );
expect_contains( $query, 'LIMIT 25 OFFSET 50', 'und blaettert' );
expect_contains( $html, 'Entries are kept forever.', '0 Tage heisst: fuer immer' );
expect_contains( $html, 'Clear filters', 'bietet an, die Filter zu loeschen' );

list( $where, $args ) = WPMCP_Log_Table::where( WPMCP_Log_Table::sanitize_filters( array( 'log_result' => 'saved' ) ) );
check( "WHERE dry_run = 0 AND operation <> '' AND operation <> 'rejected'" === $where && array() === $args, '"Saved" heisst: echt, nicht abgelehnt, mit Operation', $where );
list( $where ) = WPMCP_Log_Table::where( WPMCP_Log_Table::sanitize_filters( array( 'log_result' => "x' OR 1=1" ) ) );
check( '' === $where, 'ein unbekannter Ergebniswert filtert gar nicht', $where );

// The list table's form carries a nonce and the referer; core's list
// screens redirect them out of the address, and so does this one.
$_GET                   = array( 'tab' => 'activity', '_wp_http_referer' => '/wp-admin/tools.php?x=1', '_wpnonce' => 'n', 'log_tool' => 'wpmcp/content-read' );
$_SERVER['REQUEST_URI'] = '/wp-admin/tools.php?' . http_build_query( $_GET );
try {
	wpmcp_admin_load();
	$end = 'fell through';
} catch ( Halt $h ) {
	$end = $h->getMessage();
}
check( 'redirect:/wp-admin/tools.php?tab=activity&log_tool=wpmcp%2Fcontent-read' === $end, 'Nonce und Referer verschwinden aus der Adresse, die Filter bleiben', $end );
$_GET = array( 'tab' => 'activity' );
try {
	wpmcp_admin_load();
	$end = 'fell through';
} catch ( Halt $h ) {
	$end = $h->getMessage();
}
check( 'fell through' === $end, 'ohne sie keine Umleitung', $end );

$GLOBALS['stub']['log'] = array();
unset( $GLOBALS['stub']['options']['wpmcp_log_retention_days'] );
$html = render_case( 'leeres Protokoll', function () {}, 'activity' );
expect_contains( $html, 'Nothing logged yet.', 'sagt, dass noch nichts da ist' );

echo "\n";
if ( 0 === $fail ) {
	echo "\033[32mAdmin-Rendering in Ordnung.\033[0m\n";
	exit( 0 );
}
echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
exit( 1 );
