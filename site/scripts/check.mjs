// Deterministic acceptance checks for the built site (SITE-PLAN.md §7).
//   npm run build && npm run check
// Exits 1 if any check fails. Each check prints PASS/FAIL with details.
import { readFileSync, readdirSync, statSync, existsSync } from 'node:fs';
import { join, dirname, relative, extname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { HtmlValidate } from 'html-validate';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const DIST = join(ROOT, 'dist');
const SRC = join(ROOT, 'src');
const REPO = join(ROOT, '..');
const cfg = JSON.parse(readFileSync(join(SRC, 'config.json'), 'utf8'));
const SITE = cfg.siteUrl.replace(/\/$/, '');

const results = [];
function check(name, fn) {
  const errors = [];
  try {
    fn((msg) => errors.push(msg));
  } catch (e) {
    errors.push('exception: ' + (e.stack || e));
  }
  results.push({ name, errors });
}
const walk = (dir) =>
  readdirSync(dir).flatMap((n) => {
    const p = join(dir, n);
    return statSync(p).isDirectory() ? walk(p) : [p];
  });
const read = (p) => readFileSync(p, 'utf8');
const decode = (s) =>
  s.replace(/&nbsp;/g, ' ').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&amp;/g, '&');
const text = (html) => decode(html.replace(/<[^>]+>/g, ' ')).replace(/\s+/g, ' ').trim();
const words = (s) => (s.match(/[A-Za-z0-9][A-Za-z0-9'’.\-–/]*/g) || []).length;
const attr = (tag, name) => {
  const m = tag.match(new RegExp(`\\s${name}="([^"]*)"`));
  return m ? decode(m[1]) : null;
};
const meta = (html, key, val) => {
  const tags = html.match(/<meta\s[^>]*>/g) || [];
  const t = tags.find((x) => attr(x, key) === val);
  return t ? attr(t, 'content') : null;
};

if (!existsSync(DIST)) {
  console.error('dist/ not found: run npm run build first.');
  process.exit(1);
}

// ------------------------------------------------------------------ inventory
const POSTS = ['add-custom-product-tab-woocommerce', 'rename-reorder-remove-woocommerce-tabs', 'woocommerce-size-chart-tab', 'woocommerce-product-tabs-disappeared'];
const PAGES = ['/', '/features/', '/pricing/', '/blog/', ...POSTS.map((s) => `/blog/${s}/`), '/support/', '/privacy/'];
const fileFor = (path) => (path === '/404.html' ? join(DIST, '404.html') : join(DIST, path, 'index.html'));
const htmlFiles = walk(DIST).filter((f) => f.endsWith('.html'));
const pathOf = (f) => {
  const r = '/' + relative(DIST, f).split('\\').join('/');
  return r === '/404.html' ? r : r.replace(/index\.html$/, '');
};
const pages = Object.fromEntries(htmlFiles.map((f) => [pathOf(f), read(f)]));

// 1. Clean build outputs every page plus 404, sitemap, rss, llms.txt, robots.
check('1. build outputs every page, 404, sitemap, rss, llms.txt, robots.txt', (fail) => {
  for (const p of [...PAGES, '/404.html']) if (!existsSync(fileFor(p))) fail('missing page ' + p);
  for (const f of ['sitemap.xml', 'rss.xml', 'llms.txt', 'robots.txt', '_headers']) if (!existsSync(join(DIST, f))) fail('missing ' + f);
  const extra = Object.keys(pages).filter((p) => ![...PAGES, '/404.html'].includes(p));
  if (extra.length) fail('unexpected pages (add them to PAGES if intended): ' + extra.join(' '));
  const llms = read(join(DIST, 'llms.txt'));
  for (const h of ['## Product', '## Pricing', '## Guides', '## Support']) if (!llms.includes(h)) fail('llms.txt lacks ' + h);
  const robots = read(join(DIST, 'robots.txt'));
  if (!/User-agent: \*\nAllow: \//.test(robots) || /Disallow:\s*\/\s*$/m.test(robots)) fail('robots.txt must allow all crawlers');
  if (!robots.includes(`Sitemap: ${SITE}/sitemap.xml`)) fail('robots.txt lacks Sitemap line');
  for (const bot of ['GPTBot', 'ClaudeBot', 'PerplexityBot', 'Google-Extended']) if (!robots.includes('User-agent: ' + bot)) fail('robots.txt does not name ' + bot);
});

// 2. Per page: unique title (<=60) and meta (<=155), one H1, absolute self-canonical, OG tags.
check('2. titles, descriptions, H1, canonical, Open Graph', (fail) => {
  const titles = {};
  const descs = {};
  for (const [path, html] of Object.entries(pages)) {
    const title = decode((html.match(/<title>([^<]*)<\/title>/) || [])[1] || '');
    const desc = meta(html, 'name', 'description') || '';
    if (!title) fail(`${path}: no <title>`);
    if (title.length > 60) fail(`${path}: title ${title.length} chars > 60: ${title}`);
    if (!desc) fail(`${path}: no meta description`);
    if (desc.length > 155) fail(`${path}: description ${desc.length} chars > 155`);
    (titles[title] ||= []).push(path);
    (descs[desc] ||= []).push(path);
    const h1 = (html.match(/<h1[\s>]/g) || []).length;
    if (h1 !== 1) fail(`${path}: ${h1} <h1> elements`);
    if (!/<html lang="en">/.test(html)) fail(`${path}: html lang="en" missing`);
    const canon = (html.match(/<link rel="canonical" href="([^"]+)">/) || [])[1];
    const want = SITE + path;
    if (path === '/404.html') {
      // The 404 body is served at any missing URL: no canonical or og:url.
      if (canon || meta(html, 'property', 'og:url')) fail('/404.html: should have no canonical or og:url');
    } else {
      if (canon !== want) fail(`${path}: canonical ${canon} != ${want}`);
      if (meta(html, 'property', 'og:url') !== want) fail(`${path}: og:url is not the canonical URL`);
      if (!meta(html, 'property', 'og:url')) fail(`${path}: missing og:url`);
    }
    for (const p of ['og:title', 'og:description', 'og:image', 'og:type']) if (!meta(html, 'property', p)) fail(`${path}: missing ${p}`);
    const og = meta(html, 'property', 'og:image') || '';
    if (!og.startsWith(SITE + '/')) fail(`${path}: og:image not absolute on this site`);
    else if (!existsSync(join(DIST, og.slice(SITE.length)))) fail(`${path}: og:image file missing`);
    if (meta(html, 'name', 'twitter:card') !== 'summary_large_image') fail(`${path}: twitter:card missing`);
  }
  for (const [t, ps] of Object.entries(titles)) if (ps.length > 1) fail(`duplicate title "${t}": ${ps.join(' ')}`);
  for (const [d, ps] of Object.entries(descs)) if (ps.length > 1) fail(`duplicate description: ${ps.join(' ')}`);
  const og = join(DIST, 'assets/screens/og-card.png');
  const b = readFileSync(og);
  if (b.readUInt32BE(16) !== 1200 || b.readUInt32BE(20) !== 630) fail('og-card.png is not 1200x630');
});

// 3. JSON-LD parses and includes the §6 types.
const ld = {};
check('3. JSON-LD parses and has the required types', (fail) => {
  const types = (node, out = []) => {
    if (Array.isArray(node)) node.forEach((n) => types(n, out));
    else if (node && typeof node === 'object') {
      if (node['@type']) out.push(node['@type']);
      Object.values(node).forEach((v) => types(v, out));
    }
    return out;
  };
  for (const [path, html] of Object.entries(pages)) {
    const blocks = [...html.matchAll(/<script type="application\/ld\+json">([\s\S]*?)<\/script>/g)].map((m) => m[1]);
    if (!blocks.length) fail(`${path}: no JSON-LD`);
    const graph = [];
    for (const b of blocks) {
      try {
        const j = JSON.parse(b);
        graph.push(...(j['@graph'] || [j]));
      } catch (e) {
        fail(`${path}: JSON-LD does not parse: ${e.message}`);
      }
    }
    ld[path] = graph;
    const top = graph.map((n) => n['@type']);
    const all = types(graph);
    const need = ['Organization'];
    if (path !== '/') need.push('BreadcrumbList');
    if (path === '/') need.push('WebSite', 'SoftwareApplication', 'FAQPage');
    if (path === '/features/') need.push('SoftwareApplication');
    if (path === '/pricing/') need.push('SoftwareApplication', 'FAQPage');
    if (path === '/blog/') need.push('Blog');
    if (/^\/blog\/.+\//.test(path)) need.push('BlogPosting', 'FAQPage');
    for (const t of need) if (!top.includes(t)) fail(`${path}: JSON-LD lacks ${t}`);
    const org = graph.find((n) => n['@type'] === 'Organization');
    if (org && !(org.contactPoint && org.contactPoint['@type'] === 'ContactPoint')) fail(`${path}: Organization lacks ContactPoint`);
    if (all.includes('AggregateRating') || all.includes('Review') || /"(aggregateRating|ratingValue|reviewCount)"/.test(JSON.stringify(graph)))
      fail(`${path}: ratings/reviews in JSON-LD (we have none to cite)`);
    const app = graph.find((n) => n['@type'] === 'SoftwareApplication');
    if (app) {
      if (app.applicationCategory !== 'BusinessApplication') fail(`${path}: applicationCategory`);
      if (app.operatingSystem !== 'WordPress 6.8+, WooCommerce') fail(`${path}: operatingSystem`);
      const offers = [].concat(app.offers || []);
      if (!offers.some((o) => o.price === '0')) fail(`${path}: no free offer (price 0)`);
      if (path === '/pricing/' && !(offers.length === 2 && offers.some((o) => o.price === cfg.pro.price))) fail('/pricing/: needs two Offers incl. Pro');
      if (path === '/features/' && !(Array.isArray(app.featureList) && app.featureList.length >= 5)) fail('/features/: featureList missing');
    }
    // FAQPage must mirror visible questions.
    const faq = graph.find((n) => n['@type'] === 'FAQPage');
    if (faq) {
      const h3 = [...html.matchAll(/<h3[^>]*>([\s\S]*?)<\/h3>/g)].map((m) => text(m[1]));
      if (!faq.mainEntity?.length) fail(`${path}: empty FAQPage`);
      for (const q of faq.mainEntity || []) {
        if (!h3.includes(q.name)) fail(`${path}: FAQ question not visible: ${q.name}`);
        if (!q.acceptedAnswer?.text || q.acceptedAnswer.text.length < 20) fail(`${path}: FAQ answer too short: ${q.name}`);
      }
    }
  }
});

// 4. No broken internal hrefs/srcs (including #fragments).
check('4. internal links and assets resolve', (fail) => {
  const resolve = (url) => {
    let u = url.startsWith(SITE) ? url.slice(SITE.length) || '/' : url;
    if (!u.startsWith('/')) return null;
    u = u.split('?')[0];
    const [p, frag] = u.split('#');
    let file = join(DIST, decodeURIComponent(p));
    if (p.endsWith('/')) file = join(file, 'index.html');
    else if (!existsSync(file) && existsSync(join(file, 'index.html'))) return { file: null, redirect: true };
    return { file, frag };
  };
  for (const [path, html] of Object.entries(pages)) {
    const refs = [...html.matchAll(/\s(?:href|src)="([^"]+)"/g)].map((m) => decode(m[1]));
    for (const ref of refs) {
      if (/^(mailto:|https?:\/\/(?!bulktab\.waukeganlabs\.com))/.test(ref)) continue;
      if (ref.startsWith('#')) {
        if (ref.length > 1 && !html.includes(`id="${ref.slice(1)}"`)) fail(`${path}: missing anchor ${ref}`);
        continue;
      }
      const r = resolve(ref);
      if (!r) {
        fail(`${path}: relative or unknown link ${ref}`);
        continue;
      }
      if (r.redirect) {
        fail(`${path}: ${ref} needs a trailing slash`);
        continue;
      }
      if (!existsSync(r.file)) fail(`${path}: broken link ${ref}`);
      else if (r.frag && r.file.endsWith('.html') && !read(r.file).includes(`id="${r.frag}"`)) fail(`${path}: missing anchor ${ref}`);
    }
  }
  for (const css of walk(DIST).filter((f) => f.endsWith('.css'))) {
    for (const m of read(css).matchAll(/url\("?([^")]+)"?\)/g)) if (m[1].startsWith('/') && !existsSync(join(DIST, m[1]))) fail(`${relative(DIST, css)}: missing ${m[1]}`);
  }
  for (const f of ['sitemap.xml', 'rss.xml', 'llms.txt']) {
    for (const m of read(join(DIST, f)).matchAll(new RegExp(SITE.replace(/\./g, '\\.') + '(/[^\\s<)"]*)', 'g'))) {
      const r = resolve(m[1]);
      if (r && !r.redirect && !existsSync(r.file)) fail(`${f}: broken ${m[0]}`);
    }
  }
});

// 5. Banned words in src + dist (case-insensitive).
check('5. banned words (src + dist)', (fail) => {
  const banned = ['YIKES', 'Custom Product Tabs', 'lorem', 'TODO', 'TBD', 'placeholder', 'example.com'];
  const textExt = new Set(['.html', '.md', '.css', '.js', '.json', '.txt', '.xml', '.php', '.svg', '']);
  for (const f of [...walk(SRC), ...walk(DIST)]) {
    if (!textExt.has(extname(f))) continue;
    const s = read(f).toLowerCase();
    for (const w of banned) if (s.includes(w.toLowerCase())) fail(`${relative(ROOT, f)} contains "${w}"`);
  }
});

// 6. Every <img> has alt, width, height; image files < 300 KB.
check('6. images: alt, width, height, < 300 KB', (fail) => {
  for (const [path, html] of Object.entries(pages)) {
    for (const tag of html.match(/<img\s[^>]*>/g) || []) {
      for (const a of ['alt', 'width', 'height']) if (attr(tag, a) === null) fail(`${path}: <img> without ${a}: ${tag.slice(0, 80)}`);
      const src = attr(tag, 'src') || '';
      if (/screens\//.test(src) && !(attr(tag, 'alt') || '').trim()) fail(`${path}: screenshot without alt text ${src}`);
    }
  }
  for (const f of walk(DIST).filter((x) => /\.(png|webp|jpe?g|gif|svg|avif)$/.test(x))) {
    const kb = statSync(f).size / 1024;
    if (kb >= 300) fail(`${relative(DIST, f)} is ${kb.toFixed(0)} KB`);
  }
});

// 7. html-validate:recommended.
const validator = new HtmlValidate({
  extends: ['html-validate:recommended'],
  rules: {
    // The Freemius checkout script is versionless and updated by Freemius, so a
    // fixed SRI hash would break checkout. It is the only third-party script.
    'require-sri': ['error', { target: 'crossorigin', exclude: ['https://checkout.freemius.com/'] }],
  },
});
const validation = [];
for (const f of htmlFiles) validation.push(validator.validateFile(f).then((r) => [f, r]));
const reports = await Promise.all(validation);
check('7. html-validate:recommended', (fail) => {
  for (const [f, report] of reports) {
    for (const res of report.results) for (const m of res.messages) fail(`${relative(DIST, f)}:${m.line}:${m.column} ${m.ruleId}: ${m.message}`);
  }
});

// 8. Per page HTML + CSS + JS + fonts < 500 KB.
check('8. page weight (HTML + CSS + JS + fonts) < 500 KB', (fail) => {
  const size = (u) => {
    const f = join(DIST, u.split('?')[0]);
    return existsSync(f) ? statSync(f).size : 0;
  };
  for (const [path, html] of Object.entries(pages)) {
    let total = Buffer.byteLength(html);
    const assets = new Set();
    for (const m of html.matchAll(/<link rel="(?:stylesheet|preload)" href="(\/[^"]+)"/g)) assets.add(m[1].split('?')[0]);
    for (const m of html.matchAll(/<script src="(\/[^"]+)"/g)) assets.add(m[1].split('?')[0]);
    for (const a of [...assets].filter((x) => x.endsWith('.css'))) for (const m of read(join(DIST, a)).matchAll(/url\("?(\/[^")]+)"?\)/g)) assets.add(m[1]);
    for (const a of assets) total += size(a);
    if (total >= 500 * 1024) fail(`${path}: ${(total / 1024).toFixed(0)} KB`);
  }
});

// 9. Declared text/background pairs meet WCAG AA.
check('9. colour contrast of declared pairs', (fail) => {
  const pairs = JSON.parse(read(join(SRC, 'contrast.json')));
  const css = read(join(SRC, 'assets/css/site.css')).toLowerCase();
  const lum = (hex) => {
    const c = hex.replace('#', '').match(/../g).map((x) => parseInt(x, 16) / 255).map((v) => (v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4));
    return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
  };
  for (const p of pairs) {
    const [a, b] = [lum(p.fg), lum(p.bg)];
    const ratio = (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
    const min = p.large ? 3 : 4.5;
    for (const c of [p.fg, p.bg]) if (!css.includes(c.toLowerCase())) fail(`${c} (${p.use}) is not used in site.css`);
    if (ratio < min) fail(`${p.use}: ${p.fg} on ${p.bg} = ${ratio.toFixed(2)}:1 < ${min}:1`);
  }
  if (!css.includes('#9a5b00') || !/--amber-large:\s*#b86e00/.test(css)) fail('amber text tokens missing');
});

// 10. Sitemap lists every page except 404; RSS lists all posts.
check('10. sitemap and RSS completeness', (fail) => {
  const sm = read(join(DIST, 'sitemap.xml'));
  const locs = [...sm.matchAll(/<loc>([^<]+)<\/loc>/g)].map((m) => m[1]);
  const want = Object.keys(pages).filter((p) => p !== '/404.html').map((p) => SITE + p);
  for (const w of want) if (!locs.includes(w)) fail('sitemap lacks ' + w);
  for (const l of locs) if (!want.includes(l)) fail('sitemap has unknown ' + l);
  if ((sm.match(/<lastmod>\d{4}-\d{2}-\d{2}<\/lastmod>/g) || []).length !== locs.length) fail('sitemap: every URL needs a lastmod');
  if (sm.includes('404')) fail('sitemap lists the 404 page');
  const rss = read(join(DIST, 'rss.xml'));
  for (const s of POSTS) if (!rss.includes(`<link>${SITE}/blog/${s}/</link>`)) fail('rss lacks ' + s);
  for (const [path, html] of Object.entries(pages)) if (path !== '/404.html' && /<meta name="robots" content="noindex">/.test(html)) fail(path + ' is noindex');
  if (!/<meta name="robots" content="noindex">/.test(pages['/404.html'])) fail('404 should be noindex');
});

// 11. Freemius checkout on /pricing/.
check('11. Freemius checkout on /pricing/', (fail) => {
  const html = pages['/pricing/'];
  const fs = cfg.freemius;
  if (fs.productId !== 46190 || fs.planId !== 81330 || fs.publicKey !== 'pk_b98508e9c95a26311a9fa53379d9e') fail('config.json Freemius ids differ from the brief');
  if (!html.includes("s.src = 'https://checkout.freemius.com/js/v1/';")) fail('Freemius checkout script loader missing');
  if (/<script[^>]+src="https:\/\/checkout\.freemius\.com/.test(html)) fail('Freemius script must load on click, not with the page');
  if (!/addEventListener\('click'[\s\S]*loadCheckout\(\)[\s\S]*new FS\.Checkout\(/.test(html)) fail('FS.Checkout must be created after the click-triggered load');
  if (!/window\.location\.href = link\.href/.test(html)) fail('no fallback to the hosted checkout when the script fails');
  if (!html.includes('new FS.Checkout(')) fail('FS.Checkout not created');
  if (!html.includes("product_id: '46190'")) fail('product_id 46190 missing');
  if (!html.includes("plan_id: '81330'")) fail('plan_id 81330 missing');
  if (!html.includes("public_key: 'pk_b98508e9c95a26311a9fa53379d9e'")) fail('public key missing');
  const buys = html.match(/<a\s[^>]*data-fs-checkout[^>]*>/g) || [];
  if (!buys.length) fail('no buy link with data-fs-checkout');
  for (const b of buys) if (attr(b, 'href') !== 'https://checkout.freemius.com/product/46190/plan/81330/') fail('buy link lacks hosted-checkout fallback href: ' + b);
  for (const [path, h] of Object.entries(pages)) {
    if (path !== '/pricing/' && /checkout\.freemius\.com\/js|FS\.Checkout|data-fs-checkout/.test(h)) fail(`${path}: Freemius checkout outside /pricing/`);
    // No third-party requests on page load anywhere.
    const loads = [...h.matchAll(/<(script|img|iframe|source|video|audio)\s[^>]*\ssrc="([^"]+)"/g), ...h.matchAll(/<link\s[^>]*\shref="([^"]+)"[^>]*>/g)]
      .filter((m) => !/rel="(canonical|alternate)"/.test(m[0]))
      .map((m) => m[2] || m[1]);
    for (const u of loads) if (/^(https?:)?\/\//.test(u) && !u.startsWith(SITE)) fail(`${path}: loads third-party resource on page load: ${u}`);
  }
  const p = read(join(DIST, 'privacy/index.html'));
  if (!/Nothing loads until you click Buy/.test(p) || !/merchant of record/.test(p)) fail('privacy page must say nothing loads until Buy and name Freemius as merchant of record');
  if (!/[Rr]enews automatically/.test(html) || !/cancel any time/i.test(html)) fail('/pricing/ must disclose automatic yearly renewal and how to cancel');
  const free = [...Object.values(pages).join('').matchAll(/<a\s[^>]*>Get BulkTab free<\/a>/g)].map((m) => attr(m[0], 'href'));
  if (!free.length || free.some((h) => h !== cfg.FREE_URL)) fail('every "Get BulkTab free" link must use FREE_URL');
});

// 12. Visible numbers match the allowlist from SITE-BRIEF.md (dates and code excepted).
check('12. visible numbers are on the allowlist', (fail) => {
  // From SITE-BRIEF.md: 530 reviews, 750 threads, 26/12/21/14/6/18 %, ~70,000 stores, April 2025,
  // 52 e2e checks, 458 unit tests, $49.99/year, 1 site, 30-day refund, 20 tabs, WordPress 6.8+, PHP 7.4+,
  // $0 (free). 2026 = copyright year.
  const allow = new Set(['530', '750', '26', '12', '21', '14', '6', '18', '70000', '2025', '52', '458', '49.99', '1', '30', '20', '6.8', '7.4', '0', '2026']);
  for (const [path, html] of Object.entries(pages)) {
    const body = html
      .replace(/<head>[\s\S]*?<\/head>/, '')
      .replace(/<script[\s\S]*?<\/script>/g, '')
      .replace(/<pre[\s\S]*?<\/pre>/g, '')
      .replace(/<code>[\s\S]*?<\/code>/g, '')
      .replace(/<time[^>]*>[\s\S]*?<\/time>/g, '');
    const t = text(body);
    for (const m of t.matchAll(/\d[\d,]*(?:\.\d+)?/g)) {
      const n = m[0].replace(/,/g, '').replace(/\.$/, '');
      if (!allow.has(n)) fail(`${path}: number "${m[0]}" not on the allowlist: …${t.slice(Math.max(0, m.index - 40), m.index + 20)}…`);
    }
  }
});

// 13. Posts: 700–1,200 words; first paragraph <= 60 words; visible dates; query in H1 or first paragraph.
check('13. posts: length, answer-first, dates, query, links, FAQ', (fail) => {
  const stop = new Set(['how', 'to', 'in', 'a', 'the', 'and', 'or', 'of', 'for', 'add']);
  for (const s of POSTS) {
    const path = `/blog/${s}/`;
    const html = pages[path];
    const art = (html.match(/<article[\s\S]*?<\/article>/) || [''])[0];
    const body = art.split('<h2 id="related-guides"')[0].replace(/<nav class="breadcrumb"[\s\S]*?<\/nav>/, '').replace(/<p class="byline">[\s\S]*?<\/p>/, '').replace(/<pre[\s\S]*?<\/pre>/g, '');
    const n = words(text(body));
    if (n < 700 || n > 1200) fail(`${path}: ${n} words (want 700–1,200)`);
    const first = (art.match(/<p class="answer">([\s\S]*?)<\/p>/) || [])[1];
    if (!first) fail(`${path}: no answer-first paragraph`);
    else if (words(text(first)) > 60) fail(`${path}: first paragraph ${words(text(first))} words > 60`);
    if ((art.match(/<time datetime="\d{4}-\d{2}-\d{2}">/g) || []).length < 2) fail(`${path}: published and updated dates not visible`);
    const post = (ld[path] || []).find((x) => x['@type'] === 'BlogPosting');
    const query = post?.about || '';
    if (!query) fail(`${path}: no query`);
    const h1 = text((html.match(/<h1>([\s\S]*?)<\/h1>/) || [])[1] || '');
    const hay = (h1 + ' ' + text(first || '')).toLowerCase();
    for (const w of query.toLowerCase().split(/\s+/)) {
      if (stop.has(w)) continue;
      const stem = w.replace(/s$/, '');
      if (!new RegExp(`\\b${stem}`).test(hay)) fail(`${path}: query word "${w}" not in H1 or first paragraph`);
    }
    if (!post?.author?.name || post.author.name !== cfg.author.name) fail(`${path}: author missing`);
    for (const href of ['/features/', '/pricing/']) if (!art.includes(`href="${href}`)) fail(`${path}: no link to ${href}`);
    const others = POSTS.filter((o) => o !== s && art.includes(`href="/blog/${o}/"`));
    if (others.length < 2) fail(`${path}: links to ${others.length} other posts (want 2)`);
    if (!/<ol>[\s\S]*?<\/ol>|<ol start/.test(art)) fail(`${path}: no numbered steps`);
    if (!/<pre><code class="language-php">[\s\S]*woocommerce_product_tabs/.test(art)) fail(`${path}: no woocommerce_product_tabs snippet`);
    if (!/href="https:\/\/(woocommerce\.com|developer\.woocommerce\.com)\//.test(art)) fail(`${path}: no link to WooCommerce docs`);
    if (!/<h2 id="common-problems">/.test(art)) fail(`${path}: no Common problems section`);
    const faq = (ld[path] || []).find((x) => x['@type'] === 'FAQPage');
    const q = faq?.mainEntity?.length || 0;
    if (q < 3 || q > 4) fail(`${path}: ${q} FAQs (want 3–4)`);
    if (!html.includes('class="cta-band"')) fail(`${path}: no CTA band`);
  }
  // Home links to Features, Pricing and every post; Features groups link to their posts.
  const home = pages['/'];
  for (const h of ['/features/', '/pricing/', ...POSTS.map((s) => `/blog/${s}/`)]) if (!home.includes(`href="${h}"`)) fail('home does not link to ' + h);
  const feat = pages['/features/'];
  for (const [id, post] of [['shared-tabs', POSTS[0]], ['control', POSTS[1]], ['product-tabs', POSTS[2]], ['storage', POSTS[3]]]) {
    const sec = (feat.match(new RegExp(`<section[^>]*id="${id}"[\\s\\S]*?</section>`)) || [''])[0];
    if (!sec.includes(`href="/blog/${post}/"`)) fail(`features #${id} does not link to ${post}`);
  }
});

// 14. .distignore contains /site; no tokens in any file; wrangler name is bulktab-site.
check('14. deploy config: .distignore, wrangler, no secrets', (fail) => {
  const di = read(join(REPO, '.distignore'));
  if (!/^\/site\/?$/m.test(di)) fail('.distignore lacks /site');
  const w = read(join(ROOT, 'wrangler.toml'));
  if (!/^name = "bulktab-site"$/m.test(w)) fail('wrangler name is not bulktab-site');
  if (!/directory = "\.\/dist"/.test(w) || !/not_found_handling = "404-page"/.test(w)) fail('wrangler [assets] incomplete');
  if (!/pattern = "bulktab\.waukeganlabs\.com"/.test(w) || !/custom_domain = true/.test(w)) fail('wrangler custom domain route missing');
  const wf = join(REPO, '.github/workflows/site.yml');
  if (!existsSync(wf)) fail('missing .github/workflows/site.yml');
  else {
    const y = read(wf);
    if (!y.includes('secrets.CLOUDFLARE_API_TOKEN') || !y.includes('secrets.CLOUDFLARE_ACCOUNT_ID')) fail('workflow does not read the Cloudflare secrets');
  }
  const secret = [
    /\b(sk|rk)_(live|test)_[A-Za-z0-9]{10,}/,
    /\bsk_[A-Za-z0-9]{20,}/,
    /\bghp_[A-Za-z0-9]{20,}/,
    /\bgithub_pat_[A-Za-z0-9_]{20,}/,
    /(api[_-]?token|secret[_-]?key|account[_-]?id|password)\s*[:=]\s*["'][A-Za-z0-9_\-]{16,}["']/i,
    /-----BEGIN [A-Z ]*PRIVATE KEY-----/,
  ];
  const files = [...walk(ROOT).filter((f) => !f.includes('/node_modules/') && !f.includes('/.wrangler/') && /\.(html|md|css|js|mjs|json|txt|xml|toml|yml|php|sh)$|_headers$/.test(f)), wf].filter(existsSync);
  for (const f of files) {
    if (f.endsWith('package-lock.json')) continue;
    const s = read(f);
    for (const r of secret) if (r.test(s)) fail(`${relative(REPO, f)} looks like it contains a secret (${r})`);
  }
});

// ------------------------------------------------------------------ report
let failed = 0;
for (const r of results) {
  const ok = r.errors.length === 0;
  if (!ok) failed++;
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${r.name}`);
  for (const e of r.errors.slice(0, 40)) console.log('      - ' + e);
  if (r.errors.length > 40) console.log(`      … and ${r.errors.length - 40} more`);
}
console.log(failed ? `\n${failed} of ${results.length} checks failed.` : `\nAll ${results.length} checks passed.`);
process.exit(failed ? 1 : 0);
