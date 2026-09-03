<?php
/**
 * The pages the connector was built for, and could not save.
 *
 * Some block libraries refuse to store a page holding dynamic data unless
 * the account has unfiltered_html. On one real site that was nearly every
 * service and industry page. The refusal arrived as a bare 403, three
 * separate sprints read it as "the plugin filters my content", and the
 * work went around the API through the database — where nothing is
 * checked at all.
 *
 * Granting the capability to the role was never an option: it is the
 * widest permission in the set, in a role that deliberately cannot
 * publish, delete, upload or change settings. So it is granted for one
 * save, and this guard replaces the filtering WordPress then skips.
 *
 * Run: php tests/dynamic-data.php
 *
 * @package wp-mcp-connector-plus
 */

require_once __DIR__ . '/bootstrap.php';

function wp_kses_post( $content ) {
	$content = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', (string) $content );
	return preg_replace( '#<iframe\b[^>]*>.*?</iframe>#is', '', $content );
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

/** Names of what a change would newly bring in. */
function kinds( $before, $after ) {
	return array_column( wpmcp_unsafe_additions( $before, $after ), 'kind' );
}

$plain = '<!-- wp:paragraph --><p>Ganz normaler Text</p><!-- /wp:paragraph -->';

echo "\n\033[1mWas der Guard durchlaesst\033[0m\n";

check( array() === kinds( $plain, $plain . $plain ), 'harmloses Markup' );
check(
	array() === kinds( $plain, str_replace( 'normaler', 'geaenderter', $plain ) ),
	'eine Textaenderung'
);
check(
	array() === kinds( $plain, '<!-- wp:paragraph --><p><a href="https://example.test">Link</a></p><!-- /wp:paragraph -->' ),
	'ein gewoehnlicher Link'
);
check(
	array() === kinds( $plain, '<!-- wp:paragraph --><p><a href="tel:+4971313859840">Anrufen</a></p><!-- /wp:paragraph -->' ),
	'ein tel-Link'
);
check(
	array() === kinds( $plain, '<!-- wp:image --><img src="data:image/png;base64,AAA" alt="x"><!-- /wp:image -->' ),
	'ein eingebettetes Bild als data-URL',
	'nur data:text/html kann Code ausfuehren'
);

echo "\n\033[1mWas er anhaelt\033[0m\n";

check(
	in_array( '<script>', kinds( $plain, $plain . '<!-- wp:html --><script>alert(1)</script><!-- /wp:html -->' ), true ),
	'ein neues Script-Tag'
);
check(
	in_array( 'event handler', kinds( $plain, '<!-- wp:button --><button onclick="steal()">Klick</button><!-- /wp:button -->' ), true ),
	'ein onclick-Attribut',
	'mit unfiltered_html landet das genauso in der Datenbank wie ein Script'
);
check(
	in_array( 'event handler', kinds( $plain, '<!-- wp:image --><img src="x" onerror="steal()"><!-- /wp:image -->' ), true ),
	'auch onerror an einem Bild'
);
check(
	in_array( 'javascript: URL', kinds( $plain, '<!-- wp:paragraph --><p><a href="javascript:steal()">Klick</a></p><!-- /wp:paragraph -->' ), true ),
	'eine javascript:-URL'
);
check(
	in_array( 'javascript: URL', kinds( $plain, '<!-- wp:paragraph --><p><a href=" JavaScript:steal()">x</a></p><!-- /wp:paragraph -->' ), true ),
	'auch mit Leerzeichen und in anderer Schreibweise'
);
check(
	in_array( 'data: document', kinds( $plain, '<!-- wp:paragraph --><iframe src="data:text/html,<script>x</script>"></iframe><!-- /wp:paragraph -->' ), true ),
	'ein data:text/html-Dokument'
);

echo "\n\033[1mNur Neues zaehlt\033[0m\n";

// A page that already embeds a video must stay editable, or the guard
// blocks exactly the everyday work it exists to protect.
$with_embed = $plain . '<!-- wp:html --><iframe src="https://player.test/1"></iframe><!-- /wp:html -->';

check(
	array() === kinds( $with_embed, $with_embed . $plain ),
	'ein bestehender iframe blockiert keine Bearbeitung'
);
check(
	array() === kinds( $with_embed, str_replace( 'normaler', 'neuer', $with_embed ) ),
	'auch nicht beim Aendern eines anderen Blocks'
);
check(
	in_array( '<iframe>', kinds( $with_embed, $with_embed . '<!-- wp:html --><iframe src="https://andere.test/2"></iframe><!-- /wp:html -->' ), true ),
	'ein zweiter, anderer iframe ist neu'
);

$with_handler = '<!-- wp:button --><button onclick="ok()">Klick</button><!-- /wp:button -->';
check(
	array() === kinds( $with_handler, $with_handler . $plain ),
	'ein bestehender Eventhandler ebenfalls nicht'
);
check(
	in_array( 'event handler', kinds( $with_handler, $with_handler . '<!-- wp:button --><button onmouseover="neu()">B</button><!-- /wp:button -->' ), true ),
	'ein anderer Eventhandler daneben schon'
);

echo "\n\033[1mDie Meldung\033[0m\n";

$after  = $plain . '<!-- wp:html --><button onclick="steal()">Klick</button><!-- /wp:html -->';
$unsafe = wpmcp_unsafe_additions( $plain, $after );
$blocks = parse_blocks( $after );
$message = wpmcp_unsafe_message( $unsafe, $blocks );

check( false !== strpos( $message, 'event handler' ), 'nennt die Art' );
check( false !== strpos( $message, 'onclick' ), 'nennt das konkrete Element' );
check( false !== strpos( $message, 'in block 1' ), 'und den Blockpfad', $message );
check( false !== strpos( $message, 'stands in its place' ), 'und warum ueberhaupt geprueft wird' );

echo "\n\033[1mDen Block finden\033[0m\n";

$nested = '<!-- wp:group --><div class="wp-block-group">'
	. '<!-- wp:paragraph --><p>Eins</p><!-- /wp:paragraph -->'
	. '<!-- wp:paragraph --><p>Zwei mit onclick="x"</p><!-- /wp:paragraph -->'
	. '</div><!-- /wp:group -->';

check( '0.1' === wpmcp_locate_markup( parse_blocks( $nested ), 'onclick="x"' ), 'auch verschachtelt' );
check( null === wpmcp_locate_markup( parse_blocks( $nested ), 'gibt es nicht' ), 'und meldet sich nicht bei einem Fehlgriff' );

echo "\n\033[1mDie Fehlermeldung, wenn es gesperrt ist\033[0m\n";

// The wording differs per plugin and none of them name the capability.
check(
	wpmcp_looks_like_unfiltered_html( "This content contains dynamic data, which your account doesn't have permission to save." ),
	'die Meldung von GenerateBlocks wird erkannt'
);
check(
	wpmcp_looks_like_unfiltered_html( 'Sorry, you are not allowed to use unfiltered_html.' ),
	'eine, die die Capability selbst nennt, ebenfalls'
);
check(
	! wpmcp_looks_like_unfiltered_html( 'Invalid post ID.' ),
	'eine gewoehnliche Fehlermeldung nicht'
);
check(
	! wpmcp_looks_like_unfiltered_html( 'This block shows dynamic data.' ),
	'und auch nicht jede Erwaehnung von dynamic data',
	'ohne "permission" ist es keine Rechtefrage'
);

echo "\n";
if ( 0 === $fail ) {
	echo "\033[32mDynamic Data in Ordnung.\033[0m\n";
	exit( 0 );
}
echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
exit( 1 );
