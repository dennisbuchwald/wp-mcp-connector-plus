<?php
/**
 * The check that exists because WordPress silently rewrote content.
 *
 * An agent account has no unfiltered_html, so wp_kses_post removes script
 * tags on save. A JSON-LD block disappeared from a real customer page with
 * nothing in any log. wpmcp_verify_stored() compares what was sent against
 * what landed; these tests pin that behaviour down.
 *
 * Run: php tests/verify-stored.php
 *
 * @package wp-mcp-connector-plus
 */

require_once __DIR__ . '/bootstrap.php';

// The stored content is whatever the stub says it is.
$GLOBALS['stored'] = '';
function get_post_field( $field, $post_id, $context = 'display' ) {
	return $GLOBALS['stored'];
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

echo "\n\033[1mGespeicherten Inhalt gegenprüfen\033[0m\n";

// 1. Nothing changed.
$sent              = '<!-- wp:core/paragraph --><p>Hallo</p><!-- /wp:core/paragraph -->';
$GLOBALS['stored'] = $sent;
check( array() === wpmcp_verify_stored( 1, $sent ), 'Unveränderter Inhalt erzeugt keine Warnung' );

// 2. The real case: a script tag was stripped.
$sent              = '<!-- wp:core/html --><script type="application/ld+json">{"@type":"FAQPage"}</script><!-- /wp:core/html -->';
$GLOBALS['stored'] = '<!-- wp:core/html -->{"@type":"FAQPage"}<!-- /wp:core/html -->';
$w                 = wpmcp_verify_stored( 1, $sent );
check( ! empty( $w ), 'Entferntes <script> wird gemeldet' );
check( false !== strpos( implode( ' ', $w ), 'script tags' ), 'Die Meldung benennt Script-Tags', implode( ' | ', $w ) );
check( false !== strpos( implode( ' ', $w ), 'unfiltered_html' ), 'Die Meldung nennt die Ursache' );

// 3. An iframe was stripped.
$sent              = '<!-- wp:core/html --><iframe src="https://example.test"></iframe><!-- /wp:core/html -->';
$GLOBALS['stored'] = '<!-- wp:core/html --><!-- /wp:core/html -->';
$w                 = wpmcp_verify_stored( 1, $sent );
check( false !== strpos( implode( ' ', $w ), 'iframes' ), 'Entfernter iframe wird gemeldet', implode( ' | ', $w ) );

// 4. A whole block vanished.
$sent              = '<!-- wp:core/paragraph --><p>A</p><!-- /wp:core/paragraph --><!-- wp:core/paragraph --><p>B</p><!-- /wp:core/paragraph -->';
$GLOBALS['stored'] = '<!-- wp:core/paragraph --><p>A</p><!-- /wp:core/paragraph -->';
$w                 = wpmcp_verify_stored( 1, $sent );
check( false !== strpos( implode( ' ', $w ), 'stored 1 blocks where 2' ), 'Verlorener Block wird gezählt', implode( ' | ', $w ) );

// 5. Changed but in no recognisable way — still say something.
$sent              = '<!-- wp:core/paragraph --><p>Hallo <b onclick="x()">Welt</b></p><!-- /wp:core/paragraph -->';
$GLOBALS['stored'] = '<!-- wp:core/paragraph --><p>Hallo <b>Welt</b></p><!-- /wp:core/paragraph -->';
$w                 = wpmcp_verify_stored( 1, $sent );
check( ! empty( $w ), 'Sonstige Veränderung wird trotzdem gemeldet', 'Stille Änderung blieb unbemerkt' );

echo "\n";
if ( 0 === $fail ) {
	echo "\033[32mVerifikation in Ordnung.\033[0m\n";
	exit( 0 );
}
echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
exit( 1 );
