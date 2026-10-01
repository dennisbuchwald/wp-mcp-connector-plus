<?php
/**
 * content-fetch-live fetches this site's own public pages, nothing else.
 *
 * The tool makes the server request a URL. That URL is a permalink, but a
 * permalink can redirect anywhere, and a draft or a password-protected
 * page has no public version at all: fetching one returns a login screen
 * or a password form, which reads like a broken page.
 *
 * Run: php tests/fetch-live.php
 *
 * @package wp-mcp-connector-plus
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['posts']     = array();
$GLOBALS['responses'] = array();
$GLOBALS['requests']  = array();

function get_post( $id ) { return $GLOBALS['posts'][ (int) $id ] ?? null; }
function current_user_can( ...$a ) { return true; }
function get_post_types( $args = array(), $output = 'names' ) {
	$page = (object) array( 'name' => 'page', 'public' => true );
	return 'names' === $output ? array( 'page' ) : array( 'page' => $page );
}
function post_type_exists( $type ) { return 'page' === $type; }
function wpmcp_pattern_access() { return 'read'; }
function wpmcp_extra_post_types() { return array(); }
function wpmcp_log( $ability, $data = array() ) {}
function get_permalink( $post ) { return 'https://example.test/' . $post->post_name . '/'; }
function add_query_arg( $key, $value, $url ) { return $url . '?' . $key . '=' . $value; }
function home_url( $path = '' ) { return 'https://example.test' . $path; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }

function wp_remote_get( ...$a ) {
	throw new RuntimeException( 'wp_remote_get used; it follows redirects anywhere and reaches private addresses' );
}
function wp_safe_remote_get( $url, $args ) {
	$GLOBALS['requests'][] = array( $url, $args );
	return array_shift( $GLOBALS['responses'] ) ?? new WP_Error( 'http_request_failed', 'no scripted response' );
}
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }
function wp_remote_retrieve_response_code( $r ) { return $r['code'] ?? 0; }
function wp_remote_retrieve_header( $r, $name ) { return $r['headers'][ $name ] ?? ''; }

function respond( $code, $body = '', array $headers = array() ) {
	$GLOBALS['responses'][] = array( 'code' => $code, 'body' => $body, 'headers' => $headers );
}

require_once __DIR__ . '/kses-stub.php';
require_once dirname( __DIR__ ) . '/includes/content.php';
require_once dirname( __DIR__ ) . '/includes/cache.php';

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

function post( $id, $status, $password = '' ) {
	$GLOBALS['posts'][ $id ] = (object) array(
		'ID'            => $id,
		'post_type'     => 'page',
		'post_status'   => $status,
		'post_password' => $password,
		'post_name'     => 'seite-' . $id,
		'post_content'  => '',
	);
}

function fetch( $id ) {
	$GLOBALS['requests'] = array();
	try {
		return wpmcp_fetch_live( $id, false );
	} catch ( RuntimeException $e ) {
		return new WP_Error( 'test', $e->getMessage() );
	}
}

$page = '<html><head><title>T</title></head><body><main><p>Hallo</p></main></body></html>';

echo "\n\033[1mNur oeffentliche Seiten\033[0m\n";

post( 1, 'publish' );
post( 2, 'draft' );
post( 3, 'publish', 'geheim' );
post( 4, 'private' );

foreach ( array( 2 => 'ein Entwurf', 3 => 'eine passwortgeschuetzte Seite', 4 => 'eine private Seite' ) as $id => $label ) {
	$out = fetch( $id );
	check(
		is_wp_error( $out ) && 'wpmcp_not_public' === $out->get_error_code() && array() === $GLOBALS['requests'],
		"{$label} wird gar nicht erst abgerufen",
		is_wp_error( $out ) ? $out->get_error_code() : 'abgerufen'
	);
}
$out = fetch( 2 );
check( is_wp_error( $out ) && false !== strpos( $out->get_error_message(), 'content-preview' ), 'die Meldung verweist auf content-preview' );

respond( 200, $page );
$out = fetch( 1 );
check( ! is_wp_error( $out ) && 200 === $out['httpStatus'], 'eine veroeffentlichte Seite schon', is_wp_error( $out ) ? $out->get_error_message() : '' );
list( $url, $args ) = $GLOBALS['requests'][0] ?? array( '', array() );
check( 0 === ( $args['redirection'] ?? null ), 'ohne automatisches Weiterleiten' );
check( 10 === ( $args['timeout'] ?? null ), 'mit 10 Sekunden Zeitlimit', 'bisher 20' );
check( ! isset( $out['hint'] ), 'und ohne Hinweis, wenn alles in Ordnung ist' );

echo "\n\033[1mWeiterleitungen nur innerhalb der Seite\033[0m\n";

respond( 301, '', array( 'location' => 'https://example.test/neu/' ) );
respond( 200, $page );
$out = fetch( 1 );
check( ! is_wp_error( $out ) && 2 === count( $GLOBALS['requests'] ), 'eine Weiterleitung auf derselben Seite wird verfolgt' );
check( ! is_wp_error( $out ) && 'https://example.test/neu/' === $out['url'], 'und die Antwort nennt die Adresse, die geantwortet hat' );

respond( 302, '', array( 'location' => 'http://169.254.169.254/latest/meta-data/' ) );
$out = fetch( 1 );
check( ! is_wp_error( $out ) && 1 === count( $GLOBALS['requests'] ), 'eine Weiterleitung auf einen fremden Host wird nicht verfolgt' );
check( ! is_wp_error( $out ) && false !== strpos( $out['hint'] ?? '', '169.254.169.254' ), 'aber im Hinweis genannt' );

for ( $i = 0; $i < 6; $i++ ) {
	respond( 302, '', array( 'location' => 'https://example.test/schleife/' ) );
}
$out = fetch( 1 );
check( count( $GLOBALS['requests'] ) <= 4, 'eine Schleife endet nach wenigen Spruengen', count( $GLOBALS['requests'] ) . ' Anfragen' );
check( ! is_wp_error( $out ) && false !== strpos( $out['hint'] ?? '', 'loop' ), 'mit Hinweis auf die Schleife' );
$GLOBALS['responses'] = array();

echo "\n\033[1mHinweise bei Fehlerstatus\033[0m\n";

respond( 401, 'Unauthorized' );
$out = fetch( 1 );
check( ! is_wp_error( $out ) && false !== strpos( $out['hint'] ?? '', 'anonymous visitor' ), '401: erklaert, dass etwas vor der Seite steht' );
respond( 403, 'Forbidden' );
$out = fetch( 1 );
check( ! is_wp_error( $out ) && false !== strpos( $out['hint'] ?? '', 'firewall' ), '403: ebenso' );
respond( 503, 'down' );
$out = fetch( 1 );
check( ! is_wp_error( $out ) && false !== strpos( $out['hint'] ?? '', 'server error' ), '5xx: verweist auf content-preview und das Fehlerlog' );

echo "\n\033[1mFenstergroesse\033[0m\n";

// 200000 bytes per window was more than an agent's context takes in one
// piece; content-preview already answered in windows of 60000. Both tools
// now cut the same way.
$long = '<html><body>' . str_repeat( 'x', 70000 ) . '</body></html>';
respond( 200, $long );
$out = fetch( 1 );
check( ! is_wp_error( $out ) && true === ( $out['truncated'] ?? null ), 'eine Seite mit 70 KB kommt nicht in einem Stueck', wp_json_encode( $out['truncated'] ?? null ) );
check( ! is_wp_error( $out ) && 60000 === ( $out['nextOffset'] ?? null ), 'das naechste Fenster beginnt bei 60000', wp_json_encode( $out['nextOffset'] ?? null ) );

echo "\n\033[1mWas die Cache-Leerung meldet\033[0m\n";

// clean_post_cache() clears one post's entries. "flushed" read as if the
// whole object cache had been emptied, which nothing here does.
function wp_cache_flush() { throw new RuntimeException( 'the whole object cache must not be flushed' ); }
function clean_post_cache( $id ) { $GLOBALS['cleaned'][] = $id; }
function wp_using_ext_object_cache() { return true; }
function has_action( $tag ) { return false; }
$purged = wpmcp_purge_caches( 1 );
check( 'post cache cleared' === $purged['object'], 'mit persistentem Objekt-Cache: "post cache cleared"', $purged['object'] );
check( array( 1 ) === ( $GLOBALS['cleaned'] ?? null ), 'und geleert wird nur der Beitrag' );

echo "\n";
if ( 0 === $fail ) {
	echo "\033[32mLive-Abruf in Ordnung.\033[0m\n";
	exit( 0 );
}
echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
exit( 1 );
