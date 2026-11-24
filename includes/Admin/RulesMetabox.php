<?php
/**
 * "Show this tab on" box for the shared-tab edit screen (Free UI).
 * Pro replaces the editor via the `bulktab_rules_editor` action and raises
 * limits via `bulktab_rule_limits`; storage and evaluation are shared.
 *
 * @package BulkTab
 */

namespace BulkTab\Admin;

use BulkTab\PostType;
use BulkTab\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Rules meta box on the shared-tab editor.
 */
final class RulesMetabox {

	const NONCE = 'bulktab_rules_nonce';

	/**
	 * Register hooks.
	 */
	public static function register_hooks(): void {
		add_action( 'add_meta_boxes_' . PostType::NAME, array( self::class, 'add' ) );
		add_action( 'save_post_' . PostType::NAME, array( self::class, 'save' ), 10, 2 );
	}

	/**
	 * Rule limits in force (Free limits unless Pro raises them).
	 *
	 * @return array
	 */
	public static function limits(): array {
		/**
		 * Rule limits for saving from the editor. Pro raises these.
		 *
		 * @param array $limits See Rules::free_limits().
		 */
		return (array) apply_filters( 'bulktab_rule_limits', Rules::free_limits() );
	}

	/**
	 * Add the meta box.
	 */
	public static function add(): void {
		add_meta_box( 'bulktab-rules', __( 'Show this tab on', 'bulktab' ), array( self::class, 'render' ), PostType::NAME, 'normal', 'high' );
	}

	/**
	 * Print the meta box.
	 *
	 * @param \WP_Post $post Shared tab.
	 */
	public static function render( $post ): void {
		wp_nonce_field( self::NONCE, self::NONCE );
		$rules = Rules::parse( get_post_meta( $post->ID, '_bulktab_rules', true ) );
		$key   = (string) get_post_meta( $post->ID, '_bulktab_key', true );

		if ( has_action( 'bulktab_rules_editor' ) ) {
			/**
			 * Replace the rules editor (Pro).
			 *
			 * @param array    $rules Parsed rules.
			 * @param \WP_Post $post  Shared tab.
			 */
			do_action( 'bulktab_rules_editor', $rules, $post );
			return;
		}

		if ( Rules::check_limits( $rules, self::limits() ) ) {
			// Saved with Pro, Pro now inactive: keep and keep applying, but don't let the Free form overwrite it.
			echo '<p>' . esc_html__( 'These rules were made with Pro. They still apply to your store. Re-activate Pro to edit them.', 'bulktab' ) . '</p>';
			echo '<input type="hidden" name="bulktab_rules_keep" value="1" />';
			return;
		}

		$mode  = $rules['all'] ? 'all' : ( $rules['groups'] ? 'terms' : 'manual' );
		$cond  = $rules['groups'][0][0] ?? array(
			'tax'   => 'product_cat',
			'terms' => array(),
		);
		$taxes = array_filter( Rules::TAXONOMIES, 'taxonomy_exists' );
		?>
		<p>
			<label><input type="radio" name="bulktab_mode" value="manual" <?php checked( $mode, 'manual' ); ?> /> <?php esc_html_e( 'Only products I pick', 'bulktab' ); ?></label><br />
			<label><input type="radio" name="bulktab_mode" value="all" <?php checked( $mode, 'all' ); ?> /> <?php esc_html_e( 'All products', 'bulktab' ); ?></label><br />
			<label><input type="radio" name="bulktab_mode" value="terms" <?php checked( $mode, 'terms' ); ?> /> <?php esc_html_e( 'Products in…', 'bulktab' ); ?></label>
		</p>
		<p>
			<select name="bulktab_tax" style="width:100%">
				<?php foreach ( $taxes as $tax ) : ?>
					<option value="<?php echo esc_attr( $tax ); ?>" <?php selected( $cond['tax'], $tax ); ?>><?php echo esc_html( get_taxonomy( $tax )->labels->singular_name ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php foreach ( $taxes as $tax ) : ?>
			<p class="bulktab-terms" data-tax="<?php echo esc_attr( $tax ); ?>">
				<select name="bulktab_terms[<?php echo esc_attr( $tax ); ?>][]" multiple size="6" style="width:100%">
					<?php
					$terms = get_terms(
						array(
							'taxonomy'   => $tax,
							'hide_empty' => false,
							'number'     => 1000,
						)
					);
					foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) :
						$selected = $cond['tax'] === $tax && in_array( (int) $term->term_id, $cond['terms'], true );
						?>
						<option value="<?php echo (int) $term->term_id; ?>" <?php selected( $selected ); ?>><?php echo esc_html( $term->name ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
		<?php endforeach; ?>
		<p class="description"><?php esc_html_e( 'Sub-categories are included. You can also attach or hide this tab on any single product.', 'bulktab' ); ?></p>
		<?php if ( '' !== $key ) : ?>
			<p class="description"><?php esc_html_e( 'Tab ID:', 'bulktab' ); ?> <code><?php echo esc_html( $key ); ?></code></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Save the rules (save_post_bulktab_tab).
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post (unused; part of the hook signature).
	 */
	public static function save( $post_id, $post ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- hook signature.
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) || ! empty( $_POST['bulktab_rules_keep'] ) ) {
			return;
		}

		/**
		 * Build rules from the submitted form. Pro's editor returns its own array here.
		 *
		 * @param array|null $raw Raw rules (v1 shape) or null to use the Free form.
		 */
		$raw = apply_filters( 'bulktab_rules_from_request', null, $post_id );
		if ( null === $raw ) {
			$raw  = Rules::empty_rules();
			$mode = isset( $_POST['bulktab_mode'] ) ? sanitize_key( wp_unslash( $_POST['bulktab_mode'] ) ) : 'manual';
			$tax  = isset( $_POST['bulktab_tax'] ) ? sanitize_key( wp_unslash( $_POST['bulktab_tax'] ) ) : '';
			if ( 'all' === $mode ) {
				$raw['all'] = true;
			} elseif ( 'terms' === $mode ) {
				$terms = isset( $_POST['bulktab_terms'][ $tax ] ) ? array_map( 'absint', (array) wp_unslash( $_POST['bulktab_terms'][ $tax ] ) ) : array();
				if ( $terms ) {
					$raw['groups'] = array(
						array(
							array(
								'tax'   => $tax,
								'terms' => $terms,
								'not'   => false,
							),
						),
					);
				} else {
					Notices::add( __( 'Pick at least one term, or choose another option. The previous rule was kept.', 'bulktab' ), 'warning' );
					return;
				}
			}
		}

		$errors = array();
		$rules  = Rules::parse( $raw, $errors );
		// Validity only (no feature limits): any rule the engine can evaluate may be saved.
		if ( $rules['all'] && $rules['groups'] ) {
			$errors[] = 'all_with_groups';
		}
		if ( $errors ) {
			Notices::add(
				/* translators: %s: error codes */
				sprintf( __( 'Rule not saved (%s). The previous rule was kept.', 'bulktab' ), implode( ', ', $errors ) )
			);
			return;
		}
		update_post_meta( $post_id, '_bulktab_rules', wp_slash( Rules::to_json( $rules ) ) );
	}
}
