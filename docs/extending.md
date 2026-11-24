# Extending BulkTab

BulkTab exposes a small, versioned hook API. The Pro add-on uses only these hooks;
third-party code can use them too. All hooks are prefixed `bulktab_`.

Compatibility rules:

- Hook names and argument lists are stable within a major version. New arguments are only ever appended.
- Rules arrays use **schema v1** (see `includes/Rules.php`). Always run your data through `\BulkTab\Rules::parse()`; never assume a shape you didn't validate.
- Tab refs: built-ins `description`, `additional_information`, `reviews`; shared tabs `s:<key>`; per-product tabs `c:<n>` (n = 1..20); other plugins' tabs `x:<key>`.
- Front-end tab keys: `bulktab_s_<key>` (shared) and `bulktab_c_<n>` (per-product).

Check that the free plugin is new enough before using anything here:

```php
add_action( 'bulktab_loaded', function ( $version ) {
	if ( version_compare( $version, '0.1.0', '<' ) ) {
		return; // Show a notice and stay inert.
	}
	// Register your hooks.
} );
```

---

## Actions

### `bulktab_loaded`

```php
do_action( 'bulktab_loaded', string $version );
```

Fires at the end of `BulkTab\Plugin::boot()` (on `plugins_loaded`, priority 20), after all BulkTab hooks are registered. `$version` is `BULKTAB_VERSION`.

```php
add_action( 'bulktab_loaded', function ( string $version ) {
	require __DIR__ . '/includes/integration.php';
} );
```

### `bulktab_rules_editor`

```php
do_action( 'bulktab_rules_editor', array $rules, WP_Post $post );
```

Replaces the "Show this tab on" box on the shared-tab edit screen. If **any** callback is attached, the free rules form is not printed. The free nonce field is still printed first, so the free save handler runs; pair this with `bulktab_rules_from_request` to supply the rules. `$rules` is the parsed v1 array stored on the tab.

```php
add_action( 'bulktab_rules_editor', function ( array $rules, WP_Post $post ) {
	printf(
		'<textarea name="my_rules_json" class="widefat" rows="6">%s</textarea>',
		esc_textarea( wp_json_encode( $rules ) )
	);
}, 10, 2 );
```

### `bulktab_product_tabs_saved`

```php
do_action( 'bulktab_product_tabs_saved', int $product_id, array $before, array $after );
```

