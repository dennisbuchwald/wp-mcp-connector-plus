<?php
/**
 * Attributes a block reads from its markup, sent in "attrs".
 *
 * On staging.maxport.ch a core/shortcode went in as
 * {"name":"core/shortcode","attrs":{"text":"[contact-form-7 id=\"1\"]"}}
 * with no "html", the dry run said ok, and the page got an empty block:
 * "text" has `source: raw`, so WordPress and the editor read it from the
 * block's markup, never from the comment, and the markup was empty. The
 * same holds for every attribute with a source (html, text, rich-text,
 * raw, attribute, query, children): core/paragraph's content, core/image's
 * url and alt, core/button's text.
 *
 * The block definitions below are those of WordPress 6.9.8
 * (wp-includes/blocks/<name>/block.json), reduced to the attributes used.
 *
 * Run: php tests/sourced-attributes.php
 *
 * @package wp-mcp-connector-plus
 */

require_once __DIR__ . '/bootstrap.php';

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
$registry->register( 'core/html', array( 'attributes' => array( 'content' => array( 'type' => 'string', 'source' => 'raw' ) ) ) );
$registry->register(
	'core/paragraph',
	array(
		'attributes' => array(
			'content' => array( 'type' => 'rich-text', 'source' => 'rich-text', 'selector' => 'p', 'role' => 'content' ),
			'style'   => array( 'type' => 'object' ),
		),
	)
);
$registry->register(
	'core/heading',
	array(
		'attributes' => array(
			'content' => array( 'type' => 'rich-text', 'source' => 'rich-text', 'selector' => 'h1,h2,h3,h4,h5,h6', 'role' => 'content' ),
			'level'   => array( 'type' => 'number', 'default' => 2 ),
		),
	)
);
$registry->register(
	'core/image',
	array(
		'attributes' => array(
			'url' => array( 'type' => 'string', 'source' => 'attribute', 'selector' => 'img', 'attribute' => 'src' ),
			'alt' => array( 'type' => 'string', 'source' => 'attribute', 'selector' => 'img', 'attribute' => 'alt', 'default' => '' ),
			'id'  => array( 'type' => 'number' ),
		),
	)
);

$shortcode = '[contact-form-7 id="1"]';

echo "\n\033[1mMarkup, die nur aus dem Attribut besteht, wird erzeugt\033[0m\n";

$errors  = array();
$context = wpmcp_wrapper_context();
$blocks  = wpmcp_tree_to_blocks( array( array( 'name' => 'core/shortcode', 'attrs' => array( 'text' => $shortcode ) ) ), '', $errors, $context );
check( empty( $errors ), 'core/shortcode mit attrs.text und ohne html wird angenommen', implode( ' ', $errors ) );
check( $shortcode === ( $blocks[0]['innerHTML'] ?? null ), 'die Markup ist der Shortcode', wp_json_encode( $blocks[0] ?? null ) );
check( array() === ( $blocks[0]['attrs'] ?? null ), 'text steht nicht im Kommentar', wp_json_encode( $blocks[0]['attrs'] ?? null ) );
$serialized = serialize_blocks( $blocks );
check( '<!-- wp:shortcode -->' . $shortcode . '<!-- /wp:shortcode -->' === $serialized, 'gespeichert wie vom Editor gelesen', $serialized );
$parsed = parse_blocks( $serialized );
check( $shortcode === trim( $parsed[0]['innerHTML'] ?? '' ), 'und wieder gelesen steht der Shortcode in der Markup' );
$generated = $context->markup ?? array();
check( array( array( 'path' => '0', 'block' => 'core/shortcode', 'attribute' => 'text' ) ) === $generated, 'markupGenerated nennt Pfad, Block und Attribut', wp_json_encode( $generated ) );

$errors  = array();
$context = wpmcp_wrapper_context();
$blocks  = wpmcp_tree_to_blocks( array( array( 'name' => 'core/html', 'attrs' => array( 'content' => '<div class="x">Hallo</div>' ) ) ), '', $errors, $context );
check( empty( $errors ) && '<div class="x">Hallo</div>' === ( $blocks[0]['innerHTML'] ?? null ) && array() === $blocks[0]['attrs'], 'core/html ebenso', wp_json_encode( $blocks ) . implode( ' ', $errors ) );

$errors = array();
$blocks = wpmcp_tree_to_blocks( array( array( 'name' => 'core/shortcode', 'attrs' => array( 'text' => $shortcode ), 'html' => '[gallery]' ) ), '', $errors );
check( empty( $errors ) && '[gallery]' === $blocks[0]['innerHTML'] && array() === $blocks[0]['attrs'], 'mit html gilt html, und text bleibt aus dem Kommentar', wp_json_encode( $blocks ) . implode( ' ', $errors ) );

echo "\n\033[1mAndere Attribute aus der Markup ohne html: abgelehnt, mit Hinweis\033[0m\n";

