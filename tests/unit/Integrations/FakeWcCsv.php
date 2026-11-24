<?php
/**
 * Test double that reproduces what WooCommerce's product CSV exporter and importer
 * do to cell values (checked against WC trunk: abstract-wc-csv-exporter.php,
 * class-wc-product-csv-importer.php, class-wc-product-csv-importer-controller.php).
 *
 * @package BulkTab
 */

namespace BulkTab\Tests\Unit\Integrations;

use BulkTab\Integrations\Csv\Cells;
use BulkTab\Integrations\Csv\Columns;

final class FakeWcCsv {

	/**
	 * WC_CSV_Exporter::escape_data(): prefix ' to formula-like values.
	 */
	public static function escape( $data ) {
		if ( is_int( $data ) || is_float( $data ) ) {
			return $data;
		}
		if ( in_array( mb_substr( (string) $data, 0, 1 ), array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			$data = "'" . $data;
		}
		return $data;
	}

	/**
	 * Export: BOM + header row + rows, as WC_CSV_Batch_Exporter writes them.
	 *
	 * @param array $columns Column id => header (after the column_names filter).
	 * @param array $rows    Rows of column id => value (after the row_data filter).
	 */
	public static function export( array $columns, array $rows ): string {
		$buffer = fopen( 'php://memory', 'w+' );
		fputcsv( $buffer, array_map( array( self::class, 'escape' ), array_values( $columns ) ), ',', '"', "\0" );
		foreach ( $rows as $row ) {
			$out = array();
			foreach ( array_keys( $columns ) as $id ) {
				$out[] = isset( $row[ $id ] ) ? self::escape( $row[ $id ] ) : '';
			}
			fputcsv( $buffer, $out, ',', '"', "\0" );
		}
		rewind( $buffer );
		$csv = stream_get_contents( $buffer );
		fclose( $buffer );
		return chr( 239 ) . chr( 187 ) . chr( 191 ) . $csv;
	}

	/**
	 * Import: parse, auto-map headers, run our formatting callback on our columns.
	 *
	 * @return array<int, array<string, string>> Parsed rows (mapped key => value).
	 */
	public static function import( string $csv ): array {
		$handle = fopen( 'php://memory', 'w+' );
		fwrite( $handle, $csv );
		rewind( $handle );

		$headers = array_map( 'trim', (array) fgetcsv( $handle, 0, ',', '"', "\0" ) );
		if ( isset( $headers[0] ) && 0 === strpos( $headers[0], "\xEF\xBB\xBF" ) ) {
			$headers[0] = substr( $headers[0], 3 );
		}
		$mapped = self::auto_map( $headers );

		$rows = array();
		while ( false !== ( $row = fgetcsv( $handle, 0, ',', '"', "\0" ) ) ) {
			if ( ! count( array_filter( $row ) ) ) {
				continue; // WC skips empty rows.
			}
			$data = array();
			foreach ( $row as $i => $value ) {
				if ( empty( $mapped[ $i ] ) ) {
					continue;
				}
				$key          = $mapped[ $i ];
				$data[ $key ] = Columns::is_ours( $key ) ? Cells::unescape( (string) $value ) : (string) $value;
			}
			$rows[] = $data;
		}
		fclose( $handle );
		return $rows;
	}

	/**
	 * WC_Product_CSV_Importer_Controller::auto_map_columns() with only our mappings registered.
	 *
	 * @param string[] $headers Raw headers.
	 * @return string[] Mapped keys by column index.
	 */
	public static function auto_map( array $headers ): array {
		$default = array_change_key_case( Columns::default_mapping() + array( 'sku' => 'sku' ), CASE_LOWER );
		$special = array();
		foreach ( array_change_key_case( Columns::special_mapping(), CASE_LOWER ) as $pattern => $key ) {
			$special[ '/' . str_replace( array( '%d', '%s' ), '(.*)', trim( quotemeta( $pattern ) ) ) . '/i' ] = $key;
		}

		$out = array();
		foreach ( $headers as $i => $field ) {
			$normalized = strtolower( $field );
			$out[ $i ]  = $normalized;
			if ( isset( $default[ $normalized ] ) ) {
				$out[ $i ] = $default[ $normalized ];
				continue;
			}
			foreach ( $special as $regex => $key ) {
				if ( preg_match( $regex, $field, $m ) ) {
					$out[ $i ] = $key . $m[1];
					break;
				}
			}
		}
		return $out;
	}
}
