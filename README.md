# BulkTab – Bulk Product Tabs for WooCommerce

Product tabs for large WooCommerce catalogs: set product info once, apply it everywhere, never lose it.

"BulkTab" / `bulktab` is a working name. The release slug, main file name and text domain are set at build time (see [Build](#build)).

- **Shared tabs** (`bulktab_tab` post type) shown on all products or on a category, tag or brand, plus per-product attach/hide.
- **Per-product tabs**: up to 20 per product, stored as plain post meta (`_bulktab_{n}_title`, `_bulktab_{n}_content`) with one-step undo.
- **Layout**: reorder, rename and hide any tab, including WooCommerce's defaults; empty tabs are hidden.
- **Fallbacks**: `[bulktab_tabs]` and `[bulktab_tab key="…"|slot="…"]` for page builders and custom templates.
- **WPML / Polylang** via `wpml-config.xml`.

Requirements: WordPress 6.8+, PHP 7.4+, WooCommerce (classic product editor).

The paid Pro add-on is a separate plugin in a separate repository. It only uses the public hooks described in [docs/extending.md](docs/extending.md); this repository contains no Pro or licensing code.

## Development setup

Requires PHP 7.4+ with Composer, plus Node.js and Docker for the local site.

```sh
composer install                 # PHPUnit, PHPCS, WPCS 3, PHPCompatibilityWP
npm install -g @wordpress/env    # once
wp-env start                     # WordPress + WooCommerce + this plugin, PHP 8.3 (see .wp-env.json)
```

The site runs at http://localhost:8888 (admin: http://localhost:8888/wp-admin, default wp-env credentials).

| Task | Command |
|---|---|
| Unit tests | `composer test` |
| Coding standards | `composer lint` (auto-fix: `vendor/bin/phpcbf`) |
| Build release zip | `php bin/build.php --slug=<slug> [--name="Display Name"]` |
| Stop / reset the site | `wp-env stop` / `wp-env clean all` |

## Build

`bin/build.php` copies the plugin (minus everything in `.distignore`) into `build/<slug>/`, renames `bulktab.php` to `<slug>.php`, rewrites the `Text Domain` header and every `'bulktab'` text-domain argument of the i18n functions to the slug, and writes `build/<slug>.zip`.

```sh
# Path A: takeover of the existing WordPress.org plugin
php bin/build.php --slug=yikes-inc-easy-custom-woocommerce-product-tabs

# Path B: our own slug
php bin/build.php --slug=<our-slug> --name="<Brand> Product Tabs for WooCommerce"
```

Options: `--no-zip` (folder only), `--src=<dir>` and `--domain=<domain>` (build another plugin, e.g. Pro), `--free-slug=<slug>` (rewrite `bulktab` in the `Requires Plugins` header).

## Branching and releases

- `main` = what is released. It must always pass CI.
- Work on feature branches (`feature/…`, `fix/…`) and merge to `main` through pull requests.
- To release: bump the version in the plugin header, `BULKTAB_VERSION` and `Stable tag` in `readme.txt`, update the changelog, then push a tag `vX.Y.Z`.
- The tag triggers `.github/workflows/deploy.yml`: it builds with `--slug=${{ vars.WPORG_SLUG }}`, commits to WordPress.org SVN (with `.wordpress-org/` as the assets folder) and attaches the zip to a GitHub release.
- Repository settings needed: variable `WPORG_SLUG`; secrets `SVN_USERNAME` and `SVN_PASSWORD` (the SVN password from your WordPress.org account settings).

CI (`.github/workflows/ci.yml`) runs PHPCS, unit tests on PHP 7.4 / 8.1 / 8.3 / 8.4, and Plugin Check on a built copy.

## Extending

See [docs/extending.md](docs/extending.md) for the hook contract.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
