<?php
/**
 * Which posts the agent may read and write, decided by the connector.
 *
 * Capabilities answer most of it, but not all: they can come from places
 * the plugin does not control (a second role on the account, a role
 * editor), and some lines have to hold regardless. Published pages stay
 * read-only unless live editing is on, whatever the capabilities say.
 *
 * Run: php tests/content-access.php
 *
 * @package wp-mcp-connector-plus
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['posts'] = array();
$GLOBALS['caps']  = array();
$GLOBALS['live']  = false;

function get_post( $id ) { return $GLOBALS['posts'][ (int) $id ] ?? null; }
// edit_post the way map_meta_cap resolves it for another user's post,
// unless a test sets the answer for one post directly.
function current_user_can( $cap, ...$args ) {
	$caps = $GLOBALS['caps'];
	if ( isset( $args[0] ) && in_array( $cap, array( 'edit_post', 'edit_page' ), true ) ) {
		$id = (int) $args[0];
		if ( isset( $caps[ 'edit_post:' . $id ] ) ) {
			return (bool) $caps[ 'edit_post:' . $id ];
		}
		$post = get_post( $id );
		if ( ! $post ) {
			return false;
		}
		$s = 'page' === $post->post_type ? 'pages' : 'posts';
		$need = array( "edit_others_{$s}" );
		if ( 'publish' === $post->post_status ) {
			$need[] = "edit_published_{$s}";
		}
		if ( 'private' === $post->post_status ) {
			$need[] = "edit_private_{$s}";
		}
		foreach ( $need as $one ) {
			if ( empty( $caps[ $one ] ) ) {
				return false;
			}
		}
		return true;
	}
	return ! empty( $caps[ $cap ] );
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
			'publish_posts'        => "publish_{$s}",
		),
	);
}
function get_the_title( $post ) { return $post->post_title; }
function get_permalink( $post ) { return 'https://example.test/?p=' . $post->ID; }
function esc_sql( $v ) { return addslashes( (string) $v ); }
function remove_filter( $tag, $cb, $priority = 10 ) {
	foreach ( $GLOBALS['dbw_filters'][ $tag ] ?? array() as $i => $one ) {
		if ( $one === $cb ) {
			unset( $GLOBALS['dbw_filters'][ $tag ][ $i ] );
		}
	}
	return true;
}
// has_block() as in wp-includes/blocks.php: a string test on the content.
function has_block( $name, $post ) {
	$content = is_object( $post ) ? $post->post_content : (string) $post;
	if ( false === strpos( $name, '/' ) ) {
		$name = 'core/' . $name;
	}
	$short = 0 === strpos( $name, 'core/' ) ? substr( $name, 5 ) : $name;
	return false !== strpos( $content, '<!-- wp:' . $short . ' ' ) || false !== strpos( $content, '<!-- wp:' . $name . ' ' );
}

/*
 * The posts table lives in SQLite, so the WHERE clause the plugin builds
 * runs as real SQL rather than being compared as a string.
 */
if ( ! class_exists( 'PDO' ) || ! in_array( 'sqlite', PDO::getAvailableDrivers(), true ) ) {
	fwrite( STDERR, "content-access.php needs the pdo_sqlite extension.\n" );
	exit( 1 );
}
$GLOBALS['pdo'] = new PDO( 'sqlite::memory:' );
$GLOBALS['pdo']->exec( 'CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_type TEXT, post_status TEXT, post_password TEXT, post_content TEXT, post_title TEXT, post_modified_gmt TEXT)' );

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
};

// WP_Query as far as the list needs it: the same base query WordPress
// builds, the posts_where filter, paging and found_posts.
class WP_Query {
	public $posts         = array();
	public $found_posts   = 0;
	public $max_num_pages = 0;
	public $query_vars    = array();
	public function query( $args ) {
		$this->query_vars = $args;
		$pdo   = $GLOBALS['pdo'];
		$q     = function ( $v ) use ( $pdo ) { return $pdo->quote( (string) $v ); };
		$where = ' AND wp_posts.post_type IN (' . implode( ',', array_map( $q, (array) $args['post_type'] ) ) . ')'
			. ' AND wp_posts.post_status IN (' . implode( ',', array_map( $q, (array) $args['post_status'] ) ) . ')';
		foreach ( $GLOBALS['dbw_filters']['posts_where'] ?? array() as $cb ) {
			$where = $cb( $where, $this );
		}
		$GLOBALS['last_where'] = $where;
		$this->found_posts   = (int) $pdo->query( 'SELECT COUNT(*) FROM wp_posts WHERE 1=1' . $where )->fetchColumn();
		$per                 = (int) $args['posts_per_page'];
		$this->max_num_pages = (int) ceil( $this->found_posts / $per );
		$offset              = ( (int) $args['paged'] - 1 ) * $per;
		$ids                 = $pdo->query( 'SELECT ID FROM wp_posts WHERE 1=1' . $where . " ORDER BY post_modified_gmt DESC, ID DESC LIMIT {$per} OFFSET {$offset}" )->fetchAll( PDO::FETCH_COLUMN );
		$this->posts         = array_map( 'get_post', $ids );
		return $this->posts;
	}
}

