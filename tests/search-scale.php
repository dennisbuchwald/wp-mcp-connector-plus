<?php
/**
 * content-search on a site with many pages: what it loads and what it may see.
 *
 * The search loaded every post of every searchable type in one call
 * (posts_per_page -1), content included, then searched them in PHP. On a
 * site with a few thousand posts that is the whole content table in
 * memory for every search. And post_status went to the query unchecked,
 * so "trash" or "any" found text in posts no other tool shows.
 *
 * The posts table lives in SQLite, so the WHERE clause runs as real SQL.
 *
 * Run: php tests/search-scale.php
 *
 * @package wp-mcp-connector-plus
 */

require_once __DIR__ . '/bootstrap.php';

if ( ! class_exists( 'PDO' ) || ! in_array( 'sqlite', PDO::getAvailableDrivers(), true ) ) {
	fwrite( STDERR, "search-scale.php needs the pdo_sqlite extension.\n" );
	exit( 1 );
}

$GLOBALS['posts']   = array();
$GLOBALS['caps']    = array();
$GLOBALS['loads']   = array();
$GLOBALS['sql']     = array();

$GLOBALS['pdo'] = new PDO( 'sqlite::memory:' );
$GLOBALS['pdo']->exec( 'CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_type TEXT, post_status TEXT, post_password TEXT, post_content TEXT)' );

$GLOBALS['wpdb'] = new class() {
	public $posts = 'wp_posts';
	public function prepare( $query, ...$args ) {
		$args = isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : $args;
		return preg_replace_callback(
			'/%[sd]/',
			function ( $m ) use ( &$args ) {
				$value = array_shift( $args );
				return '%d' === $m[0] ? (string) (int) $value : $GLOBALS['pdo']->quote( (string) $value );
			},
			$query
		);
	}
	public function esc_like( $text ) { return addcslashes( $text, '_%\\' ); }
	public function get_col( $sql ) {
		$GLOBALS['sql'][] = $sql;
		return $GLOBALS['pdo']->query( $sql )->fetchAll( PDO::FETCH_COLUMN );
	}
};

function get_post( $id ) { return $GLOBALS['posts'][ (int) $id ] ?? null; }
// Loads only what is asked for by ID, and records each load.
function get_posts( $args = array() ) {
	if ( empty( $args['post__in'] ) || ( $args['posts_per_page'] ?? 0 ) < 0 ) {
		throw new RuntimeException( 'unbounded get_posts: ' . wp_json_encode( $args ) );
	}
	$GLOBALS['loads'][] = $args['post__in'];
	return array_values( array_filter( array_map( 'get_post', $args['post__in'] ) ) );
}
function current_user_can( $cap, ...$args ) {
	if ( isset( $args[0] ) && 'edit_post' === $cap ) {
		return ! empty( $GLOBALS['caps']['edit_others_pages'] );
	}
	return ! empty( $GLOBALS['caps'][ $cap ] );
}
function get_post_type_object( $type ) {
	if ( ! in_array( $type, array( 'page', 'post' ), true ) ) {
		return null;
	}
	$s = 'page' === $type ? 'pages' : 'posts';
	return (object) array(
		'name' => $type,
		'cap'  => (object) array(
			'edit_posts'           => "edit_{$s}",
			'edit_others_posts'    => "edit_others_{$s}",
			'edit_private_posts'   => "edit_private_{$s}",
			'edit_published_posts' => "edit_published_{$s}",
		),
	);
}
function get_post_types( $args = array(), $output = 'names' ) {
	$types = array(
		'page' => (object) array( 'name' => 'page', 'public' => true ),
		'post' => (object) array( 'name' => 'post', 'public' => true ),
	);
	return 'names' === $output ? array_keys( $types ) : $types;
}
function post_type_exists( $type ) { return in_array( $type, array( 'page', 'post' ), true ); }
function get_the_title( $post ) { $GLOBALS['titles'][] = $post->ID; return 'Seite ' . $post->ID; }
function get_permalink( $post ) { $GLOBALS['links'][] = $post->ID; return 'https://example.test/?p=' . $post->ID; }
function esc_sql( $v ) { return addslashes( (string) $v ); }
function wpmcp_pattern_access() { return 'read'; }
function wpmcp_extra_post_types() { return array(); }
function wpmcp_live_edit_enabled() { return false; }
function wpmcp_privacy_policy_page_id() { return 0; }
function wpmcp_log( $ability, $data = array() ) {}
function wpmcp_work_session_active() { return false; }

