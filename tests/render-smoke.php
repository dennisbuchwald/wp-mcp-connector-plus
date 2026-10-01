<?php
/**
 * The render check leaves the output buffers as it found them.
 *
 * It renders the new markup once inside its own buffer, to catch a broken
 * render.php before a visitor does. A block that opens a buffer of its own
 * and then throws left that buffer open: the check closed one level, the
 * block's, and its own stayed behind and swallowed the JSON answer of the
 * request, or WordPress flushed it into the response at shutdown.
 *
 * Run: php tests/render-smoke.php
 *
 * @package wp-mcp-connector-plus
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['render'] = null;

/** Renders by calling whatever the test put in place. */
function do_blocks( $content ) {
	return $GLOBALS['render'] ? ( $GLOBALS['render'] )() : $content;
}

class WP_Error {
	public $code;
	public $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_message() {
		return $this->message;
	}
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

require_once dirname( __DIR__ ) . '/includes/validate.php';

$fail = 0;

// Straight to STDOUT: echo would land in the very buffers under test.
function check( $ok, $name, $detail = '' ) {
	global $fail;
	if ( $ok ) {
		fwrite( STDOUT, "  \033[32m✓\033[0m {$name}\n" );
		return;
	}
	fwrite( STDOUT, "  \033[31m✗\033[0m {$name}\n" );
	if ( '' !== $detail ) {
		fwrite( STDOUT, "      {$detail}\n" );
	}
	++$fail;
}

fwrite( STDOUT, "\n\033[1mPufferebenen nach dem Render-Test\033[0m\n" );

// The request's own buffer, as a REST response has one.
ob_start();
$outer = ob_get_level();

$GLOBALS['render'] = function () {
	echo 'normal';
	return 'normal';
};
$r = wpmcp_render_smoke_test( '<!-- wp:paragraph /-->' );
check( ! is_wp_error( $r ), 'ein gewoehnlicher Block besteht' );
check( $outer === ob_get_level(), 'und laesst die Pufferebene stehen' );

$GLOBALS['render'] = function () {
	ob_start();
	echo 'halb';
	throw new \RuntimeException( 'kaputt' );
};
$r = wpmcp_render_smoke_test( '<!-- wp:acme/kaputt /-->' );
check( is_wp_error( $r ), 'ein Block, der wirft, wird gemeldet' );
check( $outer === ob_get_level(), 'und auch sein eigener Puffer ist danach zu', 'Ebene ' . ob_get_level() . ' statt ' . $outer );

$GLOBALS['render'] = function () {
	ob_start();
	ob_start();
	echo 'vergessen';
	return '';
};
$r = wpmcp_render_smoke_test( '<!-- wp:acme/vergesslich /-->' );
check( ! is_wp_error( $r ), 'ein Block, der Puffer offen laesst, besteht' );
check( $outer === ob_get_level(), 'aber seine Puffer bleiben nicht offen', 'Ebene ' . ob_get_level() . ' statt ' . $outer );

$GLOBALS['render'] = function () {
	echo 'still';
	return '';
};
wpmcp_render_smoke_test( '' );
$leaked = ob_get_contents();
check( '' === $leaked, 'nichts aus dem Render landet in der Antwort', var_export( $leaked, true ) );

while ( ob_get_level() > 0 ) {
	ob_end_clean();
}

echo "\n";
if ( 0 === $fail ) {
	echo "\033[32mRender-Test in Ordnung.\033[0m\n";
	exit( 0 );
}
echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
exit( 1 );
