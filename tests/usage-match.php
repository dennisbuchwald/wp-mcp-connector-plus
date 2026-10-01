<?php
/**
 * Pattern 12 is not pattern 123, and image 1 is not image 12.
 *
 * Both usage lookups searched post content for a prefix: '"ref":12' also
 * matched a page embedding pattern 123, and 'wp-image-1' every page with
 * image 12, 100 or 1999. The dry run of a pattern edit then warned about
 * pages it did not touch, the cache purge after the save purged them, and
 * an image counted as used on pages that never showed it.
 *
 * The pattern count and the list of embedding pages also came from two
 * different queries: one counted other patterns, the other left them out
 * and stopped at 200. The warning and the purge disagreed about the same
 * pattern.
 *
 * Run: php tests/usage-match.php
 *
 * @package wp-mcp-connector-plus
 */

require_once __DIR__ . '/bootstrap.php';

/**
 * Just enough of wpdb to run the usage queries against rows in memory.
 *
 * The LIKE patterns arrive as prepare() arguments; a row matches when any
 * of them matches its content (the queries OR them together), and the
 * usual exclusions apply.
 */
class Usage_Wpdb {
	public $posts    = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $rows     = array();
	public $queries  = 0;
	private $likes   = array();

	public function esc_like( $text ) {
		return addcslashes( $text, '_%\\' );
	}

	public function prepare( $sql, ...$args ) {
		$args        = ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args;
		$this->likes = array_values(
			array_filter(
				$args,
				function ( $a ) {
					return is_string( $a ) && '%' === substr( $a, 0, 1 );
				}
			)
		);
		return $sql;
	}

	private function matching() {
		++$this->queries;
		$out = array();
		foreach ( $this->rows as $row ) {
			if ( in_array( $row->post_type, array( 'revision', 'attachment' ), true ) || 'trash' === $row->post_status ) {
				continue;
			}
			foreach ( $this->likes as $like ) {
				if ( preg_match( $this->like_regex( $like ), $row->post_content ) ) {
					$out[] = $row;
					break;
				}
			}
		}
		return $out;
	}

	private function like_regex( $like ) {
		$regex = '';
		$len   = strlen( $like );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $like[ $i ];
			if ( '\\' === $c && $i + 1 < $len ) {
				$regex .= preg_quote( $like[ ++$i ], '/' );
			} elseif ( '%' === $c ) {
				$regex .= '.*';
			} elseif ( '_' === $c ) {
				$regex .= '.';
			} else {
				$regex .= preg_quote( $c, '/' );
			}
		}
		return '/^' . $regex . '$/s';
	}

	public function get_var( $sql ) {
		return count( $this->matching() );
	}

	public function get_col( $sql ) {
		return array_map(
			function ( $row ) {
				return $row->ID;
			},
			$this->matching()
		);
	}

	public function get_results( $sql ) {
		if ( false !== strpos( $sql, 'postmeta' ) ) {
			return array();
		}
		return $this->matching();
	}
}

$GLOBALS['wpdb'] = new Usage_Wpdb();

function row( $id, $content, $type = 'page' ) {
	return (object) array( 'ID' => $id, 'post_type' => $type, 'post_status' => 'publish', 'post_content' => $content );
}

$GLOBALS['url_calls'] = 0;
function wp_get_attachment_url( $id ) {
	++$GLOBALS['url_calls'];
	return 'https://example.test/wp-content/uploads/2026/09/bild-' . $id . '.jpg';
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

require_once dirname( __DIR__ ) . '/includes/content.php';
require_once dirname( __DIR__ ) . '/includes/media.php';

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

echo "\n\033[1mWo ein synchronisiertes Muster steckt\033[0m\n";

$GLOBALS['wpdb']->rows = array(
	row( 1, '<!-- wp:block {"ref":12} /-->' ),
	row( 2, '<!-- wp:block {"ref":123} /-->' ),
	row( 3, '<!-- wp:block {"ref":12,"content":{"Titel":{"content":"Hallo"}}} /-->' ),
	row( 4, '<!-- wp:block {"ref":1200} /-->' ),
	row( 5, '<!-- wp:block {"ref":12} /-->', 'wp_block' ),
	row( 6, '<!-- wp:block {"ref":12} /-->', 'revision' ),
);

$ids = wpmcp_pattern_usage_ids( 12 );
sort( $ids );
check( array( 1, 3, 5 ) === $ids, 'genau die Seiten mit Muster 12, nicht 123 oder 1200', 'gefunden: ' . implode( ', ', $ids ) );
check( 3 === wpmcp_pattern_usage_count( 12 ), 'die Warnung zaehlt dieselben', 'gezaehlt: ' . wpmcp_pattern_usage_count( 12 ) );
check( count( wpmcp_pattern_usage_ids( 123 ) ) === wpmcp_pattern_usage_count( 123 ), 'Zahl und Liste stimmen auch fuer 123 ueberein' );
check( array( 2 ) === wpmcp_pattern_usage_ids( 123 ), 'und 123 findet nur 123' );

echo "\n\033[1mWo ein Bild steckt\033[0m\n";

$GLOBALS['wpdb']->rows = array(
	row( 10, '<img class="wp-image-1" src="/x.jpg">' ),
	row( 11, '<img class="wp-image-12" src="/y.jpg">' ),
	row( 12, '<img class="alignleft wp-image-1 size-full" src="/z.jpg">' ),
	row( 13, '<figure><img class="wp-image-100"></figure>' ),
	row( 14, '<img src="https://alt.test/wp-content/uploads/2026/09/bild-1.jpg">' ),
);

$GLOBALS['url_calls'] = 0;
$usage = wpmcp_media_usage( array( 1, 12 ) );
$one   = $usage[1];
sort( $one );
check( array( 10, 12, 14 ) === $one, 'Bild 1 steckt in 10, 12 und 14, nicht in 11 oder 13', 'gefunden: ' . implode( ', ', $one ) );
check( array( 11 ) === $usage[12], 'Bild 12 nur in 11', 'gefunden: ' . implode( ', ', $usage[12] ) );
check( $GLOBALS['url_calls'] <= 2, 'die URL wird je Bild einmal bestimmt, nicht je Seite', $GLOBALS['url_calls'] . ' Aufrufe' );

echo "\n";
if ( 0 === $fail ) {
	echo "\033[32mVerwendung in Ordnung.\033[0m\n";
	exit( 0 );
}
echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
exit( 1 );
