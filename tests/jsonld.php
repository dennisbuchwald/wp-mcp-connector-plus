<?php
/**
 * Structured data, written without opening the door to scripts.
 *
 * Every SEO-minded article carries JSON-LD, and it could not be written:
 * it is a script tag, and scripts need unfiltered_html. Browsers do not
 * execute JSON-LD, though. What makes it dangerous is only a "</script>"
 * inside the data, closing the tag early — so the data is re-encoded
 * before storing, and anything that is not plainly JSON-LD stays refused.
 *
 * Run: php tests/jsonld.php
 *
 * @package wp-mcp-connector-plus
 */

require_once __DIR__ . '/bootstrap.php';

function wp_kses_post( $content ) {
	return preg_replace( '#<script\b[^>]*>.*?</script>#is', '', (string) $content );
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

$clean  = '<!-- wp:paragraph --><p>Text</p><!-- /wp:paragraph -->';
$jsonld = '<!-- wp:html --><script type="application/ld+json">{"@context":"https://schema.org","@type":"FAQPage"}</script><!-- /wp:html -->';

echo "\n\033[1mStrukturierte Daten gehen durch\033[0m\n";

$i = wpmcp_kses_impact( $clean, $clean . $jsonld );
check( false === $i['introduces'], 'neues JSON-LD gilt nicht als eingeschleustes Script' );
check( true === wpmcp_should_preserve_markup( $i ), 'und wird am Filter vorbei gespeichert', 'sonst entfernt kses es beim Speichern still' );
check( array() === wpmcp_unsafe_additions( $clean, $clean . $jsonld ), 'und der Guard fuer Dynamic Data sieht es ebenfalls nicht als Gefahr' );
check( $jsonld === wpmcp_normalize_jsonld( $jsonld ), 'sicheres JSON-LD bleibt Byte fuer Byte gleich', 'sonst entstuende bei jedem Speichern ein Diff' );

$single = "<script type='application/ld+json'>{\"a\":1}</script>";
check( false === wpmcp_kses_impact( '', $single )['introduces'], 'auch mit einfachen Anfuehrungszeichen am type' );

echo "\n\033[1mDer einzige Ausweg wird verschlossen\033[0m\n";

// A "<" in the data is the start of every way out of the tag.
$lt   = '<script type="application/ld+json">{"name":"a<b"}</script>';
$safe = wpmcp_normalize_jsonld( $lt );
check( false === strpos( substr( $safe, 35 ), '<b' ), 'ein < in den Daten wird kodiert', $safe );
check( false !== strpos( $safe, '<' ), 'als <, was jeder JSON-Parser zurueckliest' );
check( array( 'name' => 'a<b' ) === json_decode( trim( preg_replace( '#</?script[^>]*>#', '', $safe ) ), true ), 'die Daten bleiben inhaltlich gleich' );

// "</script>" inside the data closes the tag early: what is left is not JSON.
$breakout = '<script type="application/ld+json">{"x":"</script><script>alert(1)</script>"}</script>';
check( true === wpmcp_kses_impact( '', $breakout )['introduces'], 'ein Ausbruch aus dem Tag wird abgelehnt' );

echo "\n\033[1mWas weiter ein Script ist\033[0m\n";

check( true === wpmcp_kses_impact( '', '<script type="text/javascript">x()</script>' )['introduces'], 'ein anderer type' );
check( true === wpmcp_kses_impact( '', '<script type="application/ld+json" onload="x()">{}</script>' )['introduces'], 'ein zusaetzliches Attribut am Tag' );
check( true === wpmcp_kses_impact( '', '<script type="application/ld+json">das ist kein JSON</script>' )['introduces'], 'Inhalt, der kein JSON ist' );
check( true === wpmcp_kses_impact( '', '<script type="application/ld+json"></script>' )['introduces'], 'ein leerer Block' );

echo "\n";
if ( 0 === $fail ) {
	echo "\033[32mJSON-LD in Ordnung.\033[0m\n";
	exit( 0 );
}
echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
exit( 1 );
