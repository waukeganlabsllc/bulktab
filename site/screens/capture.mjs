// Captures the site's real screenshots from the Harbor Outfitters Playground stores.
//
//   screens/serve.sh free 9501 /tmp/scr-free &   (wait for "Ready!")
//   screens/serve.sh pro  9502 /tmp/scr-pro  &
//   TOOLS=/home/claude/tools node screens/capture.mjs http://127.0.0.1:9501 http://127.0.0.1:9502 /tmp/scr-free/ids.json
//
// Playwright and sharp are loaded from $TOOLS (kept outside the repo).
import { createRequire } from 'node:module';
import { readFileSync, mkdirSync, statSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const TOOLS = process.env.TOOLS || '/home/claude/tools';
const require = createRequire(join(TOOLS, 'package.json'));
const { chromium } = require('playwright');
const sharp = require('sharp');

const [, , FREE, PRO, IDS] = process.argv;
const ids = JSON.parse(readFileSync(IDS, 'utf8'));
const HERE = dirname(fileURLToPath(import.meta.url));
const OUT = join(HERE, '..', 'src', 'assets', 'screens');
mkdirSync(OUT, { recursive: true });
const MAX = 300 * 1024;

// Hide only third-party admin chrome that is not BulkTab UI (Freemius dev-mode
// activation nag, WooCommerce marketing banners). BulkTab screens are untouched.
const CLEAN_CSS = `
  .fs-notice, .fs-plugin-title, .fs-slug-bulktab-pro, .woocommerce-layout__activity-panel, .woocommerce-message.updated,
  #wp-admin-bar-wc-admin-store-status, .notice.wcs-nux__notice { display: none !important; }
`;

async function save(buf, name, maxWidth = 1600) {
  let q = 82;
  let out;
  for (;;) {
    out = await sharp(buf).resize({ width: maxWidth, withoutEnlargement: true }).webp({ quality: q, effort: 6 }).toBuffer();
    if (out.length < MAX - 4096 || q <= 50) break;
    q -= 6;
  }
  const meta = await sharp(out).metadata();
  await sharp(out).toFile(join(OUT, name));
  console.log(name, meta.width + 'x' + meta.height, Math.round(statSync(join(OUT, name)).size / 1024) + ' KB', 'q' + q);
  return out;
}

async function open(base) {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 2 });
  const page = await ctx.newPage();
  await page.goto(base + '/wp-admin/', { waitUntil: 'networkidle', timeout: 180000 });
  return { browser, page };
}

async function clean(page) {
  await page.addStyleTag({ content: CLEAN_CSS });
  await page.evaluate(() => document.querySelectorAll('.fs-notice').forEach((n) => n.remove()));
  await page.waitForTimeout(800);
}

async function go(page, url) {
  await page.goto(url, { waitUntil: 'networkidle', timeout: 180000 });
  await clean(page);
}

// Screenshot of a region spanning one or more elements, with padding.
async function region(page, selectors, pad = 16, extra = {}) {
  // Tall viewport instead of fullPage, so sticky bars and lazy editors render correctly.
  const vw = page.viewportSize();
  const h = await page.evaluate(() => document.documentElement.scrollHeight);
  if (h > vw.height) await page.setViewportSize({ width: vw.width, height: Math.min(h, 6000) });
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.waitForTimeout(400);
  const boxes = [];
  for (const s of selectors) {
    const b = await page.locator(s).first().boundingBox();
    if (!b) throw new Error('No element for ' + s);
    boxes.push(b);
  }
  const x = Math.max(0, Math.min(...boxes.map((b) => b.x)) - pad);
  const y = Math.max(0, Math.min(...boxes.map((b) => b.y)) - pad);
  const r = Math.max(...boxes.map((b) => b.x + b.width)) + pad;
  const btm = Math.max(...boxes.map((b) => b.y + b.height)) + pad + (extra.bottom || 0);
  const shot = await page.screenshot({ clip: { x, y, width: r - x, height: btm - y } });
  await page.setViewportSize(vw);
  return shot;
}

const t = ids.tabs;
const boot = ids.products['Trail Boot'];

// ONLY=free|pro|og runs one part (the Pro part changes the store: restart it before re-running).
const ONLY = process.env.ONLY || '';

