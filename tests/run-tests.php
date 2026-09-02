<?php
/**
 * Test suite for the block tree, validation and catalogue layers.
 *
 * Run: php tests/run-tests.php
 *
 * @package wp-mcp-connector-plus
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['dbw_tests']  = 0;
$GLOBALS['dbw_failed'] = array();

function t_ok( $condition, $name, $detail = '' ) {
	++$GLOBALS['dbw_tests'];
	if ( $condition ) {
		echo "  \033[32m✓\033[0m {$name}\n";
		return true;
	}
	echo "  \033[31m✗\033[0m {$name}\n";
	if ( '' !== $detail ) {
		echo "      {$detail}\n";
	}
	$GLOBALS['dbw_failed'][] = $name;
	return false;
}

function t_group( $name ) {
	echo "\n\033[1m{$name}\033[0m\n";
}

function t_same( $expected, $actual, $name ) {
	return t_ok(
		$expected === $actual,
		$name,
		'expected: ' . var_export( $expected, true ) . "\n      actual:   " . var_export( $actual, true )
	);
}

// ---------------------------------------------------------------------
t_group( 'Roundtrip: real block markup survives read -> tree -> write' );

$markup_samples = array(
	'self-closing block'    => '<!-- wp:dbw-base/hero {"heading":"Willkommen"} /-->',
	'container with child'  => '<!-- wp:dbw-base/cards {"columns":2} --><!-- wp:dbw-base/card-item {"heading":"Eins"} /--><!-- wp:dbw-base/card-item {"heading":"Zwei"} /--><!-- /wp:dbw-base/cards -->',
	'core leaf'             => '<!-- wp:core/paragraph --><p>Hallo Welt</p><!-- /wp:core/paragraph -->',
	'nested three levels'   => '<!-- wp:dbw-base/section {"paddingSize":"l"} --><!-- wp:dbw-base/cards --><!-- wp:dbw-base/card-item {"heading":"A"} --><!-- wp:dbw-base/feature-list /--><!-- /wp:dbw-base/card-item --><!-- /wp:dbw-base/cards --><!-- /wp:dbw-base/section -->',
	'core group wrapper'    => '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>Drin</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
	// WordPress escapes quotes, angle brackets and ampersands in attributes
	// as unicode sequences — this is the form that actually sits in the DB.
	'attrs with quotes'     => '<!-- wp:dbw-base/hero {"heading":"Er sagte \u0022Hallo\u0022"} /-->',
	'attrs with markup'     => '<!-- wp:dbw-base/hero {"heading":"5 & mehr <br> Zeilen"} /-->',
	'attrs with url'        => '<!-- wp:dbw-base/cards --><!-- wp:dbw-base/card-item {"buttons":[{"text":"Zum Angebot","url":"https://example.de/leistungen?a=1&b=2"}]} /--><!-- /wp:dbw-base/cards -->',
	'mixed freeform html'   => '<!-- wp:dbw-base/hero /--><p>Klassischer Text</p><!-- wp:core/paragraph --><p>Block</p><!-- /wp:core/paragraph -->',
	'empty attrs'           => '<!-- wp:dbw-base/usp-list --><!-- wp:dbw-base/usp-item /--><!-- /wp:dbw-base/usp-list -->',
	'array attribute'       => '<!-- wp:dbw-base/cards --><!-- wp:dbw-base/card-item {"buttons":[{"text":"Mehr","url":"/x","style":"primary","newTab":false}]} /--><!-- /wp:dbw-base/cards -->',
	'umlauts and emoji'     => '<!-- wp:dbw-base/hero {"heading":"Grüße & Spaß 🎉"} /-->',
);

foreach ( $markup_samples as $label => $markup ) {
	$blocks = parse_blocks( $markup );
	$tree   = wpmcp_blocks_to_tree( $blocks, array(), true );

	$errors  = array();
	$rebuilt = wpmcp_tree_to_blocks( $tree, '', $errors );

	if ( ! empty( $errors ) ) {
		t_ok( false, "roundtrip: {$label}", 'tree_to_blocks errors: ' . implode( '; ', $errors ) );
		continue;
	}

	$reserialized = serialize_blocks( $rebuilt );

	// Compare semantically: re-parsing both must yield identical structures.
	$a = parse_blocks( $markup );
	$b = parse_blocks( $reserialized );

	t_ok(
		wp_json_encode( $a ) === wp_json_encode( $b ),
		"roundtrip: {$label}",
		"in:  {$markup}\n      out: {$reserialized}"
	);
}

// ---------------------------------------------------------------------
t_group( 'Default stripping' );

$blocks = parse_blocks( '<!-- wp:dbw-base/section {"paddingSize":"m","backgroundColor":"dark"} /-->' );
$tree   = wpmcp_blocks_to_tree( $blocks, array(), false );
$attrs  = (array) $tree[0]['attrs'];

t_ok( ! isset( $attrs['paddingSize'] ), 'default-valued attribute is omitted' );
t_ok( isset( $attrs['backgroundColor'] ) && 'dark' === $attrs['backgroundColor'], 'non-default attribute is kept' );

$tree_full = wpmcp_blocks_to_tree( $blocks, array(), true );
t_ok( isset( ( (array) $tree_full[0]['attrs'] )['paddingSize'] ), 'include_defaults returns the default too' );

// Stripping defaults must not change what gets written back.
$errors  = array();
$rebuilt = wpmcp_tree_to_blocks( $tree, '', $errors );
$out     = serialize_blocks( $rebuilt );
$reparse = parse_blocks( $out );
t_same( 'dark', $reparse[0]['attrs']['backgroundColor'], 'stripped tree still writes the explicit value' );

// ---------------------------------------------------------------------
t_group( 'Path addressing' );

$markup = '<!-- wp:dbw-base/section --><!-- wp:dbw-base/cards --><!-- wp:dbw-base/card-item {"heading":"A"} /--><!-- wp:dbw-base/card-item {"heading":"B"} /--><!-- /wp:dbw-base/cards --><!-- /wp:dbw-base/section -->';
$blocks = parse_blocks( $markup );

$node = wpmcp_blocks_at_path( $blocks, array( 0, 0, 1 ) );
t_ok( $node && 'B' === ( $node['attrs']['heading'] ?? '' ), 'path 0.0.1 resolves to the second card' );

t_ok( null === wpmcp_blocks_at_path( $blocks, array( 0, 0, 9 ) ), 'out-of-range path returns null' );
t_same( array( 2, 0, 1 ), wpmcp_path_parse( '2.0.1' ), 'path string parses to segments' );
t_same( null, wpmcp_path_parse( '2..1' ), 'malformed path is rejected' );
t_same( null, wpmcp_path_parse( 'a.b' ), 'non-numeric path is rejected' );

$tree = wpmcp_blocks_to_tree( $blocks );
t_same( '0.0.1', $tree[0]['innerBlocks'][0]['innerBlocks'][1]['path'], 'tree nodes carry their own path' );

// ---------------------------------------------------------------------
t_group( 'Patch operations' );

$base = parse_blocks( '<!-- wp:dbw-base/hero {"heading":"Alt"} /--><!-- wp:dbw-base/usp-list --><!-- wp:dbw-base/usp-item {"heading":"Eins"} /--><!-- /wp:dbw-base/usp-list -->' );

// set_attrs
$result = wpmcp_apply_ops(
	$base,
	array(
		array(
			'op'    => 'set_attrs',
			'path'  => '0',
			'attrs' => array( 'heading' => 'Neu' ),
		),
	)
);
t_ok( ! is_wp_error( $result ), 'set_attrs succeeds' );
t_same( 'Neu', $result['blocks'][0]['attrs']['heading'], 'set_attrs changes the value' );
t_same( 1, $result['summary']['patched'], 'set_attrs is counted' );

// set_attrs with null removes
$result = wpmcp_apply_ops(
	$base,
	array(
		array(
			'op'    => 'set_attrs',
			'path'  => '0',
			'attrs' => array( 'heading' => null ),
		),
	)
);
t_ok( ! isset( $result['blocks'][0]['attrs']['heading'] ), 'null value removes an attribute' );

// insert at top level
$result = wpmcp_apply_ops(
	$base,
	array(
		array(
			'op'    => 'insert',
			'path'  => '0',
			'block' => array(
				'name'  => 'dbw-base/section',
				'attrs' => array( 'paddingSize' => 'l' ),
			),
		),
	)
);
t_same( 'dbw-base/section', $result['blocks'][0]['blockName'], 'insert places the block at the given index' );
t_same( 'dbw-base/hero', $result['blocks'][1]['blockName'], 'insert shifts the existing block down' );

// insert nested
$result = wpmcp_apply_ops(
	$base,
	array(
		array(
			'op'    => 'insert',
			'path'  => '1.1',
			'block' => array(
				'name'  => 'dbw-base/usp-item',
				'attrs' => array( 'heading' => 'Zwei' ),
			),
		),
	)
);
t_ok( ! is_wp_error( $result ), 'nested insert succeeds' );
$usp = $result['blocks'][1];
t_same( 2, count( $usp['innerBlocks'] ), 'nested insert appends a second child' );
t_same( 2, count( array_filter( $usp['innerContent'], 'is_null' ) ), 'innerContent slots track the child count' );

$serialized = serialize_blocks( $result['blocks'] );
$reparsed   = parse_blocks( $serialized );
t_same( 2, count( $reparsed[1]['innerBlocks'] ), 'nested insert survives serialization' );
t_same( 'Zwei', $reparsed[1]['innerBlocks'][1]['attrs']['heading'], 'inserted child keeps its attributes' );

// replace
$result = wpmcp_apply_ops(
	$base,
	array(
		array(
			'op'    => 'replace',
			'path'  => '0',
			'block' => array(
				'name'  => 'dbw-base/hero',
				'attrs' => array( 'heading' => 'Ersetzt' ),
			),
		),
	)
);
t_same( 'Ersetzt', $result['blocks'][0]['attrs']['heading'], 'replace swaps the block' );
t_same( 2, count( $result['blocks'] ), 'replace does not change the block count' );

// remove
$result = wpmcp_apply_ops( $base, array( array( 'op' => 'remove', 'path' => '0' ) ) );
t_same( 1, count( $result['blocks'] ), 'remove deletes the block' );
t_same( 'dbw-base/usp-list', $result['blocks'][0]['blockName'], 'remove keeps the remaining block' );

// move
$result = wpmcp_apply_ops( $base, array( array( 'op' => 'move', 'path' => '0', 'to' => '1' ) ) );
t_ok( ! is_wp_error( $result ), 'move succeeds' );
t_same( 'dbw-base/hero', $result['blocks'][1]['blockName'], 'move relocates the block' );

// error cases
$result = wpmcp_apply_ops( $base, array( array( 'op' => 'nope', 'path' => '0' ) ) );
t_ok( is_wp_error( $result ), 'unknown op is rejected' );

$result = wpmcp_apply_ops( $base, array( array( 'op' => 'remove', 'path' => '99' ) ) );
t_ok( is_wp_error( $result ), 'out-of-range path is rejected' );

$result = wpmcp_apply_ops( $base, array( array( 'op' => 'remove', 'path' => 'bad' ) ) );
t_ok( is_wp_error( $result ), 'malformed path is rejected' );

// ---------------------------------------------------------------------
t_group( 'Validation: existence and schema' );

$blocks = parse_blocks( '<!-- wp:dbw-base/erfunden {"x":1} /-->' );
$v      = wpmcp_validate_blocks( $blocks );
t_ok( ! empty( $v['errors'] ), 'unregistered block is an error' );
t_ok( false !== strpos( implode( ' ', $v['errors'] ), 'not registered' ), 'error names the problem' );

$blocks = parse_blocks( '<!-- wp:dbw-base/hero {"erfundenesAttribut":"x"} /-->' );
$v      = wpmcp_validate_blocks( $blocks );
t_ok( ! empty( $v['errors'] ), 'unknown attribute on a dbw block is an error' );

$blocks = parse_blocks( '<!-- wp:dbw-base/hero {"headingLevel":"zwei"} /-->' );
$v      = wpmcp_validate_blocks( $blocks );
t_ok( ! empty( $v['errors'] ), 'wrong attribute type is an error' );

$blocks = parse_blocks( '<!-- wp:dbw-base/section {"paddingSize":"gigantisch"} /-->' );
$v      = wpmcp_validate_blocks( $blocks );
t_ok( ! empty( $v['errors'] ), 'enum violation is an error' );

$blocks = parse_blocks( '<!-- wp:dbw-base/section {"backgroundColor":"surface"} /-->' );
$v      = wpmcp_validate_blocks( $blocks );
t_ok( empty( $v['errors'] ), 'deprecated enum value is still valid' );
t_ok( ! empty( $v['warnings'] ), 'deprecated enum value warns' );

$blocks = parse_blocks( '<!-- wp:dbw-base/section {"paddingSize":"l","backgroundColor":"dark"} /-->' );
$v      = wpmcp_validate_blocks( $blocks );
t_ok( empty( $v['errors'] ), 'valid block passes clean' );

// Array item validation.
$blocks = parse_blocks( '<!-- wp:dbw-base/cards --><!-- wp:dbw-base/card-item {"buttons":[{"text":"Mehr","newTab":"ja"}]} /--><!-- /wp:dbw-base/cards -->' );
$v      = wpmcp_validate_blocks( $blocks );
t_ok( ! empty( $v['errors'] ), 'wrong type inside an array item is an error' );

// ---------------------------------------------------------------------
t_group( 'Validation: structure' );

$blocks = parse_blocks( '<!-- wp:dbw-base/card-item {"heading":"Frei"} /-->' );
$v      = wpmcp_validate_blocks( $blocks );
t_ok( ! empty( $v['errors'] ), 'child block at top level is an error' );
t_ok( false !== strpos( implode( ' ', $v['errors'] ), 'dbw-base/cards' ), 'error names the required parent' );

$blocks = parse_blocks( '<!-- wp:dbw-base/cards --><!-- wp:dbw-base/usp-item /--><!-- /wp:dbw-base/cards -->' );
$v      = wpmcp_validate_blocks( $blocks );
t_ok( ! empty( $v['errors'] ), 'wrong child in a closed container is an error' );

$blocks = parse_blocks( '<!-- wp:dbw-base/cards --><!-- wp:dbw-base/card-item /--><!-- /wp:dbw-base/cards -->' );
$v      = wpmcp_validate_blocks( $blocks );
t_ok( empty( $v['errors'] ), 'correct nesting passes' );

$blocks = parse_blocks( '<!-- wp:dbw-base/section --><!-- wp:dbw-base/cards --><!-- wp:dbw-base/card-item /--><!-- /wp:dbw-base/cards --><!-- /wp:dbw-base/section -->' );
$v      = wpmcp_validate_blocks( $blocks );
t_ok( empty( $v['errors'] ), 'nesting inside an open container passes' );

// ---------------------------------------------------------------------
t_group( 'Validation: design contract' );

$blocks = parse_blocks( '<!-- wp:dbw-base/card-item {"iconColor":"#ff0000"} /-->' );
// Wrap so parent validation does not add noise.
$wrapped = parse_blocks( '<!-- wp:dbw-base/cards --><!-- wp:dbw-base/card-item {"iconColor":"#ff0000"} /--><!-- /wp:dbw-base/cards -->' );
$v       = wpmcp_validate_blocks( $wrapped );
t_ok( ! empty( $v['errors'] ), 'hex colour in a dbw colour attribute is an error' );

$blocks = parse_blocks( '<!-- wp:core/paragraph {"style":{"color":{"text":"#ff0000"}}} --><p>x</p><!-- /wp:core/paragraph -->' );
$v      = wpmcp_validate_blocks( $blocks );
t_ok( ! empty( $v['errors'] ), 'literal colour in style is an error while theme.json locks colours' );

$blocks = parse_blocks( '<!-- wp:dbw-base/cards --><!-- wp:dbw-base/card-item {"iconColor":"primary"} /--><!-- /wp:dbw-base/cards -->' );
$v      = wpmcp_validate_blocks( $blocks );
t_ok( empty( $v['errors'] ), 'preset colour slug passes' );

// ---------------------------------------------------------------------
t_group( 'Validation: roundtrip guard' );

$tree = array(
	array(
		'name'        => 'core/group',
		'attrs'       => array(),
		'html'        => '<div class="wp-block-group"></div>',
		'innerBlocks' => array(
			array(
				'name'  => 'core/paragraph',
				'attrs' => array(),
				'html'  => '<p>x</p>',
			),
		),
	),
);
$errors = array();
wpmcp_tree_to_blocks( $tree, '', $errors );
t_ok( ! empty( $errors ), 'html plus innerBlocks without a template is rejected' );
t_ok( false !== strpos( implode( ' ', $errors ), 'htmlTemplate' ), 'rejection explains the fix' );

// With template it works.
$tree[0]['htmlTemplate'] = array( '<div class="wp-block-group">', null, '</div>' );
$errors                  = array();
$blocks                  = wpmcp_tree_to_blocks( $tree, '', $errors );
t_ok( empty( $errors ), 'html plus innerBlocks with a template is accepted' );
$out = serialize_blocks( $blocks );
t_ok( false !== strpos( $out, '<div class="wp-block-group">' ), 'template wrapper is preserved on write' );
t_ok( false !== strpos( $out, '<p>x</p>' ), 'child markup lands inside the wrapper' );

// ---------------------------------------------------------------------
t_group( 'Outline' );

$markup  = '<!-- wp:dbw-base/section --><!-- wp:dbw-base/cards {"heading":"Unsere Leistungen"} --><!-- wp:dbw-base/card-item {"heading":"Webdesign"} /--><!-- /wp:dbw-base/cards --><!-- /wp:dbw-base/section -->';
$outline = wpmcp_blocks_to_outline( parse_blocks( $markup ) );

t_same( 'dbw-base/section', $outline[0]['name'], 'outline lists the outer block' );
t_same( 'Unsere Leistungen', $outline[0]['children'][0]['label'], 'outline labels a block by its heading' );
t_same( '0.0.0', $outline[0]['children'][0]['children'][0]['path'], 'outline carries nested paths' );

$outline = wpmcp_blocks_to_outline( parse_blocks( '<!-- wp:core/paragraph --><p>Ein längerer Fließtext für das Label</p><!-- /wp:core/paragraph -->' ) );
t_ok( false !== strpos( $outline[0]['label'], 'Ein längerer' ), 'outline falls back to text content for a label' );

// ---------------------------------------------------------------------
t_group( 'Catalogue' );

$catalog = wpmcp_build_catalog( 'site' );
$by_name = array();
foreach ( $catalog as $entry ) {
	$by_name[ $entry['name'] ] = $entry;
}

t_ok( isset( $by_name['dbw-base/hero'] ), 'catalogue contains the hero block' );
t_ok( ! isset( $by_name['core/paragraph'] ), 'scope "site" excludes core blocks' );
t_same( 'child', $by_name['dbw-base/card-item']['role'], 'card-item is classified as a child block' );
t_same( 'container', $by_name['dbw-base/cards']['role'], 'cards is classified as a container' );
t_same( 'container', $by_name['dbw-base/section']['role'], 'open container is classified as a container' );
t_same( 'any', $by_name['dbw-base/section']['accepts'], 'open container accepts anything' );
t_same( array( 'dbw-base/card-item' ), $by_name['dbw-base/cards']['accepts'], 'closed container lists its children' );
t_same( 'standalone', $by_name['dbw-base/hero']['role'], 'hero is standalone' );

// The inverse parent map must find children even without allowedBlocks.
t_same( array( 'dbw-base/usp-item' ), $by_name['dbw-base/usp-list']['accepts'], 'children are found via the inverse parent map' );

$all = wpmcp_build_catalog( 'all' );
t_ok( count( $all ) > count( $catalog ), 'scope "all" includes core blocks' );

// ---------------------------------------------------------------------
t_group( 'Block detail' );

$described = wpmcp_describe_blocks( array( 'dbw-base/hero', 'dbw-base/gibtsnicht' ) );
t_same( 'dbw-base/hero', $described[0]['name'], 'describe returns the requested block' );
t_ok( isset( $described[1]['error'] ), 'describe reports unknown blocks instead of failing' );

$groups = $described[0]['attributes'];
t_ok( isset( $groups['legacy']['primaryButtonText'] ), 'legacy attributes land in the legacy group' );
t_ok( isset( $groups['behavior']['showScrollHint'] ), 'show* attributes land in the behavior group' );
t_ok( isset( $groups['content']['heading'] ), 'content attributes land in the content group' );
t_ok( isset( $described[0]['example']['name'] ), 'describe includes an example' );

$section = wpmcp_describe_blocks( array( 'dbw-base/section' ) )[0];
$bg      = $section['attributes']['layout']['backgroundColor'];
t_ok( ! in_array( 'surface', $bg['enum'], true ), 'deprecated values are removed from the offered enum' );
t_ok( in_array( 'surface', $bg['deprecatedValues'], true ), 'deprecated values are listed separately' );

$card = wpmcp_describe_blocks( array( 'dbw-base/card-item' ) )[0];
t_same( array( 'dbw-base/cards' ), $card['mustBeInside'], 'describe states the required parent' );

// ---------------------------------------------------------------------
t_group( 'Design tokens' );

$tokens = wpmcp_design_tokens();
t_same( 2, count( $tokens['colors'] ), 'palette is read from theme.json' );
t_same( 'primary', $tokens['colors'][0]['slug'], 'colour slugs are exposed' );
t_ok( false === $tokens['customColors'], 'lockdown state is reported' );
t_same( array( 's', 'm', 'l' ), $tokens['spacingSizes'], 'spacing presets are exposed' );

// ---------------------------------------------------------------------
t_group( 'Counting and names' );

$blocks = parse_blocks( '<!-- wp:dbw-base/section --><!-- wp:dbw-base/cards --><!-- wp:dbw-base/card-item /--><!-- wp:dbw-base/card-item /--><!-- /wp:dbw-base/cards --><!-- /wp:dbw-base/section -->' );
t_same( 4, wpmcp_count_blocks( $blocks ), 'nested blocks are counted' );
t_same(
	array( 'dbw-base/section', 'dbw-base/cards', 'dbw-base/card-item', 'dbw-base/card-item' ),
	wpmcp_block_names( $blocks ),
	'block names are collected depth-first'
);

// Whitespace between blocks must not be counted.
$blocks = parse_blocks( "<!-- wp:dbw-base/hero /-->\n\n<!-- wp:dbw-base/usp-list /-->" );
t_same( 2, wpmcp_count_blocks( $blocks ), 'whitespace null-blocks are not counted' );

$tree = wpmcp_blocks_to_tree( $blocks );
t_same( 2, count( $tree ), 'whitespace null-blocks do not appear in the tree' );
t_same( '1', $tree[1]['path'], 'paths ignore whitespace null-blocks' );

// And addressing must still hit the right block.
$result = wpmcp_apply_ops( $blocks, array( array( 'op' => 'remove', 'path' => '1' ) ) );
$names  = wpmcp_block_names( $result['blocks'] );
t_same( array( 'dbw-base/hero' ), $names, 'removing by visible index removes the right block' );

// ---------------------------------------------------------------------
t_group( 'Attribute, die als Objekt gespeichert werden müssen' );

$registry->register(
	'acme/styled',
	array(
		'title'      => 'Styled',
		'attributes' => array(
			'uniqueId' => array(
				'type'    => 'string',
				'default' => '',
			),
			'styles'   => array( 'type' => 'object' ),
			'items'    => array( 'type' => 'array' ),
		),
	)
);

// JSON decoding turns {} into an empty PHP array, which encodes back as [].
$tree   = array(
	array(
		'name'  => 'acme/styled',
		'attrs' => json_decode( '{"uniqueId":"243c9e83","styles":{},"items":[]}', true ),
	),
);
$errors = array();
$out    = serialize_blocks( wpmcp_tree_to_blocks( $tree, '', $errors ) );

t_ok( false !== strpos( $out, '"styles":{}' ), 'leeres Objekt bleibt ein Objekt', $out );
t_ok( false !== strpos( $out, '"items":[]' ), 'leeres Array bleibt ein Array', $out );

// A populated object was never ambiguous, but must not regress.
$tree[0]['attrs'] = json_decode( '{"styles":{"textAlign":"center"}}', true );
$errors           = array();
$out              = serialize_blocks( wpmcp_tree_to_blocks( $tree, '', $errors ) );
t_ok( false !== strpos( $out, '"styles":{"textAlign":"center"}' ), 'gefülltes Objekt bleibt unverändert', $out );

// ---------------------------------------------------------------------
t_group( 'Fehlende Instanz-ID' );

$blocks = parse_blocks( '<!-- wp:acme/styled {"uniqueId":"243c9e83"} /-->' );
$v      = wpmcp_validate_blocks( $blocks );
t_ok( empty( $v['warnings'] ), 'gesetzte uniqueId erzeugt keine Warnung' );

$blocks = parse_blocks( '<!-- wp:acme/styled {} /-->' );
$v      = wpmcp_validate_blocks( $blocks );
t_ok( ! empty( $v['warnings'] ), 'fehlende uniqueId wird gemeldet' );
t_ok(
	false !== strpos( implode( ' ', $v['warnings'] ), 'marking it as changed' ),
	'die Warnung nennt die Folge fürs Backend'
);
t_ok( empty( $v['errors'] ), 'fehlende uniqueId ist eine Warnung, kein Fehler' );

// ---------------------------------------------------------------------
t_group( 'Geerbte Fehler blockieren nicht' );

// The reported case: a page saved through the block editor carries five
// literal colours the validator rejects. Inserting a clean block must not
// be refused because of them.
$registry->register(
	'acme/card',
	array(
		'title'      => 'Card',
		'attributes' => array(
			'highlightColor' => array(
				'type'    => 'string',
				'default' => '',
			),
		),
	)
);

$existing = '<!-- wp:acme/card {"highlightColor":"#2E7D9B"} /-->'
	. '<!-- wp:acme/card {"highlightColor":"#7DBB42"} /-->'
	. '<!-- wp:acme/card {"highlightColor":"#E8A838"} /-->';

$before = parse_blocks( $existing );

// Without the before-state, every pre-existing violation is an error.
$v = wpmcp_validate_blocks( $before );
t_same( 3, count( $v['errors'] ), 'ohne Vorzustand sind es drei Fehler' );

// With it, they are inherited and stop being a veto.
$v = wpmcp_validate_blocks( $before, $before );
t_ok( empty( $v['errors'] ), 'mit Vorzustand blockiert nichts mehr', implode( ' | ', $v['errors'] ) );
t_same( 3, count( $v['warnings'] ), 'sie werden weiterhin gemeldet' );
t_ok(
	false !== strpos( implode( ' ', $v['warnings'] ), 'already present before this change' ),
	'die Meldung sagt, dass es sie vorher schon gab'
);

// Inserting a clean block into that page must go through.
$after = parse_blocks( $existing . '<!-- wp:dbw-base/hero {"heading":"Neu"} /-->' );
$v     = wpmcp_validate_blocks( $after, $before );
t_ok( empty( $v['errors'] ), 'sauberer Einschub wird nicht abgelehnt', implode( ' | ', $v['errors'] ) );

// But a NEW violation of the same kind is still caught — counted, not matched.
$after = parse_blocks( $existing . '<!-- wp:acme/card {"highlightColor":"#123456"} /-->' );
$v     = wpmcp_validate_blocks( $after, $before );
t_same( 1, count( $v['errors'] ), 'eine vierte Verletzung derselben Art wird erkannt' );
t_ok(
	false !== strpos( $v['errors'][0], '#123456' ),
	'und zwar die neue, nicht eine der alten',
	$v['errors'][0] ?? ''
);

// Removing an offending block is an improvement, never an error.
$after = parse_blocks( '<!-- wp:acme/card {"highlightColor":"#2E7D9B"} /-->' );
$v     = wpmcp_validate_blocks( $after, $before );
t_ok( empty( $v['errors'] ), 'weniger Verstoesse als vorher ist kein Fehler' );

// ---------------------------------------------------------------------
t_group( 'Leere Container' );

$blocks = parse_blocks( '<!-- wp:dbw-base/cards /-->' );
$v      = wpmcp_validate_blocks( $blocks );
t_ok( empty( $v['errors'] ), 'leerer Container ist kein Fehler' );
t_ok( ! empty( $v['warnings'] ), 'wird aber gemeldet' );
t_ok(
	false !== strpos( implode( ' ', $v['warnings'] ), 'renders as an empty section' ),
	'die Meldung nennt die Folge'
);

$blocks = parse_blocks( '<!-- wp:dbw-base/cards --><!-- wp:dbw-base/card-item /--><!-- /wp:dbw-base/cards -->' );
$v      = wpmcp_validate_blocks( $blocks );
t_ok( empty( $v['warnings'] ), 'gefuellter Container meldet nichts' );

// Open containers legitimately hold nothing yet.
$blocks = parse_blocks( '<!-- wp:dbw-base/section /-->' );
$v      = wpmcp_validate_blocks( $blocks );
t_ok( empty( $v['warnings'] ), 'offener Container wird nicht bemaengelt' );

// ---------------------------------------------------------------------
echo "\n\033[1mVerwaistes JSON-LD\033[0m\n";

// Structured data with no script around it renders as a wall of text on
// the page. It is what a stripped script leaves behind, and it was only
// ever noticed by someone looking at the page.
t_ok(
	wpmcp_has_orphaned_structured_data( '{"@context": "https://schema.org", "@type": "Service"}' ),
	'nacktes JSON-LD wird erkannt'
);
t_ok(
	wpmcp_has_orphaned_structured_data( '{ &quot;@context&quot;: &quot;https://schema.org&quot; }' ),
	'auch als HTML-Entities'
);
t_ok(
	wpmcp_has_orphaned_structured_data( '{ “@graph”: [] }' ),
	'auch mit typografischen Anfuehrungszeichen'
);
t_ok(
	! wpmcp_has_orphaned_structured_data( '<script type="application/ld+json">{"@context":"https://schema.org"}</script>' ),
	'im Script-Tag ist es in Ordnung'
);
t_ok(
	! wpmcp_has_orphaned_structured_data( '<p>Wir arbeiten nach schema.org.</p>' ),
	'ein blosser Textverweis ist kein Befund'
);
t_ok(
	! wpmcp_has_orphaned_structured_data( '<p>Preis auf Anfrage.</p>' ),
	'gewoehnlicher Text loest nichts aus'
);

$blocks = parse_blocks( '<!-- wp:core/html -->{"@context": "https://schema.org"}<!-- /wp:core/html -->' );
$v      = wpmcp_validate_blocks( $blocks );
t_ok(
	(bool) preg_grep( '/JSON-LD/', $v['warnings'] ),
	'die Pruefung meldet es als Warnung am Block'
);

// ---------------------------------------------------------------------
echo "\n";
$total  = $GLOBALS['dbw_tests'];
$failed = count( $GLOBALS['dbw_failed'] );
$passed = $total - $failed;

if ( 0 === $failed ) {
	echo "\033[32m{$passed}/{$total} Tests bestanden.\033[0m\n";
	exit( 0 );
}

echo "\033[31m{$failed} von {$total} Tests fehlgeschlagen:\033[0m\n";
foreach ( $GLOBALS['dbw_failed'] as $name ) {
	echo "  - {$name}\n";
}
exit( 1 );
