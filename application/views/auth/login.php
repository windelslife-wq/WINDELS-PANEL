<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<h1 class="text-2xl font-bold tracking-tight">Log in to your account</h1>
<p class="mt-1 text-sm text-slate-500">Enter your email, username, or six-digit user ID (see Profile → Account).</p>

<?=form_open('login', array('class' => 'mt-6 space-y-4', 'autocomplete' => 'on', 'data-auth-form' => ''))?>
  <div>
    <label for="identifier" class="label">Email, username or user ID</label>
    <input id="identifier" name="identifier" type="text" class="input" autocomplete="username" required autofocus
           value="<?=htmlspecialchars(set_value('identifier'))?>">
    <?=form_error('identifier', '<p class="form-error">', '</p>')?>
  </div>

  <div>
    <div class="flex items-center justify-between">
      <label for="password" class="label" style="margin-bottom:0">Password</label>
      <a href="<?=site_url('forgot-password')?>" class="text-sm">Forgot password?</a>
    </div>
    <div class="ws-password mt-1">
      <input id="password" name="password" type="password" class="input" autocomplete="current-password" required>
      <button type="button" class="ws-password-toggle" data-password-toggle="password" aria-label="Show password" aria-pressed="false"><svg class="ws-eye" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/><path class="ws-eye-slash" d="M3 3l18 18"/></svg><span class="ws-password-label">Show</span></button>
    </div>
    <?=form_error('password', '<p class="form-error">', '</p>')?>
  </div>

  <label class="checkbox">
    <input type="checkbox" name="remember" value="1" <?=set_checkbox('remember', '1')?>>
    Remember me on this device
  </label>

  <p class="alert alert-danger" data-auth-validation role="alert" hidden></p>
  <button type="submit" class="btn btn-primary btn-block" data-loading-text="Signing in…">Log in</button>
<?=form_close()?>

<p class="ws-auth-aside">
  Don't have an account?
  <a href="<?=site_url(!empty($referral) ? 'register?ref='.urlencode($referral) : 'register')?>">Create one</a>
</p>
