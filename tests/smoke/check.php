<?php
/**
 * Deterministic acceptance checks against a live Playground store.
 * Usage: php tests/smoke/check.php <out-dir> <base-url>
 * Prints PASS/FAIL per requirement; exit code 1 on any failure.
 *
 * @package BulkTab
 */

// phpcs:ignoreFile -- dev-only test runner, never shipped (see .distignore).

$dir  = rtrim( $argv[1] ?? '/home/claude/smoke-out', '/' );
$base = rtrim( $argv[2] ?? 'http://127.0.0.1:9400', '/' );
$ids  = json_decode( (string) file_get_contents( "$dir/ids.json" ), true );
if ( ! is_array( $ids ) ) {
	fwrite( STDERR, "No fixture output at $dir/ids.json (setup failed?)\n" );
	exit( 2 );
}

/** Fetch a product page (Playground sets a cookie on first hit). */
function page( string $base, string $dir, int $id ): string {
	$jar = escapeshellarg( "$dir/jar.txt" );
	$url = escapeshellarg( "$base/?post_type=product&p=$id" );
	return html_entity_decode( (string) shell_exec( "curl -sS -L --max-redirs 5 --max-time 120 -c $jar -b $jar $url" ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
}

/** Tab titles in page order: key => title. */
function tabs( string $html ): array {
	preg_match_all( '/<li[^>]*class="([a-z0-9_\-]+)_tab\b[^"]*"[^>]*>\s*<a[^>]*>(.*?)<\/a>/s', $html, $m, PREG_SET_ORDER );
	$out = array();
	foreach ( $m as $row ) {
		$out[] = trim( strip_tags( $row[2] ) );
	}
	return $out;
}

$results = array();
function check( string $req, string $what, bool $ok, $detail = '' ): void {
	global $results;
	$results[] = $ok;
	printf( "%s  %-5s %s%s\n", $ok ? 'PASS' : 'FAIL', $req, $what, $ok || '' === $detail ? '' : '  -> ' . ( is_string( $detail ) ? $detail : json_encode( $detail, JSON_UNESCAPED_UNICODE ) ) );
}

$boot = page( $base, $dir, (int) $ids['boot'] );
$hat  = page( $base, $dir, (int) $ids['hat'] );
$bt   = tabs( $boot );
$ht   = tabs( $hat );

// Problem 2: write once, apply to many.
check( 'R-15', 'Shared tab by parent category shows on sub-category product', in_array( 'Size guide', $bt, true ), $bt );
check( 'R-15', 'Category tab does not show on product outside it', ! in_array( 'Size guide', $ht, true ), $ht );
check( 'R-13', '"All products" shared tab shows on a product', in_array( 'Shipping', $bt, true ), $bt );
check( 'R-13', 'Manual-only shared tab is not shown unless attached', ! in_array( 'Gift wrap', $bt, true ), $bt );
check( 'R-13', 'Attached shared tab shows on that product', in_array( 'Gift wrap', $ht, true ), $ht );
check( 'R-12', 'Shared tab table content renders', false !== strpos( $boot, '<td>EU 42</td>' ) );
check( 'R-11', 'Product tab shows with its title', in_array( 'Care', $bt, true ), $bt );
check( 'R-12', 'Content with backslashes/quotes/unicode/emoji intact', false !== strpos( $boot, 'Wash at C:\\cold \\' ) && false !== strpos( $boot, '30°' ) && false !== strpos( $boot, '👟' ) );
check( 'R-12', 'Shortcode inside tab content is executed', false === strpos( $boot, '[bulktab_tab key="shipping"]' ) );
check( 'R-31', 'Undo restored the previous content', ! empty( $ids['undo_ok'] ) && false === strpos( $boot, 'oops' ) );

// Problem 4: control.
check( 'R-29', 'Empty shared tab hidden', ! in_array( 'Empty tab', $bt, true ), $bt );
check( 'R-29', 'Empty product tab hidden', ! in_array( 'Blank', $bt, true ), $bt );
check( 'R-27', 'Store-wide rename Description -> Details', in_array( 'Details', $bt, true ) && in_array( 'Details', $ht, true ), array( $bt, $ht ) );
check( 'R-28', 'Per-product hide of a shared tab', ! in_array( 'Shipping', $ht, true ), $ht );
check( 'R-28', 'Per-product hide of Reviews', ! preg_grep( '/^Reviews/', $ht ), $ht );
check( 'R-28', 'Hide on one product leaves others alone', (bool) preg_grep( '/^Reviews/', $bt ), $bt );
check( 'R-21', 'Tabs render in the default block theme', count( $bt ) >= 4, $bt );

// YIKES import (fixture must exist; a missing fixture is a failure, not a skip).
check( 'R-6', 'YIKES fixture ran', isset( $ids['mug'], $ids['yikes_run'] ) );
if ( isset( $ids['mug'] ) ) {
	$mug = tabs( page( $base, $dir, (int) $ids['mug'] ) );
	$dry = $ids['yikes_dry'] ?? array();
	$run = $ids['yikes_run'] ?? array();
	check( 'R-6', 'Dry run finished', 'done' === ( $dry['status'] ?? '' ), $dry['status'] ?? null );
	check( 'R-6', 'Dry run wrote nothing', $ids['ours_before_import'] === $ids['ours_after_dry'], array( $ids['ours_before_import'], $ids['ours_after_dry'] ) );
	check( 'R-6', 'Import finished', 'done' === ( $run['status'] ?? '' ), $run['status'] ?? null );
	check( 'R-6', 'Import created the saved tab and the product tab', $ids['ours_after_run']['shared'] > $ids['ours_after_dry']['shared'] && $ids['ours_after_run']['slots'] >= 1, $ids['ours_after_run'] );
	check( 'R-7', 'Re-running the import creates no duplicates', $ids['ours_after_run'] === $ids['ours_after_rerun'], array( $ids['ours_after_run'], $ids['ours_after_rerun'] ) );
	check( 'R-7', 'YIKES data unchanged', $ids['yikes_hash_before'] === $ids['yikes_hash_after'] );
	$counts = array_count_values( $mug );
	check( 'R-8', 'With YIKES active, each tab shows once', 1 === ( $counts['Material'] ?? 0 ) && 1 === ( $counts['Returns'] ?? 0 ), $mug );
}

// CSV round trip (fixture must exist).
check( 'R-2', 'CSV fixture ran', isset( $ids['shirt'], $ids['csv_result'] ) );
if ( isset( $ids['shirt'] ) ) {
	check( 'R-25', 'Export has readable BulkTab columns', in_array( 'BulkTab 1 content', $ids['csv_headers'] ?? array(), true ), $ids['csv_headers'] ?? null );
	check( 'R-25', 'Exported cell is the plain content', 'Cotton' === ( $ids['csv_exported'] ?? null ), $ids['csv_exported'] ?? null );
	check( 'R-2', 'Import of edited CSV succeeded', 1 === ( $ids['csv_result']['updated'] ?? 0 ) && 0 === ( $ids['csv_result']['failed'] ?? 1 ), $ids['csv_result'] ?? null );
	check( 'R-2', 'Edited content stored exactly', ( $ids['csv_edit'] ?? 'x' ) === ( $ids['csv_after'] ?? 'y' ), $ids['csv_after'] ?? null );
	$shirt = page( $base, $dir, (int) $ids['shirt'] );
	check( 'R-2', 'Edited content shows on the product page', false !== strpos( $shirt, 'pre-washed' ) && false !== strpos( $shirt, '<strong>cotton</strong>' ) );
}

// Pro checks live in the Pro repo: pass its path as the 3rd argument.
$pro_dir = $argv[3] ?? '';
if ( '' !== $pro_dir ) {
	if ( is_file( "$dir/pro.json" ) ) {
		require rtrim( $pro_dir, '/' ) . '/tests/smoke/check-pro.php';
	} else {
		check( 'PRO', 'Pro fixture ran', false, 'no pro.json' );
	}
}

$log = is_file( "$dir/debug.log" ) ? (string) file_get_contents( "$dir/debug.log" ) : '';
preg_match_all( '/PHP (Fatal error|Warning|Notice|Deprecated):[^\n]*bulktab[^\n]*/i', $log, $errs );
check( 'N-5', 'No PHP errors/warnings from BulkTab code', ! $errs[0], array_slice( array_unique( $errs[0] ), 0, 5 ) );

$failed = count( array_filter( $results, static function ( $r ) { return ! $r; } ) );
printf( "\n%d checks, %d failed\n", count( $results ), $failed );
exit( $failed ? 1 : 0 );
