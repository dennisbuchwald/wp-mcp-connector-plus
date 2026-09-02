<?php
/**
 * A copy must be a copy.
 *
 * Duplicating a page ran through wp_insert_post, which filters content for
 * accounts without unfiltered_html. The JSON-LD schema of the source was
 * therefore gone the moment the copy was created — before any editing
 * happened. A later write on that copy then found nothing to preserve and
 * correctly reported nothing, which made the loss look like it came from
 * the write.
 *
 * Run: php tests/duplicate-preserves.php
 *
 * @package wp-mcp-connector-plus
 */

require_once __DIR__ . '/bootstrap.php';


// Record whether the content filter was active at the moment of the insert.
$GLOBALS['kses_active'] = true;
$GLOBALS['inserted']    = null;

function remove_filter( $tag, $cb, $priority = 10 ) {
	if ( 'content_save_pre' === $tag ) {
		$GLOBALS['kses_active'] = false;
	}
	return true;
}
function wp_insert_post( $postarr, $error = false ) {
	// Mimic WordPress: filter the content when the filter is on.
	$content = $postarr['post_content'] ?? '';
	if ( $GLOBALS['kses_active'] ) {
		$content = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', (string) $content );
	}
	$GLOBALS['inserted'] = $content;
	return 4711;
}
function wp_update_post( $postarr, $error = false ) { return $postarr['ID'] ?? 1; }
function wp_slash( $v ) { return $v; }
function wp_kses_post( $c ) { return preg_replace( '#<script\b[^>]*>.*?</script>#is', '', (string) $c ); }

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

echo "\n\033[1mDuplizieren erhaelt den Inhalt\033[0m\n";

$source = '<!-- wp:core/html --><script type="application/ld+json">{"@type":"FAQPage"}</script><!-- /wp:core/html -->';

// The old behaviour, for contrast.
$GLOBALS['kses_active'] = true;
$GLOBALS['inserted']    = null;
wp_insert_post( array( 'post_content' => $source ) );
check(
	false === strpos( (string) $GLOBALS['inserted'], '<script' ),
	'ohne den Fix verliert die Kopie das Script (Ausgangslage)',
	'Der Stub bildet das Filtern nicht nach — der Test waere wertlos'
);

// What the connector does now.
$GLOBALS['kses_active'] = true;
$GLOBALS['inserted']    = null;
$id = wpmcp_insert_post_preserving( array( 'post_content' => $source ) );

check( 4711 === $id, 'der Beitrag wird angelegt' );
check(
	false !== strpos( (string) $GLOBALS['inserted'], '<script type="application/ld+json">' ),
	'die Kopie behaelt das Script',
	var_export( $GLOBALS['inserted'], true )
);
check(
	$source === $GLOBALS['inserted'],
	'und ist Zeichen fuer Zeichen identisch zur Vorlage'
);
$restored = $GLOBALS['dbw_filters']['content_save_pre'] ?? array();
check(
	in_array( 'wp_filter_post_kses', $restored, true ),
	'der Filter wird danach wieder gesetzt',
	'sonst bliebe er fuer den Rest des Requests aus'
);

echo "\n";
if ( 0 === $fail ) {
	echo "\033[32mDuplizieren in Ordnung.\033[0m\n";
	exit( 0 );
}
echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
exit( 1 );
