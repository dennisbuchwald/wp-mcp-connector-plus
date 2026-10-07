<?php
/**
 * Which widgets a site has, and what the agent hears about it.
 *
 * On staging.maxport.ch elementor-write refused a heading, a text editor,
 * a button and nine more with "Elementor does not know these element
 * types", which read as a broken connector. The cause was a site setting:
 * under Elementor > Element Manager 85 of 86 widgets were deactivated and
 * only "HTML" was left. Elementor then never registers them
 * (`elementor/widgets/is_widget_enabled`, option
 * `elementor_disabled_elements`), so refusing is right: a deactivated
 * widget would not render. The message has to say why, and what there
 * is to build with; elementor-read and site-info say it before a write.
 *
 * The site owner keeps it that way, so the HTML-only way of working is
 * pinned here end to end: a section is a container with one html widget.
 *
 * Every request in the first two tests goes the whole way a client's
 * does: application password, REST fence, MCP initialize and tools/call,
 * so a registry that were not ready in that context would show here.
 *
 * @package wp-mcp-connector-plus
 */

class ElementorWidgetsTest extends WPMCP_Real_Elementor_TestCase {

	/** @var array Widget instances as registered before the test. */
	private $registered = array();
	/** @var string[] What Element Manager lists, and so can switch off. */
	private $listed = array();
	/** @var WP_User */
	private $agent;

	public function set_up() {
		parent::set_up();
		$this->registered = \Elementor\Plugin::$instance->widgets_manager->get_widget_types();
		// Element Manager lists the widgets of the panel only (its
		// ajax_get_admin_page_data()); the hidden ones every widget builds
		// on (common, common-base ...) cannot be switched off there.
		foreach ( $this->registered as $name => $widget ) {
			// html hides itself from accounts without unfiltered_html.
			if ( ( 'html' === $name || $widget->show_in_panel() ) && ! $widget instanceof \Elementor\Widget_Common_Base ) {
				$this->listed[] = $name;
			}
		}
		$this->agent = $this->agent();
	}

	public function tear_down() {
		delete_option( 'elementor_disabled_elements' );
		$this->register_again( $this->registered );
		parent::tear_down();
	}

	/**
	 * What Elementor > Element Manager stores, and the registry as a
	 * request after that sees it: every widget goes through register()
	 * and Elementor's own filter again.
	 *
	 * @param string[] $names Widget names to deactivate.
	 */
	private function deactivate( array $names ) {
		update_option( 'elementor_disabled_elements', $names );
		$this->register_again( $this->registered );
	}

	private function register_again( array $instances ) {
		$manager = \Elementor\Plugin::$instance->widgets_manager;
		foreach ( array_keys( $manager->get_widget_types() ) as $name ) {
			$manager->unregister( $name );
		}
		foreach ( $instances as $instance ) {
			$manager->register( $instance );
		}
	}

	private function only_html() {
		$this->deactivate( array_values( array_diff( $this->listed, array( 'html' ) ) ) );
	}

	private function page_elements() {
		return array(
			self::html_section( 'a000001', 'b000001', '<section class="mx-hero"><h1 class="mx-title">Bootsfuehrerschein</h1><p class="mx-lead">Wir machen dich fit.</p></section>' ),
			self::html_section( 'a000002', 'b000002', '<section class="mx-block"><h2 class="mx-h">Kurse</h2><p class="mx-text">Binnen und See.</p></section>' ),
		);
	}

	private function heading_insert() {
		return array(
			array(
				'op'      => 'insert',
				'after'   => 'a000002',
				'element' => array(
					'elType'   => 'container',
					'elements' => array(
						array(
							'elType'     => 'widget',
							'widgetType' => 'heading',
							'settings'   => array(
								'title'       => 'Preise',
								'header_size' => 'h2',
							),
						),
					),
				),
			),
		);
	}

	private function agent_password() {
		list( $password ) = WP_Application_Passwords::create_new_application_password( $this->agent->ID, array( 'name' => 'MCP' ) );
		return $password;
	}

