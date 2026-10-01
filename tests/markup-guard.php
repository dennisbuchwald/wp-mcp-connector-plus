<?php
/**
 * Nothing the agent writes goes past WordPress's content filter.
 *
 * Up to 0.18.2 the decision to save without kses only asked whether the
 * agent added a script, style, iframe, form, object or embed. Everything
 * kses removes besides those whole elements - an onerror on an image, a
 * javascript: link, an svg with onload, an event handler on a details
 * element - counted as "the page already had something to protect", and
 * the save went past the filter. Stored XSS at the default access level.
 *
 * The elevated path (dynamic data, unfiltered_html for one save) had its
 * own guard, a list of regular expressions, and the usual ways around a
 * list of regular expressions went around it.
 *
 * Since 0.18.3 both ask kses itself, block by block: a block that is
 * byte-identical to one already stored keeps its markup, every other
 * block must come out of wp_kses_post exactly as it went in.
 *
 * Run: php tests/markup-guard.php
 *
 * @package wp-mcp-connector-plus
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/kses-stub.php';

// --- Enough WordPress for the write path to save ---------------------

$GLOBALS['posts']   = array();
$GLOBALS['saves']   = array();
$GLOBALS['elevate'] = false;

function get_post( $id ) {
	return $GLOBALS['posts'][ (int) $id ] ?? null;
}
function get_post_field( $field, $id, $context = 'display' ) {
	$post = get_post( $id );
	return $post ? $post->$field : '';
}
function get_post_types( $args = array(), $output = 'names' ) {
	$page = (object) array( 'name' => 'page', 'public' => true );
	return 'names' === $output ? array( 'page' ) : array( 'page' => $page );
}
function post_type_exists( $type ) { return 'page' === $type; }
function get_post_type_object( $type ) {
	return (object) array( 'name' => $type, 'cap' => (object) array( 'publish_posts' => 'publish_pages', 'create_posts' => 'edit_pages' ) );
}
function current_user_can( ...$args ) { return true; }
function get_current_user_id() { return 9; }
function is_multisite() { return false; }
function sanitize_title( $t ) { return strtolower( trim( preg_replace( '/[^A-Za-z0-9]+/', '-', (string) $t ), '-' ) ); }
function get_post_meta( $id, $key = '', $single = false ) { return $single ? '' : array(); }
function wp_slash( $v ) { return $v; }
function wp_get_post_revisions( $id, $args = array() ) { return array(); }
function get_permalink( $post ) { return 'https://example.test/?p=' . ( is_object( $post ) ? $post->ID : (int) $post ); }
function wp_get_post_revision( $id ) { return $GLOBALS['revisions'][ (int) $id ] ?? null; }
function wpmcp_pattern_access() { return 'read'; }
function wpmcp_extra_post_types() { return array(); }
function wpmcp_work_session_active() { return false; }
function wpmcp_live_edit_enabled() { return true; }
function wpmcp_privacy_policy_page_id() { return 0; }
function wpmcp_dynamic_data_allowed() { return $GLOBALS['elevate']; }
function wpmcp_log( $ability, $data = array() ) {}
function wpmcp_preview_url( $id ) { return 'https://example.test/preview/' . $id; }
function wpmcp_purge_caches( $id ) { return array(); }

/** WordPress registers kses on these two filters for accounts without unfiltered_html. */
function kses_on() {
	$GLOBALS['dbw_filters']['content_save_pre']          = array( 'wp_filter_post_kses' );
	$GLOBALS['dbw_filters']['content_filtered_save_pre'] = array( 'wp_filter_post_kses' );
}

function remove_filter( $tag, $callback, $priority = 10 ) {
	$list = $GLOBALS['dbw_filters'][ $tag ] ?? array();
	foreach ( $list as $i => $registered ) {
		if ( $registered === $callback ) {
			unset( $list[ $i ] );
			$GLOBALS['dbw_filters'][ $tag ] = array_values( $list );
			return true;
		}
	}
	return false;
}

function kses_active() {
	return in_array( 'wp_filter_post_kses', $GLOBALS['dbw_filters']['content_save_pre'] ?? array(), true );
}