function get_post_types( $args = array(), $output = 'names' ) {
	$types = array(
		'page' => (object) array( 'name' => 'page', 'public' => true ),
		'post' => (object) array( 'name' => 'post', 'public' => true ),
	);
	return 'names' === $output ? array_keys( $types ) : $types;
}
function post_type_exists( $type ) { return in_array( $type, array( 'page', 'post' ), true ); }
function wpmcp_pattern_access() { return 'read'; }
function wpmcp_extra_post_types() { return array(); }
function wpmcp_live_edit_enabled() { return $GLOBALS['live']; }
function wpmcp_privacy_policy_page_id() { return 0; }
function wpmcp_log( $ability, $data = array() ) {}
function wpmcp_work_session_active() { return false; }

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

function post( $id, $status, array $extra = array() ) {
	$post = $GLOBALS['posts'][ $id ] = (object) array_merge(
		array(
			'ID'            => $id,
			'post_type'     => 'page',
			'post_status'   => $status,
			'post_password' => '',
			'post_content'  => '',
			'post_title'    => 'Seite ' . $id,
			'post_name'     => 'seite-' . $id,
			'post_parent'   => 0,
			'post_modified_gmt' => '2026-10-01 10:00:00',
		),
		$extra
	);
	$GLOBALS['pdo']->prepare( 'INSERT OR REPLACE INTO wp_posts VALUES (?, ?, ?, ?, ?, ?, ?)' )->execute(
		array( $id, $post->post_type, $post->post_status, $post->post_password, $post->post_content, $post->post_title, $post->post_modified_gmt )
	);
}

echo "\n\033[1mVeroeffentlichtes bleibt ohne Live-Edit gesperrt\033[0m\n";

post( 10, 'publish' );
post( 11, 'draft' );

// The capabilities say yes, for instance through a second role.
$GLOBALS['caps'] = array( 'edit_post:10' => true, 'edit_post:11' => true );

$GLOBALS['live'] = false;
$out = wpmcp_get_writable_post( 10 );
check(
	is_wp_error( $out ) && 'wpmcp_live_edit_disabled' === $out->get_error_code(),
	'eine veroeffentlichte Seite ist ohne Live-Edit nicht schreibbar, auch wenn die Rechte es erlauben',
	is_wp_error( $out ) ? $out->get_error_code() : 'schreibbar'
);
check( ! is_wp_error( wpmcp_get_writable_post( 11 ) ), 'ein Entwurf schon' );

$GLOBALS['live'] = true;
check( ! is_wp_error( wpmcp_get_writable_post( 10 ) ), 'mit Live-Edit auch die veroeffentlichte Seite' );

$GLOBALS['caps'] = array();
$out = wpmcp_get_writable_post( 10 );
check( is_wp_error( $out ) && 'wpmcp_forbidden' === $out->get_error_code(), 'ohne Recht bleibt es beim Nein' );
$GLOBALS['live'] = false;

echo "\n\033[1mPasswortgeschuetzte Seiten sind nicht oeffentlich\033[0m\n";

post( 20, 'publish', array( 'post_password' => 'geheim' ) );
$draft_level = array( 'edit_pages' => true, 'edit_others_pages' => true, 'edit_posts' => true, 'edit_others_posts' => true );
$full_level  = $draft_level + array( 'edit_published_pages' => true, 'edit_published_posts' => true );

$GLOBALS['caps'] = array();
$out = wpmcp_get_readable_post( 20 );
check(
	is_wp_error( $out ) && 'wpmcp_password_protected' === $out->get_error_code(),
	'ohne Bearbeitungsrecht ist ihr Inhalt nicht lesbar',
	'bis 0.18 galt "veroeffentlicht" als oeffentlich, Passwort hin oder her'
);
check( ! is_wp_error( wpmcp_get_readable_post( 10 ) ), 'eine gewoehnliche veroeffentlichte Seite schon' );

