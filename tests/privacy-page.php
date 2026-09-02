<?php
/**
 * The one page WordPress hides behind an administrator capability.
 *
 * A page designated under Settings > Privacy carries an extra guard:
 * core merges manage_privacy_options into the edit check. That is a meta
 * capability mapping to manage_options — the whole site. The obvious fix
 * (give the role manage_privacy_options) does nothing, because nobody ever
 * checks that capability directly; the honest version of it would hand the
 * agent full administration.
 *
 * These tests pin the narrow exception: the admin requirement is dropped
 * for editing that one page, and for nothing else.
 *
 * Run: php tests/privacy-page.php
 *
 * @package wp-mcp-connector-plus
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['options'] = array(
	'wpmcp_access_level'      => 'full',
	'wp_page_for_privacy_policy' => 75,
);
$GLOBALS['is_agent']  = true;
$GLOBALS['multisite'] = false;
$GLOBALS['allow']     = true;

// --- WordPress stubs ----------------------------------------------------

function get_option( $n, $d = false ) { return $GLOBALS['options'][ $n ] ?? $d; }
function add_filter( ...$a ) { return true; }
function add_action( ...$a ) { return true; }
function __( $t, $d = null ) { return $t; }
function get_role( $r ) { return null; }
function is_multisite() { return $GLOBALS['multisite']; }
function apply_filters( $tag, $value, ...$args ) {
	return 'wpmcp_allow_privacy_policy_edit' === $tag ? $GLOBALS['allow'] : $value;
}
function wpmcp_is_ai_user( $u ) { return $GLOBALS['is_agent']; }
function wpmcp_register_role() {}

const WPMCP_CAP  = 'wpmcp_access';
const WPMCP_ROLE = 'wpmcp_ai_editor';

require_once dirname( __DIR__ ) . '/includes/access.php';

// --- Harness ------------------------------------------------------------

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
 * What core hands our filter for the privacy page: the ordinary page caps
 * plus the administrator requirement merged in by map_meta_cap().
 */
function core_caps() {
	return array( 'edit_others_pages', 'edit_published_pages', 'manage_options' );
}

function mapped( $cap = 'edit_post', $post_id = 75, $caps = null ) {
	return wpmcp_allow_privacy_policy_edit(
		null === $caps ? core_caps() : $caps,
		$cap,
		9,
		array( $post_id )
	);
}

echo "\n\033[1mDatenschutzseite bearbeiten\033[0m\n";

$caps = mapped();
check(
	! in_array( 'manage_options', $caps, true ),
	'Vollstufe: die Admin-Anforderung faellt weg',
	implode( ', ', $caps )
);
check(
	in_array( 'edit_published_pages', $caps, true ) && in_array( 'edit_others_pages', $caps, true ),
	'alle uebrigen Anforderungen bleiben stehen',
	implode( ', ', $caps )
);

echo "\n\033[1mDie Ausnahme greift nirgends sonst\033[0m\n";

check( core_caps() === mapped( 'edit_post', 74 ), 'eine andere Seite bleibt unberuehrt' );
check( core_caps() === mapped( 'delete_post' ), 'Loeschen wird nie erleichtert' );
check( core_caps() === mapped( 'edit_post', 0 ), 'ohne Post-ID passiert nichts' );

$GLOBALS['is_agent'] = false;
check( core_caps() === mapped(), 'fuer andere Benutzer aendert sich nichts' );
$GLOBALS['is_agent'] = true;

$GLOBALS['options']['wpmcp_access_level'] = 'draft';
check( core_caps() === mapped(), 'Entwurfsstufe: die Seite bleibt gesperrt' );
$GLOBALS['options']['wpmcp_access_level'] = 'read';
check( core_caps() === mapped(), 'Lesestufe: die Seite bleibt gesperrt' );
$GLOBALS['options']['wpmcp_access_level'] = 'full';

$GLOBALS['options']['wp_page_for_privacy_policy'] = 0;
check( core_caps() === mapped(), 'ohne hinterlegte Datenschutzseite passiert nichts' );
$GLOBALS['options']['wp_page_for_privacy_policy'] = 75;

$GLOBALS['allow'] = false;
check( core_caps() === mapped(), 'der Filter kann die Ausnahme abschalten' );
$GLOBALS['allow'] = true;

echo "\n\033[1mMultisite\033[0m\n";

$GLOBALS['multisite'] = true;
$caps = mapped( 'edit_post', 75, array( 'edit_published_pages', 'manage_network' ) );
check(
	! in_array( 'manage_network', $caps, true ),
	'dort heisst die Admin-Anforderung manage_network',
	implode( ', ', $caps )
);
$GLOBALS['multisite'] = false;

echo "\n\033[1mDie Rolle bekommt nichts dazu\033[0m\n";

foreach ( array( 'read', 'draft', 'full' ) as $level ) {
	$granted = array_keys( wpmcp_level_capabilities( $level ) );
	$found   = array_intersect( array( 'manage_options', 'manage_network', 'manage_privacy_options' ), $granted );
	check(
		empty( $found ),
		"Stufe '{$level}' vergibt keine Administrationsrechte",
		'vergeben: ' . implode( ', ', $found )
	);
}

echo "\n";
if ( 0 === $fail ) {
	echo "\033[32mDatenschutzseite in Ordnung.\033[0m\n";
	exit( 0 );
}
echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
exit( 1 );
