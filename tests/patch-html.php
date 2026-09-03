<?php
/**
 * Change a phone number without retyping the privacy policy.
 *
 * `replace` wants the whole block back. On a legal page that is sixty
 * thousand characters transcribed to correct twelve, and everything
 * transcribed can come back wrong. patch_html names the text to change
 * instead — which only works if it is refused whenever which occurrence
 * is meant would be a guess.
 *
 * Run: php tests/patch-html.php
 *
 * @package wp-mcp-connector-plus
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/tree.php';

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
 * Run ops over serialized content and give the result back the same way,
 * so what is asserted is what would land in the database.
 */
function patch( $content, array $ops ) {
	$result = wpmcp_apply_ops( parse_blocks( $content ), $ops );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	return serialize_blocks( $result['blocks'] );
}

echo "\n\033[1mText in einem Block aendern\033[0m\n";

$page = '<!-- wp:paragraph --><p>Rufen Sie an: 07131 123456</p><!-- /wp:paragraph -->'
	. '<!-- wp:paragraph --><p>Zweiter Absatz.</p><!-- /wp:paragraph -->';

$out = patch(
	$page,
	array(
		array(
			'op'      => 'patch_html',
			'path'    => '0',
			'find'    => '07131 123456',
			'replace' => '+49 7131 123456',
		),
	)
);

check( ! is_wp_error( $out ), 'die Operation laeuft durch', is_wp_error( $out ) ? $out->get_error_message() : '' );
check( is_string( $out ) && false !== strpos( $out, '+49 7131 123456' ), 'die neue Nummer steht drin' );
check( is_string( $out ) && false === strpos( $out, '>07131' ), 'die alte ist weg' );
check( is_string( $out ) && false !== strpos( $out, 'Zweiter Absatz.' ), 'der Nachbarblock bleibt unberuehrt' );
check( is_string( $out ) && false !== strpos( $out, '<!-- wp:paragraph -->' ), 'der Blockkommentar bleibt stehen' );

// Nothing but the anchor may move.
$expected = str_replace( '07131 123456', '+49 7131 123456', $page );
check( $expected === $out, 'sonst aendert sich kein einziges Zeichen', var_export( $out, true ) );

echo "\n\033[1mWann es sich weigert\033[0m\n";

$twice = '<!-- wp:paragraph --><p>Preis auf Anfrage. Preis auf Anfrage.</p><!-- /wp:paragraph -->';
$out   = patch( $twice, array( array( 'op' => 'patch_html', 'path' => '0', 'find' => 'Preis auf Anfrage.', 'replace' => 'Auf Anfrage.' ) ) );
check( is_wp_error( $out ), 'zweimal gefunden ist ein Fehler, keine Wahl' );
check( is_wp_error( $out ) && false !== strpos( $out->get_error_message(), '2 times' ), 'die Meldung nennt die Anzahl', is_wp_error( $out ) ? $out->get_error_message() : '' );

$out = patch( $page, array( array( 'op' => 'patch_html', 'path' => '0', 'find' => 'gibt es nicht', 'replace' => 'x' ) ) );
check( is_wp_error( $out ), 'gar nicht gefunden ist ebenfalls ein Fehler' );
check(
	is_wp_error( $out ) && false !== strpos( $out->get_error_message(), 'content-read' ),
	'und verweist auf das erneute Lesen',
	'sonst raet der Aufrufer weiter an einer veralteten Fassung herum'
);

// A hit in the neighbour is not a hit here.
$out = patch( $page, array( array( 'op' => 'patch_html', 'path' => '0', 'find' => 'Zweiter Absatz.', 'replace' => 'x' ) ) );
check( is_wp_error( $out ), 'ein Treffer im Nachbarblock zaehlt nicht' );

$out = patch( $page, array( array( 'op' => 'patch_html', 'path' => '0', 'find' => '', 'replace' => 'x' ) ) );
check( is_wp_error( $out ), 'ein leerer Suchtext wird abgelehnt' );

$out = patch( $page, array( array( 'op' => 'patch_html', 'path' => '0', 'find' => 'Rufen' ) ) );
check( is_wp_error( $out ), 'ohne "replace" ebenfalls' );

