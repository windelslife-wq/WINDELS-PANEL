<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<h1 class="text-2xl font-bold tracking-tight">Set a new password</h1>
<p class="mt-1 text-sm text-slate-500">Choose a new password of at least 8 characters. This link works once.</p>

<?=form_open('reset-password/'.($token ?? ''), array('class' => 'mt-6 space-y-4'))?>
  <div>
    <label for="password" class="label">New password</label>
    <div class="ws-password">
      <input id="password" name="password" type="password" class="input" autocomplete="new-password" required minlength="8" autofocus>
      <button type="button" class="ws-password-toggle" data-password-toggle="password" aria-label="Show password" aria-pressed="false"><svg class="ws-eye" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/><path class="ws-eye-slash" d="M3 3l18 18"/></svg><span class="ws-password-label">Show</span></button>
    </div>
    <?=form_error('password', '<p class="form-error">', '</p>')?>
  </div>
  <div>
    <label for="password_confirm" class="label">Confirm new password</label>
    <div class="ws-password">
      <input id="password_confirm" name="password_confirm" type="password" class="input" autocomplete="new-password" required minlength="8">
      <button type="button" class="ws-password-toggle" data-password-toggle="password_confirm" aria-label="Show password confirmation" aria-pressed="false"><svg class="ws-eye" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/><path class="ws-eye-slash" d="M3 3l18 18"/></svg><span class="ws-password-label">Show</span></button>
    </div>
  </div>
    <?=form_error('password_confirm', '<p class="form-error">', '</p>')?>
  <button type="submit" class="btn btn-primary btn-block">Reset password</button>
<?=form_close()?>
