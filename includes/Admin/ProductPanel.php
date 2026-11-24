<?php
/**
 * "Tabs" panel on the classic product editor: shared tabs (attach / hide),
 * this product's own tabs (slots 1..20 with one-step undo), and tab order.
 *
 * Saves only when this panel's nonce is posted, so quick edit, bulk edit and
 * REST saves never touch tab data.
 *
 * @package BulkTab
 */

namespace BulkTab\Admin;

use BulkTab\Content;
use BulkTab\Layout;
use BulkTab\MetaStore;
use BulkTab\PostType;
use BulkTab\ProductContext;
use BulkTab\RulesIndex;
use BulkTab\TabMatcher;
use BulkTab\TabStore;

defined( 'ABSPATH' ) || exit;

/**
 * Product data "Tabs" panel.
 */
final class ProductPanel {

	const NONCE  = 'bulktab_product_nonce';
	const TARGET = 'bulktab_product_data';

	/**
	 * Tab storage.
	 *
	 * @var TabStore
	 */
	private $store;

	/**
	 * Constructor.
	 *
	 * @param MetaStore $meta Meta storage.
	 */
	public function __construct( MetaStore $meta ) {
		$this->store = new TabStore( $meta );
	}

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'render' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Add the "Tabs" product data tab.
	 *
	 * @param array $tabs Product data tabs.
	 * @return array
	 */
	public function add_tab( $tabs ): array {
		$tabs            = is_array( $tabs ) ? $tabs : array();
		$tabs['bulktab'] = array(
			'label'    => __( 'Tabs', 'bulktab' ),
			'target'   => self::TARGET,
			'class'    => array(),
			'priority' => 75,
		);
		return $tabs;
	}

