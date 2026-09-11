<?php
/**
 * What this plugin puts on a customer's server.
 *
 * A shop site went down the moment the plugin was activated — not with an
 * error of ours, but with a fatal inside WooCommerce Germanized:
 *
 *   require(.../wp-mcp-connector-plus/vendor/autoload_packages.php):
 *   Failed to open stream: No such file or directory
 *
 * The mcp-adapter depends on automattic/jetpack-autoloader, and its
 * manifests ended up in vendor/composer/ while the Composer plugin that
 * writes vendor/autoload_packages.php was deliberately switched off. Half
 * an autoloader: the half that announces "I have a newer one" without the
 * half anyone can load.
 *
 * Every plugin sharing that autoloader — Jetpack, WooCommerce, Germanized —
 * then scans the active plugins, believes ours is the newest, and requires
 * a file that was never generated. The site is down on every request, not
 * just in wp-admin.
 *
 * Run: php tests/shipped-files.php
 *
 * @package wp-mcp-connector-plus
 */

$root = dirname( __DIR__ );
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

echo "\n\033[1mKeine halbe Jetpack-Autoloader-Anmeldung\033[0m\n";

// The file the other plugins read to decide "this one has an autoloader".
$manifests = glob( $root . '/vendor/composer/jetpack_autoload_*.php' );
check(
	empty( $manifests ),
	'keine jetpack_autoload_*-Manifeste im vendor/composer',
	empty( $manifests ) ? '' : 'gefunden: ' . implode( ', ', array_map( 'basename', $manifests ) )
		. "\n      Diese Dateien melden einen Autoloader an, den es hier nicht gibt."
);

// The file they require once they believe it.
check(
	! file_exists( $root . '/vendor/autoload_packages.php' ),
	'und auch keine autoload_packages.php',
	'entweder beide oder keine - eine allein ist der Fatal'
);

echo "\n\033[1mDer eigene Autoloader traegt trotzdem\033[0m\n";

$psr4 = $root . '/vendor/composer/autoload_psr4.php';
check( file_exists( $psr4 ), 'der gewoehnliche Composer-Autoloader ist da' );

$map = file_exists( $psr4 ) ? require $psr4 : array();
check( isset( $map['WP\\MCP\\'] ), 'und bildet den mcp-adapter ab', 'ohne ihn startet gar nichts' );
// The update checker is not PSR-4: Composer includes its loader file.
$files = file_exists( $root . '/vendor/composer/autoload_files.php' )
	? require $root . '/vendor/composer/autoload_files.php'
	: array();
check(
	(bool) preg_grep( '#plugin-update-checker#', $files ),
	'und der Updater wird als Datei eingebunden',
	'sonst laufen die beiden Livesites nie wieder ein Update'
);

check(
	file_exists( $root . '/vendor/wordpress/mcp-adapter/includes/Core/McpAdapter.php' ),
	'die Klasse liegt, wo die Abbildung sie erwartet'
);

echo "\n\033[1mDie Absicherung dagegen\033[0m\n";

$gitignore = file_get_contents( $root . '/.gitignore' );
check(
	false !== strpos( $gitignore, 'jetpack_autoload_' ),
	'.gitignore haelt die Manifeste draussen'
);

$composer = json_decode( file_get_contents( $root . '/composer.json' ), true );
check(
	false === ( $composer['config']['allow-plugins']['automattic/jetpack-autoloader'] ?? null ),
	'das Composer-Plugin bleibt abgeschaltet'
);
check(
	isset( $composer['scripts']['post-install-cmd'] ),
	'und ein Install raeumt die Reste weg',
	'sonst kommen sie beim naechsten composer install zurueck'
);

echo "\n";
if ( 0 === $fail ) {
	echo "\033[32mAuslieferung in Ordnung.\033[0m\n";
	exit( 0 );
}
echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
exit( 1 );
