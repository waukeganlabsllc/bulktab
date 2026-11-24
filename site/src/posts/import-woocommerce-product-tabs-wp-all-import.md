---
title: Import WooCommerce Product Tabs with WP All Import
description: Import and update WooCommerce product tabs from a supplier feed or spreadsheet with WP All Import, without touching prices, stock or other data.
slug: import-woocommerce-product-tabs-wp-all-import
date: 2026-11-24
updated: 2026-11-24
order: 6
query: import woocommerce product tabs wp all import
related: bulk-edit-woocommerce-product-tabs-csv, woocommerce-product-tabs-disappeared
image: s3
---
To import WooCommerce product tabs with WP All Import, store each tab in its own custom field, map your file's columns to those fields, and match existing products by SKU. With code, a short snippet turns one custom field into a tab. With BulkTab Pro, you map fields such as `bulktab:content:1` and every change keeps an undo copy.

## When WP All Import is the right tool

WooCommerce's own [CSV importer](https://woocommerce.com/document/product-csv-importer-exporter/) is enough when you export, edit and import your own products. Our guide to [bulk editing product tabs with CSV and Excel](/blog/bulk-edit-woocommerce-product-tabs-csv/) covers that.

WP All Import is the better fit when:

- **Data comes from a supplier** as XML, Excel or a feed rather than a WooCommerce export.
- **Imports run again and again**, such as a nightly update of specifications from a supplier.
- **You want to update only some fields**, leaving prices, stock and images exactly as they are.

Either way, the tab must be stored as plain fields. If a plugin packs all tabs into one encoded field, an import that writes to it can wipe every tab on the product. Our guide to [why WooCommerce product tabs disappear](/blog/woocommerce-product-tabs-disappeared/) explains why.

## Option one: one custom field per tab, with code

This snippet shows a "Specifications" tab from a custom field called `specs`. Products with an empty field get no tab.

{{snippet:specs-meta-tab}}

Add the code to a [child theme](https://developer.wordpress.org/themes/advanced-topics/child-themes/)'s `functions.php` file or a code snippets plugin. Then set up the import:

1. Go to **All Import → New Import**, upload your file, and choose **WooCommerce Products**. To update products you already have, choose **Existing Items**.
2. In the import template, open the **Custom Fields** section. Add a field named `specs` and drag the specifications column from your file into its value.
3. In the import settings, match records by **SKU**.
4. Turn on **Choose which data to update**, untick everything except **Custom Fields**, and choose to update only these custom fields: `specs`.
5. Run the import on a few records first, check the products, then run it on the full file.

Step four matters most. If WP All Import is set to update all custom fields, fields missing from the template can be erased. WP All Import's guide to [updating existing data](https://www.wpallimport.com/documentation/manual-record-matching/) covers these options.

We tested the snippet on a store with WooCommerce's own importer writing the same custom field. The downside is the same as with any code: each new tab needs another field and another change to the snippet, and an import that writes bad text replaces the old text for good.

## Option two: BulkTab Pro, no code

BulkTab stores each tab title and content as a separate plain field. [BulkTab Pro](/pricing/) adds WP All Import support, so imports go through BulkTab and keep its safety rules.

1. Install the free BulkTab plugin and BulkTab Pro, and activate your licence.
2. In the import template's **Custom Fields** section, add fields named after BulkTab's columns: `bulktab:title:1` and `bulktab:content:1` for the first tab, the next number for the next tab, up to 20 tabs. Lists of shared tab IDs go in `bulktab:attached`, `bulktab:hidden` and `bulktab:order`.
3. Map your file's columns into those fields.
4. Match records by **SKU**, and under **Choose which data to update** include those BulkTab fields.
5. Run the import.

If your template shows a **BulkTab** section, you can map the fields there instead. It appears when another WP All Import add-on on your site has loaded WP All Import's add-on library.

{{figure:s3}}

The rules are the same as BulkTab's CSV import:

- **Field not mapped:** left alone.
- **Value is `[keep]`:** left alone.
- **Value is empty:** cleared.

Nothing is deleted. The previous version of each tab is kept, so you can undo from the product's **Tabs** panel. This also applies to scheduled imports that WP All Import runs in the background. See all Pro features on the [features page](/features/).

## Common problems

### Tabs were cleared on products I didn't mean to change

The import was set to update all custom fields, and the file had empty values. Choose which data to update, and list only the fields you mean to change.

### The import created new products instead of updating them

The import wasn't matched to existing products. Choose **Existing Items** or match records by SKU, and check that the SKUs in the file match the store exactly.

### The tab doesn't show on a product

An empty field means no tab, with the snippet and with BulkTab. Check the field on the product. For BulkTab, check that the tab has a title or content after the import.

### Some BulkTab fields were ignored

Tab numbers go from 1 to 20. Fields with other numbers are ignored, and shared tab IDs that don't exist are reported in the import log.

## Frequently asked questions

### Can WP All Import update tabs without touching prices or stock?

Yes. Turn on **Choose which data to update** and select only the custom fields that hold your tabs. Everything else on the product is left as it is.

### Does the free BulkTab plugin work with WP All Import?

WP All Import support is part of BulkTab Pro. The free plugin works with WooCommerce's own CSV import and export, which is enough for editing your own products in a spreadsheet.

### Can I import tabs from a supplier feed on a schedule?

Yes. Set the import to run on a schedule in WP All Import. Each run follows the same rules, so unmapped tabs stay as they are.

### What happens to tabs that aren't in my file?

They are left alone. Only the fields you map are changed.
