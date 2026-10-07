<?php
/**
 * The page caches a write clears, SiteGround Speed Optimizer among them.
 *
 * On staging.maxport.ch (SiteGround) every save stayed invisible until
 * the cache was cleared by hand over FTP: the write report said a page
 * cache was there, and nothing cleared it. Speed Optimizer (sg-cachepress)
 * has a purge API, and the same call clears SiteGround's server-level
 * dynamic cache. The names below are those of sg-cachepress 7.8.4
 * (core/Supercacher/Supercacher.php, helpers/helpers.php); the real
 * plugin runs in tests/wp-real/siteground.
 *
 * Run: php tests/page-caches.php            (the plugin's class is there)
 *      php tests/page-caches.php helper     (only its helper function)
 *      php tests/page-caches.php everything (only the full purge)
 *
 * @package wp-mcp-connector-plus
 */

namespace SiteGround_Optimizer\Supercacher {
	if ( 'class' === ( $GLOBALS['argv'][1] ?? 'class' ) ) {
		/**
		 * Stand-in for the plugin's Supercacher, recording each call.
		 */
		class Supercacher {
			public static function purge_cache_request( $url, $include_child_paths = true ) {
				$GLOBALS['sg_calls'][] = array( 'class', $url, $include_child_paths );
				return $GLOBALS['sg_result'] ?? true;
			}
		}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	$GLOBALS['sg_calls'] = array();
	$mode                = $GLOBALS['argv'][1] ?? 'class';

	if ( 'helper' === $mode ) {
		function sg_cachepress_purge_cache( $url = false ) {
			$GLOBALS['sg_calls'][] = array( 'helper', $url );
			return true;
		}
	}
	if ( 'helper' === $mode || 'everything' === $mode ) {
		function sg_cachepress_purge_everything() {
			$GLOBALS['sg_calls'][] = array( 'everything' );
		}
	}

	$GLOBALS['posts'] = array(
		7  => (object) array( 'ID' => 7, 'post_status' => 'publish', 'post_name' => 'kurse', 'post_parent' => 0 ),
		12 => (object) array( 'ID' => 12, 'post_status' => 'publish', 'post_name' => 'zuerich', 'post_parent' => 7 ),
		13 => (object) array( 'ID' => 13, 'post_status' => 'draft', 'post_name' => '', 'post_parent' => 7 ),
	);

	function get_post( $id ) { return $GLOBALS['posts'][ (int) ( is_object( $id ) ? $id->ID : $id ) ] ?? null; }
	function get_post_status( $id ) { return get_post( $id )->post_status ?? false; }
	function get_post_ancestors( $post ) {
		$out = array();
		$p   = get_post( $post );
		while ( $p && $p->post_parent ) {
			$out[] = $p->post_parent;
			$p     = get_post( $p->post_parent );
		}
		return $out;
	}
	function get_permalink( $post ) {
		$p = get_post( $post );
		if ( 'publish' !== $p->post_status ) {
			return 'https://example.test/?page_id=' . $p->ID;
		}
		$path = array( $p->post_name );
		foreach ( get_post_ancestors( $p ) as $a ) {
			array_unshift( $path, get_post( $a )->post_name );
		}
		return 'https://example.test/' . implode( '/', $path ) . '/';
	}
	function home_url( $path = '' ) { return 'https://example.test' . $path; }
	function get_home_url( $blog = null, $path = '' ) { return home_url( $path ); }
	function clean_post_cache( $id ) {}
	function wp_using_ext_object_cache() { return false; }
	function wp_cache_flush() { return true; }
	function do_action( ...$a ) {}
	function has_action( ...$a ) { return false; }

	require_once dirname( __DIR__ ) . '/includes/cache.php';

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

	$notes = function ( $r ) {
		return implode( ' ', $r['notes'] ?? array() );
	};

	if ( 'class' === $mode ) {
		echo "\n\033[1mSiteGround: die Seite, ihre Eltern und die Startseite\033[0m\n";

		$r = wpmcp_purge_caches( 12 );
		check( 'purged' === $r['page'], 'page: purged', json_encode( $r ) );
		check(
			array(
				array( 'class', 'https://example.test/kurse/zuerich/', true ),
				array( 'class', 'https://example.test/kurse/', true ),
				array( 'class', 'https://example.test/', false ),
			) === $GLOBALS['sg_calls'],
			'die URL der Seite (mit Unterseiten), der Eltern und die Startseite allein',
			json_encode( $GLOBALS['sg_calls'] )
		);
		check( false !== strpos( $notes( $r ), 'SiteGround Speed Optimizer' ), 'der Bericht nennt SiteGround', $notes( $r ) );
		check( false !== strpos( $notes( $r ), '3 URLs' ), 'und wie viele URLs', $notes( $r ) );

		echo "\n\033[1mEin Entwurf hat keine oeffentliche Seite\033[0m\n";
		$GLOBALS['sg_calls'] = array();
		$r                   = wpmcp_purge_caches( 13 );
		check( array() === $GLOBALS['sg_calls'], 'nichts wird geleert, schon gar nicht die ganze Startseite', json_encode( $GLOBALS['sg_calls'] ) );

		echo "\n\033[1mEin Fehlschlag wird gesagt\033[0m\n";
		$GLOBALS['sg_calls']  = array();
		$GLOBALS['sg_result'] = false;
		$r                    = wpmcp_purge_caches( 7 );
		check( 'present but not clearable from here' === $r['page'] || false !== strpos( $notes( $r ), 'failed' ), 'page sagt nicht einfach purged', json_encode( $r ) );
		check( false !== strpos( $notes( $r ), 'https://example.test/kurse/' ), 'die URL steht im Bericht', $notes( $r ) );
	}

	if ( 'helper' === $mode ) {
		echo "\n\033[1mNur die oeffentliche Hilfsfunktion: je URL, ohne Startseite\033[0m\n";
		$r = wpmcp_purge_caches( 12 );
		check( 'purged' === $r['page'], 'page: purged', json_encode( $r ) );
		check(
			array(
				array( 'helper', 'https://example.test/kurse/zuerich/' ),
				array( 'helper', 'https://example.test/kurse/' ),
			) === $GLOBALS['sg_calls'],
			'sg_cachepress_purge_cache je URL; die Startseite wuerde dort alles leeren',
			json_encode( $GLOBALS['sg_calls'] )
		);
	}

	if ( 'everything' === $mode ) {
		echo "\n\033[1mNur der volle Purge\033[0m\n";
		$r = wpmcp_purge_caches( 12 );
		check( array( array( 'everything' ) ) === $GLOBALS['sg_calls'], 'sg_cachepress_purge_everything', json_encode( $GLOBALS['sg_calls'] ) );
		check( false !== strpos( $notes( $r ), 'whole' ), 'der Bericht sagt, dass alles geleert wurde', $notes( $r ) );
	}

	if ( 'class' === $mode ) {
		foreach ( array( 'helper', 'everything' ) as $other ) {
			$out    = array();
			$status = 0;
			exec( escapeshellarg( PHP_BINARY ) . ' -d error_reporting=-1 -d display_errors=1 ' . escapeshellarg( __FILE__ ) . ' ' . $other . ' 2>&1', $out, $status );
			echo implode( "\n", $out ), "\n";
			check( 0 === $status && ! preg_match( '/(Warning|Notice|Deprecated|Fatal error):/', implode( "\n", $out ) ), "Lauf \"{$other}\" bestanden" );
		}
	}

	echo "\n";
	if ( $fail ) {
		echo "\033[31m{$fail} Pruefung(en) fehlgeschlagen.\033[0m\n";
		exit( 1 );
	}
	echo "\033[32mAlle Pruefungen bestanden.\033[0m\n";
}
