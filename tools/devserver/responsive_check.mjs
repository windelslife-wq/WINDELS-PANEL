/**
 * Responsive-layout checks.
 *
 * DEV TOOLING ONLY. This is a static rendered-markup/CSS audit, not a pixel
 * test (there is no browser engine in this harness). It exercises the public,
 * customer/seller and staff surfaces and catches missing viewport tags, hard
 * fixed-width inline layouts, and shell breakpoints that can force overflow.
 *
 *   node tools/devserver/responsive_check.mjs --base ... --admin-password ...
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { Client } from './client.mjs';

const argv = process.argv.slice(2);
const arg = (name, def = '') => { const i = argv.indexOf(name); return i === -1 ? def : argv[i + 1]; };
const BASE = arg('--base', 'http://127.0.0.1:8080');
const ADMIN_PASSWORD = arg('--admin-password');
const CUSTOMER_PASSWORD = arg('--password', ADMIN_PASSWORD);
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');

const results = [];
function check(label, ok, detail = '') {
  results.push({ label, ok: !!ok, detail });
  console.log(`   ${ok ? '✓' : '✗'} ${label}${ok || !detail ? '' : `\n       ${detail}`}`);
}

async function inspectPage(label, get) {
  let response;
  try { response = await get(); }
  catch (error) {
    check(`${label} is reachable and responsive`, false, error.message);
    return;
  }
  const html = response.text;
  if (response.status !== 200) {
    check(`${label} is reachable and responsive`, false, `HTTP ${response.status}`);
    return;
  }

  const hasViewport = /<meta[^>]+name=["']viewport["'][^>]+width=device-width/i.test(html);
  if (!hasViewport) {
    check(`${label} declares a viewport`, false, 'missing width=device-width — the page will render zoomed out');
    return;
  }

  // Fixed widths in inline styles are the usual cause of horizontal overflow.
  // max-width and min-width are safe, but a hard width >420px can push the
  // layout wider than a phone. Include inline style blocks as well as attrs.
  const inlineCss = [
    ...[...html.matchAll(/style=["']([^"']*)["']/gi)].map((m) => m[1]),
    ...[...html.matchAll(/<style\b[^>]*>([\s\S]*?)<\/style>/gi)].map((m) => m[1]),
  ].join(';');
  const wide = [...inlineCss.matchAll(/(?:^|[;{])\s*([a-z-]*)width:\s*(\d{3,})px/gi)]
    .filter((m) => m[1] !== 'max-' && m[1] !== 'min-')
    .map((m) => parseInt(m[2], 10))
    .filter((w) => w > 420);

  // A grid with 3+ fixed columns needs an inline breakpoint; shared CSS grids
  // are checked through the design-system breakpoint rules below.
  const rigidGrid = /grid-template-columns:\s*repeat\((\d+),/i.exec(inlineCss);
  const hasBreakpoint = /@media\s*\([^)]*max-width/i.test(inlineCss);
  const ok = wide.length === 0 && (!rigidGrid || parseInt(rigidGrid[1], 10) <= 2 || hasBreakpoint);
  check(
    `${label} has no fixed-width overflow`,
    ok,
    wide.length ? `inline width up to ${Math.max(...wide)}px` : 'multi-column grid with no max-width breakpoint'
  );
}

console.log('── Responsive · rendered pages');
for (const p of [
  '/', '/services', '/services?q=instagram', '/pricing', '/about', '/faq', '/contact', '/terms', '/login', '/register',
  '/shop', '/shop?q=gift', '/shop/gift-cards',
]) {
  await inspectPage(p, async () => {
    const r = await fetch(BASE + p);
    return { status: r.status, text: await r.text() };
  });
}

if (ADMIN_PASSWORD) {
  const admin = new Client(BASE);
  await admin.get('/admin/login');
  const a = await admin.postForm('/admin/login', { identifier: 'admin', password: ADMIN_PASSWORD });
  check('staff session is available for responsive checks', /\/admin/.test(a.url) && !/login/.test(a.url), a.url);
  if (/\/admin/.test(a.url) && !/login/.test(a.url)) {
    for (const p of [
      '/admin', '/admin/orders', '/admin/customers', '/admin/wallets', '/admin/analytics',
      '/admin/services', '/admin/catalogue', '/admin/providers', '/admin/tickets', '/admin/payments',
      '/admin/marketplace', '/admin/shop', '/admin/affiliates', '/admin/staff', '/admin/settings',
      '/admin/api-keys', '/admin/inbox',
    ]) await inspectPage(`staff ${p}`, () => admin.get(p));
  }

  const customer = new Client(BASE);
  await customer.get('/login');
  const c = await customer.postForm('/login', { identifier: 'demo@marvy.local', password: CUSTOMER_PASSWORD });
  check('customer session is available for responsive checks', /\/dashboard/.test(c.url) && !/login/.test(c.url), c.url);
  if (/\/dashboard/.test(c.url) && !/login/.test(c.url)) {
    for (const p of [
      '/dashboard', '/dashboard/orders', '/dashboard/history', '/dashboard/transactions',
      '/dashboard/new-order', '/dashboard/services', '/dashboard/marketplace',
      '/dashboard/marketplace/orders', '/dashboard/tickets', '/dashboard/add-funds',
      '/dashboard/security', '/cart', '/checkout',
    ]) await inspectPage(`customer ${p}`, () => customer.get(p));
  }
} else {
  console.log('   (authenticated page checks skipped: pass --admin-password to enable them)');
}

console.log('\n── Responsive · stylesheet');
const cssPath = path.join(ROOT, 'assets/css/design-system.css');
const css = fs.readFileSync(cssPath, 'utf8');
check('the design system defines mobile breakpoints', /@media[^{]*max-width/i.test(css));
check('tables can scroll rather than overflow', /overflow-x\s*:\s*auto/i.test(css),
  'no horizontal scroll container — wide tables will push the page');
check('the sidebar collapses on small screens',
  /@media[^{]*max-width[^}]*\{[\s\S]{0,4000}?(sidebar|ws-side|nav)/i.test(css),
  'no responsive rule mentioning the sidebar');
check('no viewport-breaking min-width on the body',
  !/body\s*\{[^}]*min-width:\s*\d{3,}px/i.test(css));

console.log('\n── Responsive · dashboard shell');
const appLayout = fs.readFileSync(path.join(ROOT, 'application/views/layouts/app.php'), 'utf8');
check('the sidebar is hidden on small screens',
  /@media\s*\(max-width:\s*767px\)\s*\{[\s\S]{0,400}?\.ws-sidebar\s*\{[\s\S]{0,200}?transform:\s*translateX\(-100%\)/.test(css)
    && appLayout.includes('class="ws-sidebar"'),
  'no off-canvas transform for .ws-sidebar below the mobile breakpoint');
check('a mobile navigation replaces it',
  /\.ws-mobile-tabbar\s*\{[^}]*display:\s*none/.test(css)
    && /@media\s*\(max-width:\s*767px\)\s*\{[\s\S]{0,2000}?\.ws-mobile-tabbar\s*\{[^}]*display:\s*grid/.test(css)
    && appLayout.includes('class="ws-mobile-tabbar"'), 'no mobile bottom navigation');
check('the app shell declares a viewport', /width=device-width/.test(appLayout)
  || /partials\/head/.test(appLayout), 'no viewport and no shared head partial');

const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
process.exit(failed.length ? 1 : 0);
