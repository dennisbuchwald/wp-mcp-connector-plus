<?php
/**
 * Keys the connector does not know are refused, never ignored.
 *
 * On staging.maxport.ch a core/shortcode went in as
 * {"name":"core/shortcode","attributes":{"text":"[contact-form-7]"}}.
 * The node key the connector reads is "attrs"; "attributes" was dropped
 * without a word, the dry run said ok and the page got an empty block.
 * Agents send "attributes" for a reason: blocks-describe answers with a
 * field of that name. So "attributes" is now another name for "attrs",
 * and every other key a node, an operation or an Elementor element does
 * not have is refused, with the keys it does have and the nearest one.
 *
 * Run: php tests/unknown-keys.php
 *
 * @package wp-mcp-connector-plus
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/kses-stub.php';
require_once dirname( __DIR__ ) . '/includes/markup-guard.php';
require_once dirname( __DIR__ ) . '/includes/elementor/data.php';

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

$registry = WP_Block_Type_Registry::get_instance();
$registry->register( 'core/shortcode', array( 'attributes' => array( 'text' => array( 'type' => 'string', 'source' => 'raw' ) ) ) );

/**
 * Build a tree the way content-write does.
 *
 * @param array $nodes Nodes.
 * @return array { blocks, errors, context }
 */
function build( array $nodes ) {
	$errors  = array();
	$context = wpmcp_wrapper_context();
	$blocks  = wpmcp_tree_to_blocks( $nodes, '', $errors, $context );
	return array(
		'blocks'  => $blocks,
		'errors'  => $errors,
		'context' => $context,
	);
}

function code_of( $result ) {
	return is_wp_error( $result ) ? $result->get_error_code() : '(kein Fehler)';
}

function text_of( $result ) {
	return is_wp_error( $result ) ? $result->get_error_message() : wp_json_encode( $result );
}

$shortcode = '[contact-form-7 id="1"]';
$page      = parse_blocks( '<!-- wp:paragraph --><p>Eins</p><!-- /wp:paragraph -->' );

echo "\n\033[1m\"attributes\" ist ein anderer Name fuer \"attrs\"\033[0m\n";

$r = build( array( array( 'name' => 'core/shortcode', 'attributes' => array( 'text' => $shortcode ) ) ) );
check( empty( $r['errors'] ), 'core/shortcode mit attributes.text wird angenommen (Feldbericht maxport)', implode( ' ', $r['errors'] ) );
check( $shortcode === ( $r['blocks'][0]['innerHTML'] ?? null ), 'und als Shortcode gespeichert, nicht leer', wp_json_encode( $r['blocks'][0] ?? null ) );

$r = build( array( array( 'name' => 'core/heading', 'attributes' => array( 'level' => 3 ), 'html' => '<h3>Titel</h3>' ) ) );
check( 3 === ( $r['blocks'][0]['attrs']['level'] ?? null ), 'attributes landen im Kommentar wie attrs', wp_json_encode( $r['blocks'][0]['attrs'] ?? null ) );

$r = build( array( array( 'name' => 'core/heading', 'attrs' => array( 'level' => 3 ), 'attributes' => array( 'level' => 3 ), 'html' => '<h3>Titel</h3>' ) ) );
check( empty( $r['errors'] ), 'attrs und attributes gleich: angenommen', implode( ' ', $r['errors'] ) );

$r = build( array( array( 'name' => 'core/heading', 'attrs' => array( 'level' => 3 ), 'attributes' => array( 'level' => 4 ), 'html' => '<h3>Titel</h3>' ) ) );
check( ! empty( $r['errors'] ), 'attrs und attributes verschieden: abgelehnt' );
check( false !== strpos( implode( ' ', $r['errors'] ), '"attributes"' ) && false !== strpos( implode( ' ', $r['errors'] ), '"attrs"' ), 'die Meldung nennt beide', implode( ' ', $r['errors'] ) );
check( ! empty( $r['context']->unknown ), 'und zaehlt als wpmcp_unknown_key', wp_json_encode( $r['context']->unknown ?? null ) );

