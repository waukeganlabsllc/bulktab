=== BulkTab – Bulk Product Tabs for WooCommerce ===
Contributors: waukeganlabs
Tags: product tabs, woocommerce product tabs, woocommerce tabs, bulk edit, product information
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Write a product tab once, show it on every matching product, and never lose it. Shared tabs, spreadsheet-safe CSV, undo, one-click import.

== Description ==

**Your size guide, shipping info and specs: written once, on every product that needs them, and never lost.**

BulkTab is for WooCommerce stores with more than a handful of products. Instead of pasting the same text into hundreds of product pages, you write each tab once and choose where it appears. When something changes, you edit it once and every product updates.

We built BulkTab after reading 530 reviews of product-tab plugins and 750 support threads. The same four problems kept coming back: tabs that disappear, the same text typed into every product, tabs that break with a theme or importer, and no control over the product page. BulkTab is designed around fixing those four.

= Stop copying the same tab into every product =

* Create a **shared tab** once, such as Shipping, Returns or a Size guide, and show it on all products or on every product in chosen categories, tags or brands. Sub-categories are included.
* New products in that category get the tab automatically. No extra step.
* Edit the shared tab once and every product shows the new version on the next page load.
* Attach a shared tab to a single product, or hide it on one product, without touching the rule.
* Add up to 20 tabs of a product's own: text, tables, images, video embeds, links to PDFs and shortcodes.

= Never lose your tab content =

* Tabs are stored as plain fields, one value each, not one fragile block of data. Imports, search-and-replace and site migrations leave them intact.
* The previous version of every product tab is kept, so a bad edit can be restored from the product's Tabs panel.
* If BulkTab ever finds tab data it can't read, it tells you which product is affected and keeps the data untouched. It never silently shows an empty tab.
* Deleting the plugin keeps your tabs unless you choose otherwise, so reinstalling brings everything back.

= Edit tabs in a spreadsheet =

* Tabs appear as readable columns in WooCommerce's own product CSV export, such as "BulkTab 1 title" and "BulkTab 1 content".
* Edit them in Excel or Google Sheets and import the file back. Formatting, quotes, line breaks and accents come through exactly as you typed them.
* Leave a column out and that data stays as it is. Type `[keep]` in a cell to leave just that cell unchanged.

= Switch from another tab plugin in minutes =

* One-click import of your existing product tabs and saved tabs, with a dry run first so you see exactly what will happen.
* Your old plugin's data is never changed, and running the import again doesn't create duplicates.
* While both plugins are active, each tab appears only once on the product page.

= Your product page, your way =

* Reorder, rename or hide any tab, including WooCommerce's Description, Additional information and Reviews, for the whole store or for a single product.
* Empty tabs are hidden automatically.
* Works with classic and block themes. For page builders and custom layouts, the `[bulktab_tabs]` shortcode places the tabs anywhere.
* Translatable with WPML and Polylang.

= Light and respectful =

* No extra scripts or styles on your storefront. Your theme and WooCommerce display the tabs.
* No tracking, no data sent anywhere, no pop-ups and no repeating notices.
* Everything described here is free and fully working. There are no locked features, trials or licence checks in this plugin.

= Need more for a big catalog? =

BulkTab Pro is a separate add-on, sold on our own site, for stores that manage tabs at scale. It adds combined rules with exclusions (for example "Shoes, but not products tagged Sale"), a live count of matching products, add/replace/remove changes across thousands of products with one-click undo, per-product additions to a shared tab, full backup and restore, and WP All Import support.

== Installation ==

1. Make sure WooCommerce is installed and active.
2. Install BulkTab from Plugins → Add New, then activate it.
3. Add tabs to a product in the "Tabs" panel of the Product data box, or create shared tabs under Products → Shared tabs.
4. Moving from another tab plugin? Go to Products → Import tabs, run the dry run, then import.

== Frequently Asked Questions ==

= Will my tabs survive updates, imports and site migrations? =

Yes. Each tab title and content is stored as its own plain field, so plugin, WordPress and WooCommerce updates, CSV imports and search-and-replace during a migration don't corrupt them. The previous version of each product tab is also kept for undo.

= Can I edit tabs with the WooCommerce product CSV import and export? =

Yes. Export your products as usual and you'll find readable BulkTab columns. Edit them in any spreadsheet and import the file back with "Update existing products" ticked.

= How do I move my tabs from another tab plugin? =

Go to Products → Import tabs. The dry run lists how many products and tabs will be imported, and any problems, before anything is written. Your old plugin's data stays exactly as it was.

= My theme or page builder doesn't show the tabs. What can I do? =

Most themes, including block themes using WooCommerce's default product template, show the tabs automatically. If your theme or builder (Elementor, Divi and others) uses its own product layout, place one of these shortcodes where the tabs should appear:

* `[bulktab_tabs]`: all tabs of the current product.
* `[bulktab_tab key="size-guide"]`: one shared tab, by its Tab ID (shown in the shared tab's sidebar).
* `[bulktab_tab slot="1"]`: one product tab, by its number (1–20).

Add `id="123"` to show the tabs of a specific product outside the product page.

= Does it work with block themes? =

Yes. With WooCommerce's default Product Details block, tabs work as in classic themes. If you switched that block to its accordion style, our tabs are added as accordion items, but renaming, hiding or reordering the default WooCommerce sections must be done in the Site Editor.

= Does it work with WPML or Polylang? =

Yes. Tab titles and content are translatable; the tab layout and which shared tabs a product uses are copied to translations. A shared tab with no translation yet falls back to the original language.

= Will it slow down my store? =

No extra scripts or styles are loaded on the storefront, and shared-tab rules are pre-compiled so each product page does very little work.

= What happens to my data if I deactivate or delete the plugin? =

Deactivating keeps everything. Deleting the plugin also keeps your tabs by default, so reinstalling brings them back. Tab data is only removed if you first tick "Delete all BulkTab data when the plugin is deleted" under Products → Tab settings.

== Changelog ==

= 0.1.0 =
* First release.
