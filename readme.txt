=== BulkTab – Bulk Product Tabs for WooCommerce ===
Contributors: waukeganlabs
Tags: product tabs, woocommerce tabs, custom tabs, product information, tabs
Requires at least: 6.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Tabs that scale with your catalog: set product info once, apply it everywhere, never lose it.

== Description ==

Add extra tabs to your WooCommerce product pages – Shipping, Size Guide, Specifications, FAQ, Manuals – and manage them across hundreds or thousands of products without copying the same text into every product.

**Write once, apply everywhere**

* **Shared tabs.** Create a tab once under Products → Shared tabs and show it on all products, or on every product in a category, tag or brand. Edit it once and every product shows the new version.
* **Per-product tabs.** Add up to 20 tabs of your own on any product: text, tables, images, video embeds, links to PDFs and shortcodes.
* **Attach or hide per product.** Show a shared tab on a single product, or hide it on one product without changing its rule.

**Control the whole tab area**

* Reorder, rename or hide any tab, including WooCommerce's Description, Additional information and Reviews.
* Empty tabs are hidden automatically.
* No extra CSS or JavaScript on the front end: your theme and WooCommerce's own tab script do the work.

**Built so your tabs don't disappear**

* Tab data is stored as plain post meta and a regular post type, not one serialized blob, so imports, search-and-replace tools and migrations don't silently corrupt it.
* The previous version of every per-product tab is kept, so a bad save can be undone.
* Tab content is not passed through `the_content`, so page builders don't copy the product description into every tab.

**Works with your stack**

* Classic and block themes, with `[bulktab_tabs]` and `[bulktab_tab]` shortcodes as a fallback for page builders and custom templates.
* WPML and Polylang via the included `wpml-config.xml`.
* Compatible with WooCommerce High-Performance Order Storage (we never touch orders or checkout).

**Pro add-on**

An optional paid add-on, sold separately on our own site, adds combined rules with exclusions (for example "category A but not tag B"), bulk tools for adding, removing or replacing tabs across many products, and full backup and restore. Everything described above is free and fully working without it. This plugin contains no locked features, trials or license checks.

== Installation ==

1. Make sure WooCommerce is installed and active.
2. Install this plugin from Plugins → Add New, or upload the zip, then activate it.
3. Add tabs to a product in the "Tabs" panel of the Product data box, or create shared tabs under Products → Shared tabs.

== Frequently Asked Questions ==

= My theme or page builder doesn't show the tabs. What can I do? =

Most themes, including block themes using WooCommerce's default single-product template, display the tabs automatically. If your theme or builder (Elementor, Divi and others) uses its own product layout, place one of these shortcodes where the tabs should appear:

* `[bulktab_tabs]` – all tabs of the current product, one after another.
* `[bulktab_tab key="size-guide"]` – the content of one shared tab, by its Tab ID (shown in the shared tab's sidebar).
* `[bulktab_tab slot="1"]` – the content of one per-product tab, by its number (1–20).

Add `id="123"` to either shortcode to show tabs of a specific product outside the product page.

= Does it work with block themes? =

Yes. With WooCommerce's default Product Details block, tabs work as in classic themes. If you switched that block to its accordion style, our tabs are added as accordion items, but renaming, hiding or reordering the default WooCommerce sections must be done in the Site Editor.

= Does it work with WPML or Polylang? =

Yes. The plugin ships a `wpml-config.xml` file that both read. Tab titles and content are translatable; the tab layout and which shared tabs a product uses are copied to translations. A shared tab that has no translation yet falls back to the original language.

= What happens to my data if I deactivate or delete the plugin? =

Deactivating keeps everything. Deleting the plugin also keeps your tabs by default, so reinstalling brings them back. Your tab data is only removed on uninstall if you turned on the option to delete all plugin data.

= Can I import and export tabs with the WooCommerce product CSV tools? =

Tabs are stored in plain post meta fields, one value per field, so they survive spreadsheet editing and URL search-and-replace without the length errors that serialized data causes.

== Changelog ==

= 0.1.0 =
* Initial development release.
