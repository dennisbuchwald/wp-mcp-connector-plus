<?php
/**
 * Finding every occurrence, with the markup around it.
 *
 * Built after a real task: 31 occurrences of a phone number across 18
 * pages. Five pages had a <br> before an empty tel anchor and one did not,
 * and a change extrapolated from the five would have skipped the sixth
 * while reporting success. The context in the result is what prevents that.
 *
 * Run: php tests/search.php
 *
 * @package wp-mcp-connector-plus
 */

require_once __DIR__ . '/bootstrap.php';

require_once dirname( __DIR__ ) . '/includes/search.php';

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

echo "\n\033[1mTreffer finden\033[0m\n";

// The two real shapes: with and without the <br>.
$with    = '<h1 class="gb-text">weiterhelfen?<br><a href="tel:+4971323696900"></a></h1>';
$without = '<h1 class="gb-text"><mark>Jobs</mark><a href="tel:+4971323696900"></a></h1>';

$offsets = wpmcp_find_offsets( $with, 'tel:+4971323696900', false );
check( 1 === count( $offsets ), 'ein Treffer im Markup' );

$offsets = wpmcp_find_offsets( $with . $with, 'tel:+4971323696900', false );
check( 2 === count( $offsets ), 'zwei Treffer werden beide gefunden' );

$offsets = wpmcp_find_offsets( $with, 'nicht vorhanden', false );
check( 0 === count( $offsets ), 'kein Treffer ist kein Treffer' );

// Overlapping needles must not loop forever.
$offsets = wpmcp_find_offsets( 'aaaa', 'aa', false );
check( 2 === count( $offsets ), 'ueberlappende Suche terminiert' );

echo "\n\033[1mRegulaerer Ausdruck\033[0m\n";

$hay = 'Tel 07132 3696900 und 071323696900 und 07131 3859840';
$offsets = wpmcp_find_offsets( $hay, '07\d{3}\s?\d{6,7}', true );
check( 3 === count( $offsets ), 'drei Schreibweisen einer Nummer', 'gefunden: ' . count( $offsets ) );

echo "\n\033[1mKontext um den Treffer\033[0m\n";

// This is the check the whole tool exists for.
$offset = wpmcp_find_offsets( $with, '<a href="tel:+4971323696900">', false )[0];
$ctx    = wpmcp_context_around( $with, $offset[0], strlen( $offset[1] ), 20 );
check( false !== strpos( $ctx['before'], '<br>' ), 'das <br> davor ist im Kontext sichtbar', var_export( $ctx['before'], true ) );

$offset = wpmcp_find_offsets( $without, '<a href="tel:+4971323696900">', false )[0];
$ctx    = wpmcp_context_around( $without, $offset[0], strlen( $offset[1] ), 20 );
check( false === strpos( $ctx['before'], '<br>' ), 'sein Fehlen ebenso', var_export( $ctx['before'], true ) );
check( false !== strpos( $ctx['before'], '</mark>' ), 'und was stattdessen davor steht' );

// Raw means raw: no trimming, no entity handling.
$raw = "\n<p>  Text &amp; mehr tel:123  </p>";
$off = wpmcp_find_offsets( $raw, 'tel:123', false )[0];
$ctx = wpmcp_context_around( $raw, $off[0], 7, 100 );
check( false !== strpos( $ctx['before'], '&amp;' ), 'Entities bleiben unangetastet' );
check( "\n" === substr( $ctx['before'], 0, 1 ), 'fuehrender Zeilenumbruch bleibt erhalten' );

echo "\n\033[1mSuche im Blockbaum\033[0m\n";

$GLOBALS['posts'] = array();

function get_posts( $args = array() ) { $GLOBALS['last_query'] = $args; return $GLOBALS['posts']; }
function wpmcp_allowed_post_types() { return array( 'page', 'post' ); }
function current_user_can( ...$a ) { return true; }
function wpmcp_log( ...$a ) {}
function get_permalink( $p ) { return 'https://example.test/x/'; }
function get_the_title( $p ) { return 'Stub'; }

class StubPost {
	public $ID = 1;
	public $post_type = 'page';
	public $post_status = 'publish';
	public $post_content = '';
	public function __construct( $content ) { $this->post_content = $content; }
}

$markup = '<!-- wp:generateblocks/text {"uniqueId":"243c9e83"} -->' . $with . '<!-- /wp:generateblocks/text -->';
$hits   = wpmcp_search_in_post( new StubPost( $markup ), 'tel:+4971323696900', false, 30 );

check( 1 === count( $hits ), 'Treffer im Block gefunden', count( $hits ) . ' Treffer' );
check( '0' === $hits[0]['path'], 'mit Blockpfad' );
check( 'generateblocks/text' === $hits[0]['blockName'], 'mit Blocktyp' );
check( '243c9e83' === $hits[0]['uniqueId'], 'mit Instanz-ID' );
check( 'innerHTML' === $hits[0]['in'], 'und der Angabe, wo der Treffer sitzt' );
check( false !== strpos( $hits[0]['context']['before'], '<br>' ), 'Kontext auch hier vollstaendig' );

