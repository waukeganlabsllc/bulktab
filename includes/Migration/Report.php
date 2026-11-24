<?php
/**
 * Migration report: counters plus a capped list of per-product issues.
 * Stored inside the job option between batches. Codes only; the import page
 * turns them into translated text.
 *
 * Pure PHP, no WordPress calls.
 *
 * @package BulkTab
 */

namespace BulkTab\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Accumulates migration results.
 */
final class Report {

	/** Most issues kept (counters keep counting past it). */
	const MAX_ISSUES = 500;

	const COUNTERS = array(
		'products_scanned',
		'products_with_tabs',
		'products_written',
		'products_unchanged',
		'products_conflict',
		'products_error',
		'tabs_found',
		'repaired',
		'unreadable',
		'shared_refs',
		'matched_unlinked',
		'drifted',
		'custom_tabs',
		'hidden_tabs',
		'skipped_empty',
		'overflow',
		'shared_to_create',
		'shared_existing',
		'shared_with_rules',
	);

	/**
	 * Counters.
	 *
	 * @var array<string,int>
	 */
	private $counts;

	/**
	 * Issues: list of array( product_id, title, code, detail ).
	 *
	 * @var array<int, array{product_id:int,title:string,code:string,detail:string}>
	 */
	private $issues = array();

	/**
	 * Issues not kept because of the cap.
	 *
	 * @var int
	 */
	private $dropped = 0;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->counts = array_fill_keys( self::COUNTERS, 0 );
	}

	/**
	 * Restore from to_array().
	 *
	 * @param mixed $data Stored array.
	 * @return self
	 */
	public static function from_array( $data ): self {
		$report = new self();
		if ( ! is_array( $data ) ) {
			return $report;
		}
		foreach ( self::COUNTERS as $key ) {
			$report->counts[ $key ] = (int) ( $data['counts'][ $key ] ?? 0 );
		}
		$report->issues  = isset( $data['issues'] ) && is_array( $data['issues'] ) ? array_values( $data['issues'] ) : array();
		$report->dropped = (int) ( $data['dropped'] ?? 0 );
		return $report;
	}

	/**
	 * Plain array for storage.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'counts'  => $this->counts,
			'issues'  => $this->issues,
			'dropped' => $this->dropped,
		);
	}

	/**
	 * Add to a counter.
	 *
	 * @param string $key Counter name.
	 * @param int    $by  Amount.
	 */
	public function count( string $key, int $by = 1 ): void {
		if ( isset( $this->counts[ $key ] ) ) {
			$this->counts[ $key ] += $by;
		}
	}

	/**
	 * Counter value.
	 *
	 * @param string $key Counter name.
	 * @return int
	 */
	public function get( string $key ): int {
		return $this->counts[ $key ] ?? 0;
	}

	/**
	 * Record an issue.
	 *
	 * @param int    $product_id Product ID (0 = site-wide).
	 * @param string $title      Product title.
	 * @param string $code       Issue code.
	 * @param string $detail     Detail (tab title, excerpt...).
	 */
	public function issue( int $product_id, string $title, string $code, string $detail = '' ): void {
		if ( count( $this->issues ) >= self::MAX_ISSUES ) {
			++$this->dropped;
			return;
		}
		$this->issues[] = array(
			'product_id' => $product_id,
			'title'      => $title,
			'code'       => $code,
			'detail'     => self::excerpt( $detail ),
		);
	}

	/**
	 * Add a product's plan and write result.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $title      Product title.
	 * @param array  $plan       Mapper::map_product() result.
	 * @param array  $result     ProductWriter::apply() result.
	 */
	public function add_product( int $product_id, string $title, array $plan, array $result ): void {
		switch ( $result['status'] ) {
			case ProductWriter::UNCHANGED:
				$this->count( 'products_unchanged' );
				return;
			case ProductWriter::CONFLICT:
				$this->count( 'products_conflict' );
				$this->issue( $product_id, $title, 'conflict' );
				return;
			case ProductWriter::ERROR:
				$this->count( 'products_error' );
				$this->issue( $product_id, $title, 'write_error', implode( ' ', $result['errors'] ) );
				break;
			case ProductWriter::WRITTEN:
				$this->count( 'products_written' );
				break;
		}

		$this->count( 'tabs_found', $plan['counts']['tabs'] );
		$this->count( 'shared_refs', $plan['counts']['shared'] );
		$this->count( 'matched_unlinked', $plan['counts']['matched_unlinked'] );
		$this->count( 'drifted', $plan['counts']['drifted'] );
		$this->count( 'custom_tabs', $plan['counts']['custom'] - count( $result['overflow'] ) );
		$this->count( 'hidden_tabs', $plan['counts']['hidden'] );
		$this->count( 'skipped_empty', $plan['counts']['skipped_empty'] );
		$this->count( 'overflow', count( $result['overflow'] ) );

		foreach ( $plan['issues'] as $issue ) {
			$this->issue( $product_id, $title, $issue['code'], $issue['detail'] );
		}
		foreach ( $result['overflow'] as $tab_title ) {
			$this->issue( $product_id, $title, 'overflow', $tab_title );
		}
	}

	/**
	 * Issues.
	 *
	 * @return array<int, array{product_id:int,title:string,code:string,detail:string}>
	 */
	public function issues(): array {
		return $this->issues;
	}

	/**
	 * Issues dropped because of the cap.
	 *
	 * @return int
	 */
	public function dropped(): int {
		return $this->dropped;
	}

	/**
	 * Short single-line excerpt (raw text; escape on output).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function excerpt( string $text ): string {
		$text = trim( (string) preg_replace( '/\s+/', ' ', $text ) );
		if ( strlen( $text ) <= 120 ) {
			return $text;
		}
		$cut = substr( $text, 0, 120 );
		// Do not end in the middle of a UTF-8 sequence.
		$cut = (string) preg_replace( '/[\xC0-\xFF][\x80-\xBF]*$/', '', $cut );
		return $cut . '...';
	}
}
