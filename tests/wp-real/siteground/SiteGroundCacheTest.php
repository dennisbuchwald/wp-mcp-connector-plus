<?php
/**
 * A write clears SiteGround Speed Optimizer's cache, against the plugin.
 *
 * On staging.maxport.ch every save stayed invisible until the cache was
 * cleared by hand over FTP. Here Speed Optimizer 7.8.4 is active, its
 * file cache is switched on, and a cached copy of the page lies where
 * the plugin keeps it (wp-content/cache/sgo-cache/<host>/<path>/). Off
 * SiteGround the plugin's purge clears exactly that file cache; on
 * SiteGround the same call clears the server's dynamic cache too.
 *
 * @package wp-mcp-connector-plus
 */

class SiteGroundCacheTest extends WPMCP_Real_TestCase {

	/** @var string */
	private $cached;

	public function set_up() {
		parent::set_up();
		update_option( 'siteground_optimizer_file_caching', 1 );
	}

	public function tear_down() {
		$this->remove( WP_CONTENT_DIR . '/cache/sgo-cache' );
		parent::tear_down();
	}

	private function remove( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $dir );
	}

	/**
	 * A cached copy of the page, laid out as Speed Optimizer lays it out.
	 *
	 * @param int $id Post.
	 * @return string File.
	 */
	private function cache_page( $id ) {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$path = wp_parse_url( get_permalink( $id ), PHP_URL_PATH );
		$dir  = WP_CONTENT_DIR . '/cache/sgo-cache/' . $host . rtrim( (string) $path, '/' ) . '/';
		wp_mkdir_p( $dir );
		file_put_contents( $dir . 'index.html', '<p>alt</p>' );
		return $dir . 'index.html';
	}

	private function published_page() {
		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( '/%postname%/' );
		$id = $this->page( '<!-- wp:paragraph --><p>Alt</p><!-- /wp:paragraph -->', 'publish' );
		wp_update_post(
			array(
				'ID'        => $id,
				'post_name' => 'kurse',
			)
		);
		return $id;
	}

	private function write( $id, $dry_run ) {
		return $this->execute(
			'wpmcp/content-write',
			array(
				'post_id' => $id,
				'ops'     => array(
					array(
						'op'      => 'patch_html',
						'path'    => '0',
						'find'    => 'Alt',
						'replace' => 'Neu',
					),
				),
				'dry_run' => $dry_run,
			)
		);
	}

	public function test_the_plugin_offers_the_purge_api_the_connector_calls() {
		$this->assertTrue( function_exists( 'sg_cachepress_purge_cache' ) );
		$this->assertTrue( method_exists( 'SiteGround_Optimizer\\Supercacher\\Supercacher', 'purge_cache_request' ) );
		$method = new ReflectionMethod( 'SiteGround_Optimizer\\Supercacher\\Supercacher', 'purge_cache_request' );
		$this->assertTrue( $method->isStatic() && $method->isPublic() );
		$this->assertSame( array( 'url', 'include_child_paths' ), array_map( function ( $p ) { return $p->getName(); }, $method->getParameters() ) );
	}

	public function test_a_write_clears_the_cached_page_and_says_so() {
		$this->set_level( 'full' );
		$id     = $this->published_page();
		$cached = $this->cache_page( $id );
		$this->act_as( $this->agent() );

		$dry = $this->write( $id, true );
		$this->assertTrue( $dry['ok'] ?? false, $this->explain( $dry ) );
		$this->assertFileExists( $cached, 'a dry run clears nothing' );

		$result = $this->write( $id, false );
		$this->assertTrue( $result['ok'] ?? false, $this->explain( $result ) );
		$this->assertFileDoesNotExist( $cached, 'the cached copy is gone' );
		$this->assertSame( 'purged', $result['cache']['page'] ?? null, $this->explain( $result ) );
		$this->assertStringContainsString( 'SiteGround Speed Optimizer', implode( ' ', $result['cache']['notes'] ) );
	}

	public function test_a_draft_clears_nothing() {
		$id     = $this->page( '<!-- wp:paragraph --><p>Alt</p><!-- /wp:paragraph -->' );
		$other  = $this->published_page();
		$cached = $this->cache_page( $other );
		$this->act_as( $this->agent() );

		$result = $this->write( $id, false );
		$this->assertTrue( $result['ok'] ?? false, $this->explain( $result ) );
		$this->assertFileExists( $cached, 'a draft has no cached page, and the rest stays' );
		$this->assertStringContainsString( 'not public', implode( ' ', $result['cache']['notes'] ) );
	}
}
