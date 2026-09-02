<?php
/**
 * Load the plugin and fire the ability hooks in the order WordPress fires
 * them, then check that all eight abilities actually register.
 *
 * This exists because two separate bugs made every registration fail
 * silently — the category hook was added from a file loaded too late, and
 * later the category slug drifted apart from the one the abilities named.
 * Both produced the same symptom: an MCP server that connects and offers
 * nothing. Neither was visible without a live site until now.
 *
 * Run: php tests/register-abilities.php
 *
 * @package wp-mcp-connector-plus
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['hooks']      = array();
$GLOBALS['categories'] = array();
$GLOBALS['abilities']  = array();

// --- Minimal hook system, order-preserving ------------------------------

function add_action( $tag, $cb, $priority = 10, $args = 1 ) {
	$GLOBALS['hooks'][ $tag ][ $priority ][] = $cb;
	return true;
}
function add_filter( $tag, $cb, $priority = 10, $args = 1 ) {
	return add_action( $tag, $cb, $priority, $args );
}
function apply_filters( $tag, $value ) {
	foreach ( $GLOBALS['hooks'][ $tag ] ?? array() as $bucket ) {
		foreach ( $bucket as $cb ) {
			$value = $cb( $value );
		}
	}
	return $value;
}
function do_action( $tag, ...$args ) {
	$buckets = $GLOBALS['hooks'][ $tag ] ?? array();
	ksort( $buckets );
	foreach ( $buckets as $bucket ) {
		foreach ( $bucket as $cb ) {
			$cb( ...$args );
		}
	}
}

// --- The Abilities API, behaving like the real one ----------------------

function wp_register_ability_category( $slug, $args = array() ) {
	$GLOBALS['categories'][ $slug ] = $args;
	return true;
}

function wp_register_ability( $name, $args = array() ) {
	// The real API rejects an ability naming a category that does not exist.
	$category = $args['category'] ?? null;
	if ( ! $category || ! isset( $GLOBALS['categories'][ $category ] ) ) {
		$GLOBALS['rejected'][ $name ] = $category;
		return false;
	}
	$GLOBALS['abilities'][ $name ] = $args;
	return true;
}

function wp_get_ability( $name ) {
	return $GLOBALS['abilities'][ $name ] ?? null;
}

// --- Everything else the plugin touches while loading -------------------

function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function register_activation_hook( ...$a ) { return true; }
function is_admin() { return false; }
function wp_doing_cron() { return false; }
function __( $t, $d = null ) { return $t; }
function esc_html__( $t, $d = null ) { return $t; }
function esc_html( $t ) { return $t; }
function remove_role( $r ) { return true; }
function add_role( ...$a ) { return true; }
function current_user_can( $c ) { return true; }
function is_user_logged_in() { return true; }
function get_userdata( $id ) { return null; }
function wp_salt( $s = 'auth' ) { return 'test-salt'; }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function get_post_type( $id ) { return 'page'; }
function get_option( $n, $d = false ) { return $d; }

// --- Load the plugin ----------------------------------------------------

require_once dirname( __DIR__ ) . '/wp-mcp-connector-plus.php';

// --- Fire the hooks in WordPress order ----------------------------------

do_action( 'wp_abilities_api_categories_init' );
do_action( 'wp_abilities_api_init' );

// --- Check --------------------------------------------------------------

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

echo "\n\033[1mAbility-Registrierung\033[0m\n";

check(
	! empty( $GLOBALS['categories'] ),
	'Kategorie ist registriert: ' . implode( ', ', array_keys( $GLOBALS['categories'] ) ),
	'Keine Kategorie registriert — jede Ability wird abgelehnt.'
);

$expected = wpmcp_ability_names();
$got      = array_keys( $GLOBALS['abilities'] );

check(
	count( $got ) === count( $expected ),
	sprintf( '%d von %d Abilities registriert', count( $got ), count( $expected ) ),
	empty( $GLOBALS['rejected'] )
		? ''
		: 'abgelehnt wegen unbekannter Kategorie: ' . implode(
			', ',
			array_map(
				function ( $n, $c ) {
					return "{$n} (Kategorie: " . var_export( $c, true ) . ')';
				},
				array_keys( $GLOBALS['rejected'] ),
				$GLOBALS['rejected']
			)
		)
);

foreach ( $expected as $name ) {
	check( isset( $GLOBALS['abilities'][ $name ] ), "registriert: {$name}" );
}

echo "\n\033[1mSchema jeder Ability\033[0m\n";

foreach ( $GLOBALS['abilities'] as $name => $args ) {
	$problems = array();
	foreach ( array( 'label', 'description', 'category', 'execute_callback', 'permission_callback' ) as $key ) {
		if ( empty( $args[ $key ] ) ) {
			$problems[] = "{$key} fehlt";
		}
	}
	if ( ! empty( $args['permission_callback'] ) && ! is_callable( $args['permission_callback'] ) ) {
		$problems[] = 'permission_callback nicht aufrufbar';
	}
	if ( ! empty( $args['execute_callback'] ) && ! is_callable( $args['execute_callback'] ) ) {
		$problems[] = 'execute_callback nicht aufrufbar';
	}
	check( empty( $problems ), "vollständig: {$name}", implode( ', ', $problems ) );
}

echo "\n";
if ( 0 === $fail ) {
	echo "\033[32mRegistrierung in Ordnung.\033[0m\n";
	exit( 0 );
}
echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
exit( 1 );
