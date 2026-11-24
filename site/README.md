# BulkTab marketing site

Static site for **bulktab.waukeganlabs.com**: plain HTML and CSS built by a small Node script, served by a Cloudflare Worker with static assets (no Worker code). Not shipped in the plugin zip (`/site` is in `.distignore`).

## Layout

| Path | What it is |
|---|---|
| `src/config.json` | Site settings: `FREE_URL`, blog author, Freemius ids, support email, links |
| `src/pages/*.html` | Pages. JSON meta (title, description, schema types) in the leading `<!--page … -->` comment |
| `src/posts/*.md` | Blog posts with `key: value` front matter (`title, description, slug, date, updated, query, related, image`) |
| `src/snippets/*.php` | PHP snippets shown in the posts (tested, see below) |
| `src/templates/` | `base.html` layout and the header, footer and CTA partials |
| `src/screens.json` | Screenshot file names, alt text and captions |
| `src/assets/` | CSS, self-hosted fonts (SIL OFL), icons, screenshots |
| `src/static/_headers` | Security and cache headers (Workers static assets `_headers` file) |
| `src/contrast.json` | Text/background colour pairs checked for WCAG AA |
| `scripts/build.mjs` | Renders `src/` to `dist/`, plus `sitemap.xml`, `rss.xml`, `llms.txt`, `robots.txt` |
| `scripts/check.mjs` | The acceptance checklist (SEO tags, JSON-LD, links, banned words, images, html-validate, weight, contrast, sitemap/RSS, Freemius, numbers, posts, deploy config) |
| `screens/` | Tools that capture the real screenshots from a WordPress Playground store |
| `wrangler.toml` | Worker `bulktab-site`, assets from `./dist`, custom domain |

Template tokens: `{{FREE_URL}}` and any other `config.json` key (dotted for nested keys, e.g. `{{freemius.checkoutUrl}}`), `{{figure:s1}}` for a screenshot, `{{snippet:name}}` for a PHP snippet, `{{> cta}}` for the call-to-action band.

## Build and check

Node 20 or later.

```sh
cd site
npm ci
npm run build    # writes dist/
npm run check    # exits non-zero if any acceptance check fails
```

## Preview locally

```sh
npm run build
npx wrangler dev          # serves dist/ like production (404 page, trailing slashes, _headers)
# or any static server:
npx serve dist            # or: cd dist && python3 -m http.server 8788
```

## Deploy

Pushes to `main` that touch `site/**` run `.github/workflows/site.yml`: build, check, then `wrangler deploy` through `cloudflare/wrangler-action`. Pull requests only build and check.

The deploy job needs two repository secrets (Settings → Secrets and variables → Actions):

- `CLOUDFLARE_API_TOKEN`: a token with the "Edit Cloudflare Workers" permissions for the account, plus Zone DNS edit on `waukeganlabs.com` for the custom domain.
- `CLOUDFLARE_ACCOUNT_ID`

Without them the deploy step is skipped with a notice; the build and check still run. To deploy by hand: `npm run build && npx wrangler deploy` (after `npx wrangler login`).

The custom domain `bulktab.waukeganlabs.com` is created by the `[[routes]] … custom_domain = true` entry on first deploy. Nothing on the main waukeganlabs.com site is touched.

## Switch the free download link (FREE_URL)

Every "Get BulkTab free" button reads one value. Edit `src/config.json`:

```json
"FREE_URL": "https://wordpress.org/plugins/bulktab/"
```

Rebuild and deploy. `npm run check` fails if any "Get BulkTab free" link does not use this value.

## Change the blog author

`author` in `src/config.json` is used for every post byline, the about box and the `BlogPosting` JSON-LD. To credit a person, set `"type": "Person"`, `"name"`, `"url"` (for example a profile page) and `"bio"`.

## Checkout

`/pricing/` loads Freemius Checkout JS (`https://checkout.freemius.com/js/v1/`) and opens the overlay with product 46190, plan 81330 and the public key from `config.json`. Each buy button is a normal link to the hosted checkout (`https://checkout.freemius.com/product/46190/plan/81330/`), so it still works with JavaScript off or the script blocked. No other page loads third-party scripts.

## Screenshots

All screenshots are real captures from a fictional store ("Harbor Outfitters") in WordPress Playground with WooCommerce, Twenty Twenty-Five, BulkTab and (for Pro shots) BulkTab Pro with `BULKTAB_PRO_DEV`. Playwright and sharp live outside the repo (default `/home/claude/tools`, set `TOOLS=`).

```sh
screens/serve.sh free 9501 /tmp/scr-free &    # wait for "Ready!"
screens/serve.sh pro  9502 /tmp/scr-pro  &
TOOLS=/path/to/tools node screens/capture.mjs http://127.0.0.1:9501 http://127.0.0.1:9502 /tmp/scr-free/ids.json
```

`capture.mjs` writes WebP files (under 300 KB) and `og-card.png` (1200×630) to `src/assets/screens/`. The Pro part runs a bulk job, so restart the Pro store before re-running it (`ONLY=free|pro|og` runs one part). Update `src/screens.json` if a screen changes.

## Snippets

The PHP snippets in the posts are tested in a WooCommerce-only Playground (no tab plugin) as must-use plugins:

```sh
TOOLS=/path/to/tools node screens/test-snippets.mjs
```
