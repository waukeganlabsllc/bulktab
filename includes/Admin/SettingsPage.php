<?php
/**
 * Products > Tab settings (spec 06): store-wide layout of the WooCommerce
 * default tabs, shared tabs and other plugins' tabs (rename / hide / order,
 * option `bulktab_layout`, format in Layout.php), front-end options and the
 * "Check product page" diagnostics.
 *
 * Plain PHP form posted to admin-post.php; the order list reuses the product
 * panel's assets/admin.js sortable (same element IDs and classes).
 *
 * @package BulkTab
 */

namespace BulkTab\Admin;

use BulkTab\Compat\Diagnostics;
use BulkTab\Layout;
use BulkTab\RulesIndex;

defined( 'ABSPATH' ) || exit;

/**
 * Tab settings screen and its options.
 */
final class SettingsPage {

	const SLUG         = 'bulktab-settings';
	const PARENT       = 'edit.php?post_type=product';
	const CAP          = 'manage_woocommerce';
	const SAVE_ACTION  = 'bulktab_save_settings';
	const CHECK_ACTION = 'bulktab_check_page';

	/** Pseudo-ref of the "tabs added on each product" row in the order list. */
	const OWN = '__own__';

	/** Priority of the pseudo row: per-product tabs use 41..60 (Renderer: 40 + slot). */
	const OWN_PRIORITY = 40.5;

