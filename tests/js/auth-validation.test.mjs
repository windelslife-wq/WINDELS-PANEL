import { readFileSync } from 'node:fs';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';

const app = readFileSync('assets/js/app.js', 'utf8');
for (const name of ['login', 'register', 'first_admin']) {
  const source = readFileSync(`application/views/auth/${name}.php`, 'utf8');
  assert.match(source, /'data-auth-form'\s*=>/);
  assert.match(source, /data-auth-validation[^>]*role="alert"[^>]*hidden/);
}
const html = `<form data-auth-form>
  <label for="username">Username</label>
  <input id="username" name="username" required pattern="[A-Za-z0-9]+">
  <label>Agree to the terms <input type="checkbox" name="terms" required data-validation-label="Terms of Service and Privacy Policy"></label>
  <p data-auth-validation role="alert" hidden></p>
  <button type="submit" data-loading-text="Creating account…">Create account</button>
</form>`;
const dom = new JSDOM(html, { url: 'https://example.test/', runScripts: 'outside-only' });
const { window: w } = dom;
w.fetch = async () => ({ ok: true, json: async () => ({}) });
w.eval(app);
w.document.dispatchEvent(new w.Event('DOMContentLoaded'));
const form = w.document.querySelector('form');
const message = form.querySelector('[data-auth-validation]');
const username = form.elements.username;
const terms = form.elements.terms;
// Exercise the submit button itself, not just a synthetic submit event.
form.querySelector('button').click();
assert.equal(message.hidden, false);
assert.match(message.textContent, /^Username:/);
username.value = 'invalid_name';
username.dispatchEvent(new w.Event('input', { bubbles: true }));
assert.match(message.textContent, /letters and numbers only/i);
username.value = 'validname';
username.dispatchEvent(new w.Event('input', { bubbles: true }));
assert.match(message.textContent, /^Terms of Service and Privacy Policy:/);
terms.checked = true;
terms.dispatchEvent(new w.Event('change', { bubbles: true }));
assert.equal(message.hidden, true);
assert.equal(form.checkValidity(), true);
const submit = new w.Event('submit', { bubbles: true, cancelable: true });
form.dispatchEvent(submit);
assert.equal(submit.defaultPrevented, false, 'valid auth forms must be allowed to submit');
assert.equal(form.querySelector('button').disabled, true);
w.dispatchEvent(new w.PageTransitionEvent('pageshow', { persisted: true }));
assert.equal(form.querySelector('button').disabled, false);
// A real click on a valid form reaches submit. Cancel at the document only
// to avoid navigation in jsdom; a later cancellation must not leave it stuck.
let clickedSubmit = false;
w.document.addEventListener('submit', e => { clickedSubmit = true; e.preventDefault(); });
form.querySelector('button').click();
await Promise.resolve();
assert.equal(clickedSubmit, true, 'a valid click reaches the form submit event');
assert.equal(form.querySelector('button').disabled, false);
console.log('Auth validation and submit recovery passed');
dom.window.close();
