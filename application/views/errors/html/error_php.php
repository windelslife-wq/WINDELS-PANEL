<?php defined('BASEPATH') OR exit('No direct script access allowed');
// Branded PHP-error page. Generic message in production; technical detail only
// outside production. Errors are always written to the log by CodeIgniter.
$heading = 'Something went wrong';
$is_prod = defined('ENVIRONMENT') && ENVIRONMENT === 'production';
$message = 'The request could not be completed. Please try again, or contact support if the problem persists.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo htmlspecialchars($heading); ?> · Active Digital Hub</title>
<link rel="icon" href="/assets/brand/favicon.svg" type="image/svg+xml">
<style>
*{box-sizing:border-box}
body{margin:0;font-family:Inter,ui-sans-serif,system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;color:#0f172a;background:radial-gradient(900px 280px at 12% -10%,#dbeafe,transparent 60%),radial-gradient(700px 240px at 92% 0,#eff6ff,transparent 55%),#fff;line-height:1.6;-webkit-font-smoothing:antialiased}
.wrap{max-width:40rem;margin:0 auto;padding:14vh 1.25rem 4rem;text-align:center}
.brand img{display:block;width:34px;height:34px;border-radius:10px}.brand{display:inline-flex;align-items:center;gap:.6rem;font-weight:700;letter-spacing:-.02em;margin-bottom:1.5rem;text-decoration:none;color:#0f172a}
.code{display:inline-block;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.8rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#1877f2;background:#eff6ff;border:1px solid #dbeafe;border-radius:999px;padding:.3rem .8rem;margin-bottom:1rem}
h1{font-family:Georgia,'Times New Roman',serif;font-weight:600;font-size:clamp(1.6rem,4vw,2.2rem);margin:0 0 .6rem;letter-spacing:-.01em}
p{color:#475569;margin:0 auto 1.5rem;max-width:30rem}
.actions{display:flex;gap:.6rem;justify-content:center;flex-wrap:wrap}
.btn{display:inline-block;padding:.65rem 1.15rem;border-radius:.75rem;font-weight:600;font-size:.925rem;text-decoration:none;border:1px solid transparent}
.btn-primary{background:#1877f2;color:#fff}
.btn-secondary{background:#fff;color:#0f172a;border-color:#cbd5e1}
pre{text-align:left;background:#0f172a;color:#e2e8f0;border-radius:.75rem;padding:1rem;overflow:auto;max-height:16rem;font-size:.78rem}
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
    <a class="brand" href="/">
      <img src="/assets/brand/logo-icon.svg" alt="" width="34" height="34">
      Active Digital Hub
    </a>
    <div><span class="code">500</span></div>
    <h1><?php echo htmlspecialchars($heading); ?></h1>
    <p><?php echo htmlspecialchars($message); ?></p>
    <?php if (!$is_prod && isset($filepath) && isset($line)): ?>
      <pre><?php echo htmlspecialchars($filepath.':'.$line); ?></pre>
    <?php endif; ?>
    <div class="actions">
      <a class="btn btn-primary" href="/">Go to homepage</a>
      <a class="btn btn-secondary" href="/contact">Contact support</a>
    </div>
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