$r = build(
	array(
		array(
			'name'        => 'core/group',
			'attributes'  => array( 'tagName' => 'section' ),
			'innerBlocks' => array( array( 'name' => 'core/shortcode', 'attributes' => array( 'text' => $shortcode ) ) ),
			'htmlTemplate' => array( '<section class="wp-block-group">', null, '</section>' ),
		),
	)
);
check( $shortcode === ( $r['blocks'][0]['innerBlocks'][0]['innerHTML'] ?? null ), 'auch verschachtelt', implode( ' ', $r['errors'] ) );

echo "\n\033[1mUnbekannte Schluessel an einem Knoten werden abgelehnt\033[0m\n";

$r = build( array( array( 'name' => 'core/group', 'children' => array( array( 'name' => 'core/paragraph', 'html' => '<p>x</p>' ) ) ) ) );
$e = implode( ' ', $r['errors'] );
check( ! empty( $r['errors'] ), '"children" statt innerBlocks: abgelehnt, nicht als leere Gruppe gespeichert' );
check( false !== strpos( $e, '0: unknown key "children"' ), 'mit Pfad und Schluessel', $e );
check( false !== strpos( $e, 'did you mean "innerBlocks"' ), 'mit Vorschlag innerBlocks', $e );
check( false !== strpos( $e, 'name, attrs' ) && false !== strpos( $e, 'htmlTemplate' ), 'mit den erlaubten Schluesseln', $e );
check( array( '0' ) === array_column( $r['context']->unknown ?? array(), 'path' ), 'context->unknown nennt den Pfad', wp_json_encode( $r['context']->unknown ) );

$r = build( array( array( 'name' => 'core/paragraph', 'innerHTML' => '<p>x</p>' ) ) );
check( false !== strpos( implode( ' ', $r['errors'] ), 'did you mean "html"' ), 'innerHTML: Vorschlag html', implode( ' ', $r['errors'] ) );

$r = build( array( array( 'name' => 'core/paragraph', 'html' => '<p>x</p>', 'attrz' => array() ) ) );
check( false !== strpos( implode( ' ', $r['errors'] ), 'did you mean "attrs"' ), 'Tippfehler attrz: Vorschlag attrs', implode( ' ', $r['errors'] ) );

$r = build( array( array( 'name' => 'core/paragraph', 'html' => '<p>x</p>', 'colour' => 'red' ) ) );
check( ! empty( $r['errors'] ) && false === strpos( implode( ' ', $r['errors'] ), 'did you mean' ), 'ohne nahen Schluessel kein Vorschlag', implode( ' ', $r['errors'] ) );

$r = build( array( array( 'name' => 'core/group', 'htmlTemplate' => array( '<div class="wp-block-group">', null, '</div>' ), 'innerBlocks' => array( array( 'name' => 'core/paragraph', 'html' => '<p>x</p>', 'children' => array() ) ) ) ) );
check( false !== strpos( implode( ' ', $r['errors'] ), '0.0: unknown key "children"' ), 'verschachtelt mit Pfad 0.0', implode( ' ', $r['errors'] ) );

$r = build( array( array( 'name' => null, 'html' => '<p>frei</p>', 'attrs' => array( 'x' => 1 ) ) ) );
check( ! empty( $r['errors'] ), 'freies HTML (name null) mit attrs: abgelehnt, attrs wuerden nicht gespeichert', implode( ' ', $r['errors'] ) );

echo "\n\033[1mWas content-read liefert, geht unveraendert zurueck\033[0m\n";

$read = json_decode( wp_json_encode( wpmcp_blocks_to_tree( parse_blocks( '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>A</p><!-- /wp:paragraph --></div><!-- /wp:group --><p>frei</p>' ) ) ), true );
$r    = build( $read );
check( empty( $r['errors'] ), 'Knoten aus content-read (mit path, htmlTemplate, freiem HTML) werden angenommen', implode( ' ', $r['errors'] ) . ' ' . wp_json_encode( $read ) );

echo "\n\033[1mOperationen: nur ihre eigenen Schluessel\033[0m\n";