// ---------------------------------------------------------------- Free store
if (!ONLY || ONLY === 'free') {
  const { browser, page } = await open(FREE);

  // S1: shared tab editor, "Size guide – shoes", Category = Shoes (main column only).
  await go(page, `${FREE}/wp-admin/post.php?post=${t.shoes.id}&action=edit`);
  await page.locator('#bulktab-rules select[multiple]').first().evaluate((el) => {
    const o = [...el.options].find((x) => x.selected);
    if (o) o.scrollIntoView({ block: 'nearest' });
  });
  await save(await region(page, ['.wp-heading-inline', '#titlediv', '#postdivrich', '#bulktab-rules']), 's1-shared-tab-editor.webp');
  // S1b: just the "Show this tab on" box: the rule that does the work.
  await save(await region(page, ['#bulktab-rules'], 0), 's1b-show-this-tab-on.webp', 1400);

  // S3: shared tabs list.
  await go(page, `${FREE}/wp-admin/edit.php?post_type=bulktab_tab&orderby=title&order=asc`);
  await save(await region(page, ['.wp-heading-inline', '.wp-list-table']), 's3-shared-tabs-list.webp');

  // S4: Trail Boot, Product data > Tabs panel.
  await go(page, `${FREE}/wp-admin/post.php?post=${boot}&action=edit`);
  await page.locator('#woocommerce-product-data .product_data_tabs a', { hasText: /^Tabs$/ }).click();
  await page.waitForTimeout(800);
  // S4: the panel itself: shared tabs with rule badges and attach/hide, then the product's own "Materials" tab.
  await save(await region(page, ['#bulktab_product_data .bulktab-shared', '#bulktab_product_data .bulktab-own .bulktab-tab'], 8), 's4-product-tabs-panel.webp', 1400);
  // S4b: per-product order list with the hidden Additional information row.
  await save(await region(page, ['#bulktab_product_data .bulktab-order'], 8), 's4b-product-tab-order.webp');

  // S5: Tab settings (Description renamed "Details", Reviews hidden).
  await go(page, `${FREE}/wp-admin/edit.php?post_type=product&page=bulktab-settings`);
  {
    const vw = page.viewportSize();
    await page.setViewportSize({ width: 1440, height: 2000 });
    const head = await page.locator('.bulktab-settings h1').boundingBox();
    const until = await page.locator('.bulktab-settings h2', { hasText: 'Options' }).first().boundingBox();
    await save(await page.screenshot({ clip: { x: head.x - 16, y: head.y - 16, width: 1080, height: until.y - head.y + 8 } }), 's5-tab-settings.webp');
    await page.setViewportSize(vw);
  }

  // S2: storefront, Trail Boot with the Size guide tab open (logged-out view).
  const ctx2 = await browser.newContext({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 2 });
  const shop = await ctx2.newPage();
  await shop.goto(`${FREE}/?post_type=product&p=${boot}`, { waitUntil: 'networkidle', timeout: 180000 });
  await shop.addStyleTag({ content: '#wpadminbar{display:none!important} html{margin-top:0!important}' });
  await shop.locator('.wc-tabs a', { hasText: 'Size guide' }).first().click();
  await shop.waitForTimeout(500);
  // Tabs and the open panel (the fixture store has no product photos).
  const tabsOnly = await region(shop, ['.wc-tabs', '.woocommerce-Tabs-panel:visible'], 24);
  await save(tabsOnly, 's2-storefront-size-guide.webp');
  await sharp(tabsOnly).png().toFile('/tmp/og-source.png');
  await ctx2.close();

  await browser.close();
}