$GLOBALS['caps'] = $draft_level;
check( is_wp_error( wpmcp_get_readable_post( 20 ) ), 'auch auf der Entwurfsstufe nicht', 'dort fehlt edit_published_pages' );

$GLOBALS['caps'] = $full_level;
check( ! is_wp_error( wpmcp_get_readable_post( 20 ) ), 'wer sie bearbeiten darf, liest sie' );

echo "\n\033[1mcontent-list zeigt nur, was gelesen werden darf\033[0m\n";

$GLOBALS['posts'] = array();
$GLOBALS['pdo']->exec( 'DELETE FROM wp_posts' );

$p  = '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->';
$gp = '<!-- wp:group --><div><!-- wp:paragraph {"align":"center"} --><p>x</p><!-- /wp:paragraph --></div><!-- /wp:group -->';

post( 1, 'publish', array( 'post_content' => $p ) );
post( 2, 'draft', array( 'post_content' => $gp ) );
post( 3, 'private' );
post( 4, 'publish', array( 'post_password' => 'geheim' ) );
post( 5, 'trash' );
post( 6, 'pending' );
post( 7, 'draft', array( 'post_type' => 'shop_order' ) );
post( 8, 'auto-draft' );
post( 9, 'inherit', array( 'post_type' => 'revision' ) );

function listed( array $args ) {
	$out = wpmcp_list_content( $args );
	if ( is_wp_error( $out ) ) {
		return $out;
	}
	$ids = array_column( $out['items'], 'id' );
	sort( $ids );
	return $ids;
}

$GLOBALS['caps'] = array();
check( array( 1 ) === listed( array() ), 'Lesestufe: nur Veroeffentlichtes ohne Passwort', implode( ', ', (array) listed( array() ) ) );

$GLOBALS['caps'] = $draft_level;
$ids = listed( array() );
check( array( 1, 2, 6 ) === $ids, 'Entwurfsstufe: dazu Entwuerfe und Ausstehendes', implode( ', ', (array) $ids ) );
check( ! in_array( 3, (array) $ids, true ), 'nichts Privates ohne edit_private_*' );
check( ! in_array( 4, (array) $ids, true ), 'keine passwortgeschuetzte Seite ohne edit_published_*' );
check( ! in_array( 5, (array) $ids, true ), 'nichts aus dem Papierkorb' );
check( ! in_array( 7, (array) $ids, true ), 'keine Bestellung: der Post-Type ist nicht freigegeben' );

$GLOBALS['caps'] = $full_level;
check( array( 1, 2, 4, 6 ) === listed( array() ), 'Vollstufe: auch die passwortgeschuetzte Seite' );

$GLOBALS['caps'] = $draft_level;

foreach ( array( 'trash', 'inherit', 'auto-draft', 'publish,trash', 'any,trash' ) as $status ) {
	$out = wpmcp_list_content( array( 'status' => $status ) );
	check( is_wp_error( $out ) && 'wpmcp_bad_status' === $out->get_error_code(), "Status \"{$status}\" wird abgelehnt", 'bis 0.18 ging er unveraendert an WP_Query' );
}
check( array( 1, 2, 6 ) === listed( array( 'status' => 'any' ) ), 'Status "any" (0.20.1): alles Lesbare, wie ohne Status', wp_json_encode( listed( array( 'status' => 'any' ) ) ) );
check( array( 1, 2, 6 ) === listed( array( 'status' => array( 'any', 'draft' ) ) ), '"any" in einer Liste ebenso, ohne Doppelte', wp_json_encode( listed( array( 'status' => array( 'any', 'draft' ) ) ) ) );
$GLOBALS['caps'] = array();
check( array( 1 ) === listed( array( 'status' => 'any' ) ), '"any" auf der Lesestufe: nur Veroeffentlichtes, kein Entwurf', wp_json_encode( listed( array( 'status' => 'any' ) ) ) );
$GLOBALS['caps'] = $full_level;
check( array( 1, 2, 4, 6 ) === listed( array( 'status' => 'any' ) ), '"any" auf der Vollstufe: nie Papierkorb, auto-draft oder inherit', wp_json_encode( listed( array( 'status' => 'any' ) ) ) );
$GLOBALS['caps'] = $draft_level;
check( array( 2 ) === listed( array( 'status' => 'draft' ) ), 'Status "draft" liefert nur Entwuerfe' );
check( array( 1, 2 ) === listed( array( 'status' => 'publish,draft' ) ), 'mehrere Status mit Komma' );
check( array() === listed( array( 'status' => 'private' ) ), 'nach "private" gefragt: leer statt fremder Inhalte' );

