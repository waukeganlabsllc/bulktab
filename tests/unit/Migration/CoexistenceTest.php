<?php
/**
 * @package BulkTab
 */

namespace BulkTab\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use BulkTab\Migration\Coexistence;

/**
 * Stand-in for YIKES_Custom_Product_Tabs_Display.
 */
final class FakeYikesDisplay {
	public function custom_product_tabs_panel_content(): void {}
}

/**
 * Stand-in for a subclass (e.g. a theme extending the YIKES display class).
 */
final class OtherDisplay {
	public function panel(): void {}
}

/**
 * @covers \BulkTab\Migration\Coexistence
 */
final class CoexistenceTest extends TestCase {

	private static function yikes_tabs(): array {
		return array(
			array(
				'id'      => 'shipping',
				'title'   => 'Shipping',
				'content' => 'Free.',
			),
			array(
				'id'      => 'specs',
				'title'   => 'Specs',
				'content' => 'S',
			),
		);
	}

	private static function page_tabs(): array {
		$yikes = new FakeYikesDisplay();
		return array(
			'description'        => array(
				'title'    => 'Description',
				'priority' => 10,
				'callback' => 'woocommerce_product_description_tab',
			),
			'shipping'           => array(
				'title'    => 'Shipping',
				'priority' => 25,
				'callback' => array( $yikes, 'custom_product_tabs_panel_content' ),
				'content'  => 'Free.',
			),
			'specs'              => array(
				'title'    => 'Specs',
				'priority' => 26,
				'callback' => array( $yikes, 'custom_product_tabs_panel_content' ),
				'content'  => 'S',
			),
			'bulktab_s_shipping' => array(
				'title'       => 'Shipping',
				'priority'    => 30,
				'callback'    => array( new OtherDisplay(), 'panel' ),
				'bulktab_ref' => 's:shipping',
			),
			'reviews'            => array(
				'title'    => 'Reviews',
				'priority' => 30,
				'callback' => 'comments_template',
			),
		);
	}

	public function test_drops_tabs_rendered_by_yikes_only(): void {
		$this->assertSame(
			array( 'shipping', 'specs' ),
			Coexistence::keys_to_drop( self::page_tabs(), self::yikes_tabs(), FakeYikesDisplay::class )
		);
	}

	public function test_drops_by_callback_even_without_stored_data(): void {
		$this->assertSame(
			array( 'shipping', 'specs' ),
			Coexistence::keys_to_drop( self::page_tabs(), array(), FakeYikesDisplay::class )
		);
	}

	public function test_wrapped_callback_matched_by_key_title_and_content(): void {
		$tabs                         = self::page_tabs();
		$tabs['shipping']['callback'] = static function () {};
		$this->assertSame(
			array( 'shipping', 'specs' ),
			Coexistence::keys_to_drop( $tabs, self::yikes_tabs(), FakeYikesDisplay::class )
		);
	}

	public function test_other_plugins_tab_with_same_key_but_no_yikes_shape_is_kept(): void {
		$tabs             = self::page_tabs();
		$tabs['shipping'] = array(
			'title'    => 'Shipping',
			'priority' => 40,
			'callback' => 'other_plugin_shipping_tab',
		);
		$this->assertSame( array( 'specs' ), Coexistence::keys_to_drop( $tabs, self::yikes_tabs(), FakeYikesDisplay::class ) );
	}

	public function test_renamed_title_not_matched_without_yikes_callback(): void {
		$tabs                     = self::page_tabs();
		$tabs['specs']['callback'] = 'something_else';
		$tabs['specs']['title']   = 'Technical data';
		$this->assertSame( array( 'shipping' ), Coexistence::keys_to_drop( $tabs, self::yikes_tabs(), FakeYikesDisplay::class ) );
	}

	public function test_nothing_dropped_when_yikes_class_differs(): void {
		$this->assertSame( array(), Coexistence::keys_to_drop( array_intersect_key( self::page_tabs(), array_flip( array( 'description', 'reviews', 'bulktab_s_shipping' ) ) ), self::yikes_tabs(), FakeYikesDisplay::class ) );
	}

	public function test_bulktab_tabs_never_dropped_even_with_matching_key(): void {
		$tabs = array(
			'shipping' => array(
				'title'       => 'Shipping',
				'content'     => 'Free.',
				'bulktab_ref' => 'c:1',
				'callback'    => array( new FakeYikesDisplay(), 'custom_product_tabs_panel_content' ),
			),
		);
		$this->assertSame( array(), Coexistence::keys_to_drop( $tabs, self::yikes_tabs(), FakeYikesDisplay::class ) );
	}

	public function test_default_class_name_is_yikes_display(): void {
		$this->assertSame( 'YIKES_Custom_Product_Tabs_Display', Coexistence::YIKES_DISPLAY_CLASS );
		// The real class is not loaded here: only data matching applies.
		$this->assertSame( array( 'shipping', 'specs' ), Coexistence::keys_to_drop( self::page_tabs(), self::yikes_tabs() ) );
	}

	public function test_legacy_tabs_match_yikes_render(): void {
		$legacy = Coexistence::legacy_tabs(
			array(
				array(
					'id'      => 'a',
					'title'   => 'A',
					'content' => 'first',
				),
				array(
					'id'      => '',
					'title'   => '',
					'content' => 'no title',
				),
				array(
					'id'      => 'b',
					'title'   => 'B',
					'content' => 'b',
				),
				array(
					'id'      => 'a',
					'title'   => 'A',
					'content' => 'second',
				),
			)
		);
		$this->assertSame( array( 'a', 'b' ), array_keys( $legacy ) );
		$this->assertSame( 'second', $legacy['a']['content'] );
		$this->assertSame( 27, $legacy['a']['priority'] );
		$this->assertSame( 26, $legacy['b']['priority'] );
	}
}
