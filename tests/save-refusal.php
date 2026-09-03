<?php
/**
 * When something else refuses the save, say so.
 *
 * A run against a real site hit "This content contains dynamic data" on
 * every page holding a certain plugin's legacy blocks. The message is not
 * WordPress's and not the connector's, but it arrived bare, right after a
 * dry run that had said yes — so it read as a connector bug. Most of an
 * afternoon later the conclusion was to grant the agent unfiltered_html,
 * which would not have helped: the content filter rewrites content
 * silently and never refuses a save.
 *
 * Run: php tests/save-refusal.php
 *
 * @package wp-mcp-connector-plus
 */

require_once __DIR__ . '/bootstrap.php';

define( 'WP_PLUGIN_DIR', '/var/www/wp-content/plugins' );

function wp_normalize_path( $path ) {
	return str_replace( '\\', '/', (string) $path );
}

function wp_get_current_user() {
	return (object) array( 'ID' => 9, 'user_login' => 'dbw-ki' );
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

echo "\n\033[1mDie Meldung ordnet sich selbst ein\033[0m\n";

$original = new WP_Error( 'invalid_post', 'This content contains dynamic data.' );
$impact   = array( 'alters' => false, 'introduces' => false, 'affected' => array(), 'added' => array() );

$explained = wpmcp_explain_save_refusal( $original, $impact );
$message   = $explained->get_error_message();

check( is_wp_error( $explained ), 'es bleibt ein Fehler' );
check( 'invalid_post' === $explained->get_error_code(), 'der Fehlercode bleibt unveraendert' );
check( false !== strpos( $message, 'This content contains dynamic data' ), 'der Originaltext steht vorne' );
check(
	false !== strpos( $message, 'not from the connector' ),
	'die Herkunft wird benannt',
	'sonst liest sich das wie ein Fehler des Konnektors'
);
check(
	false !== strpos( $message, 'dry run reported no errors' ),
	'und dass die eigene Pruefung durchlief'
);
check(
	false !== strpos( $message, 'already stored' ),
	'sagt, dass es um vorhandenen Inhalt geht, nicht um die Aenderung'
);
check(
	false !== strpos( $message, 'unfiltered_html will not help' ),
	'und schneidet den falschen Loesungsweg ab',
	'genau der wurde beim letzten Mal vorgeschlagen'
);

echo "\n\033[1mWenn die Aenderung selbst gefiltertes Markup bringt\033[0m\n";

$impact['introduces'] = true;
$message = wpmcp_explain_save_refusal( $original, $impact )->get_error_message();
check(
	false === strpos( $message, 'already stored' ),
	'dann wird das nicht behauptet',
	'die Entlastung gilt nur, wenn die Aenderung nichts einbringt'
);

echo "\n\033[1mWer am Speichern mitschreibt\033[0m\n";

// A callback whose file sits in a plugin directory names that plugin.
check(
	'generateblocks' === wpmcp_plugin_slug_from_path( WP_PLUGIN_DIR . '/generateblocks/includes/blocks.php' ),
	'ein Plugin-Pfad wird zum Plugin-Namen'
);
check(
	null === wpmcp_plugin_slug_from_path( '/var/www/wp-includes/post.php' ),
	'Core zaehlt nicht als Plugin'
);
check(
	null === wpmcp_plugin_slug_from_path( WP_PLUGIN_DIR . '/wp-mcp-connector-plus/includes/content.php' ),
	'und dieses Plugin nennt sich nicht selbst',
	'dass es Speichern filtert, ist fuer niemanden eine Neuigkeit'
);

// Resolving a callback to its file, for the shapes add_filter accepts.
check(
	null !== wpmcp_callback_file( 'wpmcp_explain_save_refusal' ),
	'ein Funktionsname laesst sich aufloesen'
);
check(
	null !== wpmcp_callback_file( function () {} ),
	'eine Closure ebenfalls'
);
check(
	null === wpmcp_callback_file( 'diese_funktion_gibt_es_nicht' ),
	'ein unbekannter Name bringt nichts durcheinander'
);
check( null === wpmcp_callback_file( null ), 'null ebenfalls nicht' );
check( null === wpmcp_callback_file( array( 'KeineKlasse', 'keineMethode' ) ), 'eine tote Methode ebenfalls nicht' );

// The registry walk must survive an empty or oddly shaped $wp_filter.
$GLOBALS['wp_filter'] = array();
check( array() === wpmcp_save_filter_plugins(), 'ohne Hooks kommt eine leere Liste' );

$GLOBALS['wp_filter'] = array( 'wp_insert_post_data' => new stdClass() );
check( array() === wpmcp_save_filter_plugins(), 'ein Hook ohne callbacks wirft nichts um' );

echo "\n\033[1mDie Capability-Ablehnung\033[0m\n";

// This one has a setting behind it, so it gets its own answer instead of
// the general "something else refused" text.
$blocked = wpmcp_explain_save_refusal(
	new WP_Error( 'wp_die', "This content contains dynamic data, which your account doesn't have permission to save." ),
	$impact
);
$text = $blocked->get_error_message();

check( 'wpmcp_dynamic_data_blocked' === $blocked->get_error_code(), 'bekommt einen eigenen Fehlercode' );
check( false !== strpos( $text, 'unfiltered_html' ), 'nennt die fehlende Capability' );
check( false !== strpos( $text, 'dbw-ki' ), 'nennt den betroffenen Benutzer' );
check( false !== strpos( $text, 'Dynamic data' ), 'nennt die Einstellung, die es behebt' );
check(
	false !== strpos( $text, 'WP-CLI' ),
	'und sagt, dass WP-CLI daran vorbeigeht',
	'sonst weicht der naechste Agent wieder auf die Datenbank aus'
);

echo "\n";
if ( 0 === $fail ) {
	echo "\033[32mFremde Ablehnungen in Ordnung.\033[0m\n";
	exit( 0 );
}
echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
exit( 1 );
