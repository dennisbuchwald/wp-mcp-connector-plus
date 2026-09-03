<?php
/**
 * SEO fields, without ending up in the browser.
 *
 * Canonical URL, title and description could be read but not written, so
 * every SEO correction stopped at the connector and finished by hand in
 * wp-admin. Writing them is a whitelist, not an opening of post meta:
 * blocks, page builders and licence checks all keep things in meta, and
 * none of that is an agent's business.
 *
 * The other half is honesty. WordPress revisions cover post content, not
 * post meta — so a meta change has no one-click rollback, and the old
 * value has to be reported or it is gone.
 *
 * Run: php tests/meta-write.php
 *
 * @package wp-mcp-connector-plus
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['meta']    = array();
$GLOBALS['deleted'] = array();

function get_post_meta( $id, $key, $single = false ) {
	return $GLOBALS['meta'][ $key ] ?? '';
}
function update_post_meta( $id, $key, $value ) {
	$GLOBALS['meta'][ $key ] = $value;
	return true;
}
function delete_post_meta( $id, $key ) {
	unset( $GLOBALS['meta'][ $key ] );
	$GLOBALS['deleted'][] = $key;
	return true;
}
function sanitize_text_field( $value ) {
	return trim( preg_replace( '/[\r\n\t]+/', ' ', wp_strip_all_tags( (string) $value ) ) );
}
function esc_url_raw( $url ) {
	return (string) $url;
}
function wp_http_validate_url( $url ) {
	return (bool) preg_match( '#^https?://[^\s/]+#i', (string) $url );
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

$post = (object) array( 'ID' => 42 );

echo "\n\033[1mWas eine Aenderung bewirken wuerde\033[0m\n";

$GLOBALS['meta'] = array( 'rank_math_title' => 'Alter Titel' );

$diff = wpmcp_meta_diff( $post, array( 'rank_math_title' => 'Neuer Titel' ) );
check( empty( $diff['errors'] ), 'ein erlaubtes Feld wird angenommen', implode( ' ', $diff['errors'] ) );
check( 'Alter Titel' === $diff['fields']['rank_math_title']['from'], 'der alte Wert wird genannt' );
check( 'Neuer Titel' === $diff['fields']['rank_math_title']['to'], 'und der neue' );
check( 1 === $diff['changes'], 'die Aenderung wird gezaehlt' );

// The old value is the only way back, so it must survive into the log.
check(
	false !== strpos( wpmcp_meta_log_line( $diff['fields'] ), 'Alter Titel' ),
	'die Protokollzeile traegt den alten Wert',
	'Revisionen decken Meta nicht ab - ohne diese Zeile ist er weg'
);

$diff = wpmcp_meta_diff( $post, array( 'rank_math_title' => 'Alter Titel' ) );
check( 0 === $diff['changes'], 'derselbe Wert ist keine Aenderung' );
check( false === $diff['fields']['rank_math_title']['changed'], 'und wird auch so markiert' );

echo "\n\033[1mDie Whitelist\033[0m\n";

$diff = wpmcp_meta_diff( $post, array( '_wp_page_template' => 'evil.php' ) );
check( ! empty( $diff['errors'] ), 'ein fremdes Meta-Feld wird abgelehnt' );
check(
	false !== strpos( $diff['errors'][0], 'rank_math_title' ),
	'die Meldung nennt, was erlaubt ist',
	$diff['errors'][0]
);
check( empty( $diff['fields'] ), 'und nichts davon landet im Diff' );

$diff = wpmcp_meta_diff( $post, array( 'rank_math_title' => array( 'nope' ) ) );
check( ! empty( $diff['errors'] ), 'ein Array statt eines Strings wird abgelehnt' );

$diff = wpmcp_meta_diff( $post, array( 'rank_math_canonical_url' => 'nicht-mal-eine-url' ) );
check( ! empty( $diff['errors'] ), 'eine kaputte Canonical-URL wird abgelehnt' );

$diff = wpmcp_meta_diff( $post, array( 'rank_math_canonical_url' => 'https://example.test/seite/' ) );
check( empty( $diff['errors'] ), 'eine gueltige geht durch', implode( ' ', $diff['errors'] ) );

// Markup in a title would end up in the page head as text.
$diff = wpmcp_meta_diff( $post, array( 'rank_math_description' => "<b>Fett</b>\nund umgebrochen" ) );
check(
	'Fett und umgebrochen' === $diff['fields']['rank_math_description']['to'],
	'Markup und Zeilenumbrueche werden entfernt',
	var_export( $diff['fields']['rank_math_description']['to'], true )
);

echo "\n\033[1mSchreiben\033[0m\n";

$GLOBALS['meta']    = array( 'rank_math_title' => 'Alt' );
$GLOBALS['deleted'] = array();

$diff    = wpmcp_meta_diff( $post, array( 'rank_math_title' => 'Neu', 'rank_math_description' => 'Beschreibung' ) );
$written = wpmcp_apply_meta( $post, $diff['fields'] );

check( 2 === count( $written ), 'beide Felder werden geschrieben', implode( ', ', $written ) );
check( 'Neu' === $GLOBALS['meta']['rank_math_title'], 'der Titel steht in der Datenbank' );
check( 'Beschreibung' === $GLOBALS['meta']['rank_math_description'], 'die Beschreibung ebenfalls' );

// null clears a field rather than storing the word "null".
$diff    = wpmcp_meta_diff( $post, array( 'rank_math_title' => null ) );
$written = wpmcp_apply_meta( $post, $diff['fields'] );
check( in_array( 'rank_math_title', $GLOBALS['deleted'], true ), 'null loescht das Feld' );
check( ! isset( $GLOBALS['meta']['rank_math_title'] ), 'und es ist danach weg' );

// An unchanged field is not written at all.
$GLOBALS['meta'] = array( 'rank_math_title' => 'Gleich' );
$diff    = wpmcp_meta_diff( $post, array( 'rank_math_title' => 'Gleich' ) );
$written = wpmcp_apply_meta( $post, $diff['fields'] );
check( empty( $written ), 'ein unveraendertes Feld wird nicht angefasst' );

echo "\n";
if ( 0 === $fail ) {
	echo "\033[32mMeta-Schreiben in Ordnung.\033[0m\n";
	exit( 0 );
}
echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
exit( 1 );