require_once __DIR__ . '/kses-stub.php';
require_once dirname( __DIR__ ) . '/includes/content.php';
require_once dirname( __DIR__ ) . '/includes/search.php';

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

function post( $id, $status, $content, array $extra = array() ) {
	$post = $GLOBALS['posts'][ $id ] = (object) array_merge(
		array(
			'ID'            => $id,
			'post_type'     => 'page',
			'post_status'   => $status,
			'post_password' => '',
			'post_content'  => $content,
		),
		$extra
	);
	$GLOBALS['pdo']->prepare( 'INSERT OR REPLACE INTO wp_posts VALUES (?, ?, ?, ?, ?)' )->execute(
		array( $id, $post->post_type, $post->post_status, $post->post_password, $post->post_content )
	);
}

function reset_posts() {
	$GLOBALS['posts'] = array();
	$GLOBALS['pdo']->exec( 'DELETE FROM wp_posts' );
}

function search( array $args ) {
	$GLOBALS['loads']  = array();
	$GLOBALS['sql']    = array();
	$GLOBALS['titles'] = array();
	$GLOBALS['links']  = array();
	try {
		return wpmcp_search_content( $args );
	} catch ( RuntimeException $e ) {
		return new WP_Error( 'test', $e->getMessage() );
	}
}

function hit_ids( $out ) {
	return is_wp_error( $out ) ? $out->get_error_message() : array_values( array_unique( array_column( $out['matches'], 'postId' ) ) );
}

$para = function ( $text ) {
	return '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->';
};

echo "\n\033[1mNur passende Seiten werden geladen, in Portionen\033[0m\n";

reset_posts();
for ( $i = 1; $i <= 300; $i++ ) {
	post( $i, 'publish', $para( 0 === $i % 3 ? 'Ruf an: tel:+4971313859840 oder tel:+4971313859840' : 'nichts' ) );
}

$out = search( array( 'query' => 'tel:+4971313859840', 'limit' => 500 ) );
check( ! is_wp_error( $out ) && 200 === count( $out['matches'] ), 'alle 200 Treffer gefunden', is_wp_error( $out ) ? $out->get_error_message() : count( $out['matches'] ) . ' Treffer' );
$loaded = array_merge( ...( $GLOBALS['loads'] ?: array( array() ) ) );
check( 100 === count( $loaded ), 'geladen werden nur die 100 Seiten, die den Text enthalten', count( $loaded ) . ' geladen; vorher alle 300' );
check( array() === array_filter( $GLOBALS['loads'], function ( $batch ) { return count( $batch ) > 50; } ), 'in Portionen von hoechstens 50' );
check( false !== strpos( implode( ' ', $GLOBALS['sql'] ), 'LIKE' ), 'die Datenbank filtert vor (LIKE)' );
check( 100 === count( $GLOBALS['links'] ) && 100 === count( $GLOBALS['titles'] ), 'Titel und Permalink einmal je Seite, nicht je Treffer', count( $GLOBALS['links'] ) . ' Permalinks fuer 200 Treffer' );

$out = search( array( 'query' => 'tel:+4971313859840', 'limit' => 5 ) );
check( ! is_wp_error( $out ) && 5 === count( $out['matches'] ) && true === $out['truncated'], 'bei limit 5: abgeschnitten' );
check( 1 === count( $GLOBALS['loads'] ), 'und nur eine Portion geladen, sobald das Limit erreicht ist', count( $GLOBALS['loads'] ) . ' Portionen' );
check( ! is_wp_error( $out ) && 5 === ( $out['nextOffset'] ?? null ), 'nextOffset bleibt der Treffer-Offset' );

$out = search( array( 'query' => 'tel:+4971313859840', 'limit' => 5, 'offset' => 195 ) );
check( ! is_wp_error( $out ) && 5 === count( $out['matches'] ) && false === $out['truncated'] && ! isset( $out['nextOffset'] ), 'die letzte Seite ab offset 195' );
check( ! is_wp_error( $out ) && 300 === $out['matches'][4]['postId'], 'endet beim letzten Treffer', wp_json_encode( $out['matches'][4]['postId'] ?? null ) );

