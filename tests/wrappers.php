<?php
/**
 * Containers sent without their wrapper markup.
 *
 * Until 0.19.1 a node with innerBlocks and neither "html" nor
 * "htmlTemplate" was saved as its children alone. For a dbw-base block,
 * which renders on the server and saves InnerBlocks.Content, that is
 * right. For a GenerateBlocks element, a core/group or core/columns it
 * threw away the wrapper and every class on it, and the dry run said ok:
 * on dbw-media.de a new section with three cards went live as a loose
 * heading, a paragraph and three more headings.
 *
 * The expected markup below is not written by hand. It is what the block
 * editor itself saved for the same attributes: WordPress 6.9.8's
 * wp-includes/js/dist (blocks, block-editor, block-library) and
 * GenerateBlocks 2.4.1's dist/blocks/element/index.js, loaded into jsdom,
 * createBlock() and serialize(). Generated wrappers must match it to the
 * byte, or the editor would call the block invalid.
 *
 * Run: php tests/wrappers.php
 *
 * @package wp-mcp-connector-plus
 */

require_once __DIR__ . '/bootstrap.php';

define( 'GENERATEBLOCKS_VERSION', '2.4.1' );

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

// As GenerateBlocks 2.4.1 registers it: a render callback that only adds
// CSS, no generated class, a custom class name.
$registry->register(
	'generateblocks/element',
	array(
		'attributes'      => array(
			'uniqueId'       => array( 'type' => 'string', 'default' => '' ),
			'tagName'        => array( 'type' => 'string', 'default' => '' ),
			'styles'         => array( 'type' => 'object', 'default' => array() ),
			'css'            => array( 'type' => 'string', 'default' => '' ),
			'globalClasses'  => array( 'type' => 'array', 'default' => array() ),
			'htmlAttributes' => array( 'type' => 'object', 'default' => array() ),
			'align'          => array( 'type' => 'string', 'default' => '' ),
			'className'      => array( 'type' => 'string' ),
		),
		'supports'        => array( 'className' => false ),
		'render_callback' => '__return_true',
	)
);

// Static, like core/columns and core/buttons in WordPress 6.9.8.
$registry->register( 'core/columns', array( 'attributes' => array( 'className' => array( 'type' => 'string' ) ) ) );
$registry->register( 'core/column', array( 'attributes' => array( 'className' => array( 'type' => 'string' ) ) ) );

$para = array(
	'name' => 'core/paragraph',
	'html' => "\n<p>A</p>\n",
);

/**
 * Build a tree as content-write does and serialize it.
 *
 * @return array { markup, errors, context }
 */
function build( array $tree, array $stored = array() ) {
	$errors  = array();
	$context = wpmcp_wrapper_context( $stored );
	$blocks  = wpmcp_tree_to_blocks( $tree, '', $errors, $context );
	return array(
		'markup'  => serialize_blocks( $blocks ),
		'errors'  => $errors,
		'context' => $context,
	);
}

echo "\n\033[1mWas der Block-Editor speichert, wird Byte fuer Byte erzeugt\033[0m\n";

