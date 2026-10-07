<?php
/**
 * Base class for the tests that run with Elementor active.
 *
 * Pages are stored the way Elementor stores them: `_elementor_data` as
 * slashed JSON, `_elementor_edit_mode` = builder, the document type and
 * version, written by an administrator. What the agent then does goes
 * through the abilities, as on a customer site.
 *
 * @package wp-mcp-connector-plus
 */

abstract class WPMCP_Real_Elementor_TestCase extends WPMCP_Real_TestCase {

	public function set_up() {
		parent::set_up();
		// PHPUnit installed its own handler for this test; Elementor's PHP
		// 8.4 deprecations are filtered in front of it (see bootstrap.php).
		set_error_handler( wpmcp_real_elementor_noise_filter( set_error_handler( null ) ) );
		$this->set_level( 'draft' );
		// What Elementor's activation leaves behind and its documents rely
		// on: the default kit, the site-wide settings document. The test
		// library deletes every post after each test class, the kit too,
		// so it is made here, inside the test's transaction.
		if ( ! get_post( (int) get_option( 'elementor_active_kit' ) ) ) {
			delete_option( 'elementor_active_kit' );
			\Elementor\Core\Kits\Manager::create_default_kit();
		}
		// The agent always arrives over REST, and Elementor registers its
		// post meta (with a kses sanitizer for _elementor_data) only then.
		rest_get_server();
	}

	public function tear_down() {
		restore_error_handler();
		parent::tear_down();
	}

	/**
	 * A page built with Elementor, stored by an administrator.
	 *
	 * @param array  $elements Elementor elements.
	 * @param string $status   Post status.
	 * @return int Post ID.
	 */
	protected function elementor_page( array $elements, $status = 'draft' ) {
		$previous = get_current_user_id();
		wp_set_current_user( $this->admin()->ID );
		$id = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Elementor page',
				'post_status' => $status,
			),
			true
		);
		$this->assertIsInt( $id );
		update_post_meta( $id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $id, '_elementor_template_type', 'wp-page' );
		update_post_meta( $id, '_elementor_version', ELEMENTOR_VERSION );
		update_post_meta( $id, '_elementor_data', wp_slash( wp_json_encode( $elements ) ) );
		wp_set_current_user( $previous );
		return $id;
	}

	/**
	 * What `_elementor_data` holds, decoded, straight from the database.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	protected function stored_elements( $post_id ) {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_elementor_data'", $post_id ) );
		$this->assertIsString( $raw, '_elementor_data is stored' );
		$decoded = json_decode( $raw, true );
		$this->assertSame( JSON_ERROR_NONE, json_last_error(), '_elementor_data is valid JSON' );
		return $decoded;
	}

	/**
	 * The page as Elementor's frontend renders it.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	protected function rendered( $post_id ) {
		\Elementor\Plugin::$instance->documents->get( $post_id, false );
		return (string) \Elementor\Plugin::$instance->frontend->get_builder_content( $post_id, false );
	}

	/**
	 * A container holding one HTML widget, the shape of every page on
	 * the first customer site.
	 *
	 * @param string $container_id Container id.
	 * @param string $widget_id    Widget id.
	 * @param string $html         HTML.
	 * @return array
	 */
	protected static function html_section( $container_id, $widget_id, $html ) {
		return array(
			'id'       => $container_id,
			'elType'   => 'container',
			'settings' => array(),
			'elements' => array(
				array(
					'id'         => $widget_id,
					'elType'     => 'widget',
					'widgetType' => 'html',
					'settings'   => array( 'html' => $html ),
					'elements'   => array(),
				),
			),
			'isInner'  => false,
		);
	}
}
