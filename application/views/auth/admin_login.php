<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<p class="badge badge-brand">Staff only</p>
<h1 class="text-2xl font-bold tracking-tight mt-2">Administrator sign-in</h1>
<p class="mt-1 text-sm text-slate-500">This form authenticates against the same user table as customer login, then refuses any account that is not SUPER_ADMIN, ADMIN or STAFF. A customer password will not open the back office.</p>

<?=form_open('admin/login', array('class' => 'mt-6 space-y-4', 'autocomplete' => 'on'))?>
  <div>
    <label for="identifier" class="label">Staff email or username</label>
    <input id="identifier" name="identifier" type="text" class="input" autocomplete="username" required autofocus
           value="<?=htmlspecialchars(set_value('identifier'))?>">
    <?=form_error('identifier', '<p class="form-error">', '</p>')?>
  </div>
  <div>
    <label for="password" class="label">Password</label>
    <div class="ws-password">
      <input id="password" name="password" type="password" class="input" autocomplete="current-password" required>
      <button type="button" class="ws-password-toggle" data-password-toggle="password" aria-label="Show password" aria-pressed="false"><svg class="ws-eye" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/><path class="ws-eye-slash" d="M3 3l18 18"/></svg><span class="ws-password-label">Show</span></button>
    </div>
  </div>
  <label class="checkbox">
    <input type="checkbox" name="remember" value="1">
    Remember this staff device
  </label>
  <button type="submit" class="btn btn-primary btn-block" data-loading-text="Signing in…">Sign in to admin</button>
<?=form_close()?>

<p class="ws-auth-aside">
  Not staff? <a href="<?=site_url('login')?>">Customer login</a>
</p>

<p class="ws-auth-aside"><a href="<?=site_url('forgot-password')?>">Forgot password?</a> · <a href="<?=site_url('setup')?>">First administrator setup</a></p>
