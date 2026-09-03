<?php
/**
 * A long page must not come back quietly halved.
 *
 * content-preview and content-fetch-live both cap what they return. The
 * cap was a comment buried in the middle of the markup — easy to miss,
 * and impossible to act on. A privacy policy was read, cut at 60000
 * bytes, and judged on the part that arrived.
 *
 * Being cut off is now a field, and the rest is reachable.
 *
 * Run: php tests/long-output.php
 *
 * @package wp-mcp-connector-plus
 */

require_once __DIR__ . '/bootstrap.php';
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

$long = str_repeat( 'x', 250 );

echo "\n\033[1mAbschneiden wird gemeldet\033[0m\n";

$s = wpmcp_slice_text( $long, 100 );
check( 100 === strlen( $s['html'] ), 'schneidet auf die Fenstergroesse' );
check( 250 === $s['bytes'], 'nennt die volle Laenge' );
check( true === $s['truncated'], 'sagt, dass abgeschnitten wurde' );
check( 100 === $s['nextOffset'], 'und wo es weitergeht' );
check( ! empty( $s['note'] ), 'im Klartext dazu', var_export( $s['note'] ?? null, true ) );
check( false === strpos( $s['html'], 'truncated by' ), 'ohne Kommentar im Inhalt', 'der war vorher die einzige Spur' );

echo "\n\033[1mDen Rest holen\033[0m\n";

$s = wpmcp_slice_text( $long, 100, 100 );
check( 100 === $s['offset'], 'das zweite Fenster kennt seinen Anfang' );
check( 200 === $s['nextOffset'], 'und den naechsten' );

$s = wpmcp_slice_text( $long, 100, 200 );
check( 50 === strlen( $s['html'] ), 'das letzte Fenster ist so lang wie der Rest' );
check( false === $s['truncated'], 'und meldet sich nicht mehr als abgeschnitten' );
check( ! isset( $s['nextOffset'] ), 'ohne weiteren Offset' );

// Following nextOffset to the end must reproduce the text exactly.
$walked = '';
$at     = 0;
$guard  = 0;
do {
	$s       = wpmcp_slice_text( $long, 100, $at );
	$walked .= $s['html'];
	$at      = $s['nextOffset'] ?? null;
} while ( null !== $at && ++$guard < 20 );

check( $long === $walked, 'Fenster fuer Fenster ergibt wieder den ganzen Text' );
check( $guard < 20, 'und laeuft dabei nicht im Kreis' );

echo "\n\033[1mRandfaelle\033[0m\n";

$s = wpmcp_slice_text( 'kurz', 100 );
check( false === $s['truncated'], 'kurzer Text wird nicht angefasst' );
check( 'kurz' === $s['html'], 'und kommt unveraendert zurueck' );

$s = wpmcp_slice_text( '', 100 );
check( '' === $s['html'] && false === $s['truncated'], 'leerer Text ist kein Sonderfall' );

$s = wpmcp_slice_text( $long, 100, 9999 );
check( '' === $s['html'], 'ein Offset hinter dem Ende liefert nichts' );
check( false === $s['truncated'], 'und behauptet nicht, es gaebe noch mehr' );

$s = wpmcp_slice_text( $long, 100, -50 );
check( 0 === $s['offset'], 'ein negativer Offset faengt vorne an' );

$s = wpmcp_slice_text( $long, 250 );
check( false === $s['truncated'], 'genau passend ist nicht abgeschnitten' );

echo "\n";
if ( 0 === $fail ) {
	echo "\033[32mLange Ausgaben in Ordnung.\033[0m\n";
	exit( 0 );
}
echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
exit( 1 );