	public function test_elementor_keeps_its_deactivated_widgets_in_this_option() {
		$this->assertInstanceOf( 'Elementor\\Widget_Base', $this->registered['heading'] ?? null, 'heading is registered on a fresh site' );
		$this->deactivate( array( 'heading' ) );
		$this->assertNull( \Elementor\Plugin::$instance->widgets_manager->get_widget_types( 'heading' ), 'deactivated, Elementor does not register it' );
		$this->assertTrue( \Elementor\Modules\ElementManager\Options::is_element_disabled( 'heading' ) );
		$this->assertNotNull( \Elementor\Plugin::$instance->widgets_manager->get_widget_types( 'html' ) );
	}

	public function test_a_heading_goes_through_the_mcp_endpoint_when_the_widget_is_on() {
		$id       = $this->elementor_page( $this->page_elements() );
		$password = $this->agent_password();

		$call = $this->mcp_call( $this->agent, $password, 'wpmcp-elementor-write', array( 'post_id' => $id, 'ops' => $this->heading_insert(), 'dry_run' => false ) );
		$this->assertFalse( $call['isError'], $call['text'] );
		$this->assertTrue( $call['result']['ok'] ?? false, $call['text'] );

		$stored = $this->stored_elements( $id );
		$this->assertCount( 3, $stored );
		$this->assertSame( 'heading', $stored[2]['elements'][0]['widgetType'] );
		$this->assertStringContainsString( 'Preise', $this->rendered( $id ) );
	}

	public function test_a_deactivated_widget_is_refused_with_why_and_what_there_is() {
		$id       = $this->elementor_page( $this->page_elements() );
		$password = $this->agent_password();
		$this->only_html();

		$call = $this->mcp_call( $this->agent, $password, 'wpmcp-elementor-write', array( 'post_id' => $id, 'ops' => $this->heading_insert(), 'dry_run' => false ) );
		$this->assertFalse( $call['result']['ok'] ?? true, $call['text'] );
		$message = implode( ' ', $call['result']['errors'] ?? array() );
		$this->assertStringContainsString( 'deactivated', $message );
		$this->assertStringContainsString( 'Element Manager', $message );
		$this->assertStringContainsString( 'widget "heading"', $message );
		$this->assertStringContainsString( 'site-wide', $message, 'turning it back on is for a person to decide' );
		$this->assertStringContainsString( 'Widget types this site has: html', $message );
		$this->assertStringNotContainsString( 'does not know', $message, 'not mistaken for a missing plugin' );
		$this->assertSame( $this->page_elements(), $this->stored_elements( $id ), 'nothing stored' );
	}

	public function test_an_unknown_type_is_still_named_as_missing_with_what_there_is() {
		$id = $this->elementor_page( $this->page_elements() );
		$this->only_html();
		$this->act_as( $this->agent );

		$ops                                       = $this->heading_insert();
		$ops[0]['element']['elements'][0]['widgetType'] = 'some-addon-widget';
		$write                                     = $this->execute( 'wpmcp/elementor-write', array( 'post_id' => $id, 'ops' => $ops ) );
		$message                                   = implode( ' ', $write['errors'] ?? array() );
		$this->assertStringContainsString( 'would delete them', $message );
		$this->assertStringContainsString( 'some-addon-widget', $message );
		$this->assertStringNotContainsString( 'Element Manager', $message, 'not deactivated, just not there' );
		$this->assertStringContainsString( 'Widget types this site has: html', $message );
	}

	public function test_read_and_site_info_say_what_can_be_built_before_a_write() {
		$id = $this->elementor_page( $this->page_elements() );
		$this->act_as( $this->agent );

		$info = $this->execute( 'wpmcp/site-info', array() );
		$this->assertSame( count( $this->listed ), $info['elementor']['availableWidgets']['count'] );
		$this->assertSame( 0, $info['elementor']['disabledByElementManager'] );
		$this->assertArrayNotHasKey( 'hint', $info['elementor'], 'nothing to say on a site with every widget' );

		$this->only_html();
		$info = $this->execute( 'wpmcp/site-info', array() );
		$this->assertSame( array( 'count' => 1, 'names' => array( 'html' ) ), $info['elementor']['availableWidgets'] );
		$this->assertSame( count( $this->listed ) - 1, $info['elementor']['disabledByElementManager'] );
		$this->assertStringContainsString( 'container with one html widget', $info['elementor']['hint'] ?? '' );
		$this->assertStringContainsString( 'element_id', $info['elementor']['hint'] );

		$read = $this->execute( 'wpmcp/elementor-read', array( 'post_id' => $id ) );
		$this->assertSame( array( 'count' => 1, 'names' => array( 'html' ) ), $read['availableWidgets'] ?? null, $this->explain( $read ) );
		$this->assertSame( count( $this->listed ) - 1, $read['disabledByElementManager'] );
		$this->assertStringContainsString( 'container with one html widget', $read['hint'] ?? '' );
	}