$out = wpmcp_list_content( array( 'post_type' => 'shop_order' ) );
check( is_wp_error( $out ) && 'wpmcp_forbidden_type' === $out->get_error_code(), 'ein nicht freigegebener Post-Type wird abgelehnt' );
$out = wpmcp_list_content( array( 'post_type' => 'revision' ) );
check( is_wp_error( $out ), 'Revisionen ebenso' );
check( array( 1, 2, 6 ) === listed( array( 'post_type' => 'page,shop_order' ) ), 'gemischt: nur der freigegebene Teil' );

echo "\n\033[1muses_block filtert vor dem Blaettern\033[0m\n";

check( array( 1, 2 ) === listed( array( 'uses_block' => 'core/paragraph' ) ), 'findet den Block, auch verschachtelt' );
check( array( 1, 2 ) === listed( array( 'uses_block' => 'paragraph' ) ), 'auch ohne Namensraum' );

$GLOBALS['posts'] = array();
$GLOBALS['pdo']->exec( 'DELETE FROM wp_posts' );
for ( $i = 100; $i < 130; $i++ ) {
	$hero = 0 === $i % 2;
	post(
		$i,
		'publish',
		array(
			'post_type'         => 'post',
			'post_content'      => $hero ? '<!-- wp:dbw-base/hero {"heading":"H"} /-->' : '<!-- wp:dbw-base/hero-slider /-->',
			'post_modified_gmt' => sprintf( '2026-09-%02d 10:00:00', $i - 99 ),
		)
	);
}

$out = wpmcp_list_content( array( 'uses_block' => 'dbw-base/hero', 'per_page' => 5 ) );
check( 5 === count( $out['items'] ), 'eine volle Seite mit 5 Treffern', count( $out['items'] ) . ' Eintraege; bis 0.18 wurde erst geblaettert, dann gefiltert' );
check( 15 === $out['total'], 'total zaehlt nur die Treffer', 'total: ' . $out['total'] );
check( 3 === $out['pages'], 'und die Seitenzahl passt', 'pages: ' . $out['pages'] );
check(
	array() === array_filter( $out['items'], function ( $item ) { return 0 !== $item['id'] % 2; } ),
	'hero-slider zaehlt nicht als hero',
	'der Name endet erst am Leerzeichen'
);
$last = wpmcp_list_content( array( 'uses_block' => 'dbw-base/hero', 'per_page' => 5, 'page' => 3 ) );
check( 5 === count( $last['items'] ), 'auch die letzte Seite ist voll' );

echo "\n\033[1mBlockzahl ohne Parser\033[0m\n";

// The list counted blocks by parsing every page on it: up to 100 full
// parses for one number per row. Counting the opening comments gives the
// same number for block content, nested blocks included.
$nested = '<!-- wp:group --><div><!-- wp:paragraph --><p>a</p><!-- /wp:paragraph --><!-- wp:image {"id":5} /--></div><!-- /wp:group -->';
check( 3 === wpmcp_count_blocks_in_markup( $nested ), 'zaehlt verschachtelte und selbstschliessende Bloecke, keine schliessenden Kommentare', (string) wpmcp_count_blocks_in_markup( $nested ) );
check( wpmcp_count_blocks( parse_blocks( $nested ) ) === wpmcp_count_blocks_in_markup( $nested ), 'gleiche Zahl wie ueber den Parser' );
check( 0 === wpmcp_count_blocks_in_markup( '<p>klassischer Inhalt</p>' ), 'klassischer Inhalt ohne Blockkommentare zaehlt 0' );
check( 0 === wpmcp_count_blocks_in_markup( '' ), 'leerer Inhalt zaehlt 0' );

$GLOBALS['posts'] = array();
$GLOBALS['pdo']->exec( 'DELETE FROM wp_posts' );
post( 200, 'publish', array( 'post_content' => $nested ) );
$GLOBALS['dbw_parse_blocks_calls'] = 0;
$out = wpmcp_list_content( array() );
check( 3 === ( $out['items'][0]['blocks'] ?? null ), 'content-list meldet 3 Bloecke', wp_json_encode( $out['items'][0]['blocks'] ?? null ) );
check( 0 === $GLOBALS['dbw_parse_blocks_calls'], 'ohne eine Seite zu parsen', $GLOBALS['dbw_parse_blocks_calls'] . ' Aufrufe von parse_blocks' );

$GLOBALS['caps'] = array();
$out = wpmcp_get_writable_post( 10 );

echo "\n";
if ( 0 === $fail ) {
	echo "\033[32mZugriff auf Inhalte in Ordnung.\033[0m\n";
	exit( 0 );
}
echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
exit( 1 );
