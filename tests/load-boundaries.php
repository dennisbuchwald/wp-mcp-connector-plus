<?php
/**
 * Nothing that loads on every request may call into the tool files.
 *
 * The tool files (tree, validation, content, search ...) only load when the
 * Abilities API initialises, so an ordinary page view never pays for them.
 * Everything else (the admin screen, the admin bar, auth, access) loads
 * always and must not depend on them.
 *
 * 0.19.0 broke exactly this on a live site: the Access tab called
 * wpmcp_selectable_post_types() in access.php, which called
 * wpmcp_allowed_post_types() in content-access.php. In 0.18.2 the status
 * table on the same page counted the abilities and so loaded the tool
 * files as a side effect; once the settings moved to a tab of their own,
 * nothing did, and the tab died with "Call to undefined function". Every
 * other test loads all files up front and could not see it.
 *
 * So this test reads the code instead of running it: which files does the
 * plugin load lazily, which functions do they define, and does any
 * always-loaded file call one of those without loading the file first or
 * asking function_exists().
 *
 * Run: php tests/load-boundaries.php
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

/**
 * Source without comments, so a function named in a docblock is no call.
 */
function code_only( $file ) {
	$code = '';
	foreach ( token_get_all( file_get_contents( $file ) ) as $token ) {
		if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		$code .= is_array( $token ) ? $token[1] : $token;
	}
	return $code;
}

// The lazy set is what the plugin itself says it loads late: everything
// wpmcp_load_abilities() requires, plus what content.php pulls in.
$main = code_only( $root . '/wp-mcp-connector-plus.php' );
preg_match( '/function\s+wpmcp_load_abilities\s*\(\)\s*\{(.*?)\n\}/s', $main, $loader );
check( ! empty( $loader[1] ), 'wpmcp_load_abilities() gefunden' );
preg_match_all( "#includes/([\w.-]+\.php)#", $loader[1] ?? '', $m );
$lazy = $m[1];
preg_match_all( "#__DIR__\s*\.\s*'/([\w.-]+\.php)'#", code_only( $root . '/includes/content.php' ), $m );
$lazy = array_values( array_unique( array_merge( $lazy, $m[1] ) ) );

// A file an always-loaded file requires at load time is always loaded too,
// even when content.php names it again (post-types.php through access.php).
foreach ( array( 'auth.php', 'access.php', 'audit.php', 'preview.php', 'editor.php', 'admin-bar.php', 'updater.php' ) as $base ) {
	preg_match_all( "#^require_once\s+__DIR__\s*\.\s*'/([\w.-]+\.php)'#m", code_only( $root . '/includes/' . $base ), $m );
	$lazy = array_values( array_diff( $lazy, $m[1] ) );
}
check( in_array( 'content-access.php', $lazy, true ), 'die Inhaltsdateien zaehlen als spaet geladen' );

$defined_in = array();
foreach ( $lazy as $file ) {
	preg_match_all( '/^\s*function\s+(wpmcp_\w+)\s*\(/m', code_only( $root . '/includes/' . $file ), $m );
	foreach ( $m[1] as $fn ) {
		$defined_in[ $fn ] = $file;
	}
}
check( count( $defined_in ) > 50, 'spaet geladene Funktionen erfasst (' . count( $defined_in ) . ')' );

// Everything else in includes/, plus the main file and uninstall.php.
$always = array( 'wp-mcp-connector-plus.php', 'uninstall.php' );
foreach ( glob( $root . '/includes/*.php' ) as $path ) {
	if ( ! in_array( basename( $path ), $lazy, true ) ) {
		$always[] = 'includes/' . basename( $path );
	}
}

$crossings = array();
foreach ( $always as $rel ) {
	$code = code_only( $root . '/' . $rel );

	// Inside the main file, the loader and the cron handler require the
	// file they call right before calling it.
	if ( 'wp-mcp-connector-plus.php' === $rel ) {
		$code = preg_replace( '/function\s+\w+\s*\([^)]*\)\s*\{[^{}]*require_once[^{}]*\}/s', '', $code );
	}

	preg_match_all( '/(?<![\w>$:])(wpmcp_\w+)\s*\(/', $code, $m, PREG_OFFSET_CAPTURE );
	foreach ( $m[1] as $hit ) {
		list( $fn, $offset ) = $hit;
		if ( ! isset( $defined_in[ $fn ] ) ) {
			continue;
		}
		if ( preg_match( '/function\s+$/', substr( $code, max( 0, $offset - 20 ), $offset - max( 0, $offset - 20 ) ) ) ) {
			continue;
		}
		if ( preg_match( "/function_exists\(\s*'" . $fn . "'/", $code ) ) {
			continue;
		}
		$line        = substr_count( substr( $code, 0, $offset ), "\n" ) + 1;
		$crossings[] = "{$rel}:{$line} ruft {$fn}() aus {$defined_in[ $fn ]}";
	}
}

check(
	empty( $crossings ),
	'keine immer geladene Datei ruft in eine spaet geladene',
	implode( "\n      ", $crossings )
);

// The function that broke the Access tab lives where the tab can reach it.
check( ! isset( $defined_in['wpmcp_allowed_post_types'] ), 'wpmcp_allowed_post_types() ist immer geladen' );

echo "\n";
if ( $fail ) {
	echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
	exit( 1 );
}
echo "\033[32mLadegrenzen in Ordnung.\033[0m\n";