// Openings the editor wrote, per attribute set.
$editor = array(
	array( 'core/group', array(), '<div class="wp-block-group">' ),
	array( 'core/group', array( 'tagName' => 'section', 'className' => 'foo bar' ), '<section class="wp-block-group foo bar">' ),
	array( 'core/group', array( 'layout' => array( 'type' => 'constrained' ) ), '<div class="wp-block-group">' ),
	array( 'core/group', array( 'layout' => array( 'type' => 'flex', 'flexWrap' => 'nowrap', 'justifyContent' => 'center' ) ), '<div class="wp-block-group">' ),
	array( 'core/group', array( 'layout' => array( 'type' => 'grid', 'columnCount' => 3 ) ), '<div class="wp-block-group">' ),
	array( 'core/group', array( 'align' => 'wide', 'anchor' => 'x', 'className' => 'foo' ), '<div class="wp-block-group alignwide foo" id="x">' ),
	array(
		'core/group',
		array(
			'tagName'       => 'main',
			'align'         => 'full',
			'className'     => 'a&b x',
			'anchor'        => 'top',
			'layout'        => array( 'type' => 'constrained' ),
			'metadata'      => array( 'name' => 'Hero' ),
			'templateLock'  => 'all',
			'allowedBlocks' => array( 'core/paragraph' ),
			'lock'          => array( 'move' => true ),
		),
		'<main class="wp-block-group alignfull a&amp;b x" id="top">',
	),
	array( 'generateblocks/element', array( 'tagName' => 'section', 'globalClasses' => array( 'section--fullwidth' ), 'className' => 'problems' ), '<section class="section--fullwidth problems">' ),
	array(
		'generateblocks/element',
		array(
			'tagName'        => 'div',
			'uniqueId'       => 'abc123',
			'styles'         => array( 'display' => 'grid' ),
			'htmlAttributes' => array(
				'id'       => 'x',
				'data-foo' => 'a&"b<',
				'style'    => 'color:red',
			),
			'className'      => 'c',
		),
		'<div class="gb-element-abc123 c" id="x" data-foo="a&amp;&quot;b<" style="color:red">',
	),
	array( 'generateblocks/element', array( 'tagName' => 'a', 'htmlAttributes' => array( 'href' => 'https://x.test/?a=1&b=2' ) ), '<a href="https://x.test/?a=1&amp;b=2">' ),
	array(
		'generateblocks/element',
		array(
			'tagName'        => 'div',
			'globalClasses'  => array( '', '', ' g1' ),
			'className'      => '',
			'htmlAttributes' => array(
				'data-x'   => '&amp; &foo; &#38; &x a>b q',
				'tabindex' => 0,
				'title'    => '',
			),
		),
		'<div class="g1" data-x="&amp; &foo; &#38; &amp;x a&gt;b q" tabindex="0" title="">',
	),
	array( 'generateblocks/element', array( 'tagName' => 'div', 'globalClasses' => array( 'a' ), 'className' => ' b ', 'align' => 'wide', 'css' => '.x{}', 'metadata' => array( 'name' => 'Head' ) ), '<div class="a  b ">' ),
	array( 'generateblocks/element', array( 'tagName' => 'div', 'className' => ' ' ), '<div class=" ">' ),
	array( 'generateblocks/element', array( 'tagName' => 'div', 'htmlAttributes' => array( 'data-a' => "x'y<z" ) ), "<div data-a=\"x'y<z\">" ),
);

foreach ( $editor as list( $name, $attrs, $open ) ) {
	$generated = wpmcp_generate_wrapper( $name, $attrs );
	check(
		$open === ( $generated['open'] ?? null ),
		sprintf( '%s %s', $name, wp_json_encode( $attrs ) ),
		'erwartet: ' . $open . "\n      erzeugt:  " . ( $generated['open'] ?? wp_json_encode( $generated ) )
	);
}

// The whole block, delimiters and line breaks included, as the editor
// serialized it with two paragraphs inside.
$out = build(
	array(
		array(
			'name'        => 'core/group',
			'attrs'       => array( 'tagName' => 'section', 'className' => 'foo bar' ),
			'innerBlocks' => array( $para, $para ),
		),
	)
);
check(
	"<!-- wp:group {\"tagName\":\"section\",\"className\":\"foo bar\"} -->\n<section class=\"wp-block-group foo bar\"><!-- wp:paragraph -->\n<p>A</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>A</p>\n<!-- /wp:paragraph --></section>\n<!-- /wp:group -->" === $out['markup'],
	'core/group ohne htmlTemplate wird gespeichert wie im Editor',
	$out['markup']
);

