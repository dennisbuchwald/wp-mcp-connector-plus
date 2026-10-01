<?php
/**
 * WPMCP_DISABLE turns the connector off, the agent's sign-in included.
 *
 * The kill switch stops the plugin before anything loads, the fence that
 * keeps the agent's credential on the MCP endpoint among it. Its
 * application password must not then open the rest of the REST API.
 *
 * Run: php tests/kill-switch.php
 *
 * @package wp-mcp-connector-plus
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'WPMCP_DISABLE', true );

$GLOBALS['hooks'] = array();

function add_filter( $tag, $cb, $priority = 10, $args = 1 ) {
	$GLOBALS['hooks'][ $tag ][] = array( $cb, $priority, $args );
	return true;
}
function add_action( ...$a ) { return add_filter( ...$a ); }
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }

require dirname( __DIR__ ) . '/wp-mcp-connector-plus.php';

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

echo "\n\033[1mNotschalter\033[0m\n";

check( ! function_exists( 'wpmcp_rest_scope' ), 'nichts vom Connector wird geladen', 'auch der Zaun um den Endpunkt nicht' );
check(
	array( 'wp_is_application_passwords_available_for_user' ) === array_keys( $GLOBALS['hooks'] ),
	'es bleibt genau ein Filter',
	'angemeldet: ' . implode( ', ', array_keys( $GLOBALS['hooks'] ) )
);

list( $cb, $priority, $args ) = $GLOBALS['hooks']['wp_is_application_passwords_available_for_user'][0] ?? array( null, 0, 0 );

$agent = (object) array( 'ID' => 7, 'roles' => array( 'wpmcp_ai_editor' ) );
$human = (object) array( 'ID' => 1, 'roles' => array( 'administrator' ) );

check( is_callable( $cb ) && 2 === $args, 'er bekommt den Benutzer mit' );
check( is_callable( $cb ) && false === $cb( true, $agent ), 'der Agent kann sich nicht mehr anmelden' );
check( is_callable( $cb ) && true === $cb( true, $human ), 'Menschen behalten, was sie hatten' );
check( is_callable( $cb ) && false === $cb( false, $human ), 'auch ein Nein' );

echo "\n";
if ( 0 === $fail ) {
	echo "\033[32mNotschalter in Ordnung.\033[0m\n";
	exit( 0 );
}
echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
exit( 1 );
