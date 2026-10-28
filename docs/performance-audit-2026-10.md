# Platform performance audit — 2026-10-28

## Scope and evidence

Reviewed the public storefront, service search, customer and seller surfaces, cart/checkout, wallets, order history, staff operations, Super Admin pages, shared assets, and the common authenticated request path. Performance measurements use the project's real CodeIgniter app on its SQLite-backed MySQL-protocol dev server; they validate route/query shape and are **not production MySQL latency claims**.

The load fixture is tagged and cleaned by `tools/devserver/seed_load.mjs`: 12,000 orders, 20,000 wallet movements, 4,000 service transactions, 400 users, 800 tickets, in addition to existing demo/E2E records. Latest sample volume was 12,026 orders, 20,149 wallet transactions and 821 tickets. The performance checker now covers 43 public/customer/seller/operator pages and asserts HTTP 200, per-page query ceilings, a 1,500 ms loose sandbox response ceiling, and list-query growth as result size increases.

## Bottlenecks fixed

- **Duplicate unread-count reads across authenticated pages:** 66 controller-supplied `DashboardStats::unread_count()` values across 42 controllers repeated work already needed by the shared notification bell. Removed those per-controller reads and unused library loads. The bell count is resolved lazily when the authenticated HTML shell renders, so POST/redirect and API requests do not query a badge they never display. The customer dashboard also skips the duplicate overview count. Inbox unread state now uses its own `inbox_unread` value and no longer overwrites the notification badge; pages that had forced the badge to zero now inherit the correct shared value.
- **Tailwind production-build flag was ineffective:** the build used unsupported `--minified`, leaving the output unminified. Changed it to Tailwind's `--minify`: `assets/css/tailwind.css` went from 26,506 to 17,787 bytes (**8,719 bytes / 32.9% smaller**). The generated file is rebuilt and included in the package.
- **Blocking external web-font CSS in authenticated shells:** public pages already had a fallback, but the two authenticated layouts linked Google Fonts as render-blocking stylesheets. All shells now share a CSP-nonce-authorized asynchronous font loader. Local system-font stacks render immediately and remain usable if the font host or JavaScript is unavailable; Fraunces is only requested by the full theme.
- **Text transfer encoding:** enabled gzip for HTML/CSS/JS/JSON/SVG text responses in both Nginx profiles. Static assets retain the existing seven-day expiry. Local gzip estimates (not a live Nginx response test): Tailwind 17,787 → 4,165 bytes; design system 62,431 → 16,568; marketing CSS 8,061 → 2,485; app JavaScript 37,406 → 10,134.
- **SQLite development search compatibility:** service search emitted MySQL `MATCH ... AGAINST` SQL, which the local SQLite backend rejected with HTTP 500 despite the query having a portable `LIKE` fallback. The dev-only SQL translator now lowers the full-text term to false and preserves the `LIKE` branch. Production MySQL full-text behavior is unchanged.
- **Shared-DB E2E mail backlog:** the email worker is intentionally batch-bounded at 50; its test assumed an empty queue. The notifications check now drains due batches before asserting delivery, so previous suites cannot leave unrelated due mail behind.

## Measurements

`node tools/devserver/perf_check.mjs --base http://127.0.0.1:8081 --admin-password '…' --customer-password '…'` after seeding the high-volume fixture: **137/137 checks passed** across 43 pages. All page responses were HTTP 200; measured response times were 44–131 ms in this run. Representative query counts: Super Admin dashboard 26, admin orders 9, admin customers 12, analytics 19, customer dashboard 16, customer orders 8, order history 10, service search 8, shop 9, cart 11, checkout 13, seller marketplace 12, seller orders 7. The three tested list endpoints remained bounded as page size grew.

`responsive_check.mjs` now audits 43 rendered public/customer/staff routes (not just marketing pages): **52/52 static responsive checks passed**, including viewport declarations, fixed inline-width checks, mobile sidebar/tabbar rules and table scrolling. This is markup/CSS inspection, not a real-browser pixel or device-emulation test.

Relevant regressions also passed: full PHP suite **1,818 tests, 0 failures, 1 documented skip**; JS behavior/SQL translation suite; dashboard/admin/assets focused tests; admin E2E 18/18; analytics 21/21; notifications 22/22; chrome 91/91. The deployment package built and validated. No deployment occurred.

## Remaining verification limits

- No production MySQL instance was available for `EXPLAIN`, index-plan comparisons, or realistic remote DB connection latency. Query-count and growth evidence comes from the local translator stack.
- Nginx is not installed in this environment, so gzip/cache directives were source-tested and gzip sizes were estimated locally, but not exercised through the production Nginx container.
- Responsive checks are static; a real browser on physical mobile/desktop devices is still needed for CLS/LCP/INP and visual overflow confirmation.
- A traffic-triggered cron batch still runs inline on the request that wins the once-per-minute heartbeat. In a prior synthetic run with a test backlog, one request included 1,236 cron-related queries and took 2.77 s. The perf harness performs an unmeasured warm-up to separate that background work from page-widget costs; this does not remove the occasional cron-triggered request latency. Production cron scheduling/background execution should be validated separately.
