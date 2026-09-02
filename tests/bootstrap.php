<?php
/**
 * Minimal WordPress shim so the tree, schema and validation logic can be
 * tested without a full WordPress install.
 *
 * The block parser and serializer are the REAL WordPress implementations
 * (fetched into tests/wp-shim), so round-trip results here mean the same
 * thing they would mean on a live site.
 *
 * @package wp-mcp-connector-plus
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'WPMCP_VERSION', 'test' );

require_once __DIR__ . '/wp-shim/class-wp-block-parser-block.php';
require_once __DIR__ . '/wp-shim/class-wp-block-parser-frame.php';
require_once __DIR__ . '/wp-shim/class-wp-block-parser.php';

// --- Core helpers used by the parser/serializer ------------------------

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

function parse_blocks( $content ) {
	$parser = new WP_Block_Parser();
	return $parser->parse( $content );
}

function strip_core_block_namespace( $block_name = null ) {
	if ( is_string( $block_name ) && str_starts_with( $block_name, 'core/' ) ) {
		return substr( $block_name, 5 );
	}
	return $block_name;
}

function serialize_block_attributes( $block_attributes ) {
	$encoded = wp_json_encode( $block_attributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	$encoded = preg_replace( '/--/', '\\u002d\\u002d', $encoded );
	$encoded = preg_replace( '/</', '\\u003c', $encoded );
	$encoded = preg_replace( '/>/', '\\u003e', $encoded );
	$encoded = preg_replace( '/&/', '\\u0026', $encoded );
	$encoded = preg_replace( '/\\\\"/', '\\u0022', $encoded );
	return $encoded;
}

function get_comment_delimited_block_content( $block_name, $block_attributes, $block_content ) {
	if ( is_null( $block_name ) ) {
		return $block_content;
	}
	$name  = strip_core_block_namespace( $block_name );
	$attrs = empty( $block_attributes ) ? '' : serialize_block_attributes( $block_attributes ) . ' ';

	if ( '' === $block_content ) {
		return sprintf( '<!-- wp:%s %s/-->', $name, $attrs );
	}
	return sprintf( '<!-- wp:%s %s-->%s<!-- /wp:%s -->', $name, $attrs, $block_content, $name );
}

function serialize_block( $block ) {
	$content = '';
	$index   = 0;
	foreach ( (array) $block['innerContent'] as $chunk ) {
		$content .= is_string( $chunk ) ? $chunk : serialize_block( $block['innerBlocks'][ $index++ ] );
	}
	if ( ! is_array( $block['attrs'] ) ) {
		$block['attrs'] = array();
	}
	return get_comment_delimited_block_content( $block['blockName'], $block['attrs'], $content );
}

function serialize_blocks( $blocks ) {
	return implode( '', array_map( 'serialize_block', $blocks ) );
}

// --- Minimal WP surface -------------------------------------------------

class WP_Error {
	public $code;
	public $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_message() {
		return $this->message;
	}
	public function get_error_code() {
		return $this->code;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

$GLOBALS['dbw_filters'] = array();

function add_filter( $tag, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['dbw_filters'][ $tag ][] = $callback;
	return true;
}

function apply_filters( $tag, $value ) {
	foreach ( $GLOBALS['dbw_filters'][ $tag ] ?? array() as $callback ) {
		$value = $callback( $value );
	}
	return $value;
}

function add_action( $tag, $callback, $priority = 10, $args = 1 ) {
	return true;
}

function __return_true() {
	return true;
}

function __return_false() {
	return false;
}

function wp_strip_all_tags( $text ) {
	return trim( strip_tags( (string) $text ) );
}

function do_blocks( $content ) {
	// Tests exercise tree/validation logic, not block rendering.
	return $content;
}

/**
 * Block type stub mirroring the parts of WP_Block_Type we rely on.
 */
class WP_Block_Type {
	public $name;
	public $title       = '';
	public $description = '';
	public $attributes  = array();
	public $parent;
	public $ancestor;
	public $allowed_blocks;
	public $supports    = array();

	public function __construct( $name, array $args = array() ) {
		$this->name = $name;
		foreach ( $args as $key => $value ) {
			$this->{$key} = $value;
		}
	}
}

class WP_Block_Type_Registry {
	private static $instance = null;
	private $types           = array();

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register( $name, array $args = array() ) {
		$this->types[ $name ] = new WP_Block_Type( $name, $args );
	}

	public function get_registered( $name ) {
		return $this->types[ $name ] ?? null;
	}

	public function get_all_registered() {
		return $this->types;
	}
}

// Global settings stub (theme.json equivalent).
$GLOBALS['dbw_test_global_settings'] = array(
	'color'      => array(
		'custom'  => false,
		'palette' => array(
			'theme' => array(
				array(
					'slug'  => 'primary',
					'name'  => 'Primary',
					'color' => '#1A4B84',
				),
				array(
					'slug'  => 'accent',
					'name'  => 'Accent',
					'color' => '#EA2B1F',
				),
			),
		),
	),
	'typography' => array(
		'customFontSize' => false,
		'fontSizes'      => array(
			'theme' => array(
				array( 'slug' => 'h1' ),
				array( 'slug' => 'base' ),
			),
		),
	),
	'spacing'    => array(
		'spacingSizes' => array(
			'theme' => array(
				array( 'slug' => 's' ),
				array( 'slug' => 'm' ),
				array( 'slug' => 'l' ),
			),
		),
	),
	'layout'     => array(
		'contentSize' => '800px',
		'wideSize'    => '1200px',
	),
);

