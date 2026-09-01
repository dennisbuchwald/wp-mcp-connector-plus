<?php
/**
 * Integration test against the real dbw-base-core block.json files.
 *
 * Loads all 47 blocks into the registry stub and exercises the catalogue,
 * the detail view and the validator against them — the synthetic blocks in
 * the unit tests cannot catch schema surprises in the actual kit.
 *
 * Usage: php tests/run-integration.php [path/to/dbw-base-core]
 *
 * @package wp-mcp-connector-plus
 */

require_once __DIR__ . '/bootstrap.php';

$core_path = $argv[1] ?? getenv( 'DBW_CORE_PATH' );
if ( ! $core_path ) {
	$core_path = dirname( __DIR__, 3 ) . '/01_Webprojekte/dbw-base-core';
}
$blocks_dir = rtrim( $core_path, '/' ) . '/blocks/src';

if ( ! is_dir( $blocks_dir ) ) {
	echo "dbw-base-core nicht gefunden: {$blocks_dir}\n";
	echo "Aufruf: php tests/run-integration.php /pfad/zu/dbw-base-core\n";
	exit( 2 );
}

echo "\033[1mIntegration gegen echte block.json\033[0m\n";
echo "  Quelle: {$blocks_dir}\n\n";

$registry = WP_Block_Type_Registry::get_instance();
$loaded   = 0;
$problems = array();

foreach ( scandir( $blocks_dir ) as $entry ) {
	$file = $blocks_dir . '/' . $entry . '/block.json';
	if ( '.' === $entry[0] || ! is_file( $file ) ) {
		continue;
	}

	$json = json_decode( file_get_contents( $file ), true );
	if ( ! is_array( $json ) || empty( $json['name'] ) ) {
		$problems[] = "{$entry}: block.json unlesbar";
		continue;
	}

	$registry->register(
		$json['name'],
		array(
			'title'          => $json['title'] ?? '',
			'description'    => $json['description'] ?? '',
			'attributes'     => $json['attributes'] ?? array(),
			'parent'         => $json['parent'] ?? null,
			'ancestor'       => $json['ancestor'] ?? null,
			'allowed_blocks' => $json['allowedBlocks'] ?? null,
			'supports'       => $json['supports'] ?? array(),
		)
	);
	++$loaded;
}

echo "  {$loaded} Blöcke geladen\n";
foreach ( $problems as $p ) {
	echo "  \033[31m!\033[0m {$p}\n";
}

$fail = 0;

function check( $condition, $message, $detail = '' ) {
	if ( $condition ) {
		echo "  \033[32m✓\033[0m {$message}\n";
		return true;
	}
	echo "  \033[31m✗\033[0m {$message}\n";
	if ( '' !== $detail ) {
		echo "      {$detail}\n";
	}
	$GLOBALS['fail'] = ( $GLOBALS['fail'] ?? 0 ) + 1;
	return false;
}

// --- Catalogue ---------------------------------------------------------
echo "\n\033[1mKatalog\033[0m\n";

$catalog = wpmcp_build_catalog( 'site' );
check( count( $catalog ) > 40, sprintf( 'Katalog enthält %d Blöcke', count( $catalog ) ) );

$roles = array_count_values( array_column( $catalog, 'role' ) );
echo sprintf(
	"      Rollen: %d Container, %d Kind-Blöcke, %d standalone\n",
	$roles['container'] ?? 0,
	$roles['child'] ?? 0,
	$roles['standalone'] ?? 0
);

$by_name = array();
foreach ( $catalog as $entry ) {
	$by_name[ $entry['name'] ] = $entry;
}

// Nesting relationships that must be answerable server-side.
$expected_nesting = array(
	'dbw-base/cards'               => 'dbw-base/card-item',
	'dbw-base/accordion'           => 'dbw-base/accordion-item',
	'dbw-base/usp-list'            => 'dbw-base/usp-item',
	'dbw-base/timeline'            => 'dbw-base/timeline-item',
	'dbw-base/bento-grid'          => 'dbw-base/bento-card',
	'dbw-base/card-carousel'       => 'dbw-base/carousel-card',
	'dbw-base/compare-list'        => 'dbw-base/compare-column',
	'dbw-base/compare-column'      => 'dbw-base/compare-item',
	'dbw-base/card-item'           => 'dbw-base/feature-list',
	'dbw-base/testimonials-grid'   => 'dbw-base/testimonial-quote',
	'dbw-base/process-steps'       => 'dbw-base/process-step',
	'dbw-base/team-grid'           => 'dbw-base/team-card',
	'dbw-base/case-study-grid'     => 'dbw-base/case-study-card',
	'dbw-base/sticky-media-scroll' => 'dbw-base/sticky-panel',
	'dbw-base/scrolly-framework'   => 'dbw-base/scrolly-card',
);

