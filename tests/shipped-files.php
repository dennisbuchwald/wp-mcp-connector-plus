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

echo "\n\033[1mDeinstallation wird mitgeliefert\033[0m\n";

// WordPress looks for uninstall.php in the plugin root. Missing from the
// ZIP, deleting the plugin would leave the agent's credentials behind.
check( is_file( $root . '/uninstall.php' ), 'uninstall.php liegt im Plugin-Wurzelordner' );
$attr = shell_exec( 'git -C ' . escapeshellarg( $root ) . ' check-attr export-ignore -- uninstall.php 2>/dev/null' );
check(
	null === $attr || false === strpos( (string) $attr, ': set' ),
	'und ist nicht von git archive ausgenommen',
	trim( (string) $attr )
);

echo "\n\033[1mWas jede Sitzung im Kontext traegt\033[0m\n";

// Tool descriptions are sent once per session and sit there for its whole
// length. They are also what makes the agent behave like an editor rather
// than a CRUD client, so the answer is not "as short as possible" — it is
// "no repetition". This budget is here so twelve more releases of
// appending do not quietly double it again.
$src = file_get_contents( $root . '/includes/abilities.php' );
preg_match_all( "/^\t\t'(wpmcp\/[a-z-]+)' => array\($/m", $src, $names, PREG_OFFSET_CAPTURE );

$total     = 0;
$elementor = 0;
$largest   = array( 'name' => '', 'len' => 0 );

// From the entry in wpmcp_ability_definitions(): the name also appears
// earlier, in the annotation table, where there is no description to
// measure (and where "array(" is followed by the hints on the same line).
foreach ( $names[1] as list( $name, $at ) ) {
	$chunk = substr( $src, $at, 6000 );
	if ( ! preg_match( "/'description' => '((?:[^'\\\\]|\\\\.)*)'/", $chunk, $d ) ) {
		continue;
	}
	$len = strlen( stripslashes( $d[1] ) );
	// The Elementor tools are registered only where Elementor runs
	// (wpmcp_offered_where_supported), so they have a budget of their own
	// on top: every other site never carries them.
	if ( 0 === strpos( $name, 'wpmcp/elementor-' ) ) {
		$elementor += $len;
	} else {
		$total += $len;
	}
	if ( $len > $largest['len'] ) {
		$largest = array( 'name' => $name, 'len' => $len );
	}
}

check(
	count( $names[1] ) >= 18,
	sprintf( '%d Registrierungen gemessen', count( $names[1] ) )
);
check(
	$total > 0 && $total < 11000,
	sprintf( 'alle Beschreibungen zusammen: %s Zeichen (~%d Tokens)', number_format( $total ), (int) ( $total / 4 ) ),
	'ueber 11000 Zeichen - pruefen, was sich doppelt'
);
check(
	$elementor > 0 && $elementor < 2500,
	sprintf( 'dazu auf Elementor-Seiten: %s Zeichen (~%d Tokens)', number_format( $elementor ), (int) ( $elementor / 4 ) ),
	'ueber 2500 Zeichen fuer zwei Werkzeuge - pruefen, was sich doppelt'
);
check(
	$largest['len'] < 2000,
	sprintf( 'die laengste ist %s mit %d Zeichen (~%d Tokens)', $largest['name'], $largest['len'], (int) ( $largest['len'] / 4 ) ),
	'eine Beschreibung ueber 2000 Zeichen erklaert etwas zweimal'
);

echo "\n\033[1mJeder Fehlercode ist dokumentiert\033[0m\n";

// The codes are the part of an answer an agent can branch on, so they are
// contract. One that exists only in the code is one nobody can rely on.
$codes = array();
foreach ( array_merge( glob( $root . '/includes/*.php' ), glob( $root . '/includes/*/*.php' ) ) as $file ) {
	$php = file_get_contents( $file );
	preg_match_all( "/WP_Error\(\s*'(wpmcp_[a-z_]+)'/", $php, $m );
	$codes = array_merge( $codes, $m[1] );
	preg_match_all( "/'code'\s*=>[^;\n]*'(wpmcp_[a-z_]+)'/", $php, $m );
	$codes = array_merge( $codes, $m[1] );
	preg_match_all( "/'code'\s*=>\s*'(wpmcp_[a-z_]+)'/", $php, $m );
	$codes = array_merge( $codes, $m[1] );
	preg_match_all( "/\\\$shaped\['code'\]\s*=\s*'(wpmcp_[a-z_]+)'/", $php, $m );
	$codes = array_merge( $codes, $m[1] );
}
$codes  = array_unique( $codes );
$readme = file_get_contents( $root . '/README.md' );
$undoc  = array_filter(
	$codes,
	function ( $c ) use ( $readme ) {
		return false === strpos( $readme, '| `' . $c . '` |' );
	}
);
check( count( $codes ) > 30, sprintf( '%d Fehlercodes im Code gefunden', count( $codes ) ) );
check( empty( $undoc ), 'jeder steht in der README-Tabelle "Error codes"', 'fehlt: ' . implode( ', ', $undoc ) );

echo "\n\033[1mUebersetzungen werden mitgeliefert\033[0m\n";

/**
 * The msgids of a .po or .pot file (singular, with the plural after a
 * NUL, the way gettext keys them).
 */