// A hit inside an attribute must be found too, and marked as such.
$markup = '<!-- wp:acme/button {"url":"tel:+4971323696900"} /-->';
$hits   = wpmcp_search_in_post( new StubPost( $markup ), 'tel:+4971323696900', false, 30 );
check( 1 === count( $hits ), 'Treffer im Attribut gefunden' );
check( 'attrs' === $hits[0]['in'], 'und als Attribut-Treffer gekennzeichnet' );

// The attributes are searched as JSON, and the serializer stores them
// unescaped. Encoded with the defaults, "Müller" became "M\u00fcller" and
// every "/" a "\/": a name with an umlaut or any URL in an attribute was
// never found, and the result said so with a confident zero.
$markup = '<!-- wp:acme/team {"name":"Jörg Müller","url":"https://example.test/team/joerg"} /-->';
$hits   = wpmcp_search_in_post( new StubPost( $markup ), 'Müller', false, 10 );
check( 1 === count( $hits ), 'ein Umlaut im Attribut wird gefunden', count( $hits ) . ' Treffer' );
check( ! empty( $hits ) && false !== strpos( $hits[0]['context']['before'], 'Jörg ' ), 'und der Kontext zeigt den Text, wie er gespeichert ist', var_export( $hits[0]['context']['before'] ?? null, true ) );
$hits = wpmcp_search_in_post( new StubPost( $markup ), 'https://example.test/team/joerg', false, 10 );
check( 1 === count( $hits ), 'eine URL im Attribut ebenso', count( $hits ) . ' Treffer' );

// Nested blocks are reached.
$markup = '<!-- wp:core/group --><div><!-- wp:acme/button {"url":"tel:+49"} /--></div><!-- /wp:core/group -->';
$hits   = wpmcp_search_in_post( new StubPost( $markup ), 'tel:+49', false, 10 );
check( ! empty( $hits ), 'verschachtelte Bloecke werden durchsucht' );
check( '0.0' === $hits[0]['path'], 'mit korrektem verschachteltem Pfad', $hits[0]['path'] ?? '' );

echo "\n\033[1mBlaettern ueber das Limit hinaus\033[0m\n";

// The limit stops at 500 hits. A site with more had no way to the rest.
$many = '';
for ( $n = 0; $n < 7; $n++ ) {
	$many .= '<!-- wp:paragraph --><p>tel:' . $n . '</p><!-- /wp:paragraph -->';
}
$GLOBALS['posts'] = array( new StubPost( $many ) );

$first = wpmcp_search_content( array( 'query' => 'tel:', 'limit' => 3 ) );
check( 3 === count( $first['matches'] ) && true === $first['truncated'], 'erste Seite: 3 Treffer, abgeschnitten' );
check( 3 === ( $first['nextOffset'] ?? null ), 'mit nextOffset', wp_json_encode( $first['nextOffset'] ?? null ) );

$second = wpmcp_search_content( array( 'query' => 'tel:', 'limit' => 3, 'offset' => $first['nextOffset'] ) );
check( '3' === $second['matches'][0]['path'], 'die zweite Seite beginnt beim vierten Treffer', $second['matches'][0]['path'] ?? '' );
check( 3 === $second['offset'], 'und nennt ihren offset' );

$last = wpmcp_search_content( array( 'query' => 'tel:', 'limit' => 3, 'offset' => 6 ) );
check( 1 === count( $last['matches'] ) && false === $last['truncated'] && ! isset( $last['nextOffset'] ), 'die letzte Seite: kein nextOffset' );

echo "\n\033[1mpost_type als Text\033[0m\n";

// content-list and content-create take post_type as a string, so agents
// send it here as well. It was ignored and the search ran site-wide.
wpmcp_search_content( array( 'query' => 'tel:', 'post_type' => 'page' ) );
check( array( 'page' ) === $GLOBALS['last_query']['post_type'], 'post_type: "page" grenzt ein', wp_json_encode( $GLOBALS['last_query']['post_type'] ) );
wpmcp_search_content( array( 'query' => 'tel:', 'post_types' => 'post' ) );
check( array( 'post' ) === $GLOBALS['last_query']['post_type'], 'post_types als Text ebenso' );
wpmcp_search_content( array( 'query' => 'tel:', 'post_types' => array( 'page', 'shop_order' ) ) );
check( array( 'page' ) === $GLOBALS['last_query']['post_type'], 'eine Liste bleibt auf den Bereich des Connectors begrenzt' );
wpmcp_search_content( array( 'query' => 'tel:', 'post_status' => 'publish' ) );
check( array( 'publish' ) === $GLOBALS['last_query']['post_status'], 'post_status als Text' );

echo "\n";
if ( 0 === $fail ) {
	echo "\033[32mSuche in Ordnung.\033[0m\n";
	exit( 0 );
}
echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
exit( 1 );
