<?php
/**
 * CSS settings of Elementor elements are judged as CSS, not as markup.
 *
 * Field report: elementor-write refused an SVG background written as
 * url("data:image/svg+xml,<svg ...></svg>") in a widget's custom_css
 * with "adds markup WordPress will not store". The guard read the CSS as
 * HTML and kses as its judge: the "<svg" made it markup, kses stripped
 * it, refused. In a style sheet that text is a picture. What can do harm
 * in CSS is something else, and that is refused, each with its fragment:
 * anything that ends the <style> element around it, expression(),
 * javascript: and vbscript:, behavior and -moz-binding, @import, and a
 * data: URL that is not an image. CSS escapes are decoded first.
 *
 * Run: php tests/elementor-css.php
 *
 * @package wp-mcp-connector-plus
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/kses-stub.php';
require_once dirname( __DIR__ ) . '/includes/tree.php';
require_once dirname( __DIR__ ) . '/includes/markup-guard.php';
require_once dirname( __DIR__ ) . '/includes/elementor/data.php';
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

function page_with_css( $css ) {
	return array(
		array(
			'id'       => 'c000001',
			'elType'   => 'container',
			'settings' => array(),
			'elements' => array(
				array(
					'id'         => 'w000001',
					'elType'     => 'widget',
					'widgetType' => 'html',
					'settings'   => array_filter(
						array(
							'html'       => '<div class="hero">Hallo</div>',
							'custom_css' => $css,
						),
						function ( $v ) { return null !== $v; }
					),
					'elements'   => array(),
				),
			),
			'isInner'  => false,
		),
	);
}

function judge( $css, $before_css = null ) {
	return wpmcp_elementor_guard( page_with_css( $before_css ), page_with_css( $css ) );
}

$svg_raw     = "selector .hero { background: url(\"data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 10 10'><path d='M0 0h10v10z' fill='%23D9F24A'/></svg>\") no-repeat; }";
$svg_encoded = 'selector .hero { background-image: url("data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 10 10%27%3E%3Cpath d=%27M0 0h10v10z%27/%3E%3C/svg%3E"); }';
$png         = 'selector { background: url(data:image/png;base64,iVBORw0KGgo=) }';

echo "\n\033[1mBilder in CSS werden angenommen\033[0m\n";

foreach ( array( 'SVG roh' => $svg_raw, 'SVG prozentkodiert' => $svg_encoded, 'PNG base64' => $png, 'gewoehnliches CSS' => 'selector h2 { color: #0f1330; content: "a < b"; }' ) as $label => $css ) {
	$r = judge( $css );
	check( empty( $r['errors'] ), $label . ': kein Fehler', implode( ' ', $r['errors'] ) );
}

$r = judge( $svg_raw . ' selector { background: url("https://example.test/a.png"); }' );
check( empty( $r['errors'] ), 'neben einer gewoehnlichen URL', implode( ' ', $r['errors'] ) );

foreach ( array( 'image/jpeg', 'image/gif', 'image/webp', 'image/avif' ) as $mime ) {
	$r = judge( 'selector { background: url("data:' . $mime . ';base64,AAAA") }' );
	check( empty( $r['errors'] ), $mime . ' ist ein Bild', implode( ' ', $r['errors'] ) );
}

echo "\n\033[1mWas in CSS schaden kann, wird mit Fundstueck abgelehnt\033[0m\n";

$dangers = array(
	'</style> beendet das Style-Element'      => array( 'selector{} </style><script>alert(1)</script>', '</style' ),
	'</STYLE in Grossbuchstaben'              => array( 'selector{} </STYLE >', '</STYLE' ),
	'<\\/style als Escape'                     => array( 'selector{} <\\/style>', '<\\/style' ),
	'<!-- Kommentar oeffnet'                   => array( 'selector{} <!--', '<!--' ),
	'--> Kommentar schliesst'                  => array( 'selector{} -->', '-->' ),
	'<script'                                  => array( 'selector{ background: url("data:image/svg+xml,<svg><script>alert(1)</script></svg>") }', '<script' ),
	'expression('                              => array( 'selector{ width: expression(alert(1)) }', 'expression(' ),
	'expression mit CSS-Escape'                => array( 'selector{ width: \\65 xpression(alert(1)) }', 'expression(' ),
	'expression mit Kommentar'                 => array( 'selector{ width: expr/**/ession(alert(1)) }', 'expression(' ),
	'javascript: in url()'                     => array( 'selector{ background: url(javascript:alert(1)) }', 'javascript:' ),
	'javascript: mit CSS-Escape'               => array( 'selector{ background: url(\\6a avascript:alert(1)) }', 'javascript:' ),
	'javascript: mit Escape eines Buchstabens' => array( 'selector{ background: url(java\\script:alert(1)) }', 'javascript:' ),
	'vbscript:'                                => array( 'selector{ background: url("vbscript:x") }', 'vbscript:' ),
	'behavior:'                                => array( 'selector{ behavior: url(x.htc) }', 'behavior:' ),
	'-moz-binding'                             => array( 'selector{ -moz-binding: url(x.xml#a) }', '-moz-binding' ),
	'@import'                                  => array( '@import url("https://evil.test/x.css"); selector{}', '@import' ),
	'@IMPORT mit Escape'                       => array( '@\\49 MPORT "x.css";', '@import' ),
	'data:text/html'                           => array( 'selector{ background: url("data:text/html,<b>x</b>") }', 'data:text/html' ),
	'data: ohne Bild-Typ'                      => array( 'selector{ background: url(data:;base64,AAAA) }', 'data:' ),
	'data:image/svg+xml mit script'            => array( "selector{ background: url(\"data:image/svg+xml,<svg><script>x</script></svg>\") }", '<script' ),
);

foreach ( $dangers as $label => $case ) {
	list( $css, $fragment ) = $case;
	$r    = judge( $css );
	$text = implode( ' ', $r['errors'] );
	check( ! empty( $r['errors'] ), $label . ': abgelehnt', $text );
	check( false !== stripos( $text, $fragment ), '  ... mit dem Fundstueck ' . $fragment, $text );
	check( false !== strpos( $text, 'w000001:custom_css' ), '  ... und dem Ort', $text );
}

echo "\n\033[1mUnveraendertes CSS bleibt, wie es ist\033[0m\n";

$old    = 'selector{ behavior: url(x.htc) }';
$before = page_with_css( $old );
$after  = page_with_css( $old );
$after[0]['elements'][0]['settings']['html'] = '<div class="hero">Neu</div>';
$r = wpmcp_elementor_guard( $before, $after );
check( empty( $r['errors'] ), 'eine Aenderung am HTML laesst altes CSS unbeurteilt', implode( ' ', $r['errors'] ) );

echo "\n\033[1mDas Markup eines HTML-Widgets bleibt Markup\033[0m\n";

$before = page_with_css( null );
$after  = page_with_css( null );
$after[0]['elements'][0]['settings']['html'] = '<div onclick="alert(1)">x</div>';
$r = wpmcp_elementor_guard( $before, $after );
check( ! empty( $r['errors'] ), 'ein Event-Handler im html bleibt abgelehnt', implode( ' ', $r['errors'] ) );

echo "\n";
if ( $fail ) {
	echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
	exit( 1 );
}
echo "\033[32mAlle Pruefungen bestanden.\033[0m\n";