	/**
	 * Register hooks. Called from Plugin::boot().
	 */
	public static function register_hooks(): void {
		// Priority 5 so site code using the documented filter at 10 still wins.
		add_filter( 'bulktab_show_heading', array( self::class, 'filter_show_heading' ), 5 );

		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'admin_post_' . self::SAVE_ACTION, array( self::class, 'save' ) );
		add_action( 'admin_post_' . self::CHECK_ACTION, array( self::class, 'check' ) );
		if ( defined( 'BULKTAB_FILE' ) ) {
			add_filter( 'plugin_action_links_' . plugin_basename( BULKTAB_FILE ), array( self::class, 'action_links' ) );
		}
	}

	/**
	 * "Show tab title inside panel" option (off by default, spec 04).
	 *
	 * @return bool
	 */
	public static function filter_show_heading(): bool {
		return (bool) get_option( 'bulktab_show_heading', 0 );
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param array $links Action links.
	 * @return array
	 */
	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( self::url() ), esc_html__( 'Settings', 'bulktab' ) ) );
		return $links;
	}

	/**
	 * Add Products > Tab settings.
	 */
	public static function add_menu(): void {
		add_submenu_page(
			self::PARENT,
			__( 'Tab settings', 'bulktab' ),
			__( 'Tab settings', 'bulktab' ),
			self::CAP,
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * Load the product panel's order-list assets on this screen.
	 *
	 * @param string $hook_suffix Admin page hook.
	 */
	public static function enqueue( $hook_suffix ): void {
		if ( 'product_page_' . self::SLUG !== $hook_suffix ) {
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
				'defaultOrder' => __( 'The default order will be used after you save.', 'bulktab' ),
			)
		);
	}

	/**
	 * URL of this screen.
	 *
	 * @param array $args Extra query args.
	 * @return string
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( self::PARENT ) );
	}

	// Render.

	/**
	 * Print the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen state.
		$sample  = isset( $_GET['sample'] ) ? absint( $_GET['sample'] ) : 0;
		$updated = ! empty( $_GET['updated'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$sample = self::sample_product( $sample );
		$layout = self::layout();
		$found  = $sample ? Diagnostics::scan( $sample ) : array( 'before' => array() );
		$items  = self::items( $layout, $found['before'] );
		$custom = (bool) array_filter(
			$items,
			static function ( $item ) {
				return null !== $item['saved_priority'];
			}
		);
		$check  = get_transient( 'bulktab_check_' . get_current_user_id() );
		if ( is_array( $check ) ) {
			delete_transient( 'bulktab_check_' . get_current_user_id() );
		}
		?>
		<div class="wrap bulktab-settings">
			<h1><?php esc_html_e( 'Tab settings', 'bulktab' ); ?></h1>

			<?php if ( $updated ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Settings saved.', 'bulktab' ); ?></p></div>
			<?php endif; ?>
			<?php if ( is_array( $check ) && isset( $check['type'], $check['message'] ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( (string) $check['type'] ); ?>"><p><?php echo esc_html( (string) $check['message'] ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>" />
				<input type="hidden" name="sample" value="<?php echo esc_attr( (string) $sample ); ?>" />
				<?php wp_nonce_field( self::SAVE_ACTION ); ?>

				<h2><?php esc_html_e( 'Tab layout for all products', 'bulktab' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Rename, hide or reorder tabs on every product page. Leave a name empty to keep the original. An order set on a single product (Product data > Tabs) wins over this order.', 'bulktab' ); ?>
				</p>

				<input type="hidden" id="bulktab_order" name="bulktab_order" value="<?php echo esc_attr( $custom ? implode( ',', array_column( $items, 'ref' ) ) : '' ); ?>" />
				<ul id="bulktab-order-list" class="bulktab-order-list bulktab-layout-list">
					<?php
					foreach ( $items as $item ) {
						self::render_item( $item );
					}
					?>
				</ul>
				<p>
					<button type="button" class="button bulktab-order-reset"><?php esc_html_e( 'Use default order', 'bulktab' ); ?></button>
					<span class="bulktab-order-status" aria-live="polite"><?php echo $custom ? '' : esc_html__( 'Using the default order.', 'bulktab' ); ?></span>
				</p>

				<h2><?php esc_html_e( 'Options', 'bulktab' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Tab title inside panel', 'bulktab' ); ?></th>
						<td>
							<label><input type="checkbox" name="bulktab_show_heading" value="1" <?php checked( (bool) get_option( 'bulktab_show_heading', 0 ) ); ?> /> <?php esc_html_e( 'Repeat the tab title as a heading at the top of each BulkTab tab', 'bulktab' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Content filter', 'bulktab' ); ?></th>
						<td>
							<label><input type="checkbox" name="bulktab_use_the_content" value="1" <?php checked( (bool) get_option( 'bulktab_use_the_content', 0 ) ); ?> /> <?php esc_html_e( 'Run tab content through the "the_content" filter', 'bulktab' ); ?></label>
							<p class="description"><?php esc_html_e( 'Leave this off unless a plugin\'s content only works through that filter. When it is on, page builders, related-post and sharing plugins may add their output inside every tab.', 'bulktab' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Uninstall', 'bulktab' ); ?></th>
						<td>
							<label><input type="checkbox" name="bulktab_delete_data_on_uninstall" value="1" <?php checked( (bool) get_option( 'bulktab_delete_data_on_uninstall', 0 ) ); ?> /> <?php esc_html_e( 'Delete all BulkTab data when the plugin is deleted', 'bulktab' ); ?></label>
							<p class="description"><strong><?php esc_html_e( 'Warning:', 'bulktab' ); ?></strong> <?php esc_html_e( 'Deleting the plugin will then permanently erase every shared tab, every product\'s own tabs and these settings. This cannot be undone. Leave it off to keep your tabs if you reinstall later.', 'bulktab' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Sample product', 'bulktab' ); ?></h2>
			<form method="get" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>">
				<input type="hidden" name="post_type" value="product" />
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
				<p>
					<label for="bulktab-sample"><?php esc_html_e( 'Product ID', 'bulktab' ); ?></label>
					<input type="number" min="1" id="bulktab-sample" name="sample" value="<?php echo esc_attr( $sample ? (string) $sample : '' ); ?>" class="small-text" />
					<?php submit_button( __( 'Find tabs on this product', 'bulktab' ), 'secondary', '', false ); ?>
				</p>
				<p class="description">
					<?php
					echo $sample
						/* translators: %s: product title */
						? esc_html( sprintf( __( 'Tabs from other plugins are found on "%s". Pick another product if some are missing above.', 'bulktab' ), get_the_title( $sample ) ) )
						: esc_html__( 'Add a published product to find tabs from other plugins.', 'bulktab' );
					?>
				</p>
			</form>

			<?php if ( $sample ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::CHECK_ACTION ); ?>" />
					<input type="hidden" name="sample" value="<?php echo esc_attr( (string) $sample ); ?>" />
					<?php wp_nonce_field( self::CHECK_ACTION ); ?>
					<p>
						<?php submit_button( __( 'Check product page', 'bulktab' ), 'secondary', '', false ); ?>
						<span class="description"><?php esc_html_e( 'Loads this product\'s page as a visitor and checks that its BulkTab tabs are shown.', 'bulktab' ); ?></span>
					</p>
				</form>
			<?php endif; ?>

			<?php
			/**
			 * Fires at the end of the Tab settings screen, outside the settings form.
			 * Other components and add-ons print their own sections and forms here.
			 */
			do_action( 'bulktab_settings_page_after' );
			?>
		</div>
		<?php
	}

	/**
	 * One row of the layout list.
	 *
	 * @param array $item Row from items().
	 */
	private static function render_item( array $item ): void {
		$ref   = $item['ref'];
		$own   = self::OWN === $ref;
		$field = 'bulktab-layout-' . sanitize_html_class( str_replace( ':', '-', $ref ) );
		?>
		<li class="bulktab-order-item<?php echo $item['hide'] ? ' is-hidden' : ''; ?>" data-ref="<?php echo esc_attr( $ref ); ?>">
			<span class="dashicons dashicons-menu bulktab-handle" aria-hidden="true"></span>
			<span class="bulktab-order-label">
				<?php echo esc_html( $item['label'] ); ?>
				<span class="bulktab-order-type"><?php echo esc_html( $item['type'] ); ?></span>
			</span>
			<?php if ( ! $own ) : ?>
				<input type="hidden" name="bulktab_refs[]" value="<?php echo esc_attr( $ref ); ?>" />
				<?php if ( 0 === strpos( $ref, 'x:' ) ) : ?>
					<input type="hidden" name="bulktab_label[<?php echo esc_attr( $ref ); ?>]" value="<?php echo esc_attr( $item['label'] ); ?>" />
				<?php endif; ?>
				<label class="screen-reader-text" for="<?php echo esc_attr( $field . '-title' ); ?>">
					<?php
					/* translators: %s: tab name */
					echo esc_html( sprintf( __( 'New name for %s', 'bulktab' ), $item['label'] ) );
					?>
				</label>
				<input type="text" id="<?php echo esc_attr( $field . '-title' ); ?>" name="bulktab_title[<?php echo esc_attr( $ref ); ?>]" value="<?php echo esc_attr( $item['title'] ); ?>" placeholder="<?php echo esc_attr( $item['label'] ); ?>" class="regular-text" />
				<label><input type="checkbox" name="bulktab_hide[<?php echo esc_attr( $ref ); ?>]" value="1" <?php checked( $item['hide'] ); ?> /> <?php esc_html_e( 'Hide', 'bulktab' ); ?></label>
			<?php else : ?>
				<span class="description"><?php esc_html_e( 'Each product\'s own tabs go here', 'bulktab' ); ?></span>
			<?php endif; ?>
			<span class="bulktab-order-buttons">
				<button type="button" class="button-link bulktab-move" data-dir="up">
					<span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span>
					<span class="screen-reader-text">
						<?php
						/* translators: %s: tab name */
						echo esc_html( sprintf( __( 'Move %s up', 'bulktab' ), $item['label'] ) );
						?>
					</span>
				</button>
				<button type="button" class="button-link bulktab-move" data-dir="down">
					<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
					<span class="screen-reader-text">
						<?php
						/* translators: %s: tab name */
						echo esc_html( sprintf( __( 'Move %s down', 'bulktab' ), $item['label'] ) );
						?>
					</span>
				</button>
			</span>
		</li>
		<?php
	}

	/**
	 * Rows of the layout list, in display order.
	 *
	 * @param array $layout  Saved layout.
	 * @param array $scanned Tabs found on the sample product before BulkTab's layout ran.
	 * @return array<int, array{ref: string, label: string, type: string, title: string, hide: bool, saved_priority: int|null}>
	 */
	private static function items( array $layout, array $scanned ): array {
		$rows = array(
			'description'            => array( __( 'Description', 'bulktab' ), __( 'WooCommerce', 'bulktab' ), 10 ),
			'additional_information' => array( __( 'Additional information', 'bulktab' ), __( 'WooCommerce', 'bulktab' ), 20 ),
			'reviews'                => array( __( 'Reviews', 'bulktab' ), __( 'WooCommerce', 'bulktab' ), 30 ),
		);
		foreach ( RulesIndex::get() as $key => $entry ) {
			$post_id                      = (int) ( $entry['post_id'] ?? 0 );
			$title                        = $post_id ? get_the_title( $post_id ) : '';
			$rows[ 's:' . (string) $key ] = array( '' !== $title ? $title : (string) $key, __( 'Shared', 'bulktab' ), (int) ( $entry['priority'] ?? 25 ) );
		}
		$rows[ self::OWN ] = array( __( 'Product tabs', 'bulktab' ), __( 'This product only', 'bulktab' ), self::OWN_PRIORITY );
		foreach ( $scanned as $key => $tab ) {
			$ref = Layout::ref_for( (string) $key, is_array( $tab ) ? $tab : array() );
			if ( 0 !== strpos( $ref, 'x:' ) || ! self::valid_ref( $ref ) ) {
				continue;
			}
			$title        = wp_strip_all_tags( (string) ( $tab['title'] ?? '' ) );
			$rows[ $ref ] = array( '' !== $title ? $title : substr( $ref, 2 ), __( 'Other plugin', 'bulktab' ), (int) ( $tab['priority'] ?? 50 ) );
		}
		foreach ( $layout as $ref => $entry ) {
			$ref = (string) $ref;
			if ( ! isset( $rows[ $ref ] ) && 0 === strpos( $ref, 'x:' ) ) {
				$label        = isset( $entry['label'] ) ? (string) $entry['label'] : '';
				$rows[ $ref ] = array( '' !== $label ? $label : substr( $ref, 2 ), __( 'Other plugin', 'bulktab' ), 50 );
			}
		}

		$items = array();
		$i     = 0;
		foreach ( $rows as $ref => $row ) {
			$ref     = (string) $ref;
			$entry   = isset( $layout[ $ref ] ) && is_array( $layout[ $ref ] ) ? $layout[ $ref ] : array();
			$saved   = isset( $entry['priority'] ) && is_numeric( $entry['priority'] ) ? (int) $entry['priority'] : null;
			$items[] = array(
				'ref'            => $ref,
				'label'          => (string) $row[0],
				'type'           => (string) $row[1],
				'title'          => isset( $entry['title'] ) ? (string) $entry['title'] : '',
				'hide'           => ! empty( $entry['hide'] ),
				'saved_priority' => $saved,
				'sort'           => array( null !== $saved ? $saved : $row[2], $i++ ),
			);
		}
		usort(
			$items,
			static function ( $a, $b ) {
				return $a['sort'] <=> $b['sort'];
			}
		);
		return $items;
	}

	/**
	 * Saved layout option.
	 *
	 * @return array
	 */
	private static function layout(): array {
		$layout = get_option( 'bulktab_layout', array() );
		return is_array( $layout ) ? $layout : array();
	}

	/**
	 * The requested product, else the newest published product (0 if none).
	 *
	 * @param int $requested Product ID from the request.
	 * @return int
	 */
	private static function sample_product( int $requested ): int {
		if ( $requested && 'product' === get_post_type( $requested ) ) {
			return $requested;
		}
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	// Save.

	/**
	 * Save the layout and options (admin-post).
	 */
	public static function save(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to change these settings.', 'bulktab' ), 403 );
		}
		check_admin_referer( self::SAVE_ACTION );

		$refs   = isset( $_POST['bulktab_refs'] ) ? Layout::parse_list( array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['bulktab_refs'] ) ) ) : array();
		$titles = isset( $_POST['bulktab_title'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['bulktab_title'] ) ) : array();
		$labels = isset( $_POST['bulktab_label'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['bulktab_label'] ) ) : array();
		$hidden = isset( $_POST['bulktab_hide'] ) ? array_keys( (array) wp_unslash( $_POST['bulktab_hide'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only keys are used, validated in build_layout().
		$order  = isset( $_POST['bulktab_order'] ) ? Layout::parse_list( sanitize_text_field( wp_unslash( $_POST['bulktab_order'] ) ) ) : array();
		$sample = isset( $_POST['sample'] ) ? absint( $_POST['sample'] ) : 0;

		update_option( 'bulktab_layout', self::build_layout( self::layout(), $refs, $titles, array_map( 'strval', $hidden ), $labels, $order ) );
		update_option( 'bulktab_show_heading', empty( $_POST['bulktab_show_heading'] ) ? 0 : 1 );
		update_option( 'bulktab_use_the_content', empty( $_POST['bulktab_use_the_content'] ) ? 0 : 1 );
		update_option( 'bulktab_delete_data_on_uninstall', empty( $_POST['bulktab_delete_data_on_uninstall'] ) ? 0 : 1, false );

		wp_safe_redirect(
			self::url(
				array(
					'updated' => 1,
					'sample'  => $sample,
				)
			)
		);
		exit;
	}

	/**
	 * Run the "Check product page" diagnostics (admin-post).
	 */
	public static function check(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'bulktab' ), 403 );
		}
		check_admin_referer( self::CHECK_ACTION );
		$sample = isset( $_POST['sample'] ) ? absint( $_POST['sample'] ) : 0;
		set_transient( 'bulktab_check_' . get_current_user_id(), Diagnostics::check( $sample ), 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( self::url( array( 'sample' => $sample ) ) );
		exit;
	}

	/**
	 * New layout option from the submitted form. Pure (inputs already sanitized).
	 *
	 * Refs that were not on the form keep their saved entry. Entries with
	 * nothing set are dropped.
	 *
	 * @param array    $saved  Current layout.
	 * @param string[] $refs   Refs that were on the form.
	 * @param array    $titles Ref => new name ('' keeps the original).
	 * @param string[] $hidden Refs ticked "Hide".
	 * @param array    $labels Ref => display label (other plugins' tabs only).
	 * @param string[] $order  Refs in display order, including self::OWN; empty = default order.
	 * @return array
	 */
	public static function build_layout( array $saved, array $refs, array $titles, array $hidden, array $labels, array $order ): array {
		$priorities = $order ? self::priorities( $order ) : array();
		$layout     = array();
		foreach ( $saved as $ref => $entry ) {
			$ref = (string) $ref;
			if ( self::valid_ref( $ref ) && is_array( $entry ) && ! in_array( $ref, $refs, true ) ) {
				$layout[ $ref ] = $entry;
			}
		}
		foreach ( $refs as $ref ) {
			$ref = (string) $ref;
			if ( ! self::valid_ref( $ref ) ) {
				continue;
			}
			$entry = array();
			$title = isset( $titles[ $ref ] ) ? trim( (string) $titles[ $ref ] ) : '';
			if ( '' !== $title ) {
				$entry['title'] = $title;
			}
			if ( in_array( $ref, $hidden, true ) ) {
				$entry['hide'] = true;
			}
			if ( isset( $priorities[ $ref ] ) ) {
				$entry['priority'] = $priorities[ $ref ];
			}
			if ( $entry && 0 === strpos( $ref, 'x:' ) && isset( $labels[ $ref ] ) && '' !== trim( (string) $labels[ $ref ] ) ) {
				$entry['label'] = trim( (string) $labels[ $ref ] );
			}
			if ( $entry ) {
				$layout[ $ref ] = $entry;
			}
		}
		return $layout;
	}

	/**
	 * Priorities for an ordered list of refs. Pure.
	 *
	 * Refs above self::OWN end below 40 and refs below it start at 70, so each
	 * product's own tabs (priorities 41..60) stay where the pseudo row was put.
	 *
	 * @param string[] $order Refs in display order.
	 * @return array<string, int>
	 */
	public static function priorities( array $order ): array {
		$order = array_values( array_map( 'strval', $order ) );
		$own   = array_search( self::OWN, $order, true );
		$own   = false === $own ? count( $order ) : (int) $own;
		$above = array_slice( $order, 0, $own );
		$below = array_slice( $order, $own + 1 );
		$count = count( $above );
		$step  = $count ? min( 10, max( 1, intdiv( 39, $count ) ) ) : 10;
		$out   = array();
		foreach ( $above as $i => $ref ) {
			$out[ $ref ] = 40 - ( $count - $i ) * $step;
		}
		foreach ( $below as $i => $ref ) {
			$out[ $ref ] = 60 + ( $i + 1 ) * 10;
		}
		return $out;
	}

	/**
	 * Whether a ref can be part of the store-wide layout (not per-product `c:` refs).
	 *
	 * @param string $ref Ref.
	 * @return bool
	 */
	public static function valid_ref( string $ref ): bool {
		return in_array( $ref, Layout::BUILT_IN, true ) || 1 === preg_match( '/^[sx]:[a-z0-9_\-]+$/i', $ref );
	}
}
