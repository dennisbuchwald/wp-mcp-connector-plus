<?php
/**
 * A 32 KB legal text has to fit in one call.
 *
 * It did not. The error was `input[ops][0] is not of type object`, which
 * reads like a malformed operation and is nothing of the sort: past a
 * certain size the client hands the argument over as a JSON string, and
 * WordPress's REST layer, wanting an array, splits a scalar on commas
 * (rest_is_array -> wp_parse_list). Item 0 is then a fragment of JSON
 * text. The complaint is literally true and points nowhere.
 *
 * The workaround was thirteen placeholder blocks and fifteen sequential
 * writes for a single page, with the text flow across chunk boundaries
 * maintained by hand.
 *
 * The split is not repaired by rejoining on commas: that would turn
 * "Komma, Punkt" into "Komma,Punkt" and quietly alter the customer's
 * text. It is reported instead.
 *
 * Run: php tests/large-payload.php
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

$ops = array(
	array(
		'op'      => 'patch_html',
		'path'    => '4.2',
		'find'    => 'alte Nummer',
		'replace' => 'neue Nummer',
	),
);

echo "\n\033[1mDie Formen, in denen ein Argument ankommt\033[0m\n";

$out = wpmcp_decode_structure( $ops, 'ops' );
check( $ops === $out, 'als Liste von Objekten unveraendert durch' );

$out = wpmcp_decode_structure( wp_json_encode( $ops ), 'ops' );
check( $ops === $out, 'als JSON-Text dekodiert' );

// What WordPress leaves behind: wp_parse_list on the JSON string.
$split = array_map( 'trim', explode( ',', wp_json_encode( $ops ) ) );
check( count( $split ) > 1, 'die Zerlegung erzeugt mehrere Teile', 'sonst prueft der naechste Test nichts' );

$out = wpmcp_decode_structure( $split, 'ops' );
check( is_wp_error( $out ), 'die zerlegten Teile werden nicht stillschweigend geflickt' );
check(
	is_wp_error( $out ) && false !== strpos( $out->get_error_message(), 'splits it on commas' ),
	'sondern mit Angabe der Ursache abgelehnt',
	'ein Wiederzusammenbau waere verlustbehaftet: "Komma, Punkt" wuerde zu "Komma,Punkt"'
);

echo "\n\033[1mGroesse spielt keine Rolle mehr\033[0m\n";

// The actual case: a privacy policy in one block.
$big = array(
	array(
		'op'    => 'replace',
		'path'  => '3',
		'block' => array(
			'name' => 'core/html',
			'html' => str_repeat( '<p>Absatz mit Komma, Punkt und Anfuehrungszeichen "so".</p>', 600 ),
		),
	),
);

$json = wp_json_encode( $big );
check( strlen( $json ) > 30000, 'der Testfall ist wirklich gross (' . strlen( $json ) . ' Bytes)' );

$out = wpmcp_decode_structure( $json, 'ops' );
check( $big === $out, 'als Text kommt er vollstaendig an' );

$out = wpmcp_decode_structure( array_map( 'trim', explode( ',', $json ) ), 'ops' );
check( is_wp_error( $out ), 'zerlegt wird er abgelehnt statt beschaedigt gespeichert' );

echo "\n\033[1mMeta genauso\033[0m\n";

$meta = array( 'rank_math_title' => 'Ein Titel, mit Komma' );
check( $meta === wpmcp_decode_structure( wp_json_encode( $meta ), 'meta' ), 'ein Objekt als Text' );

echo "\n\033[1mWenn wirklich etwas kaputt ist\033[0m\n";

$broken = wpmcp_decode_structure( substr( $json, 0, 5000 ), 'ops' );
check( is_wp_error( $broken ), 'abgeschnittener Text ist ein Fehler' );
check(
	is_wp_error( $broken ) && false !== strpos( $broken->get_error_message(), 'truncated' ),
	'und die Meldung sagt, dass es vermutlich abgeschnitten wurde',
	is_wp_error( $broken ) ? $broken->get_error_message() : ''
);
check(
	is_wp_error( $broken ) && false !== strpos( $broken->get_error_message(), '5000 bytes' ),
	'mit der Groesse, die tatsaechlich ankam'
);

check( array() === wpmcp_decode_structure( array(), 'ops' ), 'eine leere Liste bleibt eine leere Liste' );
check( is_wp_error( wpmcp_decode_structure( 42, 'ops' ) ), 'eine Zahl ist keine Struktur' );
check( is_wp_error( wpmcp_decode_structure( 'einfach Text', 'ops' ) ), 'beliebiger Text ebenfalls nicht' );

echo "\n";
if ( 0 === $fail ) {
	echo "\033[32mGrosse Payloads in Ordnung.\033[0m\n";
	exit( 0 );
}
echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
exit( 1 );
