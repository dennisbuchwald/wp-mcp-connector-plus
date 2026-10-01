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

echo "\n\033[1mEinmal rendern\033[0m\n";

// The preview rendered every page twice: once inside the render check,
// whose output was thrown away, and once more for the answer. A block
// that echoes instead of returning also printed straight into the JSON
// response the second time, outside any buffer.
if ( ! function_exists( 'do_shortcode' ) ) {
	function do_shortcode( $html ) { return $html; }
}
$page = (object) array( 'ID' => 5, 'post_content' => '<!-- wp:heading --><h2>Titel</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Text</p><!-- /wp:paragraph -->' );
$GLOBALS['dbw_do_blocks_calls'] = 0;
$out = wpmcp_render_post_html( $page );
check( ! is_wp_error( $out ) && false !== strpos( $out['html'], '<p>Text</p>' ), 'die Vorschau liefert das gerenderte HTML' );
check( ! is_wp_error( $out ) && 'Titel' === ( $out['headings'][0]['text'] ?? null ), 'mit Ueberschriften' );
check( 1 === $GLOBALS['dbw_do_blocks_calls'], 'und rendert die Seite genau einmal', $GLOBALS['dbw_do_blocks_calls'] . ' Mal do_blocks' );

check( ! is_wp_error( $out ) && ! isset( $out['debug'] ), 'ohne WP_DEBUG keine Messwerte' );
$GLOBALS['dbw_filters']['wpmcp_debug_timings'][] = '__return_true';
$out = wpmcp_render_post_html( $page );
array_pop( $GLOBALS['dbw_filters']['wpmcp_debug_timings'] );
check( ! is_wp_error( $out ) && isset( $out['debug']['timings']['render'] ) && ( $out['debug']['peakMemory'] ?? 0 ) > 0, 'mit: Renderzeit und Speicher, fuer content-preview', wp_json_encode( $out['debug'] ?? null ) );

$smoke = wpmcp_render_smoke_test( '<p>x</p>' );
check( '<p>x</p>' === ( $smoke['html'] ?? null ), 'der Render-Test gibt sein Ergebnis zurueck, statt es wegzuwerfen' );

echo "\n";
if ( 0 === $fail ) {
	echo "\033[32mLange Ausgaben in Ordnung.\033[0m\n";
	exit( 0 );
}
echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
exit( 1 );
