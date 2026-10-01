<?php
/**
 * Signed preview links: valid for one post, for a while, for this purpose.
 *
 * The link is the only thing standing between a draft and the internet,
 * and until 0.19 nothing tested it. The signature is an HMAC with the
 * site's auth salt, which other code signs with too, so the signed data
 * carries a purpose label: without it, any HMAC over "<id>|<time>" made
 * with that salt would have opened a draft.
 *
 * Run: php tests/preview.php
 *
 * @package wp-mcp-connector-plus
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['filters'] = array();
$GLOBALS['actions'] = array();

function add_filter( $tag, $cb, $priority = 10, $args = 1 ) { $GLOBALS['filters'][ $tag ][] = $cb; return true; }
function add_action( $tag, $cb, $priority = 10, $args = 1 ) { $GLOBALS['actions'][ $tag ][] = $cb; return true; }
function wp_salt( $scheme = 'auth' ) { return 'test-auth-salt'; }
function home_url( $path = '' ) { return 'https://example.test' . $path; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function get_post_type( $id ) { return 'page'; }
function wp_unslash( $v ) { return $v; }

require_once dirname( __DIR__ ) . '/includes/preview.php';

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
 * Open a URL against the gate and say whether it let the draft through.
 */
function opens( $query ) {
	$GLOBALS['filters'] = array();
	$GLOBALS['actions'] = array();
	$_GET               = $query;
	wpmcp_maybe_allow_preview();
	return ! empty( $GLOBALS['filters']['posts_results'] );
}

function query_of( $url ) {
	parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
	return $q;
}

echo "\n\033[1mEin gueltiger Link\033[0m\n";

$link = wpmcp_preview_url( 42 );
$q    = query_of( $link['url'] );

check( '42' === $q['p'] && '1' === $q[ WPMCP_PREVIEW_PARAM ], 'traegt Beitrag und Kennung' );
check( abs( $link['expires'] - ( time() + WPMCP_PREVIEW_TTL ) ) <= 2, 'laeuft nach 15 Minuten ab' );
check( opens( $q ), 'oeffnet den Entwurf' );
check( ! empty( $GLOBALS['actions']['wp_head'] ), 'und haelt Suchmaschinen fern', 'noindex' );

echo "\n\033[1mWas nicht oeffnet\033[0m\n";

check( ! opens( array() ), 'eine Seite ohne Vorschau-Parameter', 'der Normalfall kostet ein isset()' );

$other      = $q;
$other['p'] = '43';
check( ! opens( $other ), 'derselbe Token fuer einen anderen Beitrag' );

$later        = $q;
$later['exp'] = (string) ( (int) $q['exp'] + 86400 );
check( ! opens( $later ), 'ein verlaengertes Ablaufdatum' );

$bent        = $q;
$bent['tok'] = substr( $q['tok'], 0, -1 ) . ( '0' === substr( $q['tok'], -1 ) ? '1' : '0' );
check( ! opens( $bent ), 'ein veraenderter Token' );

$empty        = $q;
$empty['tok'] = '';
check( ! opens( $empty ), 'ein leerer Token' );

$expired = time() - 10;
$old     = array(
	'p'                  => '42',
	WPMCP_PREVIEW_PARAM  => '1',
	'exp'                => (string) $expired,
	'tok'                => wpmcp_preview_token( 42, $expired ),
);
check( ! opens( $old ), 'ein abgelaufener Link, auch korrekt signiert' );

// The same salt signs other things. An HMAC over "<id>|<time>" without the
// purpose label is what the plugin itself used to issue, and what any
// other code signing a pair of numbers with this salt would produce.
$exp     = time() + 600;
$foreign = array(
	'p'                  => '42',
	WPMCP_PREVIEW_PARAM  => '1',
	'exp'                => (string) $exp,
	'tok'                => hash_hmac( 'sha256', '42|' . $exp, wp_salt( 'auth' ) ),
);
check( ! opens( $foreign ), 'eine Signatur ohne Zweck-Kennung', 'bis 0.18 genau das Format der Links' );

check(
	wpmcp_preview_token( 4, 21 ) !== wpmcp_preview_token( 42, 1 ),
	'"4|21" und "42|1" ergeben verschiedene Tokens',
	'die Trennzeichen halten die Zahlen auseinander'
);

echo "\n\033[1mWas der Link zeigt\033[0m\n";

opens( $q );
$gate = $GLOBALS['filters']['posts_results'][0] ?? null;

class StubQuery {
	public $main;
	public function __construct( $main ) { $this->main = $main; }
	public function is_main_query() { return $this->main; }
}
$GLOBALS['stub_post'] = null;
function get_post( $id ) { return $GLOBALS['stub_post']; }

$GLOBALS['stub_post'] = (object) array( 'ID' => 42, 'post_status' => 'draft' );
$out = $gate( array(), new StubQuery( true ) );
check( 1 === count( $out ) && 'publish' === $out[0]->post_status, 'den Entwurf, als waere er veroeffentlicht' );

check( array() === $gate( array(), new StubQuery( false ) ), 'nur in der Hauptabfrage', 'Widgets und Menues bleiben, wie sie sind' );

$GLOBALS['stub_post'] = (object) array( 'ID' => 42, 'post_status' => 'trash' );
check( array() === $gate( array(), new StubQuery( true ) ), 'nichts aus dem Papierkorb' );

$GLOBALS['stub_post'] = (object) array( 'ID' => 42, 'post_status' => 'private' );
check( array() === $gate( array(), new StubQuery( true ) ), 'nichts Privates' );

echo "\n";
if ( 0 === $fail ) {
	echo "\033[32mVorschau-Links in Ordnung.\033[0m\n";
	exit( 0 );
}
echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
exit( 1 );
