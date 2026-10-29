/**
 * Visual preview of the public Active Digital Hub site.
 * Serves the real design-system CSS / app.js and static HTML that mirrors
 * the PHP views, plus a local /assistant/chat that uses the same knowledge.
 * Not a replacement for the CodeIgniter app.
 */
const http = require('http');
const fs = require('fs');
const path = require('path');
const url = require('url');

const ROOT = path.join(__dirname, '..');
const PORT = Number(process.env.PORT || 8080);
const HOST = process.env.HOST || '0.0.0.0';

const faqs = [
  ['General', 'What is Active Digital Hub?', 'A prepaid reseller platform for SMM, Nigerian VTU, virtual numbers, identity lookups, gift cards and a platform-owned marketplace. The wallet is a spending balance — there are no customer withdrawals.'],
  ['General', 'Do I need a subscription?', 'No. Accounts are free. You pay published rates from a prepaid wallet. There is no public monthly SaaS plan.'],
  ['Accounts', 'How do I create an account?', 'Register with a username, email and password (at least 8 characters) and accept the Terms. A wallet is created automatically.'],
  ['Accounts', 'I forgot my password.', 'Use Forgot password. If an account matches we email a reset link. The confirmation is the same either way so addresses cannot be probed.'],
  ['Services', 'What can I order?', 'The live SMM catalogue is on /services. Signed-in customers can also use VTU, numbers, identity, gift cards and the marketplace when those products are priced.'],
  ['Pricing and billing', 'How does pricing work?', 'You add funds and pay the published rate for each service. Volume groups (Silver, Gold, Reseller) are assigned by staff. Default deposit minimum is ₦500.'],
  ['Pricing and billing', 'Can I withdraw wallet funds?', 'No. The wallet is a spending balance for purchases on this panel.'],
  ['Security', 'Is the site assistant a cloud AI?', 'No. It is an embedded operational engine. It does not call a third-party AI API and cannot place orders.'],
  ['Technical support', 'How do I contact support?', 'Use the contact form. Signed-in customers get a ticket. Include the public order ID.'],
  ['Administrators', 'Where do staff sign in?', 'Use /admin/login. Customer accounts are refused after the password check.'],
];

function esc(s) {
  return String(s).replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

/* Social channel buttons — mirrors partials/footer.php + partials/social_icon.php
   (the original brand marks from Simple Icons, CC0) so the preview shows the
   footer the PHP app actually renders. Each mark carries its ORIGINAL brand
   colour as its own fill (Instagram is its published gradient, inline defs),
   and design-system.css puts them on white buttons. URLs match the seeded
   social_*_url defaults. */
const socialGlyphs = {
  facebook: '<path fill="#0866FF" d="M9.101 23.691v-7.98H6.627v-3.667h2.474v-1.58c0-4.085 1.848-5.978 5.858-5.978.401 0 .955.042 1.468.103a8.68 8.68 0 0 1 1.141.195v3.325a8.623 8.623 0 0 0-.653-.036 26.805 26.805 0 0 0-.733-.009c-.707 0-1.259.096-1.675.309a1.686 1.686 0 0 0-.679.622c-.258.42-.374.995-.374 1.752v1.297h3.919l-.386 2.103-.287 1.564h-3.246v8.245C19.396 23.238 24 18.179 24 12.044c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.628 3.874 10.35 9.101 11.647Z"/>',
  instagram: '<defs><linearGradient id="ws-social-instagram" x1="0" y1="1" x2="1" y2="0"><stop offset="0" stop-color="#FEDA75"/><stop offset=".28" stop-color="#FA7E1E"/><stop offset=".62" stop-color="#D62976"/><stop offset=".86" stop-color="#962FBF"/><stop offset="1" stop-color="#4F5BD5"/></linearGradient></defs><path fill="url(#ws-social-instagram)" d="M7.0301.084c-1.2768.0602-2.1487.264-2.911.5634-.7888.3075-1.4575.72-2.1228 1.3877-.6652.6677-1.075 1.3368-1.3802 2.127-.2954.7638-.4956 1.6365-.552 2.914-.0564 1.2775-.0689 1.6882-.0626 4.947.0062 3.2586.0206 3.6671.0825 4.9473.061 1.2765.264 2.1482.5635 2.9107.308.7889.72 1.4573 1.388 2.1228.6679.6655 1.3365 1.0743 2.1285 1.38.7632.295 1.6361.4961 2.9134.552 1.2773.056 1.6884.069 4.9462.0627 3.2578-.0062 3.668-.0207 4.9478-.0814 1.28-.0607 2.147-.2652 2.9098-.5633.7889-.3086 1.4578-.72 2.1228-1.3881.665-.6682 1.0745-1.3378 1.3795-2.1284.2957-.7632.4966-1.636.552-2.9124.056-1.2809.0692-1.6898.063-4.948-.0063-3.2583-.021-3.6668-.0817-4.9465-.0607-1.2797-.264-2.1487-.5633-2.9117-.3084-.7889-.72-1.4568-1.3876-2.1228C21.2982 1.33 20.628.9208 19.8378.6165 19.074.321 18.2017.1197 16.9244.0645 15.6471.0093 15.236-.005 11.977.0014 8.718.0076 8.31.0215 7.0301.0839m.1402 21.6932c-1.17-.0509-1.8053-.2453-2.2287-.408-.5606-.216-.96-.4771-1.3819-.895-.422-.4178-.6811-.8186-.9-1.378-.1644-.4234-.3624-1.058-.4171-2.228-.0595-1.2645-.072-1.6442-.079-4.848-.007-3.2037.0053-3.583.0607-4.848.05-1.169.2456-1.805.408-2.2282.216-.5613.4762-.96.895-1.3816.4188-.4217.8184-.6814 1.3783-.9003.423-.1651 1.0575-.3614 2.227-.4171 1.2655-.06 1.6447-.072 4.848-.079 3.2033-.007 3.5835.005 4.8495.0608 1.169.0508 1.8053.2445 2.228.408.5608.216.96.4754 1.3816.895.4217.4194.6816.8176.9005 1.3787.1653.4217.3617 1.056.4169 2.2263.0602 1.2655.0739 1.645.0796 4.848.0058 3.203-.0055 3.5834-.061 4.848-.051 1.17-.245 1.8055-.408 2.2294-.216.5604-.4763.96-.8954 1.3814-.419.4215-.8181.6811-1.3783.9-.4224.1649-1.0577.3617-2.2262.4174-1.2656.0595-1.6448.072-4.8493.079-3.2045.007-3.5825-.006-4.848-.0608M16.953 5.5864A1.44 1.44 0 1 0 18.39 4.144a1.44 1.44 0 0 0-1.437 1.4424M5.8385 12.012c.0067 3.4032 2.7706 6.1557 6.173 6.1493 3.4026-.0065 6.157-2.7701 6.1506-6.1733-.0065-3.4032-2.771-6.1565-6.174-6.1498-3.403.0067-6.156 2.771-6.1496 6.1738M8 12.0077a4 4 0 1 1 4.008 3.9921A3.9996 3.9996 0 0 1 8 12.0077"/>',
  whatsapp: '<path fill="#25D366" d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/>',
  x: '<path fill="#000000" d="M18.901 1.153h3.68l-8.04 9.19L24 22.846h-7.406l-5.8-7.584-6.638 7.584H.474l8.6-9.83L0 1.154h7.594l5.243 6.932ZM17.61 20.644h2.039L6.486 3.24H4.298Z"/>',
  linkedin: '<path fill="#0A66C2" d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/>',
  telegram: '<path fill="#26A5E4" d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/>',
  youtube: '<path fill="#FF0000" d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/>',
};
const socialChannels = [
  ['facebook', 'Facebook', 'https://www.facebook.com/'],
  ['instagram', 'Instagram', 'https://www.instagram.com/'],
  ['whatsapp', 'WhatsApp', 'https://www.whatsapp.com/'],
  ['x', 'X (Twitter)', 'https://x.com/'],
  ['linkedin', 'LinkedIn', 'https://www.linkedin.com/'],
  ['telegram', 'Telegram', 'https://t.me/'],
  ['youtube', 'YouTube', 'https://www.youtube.com/'],
];
const socialRow = `<nav class="ws-social" aria-label="Social channels">${socialChannels
  .map(([glyph, label, url]) => `<a class="ws-social-btn ws-social-btn--${glyph}" href="${url}" target="_blank" rel="noopener" aria-label="Active Digital Hub on ${label}"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" aria-hidden="true" class="ws-social-mark">${socialGlyphs[glyph]}</svg></a>`)
  .join('')}</nav>`;

function layout(title, desc, body, extra = '') {
  return `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>${esc(title)} · Active Digital Hub</title>
