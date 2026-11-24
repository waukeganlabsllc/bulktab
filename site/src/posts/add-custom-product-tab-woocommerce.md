---
title: How to Add a Custom Product Tab in WooCommerce
description: Add a custom tab to WooCommerce product pages with a short snippet or no code, for one product, a category or every product.
slug: add-custom-product-tab-woocommerce
date: 2026-11-24
updated: 2026-11-24
order: 1
query: add custom tab in woocommerce product page
related: woocommerce-size-chart-tab, rename-reorder-remove-woocommerce-tabs
image: s3
---
To add a custom tab to a WooCommerce product page, hook a function into the `woocommerce_product_tabs` filter that adds a title, a priority and a callback that prints the content. Or, with no code, use BulkTab: write the tab in the WordPress editor and choose where it shows, on one product, a category or every product.

## How WooCommerce product tabs work

Below the product summary, WooCommerce shows up to three tabs: Description, Additional information and Reviews. Behind the scenes they are entries in one PHP array. Before the page is printed, that array passes through the `woocommerce_product_tabs` filter, so any plugin or snippet can add, rename, reorder or remove entries. WooCommerce documents this in [Editing product data tabs](https://woocommerce.com/document/editing-product-data-tabs/).

Each tab entry has three parts:

- **title**: the label customers click.
- **priority**: where the tab sits. Lower numbers come first. WooCommerce gives Description `10`, Additional information `20` and Reviews `30`.
- **callback**: a function that prints the tab's content.

## Option one: add a tab with code

Use this if you are comfortable editing PHP and the tab content rarely changes.

1. Decide where the code goes. Use a [child theme](https://developer.wordpress.org/themes/advanced-topics/child-themes/)'s `functions.php` file or a code snippets plugin. Never edit the parent theme: WooCommerce's documentation warns that theme updates overwrite custom code added there.
2. Paste this snippet. It adds a "Shipping" tab to every product:

{{snippet:add-custom-tab}}

3. Change the title and the text in the callback to your own.
4. Choose the position with `priority`. The `25` in the example places the tab between Additional information and Reviews.
5. Open any product page and check the new tab. If your site uses a page cache, clear it first.

We tested this snippet on a WooCommerce store running the Twenty Twenty-Five theme before publishing.

### Showing the tab on some products only

The snippet above adds the tab everywhere. To limit it, check the product inside the filter and return `$tabs` unchanged when it doesn't match. Our [size chart guide](/blog/woocommerce-size-chart-tab/) shows a tested version that adds a tab only to products in one category, sub-categories included.

### The limits of the code approach

The tab text lives in your theme or snippet plugin, so every wording change means editing code. One snippet gives every product the same text unless you also add custom fields. And if the code sits in a child theme, switching themes removes the tab.

## Option two: add a tab without code

BulkTab is a free plugin that does the same job from the WordPress admin, with no locked features.

1. Install [BulkTab from WordPress.org]({{FREE_URL}}) and activate it. WooCommerce must be active.
2. Go to **Products → Shared tabs** and click **Add shared tab**.
3. Give the tab a title, such as "Shipping", and write the content in the normal editor. A tab can hold text, tables, images, video embeds, links to PDFs and shortcodes.
4. Under **Show this tab on**, pick **All products**, or **Products in…** a category, tag or brand. Sub-categories are included.
5. Click **Publish**. Every matching product shows the tab, and new products in that category get it automatically.

{{figure:s3}}

To change the text later, edit the shared tab once and every product shows the new version on the next page load.

### A tab for one product only

Open the product, go to **Product data → Tabs**, and add a tab under **This product's tabs**. Each product can have up to 20 tabs of its own. You can also attach a shared tab to a single product, or hide it on one product, from the same panel.

## Which option should you choose?

| Need | Code snippet | BulkTab |
|---|---|---|
| Who can edit the tab text | Someone who can edit PHP | Anyone with admin access |
| Show on a category, tag or brand | Extra code per rule | Pick it in the editor |
| Different tabs per product | Needs custom fields | Built in |
| Survives a theme switch | Only if not in the theme | Yes |
| Edit in a spreadsheet | No | Yes, in the WooCommerce CSV |

If you need one fixed tab and you already maintain a child theme, the snippet is fine. If store staff will edit the content, or different products need different tabs, a plugin saves time.

## Common problems

### The tab doesn't appear

First check that the callback prints something: a tab with no content is easy to miss. Then check your theme. Some themes and page builders replace WooCommerce's product template and never print the tabs. In that case use your builder's product tabs element, or BulkTab's `[bulktab_tabs]` shortcode where the tabs should appear.

### The tab appears twice

The same code was probably added in two places, such as the theme and a snippet plugin, or two plugins add a similar tab. Remove one copy.

### The tab is in the wrong position

Change its `priority`. Remember that lower numbers appear first, and that other plugins may also change priorities.

### The site shows an error after pasting the code

A missing bracket or quote stops PHP. Remove the code through your host's file manager or SFTP, then paste it again carefully. Code snippets plugins usually catch these errors before saving.

## Frequently asked questions

### Can I add a custom tab to just one product?

Yes. In code, compare `$product->get_id()` with the product's ID inside the filter. In BulkTab, open the product and add a tab in its **Tabs** panel, or attach a shared tab to that product.

### Where should I put WooCommerce tab code?

In a child theme's `functions.php` file or a code snippets plugin. Never in the parent theme, because theme updates overwrite it.

### How many tabs can a WooCommerce product have?

WooCommerce itself doesn't set a limit. With BulkTab, each product can have up to 20 tabs of its own, plus any shared tabs that match it.

### Can I edit custom tabs in bulk?

With code, only by editing the code. With BulkTab, edit a shared tab once to update every product, or edit product tabs in the WooCommerce product CSV export and import the file back.