	/**
	 * Assets for the product editor and the shared-tab editor (rules box).
	 *
	 * @param string $hook_suffix Admin page hook.
	 */
	public function enqueue( $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->post_type, array( 'product', PostType::NAME ), true ) ) {
			return;
		}
		$base = defined( 'BULKTAB_URL' ) ? BULKTAB_URL : plugin_dir_url( dirname( __DIR__ ) . '/bulktab.php' );
		$ver  = defined( 'BULKTAB_VERSION' ) ? BULKTAB_VERSION : '0';
		wp_enqueue_style( 'bulktab-admin', $base . 'assets/admin.css', array(), $ver );
		wp_enqueue_script( 'bulktab-admin', $base . 'assets/admin.js', array( 'jquery', 'jquery-ui-sortable' ), $ver, true );
		wp_localize_script(
			'bulktab-admin',
			'bulktabAdmin',
			array(
				'defaultOrder' => __( 'Default order will be used after you update the product.', 'bulktab' ),
				'limit'        => __( 'This product has the maximum number of tabs.', 'bulktab' ),
			)
		);
	}

	// Render.

	/**
	 * Print the panel (woocommerce_product_data_panels).
	 */
	public function render(): void {
		global $post;
		$product_id = ( $post instanceof \WP_Post ) ? (int) $post->ID : 0;

		$tabs     = $product_id ? $this->store->get_tabs( $product_id ) : array();
		$attached = $product_id ? $this->store->get_list( $product_id, '_bulktab_attach' ) : array();
		$hidden   = $product_id ? $this->store->get_list( $product_id, '_bulktab_hide' ) : array();
		$order    = $product_id ? $this->store->get_list( $product_id, '_bulktab_order' ) : array();
		$index    = RulesIndex::get();
		$ctx      = ProductContext::for_product( $product_id );
		?>
		<div id="<?php echo esc_attr( self::TARGET ); ?>" class="panel woocommerce_options_panel bulktab-panel hidden">
			<?php wp_nonce_field( self::NONCE, self::NONCE ); ?>

			<?php $this->render_shared( $index, $ctx, $attached, $hidden ); ?>
			<?php $this->render_own( $tabs, $hidden ); ?>
			<?php $this->render_order( $index, $ctx, $attached, $hidden, $order, $tabs ); ?>

			<?php
			/**
			 * Fires at the end of the product "Tabs" panel.
			 *
			 * @param int $product_id Product ID.
			 */
			do_action( 'bulktab_product_panel_after', $product_id );
			?>
		</div>
		<?php
	}

	/**
	 * Shared tabs table: attach / hide per product.
	 *
	 * @param array    $index    RulesIndex::get().
	 * @param array    $ctx      ProductContext::for_product().
	 * @param string[] $attached Attached shared-tab keys.
	 * @param string[] $hidden   Hidden refs.
	 */
	private function render_shared( array $index, array $ctx, array $attached, array $hidden ): void {
		?>
		<div class="bulktab-section bulktab-shared">
			<h3><?php esc_html_e( 'Shared tabs', 'bulktab' ); ?></h3>
			<?php if ( ! $index ) : ?>
				<p class="description"><?php esc_html_e( 'No shared tabs yet. Shared tabs let you write a tab once and show it on many products.', 'bulktab' ); ?></p>
			<?php else : ?>
				<table class="widefat striped bulktab-shared-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Tab', 'bulktab' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Rule', 'bulktab' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Attach to this product', 'bulktab' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Hide on this product', 'bulktab' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php
					foreach ( $index as $key => $entry ) :
						$key     = (string) $key;
						$post_id = (int) ( $entry['post_id'] ?? 0 );
						$title   = $post_id ? get_the_title( $post_id ) : '';
						$title   = '' !== $title ? $title : $key;
						$link    = $post_id ? get_edit_post_link( $post_id ) : '';
						$by_rule = isset( $entry['rules'] ) && is_array( $entry['rules'] ) && TabMatcher::matches( $entry['rules'], $ctx );
						$field   = 'bulktab_shared_' . sanitize_html_class( $key );
						?>
						<tr>
							<td>
								<input type="hidden" name="bulktab_shared_seen[]" value="<?php echo esc_attr( $key ); ?>" />
								<?php if ( $link ) : ?>
									<a href="<?php echo esc_url( $link ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $title ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $title ); ?>
								<?php endif; ?>
							</td>
							<td>
								<span class="bulktab-badge <?php echo $by_rule ? 'bulktab-badge-on' : 'bulktab-badge-off'; ?>">
									<?php echo $by_rule ? esc_html__( 'Shows here by rule', 'bulktab' ) : esc_html__( 'Not shown by rule', 'bulktab' ); ?>
								</span>
							</td>
							<td>
								<label>
									<input type="checkbox" id="<?php echo esc_attr( $field . '_attach' ); ?>" name="bulktab_attach[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $attached, true ) ); ?> />
									<span class="screen-reader-text">
										<?php
										/* translators: %s: shared tab title */
										echo esc_html( sprintf( __( 'Attach "%s" to this product', 'bulktab' ), $title ) );
										?>
									</span>
								</label>
							</td>
							<td>
								<label>
									<input type="checkbox" id="<?php echo esc_attr( $field . '_hide' ); ?>" name="bulktab_hide[]" value="<?php echo esc_attr( 's:' . $key ); ?>" <?php checked( in_array( 's:' . $key, $hidden, true ) ); ?> />
									<span class="screen-reader-text">
										<?php
										/* translators: %s: shared tab title */
										echo esc_html( sprintf( __( 'Hide "%s" on this product', 'bulktab' ), $title ) );
										?>
									</span>
								</label>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( 'Editing a shared tab changes it on every product that shows it. Hiding wins over attaching.', 'bulktab' ); ?></p>
			<?php endif; ?>
			<p><a class="button" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . PostType::NAME ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Add shared tab', 'bulktab' ); ?></a></p>
		</div>
		<?php
	}

	/**
	 * This product's own tabs and the "Add a tab" rows.
	 *
	 * @param array    $tabs   TabStore::get_tabs().
	 * @param string[] $hidden Hidden refs.
	 */
	private function render_own( array $tabs, array $hidden ): void {
		$remaining = TabStore::MAX_TABS - count( $tabs );
		?>
		<div class="bulktab-section bulktab-own">
			<h3><?php esc_html_e( "This product's tabs", 'bulktab' ); ?></h3>
			<?php if ( ! $tabs ) : ?>
				<p class="description"><?php esc_html_e( 'No tabs for this product only yet.', 'bulktab' ); ?></p>
			<?php endif; ?>

			<?php
			foreach ( $tabs as $slot => $tab ) :
				$slot = (int) $slot;
				$base = 'bulktab_tabs[' . $slot . ']';
				$id   = 'bulktab_tab_' . $slot;
				?>
				<div class="bulktab-tab" data-slot="<?php echo esc_attr( (string) $slot ); ?>">
					<p class="bulktab-field">
						<label for="<?php echo esc_attr( $id . '_title' ); ?>"><?php esc_html_e( 'Title', 'bulktab' ); ?></label>
						<input type="text" class="widefat" id="<?php echo esc_attr( $id . '_title' ); ?>" name="<?php echo esc_attr( $base . '[title]' ); ?>" value="<?php echo esc_attr( $tab['title'] ); ?>" />
					</p>
					<div class="bulktab-field bulktab-editor">
						<?php $this->editor( $tab['content'], 'bulktab_content_' . $slot, $base . '[content]' ); ?>
					</div>
					<p class="bulktab-tab-actions">
						<label><input type="checkbox" name="<?php echo esc_attr( $base . '[hide]' ); ?>" value="1" <?php checked( in_array( 'c:' . $slot, $hidden, true ) ); ?> /> <?php esc_html_e( 'Hide', 'bulktab' ); ?></label>
						<label class="bulktab-delete"><input type="checkbox" name="<?php echo esc_attr( $base . '[delete]' ); ?>" value="1" /> <?php esc_html_e( 'Delete tab', 'bulktab' ); ?></label>
						<?php if ( $tab['has_prev'] ) : ?>
							<label><input type="checkbox" name="<?php echo esc_attr( $base . '[restore]' ); ?>" value="1" /> <?php esc_html_e( 'Restore previous version', 'bulktab' ); ?></label>
						<?php endif; ?>
						<span class="bulktab-ref"><?php esc_html_e( 'Tab ID:', 'bulktab' ); ?> <code><?php echo esc_html( 'c:' . $slot ); ?></code></span>
					</p>
				</div>
			<?php endforeach; ?>

			<div class="bulktab-new" data-remaining="<?php echo esc_attr( (string) max( 0, $remaining ) ); ?>">
				<h4><?php esc_html_e( 'Add a tab', 'bulktab' ); ?></h4>
				<?php if ( $remaining > 0 ) : ?>
					<div class="bulktab-new-rows">
						<?php $this->new_row( '0' ); ?>
					</div>
					<?php if ( $remaining > 1 ) : ?>
						<p><button type="button" class="button bulktab-add-row"><?php esc_html_e( 'Add another', 'bulktab' ); ?></button></p>
					<?php endif; ?>
					<script type="text/html" id="tmpl-bulktab-new-row"><?php $this->new_row( '__i__' ); ?></script>
					<p class="description"><?php esc_html_e( 'Rows left empty are ignored. Tabs with no content are not shown on the storefront.', 'bulktab' ); ?></p>
				<?php else : ?>
					<p class="description">
						<?php
						/* translators: %d: maximum number of tabs per product */
						echo esc_html( sprintf( __( 'This product has the maximum of %d tabs. Delete one to add another.', 'bulktab' ), TabStore::MAX_TABS ) );
						?>
					</p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Blank "new tab" row with a plain textarea (clonable by JS).
	 *
	 * @param string $i Row index or the "__i__" template placeholder.
	 */
	private function new_row( string $i ): void {
		$base = 'bulktab_new[' . $i . ']';
		$id   = 'bulktab_new_' . $i;
		?>
		<div class="bulktab-new-row">
			<p class="bulktab-field">
				<label for="<?php echo esc_attr( $id . '_title' ); ?>"><?php esc_html_e( 'Title', 'bulktab' ); ?></label>
				<input type="text" class="widefat" id="<?php echo esc_attr( $id . '_title' ); ?>" name="<?php echo esc_attr( $base . '[title]' ); ?>" value="" />
			</p>
			<p class="bulktab-field">
				<label for="<?php echo esc_attr( $id . '_content' ); ?>"><?php esc_html_e( 'Content', 'bulktab' ); ?></label>
				<textarea class="widefat" rows="6" id="<?php echo esc_attr( $id . '_content' ); ?>" name="<?php echo esc_attr( $base . '[content]' ); ?>"></textarea>
			</p>
		</div>
		<?php
	}

	/**
	 * Rich editor for an existing tab (textarea fallback).
	 *
	 * @param string $content   Tab HTML.
	 * @param string $editor_id Editor element ID.
	 * @param string $name      Field name.
	 */
	private function editor( string $content, string $editor_id, string $name ): void {
		if ( function_exists( 'wp_editor' ) ) {
			wp_editor(
				$content,
				$editor_id,
				array(
					'textarea_name' => $name,
					'textarea_rows' => 8,
					'teeny'         => true,
					'media_buttons' => true,
				)
			);
			return;
		}
		printf(
			'<textarea class="widefat" rows="8" id="%1$s" name="%2$s">%3$s</textarea>',
			esc_attr( $editor_id ),
			esc_attr( $name ),
			esc_textarea( $content )
		);
	}

	/**
	 * Sortable tab order list.
	 *
	 * @param array    $index    RulesIndex::get().
	 * @param array    $ctx      ProductContext::for_product().
	 * @param string[] $attached Attached shared-tab keys.
	 * @param string[] $hidden   Hidden refs.
	 * @param string[] $order    Saved order (refs).
	 * @param array    $tabs     TabStore::get_tabs().
	 */
	private function render_order( array $index, array $ctx, array $attached, array $hidden, array $order, array $tabs ): void {
		$items = $this->order_items( $index, $ctx, $attached, $hidden, $order, $tabs );
		?>
		<div class="bulktab-section bulktab-order">
			<h3><?php esc_html_e( 'Tab order', 'bulktab' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Drag to change the order on this product, or use the arrow buttons. New tabs are added at the end.', 'bulktab' ); ?></p>
			<input type="hidden" id="bulktab_order" name="bulktab_order" value="<?php echo esc_attr( implode( ',', $order ) ); ?>" />
			<ul id="bulktab-order-list" class="bulktab-order-list">
				<?php foreach ( $items as $item ) : ?>
					<li class="bulktab-order-item<?php echo $item['hidden'] ? ' is-hidden' : ''; ?>" data-ref="<?php echo esc_attr( $item['ref'] ); ?>">
						<span class="dashicons dashicons-menu bulktab-handle" aria-hidden="true"></span>
						<span class="bulktab-order-label"><?php echo esc_html( $item['label'] ); ?></span>
						<span class="bulktab-order-type"><?php echo esc_html( $item['type'] ); ?></span>
						<?php if ( $item['hidden'] ) : ?>
							<span class="bulktab-order-type"><?php esc_html_e( '(hidden)', 'bulktab' ); ?></span>
						<?php endif; ?>
						<span class="bulktab-order-buttons">
							<button type="button" class="button-link bulktab-move" data-dir="up">
								<span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span>
								<span class="screen-reader-text">
									<?php
									/* translators: %s: tab title */
									echo esc_html( sprintf( __( 'Move %s up', 'bulktab' ), $item['label'] ) );
									?>
								</span>
							</button>
							<button type="button" class="button-link bulktab-move" data-dir="down">
								<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
								<span class="screen-reader-text">
									<?php
									/* translators: %s: tab title */
									echo esc_html( sprintf( __( 'Move %s down', 'bulktab' ), $item['label'] ) );
									?>
								</span>
							</button>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
			<p>
				<button type="button" class="button bulktab-order-reset"><?php esc_html_e( 'Use default order', 'bulktab' ); ?></button>
				<span class="bulktab-order-status" aria-live="polite"><?php echo $order ? '' : esc_html__( 'Using the default order.', 'bulktab' ); ?></span>
			</p>
		</div>
		<?php
	}

	/**
	 * Refs this product will show, sorted by saved order (if any) else default priority.
	 *
	 * @param array    $index    RulesIndex::get().
	 * @param array    $ctx      ProductContext::for_product().
	 * @param string[] $attached Attached shared-tab keys.
	 * @param string[] $hidden   Hidden refs.
	 * @param string[] $order    Saved order (refs).
	 * @param array    $tabs     TabStore::get_tabs().
	 * @return array<int, array{ref:string,label:string,type:string,hidden:bool}>
	 */
	private function order_items( array $index, array $ctx, array $attached, array $hidden, array $order, array $tabs ): array {
		$global = get_option( 'bulktab_layout', array() );
		$global = is_array( $global ) ? $global : array();
		$items  = array();

		$builtins = array(
			'description'            => array( __( 'Description', 'bulktab' ), 10 ),
			'additional_information' => array( __( 'Additional information', 'bulktab' ), 20 ),
			'reviews'                => array( __( 'Reviews', 'bulktab' ), 30 ),
		);
		foreach ( $builtins as $ref => $def ) {
			$g = isset( $global[ $ref ] ) && is_array( $global[ $ref ] ) ? $global[ $ref ] : array();
			if ( ! empty( $g['hide'] ) ) {
				continue;
			}
			$items[ $ref ] = array(
				'ref'      => $ref,
				'label'    => isset( $g['title'] ) && '' !== trim( (string) $g['title'] ) ? (string) $g['title'] : $def[0],
				'type'     => __( 'WooCommerce', 'bulktab' ),
				'hidden'   => in_array( $ref, $hidden, true ),
				'priority' => isset( $g['priority'] ) && is_numeric( $g['priority'] ) ? (int) $g['priority'] : $def[1],
			);
		}

		foreach ( TabMatcher::resolve( $index, $ctx, $attached, array() ) as $key ) {
			$ref     = 's:' . $key;
			$post_id = (int) ( $index[ $key ]['post_id'] ?? 0 );
			$title   = $post_id ? get_the_title( $post_id ) : '';
			$g       = isset( $global[ $ref ] ) && is_array( $global[ $ref ] ) ? $global[ $ref ] : array();
			if ( ! empty( $g['hide'] ) ) {
				continue;
			}
			$items[ $ref ] = array(
				'ref'      => $ref,
				'label'    => '' !== $title ? $title : $key,
				'type'     => __( 'Shared', 'bulktab' ),
				'hidden'   => in_array( $ref, $hidden, true ),
				'priority' => isset( $g['priority'] ) && is_numeric( $g['priority'] ) ? (int) $g['priority'] : (int) ( $index[ $key ]['priority'] ?? 25 ),
			);
		}

		foreach ( $tabs as $slot => $tab ) {
			$ref           = 'c:' . (int) $slot;
			$items[ $ref ] = array(
				'ref'      => $ref,
				'label'    => '' !== $tab['title'] ? $tab['title'] : __( 'More info', 'bulktab' ),
				'type'     => __( 'This product', 'bulktab' ),
				'hidden'   => in_array( $ref, $hidden, true ),
				'priority' => 40 + (int) $slot,
			);
		}

		// Keep refs from the saved order we can't resolve here (other plugins' tabs), so saving doesn't drop them.
		foreach ( $order as $ref ) {
			if ( ! isset( $items[ $ref ] ) && 0 === strpos( $ref, 'x:' ) ) {
				$items[ $ref ] = array(
					'ref'      => $ref,
					'label'    => substr( $ref, 2 ),
					'type'     => __( 'Other plugin', 'bulktab' ),
					'hidden'   => in_array( $ref, $hidden, true ),
					'priority' => 50,
				);
			}
		}

		// Listed refs first (in saved order), then unlisted by priority, then original order.
		// Mirrors Layout::apply(); explicit tie-breaks because usort isn't stable on PHP 7.4.
		$position = array_flip( array_values( $order ) );
		$i        = 0;
		foreach ( $items as $ref => $item ) {
			$listed                = isset( $position[ $ref ] );
			$items[ $ref ]['sort'] = array( $listed ? 0 : 1, $listed ? $position[ $ref ] : (int) $item['priority'], $i++ );
		}
		uasort(
			$items,
			static function ( $a, $b ) {
				return $a['sort'] <=> $b['sort'];
			}
		);
		return array_values( $items );
	}

	// Save.

	/**
	 * Save the panel (woocommerce_process_product_meta).
	 *
	 * @param int $post_id Product ID.
	 */
	public function save( $post_id ): void {
		// No nonce = not saved from this panel (quick edit, REST, imports): leave tabs alone.
		if ( ! isset( $_POST[ self::NONCE ] ) ) {
			return;
		}
		$post_id = (int) $post_id;
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			Notices::add( __( 'Tabs were not saved because the page expired. Reload the product and try again.', 'bulktab' ) );
			return;
		}
		if ( ! current_user_can( 'edit_product', $post_id ) ) {
			return;
		}

		$before = $this->store->get_tabs( $post_id );
		$errors = array();

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is sanitized below.
		$posted   = isset( $_POST['bulktab_tabs'] ) && is_array( $_POST['bulktab_tabs'] ) ? wp_unslash( $_POST['bulktab_tabs'] ) : array();
		$new_rows = isset( $_POST['bulktab_new'] ) && is_array( $_POST['bulktab_new'] ) ? wp_unslash( $_POST['bulktab_new'] ) : array();
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$deleted    = array();
		$rendered   = array(); // Existing slots that were on the form.
		$hide_slots = array();
		$save       = array();
		$orphans    = array();

		foreach ( $posted as $slot => $fields ) {
			$slot = (int) $slot;
			if ( $slot < 1 || $slot > TabStore::MAX_TABS || ! is_array( $fields ) ) {
				continue;
			}
			$title   = sanitize_text_field( (string) ( $fields['title'] ?? '' ) );
			$content = Content::sanitize_for_storage( (string) ( $fields['content'] ?? '' ) );

			if ( ! isset( $before[ $slot ] ) ) {
				// Removed elsewhere since the page loaded: keep the text as a new tab rather than lose it.
				if ( empty( $fields['delete'] ) && ( '' !== $title || '' !== trim( $content ) ) ) {
					$orphans[] = array(
						'title'   => $title,
						'content' => $content,
					);
				}
				continue;
			}

			if ( ! empty( $fields['delete'] ) ) {
				$this->store->delete_tab( $post_id, $slot );
				$deleted[] = 'c:' . $slot;
				continue;
			}

			$rendered[] = 'c:' . $slot;
			if ( ! empty( $fields['hide'] ) ) {
				$hide_slots[] = 'c:' . $slot;
			}

			if ( ! empty( $fields['restore'] ) ) {
				if ( $this->store->undo( $post_id, $slot ) ) {
					$current = $this->store->get_tabs( $post_id );
					$content = isset( $current[ $slot ] ) ? $current[ $slot ]['content'] : $content;
				} else {
					/* translators: %s: tab title */
					$errors[] = sprintf( __( 'The previous version of "%s" could not be restored.', 'bulktab' ), '' !== $title ? $title : $slot );
				}
			} elseif ( self::same_content( $before[ $slot ]['content'], $content ) ) {
				// Editor round-trips (TinyMCE / wpautop) must not count as an edit or the undo copy is lost.
				$content = $before[ $slot ]['content'];
			}

			$save[ $slot ] = array(
				'title'   => $title,
				'content' => $content,
			);
		}

		if ( $save ) {
			$errors = array_merge( $errors, $this->store->save_tabs( $post_id, $save ) );
		}

		foreach ( array_merge( $orphans, array_values( $new_rows ) ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$title   = sanitize_text_field( (string) ( $row['title'] ?? '' ) );
			$content = Content::sanitize_for_storage( (string) ( $row['content'] ?? '' ) );
			if ( '' === $title && '' === trim( $content ) ) {
				continue;
			}
			$errors = array_merge(
				$errors,
				$this->store->save_tabs(
					$post_id,
					array(
						0 => array(
							'title'   => $title,
							'content' => $content,
						),
					)
				)
			);
		}

		$errors = array_merge( $errors, $this->save_lists( $post_id, $rendered, $hide_slots, $deleted ) );

		$after = $this->store->get_tabs( $post_id );

		foreach ( $errors as $message ) {
			Notices::add( (string) $message );
		}

		/**
		 * Fires after a product's tabs are saved from the product editor.
		 *
		 * @param int   $product_id Product ID.
		 * @param array $before     TabStore::get_tabs() before saving.
		 * @param array $after      TabStore::get_tabs() after saving.
		 */
		do_action( 'bulktab_product_tabs_saved', $post_id, $before, $after );
	}

	/**
	 * Attach / hide / order lists. Refs not managed by the form are kept.
	 *
	 * @param int      $post_id    Product ID.
	 * @param string[] $rendered   Own-tab refs that were on the form (not deleted).
	 * @param string[] $hide_slots Own-tab refs ticked "Hide".
	 * @param string[] $deleted    Own-tab refs deleted in this save.
	 * @return string[] Errors.
	 */
	private function save_lists( int $post_id, array $rendered, array $hide_slots, array $deleted ): array {
		$errors = array();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in save().
		$seen       = isset( $_POST['bulktab_shared_seen'] ) ? Layout::parse_list( array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['bulktab_shared_seen'] ) ) ) : array();
		$attach_in  = isset( $_POST['bulktab_attach'] ) ? Layout::parse_list( array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['bulktab_attach'] ) ) ) : array();
		$hide_in    = isset( $_POST['bulktab_hide'] ) ? Layout::parse_list( array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['bulktab_hide'] ) ) ) : array();
		$order_post = isset( $_POST['bulktab_order'] ) ? sanitize_text_field( wp_unslash( $_POST['bulktab_order'] ) ) : null;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Attach: keep keys not shown on the form, replace the ones that were.
		$attach = array_merge(
			array_diff( $this->store->get_list( $post_id, '_bulktab_attach' ), $seen ),
			array_intersect( $attach_in, $seen )
		);
		if ( ! $this->store->set_list( $post_id, '_bulktab_attach', $attach ) ) {
			$errors[] = __( 'Attached shared tabs were not saved.', 'bulktab' );
		}

		// Hide: managed refs = shared tabs on the form + own tabs on the form.
		$seen_refs = array_map(
			static function ( $key ) {
				return 's:' . $key;
			},
			$seen
		);
		$managed   = array_merge( $seen_refs, $rendered, $deleted );
		$hide      = array_merge(
			array_diff( $this->store->get_list( $post_id, '_bulktab_hide' ), $managed ),
			array_intersect( $hide_in, $seen_refs ),
			$hide_slots
		);
		if ( ! $this->store->set_list( $post_id, '_bulktab_hide', $hide ) ) {
			$errors[] = __( 'Hidden tabs were not saved.', 'bulktab' );
		}

		if ( null !== $order_post ) {
			$order = array_diff( Layout::parse_list( $order_post ), $deleted );
			if ( ! $this->store->set_list( $post_id, '_bulktab_order', array_values( $order ) ) ) {
				$errors[] = __( 'Tab order was not saved.', 'bulktab' );
			}
		}

		return $errors;
	}

	/**
	 * True when two HTML strings differ only by editor formatting (paragraphs, whitespace).
	 *
	 * @param string $a HTML.
	 * @param string $b HTML.
	 * @return bool
	 */
	private static function same_content( string $a, string $b ): bool {
		if ( $a === $b ) {
			return true;
		}
		$norm = static function ( string $html ): string {
			return (string) preg_replace( '/\s+/', ' ', trim( wpautop( $html ) ) );
		};
		return $norm( $a ) === $norm( $b );
	}
}
