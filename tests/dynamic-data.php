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

function is_multisite() {
	return false;
}
$GLOBALS['removed'] = array();
function remove_filter( $tag, $cb, $priority = 10 ) {
	$GLOBALS['removed'][] = $tag;
	return true;
}
function get_current_user_id() {
	return 9;
}
function wp_update_post( $postarr, $error = false ) {
	return $postarr['ID'] ?? 1;
}

require_once __DIR__ . '/kses-stub.php';

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

echo "\n\033[1mWenn die Capability gar nicht erreichbar ist\033[0m\n";

// unfiltered_html is a meta capability. A site can turn it into
// do_not_allow from wp-config, and then granting it changes nothing —
// the save would go ahead and fail with the block library's message,
// which explains none of this.
check( null === wpmcp_unfiltered_html_blocker(), 'normal ist sie erreichbar' );

define( 'DISALLOW_UNFILTERED_HTML', true );
$blocker = wpmcp_unfiltered_html_blocker();

check( null !== $blocker, 'mit DISALLOW_UNFILTERED_HTML nicht mehr' );
check( false !== strpos( (string) $blocker, 'wp-config.php' ), 'die Meldung nennt, wo es steht' );
check( false !== strpos( (string) $blocker, 'do_not_allow' ), 'und was WordPress daraus macht' );
check(
	false !== strpos( (string) $blocker, 'on purpose' ),
	'und dass das eine Entscheidung der Seite ist',
	'daran vorbeizuarbeiten waere genau das Falsche'
);

$result = wpmcp_update_post_elevated( array( 'ID' => 1, 'post_content' => 'x' ) );
check( is_wp_error( $result ), 'ein Save wird dann gar nicht erst versucht' );
check(
	is_wp_error( $result ) && 'wpmcp_unfiltered_html_unavailable' === $result->get_error_code(),
	'mit eigenem Fehlercode statt der Meldung der Block-Bibliothek'
);
check(
	in_array( 'user_has_cap', $GLOBALS['removed'], true ),
	'und die Berechtigung wird auch auf diesem Weg wieder entzogen',
	'das finally muss auch bei einem fruehen return greifen'
);

echo "\n";
if ( 0 === $fail ) {
	echo "\033[32mDynamic Data in Ordnung.\033[0m\n";
	exit( 0 );
}
echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
exit( 1 );