$errors  = array();
$context = wpmcp_wrapper_context();
wpmcp_tree_to_blocks( array( array( 'name' => 'core/paragraph', 'attrs' => array( 'content' => 'Hallo Welt' ) ) ), '', $errors, $context );
$message = implode( ' ', $errors );
check( 1 === count( $errors ), 'core/paragraph mit attrs.content und ohne html wird abgelehnt', $message );
check( ! empty( $context->sourced ), 'und als wpmcp_sourced_attribute erkannt', wp_json_encode( $context->sourced ?? null ) );
check( false !== strpos( $message, '"content"' ) && false !== strpos( $message, '"p"' ), 'die Meldung nennt Attribut und Selektor', $message );
check( false !== strpos( $message, '<p>Hallo Welt</p>' ), 'und zeigt die Markup, die zu senden ist', $message );

$errors = array();
wpmcp_tree_to_blocks( array( array( 'name' => 'core/heading', 'attrs' => array( 'content' => 'Kurse', 'level' => 3 ) ) ), '', $errors );
check( false !== strpos( implode( ' ', $errors ), '<h3 class="wp-block-heading">Kurse</h3>' ), 'core/heading: die Ebene aus level', implode( ' ', $errors ) );

$errors = array();
wpmcp_tree_to_blocks( array( array( 'name' => 'core/image', 'attrs' => array( 'url' => '/a.jpg', 'alt' => 'Boot', 'id' => 5 ) ) ), '', $errors );
$message = implode( ' ', $errors );
check( false !== strpos( $message, '"url"' ) && false !== strpos( $message, '"alt"' ) && false !== strpos( $message, '<figure class="wp-block-image"><img src="/a.jpg" alt="Boot"' ), 'core/image: url und alt, mit figure und img', $message );

$errors = array();
wpmcp_tree_to_blocks( array( array( 'name' => 'core/group', 'innerBlocks' => array( array( 'name' => 'core/paragraph', 'attrs' => array( 'content' => 'x' ) ) ) ) ), '', $errors );
check( false !== strpos( implode( ' ', $errors ), '0.0:' ), 'auch verschachtelt, mit Pfad', implode( ' ', $errors ) );

$errors = array();
$blocks = wpmcp_tree_to_blocks( array( array( 'name' => 'core/paragraph', 'attrs' => array( 'content' => 'Alt' ), 'html' => '<p>Neu</p>' ) ), '', $errors );
check( empty( $errors ) && array() === $blocks[0]['attrs'] && '<p>Neu</p>' === $blocks[0]['innerHTML'], 'mit html: angenommen, content kommt nicht in den Kommentar', wp_json_encode( $blocks ) . implode( ' ', $errors ) );

$errors = array();
$blocks = wpmcp_tree_to_blocks( array( array( 'name' => 'core/paragraph', 'attrs' => array( 'style' => array( 'x' => 1 ) ), 'html' => '<p>a</p>' ) ), '', $errors );
check( empty( $errors ) && isset( $blocks[0]['attrs']['style'] ), 'Attribute ohne source bleiben im Kommentar' );

echo "\n\033[1mOperationen\033[0m\n";

$page = parse_blocks( '<!-- wp:paragraph --><p>a</p><!-- /wp:paragraph -->' );

$r = wpmcp_apply_ops( $page, array( array( 'op' => 'insert', 'path' => '1', 'block' => array( 'name' => 'core/paragraph', 'attrs' => array( 'content' => 'b' ) ) ) ) );
check( is_wp_error( $r ) && 'wpmcp_sourced_attribute' === $r->get_error_code(), 'insert ohne html: wpmcp_sourced_attribute', is_wp_error( $r ) ? $r->get_error_code() . ' ' . $r->get_error_message() : 'kein Fehler' );

$r = wpmcp_apply_ops( $page, array( array( 'op' => 'insert', 'path' => '1', 'block' => array( 'name' => 'core/shortcode', 'attrs' => array( 'text' => $shortcode ) ) ) ) );
check( ! is_wp_error( $r ) && $shortcode === ( $r['blocks'][1]['innerHTML'] ?? null ), 'insert eines Shortcodes nur mit text', is_wp_error( $r ) ? $r->get_error_message() : wp_json_encode( $r['blocks'][1] ?? null ) );

$r = wpmcp_apply_ops( $page, array( array( 'op' => 'set_attrs', 'path' => '0', 'attrs' => array( 'content' => 'b' ) ) ) );
check( is_wp_error( $r ) && 'wpmcp_sourced_attribute' === $r->get_error_code() && false !== strpos( $r->get_error_message(), 'patch_html' ), 'set_attrs auf ein Attribut aus der Markup: abgelehnt, mit Verweis auf patch_html', is_wp_error( $r ) ? $r->get_error_message() : 'kein Fehler' );

$stored = parse_blocks( '<!-- wp:paragraph {"content":"alt"} --><p>a</p><!-- /wp:paragraph -->' );
$r      = wpmcp_apply_ops( $stored, array( array( 'op' => 'set_attrs', 'path' => '0', 'attrs' => array( 'content' => null ) ) ) );
check( ! is_wp_error( $r ) && ! isset( $r['blocks'][0]['attrs']['content'] ), 'set_attrs mit null raeumt einen solchen Kommentar-Rest auf' );

exit( $fail > 0 ? 1 : 0 );