$out = search( array( 'query' => 'tel:+4971313859840', 'limit' => 1 ) );
check( ! is_wp_error( $out ) && isset( $out['scanned'] ) && $out['scanned'] <= 50, 'die Antwort nennt, wie viele Seiten durchsucht wurden', wp_json_encode( $out['scanned'] ?? null ) );

echo "\n\033[1mVorfilter findet, was der Attribut-Vergleich findet\033[0m\n";

// The attributes are searched as re-encoded JSON, the database holds
// them as stored. Characters the serializer escapes must not let the
// prefilter drop a page the search itself would have found.
reset_posts();
post( 1, 'publish', '<!-- wp:acme/link {"label":"a <b> Preis","url":"https:\/\/example.test\/team"} /-->' );
post( 2, 'publish', '<!-- wp:acme/team {"name":"Jörg Müller"} /-->' );
post( 3, 'publish', '<!-- wp:acme/note {"text":"a -- b"} /-->' );
check( array( 1 ) === hit_ids( search( array( 'query' => 'a <b> Preis' ) ) ), '"<" in einem Attribut (gespeichert als <)', wp_json_encode( hit_ids( search( array( 'query' => 'a <b> Preis' ) ) ) ) );
check( array( 1 ) === hit_ids( search( array( 'query' => 'https://example.test/team' ) ) ), 'eine URL, auch wenn die Schraegstriche escaped gespeichert sind' );
check( array( 2 ) === hit_ids( search( array( 'query' => 'Jörg Müller' ) ) ), 'Umlaute, auch wenn sie als ü gespeichert sind' );
check( array( 3 ) === hit_ids( search( array( 'query' => 'a -- b' ) ) ), 'ein doppelter Bindestrich' );

echo "\n\033[1mNur, was gelesen werden darf\033[0m\n";

reset_posts();
post( 1, 'publish', $para( 'geheim-wort' ) );
post( 2, 'draft', $para( 'geheim-wort' ) );
post( 3, 'trash', $para( 'geheim-wort' ) );
post( 4, 'publish', $para( 'geheim-wort' ), array( 'post_password' => 'pw' ) );
post( 5, 'private', $para( 'geheim-wort' ) );
post( 6, 'inherit', $para( 'geheim-wort' ), array( 'post_type' => 'revision' ) );

$GLOBALS['caps'] = array();
check( array( 1 ) === hit_ids( search( array( 'query' => 'geheim-wort' ) ) ), 'ohne Rechte: nur Oeffentliches', wp_json_encode( hit_ids( search( array( 'query' => 'geheim-wort' ) ) ) ) );

$GLOBALS['caps'] = array( 'edit_pages' => true, 'edit_others_pages' => true, 'edit_published_pages' => true, 'edit_private_pages' => true );
check( array( 1, 2, 4, 5 ) === hit_ids( search( array( 'query' => 'geheim-wort' ) ) ), 'mit Bearbeitungsrechten auch Entwurf, Passwortseite und Privates, aber kein Papierkorb', wp_json_encode( hit_ids( search( array( 'query' => 'geheim-wort' ) ) ) ) );

foreach ( array( 'trash', 'any', 'inherit', 'auto-draft' ) as $status ) {
	$out = search( array( 'query' => 'geheim-wort', 'post_status' => $status ) );
	check( is_wp_error( $out ) && 'wpmcp_bad_status' === $out->get_error_code(), "post_status \"{$status}\" wird abgelehnt", is_wp_error( $out ) ? $out->get_error_code() : wp_json_encode( hit_ids( $out ) ) );
}
check( array( 2 ) === hit_ids( search( array( 'query' => 'geheim-wort', 'post_status' => 'draft' ) ) ), 'post_status "draft" grenzt ein' );
check( array( 1, 4 ) === hit_ids( search( array( 'query' => 'geheim-wort', 'post_status' => 'publish' ) ) ), 'post_status als Text' );

$out = search( array( 'query' => 'geheim-wort', 'post_type' => 'revision' ) );
check( is_wp_error( $out ) && 'wpmcp_forbidden_type' === $out->get_error_code(), 'ein Post-Type ausserhalb des Bereichs wird abgelehnt', is_wp_error( $out ) ? $out->get_error_code() : wp_json_encode( hit_ids( $out ) ) );
$out = search( array( 'query' => 'geheim-wort', 'post_types' => array( 'page', 'shop_order' ) ) );
check( ! is_wp_error( $out ) && array( 1, 2, 4, 5 ) === hit_ids( $out ), 'eine Liste bleibt auf den Bereich des Connectors begrenzt' );