$out = patch( $page, array( array( 'op' => 'patch_html', 'path' => '9', 'find' => 'Rufen', 'replace' => 'x' ) ) );
check( is_wp_error( $out ), 'ein Pfad ins Leere ebenfalls' );

echo "\n\033[1mContainer\033[0m\n";

// Only the container's own markup is its business. Its children have
// their own paths, and patching through them would edit at a distance.
$nested = '<!-- wp:group --><div class="wp-block-group">'
	. '<!-- wp:paragraph --><p>Kind mit 07131 123456</p><!-- /wp:paragraph -->'
	. '</div><!-- /wp:group -->';

$out = patch( $nested, array( array( 'op' => 'patch_html', 'path' => '0', 'find' => '07131 123456', 'replace' => 'x' ) ) );
check( is_wp_error( $out ), 'der Container sieht den Text seines Kindes nicht' );

$out = patch( $nested, array( array( 'op' => 'patch_html', 'path' => '0.0', 'find' => '07131 123456', 'replace' => '+49 7131 123456' ) ) );
check( ! is_wp_error( $out ), 'ueber den Kindpfad geht es', is_wp_error( $out ) ? $out->get_error_message() : '' );
check( is_string( $out ) && false !== strpos( $out, '+49 7131 123456' ), 'und aendert den Text' );
check( is_string( $out ) && false !== strpos( $out, 'wp-block-group' ), 'die Huelle des Containers bleibt heil' );

// The container's own class can be patched, without disturbing the child.
$out = patch( $nested, array( array( 'op' => 'patch_html', 'path' => '0', 'find' => 'wp-block-group', 'replace' => 'wp-block-group is-wide' ) ) );
check( ! is_wp_error( $out ) && false !== strpos( (string) $out, 'is-wide' ), 'sein eigenes Markup laesst sich aendern' );
check( is_string( $out ) && false !== strpos( $out, 'Kind mit 07131 123456' ), 'das Kind bleibt dabei, wie es war' );

echo "\n\033[1mZusammen mit anderen Operationen\033[0m\n";

$out = patch(
	$page,
	array(
		array( 'op' => 'set_attrs', 'path' => '1', 'attrs' => array( 'align' => 'wide' ) ),
		array( 'op' => 'patch_html', 'path' => '0', 'find' => '07131 123456', 'replace' => '+49 7131 123456' ),
	)
);
check( ! is_wp_error( $out ), 'gemischte Operationen laufen durch', is_wp_error( $out ) ? $out->get_error_message() : '' );
check( is_string( $out ) && false !== strpos( $out, '+49 7131 123456' ), 'die Textaenderung greift' );
check( is_string( $out ) && false !== strpos( $out, '"align":"wide"' ), 'die Attributaenderung ebenfalls' );

// Two patches in sequence, the second reading the result of the first.
$out = patch(
	$page,
	array(
		array( 'op' => 'patch_html', 'path' => '0', 'find' => '07131 123456', 'replace' => 'PLATZHALTER' ),
		array( 'op' => 'patch_html', 'path' => '0', 'find' => 'PLATZHALTER', 'replace' => '+49 7131 123456' ),
	)
);
check( is_string( $out ) && false !== strpos( $out, '+49 7131 123456' ), 'die zweite Aenderung sieht das Ergebnis der ersten' );

// A whole operation list is refused as a whole.
$out = patch(
	$page,
	array(
		array( 'op' => 'patch_html', 'path' => '0', 'find' => '07131 123456', 'replace' => 'x' ),
		array( 'op' => 'patch_html', 'path' => '1', 'find' => 'gibt es nicht', 'replace' => 'y' ),
	)
);
check( is_wp_error( $out ), 'scheitert eine Operation, faellt die ganze Liste' );

echo "\n\033[1mUnbekannte Operation\033[0m\n";

$out = patch( $page, array( array( 'op' => 'patch_text', 'path' => '0' ) ) );
check(
	is_wp_error( $out ) && false !== strpos( $out->get_error_message(), 'patch_html' ),
	'die Fehlermeldung listet patch_html mit auf',
	is_wp_error( $out ) ? $out->get_error_message() : ''
);

echo "\n";
if ( 0 === $fail ) {
	echo "\033[32mpatch_html in Ordnung.\033[0m\n";
	exit( 0 );
}
echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
exit( 1 );