$nesting_ok = 0;
foreach ( $expected_nesting as $parent => $child ) {
	$accepts = $by_name[ $parent ]['accepts'] ?? null;
	if ( is_array( $accepts ) && in_array( $child, $accepts, true ) ) {
		++$nesting_ok;
	} else {
		echo "      \033[31mfehlt:\033[0m {$parent} akzeptiert {$child} nicht\n";
	}
}
check(
	count( $expected_nesting ) === $nesting_ok,
	sprintf( 'Alle %d Container-Beziehungen serverseitig lesbar', count( $expected_nesting ) )
);

check(
	'any' === ( $by_name['dbw-base/section']['accepts'] ?? null ),
	'section ist als offener Container erkannt'
);

// --- Descriptions ------------------------------------------------------
echo "\n\033[1mSemantik\033[0m\n";

$total_attrs = 0;
$missing     = array();
foreach ( $registry->get_all_registered() as $name => $type ) {
	// Only the real kit — bootstrap.php also registers core block stubs.
	if ( ! str_starts_with( $name, 'dbw-base/' ) ) {
		continue;
	}
	foreach ( (array) $type->attributes as $key => $def ) {
		++$total_attrs;
		if ( empty( $def['description'] ) ) {
			$missing[] = "{$name}.{$key}";
		}
	}
}
check(
	empty( $missing ),
	sprintf( 'Alle %d Attribute haben eine Beschreibung', $total_attrs ),
	empty( $missing ) ? '' : 'fehlend: ' . implode( ', ', array_slice( $missing, 0, 8 ) )
);

// --- Detail view -------------------------------------------------------
echo "\n\033[1mBlock-Details\033[0m\n";

$heavy    = array( 'dbw-base/hero', 'dbw-base/card-item', 'dbw-base/footer-info' );
$detailed = wpmcp_describe_blocks( $heavy );

foreach ( $detailed as $block ) {
	if ( isset( $block['error'] ) ) {
		check( false, "Detail für {$block['name']}", $block['error'] );
		continue;
	}
	$size    = strlen( wp_json_encode( $block ) );
	$groups  = array_keys( $block['attributes'] );
	$counted = 0;
	foreach ( $block['attributes'] as $group ) {
		$counted += count( $group );
	}
	check(
		$size < 20000,
		sprintf( '%s: %d Attribute in %d Gruppen, %.1f KB', $block['name'], $counted, count( $groups ), $size / 1024 ),
		$size >= 20000 ? 'Detail zu groß fürs Kontextfenster' : ''
	);
}

$catalog_size = strlen( wp_json_encode( $catalog ) );
check(
	$catalog_size < 60000,
	sprintf( 'Katalog ist %.1f KB (~%d Tokens)', $catalog_size / 1024, (int) ( $catalog_size / 4 ) )
);

// Legacy attributes must be grouped away from the useful ones.
$hero        = $detailed[0];
$legacy_hero = array_keys( $hero['attributes']['legacy'] ?? array() );
check(
	! empty( $legacy_hero ),
	sprintf( 'hero: %d Legacy-Attribute sind ausgegliedert', count( $legacy_hero ) ),
	'Legacy-Attribute wurden nicht als solche erkannt'
);
check(
	! isset( $hero['attributes']['content']['primaryButtonText'] ),
	'hero: Legacy-Button-Attribute stehen nicht bei den Inhalts-Attributen'
);

// --- Deprecated enum values -------------------------------------------
echo "\n\033[1mDeprecated-Werte\033[0m\n";

