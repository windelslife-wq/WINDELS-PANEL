<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<h1 class="text-2xl font-bold tracking-tight">Create your account</h1>
<p class="mt-1 text-sm text-slate-500">A wallet is created with the account. No payment is required to register.</p>

<?=form_open('register', array('class' => 'mt-6 space-y-4', 'autocomplete' => 'on', 'data-auth-form' => ''))?>
  <?php if (!empty($referral)): ?>
    <input type="hidden" name="ref" value="<?=htmlspecialchars($referral)?>">
  <?php endif; ?>

  <div class="grid grid-cols-2 gap-3">
    <div>
      <label for="first_name" class="label">First name</label>
      <input id="first_name" name="first_name" type="text" class="input" autocomplete="given-name" maxlength="100"
             value="<?=htmlspecialchars(set_value('first_name'))?>">
      <?=form_error('first_name', '<p class="form-error">', '</p>')?>
    </div>
    <div>
      <label for="last_name" class="label">Last name</label>
      <input id="last_name" name="last_name" type="text" class="input" autocomplete="family-name" maxlength="100"
             value="<?=htmlspecialchars(set_value('last_name'))?>">
      <?=form_error('last_name', '<p class="form-error">', '</p>')?>
    </div>
  </div>

  <div>
    <label for="username" class="label">Username</label>
    <input id="username" name="username" type="text" class="input" autocomplete="username" required minlength="3" maxlength="64" pattern="[A-Za-z0-9]+" aria-describedby="username-hint"
           value="<?=htmlspecialchars(set_value('username'))?>">
    <p id="username-hint" class="hint">Use 3–64 letters and numbers only.</p>
    <?=form_error('username', '<p class="form-error">', '</p>')?>
  </div>

  <div>
    <label for="email" class="label">Email</label>
    <input id="email" name="email" type="email" class="input" autocomplete="email" required maxlength="255"
           value="<?=htmlspecialchars(set_value('email'))?>">
    <?=form_error('email', '<p class="form-error">', '</p>')?>
  </div>

  <div>
    <label for="password" class="label">Password</label>
    <div class="ws-password">
      <input id="password" name="password" type="password" class="input" autocomplete="new-password" required minlength="8">
      <button type="button" class="ws-password-toggle" data-password-toggle="password" aria-label="Show password" aria-pressed="false"><svg class="ws-eye" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/><path class="ws-eye-slash" d="M3 3l18 18"/></svg><span class="ws-password-label">Show</span></button>
    </div>
    <p class="hint">At least 8 characters.</p>
    <?=form_error('password', '<p class="form-error">', '</p>')?>
  </div>

  <div>
    <label for="password_confirm" class="label">Confirm password</label>
    <div class="ws-password">
      <input id="password_confirm" name="password_confirm" type="password" class="input" autocomplete="new-password" required minlength="8">
      <button type="button" class="ws-password-toggle" data-password-toggle="password_confirm" aria-label="Show password confirmation" aria-pressed="false"><svg class="ws-eye" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/><path class="ws-eye-slash" d="M3 3l18 18"/></svg><span class="ws-password-label">Show</span></button>
    </div>
    <?=form_error('password_confirm', '<p class="form-error">', '</p>')?>
  </div>

  <label class="checkbox">
    <input type="checkbox" name="terms" value="1" required data-validation-label="Terms of Service and Privacy Policy" <?=set_checkbox('terms', '1')?>>
    <span>I agree to the <a href="<?=site_url('terms')?>" target="_blank" rel="noopener">Terms of Service</a> and <a href="<?=site_url('privacy')?>" target="_blank" rel="noopener">Privacy Policy</a>.</span>
  </label>
  <?=form_error('terms', '<p class="form-error">', '</p>')?>

  <p class="alert alert-danger" data-auth-validation role="alert" hidden></p>
  <button type="submit" class="btn btn-primary btn-block" data-loading-text="Creating account…">Create account</button>
<?=form_close()?>

<p class="ws-auth-aside">
  Already have an account? <a href="<?=site_url('login')?>">Log in</a>
</p>