function wp_get_global_settings() {
	return $GLOBALS['dbw_test_global_settings'];
}

// --- Code under test ----------------------------------------------------

require_once dirname( __DIR__ ) . '/includes/schema.php';
require_once dirname( __DIR__ ) . '/includes/tree.php';
require_once dirname( __DIR__ ) . '/includes/validate.php';
require_once dirname( __DIR__ ) . '/includes/catalog.php';

// --- Test blocks mirroring the real dbw-base kit ------------------------

$registry = WP_Block_Type_Registry::get_instance();

$registry->register(
	'dbw-base/section',
	array(
		'title'       => 'Section',
		'description' => 'Outer section container.',
		'attributes'  => array(
			'paddingSize'     => array(
				'type'    => 'string',
				'default' => 'm',
				'enum'    => array( 's', 'm', 'l' ),
			),
			'backgroundColor' => array(
				'type'           => 'string',
				'default'        => '',
				'enum'           => array( '', 'primary', 'accent', 'white', 'off-white', 'dark', 'surface' ),
				'deprecatedEnum' => array( 'surface' ),
			),
			'anchor'          => array( 'type' => 'string' ),
		),
		'supports'    => array(
			'anchor' => true,
			'align'  => array( 'wide', 'full' ),
		),
	)
);

$registry->register(
	'dbw-base/cards',
	array(
		'title'          => 'Cards',
		'description'    => 'Grid container for cards.',
		'attributes'     => array(
			'columns' => array(
				'type'    => 'number',
				'default' => 3,
			),
			'heading' => array(
				'type'    => 'string',
				'default' => '',
			),
		),
		'allowed_blocks' => array( 'dbw-base/card-item' ),
	)
);

$registry->register(
	'dbw-base/card-item',
	array(
		'title'      => 'Card',
		'attributes' => array(
			'heading'    => array(
				'type'    => 'string',
				'default' => '',
			),
			'text'       => array(
				'type'    => 'string',
				'default' => '',
			),
			'iconColor'  => array(
				'type'    => 'string',
				'default' => 'primary',
			),
			'buttons'    => array(
				'type'    => 'array',
				'default' => array(),
				'items'   => array(
					'type'       => 'object',
					'properties' => array(
						'text'   => array( 'type' => 'string' ),
						'url'    => array( 'type' => 'string' ),
						'style'  => array( 'type' => 'string' ),
						'newTab' => array( 'type' => 'boolean' ),
					),
				),
			),
		),
		'parent'     => array( 'dbw-base/cards' ),
	)
);

$registry->register(
	'dbw-base/feature-list',
	array(
		'title'      => 'Feature list',
		'attributes' => array(
			'items' => array(
				'type'    => 'array',
				'default' => array(),
			),
		),
		'parent'     => array( 'dbw-base/card-item' ),
	)
);

$registry->register(
	'dbw-base/hero',
	array(
		'title'      => 'Hero',
		'attributes' => array(
			'heading'          => array(
				'type'    => 'string',
				'default' => '',
			),
			'headingLevel'     => array(
				'type'    => 'number',
				'default' => 1,
			),
			'showScrollHint'   => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'primaryButtonText' => array(
				'type'        => 'string',
				'description' => 'Legacy: migrated to buttons[]. Do not use for new content.',
			),
		),
	)
);

$registry->register(
	'dbw-base/usp-list',
	array(
		'title'      => 'USP list',
		'attributes' => array(
			'heading' => array(
				'type'    => 'string',
				'default' => '',
			),
		),
	)
);

$registry->register(
	'dbw-base/usp-item',
	array(
		'title'      => 'USP item',
		'attributes' => array(
			'heading' => array(
				'type'    => 'string',
				'default' => '',
			),
		),
		'parent'     => array( 'dbw-base/usp-list' ),
	)
);

$registry->register(
	'core/paragraph',
	array(
		'title'      => 'Paragraph',
		'attributes' => array(
			'content' => array(
				'type'    => 'string',
				'default' => '',
			),
			'style'   => array( 'type' => 'object' ),
		),
	)
);

$registry->register(
	'core/heading',
	array(
		'title'      => 'Heading',
		'attributes' => array(
			'content' => array(
				'type'    => 'string',
				'default' => '',
			),
			'level'   => array(
				'type'    => 'number',
				'default' => 2,
			),
		),
	)
);

$registry->register(
	'core/group',
	array(
		'title'      => 'Group',
		'attributes' => array(),
	)
);

/*
 * Register this kit's open containers the way a real theme or plugin would.
 * The plugin ships only the WordPress core containers; everything else
 * arrives through this filter, so exercising it here also tests it.
 */
add_filter(
	'wpmcp_open_containers',
	function ( $blocks ) {
		$blocks[] = 'dbw-base/section';
		$blocks[] = 'dbw-base/popup';
		$blocks[] = 'dbw-base/scroll-scale-section';
		return $blocks;
	}
);