$section = wpmcp_describe_blocks( array( 'dbw-base/section' ) )[0];
$bg      = null;
foreach ( $section['attributes'] as $group ) {
	if ( isset( $group['backgroundColor'] ) ) {
		$bg = $group['backgroundColor'];
		break;
	}
}
check( null !== $bg, 'section.backgroundColor gefunden' );
if ( $bg ) {
	check(
		! empty( $bg['deprecatedValues'] ),
		'Legacy-Werte sind als deprecated ausgewiesen: ' . implode( ', ', $bg['deprecatedValues'] ?? array() )
	);
	$overlap = array_intersect( $bg['enum'], $bg['deprecatedValues'] ?? array() );
	check( empty( $overlap ), 'Deprecated-Werte tauchen nicht mehr im angebotenen Enum auf' );
}

// A stored legacy value must still validate.
$blocks = parse_blocks( '<!-- wp:dbw-base/section {"backgroundColor":"dark-grey"} /-->' );
$v      = wpmcp_validate_blocks( $blocks );
check( empty( $v['errors'] ), 'Gespeicherter Legacy-Wert bleibt gültig (Bestandsschutz)' );
check( ! empty( $v['warnings'] ), 'Gespeicherter Legacy-Wert erzeugt eine Warnung' );

// --- Validation against real schemas -----------------------------------
echo "\n\033[1mValidierung gegen echte Schemas\033[0m\n";

$valid_page = array(
	array(
		'name'  => 'dbw-base/hero',
		'attrs' => array(
			'heading' => 'Wir machen Ihre Website zum besten Mitarbeiter',
			'eyebrow' => 'Webdesign',
		),
	),
	array(
		'name'        => 'dbw-base/usp-list',
		'attrs'       => array( 'sectionHeading' => 'Warum wir' ),
		'innerBlocks' => array(
			array(
				'name'  => 'dbw-base/usp-item',
				'attrs' => array(
					'heading' => 'Schnell',
					'text'    => 'In 48 Stunden online.',
				),
			),
		),
	),
	array(
		'name'        => 'dbw-base/cards',
		'attrs'       => array( 'columns' => 3 ),
		'innerBlocks' => array(
			array(
				'name'  => 'dbw-base/card-item',
				'attrs' => array( 'heading' => 'Beratung' ),
			),
		),
	),
);

$errors = array();
$built  = wpmcp_tree_to_blocks( $valid_page, '', $errors );
check( empty( $errors ), 'Realistische Seite lässt sich in Blöcke übersetzen', implode( '; ', $errors ) );

$v = wpmcp_validate_blocks( $built );
check( empty( $v['errors'] ), 'Realistische Seite besteht die Validierung', implode( ' | ', $v['errors'] ) );

$markup = serialize_blocks( $built );
check( str_contains( $markup, 'wp:dbw-base/hero' ), 'Serialisierung erzeugt gültiges Block-Markup' );

$reparsed = parse_blocks( $markup );
check(
	wpmcp_count_blocks( $reparsed ) === wpmcp_count_blocks( $built ),
	'Roundtrip der realistischen Seite ist verlustfrei'
);

// Wrong nesting must be caught with the real schemas.
$bad = wpmcp_tree_to_blocks(
	array(
		array(
			'name'  => 'dbw-base/card-item',
			'attrs' => array( 'heading' => 'Frei stehend' ),
		),
	),
	'',
	$errors
);
$v = wpmcp_validate_blocks( $bad );
check( ! empty( $v['errors'] ), 'Falsche Verschachtelung wird mit echten Schemas erkannt' );

$bad = wpmcp_tree_to_blocks(
	array(
		array(
			'name'        => 'dbw-base/cards',
			'attrs'       => array(),
			'innerBlocks' => array(
				array(
					'name'  => 'dbw-base/usp-item',
					'attrs' => array(),
				),
			),
		),
	),
	'',
	$errors
);
$v = wpmcp_validate_blocks( $bad );
check( ! empty( $v['errors'] ), 'Falsches Kind in geschlossenem Container wird erkannt' );

// --- Result ------------------------------------------------------------
$fail = $GLOBALS['fail'] ?? 0;
echo "\n";
if ( 0 === $fail ) {
	echo "\033[32mIntegration bestanden.\033[0m\n";
	exit( 0 );
}
echo "\033[31m{$fail} Prüfung(en) fehlgeschlagen.\033[0m\n";
exit( 1 );
