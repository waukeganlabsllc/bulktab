# Extending BulkTab

BulkTab exposes a small, versioned hook API for add-ons and custom code. All hooks are prefixed `bulktab_`.

Compatibility rules:

- Hook names and argument lists are stable within a major version. New arguments are only ever appended.
- A shared tab's rule uses **schema v2** (see `includes/Rules.php`): all products, or any of the chosen terms of one taxonomy. Always run your data through `\BulkTab\Rules::parse()`; never assume a shape you didn't validate.
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

### `bulktab_shared_tab_shows`

```php
apply_filters( 'bulktab_shared_tab_shows', ?bool $shows, string $key, array $entry, array $ctx, bool $attached ): ?bool;
```

Decide whether a shared tab shows on a product. Return `true` or `false` to decide, or `null` (the default) to keep BulkTab's own decision: attached on the product, or matched by the tab's rule. Hiding a tab on a product always wins and is applied before this filter. `$entry` is the index entry (`post_id`, `priority`, `rules`); `$ctx` is `array( 'id' => int, 'terms' => array( taxonomy => term IDs incl. ancestors ) )`.

```php
add_filter( 'bulktab_shared_tab_shows', function ( $shows, $key, $entry, $ctx ) {
	return 'clearance-note' === $key ? in_array( 42, $ctx['terms']['product_tag'] ?? array(), true ) : $shows;
}, 10, 4 );
```

### `bulktab_allowed_html`

```php
apply_filters( 'bulktab_allowed_html', array $allowed ): array;
```

HTML allowed when tab content is output (wp_kses format). Defaults to post content plus iframe, video, audio, source and track.

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