post( 7, 'publish', $para( 'geheim-wort' ), array( 'post_type' => 'post' ) );
check( array( 1, 2, 4, 5 ) === hit_ids( search( array( 'query' => 'geheim-wort', 'post_type' => 'page' ) ) ), 'post_type als Text grenzt ein' );
check( array( 7 ) === hit_ids( search( array( 'query' => 'geheim-wort', 'post_types' => 'post' ) ) ), 'post_types als Text ebenso' );

echo "\n\033[1mRegulaere Ausdruecke\033[0m\n";

reset_posts();
$GLOBALS['caps'] = array();
for ( $i = 1; $i <= 120; $i++ ) {
	post( $i, 'publish', $para( 0 === $i % 40 ? 'Tel 07131 3859840' : 'nichts' ) );
}
$out = search( array( 'query' => '07\d{3}\s?\d{6,7}', 'regex' => true ) );
check( ! is_wp_error( $out ) && 3 === count( $out['matches'] ), 'findet ueber alle Seiten', is_wp_error( $out ) ? $out->get_error_message() : count( $out['matches'] ) . ' Treffer' );
check( array() === array_filter( $GLOBALS['loads'], function ( $batch ) { return count( $batch ) > 50; } ), 'auch hier in Portionen von hoechstens 50' );
check( ! is_wp_error( $out ) && 120 === $out['scanned'], 'und sagt, dass 120 Seiten durchsucht wurden', wp_json_encode( $out['scanned'] ?? null ) );

$out = search( array( 'query' => str_repeat( 'a', 201 ), 'regex' => true ) );
check( is_wp_error( $out ) && 'wpmcp_bad_regex' === $out->get_error_code(), 'ein Ausdruck ueber 200 Zeichen wird abgelehnt' );
check( is_wp_error( $out ) && array() === $GLOBALS['sql'], 'bevor irgendetwas abgefragt wird' );

$out = search( array( 'query' => '(unclosed', 'regex' => true ) );
check( is_wp_error( $out ) && 'wpmcp_bad_regex' === $out->get_error_code(), 'ein kaputter Ausdruck ebenso' );

// Catastrophic backtracking: preg_match_all() returns false. That used to
// read as "no hits", a confident zero that was really an error.
post( 121, 'publish', $para( str_repeat( 'a', 5000 ) . '!' ) );
ini_set( 'pcre.backtrack_limit', '1000' );
$out = search( array( 'query' => '(a|aa)+$', 'regex' => true ) );
ini_restore( 'pcre.backtrack_limit' );
check( is_wp_error( $out ) && 'wpmcp_bad_regex' === $out->get_error_code(), 'ein Ausdruck, der an einer Seite scheitert, meldet das statt 0 Treffer', is_wp_error( $out ) ? $out->get_error_code() : wp_json_encode( $out['totalMatches'] ) );
check( is_wp_error( $out ) && false !== strpos( $out->get_error_message(), '121' ), 'und nennt die Seite' );

echo "\n\033[1mObergrenze fuer eine Suche\033[0m\n";

add_filter(
	'wpmcp_search_limits',
	function ( $limits ) {
		$limits['posts'] = 60;
		return $limits;
	}
);
$out = search( array( 'query' => 'nichts-da', 'regex' => true ) );
check( ! is_wp_error( $out ) && 60 === $out['scanned'], 'hoechstens so viele Seiten wie die Grenze', wp_json_encode( $out['scanned'] ?? null ) );
check( ! is_wp_error( $out ) && true === ( $out['scanLimitReached'] ?? null ) && true === $out['truncated'], 'mit scanLimitReached und truncated' );
check( ! is_wp_error( $out ) && ! isset( $out['nextOffset'] ), 'ohne nextOffset, das nicht weiterfuehren wuerde' );
check( ! is_wp_error( $out ) && false !== strpos( $out['hint'] ?? '', 'post_type' ), 'aber mit einem Hinweis, wie es weitergeht' );

echo "\n";
if ( 0 === $fail ) {
	echo "\033[32mSuche im Grossen in Ordnung.\033[0m\n";
	exit( 0 );
}
echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
exit( 1 );
