---
title: How to Add a PDF to a WooCommerce Product Page
description: Put manuals, datasheets and instruction PDFs on WooCommerce product pages in a Downloads tab, with a short snippet or no code.
slug: add-pdf-woocommerce-product-page
date: 2026-11-26
updated: 2026-11-26
order: 7
query: add pdf to woocommerce product page
related: add-custom-product-tab-woocommerce, bulk-edit-woocommerce-product-tabs-csv
image: s4
---
To add a PDF to a WooCommerce product page, upload it to the Media Library and link to it from a product tab such as "Downloads". With code, a `woocommerce_product_tabs` snippet builds that tab from a custom field. With BulkTab, you insert the link in the tab editor, per product or once for a whole category.

## Where a PDF belongs on a product page

Manuals, datasheets, safety sheets and warranty terms are reference material. Most shoppers skip them; the ones who need them want them fast. A dedicated tab keeps the description about the product and puts the documents one click away.

Before you choose a method, sort your PDFs into two groups:

- **Different on every product**, such as a manual or a datasheet for one model. Each product needs its own link.
- **The same on many products**, such as warranty terms or a care guide for a whole category. One copy should be shown everywhere it applies, so you update it in one place.

This split decides the best setup. Treat a shared PDF like a per-product one and you end up with the same warranty file linked by hand from hundreds of descriptions, with old copies left behind every time it changes.

A PDF to read is not the same as a product to sell. If customers should only get the file after they pay, sell it as a downloadable product instead; see the WooCommerce guide to [digital and downloadable products](https://woocommerce.com/document/digital-downloadable-product-handling/). Files in the Media Library are public to anyone who has the link.

## Option one: add a Downloads tab with code

This snippet adds a "Downloads" tab to any product whose `product_documents` custom field has at least one PDF link. Products without links get no tab:

{{snippet:pdf-downloads-tab}}

How to use it:

1. Add the code to a [child theme](https://developer.wordpress.org/themes/advanced-topics/child-themes/)'s `functions.php` file or a code snippets plugin, never the parent theme.
2. Upload your PDF under **Media → Add New Media File**, open it, and click **Copy URL to clipboard**.
3. Edit the product. If you don't see the **Custom Fields** box, turn it on under **Screen Options** at the top of the screen.
4. Add a field named `product_documents`. Put one document per line: the link text, a vertical bar, then the URL, for example `Assembly manual | https://…/manual.pdf`.
5. Update the product and check the new tab on the storefront.

The snippet skips lines that are not full web addresses, escapes the link text and URLs, and adds the `download` attribute so the file saves instead of opening, for files on your own domain. We tested it in WordPress Playground with WooCommerce and PHP `8.3`, including products with no field, an empty field, Windows line breaks and lines with no URL.

The downside: a custom field is a plain text box, so a typo in a URL fails quietly, and a PDF shared by a whole category still has to be typed into every product. You can add the field in bulk through the CSV import as a `meta:product_documents` column; our guide to [bulk editing product tabs with CSV](/blog/bulk-edit-woocommerce-product-tabs-csv/) explains how.

## Option two: add PDFs with BulkTab, no code

BulkTab is a free plugin for product tabs, with no locked features. A tab can hold text, tables, images, video embeds, links to PDFs and shortcodes, written in the normal WordPress editor.

For a PDF that is different on every product:

1. Install [BulkTab]({{FREE_URL}}) and activate it.
2. Open the product and go to **Product data → Tabs**.
3. Under **Add a tab**, enter a title such as "Manuals" and some content, then click **Update**.
4. The tab now opens in the full editor. Click **Add Media**, choose the PDF, and insert it. WordPress adds a link to the file.
5. Update the product again. Each product can have up to 20 tabs of its own.

{{figure:s4}}

For a PDF shared by many products, such as your warranty terms:

1. Go to **Products → Shared tabs → Add shared tab** and name it, for example "Warranty".
2. Insert the PDF link with **Add Media**, plus a sentence on what the document covers.
3. Under **Show this tab on**, choose **All products**, or **Products in…** a category, tag or brand. Sub-categories are included.
4. Click **Publish**.

When the warranty changes, upload the new PDF and swap the link in that one shared tab. Every product shows the new version on the next page load, and new products in the category get the tab automatically. The [features page](/features/) shows how shared and per-product tabs work together; [adding a custom product tab](/blog/add-custom-product-tab-woocommerce/) covers the basics.

### Hundreds of products, each with its own manual

Export your products, fill the **BulkTab 1 content** column with each product's HTML link, and import the file back with **Update existing products** ticked. If your manuals come from a supplier feed, BulkTab Pro adds WP All Import support; see [pricing](/pricing/).

## Common problems

### The PDF opens in the browser instead of downloading

Browsers open PDFs by default. The `download` attribute on the link asks the browser to save the file instead, but only for files on your own domain. Links to another site, such as a manufacturer's server, still open in the browser.

### The tab doesn't show on the product

In code, check that the field name is exactly `product_documents` and that each line holds a full URL starting with `https://`. In BulkTab, empty tabs are hidden automatically, so a tab with no content won't appear. If your theme or page builder uses its own product layout, place the `[bulktab_tabs]` shortcode where the tabs should appear.

### The links broke after moving the site

PDF links usually contain the old domain. A search-and-replace on the database fixes them. BulkTab stores each tab as a plain field, so search-and-replace updates the links without damaging the tab. Our guide to [why product tabs disappear](/blog/woocommerce-product-tabs-disappeared/) covers other causes.

### The PDF is missing on translated products

The translated product needs the link too. With BulkTab, tab titles and content are translatable with WPML and Polylang, so each language can link to its own translated manual.

## Frequently asked questions

### Do I need a plugin to add a PDF to a WooCommerce product?

No. You can paste a link into the description, or use the snippet above for a separate tab. A plugin helps when many products share one PDF, or when staff without PHP access manage the files.

### Can I add more than one PDF to a product?

Yes. In the snippet, put one document per line in the custom field. In BulkTab, insert several links in one tab, or give each document its own tab.

### Can I show the PDF inside the page instead of linking to it?

You can embed it, but many phone browsers display embedded PDFs poorly. A clear link with a descriptive name, such as "Assembly manual (PDF)", works on every device.

### Can customers download the PDF only after buying?

Not with a tab, because tab links are visible to every visitor. Make it a downloadable product, so WooCommerce gives the file only to customers who bought it.
