/**
 * Performance check — what the heavy screens cost under real volume.
 *
 * DEV TOOLING ONLY. Every earlier performance claim in this project was made
 * against a database with a dozen orders in it, where an N+1 costs twelve
 * queries and nobody notices. This measures the pages that matter against the
 * volume `seed_load.mjs` creates (12,000 orders, 20,000 wallet movements, 400
 * customers, 800 tickets), counting the SQL the application actually issues
 * through the dev database's stats side-channel.
 *
 * A page is judged on two numbers:
 *
 *   queries — the shape problem. A list page whose cost grows with the number
 *             of rows on it is an N+1, however fast the database is today.
 *   ms      — the symptom, kept as a loose ceiling only: this is a wasm PHP
 *             runtime over a SQLite translation layer, so absolute timings are
 *             not production numbers. The query count is the real assertion.
 *
 *   node tools/devdb/server.js --port 3399 --stats-port 3400 --db …
 *   node tools/devserver/seed_load.mjs
 *   node tools/devserver/perf_check.mjs --admin-password '…'
 */
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { Client } from './client.mjs';

const require = createRequire(import.meta.url);
const argv = process.argv.slice(2);
const arg = (name, def) => { const i = argv.indexOf(name); return i === -1 ? def : argv[i + 1]; };
const BASE = arg('--base', 'http://127.0.0.1:8080');
const STATS = arg('--stats', 'http://127.0.0.1:3400');
const DB_PATH = arg('--db', 'storage/devdb/marvy.sqlite');
const ADMIN_PASSWORD = arg('--admin-password', 'Demo!cabcd50b');
const CUSTOMER_PASSWORD = arg('--customer-password', ADMIN_PASSWORD);
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');

const results = [];
function check(label, ok, detail = '') {
  results.push({ label, ok: !!ok, detail });
  console.log(`   ${ok ? '✓' : '✗'} ${label}${ok || !detail ? '' : `\n       ${detail}`}`);
}
function withDb(fn) {
  const { DatabaseSync } = require('node:sqlite');
  const db = new DatabaseSync(path.resolve(ROOT, DB_PATH));
  try { return fn(db); } finally { db.close(); }
}

async function stats(reset = false) {
  const res = await fetch(STATS + (reset ? '/reset' : '/'), { method: reset ? 'POST' : 'GET' });
  return res.json();
}

/** Load a page and report what it cost. */
async function measure(client, url) {
  await stats(true);
  const started = Date.now();
  const res = await client.get(url);
  const ms = Date.now() - started;
  const s = await stats();
  return { url, status: res.status, ms, queries: s.queries, byTable: s.byTable,
           slowest: s.slowest, text: res.text };
}

/* --------------------------- volume + sanity ----------------------------- */

const volume = withDb((db) => ({
  users: db.prepare(`SELECT COUNT(*) n FROM users`).get().n,
  orders: db.prepare(`SELECT COUNT(*) n FROM orders`).get().n,
  wallet_transactions: db.prepare(`SELECT COUNT(*) n FROM wallet_transactions`).get().n,
  tickets: db.prepare(`SELECT COUNT(*) n FROM tickets`).get().n,
}));
console.log('\nvolume under test:', JSON.stringify(volume));

try {
  await stats();
} catch {
  console.error('\n  the dev database has no stats channel — restart it with --stats-port 3400\n');
  process.exit(2);
}
check('the database carries enough history to be worth measuring',
  volume.orders >= 5000, JSON.stringify(volume));

/* -------------------------------- sign in -------------------------------- */

const admin = new Client(BASE);
await admin.get('/admin/login');
const alogin = await admin.postForm('/admin/login', { identifier: 'admin', password: ADMIN_PASSWORD });
check('admin signed in', /\/admin/.test(alogin.url) && !/login/.test(alogin.url), alogin.url);

const cust = new Client(BASE);
await cust.get('/login');
const clogin = await cust.postForm('/login',
  { identifier: 'demo@marvy.local', password: CUSTOMER_PASSWORD });
check('customer signed in', /\/dashboard/.test(clogin.url), clogin.url);
const pub = new Client(BASE);

/* ------------------------------ the screens ------------------------------ */

// Every ordinary request can elect the once-per-minute in-app cron heartbeat.
// Warm the admin dashboard once before taking query deltas so an overdue
// worker batch is not accidentally attributed to the dashboard widgets.
await admin.get('/admin');