<meta name="description" content="${esc(desc)}">
<link rel="canonical" href="/">
<meta property="og:title" content="${esc(title)}">
<meta property="og:description" content="${esc(desc)}">
<meta name="csrf-name" content="csrf_marvy">
<meta name="csrf-token" content="preview">
<meta name="csrf-endpoint" content="/csrf">
<link rel="icon" href="/assets/brand/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="/assets/css/design-system.css">
</head>
<body>
<a class="ws-skip" href="#main">Skip to content</a>
<div class="ws-announce" role="region" aria-label="Announcements" tabindex="0" data-announce data-announce-interval="9000">
  <div class="ws-announce-viewport">
    <div class="ws-announce-slides">
      <div class="ws-announce-slide is-active">Prepaid wallet: add funds and spend on services — leftover deposits cannot be withdrawn.</div>
      <div class="ws-announce-slide">New here? Create an account, then browse Services or read Pricing.</div>
      <div class="ws-announce-slide">Need help? Open the FAQ, send a Contact message, or ask the on-site assistant.</div>
      <div class="ws-announce-slide">Staff sign in at Admin login. Customer passwords cannot open the back office.</div>
    </div>
  </div>
  <div class="ws-announce-dots" data-announce-dots aria-hidden="true"></div>
</div>
<nav class="ws-public-nav ws-sticky-below-announce" aria-label="Primary">
  <div class="ws-public-nav-inner">
    <a class="ws-brand" href="/"><img class="ws-logo" src="/assets/brand/logo-dark.svg" alt="Active Digital Hub" height="32" width="240"></a>
    <div class="ws-nav-links">
      <a href="/services">Services</a><a href="/pricing">Pricing</a><a href="/faq">FAQ</a><a href="/blog">Blog</a><a href="/contact">Contact</a>
    </div>
    <div class="ws-nav-actions">
      <div class="ws-nav-desktop row" style="gap:.4rem">
        <a class="btn btn-ghost btn-sm" href="/login">Log in</a>
        <a class="btn btn-primary btn-sm" href="/register">Create account</a>
      </div>
      <button type="button" class="ws-nav-toggle" data-nav-toggle aria-controls="ws-nav-panel" aria-expanded="false">Menu</button>
    </div>
  </div>
  <div id="ws-nav-panel" class="ws-nav-panel" hidden>
    <a href="/services">Services</a><a href="/pricing">Pricing</a><a href="/faq">FAQ</a>
    <a href="/blog">Blog</a><a href="/contact">Contact</a><a href="/about">About</a>
    <a href="/login">Log in</a><a href="/register">Create account</a>
  </div>