$out = build(
	array(
		array(
			'name'        => 'generateblocks/element',
			'attrs'       => array( 'tagName' => 'section', 'globalClasses' => array( 'section--fullwidth' ), 'className' => 'problems' ),
			'innerBlocks' => array( $para, $para ),
		),
	)
);
check(
	"<!-- wp:generateblocks/element {\"tagName\":\"section\",\"globalClasses\":[\"section\\u002d\\u002dfullwidth\"],\"className\":\"problems\"} -->\n<section class=\"section--fullwidth problems\"><!-- wp:paragraph -->\n<p>A</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>A</p>\n<!-- /wp:paragraph --></section>\n<!-- /wp:generateblocks/element -->" === $out['markup'],
	'GenerateBlocks-Element ohne htmlTemplate ebenso',
	$out['markup']
);
check( empty( $out['errors'] ), 'ohne Fehler', implode( ' | ', $out['errors'] ) );
check( 1 === count( $out['context']->generated ), 'und als erzeugt gemeldet' );
check( '0' === ( $out['context']->generated[0]['path'] ?? null ), 'mit Pfad' );

echo "\n\033[1mDer Fall aus dem Feldbericht: Sektion, Kopf, Raster, drei Karten\033[0m\n";

$card  = function ( $title ) {
	return array(
		'name'        => 'generateblocks/element',
		'attrs'       => array( 'tagName' => 'div', 'className' => 'problem-card' ),
		'innerBlocks' => array(
			array( 'name' => 'core/heading', 'html' => '<h3>' . $title . '</h3>' ),
			array( 'name' => 'core/paragraph', 'html' => '<p>Text</p>' ),
		),
	);
};
$field = array(
	array(
		'name'        => 'generateblocks/element',
		'attrs'       => array( 'tagName' => 'section', 'globalClasses' => array( 'section--fullwidth' ), 'className' => 'problems' ),
		'innerBlocks' => array(
			array(
				'name'        => 'generateblocks/element',
				'attrs'       => array( 'tagName' => 'div', 'className' => 'problems__head' ),
				'innerBlocks' => array(
					array( 'name' => 'core/heading', 'html' => '<h2>Probleme</h2>' ),
					array( 'name' => 'core/paragraph', 'html' => '<p>Kennst du das?</p>' ),
				),
			),
			array(
				'name'        => 'generateblocks/element',
				'attrs'       => array( 'tagName' => 'div', 'className' => 'problems__grid' ),
				'innerBlocks' => array( $card( 'Eins' ), $card( 'Zwei' ), $card( 'Drei' ) ),
			),
		),
	),
);

$out = build( $field );
check( empty( $out['errors'] ), 'geht durch', implode( ' | ', $out['errors'] ) );
check( 1 === substr_count( $out['markup'], '<section class="section--fullwidth problems">' ), 'die Sektion ist da', $out['markup'] );
check( 1 === substr_count( $out['markup'], '<div class="problems__head">' ), 'der Kopf' );
check( 1 === substr_count( $out['markup'], '<div class="problems__grid">' ), 'das Raster' );
check( 3 === substr_count( $out['markup'], '<div class="problem-card">' ), 'alle drei Karten' );
check( 6 === count( $out['context']->generated ), 'sechs Huellen erzeugt und gemeldet' );
$reparsed = parse_blocks( $out['markup'] );
check( 6 === count( array_filter( $out['context']->generated, function ( $g ) { return 'generateblocks/element' === $g['block']; } ) ), 'alle als generateblocks/element' );
check( 'problem-card' === ( $reparsed[0]['innerBlocks'][1]['innerBlocks'][2]['attrs']['className'] ?? null ), 'und der Baum liest sich zurueck wie gesendet' );

echo "\n\033[1mWas nicht exakt erzeugt werden kann, wird abgelehnt\033[0m\n";

$refusals = array(
	'core/group mit Hintergrundfarbe'      => array( 'core/group', array( 'backgroundColor' => 'accent' ), 'backgroundColor' ),
	'core/group mit style'                 => array( 'core/group', array( 'style' => array( 'spacing' => array( 'padding' => '1rem' ) ) ), 'style' ),
	'core/group mit ariaLabel'             => array( 'core/group', array( 'ariaLabel' => 'x' ), 'ariaLabel' ),
	'core/group mit fremdem tagName'       => array( 'core/group', array( 'tagName' => 'span' ), 'tagName' ),
	'GB ohne tagName'                      => array( 'generateblocks/element', array( 'className' => 'x' ), 'tagName is missing' ),
	'GB mit styles ohne uniqueId'          => array( 'generateblocks/element', array( 'tagName' => 'div', 'styles' => array( 'color' => 'red' ) ), 'uniqueId' ),
	'GB mit Boolean-Attribut'              => array( 'generateblocks/element', array( 'tagName' => 'div', 'htmlAttributes' => array( 'hidden' => 'hidden' ) ), '"hidden"' ),
	'GB mit Attribut, das kein Text ist'   => array( 'generateblocks/element', array( 'tagName' => 'div', 'htmlAttributes' => array( 'data-x' => true ) ), '"data-x"' ),
);