	/**
	 * The way of working on maxport.ch, end to end, with every widget but
	 * html deactivated.
	 */
	public function test_an_html_only_site_can_be_worked_on_completely() {
		$id = $this->elementor_page( $this->page_elements() );
		$this->only_html();
		$this->act_as( $this->agent );

		$write = function ( array $ops ) use ( $id ) {
			$result = $this->execute( 'wpmcp/elementor-write', array( 'post_id' => $id, 'ops' => $ops, 'dry_run' => false ) );
			$this->assertFalse( $this->refused( $result ), $this->explain( $result ) );
			return $result;
		};

		// A new section: a container with one html widget, in the classes
		// the page already uses.
		$write(
			array(
				array(
					'op'      => 'insert',
					'after'   => 'a000002',
					'element' => array(
						'elType'   => 'container',
						'elements' => array(
							array(
								'elType'     => 'widget',
								'widgetType' => 'html',
								'settings'   => array( 'html' => '<section class="mx-block"><h2 class="mx-h">Preise</h2><p class="mx-text">Ab 499 Franken.</p></section>' ),
							),
						),
					),
				),
			)
		);
		$stored = $this->stored_elements( $id );
		$this->assertCount( 3, $stored );
		$this->assertSame( 'html', $stored[2]['elements'][0]['widgetType'] );
		$new_widget = $stored[2]['elements'][0]['id'];

		// A section without a script, duplicated.
		$write( array( array( 'op' => 'duplicate', 'id' => 'a000002' ) ) );
		$stored = $this->stored_elements( $id );
		$this->assertCount( 4, $stored );
		$this->assertSame( $stored[1]['elements'][0]['settings'], $stored[2]['elements'][0]['settings'], 'the copy sits right after the original' );

		// A heading level changed inside the markup.
		$write(
			array(
				array( 'op' => 'patch_html', 'id' => $new_widget, 'find' => '<h2 class="mx-h">Preise</h2>', 'replace' => '<h3 class="mx-h">Preise</h3>' ),
			)
		);
		$this->assertStringContainsString( '<h3 class="mx-h">Preise</h3>', $this->rendered( $id ) );

		// A FAQ with its structured data, set as the markup of a widget.
		$jsonld = '<script type="application/ld+json">{"@context":"https://schema.org","@type":"FAQPage","mainEntity":[{"@type":"Question","name":"Wie lange dauert der Kurs?","acceptedAnswer":{"@type":"Answer","text":"Zwei Wochenenden."}}]}</script>';
		$faq    = '<section class="mx-block"><h2 class="mx-h">Fragen</h2><details class="mx-text"><summary>Wie lange dauert der Kurs?</summary><p>Zwei Wochenenden.</p></details>' . $jsonld . '</section>';
		$write( array( array( 'op' => 'set_html', 'id' => $new_widget, 'html' => $faq ) ) );

		$stored = $this->stored_elements( $id );
		$index  = wpmcp_elementor_index( $stored );
		$this->assertSame( $faq, wpmcp_elementor_at( $stored, $index[ $new_widget ] )['settings']['html'] ?? null, 'stored byte for byte, script included' );
		$rendered = $this->rendered( $id );
		$this->assertStringContainsString( '<summary>Wie lange dauert der Kurs?</summary>', $rendered );
		$this->assertStringContainsString( '"@type":"FAQPage"', $rendered );
		$this->assertStringContainsString( '<script type="application/ld+json">', $rendered );
	}
}