$r = wpmcp_apply_ops( $page, array( array( 'op' => 'set_attrs', 'path' => '0', 'attributes' => array( 'style' => array( 'x' => 1 ) ) ) ) );
check( ! is_wp_error( $r ) && 1 === ( $r['blocks'][0]['attrs']['style']['x'] ?? null ), 'set_attrs mit attributes statt attrs', text_of( $r ) );

$r = wpmcp_apply_ops( $page, array( array( 'op' => 'set_attrs', 'path' => '0', 'attrs' => array( 'a' => 1 ), 'attributes' => array( 'a' => 2 ) ) ) );
check( 'wpmcp_unknown_key' === code_of( $r ), 'set_attrs mit verschiedenen attrs und attributes: wpmcp_unknown_key', text_of( $r ) );

$r = wpmcp_apply_ops( $page, array( array( 'op' => 'insert', 'path' => '1', 'block' => array( 'name' => 'core/shortcode', 'attributes' => array( 'text' => $shortcode ) ) ) ) );
check( ! is_wp_error( $r ) && $shortcode === ( $r['blocks'][1]['innerHTML'] ?? null ), 'insert mit attributes im Block', text_of( $r ) );

$r = wpmcp_apply_ops( $page, array( array( 'op' => 'insert', 'path' => '1', 'block' => array( 'name' => 'core/paragraph', 'html' => '<p>x</p>', 'children' => array() ) ) ) );
check( 'wpmcp_unknown_key' === code_of( $r ), 'insert mit unbekanntem Schluessel im Block: wpmcp_unknown_key', text_of( $r ) );
check( false !== strpos( text_of( $r ), 'op0.0: unknown key "children"' ), 'mit Pfad im Block', text_of( $r ) );

$r = wpmcp_apply_ops( $page, array( array( 'op' => 'patch_html', 'path' => '0', 'find' => 'Eins', 'replace' => 'Zwei', 'all' => true ) ) );
check( 'wpmcp_unknown_key' === code_of( $r ), 'patch_html mit "all" (gibt es hier nicht): abgelehnt', text_of( $r ) );
check( false !== strpos( text_of( $r ), 'Operation 0 (patch_html): unknown key "all"' ), 'die Meldung nennt Operation und Schluessel', text_of( $r ) );
check( false !== strpos( text_of( $r ), 'op, path, find, replace' ), 'und was patch_html nimmt', text_of( $r ) );

$r = wpmcp_apply_ops( $page, array( array( 'op' => 'move', 'path' => '0', 'target' => '1' ) ) );
check( false !== strpos( text_of( $r ), 'did you mean "to"' ) || false !== strpos( text_of( $r ), 'unknown key "target"' ), 'move mit "target": abgelehnt', text_of( $r ) );

$r = wpmcp_apply_ops( $page, array( array( 'op' => 'remove', 'path' => '0', 'block' => array( 'name' => 'core/paragraph' ) ) ) );
check( 'wpmcp_unknown_key' === code_of( $r ), 'remove mit "block": abgelehnt (gehoert zu insert/replace)', text_of( $r ) );

$r = wpmcp_apply_ops( $page, array( array( 'op' => 'replace', 'path' => '0', 'block' => array( 'name' => 'core/paragraph', 'html' => '<p>Neu</p>' ) ) ) );
check( ! is_wp_error( $r ), 'eine gueltige Operation geht weiter', text_of( $r ) );

echo "\n\033[1mElementor: Operationen und eingefuegte Elemente\033[0m\n";

$elements = array(
	array(
		'id'       => 'c000001',
		'elType'   => 'container',
		'settings' => array(),
		'elements' => array(
			array(
				'id'         => 'w000001',
				'elType'     => 'widget',
				'widgetType' => 'html',
				'settings'   => array( 'html' => '<h2>Alt</h2>' ),
				'elements'   => array(),
			),
		),
		'isInner'  => false,
	),
);

$r = wpmcp_elementor_apply_ops( $elements, array( array( 'op' => 'patch_html', 'id' => 'w000001', 'find' => 'Alt', 'replace' => 'Neu', 'all' => true ) ) );
check( ! is_wp_error( $r ), 'patch_html mit all ist dort gueltig', text_of( $r ) );

