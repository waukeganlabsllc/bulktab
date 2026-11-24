// Boots a WooCommerce-only Playground with the blog snippets as mu-plugins and
// checks the product pages. Exit code 0 = all snippets behave as the posts say.
//   TOOLS=/home/claude/tools node screens/test-snippets.mjs
import { createRequire } from 'node:module';
import { spawn } from 'node:child_process';
import { mkdtempSync, readFileSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const TOOLS = process.env.TOOLS || '/home/claude/tools';
const require = createRequire(join(TOOLS, 'package.json'));
const { chromium } = require('playwright');
const HERE = dirname(fileURLToPath(import.meta.url));
const SNIPPETS = resolve(HERE, '../src/snippets');
const OUT = mkdtempSync(join(tmpdir(), 'bt-snip-'));
const PORT = process.env.PORT || '9503';
const base = `http://127.0.0.1:${PORT}`;

const srv = spawn(join(TOOLS, 'node_modules/.bin/wp-playground-cli'), [
  'server', `--port=${PORT}`, '--php=8.3',
  `--mount=${SNIPPETS}:/wordpress/wp-content/mu-plugins`, `--mount=${HERE}:/screens`, `--mount=${OUT}:/screens-out`,
  `--blueprint=${join(HERE, 'snippets-blueprint.json')}`, '--blueprint-may-read-adjacent-files',
  '--define-bool', 'WP_DEBUG', 'true', '--define-bool', 'WP_DEBUG_DISPLAY', 'false', '--define', 'WP_DEBUG_LOG', '/screens-out/debug.log',
], { stdio: ['ignore', 'pipe', 'pipe'] });
let log = '';
srv.stdout.on('data', (d) => (log += d));
srv.stderr.on('data', (d) => (log += d));
const stop = () => { try { srv.kill('SIGTERM'); } catch {} };
process.on('exit', stop);

for (let i = 0; i < 200 && !log.includes('Ready!'); i++) await new Promise((r) => setTimeout(r, 3000));
if (!log.includes('Ready!')) { console.error(log); process.exit(2); }

const ids = JSON.parse(readFileSync(join(OUT, 'snippet-ids.json'), 'utf8'));
const browser = await chromium.launch();
const page = await browser.newPage();
await page.goto(base + '/wp-admin/', { waitUntil: 'networkidle', timeout: 180000 });
const tabs = async (id) => {
  await page.goto(`${base}/?post_type=product&p=${id}`, { waitUntil: 'networkidle', timeout: 180000 });
  return page.$$eval('.wc-tabs li', (lis) => lis.map((li) => ({ id: li.id.replace('tab-title-', ''), title: li.textContent.trim() })));
};
const boot = await tabs(ids['Trail Boot']);
const tee = await tabs(ids['Crew Tee']);
const sizeTable = await (async () => { await page.goto(`${base}/?post_type=product&p=${ids['Trail Boot']}`); return page.locator('#tab-size_chart table').count(); })();
await browser.close();

const fails = [];
const keys = (t) => t.map((x) => x.id).join(',');
const expect = (ok, msg) => { console.log((ok ? 'PASS ' : 'FAIL ') + msg); if (!ok) fails.push(msg); };
console.log('Trail Boot tabs:', keys(boot), '| Crew Tee tabs:', keys(tee));
expect(boot.some((t) => t.id === 'shipping_info' && t.title === 'Shipping') && tee.some((t) => t.id === 'shipping_info'), 'post 1: Shipping tab on every product');
expect(boot.find((t) => t.id === 'description')?.title === 'Details', 'post 2: Description renamed Details');
expect(boot[0]?.id === 'reviews' && tee[0]?.id === 'reviews', 'post 2: Reviews moved first');
expect(!boot.some((t) => t.id === 'additional_information') && !tee.some((t) => t.id === 'additional_information'), 'post 2: Additional information removed');
expect(boot.some((t) => t.id === 'size_chart') && sizeTable === 1, 'post 3: Size chart on a product in a Shoes sub-category');
expect(!tee.some((t) => t.id === 'size_chart'), 'post 3: no Size chart outside Shoes');
const dbg = existsSync(join(OUT, 'debug.log')) ? readFileSync(join(OUT, 'debug.log'), 'utf8') : '';
expect(/Product tabs on \d+: .*reviews \(5\).*shipping_info \(25\)/.test(dbg), 'post 4: debug snippet logs final tabs with priorities');
expect(!/PHP (Fatal|Warning|Notice|Deprecated).*mu-plugins/.test(dbg), 'no PHP errors from the snippets');
stop();
process.exit(fails.length ? 1 : 0);
