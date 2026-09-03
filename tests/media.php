<?php
/**
 * The media library, and the question that made it necessary.
 *
 * An audit found 76 images with an empty alt attribute in the markup and
 * could say nothing about any of them: alt text usually lives on the
 * attachment, so an empty attribute in a block may still render with an
 * alt the theme fills in from the library, or may not. Without a look at
 * the library the count is noise.
 *
 * "usedIn" is the other half. An unused image with no alt text is a
 * cleanup job; the same image on eleven pages is not.
 *
 * Run: php tests/media.php
 *
 * @package wp-mcp-connector-plus
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['meta']    = array();
$GLOBALS['updated'] = array();
$GLOBALS['logged']  = array();

function get_post_meta( $id, $key, $single = false ) {
	return $GLOBALS['meta'][ $id ][ $key ] ?? '';
}
function update_post_meta( $id, $key, $value ) {
	$GLOBALS['meta'][ $id ][ $key ] = $value;
	return true;
}
function sanitize_text_field( $value ) {
	return trim( preg_replace( '/[\r\n\t]+/', ' ', wp_strip_all_tags( (string) $value ) ) );
}
function wp_slash( $v ) {
	return $v;
}
function wp_update_post( $postarr, $error = false ) {
	$GLOBALS['updated'][] = $postarr;
	return $postarr['ID'] ?? 1;
}
function get_post( $id ) {
	return $GLOBALS['posts'][ (int) $id ] ?? null;
}
function current_user_can( $cap, $id = null ) {
	return $GLOBALS['can'] ?? true;
}
function get_attached_file( $id ) {
	return '/var/www/uploads/2025/04/Boyn.png';
}
function wp_get_attachment_url( $id ) {
	return 'https://example.test/wp-content/uploads/2025/04/Boyn.png';
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}
function wpmcp_log( $ability, $args ) {
	$GLOBALS['logged'][] = $args;
}

require_once dirname( __DIR__ ) . '/includes/media.php';

$GLOBALS['posts'] = array(
	1234 => (object) array(
		'ID'             => 1234,
		'post_type'      => 'attachment',
		'post_title'     => 'Boyn',
		'post_excerpt'   => '',
		'post_mime_type' => 'image/png',
	),
	702  => (object) array( 'ID' => 702, 'post_type' => 'page' ),
);

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

echo "\n\033[1mEine Mediendatei beschreiben\033[0m\n";

$shape = wpmcp_media_shape( $GLOBALS['posts'][1234], array( 702, 1598 ) );
check( 1234 === $shape['id'], 'die ID steht drin' );
check( 'Boyn.png' === $shape['filename'], 'der Dateiname, nicht der ganze Pfad' );
check( '' === $shape['alt'], 'ein fehlender Alt-Text ist leer, nicht null' );
check( array( 702, 1598 ) === $shape['usedIn'], 'und die Seiten, die das Bild einbinden' );

echo "\n\033[1mAendern\033[0m\n";

$GLOBALS['meta'][1234]['_wp_attachment_image_alt'] = '';

$result = wpmcp_media_update( array( 'id' => 1234, 'alt' => 'Logo Boyn Versicherungsmakler' ) );
check( ! is_wp_error( $result ), 'der Probelauf laeuft durch', is_wp_error( $result ) ? $result->get_error_message() : '' );
check( true === $result['dryRun'], 'und ist standardmaessig ein Probelauf' );
check( '' === $GLOBALS['meta'][1234]['_wp_attachment_image_alt'], 'dabei wird nichts geschrieben' );
check( true === $result['changes']['alt']['changed'], 'die Aenderung wird gemeldet' );
check( ! empty( $result['note'] ), 'mit dem Hinweis, dass es keine Revisionen gibt' );

$result = wpmcp_media_update( array( 'id' => 1234, 'alt' => 'Logo Boyn Versicherungsmakler', 'dry_run' => false ) );
check( 'Logo Boyn Versicherungsmakler' === $GLOBALS['meta'][1234]['_wp_attachment_image_alt'], 'scharf geschaltet steht der Alt-Text drin' );
check( ! empty( $GLOBALS['logged'] ), 'und es wird protokolliert' );
check(
	false !== strpos( $GLOBALS['logged'][0]['summary'], '"" -> "Logo Boyn' ),
	'die Protokollzeile traegt alten und neuen Wert',
	$GLOBALS['logged'][0]['summary'] ?? ''
);

// An empty alt is a decision, not a mistake: decorative images want one.
$result = wpmcp_media_update( array( 'id' => 1234, 'alt' => '', 'dry_run' => false ) );
check( '' === $GLOBALS['meta'][1234]['_wp_attachment_image_alt'], 'ein leerer Alt-Text laesst sich setzen' );

$GLOBALS['updated'] = array();
wpmcp_media_update( array( 'id' => 1234, 'title' => 'Boyn Logo', 'dry_run' => false ) );
check( 1 === count( $GLOBALS['updated'] ), 'der Titel geht ueber wp_update_post' );
check( 'Boyn Logo' === $GLOBALS['updated'][0]['post_title'], 'mit dem neuen Wert' );

echo "\n\033[1mWas es nicht tut\033[0m\n";

$result = wpmcp_media_update( array( 'id' => 1234, 'caption' => 'Neu' ) );
check( is_wp_error( $result ), 'ein anderes Feld ist kein gueltiger Aufruf' );

$result = wpmcp_media_update( array( 'id' => 702 ) );
check( is_wp_error( $result ), 'eine Seite ist keine Mediendatei' );
check( is_wp_error( $result ) && 'wpmcp_not_found' === $result->get_error_code(), 'und wird als solche abgelehnt' );

$result = wpmcp_media_update( array( 'id' => 99999 ) );
check( is_wp_error( $result ), 'eine unbekannte ID ebenfalls' );

$GLOBALS['can'] = false;
$result = wpmcp_media_update( array( 'id' => 1234, 'alt' => 'x' ) );
check( is_wp_error( $result ), 'ohne Bearbeitungsrecht geht nichts' );
check(
	is_wp_error( $result ) && 'wpmcp_forbidden' === $result->get_error_code(),
	'auf der Lesestufe traegt WordPress die Grenze mit',
	'die Rolle hat dort kein edit_post'
);
$GLOBALS['can'] = true;

echo "\n\033[1mURL-Vergleich\033[0m\n";

// Matching whole URLs misses every page written under another domain.
check(
	'/wp-content/uploads/2025/04/Boyn.png' === wpmcp_url_path( 'https://example.test/wp-content/uploads/2025/04/Boyn.png' ),
	'verglichen wird der Pfad, nicht die ganze URL'
);
check(
	'/wp-content/uploads/2025/04/Boyn.png' === wpmcp_url_path( 'http://alte-domain.test/wp-content/uploads/2025/04/Boyn.png' ),
	'damit passt auch eine alte Domain'
);

echo "\n";
if ( 0 === $fail ) {
	echo "\033[32mMediathek in Ordnung.\033[0m\n";
	exit( 0 );
}
echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
exit( 1 );
