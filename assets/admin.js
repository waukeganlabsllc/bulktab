/**
 * BulkTab admin: product "Tabs" panel (order list, add rows) and the
 * shared-tab "Show this tab on" box.
 */
( function ( $ ) {
	'use strict';

	var l10n = window.bulktabAdmin || {};

	/* ---------------------------------------------------------------- */
	/* Product panel: tab order                                         */
	/* ---------------------------------------------------------------- */

	function writeOrder( $list ) {
		var refs = $list.children( 'li' ).map( function () {
			return $( this ).attr( 'data-ref' );
		} ).get();
		$( '#bulktab_order' ).val( refs.join( ',' ) );
		$( '.bulktab-order-status' ).text( '' );
	}

	function initOrder() {
		var $list = $( '#bulktab-order-list' );
		if ( ! $list.length ) {
			return;
		}

		if ( $.fn.sortable ) {
			$list.sortable( {
				items: '> li',
				handle: '.bulktab-handle',
				axis: 'y',
				placeholder: 'bulktab-order-placeholder',
				update: function () {
					writeOrder( $list );
				}
			} );
		}

		$list.on( 'click', '.bulktab-move', function ( e ) {
			var $btn = $( this ),
				$li = $btn.closest( 'li' ),
				dir = $btn.attr( 'data-dir' );

			e.preventDefault();
			if ( 'up' === dir && $li.prev( 'li' ).length ) {
				$li.insertBefore( $li.prev( 'li' ) );
			} else if ( 'down' === dir && $li.next( 'li' ).length ) {
				$li.insertAfter( $li.next( 'li' ) );
			} else {
				return;
			}
			writeOrder( $list );
			$li.find( '.bulktab-move[data-dir="' + dir + '"]' ).trigger( 'focus' );
		} );

		$( '.bulktab-order-reset' ).on( 'click', function ( e ) {
			e.preventDefault();
			$( '#bulktab_order' ).val( '' );
			$( '.bulktab-order-status' ).text( l10n.defaultOrder || '' );
		} );
	}

	/* ---------------------------------------------------------------- */
	/* Product panel: "Add another" new-tab rows                        */
	/* ---------------------------------------------------------------- */

	function initNewRows() {
		var $wrap = $( '.bulktab-new' ),
			tmpl = $( '#tmpl-bulktab-new-row' ).html(),
			next = 1;

		if ( ! $wrap.length || ! tmpl ) {
			return;
		}

		$wrap.on( 'click', '.bulktab-add-row', function ( e ) {
			var remaining = parseInt( $wrap.attr( 'data-remaining' ), 10 ) || 0,
				$rows = $wrap.find( '.bulktab-new-rows' ),
				$row;

			e.preventDefault();
			if ( $rows.children( '.bulktab-new-row' ).length >= remaining ) {
				$( this ).prop( 'disabled', true );
				return;
			}
			$row = $( tmpl.replace( /__i__/g, String( next++ ) ) );
			$rows.append( $row );
			$row.find( 'input[type="text"]' ).trigger( 'focus' );
			if ( $rows.children( '.bulktab-new-row' ).length >= remaining ) {
				$( this ).prop( 'disabled', true ).after(
					$( '<span class="description bulktab-limit" />' ).text( ' ' + ( l10n.limit || '' ) )
				);
			}
		} );
	}

	/* ---------------------------------------------------------------- */
	/* Shared tab: "Show this tab on" box                               */
	/* ---------------------------------------------------------------- */

	function initRulesBox() {
		var $box = $( '#bulktab-rules' ),
			$tax,
			$taxRow;

		if ( ! $box.length ) {
			return;
		}
		$tax = $box.find( 'select[name="bulktab_tax"]' );
		$taxRow = $tax.closest( 'p' );

		function sync() {
			var mode = $box.find( 'input[name="bulktab_mode"]:checked' ).val(),
				isTerms = 'terms' === mode,
				tax = $tax.val();

			$taxRow.toggle( isTerms );
			$box.find( '.bulktab-terms' ).each( function () {
				var $p = $( this );
				$p.toggle( isTerms && $p.attr( 'data-tax' ) === tax );
			} );
		}

		$box.on( 'change', 'input[name="bulktab_mode"], select[name="bulktab_tax"]', sync );
		sync();
	}

	$( function () {
		initOrder();
		initNewRows();
		initRulesBox();
	} );
}( jQuery ) );
