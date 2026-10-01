<?php
/**
 * A cut never lands inside a character.
 *
 * Every window and excerpt the connector returns is cut by byte count:
 * the preview and live-fetch windows, the context around a search hit,
 * the confirmation after a patch, the snippets of a live check. A German
 * page has an umlaut every few words, and a cut through its second byte
 * leaves a string that is not UTF-8. json_encode() then fails on the whole
 * response, so the agent got nothing at all, or, in the windows, a broken
 * character at the end of one part and another at the start of the next.
 *
 * Run: php tests/utf8-cuts.php
 *
 * @package wp-mcp-connector-plus
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/content.php';
require_once dirname( __DIR__ ) . '/includes/search.php';
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

function utf8( $text ) {
	return mb_check_encoding( (string) $text, 'UTF-8' );
}

echo "\n\033[1mDer Hilfsbaustein\033[0m\n";

check( function_exists( 'wpmcp_utf8_boundary' ), 'wpmcp_utf8_boundary() gibt es' );
if ( function_exists( 'wpmcp_utf8_boundary' ) ) {
	$t = 'aü😀b'; // a=0, ü=1..2, 😀=3..6, b=7.
	check( 1 === wpmcp_utf8_boundary( $t, 2 ), 'mitten im ü geht es zum Anfang des ü' );
	check( 3 === wpmcp_utf8_boundary( $t, 3 ), 'eine Zeichengrenze bleibt, wo sie ist' );
	check( 3 === wpmcp_utf8_boundary( $t, 6 ), 'mitten im Emoji zum Anfang des Emoji' );
	check( 8 === wpmcp_utf8_boundary( $t, 8 ), 'das Ende ist eine Grenze' );
	check( 0 === wpmcp_utf8_boundary( $t, 0 ), 'der Anfang auch' );
	check( 2 === wpmcp_utf8_boundary( "ab\x80\x80\x80\x80c", 5 ), 'kaputtes UTF-8: hoechstens drei Schritte zurueck' );
}

echo "\n\033[1mFenster in content-preview und content-fetch-live\033[0m\n";

// The cut at 100 falls on the second byte of the ü.
$umlaut = str_repeat( 'a', 99 ) . 'ü' . str_repeat( 'b', 50 );
$s      = wpmcp_slice_text( $umlaut, 100 );
check( utf8( $s['html'] ), 'ein Umlaut an der Grenze wird nicht zerschnitten', bin2hex( substr( $s['html'], -2 ) ) );
check( false !== wp_json_encode( $s ), 'die Antwort laesst sich als JSON ausliefern' );
check( 99 === ( $s['nextOffset'] ?? null ), 'und das naechste Fenster beginnt beim Umlaut', var_export( $s['nextOffset'] ?? null, true ) );

$emoji = str_repeat( 'a', 98 ) . '😀' . str_repeat( 'b', 50 );
$s     = wpmcp_slice_text( $emoji, 100 );
check( utf8( $s['html'] ), 'ein Emoji an der Grenze ebenso' );
check( 98 === ( $s['nextOffset'] ?? null ), 'es wandert ganz ins naechste Fenster', var_export( $s['nextOffset'] ?? null, true ) );

// A caller that does its own arithmetic may send an offset inside a
// character. It starts at that character instead of mid-way.
$s = wpmcp_slice_text( $umlaut, 100, 100 );
check( utf8( $s['html'] ), 'ein Offset mitten im Zeichen liefert gueltiges UTF-8' );
check( 99 === $s['offset'], 'und meldet, wo das Fenster wirklich beginnt', var_export( $s['offset'], true ) );

foreach ( array( 'Umlaute' => str_repeat( 'Grüße aus Köln, ', 40 ), 'Emoji' => str_repeat( 'Hallo 👋🏽 Welt ', 40 ) ) as $label => $text ) {
	foreach ( array( 7, 33, 100 ) as $window ) {
		$walked = '';
		$at     = 0;
		$guard  = 0;
		$valid  = true;
		do {
			$s       = wpmcp_slice_text( $text, $window, $at );
			$valid   = $valid && utf8( $s['html'] );
			$walked .= $s['html'];
			$at      = $s['nextOffset'] ?? null;
		} while ( null !== $at && ++$guard < 1000 );
		check( $valid && $walked === $text, "{$label}, Fenster {$window}: jedes Fenster gueltig, zusammen der ganze Text" );
	}
}

echo "\n\033[1mKontext eines Suchtreffers\033[0m\n";

$hay = str_repeat( 'ä', 50 ) . 'TREFFER' . str_repeat( 'ö', 50 );
$hit = wpmcp_find_offsets( $hay, 'TREFFER', false )[0];
$ctx = wpmcp_context_around( $hay, $hit[0], strlen( $hit[1] ), 5 );
check( utf8( $ctx['before'] ), 'der Kontext davor zerschneidet kein ä', bin2hex( $ctx['before'] ) );
check( utf8( $ctx['after'] ), 'der danach kein ö', bin2hex( $ctx['after'] ) );
check( 'TREFFER' === $ctx['match'], 'der Treffer selbst bleibt' );
check( false !== wp_json_encode( $ctx ), 'und alles laesst sich ausliefern' );

echo "\n\033[1mBestaetigung nach patch_html\033[0m\n";

// Three-byte characters, so that the cut 80 bytes before the new text
// lands inside one.
$html   = '<p>' . str_repeat( '€', 60 ) . 'NEU' . str_repeat( ' Grüße', 60 ) . '</p>';
$blocks = parse_blocks( '<!-- wp:paragraph -->' . $html . '<!-- /wp:paragraph -->' );
$conf   = wpmcp_patch_confirmations( $blocks, array( array( 'op' => 'patch_html', 'path' => '0', 'find' => 'ALT', 'replace' => 'NEU' ) ) );
check( 1 === count( $conf ), 'eine Bestaetigung kommt' );
check( ! empty( $conf ) && utf8( $conf[0]['now'] ), 'ohne zerschnittenes Zeichen', empty( $conf ) ? '' : bin2hex( substr( $conf[0]['now'], 0, 8 ) ) );
check( false !== wp_json_encode( $conf ), 'und auslieferbar' );

$conf = wpmcp_patch_confirmations( $blocks, array( array( 'op' => 'patch_html', 'path' => '0', 'find' => 'ALT', 'replace' => 'fehlt' ) ) );
check( ! empty( $conf ) && utf8( $conf[0]['now'] ), 'auch der Anfang des Blocks, wenn der neue Text nicht vorkommt' );

echo "\n\033[1mFundstellen in content-fetch-live\033[0m\n";

$page = '<main>' . str_repeat( '€', 120 ) . 'Telefon' . str_repeat( '😀', 80 ) . '</main>';
$find = wpmcp_find_in_page( $page, 'telefon' );
check( 1 === $find['count'], 'der Treffer wird gefunden' );
check( ! empty( $find['snippets'] ) && utf8( $find['snippets'][0] ), 'und sein Ausschnitt ist gueltiges UTF-8', empty( $find['snippets'] ) ? '' : bin2hex( substr( $find['snippets'][0], 0, 4 ) ) );
check( false !== wp_json_encode( $find ), 'auslieferbar' );

echo "\n";
if ( 0 === $fail ) {
	echo "\033[32mSchnitte in Ordnung.\033[0m\n";
	exit( 0 );
}
echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
exit( 1 );