// ---------------------------------------------------------------- Pro store
if (PRO && (!ONLY || ONLY === 'pro')) {
  const { browser, page } = await open(PRO);
  const pro = JSON.parse(readFileSync(IDS.replace('scr-free', 'scr-pro'), 'utf8'));

  // S7: combined rule "Shoes AND NOT Sale" with the live count.
  await go(page, `${PRO}/wp-admin/post.php?post=${pro.pro_tab}&action=edit`);
  await page.waitForTimeout(2500);
  await save(await region(page, ['#bulktab-rules'], 0), 's7-pro-combined-rule.webp');

  // S8: bulk replace across Apparel, dry-run preview.
  await go(page, `${PRO}/wp-admin/edit.php?post_type=product&page=bulktab-pro-bulk`);
  await page.locator('input[name="sel[target]"][value="cat"]').check();
  await page.locator('#bulktab-pro-terms-cat').evaluate((el, id) => {
    for (const o of el.options) o.selected = String(o.value) === String(id);
    el.dispatchEvent(new Event('change', { bubbles: true }));
  }, pro.terms.apparel);
  await page.locator('input[name="op[type]"][value="replace"]').check();
  await page.locator('#bulktab-pro-find').fill('Machine wash at 40°C');
  await page.locator('#bulktab-pro-replace').fill('Machine wash at 30°C');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle', timeout: 180000 }), page.locator('.bulktab-pro-bulk-form button[type="submit"]').click()]);
  await clean(page);
  // S8: dry-run heading, counts and the first three before/after rows.
  await save(await region(page, ['.bulktab-pro-bulk h1', '.bulktab-pro-bulk h2', '.bulktab-pro-bulk table >> nth=0', '.bulktab-pro-bulk table >> nth=1 >> tr >> nth=3'], 8), 's8-pro-bulk-preview.webp', 1400);

  // Run the job, then show the finished job with its one-click undo.
  const run = page.locator('.bulktab-pro-bulk form button[type="submit"]', { hasText: /^\s*Run on/ }).first();
  if (await run.count()) {
    page.once('dialog', (d) => d.accept());
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle', timeout: 180000 }), run.click()]);
    await clean(page);
    for (let i = 0; i < 60; i++) {
      if (await page.locator('button', { hasText: 'Undo this job' }).count()) break;
      await page.waitForTimeout(2000);
      if (i % 5 === 4) {
        await page.reload({ waitUntil: 'networkidle' });
        await clean(page);
      }
    }
    await save(await region(page, ['.bulktab-pro-bulk'], 8), 's8b-pro-bulk-job-undo.webp');
  }

  // S9: Tab backups.
  await go(page, `${PRO}/wp-admin/edit.php?post_type=product&page=bulktab-backups`);
  const dismiss = page.getByRole('button', { name: 'Dismiss' });
  if (await dismiss.count()) {
    await Promise.all([page.waitForLoadState('networkidle'), dismiss.first().click()]);
    await page.waitForTimeout(1500);
    await go(page, `${PRO}/wp-admin/edit.php?post_type=product&page=bulktab-backups`);
  }
  await save(await region(page, ['.wrap h1', '.wrap table'], 16, { bottom: 0 }), 's9-pro-backups.webp');

  await browser.close();
}

// ---------------------------------------------------------------- OG card (1200x630) from S2
{
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1200, height: 630 } });
  const fonts = join(HERE, '..', 'src', 'assets', 'fonts');
  const src = sharp('/tmp/og-source.png');
  const m = await src.metadata();
  const shot = (await src.extract({ left: 0, top: 0, width: Math.min(m.width, 1400), height: Math.min(m.height, 1400) }).resize({ width: 1240 }).png().toBuffer()).toString('base64');
  const icon = readFileSync(join(HERE, '..', 'src', 'assets', 'img', 'bulktab-icon.svg'), 'utf8');
  const sg = readFileSync(join(fonts, 'space-grotesk-latin-wght.woff2')).toString('base64');
  const inter = readFileSync(join(fonts, 'inter-latin-wght.woff2')).toString('base64');
  await page.setContent(`<!doctype html><html><head><style>
    @font-face{font-family:SG;src:url(data:font/woff2;base64,${sg}) format('woff2');font-weight:300 700}
    @font-face{font-family:Inter;src:url(data:font/woff2;base64,${inter}) format('woff2');font-weight:100 900}
    *{margin:0;box-sizing:border-box} body{width:1200px;height:630px;background:#1E3A5F;color:#fff;font-family:Inter;overflow:hidden;position:relative}
    .l{position:absolute;left:64px;top:64px;width:520px}
    .brand{display:flex;align-items:center;gap:16px;font:700 34px SG}
    .brand svg{width:64px;height:64px}
    h1{font:700 52px/1.08 SG;margin-top:40px;letter-spacing:-.5px}
    h1 span{color:#F5A524}
    p{font:400 24px/1.4 Inter;margin-top:24px;color:#D9E2EE}
    .shot{position:absolute;left:624px;top:72px;width:620px;height:600px;border-radius:14px;overflow:hidden;background:#fff;box-shadow:0 20px 60px rgba(0,0,0,.35)}
    .shot img{width:620px;display:block}
  </style></head><body>
    <div class="l"><div class="brand">${icon}BulkTab</div>
    <h1>Write a product tab once. <span>Show it on every matching product.</span></h1>
    <p>Shared WooCommerce tabs by category, tag or brand.</p></div>
    <div class="shot"><img src="data:image/png;base64,${shot}" alt=""></div>
  </body></html>`);
  await page.waitForTimeout(500);
  const og = await page.screenshot({ type: 'png' });
  const png = await sharp(og).png({ palette: true, quality: 90, effort: 10 }).toBuffer();
  await sharp(png).toFile(join(OUT, 'og-card.png'));
  console.log('og-card.png', Math.round(png.length / 1024) + ' KB');
  await browser.close();
}
