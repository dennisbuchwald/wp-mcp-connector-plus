<?php
/**
 * The markup guard on single strings rather than blocks.
 *
 * Elementor keeps a page as element settings, and an HTML widget's markup
 * is one string. wpmcp_unstable_strings() applies the block rule to such
 * strings: a changed string must survive kses, or keep only what the
 * stored page already holds, at least as often. Identity is by key, so a
 * copy is a second occurrence. The block path is untouched by it
 * (tests/markup-guard.php).
 *
 * Run: php tests/markup-guard-strings.php
 *
 * @package wp-mcp-connector-plus
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/kses-stub.php';
require_once dirname( __DIR__ ) . '/includes/tree.php';
require_once dirname( __DIR__ ) . '/includes/markup-guard.php';

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

const SLIDER = '<script>document.querySelectorAll(".slide").forEach(function(s){s.hidden=false;});</script>';

echo "\n\033[1mDer Waechter auf Zeichenketten\033[0m\n";

$kept = null;
check( array() === wpmcp_unstable_strings( array( 'a:html' => '<p>x</p>' . SLIDER ), array( 'a:html' => '<p>x</p>' . SLIDER ), $kept ) && array() === $kept, 'unveraendert an derselben Stelle: nichts zu pruefen' );
$refused = wpmcp_unstable_strings( array( 'b:html' => SLIDER ), array( 'a:html' => SLIDER ) );
check( empty( $refused ), 'von a nach b verschoben: geht' );
$refused = wpmcp_unstable_strings( array( 'a:html' => SLIDER, 'b:html' => SLIDER ), array( 'a:html' => SLIDER ) );
check( 1 === count( $refused ) && 'b:html' === $refused[0]['path'] && false === reset( $refused[0]['fragments'] )['inThis'], 'kopiert: die Kopie wird abgelehnt und nicht als "schon in diesem Element" bezeichnet' );
check( 0 === strpos( wpmcp_unstable_summary( $refused, 'element' ), 'element b:html' ), 'die Zusammenfassung spricht von Elementen' );
check( 0 === strpos( wpmcp_unstable_summary( $refused ), 'block b:html' ), 'und ohne Angabe weiter von Bloecken' );

$refused = wpmcp_unstable_strings( array( 'a:html' => '<p>Neu<script>x()</script></p>' ), array( 'a:html' => '<p>Alt</p>' ) );
check( 1 === count( $refused ) && 0 === reset( $refused[0]['fragments'] )['before'], 'ein neues Skript: abgelehnt, vorher nicht da' );
$refused = wpmcp_unstable_strings( array( 'a:html' => '<p>Neu</p>' . SLIDER ), array( 'a:html' => '<p>Alt</p>' . SLIDER ), $kept );
check( array() === $refused && array( SLIDER ) === $kept, 'Text neben einem vorhandenen Skript geaendert: geht, das Skript wird als behalten genannt' );
$refused = wpmcp_unstable_strings( array( 'a:html' => '<p onclick="x()">Neu</p>' ), array( 'a:html' => '<p onclick="x()">Alt</p>' ) );
check( array() === $refused, 'ein vorhandenes onclick am selben Element bleibt' );
$refused = wpmcp_unstable_strings( array( 'a:html' => '<p onclick="y()">Neu</p>' ), array( 'a:html' => '<p onclick="x()">Alt</p>' ) );
check( 1 === count( $refused ), 'ein geaendertes onclick nicht' );
$refused = wpmcp_unstable_strings( array( 'a:html' => '<p>Müller & Söhne</p>' ), array() );
check( array() === $refused, 'Text mit Umlauten und & geht durch' );
$refused = wpmcp_unstable_strings( array( 'a:html' => '<script type="application/ld+json">{"@type":"Organization"}</script>' ), array() );
check( array() === $refused, 'sicheres JSON-LD darf neu sein' );

echo "\n";
if ( $fail ) {
	echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
	exit( 1 );
}
echo "\033[32mWaechter auf Zeichenketten in Ordnung.\033[0m\n";