/** Saves the way WordPress does: through kses unless the filter is off. */
function wp_update_post( $postarr, $error = false ) {
	$content = $postarr['post_content'] ?? null;
	$kses    = kses_active();

	if ( null !== $content ) {
		$stored = $kses ? wp_kses_post( $content ) : $content;
		$GLOBALS['posts'][ (int) $postarr['ID'] ]->post_content = $stored;
	}
	$GLOBALS['saves'][] = array(
		'id'      => (int) $postarr['ID'],
		'kses'    => $kses,
		'content' => $content,
	);

	return (int) $postarr['ID'];
}

// The blocks these tests write, so that a refusal can only come from the
// markup guard and never from "block not registered".
foreach ( array( 'core/html' => 'Custom HTML', 'core/image' => 'Image' ) as $name => $title ) {
	\WP_Block_Type_Registry::get_instance()->register( $name, array( 'title' => $title, 'attributes' => array() ) );
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

function page( $id, $content ) {
	$GLOBALS['posts'][ $id ] = (object) array(
		'ID'                => $id,
		'post_status'       => 'draft',
		'post_parent'       => 0,
		'post_name'         => 'seite-' . $id,
		'post_type'         => 'page',
		'post_content'      => $content,
		'post_modified_gmt' => '2026-10-01 10:00:00',
	);
	return $GLOBALS['posts'][ $id ];
}

/** Write a tree for real and report what happened. */
function write( $id, array $tree ) {
	kses_on();
	$GLOBALS['saves'] = array();
	$result           = wpmcp_write_content( array( 'post_id' => $id, 'tree' => $tree, 'dry_run' => false ) );
	return array(
		'result' => $result,
		'saves'  => $GLOBALS['saves'],
		'stored' => $GLOBALS['posts'][ $id ]->post_content,
	);
}

function message_of( $result ) {
	if ( is_wp_error( $result ) ) {
		return $result->get_error_message();
	}
	return implode( ' ', (array) ( $result['errors'] ?? array() ) );
}

/** The write was refused, nothing saved, and nothing executable stored. */
function refused( array $w ) {
	$refused = is_wp_error( $w['result'] ) || empty( $w['result']['ok'] );
	return $refused && empty( $w['saves'] );
}

$para  = '<!-- wp:paragraph --><p>Ganz normaler Text</p><!-- /wp:paragraph -->';
$p     = function ( $html ) {
	return array( 'name' => 'core/paragraph', 'html' => $html );
};
$free  = function ( $html ) {
	return array( 'name' => null, 'html' => $html );
};
$plain = array( 'name' => 'core/paragraph', 'html' => '<p>Ganz normaler Text</p>' );

// Every one of these survived a save through 0.18.2, either on the
// preserve path (C1) or through the dynamic data guard (C2).
$vectors = array(
	'img onerror'                 => '<img src="x" onerror="alert(1)">',
	'img onerror unquoted'        => '<img src=x onerror=alert(1)>',
	'onerror after a slash'       => '<img src="x"/onerror="alert(1)">',
	'a href javascript:'          => '<a href="javascript:alert(1)">x</a>',
	'javascript: entity-encoded'  => '<a href="&#106;avascript:alert(1)">x</a>',
	'javascript: no semicolon'    => '<a href="&#106avascript:alert(1)">x</a>',
	'javascript: hex entity'      => '<a href="&#x6A;avascript:alert(1)">x</a>',
	'javascript: with a tab'      => "<a href=\"java\tscript:alert(1)\">x</a>",
	'javascript: with &Tab;'      => '<a href="java&Tab;script:alert(1)">x</a>',
	'javascript: with &colon;'    => '<a href="javascript&colon;alert(1)">x</a>',
	'vbscript:'                   => '<a href="vbscript:msgbox(1)">x</a>',
	'data:text/html'              => '<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a>',
	'svg onload'                  => '<svg onload="alert(1)"></svg>',
	'svg/onload'                  => '<svg/onload=alert(1)>',
	'details ontoggle'            => '<details open ontoggle="alert(1)"><summary>x</summary></details>',
	'xlink:href javascript:'      => '<svg><a xlink:href="javascript:alert(1)"><text>x</text></a></svg>',
	'animate values'              => '<svg><a><animate attributeName="href" values="javascript:alert(1)"/><text>x</text></a></svg>',
	'meta refresh'                => '<meta http-equiv="refresh" content="0;url=https://evil.test">',
	'base href'                   => '<base href="https://evil.test/">',
	'link stylesheet'             => '<link rel="stylesheet" href="https://evil.test/x.css">',
	'style url()'                 => '<p style="background:url(javascript:alert(1))">x</p>',
	'style element'               => '<style>body{display:none}</style>',
	'form'                        => '<form action="https://evil.test"><button>Senden</button></form>',
);

echo "\n\033[1mDer Stub filtert, was WordPress filtert\033[0m\n";

// A stub blind to an attack proves nothing about a guard.
foreach ( $vectors as $label => $html ) {
	check( wp_kses_post( $html ) !== $html, "kses veraendert: {$label}" );
}
check( wp_kses_post( '<p class="a">Text <a href="https://x.test">Link</a></p>' ) === '<p class="a">Text <a href="https://x.test">Link</a></p>', 'sauberes Markup bleibt Byte fuer Byte gleich' );

echo "\n\033[1mC1: kein ungefilterter Save fuer neues Markup\033[0m\n";

foreach ( $vectors as $label => $html ) {
	page( 10, $para );
	$w = write( 10, array( $plain, $free( $html ) ) );
	check(
		refused( $w ),
		"abgelehnt: {$label}",
		empty( $w['saves'] ) ? message_of( $w['result'] ) : sprintf( 'gespeichert, kses %s: %s', $w['saves'][0]['kses'] ? 'an' : 'AUS', $w['stored'] )
	);
}

// The reported shape: a page with markup to protect, and an attack
// alongside an ordinary edit. This is the case that took the preserve path.
$embed = '<!-- wp:html --><iframe src="https://player.test/1"></iframe><!-- /wp:html -->';
page( 11, $para . $embed );
$w = write( 11, array( $p( '<p>Neu <img src="x" onerror="alert(1)"></p>' ), array( 'name' => 'core/html', 'html' => '<iframe src="https://player.test/1"></iframe>' ) ) );
check( refused( $w ), 'auch neben bestehendem Embed: onerror wird abgelehnt', message_of( $w['result'] ) . ' ' . $w['stored'] );

$msg = message_of( $w['result'] );
check( false !== strpos( $msg, 'block 0' ), 'die Meldung nennt den Blockpfad', $msg );
check( false !== stripos( $msg, 'onerror' ), 'und das Konstrukt', $msg );
check( false !== strpos( $msg, 'Remove it' ) && false !== strpos( $msg, 'editor' ), 'und was zu tun ist', $msg );

echo "\n\033[1mBestehendes bleibt erhalten\033[0m\n";

// The reason the preserve path exists: an untouched block is not the
// agent's doing and must survive an edit elsewhere on the page.
page( 12, $para . $embed );
$w = write( 12, array( $p( '<p>Geaenderter Text</p>' ), array( 'name' => 'core/html', 'html' => '<iframe src="https://player.test/1"></iframe>' ) ) );
check( ! refused( $w ), 'ein Textwechsel neben einem Embed wird gespeichert', message_of( $w['result'] ) );
check( false !== strpos( $w['stored'], '<iframe src="https://player.test/1"></iframe>' ), 'das Embed ueberlebt den Save', $w['stored'] );
check( false !== strpos( $w['stored'], 'Geaenderter Text' ), 'die Aenderung ist drin' );
check( kses_active(), 'der Filter ist danach wieder gesetzt' );

// Nested: a group whose child is untouched keeps it, even when the
// group's own markup changes.
$group = '<!-- wp:group --><div class="wp-block-group">' . $embed . '</div><!-- /wp:group -->';
page( 13, $para . $group );
$w = write(
	13,
	array(
		$plain,
		array(
			'name'         => 'core/group',
			'htmlTemplate' => array( '<div class="wp-block-group is-neu">', null, '</div>' ),
			'innerBlocks'  => array( array( 'name' => 'core/html', 'html' => '<iframe src="https://player.test/1"></iframe>' ) ),
		),
	)
);
check( ! refused( $w ), 'ein unveraendertes Kind in einem geaenderten Container bleibt', message_of( $w['result'] ) );
check( false !== strpos( $w['stored'], '<iframe src="https://player.test/1"></iframe>' ), 'mit seinem Markup', $w['stored'] );

// But a block that holds such markup and is itself changed is the agent's
// block now, and goes through the same test as anything new.
page( 14, $para . $embed );
$w = write( 14, array( $plain, array( 'name' => 'core/html', 'html' => '<iframe src="https://player.test/1"></iframe><p onclick="alert(1)">x</p>' ) ) );
check( refused( $w ), 'ein geaenderter Block mit Embed wird geprueft wie neuer Inhalt', $w['stored'] );
check( false !== strpos( message_of( $w['result'] ), 'block 1' ), 'mit Pfad', message_of( $w['result'] ) );

echo "\n\033[1mWas kses nur umschreibt, ist kein Angriff\033[0m\n";

// Gutenberg writes <img .../>, kses rebuilds it as <img ... />. Taken
// literally, every image block an agent writes would be refused.
page( 17, $para );
$w = write( 17, array( $plain, array( 'name' => 'core/image', 'html' => '<figure class="wp-block-image"><img src="https://example.test/a.jpg" alt="" class="wp-image-5"/></figure>' ) ) );
check( ! refused( $w ), 'ein Bild-Block in Gutenberg-Schreibweise geht durch', message_of( $w['result'] ) );

page( 18, $para );
$w = write( 18, array( $p( '<p>Stegmeier & Weber</p>' ) ) );
check( ! refused( $w ), 'ein freistehendes & ebenso', message_of( $w['result'] ) );

// "&" before letters can be a character reference to a browser, so it
// is not waved through. The message shows the form that goes through.
page( 19, $para );
$w   = write( 19, array( $p( '<p>a&b</p>' ) ) );
$msg = message_of( $w['result'] );
check( refused( $w ), 'ein & direkt vor Buchstaben nicht' );
check( false !== strpos( $msg, 'a&amp;b' ), 'die Meldung zeigt, wie WordPress es speichern wuerde', $msg );

echo "\n\033[1mJSON-LD bleibt schreibbar\033[0m\n";

page( 15, $para );
$w = write( 15, array( $plain, $free( '<script type="application/ld+json">{"@context":"https://schema.org","@type":"FAQPage"}</script>' ) ) );
check( ! refused( $w ), 'gueltiges JSON-LD wird gespeichert', message_of( $w['result'] ) );
check( false !== strpos( $w['stored'], 'application/ld+json' ), 'und steht in der Datenbank', $w['stored'] );

page( 16, $para );
$w = write( 16, array( $plain, $free( '<script type="application/ld+json" onload="x()">{}</script>' ) ) );
check( refused( $w ), 'ein Script mit Zusatzattribut nicht' );

echo "\n\033[1mC2: der erhoehte Pfad prueft genauso\033[0m\n";

$GLOBALS['elevate'] = true;
foreach ( $vectors as $label => $html ) {
	page( 20, $para );
	$w = write( 20, array( $plain, $free( $html ) ) );
	check( refused( $w ), "mit unfiltered_html abgelehnt: {$label}", empty( $w['saves'] ) ? '' : $w['stored'] );
}

page( 21, $para . $embed );
$w = write( 21, array( $p( '<p>Neu</p>' ), array( 'name' => 'core/html', 'html' => '<iframe src="https://player.test/1"></iframe>' ) ) );
check( ! refused( $w ), 'eine gewoehnliche Aenderung geht mit unfiltered_html durch', message_of( $w['result'] ) );
check( false !== strpos( $w['stored'], '<iframe' ), 'und das Embed bleibt' );
check( empty( $GLOBALS['dbw_filters']['user_has_cap'] ), 'die Berechtigung ist danach wieder weg' );
$GLOBALS['elevate'] = false;

echo "\n\033[1mDie Tuer fuer Reparaturen bleibt eine Tuer\033[0m\n";

add_filter( 'wpmcp_allow_filtered_markup', '__return_true' );
page( 30, $para );
$w = write( 30, array( $plain, $free( '<iframe src="https://player.test/2"></iframe>' ) ) );
check( ! refused( $w ), 'geoeffnet geht auch neues Markup durch', message_of( $w['result'] ) );
check( false !== strpos( $w['stored'], '<iframe' ), 'und erreicht die Datenbank' );
check( ! empty( $w['result']['warnings'] ) && false !== strpos( implode( ' ', $w['result']['warnings'] ), 'wpmcp_allow_filtered_markup' ), 'mit Warnung' );
$GLOBALS['dbw_filters']['wpmcp_allow_filtered_markup'] = array();

echo "\n\033[1mcontent-create prueft im Probelauf genauso\033[0m\n";

$r = wpmcp_create_content( array( 'title' => 'X', 'tree' => array( $free( '<img src="x" onerror="alert(1)">' ) ) ) );
check( ! is_wp_error( $r ) && false === $r['ok'], 'onerror im neuen Baum faellt durch', is_wp_error( $r ) ? $r->get_error_message() : wp_json_encode( $r['errors'] ) );

echo "\n\033[1mDer Filter kommt immer zurueck, und nur der entfernte\033[0m\n";

if ( ! function_exists( 'wpmcp_without_kses' ) ) {
	check( false, 'wpmcp_without_kses() gibt es' );
} else {
	kses_on();
	$inside = null;
	$r      = wpmcp_without_kses(
		function () use ( &$inside ) {
			$inside = kses_active();
			return 42;
		}
	);
	check( 42 === $r, 'gibt das Ergebnis zurueck' );
	check( false === $inside, 'waehrenddessen ist kses aus' );
	check( kses_active(), 'danach wieder an' );
	check( 1 === count( $GLOBALS['dbw_filters']['content_save_pre'] ), 'und nur einmal' );

	kses_on();
	try {
		wpmcp_without_kses(
			function () {
				throw new \RuntimeException( 'Absturz im Save' );
			}
		);
	} catch ( \RuntimeException $e ) {
		unset( $e );
	}
	check( kses_active(), 'auch wenn der Save eine Exception wirft' );

	// An account with unfiltered_html never had the filter. Adding it back
	// would start filtering a human's saves for the rest of the request.
	$GLOBALS['dbw_filters']['content_save_pre']          = array();
	$GLOBALS['dbw_filters']['content_filtered_save_pre'] = array();
	wpmcp_without_kses( '__return_true' );
	check( ! kses_active(), 'ein Filter, der nicht da war, wird nicht hinzugefuegt' );
}

echo "\n\033[1mWiederherstellen einer Revision\033[0m\n";

// A revision is a state the post already held, so its blocks are known.
$GLOBALS['revisions'] = array(
	501 => (object) array( 'ID' => 501, 'post_parent' => 40, 'post_content' => $para . $embed, 'post_modified_gmt' => '2026-09-01 10:00:00' ),
);
page( 40, $para );
kses_on();
$GLOBALS['saves'] = array();
$r = wpmcp_restore_revision( 40, 501, false );
check( ! is_wp_error( $r ) && ! empty( $r['ok'] ), 'die Revision wird wiederhergestellt', is_wp_error( $r ) ? $r->get_error_message() : '' );
check( false !== strpos( $GLOBALS['posts'][40]->post_content, '<iframe' ), 'mit ihrem Embed', $GLOBALS['posts'][40]->post_content );
check( kses_active(), 'und der Filter ist danach wieder gesetzt' );

echo "\n";
if ( 0 === $fail ) {
	echo "\033[32mMarkup-Waechter in Ordnung.\033[0m\n";
	exit( 0 );
}
echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
exit( 1 );
