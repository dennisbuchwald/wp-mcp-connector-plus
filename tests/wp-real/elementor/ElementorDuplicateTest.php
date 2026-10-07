<?php
/**
 * content-duplicate on an Elementor page.
 *
 * Duplicating is the recommended way to start a new page, and on an
 * Elementor site every page is an Elementor page. The copy's
 * _elementor_data went through Elementor's REST meta sanitizer, which
 * runs kses for an account without unfiltered_html: the copy lost every
 * script the original had, without a word. The same failure the block
 * path had with JSON-LD in 0.10.
 *
 * @package wp-mcp-connector-plus
 */

class ElementorDuplicateTest extends WPMCP_Real_Elementor_TestCase {

	const SLIDER = '<script>window.wpmcpSlider = (window.wpmcpSlider || 0) + 1;</script>';

	private function elements() {
		return array(
			self::html_section( 'a000001', 'b000001', '<h1>Kurse</h1>' . self::SLIDER ),
			self::html_section( 'a000002', 'b000002', '<p>Binnen und See</p>' ),
		);
	}

	public function test_duplicating_an_elementor_page_copies_its_elements_unchanged() {
		$id = $this->elementor_page( $this->elements() );
		update_post_meta( $id, '_elementor_css', array( 'status' => 'file', 'time' => 1 ) );
		$this->act_as( $this->agent() );

		$copy = $this->execute( 'wpmcp/content-duplicate', array( 'post_id' => $id ) );
		$this->assertFalse( $this->refused( $copy ), $this->explain( $copy ) );

		$this->assertSame( 'builder', get_post_meta( $copy['id'], '_elementor_edit_mode', true ) );
		$this->assertSame( $this->elements(), $this->stored_elements( $copy['id'] ), 'the element data, scripts included, as the original holds it' );
		$this->assertSame( '', get_post_meta( $copy['id'], '_elementor_css', true ), 'the original\'s CSS record stays with the original' );
		$this->assertStringContainsString( self::SLIDER, $this->rendered( $copy['id'] ) );
		$this->assertFalse( current_user_can( 'unfiltered_html' ), 'the grant ended with the copy' );
	}
}