</nav>
<main id="main">${body}</main>
<footer class="ws-footer">
  <div class="container">
    <div class="ws-footer-grid">
      <div>
        <a class="ws-brand" href="/"><img class="ws-logo" src="/assets/brand/logo-dark.svg" alt="Active Digital Hub" height="40" width="176"></a>
        <p class="muted mt-2">A prepaid reseller panel for social-media services, Nigerian VTU, virtual numbers, identity checks, gift cards and a platform-owned marketplace.</p>
        ${socialRow}
      </div>
      <div><h2>Platform</h2><ul>
        <li><a href="/services">Services</a></li><li><a href="/pricing">Pricing</a></li>
        <li><a href="/faq">FAQ</a></li><li><a href="/api/docs">API documentation</a></li></ul></div>
      <div><h2>Company</h2><ul>
        <li><a href="/about">About</a></li><li><a href="/blog">Blog</a></li>
        <li><a href="/contact">Contact</a></li></ul></div>
      <div><h2>Support</h2><ul>
        <li><a href="/faq">Help centre</a></li><li><a href="/dashboard/tickets">Support tickets</a></li>
        <li><a href="/contact">Contact support</a></li></ul></div>
      <div><h2>Legal</h2><ul>
        <li><a href="/terms">Terms of service</a></li><li><a href="/privacy">Privacy policy</a></li>
        <li><a href="/refund-policy">Refund policy</a></li><li><a href="/acceptable-use">Acceptable use</a></li></ul></div>
    </div>
    <div class="ws-footer-meta">
      <div>© ${new Date().getFullYear()} Active Digital Hub. Wallet balances are for spending on this platform only.</div>
      <div><a href="/login">Customer login</a> · <a href="/admin/login">Staff login</a></div>
    </div>
  </div>
</footer>
<button type="button" class="ws-assistant-launch" id="ws-assistant-launch" aria-controls="ws-assistant" aria-expanded="false">
  <img src="/assets/images/ai/avatar.jpg" alt="" width="28" height="28" class="ws-avatar" style="width:28px;height:28px"> Assistant
</button>
<section class="ws-assistant" id="ws-assistant" hidden data-endpoint="/assistant/chat" aria-label="Site assistant">
  <header class="ws-assistant-head">
    <div class="row" style="gap:.7rem">
      <img src="/assets/images/ai/avatar.jpg" alt="" width="40" height="40" class="ws-avatar">
      <div><h2>Site assistant</h2><p class="muted" style="margin:0;font-size:.8rem">Built-in knowledge · no external AI API</p></div>
    </div>
    <button type="button" class="btn btn-ghost btn-sm" id="ws-assistant-close">Close</button>
  </header>
  <div class="ws-assistant-log" id="ws-assistant-log">
    <div class="ws-bubble ws-bubble-assistant">I am Active Digital Hub’s on-site assistant. I answer from the panel’s built-in knowledge. I am not a cloud generative AI model and I cannot place orders.

Ask about services, pricing, accounts or where to find a page.</div>
  </div>
  <div class="ws-suggest" id="ws-assistant-suggest">
    <button type="button" data-suggest="What services can I order?">What services can I order?</button>
    <button type="button" data-suggest="How does pricing work?">How does pricing work?</button>
    <button type="button" data-suggest="How do I create an account?">How do I create an account?</button>
    <button type="button" data-suggest="How do I add funds?">How do I add funds?</button>
  </div>
  <div class="ws-assistant-status" id="ws-assistant-status" aria-live="polite"></div>
  <form class="ws-assistant-form" id="ws-assistant-form">
    <label class="sr-only" for="ws-assistant-input">Your question</label>
    <input class="input" id="ws-assistant-input" maxlength="1000" placeholder="Ask about the panel…">
    <button class="btn btn-primary" type="submit" id="ws-assistant-send">Send</button>
  </form>
