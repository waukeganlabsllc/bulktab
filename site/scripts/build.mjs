// Builds the static BulkTab site: src/ -> dist/.
//   npm run build
// Pages: src/pages/*.html (JSON meta in a leading <!--page ... --> comment).
// Posts: src/posts/*.md (simple "key: value" front matter).
// Also writes sitemap.xml, rss.xml, llms.txt, robots.txt and copies assets + src/static/.
import { readFileSync, writeFileSync, mkdirSync, rmSync, readdirSync, statSync, copyFileSync, existsSync } from 'node:fs';
import { join, dirname, extname, basename } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createHash } from 'node:crypto';
import { Marked } from 'marked';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const SRC = join(ROOT, 'src');
const DIST = join(ROOT, 'dist');
const cfg = JSON.parse(readFileSync(join(SRC, 'config.json'), 'utf8'));
const screens = JSON.parse(readFileSync(join(SRC, 'screens.json'), 'utf8'));
const SITE = cfg.siteUrl.replace(/\/$/, '');
const OG_IMAGE = `${SITE}/assets/screens/og-card.png`;
const ORG_ID = `${SITE}/#organization`;

// ------------------------------------------------------------------ helpers
const read = (p) => readFileSync(p, 'utf8');
const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const xml = esc;
const hash = (s) => createHash('sha256').update(s).digest('hex').slice(0, 10);
const slugify = (s) =>
  s.toLowerCase().replace(/<[^>]+>/g, '').replace(/&[a-z#0-9]+;/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
const stripTags = (s) =>
  s.replace(/<[^>]+>/g, ' ').replace(/&nbsp;/g, ' ').replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/\s+/g, ' ').trim();
const fmtDate = (d) => new Date(d + 'T12:00:00Z').toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric', timeZone: 'UTC' });

function copyDir(from, to) {
  mkdirSync(to, { recursive: true });
  for (const name of readdirSync(from)) {
    const a = join(from, name);
    const b = join(to, name);
    if (statSync(a).isDirectory()) copyDir(a, b);
    else copyFileSync(a, b);
  }
}

// Image size from the file header (WebP VP8/VP8L/VP8X, PNG).
function imageSize(file) {
  const b = readFileSync(file);
  if (b.toString('ascii', 1, 4) === 'PNG') return { width: b.readUInt32BE(16), height: b.readUInt32BE(20) };
  if (b.toString('ascii', 0, 4) === 'RIFF' && b.toString('ascii', 8, 12) === 'WEBP') {
    const kind = b.toString('ascii', 12, 16);
    if (kind === 'VP8X') return { width: 1 + b.readUIntLE(24, 3), height: 1 + b.readUIntLE(27, 3) };
    if (kind === 'VP8 ') return { width: b.readUInt16LE(26) & 0x3fff, height: b.readUInt16LE(28) & 0x3fff };
    if (kind === 'VP8L') {
      const bits = b.readUInt32LE(21);
      return { width: (bits & 0x3fff) + 1, height: ((bits >> 14) & 0x3fff) + 1 };
    }
  }
  if (extname(file) === '.svg') {
    const m = read(file).match(/width="(\d+)"[^>]*height="(\d+)"/);
    if (m) return { width: +m[1], height: +m[2] };
  }
  throw new Error('Unknown image format: ' + file);
}

// ------------------------------------------------------------------ templates
const TPL = join(SRC, 'templates');
const partials = Object.fromEntries(readdirSync(TPL).filter((f) => f.endsWith('.html')).map((f) => [basename(f, '.html'), read(join(TPL, f))]));

function flat(obj, prefix = '', out = {}) {
  for (const [k, v] of Object.entries(obj)) {
    if (v && typeof v === 'object' && !Array.isArray(v)) flat(v, prefix + k + '.', out);
    else out[prefix + k] = v;
  }
  return out;
}
const cfgVars = flat(cfg);

function figure(id, eager = false) {
  const s = screens[id];
  if (!s) throw new Error('Unknown screen ' + id);
  const { width, height } = imageSize(join(SRC, 'assets', 'screens', s.file));
  const load = eager ? 'fetchpriority="high"' : 'loading="lazy"';
  const src = `/assets/screens/${s.file}`;
  return `<figure class="shot"><a href="${src}" title="Open the full-size screenshot"><img src="${src}" alt="${esc(s.alt)}" width="${width}" height="${height}" ${load} decoding="async"></a><figcaption>${esc(s.caption)} <a class="zoom" href="${src}">View full size</a></figcaption></figure>`;
}

function snippet(name) {
  const code = read(join(SRC, 'snippets', name + '.php')).replace(/^<\?php\s*\n/, '').replace(/^\/\/ phpcs:.*\n/m, '');
  return `<pre><code class="language-php">${esc(code.trimEnd())}</code></pre>`;
}

let ctaCount = 0;
function render(str, vars) {
  let out = str;
  for (let i = 0; i < 5 && /\{\{/.test(out); i++) {
    out = out
      .replace(/\{\{> ?([a-z-]+)\}\}/g, (_, n) => {
        if (n.startsWith('cta')) return partials[n].replace(/\{\{ctaId\}\}/g, String(++ctaCount));
        if (!partials[n]) throw new Error('Unknown partial ' + n);
        return partials[n];
      })
      .replace(/\{\{figure:([a-z0-9]+)( eager)?\}\}/g, (_, id, eager) => figure(id, !!eager))
      .replace(/\{\{snippet:([a-z0-9-]+)\}\}/g, (_, n) => snippet(n))
      .replace(/\{\{current:([a-z]+)\}\}/g, (_, s) => (vars.section === s ? ' aria-current="page"' : ''))
      .replace(/\{\{([A-Za-z0-9_.]+)\}\}/g, (m, k) => {
        if (k in vars) return String(vars[k]);
        if (k in cfgVars) return String(cfgVars[k]);
        throw new Error('Unknown template variable ' + m);
      });
  }
  return out;
}

// ------------------------------------------------------------------ JSON-LD
const org = () => ({
  '@type': 'Organization',
  '@id': ORG_ID,
  name: cfg.company.name,
  url: SITE + '/',
  logo: `${SITE}/assets/img/bulktab-icon-300.png`,
  email: cfg.supportEmail,
  address: { '@type': 'PostalAddress', addressLocality: cfg.company.city, addressRegion: cfg.company.regionCode, addressCountry: cfg.company.country },
  contactPoint: { '@type': 'ContactPoint', contactType: 'customer support', email: cfg.supportEmail, availableLanguage: 'en', url: `${SITE}/support/` },
  sameAs: [cfg.githubUrl],
});
const breadcrumbs = (items) => ({
  '@type': 'BreadcrumbList',
  itemListElement: items.map(([name, path], i) => ({ '@type': 'ListItem', position: i + 1, name, item: SITE + path })),
});
const authorLd = () =>
  cfg.author.type === 'Person'
    ? { '@type': 'Person', name: cfg.author.name, url: cfg.author.url }
    : { '@type': 'Organization', name: cfg.author.name, url: cfg.author.url, parentOrganization: { '@id': ORG_ID } };
const freeOffer = () => ({ '@type': 'Offer', name: 'BulkTab (free)', price: '0', priceCurrency: 'USD', url: cfg.FREE_URL, availability: 'https://schema.org/InStock' });
const proOffer = () => ({
  '@type': 'Offer',
  name: 'BulkTab Pro, 1 site, yearly',
  price: cfg.pro.price,
  priceCurrency: cfg.pro.currency,
  url: `${SITE}/pricing/`,
  availability: 'https://schema.org/InStock',
  priceSpecification: { '@type': 'UnitPriceSpecification', price: cfg.pro.price, priceCurrency: cfg.pro.currency, billingDuration: 'P1Y' },
  seller: { '@id': ORG_ID },
});
const app = (extra = {}) => ({
  '@type': 'SoftwareApplication',
  '@id': `${SITE}/#software`,
  name: cfg.productName,
  alternateName: 'BulkTab',
  applicationCategory: 'BusinessApplication',
  applicationSubCategory: 'WooCommerce plugin',
  operatingSystem: 'WordPress 6.8+, WooCommerce',
  softwareRequirements: 'WordPress 6.8 or later, PHP 7.4 or later, WooCommerce (classic product editor)',
  url: `${SITE}/`,
  downloadUrl: cfg.FREE_URL,
  license: 'https://www.gnu.org/licenses/gpl-2.0.html',
  isAccessibleForFree: true,
  description: 'Write a WooCommerce product tab once and show it on every matching product, by category, tag or brand. Safe storage, CSV editing, undo and one-click import.',
  image: OG_IMAGE,
  publisher: { '@id': ORG_ID },
  offers: freeOffer(),
  ...extra,
});

function faqFrom(html) {
  const sec = html.match(/<section[^>]*class="[^"]*\bfaq\b[^"]*"[^>]*>([\s\S]*?)<\/section>/);
  const body = sec ? sec[1] : (html.match(/<h2[^>]*id="frequently-asked-questions"[^>]*>[\s\S]*?<\/h2>([\s\S]*?)(?=<h2|$)/) || [])[1];
  if (!body) throw new Error('FAQPage requested but no FAQ section found');
  const parts = body.split(/<h3[^>]*>/).slice(1);
  return {
    '@type': 'FAQPage',
    mainEntity: parts.map((p) => {
      const [q, a] = p.split('</h3>');
      return { '@type': 'Question', name: stripTags(q), acceptedAnswer: { '@type': 'Answer', text: stripTags(a) } };
    }),
  };
}

const ldScript = (graph) => `<script type="application/ld+json">${JSON.stringify({ '@context': 'https://schema.org', '@graph': graph }).replace(/</g, '\\u003c')}</script>`;

// ------------------------------------------------------------------ posts
function frontMatter(src) {
  const m = src.match(/^---\n([\s\S]*?)\n---\n/);
  if (!m) throw new Error('Missing front matter');
  const data = {};
  for (const line of m[1].split('\n')) {
    const mm = line.match(/^([A-Za-z]+):\s*(.*)$/);
    if (mm) data[mm[1]] = mm[2].trim();
  }
  return [data, src.slice(m[0].length)];
}

const marked = new Marked({ gfm: true });
marked.use({
  renderer: {
    heading({ tokens, depth }) {
      const text = this.parser.parseInline(tokens);
      return `<h${depth} id="${slugify(text)}">${text}</h${depth}>\n`;
    },
    table(token) {
      // Wrap tables for horizontal scroll on small screens.
      const head = token.header.map((c) => `<th scope="col">${this.parser.parseInline(c.tokens)}</th>`).join('');
      const rows = token.rows.map((r) => '<tr>' + r.map((c) => `<td>${this.parser.parseInline(c.tokens)}</td>`).join('') + '</tr>').join('\n');
      return `<div class="table-wrap"><table>\n<thead><tr>${head}</tr></thead>\n<tbody>\n${rows}\n</tbody>\n</table></div>\n`;
    },
  },
});

const posts = readdirSync(join(SRC, 'posts'))
  .filter((f) => f.endsWith('.md'))
  .map((f) => {
    const [data, body] = frontMatter(read(join(SRC, 'posts', f)));
    for (const k of ['title', 'description', 'slug', 'date', 'updated', 'query', 'related', 'image']) if (!data[k]) throw new Error(`${f}: missing ${k}`);
    return { ...data, file: f, body, path: `/blog/${data.slug}/` };
  })
  .map((p) => ({ ...p, order: Number(p.order || 0) }))
  .sort((a, b) => b.date.localeCompare(a.date) || a.order - b.order);
const postBySlug = Object.fromEntries(posts.map((p) => [p.slug, p]));

// ------------------------------------------------------------------ build
rmSync(DIST, { recursive: true, force: true });
mkdirSync(DIST, { recursive: true });
copyDir(join(SRC, 'assets'), join(DIST, 'assets'));
if (existsSync(join(SRC, 'static'))) copyDir(join(SRC, 'static'), DIST);

const cssVersion = hash(read(join(SRC, 'assets', 'css', 'site.css')));
const jsVersion = hash(read(join(SRC, 'assets', 'js', 'site.js')));
const year = '2026';
const pagesOut = []; // { path, updated, title, description, section }

function writePage(path, html) {
  const file = path === '/404.html' ? join(DIST, '404.html') : join(DIST, path, 'index.html');
  mkdirSync(dirname(file), { recursive: true });
  writeFileSync(file, html);
}

function layout({ meta, content, graph, bodyEnd = '', ogType = 'website', articleMeta = '' }) {
  const canonical = SITE + meta.path;
  const vars = {
    metaTitle: esc(meta.title),
    ogTitle: esc(meta.ogTitle || meta.title),
    metaDescription: esc(meta.description),
    canonicalTag: meta.noindex ? '' : `<link rel="canonical" href="${canonical}">`,
    ogUrlTag: meta.noindex ? '' : `<meta property="og:url" content="${canonical}">`,
    ogImage: OG_IMAGE,
    ogType,
    articleMeta,
    robotsMeta: meta.noindex ? '<meta name="robots" content="noindex">' : '',
    jsonld: ldScript(graph),
    content,
    bodyEnd,
    cssVersion,
    jsVersion,
    year,
    section: meta.section || '',
  };
  return render(partials.base, { ...vars, content: render(content, vars) });
}

// Post list HTML (blog index cards, home links).
const postCards = posts
  .map(
    (p) => `<li class="card post-card">
<h2><a href="${p.path}">${esc(p.title)}</a></h2>
<p>${esc(p.description)}</p>
<p class="meta"><time datetime="${p.date}">${fmtDate(p.date)}</time></p>
</li>`,
  )
  .join('\n');
const postLinks = posts.map((p) => `<li><a href="${p.path}">${esc(p.title)}</a></li>`).join('\n');

// --- Pages
for (const f of readdirSync(join(SRC, 'pages')).filter((x) => x.endsWith('.html'))) {
  const src = read(join(SRC, 'pages', f));
  const m = src.match(/^<!--page\n([\s\S]*?)\n-->\n/);
  if (!m) throw new Error(f + ': missing <!--page --> meta');
  const meta = JSON.parse(m[1]);
  let body = src.slice(m[0].length).replace('{{postCards}}', postCards).replace('{{postLinks}}', postLinks);
  body = render(body, { section: meta.section || '' });
  const graph = [org()];
  if (meta.path !== '/') graph.push(breadcrumbs([['Home', '/'], ...(meta.crumbs || [[meta.h1 || meta.title, meta.path]])]));
  for (const type of meta.schema || []) {
    if (type === 'WebSite') graph.push({ '@type': 'WebSite', '@id': `${SITE}/#website`, name: 'BulkTab', url: SITE + '/', publisher: { '@id': ORG_ID }, inLanguage: 'en' });
    else if (type === 'SoftwareApplication') graph.push(app());
    else if (type === 'SoftwareApplicationFeatures') graph.push(app({ featureList: meta.featureList }));
    else if (type === 'SoftwareApplicationOffers') graph.push(app({ offers: [freeOffer(), proOffer()] }));
    else if (type === 'FAQPage') graph.push(faqFrom(body));
    else if (type === 'Blog')
      graph.push({
        '@type': 'Blog',
        name: 'BulkTab Blog',
        url: `${SITE}/blog/`,
        publisher: { '@id': ORG_ID },
        inLanguage: 'en',
        blogPost: posts.map((p) => ({ '@type': 'BlogPosting', headline: p.title, url: SITE + p.path, datePublished: p.date, dateModified: p.updated })),
      });
    else throw new Error(f + ': unknown schema ' + type);
  }
  let bodyEnd = '';
  if (meta.checkout) {
    const fs = cfg.freemius;
    // Nothing third-party loads with the page: the Freemius script is fetched on the first
    // click of a buy link. If it can't load, the link's hosted-checkout href is followed.
    bodyEnd = `<script>
(function () {
	var links = document.querySelectorAll('[data-fs-checkout]');
	var loading = null;
	var handler = null;
	function loadCheckout() {
		if (!loading) {
			loading = new Promise(function (resolve, reject) {
				var s = document.createElement('script');
				s.src = '${fs.script}';
				s.onload = resolve;
				s.onerror = reject;
				document.head.appendChild(s);
			});
		}
		return loading;
	}
	links.forEach(function (link) {
		link.addEventListener('click', function (e) {
			if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) {
				return; // new tab or window: follow the hosted-checkout link
			}
			e.preventDefault();
			link.setAttribute('aria-busy', 'true');
			loadCheckout().then(function () {
				if (!handler) {
					handler = new FS.Checkout({
						product_id: '${fs.productId}',
						plan_id: '${fs.planId}',
						public_key: '${fs.publicKey}',
						image: '${SITE}/assets/img/bulktab-icon-100.png'
					});
				}
				link.removeAttribute('aria-busy');
				handler.open({
					title: 'BulkTab Pro',
					licenses: 1,
					// After a purchase, when the buyer closes the checkout, show the setup steps.
					success: function () {
						window.location.href = '/install-pro/';
					}
				});
			}).catch(function () {
				window.location.href = link.href;
			});
		});
	});
})();
</script>`;
  }
  writePage(meta.path, layout({ meta, content: body, graph, bodyEnd }));
  if (!meta.noindex) pagesOut.push({ path: meta.path, updated: meta.updated, title: meta.title, description: meta.description });
}

// --- Posts
const author = cfg.author;
for (const p of posts) {
  let body = p.body;
  body = render(body.replace(/\{\{snippet:([a-z0-9-]+)\}\}/g, (_, n) => snippet(n)).replace(/\{\{figure:([a-z0-9]+)\}\}/g, (_, id) => figure(id)), {});
  let html = marked.parse(body);
  html = html.replace('<p>', '<p class="answer">'); // first paragraph: the direct answer
  const related = p.related.split(',').map((s) => postBySlug[s.trim()]);
  if (related.some((r) => !r)) throw new Error(p.file + ': unknown related post');
  const docTitle = (p.title + ' | BulkTab').length <= 60 ? p.title + ' | BulkTab' : p.title;
  const content = `<article class="post">
<div class="wrap narrow">
<nav class="breadcrumb" aria-label="Breadcrumb"><ol><li><a href="/">Home</a></li><li><a href="/blog/">Blog</a></li><li><span aria-current="page">${esc(p.title)}</span></li></ol></nav>
<h1>${esc(p.title)}</h1>
<p class="byline">By <a href="${author.url.replace(SITE, '')}">${esc(author.name)}</a> · Published <time datetime="${p.date}">${fmtDate(p.date)}</time> · Updated <time datetime="${p.updated}">${fmtDate(p.updated)}</time></p>
${html}
<h2 id="related-guides">Related guides</h2>
<ul>
${related.map((r) => `<li><a href="${r.path}">${esc(r.title)}</a></li>`).join('\n')}
<li><a href="/features/">Every BulkTab feature, explained</a></li>
<li><a href="/pricing/">BulkTab pricing: Free vs Pro</a></li>
</ul>
<aside class="about-box" aria-label="About the author">
<img src="/assets/img/bulktab-icon.svg" alt="" width="48" height="48">
<p><strong>About ${esc(author.name)}.</strong> ${esc(author.bio)}</p>
</aside>
</div>
</article>
{{> cta}}`;
  const graph = [
    org(),
    breadcrumbs([['Home', '/'], ['Blog', '/blog/'], [p.title, p.path]]),
    {
      '@type': 'BlogPosting',
      '@id': SITE + p.path + '#post',
      headline: p.title,
      description: p.description,
      datePublished: p.date,
      dateModified: p.updated,
      author: authorLd(),
      publisher: { '@id': ORG_ID },
      mainEntityOfPage: SITE + p.path,
      image: OG_IMAGE,
      inLanguage: 'en',
      about: p.query,
    },
    faqFrom(html),
  ];
  const meta = { path: p.path, title: docTitle, ogTitle: p.title, description: p.description, section: 'blog' };
  const articleMeta = `<meta property="article:published_time" content="${p.date}">\n<meta property="article:modified_time" content="${p.updated}">`;
  writePage(p.path, layout({ meta, content, graph, ogType: 'article', articleMeta }));
  pagesOut.push({ path: p.path, updated: p.updated, title: p.title, description: p.description, post: p });
}

// ------------------------------------------------------------------ feeds and crawler files
const sitemap = `<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
${pagesOut.map((p) => `  <url><loc>${SITE}${p.path}</loc><lastmod>${p.updated}</lastmod></url>`).join('\n')}
</urlset>
`;
writeFileSync(join(DIST, 'sitemap.xml'), sitemap);

const rfc822 = (d) => new Date(d + 'T12:00:00Z').toUTCString();
const rss = `<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
<channel>
<title>BulkTab Blog</title>
<link>${SITE}/blog/</link>
<atom:link href="${SITE}/rss.xml" rel="self" type="application/rss+xml"/>
<description>Practical guides to WooCommerce product tabs from the BulkTab team.</description>
<language>en</language>
<lastBuildDate>${rfc822(posts.map((p) => p.updated).sort().pop())}</lastBuildDate>
${posts
  .map(
    (p) => `<item>
<title>${xml(p.title)}</title>
<link>${SITE}${p.path}</link>
<guid isPermaLink="true">${SITE}${p.path}</guid>
<pubDate>${rfc822(p.date)}</pubDate>
<description>${xml(p.description)}</description>
</item>`,
  )
  .join('\n')}
</channel>
</rss>
`;
writeFileSync(join(DIST, 'rss.xml'), rss);

const page = (path) => pagesOut.find((p) => p.path === path);
const llmsLine = (p) => `- [${p.title}](${SITE}${p.path}): ${p.description}`;
const llms = `# BulkTab – Bulk Product Tabs for WooCommerce

> BulkTab is a free WordPress plugin by ${cfg.company.name} (${cfg.company.city}, ${cfg.company.region}) for WooCommerce stores. Write a product tab once (a size guide, shipping and returns, specifications) and show it on every product in chosen categories, tags or brands. Tabs are stored as plain fields that survive updates, CSV imports and migrations, with undo. Free on WordPress.org with no locked features and no tracking. BulkTab Pro ($${cfg.pro.price}/year, 1 site) adds combined rules, bulk changes with one-click undo, per-product overrides, backup and restore, and WP All Import support.

Requirements: WordPress 6.8+, PHP 7.4+, WooCommerce (classic product editor). Free download: ${cfg.FREE_URL}. Source: ${cfg.githubUrl}.

## Product
${[page('/'), page('/features/')].map(llmsLine).join('\n')}

## Pricing
${llmsLine(page('/pricing/'))}

## Guides
${[page('/blog/'), ...posts.map((p) => page(p.path))].map(llmsLine).join('\n')}

## Support
${[page('/support/'), page('/install-pro/'), page('/privacy/'), page('/terms/')].map(llmsLine).join('\n')}
- [Support email](mailto:${cfg.supportEmail}): BulkTab Pro customers and general questions.
- [WordPress.org support forum](${cfg.forumUrl}): free plugin support.
`;
writeFileSync(join(DIST, 'llms.txt'), llms);

const bots = ['GPTBot', 'OAI-SearchBot', 'ChatGPT-User', 'ClaudeBot', 'Claude-SearchBot', 'Claude-User', 'anthropic-ai', 'PerplexityBot', 'Perplexity-User', 'Google-Extended', 'Applebot-Extended', 'CCBot', 'Meta-ExternalAgent', 'Amazonbot', 'DuckAssistBot', 'MistralAI-User'];
const robots = `# All crawlers are welcome, including AI search and answer engines.
User-agent: *
Allow: /

${bots.map((b) => `User-agent: ${b}\nAllow: /`).join('\n\n')}

Sitemap: ${SITE}/sitemap.xml
`;
writeFileSync(join(DIST, 'robots.txt'), robots);

console.log(`Built ${pagesOut.length} indexable pages (+404) into dist/: ${pagesOut.map((p) => p.path).join(' ')}`);