Fires after the product "Tabs" panel is saved in the product editor (only when that panel's nonce was posted, so not on quick edit, bulk edit or REST saves). `$before` and `$after` are `TabStore::get_tabs()` results: `array( n => array( 'slot' => n, 'title' => string, 'content' => string, 'has_prev' => bool ) )`.

```php
add_action( 'bulktab_product_tabs_saved', function ( int $product_id, array $before, array $after ) {
	if ( $before !== $after ) {
		// e.g. purge this product's page from your cache.
		do_action( 'my_cache_purge_post', $product_id );
	}
}, 10, 3 );
```

### `bulktab_product_panel_after`

```php
do_action( 'bulktab_product_panel_after', int $product_id );
```

Fires at the end of the product "Tabs" panel markup, inside the panel's wrapper. Print extra fields here and save them on `woocommerce_process_product_meta` with your own nonce and capability check.

```php
add_action( 'bulktab_product_panel_after', function ( int $product_id ) {
	wp_nonce_field( 'my_tabs_extra', 'my_tabs_extra_nonce' );
	printf(
		'<p class="form-field"><label for="my_tabs_note">%s</label><input type="text" id="my_tabs_note" name="my_tabs_note" value="%s" /></p>',
		esc_html__( 'Internal note', 'my-plugin' ),
		esc_attr( (string) get_post_meta( $product_id, '_my_tabs_note', true ) )
	);
} );
```

---

## Filters

### `bulktab_rule_limits`

```php
apply_filters( 'bulktab_rule_limits', array $limits ): array;
```

Limits applied when **saving** rules from the editor. Stored rules are always evaluated in full, whatever the limits, so a lapsed add-on never changes what shoppers see. Defaults (`Rules::free_limits()`):

| Key | Free default | Meaning |
|---|---|---|
| `groups` | `1` | Max OR-groups |
| `conditions` | `1` | Max AND-conditions per group |
| `not` | `false` | Negated conditions allowed |
| `include_exclude` | `false` | Product include/exclude lists allowed |
| `taxonomies` | `product_cat`, `product_tag`, `product_brand` | Allowed taxonomies |

```php
add_filter( 'bulktab_rule_limits', function ( array $limits ) {
	$limits['groups']     = 5;
	$limits['conditions'] = 3;
	$limits['not']        = true;
	return $limits;
} );
```

### `bulktab_rules_from_request`

```php
apply_filters( 'bulktab_rules_from_request', ?array $raw_rules, int $post_id ): ?array;
```

Runs when a shared tab is saved, after the nonce and `edit_post` checks. Return `null` to let the free form be parsed, or a v1-shaped array to use instead. The result still goes through `Rules::parse()` and `bulktab_rule_limits`; if either fails, the previous rules are kept and a notice is shown. Read `$_POST` with `wp_unslash()` and sanitize.

```php
add_filter( 'bulktab_rules_from_request', function ( $raw, int $post_id ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- BulkTab verified its nonce.
	if ( ! isset( $_POST['my_rules_json'] ) ) {
		return $raw;
	}
	$decoded = json_decode( sanitize_textarea_field( wp_unslash( $_POST['my_rules_json'] ) ), true );
	return is_array( $decoded ) ? $decoded : $raw;
}, 10, 2 );
```

### `bulktab_product_tabs`

```php
apply_filters( 'bulktab_product_tabs', array $tabs, int $product_id ): array;
```

BulkTab' own tabs for a product, before they are merged into WooCommerce's `woocommerce_product_tabs` list (layout is applied afterwards, at priority 98). Each entry is keyed `bulktab_s_<key>` or `bulktab_c_<n>`:

```php
array(
	'title'         => string,   // Plain text; escaped on output.
	'priority'      => int,
	'callback'      => callable, // Renderer::panel().
	'bulktab_ref'     => string,   // 's:<key>' | 'c:<n>'.
	'bulktab_content' => string,   // Raw stored HTML.
	'bulktab_product' => int,
)
```

```php
// Hide the "shipping" shared tab on virtual products.
add_filter( 'bulktab_product_tabs', function ( array $tabs, int $product_id ) {
	$product = wc_get_product( $product_id );
	if ( $product && $product->is_virtual() ) {
		unset( $tabs['bulktab_s_shipping'] );
	}
	return $tabs;
}, 10, 2 );
```

### `bulktab_tab_content`

```php
apply_filters( 'bulktab_tab_content', string $html, string $raw, array $context ): string;
```

Rendered tab HTML, after BulkTab' content pipeline (blocks, embeds, `wpautop`, shortcodes, responsive images; **not** `the_content`). `$raw` is the stored HTML. `$context` is `array( 'ref' => 's:<key>'|'c:<n>', 'product_id' => int )`. Escape anything you add.

```php
add_filter( 'bulktab_tab_content', function ( string $html, string $raw, array $context ) {
	if ( 's:size-guide' === $context['ref'] ) {
		$html .= '<p class="size-note">' . esc_html__( 'Between sizes? Size up.', 'my-plugin' ) . '</p>';
	}
	return $html;
}, 10, 3 );
```

### `bulktab_show_heading`

```php
apply_filters( 'bulktab_show_heading', bool $show, array $tab ): bool;
```

Whether to print the tab title as an `<h2>` at the top of a BulkTab panel. `$tab` is the tab array described under `bulktab_product_tabs`.

```php
add_filter( 'bulktab_show_heading', '__return_false' );
```

---

## Options (not hooks)

| Option | Effect |
|---|---|
| `bulktab_use_the_content` | Truthy: render tab content through `the_content` instead of BulkTab' own pipeline. Can duplicate page-builder output inside tabs. |
| `bulktab_delete_data_on_uninstall` | Truthy: `uninstall.php` deletes shared tabs, `_bulktab_*` post meta and `bulktab_*` options. Default keeps everything. |
