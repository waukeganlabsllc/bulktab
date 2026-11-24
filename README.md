# BulkTab – Bulk Product Tabs for WooCommerce

**Tabs that scale with your catalog: set product info once, apply it everywhere, never lose it.**

Free on WordPress.org · Pro add-on $49/yr · by Waukegan Labs LLC · GPL-2.0-or-later

**Mission:** give WooCommerce stores with large catalogs product tabs (shipping, size guides, specs, manuals, FAQs) they can manage in bulk and trust to survive updates, imports and migrations.

## The problem

The most-installed product-tabs plugin, Custom Product Tabs for WooCommerce (YIKES), runs on about 70,000 stores and was last updated in April 2025. The next-largest alternatives have 10,000 installs each.

We read 530 reviews of eight tab plugins and 750 YIKES support threads. Four problems keep coming back:

| # | Problem | Evidence |
|---|---|---|
| 1 | Tabs disappear after updates, imports and migrations | 26% of support threads. 5 of 39 one-star reviews describe tab content lost or overwritten; one reads simply "Tabs disappeared randomly." |
| 2 | The same tab is typed into every product | 12% of threads, and "write once, apply to many" is the main reason people pay |
| 3 | Tabs break with the theme, page builder or importer | 21% theme or page builder (Elementor, Divi, WPBakery); 14% import/export; 6% WPML |
| 4 | No control over the product page | 18% want to reorder, rename or hide tabs (including WooCommerce's own) and hide empty ones. 2 one-star reviews are about reordering being locked behind a paid plan |

Behind all four sits abandonment: 9 of the 21 one-star YIKES reviews are about unanswered support or a broken Pro licence, and 26 of the 750 threads were opened after its last release.

## What changes for a store

| Before | After |
|---|---|
| An update or a CSV round trip wipes tab content | Tabs survive updates, imports and migrations. Anything unreadable raises a visible warning instead of an empty tab, and product tabs have a one-step undo |
| The same tab pasted into every product | Write it once; show it on all products or a category, tag or brand. Edit once, every product updates |
| Tabs vanish under a theme, builder or translation plugin | Works with classic and block themes, Elementor, Divi and WPML; shortcodes cover custom templates |
| WooCommerce's default tabs are fixed; empty tabs still show | Reorder, rename or hide any tab, including WooCommerce's own. Empty tabs hide themselves |
| Moving off YIKES means retyping everything | One-click import with a dry run first. YIKES data is never modified |

## Why we built it this way

| What the data showed | What we decided |
|---|---|
| YIKES stores each product's tabs as one serialized blob and hides read errors, so one damaged character silently empties them. Its CSV export puts that blob in a cell, where spreadsheet edits break it. | Each tab is plain text in its own field, verified after every write. CSV columns hold plain, editable content. Problems produce a warning, never silence. |
| YIKES copies shared-tab content into every product and re-syncs it, so copies drift apart. | Shared tabs are their own records. Products point to them instead of holding copies. |
| "Write once" is why people pay (12% of threads). | Shared tabs with a rule (all products, or one category, tag or brand) are free. Pro adds precision and scale for big catalogs. |
| Page control is 18% of threads, and paywalled reordering earns one-star reviews. | Page control is free. The free plugin contains no locked features. |
| Abandonment is the root of the anger. | We publish a support target and measure it (below). |

## Free vs Pro

| What you can do | Free | Pro ($49/yr) |
|---|---|---|
| Add rich tabs per product: text, tables, images, video, PDF links, shortcodes | Yes | Yes |
| Write a tab once; show it on all products or one category, tag or brand | Yes | Yes |
| Reorder, rename or hide any tab; auto-hide empty tabs | Yes | Yes |
| Edit tabs safely through WooCommerce CSV import/export | Yes | Yes |
| Import from YIKES with a dry run | Yes | Yes |
| Classic and block themes, Elementor, Divi, WPML | Yes | Yes |
| Target precisely: combine conditions and exclusions (category A but not tag B) | | Yes |
| Keep a shared tab and add extra text for one product | | Yes |
| Add, remove or replace a tab across hundreds or thousands of products at once | | Yes |
| Back up and restore all tab data | | Yes |
| WP All Import support | | Yes |

## Goals and how we measure them

- 500+ free installs within 60 days of launch
- 10+ Pro customers within 60 days of launch
- 0 "lost my tabs" reports
- 100% of support threads answered within 48 hours

## Proof it works

BulkTab passes 52 scripted pass/fail acceptance checks (Free + Pro) on a real WordPress + WooCommerce store, run in CI. Examples: a spreadsheet-edited CSV re-imports exactly; a YIKES dry run writes nothing; re-running an import creates no duplicates; tabs render in the default block theme; no PHP errors or warnings.

## Status and roadmap

- **Done:** MVP built, Free and Pro.
- **Next:** WordPress.org plugin review, then launch.
- **Not in this release:** accordion and mobile layouts (10% of threads), FAQ schema, search indexing of tab content.

## How we got these numbers

- **Problem percentages:** our topic classification of 750 YIKES support threads and 530 WordPress.org reviews of eight tab plugins.
- **Installs and last update:** WordPress.org plugin directory, November 2026.
- **One-star counts:** 39 of the 530 reviews are one-star (21 for YIKES); we read and tagged all 39 by hand.
- **Threads after the last release:** threads dated after 12 April 2025, the last YIKES update.

## For developers

Requires WordPress 6.8+, PHP 7.4+ and WooCommerce (classic product editor). Pro is a separate plugin that uses only the public hooks in [docs/extending.md](docs/extending.md); this repository has no Pro or licensing code.

```sh
composer install                      # dev tools: PHPUnit, PHPCS (WPCS 3)
composer test                         # unit tests
bin/smoke.sh [path-to-bulktab-pro]    # end-to-end acceptance checks (needs Node 20+)
php bin/build.php --slug=<slug>       # release zip in build/
```

Contributions go through pull requests to `main`, which must pass CI. License: GPL-2.0-or-later, see [LICENSE](LICENSE).
