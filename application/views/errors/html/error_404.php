<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Page not found · Active Digital Hub</title>
<style>
body{margin:0;font-family:Inter,system-ui,sans-serif;color:#0f172a;background:#fff;line-height:1.6}
.wrap{max-width:36rem;margin:12vh auto;padding:0 1.25rem;text-align:center}
h1{font-family:Georgia,serif;font-size:2rem}
.brand{display:inline-flex;align-items:center;gap:.65rem;margin-bottom:1.25rem;color:#0f172a;font-weight:700;text-decoration:none}.brand img{width:42px;height:42px;border-radius:12px}
a{color:#1877f2}
.btn{display:inline-block;margin:.35rem;padding:.65rem 1rem;border-radius:.75rem;background:#1877f2;color:#fff;text-decoration:none;font-weight:600}
.btn-secondary{background:#fff;color:#0f172a;border:1px solid #cbd5e1}
.ws-err-nav{display:flex;gap:1rem;justify-content:center;flex-wrap:wrap;padding:1rem 1.25rem;border-bottom:1px solid #e5e7eb;font-size:.9rem}
.ws-err-nav a{color:#0f172a;text-decoration:none;font-weight:500}
.ws-err-nav a:hover{color:#1877f2;text-decoration:underline}
.ws-err-footer{border-top:1px solid #e5e7eb;padding:1rem 1.25rem;text-align:center;font-size:.85rem;color:#64748b}
.ws-err-footer a{color:#64748b}
</style>
</head>
<body>
  <nav class="ws-err-nav" aria-label="Primary">
    <a href="/">Home</a>
    <a href="/services">Services</a>
    <a href="/shop">Shop</a>
    <a href="/pricing">Pricing</a>
    <a href="/faq">FAQ</a>
    <a href="/contact">Contact</a>
    <a href="/login">Log in</a>
  </nav>
  <div class="wrap">
    <a class="brand" href="/"><img src="/assets/brand/logo-icon.svg" alt="" width="42" height="42">Active Digital Hub</a>
    <p>404</p>
    <h1><?php echo htmlspecialchars(isset($heading) ? $heading : 'Page not found'); ?></h1>
    <p><?php echo isset($message) ? $message : 'That address is not a page on Active Digital Hub.'; ?></p>
    <p>
      <a class="btn" href="/">Home</a>
      <a class="btn btn-secondary" href="/services">Services</a>
    </p>
  </div>
  <footer class="ws-err-footer">
    <p>&copy; <?php echo date('Y'); ?> Active Digital Hub ·
      <a href="/terms">Terms</a> ·
      <a href="/privacy">Privacy</a> ·
      <a href="/refund-policy">Refunds</a> ·
      <a href="/contact">Contact support</a>
    </p>
  </footer>
</body>
</html>