function po_msgids( $file ) {
	$po = (string) file_get_contents( $file );
	$po = preg_replace( "/\"\n\"/", '', $po );
	preg_match_all( '/^msgid "(.*)"\n(?:msgid_plural "(.*)"\n)?/m', $po, $m, PREG_SET_ORDER );
	$ids = array();
	foreach ( $m as $entry ) {
		if ( '' !== $entry[1] ) {
			$ids[] = stripcslashes( $entry[1] ) . ( isset( $entry[2] ) && '' !== $entry[2] ? "\0" . stripcslashes( $entry[2] ) : '' );
		}
	}
	sort( $ids );
	return $ids;
}

$pot = $root . '/languages/wp-mcp-connector-plus.pot';
$po  = $root . '/languages/wp-mcp-connector-plus-de_DE.po';
$mo  = $root . '/languages/wp-mcp-connector-plus-de_DE.mo';

check( is_file( $pot ), 'die Vorlage languages/wp-mcp-connector-plus.pot liegt bei' );
check( is_file( $po ) && is_file( $mo ), 'und die deutsche Uebersetzung als .po und .mo' );

$attr = shell_exec( 'git -C ' . escapeshellarg( $root ) . ' check-attr export-ignore -- languages/wp-mcp-connector-plus-de_DE.mo 2>/dev/null' );
check(
	null === $attr || false === strpos( (string) $attr, ': set' ),
	'languages/ ist nicht von git archive ausgenommen',
	trim( (string) $attr )
);

$header = (string) file_get_contents( $root . '/wp-mcp-connector-plus.php', false, null, 0, 2000 );
check( false !== strpos( $header, 'Domain Path:       /languages' ), 'der Plugin-Kopf nennt den Ordner' );
check(
	(bool) preg_match( "/load_plugin_textdomain\(\s*'wp-mcp-connector-plus'/", (string) file_get_contents( $root . '/wp-mcp-connector-plus.php' ) ),
	'und das Plugin laedt ihn'
);

if ( is_file( $pot ) && is_file( $po ) ) {
	$template = po_msgids( $pot );
	$german   = po_msgids( $po );
	check( count( $template ) > 100, sprintf( '%d Zeichenketten in der Vorlage', count( $template ) ) );
	check(
		$template === $german,
		'die deutsche Uebersetzung kennt genau die Zeichenketten der Vorlage',
		'nicht abgeglichen: ' . implode( ' | ', array_slice( array_merge( array_diff( $template, $german ), array_diff( $german, $template ) ), 0, 5 ) ) . "\n      bash bin/i18n.sh gleicht ab"
	);
	$po_text = (string) file_get_contents( $po );
	check( ! preg_match( '/^msgstr ""\n(?!")/m', preg_replace( '/\A.*?\n\n/s', '', $po_text ) ), 'und keine ist leer' );
	check( false === strpos( $po_text, '#, fuzzy' ), 'und keine unsicher (fuzzy)' );
}

// The template must follow the code. Checked where xgettext exists; CI
// without gettext skips it rather than failing on a missing tool.
$xgettext = trim( (string) shell_exec( 'command -v xgettext 2>/dev/null' ) );
if ( '' !== $xgettext && is_file( $pot ) ) {
	$fresh = tempnam( sys_get_temp_dir(), 'wpmcp-pot' );
	$files = array_merge( array( 'wp-mcp-connector-plus.php', 'uninstall.php' ), array_map( function ( $f ) use ( $root ) { return substr( $f, strlen( $root ) + 1 ); }, array_merge( glob( $root . '/includes/*.php' ), glob( $root . '/includes/*/*.php' ) ) ) );
	$cmd   = 'cd ' . escapeshellarg( $root ) . ' && ' . escapeshellarg( $xgettext ) . ' --language=PHP --from-code=UTF-8'
		. ' --keyword=__ --keyword=_e --keyword=esc_html__ --keyword=esc_html_e --keyword=esc_attr__ --keyword=esc_attr_e'
		. ' --keyword=_x:1,2c --keyword=_n:1,2 --keyword=_nx:1,2,4c --keyword=esc_html_x:1,2c --keyword=esc_attr_x:1,2c --keyword=_n_noop:1,2'
		. ' -o ' . escapeshellarg( $fresh ) . ' ' . implode( ' ', array_map( 'escapeshellarg', $files ) ) . ' 2>&1';
	shell_exec( $cmd );
	$now = is_file( $fresh ) ? po_msgids( $fresh ) : array();
	@unlink( $fresh );
	check(
		po_msgids( $pot ) === $now,
		'die Vorlage passt zum Code',
		'neu oder weg: ' . implode( ' | ', array_slice( array_merge( array_diff( $now, po_msgids( $pot ) ), array_diff( po_msgids( $pot ), $now ) ), 0, 5 ) ) . "\n      bash bin/i18n.sh erneuert sie"
	);
} else {
	echo "  - xgettext fehlt, Abgleich der Vorlage mit dem Code uebersprungen\n";
}

echo "\n";
if ( 0 === $fail ) {
	echo "\033[32mAuslieferung in Ordnung.\033[0m\n";
	exit( 0 );
}
echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
exit( 1 );