foreach ( $refusals as $label => list( $name, $attrs, $reason ) ) {
	$out = build(
		array(
			array(
				'name'        => $name,
				'attrs'       => $attrs,
				'innerBlocks' => array( $para, $para ),
			),
		)
	);
	$message = implode( ' | ', $out['errors'] );
	check( 1 === count( $out['errors'] ), $label, $message . ' ' . $out['markup'] );
	check( false !== strpos( $message, $reason ), '  nennt den Grund', $message );
	check( false !== strpos( $message, '"htmlTemplate": ["\n<' ), '  schlaegt ein htmlTemplate vor', $message );
	check( empty( $out['context']->generated ) && 1 === count( $out['context']->missing ), '  und erzeugt nichts' );
}

$out = build(
	array(
		array(
			'name'        => 'generateblocks/element',
			'attrs'       => array( 'tagName' => 'div', 'styles' => array( 'color' => 'red' ), 'className' => 'card' ),
			'innerBlocks' => array( $para, $para ),
		),
	)
);
check(
	false !== strpos( $out['errors'][0] ?? '', '"htmlTemplate": ["\n<div class=\"card\">",null,"\n\n",null,"</div>\n"]' ),
	'der Vorschlag ohne Vorbild: Tag und Klasse, ohne wp-block-Klasse, wo der Block sie abschaltet',
	$out['errors'][0] ?? ''
);

echo "\n\033[1mStatische Bloecke ohne Erzeuger\033[0m\n";

$columns = array(
	array(
		'name'        => 'core/columns',
		'attrs'       => array( 'className' => 'neu' ),
		'innerBlocks' => array(
			array(
				'name'        => 'core/column',
				'innerBlocks' => array( $para ),
			),
		),
	),
);

$out = build( $columns );
check( 2 === count( $out['errors'] ), 'core/columns und core/column ohne Vorbild werden abgelehnt', implode( ' | ', $out['errors'] ) );
check( false !== strpos( $out['errors'][0] ?? '', 'no render callback' ), 'weil WordPress sie statisch speichert', $out['errors'][0] ?? '' );
check( 0 === strpos( $out['errors'][0] ?? '', '0.0: "core/column"' ) && 0 === strpos( $out['errors'][1] ?? '', '0: "core/columns"' ), 'jeder mit seinem Pfad', implode( ' | ', $out['errors'] ) );
check( false !== strpos( implode( ' ', $out['errors'] ), '["\n<div class=\"wp-block-columns neu\">",null,"</div>\n"]' ), 'mit einem Vorschlag aus Tag, wp-block-Klasse und className', implode( ' | ', $out['errors'] ) );

// An instance on the page decides, and the proposal copies it.
$stored = parse_blocks( '<!-- wp:columns {"className":"alt"} --><div class="wp-block-columns are-vertically-aligned-center alt"><!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>x</p><!-- /wp:paragraph --></div><!-- /wp:column --></div><!-- /wp:columns -->' );
$out    = build( $columns, $stored );
// Children are built first, so their messages come first.
$message = implode( ' | ', $out['errors'] );
check( false !== strpos( $message, '0: "core/columns" has innerBlocks' ) && false !== strpos( $message, 'the one in this page at 0 has markup around its children' ), 'ein Vorbild auf der Seite wird genannt', $message );
check(
	false !== strpos( $message, '["\n<div class=\"wp-block-columns are-vertically-aligned-center neu\">",null,"</div>\n"]' ),
	'und sein Wrapper vorgeschlagen, mit der Klasse des Knotens statt seiner eigenen',
	$message
);

