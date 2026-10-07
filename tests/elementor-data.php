<?php
/**
 * Elementor pages as a tree of elements: operations, ids, the outline and
 * what the markup guard lets through.
 *
 * The first customer site on Elementor keeps every section as one HTML
 * widget holding raw markup, some of it with a script for a slider or
 * the reviews. An SEO rework there means changing a heading three
 * characters away from such a script. Elementor's own save would run
 * kses over the whole page for the agent account and take every script
 * on it; these tests pin the rule that replaces that filter, and the
 * tree operations the rework needs (texts, headings, an FAQ section
 * inserted, moved, duplicated, removed), without WordPress or Elementor.
 * tests/wp-real/elementor does the same against both.
 *
 * Run: php tests/elementor-data.php
 *
 * @package wp-mcp-connector-plus
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/kses-stub.php';
require_once dirname( __DIR__ ) . '/includes/tree.php';
require_once dirname( __DIR__ ) . '/includes/markup-guard.php';
require_once dirname( __DIR__ ) . '/includes/elementor/data.php';
require_once dirname( __DIR__ ) . '/includes/elementor/outline.php';
require_once dirname( __DIR__ ) . '/includes/elementor/guard.php';

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

function code_of( $result ) {
	return is_wp_error( $result ) ? $result->get_error_code() : '(kein Fehler)';
}

const SLIDER = '<script>document.querySelectorAll(".slide").forEach(function(s){s.hidden=false;});</script>';
const HERO   = '<section class="hero"><h1>Bootsführerschein in Zürich</h1><p>Wir machen dich fit für den Schein, ganz ohne Stress und mit Spaß.</p><a href="/kurse/">Zu den Kursen</a><img src="/boot.jpg" alt="Segelboot auf dem See">' . SLIDER . '</section>';

function page() {
	return array(
		array(
			'id'       => 'c000001',
			'elType'   => 'container',
			'settings' => array(),
			'isInner'  => false,
			'elements' => array(
				array(
					'id'         => 'w000001',
					'elType'     => 'widget',
					'widgetType' => 'html',
					'settings'   => array( 'html' => HERO ),
					'elements'   => array(),
				),
			),
		),
		array(
			'id'       => 'c000002',
			'elType'   => 'container',
			'settings' => array( '_title' => 'Leistungen' ),
			'isInner'  => false,
			'elements' => array(
				array(
					'id'         => 'w000002',
					'elType'     => 'widget',
					'widgetType' => 'heading',
					'settings'   => array(
						'title'       => 'Unsere Leistungen',
						'header_size' => 'h2',
					),
					'elements'   => array(),
				),
				array(
					'id'       => 'c000003',
					'elType'   => 'container',
					'settings' => array(),
					'isInner'  => true,
					'elements' => array(
						array(
							'id'         => 'w000003',
							'elType'     => 'widget',
							'widgetType' => 'html',
							'settings'   => array( 'html' => '<h3>Kurse</h3><p>Binnen &amp; See</p>' ),
							'elements'   => array(),
						),
					),
				),
			),
		),
	);
}

function apply( array $ops, ?array $elements = null ) {
	return wpmcp_elementor_apply_ops( null === $elements ? page() : $elements, $ops );
}

function setting_of( array $elements, $id, $key ) {
	$index = wpmcp_elementor_index( $elements );
	$el    = wpmcp_elementor_at( $elements, $index[ $id ] );
	return wpmcp_elementor_setting_get( $el['settings'], $key );
}

echo "\n\033[1mIds und Pfade\033[0m\n";

$index = wpmcp_elementor_index( page() );
check( array( 1, 1, 0 ) === $index['w000003'], 'ein Widget im inneren Container liegt auf 1.1.0' );
check( 6 === wpmcp_elementor_count_all( page() ), 'sechs Elemente auf der Seite' );

$taken = array_fill_keys( array_keys( $index ), true );
$ids   = array();
for ( $i = 0; $i < 2000; $i++ ) {
	$ids[] = wpmcp_elementor_new_id( $taken );
}
check( 2000 === count( array_unique( $ids ) ), '2000 neue Ids sind verschieden' );
check( 0 === count( array_filter( $ids, function ( $id ) { return ! preg_match( '/^[0-9a-f]{7}$/', $id ); } ) ), 'jede hat die Form des Editors: sieben Hexziffern' );
check( empty( array_intersect( $ids, array_keys( $index ) ) ), 'keine trifft eine Id, die schon auf der Seite steht' );

echo "\n\033[1mpatch_html\033[0m\n";

$r = apply( array( array( 'op' => 'patch_html', 'id' => 'w000001', 'find' => '<h1>Bootsführerschein in Zürich</h1>', 'replace' => '<h2>Bootsführerschein in Zürich</h2>' ) ) );
check( ! is_wp_error( $r ), 'H1 zu H2 im HTML-Widget', code_of( $r ) );
$html = is_wp_error( $r ) ? '' : setting_of( $r['elements'], 'w000001', 'html' );
check( false !== strpos( $html, '<h2>Bootsführerschein in Zürich</h2>' ), 'die Ueberschrift ist jetzt eine H2, Umlaute unversehrt' );
check( false !== strpos( $html, SLIDER ), 'das Skript daneben steht byte-gleich da' );
check( ! is_wp_error( $r ) && 1 === $r['confirm'][0]['replaced'] && '0.0' === $r['confirm'][0]['path'], 'die Bestaetigung nennt Anzahl und Pfad' );
check( ! is_wp_error( $r ) && false !== strpos( $r['confirm'][0]['now'], 'Zürich</h2>' ), 'und zeigt, wie die Stelle jetzt lautet' );

$r = apply( array( array( 'op' => 'patch_html', 'id' => 'w000001', 'find' => 'gibt es nicht', 'replace' => 'x' ) ) );
check( 'wpmcp_anchor_not_unique' === code_of( $r ), 'ein Anker, der nicht vorkommt, wird abgelehnt' );

$twice = page();
$twice[0]['elements'][0]['settings']['html'] = '<p>Kurs</p><p>Kurs</p>';
$r = apply( array( array( 'op' => 'patch_html', 'id' => 'w000001', 'find' => 'Kurs', 'replace' => 'Lehrgang' ) ), $twice );
check( 'wpmcp_anchor_not_unique' === code_of( $r ), 'zweimal vorhanden ohne "all": abgelehnt' );
$r = apply( array( array( 'op' => 'patch_html', 'id' => 'w000001', 'find' => 'Kurs', 'replace' => 'Lehrgang', 'all' => true ) ), $twice );
check( ! is_wp_error( $r ) && '<p>Lehrgang</p><p>Lehrgang</p>' === setting_of( $r['elements'], 'w000001', 'html' ) && 2 === $r['confirm'][0]['replaced'], 'mit "all": beide, und die Bestaetigung sagt 2' );

$r = apply( array( array( 'op' => 'patch_html', 'id' => 'w000002', 'find' => 'Unsere', 'replace' => 'Alle' ) ) );
check( 'wpmcp_bad_op' === code_of( $r ), 'an einer Heading-Widget ohne "setting": fragt nach der Einstellung' );
$r = apply( array( array( 'op' => 'patch_html', 'id' => 'w000002', 'setting' => 'title', 'find' => 'Unsere', 'replace' => 'Alle' ) ) );
check( ! is_wp_error( $r ) && 'Alle Leistungen' === setting_of( $r['elements'], 'w000002', 'title' ), 'mit "setting": "title" geaendert' );

$r = apply( array( array( 'op' => 'patch_html', 'id' => 'zzzzzzz', 'find' => 'a', 'replace' => 'b' ) ) );
check( 'wpmcp_element_not_found' === code_of( $r ), 'eine unbekannte Id: wpmcp_element_not_found' );

echo "\n\033[1mset_html und set_settings\033[0m\n";

$r = apply( array( array( 'op' => 'set_html', 'id' => 'w000003', 'html' => '<h3>Alle Kurse</h3>' ) ) );
check( ! is_wp_error( $r ) && '<h3>Alle Kurse</h3>' === setting_of( $r['elements'], 'w000003', 'html' ), 'set_html ersetzt das Markup' );
$r = apply( array( array( 'op' => 'set_html', 'id' => 'w000002', 'html' => '<h2>x</h2>' ) ) );
check( 'wpmcp_bad_op' === code_of( $r ), 'set_html an einem Heading-Widget: abgelehnt, set_settings ist der Weg' );

$r = apply( array( array( 'op' => 'set_settings', 'id' => 'w000002', 'settings' => array( 'header_size' => 'h3', 'title' => null ) ) ) );
check( ! is_wp_error( $r ) && 'h3' === setting_of( $r['elements'], 'w000002', 'header_size' ) && null === setting_of( $r['elements'], 'w000002', 'title' ), 'set_settings fuehrt zusammen, null entfernt' );
$r = apply( array( array( 'op' => 'set_settings', 'id' => 'w000002', 'settings' => array( 'h3' ) ) ) );
check( 'wpmcp_bad_op' === code_of( $r ), 'eine Liste statt eines Objekts wird abgelehnt' );

echo "\n\033[1minsert: ein FAQ-Abschnitt\033[0m\n";

$faq = array(
	'id'       => 'eigene1',
	'elType'   => 'container',
	'elements' => array(
		array(
			'elType'     => 'widget',
			'widgetType' => 'html',
			'settings'   => array( 'html' => '<h2>Häufige Fragen</h2><h3>Wie lange dauert der Kurs?</h3><p>Zwei Wochenenden.</p>' ),
		),
	),
);
$r = apply( array( array( 'op' => 'insert', 'element' => $faq, 'after' => 'c000002' ) ) );
check( ! is_wp_error( $r ), 'nach dem letzten Abschnitt eingefuegt', code_of( $r ) );
if ( ! is_wp_error( $r ) ) {
	$new = $r['elements'][2];
	check( 'eigene1' !== $new['id'] && preg_match( '/^[0-9a-f]{7}$/', $new['id'] ), 'die mitgeschickte Id wird nicht uebernommen, eine neue erzeugt' );
	check( false === $new['isInner'], 'ein Container auf der Seite ist nicht inner' );
	check( ! array_key_exists( 'isInner', $new['elements'][0] ) && preg_match( '/^[0-9a-f]{7}$/', $new['elements'][0]['id'] ), 'das Widget bekommt eine Id und kein isInner' );
	check( array() === $new['settings'] && array() === $new['elements'][0]['elements'], 'fehlende settings und elements werden leer angelegt' );
	check( array( $new['id'] ) === $r['confirm'][0]['created'] && array( '2' ) === $r['confirm'][0]['paths'], 'die Bestaetigung nennt Id und Pfad' );
}

$r = apply( array( array( 'op' => 'insert', 'element' => $faq, 'inside' => 'c000002' ) ) );
check( ! is_wp_error( $r ) && true === $r['elements'][1]['elements'][2]['isInner'], 'in einen Container: ans Ende, als inner' );
$r = apply( array( array( 'op' => 'insert', 'element' => $faq, 'inside' => 'root' ) ) );
check( ! is_wp_error( $r ) && 3 === count( $r['elements'] ), 'inside "root": ans Ende der Seite' );
$r = apply( array( array( 'op' => 'insert', 'element' => $faq, 'before' => 'c000001' ) ) );
check( ! is_wp_error( $r ) && 'c000001' === $r['elements'][1]['id'], 'before: davor' );
$r = apply( array( array( 'op' => 'insert', 'element' => $faq, 'after' => 'c000001', 'inside' => 'root' ) ) );
check( 'wpmcp_bad_op' === code_of( $r ), 'zwei Ortsangaben: abgelehnt' );

$widget = array( 'elType' => 'widget', 'widgetType' => 'html', 'settings' => array( 'html' => '<p>x</p>' ) );
$r      = apply( array( array( 'op' => 'insert', 'element' => $widget, 'inside' => 'root' ) ) );
check( 'wpmcp_bad_op' === code_of( $r ), 'ein Widget direkt auf der Seite: abgelehnt' );
$r = apply( array( array( 'op' => 'insert', 'element' => $faq, 'inside' => 'w000001' ) ) );
check( 'wpmcp_bad_op' === code_of( $r ), 'ein Container in einem Widget: abgelehnt' );
$r = apply( array( array( 'op' => 'insert', 'element' => array( 'elType' => 'container', 'elements' => array( array( 'elType' => 'column' ) ) ), 'inside' => 'root' ) ) );
check( 'wpmcp_bad_element' === code_of( $r ), 'eine Spalte im Container: wpmcp_bad_element' );
$r = apply( array( array( 'op' => 'insert', 'element' => array( 'elType' => 'widget' ), 'inside' => 'c000001' ) ) );
check( 'wpmcp_bad_element' === code_of( $r ), 'ein Widget ohne widgetType: wpmcp_bad_element' );
$r = apply( array( array( 'op' => 'insert', 'element' => array( 'elType' => 'widget', 'widgetType' => 'e-heading' ), 'inside' => 'c000001' ) ) );
check( 'wpmcp_elementor_atomic' === code_of( $r ), 'ein Element des Atomic-Editors: wpmcp_elementor_atomic' );

echo "\n\033[1mduplicate, move, remove\033[0m\n";

$r = apply( array( array( 'op' => 'duplicate', 'id' => 'c000002' ) ) );
check( ! is_wp_error( $r ) && 3 === count( $r['elements'] ), 'die Kopie steht direkt hinter dem Original' );
if ( ! is_wp_error( $r ) ) {
	$copy_ids = array_keys( wpmcp_elementor_index( array( $r['elements'][2] ) ) );
	check( 4 === count( $copy_ids ) && empty( array_intersect( $copy_ids, array_keys( $index ) ) ), 'jedes Element der Kopie hat eine neue Id' );
	check( $r['elements'][1] === page()[1], 'das Original ist unveraendert' );
	check( 4 === $r['confirm'][0]['elements'], 'die Bestaetigung zaehlt die kopierten Elemente' );
}

$r = apply( array( array( 'op' => 'move', 'id' => 'c000003', 'before' => 'c000001' ) ) );
check( ! is_wp_error( $r ) && 'c000003' === $r['elements'][0]['id'] && false === $r['elements'][0]['isInner'], 'ein innerer Container nach oben auf die Seite: nicht mehr inner' );
$r = apply( array( array( 'op' => 'move', 'id' => 'c000001', 'inside' => 'c000003' ) ) );
check( ! is_wp_error( $r ) && true === wpmcp_elementor_at( $r['elements'], wpmcp_elementor_index( $r['elements'] )['c000001'] )['isInner'], 'und umgekehrt: in einen Container, jetzt inner' );
$r = apply( array( array( 'op' => 'move', 'id' => 'c000002', 'inside' => 'c000003' ) ) );
check( 'wpmcp_bad_op' === code_of( $r ), 'in sich selbst verschieben: abgelehnt' );
$r = apply( array( array( 'op' => 'move', 'id' => 'w000002', 'after' => 'c000001' ) ) );
check( 'wpmcp_bad_op' === code_of( $r ), 'ein Widget neben einen Abschnitt auf die Seite: abgelehnt' );

$r = apply( array( array( 'op' => 'remove', 'id' => 'c000002' ) ) );
check( ! is_wp_error( $r ) && 1 === count( $r['elements'] ) && 4 === $r['confirm'][0]['elements'], 'remove nimmt den Abschnitt mit allem darin' );

$r = apply(
	array(
		array( 'op' => 'insert', 'element' => $faq, 'after' => 'c000001' ),
		array( 'op' => 'remove', 'id' => 'c000001' ),
		array( 'op' => 'patch_html', 'id' => 'w000003', 'find' => 'Kurse', 'replace' => 'Lehrgänge' ),
	)
);
check( ! is_wp_error( $r ) && 2 === count( $r['elements'] ) && '1.1.0' === $r['confirm'][2]['path'] && '0' === $r['confirm'][0]['paths'][0], 'mehrere Operationen der Reihe nach, Pfade nach der letzten' );

$r = apply( array( array( 'op' => 'replace', 'id' => 'w000001' ) ) );
check( 'wpmcp_bad_op' === code_of( $r ), 'eine unbekannte Operation: wpmcp_bad_op' );
$r = apply( array() );
check( 'wpmcp_bad_request' === code_of( $r ), 'keine Operationen: wpmcp_bad_request' );

echo "\n\033[1mGespeicherte Daten\033[0m\n";

check( 'wpmcp_elementor_data_invalid' === code_of( wpmcp_elementor_decode( '[{"id":' ) ), 'kaputtes JSON: wpmcp_elementor_data_invalid' );
check( array() === wpmcp_elementor_decode( '' ), 'leere Seite: keine Elemente' );
check( array( 'c000001:_title' ) === array_keys( wpmcp_elementor_strings( array( array( 'id' => 'c000001', 'settings' => array( '_title' => 'x', 'n' => 3 ) ) ) ) ), 'Zeichenketten sind nach Element und Einstellung benannt' );
check( array( 'w1:link.url' => 'https://x' ) === wpmcp_elementor_strings( array( array( 'id' => 'w1', 'settings' => array( 'link' => array( 'url' => 'https://x' ) ) ) ) ), 'auch verschachtelte (link.url)' );
check( array( 'e1' ) === wpmcp_elementor_atomic_ids( array( array( 'id' => 'c1', 'elType' => 'container', 'elements' => array( array( 'id' => 'e1', 'elType' => 'widget', 'widgetType' => 'e-heading' ) ) ) ) ), 'Atomic-Elemente werden gefunden' );

echo "\n\033[1mGliederung eines HTML-Widgets\033[0m\n";

$outline = wpmcp_elementor_html_outline( HERO . '<p><img src="/deko.png" alt=""></p><script type="application/ld+json">{"@type":"FAQPage"}</script>' );
check( array( array( 'level' => 1, 'text' => 'Bootsführerschein in Zürich' ) ) === $outline['headings'], 'Ueberschrift mit Ebene und Umlauten' );
check( 'Wir machen dich fit für den Schein, ganz ohne Stress und mit Spaß.' === $outline['paragraphs'][0], 'der Absatz, entschluesselt' );
check( array( 'href' => '/kurse/', 'text' => 'Zu den Kursen' ) === $outline['links'][0], 'Link mit Ziel und Text' );
check( 'Segelboot auf dem See' === $outline['images'][0]['alt'] && '' === $outline['images'][1]['alt'], 'Bilder mit Alt-Text, ein leerer bleibt leer' );
check( 2 === $outline['scripts'] && 1 === $outline['jsonLd'], 'Skripte gezaehlt, strukturierte Daten getrennt' );
check( strlen( HERO ) + 0 < $outline['bytes'], 'Groesse in Bytes' );
check( false === strpos( wp_json_encode( $outline ), 'querySelectorAll' ), 'der Code des Skripts erscheint nirgends als Text' );
$missing = wpmcp_elementor_html_outline( '<img src="/a.jpg">' );
check( null === $missing['images'][0]['alt'], 'ein Bild ganz ohne alt: null, nicht leer' );
$many = wpmcp_elementor_html_outline( str_repeat( '<h3>Frage</h3>', 45 ) );
check( 40 === count( $many['headings'] ) && 5 === $many['more']['headings'], 'lange Listen werden gekappt und sagen, wie viele fehlen' );

$tree = wpmcp_elementor_outline( page() );
check( 'Leistungen' === $tree[1]['title'] && 'Unsere Leistungen' === $tree[1]['elements'][0]['summary']['title'] && 'h2' === $tree[1]['elements'][0]['summary']['headerSize'], 'die Seite: Navigator-Name, Heading-Widget mit Text und Ebene' );
check( '1.1.0' === $tree[1]['elements'][1]['elements'][0]['path'] && isset( $tree[0]['elements'][0]['html']['headings'] ), 'Pfade und HTML-Gliederung im Baum' );

echo "\n\033[1mWas eine Aenderung speichern darf\033[0m\n";

function guard( array $ops, ?array $elements = null ) {
	$before = null === $elements ? page() : $elements;
	$r      = wpmcp_elementor_apply_ops( $before, $ops );
	if ( is_wp_error( $r ) ) {
		return array( 'errors' => array( $r->get_error_message() ), 'warnings' => array(), 'kept' => array() );
	}
	return wpmcp_elementor_guard( $before, wpmcp_elementor_normalize_jsonld( $r['elements'], wpmcp_elementor_strings( $before ) ) );
}

$g = guard( array( array( 'op' => 'patch_html', 'id' => 'w000001', 'find' => 'Zürich', 'replace' => 'Zürich und Luzern' ) ) );
check( empty( $g['errors'] ), 'Text neben dem Slider-Skript aendern: geht durch', implode( ' | ', $g['errors'] ) );
check( array( SLIDER ) === $g['kept'], 'und das Skript bleibt, weil es schon da war' );

$g = guard( array( array( 'op' => 'patch_html', 'id' => 'w000003', 'find' => '</p>', 'replace' => '</p><script>fetch("/x")</script>' ) ) );
check( ! empty( $g['errors'] ) && false !== strpos( $g['errors'][0], 'new: not in the stored page' ) && false !== strpos( $g['errors'][0], 'element w000003:html' ), 'ein neues Skript: abgelehnt, mit Element und Fundstueck' );

$g = guard( array( array( 'op' => 'patch_html', 'id' => 'w000003', 'find' => '<p>', 'replace' => '<p><img src="x" onerror="fetch(1)">' ) ) );
check( ! empty( $g['errors'] ), 'ein neues onerror: abgelehnt' );

$g = guard( array( array( 'op' => 'patch_html', 'id' => 'w000001', 'find' => 's.hidden=false', 'replace' => 's.hidden=true' ) ) );
check( ! empty( $g['errors'] ), 'das Skript um ein Zeichen geaendert: abgelehnt' );

$g = guard( array( array( 'op' => 'duplicate', 'id' => 'c000001' ) ) );
check( ! empty( $g['errors'] ) && false !== strpos( $g['errors'][0], '2 times where it had it 1' ), 'einen Abschnitt mit Skript verdoppeln: abgelehnt, das Skript waere zweimal da' );

$g = guard(
	array(
		array( 'op' => 'set_html', 'id' => 'w000003', 'html' => '<h3>Kurse</h3>' . SLIDER ),
		array( 'op' => 'patch_html', 'id' => 'w000001', 'find' => SLIDER, 'replace' => '' ),
	)
);
check( empty( $g['errors'] ), 'ein Skript in ein anderes Widget verschieben: geht, die Seite hat es so oft wie vorher', implode( ' | ', $g['errors'] ) );

$g = guard( array( array( 'op' => 'set_html', 'id' => 'w000003', 'html' => '<h3>FAQ</h3><script type="application/ld+json">{"@type":"FAQPage","name":"Fragen & Antworten"}</script>' ) ) );
check( empty( $g['errors'] ), 'strukturierte Daten (JSON-LD) duerfen neu sein', implode( ' | ', $g['errors'] ) );

$before = page();
$r      = wpmcp_elementor_apply_ops( $before, array( array( 'op' => 'set_html', 'id' => 'w000003', 'html' => '<script type="application/ld+json">{"name":"<b>Kurs</b>"}</script>' ) ) );
$after  = wpmcp_elementor_normalize_jsonld( $r['elements'], wpmcp_elementor_strings( $before ) );
check( false !== strpos( setting_of( $after, 'w000003', 'html' ), '\u003Cb' ) && false === strpos( setting_of( $after, 'w000003', 'html' ), '<b>' ), 'ein "<" in JSON-LD wird als \u003C gespeichert' );
check( empty( wpmcp_elementor_guard( $before, $after )['errors'] ), 'und ist dann sicher' );

$g = guard( array( array( 'op' => 'set_settings', 'id' => 'w000002', 'settings' => array( 'link' => array( 'url' => ' java&#x09;script:alert(document)' ) ) ) ) );
check( ! empty( $g['errors'] ) && false !== strpos( $g['errors'][0], 'w000002:link.url' ), 'ein javascript:-Link in einer Einstellung: abgelehnt, auch verschleiert' );
$g = guard( array( array( 'op' => 'set_settings', 'id' => 'w000002', 'settings' => array( 'link' => array( 'url' => 'https://example.org/?a=1&b=2' ) ) ) ) );
check( empty( $g['errors'] ), 'ein https-Link mit & geht durch' );
$g = guard( array( array( 'op' => 'set_settings', 'id' => 'w000002', 'settings' => array( '__dynamic__' => array( 'title' => '[elementor-tag id="1" name="shortcode"]' ) ) ) ) );
check( ! empty( $g['errors'] ) && false !== strpos( $g['errors'][0], 'Dynamic tags' ), 'ein dynamisches Tag: abgelehnt' );
$g = guard( array( array( 'op' => 'set_settings', 'id' => 'w000002', 'settings' => array( 'title' => 'Müller & Söhne' ) ) ) );
check( empty( $g['errors'] ), 'Text mit & ohne Markup geht durch' );

$stored = page();
$stored[1]['elements'][0]['settings']['title'] = '<span onclick="x()">Alt</span>';
$g = guard( array( array( 'op' => 'patch_html', 'id' => 'w000003', 'find' => 'Kurse', 'replace' => 'Lehrgaenge' ) ), $stored );
check( empty( $g['errors'] ), 'was in einem unberuehrten Element steht, geht diese Aenderung nichts an' );

echo "\n";
if ( $fail ) {
	echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
	exit( 1 );
}
echo "\033[32mElementor-Daten in Ordnung.\033[0m\n";
