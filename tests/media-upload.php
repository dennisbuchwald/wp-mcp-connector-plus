<?php
/**
 * An image upload, judged by what the file is.
 *
 * The agent can finally put the customer's photos on the pages it builds,
 * and it can do so only while a work session is open. The file is judged
 * by its bytes, never its name: that is the difference between accepting
 * images and accepting whatever someone called an image.
 *
 * Run: php tests/media-upload.php
 *
 * @package wp-mcp-connector-plus
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['session'] = false;

function wpmcp_work_session_active() { return $GLOBALS['session']; }
function sanitize_text_field( $v ) { return trim( wp_strip_all_tags( (string) $v ) ); }
function sanitize_file_name( $v ) { return preg_replace( '/[^A-Za-z0-9._-]+/', '-', (string) $v ); }

require_once dirname( __DIR__ ) . '/includes/media.php';

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

$png  = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
$gif  = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
$svg  = base64_encode( '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>' );

echo "\n\033[1mWas angenommen wird\033[0m\n";

$file = wpmcp_inspect_upload( 'Boot am Steg.png', $png );
check( ! is_wp_error( $file ), 'ein PNG', is_wp_error( $file ) ? $file->get_error_message() : '' );
check( ! is_wp_error( $file ) && 'image/png' === $file['mime'], 'als image/png erkannt' );
check( ! is_wp_error( $file ) && 1 === $file['width'], 'mit seinen Abmessungen' );

$file = wpmcp_inspect_upload( 'bild.png', 'data:image/png;base64,' . $png );
check( ! is_wp_error( $file ), 'auch als data:-URL' );

if ( function_exists( 'imagecreatetruecolor' ) ) {
	ob_start();
	imagejpeg( imagecreatetruecolor( 2, 2 ) );
	$jpeg = base64_encode( ob_get_clean() );
	$file = wpmcp_inspect_upload( 'foto.jpg', $jpeg );
	check( ! is_wp_error( $file ) && 'image/jpeg' === $file['mime'], 'ein JPEG' );

	if ( function_exists( 'imagewebp' ) ) {
		ob_start();
		imagewebp( imagecreatetruecolor( 2, 2 ) );
		$webp = base64_encode( ob_get_clean() );
		$file = wpmcp_inspect_upload( 'foto.webp', $webp );
		check( ! is_wp_error( $file ) && 'image/webp' === $file['mime'], 'ein WebP' );
	}
}

echo "\n\033[1mEntschieden wird am Inhalt, nicht am Namen\033[0m\n";

$file = wpmcp_inspect_upload( 'shell.php', $png );
check( ! is_wp_error( $file ) && 'shell.png' === $file['filename'], 'ein PNG namens shell.php wird shell.png', is_wp_error( $file ) ? '' : $file['filename'] );

$file = wpmcp_inspect_upload( 'foto.jpg', $svg );
check( is_wp_error( $file ), 'ein SVG namens foto.jpg wird abgelehnt', 'SVG kann Script tragen' );

$file = wpmcp_inspect_upload( 'bild.gif', $gif );
check( is_wp_error( $file ), 'ein GIF ebenfalls', 'nur JPEG, PNG, WebP' );

$polyglot = base64_encode( base64_decode( $png ) . '<?php system($_GET["c"]); ?>' );
$file     = wpmcp_inspect_upload( 'bild.png', $polyglot );
check( is_wp_error( $file ), 'ein Bild mit angehaengtem PHP wird abgelehnt', 'gueltig als Bild und als Programm zugleich' );

$file = wpmcp_inspect_upload( 'bild.png', '!!!kein base64!!!' );
check( is_wp_error( $file ), 'kaputtes base64 wird abgelehnt' );

add_filter( 'wpmcp_max_upload_bytes', function () { return 10; } );
$file = wpmcp_inspect_upload( 'bild.png', $png );
check( is_wp_error( $file ) && 'wpmcp_upload_too_large' === $file->get_error_code(), 'zu gross wird abgelehnt' );
$GLOBALS['dbw_filters']['wpmcp_max_upload_bytes'] = array();

echo "\n\033[1mNur in einer Arbeitssitzung\033[0m\n";

$out = wpmcp_media_upload( array( 'filename' => 'bild.png', 'data' => $png, 'alt' => 'Boot' ) );
check( is_wp_error( $out ) && 'wpmcp_upload_needs_session' === $out->get_error_code(), 'ohne Sitzung kein Upload' );

$GLOBALS['session'] = true;

$out = wpmcp_media_upload( array( 'filename' => 'bild.png', 'data' => $png ) );
check( is_wp_error( $out ) && 'wpmcp_upload_needs_alt' === $out->get_error_code(), 'ohne Alt-Text abgelehnt' );

$out = wpmcp_media_upload( array( 'filename' => 'bild.png', 'data' => $png, 'decorative' => true ) );
check( ! is_wp_error( $out ) && true === $out['dryRun'], 'dekorativ geht ohne Alt-Text', is_wp_error( $out ) ? $out->get_error_message() : '' );

// Dry run by default: nothing may reach wp_upload_bits, which does not
// exist here and would end the test with a fatal.
$out = wpmcp_media_upload( array( 'filename' => 'Boot am Steg.png', 'data' => $png, 'alt' => 'Segelboot am Holzsteg' ) );
check( ! is_wp_error( $out ) && true === $out['dryRun'], 'standardmaessig nur ein Probelauf' );
check( ! is_wp_error( $out ) && ! isset( $out['bytes'] ) || ( is_int( $out['bytes'] ?? null ) ), 'die Antwort traegt die Dateigroesse, nicht den Inhalt' );

$GLOBALS['session'] = false;

echo "\n";
if ( 0 === $fail ) {
	echo "\033[32mUpload in Ordnung.\033[0m\n";
	exit( 0 );
}
echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
exit( 1 );
