<?php
/**
 * Where a page lives: its slug, its parent, its status.
 *
 * These three were untouchable, and the reason held for exactly one of
 * the two cases they cover. A published page's slug is what every link to
 * it points at, its parent is part of that URL, and taking it back to
 * draft removes it from the site. None of that is an agent's call.
 *
 * A page that has never been published has none of those problems, and it
 * is exactly the page the agent just made. Refusing there meant building
 * twenty-two pages and then correcting twenty-two slugs and parents by
 * hand — not a safeguard, only work.
 *
 * So the line runs along "is this page live", not along "these fields".
 * These tests are that line.
 *
 * Run: php tests/placement.php
 *
 * @package wp-mcp-connector-plus
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['posts'] = array();

function get_post( $id ) {
	return $GLOBALS['posts'][ (int) $id ] ?? null;
}
function sanitize_title( $title ) {
	$title = strtolower( (string) $title );
	$title = str_replace( array( 'ä', 'ö', 'ü', 'ß' ), array( 'ae', 'oe', 'ue', 'ss' ), $title );
	return trim( preg_replace( '/-+/', '-', preg_replace( '/[^a-z0-9]+/', '-', $title ) ), '-' );
}

$GLOBALS['session'] = false;
function wpmcp_work_session_active() { return $GLOBALS['session']; }

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

function page( $id, $status = 'draft', $parent = 0, $slug = 'alt', $type = 'page' ) {
	$post = (object) array(
		'ID'          => $id,
		'post_status' => $status,
		'post_parent' => $parent,
		'post_name'   => $slug,
		'post_type'   => $type,
	);
	$GLOBALS['posts'][ $id ] = $post;
	return $post;
}

$parent_page = page( 17, 'publish', 0, 'produkte' );
$draft       = page( 100, 'draft', 0, 'alt' );
$live        = page( 200, 'publish', 0, 'leistungen' );

echo "\n\033[1mEin Entwurf laesst sich einordnen\033[0m\n";

$d = wpmcp_placement_diff( $draft, array( 'slug' => 'Motor & Antrieb', 'parent' => 17, 'status' => 'pending' ) );

check( empty( $d['errors'] ), 'kein Fehler', implode( ' ', $d['errors'] ) );
check( 'motor-antrieb' === $d['fields']['slug']['to'], 'der Slug wird normalisiert', var_export( $d['fields']['slug']['to'] ?? null, true ) );
check( 17 === $d['fields']['parent']['to'], 'der Elternteil wird uebernommen' );
check( 'pending' === $d['fields']['status']['to'], 'und der Status' );
check( 3 === $d['changes'], 'drei Aenderungen' );

$d = wpmcp_placement_diff( $draft, array( 'slug' => 'alt' ) );
check( 0 === $d['changes'], 'derselbe Slug ist keine Aenderung' );

echo "\n\033[1mEine veroeffentlichte Seite behaelt ihre Adresse\033[0m\n";

$d = wpmcp_placement_diff( $live, array( 'slug' => 'neu' ) );
check( ! empty( $d['errors'] ), 'der Slug wird abgelehnt' );
check(
	false !== strpos( $d['errors'][0], 'already linked to' ),
	'mit dem Grund: darauf zeigen Links',
	$d['errors'][0]
);
check( 0 === $d['changes'], 'und nichts wird vorgemerkt' );

$d = wpmcp_placement_diff( $live, array( 'parent' => 17 ) );
check( ! empty( $d['errors'] ), 'der Elternteil ebenfalls', 'er steckt im Pfad der URL' );

$d = wpmcp_placement_diff( $live, array( 'status' => 'draft' ) );
check( ! empty( $d['errors'] ), 'und das Zuruecknehmen auf Entwurf' );
check(
	false !== strpos( $d['errors'][0], 'take a live page off the site' ),
	'das ist keine Aenderung, das ist eine Entfernung',
	$d['errors'][0]
);

// Sending the value it already has is not a change and must not be refused.
$d = wpmcp_placement_diff( $live, array( 'slug' => 'leistungen' ) );
check( empty( $d['errors'] ), 'der unveraenderte Slug stoert nicht', 'sonst scheitert jeder Schreibvorgang, der ihn mitschickt' );

echo "\n\033[1mVeroeffentlichen geht ueberhaupt nicht\033[0m\n";

foreach ( array( 'publish', 'future', 'private' ) as $status ) {
	$d = wpmcp_placement_diff( $draft, array( 'status' => $status ) );
	check( ! empty( $d['errors'] ), "Status \"{$status}\" wird abgelehnt" );
}
check(
	array( 'draft', 'pending' ) === wpmcp_writable_statuses(),
	'es gibt nur zwei setzbare Status',
	'jeder weitere waere ein Weg zum Veroeffentlichen'
);

echo "\n\033[1mVeroeffentlichen in einer Arbeitssitzung\033[0m\n";

// Opening a session is the human deciding that what gets built in it may
// go live. Outside one, nothing publishes.
$GLOBALS['session'] = true;

check( in_array( 'publish', wpmcp_writable_statuses(), true ), 'waehrend der Sitzung ist publish setzbar' );
$d = wpmcp_placement_diff( $draft, array( 'status' => 'publish' ) );
check( empty( $d['errors'] ) && 'publish' === $d['fields']['status']['to'], 'ein Entwurf laesst sich veroeffentlichen', implode( ' ', $d['errors'] ) );

$d = wpmcp_placement_diff( $live, array( 'status' => 'draft' ) );
check( ! empty( $d['errors'] ), 'eine Live-Seite zurueckzunehmen bleibt gesperrt', 'auch in der Sitzung ist das eine Entfernung' );

$d = wpmcp_placement_diff( $draft, array( 'status' => 'private' ) );
check( ! empty( $d['errors'] ), 'private bleibt abgelehnt' );

$GLOBALS['session'] = false;

$d = wpmcp_placement_diff( $draft, array( 'status' => 'publish' ) );
check(
	! empty( $d['errors'] ) && false !== strpos( $d['errors'][0], 'work session' ),
	'ohne Sitzung wird publish abgelehnt, mit Hinweis auf die Sitzung',
	$d['errors'][0] ?? ''
);

echo "\n\033[1mDer Elternteil muss einer sein koennen\033[0m\n";

$d = wpmcp_placement_diff( $draft, array( 'parent' => 100 ) );
check( ! empty( $d['errors'] ) && false !== strpos( $d['errors'][0], 'its own parent' ), 'sich selbst nicht' );

$d = wpmcp_placement_diff( $draft, array( 'parent' => 9999 ) );
check( ! empty( $d['errors'] ), 'eine unbekannte ID nicht' );

page( 300, 'draft', 0, 'ein-beitrag', 'post' );
$d = wpmcp_placement_diff( $draft, array( 'parent' => 300 ) );
check(
	! empty( $d['errors'] ) && false !== strpos( $d['errors'][0], 'cannot sit under it' ),
	'und kein anderer Post-Type'
);

// A loop would take the page out of the tree entirely.
$child = page( 400, 'draft', 100, 'kind' );
$d     = wpmcp_placement_diff( $draft, array( 'parent' => 400 ) );
check(
	! empty( $d['errors'] ) && false !== strpos( $d['errors'][0], 'loop' ),
	'ein Kreis wird erkannt',
	'sonst haengt der Zweig an nichts mehr'
);

$d = wpmcp_placement_diff( $draft, array( 'parent' => 0 ) );
check( empty( $d['errors'] ), 'kein Elternteil ist erlaubt' );

echo "\n\033[1mUnbrauchbare Angaben\033[0m\n";

$d = wpmcp_placement_diff( $draft, array( 'slug' => '???' ) );
check( ! empty( $d['errors'] ), 'ein Slug aus lauter Sonderzeichen' );

$d = wpmcp_placement_diff( $draft, array( 'status' => 'trash' ) );
check( ! empty( $d['errors'] ), 'ein erfundener Status' );

$d = wpmcp_placement_diff( $draft, array() );
check( empty( $d['errors'] ) && 0 === $d['changes'], 'gar keine Angabe ist kein Fehler' );

echo "\n";
if ( 0 === $fail ) {
	echo "\033[32mEinordnung in Ordnung.\033[0m\n";
	exit( 0 );
}
echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
exit( 1 );