</section>
<script src="/assets/js/app.js"></script>
${extra}
</body></html>`;
}

const pages = {
  '/': () => layout('Prepaid SMM, VTU and digital-goods panel',
    'Active Digital Hub is a prepaid reseller platform for social-media services, Nigerian VTU, virtual numbers, identity checks, gift cards and a platform marketplace.',
    `<section class="ws-page-hero">
      <div class="container" style="max-width:1180px">
        <div class="ws-hero-split">
          <div>
        <span class="badge badge-brand">Prepaid reseller panel</span>
        <h1 class="mt-4">Grow your social presence<br><span class="gradient-text">with Active Digital Hub</span></h1>
        <p class="ws-lede">One platform for SMM, VTU and digital goods — automated fulfilment when providers are connected, and a wallet ledger you can audit.</p>
        <div class="row" style="margin-top:1.5rem">
          <a class="btn btn-primary btn-lg" href="/register">Start ordering →</a>
          <a class="btn btn-secondary btn-lg" href="/services">View services</a>
        </div>
          </div>
          <div class="ws-hero-media">
            <img src="/assets/images/home/hero.jpg" alt="Abstract commerce operations hub of glass panels and connected services." width="960" height="720">
          </div>
        </div>
        <div class="grid grid-4 mt-6">
          <div class="card"><strong>Wallet</strong><p class="muted">Prepaid spend only</p></div>
          <div class="card"><strong>Ledger</strong><p class="muted">Double-entry credits</p></div>
          <div class="card"><strong>API</strong><p class="muted">Reseller keys &amp; scopes</p></div>
          <div class="card"><strong>Staff</strong><p class="muted">RBAC back office</p></div>
        </div>
      </div>
    </section>
    <section class="ws-section-sm"><div class="container">
      <h2 class="text-center">How it works</h2>
      <div class="grid grid-4 mt-4">
        <article class="card"><div class="badge badge-brand">01</div><h3 class="card-title">Create an account</h3><p class="muted">Username, email, password. A wallet is created automatically.</p></article>
        <article class="card"><div class="badge badge-brand">02</div><h3 class="card-title">Add funds</h3><p class="muted">Deposit through an enabled method. No customer withdrawals.</p></article>
        <article class="card"><div class="badge badge-brand">03</div><h3 class="card-title">Place an order</h3><p class="muted">Rates freeze at checkout. The ledger records the debit.</p></article>
        <article class="card"><div class="badge badge-brand">04</div><h3 class="card-title">Track and follow up</h3><p class="muted">Refill, cancel or open a ticket when the service allows it.</p></article>
      </div>
    </div></section>
    <section class="ws-section-sm" style="background:var(--slate-50)"><div class="container">
      <h2 class="text-center">Built for resellers, agencies and creators</h2>
      <div class="grid grid-3 mt-4">
        <article class="card"><h3 class="card-title">Agencies</h3><p>One prepaid balance for many orders, with refill and ticket history.</p></article>
        <article class="card"><h3 class="card-title">Resellers</h3><p>The same order engine behind API keys, scopes and IP allowlists.</p></article>
        <article class="card"><h3 class="card-title">Creators</h3><p>Pick a service, freeze the rate, pay from the wallet.</p></article>
      </div>
      <div class="text-center mt-6"><a class="btn btn-primary" href="/register">Ready to scale? Create your account</a></div>
    </div></section>`),

  '/services': () => layout('Services', 'Social-media services plus VTU, virtual numbers, identity checks, gift cards and a platform marketplace.',
    `<section class="ws-page-hero"><div class="container" style="max-width:800px">
      <p class="ws-kicker">What you can buy</p>
      <h1>One wallet for SMM, bills and digital goods</h1>
      <p class="ws-lede">For creators, agencies and resellers who want prepaid checkout and a staff-run back office — not invented volume numbers.</p>
      <div class="row mt-4"><a class="btn btn-primary" href="/register">Create an account</a><a class="btn btn-secondary" href="#catalogue">Jump to catalogue</a></div>
    </div></section>
    <section class="ws-section-sm"><div class="container">
      <h2>Product areas</h2>
      <div class="grid grid-3 mt-4">
        ${[['Social media services','Creators, agencies and resellers','Browse the live SMM catalogue. Rates freeze at checkout.','/services','/assets/images/services/smm.jpg'],
           ['VTU and bills','Nigerian airtime, data and bills','Airtime, data, cable, electricity and exam pins from the wallet.','/login','/assets/images/services/vtu.jpg'],
           ['Virtual numbers','Temporary SMS / OTP','Rent a number after a provider is connected and priced.','/login','/assets/images/services/numbers.jpg'],
           ['Identity verification','NIN and BVN lookups','Hidden until staff set a real vendor price.','/login','/assets/images/services/identity.jpg'],
           ['Gift cards','Digital brands','Encrypted codes, revealed only to authorised viewers.','/login','/assets/images/services/giftcards.jpg'],
           ['Marketplace','Platform-owned listings','The platform is the only seller.','/login','/assets/images/services/marketplace.jpg']].map(([n,a,s,h,img]) =>
          `<article class="card" style="padding-top:0;overflow:hidden"><img class="ws-visual-card" src="${img}" alt="${n}" width="640" height="400" loading="lazy"><h3 class="card-title">${n}</h3><p class="hint">${a}</p><p>${s}</p><a class="btn btn-secondary btn-sm" href="${h}">Open</a></article>`).join('')}
      </div>
    </div></section>
    <section class="py-10" id="catalogue"><div class="container">
      <header class="text-center mb-8"><h2>SMM catalogue</h2><p class="muted">Live rows appear when the operator publishes services. This preview shows the empty state the PHP catalogue uses when the database has none.</p></header>
      <div class="empty-state card"><h3>No services match your filters.</h3><p>Publish services from Admin → SMM services, or clear filters.</p><a class="btn btn-secondary" href="/services">Clear filters</a></div>
    </div></section>`),

  '/pricing': () => layout('Pricing', 'Prepaid wallet pricing. No invented monthly plans.',
    `<section class="ws-page-hero"><div class="container" style="max-width:1100px">
      <div class="ws-hero-split"><div>
      <p class="ws-kicker">Pricing</p><h1>Prepaid wallet. Published rates. No fake plans.</h1>
      <p class="ws-lede">Active Digital Hub does not sell a public monthly subscription. You add funds and pay the rate shown on each service.</p>
      </div><div class="ws-hero-media"><img src="/assets/images/services/marketplace.jpg" alt="Curated digital storefront representing prepaid catalogue pricing." width="800" height="600"></div></div>
    </div></section>
    <section class="ws-section-sm"><div class="container"><div class="grid grid-3">
      <article class="card ws-plan"><span class="badge badge-success">Available</span><h2 class="card-title mt-2">Pay as you go</h2>
        <p class="muted">Individual customers and new resellers</p>
        <p style="font-size:1.5rem;font-family:var(--font-display)">Service rates</p>
        <p>Free account. Default retail group. Optional API key. No monthly platform fee.</p>
        <a class="btn btn-primary btn-block" href="/register">Create an account</a></article>
      <article class="card ws-plan"><span class="badge badge-brand">Contact sales</span><h2 class="card-title mt-2">Volume groups</h2>
        <p class="muted">Agencies and active resellers</p>
        <p style="font-size:1.5rem;font-family:var(--font-display)">Custom rates</p>
        <p>Silver, Gold and Reseller groups are assigned by staff — not self-serve checkout plans.</p>
        <a class="btn btn-secondary btn-block" href="/contact">Contact sales</a></article>
      <article class="card ws-plan"><span class="badge badge-brand">Contact sales</span><h2 class="card-title mt-2">Custom / operator</h2>
        <p class="muted">Tailored catalogue or process</p>
        <p style="font-size:1.5rem;font-family:var(--font-display)">Contact sales</p>
        <p>Nothing here is a public price commitment.</p>
        <a class="btn btn-secondary btn-block" href="/contact">Contact sales</a></article>
    </div></div></section>`),

  '/faq': () => layout('FAQ', 'Answers about accounts, billing, services and the assistant.',
    `<section class="ws-page-hero"><div class="container" style="max-width:1100px">
      <div class="ws-hero-split"><div>
      <p class="ws-kicker">Help</p><h1>Frequently asked questions</h1>
      <input class="input mt-4" id="ws-faq-search" type="search" placeholder="Search questions…" style="max-width:28rem">
      </div><div class="ws-hero-media"><img src="/assets/images/faq/hero.jpg" alt="Knowledge space of glowing glass cards." width="800" height="600"></div></div>
    </div></section>
    <section class="ws-section-sm"><div class="container" style="max-width:800px">
      ${faqs.map(([c,q,a]) => `<section data-faq-category><h2 style="font-size:1.1rem">${esc(c)}</h2>
        <details class="accordion-item" data-faq-item data-faq-text="${esc((q+' '+a+' '+c).toLowerCase())}">
          <summary>${esc(q)}</summary><div class="accordion-body">${esc(a)}</div></details></section>`).join('')}
      <div class="card text-center mt-6"><p class="muted">Still have a question?</p>
        <a class="btn btn-primary" href="/contact">Contact support</a></div>
    </div></section>`),

  '/about': () => layout('About', 'What Active Digital Hub is and who it is for.',
    `<section class="ws-page-hero"><div class="container" style="max-width:760px">
      <p class="ws-kicker">About</p><h1>A panel for selling digital fulfilment — not a marketing slogan</h1>
      <p class="ws-lede">Active Digital Hub is the software that runs this site. It is operated by whoever deployed this instance.</p>
    </div></section>
    <section class="ws-section-sm"><div class="container ws-prose">
      <p>One customer account can buy social-media services, Nigerian VTU, virtual numbers, identity lookups, gift cards and platform-owned marketplace listings — when those products are connected and priced.</p>
      <p>This page does not publish customer counts, star ratings or uptime percentages.</p>
      <a class="btn btn-primary" href="/contact">Contact</a>
    </div></section>`),

  '/contact': () => layout('Contact', 'Contact Active Digital Hub support.',
    `<section class="ws-page-hero"><div class="container" style="max-width:720px">
      <p class="ws-kicker">Support</p><h1>Contact us</h1>
      <p class="ws-lede">A person answers. The on-site assistant cannot open a ticket for you.</p>
    </div></section>
    <section class="ws-section-sm"><div class="container" style="max-width:640px">
      <div class="card"><form class="stack" method="post" action="/contact">
        <label class="label" for="n">Your name</label><input class="input" id="n" name="name" required>
        <label class="label" for="e">Email</label><input class="input" id="e" name="email" type="email" required>
        <label class="label" for="s">Subject</label><input class="input" id="s" name="subject" required>
        <label class="label" for="m">Message</label><textarea class="textarea" id="m" name="message" required></textarea>
        <button class="btn btn-primary" type="submit">Send message</button>
      </form></div>
    </div></section>`),

  '/terms': () => legal('Terms of Service', ['Acceptance of terms','Eligibility','Account registration','Account security','Payments and billing','On-site assistant','Disclaimers','Governing law (counsel review)']),
  '/privacy': () => legal('Privacy Policy', ['Information collected','How information is used','Cookies','Security measures','Retention','Your rights','Children']),
  '/refund-policy': () => legal('Refund Policy', ['Wallet, not cash-out','Partial SMM deliveries','Failed automated purchases','Deposits']),
  '/acceptable-use': () => legal('Acceptable Use', ['You may','You may not','Enforcement']),
  '/blog': () => layout('Blog', 'Guides and product updates.',
    `<section class="ws-page-hero"><div class="container text-center"><h1>Blog</h1><p class="muted">Guides, product updates, and reseller tips.</p></div></section>
     <section class="ws-section-sm"><div class="container"><div class="empty-state card"><h2>No posts published yet</h2><p>Staff publish from the admin content tools.</p><a class="btn btn-secondary" href="/faq">Read the FAQ</a></div></div></section>`),
  '/login': () => auth('Log in to your account',
    `<form class="stack" method="post" action="/login">
      <label class="label" for="identifier">Email or username</label>
      <input class="input" id="identifier" name="identifier" required>
      <label class="label" for="password">Password</label>
      <div class="ws-password"><input class="input" id="password" name="password" type="password" required>
      <button type="button" class="ws-password-toggle" data-password-toggle="password">Show</button></div>
      <label class="checkbox"><input type="checkbox" name="remember" value="1"> Remember me on this device</label>
      <button class="btn btn-primary btn-block" type="submit">Log in</button>
    </form>
    <p class="ws-auth-aside">Don't have an account? <a href="/register">Create one</a> · <a href="/forgot-password">Forgot password?</a></p>`),
  '/register': () => auth('Create your account',
    `<form class="stack" method="post" action="/register">
      <label class="label" for="username">Username</label><input class="input" id="username" required minlength="3">
      <label class="label" for="email">Email</label><input class="input" id="email" type="email" required>
      <label class="label" for="password">Password</label>
      <div class="ws-password"><input class="input" id="password" type="password" required minlength="8">
      <button type="button" class="ws-password-toggle" data-password-toggle="password">Show</button></div>
      <label class="checkbox"><input type="checkbox" name="terms" required> I agree to the <a href="/terms">Terms</a> and <a href="/privacy">Privacy Policy</a>.</label>
      <button class="btn btn-primary btn-block" type="submit">Create account</button>
    </form>`),
  '/forgot-password': () => auth('Reset your password',
    `<p class="muted">If an account matches we email a reset link. The confirmation is the same either way.</p>
     <form class="stack" method="post" action="/forgot-password">
       <label class="label" for="identifier">Email or username</label>
       <input class="input" id="identifier" required>
       <button class="btn btn-primary btn-block" type="submit">Send reset link</button>
     </form>`),
  '/admin/login': () => auth('Administrator sign-in',
    `<p class="badge badge-brand">Staff only</p>
     <p class="muted">Customer passwords are refused. The first administrator is created by the official database import or seed core — never from a password in git.</p>
     <form class="stack" method="post" action="/admin/login">
       <label class="label" for="identifier">Staff email or username</label>
       <input class="input" id="identifier" required>
       <label class="label" for="password">Password</label>
       <div class="ws-password"><input class="input" id="password" type="password" required>
       <button type="button" class="ws-password-toggle" data-password-toggle="password">Show</button></div>
       <button class="btn btn-primary btn-block" type="submit">Sign in to admin</button>
     </form>`),
  '/assistant': () => layout('Site assistant', 'On-site assistant. No external AI API.',
    `<section class="ws-page-hero"><div class="container" style="max-width:720px">
      <p class="ws-kicker">On-site assistant</p><h1>Ask the panel, not a cloud model</h1>
      <p class="ws-lede">Use the Assistant button. This page documents the same engine.</p>
    </div></section>`),
  '/api/docs': () => layout('Reseller API', 'HTTP API for services, orders and balance.',
    `<section class="ws-page-hero"><div class="container"><h1>Reseller API</h1>
      <p class="ws-lede">Authenticate with an API key from your dashboard. Interactive docs ship with the PHP app at /api/docs.</p>
      <pre style="background:#0f172a;color:#e2e8f0;padding:1rem;border-radius:.75rem">POST /api/v1/orders
X-Api-Key: wind_…</pre>
    </div></section>`),
};

