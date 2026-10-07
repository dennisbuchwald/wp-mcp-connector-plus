<?php
/**
 * CSS settings are judged as CSS, against Elementor 4.3.4.
 *
 * Field report: an SVG background written as
 * url("data:image/svg+xml,<svg ...></svg>") into a widget's Custom CSS
 * was refused with "adds markup WordPress will not store". Custom CSS is
 * Elementor Pro's (a code control with language css), and Pro is not on
 * wordpress.org; the control is added here to the HTML widget the way
 * Pro adds it to every element, so the connector finds it by asking
 * Elementor, as it does on a site with Pro.
 *
 * @package wp-mcp-connector-plus
 */

class ElementorCssTest extends WPMCP_Real_Elementor_TestCase {

	const SVG_RAW     = "selector .hero { background: url(\"data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 10 10'><path d='M0 0h10v10z' fill='%23D9F24A'/></svg>\") no-repeat; }";
	const SVG_ENCODED = 'selector .hero { background-image: url("data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27%3E%3Cpath d=%27M0 0h10v10z%27/%3E%3C/svg%3E"); }';

	/** @var \Elementor\Widget_Base */
	private $widget;

	public function set_up() {
		parent::set_up();
		$this->widget = \Elementor\Plugin::$instance->widgets_manager->get_widget_types( 'html' );
		$this->widget->get_controls();
		\Elementor\Plugin::$instance->controls_manager->add_control_to_stack(
			$this->widget,
			'custom_css',
			array(
				'type'     => \Elementor\Controls_Manager::CODE,
				'language' => 'css',
				'label'    => 'Custom CSS',
				'tab'      => \Elementor\Controls_Manager::TAB_ADVANCED,
			)
		);
	}

	public function tear_down() {
		\Elementor\Plugin::$instance->controls_manager->remove_control_from_stack( $this->widget->get_unique_name(), 'custom_css' );
		parent::tear_down();
	}

	private function css_write( $id, $css, $dry_run = false ) {
		return $this->execute(
			'wpmcp/elementor-write',
			array(
				'post_id' => $id,
				'ops'     => array(
					array(
						'op'       => 'set_settings',
						'id'       => 'b000001',
						'settings' => array( 'custom_css' => $css ),
					),
				),
				'dry_run' => $dry_run,
			)
		);
	}

	public function test_elementor_reports_the_control_as_css() {
		$controls = $this->widget->get_controls();
		$this->assertSame( 'code', $controls['custom_css']['type'] );
		$this->assertSame( 'css', $controls['custom_css']['language'] );
		$this->assertSame( 'code', $controls['html']['type'], 'the HTML widget\'s markup is a code control too' );
		$this->assertNotSame( 'css', $controls['html']['language'] ?? null, 'but not one in CSS' );
	}

	public function test_an_svg_background_is_stored_byte_identical() {
		foreach ( array( self::SVG_RAW, self::SVG_ENCODED ) as $css ) {
			$id = $this->elementor_page( array( self::html_section( 'a000001', 'b000001', '<div class="hero">Hallo</div>' ) ) );
			$this->act_as( $this->agent() );

			$result = $this->css_write( $id, $css );
			$this->assertTrue( $result['ok'] ?? false, $this->explain( $result ) );
			$this->assertSame( $css, $this->stored_elements( $id )[0]['elements'][0]['settings']['custom_css'] ?? null );
		}
	}

	public function test_each_danger_is_refused_with_its_fragment_and_nothing_is_stored() {
		$dangers = array(
			'selector{} </style><script>alert(1)</script>' => '</style',
			'selector{ width: expression(alert(1)) }'      => 'expression(',
			'selector{ background: url(\\6a avascript:alert(1)) }' => 'javascript:',
			'selector{ behavior: url(x.htc) }'             => 'behavior:',
			'selector{ -moz-binding: url(x.xml#a) }'       => '-moz-binding',
			'@import url("https://evil.test/x.css");'      => '@import',
			'selector{ background: url("data:text/html,<b>x</b>") }' => 'data:text/html',
		);

		foreach ( $dangers as $css => $fragment ) {
			$elements = array( self::html_section( 'a000001', 'b000001', '<div class="hero">Hallo</div>' ) );
			$id       = $this->elementor_page( $elements );
			$this->act_as( $this->agent() );

			$result = $this->css_write( $id, $css );
			$this->assertFalse( $result['ok'] ?? true, $css . ': ' . $this->explain( $result ) );
			$this->assertStringContainsString( $fragment, implode( ' ', $result['errors'] ), $css );
			$this->assertStringContainsString( 'b000001:custom_css', implode( ' ', $result['errors'] ), $css );
			$this->assertArrayNotHasKey( 'custom_css', $this->stored_elements( $id )[0]['elements'][0]['settings'], 'nothing stored' );
		}
	}

	public function test_the_html_setting_is_still_judged_as_markup() {
		$id = $this->elementor_page( array( self::html_section( 'a000001', 'b000001', '<div class="hero">Hallo</div>' ) ) );
		$this->act_as( $this->agent() );

		$result = $this->execute(
			'wpmcp/elementor-write',
			array(
				'post_id' => $id,
				'ops'     => array(
					array(
						'op'   => 'set_html',
						'id'   => 'b000001',
						'html' => '<div onclick="alert(1)">x</div>',
					),
				),
				'dry_run' => false,
			)
		);
		$this->assertFalse( $result['ok'] ?? true, $this->explain( $result ) );
	}
}
