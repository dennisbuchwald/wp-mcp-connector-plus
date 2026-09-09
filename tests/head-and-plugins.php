<?php
/**
 * Confirming that an SEO field reaches the page.
 *
 * Writing rank_math_title and reading it back proves only that the value
 * was stored. content-preview renders the body, so it cannot answer
 * whether the SEO plugin puts the title in the head — and that is the
 * question. The delivered page can answer it, so its head is parsed.
 *
 * Run: php tests/head-and-plugins.php
 *
 * @package wp-mcp-connector-plus
 */

require_once __DIR__ . '/bootstrap.php';
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

$page = '<!doctype html><html><head>'
	. '<title>Website f&uuml;r Handwerker | dbw media</title>'
	. '<meta name="description" content="Kurz, knapp, mit &quot;Zitat&quot;.">'
	. '<meta name="robots" content="index, follow">'
	. '<meta property="og:title" content="Website für Handwerker">'
	. '<meta property="og:image" content="https://example.test/bild.jpg">'
	. '<meta name="generator" content="WordPress 6.9">'
	. '<link rel="canonical" href="https://example.test/handwerker/">'
	. '<script type="application/ld+json">{"@type":"Service"}</script>'
	. '</head><body><p>Inhalt</p></body></html>';

echo "\n\033[1mDer Kopf der ausgelieferten Seite\033[0m\n";

$head = wpmcp_head_summary( $page );

check( 'Website für Handwerker | dbw media' === $head['title'], 'der Titel, Entities aufgeloest', var_export( $head['title'] ?? null, true ) );
check( 'Kurz, knapp, mit "Zitat".' === $head['description'], 'die Description ebenfalls', var_export( $head['description'] ?? null, true ) );
check( 'index, follow' === $head['robots'], 'robots' );
check( 'https://example.test/handwerker/' === $head['canonical'], 'die Canonical' );
check( 'Website für Handwerker' === $head['og:title'], 'og:title kommt ueber property=' );
check( 1 === $head['jsonLdBlocks'], 'und die Anzahl der JSON-LD-Bloecke' );
check( ! isset( $head['generator'] ), 'was nicht zur Arbeit gehoert, bleibt draussen' );

echo "\n\033[1mWenn nichts davon da ist\033[0m\n";

$bare = wpmcp_head_summary( '<html><body>Nur Text</body></html>' );
check( ! isset( $bare['title'] ), 'ohne Kopf kein Titel' );
check( 0 === $bare['jsonLdBlocks'], 'und null JSON-LD statt eines Fehlers' );

// A maintenance page has a head of its own — it must not read as the page.
$holding = wpmcp_head_summary( '<html><head><title>Wartungsmodus</title></head><body></body></html>' );
check( 'Wartungsmodus' === $holding['title'], 'eine Wartungsseite liefert ihren eigenen Titel', 'deshalb steht der Hinweis in der Werkzeugbeschreibung' );

// Only the head, never the body: a page quoting a meta tag in its text
// must not be reported as having it.
$tricky = '<html><head><title>Echt</title></head><body><meta name="description" content="Aus dem Text"></body></html>';
check( ! isset( wpmcp_head_summary( $tricky )['description'] ), 'ein Meta-Tag im Body zaehlt nicht' );

echo "\n";
if ( 0 === $fail ) {
	echo "\033[32mKopf-Auswertung in Ordnung.\033[0m\n";
	exit( 0 );
}
echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
exit( 1 );