function legal(title, sections) {
  const copy = {
    'Terms of Service': [
      ['Acceptance of terms', 'By creating an account, signing in, calling the API or otherwise using the service, you agree to these terms, the Privacy Policy, the Refund Policy and the Acceptable Use Policy.'],
      ['Eligibility', 'You must be able to form a binding contract. The service is not directed at children under 16, or the higher age required in your country.'],
      ['Account registration', 'You provide a unique username, a working email and a password of at least eight characters. A wallet in the panel base currency is created with the account.'],
      ['Account security', 'Keep passwords and API keys confidential. Enable TOTP if you handle significant balance. Logout is POST-only with a CSRF token.'],
      ['Payments and billing', 'The service is prepaid. The wallet is a spending balance: the software does not offer customer withdrawals of leftover deposits.'],
      ['On-site assistant', 'The embedded assistant is a local operational engine. It is not a generative cloud model and cannot place orders or move funds.'],
      ['Disclaimers', 'The service is provided as is. Social-platform outcomes, SMS delivery and vendor matches are outside the operator’s sole control.'],
      ['Governing law', 'Requires review by the operator’s legal counsel. Until a jurisdiction is designated, notices go to the configured support email.'],
    ],
    'Privacy Policy': [
      ['Information collected', 'Account data (username, email, hashed password, role), session and CSRF cookies, login-attempt rows, wallet and order records, and optional MFA secrets stored encrypted.'],
      ['How information is used', 'To authenticate you, take payment, fulfil orders through connected providers, answer tickets, enforce rate limits, and answer assistant questions locally.'],
      ['Cookies', 'A first-party session cookie is required to stay signed in and to bind CSRF. Optional remember-me lengthens that cookie. No advertising cookies ship in this codebase.'],
      ['Security measures', 'Hashed passwords, dummy verify on unknown identifiers, encrypted secrets, TLS on outbound provider calls, RBAC on admin routes.'],
      ['Retention', 'Ledger rows are kept for accounting. Identity-lookup payloads follow the operator’s retention setting (default 30 days).'],
      ['Your rights', 'Depending on where you live you may access, correct or delete personal data via a support ticket. Some ledger rows cannot be erased without breaking the books.'],
      ['Children', 'The service is not directed at children under 16. Contact us to delete an account opened by a child.'],
    ],
    'Refund Policy': [
      ['Wallet, not cash-out', 'Refunds return value to the prepaid wallet through the ledger. They are not bank payouts.'],
      ['Partial SMM deliveries', 'Undelivered quantity can be credited when partial refunds are enabled. Completed work is not cancelled.'],
      ['Failed automated purchases', 'VTU, numbers, identity and gift-card engines credit the wallet when they mark a job failed or abandoned. Revealed codes are completed products.'],
      ['Deposits', 'A verified deposit is wallet balance. Chargebacks may lead to account suspension.'],
    ],
    'Acceptable Use': [
      ['You may', 'Order catalogue services for properties you are authorised to act on, pay bills you are allowed to fund, and call the API with a key you created.'],
      ['You may not', 'Attack the panel, bypass CSRF or RBAC, use stolen payment instruments, or resell identity-lookup payloads in breach of vendor or KYC rules.'],
      ['Enforcement', 'The operator may refuse an order, freeze a wallet, revoke keys or close an account. Appeals go through the contact form.'],
    ],
  };
  const blocks = copy[title] || sections.map((s) => [s, 'See the PHP view for the full clause.']);
  return layout(title, title + ' for this Active Digital Hub instance.',
    `<section class="ws-page-hero"><div class="container"><p class="ws-kicker">Legal</p><h1>${esc(title)}</h1>
      <p class="hint">Effective 19 August 2026 · Last updated 22 August 2026</p></div></section>
     <section class="ws-section-sm"><div class="container ws-prose">
       <div class="ws-callout">Operator entity, address and jurisdiction are placeholders for the party that deployed this instance and for counsel review.</div>
       ${blocks.map((pair,i) => `<h2>${i+1}. ${esc(pair[0])}</h2><p>${esc(pair[1])}</p>`).join('')}
       <p><a href="/contact">Contact</a></p>
     </div></section>`);
}

