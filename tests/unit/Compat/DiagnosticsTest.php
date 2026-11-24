<?php
/**
 * @package BulkTab
 */

namespace BulkTab\Tests\Unit\Compat;

use PHPUnit\Framework\TestCase;
use BulkTab\Compat\Diagnostics;

/**
 * @covers \BulkTab\Compat\Diagnostics
 */
final class DiagnosticsTest extends TestCase {

	public function test_all_markers_found(): void {
		$html = '<div id="tab-bulktab_s_faq"><div class="bulktab-tab-content" data-bulktab-ref="s:faq">x</div></div>'
			. '<div class="bulktab-tab-content" data-bulktab-ref="c:1">y</div>';
		$this->assertSame(
			array(),
			Diagnostics::missing_refs(
				$html,
				array(
					's:faq' => 'bulktab_s_faq',
					'c:1'   => 'bulktab_c_1',
				)
			)
		);
	}

	public function test_missing_refs_in_input_order(): void {
		$html = '<div data-bulktab-ref="c:1"></div>';
		$this->assertSame(
			array( 's:faq', 'c:2' ),
			Diagnostics::missing_refs(
				$html,
				array(
					's:faq' => 'bulktab_s_faq',
					'c:1'   => 'bulktab_c_1',
					'c:2'   => 'bulktab_c_2',
				)
			)
		);
	}

	public function test_shortcode_section_class_counts(): void {
		$html = '<div class="bulktab-stack"><section class="bulktab-section bulktab-bulktab_s_size-guide">x</section></div>';
		$this->assertSame( array(), Diagnostics::missing_refs( $html, array( 's:size-guide' => 'bulktab_s_size-guide' ) ) );
	}

	public function test_class_prefix_of_longer_key_does_not_count(): void {
		$html = '<section class="bulktab-section bulktab-bulktab_c_10">x</section>';
		$this->assertSame( array( 'c:1' ), Diagnostics::missing_refs( $html, array( 'c:1' => 'bulktab_c_1' ) ) );
	}

	public function test_ref_text_outside_marker_does_not_count(): void {
		$html = '<p>See s:faq and bulktab_s_faq for details</p>';
		$this->assertSame( array( 's:faq' ), Diagnostics::missing_refs( $html, array( 's:faq' => 'bulktab_s_faq' ) ) );
	}

	public function test_expected_refs(): void {
		$tabs = array(
			'description'   => array( 'title' => 'Description' ),
			'bulktab_s_faq' => array( 'bulktab_ref' => 's:faq' ),
			'other'         => 'not an array',
			'bulktab_c_2'   => array( 'bulktab_ref' => 'c:2' ),
			'x'             => array( 'bulktab_ref' => '' ),
		);
		$this->assertSame(
			array(
				's:faq' => 'bulktab_s_faq',
				'c:2'   => 'bulktab_c_2',
			),
			Diagnostics::expected_refs( $tabs )
		);
	}
}