$r = wpmcp_elementor_apply_ops( $elements, array( array( 'op' => 'set_html', 'id' => 'w000001', 'html' => '<h2>Neu</h2>', 'find' => 'Alt' ) ) );
check( 'wpmcp_unknown_key' === code_of( $r ), 'set_html mit find: abgelehnt', text_of( $r ) );
check( false !== strpos( text_of( $r ), 'op, id, html' ), 'nennt, was set_html nimmt', text_of( $r ) );

$r = wpmcp_elementor_apply_ops( $elements, array( array( 'op' => 'remove', 'element_id' => 'w000001' ) ) );
check( 'wpmcp_unknown_key' === code_of( $r ), 'remove mit element_id statt id: wpmcp_unknown_key, nicht "id fehlt"', text_of( $r ) );
check( false !== strpos( text_of( $r ), 'did you mean "id"' ), 'mit Vorschlag id', text_of( $r ) );

$r = wpmcp_elementor_apply_ops(
	$elements,
	array(
		array(
			'op'     => 'insert',
			'inside' => 'c000001',
			'element' => array(
				'id'         => 'abc1234',
				'elType'     => 'widget',
				'widgetType' => 'html',
				'settings'   => array( 'html' => '<p>x</p>' ),
				'elements'   => array(),
				'isInner'    => true,
			),
		),
	)
);
check( ! is_wp_error( $r ), 'ein Element, wie elementor-read es liefert (id, isInner), wird eingefuegt', text_of( $r ) );
check( ! is_wp_error( $r ) && 'abc1234' !== ( $r['elements'][0]['elements'][1]['id'] ?? 'abc1234' ), 'die id wird trotzdem neu erzeugt', text_of( $r ) );

$r = wpmcp_elementor_apply_ops(
	$elements,
	array(
		array(
			'op'      => 'insert',
			'inside'  => 'c000001',
			'element' => array(
				'elType'     => 'widget',
				'widgetType' => 'html',
				'setting'    => array( 'html' => '<p>x</p>' ),
			),
		),
	)
);
check( 'wpmcp_unknown_key' === code_of( $r ), 'Element mit "setting" statt settings: abgelehnt statt leeres Widget', text_of( $r ) );
check( false !== strpos( text_of( $r ), 'did you mean "settings"' ), 'mit Vorschlag settings', text_of( $r ) );
check( false !== strpos( text_of( $r ), 'elType, widgetType, settings, elements' ), 'mit den erlaubten Schluesseln', text_of( $r ) );

$r = wpmcp_elementor_apply_ops(
	$elements,
	array(
		array(
			'op'      => 'insert',
			'inside'  => 'c000001',
			'element' => array(
				'elType'   => 'container',
				'elements' => array(
					array(
						'elType'     => 'widget',
						'widgetType' => 'html',
						'settings'   => array( 'html' => '<p>x</p>' ),
						'children'   => array(),
					),
				),
			),
		),
	)
);
check( 'wpmcp_unknown_key' === code_of( $r ), 'auch in einem Kind-Element', text_of( $r ) );

echo "\n\033[1mVorschlaege\033[0m\n";

check( 'post_id' === wpmcp_did_you_mean( 'postId', array( 'post_id', 'title' ) ), 'postId -> post_id' );
check( 'dry_run' === wpmcp_did_you_mean( 'dryRun', array( 'post_id', 'dry_run' ) ), 'dryRun -> dry_run' );
check( 'expected_modified' === wpmcp_did_you_mean( 'expected_modifed', array( 'post_id', 'expected_modified' ) ), 'Tippfehler expected_modifed' );
check( '' === wpmcp_did_you_mean( 'colour', array( 'post_id', 'title' ) ), 'nichts Nahes: kein Vorschlag' );

echo "\n";
if ( $fail ) {
	echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
	exit( 1 );
}
echo "\033[32mAlle Pruefungen bestanden.\033[0m\n";