// Query ceilings are deliberately generous: the point is to catch a cost that
// scales with the rows on the page, not to shave a query off a fixed shell.
const pages = [
  // Ceilings are set just above what each page costs today, so a regression
  // that reintroduces a per-row query fails here instead of being discovered
  // by an operator whose panel got slower.
  // Module 20 consolidated the widgets (31 queries -> 20). Later command-center
  // additions include cross-domain revenue/health, staff inbox/contact queues,
  // and freshness. Their reads are fixed per widget, not row-proportional. The
  // 26-query ceiling is the measured cost after removing the duplicate unread
  // count, and remains tight enough to catch an accidental extra query.
  ['admin dashboard',        admin, '/admin',                      26],
  ['admin orders (page 1)',  admin, '/admin/orders',               14],
  ['admin orders (page 20)', admin, '/admin/orders?page=20',       14],
  ['admin customers',        admin, '/admin/customers',            18],
  ['admin wallets',          admin, '/admin/wallets',              14],
  ['admin analytics 30d',    admin, '/admin/analytics?days=30',    22],
  ['admin analytics 90d',    admin, '/admin/analytics?days=90',    22],
  ['admin tickets',          admin, '/admin/tickets',              14],
  ['admin refunds',          admin, '/admin/refunds',              14],
  ['customer dashboard',     cust,  '/dashboard',                  18],
  ['customer orders',        cust,  '/dashboard/orders',           14],
  ['customer transactions',  cust,  '/dashboard/transactions',     15],
  ['customer downloads',     cust,  '/dashboard/downloads',        13],
  ['customer tickets',       cust,  '/dashboard/tickets',          14],
  ['customer history',       cust,  '/dashboard/history',          16],
  ['public services',        cust,  '/services',                   13],
  // Broader storefront, customer/vendor, checkout and operator surfaces.
  // The ceilings are a few queries over the 12k-order measured baseline.
  ['public home',             pub,   '/',                            16],
  ['public service search',   pub,   '/services?q=instagram',        12],
  ['public shop',             pub,   '/shop',                        12],
  ['shop search',             pub,   '/shop?q=gift',                 10],
  ['gift cards catalogue',    pub,   '/shop/gift-cards',              7],
  ['pricing page',            pub,   '/pricing',                      6],
  ['customer order history',  cust,  '/dashboard/history',           13],
  ['customer new order',      cust,  '/dashboard/new-order',         14],
  ['customer service list',   cust,  '/dashboard/services',          14],
  ['seller marketplace',      cust,  '/dashboard/marketplace',       15],
  ['seller orders',           cust,  '/dashboard/marketplace/orders',10],
  ['customer support tickets',cust,  '/dashboard/tickets',            11],
  ['customer add funds',      cust,  '/dashboard/add-funds',         15],
  ['cart',                    cust,  '/cart',                        14],
  ['checkout',                cust,  '/checkout',                     16],
  ['account security',        cust,  '/dashboard/security',           10],
  ['admin SMM services',      admin, '/admin/services',               13],
  ['admin service catalogue', admin, '/admin/catalogue',              16],
  ['admin providers',         admin, '/admin/providers',              11],
  ['admin payments',          admin, '/admin/payments',               12],
  ['marketplace moderation',  admin, '/admin/marketplace',            10],
  ['shop operations',         admin, '/admin/shop',                    9],
  ['affiliate management',    admin, '/admin/affiliates',             14],
  ['staff management',        admin, '/admin/staff',                  15],
  ['admin settings',          admin, '/admin/settings',               10],
  ['reseller API keys',       admin, '/admin/api-keys',               14],
  ['staff inbox',             admin, '/admin/inbox',                  11],
];

console.log('\n── Cost of each screen under load');
console.log('   ' + 'page'.padEnd(26) + 'status  queries      ms   heaviest table');
const measured = [];
for (const [label, client, url, ceiling] of pages) {
  const m = await measure(client, url);
  measured.push({ label, ceiling, ...m });
  const heaviest = Object.entries(m.byTable).sort((a, b) => b[1] - a[1])[0] || ['—', 0];
  console.log(`   ${label.padEnd(26)}${String(m.status).padEnd(8)}${String(m.queries).padStart(7)}`
            + `${String(m.ms).padStart(8)}   ${heaviest[0]} x${heaviest[1]}`);
}

console.log('');
for (const m of measured) {
  check(`${m.label} loads`, m.status === 200, `status=${m.status}`);
}
for (const m of measured) {
  check(`${m.label} stays under ${m.ceiling} queries`, m.queries <= m.ceiling,
    `${m.queries} queries; heaviest: ${JSON.stringify(m.byTable)}`);
}
// A loose ceiling for the wasm/SQLite dev stack, not a production SLA. It is
// high enough for the sandbox's translation overhead, but catches pages that
// regress from sub-150ms to multi-second synchronous work.
const RESPONSE_CEILING_MS = 1500;
for (const m of measured) {
  check(`${m.label} responds within ${RESPONSE_CEILING_MS} ms`, m.ms <= RESPONSE_CEILING_MS,
    `${m.ms} ms`);
}

/* ------------------------- the N+1 shape, directly ----------------------- */

// A list page must cost the same whether it shows 5 rows or 100. Comparing two
// page sizes is the only way to tell a fixed shell from a per-row query.
console.log('\n── Does the cost grow with the number of rows?');
for (const [label, client, small, large] of [
  ['admin orders', admin, '/admin/orders?per_page=5', '/admin/orders?per_page=100'],
  ['admin customers', admin, '/admin/customers?per_page=5', '/admin/customers?per_page=100'],
  ['customer orders', cust, '/dashboard/orders?per_page=5', '/dashboard/orders?per_page=100'],
]) {
  const a = await measure(client, small);
  const b = await measure(client, large);
  const growth = b.queries - a.queries;
  check(`${label}: 20x the rows costs no more than 5 extra queries`,
    growth <= 5, `${a.queries} -> ${b.queries} queries`);
}

/* ------------------------------- the API --------------------------------- */

console.log('\n── The reseller API under load');
const key = withDb((db) => db.prepare(
  `SELECT public_id FROM api_keys WHERE revoked_at IS NULL LIMIT 1`).get());
if (key) {
  const anon = new Client(BASE);
  const m = await measure(anon, '/api/v1/services');
  check('GET /api/v1/services answers', [200, 401].includes(m.status), `status=${m.status}`);
  check('and costs a bounded number of queries', m.queries <= 20, `${m.queries} queries`);
}

const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
if (failed.length) {
  console.log('\nFailures:');
  for (const f of failed) console.log(`  ${f.label} — ${f.detail}`);
  process.exit(1);
}