function auth(title, inner) {
  return layout(title, title,
    `<section class="ws-section-sm"><div class="container" style="max-width:28rem">
      <div class="card"><h1 style="font-size:1.6rem">${esc(title)}</h1>${inner}</div>
    </div></section>`);
}

function norm(s) {
  return String(s || '').toLowerCase().replace(/[''`]/g, '').replace(/[^a-z0-9\s+\-\/]/g, ' ').replace(/\s+/g, ' ').trim();
}

function assistantReply(message) {
  const q = norm(message);
  if (!q) return { success: false, error: { message: 'Write a question and I will answer from the site knowledge.' } };

  if (/place an order|buy now|pay now|fund my wallet|delete my account/.test(q)) {
    return ok('I cannot do that from this chat. I only explain the site — I will not pretend an account, payment or order succeeded.',
      [{label:'Contact', href:'/contact'}]);
  }
  if (q === 'night' || /good night|have a good night|goodbye|^bye$/.test(q)) {
    return ok('Good night. I will be here when you come back if you need help with the site.');
  }
  if (/good morning|^morning$/.test(q)) return ok('Good morning. I can help with Active Digital Hub — services, pricing, accounts, or finding a page.');
  if (/good afternoon|^afternoon$/.test(q)) return ok('Good afternoon. Ask me about this panel’s services, pricing, or how to create an account.');
  if (/good evening|^evening$/.test(q)) return ok('Good evening. I am here if you want a walkthrough of services, pricing, or signing in.');
  if (/good day/.test(q)) return ok('Good day. I can explain what this site does and point you to the right page.');
  if (/^(hi|hey|hello|howdy|greetings|hi there|hey there|hello there|hello ai|hey assistant|hello assistant)$/.test(q)) {
    return ok('Hello. I can explain what Active Digital Hub sells, how the wallet works, and where to sign up or log in.',
      [{label:'Sign up', href:'/register'},{label:'View Services', href:'/services'}]);
  }
  if (/^(ok|okay|great|perfect|good|nice|awesome|alright|got it|cool|i understand|thats fine|that is fine)$/.test(q)) {
    return ok('Sounds good. I am here if you need anything.');
  }
  if (/thank/.test(q) && q.split(' ').length <= 5) {
    return ok("You're welcome. If you need anything else about Active Digital Hub, I am here to help.");
  }
  if (/youre welcome|you are welcome|no problem/.test(q)) return ok('Glad that helped.');
  if (/how are you|what is going on|whats going on|how are things/.test(q)) {
    return ok('I am the local site assistant — running fine, and ready to help. I can explain this website, or we can pick a page to open.');
  }
  if (/what can you do|can you help|i need help|i have a question|what can you help/.test(q)) {
    return ok('I can help with Active Digital Hub services, pricing, account registration, login, password reset, FAQs, privacy, terms, and navigating the website. I cannot create an account or place an order from this chat.',
      [{label:'View Services', href:'/services'},{label:'Sign up', href:'/register'}]);
  }
  if (/this website|this site|what can i do here|what is marvy|tell me about this/.test(q)) {
    return ok('Active Digital Hub is a prepaid reseller panel for social-media services, Nigerian VTU, virtual numbers, identity lookups, gift cards and a platform marketplace. You add funds to a wallet and spend that balance here.',
      [{label:'View Services', href:'/services'},{label:'View Pricing', href:'/pricing'}]);
  }
  if (/forgot|reset my password|cant log in|cannot log in|login isnt working/.test(q)) {
    return ok('Use Forgot password and enter your email or username. If an account matches, a reset link is emailed. I cannot reset the password for you.',
      [{label:'Forgot password', href:'/forgot-password'}]);
  }
  if (/sign up|signup|register|create an account|create account|i want to join|need an account/.test(q)) {
    return ok('To create your Active Digital Hub account, open the registration page, choose a username and a password of at least 8 characters, and accept the Terms. I cannot register you from this chat.',
      [{label:'Sign up', href:'/register'}]);
  }
  if (/log in|login|sign me in|sign in|access my account/.test(q)) {
    return ok('Customer and staff sign-in is on the login page. I cannot sign you in from this chat.',
      [{label:'Log in', href:'/login'}]);
  }
  if (/privacy/.test(q)) return ok('The Privacy Policy describes account data, hashed passwords, session cookies and local assistant processing. We do not sell personal data.', [{label:'Privacy Policy', href:'/privacy'}]);
  if (/\bterms\b/.test(q)) return ok('The Terms of Service cover accounts, prepaid wallet billing and acceptable use. Open the Terms page for the full text.', [{label:'Terms of Service', href:'/terms'}]);
  if (/faq|frequently asked/.test(q)) return ok('The FAQ covers accounts, billing, services and this assistant. Ask a specific question here or open the FAQ page.', [{label:'View FAQ', href:'/faq'}]);

  if (/pric|cost|how much|plan|subscription/.test(q)) {
    return ok('There is no public monthly subscription. You add funds to a prepaid wallet and pay the published rate. Volume groups are assigned by staff. Default deposit minimum is ₦500.',
      [{label:'View Pricing', href:'/pricing'}]);
  }
  if (/withdraw|wallet|add fund/.test(q)) {
    return ok('Your wallet is a spending balance. Add funds from the dashboard. There are no customer withdrawals.',
      [{label:'View Pricing', href:'/pricing'}]);
  }
  if (/admin|staff/.test(q)) {
    return ok('Staff sign in at /admin/login. Customer accounts are refused. I will not share credentials.',
      [{label:'Staff sign-in', href:'/admin/login'}]);
  }
  if (/vtu|airtime|service|what can i buy|what do you sell/.test(q)) {
    return ok('Active Digital Hub sells SMM services, Nigerian VTU, virtual numbers, identity lookups, gift cards, a platform marketplace and a reseller API — when the operator has enabled and priced them.',
      [{label:'View Services', href:'/services'}]);
  }
  const hit = faqs.find(([, question]) => q.includes(norm(question).slice(0, 12)));
  if (hit) return ok(hit[2], [{label:'View FAQ', href:'/faq'}]);
  return ok('I am not sure I understood that. I can help with Active Digital Hub services, pricing, account registration, login, FAQs, privacy, terms, and navigating the website. What would you like to know?',
    [{label:'View FAQ', href:'/faq'},{label:'Contact', href:'/contact'}]);
}

function ok(reply, links) {
  return { success: true, data: { reply, intent: 'preview', links, suggestions: ['What services can I order?','How does pricing work?','How do I contact support?'] } };
}

function serveFile(req, res, file) {
  const abs = path.normalize(file);
  if (!abs.startsWith(path.join(ROOT, 'assets'))) { res.writeHead(403); res.end(); return; }
  fs.readFile(abs, (err, data) => {
    if (err) { res.writeHead(404); res.end('not found'); return; }
    const ext = path.extname(abs);
    const types = {'.css':'text/css','.js':'application/javascript','.png':'image/png','.jpg':'image/jpeg','.svg':'image/svg+xml','.ico':'image/x-icon','.webmanifest':'application/manifest+json'};
    res.writeHead(200, {'Content-Type': types[ext] || 'application/octet-stream'});
    res.end(data);
  });
}

const server = http.createServer((req, res) => {
  const parsed = url.parse(req.url, true);
  let p = decodeURIComponent(parsed.pathname || '/');
  if (p.length > 1 && p.endsWith('/')) p = p.slice(0, -1);

  // This server has no authentication backend. Never pretend to accept a
  // sign-in or registration POST, or display a form that cannot work.
  if (['/login', '/register', '/admin/login', '/setup', '/setup/admin', '/forgot-password'].includes(p)) {
    res.writeHead(503, {'Content-Type':'text/html; charset=utf-8', 'Cache-Control':'no-store'});
    res.end(layout('Authentication unavailable in visual preview', 'Visual preview only',
      `<section class="ws-section"><div class="container"><h1>Authentication is unavailable in this visual preview</h1><p>Run the PHP application with its configured database to register, sign in, or set up an administrator.</p></div></section>`));
    return;
  }

  if (p === '/csrf') {
    res.writeHead(200, {'Content-Type':'application/json'});
    res.end(JSON.stringify({success:true,data:{name:'csrf_marvy',hash:'preview',header:'X-CSRF-TOKEN',expiresIn:7200}}));
    return;
  }
  if (p === '/assistant/chat' && req.method === 'POST') {
    let body = '';
    req.on('data', (c) => { body += c; if (body.length > 20000) req.destroy(); });
    req.on('end', () => {
      let msg = '';
      try { msg = JSON.parse(body).message || ''; } catch (e) { msg = ''; }
      res.writeHead(200, {'Content-Type':'application/json'});
      res.end(JSON.stringify(assistantReply(msg)));
    });
    return;
  }
  if (p.startsWith('/assets/')) {
    serveFile(req, res, path.join(ROOT, p));
    return;
  }
  if (pages[p]) {
    res.writeHead(200, {'Content-Type':'text/html; charset=utf-8'});
    res.end(pages[p]());
    return;
  }
  res.writeHead(404, {'Content-Type':'text/html; charset=utf-8'});
  res.end(layout('Page not found', 'Not found',
    `<section class="ws-page-hero"><div class="container text-center">
      <p class="ws-kicker">404</p><h1>That page is not here</h1>
      <a class="btn btn-primary" href="/">Go to homepage</a>
    </div></section>`));
});

server.listen(PORT, HOST, () => {
  console.log('Active Digital Hub public preview on http://' + HOST + ':' + PORT);
});