echo "\n\033[1mBloecke, die nur ihre Kinder speichern, bleiben, wie sie sind\033[0m\n";

$cards = array(
	array(
		'name'        => 'dbw-base/cards',
		'attrs'       => array( 'columns' => 2 ),
		'innerBlocks' => array(
			array(
				'name'  => 'dbw-base/card-item',
				'attrs' => array( 'heading' => 'Eins' ),
			),
		),
	),
);

$out = build( $cards );
check( empty( $out['errors'] ), 'ein dynamischer Container ohne Vorbild geht durch', implode( ' | ', $out['errors'] ) );
check( '<!-- wp:dbw-base/cards {"columns":2} --><!-- wp:dbw-base/card-item {"heading":"Eins"} /--><!-- /wp:dbw-base/cards -->' === $out['markup'], 'als seine Kinder', $out['markup'] );
check( 1 === count( $out['context']->notes ) && false !== strpos( $out['context']->notes[0], 'renders on the server' ), 'aber nicht still: eine Warnung sagt, was angenommen wurde', implode( ' | ', $out['context']->notes ) );

$out = build( $cards, parse_blocks( '<!-- wp:dbw-base/cards --><!-- wp:dbw-base/card-item {"heading":"Alt"} /--><!-- /wp:dbw-base/cards -->' ) );
check( empty( $out['errors'] ) && empty( $out['context']->notes ), 'mit einem Vorbild auf der Seite ganz ohne Warnung', implode( ' | ', $out['context']->notes ) );

// A page that lost its group wrapper to 0.19.1 is not refused for it:
// the block is kept, not written.
$damaged = '<!-- wp:group {"className":"alt"} --><!-- wp:paragraph --><p>x</p><!-- /wp:paragraph --><!-- /wp:group -->';
$tree    = wpmcp_blocks_to_tree( parse_blocks( $damaged ), array(), true );
$out     = build( $tree, parse_blocks( $damaged ) );
check( empty( $out['errors'] ) && empty( $out['context']->generated ), 'ein unveraenderter Block bleibt unangetastet', implode( ' | ', $out['errors'] ) );
check( $damaged === $out['markup'], 'Byte fuer Byte', $out['markup'] );

// Children only on a block that has a template already is the old path.
$out = build(
	array(
		array(
			'name'         => 'core/group',
			'htmlTemplate' => array( '<div class="wp-block-group eigen">', null, '</div>' ),
			'innerBlocks'  => array( $para ),
		),
	)
);
check( false !== strpos( $out['markup'], '<div class="wp-block-group eigen">' ) && empty( $out['context']->generated ), 'ein mitgeschicktes htmlTemplate gilt, nichts wird erzeugt' );

echo "\n\033[1mPatch-Operationen pruefen dasselbe\033[0m\n";

$result = wpmcp_apply_ops(
	parse_blocks( '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' ),
	array(
		array(
			'op'    => 'insert',
			'path'  => '1',
			'block' => $columns[0],
		),
	)
);
check( is_wp_error( $result ) && 'wpmcp_wrapper_missing' === $result->get_error_code(), 'ein insert ohne Wrapper gibt wpmcp_wrapper_missing', is_wp_error( $result ) ? $result->get_error_code() : 'kein Fehler' );

$context = wpmcp_wrapper_context();
$result  = wpmcp_apply_ops(
	parse_blocks( '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' ),
	array(
		array(
			'op'    => 'insert',
			'path'  => '1',
			'block' => $field[0],
		),
	),
	$context
);
check( ! is_wp_error( $result ) && 6 === count( $context->generated ), 'ein insert der Sektion aus dem Bericht erzeugt die Huellen' );
$paths = array_column( $context->generated, 'path' );
check( in_array( 'op0.0', $paths, true ) && in_array( 'op0.0.1.2', $paths, true ), 'Pfade wie in den Fehlermeldungen der Operation', implode( ', ', $paths ) );

echo "\n";
if ( 0 === $fail ) {
	echo "\033[32mWrapper in Ordnung.\033[0m\n";
	exit( 0 );
}
echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
exit( 1 );
